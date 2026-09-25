-- Run once against the Pandapesa database before deploying admin user management.
ALTER TABLE users
    ADD COLUMN banned TINYINT(1) NOT NULL DEFAULT 0 AFTER balance;