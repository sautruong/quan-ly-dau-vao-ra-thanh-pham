<?php
defined('APPPATH') OR exit('Không được quyền truy cập phần này');

/**
 * KHO — controller. View "Soạn hàng" (picking_task).
 *
 * CẢNH BÁO AN TOÀN: permission_guard() FAIL-OPEN với action không nằm trong
 * tbl_views, nên MỌI action AJAX ở đây phải tự kiểm quyền — xem wh_guard_json().
 */

require_once __DIR__ . '/../../../libraries/notifications.php';

function construct()
{
    load_model('warehouse');
}

/* ---------------------------------------------------------------------
 *  Tiện ích
 * -------------------------------------------------------------------*/

function wh_json($payload)
{
    while (ob_get_level() > 0) { ob_end_clean(); }
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($payload, JSON_UNESCAPED_UNICODE);
    exit;
}

function wh_actor_id()
{
    if (!function_exists('permission_current_user')) return 0;
    $u = permission_current_user();
    return (int) ($u['id'] ?? 0);
}

/** Chốt chặn cho mọi endpoint AJAX của module này. */
function wh_guard_json()
{
    if (!function_exists('permission_current_user') || !permission_current_user()) {
        wh_json(['ok' => false, 'msg' => 'Chưa đăng nhập.']);
    }
    $u = permission_current_user();
    if (function_exists('permission_is_admin') && permission_is_admin($u)) return $u;
    $ids = permission_user_ids_for_view('warehouse', 'warehouse', 'picking_task');
    if (!in_array((int) $u['id'], $ids, true)) {
        wh_json(['ok' => false, 'msg' => 'Bạn không có quyền thao tác phiếu soạn.']);
    }
    return $u;
}

/** Phiếu còn sửa được không — dùng chung cho các endpoint ghi. */
function wh_require_editable($slip_id)
{
    $slip = db_fetch_row('SELECT * FROM wh_picking_slips WHERE id = ' . (int) $slip_id . ' LIMIT 1');
    if (!$slip) wh_json(['ok' => false, 'msg' => 'Không tìm thấy phiếu soạn.']);
    if (!wh_slip_editable($slip)) wh_json(['ok' => false, 'msg' => 'Phiếu này đã chốt, không sửa được nữa.']);
    return $slip;
}

/** Trả nguyên trạng thái phiếu sau mỗi thao tác để JS vẽ lại (kể cả tổng kiện). */
function wh_json_slip($slip_id, $extra = [])
{
    $slip = wh_get_slip($slip_id);
    if (!$slip) wh_json(['ok' => false, 'msg' => 'Không tìm thấy phiếu soạn.']);
    wh_json(array_merge(['ok' => true, 'slip' => $slip], $extra));
}

/* ---------------------------------------------------------------------
 *  VIEW: Soạn hàng
 * -------------------------------------------------------------------*/

function picking_taskAction()
{
    wh_ensure_tables();
    wh_ensure_view_registered();

    $slips = wh_list_slips_for_staff();
    $sid   = isset($_GET['slip_id']) ? (int) $_GET['slip_id'] : 0;
    if ($sid <= 0 && $slips) $sid = (int) $slips[0]['id'];
    $slip  = $sid > 0 ? wh_get_slip($sid) : null;

    // Người đang đăng nhập — JS cần để biết dòng vừa được tích là do MÌNH hay đồng nghiệp
    // (chỉ bay avatar khi người khác tích).
    $u = permission_current_user();
    $ten = trim((string) ($u['fullname'] ?? '')) !== '' ? (string) $u['fullname'] : (string) ($u['username'] ?? '');

    load_view('picking_task', [
        'slips' => $slips,
        'slip'  => $slip,
        'me'    => [
            'id'       => (int) ($u['id'] ?? 0),
            'name'     => $ten,
            'avatar'   => (string) ($u['avatar'] ?? ''),
            'initial'  => mb_strtoupper(mb_substr($ten, 0, 1, 'UTF-8'), 'UTF-8'),
            'is_admin' => (function_exists('permission_is_admin') && permission_is_admin($u)) ? 1 : 0,
        ],
    ]);
}

/* ---------------------------------------------------------------------
 *  AJAX — nhân viên thao tác
 * -------------------------------------------------------------------*/

