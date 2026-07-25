<?php
defined('APPPATH') OR exit('Không được quyền truy cập phần này');

/* =====================================================================
 *  CÔNG NỢ NHÀ CUNG CẤP — controller (view: Lịch sử thanh toán)
 * =====================================================================*/

function construct()
{
    load_model('supplier_debt');
    sd_ensure_schema();
}

/** AJAX: Check Database — preview các bảng bị tác động (dùng chung). */
function check_databaseAction()
{
    require_once __DIR__ . '/../../../libraries/check_database.php';
    cdb_handle_ajax();
}

/** Helper: trả JSON sạch rồi dừng. */
function sd_json($payload)
{
    while (ob_get_level() > 0) { ob_end_clean(); }
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($payload, JSON_UNESCAPED_UNICODE);
    exit;
}

/* ============================================================
 *  Trang chính
 * ============================================================ */

function payment_historyAction()
{
    $hist = sd_get_history(1, 5, 0);
    load_view('payment_history', [
        'history'        => $hist['rows'],
        'history_total'  => $hist['total'],
        'print_settings' => sd_get_print_settings(),
        'sign_config'    => sd_get_sign_config(),
    ]);
}

/* ============================================================
 *  AJAX — Nhà cung cấp
 * ============================================================ */

function search_suppliersAction()
{
    sd_json(['data' => sd_search_suppliers($_POST['keyword'] ?? '')]);
}

function get_supplierAction()
{
    $sid = (int) ($_POST['supplier_id'] ?? 0);
    $sup = sd_get_supplier($sid);
    if (!$sup) sd_json(['success' => false, 'message' => 'Không tìm thấy NCC.']);
    sd_json(['success' => true, 'data' => $sup]);
}

/** Lưu địa chỉ NCC (sửa tại chỗ trên phiếu chi). */
function save_supplier_addressAction()
{
    $sid = (int) ($_POST['supplier_id'] ?? 0);
    $ok  = sd_save_supplier_address($sid, $_POST['address'] ?? '');
    sd_json(['success' => $ok]);
}

/** Lưu 1 trường cố định của phiếu in (company_name, signer_director...). */
function save_print_settingAction()
{
    $key = trim((string) ($_POST['key'] ?? ''));
    $val = (string) ($_POST['value'] ?? '');
    $ok  = sd_save_print_setting($key, $val);
    sd_json(['success' => $ok, 'message' => $ok ? '' : 'Trường không hợp lệ.', 'key' => $key, 'value' => $val]);
}

/** Lưu cấu hình vùng ký (sign_roles / signs — JSON) trên phiếu chi. */
function save_sign_settingAction()
{
    $key = trim((string) ($_POST['key'] ?? ''));
    $val = (string) ($_POST['value'] ?? '');
    $ok  = sd_save_sign_setting($key, $val);
    sd_json(['success' => $ok]);
}

/* ============================================================
 *  AJAX — Lịch sử thanh toán (CRUD)
 * ============================================================ */

function get_historyAction()
{
    $page = (int) ($_POST['page'] ?? 1);
    $per  = (int) ($_POST['per_page'] ?? 5);
    $sid  = (int) ($_POST['supplier_id'] ?? 0);
    $df   = (string) ($_POST['date_from'] ?? '');
    $dt   = (string) ($_POST['date_to'] ?? '');
    sd_json(['success' => true] + sd_get_history($page, $per, $sid, $df, $dt));
}

function get_paymentAction()
{
    $id  = (int) ($_POST['id'] ?? 0);
    $row = sd_get_payment($id);
    if (!$row) sd_json(['success' => false, 'message' => 'Không tìm thấy phiếu.']);
    sd_json(['success' => true, 'data' => $row]);
}

function recordAction()
{
    $data = [
        'supplier_id' => $_POST['supplier_id'] ?? 0,
        'amount'      => $_POST['amount']      ?? 0,
        'note'        => $_POST['note']        ?? '',
        'created_at'  => $_POST['created_at']  ?? '',
    ];
    $id = sd_record($data);
    if ($id <= 0) sd_json(['success' => false, 'message' => 'Thiếu nhà cung cấp hoặc số tiền không hợp lệ.']);
    sd_json(['success' => true, 'id' => $id]);
}

function editAction()
{
    $id   = (int) ($_POST['id'] ?? 0);
    $data = [
        'supplier_id' => $_POST['supplier_id'] ?? 0,
        'amount'      => $_POST['amount']      ?? 0,
        'note'        => $_POST['note']        ?? '',
        'created_at'  => $_POST['created_at']  ?? '',
    ];
    $ok = sd_update($id, $data);
    if (!$ok) sd_json(['success' => false, 'message' => 'Không cập nhật được — kiểm tra dữ liệu nhập.']);
    sd_json(['success' => true, 'id' => $id]);
}

function deleteAction()
{
    $id = (int) ($_POST['id'] ?? 0);
    if ($id <= 0) sd_json(['success' => false, 'message' => 'Thiếu id.']);
    sd_delete($id);
    sd_json(['success' => true]);
}

/* ============================================================
 *  AJAX — Đính kèm ảnh/file của 1 phiếu chi
 * ============================================================ */

function upload_attachmentsAction()
{
    $pid = (int) ($_POST['payment_id'] ?? 0);
    if ($pid <= 0 || empty($_FILES['files'])) {
        sd_json(['ok' => false, 'message' => 'Thiếu payment_id hoặc tệp.']);
    }
    sd_json(sd_save_attachments($pid, $_FILES['files']));
}

function list_attachmentsAction()
{
    $pid = (int) ($_POST['payment_id'] ?? 0);
    sd_json(['ok' => true, 'data' => $pid > 0 ? sd_list_attachments($pid) : []]);
}

/* ============================================================
 *  AJAX — Các lần mua của 1 NCC (modal khi click tên)
 * ============================================================ */

function get_supplier_purchasesAction()
{
    $sid  = (int) ($_POST['supplier_id'] ?? 0);
    $page = (int) ($_POST['page'] ?? 1);
    $per  = (int) ($_POST['per_page'] ?? 5);
    sd_json(['success' => true] + sd_get_supplier_purchases($sid, $page, $per));
}
