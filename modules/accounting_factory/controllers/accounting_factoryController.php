<?php
/* =====================================================================
 *  CONTROLLER: KẾ TOÁN - NHÀ MÁY (TASK 4)
 *  Views: material_flow (Luồng 1 NVL), product_flow (Luồng 1 sản phẩm).
 * ===================================================================== */

function construct()
{
    load_model('accounting_factory');
    // Cần im_product_material_cost_per_unit_incl() cho fallback giá vốn.
    $im_model = MODULESPATH . DIRECTORY_SEPARATOR . 'inventory_management'
        . DIRECTORY_SEPARATOR . 'models' . DIRECTORY_SEPARATOR . 'inventory_managementModel.php';
    if (is_file($im_model)) {
        require_once $im_model;
    }
}

/** View: Luồng một nguyên vật liệu. */
function material_flowAction()
{
    load_view('material_flow', ['today' => date('Y-m-d')]);
}

/** View: Luồng một sản phẩm. */
function product_flowAction()
{
    load_view('product_flow', ['today' => date('Y-m-d')]);
}

/** AJAX: tìm NVL / sản phẩm theo keyword. ?kind=material|product */
function search_itemAction()
{
    header('Content-Type: application/json; charset=utf-8');
    $kw   = $_POST['keyword'] ?? '';
    $kind = ($_POST['kind'] ?? 'material') === 'product' ? 'product' : 'material';
    echo json_encode(['success' => true, 'data' => af_search_items($kw, $kind)], JSON_UNESCAPED_UNICODE);
    exit;
}

/** AJAX: dữ liệu luồng 1 NVL. */
function material_flow_dataAction()
{
    header('Content-Type: application/json; charset=utf-8');
    $mid  = (int) ($_POST['material_id'] ?? 0);
    $from = $_POST['from'] ?? '';
    $to   = $_POST['to'] ?? '';
    if ($mid <= 0) {
        echo json_encode(['success' => false, 'message' => 'Chưa chọn nguyên vật liệu.']);
        exit;
    }
    echo json_encode(['success' => true, 'data' => af_material_flow($mid, $from, $to)], JSON_UNESCAPED_UNICODE);
    exit;
}

/** AJAX: dữ liệu luồng 1 sản phẩm. */
function product_flow_dataAction()
{
    header('Content-Type: application/json; charset=utf-8');
    $pid  = (int) ($_POST['product_id'] ?? 0);
    $from = $_POST['from'] ?? '';
    $to   = $_POST['to'] ?? '';
    if ($pid <= 0) {
        echo json_encode(['success' => false, 'message' => 'Chưa chọn sản phẩm.']);
        exit;
    }
    echo json_encode(['success' => true, 'data' => af_product_flow($pid, $from, $to)], JSON_UNESCAPED_UNICODE);
    exit;
}
