<?php
/**
 * =====================================================================
 *  QUẢN LÝ FILE (file_management) — Model
 * =====================================================================
 *  Mục tiêu: mỗi user có "kho file" cá nhân hóa (tải lên / tải xuống /
 *  đổi tên / xóa / tạo bản sao / gắn sao), KHÔNG ai xem được file của
 *  người khác trừ khi được chia sẻ.
 *
 *  Cấu trúc:
 *   - fm_folders : cây thư mục. parent_id = 0  => "nhóm file" (card dọc).
 *                  parent_id > 0  => thư mục con (cấp con, cấp con nữa...).
 *   - fm_files   : file thực tế, thuộc 1 thư mục (folder_id) hoặc 0 (gốc).
 *   - fm_shares  : chia sẻ file/thư mục giữa các user.
 *                  status: pending | accepted | rejected | revoked.
 *                  in_my_library = 1  => người nhận đã "đưa vào Thư viện
 *                  của tôi" → chủ sở hữu gỡ chia sẻ KHÔNG còn tác dụng
 *                  (logic giống Google Drive).
 *
 *  Tên file/thư mục mã hóa bằng libraries/crypto.php (khóa CHUNG → người
 *  được chia sẻ vẫn giải mã hiển thị được).
 *
 *  Tích hợp: chia sẻ 1 file trực tiếp vào chat (libraries/chat.php) và
 *  vào 1 session của 1 dự án (modules/project_management).
 *
 *  Prefix hàm: fm_*.
 * =====================================================================
 */

require_once __DIR__ . '/../../../libraries/crypto.php';
require_once __DIR__ . '/../../../libraries/notifications.php';
require_once __DIR__ . '/../../../libraries/system_settings.php'; // giới hạn dung lượng (Cài đặt hệ thống)

/* ============================================================
 *  Khởi tạo bảng (idempotent)
 * ============================================================ */
