<?php

declare(strict_types=1);

namespace App;

/** Sesiones con tokens JWT: uno corto para las peticiones y otro renovable. */
final class Auth
{
    public static function session(array $user): array
    {
        $secret = (string) Config::get('jwt_secret');
        $accessTtl = (int) Config::get('access_ttl', 3600);
        $refreshTtl = (int) Config::get('refresh_ttl_days', 90) * 86400;
        $claims = [
            'sub' => (int) $user['id'],
            'bid' => (int) $user['business_id'],
            'ver' => (int) $user['token_version'],
        ];
        $business = Database::one('SELECT id, name FROM businesses WHERE id = ?', [$user['business_id']]);

        return [
            'access_token' => Jwt::encode($claims + ['typ' => 'access', 'exp' => time() + $accessTtl], $secret),
            'refresh_token' => Jwt::encode($claims + ['typ' => 'refresh', 'exp' => time() + $refreshTtl], $secret),
            'expires_in' => $accessTtl,
            'user' => self::publicUser($user),
            'business' => ['id' => (int) $business['id'], 'name' => $business['name']],
        ];
    }

    /** Valida un token del tipo indicado y devuelve el usuario activo. */
    public static function userFromToken(?string $token, string $type): array
    {
        $payload = $token ? Jwt::decode($token, (string) Config::get('jwt_secret')) : null;
        if ($payload === null || ($payload['typ'] ?? '') !== $type) {
            throw new ApiException(401, 'Sesión no válida o caducada.');
        }
        $user = Database::one('SELECT * FROM users WHERE id = ?', [(int) $payload['sub']]);
        if ($user === null || !$user['active'] || (int) $user['token_version'] !== (int) $payload['ver']) {
            throw new ApiException(401, 'Sesión no válida o caducada.');
        }
        return $user;
    }

    public static function requireUser(Request $request): array
    {
        return self::userFromToken($request->bearerToken(), 'access');
    }

    public static function requireAdmin(Request $request): array
    {
        $user = self::requireUser($request);
        if ($user['role'] !== 'admin') {
            throw new ApiException(403, 'Solo un administrador puede hacer esto.');
        }
        return $user;
    }

    public static function publicUser(array $user): array
    {
        return [
            'id' => (int) $user['id'],
            'name' => $user['name'],
            'username' => $user['username'],
            'role' => $user['role'],
            'active' => (bool) $user['active'],
        ];
    }

    public static function hashPassword(string $password): string
    {
        return password_hash($password, PASSWORD_DEFAULT);
    }

    public static function validatePassword(string $password): void
    {
        if (mb_strlen($password) < 6) {
            throw new ApiException(422, 'La contraseña debe tener al menos 6 caracteres.');
        }
    }

    public static function validateUsername(string $username): void
    {
        if (!preg_match('/^[A-Za-z0-9._-]{3,60}$/', $username)) {
            throw new ApiException(422, 'El usuario debe tener entre 3 y 60 caracteres (letras, números, punto, guion).');
        }
    }
}
