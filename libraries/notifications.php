<?php
/**
 * notifications — thông báo đẩy vào "chuông" (bell) trên app-header.
 * Lưu bảng `notifications` (1 dòng / 1 người nhận). Dùng chung mọi module.
 * Áp dụng đầu tiên: khi có thành viên đăng ký mới → đẩy cho các tài khoản admin.
 *
 * File require_once từ nhiều nơi → mọi hàm có guard function_exists.
 */

// Mốc thời gian thông báo phải theo giờ VN (created_at = date('Y-m-d H:i:s')).
// Nếu không set, các endpoint chưa nạp lib khác sẽ dùng giờ mặc định -> chuông sai giờ.
if (date_default_timezone_get() !== 'Asia/Ho_Chi_Minh') {
    date_default_timezone_set('Asia/Ho_Chi_Minh');
}

if (!function_exists('notify_ensure_table')) {

    /** Tạo bảng notifications nếu chưa có (1 lần / request). */
    function notify_ensure_table()
    {
        static $done = false;
        if ($done) return;
        $done = true;
        db_query("CREATE TABLE IF NOT EXISTS notifications (
            id          INT(11) NOT NULL AUTO_INCREMENT,
            user_id     INT(11) NOT NULL,
            actor_id    INT(11) NOT NULL DEFAULT 0,
            type        VARCHAR(50)  NOT NULL DEFAULT 'general',
            title       VARCHAR(255) NOT NULL,
            message     TEXT DEFAULT NULL,
            link        VARCHAR(255) DEFAULT NULL,
            is_read     TINYINT(1) NOT NULL DEFAULT 0,
            created_at  DATETIME NOT NULL,
            PRIMARY KEY (id),
            KEY idx_user_read (user_id, is_read)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci");
        // Bảng cũ có thể thiếu cột actor_id (người gây ra thông báo) → bổ sung 1 lần.
        $col = db_fetch_row("SHOW COLUMNS FROM notifications LIKE 'actor_id'");
        if (!$col) db_query("ALTER TABLE notifications ADD actor_id INT(11) NOT NULL DEFAULT 0 AFTER user_id");
    }

    /** Tạo 1 thông báo cho 1 người nhận. $actor_id = user gây ra (0 = hệ thống). Trả id vừa chèn. */
    function notify_create($user_id, $title, $message = '', $link = '', $type = 'general', $actor_id = 0)
    {
        $uid = (int) $user_id;
        if ($uid <= 0) return 0;
        notify_ensure_table();
        return (int) db_insert('notifications', [
            'user_id'    => $uid,
            'actor_id'   => (int) $actor_id,
            'type'       => (string) $type,
            'title'      => (string) $title,
            'message'    => (string) $message,
            'link'       => (string) $link,
            'is_read'    => 0,
            'created_at' => date('Y-m-d H:i:s'),
        ]);
    }

    /** Lấy id các tài khoản admin (để đẩy thông báo / gửi mail). */
    function notify_admin_ids()
    {
        $rows = db_fetch_array("SELECT id FROM tbl_users WHERE role = 'admin'");
        return array_map(static function ($r) { return (int) $r['id']; }, $rows ?: []);
    }

    /** Đẩy 1 thông báo tới TẤT CẢ admin. Trả số bản ghi đã tạo. */
    function notify_admins($title, $message = '', $link = '', $type = 'general')
    {
        $n = 0;
        foreach (notify_admin_ids() as $aid) {
            if (notify_create($aid, $title, $message, $link, $type) > 0) $n++;
        }
        return $n;
    }

    /** Kiểm tra 1 bảng có tồn tại không (cache trong request). */
    function notify_table_exists($table)
    {
        static $cache = [];
        $t = (string) $table;
        if (isset($cache[$t])) return $cache[$t];
        $esc = escape_string($t);
        $row = db_fetch_row("SHOW TABLES LIKE '{$esc}'");
        return $cache[$t] = (bool) $row;
    }

    /**
     * Bản đồ tên-thật => danh-xưng mà NGƯỜI NHẬN ($recipient_id) đặt trong danh bạ chat.
     * Mục đích: rút gọn thông báo, hiển thị "Anh 6 QL" thay vì "Truong Ngoc Sau".
     * Cache theo từng người nhận.
     */
    function notify_alias_name_map($recipient_id)
    {
        static $cache = [];
        $rid = (int) $recipient_id;
        if ($rid <= 0) return [];
        if (isset($cache[$rid])) return $cache[$rid];
        if (!notify_table_exists('chat_contact_aliases')) return $cache[$rid] = [];
        $rows = db_fetch_array(
            "SELECT a.alias, u.fullname, u.username
             FROM chat_contact_aliases a
             JOIN tbl_users u ON u.id = a.contact_id
             WHERE a.owner_id = {$rid} AND a.alias <> ''"
        ) ?: [];
        $map = [];
        foreach ($rows as $r) {
            $alias = trim((string) $r['alias']);
            if ($alias === '') continue;
            $full = trim((string) ($r['fullname'] ?? ''));
            $user = trim((string) ($r['username'] ?? ''));
            if ($full !== '') $map[$full] = $alias;
            if ($user !== '') $map[$user] = $alias;
        }
        return $cache[$rid] = $map;
    }

    /**
     * Bản đồ tên khách hàng đầy đủ => tên viết tắt (short_name). Dùng chung mọi người nhận.
     * Chỉ lấy khách có short_name khác rỗng và khác tên đầy đủ.
     */
    function notify_customer_short_map()
    {
        static $map = null;
        if ($map !== null) return $map;
        $map = [];
        if (!notify_table_exists('customers')) return $map;
        $rows = db_fetch_array(
            "SELECT name, short_name FROM customers
             WHERE short_name IS NOT NULL AND short_name <> '' AND short_name <> name"
        ) ?: [];
        foreach ($rows as $r) {
            $name  = trim((string) $r['name']);
            $short = trim((string) $r['short_name']);
            if ($name !== '' && $short !== '') $map[$name] = $short;
        }
        return $map;
    }

    /**
     * Rút gọn 1 chuỗi thông báo cho người nhận: thay fullname -> danh xưng,
     * thay tên khách -> tên viết tắt. Thay chuỗi dài trước để tránh chồng lấn.
     */
    function notify_humanize($text, $recipient_id)
    {
        $text = (string) $text;
        if ($text === '') return $text;
        $map = notify_alias_name_map($recipient_id) + notify_customer_short_map();
        if (!$map) return $text;
        $keys = array_keys($map);
        usort($keys, static function ($a, $b) {
            return mb_strlen($b, 'UTF-8') - mb_strlen($a, 'UTF-8');
        });
        foreach ($keys as $from) {
            if ($from === '' || mb_strlen($from, 'UTF-8') < 2) continue;
            if (strpos($text, $from) !== false) {
                $text = str_replace($from, $map[$from], $text);
            }
        }
        return $text;
    }

    /** Danh sách thông báo gần đây của 1 người (mới nhất trước), kèm avatar người gây ra. */
    function notify_list($user_id, $limit = 20, $offset = 0)
    {
        $uid = (int) $user_id;
        $lim = max(1, (int) $limit);
        $off = max(0, (int) $offset);
        if ($uid <= 0) return [];
        notify_ensure_table();
        $rows = db_fetch_array("SELECT n.id, n.actor_id, n.type, n.title, n.message, n.link, n.is_read, n.created_at,
                                       u.fullname AS actor_fullname, u.username AS actor_username, u.avatar AS actor_avatar
                                FROM notifications n
                                LEFT JOIN tbl_users u ON u.id = n.actor_id
                                WHERE n.user_id = $uid
                                ORDER BY n.created_at DESC, n.id DESC
                                LIMIT $off, $lim") ?: [];
        foreach ($rows as &$r) {
            $av = trim((string) ($r['actor_avatar'] ?? ''));
            $r['actor_avatar'] = $av !== '' ? 'public/images/avatar/' . $av : '';
            $actor_name        = (string) (($r['actor_fullname'] ?? '') ?: ($r['actor_username'] ?? ''));
            // Rút gọn: danh xưng người nhận đặt + tên viết tắt khách hàng.
            $r['actor_name']   = notify_humanize($actor_name, $uid);
            $r['title']        = notify_humanize($r['title'] ?? '', $uid);
            $r['message']      = notify_humanize($r['message'] ?? '', $uid);
            unset($r['actor_fullname'], $r['actor_username']);
        }
        unset($r);
        return $rows;
    }

    /** Tổng số thông báo của 1 người (để biết còn tin cũ hơn để tải). */
    function notify_total_count($user_id)
    {
        $uid = (int) $user_id;
        if ($uid <= 0) return 0;
        notify_ensure_table();
        $row = db_fetch_row("SELECT COUNT(*) AS c FROM notifications WHERE user_id = $uid");
        return $row ? (int) $row['c'] : 0;
    }

    /** Xóa toàn bộ thông báo của 1 người (đồng nghĩa đã xem rồi xóa). */
    function notify_delete_all($user_id)
    {
        $uid = (int) $user_id;
        if ($uid <= 0) return false;
        notify_ensure_table();
        db_delete('notifications', "user_id = $uid");
        return true;
    }

    /** Số thông báo chưa đọc của 1 người. */
    function notify_unread_count($user_id)
    {
        $uid = (int) $user_id;
        if ($uid <= 0) return 0;
        notify_ensure_table();
        $row = db_fetch_row("SELECT COUNT(*) AS c FROM notifications
                             WHERE user_id = $uid AND is_read = 0");
        return $row ? (int) $row['c'] : 0;
    }

    /** Đánh dấu 1 thông báo đã đọc (chỉ của chính chủ). */
    function notify_mark_read($id, $user_id)
    {
        $nid = (int) $id;
        $uid = (int) $user_id;
        if ($nid <= 0 || $uid <= 0) return false;
        notify_ensure_table();
        db_update('notifications', ['is_read' => 1], "id = $nid AND user_id = $uid");
        return true;
    }

    /** Đánh dấu tất cả thông báo của 1 người là đã đọc. */
    function notify_mark_all_read($user_id)
    {
        $uid = (int) $user_id;
        if ($uid <= 0) return false;
        notify_ensure_table();
        db_update('notifications', ['is_read' => 1], "user_id = $uid AND is_read = 0");
        return true;
    }
}
