-- =====================================================================
--  CHAT NỘI BỘ — các bảng (kiểu Messenger/Zalo)
--  Lưu ý: libraries/chat.php tự tạo các bảng này (chat_ensure_tables)
--  khi widget chạy lần đầu. File này chỉ để tham khảo / chạy thủ công.
-- =====================================================================

CREATE TABLE IF NOT EXISTS chat_conversations (
    id          INT(11) NOT NULL AUTO_INCREMENT,
    type        ENUM('direct','group') NOT NULL DEFAULT 'direct',
    name        VARCHAR(255) DEFAULT NULL,   -- tên nhóm (group)
    avatar      VARCHAR(255) DEFAULT NULL,   -- ảnh nhóm
    created_by  INT(11) NOT NULL DEFAULT 0,
    created_at  DATETIME NOT NULL,
    updated_at  DATETIME NOT NULL,           -- lần hoạt động gần nhất (để sắp xếp)
    PRIMARY KEY (id),
    KEY idx_updated (updated_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE IF NOT EXISTS chat_participants (
    id                    INT(11) NOT NULL AUTO_INCREMENT,
    conversation_id       INT(11) NOT NULL,
    user_id               INT(11) NOT NULL,
    role                  ENUM('member','admin') NOT NULL DEFAULT 'member',
    last_read_message_id  INT(11) NOT NULL DEFAULT 0,  -- để tính số chưa đọc
    joined_at             DATETIME NOT NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_conv_user (conversation_id, user_id),
    KEY idx_user (user_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE IF NOT EXISTS chat_messages (
    id              INT(11) NOT NULL AUTO_INCREMENT,
    conversation_id INT(11) NOT NULL,
    sender_id       INT(11) NOT NULL,
    body            TEXT DEFAULT NULL,
    type            ENUM('text','image','file','system') NOT NULL DEFAULT 'text',
    created_at      DATETIME NOT NULL,
    PRIMARY KEY (id),
    KEY idx_conv (conversation_id, id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE IF NOT EXISTS chat_attachments (
    id            INT(11) NOT NULL AUTO_INCREMENT,
    message_id    INT(11) NOT NULL,
    file_name     VARCHAR(255) NOT NULL,   -- tên file lưu trên đĩa
    original_name VARCHAR(255) NOT NULL,   -- tên gốc khi tải lên
    mime          VARCHAR(120) DEFAULT NULL,
    size          INT(11) NOT NULL DEFAULT 0,
    is_image      TINYINT(1) NOT NULL DEFAULT 0,
    PRIMARY KEY (id),
    KEY idx_msg (message_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- =====================================================================
--  NÂNG CẤP: cảm xúc, nhắc hẹn + cột bổ sung (thu hồi, mute, xóa, đã xem)
-- =====================================================================

CREATE TABLE IF NOT EXISTS chat_reactions (
    id          INT(11) NOT NULL AUTO_INCREMENT,
    message_id  INT(11) NOT NULL,
    user_id     INT(11) NOT NULL,
    emoji       VARCHAR(24) NOT NULL,
    created_at  DATETIME NOT NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_msg_user (message_id, user_id),   -- 1 user / 1 tin / 1 cảm xúc
    KEY idx_msg (message_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE IF NOT EXISTS chat_reminders (
    id              INT(11) NOT NULL AUTO_INCREMENT,
    user_id         INT(11) NOT NULL,
    message_id      INT(11) NOT NULL,
    conversation_id INT(11) NOT NULL,
    remind_at       DATETIME NOT NULL,   -- thời điểm hiện lại tin
    notified        TINYINT(1) NOT NULL DEFAULT 0,
    created_at      DATETIME NOT NULL,
    PRIMARY KEY (id),
    KEY idx_due (user_id, notified, remind_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- Cột bổ sung (chat_add_column tự thêm nếu thiếu):
ALTER TABLE chat_messages     ADD COLUMN recalled     TINYINT(1) NOT NULL DEFAULT 0;  -- thu hồi
ALTER TABLE chat_messages     ADD COLUMN reply_to_id  INT(11) NOT NULL DEFAULT 0;     -- trả lời 1 tin
ALTER TABLE chat_messages     ADD COLUMN admin_only   TINYINT(1) NOT NULL DEFAULT 0;  -- tin chỉ trưởng nhóm thấy (rời nhóm im lặng)
ALTER TABLE chat_reminders    ADD COLUMN note         TEXT DEFAULT NULL;              -- nội dung nhắc hẹn
ALTER TABLE chat_participants ADD COLUMN muted_until  DATETIME DEFAULT NULL;          -- tắt thông báo
ALTER TABLE chat_participants ADD COLUMN cleared_at   DATETIME DEFAULT NULL;          -- xóa hội thoại (ẩn lịch sử)
ALTER TABLE chat_participants ADD COLUMN last_read_at DATETIME DEFAULT NULL;          -- thời điểm "đã xem"
ALTER TABLE tbl_users         ADD COLUMN last_seen    DATETIME DEFAULT NULL;          -- heartbeat online

-- =====================================================================
--  NÂNG CẤP ĐỢT 2: cấu hình theo user (online / tắt thông báo / nhận tin)
-- =====================================================================
CREATE TABLE IF NOT EXISTS chat_user_settings (
    user_id          INT(11) NOT NULL,
    show_online      TINYINT(1) NOT NULL DEFAULT 1,   -- hiển thị trạng thái hoạt động
    mute_all_until   DATETIME DEFAULT NULL,           -- tắt thông báo toàn hộp chat
    notify_in_system TINYINT(1) NOT NULL DEFAULT 1,   -- chỉ hiển thị trong hệ thống (tự bung)
    notify_toast     TINYINT(1) NOT NULL DEFAULT 1,   -- thông báo nổi (chat toast)
    updated_at       DATETIME NOT NULL,
    PRIMARY KEY (user_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
