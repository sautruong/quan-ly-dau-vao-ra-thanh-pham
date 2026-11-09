-- ============================================================================
--  CHUÔNG CHO "CÔNG BỐ KẾ HOẠCH SẢN XUẤT HẰNG NGÀY" (2026-11-09)
--  Nghiệp vụ ở libraries/ltp_share_notify.php (prefix ltpn_*).
--
--  LƯU Ý: hai bảng dưới đây được code TỰ TẠO qua ltpn_ensure_tables() ngay lần
--  chạy đầu (giống các bảng phân quyền / plan_auto_export). File này chỉ để cài
--  tay hoặc tra cứu — KHÔNG bắt buộc chạy.
-- ============================================================================

-- Sự thật "đã công bố kế hoạch cho ngày nào" — 1 dòng / plan_date.
CREATE TABLE IF NOT EXISTS ltp_share_state (
    plan_date          DATE NOT NULL,
    shared_at          DATETIME DEFAULT NULL,   -- lần công bố đầu tiên
    shared_by          INT(11) NOT NULL DEFAULT 0,
    last_change_at     DATETIME DEFAULT NULL,   -- lần công bố/cập nhật gần nhất
    notified_users_at  DATETIME DEFAULT NULL,   -- mốc bắn chuông gần nhất (throttle)
    share_count        INT(11) NOT NULL DEFAULT 0,
    PRIMARY KEY (plan_date)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- Cấu hình lời nhắc 17:00 — CHỈ MỘT dòng (chưa có màn UI, mặc định đã đúng yêu cầu).
CREATE TABLE IF NOT EXISTS ltp_share_reminder_configs (
    id                 INT(11) NOT NULL AUTO_INCREMENT,
    is_active          TINYINT(1) NOT NULL DEFAULT 1,
    remind_time        TIME NOT NULL DEFAULT '17:00:00',
    skip_sunday        TINYINT(1) NOT NULL DEFAULT 1,
    last_reminded_date DATE DEFAULT NULL,       -- chống nhắc lặp: mỗi ngày đúng 1 lần
    created_at         DATETIME NOT NULL,
    updated_at         DATETIME NOT NULL,
    PRIMARY KEY (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
