<?php
// Hint cho IDE (PHP Intelephense) resolve load_* và sl_*.
if (!function_exists('__sl_intelephense_hint_stub')) {
    function __sl_intelephense_hint_stub()
    {
        require_once __DIR__ . '/../../../core/base.php';
        require_once __DIR__ . '/../models/siro_labelModel.php';
    }
}

function construct()
{
    load_model('siro_label');
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

function siro_labelAction()
{
    sl_ensure_view_registered();
    load_view('siro_label', [
        'today' => date('Y-m-d'),
        'texts' => sl_get_fixed_texts(),
    ]);
}

/* ============================================================
 *  AJAX — Sửa các dòng cố định (Công ty/Địa chỉ/Bảo quản/Hotline/Khối lượng/Xuất xứ):
 *  sửa 1 lần dùng nhiều lần, giống pattern ở tea_label/production_label.
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
