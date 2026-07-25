-- =====================================================================
--  ĐẶT HÀNG NVL (order_material) — tab "SẢN XUẤT"
--  KHỐI 1: Phân tích nguyên vật liệu + cảnh báo nên đặt
--  KHỐI 2: Đơn đặt hàng (lưu/đã nhận/xóa/đặt lại + share)
--  Chạy 1 lần trên DB NMSX_VAT (MariaDB/XAMPP).
-- =====================================================================

-- 1) Thiết lập tồn tối thiểu để phát cảnh báo "nên đặt" --------------------------
--    usage_window: '1m' | '3m' | '6m' | '1y' | 'none' (Không xét thời gian)
--    lead_days   : thời gian về dự kiến (ngày) tính từ thời điểm đặt
CREATE TABLE IF NOT EXISTS material_min_stock_settings (
    material_id  INT           NOT NULL PRIMARY KEY,
    min_quantity DECIMAL(15,3) NOT NULL DEFAULT 0,
    lead_days    INT           NOT NULL DEFAULT 0,
    usage_window VARCHAR(10)   NOT NULL DEFAULT 'none',
    updated_at   TIMESTAMP     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- 2) Đơn đặt hàng NVL (mỗi đơn = 1 list của 1 nhà cung cấp) ----------------------
--    order_items JSON: [{ material_id, name, unit, qty }]
--    status: 'new' | 'received' ; hidden = xóa mềm
CREATE TABLE IF NOT EXISTS material_purchase_orders (
    id            INT AUTO_INCREMENT PRIMARY KEY,
    user_id       INT          DEFAULT NULL,
    supplier_id   INT          DEFAULT NULL,
    supplier_name VARCHAR(255) DEFAULT NULL,
    order_items   LONGTEXT     DEFAULT NULL,
    note          TEXT         DEFAULT NULL,
    status        VARCHAR(20)  NOT NULL DEFAULT 'new',
    received      TINYINT(1)   NOT NULL DEFAULT 0,
    received_at   DATETIME     DEFAULT NULL,
    hidden        TINYINT(1)   NOT NULL DEFAULT 0,
    created_at    TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
    KEY idx_supplier (supplier_id),
    KEY idx_created  (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- 3) Bảng cấu hình key-value dùng chung (tiêu đề phiếu + tên người ký) -----------
CREATE TABLE IF NOT EXISTS app_settings (
    setting_key   VARCHAR(100) NOT NULL PRIMARY KEY,
    setting_value TEXT DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- 4) Đảm bảo cột địa chỉ NCC tồn tại (suppliers.address) -------------------------
ALTER TABLE suppliers
    ADD COLUMN IF NOT EXISTS address VARCHAR(255) DEFAULT NULL;

-- 5) Đăng ký view vào menu "SẢN XUẤT" (admin tự thấy; user cần gán tbl_user_views)
INSERT IGNORE INTO tbl_views (module, controller, action, label, group_label, sort)
VALUES ('order_material','order_material','order_material','Đặt hàng NVL','SẢN XUẤT', 45);
