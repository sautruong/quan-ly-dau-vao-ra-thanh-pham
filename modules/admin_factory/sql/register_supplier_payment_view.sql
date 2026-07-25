SET NAMES utf8mb4;
DELETE FROM tbl_views WHERE module = 'admin_factory' AND action = 'supplier_payment_history';
INSERT INTO tbl_views (module, controller, action, label, group_label, sort, is_active)
VALUES ('admin_factory', 'admin', 'supplier_payment_history', 'Lịch sử thanh toán NCC', 'ADMIN - NHÀ MÁY', 134, 1);
