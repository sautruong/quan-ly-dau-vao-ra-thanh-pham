SET NAMES utf8mb4;

/* =====================================================================
 *  Hình ảnh cho nguyên liệu (1 NVL có nhiều ảnh) — cột "Thao tác".
 * ===================================================================== */
CREATE TABLE IF NOT EXISTS `material_images` (
  `id` INT(11) NOT NULL AUTO_INCREMENT,
  `material_id` INT(11) NOT NULL,
  `file_path` VARCHAR(255) NOT NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_matimg_material` (`material_id`),
  CONSTRAINT `fk_matimg_material` FOREIGN KEY (`material_id`)
    REFERENCES `material_information` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
