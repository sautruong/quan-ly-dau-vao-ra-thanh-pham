-- Đăng ký view "price_change_check" (Check biến động giá) vào nhóm menu
-- 'NHẬP NGUYÊN VẬT LIỆU' (hiển thị sidebar là "Mua hàng" qua permission_group_display_label()).
-- PHẢI chạy với client charset utf8mb4 để tránh mojibake tiếng Việt:
--   mysql --default-character-set=utf8mb4 -uroot NMSX_VAT < migration_price_change_check.sql

INSERT INTO tbl_views (module, controller, action, label, group_label, sort)
SELECT 'inventory_receiving', 'inventory_receiving', 'price_change_check', 'Check biến động giá', 'NHẬP NGUYÊN VẬT LIỆU', 21
WHERE NOT EXISTS (
    SELECT 1 FROM tbl_views
    WHERE module = 'inventory_receiving' AND controller = 'inventory_receiving' AND action = 'price_change_check'
);
