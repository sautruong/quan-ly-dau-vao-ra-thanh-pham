-- Gỡ view 'production_plan' (mọi logic đã dồn qua 'plan_for_staff') + đổi tên
-- 'plan_for_staff' thành "Kế hoạch sản xuất hằng ngày".
-- PHẢI chạy với client charset utf8mb4 để tránh mojibake tiếng Việt:
--   mysql --default-character-set=utf8mb4 -uroot NMSX_VAT < remove_production_plan_view.sql

DELETE tuv FROM tbl_user_views tuv
JOIN tbl_views v ON v.id = tuv.view_id
WHERE v.module = 'production_staff'
  AND v.controller = 'production_staff'
  AND v.action = 'production_plan';

DELETE FROM tbl_views
WHERE module = 'production_staff'
  AND controller = 'production_staff'
  AND action = 'production_plan';

UPDATE tbl_views
SET label = 'Kế hoạch sản xuất hằng ngày'
WHERE module = 'production_staff'
  AND controller = 'production_staff'
  AND action = 'plan_for_staff';
