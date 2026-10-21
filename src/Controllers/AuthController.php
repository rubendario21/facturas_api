<?php

declare(strict_types=1);

namespace App\Controllers;

use App\ApiException;
use App\Auth;
use App\Database;
use App\Request;

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

    /** Alta inicial: crea el negocio y su administrador. Solo si no hay usuarios. */
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

        $user = Database::transaction(function () use ($businessName, $name, $username, $password) {
            // Bloqueo para que dos altas simultáneas no creen dos negocios.
            Database::one('SELECT GET_LOCK(\'facturas_setup\', 10)');
            if ((int) Database::one('SELECT COUNT(*) AS n FROM users')['n'] > 0) {
                throw new ApiException(409, 'El servidor ya está configurado. Inicia sesión.');
            }
            Database::exec('INSERT INTO businesses (name) VALUES (?)', [$businessName]);
            $businessId = (int) Database::pdo()->lastInsertId();
            Database::exec('INSERT INTO issuer (business_id, name, rev) VALUES (?, ?, ?)',
                [$businessId, $businessName, Database::nextRev($businessId)]);
            Database::exec(
                'INSERT INTO users (business_id, name, username, password_hash, role) VALUES (?, ?, ?, ?, \'admin\')',
                [$businessId, $name, $username, Auth::hashPassword($password)],
            );
            $user = Database::one('SELECT * FROM users WHERE id = ?', [Database::pdo()->lastInsertId()]);
            Database::one('SELECT RELEASE_LOCK(\'facturas_setup\')');
            return $user;
        });
        return Auth::session($user);
    }

    public static function login(Request $request): array
    {
        $username = $request->string('username');
        $password = (string) ($request->body['password'] ?? '');

        $attempts = (int) Database::one(
            'SELECT COUNT(*) AS n FROM login_attempts
             WHERE ip = ? AND username = ? AND created_at > NOW() - INTERVAL ? MINUTE',
            [$request->ip, $username, self::LOCK_MINUTES],
        )['n'];
        if ($attempts >= self::MAX_ATTEMPTS) {
            throw new ApiException(429, 'Demasiados intentos fallidos. Espera ' . self::LOCK_MINUTES . ' minutos.');
        }

        $user = Database::one('SELECT * FROM users WHERE username = ?', [$username]);
        if ($user === null || !password_verify($password, $user['password_hash'])) {
            Database::exec('INSERT INTO login_attempts (ip, username) VALUES (?, ?)', [$request->ip, $username]);
            throw new ApiException(401, 'Usuario o contraseña incorrectos.');
        }
        if (!$user['active']) {
            throw new ApiException(403, 'Tu usuario está desactivado. Habla con el administrador.');
        }
        Database::exec('DELETE FROM login_attempts WHERE ip = ? AND username = ?', [$request->ip, $username]);
        return Auth::session($user);
    }

    public static function refresh(Request $request): array
    {
        $user = Auth::userFromToken($request->string('refresh_token'), 'refresh');
        return Auth::session($user);
    }

    public static function me(Request $request): array
    {
        $user = Auth::requireUser($request);
        $business = Database::one('SELECT id, name FROM businesses WHERE id = ?', [$user['business_id']]);
        return [
            'user' => Auth::publicUser($user),
            'business' => ['id' => (int) $business['id'], 'name' => $business['name']],
        ];
    }

    /** Cambio de la propia contraseña; cierra las sesiones en otros dispositivos. */
    public static function changePassword(Request $request): array
    {
        $user = Auth::requireUser($request);
        if (!password_verify((string) ($request->body['current_password'] ?? ''), $user['password_hash'])) {
            throw new ApiException(422, 'La contraseña actual no es correcta.');
        }
        $new = (string) ($request->body['new_password'] ?? '');
        Auth::validatePassword($new);
        Database::exec(
            'UPDATE users SET password_hash = ?, token_version = token_version + 1 WHERE id = ?',
            [Auth::hashPassword($new), $user['id']],
        );
        return Auth::session(Database::one('SELECT * FROM users WHERE id = ?', [$user['id']]));
    }
}
