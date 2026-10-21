-- Esquema de la API de Facturas (MariaDB 10.6+ / MySQL 8+).
-- Uso: mysql -u root -p < database/schema.sql

CREATE DATABASE IF NOT EXISTS facturas
  CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE facturas;

-- Un negocio agrupa usuarios, emisor, facturas y pagos.
CREATE TABLE IF NOT EXISTS businesses (
  id          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  name        VARCHAR(150) NOT NULL,
  -- Contador de cambios: cada modificación recibe el siguiente valor (rev),
  -- y los dispositivos piden "todo lo que cambió desde mi último rev".
  last_rev    BIGINT UNSIGNED NOT NULL DEFAULT 0,
  -- Un negocio desactivado no puede usarse (nadie entra en él).
  active      TINYINT(1) NOT NULL DEFAULT 1,
  created_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB;

-- Cuentas de usuario (una por persona). A qué negocios pertenece y con qué
-- rol está en memberships.
CREATE TABLE IF NOT EXISTS users (
  id             INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  name           VARCHAR(120) NOT NULL,
  username       VARCHAR(60)  NOT NULL,
  password_hash  VARCHAR(255) NOT NULL,
  -- Dueño de la plataforma: crea negocios y puede gestionar cualquiera.
  is_superadmin  TINYINT(1) NOT NULL DEFAULT 0,
  -- Negocio que se abre por defecto al iniciar sesión.
  last_business_id INT UNSIGNED NULL,
  active         TINYINT(1) NOT NULL DEFAULT 1,
  -- Se incrementa al cambiar contraseña o desactivar: invalida sesiones.
  token_version  INT UNSIGNED NOT NULL DEFAULT 1,
  created_at     DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at     DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uq_users_username (username)
) ENGINE=InnoDB;

-- Pertenencia de un usuario a un negocio, con su rol en él.
CREATE TABLE IF NOT EXISTS memberships (
  user_id      INT UNSIGNED NOT NULL,
  business_id  INT UNSIGNED NOT NULL,
  role         ENUM('admin','member') NOT NULL DEFAULT 'member',
  active       TINYINT(1) NOT NULL DEFAULT 1,
  -- Puede trabajar desde el portal web (los administradores siempre pueden).
  web_access   TINYINT(1) NOT NULL DEFAULT 0,
  created_at   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (user_id, business_id),
  KEY ix_memberships_business (business_id),
  CONSTRAINT fk_memberships_user FOREIGN KEY (user_id) REFERENCES users(id),
  CONSTRAINT fk_memberships_business FOREIGN KEY (business_id) REFERENCES businesses(id)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS issuer (
  business_id          INT UNSIGNED PRIMARY KEY,
  name                 VARCHAR(150) NOT NULL DEFAULT '',
  tax_id               VARCHAR(60)  NOT NULL DEFAULT '',
  address              VARCHAR(255) NOT NULL DEFAULT '',
  city                 VARCHAR(120) NOT NULL DEFAULT '',
  phone                VARCHAR(60)  NOT NULL DEFAULT '',
  email                VARCHAR(120) NOT NULL DEFAULT '',
  bank_account         VARCHAR(120) NOT NULL DEFAULT '',
  invoice_prefix       VARCHAR(20)  NOT NULL DEFAULT 'F-',
  next_invoice_number  INT UNSIGNED NOT NULL DEFAULT 1,
  next_receipt_number  INT UNSIGNED NOT NULL DEFAULT 1,
  default_tax_rate     DECIMAL(5,2) NOT NULL DEFAULT 19.00,
  currency             VARCHAR(10)  NOT NULL DEFAULT '$',
  logo                 MEDIUMBLOB NULL,
  rev                  BIGINT UNSIGNED NOT NULL DEFAULT 0,
  updated_at           DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  CONSTRAINT fk_issuer_business FOREIGN KEY (business_id) REFERENCES businesses(id)
) ENGINE=InnoDB;

-- Los ids de facturas y pagos son UUID generados en el dispositivo, para
-- poder crearlos sin conexión. El número (F-0001 / RC-0001) lo asigna el
-- servidor al sincronizar.
CREATE TABLE IF NOT EXISTS invoices (
  id              CHAR(36) PRIMARY KEY,
  business_id     INT UNSIGNED NOT NULL,
  number          VARCHAR(40) NULL,
  issue_date      DATE NOT NULL,
  due_date        DATE NULL,
  client_name     VARCHAR(150) NOT NULL,
  client_tax_id   VARCHAR(60)  NOT NULL DEFAULT '',
  client_address  VARCHAR(255) NOT NULL DEFAULT '',
  client_email    VARCHAR(120) NOT NULL DEFAULT '',
  tax_rate        DECIMAL(5,2) NOT NULL,
  notes           TEXT NULL,
  -- Conceptos: [{"description":..,"quantity":..,"unit_price":..}, ...]
  items           LONGTEXT NOT NULL,
  paid            TINYINT(1) NOT NULL DEFAULT 0,
  paid_date       DATE NULL,
  created_by      INT UNSIGNED NULL,
  created_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  deleted_at      DATETIME NULL,
  rev             BIGINT UNSIGNED NOT NULL,
  UNIQUE KEY uq_invoices_number (business_id, number),
  KEY ix_invoices_rev (business_id, rev),
  CONSTRAINT fk_invoices_business FOREIGN KEY (business_id) REFERENCES businesses(id)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS payments (
  id              CHAR(36) PRIMARY KEY,
  business_id     INT UNSIGNED NOT NULL,
  invoice_id      CHAR(36) NOT NULL,
  receipt_number  VARCHAR(40) NULL,
  amount          DECIMAL(14,2) NOT NULL,
  date            DATE NOT NULL,
  method          VARCHAR(40)  NOT NULL DEFAULT '',
  note            VARCHAR(255) NOT NULL DEFAULT '',
  created_by      INT UNSIGNED NULL,
  created_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  deleted_at      DATETIME NULL,
  rev             BIGINT UNSIGNED NOT NULL,
  UNIQUE KEY uq_payments_receipt (business_id, receipt_number),
  KEY ix_payments_rev (business_id, rev),
  KEY ix_payments_invoice (invoice_id),
  CONSTRAINT fk_payments_business FOREIGN KEY (business_id) REFERENCES businesses(id),
  CONSTRAINT fk_payments_invoice FOREIGN KEY (invoice_id) REFERENCES invoices(id)
) ENGINE=InnoDB;

-- Intentos fallidos de login, para frenar ataques de fuerza bruta.
CREATE TABLE IF NOT EXISTS login_attempts (
  id          BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  ip          VARCHAR(45) NOT NULL,
  username    VARCHAR(60) NOT NULL,
  created_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY ix_attempts (ip, username, created_at)
) ENGINE=InnoDB;

-- Migraciones aplicadas (ver database/migrate.php). Una instalación nueva ya
-- incluye todas.
CREATE TABLE IF NOT EXISTS schema_migrations (
  id          VARCHAR(80) PRIMARY KEY,
  applied_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB;
INSERT IGNORE INTO schema_migrations (id) VALUES ('002_multi_negocio');
