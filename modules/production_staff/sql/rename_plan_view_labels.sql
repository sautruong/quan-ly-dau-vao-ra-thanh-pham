-- Đổi nhãn hiển thị (sidebar + tab) của cụm view Kế hoạch sản xuất (production_staff).
-- PHẢI chạy với client charset utf8mb4 để tránh mojibake tiếng Việt:
--   mysql --default-character-set=utf8mb4 -uroot NMSX_VAT < rename_plan_view_labels.sql

UPDATE tbl_views
SET label = 'Lập kế hoạch sản xuất'
WHERE module = 'production_staff'
  AND controller = 'production_staff'
  AND action = 'production_plan';

UPDATE tbl_views
SET label = 'BC KHSX cho nhân viên'
WHERE module = 'production_staff'
  AND controller = 'production_staff'
  AND action = 'plan_for_staff';
