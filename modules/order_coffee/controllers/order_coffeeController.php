<?php
// Hint cho IDE (PHP Intelephense) resolve load_* và oc_*.
if (!function_exists('__oc_intelephense_hint_stub')) {
    function __oc_intelephense_hint_stub()
    {
        require_once __DIR__ . '/../../../core/base.php';
        require_once __DIR__ . '/../models/order_coffeeModel.php';
    }
}

function construct()
{
    load_model('order_coffee');
}

/** Trả JSON sạch (UTF-8, không escape unicode). */
function oc_json($payload)
{
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($payload, JSON_UNESCAPED_UNICODE);
    exit;
}

function oc_current_user_id()
{
    if (!function_exists('permission_current_user')) return 0;
    $u = permission_current_user();
    return (int) ($u['id'] ?? 0);
}

/** Đọc payload JSON body. */
function oc_json_body()
{
    $p = json_decode(file_get_contents('php://input'), true);
    return is_array($p) ? $p : [];
}

/* ============================================================
 *  Page
 * ============================================================ */
function order_coffeeAction()
{
    oc_ensure_tables();
    oc_ensure_view_registered();
    load_view('order_coffee', [
        'doc'     => oc_doc_settings(),
        'recipes' => oc_list_recipes(),
        'orders'  => oc_list_orders(50),
    ]);
}

/* ============================================================
 *  AJAX — Tìm kiếm
 * ============================================================ */
function search_suppliersAction()
{
    oc_json(['data' => oc_search_suppliers($_POST['keyword'] ?? '')]);
}

function search_productsAction()
{
    oc_json(['data' => oc_search_products($_POST['keyword'] ?? '')]);
}

function search_materialsAction()
{
    oc_json(['data' => oc_search_materials($_POST['keyword'] ?? '')]);
}

function search_packagingAction()
{
    oc_json(['data' => oc_search_packaging($_POST['keyword'] ?? '')]);
}

function supplier_infoAction()
{
    $sid = (int) ($_POST['supplier_id'] ?? 0);
    oc_json(['success' => true, 'data' => oc_supplier_info($sid)]);
}

function update_supplierAction()
{
    $sid = (int) ($_POST['supplier_id'] ?? 0);
    if ($sid <= 0) oc_json(['success' => false, 'message' => 'Thiếu supplier_id.']);
    $data = [];
    foreach (['supplier_name', 'phone_number', 'email', 'website', 'address'] as $f) {
        if (isset($_POST[$f])) $data[$f] = (string) $_POST[$f];
    }
    oc_json(['success' => oc_update_supplier($sid, $data)]);
}

/* ============================================================
 *  AJAX — Công thức (thiết lập sản phẩm)
 * ============================================================ */
function save_recipeAction()
{
    $res = oc_save_recipe(oc_json_body());
    if (!empty($res['success'])) $res['recipes'] = oc_list_recipes();
    oc_json($res);
}

function list_recipesAction()
{
    oc_json(['success' => true, 'data' => oc_list_recipes()]);
}

function delete_recipeAction()
{
    $id = (int) ($_POST['id'] ?? 0);
    if ($id <= 0) oc_json(['success' => false, 'message' => 'Thiếu id.']);
    oc_delete_recipe($id);
    oc_json(['success' => true, 'recipes' => oc_list_recipes()]);
}

/** Lưu ghi chú SP để tái sử dụng (nhập 1 lần, dùng nhiều lần). */
function update_recipe_noteAction()
{
    $pid  = (int) ($_POST['product_id'] ?? 0);
    $note = (string) ($_POST['note'] ?? '');
    if ($pid <= 0) oc_json(['success' => false, 'message' => 'Thiếu product_id.']);
    oc_json(['success' => oc_update_recipe_note($pid, $note)]);
}

/* ============================================================
 *  AJAX — Đơn đặt hàng
 * ============================================================ */
function save_orderAction()
{
    $payload = oc_json_body();
    $id = oc_save_order($payload, oc_current_user_id());
    if ($id <= 0) oc_json(['success' => false, 'message' => 'Chưa có mặt hàng để lưu đơn.']);
    oc_json(['success' => true, 'id' => $id, 'orders' => oc_list_orders(50)]);
}

function list_ordersAction()
{
    oc_json(['success' => true, 'data' => oc_list_orders(50)]);
}

function order_detailAction()
{
    $id  = (int) ($_POST['id'] ?? ($_GET['id'] ?? 0));
    $row = oc_get_order($id);
    if (!$row) oc_json(['success' => false, 'message' => 'Không tìm thấy đơn.']);
    oc_json(['success' => true, 'data' => $row]);
}

