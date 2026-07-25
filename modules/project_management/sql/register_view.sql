SET NAMES utf8mb4;
-- Đăng ký view "Quản lý dự án" (module project_management) vào danh mục phân quyền.
-- Chỉ đăng ký view dashboard làm mục menu; các action còn lại (detail + AJAX)
-- không nằm trong tbl_views → permission_guard cho qua, kiểm soát theo thành viên dự án.
DELETE FROM tbl_views WHERE module = 'project_management' AND action = 'dashboard';
INSERT INTO tbl_views (module, controller, action, label, group_label, sort, is_active)
VALUES ('project_management', 'project', 'dashboard', 'Quản lý dự án', 'Tiện ích', 121, 1);

-- Gán quyền cho admin (truongngocsau) là tự động (admin thấy mọi view).
-- Muốn gán cho user thường: thêm dòng vào tbl_user_views (user_id, view_id) tương ứng.
