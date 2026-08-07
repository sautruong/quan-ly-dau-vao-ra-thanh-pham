<?php
// Dùng lại helper/queries kế hoạch SX dài ngày của module production_staff
// (ltp_build_upcoming_days, ltp_get_items_grouped, ltp_weekday_vi).
require_once __DIR__ . '/../../production_staff/models/production_staffModel.php';

function construct()
{
    load_model('sell_factory');
}

/* ------------------------------------------------------------------
 * MAIN PAGE
 * ------------------------------------------------------------------ */
function order_factoryAction()
{
    // Lưới tồn: toàn bộ sản phẩm còn tồn, nhóm theo danh mục (render full).
    $product_groups = sf_get_products_grouped_by_category();
    $categories     = sf_get_all_categories();

    $uid = sf_current_user_id();
    // "Trước đó" trong modal giỏ: vài đơn gần nhất của chính user.
    $history     = sf_get_history(1, 5, $uid);
    // Chi nhánh ghi nhớ (nhập 1 lần dùng nhiều lần).
    $last_branch = sf_get_last_branch($uid);

    load_view('order_factory', [
        'product_groups' => $product_groups,
        'categories'     => $categories,
        'history'        => $history,
        'last_branch'    => $last_branch,
        // Sản phẩm của mẻ sản xuất gần nhất -> ô sản phẩm chớp sáng khi vào trang.
        'recent_prod_ids' => sf_get_recent_production_product_ids(),
    ]);
}

/* ------------------------------------------------------------------
 * LỊCH SỬ ĐẶT HÀNG (cá nhân) — tab riêng cạnh "KHSX Dự Kiến".
 * ------------------------------------------------------------------ */
function order_historyAction()
{
    $page = isset($_GET['page']) ? (int) $_GET['page'] : 1;
    load_view('order_history', [
        'history'    => sf_get_history($page, 12, sf_current_user_id()),
        'page_title' => 'Lịch Sử đặt hàng nhà máy',
    ]);
}

/* AJAX: danh bạ chat (cho hộp chọn người nhận của nút "Gửi qua chat").
   Đi vòng qua đây thay vì gọi thẳng ?mod=chat&action=contacts để trang này không phụ thuộc
   vào phân quyền/định dạng trả về của module chat. */
function chat_contactsAction()
{
    header('Content-Type: application/json; charset=utf-8');
    $uid = sf_current_user_id();
    if ($uid <= 0) { echo json_encode(['ok' => false, 'msg' => 'Chưa đăng nhập.'], JSON_UNESCAPED_UNICODE); return; }
    require_once APPPATH . DIRECTORY_SEPARATOR . 'libraries' . DIRECTORY_SEPARATOR . 'chat.php';
    chat_ensure_tables();
    echo json_encode(['ok' => true, 'data' => chat_contacts($uid)], JSON_UNESCAPED_UNICODE);
}

/* AJAX: gửi ảnh đơn hàng vào chat. multipart: image (PNG), targets[] , note. */
function share_order_to_chatAction()
{
    header('Content-Type: application/json; charset=utf-8');
    $uid = sf_current_user_id();
    if ($uid <= 0) { echo json_encode(['ok' => false, 'msg' => 'Chưa đăng nhập.'], JSON_UNESCAPED_UNICODE); return; }

    $targets = isset($_POST['targets']) ? (array) $_POST['targets'] : [];
    if (empty($_FILES['image']) || ($_FILES['image']['error'] ?? 1) !== UPLOAD_ERR_OK) {
        echo json_encode(['ok' => false, 'msg' => 'Thiếu ảnh đơn hàng.'], JSON_UNESCAPED_UNICODE);
        return;
    }
    $res = sf_share_order_to_chat($uid, $_FILES['image'], $targets, (string) ($_POST['note'] ?? ''));
    echo json_encode(
        ['ok' => !empty($res['ok']), 'sent' => (int) ($res['sent'] ?? 0), 'msg' => (string) ($res['message'] ?? '')],
        JSON_UNESCAPED_UNICODE
    );
}