function set_receivedAction()
{
    $id = (int) ($_POST['id'] ?? 0);
    $rc = !empty($_POST['received']);
    if ($id <= 0) oc_json(['success' => false, 'message' => 'Thiếu id.']);
    oc_set_received($id, $rc);
    oc_json(['success' => true, 'orders' => oc_list_orders(50)]);
}

function delete_orderAction()
{
    $id = (int) ($_POST['id'] ?? 0);
    if ($id <= 0) oc_json(['success' => false, 'message' => 'Thiếu id.']);
    oc_delete_order($id);
    oc_json(['success' => true, 'orders' => oc_list_orders(50)]);
}

/* ============================================================
 *  AJAX — Sổ bao bì tại NCC
 * ============================================================ */
function packaging_bookAction()
{
    $sid = (int) ($_POST['supplier_id'] ?? 0);
    oc_json(['success' => true, 'data' => oc_packaging_book($sid)]);
}

function packaging_addAction()
{
    $sid   = (int) ($_POST['supplier_id'] ?? 0);
    $pid   = (int) ($_POST['packaging_id'] ?? 0);
    $pname = (string) ($_POST['packaging_name'] ?? '');
    $type  = (string) ($_POST['entry_type'] ?? '');
    $qty   = (float) ($_POST['qty'] ?? 0);
    $date  = (string) ($_POST['entry_date'] ?? '');
    $res = oc_packaging_add($sid, $pid, $pname, $type, $qty, $date !== '' ? $date : null);
    if (!empty($res['success'])) $res['data'] = oc_packaging_book($sid);
    oc_json($res);
}

/* Ảnh dẫn chứng dòng sổ bao bì (dán Ctrl+V vào ô Ngày). POST: entry_id, supplier_id; FILES: files[]. */
function ledger_evidence_uploadAction()
{
    $eid = (int) ($_POST['entry_id'] ?? 0);
    $sid = (int) ($_POST['supplier_id'] ?? 0);
    $res = oc_ledger_evidence_add($eid, $_FILES['files'] ?? []);
    if (!empty($res['success'])) $res['data'] = oc_packaging_book($sid);
    oc_json($res);
}

/* Xóa 1 ảnh dẫn chứng. POST: evidence_id, supplier_id. */
function ledger_evidence_deleteAction()
{
    $id  = (int) ($_POST['evidence_id'] ?? 0);
    $sid = (int) ($_POST['supplier_id'] ?? 0);
    if ($id <= 0) oc_json(['success' => false, 'message' => 'Thiếu evidence_id.']);
    oc_ledger_evidence_delete($id);
    oc_json(['success' => true, 'data' => oc_packaging_book($sid)]);
}

/* ============================================================
 *  AJAX — Báo cáo tồn bao bì theo mốc (Xuất báo cáo / Chốt tồn)
 * ============================================================ */
function packaging_milestonesAction()
{
    $sid = (int) ($_POST['supplier_id'] ?? 0);
    $pid = (int) ($_POST['packaging_id'] ?? 0);
    oc_json(['success' => true, 'data' => oc_packaging_milestones($sid, $pid)]);
}

function packaging_reportAction()
{
    $sid = (int) ($_POST['supplier_id'] ?? 0);
    $pid = (int) ($_POST['packaging_id'] ?? 0);
    $mid = (int) ($_POST['milestone_id'] ?? 0);
    oc_json(['success' => true, 'data' => oc_packaging_report($sid, $pid, $mid)]);
}

function packaging_confirmAction()
{
    $sid   = (int) ($_POST['supplier_id'] ?? 0);
    $pid   = (int) ($_POST['packaging_id'] ?? 0);
    $pname = (string) ($_POST['packaging_name'] ?? '');
    $res = oc_packaging_confirm($sid, $pid, $pname);
    if (!empty($res['success'])) {
        $res['report']     = oc_packaging_report($sid, $pid, 0);
        $res['milestones'] = oc_packaging_milestones($sid, $pid);
    }
    oc_json($res);
}

/* ============================================================
 *  AJAX — Lưu tiêu đề phiếu + người ký
 * ============================================================ */
function save_settingAction()
{
    $key   = (string) ($_POST['key'] ?? '');
    $value = (string) ($_POST['value'] ?? '');
    $allow = [
        'order_coffee.company_name',
        'order_coffee.company_enname',
        'order_coffee.company_address',
        'order_coffee.company_phone',
        'order_coffee.company_email',
        'order_coffee.sign_roles',
        'order_coffee.signs',
    ];
    if (!in_array($key, $allow, true)) {
        oc_json(['success' => false, 'message' => 'Khóa cấu hình không hợp lệ.']);
    }
    oc_json(['success' => oc_set_setting($key, $value)]);
}

/* ============================================================
 *  AJAX — Check Database (dùng chung)
 * ============================================================ */
function check_databaseAction()
{
    require_once __DIR__ . '/../../../libraries/check_database.php';
    cdb_handle_ajax();
}
