-- Migration: tăng độ chính xác "Đơn giá" NVL (view row_material_receiving) — KHÔNG giới hạn ở
-- 4 chữ số thập phân, cho phép 5/6/7/8 chữ số. Phải chạy file này trước khi dùng tính năng.
--
-- Trước khi sửa:
--   - material_purchase_prices.purchase_price là INT(11) -> không lưu được thập phân,
--     ir_save_material_purchase_price() còn ép (int) round() phía PHP trước khi ghi.
--   - material_purchase_prices.purchase_price_includes_purchase_cost và stock_imports.unit_price
--     là DECIMAL(15,2) -> chỉ giữ 2 chữ số thập phân.
-- Hệ quả: Đơn giá nhập tay dạng "258.3333" bị làm tròn mất chính xác khi lưu DB.
--
-- DECIMAL(20,8): 8 chữ số thập phân (đủ cho mọi nhu cầu thực tế, kể cả 5/6/7 số như yêu cầu),
-- 12 chữ số phần nguyên — dư sức cho đơn giá VND.

ALTER TABLE `material_purchase_prices`
    MODIFY COLUMN `purchase_price` DECIMAL(20,8) NOT NULL;

ALTER TABLE `material_purchase_prices`
    MODIFY COLUMN `purchase_price_includes_purchase_cost` DECIMAL(20,8) NULL DEFAULT NULL;

ALTER TABLE `stock_imports`
    MODIFY COLUMN `unit_price` DECIMAL(20,8) NULL DEFAULT NULL;