/* AJAX: danh sách sản phẩm + thứ tự hiển thị (cho modal cài đặt). */
function display_order_listAction()
{
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['ok' => true, 'data' => sf_get_all_products_for_order()], JSON_UNESCAPED_UNICODE);
}

/* AJAX: lưu thứ tự hiển thị. POST JSON { orders: { product_id: sort } }. */
function save_display_orderAction()
{
    header('Content-Type: application/json; charset=utf-8');
    $raw     = file_get_contents('php://input');
    $payload = json_decode($raw, true);
    $orders  = (is_array($payload) && isset($payload['orders']) && is_array($payload['orders']))
        ? $payload['orders'] : [];
    echo json_encode(['ok' => sf_save_display_order($orders)], JSON_UNESCAPED_UNICODE);
}

/* AJAX: ghi nhớ chi nhánh đang chọn cho user hiện tại. */
function set_branchAction()
{
    header('Content-Type: application/json; charset=utf-8');
    $cid   = isset($_POST['customer_id']) && $_POST['customer_id'] !== '' ? (int) $_POST['customer_id'] : null;
    $cname = isset($_POST['customer_name']) ? trim($_POST['customer_name']) : '';
    $ok    = sf_set_last_branch(sf_current_user_id(), $cid, $cname);
    echo json_encode(['ok' => $ok], JSON_UNESCAPED_UNICODE);
}

/* ------------------------------------------------------------------
 * KHSX DỰ KIẾN (read-only) — tab cho đội bán hàng xem lịch SX nhà máy.
 * ------------------------------------------------------------------ */
function production_forecastAction()
{
    load_view('production_forecast', [
        'days'       => sf_get_production_forecast(),
        'page_title' => 'KHSX của nhà máy các ngày tới',
    ]);
}

/* ------------------------------------------------------------------
 * AJAX ENDPOINTS
 * ------------------------------------------------------------------ */

// Đối chiếu tồn nhà máy cho đơn đặt hàng hiện tại (TASK 5).
// POST JSON: { items: [ {product_id, qty}, ... ] }
function order_inventory_checkAction()
{
    header('Content-Type: application/json; charset=utf-8');
    $raw     = file_get_contents('php://input');
    $payload = json_decode($raw, true);
    $items   = (is_array($payload) && isset($payload['items']) && is_array($payload['items']))
        ? $payload['items'] : [];
    echo json_encode(['ok' => true, 'data' => sf_check_order_inventory($items)], JSON_UNESCAPED_UNICODE);
    exit;
}

// Modal chi tiết sản phẩm (info)
function product_detailAction()
{
    header('Content-Type: application/json; charset=utf-8');
    $product_id = isset($_GET['product_id']) ? (int)$_GET['product_id'] : 0;
    if ($product_id <= 0) {
        echo json_encode(['ok' => false, 'msg' => 'Thiếu product_id']);
        return;
    }
    $row = sf_get_product_detail($product_id);
    if (!empty($row['image_url'])) {
        $row['image_url'] = 'public/images/' . $row['image_url'];
    }
    echo json_encode(['ok' => true, 'data' => $row], JSON_UNESCAPED_UNICODE);
}

// Lấy info để chèn 1 dòng vào bảng đặt hàng (khi click "order")
function product_order_infoAction()
{
    header('Content-Type: application/json; charset=utf-8');
    $product_id = isset($_GET['product_id']) ? (int)$_GET['product_id'] : 0;
    if ($product_id <= 0) {
        echo json_encode(['ok' => false, 'msg' => 'Thiếu product_id']);
        return;
    }
    $row = sf_get_product_order_info($product_id);
    echo json_encode(['ok' => true, 'data' => $row], JSON_UNESCAPED_UNICODE);
}

