<?php
/**
 * purchase_request — "ĐỀ NGHỊ MUA ĐỒ" (module cash_transactions / nhóm THU CHI). Prefix pr_*.
 *
 * Chủ yêu cầu 19/11/2026: các đồ dùng mua linh tinh phục vụ hoạt động doanh nghiệp (vd y tế, văn phòng,
 * vệ sinh, vật liệu sản xuất). Nhân viên đề nghị trên điện thoại, admin gom nhiều đề nghị thành MỘT danh
 * sách, chụp ảnh gửi người đi mua rồi đánh dấu "Đã đặt mua". Phiếu gửi đi CHỈ cần: mua gì · số lượng ·
 * đơn vị, theo từng mục chi (chủ chốt 19/11 — ảnh phiếu giấy chỉ minh hoạ ý nghĩa, không phải mẫu thiết kế).
 *
 * ==============================================================================
 * 3 LỚP DỮ LIỆU
 * ==============================================================================
 *  1. DANH SÁCH ĐỀ NGHỊ (catalog):  pr_categories (Mục chi) → pr_items (Hạng mục chi, có ảnh).
 *     Mỗi hạng mục sinh ra từ MỘT dòng ở "Nhập thu chi" (nút "Thêm DS đề nghị", pr_items.source_cash_id)
 *     hoặc tạo tay trong "QL danh sách đề nghị".
 *  2. ĐỀ NGHỊ (request):  pr_requests (đơn) → pr_request_lines (mỗi dòng 1 hạng mục: SL + đơn vị + ghi chú).
 *     Trạng thái: draft → submitted (chờ duyệt) → ordered (đã đặt mua) | rejected (trả lại, kèm lý do) |
 *     cancelled (người đề nghị tự hủy). Dòng lưu SNAPSHOT tên hạng mục/mục chi: đổi tên/xóa hạng mục sau
 *     này không làm hỏng đơn cũ.
 *  3. ĐỢT ĐẶT MUA (order):  pr_orders — mỗi lần admin "Xác nhận gửi đặt mua" ghi 1 dòng kèm danh sách đã gộp
 *     (snapshot_json). Các đơn trong đợt trỏ về order_id.
 *
 * ==============================================================================
 * QUY TẮC NGHIỆP VỤ (đừng phá)
 * ==============================================================================
 *  · NGƯỜI DUYỆT = admin (tbl_users.role = 'admin') — pr_is_approver(). Muốn thêm người duyệt khác thì sửa
 *    DUY NHẤT hàm đó.
 *  · "Trước đó 5/10: 4 bịch | 1/11: 4 bịch" = 2 lần GẦN NHẤT hạng mục đó thật sự được đặt mua (đơn 'ordered',
 *    dòng không bị gỡ), lấy SL admin đã chốt. Bảng thu chi không có số lượng/đơn vị nên không dùng làm nguồn.
 *  · Chống đề nghị trùng: khi chọn hạng mục mà đang có đề nghị KHÁC chờ duyệt cho đúng hạng mục đó → cảnh báo
 *    kèm người đề nghị + SL; khi admin gom, các dòng cùng (hạng mục + đơn vị) được cộng dồn trên ảnh.
 *  · Đơn đã gửi thì khoá, chỉ người đề nghị HỦY được (hoặc admin Từ chối). Đơn bị từ chối/nháp mới sửa được.
 *  · Gỡ 1 dòng khi gom (dropped = 1) = dòng đó KHÔNG được mua đợt này; đơn vẫn 'ordered' nhưng phải còn ít nhất
 *    1 dòng chưa gỡ (không có đơn "đã đặt mua" mà chẳng mua gì).
 *
 * Bảng tự tạo qua pr_ensure_tables() (giống plan_history / ltp_share_notify). File .sql đi kèm chỉ để tra cứu.
 */

if (date_default_timezone_get() !== 'Asia/Ho_Chi_Minh') {
    date_default_timezone_set('Asia/Ho_Chi_Minh');
}

require_once __DIR__ . '/notifications.php';

