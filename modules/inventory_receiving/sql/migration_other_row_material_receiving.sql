-- Migration cho page other_row_material_receiving (Nhập NVL khác).
-- Phải chạy file này trước khi dùng tính năng.
--
-- Thay đổi:
--   1) stock_imports.type_import: mở rộng ENUM thêm 'other_row_material_receiving'.

ALTER TABLE `stock_imports`
    MODIFY COLUMN `type_import`
    ENUM(
        'fg_receipt_production',
        'fg_receipt_purchase',
        'other_receipt',
        'sales_return_receipt',
        'investment_production',
        'row_material_receiving',
        'other_row_material_receiving'
    ) NOT NULL;
