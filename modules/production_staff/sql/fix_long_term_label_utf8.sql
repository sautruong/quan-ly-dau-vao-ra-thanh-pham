-- Sửa mojibake nhãn view long_term_production_plan trong tbl_views.
-- PHẢI chạy với client charset utf8mb4:
--   mysql --default-character-set=utf8mb4 -uroot NMSX_VAT < fix_long_term_label_utf8.sql
-- group_label PHẢI khớp đúng 'SẢN XUẤT' của các view SX khác để gộp chung 1 tab (không tạo nhóm mới).
UPDATE tbl_views
SET label       = 'Kế hoạch sản xuất dài ngày',
    group_label = 'SẢN XUẤT'
WHERE module = 'production_staff'
  AND controller = 'production_staff'
  AND action = 'long_term_production_plan';
