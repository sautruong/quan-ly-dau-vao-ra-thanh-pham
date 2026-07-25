-- Bảng warehouse_receipts (Hóa đơn nhập kho) cho page row_material_receiving.
-- Phải chạy file này trước khi dùng nút Ghi.
--
-- Link tới stock_import_invoices qua import_invoice_id (cascade) để khi xóa
-- batch ở stock_import_invoices thì row warehouse_receipts tương ứng bị xóa
-- theo, đảm bảo dữ liệu nhất quán.

CREATE TABLE IF NOT EXISTS `warehouse_receipts` (
    `id` INT(11) NOT NULL AUTO_INCREMENT,
    `supplier_id` INT(11) DEFAULT NULL,
    `warehouse_receipt_value` DECIMAL(15,2) NOT NULL DEFAULT 0,
    `purchasing_cost` DECIMAL(15,2) NOT NULL DEFAULT 0,
    `import_invoice_id` INT(11) DEFAULT NULL,
    `created_at` TIMESTAMP NOT NULL DEFAULT current_timestamp(),
    PRIMARY KEY (`id`),
    KEY `idx_warehouse_receipts_supplier` (`supplier_id`),
    KEY `idx_warehouse_receipts_invoice` (`import_invoice_id`),
    CONSTRAINT `fk_wr_supplier`
        FOREIGN KEY (`supplier_id`) REFERENCES `suppliers` (`id`)
        ON DELETE SET NULL ON UPDATE CASCADE,
    CONSTRAINT `fk_wr_invoice`
        FOREIGN KEY (`import_invoice_id`) REFERENCES `stock_import_invoices` (`id`)
        ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
