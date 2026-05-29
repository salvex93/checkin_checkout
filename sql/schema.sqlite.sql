-- Schema SQLite para desarrollo local.
-- Equivalente funcional al schema MySQL. ENUM se expresa como CHECK constraint.

PRAGMA foreign_keys = ON;

CREATE TABLE IF NOT EXISTS brands (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    slug TEXT NOT NULL UNIQUE,
    name TEXT NOT NULL,
    logo_url TEXT NOT NULL,
    primary_color TEXT NOT NULL DEFAULT '#2563eb',
    secondary_color TEXT NULL,
    welcome_intro TEXT NULL,
    is_active INTEGER NOT NULL DEFAULT 1,
    created_at TEXT DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE IF NOT EXISTS tenant_settings (
    id INTEGER PRIMARY KEY,
    product_name TEXT NOT NULL DEFAULT 'Melius Clockin',
    logo_url TEXT NULL,
    primary_color TEXT NOT NULL DEFAULT '#07d6da',
    secondary_color TEXT NULL,
    updated_at TEXT DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE IF NOT EXISTS subscription_plans (
    code TEXT PRIMARY KEY,
    name TEXT NOT NULL,
    price_monthly_cents INTEGER NOT NULL DEFAULT 0,
    currency TEXT NOT NULL DEFAULT 'USD',
    max_users INTEGER NULL,
    max_companies INTEGER NULL,
    features TEXT NULL,
    is_active INTEGER NOT NULL DEFAULT 1,
    sort_order INTEGER NOT NULL DEFAULT 0
);

CREATE TABLE IF NOT EXISTS subscriptions (
    id INTEGER PRIMARY KEY,
    plan_code TEXT NOT NULL DEFAULT 'free',
    provider TEXT NOT NULL DEFAULT 'none',
    provider_customer_id TEXT NULL,
    provider_subscription_id TEXT NULL,
    status TEXT NOT NULL DEFAULT 'trial',
    current_period_start TEXT NULL,
    current_period_end TEXT NULL,
    cancel_at_period_end INTEGER NOT NULL DEFAULT 0,
    metadata TEXT NULL,
    created_at TEXT DEFAULT CURRENT_TIMESTAMP,
    updated_at TEXT DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (plan_code) REFERENCES subscription_plans(code)
);

CREATE TABLE IF NOT EXISTS companies (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    name TEXT NOT NULL UNIQUE,
    brand_id INTEGER NULL,
    branding_logo_url TEXT NULL,
    branding_primary TEXT NULL,
    branding_secondary TEXT NULL,
    timezone TEXT NOT NULL DEFAULT 'America/Mexico_City',
    work_start_time TEXT NOT NULL DEFAULT '09:00',
    work_end_time TEXT NOT NULL DEFAULT '18:00',
    work_days_mask INTEGER NOT NULL DEFAULT 31,
    grace_minutes_late INTEGER NOT NULL DEFAULT 15,
    is_configured INTEGER NOT NULL DEFAULT 1,
    created_at TEXT DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (brand_id) REFERENCES brands(id) ON DELETE SET NULL
);

CREATE INDEX IF NOT EXISTS idx_companies_brand ON companies(brand_id);

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
    client_timezone TEXT NULL,
    tz_mismatch INTEGER NOT NULL DEFAULT 0,
    closed_reason TEXT NULL CHECK (closed_reason IN ('normal','forgotten','overtime') OR closed_reason IS NULL),
    overtime_hours REAL NOT NULL DEFAULT 0,
    overtime_status TEXT NOT NULL DEFAULT 'none' CHECK (overtime_status IN ('none','pending','approved','rejected')),
    geo_country_code TEXT NULL,
    geo_country_name TEXT NULL,
    geo_city TEXT NULL,
    geo_region TEXT NULL,
    geo_lat REAL NULL,
    geo_lon REAL NULL,
    geo_ip_masked TEXT NULL,
    geo_source TEXT NULL,
    geo_alert_flag INTEGER NOT NULL DEFAULT 0,
    geo_alert_reasons TEXT NULL,
    geo_exit_country_code TEXT NULL,
    geo_exit_city TEXT NULL,
    geo_exit_lat REAL NULL,
    geo_exit_lon REAL NULL,
    created_at TEXT DEFAULT CURRENT_TIMESTAMP,
    UNIQUE (user_id, work_date),
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
);

CREATE INDEX IF NOT EXISTS idx_records_user ON attendance_records(user_id);
CREATE INDEX IF NOT EXISTS idx_records_alert ON attendance_records(geo_alert_flag);

CREATE TABLE IF NOT EXISTS location_alerts (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    user_id INTEGER NOT NULL,
    attendance_id INTEGER NOT NULL,
    triggered_at TEXT DEFAULT CURRENT_TIMESTAMP,
    reason_codes TEXT NOT NULL,
    prev_country_code TEXT NULL,
    prev_city TEXT NULL,
    prev_lat REAL NULL,
    prev_lon REAL NULL,
    prev_marked_at TEXT NULL,
    curr_country_code TEXT NULL,
    curr_city TEXT NULL,
    curr_lat REAL NULL,
    curr_lon REAL NULL,
    distance_km REAL NULL,
    elapsed_minutes INTEGER NULL,
    implied_speed_kmh REAL NULL,
    status TEXT NOT NULL DEFAULT 'pending' CHECK (status IN ('pending','reviewed','dismissed')),
    reviewed_by INTEGER NULL,
    reviewed_at TEXT NULL,
    notes TEXT NULL,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    FOREIGN KEY (attendance_id) REFERENCES attendance_records(id) ON DELETE CASCADE,
    FOREIGN KEY (reviewed_by) REFERENCES users(id) ON DELETE SET NULL
);

CREATE INDEX IF NOT EXISTS idx_locales_status ON location_alerts(status, triggered_at);
CREATE INDEX IF NOT EXISTS idx_locales_user ON location_alerts(user_id);

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
    geo_country_code TEXT NULL,
    geo_country_name TEXT NULL,
    geo_ip_masked TEXT NULL,
    geo_source TEXT NULL,
    requested_at TEXT DEFAULT CURRENT_TIMESTAMP,
    resolved_at TEXT NULL,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    FOREIGN KEY (record_id) REFERENCES attendance_records(id) ON DELETE CASCADE,
    FOREIGN KEY (referenced_request_id) REFERENCES overtime_requests(id) ON DELETE CASCADE
);

