SET NAMES utf8mb4;

/* =====================================================================
 *  Module: Công thức sản xuất (production_formula) — v4
 *  - product_batch_recipes.output_qty: "Tổng sản phẩm" do user CHỦ ĐỘNG
 *    sửa (ghi đè), khác với tổng tự tính = multiplier * hệ số xem trước.
 *    NULL = chưa ghi đè, dùng lại giá trị tự tính như trước giờ.
 * ===================================================================== */
ALTER TABLE `product_batch_recipes`
  ADD COLUMN `output_qty` DECIMAL(12,2) NULL AFTER `multiplier`;
