<?php
require_once __DIR__ . '/../../../libraries/warehouse_receipt_invoices.php';
require_once __DIR__ . '/../../accounting_factory/models/accounting_factoryModel.php';
require_once __DIR__ . '/../../payroll/models/payrollModel.php';
require_once __DIR__ . '/../../order_management/models/order_managementModel.php';
require_once __DIR__ . '/../../production_formula/models/production_formulaModel.php';

require_once __DIR__ . '/../../../libraries/auto_report.php';

function construct()
{
    load_model('report');
}

function finished_goods_inventoryAction()
{
    rp_ensure_inventory_views_group();
    $user = permission_current_user();
    $uid  = $user ? (int) $user['id'] : 0;
    $product_groups = rp_fgi_get_products_grouped_by_category($uid);
    load_view('finished_goods_inventory', [
        'product_groups' => $product_groups,
        'is_admin'       => permission_is_admin($user),
    ]);
}

/** AJAX: kéo-giữ chuột đổi vị trí 2 sản phẩm trên lưới — lưu thứ tự CÁ NHÂN cho
 * riêng user hiện tại. POST ordered_ids (JSON mảng product_id, đúng thứ tự mới
 * của TOÀN BỘ danh mục chứa 2 sản phẩm vừa đổi chỗ). */
function fgi_swap_display_orderAction()
{
    header('Content-Type: application/json; charset=utf-8');
    $user = permission_current_user();
    $uid  = $user ? (int) $user['id'] : 0;
    if ($uid <= 0) {
        echo json_encode(['ok' => false, 'message' => 'Chưa đăng nhập.'], JSON_UNESCAPED_UNICODE);
        exit;
    }
    $ids = $_POST['ordered_ids'] ?? '';
    if (is_string($ids)) {
        $decoded = json_decode($ids, true);
        $ids     = is_array($decoded) ? $decoded : [];
    }
    if (!is_array($ids)) $ids = [];
    echo json_encode(['ok' => rp_fgi_save_personal_display_order($uid, $ids)], JSON_UNESCAPED_UNICODE);
    exit;
}

/** AJAX: danh sách toàn bộ sản phẩm + tồn kho hiện tại (tab "Điều chỉnh tồn kho" — chỉ admin). */
function fgi_stock_adjust_listAction()
{
    header('Content-Type: application/json; charset=utf-8');
    if (!permission_is_admin()) {
        echo json_encode(['ok' => false, 'message' => 'Chỉ admin mới được điều chỉnh tồn kho.'], JSON_UNESCAPED_UNICODE);
        exit;
    }
    echo json_encode(['ok' => true, 'data' => rp_fgi_all_products_with_stock()], JSON_UNESCAPED_UNICODE);
    exit;
}

/** AJAX: lưu tồn kho đã điều chỉnh (chỉ admin). POST quantities (JSON map product_id=>quantity). */
function fgi_save_stock_adjustmentAction()
{
    header('Content-Type: application/json; charset=utf-8');
    if (!permission_is_admin()) {
        echo json_encode(['ok' => false, 'message' => 'Chỉ admin mới được điều chỉnh tồn kho.'], JSON_UNESCAPED_UNICODE);
        exit;
    }
    $quantities = $_POST['quantities'] ?? '';
    if (is_string($quantities)) {
        $decoded    = json_decode($quantities, true);
        $quantities = is_array($decoded) ? $decoded : [];
    }
    if (!is_array($quantities)) $quantities = [];
    echo json_encode(['ok' => rp_fgi_save_stock_adjustment($quantities)], JSON_UNESCAPED_UNICODE);
    exit;
}

/** AJAX: danh sách toàn bộ sản phẩm + thứ tự hiển thị (modal cài đặt). */
function fgi_display_order_listAction()
{
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['ok' => true, 'data' => rp_fgi_all_products_for_order()], JSON_UNESCAPED_UNICODE);
    exit;
}

/** AJAX: lưu thứ tự hiển thị sản phẩm. POST orders (JSON map product_id=>order). */
function fgi_save_display_orderAction()
{
    header('Content-Type: application/json; charset=utf-8');
    $orders = $_POST['orders'] ?? '';
    if (is_string($orders)) {
        $decoded = json_decode($orders, true);
        $orders  = is_array($decoded) ? $decoded : [];
    }
    if (!is_array($orders)) $orders = [];
    echo json_encode(['ok' => rp_fgi_save_display_order($orders)], JSON_UNESCAPED_UNICODE);
    exit;
}

/** AJAX: nhóm sản phẩm "đã lâu chưa bán" theo mốc tháng đã chọn. */
function fgi_long_unsoldAction()
{
    header('Content-Type: application/json; charset=utf-8');
    $months = (int) ($_POST['months'] ?? 3);
    echo json_encode(['ok' => true, 'months' => $months, 'data' => rp_fgi_long_unsold($months)], JSON_UNESCAPED_UNICODE);
    exit;
}

/** AJAX: gắn/bỏ thẻ "Ngưng bán" cho 1 sản phẩm. */
function fgi_set_discontinuedAction()
{
    header('Content-Type: application/json; charset=utf-8');
    $pid = (int) ($_POST['product_id'] ?? 0);
    $on  = (int) ($_POST['on'] ?? 1) === 1;
    echo json_encode(['ok' => rp_fgi_set_discontinued($pid, $on)], JSON_UNESCAPED_UNICODE);
    exit;
}

