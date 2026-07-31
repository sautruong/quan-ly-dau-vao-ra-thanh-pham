<?php
// Hint cho IDE (PHP Intelephense) resolve load_* và im_* — framework nạp động
// qua load_model. Hàm stub này không bao giờ được gọi nên không ảnh hưởng runtime.
if (!function_exists('__im_intelephense_hint_stub')) {
    function __im_intelephense_hint_stub()
    {
        require_once __DIR__ . '/../../../core/base.php';
        require_once __DIR__ . '/../models/inventory_managementModel.php';
    }
}

require_once __DIR__ . '/../../../libraries/warehouse_receipt_invoices.php';
require_once __DIR__ . '/../../../libraries/print_settings.php';

function construct()
{
    load_model('inventory_management');
}

/**
 * AJAX: upload file hóa đơn cho 1 phiếu "Nhập thành phẩm mua hàng" vừa ghi/sửa.
 * Input POST: invoice_id (= stock_import_invoices.id) ; FILES: files[].
 * Lưu vào warehouse_receipt_invoices type='purchase_invoice' (wr_id = warehouse_receipts.id).
 */
function upload_purchase_invoiceAction()
{
    header('Content-Type: application/json; charset=utf-8');
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

/** AJAX: Check Database — preview nội dung các bảng bị tác động (dùng chung). */
function check_databaseAction()
{
    require_once __DIR__ . '/../../../libraries/check_database.php';
    cdb_handle_ajax();
}

/**
 * Limit lịch sử mặc định cho từng page. Tất cả các tab đều phân trang 10/dòng,
 * nên nạp tối đa 100 batch để chia trang client-side.
 */
function im_history_limit($scope)
{
    return 100;
}


function dashboardAction()
{
    $plans       = im_get_plans_for_inventory();
    $plan_date   = date('d/m/Y');
    $type_import = 'fg_receipt_production';

    $items = [];
    foreach ($plans as $p) {
        $pid = (int) $p['product_id'];
        $items[] = [
            'plan_id'       => (int) $p['plan_id'],
            'product_id'    => $pid,
            'product_name'  => $p['product_name'],
            'quantity'      => (int) $p['quantity'],
            'plan_date'     => $plan_date,
            'current_stock' => im_get_current_stock($pid),
        ];
    }

    $history = im_get_recent_batches(100, $type_import);

    load_view('dashboard', [
        'items'       => $items,
        'plan_date'   => $plan_date,
        'history'     => $history,
        'type_import' => $type_import,
    ]);
}

/**
 * Nút × trên card sản phẩm (dashboard + investment_products): "Xóa hẳn dữ liệu ngày đó".
 * Xóa phiếu nhập thành phẩm (fg_receipt_production) của (product, ngày), trừ lại tồn kho,
 * xóa dòng sản lượng của ngày, và đánh dấu "đã gỡ" để cả 2 trang không nạp lại.
 * Input: product_id, date (Y-m-d), source ('dashboard'|'investment_products').
 */
function remove_day_productAction()
{
    header('Content-Type: application/json');
    $source = ($_POST['source'] ?? '') === 'investment_products' ? 'investment_products' : 'dashboard';
    permission_require_can_edit('inventory_management', 'inventory_management', $source);

    $pid  = (int) ($_POST['product_id'] ?? 0);
    $date = trim((string) ($_POST['date'] ?? ''));
    if ($pid <= 0) {
        echo json_encode(['success' => false, 'message' => 'Thiếu sản phẩm.']);
        exit;
    }
    if ($date === '') $date = date('Y-m-d');

    echo json_encode(im_remove_day_product($pid, $date), JSON_UNESCAPED_UNICODE);
    exit;
}

function search_productAction()
{
    header('Content-Type: application/json');
    $keyword = $_POST['keyword'] ?? '';
    echo json_encode(['data' => im_search_products($keyword)]);
    exit;
}

function get_productAction()
{
    header('Content-Type: application/json');
    $product_id = (int) ($_POST['product_id'] ?? 0);
    $p = im_get_product($product_id);
    if (!$p) {
        echo json_encode(['success' => false, 'message' => 'Product not found']);
        exit;
    }
    echo json_encode([
        'success' => true,
        'data' => [
            'product_id'    => (int) $p['id'],
            'product_name'  => $p['product_name'],
            'current_stock' => im_get_current_stock((int) $p['id']),
            'plan_date'     => date('d/m/Y'),
        ]
    ]);
    exit;
}

/**
 * Ghi sản lượng (tạo batch mới). Mỗi item: {product_id, quantity, interpretation}.
 * $_POST['created_at'] (tuỳ chọn): 'Y-m-d H:i:s' từ datetime picker phía client.
 */
function record_stockAction()
{
    header('Content-Type: application/json');

    $items_raw   = $_POST['items'] ?? '[]';
    $items       = json_decode($items_raw, true) ?: [];
    $created_at  = trim((string) ($_POST['created_at'] ?? ''));
    $ca          = $created_at !== '' ? $created_at : null;
    $type_import = im_normalize_type_import($_POST['type_import'] ?? 'fg_receipt_production');
    permission_require_can_edit('inventory_management', 'inventory_management', $type_import === 'other_receipt' ? 'other_receipt' : 'dashboard');

    if (empty($items)) {
        echo json_encode(['success' => false, 'message' => 'Chưa có sản phẩm nào để ghi.']);
        exit;
    }

    $count        = 0;
    $first_si_id  = 0;
    $sum_total    = 0.0;
    foreach ($items as $it) {
        $pid    = (int) ($it['product_id'] ?? 0);
        $qty    = (float) ($it['quantity'] ?? 0);
        $interp = trim((string) ($it['interpretation'] ?? ''));
        if ($pid <= 0 || $qty <= 0) continue;
        $si_id = (int) im_record_import($pid, $qty, $interp, $ca, $type_import);
        if ($si_id > 0) {
            $count++;
            if ($first_si_id === 0) $first_si_id = $si_id;
            if ($type_import === 'other_receipt') {
                $sum_total += $qty * im_compute_product_cost_per_unit($pid);
            }
        }
    }

    // other_receipt: ghi bút toán kế toán cho cả batch.
    // Default Dr 156 / Cr 338, amount = sum(qty * cost_per_unit); user có thể
    // override qua je_debit/je_credit/je_amount trên form GHI BÚT TOÁN KẾ TOÁN.
    if ($type_import === 'other_receipt' && $first_si_id > 0) {
        $resolved = im_je_resolve([
            'debit'  => $_POST['je_debit']  ?? null,
            'credit' => $_POST['je_credit'] ?? null,
            'amount' => $_POST['je_amount'] ?? null,
        ], '156', '338', $sum_total);
        // Đa bút toán: ghi tất cả cụm hiện diện trên giao diện (POST je_entries); else 1 cặp default.
        je_insert_pairs('other_receipt', $first_si_id, je_entries_from_payload([], $resolved), $ca);
    }

    echo json_encode([
        'success' => true,
        'count'   => $count,
        'history' => im_get_recent_batches(im_history_limit($type_import), $type_import),
    ]);
    exit;
}

/**
 * Cập nhật production_plans.quantity theo plan_id (ưu tiên) hoặc product_id.
 * Input: plan_id (int), product_id (int, fallback), quantity (int).
 */
function update_plan_quantityAction()
{
    header('Content-Type: application/json');
    $plan_id    = (int) ($_POST['plan_id']    ?? 0);
    $product_id = (int) ($_POST['product_id'] ?? 0);
    $quantity   = (int) ($_POST['quantity']   ?? 0);

    if ($plan_id <= 0 && $product_id <= 0) {
        echo json_encode(['success' => false, 'message' => 'Thiếu plan_id/product_id.']);
        exit;
    }
    if ($quantity < 0) {
        echo json_encode(['success' => false, 'message' => 'Quantity không hợp lệ.']);
        exit;
    }
    $ok = im_update_plan_quantity($plan_id, $product_id, $quantity);
    echo json_encode(['success' => (bool) $ok]);
    exit;
}

/**
 * Kiểm tra trùng ngày+sản phẩm trước khi ghi.
 * Input: product_ids (JSON mảng int), plan_date ('Y-m-d' hoặc dạng khác parse được).
 * Output: data = [{product_id, product_name, date_vn}, ...]
 */
function check_duplicatesAction()
{
    header('Content-Type: application/json');
    $ids_raw     = $_POST['product_ids'] ?? '[]';
    $product_ids = json_decode($ids_raw, true) ?: [];
    $plan_date   = trim((string) ($_POST['plan_date'] ?? ''));
    $type_import = trim((string) ($_POST['type_import'] ?? ''));
    echo json_encode(['data' => im_find_duplicate_imports($product_ids, $plan_date, $type_import !== '' ? $type_import : null)]);
    exit;
}

/** Trả lịch sử (JSON), tuỳ chọn scope theo type_import. Limit theo từng page (5 hoặc 100). */
function get_historyAction()
{
    header('Content-Type: application/json');
    $type_import = trim((string) ($_POST['type_import'] ?? ''));
    $scope       = $type_import !== '' ? $type_import : null;
    if ($scope === 'investment_production') {
        $data = im_get_investment_history_with_pending(im_history_limit($scope));
    } else {
        $data = im_get_recent_batches(im_history_limit($scope), $scope);
    }
    echo json_encode(['data' => $data]);
    exit;
}

/**
 * Sửa từng dòng trong 1 batch.
 * Input: items = [{import_id, quantity, interpretation}, ...]
 */
function edit_batch_stockAction()
{
    header('Content-Type: application/json');

    $items_raw   = $_POST['items'] ?? '[]';
    $items       = json_decode($items_raw, true) ?: [];
    $created_at  = trim((string) ($_POST['created_at'] ?? ''));
    $ca          = $created_at !== '' ? $created_at : null;
    $type_import = trim((string) ($_POST['type_import'] ?? ''));
    $scope       = $type_import !== '' ? im_normalize_type_import($type_import) : null;
    permission_require_can_edit('inventory_management', 'inventory_management', $scope === 'other_receipt' ? 'other_receipt' : 'dashboard');

    if (empty($items)) {
        echo json_encode(['success' => false, 'message' => 'Không có dữ liệu để cập nhật.']);
        exit;
    }

    $count       = 0;
    $import_ids  = [];
    foreach ($items as $it) {
        $iid    = (int) ($it['import_id'] ?? 0);
        $pid    = (int) ($it['product_id'] ?? 0);
        $qty    = (float) ($it['quantity'] ?? 0);
        $interp = trim((string) ($it['interpretation'] ?? ''));
        if ($iid > 0) {
            if (im_update_import_item($iid, $qty, $interp, $ca)) {
                $count++;
                $import_ids[] = $iid;
            }
        } elseif ($pid > 0 && $qty > 0) {
            // Sản phẩm được thêm mới trong lúc đang sửa nhóm → chèn thêm dòng,
            // dùng chung $ca để nằm cùng batch (group_key = created_at đến giây).
            $new_iid = (int) im_record_import($pid, $qty, $interp, $ca, $scope ?: 'fg_receipt_production');
            if ($new_iid > 0) {
                $count++;
                $import_ids[] = $new_iid;
            }
        }
    }

    // other_receipt: đồng bộ lại 2 dòng bút toán cho batch (user có thể override
    // account_code/amount qua form GHI BÚT TOÁN KẾ TOÁN).
    if ($scope === 'other_receipt' && !empty($import_ids)) {
        $je = [
            'debit'  => $_POST['je_debit']  ?? null,
            'credit' => $_POST['je_credit'] ?? null,
            'amount' => $_POST['je_amount'] ?? null,
        ];
        im_sync_other_receipt_transactions($import_ids, $ca, $je);
    }

    echo json_encode([
        'success' => true,
        'count'   => $count,
        'history' => im_get_recent_batches(im_history_limit($scope), $scope),
    ]);
    exit;
}

/** Xóa 1 batch (group_key = chuỗi 'Y-m-d H:i:s'), tuỳ chọn scope theo type_import. */
function delete_batch_stockAction()
{
    header('Content-Type: application/json');

    $group_key   = trim((string) ($_POST['group_key'] ?? ''));
    $type_import = trim((string) ($_POST['type_import'] ?? ''));
    $scope       = $type_import !== '' ? im_normalize_type_import($type_import) : null;
    permission_require_can_edit('inventory_management', 'inventory_management', $scope === 'other_receipt' ? 'other_receipt' : 'dashboard');

    if ($group_key === '') {
        echo json_encode(['success' => false, 'message' => 'Thiếu group_key.']);
        exit;
    }

    // other_receipt: xóa transactions trước khi xóa stock_imports (cần id để lookup).
    if ($scope === 'other_receipt') {
        $import_ids = im_get_other_receipt_import_ids($group_key);
        im_delete_other_receipt_transactions($import_ids);
    }

    $removed = im_delete_batch($group_key, $scope);
    echo json_encode([
        'success' => true,
        'removed' => $removed,
        'history' => im_get_recent_batches(im_history_limit($scope), $scope),
    ]);
    exit;
}

function other_receiptAction()
{
    $plan_date   = date('d/m/Y');
    $type_import = 'other_receipt';
    $history     = im_get_recent_batches(im_history_limit($type_import), $type_import);

    load_view('other_receipt', [
        'items'       => [],
        'plan_date'   => $plan_date,
        'history'     => $history,
        'type_import' => $type_import,
    ]);
}

/* ============================================================
 *   SALES ISSUE — page sales_issue (Xuất kho bán hàng)
 * ============================================================ */

function sales_issueAction()
{
    $plan_date   = date('d/m/Y');
    $type_export = 'sales_issue';
    $history     = im_get_recent_sales_issue_batches(100);

    load_view('sales_issue', [
        'plan_date'   => $plan_date,
        'history'     => $history,
        'type_export' => $type_export,
    ]);
}

/** Lấy 1 khách hàng đầy đủ field (cho page sales_issue). */
function get_customer_fullAction()
{
    header('Content-Type: application/json');
    $cid = (int) ($_POST['customer_id'] ?? 0);
    $c = im_get_customer($cid);
    if (!$c) {
        echo json_encode(['success' => false, 'message' => 'Customer not found']);
        exit;
    }
    echo json_encode([
        'success' => true,
        'data' => [
            'id'         => (int) $c['id'],
            'name'       => $c['name'],
            'short_name' => $c['short_name'] ?: '',
            'address'    => $c['address']    ?? '',
            'receiver'   => $c['receiver']   ?? '',
            'phone'      => $c['phone']      ?? '',
            // Chỉ trả khi đúng dạng #rrggbb — giá trị này được gán thẳng vào style
            // màu chữ ở client, lọc tại đây để không đẩy chuỗi lạ ra giao diện.
            'secondary_color' => preg_match('/^#[0-9a-fA-F]{6}$/', (string) ($c['secondary_color'] ?? ''))
                ? strtolower($c['secondary_color']) : '',
        ]
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

/** Nhắc "trước bốc hàng" (Điểm nhắc) áp dụng cho 1 khách hàng — hiện ở sales_delivery_note.php
 *  sau khi chọn khách hàng, xem [[reminder-points-module]]. Đọc -> không cần gate quyền. */
function get_branch_pickup_remindersAction()
{
    header('Content-Type: application/json');
    $cid = (int) ($_POST['customer_id'] ?? 0);
    echo json_encode(['success' => true, 'data' => im_get_branch_pickup_reminders($cid)], JSON_UNESCAPED_UNICODE);
    exit;
}

/** Xóa 1 lời nhắc "trước bốc hàng" tại sales_delivery_note.php — nghĩa là đã thực thi. */
function delete_branch_pickup_reminderAction()
{
    header('Content-Type: application/json');
    permission_require_can_edit('warehouse_outbound', 'warehouse_outbound', 'sales_delivery_note');
    $id = (int) ($_POST['id'] ?? 0);
    echo json_encode(['success' => im_delete_branch_pickup_reminder($id)], JSON_UNESCAPED_UNICODE);
    exit;
}

/**
 * Ghi 1 phiếu xuất kho bán hàng (TP + NVL).
 * Input:
 *   customer_id,
 *   created_at ('Y-m-d H:i:s'),
 *   items = [{id, type:'product'|'material', quantity, unit_price, total_amount, interpretation}, ...]
 *   weight       (kg)    — tổng khối lượng (.wp-weight > .result)
 *   goods_value  (VND)   — giá trị hàng hóa (.wp-value > .result)
 *   je_debit, je_credit, je_amount — bút toán user (optional, default Dr 131 / Cr 511 / amount=goods_value).
 * Output:
 *   { success, recorded, shortages, invoice_id, history }
 *   - recorded:  [{id, type}, ...]
 *   - shortages: [{id, type, available, requested}, ...]
 */
function record_sales_issueAction()
{
    header('Content-Type: application/json');
    permission_require_can_edit('warehouse_outbound', 'warehouse_outbound', 'sales_delivery_note');

    $cid          = (int) ($_POST['customer_id'] ?? 0);
    $items_raw    = $_POST['items'] ?? '[]';
    $items        = json_decode($items_raw, true) ?: [];
    $created_at   = trim((string) ($_POST['created_at'] ?? ''));
    $ca           = $created_at !== '' ? $created_at : null;
    $total_qty_in = isset($_POST['total_quantity']) ? (float) $_POST['total_quantity'] : -1.0;
    $weight       = (float) ($_POST['weight']      ?? 0);
    $goods_value  = (float) ($_POST['goods_value'] ?? 0);

    if ($cid <= 0) {
        echo json_encode(['success' => false, 'message' => 'Chưa chọn khách hàng.']);
        exit;
    }
    if (empty($items)) {
        echo json_encode(['success' => false, 'message' => 'Chưa có sản phẩm nào để ghi.']);
        exit;
    }

    // Phase 1: chuẩn hoá payload + probe tồn để xác định batch có thiếu tồn không.
    // KHÔNG đụng bảng nào trong phase này — chỉ đọc tồn hiện tại.
    $validated = [];
    $shortages = [];
    foreach ($items as $it) {
        $id     = (int) ($it['id'] ?? $it['product_id'] ?? 0);
        $type   = trim((string) ($it['type'] ?? 'product'));
        $qty    = (float) ($it['quantity'] ?? 0);
        $price  = (float) ($it['unit_price'] ?? 0);
        $total  = (float) ($it['total_amount'] ?? 0);
        $interp = trim((string) ($it['interpretation'] ?? ''));
        if ($id <= 0 || $qty <= 0) continue;

        if ($type === 'material') {
            $row = db_fetch_row("SELECT quantity FROM material_inventory WHERE material_id = $id LIMIT 1");
        } else {
            $row = db_fetch_row("SELECT quantity FROM finished_goods_inventory WHERE product_id = $id LIMIT 1");
        }
        $available = $row ? (float) $row['quantity'] : 0.0;

        $validated[] = [
            'id'    => $id, 'type' => $type, 'qty' => $qty,
            'price' => $price, 'total' => $total, 'interp' => $interp,
            'available' => $available,
        ];
        if ($available < $qty) {
            $shortages[] = [
                'id'        => $id,
                'type'      => $type,
                'available' => $available,
                'requested' => $qty,
            ];
        }
    }

    // Phase 2A: batch thiếu tồn → CHỈ ghi stock_exports với marker shortage,
    // KHÔNG trừ tồn / KHÔNG ghi sales_warehouse_export_invoices / KHÔNG ghi transactions.
    if (!empty($shortages)) {
        foreach ($validated as $iv) {
            im_record_shortage_export($iv['id'], $iv['type'], $cid, $iv['qty'], $iv['price'], $iv['total'], $ca);
        }
        echo json_encode([
            'success'        => true,
            'recorded'       => [],
            'shortages'      => $shortages,
            'shortage_batch' => true,
            'invoice_id'     => 0,
            'history'        => im_get_recent_sales_issue_batches(100),
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }

    // Phase 2B: không thiếu tồn → flow ghi đầy đủ (tồn, invoice, transactions).
    $recorded = [];
    $sum_qty  = 0.0;
    foreach ($validated as $iv) {
        $r = ($iv['type'] === 'material')
            ? im_record_sales_issue_material($iv['id'], $cid, $iv['qty'], $iv['price'], $iv['total'], $iv['interp'], $ca)
            : im_record_sales_issue($iv['id'], $cid, $iv['qty'], $iv['price'], $iv['total'], $iv['interp'], $ca);

        if (!empty($r['ok'])) {
            $recorded[] = ['id' => $iv['id'], 'type' => $iv['type']];
            $sum_qty   += $iv['qty'];
        }
    }

    $invoice_id = 0;
    if (!empty($recorded)) {
        // sum_qty từ UI (nếu được gửi) > sum_qty tính lại từ items đã ghi.
        $invoice_qty = ($total_qty_in >= 0) ? $total_qty_in : $sum_qty;
        // Ghi 1 dòng sales_warehouse_export_invoices cho cả phiếu.
        $invoice_id = im_record_sales_delivery_invoice($cid, $invoice_qty, $weight, $goods_value, $ca);

        // Ghi 2 dòng bút toán Dr/Cr; user có thể override qua form bút toán.
        if ($invoice_id > 0) {
            $je = [
                'debit'  => $_POST['je_debit']  ?? null,
                'credit' => $_POST['je_credit'] ?? null,
                'amount' => $_POST['je_amount'] ?? null,
            ];
            im_record_sales_delivery_transactions($invoice_id, $goods_value, $ca, $je);
        }
    }

    echo json_encode([
        'success'        => true,
        'recorded'       => $recorded,
        'shortages'      => [],
        'shortage_batch' => false,
        'invoice_id'     => $invoice_id,
        'history'        => im_get_recent_sales_issue_batches(100),
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

/** Trả tồn hiện tại của 1 product (để xem trước khi ghi nếu cần). */
function get_current_stockAction()
{
    header('Content-Type: application/json');
    $pid = (int) ($_POST['product_id'] ?? 0);
    echo json_encode([
        'success' => true,
        'data'    => ['product_id' => $pid, 'current_stock' => im_get_current_stock($pid)],
    ]);
    exit;
}

/* ============================================================
 *   SALES RETURN — page sales_return_receipt
 * ============================================================ */

function sales_return_receiptAction()
{
    $plan_date   = date('d/m/Y');
    $type_import = 'sales_return_receipt';
    $history     = im_get_recent_sales_return_batches(100);

    load_view('sales_return_receipt', [
        'plan_date'   => $plan_date,
        'history'     => $history,
        'type_import' => $type_import,
    ]);
}

/** Tìm khách hàng theo keyword (cho ô #customer). */
function search_customerAction()
{
    header('Content-Type: application/json');
    $keyword = $_POST['keyword'] ?? '';
    echo json_encode(['data' => im_search_customers($keyword)], JSON_UNESCAPED_UNICODE);
    exit;
}

/** Lấy 1 khách hàng theo id. */
function get_customerAction()
{
    header('Content-Type: application/json');
    $cid = (int) ($_POST['customer_id'] ?? 0);
    $c = im_get_customer($cid);
    if (!$c) {
        echo json_encode(['success' => false, 'message' => 'Customer not found']);
        exit;
    }
    echo json_encode([
        'success' => true,
        'data' => [
            'id'         => (int) $c['id'],
            'name'       => $c['name'],
            'short_name' => $c['short_name'] ?: '',
        ]
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

/**
 * Ghi 1 batch hàng trả lại.
 * Input: customer_id, created_at ('Y-m-d H:i:s'),
 *        items = [{product_id, quantity, reason, interpretation}, ...]
 */
function record_sales_returnAction()
{
    header('Content-Type: application/json');
    permission_require_can_edit('inventory_management', 'inventory_management', 'sales_return_receipt');

    $cid        = (int) ($_POST['customer_id'] ?? 0);
    $items_raw  = $_POST['items'] ?? '[]';
    $items      = json_decode($items_raw, true) ?: [];
    $created_at = trim((string) ($_POST['created_at'] ?? ''));
    $ca         = $created_at !== '' ? $created_at : null;

    if ($cid <= 0) {
        echo json_encode(['success' => false, 'message' => 'Chưa chọn khách hàng.']);
        exit;
    }
    if (empty($items)) {
        echo json_encode(['success' => false, 'message' => 'Chưa có sản phẩm nào để ghi.']);
        exit;
    }

    $count        = 0;
    $any_sid      = 0;
    foreach ($items as $it) {
        $pid    = (int) ($it['product_id'] ?? 0);
        $qty    = (float) ($it['quantity'] ?? 0);
        $reason = trim((string) ($it['reason'] ?? ''));
        $interp = trim((string) ($it['interpretation'] ?? ''));
        if ($pid <= 0 || $qty <= 0) continue;
        $r = im_record_sales_return($pid, $cid, $qty, $reason, $interp, $ca);
        if ($r) {
            $count++;
            if ($any_sid === 0 && !empty($r['sales_return_id'])) {
                $any_sid = (int) $r['sales_return_id'];
            }
        }
    }

    $je = [
        'debit'  => $_POST['je_debit']  ?? null,
        'credit' => $_POST['je_credit'] ?? null,
        'amount' => $_POST['je_amount'] ?? null,
    ];
    if ($any_sid > 0) im_sync_sales_return_transactions_by_id($any_sid, $je);

    echo json_encode([
        'success' => true,
        'count'   => $count,
        'history' => im_get_recent_sales_return_batches(100),
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

/** Lấy chi tiết 1 batch để render form sửa / xử lý. */
function get_sales_return_batchAction()
{
    header('Content-Type: application/json');
    $group_key = trim((string) ($_POST['group_key'] ?? ''));
    $cid       = (int) ($_POST['customer_id'] ?? 0);
    $batch     = im_get_sales_return_batch($group_key, $cid);
    if (!$batch) {
        echo json_encode(['success' => false, 'message' => 'Không tìm thấy nhóm.']);
        exit;
    }
    echo json_encode(['success' => true, 'data' => $batch], JSON_UNESCAPED_UNICODE);
    exit;
}

/**
 * Sửa 1 batch sales_return. Hỗ trợ chèn thêm sản phẩm mới vào batch đang sửa.
 * Input:
 *   - customer_id (cho item mới — phải khớp customer của batch)
 *   - created_at  ('Y-m-d H:i:s' — group_key mới của batch nếu user đổi datetime)
 *   - items = [
 *       // dòng cũ → cập nhật
 *       {sales_return_id, import_id, quantity, reason, interpretation},
 *       // dòng mới → chèn (sales_return_id = 0)
 *       {sales_return_id: 0, product_id, quantity, reason, interpretation}
 *     ]
 */
function edit_sales_returnAction()
{
    header('Content-Type: application/json');
    permission_require_can_edit('inventory_management', 'inventory_management', 'sales_return_receipt');
    $items_raw  = $_POST['items'] ?? '[]';
    $items      = json_decode($items_raw, true) ?: [];
    $cid        = (int) ($_POST['customer_id'] ?? 0);
    $created_at = trim((string) ($_POST['created_at'] ?? ''));
    $ca         = $created_at !== '' ? $created_at : null;

    if (empty($items)) {
        echo json_encode(['success' => false, 'message' => 'Không có dữ liệu để cập nhật.']);
        exit;
    }
    $count   = 0;
    $any_sid = 0;
    foreach ($items as $it) {
        $sid = (int) ($it['sales_return_id'] ?? 0);
        $iid = (int) ($it['import_id'] ?? 0);
        $qty = (float) ($it['quantity'] ?? 0);
        $rs  = trim((string) ($it['reason'] ?? ''));
        $ip  = trim((string) ($it['interpretation'] ?? ''));

        if ($sid > 0) {
            if (im_update_sales_return_item($sid, $iid, $qty, $rs, $ip)) {
                $count++;
                if ($any_sid === 0) $any_sid = $sid;
            }
        } else {
            $pid = (int) ($it['product_id'] ?? 0);
            if ($pid <= 0 || $cid <= 0 || $qty <= 0) continue;
            $r = im_record_sales_return($pid, $cid, $qty, $rs, $ip, $ca);
            if ($r) {
                $count++;
                if ($any_sid === 0 && !empty($r['sales_return_id'])) {
                    $any_sid = (int) $r['sales_return_id'];
                }
            }
        }
    }

    $je = [
        'debit'  => $_POST['je_debit']  ?? null,
        'credit' => $_POST['je_credit'] ?? null,
        'amount' => $_POST['je_amount'] ?? null,
    ];
    if ($any_sid > 0) im_sync_sales_return_transactions_by_id($any_sid, $je);

    echo json_encode([
        'success' => true,
        'count'   => $count,
        'history' => im_get_recent_sales_return_batches(100),
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

/**
 * Xóa 1 dòng sales_return + stock_imports tương ứng. Đồng thời điều chỉnh
 * finished_goods_inventory nếu handling_method trước đó đã cộng tồn.
 * Input: sales_return_id, import_id (tuỳ chọn).
 */
function delete_sales_return_itemAction()
{
    header('Content-Type: application/json');
    permission_require_can_edit('inventory_management', 'inventory_management', 'sales_return_receipt');
    $sid = (int) ($_POST['sales_return_id'] ?? 0);
    $iid = (int) ($_POST['import_id'] ?? 0);
    if ($sid <= 0) {
        echo json_encode(['success' => false, 'message' => 'Thiếu sales_return_id.']);
        exit;
    }
    // Lấy thông tin batch trước khi xóa để biết group_key + customer_id
    $batch_info = im_get_sales_return_batch_key($sid);
    $ok = im_delete_sales_return_item($sid, $iid);
    if ($ok && $batch_info) {
        // Sync transactions theo các sales_return_id còn lại của batch (nếu hết → xóa hết tx).
        $remaining = im_get_sales_return_ids_for_batch($batch_info['group_key'], $batch_info['customer_id']);
        if (!empty($remaining)) {
            im_sync_sales_return_transactions($batch_info['group_key'], $batch_info['customer_id']);
        } else {
            im_delete_sales_return_transactions([$sid]);
        }
    }
    echo json_encode([
        'success' => (bool) $ok,
        'history' => im_get_recent_sales_return_batches(100),
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

/**
 * Xóa toàn bộ 1 batch sales_return (group_key + customer_id):
 * sales_returns + stock_imports + transactions; rollback tồn nếu đã add.
 */
function delete_sales_return_batchAction()
{
    header('Content-Type: application/json');
    permission_require_can_edit('inventory_management', 'inventory_management', 'sales_return_receipt');
    $group_key = trim((string) ($_POST['group_key'] ?? ''));
    $cid       = (int) ($_POST['customer_id'] ?? 0);
    if ($group_key === '' || $cid <= 0) {
        echo json_encode(['success' => false, 'message' => 'Thiếu group_key/customer_id.']);
        exit;
    }
    $removed = im_delete_sales_return_batch($group_key, $cid);
    echo json_encode([
        'success' => true,
        'removed' => $removed,
        'history' => im_get_recent_sales_return_batches(100),
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

/**
 * Áp dụng cách thức xử lý cho 1 batch.
 * Input: items = [{sales_return_id, method}, ...]
 */
function handle_sales_returnAction()
{
    header('Content-Type: application/json');
    permission_require_can_edit('inventory_management', 'inventory_management', 'sales_return_receipt');
    $items_raw = $_POST['items'] ?? '[]';
    $items     = json_decode($items_raw, true) ?: [];
    if (empty($items)) {
        echo json_encode(['success' => false, 'message' => 'Chưa chọn cách thức xử lý.']);
        exit;
    }
    $count   = 0;
    $any_sid = 0;
    foreach ($items as $it) {
        $sid    = (int) ($it['sales_return_id'] ?? 0);
        $method = trim((string) ($it['method'] ?? ''));
        // SL xử lý đợt này (tùy chọn) — nhỏ hơn SL dòng thì tách dòng, phần còn lại chờ đợt sau.
        $hq     = isset($it['qty']) && $it['qty'] !== '' ? (float) $it['qty'] : null;
        if ($sid <= 0 || $method === '') continue;
        if (im_handle_sales_return_item($sid, $method, $hq)) {
            $count++;
            if ($any_sid === 0) $any_sid = $sid;
        }
    }

    if ($any_sid > 0) im_sync_sales_return_transactions_by_id($any_sid);

    echo json_encode([
        'success' => true,
        'count'   => $count,
        'history' => im_get_recent_sales_return_batches(100),
    ], JSON_UNESCAPED_UNICODE);
    exit;
}
/* ============================================================
 *   INVESTMENT PRODUCTION — page investment_products
 * ============================================================ */

function investment_productsAction()
{
    $plan_date   = date('d/m/Y');
    $type_import = 'investment_production';

    // Khởi tạo theo ngày hiện tại — JS sẽ refetch khi user đổi #record-datetime.
    $items = im_get_investment_items_for_date(date('Y-m-d'));
    foreach ($items as &$it) {
        $it['plan_date'] = $plan_date;
    }
    unset($it);

    $history = im_get_investment_history_with_pending(im_history_limit($type_import));

    load_view('investment_products', [
        'items'                => $items,
        'plan_date'            => $plan_date,
        'history'              => $history,
        'type_import'          => $type_import,
        'production_cost_rate' => production_cost_rate(),
    ]);
}

/**
 * TASK 3b: tính tổng giá vốn hàng bán (632) cho phiếu xuất kho bán hàng.
 * Input: items = JSON [{id, type:'product'|'material', qty}].
 * Output: { success, cogs }.
 */
function compute_sales_cogsAction()
{
    header('Content-Type: application/json');
    $items = json_decode($_POST['items'] ?? '[]', true);
    if (!is_array($items)) $items = [];
    echo json_encode(['success' => true, 'cogs' => im_sales_cogs_for_items($items)]);
    exit;
}

/**
 * TASK 3: trả breakdown chi tiết giá vốn hàng bán (632) để modal "con mắt" giải thích.
 * Input: items (JSON: [{id,type,qty}]). Output: { success, data:{rate,total,rows[]} }.
 */
function explain_sales_cogsAction()
{
    header('Content-Type: application/json');
    $items = json_decode($_POST['items'] ?? '[]', true);
    if (!is_array($items)) $items = [];
    echo json_encode(['success' => true, 'data' => im_sales_cogs_breakdown_for_items($items)], JSON_UNESCAPED_UNICODE);
    exit;
}

/** Lấy đơn giá chi phí sản xuất hiện tại (overhead/đơn vị). */
function get_production_cost_rateAction()
{
    header('Content-Type: application/json');
    echo json_encode(['success' => true, 'rate' => production_cost_rate()]);
    exit;
}

/** Lưu đơn giá chi phí sản xuất mới (1 dòng config, áp dụng hồi tố). */
function save_production_cost_rateAction()
{
    header('Content-Type: application/json');
    permission_require_can_edit('inventory_management', 'inventory_management', 'investment_products');
    $rate = isset($_POST['rate']) ? (float) $_POST['rate'] : -1;
    if ($rate < 0) {
        echo json_encode(['success' => false, 'message' => 'Đơn giá không hợp lệ.']);
        exit;
    }
    $saved = set_production_cost_rate($rate);
    echo json_encode(['success' => true, 'rate' => $saved]);
    exit;
}

/** Nút × trên dòng "Chi phí sản xuất" — loại 1 sản phẩm (vd hàng mẫu) khỏi việc tính overhead. */
function remove_production_cost_from_productAction()
{
    header('Content-Type: application/json');
    permission_require_can_edit('inventory_management', 'inventory_management', 'investment_products');
    $pid = (int) ($_POST['product_id'] ?? 0);
    if ($pid <= 0) {
        echo json_encode(['success' => false, 'message' => 'Thiếu sản phẩm.']);
        exit;
    }
    im_set_exclude_production_cost($pid, true);
    echo json_encode(['success' => true]);
    exit;
}

/**
 * Refetch list sản phẩm + materials theo NGÀY (Y-m-d) khi user đổi datetime picker.
 * Input: date ('Y-m-d' hoặc bất kỳ chuỗi parse được).
 */
function get_investment_items_for_dateAction()
{
    header('Content-Type: application/json');
    $date  = trim((string) ($_POST['date'] ?? ''));
    $items = im_get_investment_items_for_date($date);
    echo json_encode([
        'success' => true,
        'data'    => $items,
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

/**
 * TASK 2: truy vấn ngược lần sản xuất tương đồng (±3% sản lượng) để lấy lại list
 * total_qty NVL của lần đó. Input: product_id, quantity (iqt), exclude_date (Y-m-d).
 * Output: { success, matched, source_date, source_quantity, deviation, materials[] }.
 */
function get_similar_production_materialsAction()
{
    header('Content-Type: application/json');
    $pid  = (int) ($_POST['product_id'] ?? 0);
    $qty  = (float) ($_POST['quantity'] ?? 0);
    $excl = trim((string) ($_POST['exclude_date'] ?? ''));
    if ($pid <= 0 || $qty <= 0) {
        echo json_encode(['success' => true, 'matched' => false, 'materials' => []]);
        exit;
    }
    $res = im_get_similar_production_issue($pid, $qty, $excl);
    echo json_encode(array_merge(['success' => true], $res), JSON_UNESCAPED_UNICODE);
    exit;
}

/**
 * Cập nhật product_materials.quantity_required cho 1 cặp (product_id, material_id).
 * Input: product_id, material_id, quantity_required
 */
function update_material_qtyAction()
{
    header('Content-Type: application/json');
    permission_require_can_edit('inventory_management', 'inventory_management', 'investment_products');
    $pid = (int) ($_POST['product_id'] ?? 0);
    $mid = (int) ($_POST['material_id'] ?? 0);
    $qr  = isset($_POST['quantity_required']) ? (float) $_POST['quantity_required'] : -1.0;

    if ($pid <= 0 || $mid <= 0 || $qr < 0) {
        echo json_encode(['success' => false, 'message' => 'Tham số không hợp lệ.']);
        exit;
    }
    $ok = im_update_product_material_qty($pid, $mid, $qr);
    echo json_encode([
        'success' => (bool) $ok,
        'data'    => [
            'product_id'        => $pid,
            'material_id'       => $mid,
            'quantity_required' => $qr,
        ],
    ]);
    exit;
}

/** Tìm NVL theo keyword cho dropdown gợi ý ở .name-material. */
function search_materialsAction()
{
    header('Content-Type: application/json');
    $keyword = $_POST['keyword'] ?? '';
    echo json_encode(['data' => im_search_materials($keyword)], JSON_UNESCAPED_UNICODE);
    exit;
}

/**
 * Tính giá vốn xuất 1 NVL theo MÔ HÌNH 2 LỚP GIÁ (Phần 1 — page investment_products).
 * Input: material_id, qty (= .input-total-qty), as_of ('Y-m-d H:i:s' = #record-datetime).
 * Output: data = { total_cost, unit_price, mi_ago, price_old, price_new, q_old, q_new,
 *                  warn, has_receipt, material_name, unit }.
 */
function compute_material_issue_costAction()
{
    header('Content-Type: application/json');
    $mid   = (int) ($_POST['material_id'] ?? 0);
    $qty   = (float) ($_POST['qty'] ?? 0);
    $as_of = trim((string) ($_POST['as_of'] ?? ''));
    if ($mid <= 0) {
        echo json_encode(['success' => false, 'message' => 'Thiếu material_id.']);
        exit;
    }

    $res = mic_compute_issue_cost($mid, $qty, $as_of !== '' ? $as_of : null);

    $info = db_fetch_row("SELECT material_name, unit FROM material_information WHERE id = $mid LIMIT 1");
    echo json_encode([
        'success' => true,
        'data' => [
            'total_cost'    => (float) $res['total'],
            'unit_price'    => (float) $res['unit_price'],
            'mi_ago'        => $res['mi_ago'] !== null ? (float) $res['mi_ago'] : null,
            'price_old'     => (float) $res['price_old'],
            'price_new'     => (float) $res['price_new'],
            'q_old'         => (float) $res['q_old'],
            'q_new'         => (float) $res['q_new'],
            'warn'          => (bool) $res['warn'],
            'has_receipt'   => (bool) $res['has_receipt'],
            'material_name' => $info && $info['material_name'] !== null ? $info['material_name'] : ('#' . $mid),
            'unit'          => $info && $info['unit'] !== null ? $info['unit'] : '',
        ],
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

/**
 * Cập nhật định mức (BOM) của 1 product theo danh sách thành phần hiện trên giao diện.
 * Input: product_id, materials = JSON [{material_id, quantity_required}, ...] (thứ tự top→bottom).
 */
function update_product_normAction()
{
    header('Content-Type: application/json');
    permission_require_can_edit('inventory_management', 'inventory_management', 'investment_products');
    $pid       = (int) ($_POST['product_id'] ?? 0);
    $materials = json_decode($_POST['materials'] ?? '[]', true) ?: [];

    if ($pid <= 0) {
        echo json_encode(['success' => false, 'message' => 'Thiếu mã sản phẩm.']);
        exit;
    }
    if (empty($materials)) {
        echo json_encode(['success' => false, 'message' => 'Cần ít nhất 1 thành phần.']);
        exit;
    }
    $ok = im_update_product_materials($pid, $materials);
    echo json_encode([
        'success' => (bool) $ok,
        'message' => $ok ? '' : 'Không cập nhật được định mức (thiếu thành phần hợp lệ).',
    ]);
    exit;
}

/**
 * Cập nhật purchase_price (giá mua) cho 1 material trong material_purchase_prices.
 * Input: material_id, price.
 */
function update_material_purchase_priceAction()
{
    header('Content-Type: application/json');
    permission_require_can_edit('inventory_management', 'inventory_management', 'investment_products');
    $mid   = (int) ($_POST['material_id'] ?? 0);
    $price = isset($_POST['price']) ? (float) $_POST['price'] : -1.0;

    if ($mid <= 0 || $price < 0) {
        echo json_encode(['success' => false, 'message' => 'Tham số không hợp lệ.']);
        exit;
    }
    $rid = im_save_material_purchase_price($mid, $price);
    echo json_encode([
        'success' => $rid > 0,
        'data'    => [
            'material_id'    => $mid,
            'purchase_price' => (int) round($price),
        ],
    ]);
    exit;
}

/**
 * Ghi 1 phiếu "Nhập giá vốn sản xuất".
 * Input (JSON shape items):
 *   items = [
 *     { product_id, materials: [{material_id, total_qty, unit_price, total_cost}, ...] },
 *     ...
 *   ]
 *   cost_price, goods_value, created_at ('Y-m-d H:i:s')
 */
function record_investmentAction()
{
    header('Content-Type: application/json');
    permission_require_can_edit('inventory_management', 'inventory_management', 'investment_products');
    $items_raw   = $_POST['items'] ?? '[]';
    $items       = json_decode($items_raw, true) ?: [];
    $cost_price  = (float) ($_POST['cost_price']  ?? 0);
    $goods_value = (float) ($_POST['goods_value'] ?? 0);
    $created_at  = trim((string) ($_POST['created_at'] ?? ''));
    $ca          = $created_at !== '' ? $created_at : null;

    if (empty($items)) {
        echo json_encode(['success' => false, 'message' => 'Chưa có sản phẩm nào để ghi.']);
        exit;
    }

    // "Ghi đè": xóa các phiếu giá vốn cũ trùng (product + ngày) TRƯỚC khi ghi phiếu mới,
    // để tồn NVL được hoàn lại đúng trước khi tính cảnh báo + trừ tồn cho phiếu mới.
    $overwrite_raw  = $_POST['overwrite_keys'] ?? '[]';
    $overwrite_keys = json_decode($overwrite_raw, true) ?: [];
    if (is_array($overwrite_keys)) {
        foreach (array_unique($overwrite_keys) as $gk) {
            $gk = trim((string) $gk);
            if ($gk !== '') im_delete_investment_batch($gk);
        }
    }

    $je = [
        'debit'  => $_POST['je_debit']  ?? null,
        'credit' => $_POST['je_credit'] ?? null,
        'amount' => $_POST['je_amount'] ?? null,
    ];

    // Đối chiếu tồn NVL TRƯỚC KHI ghi (material_inventory chưa bị trừ) để n1 = tồn thật.
    $stock_warnings = im_check_material_stock_warnings($items);
    // Cảnh báo NVL "kiểm soát" (tea_scent_group) sắp cần ủ thêm — cũng tính TRƯỚC khi ghi.
    $tea_scent_warnings = function_exists('tsg_check_usage_warnings') ? tsg_check_usage_warnings($items) : [];

    $si_id = im_record_investment($items, $cost_price, $goods_value, $ca, $je);

    echo json_encode([
        'success'            => $si_id > 0,
        'stock_imports_id'   => $si_id,
        'history'            => im_get_investment_history_with_pending(im_history_limit('investment_production')),
        'stock_warnings'     => $stock_warnings,
        'tea_scent_warnings' => $tea_scent_warnings,
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

/**
 * Kiểm tra trùng phiếu giá vốn theo (product_ids, plan_date).
 * Input: product_ids JSON, plan_date.
 * Output: data = [{product_id, product_name, date_vn}, ...]
 */
function check_investment_duplicatesAction()
{
    header('Content-Type: application/json');
    $ids_raw     = $_POST['product_ids'] ?? '[]';
    $product_ids = json_decode($ids_raw, true) ?: [];
    $plan_date   = trim((string) ($_POST['plan_date'] ?? ''));
    echo json_encode(['data' => im_find_duplicate_investments($product_ids, $plan_date)]);
    exit;
}

/**
 * Lấy chi tiết 1 batch investment để render lại trong chế độ Sửa.
 * Input: group_key.
 * Output: data = items[] (có materials lấy từ stock_exports).
 */
function get_investment_batch_detailAction()
{
    header('Content-Type: application/json');
    $group_key = trim((string) ($_POST['group_key'] ?? ''));
    $items     = im_get_investment_batch_detail($group_key);
    echo json_encode(['success' => true, 'data' => $items], JSON_UNESCAPED_UNICODE);
    exit;
}

/**
 * Cập nhật 1 batch investment đang ở chế độ Sửa: items, cost_price, goods_value.
 * Server tự tính delta vs stock_exports cũ để điều chỉnh material_inventory.
 */
function edit_investment_batchAction()
{
    header('Content-Type: application/json');
    permission_require_can_edit('inventory_management', 'inventory_management', 'investment_products');
    $group_key   = trim((string) ($_POST['group_key'] ?? ''));
    $items_raw   = $_POST['items'] ?? '[]';
    $items       = json_decode($items_raw, true) ?: [];
    $cost_price  = (float) ($_POST['cost_price']  ?? 0);
    $goods_value = (float) ($_POST['goods_value'] ?? 0);

    if ($group_key === '') {
        echo json_encode(['success' => false, 'message' => 'Thiếu group_key.']);
        exit;
    }
    $je = [
        'debit'  => $_POST['je_debit']  ?? null,
        'credit' => $_POST['je_credit'] ?? null,
        'amount' => $_POST['je_amount'] ?? null,
    ];
    // Cảnh báo NVL "kiểm soát" TRƯỚC khi sửa — loại trừ usage cũ của chính batch này
    // để tồn "trước" phản ánh đúng thời điểm trước khi Sửa (không bị tính trùng).
    $tea_scent_warnings = function_exists('tsg_check_usage_warnings') ? tsg_check_usage_warnings($items, $group_key) : [];

    $ok = im_update_investment_batch($group_key, $items, $cost_price, $goods_value, $je);
    echo json_encode([
        'success'            => (bool) $ok,
        'history'            => im_get_investment_history_with_pending(im_history_limit('investment_production')),
        'tea_scent_warnings' => $tea_scent_warnings,
    ]);
    exit;
}

/** Xóa 1 batch investment theo group_key (đồng bộ 4 bảng). */
function delete_investment_batchAction()
{
    header('Content-Type: application/json');
    permission_require_can_edit('inventory_management', 'inventory_management', 'investment_products');
    $group_key = trim((string) ($_POST['group_key'] ?? ''));
    if ($group_key === '') {
        echo json_encode(['success' => false, 'message' => 'Thiếu group_key.']);
        exit;
    }
    $removed = im_delete_investment_batch($group_key);
    echo json_encode([
        'success' => true,
        'removed' => $removed,
        'history' => im_get_investment_history_with_pending(im_history_limit('investment_production')),
    ]);
    exit;
}

/* ============================================================
 *   SALES DELIVERY NOTE — page sales_delivery_note (warehouse_outbound)
 *   Các action riêng cho ô .name_product tìm cả products + material_information
 *   và lấy weight/selling_price mặc định cho từng dòng.
 * ============================================================ */

/**
 * AJAX: lưu 1 trường thông tin cố định của phiếu in (company_name, address, signer...).
 * Dùng chung cho modal in phiếu xuất kho. Chỉ chấp nhận key trong whitelist.
 */
function save_print_settingAction()
{
    header('Content-Type: application/json');
    permission_require_can_edit('warehouse_outbound', 'warehouse_outbound', 'sales_delivery_note');
    $key = trim((string) ($_POST['key'] ?? ''));
    $val = (string) ($_POST['value'] ?? '');
    $ok  = print_settings_save($key, $val);
    echo json_encode([
        'success' => $ok,
        'message' => $ok ? '' : 'Trường không hợp lệ.',
        'key'     => $key,
        'value'   => $val,
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

/**
 * Tìm products theo keyword; nếu rỗng → fallback material_information.
 * Trả mỗi item: {id, name, type}, type ∈ {'product', 'material'}.
 */
function search_product_or_materialAction()
{
    header('Content-Type: application/json');
    $keyword = $_POST['keyword'] ?? '';
    echo json_encode(['data' => im_search_products_or_materials($keyword)], JSON_UNESCAPED_UNICODE);
    exit;
}

/**
 * Lấy chi tiết 1 hàng hóa (product hoặc material) gồm weight_default + unit_price.
 * Input: id (int), type ('product'|'material').
 */
function get_item_defaultsAction()
{
    header('Content-Type: application/json');
    $id   = (int) ($_POST['id'] ?? 0);
    $type = trim((string) ($_POST['type'] ?? 'product'));
    if ($id <= 0) {
        echo json_encode(['success' => false, 'message' => 'Thiếu id.']);
        exit;
    }
    if ($type === 'material') {
        $m = im_get_material($id);
        if (!$m) {
            echo json_encode(['success' => false, 'message' => 'Material not found']);
            exit;
        }
        echo json_encode([
            'success' => true,
            'data' => [
                'id'         => (int) $m['id'],
                'name'       => $m['material_name'],
                'type'       => 'material',
                'unit'       => $m['unit'] ?? '',
                'weight_kg'  => 0,
                'unit_price' => im_get_material_selling_price($id),
                'warehouse'  => 'Kho NVL',
            ]
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }
    $p = im_get_product($id);
    if (!$p) {
        echo json_encode(['success' => false, 'message' => 'Product not found']);
        exit;
    }
    echo json_encode([
        'success' => true,
        'data' => [
            'id'         => (int) $p['id'],
            'name'       => $p['product_name'],
            'type'       => 'product',
            'unit'       => $p['unit'] ?? '',
            'weight_kg'  => im_get_product_weight($id),
            'unit_price' => im_get_product_selling_price($id),
            'warehouse'  => 'Kho TP',
        ]
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

/** Trả chi tiết 1 batch sales_issue để render lại form Sửa. */
function get_sales_issue_batchAction()
{
    header('Content-Type: application/json');
    $gk  = trim((string) ($_POST['group_key']   ?? ''));
    $cid = (int) ($_POST['customer_id'] ?? 0);
    $batch = im_get_sales_issue_batch($gk, $cid);
    if (!$batch) {
        echo json_encode(['success' => false, 'message' => 'Không tìm thấy nhóm.']);
        exit;
    }
    echo json_encode(['success' => true, 'data' => $batch], JSON_UNESCAPED_UNICODE);
    exit;
}

/**
 * Cập nhật 1 batch sales_issue đang ở chế độ Sửa.
 * Input giống record_sales_issue + group_key + items có thêm export_id (>0 = update,
 * 0 = insert dòng mới trong batch).
 */
function edit_sales_issue_batchAction()
{
    header('Content-Type: application/json');
    permission_require_can_edit('warehouse_outbound', 'warehouse_outbound', 'sales_delivery_note');
    $gk          = trim((string) ($_POST['group_key']   ?? ''));
    $cid         = (int) ($_POST['customer_id'] ?? 0);
    $items_raw   = $_POST['items'] ?? '[]';
    $items       = json_decode($items_raw, true) ?: [];
    $created_at  = trim((string) ($_POST['created_at'] ?? ''));
    $total_qty   = (float) ($_POST['total_quantity'] ?? 0);
    $weight      = (float) ($_POST['weight']         ?? 0);
    $goods_value = (float) ($_POST['goods_value']    ?? 0);

    if ($gk === '' || $cid <= 0) {
        echo json_encode(['success' => false, 'message' => 'Thiếu group_key/customer_id.']);
        exit;
    }
    $je = [
        'debit'  => $_POST['je_debit']  ?? null,
        'credit' => $_POST['je_credit'] ?? null,
        'amount' => $_POST['je_amount'] ?? null,
    ];
    $r = im_edit_sales_issue_batch($gk, $cid, $items, $total_qty, $weight, $goods_value, $created_at, $je);
    echo json_encode([
        'success'        => !empty($r['ok']),
        'invoice_id'     => (int) ($r['invoice_id'] ?? 0),
        'shortages'      => $r['shortages'] ?? [],
        'shortage_batch' => !empty($r['shortage_batch']),
        'history'        => im_get_recent_sales_issue_batches(100),
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

/** Xóa 1 batch sales_issue theo group_key + customer_id. */
function delete_sales_issue_batchAction()
{
    header('Content-Type: application/json');
    permission_require_can_edit('warehouse_outbound', 'warehouse_outbound', 'sales_delivery_note');
    $gk  = trim((string) ($_POST['group_key']   ?? ''));
    $cid = (int) ($_POST['customer_id'] ?? 0);
    if ($gk === '' || $cid <= 0) {
        echo json_encode(['success' => false, 'message' => 'Thiếu group_key/customer_id.']);
        exit;
    }
    $removed = im_delete_sales_issue_batch($gk, $cid);
    echo json_encode([
        'success' => true,
        'removed' => $removed,
        'history' => im_get_recent_sales_issue_batches(100),
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

/**
 * Cập nhật unit cho 1 hàng hóa (gọi khi user blur khỏi ô .cell-unit input).
 * Input: id, type ('product'|'material'), unit (string).
 */
function update_item_unitAction()
{
    header('Content-Type: application/json');
    permission_require_can_edit('warehouse_outbound', 'warehouse_outbound', 'sales_delivery_note');
    $id   = (int) ($_POST['id'] ?? 0);
    $type = trim((string) ($_POST['type'] ?? 'product'));
    $unit = trim((string) ($_POST['unit'] ?? ''));
    if ($id <= 0) {
        echo json_encode(['success' => false, 'message' => 'Thiếu id.']);
        exit;
    }
    $ok = im_update_item_unit($id, $type, $unit);
    echo json_encode(['success' => (bool) $ok, 'data' => ['id' => $id, 'type' => $type, 'unit' => $unit]]);
    exit;
}
