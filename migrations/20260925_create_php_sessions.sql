-- Run once against the Pandapesa database before deploying MySQL-backed sessions.
-- (api/session.php also creates this table on first use if it is missing.)
CREATE TABLE IF NOT EXISTS php_sessions (
    id VARCHAR(128) NOT NULL,
    data MEDIUMBLOB NOT NULL,
    expires_at INT UNSIGNED NOT NULL,
    PRIMARY KEY (id),
    KEY idx_php_sessions_expires (expires_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
