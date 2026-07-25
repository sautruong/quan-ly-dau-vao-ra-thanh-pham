<?php
// Hint cho IDE (PHP Intelephense) resolve load_* và tsgm_*/tsg_*.
if (!function_exists('__tsgm_intelephense_hint_stub')) {
    function __tsgm_intelephense_hint_stub()
    {
        require_once __DIR__ . '/../../../core/base.php';
        require_once __DIR__ . '/../models/tea_scent_groupModel.php';
    }
}

function construct()
{
    load_model('tea_scent_group');
}

/** Trả JSON sạch (UTF-8, không escape unicode). */
function tsgm_json($payload)
{
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($payload, JSON_UNESCAPED_UNICODE);
    exit;
}

/* ============================================================
 *  Page
 * ============================================================ */
function tea_scent_groupAction()
{
    tsg_ensure_tables();
    tsgm_ensure_view_registered();
    load_view('tea_scent_group', [
        'groups' => tsg_group_list(),
    ]);
}

/* ============================================================
 *  AJAX — Tìm kiếm
 * ============================================================ */
function search_materialsAction()
{
    tsgm_json(['data' => tsgm_search_materials($_POST['keyword'] ?? '')]);
}

function search_productsAction()
{
    tsgm_json(['data' => tsgm_search_products($_POST['keyword'] ?? '')]);
}

function material_productsAction()
{
    $mid = (int) ($_POST['material_id'] ?? 0);
    tsgm_json(['success' => true, 'data' => tsgm_products_for_material($mid)]);
}

/* ============================================================
 *  AJAX — Nhóm
 * ============================================================ */
function group_listAction()
{
    tsgm_json(['success' => true, 'data' => tsg_group_list()]);
}

function group_addAction()
{
    permission_require_can_edit('tea_scent_group', 'tea_scent_group', 'tea_scent_group');
    $mid   = (int) ($_POST['material_id'] ?? 0);
    $mname = (string) ($_POST['material_name'] ?? '');
    $unit  = (string) ($_POST['unit'] ?? '');
    $th    = (float) ($_POST['threshold'] ?? 4);
    $note  = (string) ($_POST['note'] ?? '');
    if ($mid <= 0) {
        tsgm_json(['success' => false, 'message' => 'Chọn nguyên liệu kiểm soát.']);
    }
    $gid = tsg_group_create($mid, $mname, $unit, $th, $note);
    if ($gid <= 0) {
        tsgm_json(['success' => false, 'message' => 'Không thể tạo nhóm (có thể NVL này đã có nhóm).']);
    }
    tsgm_json(['success' => true, 'group_id' => $gid, 'data' => tsg_group_list()]);
}

function group_detailAction()
{
    $gid   = (int) ($_POST['group_id'] ?? 0);
    $group = tsg_group_get($gid);
    if (!$group) {
        tsgm_json(['success' => false, 'message' => 'Không tìm thấy nhóm.']);
    }
    tsgm_json([
        'success' => true,
        'data'    => [
            'group' => [
                'group_id'          => (int) $group['id'],
                'material_id'       => (int) $group['material_id'],
                'material_name'     => $group['material_name'],
                'unit'              => $group['unit'],
                'warning_threshold' => (float) $group['warning_threshold'],
                'note'              => $group['note'],
            ],
            'balance'  => tsg_balance($gid),
            'setup'    => tsg_setup_list($gid),
            'opening'  => tsg_opening_get($gid),
            'receipts' => tsg_receipts_history($gid),
            'ledger'   => tsg_raw_entries($gid),
        ],
    ]);
}

function group_update_thresholdAction()
{
    permission_require_can_edit('tea_scent_group', 'tea_scent_group', 'tea_scent_group');
    $gid = (int) ($_POST['group_id'] ?? 0);
    $th  = (float) ($_POST['threshold'] ?? 0);
    if ($gid <= 0 || $th <= 0) {
        tsgm_json(['success' => false, 'message' => 'Ngưỡng không hợp lệ.']);
    }
    tsg_group_update_threshold($gid, $th);
    tsgm_json(['success' => true]);
}

