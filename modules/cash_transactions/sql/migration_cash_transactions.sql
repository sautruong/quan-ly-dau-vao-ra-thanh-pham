-- =====================================================================
--  Bảng lưu lịch sử hoạt động nhập thu/chi (page: Nhập thu chi).
--  Mỗi dòng = 1 phiếu thu (Thu) hoặc chi (Chi).
--    description       : Diễn giải (user nhập)
--    transaction_type  : Phân loại — 'Thu' | 'Chi'
--    account_name      : Tài khoản — 'TM' (Tiền mặt) | 'TG' (Tiền gửi)
--    account_number    : Số tài khoản — chỉ bắt buộc khi account_name = 'TG'
--    amount            : Số tiền
--    created_at        : Thời điểm ghi (theo datetime-local trên giao diện)
-- =====================================================================
CREATE TABLE IF NOT EXISTS `cash_transactions` (
    `id`               INT(11)        NOT NULL AUTO_INCREMENT,
    `description`      VARCHAR(255)   NOT NULL,
    `transaction_type` VARCHAR(100)   NOT NULL,
    `account_name`     VARCHAR(150)   NOT NULL,
    `account_number`   VARCHAR(50)    DEFAULT NULL,
    `amount`           DECIMAL(15, 2) NOT NULL,
    `created_at`       TIMESTAMP      NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `idx_cash_transactions_created_at` (`created_at`),
    KEY `idx_cash_transactions_type` (`transaction_type`)
) ENGINE = InnoDB DEFAULT CHARSET = utf8mb4 COLLATE = utf8mb4_unicode_ci;