/** AJAX: danh sách sản phẩm đang "Ngưng bán". */
function fgi_discontinued_listAction()
{
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['ok' => true, 'data' => rp_fgi_discontinued_list()], JSON_UNESCAPED_UNICODE);
    exit;
}

/** AJAX: xóa vĩnh viễn 1 sản phẩm (giữ lại nhập/xuất). */
function fgi_delete_productAction()
{
    header('Content-Type: application/json; charset=utf-8');
    $pid = (int) ($_POST['product_id'] ?? 0);
    echo json_encode(['ok' => rp_fgi_delete_product($pid)], JSON_UNESCAPED_UNICODE);
    exit;
}

/** AJAX: phân tích 1 sản phẩm (tồn, sản xuất gần nhất, xuất 1/3/6 tháng). */
function fgi_product_analysisAction()
{
    header('Content-Type: application/json; charset=utf-8');
    $pid = (int) ($_POST['product_id'] ?? $_GET['product_id'] ?? 0);
    $data = rp_fgi_product_analysis($pid);
    echo json_encode($data ? ['ok' => true, 'data' => $data] : ['ok' => false, 'message' => 'Không tìm thấy sản phẩm.'], JSON_UNESCAPED_UNICODE);
    exit;
}

/** AJAX: sửa "Tên thường gọi" của 1 sản phẩm tại modal phân tích (hover hiện bút chì). */
function fgi_save_product_common_nameAction()
{
    header('Content-Type: application/json; charset=utf-8');
    $pid   = (int) ($_POST['product_id'] ?? 0);
    $value = (string) ($_POST['value'] ?? '');
    if ($pid <= 0) {
        echo json_encode(['ok' => false, 'message' => 'Thiếu sản phẩm.'], JSON_UNESCAPED_UNICODE);
        exit;
    }
    echo json_encode(['ok' => rp_fgi_save_product_common_name($pid, $value), 'value' => trim($value)], JSON_UNESCAPED_UNICODE);
    exit;
}

function material_inventoryAction()
{
    rp_ensure_inventory_views_group();
    load_view('material_inventory', [
        'materials' => rp_mi_get_materials_table(),
        'is_admin'  => permission_is_admin(),
    ]);
}

/** AJAX: admin sửa trực tiếp tồn kho NVL từ bảng (cột "Tồn kho"). */
function mi_update_quantityAction()
{
    header('Content-Type: application/json; charset=utf-8');
    permission_require_admin(true);
    $mid = (int) ($_POST['material_id'] ?? 0);
    if ($mid <= 0 || !isset($_POST['quantity'])) {
        echo json_encode(['ok' => false, 'message' => 'Thiếu dữ liệu.']);
        exit;
    }
    echo json_encode(['ok' => rp_mi_update_quantity($mid, (float) $_POST['quantity'])], JSON_UNESCAPED_UNICODE);
    exit;
}

/** AJAX: phân tích 1 NVL (tồn, mua gần nhất, dùng 1/3/6 tháng). */
function mi_material_analysisAction()
{
    header('Content-Type: application/json; charset=utf-8');
    $mid = (int) ($_POST['material_id'] ?? $_GET['material_id'] ?? 0);
    $data = rp_mi_material_analysis($mid);
    echo json_encode($data ? ['ok' => true, 'data' => $data] : ['ok' => false, 'message' => 'Không tìm thấy NVL.'], JSON_UNESCAPED_UNICODE);
    exit;
}

/** AJAX: danh sách toàn bộ NVL + thứ tự hiển thị (modal cài đặt). */
function mi_display_order_listAction()
{
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['ok' => true, 'data' => rp_mi_all_materials_for_order()], JSON_UNESCAPED_UNICODE);
    exit;
}

/** AJAX: lưu thứ tự hiển thị NVL. POST orders (JSON map material_id=>order). */
function mi_save_display_orderAction()
{
    header('Content-Type: application/json; charset=utf-8');
    $orders = $_POST['orders'] ?? '';
    if (is_string($orders)) {
        $decoded = json_decode($orders, true);
        $orders  = is_array($decoded) ? $decoded : [];
    }
    if (!is_array($orders)) $orders = [];
    echo json_encode(['ok' => rp_mi_save_display_order($orders)], JSON_UNESCAPED_UNICODE);
    exit;
}

/** AJAX: NVL "đã lâu chưa dùng" theo mốc tháng đã chọn. */
function mi_long_unusedAction()
{
    header('Content-Type: application/json; charset=utf-8');
    $months = (int) ($_POST['months'] ?? 3);
    echo json_encode(['ok' => true, 'months' => $months, 'data' => rp_mi_long_unused($months)], JSON_UNESCAPED_UNICODE);
    exit;
}

/** AJAX: gắn/bỏ thẻ "Ngưng dùng" cho 1 NVL. */
function mi_set_discontinuedAction()
{
    header('Content-Type: application/json; charset=utf-8');
    $mid = (int) ($_POST['material_id'] ?? 0);
    $on  = (int) ($_POST['on'] ?? 1) === 1;
    echo json_encode(['ok' => rp_mi_set_discontinued($mid, $on)], JSON_UNESCAPED_UNICODE);
    exit;
}

