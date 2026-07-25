<?php
/**
 * order_management controller — phía nhà máy: xem đơn từ chi nhánh + in phiếu soạn hàng.
 */
require_once __DIR__ . '/../../../libraries/notifications.php';

function construct()
{
    load_model('order_management');
}

/** Id user nhà máy đang thao tác (để gắn actor cho chuông). */
function om_actor_id()
{
    if (!function_exists('permission_current_user')) return 0;
    $u = permission_current_user();
    return (int) ($u['id'] ?? 0);
}

/** Đẩy chuông cho người gửi đơn (bán hàng) khi nhà máy có thao tác. */
function om_notify_seller($order_meta, $title, $message)
{
    $uid = (int) ($order_meta['user_id'] ?? 0);
    if ($uid <= 0) return;
    $link = '?mod=sell_factory&controllers=sell_factory&action=order_history';
    notify_create($uid, $title, $message, $link, 'factory_order', om_actor_id());
}

/* ------------------------------------------------------------------
 * VIEW: Đơn hàng từ chi nhánh
 * ------------------------------------------------------------------ */
function branch_ordersAction()
{
    om_apply_auto_delete_picked();
    $page = isset($_GET['page']) ? (int) $_GET['page'] : 1;
    $data = om_get_branch_orders($page, 10);
    $data['customers'] = om_get_customers();
    $data['auto_delete_days'] = om_get_auto_delete_days();
    load_view('branch_orders', [
        'data' => $data,
    ]);
}

/* AJAX: "Xem hàng thiếu" (modal Xem đơn hàng) -> click ô Thiếu để đối chiếu công thức với tồn NVL. */
function check_shortage_recipeAction()
{
    header('Content-Type: application/json; charset=utf-8');
    $pid      = isset($_GET['product_id']) ? (int) $_GET['product_id'] : 0;
    $shortage = isset($_GET['shortage']) ? (float) $_GET['shortage'] : 0;
    if ($pid <= 0 || $shortage <= 0) { echo json_encode(['ok' => false, 'msg' => 'Thiếu dữ liệu']); return; }
    echo json_encode(om_check_shortage_recipe($pid, $shortage), JSON_UNESCAPED_UNICODE);
}

/* AJAX: "Thêm vào lịch sản xuất" (modal Xem hàng thiếu) -> gán các sản phẩm thiếu vào Kế hoạch
 * sản xuất dài ngày (production_staff / long_term_production_plans), ngày tính theo tùy chọn
 * chọn từ dropdown. Số lượng dự kiến = lần sản xuất gần nhất (finished_product_production_data),
 * chưa có lần nào thì lấy 0. POST: product_ids (JSON mảng product_id), option. */
function ajax_add_shortage_to_planAction()
{
    header('Content-Type: application/json; charset=utf-8');
    require_once __DIR__ . '/../../production_staff/models/production_staffModel.php';

    $offsets = ['tomorrow' => 1, '2d' => 2, '3d' => 3, 'nextweek' => 7];
    $option  = (string) ($_POST['option'] ?? '');
    if (!isset($offsets[$option])) {
        echo json_encode(['ok' => false, 'msg' => 'Chưa chọn thời điểm.']);
        exit;
    }
    $plan_date = date('Y-m-d', strtotime('+' . $offsets[$option] . ' day'));

    $ids = $_POST['product_ids'] ?? '';
    if (is_string($ids)) {
        $decoded = json_decode($ids, true);
        $ids     = is_array($decoded) ? $decoded : [];
    }
    if (!is_array($ids) || empty($ids)) {
        echo json_encode(['ok' => false, 'msg' => 'Không có sản phẩm nào để thêm.']);
        exit;
    }

    $added = 0;
    foreach ($ids as $pid) {
        $pid = (int) $pid;
        if ($pid <= 0) continue;
        $row = db_fetch_row(
            "SELECT quantity FROM finished_product_production_data
             WHERE product_id = $pid ORDER BY created_at DESC, id DESC LIMIT 1"
        );
        $qty = $row ? (float) $row['quantity'] : 0;
        if (ltp_add_product($plan_date, $pid, $qty) > 0) $added++;
    }

    echo json_encode(['ok' => true, 'added' => $added, 'plan_date' => $plan_date], JSON_UNESCAPED_UNICODE);
    exit;
}

/* AJAX: lưu cài đặt tự xóa đơn đã bốc (0/1/3/7/30 ngày). */
function set_auto_delete_settingAction()
{
    header('Content-Type: application/json; charset=utf-8');
    $days = isset($_POST['days']) ? (int) $_POST['days'] : 0;
    echo json_encode(['ok' => om_set_auto_delete_days($days)], JSON_UNESCAPED_UNICODE);
}

