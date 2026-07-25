-- Mở rộng ENUM type_export để hỗ trợ trang "Xuất kho khác" (other_warehouse_outbound).
-- 'other_export_outbound' dùng cho xuất kho khác (phát hiện thiếu, hao hụt, v.v...)
ALTER TABLE `stock_exports`
    MODIFY COLUMN `type_export`
        ENUM('sales_issue', 'other_issue', 'export_production', 'other_export_outbound')
        NOT NULL DEFAULT 'sales_issue';
