-- Migration cho page row_material_receiving (Nhập NVL mua).
-- Phải chạy file này trước khi dùng tính năng.
--
-- Thay đổi:
--   1) stock_imports: thêm material_id (nullable, FK material_information),
--      cho product_id nullable, mở rộng ENUM type_import thêm 'row_material_receiving'.
--   2) material_purchase_prices: thêm cột purchase_price_includes_purchase_cost
--      (chứa giá đã bao gồm CPMH = .cell-total / .cell-quantity, làm tròn 0 chữ số).

-- 1) stock_imports
ALTER TABLE `stock_imports`
    MODIFY COLUMN `product_id` INT(11) NULL DEFAULT NULL,
    ADD COLUMN `material_id` INT(11) NULL DEFAULT NULL AFTER `product_id`,
    ADD KEY `idx_stock_imports_material` (`material_id`),
    ADD CONSTRAINT `fk_stock_imports_material`
        FOREIGN KEY (`material_id`) REFERENCES `material_information` (`id`)
        ON DELETE CASCADE ON UPDATE CASCADE;

ALTER TABLE `stock_imports`
    MODIFY COLUMN `type_import`
    ENUM(
        'fg_receipt_production',
        'fg_receipt_purchase',
        'other_receipt',
        'sales_return_receipt',
        'investment_production',
        'row_material_receiving'
    ) NOT NULL;

-- 2) material_purchase_prices
ALTER TABLE `material_purchase_prices`
    ADD COLUMN IF NOT EXISTS `purchase_price_includes_purchase_cost`
        DECIMAL(15,2) NOT NULL DEFAULT 0
        AFTER `purchase_price`;
