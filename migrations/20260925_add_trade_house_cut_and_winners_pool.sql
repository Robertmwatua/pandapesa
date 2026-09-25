-- Run once against the Pandapesa database before deploying the forced
-- settlement logic. Winners keep a fixed 75% profit on their stake; the
-- remaining 25% (the "other" half of the profit split) is recorded here so it
-- can be tracked and manually distributed to winning users later.
ALTER TABLE trades
    ADD COLUMN house_cut DECIMAL(14,2) NOT NULL DEFAULT 0.00 AFTER payout;

CREATE TABLE winners_pool (
    id         BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    trade_id   BIGINT UNSIGNED NOT NULL,
    user_id    BIGINT UNSIGNED NOT NULL,
    amount     DECIMAL(14,2) NOT NULL DEFAULT 0.00,
    paid_out   TINYINT(1) NOT NULL DEFAULT 0,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_winners_pool_trade (trade_id),
    KEY idx_winners_pool_user (user_id, paid_out)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;