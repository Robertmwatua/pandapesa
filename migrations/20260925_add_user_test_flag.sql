-- Run once against the Pandapesa database before deploying admin test accounts.
-- Test accounts can have their wallet balance set from the admin Users tab and
-- are blocked from requesting withdrawals.
ALTER TABLE users
    ADD COLUMN is_test TINYINT(1) NOT NULL DEFAULT 0 AFTER banned;
