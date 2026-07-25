<?php
// Hint cho IDE (PHP Intelephense) resolve load_* và pf_*.
if (!function_exists('__pf_intelephense_hint_stub')) {
    function __pf_intelephense_hint_stub()
    {
        require_once __DIR__ . '/../../../core/base.php';
        require_once __DIR__ . '/../models/production_formulaModel.php';
    }
}

function construct()
{
    load_model('production_formula');
}

/** Trả JSON sạch + bắt mọi Throwable (tránh client SyntaxError "<"). */
function pf_json($payload)
{
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($payload, JSON_UNESCAPED_UNICODE);
    exit;
}

/* ============================================================
 *  Page
 * ============================================================ */

function production_formulaAction()
{
    pf_ensure_view_registered();
    load_view('production_formula', []);
}

/* ============================================================
 *  AJAX — Search sản phẩm + nạp công thức
 * ============================================================ */

function search_productsAction()
{
    $kw = $_POST['keyword'] ?? '';
    pf_json(['data' => pf_search_products($kw)]);
}

function get_recipeAction()
{
    $pid = (int) ($_POST['product_id'] ?? 0);
    $product = pf_get_product($pid);
    if (!$product) {
        pf_json(['success' => false, 'message' => 'Không tìm thấy sản phẩm.']);
    }
    pf_json([
        'success'  => true,
        'product'  => $product,
        'recipe'   => pf_get_recipe($pid),
        'note'     => pf_get_recipe_note($pid),
        'batches'  => pf_list_batch_recipes($pid),
    ]);
}

/* ============================================================
 *  AJAX — Sửa số lượng / Sắp xếp / Ghi chú (công thức 1 đơn vị)
 * ============================================================ */

function update_quantityAction()
{
    permission_require_can_edit('production_formula', 'production_formula', 'production_formula');
    $pm_id = (int) ($_POST['pm_id'] ?? 0);
    $qty   = (float) ($_POST['quantity'] ?? 0);
    if ($pm_id <= 0) {
        pf_json(['success' => false, 'message' => 'Thiếu pm_id.']);
    }
    pf_json(['success' => pf_update_quantity($pm_id, $qty)]);
}

function add_recipe_itemAction()
{
    permission_require_can_edit('production_formula', 'production_formula', 'production_formula');
    $pid = (int) ($_POST['product_id'] ?? 0);
    $mid = (int) ($_POST['material_id'] ?? 0);
    $qty = (float) ($_POST['quantity'] ?? 0);
    if ($pid <= 0 || $mid <= 0) {
        pf_json(['success' => false, 'message' => 'Thiếu product_id hoặc material_id.']);
    }
    $row = pf_add_recipe_item($pid, $mid, $qty);
    if (!$row) {
        pf_json(['success' => false, 'message' => 'Không thêm được thành phần.']);
    }
    pf_json(['success' => true, 'item' => $row]);
}

function delete_itemAction()
{
    permission_require_can_edit('production_formula', 'production_formula', 'production_formula');
    $pm_id = (int) ($_POST['pm_id'] ?? 0);
    if ($pm_id <= 0) {
        pf_json(['success' => false, 'message' => 'Thiếu pm_id.']);
    }
    pf_json(['success' => pf_delete_recipe_item($pm_id)]);
}

function reorderAction()
{
    permission_require_can_edit('production_formula', 'production_formula', 'production_formula');
    $pid     = (int) ($_POST['product_id'] ?? 0);
    $ids_raw = $_POST['order'] ?? '[]';
    $ids     = json_decode($ids_raw, true);
    if (!is_array($ids)) $ids = [];
    pf_json(['success' => pf_reorder($pid, $ids)]);
}

function save_noteAction()
{
    permission_require_can_edit('production_formula', 'production_formula', 'production_formula');
    $pid  = (int) ($_POST['product_id'] ?? 0);
    $note = (string) ($_POST['note'] ?? '');
    if ($pid <= 0) {
        pf_json(['success' => false, 'message' => 'Thiếu product_id.']);
    }
    pf_json(['success' => pf_save_recipe_note($pid, $note)]);
}

/* ============================================================
 *  AJAX — Công thức mẻ sản xuất
 * ============================================================ */

