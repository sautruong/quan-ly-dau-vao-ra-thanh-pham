-- =====================================================================
--  KẾ HOẠCH SẢN XUẤT DỰ PHÒNG (long_term_production_backup)
--  - Danh sách sản phẩm "dự phòng" (không gắn ngày). Mỗi dòng có ngày tạo.
--  - Kéo thả sắp xếp trong danh sách, hoặc kéo lên 1 ngày để đưa vào kế hoạch
--    (chuyển sang long_term_production_plans, xóa khỏi dự phòng).
--  - Tự động xóa sau N (1 tuần / 2 tuần / 1 tháng) tính từ created_at; cấu hình
--    lưu trong app_settings (key ltp_backup_ttl: '1w' | '2w' | '1m').
--
--  QUAN TRỌNG: chạy với client charset utf8mb4 để nhãn tiếng Việt không mojibake:
--    mysql --default-character-set=utf8mb4 -uroot NMSX_VAT < migration_long_term_backup.sql
-- =====================================================================

CREATE TABLE IF NOT EXISTS long_term_production_backup (
    id          INT AUTO_INCREMENT PRIMARY KEY,
    product_id  INT           NOT NULL,
    quantity    DECIMAL(12,2) NOT NULL DEFAULT 0,
    sort_order  INT           NOT NULL DEFAULT 0,
    created_at  TIMESTAMP     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    KEY idx_ltpb_product (product_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Cấu hình thời gian tự xóa mặc định (1 tháng) nếu chưa có.
INSERT INTO app_settings (setting_key, setting_value)
VALUES ('ltp_backup_ttl', '1m')
ON DUPLICATE KEY UPDATE setting_value = setting_value;