function fm_ensure_tables()
{
    static $done = false;
    if ($done) return;
    $done = true;

    db_query("CREATE TABLE IF NOT EXISTS fm_folders (
        id INT AUTO_INCREMENT PRIMARY KEY,
        owner_id   INT NOT NULL,
        parent_id  INT NOT NULL DEFAULT 0,
        name       TEXT DEFAULT NULL,
        color      VARCHAR(20) DEFAULT NULL,
        starred    TINYINT(1) NOT NULL DEFAULT 0,
        sort_order INT NOT NULL DEFAULT 0,
        created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        KEY idx_owner_parent (owner_id, parent_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci");

    db_query("CREATE TABLE IF NOT EXISTS fm_files (
        id INT AUTO_INCREMENT PRIMARY KEY,
        owner_id      INT NOT NULL,
        folder_id     INT NOT NULL DEFAULT 0,
        stored_name   VARCHAR(255) NOT NULL,
        original_name TEXT DEFAULT NULL,
        mime          VARCHAR(150) DEFAULT NULL,
        size          BIGINT NOT NULL DEFAULT 0,
        ext           VARCHAR(20) DEFAULT NULL,
        is_image      TINYINT(1) NOT NULL DEFAULT 0,
        starred       TINYINT(1) NOT NULL DEFAULT 0,
        sort_order    INT NOT NULL DEFAULT 0,
        source_file_id INT NOT NULL DEFAULT 0,
        created_at    TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        updated_at    TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        KEY idx_owner_folder (owner_id, folder_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci");

    db_query("CREATE TABLE IF NOT EXISTS fm_shares (
        id INT AUTO_INCREMENT PRIMARY KEY,
        item_type     VARCHAR(10) NOT NULL,
        item_id       INT NOT NULL,
        owner_id      INT NOT NULL,
        shared_by     INT NOT NULL,
        shared_with   INT NOT NULL,
        status        VARCHAR(12) NOT NULL DEFAULT 'pending',
        in_my_library TINYINT(1) NOT NULL DEFAULT 0,
        alias         TEXT DEFAULT NULL,
        created_at    TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        responded_at  DATETIME DEFAULT NULL,
        UNIQUE KEY uniq_share (item_type, item_id, shared_with),
        KEY idx_with (shared_with, status),
        KEY idx_owner (owner_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci");

    // Bộ đếm click cho danh sách "File hay dùng" (mỗi lần mở file +1).
    if (!db_fetch_row("SHOW COLUMNS FROM fm_files LIKE 'click_count'")) {
        db_query("ALTER TABLE fm_files ADD COLUMN click_count INT NOT NULL DEFAULT 0 AFTER sort_order");
    }

    // Mốc "đụng đến" gần nhất (mở xem/tải xuống) — dùng cho bộ lọc "chưa đụng N tháng".
    if (!db_fetch_row("SHOW COLUMNS FROM fm_files LIKE 'last_opened_at'")) {
        db_query("ALTER TABLE fm_files ADD COLUMN last_opened_at DATETIME DEFAULT NULL AFTER click_count");
    }
}

/** Đánh dấu 1 file vừa được mở xem/tải xuống (cho bộ lọc "chưa đụng N tháng"). */
function fm_touch_opened($file_id)
{
    $file_id = (int) $file_id;
    if ($file_id <= 0) return;
    db_update('fm_files', ['last_opened_at' => date('Y-m-d H:i:s')], "id = $file_id");
}

/** Đăng ký view vào menu "Tiện ích" (idempotent, tự sửa nếu đã lỡ đăng ký nhóm cũ). */
function fm_ensure_view_registered()
{
    if (db_num_rows("SHOW TABLES LIKE 'tbl_views'") <= 0) return;
    db_query("INSERT IGNORE INTO tbl_views (module, controller, action, label, group_label, sort)
              VALUES ('file_management','file_management','file_manager','Quản lý file','Tiện ích', 120)");
    db_query("UPDATE tbl_views SET group_label = 'Tiện ích', sort = 120
              WHERE module = 'file_management' AND controller = 'file_management' AND action = 'file_manager'");
}

/* ============================================================
 *  Helpers
 * ============================================================ */
function fm_uid()
{
    if (!function_exists('permission_current_user')) return 0;
    $u = permission_current_user();
    return (int) ($u['id'] ?? 0);
}

function fm_enc($s) { return crypto_encrypt((string) $s); }
function fm_dec($s) { return crypto_decrypt((string) $s); }

/** Thư mục lưu file vật lý. */
function fm_upload_dir()
{
    $dir = APPPATH . DIRECTORY_SEPARATOR . 'public' . DIRECTORY_SEPARATOR
         . 'uploads' . DIRECTORY_SEPARATOR . 'file_manager';
    if (!is_dir($dir)) @mkdir($dir, 0775, true);
    return $dir;
}

/** URL public của 1 file đã lưu. */
function fm_file_url($stored_name)
{
    return 'public/uploads/file_manager/' . $stored_name;
}

/** Nhóm loại file để chọn icon hiển thị. */
function fm_kind($ext, $mime = '')
{
    $ext = strtolower((string) $ext);
    $img = ['jpg', 'jpeg', 'png', 'gif', 'webp', 'bmp', 'svg', 'heic'];
    $doc = ['doc', 'docx', 'odt', 'rtf'];
    $xls = ['xls', 'xlsx', 'csv', 'ods'];
    $ppt = ['ppt', 'pptx', 'odp'];
    $vid = ['mp4', 'mov', 'avi', 'mkv', 'webm', 'flv'];
    $aud = ['mp3', 'wav', 'ogg', 'm4a', 'flac'];
    $zip = ['zip', 'rar', '7z', 'tar', 'gz'];
    if (in_array($ext, $img, true) || strpos((string) $mime, 'image/') === 0) return 'image';
    if ($ext === 'pdf') return 'pdf';
    if (in_array($ext, $doc, true)) return 'word';
    if (in_array($ext, $xls, true)) return 'excel';
    if (in_array($ext, $ppt, true)) return 'ppt';
    if (in_array($ext, $vid, true)) return 'video';
    if (in_array($ext, $aud, true)) return 'audio';
    if (in_array($ext, $zip, true)) return 'archive';
    if (in_array($ext, ['txt', 'md', 'log'], true)) return 'text';
    return 'file';
}

function fm_human_size($bytes)
{
    $bytes = (float) $bytes;
    $u = ['B', 'KB', 'MB', 'GB', 'TB'];
    $i = 0;
    while ($bytes >= 1024 && $i < count($u) - 1) { $bytes /= 1024; $i++; }
    return ($i === 0 ? (int) $bytes : number_format($bytes, $bytes < 10 ? 1 : 0)) . ' ' . $u[$i];
}

/* ============================================================
 *  Lưu file upload (giống chat_store_upload)
 * ============================================================ */
function fm_store_upload($file, $unlimited_size = false)
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
    if (!$unlimited_size && $file['size'] > 50 * 1024 * 1024) {
        return ['ok' => false, 'name' => $orig, 'reason' => 'vượt 50MB'];
    }

    $ext = strtolower(pathinfo($orig, PATHINFO_EXTENSION));
    $blocked = ['php', 'php3', 'php4', 'php5', 'phtml', 'phar', 'exe', 'sh', 'bat', 'cmd', 'htaccess'];
    if (in_array($ext, $blocked, true)) {
        return ['ok' => false, 'name' => $orig, 'reason' => 'định dạng .' . $ext . ' không được phép'];
    }

    $dir = fm_upload_dir();
    $info = @getimagesize($file['tmp_name']);
    $is_image = $info !== false ? 1 : 0;
    $safe_ext = preg_replace('/[^a-z0-9]/', '', $ext);
    $filename = 'f' . time() . '_' . substr(md5($orig . uniqid('', true)), 0, 10)
              . ($safe_ext !== '' ? '.' . $safe_ext : '');
    $dest = $dir . DIRECTORY_SEPARATOR . $filename;
    if (!move_uploaded_file($file['tmp_name'], $dest)) {
        return ['ok' => false, 'name' => $orig, 'reason' => 'không lưu được tệp'];
    }

    return [
        'ok'            => true,
        'stored_name'   => $filename,
        'original_name' => mb_substr($orig, 0, 250, 'UTF-8'),
        'mime'          => (string) ($file['type'] ?? ''),
        'size'          => (int) $file['size'],
        'ext'           => $safe_ext,
        'is_image'      => $is_image,
    ];
}

/** Tạo bản sao vật lý của 1 file đã lưu, trả stored_name mới (hoặc '' nếu lỗi). */
function fm_duplicate_blob($stored_name, $ext)
{
    $src = fm_upload_dir() . DIRECTORY_SEPARATOR . $stored_name;
    if (!is_file($src)) return '';
    $safe_ext = preg_replace('/[^a-z0-9]/', '', (string) $ext);
    $new = 'f' . time() . '_' . substr(md5($stored_name . uniqid('', true)), 0, 10)
         . ($safe_ext !== '' ? '.' . $safe_ext : '');
    $dest = fm_upload_dir() . DIRECTORY_SEPARATOR . $new;
    return @copy($src, $dest) ? $new : '';
}

/* ============================================================
 *  Đọc 1 dòng (đã giải mã tên)
 * ============================================================ */
function fm_file_row($id)
{
    $id = (int) $id;
    $r = db_fetch_row("SELECT * FROM fm_files WHERE id = $id LIMIT 1");
    return $r ? fm_format_file($r) : null;
}

function fm_folder_row($id)
{
    $id = (int) $id;
    $r = db_fetch_row("SELECT * FROM fm_folders WHERE id = $id LIMIT 1");
    return $r ? fm_format_folder($r) : null;
}

function fm_format_file($r)
{
    $name = fm_dec($r['original_name'] ?? '');
    return [
        'type'       => 'file',
        'id'         => (int) $r['id'],
        'owner_id'   => (int) $r['owner_id'],
        'folder_id'  => (int) $r['folder_id'],
        'name'       => $name,
        'ext'        => (string) ($r['ext'] ?? ''),
        'mime'       => (string) ($r['mime'] ?? ''),
        'size'       => (int) $r['size'],
        'size_human' => fm_human_size($r['size']),
        'is_image'   => (int) $r['is_image'] === 1,
        'kind'       => fm_kind($r['ext'] ?? '', $r['mime'] ?? ''),
        'starred'    => (int) $r['starred'] === 1,
        'sort_order' => (int) $r['sort_order'],
        'click_count' => (int) ($r['click_count'] ?? 0),
        'url'        => fm_file_url($r['stored_name']),
        'created_at' => $r['created_at'],
    ];
}

function fm_format_folder($r)
{
    return [
        'type'       => 'folder',
        'id'         => (int) $r['id'],
        'owner_id'   => (int) $r['owner_id'],
        'parent_id'  => (int) $r['parent_id'],
        'is_group'   => (int) $r['parent_id'] === 0,
        'name'       => fm_dec($r['name'] ?? ''),
        'color'      => (string) ($r['color'] ?? ''),
        'starred'    => (int) $r['starred'] === 1,
        'sort_order' => (int) $r['sort_order'],
        'created_at' => $r['created_at'],
    ];
}

/* ============================================================
 *  Cây thư mục của CHÍNH MÌNH (My Library)
 * ============================================================ */
function fm_build_tree($owner, $parent_id)
{
    $owner = (int) $owner;
    $parent_id = (int) $parent_id;
    $folders = db_fetch_array(
        "SELECT * FROM fm_folders WHERE owner_id = $owner AND parent_id = $parent_id
         ORDER BY sort_order ASC, id ASC"
    ) ?: [];
    $out = [];
    foreach ($folders as $f) {
        $node = fm_format_folder($f);
        $node['folders'] = fm_build_tree($owner, (int) $f['id']);
        $node['files']   = fm_files_in($owner, (int) $f['id']);
        $out[] = $node;
    }
    return $out;
}

function fm_files_in($owner, $folder_id)
{
    $owner = (int) $owner;
    $folder_id = (int) $folder_id;
    $rows = db_fetch_array(
        "SELECT * FROM fm_files WHERE owner_id = $owner AND folder_id = $folder_id
         ORDER BY sort_order ASC, id ASC"
    ) ?: [];
    return array_map('fm_format_file', $rows);
}

/** Toàn bộ dữ liệu cho trang: thư viện của tôi + được chia sẻ với tôi. */
function fm_my_library($owner)
{
    $owner = (int) $owner;
    // Mục được chia sẻ mà chính $owner đã bấm "Vào thư viện của tôi" (in_my_library=1)
    // → phải hiện trong tab "Thư viện của tôi", không chỉ giữ quyền truy cập ngầm.
    $shared_saved = array_values(array_filter(
        fm_shared_entries_for($owner),
        static function ($e) { return $e['in_my_library']; }
    ));
    return [
        'groups'       => fm_build_tree($owner, 0),   // các nhóm (card dọc) + cây con
        'root_files'   => fm_files_in($owner, 0),     // file ngoài nhóm
        'shared_saved' => $shared_saved,              // mục chia sẻ đã lưu vào thư viện
    ];
}

/* ============================================================
 *  Quyền truy cập (chia sẻ)
 * ============================================================ */

/** Tập id thư mục (của owner) mà $uid được chia sẻ trực tiếp & còn hiệu lực. */
function fm_shared_folder_roots($uid)
{
    $uid = (int) $uid;
    $rows = db_fetch_array(
        "SELECT item_id FROM fm_shares
         WHERE item_type = 'folder' AND shared_with = $uid
           AND (status = 'accepted' OR in_my_library = 1)"
    ) ?: [];
    return array_map(static function ($r) { return (int) $r['item_id']; }, $rows);
}

/** Tập id file được chia sẻ trực tiếp & còn hiệu lực. */
function fm_shared_file_ids($uid)
{
    $uid = (int) $uid;
    $rows = db_fetch_array(
        "SELECT item_id FROM fm_shares
         WHERE item_type = 'file' AND shared_with = $uid
           AND (status = 'accepted' OR in_my_library = 1)"
    ) ?: [];
    return array_map(static function ($r) { return (int) $r['item_id']; }, $rows);
}

/** Đường dẫn tổ tiên (mảng id) của 1 thư mục, gồm chính nó. */
function fm_folder_ancestry($folder_id)
{
    $chain = [];
    $cur = (int) $folder_id;
    $guard = 0;
    while ($cur > 0 && $guard++ < 100) {
        $chain[] = $cur;
        $r = db_fetch_row("SELECT parent_id FROM fm_folders WHERE id = $cur LIMIT 1");
        if (!$r) break;
        $cur = (int) $r['parent_id'];
    }
    return $chain;
}

/** $uid có quyền xem thư mục $folder_id không (chủ sở hữu hoặc nằm trong nhánh được chia sẻ). */
function fm_can_access_folder($uid, $folder_id)
{
    $uid = (int) $uid;
    $folder_id = (int) $folder_id;
    if ($folder_id <= 0) return false;
    $f = db_fetch_row("SELECT owner_id FROM fm_folders WHERE id = $folder_id LIMIT 1");
    if (!$f) return false;
    if ((int) $f['owner_id'] === $uid) return true;
    $roots = fm_shared_folder_roots($uid);
    if (!$roots) return false;
    $ancestry = fm_folder_ancestry($folder_id);
    return (bool) array_intersect($ancestry, $roots);
}

/** $uid có quyền xem file $file_id không. */
function fm_can_access_file($uid, $file_id)
{
    $uid = (int) $uid;
    $file_id = (int) $file_id;
    $r = db_fetch_row("SELECT owner_id, folder_id FROM fm_files WHERE id = $file_id LIMIT 1");
    if (!$r) return false;
    if ((int) $r['owner_id'] === $uid) return true;
    if (in_array($file_id, fm_shared_file_ids($uid), true)) return true;
    $fid = (int) $r['folder_id'];
    return $fid > 0 ? fm_can_access_folder($uid, $fid) : false;
}

/** Nội dung 1 thư mục (subfolders + files) cho người được chia sẻ (lazy expand). */
function fm_folder_contents($uid, $folder_id)
{
    if (!fm_can_access_folder($uid, $folder_id)) return null;
    $owner = (int) (db_fetch_row("SELECT owner_id FROM fm_folders WHERE id = " . (int) $folder_id)['owner_id'] ?? 0);
    $subs = db_fetch_array(
        "SELECT * FROM fm_folders WHERE owner_id = $owner AND parent_id = " . (int) $folder_id . "
         ORDER BY sort_order ASC, id ASC"
    ) ?: [];
    return [
        'folders' => array_map('fm_format_folder', $subs),
        'files'   => fm_files_in($owner, (int) $folder_id),
    ];
}

/* ============================================================
 *  Danh sách "Được chia sẻ với tôi"
 * ============================================================ */

/** Bản đồ biệt danh (danh xưng danh bạ) [owner_id => alias] mà $uid đã đặt trong Chat. */
function fm_owner_alias_map($uid)
{
    if (!function_exists('chat_contact_aliases_map')) {
        require_once __DIR__ . '/../../../libraries/chat.php';
    }
    return function_exists('chat_contact_aliases_map') ? chat_contact_aliases_map((int) $uid) : [];
}

/** Danh sách share (pending + accepted + in_my_library) của $uid, kèm item + tên chủ sở hữu. */
function fm_shared_entries_for($uid)
{
    fm_ensure_tables();
    $uid = (int) $uid;
    $aliasMap = fm_owner_alias_map($uid);
    $rows = db_fetch_array(
        "SELECT s.*, u.fullname AS owner_fullname, u.username AS owner_username, u.avatar AS owner_avatar
         FROM fm_shares s
         LEFT JOIN tbl_users u ON u.id = s.owner_id
         WHERE s.shared_with = $uid AND s.status <> 'rejected'
           AND (s.status = 'accepted' OR s.status = 'pending' OR s.in_my_library = 1)
         ORDER BY s.created_at DESC, s.id DESC"
    ) ?: [];

    $out = [];
    foreach ($rows as $s) {
        $item = $s['item_type'] === 'folder'
            ? fm_folder_row((int) $s['item_id'])
            : fm_file_row((int) $s['item_id']);
        if (!$item) continue; // item gốc đã bị xóa
        $item_alias = fm_dec($s['alias'] ?? '');
        if ($item_alias !== '') $item['name'] = $item_alias;
        $owner_id   = (int) $s['owner_id'];
        $real_name  = (string) (($s['owner_fullname'] ?? '') ?: ($s['owner_username'] ?? ''));
        $owner_name = !empty($aliasMap[$owner_id]) ? $aliasMap[$owner_id] : $real_name;
        $out[] = [
            'share_id'      => (int) $s['id'],
            'item_type'     => $s['item_type'],
            'status'        => $s['status'],
            'in_my_library' => (int) $s['in_my_library'] === 1,
            'owner_name'    => $owner_name,
            'owner_avatar'  => trim((string) ($s['owner_avatar'] ?? '')) !== '' ? 'public/images/avatar/' . $s['owner_avatar'] : '',
            'created_at'    => $s['created_at'],
            'item'          => $item,
        ];
    }
    return $out;
}

function fm_shared_with_me($uid)
{
    $pending = [];
    $accepted = [];
    foreach (fm_shared_entries_for($uid) as $entry) {
        if ($entry['status'] === 'pending') $pending[] = $entry;
        else $accepted[] = $entry;
    }
    return ['pending' => $pending, 'accepted' => $accepted];
}

/* ============================================================
 *  THAO TÁC: thư mục
 * ============================================================ */
function fm_create_folder($owner, $parent_id, $name)
{
    fm_ensure_tables();
    $owner = (int) $owner;
    $parent_id = (int) $parent_id;
    $name = trim((string) $name);
    if ($owner <= 0 || $name === '') return ['success' => false, 'message' => 'Thiếu tên.'];
    // Thư mục con phải thuộc thư mục của chính mình.
    if ($parent_id > 0) {
        $p = db_fetch_row("SELECT owner_id FROM fm_folders WHERE id = $parent_id LIMIT 1");
        if (!$p || (int) $p['owner_id'] !== $owner) return ['success' => false, 'message' => 'Thư mục cha không hợp lệ.'];
    }
    $max = db_fetch_row("SELECT COALESCE(MAX(sort_order),-1)+1 AS n FROM fm_folders WHERE owner_id = $owner AND parent_id = $parent_id");
    $id = (int) db_insert('fm_folders', [
        'owner_id'   => $owner,
        'parent_id'  => $parent_id,
        'name'       => fm_enc($name),
        'sort_order' => (int) ($max['n'] ?? 0),
    ]);
    return ['success' => $id > 0, 'id' => $id];
}

/** Như fm_create_folder, nhưng nếu đã có thư mục con cùng tên (đã giải mã) dưới cùng owner+parent
 *  thì dùng lại thay vì tạo trùng. Dùng khi tải cả cây thư mục lên qua nhiều đợt (batch) — mỗi đợt
 *  phải "gặp lại" đúng thư mục cha đã tạo ở đợt trước thay vì tạo thêm 1 bản trùng tên. */
function fm_get_or_create_folder($owner, $parent_id, $name)
{
    fm_ensure_tables();
    $owner = (int) $owner;
    $parent_id = (int) $parent_id;
    $name = trim((string) $name);
    if ($owner <= 0 || $name === '') return ['success' => false, 'message' => 'Thiếu tên.'];
    $rows = db_fetch_array("SELECT id, name FROM fm_folders WHERE owner_id = $owner AND parent_id = $parent_id") ?: [];
    foreach ($rows as $r) {
        if (fm_dec($r['name']) === $name) return ['success' => true, 'id' => (int) $r['id']];
    }
    return fm_create_folder($owner, $parent_id, $name);
}

function fm_rename_folder($owner, $id, $name)
{
    $owner = (int) $owner; $id = (int) $id; $name = trim((string) $name);
    if ($name === '') return false;
    return db_update('fm_folders', ['name' => fm_enc($name)], "id = $id AND owner_id = $owner") >= 0
        && db_num_rows("SELECT 1 FROM fm_folders WHERE id = $id AND owner_id = $owner") > 0;
}

function fm_set_folder_color($owner, $id, $color)
{
    $owner = (int) $owner; $id = (int) $id;
    $color = preg_replace('/[^#a-zA-Z0-9]/', '', (string) $color);
    db_update('fm_folders', ['color' => $color], "id = $id AND owner_id = $owner");
    return true;
}

/** Xóa thư mục (đệ quy con + file + bản ghi chia sẻ + blob). */
function fm_delete_folder($owner, $id)
{
    $owner = (int) $owner; $id = (int) $id;
    $f = db_fetch_row("SELECT owner_id FROM fm_folders WHERE id = $id LIMIT 1");
    if (!$f || (int) $f['owner_id'] !== $owner) return false;

    // Xóa file trong thư mục.
    $files = db_fetch_array("SELECT id FROM fm_files WHERE owner_id = $owner AND folder_id = $id") ?: [];
    foreach ($files as $fr) fm_delete_file($owner, (int) $fr['id']);

    // Đệ quy thư mục con.
    $subs = db_fetch_array("SELECT id FROM fm_folders WHERE owner_id = $owner AND parent_id = $id") ?: [];
    foreach ($subs as $s) fm_delete_folder($owner, (int) $s['id']);

    db_delete('fm_shares', "item_type = 'folder' AND item_id = $id");
    db_delete('fm_folders', "id = $id AND owner_id = $owner");
    return true;
}

/* ============================================================
 *  THAO TÁC: file
 * ============================================================ */
function fm_add_file($owner, $folder_id, $meta)
{
    fm_ensure_tables();
    $owner = (int) $owner;
    $folder_id = (int) $folder_id;
    if ($folder_id > 0) {
        $p = db_fetch_row("SELECT owner_id FROM fm_folders WHERE id = $folder_id LIMIT 1");
        if (!$p || (int) $p['owner_id'] !== $owner) $folder_id = 0;
    }
    $max = db_fetch_row("SELECT COALESCE(MAX(sort_order),-1)+1 AS n FROM fm_files WHERE owner_id = $owner AND folder_id = $folder_id");
    return (int) db_insert('fm_files', [
        'owner_id'      => $owner,
        'folder_id'     => $folder_id,
        'stored_name'   => $meta['stored_name'],
        'original_name' => fm_enc($meta['original_name']),
        'mime'          => $meta['mime'],
        'size'          => $meta['size'],
        'ext'           => $meta['ext'],
        'is_image'      => $meta['is_image'],
        'sort_order'    => (int) ($max['n'] ?? 0),
        'source_file_id' => (int) ($meta['source_file_id'] ?? 0),
    ]);
}

function fm_rename_file($owner, $id, $name)
{
    $owner = (int) $owner; $id = (int) $id; $name = trim((string) $name);
    if ($name === '') return false;
    db_update('fm_files', ['original_name' => fm_enc($name)], "id = $id AND owner_id = $owner");
    return true;
}

function fm_delete_file($owner, $id)
{
    $owner = (int) $owner; $id = (int) $id;
    $r = db_fetch_row("SELECT owner_id, stored_name, source_file_id FROM fm_files WHERE id = $id LIMIT 1");
    if (!$r || (int) $r['owner_id'] !== $owner) return false;
    // Chỉ xóa blob khi không còn file nào tham chiếu cùng stored_name (bản sao dùng chung file gốc? — ở đây mỗi copy có blob riêng).
    $stored = (string) $r['stored_name'];
    $others = db_fetch_row("SELECT COUNT(*) AS c FROM fm_files WHERE stored_name = '" . escape_string($stored) . "' AND id <> $id");
    if ((int) ($others['c'] ?? 0) === 0) {
        $path = fm_upload_dir() . DIRECTORY_SEPARATOR . $stored;
        if (is_file($path)) @unlink($path);
    }
    db_delete('fm_shares', "item_type = 'file' AND item_id = $id");
    db_delete('fm_files', "id = $id AND owner_id = $owner");
    return true;
}

/** Xóa nhiều file cùng lúc (dọn dẹp dung lượng). Trả số file xóa thành công. */
function fm_delete_files_bulk($owner, array $ids)
{
    $owner = (int) $owner;
    $done = 0;
    foreach ($ids as $id) {
        if (fm_delete_file($owner, (int) $id)) $done++;
    }
    return $done;
}

/* ============================================================
 *  Ưu tiên dung lượng theo từng user (ghi đè giới hạn chung)
 * ============================================================ */

/** Tạo bảng ưu tiên dung lượng nếu chưa có (1 lần / request). */
function fm_quota_ensure_table()
{
    static $done = false;
    if ($done) return;
    $done = true;
    db_query("CREATE TABLE IF NOT EXISTS fm_quota_overrides (
        user_id    INT NOT NULL,
        quota_mb   INT NOT NULL,
        created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        PRIMARY KEY (user_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci");
}

/** Giới hạn riêng (MB) của 1 user, null nếu không có ưu tiên (dùng mức chung). */
function fm_quota_override_get($uid)
{
    fm_quota_ensure_table();
    $uid = (int) $uid;
    $r = db_fetch_row("SELECT quota_mb FROM fm_quota_overrides WHERE user_id = $uid LIMIT 1");
    return $r ? (int) $r['quota_mb'] : null;
}

/** Danh sách toàn bộ ưu tiên dung lượng (kèm tên user) — cho admin xem/sửa. */
function fm_quota_overrides_list()
{
    fm_quota_ensure_table();
    $rows = db_fetch_array(
        "SELECT o.user_id, o.quota_mb, u.fullname, u.username, u.avatar
         FROM fm_quota_overrides o
         JOIN tbl_users u ON u.id = o.user_id
         ORDER BY u.fullname, u.username"
    ) ?: [];
    $out = [];
    foreach ($rows as $r) {
        $out[] = [
            'user_id'  => (int) $r['user_id'],
            'name'     => (string) (($r['fullname'] ?? '') ?: ($r['username'] ?? '')),
            'avatar'   => trim((string) ($r['avatar'] ?? '')) !== '' ? 'public/images/avatar/' . $r['avatar'] : '',
            'quota_mb' => (int) $r['quota_mb'],
        ];
    }
    return $out;
}

/** Lưu (thêm/sửa) mức ưu tiên dung lượng cho 1 user. */
function fm_quota_override_save($user_id, $quota_mb)
{
    fm_quota_ensure_table();
    $user_id = (int) $user_id;
    $quota_mb = (int) $quota_mb;
    if ($user_id <= 0 || $quota_mb <= 0) return false;
    if (!db_fetch_row("SELECT 1 FROM tbl_users WHERE id = $user_id LIMIT 1")) return false;

    $exists = db_num_rows("SELECT 1 FROM fm_quota_overrides WHERE user_id = $user_id") > 0;
    if ($exists) {
        db_update('fm_quota_overrides', ['quota_mb' => $quota_mb], "user_id = $user_id");
    } else {
        db_insert('fm_quota_overrides', ['user_id' => $user_id, 'quota_mb' => $quota_mb]);
    }
    return true;
}

/** Gỡ mức ưu tiên dung lượng của 1 user (quay lại dùng mức chung). */
function fm_quota_override_delete($user_id)
{
    fm_quota_ensure_table();
    db_delete('fm_quota_overrides', "user_id = " . (int) $user_id);
    return true;
}

/** Tất cả folder_id con cháu (đệ quy) của 1 thư mục, gồm chính nó. */
function fm_folder_descendants($owner, $folder_id)
{
    $owner = (int) $owner;
    $ids = [(int) $folder_id];
    $queue = [(int) $folder_id];
    while ($queue) {
        $cur = array_shift($queue);
        $subs = db_fetch_array("SELECT id FROM fm_folders WHERE owner_id = $owner AND parent_id = $cur") ?: [];
        foreach ($subs as $s) { $ids[] = (int) $s['id']; $queue[] = (int) $s['id']; }
    }
    return $ids;
}

/** Dung lượng (byte) + số file mà 1 thư mục (và toàn bộ thư mục con) đang chiếm —
 *  để user biết nhóm/thư mục nào nặng mà dọn dẹp, giải phóng dung lượng. */
function fm_folder_storage($owner, $folder_id)
{
    $owner = (int) $owner;
    $ids = fm_folder_descendants($owner, $folder_id);
    $in = implode(',', $ids);
    $row = db_fetch_row("SELECT COALESCE(SUM(size),0) AS total, COUNT(*) AS cnt FROM fm_files WHERE owner_id = $owner AND folder_id IN ($in)");
    return [
        'bytes' => (int) ($row['total'] ?? 0),
        'count' => (int) ($row['cnt'] ?? 0),
    ];
}

/** Tổng dung lượng đã dùng (byte) + giới hạn (byte) cho 1 user — ưu tiên riêng
 *  (fm_quota_overrides) nếu có, không thì dùng mức chung (system_settings). */
function fm_storage_stats($uid)
{
    fm_ensure_tables();
    $uid = (int) $uid;
    $row = db_fetch_row("SELECT COALESCE(SUM(size),0) AS total FROM fm_files WHERE owner_id = $uid");
    $used = (int) ($row['total'] ?? 0);
    $override = fm_quota_override_get($uid);
    $quota_mb = $override !== null ? $override : (int) (system_settings_get()['storage_quota_mb'] ?? 2048);
    return [
        'used_bytes'  => $used,
        'quota_bytes' => $quota_mb * 1024 * 1024,
        'percent'     => $quota_mb > 0 ? min(100, round($used / ($quota_mb * 1024 * 1024) * 100, 1)) : 0,
        'is_priority' => $override !== null,
    ];
}

/** Dung lượng còn lại (byte) mà $uid còn được phép tải lên, null = không giới hạn (admin). */
function fm_quota_remaining($uid, $unlimited = false)
{
    if ($unlimited) return null;
    $stats = fm_storage_stats($uid);
    return max(0, (int) $stats['quota_bytes'] - (int) $stats['used_bytes']);
}

/* ============================================================
 *  Thêm file đính kèm trong Chat vào "Đã lưu từ chia sẻ"
 * ============================================================ */

/** $uid có được phân quyền vào view "Quản lý file" (file_manager) không. */
function fm_user_has_view_access($uid)
{
    $uid = (int) $uid;
    if ($uid <= 0) return false;
    $user = db_fetch_row("SELECT * FROM tbl_users WHERE id = $uid LIMIT 1");
    if (!$user) return false;
    if (permission_is_admin($user)) return true;
    $view = permission_find_view('file_management', 'file_management', 'file_manager');
    if (!$view) return true; // view chưa đăng ký danh mục -> không bị chặn (giống permission_guard)
    return permission_user_has_view($uid, (int) $view['id']);
}

/** Thêm 1 file đính kèm trong Chat vào "Đã lưu từ chia sẻ" của $uid (chỉ khi được phân quyền vào Quản lý file). */
function fm_add_from_chat_attachment($uid, $attachment_id)
{
    fm_ensure_tables();
    $uid = (int) $uid;
    $attachment_id = (int) $attachment_id;
    if (!fm_user_has_view_access($uid)) {
        return ['success' => false, 'message' => 'Bạn chưa được phân quyền vào Quản lý file.'];
    }

    $att = db_fetch_row("SELECT * FROM chat_attachments WHERE id = $attachment_id LIMIT 1");
    if (!$att) return ['success' => false, 'message' => 'Không tìm thấy tệp.'];

    if (!function_exists('chat_is_participant')) {
        require_once __DIR__ . '/../../../libraries/chat.php';
    }
    $msg = db_fetch_row("SELECT conversation_id, sender_id FROM chat_messages WHERE id = " . (int) $att['message_id'] . " LIMIT 1");
    if (!$msg || !chat_is_participant((int) $msg['conversation_id'], $uid)) {
        return ['success' => false, 'message' => 'Bạn không thuộc hội thoại này.'];
    }
    $sender_id = (int) $msg['sender_id'];

    db_query("CREATE TABLE IF NOT EXISTS fm_chat_imports (
        attachment_id INT NOT NULL,
        fm_file_id    INT NOT NULL,
        created_at    TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (attachment_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci");

    $imported = db_fetch_row("SELECT fm_file_id FROM fm_chat_imports WHERE attachment_id = $attachment_id LIMIT 1");
    if ($imported) {
        $file_id = (int) $imported['fm_file_id'];
    } else {
        $src = APPPATH . DIRECTORY_SEPARATOR . 'public' . DIRECTORY_SEPARATOR
             . 'uploads' . DIRECTORY_SEPARATOR . 'chat' . DIRECTORY_SEPARATOR . $att['file_name'];
        if (!is_file($src)) return ['success' => false, 'message' => 'Tệp gốc không còn tồn tại.'];
        $ext = (string) pathinfo((string) $att['file_name'], PATHINFO_EXTENSION);
        $safe_ext = preg_replace('/[^a-z0-9]/i', '', $ext);
        $new_name = 'cf' . time() . '_' . substr(md5($att['file_name'] . uniqid('', true)), 0, 10)
                  . ($safe_ext !== '' ? '.' . $safe_ext : '');
        $dest = fm_upload_dir() . DIRECTORY_SEPARATOR . $new_name;
        if (!@copy($src, $dest)) return ['success' => false, 'message' => 'Không sao chép được tệp.'];

        $file_id = (int) fm_add_file($sender_id, 0, [
            'stored_name'   => $new_name,
            'original_name' => (string) $att['original_name'],
            'mime'          => (string) $att['mime'],
            'size'          => (int) $att['size'],
            'ext'           => $ext,
            'is_image'      => (int) $att['is_image'],
        ]);
        if ($file_id <= 0) return ['success' => false, 'message' => 'Không tạo được bản ghi tệp.'];
        db_insert('fm_chat_imports', ['attachment_id' => $attachment_id, 'fm_file_id' => $file_id]);
    }

    $exists = db_fetch_row("SELECT id FROM fm_shares WHERE item_type = 'file' AND item_id = $file_id AND shared_with = $uid LIMIT 1");
    if ($exists) {
        db_update('fm_shares', ['status' => 'accepted', 'in_my_library' => 1, 'responded_at' => date('Y-m-d H:i:s')], "id = " . (int) $exists['id']);
    } else {
        db_insert('fm_shares', [
            'item_type'     => 'file',
            'item_id'       => $file_id,
            'owner_id'      => $sender_id,
            'shared_by'     => $sender_id,
            'shared_with'   => $uid,
            'status'        => 'accepted',
            'in_my_library' => 1,
            'responded_at'  => date('Y-m-d H:i:s'),
        ]);
    }
    return ['success' => true];
}

/** Danh sách file của CHÍNH user, "chưa đụng" (mở/tải) trong >= $months tháng gần đây. */
function fm_stale_files($uid, $months)
{
    fm_ensure_tables();
    $uid = (int) $uid;
    $months = max(1, (int) $months);
    $rows = db_fetch_array(
        "SELECT * FROM fm_files
         WHERE owner_id = $uid
           AND COALESCE(last_opened_at, created_at) < DATE_SUB(NOW(), INTERVAL $months MONTH)
         ORDER BY COALESCE(last_opened_at, created_at) ASC"
    ) ?: [];
    return array_map('fm_format_file', $rows);
}

function fm_move_file($owner, $id, $folder_id)
{
    $owner = (int) $owner; $id = (int) $id; $folder_id = (int) $folder_id;
    if ($folder_id > 0) {
        $p = db_fetch_row("SELECT owner_id FROM fm_folders WHERE id = $folder_id LIMIT 1");
        if (!$p || (int) $p['owner_id'] !== $owner) return false;
    }
    $max = db_fetch_row("SELECT COALESCE(MAX(sort_order),-1)+1 AS n FROM fm_files WHERE owner_id = $owner AND folder_id = $folder_id");
    db_update('fm_files', ['folder_id' => $folder_id, 'sort_order' => (int) ($max['n'] ?? 0)], "id = $id AND owner_id = $owner");
    return true;
}

function fm_toggle_star($owner, $type, $id)
{
    $owner = (int) $owner; $id = (int) $id;
    $tbl = $type === 'folder' ? 'fm_folders' : 'fm_files';
    $r = db_fetch_row("SELECT starred FROM $tbl WHERE id = $id AND owner_id = $owner LIMIT 1");
    if (!$r) return ['success' => false];
    $new = (int) $r['starred'] === 1 ? 0 : 1;
    db_update($tbl, ['starred' => $new], "id = $id AND owner_id = $owner");
    return ['success' => true, 'starred' => $new === 1];
}

/** Tạo bản sao 1 file vào "Thư viện của tôi" (gốc). $uid phải có quyền xem file đó. */
function fm_make_copy($uid, $file_id, $folder_id = 0)
{
    fm_ensure_tables();
    $uid = (int) $uid; $file_id = (int) $file_id;
    if (!fm_can_access_file($uid, $file_id)) return ['success' => false, 'message' => 'Không có quyền.'];
    $r = db_fetch_row("SELECT * FROM fm_files WHERE id = $file_id LIMIT 1");
    if (!$r) return ['success' => false, 'message' => 'Không tìm thấy file.'];
    $new_blob = fm_duplicate_blob($r['stored_name'], $r['ext']);
    if ($new_blob === '') return ['success' => false, 'message' => 'Không sao chép được tệp.'];
    // Chỉ cho copy vào thư mục của chính mình.
    $folder_id = (int) $folder_id;
    if ($folder_id > 0) {
        $p = db_fetch_row("SELECT owner_id FROM fm_folders WHERE id = $folder_id LIMIT 1");
        if (!$p || (int) $p['owner_id'] !== $uid) $folder_id = 0;
    }
    $base = fm_dec($r['original_name'] ?? '');
    $name = (int) $r['owner_id'] === $uid ? ($base . ' (bản sao)') : $base;
    $id = fm_add_file($uid, $folder_id, [
        'stored_name'    => $new_blob,
        'original_name'  => $name,
        'mime'           => $r['mime'],
        'size'           => $r['size'],
        'ext'            => $r['ext'],
        'is_image'       => $r['is_image'],
        'source_file_id' => $file_id,
    ]);
    return ['success' => $id > 0, 'id' => $id];
}

/* ============================================================
 *  Sắp xếp (kéo-thả)
 * ============================================================ */
function fm_reorder($owner, $type, $parent_id, $ids)
{
    $owner = (int) $owner; $parent_id = (int) $parent_id;
    if (!is_array($ids)) return false;
    $tbl = $type === 'folder' ? 'fm_folders' : 'fm_files';
    $col = $type === 'folder' ? 'parent_id' : 'folder_id';
    $order = 0;
    foreach ($ids as $id) {
        $id = (int) $id;
        if ($id <= 0) continue;
        db_update($tbl, [$col => $parent_id, 'sort_order' => $order], "id = $id AND owner_id = $owner");
        $order++;
    }
    return true;
}

/* ============================================================
 *  Tìm kiếm (file + thư mục của mình + được chia sẻ)
 * ============================================================ */
function fm_search($uid, $keyword)
{
    fm_ensure_tables();
    $uid = (int) $uid;
    $kw = mb_strtolower(trim((string) $keyword), 'UTF-8');
    if ($kw === '') return [];

    $results = [];
    // File của mình (tên đã mã hóa → giải mã rồi so khớp trong PHP).
    $mine = db_fetch_array("SELECT * FROM fm_files WHERE owner_id = $uid ORDER BY updated_at DESC LIMIT 500") ?: [];
    foreach ($mine as $r) {
        $f = fm_format_file($r);
        if (mb_strpos(mb_strtolower($f['name'], 'UTF-8'), $kw) !== false) {
            $f['scope'] = 'mine';
            $results[] = $f;
        }
    }
    $foldersMine = db_fetch_array("SELECT * FROM fm_folders WHERE owner_id = $uid LIMIT 500") ?: [];
    foreach ($foldersMine as $r) {
        $f = fm_format_folder($r);
        if (mb_strpos(mb_strtolower($f['name'], 'UTF-8'), $kw) !== false) {
            $f['scope'] = 'mine';
            $results[] = $f;
        }
    }
    // File được chia sẻ.
    foreach (fm_shared_file_ids($uid) as $fid) {
        $f = fm_file_row($fid);
        if ($f && mb_strpos(mb_strtolower($f['name'], 'UTF-8'), $kw) !== false) {
            $f['scope'] = 'shared';
            $results[] = $f;
        }
    }
    return array_slice($results, 0, 80);
}

/* ============================================================
 *  File hay dùng (bộ đếm click)
 * ============================================================ */

/** Mỗi lần user mở 1 file (xem/tải) → +1 vào click_count. $uid phải có quyền xem file. */
function fm_bump_click($uid, $file_id)
{
    fm_ensure_tables();
    $uid = (int) $uid; $file_id = (int) $file_id;
    if (!fm_can_access_file($uid, $file_id)) return ['success' => false];
    db_query("UPDATE fm_files SET click_count = click_count + 1 WHERE id = $file_id");
    fm_touch_opened($file_id);
    return ['success' => true];
}

/** Top N file "hay dùng" (click_count cao nhất) mà $uid có quyền xem (của mình + được chia sẻ). */
function fm_frequent_files($uid, $limit = 10)
{
    fm_ensure_tables();
    $uid = (int) $uid;
    $limit = max(1, (int) $limit);
    $shared_ids = fm_shared_file_ids($uid);
    $where = "owner_id = $uid";
    if ($shared_ids) $where .= " OR id IN (" . implode(',', array_map('intval', $shared_ids)) . ")";
    $rows = db_fetch_array(
        "SELECT * FROM fm_files WHERE ($where) AND click_count > 0
         ORDER BY click_count DESC, updated_at DESC LIMIT $limit"
    ) ?: [];
    return array_map('fm_format_file', $rows);
}

/* ============================================================
 *  CHIA SẺ tới user trong hệ thống (folder hoặc file)
 * ============================================================ */
function fm_share_to_user($owner, $type, $item_id, $target_uid, $shared_by = 0)
{
    fm_ensure_tables();
    $owner = (int) $owner; $item_id = (int) $item_id; $target_uid = (int) $target_uid;
    $shared_by = (int) $shared_by ?: $owner;
    $type = $type === 'folder' ? 'folder' : 'file';
    if ($target_uid <= 0 || $target_uid === $owner) return ['success' => false, 'message' => 'Người nhận không hợp lệ.'];

    // Xác minh quyền sở hữu / quyền chia sẻ tiếp.
    if ($type === 'folder') {
        $r = db_fetch_row("SELECT owner_id FROM fm_folders WHERE id = $item_id LIMIT 1");
    } else {
        $r = db_fetch_row("SELECT owner_id FROM fm_files WHERE id = $item_id LIMIT 1");
    }
    if (!$r) return ['success' => false, 'message' => 'Không tìm thấy mục.'];
    $real_owner = (int) $r['owner_id'];
    // Người chia sẻ phải là chủ sở hữu HOẶC đã được chia sẻ mục này (chia sẻ tiếp).
    $can = $real_owner === $shared_by
        || ($type === 'file'   && in_array($item_id, fm_shared_file_ids($shared_by), true))
        || ($type === 'folder' && in_array($item_id, fm_shared_folder_roots($shared_by), true));
    if (!$can) return ['success' => false, 'message' => 'Bạn không có quyền chia sẻ mục này.'];

    $exists = db_fetch_row("SELECT id, status FROM fm_shares
        WHERE item_type = '$type' AND item_id = $item_id AND shared_with = $target_uid LIMIT 1");
    if ($exists) {
        // Khôi phục lại chia sẻ đã gỡ.
        db_update('fm_shares', ['status' => 'pending', 'shared_by' => $shared_by, 'responded_at' => null],
            "id = " . (int) $exists['id']);
        $share_id = (int) $exists['id'];
    } else {
        $share_id = (int) db_insert('fm_shares', [
            'item_type'   => $type,
            'item_id'     => $item_id,
            'owner_id'    => $real_owner,
            'shared_by'   => $shared_by,
            'shared_with' => $target_uid,
            'status'      => 'pending',
        ]);
    }

    // Đẩy chuông cho người nhận (Nhận / Từ chối).
    $sharer = db_fetch_row("SELECT fullname, username FROM tbl_users WHERE id = $shared_by LIMIT 1");
    $sname = (string) (($sharer['fullname'] ?? '') ?: ($sharer['username'] ?? 'Ai đó'));
    $iname = $type === 'folder' ? (fm_folder_row($item_id)['name'] ?? 'thư mục') : (fm_file_row($item_id)['name'] ?? 'tệp');
    notify_create(
        $target_uid,
        $sname . ' đã chia sẻ ' . ($type === 'folder' ? 'nhóm file' : 'tệp') . ' với bạn',
        '“' . $iname . '”',
        '?mod=file_management&controllers=file_management&action=file_manager&share=' . $share_id,
        'fm_share',
        $shared_by
    );
    return ['success' => true, 'share_id' => $share_id];
}

/** Người nhận trả lời: accept | reject. */
function fm_share_respond($uid, $share_id, $accept)
{
    fm_ensure_tables();
    $uid = (int) $uid; $share_id = (int) $share_id;
    $s = db_fetch_row("SELECT * FROM fm_shares WHERE id = $share_id AND shared_with = $uid LIMIT 1");
    if (!$s) return ['success' => false, 'message' => 'Không tìm thấy lời mời.'];
    $status = $accept ? 'accepted' : 'rejected';
    db_update('fm_shares', ['status' => $status, 'responded_at' => date('Y-m-d H:i:s')], "id = $share_id");

    $me = db_fetch_row("SELECT fullname, username FROM tbl_users WHERE id = $uid LIMIT 1");
    $mname = (string) (($me['fullname'] ?? '') ?: ($me['username'] ?? 'Người nhận'));
    $iname = $s['item_type'] === 'folder' ? (fm_folder_row((int) $s['item_id'])['name'] ?? 'thư mục') : (fm_file_row((int) $s['item_id'])['name'] ?? 'tệp');
    notify_create(
        (int) $s['shared_by'],
        $mname . ($accept ? ' đã chấp nhận' : ' đã từ chối') . ' chia sẻ',
        '“' . $iname . '”',
        '?mod=file_management&controllers=file_management&action=file_manager',
        'fm_share_reply',
        $uid
    );
    return ['success' => true, 'status' => $status];
}

/** Người nhận "đưa vào Thư viện của tôi" → chủ gỡ chia sẻ không còn tác dụng. */
function fm_add_to_library($uid, $share_id)
{
    fm_ensure_tables();
    $uid = (int) $uid; $share_id = (int) $share_id;
    $s = db_fetch_row("SELECT id, status FROM fm_shares WHERE id = $share_id AND shared_with = $uid LIMIT 1");
    if (!$s) return ['success' => false];
    db_update('fm_shares', [
        'in_my_library' => 1,
        'status'        => $s['status'] === 'pending' ? 'accepted' : $s['status'],
        'responded_at'  => $s['status'] === 'pending' ? date('Y-m-d H:i:s') : null,
    ], "id = $share_id");
    return ['success' => true];
}

/**
 * Người NHẬN tự gỡ 1 mục khỏi "Được chia sẻ với tôi" (và khỏi Thư viện của tôi nếu
 * đã lưu). Khác với fm_revoke_share (chủ sở hữu gỡ): đây là lựa chọn của người nhận,
 * nên luôn thực hiện được kể cả khi in_my_library = 1.
 */
function fm_leave_share($uid, $share_id)
{
    fm_ensure_tables();
    $uid = (int) $uid; $share_id = (int) $share_id;
    $s = db_fetch_row("SELECT id FROM fm_shares WHERE id = $share_id AND shared_with = $uid LIMIT 1");
    if (!$s) return ['success' => false, 'message' => 'Không tìm thấy.'];
    db_update('fm_shares', [
        'status'        => 'rejected',
        'in_my_library' => 0,
        'responded_at'  => date('Y-m-d H:i:s'),
    ], "id = $share_id");
    return ['success' => true];
}

/** Chủ sở hữu gỡ chia sẻ với 1 người. Nếu người đó đã đưa vào thư viện → vô hiệu. */
function fm_revoke_share($owner, $share_id)
{
    fm_ensure_tables();
    $owner = (int) $owner; $share_id = (int) $share_id;
    $s = db_fetch_row("SELECT * FROM fm_shares WHERE id = $share_id LIMIT 1");
    if (!$s || (int) $s['owner_id'] !== $owner) return ['success' => false, 'message' => 'Không có quyền.'];
    if ((int) $s['in_my_library'] === 1) {
        return ['success' => false, 'message' => 'Người nhận đã đưa vào Thư viện của họ, không thể gỡ.'];
    }
    db_update('fm_shares', ['status' => 'revoked'], "id = $share_id");
    return ['success' => true];
}

/** Danh sách người mình đã chia sẻ 1 mục cho (để quản lý / gỡ). */
function fm_share_list($owner, $type, $item_id)
{
    $owner = (int) $owner; $item_id = (int) $item_id;
    $type = $type === 'folder' ? 'folder' : 'file';
    $rows = db_fetch_array(
        "SELECT s.id, s.status, s.in_my_library, s.shared_with,
                u.fullname, u.username, u.avatar
         FROM fm_shares s LEFT JOIN tbl_users u ON u.id = s.shared_with
         WHERE s.item_type = '$type' AND s.item_id = $item_id AND s.owner_id = $owner
         ORDER BY s.created_at DESC"
    ) ?: [];
    $out = [];
    foreach ($rows as $r) {
        $out[] = [
            'share_id'      => (int) $r['id'],
            'status'        => $r['status'],
            'in_my_library' => (int) $r['in_my_library'] === 1,
            'user_id'       => (int) $r['shared_with'],
            'name'          => (string) (($r['fullname'] ?? '') ?: ($r['username'] ?? '')),
            'avatar'        => trim((string) ($r['avatar'] ?? '')) !== '' ? 'public/images/avatar/' . $r['avatar'] : '',
        ];
    }
    return $out;
}

/* ============================================================
 *  Danh bạ người dùng (chọn người để chia sẻ)
 * ============================================================ */
function fm_users($uid)
{
    $uid = (int) $uid;
    $rows = db_fetch_array(
        "SELECT id, fullname, username, avatar FROM tbl_users
         WHERE id <> $uid AND (status IS NULL OR status NOT IN ('blocked', 'left', 'system'))
         ORDER BY fullname, username"
    ) ?: [];
    $out = [];
    foreach ($rows as $r) {
        $out[] = [
            'id'       => (int) $r['id'],
            'name'     => (string) (($r['fullname'] ?? '') ?: ($r['username'] ?? '')),
            'username' => (string) $r['username'],
            'avatar'   => trim((string) ($r['avatar'] ?? '')) !== '' ? 'public/images/avatar/' . $r['avatar'] : '',
        ];
    }
    return $out;
}

/* ============================================================
 *  CHIA SẺ 1 FILE vào CHAT (danh bạ)
 * ============================================================ */
function fm_share_to_chat($uid, $file_id, $target_uid, $note = '')
{
    $uid = (int) $uid; $file_id = (int) $file_id; $target_uid = (int) $target_uid;
    if (!fm_can_access_file($uid, $file_id)) return ['success' => false, 'message' => 'Không có quyền với tệp này.'];
    if ($target_uid <= 0 || $target_uid === $uid) return ['success' => false, 'message' => 'Người nhận không hợp lệ.'];
    if (!function_exists('chat_get_or_create_direct') || !function_exists('chat_insert_message')) {
        require_once __DIR__ . '/../../../libraries/chat.php';
    }
    $r = db_fetch_row("SELECT * FROM fm_files WHERE id = $file_id LIMIT 1");
    if (!$r) return ['success' => false, 'message' => 'Không tìm thấy tệp.'];

    // Nhân bản blob sang thư mục chat (để chat tự quản vòng đời).
    $orig = fm_dec($r['original_name'] ?? '');
    $chat_dir = APPPATH . DIRECTORY_SEPARATOR . 'public' . DIRECTORY_SEPARATOR . 'uploads' . DIRECTORY_SEPARATOR . 'chat';
    if (!is_dir($chat_dir)) @mkdir($chat_dir, 0775, true);
    $safe_ext = preg_replace('/[^a-z0-9]/', '', (string) $r['ext']);
    $new = 'c' . time() . '_' . substr(md5($r['stored_name'] . uniqid('', true)), 0, 10) . ($safe_ext !== '' ? '.' . $safe_ext : '');
    if (!@copy(fm_upload_dir() . DIRECTORY_SEPARATOR . $r['stored_name'], $chat_dir . DIRECTORY_SEPARATOR . $new)) {
        return ['success' => false, 'message' => 'Không gửi được tệp.'];
    }

    $cid = chat_get_or_create_direct($uid, $target_uid);
    $body = trim((string) $note) !== '' ? $note : $orig;
    $mid = chat_insert_message($cid, $uid, $body, 'file');
    db_insert('chat_attachments', [
        'message_id'    => $mid,
        'file_name'     => $new,
        'original_name' => mb_substr($orig, 0, 250, 'UTF-8'),
        'mime'          => (string) $r['mime'],
        'size'          => (int) $r['size'],
        'is_image'      => (int) $r['is_image'],
    ]);
    return ['success' => true];
}

/* ============================================================
 *  CHIA SẺ 1 FILE vào SESSION của DỰ ÁN
 * ============================================================ */
function fm_my_projects($uid)
{
    if (!function_exists('pm_projects_for')) {
        require_once __DIR__ . '/../../project_management/models/projectModel.php';
    }
    $rows = pm_projects_for((int) $uid) ?: [];
    $out = [];
    foreach ($rows as $p) {
        $out[] = ['id' => (int) $p['id'], 'name' => (string) $p['name'], 'updated_at' => $p['updated_at'] ?? ''];
    }
    return $out;
}

/** Session của 1 dự án, sắp theo hoạt động gần nhất (đề xuất phía trên). */
function fm_project_sessions($uid, $project_id)
{
    if (!function_exists('pm_is_member')) {
        require_once __DIR__ . '/../../project_management/models/projectModel.php';
    }
    $uid = (int) $uid; $project_id = (int) $project_id;
    // Bảo đảm $uid là thành viên dự án.
    $m = db_fetch_row("SELECT 1 FROM project_members WHERE project_id = $project_id AND user_id = $uid LIMIT 1");
    if (!$m) return [];
    $rows = db_fetch_array(
        "SELECT id, name, updated_at FROM project_sessions
         WHERE project_id = $project_id
         ORDER BY updated_at DESC, id DESC LIMIT 30"
    ) ?: [];
    return array_map(static function ($r) {
        return ['id' => (int) $r['id'], 'name' => (string) $r['name'], 'updated_at' => $r['updated_at']];
    }, $rows);
}

function fm_share_to_project($uid, $file_id, $project_id, $session_id, $note = '')
{
    $uid = (int) $uid; $file_id = (int) $file_id; $project_id = (int) $project_id; $session_id = (int) $session_id;
    if (!fm_can_access_file($uid, $file_id)) return ['success' => false, 'message' => 'Không có quyền với tệp này.'];
    require_once __DIR__ . '/../../project_management/models/projectModel.php';

    // Xác minh thành viên + session thuộc dự án.
    $m = db_fetch_row("SELECT 1 FROM project_members WHERE project_id = $project_id AND user_id = $uid LIMIT 1");
    if (!$m) return ['success' => false, 'message' => 'Bạn không thuộc dự án này.'];
    $ss = db_fetch_row("SELECT id FROM project_sessions WHERE id = $session_id AND project_id = $project_id LIMIT 1");
    if (!$ss) return ['success' => false, 'message' => 'Session không hợp lệ.'];

    $r = db_fetch_row("SELECT * FROM fm_files WHERE id = $file_id LIMIT 1");
    if (!$r) return ['success' => false, 'message' => 'Không tìm thấy tệp.'];
    $orig = fm_dec($r['original_name'] ?? '');

    $proj_dir = APPPATH . DIRECTORY_SEPARATOR . 'public' . DIRECTORY_SEPARATOR . 'uploads' . DIRECTORY_SEPARATOR . 'project';
    if (!is_dir($proj_dir)) @mkdir($proj_dir, 0775, true);
    $safe_ext = preg_replace('/[^a-z0-9]/', '', (string) $r['ext']);
    $new = 'p' . time() . '_' . substr(md5($r['stored_name'] . uniqid('', true)), 0, 10) . ($safe_ext !== '' ? '.' . $safe_ext : '');
    if (!@copy(fm_upload_dir() . DIRECTORY_SEPARATOR . $r['stored_name'], $proj_dir . DIRECTORY_SEPARATOR . $new)) {
        return ['success' => false, 'message' => 'Không gửi được tệp.'];
    }

    $body = trim((string) $note) !== '' ? $note : $orig;
    $mid = pm_insert_message($session_id, $project_id, $uid, $body, 'file');
    db_insert('project_attachments', [
        'message_id'    => $mid,
        'file_name'     => $new,
        'original_name' => mb_substr($orig, 0, 250, 'UTF-8'),
        'mime'          => (string) $r['mime'],
        'size'          => (int) $r['size'],
        'is_image'      => (int) $r['is_image'],
    ]);
    return ['success' => true];
}

/* ============================================================
 *  Tải xuống (kiểm tra quyền)
 * ============================================================ */
function fm_download_target($uid, $file_id)
{
    $uid = (int) $uid; $file_id = (int) $file_id;
    if (!fm_can_access_file($uid, $file_id)) return null;
    $r = db_fetch_row("SELECT * FROM fm_files WHERE id = $file_id LIMIT 1");
    if (!$r) return null;
    $path = fm_upload_dir() . DIRECTORY_SEPARATOR . $r['stored_name'];
    if (!is_file($path)) return null;
    fm_touch_opened($file_id);
    return [
        'path'  => $path,
        'name'  => fm_dec($r['original_name'] ?? '') ?: ('tep.' . $r['ext']),
        'mime'  => (string) ($r['mime'] ?? 'application/octet-stream'),
        'size'  => (int) $r['size'],
    ];
}