if (!function_exists('pr_ensure_tables')) {

    /* ============================================================
     *  HẰNG SỐ + SCHEMA
     * ============================================================ */

    /** Thư mục ảnh hạng mục (đường dẫn tương đối từ gốc app — view dùng thẳng, đã có <base href>). */
    function pr_dir_rel() { return 'public/uploads/purchase_request'; }

    /** Link chuông → trang đề nghị (tab theo ngữ cảnh). */
    function pr_link($tab = 'mine') { return 'cash_transactions/purchase_request?tab=' . $tab; }

    function pr_now() { return date('Y-m-d H:i:s'); }

    function pr_status_labels()
    {
        return [
            'draft'     => 'Nháp',
            'submitted' => 'Chờ duyệt',
            'ordered'   => 'Đã đặt mua',
            'rejected'  => 'Bị từ chối',
            'cancelled' => 'Đã hủy',
        ];
    }

    function pr_ensure_tables()
    {
        static $done = false;
        if ($done) return;
        $done = true;

        db_query("CREATE TABLE IF NOT EXISTS pr_categories (
            id         INT(11) NOT NULL AUTO_INCREMENT,
            name       VARCHAR(120) NOT NULL,
            sort_order INT(11) NOT NULL DEFAULT 0,
            is_active  TINYINT(1) NOT NULL DEFAULT 1,
            created_at DATETIME NOT NULL,
            updated_at DATETIME NOT NULL,
            PRIMARY KEY (id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci");

        db_query("CREATE TABLE IF NOT EXISTS pr_items (
            id             INT(11) NOT NULL AUTO_INCREMENT,
            category_id    INT(11) NOT NULL,
            name           VARCHAR(255) NOT NULL,
            image_file     VARCHAR(255) DEFAULT NULL,
            /* Phiếu 'Nhập thu chi' đã sinh ra hạng mục này (NULL = tạo tay trong QL danh sách). */
            source_cash_id INT(11) DEFAULT NULL,
            is_active      TINYINT(1) NOT NULL DEFAULT 1,
            created_by     INT(11) NOT NULL DEFAULT 0,
            created_at     DATETIME NOT NULL,
            updated_at     DATETIME NOT NULL,
            PRIMARY KEY (id),
            KEY idx_cat (category_id),
            KEY idx_source (source_cash_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci");

        db_query("CREATE TABLE IF NOT EXISTS pr_orders (
            id            INT(11) NOT NULL AUTO_INCREMENT,
            created_by    INT(11) NOT NULL DEFAULT 0,
            created_at    DATETIME NOT NULL,
            request_count INT(11) NOT NULL DEFAULT 0,
            line_count    INT(11) NOT NULL DEFAULT 0,
            /* Danh sách ĐÃ GỘP gửi đi (đóng băng) để xem lại sau này. */
            snapshot_json LONGTEXT DEFAULT NULL,
            PRIMARY KEY (id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci");

        db_query("CREATE TABLE IF NOT EXISTS pr_requests (
            id            INT(11) NOT NULL AUTO_INCREMENT,
            code          VARCHAR(30) NOT NULL,
            user_id       INT(11) NOT NULL,
            status        VARCHAR(12) NOT NULL DEFAULT 'draft',
            is_urgent     TINYINT(1) NOT NULL DEFAULT 0,
            note          VARCHAR(500) DEFAULT NULL,
            reject_reason VARCHAR(500) DEFAULT NULL,
            order_id      INT(11) DEFAULT NULL,
            created_at    DATETIME NOT NULL,
            updated_at    DATETIME NOT NULL,
            submitted_at  DATETIME DEFAULT NULL,
            decided_at    DATETIME DEFAULT NULL,
            decided_by    INT(11) NOT NULL DEFAULT 0,
            PRIMARY KEY (id),
            KEY idx_user (user_id, status),
            KEY idx_status (status, submitted_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci");

        db_query("CREATE TABLE IF NOT EXISTS pr_request_lines (
            id             INT(11) NOT NULL AUTO_INCREMENT,
            request_id     INT(11) NOT NULL,
            item_id        INT(11) DEFAULT NULL,
            item_name      VARCHAR(255) NOT NULL,
            category_id    INT(11) DEFAULT NULL,
            category_name  VARCHAR(120) NOT NULL DEFAULT '',
            qty            DECIMAL(12,2) NOT NULL DEFAULT 0,
            qty_requested  DECIMAL(12,2) NOT NULL DEFAULT 0,
            unit           VARCHAR(40) NOT NULL DEFAULT '',
            note           VARCHAR(255) DEFAULT NULL,
            dropped        TINYINT(1) NOT NULL DEFAULT 0,
            sort_order     INT(11) NOT NULL DEFAULT 0,
            PRIMARY KEY (id),
            KEY idx_req (request_id),
            KEY idx_item (item_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci");

        /* KHÔNG gieo dữ liệu mẫu: danh sách đề nghị do chủ xây từ "Nhập thu chi" (nút Thêm DS đề nghị)
           hoặc "QL danh sách đề nghị". Ảnh phiếu giấy chủ gửi chỉ để hiểu nghiệp vụ, không phải nguồn dữ liệu. */
    }

    /* ============================================================
     *  QUYỀN + TIỆN ÍCH
     * ============================================================ */

    /** Người duyệt đề nghị. Hiện = admin. Muốn mở rộng (vd thêm kế toán) chỉ sửa hàm này. */
    function pr_is_approver($user = null)
    {
        if ($user === null) $user = permission_current_user();
        return $user && ($user['role'] ?? 'user') === 'admin';
    }

    function pr_approver_ids()
    {
        $ids = [];
        foreach ((db_fetch_array("SELECT id FROM tbl_users WHERE role = 'admin'") ?: []) as $r) $ids[] = (int) $r['id'];
        return $ids;
    }

    function pr_user_name($uid)
    {
        static $cache = [];
        $uid = (int) $uid;
        if ($uid <= 0) return 'Hệ thống';
        if (isset($cache[$uid])) return $cache[$uid];
        $r = db_fetch_row("SELECT fullname, username FROM tbl_users WHERE id = $uid LIMIT 1");
        if (!$r) return $cache[$uid] = '#' . $uid;
        $n = trim((string) ($r['fullname'] ?? ''));
        return $cache[$uid] = ($n !== '' ? $n : (string) $r['username']);
    }

    function pr_clean($s, $max)
    {
        $s = trim(preg_replace('/\s+/u', ' ', (string) $s));
        return mb_substr($s, 0, $max, 'UTF-8');
    }

    function pr_qty_fmt($q)
    {
        $q = (float) $q;
        return rtrim(rtrim(number_format($q, 2, '.', ''), '0'), '.');
    }

    function pr_dmy($datetime)
    {
        $t = strtotime((string) $datetime);
        return $t ? date('d/m/Y', $t) : '';
    }

    /** Gửi chuông cho 1 danh sách user; lỗi chuông không được làm hỏng nghiệp vụ. Trả số chuông đã tạo. */
    function pr_notify(array $uids, $title, $message, $tab, $actor)
    {
        $n = 0;
        foreach (array_unique(array_map('intval', $uids)) as $uid) {
            if ($uid <= 0 || $uid === (int) $actor) continue;   // không tự báo cho mình
            try {
                if (notify_create($uid, $title, $message, pr_link($tab), 'purchase_request', (int) $actor) > 0) $n++;
            } catch (\Throwable $e) {
                error_log('[purchase_request] notify: ' . $e->getMessage());
            }
        }
        return $n;
    }

    /* ============================================================
     *  ẢNH HẠNG MỤC
     * ============================================================ */

    function pr_image_url($rel)
    {
        $rel = trim((string) $rel);
        return $rel !== '' ? $rel : '';
    }

    /** Xoá file ảnh cũ — CHỈ file nằm trong thư mục ảnh của module này. */
    function pr_delete_image_file($rel)
    {
        $rel = trim((string) $rel);
        if ($rel === '' || strpos($rel, pr_dir_rel() . '/') !== 0 || strpos($rel, '..') !== false) return;
        $abs = APPPATH . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $rel);
        if (is_file($abs)) @unlink($abs);
    }

    /**
     * Lưu 1 ảnh tải lên ($file = phần tử $_FILES). Kiểm MIME thật (không tin phần mở rộng) và ghi lại
     * bằng đuôi chuẩn theo MIME. Trả ['ok'=>true,'rel'=>path] | ['ok'=>false,'message'=>...].
     */
    function pr_save_uploaded_image($file, $item_id)
    {
        if (empty($file) || ($file['error'] ?? 1) !== UPLOAD_ERR_OK) {
            return ['ok' => false, 'message' => 'Chưa nhận được ảnh hợp lệ.'];
        }
        if (($file['size'] ?? 0) > 8 * 1024 * 1024) return ['ok' => false, 'message' => 'Ảnh quá lớn (tối đa 8MB).'];
        $info = @getimagesize($file['tmp_name']);
        $map  = [IMAGETYPE_JPEG => 'jpg', IMAGETYPE_PNG => 'png', IMAGETYPE_GIF => 'gif', IMAGETYPE_WEBP => 'webp'];
        if (!$info || !isset($map[$info[2]])) return ['ok' => false, 'message' => 'File không phải ảnh JPG/PNG/WEBP/GIF.'];

        $abs_dir = APPPATH . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, pr_dir_rel());
        if (!is_dir($abs_dir)) @mkdir($abs_dir, 0777, true);
        $name = 'pr' . (int) $item_id . '_' . time() . '_' . bin2hex(random_bytes(3)) . '.' . $map[$info[2]];
        if (!move_uploaded_file($file['tmp_name'], $abs_dir . DIRECTORY_SEPARATOR . $name)) {
            return ['ok' => false, 'message' => 'Không lưu được ảnh lên máy chủ.'];
        }
        return ['ok' => true, 'rel' => pr_dir_rel() . '/' . $name];
    }

    /* ============================================================
     *  DANH SÁCH ĐỀ NGHỊ — MỤC CHI
     * ============================================================ */

    function pr_categories_list()
    {
        pr_ensure_tables();
        return db_fetch_array(
            "SELECT c.id, c.name, c.sort_order,
                    (SELECT COUNT(*) FROM pr_items i WHERE i.category_id = c.id AND i.is_active = 1) AS item_count
             FROM pr_categories c WHERE c.is_active = 1 ORDER BY c.sort_order ASC, c.id ASC"
        ) ?: [];
    }

    function pr_category_get($id)
    {
        $id = (int) $id;
        if ($id <= 0) return null;
        return db_fetch_row("SELECT * FROM pr_categories WHERE id = $id AND is_active = 1 LIMIT 1") ?: null;
    }

    /** Thêm (id=0) hoặc sửa tên Mục chi. Trả ['ok','id','message']. */
    function pr_category_save($id, $name)
    {
        pr_ensure_tables();
        $id   = (int) $id;
        $name = pr_clean($name, 120);
        if ($name === '') return ['ok' => false, 'message' => 'Chưa nhập tên mục chi.'];

        $dup = db_fetch_row("SELECT id FROM pr_categories WHERE is_active = 1 AND id <> $id
                             AND LOWER(name) = LOWER('" . escape_string($name) . "') LIMIT 1");
        if ($dup) return ['ok' => false, 'message' => 'Mục chi "' . $name . '" đã có rồi.'];

        $now = pr_now();
        if ($id > 0) {
            if (!pr_category_get($id)) return ['ok' => false, 'message' => 'Không tìm thấy mục chi.'];
            db_update('pr_categories', ['name' => $name, 'updated_at' => $now], "id = $id");
            // Đồng bộ tên hiển thị trên các hạng mục chưa chốt; dòng ĐÃ đặt mua giữ nguyên snapshot lịch sử.
            db_query("UPDATE pr_request_lines l JOIN pr_requests r ON r.id = l.request_id
                      SET l.category_name = '" . escape_string($name) . "'
                      WHERE l.category_id = $id AND r.status IN ('draft','submitted','rejected')");
            return ['ok' => true, 'id' => $id];
        }
        $mx = db_fetch_row("SELECT COALESCE(MAX(sort_order), 0) AS m FROM pr_categories");
        $nid = (int) db_insert('pr_categories', [
            'name' => $name, 'sort_order' => (int) $mx['m'] + 1, 'is_active' => 1, 'created_at' => $now, 'updated_at' => $now,
        ]);
        return $nid > 0 ? ['ok' => true, 'id' => $nid] : ['ok' => false, 'message' => 'Không lưu được mục chi.'];
    }

    /** Xoá Mục chi — chỉ khi không còn hạng mục (tránh mồ côi hàng loạt do lỡ tay). */
    function pr_category_delete($id)
    {
        pr_ensure_tables();
        $id = (int) $id;
        if (!pr_category_get($id)) return ['ok' => false, 'message' => 'Không tìm thấy mục chi.'];
        $n = (int) (db_fetch_row("SELECT COUNT(*) AS c FROM pr_items WHERE category_id = $id AND is_active = 1")['c'] ?? 0);
        if ($n > 0) {
            return ['ok' => false, 'message' => 'Mục chi còn ' . $n . ' hạng mục. Hãy chuyển hoặc xóa các hạng mục trước.'];
        }
        db_update('pr_categories', ['is_active' => 0, 'updated_at' => pr_now()], "id = $id");
        return ['ok' => true];
    }

    /* ============================================================
     *  DANH SÁCH ĐỀ NGHỊ — HẠNG MỤC CHI
     * ============================================================ */

    function pr_item_row_out($r)
    {
        return [
            'id'             => (int) $r['id'],
            'category_id'    => (int) $r['category_id'],
            'category_name'  => (string) ($r['category_name'] ?? ''),
            'name'           => (string) $r['name'],
            'image_url'      => pr_image_url($r['image_file'] ?? ''),
            'source_cash_id' => $r['source_cash_id'] !== null ? (int) $r['source_cash_id'] : 0,
        ];
    }

    function pr_item_get($id)
    {
        $id = (int) $id;
        if ($id <= 0) return null;
        $r = db_fetch_row("SELECT i.*, c.name AS category_name FROM pr_items i
                           LEFT JOIN pr_categories c ON c.id = i.category_id
                           WHERE i.id = $id AND i.is_active = 1 LIMIT 1");
        return $r ?: null;
    }

    /** Toàn bộ danh sách: [ {id,name,sort_order,items:[...]} ] — dùng cho bộ chọn + màn QL. */
    function pr_catalog()
    {
        $cats  = pr_categories_list();
        $rows  = db_fetch_array(
            "SELECT i.*, c.name AS category_name FROM pr_items i
             JOIN pr_categories c ON c.id = i.category_id AND c.is_active = 1
             WHERE i.is_active = 1 ORDER BY c.sort_order ASC, c.id ASC, i.id ASC"   /* thứ tự thêm = thứ tự trên phiếu giấy */
        ) ?: [];
        $by = [];
        foreach ($rows as $r) $by[(int) $r['category_id']][] = pr_item_row_out($r);
        foreach ($cats as &$c) {
            $c['id'] = (int) $c['id'];
            $c['items'] = $by[$c['id']] ?? [];
        }
        unset($c);
        return $cats;
    }

    /**
     * Thêm (id=0) hoặc sửa 1 hạng mục. $file = $_FILES['image'] | null; $remove_image = bỏ ảnh hiện có.
     * Trả ['ok','item'] | ['ok'=>false,'message'].
     */
    function pr_item_save($id, $category_id, $name, $source_cash_id, $file, $remove_image, $user_id)
    {
        pr_ensure_tables();
        $id   = (int) $id;
        $cid  = (int) $category_id;
        $name = pr_clean($name, 255);
        $src  = (int) $source_cash_id;

        if ($name === '') return ['ok' => false, 'message' => 'Chưa nhập hạng mục chi.'];
        if (!pr_category_get($cid)) return ['ok' => false, 'message' => 'Hãy chọn mục chi.'];

        $cur = $id > 0 ? pr_item_get($id) : null;
        if ($id > 0 && !$cur) return ['ok' => false, 'message' => 'Không tìm thấy hạng mục.'];

        // Trùng (cùng mục chi + cùng tên) với hạng mục KHÁC đang hoạt động → chặn, báo rõ.
        $dup = db_fetch_row("SELECT id FROM pr_items WHERE is_active = 1 AND id <> $id AND category_id = $cid
                             AND LOWER(name) = LOWER('" . escape_string($name) . "') LIMIT 1");
        if ($dup) return ['ok' => false, 'message' => 'Hạng mục "' . $name . '" đã có trong mục chi này.'];

        // Thêm mới mà trùng một hạng mục ĐÃ GỠ trước đây → bật lại (giữ lịch sử đặt mua gắn với nó).
        if ($id === 0) {
            $old = db_fetch_row("SELECT id FROM pr_items WHERE is_active = 0 AND category_id = $cid
                                 AND LOWER(name) = LOWER('" . escape_string($name) . "') ORDER BY id DESC LIMIT 1");
            if ($old) {
                $id  = (int) $old['id'];
                $cur = db_fetch_row("SELECT i.*, c.name AS category_name FROM pr_items i
                                     LEFT JOIN pr_categories c ON c.id = i.category_id WHERE i.id = $id");
                db_update('pr_items', ['is_active' => 1], "id = $id");
            }
        }

        $image = $cur ? (string) ($cur['image_file'] ?? '') : '';
        if (!empty($file) && ($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE) {
            $up = pr_save_uploaded_image($file, $id > 0 ? $id : 0);
            if (empty($up['ok'])) return ['ok' => false, 'message' => $up['message']];
            if ($image !== '') pr_delete_image_file($image);
            $image = $up['rel'];
        } elseif ($remove_image && $image !== '') {
            pr_delete_image_file($image);
            $image = '';
        }

        $now  = pr_now();
        $data = ['category_id' => $cid, 'name' => $name, 'image_file' => ($image !== '' ? $image : null), 'updated_at' => $now];
        if ($id > 0) {
            if ($src > 0) $data['source_cash_id'] = $src;
            db_update('pr_items', $data, "id = $id");
        } else {
            $data['source_cash_id'] = $src > 0 ? $src : null;
            $data['is_active']  = 1;
            $data['created_by'] = (int) $user_id;
            $data['created_at'] = $now;
            $id = (int) db_insert('pr_items', $data);
            if ($id <= 0) return ['ok' => false, 'message' => 'Không lưu được hạng mục.'];
        }
        return ['ok' => true, 'item' => pr_item_row_out(pr_item_get($id))];
    }

    /** Gỡ hạng mục khỏi danh sách (ẩn, giữ lịch sử). Đề nghị đang chờ giữ nguyên nhờ snapshot tên. */
    function pr_item_delete($id)
    {
        pr_ensure_tables();
        $id = (int) $id;
        $it = pr_item_get($id);
        if (!$it) return ['ok' => false, 'message' => 'Không tìm thấy hạng mục.'];
        db_update('pr_items', ['is_active' => 0, 'updated_at' => pr_now()], "id = $id");
        return ['ok' => true];
    }

    /** Hạng mục đang hoạt động sinh ra từ 1 phiếu thu chi (0 nếu chưa có). */
    function pr_item_id_by_cash($cash_id)
    {
        $cash_id = (int) $cash_id;
        if ($cash_id <= 0) return 0;
        $r = db_fetch_row("SELECT id FROM pr_items WHERE source_cash_id = $cash_id AND is_active = 1 ORDER BY id DESC LIMIT 1");
        return $r ? (int) $r['id'] : 0;
    }

    /** Map [cash_id => item_id] cho 1 loạt phiếu (1 truy vấn) — màn "Nhập thu chi" tô nút Thêm/Gỡ. */
    function pr_item_ids_by_cash(array $cash_ids)
    {
        pr_ensure_tables();
        $ids = array_values(array_unique(array_filter(array_map('intval', $cash_ids), static fn($v) => $v > 0)));
        if (!$ids) return [];
        $rows = db_fetch_array("SELECT source_cash_id, MAX(id) AS item_id FROM pr_items
                                WHERE is_active = 1 AND source_cash_id IN (" . implode(',', $ids) . ")
                                GROUP BY source_cash_id") ?: [];
        $map = [];
        foreach ($rows as $r) $map[(int) $r['source_cash_id']] = (int) $r['item_id'];
        return $map;
    }

    /* ============================================================
     *  THÔNG TIN GỢI Ý CHO 1 HẠNG MỤC (lần mua trước + đề nghị đang chờ)
     * ============================================================ */

    /**
     * history: tối đa 2 lần ĐÃ ĐẶT MUA gần nhất. pending: đề nghị KHÁC đang chờ duyệt cho cùng hạng mục.
     * $exclude_request: bỏ qua đơn đang soạn (khi sửa lại đơn đã gửi/bị từ chối).
     */
    function pr_item_info($item_id, $exclude_request = 0)
    {
        pr_ensure_tables();
        $item_id = (int) $item_id;
        $ex      = (int) $exclude_request;
        if ($item_id <= 0) return ['history' => [], 'pending' => []];

        $hist = db_fetch_array(
            "SELECT r.decided_at, l.qty, l.unit FROM pr_request_lines l
             JOIN pr_requests r ON r.id = l.request_id
             WHERE l.item_id = $item_id AND r.status = 'ordered' AND l.dropped = 0
             ORDER BY r.decided_at DESC, l.id DESC LIMIT 2"
        ) ?: [];
        $history = [];
        foreach ($hist as $h) {
            $history[] = ['date' => pr_dmy($h['decided_at']), 'qty' => (float) $h['qty'],
                          'qty_text' => pr_qty_fmt($h['qty']), 'unit' => (string) $h['unit']];
        }

        $pend = db_fetch_array(
            "SELECT r.id, r.user_id, r.submitted_at, l.qty, l.unit FROM pr_request_lines l
             JOIN pr_requests r ON r.id = l.request_id
             WHERE l.item_id = $item_id AND r.status = 'submitted' AND r.id <> $ex
             ORDER BY r.submitted_at DESC LIMIT 5"
        ) ?: [];
        $pending = [];
        foreach ($pend as $p) {
            $pending[] = ['who' => pr_user_name($p['user_id']), 'date' => pr_dmy($p['submitted_at']),
                          'qty_text' => pr_qty_fmt($p['qty']), 'unit' => (string) $p['unit']];
        }
        return ['history' => $history, 'pending' => $pending];
    }

    /* ============================================================
     *  ĐỀ NGHỊ — ĐỌC
     * ============================================================ */

    function pr_lines_of(array $request_ids)
    {
        $ids = array_values(array_unique(array_filter(array_map('intval', $request_ids), static fn($v) => $v > 0)));
        if (!$ids) return [];
        $rows = db_fetch_array(
            "SELECT l.*, i.image_file FROM pr_request_lines l
             LEFT JOIN pr_items i ON i.id = l.item_id
             WHERE l.request_id IN (" . implode(',', $ids) . ") ORDER BY l.request_id ASC, l.sort_order ASC, l.id ASC"
        ) ?: [];
        $by = [];
        foreach ($rows as $r) {
            $by[(int) $r['request_id']][] = [
                'id'            => (int) $r['id'],
                'item_id'       => $r['item_id'] !== null ? (int) $r['item_id'] : 0,
                'item_name'     => (string) $r['item_name'],
                'category_id'   => $r['category_id'] !== null ? (int) $r['category_id'] : 0,
                'category_name' => (string) $r['category_name'],
                'qty'           => (float) $r['qty'],
                'qty_text'      => pr_qty_fmt($r['qty']),
                'qty_requested' => (float) $r['qty_requested'],
                'unit'          => (string) $r['unit'],
                'note'          => (string) ($r['note'] ?? ''),
                'dropped'       => (int) $r['dropped'] === 1,
                'image_url'     => pr_image_url($r['image_file'] ?? ''),
            ];
        }
        return $by;
    }

    function pr_request_out($r, $lines)
    {
        $labels = pr_status_labels();
        return [
            'id'            => (int) $r['id'],
            'code'          => (string) $r['code'],
            'user_id'       => (int) $r['user_id'],
            'user_name'     => pr_user_name($r['user_id']),
            'status'        => (string) $r['status'],
            'status_label'  => $labels[$r['status']] ?? (string) $r['status'],
            'is_urgent'     => (int) $r['is_urgent'] === 1,
            'note'          => (string) ($r['note'] ?? ''),
            'reject_reason' => (string) ($r['reject_reason'] ?? ''),
            'order_id'      => $r['order_id'] !== null ? (int) $r['order_id'] : 0,
            'created_at'    => (string) $r['created_at'],
            'submitted_at'  => (string) ($r['submitted_at'] ?? ''),
            'decided_at'    => (string) ($r['decided_at'] ?? ''),
            'decided_by'    => $r['decided_by'] ? pr_user_name($r['decided_by']) : '',
            'date_text'     => pr_dmy($r['submitted_at'] ?: $r['created_at']),
            'lines'         => $lines,
        ];
    }

    /**
     * $order = mệnh đề ORDER BY tuỳ chọn — CHỈ truyền hằng số trong code, tuyệt đối không nhận từ người dùng.
     * Mặc định: chờ duyệt → nháp → bị từ chối → còn lại; trong nhóm mới nhất trước.
     */
    function pr_requests_fetch($where, $limit = 60, $offset = 0, $order = null)
    {
        pr_ensure_tables();
        if (!$order) {
            $order = "CASE status WHEN 'submitted' THEN 0 WHEN 'draft' THEN 1 WHEN 'rejected' THEN 2 ELSE 3 END,
                      COALESCE(submitted_at, created_at) DESC, id DESC";
        }
        $rows = db_fetch_array("SELECT * FROM pr_requests WHERE $where ORDER BY $order
                                LIMIT " . (int) $limit . " OFFSET " . max(0, (int) $offset)) ?: [];
        $lines = pr_lines_of(array_map(static fn($r) => (int) $r['id'], $rows));
        $out = [];
        foreach ($rows as $r) $out[] = pr_request_out($r, $lines[(int) $r['id']] ?? []);
        return $out;
    }

    function pr_request_get($id)
    {
        pr_ensure_tables();
        $id = (int) $id;
        if ($id <= 0) return null;
        $rows = pr_requests_fetch("id = $id", 1);
        return $rows ? $rows[0] : null;
    }

    /**
     * Một TRANG đề nghị theo điều kiện $where (dùng chung cho "Đơn của tôi" và "Đã xử lý").
     * Trang vượt quá số trang hiện có (vd vừa xóa đơn cuối của trang cuối) tự lùi về trang cuối.
     * Trả ['requests'=>[...], 'total'=>N, 'page'=>p, 'per_page'=>n, 'pages'=>P].
     */
    function pr_requests_page($where, $page = 1, $per_page = 10, $order = null)
    {
        pr_ensure_tables();
        $per   = max(1, min(50, (int) $per_page));
        $total = (int) (db_fetch_row("SELECT COUNT(*) AS c FROM pr_requests WHERE $where")['c'] ?? 0);
        $pages = max(1, (int) ceil($total / $per));
        $page  = min(max(1, (int) $page), $pages);
        return [
            'requests' => $total > 0 ? pr_requests_fetch($where, $per, ($page - 1) * $per, $order) : [],
            'total'    => $total, 'page' => $page, 'per_page' => $per, 'pages' => $pages,
        ];
    }

    /** "Đơn của tôi" — phân trang 10 đơn/trang (chờ duyệt → nháp → bị từ chối xếp trước, trong nhóm mới nhất trước). */
    function pr_my_requests($user_id, $page = 1, $per_page = 10)
    {
        $uid = (int) $user_id;
        // Đơn đã hủy chỉ giữ 14 ngày cho gọn danh sách.
        return pr_requests_page("user_id = $uid AND (status <> 'cancelled' OR created_at >= DATE_SUB(NOW(), INTERVAL 14 DAY))", $page, $per_page);
    }

    /**
     * Hàng đợi duyệt: 'submitted' = chờ duyệt (hàng đợi làm việc, KHÔNG phân trang để tick gom nhiều đơn);
     * 'done' = đã xử lý 30 ngày gần đây, PHÂN TRANG — các đề nghị đã GOM cùng một lần đặt mua hiện thành MỘT mục
     * (phiếu gom), xem pr_review_done_page(). Cùng dạng kết quả với pr_requests_page() (riêng 'done' trả thêm 'entries').
     */
    function pr_review_list($mode = 'submitted', $page = 1, $per_page = 10)
    {
        if ($mode === 'done') return pr_review_done_page($page, $per_page);
        $rows = pr_requests_fetch("status = 'submitted'", 100);
        return ['requests' => $rows, 'total' => count($rows), 'page' => 1, 'per_page' => max(1, count($rows)), 'pages' => 1];
    }

    /**
     * "Đã xử lý (30 ngày)": mỗi PHIẾU GOM (pr_orders) = MỘT mục gồm các đề nghị đã gom vào lần đặt mua đó; đề nghị bị
     * TỪ CHỐI (và đơn 'ordered' đời cũ không có order_id) = mỗi đơn một mục riêng. Mới xử lý xong xếp trước, phân trang.
     * Trả ['entries'=>[ ['kind'=>'order','order'=>{...}] | ['kind'=>'request','request'=>{...}] ], 'requests'=>[],
     *       'total'=>N (số MỤC), 'page', 'per_page', 'pages'].
     */
    function pr_review_done_page($page = 1, $per_page = 10)
    {
        pr_ensure_tables();
        $per = max(1, min(50, (int) $per_page));
        $win = "DATE_SUB(NOW(), INTERVAL 30 DAY)";
        $union = "SELECT 'order' AS kind, o.id AS ref_id, o.created_at AS sort_at
                    FROM pr_orders o
                   WHERE o.created_at >= $win
                     AND EXISTS (SELECT 1 FROM pr_requests r WHERE r.order_id = o.id AND r.status = 'ordered')
                  UNION ALL
                  SELECT 'request' AS kind, r.id AS ref_id, r.decided_at AS sort_at
                    FROM pr_requests r
                   WHERE r.decided_at >= $win
                     AND (r.status = 'rejected' OR (r.status = 'ordered' AND (r.order_id IS NULL OR r.order_id = 0)))";
        $total = (int) (db_fetch_row("SELECT COUNT(*) AS c FROM ($union) e")['c'] ?? 0);
        $pages = max(1, (int) ceil($total / $per));
        $page  = min(max(1, (int) $page), $pages);
        $rows  = $total > 0
            ? (db_fetch_array("SELECT kind, ref_id FROM ($union) e ORDER BY sort_at DESC, kind ASC, ref_id DESC
                               LIMIT $per OFFSET " . (($page - 1) * $per)) ?: [])
            : [];

        $orderIds = []; $reqIds = [];
        foreach ($rows as $e) { if ($e['kind'] === 'order') $orderIds[] = (int) $e['ref_id']; else $reqIds[] = (int) $e['ref_id']; }

        $orders = []; $members = [];
        if ($orderIds) {
            $in = implode(',', $orderIds);
            foreach (db_fetch_array("SELECT * FROM pr_orders WHERE id IN ($in)") ?: [] as $o) $orders[(int) $o['id']] = $o;
            // Các đề nghị thuộc từng phiếu (+ số dòng còn lại sau khi admin gỡ bớt) để hiện "Gồm: DN-… · tên".
            $mrows  = db_fetch_array("SELECT id, code, user_id, order_id, note FROM pr_requests
                                      WHERE order_id IN ($in) AND status = 'ordered' ORDER BY id ASC") ?: [];
            $counts = [];
            if ($mrows) {
                $mids = implode(',', array_map(static fn($r) => (int) $r['id'], $mrows));
                foreach (db_fetch_array("SELECT request_id, COUNT(*) AS c FROM pr_request_lines
                                         WHERE request_id IN ($mids) AND dropped = 0 GROUP BY request_id") ?: [] as $c) {
                    $counts[(int) $c['request_id']] = (int) $c['c'];
                }
            }
            foreach ($mrows as $r) {
                $members[(int) $r['order_id']][] = [
                    'id' => (int) $r['id'], 'code' => (string) $r['code'], 'user_name' => pr_user_name($r['user_id']),
                    'line_count' => $counts[(int) $r['id']] ?? 0,
                    'note' => (string) ($r['note'] ?? ''),          // ghi chú chung của đề nghị → in lên phiếu khi xem lại
                ];
            }
        }
        $reqs = [];
        if ($reqIds) foreach (pr_requests_fetch('id IN (' . implode(',', $reqIds) . ')', count($reqIds)) as $r) $reqs[(int) $r['id']] = $r;

        $entries = [];
        foreach ($rows as $e) {
            $id = (int) $e['ref_id'];
            if ($e['kind'] === 'order' && isset($orders[$id])) {
                $entries[] = ['kind' => 'order', 'order' => pr_order_out($orders[$id], $members[$id] ?? [])];
            } elseif ($e['kind'] === 'request' && isset($reqs[$id])) {
                $entries[] = ['kind' => 'request', 'request' => $reqs[$id]];
            }
        }
        return ['entries' => $entries, 'requests' => [], 'total' => $total, 'page' => $page, 'per_page' => $per, 'pages' => $pages];
    }

    /**
     * Dữ liệu 1 PHIẾU GOM để hiện lại: danh sách đã gom ĐÓNG BĂNG lúc xác nhận (pr_orders.snapshot_json) + các đề nghị trong phiếu.
     * Hình của món lấy theo hình HIỆN TẠI của hạng mục (hình lưu lúc gom có thể đã bị thay/xóa → ô hình vỡ khi xem lại phiếu).
     */
    function pr_order_out($o, array $members)
    {
        $lines = json_decode((string) ($o['snapshot_json'] ?? ''), true);
        if (!is_array($lines)) $lines = [];
        $ids = array_values(array_unique(array_filter(array_map(static fn($l) => (int) ($l['item_id'] ?? 0), $lines))));
        $img = [];
        if ($ids) {
            foreach (db_fetch_array("SELECT id, image_file FROM pr_items WHERE id IN (" . implode(',', $ids) . ")") ?: [] as $r) {
                $img[(int) $r['id']] = pr_image_url($r['image_file'] ?? '');
            }
        }
        $out = [];
        foreach ($lines as $l) {
            $iid = (int) ($l['item_id'] ?? 0);
            $out[] = [
                'category_id'   => (int) ($l['category_id'] ?? 0),
                'category_name' => (string) ($l['category_name'] ?? ''),
                'item_id'       => $iid,
                'item_name'     => (string) ($l['item_name'] ?? ''),
                'qty'           => (float) ($l['qty'] ?? 0),
                'qty_text'      => (string) ($l['qty_text'] ?? pr_qty_fmt($l['qty'] ?? 0)),
                'unit'          => (string) ($l['unit'] ?? ''),
                'note'          => (string) ($l['note'] ?? ''),
                'image_url'     => $iid > 0 ? ($img[$iid] ?? '') : pr_image_url($l['image_url'] ?? ''),
            ];
        }
        $t = strtotime((string) $o['created_at']);
        return [
            'id'            => (int) $o['id'],
            'created_at'    => (string) $o['created_at'],
            'date_text'     => pr_dmy($o['created_at']),
            'time_text'     => $t ? date('H:i', $t) : '',
            'by_name'       => pr_user_name($o['created_by']),
            'request_count' => $members ? count($members) : (int) $o['request_count'],
            'line_count'    => count($out),
            'requests'      => $members,
            'lines'         => $out,
        ];
    }

    /** Số liệu cho huy hiệu trên tab. */
    function pr_counts($user_id, $is_approver)
    {
        pr_ensure_tables();
        $uid = (int) $user_id;
        $mine = (int) (db_fetch_row("SELECT COUNT(*) AS c FROM pr_requests WHERE user_id = $uid AND status IN ('draft','rejected')")['c'] ?? 0);
        $out = ['mine_open' => $mine, 'review' => 0];
        if ($is_approver) {
            $out['review'] = (int) (db_fetch_row("SELECT COUNT(*) AS c FROM pr_requests WHERE status = 'submitted'")['c'] ?? 0);
        }
        return $out;
    }

    /* ============================================================
     *  ĐỀ NGHỊ — GHI
     * ============================================================ */

    function pr_next_code()
    {
        $d = date('Ymd');
        // Lấy SỐ LỚN NHẤT trong ngày + 1 (không đếm số dòng: xóa 1 đơn nháp giữa chừng sẽ làm mã mới trùng mã đã có).
        // 'DN-YYYYMMDD-' dài 12 ký tự → phần số bắt đầu ở vị trí 13.
        $r = db_fetch_row("SELECT MAX(CAST(SUBSTRING(code, 13) AS UNSIGNED)) AS m FROM pr_requests WHERE code LIKE 'DN-$d-%'");
        return 'DN-' . $d . '-' . str_pad((string) ((int) ($r['m'] ?? 0) + 1), 3, '0', STR_PAD_LEFT);
    }

    /**
     * Chuẩn hoá các dòng client gửi lên. Dòng thuộc DANH SÁCH: tên + mục chi lấy từ DB (không tin client).
     * Dòng "Đề xuất khác": tên tự do + mục chi tuỳ chọn. $strict (khi GỬI): bắt buộc SL > 0 và đơn vị.
     * Trả ['ok'=>true,'lines'=>[...]] | ['ok'=>false,'message'].
     */
    function pr_normalize_lines($raw, $strict)
    {
        if (!is_array($raw) || !$raw) return ['ok' => false, 'message' => 'Chưa có mục nào trong đề nghị.'];
        $out = [];
        $seen = [];
        foreach ($raw as $i => $l) {
            if (!is_array($l)) continue;
            $item_id = (int) ($l['item_id'] ?? 0);
            $qty     = round((float) str_replace(',', '.', (string) ($l['qty'] ?? 0)), 2);
            $unit    = pr_clean($l['unit'] ?? '', 40);
            $note    = pr_clean($l['note'] ?? '', 255);

            if ($item_id > 0) {
                $it = pr_item_get($item_id);
                if (!$it) return ['ok' => false, 'message' => 'Hạng mục "' . pr_clean($l['name'] ?? '', 80) . '" đã bị gỡ khỏi danh sách — hãy bỏ dòng này.'];
                if (isset($seen['i' . $item_id])) return ['ok' => false, 'message' => 'Hạng mục "' . $it['name'] . '" bị chọn 2 lần — hãy gộp thành 1 dòng.'];
                $seen['i' . $item_id] = true;
                $name = (string) $it['name'];
                $cid  = (int) $it['category_id'];
                $cname = (string) $it['category_name'];
            } else {
                $name = pr_clean($l['name'] ?? '', 255);
                if ($name === '') return ['ok' => false, 'message' => 'Dòng "Đề xuất khác" chưa nhập tên.'];
                $cid = (int) ($l['category_id'] ?? 0);
                $cat = $cid > 0 ? pr_category_get($cid) : null;
                $cid = $cat ? (int) $cat['id'] : 0;
                $cname = $cat ? (string) $cat['name'] : 'Đề xuất khác';
            }

            if ($strict) {
                if ($qty <= 0)   return ['ok' => false, 'message' => 'Nhập số lượng cho "' . $name . '".'];
                if ($unit === '') return ['ok' => false, 'message' => 'Nhập đơn vị cho "' . $name . '".'];
            }
            if ($qty < 0) $qty = 0;
            if ($qty > 9999999) return ['ok' => false, 'message' => 'Số lượng của "' . $name . '" quá lớn.'];

            $out[] = ['item_id' => $item_id > 0 ? $item_id : null, 'item_name' => $name,
                      'category_id' => $cid > 0 ? $cid : null, 'category_name' => $cname,
                      'qty' => $qty, 'unit' => $unit, 'note' => ($note !== '' ? $note : null), 'sort_order' => count($out) + 1];
        }
        if (!$out) return ['ok' => false, 'message' => 'Chưa có mục nào trong đề nghị.'];
        return ['ok' => true, 'lines' => $out];
    }

    /**
     * Lưu đơn đề nghị của $user. $payload: id (0 = mới), is_urgent, note, lines[]. $submit = gửi luôn.
     * Chỉ chủ đơn mới sửa, và chỉ khi đơn đang 'draft' hoặc 'rejected'.
     * Trả ['ok','request'] | ['ok'=>false,'message'].
     */
    function pr_request_save($user, array $payload, $submit)
    {
        pr_ensure_tables();
        $uid = (int) ($user['id'] ?? 0);
        if ($uid <= 0) return ['ok' => false, 'message' => 'Chưa đăng nhập.'];

        $id  = (int) ($payload['id'] ?? 0);
        $cur = null;
        if ($id > 0) {
            $cur = db_fetch_row("SELECT * FROM pr_requests WHERE id = $id LIMIT 1");
            if (!$cur || (int) $cur['user_id'] !== $uid) return ['ok' => false, 'message' => 'Không tìm thấy đề nghị của bạn.'];
            if (!in_array($cur['status'], ['draft', 'rejected'], true)) {
                return ['ok' => false, 'message' => $cur['status'] === 'ordered' ? 'Đề nghị đã được đặt mua — không sửa được.' : ($cur['status'] === 'cancelled' ? 'Đề nghị đã hủy — hãy lập đề nghị mới.' : 'Đề nghị đang chờ duyệt — không sửa được. Hãy hủy rồi lập lại nếu cần.')];
            }
        }

        $norm = pr_normalize_lines($payload['lines'] ?? [], (bool) $submit);
        if (empty($norm['ok'])) return $norm;

        $now  = pr_now();
        $note = pr_clean($payload['note'] ?? '', 500);
        $head = [
            'is_urgent'  => !empty($payload['is_urgent']) ? 1 : 0,
            'note'       => $note !== '' ? $note : null,
            'updated_at' => $now,
            // Sửa lại đơn từng bị từ chối: xoá dấu quyết định cũ để không hiện lý do/ngày của lần trước.
            'decided_at' => null,
            'decided_by' => 0,
        ];
        if ($submit) {
            $head['status'] = 'submitted';
            $head['submitted_at'] = $now;
            $head['reject_reason'] = null;
        } else {
            $head['status'] = 'draft';           // lưu nháp lại một đơn bị từ chối = quay về nháp
            $head['reject_reason'] = null;
        }

        if ($id > 0) {
            db_update('pr_requests', $head, "id = $id");
            db_delete('pr_request_lines', "request_id = $id");
            $code = (string) $cur['code'];
        } else {
            $code = pr_next_code();
            $id = (int) db_insert('pr_requests', array_merge($head, [
                'code' => $code, 'user_id' => $uid, 'created_at' => $now,
            ]));
            if ($id <= 0) return ['ok' => false, 'message' => 'Không lưu được đề nghị.'];
        }
        foreach ($norm['lines'] as $ln) {
            db_insert('pr_request_lines', array_merge($ln, ['request_id' => $id, 'qty_requested' => $ln['qty'], 'dropped' => 0]));
        }

        if ($submit) {
            $who = pr_user_name($uid);
            pr_notify(
                pr_approver_ids(),
                'Đề nghị mua đồ mới' . (!empty($payload['is_urgent']) ? ' (GẤP)' : ''),
                $who . ' vừa gửi đề nghị ' . $code . ' gồm ' . count($norm['lines']) . ' mục. Mở để duyệt.',
                'review',
                $uid
            );
        }
        return ['ok' => true, 'request' => pr_request_get($id)];
    }

    /** Chủ đơn tự HỦY đơn đã gửi (chưa đặt mua). */
    function pr_request_cancel($user, $id)
    {
        pr_ensure_tables();
        $id = (int) $id;
        $r = db_fetch_row("SELECT * FROM pr_requests WHERE id = $id LIMIT 1");
        if (!$r || (int) $r['user_id'] !== (int) ($user['id'] ?? 0)) return ['ok' => false, 'message' => 'Không tìm thấy đề nghị của bạn.'];
        if ($r['status'] !== 'submitted') return ['ok' => false, 'message' => 'Chỉ hủy được đề nghị đang chờ duyệt.'];
        db_update('pr_requests', ['status' => 'cancelled', 'updated_at' => pr_now()], "id = $id AND status = 'submitted'");
        return ['ok' => true];
    }

    /** Chủ đơn xoá hẳn đơn nháp / bị từ chối / đã hủy. */
    function pr_request_delete($user, $id)
    {
        pr_ensure_tables();
        $id = (int) $id;
        $r = db_fetch_row("SELECT * FROM pr_requests WHERE id = $id LIMIT 1");
        if (!$r || (int) $r['user_id'] !== (int) ($user['id'] ?? 0)) return ['ok' => false, 'message' => 'Không tìm thấy đề nghị của bạn.'];
        if (!in_array($r['status'], ['draft', 'rejected', 'cancelled'], true)) {
            return ['ok' => false, 'message' => 'Đề nghị đã gửi hoặc đã đặt mua — không xóa được.'];
        }
        db_delete('pr_request_lines', "request_id = $id");
        db_delete('pr_requests', "id = $id");
        return ['ok' => true];
    }

    /** Admin TỪ CHỐI (trả lại) đề nghị chờ duyệt, bắt buộc lý do; báo chuông cho người đề nghị. */
    function pr_request_reject($user, $id, $reason)
    {
        pr_ensure_tables();
        if (!pr_is_approver($user)) return ['ok' => false, 'message' => 'Bạn không có quyền duyệt đề nghị.'];
        $id = (int) $id;
        $reason = pr_clean($reason, 500);
        if ($reason === '') return ['ok' => false, 'message' => 'Hãy nhập lý do từ chối để người đề nghị biết cần sửa gì.'];
        $r = db_fetch_row("SELECT * FROM pr_requests WHERE id = $id LIMIT 1");
        if (!$r) return ['ok' => false, 'message' => 'Không tìm thấy đề nghị.'];
        if ($r['status'] !== 'submitted') return ['ok' => false, 'message' => 'Đề nghị này không còn ở trạng thái chờ duyệt.'];

        db_update('pr_requests', [
            'status' => 'rejected', 'reject_reason' => $reason, 'decided_at' => pr_now(),
            'decided_by' => (int) $user['id'], 'updated_at' => pr_now(),
        ], "id = $id AND status = 'submitted'");
        pr_notify([(int) $r['user_id']], 'Đề nghị mua đồ bị từ chối',
            'Đề nghị ' . $r['code'] . ': ' . $reason, 'mine', (int) $user['id']);
        return ['ok' => true];
    }

    /**
     * Admin XÁC NHẬN GỬI ĐẶT MUA các đơn đã gom.
     * $request_ids: các đơn đã chọn. $adjust: [ ['line_id'=>, 'qty'=>, 'dropped'=>bool], ... ] — chỉnh SL / gỡ dòng.
     * Mọi đơn phải còn 'submitted'; mỗi đơn phải còn ≥1 dòng chưa gỡ. Trả ['ok','order_id','merged'] | ['ok'=>false].
     */
    function pr_order_confirm($user, array $request_ids, array $adjust)
    {
        pr_ensure_tables();
        if (!pr_is_approver($user)) return ['ok' => false, 'message' => 'Bạn không có quyền duyệt đề nghị.'];
        $ids = array_values(array_unique(array_filter(array_map('intval', $request_ids), static fn($v) => $v > 0)));
        if (!$ids) return ['ok' => false, 'message' => 'Chưa chọn đề nghị nào.'];
        $in = implode(',', $ids);

        $reqs = db_fetch_array("SELECT * FROM pr_requests WHERE id IN ($in)") ?: [];
        if (count($reqs) !== count($ids)) return ['ok' => false, 'message' => 'Có đề nghị không còn tồn tại.'];
        foreach ($reqs as $r) {
            if ($r['status'] !== 'submitted') {
                return ['ok' => false, 'message' => 'Đề nghị ' . $r['code'] . ' không còn ở trạng thái chờ duyệt (có thể người đề nghị vừa hủy). Hãy tải lại danh sách.'];
            }
        }

        // Dựng danh sách dòng SAU chỉnh trong BỘ NHỚ trước, kiểm hợp lệ xong mới ghi — nếu không, một lần
        // bị từ chối ở bước kiểm (đơn bị gỡ hết mục) sẽ để lại cờ "đã gỡ"/SL đã đổi trên đơn CHƯA đặt mua.
        $lines = pr_lines_of($ids);                       // [request_id => [line,...]]
        $own   = [];                                       // line_id => [request_id, index]
        foreach ($lines as $rid => $ls) foreach ($ls as $i => $l) $own[$l['id']] = [$rid, $i];

        $writes = [];                                      // line_id => cột cần cập nhật
        foreach ($adjust as $a) {
            $lid = (int) ($a['line_id'] ?? 0);
            if (!isset($own[$lid])) continue;              // chỉ chạm dòng THUỘC các đơn đã chọn
            [$rid, $i] = $own[$lid];
            $upd = ['dropped' => !empty($a['dropped']) ? 1 : 0];
            $lines[$rid][$i]['dropped'] = !empty($a['dropped']);
            if (isset($a['qty'])) {
                $q = round((float) str_replace(',', '.', (string) $a['qty']), 2);
                if ($q > 0 && $q <= 9999999) {
                    $upd['qty'] = $q;
                    $lines[$rid][$i]['qty'] = $q;
                    $lines[$rid][$i]['qty_text'] = pr_qty_fmt($q);
                }
            }
            $writes[$lid] = $upd;
        }
        foreach ($reqs as $r) {
            $alive = 0;
            foreach ($lines[(int) $r['id']] ?? [] as $l) if (!$l['dropped']) $alive++;
            if ($alive === 0) {
                return ['ok' => false, 'message' => 'Đề nghị ' . $r['code'] . ' đã bị gỡ hết mục — hãy bỏ chọn đơn đó hoặc Từ chối.'];
            }
        }
        foreach ($writes as $lid => $upd) db_update('pr_request_lines', $upd, "id = " . (int) $lid);

        $merged = pr_merge_lines($lines);
        $now    = pr_now();
        $oid = (int) db_insert('pr_orders', [
            'created_by' => (int) $user['id'], 'created_at' => $now, 'request_count' => count($ids),
            'line_count' => count($merged), 'snapshot_json' => json_encode($merged),
        ]);
        db_query("UPDATE pr_requests SET status = 'ordered', order_id = $oid, decided_at = '$now',
                  decided_by = " . (int) $user['id'] . ", updated_at = '$now' WHERE id IN ($in) AND status = 'submitted'");

        // Mỗi người đề nghị nhận 1 chuông tổng hợp (không phải 1 chuông/đơn).
        $byUser = [];
        foreach ($reqs as $r) $byUser[(int) $r['user_id']][] = $r['code'];
        foreach ($byUser as $uid => $codes) {
            pr_notify([$uid], 'Đề nghị mua đồ đã được đặt mua',
                'Đề nghị ' . implode(', ', $codes) . ' đã được đặt mua.', 'mine', (int) $user['id']);
        }
        return ['ok' => true, 'order_id' => $oid, 'merged' => $merged];
    }

    /**
     * Gộp các dòng (chưa gỡ) của nhiều đơn: cộng dồn dòng cùng (hạng mục + đơn vị). Dòng "khác" gộp theo tên (không phân biệt
     * hoa thường). Trả mảng theo thứ tự mục chi → tên, mỗi phần tử {category_name, item_name, qty, qty_text, unit, note, image_url}.
     */
    function pr_merge_lines(array $lines_by_request)
    {
        $acc = [];
        foreach ($lines_by_request as $lines) {
            foreach ($lines as $l) {
                if (!empty($l['dropped'])) continue;
                $key = ($l['item_id'] > 0 ? 'i' . $l['item_id'] : 'n' . mb_strtolower($l['item_name'], 'UTF-8'))
                     . '|' . mb_strtolower($l['unit'], 'UTF-8');
                if (!isset($acc[$key])) {
                    $acc[$key] = ['category_name' => $l['category_name'], 'category_id' => $l['category_id'],
                                  'item_id' => $l['item_id'],
                                  'item_name' => $l['item_name'], 'qty' => 0.0, 'unit' => $l['unit'],
                                  'notes' => [], 'image_url' => $l['image_url']];
                }
                $acc[$key]['qty'] += (float) $l['qty'];
                if ($l['note'] !== '' && !in_array($l['note'], $acc[$key]['notes'], true)) $acc[$key]['notes'][] = $l['note'];
            }
        }
        $out = [];
        foreach ($acc as $a) {
            $out[] = ['category_name' => $a['category_name'], 'category_id' => $a['category_id'], 'item_id' => $a['item_id'],
                      'item_name' => $a['item_name'],
                      'qty' => $a['qty'], 'qty_text' => pr_qty_fmt($a['qty']), 'unit' => $a['unit'],
                      'note' => implode('; ', $a['notes']), 'image_url' => $a['image_url']];
        }
        // Thứ tự mục chi theo danh sách (sort_order), trong mục theo thứ tự hạng mục; dòng "Đề xuất khác"
        // (không thuộc danh sách) xuống cuối mỗi mục, xếp theo tên.
        $catOrder = [];
        foreach (pr_categories_list() as $i => $c) $catOrder[(int) $c['id']] = $i;
        usort($out, static function ($x, $y) use ($catOrder) {
            $cx = $catOrder[$x['category_id']] ?? PHP_INT_MAX;
            $cy = $catOrder[$y['category_id']] ?? PHP_INT_MAX;
            if ($cx !== $cy) return $cx <=> $cy;
            $ix = $x['item_id'] > 0 ? $x['item_id'] : PHP_INT_MAX;
            $iy = $y['item_id'] > 0 ? $y['item_id'] : PHP_INT_MAX;
            return $ix <=> $iy ?: strcasecmp($x['item_name'], $y['item_name']);
        });
        return $out;
    }
}
