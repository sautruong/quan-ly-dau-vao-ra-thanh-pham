-- Bổ sung 2 field cho bảng products phục vụ mua hàng thương mại:
--   type        : 'Sản xuất' (tự sản xuất) hoặc 'Thương mại' (mua vào)
--   supplier_id : nhà cung cấp (với hàng thương mại)
ALTER TABLE products
  ADD COLUMN IF NOT EXISTS type
    ENUM('Sản xuất','Thương mại')
    NOT NULL DEFAULT 'Sản xuất'
    AFTER product_name,
  ADD COLUMN IF NOT EXISTS supplier_id INT NULL AFTER type;

-- Liên kết supplier_id tới suppliers(id). Bỏ qua nếu constraint đã tồn tại.
ALTER TABLE products
  ADD CONSTRAINT fk_products_supplier
  FOREIGN KEY (supplier_id) REFERENCES suppliers(id)
  ON DELETE SET NULL
  ON UPDATE CASCADE;

CREATE INDEX IF NOT EXISTS idx_products_type     ON products(type);
CREATE INDEX IF NOT EXISTS idx_products_supplier ON products(supplier_id);
