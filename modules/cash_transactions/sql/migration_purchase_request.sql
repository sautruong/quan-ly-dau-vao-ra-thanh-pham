-- ============================================================================
--  ĐỀ NGHỊ MUA ĐỒ (module cash_transactions / nhóm THU CHI) — 2026-11-19
--  Nghiệp vụ ở libraries/purchase_request.php (prefix pr_*).
--
--  LƯU Ý: các bảng dưới đây được code TỰ TẠO qua pr_ensure_tables() ngay lần mở đầu tiên
--  (giống plan_history / ltp_share_notify). View 'Đề nghị mua đồ' cũng tự đăng ký vào tbl_views
--  (helper/permission.php::permission_ensure_static_views). File này chỉ để cài tay / tra cứu —
--  KHÔNG bắt buộc chạy; deploy máy chủ chỉ cần `git pull`.
--  KHÔNG có dữ liệu mẫu: danh sách do chủ xây từ "Nhập thu chi" (Thêm DS đề nghị / QL danh sách).
-- ============================================================================

-- Mục chi (vd: Đồ dùng y tế, Đồ dùng văn phòng...) — xóa mềm.
CREATE TABLE IF NOT EXISTS pr_categories (
    id         INT(11) NOT NULL AUTO_INCREMENT,
    name       VARCHAR(120) NOT NULL,
    sort_order INT(11) NOT NULL DEFAULT 0,
    is_active  TINYINT(1) NOT NULL DEFAULT 1,
    created_at DATETIME NOT NULL,
    updated_at DATETIME NOT NULL,
    PRIMARY KEY (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- Hạng mục chi (danh sách đề nghị) — mỗi hạng mục có ảnh minh họa (public/uploads/purchase_request/).
CREATE TABLE IF NOT EXISTS pr_items (
    id             INT(11) NOT NULL AUTO_INCREMENT,
    category_id    INT(11) NOT NULL,
    name           VARCHAR(255) NOT NULL,
    image_file     VARCHAR(255) DEFAULT NULL,
    source_cash_id INT(11) DEFAULT NULL,          -- phiếu "Nhập thu chi" đã sinh ra hạng mục (NULL = tạo tay)
    is_active      TINYINT(1) NOT NULL DEFAULT 1, -- xóa mềm: giữ lịch sử đặt mua gắn với hạng mục
    created_by     INT(11) NOT NULL DEFAULT 0,
    created_at     DATETIME NOT NULL,
    updated_at     DATETIME NOT NULL,
    PRIMARY KEY (id),
    KEY idx_cat (category_id),
    KEY idx_source (source_cash_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- Đợt đặt mua: mỗi lần admin "Xác nhận gửi đặt mua" ghi 1 dòng + danh sách đã gộp (đóng băng).
CREATE TABLE IF NOT EXISTS pr_orders (
    id            INT(11) NOT NULL AUTO_INCREMENT,
    created_by    INT(11) NOT NULL DEFAULT 0,
    created_at    DATETIME NOT NULL,
    request_count INT(11) NOT NULL DEFAULT 0,
    line_count    INT(11) NOT NULL DEFAULT 0,
    snapshot_json LONGTEXT DEFAULT NULL,
    PRIMARY KEY (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- Đơn đề nghị. status: draft | submitted (chờ duyệt) | ordered (đã đặt mua) | rejected | cancelled.
CREATE TABLE IF NOT EXISTS pr_requests (
    id            INT(11) NOT NULL AUTO_INCREMENT,
    code          VARCHAR(30) NOT NULL,           -- DN-YYYYMMDD-NNN
    user_id       INT(11) NOT NULL,
    status        VARCHAR(12) NOT NULL DEFAULT 'draft',
    is_urgent     TINYINT(1) NOT NULL DEFAULT 0,
    note          VARCHAR(500) DEFAULT NULL,
    reject_reason VARCHAR(500) DEFAULT NULL,
    order_id      INT(11) DEFAULT NULL,
    created_at    DATETIME NOT NULL,
    updated_at    DATETIME NOT NULL,
    submitted_at  DATETIME DEFAULT NULL,
    decided_at    DATETIME DEFAULT NULL,
    decided_by    INT(11) NOT NULL DEFAULT 0,
    PRIMARY KEY (id),
    KEY idx_user (user_id, status),
    KEY idx_status (status, submitted_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- Dòng đề nghị: lưu SNAPSHOT tên hạng mục/mục chi; qty = SL admin chốt, qty_requested = SL người đề nghị nhập.
CREATE TABLE IF NOT EXISTS pr_request_lines (
    id            INT(11) NOT NULL AUTO_INCREMENT,
    request_id    INT(11) NOT NULL,
    item_id       INT(11) DEFAULT NULL,           -- NULL = "Đề xuất khác" (ngoài danh sách)
    item_name     VARCHAR(255) NOT NULL,
    category_id   INT(11) DEFAULT NULL,
    category_name VARCHAR(120) NOT NULL DEFAULT '',
    qty           DECIMAL(12,2) NOT NULL DEFAULT 0,
    qty_requested DECIMAL(12,2) NOT NULL DEFAULT 0,
    unit          VARCHAR(40) NOT NULL DEFAULT '',
    note          VARCHAR(255) DEFAULT NULL,
    dropped       TINYINT(1) NOT NULL DEFAULT 0,  -- 1 = admin gỡ dòng này khỏi đợt mua
    sort_order    INT(11) NOT NULL DEFAULT 0,
    PRIMARY KEY (id),
    KEY idx_req (request_id),
    KEY idx_item (item_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- View + quyền (tự đăng ký bởi code; ghi ở đây để tra cứu):
-- INSERT IGNORE INTO tbl_views (module, controller, action, label, group_label, sort, is_active)
-- VALUES ('cash_transactions', 'cash_transactions', 'purchase_request', 'Đề nghị mua đồ', 'THU CHI', 51, 1);
