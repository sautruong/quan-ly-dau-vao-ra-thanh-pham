<?php

/**
 * vsattp — Biểu mẫu Quản lý VSATTP (7 biểu mẫu nộp cơ quan nhà nước).
 *
 * Hint cho IDE (PHP Intelephense) resolve load_* và vt_*.
 */
if (!function_exists('__vsattp_intelephense_hint_stub')) {
    function __vsattp_intelephense_hint_stub()
    {
        require_once __DIR__ . '/../../../core/base.php';
        require_once __DIR__ . '/../models/vsattpModel.php';
    }
}

function construct()
{
    load_model('vsattp');
}

/* ============================================================
 *  View 1 — Phiếu tiếp nhận nguyên liệu đầu vào
 * ============================================================ */

function material_receivingAction()
{
    load_view('material_receiving');
}

/** AJAX: search NVL theo material_name (dropdown chọn NVL hiển thị). */
function search_materialsAction()
{
    header('Content-Type: application/json; charset=utf-8');
    $kw = $_POST['keyword'] ?? '';
    echo json_encode(['data' => vt_search_materials($kw)], JSON_UNESCAPED_UNICODE);
    exit;
}

/** AJAX: dữ liệu phiếu tiếp nhận NVL theo khoảng ngày + NVL đã chọn. */
function material_receiving_dataAction()
{
    header('Content-Type: application/json; charset=utf-8');
    $from = (string) ($_POST['from'] ?? '');
    $to   = (string) ($_POST['to']   ?? '');
    $ids  = json_decode((string) ($_POST['material_ids'] ?? '[]'), true) ?: [];
    echo json_encode([
        'success' => true,
        'data'    => vt_get_receiving_rows($from, $to, $ids),
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

/** AJAX: search sản phẩm theo product_name (dùng chung cho view 2,3,4,7). */
function search_productsAction()
{
    header('Content-Type: application/json; charset=utf-8');
    $kw = $_POST['keyword'] ?? '';
    echo json_encode(['data' => vt_search_products($kw)], JSON_UNESCAPED_UNICODE);
    exit;
}

/** Đọc tham số chung from/to/product_ids cho các AJAX data. */
function vt_req_params()
{
    return [
        'from' => (string) ($_POST['from'] ?? ''),
        'to'   => (string) ($_POST['to']   ?? ''),
        'ids'  => json_decode((string) ($_POST['product_ids'] ?? '[]'), true) ?: [],
    ];
}

function vt_json_data($rows)
{
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['success' => true, 'data' => $rows], JSON_UNESCAPED_UNICODE);
    exit;
}

/* ----- VIEW 2: Sổ sản xuất theo lô/mẻ ----- */
function production_logAction()
{
    load_view('production_log', ['active' => 'production_log']);
}
function production_log_dataAction()
{
    $p = vt_req_params();
    vt_json_data(vt_production_rows($p['from'], $p['to'], $p['ids']));
}

/* ----- VIEW 3: Phiếu kiểm soát quá trình ----- */
function process_controlAction()
{
    load_view('process_control', ['active' => 'process_control']);
}
function process_control_dataAction()
{
    $p = vt_req_params();
    vt_json_data(vt_process_control_rows($p['from'], $p['to'], $p['ids']));
}

/* ----- VIEW 4: Sổ nhập – xuất kho thành phẩm ----- */
function finished_goods_ledgerAction()
{
    load_view('finished_goods_ledger', ['active' => 'finished_goods_ledger']);
}
function finished_goods_ledger_dataAction()
{
    $p = vt_req_params();
    vt_json_data(vt_finished_ledger_rows($p['from'], $p['to'], $p['ids']));
}

/* ----- VIEW 5: Sổ vệ sinh nhà xưởng – thiết bị ----- */
function sanitation_logAction()
{
    load_view('sanitation_log', ['active' => 'sanitation_log']);
}
function sanitation_log_dataAction()
{
    $p = vt_req_params();
    vt_json_data(vt_sanitation_rows($p['from'], $p['to']));
}

/* ----- VIEW 6: Sổ theo dõi sức khỏe & tập huấn (nhập tay) ----- */
function health_training_logAction()
{
    load_view('health_training_log', ['active' => 'health_training_log']);
}

/* ----- VIEW 7: Hồ sơ truy xuất nguồn gốc lô sản phẩm ----- */
function traceabilityAction()
{
    load_view('traceability', ['active' => 'traceability']);
}
function traceability_dataAction()
{
    $p = vt_req_params();
    vt_json_data(vt_traceability_rows($p['from'], $p['to'], $p['ids']));
}

/* ----- VIEW 8: Tồn kho thành phẩm (theo sản phẩm đã chọn ở production_log) ----- */
function product_stockAction()
{
    load_view('product_stock', ['active' => 'product_stock']);
}
function product_stock_dataAction()
{
    $ids = json_decode((string) ($_POST['product_ids'] ?? '[]'), true) ?: [];
    vt_json_data(vt_product_stock_rows($ids));
}

/* ----- VIEW 9: Tồn kho nguyên liệu (NVL dùng cho sản phẩm đã chọn) ----- */
function material_stockAction()
{
    load_view('material_stock', ['active' => 'material_stock']);
}
function material_stock_dataAction()
{
    $ids = json_decode((string) ($_POST['product_ids'] ?? '[]'), true) ?: [];
    vt_json_data(vt_material_stock_rows($ids));
}
