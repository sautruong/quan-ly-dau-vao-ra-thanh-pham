<?php
/**
 * =====================================================================
 *  Sự kiện hằng ngày (daily_events) — thư viện dùng chung
 * =====================================================================
 *  3 loại "cụm" sự kiện, cùng nằm trong 1 bảng de_items (item_type phân
 *  biệt), mỗi cụm có thể có nhiều bình luận (de_comments) + ảnh dẫn
 *  chứng (de_attachments, dùng chung cho cả item lẫn comment) + được
 *  chia sẻ cho user khác xem/bình luận (de_shares).
 *
 *  CÁ NHÂN HÓA: mặc định 1 sự kiện chỉ chủ sở hữu (created_by) mới thấy.
 *  User khác CHỈ thấy khi được chia sẻ (có dòng trong de_shares) — xem
 *  de_can_view_item()/de_item_list(). Admin luôn thấy tất cả. Chỉ chủ sở
 *  hữu hoặc admin mới được thêm/gỡ chia sẻ (de_share_add/de_share_remove).
 *
 *  - feedback (Phản hồi khách hàng): product_id/product_name + description
 *    (mô tả tự động theo mẫu "Phản hồi {tên SP}", soạn ở JS).
 *  - sample_received (Nhận mẫu): party_name = người gửi, sample_name, description.
 *  - sample_sent (Gửi mẫu): party_name = người nhận, sample_name, description.
 *
 *  Bình luận có thể "thu hồi" (xóa hẳn) trong 5 giây đầu sau khi gửi
 *  (de_comment_can_recall/de_comment_delete) — sau 5s, chỉ admin xóa được.
 *
 *  Prefix hàm: de_*
 * =====================================================================
 */

// Mốc thời gian phải theo giờ VN để cửa sổ "thu hồi 5s" tính đúng.
if (date_default_timezone_get() !== 'Asia/Ho_Chi_Minh') {
    date_default_timezone_set('Asia/Ho_Chi_Minh');
}

