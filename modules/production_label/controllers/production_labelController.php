<?php
// Hint cho IDE (PHP Intelephense) resolve load_* và pl_*.
if (!function_exists('__pl_intelephense_hint_stub')) {
    function __pl_intelephense_hint_stub()
    {
        require_once __DIR__ . '/../../../core/base.php';
        require_once __DIR__ . '/../models/production_labelModel.php';
    }
}

function construct()
{
    load_model('production_label');
}

/** Trả JSON sạch (UTF-8, không escape unicode). */
function pl_json($payload)
{
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($payload, JSON_UNESCAPED_UNICODE);
    exit;
}

/* ============================================================
 *  Page
 * ============================================================ */

function production_labelAction()
{
    pl_ensure_view_registered();
    load_view('production_label', [
        'today'         => date('Y-m-d'),
        'hotline'       => pl_get_hotline(),
        'khsx_products' => pl_get_khsx_products(),
    ]);
}

/* ============================================================
 *  AJAX — Search sản phẩm
 * ============================================================ */

function search_productsAction()
{
    $kw = $_POST['keyword'] ?? '';
    pl_json(['data' => pl_search_products($kw)]);
}

/* ============================================================
 *  AJAX — Tạo tem lưu mẫu
 * ============================================================ */

function get_sample_label_dataAction()
{
    $pid  = (int) ($_POST['product_id'] ?? 0);
    $date = (string) ($_POST['date'] ?? '');
    if ($pid <= 0) {
        pl_json(['success' => false, 'message' => 'Thiếu sản phẩm.']);
    }
    $data = pl_get_sample_label_data($pid, $date);
    if (empty($data['ingredients'])) {
        pl_json(['success' => false, 'message' => 'Sản phẩm chưa có công thức nguyên liệu (product_materials) để lập tem lưu mẫu.']);
    }
    pl_json(['success' => true, 'data' => $data]);
}

/* ============================================================
 *  AJAX — Tạo tem bao bì ngoài
 * ============================================================ */

function get_outer_label_dataAction()
{
    $pid        = (int) ($_POST['product_id'] ?? 0);
    $date       = (string) ($_POST['date'] ?? '');
    $batchCount = (int) ($_POST['batch_count'] ?? 1);
    $qty        = (float) ($_POST['qty_needed'] ?? 0);
    if ($pid <= 0) {
        pl_json(['success' => false, 'message' => 'Thiếu sản phẩm.']);
    }
    if ($qty <= 0) {
        pl_json(['success' => false, 'message' => 'Số lượng cần sản xuất phải lớn hơn 0.']);
    }
    $data = pl_get_outer_label_data($pid, $date, $batchCount, $qty);
    if (empty($data['groups'])) {
        pl_json(['success' => false, 'message' => 'Sản phẩm chưa thiết lập quy cách bao bì ngoài (product_info_basic) — vào Hồ sơ sản phẩm để thiết lập.']);
    }
    pl_json(['success' => true, 'data' => $data]);
}

/* ============================================================
 *  AJAX — Sửa hotline (sửa 1 lần dùng nhiều lần, áp cho mọi tem bao bì ngoài)
 * ============================================================ */

function save_hotlineAction()
{
    $value = (string) ($_POST['value'] ?? '');
    pl_json(['success' => pl_save_hotline($value), 'value' => trim($value)]);
}

/* ============================================================
 *  AJAX — Sửa tên thường gọi sản phẩm (đổi tại card -> đồng bộ mọi card cùng SP)
 * ============================================================ */

function save_product_common_nameAction()
{
    $pid   = (int) ($_POST['product_id'] ?? 0);
    $value = (string) ($_POST['value'] ?? '');
    if ($pid <= 0) {
        pl_json(['success' => false, 'message' => 'Thiếu sản phẩm.']);
    }
    pl_json(['success' => pl_save_product_common_name($pid, $value), 'value' => trim($value)]);
}

/* ============================================================
 *  AJAX — Check Database (dùng chung)
 * ============================================================ */

function check_databaseAction()
{
    require_once __DIR__ . '/../../../libraries/check_database.php';
    cdb_handle_ajax();
}