/** AJAX: danh sách NVL đang "Ngưng dùng". */
function mi_discontinued_listAction()
{
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['ok' => true, 'data' => rp_mi_discontinued_list()], JSON_UNESCAPED_UNICODE);
    exit;
}

/** AJAX: xóa vĩnh viễn 1 NVL (giữ lại nhập/xuất). */
function mi_delete_materialAction()
{
    header('Content-Type: application/json; charset=utf-8');
    $mid = (int) ($_POST['material_id'] ?? 0);
    echo json_encode(['ok' => rp_mi_delete_material($mid)], JSON_UNESCAPED_UNICODE);
    exit;
}

/* ============================================================
 *  TAB: stock_at_point (Tồn tại một thời điểm)
 * ============================================================ */

function stock_at_pointAction()
{
    rp_ensure_inventory_views_group();
    load_view('stock_at_point', []);
}

/** AJAX: trả tồn thành phẩm + NVL tại 1 thời điểm (đầu/cuối ngày). */
function stock_at_point_dataAction()
{
    header('Content-Type: application/json');
    $date = rp_sap_sanitize_date($_POST['date'] ?? '');
    $mode = (($_POST['mode'] ?? 'end') === 'start') ? 'start' : 'end';

    $t = strtotime($date);
    $dmy = $t ? date('d/m/Y', $t) : $date;
    $label = ($mode === 'start' ? 'Đầu ngày ' : 'Cuối ngày ') . $dmy;

    echo json_encode([
        'success'   => true,
        'date'      => $date,
        'mode'      => $mode,
        'label'     => $label,
        'products'  => rp_sap_products_at($date, $mode),
        'materials' => rp_sap_materials_at($date, $mode),
    ], JSON_UNESCAPED_UNICODE);
    exit;
}
/* ============================================================
 *  DASHBOARD: daily_dashboard (BC SẢN XUẤT HẰNG NGÀY) — v2, 6 khối cố định
 * ============================================================ */

function daily_dashboardAction()
{
    rp_dd_ensure_view_registered();
    ar_ensure_view_registered(); // để menu "Gửi báo cáo tự động" tự hiện khi admin mở báo cáo ngày
    $data = rp_dd_dashboard_bootstrap();
    load_view('daily_dashboard', [
        'data' => $data,
    ]);
}

