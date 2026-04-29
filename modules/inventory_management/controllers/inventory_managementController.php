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

function construct()
{
    load_model('inventory_management');
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

    if (empty($items)) {
        echo json_encode(['success' => false, 'message' => 'Chưa có sản phẩm nào để ghi.']);
        exit;
    }

    $count = 0;
    foreach ($items as $it) {
        $pid    = (int) ($it['product_id'] ?? 0);
        $qty    = (float) ($it['quantity'] ?? 0);
        $interp = trim((string) ($it['interpretation'] ?? ''));
        if ($pid <= 0 || $qty <= 0) continue;
        if (im_record_import($pid, $qty, $interp, $ca, $type_import)) $count++;
    }

    echo json_encode([
        'success' => true,
        'count'   => $count,
        'history' => im_get_recent_batches(im_history_limit($type_import), $type_import),
    ]);
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
    echo json_encode(['data' => im_get_recent_batches(im_history_limit($scope), $scope)]);
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

    if (empty($items)) {
        echo json_encode(['success' => false, 'message' => 'Không có dữ liệu để cập nhật.']);
        exit;
    }

    $count = 0;
    foreach ($items as $it) {
        $iid    = (int) ($it['import_id'] ?? 0);
        $qty    = (float) ($it['quantity'] ?? 0);
        $interp = trim((string) ($it['interpretation'] ?? ''));
        if ($iid <= 0) continue;
        if (im_update_import_item($iid, $qty, $interp, $ca)) $count++;
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

    if ($group_key === '') {
        echo json_encode(['success' => false, 'message' => 'Thiếu group_key.']);
        exit;
    }

    $removed = im_delete_batch($group_key, $scope);
    echo json_encode([
        'success' => true,
        'removed' => $removed,
        'history' => im_get_recent_batches(im_history_limit($scope), $scope),
    ]);
    exit;
}

function product_buyAction()
{
    $plan_date   = date('d/m/Y');
    $type_import = 'fg_receipt_purchase';
    $history     = im_get_recent_batches(im_history_limit($type_import), $type_import);

    load_view('product_buy', [
        'items'       => [],
        'plan_date'   => $plan_date,
        'history'     => $history,
        'type_import' => $type_import,
    ]);
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

/** Tìm sản phẩm thương mại kèm NCC (page product_buy). */
function search_product_supplierAction()
{
    header('Content-Type: application/json');
    $keyword = $_POST['keyword'] ?? '';
    echo json_encode(['data' => im_search_products_with_supplier($keyword)]);
    exit;
}

/** Lấy 1 product thương mại kèm supplier cho product_buy. */
function get_product_supplierAction()
{
    header('Content-Type: application/json');
    $product_id = (int) ($_POST['product_id'] ?? 0);
    $p = im_get_product_with_supplier($product_id);
    if (!$p) {
        echo json_encode(['success' => false, 'message' => 'Product not found']);
        exit;
    }
    echo json_encode([
        'success' => true,
        'data' => [
            'product_id'    => (int) $p['id'],
            'product_name'  => $p['product_name'],
            'supplier_id'   => $p['supplier_id'] !== null ? (int) $p['supplier_id'] : null,
            'supplier_name' => $p['supplier_name'] ?: '',
            'current_stock' => im_get_current_stock((int) $p['id']),
            'plan_date'     => date('d/m/Y'),
        ]
    ]);
    exit;
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
        ]
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

/**
 * Ghi 1 phiếu xuất kho bán hàng.
 * Input: customer_id, created_at ('Y-m-d H:i:s'),
 *        items = [{product_id, quantity, unit_price, total_amount, interpretation}, ...]
 * Output: { success, recorded: [pid...], shortages: [{product_id, available, requested}] }
 */
function record_sales_issueAction()
{
    header('Content-Type: application/json');

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

    $recorded  = [];
    $shortages = [];
    foreach ($items as $it) {
        $pid    = (int) ($it['product_id'] ?? 0);
        $qty    = (float) ($it['quantity'] ?? 0);
        $price  = (float) ($it['unit_price'] ?? 0);
        $total  = (float) ($it['total_amount'] ?? 0);
        $interp = trim((string) ($it['interpretation'] ?? ''));
        if ($pid <= 0 || $qty <= 0) continue;

        $r = im_record_sales_issue($pid, $cid, $qty, $price, $total, $interp, $ca);
        if (!empty($r['ok'])) {
            $recorded[] = $pid;
        } elseif (($r['reason'] ?? '') === 'shortage') {
            $shortages[] = [
                'product_id' => $pid,
                'available'  => (float) $r['available'],
                'requested'  => (float) $r['requested'],
            ];
        }
    }

    echo json_encode([
        'success'   => true,
        'recorded'  => $recorded,
        'shortages' => $shortages,
        'history'   => im_get_recent_sales_issue_batches(100),
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

    $count = 0;
    foreach ($items as $it) {
        $pid    = (int) ($it['product_id'] ?? 0);
        $qty    = (float) ($it['quantity'] ?? 0);
        $reason = trim((string) ($it['reason'] ?? ''));
        $interp = trim((string) ($it['interpretation'] ?? ''));
        if ($pid <= 0 || $qty <= 0) continue;
        $r = im_record_sales_return($pid, $cid, $qty, $reason, $interp, $ca);
        if ($r) $count++;
    }

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
    $items_raw  = $_POST['items'] ?? '[]';
    $items      = json_decode($items_raw, true) ?: [];
    $cid        = (int) ($_POST['customer_id'] ?? 0);
    $created_at = trim((string) ($_POST['created_at'] ?? ''));
    $ca         = $created_at !== '' ? $created_at : null;

    if (empty($items)) {
        echo json_encode(['success' => false, 'message' => 'Không có dữ liệu để cập nhật.']);
        exit;
    }
    $count = 0;
    foreach ($items as $it) {
        $sid = (int) ($it['sales_return_id'] ?? 0);
        $iid = (int) ($it['import_id'] ?? 0);
        $qty = (float) ($it['quantity'] ?? 0);
        $rs  = trim((string) ($it['reason'] ?? ''));
        $ip  = trim((string) ($it['interpretation'] ?? ''));

        if ($sid > 0) {
            if (im_update_sales_return_item($sid, $iid, $qty, $rs, $ip)) $count++;
        } else {
            $pid = (int) ($it['product_id'] ?? 0);
            if ($pid <= 0 || $cid <= 0 || $qty <= 0) continue;
            if (im_record_sales_return($pid, $cid, $qty, $rs, $ip, $ca)) $count++;
        }
    }
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
    $sid = (int) ($_POST['sales_return_id'] ?? 0);
    $iid = (int) ($_POST['import_id'] ?? 0);
    if ($sid <= 0) {
        echo json_encode(['success' => false, 'message' => 'Thiếu sales_return_id.']);
        exit;
    }
    $ok = im_delete_sales_return_item($sid, $iid);
    echo json_encode([
        'success' => (bool) $ok,
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
    $items_raw = $_POST['items'] ?? '[]';
    $items     = json_decode($items_raw, true) ?: [];
    if (empty($items)) {
        echo json_encode(['success' => false, 'message' => 'Chưa chọn cách thức xử lý.']);
        exit;
    }
    $count = 0;
    foreach ($items as $it) {
        $sid    = (int) ($it['sales_return_id'] ?? 0);
        $method = trim((string) ($it['method'] ?? ''));
        if ($sid <= 0 || $method === '') continue;
        if (im_handle_sales_return_item($sid, $method)) $count++;
    }
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

    $history = im_get_recent_batches(im_history_limit($type_import), $type_import);

    load_view('investment_products', [
        'items'       => $items,
        'plan_date'   => $plan_date,
        'history'     => $history,
        'type_import' => $type_import,
    ]);
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
 * Cập nhật product_materials.quantity_required cho 1 cặp (product_id, material_id).
 * Input: product_id, material_id, quantity_required
 */
function update_material_qtyAction()
{
    header('Content-Type: application/json');
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

    $si_id = im_record_investment($items, $cost_price, $goods_value, $ca);

    echo json_encode([
        'success'          => $si_id > 0,
        'stock_imports_id' => $si_id,
        'history'          => im_get_recent_batches(im_history_limit('investment_production'), 'investment_production'),
    ]);
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
    $group_key   = trim((string) ($_POST['group_key'] ?? ''));
    $items_raw   = $_POST['items'] ?? '[]';
    $items       = json_decode($items_raw, true) ?: [];
    $cost_price  = (float) ($_POST['cost_price']  ?? 0);
    $goods_value = (float) ($_POST['goods_value'] ?? 0);

    if ($group_key === '') {
        echo json_encode(['success' => false, 'message' => 'Thiếu group_key.']);
        exit;
    }
    $ok = im_update_investment_batch($group_key, $items, $cost_price, $goods_value);
    echo json_encode([
        'success' => (bool) $ok,
        'history' => im_get_recent_batches(im_history_limit('investment_production'), 'investment_production'),
    ]);
    exit;
}

/** Xóa 1 batch investment theo group_key (đồng bộ 4 bảng). */
function delete_investment_batchAction()
{
    header('Content-Type: application/json');
    $group_key = trim((string) ($_POST['group_key'] ?? ''));
    if ($group_key === '') {
        echo json_encode(['success' => false, 'message' => 'Thiếu group_key.']);
        exit;
    }
    $removed = im_delete_investment_batch($group_key);
    echo json_encode([
        'success' => true,
        'removed' => $removed,
        'history' => im_get_recent_batches(im_history_limit('investment_production'), 'investment_production'),
    ]);
    exit;
}