<?php
// Hint cho IDE (PHP Intelephense) resolve load_* và ir_*.
if (!function_exists('__ir_intelephense_hint_stub')) {
    function __ir_intelephense_hint_stub()
    {
        require_once __DIR__ . '/../../../core/base.php';
        require_once __DIR__ . '/../models/inventory_receivingModel.php';
    }
}

require_once __DIR__ . '/../../../libraries/warehouse_receipt_invoices.php';

function construct()
{
    load_model('inventory_receiving');
}

/**
 * AJAX: upload file hóa đơn mua hàng cho 1 phiếu vừa ghi/sửa.
 * Input POST: invoice_id (= stock_import_invoices.id) ; FILES: files[].
 * Resolve warehouse_receipts.id theo import_invoice_id rồi lưu type='purchase_invoice'.
 */
function upload_purchase_invoiceAction()
{
    header('Content-Type: application/json; charset=utf-8');
    permission_require_can_edit('inventory_receiving', 'inventory_receiving', 'row_material_receiving', 'ok');
    $invoice_id = (int) ($_POST['invoice_id'] ?? 0);
    if ($invoice_id <= 0 || empty($_FILES['files'])) {
        echo json_encode(['ok' => false, 'message' => 'Thiếu invoice_id hoặc tệp.']);
        exit;
    }
    $wr_id = wri_wr_id_by_invoice($invoice_id);
    if ($wr_id <= 0) {
        echo json_encode(['ok' => false, 'message' => 'Không tìm thấy phiếu nhập kho tương ứng.']);
        exit;
    }
    echo json_encode(wri_save_uploaded_files($wr_id, 'purchase_invoice', $_FILES['files']), JSON_UNESCAPED_UNICODE);
    exit;
}

/**
 * AJAX: liệt kê hóa đơn mua hàng ĐÃ LƯU của 1 phiếu (để load lại khi Sửa).
 * Input POST: invoice_id (= stock_import_invoices.id).
 * Trả: { ok:true, data:[{id, file_url, created_at}, ...] }.
 */
function list_purchase_invoicesAction()
{
    header('Content-Type: application/json; charset=utf-8');
    $invoice_id = (int) ($_POST['invoice_id'] ?? 0);
    if ($invoice_id <= 0) {
        echo json_encode(['ok' => true, 'data' => []]);
        exit;
    }
    $wr_id = wri_wr_id_by_invoice($invoice_id);
    $list  = $wr_id > 0 ? wri_list($wr_id, 'purchase_invoice') : [];
    echo json_encode(['ok' => true, 'data' => $list], JSON_UNESCAPED_UNICODE);
    exit;
}

/** AJAX: Check Database — preview nội dung các bảng bị tác động (dùng chung). */
function check_databaseAction()
{
    require_once __DIR__ . '/../../../libraries/check_database.php';
    cdb_handle_ajax();
}

/**
 * Wrap 1 closure trả JSON; output buffer + bắt mọi warning/notice/throwable
 * để response luôn là JSON (tránh client SyntaxError "Unexpected token '<'").
 */
function ir_json_action(callable $fn)
{
    ob_start();
    try {
        $payload = $fn();
        $leak = ob_get_clean();
        if ($leak !== false && $leak !== '') {
            // log warning leak; vẫn trả JSON sạch
            error_log('[inventory_receiving] output leak: ' . substr($leak, 0, 500));
        }
        header('Content-Type: application/json');
        echo json_encode($payload, JSON_UNESCAPED_UNICODE);
    } catch (\Throwable $e) {
        if (ob_get_level() > 0) ob_end_clean();
        header('Content-Type: application/json');
        echo json_encode([
            'success' => false,
            'message' => 'Lỗi máy chủ: ' . $e->getMessage(),
        ], JSON_UNESCAPED_UNICODE);
    }
    exit;
}

/* ============================================================
 *  Page: row_material_receiving (Nhập NVL mua)
 * ============================================================ */