/** AJAX: phân trang batch + lọc nhà cung cấp khối "Nhập kho". */
function daily_dashboard_importsAction()
{
    header('Content-Type: application/json; charset=utf-8');
    $page = (int) ($_POST['page'] ?? 1);
    $sid  = (int) ($_POST['supplier_id'] ?? 0);
    echo json_encode([
        'success' => true,
        'summary' => rp_dd_imports_month_summary($sid ?: null),
        'recent'  => rp_dd_imports_recent($page, 3, $sid ?: null),
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

/** AJAX: modal "7 hóa đơn" (field=count) / "CPMH" (field=cost) nhóm theo NCC. */
function daily_dashboard_imports_group_modalAction()
{
    header('Content-Type: application/json; charset=utf-8');
    $field = (string) ($_POST['field'] ?? 'count');
    echo json_encode(['success' => true, 'data' => rp_dd_imports_group_by_supplier($field)], JSON_UNESCAPED_UNICODE);
    exit;
}

/** AJAX: phân trang batch + lọc khách hàng khối "Xuất kho". */
function daily_dashboard_exportsAction()
{
    header('Content-Type: application/json; charset=utf-8');
    $page = (int) ($_POST['page'] ?? 1);
    $cid  = (int) ($_POST['customer_id'] ?? 0);
    echo json_encode([
        'success'    => true,
        'summary'    => rp_dd_exports_month_total($cid ?: null),
        'series'     => rp_dd_exports_series_7m($cid ?: null),
        'series_qty' => rp_dd_exports_qty_series_7m($cid ?: null),
        'recent'     => rp_dd_exports_recent($page, 3, $cid ?: null),
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

/** AJAX: modal drill-down 1 sản phẩm sản xuất hôm nay (tồn kho / xuất 1-3-6 tháng / biên độ). */
function daily_dashboard_product_detailAction()
{
    header('Content-Type: application/json; charset=utf-8');
    $pid  = (int) ($_POST['product_id'] ?? 0);
    $date = (string) ($_POST['date'] ?? '');
    $data = rp_dd_product_detail($pid, $date ?: null);
    echo json_encode($data
        ? ['success' => true, 'data' => $data]
        : ['success' => false, 'message' => 'Không tìm thấy sản phẩm.'], JSON_UNESCAPED_UNICODE);
    exit;
}

/** AJAX: phân trang batch khối "Đặt hàng nguyên liệu". */
function daily_dashboard_material_ordersAction()
{
    header('Content-Type: application/json; charset=utf-8');
    $page = (int) ($_POST['page'] ?? 1);
    echo json_encode(['success' => true, 'data' => rp_dd_pending_material_orders($page, RP_DD_MATERIAL_ORDERS_PER_PAGE)], JSON_UNESCAPED_UNICODE);
    exit;
}

/* ---- Khối "Theo dõi tồn kho" (hàng dưới cùng) ---- */

/** AJAX: nạp lại toàn bộ 2 danh sách (sau khi ẩn / bật lại 1 mục). */
function daily_dashboard_stock_watchAction()
{
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['success' => true, 'data' => rp_dd_stock_watch()], JSON_UNESCAPED_UNICODE);
    exit;
}

/** AJAX: bấm 1 mục -> SP thì trả NVL trong công thức, NVL thì trả SP đang dùng nó (kèm tồn).
 *  POST kind ('product'|'material'), id. */
function daily_dashboard_sw_detailAction()
{
    header('Content-Type: application/json; charset=utf-8');
    $kind = (string) ($_POST['kind'] ?? '');
    $id   = (int) ($_POST['id'] ?? 0);
    $data = $kind === 'material' ? rp_dd_sw_material_detail($id) : rp_dd_sw_product_detail($id);
    echo json_encode($data
        ? ['success' => true, 'kind' => $kind, 'data' => $data]
        : ['success' => false, 'message' => 'Không tìm thấy dữ liệu.'], JSON_UNESCAPED_UNICODE);
    exit;
}

/** AJAX: ẩn / bật lại 1 mục khỏi khối. POST kind, id, hidden (1|0). */
function daily_dashboard_sw_hideAction()
{
    header('Content-Type: application/json; charset=utf-8');
    $kind   = (string) ($_POST['kind'] ?? '');
    $id     = (int) ($_POST['id'] ?? 0);
    $hidden = (string) ($_POST['hidden'] ?? '1') === '1';
    $ok = rp_dd_sw_set_hidden($kind, $id, $hidden);
    echo json_encode($ok
        ? ['success' => true, 'data' => rp_dd_stock_watch(), 'hidden_list' => rp_dd_sw_hidden_list()]
        : ['success' => false, 'message' => 'Không cập nhật được.'], JSON_UNESCAPED_UNICODE);
    exit;
}

/** AJAX: danh sách mục đang ẩn (modal bánh răng của khối). */
function daily_dashboard_sw_hidden_listAction()
{
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['success' => true, 'items' => rp_dd_sw_hidden_list()], JSON_UNESCAPED_UNICODE);
    exit;
}

/** AJAX: phân trang batch khối "Biến động giá nhập" (hàng dưới cùng). */
function daily_dashboard_price_changesAction()
{
    header('Content-Type: application/json; charset=utf-8');
    $page = (int) ($_POST['page'] ?? 1);
    echo json_encode(['success' => true, 'data' => rp_dd_price_changes($page, RP_DD_PRICE_CHANGES_PER_PAGE)], JSON_UNESCAPED_UNICODE);
    exit;
}

/** AJAX: phân trang batch khối "Quỹ". */
function daily_dashboard_fundAction()
{
    header('Content-Type: application/json; charset=utf-8');
    $page = (int) ($_POST['page'] ?? 1);
    echo json_encode(['success' => true, 'data' => rp_dd_fund_recent($page, 3)], JSON_UNESCAPED_UNICODE);
    exit;
}

/** AJAX: lưu "tồn đầu" của Quỹ. */
function daily_dashboard_save_fund_opening_balanceAction()
{
    header('Content-Type: application/json; charset=utf-8');
    $value = (float) ($_POST['value'] ?? 0);
    rp_dd_save_fund_opening_balance($value);
    echo json_encode(['success' => true, 'balance' => rp_dd_fund_balance()], JSON_UNESCAPED_UNICODE);
    exit;
}

/** AJAX: modal chi tiết NVL đã dùng SX hôm nay (click vào giá vốn sản xuất). */
function daily_dashboard_product_cost_breakdownAction()
{
    header('Content-Type: application/json; charset=utf-8');
    $pid  = (int) ($_POST['product_id'] ?? 0);
    $date = (string) ($_POST['date'] ?? '');
    $data = rp_dd_product_cost_breakdown($pid, $date ?: null);
    echo json_encode($data
        ? ['success' => true, 'data' => $data]
        : ['success' => false, 'message' => 'Không tìm thấy sản phẩm.'], JSON_UNESCAPED_UNICODE);
    exit;
}

/** AJAX: lưu cài đặt "Hiệu quả" (ngưỡng sản lượng tối đa/công + danh sách sản phẩm chủ lực). */
function daily_dashboard_save_settingsAction()
{
    header('Content-Type: application/json; charset=utf-8');
    $maxOutput = (float) ($_POST['max_output'] ?? 0);
    $raw = (string) ($_POST['items'] ?? '[]');
    $decoded = json_decode($raw, true);
    $items = is_array($decoded) ? $decoded : [];

    rp_dd_save_max_output_setting($maxOutput);
    rp_dd_save_key_products($items);

    echo json_encode(['success' => true, 'data' => rp_dd_output_block()], JSON_UNESCAPED_UNICODE);
    exit;
}

/** AJAX: lưu "Thiết lập giá trị tạm" (ưu tiên hơn dữ liệu tính từ DB cho từng tháng). */
function daily_dashboard_save_month_overridesAction()
{
    header('Content-Type: application/json; charset=utf-8');
    $raw = (string) ($_POST['items'] ?? '[]');
    $decoded = json_decode($raw, true);
    $items = is_array($decoded) ? $decoded : [];

    rp_dd_save_month_overrides($items);

    echo json_encode(['success' => true, 'data' => rp_dd_output_block()], JSON_UNESCAPED_UNICODE);
    exit;
}

/** AJAX: lưu "Thiết lập giá trị tạm" cho Xuất kho (metric='export', dùng chung bảng overrides). */
function daily_dashboard_save_export_month_overridesAction()
{
    header('Content-Type: application/json; charset=utf-8');
    $decoded = json_decode((string) ($_POST['items'] ?? '[]'), true);
    $items = is_array($decoded) ? $decoded : [];
    rp_dd_save_month_overrides($items, 'export');

    // Tab "Hiển thị theo số lượng" — cùng modal, lưu chung 1 lần nếu có gửi kèm.
    $decodedQty = json_decode((string) ($_POST['items_qty'] ?? '[]'), true);
    $itemsQty = is_array($decodedQty) ? $decodedQty : [];
    if ($itemsQty) rp_dd_save_month_overrides($itemsQty, 'export_qty');

    // Mốc bắt đầu + bước nhảy trục giá trị (cùng modal, lưu chung 1 lần).
    rp_dd_save_export_axis_setting($_POST['axis_min'] ?? 0, $_POST['axis_step'] ?? 0);

    $cid = (int) ($_POST['customer_id'] ?? 0);
    echo json_encode([
        'success'    => true,
        'summary'    => rp_dd_exports_month_total($cid ?: null),
        'series'     => rp_dd_exports_series_7m($cid ?: null),
        'series_qty' => rp_dd_exports_qty_series_7m($cid ?: null),
        'axis'       => rp_dd_get_export_axis_setting(),
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

/** AJAX: lưu chú thích logo trang dashboard này (sửa tại chỗ, chỉ áp dụng riêng trang này). */
function daily_dashboard_save_logo_captionAction()
{
    header('Content-Type: application/json; charset=utf-8');
    $value = (string) ($_POST['value'] ?? '');
    rp_dd_save_logo_caption($value);
    echo json_encode(['success' => true, 'value' => rp_dd_get_logo_caption()], JSON_UNESCAPED_UNICODE);
    exit;
}

/* ============================================================
 *  DASHBOARD: sidebar icon — modal danh sách ĐẦY ĐỦ (không giới hạn tháng hiện tại/30 ngày)
 *  + điều hướng ngày trên card "Sản xuất hôm nay".
 * ============================================================ */

/** AJAX: modal đầy đủ "Nhập kho" (sidebar + nút "..." trong khối Nhập kho). */
function daily_dashboard_imports_fullAction()
{
    header('Content-Type: application/json; charset=utf-8');
    $page = (int) ($_POST['page'] ?? 1);
    $sid  = (int) ($_POST['supplier_id'] ?? 0);
    $kw   = (string) ($_POST['keyword'] ?? '');
    echo json_encode(['success' => true, 'data' => rp_dd_imports_all($page, 10, $sid ?: null, $kw)], JSON_UNESCAPED_UNICODE);
    exit;
}

/** AJAX: modal đầy đủ "Xuất kho" (sidebar + nút "Sales Order" trong chart). */
function daily_dashboard_exports_fullAction()
{
    header('Content-Type: application/json; charset=utf-8');
    $page = (int) ($_POST['page'] ?? 1);
    $cid  = (int) ($_POST['customer_id'] ?? 0);
    $kw   = (string) ($_POST['keyword'] ?? '');
    echo json_encode(['success' => true, 'data' => rp_dd_exports_all($page, 10, $cid ?: null, $kw)], JSON_UNESCAPED_UNICODE);
    exit;
}

/** AJAX: modal đầy đủ "Sản lượng" — Ngày | Sản phẩm | Số lượng, tháng hiện tại, mới nhất trước. */
function daily_dashboard_output_fullAction()
{
    header('Content-Type: application/json; charset=utf-8');
    $page = (int) ($_POST['page'] ?? 1);
    $kw   = (string) ($_POST['keyword'] ?? '');
    echo json_encode(['success' => true, 'data' => rp_dd_output_rows_month($page, 10, $kw)], JSON_UNESCAPED_UNICODE);
    exit;
}

/** AJAX: dùng chung cho modal "Sản xuất" (đầy đủ cột) VÀ điều hướng ngày trên card "Sản xuất hôm nay". */
function daily_dashboard_production_fullAction()
{
    header('Content-Type: application/json; charset=utf-8');
    $date = (string) ($_POST['date'] ?? date('Y-m-d'));
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) $date = date('Y-m-d');
    echo json_encode([
        'success' => true,
        'label'   => rp_dd_production_day_label($date),
        'rows'    => rp_dd_production_day_detail($date),
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

/** AJAX: modal đầy đủ "Đặt hàng nguyên liệu" (mọi trạng thái, không chỉ đang chờ ≤30 ngày). */
function daily_dashboard_material_orders_fullAction()
{
    header('Content-Type: application/json; charset=utf-8');
    $page = (int) ($_POST['page'] ?? 1);
    echo json_encode(['success' => true, 'data' => rp_dd_material_orders_all($page, 10)], JSON_UNESCAPED_UNICODE);
    exit;
}

/** AJAX: modal đầy đủ "Chi nhánh đặt hàng" (kèm giải thích thiếu NVL cho từng SP thiếu tồn). */
function daily_dashboard_branch_fullAction()
{
    header('Content-Type: application/json; charset=utf-8');
    $page = (int) ($_POST['page'] ?? 1);
    echo json_encode(['success' => true, 'data' => rp_dd_branch_orders_all($page, 10)], JSON_UNESCAPED_UNICODE);
    exit;
}

/** AJAX: modal đầy đủ "Quỹ". */
function daily_dashboard_fund_fullAction()
{
    header('Content-Type: application/json; charset=utf-8');
    $page = (int) ($_POST['page'] ?? 1);
    $type = (string) ($_POST['type'] ?? '');
    echo json_encode(['success' => true, 'data' => rp_dd_fund_all($page, 10, $type ?: null)], JSON_UNESCAPED_UNICODE);
    exit;
}

/* ============================================================
 *  SỰ KIỆN NHÀ MÁY (factory_events) — báo cáo dạng lịch, chỉ đọc.
 * ============================================================ */

function factory_eventsAction()
{
    rp_fev_ensure_view_registered();
    load_view('factory_events');
}

/** AJAX: nhóm sự kiện theo ngày cho 1 khoảng thời gian + bộ lọc đã chọn. */
function fev_rangeAction()
{
    header('Content-Type: application/json; charset=utf-8');
    $from = (string) ($_POST['from'] ?? '');
    $to   = (string) ($_POST['to'] ?? '');
    $raw  = (string) ($_POST['filters'] ?? '');
    $decoded = json_decode($raw, true);
    $filters = is_array($decoded) ? $decoded : [];
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $from) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $to)) {
        echo json_encode(['ok' => false, 'message' => 'Khoảng thời gian không hợp lệ.']);
        exit;
    }
    echo json_encode(['ok' => true, 'data' => rp_fev_range($from, $to, $filters)], JSON_UNESCAPED_UNICODE);
    exit;
}

function fev_search_suppliersAction()
{
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['ok' => true, 'data' => rp_fev_search_suppliers($_POST['keyword'] ?? '')], JSON_UNESCAPED_UNICODE);
    exit;
}

function fev_search_materialsAction()
{
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['ok' => true, 'data' => rp_fev_search_materials($_POST['keyword'] ?? '')], JSON_UNESCAPED_UNICODE);
    exit;
}

function fev_search_customersAction()
{
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['ok' => true, 'data' => rp_fev_search_customers($_POST['keyword'] ?? '')], JSON_UNESCAPED_UNICODE);
    exit;
}

function fev_search_productsAction()
{
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['ok' => true, 'data' => rp_fev_search_products($_POST['keyword'] ?? '')], JSON_UNESCAPED_UNICODE);
    exit;
}

/** AJAX: gợi ý "Nhập kho - Hàng hóa" (NVL + sản phẩm thương mại gộp chung). */
function fev_search_goodsAction()
{
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['ok' => true, 'data' => rp_fev_search_goods($_POST['keyword'] ?? '')], JSON_UNESCAPED_UNICODE);
    exit;
}

/** AJAX: ảnh hóa đơn nhập kho của 1 NCC trong 1 ngày (dùng cho cả NK-NCC chính xác và NK-NVL suy luận). */
function fev_invoices_by_supplier_dateAction()
{
    header('Content-Type: application/json; charset=utf-8');
    $sid  = (int) ($_POST['supplier_id'] ?? 0);
    $date = (string) ($_POST['date'] ?? '');
    echo json_encode(['ok' => true, 'data' => rp_fev_invoice_images_by_supplier_date($sid, $date)], JSON_UNESCAPED_UNICODE);
    exit;
}

/** AJAX: ảnh đính kèm của 1 phiếu chi NCC. */
function fev_payment_attachmentsAction()
{
    header('Content-Type: application/json; charset=utf-8');
    $pid = (int) ($_POST['payment_id'] ?? 0);
    echo json_encode(['ok' => true, 'data' => rp_fev_payment_attachments($pid)], JSON_UNESCAPED_UNICODE);
    exit;
}

/** AJAX: ảnh hóa đơn bán hàng của 1 đơn (sales_orders). */
function fev_sales_invoicesAction()
{
    header('Content-Type: application/json; charset=utf-8');
    $oid = (int) ($_POST['sales_order_id'] ?? 0);
    echo json_encode(['ok' => true, 'data' => wri_list($oid, 'sales_invoice')], JSON_UNESCAPED_UNICODE);
    exit;
}

/** AJAX: ảnh hóa đơn xuất bán mới của 1 phiếu (sales_warehouse_export_invoices). */
function fev_sales_export_invoicesAction()
{
    header('Content-Type: application/json; charset=utf-8');
    $oid = (int) ($_POST['sales_order_id'] ?? 0);
    echo json_encode(['ok' => true, 'data' => wri_list($oid, 'sales_export_invoice')], JSON_UNESCAPED_UNICODE);
    exit;
}

/** AJAX: ảnh hóa đơn xuất bán của 1 khách hàng trong 1 ngày (suy luận — dùng cho Xuất kho-Thành phẩm). */
function fev_invoices_by_customer_dateAction()
{
    header('Content-Type: application/json; charset=utf-8');
    $cid  = (int) ($_POST['customer_id'] ?? 0);
    $date = (string) ($_POST['date'] ?? '');
    echo json_encode(['ok' => true, 'data' => rp_fev_invoice_images_by_customer_date($cid, $date)], JSON_UNESCAPED_UNICODE);
    exit;
}

/* =====================================================================
 *  GỬI BÁO CÁO TỰ ĐỘNG (auto_report) — nghiệp vụ ở libraries/auto_report.php
 * ===================================================================== */

/** HTML: trang quản trị danh sách lịch gửi tự động (chỉ admin, tự đăng ký menu nhóm BÁO CÁO). */
function auto_report_adminAction()
{
    ar_ensure_tables();
    ar_ensure_view_registered();
    $user = permission_require_admin();
    load_view('auto_report_admin', [
        'configs' => array_map('ar_decorate', ar_configs_all()),
        'users'   => ar_active_users(),
        'me'      => $user,
    ]);
}

/** HTML: trang tự phục vụ của người được uỷ quyền (xem/tạm ngưng lịch của mình). */
function auto_report_mineAction()
{
    ar_ensure_tables();
    $user = permission_current_user();
    if (!$user) { permission_redirect_login(); }
    load_view('auto_report_mine', [
        'configs' => array_map('ar_decorate', ar_configs_for_delegate((int) $user['id'])),
        'me'      => $user,
    ]);
}

/** JSON: danh sách lịch (admin). */
function auto_report_listAction()
{
    permission_require_admin(true);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['ok' => true, 'configs' => array_map('ar_decorate', ar_configs_all())], JSON_UNESCAPED_UNICODE);
    exit;
}

