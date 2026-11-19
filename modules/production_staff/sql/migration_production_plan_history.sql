-- ============================================================================
--  LỊCH SỬ "KẾ HOẠCH SẢN XUẤT HẰNG NGÀY" THEO NGÀY (2026-11-19)
--  Nghiệp vụ ở libraries/plan_history.php (prefix phs_*).
--
--  LƯU Ý: bảng dưới đây được code TỰ TẠO qua phs_ensure_table() ngay lần chạy đầu
--  (giống ltp_share_state / plan_auto_export). File này chỉ để cài tay hoặc tra cứu —
--  KHÔNG bắt buộc chạy; deploy máy chủ chỉ cần `git pull`.
--
--  Mỗi NGÀY đúng MỘT dòng: lưu lại = ghi đè bằng trạng thái mới nhất của ngày đó
--  (đổ từ kế hoạch dài ngày, hoặc điều chỉnh trên trang hằng ngày). Nội dung là
--  ẢNH CHỤP đã đóng băng (tên SP, bao bì, quy cách, tồn, ghi chú...) để sau này đổi
--  danh mục thì ngày cũ vẫn hiện đúng như hôm đó.
-- ============================================================================

CREATE TABLE IF NOT EXISTS production_plan_history (
    plan_date      DATE NOT NULL,                 -- ngày của kế hoạch (khoá chính)
    items_json     LONGTEXT NOT NULL,             -- danh sách sản phẩm (JSON, ASCII)
    tasks_json     LONGTEXT NOT NULL,             -- danh sách "việc khác" (JSON, ASCII)
    item_count     INT(11) NOT NULL DEFAULT 0,
    task_count     INT(11) NOT NULL DEFAULT 0,
    first_saved_at DATETIME NOT NULL,             -- lần lưu đầu tiên của ngày
    last_saved_at  DATETIME NOT NULL,             -- lần cập nhật gần nhất
    last_saved_by  INT(11) NOT NULL DEFAULT 0,    -- tbl_users.id (0 = hệ thống / không rõ)
    last_source    VARCHAR(20) NOT NULL DEFAULT '', -- publish | auto | edit | init
    save_count     INT(11) NOT NULL DEFAULT 1,    -- số lần đã cập nhật
    PRIMARY KEY (plan_date)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
