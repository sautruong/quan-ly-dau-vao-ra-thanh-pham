-- Bổ sung cột interpretation để lưu diễn giải user nhập từ UI.
ALTER TABLE stock_imports
  ADD COLUMN IF NOT EXISTS interpretation TEXT DEFAULT NULL AFTER quantity;
