<?php
// Hint cho IDE (PHP Intelephense) resolve load_* và cpm_*.
if (!function_exists('__cpm_intelephense_hint_stub')) {
    function __cpm_intelephense_hint_stub()
    {
        require_once __DIR__ . '/../../../core/base.php';
        require_once __DIR__ . '/../models/customer_packagingModel.php';
    }
}

function construct()
{
    load_model('customer_packaging');
}

/** Trả JSON sạch (UTF-8, không escape unicode). */
function cpm_json($payload)
{
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($payload, JSON_UNESCAPED_UNICODE);
    exit;
}

/* ============================================================
 *  Page
 * ============================================================ */
function customer_packagingAction()
{
    cpm_ensure_tables();
    cpm_ensure_view_registered();
    load_view('customer_packaging', [
        'setups' => cpm_setup_list(),
    ]);
}

/* ============================================================
 *  AJAX — Tìm kiếm
 * ============================================================ */
function search_productsAction()
{
    cpm_json(['data' => cpm_search_products($_POST['keyword'] ?? '')]);
}

function customer_suggestAction()
{
    cpm_json(['data' => cpm_customer_suggest($_POST['keyword'] ?? '')]);
}

function packaging_suggestAction()
{
    $cn = (string) ($_POST['customer_name'] ?? '');
    cpm_json(['data' => cpm_packaging_suggest($cn, $_POST['keyword'] ?? '')]);
}

/* ============================================================
 *  AJAX — Thiết lập (khách hàng + bao bì + sản phẩm)
 * ============================================================ */
function setup_listAction()
{
    cpm_json(['success' => true, 'data' => cpm_setup_list()]);
}

function setup_addAction()
{
    $cn   = (string) ($_POST['customer_name'] ?? '');
    $pn   = (string) ($_POST['packaging_name'] ?? '');
    $pid  = (int) ($_POST['product_id'] ?? 0);
    $pname = (string) ($_POST['product_name'] ?? '');
    $res = cpm_setup_add($cn, $pn, $pid, $pname);
    if (!empty($res['success'])) $res['setups'] = cpm_setup_list();
    cpm_json($res);
}

function setup_deleteAction()
{
    $id = (int) ($_POST['id'] ?? 0);
    if ($id <= 0) cpm_json(['success' => false, 'message' => 'Thiếu id.']);
    cpm_setup_delete($id);
    cpm_json(['success' => true, 'setups' => cpm_setup_list()]);
}

/* ============================================================
 *  AJAX — Sổ bao bì theo khách hàng
 * ============================================================ */
function ledger_bookAction()
{
    $cn = (string) ($_POST['customer_name'] ?? '');
    cpm_json(['success' => true, 'data' => cpm_customer_book($cn)]);
}

function entry_addAction()
{
    $cn    = (string) ($_POST['customer_name'] ?? '');
    $pn    = (string) ($_POST['packaging_name'] ?? '');
    $type  = (string) ($_POST['entry_type'] ?? '');
    $qty   = (float) ($_POST['qty'] ?? 0);
    $date  = (string) ($_POST['entry_date'] ?? '');
    $pid   = (int) ($_POST['product_id'] ?? 0);
    $pname = (string) ($_POST['product_name'] ?? '');
    $reason = (string) ($_POST['reason'] ?? '');
    $res = cpm_entry_add($cn, $pn, $type, $qty, $date !== '' ? $date : null, $pid, $pname, $reason);
    if (!empty($res['success'])) $res['data'] = cpm_customer_book($cn);
    cpm_json($res);
}

/* ============================================================
 *  AJAX — Chốt tồn (tạo mốc 'confirm' mới cho 1 loại bao bì)
 * ============================================================ */
function confirmAction()
{
    $cn = (string) ($_POST['customer_name'] ?? '');
    $pn = (string) ($_POST['packaging_name'] ?? '');
    $res = cpm_confirm($cn, $pn);
    if (!empty($res['success'])) $res['data'] = cpm_customer_book($cn);
    cpm_json($res);
}

/* ============================================================
 *  AJAX — Check Database (dùng chung)
 * ============================================================ */
function check_databaseAction()
{
    require_once __DIR__ . '/../../../libraries/check_database.php';
    cdb_handle_ajax();
}
