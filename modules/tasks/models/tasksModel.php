<?php
defined('APPPATH') OR exit('Không được quyền truy cập phần này');

/* =====================================================================
 *  MODULE: VIỆC CẦN LÀM — view "Danh sách công việc" (giống Google Tasks)
 *  prefix: tk_
 *  ---------------------------------------------------------------------
 *  Dữ liệu CÁ NHÂN HÓA cho mỗi user (lọc theo user_id) — user này không
 *  thấy / không đụng dữ liệu của user khác.
 *
 *  - task_groups : 1 dòng = 1 "nhóm công việc" (card/list, dàn ngang).
 *  - task_items  : 1 dòng = 1 công việc.
 *        parent_id = 0  -> việc LEVER 1 (nằm trực tiếp trong nhóm).
 *        parent_id > 0  -> việc LEVER 2 (việc con của 1 lever 1).
 *        group_id luôn lưu (kể cả lever 2) để kéo-thả qua nhóm khác kiểu Trello.
 *
 *  Gotcha múi giờ (xem chat.php / todos.php): XAMPP mặc định Europe/Berlin
 *  → set lại VN cho created_at / done_at / remind_at đúng giờ.
 * =====================================================================*/

date_default_timezone_set('Asia/Ho_Chi_Minh');

// Thư viện mã hóa dữ liệu cá nhân hóa (title nhóm + nội dung/mô tả việc).
require_once __DIR__ . '/../../../libraries/crypto.php';

/* ============================================================
 *  Schema — tự tạo (idempotent, gọi ở construct)
 * ============================================================ */

