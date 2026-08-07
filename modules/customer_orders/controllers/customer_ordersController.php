<?php
defined('APPPATH') OR exit('Không được quyền truy cập phần này');

/**
 * QUẢN LÝ ĐƠN HÀNG — controller.
 *
 * CẢNH BÁO AN TOÀN: permission_guard() FAIL-OPEN với action không nằm trong tbl_views
 * (helper/permission.php — "$view = permission_find_view(...); if (!$view) return;"), nên
 * MỌI action AJAX ở đây phải TỰ kiểm quyền. Cũng vì thế module này tuyệt đối không trỏ
 * AJAX về các endpoint hóa đơn của admin_factory — chúng không kiểm quyền dòng nào.
 *
 * Quy ước JSON: luôn trả {ok: bool, ...} (JS ô hóa đơn đọc res.ok, không đọc res.success).
 */

function construct()
{
    load_model('customer_orders');
}

/* ---------------------------------------------------------------------
 *  Tiện ích
 * -------------------------------------------------------------------*/

/** Trả JSON rồi dừng hẳn. ob_end_clean để mọi cảnh báo lỡ in ra không phá cú pháp JSON. */
function co_json($payload)
{
    while (ob_get_level() > 0) { ob_end_clean(); }
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($payload, JSON_UNESCAPED_UNICODE);
    exit;
}

/** Chốt chặn chung cho mọi action AJAX. Trả về phạm vi xem. */
function co_require_scope_json()
{
    if (!permission_current_user()) {
        co_json(['ok' => false, 'message' => 'Chưa đăng nhập.']);
    }
    $s = co_scope();
    if ($s['mode'] === 'none') {
        co_json(['ok' => false, 'message' => 'Tài khoản chưa được định danh khách hàng.']);
    }
    return $s;
}

/* ---------------------------------------------------------------------
 *  TRANG "ĐƠN HÀNG"
 * -------------------------------------------------------------------*/

function ordersAction()
{
    co_ensure_tables();
    co_ensure_view_registered();

    $scope = co_scope();
    $from  = isset($_GET['from']) ? trim((string) $_GET['from']) : '';
    $to    = isset($_GET['to'])   ? trim((string) $_GET['to'])   : '';
    $invf  = isset($_GET['inv'])  ? (string) $_GET['inv']  : '';   // '' | 'has' | 'none'
    $per   = (int) ($_GET['per'] ?? 25);
    if (!in_array($per, [10, 25, 50, 100], true)) $per = 25;

    // CHỖ CHẶN QUAN TRỌNG NHẤT: khách gõ tay ?customer_id=<khách khác> vẫn bị ép về khách
    // của chính mình. Không được chỉ ẩn nút lọc ở giao diện.
    $cid  = co_effective_customer_id((int) ($_GET['customer_id'] ?? 0));
    $rows = co_get_orders($from, $to, $cid);

    // 2 map hóa đơn RIÊNG theo từng namespace type — bắt buộc, vì id của 2 nguồn có thể trùng.
    $order_ids = $export_ids = [];
    foreach ($rows as $r) {
        if (($r['inv_type'] ?? '') === 'sales_export_invoice') $export_ids[] = (int) $r['id'];
        else                                                   $order_ids[]  = (int) $r['id'];
    }
    $inv_map = [
        'sales_invoice'        => co_invoices_map('sales_invoice', $order_ids),
        'sales_export_invoice' => co_invoices_map('sales_export_invoice', $export_ids),
    ];

    // Lọc "Đã / Chưa tải hóa đơn" làm ở PHP SAU khi đã có map — rẻ và đúng, vì rows đến từ UNION.
    if ($invf === 'has' || $invf === 'none') {
        $rows = array_values(array_filter($rows, static function ($r) use ($inv_map, $invf) {
            $t   = (string) ($r['inv_type'] ?? '');
            $has = !empty($inv_map[$t][(int) $r['id']]);
            return $invf === 'has' ? $has : !$has;
        }));
    }

    load_view('orders', [
        'rows'        => $rows,
        'inv_map'     => $inv_map,
        'summaries'   => co_order_summaries($rows),
        'from'        => $from,
        'to'          => $to,
        'inv_filter'  => $invf,
        'per_page'    => $per,
        'scope'       => $scope,
        'customer_id' => $cid > 0 ? $cid : 0,
        'customers'   => $scope['mode'] === 'admin' ? co_customer_list() : [],
    ]);
}

