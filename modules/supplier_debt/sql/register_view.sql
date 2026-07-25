SET NAMES utf8mb4;
DELETE FROM tbl_views WHERE module = 'supplier_debt' AND action = 'payment_history';
INSERT INTO tbl_views (module, controller, action, label, group_label, sort, is_active)
VALUES ('supplier_debt', 'supplier_debt', 'payment_history', 'Ghi thanh toán', 'CÔNG NỢ', 55, 1);
