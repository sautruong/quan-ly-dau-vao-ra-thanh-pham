-- Bảng lịch sử xuất kho bán hàng (stock_exports)
-- Mỗi dòng tương ứng 1 sản phẩm xuất cho 1 khách hàng. Group theo (created_at, customer_id)
-- là 1 phiếu xuất (giống cách stock_imports group theo created_at).

CREATE TABLE IF NOT EXISTS stock_exports (
    id INT AUTO_INCREMENT PRIMARY KEY,
    product_id INT NOT NULL,
    customer_id INT NOT NULL,
    quantity DECIMAL(10,2) NOT NULL,
    unit_price DECIMAL(15,2) DEFAULT 0,
    total_amount DECIMAL(15,2) DEFAULT 0,
    interpretation TEXT DEFAULT NULL,
    type_export ENUM('sales_issue','other_issue') NOT NULL DEFAULT 'sales_issue',
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,

    CONSTRAINT fk_stock_export_product
        FOREIGN KEY (product_id) REFERENCES products(id)
        ON DELETE CASCADE ON UPDATE CASCADE,
    CONSTRAINT fk_stock_export_customer
        FOREIGN KEY (customer_id) REFERENCES customers(id)
        ON DELETE CASCADE ON UPDATE CASCADE
);

CREATE INDEX idx_stock_exports_product  ON stock_exports(product_id);
CREATE INDEX idx_stock_exports_customer ON stock_exports(customer_id);
CREATE INDEX idx_stock_exports_created  ON stock_exports(created_at);
CREATE INDEX idx_stock_exports_type     ON stock_exports(type_export);
