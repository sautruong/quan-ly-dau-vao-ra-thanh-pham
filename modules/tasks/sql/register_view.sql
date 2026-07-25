SET NAMES utf8mb4;
-- Đăng ký view "Danh sách công việc" (module Việc cần làm) vào danh mục phân quyền.
DELETE FROM tbl_views WHERE module = 'tasks' AND action = 'task_list';
INSERT INTO tbl_views (module, controller, action, label, group_label, sort, is_active)
VALUES ('tasks', 'tasks', 'task_list', 'Danh sách công việc', 'Tiện ích', 123, 1);
