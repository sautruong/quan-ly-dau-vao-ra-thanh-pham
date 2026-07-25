<?php
/**
 * chat — hộp thoại chat nội bộ giữa các user (góc phải dưới, kiểu Messenger).
 * Lưu 4 bảng: chat_conversations, chat_participants, chat_messages, chat_attachments.
 * Dùng chung mọi module; require_once từ nhiều nơi → mọi hàm có guard function_exists.
 *
 * Mô hình:
 *  - conversation type='direct' (2 người, không trùng) hoặc 'group' (>=2, kiểu Zalo).
 *  - participant giữ last_read_message_id để tính số chưa đọc.
 *  - message có thể kèm nhiều attachment (ảnh/file). type='system' cho thông báo nhóm.
 */

// Múi giờ Việt Nam cho mọi mốc thời gian chat (date() mặc định của PHP đang là
// Europe/Berlin → lệch 5-7h). Đặt ở đây để created_at / last_read_at / remind_at đúng.
if (date_default_timezone_get() !== 'Asia/Ho_Chi_Minh') {
    date_default_timezone_set('Asia/Ho_Chi_Minh');
}

// Mã hóa dữ liệu cá nhân hóa: nội dung tin nhắn (chat_messages.body).
require_once __DIR__ . '/crypto.php';
// Cấu hình dọn tin nhắn (system_settings.chat_retention_mode) — Cài đặt hệ thống ở sidebar.
require_once __DIR__ . '/system_settings.php';

