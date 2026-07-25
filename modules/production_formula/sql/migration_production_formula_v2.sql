SET NAMES utf8mb4;

/* =====================================================================
 *  Module: Công thức sản xuất (production_formula) — v2
 *  - product_batch_recipe_items.material_id -> cho phép NULL để hỗ trợ
 *    "nguyên liệu tự do" (chỉ tồn tại trong 1 công thức mẻ cụ thể,
 *    KHÔNG có trong material_information — vd: màu thực phẩm pha sẵn,
 *    nước tinh khiết...).
 *  - product_batch_recipe_items.custom_name -> tên hiển thị khi
 *    material_id IS NULL.
 *  FK fk_bri_material (ON DELETE CASCADE) không cần đổi: InnoDB chỉ áp
 *  constraint FK khi giá trị cột con khác NULL.
 * ===================================================================== */
ALTER TABLE `product_batch_recipe_items`
  MODIFY COLUMN `material_id` INT(11) NULL,
  ADD COLUMN `custom_name` VARCHAR(255) NULL AFTER `material_id`;
