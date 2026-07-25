<?php
// Hint cho IDE (PHP Intelephense) resolve load_* và sl_*.
if (!function_exists('__sl_intelephense_hint_stub')) {
    function __sl_intelephense_hint_stub()
    {
        require_once __DIR__ . '/../../../core/base.php';
        require_once __DIR__ . '/../models/sample_labelModel.php';
    }
}

function construct()
{
    load_model('sample_label');
}

/** Trả JSON sạch (UTF-8, không escape unicode). */
function sl_json($payload)
{
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($payload, JSON_UNESCAPED_UNICODE);
    exit;
}

/* ============================================================
 *  Page
 * ============================================================ */

function sample_labelAction()
{
    sl_ensure_view_registered();
    load_view('sample_label', [
        'texts' => sl_get_fixed_texts(),
    ]);
}

/* ============================================================
 *  AJAX — Search sản phẩm
 * ============================================================ */

function search_productAction()
{
    sl_json(['data' => sl_search_products($_POST['keyword'] ?? '')]);
}

/* ============================================================
 *  AJAX — Sửa các trường cố định (Khối lượng/Ghi chú/Cảnh báo):
 *  sửa 1 lần dùng nhiều lần, giống pattern tea_label/production_label.
 * ============================================================ */

function save_fixed_textAction()
{
    $key   = (string) ($_POST['key'] ?? '');
    $value = (string) ($_POST['value'] ?? '');
    $ok    = sl_save_fixed_text($key, $value);
    if (!$ok) {
        sl_json(['success' => false, 'message' => 'Khóa không hợp lệ.']);
    }
    sl_json(['success' => true, 'value' => trim($value)]);
}

/* ============================================================
 *  AJAX — Check Database (dùng chung)
 * ============================================================ */

function check_databaseAction()
{
    require_once __DIR__ . '/../../../libraries/check_database.php';
    cdb_handle_ajax();
}
