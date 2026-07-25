-- =====================================================================
--  KẾ HOẠCH SẢN XUẤT DÀI NGÀY (long_term_production_plan)
--  - long_term_production_plans : sản phẩm cần SX theo từng ngày (cụm "Sản phẩm").
--  - long_term_production_tasks  : "Việc khác" theo từng ngày.
--  Cửa sổ hiển thị = 10 ngày liên tiếp KHÔNG tính hôm nay; ngày trôi qua tự rơi
--  vào khối "Đã hoàn thành" (không xóa khỏi DB). product_id = PK bảng products.
--  Chạy 1 lần trên DB NMSX_VAT (MariaDB/XAMPP).
--
--  QUAN TRỌNG: chạy với client charset utf8mb4 để nhãn tiếng Việt không bị mojibake:
--    mysql --default-character-set=utf8mb4 -uroot NMSX_VAT < migration_long_term_production_plan.sql
-- =====================================================================

CREATE TABLE IF NOT EXISTS long_term_production_plans (
    id          INT AUTO_INCREMENT PRIMARY KEY,
    plan_date   DATE          NOT NULL,
    product_id  INT           NOT NULL,
    quantity    DECIMAL(12,2) NOT NULL DEFAULT 0,
    sort_order  INT           NOT NULL DEFAULT 0,
    status      ENUM('pending','done') NOT NULL DEFAULT 'pending',
    created_at  TIMESTAMP     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    KEY idx_ltp_date (plan_date),
    KEY idx_ltp_product (product_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS long_term_production_tasks (
    id          INT AUTO_INCREMENT PRIMARY KEY,
    plan_date   DATE      NOT NULL,
    content     TEXT      NOT NULL,
    sort_order  INT       NOT NULL DEFAULT 0,
    status      ENUM('pending','done') NOT NULL DEFAULT 'pending',
    created_at  TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    KEY idx_ltt_date (plan_date)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Đăng ký view vào danh mục phân quyền (admin tick để user thấy trong sidebar).
INSERT INTO tbl_views (module, controller, action, label, group_label, sort) VALUES
('production_staff','production_staff','long_term_production_plan', 'Kế hoạch sản xuất dài ngày', 'SẢN XUẤT', 42)
ON DUPLICATE KEY UPDATE label = VALUES(label), group_label = VALUES(group_label), sort = VALUES(sort);

-- An toàn: ép group_label khớp đúng nhóm 'SẢN XUẤT' của các view SX khác để gộp chung 1 tab
-- (phòng trường hợp lỡ chạy migration với client charset sai làm lệch byte tiếng Việt).
UPDATE tbl_views t
JOIN (SELECT group_label FROM tbl_views WHERE action = 'production_plan' LIMIT 1) s
SET t.group_label = s.group_label
WHERE t.action = 'long_term_production_plan';
