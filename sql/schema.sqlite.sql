-- Schema SQLite para desarrollo local.
-- Equivalente funcional al schema MySQL. ENUM se expresa como CHECK constraint.

PRAGMA foreign_keys = ON;

CREATE TABLE IF NOT EXISTS companies (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    name TEXT NOT NULL UNIQUE,
    timezone TEXT NOT NULL DEFAULT 'America/Mexico_City',
    work_start_time TEXT NOT NULL DEFAULT '09:00',
    work_end_time TEXT NOT NULL DEFAULT '18:00',
    work_days_mask INTEGER NOT NULL DEFAULT 31,
    grace_minutes_late INTEGER NOT NULL DEFAULT 15,
    is_configured INTEGER NOT NULL DEFAULT 1,
    created_at TEXT DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE IF NOT EXISTS users (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    email TEXT NOT NULL UNIQUE,
    name TEXT NOT NULL,
    password_hash TEXT NULL,
    role TEXT NOT NULL DEFAULT 'consultant' CHECK (role IN ('consultant','admin','super_admin')),
    company_id INTEGER NULL,
    timezone TEXT NULL,
    work_start_time TEXT NULL,
    work_end_time TEXT NULL,
    work_days_mask INTEGER NULL,
    is_active INTEGER NOT NULL DEFAULT 1,
    status TEXT NOT NULL DEFAULT 'active' CHECK (status IN ('pending_confirmation','active','disabled')),
    email_verified_at TEXT NULL,
    failed_attempts INTEGER NOT NULL DEFAULT 0,
    locked_until TEXT NULL,
    must_change_password INTEGER NOT NULL DEFAULT 0,
    password_changed_at TEXT NULL,
    created_at TEXT DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (company_id) REFERENCES companies(id) ON DELETE SET NULL
);

CREATE INDEX IF NOT EXISTS idx_users_company ON users(company_id);
CREATE INDEX IF NOT EXISTS idx_users_status ON users(status);

CREATE TABLE IF NOT EXISTS invitations (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    user_id INTEGER NOT NULL,
    token TEXT NOT NULL UNIQUE,
    email TEXT NOT NULL,
    expires_at TEXT NOT NULL,
    consumed_at TEXT NULL,
    created_by INTEGER NULL,
    created_at TEXT DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL
);

CREATE INDEX IF NOT EXISTS idx_invitations_token ON invitations(token);
CREATE INDEX IF NOT EXISTS idx_invitations_user ON invitations(user_id);

CREATE TABLE IF NOT EXISTS password_reset_tokens (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    user_id INTEGER NOT NULL,
    token_hash TEXT NOT NULL UNIQUE,
    expires_at TEXT NOT NULL,
    consumed_at TEXT NULL,
    ip_address TEXT NULL,
    created_at TEXT DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
);

CREATE INDEX IF NOT EXISTS idx_prtokens_user ON password_reset_tokens(user_id);
CREATE INDEX IF NOT EXISTS idx_prtokens_expires ON password_reset_tokens(expires_at);

CREATE TABLE IF NOT EXISTS attendance_records (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    user_id INTEGER NOT NULL,
    work_date TEXT NOT NULL,
    entry_time TEXT NOT NULL,
    exit_time TEXT NULL,
    timezone TEXT NULL,
    closed_reason TEXT NULL CHECK (closed_reason IN ('normal','forgotten','overtime') OR closed_reason IS NULL),
    overtime_hours REAL NOT NULL DEFAULT 0,
    overtime_status TEXT NOT NULL DEFAULT 'none' CHECK (overtime_status IN ('none','pending','approved','rejected')),
    created_at TEXT DEFAULT CURRENT_TIMESTAMP,
    UNIQUE (user_id, work_date),
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
);

CREATE INDEX IF NOT EXISTS idx_records_user ON attendance_records(user_id);

CREATE TABLE IF NOT EXISTS change_requests (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    user_id INTEGER NOT NULL,
    old_company_id INTEGER NULL,
    new_company_id INTEGER NOT NULL,
    status TEXT NOT NULL DEFAULT 'pending' CHECK (status IN ('pending','approved','rejected')),
    requested_at TEXT DEFAULT CURRENT_TIMESTAMP,
    resolved_at TEXT NULL,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    FOREIGN KEY (new_company_id) REFERENCES companies(id) ON DELETE CASCADE
);

CREATE INDEX IF NOT EXISTS idx_chreq_status ON change_requests(status);

CREATE TABLE IF NOT EXISTS overtime_requests (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    user_id INTEGER NOT NULL,
    record_id INTEGER NOT NULL,
    hours REAL NOT NULL,
    reason TEXT NULL,
    status TEXT NOT NULL DEFAULT 'pending' CHECK (status IN ('pending','approved','rejected')),
    request_type TEXT NOT NULL DEFAULT 'new' CHECK (request_type IN ('new','edit')),
    referenced_request_id INTEGER NULL,
    new_hours REAL NULL,
    requested_at TEXT DEFAULT CURRENT_TIMESTAMP,
    resolved_at TEXT NULL,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    FOREIGN KEY (record_id) REFERENCES attendance_records(id) ON DELETE CASCADE,
    FOREIGN KEY (referenced_request_id) REFERENCES overtime_requests(id) ON DELETE CASCADE
);

CREATE INDEX IF NOT EXISTS idx_otreq_status ON overtime_requests(status);
CREATE INDEX IF NOT EXISTS idx_otreq_type ON overtime_requests(request_type);

CREATE TABLE IF NOT EXISTS audit_log (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    user_id INTEGER NULL,
    event TEXT NOT NULL,
    ip TEXT NULL,
    user_agent TEXT NULL,
    metadata TEXT NULL,
    created_at TEXT DEFAULT CURRENT_TIMESTAMP
);

CREATE INDEX IF NOT EXISTS idx_audit_user_event ON audit_log(user_id, event);
CREATE INDEX IF NOT EXISTS idx_audit_created ON audit_log(created_at);

-- Seed: empresas con defaults Mexico City L-V 09:00-18:00
INSERT OR IGNORE INTO companies (name, timezone, work_start_time, work_end_time, work_days_mask, grace_minutes_late)
VALUES
    ('Melius Services', 'America/Mexico_City', '09:00', '18:00', 31, 15),
    ('Coppel',          'America/Mexico_City', '09:00', '18:00', 31, 15),
    ('Hyatt',           'America/Mexico_City', '09:00', '18:00', 31, 15),
    ('Arajet',          'America/Mexico_City', '09:00', '18:00', 31, 15);
