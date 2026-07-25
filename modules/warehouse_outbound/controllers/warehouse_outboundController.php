<?php
function construct()
{
    load_model('warehouse_outbound');
    // sales_delivery_note + other_warehouse_outbound tái dùng các hàm im_* từ
    // inventory_management (history search, sanitize datetime, je_resolve...).
    require_once __DIR__ . '/../../inventory_management/models/inventory_managementModel.php';
    require_once __DIR__ . '/../../../libraries/print_settings.php';
}

/** AJAX: Check Database — preview nội dung các bảng bị tác động (dùng chung). */
function check_databaseAction()
{
    require_once __DIR__ . '/../../../libraries/check_database.php';
    cdb_handle_ajax();
}

function sales_delivery_noteAction()
{
    $plan_date   = date('d/m/Y');
    $type_export = 'sales_issue';
    // 100 batch nạp sẵn; JS phân trang 10 dòng/page.
    $history     = im_get_recent_sales_issue_batches(100);

    load_view('sales_delivery_note', [
        'plan_date'      => $plan_date,
        'history'        => $history,
        'type_export'    => $type_export,
        'print_settings' => print_settings_get(),
    ]);
}

/* ============================================================
 *   OTHER WAREHOUSE OUTBOUND — Xuất kho khác
 * ============================================================ */

function other_warehouse_outboundAction()
{
    $plan_date   = date('d/m/Y');
    $type_export = 'other_export_outbound';
    // 100 batch nạp sẵn — JS phân trang 5 dòng/page như yêu cầu.
    $history     = wo_get_recent_other_outbound_batches(100);

    load_view('other_warehouse_outbound', [
        'plan_date'   => $plan_date,
        'history'     => $history,
        'type_export' => $type_export,
    ]);
}

/** Tìm products + material_information theo keyword (giống sales_delivery_note). */
function search_product_or_materialAction()
{
    header('Content-Type: application/json');
    $keyword = $_POST['keyword'] ?? '';
    echo json_encode(['data' => im_search_products_or_materials($keyword)], JSON_UNESCAPED_UNICODE);
    exit;
}

/** Lấy defaults (name, warehouse, unit_cost) khi user pick 1 item từ dropdown. */
function get_item_defaultsAction()
{
    header('Content-Type: application/json');
    $id   = (int) ($_POST['id'] ?? 0);
    $type = trim((string) ($_POST['type'] ?? 'product'));
    $data = wo_get_item_defaults($id, $type);
    if (!$data) {
        echo json_encode(['success' => false, 'message' => 'Không tìm thấy hàng hóa']);
        exit;
    }
    echo json_encode(['success' => true, 'data' => $data], JSON_UNESCAPED_UNICODE);
    exit;
}

/**
 * Ghi 1 batch xuất kho khác.
 * Input: items JSON, created_at ('Y-m-d H:i:s').
 */
function record_other_outboundAction()
{
    header('Content-Type: application/json');
    permission_require_can_edit('warehouse_outbound', 'warehouse_outbound', 'other_warehouse_outbound');

    $items_raw  = $_POST['items'] ?? '[]';
    $items      = json_decode($items_raw, true) ?: [];
    $created_at = trim((string) ($_POST['created_at'] ?? ''));
    $ca         = $created_at !== '' ? $created_at : null;

    if (empty($items)) {
        echo json_encode(['success' => false, 'message' => 'Chưa có hàng hóa nào để ghi.']);
        exit;
    }

    $r = wo_record_other_outbound_batch($items, $ca);
    echo json_encode([
        'success' => !empty($r['ok']),
        'count'   => (int) ($r['count'] ?? 0),
        'history' => wo_get_recent_other_outbound_batches(100),
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

/**
 * Sửa 1 batch xuất kho khác — wipe & rewrite theo group_key + created_at mới.
 * Input: group_key, items JSON, created_at.
 */
function edit_other_outbound_batchAction()
{
    header('Content-Type: application/json');
    permission_require_can_edit('warehouse_outbound', 'warehouse_outbound', 'other_warehouse_outbound');

    $group_key  = trim((string) ($_POST['group_key'] ?? ''));
    $items_raw  = $_POST['items'] ?? '[]';
    $items      = json_decode($items_raw, true) ?: [];
    $created_at = trim((string) ($_POST['created_at'] ?? ''));
    $ca         = $created_at !== '' ? $created_at : null;

    if ($group_key === '') {
        echo json_encode(['success' => false, 'message' => 'Thiếu group_key.']);
        exit;
    }
    if (empty($items)) {
        echo json_encode(['success' => false, 'message' => 'Không có dữ liệu để cập nhật.']);
        exit;
    }

    $r = wo_edit_other_outbound_batch($group_key, $items, $ca);
    echo json_encode([
        'success' => !empty($r['ok']),
        'count'   => (int) ($r['count'] ?? 0),
        'history' => wo_get_recent_other_outbound_batches(100),
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

/** Xoá 1 batch xuất kho khác theo group_key. */
function delete_other_outbound_batchAction()
{
    header('Content-Type: application/json');
    permission_require_can_edit('warehouse_outbound', 'warehouse_outbound', 'other_warehouse_outbound');
    $group_key = trim((string) ($_POST['group_key'] ?? ''));
    if ($group_key === '') {
        echo json_encode(['success' => false, 'message' => 'Thiếu group_key.']);
        exit;
    }
    $removed = wo_delete_other_outbound_batch($group_key);
    echo json_encode([
        'success' => true,
        'removed' => $removed,
        'history' => wo_get_recent_other_outbound_batches(100),
    ], JSON_UNESCAPED_UNICODE);
    exit;
}
