-- =====================================================================
--  Bổ sung cột ghi chú cho Kế hoạch sản xuất dự phòng.
--  Chạy với client charset utf8mb4:
--    mysql --default-character-set=utf8mb4 -uroot NMSX_VAT < migration_long_term_backup_note.sql
-- =====================================================================

ALTER TABLE long_term_production_backup
    ADD COLUMN note VARCHAR(255) NOT NULL DEFAULT '' AFTER quantity;
