<?php
// Hint cho IDE (PHP Intelephense) resolve load_* và om_*.
if (!function_exists('__om_intelephense_hint_stub')) {
    function __om_intelephense_hint_stub()
    {
        require_once __DIR__ . '/../../../core/base.php';
        require_once __DIR__ . '/../models/order_materialModel.php';
    }
}

function construct()
{
    load_model('order_material');
}

/** Trả JSON sạch (UTF-8, không escape unicode). */
function om_json($payload)
{
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($payload, JSON_UNESCAPED_UNICODE);
    exit;
}

function om_current_user_id()
{
    if (!function_exists('permission_current_user')) return 0;
    $u = permission_current_user();
    return (int) ($u['id'] ?? 0);
}

/* ============================================================
 *  Page
 * ============================================================ */
function order_materialAction()
{
    om_ensure_tables();
    om_ensure_view_registered();
    load_view('order_material', [
        'doc'    => om_doc_settings(),
        'orders' => om_list_orders(50),
    ]);
}

/* ============================================================
 *  AJAX — KHỐI 1: Phân tích NVL
 * ============================================================ */

function search_suppliersAction()
{
    om_json(['data' => om_search_suppliers($_POST['keyword'] ?? '')]);
}

function supplier_infoAction()
{
    $sid = (int) ($_POST['supplier_id'] ?? 0);
    om_json(['success' => true, 'data' => om_supplier_info($sid)]);
}

function update_supplierAction()
{
    $sid = (int) ($_POST['supplier_id'] ?? 0);
    if ($sid <= 0) om_json(['success' => false, 'message' => 'Thiếu supplier_id.']);
    $data = [];
    foreach (['supplier_name', 'phone_number', 'email', 'website', 'address'] as $f) {
        if (isset($_POST[$f])) $data[$f] = (string) $_POST[$f];
    }
    om_json(['success' => om_update_supplier($sid, $data)]);
}

function supplier_materialsAction()
{
    $sid = (int) ($_POST['supplier_id'] ?? 0);
    om_json(['success' => true, 'data' => om_supplier_materials($sid)]);
}

/** AJAX: "Tạm ẩn" 1 NVL khỏi danh sách của NCC đang chọn. */
function hide_materialAction()
{
    $mid = (int) ($_POST['material_id'] ?? 0);
    $sid = (int) ($_POST['supplier_id'] ?? 0);
    om_json(['success' => om_hide_material($mid, $sid)]);
}

/** AJAX: mở lại 1 NVL đang "tạm ẩn" (khối "Thành phần tạm ẩn"). */
function unhide_materialAction()
{
    $mid = (int) ($_POST['material_id'] ?? 0);
    $sid = (int) ($_POST['supplier_id'] ?? 0);
    om_json(['success' => om_unhide_material($mid, $sid)]);
}

/** AJAX: danh sách NVL đang "tạm ẩn" của 1 NCC. */
function hidden_materialsAction()
{
    $sid = (int) ($_POST['supplier_id'] ?? 0);
    om_json(['success' => true, 'data' => om_hidden_materials($sid)]);
}

function material_analysisAction()
{
    $mid  = (int) ($_POST['material_id'] ?? 0);
    $data = om_material_analysis($mid);
    if (!$data) om_json(['success' => false, 'message' => 'Không tìm thấy nguyên vật liệu.']);
    om_json(['success' => true, 'data' => $data]);
}

function save_min_settingAction()
{
    $mid  = (int) ($_POST['material_id'] ?? 0);
    $min  = (float) ($_POST['min_quantity'] ?? 0);
    $lead = (int) ($_POST['lead_days'] ?? 0);
    $win  = (string) ($_POST['usage_window'] ?? 'none');
    if ($mid <= 0) om_json(['success' => false, 'message' => 'Thiếu material_id.']);
    om_json(['success' => om_save_min_setting($mid, $min, $lead, $win)]);
}

/** Lưu hàng loạt thiết lập tồn tối thiểu (modal list NVL). */
function save_min_settings_bulkAction()
{
    $raw   = $_POST['items'] ?? '[]';
    $items = json_decode($raw, true);
    if (!is_array($items)) $items = [];
    $n = 0;
    foreach ($items as $it) {
        $mid = (int) ($it['material_id'] ?? 0);
        if ($mid <= 0) continue;
        om_save_min_setting(
            $mid,
            (float) ($it['min_quantity'] ?? 0),
            (int) ($it['lead_days'] ?? 0),
            (string) ($it['usage_window'] ?? 'none')
        );
        $n++;
    }
    om_json(['success' => true, 'saved' => $n]);
}

/* ============================================================
 *  AJAX — KHỐI 2: Đơn đặt hàng
 * ============================================================ */

