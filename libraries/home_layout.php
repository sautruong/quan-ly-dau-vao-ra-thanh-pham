<?php
/**
 * home_layout — cá nhân hóa header trang chủ:
 *   - Thứ tự menu cha (kéo-thả) theo từng user  -> home_menu_order
 *   - Thư viện hình nền dùng chung do admin quản lý -> home_bg_library
 *   - Slide hình nền cá nhân của từng user (tối đa 3) -> home_bg_slides
 *
 * Cùng phong cách todos.php/system_settings.php: mọi hàm bọc guard
 * function_exists, KHÔNG autoload, tự tạo bảng qua *_ensure_tables().
 */

if (!function_exists('home_layout_ensure_tables')) {

    define('HOME_BG_MAX_SLIDES', 3);
    define('HOME_BG_LIBRARY_DIR', APPPATH . DIRECTORY_SEPARATOR . 'public' . DIRECTORY_SEPARATOR . 'images' . DIRECTORY_SEPARATOR . 'home_bg_library');
    define('HOME_BG_UPLOAD_DIR', APPPATH . DIRECTORY_SEPARATOR . 'public' . DIRECTORY_SEPARATOR . 'uploads' . DIRECTORY_SEPARATOR . 'home_bg');
    define('HOME_NAV_MAX_BAR', 8);

    /** Tạo bảng nếu chưa có (1 lần / request). */
    function home_layout_ensure_tables()
    {
        static $done = false;
        if ($done) return;
        $done = true;

        db_query("CREATE TABLE IF NOT EXISTS home_menu_order (
            id          INT(11) NOT NULL AUTO_INCREMENT,
            user_id     INT(11) NOT NULL,
            group_key   VARCHAR(191) NOT NULL,
            sort_order  INT(11) NOT NULL DEFAULT 0,
            in_bar      TINYINT(1) NOT NULL DEFAULT 1,
            PRIMARY KEY (id),
            UNIQUE KEY uniq_user_group (user_id, group_key)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci");
        // Bảng có thể đã tồn tại từ bản trước khi có cột in_bar (thanh dấu trang vs ẩn trong ">>").
        if (!db_fetch_row("SHOW COLUMNS FROM home_menu_order LIKE 'in_bar'")) {
            db_query("ALTER TABLE home_menu_order ADD in_bar TINYINT(1) NOT NULL DEFAULT 1 AFTER sort_order");
        }

        db_query("CREATE TABLE IF NOT EXISTS home_bg_library (
            id          INT(11) NOT NULL AUTO_INCREMENT,
            filename    VARCHAR(191) NOT NULL,
            added_by    INT(11) NOT NULL,
            created_at  DATETIME NOT NULL,
            PRIMARY KEY (id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci");

        db_query("CREATE TABLE IF NOT EXISTS home_bg_slides (
            id               INT(11) NOT NULL AUTO_INCREMENT,
            user_id          INT(11) NOT NULL,
            filename         VARCHAR(191) NOT NULL,
            is_from_library  TINYINT(1) NOT NULL DEFAULT 0,
            sort_order       INT(11) NOT NULL DEFAULT 0,
            created_at       DATETIME NOT NULL,
            PRIMARY KEY (id),
            KEY idx_user (user_id, sort_order)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci");

        if (!is_dir(HOME_BG_LIBRARY_DIR)) { @mkdir(HOME_BG_LIBRARY_DIR, 0775, true); }
        if (!is_dir(HOME_BG_UPLOAD_DIR))  { @mkdir(HOME_BG_UPLOAD_DIR, 0775, true); }
    }

    /* ============================================================
     *  Thứ tự menu cha
     * ============================================================ */

    /** Áp thứ tự đã lưu của user lên $menu_groups (nhóm chưa lưu -> xếp cuối, giữ thứ tự gốc). */
    function home_menu_order_apply($user_id, $menu_groups)
    {
        $uid = (int) $user_id;
        if ($uid <= 0 || !is_array($menu_groups) || empty($menu_groups)) return $menu_groups;
        home_layout_ensure_tables();

        $rows = db_fetch_array("SELECT group_key, sort_order FROM home_menu_order
                                WHERE user_id = $uid ORDER BY sort_order ASC") ?: [];
        if (empty($rows)) return $menu_groups;

        $order_map = [];
        foreach ($rows as $r) { $order_map[(string) $r['group_key']] = true; }

        $ordered = [];
        foreach ($rows as $r) {
            $k = (string) $r['group_key'];
            if (array_key_exists($k, $menu_groups)) { $ordered[$k] = $menu_groups[$k]; }
        }
        foreach ($menu_groups as $k => $v) {
            if (!isset($order_map[$k])) { $ordered[$k] = $v; }
        }
        return $ordered;
    }

    /**
     * Lưu thứ tự + trạng thái thanh dấu trang của menu cha đã kéo-thả.
     * $ordered_keys: TOÀN BỘ key đang hiển thị, thứ tự = hàng chính trước rồi tới danh sách
     * ẩn trong ">>" (đúng thứ tự DOM lúc lưu). $bar_count: số key đầu tiên thuộc hàng chính
     * (phần còn lại tự động là "ẩn trong ...").  Chỉ chấp nhận key nằm trong $allowed_keys.
     */
    function home_menu_order_save($user_id, $ordered_keys, $allowed_keys, $bar_count = null)
    {
        $uid = (int) $user_id;
        if ($uid <= 0 || !is_array($ordered_keys)) return false;
        home_layout_ensure_tables();

        if ($bar_count === null) $bar_count = HOME_NAV_MAX_BAR;
        $bar_count = max(0, (int) $bar_count);

        $allowed = array_flip($allowed_keys);
        db_delete('home_menu_order', "user_id = $uid");
        $order = 0;
        foreach ($ordered_keys as $key) {
            $key = (string) $key;
            if ($key === '' || !isset($allowed[$key])) continue;
            db_insert('home_menu_order', [
                'user_id'    => $uid,
                'group_key'  => $key,
                'sort_order' => $order,
                'in_bar'     => $order < $bar_count ? 1 : 0,
            ]);
            $order++;
        }
        return true;
    }

    /**
     * Nhóm nào thuộc thanh chính (menu cha hiện ngang) vs ẩn trong ">>".
     * Trả về map [group_key => true] cho các key thuộc thanh chính.
     * Chưa từng lưu gì -> mặc định HOME_NAV_MAX_BAR key đầu tiên (theo thứ tự hiện có) vào
     * thanh chính. Đã từng lưu -> theo đúng lựa chọn đã lưu; key MỚI chưa từng thấy (vd vừa
     * được cấp quyền) mặc định ẩn trong ">>" để không làm tràn thanh chính đã sắp xếp.
     */
    function home_menu_bar_keys($user_id, $ordered_keys_all)
    {
        $uid = (int) $user_id;
        home_layout_ensure_tables();

        $rows = ($uid > 0)
            ? (db_fetch_array("SELECT group_key, in_bar FROM home_menu_order WHERE user_id = $uid") ?: [])
            : [];

        if (empty($rows)) {
            $bar = [];
            $i = 0;
            foreach ($ordered_keys_all as $k) {
                if ($i >= HOME_NAV_MAX_BAR) break;
                $bar[$k] = true;
                $i++;
            }
            return $bar;
        }

        $saved = [];
        foreach ($rows as $r) { $saved[(string) $r['group_key']] = ((int) $r['in_bar']) === 1; }

        $bar = [];
        foreach ($ordered_keys_all as $k) {
            if (!empty($saved[$k])) { $bar[$k] = true; }
        }
        return $bar;
    }

    /* ============================================================
     *  Kiểm tra ảnh (dùng chung cho thư viện + upload cá nhân)
     * ============================================================ */

    /** [ok, ext|message] — chặn không phải ảnh, quá 4MB, hoặc không phải ảnh nằm ngang. */
    function home_bg_validate_image($file)
    {
        if (empty($file) || $file['error'] !== UPLOAD_ERR_OK) {
            return [false, 'Không nhận được tệp.'];
        }
        if ($file['size'] > 4 * 1024 * 1024) {
            return [false, 'Ảnh vượt quá 4MB.'];
        }
        $info = @getimagesize($file['tmp_name']);
        if ($info === false) {
            return [false, 'Tệp không phải hình ảnh.'];
        }
        $ext_map = [IMAGETYPE_JPEG => 'jpg', IMAGETYPE_PNG => 'png', IMAGETYPE_GIF => 'gif', IMAGETYPE_WEBP => 'webp'];
        if (!isset($ext_map[$info[2]])) {
            return [false, 'Chỉ chấp nhận JPG/PNG/GIF/WEBP.'];
        }
        $w = (int) $info[0];
        $h = (int) $info[1];
        if ($h <= 0 || ($w / $h) < 1.2) {
            return [false, 'Vui lòng chọn ảnh nằm ngang (chữ nhật ngang) để tránh vỡ hình khi làm nền.'];
        }
        return [true, $ext_map[$info[2]]];
    }

    /* ============================================================
     *  Thư viện dùng chung (admin quản lý)
     * ============================================================ */

    /** Toàn bộ ảnh trong thư viện, mới thêm lên trước. */
    function home_bg_library_list()
    {
        home_layout_ensure_tables();
        return db_fetch_array("SELECT id, filename, added_by, created_at FROM home_bg_library
                               ORDER BY id DESC") ?: [];
    }

    /** Thêm 1 ảnh vào thư viện dùng chung (chỉ gọi sau khi đã permission_is_admin() ở controller). */
    function home_bg_library_add($file, $added_by)
    {
        home_layout_ensure_tables();
        [$ok, $extOrMsg] = home_bg_validate_image($file);
        if (!$ok) return ['ok' => false, 'message' => $extOrMsg];

        $filename = 'lib_' . time() . '_' . mt_rand(1000, 9999) . '.' . $extOrMsg;
        $dest = HOME_BG_LIBRARY_DIR . DIRECTORY_SEPARATOR . $filename;
        if (!move_uploaded_file($file['tmp_name'], $dest)) {
            return ['ok' => false, 'message' => 'Không lưu được tệp.'];
        }
        db_insert('home_bg_library', [
            'filename'   => $filename,
            'added_by'   => (int) $added_by,
            'created_at' => date('Y-m-d H:i:s'),
        ]);
        return ['ok' => true];
    }

    /** Xóa 1 ảnh khỏi thư viện: xóa file vật lý + xóa mọi slide cá nhân đang tham chiếu nó. */
    function home_bg_library_remove($id)
    {
        home_layout_ensure_tables();
        $id = (int) $id;
        $row = db_fetch_row("SELECT filename FROM home_bg_library WHERE id = $id");
        if (!$row) return false;
        $filename = (string) $row['filename'];

        db_delete('home_bg_slides', "is_from_library = 1 AND filename = '" . escape_string($filename) . "'");
        db_delete('home_bg_library', "id = $id");

        $path = HOME_BG_LIBRARY_DIR . DIRECTORY_SEPARATOR . basename($filename);
        if (is_file($path)) { @unlink($path); }
        return true;
    }

    /* ============================================================
     *  Slide hình nền cá nhân (tối đa HOME_BG_MAX_SLIDES)
     * ============================================================ */

    /** Slide của 1 user, có kèm url đã resolve theo thư mục nguồn (thư viện / cá nhân). */
    function home_bg_user_slides($user_id)
    {
        home_layout_ensure_tables();
        $uid = (int) $user_id;
        $rows = db_fetch_array("SELECT id, filename, is_from_library, sort_order FROM home_bg_slides
                                WHERE user_id = $uid ORDER BY sort_order ASC") ?: [];
        $out = [];
        foreach ($rows as $r) {
            $lib = !empty($r['is_from_library']);
            $out[] = [
                'id'       => (int) $r['id'],
                'filename' => (string) $r['filename'],
                'from_lib' => $lib,
                'url'      => ($lib ? 'public/images/home_bg_library/' : 'public/uploads/home_bg/') . rawurlencode((string) $r['filename']),
            ];
        }
        return $out;
    }

    /** Thêm 1 slide từ thư viện dùng chung (tối đa HOME_BG_MAX_SLIDES). */
    function home_bg_slide_add_from_library($user_id, $library_id)
    {
        home_layout_ensure_tables();
        $uid = (int) $user_id;
        $lib = db_fetch_row("SELECT filename FROM home_bg_library WHERE id = " . (int) $library_id);
        if (!$lib) return ['ok' => false, 'message' => 'Ảnh thư viện không tồn tại.'];

        $count = (int) db_num_rows("SELECT 1 FROM home_bg_slides WHERE user_id = $uid");
        if ($count >= HOME_BG_MAX_SLIDES) {
            return ['ok' => false, 'message' => 'Chỉ được chọn tối đa ' . HOME_BG_MAX_SLIDES . ' hình.'];
        }
        db_insert('home_bg_slides', [
            'user_id'         => $uid,
            'filename'        => (string) $lib['filename'],
            'is_from_library' => 1,
            'sort_order'      => $count,
            'created_at'      => date('Y-m-d H:i:s'),
        ]);
        return ['ok' => true, 'slides' => home_bg_user_slides($uid)];
    }

    /** Tải ảnh cá nhân từ máy tính, thêm làm slide (tối đa HOME_BG_MAX_SLIDES). */
    function home_bg_slide_upload($user_id, $file)
    {
        home_layout_ensure_tables();
        $uid = (int) $user_id;
        $count = (int) db_num_rows("SELECT 1 FROM home_bg_slides WHERE user_id = $uid");
        if ($count >= HOME_BG_MAX_SLIDES) {
            return ['ok' => false, 'message' => 'Chỉ được chọn tối đa ' . HOME_BG_MAX_SLIDES . ' hình.'];
        }
        [$ok, $extOrMsg] = home_bg_validate_image($file);
        if (!$ok) return ['ok' => false, 'message' => $extOrMsg];

        $filename = 'u' . $uid . '_' . time() . '_' . mt_rand(1000, 9999) . '.' . $extOrMsg;
        $dest = HOME_BG_UPLOAD_DIR . DIRECTORY_SEPARATOR . $filename;
        if (!move_uploaded_file($file['tmp_name'], $dest)) {
            return ['ok' => false, 'message' => 'Không lưu được tệp.'];
        }
        db_insert('home_bg_slides', [
            'user_id'         => $uid,
            'filename'        => $filename,
            'is_from_library' => 0,
            'sort_order'      => $count,
            'created_at'      => date('Y-m-d H:i:s'),
        ]);
        return ['ok' => true, 'slides' => home_bg_user_slides($uid)];
    }

    /** Xóa 1 slide của user (xóa file vật lý nếu là ảnh cá nhân, không đụng thư viện dùng chung). */
    function home_bg_slide_remove($user_id, $slide_id)
    {
        home_layout_ensure_tables();
        $uid = (int) $user_id;
        $sid = (int) $slide_id;
        $row = db_fetch_row("SELECT filename, is_from_library FROM home_bg_slides WHERE id = $sid AND user_id = $uid");
        if (!$row) return ['ok' => false, 'message' => 'Không tìm thấy hình.'];

        db_delete('home_bg_slides', "id = $sid AND user_id = $uid");
        if (empty($row['is_from_library'])) {
            $path = HOME_BG_UPLOAD_DIR . DIRECTORY_SEPARATOR . basename((string) $row['filename']);
            if (is_file($path)) { @unlink($path); }
        }

        // Nén lại sort_order cho liền mạch.
        $remain = db_fetch_array("SELECT id FROM home_bg_slides WHERE user_id = $uid ORDER BY sort_order ASC") ?: [];
        $order = 0;
        foreach ($remain as $r) {
            db_update('home_bg_slides', ['sort_order' => $order], 'id = ' . (int) $r['id']);
            $order++;
        }
        return ['ok' => true, 'slides' => home_bg_user_slides($uid)];
    }

    /** Kéo-thả đổi thứ tự slideshow (POST ids[] theo thứ tự mới). */
    function home_bg_slide_reorder($user_id, $ids)
    {
        home_layout_ensure_tables();
        $uid = (int) $user_id;
        if (!is_array($ids)) return ['ok' => false];
        $order = 0;
        foreach ($ids as $id) {
            $sid = (int) $id;
            if ($sid <= 0) continue;
            db_update('home_bg_slides', ['sort_order' => $order], "id = $sid AND user_id = $uid");
            $order++;
        }
        return ['ok' => true, 'slides' => home_bg_user_slides($uid)];
    }
}
