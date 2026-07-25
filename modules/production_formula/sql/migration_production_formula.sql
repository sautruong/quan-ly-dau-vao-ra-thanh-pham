SET NAMES utf8mb4;

/* =====================================================================
 *  Module: Công thức sản xuất (production_formula)
 *  --------------------------------------------------------------------
 *  - material_information.common_material_name : tên thường gọi (ngắn gọn,
 *    phổ thông) đặt ngay sau material_name; UI ưu tiên hiển thị tên này.
 *  - product_materials.sort_order             : thứ tự thành phần trong
 *    công thức (kéo-thả Trello cập nhật cột này).
 *  - product_recipe_notes                     : ghi chú công thức / sản
 *    phẩm (nhập 1 lần, dùng nhiều lần — load lại khi search).
 *  - product_batch_recipes (+ _items)         : "Công thức mẻ sản xuất"
 *    được lưu khi user nhân x2..x200 (KHÔNG ghi đè product_materials).
 * ===================================================================== */

/* 1) Tên thường gọi cho nguyên liệu --------------------------------- */
ALTER TABLE `material_information`
  ADD COLUMN `common_material_name` VARCHAR(255) NULL AFTER `material_name`;

/* 2) Thứ tự thành phần trong công thức gốc -------------------------- */
ALTER TABLE `product_materials`
  ADD COLUMN `sort_order` INT NOT NULL DEFAULT 0 AFTER `quantity_required`;

/* Khởi tạo sort_order theo id hiện tại trong từng product (giữ nguyên
   thứ tự đang có) để kéo-thả có mốc ban đầu ổn định. */
UPDATE `product_materials` pm
JOIN (
  SELECT id, (@r := IF(@p = product_id, @r + 1, 1)) AS rn,
         (@p := product_id) AS p
  FROM `product_materials`, (SELECT @r := 0, @p := 0) v
  ORDER BY product_id, id
) t ON t.id = pm.id
SET pm.sort_order = t.rn;

/* 3) Ghi chú công thức theo sản phẩm (1 dòng / product) ------------- */
CREATE TABLE IF NOT EXISTS `product_recipe_notes` (
  `product_id` INT(11) NOT NULL,
  `note` TEXT NULL,
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`product_id`),
  CONSTRAINT `fk_recipe_note_product` FOREIGN KEY (`product_id`)
    REFERENCES `products` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

/* 4) Công thức mẻ sản xuất (snapshot khi nhân hệ số) ---------------- */
CREATE TABLE IF NOT EXISTS `product_batch_recipes` (
  `id` INT(11) NOT NULL AUTO_INCREMENT,
  `product_id` INT(11) NOT NULL,
  `label` VARCHAR(255) NULL,
  `multiplier` DECIMAL(10,2) NOT NULL DEFAULT 1.00,
  `note` TEXT NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_batch_product` (`product_id`),
  CONSTRAINT `fk_batch_recipe_product` FOREIGN KEY (`product_id`)
    REFERENCES `products` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `product_batch_recipe_items` (
  `id` INT(11) NOT NULL AUTO_INCREMENT,
  `batch_id` INT(11) NOT NULL,
  `material_id` INT(11) NOT NULL,
  `quantity` DECIMAL(12,3) NOT NULL DEFAULT 0.000,
  `unit` VARCHAR(50) NULL,
  `sort_order` INT NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`),
  KEY `idx_bri_batch` (`batch_id`),
  KEY `idx_bri_material` (`material_id`),
  CONSTRAINT `fk_bri_batch` FOREIGN KEY (`batch_id`)
    REFERENCES `product_batch_recipes` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `fk_bri_material` FOREIGN KEY (`material_id`)
    REFERENCES `material_information` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

/* 5) Đăng ký view vào danh mục phân quyền --------------------------- */
DELETE FROM `tbl_views` WHERE `module` = 'production_formula' AND `action` = 'production_formula';
INSERT INTO `tbl_views` (`module`, `controller`, `action`, `label`, `group_label`, `sort`, `is_active`)
VALUES ('production_formula', 'production_formula', 'production_formula', 'Công thức sản xuất', 'SẢN XUẤT', 43, 1);