// Lấy info để chèn 1 dòng NGUYÊN VẬT LIỆU vào bảng đặt hàng
function material_order_infoAction()
{
    header('Content-Type: application/json; charset=utf-8');
    $material_id = isset($_GET['material_id']) ? (int) $_GET['material_id'] : 0;
    if ($material_id <= 0) {
        echo json_encode(['ok' => false, 'msg' => 'Thiếu material_id']);
        return;
    }
    $row = sf_get_material_order_info($material_id);
    if (!$row) { echo json_encode(['ok' => false, 'msg' => 'Không tìm thấy nguyên vật liệu']); return; }
    echo json_encode(['ok' => true, 'data' => $row], JSON_UNESCAPED_UNICODE);
}

// Xóa 1 dòng lịch sử đặt hàng
function delete_historyAction()
{
    header('Content-Type: application/json; charset=utf-8');
    $id = isset($_POST['id']) ? (int) $_POST['id'] : 0;
    if ($id <= 0) { echo json_encode(['ok' => false, 'msg' => 'Thiếu id']); return; }
    echo json_encode(['ok' => sf_delete_history_row($id, sf_current_user_id())], JSON_UNESCAPED_UNICODE);
}

// Tìm sản phẩm ĐANG HẾT (không tồn) theo keyword — hiện ở lưới khi search.
function search_out_of_stockAction()
{
    header('Content-Type: application/json; charset=utf-8');
    $keyword = isset($_GET['keyword']) ? trim($_GET['keyword']) : '';
    if ($keyword === '') { echo json_encode(['ok' => true, 'data' => []]); return; }
    echo json_encode(['ok' => true, 'data' => sf_search_out_of_stock($keyword)], JSON_UNESCAPED_UNICODE);
}

// Tìm khách hàng (input #brand)
function search_customerAction()
{
    header('Content-Type: application/json; charset=utf-8');
    $keyword = isset($_GET['keyword']) ? trim($_GET['keyword']) : '';
    if ($keyword === '') {
        echo json_encode(['ok' => true, 'data' => []]);
        return;
    }
    echo json_encode(['ok' => true, 'data' => sf_search_customers($keyword)], JSON_UNESCAPED_UNICODE);
}

// Tìm sản phẩm (.wp-search-goods input)
function search_productAction()
{
    header('Content-Type: application/json; charset=utf-8');
    $keyword = isset($_GET['keyword']) ? trim($_GET['keyword']) : '';
    if ($keyword === '') {
        echo json_encode(['ok' => true, 'data' => []]);
        return;
    }
    echo json_encode(['ok' => true, 'data' => sf_search_products($keyword)], JSON_UNESCAPED_UNICODE);
}

// Gửi nhà máy -> lưu lịch sử
function save_orderAction()
{
    header('Content-Type: application/json; charset=utf-8');
    $raw = file_get_contents('php://input');
    $payload = json_decode($raw, true);
    if (!is_array($payload)) {
        echo json_encode(['ok' => false, 'msg' => 'Payload không hợp lệ']);
        return;
    }
    $customer_id   = isset($payload['customer_id']) && $payload['customer_id'] !== '' ? (int)$payload['customer_id'] : null;
    $customer_name = isset($payload['customer_name']) ? trim($payload['customer_name']) : '';
    $items         = isset($payload['items']) && is_array($payload['items']) ? $payload['items'] : [];
    $weight_total  = isset($payload['weight_total']) ? (float)$payload['weight_total'] : 0;
    $value_total   = isset($payload['value_total'])  ? (float)$payload['value_total']  : 0;
    $note          = isset($payload['note']) ? trim($payload['note']) : '';

    if (empty($items)) {
        echo json_encode(['ok' => false, 'msg' => 'Chưa có sản phẩm']);
        return;
    }
    $uid = sf_current_user_id();
    $id  = sf_save_factory_order($customer_id, $customer_name, $items, $weight_total, $value_total, $uid, $note);
    // Ghi nhớ chi nhánh cho lần sau.
    sf_set_last_branch($uid, $customer_id, $customer_name);

    // Đẩy chuông cho user có quyền vào branch_orders (đội nhà máy quản lý đơn mới).
    require_once __DIR__ . '/../../../libraries/notifications.php';
    $u    = function_exists('permission_current_user') ? permission_current_user() : [];
    $name = (string) ($u['fullname'] ?? $u['username'] ?? '');
    $link = '?mod=order_management&controllers=order_management&action=branch_orders';
    $msg  = ($name !== '' ? $name . ' vừa gửi' : 'Có') . ' đơn đặt hàng mới'
          . ($customer_name !== '' ? ' cho "' . $customer_name . '"' : '') . '.';
    foreach (permission_user_ids_for_view('order_management', 'order_management', 'branch_orders') as $rid) {
        if ($rid === $uid) continue;
        notify_create($rid, 'Đơn đặt hàng mới từ chi nhánh', $msg, $link, 'factory_order', $uid);
    }

    echo json_encode(['ok' => true, 'id' => $id], JSON_UNESCAPED_UNICODE);
}

