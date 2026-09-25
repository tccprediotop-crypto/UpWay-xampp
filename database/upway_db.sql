CREATE DATABASE IF NOT EXISTS upway_db
  CHARACTER SET utf8mb4
  COLLATE utf8mb4_unicode_ci;

USE upway_db;

CREATE TABLE IF NOT EXISTS towers (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(50) NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_towers_name (name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS units (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    tower_id BIGINT UNSIGNED NOT NULL,
    unit_number VARCHAR(20) NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_units_tower
        FOREIGN KEY (tower_id) REFERENCES towers (id),
    UNIQUE KEY uq_units_tower_number (tower_id, unit_number)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS users (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    unit_id BIGINT UNSIGNED NULL,
    full_name VARCHAR(150) NOT NULL,
    email VARCHAR(150) NOT NULL,
    phone VARCHAR(30) NULL,
    password_hash VARCHAR(255) NOT NULL,
    role ENUM('RESIDENT', 'PORTER', 'ADMIN') NOT NULL DEFAULT 'RESIDENT',
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    browser_push_enabled TINYINT(1) NOT NULL DEFAULT 0,
    whatsapp_enabled TINYINT(1) NOT NULL DEFAULT 1,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT fk_users_unit
        FOREIGN KEY (unit_id) REFERENCES units (id),
    UNIQUE KEY uq_users_email (email),
    KEY idx_users_role_active (role, is_active)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS packages (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    resident_id BIGINT UNSIGNED NOT NULL,
    received_by_user_id BIGINT UNSIGNED NOT NULL,
    tracking_code VARCHAR(120) NULL,
    carrier VARCHAR(120) NOT NULL,
    description TEXT NULL,
    status ENUM('RECEIVED', 'NOTIFIED', 'TOKEN_GENERATED', 'PICKED_UP') NOT NULL DEFAULT 'RECEIVED',
    received_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    notified_at DATETIME NULL,
    picked_up_at DATETIME NULL,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT fk_packages_resident
        FOREIGN KEY (resident_id) REFERENCES users (id),
    CONSTRAINT fk_packages_porter
        FOREIGN KEY (received_by_user_id) REFERENCES users (id),
    KEY idx_packages_resident_status (resident_id, status),
    KEY idx_packages_status (status),
    KEY idx_packages_received_at (received_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS pickup_tokens (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    package_id BIGINT UNSIGNED NOT NULL,
    generated_by_user_id BIGINT UNSIGNED NOT NULL,
    validated_by_user_id BIGINT UNSIGNED NULL,
    token_code VARCHAR(16) NOT NULL,
    qr_payload VARCHAR(255) NOT NULL,
    expires_at DATETIME NOT NULL,
    generated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    used_at DATETIME NULL,
    CONSTRAINT fk_pickup_tokens_package
        FOREIGN KEY (package_id) REFERENCES packages (id),
    CONSTRAINT fk_pickup_tokens_generated_by
        FOREIGN KEY (generated_by_user_id) REFERENCES users (id),
    CONSTRAINT fk_pickup_tokens_validated_by
        FOREIGN KEY (validated_by_user_id) REFERENCES users (id),
    UNIQUE KEY uq_pickup_tokens_package (package_id),
    UNIQUE KEY uq_pickup_tokens_code (token_code),
    KEY idx_pickup_tokens_expires_at (expires_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS notifications (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id BIGINT UNSIGNED NOT NULL,
    package_id BIGINT UNSIGNED NULL,
    channel ENUM('IN_APP', 'WHATSAPP', 'PUSH') NOT NULL,
    title VARCHAR(150) NOT NULL,
    message VARCHAR(255) NOT NULL,
    delivery_status ENUM('PENDING', 'SENT', 'FAILED') NOT NULL DEFAULT 'PENDING',
    metadata_json LONGTEXT NULL,
    sent_at DATETIME NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_notifications_user
        FOREIGN KEY (user_id) REFERENCES users (id),
    CONSTRAINT fk_notifications_package
        FOREIGN KEY (package_id) REFERENCES packages (id),
    KEY idx_notifications_user_created (user_id, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS audit_logs (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    actor_user_id BIGINT UNSIGNED NULL,
    action VARCHAR(80) NOT NULL,
    entity_type VARCHAR(80) NOT NULL,
    entity_id BIGINT UNSIGNED NULL,
    metadata_json LONGTEXT NULL,
    ip_address VARCHAR(45) NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_audit_logs_actor
        FOREIGN KEY (actor_user_id) REFERENCES users (id),
    KEY idx_audit_logs_entity (entity_type, entity_id),
    KEY idx_audit_logs_actor_created (actor_user_id, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS login_attempts (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    login_identifier VARCHAR(150) NOT NULL,
    ip_address VARCHAR(45) NOT NULL,
    was_successful TINYINT(1) NOT NULL DEFAULT 0,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    KEY idx_login_attempts_lookup (login_identifier, ip_address, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
