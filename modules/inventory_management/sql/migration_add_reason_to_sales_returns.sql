-- Lưu lý do khách trả hàng (label radio trong .wp-reason).
-- Ví dụ: "Rách bao bì", "Cận date", "Hết date", "Lỗi kĩ thuật",
-- "Chất lượng không đạt", "Lý do khác".
ALTER TABLE sales_returns
  ADD COLUMN IF NOT EXISTS reason VARCHAR(100) NULL AFTER quantity;
