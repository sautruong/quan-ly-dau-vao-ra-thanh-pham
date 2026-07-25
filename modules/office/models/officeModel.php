<?php
/**
 * =====================================================================
 *  OFFICE (office) — Docs + Sheets — Model
 * =====================================================================
 *  Không gian soạn thảo văn bản (Docs) và bảng tính (Sheets) dùng chung
 *  1 bộ bảng (ofc_*), phân biệt bằng cột `type`. Chia sẻ có phân quyền
 *  xem/bình luận/sửa (giống Google Drive), có lịch sử phiên bản, và
 *  "khóa mềm" (chỉ cảnh báo, không khóa cứng) cho cộng tác cơ bản.
 *
 *  title + content được mã hóa bằng libraries/crypto.php (khóa CHUNG,
 *  như các trường cá nhân hóa khác — xem file_management/tasks/todo/chat).
 *
 *  Prefix hàm: office_*.
 * =====================================================================
 */

require_once __DIR__ . '/../../../libraries/crypto.php';
require_once __DIR__ . '/../../../libraries/notifications.php';

/** Gắn ?v=<mtime> vào URL asset tĩnh (css/js) của module Office — module này đang chỉnh sửa
 *  liên tục nên cần "cache-busting" để trình duyệt luôn tải bản mới nhất, không dùng bản cũ
 *  cache lại khiến các bản vá tưởng như "chưa có tác dụng". $relPath dạng 'public/css/...'. */
function office_asset($relPath)
{
    $full = APPPATH . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $relPath);
    $ver = is_file($full) ? filemtime($full) : time();
    return $relPath . '?v=' . $ver;
}

/* ============================================================
 *  Khởi tạo bảng (idempotent)
 * ============================================================ */
