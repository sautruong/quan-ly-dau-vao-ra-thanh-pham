-- =====================================================================
--  CHAT — TÀI KHOẢN HỆ THỐNG ("Safe King")
--  Lưu ý: libraries/chat_bot.php tự tạo bảng + cột + tài khoản hệ thống
--  (chatbot_ensure_schema / chatbot_user_id) khi widget chạy lần đầu.
--  File này chỉ để tham khảo / chạy thủ công khi cần.
-- =====================================================================

-- 1) Quyền trò chuyện với tài khoản hệ thống, chi tiết tới TỪNG CHỦ ĐỀ.
--    Không có dòng nào = user đó không thấy / không chat được với hệ thống.
--    Admin (tbl_users.role='admin') luôn đủ mọi chủ đề, không cần dòng ở đây.
--    topic hiện có: 'formula' (công thức sản xuất), 'stock' (tồn kho sản phẩm).
CREATE TABLE IF NOT EXISTS chat_bot_access (
    user_id     INT(11) NOT NULL,
    topic       VARCHAR(32) NOT NULL,
    granted_by  INT(11) NOT NULL DEFAULT 0,   -- admin đã cấp quyền
    granted_at  DATETIME NOT NULL,
    PRIMARY KEY (user_id, topic),
    KEY idx_user (user_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- 2) meta: JSON phụ trợ của 1 tin nhắn — hiện dùng cho cụm nút gợi ý
--    ("Có phải bạn hỏi về…"): {"options":[{"t":"nhãn","k":"formula","p":30}],"used":1}
--    KHÔNG mã hóa (không chứa nội dung người dùng nhập).
--    chat.php::chat_ensure_tables() cũng tự thêm cột này (chat_add_column).
ALTER TABLE chat_messages ADD COLUMN meta TEXT DEFAULT NULL;

-- 3) Tên hiển thị của tài khoản hệ thống (admin sửa trong Cài đặt trò chuyện).
--    Bảng app_settings dùng chung với print_settings / system_settings.
INSERT INTO app_settings (setting_key, setting_value) VALUES ('chatbot.name', 'Safe King')
ON DUPLICATE KEY UPDATE setting_value = setting_value;

-- 4) Tài khoản hệ thống trong tbl_users. status='system' nên:
--    - không đăng nhập được (đăng nhập yêu cầu status='active'),
--    - bị loại khỏi mọi danh sách chọn người dùng (danh bạ, chia sẻ file, nhóm…).
--    Mật khẩu chỉ là chuỗi ngẫu nhiên, không dùng để đăng nhập.
INSERT INTO tbl_users (fullname, dateofbirth, gender, phone, email, username, password, status, role, created_at)
SELECT 'Safe King', '1970-01-01', 'M', '', 'safe_king@system.local', 'safe_king', MD5(RAND()), 'system', 'system', NOW()
WHERE NOT EXISTS (SELECT 1 FROM tbl_users WHERE username = 'safe_king');
