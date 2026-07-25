-- Fix sai khoá ngoại của production_materials.material_id:
-- ban đầu trỏ sai về products(id), trong khi material_id thực tế là material_information(id).
-- Khi vật liệu có id > MAX(products.id) (ví dụ bao bì/phụ liệu chỉ có trong material_information),
-- INSERT vào production_materials sẽ vi phạm FK → db_sql_error in HTML → AJAX reload fail.
ALTER TABLE production_materials
  DROP FOREIGN KEY production_materials_ibfk_2;

ALTER TABLE production_materials
  ADD CONSTRAINT fk_production_materials_material
  FOREIGN KEY (material_id) REFERENCES material_information(id);
