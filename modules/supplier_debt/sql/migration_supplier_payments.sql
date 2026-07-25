-- =====================================================================
--  MODULE: Công nợ nhà cung cấp — view "Lịch sử thanh toán".
--  Lưu lịch sử các lần thanh toán cho nhà cung cấp (mua NVL từ
--  row_material_receiving + mua thành phẩm từ product_buy).
--  Tình huống: thanh toán 100% đơn, thanh toán một phần, hoặc trả trước.
--
--    supplier_id : nhà cung cấp (suppliers.id)
--    amount      : số tiền thanh toán (giá trị phiếu chi)
--    note        : lý do chi — "chi tiền cho {supplier_name}"
--    created_at  : ngày giờ ghi (theo datetime-local trên giao diện)
-- =====================================================================
CREATE TABLE IF NOT EXISTS `supplier_payments` (
    `id`          INT(11)        NOT NULL AUTO_INCREMENT,
    `supplier_id` INT(11)        DEFAULT NULL,
    `amount`      DECIMAL(15, 2) NOT NULL DEFAULT 0,
    `note`        VARCHAR(255)   DEFAULT NULL,
    `created_at`  TIMESTAMP      NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `idx_supplier_payments_supplier` (`supplier_id`),
    KEY `idx_supplier_payments_created_at` (`created_at`)
) ENGINE = InnoDB DEFAULT CHARSET = utf8mb4 COLLATE = utf8mb4_unicode_ci;

-- Ảnh / file đính kèm cho 1 phiếu chi (dán Ctrl+V, tải lên, kéo thả).
-- file_url lưu dạng web-relative: 'public/uploads/supplier_payments/<file>'
CREATE TABLE IF NOT EXISTS `supplier_payment_attachments` (
    `id`         INT(11)      NOT NULL AUTO_INCREMENT,
    `payment_id` INT(11)      NOT NULL,
    `file_url`   VARCHAR(255) NOT NULL,
    `created_at` TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `idx_spa_payment` (`payment_id`)
) ENGINE = InnoDB DEFAULT CHARSET = utf8mb4 COLLATE = utf8mb4_unicode_ci;

-- Địa chỉ nhà cung cấp (in trên phiếu chi). Bảng suppliers gốc chưa có cột này.
-- (Chạy độc lập an toàn — model cũng tự thêm cột nếu thiếu.)
-- ALTER TABLE `suppliers` ADD COLUMN `address` VARCHAR(255) DEFAULT NULL;
