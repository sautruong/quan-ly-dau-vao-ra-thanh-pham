-- Migration: bảng purchase_price_changes — lưu biến động giá nhập (đã gồm CPMH).
-- (Giống file cùng tên trong inventory_management — chỉ cần chạy 1 lần, dùng chung.)
--
-- row_material_receiving (inventory_receiving): material_id != NULL, product_id = NULL.
-- new_price = (.cell-fixed.cell-total) / (.cell-input.cell-quantity).
-- old_price = material_purchase_prices.purchase_price_includes_purchase_cost (lần gần nhất,
--             với điều kiện cột này KHÔNG "" và KHÔNG NULL).
-- change_rate = ((new_price - old_price) / old_price) * 100.
-- created_at  = #record-datetime của phiếu.

CREATE TABLE IF NOT EXISTS purchase_price_changes (
    id INT(11) NOT NULL AUTO_INCREMENT,
    product_id INT(11) DEFAULT NULL,
    material_id INT(11) DEFAULT NULL,
    old_price DECIMAL(15,2) NOT NULL DEFAULT 0,
    new_price DECIMAL(15,2) NOT NULL DEFAULT 0,
    change_rate DECIMAL(12,4) NOT NULL DEFAULT 0,
    created_at TIMESTAMP NOT NULL DEFAULT current_timestamp(),
    PRIMARY KEY (id),
    KEY idx_ppc_product (product_id),
    KEY idx_ppc_material (material_id),
    KEY idx_ppc_created (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
