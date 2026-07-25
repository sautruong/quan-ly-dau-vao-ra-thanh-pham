-- Migration: hỗ trợ "Nhập thành phẩm mua hàng" (page product_buy).
-- 1) stock_imports lưu thêm unit_price (đơn giá tại thời điểm Ghi)
--    và import_invoice_id (link tới phiếu nhập tổng stock_import_invoices).
-- 2) Bảng mới stock_import_purchase_costs lưu các CPMH (Chi Phí Mua Hàng)
--    rời theo từng dòng stock_imports — mỗi dòng có description + price.

ALTER TABLE stock_imports
    ADD COLUMN unit_price DECIMAL(15,2) DEFAULT NULL AFTER quantity;

ALTER TABLE stock_imports
    ADD COLUMN import_invoice_id INT(11) DEFAULT NULL AFTER type_import,
    ADD KEY idx_stock_imports_invoice (import_invoice_id),
    ADD CONSTRAINT fk_stock_imports_invoice
        FOREIGN KEY (import_invoice_id) REFERENCES stock_import_invoices(id)
        ON DELETE SET NULL ON UPDATE CASCADE;

CREATE TABLE IF NOT EXISTS stock_import_purchase_costs (
    id INT(11) NOT NULL AUTO_INCREMENT,
    stock_import_id INT(11) NOT NULL,
    description VARCHAR(255) DEFAULT NULL,
    price DECIMAL(15,2) NOT NULL DEFAULT 0,
    created_at TIMESTAMP NOT NULL DEFAULT current_timestamp(),
    PRIMARY KEY (id),
    KEY idx_sipc_import (stock_import_id),
    CONSTRAINT fk_sipc_import FOREIGN KEY (stock_import_id)
        REFERENCES stock_imports(id) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- 3) product_purchase_prices.price_including_tax = .total / .input-quantity
--    (đơn giá đã bao gồm CPMH; cập nhật cùng latest_price khi user Ghi/Sửa).
ALTER TABLE product_purchase_prices
    ADD COLUMN price_including_tax DECIMAL(15,2) NOT NULL AFTER latest_price;
