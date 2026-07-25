-- =====================================================================
--  Thêm cột `transaction_name` vào bảng `transactions`.
--  Mỗi dòng bút toán (Nợ/Có) ghi kèm "Diễn giải nghiệp vụ" của cụm bút
--  toán sinh ra nó (page Nhập thu chi). Nhờ đó:
--    - mỗi diễn giải nghiệp vụ giữ đúng "Mô tả nghiệp vụ" riêng (tra từ
--      accounting_transaction_mapping theo transaction_name);
--    - khi "Sửa" phiếu, dựng lại đúng & khớp các cụm bút toán đã ghi.
--  Cột NULLABLE: các module khác không ghi cột này vẫn hoạt động bình thường.
-- =====================================================================
ALTER TABLE `transactions`
    ADD COLUMN `transaction_name` VARCHAR(255) NULL DEFAULT NULL
    COMMENT 'Diễn giải nghiệp vụ của cụm bút toán (cash_transactions)'
    AFTER `reference_id`;
