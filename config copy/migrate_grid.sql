-- ============================================================
-- Migration: add grid_x / grid_y columns to plots table
-- Run this ONCE on any existing garden_db installation.
-- Safe to run even if columns already exist (uses IF NOT EXISTS).
-- ============================================================

USE garden_db;

-- Add columns only if they don't exist yet (MySQL 8+ / MariaDB 10.3+)
SET @col_x = (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
              WHERE TABLE_SCHEMA = 'garden_db'
                AND TABLE_NAME  = 'plots'
                AND COLUMN_NAME = 'grid_x');

SET @col_y = (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
              WHERE TABLE_SCHEMA = 'garden_db'
                AND TABLE_NAME  = 'plots'
                AND COLUMN_NAME = 'grid_y');

SET @sql_x = IF(@col_x = 0,
    'ALTER TABLE plots ADD COLUMN grid_x TINYINT UNSIGNED DEFAULT NULL AFTER lng',
    'SELECT "grid_x already exists" AS info');

SET @sql_y = IF(@col_y = 0,
    'ALTER TABLE plots ADD COLUMN grid_y TINYINT UNSIGNED DEFAULT NULL AFTER grid_x',
    'SELECT "grid_y already exists" AS info');

PREPARE stmt FROM @sql_x; EXECUTE stmt; DEALLOCATE PREPARE stmt;
PREPARE stmt FROM @sql_y; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- Add unique constraint if not present
ALTER TABLE plots
    ADD UNIQUE IF NOT EXISTS unique_grid_cell (grid_x, grid_y);

-- ── Assign grid positions to existing sample plots ────────────
-- Adjust these UPDATEs to match your actual plot_codes.
UPDATE plots SET grid_x = 1, grid_y = 1 WHERE plot_code = 'A-01' AND grid_x IS NULL;
UPDATE plots SET grid_x = 2, grid_y = 1 WHERE plot_code = 'A-02' AND grid_x IS NULL;
UPDATE plots SET grid_x = 1, grid_y = 2 WHERE plot_code = 'B-01' AND grid_x IS NULL;
UPDATE plots SET grid_x = 2, grid_y = 2 WHERE plot_code = 'B-02' AND grid_x IS NULL;

SELECT plot_code, grid_x, grid_y, status FROM plots ORDER BY grid_y, grid_x;
