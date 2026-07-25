<?php
/**
 * todos — "Việc cần làm" cạnh chuông trên app-header.
 *
 * NÂNG CẤP (nhiều danh sách + chia sẻ cộng tác):
 *   - Mỗi user có thể tạo NHIỀU danh sách todo (todo_lists), mỗi danh sách là 1 nhóm task.
 *   - Một danh sách có thể được GÁN (share) cho user khác, kèm quyền can_edit (mặc định TẮT):
 *     người nhận luôn sắp xếp + tích xong được, nhưng chỉ thêm task mới / sửa nội dung nếu
 *     chủ bật "Cho phép thêm, sửa" lúc share hoặc bật sau trong bảng thành viên. Bấm "Nhận"/
 *     "Từ chối" trên chuông; đồng bộ thời gian thực bằng polling.
 *   - Chủ có thể "Gỡ" người nhận (list biến mất bên họ). Người nhận không xóa được list
 *     nhưng có thể "Rời bỏ". Mọi hành động (nhận/từ chối/gỡ/rời) đẩy chuông cho bên kia.
 *
 * Bảng:
 *   - todo_lists         : 1 dòng / 1 danh sách (chủ sở hữu, tên, màu, thứ tự tab).
 *   - todo_list_members  : ai có quyền truy cập 1 danh sách + trạng thái lời mời.
 *   - todo_items         : từng task (thuộc 1 list_id; done_by = ai đánh dấu xong).
 *   - todo_settings      : 1 dòng/user — chế độ tự xóa task hoàn thành (giữ nguyên).
 *
 * Cùng phong cách [[bell-notifications]] notifications.php: file require_once nhiều nơi
 * → mọi hàm bọc guard function_exists, KHÔNG autoload.
 *
 * Gotcha múi giờ (xem chat.php): XAMPP này mặc định Europe/Berlin → set lại VN.
 */

date_default_timezone_set('Asia/Ho_Chi_Minh');

// Mã hóa dữ liệu cá nhân hóa: tên danh sách (todo_lists.title) + nội dung task (todo_items.content).
require_once __DIR__ . '/crypto.php';