/* AJAX: nhà máy tạo đơn hàng thủ công cho 1 chi nhánh (không qua bán hàng gửi lên). */
function create_manual_orderAction()
{
    header('Content-Type: application/json; charset=utf-8');
    $customer_id = isset($_POST['customer_id']) ? (int) $_POST['customer_id'] : 0;
    $items       = isset($_POST['items']) ? json_decode($_POST['items'], true) : [];
    $order_date  = isset($_POST['order_date']) ? trim((string) $_POST['order_date']) : '';
    $note        = isset($_POST['note']) ? trim((string) $_POST['note']) : '';
    if ($customer_id <= 0) { echo json_encode(['ok' => false, 'msg' => 'Chưa chọn chi nhánh']); return; }
    if (!is_array($items) || !$items) { echo json_encode(['ok' => false, 'msg' => 'Chưa có sản phẩm']); return; }
    $id = om_create_manual_order($customer_id, $items, $order_date, $note);
    if (!$id) { echo json_encode(['ok' => false, 'msg' => 'Không tạo được đơn']); return; }
    echo json_encode(['ok' => true, 'id' => $id], JSON_UNESCAPED_UNICODE);
}

/* ------------------------------------------------------------------
 * VIEW: Phiếu soạn hàng (A4) — từ đơn (order_id) hoặc nhập tay
 * ------------------------------------------------------------------ */
function picking_slipAction()
{
    $order_id = isset($_GET['order_id']) ? (int) $_GET['order_id'] : 0;
    $order    = $order_id > 0 ? om_get_order($order_id) : null;
    load_view('picking_slip', [
        'order'     => $order,
        'customers' => om_get_customers(),
    ]);
}

/* ------------------------------------------------------------------
 * AJAX: tìm sản phẩm cho nhập tay phiếu soạn
 * ------------------------------------------------------------------ */
function search_product_slipAction()
{
    header('Content-Type: application/json; charset=utf-8');
    $keyword = isset($_GET['keyword']) ? trim($_GET['keyword']) : '';
    if ($keyword === '') { echo json_encode(['ok' => true, 'data' => []]); return; }
    echo json_encode(['ok' => true, 'data' => om_search_products($keyword)], JSON_UNESCAPED_UNICODE);
}

/* AJAX: đánh dấu / bỏ đánh dấu đã bốc. */
function set_pickedAction()
{
    header('Content-Type: application/json; charset=utf-8');
    $id     = isset($_POST['id']) ? (int) $_POST['id'] : 0;
    $picked = !empty($_POST['picked']);
    echo json_encode(['ok' => om_set_picked($id, $picked)], JSON_UNESCAPED_UNICODE);
}

/* AJAX: khóa / mở khóa đơn. */
function set_lockAction()
{
    header('Content-Type: application/json; charset=utf-8');
    $id     = isset($_POST['id']) ? (int) $_POST['id'] : 0;
    $locked = !empty($_POST['locked']);
    echo json_encode(['ok' => om_set_lock($id, $locked)], JSON_UNESCAPED_UNICODE);
}

/* AJAX: nhà máy ẩn đơn. Nếu đơn CHƯA bốc -> báo bán hàng "đã xem & bỏ qua". */
function delete_orderAction()
{
    header('Content-Type: application/json; charset=utf-8');
    $id = isset($_POST['id']) ? (int) $_POST['id'] : 0;
    if ($id <= 0) { echo json_encode(['ok' => false, 'msg' => 'Thiếu id']); return; }
    $meta = om_get_order_meta($id);
    $ok   = om_delete_order($id);
    // Chưa đánh dấu đã bốc mà nhà máy bỏ đơn -> báo lại cho bán hàng.
    if ($ok && $meta && empty($meta['picked'])) {
        om_notify_seller($meta, 'Nhà máy đã bỏ qua đơn hàng',
            'Nhà máy đã xem đơn "' . ($meta['customer_name'] ?? '') . '" và bỏ qua đơn hàng này.');
    }
    echo json_encode(['ok' => $ok], JSON_UNESCAPED_UNICODE);
}

/* AJAX: nhà máy "Xác nhận đơn hàng" (không đẩy thông báo cho bán hàng). */
function set_confirmedAction()
{
    header('Content-Type: application/json; charset=utf-8');
    $id        = isset($_POST['id']) ? (int) $_POST['id'] : 0;
    $confirmed = !empty($_POST['confirmed']);
    if ($id <= 0) { echo json_encode(['ok' => false, 'msg' => 'Thiếu id']); return; }
    $ok = om_set_confirmed($id, $confirmed);
    echo json_encode(['ok' => $ok], JSON_UNESCAPED_UNICODE);
}

/* AJAX: hẹn ngày bốc + gửi thông báo cho bán hàng. */
function set_pickupAction()
{
    header('Content-Type: application/json; charset=utf-8');
    $id     = isset($_POST['id']) ? (int) $_POST['id'] : 0;
    $pickup = isset($_POST['pickup_time']) ? trim($_POST['pickup_time']) : '';
    if ($id <= 0) { echo json_encode(['ok' => false, 'msg' => 'Thiếu id']); return; }
    $meta = om_get_order_meta($id);
    om_set_pickup($id, $pickup);
    $when = $pickup ? date('H:i d/m/Y', strtotime($pickup)) : '';
    om_notify_seller($meta, 'Nhà máy hẹn lịch bốc hàng',
        'Đơn "' . ($meta['customer_name'] ?? '') . '" sẽ được bốc lúc ' . $when);
    echo json_encode(['ok' => true], JSON_UNESCAPED_UNICODE);
}

