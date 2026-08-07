-- =====================================================================================
--  QUẢN LÝ ĐƠN HÀNG (module customer_orders) — lưu vết migration.
--
--  KHÔNG BẮT BUỘC CHẠY TAY: mã nguồn tự tạo các cột này khi mở trang
--  (co_ensure_tables() và wri_ensure_source_columns() dùng SHOW COLUMNS + ALTER).
--  File này chỉ để đối chiếu khi cần xem lịch sử thay đổi cấu trúc.
-- =====================================================================================

-- 1) Định danh tài khoản (dùng chung với /admin_factory/manage_user_list).
--    user_kind : '' chưa đánh dấu | 'customer' Là khách hàng | 'factory' Nội bộ sản xuất
--    customer_id : soft FK tới customers.id — CỐ TÌNH không khai FOREIGN KEY cứng vì
--    admin_delete_customer() đang bắt lỗi ràng buộc để báo "đang dùng ở phiếu bán hàng /
--    xuất kho"; thêm FK từ tbl_users sẽ làm câu báo đó sai nguyên nhân.
ALTER TABLE `tbl_users` ADD COLUMN `user_kind`   VARCHAR(20) NOT NULL DEFAULT '';
ALTER TABLE `tbl_users` ADD COLUMN `customer_id` INT(11)     NOT NULL DEFAULT 0;
ALTER TABLE `tbl_users` ADD INDEX `idx_users_customer` (`customer_id`);

-- 2) Nguồn tải lên của hóa đơn — quyết định quyền XÓA.
--    DEFAULT 'factory' khiến TOÀN BỘ hóa đơn đã có tự động thành "nhà máy tải lên", nên khách
--    không xóa được, và khỏi phải backfill. 4 chỗ ghi hóa đơn sẵn có không truyền tham số mới
--    nên hóa đơn nhà máy tải về sau cũng tự động 'factory'.
ALTER TABLE `warehouse_receipt_invoices`
    ADD COLUMN `uploaded_by`   INT(11)     NOT NULL DEFAULT 0,
    ADD COLUMN `upload_source` VARCHAR(20) NOT NULL DEFAULT 'factory';
ALTER TABLE `warehouse_receipt_invoices` ADD INDEX `idx_wri_src` (`upload_source`);

-- 3) Đăng ký view vào danh mục phân quyền.
--    Cột `controller` PHẢI bằng đúng tiền tố tên file customer_ordersController.php.
--    Sai một chữ -> permission_find_view() trả null -> guard FAIL-OPEN (ai cũng vào được).
INSERT IGNORE INTO `tbl_views` (`module`, `controller`, `action`, `label`, `group_label`, `sort`)
VALUES ('customer_orders', 'customer_orders', 'orders', 'Đơn hàng', 'QUẢN LÝ ĐƠN HÀNG', 150);