function save_batchAction()
{
    permission_require_can_edit('production_formula', 'production_formula', 'production_formula');
    $pid        = (int) ($_POST['product_id'] ?? 0);
    $multiplier = (float) ($_POST['multiplier'] ?? 1);
    $label      = (string) ($_POST['label'] ?? '');
    $note       = (string) ($_POST['note'] ?? '');
    $items_raw  = $_POST['items'] ?? '[]';
    $items      = json_decode($items_raw, true) ?: [];

    if ($pid <= 0 || empty($items)) {
        pf_json(['success' => false, 'message' => 'Thiếu dữ liệu công thức mẻ.']);
    }
    $batch_id = pf_save_batch_recipe($pid, $multiplier, $label, $note, $items);
    pf_json([
        'success'  => $batch_id > 0,
        'batch_id' => $batch_id,
        'batches'  => pf_list_batch_recipes($pid),
    ]);
}

function list_batchesAction()
{
    $pid = (int) ($_POST['product_id'] ?? 0);
    pf_json(['success' => true, 'data' => pf_list_batch_recipes($pid)]);
}

function get_batchAction()
{
    $bid   = (int) ($_POST['batch_id'] ?? 0);
    $batch = pf_get_batch_recipe($bid);
    if (!$batch) {
        pf_json(['success' => false, 'message' => 'Không tìm thấy công thức mẻ.']);
    }
    pf_json(['success' => true, 'data' => $batch]);
}

function delete_batchAction()
{
    permission_require_can_edit('production_formula', 'production_formula', 'production_formula');
    $bid = (int) ($_POST['batch_id'] ?? 0);
    $pid = (int) ($_POST['product_id'] ?? 0);
    if ($bid <= 0) {
        pf_json(['success' => false, 'message' => 'Thiếu batch_id.']);
    }
    pf_delete_batch_recipe($bid);
    pf_json(['success' => true, 'batches' => pf_list_batch_recipes($pid)]);
}

/** Nhân bản 1 công thức mẻ đã lưu thành 1 mẻ mới độc lập. */
function duplicate_batchAction()
{
    permission_require_can_edit('production_formula', 'production_formula', 'production_formula');
    $bid = (int) ($_POST['batch_id'] ?? 0);
    if ($bid <= 0) {
        pf_json(['success' => false, 'message' => 'Thiếu batch_id.']);
    }
    $new_id = pf_duplicate_batch_recipe($bid);
    if ($new_id <= 0) {
        pf_json(['success' => false, 'message' => 'Không nhân bản được công thức mẻ.']);
    }
    $newBatch = pf_get_batch_recipe($new_id);
    pf_json([
        'success'  => true,
        'batch_id' => $new_id,
        'data'     => $newBatch,
        'batches'  => pf_list_batch_recipes($newBatch['product_id']),
    ]);
}

/** Sửa/xóa ghi chú công thức mẻ đã lưu (tab "Công thức mẻ sản xuất"). */
function update_batch_noteAction()
{
    permission_require_can_edit('production_formula', 'production_formula', 'production_formula');
    $bid  = (int) ($_POST['batch_id'] ?? 0);
    $note = (string) ($_POST['note'] ?? '');
    if ($bid <= 0) {
        pf_json(['success' => false, 'message' => 'Thiếu batch_id.']);
    }
    pf_json(['success' => pf_update_batch_note($bid, $note)]);
}

/** Ghi đè "Tổng sản phẩm" của 1 công thức mẻ — trả lại giá trị đã lưu (null = đã xóa, dùng lại tự tính). */
function update_batch_output_qtyAction()
{
    permission_require_can_edit('production_formula', 'production_formula', 'production_formula');
    $bid = (int) ($_POST['batch_id'] ?? 0);
    $qty = $_POST['qty'] ?? '';
    if ($bid <= 0) {
        pf_json(['success' => false, 'message' => 'Thiếu batch_id.']);
    }
    $value = pf_update_batch_output_qty($bid, $qty);
    pf_json(['success' => true, 'output_qty' => $value]);
}

