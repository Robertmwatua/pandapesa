-- Run once against the Pandapesa database before deploying the withdrawal API.
ALTER TABLE withdrawals
    ADD COLUMN payout_phone VARCHAR(15) NULL AFTER amount;
