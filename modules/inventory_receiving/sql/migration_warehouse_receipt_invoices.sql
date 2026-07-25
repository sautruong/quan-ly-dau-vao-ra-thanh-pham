-- Bảng warehouse_receipt_invoices — lưu ảnh/file hóa đơn đính kèm cho:
--   * purchase_invoice     : hóa đơn MUA HÀNG  -> wr_id = warehouse_receipts.id
--   * sales_invoice        : hóa đơn BÁN HÀNG (lịch sử) -> wr_id = sales_orders.id
--   * sales_export_invoice : hóa đơn BÁN HÀNG (phiếu xuất kho mới)
--                            -> wr_id = sales_warehouse_export_invoices.id
--
-- LƯU Ý THIẾT KẾ:
--   wr_id là khóa "đa hình" (polymorphic) theo cột `type`. Vì hóa đơn bán hàng
--   tham chiếu sales_orders (id tới hàng trăm) chứ không tồn tại trong
--   warehouse_receipts, KHÔNG thể ràng buộc FOREIGN KEY cứng tới warehouse_receipts
--   (sẽ chặn mọi sales_invoice). Do đó wr_id chỉ là FK logic + index.
--
-- File này idempotent: tạo bảng nếu chưa có, và gỡ FK cứng cũ (nếu tồn tại từ
-- phiên bản trước) để hỗ trợ lưu sales_invoice.

CREATE TABLE IF NOT EXISTS `warehouse_receipt_invoices` (
    `id` INT(11) NOT NULL AUTO_INCREMENT,
    `wr_id` INT(11) NOT NULL,
    `type` ENUM('purchase_invoice','sales_invoice','sales_export_invoice') NOT NULL,
    `file_url` VARCHAR(500) NOT NULL,
    `created_at` TIMESTAMP NOT NULL DEFAULT current_timestamp(),
    PRIMARY KEY (`id`),
    KEY `idx_wri_wr_type` (`wr_id`, `type`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- Mở rộng ENUM cho bảng đã tồn tại từ phiên bản trước (idempotent — chạy lại không lỗi):
-- thêm 'sales_export_invoice' để gắn hóa đơn cho phiếu xuất kho bán hàng mới.
ALTER TABLE `warehouse_receipt_invoices`
    MODIFY `type` ENUM('purchase_invoice','sales_invoice','sales_export_invoice') NOT NULL;

-- Gỡ ràng buộc FK cứng cũ (nếu có) — không fail nếu không tồn tại.
SET @fk := (SELECT CONSTRAINT_NAME FROM information_schema.TABLE_CONSTRAINTS
            WHERE TABLE_SCHEMA = DATABASE()
              AND TABLE_NAME = 'warehouse_receipt_invoices'
              AND CONSTRAINT_TYPE = 'FOREIGN KEY'
            LIMIT 1);
SET @sql := IF(@fk IS NOT NULL,
    CONCAT('ALTER TABLE `warehouse_receipt_invoices` DROP FOREIGN KEY `', @fk, '`'),
    'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- Đảm bảo có index (wr_id, type) sau khi đã gỡ FK.
SET @idx := (SELECT COUNT(*) FROM information_schema.STATISTICS
             WHERE TABLE_SCHEMA = DATABASE()
               AND TABLE_NAME = 'warehouse_receipt_invoices'
               AND INDEX_NAME = 'idx_wri_wr_type');
SET @sql := IF(@idx = 0,
    'ALTER TABLE `warehouse_receipt_invoices` ADD INDEX `idx_wri_wr_type` (`wr_id`, `type`)',
    'SELECT 1');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;
