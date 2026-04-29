-- Bảng tổng hợp giá vốn sản xuất theo từng phiếu (1 row / 1 lần Ghi đầu tư).
-- Cột chính:
--   stock_imports_id  -> id của row stock_imports (type = investment_production) vừa thêm.
--   cost_price        -> tổng vốn sản xuất ( .total-list-value-cost ).
--   goods_value       -> tổng giá trị hàng hóa ( .total-list-value-amount ).
CREATE TABLE IF NOT EXISTS `production_costs_daily` (
    `id` INT(11) NOT NULL AUTO_INCREMENT,
    `stock_imports_id` INT(11) DEFAULT NULL,
    `cost_price`  DECIMAL(12,2) DEFAULT NULL,
    `goods_value` DECIMAL(12,2) DEFAULT NULL,
    `created_at`  DATETIME DEFAULT current_timestamp(),
    PRIMARY KEY (`id`),
    KEY `fk_production_costs_stock_import` (`stock_imports_id`),
    CONSTRAINT `fk_production_costs_stock_import`
        FOREIGN KEY (`stock_imports_id`) REFERENCES `stock_imports` (`id`)
        ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