function group_deleteAction()
{
    permission_require_can_edit('tea_scent_group', 'tea_scent_group', 'tea_scent_group');
    $gid = (int) ($_POST['group_id'] ?? 0);
    if ($gid <= 0) {
        tsgm_json(['success' => false, 'message' => 'Thiếu group_id.']);
    }
    tsg_group_delete($gid);
    tsgm_json(['success' => true, 'data' => tsg_group_list()]);
}

/* ============================================================
 *  AJAX — Thiết lập sản phẩm dùng NVL kiểm soát
 * ============================================================ */
function setup_addAction()
{
    permission_require_can_edit('tea_scent_group', 'tea_scent_group', 'tea_scent_group');
    $gid   = (int) ($_POST['group_id'] ?? 0);
    $pid   = (int) ($_POST['product_id'] ?? 0);
    $pname = (string) ($_POST['product_name'] ?? '');
    $pct   = (float) ($_POST['usage_ratio_percent'] ?? 0);
    $note  = (string) ($_POST['note'] ?? '');
    if ($gid <= 0 || $pid <= 0 || $pct <= 0) {
        tsgm_json(['success' => false, 'message' => 'Thiếu sản phẩm hoặc tỉ lệ dùng.']);
    }
    $id = tsg_setup_add($gid, $pid, $pname, $pct / 100, $note);
    if ($id <= 0) {
        tsgm_json(['success' => false, 'message' => 'Không thể lưu thiết lập.']);
    }
    tsgm_json(['success' => true, 'id' => $id]);
}

function setup_update_ratioAction()
{
    permission_require_can_edit('tea_scent_group', 'tea_scent_group', 'tea_scent_group');
    $id  = (int) ($_POST['id'] ?? 0);
    $pct = (float) ($_POST['usage_ratio_percent'] ?? 0);
    if ($id <= 0 || $pct <= 0) {
        tsgm_json(['success' => false, 'message' => 'Tỉ lệ không hợp lệ.']);
    }
    tsg_setup_update_ratio($id, $pct / 100);
    tsgm_json(['success' => true]);
}

function setup_deleteAction()
{
    permission_require_can_edit('tea_scent_group', 'tea_scent_group', 'tea_scent_group');
    $id = (int) ($_POST['id'] ?? 0);
    if ($id <= 0) {
        tsgm_json(['success' => false, 'message' => 'Thiếu id.']);
    }
    tsg_setup_delete($id);
    tsgm_json(['success' => true]);
}

/* ============================================================
 *  AJAX — Mốc tồn đầu + Nhập thêm
 * ============================================================ */
function opening_setAction()
{
    permission_require_can_edit('tea_scent_group', 'tea_scent_group', 'tea_scent_group');
    $gid  = (int) ($_POST['group_id'] ?? 0);
    $qty  = isset($_POST['qty']) ? (float) $_POST['qty'] : -1.0;
    $date = trim((string) ($_POST['date'] ?? ''));
    if ($gid <= 0 || $qty < 0) {
        tsgm_json(['success' => false, 'message' => 'Số lượng không hợp lệ.']);
    }
    tsg_set_opening($gid, $qty, $date !== '' ? $date : null, tsgm_current_user_id());
    tsgm_json(['success' => true]);
}

function receipt_addAction()
{
    permission_require_can_edit('tea_scent_group', 'tea_scent_group', 'tea_scent_group');
    $gid  = (int) ($_POST['group_id'] ?? 0);
    $qty  = (float) ($_POST['qty'] ?? 0);
    $note = (string) ($_POST['note'] ?? '');
    $date = trim((string) ($_POST['date'] ?? ''));
    if ($gid <= 0 || $qty <= 0) {
        tsgm_json(['success' => false, 'message' => 'Số lượng phải lớn hơn 0.']);
    }
    tsg_add_receipt($gid, $qty, $note, $date !== '' ? $date : null, tsgm_current_user_id());
    tsgm_json(['success' => true]);
}

/* ============================================================
 *  AJAX — Check Database (dùng chung)
 * ============================================================ */
function check_databaseAction()
{
    require_once __DIR__ . '/../../../libraries/check_database.php';
    cdb_handle_ajax();
}