/** Nạp lại 1 phiếu (đổi phiếu bằng chip, hoặc F5 mềm). */
function slip_dataAction()
{
    wh_guard_json();
    $sid = isset($_REQUEST['slip_id']) ? (int) $_REQUEST['slip_id'] : 0;
    if ($sid <= 0) wh_json(['ok' => false, 'msg' => 'Thiếu slip_id.']);
    wh_json_slip($sid, ['slips' => wh_list_slips_for_staff()]);
}

/** Đổi số lượng thực bốc. */
function set_item_qtyAction()
{
    wh_guard_json();
    $item_id = (int) ($_POST['item_id'] ?? 0);
    $qty     = (float) ($_POST['qty'] ?? 0);
    $it = wh_get_item($item_id);
    if (!$it) wh_json(['ok' => false, 'msg' => 'Không tìm thấy dòng hàng.']);
    wh_require_editable((int) $it['slip_id']);
    $r = wh_set_item_qty($item_id, $qty);
    if (empty($r['ok'])) wh_json($r);
    wh_json_slip((int) $it['slip_id']);
}

/** Gán / bỏ số chung kiện. */
function set_item_groupAction()
{
    wh_guard_json();
    $item_id = (int) ($_POST['item_id'] ?? 0);
    $group   = isset($_POST['group']) ? trim((string) $_POST['group']) : '';
    $it = wh_get_item($item_id);
    if (!$it) wh_json(['ok' => false, 'msg' => 'Không tìm thấy dòng hàng.']);
    wh_require_editable((int) $it['slip_id']);
    $r = wh_set_item_group($item_id, $group === '' ? null : $group);
    if (empty($r['ok'])) wh_json($r);
    wh_json_slip((int) $it['slip_id']);
}

/** Tích / bỏ tích "bốc đủ". */
function set_item_pickedAction()
{
    $u = wh_guard_json();
    $item_id = (int) ($_POST['item_id'] ?? 0);
    $picked  = !empty($_POST['picked']);
    $it = wh_get_item($item_id);
    if (!$it) wh_json(['ok' => false, 'msg' => 'Không tìm thấy dòng hàng.']);
    wh_require_editable((int) $it['slip_id']);
    // Ghi luôn AI tích dòng này -> lịch sử hiện đủ avatar khi 2-3 người cùng soạn 1 phiếu.
    $r = wh_set_item_picked($item_id, $picked, (int) ($u['id'] ?? 0));
    if (empty($r['ok'])) wh_json($r);
    wh_json_slip((int) $it['slip_id']);
}

/** Gỡ / phục hồi 1 dòng. */
function set_item_removedAction()
{
    wh_guard_json();
    $item_id = (int) ($_POST['item_id'] ?? 0);
    $removed = !empty($_POST['removed']);
    $it = wh_get_item($item_id);
    if (!$it) wh_json(['ok' => false, 'msg' => 'Không tìm thấy dòng hàng.']);
    wh_require_editable((int) $it['slip_id']);
    $r = wh_remove_item($item_id, $removed);
    if (empty($r['ok'])) wh_json($r);
    wh_json_slip((int) $it['slip_id']);
}

/** Thêm SP / NVL vào phiếu. */
function add_itemAction()
{
    wh_guard_json();
    $slip_id = (int) ($_POST['slip_id'] ?? 0);
    $type    = (string) ($_POST['type'] ?? 'product');
    $item_id = (int) ($_POST['item_id'] ?? 0);
    wh_require_editable($slip_id);
    $r = wh_add_item($slip_id, $type, $item_id, 0);
    if (empty($r['ok'])) wh_json($r);
    wh_json_slip($slip_id, ['new_item_id' => (int) $r['id']]);
}

/** Lưu bảng khai chung kiện. */
function save_kienAction()
{
    wh_guard_json();
    $slip_id = (int) ($_POST['slip_id'] ?? 0);
    $raw     = $_POST['map'] ?? '{}';
    $map     = is_string($raw) ? json_decode($raw, true) : $raw;
    if (!is_array($map)) $map = [];
    wh_require_editable($slip_id);
    $r = wh_save_kien_map($slip_id, $map);
    if (empty($r['ok'])) wh_json($r);
    wh_json_slip($slip_id, ['invalid' => $r['invalid']]);
}

/** Ghi chú phiếu. */
function save_noteAction()
{
    wh_guard_json();
    $slip_id = (int) ($_POST['slip_id'] ?? 0);
    wh_require_editable($slip_id);
    wh_save_note($slip_id, (string) ($_POST['note'] ?? ''));
    wh_json(['ok' => true]);
}

