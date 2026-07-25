-- Gộp "Nhập thành phẩm mua hàng" (product_buy) vào "Nhập NVL mua" (row_material_receiving).
-- Từ nay row_material_receiving nhận cả dòng NVL (material_id) lẫn dòng thành phẩm
-- (product_id) trong cùng 1 phiếu / 1 NCC.
--
-- 1) note: lưu diễn giải CP khác do user nhập (description vẫn giữ 'CP khác' cố định
--    để không phá parsing hiện có ở ir_get_batch()).
-- 2) vat_includes_other_cost: phục hồi trạng thái checkbox "Gồm CP khác" khi Sửa lại phiếu.

ALTER TABLE stock_import_purchase_costs
    ADD COLUMN note VARCHAR(500) NULL AFTER description;

ALTER TABLE stock_imports
    ADD COLUMN vat_includes_other_cost TINYINT(1) NOT NULL DEFAULT 0 AFTER unit_price;

-- Đổi tên menu con + gỡ menu "Nhập thành phẩm mua hàng" (product_buy đã gộp vào đây).
UPDATE tbl_views
    SET label = 'Nhập mua hàng hóa'
    WHERE module = 'inventory_receiving' AND controller = 'inventory_receiving' AND action = 'row_material_receiving';

DELETE FROM tbl_views
    WHERE module = 'inventory_management' AND controller = 'inventory_management' AND action = 'product_buy';
