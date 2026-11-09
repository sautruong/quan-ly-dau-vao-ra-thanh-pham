-- ============================================================================
--  HÌNH CÔNG THỨC MẺ SẢN XUẤT + DUYỆT THEO KẾ HOẠCH (2026-11-09)
--  Nghiệp vụ ở libraries/formula_image.php (fmi_*). Hai bảng TỰ TẠO qua
--  fmi_ensure_tables() lần chạy đầu — file này chỉ để cài tay / tra cứu.
-- ============================================================================

-- 1 ảnh công thức / (sản phẩm, sản lượng). Ảnh lân cận lấy theo biên ±3% (xử lý ở PHP).
CREATE TABLE IF NOT EXISTS product_formula_images (
    id          INT(11) NOT NULL AUTO_INCREMENT,
    product_id  INT(11) NOT NULL,
    quantity    INT(11) NOT NULL,
    image_file  VARCHAR(255) NOT NULL,       -- đường dẫn web: public/uploads/formula_images/...
    uploaded_by INT(11) NOT NULL DEFAULT 0,
    created_at  DATETIME NOT NULL,
    updated_at  DATETIME NOT NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_prod_qty (product_id, quantity),
    KEY idx_prod (product_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- Duyệt cho NV xem công thức, THEO TỪNG kế hoạch (plan_date đổi mỗi lần công bố → duyệt lại).
CREATE TABLE IF NOT EXISTS plan_formula_approvals (
    plan_date   DATE NOT NULL,
    product_id  INT(11) NOT NULL,
    approved_by INT(11) NOT NULL DEFAULT 0,
    approved_at DATETIME NOT NULL,
    PRIMARY KEY (plan_date, product_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
