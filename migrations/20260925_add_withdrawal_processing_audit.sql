-- Run once against the Pandapesa database before deploying the admin withdrawal queue.
ALTER TABLE withdrawals
    ADD COLUMN payment_reference VARCHAR(100) NULL AFTER payout_phone,
    ADD COLUMN processed_at DATETIME NULL AFTER created_at,
    ADD COLUMN processed_by INT NULL AFTER processed_at;
