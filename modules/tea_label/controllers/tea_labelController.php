<?php
// Hint cho IDE (PHP Intelephense) resolve load_* và tl_*.
if (!function_exists('__tl_intelephense_hint_stub')) {
    function __tl_intelephense_hint_stub()
    {
        require_once __DIR__ . '/../../../core/base.php';
        require_once __DIR__ . '/../models/tea_labelModel.php';
    }
}

function construct()
{
    load_model('tea_label');
}

/** Trả JSON sạch (UTF-8, không escape unicode). */
function tl_json($payload)
{
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($payload, JSON_UNESCAPED_UNICODE);
    exit;
}

/* ============================================================
 *  Page
 * ============================================================ */

function tea_labelAction()
{
    tl_ensure_view_registered();
    load_view('tea_label', [
        'today' => date('Y-m-d'),
        'texts' => tl_get_fixed_texts(),
    ]);
}

/* ============================================================
 *  AJAX — Gợi ý tên nguyên liệu (free text, chỉ gợi ý không ràng buộc)
 * ============================================================ */

function search_materialsAction()
{
    tl_json(['data' => tl_search_materials($_POST['keyword'] ?? '')]);
}

/* ============================================================
 *  AJAX — Tìm nhà cung cấp (tham chiếu bảng suppliers)
 * ============================================================ */

function search_suppliersAction()
{
    tl_json(['data' => tl_search_suppliers($_POST['keyword'] ?? '')]);
}

/* ============================================================
 *  AJAX — Sửa các dòng cố định (Công dụng/Hạn sử dụng/Bảo quản):
 *  sửa 1 lần dùng nhiều lần, giống hotline ở production_label.
 * ============================================================ */

function save_fixed_textAction()
{
    $key   = (string) ($_POST['key'] ?? '');
    $value = (string) ($_POST['value'] ?? '');
    $ok    = tl_save_fixed_text($key, $value);
    if (!$ok) {
        tl_json(['success' => false, 'message' => 'Khóa không hợp lệ.']);
    }
    tl_json(['success' => true, 'value' => trim($value)]);
}

/* ============================================================
 *  AJAX — Check Database (dùng chung)
 * ============================================================ */

function check_databaseAction()
{
    require_once __DIR__ . '/../../../libraries/check_database.php';
    cdb_handle_ajax();
}
