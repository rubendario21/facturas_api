<?php

declare(strict_types=1);

namespace App;

/**
 * Sesiones con tokens JWT ligadas a un negocio: uno corto para las peticiones
 * y otro renovable.
 *
 * Un "contexto" es la fila del usuario más el negocio en el que trabaja ahora
 * (business_id, business_name) y su rol en él (role). El resto de la API usa
 * siempre el contexto, así que las reglas se aplican negocio a negocio.
 */
final class Auth
{
    /** Contexto del usuario en un negocio, o null si no tiene acceso a él. */
    public static function context(array $user, int $businessId): ?array
    {
        $row = Database::one(
            'SELECT b.id, b.name, b.active AS business_active, m.role, m.active AS member_active, m.web_access
             FROM businesses b
             LEFT JOIN memberships m ON m.business_id = b.id AND m.user_id = ?
             WHERE b.id = ?',
            [$user['id'], $businessId],
        );
        if ($row === null || !$row['business_active']) {
            return null;
        }
        if ($row['role'] === null || !$row['member_active']) {
            return null;
        }
        return array_merge($user, [
            'business_id' => (int) $row['id'],
            'business_name' => $row['name'],
            'role' => $row['role'],
            // Los administradores siempre pueden usar el portal web.
            'web_access' => $row['role'] === 'admin' || (bool) $row['web_access'],
        ]);
    }

    /** Negocios activos en los que el usuario es miembro activo. */
    public static function businessesOf(int $userId): array
    {
        return array_map(fn($r) => [
            'id' => (int) $r['id'],
            'name' => $r['name'],
            'role' => $r['role'],
        ], Database::all(
            'SELECT b.id, b.name, m.role FROM memberships m JOIN businesses b ON b.id = m.business_id
             WHERE m.user_id = ? AND m.active = 1 AND b.active = 1 ORDER BY b.name',
            [$userId],
        ));
    }

    /**
     * Negocio con el que entra el usuario: el pedido, el último que usó o el
     * primero disponible. Null si no tiene acceso a ninguno.
     */
    public static function defaultContext(array $user, ?int $requested = null): ?array
    {
        foreach ([$requested, $user['last_business_id'] ?? null] as $candidate) {
            if ($candidate && ($ctx = self::context($user, (int) $candidate))) {
                return $ctx;
            }
        }
        $first = self::businessesOf((int) $user['id'])[0] ?? null;
        return $first ? self::context($user, $first['id']) : null;
    }

    public static function session(array $ctx): array
    {
        $secret = (string) Config::get('jwt_secret');
        $accessTtl = (int) Config::get('access_ttl', 3600);
        $refreshTtl = (int) Config::get('refresh_ttl_days', 90) * 86400;
        $claims = [
            'sub' => (int) $ctx['id'],
            'bid' => (int) $ctx['business_id'],
            'ver' => (int) $ctx['token_version'],
        ];
        Database::exec('UPDATE users SET last_business_id = ? WHERE id = ?', [$ctx['business_id'], $ctx['id']]);

        return [
            'access_token' => Jwt::encode($claims + ['typ' => 'access', 'exp' => time() + $accessTtl], $secret),
            'refresh_token' => Jwt::encode($claims + ['typ' => 'refresh', 'exp' => time() + $refreshTtl], $secret),
            'expires_in' => $accessTtl,
            'user' => self::publicUser($ctx),
            'business' => ['id' => (int) $ctx['business_id'], 'name' => $ctx['business_name']],
            'businesses' => self::businessesOf((int) $ctx['id']),
        ];
    }

    /** Valida un token del tipo indicado y devuelve el contexto del usuario. */
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
        // Quitado del negocio, negocio desactivado...: la sesión deja de valer.
        $ctx = self::context($user, (int) ($payload['bid'] ?? 0));
        if ($ctx === null) {
            throw new ApiException(401, 'Ya no tienes acceso a este negocio.');
        }
        return $ctx;
    }

    public static function requireUser(Request $request): array
    {
        return self::userFromToken($request->bearerToken(), 'access');
    }

    public static function requireAdmin(Request $request): array
    {
        $ctx = self::requireUser($request);
        if ($ctx['role'] !== 'admin') {
            throw new ApiException(403, 'Solo un administrador puede hacer esto.');
        }
        return $ctx;
    }

    public static function requireSuperadmin(Request $request): array
    {
        $ctx = self::requireUser($request);
        if (!$ctx['is_superadmin']) {
            throw new ApiException(403, 'Solo el superadministrador puede hacer esto.');
        }
        return $ctx;
    }

    /** Datos públicos de un usuario (con su rol en el negocio, si lo hay). */
    public static function publicUser(array $user): array
    {
        return [
            'id' => (int) $user['id'],
            'name' => $user['name'],
            'username' => $user['username'],
            'role' => $user['role'] ?? 'member',
            'active' => (bool) $user['active'],
            'is_superadmin' => (bool) ($user['is_superadmin'] ?? false),
            'web_access' => (bool) ($user['web_access'] ?? false),
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
