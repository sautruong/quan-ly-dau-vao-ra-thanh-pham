-- Bổ sung cột type_import để phân loại nguồn nhập kho:
--   fg_receipt_production : nhập từ kế hoạch sản xuất (page dashboard)
--   fg_receipt_purchase   : nhập từ mua hàng thương mại  (page product_buy)
--   other_receipt         : nhập kho khác (cập nhật sau)
ALTER TABLE stock_imports
  ADD COLUMN IF NOT EXISTS type_import
    ENUM('fg_receipt_production','fg_receipt_purchase','other_receipt')
    NOT NULL DEFAULT 'fg_receipt_production'
    AFTER interpretation;

CREATE INDEX IF NOT EXISTS idx_stock_imports_type ON stock_imports(type_import);