function office_ensure_tables()
{
    static $done = false;
    if ($done) return;
    $done = true;

    db_query("CREATE TABLE IF NOT EXISTS ofc_documents (
        id             INT AUTO_INCREMENT PRIMARY KEY,
        type           ENUM('doc','sheet') NOT NULL,
        title          TEXT DEFAULT NULL,
        content        LONGTEXT DEFAULT NULL,
        owner_id       INT NOT NULL,
        current_version INT NOT NULL DEFAULT 1,
        is_starred     TINYINT(1) NOT NULL DEFAULT 0,
        created_at     TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        updated_at     TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        updated_by     INT NOT NULL DEFAULT 0,
        last_opened_at DATETIME DEFAULT NULL,
        KEY idx_owner_type (owner_id, type)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci");

    db_query("CREATE TABLE IF NOT EXISTS ofc_document_versions (
        id           INT AUTO_INCREMENT PRIMARY KEY,
        document_id  INT NOT NULL,
        version_no   INT NOT NULL,
        content      LONGTEXT DEFAULT NULL,
        editor_id    INT NOT NULL,
        note         VARCHAR(120) DEFAULT NULL,
        created_at   TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        KEY idx_doc (document_id, version_no)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci");

    db_query("CREATE TABLE IF NOT EXISTS ofc_shares (
        id           INT AUTO_INCREMENT PRIMARY KEY,
        document_id  INT NOT NULL,
        owner_id     INT NOT NULL,
        shared_by    INT NOT NULL,
        shared_with  INT NOT NULL,
        permission   ENUM('view','comment','edit') NOT NULL DEFAULT 'view',
        status       VARCHAR(12) NOT NULL DEFAULT 'pending',
        created_at   TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        responded_at DATETIME DEFAULT NULL,
        UNIQUE KEY uniq_share (document_id, shared_with),
        KEY idx_with (shared_with, status),
        KEY idx_owner (owner_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci");

    // "Thêm vào thư viện của tôi" (ghim riêng, không phụ thuộc trạng thái share).
    if (!db_fetch_row("SHOW COLUMNS FROM ofc_shares LIKE 'in_my_library'")) {
        db_query("ALTER TABLE ofc_shares ADD COLUMN in_my_library TINYINT(1) NOT NULL DEFAULT 0 AFTER status");
    }
    // Di chuyển dữ liệu cũ: trước 2026-07-07 chia sẻ còn ở trạng thái 'pending' (chờ chấp
    // nhận/từ chối) — nay chia sẻ là cấp quyền NGAY, 'pending' không còn ý nghĩa và khiến
    // office_can_view() coi như CHƯA có quyền (chỉ nhận 'accepted') → người được chia sẻ từ
    // TRƯỚC lần cập nhật này sẽ không thấy được tài liệu/bảng tính dù đã có trong ofc_shares.
    db_query("UPDATE ofc_shares SET status = 'accepted', responded_at = COALESCE(responded_at, NOW())
              WHERE status = 'pending'");

    db_query("CREATE TABLE IF NOT EXISTS ofc_active_sessions (
        id           INT AUTO_INCREMENT PRIMARY KEY,
        document_id  INT NOT NULL,
        user_id      INT NOT NULL,
        last_ping_at DATETIME NOT NULL,
        UNIQUE KEY uniq_doc_user (document_id, user_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci");

    // Trạng thái "đang nhập" (chỉ báo kiểu chat) — thêm cột sau nếu bảng đã tồn tại từ bản cũ.
    if (!db_fetch_row("SHOW COLUMNS FROM ofc_active_sessions LIKE 'is_typing'")) {
        db_query("ALTER TABLE ofc_active_sessions ADD COLUMN is_typing TINYINT(1) NOT NULL DEFAULT 0 AFTER last_ping_at");
    }
    if (!db_fetch_row("SHOW COLUMNS FROM ofc_active_sessions LIKE 'typing_at'")) {
        db_query("ALTER TABLE ofc_active_sessions ADD COLUMN typing_at DATETIME DEFAULT NULL AFTER is_typing");
    }
}

/** Đăng ký 2 view "Docs" và "Sheets" vào menu (idempotent). */
function office_ensure_view_registered()
{
    if (db_num_rows("SHOW TABLES LIKE 'tbl_views'") <= 0) return;
    db_query("INSERT IGNORE INTO tbl_views (module, controller, action, label, group_label, sort)
              VALUES ('office','office','docs','Docs','VĂN PHÒNG', 125)");
    db_query("INSERT IGNORE INTO tbl_views (module, controller, action, label, group_label, sort)
              VALUES ('office','office','sheets','Sheets','VĂN PHÒNG', 126)");
}

/* ============================================================
 *  Helpers chung
 * ============================================================ */
function office_uid()
{
    if (!function_exists('permission_current_user')) return 0;
    $u = permission_current_user();
    return (int) ($u['id'] ?? 0);
}

function office_enc($s) { return crypto_encrypt((string) $s); }
function office_dec($s) { return crypto_decrypt((string) $s); }

function office_type_ok($type) { return $type === 'sheet' ? 'sheet' : 'doc'; }

/** Nội dung mặc định cho tài liệu mới. */
function office_default_content($type)
{
    if ($type === 'sheet') {
        // QUAN TRỌNG: colWidths/rowHeights/cells phải encode thành JSON OBJECT "{}" chứ KHÔNG
        // PHẢI mảng "[]" — PHP json_encode(mảng rỗng) luôn ra "[]". Nếu để "[]", phía JS đọc
        // `loaded.cells || {}` sẽ giữ nguyên mảng rỗng (mảng luôn truthy trong JS dù rỗng),
        // biến sheet.cells thành MẢNG thay vì OBJECT — gán khóa chữ như "A1" vẫn chạy được
        // trong bộ nhớ nhưng JSON.stringify() trên mảng sẽ ÂM THẦM BỎ MẤT các khóa không phải
        // số, khiến lưu xong dữ liệu ô biến mất hoàn toàn (đã xác minh đúng lỗi này trên dữ
        // liệu thật). new stdClass() ép json_encode ra "{}" ngay từ đầu, tránh cả gốc.
        return json_encode([
            'cols' => 12, 'rows' => 30,
            'colWidths' => new stdClass(), 'rowHeights' => new stdClass(),
            'cells' => new stdClass(),
        ], JSON_UNESCAPED_UNICODE);
    }
    return '<p></p>';
}

/* ============================================================
 *  Đọc / định dạng 1 tài liệu
 * ============================================================ */
function office_row($id)
{
    $id = (int) $id;
    return db_fetch_row("SELECT * FROM ofc_documents WHERE id = $id LIMIT 1");
}

/** Định dạng cho danh sách (không kèm content — nhẹ). */
function office_format_brief($r)
{
    return [
        'id'         => (int) $r['id'],
        'type'       => $r['type'],
        'title'      => office_dec($r['title'] ?? '') ?: 'Không có tiêu đề',
        'owner_id'   => (int) $r['owner_id'],
        'is_starred' => (int) $r['is_starred'] === 1,
        'updated_at' => $r['updated_at'],
        'created_at' => $r['created_at'],
    ];
}

/** Định dạng đầy đủ (kèm content đã giải mã) cho editor. */
function office_format_full($r)
{
    $out = office_format_brief($r);
    $out['content'] = office_dec($r['content'] ?? '') ?: office_default_content($r['type']);
    $out['version'] = (int) $r['current_version'];
    return $out;
}

/* ============================================================
 *  Quyền
 * ============================================================ */

/** Quyền của $uid trên tài liệu $doc (mảng row), null nếu không có quyền gì. */
function office_permission_of($uid, $doc)
{
    $uid = (int) $uid;
    if (!$doc) return null;
    if ((int) $doc['owner_id'] === $uid) return 'owner';
    $s = db_fetch_row("SELECT permission FROM ofc_shares
        WHERE document_id = " . (int) $doc['id'] . " AND shared_with = $uid
          AND status = 'accepted' LIMIT 1");
    return $s ? $s['permission'] : null;
}

function office_can_view($uid, $doc) { return office_permission_of($uid, $doc) !== null; }
function office_can_edit($uid, $doc)
{
    $p = office_permission_of($uid, $doc);
    return $p === 'owner' || $p === 'edit';
}

/* ============================================================
 *  Danh sách
 * ============================================================ */

/** Tài liệu của chính $uid. */
function office_list_mine($uid, $type)
{
    office_ensure_tables();
    $uid = (int) $uid; $type = office_type_ok($type);
    $rows = db_fetch_array(
        "SELECT * FROM ofc_documents WHERE owner_id = $uid AND type = '$type'
         ORDER BY updated_at DESC"
    ) ?: [];
    return array_map('office_format_brief', $rows);
}

/** Tài liệu được chia sẻ với $uid (cấp quyền ngay, không qua bước chấp nhận), kèm tên chủ sở hữu + quyền. */
function office_list_shared_with_me($uid, $type)
{
    office_ensure_tables();
    $uid = (int) $uid; $type = office_type_ok($type);
    $rows = db_fetch_array(
        "SELECT d.*, s.id AS share_id, s.permission, s.in_my_library,
                u.fullname AS owner_fullname, u.username AS owner_username
         FROM ofc_shares s
         JOIN ofc_documents d ON d.id = s.document_id
         LEFT JOIN tbl_users u ON u.id = d.owner_id
         WHERE s.shared_with = $uid AND d.type = '$type'
         ORDER BY d.updated_at DESC"
    ) ?: [];
    $aliasMap = office_alias_map($uid);
    $out = [];
    foreach ($rows as $r) {
        $item = office_format_brief($r);
        $item['share_id']       = (int) $r['share_id'];
        $item['permission']     = $r['permission'];
        $item['in_my_library']  = (int) $r['in_my_library'] === 1;
        $ownerId = (int) $r['owner_id'];
        $realOwnerName = (string) (($r['owner_fullname'] ?? '') ?: ($r['owner_username'] ?? ''));
        $item['owner_name'] = !empty($aliasMap[$ownerId]) ? $aliasMap[$ownerId] : $realOwnerName;
        $out[] = $item;
    }
    return $out;
}

/** Tài liệu của $uid đang chia sẻ cho người khác (để xem "Đã chia sẻ"). */
function office_list_shared_by_me($uid, $type)
{
    office_ensure_tables();
    $uid = (int) $uid; $type = office_type_ok($type);
    $rows = db_fetch_array(
        "SELECT DISTINCT d.* FROM ofc_documents d
         JOIN ofc_shares s ON s.document_id = d.id
         WHERE d.owner_id = $uid AND d.type = '$type'
         ORDER BY d.updated_at DESC"
    ) ?: [];
    return array_map('office_format_brief', $rows);
}

/* ============================================================
 *  Tạo / đổi tên / xóa / gắn sao
 * ============================================================ */
function office_create($uid, $type, $title)
{
    office_ensure_tables();
    $uid = (int) $uid; $type = office_type_ok($type);
    $title = trim((string) $title) ?: ($type === 'sheet' ? 'Bảng tính không tiêu đề' : 'Tài liệu không tiêu đề');
    $id = (int) db_insert('ofc_documents', [
        'type'    => $type,
        'title'   => office_enc($title),
        'content' => office_enc(office_default_content($type)),
        'owner_id' => $uid,
        'updated_by' => $uid,
    ]);
    return $id > 0 ? office_format_full(office_row($id)) : null;
}

function office_rename($uid, $id, $title)
{
    $uid = (int) $uid; $id = (int) $id; $title = trim((string) $title);
    if ($title === '') return false;
    $doc = office_row($id);
    if (!office_can_edit($uid, $doc)) return false;
    db_update('ofc_documents', ['title' => office_enc($title), 'updated_by' => $uid], "id = $id");
    return true;
}

function office_delete($uid, $id)
{
    $uid = (int) $uid; $id = (int) $id;
    $doc = office_row($id);
    if (!$doc || (int) $doc['owner_id'] !== $uid) return false;
    db_delete('ofc_document_versions', "document_id = $id");
    db_delete('ofc_shares', "document_id = $id");
    db_delete('ofc_active_sessions', "document_id = $id");
    db_delete('ofc_documents', "id = $id AND owner_id = $uid");
    return true;
}

function office_toggle_star($uid, $id)
{
    $uid = (int) $uid; $id = (int) $id;
    $r = db_fetch_row("SELECT is_starred FROM ofc_documents WHERE id = $id AND owner_id = $uid LIMIT 1");
    if (!$r) return ['success' => false];
    $new = (int) $r['is_starred'] === 1 ? 0 : 1;
    db_update('ofc_documents', ['is_starred' => $new], "id = $id AND owner_id = $uid");
    return ['success' => true, 'is_starred' => $new === 1];
}

/* ============================================================
 *  Mở tài liệu (kèm quyền + presence)
 * ============================================================ */
function office_open($uid, $id)
{
    $doc = office_row($id);
    if (!$doc || !office_can_view($uid, $doc)) return null;
    db_update('ofc_documents', ['last_opened_at' => date('Y-m-d H:i:s')], "id = " . (int) $id);
    $out = office_format_full($doc);
    $out['my_permission'] = office_permission_of($uid, $doc);
    return $out;
}

/* ============================================================
 *  Lưu nội dung (khóa mềm: so version, KHÔNG tự merge)
 * ============================================================ */
function office_save($uid, $id, $content, $base_version, $title = null)
{
    office_ensure_tables();
    $uid = (int) $uid; $id = (int) $id;
    $doc = office_row($id);
    if (!office_can_edit($uid, $doc)) return ['success' => false, 'message' => 'Không có quyền sửa.'];

    $dbVer = (int) $doc['current_version'];
    $base = (int) $base_version;
    if ($base > 0 && $base < $dbVer) {
        return ['success' => false, 'conflict' => true, 'version' => $dbVer,
                 'message' => 'Tài liệu đã được người khác cập nhật.'];
    }

    $newVer = $dbVer + 1;
    $upd = [
        'content' => office_enc((string) $content),
        'current_version' => $newVer,
        'updated_by' => $uid,
    ];
    if ($title !== null && trim((string) $title) !== '') $upd['title'] = office_enc(trim((string) $title));
    db_update('ofc_documents', $upd, "id = $id");

    // Snapshot phiên bản: throttle — chỉ tạo bản mới nếu bản gần nhất cách đây >= 5 phút.
    $last = db_fetch_row("SELECT created_at FROM ofc_document_versions WHERE document_id = $id ORDER BY id DESC LIMIT 1");
    $shouldSnapshot = !$last || (strtotime((string) $last['created_at']) <= time() - 300);
    if ($shouldSnapshot) {
        db_insert('ofc_document_versions', [
            'document_id' => $id, 'version_no' => $newVer,
            'content' => office_enc((string) $content), 'editor_id' => $uid,
        ]);
    }
    return ['success' => true, 'version' => $newVer];
}

/** Lưu phiên bản thủ công (nút "Lưu phiên bản"), luôn tạo bản ghi mới bất kể throttle. */
function office_save_version_manual($uid, $id, $note = '')
{
    $uid = (int) $uid; $id = (int) $id;
    $doc = office_row($id);
    if (!office_can_edit($uid, $doc)) return ['success' => false];
    db_insert('ofc_document_versions', [
        'document_id' => $id, 'version_no' => (int) $doc['current_version'],
        'content' => $doc['content'], 'editor_id' => $uid,
        'note' => mb_substr(trim((string) $note), 0, 120, 'UTF-8') ?: 'Lưu thủ công',
    ]);
    return ['success' => true];
}

/* ============================================================
 *  Lịch sử phiên bản
 * ============================================================ */
function office_versions($uid, $id)
{
    $id = (int) $id;
    $doc = office_row($id);
    if (!office_can_view($uid, $doc)) return [];
    $rows = db_fetch_array(
        "SELECT v.id, v.version_no, v.note, v.created_at, v.editor_id,
                u.fullname, u.username
         FROM ofc_document_versions v LEFT JOIN tbl_users u ON u.id = v.editor_id
         WHERE v.document_id = $id ORDER BY v.id DESC LIMIT 100"
    ) ?: [];
    $out = [];
    foreach ($rows as $r) {
        $out[] = [
            'id'         => (int) $r['id'],
            'version_no' => (int) $r['version_no'],
            'note'       => (string) ($r['note'] ?? ''),
            'created_at' => $r['created_at'],
            'editor_name' => (string) (($r['fullname'] ?? '') ?: ($r['username'] ?? '')),
        ];
    }
    return $out;
}

function office_version_restore($uid, $id, $version_id)
{
    $uid = (int) $uid; $id = (int) $id; $version_id = (int) $version_id;
    $doc = office_row($id);
    if (!office_can_edit($uid, $doc)) return ['success' => false, 'message' => 'Không có quyền.'];
    $v = db_fetch_row("SELECT * FROM ofc_document_versions WHERE id = $version_id AND document_id = $id LIMIT 1");
    if (!$v) return ['success' => false, 'message' => 'Không tìm thấy phiên bản.'];
    $newVer = (int) $doc['current_version'] + 1;
    db_update('ofc_documents', [
        'content' => $v['content'], 'current_version' => $newVer, 'updated_by' => $uid,
    ], "id = $id");
    db_insert('ofc_document_versions', [
        'document_id' => $id, 'version_no' => $newVer, 'content' => $v['content'],
        'editor_id' => $uid, 'note' => 'Khôi phục từ phiên bản #' . (int) $v['version_no'],
    ]);
    return ['success' => true, 'content' => office_dec($v['content'] ?? ''), 'version' => $newVer];
}

/* ============================================================
 *  Presence ("đang mở") — khóa mềm
 * ============================================================ */
/** $typing: null = chỉ giữ kết nối (không đổi trạng thái nhập); true/false = đặt trạng thái đang/ngừng nhập. */
function office_ping_presence($uid, $id, $typing = null)
{
    office_ensure_tables();
    $uid = (int) $uid; $id = (int) $id;
    if ($uid <= 0 || $id <= 0) return;
    $now = date('Y-m-d H:i:s');
    $data = ['last_ping_at' => $now];
    if ($typing !== null) {
        $data['is_typing'] = $typing ? 1 : 0;
        if ($typing) $data['typing_at'] = $now;
    }
    $exists = db_num_rows("SELECT 1 FROM ofc_active_sessions WHERE document_id = $id AND user_id = $uid") > 0;
    if ($exists) {
        db_update('ofc_active_sessions', $data, "document_id = $id AND user_id = $uid");
    } else {
        db_insert('ofc_active_sessions', array_merge(['document_id' => $id, 'user_id' => $uid], $data));
    }
}

/** Người khác (khác $uid) đang mở tài liệu trong 30s gần đây, kèm trạng thái đang nhập (tự hết hạn sau 4s im lặng). */
function office_active_users($uid, $id)
{
    office_ensure_tables();
    $uid = (int) $uid; $id = (int) $id;
    $rows = db_fetch_array(
        "SELECT a.user_id, a.is_typing, a.typing_at, u.fullname, u.username
         FROM ofc_active_sessions a LEFT JOIN tbl_users u ON u.id = a.user_id
         WHERE a.document_id = $id AND a.user_id <> $uid
           AND a.last_ping_at >= DATE_SUB(NOW(), INTERVAL 30 SECOND)"
    ) ?: [];
    $aliasMap = office_alias_map($uid);
    $out = [];
    foreach ($rows as $r) {
        $ouid = (int) $r['user_id'];
        $real = (string) (($r['fullname'] ?? '') ?: ($r['username'] ?? ''));
        $isTyping = (int) $r['is_typing'] === 1 && !empty($r['typing_at'])
            && strtotime((string) $r['typing_at']) >= time() - 4;
        $out[] = [
            'user_id'   => $ouid,
            'name'      => !empty($aliasMap[$ouid]) ? $aliasMap[$ouid] : $real,
            'is_typing' => $isTyping,
        ];
    }
    return $out;
}

/** Danh xưng (biệt danh) mà $uid đã đặt cho từng liên hệ trong Chat — dùng để hiển thị tên nhất quán với danh bạ chat. */
function office_alias_map($uid)
{
    if (!function_exists('chat_contact_aliases_map')) {
        require_once __DIR__ . '/../../../libraries/chat.php';
    }
    return function_exists('chat_contact_aliases_map') ? chat_contact_aliases_map((int) $uid) : [];
}

/* ============================================================
 *  Chia sẻ
 * ============================================================ */
function office_editor_link($id)
{
    return '?mod=office&controllers=office&action=editor&id=' . (int) $id;
}

function office_share($uid, $id, $target_uid, $permission)
{
    office_ensure_tables();
    $uid = (int) $uid; $id = (int) $id; $target_uid = (int) $target_uid;
    $permission = in_array($permission, ['view', 'comment', 'edit'], true) ? $permission : 'view';
    $doc = office_row($id);
    if (!$doc || (int) $doc['owner_id'] !== $uid) return ['success' => false, 'message' => 'Không có quyền chia sẻ.'];
    if ($target_uid <= 0 || $target_uid === $uid) return ['success' => false, 'message' => 'Người nhận không hợp lệ.'];

    // Chia sẻ = cấp quyền NGAY (không cần người nhận bấm "Chấp nhận" như trước).
    $now = date('Y-m-d H:i:s');
    $exists = db_fetch_row("SELECT id FROM ofc_shares WHERE document_id = $id AND shared_with = $target_uid LIMIT 1");
    if ($exists) {
        db_update('ofc_shares', ['permission' => $permission, 'status' => 'accepted', 'responded_at' => $now],
            "id = " . (int) $exists['id']);
        $share_id = (int) $exists['id'];
    } else {
        $share_id = (int) db_insert('ofc_shares', [
            'document_id' => $id, 'owner_id' => $uid, 'shared_by' => $uid,
            'shared_with' => $target_uid, 'permission' => $permission,
            'status' => 'accepted', 'responded_at' => $now,
        ]);
    }

    $sharer = db_fetch_row("SELECT fullname, username FROM tbl_users WHERE id = $uid LIMIT 1");
    $sname = (string) (($sharer['fullname'] ?? '') ?: ($sharer['username'] ?? 'Ai đó'));
    $title = office_dec($doc['title'] ?? '') ?: 'tài liệu';
    $kind = $doc['type'] === 'sheet' ? 'bảng tính' : 'tài liệu';
    notify_create(
        $target_uid,
        $sname . ' đã chia sẻ ' . $kind . ' với bạn',
        '"' . $title . '"',
        office_editor_link($id),
        'office_share',
        $uid
    );
    return ['success' => true, 'share_id' => $share_id];
}

function office_share_list($uid, $id)
{
    $uid = (int) $uid; $id = (int) $id;
    $doc = office_row($id);
    if (!$doc || (int) $doc['owner_id'] !== $uid) return [];
    $rows = db_fetch_array(
        "SELECT s.id, s.permission, s.status, s.shared_with, u.fullname, u.username, u.avatar
         FROM ofc_shares s LEFT JOIN tbl_users u ON u.id = s.shared_with
         WHERE s.document_id = $id ORDER BY s.created_at DESC"
    ) ?: [];
    $aliasMap = office_alias_map($uid);
    $out = [];
    foreach ($rows as $r) {
        $targetId = (int) $r['shared_with'];
        $realName = (string) (($r['fullname'] ?? '') ?: ($r['username'] ?? ''));
        $out[] = [
            'share_id'   => (int) $r['id'],
            'permission' => $r['permission'],
            'status'     => $r['status'],
            'user_id'    => $targetId,
            'name'       => !empty($aliasMap[$targetId]) ? $aliasMap[$targetId] : $realName,
            'avatar'     => trim((string) ($r['avatar'] ?? '')) !== '' ? 'public/images/avatar/' . $r['avatar'] : '',
        ];
    }
    return $out;
}

/** Chủ sở hữu đổi quyền (view/comment/edit) của 1 người đã được chia sẻ. */
function office_change_permission($uid, $share_id, $permission)
{
    $uid = (int) $uid; $share_id = (int) $share_id;
    $permission = in_array($permission, ['view', 'comment', 'edit'], true) ? $permission : 'view';
    $s = db_fetch_row("SELECT owner_id FROM ofc_shares WHERE id = $share_id LIMIT 1");
    if (!$s || (int) $s['owner_id'] !== $uid) return ['success' => false, 'message' => 'Không có quyền.'];
    db_update('ofc_shares', ['permission' => $permission], "id = $share_id");
    return ['success' => true];
}

/** Người NHẬN tự gỡ 1 mục khỏi "Được chia sẻ với tôi" để dọn dẹp (khác revoke_share — đây là chủ động của người nhận). */
function office_leave_share($uid, $share_id)
{
    $uid = (int) $uid; $share_id = (int) $share_id;
    $s = db_fetch_row("SELECT id FROM ofc_shares WHERE id = $share_id AND shared_with = $uid LIMIT 1");
    if (!$s) return ['success' => false, 'message' => 'Không tìm thấy.'];
    db_delete('ofc_shares', "id = $share_id");
    return ['success' => true];
}

/** Người nhận ghim/bỏ ghim 1 mục được chia sẻ vào "Thư viện của tôi". */
function office_toggle_library($uid, $share_id)
{
    $uid = (int) $uid; $share_id = (int) $share_id;
    $s = db_fetch_row("SELECT in_my_library FROM ofc_shares WHERE id = $share_id AND shared_with = $uid LIMIT 1");
    if (!$s) return ['success' => false];
    $new = (int) $s['in_my_library'] === 1 ? 0 : 1;
    db_update('ofc_shares', ['in_my_library' => $new], "id = $share_id");
    return ['success' => true, 'in_my_library' => $new === 1];
}

function office_revoke_share($uid, $share_id)
{
    $uid = (int) $uid; $share_id = (int) $share_id;
    $s = db_fetch_row("SELECT * FROM ofc_shares WHERE id = $share_id LIMIT 1");
    if (!$s || (int) $s['owner_id'] !== $uid) return ['success' => false, 'message' => 'Không có quyền.'];
    db_delete('ofc_shares', "id = $share_id");
    return ['success' => true];
}

/* ============================================================
 *  Danh bạ người dùng (chọn người để chia sẻ)
 * ============================================================ */
function office_users($uid)
{
    $uid = (int) $uid;
    $rows = db_fetch_array(
        "SELECT id, fullname, username, avatar FROM tbl_users
         WHERE id <> $uid AND (status IS NULL OR status NOT IN ('blocked', 'left'))
         ORDER BY fullname, username"
    ) ?: [];
    $aliasMap = office_alias_map($uid);
    $out = [];
    foreach ($rows as $r) {
        $rid = (int) $r['id'];
        $realName = (string) (($r['fullname'] ?? '') ?: ($r['username'] ?? ''));
        $out[] = [
            'id'       => $rid,
            'name'     => !empty($aliasMap[$rid]) ? $aliasMap[$rid] : $realName,
            'avatar'   => trim((string) ($r['avatar'] ?? '')) !== '' ? 'public/images/avatar/' . $r['avatar'] : '',
        ];
    }
    return $out;
}

/* ============================================================
 *  Xuất bản sao vào "Quản lý file"
 * ============================================================ */
/** Kết xuất nội dung tài liệu thành 1 tệp thô (HTML cho Docs, CSV cho Sheets — công thức đã
 *  tính sẵn giá trị hiển thị). Dùng chung cho "Đưa vào Quản lý file" + "Chia sẻ qua Chat" +
 *  tải xuống trực tiếp. */
function office_render_export_body($doc)
{
    $title = office_dec($doc['title'] ?? '') ?: ($doc['type'] === 'sheet' ? 'bang_tinh' : 'tai_lieu');
    $content = office_dec($doc['content'] ?? '');

    if ($doc['type'] === 'sheet') {
        $data = json_decode($content, true) ?: ['cols' => 0, 'rows' => 0, 'cells' => []];
        $cols = (int) ($data['cols'] ?? 0);
        $rows = (int) ($data['rows'] ?? 0);
        $cells = $data['cells'] ?? [];
        $cache = []; $visiting = [];
        $lines = [];
        for ($r = 1; $r <= $rows; $r++) {
            $line = [];
            for ($c = 0; $c < $cols; $c++) {
                $ref = office_cell_ref($c, $r);
                $v = office_sheet_compute_cell($cells, $ref, $cache, $visiting);
                $v = office_csv_guard((string) ($v === null ? '' : $v));
                $v = str_replace('"', '""', $v);
                $line[] = (strpos($v, ',') !== false || strpos($v, '"') !== false) ? '"' . $v . '"' : $v;
            }
            $lines[] = implode(',', $line);
        }
        $body = "\xEF\xBB\xBF" . implode("\r\n", $lines);
        return ['title' => $title, 'body' => $body, 'ext' => 'csv', 'mime' => 'text/csv'];
    }

    return office_doc_wrap_body($title, $content);
}

/** Đóng gói 1 tiêu đề + nội dung Docs THÔ thành bản .doc hoàn chỉnh (HTML mở được bằng Word) —
 *  tách riêng khỏi office_render_export_body() để dùng lại được cho nội dung CHƯA từng lưu thành
 *  ofc_documents (xem office_mail_merge_zip_preview() — "Tải xuống hết" bản xem trước TRƯỚC khi
 *  bấm "Lưu vào Docs"), không chỉ cho tài liệu đã có sẵn trong DB. */
function office_doc_wrap_body($title, $content)
{
    $content = (string) $content;
    // Canh lề (mốc "<!--OFMARGIN:top,right,bottom,left-->" đầu content, xem docs_editor.js) —
    // đọc ra để dựng @page margin thật cho Word, rồi xoá mốc khỏi content (dù là comment HTML vô
    // hình khi hiển thị, không xoá thì vẫn nằm lẫn trong file .doc xuất ra). Tài liệu cũ (lưu
    // trước khi có tính năng canh lề) không có mốc này -> để Word tự dùng lề mặc định của nó,
    // giữ đúng hành vi cũ.
    $pageMarginCss = '';
    if (preg_match('/^<!--OFMARGIN:([\d.]+),([\d.]+),([\d.]+),([\d.]+)-->/', $content, $mm)) {
        $pageMarginCss = '@page{margin:' . $mm[1] . 'cm ' . $mm[2] . 'cm ' . $mm[3] . 'cm ' . $mm[4] . 'cm}';
        $content = substr($content, strlen($mm[0]));
    }
    // Dải "Xuống dòng sau bảng" (.of-table-exit) chỉ là tiện ích BẤM CHUỘT trong lúc soạn thảo
    // (đặt caret ra khỏi bảng) — không phải nội dung thật, phải gỡ khỏi bản xuất.
    $content = preg_replace('/<div class="of-table-exit"[^>]*>.*?<\/div>/is', '', $content);
    // Cỡ chữ trên màn hình lưu bằng px (xem applyFontSize() ở docs_editor.js). Word đọc HTML này
    // qua bộ quy đổi 96dpi riêng của nó (1px = 0.75pt) rồi LÀM TRÒN, nên "13px" thành "10pt"
    // trong hộp cỡ chữ của Word — số hiển thị lệch hẳn so với số user đã chọn trên hệ thống. Đổi
    // thẳng đơn vị px -> pt GIỮ NGUYÊN SỐ (không nhân hệ số quy đổi) để Word hiển thị ĐÚNG cùng
    // con số "13" người dùng đã thấy trên web.
    $content = preg_replace('/font-size:\s*(\d+(?:\.\d+)?)px/i', 'font-size:$1pt', $content);
    // Đổi mốc ngắt trang <!--OFPAGE--> (nhiều "trang" A4 nối nhau, xem docs_editor.js) thành 1
    // lần ngắt trang thật khi xuất ra Word, giữ đúng cấu trúc nhiều trang.
    $content = str_replace('<!--OFPAGE-->', '<div style="page-break-before:always"></div>', $content);
    // Bảng chèn từ 2026-07-07 đã có border/padding inline sẵn trên từng ô (không phụ thuộc CSS
    // của app). Thêm 1 <style> dự phòng cho các bảng cũ tạo trước đó (chỉ có border qua CSS
    // class .of-doc-table, không có inline) để mở bằng Word vẫn thấy viền thay vì trắng tinh.
    $body = '<!DOCTYPE html><html lang="vi"><head><meta charset="UTF-8"><title>'
          . htmlspecialchars($title) . '</title>'
          . '<style>' . $pageMarginCss . 'table.of-doc-table{border-collapse:collapse}table.of-doc-table td{border:1px solid #cbd5e1;padding:6px 8px}</style>'
          . '</head><body>' . $content . '</body></html>';
    // Đuôi .doc (thủ thuật HTML mở bằng Word) — dùng chung cho "Đưa vào Quản lý file" và
    // "Chia sẻ qua Chat", KHÔNG xuất .html nữa (Word/LibreOffice vẫn mở tốt file .doc dạng này).
    return ['title' => $title, 'body' => $body, 'ext' => 'doc', 'mime' => 'application/msword'];
}

function office_export_to_fm($uid, $id)
{
    $uid = (int) $uid; $id = (int) $id;
    $doc = office_row($id);
    if (!$doc || !office_can_view($uid, $doc)) return ['success' => false, 'message' => 'Không có quyền.'];

    require_once __DIR__ . '/../../file_management/models/file_managementModel.php';
    fm_ensure_tables();

    $rendered = office_render_export_body($doc);
    $title = $rendered['title']; $body = $rendered['body']; $ext = $rendered['ext']; $mime = $rendered['mime'];
    $dir = fm_upload_dir();

    $safe_ext = $ext;
    $filename = 'o' . time() . '_' . substr(md5($title . uniqid('', true)), 0, 10) . '.' . $safe_ext;
    $dest = $dir . DIRECTORY_SEPARATOR . $filename;
    if (@file_put_contents($dest, $body) === false) {
        return ['success' => false, 'message' => 'Không ghi được tệp.'];
    }

    $file_id = fm_add_file($uid, 0, [
        'stored_name'   => $filename,
        'original_name' => $title . '.' . $ext,
        'mime'          => $mime,
        'size'          => strlen($body),
        'ext'           => $ext,
        'is_image'      => 0,
    ]);
    return ['success' => $file_id > 0, 'file_id' => $file_id];
}

/* ============================================================
 *  "Trộn file" (mail-merge Docs ⇄ Sheets) — 1 văn bản Docs mẫu chứa
 *  placeholder [key] + 1 bảng Sheets có dòng tiêu đề (row 1) đặt tên cột
 *  cũng dạng [key] → sinh 1 văn bản Docs THẬT (ofc_documents) cho MỖI
 *  dòng dữ liệu, [key] được thay bằng giá trị ô tương ứng của dòng đó.
 * ============================================================ */

/** Đọc dòng tiêu đề (row 1) của 1 bảng Sheets đã decode, trả về map
 *  col (0-based) => key (đã bỏ ngoặc vuông) — CHỈ những cột tiêu đề đúng
 *  dạng "[key]" mới được đưa vào map; cột khác coi là cột phụ, bỏ qua. */
function office_mail_merge_header_map($cols, $cells, &$cache, &$visiting)
{
    $map = [];
    for ($c = 0; $c < $cols; $c++) {
        $ref = office_cell_ref($c, 1);
        $header = trim((string) office_sheet_compute_cell($cells, $ref, $cache, $visiting));
        if (preg_match('/^\[(.+)\]$/', $header, $m)) $map[$c] = $m[1];
    }
    return $map;
}

/* ============================================================
 *  Trộn dữ liệu dạng BẢNG lồng ("Sheet ngoại") — mốc "[table]" trong mẫu Docs đứng ngay TRƯỚC
 *  1 bảng <table> mẫu (dòng tiêu đề chữ thường + đúng 1 dòng "mẫu" chứa placeholder [key] lấy
 *  từ Sheet ngoại) → nhân dòng mẫu đó thành N dòng thật theo N dòng dữ liệu ngoại đã khớp với
 *  dòng chính hiện tại (qua field join). Cột không có placeholder (vd "áp dụng theo") giữ
 *  nguyên y hệt, lặp lại cho mọi dòng sinh ra.
 *
 *  CỐ TÌNH thao tác CHUỖI, KHÔNG dùng DOMDocument::loadHTML()/saveHTML() cho cả tài liệu — round
 *  trip qua parser HTML của libxml2 có rủi ro làm lệch định dạng phần còn lại của tài liệu
 *  (khoảng trắng/entity/thẻ tự đóng...). Chỉ định vị & sửa ĐÚNG đoạn <table>...</table> ngay sau
 *  mốc bằng strpos/stripos, phần còn lại của $html giữ nguyên 100%.
 * ============================================================ */

/** Nhân dòng MẪU (dòng CUỐI CÙNG trong bảng có chứa ít nhất 1 "[key]" — dòng tiêu đề chữ thường
 *  không khớp pattern này nên tự động bị loại) thành N dòng theo $extRows (mảng các map
 *  key=>value). $extRows rỗng → GIỮ NGUYÊN dòng mẫu (còn placeholder chưa thay) để dễ nhận ra
 *  sản phẩm này thiếu dữ liệu bên Sheet ngoại, không âm thầm bỏ trống. */
function office_mail_merge_expand_one_table($tableHtml, array $extRows)
{
    if (!$extRows) return $tableHtml;
    if (!preg_match_all('/<tr\b[^>]*>.*?<\/tr>/is', $tableHtml, $m)) return $tableHtml;
    $rows = $m[0];
    $templateRow = null;
    for ($i = count($rows) - 1; $i >= 0; $i--) {
        if (preg_match('/\[[^\[\]]+\]/', $rows[$i])) { $templateRow = $rows[$i]; break; }
    }
    if ($templateRow === null) return $tableHtml; // không có dòng nào chứa placeholder -> không phải bảng mẫu

    $generated = [];
    foreach ($extRows as $rowData) {
        $search = []; $replace = [];
        foreach ($rowData as $key => $val) {
            $search[] = '[' . $key . ']';
            $replace[] = htmlspecialchars((string) $val, ENT_QUOTES, 'UTF-8');
        }
        $generated[] = str_replace($search, $replace, $templateRow);
    }
    return str_replace($templateRow, implode('', $generated), $tableHtml);
}

/** Lặp tìm mọi mốc "[table]" trong $html, mở rộng đúng bảng <table> gần nhất theo sau mỗi mốc,
 *  bỏ hẳn chữ "[table]" khỏi bản xuất (chỉ là hướng dẫn cho lúc trộn, không phải nội dung hiển
 *  thị). Không tìm thấy <table> theo sau 1 mốc nào đó -> bỏ qua đúng mốc đó (vẫn xoá chữ mốc). */
function office_mail_merge_expand_tables($html, array $extRowsForThisRecord)
{
    $marker = '[table]';
    $out = '';
    $rest = (string) $html;
    while (($pos = strpos($rest, $marker)) !== false) {
        $out .= substr($rest, 0, $pos);
        $afterMarker = substr($rest, $pos + strlen($marker));

        $tablePos = stripos($afterMarker, '<table');
        if ($tablePos === false) { $rest = $afterMarker; continue; }
        $closeTagPos = stripos($afterMarker, '</table>', $tablePos);
        if ($closeTagPos === false) { $rest = $afterMarker; continue; }
        $closeEnd = $closeTagPos + strlen('</table>');

        $beforeTable = substr($afterMarker, 0, $tablePos);
        $tableHtml = substr($afterMarker, $tablePos, $closeEnd - $tablePos);
        $afterTable = substr($afterMarker, $closeEnd);

        $out .= $beforeTable . office_mail_merge_expand_one_table($tableHtml, $extRowsForThisRecord);
        $rest = $afterTable;
    }
    $out .= $rest;
    return $out;
}

/** $titlePrefix: phần chữ user tự gõ; $nameCol: key cột được chọn làm "tên chính" — tiêu đề mỗi
 *  file sinh ra = rtrim($titlePrefix) . ' ' . <giá trị cột tên chính của dòng đó>.
 *  $extSheetId/$mainJoinCol/$extJoinCol: TÙY CHỌN — bật "trộn dữ liệu dạng bảng" (mốc "[table]",
 *  xem office_mail_merge_expand_tables ở trên). Không truyền extSheetId (0/rỗng) -> bỏ qua hoàn
 *  toàn bước này, hành vi y hệt trước khi có tính năng này (không đụng gì tới "[table]"). */
function office_mail_merge($uid, $templateId, $sheetId, $titlePrefix, $nameCol, $extSheetId = 0, $mainJoinCol = '', $extJoinCol = '')
{
    $uid = (int) $uid;
    $tpl = office_row($templateId);
    if (!$tpl || $tpl['type'] !== 'doc' || !office_can_view($uid, $tpl)) {
        return ['success' => false, 'message' => 'Không tìm thấy văn bản mẫu hoặc không có quyền.'];
    }
    $sheet = office_row($sheetId);
    if (!$sheet || $sheet['type'] !== 'sheet' || !office_can_view($uid, $sheet)) {
        return ['success' => false, 'message' => 'Không tìm thấy dữ liệu Sheets hoặc không có quyền.'];
    }
    $nameCol = trim((string) $nameCol);
    if ($nameCol === '') return ['success' => false, 'message' => 'Chưa chọn cột làm tên chính.'];

    $tplContent = office_dec($tpl['content'] ?? '');
    $data = json_decode(office_dec($sheet['content'] ?? ''), true) ?: ['cols' => 0, 'rows' => 0, 'cells' => []];
    $cols = (int) ($data['cols'] ?? 0);
    $rows = (int) ($data['rows'] ?? 0);
    $cells = $data['cells'] ?? [];
    $cache = []; $visiting = [];

    $colKeys = office_mail_merge_header_map($cols, $cells, $cache, $visiting);
    if (!$colKeys) {
        return ['success' => false, 'message' => 'Sheets chưa có cột nào đặt tên dạng [tên_cột] ở dòng 1.'];
    }
    if (!in_array($nameCol, $colKeys, true)) {
        return ['success' => false, 'message' => 'Cột làm tên chính không hợp lệ.'];
    }

    // "Sheet ngoại" (tùy chọn) — đọc TOÀN BỘ dòng dữ liệu 1 LẦN trước vòng lặp dòng chính, lọc
    // theo từng dòng chính ngay bên dưới (đỡ đọc lại nhiều lần nếu Sheet ngoại nhiều dòng).
    $extSheetId = (int) $extSheetId;
    $mainJoinCol = trim((string) $mainJoinCol);
    $extJoinCol = trim((string) $extJoinCol);
    $extAllRows = null;
    if ($extSheetId > 0) {
        if ($mainJoinCol === '' || $extJoinCol === '') {
            return ['success' => false, 'message' => 'Đã chọn Sheet ngoại nhưng chưa chọn đủ field khớp (chính/ngoại).'];
        }
        if (!in_array($mainJoinCol, $colKeys, true)) {
            return ['success' => false, 'message' => 'Field chính không hợp lệ.'];
        }
        $extSheet = office_row($extSheetId);
        if (!$extSheet || $extSheet['type'] !== 'sheet' || !office_can_view($uid, $extSheet)) {
            return ['success' => false, 'message' => 'Không tìm thấy Sheet ngoại hoặc không có quyền.'];
        }
        $extData = json_decode(office_dec($extSheet['content'] ?? ''), true) ?: ['cols' => 0, 'rows' => 0, 'cells' => []];
        $extCols = (int) ($extData['cols'] ?? 0);
        $extRows = (int) ($extData['rows'] ?? 0);
        $extCells = $extData['cells'] ?? [];
        $extCache = []; $extVisiting = [];
        $extColKeys = office_mail_merge_header_map($extCols, $extCells, $extCache, $extVisiting);
        if (!in_array($extJoinCol, $extColKeys, true)) {
            return ['success' => false, 'message' => 'Field ngoại không hợp lệ.'];
        }
        $extAllRows = [];
        for ($er = 2; $er <= $extRows; $er++) {
            $erRepl = [];
            foreach ($extColKeys as $ec => $ekey) {
                $erRepl[$ekey] = (string) office_sheet_compute_cell($extCells, office_cell_ref($ec, $er), $extCache, $extVisiting);
            }
            // Dòng ngoại không có giá trị field ngoại thì không thể khớp với ai — bỏ qua.
            if (trim($erRepl[$extJoinCol] ?? '') === '') continue;
            $extAllRows[] = $erRepl;
        }
    }

    $prefix = rtrim((string) $titlePrefix);
    $created = [];
    $seenNames = []; // khử trùng: mỗi giá trị "tên chính" chỉ sinh ĐÚNG 1 file — nếu Sheets chính
                      // lỡ có nhiều dòng cùng chung 1 tên (vd 11 dòng nhưng chỉ 2 sản phẩm khác
                      // nhau thật sự), các dòng trùng SAU dòng đầu tiên sẽ bị bỏ qua thay vì sinh
                      // thêm file trùng tên gây hiểu nhầm là lỗi.
    for ($r = 2; $r <= $rows; $r++) {
        $repl = [];
        foreach ($colKeys as $c => $key) {
            $repl[$key] = (string) office_sheet_compute_cell($cells, office_cell_ref($c, $r), $cache, $visiting);
        }
        $nameVal = trim($repl[$nameCol] ?? '');
        if ($nameVal === '') continue; // dòng trống (không có tên chính) — bỏ qua, không dừng hẳn
        if (isset($seenNames[$nameVal])) continue; // đã xử lý tên này ở 1 dòng trước đó rồi
        $seenNames[$nameVal] = true;

        $search = []; $replace = [];
        foreach ($repl as $key => $val) {
            $search[] = '[' . $key . ']';
            $replace[] = htmlspecialchars($val, ENT_QUOTES, 'UTF-8');
        }
        $mergedContent = str_replace($search, $replace, $tplContent);

        if ($extAllRows !== null) {
            $mainJoinVal = trim($repl[$mainJoinCol] ?? '');
            $matchingExtRows = array_values(array_filter($extAllRows, function ($extRow) use ($extJoinCol, $mainJoinVal) {
                return trim($extRow[$extJoinCol]) === $mainJoinVal;
            }));
            $mergedContent = office_mail_merge_expand_tables($mergedContent, $matchingExtRows);
        }

        $title = ($prefix !== '' ? $prefix . ' ' : '') . $nameVal;

        // CHƯA lưu thành ofc_documents ở đây — chỉ trả nội dung THÔ, chờ user bấm "Lưu vào Docs"
        // (office_mail_merge_save() bên dưới) mới thật sự ghi DB. Nhờ vậy kết quả trộn chỉ là
        // bản xem trước, không tự ý xuất hiện trong danh sách Docs khi user chưa xác nhận.
        $created[] = ['title' => $title, 'content' => $mergedContent];
    }
    return ['success' => true, 'data' => $created];
}

/** "Lưu vào Docs" — user đã xem bản xem trước (office_mail_merge() trả nội dung THÔ, chưa lưu),
 *  giờ mới thật sự ghi thành ofc_documents. $items: mảng [{title,content}] — CHÍNH LÀ những gì
 *  office_mail_merge() vừa trả về, client gửi lại nguyên vẹn (không tự chế thêm nội dung khác). */
function office_mail_merge_save($uid, array $items)
{
    $uid = (int) $uid;
    $created = [];
    foreach ($items as $item) {
        $title = trim((string) ($item['title'] ?? ''));
        $content = (string) ($item['content'] ?? '');
        if ($title === '') continue;
        $doc = office_create($uid, 'doc', $title);
        if (!$doc) continue;
        office_save($uid, $doc['id'], $content, 0);
        $created[] = ['id' => (int) $doc['id'], 'title' => $title, 'type' => 'doc'];
    }
    return ['success' => true, 'data' => $created];
}

/** "Tải xuống hết" cho kết quả CHƯA LƯU (chưa có id thật) — y hệt office_mail_merge_zip_body()
 *  bên dưới nhưng nhận thẳng [{title,content}] thay vì đọc lại ofc_documents theo id, đóng gói
 *  bằng office_doc_wrap_body() thay vì office_render_export_body(). Không đụng DB nên không cần
 *  $uid/kiểm quyền. */
function office_mail_merge_zip_preview(array $items)
{
    if (!class_exists('ZipArchive')) return null;
    $tmpFile = tempnam(sys_get_temp_dir(), 'ofz');
    if ($tmpFile === false) return null;
    $zip = new ZipArchive();
    if ($zip->open($tmpFile, ZipArchive::OVERWRITE) !== true) { @unlink($tmpFile); return null; }

    $used = [];
    foreach ($items as $item) {
        $title = trim((string) ($item['title'] ?? ''));
        $content = (string) ($item['content'] ?? '');
        if ($title === '') continue;
        $rendered = office_doc_wrap_body($title, $content);
        $base = $rendered['title'] . '.' . $rendered['ext'];
        $name = $base; $i = 2;
        while (isset($used[$name])) {
            $name = $rendered['title'] . ' (' . $i . ').' . $rendered['ext'];
            $i++;
        }
        $used[$name] = true;
        $zip->addFromString($name, $rendered['body']);
    }
    $zip->close();
    $body = file_get_contents($tmpFile);
    @unlink($tmpFile);
    return $body === false ? null : $body;
}

/** Gộp nhiều tài liệu (thường là kết quả 1 lượt trộn file ĐÃ LƯU) thành 1 tệp .zip — mỗi tài
 *  liệu xuất bằng đúng bản xuất .doc/.xlsx sẵn có (office_render_export_body), không phát minh
 *  định dạng riêng. Trả về chuỗi bytes của .zip, hoặc null nếu extension `zip` không có/không
 *  tạo được. */
function office_mail_merge_zip_body($uid, array $ids)
{
    if (!class_exists('ZipArchive')) return null;
    $uid = (int) $uid;
    $tmpFile = tempnam(sys_get_temp_dir(), 'ofz');
    if ($tmpFile === false) return null;
    $zip = new ZipArchive();
    if ($zip->open($tmpFile, ZipArchive::OVERWRITE) !== true) { @unlink($tmpFile); return null; }

    $used = [];
    foreach ($ids as $id) {
        $doc = office_row((int) $id);
        if (!$doc || !office_can_view($uid, $doc)) continue;
        $rendered = office_render_export_body($doc);
        $base = $rendered['title'] . '.' . $rendered['ext'];
        $name = $base; $i = 2;
        while (isset($used[$name])) {
            $name = $rendered['title'] . ' (' . $i . ').' . $rendered['ext'];
            $i++;
        }
        $used[$name] = true;
        $zip->addFromString($name, $rendered['body']);
    }
    $zip->close();
    $body = file_get_contents($tmpFile);
    @unlink($tmpFile);
    return $body === false ? null : $body;
}

/** Chia sẻ 1 tài liệu/bảng tính (bản xuất) trực tiếp vào Chat (danh bạ), giống fm_share_to_chat. */
function office_share_to_chat($uid, $id, $target_uid, $note = '')
{
    $uid = (int) $uid; $id = (int) $id; $target_uid = (int) $target_uid;
    $doc = office_row($id);
    if (!$doc || !office_can_view($uid, $doc)) return ['success' => false, 'message' => 'Không có quyền.'];
    if ($target_uid <= 0 || $target_uid === $uid) return ['success' => false, 'message' => 'Người nhận không hợp lệ.'];
    if (!function_exists('chat_get_or_create_direct')) require_once __DIR__ . '/../../../libraries/chat.php';

    $rendered = office_render_export_body($doc);
    $chat_dir = APPPATH . DIRECTORY_SEPARATOR . 'public' . DIRECTORY_SEPARATOR . 'uploads' . DIRECTORY_SEPARATOR . 'chat';
    if (!is_dir($chat_dir)) @mkdir($chat_dir, 0775, true);
    $filename = 'oc' . time() . '_' . substr(md5($rendered['title'] . uniqid('', true)), 0, 10) . '.' . $rendered['ext'];
    if (@file_put_contents($chat_dir . DIRECTORY_SEPARATOR . $filename, $rendered['body']) === false) {
        return ['success' => false, 'message' => 'Không gửi được tệp.'];
    }

    $cid = chat_get_or_create_direct($uid, $target_uid);
    $body = trim((string) $note) !== '' ? $note : $rendered['title'];
    $mid = chat_insert_message($cid, $uid, $body, 'file');
    db_insert('chat_attachments', [
        'message_id'    => $mid,
        'file_name'     => $filename,
        'original_name' => mb_substr($rendered['title'] . '.' . $rendered['ext'], 0, 250, 'UTF-8'),
        'mime'          => $rendered['mime'],
        'size'          => strlen($rendered['body']),
        'is_image'      => 0,
    ]);
    return ['success' => true];
}

/** Tải xuống Docs dạng .doc (HTML mở được bằng Word/LibreOffice — chưa có thư viện sinh OOXML thật). */
function office_download_doc($uid, $id)
{
    $uid = (int) $uid; $id = (int) $id;
    $doc = office_row($id);
    if (!$doc || $doc['type'] !== 'doc' || !office_can_view($uid, $doc)) return null;
    $rendered = office_render_export_body($doc);
    return ['title' => $rendered['title'], 'body' => $rendered['body'], 'mime' => 'application/msword', 'ext' => 'doc'];
}

/** Tải xuống Sheets dạng .xlsx thật (dùng thư viện PhpSpreadsheet đã có sẵn trong composer.json). */
function office_download_sheet_xlsx($uid, $id)
{
    $uid = (int) $uid; $id = (int) $id;
    $doc = office_row($id);
    if (!$doc || $doc['type'] !== 'sheet' || !office_can_view($uid, $doc)) return null;
    require_once __DIR__ . '/../../../vendor/autoload.php';

    $title = office_dec($doc['title'] ?? '') ?: 'bang_tinh';
    $data = json_decode(office_dec($doc['content'] ?? ''), true) ?: ['cols' => 0, 'rows' => 0, 'cells' => []];
    $cols = (int) ($data['cols'] ?? 0);
    $rows = (int) ($data['rows'] ?? 0);
    $cells = $data['cells'] ?? [];
    $cache = []; $visiting = [];

    $spreadsheet = new \PhpOffice\PhpSpreadsheet\Spreadsheet();
    $sheet = $spreadsheet->getActiveSheet();
    for ($r = 1; $r <= $rows; $r++) {
        for ($c = 0; $c < $cols; $c++) {
            $ref = office_cell_ref($c, $r);
            $v = office_sheet_compute_cell($cells, $ref, $cache, $visiting);
            if ($v === '' || $v === null) continue;
            $sheet->setCellValue($ref, $v);
            $style = $cells[$ref]['s'] ?? null;
            if ($style) {
                if (!empty($style['b'])) $sheet->getStyle($ref)->getFont()->setBold(true);
                if (!empty($style['i'])) $sheet->getStyle($ref)->getFont()->setItalic(true);
                if (!empty($style['u'])) $sheet->getStyle($ref)->getFont()->setUnderline(true);
                if (!empty($style['color'])) $sheet->getStyle($ref)->getFont()->getColor()->setRGB(ltrim($style['color'], '#'));
                if (!empty($style['bg'])) {
                    $sheet->getStyle($ref)->getFill()
                        ->setFillType(\PhpOffice\PhpSpreadsheet\Style\Fill::FILL_SOLID)
                        ->getStartColor()->setRGB(ltrim($style['bg'], '#'));
                }
                if (!empty($style['align'])) $sheet->getStyle($ref)->getAlignment()->setHorizontal($style['align']);
            }
        }
    }

    $writer = new \PhpOffice\PhpSpreadsheet\Writer\Xlsx($spreadsheet);
    ob_start();
    $writer->save('php://output');
    $bytes = ob_get_clean();
    return [
        'title' => $title, 'body' => $bytes, 'ext' => 'xlsx',
        'mime'  => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
    ];
}

/* ============================================================
 *  Ảnh chèn vào Docs
 * ============================================================ */
function office_upload_dir()
{
    $dir = APPPATH . DIRECTORY_SEPARATOR . 'public' . DIRECTORY_SEPARATOR
         . 'uploads' . DIRECTORY_SEPARATOR . 'office';
    if (!is_dir($dir)) @mkdir($dir, 0775, true);
    return $dir;
}

function office_store_image($file)
{
    if (empty($file) || !isset($file['error']) || $file['error'] !== UPLOAD_ERR_OK) {
        return ['ok' => false, 'reason' => 'lỗi tải lên'];
    }
    $info = @getimagesize($file['tmp_name']);
    if ($info === false) return ['ok' => false, 'reason' => 'không phải hình ảnh'];
    if ($file['size'] > 10 * 1024 * 1024) return ['ok' => false, 'reason' => 'vượt 10MB'];

    $ext = strtolower(pathinfo((string) $file['name'], PATHINFO_EXTENSION));
    $safe_ext = preg_replace('/[^a-z0-9]/', '', $ext) ?: 'png';
    $filename = 'img' . time() . '_' . substr(md5((string) $file['name'] . uniqid('', true)), 0, 10) . '.' . $safe_ext;
    $dest = office_upload_dir() . DIRECTORY_SEPARATOR . $filename;
    if (!move_uploaded_file($file['tmp_name'], $dest)) return ['ok' => false, 'reason' => 'không lưu được tệp'];
    return ['ok' => true, 'url' => 'public/uploads/office/' . $filename];
}

/** Quy đổi (col 0-based, row 1-based) sang ký hiệu kiểu A1. */
function office_cell_ref($col, $row)
{
    $letters = '';
    $c = $col;
    while (true) {
        $letters = chr(65 + ($c % 26)) . $letters;
        $c = intdiv($c, 26) - 1;
        if ($c < 0) break;
    }
    return $letters . $row;
}

/* ============================================================
 *  Bộ tính công thức Sheets (port từ sheets_editor.js) — dùng khi
 *  xuất CSV để giá trị xuất ra khớp với giá trị đang hiển thị trên
 *  lưới, thay vì xuất nguyên văn chuỗi công thức "=...".
 * ============================================================ */
function office_parse_ref($a1)
{
    if (!preg_match('/^([A-Za-z]+)([0-9]+)$/', (string) $a1, $m)) return null;
    $letters = strtoupper($m[1]);
    $col = 0;
    for ($i = 0; $i < strlen($letters); $i++) $col = $col * 26 + (ord($letters[$i]) - 64);
    return ['col' => $col - 1, 'row' => (int) $m[2]];
}

function office_split_top_level($str, $sep)
{
    $out = []; $depth = 0; $cur = '';
    $len = strlen((string) $str);
    for ($i = 0; $i < $len; $i++) {
        $ch = $str[$i];
        if ($ch === '(') $depth++;
        if ($ch === ')') $depth--;
        if ($ch === $sep && $depth === 0) { $out[] = $cur; $cur = ''; } else $cur .= $ch;
    }
    $out[] = $cur;
    return $out;
}

function office_sheet_expand_range($a, $b)
{
    $pa = office_parse_ref($a); $pb = office_parse_ref($b);
    if (!$pa || !$pb) return [];
    $out = [];
    for ($r = min($pa['row'], $pb['row']); $r <= max($pa['row'], $pb['row']); $r++) {
        for ($c = min($pa['col'], $pb['col']); $c <= max($pa['col'], $pb['col']); $c++) {
            $out[] = office_cell_ref($c, $r);
        }
    }
    return $out;
}

function office_sheet_substitute_refs($cells, $expr, &$cache, &$visiting)
{
    return preg_replace_callback('/\$?([A-Za-z]+)\$?([0-9]+)/', function ($m) use ($cells, &$cache, &$visiting) {
        $ref = strtoupper($m[1]) . $m[2];
        $v = office_sheet_compute_cell($cells, $ref, $cache, $visiting);
        return (string) (is_numeric($v) ? $v + 0 : 0);
    }, (string) $expr);
}

/** Chuỗi chỉ còn số + toán tử → an toàn để eval (không còn chữ cái/dấu chấm phẩy). */
function office_sheet_safe_eval($expr, $allowCompare = false)
{
    $pattern = $allowCompare ? '/^[0-9+\-*\/(). <>=!]*$/' : '/^[0-9+\-*\/(). ]*$/';
    if (!preg_match($pattern, (string) $expr)) return null;
    $code = trim((string) $expr);
    if ($code === '') $code = '0';
    try {
        return eval('return (' . $code . ');');
    } catch (\Throwable $e) {
        return null;
    }
}

function office_sheet_eval_arithmetic($cells, $expr, &$cache, &$visiting)
{
    $sub = office_sheet_substitute_refs($cells, $expr, $cache, $visiting);
    $r = office_sheet_safe_eval($sub, false);
    return ($r !== null && is_numeric($r)) ? $r + 0 : '#ERR';
}

function office_sheet_expand_args_numbers($cells, $argsStr, &$cache, &$visiting)
{
    $parts = array_filter(array_map('trim', office_split_top_level($argsStr, ',')), function ($s) { return $s !== ''; });
    $vals = [];
    foreach ($parts as $p) {
        if (preg_match('/^([A-Za-z]+[0-9]+):([A-Za-z]+[0-9]+)$/', $p, $m)) {
            foreach (office_sheet_expand_range(strtoupper($m[1]), strtoupper($m[2])) as $r) {
                $v = office_sheet_compute_cell($cells, $r, $cache, $visiting);
                if (is_numeric($v)) $vals[] = $v + 0;
            }
        } elseif (preg_match('/^[A-Za-z]+[0-9]+$/', $p)) {
            $v = office_sheet_compute_cell($cells, strtoupper($p), $cache, $visiting);
            if (is_numeric($v)) $vals[] = $v + 0;
        } elseif (is_numeric($p)) {
            $vals[] = $p + 0;
        }
    }
    return $vals;
}

function office_sheet_eval_if($cells, $inner, &$cache, &$visiting)
{
    $parts = office_split_top_level($inner, ',');
    if (count($parts) < 2) return '#ERR';
    $condSub = office_sheet_substitute_refs($cells, $parts[0], $cache, $visiting);
    $condExpr = preg_replace('/(<=|>=|==|!=|<|>)/', ' $1 ', $condSub);
    $cond = office_sheet_safe_eval($condExpr, true);
    $branch = trim($cond ? ($parts[1] ?? '') : (isset($parts[2]) ? $parts[2] : '""'));
    if (preg_match('/^[A-Za-z]+[0-9]+$/', $branch)) return office_sheet_compute_cell($cells, strtoupper($branch), $cache, $visiting);
    if (preg_match('/^".*"$/s', $branch)) return substr($branch, 1, -1);
    return office_sheet_eval_arithmetic($cells, $branch, $cache, $visiting);
}

/** So khớp kiểu Excel cho SUMIF/COUNTIF: hỗ trợ toán tử so sánh trong chuỗi (">10","<=5","<>0") hoặc so khớp trực tiếp. */
function office_sheet_match_criteria($value, $criteria)
{
    $criteria = trim((string) $criteria);
    if (preg_match('/^(<=|>=|<>|=|<|>)(.*)$/', $criteria, $m)) {
        $op = $m[1]; $rhsRaw = trim($m[2]);
        if (is_numeric($rhsRaw) && is_numeric($value)) {
            $rhsNum = $rhsRaw + 0; $lhsNum = $value + 0;
            switch ($op) {
                case '=': return $lhsNum === $rhsNum;
                case '<>': return $lhsNum !== $rhsNum;
                case '<': return $lhsNum < $rhsNum;
                case '>': return $lhsNum > $rhsNum;
                case '<=': return $lhsNum <= $rhsNum;
                case '>=': return $lhsNum >= $rhsNum;
            }
        }
        if ($op === '=') return mb_strtolower((string) $value) === mb_strtolower($rhsRaw);
        if ($op === '<>') return mb_strtolower((string) $value) !== mb_strtolower($rhsRaw);
        return false;
    }
    if (is_numeric($criteria) && is_numeric($value)) return ($value + 0) === ($criteria + 0);
    return mb_strtolower((string) $value) === mb_strtolower($criteria);
}

function office_sheet_strip_quotes($s)
{
    $s = trim($s);
    return preg_match('/^".*"$/s', $s) ? substr($s, 1, -1) : $s;
}

function office_sheet_eval_sumif($cells, $argsStr, &$cache, &$visiting)
{
    $parts = array_map('trim', office_split_top_level($argsStr, ','));
    if (count($parts) < 2) return '#ERR';
    if (!preg_match('/^([A-Za-z]+[0-9]+):([A-Za-z]+[0-9]+)$/', $parts[0], $m)) return '#ERR';
    $range = office_sheet_expand_range(strtoupper($m[1]), strtoupper($m[2]));
    $criteria = office_sheet_strip_quotes($parts[1]);
    $sumRange = $range;
    if (!empty($parts[2]) && preg_match('/^([A-Za-z]+[0-9]+):([A-Za-z]+[0-9]+)$/', $parts[2], $m2)) {
        $sumRange = office_sheet_expand_range(strtoupper($m2[1]), strtoupper($m2[2]));
    }
    $total = 0;
    foreach ($range as $i => $r) {
        $val = office_sheet_compute_cell($cells, $r, $cache, $visiting);
        if (office_sheet_match_criteria($val, $criteria) && isset($sumRange[$i])) {
            $sv = office_sheet_compute_cell($cells, $sumRange[$i], $cache, $visiting);
            if (is_numeric($sv)) $total += $sv + 0;
        }
    }
    return $total;
}

function office_sheet_eval_countif($cells, $argsStr, &$cache, &$visiting)
{
    $parts = array_map('trim', office_split_top_level($argsStr, ','));
    if (count($parts) < 2) return '#ERR';
    if (!preg_match('/^([A-Za-z]+[0-9]+):([A-Za-z]+[0-9]+)$/', $parts[0], $m)) return '#ERR';
    $range = office_sheet_expand_range(strtoupper($m[1]), strtoupper($m[2]));
    $criteria = office_sheet_strip_quotes($parts[1]);
    $count = 0;
    foreach ($range as $r) {
        if (office_sheet_match_criteria(office_sheet_compute_cell($cells, $r, $cache, $visiting), $criteria)) $count++;
    }
    return $count;
}

function office_sheet_resolve_lookup_value($cells, $raw, &$cache, &$visiting)
{
    $raw = office_sheet_strip_quotes(trim($raw));
    if (preg_match('/^[A-Za-z]+[0-9]+$/', $raw)) return office_sheet_compute_cell($cells, strtoupper($raw), $cache, $visiting);
    return is_numeric($raw) ? $raw + 0 : $raw;
}
function office_sheet_values_match($a, $b)
{
    if (is_numeric($a) && is_numeric($b)) return ($a + 0) === ($b + 0);
    return mb_strtolower((string) $a) === mb_strtolower((string) $b);
}

function office_sheet_eval_vlookup($cells, $argsStr, &$cache, &$visiting)
{
    $parts = array_map('trim', office_split_top_level($argsStr, ','));
    if (count($parts) < 3) return '#ERR';
    $lookupVal = office_sheet_resolve_lookup_value($cells, $parts[0], $cache, $visiting);
    if (!preg_match('/^([A-Za-z]+[0-9]+):([A-Za-z]+[0-9]+)$/', $parts[1], $m)) return '#ERR';
    $pa = office_parse_ref(strtoupper($m[1])); $pb = office_parse_ref(strtoupper($m[2]));
    $c1 = min($pa['col'], $pb['col']); $r1 = min($pa['row'], $pb['row']); $r2 = max($pa['row'], $pb['row']);
    $colIdx = (int) round((float) office_sheet_substitute_refs($cells, $parts[2], $cache, $visiting)) - 1;
    if ($colIdx < 0) return '#ERR';
    for ($r = $r1; $r <= $r2; $r++) {
        $first = office_sheet_compute_cell($cells, office_cell_ref($c1, $r), $cache, $visiting);
        if (office_sheet_values_match($first, $lookupVal)) {
            return office_sheet_compute_cell($cells, office_cell_ref($c1 + $colIdx, $r), $cache, $visiting);
        }
    }
    return '#N/A';
}

function office_sheet_eval_hlookup($cells, $argsStr, &$cache, &$visiting)
{
    $parts = array_map('trim', office_split_top_level($argsStr, ','));
    if (count($parts) < 3) return '#ERR';
    $lookupVal = office_sheet_resolve_lookup_value($cells, $parts[0], $cache, $visiting);
    if (!preg_match('/^([A-Za-z]+[0-9]+):([A-Za-z]+[0-9]+)$/', $parts[1], $m)) return '#ERR';
    $pa = office_parse_ref(strtoupper($m[1])); $pb = office_parse_ref(strtoupper($m[2]));
    $c1 = min($pa['col'], $pb['col']); $c2 = max($pa['col'], $pb['col']); $r1 = min($pa['row'], $pb['row']);
    $rowIdx = (int) round((float) office_sheet_substitute_refs($cells, $parts[2], $cache, $visiting)) - 1;
    if ($rowIdx < 0) return '#ERR';
    for ($c = $c1; $c <= $c2; $c++) {
        $first = office_sheet_compute_cell($cells, office_cell_ref($c, $r1), $cache, $visiting);
        if (office_sheet_values_match($first, $lookupVal)) {
            return office_sheet_compute_cell($cells, office_cell_ref($c, $r1 + $rowIdx), $cache, $visiting);
        }
    }
    return '#N/A';
}

/** Giá trị hiển thị của 1 ô (đệ quy theo công thức, có bảo vệ vòng lặp). $cache/$visiting dùng chung 1 lượt tính cho cả sheet. */
function office_sheet_compute_cell($cells, $ref, &$cache, &$visiting)
{
    if (array_key_exists($ref, $cache)) return $cache[$ref];
    if (!empty($visiting[$ref])) return '#CYCLE!';
    $raw = isset($cells[$ref]['v']) ? (string) $cells[$ref]['v'] : '';
    $trimmed = trim($raw);
    if ($trimmed === '' || $trimmed[0] !== '=') {
        $val = $trimmed === '' ? '' : (is_numeric($trimmed) ? $trimmed + 0 : $trimmed);
        $cache[$ref] = $val;
        return $val;
    }
    $visiting[$ref] = true;
    $formula = trim(substr($trimmed, 1));
    // IF/VLOOKUP/HLOOKUP có thể trả về CHUỖI → chỉ xử lý riêng khi công thức CHỈ LÀ 1 lệnh gọi
    // hàm này (không nhúng trong biểu thức số học khác) — giống hệt logic phía client (JS).
    if (preg_match('/^(IF|VLOOKUP|HLOOKUP)\((.*)\)$/is', $formula, $bm)) {
        $fnName = strtoupper($bm[1]);
        if ($fnName === 'IF') $result = office_sheet_eval_if($cells, $bm[2], $cache, $visiting);
        elseif ($fnName === 'VLOOKUP') $result = office_sheet_eval_vlookup($cells, $bm[2], $cache, $visiting);
        else $result = office_sheet_eval_hlookup($cells, $bm[2], $cache, $visiting);
    } else {
        $fnExpr = preg_replace_callback('/(SUM|AVERAGE|COUNT|MIN|MAX|SUMIF|COUNTIF)\(([^()]*)\)/i', function ($mm) use ($cells, &$cache, &$visiting) {
            $fn = strtoupper($mm[1]);
            if ($fn === 'SUMIF') return (string) office_sheet_eval_sumif($cells, $mm[2], $cache, $visiting);
            if ($fn === 'COUNTIF') return (string) office_sheet_eval_countif($cells, $mm[2], $cache, $visiting);
            $vals = office_sheet_expand_args_numbers($cells, $mm[2], $cache, $visiting);
            if ($fn === 'SUM') $r = array_sum($vals);
            elseif ($fn === 'AVERAGE') $r = count($vals) ? array_sum($vals) / count($vals) : 0;
            elseif ($fn === 'COUNT') $r = count($vals);
            elseif ($fn === 'MIN') $r = count($vals) ? min($vals) : 0;
            else $r = count($vals) ? max($vals) : 0;
            return (string) $r;
        }, $formula);
        $result = office_sheet_eval_arithmetic($cells, $fnExpr, $cache, $visiting);
    }
    $visiting[$ref] = false;
    $cache[$ref] = $result;
    return $result;
}

/** Chống CSV injection: giá trị bắt đầu bằng =, +, -, @ sẽ được Excel/LibreOffice hiểu thành công thức khi mở file. */
function office_csv_guard($v)
{
    $v = (string) $v;
    return ($v !== '' && strpbrk($v[0], '=+-@') !== false) ? "'" . $v : $v;
}
