-- =====================================================================
--  "CHỈ XEM" (view-only) cho 14 view nhập liệu
--  - tbl_user_views.can_edit: 1 = ghi/sửa được (mặc định, giữ hành vi cũ),
--    0 = chỉ xem (chặn bước ghi cuối cùng, xem helper/permission.php).
--  - Đăng ký thêm view production_formula (trước đây sót, chưa nằm trong
--    danh mục tbl_views) để có thể gán "chỉ xem" cho nó. KHÔNG tự cấp
--    quyền view này cho user hiện có — admin cần tick cấp lại thủ công.
--  Chạy 1 lần trên DB NMSX_VAT (MariaDB/XAMPP).
-- =====================================================================

ALTER TABLE tbl_user_views
    ADD COLUMN IF NOT EXISTS can_edit TINYINT(1) NOT NULL DEFAULT 1 AFTER view_id;

INSERT IGNORE INTO tbl_views (module, controller, action, label, group_label, sort) VALUES
('production_formula','production_formula','production_formula','Công thức sản xuất mẻ','SẢN XUẤT', 42);
