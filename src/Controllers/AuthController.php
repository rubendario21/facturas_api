<?php

declare(strict_types=1);

namespace App\Controllers;

use App\ApiException;
use App\Auth;
use App\Database;
use App\Request;
use App\UserService;

final class AuthController
{
    private const MAX_ATTEMPTS = 5;
    private const LOCK_MINUTES = 15;

    /** Estado del servidor; indica si falta crear el primer administrador. */
    public static function status(Request $request): array
    {
        $users = (int) Database::one('SELECT COUNT(*) AS n FROM users')['n'];
        return ['ok' => true, 'needs_setup' => $users === 0];
    }

    /**
     * Alta inicial: crea el primer negocio y el superadministrador de la
     * plataforma. Solo funciona con el servidor vacío.
     */
    public static function setup(Request $request): array
    {
        $businessName = $request->string('business_name');
        $name = $request->string('name');
        $username = $request->string('username');
        $password = (string) ($request->body['password'] ?? '');
        if ($businessName === '' || $name === '') {
            throw new ApiException(422, 'Indica el nombre del negocio y tu nombre.');
        }
        Auth::validateUsername($username);
        Auth::validatePassword($password);

        $ctx = Database::transaction(function () use ($businessName, $name, $username, $password) {
            // Bloqueo para que dos altas simultáneas no creen dos plataformas.
            Database::one('SELECT GET_LOCK(\'facturas_setup\', 10)');
            if ((int) Database::one('SELECT COUNT(*) AS n FROM users')['n'] > 0) {
                throw new ApiException(409, 'El servidor ya está configurado. Inicia sesión.');
            }
            Database::exec('INSERT INTO businesses (name) VALUES (?)', [$businessName]);
            $businessId = (int) Database::pdo()->lastInsertId();
            Database::exec('INSERT INTO issuer (business_id, name, rev) VALUES (?, ?, ?)',
                [$businessId, $businessName, Database::nextRev($businessId)]);
            Database::exec(
                'INSERT INTO users (name, username, password_hash, is_superadmin, last_business_id) VALUES (?, ?, ?, 1, ?)',
                [$name, $username, Auth::hashPassword($password), $businessId],
            );
            $userId = (int) Database::pdo()->lastInsertId();
            Database::exec('INSERT INTO memberships (user_id, business_id, role, web_access) VALUES (?, ?, \'admin\', 1)',
                [$userId, $businessId]);
            Database::one('SELECT RELEASE_LOCK(\'facturas_setup\')');
            return Auth::context(Database::one('SELECT * FROM users WHERE id = ?', [$userId]), $businessId);
        });
        return Auth::session($ctx);
    }

    /** {username, password, business_id?} → sesión en ese negocio (o el último usado). */
    public static function login(Request $request): array
    {
        $username = $request->string('username');
        $password = (string) ($request->body['password'] ?? '');
        $user = self::checkCredentials($request->ip, $username, $password);

        $requested = isset($request->body['business_id']) ? (int) $request->body['business_id'] : null;
        $ctx = Auth::defaultContext($user, $requested);
        if ($ctx === null) {
            throw new ApiException(403, $user['is_superadmin']
                ? 'No perteneces a ningún negocio activo. Entra al portal web para crear uno o añadirte.'
                : 'No tienes acceso a ningún negocio activo. Habla con tu administrador.');
        }
        return Auth::session($ctx);
    }

    /** Usuario y contraseña con límite de intentos; devuelve la cuenta. */
    public static function checkCredentials(string $ip, string $username, string $password): array
    {
        $attempts = (int) Database::one(
            'SELECT COUNT(*) AS n FROM login_attempts
             WHERE ip = ? AND username = ? AND created_at > NOW() - INTERVAL ? MINUTE',
            [$ip, $username, self::LOCK_MINUTES],
        )['n'];
        if ($attempts >= self::MAX_ATTEMPTS) {
            throw new ApiException(429, 'Demasiados intentos fallidos. Espera ' . self::LOCK_MINUTES . ' minutos.');
        }
        $user = Database::one('SELECT * FROM users WHERE username = ?', [$username]);
        if ($user === null || !password_verify($password, $user['password_hash'])) {
            Database::exec('INSERT INTO login_attempts (ip, username) VALUES (?, ?)', [$ip, $username]);
            throw new ApiException(401, 'Usuario o contraseña incorrectos.');
        }
        if (!$user['active']) {
            throw new ApiException(403, 'Tu usuario está desactivado. Habla con el administrador.');
        }
        Database::exec('DELETE FROM login_attempts WHERE ip = ? AND username = ?', [$ip, $username]);
        return $user;
    }

    public static function refresh(Request $request): array
    {
        return Auth::session(Auth::userFromToken($request->string('refresh_token'), 'refresh'));
    }

    public static function me(Request $request): array
    {
        $ctx = Auth::requireUser($request);
        return [
            'user' => Auth::publicUser($ctx),
            'business' => ['id' => (int) $ctx['business_id'], 'name' => $ctx['business_name']],
            'businesses' => Auth::businessesOf((int) $ctx['id']),
        ];
    }

    /** Negocios en los que el usuario puede trabajar. */
    public static function businesses(Request $request): array
    {
        $ctx = Auth::requireUser($request);
        return ['businesses' => Auth::businessesOf((int) $ctx['id'])];
    }

    /** {business_id} → nueva sesión en otro negocio del mismo usuario. */
    public static function switchBusiness(Request $request): array
    {
        $ctx = Auth::requireUser($request);
        $user = Database::one('SELECT * FROM users WHERE id = ?', [$ctx['id']]);
        $target = Auth::context($user, (int) ($request->body['business_id'] ?? 0));
        if ($target === null) {
            throw new ApiException(403, 'No tienes acceso a ese negocio.');
        }
        return Auth::session($target);
    }

    /** Cambio de la propia contraseña; cierra las sesiones en otros dispositivos. */
    public static function changePassword(Request $request): array
    {
        $ctx = Auth::requireUser($request);
        $user = UserService::changeOwnPassword(
            (int) $ctx['id'],
            (string) ($request->body['current_password'] ?? ''),
            (string) ($request->body['new_password'] ?? ''),
        );
        return Auth::session(Auth::context($user, (int) $ctx['business_id']));
    }
}
