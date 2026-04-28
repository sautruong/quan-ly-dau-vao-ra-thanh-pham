-- Bảng tồn kho thành phẩm đã có sẵn (finished_goods_inventory) — dùng cột `quantity` làm tồn hiện tại.
-- Ở đây chỉ tạo bảng lịch sử nhập kho (stock_imports).

CREATE TABLE IF NOT EXISTS stock_imports (
    id INT AUTO_INCREMENT PRIMARY KEY,
    product_id INT NOT NULL,
    quantity DECIMAL(10,2) NOT NULL,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,

    CONSTRAINT fk_stock_import_product
    FOREIGN KEY (product_id)
    REFERENCES products(id)
    ON DELETE CASCADE
    ON UPDATE CASCADE
);

CREATE INDEX idx_stock_imports_product ON stock_imports(product_id);
CREATE INDEX idx_stock_imports_created ON stock_imports(created_at);
