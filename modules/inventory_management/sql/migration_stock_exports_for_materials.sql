-- Mở rộng stock_exports để ghi nhận xuất nguyên liệu cho sản xuất:
--   - thêm cột material_id (nullable, FK → material_information)
--   - cho customer_id nullable (export_production không có khách hàng)
--   - mở rộng ENUM type_export thêm 'export_production'
ALTER TABLE `stock_exports`
    MODIFY COLUMN `customer_id` INT(11) NULL DEFAULT NULL,
    MODIFY COLUMN `type_export`
        ENUM('sales_issue', 'other_issue', 'export_production')
        NOT NULL DEFAULT 'sales_issue',
    ADD COLUMN `material_id` INT(11) NULL DEFAULT NULL AFTER `product_id`,
    ADD KEY `idx_stock_exports_material` (`material_id`),
    ADD CONSTRAINT `fk_stock_export_material`
        FOREIGN KEY (`material_id`) REFERENCES `material_information` (`id`)
        ON DELETE CASCADE ON UPDATE CASCADE;
