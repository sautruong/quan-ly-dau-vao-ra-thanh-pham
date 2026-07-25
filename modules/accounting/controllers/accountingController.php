<?php
defined('APPPATH') OR exit('Không được quyền truy cập phần này');

function construct() {
    load_model('accounting');
}

/**
 * Helper output JSON + dừng request, đảm bảo không có byte rác phía trước.
 */
function acc_json($payload) {
    while (ob_get_level() > 0) { ob_end_clean(); }
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($payload, JSON_UNESCAPED_UNICODE);
    exit;
}

/**
 * GET ?mod=accounting&controllers=accounting&action=list_names&transaction_group=<group>
 * Trả về toàn bộ mapping + entries để JS render datalist + auto-fill khi chọn.
 */
function list_namesAction() {
    $group = isset($_GET['transaction_group']) ? $_GET['transaction_group'] : '';
    $data  = acc_list_by_group($group);
    acc_json(array('success' => true, 'data' => $data));
}

/**
 * POST ?mod=accounting&controllers=accounting&action=save
 * Body:
 *   transaction_group, transaction_name, description,
 *   debit_code,  debit_name,
 *   credit_code, credit_name,
 *   amount_formula
 */
function saveAction() {
    $group       = isset($_POST['transaction_group'])  ? $_POST['transaction_group']  : '';
    $name        = isset($_POST['transaction_name'])   ? $_POST['transaction_name']   : '';
    $description = isset($_POST['description'])        ? $_POST['description']        : '';
    $debit_code  = isset($_POST['debit_code'])         ? $_POST['debit_code']         : '';
    $debit_name  = isset($_POST['debit_name'])         ? $_POST['debit_name']         : '';
    $credit_code = isset($_POST['credit_code'])        ? $_POST['credit_code']        : '';
    $credit_name = isset($_POST['credit_name'])        ? $_POST['credit_name']        : '';
    $amount      = isset($_POST['amount_formula'])     ? $_POST['amount_formula']     : '';

    if (trim($group) === '' || trim($name) === '') {
        acc_json(array('success' => false, 'message' => 'Thiếu transaction_group hoặc transaction_name'));
    }

    $entries = array(
        'debit' => array(
            'account_code'   => $debit_code,
            'account_name'   => $debit_name,
            'amount_formula' => $amount,
        ),
        'credit' => array(
            'account_code'   => $credit_code,
            'account_name'   => $credit_name,
            'amount_formula' => $amount,
        ),
    );

    $mapping_id = acc_save_mapping_with_entries($group, $name, $description, $entries);

    if ($mapping_id <= 0) {
        acc_json(array('success' => false, 'message' => 'Không lưu được mapping'));
    }

    acc_json(array('success' => true, 'mapping_id' => $mapping_id));
}