if (!function_exists('chat_ensure_tables')) {

    /** Tạo các bảng chat nếu chưa có (1 lần / request). */
    function chat_ensure_tables()
    {
        static $done = false;
        if ($done) return;
        $done = true;

        db_query("CREATE TABLE IF NOT EXISTS chat_conversations (
            id          INT(11) NOT NULL AUTO_INCREMENT,
            type        ENUM('direct','group') NOT NULL DEFAULT 'direct',
            name        VARCHAR(255) DEFAULT NULL,
            avatar      VARCHAR(255) DEFAULT NULL,
            created_by  INT(11) NOT NULL DEFAULT 0,
            created_at  DATETIME NOT NULL,
            updated_at  DATETIME NOT NULL,
            PRIMARY KEY (id),
            KEY idx_updated (updated_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci");

        db_query("CREATE TABLE IF NOT EXISTS chat_participants (
            id                    INT(11) NOT NULL AUTO_INCREMENT,
            conversation_id       INT(11) NOT NULL,
            user_id               INT(11) NOT NULL,
            role                  ENUM('member','admin') NOT NULL DEFAULT 'member',
            last_read_message_id  INT(11) NOT NULL DEFAULT 0,
            joined_at             DATETIME NOT NULL,
            PRIMARY KEY (id),
            UNIQUE KEY uq_conv_user (conversation_id, user_id),
            KEY idx_user (user_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci");

        db_query("CREATE TABLE IF NOT EXISTS chat_messages (
            id              INT(11) NOT NULL AUTO_INCREMENT,
            conversation_id INT(11) NOT NULL,
            sender_id       INT(11) NOT NULL,
            body            TEXT DEFAULT NULL,
            type            ENUM('text','image','file','system') NOT NULL DEFAULT 'text',
            created_at      DATETIME NOT NULL,
            PRIMARY KEY (id),
            KEY idx_conv (conversation_id, id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci");

        db_query("CREATE TABLE IF NOT EXISTS chat_attachments (
            id            INT(11) NOT NULL AUTO_INCREMENT,
            message_id    INT(11) NOT NULL,
            file_name     VARCHAR(255) NOT NULL,
            original_name VARCHAR(255) NOT NULL,
            mime          VARCHAR(120) DEFAULT NULL,
            size          INT(11) NOT NULL DEFAULT 0,
            is_image      TINYINT(1) NOT NULL DEFAULT 0,
            PRIMARY KEY (id),
            KEY idx_msg (message_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci");

        // Thả cảm xúc cho tin nhắn (1 user / 1 tin / 1 emoji).
        db_query("CREATE TABLE IF NOT EXISTS chat_reactions (
            id          INT(11) NOT NULL AUTO_INCREMENT,
            message_id  INT(11) NOT NULL,
            user_id     INT(11) NOT NULL,
            emoji       VARCHAR(24) NOT NULL,
            created_at  DATETIME NOT NULL,
            PRIMARY KEY (id),
            UNIQUE KEY uq_msg_user (message_id, user_id),
            KEY idx_msg (message_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci");

        // Nhắc hẹn: hiện lại 1 tin nhắn vào thời điểm chọn.
        db_query("CREATE TABLE IF NOT EXISTS chat_reminders (
            id              INT(11) NOT NULL AUTO_INCREMENT,
            user_id         INT(11) NOT NULL,
            message_id      INT(11) NOT NULL,
            conversation_id INT(11) NOT NULL,
            remind_at       DATETIME NOT NULL,
            notified        TINYINT(1) NOT NULL DEFAULT 0,
            created_at      DATETIME NOT NULL,
            PRIMARY KEY (id),
            KEY idx_due (user_id, notified, remind_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci");

        // Xóa tin nhắn phía người NHẬN (chỉ ẩn với người xóa, người gửi vẫn còn nguyên).
        // Khác "thu hồi" (chat_messages.recalled, chỉ người gửi, ẩn với mọi người).
        // silent=1 (tự xóa theo giờ hẹn) -> ẩn HOÀN TOÀN, không để lại "Tin nhắn đã xóa";
        // silent=0 (bấm nút xóa thủ công) -> vẫn để lại bong bóng "Tin nhắn đã xóa".
        db_query("CREATE TABLE IF NOT EXISTS chat_message_deletes (
            message_id  INT(11) NOT NULL,
            user_id     INT(11) NOT NULL,
            deleted_at  DATETIME NOT NULL,
            silent      TINYINT(1) NOT NULL DEFAULT 0,
            PRIMARY KEY (message_id, user_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci");
        chat_add_column('chat_message_deletes', 'silent', "TINYINT(1) NOT NULL DEFAULT 0");

        // Biệt danh liên hệ (riêng tư từng user): owner_id đặt tên gợi nhớ cho contact_id.
        // Chỉ owner thấy, không ảnh hưởng người khác. Xem [[chat-widget]].
        db_query("CREATE TABLE IF NOT EXISTS chat_contact_aliases (
            owner_id    INT(11) NOT NULL,
            contact_id  INT(11) NOT NULL,
            alias       VARCHAR(150) NOT NULL,
            updated_at  DATETIME NOT NULL,
            PRIMARY KEY (owner_id, contact_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci");

        // Cấu hình theo từng user (1 dòng / user): hiển thị online, tắt thông báo
        // toàn hộp chat, chế độ nhận tin. Xem [[chat-widget]] phần nâng cấp.
        db_query("CREATE TABLE IF NOT EXISTS chat_user_settings (
            user_id          INT(11) NOT NULL,
            show_online      TINYINT(1) NOT NULL DEFAULT 1,
            mute_all_until   DATETIME DEFAULT NULL,
            notify_in_system TINYINT(1) NOT NULL DEFAULT 1,
            notify_toast     TINYINT(1) NOT NULL DEFAULT 1,
            updated_at       DATETIME NOT NULL,
            PRIMARY KEY (user_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci");

        // Cột bổ sung (idempotent) cho các tính năng nâng cấp.
        chat_add_column('chat_messages',     'recalled',     "TINYINT(1) NOT NULL DEFAULT 0");
        chat_add_column('chat_messages',     'reply_to_id',  "INT(11) NOT NULL DEFAULT 0");
        // forwarded=1: tin được "chia sẻ lại" từ 1 tin khác (hiện nhãn "Đã chia sẻ").
        chat_add_column('chat_messages',     'forwarded',    "TINYINT(1) NOT NULL DEFAULT 0");
        // admin_only=1: tin hệ thống chỉ trưởng nhóm (admin) thấy — dùng cho "rời nhóm trong im lặng".
        chat_add_column('chat_messages',     'admin_only',   "TINYINT(1) NOT NULL DEFAULT 0");
        chat_add_column('chat_reminders',     'note',         "TEXT DEFAULT NULL");
        chat_add_column('chat_participants',  'muted_until',  "DATETIME DEFAULT NULL");
        chat_add_column('chat_participants',  'cleared_at',   "DATETIME DEFAULT NULL");
        chat_add_column('chat_participants',  'last_read_at', "DATETIME DEFAULT NULL");
        // Mốc đã xem các "thả cảm xúc" của người khác trên tin của tôi (cho badge ở danh sách).
        chat_add_column('chat_participants',  'reactions_seen_at', "DATETIME DEFAULT NULL");
        // Tự xóa tin nhắn cá nhân hóa theo hội thoại (chỉ ẩn phía user đặt, NULL/0 = tắt).
        // Đơn vị giây: 3600=1h, 14400=4h, 86400=1 ngày, 604800=1 tuần, 2592000=1 tháng.
        chat_add_column('chat_participants',  'auto_delete_seconds', "INT(11) DEFAULT NULL");
        // Mốc hoạt động gần nhất để nhận diện user đang online (heartbeat qua poll).
        chat_add_column('tbl_users',          'last_seen',    "DATETIME DEFAULT NULL");
        // Throttle cho quét tự xóa (mỗi user tối đa 1 lần/phút, xem chat_apply_auto_delete_for_user).
        chat_add_column('chat_user_settings', 'auto_delete_last_run_at', "DATETIME DEFAULT NULL");

        // Mã hóa nốt nội dung tin cũ còn ở dạng rõ — CHẠY 1 LẦN. Gate bằng cột marker
        // 'body_enc_migrated': khi cột này chưa tồn tại nghĩa là chưa migrate.
        $mk = db_fetch_row(
            "SELECT COUNT(*) AS n FROM information_schema.columns
             WHERE table_schema = DATABASE() AND table_name = 'chat_messages' AND column_name = 'body_enc_migrated'"
        );
        if (!$mk || (int) $mk['n'] === 0) {
            $rows = db_fetch_array(
                "SELECT id, body FROM chat_messages
                 WHERE body IS NOT NULL AND body <> '' AND body NOT LIKE 'enc:v1:%'"
            ) ?: [];
            foreach ($rows as $r) {
                db_update('chat_messages', ['body' => crypto_encrypt($r['body'])], 'id = ' . (int) $r['id']);
            }
            chat_add_column('chat_messages', 'body_enc_migrated', "TINYINT(1) NOT NULL DEFAULT 1");
        }
    }

    /** Thêm 1 cột nếu chưa tồn tại (an toàn khi gọi lặp). */
    function chat_add_column($table, $column, $definition)
    {
        $t = escape_string($table);
        $c = escape_string($column);
        $row = db_fetch_row(
            "SELECT COUNT(*) AS n FROM information_schema.columns
             WHERE table_schema = DATABASE() AND table_name = '{$t}' AND column_name = '{$c}'"
        );
        if (!$row || (int) $row['n'] === 0) {
            db_query("ALTER TABLE `{$table}` ADD COLUMN `{$column}` {$definition}");
        }
    }

    /* ---------------------------------------------------------------
     *  Tiện ích người dùng
     * --------------------------------------------------------------- */

    /** URL avatar của 1 user (rỗng nếu chưa có ảnh → FE hiển thị chữ cái đầu). */
    function chat_avatar_url($avatar)
    {
        $avatar = trim((string) $avatar);
        return $avatar === '' ? '' : 'public/images/avatar/' . $avatar;
    }

    /** Bản đồ biệt danh của 1 owner: [contact_id => alias]. */
    function chat_contact_aliases_map($owner_id)
    {
        chat_ensure_tables();
        $oid = (int) $owner_id;
        $rows = db_fetch_array("SELECT contact_id, alias FROM chat_contact_aliases WHERE owner_id = {$oid}");
        $map = [];
        foreach ($rows as $r) $map[(int) $r['contact_id']] = $r['alias'];
        return $map;
    }

    /** Đặt / xóa biệt danh cho 1 liên hệ (alias rỗng = gỡ, trả về tên thật). */
    function chat_set_contact_alias($owner_id, $contact_id, $alias)
    {
        chat_ensure_tables();
        $oid = (int) $owner_id;
        $cid = (int) $contact_id;
        $alias = trim((string) $alias);
        if ($oid <= 0 || $cid <= 0 || $oid === $cid) return false;
        if (mb_strlen($alias, 'UTF-8') > 150) $alias = mb_substr($alias, 0, 150, 'UTF-8');
        if ($alias === '') {
            db_delete('chat_contact_aliases', "owner_id = {$oid} AND contact_id = {$cid}");
            return true;
        }
        $exists = db_fetch_row("SELECT 1 AS n FROM chat_contact_aliases
                                WHERE owner_id = {$oid} AND contact_id = {$cid} LIMIT 1");
        if ($exists) {
            db_update('chat_contact_aliases', ['alias' => $alias, 'updated_at' => date('Y-m-d H:i:s')],
                      "owner_id = {$oid} AND contact_id = {$cid}");
        } else {
            db_insert('chat_contact_aliases', [
                'owner_id' => $oid, 'contact_id' => $cid, 'alias' => $alias, 'updated_at' => date('Y-m-d H:i:s'),
            ]);
        }
        return true;
    }

    /** Danh bạ: tất cả user khác (đang hoạt động), kèm avatar + fullname + biệt danh. */
    function chat_contacts($me_id)
    {
        $me = (int) $me_id;
        $rows = db_fetch_array(
            "SELECT id, fullname, username, avatar
             FROM tbl_users
             WHERE id <> {$me} AND (status IS NULL OR status NOT IN ('blocked', 'left'))
             ORDER BY fullname, username"
        );
        $online  = chat_online_ids();
        $aliases = chat_contact_aliases_map($me);
        $out = [];
        foreach ($rows as $r) {
            $id = (int) $r['id'];
            $out[] = [
                'id'       => $id,
                'fullname' => $r['fullname'] !== '' ? $r['fullname'] : $r['username'],
                'username' => $r['username'],
                'avatar'   => chat_avatar_url($r['avatar']),
                'online'   => isset($online[$id]),
                'alias'    => $aliases[$id] ?? '',   // biệt danh riêng tư (rỗng = chưa đặt)
            ];
        }
        return $out;
    }

    /** Thông tin gọn của 1 user. Tài khoản đã rời/đã xóa -> "Tài khoản không tồn tại". */
    function chat_user_brief($user_id)
    {
        $uid = (int) $user_id;
        $r = db_fetch_row("SELECT id, fullname, username, avatar, status FROM tbl_users WHERE id = {$uid} LIMIT 1");
        if (!$r || ($r['status'] ?? '') === 'left') {
            return ['id' => $uid, 'fullname' => 'Tài khoản không tồn tại', 'username' => '', 'avatar' => '', 'gone' => true];
        }
        return [
            'id'       => (int) $r['id'],
            'fullname' => $r['fullname'] !== '' ? $r['fullname'] : $r['username'],
            'username' => $r['username'],
            'avatar'   => chat_avatar_url($r['avatar']),
        ];
    }

    /* ---------------------------------------------------------------
     *  Online / Presence (heartbeat qua poll)
     * --------------------------------------------------------------- */

    /** Số giây coi là "đang online" kể từ hoạt động gần nhất. */
    if (!defined('CHAT_ONLINE_WINDOW')) define('CHAT_ONLINE_WINDOW', 90);

    /** Cập nhật mốc hoạt động của user (gọi mỗi nhịp poll). */
    function chat_touch_presence($user_id)
    {
        $uid = (int) $user_id;
        if ($uid <= 0) return;
        db_update('tbl_users', ['last_seen' => date('Y-m-d H:i:s')], "id = {$uid}");
        chat_apply_retention_if_due();
        chat_apply_auto_delete_for_user($uid);
    }

    /**
     * Dọn tin nhắn cũ theo cấu hình "Cài đặt hệ thống" (chat_retention_mode).
     * Chạy ngầm khi có truy cập (gọi từ chat_touch_presence, mỗi lần heartbeat/poll),
     * có throttle 6 giờ/lần để không DELETE nặng mỗi request.
     */
    function chat_apply_retention_if_due()
    {
        $mode = system_settings_get()['chat_retention_mode'] ?? 'none';
        if ($mode === 'none') return;

        system_settings_ensure_table();
        $last = db_fetch_row("SELECT setting_value FROM app_settings WHERE setting_key = 'system.chat_retention_last_run' LIMIT 1");
        $lastTs = $last ? strtotime((string) $last['setting_value']) : 0;
        if ($lastTs && (time() - $lastTs) < 6 * 3600) return;

        $now = date('Y-m-d H:i:s');
        $exists = db_num_rows("SELECT 1 FROM app_settings WHERE setting_key = 'system.chat_retention_last_run'") > 0;
        if ($exists) {
            db_update('app_settings', ['setting_value' => $now], "setting_key = 'system.chat_retention_last_run'");
        } else {
            db_insert('app_settings', ['setting_key' => 'system.chat_retention_last_run', 'setting_value' => $now]);
        }

        if ($mode === 'count_500') {
            db_query("DELETE FROM chat_messages WHERE id IN (
                SELECT id FROM (
                    SELECT id, ROW_NUMBER() OVER (PARTITION BY conversation_id ORDER BY id DESC) AS rn
                    FROM chat_messages
                ) t WHERE rn > 500
            )");
            return;
        }

        if (strpos($mode, 'days_') === 0) {
            $days = (int) substr($mode, 5);
            if ($days > 0) {
                db_query("DELETE FROM chat_messages WHERE created_at < DATE_SUB(NOW(), INTERVAL {$days} DAY)");
            }
        }
    }

    /** Số giây tương ứng 1 mã chế độ tự xóa ('1h'|'4h'|'1d'|'1w'|'1m'), null nếu 'off'/không hợp lệ. */
    function chat_auto_delete_seconds_for_mode($mode)
    {
        switch ((string) $mode) {
            case '1h': return 3600;
            case '4h': return 14400;
            case '1d': return 86400;
            case '1w': return 604800;
            case '1m': return 2592000;
            default:   return null;
        }
    }

    /** Đặt/tắt tự xóa tin nhắn cho 1 hội thoại — CHỈ ẩn phía user đặt (khác thu hồi/xóa ẩn cả 2 phía). */
    function chat_set_auto_delete($conversation_id, $user_id, $mode)
    {
        chat_ensure_tables();
        $cid = (int) $conversation_id;
        $uid = (int) $user_id;
        if (!chat_is_participant($cid, $uid)) return false;
        db_update('chat_participants',
            ['auto_delete_seconds' => chat_auto_delete_seconds_for_mode($mode)],
            "conversation_id = {$cid} AND user_id = {$uid}");
        return true;
    }

    /**
     * Quét các hội thoại mà $user_id đã bật "tự xóa tin nhắn" — tin nào quá tuổi thì
     * ghi vào chat_message_deletes (chỉ ẩn phía user này, người khác không bị ảnh hưởng).
     * Throttle tối đa 1 lần/phút/user (gọi mỗi nhịp poll 6s nên cần chặn bớt).
     */
    function chat_apply_auto_delete_for_user($user_id)
    {
        chat_ensure_tables();
        $uid = (int) $user_id;
        if ($uid <= 0) return;

        chat_get_user_settings($uid); // đảm bảo có dòng chat_user_settings
        $row  = db_fetch_row("SELECT auto_delete_last_run_at FROM chat_user_settings WHERE user_id = {$uid} LIMIT 1");
        $last = ($row && !empty($row['auto_delete_last_run_at'])) ? strtotime($row['auto_delete_last_run_at']) : 0;
        if ($last && (time() - $last) < 60) return;
        db_update('chat_user_settings', ['auto_delete_last_run_at' => date('Y-m-d H:i:s')], "user_id = {$uid}");

        $convs = db_fetch_array(
            "SELECT conversation_id, auto_delete_seconds FROM chat_participants
             WHERE user_id = {$uid} AND auto_delete_seconds IS NOT NULL AND auto_delete_seconds > 0"
        ) ?: [];
        if (!$convs) return;

        $now = date('Y-m-d H:i:s');
        foreach ($convs as $c) {
            $cid    = (int) $c['conversation_id'];
            $sec    = (int) $c['auto_delete_seconds'];
            $cutoff = date('Y-m-d H:i:s', time() - $sec);
            $due = db_fetch_array(
                "SELECT m.id FROM chat_messages m
                 LEFT JOIN chat_message_deletes d ON d.message_id = m.id AND d.user_id = {$uid}
                 WHERE m.conversation_id = {$cid} AND m.type <> 'system'
                   AND m.created_at < '" . escape_string($cutoff) . "' AND d.message_id IS NULL"
            ) ?: [];
            foreach ($due as $d) {
                // silent=1: tự xóa theo giờ hẹn -> ẩn âm thầm, KHÔNG để lại "Tin nhắn đã xóa"
                // (khác nút xóa thủ công của người nhận, vẫn giữ bong bóng báo đã xóa).
                db_insert('chat_message_deletes', ['message_id' => (int) $d['id'], 'user_id' => $uid, 'deleted_at' => $now, 'silent' => 1]);
            }
        }
    }

    /** Tập id user đang online (last_seen trong cửa sổ) VÀ cho phép hiển thị online. */
    function chat_online_ids()
    {
        chat_ensure_tables();
        $cutoff = date('Y-m-d H:i:s', time() - CHAT_ONLINE_WINDOW);
        $rows = db_fetch_array(
            "SELECT u.id
             FROM tbl_users u
             LEFT JOIN chat_user_settings s ON s.user_id = u.id
             WHERE u.last_seen IS NOT NULL AND u.last_seen >= '" . escape_string($cutoff) . "'
               AND (s.show_online IS NULL OR s.show_online = 1)"
        );
        $ids = [];
        foreach ($rows as $r) $ids[(int) $r['id']] = true;
        return $ids;
    }

    /* ---------------------------------------------------------------
     *  Cấu hình theo user (settings)
     * --------------------------------------------------------------- */

    /** Lấy cấu hình của 1 user (tạo mặc định nếu chưa có). */
    function chat_get_user_settings($user_id)
    {
        chat_ensure_tables();
        $uid = (int) $user_id;
        $r = db_fetch_row("SELECT * FROM chat_user_settings WHERE user_id = {$uid} LIMIT 1");
        if (!$r) {
            db_insert('chat_user_settings', [
                'user_id' => $uid, 'show_online' => 1, 'mute_all_until' => null,
                'notify_in_system' => 1, 'notify_toast' => 1, 'updated_at' => date('Y-m-d H:i:s'),
            ]);
            $r = db_fetch_row("SELECT * FROM chat_user_settings WHERE user_id = {$uid} LIMIT 1");
        }
        $muteUntil = !empty($r['mute_all_until']) ? $r['mute_all_until'] : '';
        if ($muteUntil !== '' && $muteUntil <= date('Y-m-d H:i:s')) $muteUntil = ''; // hết hạn
        return [
            'show_online'      => (int) $r['show_online'] === 1,
            'mute_all_until'   => $muteUntil,
            'notify_in_system' => (int) $r['notify_in_system'] === 1,
            'notify_toast'     => (int) $r['notify_toast'] === 1,
        ];
    }

    /** Lưu cấu hình user (chỉ các khóa được truyền). $mute_mode: ''|'1h'|'4h'|'forever'|'off'. */
    function chat_save_user_settings($user_id, array $data)
    {
        chat_ensure_tables();
        $uid = (int) $user_id;
        if ($uid <= 0) return false;
        chat_get_user_settings($uid); // đảm bảo có dòng

        $set = ['updated_at' => date('Y-m-d H:i:s')];
        if (array_key_exists('show_online', $data))      $set['show_online']      = $data['show_online'] ? 1 : 0;
        if (array_key_exists('notify_in_system', $data)) $set['notify_in_system'] = $data['notify_in_system'] ? 1 : 0;
        if (array_key_exists('notify_toast', $data))     $set['notify_toast']     = $data['notify_toast'] ? 1 : 0;
        if (array_key_exists('mute_mode', $data)) {
            $m = (string) $data['mute_mode'];
            if ($m === 'off')          $set['mute_all_until'] = null;
            elseif ($m === '1h')       $set['mute_all_until'] = date('Y-m-d H:i:s', time() + 3600);
            elseif ($m === '4h')       $set['mute_all_until'] = date('Y-m-d H:i:s', time() + 4 * 3600);
            elseif ($m === 'forever')  $set['mute_all_until'] = '2099-12-31 23:59:59';
        }
        db_update('chat_user_settings', $set, "user_id = {$uid}");
        return true;
    }

    /** Trạng thái thông báo (cho JS gate auto-open/toast). Tính mute_all theo giờ hiện tại. */
    function chat_notif_state($user_id)
    {
        $s = chat_get_user_settings($user_id);
        return [
            'mute_all'  => $s['mute_all_until'] !== '',
            'in_system' => $s['notify_in_system'],
            'toast'     => $s['notify_toast'],
        ];
    }

    /* ---------------------------------------------------------------
     *  Hội thoại
     * --------------------------------------------------------------- */

    /** Kiểm tra user có thuộc 1 hội thoại không. */
    function chat_is_participant($conversation_id, $user_id)
    {
        $cid = (int) $conversation_id;
        $uid = (int) $user_id;
        return db_num_rows(
            "SELECT 1 FROM chat_participants WHERE conversation_id = {$cid} AND user_id = {$uid} LIMIT 1"
        ) > 0;
    }

    /** User có phải trưởng nhóm (admin) của hội thoại không. */
    function chat_is_admin($conversation_id, $user_id)
    {
        $cid = (int) $conversation_id;
        $uid = (int) $user_id;
        return db_num_rows(
            "SELECT 1 FROM chat_participants
             WHERE conversation_id = {$cid} AND user_id = {$uid} AND role = 'admin' LIMIT 1"
        ) > 0;
    }

    /** Lấy (hoặc tạo mới) hội thoại 1-1 giữa 2 user. Trả conversation_id. */
    function chat_get_or_create_direct($me_id, $other_id)
    {
        chat_ensure_tables();
        $a = (int) $me_id;
        $b = (int) $other_id;
        if ($a <= 0 || $b <= 0 || $a === $b) return 0;

        $row = db_fetch_row(
            "SELECT c.id
             FROM chat_conversations c
             JOIN chat_participants p1 ON p1.conversation_id = c.id AND p1.user_id = {$a}
             JOIN chat_participants p2 ON p2.conversation_id = c.id AND p2.user_id = {$b}
             WHERE c.type = 'direct'
             LIMIT 1"
        );
        if ($row && !empty($row['id'])) return (int) $row['id'];

        $now = date('Y-m-d H:i:s');
        $cid = (int) db_insert('chat_conversations', [
            'type'       => 'direct',
            'name'       => null,
            'avatar'     => null,
            'created_by' => $a,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        foreach ([$a, $b] as $uid) {
            db_insert('chat_participants', [
                'conversation_id'      => $cid,
                'user_id'              => $uid,
                'role'                 => 'member',
                'last_read_message_id' => 0,
                'joined_at'            => $now,
            ]);
        }
        return $cid;
    }

    /** Tạo nhóm chat (kiểu Zalo). $member_ids gồm cả người tạo hoặc không đều được. */
    function chat_create_group($creator_id, $name, array $member_ids)
    {
        chat_ensure_tables();
        $creator = (int) $creator_id;
        $name    = trim((string) $name);
        if ($creator <= 0 || $name === '') return 0;

        // Gộp người tạo + thành viên, loại trùng, bỏ id không hợp lệ.
        $ids = array_map('intval', $member_ids);
        $ids[] = $creator;
        $ids = array_values(array_unique(array_filter($ids, static fn($v) => $v > 0)));
        if (count($ids) < 2) return 0; // nhóm cần >= 2 người

        $now = date('Y-m-d H:i:s');
        $cid = (int) db_insert('chat_conversations', [
            'type'       => 'group',
            'name'       => $name,
            'avatar'     => null,
            'created_by' => $creator,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        foreach ($ids as $uid) {
            db_insert('chat_participants', [
                'conversation_id'      => $cid,
                'user_id'              => $uid,
                'role'                 => $uid === $creator ? 'admin' : 'member',
                'last_read_message_id' => 0,
                'joined_at'            => $now,
            ]);
        }
        // Tin nhắn hệ thống "đã tạo nhóm".
        $who = chat_user_brief($creator);
        chat_insert_message($cid, $creator, $who['fullname'] . ' đã tạo nhóm "' . $name . '"', 'system');
        return $cid;
    }

    /** Mảng id thành viên của 1 hội thoại. */
    function chat_participant_ids($conversation_id)
    {
        $cid = (int) $conversation_id;
        $rows = db_fetch_array("SELECT user_id FROM chat_participants WHERE conversation_id = {$cid}");
        return array_map(static fn($r) => (int) $r['user_id'], $rows);
    }

    /** Trạng thái tắt thông báo (muted) của tôi trong hội thoại: trả mốc hết hạn hoặc ''. */
    function chat_my_mute($conversation_id, $me_id)
    {
        $cid = (int) $conversation_id;
        $me  = (int) $me_id;
        $p = db_fetch_row("SELECT muted_until FROM chat_participants
                           WHERE conversation_id = {$cid} AND user_id = {$me} LIMIT 1");
        if (!$p || empty($p['muted_until'])) return '';
        // Hết hạn rồi thì coi như không mute.
        return ($p['muted_until'] > date('Y-m-d H:i:s')) ? $p['muted_until'] : '';
    }

    /** Meta hiển thị của hội thoại theo góc nhìn của $me (tên + avatar + loại + quyền). */
    function chat_conversation_meta($conversation_id, $me_id)
    {
        $cid = (int) $conversation_id;
        $me  = (int) $me_id;
        $c = db_fetch_row("SELECT * FROM chat_conversations WHERE id = {$cid} LIMIT 1");
        if (!$c) return null;

        $myrow = db_fetch_row("SELECT role, auto_delete_seconds FROM chat_participants
                               WHERE conversation_id = {$cid} AND user_id = {$me} LIMIT 1");
        $my_role     = $myrow ? $myrow['role'] : 'member';
        $muted       = chat_my_mute($cid, $me);
        $autoDelete  = ($myrow && $myrow['auto_delete_seconds'] !== null) ? (int) $myrow['auto_delete_seconds'] : 0;

        if ($c['type'] === 'group') {
            return [
                'id'           => $cid,
                'type'         => 'group',
                'name'         => $c['name'] !== '' ? $c['name'] : 'Nhóm',
                'avatar'       => chat_avatar_url($c['avatar']),
                'created_by'   => (int) $c['created_by'],
                'my_role'      => $my_role,
                'is_admin'     => $my_role === 'admin',
                'muted_until'  => $muted,
                'auto_delete_seconds' => $autoDelete,
                'members'      => chat_group_members($cid),
                'member_count' => db_num_rows("SELECT 1 FROM chat_participants WHERE conversation_id = {$cid}"),
            ];
        }
        // direct: lấy người còn lại.
        $other_id = 0;
        foreach (chat_participant_ids($cid) as $uid) {
            if ($uid !== $me) { $other_id = $uid; break; }
        }
        $other = chat_user_brief($other_id);
        // Biệt danh riêng tư của tôi cho người này (nếu có) → dùng làm tên hiển thị.
        $aliasMap = chat_contact_aliases_map($me);
        $display  = !empty($aliasMap[$other_id]) ? $aliasMap[$other_id] : $other['fullname'];
        return [
            'id'          => $cid,
            'type'        => 'direct',
            'name'        => $display,
            'real_name'   => $other['fullname'],
            'avatar'      => $other['avatar'],
            'user_id'     => $other['id'],
            'username'    => $other['username'],
            'muted_until' => $muted,
            'auto_delete_seconds' => $autoDelete,
        ];
    }

    /** Danh sách thành viên nhóm kèm vai trò (admin = trưởng nhóm). */
    function chat_group_members($conversation_id)
    {
        $cid = (int) $conversation_id;
        $rows = db_fetch_array(
            "SELECT p.user_id, p.role, p.joined_at
             FROM chat_participants p
             WHERE p.conversation_id = {$cid}
             ORDER BY (p.role = 'admin') DESC, p.joined_at ASC, p.id ASC"
        );
        $out = [];
        foreach ($rows as $r) {
            $b = chat_user_brief((int) $r['user_id']);
            $b['role']     = $r['role'];
            $b['is_admin'] = $r['role'] === 'admin';
            $out[] = $b;
        }
        return $out;
    }

    /** Số tin chưa đọc trong 1 hội thoại của 1 user. */
    function chat_unread_in($conversation_id, $user_id)
    {
        $cid = (int) $conversation_id;
        $uid = (int) $user_id;
        $p = db_fetch_row("SELECT last_read_message_id FROM chat_participants
                           WHERE conversation_id = {$cid} AND user_id = {$uid} LIMIT 1");
        if (!$p) return 0;
        $last = (int) $p['last_read_message_id'];
        $r = db_fetch_row("SELECT COUNT(*) AS c FROM chat_messages
                           WHERE conversation_id = {$cid} AND id > {$last}
                             AND sender_id <> {$uid} AND type <> 'system'");
        return $r ? (int) $r['c'] : 0;
    }

    /** Tổng số tin chưa đọc của 1 user (cho badge nút chat). */
    function chat_unread_total($user_id)
    {
        chat_ensure_tables();
        $uid = (int) $user_id;
        $rows = db_fetch_array(
            "SELECT m.conversation_id
             FROM chat_messages m
             JOIN chat_participants p
               ON p.conversation_id = m.conversation_id AND p.user_id = {$uid}
             WHERE m.id > p.last_read_message_id
               AND m.sender_id <> {$uid}
               AND m.type <> 'system'"
        );
        return is_array($rows) ? count($rows) : 0;
    }

    /** Danh sách hội thoại gần đây của 1 user (mới hoạt động trước). */
    function chat_conversations($user_id, $limit = 50)
    {
        chat_ensure_tables();
        $uid = (int) $user_id;
        $lim = max(1, (int) $limit);
        $rows = db_fetch_array(
            "SELECT c.*, p.cleared_at, p.reactions_seen_at
             FROM chat_conversations c
             JOIN chat_participants p ON p.conversation_id = c.id AND p.user_id = {$uid}
             ORDER BY c.updated_at DESC, c.id DESC
             LIMIT {$lim}"
        );
        $aliasMap = chat_contact_aliases_map($uid); // danh xưng riêng để hiện tên người thả cảm xúc
        $out = [];
        foreach ($rows as $c) {
            $cid     = (int) $c['id'];
            $cleared = !empty($c['cleared_at']) ? $c['cleared_at'] : '';
            $clearedCond = $cleared !== '' ? " AND m.created_at > '" . escape_string($cleared) . "'" : '';
            $adminCond   = chat_is_admin($cid, $uid) ? '' : ' AND m.admin_only = 0';

            // Bỏ qua các tin đã bị TÔI tự xóa âm thầm (silent=1, tự xóa theo giờ hẹn) khi
            // tìm "tin cuối" — xem như chưa từng có, nhảy về tin trước đó. Tin bị xóa THỦ
            // CÔNG (silent=0) thì vẫn được tính là "tin cuối" (hiện "Tin nhắn đã xóa").
            $last = db_fetch_row(
                "SELECT m.*, u.fullname AS sender_name
                 FROM chat_messages m
                 LEFT JOIN tbl_users u ON u.id = m.sender_id
                 WHERE m.conversation_id = {$cid} {$clearedCond}{$adminCond}
                   AND m.id NOT IN (
                       SELECT message_id FROM chat_message_deletes WHERE user_id = {$uid} AND silent = 1
                   )
                 ORDER BY m.id DESC LIMIT 1"
            );
            // Đã xóa hội thoại và chưa có tin mới sau mốc xóa → ẩn khỏi danh sách.
            if ($cleared !== '' && !$last) continue;

            $meta = chat_conversation_meta($cid, $uid);
            if (!$meta) continue;

            $preview = '';
            if ($last) {
                $lastBody   = chat_strip_format_tags(crypto_decrypt((string) ($last['body'] ?? '')));
                $lastDelSet = chat_deleted_for_me_set([(int) $last['id']], $uid);
                if ((int) ($last['recalled'] ?? 0) === 1) {
                    $preview = 'Tin nhắn đã được thu hồi';
                } elseif (isset($lastDelSet[(int) $last['id']])) {
                    $preview = 'Tin nhắn đã xóa';
                } elseif ($last['type'] === 'system') {
                    $preview = $lastBody;
                } elseif (trim($lastBody) !== '') {
                    $preview = $lastBody;
                } else {
                    $preview = '[Tệp đính kèm]';
                }
            }
            $meta['last_message']  = $preview;
            $meta['last_sender']   = $last ? ($last['sender_name'] ?? '') : '';
            $meta['last_time']     = $last ? $last['created_at'] : $c['updated_at'];
            $meta['unread']        = chat_unread_in($cid, $uid);

            // Badge cảm xúc kiểu Zalo: người khác vừa thả cảm xúc lên TIN CỦA TÔI mà tôi
            // CHƯA mở xem (sau mốc reactions_seen_at) → hiện "{emoji} {tên người thả}".
            $rseen    = !empty($c['reactions_seen_at']) ? $c['reactions_seen_at'] : '';
            $seenCond = $rseen !== '' ? " AND r.created_at > '" . escape_string($rseen) . "'" : '';
            $rx = db_fetch_row(
                "SELECT r.emoji, r.user_id
                 FROM chat_reactions r
                 JOIN chat_messages m ON m.id = r.message_id
                 WHERE m.conversation_id = {$cid} AND m.sender_id = {$uid}
                   AND m.recalled = 0 AND r.user_id <> {$uid}{$clearedCond}{$seenCond}
                 ORDER BY r.created_at DESC, r.id DESC LIMIT 1"
            );
            $meta['reaction_badge'] = null;
            if ($rx) {
                $ruid  = (int) $rx['user_id'];
                $rname = !empty($aliasMap[$ruid]) ? $aliasMap[$ruid] : chat_user_brief($ruid)['fullname'];
                $meta['reaction_badge'] = ['emoji' => $rx['emoji'], 'name' => $rname];
            }
            $out[] = $meta;
        }
        return $out;
    }

    /* ---------------------------------------------------------------
     *  Tin nhắn
     * --------------------------------------------------------------- */

    /** Chèn 1 tin nhắn, cập nhật updated_at của hội thoại. Trả message_id.
     *  $reply_to_id > 0 → tin này là trả lời cho 1 tin khác (kiểu Zalo). */
    function chat_insert_message($conversation_id, $sender_id, $body, $type = 'text', $reply_to_id = 0, $admin_only = 0, $forwarded = 0)
    {
        $cid = (int) $conversation_id;
        $uid = (int) $sender_id;
        $now = date('Y-m-d H:i:s');
        $mid = (int) db_insert('chat_messages', [
            'conversation_id' => $cid,
            'sender_id'       => $uid,
            'body'            => ($body === '' ? null : crypto_encrypt((string) $body)),
            'type'            => $type,
            'reply_to_id'     => (int) $reply_to_id,
            'admin_only'      => (int) $admin_only ? 1 : 0,
            'forwarded'       => (int) $forwarded ? 1 : 0,
            'created_at'      => $now,
        ]);
        db_update('chat_conversations', ['updated_at' => $now], "id = {$cid}");
        return $mid;
    }

    /** Lấy attachment của 1 tập message_id. Trả [message_id => [att,...]]. */
    function chat_attachments_for(array $message_ids)
    {
        $ids = array_values(array_filter(array_map('intval', $message_ids), static fn($v) => $v > 0));
        if (!$ids) return [];
        $in = implode(',', $ids);
        $rows = db_fetch_array("SELECT * FROM chat_attachments WHERE message_id IN ({$in})");

        // Đã "Thêm vào thư viện của tôi" chưa (fm_chat_imports do file_management tạo) — để
        // nút không hiện lại sau khi tải lại trang (xem public/js/shared/chat.js addLibBtn).
        $importedIds = [];
        if ($rows && db_num_rows("SHOW TABLES LIKE 'fm_chat_imports'") > 0) {
            $attIds = implode(',', array_map(static fn($a) => (int) $a['id'], $rows));
            $imp = db_fetch_array("SELECT attachment_id FROM fm_chat_imports WHERE attachment_id IN ({$attIds})") ?: [];
            foreach ($imp as $i) $importedIds[(int) $i['attachment_id']] = true;
        }

        $map = [];
        foreach ($rows as $a) {
            $map[(int) $a['message_id']][] = [
                'id'            => (int) $a['id'],
                'url'           => 'public/uploads/chat/' . $a['file_name'],
                'original_name' => $a['original_name'],
                'mime'          => $a['mime'],
                'size'          => (int) $a['size'],
                'is_image'      => (int) $a['is_image'] === 1,
                'in_library'    => isset($importedIds[(int) $a['id']]),
            ];
        }
        return $map;
    }

    /** Định dạng 1 dòng message kèm người gửi + attachment + cảm xúc + cờ thu hồi + trích dẫn trả lời. */
    function chat_format_messages(array $rows, $me_id = 0)
    {
        if (!$rows) return [];
        $ids  = array_map(static fn($r) => (int) $r['id'], $rows);
        $atts = chat_attachments_for($ids);
        $reax = chat_reactions_for($ids, (int) $me_id);
        $delSet = chat_deleted_for_me_set($ids, (int) $me_id);

        // Gom các tin được trả lời để lấy trích dẫn 1 lần (tránh N+1).
        $replyIds = [];
        foreach ($rows as $r) { $rid = (int) ($r['reply_to_id'] ?? 0); if ($rid > 0) $replyIds[] = $rid; }
        $replies = chat_reply_previews($replyIds);

        $out  = [];
        foreach ($rows as $r) {
            $mid       = (int) $r['id'];
            $recalled  = (int) ($r['recalled'] ?? 0) === 1;
            $deletedMe = isset($delSet[$mid]); // đã bị TÔI xóa (chỉ ẩn phía tôi, người gửi vẫn giữ nguyên)
            // Tự xóa theo giờ hẹn (silent=true) => ẩn ÂM THẦM, bỏ hẳn khỏi danh sách của tôi,
            // không để lại bong bóng "Tin nhắn đã xóa" (khác xóa thủ công, silent=false).
            if ($deletedMe && $delSet[$mid] === true) continue;
            $brief     = chat_user_brief((int) $r['sender_id']);
            $rid       = (int) ($r['reply_to_id'] ?? 0);
            // Còn thu hồi được không: của tôi, chưa thu hồi, trong vòng 1 giờ (tính theo giờ máy chủ).
            $canRecall = !$recalled
                && (int) $me_id > 0 && (int) $r['sender_id'] === (int) $me_id
                && $r['type'] !== 'system'
                && strtotime($r['created_at']) >= time() - CHAT_RECALL_WINDOW;
            // Chỉ người NHẬN mới xóa được (không xóa tin của chính mình bằng nút này — dùng thu hồi).
            $canDelete = !$recalled && !$deletedMe
                && (int) $me_id > 0 && (int) $r['sender_id'] !== (int) $me_id
                && $r['type'] !== 'system';
            $out[] = [
                'id'             => $mid,
                'conversation_id'=> (int) $r['conversation_id'],
                'sender_id'      => (int) $r['sender_id'],
                'sender_name'    => $brief['fullname'],
                'sender_avatar'  => $brief['avatar'],
                'body'           => ($recalled || $deletedMe) ? '' : crypto_decrypt((string) ($r['body'] ?? '')),
                'type'           => $r['type'],
                'created_at'     => $r['created_at'],
                'recalled'       => $recalled,
                'can_recall'     => $canRecall,
                'deleted_for_me' => $deletedMe,
                'can_delete'     => $canDelete,
                'forwarded'      => (int) ($r['forwarded'] ?? 0) === 1,
                'attachments'    => ($recalled || $deletedMe) ? [] : ($atts[$mid] ?? []),
                'reactions'      => $reax[$mid] ?? [],
                'reply_to'       => ($rid > 0 ? ($replies[$rid] ?? null) : null),
            ];
        }
        return $out;
    }

    /** Bỏ mã định dạng [b][i][u][color=..] khỏi 1 đoạn text — dùng ở các chỗ chỉ hiện
     *  preview thuần (dòng xem trước hội thoại, trích dẫn trả lời...), không phải bong bóng tin. */
    function chat_strip_format_tags($text)
    {
        $text = (string) $text;
        $text = preg_replace('/\[color=(?:red|yellow|blue|green)\]|\[\/color\]/', '', $text);
        $text = preg_replace('/\[\/?[biu]\]/', '', $text);
        return $text;
    }

    /** Trích dẫn gọn cho các tin được trả lời. Trả [id => {id, sender_name, preview, recalled}]. */
    function chat_reply_previews(array $message_ids)
    {
        $ids = array_values(array_filter(array_map('intval', $message_ids), static fn($v) => $v > 0));
        if (!$ids) return [];
        $in   = implode(',', array_unique($ids));
        $rows = db_fetch_array("SELECT id, sender_id, body, type, recalled FROM chat_messages WHERE id IN ({$in})");
        $out  = [];
        foreach ($rows as $r) {
            $recalled = (int) ($r['recalled'] ?? 0) === 1;
            $brief    = chat_user_brief((int) $r['sender_id']);
            $body = chat_strip_format_tags(crypto_decrypt((string) ($r['body'] ?? '')));
            if ($recalled) {
                $preview = 'Tin nhắn đã thu hồi';
            } elseif (trim($body) !== '') {
                $preview = $body;
            } else {
                $preview = '[Tệp đính kèm]';
            }
            if (mb_strlen($preview, 'UTF-8') > 120) $preview = mb_substr($preview, 0, 120, 'UTF-8') . '…';
            $out[(int) $r['id']] = [
                'id'          => (int) $r['id'],
                'sender_name' => $brief['fullname'],
                'preview'     => $preview,
                'recalled'    => $recalled,
            ];
        }
        return $out;
    }

    /** Cảm xúc của 1 tập message_id, gom theo emoji. Trả [mid => [ {emoji,count,mine,users} ]]. */
    function chat_reactions_for(array $message_ids, $me_id = 0)
    {
        $ids = array_values(array_filter(array_map('intval', $message_ids), static fn($v) => $v > 0));
        if (!$ids) return [];
        $in  = implode(',', $ids);
        $me  = (int) $me_id;
        // biệt danh (danh xưng) riêng của người xem để hiện đúng tên trong tooltip
        $aliasMap = chat_contact_aliases_map($me);
        // kèm tên người thả để hiện tooltip khi hover
        $rows = db_fetch_array("SELECT r.message_id, r.emoji, r.user_id, u.fullname, u.username
                                FROM chat_reactions r
                                LEFT JOIN tbl_users u ON u.id = r.user_id
                                WHERE r.message_id IN ({$in})");
        $tmp = [];
        foreach ($rows as $r) {
            $mid = (int) $r['message_id'];
            $em  = $r['emoji'];
            $uid = (int) $r['user_id'];
            if (!isset($tmp[$mid][$em])) $tmp[$mid][$em] = ['emoji' => $em, 'count' => 0, 'mine' => false, 'users' => []];
            $tmp[$mid][$em]['count']++;
            $isMine = $uid === $me;
            if ($isMine) $tmp[$mid][$em]['mine'] = true;
            $real = ($r['fullname'] !== null && $r['fullname'] !== '') ? $r['fullname'] : ($r['username'] ?? 'Người dùng');
            $nm   = !empty($aliasMap[$uid]) ? $aliasMap[$uid] : $real;   // ưu tiên danh xưng đã đặt
            $tmp[$mid][$em]['users'][] = $isMine ? 'Bạn' : $nm;
        }
        $out = [];
        foreach ($tmp as $mid => $byEmoji) {
            $out[$mid] = array_values($byEmoji);
        }
        return $out;
    }

    /**
     * Cập nhật "động" cho các tin gần đây: cảm xúc + tin đã thu hồi.
     * Dùng khi poll để 2 phía đồng bộ cảm xúc / thu hồi mà không phải tải lại.
     * Trả ['reactions' => {mid:[...]}, 'recalled' => [mid,...]].
     */
    function chat_recent_message_updates($conversation_id, $me_id, $limit = 80)
    {
        $cid = (int) $conversation_id;
        $me  = (int) $me_id;
        $lim = max(1, min(200, (int) $limit));
        $rows = db_fetch_array("SELECT id, recalled FROM chat_messages
                                WHERE conversation_id = {$cid} ORDER BY id DESC LIMIT {$lim}");
        $ids = array_map(static fn($r) => (int) $r['id'], $rows);
        $recalled = [];
        foreach ($rows as $r) {
            if ((int) $r['recalled'] === 1) $recalled[] = (int) $r['id'];
        }
        return [
            'reactions' => chat_reactions_for($ids, $me),  // chỉ tin có cảm xúc
            'recalled'  => $recalled,
        ];
    }

    /**
     * Lấy tin nhắn của hội thoại.
     *  - before_id > 0: lấy các tin CŨ hơn (cuộn lên xem thêm).
     *  - after_id  > 0: lấy các tin MỚI hơn (poll thời gian thực).
     *  - mặc định: lô mới nhất.
     * Luôn trả theo thứ tự tăng dần (cũ → mới) để FE append xuôi.
     */
    function chat_messages($conversation_id, $limit = 20, $before_id = 0, $after_id = 0, $me_id = 0)
    {
        chat_ensure_tables();
        $cid    = (int) $conversation_id;
        $lim    = max(1, min(100, (int) $limit));
        $before = (int) $before_id;
        $after  = (int) $after_id;
        $me     = (int) $me_id;

        // Tôn trọng "Xóa hội thoại": chỉ lấy tin sau mốc cleared_at của tôi.
        $clearedCond = '';
        if ($me > 0) {
            $p = db_fetch_row("SELECT cleared_at FROM chat_participants
                               WHERE conversation_id = {$cid} AND user_id = {$me} LIMIT 1");
            if ($p && !empty($p['cleared_at'])) {
                $clearedCond = " AND created_at > '" . escape_string($p['cleared_at']) . "'";
            }
        }
        // Tin chỉ-dành-cho-admin (rời nhóm im lặng): người thường không thấy.
        $adminCond = ($me > 0 && chat_is_admin($cid, $me)) ? '' : ' AND admin_only = 0';

        if ($after > 0) {
            $rows = db_fetch_array(
                "SELECT * FROM chat_messages
                 WHERE conversation_id = {$cid} AND id > {$after} {$clearedCond}{$adminCond}
                 ORDER BY id ASC LIMIT {$lim}"
            );
            return chat_format_messages($rows, $me);
        }

        $cond = "conversation_id = {$cid}";
        if ($before > 0) $cond .= " AND id < {$before}";
        $rows = db_fetch_array(
            "SELECT * FROM chat_messages
             WHERE {$cond} {$clearedCond}{$adminCond}
             ORDER BY id DESC LIMIT {$lim}"
        );
        $rows = array_reverse($rows); // về thứ tự cũ → mới
        return chat_format_messages($rows, $me);
    }

    /**
     * Tìm tin nhắn theo từ khóa trong 1 hội thoại.
     * LƯU Ý: body lưu DẠNG MÃ HÓA nên không thể LIKE trên SQL. Ta quét các tin
     * gần đây của hội thoại, GIẢI MÃ rồi lọc bằng PHP (không phân biệt hoa/thường).
     * Giới hạn quét CHAT_SEARCH_SCAN tin gần nhất để tránh tải nặng — tin quá cũ
     * (ngoài phạm vi này) sẽ không xuất hiện trong kết quả tìm kiếm.
     */
    function chat_search_messages($conversation_id, $keyword, $limit = 50, $me_id = 0)
    {
        chat_ensure_tables();
        $cid = (int) $conversation_id;
        $kw  = trim((string) $keyword);
        if ($kw === '') return [];
        $lim  = max(1, min(100, (int) $limit));
        $scan = 2000; // số tin gần nhất được quét để tìm trong hội thoại
        $rows = db_fetch_array(
            "SELECT * FROM chat_messages
             WHERE conversation_id = {$cid} AND type <> 'system' AND recalled = 0
             ORDER BY id DESC LIMIT {$scan}"
        ) ?: [];

        $delSet  = chat_deleted_for_me_set(array_map(static fn($r) => (int) $r['id'], $rows), (int) $me_id);
        $kwLower = mb_strtolower($kw, 'UTF-8');
        $matched = [];
        foreach ($rows as $r) {
            if (isset($delSet[(int) $r['id']])) continue; // tôi đã tự xóa tin này — không hiện trong tìm kiếm của tôi
            $body = crypto_decrypt((string) ($r['body'] ?? ''));
            if ($body !== '' && mb_stripos($body, $kwLower, 0, 'UTF-8') !== false) {
                $matched[] = $r;
                if (count($matched) >= $lim) break;
            }
        }
        return chat_format_messages($matched, (int) $me_id);
    }

    /** Đánh dấu đã đọc tới message_id mới nhất trong hội thoại. */
    function chat_mark_read($conversation_id, $user_id, $up_to_id = 0)
    {
        $cid = (int) $conversation_id;
        $uid = (int) $user_id;
        $upto = (int) $up_to_id;
        if ($upto <= 0) {
            $r = db_fetch_row("SELECT MAX(id) AS m FROM chat_messages WHERE conversation_id = {$cid}");
            $upto = $r ? (int) $r['m'] : 0;
        }
        db_update('chat_participants',
            ['last_read_message_id' => $upto, 'last_read_at' => date('Y-m-d H:i:s')],
            "conversation_id = {$cid} AND user_id = {$uid} AND last_read_message_id <= {$upto}"
        );
        return $upto;
    }

    /** Đánh dấu đã xem các "thả cảm xúc" trên tin của tôi trong hội thoại (gọi khi mở phòng).
     *  → badge "{emoji} {tên}" ở danh sách hội thoại biến mất. */
    function chat_touch_reactions_seen($conversation_id, $user_id)
    {
        $cid = (int) $conversation_id;
        $uid = (int) $user_id;
        if ($cid <= 0 || $uid <= 0) return;
        db_update('chat_participants', ['reactions_seen_at' => date('Y-m-d H:i:s')],
                  "conversation_id = {$cid} AND user_id = {$uid}");
    }

    /**
     * Lưu 1 file upload vào public/uploads/chat.
     * Trả: ['ok'=>true, ...attachment] khi thành công,
     *      ['ok'=>false, 'reason'=>'...'] khi bị từ chối (để báo người dùng).
     */
    function chat_store_upload($file)
    {
        $orig = (string) ($file['name'] ?? 'tệp');
        if (empty($file) || !isset($file['error'])) {
            return ['ok' => false, 'name' => $orig, 'reason' => 'không nhận được tệp'];
        }
        if ($file['error'] !== UPLOAD_ERR_OK) {
            $msg = ($file['error'] == UPLOAD_ERR_INI_SIZE || $file['error'] == UPLOAD_ERR_FORM_SIZE)
                ? 'vượt giới hạn dung lượng của máy chủ' : 'lỗi tải lên (mã ' . (int) $file['error'] . ')';
            return ['ok' => false, 'name' => $orig, 'reason' => $msg];
        }
        if ($file['size'] > 25 * 1024 * 1024) {
            return ['ok' => false, 'name' => $orig, 'reason' => 'vượt 25MB'];
        }

        $dir = APPPATH . DIRECTORY_SEPARATOR . 'public' . DIRECTORY_SEPARATOR
             . 'uploads' . DIRECTORY_SEPARATOR . 'chat';
        if (!is_dir($dir)) @mkdir($dir, 0775, true);

        $ext = strtolower(pathinfo($orig, PATHINFO_EXTENSION));
        // Chặn các đuôi thực thi nguy hiểm.
        $blocked = ['php', 'php3', 'php4', 'php5', 'phtml', 'phar', 'exe', 'sh', 'bat', 'cmd', 'htaccess'];
        if (in_array($ext, $blocked, true)) {
            return ['ok' => false, 'name' => $orig, 'reason' => 'định dạng .' . $ext . ' không được phép'];
        }

        $info = @getimagesize($file['tmp_name']);
        $is_image = $info !== false ? 1 : 0;
        $safe_ext = preg_replace('/[^a-z0-9]/', '', $ext);
        $filename = 'c' . time() . '_' . substr(md5($orig . uniqid('', true)), 0, 10)
                  . ($safe_ext !== '' ? '.' . $safe_ext : '');
        $dest = $dir . DIRECTORY_SEPARATOR . $filename;
        if (!move_uploaded_file($file['tmp_name'], $dest)) {
            return ['ok' => false, 'name' => $orig, 'reason' => 'không lưu được tệp'];
        }

        return [
            'ok'            => true,
            'file_name'     => $filename,
            'original_name' => mb_substr($orig, 0, 250, 'UTF-8'),
            'mime'          => (string) ($file['type'] ?? ''),
            'size'          => (int) $file['size'],
            'is_image'      => $is_image,
        ];
    }

    /* ===============================================================
     *  NÂNG CẤP: thu hồi, cảm xúc, thành viên, mute, đổi tên/ảnh,
     *  nhắc hẹn, trạng thái đã xem.
     * =============================================================== */

    /** Số giây cho phép thu hồi tin sau khi gửi (1 giờ). */
    if (!defined('CHAT_RECALL_WINDOW')) define('CHAT_RECALL_WINDOW', 3600);

    /** Thu hồi 1 tin nhắn (chỉ người gửi, trong vòng 1 giờ kể từ khi gửi).
     *  Trả ['ok'=>bool, 'message'=>...]. */
    function chat_recall_message($message_id, $user_id)
    {
        $mid = (int) $message_id;
        $uid = (int) $user_id;
        $m = db_fetch_row("SELECT sender_id, created_at, recalled FROM chat_messages WHERE id = {$mid} LIMIT 1");
        if (!$m || (int) $m['sender_id'] !== $uid) {
            return ['ok' => false, 'message' => 'Không thể thu hồi tin này.'];
        }
        if ((int) ($m['recalled'] ?? 0) === 1) {
            return ['ok' => true]; // đã thu hồi rồi
        }
        // Chỉ cho thu hồi trong vòng 1 giờ kể từ lúc gửi.
        if (strtotime($m['created_at']) < time() - CHAT_RECALL_WINDOW) {
            return ['ok' => false, 'message' => 'Chỉ có thể thu hồi trong vòng 1 giờ sau khi gửi.'];
        }
        db_update('chat_messages', ['recalled' => 1, 'body' => null], "id = {$mid}");
        db_delete('chat_attachments', "message_id = {$mid}");
        db_delete('chat_reactions', "message_id = {$mid}");
        return ['ok' => true];
    }

    /** Tập message_id mà $me_id đã tự xóa (phía mình). Trả [mid => silent(bool)] —
     *  silent=true (tự xóa theo giờ hẹn) => ẩn hoàn toàn; silent=false (xóa thủ công)
     *  => vẫn hiện bong bóng "Tin nhắn đã xóa" (xem chat_format_messages). */
    function chat_deleted_for_me_set(array $message_ids, $me_id)
    {
        $me = (int) $me_id;
        $ids = array_values(array_filter(array_map('intval', $message_ids), static fn($v) => $v > 0));
        if (!$ids || $me <= 0) return [];
        $in = implode(',', array_unique($ids));
        $rows = db_fetch_array(
            "SELECT message_id, silent FROM chat_message_deletes WHERE user_id = {$me} AND message_id IN ({$in})"
        ) ?: [];
        $out = [];
        foreach ($rows as $r) $out[(int) $r['message_id']] = (int) ($r['silent'] ?? 0) === 1;
        return $out;
    }

    /** Người NHẬN tự xóa 1 tin phía mình (người gửi vẫn giữ nguyên tin). Trả ['ok'=>bool,'message'=>...]. */
    function chat_delete_message_for_me($message_id, $user_id)
    {
        chat_ensure_tables();
        $mid = (int) $message_id;
        $uid = (int) $user_id;
        $m = db_fetch_row("SELECT conversation_id, sender_id, recalled FROM chat_messages WHERE id = {$mid} LIMIT 1");
        if (!$m || !chat_is_participant((int) $m['conversation_id'], $uid)) {
            return ['ok' => false, 'message' => 'Không có quyền.'];
        }
        if ((int) $m['sender_id'] === $uid) {
            return ['ok' => false, 'message' => 'Dùng thu hồi cho tin nhắn của bạn.'];
        }
        if ((int) ($m['recalled'] ?? 0) === 1) {
            return ['ok' => true]; // đã thu hồi rồi, không cần xóa thêm
        }
        $exists = db_fetch_row(
            "SELECT 1 AS n FROM chat_message_deletes WHERE message_id = {$mid} AND user_id = {$uid} LIMIT 1"
        );
        if (!$exists) {
            // silent=0: xóa thủ công -> vẫn để lại bong bóng "Tin nhắn đã xóa" (khác tự xóa theo giờ).
            db_insert('chat_message_deletes', ['message_id' => $mid, 'user_id' => $uid, 'deleted_at' => date('Y-m-d H:i:s'), 'silent' => 0]);
        }
        return ['ok' => true];
    }

    /** Thả / đổi / gỡ cảm xúc cho 1 tin. Bấm lại cùng emoji = gỡ. Trả danh sách cảm xúc mới. */
    function chat_react($message_id, $user_id, $emoji)
    {
        chat_ensure_tables();
        $mid = (int) $message_id;
        $uid = (int) $user_id;
        $em  = trim((string) $emoji);
        if ($mid <= 0 || $uid <= 0 || $em === '') return [];
        if (mb_strlen($em, 'UTF-8') > 8) $em = mb_substr($em, 0, 8, 'UTF-8');

        $cur = db_fetch_row("SELECT id, emoji FROM chat_reactions
                             WHERE message_id = {$mid} AND user_id = {$uid} LIMIT 1");
        if ($cur) {
            if ($cur['emoji'] === $em) {
                db_delete('chat_reactions', "id = " . (int) $cur['id']); // gỡ
            } else {
                db_update('chat_reactions', ['emoji' => $em, 'created_at' => date('Y-m-d H:i:s')],
                          "id = " . (int) $cur['id']);                    // đổi
            }
        } else {
            db_insert('chat_reactions', [
                'message_id' => $mid, 'user_id' => $uid,
                'emoji' => $em, 'created_at' => date('Y-m-d H:i:s'),
            ]);
        }
        $map = chat_reactions_for([$mid], $uid);
        return $map[$mid] ?? [];
    }

    /** Tên hiển thị mặc định cho nhóm khi chưa đặt tên (ghép tên thành viên). */
    function chat_default_group_name($conversation_id)
    {
        $names = [];
        foreach (chat_group_members($conversation_id) as $m) {
            $names[] = $m['fullname'];
            if (count($names) >= 3) break;
        }
        return $names ? implode(', ', $names) : 'Nhóm mới';
    }

    /**
     * Thêm thành viên vào hội thoại. Nếu là direct → chuyển thành group.
     * $actor = người thực hiện (sẽ là admin nếu vừa tạo nhóm từ direct).
     */
    function chat_add_members($conversation_id, $actor_id, array $new_ids)
    {
        chat_ensure_tables();
        $cid   = (int) $conversation_id;
        $actor = (int) $actor_id;
        if (!chat_is_participant($cid, $actor)) return false;

        $c = db_fetch_row("SELECT * FROM chat_conversations WHERE id = {$cid} LIMIT 1");
        if (!$c) return false;

        $ids = array_values(array_unique(array_filter(array_map('intval', $new_ids), static fn($v) => $v > 0)));
        if (!$ids) return false;

        $now = date('Y-m-d H:i:s');

        // direct → group: đặt tên mặc định, người thêm thành admin.
        if ($c['type'] === 'direct') {
            db_update('chat_conversations', ['type' => 'group', 'updated_at' => $now], "id = {$cid}");
            db_update('chat_participants', ['role' => 'admin'], "conversation_id = {$cid} AND user_id = {$actor}");
        }

        $added = [];
        foreach ($ids as $uid) {
            if (chat_is_participant($cid, $uid)) continue;
            db_insert('chat_participants', [
                'conversation_id'      => $cid,
                'user_id'              => $uid,
                'role'                 => 'member',
                'last_read_message_id' => 0,
                'joined_at'            => $now,
            ]);
            $added[] = chat_user_brief($uid)['fullname'];
        }

        // Đặt tên mặc định nếu nhóm chưa có tên.
        $c2 = db_fetch_row("SELECT name, type FROM chat_conversations WHERE id = {$cid} LIMIT 1");
        if ($c2 && $c2['type'] === 'group' && trim((string) $c2['name']) === '') {
            db_update('chat_conversations', ['name' => chat_default_group_name($cid)], "id = {$cid}");
        }

        if ($added) {
            $who = chat_user_brief($actor)['fullname'];
            chat_insert_message($cid, $actor, $who . ' đã thêm ' . implode(', ', $added) . ' vào nhóm', 'system');
        }
        return true;
    }

    /** Trưởng nhóm (admin) đuổi 1 thành viên. */
    function chat_remove_member($conversation_id, $actor_id, $target_id)
    {
        $cid    = (int) $conversation_id;
        $actor  = (int) $actor_id;
        $target = (int) $target_id;
        $a = db_fetch_row("SELECT role FROM chat_participants
                           WHERE conversation_id = {$cid} AND user_id = {$actor} LIMIT 1");
        if (!$a || $a['role'] !== 'admin') return false;     // chỉ admin
        if ($target === $actor) return false;                // không tự đuổi
        if (!chat_is_participant($cid, $target)) return false;

        $name = chat_user_brief($target)['fullname'];
        db_delete('chat_participants', "conversation_id = {$cid} AND user_id = {$target}");
        $who = chat_user_brief($actor)['fullname'];
        chat_insert_message($cid, $actor, $who . ' đã mời ' . $name . ' ra khỏi nhóm', 'system');
        return true;
    }

    /**
     * Rời nhóm / rời hội thoại.
     *  - $silent=1: "rời nhóm trong im lặng" → thông báo hệ thống CHỈ trưởng nhóm thấy.
     *  - $new_admin_id: nếu người rời là trưởng nhóm DUY NHẤT và còn người ở lại,
     *    BẮT BUỘC chỉ định người kế nhiệm. Thiếu → trả ['need_admin'=>true] để FE hỏi.
     * Trả mảng ['ok'=>bool, ...].
     */
    function chat_leave_conversation($conversation_id, $user_id, $silent = 0, $new_admin_id = 0)
    {
        $cid    = (int) $conversation_id;
        $uid    = (int) $user_id;
        $silent = (int) $silent ? 1 : 0;
        $newAdm = (int) $new_admin_id;
        if (!chat_is_participant($cid, $uid)) return ['ok' => false, 'message' => 'Không thể rời.'];

        $name      = chat_user_brief($uid)['fullname'];
        $was_admin = chat_is_admin($cid, $uid);
        // Số người còn lại SAU khi tôi rời.
        $remain = db_num_rows("SELECT 1 FROM chat_participants WHERE conversation_id = {$cid} AND user_id <> {$uid}");

        // Trưởng nhóm rời mà còn người ở lại: cần đảm bảo nhóm vẫn còn trưởng.
        if ($was_admin && $remain > 0) {
            $otherAdmins = db_num_rows("SELECT 1 FROM chat_participants
                                        WHERE conversation_id = {$cid} AND user_id <> {$uid} AND role = 'admin'");
            if ($otherAdmins === 0) {
                // Chưa có trưởng nhóm khác → phải chọn người kế nhiệm.
                if ($newAdm <= 0 || $newAdm === $uid || !chat_is_participant($cid, $newAdm)) {
                    return [
                        'ok'         => false,
                        'need_admin' => true,
                        'message'    => 'Bạn là trưởng nhóm, hãy chọn người kế nhiệm trước khi rời.',
                        'members'    => array_values(array_filter(chat_group_members($cid),
                                            static fn($m) => (int) $m['id'] !== $uid)),
                    ];
                }
                db_update('chat_participants', ['role' => 'admin'],
                          "conversation_id = {$cid} AND user_id = {$newAdm}");
            }
        }

        db_delete('chat_participants', "conversation_id = {$cid} AND user_id = {$uid}");

        if ($remain > 0) {
            // Im lặng → admin_only=1 (chỉ trưởng nhóm thấy "X đã rời nhóm").
            chat_insert_message($cid, $uid, $name . ' đã rời nhóm', 'system', 0, $silent);
        }
        return ['ok' => true];
    }

    /** Xóa hội thoại (chỉ phía mình): ẩn lịch sử cũ, không đụng người khác. */
    function chat_clear_conversation($conversation_id, $user_id)
    {
        $cid = (int) $conversation_id;
        $uid = (int) $user_id;
        if (!chat_is_participant($cid, $uid)) return false;
        $now = date('Y-m-d H:i:s');
        $maxRow = db_fetch_row("SELECT MAX(id) AS m FROM chat_messages WHERE conversation_id = {$cid}");
        $upto = $maxRow ? (int) $maxRow['m'] : 0;
        db_update('chat_participants',
            ['cleared_at' => $now, 'last_read_message_id' => $upto, 'last_read_at' => $now],
            "conversation_id = {$cid} AND user_id = {$uid}"
        );
        return true;
    }

    /**
     * Tắt thông báo. $mode: '1h' | '4h' | 'forever' | 'off'.
     * 'forever' = đến khi mở lại (lưu mốc rất xa).
     */
    function chat_mute($conversation_id, $user_id, $mode)
    {
        $cid = (int) $conversation_id;
        $uid = (int) $user_id;
        if (!chat_is_participant($cid, $uid)) return false;
        if ($mode === 'off') {
            db_update('chat_participants', ['muted_until' => null], "conversation_id = {$cid} AND user_id = {$uid}");
            return true;
        }
        $until = null;
        if ($mode === '1h')      $until = date('Y-m-d H:i:s', time() + 3600);
        elseif ($mode === '4h')  $until = date('Y-m-d H:i:s', time() + 4 * 3600);
        elseif ($mode === 'forever') $until = '2099-12-31 23:59:59';
        else return false;
        db_update('chat_participants', ['muted_until' => $until], "conversation_id = {$cid} AND user_id = {$uid}");
        return true;
    }

    /** Đổi tên nhóm (ai trong nhóm cũng được). */
    function chat_rename_group($conversation_id, $actor_id, $name)
    {
        $cid   = (int) $conversation_id;
        $actor = (int) $actor_id;
        $name  = trim((string) $name);
        if ($name === '' || !chat_is_participant($cid, $actor)) return false;
        if (mb_strlen($name, 'UTF-8') > 150) $name = mb_substr($name, 0, 150, 'UTF-8');
        $c = db_fetch_row("SELECT type FROM chat_conversations WHERE id = {$cid} LIMIT 1");
        if (!$c || $c['type'] !== 'group') return false;
        db_update('chat_conversations', ['name' => $name], "id = {$cid}");
        $who = chat_user_brief($actor)['fullname'];
        chat_insert_message($cid, $actor, $who . ' đã đổi tên nhóm thành "' . $name . '"', 'system');
        return true;
    }

    /** Đổi ảnh đại diện nhóm (lưu vào public/images/avatar dùng chung). */
    function chat_set_group_avatar($conversation_id, $actor_id, $file)
    {
        $cid   = (int) $conversation_id;
        $actor = (int) $actor_id;
        if (!chat_is_participant($cid, $actor)) return ['ok' => false, 'message' => 'Không có quyền.'];
        $c = db_fetch_row("SELECT type, avatar FROM chat_conversations WHERE id = {$cid} LIMIT 1");
        if (!$c || $c['type'] !== 'group') return ['ok' => false, 'message' => 'Chỉ áp dụng cho nhóm.'];

        if (empty($file) || ($file['error'] ?? 1) !== UPLOAD_ERR_OK) {
            return ['ok' => false, 'message' => 'Không nhận được ảnh.'];
        }
        $info = @getimagesize($file['tmp_name']);
        if ($info === false) return ['ok' => false, 'message' => 'Tệp không phải hình ảnh.'];
        if ($file['size'] > 4 * 1024 * 1024) return ['ok' => false, 'message' => 'Ảnh vượt quá 4MB.'];
        $map = [IMAGETYPE_JPEG => 'jpg', IMAGETYPE_PNG => 'png', IMAGETYPE_GIF => 'gif', IMAGETYPE_WEBP => 'webp'];
        if (!isset($map[$info[2]])) return ['ok' => false, 'message' => 'Chỉ JPG/PNG/GIF/WEBP.'];

        $dir = APPPATH . DIRECTORY_SEPARATOR . 'public' . DIRECTORY_SEPARATOR . 'images' . DIRECTORY_SEPARATOR . 'avatar';
        if (!is_dir($dir)) @mkdir($dir, 0775, true);
        $filename = 'g' . $cid . '_' . time() . '.' . $map[$info[2]];
        if (!move_uploaded_file($file['tmp_name'], $dir . DIRECTORY_SEPARATOR . $filename)) {
            return ['ok' => false, 'message' => 'Không lưu được ảnh.'];
        }
        // Xóa ảnh nhóm cũ.
        $old = trim((string) ($c['avatar'] ?? ''));
        if ($old !== '' && is_file($dir . DIRECTORY_SEPARATOR . basename($old))) {
            @unlink($dir . DIRECTORY_SEPARATOR . basename($old));
        }
        db_update('chat_conversations', ['avatar' => $filename], "id = {$cid}");
        $who = chat_user_brief($actor)['fullname'];
        chat_insert_message($cid, $actor, $who . ' đã đổi ảnh đại diện nhóm', 'system');
        return ['ok' => true, 'avatar' => chat_avatar_url($filename)];
    }

    /** Tạo nhắc hẹn cho 1 tin nhắn của tôi (hiện lại lúc remind_at).
     *  $note = nội dung nhắc do người dùng nhập/sửa (mặc định lấy nội dung tin). */
    function chat_set_reminder($user_id, $message_id, $remind_at, $note = '')
    {
        chat_ensure_tables();
        $uid = (int) $user_id;
        $mid = (int) $message_id;
        $when = trim((string) $remind_at);
        $ts = strtotime($when);
        if ($uid <= 0 || $mid <= 0 || !$ts) return ['ok' => false, 'message' => 'Thời gian không hợp lệ.'];
        if ($ts <= time()) return ['ok' => false, 'message' => 'Hãy chọn thời điểm trong tương lai.'];

        $m = db_fetch_row("SELECT conversation_id FROM chat_messages WHERE id = {$mid} LIMIT 1");
        if (!$m) return ['ok' => false, 'message' => 'Tin nhắn không tồn tại.'];
        if (!chat_is_participant((int) $m['conversation_id'], $uid)) {
            return ['ok' => false, 'message' => 'Không có quyền.'];
        }
        $note = trim((string) $note);
        if (mb_strlen($note, 'UTF-8') > 1000) $note = mb_substr($note, 0, 1000, 'UTF-8');
        db_insert('chat_reminders', [
            'user_id'         => $uid,
            'message_id'      => $mid,
            'conversation_id' => (int) $m['conversation_id'],
            'remind_at'       => date('Y-m-d H:i:s', $ts),
            'note'            => ($note === '' ? null : $note),
            'notified'        => 0,
            'created_at'      => date('Y-m-d H:i:s'),
        ]);
        return ['ok' => true, 'remind_at' => date('Y-m-d H:i:s', $ts)];
    }

    /** Nhắc hẹn tới hạn (chưa báo) của 1 user → đánh dấu đã báo, trả kèm preview tin. */
    function chat_due_reminders($user_id)
    {
        chat_ensure_tables();
        $uid = (int) $user_id;
        if ($uid <= 0) return [];
        $now = date('Y-m-d H:i:s');
        $rows = db_fetch_array(
            "SELECT * FROM chat_reminders
             WHERE user_id = {$uid} AND notified = 0 AND remind_at <= '" . escape_string($now) . "'
             ORDER BY remind_at ASC LIMIT 20"
        );
        $out = [];
        foreach ($rows as $r) {
            db_update('chat_reminders', ['notified' => 1], "id = " . (int) $r['id']);
            $cid = (int) $r['conversation_id'];
            $mrow = db_fetch_array("SELECT * FROM chat_messages WHERE id = " . (int) $r['message_id'] . " LIMIT 1");
            $fmt  = $mrow ? chat_format_messages($mrow, $uid) : [];
            $meta = chat_conversation_meta($cid, $uid);
            $out[] = [
                'id'              => (int) $r['id'],
                'conversation_id' => $cid,
                'conv_name'       => $meta ? $meta['name'] : 'Hội thoại',
                'remind_at'       => $r['remind_at'],
                'message_id'      => (int) $r['message_id'],
                'note'            => (string) ($r['note'] ?? ''),
                'message'         => $fmt ? $fmt[0] : null,
            ];
        }
        return $out;
    }

    /**
     * Trạng thái "đã xem" của các thành viên khác trong hội thoại.
     * Trả [ {user_id, fullname, avatar, last_read_message_id, last_read_at} ] (loại trừ tôi).
     */
    function chat_readers($conversation_id, $me_id)
    {
        $cid = (int) $conversation_id;
        $me  = (int) $me_id;
        // Người xem là trưởng nhóm (admin) → thấy hết, kể cả người đang ẩn online.
        // Người thường → KHÔNG thấy "đã xem" của ai đang để chế độ ẩn online.
        $iAmAdmin = chat_is_admin($cid, $me);
        $hideCond = $iAmAdmin ? '' : ' AND (s.show_online IS NULL OR s.show_online = 1)';
        $rows = db_fetch_array(
            "SELECT p.user_id, p.last_read_message_id, p.last_read_at
             FROM chat_participants p
             LEFT JOIN chat_user_settings s ON s.user_id = p.user_id
             WHERE p.conversation_id = {$cid} AND p.user_id <> {$me}
               AND p.last_read_message_id > 0{$hideCond}"
        );
        $out = [];
        foreach ($rows as $r) {
            $b = chat_user_brief((int) $r['user_id']);
            $out[] = [
                'user_id'              => (int) $r['user_id'],
                'fullname'             => $b['fullname'],
                'avatar'               => $b['avatar'],
                'last_read_message_id' => (int) $r['last_read_message_id'],
                'last_read_at'         => $r['last_read_at'],
            ];
        }
        return $out;
    }

    /**
     * Tin đến mới nhất (người khác gửi) trong các hội thoại của tôi, BỎ QUA nhóm đang mute.
     * Dùng cho auto-mở khung chat. Trả {message_id, conversation_id} hoặc null.
     */
    function chat_latest_incoming($user_id)
    {
        chat_ensure_tables();
        $uid = (int) $user_id;
        $now = date('Y-m-d H:i:s');
        $row = db_fetch_row(
            "SELECT m.id, m.conversation_id, m.sender_id, m.body, m.type
             FROM chat_messages m
             JOIN chat_participants p
               ON p.conversation_id = m.conversation_id AND p.user_id = {$uid}
             WHERE m.sender_id <> {$uid} AND m.type <> 'system' AND m.recalled = 0
               AND (p.muted_until IS NULL OR p.muted_until <= '" . escape_string($now) . "')
               AND (p.cleared_at IS NULL OR m.created_at > p.cleared_at)
             ORDER BY m.id DESC LIMIT 1"
        );
        if (!$row) return null;
        $cid  = (int) $row['conversation_id'];
        $meta = chat_conversation_meta($cid, $uid);
        $sender = chat_user_brief((int) $row['sender_id'])['fullname'];
        $incBody = chat_strip_format_tags(crypto_decrypt((string) $row['body']));
        $preview = trim($incBody) !== '' ? $incBody : '[Tệp đính kèm]';
        if (mb_strlen($preview, 'UTF-8') > 120) $preview = mb_substr($preview, 0, 120, 'UTF-8') . '…';
        return [
            'message_id'      => (int) $row['id'],
            'conversation_id' => $cid,
            'conv_name'       => $meta ? $meta['name'] : 'Hội thoại',
            'sender_name'     => $sender,
            'preview'         => $preview,
        ];
    }

    /* ---------------------------------------------------------------
     *  Thẻ thông tin người dùng (cho UserCard dùng chung) + chia sẻ tin
     * --------------------------------------------------------------- */

    /** Thông tin đầy đủ 1 user cho thẻ card: tên, username, email, avatar, vai trò. */
    function chat_user_card($user_id)
    {
        $uid = (int) $user_id;
        $r = db_fetch_row("SELECT id, fullname, username, email, avatar, role
                           FROM tbl_users WHERE id = {$uid} LIMIT 1");
        if (!$r) return null;
        return [
            'id'       => (int) $r['id'],
            'fullname' => $r['fullname'] !== '' ? $r['fullname'] : $r['username'],
            'username' => (string) $r['username'],
            'email'    => (string) ($r['email'] ?? ''),
            'avatar'   => chat_avatar_url($r['avatar']),
            'is_admin' => ($r['role'] ?? '') === 'admin',
        ];
    }

    /**
     * Chia sẻ lại (forward) 1 tin nhắn sang nhiều đích:
     *  - $conv_ids: id các hội thoại đã có (Gần đây / Nhóm).
     *  - $user_ids: id các liên hệ (Danh bạ) → tự lấy/tạo hội thoại 1-1.
     * Sao chép nội dung text + ảnh/tệp đính kèm (dùng lại file đã lưu).
     * Trả ['ok'=>bool, 'count'=>n, 'message'=>...].
     */
    function chat_forward_message($message_id, array $conv_ids, array $user_ids, $actor_id)
    {
        chat_ensure_tables();
        $mid   = (int) $message_id;
        $actor = (int) $actor_id;
        if ($mid <= 0 || $actor <= 0) return ['ok' => false, 'message' => 'Thiếu dữ liệu.'];

        $src = db_fetch_row("SELECT * FROM chat_messages WHERE id = {$mid} LIMIT 1");
        if (!$src) return ['ok' => false, 'message' => 'Tin nhắn không tồn tại.'];
        // Chỉ cho chia sẻ tin mình có quyền xem (là thành viên hội thoại gốc).
        if (!chat_is_participant((int) $src['conversation_id'], $actor)) {
            return ['ok' => false, 'message' => 'Không có quyền chia sẻ tin này.'];
        }
        if ((int) ($src['recalled'] ?? 0) === 1 || $src['type'] === 'system') {
            return ['ok' => false, 'message' => 'Không thể chia sẻ tin này.'];
        }

        // Gom danh sách hội thoại đích (loại trùng): từ conv_ids + direct của user_ids.
        $targets = [];
        foreach ($conv_ids as $cid) {
            $cid = (int) $cid;
            if ($cid > 0 && chat_is_participant($cid, $actor)) $targets[$cid] = true;
        }
        foreach ($user_ids as $uid) {
            $uid = (int) $uid;
            if ($uid <= 0 || $uid === $actor) continue;
            $cid = chat_get_or_create_direct($actor, $uid);
            if ($cid > 0) $targets[$cid] = true;
        }
        if (!$targets) return ['ok' => false, 'message' => 'Chưa chọn nơi nhận.'];

        // Đính kèm của tin gốc (sao chép tham chiếu file, không upload lại).
        $atts = db_fetch_array("SELECT file_name, original_name, mime, size, is_image
                                FROM chat_attachments WHERE message_id = {$mid}");
        // $src['body'] đang ở dạng mã hóa → giải mã trước khi forward (chat_insert_message sẽ mã hóa lại).
        $body = crypto_decrypt((string) ($src['body'] ?? ''));
        $type = $src['type'];

        $count = 0;
        foreach (array_keys($targets) as $cid) {
            $newId = chat_insert_message($cid, $actor, $body, $type, 0, 0, 1); // forwarded=1
            foreach ($atts as $a) {
                db_insert('chat_attachments', [
                    'message_id'    => $newId,
                    'file_name'     => $a['file_name'],
                    'original_name' => $a['original_name'],
                    'mime'          => $a['mime'],
                    'size'          => (int) $a['size'],
                    'is_image'      => (int) $a['is_image'],
                ]);
            }
            chat_mark_read($cid, $actor, $newId);
            $count++;
        }
        return ['ok' => true, 'count' => $count];
    }
}
