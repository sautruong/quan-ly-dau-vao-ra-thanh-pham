-- Bổ sung giá trị 'investment_production' cho cột type_import (page Nhập giá vốn sản xuất).
ALTER TABLE `stock_imports`
    MODIFY COLUMN `type_import`
    ENUM(
        'fg_receipt_production',
        'fg_receipt_purchase',
        'other_receipt',
        'sales_return_receipt',
        'investment_production'
    ) NOT NULL;