/** JSON: tạo/sửa lịch (admin). Đẩy chuông xác nhận cho người được uỷ quyền. */
function auto_report_saveAction()
{
    $admin = permission_require_admin(true);
    header('Content-Type: application/json; charset=utf-8');
    $res = ar_save($_POST, (int) $admin['id']);
    if (empty($res['ok'])) {
        echo json_encode(['ok' => false, 'message' => $res['error'] ?? 'Lỗi lưu.'], JSON_UNESCAPED_UNICODE);
        exit;
    }
    $cfg = ar_config($res['id']);
    if ($cfg && (!empty($res['is_new']) || !empty($res['delegate_changed']))) {
        notify_create(
            (int) $res['delegate_id'],
            'Bạn được uỷ quyền gửi báo cáo tự động',
            'Lịch "' . $cfg['title'] . '" lúc ' . substr((string) $cfg['send_time'], 0, 5) . '. Bấm "Nhận" để xác nhận.',
            'arcfg:' . $res['id'],
            'autoreport_delegation',
            (int) $admin['id']
        );
        if (!empty($res['delegate_changed']) && (int) $res['old_delegate_id'] > 0) {
            notify_create(
                (int) $res['old_delegate_id'],
                'Kết thúc uỷ quyền gửi báo cáo',
                'Bạn không còn được uỷ quyền cho lịch "' . $cfg['title'] . '".',
                'arcfg:' . $res['id'],
                'autoreport',
                (int) $admin['id']
            );
        }
    }
    echo json_encode(['ok' => true, 'id' => $res['id']], JSON_UNESCAPED_UNICODE);
    exit;
}