CREATE TABLE IF NOT EXISTS terms_versions (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    version TEXT NOT NULL UNIQUE,
    title TEXT NOT NULL,
    body_html TEXT NOT NULL,
    privacy_html TEXT NOT NULL,
    published_at TEXT DEFAULT CURRENT_TIMESTAMP,
    is_active INTEGER NOT NULL DEFAULT 0
);

CREATE TABLE IF NOT EXISTS user_terms_acceptance (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    user_id INTEGER NOT NULL,
    terms_version_id INTEGER NOT NULL,
    accepted_at TEXT DEFAULT CURRENT_TIMESTAMP,
    ip_address TEXT NULL,
    user_agent TEXT NULL,
    UNIQUE (user_id, terms_version_id),
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    FOREIGN KEY (terms_version_id) REFERENCES terms_versions(id) ON DELETE CASCADE
);

CREATE INDEX IF NOT EXISTS idx_uta_user ON user_terms_acceptance(user_id);

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

CREATE TABLE IF NOT EXISTS email_templates (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    brand_id INTEGER NOT NULL,
    kind TEXT NOT NULL CHECK (kind IN ('invitation','password_reset','admin_disabled','admin_delete_receipt','location_alert')),
    subject TEXT NOT NULL,
    intro_html TEXT NOT NULL,
    cta_label TEXT NULL,
    updated_at TEXT DEFAULT CURRENT_TIMESTAMP,
    updated_by INTEGER NULL,
    UNIQUE (brand_id, kind),
    FOREIGN KEY (brand_id) REFERENCES brands(id) ON DELETE CASCADE,
    FOREIGN KEY (updated_by) REFERENCES users(id) ON DELETE SET NULL
);

CREATE INDEX IF NOT EXISTS idx_etpl_brand ON email_templates(brand_id);

CREATE TABLE IF NOT EXISTS vacation_requests (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    user_id INTEGER NOT NULL,
    company_id INTEGER NULL,
    start_date TEXT NOT NULL,
    end_date TEXT NOT NULL,
    days_count INTEGER NOT NULL,
    reason TEXT NULL,
    status TEXT NOT NULL DEFAULT 'pending' CHECK (status IN ('pending','approved','rejected','cancelled')),
    decided_by INTEGER NULL,
    decided_at TEXT NULL,
    decision_note TEXT NULL,
    created_at TEXT DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    FOREIGN KEY (company_id) REFERENCES companies(id) ON DELETE SET NULL,
    FOREIGN KEY (decided_by) REFERENCES users(id) ON DELETE SET NULL
);

CREATE INDEX IF NOT EXISTS idx_vac_user ON vacation_requests(user_id);
CREATE INDEX IF NOT EXISTS idx_vac_status ON vacation_requests(status, start_date);

-- Seed marcas paraguas
INSERT OR IGNORE INTO brands (slug, name, logo_url, primary_color, secondary_color) VALUES
    ('melius',  'Melius Services',  '/assets/brands/melius.webp',  '#07d6da', '#9909fe'),
    ('fullman', 'Fullman Strategy', '/assets/brands/fullman.webp', '#65422a', '#f2e484'),
    ('netfy',   'Netfy Technology', '/assets/brands/netfy.webp',   '#06c7f4', '#2c2e3a');

-- Seed empresas cliente con marca paraguas asignada
INSERT OR IGNORE INTO companies (name, brand_id, timezone, work_start_time, work_end_time, work_days_mask, grace_minutes_late)
VALUES
    ('Melius Services', (SELECT id FROM brands WHERE slug='melius'),  'America/Mexico_City', '09:00', '18:00', 31, 15),
    ('Arajet',          (SELECT id FROM brands WHERE slug='melius'),  'America/Mexico_City', '09:00', '18:00', 31, 15),
    ('Elektra',         (SELECT id FROM brands WHERE slug='melius'),  'America/Mexico_City', '09:00', '18:00', 31, 15),
    ('Ecuaquimica',     (SELECT id FROM brands WHERE slug='melius'),  'America/Mexico_City', '09:00', '18:00', 31, 15),
    ('Coppel',          (SELECT id FROM brands WHERE slug='fullman'), 'America/Mexico_City', '09:00', '18:00', 31, 15),
    ('BanCoppel',       (SELECT id FROM brands WHERE slug='fullman'), 'America/Mexico_City', '09:00', '18:00', 31, 15),
    ('Hyatt',           (SELECT id FROM brands WHERE slug='netfy'),   'America/Mexico_City', '09:00', '18:00', 31, 15),
    ('Philip Morris',   (SELECT id FROM brands WHERE slug='netfy'),   'America/Mexico_City', '09:00', '18:00', 31, 15);