function tk_ensure_schema()
{
    static $done = false;
    if ($done) return;
    $done = true;

    // title / content dùng TEXT vì lưu DẠNG MÃ HÓA (ciphertext base64 dài hơn bản rõ).
    db_query("CREATE TABLE IF NOT EXISTS task_groups (
        id          INT(11) NOT NULL AUTO_INCREMENT,
        user_id     INT(11) NOT NULL,
        title       TEXT NOT NULL,
        sort_order  INT(11) NOT NULL DEFAULT 0,
        created_at  DATETIME NOT NULL,
        PRIMARY KEY (id),
        KEY idx_tg_user_order (user_id, sort_order)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    db_query("CREATE TABLE IF NOT EXISTS task_items (
        id          INT(11) NOT NULL AUTO_INCREMENT,
        user_id     INT(11) NOT NULL,
        group_id    INT(11) NOT NULL,
        parent_id   INT(11) NOT NULL DEFAULT 0,
        content     TEXT NOT NULL,
        description TEXT DEFAULT NULL,
        is_done     TINYINT(1) NOT NULL DEFAULT 0,
        remind_at   DATETIME DEFAULT NULL,
        sort_order  INT(11) NOT NULL DEFAULT 0,
        created_at  DATETIME NOT NULL,
        done_at     DATETIME DEFAULT NULL,
        PRIMARY KEY (id),
        KEY idx_ti_user (user_id),
        KEY idx_ti_group (group_id, parent_id, sort_order)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    // DB cũ (cột VARCHAR): mở rộng sang TEXT + mã hóa dữ liệu rõ còn sót lại.
    tk_ensure_encrypted_columns();
}

/**
 * Với DB đã tồn tại từ trước: đảm bảo title/content là TEXT rồi mã hóa nốt
 * các dòng còn ở dạng rõ. Kiểm tra information_schema 1 lần/request để
 * tránh ALTER mỗi lần tải; chỉ chạy khi phát hiện cột chưa phải TEXT.
 */
function tk_ensure_encrypted_columns()
{
    static $done = false;
    if ($done) return;
    $done = true;

    $cols = db_fetch_array(
        "SELECT TABLE_NAME, COLUMN_NAME, DATA_TYPE
         FROM information_schema.COLUMNS
         WHERE TABLE_SCHEMA = DATABASE()
           AND ((TABLE_NAME = 'task_groups' AND COLUMN_NAME = 'title')
             OR (TABLE_NAME = 'task_items'  AND COLUMN_NAME = 'content'))"
    ) ?: [];

    $need_migrate = false;
    foreach ($cols as $c) {
        if (strtolower((string) $c['DATA_TYPE']) !== 'text') {
            if ($c['TABLE_NAME'] === 'task_groups') {
                db_query("ALTER TABLE task_groups MODIFY title TEXT NOT NULL");
            } else {
                db_query("ALTER TABLE task_items MODIFY content TEXT NOT NULL");
            }
            $need_migrate = true;
        }
    }
    if ($need_migrate) {
        tk_migrate_encrypt_existing();
    }
}

/** Mã hóa các dòng còn ở dạng rõ (chạy 1 lần khi nâng cấp). Idempotent. */
function tk_migrate_encrypt_existing()
{
    $groups = db_fetch_array("SELECT id, title FROM task_groups WHERE title NOT LIKE 'enc:v1:%'") ?: [];
    foreach ($groups as $g) {
        db_update('task_groups', ['title' => crypto_encrypt($g['title'])], 'id = ' . (int) $g['id']);
    }
    $items = db_fetch_array("SELECT id, content, description FROM task_items
                             WHERE content NOT LIKE 'enc:v1:%'
                                OR (description IS NOT NULL AND description <> '' AND description NOT LIKE 'enc:v1:%')") ?: [];
    foreach ($items as $it) {
        $data = ['content' => crypto_encrypt($it['content'])];
        if ($it['description'] !== null && $it['description'] !== '') {
            $data['description'] = crypto_encrypt($it['description']);
        }
        db_update('task_items', $data, 'id = ' . (int) $it['id']);
    }
}

/* ============================================================
 *  Helpers chung
 * ============================================================ */

/** id user đang đăng nhập (0 nếu không có). */
function tk_uid()
{
    $u = permission_current_user();
    return $u ? (int) $u['id'] : 0;
}

/** Chuẩn hóa datetime-local ('2026-06-19T08:30') -> 'Y-m-d H:i:s' hoặc null. */
function tk_norm_datetime($v)
{
    $v = trim((string) $v);
    if ($v === '') return null;
    $v = str_replace('T', ' ', $v);
    $ts = strtotime($v);
    if ($ts === false) return null;
    return date('Y-m-d H:i:s', $ts);
}

/* ============================================================
 *  NHÓM CÔNG VIỆC (task_groups)
 * ============================================================ */

/** Toàn bộ nhóm của 1 user, KÈM danh sách việc lồng 2 lớp. */
function tk_groups_with_items($uid)
{
    $uid = (int) $uid;
    if ($uid <= 0) return [];
    tk_ensure_schema();

    $groups = db_fetch_array("SELECT id, title, sort_order
                              FROM task_groups
                              WHERE user_id = $uid
                              ORDER BY sort_order ASC, id ASC") ?: [];

    $items = db_fetch_array("SELECT id, group_id, parent_id, content, description,
                                    is_done, remind_at, sort_order
                             FROM task_items
                             WHERE user_id = $uid
                             ORDER BY sort_order ASC, id ASC") ?: [];

    // Gom việc lever 2 theo parent_id.
    $children = [];
    foreach ($items as $it) {
        if ((int) $it['parent_id'] > 0) {
            $children[(int) $it['parent_id']][] = tk_shape_item($it);
        }
    }

    // Gom việc lever 1 theo group_id, đính kèm children.
    $byGroup = [];
    foreach ($items as $it) {
        if ((int) $it['parent_id'] === 0) {
            $row = tk_shape_item($it);
            $row['children'] = $children[(int) $it['id']] ?? [];
            $byGroup[(int) $it['group_id']][] = $row;
        }
    }

    $out = [];
    foreach ($groups as $g) {
        $out[] = [
            'id'    => (int) $g['id'],
            'title' => crypto_decrypt($g['title']),
            'items' => $byGroup[(int) $g['id']] ?? [],
        ];
    }
    return $out;
}

/** Định dạng 1 bản ghi item cho front-end. */
function tk_shape_item($it)
{
    return [
        'id'          => (int) $it['id'],
        'group_id'    => (int) $it['group_id'],
        'parent_id'   => (int) $it['parent_id'],
        'content'     => crypto_decrypt($it['content']),
        'description' => crypto_decrypt($it['description'] ?? ''),
        'is_done'     => (int) $it['is_done'],
        'remind_at'   => $it['remind_at'],
        'sort_order'  => (int) $it['sort_order'],
    ];
}

/** Tạo nhóm mới (đẩy xuống cuối hàng). */
function tk_group_create($uid, $title)
{
    $uid = (int) $uid;
    $title = trim((string) $title);
    if ($uid <= 0) return null;
    if ($title === '') $title = 'Danh sách của tôi';
    if (mb_strlen($title, 'UTF-8') > 255) $title = mb_substr($title, 0, 255, 'UTF-8');
    tk_ensure_schema();

    $row = db_fetch_row("SELECT MAX(sort_order) AS m FROM task_groups WHERE user_id = $uid");
    $order = ($row && $row['m'] !== null) ? ((int) $row['m'] + 1) : 0;

    $id = (int) db_insert('task_groups', [
        'user_id'    => $uid,
        'title'      => crypto_encrypt($title),
        'sort_order' => $order,
        'created_at' => date('Y-m-d H:i:s'),
    ]);
    if ($id <= 0) return null;
    return ['id' => $id, 'title' => $title, 'items' => []];
}

/** Đổi tên tiêu đề nhóm. */
function tk_group_rename($uid, $id, $title)
{
    $uid = (int) $uid;
    $id  = (int) $id;
    $title = trim((string) $title);
    if ($uid <= 0 || $id <= 0 || $title === '') return false;
    if (mb_strlen($title, 'UTF-8') > 255) $title = mb_substr($title, 0, 255, 'UTF-8');
    tk_ensure_schema();
    db_update('task_groups', ['title' => crypto_encrypt($title)], "id = $id AND user_id = $uid");
    return true;
}

/** Xóa nhóm + toàn bộ việc trong nhóm. */
function tk_group_delete($uid, $id)
{
    $uid = (int) $uid;
    $id  = (int) $id;
    if ($uid <= 0 || $id <= 0) return false;
    tk_ensure_schema();
    db_delete('task_items', "group_id = $id AND user_id = $uid");
    db_delete('task_groups', "id = $id AND user_id = $uid");
    return true;
}

/** Xóa các việc ĐÃ hoàn thành trong 1 nhóm (cả lever 1 & lever 2). */
function tk_group_clear_done($uid, $id)
{
    $uid = (int) $uid;
    $id  = (int) $id;
    if ($uid <= 0 || $id <= 0) return false;
    tk_ensure_schema();
    db_delete('task_items', "group_id = $id AND user_id = $uid AND is_done = 1");
    return true;
}

/** Sắp xếp lại thứ tự các nhóm (kéo card theo hàng ngang). */
function tk_groups_reorder($uid, $ids)
{
    $uid = (int) $uid;
    if ($uid <= 0 || !is_array($ids)) return false;
    tk_ensure_schema();
    $order = 0;
    foreach ($ids as $gid) {
        $gid = (int) $gid;
        if ($gid <= 0) continue;
        db_update('task_groups', ['sort_order' => $order], "id = $gid AND user_id = $uid");
        $order++;
    }
    return true;
}

/* ============================================================
 *  CÔNG VIỆC (task_items)
 * ============================================================ */

/** Tạo 1 việc (lever 1 nếu parent_id=0, lever 2 nếu parent_id>0). */
function tk_item_create($uid, $group_id, $parent_id, $content, $remind_at = null)
{
    $uid      = (int) $uid;
    $group_id = (int) $group_id;
    $parent_id = (int) $parent_id;
    $content  = trim((string) $content);
    if ($uid <= 0 || $group_id <= 0 || $content === '') return null;
    if (mb_strlen($content, 'UTF-8') > 500) $content = mb_substr($content, 0, 500, 'UTF-8');
    tk_ensure_schema();

    // Nhóm phải thuộc về user.
    $g = db_fetch_row("SELECT id FROM task_groups WHERE id = $group_id AND user_id = $uid");
    if (!$g) return null;

    // Lever 2: parent phải tồn tại, là của user, và group_id lấy theo parent.
    if ($parent_id > 0) {
        $p = db_fetch_row("SELECT id, group_id FROM task_items
                           WHERE id = $parent_id AND user_id = $uid AND parent_id = 0");
        if (!$p) return null;
        $group_id = (int) $p['group_id'];
    }

    $row = db_fetch_row("SELECT MAX(sort_order) AS m FROM task_items
                         WHERE user_id = $uid AND group_id = $group_id AND parent_id = $parent_id");
    $order = ($row && $row['m'] !== null) ? ((int) $row['m'] + 1) : 0;

    $id = (int) db_insert('task_items', [
        'user_id'    => $uid,
        'group_id'   => $group_id,
        'parent_id'  => $parent_id,
        'content'    => crypto_encrypt($content),
        'description' => null,
        'is_done'    => 0,
        'remind_at'  => tk_norm_datetime($remind_at),
        'sort_order' => $order,
        'created_at' => date('Y-m-d H:i:s'),
        'done_at'    => null,
    ]);
    if ($id <= 0) return null;

    $new = db_fetch_row("SELECT id, group_id, parent_id, content, description,
                                is_done, remind_at, sort_order
                         FROM task_items WHERE id = $id");
    $out = tk_shape_item($new);
    $out['children'] = [];
    return $out;
}

/** Cập nhật 1 trường nội dung / mô tả / nhắc hẹn của 1 việc. */
function tk_item_update($uid, $id, $fields)
{
    $uid = (int) $uid;
    $id  = (int) $id;
    if ($uid <= 0 || $id <= 0 || !is_array($fields)) return false;
    tk_ensure_schema();

    $data = [];
    if (array_key_exists('content', $fields)) {
        $c = trim((string) $fields['content']);
        if ($c === '') return false;
        if (mb_strlen($c, 'UTF-8') > 500) $c = mb_substr($c, 0, 500, 'UTF-8');
        $data['content'] = crypto_encrypt($c);
    }
    if (array_key_exists('description', $fields)) {
        $d = trim((string) $fields['description']);
        $data['description'] = ($d === '') ? null : crypto_encrypt($d);
    }
    if (array_key_exists('remind_at', $fields)) {
        $data['remind_at'] = tk_norm_datetime($fields['remind_at']);
    }
    if (!$data) return false;

    db_update('task_items', $data, "id = $id AND user_id = $uid");
    return true;
}

/** Đánh dấu hoàn thành / bỏ hoàn thành. Lever 1 done -> các lever 2 cũng done. */
function tk_item_toggle($uid, $id, $done)
{
    $uid  = (int) $uid;
    $id   = (int) $id;
    if ($uid <= 0 || $id <= 0) return false;
    tk_ensure_schema();
    $done = $done ? 1 : 0;
    $now  = $done ? "'" . date('Y-m-d H:i:s') . "'" : 'NULL';

    db_query("UPDATE task_items SET is_done = $done, done_at = $now
              WHERE id = $id AND user_id = $uid");

    // Việc lever 1: kéo theo các việc con.
    db_query("UPDATE task_items SET is_done = $done, done_at = $now
              WHERE parent_id = $id AND user_id = $uid");
    return true;
}

/** Xóa 1 việc (lever 1 -> xóa luôn việc con). */
function tk_item_delete($uid, $id)
{
    $uid = (int) $uid;
    $id  = (int) $id;
    if ($uid <= 0 || $id <= 0) return false;
    tk_ensure_schema();
    db_delete('task_items', "(id = $id OR parent_id = $id) AND user_id = $uid");
    return true;
}

/**
 * Kéo-thả: cập nhật lại 1 "thùng chứa" sau khi thả.
 * $group_id  : nhóm đích.
 * $parent_id : 0 = danh sách lever 1 của nhóm; >0 = danh sách lever 2 của 1 việc.
 * $ids       : mảng id việc theo thứ tự mới trong thùng đó.
 */
function tk_reorder($uid, $group_id, $parent_id, $ids)
{
    $uid       = (int) $uid;
    $group_id  = (int) $group_id;
    $parent_id = (int) $parent_id;
    if ($uid <= 0 || $group_id <= 0 || !is_array($ids)) return false;
    tk_ensure_schema();

    // Nếu thả vào danh sách con, group_id lấy theo việc cha.
    if ($parent_id > 0) {
        $p = db_fetch_row("SELECT group_id FROM task_items
                           WHERE id = $parent_id AND user_id = $uid AND parent_id = 0");
        if (!$p) return false;
        $group_id = (int) $p['group_id'];
    }

    $order = 0;
    foreach ($ids as $tid) {
        $tid = (int) $tid;
        if ($tid <= 0) continue;
        db_update('task_items', [
            'group_id'   => $group_id,
            'parent_id'  => $parent_id,
            'sort_order' => $order,
        ], "id = $tid AND user_id = $uid");
        // Việc lever 1 chuyển nhóm: kéo các việc con theo cùng nhóm.
        if ($parent_id === 0) {
            db_query("UPDATE task_items SET group_id = $group_id
                      WHERE parent_id = $tid AND user_id = $uid");
        }
        $order++;
    }
    return true;
}
