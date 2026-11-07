<?php

declare(strict_types=1);

namespace App;

/**
 * Equipo de un negocio, compartido por la API y el portal web.
 *
 * Cada persona tiene una cuenta (users) y pertenece a uno o varios negocios
 * (memberships) con un rol en cada uno. Activo = cuenta activa y miembro
 * activo en el negocio.
 */
final class UserService
{
    private const MEMBER_SELECT = '
        SELECT u.id, u.name, u.username, u.is_superadmin, u.token_version, u.created_at,
               m.role, (m.active AND u.active) AS active, (m.web_access OR m.role = \'admin\') AS web_access,
               (m.can_void OR m.role = \'admin\') AS can_void, m.reports,
               (SELECT COUNT(*) FROM memberships x WHERE x.user_id = u.id) AS business_count
        FROM memberships m JOIN users u ON u.id = m.user_id
        WHERE m.business_id = ?';

    public static function list(int $businessId): array
    {
        return array_map([self::class, 'withReports'],
            Database::all(self::MEMBER_SELECT . ' ORDER BY active DESC, m.role, u.name', [$businessId]));
    }

    public static function find(int $businessId, int $id): array
    {
        $user = Database::one(self::MEMBER_SELECT . ' AND u.id = ?', [$businessId, $id]);
        if ($user === null) {
            throw new ApiException(404, 'Usuario no encontrado.');
        }
        return self::withReports($user);
    }

    /** Lo guardado en la base → lista de informes que ve (un administrador, todos). */
    private static function withReports(array $user): array
    {
        return ['reports' => ReportService::keysFor($user['role'], $user['reports'])] + $user;
    }

    /** Crea una cuenta nueva y la añade al negocio con el rol indicado. */
    public static function create(int $businessId, string $name, string $username, string $password, string $role,
                                  bool $webAccess = false, bool $canVoid = false, mixed $reports = []): array
    {
        $name = trim($name);
        $username = trim($username);
        if ($name === '') {
            throw new ApiException(422, 'Indica el nombre.');
        }
        Auth::validateUsername($username);
        Auth::validatePassword($password);
        $role = self::role($role);
        $storedReports = ReportService::encode($reports);
        if (Database::one('SELECT id FROM users WHERE username = ?', [$username])) {
            throw new ApiException(409, 'Ese nombre de usuario ya existe.');
        }
        Database::exec(
            'INSERT INTO users (name, username, password_hash, last_business_id) VALUES (?, ?, ?, ?)',
            [$name, $username, Auth::hashPassword($password), $businessId],
        );
        $id = (int) Database::pdo()->lastInsertId();
        Database::exec('INSERT INTO memberships (user_id, business_id, role, web_access, can_void, reports) VALUES (?, ?, ?, ?, ?, ?)',
            [$id, $businessId, $role, $webAccess ? 1 : 0, $canVoid ? 1 : 0, $storedReports]);
        return self::find($businessId, $id);
    }

    /**
     * Cambios admitidos: name, password (restablecer), role y active (en este
     * negocio). Quien edita ($editor) solo puede cambiar el nombre o la
     * contraseña de una cuenta que pertenezca únicamente a su negocio; las
     * cuentas compartidas con otros negocios solo las toca el superadministrador.
     */
    public static function update(int $businessId, array $editor, int $id, array $changes): array
    {
        $user = self::find($businessId, $id);
        $isSelf = (int) $editor['id'] === $id;
        $touchesAccount = (isset($changes['name']) && trim((string) $changes['name']) !== $user['name'])
            || (isset($changes['password']) && $changes['password'] !== '');
        if ($touchesAccount && !$isSelf && !$editor['is_superadmin']
            && ((int) $user['business_count'] > 1 || $user['is_superadmin'])) {
            throw new ApiException(403,
                'Este usuario también trabaja en otros negocios: solo el superadministrador puede cambiar su nombre o contraseña.');
        }

        $role = array_key_exists('role', $changes) ? self::role((string) $changes['role']) : $user['role'];
        $active = array_key_exists('active', $changes) ? (bool) $changes['active'] : (bool) $user['active'];

        if ($user['role'] === 'admin' && $user['active'] && ($role !== 'admin' || !$active)) {
            self::assertAnotherAdmin($businessId);
        }
        $webAccess = array_key_exists('web_access', $changes) ? (bool) $changes['web_access'] : (bool) $user['web_access'];
        // El valor leído de un administrador siempre es verdadero: no se guarda,
        // para que no conserve el permiso si luego deja de serlo.
        $canVoid = array_key_exists('can_void', $changes)
            ? (bool) $changes['can_void']
            : $user['role'] !== 'admin' && (bool) $user['can_void'];
        // Igual con los informes: un administrador los ve todos sin tenerlos guardados.
        $reports = ReportService::encode(array_key_exists('reports', $changes)
            ? $changes['reports']
            : ($user['role'] === 'admin' ? [] : $user['reports']));
        // Desactivar a alguien solo le quita el acceso a este negocio.
        Database::exec('UPDATE memberships SET role = ?, active = ?, web_access = ?, can_void = ?, reports = ? WHERE user_id = ? AND business_id = ?',
            [$role, $active ? 1 : 0, $webAccess ? 1 : 0, $canVoid ? 1 : 0, $reports, $id, $businessId]);
        if ($active && !$user['active']) {
            Database::exec('UPDATE users SET active = 1 WHERE id = ?', [$id]);
        }

        $fields = [];
        $params = [];
        if (array_key_exists('name', $changes)) {
            $name = trim((string) $changes['name']);
            if ($name === '') {
                throw new ApiException(422, 'El nombre no puede quedar vacío.');
            }
            $fields[] = 'name = ?';
            $params[] = $name;
        }
        if (isset($changes['password']) && $changes['password'] !== '') {
            Auth::validatePassword((string) $changes['password']);
            // Cambiar la contraseña cierra todas sus sesiones abiertas.
            $fields[] = 'password_hash = ?';
            $params[] = Auth::hashPassword((string) $changes['password']);
            $fields[] = 'token_version = token_version + 1';
        }
        if ($fields !== []) {
            $params[] = $id;
            Database::exec('UPDATE users SET ' . implode(', ', $fields) . ' WHERE id = ?', $params);
        }
        return self::find($businessId, $id);
    }

    /**
     * Quita a una persona del negocio. Solo se borra su pertenencia y sus permisos
     * aquí: la cuenta sigue existiendo (puede estar en otros negocios) y las
     * facturas y pagos que registró se conservan con su nombre.
     */
    public static function removeFromBusiness(int $businessId, array $editor, int $id): void
    {
        $user = self::find($businessId, $id);
        if ((int) $editor['id'] === $id && !$editor['is_superadmin']) {
            throw new ApiException(422, 'No puedes quitarte a ti mismo del negocio.');
        }
        if ($user['is_superadmin'] && !$editor['is_superadmin']) {
            throw new ApiException(403, 'Solo el superadministrador puede quitar a esta persona del negocio.');
        }
        if ($user['role'] === 'admin' && $user['active']) {
            self::assertAnotherAdmin($businessId);
        }
        Database::exec('DELETE FROM memberships WHERE user_id = ? AND business_id = ?', [$id, $businessId]);
    }

    /** Siempre debe quedar al menos un administrador activo en el negocio. */
    private static function assertAnotherAdmin(int $businessId): void
    {
        $admins = (int) Database::one(
            'SELECT COUNT(*) AS n FROM memberships m JOIN users u ON u.id = m.user_id
             WHERE m.business_id = ? AND m.role = \'admin\' AND m.active = 1 AND u.active = 1',
            [$businessId],
        )['n'];
        if ($admins <= 1) {
            throw new ApiException(422, 'Debe quedar al menos un administrador activo en el negocio.');
        }
    }

    /** Cambio de la propia contraseña (cierra las sesiones en otros dispositivos). */
    public static function changeOwnPassword(int $userId, string $current, string $new): array
    {
        $user = Database::one('SELECT * FROM users WHERE id = ?', [$userId]);
        if ($user === null || !password_verify($current, $user['password_hash'])) {
            throw new ApiException(422, 'La contraseña actual no es correcta.');
        }
        Auth::validatePassword($new);
        Database::exec('UPDATE users SET password_hash = ?, token_version = token_version + 1 WHERE id = ?',
            [Auth::hashPassword($new), $userId]);
        return Database::one('SELECT * FROM users WHERE id = ?', [$userId]);
    }

    private static function role(string $role): string
    {
        if (!in_array($role, ['admin', 'member'], true)) {
            throw new ApiException(422, 'Rol no válido (admin o member).');
        }
        return $role;
    }
}
