-- Migration: lưu các bút toán kế toán (nghiệp vụ) ĐÃ NHẬP cho từng phiếu
-- "Xuất kho khác" (other_export_outbound), để khi bấm "Sửa" ở lịch sử chỉ đổ lại
-- đúng các nghiệp vụ đã nhập cho phiếu đó (không phải toàn bộ thư viện nghiệp vụ).
--
-- Khóa theo (reference_type, reference_id) — reference_id = stock_exports.id nhỏ nhất
-- của batch (giống reference_id dùng cho bảng transactions).
-- entries_json giữ NGUYÊN payload từ giao diện: transaction_name, debit/credit,
-- debit_name/credit_name, amount, description (đủ field để dựng lại cụm bút toán).

CREATE TABLE IF NOT EXISTS `outbound_journal_entries` (
    `id`             INT AUTO_INCREMENT PRIMARY KEY,
    `reference_type` VARCHAR(64) NOT NULL COMMENT 'other_export_outbound, ...',
    `reference_id`   INT         NOT NULL COMMENT 'stock_exports.id nhỏ nhất của batch',
    `entries_json`   MEDIUMTEXT  NULL     COMMENT 'JSON mảng bút toán đã nhập',
    `created_at`     DATETIME DEFAULT CURRENT_TIMESTAMP,
    `updated_at`     DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY `uniq_ref` (`reference_type`, `reference_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
