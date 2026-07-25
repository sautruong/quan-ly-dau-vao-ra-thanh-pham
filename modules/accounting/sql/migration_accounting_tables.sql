-- Migration: tạo 2 bảng phục vụ chuẩn hóa bút toán kế toán
-- DB: NMSX_VAT
-- accounting_transaction_mapping: 1 dòng / (transaction_group, transaction_name)
-- accounting_transaction_entry  : 2 dòng / mapping (debit + credit)

CREATE TABLE IF NOT EXISTS accounting_transaction_mapping (
    id                  INT AUTO_INCREMENT PRIMARY KEY,
    transaction_group   VARCHAR(64)  NOT NULL COMMENT 'Tên action: investment_products, product_buy, ...',
    transaction_name    VARCHAR(255) NOT NULL COMMENT 'Diễn giải nghiệp vụ (user nhập)',
    description         TEXT NULL              COMMENT 'Mô tả nghiệp vụ (user nhập)',
    created_at          DATETIME DEFAULT CURRENT_TIMESTAMP,
    updated_at          DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uniq_group_name (transaction_group, transaction_name),
    KEY idx_group (transaction_group)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS accounting_transaction_entry (
    id              INT AUTO_INCREMENT PRIMARY KEY,
    mapping_id      INT NOT NULL,
    entry_type      ENUM('debit','credit') NOT NULL,
    account_code    VARCHAR(32)  NOT NULL COMMENT 'Mã tài khoản: 131, 511, 154, 131.1...',
    account_name    VARCHAR(255) NULL     COMMENT 'Tên tài khoản',
    amount_formula  VARCHAR(255) NULL     COMMENT 'Giá trị hoặc công thức',
    created_at      DATETIME DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uniq_mapping_type (mapping_id, entry_type),
    KEY idx_mapping (mapping_id),
    CONSTRAINT fk_entry_mapping
        FOREIGN KEY (mapping_id) REFERENCES accounting_transaction_mapping(id)
        ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