if (!function_exists('de_ensure_tables')) {

    function de_ensure_tables()
    {
        static $done = false;
        if ($done) return;
        $done = true;

        db_query("CREATE TABLE IF NOT EXISTS de_items (
            id           INT(11) NOT NULL AUTO_INCREMENT,
            item_type    VARCHAR(20) NOT NULL,
            event_date   DATE NOT NULL,
            product_id   INT(11) DEFAULT NULL,
            product_name VARCHAR(255) DEFAULT NULL,
            party_name   VARCHAR(255) DEFAULT NULL,
            sample_name  VARCHAR(255) DEFAULT NULL,
            description  TEXT DEFAULT NULL,
            created_by   INT(11) NOT NULL DEFAULT 0,
            created_at   DATETIME NOT NULL,
            updated_at   DATETIME DEFAULT NULL,
            PRIMARY KEY (id),
            KEY idx_type_date (item_type, event_date)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci");

        db_query("CREATE TABLE IF NOT EXISTS de_comments (
            id          INT(11) NOT NULL AUTO_INCREMENT,
            item_id     INT(11) NOT NULL,
            content     TEXT DEFAULT NULL,
            created_by  INT(11) NOT NULL DEFAULT 0,
            created_at  DATETIME NOT NULL,
            PRIMARY KEY (id),
            KEY idx_item (item_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci");

        db_query("CREATE TABLE IF NOT EXISTS de_attachments (
            id          INT(11) NOT NULL AUTO_INCREMENT,
            owner_type  VARCHAR(10) NOT NULL,
            owner_id    INT(11) NOT NULL,
            file_url    VARCHAR(255) NOT NULL,
            created_at  DATETIME NOT NULL,
            PRIMARY KEY (id),
            KEY idx_owner (owner_type, owner_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci");

        db_query("CREATE TABLE IF NOT EXISTS de_shares (
            id          INT(11) NOT NULL AUTO_INCREMENT,
            item_id     INT(11) NOT NULL,
            shared_with INT(11) NOT NULL,
            shared_by   INT(11) NOT NULL DEFAULT 0,
            created_at  DATETIME NOT NULL,
            PRIMARY KEY (id),
            UNIQUE KEY uniq_share (item_id, shared_with)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci");
    }

    /** Đăng ký view vào menu "Tiện ích" (idempotent, tự sửa nếu đã lỡ đăng ký
     *  nhãn/nhóm cũ "Sự kiện hằng ngày"/"Sự kiện" ở phiên bản trước — view này
     *  giờ là "Thảo luận", 1 bức Tường kiểu Facebook, xem libraries/daily_events_wall.php). */
    function de_ensure_view_registered()
    {
        if (db_num_rows("SHOW TABLES LIKE 'tbl_views'") <= 0) return;
        db_query("INSERT IGNORE INTO tbl_views (module, controller, action, label, group_label, sort)
                  VALUES ('daily_events','daily_events','daily_events','Thảo luận','Tiện ích', 122)");
        db_query("UPDATE tbl_views SET group_label = 'Tiện ích', label = 'Thảo luận', sort = 122
                  WHERE module = 'daily_events' AND controller = 'daily_events' AND action = 'daily_events'");
    }

    define('DE_TYPES', ['feedback', 'sample_received', 'sample_sent']);
    define('DE_ALLOWED_IMG_EXT', 'jpg,jpeg,png,gif,webp');
    define('DE_PAGE_SIZE', 10);

    /* ============================================================
     *  Người dùng hiện tại / danh sách user (picker chia sẻ)
     * ============================================================ */
    function de_current_user()
    {
        if (!function_exists('permission_current_user')) return null;
        return permission_current_user();
    }

    function de_search_users($keyword, $me_id)
    {
        $kw  = trim((string) $keyword);
        $me  = (int) $me_id;
        $where = "id <> $me AND (status IS NULL OR status <> 'blocked')";
        if ($kw !== '') {
            $k = escape_string($kw);
            $where .= " AND (fullname LIKE '%$k%' OR username LIKE '%$k%')";
        }
        $rows = db_fetch_array(
            "SELECT id, fullname, username FROM tbl_users WHERE $where ORDER BY fullname, username LIMIT 10"
        ) ?: [];
        $out = [];
        foreach ($rows as $r) {
            $out[] = [
                'id'       => (int) $r['id'],
                'fullname' => de_display_name_for($r['id'], $me) ?: (string) (($r['fullname'] ?? '') ?: $r['username']),
                'username' => (string) $r['username'],
            ];
        }
        return $out;
    }

    /* ============================================================
     *  Tìm sản phẩm theo tên thường gọi (COALESCE common_product_name)
     * ============================================================ */
    function de_search_products($keyword)
    {
        $kw = trim((string) $keyword);
        $k  = escape_string($kw);
        $where = $kw === '' ? '' : "WHERE (common_product_name LIKE '%$k%' OR product_name LIKE '%$k%')";
        $rows = db_fetch_array(
            "SELECT id, COALESCE(NULLIF(common_product_name, ''), product_name) AS display_name
             FROM products $where ORDER BY display_name ASC LIMIT 15"
        ) ?: [];
        $out = [];
        foreach ($rows as $r) {
            $out[] = ['id' => (int) $r['id'], 'name' => (string) $r['display_name']];
        }
        return $out;
    }

    /* ============================================================
     *  Ảnh dẫn chứng (dùng chung item + comment)
     * ============================================================ */
    function de_storage_dir()
    {
        $rel = 'public' . DIRECTORY_SEPARATOR . 'uploads' . DIRECTORY_SEPARATOR . 'daily_events';
        $abs = APPPATH . DIRECTORY_SEPARATOR . $rel;
        if (!is_dir($abs)) @mkdir($abs, 0777, true);
        return $abs;
    }

    function de_normalize_files($files)
    {
        $out = [];
        if (!is_array($files) || !isset($files['name'])) return $out;
        if (is_array($files['name'])) {
            $n = count($files['name']);
            for ($i = 0; $i < $n; $i++) {
                $out[] = [
                    'name'     => $files['name'][$i] ?? '',
                    'type'     => $files['type'][$i] ?? '',
                    'tmp_name' => $files['tmp_name'][$i] ?? '',
                    'error'    => $files['error'][$i] ?? UPLOAD_ERR_NO_FILE,
                    'size'     => $files['size'][$i] ?? 0,
                ];
            }
        } else {
            $out[] = $files;
        }
        return $out;
    }

    /** $owner_type: 'item' | 'comment'. */
    function de_save_images($owner_type, $owner_id, $files)
    {
        $owner_id = (int) $owner_id;
        $owner_type = in_array($owner_type, ['item', 'comment'], true) ? $owner_type : 'item';
        if ($owner_id <= 0) return ['ok' => false, 'saved' => [], 'errors' => ['Thiếu id.']];

        $allowed = explode(',', DE_ALLOWED_IMG_EXT);
        $abs_dir = de_storage_dir();
        $rel_web = 'public/uploads/daily_events';

        $saved = [];
        $errors = [];
        foreach (de_normalize_files($files) as $f) {
            if (($f['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) continue;
            if (($f['error'] ?? 1) !== UPLOAD_ERR_OK) { $errors[] = 'Tải ảnh thất bại: ' . ($f['name'] ?? ''); continue; }
            $ext = strtolower(pathinfo($f['name'], PATHINFO_EXTENSION));
            if (!in_array($ext, $allowed, true)) { $errors[] = 'Định dạng không hợp lệ: ' . $f['name']; continue; }

            $base     = preg_replace('/[^A-Za-z0-9_\-]/', '_', pathinfo($f['name'], PATHINFO_FILENAME));
            $base     = $base !== '' ? $base : 'de';
            $unique   = $owner_type . $owner_id . '_' . time() . '_' . substr(md5($f['name'] . mt_rand()), 0, 6);
            $filename = $unique . '_' . $base . '.' . $ext;
            $abs_path = $abs_dir . DIRECTORY_SEPARATOR . $filename;

            $moved = is_uploaded_file($f['tmp_name'])
                ? move_uploaded_file($f['tmp_name'], $abs_path)
                : @rename($f['tmp_name'], $abs_path);
            if (!$moved) { $errors[] = 'Không thể lưu ảnh: ' . $f['name']; continue; }

            $file_url = $rel_web . '/' . $filename;
            $id = (int) db_insert('de_attachments', [
                'owner_type' => $owner_type,
                'owner_id'   => $owner_id,
                'file_url'   => $file_url,
                'created_at' => date('Y-m-d H:i:s'),
            ]);
            $saved[] = ['id' => $id, 'file_url' => $file_url];
        }
        return ['ok' => empty($errors) || !empty($saved), 'saved' => $saved, 'errors' => $errors];
    }

    function de_list_images($owner_type, $owner_id)
    {
        $owner_id = (int) $owner_id;
        $ot = escape_string($owner_type);
        return db_fetch_array(
            "SELECT id, file_url FROM de_attachments
             WHERE owner_type = '$ot' AND owner_id = $owner_id ORDER BY id ASC"
        ) ?: [];
    }

    /** Chủ sự kiện (owner_type='item') hoặc chủ bình luận (owner_type='comment') — hoặc admin. */
    function de_can_manage_owner($owner_type, $owner_id, $user_id, $is_admin)
    {
        if ($is_admin) return true;
        $owner_id = (int) $owner_id;
        if ($owner_type === 'item') {
            $item = de_item_get($owner_id);
            return $item && (int) $item['created_by'] === (int) $user_id;
        }
        if ($owner_type === 'comment') {
            $c = db_fetch_row("SELECT created_by FROM de_comments WHERE id = $owner_id LIMIT 1");
            return $c && (int) $c['created_by'] === (int) $user_id;
        }
        return false;
    }

    /** Chỉ chủ sự kiện/bình luận chứa ảnh này (hoặc admin) mới được xóa ảnh. */
    function de_can_manage_attachment($attachment_row, $user_id, $is_admin)
    {
        if (!$attachment_row) return false;
        return de_can_manage_owner($attachment_row['owner_type'], $attachment_row['owner_id'], $user_id, $is_admin);
    }

    /** $force=true bỏ qua kiểm tra quyền — chỉ dùng khi cascade xóa nội bộ
     *  (item_delete/comment_delete đã tự kiểm tra quyền ở tầng trên). */
    function de_delete_image($image_id, $user_id = 0, $is_admin = false, $force = false)
    {
        $id = (int) $image_id;
        if ($id <= 0) return false;
        $row = db_fetch_row("SELECT id, owner_type, owner_id, file_url FROM de_attachments WHERE id = $id LIMIT 1");
        if (!$row) return false;
        if (!$force && !de_can_manage_attachment($row, $user_id, $is_admin)) return false;
        $rel = str_replace(['/', '\\'], DIRECTORY_SEPARATOR, (string) $row['file_url']);
        $abs = APPPATH . DIRECTORY_SEPARATOR . $rel;
        if (is_file($abs) && strpos($rel, 'daily_events') !== false) @unlink($abs);
        db_delete('de_attachments', "id = $id");
        return true;
    }

    function de_delete_images_for($owner_type, $owner_id)
    {
        foreach (de_list_images($owner_type, $owner_id) as $img) {
            de_delete_image($img['id'], 0, false, true);
        }
    }

    /* ============================================================
     *  Items (3 loại cụm sự kiện)
     * ============================================================ */
    function de_sanitize_item_payload($type, $data)
    {
        $type = in_array($type, DE_TYPES, true) ? $type : '';
        if ($type === '') return ['error' => 'Loại sự kiện không hợp lệ.'];

        $event_date = trim((string) ($data['event_date'] ?? ''));
        if ($event_date === '' || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $event_date)) {
            $event_date = date('Y-m-d');
        }
        $description = trim((string) ($data['description'] ?? ''));

        $row = [
            'item_type'   => $type,
            'event_date'  => $event_date,
            'product_id'  => null,
            'product_name'=> null,
            'party_name'  => null,
            'sample_name' => null,
            'description' => $description,
        ];

        if ($type === 'feedback') {
            $pid = (int) ($data['product_id'] ?? 0);
            $pname = trim((string) ($data['product_name'] ?? ''));
            if ($pid <= 0 || $pname === '') return ['error' => 'Vui lòng chọn sản phẩm từ danh sách.'];
            if ($description === '') return ['error' => 'Vui lòng nhập mô tả sự kiện.'];
            $row['product_id'] = $pid;
            $row['product_name'] = $pname;
        } else {
            $party = trim((string) ($data['party_name'] ?? ''));
            $sample = trim((string) ($data['sample_name'] ?? ''));
            if ($party === '') return ['error' => $type === 'sample_received' ? 'Vui lòng nhập người gửi.' : 'Vui lòng nhập người nhận.'];
            if ($sample === '') return ['error' => 'Vui lòng nhập tên mẫu.'];
            $row['party_name'] = $party;
            $row['sample_name'] = $sample;
        }

        return ['row' => $row];
    }

    function de_item_add($type, $data, $user_id)
    {
        de_ensure_tables();
        $res = de_sanitize_item_payload($type, $data);
        if (isset($res['error'])) return ['success' => false, 'message' => $res['error']];
        $row = $res['row'];
        $row['created_by'] = (int) $user_id;
        $row['created_at'] = date('Y-m-d H:i:s');
        $id = (int) db_insert('de_items', $row);
        if ($id <= 0) return ['success' => false, 'message' => 'Không thể lưu sự kiện.'];
        return ['success' => true, 'id' => $id];
    }

    function de_item_get($id)
    {
        $id = (int) $id;
        if ($id <= 0) return null;
        return db_fetch_row("SELECT * FROM de_items WHERE id = $id LIMIT 1");
    }

    /** Cá nhân hóa: chủ sự kiện, admin, hoặc user đã được chia sẻ mới được xem. */
    function de_can_view_item($item_id, $viewer_id, $is_admin = false)
    {
        if ($is_admin) return true;
        $item = de_item_get($item_id);
        if (!$item) return false;
        if ((int) $item['created_by'] === (int) $viewer_id) return true;
        $viewer_id = (int) $viewer_id;
        return (bool) db_fetch_row("SELECT id FROM de_shares WHERE item_id = " . (int) $item_id . " AND shared_with = $viewer_id LIMIT 1");
    }

    function de_item_update($id, $data, $user_id, $is_admin = false)
    {
        de_ensure_tables();
        $item = de_item_get($id);
        if (!$item) return ['success' => false, 'message' => 'Không tìm thấy sự kiện.'];
        if (!$is_admin && (int) $item['created_by'] !== (int) $user_id) {
            return ['success' => false, 'message' => 'Bạn không có quyền sửa sự kiện này.'];
        }
        $res = de_sanitize_item_payload($item['item_type'], $data);
        if (isset($res['error'])) return ['success' => false, 'message' => $res['error']];
        $row = $res['row'];
        unset($row['item_type']);
        $row['updated_at'] = date('Y-m-d H:i:s');
        db_update('de_items', $row, "id = " . (int) $id);
        return ['success' => true];
    }

    function de_item_delete($id, $user_id, $is_admin = false)
    {
        $id = (int) $id;
        $item = de_item_get($id);
        if (!$item) return ['success' => false, 'message' => 'Không tìm thấy sự kiện.'];
        if (!$is_admin && (int) $item['created_by'] !== (int) $user_id) {
            return ['success' => false, 'message' => 'Bạn không có quyền xóa sự kiện này.'];
        }
        de_delete_images_for('item', $id);
        foreach (db_fetch_array("SELECT id FROM de_comments WHERE item_id = $id") ?: [] as $c) {
            de_delete_images_for('comment', $c['id']);
        }
        db_delete('de_comments', "item_id = $id");
        db_delete('de_shares', "item_id = $id");
        db_delete('de_items', "id = $id");
        return ['success' => true];
    }

    /** Điều kiện WHERE cá nhân hóa: chủ sở hữu, admin, hoặc user được chia sẻ. */
    function de_visibility_where($viewer_id, $is_admin)
    {
        if ($is_admin) return '1=1';
        $viewer_id = (int) $viewer_id;
        return "(i.created_by = $viewer_id OR EXISTS (
                    SELECT 1 FROM de_shares s WHERE s.item_id = i.id AND s.shared_with = $viewer_id
                ))";
    }

    function de_item_where($type, $keyword, $viewer_id, $is_admin)
    {
        $type = in_array($type, DE_TYPES, true) ? $type : DE_TYPES[0];
        $where = "i.item_type = '" . escape_string($type) . "' AND " . de_visibility_where($viewer_id, $is_admin);
        $kw = trim((string) $keyword);
        if ($kw !== '') {
            $k = escape_string($kw);
            $where .= " AND (i.description LIKE '%$k%' OR i.product_name LIKE '%$k%'
                        OR i.party_name LIKE '%$k%' OR i.sample_name LIKE '%$k%')";
        }
        return $where;
    }

    /** Tổng số sự kiện (loại + từ khóa + cá nhân hóa) — dùng cho phân trang. */
    function de_item_count($type, $keyword, $viewer_id, $is_admin)
    {
        de_ensure_tables();
        $where = de_item_where($type, $keyword, $viewer_id, $is_admin);
        $row = db_fetch_row("SELECT COUNT(*) AS n FROM de_items i WHERE $where");
        return (int) ($row['n'] ?? 0);
    }

    /**
     * Danh sách cụm sự kiện theo loại (cá nhân hóa + phân trang), kèm ảnh +
     * bình luận + danh sách đã chia sẻ.
     */
    function de_item_list($type, $keyword, $viewer_id, $is_admin, $page = 1, $per_page = 10)
    {
        de_ensure_tables();
        $where = de_item_where($type, $keyword, $viewer_id, $is_admin);
        $page = max(1, (int) $page);
        $per_page = max(1, (int) $per_page);
        $offset = ($page - 1) * $per_page;

        $rows = db_fetch_array(
            "SELECT * FROM de_items i WHERE $where ORDER BY i.created_at DESC LIMIT $per_page OFFSET $offset"
        ) ?: [];

        $out = [];
        foreach ($rows as $r) {
            $id = (int) $r['id'];
            $comments = de_comment_list($id, $viewer_id);
            $out[] = [
                'id'              => $id,
                'item_type'       => $r['item_type'],
                'event_date'      => $r['event_date'],
                'product_id'      => (int) $r['product_id'],
                'product_name'    => $r['product_name'],
                'party_name'      => $r['party_name'],
                'sample_name'     => $r['sample_name'],
                'description'     => $r['description'],
                'created_by'      => (int) $r['created_by'],
                'created_by_name' => de_display_name_for($r['created_by'], $viewer_id),
                'created_at'      => $r['created_at'],
                'updated_at'      => $r['updated_at'],
                'images'          => de_list_images('item', $id),
                'comments'        => $comments,
                'shares'          => de_share_list($id, $viewer_id),
            ];
        }
        return $out;
    }

    /* ============================================================
     *  Bình luận ("Bình luận" / "Yêu cầu" / "Trả lời" tùy loại — nhãn ở JS)
     * ============================================================ */
    function de_comment_add($item_id, $content, $user_id, $is_admin = false)
    {
        de_ensure_tables();
        $item_id = (int) $item_id;
        $content = trim((string) $content);
        if (!de_item_get($item_id)) return ['success' => false, 'message' => 'Không tìm thấy sự kiện.'];
        if (!de_can_view_item($item_id, $user_id, $is_admin)) {
            return ['success' => false, 'message' => 'Bạn không có quyền xem sự kiện này.'];
        }
        if ($content === '') return ['success' => false, 'message' => 'Vui lòng nhập nội dung.'];
        $id = (int) db_insert('de_comments', [
            'item_id'    => $item_id,
            'content'    => $content,
            'created_by' => (int) $user_id,
            'created_at' => date('Y-m-d H:i:s'),
        ]);
        if ($id <= 0) return ['success' => false, 'message' => 'Không thể lưu bình luận.'];

        // Thông báo cho chủ sự kiện + người đã được chia sẻ (trừ chính người vừa bình luận).
        if (function_exists('notify_create')) {
            $item = de_item_get($item_id);
            $notify_ids = [];
            if ($item) $notify_ids[] = (int) $item['created_by'];
            foreach (de_share_list($item_id) as $s) $notify_ids[] = (int) $s['user_id'];
            $notify_ids = array_unique(array_filter($notify_ids, function ($v) use ($user_id) {
                return $v > 0 && $v !== (int) $user_id;
            }));
            foreach ($notify_ids as $nid) {
                // Tên người bình luận hiển thị theo biệt danh riêng mà NGƯỜI NHẬN đã đặt (danh bạ Chat).
                $actor = de_display_name_for($user_id, $nid) ?: 'Ai đó';
                notify_create($nid, 'Bình luận mới ở sự kiện hằng ngày', $actor . ' vừa bình luận.',
                    '?mod=daily_events&controllers=daily_events&action=daily_events&item=' . $item_id,
                    'daily_event_comment', (int) $user_id);
            }
        }
        return ['success' => true, 'id' => $id];
    }

    function de_current_user_by_id($id)
    {
        $id = (int) $id;
        if ($id <= 0) return null;
        return db_fetch_row("SELECT fullname, username FROM tbl_users WHERE id = $id LIMIT 1");
    }

    /** Tên hiển thị cho $target_uid dưới góc nhìn của $viewer_id — ưu tiên biệt danh
     *  riêng tư mà $viewer_id đã đặt trong danh bạ Chat, fallback fullname/username. */
    function de_display_name_for($target_uid, $viewer_id)
    {
        $target_uid = (int) $target_uid;
        if (!function_exists('chat_contact_aliases_map')) {
            require_once __DIR__ . '/chat.php';
        }
        if (function_exists('chat_contact_aliases_map')) {
            $aliases = chat_contact_aliases_map((int) $viewer_id);
            if (!empty($aliases[$target_uid])) return $aliases[$target_uid];
        }
        $u = de_current_user_by_id($target_uid);
        return $u ? (($u['fullname'] ?: $u['username'])) : '';
    }

    define('DE_COMMENT_RECALL_SECONDS', 5);

    /** Còn trong cửa sổ 5s để tự thu hồi bình luận của chính mình không. */
    function de_comment_can_recall($comment, $user_id)
    {
        if (!$comment || (int) $comment['created_by'] !== (int) $user_id) return false;
        return (time() - strtotime($comment['created_at'])) <= DE_COMMENT_RECALL_SECONDS;
    }

    function de_comment_list($item_id, $viewer_id = 0)
    {
        $item_id = (int) $item_id;
        $rows = db_fetch_array(
            "SELECT * FROM de_comments WHERE item_id = $item_id ORDER BY created_at ASC"
        ) ?: [];
        $out = [];
        foreach ($rows as $r) {
            $cid = (int) $r['id'];
            $secondsLeft = DE_COMMENT_RECALL_SECONDS - (time() - strtotime($r['created_at']));
            $out[] = [
                'id'              => $cid,
                'item_id'         => (int) $r['item_id'],
                'content'         => $r['content'],
                'created_by'      => (int) $r['created_by'],
                'created_by_name' => de_display_name_for($r['created_by'], $viewer_id),
                'created_at'      => $r['created_at'],
                'images'          => de_list_images('comment', $cid),
                'can_recall'      => de_comment_can_recall($r, $viewer_id),
                'recall_seconds_left' => max(0, $secondsLeft),
            ];
        }
        return $out;
    }

    /** Admin xóa được bất kỳ lúc nào; chủ bình luận chỉ "thu hồi" được trong 5s đầu. */
    function de_comment_delete($comment_id, $user_id, $is_admin = false)
    {
        $comment_id = (int) $comment_id;
        $row = db_fetch_row("SELECT id, created_by, created_at FROM de_comments WHERE id = $comment_id LIMIT 1");
        if (!$row) return ['success' => false, 'message' => 'Không tìm thấy bình luận.'];
        if (!$is_admin && !de_comment_can_recall($row, $user_id)) {
            return ['success' => false, 'message' => 'Đã quá 5 giây, không thể thu hồi bình luận này.'];
        }
        de_delete_images_for('comment', $comment_id);
        db_delete('de_comments', "id = $comment_id");
        return ['success' => true];
    }

    /* ============================================================
     *  Chia sẻ cho user khác (xem + bình luận). Cá nhân hóa: chỉ chủ sự
     *  kiện hoặc admin mới được thêm/gỡ chia sẻ — xem ghi chú đầu file.
     * ============================================================ */
    function de_share_list($item_id, $viewer_id = 0)
    {
        $item_id = (int) $item_id;
        $rows = db_fetch_array(
            "SELECT shared_with AS user_id FROM de_shares WHERE item_id = $item_id ORDER BY created_at ASC"
        ) ?: [];
        return array_map(function ($r) use ($viewer_id) {
            $uid = (int) $r['user_id'];
            return ['user_id' => $uid, 'fullname' => de_display_name_for($uid, $viewer_id)];
        }, $rows);
    }

    function de_share_add($item_id, $target_uid, $by_uid, $is_admin = false)
    {
        de_ensure_tables();
        $item_id = (int) $item_id;
        $target_uid = (int) $target_uid;
        $by_uid = (int) $by_uid;
        $item = de_item_get($item_id);
        if (!$item) return ['success' => false, 'message' => 'Không tìm thấy sự kiện.'];
        if (!$is_admin && (int) $item['created_by'] !== $by_uid) {
            return ['success' => false, 'message' => 'Chỉ chủ sự kiện mới được chia sẻ.'];
        }
        if ($target_uid <= 0 || $target_uid === $by_uid) {
            return ['success' => false, 'message' => 'Người dùng không hợp lệ.'];
        }
        $exists = db_fetch_row("SELECT id FROM de_shares WHERE item_id = $item_id AND shared_with = $target_uid LIMIT 1");
        if (!$exists) {
            db_insert('de_shares', [
                'item_id'     => $item_id,
                'shared_with' => $target_uid,
                'shared_by'   => $by_uid,
                'created_at'  => date('Y-m-d H:i:s'),
            ]);
            if (function_exists('notify_create')) {
                $actor = de_display_name_for($by_uid, $target_uid) ?: 'Ai đó';
                notify_create($target_uid, 'Sự kiện hằng ngày được chia sẻ với bạn', $actor . ' đã chia sẻ 1 sự kiện với bạn.',
                    '?mod=daily_events&controllers=daily_events&action=daily_events&item=' . $item_id,
                    'daily_event_share', $by_uid);
            }
        }
        return ['success' => true, 'shares' => de_share_list($item_id, $by_uid)];
    }

    function de_share_remove($item_id, $target_uid, $by_uid, $is_admin = false)
    {
        $item_id = (int) $item_id;
        $target_uid = (int) $target_uid;
        $by_uid = (int) $by_uid;
        $item = de_item_get($item_id);
        if (!$item) return ['success' => false, 'message' => 'Không tìm thấy sự kiện.'];
        // Chủ sự kiện/admin gỡ được bất kỳ ai; người được chia sẻ tự gỡ (rời) chính mình.
        if (!$is_admin && (int) $item['created_by'] !== $by_uid && $target_uid !== $by_uid) {
            return ['success' => false, 'message' => 'Bạn không có quyền gỡ chia sẻ này.'];
        }
        db_delete('de_shares', "item_id = $item_id AND shared_with = $target_uid");
        return ['success' => true, 'shares' => de_share_list($item_id, $by_uid)];
    }
}
