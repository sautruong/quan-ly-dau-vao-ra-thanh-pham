-- Cho phép product_id NULL trong stock_exports để hỗ trợ xuất kho nguyên vật liệu
-- (Kho NVL) trên phiếu xuất kho bán hàng — material_id != NULL, product_id = NULL.
ALTER TABLE `stock_exports`
    MODIFY COLUMN `product_id` INT(11) NULL DEFAULT NULL;