function row_material_receivingAction()
{
    // Nạp TOÀN BỘ batch để bộ lọc lịch sử (từ khóa + khoảng ngày + số dòng) chạy
    // hoàn toàn client-side trên INITIAL.history (không cần endpoint phân trang server).
    $history_total = ir_count_batches();
    $history       = ir_get_history_page(1, max(1, $history_total));
    load_view('row_material_receiving', [
        'history'        => $history,
        'history_total'  => $history_total,
        'print_settings' => ir_get_print_settings(),
    ]);
}

/**
 * AJAX: lưu 1 trường thông tin cố định của phiếu in (company_name, address, signer...).
 * Input POST: key, value. Chỉ chấp nhận key trong whitelist (model).
 */
function save_print_settingAction()
{
    header('Content-Type: application/json');
    permission_require_can_edit('inventory_receiving', 'inventory_receiving', 'row_material_receiving');
    $key = trim((string) ($_POST['key'] ?? ''));
    $val = (string) ($_POST['value'] ?? '');
    $ok  = ir_save_print_setting($key, $val);
    echo json_encode([
        'success' => $ok,
        'message' => $ok ? '' : 'Trường không hợp lệ.',
        'key'     => $key,
        'value'   => $val,
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

/**
 * AJAX: dữ liệu phân tích cho "Phiếu nhập kho kèm phân tích".
 * Input POST: material_ids = JSON [id, id, ...].
 * Trả: { success:true, data: { material_id: {...}, ... } }.
 */
/**
 * AJAX: dữ liệu phân tích cho "Phiếu nhập kho kèm phân tích" — gộp cả NVL lẫn
 * thành phẩm. Trả về data map với key phân biệt loại: 'm{id}' (NVL) / 'p{id}' (thành phẩm).
 */
function analysis_row_materialAction()
{
    header('Content-Type: application/json');
    $mat_ids  = json_decode($_POST['material_ids'] ?? '[]', true);
    $prod_ids = json_decode($_POST['product_ids']  ?? '[]', true);
    if (!is_array($mat_ids))  $mat_ids  = [];
    if (!is_array($prod_ids)) $prod_ids = [];

    $data = [];
    foreach (ir_get_material_analysis($mat_ids) as $mid => $a) { $data['m' . $mid] = $a; }
    foreach (ir_get_product_analysis($prod_ids) as $pid => $a) { $data['p' . $pid] = $a; }

    echo json_encode(['success' => true, 'data' => $data], JSON_UNESCAPED_UNICODE);
    exit;
}

function other_row_material_receivingAction()
{
    // Nạp TOÀN BỘ batch để bộ lọc lịch sử (từ khóa + khoảng ngày + số dòng) chạy
    // hoàn toàn client-side trên INITIAL.history (không cần endpoint phân trang server).
    $history_total = ir2_count_batches();
    $history       = ir2_get_history_page(1, max(1, $history_total));
    load_view('other_row_material_receiving', [
        'history'       => $history,
        'history_total' => $history_total,
    ]);
}

/* ============================================================
 *  AJAX — Search
 * ============================================================ */

function search_suppliersAction()
{
    header('Content-Type: application/json');
    $kw = $_POST['keyword'] ?? '';
    echo json_encode(['data' => ir_search_suppliers($kw)], JSON_UNESCAPED_UNICODE);
    exit;
}

function search_materialsAction()
{
    header('Content-Type: application/json');
    $kw = $_POST['keyword'] ?? '';
    echo json_encode(['data' => ir_search_materials($kw)], JSON_UNESCAPED_UNICODE);
    exit;
}

function get_material_infoAction()
{
    header('Content-Type: application/json');
    $mid = (int) ($_POST['material_id'] ?? 0);
    $info = ir_get_material_info($mid);
    if (!$info) {
        echo json_encode(['success' => false, 'message' => 'Không tìm thấy NVL.']);
        exit;
    }
    echo json_encode(['success' => true, 'data' => $info], JSON_UNESCAPED_UNICODE);
    exit;
}

/**
 * AJAX: tìm "tên hàng hóa" gộp cả NVL lẫn thành phẩm (page row_material_receiving
 * đã nâng cấp — gộp từ flow product_buy).
 */
function search_itemsAction()
{
    header('Content-Type: application/json');
    $kw = $_POST['keyword'] ?? '';
    echo json_encode(['data' => ir_search_items($kw)], JSON_UNESCAPED_UNICODE);
    exit;
}

/** AJAX: lấy thông tin 1 item (NVL hoặc thành phẩm) theo type + id, cho autofill dòng. */
function get_item_infoAction()
{
    header('Content-Type: application/json');
    $type = trim((string) ($_POST['item_type'] ?? 'material'));
    $id   = (int) ($_POST['item_id'] ?? 0);
    $info = ir_get_item_info($type, $id);
    if (!$info) {
        echo json_encode(['success' => false, 'message' => 'Không tìm thấy hàng hóa.']);
        exit;
    }
    echo json_encode(['success' => true, 'data' => $info], JSON_UNESCAPED_UNICODE);
    exit;
}

/* ============================================================
 *  AJAX — Update unit (khi user edit .cell-unit live)
 * ============================================================ */

function update_material_unitAction()
{
    header('Content-Type: application/json');
    permission_require_can_edit('inventory_receiving', 'inventory_receiving', 'row_material_receiving');
    $mid  = (int) ($_POST['material_id'] ?? 0);
    $unit = isset($_POST['unit']) ? (string) $_POST['unit'] : '';
    if ($mid <= 0) {
        echo json_encode(['success' => false, 'message' => 'Thiếu material_id.']);
        exit;
    }
    ir_update_material_unit($mid, $unit);
    echo json_encode(['success' => true, 'data' => ['material_id' => $mid, 'unit' => trim($unit)]], JSON_UNESCAPED_UNICODE);
    exit;
}

/* ============================================================
 *  AJAX — Update price (khi user edit .cell-price live)
 * ============================================================ */

function update_material_priceAction()
{
    header('Content-Type: application/json');
    permission_require_can_edit('inventory_receiving', 'inventory_receiving', 'row_material_receiving');
    $mid   = (int) ($_POST['material_id'] ?? 0);
    $price = isset($_POST['price']) ? (float) $_POST['price'] : -1.0;
    if ($mid <= 0 || $price < 0) {
        echo json_encode(['success' => false, 'message' => 'Tham số không hợp lệ.']);
        exit;
    }
    $rid = ir_save_material_purchase_price($mid, $price);
    echo json_encode(['success' => $rid > 0, 'id' => (int) $rid]);
    exit;
}

/* ============================================================
 *  AJAX — Record / Edit / Delete
 * ============================================================ */

function record_row_materialAction()
{
    header('Content-Type: application/json');
    permission_require_can_edit('inventory_receiving', 'inventory_receiving', 'row_material_receiving');
    $items_raw   = $_POST['items'] ?? '[]';
    $items       = json_decode($items_raw, true) ?: [];
    $supplier_id = (int) ($_POST['supplier_id'] ?? 0);
    $created_at  = trim((string) ($_POST['created_at'] ?? ''));
    $ca          = $created_at !== '' ? $created_at : null;

    if (empty($items)) {
        echo json_encode(['success' => false, 'message' => 'Chưa có nguyên vật liệu nào để ghi.']);
        exit;
    }
    if ($supplier_id <= 0) {
        echo json_encode(['success' => false, 'message' => 'Chưa chọn nhà cung cấp.']);
        exit;
    }

    $je = [
        'debit'  => $_POST['je_debit']  ?? null,
        'credit' => $_POST['je_credit'] ?? null,
        'amount' => $_POST['je_amount'] ?? null,
    ];
    ppc_reset();
    // Đọc TRƯỚC khi ghi phiếu (ir_record_batch sẽ ghi đè material_purchase_prices),
    // để "giá trị dự kiến" còn lấy được giá lúc đặt hàng. Chỉ đọc, chưa ghi gì.
    // 1 phiếu có thể trả cho NHIỀU đơn của cùng NCC (tách nhiều lần đặt, giao gộp 1 lần).
    $order_matches = om_match_orders_for_receipt($supplier_id, ir_extract_material_lines($items));
    // Tương tự cho đơn cà phê (oc_orders): khớp theo bộ DÒNG THÀNH PHẨM của phiếu.
    $coffee_match = oc_match_order_for_receipt($supplier_id, ir_extract_product_lines($items));
    $invoice_id  = ir_record_batch($items, $supplier_id, $ca, $je);

    if ($invoice_id > 0 && $order_matches) {
        // Phiếu ghi thành công + khớp bộ NVL với các đơn đã lưu -> tự xác nhận "Đã nhận" cho
        // TẤT CẢ đơn trong tổ hợp, kèm snapshot dự kiến/thực nhận để daily_dashboard so sánh.
        foreach ($order_matches as $m) {
            om_set_received($m['order_id'], true, $m['expected_value'], $m['actual_value']);
        }
    } else {
        $order_matches = [];
    }
    $order_match = $order_matches[0] ?? null;   // giữ khóa cũ cho client bản trước
    if ($invoice_id > 0 && $coffee_match) {
        oc_set_received($coffee_match['order_id'], true, $coffee_match['expected_value'], $coffee_match['actual_value']);
    } else {
        $coffee_match = null;
    }

    echo json_encode([
        'success'      => $invoice_id > 0,
        'invoice_id'   => $invoice_id,
        'price_changes'=> ppc_take(),
        'order_match'  => $order_match,
        'order_matches'=> $order_matches,
        'coffee_match' => $coffee_match,
        'history'      => ir_get_history_page(1, max(1, ir_count_batches())),
        'history_total'=> ir_count_batches(),
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

function get_batchAction()
{
    header('Content-Type: application/json');
    $gk = trim((string) ($_POST['group_key'] ?? ''));
    $b = ir_get_batch($gk);
    if (!$b) {
        echo json_encode(['success' => false, 'message' => 'Không tìm thấy nhóm.']);
        exit;
    }
    echo json_encode(['success' => true, 'data' => $b], JSON_UNESCAPED_UNICODE);
    exit;
}

function edit_row_materialAction()
{
    header('Content-Type: application/json');
    permission_require_can_edit('inventory_receiving', 'inventory_receiving', 'row_material_receiving');
    $gk          = trim((string) ($_POST['group_key'] ?? ''));
    $items_raw   = $_POST['items'] ?? '[]';
    $items       = json_decode($items_raw, true) ?: [];
    $supplier_id = (int) ($_POST['supplier_id'] ?? 0);
    $created_at  = trim((string) ($_POST['created_at'] ?? ''));
    $ca          = $created_at !== '' ? $created_at : null;

    if ($gk === '') {
        echo json_encode(['success' => false, 'message' => 'Thiếu group_key.']);
        exit;
    }
    if (empty($items)) {
        echo json_encode(['success' => false, 'message' => 'Chưa có nguyên vật liệu nào để cập nhật.']);
        exit;
    }
    if ($supplier_id <= 0) {
        echo json_encode(['success' => false, 'message' => 'Chưa chọn nhà cung cấp.']);
        exit;
    }

    $je = [
        'debit'  => $_POST['je_debit']  ?? null,
        'credit' => $_POST['je_credit'] ?? null,
        'amount' => $_POST['je_amount'] ?? null,
    ];
    ppc_reset();
    $invoice_id = ir_edit_batch($gk, $items, $supplier_id, $ca, $je);

    echo json_encode([
        'success'      => $invoice_id > 0,
        'invoice_id'   => $invoice_id,
        'price_changes'=> ppc_take(),
        'history'      => ir_get_history_page(1, max(1, ir_count_batches())),
        'history_total'=> ir_count_batches(),
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

function delete_row_materialAction()
{
    header('Content-Type: application/json');
    permission_require_can_edit('inventory_receiving', 'inventory_receiving', 'row_material_receiving');
    $gk = trim((string) ($_POST['group_key'] ?? ''));
    if ($gk === '') {
        echo json_encode(['success' => false, 'message' => 'Thiếu group_key.']);
        exit;
    }
    $removed = ir_delete_batch($gk);
    echo json_encode([
        'success'      => true,
        'removed'      => $removed,
        'history'      => ir_get_history_page(1, max(1, ir_count_batches())),
        'history_total'=> ir_count_batches(),
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

/* ============================================================
 *  AJAX — History pagination
 * ============================================================ */

function get_historyAction()
{
    header('Content-Type: application/json');
    $page = max(1, (int) ($_POST['page'] ?? 1));
    echo json_encode([
        'success'      => true,
        'data'         => ir_get_history_page($page, 5),
        'history_total'=> ir_count_batches(),
        'page'         => $page,
        'per_page'     => 5,
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

/**
 * AJAX: nút "Giá vốn ảnh hưởng" trong modal biến động giá nhập NVL — danh sách sản
 * phẩm dùng NVL vừa đổi giá kèm giá vốn cũ/mới. POST: material_id, old_price, new_price.
 */
function ajax_material_cost_impactAction()
{
    header('Content-Type: application/json');
    $mid       = (int) ($_POST['material_id'] ?? 0);
    $old_price = (float) ($_POST['old_price'] ?? 0);
    $new_price = (float) ($_POST['new_price'] ?? 0);
    if ($mid <= 0) {
        echo json_encode(['success' => false, 'message' => 'Thiếu nguyên liệu.']);
        exit;
    }
    echo json_encode([
        'success' => true,
        'items'   => ir_material_cost_impact($mid, $old_price, $new_price),
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

/**
 * AJAX: click tên sản phẩm trong modal "Giá vốn ảnh hưởng" — giải thích chi tiết
 * thành phần/định mức/đơn giá/thành tiền của giá vốn cũ và mới.
 * POST: product_id, material_id, old_price, new_price.
 */
function ajax_product_cost_breakdownAction()
{
    header('Content-Type: application/json');
    $pid       = (int) ($_POST['product_id'] ?? 0);
    $mid       = (int) ($_POST['material_id'] ?? 0);
    $old_price = (float) ($_POST['old_price'] ?? 0);
    $new_price = (float) ($_POST['new_price'] ?? 0);
    $data = ir_product_cost_breakdown($pid, $mid, $old_price, $new_price);
    if ($data === null) {
        echo json_encode(['success' => false, 'message' => 'Không tìm thấy sản phẩm.']);
        exit;
    }
    echo json_encode(array_merge(['success' => true], $data), JSON_UNESCAPED_UNICODE);
    exit;
}

/* ============================================================
 *  Page: price_change_check (Check biến động giá — tab "Mua hàng")
 *  So giá NCC vừa báo với giá cũ TRƯỚC KHI quyết định mua — chỉ preview,
 *  không ghi gì vào DB.
 * ============================================================ */

function price_change_checkAction()
{
    load_view('price_change_check', []);
}

/** AJAX: Kiểm tra biến động giá — trường hợp chọn THÀNH PHẨM. POST: product_id, new_price. */
function pcc_check_productAction()
{
    header('Content-Type: application/json');
    $pid       = (int) ($_POST['product_id'] ?? 0);
    $new_price = (float) ($_POST['new_price'] ?? 0);
    if ($pid <= 0) {
        echo json_encode(['success' => false, 'message' => 'Thiếu sản phẩm.']);
        exit;
    }
    $prod = db_fetch_row(
        "SELECT COALESCE(NULLIF(common_product_name, ''), product_name) AS name
         FROM products WHERE id = $pid LIMIT 1"
    );
    if (!$prod) {
        echo json_encode(['success' => false, 'message' => 'Không tìm thấy sản phẩm.']);
        exit;
    }

    $last      = pcc_product_last_price($pid);
    $old_price = $last ? $last['price'] : null;
    $old_date  = $last ? $last['date'] : null;
    $rate      = ($old_price !== null && $old_price > 0) ? (($new_price - $old_price) / $old_price) * 100 : null;

    echo json_encode([
        'success'      => true,
        'product_id'   => $pid,
        'product_name' => $prod['name'],
        'old_price'    => $old_price,
        'old_date'     => $old_date,
        'new_price'    => $new_price,
        'change_rate'  => $rate !== null ? round($rate, 2) : null,
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

/** AJAX: Kiểm tra biến động giá — trường hợp chọn NGUYÊN VẬT LIỆU. POST: material_id, new_price. */
function pcc_check_materialAction()
{
    header('Content-Type: application/json');
    $mid       = (int) ($_POST['material_id'] ?? 0);
    $new_price = (float) ($_POST['new_price'] ?? 0);
    if ($mid <= 0) {
        echo json_encode(['success' => false, 'message' => 'Thiếu nguyên liệu.']);
        exit;
    }
    $mat = db_fetch_row(
        "SELECT COALESCE(NULLIF(common_material_name, ''), material_name) AS name
         FROM material_information WHERE id = $mid LIMIT 1"
    );
    if (!$mat) {
        echo json_encode(['success' => false, 'message' => 'Không tìm thấy nguyên liệu.']);
        exit;
    }

    $old_price = ir_get_price_incl($mid);
    $items     = ir_material_cost_impact($mid, $old_price ?? 0, $new_price);

    echo json_encode([
        'success'       => true,
        'material_id'   => $mid,
        'material_name' => $mat['name'],
        'old_price'     => $old_price,
        'new_price'     => $new_price,
        'items'         => $items,
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

/** AJAX: lịch sử biến động giá (purchase_price_changes) của 1 SP/NVL. POST: type(product|material), id. */
function pcc_price_historyAction()
{
    header('Content-Type: application/json');
    $type = (($_POST['type'] ?? '') === 'product') ? 'product' : 'material';
    $id   = (int) ($_POST['id'] ?? 0);
    echo json_encode(['success' => true, 'items' => pcc_price_history($type, $id)], JSON_UNESCAPED_UNICODE);
    exit;
}

/* ============================================================
 *  AJAX — Record / Edit / Delete / History
 *  Page: other_row_material_receiving (Nhập NVL khác)
 * ============================================================ */

function record_other_row_materialAction()
{
    header('Content-Type: application/json');
    permission_require_can_edit('inventory_receiving', 'inventory_receiving', 'other_row_material_receiving');
    $items_raw      = $_POST['items'] ?? '[]';
    $items          = json_decode($items_raw, true) ?: [];
    $general_interp = (string) ($_POST['general_interpretation'] ?? '');
    $created_at     = trim((string) ($_POST['created_at'] ?? ''));
    $ca             = $created_at !== '' ? $created_at : null;

    if (empty($items)) {
        echo json_encode(['success' => false, 'message' => 'Chưa có nguyên vật liệu nào để ghi.']);
        exit;
    }

    $je = [
        'debit'  => $_POST['je_debit']  ?? null,
        'credit' => $_POST['je_credit'] ?? null,
        'amount' => $_POST['je_amount'] ?? null,
    ];
    // Diễn giải lịch sử = các tên nghiệp vụ (#je-transaction-name) ghép lại, cắt 160.
    $summary   = ir2_summary_from_je($_POST['je_entries'] ?? '');
    $group_key = ir2_record_batch($items, $general_interp, $ca, $je, $summary);

    echo json_encode([
        'success'      => $group_key !== '',
        'group_key'    => $group_key,
        'history'      => ir2_get_history_page(1, max(1, ir2_count_batches())),
        'history_total'=> ir2_count_batches(),
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

function get_other_batchAction()
{
    header('Content-Type: application/json');
    $gk = trim((string) ($_POST['group_key'] ?? ''));
    $b = ir2_get_batch($gk);
    if (!$b) {
        echo json_encode(['success' => false, 'message' => 'Không tìm thấy nhóm.']);
        exit;
    }
    echo json_encode(['success' => true, 'data' => $b], JSON_UNESCAPED_UNICODE);
    exit;
}

function edit_other_row_materialAction()
{
    header('Content-Type: application/json');
    permission_require_can_edit('inventory_receiving', 'inventory_receiving', 'other_row_material_receiving');
    $gk             = trim((string) ($_POST['group_key'] ?? ''));
    $items_raw      = $_POST['items'] ?? '[]';
    $items          = json_decode($items_raw, true) ?: [];
    $general_interp = (string) ($_POST['general_interpretation'] ?? '');
    $created_at     = trim((string) ($_POST['created_at'] ?? ''));
    $ca             = $created_at !== '' ? $created_at : null;

    if ($gk === '') {
        echo json_encode(['success' => false, 'message' => 'Thiếu group_key.']);
        exit;
    }
    if (empty($items)) {
        echo json_encode(['success' => false, 'message' => 'Chưa có nguyên vật liệu nào để cập nhật.']);
        exit;
    }

    $je = [
        'debit'  => $_POST['je_debit']  ?? null,
        'credit' => $_POST['je_credit'] ?? null,
        'amount' => $_POST['je_amount'] ?? null,
    ];
    $summary = ir2_summary_from_je($_POST['je_entries'] ?? '');
    $new_gk  = ir2_edit_batch($gk, $items, $general_interp, $ca, $je, $summary);

    echo json_encode([
        'success'      => $new_gk !== '',
        'group_key'    => $new_gk,
        'history'      => ir2_get_history_page(1, max(1, ir2_count_batches())),
        'history_total'=> ir2_count_batches(),
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

function delete_other_row_materialAction()
{
    header('Content-Type: application/json');
    permission_require_can_edit('inventory_receiving', 'inventory_receiving', 'other_row_material_receiving');
    $gk = trim((string) ($_POST['group_key'] ?? ''));
    if ($gk === '') {
        echo json_encode(['success' => false, 'message' => 'Thiếu group_key.']);
        exit;
    }
    $removed = ir2_delete_batch($gk);
    echo json_encode([
        'success'      => true,
        'removed'      => $removed,
        'history'      => ir2_get_history_page(1, max(1, ir2_count_batches())),
        'history_total'=> ir2_count_batches(),
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

function get_other_historyAction()
{
    header('Content-Type: application/json');
    $page = max(1, (int) ($_POST['page'] ?? 1));
    echo json_encode([
        'success'      => true,
        'data'         => ir2_get_history_page($page, 5),
        'history_total'=> ir2_count_batches(),
        'page'         => $page,
        'per_page'     => 5,
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

/**
 * AJAX — tính tổng je-amount cho list items hiện tại trên UI.
 * Input POST: items = JSON [ { material_id, quantity }, ... ]
 */
function compute_other_je_amountAction()
{
    header('Content-Type: application/json');
    $items_raw = $_POST['items'] ?? '[]';
    $items     = json_decode($items_raw, true) ?: [];
    $amount    = ir2_compute_je_amount($items);
    echo json_encode([
        'success' => true,
        'amount'  => (float) $amount,
    ], JSON_UNESCAPED_UNICODE);
    exit;
}
