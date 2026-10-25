<?php

declare(strict_types=1);

/**
 * Actualiza una base de datos existente a la última versión del esquema.
 * Es seguro ejecutarlo varias veces: solo aplica lo que falte.
 *
 *   php database/migrate.php
 */

use App\Database;

require dirname(__DIR__) . '/src/bootstrap.php';

$pdo = Database::pdo();
$pdo->exec('CREATE TABLE IF NOT EXISTS schema_migrations (
    id VARCHAR(80) PRIMARY KEY,
    applied_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB');

$columnExists = fn(string $table, string $column) => (bool) Database::one(
    'SELECT 1 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?',
    [$table, $column],
);

$migrations = [
    // Varios negocios: un usuario puede pertenecer a varios, con un rol en cada
    // uno, y existe un superadministrador que crea negocios para sus clientes.
    '002_multi_negocio' => function () use ($pdo, $columnExists): void {
        if (!$columnExists('businesses', 'active')) {
            $pdo->exec('ALTER TABLE businesses ADD COLUMN active TINYINT(1) NOT NULL DEFAULT 1');
        }
        if (!$columnExists('users', 'is_superadmin')) {
            $pdo->exec('ALTER TABLE users ADD COLUMN is_superadmin TINYINT(1) NOT NULL DEFAULT 0');
        }
        if (!$columnExists('users', 'last_business_id')) {
            $pdo->exec('ALTER TABLE users ADD COLUMN last_business_id INT UNSIGNED NULL');
        }
        $pdo->exec('CREATE TABLE IF NOT EXISTS memberships (
            user_id      INT UNSIGNED NOT NULL,
            business_id  INT UNSIGNED NOT NULL,
            role         ENUM(\'admin\',\'member\') NOT NULL DEFAULT \'member\',
            active       TINYINT(1) NOT NULL DEFAULT 1,
            web_access   TINYINT(1) NOT NULL DEFAULT 0,
            created_at   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (user_id, business_id),
            KEY ix_memberships_business (business_id),
            CONSTRAINT fk_memberships_user FOREIGN KEY (user_id) REFERENCES users(id),
            CONSTRAINT fk_memberships_business FOREIGN KEY (business_id) REFERENCES businesses(id)
        ) ENGINE=InnoDB');

        if ($columnExists('users', 'business_id')) {
            // Cada usuario actual pasa a ser miembro de su negocio, con su rol.
            $pdo->exec('INSERT IGNORE INTO memberships (user_id, business_id, role, active)
                        SELECT id, business_id, role, active FROM users');
            $pdo->exec('UPDATE users SET last_business_id = business_id WHERE last_business_id IS NULL');
            // El primer administrador del primer negocio es el superadministrador.
            $first = Database::one('SELECT m.user_id FROM memberships m
                                    WHERE m.role = \'admin\' ORDER BY m.business_id, m.user_id LIMIT 1');
            if ($first) {
                Database::exec('UPDATE users SET is_superadmin = 1 WHERE id = ?', [$first['user_id']]);
            }
            $fk = Database::one('SELECT CONSTRAINT_NAME FROM information_schema.KEY_COLUMN_USAGE
                                 WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = \'users\'
                                   AND COLUMN_NAME = \'business_id\' AND REFERENCED_TABLE_NAME IS NOT NULL');
            if ($fk) {
                $pdo->exec("ALTER TABLE users DROP FOREIGN KEY `{$fk['CONSTRAINT_NAME']}`");
            }
            $pdo->exec('ALTER TABLE users DROP COLUMN business_id');
        }
        if ($columnExists('users', 'role')) {
            $pdo->exec('ALTER TABLE users DROP COLUMN role');
        }
    },
    // Anulación de facturas y pagos: se conservan en el historial con su
    // motivo, quién los anuló y cuándo.
    '003_anulaciones' => function () use ($pdo, $columnExists): void {
        foreach (['invoices', 'payments'] as $table) {
            if (!$columnExists($table, 'voided_at')) {
                $pdo->exec("ALTER TABLE $table
                    ADD COLUMN voided_at DATETIME NULL,
                    ADD COLUMN void_reason VARCHAR(255) NULL,
                    ADD COLUMN voided_by INT UNSIGNED NULL");
            }
        }
    },
];

$applied = array_column(Database::all('SELECT id FROM schema_migrations'), 'id');
$pending = array_diff(array_keys($migrations), $applied);
if ($pending === []) {
    echo "La base de datos ya está al día.\n";
    exit(0);
}
foreach ($pending as $id) {
    echo "Aplicando {$id}... ";
    $migrations[$id]();
    Database::exec('INSERT INTO schema_migrations (id) VALUES (?)', [$id]);
    echo "hecho.\n";
}
echo "Base de datos actualizada.\n";
