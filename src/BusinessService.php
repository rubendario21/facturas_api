<?php

declare(strict_types=1);

namespace App;

/** Negocios de la plataforma (solo el superadministrador). */
final class BusinessService
{
    /** Todos los negocios, con miembros, facturas y saldo pendiente. */
    public static function list(): array
    {
        return Database::all(
            'SELECT b.*,
               (SELECT COUNT(*) FROM memberships m JOIN users u ON u.id = m.user_id
                 WHERE m.business_id = b.id AND m.active = 1 AND u.active = 1) AS members,
               (SELECT COUNT(*) FROM invoices i WHERE i.business_id = b.id AND i.deleted_at IS NULL) AS invoices,
               (SELECT COUNT(*) FROM invoices i WHERE i.business_id = b.id AND i.deleted_at IS NULL AND i.paid = 0) AS open_invoices
             FROM businesses b ORDER BY b.active DESC, b.name',
        );
    }

    public static function find(int $id): array
    {
        $b = Database::one('SELECT * FROM businesses WHERE id = ?', [$id]);
        if ($b === null) {
            throw new ApiException(404, 'Negocio no encontrado.');
        }
        return $b;
    }

    /**
     * Crea un negocio. Opcionalmente con su primer administrador (cuenta
     * nueva) y añadiendo como administrador a quien lo crea.
     */
    public static function create(string $name, array $creator, bool $includeCreator, ?array $admin = null): array
    {
        $name = trim($name);
        if ($name === '' || mb_strlen($name) > 150) {
            throw new ApiException(422, 'Indica el nombre del negocio (máximo 150 caracteres).');
        }
        if (!$includeCreator && $admin === null) {
            throw new ApiException(422, 'El negocio necesita al menos un administrador.');
        }
        return Database::transaction(function () use ($name, $creator, $includeCreator, $admin) {
            Database::exec('INSERT INTO businesses (name) VALUES (?)', [$name]);
            $id = (int) Database::pdo()->lastInsertId();
            Database::exec('INSERT INTO issuer (business_id, name, rev) VALUES (?, ?, ?)',
                [$id, $name, Database::nextRev($id)]);
            if ($includeCreator) {
                self::addMember($id, (int) $creator['id'], 'admin');
            }
            if ($admin !== null) {
                UserService::create($id, $admin['name'] ?? '', $admin['username'] ?? '', $admin['password'] ?? '', 'admin');
            }
            return self::find($id);
        });
    }

    public static function update(int $id, array $changes): array
    {
        self::find($id);
        if (array_key_exists('name', $changes)) {
            $name = trim((string) $changes['name']);
            if ($name === '' || mb_strlen($name) > 150) {
                throw new ApiException(422, 'Indica el nombre del negocio (máximo 150 caracteres).');
            }
            Database::exec('UPDATE businesses SET name = ? WHERE id = ?', [$name, $id]);
        }
        if (array_key_exists('active', $changes)) {
            Database::exec('UPDATE businesses SET active = ? WHERE id = ?', [$changes['active'] ? 1 : 0, $id]);
        }
        return self::find($id);
    }

    /** Añade (o reactiva) a un usuario existente en un negocio. */
    public static function addMember(int $businessId, int $userId, string $role): void
    {
        if (!in_array($role, ['admin', 'member'], true)) {
            throw new ApiException(422, 'Rol no válido (admin o member).');
        }
        Database::exec(
            'INSERT INTO memberships (user_id, business_id, role) VALUES (?, ?, ?)
             ON DUPLICATE KEY UPDATE role = VALUES(role), active = 1',
            [$userId, $businessId, $role],
        );
    }

    public static function addMemberByUsername(int $businessId, string $username, string $role): array
    {
        self::find($businessId);
        $user = Database::one('SELECT * FROM users WHERE username = ?', [trim($username)]);
        if ($user === null) {
            throw new ApiException(404, 'No existe ningún usuario con ese nombre de usuario.');
        }
        self::addMember($businessId, (int) $user['id'], $role);
        return $user;
    }
}