function save_orderAction()
{
    $payload = json_decode(file_get_contents('php://input'), true);
    if (!is_array($payload)) $payload = [];
    $sid   = (int) ($payload['supplier_id'] ?? 0);
    $sname = (string) ($payload['supplier_name'] ?? '');
    $note  = (string) ($payload['note'] ?? '');
    $items = $payload['items'] ?? [];

    // Đơn trùng (cùng ngày + cùng NCC + cùng tập mặt hàng) -> không cho lưu tiếp.
    if (om_find_duplicate_order($sid, $items)) {
        om_json(['success' => false, 'duplicate' => true, 'message' => 'Đơn trùng trong ngày.']);
    }

    $id = om_save_order($sid, $sname, $items, $note, om_current_user_id());
    if ($id <= 0) om_json(['success' => false, 'message' => 'Chưa có mặt hàng để lưu đơn.']);
    om_json(['success' => true, 'id' => $id, 'orders' => om_list_orders(50)]);
}

/** AJAX: đơn hiện tại (NCC + tập mặt hàng) đã tồn tại trong "Đơn đã lưu" (hôm nay) chưa —
 *  dùng trước khi "Chụp & chia sẻ" để hỏi có lưu đơn không. Dùng lại đúng logic khớp trùng
 *  của save_orderAction() (om_find_duplicate_order) để nhất quán. */
function check_order_savedAction()
{
    $payload = json_decode(file_get_contents('php://input'), true);
    if (!is_array($payload)) $payload = [];
    $sid   = (int) ($payload['supplier_id'] ?? 0);
    $items = $payload['items'] ?? [];
    om_json(['success' => true, 'exists' => om_find_duplicate_order($sid, $items)]);
}

/** AJAX: "Sửa đơn" — cập nhật ĐÚNG đơn đã lưu (không tạo đơn mới), cho phép sửa/bổ sung thành phần. */
function update_orderAction()
{
    $payload = json_decode(file_get_contents('php://input'), true);
    if (!is_array($payload)) $payload = [];
    $id    = (int) ($payload['id'] ?? 0);
    $sid   = (int) ($payload['supplier_id'] ?? 0);
    $sname = (string) ($payload['supplier_name'] ?? '');
    $note  = (string) ($payload['note'] ?? '');
    $items = $payload['items'] ?? [];

    if ($id <= 0) om_json(['success' => false, 'message' => 'Thiếu id đơn.']);
    if (!om_update_order($id, $sid, $sname, $items, $note)) {
        om_json(['success' => false, 'message' => 'Chưa có mặt hàng để cập nhật.']);
    }
    om_json(['success' => true, 'orders' => om_list_orders(50)]);
}

function list_ordersAction()
{
    om_json(['success' => true, 'data' => om_list_orders(50)]);
}

function order_detailAction()
{
    $id  = (int) ($_POST['id'] ?? ($_GET['id'] ?? 0));
    $row = om_get_order($id);
    if (!$row) om_json(['success' => false, 'message' => 'Không tìm thấy đơn.']);
    om_json(['success' => true, 'data' => $row]);
}

function set_receivedAction()
{
    $id = (int) ($_POST['id'] ?? 0);
    $rc = !empty($_POST['received']);
    if ($id <= 0) om_json(['success' => false, 'message' => 'Thiếu id.']);
    om_set_received($id, $rc);
    om_json(['success' => true, 'orders' => om_list_orders(50)]);
}

function delete_orderAction()
{
    $id = (int) ($_POST['id'] ?? 0);
    if ($id <= 0) om_json(['success' => false, 'message' => 'Thiếu id.']);
    om_delete_order($id);
    om_json(['success' => true, 'orders' => om_list_orders(50)]);
}

/* ============================================================
 *  AJAX — Lưu tiêu đề phiếu + tên người ký (sửa 1 lần dùng nhiều lần)
 * ============================================================ */
function save_settingAction()
{
    $key   = (string) ($_POST['key'] ?? '');
    $value = (string) ($_POST['value'] ?? '');
    $allow = [
        'order_material.company_name',
        'order_material.company_enname',
        'order_material.company_address',
        'order_material.company_phone',
        'order_material.company_email',
        'order_material.signer_orderer',
        'order_material.signer_warehouse',
        'order_material.signer_accountant',
        'order_material.sign_roles',
        'order_material.signs',
    ];
    if (!in_array($key, $allow, true)) {
        om_json(['success' => false, 'message' => 'Khóa cấu hình không hợp lệ.']);
    }
    om_json(['success' => om_set_setting($key, $value)]);
}

/* ============================================================
 *  AJAX — Check Database (dùng chung)
 * ============================================================ */
function check_databaseAction()
{
    require_once __DIR__ . '/../../../libraries/check_database.php';
    cdb_handle_ajax();
}
