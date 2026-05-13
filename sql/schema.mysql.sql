-- Schema MySQL para HostGator (produccion).
-- En desarrollo local usamos SQLite (sql/schema.sqlite.sql), mismo modelo.
--
-- Convencion work_days_mask: bitmask con bit 0 = Lunes, ..., bit 6 = Domingo.
--   L-V       = 0b0011111 = 31
--   L-S       = 0b0111111 = 63
--   Solo L-V-V= 0b0010101 = 21
-- Tiempos como VARCHAR(5) "HH:MM" para evitar problemas de TIME vs TZ en SQLite.

SET NAMES utf8mb4;
SET FOREIGN_KEY_CHECKS = 0;

CREATE TABLE IF NOT EXISTS companies (
    id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(120) NOT NULL UNIQUE,
    timezone VARCHAR(64) NOT NULL DEFAULT 'America/Mexico_City',
    work_start_time VARCHAR(5) NOT NULL DEFAULT '09:00',
    work_end_time VARCHAR(5) NOT NULL DEFAULT '18:00',
    work_days_mask INT NOT NULL DEFAULT 31,
    grace_minutes_late INT NOT NULL DEFAULT 15,
    is_configured TINYINT(1) NOT NULL DEFAULT 1,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS users (
    id INT AUTO_INCREMENT PRIMARY KEY,
    email VARCHAR(190) NOT NULL UNIQUE,
    name VARCHAR(120) NOT NULL,
    password_hash VARCHAR(255) NULL,
    role ENUM('consultant','admin','super_admin') NOT NULL DEFAULT 'consultant',
    company_id INT NULL,
    timezone VARCHAR(64) NULL,
    work_start_time VARCHAR(5) NULL,
    work_end_time VARCHAR(5) NULL,
    work_days_mask INT NULL,
    is_active TINYINT(1) NOT NULL DEFAULT 1,
    status ENUM('pending_confirmation','active','disabled') NOT NULL DEFAULT 'active',
    email_verified_at DATETIME NULL,
    failed_attempts INT NOT NULL DEFAULT 0,
    locked_until DATETIME NULL,
    must_change_password TINYINT(1) NOT NULL DEFAULT 0,
    password_changed_at DATETIME NULL,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_users_company (company_id),
    INDEX idx_users_status (status),
    CONSTRAINT fk_users_company FOREIGN KEY (company_id) REFERENCES companies(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS invitations (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    token VARCHAR(64) NOT NULL UNIQUE,
    email VARCHAR(190) NOT NULL,
    expires_at DATETIME NOT NULL,
    consumed_at DATETIME NULL,
    created_by INT NULL,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_invitations_token (token),
    INDEX idx_invitations_user (user_id),
    CONSTRAINT fk_invitations_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    CONSTRAINT fk_invitations_created_by FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS password_reset_tokens (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    token_hash VARCHAR(128) NOT NULL UNIQUE,
    expires_at DATETIME NOT NULL,
    consumed_at DATETIME NULL,
    ip_address VARCHAR(45) NULL,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_prtokens_user (user_id),
    INDEX idx_prtokens_expires (expires_at),
    CONSTRAINT fk_prtokens_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS attendance_records (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    work_date DATE NOT NULL,
    entry_time TIME NOT NULL,
    exit_time TIME NULL,
    timezone VARCHAR(64) NULL,
    closed_reason ENUM('normal','forgotten','overtime') NULL,
    overtime_hours DECIMAL(3,1) NOT NULL DEFAULT 0,
    overtime_status ENUM('none','pending','approved','rejected') NOT NULL DEFAULT 'none',
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uniq_user_date (user_id, work_date),
    INDEX idx_records_user (user_id),
    CONSTRAINT fk_records_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS change_requests (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    old_company_id INT NULL,
    new_company_id INT NOT NULL,
    status ENUM('pending','approved','rejected') NOT NULL DEFAULT 'pending',
    requested_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    resolved_at DATETIME NULL,
    INDEX idx_chreq_status (status),
    CONSTRAINT fk_chreq_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    CONSTRAINT fk_chreq_new_company FOREIGN KEY (new_company_id) REFERENCES companies(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS overtime_requests (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    record_id INT NOT NULL,
    hours DECIMAL(3,1) NOT NULL,
    reason VARCHAR(240) NULL,
    status ENUM('pending','approved','rejected') NOT NULL DEFAULT 'pending',
    requested_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    resolved_at DATETIME NULL,
    INDEX idx_otreq_status (status),
    CONSTRAINT fk_otreq_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    CONSTRAINT fk_otreq_record FOREIGN KEY (record_id) REFERENCES attendance_records(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS audit_log (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NULL,
    event VARCHAR(80) NOT NULL,
    ip VARCHAR(45) NULL,
    user_agent VARCHAR(255) NULL,
    metadata JSON NULL,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_audit_user_event (user_id, event),
    INDEX idx_audit_created (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

SET FOREIGN_KEY_CHECKS = 1;

-- Seed inicial: empresas con defaults Mexico City L-V 09:00-18:00
INSERT INTO companies (name, timezone, work_start_time, work_end_time, work_days_mask, grace_minutes_late) VALUES
    ('Melius Services', 'America/Mexico_City', '09:00', '18:00', 31, 15),
    ('Coppel',          'America/Mexico_City', '09:00', '18:00', 31, 15),
    ('Hyatt',           'America/Mexico_City', '09:00', '18:00', 31, 15),
    ('Arajet',          'America/Mexico_City', '09:00', '18:00', 31, 15)
ON DUPLICATE KEY UPDATE name = name;