/* ---------------------------------------------------------------------
 *  AJAX
 * -------------------------------------------------------------------*/

/** Chi tiết mặt hàng của 1 đơn (modal khi bấm vào dòng). */
function order_detailAction()
{
    $s = co_require_scope_json();
    $inv_type = (string) ($_POST['inv_type'] ?? '');
    $id       = (int) ($_POST['id'] ?? 0);
    if (!co_can_touch_order($inv_type, $id)) {
        co_json(['ok' => false, 'message' => 'Không có quyền với đơn hàng này.']);
    }
    // Khách thì BỎ HẲN customer_id do client gửi lên, dùng của chính họ.
    $cid = $s['mode'] === 'admin' ? co_order_customer_id($inv_type, $id) : (int) $s['customer_id'];
    co_json(['ok' => true, 'lines' => co_order_lines($cid, (string) ($_POST['created_at'] ?? ''))]);
}

/** Danh sách hóa đơn của 1 đơn (làm mới ô sau khi tải/xóa). */
function invoice_listAction()
{
    co_require_scope_json();
    $inv_type = (string) ($_POST['inv_type'] ?? $_GET['inv_type'] ?? '');
    $id       = (int) ($_POST['id'] ?? $_GET['id'] ?? 0);
    if (!co_can_touch_order($inv_type, $id)) {
        co_json(['ok' => false, 'message' => 'Không có quyền với đơn hàng này.']);
    }
    co_json(['ok' => true, 'files' => wri_list($id, $inv_type), 'me' => (int) co_scope()['user_id']]);
}

/** Tải 1 hoặc nhiều ảnh hóa đơn lên cho 1 đơn. */
function invoice_uploadAction()
{
    co_require_scope_json();
    $inv_type = (string) ($_POST['inv_type'] ?? '');
    $id       = (int) ($_POST['id'] ?? 0);
    if (!co_can_touch_order($inv_type, $id)) {
        co_json(['ok' => false, 'message' => 'Không có quyền với đơn hàng này.']);
    }
    if (empty($_FILES['files'])) {
        co_json(['ok' => false, 'message' => 'Không nhận được tệp.']);
    }
    $res = co_save_invoices($inv_type, $id, $_FILES['files']);
    co_json([
        'ok'     => !empty($res['ok']),
        'saved'  => $res['saved'] ?? [],
        'errors' => $res['errors'] ?? [],
        'files'  => wri_list($id, $inv_type),
        'me'     => (int) co_scope()['user_id'],
    ]);
}

/** Xóa 1 hóa đơn (khách chỉ xóa được hóa đơn do chính mình tải). */
function invoice_deleteAction()
{
    co_require_scope_json();
    $res = co_delete_invoice((int) ($_POST['id'] ?? 0));
    co_json(['ok' => !empty($res['ok']), 'message' => $res['message'] ?? '']);
}

/** Danh bạ chat cho hộp chọn người nhận. */
function chat_contactsAction()
{
    $s = co_require_scope_json();
    require_once APPPATH . DIRECTORY_SEPARATOR . 'libraries' . DIRECTORY_SEPARATOR . 'chat.php';
    chat_ensure_tables();
    co_json(['ok' => true, 'data' => chat_contacts((int) $s['user_id'])]);
}

/** Gửi 1 ảnh hóa đơn qua chat. */
function invoice_share_chatAction()
{
    co_require_scope_json();
    $targets = isset($_POST['targets']) ? (array) $_POST['targets'] : [];
    $res = co_share_invoice_to_chat((int) ($_POST['id'] ?? 0), $targets, (string) ($_POST['note'] ?? ''));
    co_json(['ok' => !empty($res['ok']), 'sent' => (int) ($res['sent'] ?? 0), 'message' => $res['message'] ?? '']);
}

/** Nút "Check database" — dùng chung thư viện có sẵn. */
function check_databaseAction()
{
    require_once APPPATH . DIRECTORY_SEPARATOR . 'libraries' . DIRECTORY_SEPARATOR . 'check_database.php';
    cdb_handle_ajax();
}
