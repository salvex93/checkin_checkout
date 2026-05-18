-- =====================================================================
-- fix_overtime_mysql.sql — Repara overtime_requests en produccion (MySQL)
-- Anade las columnas request_type, referenced_request_id y new_hours si
-- faltan. Idempotente: comprueba INFORMATION_SCHEMA antes de cada ALTER.
-- Ejecucion: pegar en phpMyAdmin > SQL contra la BD del cPanel.
-- =====================================================================

SET @db := DATABASE();

-- 1) request_type ENUM('new','edit') NOT NULL DEFAULT 'new'
SET @col := (SELECT COUNT(*) FROM information_schema.columns
             WHERE table_schema = @db
               AND table_name   = 'overtime_requests'
               AND column_name  = 'request_type');
SET @sql := IF(@col = 0,
    "ALTER TABLE overtime_requests ADD COLUMN request_type ENUM('new','edit') NOT NULL DEFAULT 'new'",
    "SELECT 'request_type ya existe' AS info");
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- 2) referenced_request_id INT NULL
SET @col := (SELECT COUNT(*) FROM information_schema.columns
             WHERE table_schema = @db
               AND table_name   = 'overtime_requests'
               AND column_name  = 'referenced_request_id');
SET @sql := IF(@col = 0,
    "ALTER TABLE overtime_requests ADD COLUMN referenced_request_id INT NULL",
    "SELECT 'referenced_request_id ya existe' AS info");
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- 3) new_hours DECIMAL(3,1) NULL
SET @col := (SELECT COUNT(*) FROM information_schema.columns
             WHERE table_schema = @db
               AND table_name   = 'overtime_requests'
               AND column_name  = 'new_hours');
SET @sql := IF(@col = 0,
    "ALTER TABLE overtime_requests ADD COLUMN new_hours DECIMAL(3,1) NULL",
    "SELECT 'new_hours ya existe' AS info");
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- 4) Indice por request_type
SET @idx := (SELECT COUNT(*) FROM information_schema.statistics
             WHERE table_schema = @db
               AND table_name   = 'overtime_requests'
               AND index_name   = 'idx_otreq_type');
SET @sql := IF(@idx = 0,
    "CREATE INDEX idx_otreq_type ON overtime_requests(request_type)",
    "SELECT 'idx_otreq_type ya existe' AS info");
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- Verificacion final
SELECT column_name, column_type, is_nullable, column_default
FROM information_schema.columns
WHERE table_schema = DATABASE() AND table_name = 'overtime_requests'
ORDER BY ordinal_position;
