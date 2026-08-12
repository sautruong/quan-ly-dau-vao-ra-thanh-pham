-- ============================================================================
--  KHO (module warehouse) — view "Soạn hàng". LƯU VẾT, KHÔNG CẦN CHẠY TAY.
-- ----------------------------------------------------------------------------
--  Cả 2 bảng dưới đây được wh_ensure_tables() tự tạo ở lần đầu mở view, và
--  dòng tbl_views được permission_ensure_static_views() tự chèn. Tệp này chỉ
--  để đọc lại cấu trúc khi cần soi nhanh mà không phải mở model.
-- ============================================================================

CREATE TABLE IF NOT EXISTS wh_picking_slips (
    id             INT(11) NOT NULL AUTO_INCREMENT,
    order_id       INT(11) NOT NULL DEFAULT 0,   -- factory_order_sales_history.id
    customer_id    INT(11) NOT NULL DEFAULT 0,
    customer_name  VARCHAR(255) DEFAULT NULL,
    customer_short VARCHAR(100) DEFAULT NULL,
    receiver       VARCHAR(255) DEFAULT NULL,
    phone          VARCHAR(100) DEFAULT NULL,
    address        TEXT DEFAULT NULL,
    accent         VARCHAR(9)  DEFAULT NULL,     -- màu thứ cấp của chi nhánh
    note           TEXT DEFAULT NULL,
    kien_map       TEXT DEFAULT NULL,            -- JSON {"1":"1T","2":"2B"}
    status         VARCHAR(20) NOT NULL DEFAULT 'new',  -- new | doing | done | cancelled
    synced         TINYINT(1)  NOT NULL DEFAULT 0,      -- admin đã bấm "Cập nhật đơn hàng"
    synced_at      DATETIME DEFAULT NULL,
    sent_by        INT(11) NOT NULL DEFAULT 0,
    sent_at        DATETIME DEFAULT NULL,
    done_by        INT(11) NOT NULL DEFAULT 0,
    done_at        DATETIME DEFAULT NULL,
    PRIMARY KEY (id),
    KEY idx_order (order_id),
    KEY idx_status (status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE IF NOT EXISTS wh_picking_items (
    id             INT(11) NOT NULL AUTO_INCREMENT,
    slip_id        INT(11) NOT NULL,
    source_index   INT(11) DEFAULT NULL,         -- vị trí trong order_items lúc gửi; NULL = kho tự thêm
    item_type      VARCHAR(20) NOT NULL DEFAULT 'product',
    item_id        INT(11) NOT NULL DEFAULT 0,
    product_name   VARCHAR(255) DEFAULT NULL,
    unit           VARCHAR(50)  DEFAULT NULL,
    weight_kg      DECIMAL(14,4) NOT NULL DEFAULT 0,
    system_price   DECIMAL(16,2) NOT NULL DEFAULT 0,
    qty_order      DECIMAL(14,3) NOT NULL DEFAULT 0,   -- số trên đơn đặt
    qty_actual     DECIMAL(14,3) NOT NULL DEFAULT 0,   -- số nhân viên thực bốc
    kien_group     INT(11) DEFAULT NULL,               -- số chung kiện
    picked         TINYINT(1) NOT NULL DEFAULT 0,      -- tích = khóa dòng
    removed        TINYINT(1) NOT NULL DEFAULT 0,      -- xóa mềm, admin xem lại được
    added_by_staff TINYINT(1) NOT NULL DEFAULT 0,
    seq            INT(11) NOT NULL DEFAULT 0,
    PRIMARY KEY (id),
    KEY idx_slip (slip_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

INSERT IGNORE INTO tbl_views (module, controller, action, label, group_label, sort, is_active)
VALUES ('warehouse', 'warehouse', 'picking_task', 'Soạn hàng', 'KHO', 152, 1);
