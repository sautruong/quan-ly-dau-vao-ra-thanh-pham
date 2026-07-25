<?php
defined('APPPATH') OR exit('Không được quyền truy cập phần này');

/* =====================================================================
 *  VIỆC CẦN LÀM — controller (view: Danh sách công việc)
 *  Toàn bộ AJAX đều lọc theo user đang đăng nhập (tk_uid) — dữ liệu
 *  cá nhân hóa, user không đụng nhau.
 * =====================================================================*/

function construct()
{
    load_model('tasks');
    tk_ensure_schema();
}

/** Helper: trả JSON sạch rồi dừng. */
function tk_json($payload)
{
    while (ob_get_level() > 0) { ob_end_clean(); }
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($payload, JSON_UNESCAPED_UNICODE);
    exit;
}

/** Helper: lấy uid hoặc trả lỗi JSON. */
function tk_uid_or_fail()
{
    $uid = tk_uid();
    if ($uid <= 0) tk_json(['success' => false, 'message' => 'Bạn cần đăng nhập.']);
    return $uid;
}

/* ============================================================
 *  Trang chính
 * ============================================================ */

function task_listAction()
{
    $uid = tk_uid();
    load_view('task_list', [
        'groups' => $uid > 0 ? tk_groups_with_items($uid) : [],
    ]);
}

/* ============================================================
 *  AJAX — đọc lại toàn bộ
 * ============================================================ */

function listAction()
{
    $uid = tk_uid_or_fail();
    tk_json(['success' => true, 'groups' => tk_groups_with_items($uid)]);
}

/* ============================================================
 *  AJAX — Nhóm công việc
 * ============================================================ */

function groupCreateAction()
{
    $uid = tk_uid_or_fail();
    $g = tk_group_create($uid, $_POST['title'] ?? '');
    if (!$g) tk_json(['success' => false, 'message' => 'Không tạo được nhóm.']);
    tk_json(['success' => true, 'group' => $g]);
}

function groupRenameAction()
{
    $uid = tk_uid_or_fail();
    $ok = tk_group_rename($uid, $_POST['id'] ?? 0, $_POST['title'] ?? '');
    tk_json(['success' => $ok]);
}

function groupDeleteAction()
{
    $uid = tk_uid_or_fail();
    $ok = tk_group_delete($uid, $_POST['id'] ?? 0);
    tk_json(['success' => $ok]);
}

function groupClearDoneAction()
{
    $uid = tk_uid_or_fail();
    $ok = tk_group_clear_done($uid, $_POST['id'] ?? 0);
    tk_json(['success' => $ok]);
}

function groupsReorderAction()
{
    $uid = tk_uid_or_fail();
    $ids = $_POST['ids'] ?? [];
    if (is_string($ids)) $ids = json_decode($ids, true) ?: [];
    tk_json(['success' => tk_groups_reorder($uid, $ids)]);
}

/* ============================================================
 *  AJAX — Công việc
 * ============================================================ */

function itemCreateAction()
{
    $uid = tk_uid_or_fail();
    $it = tk_item_create(
        $uid,
        $_POST['group_id'] ?? 0,
        $_POST['parent_id'] ?? 0,
        $_POST['content'] ?? '',
        $_POST['remind_at'] ?? null
    );
    if (!$it) tk_json(['success' => false, 'message' => 'Không tạo được việc.']);
    tk_json(['success' => true, 'item' => $it]);
}

function itemUpdateAction()
{
    $uid = tk_uid_or_fail();
    $fields = [];
    if (isset($_POST['content']))     $fields['content']     = $_POST['content'];
    if (isset($_POST['description'])) $fields['description'] = $_POST['description'];
    if (isset($_POST['remind_at']))   $fields['remind_at']   = $_POST['remind_at'];
    $ok = tk_item_update($uid, $_POST['id'] ?? 0, $fields);
    tk_json(['success' => $ok]);
}

function itemToggleAction()
{
    $uid = tk_uid_or_fail();
    $ok = tk_item_toggle($uid, $_POST['id'] ?? 0, !empty($_POST['done']));
    tk_json(['success' => $ok]);
}

function itemDeleteAction()
{
    $uid = tk_uid_or_fail();
    $ok = tk_item_delete($uid, $_POST['id'] ?? 0);
    tk_json(['success' => $ok]);
}

/** Kéo-thả: cập nhật lại thứ tự / nhóm / cha của 1 thùng chứa. */
function reorderAction()
{
    $uid = tk_uid_or_fail();
    $ids = $_POST['ids'] ?? [];
    if (is_string($ids)) $ids = json_decode($ids, true) ?: [];
    $ok = tk_reorder($uid, $_POST['group_id'] ?? 0, $_POST['parent_id'] ?? 0, $ids);
    tk_json(['success' => $ok]);
}
