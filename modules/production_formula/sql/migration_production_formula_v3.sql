SET NAMES utf8mb4;

/* =====================================================================
 *  Module: Công thức sản xuất (production_formula) — v3
 *  - product_batch_recipe_items.conv_unit / conv_ratio: "Quy đổi đơn vị"
 *    hiển thị riêng cho tab "Công thức mẻ sản xuất" (vd kg -> thùng, tỉ lệ
 *    25 nghĩa là 1 thùng = 25 kg). CHỈ ảnh hưởng cách hiển thị/lưu số lượng
 *    của dòng này trong công thức mẻ, KHÔNG đụng material_information.unit.
 *    conv_unit NULL/rỗng = không quy đổi, hiển thị bình thường theo `unit` gốc.
 * ===================================================================== */
ALTER TABLE `product_batch_recipe_items`
  ADD COLUMN `conv_unit` VARCHAR(50) NULL AFTER `unit`,
  ADD COLUMN `conv_ratio` DECIMAL(18,6) NULL AFTER `conv_unit`;