// Lấy lịch sử (phân trang)
function historyAction()
{
    header('Content-Type: application/json; charset=utf-8');
    $page = isset($_GET['page']) ? (int)$_GET['page'] : 1;
    echo json_encode(['ok' => true, 'data' => sf_get_history($page, 5, sf_current_user_id())], JSON_UNESCAPED_UNICODE);
}

// Bán hàng cập nhật đơn (thêm/đổi/xóa SP) -> đẩy chuông cho phía nhà máy.
function update_orderAction()
{
    header('Content-Type: application/json; charset=utf-8');
    require_once __DIR__ . '/../../../libraries/notifications.php';
    $raw     = file_get_contents('php://input');
    $payload = json_decode($raw, true);
    if (!is_array($payload)) { echo json_encode(['ok' => false, 'msg' => 'Payload không hợp lệ']); return; }

    $id    = (int) ($payload['id'] ?? 0);
    $items = isset($payload['items']) && is_array($payload['items']) ? $payload['items'] : [];
    $wt    = (float) ($payload['weight_total'] ?? 0);
    $val   = (float) ($payload['value_total'] ?? 0);
    $note  = isset($payload['note']) ? trim($payload['note']) : '';
    $uid   = sf_current_user_id();

    if ($id <= 0 || empty($items)) { echo json_encode(['ok' => false, 'msg' => 'Thiếu dữ liệu']); return; }
    if (!sf_order_is_editable($id, $uid)) {
        echo json_encode(['ok' => false, 'msg' => 'Đơn đã bị khóa hoặc đã bốc, không thể sửa.']);
        return;
    }
    $ok = sf_update_order($id, $uid, $items, $wt, $val, $note);
    if ($ok) {
        $u    = permission_current_user();
        $name = (string) ($u['fullname'] ?? $u['username'] ?? '');
        $link = '?mod=order_management&controllers=order_management&action=branch_orders';
        // Chỉ user có quyền vào branch_orders mới nhận chuông.
        foreach (permission_user_ids_for_view('order_management', 'order_management', 'branch_orders') as $rid) {
            if ($rid === $uid) continue;
            notify_create($rid, 'Đơn hàng được chỉnh sửa',
                $name . ' vừa chỉnh sửa một đơn đặt hàng nhà máy.', $link, 'factory_order', $uid);
        }
    }
    echo json_encode(['ok' => $ok], JSON_UNESCAPED_UNICODE);
}

// Trạng thái đơn (realtime cho order_history): nhà máy khóa/bốc/xác nhận/bỏ qua.
function order_statusesAction()
{
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['ok' => true, 'data' => sf_get_order_statuses(sf_current_user_id())], JSON_UNESCAPED_UNICODE);
}

// Lấy 1 đơn cũ để "Đặt lại"
function history_detailAction()
{
    header('Content-Type: application/json; charset=utf-8');
    $id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
    if ($id <= 0) {
        echo json_encode(['ok' => false, 'msg' => 'Thiếu id']);
        return;
    }
    echo json_encode(['ok' => true, 'data' => sf_get_history_row($id, sf_current_user_id())], JSON_UNESCAPED_UNICODE);
}