if (!function_exists('todo_ensure_tables')) {

    /** Các chế độ tự xóa task đã hoàn thành. */
    function todo_clear_modes()
    {
        return ['manual', '1day', '1week', '30days'];
    }

    /**
     * Bản đồ [user_id => danh xưng] mà người xem ($viewer_id) đặt trong danh bạ chat.
     * Dùng để hiển thị tên người đánh dấu xong theo danh xưng (vd "Em Kim Trợ Lý").
     * Cache theo người xem; an toàn khi chưa cài module chat.
     */
    function todo_viewer_alias_map($viewer_id)
    {
        static $cache = [];
        $vid = (int) $viewer_id;
        if ($vid <= 0) return [];
        if (isset($cache[$vid])) return $cache[$vid];
        $exists = db_fetch_row("SHOW TABLES LIKE 'chat_contact_aliases'");
        if (!$exists) return $cache[$vid] = [];
        $rows = db_fetch_array("SELECT contact_id, alias FROM chat_contact_aliases
                                WHERE owner_id = {$vid} AND alias <> ''") ?: [];
        $map = [];
        foreach ($rows as $r) {
            $alias = trim((string) $r['alias']);
            if ($alias !== '') $map[(int) $r['contact_id']] = $alias;
        }
        return $cache[$vid] = $map;
    }

    /** Tạo bảng + nâng cấp schema + migrate dữ liệu cũ (1 lần / request). */
    function todo_ensure_tables()
    {
        static $done = false;
        if ($done) return;
        $done = true;

        // Danh sách todo (nhóm task). Mỗi list có 1 chủ sở hữu.
        // title dùng TEXT vì lưu DẠNG MÃ HÓA (ciphertext base64 dài hơn bản rõ).
        db_query("CREATE TABLE IF NOT EXISTS todo_lists (
            id          INT(11) NOT NULL AUTO_INCREMENT,
            owner_id    INT(11) NOT NULL,
            title       TEXT NOT NULL,
            color       VARCHAR(20) NOT NULL DEFAULT '',
            sort_order  INT(11) NOT NULL DEFAULT 0,
            created_at  DATETIME NOT NULL,
            PRIMARY KEY (id),
            KEY idx_owner (owner_id, sort_order)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci");

        // Thành viên của 1 danh sách (chủ + người được gán). status quản lý lời mời.
        // role: owner | member ; status: pending | accepted | declined | left | removed
        // can_edit: member có được thêm task mới / sửa nội dung task không (chủ luôn được).
        db_query("CREATE TABLE IF NOT EXISTS todo_list_members (
            id           INT(11) NOT NULL AUTO_INCREMENT,
            list_id      INT(11) NOT NULL,
            user_id      INT(11) NOT NULL,
            role         VARCHAR(10) NOT NULL DEFAULT 'member',
            status       VARCHAR(12) NOT NULL DEFAULT 'pending',
            can_edit     TINYINT(1) NOT NULL DEFAULT 0,
            invited_by   INT(11) NOT NULL DEFAULT 0,
            created_at   DATETIME NOT NULL,
            responded_at DATETIME DEFAULT NULL,
            PRIMARY KEY (id),
            UNIQUE KEY uniq_list_user (list_id, user_id),
            KEY idx_user_status (user_id, status)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci");
        if (!db_fetch_row("SHOW COLUMNS FROM todo_list_members LIKE 'can_edit'")) {
            db_query("ALTER TABLE todo_list_members ADD can_edit TINYINT(1) NOT NULL DEFAULT 0 AFTER status");
        }

        // todo_items: có thể đã tồn tại bản cũ (chỉ user_id). Tạo nếu chưa có.
        db_query("CREATE TABLE IF NOT EXISTS todo_items (
            id          INT(11) NOT NULL AUTO_INCREMENT,
            user_id     INT(11) NOT NULL,
            list_id     INT(11) NOT NULL DEFAULT 0,
            content     TEXT NOT NULL,
            is_done     TINYINT(1) NOT NULL DEFAULT 0,
            done_by     INT(11) NOT NULL DEFAULT 0,
            sort_order  INT(11) NOT NULL DEFAULT 0,
            created_at  DATETIME NOT NULL,
            done_at     DATETIME DEFAULT NULL,
            PRIMARY KEY (id),
            KEY idx_list_order (list_id, sort_order)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci");

        // Bổ sung cột cho bảng todo_items cũ (chỉ chạy nếu thiếu).
        if (!db_fetch_row("SHOW COLUMNS FROM todo_items LIKE 'list_id'")) {
            db_query("ALTER TABLE todo_items ADD list_id INT(11) NOT NULL DEFAULT 0 AFTER user_id");
            db_query("ALTER TABLE todo_items ADD KEY idx_list_order (list_id, sort_order)");
        }
        if (!db_fetch_row("SHOW COLUMNS FROM todo_items LIKE 'done_by'")) {
            db_query("ALTER TABLE todo_items ADD done_by INT(11) NOT NULL DEFAULT 0 AFTER is_done");
        }

        db_query("CREATE TABLE IF NOT EXISTS todo_settings (
            user_id     INT(11) NOT NULL,
            clear_mode  VARCHAR(20) NOT NULL DEFAULT 'manual',
            updated_at  DATETIME NOT NULL,
            PRIMARY KEY (user_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci");

        todo_migrate_legacy_items();
        todo_migrate_encrypt();
    }

    /**
     * Nâng cấp mã hóa: đảm bảo todo_lists.title là TEXT, rồi mã hóa nốt các
     * title/content còn ở dạng rõ. Chỉ ALTER khi cột chưa phải TEXT (1 lần).
     */
    function todo_migrate_encrypt()
    {
        $col = db_fetch_row("SELECT DATA_TYPE FROM information_schema.COLUMNS
                             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'todo_lists' AND COLUMN_NAME = 'title'");
        if ($col && strtolower((string) $col['DATA_TYPE']) !== 'text') {
            db_query("ALTER TABLE todo_lists MODIFY title TEXT NOT NULL");
            // Mã hóa các tên danh sách còn rõ.
            $lists = db_fetch_array("SELECT id, title FROM todo_lists WHERE title NOT LIKE 'enc:v1:%'") ?: [];
            foreach ($lists as $l) {
                db_update('todo_lists', ['title' => crypto_encrypt($l['title'])], 'id = ' . (int) $l['id']);
            }
            // Mã hóa các nội dung task còn rõ.
            $items = db_fetch_array("SELECT id, content FROM todo_items WHERE content NOT LIKE 'enc:v1:%'") ?: [];
            foreach ($items as $it) {
                db_update('todo_items', ['content' => crypto_encrypt($it['content'])], 'id = ' . (int) $it['id']);
            }
        }
    }

    /**
     * Chuyển các task cũ (list_id = 0, từ phiên bản 1-list/user) vào 1 danh sách
     * mặc định "Việc cấp bách" của từng user. Idempotent: chỉ chạm task list_id=0.
     */
    function todo_migrate_legacy_items()
    {
        $orphans = db_fetch_array("SELECT DISTINCT user_id FROM todo_items WHERE list_id = 0");
        if (!$orphans) return;
        $now = date('Y-m-d H:i:s');
        foreach ($orphans as $r) {
            $uid = (int) $r['user_id'];
            if ($uid <= 0) continue;
            $lid = (int) db_insert('todo_lists', [
                'owner_id'   => $uid,
                'title'      => crypto_encrypt('Việc cấp bách'),
                'color'      => '',
                'sort_order' => 0,
                'created_at' => $now,
            ]);
            if ($lid <= 0) continue;
            db_insert('todo_list_members', [
                'list_id'      => $lid,
                'user_id'      => $uid,
                'role'         => 'owner',
                'status'       => 'accepted',
                'invited_by'   => $uid,
                'created_at'   => $now,
                'responded_at' => $now,
            ]);
            db_query("UPDATE todo_items SET list_id = $lid WHERE user_id = $uid AND list_id = 0");
        }
    }

    /* ----------------------------------------------------------------------
     *  DANH SÁCH (todo_lists) + THÀNH VIÊN (todo_list_members)
     * -------------------------------------------------------------------- */

    /** Đảm bảo user có ít nhất 1 danh sách (sở hữu). Trả id danh sách đầu tiên của họ. */
    function todo_default_list_id($user_id)
    {
        $uid = (int) $user_id;
        if ($uid <= 0) return 0;
        todo_ensure_tables();
        $row = db_fetch_row("SELECT id FROM todo_lists WHERE owner_id = $uid ORDER BY sort_order ASC, id ASC LIMIT 1");
        if ($row) return (int) $row['id'];
        $lid = todo_list_create($uid, 'Việc cấp bách');
        return (int) $lid;
    }

    /** Vai trò hiệu lực của user trong 1 danh sách: 'owner' | 'member' | null (không truy cập được). */
    function todo_user_role_in_list($user_id, $list_id)
    {
        $uid = (int) $user_id;
        $lid = (int) $list_id;
        if ($uid <= 0 || $lid <= 0) return null;
        todo_ensure_tables();
        $row = db_fetch_row("SELECT role, status FROM todo_list_members
                             WHERE list_id = $lid AND user_id = $uid LIMIT 1");
        if (!$row) {
            // Phòng hờ: chủ list nhưng thiếu dòng member (dữ liệu lệch) → vẫn là owner.
            $own = db_fetch_row("SELECT id FROM todo_lists WHERE id = $lid AND owner_id = $uid");
            return $own ? 'owner' : null;
        }
        if ($row['role'] === 'owner') return 'owner';
        return ($row['status'] === 'accepted') ? 'member' : null;
    }

    /** User có quyền thao tác task trong danh sách? (owner hoặc member đã nhận). */
    function todo_can_access($user_id, $list_id)
    {
        return todo_user_role_in_list($user_id, $list_id) !== null;
    }

    /** User có quyền thêm task mới / sửa nội dung task? (owner luôn được; member chỉ khi can_edit=1). */
    function todo_can_edit_content($user_id, $list_id)
    {
        $uid = (int) $user_id;
        $lid = (int) $list_id;
        if ($uid <= 0 || $lid <= 0) return false;
        todo_ensure_tables();
        $row = db_fetch_row("SELECT role, status, can_edit FROM todo_list_members
                             WHERE list_id = $lid AND user_id = $uid LIMIT 1");
        if (!$row) {
            $own = db_fetch_row("SELECT id FROM todo_lists WHERE id = $lid AND owner_id = $uid");
            return (bool) $own;
        }
        if ($row['role'] === 'owner') return true;
        return $row['status'] === 'accepted' && (int) $row['can_edit'] === 1;
    }

    /**
     * Các danh sách hiển thị trên tab của user: sở hữu + đã nhận (accepted).
     * Mỗi phần tử: id, title, color, role, owner_id, owner_name, member_count, pending_count.
     */
    function todo_user_lists($user_id)
    {
        $uid = (int) $user_id;
        if ($uid <= 0) return [];
        todo_ensure_tables();
        $rows = db_fetch_array("
            SELECT l.id, l.title, l.color, l.owner_id, l.created_at,
                   m.role AS my_role, m.can_edit AS my_can_edit, m.created_at AS joined_at,
                   o.fullname AS owner_fullname, o.username AS owner_username
            FROM todo_list_members m
            JOIN todo_lists l ON l.id = m.list_id
            LEFT JOIN tbl_users o ON o.id = l.owner_id
            WHERE m.user_id = $uid
              AND (m.role = 'owner' OR m.status = 'accepted')
            ORDER BY (m.role = 'owner') DESC, l.sort_order ASC, l.id ASC
        ") ?: [];

        // Danh xưng người xem ($uid) đặt trong danh bạ chat cho chủ danh sách (vd "Anh 6 QL").
        $alias_map = todo_viewer_alias_map($uid);
        foreach ($rows as &$r) {
            $lid = (int) $r['id'];
            $r['title']   = crypto_decrypt($r['title']);
            $r['role']    = $r['my_role'];
            $r['is_owner'] = ($r['my_role'] === 'owner');
            $r['can_edit'] = $r['is_owner'] || ((int) ($r['my_can_edit'] ?? 0) === 1);
            $real_owner_name = (string) (($r['owner_fullname'] ?? '') ?: ($r['owner_username'] ?? ''));
            $r['owner_name'] = $alias_map[(int) $r['owner_id']] ?? $real_owner_name;
            unset($r['my_can_edit']);
            // Số người đã nhận (gồm chủ) — để chủ biết list đang chia sẻ tới mấy người.
            $c = db_fetch_row("SELECT COUNT(*) AS c FROM todo_list_members
                               WHERE list_id = $lid AND (role = 'owner' OR status = 'accepted')");
            $r['member_count'] = $c ? (int) $c['c'] : 1;
            // Số người được mời còn chờ phản hồi (pending) — chỉ ý nghĩa với chủ.
            $p = db_fetch_row("SELECT COUNT(*) AS c FROM todo_list_members
                               WHERE list_id = $lid AND status = 'pending'");
            $r['pending_count'] = $p ? (int) $p['c'] : 0;
            // Số task chưa xong trong list.
            $t = db_fetch_row("SELECT COUNT(*) AS c FROM todo_items WHERE list_id = $lid AND is_done = 0");
            $r['open_count'] = $t ? (int) $t['c'] : 0;
            unset($r['my_role'], $r['owner_fullname'], $r['owner_username']);
        }
        unset($r);
        return $rows;
    }

    /** 1 danh sách theo id (kèm tên chủ). */
    function todo_list_get($list_id)
    {
        $lid = (int) $list_id;
        if ($lid <= 0) return null;
        todo_ensure_tables();
        $row = db_fetch_row("
            SELECT l.*, o.fullname AS owner_fullname, o.username AS owner_username
            FROM todo_lists l LEFT JOIN tbl_users o ON o.id = l.owner_id
            WHERE l.id = $lid");
        if ($row && isset($row['title'])) $row['title'] = crypto_decrypt($row['title']);
        return $row;
    }

    /** Tạo danh sách mới (user thành chủ sở hữu). Trả id list vừa tạo (0 nếu lỗi). */
    function todo_list_create($user_id, $title)
    {
        $uid = (int) $user_id;
        $title = trim((string) $title);
        if ($uid <= 0) return 0;
        if ($title === '') $title = 'Danh sách mới';
        if (mb_strlen($title, 'UTF-8') > 120) $title = mb_substr($title, 0, 120, 'UTF-8');
        todo_ensure_tables();
        $row = db_fetch_row("SELECT MAX(sort_order) AS m FROM todo_lists WHERE owner_id = $uid");
        $order = ($row && $row['m'] !== null) ? ((int) $row['m'] + 1) : 0;
        $now = date('Y-m-d H:i:s');
        $lid = (int) db_insert('todo_lists', [
            'owner_id'   => $uid,
            'title'      => crypto_encrypt($title),
            'color'      => '',
            'sort_order' => $order,
            'created_at' => $now,
        ]);
        if ($lid <= 0) return 0;
        db_insert('todo_list_members', [
            'list_id'      => $lid,
            'user_id'      => $uid,
            'role'         => 'owner',
            'status'       => 'accepted',
            'invited_by'   => $uid,
            'created_at'   => $now,
            'responded_at' => $now,
        ]);
        return $lid;
    }

    /** Đổi tên danh sách (chỉ chủ). */
    function todo_list_rename($user_id, $list_id, $title)
    {
        $uid = (int) $user_id;
        $lid = (int) $list_id;
        $title = trim((string) $title);
        if ($uid <= 0 || $lid <= 0 || $title === '') return false;
        if (mb_strlen($title, 'UTF-8') > 120) $title = mb_substr($title, 0, 120, 'UTF-8');
        todo_ensure_tables();
        $own = db_fetch_row("SELECT id FROM todo_lists WHERE id = $lid AND owner_id = $uid");
        if (!$own) return false;
        db_update('todo_lists', ['title' => crypto_encrypt($title)], "id = $lid");
        return true;
    }

    /** Xóa danh sách (chỉ chủ) + toàn bộ task + thành viên. Trả mảng uid thành viên (để báo chuông). */
    function todo_list_delete($user_id, $list_id)
    {
        $uid = (int) $user_id;
        $lid = (int) $list_id;
        if ($uid <= 0 || $lid <= 0) return null;
        todo_ensure_tables();
        $own = db_fetch_row("SELECT id FROM todo_lists WHERE id = $lid AND owner_id = $uid");
        if (!$own) return null;
        $members = todo_list_member_uids($lid, ['accepted', 'pending']);
        $members = array_values(array_filter($members, function ($m) use ($uid) { return $m !== $uid; }));
        db_delete('todo_items', "list_id = $lid");
        db_delete('todo_list_members', "list_id = $lid");
        db_delete('todo_lists', "id = $lid");
        return $members;
    }

    /** Mảng uid thành viên của 1 list theo trạng thái. */
    function todo_list_member_uids($list_id, $statuses = ['accepted'])
    {
        $lid = (int) $list_id;
        if ($lid <= 0) return [];
        $in = implode(',', array_map(function ($s) { return "'" . escape_string($s) . "'"; }, $statuses ?: ['accepted']));
        $rows = db_fetch_array("SELECT user_id FROM todo_list_members
                                WHERE list_id = $lid AND status IN ($in)");
        return array_map(function ($r) { return (int) $r['user_id']; }, $rows ?: []);
    }

    /**
     * Bảng thành viên đầy đủ cho panel của chủ (mọi trạng thái trừ owner).
     * Mỗi phần tử: user_id, name, username, avatar, status.
     * Tên hiển thị theo danh xưng người xem ($viewer_id) đặt (vd "Em Kim Trợ Lý").
     */
    function todo_list_members($list_id, $viewer_id = 0)
    {
        $lid = (int) $list_id;
        if ($lid <= 0) return [];
        todo_ensure_tables();
        $rows = db_fetch_array("
            SELECT m.user_id, m.status, m.role, m.can_edit,
                   u.fullname, u.username, u.avatar
            FROM todo_list_members m
            LEFT JOIN tbl_users u ON u.id = m.user_id
            WHERE m.list_id = $lid AND m.role <> 'owner'
            ORDER BY FIELD(m.status,'accepted','pending','declined','left','removed'), m.id ASC
        ") ?: [];
        $alias_map = todo_viewer_alias_map($viewer_id);
        foreach ($rows as &$r) {
            $av = trim((string) ($r['avatar'] ?? ''));
            $r['avatar'] = $av !== '' ? 'public/images/avatar/' . $av : '';
            $real = (string) (($r['fullname'] ?? '') ?: ($r['username'] ?? ''));
            $r['name'] = $alias_map[(int) $r['user_id']] ?? $real;
            $r['can_edit'] = (int) $r['can_edit'] === 1;
            unset($r['fullname']);
        }
        unset($r);
        return $rows;
    }

    /**
     * Danh sách user có thể gán cho 1 list (mọi user trừ chủ), kèm trạng thái hiện tại.
     * status: '' (chưa gán) | pending | accepted | declined | left | removed.
     */
    function todo_assignable_users($user_id, $list_id, $keyword = '')
    {
        $uid = (int) $user_id;
        $lid = (int) $list_id;
        if ($uid <= 0 || $lid <= 0) return [];
        todo_ensure_tables();
        $where = "u.id <> $uid";
        $kw = trim((string) $keyword);
        if ($kw !== '') {
            $k = escape_string($kw);
            $where .= " AND (u.fullname LIKE '%$k%' OR u.username LIKE '%$k%')";
        }
        $rows = db_fetch_array("
            SELECT u.id AS user_id, u.fullname, u.username, u.avatar,
                   m.status
            FROM tbl_users u
            LEFT JOIN todo_list_members m ON m.list_id = $lid AND m.user_id = u.id
            WHERE $where
            ORDER BY u.fullname ASC, u.username ASC
            LIMIT 100
        ") ?: [];
        $alias_map = todo_viewer_alias_map($uid);
        foreach ($rows as &$r) {
            $av = trim((string) ($r['avatar'] ?? ''));
            $r['avatar'] = $av !== '' ? 'public/images/avatar/' . $av : '';
            $real = (string) (($r['fullname'] ?? '') ?: ($r['username'] ?? ''));
            $r['name']   = $alias_map[(int) $r['user_id']] ?? $real;
            $r['status'] = (string) ($r['status'] ?? '');
            unset($r['fullname']);
        }
        unset($r);
        return $rows;
    }

    /**
     * Chủ gán (gửi) 1 user vào danh sách → tạo/cập nhật dòng member status='pending'.
     * $can_edit: có cho phép người nhận thêm task mới / sửa nội dung không (mặc định KHÔNG).
     * Trả mảng ['ok'=>bool,'message'=>...] để controller đẩy chuông.
     */
    function todo_list_share($owner_id, $list_id, $target_uid, $can_edit = false)
    {
        $oid = (int) $owner_id;
        $lid = (int) $list_id;
        $tid = (int) $target_uid;
        if ($oid <= 0 || $lid <= 0 || $tid <= 0) return ['ok' => false, 'message' => 'Thiếu thông tin.'];
        if ($tid === $oid) return ['ok' => false, 'message' => 'Không thể gán cho chính mình.'];
        todo_ensure_tables();
        $own = db_fetch_row("SELECT id FROM todo_lists WHERE id = $lid AND owner_id = $oid");
        if (!$own) return ['ok' => false, 'message' => 'Bạn không phải chủ danh sách.'];
        $now = date('Y-m-d H:i:s');
        $canEditVal = $can_edit ? 1 : 0;
        $exists = db_fetch_row("SELECT id, status FROM todo_list_members WHERE list_id = $lid AND user_id = $tid");
        if ($exists) {
            if ($exists['status'] === 'accepted') {
                return ['ok' => false, 'message' => 'Người này đã nhận danh sách.'];
            }
            db_update('todo_list_members', [
                'status'       => 'pending',
                'can_edit'     => $canEditVal,
                'invited_by'   => $oid,
                'responded_at' => null,
                'created_at'   => $now,
            ], "id = " . (int) $exists['id']);
        } else {
            db_insert('todo_list_members', [
                'list_id'      => $lid,
                'user_id'      => $tid,
                'role'         => 'member',
                'status'       => 'pending',
                'can_edit'     => $canEditVal,
                'invited_by'   => $oid,
                'created_at'   => $now,
                'responded_at' => null,
            ]);
        }
        return ['ok' => true];
    }

    /** Chủ bật/tắt quyền thêm-sửa cho 1 người nhận đã có trong danh sách (không cần re-share). */
    function todo_set_member_can_edit($owner_id, $list_id, $target_uid, $can_edit)
    {
        $oid = (int) $owner_id;
        $lid = (int) $list_id;
        $tid = (int) $target_uid;
        if ($oid <= 0 || $lid <= 0 || $tid <= 0) return false;
        todo_ensure_tables();
        $own = db_fetch_row("SELECT id FROM todo_lists WHERE id = $lid AND owner_id = $oid");
        if (!$own) return false;
        db_update('todo_list_members', ['can_edit' => $can_edit ? 1 : 0],
            "list_id = $lid AND user_id = $tid AND role <> 'owner'");
        return true;
    }

    /** Chủ gỡ 1 người nhận khỏi danh sách (xóa dòng member). Trả true nếu gỡ được. */
    function todo_list_unshare($owner_id, $list_id, $target_uid)
    {
        $oid = (int) $owner_id;
        $lid = (int) $list_id;
        $tid = (int) $target_uid;
        if ($oid <= 0 || $lid <= 0 || $tid <= 0) return false;
        todo_ensure_tables();
        $own = db_fetch_row("SELECT id FROM todo_lists WHERE id = $lid AND owner_id = $oid");
        if (!$own) return false;
        db_delete('todo_list_members', "list_id = $lid AND user_id = $tid AND role <> 'owner'");
        return true;
    }

    /** Người được mời phản hồi: accept / decline. Trả true nếu có dòng pending hợp lệ. */
    function todo_list_respond($user_id, $list_id, $accept)
    {
        $uid = (int) $user_id;
        $lid = (int) $list_id;
        if ($uid <= 0 || $lid <= 0) return false;
        todo_ensure_tables();
        $row = db_fetch_row("SELECT id, status FROM todo_list_members
                             WHERE list_id = $lid AND user_id = $uid AND role = 'member'");
        if (!$row) return false;
        // Cho phép nhận lại nếu trước đó đã từ chối/rời, miễn vẫn còn dòng (chủ chưa gỡ).
        db_update('todo_list_members', [
            'status'       => $accept ? 'accepted' : 'declined',
            'responded_at' => date('Y-m-d H:i:s'),
        ], "id = " . (int) $row['id']);
        return true;
    }

    /** Người nhận rời bỏ danh sách (status='left'). Trả true nếu họ là member. */
    function todo_list_leave($user_id, $list_id)
    {
        $uid = (int) $user_id;
        $lid = (int) $list_id;
        if ($uid <= 0 || $lid <= 0) return false;
        todo_ensure_tables();
        $row = db_fetch_row("SELECT id FROM todo_list_members
                             WHERE list_id = $lid AND user_id = $uid AND role = 'member'");
        if (!$row) return false;
        db_update('todo_list_members', [
            'status'       => 'left',
            'responded_at' => date('Y-m-d H:i:s'),
        ], "id = " . (int) $row['id']);
        return true;
    }

    /* ----------------------------------------------------------------------
     *  TASK (todo_items) — đều thuộc 1 list_id, guard bằng quyền truy cập.
     * -------------------------------------------------------------------- */

    /** Áp dụng tự xóa: xóa task đã hoàn thành quá hạn trong các list user SỞ HỮU. */
    function todo_apply_auto_clear($user_id)
    {
        $uid = (int) $user_id;
        if ($uid <= 0) return;
        $mode = todo_get_clear_mode($uid);
        $map = ['1day' => 'INTERVAL 1 DAY', '1week' => 'INTERVAL 7 DAY', '30days' => 'INTERVAL 30 DAY'];
        if (!isset($map[$mode])) return;
        db_query("DELETE i FROM todo_items i
                  JOIN todo_lists l ON l.id = i.list_id
                  WHERE l.owner_id = $uid AND i.is_done = 1
                    AND i.done_at IS NOT NULL
                    AND i.done_at < (NOW() - {$map[$mode]})");
    }

    /** Task của 1 danh sách (kèm tên người đánh dấu xong). Kiểm tra quyền trước. */
    function todo_list_items($user_id, $list_id)
    {
        $uid = (int) $user_id;
        $lid = (int) $list_id;
        if (!todo_can_access($uid, $lid)) return [];
        todo_apply_auto_clear($uid);
        $rows = db_fetch_array("
            SELECT i.id, i.list_id, i.content, i.is_done, i.done_by, i.sort_order, i.created_at, i.done_at,
                   d.fullname AS done_fullname, d.username AS done_username
            FROM todo_items i
            LEFT JOIN tbl_users d ON d.id = i.done_by
            WHERE i.list_id = $lid
            ORDER BY i.sort_order ASC, i.id ASC
        ") ?: [];
        // Danh xưng người xem ($uid) đặt cho người đánh dấu xong (rút gọn tên hiển thị).
        $alias_map = todo_viewer_alias_map($uid);
        foreach ($rows as &$r) {
            if ((int) $r['done_by'] > 0) {
                $real = (string) (($r['done_fullname'] ?? '') ?: ($r['done_username'] ?? ''));
                $r['done_by_name'] = $alias_map[(int) $r['done_by']] ?? $real;
            } else {
                $r['done_by_name'] = '';
            }
            $r['content'] = crypto_decrypt($r['content']);
            unset($r['done_fullname'], $r['done_username']);
        }
        unset($r);
        return $rows;
    }

    /** Tổng số task chưa xong trên MỌI danh sách user truy cập được (cho badge). */
    function todo_pending_count($user_id)
    {
        $uid = (int) $user_id;
        if ($uid <= 0) return 0;
        todo_ensure_tables();
        $row = db_fetch_row("
            SELECT COUNT(*) AS c
            FROM todo_items i
            JOIN todo_list_members m ON m.list_id = i.list_id
            WHERE m.user_id = $uid AND (m.role = 'owner' OR m.status = 'accepted')
              AND i.is_done = 0");
        return $row ? (int) $row['c'] : 0;
    }

    /** Thêm 1 task vào 1 danh sách (đẩy lên đầu). Trả bản ghi vừa tạo (kèm done_by_name). */
    function todo_create($user_id, $list_id, $content)
    {
        $uid = (int) $user_id;
        $lid = (int) $list_id;
        $content = trim((string) $content);
        if ($content === '' || !todo_can_edit_content($uid, $lid)) return null;
        if (mb_strlen($content, 'UTF-8') > 500) $content = mb_substr($content, 0, 500, 'UTF-8');
        $row = db_fetch_row("SELECT MIN(sort_order) AS m FROM todo_items WHERE list_id = $lid");
        $order = ($row && $row['m'] !== null) ? ((int) $row['m'] - 1) : 0;
        $now = date('Y-m-d H:i:s');
        $id = (int) db_insert('todo_items', [
            'user_id'    => $uid,
            'list_id'    => $lid,
            'content'    => crypto_encrypt($content),
            'is_done'    => 0,
            'done_by'    => 0,
            'sort_order' => $order,
            'created_at' => $now,
            'done_at'    => null,
        ]);
        if ($id <= 0) return null;
        $item = db_fetch_row("SELECT id, list_id, content, is_done, done_by, sort_order, created_at, done_at
                              FROM todo_items WHERE id = $id");
        if ($item) {
            $item['content']     = crypto_decrypt($item['content']);
            $item['done_by_name'] = '';
        }
        return $item;
    }

    /** Sửa nội dung task (bất kỳ thành viên có quyền). */
    function todo_update_content($user_id, $list_id, $id, $content)
    {
        $uid = (int) $user_id;
        $lid = (int) $list_id;
        $tid = (int) $id;
        $content = trim((string) $content);
        if ($tid <= 0 || $content === '' || !todo_can_edit_content($uid, $lid)) return false;
        if (mb_strlen($content, 'UTF-8') > 500) $content = mb_substr($content, 0, 500, 'UTF-8');
        db_update('todo_items', ['content' => crypto_encrypt($content)], "id = $tid AND list_id = $lid");
        return true;
    }

    /** Đánh dấu xong / chưa xong; lưu done_by = người vừa thao tác. */
    function todo_set_done($user_id, $list_id, $id, $done)
    {
        $uid = (int) $user_id;
        $lid = (int) $list_id;
        $tid = (int) $id;
        if ($tid <= 0 || !todo_can_access($uid, $lid)) return false;
        $done = $done ? 1 : 0;
        db_update('todo_items', [
            'is_done' => $done,
            'done_by' => $done ? $uid : 0,
            'done_at' => $done ? date('Y-m-d H:i:s') : null,
        ], "id = $tid AND list_id = $lid");
        return true;
    }

    /** Xóa 1 task — CHỈ chủ danh sách (người nhận không được xóa task). */
    function todo_delete($user_id, $list_id, $id)
    {
        $uid = (int) $user_id;
        $lid = (int) $list_id;
        $tid = (int) $id;
        if ($tid <= 0 || todo_user_role_in_list($uid, $lid) !== 'owner') return false;
        db_delete('todo_items', "id = $tid AND list_id = $lid");
        return true;
    }

    /** Xóa task đã hoàn thành — CHỈ chủ danh sách. */
    function todo_clear_done($user_id, $list_id)
    {
        $uid = (int) $user_id;
        $lid = (int) $list_id;
        if (todo_user_role_in_list($uid, $lid) !== 'owner') return false;
        db_delete('todo_items', "list_id = $lid AND is_done = 1");
        return true;
    }

    /** Xóa TẤT CẢ task trong 1 danh sách (không xóa danh sách) — CHỈ chủ. */
    function todo_clear_all($user_id, $list_id)
    {
        $uid = (int) $user_id;
        $lid = (int) $list_id;
        if (todo_user_role_in_list($uid, $lid) !== 'owner') return false;
        db_delete('todo_items', "list_id = $lid");
        return true;
    }

    /** Sắp xếp lại task trong 1 danh sách theo mảng id (kéo-thả kiểu Trello). */
    function todo_reorder($user_id, $list_id, $ids)
    {
        $uid = (int) $user_id;
        $lid = (int) $list_id;
        if (!is_array($ids) || !todo_can_access($uid, $lid)) return false;
        $order = 0;
        foreach ($ids as $id) {
            $tid = (int) $id;
            if ($tid <= 0) continue;
            db_update('todo_items', ['sort_order' => $order], "id = $tid AND list_id = $lid");
            $order++;
        }
        return true;
    }

    /* ----------------------------------------------------------------------
     *  CÀI ĐẶT tự xóa (per-user) — giữ nguyên hành vi cũ.
     * -------------------------------------------------------------------- */

    /** Lấy chế độ tự xóa của 1 user (mặc định 'manual'). */
    function todo_get_clear_mode($user_id)
    {
        $uid = (int) $user_id;
        if ($uid <= 0) return 'manual';
        todo_ensure_tables();
        $row = db_fetch_row("SELECT clear_mode FROM todo_settings WHERE user_id = $uid");
        $mode = $row ? (string) $row['clear_mode'] : 'manual';
        return in_array($mode, todo_clear_modes(), true) ? $mode : 'manual';
    }

    /** Lưu chế độ tự xóa cho 1 user (upsert). */
    function todo_set_clear_mode($user_id, $mode)
    {
        $uid = (int) $user_id;
        if ($uid <= 0) return false;
        if (!in_array($mode, todo_clear_modes(), true)) $mode = 'manual';
        todo_ensure_tables();
        $now = date('Y-m-d H:i:s');
        $exists = db_fetch_row("SELECT user_id FROM todo_settings WHERE user_id = $uid");
        if ($exists) {
            db_update('todo_settings', ['clear_mode' => $mode, 'updated_at' => $now], "user_id = $uid");
        } else {
            db_insert('todo_settings', ['user_id' => $uid, 'clear_mode' => $mode, 'updated_at' => $now]);
        }
        return true;
    }
}
