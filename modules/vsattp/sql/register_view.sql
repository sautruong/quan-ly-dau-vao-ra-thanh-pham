SET NAMES utf8mb4;

DELETE FROM tbl_views WHERE module = 'vsattp' AND action = 'product_stock';
DELETE FROM tbl_views WHERE module = 'vsattp' AND action = 'material_stock';

INSERT INTO tbl_views (module, controller, action, label, group_label, sort, is_active) VALUES
('vsattp', 'vsattp', 'product_stock',  'Tồn kho sản phẩm',   'BIỂU MẪU QL VSATTP', 147, 1),
('vsattp', 'vsattp', 'material_stock', 'Tồn kho nguyên liệu', 'BIỂU MẪU QL VSATTP', 148, 1);