/* AJAX: nhà máy điều chỉnh số lượng -> cập nhật đơn + đẩy chuông bán hàng. */
function update_quantitiesAction()
{
    header('Content-Type: application/json; charset=utf-8');
    $raw     = file_get_contents('php://input');
    $payload = json_decode($raw, true);
    $id      = (int) ($payload['id'] ?? 0);
    $qty_map = (is_array($payload) && isset($payload['quantities']) && is_array($payload['quantities']))
        ? $payload['quantities'] : [];
    $additions = (is_array($payload) && isset($payload['additions']) && is_array($payload['additions']))
        ? $payload['additions'] : [];
    $removed = (is_array($payload) && isset($payload['removed']) && is_array($payload['removed']))
        ? $payload['removed'] : [];
    if ($id <= 0) { echo json_encode(['ok' => false, 'msg' => 'Thiếu id']); return; }
    $meta = om_get_order_meta($id);
    $ok   = om_update_quantities($id, $qty_map, $additions, $removed);
    if ($ok) {
        om_notify_seller($meta, 'Nhà máy đã điều chỉnh đơn hàng',
            'Số lượng đơn "' . ($meta['customer_name'] ?? '') . '" đã được nhà máy chỉnh cho phù hợp quy cách.');
    }
    echo json_encode(['ok' => $ok], JSON_UNESCAPED_UNICODE);
}

/* AJAX: thông tin 1 SP / NVL (bao bì, tồn, quy đổi kiện) cho dòng phiếu soạn nhập tay. */
function product_slip_infoAction()
{
    header('Content-Type: application/json; charset=utf-8');
    $pid  = isset($_GET['product_id']) ? (int) $_GET['product_id'] : 0;
    $type = isset($_GET['type']) ? trim((string) $_GET['type']) : 'product';
    if ($pid <= 0) { echo json_encode(['ok' => false, 'msg' => 'Thiếu product_id']); return; }
    $row = ($type === 'material') ? om_get_material_for_slip($pid) : om_get_product_for_slip($pid);
    if (!$row) { echo json_encode(['ok' => false, 'msg' => 'Không tìm thấy hàng hóa']); return; }
    echo json_encode(['ok' => true, 'data' => $row], JSON_UNESCAPED_UNICODE);
}

/* AJAX: danh sách SP + thứ tự hiển thị phiếu soạn (cho modal Setting). */
function slip_order_listAction()
{
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['ok' => true, 'data' => om_get_products_with_slip_order()], JSON_UNESCAPED_UNICODE);
}

/* AJAX: lưu thứ tự hiển thị phiếu soạn. POST JSON { orders: { product_id: sort } }. */
function slip_order_saveAction()
{
    header('Content-Type: application/json; charset=utf-8');
    $raw     = file_get_contents('php://input');
    $payload = json_decode($raw, true);
    $orders  = (is_array($payload) && isset($payload['orders']) && is_array($payload['orders']))
        ? $payload['orders'] : [];
    echo json_encode(['ok' => om_save_slip_order($orders)], JSON_UNESCAPED_UNICODE);
}

/* AJAX: prefill phiếu xuất kho bán hàng từ 1 đơn (picking_slip chế độ từ đơn). */
function order_prefillAction()
{
    header('Content-Type: application/json; charset=utf-8');
    $id = isset($_GET['id']) ? (int) $_GET['id'] : 0;
    if ($id <= 0) { echo json_encode(['ok' => false, 'msg' => 'Thiếu id']); return; }
    $data = om_get_order_prefill($id);
    if (!$data) { echo json_encode(['ok' => false, 'msg' => 'Không tìm thấy đơn']); return; }
    echo json_encode(['ok' => true, 'data' => $data], JSON_UNESCAPED_UNICODE);
}

/* AJAX: "Xác nhận xuất kho" -> đánh dấu đã bốc + báo bán hàng + trả prefill để đẩy sang sales_delivery_note. */
function confirm_outboundAction()
{
    header('Content-Type: application/json; charset=utf-8');
    $id = isset($_POST['id']) ? (int) $_POST['id'] : 0;
    if ($id <= 0) { echo json_encode(['ok' => false, 'msg' => 'Thiếu id']); return; }
    $meta    = om_get_order_meta($id);
    $prefill = om_get_order_prefill($id);
    // Xác nhận xuất kho = tích đã bốc.
    om_set_picked($id, true);
    if ($meta && empty($meta['picked'])) {
        om_notify_seller($meta, 'Đơn đã được xuất kho',
            'Đơn "' . ($meta['customer_name'] ?? '') . '" đã được nhà máy xác nhận xuất kho (đã bốc).');
    }
    echo json_encode(['ok' => true, 'prefill' => $prefill], JSON_UNESCAPED_UNICODE);
}