/** Sửa tên (label) công thức mẻ — trả luôn danh sách mẻ đã cập nhật. */
function update_batch_labelAction()
{
    permission_require_can_edit('production_formula', 'production_formula', 'production_formula');
    $bid = (int) ($_POST['batch_id'] ?? 0);
    $pid = (int) ($_POST['product_id'] ?? 0);
    $label = (string) ($_POST['label'] ?? '');
    if ($bid <= 0) {
        pf_json(['success' => false, 'message' => 'Thiếu batch_id.']);
    }
    pf_update_batch_label($bid, $label);
    pf_json(['success' => true, 'batches' => pf_list_batch_recipes($pid)]);
}

/* ============================================================
 *  AJAX — Thông tin tiêu đề phiếu Share (sửa 1 lần dùng nhiều lần)
 * ============================================================ */

function save_share_settingAction()
{
    permission_require_can_edit('production_formula', 'production_formula', 'production_formula');
    $key   = (string) ($_POST['key'] ?? '');
    $value = (string) ($_POST['value'] ?? '');
    pf_json(['success' => pf_save_share_setting($key, $value)]);
}

/* ============================================================
 *  AJAX — Sửa trực tiếp dòng công thức mẻ
 * ============================================================ */

function update_batch_itemAction()
{
    permission_require_can_edit('production_formula', 'production_formula', 'production_formula');
    $item_id = (int) ($_POST['item_id'] ?? 0);
    $qty     = (float) ($_POST['quantity'] ?? 0);
    if ($item_id <= 0) {
        pf_json(['success' => false, 'message' => 'Thiếu item_id.']);
    }
    pf_json(['success' => pf_update_batch_item_quantity($item_id, $qty)]);
}

/** "Quy đổi đơn vị" 1 dòng công thức mẻ (chỉ hiển thị/lưu riêng dòng đó, không đụng material_information). */
function update_batch_item_conversionAction()
{
    permission_require_can_edit('production_formula', 'production_formula', 'production_formula');
    $item_id = (int) ($_POST['item_id'] ?? 0);
    $convUnit  = (string) ($_POST['conv_unit'] ?? '');
    $convRatio = (float) ($_POST['conv_ratio'] ?? 0);
    if ($item_id <= 0) {
        pf_json(['success' => false, 'message' => 'Thiếu item_id.']);
    }
    pf_json(['success' => pf_update_batch_item_conversion($item_id, $convUnit, $convRatio)]);
}

function reorder_batch_itemsAction()
{
    permission_require_can_edit('production_formula', 'production_formula', 'production_formula');
    $bid     = (int) ($_POST['batch_id'] ?? 0);
    $ids_raw = $_POST['order'] ?? '[]';
    $ids     = json_decode($ids_raw, true);
    if (!is_array($ids)) $ids = [];
    pf_json(['success' => pf_reorder_batch_items($bid, $ids)]);
}

/** Thêm 1 dòng (NVL có sẵn hoặc tự do) vào 1 công thức mẻ ĐÃ LƯU. */
function add_batch_itemAction()
{
    permission_require_can_edit('production_formula', 'production_formula', 'production_formula');
    $bid    = (int) ($_POST['batch_id'] ?? 0);
    $mid    = (int) ($_POST['material_id'] ?? 0);
    $custom = (string) ($_POST['custom_name'] ?? '');
    $qty    = (float) ($_POST['quantity'] ?? 0);
    $unit   = (string) ($_POST['unit'] ?? '');
    if ($bid <= 0) {
        pf_json(['success' => false, 'message' => 'Thiếu batch_id.']);
    }
    $row = pf_add_batch_item($bid, $mid, $custom, $qty, $unit);
    if (!$row) {
        pf_json(['success' => false, 'message' => 'Thiếu tên nguyên liệu hoặc dữ liệu không hợp lệ.']);
    }
    pf_json(['success' => true, 'item' => $row]);
}

function delete_batch_itemAction()
{
    permission_require_can_edit('production_formula', 'production_formula', 'production_formula');
    $item_id = (int) ($_POST['item_id'] ?? 0);
    if ($item_id <= 0) {
        pf_json(['success' => false, 'message' => 'Thiếu item_id.']);
    }
    pf_json(['success' => pf_delete_batch_item($item_id)]);
}

/* ============================================================
 *  AJAX — Đổi NVL của 1 dòng công thức + search NVL
 * ============================================================ */

