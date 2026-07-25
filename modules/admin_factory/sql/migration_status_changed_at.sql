-- =====================================================================
--  Mốc thời gian đổi trạng thái tài khoản (status_changed_at)
--  Dùng để tự xóa tài khoản 'inactive' (Ngưng hoạt động) quá 24h,
--  giống cơ chế xóa tài khoản 'pending' quá 24h chưa kích hoạt.
--  Cleanup chạy lazy mỗi khi có người truy cập/đăng ký mới (module auth).
-- =====================================================================

ALTER TABLE tbl_users
    ADD COLUMN status_changed_at DATETIME NULL AFTER status;

-- Các tài khoản đang 'inactive' sẵn có: gán mốc = hiện tại để không bị xóa ngay.
UPDATE tbl_users
SET status_changed_at = NOW()
WHERE status = 'inactive' AND status_changed_at IS NULL;
