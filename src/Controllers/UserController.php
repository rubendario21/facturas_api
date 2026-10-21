<?php

declare(strict_types=1);

namespace App\Controllers;

use App\ApiException;
use App\Auth;
use App\Database;
use App\Request;

/** Gestión del equipo (solo administradores). */
final class UserController
{
    public static function list(Request $request): array
    {
        $admin = Auth::requireAdmin($request);
        $rows = Database::all(
            'SELECT * FROM users WHERE business_id = ? ORDER BY active DESC, name',
            [$admin['business_id']],
        );
        return ['users' => array_map([Auth::class, 'publicUser'], $rows)];
    }

    public static function create(Request $request): array
    {
        $admin = Auth::requireAdmin($request);
        $name = $request->string('name');
        $username = $request->string('username');
        $password = (string) ($request->body['password'] ?? '');
        $role = self::role($request->string('role', 'member'));
        if ($name === '') {
            throw new ApiException(422, 'Indica el nombre.');
        }
        Auth::validateUsername($username);
        Auth::validatePassword($password);
        if (Database::one('SELECT id FROM users WHERE username = ?', [$username])) {
            throw new ApiException(409, 'Ese nombre de usuario ya existe.');
        }
        Database::exec(
            'INSERT INTO users (business_id, name, username, password_hash, role) VALUES (?, ?, ?, ?, ?)',
            [$admin['business_id'], $name, $username, Auth::hashPassword($password), $role],
        );
        $user = Database::one('SELECT * FROM users WHERE id = ?', [Database::pdo()->lastInsertId()]);
        return ['user' => Auth::publicUser($user)];
    }

    /** Admite name, role, active y password (restablecer). */
    public static function update(Request $request): array
    {
        $admin = Auth::requireAdmin($request);
        $user = Database::one(
            'SELECT * FROM users WHERE id = ? AND business_id = ?',
            [(int) $request->params['id'], $admin['business_id']],
        );
        if ($user === null) {
            throw new ApiException(404, 'Usuario no encontrado.');
        }

        $fields = [];
        $params = [];
        $revokeSessions = false;
        if (array_key_exists('name', $request->body)) {
            $name = $request->string('name');
            if ($name === '') {
                throw new ApiException(422, 'El nombre no puede quedar vacío.');
            }
            $fields[] = 'name = ?';
            $params[] = $name;
        }
        $role = array_key_exists('role', $request->body) ? self::role($request->string('role')) : $user['role'];
        $active = array_key_exists('active', $request->body) ? (bool) $request->body['active'] : (bool) $user['active'];

        // Siempre debe quedar al menos un administrador activo.
        $losesAdmin = $user['role'] === 'admin' && $user['active'] && ($role !== 'admin' || !$active);
        if ($losesAdmin) {
            $admins = (int) Database::one(
                'SELECT COUNT(*) AS n FROM users WHERE business_id = ? AND role = \'admin\' AND active = 1',
                [$admin['business_id']],
            )['n'];
            if ($admins <= 1) {
                throw new ApiException(422, 'Debe quedar al menos un administrador activo.');
            }
        }
        $fields[] = 'role = ?';
        $params[] = $role;
        $fields[] = 'active = ?';
        $params[] = $active ? 1 : 0;
        if (!$active && $user['active']) {
            $revokeSessions = true;
        }
        if (array_key_exists('password', $request->body)) {
            $password = (string) $request->body['password'];
            Auth::validatePassword($password);
            $fields[] = 'password_hash = ?';
            $params[] = Auth::hashPassword($password);
            $revokeSessions = true;
        }
        if ($revokeSessions) {
            $fields[] = 'token_version = token_version + 1';
        }
        $params[] = $user['id'];
        Database::exec('UPDATE users SET ' . implode(', ', $fields) . ' WHERE id = ?', $params);

        return ['user' => Auth::publicUser(Database::one('SELECT * FROM users WHERE id = ?', [$user['id']]))];
    }

    private static function role(string $role): string
    {
        if (!in_array($role, ['admin', 'member'], true)) {
            throw new ApiException(422, 'Rol no válido (admin o member).');
        }
        return $role;
    }
}