/** "Soạn xong" -> chốt phiếu + đẩy chuông cho admin. */
function finish_slipAction()
{
    $u = wh_guard_json();
    $slip_id = (int) ($_POST['slip_id'] ?? 0);
    wh_require_editable($slip_id);

    $r = wh_finish_slip($slip_id, (int) ($u['id'] ?? 0));
    if (empty($r['ok'])) wh_json($r);

    $slip  = $r['slip'];
    $label = trim((string) ($slip['customer_short'] ?? '')) !== ''
        ? (string) $slip['customer_short'] : (string) $slip['customer_name'];
    $link  = '?mod=order_management&controllers=order_management&action=branch_orders';
    $title = 'Kho đã soạn xong đơn ' . $label;
    $msg   = 'Phiếu soạn đơn "' . $label . '" đã soạn xong — vào xem và bấm "Cập nhật đơn hàng" để lấy số thực bốc.';

    $seen = [];
    foreach (permission_user_ids_for_view('order_management', 'order_management', 'branch_orders') as $uid) {
        if (isset($seen[$uid]) || (int) $uid === (int) ($u['id'] ?? 0)) continue;
        $seen[$uid] = true;
        notify_create((int) $uid, $title, $msg, $link, 'picking_slip', (int) ($u['id'] ?? 0));
    }
    $sender = (int) ($slip['sent_by'] ?? 0);
    if ($sender > 0 && !isset($seen[$sender]) && $sender !== (int) ($u['id'] ?? 0)) {
        notify_create($sender, $title, $msg, $link, 'picking_slip', (int) ($u['id'] ?? 0));
    }

    wh_json(['ok' => true, 'slip' => $slip, 'slips' => wh_list_slips_for_staff()]);
}

/* ---------------------------------------------------------------------
 *  AJAX — Lịch sử soạn hàng
 * -------------------------------------------------------------------*/

/** Danh sách phiếu đã soạn xong (lọc ngày + phân trang). */
function historyAction()
{
    wh_guard_json();
    wh_json([
        'ok'   => true,
        'data' => wh_history_slips(
            (string) ($_REQUEST['from'] ?? ''),
            (string) ($_REQUEST['to'] ?? ''),
            (int) ($_REQUEST['page'] ?? 1),
            (int) ($_REQUEST['per'] ?? 10)
        ),
    ]);
}

/**
 * Xoá hẳn 1 phiếu soạn. CHỈ ADMIN — dùng khi cần sửa lại đơn rồi gửi phiếu mới,
 * bỏ bản cũ cho nhân viên khỏi soạn nhầm.
 */
function delete_slipAction()
{
    $u = wh_guard_json();
    if (!function_exists('permission_is_admin') || !permission_is_admin($u)) {
        wh_json(['ok' => false, 'msg' => 'Chỉ admin mới xoá được phiếu soạn.']);
    }
    $id = (int) ($_POST['slip_id'] ?? 0);
    if ($id <= 0) wh_json(['ok' => false, 'msg' => 'Thiếu slip_id.']);
    wh_json(['ok' => wh_delete_slip($id)]);
}

/** Chi tiết 1 phiếu trong lịch sử (chỉ xem). */
function history_detailAction()
{
    wh_guard_json();
    $d = wh_history_detail((int) ($_REQUEST['slip_id'] ?? 0));
    if (!$d) wh_json(['ok' => false, 'msg' => 'Không tìm thấy phiếu soạn.']);
    wh_json(['ok' => true, 'data' => $d]);
}

/* ---------------------------------------------------------------------
 *  AJAX — dùng chung với order_management
 * -------------------------------------------------------------------*/

/** Tìm SP / NVL bao bì để thêm vào phiếu (dùng lại om_search_products). */
function search_itemAction()
{
    wh_guard_json();
    $kw = isset($_GET['keyword']) ? trim((string) $_GET['keyword']) : '';
    if ($kw === '') wh_json(['ok' => true, 'data' => []]);
    wh_json(['ok' => true, 'data' => om_search_products($kw)]);
}

/** Thông tin 1 dòng cho modal "bấm vào tên sản phẩm": tồn kho + quy cách. */
function item_infoAction()
{
    wh_guard_json();
    $item_id = (int) ($_GET['item_id'] ?? 0);
    $row = wh_get_item($item_id);
    if (!$row) wh_json(['ok' => false, 'msg' => 'Không tìm thấy dòng hàng.']);
    wh_json(['ok' => true, 'data' => wh_enrich_item($row)]);
}