function search_materialsAction()
{
    $kw = $_POST['keyword'] ?? '';
    pf_json(['data' => pf_search_materials($kw)]);
}

function update_materialAction()
{
    permission_require_can_edit('production_formula', 'production_formula', 'production_formula');
    $pm_id = (int) ($_POST['pm_id'] ?? 0);
    $mid   = (int) ($_POST['material_id'] ?? 0);
    $row   = pf_update_recipe_material($pm_id, $mid);
    if (!$row) {
        pf_json(['success' => false, 'message' => 'Không cập nhật được nguyên liệu.']);
    }
    pf_json(['success' => true, 'material' => $row]);
}

/** Sửa tên thường gọi (common_material_name) khi user gõ trực tiếp ô tên. */
function rename_material_commonAction()
{
    permission_require_can_edit('production_formula', 'production_formula', 'production_formula');
    $mid  = (int) ($_POST['material_id'] ?? 0);
    $name = (string) ($_POST['common_material_name'] ?? '');
    if ($mid <= 0) {
        pf_json(['success' => false, 'message' => 'Thiếu material_id.']);
    }
    pf_json(['success' => pf_rename_material_common($mid, $name)]);
}

/* ============================================================
 *  AJAX — Xem nhanh tồn kho (NVL / Thành phẩm)
 * ============================================================ */

function material_stock_searchAction()
{
    pf_json(['data' => pf_material_stock_search($_POST['keyword'] ?? '')]);
}

function product_stock_searchAction()
{
    pf_json(['data' => pf_product_stock_search($_POST['keyword'] ?? '')]);
}

/** Thông tin nhanh 1 NVL (tên hệ thống/đơn vị/tồn/sản phẩm dùng/định mức 1-3-6 tháng)
 * — hiện khi click tên NVL ở tab "Công thức mẻ sản xuất". */
function get_material_infoAction()
{
    $mid  = (int) ($_POST['material_id'] ?? 0);
    $info = pf_get_material_info($mid);
    if (!$info) {
        pf_json(['success' => false, 'message' => 'Không tìm thấy nguyên liệu.']);
    }
    pf_json(['success' => true, 'data' => $info]);
}

/* ============================================================
 *  AJAX — Hình ảnh nguyên liệu (cột "Thao tác")
 * ============================================================ */

function list_material_imagesAction()
{
    $mid = (int) ($_POST['material_id'] ?? 0);
    pf_json(['success' => true, 'data' => pf_list_material_images($mid)]);
}

function upload_material_imageAction()
{
    permission_require_can_edit('production_formula', 'production_formula', 'production_formula');
    $mid = (int) ($_POST['material_id'] ?? 0);
    if ($mid <= 0 || empty($_FILES['files'])) {
        pf_json(['success' => false, 'message' => 'Thiếu material_id hoặc tệp ảnh.']);
    }
    $res = pf_save_material_images($mid, $_FILES['files']);
    pf_json([
        'success' => $res['ok'],
        'saved'   => $res['saved'],
        'errors'  => $res['errors'],
        'data'    => pf_list_material_images($mid),
    ]);
}

function delete_material_imageAction()
{
    permission_require_can_edit('production_formula', 'production_formula', 'production_formula');
    $iid = (int) ($_POST['image_id'] ?? 0);
    $mid = (int) ($_POST['material_id'] ?? 0);
    if ($iid <= 0) {
        pf_json(['success' => false, 'message' => 'Thiếu image_id.']);
    }
    pf_delete_material_image($iid);
    pf_json(['success' => true, 'data' => pf_list_material_images($mid)]);
}

/* ============================================================
 *  AJAX — Gallery ảnh toàn bộ NVL của công thức đang mở
 * ============================================================ */

function list_recipe_images_galleryAction()
{
    $pid = (int) ($_POST['product_id'] ?? 0);
    if ($pid <= 0) {
        pf_json(['success' => false, 'message' => 'Thiếu product_id.']);
    }
    pf_json(['success' => true, 'data' => pf_list_recipe_images_gallery($pid)]);
}

/* ============================================================
 *  AJAX — Check Database (dùng chung)
 * ============================================================ */

function check_databaseAction()
{
    require_once __DIR__ . '/../../../libraries/check_database.php';
    cdb_handle_ajax();
}