/** JSON: xoá mềm 1 lịch (admin). */
function auto_report_deleteAction()
{
    permission_require_admin(true);
    header('Content-Type: application/json; charset=utf-8');
    ar_soft_delete((int) ($_POST['id'] ?? 0));
    echo json_encode(['ok' => true]);
    exit;
}

/** JSON: dữ liệu ô chọn người nhận (admin) — users toàn hệ thống + nhóm của người được uỷ quyền. */
function auto_report_recipients_feedAction()
{
    permission_require_admin(true);
    header('Content-Type: application/json; charset=utf-8');
    $delegate = (int) ($_GET['delegate_user_id'] ?? $_POST['delegate_user_id'] ?? 0);
    echo json_encode([
        'ok'     => true,
        'users'  => ar_active_users(),
        'groups' => $delegate > 0 ? ar_groups_for_user($delegate) : [],
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

/** JSON: tạm ngưng / bật lại (admin HOẶC chính người được uỷ quyền → báo chuông admin). */
function auto_report_toggle_pauseAction()
{
    header('Content-Type: application/json; charset=utf-8');
    $user = permission_current_user();
    $uid  = $user ? (int) $user['id'] : 0;
    if ($uid <= 0) { echo json_encode(['ok' => false, 'message' => 'Chưa đăng nhập.'], JSON_UNESCAPED_UNICODE); exit; }
    $id     = (int) ($_POST['id'] ?? 0);
    $paused = (int) ($_POST['paused'] ?? 0) ? 1 : 0;
    $cfg    = ar_config($id);
    if (!$cfg) { echo json_encode(['ok' => false, 'message' => 'Không tìm thấy lịch.'], JSON_UNESCAPED_UNICODE); exit; }
    $isAdmin    = permission_is_admin($user);
    $isDelegate = ((int) $cfg['delegate_user_id'] === $uid);
    if (!$isAdmin && !$isDelegate) { echo json_encode(['ok' => false, 'message' => 'Không có quyền.'], JSON_UNESCAPED_UNICODE); exit; }
    ar_set_pause($id, $paused, $uid);
    if ($isDelegate && !$isAdmin && $paused) {
        notify_admins(
            'Người được uỷ quyền đã tạm ngưng gửi báo cáo',
            ar_user_name($uid) . ' đã tạm ngưng lịch "' . $cfg['title'] . '".',
            'arcfg:' . $id,
            'autoreport'
        );
    }
    echo json_encode(['ok' => true, 'paused' => $paused]);
    exit;
}

/** JSON: người được uỷ quyền Nhận/Từ chối (từ chuông). Báo admin kết quả. */
function auto_report_respondAction()
{
    header('Content-Type: application/json; charset=utf-8');
    $user = permission_current_user();
    $uid  = $user ? (int) $user['id'] : 0;
    if ($uid <= 0) { echo json_encode(['ok' => false, 'message' => 'Chưa đăng nhập.'], JSON_UNESCAPED_UNICODE); exit; }
    $cid    = (int) ($_POST['config_id'] ?? 0);
    $accept = (int) ($_POST['accept'] ?? 0) ? true : false;
    $cfg    = ar_config($cid);
    if (!$cfg || (int) $cfg['delegate_user_id'] !== $uid) {
        echo json_encode(['ok' => false, 'message' => 'Không có quyền.'], JSON_UNESCAPED_UNICODE);
        exit;
    }
    ar_respond($cid, $uid, $accept);
    notify_admins(
        $accept ? 'Đã nhận uỷ quyền gửi báo cáo' : 'Đã từ chối uỷ quyền gửi báo cáo',
        ar_user_name($uid) . ($accept ? ' đã nhận' : ' đã từ chối') . ' lịch "' . $cfg['title'] . '".',
        'arcfg:' . $cid,
        'autoreport'
    );
    echo json_encode([
        'ok'       => true,
        'accepted' => $accept,
        // Nhận xong → đưa người uỷ quyền tới trang quản lý (để sau này tự tạm ngưng/bật lại).
        'link'     => $accept ? '?mod=report&controllers=report&action=auto_report_mine' : '',
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

/** JSON: poll "đến giờ" cho user hiện tại + quét lịch bị lỡ toàn hệ thống (cơ hội). */
function auto_report_due_checkAction()
{
    header('Content-Type: application/json; charset=utf-8');
    $user = permission_current_user();
    $uid  = $user ? (int) $user['id'] : 0;
    if ($uid <= 0) { echo json_encode(['ok' => false]); exit; }
    ar_run_missed_sweep();
    echo json_encode(['ok' => true, 'due' => ar_due_for_user($uid)], JSON_UNESCAPED_UNICODE);
    exit;
}

/** JSON multipart: nhận ảnh báo cáo trình duyệt người uỷ quyền vừa chụp → gửi vào chat. */
function auto_report_receiveAction()
{
    header('Content-Type: application/json; charset=utf-8');
    $user = permission_current_user();
    $uid  = $user ? (int) $user['id'] : 0;
    if ($uid <= 0) { echo json_encode(['ok' => false, 'message' => 'Chưa đăng nhập.'], JSON_UNESCAPED_UNICODE); exit; }
    $cid = (int) ($_POST['config_id'] ?? 0);
    $cfg = ar_config($cid);
    if (!$cfg || (int) $cfg['delegate_user_id'] !== $uid
        || ($cfg['delegation_status'] ?? '') !== 'accepted' || (int) $cfg['is_paused'] === 1) {
        echo json_encode(['ok' => false, 'message' => 'Không có quyền gửi lịch này.'], JSON_UNESCAPED_UNICODE);
        exit;
    }
    if ((string) ($cfg['last_sent_date'] ?? '') === date('Y-m-d')) {
        echo json_encode(['ok' => true, 'already' => true]); // đã gửi hôm nay → client quay lại
        exit;
    }
    if (empty($_FILES['image']) || ($_FILES['image']['error'] ?? 1) !== UPLOAD_ERR_OK) {
        echo json_encode(['ok' => false, 'message' => 'Thiếu ảnh báo cáo.'], JSON_UNESCAPED_UNICODE);
        exit;
    }
    require_once __DIR__ . '/../../../libraries/chat.php';
    $file = chat_store_upload($_FILES['image']);
    if (empty($file['ok'])) {
        echo json_encode(['ok' => false, 'message' => 'Lưu ảnh thất bại: ' . ($file['reason'] ?? '')], JSON_UNESCAPED_UNICODE);
        exit;
    }
    $res = ar_send_to_recipients($cfg, $file, '');
    if (!empty($res['ok'])) {
        ar_mark_sent($cid);
        ar_log($cid, 'sent', [
            'delegate_user_id'  => $uid,
            'recipient_summary' => $res['recipient_summary'] ?? '',
            'message_ids'       => $res['message_ids'] ?? [],
            'attachment_file'   => $file['file_name'],
        ]);
        echo json_encode(['ok' => true, 'recipient_summary' => $res['recipient_summary'] ?? ''], JSON_UNESCAPED_UNICODE);
        exit;
    }
    // Thất bại gửi → log + báo admin + chặn quét-lỡ báo trùng.
    ar_log($cid, 'failed', ['delegate_user_id' => $uid, 'note' => $res['error'] ?? 'lỗi gửi']);
    ar_mark_missed_notified($cid);
    notify_admins(
        'Gửi báo cáo tự động thất bại',
        'Lịch "' . $cfg['title'] . '": ' . ($res['error'] ?? 'lỗi gửi'),
        'arcfg:' . $cid,
        'autoreport'
    );
    echo json_encode(['ok' => false, 'message' => $res['error'] ?? 'Lỗi gửi.'], JSON_UNESCAPED_UNICODE);
    exit;
}