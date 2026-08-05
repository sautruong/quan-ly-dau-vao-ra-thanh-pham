<?php
function construct()
{
    load_model('production_staff');
    ps_ensure_product_common_name_column();
}

function check_databaseAction()
{
    require_once __DIR__ . '/../../../libraries/check_database.php';
    cdb_handle_ajax();
}

function plan_for_staffAction()
{
    global $conn;

    $sql = "SELECT pp.id AS plan_id, pp.product_id, pp.quantity, pp.note AS plan_note,
                   pp.sample, pp.test, pp.check_inventory,
                   COALESCE(NULLIF(p.common_product_name, ''), p.product_name) AS product_name,
                   ppn.note AS pre_note,
                   fgi.quantity AS stock_qty,
                   COALESCE(NULLIF(mi_in.common_material_name, ''), mi_in.material_name) AS inner_packaging,
                   COALESCE(NULLIF(mi_out.common_material_name, ''), mi_out.material_name) AS outer_packaging,
                   pib.inner_packaging_id, pib.outer_packaging_id,
                   pib.inner_packaging_spec, pib.outer_packaging_spec
            FROM production_plans pp
            LEFT JOIN products p ON p.id = pp.product_id
            LEFT JOIN pre_production_notes ppn ON ppn.product_id = pp.product_id
            LEFT JOIN finished_goods_inventory fgi ON fgi.product_id = pp.product_id
            LEFT JOIN product_info_basic pib ON pib.product_id = pp.product_id
            LEFT JOIN material_information mi_in ON mi_in.id = pib.inner_packaging_id
            LEFT JOIN material_information mi_out ON mi_out.id = pib.outer_packaging_id
            ORDER BY pp.id ASC";
    $plans = db_fetch_array($sql);

    $sql_tasks = "SELECT id, description FROM additional_tasks ORDER BY id ASC";
    $tasks = db_fetch_array($sql_tasks);

    $data = [
        'plans' => $plans ?: [],
        'tasks' => $tasks ?: []
    ];
    load_view('plan_for_staff', $data);
}

/* =====================================================================
 *  KẾ HOẠCH SẢN XUẤT DÀI NGÀY (long_term_production_plan)
 * =====================================================================*/

function long_term_production_planAction()
{
    // Dọn các dòng dự phòng quá hạn (date-driven, không cron).
    ltp_backup_purge_expired();

    // 2 ngày dẫn đầu (hôm qua + hôm nay) + 10 ngày tới = 12 thẻ.
    $days = ltp_build_plan_window();
    $from = $days[0]['date'];
    $to   = $days[count($days) - 1]['date'];

    load_view('long_term_production_plan', [
        'days'          => $days,
        'items_by_date' => ltp_get_items_grouped($from, $to),
        'tasks_by_date' => ltp_get_tasks_grouped($from, $to),
        'completed'     => ltp_get_completed($from), // < ngày bắt đầu board
        'backup_items'  => ltp_get_backup_items(),
        'backup_ttl'    => ltp_backup_ttl_get(),
    ]);
}

/* ----- Kế hoạch sản xuất DỰ PHÒNG (AJAX) ----- */

/** Thêm 1 sản phẩm dự phòng. POST: product_id, quantity */
function ltp_backup_addAction()
{
    header('Content-Type: application/json; charset=utf-8');
    $id = ltp_backup_add($_POST['product_id'] ?? 0, $_POST['quantity'] ?? 0);
    if ($id <= 0) { echo json_encode(['ok' => false, 'message' => 'Dữ liệu không hợp lệ.']); exit; }
    $row = ltp_backup_row($id);
    echo json_encode([
        'ok'   => true,
        'item' => [
            'id'           => $id,
            'product_id'   => (int) ($row['product_id'] ?? 0),
            'product_name' => $row['product_name'] ?? '',
            'unit'         => $row['unit'] ?? '',
            'quantity'     => (float) ($row['quantity'] ?? 0),
            'created_at'   => $row['created_at'] ?? '',
        ],
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

/** Sửa 1 dòng dự phòng. POST: id, quantity, product_id? */
function ltp_backup_updateAction()
{
    header('Content-Type: application/json; charset=utf-8');
    $ok = ltp_backup_update($_POST['id'] ?? 0, $_POST['quantity'] ?? 0, $_POST['product_id'] ?? 0);
    echo json_encode(['ok' => $ok]);
    exit;
}

/** Sửa ghi chú 1 dòng dự phòng. POST: id, note */
function ltp_backup_update_noteAction()
{
    header('Content-Type: application/json; charset=utf-8');
    $ok = ltp_backup_update_note($_POST['id'] ?? 0, $_POST['note'] ?? '');
    echo json_encode(['ok' => $ok, 'note' => trim((string) ($_POST['note'] ?? ''))], JSON_UNESCAPED_UNICODE);
    exit;
}

/** Xóa 1 dòng dự phòng. POST: id */
function ltp_backup_deleteAction()
{
    header('Content-Type: application/json; charset=utf-8');
    $ok = ltp_backup_delete($_POST['id'] ?? 0);
    echo json_encode(['ok' => $ok]);
    exit;
}

/** Sắp xếp lại danh sách dự phòng. POST: ids (JSON mảng id theo thứ tự mới) */
function ltp_backup_reorderAction()
{
    header('Content-Type: application/json; charset=utf-8');
    $ids = json_decode((string) ($_POST['ids'] ?? '[]'), true);
    $ok  = ltp_backup_reorder(is_array($ids) ? $ids : []);
    echo json_encode(['ok' => $ok]);
    exit;
}

/** AJAX: sắp xếp lại "Việc khác" trong cùng 1 ngày (kéo-thả lên/xuống). POST: ids (JSON array) */
function ltp_task_reorderAction()
{
    header('Content-Type: application/json; charset=utf-8');
    $ids = json_decode((string) ($_POST['ids'] ?? '[]'), true);
    $ok  = ltp_task_reorder(is_array($ids) ? $ids : []);
    echo json_encode(['ok' => $ok]);
    exit;
}

/** Đưa 1 dòng dự phòng lên kế hoạch 1 ngày. POST: id, plan_date */
function ltp_backup_to_dayAction()
{
    header('Content-Type: application/json; charset=utf-8');
    $item = ltp_backup_to_day($_POST['id'] ?? 0, $_POST['plan_date'] ?? '');
    if ($item === null) { echo json_encode(['ok' => false, 'message' => 'Không thể chuyển.']); exit; }
    echo json_encode(['ok' => true, 'item' => $item], JSON_UNESCAPED_UNICODE);
    exit;
}

/** Đặt thời gian tự xóa dự phòng. POST: ttl (1w|2w|1m) */
function ltp_backup_set_ttlAction()
{
    header('Content-Type: application/json; charset=utf-8');
    $ok = ltp_backup_ttl_set($_POST['ttl'] ?? '');
    echo json_encode(['ok' => $ok]);
    exit;
}

/** AJAX: TASK 5 — xóa (reset) toàn bộ danh sách "Đã hoàn thành". */
function ltp_clear_completedAction()
{
    header('Content-Type: application/json; charset=utf-8');
    $days = ltp_build_plan_window();
    $from = $days[0]['date'];
    ltp_clear_completed($from);
    echo json_encode(['ok' => true]);
    exit;
}

/** AJAX: thêm sản phẩm vào 1 ngày. POST: plan_date, product_id, quantity */
function ltp_add_productAction()
{
    header('Content-Type: application/json; charset=utf-8');
    $id = ltp_add_product($_POST['plan_date'] ?? '', $_POST['product_id'] ?? 0, $_POST['quantity'] ?? 0);
    if ($id <= 0) { echo json_encode(['ok' => false, 'message' => 'Dữ liệu không hợp lệ.']); exit; }

    $pid = (int) ($_POST['product_id'] ?? 0);
    $p = db_fetch_row("SELECT product_name, unit FROM products WHERE id = $pid LIMIT 1");
    echo json_encode([
        'ok'   => true,
        'item' => [
            'id'           => $id,
            'product_id'   => $pid,
            'product_name' => $p['product_name'] ?? '',
            'unit'         => $p['unit'] ?? '',
            'quantity'     => (float) ($_POST['quantity'] ?? 0),
        ],
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

/** AJAX: thêm việc khác vào 1 ngày. POST: plan_date, content */
function ltp_add_taskAction()
{
    header('Content-Type: application/json; charset=utf-8');
    $id = ltp_add_task($_POST['plan_date'] ?? '', $_POST['content'] ?? '');
    if ($id <= 0) { echo json_encode(['ok' => false, 'message' => 'Nội dung không hợp lệ.']); exit; }
    echo json_encode(['ok' => true, 'task' => ['id' => $id, 'content' => trim((string) ($_POST['content'] ?? ''))]], JSON_UNESCAPED_UNICODE);
    exit;
}

/** AJAX: sửa số lượng 1 sản phẩm. POST: id, quantity */
function ltp_update_productAction()
{
    header('Content-Type: application/json; charset=utf-8');
    $ok = ltp_update_product($_POST['id'] ?? 0, $_POST['quantity'] ?? 0);
    echo json_encode(['ok' => $ok]);
    exit;
}

/** AJAX: sửa nội dung 1 việc khác. POST: id, content */
function ltp_update_taskAction()
{
    header('Content-Type: application/json; charset=utf-8');
    $ok = ltp_update_task($_POST['id'] ?? 0, $_POST['content'] ?? '');
    if (!$ok) { echo json_encode(['ok' => false, 'message' => 'Nội dung không hợp lệ.']); exit; }
    echo json_encode(['ok' => true, 'content' => trim((string) ($_POST['content'] ?? ''))], JSON_UNESCAPED_UNICODE);
    exit;
}

/** AJAX: xóa 1 sản phẩm/việc khác. POST: type (product|task), id */
function ltp_deleteAction()
{
    header('Content-Type: application/json; charset=utf-8');
    $ok = ltp_delete((string) ($_POST['type'] ?? ''), $_POST['id'] ?? 0);
    echo json_encode(['ok' => $ok]);
    exit;
}

/** AJAX: thêm 1 ngày vào cuối board (lưu lại, không giới hạn số thẻ). */
function ltp_add_dayAction()
{
    header('Content-Type: application/json; charset=utf-8');
    $date = ltp_add_day();
    echo json_encode([
        'ok'      => true,
        'date'    => $date,
        'weekday' => ltp_weekday_vi($date),
        'display' => date('j/n/Y', strtotime($date)),
        'sunday'  => ((int) date('N', strtotime($date)) === 7),
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

/** AJAX: chuyển 1 dòng sang ngày khác (kéo-thả). POST: type (product|task), id, plan_date */
function ltp_move_itemAction()
{
    header('Content-Type: application/json; charset=utf-8');
    $ok = ltp_move_item((string) ($_POST['type'] ?? ''), $_POST['id'] ?? 0, $_POST['plan_date'] ?? '');
    echo json_encode(['ok' => $ok]);
    exit;
}

/** AJAX: thêm 1 ngày vào ĐẦU board (lùi điểm bắt đầu 1 ngày). */
function ltp_add_day_firstAction()
{
    header('Content-Type: application/json; charset=utf-8');
    $date = ltp_add_day_start();
    echo json_encode(['ok' => true, 'date' => $date], JSON_UNESCAPED_UNICODE);
    exit;
}

/** AJAX: xóa ngày. Card đầu/cuối -> xóa hẳn (mode=removed); card giữa -> chỉ xóa nội dung (mode=cleared). POST: plan_date */
function ltp_remove_dayAction()
{
    header('Content-Type: application/json; charset=utf-8');
    $mode = ltp_remove_day($_POST['plan_date'] ?? '');
    if ($mode === false) { echo json_encode(['ok' => false]); exit; }
    echo json_encode(['ok' => true, 'mode' => $mode]);
    exit;
}

/** AJAX: tráo nội dung 2 ngày (kéo card). POST: date_a, date_b */
function ltp_swap_daysAction()
{
    header('Content-Type: application/json; charset=utf-8');
    $ok = ltp_swap_days($_POST['date_a'] ?? '', $_POST['date_b'] ?? '');
    echo json_encode(['ok' => $ok]);
    exit;
}

function search_productAction()
{
    global $conn;
    header('Content-Type: application/json');

    $keyword = trim($_POST['keyword'] ?? '');
    if ($keyword === '') {
        echo json_encode(['data' => []]);
        exit;
    }

    $keyword_safe = escape_string($keyword);
    $sql = "SELECT id, COALESCE(NULLIF(common_product_name, ''), product_name) AS product_name
            FROM products
            WHERE product_name LIKE '%$keyword_safe%' OR common_product_name LIKE '%$keyword_safe%'
            ORDER BY product_name ASC
            LIMIT 15";
    $result = db_fetch_array($sql);

    echo json_encode(['data' => $result ?: []]);
    exit;
}

/** Xem nhanh tồn 1 NGUYÊN VẬT LIỆU. POST: keyword -> [{id,name,unit,quantity}] */
function ltp_material_stock_searchAction()
{
    header('Content-Type: application/json; charset=utf-8');

    $keyword = trim($_POST['keyword'] ?? '');
    if ($keyword === '') { echo json_encode(['data' => []]); exit; }

    $kw  = escape_string($keyword);
    $sql = "SELECT mi.id,
                   mi.material_name AS name,
                   COALESCE(NULLIF(mi.unit, ''), '') AS unit,
                   COALESCE(minv.quantity, 0) AS quantity
            FROM material_information mi
            LEFT JOIN material_inventory minv ON minv.material_id = mi.id
            WHERE mi.material_name LIKE '%$kw%'
            ORDER BY mi.material_name ASC
            LIMIT 15";
    $rows = db_fetch_array($sql);

    echo json_encode(['data' => $rows ?: []], JSON_UNESCAPED_UNICODE);
    exit;
}

/** Xem nhanh tồn 1 THÀNH PHẨM. POST: keyword -> [{id,name,unit,quantity}] */
function ltp_product_stock_searchAction()
{
    header('Content-Type: application/json; charset=utf-8');

    $keyword = trim($_POST['keyword'] ?? '');
    if ($keyword === '') { echo json_encode(['data' => []]); exit; }

    $kw  = escape_string($keyword);
    $sql = "SELECT p.id,
                   p.product_name AS name,
                   COALESCE(NULLIF(p.unit, ''), '') AS unit,
                   COALESCE(fgi.quantity, 0) AS quantity
            FROM products p
            LEFT JOIN finished_goods_inventory fgi ON fgi.product_id = p.id
            WHERE p.product_name LIKE '%$kw%'
            ORDER BY p.product_name ASC
            LIMIT 15";
    $rows = db_fetch_array($sql);

    echo json_encode(['data' => $rows ?: []], JSON_UNESCAPED_UNICODE);
    exit;
}

function get_product_dataAction()
{
    global $conn;
    header('Content-Type: application/json');

    $product_id = (int) ($_POST['product_id'] ?? 0);
    if ($product_id <= 0) {
        echo json_encode(['success' => false, 'message' => 'Invalid product_id']);
        exit;
    }

    $sql = "SELECT id, COALESCE(NULLIF(common_product_name, ''), product_name) AS product_name
            FROM products WHERE id = $product_id LIMIT 1";
    $product = db_fetch_row($sql);

    if (empty($product)) {
        echo json_encode(['success' => false, 'message' => 'Product not found']);
        exit;
    }

    // Sản lượng đề xuất: lấy quantity của lần nhập sản xuất gần nhất (created_at mới nhất)
    $sql_qty = "SELECT quantity
                FROM finished_product_production_data
                WHERE product_id = $product_id
                ORDER BY created_at DESC, id DESC
                LIMIT 1";
    $qty_row = db_fetch_row($sql_qty);
    $last_quantity = isset($qty_row['quantity']) ? (int) $qty_row['quantity'] : '';

    require_once __DIR__ . '/../../../libraries/reminder_points.php';
    rp_ensure_tables();

    echo json_encode([
        'success' => true,
        'data' => [
            'product_id'    => (int) $product['id'],
            'product_name'  => $product['product_name'],
            'reminders'     => rp_get_pre_plan_reminders_for_product($product_id),
            'last_quantity' => $last_quantity
        ]
    ]);
    exit;
}

/** AJAX: sửa tiêu đề phiếu Share KHSX (company_name/company_address, dùng chung app_settings với production_formula). POST: key, value */
function save_share_settingAction()
{
    header('Content-Type: application/json');
    $key   = (string) ($_POST['key'] ?? '');
    $value = (string) ($_POST['value'] ?? '');
    echo json_encode(['success' => ps_save_share_setting($key, $value)], JSON_UNESCAPED_UNICODE);
    exit;
}

/** AJAX: sửa tên thường gọi 1 sản phẩm (name-product ở production_plan). POST: product_id, value */
function save_product_common_nameAction()
{
    header('Content-Type: application/json');
    $pid   = (int) ($_POST['product_id'] ?? 0);
    $value = (string) ($_POST['value'] ?? '');
    if ($pid <= 0) {
        echo json_encode(['success' => false, 'message' => 'Thiếu sản phẩm.']);
        exit;
    }
    echo json_encode(['success' => ps_save_product_common_name($pid, $value), 'value' => trim($value)], JSON_UNESCAPED_UNICODE);
    exit;
}

/** AJAX: sửa tên thường gọi 1 nguyên vật liệu (bao bì trong/ngoài ở plan_for_staff). POST: material_id, value */
function save_material_common_nameAction()
{
    header('Content-Type: application/json');
    $mid   = (int) ($_POST['material_id'] ?? 0);
    $value = (string) ($_POST['value'] ?? '');
    if ($mid <= 0) {
        echo json_encode(['success' => false, 'message' => 'Thiếu nguyên liệu.']);
        exit;
    }
    echo json_encode(['success' => ps_save_material_common_name($mid, $value), 'value' => trim($value)], JSON_UNESCAPED_UNICODE);
    exit;
}

function search_materialAction()
{
    header('Content-Type: application/json');

    $keyword = trim($_POST['keyword'] ?? '');
    if ($keyword === '') {
        echo json_encode(['data' => []]);
        exit;
    }

    $kw = escape_string($keyword);
    $sql = "SELECT mi.id, COALESCE(NULLIF(mi.common_material_name, ''), mi.material_name) AS material_name, s.supplier_name
            FROM material_information mi
            LEFT JOIN suppliers s ON s.id = mi.supplier_id
            WHERE mi.material_name LIKE '%$kw%' OR mi.common_material_name LIKE '%$kw%'
            ORDER BY mi.material_name ASC
            LIMIT 20";

    echo json_encode(['data' => db_fetch_array($sql) ?: []], JSON_UNESCAPED_UNICODE);
    exit;
}

function ajax_update_product_basicAction()
{
    global $conn;
    header('Content-Type: application/json');

    $product_id = (int) ($_POST['product_id'] ?? 0);
    $field      = $_POST['field'] ?? '';
    $value      = $_POST['value'] ?? '';

    $allowed = ['inner_packaging_spec', 'outer_packaging_spec', 'inner_packaging_id', 'outer_packaging_id'];
    if ($product_id <= 0 || !in_array($field, $allowed, true)) {
        echo json_encode(['success' => false, 'message' => 'Dữ liệu không hợp lệ.']);
        exit;
    }

    // Đảm bảo đã có bản ghi product_info_basic cho sản phẩm
    $exists = db_fetch_row("SELECT id FROM product_info_basic WHERE product_id = $product_id LIMIT 1");
    if (empty($exists)) {
        db_insert('product_info_basic', ['product_id' => $product_id]);
    }

    $resp = ['success' => true];

    if (substr($field, -3) === '_id') {
        // Bao bì trong/ngoài: lưu id nguyên liệu (FK material_information)
        $v = (int) $value;
        if ($v > 0) {
            db_update('product_info_basic', [$field => $v], "product_id = $product_id");
            $r = db_fetch_row("SELECT COALESCE(NULLIF(common_material_name, ''), material_name) AS material_name FROM material_information WHERE id = $v LIMIT 1");
            $resp['name'] = $r['material_name'] ?? '';
        } else {
            db_query("UPDATE product_info_basic SET `$field` = NULL WHERE product_id = $product_id");
            $resp['name'] = '';
        }
    } else {
        db_update('product_info_basic', [$field => trim($value)], "product_id = $product_id");
    }

    echo json_encode($resp, JSON_UNESCAPED_UNICODE);
    exit;
}

function save_noteAction()
{
    header('Content-Type: application/json');
    require_once __DIR__ . '/../../../libraries/reminder_points.php';

    $product_id = (int) ($_POST['product_id'] ?? 0);
    $note       = trim($_POST['note'] ?? '');

    if ($product_id <= 0) {
        echo json_encode(['success' => false, 'message' => 'Invalid product_id']);
        exit;
    }

    rp_save_pre_production_note($product_id, $note);

    if ($note === '') {
        echo json_encode(['success' => true, 'deleted' => true]);
        exit;
    }

    echo json_encode(['success' => true]);
    exit;
}

function delete_noteAction()
{
    header('Content-Type: application/json');
    require_once __DIR__ . '/../../../libraries/reminder_points.php';

    $product_id = (int) ($_POST['product_id'] ?? 0);
    if ($product_id <= 0) {
        echo json_encode(['success' => false, 'message' => 'Invalid product_id']);
        exit;
    }

    rp_delete_pre_production_note($product_id);
    echo json_encode(['success' => true]);
    exit;
}

/** AJAX: nhân viên tích chọn 1 trong 3 cờ QC (lấy mẫu/test/kiểm tồn) ở plan_for_staff. POST: plan_id, field, value */
function ajax_update_plan_qcAction()
{
    header('Content-Type: application/json');

    $plan_id = (int) ($_POST['plan_id'] ?? 0);
    $field   = (string) ($_POST['field'] ?? '');
    $value   = !empty($_POST['value']) ? 1 : 0;
    $allowed = ['sample', 'test', 'check_inventory'];

    if ($plan_id <= 0 || !in_array($field, $allowed, true)) {
        echo json_encode(['success' => false, 'message' => 'Dữ liệu không hợp lệ.']);
        exit;
    }

    db_update('production_plans', [$field => $value], "id = {$plan_id}");
    echo json_encode(['success' => true]);
    exit;
}

/** AJAX: nhân viên sửa số lượng sản xuất 1 sản phẩm (plan_for_staff). POST: plan_id, quantity */
function ajax_update_plan_quantityAction()
{
    header('Content-Type: application/json');

    $plan_id = (int) ($_POST['plan_id'] ?? 0);
    $quantity = (int) ($_POST['quantity'] ?? 0);

    if ($plan_id <= 0) {
        echo json_encode(['success' => false, 'message' => 'Dữ liệu không hợp lệ.']);
        exit;
    }

    db_update('production_plans', ['quantity' => $quantity], "id = {$plan_id}");
    echo json_encode(['success' => true, 'quantity' => $quantity]);
    exit;
}

/** AJAX: nhân viên thêm 1 sản phẩm mới vào KHSX hiện hành (plan_for_staff). POST: product_id */
function ajax_add_plan_productAction()
{
    header('Content-Type: application/json');

    $product_id = (int) ($_POST['product_id'] ?? 0);
    if ($product_id <= 0) {
        echo json_encode(['success' => false, 'message' => 'Dữ liệu không hợp lệ.']);
        exit;
    }

    $dup = db_fetch_row("SELECT id FROM production_plans WHERE product_id = $product_id LIMIT 1");
    if (!empty($dup)) {
        echo json_encode(['success' => false, 'message' => 'Sản phẩm đã có trong kế hoạch.']);
        exit;
    }

    $qty_row = db_fetch_row("SELECT quantity FROM finished_product_production_data
                              WHERE product_id = $product_id ORDER BY created_at DESC, id DESC LIMIT 1");
    $quantity = isset($qty_row['quantity']) ? (int) $qty_row['quantity'] : 0;

    $plan_id = db_insert('production_plans', [
        'product_id'      => $product_id,
        'quantity'        => $quantity,
        'note'            => '',
        'sample'          => 0,
        'test'            => 0,
        'check_inventory' => 0
    ]);

    $sql = "SELECT pp.id AS plan_id, pp.product_id, pp.quantity,
                   pp.sample, pp.test, pp.check_inventory,
                   COALESCE(NULLIF(p.common_product_name, ''), p.product_name) AS product_name,
                   fgi.quantity AS stock_qty,
                   COALESCE(NULLIF(mi_in.common_material_name, ''), mi_in.material_name) AS inner_packaging,
                   COALESCE(NULLIF(mi_out.common_material_name, ''), mi_out.material_name) AS outer_packaging,
                   pib.inner_packaging_id, pib.outer_packaging_id,
                   pib.inner_packaging_spec, pib.outer_packaging_spec
            FROM production_plans pp
            LEFT JOIN products p ON p.id = pp.product_id
            LEFT JOIN finished_goods_inventory fgi ON fgi.product_id = pp.product_id
            LEFT JOIN product_info_basic pib ON pib.product_id = pp.product_id
            LEFT JOIN material_information mi_in ON mi_in.id = pib.inner_packaging_id
            LEFT JOIN material_information mi_out ON mi_out.id = pib.outer_packaging_id
            WHERE pp.id = $plan_id LIMIT 1";
    $row = db_fetch_row($sql);

    echo json_encode(['success' => true, 'plan' => $row], JSON_UNESCAPED_UNICODE);
    exit;
}

/** AJAX: nhân viên thêm 1 "việc khác" vào KHSX hiện hành (plan_for_staff). POST: description */
function ajax_add_taskAction()
{
    header('Content-Type: application/json');

    $desc = trim((string) ($_POST['description'] ?? ''));
    if ($desc === '') {
        echo json_encode(['success' => false, 'message' => 'Nội dung trống.']);
        exit;
    }

    // "Việc khác" gắn về plan_id của kế hoạch hiện hành (đại diện = dòng đầu tiên,
    // giống cách save_planAction gắn tasks về $first_plan_id).
    $plan_row = db_fetch_row("SELECT id FROM production_plans ORDER BY id ASC LIMIT 1");
    if (empty($plan_row)) {
        echo json_encode(['success' => false, 'message' => 'Chưa có kế hoạch sản xuất để gắn việc khác.']);
        exit;
    }

    $id = db_insert('additional_tasks', [
        'plan_id'     => (int) $plan_row['id'],
        'description' => $desc
    ]);

    echo json_encode(['success' => true, 'id' => (int) $id], JSON_UNESCAPED_UNICODE);
    exit;
}

/** AJAX: nhân viên gỡ 1 "việc khác" khỏi KHSX hiện hành (plan_for_staff). POST: task_id */
function ajax_delete_taskAction()
{
    header('Content-Type: application/json');

    $task_id = (int) ($_POST['task_id'] ?? 0);
    if ($task_id <= 0) {
        echo json_encode(['success' => false, 'message' => 'Dữ liệu không hợp lệ.']);
        exit;
    }

    db_delete('additional_tasks', "id = {$task_id}");
    echo json_encode(['success' => true]);
    exit;
}

function save_planAction()
{
    global $conn;
    header('Content-Type: application/json');

    $items_raw   = $_POST['items'] ?? '[]';
    $tasks_raw   = $_POST['tasks'] ?? '[]';
    $force_clear = !empty($_POST['force_clear']);

    $items = json_decode($items_raw, true) ?: [];
    $tasks = json_decode($tasks_raw, true) ?: [];

    if (empty($items) && !$force_clear) {
        echo json_encode(['success' => false, 'message' => 'Không có sản phẩm nào trong kế hoạch.']);
        exit;
    }

    // 1) Xóa plan cũ + tasks cũ (1 kế hoạch hiện hành)
    db_query("DELETE FROM additional_tasks");
    db_query("DELETE FROM production_plans");

    // 2) Insert plans
    $first_plan_id = 0;
    foreach ($items as $item) {
        $pid = (int) ($item['product_id'] ?? 0);
        $qty = (int) ($item['quantity'] ?? 0);
        $note = trim($item['note'] ?? '');
        $sample = !empty($item['sample']) ? 1 : 0;
        $test = !empty($item['test']) ? 1 : 0;
        $check_inventory = !empty($item['check_inventory']) ? 1 : 0;
        if ($pid <= 0) continue;

        $plan_id = db_insert('production_plans', [
            'product_id'      => $pid,
            'quantity'        => $qty,
            'note'            => $note,
            'sample'          => $sample,
            'test'            => $test,
            'check_inventory' => $check_inventory
        ]);
        if ($first_plan_id === 0) $first_plan_id = (int) $plan_id;
    }

    // 3) Insert tasks (gắn về plan đầu tiên — đại diện kế hoạch)
    if ($first_plan_id > 0 && !empty($tasks)) {
        foreach ($tasks as $desc) {
            $desc = trim((string) $desc);
            if ($desc === '') continue;
            db_insert('additional_tasks', [
                'plan_id'     => $first_plan_id,
                'description' => $desc
            ]);
        }
    }

    echo json_encode(['success' => true]);
    exit;
}

/* =====================================================================================
 *  XUẤT KẾ HOẠCH SẢN XUẤT ĐỊNH KỲ (nút "Cài đặt" trên board long_term_production_plan)
 *
 *  User chốt 5/8/2026: hẹn giờ (vd 16:50 hằng ngày, bỏ chủ nhật) → tự chuyển KHSX NGÀY MAI
 *  sang "Kế hoạch sản xuất hằng ngày" + chụp ảnh gửi chat, người gửi là Safe King.
 *
 *  Nghiệp vụ nằm trong libraries/plan_auto_export.php (prefix pae_*). Bốn action dưới đây chỉ
 *  là cửa HTTP. Nút "XUẤT KH" thủ công trên từng card GIỮ NGUYÊN (user chốt) — tự động chỉ là
 *  thêm một đường, không thay thế, để lỡ giờ vẫn còn cách làm tay.
 * ===================================================================================== */

/** Nạp thư viện + tạo bảng (idempotent). */
function pae_boot()
{
    require_once __DIR__ . '/../../../libraries/plan_auto_export.php';
    pae_ensure_tables();
}

/** JSON: cấu hình hiện tại + danh sách người nhận / nhóm cho modal Cài đặt. */
function plan_auto_export_configAction()
{
    permission_require_admin(true);
    pae_boot();
    header('Content-Type: application/json; charset=utf-8');

    $cfg = pae_config();
    // Người thực hiện quyết định danh sách NHÓM chọn được (chỉ nhóm người đó là thành viên).
    $delegate = (int) ($_GET['delegate_user_id'] ?? ($cfg['delegate_user_id'] ?? 0));

    echo json_encode([
        'ok'     => true,
        'config' => $cfg,
        'users'  => ar_active_users(),
        'groups' => $delegate > 0 ? ar_groups_for_user($delegate) : [],
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

/** JSON: lưu cấu hình lịch. */
function plan_auto_export_saveAction()
{
    permission_require_admin(true);
    pae_boot();
    header('Content-Type: application/json; charset=utf-8');

    $user = permission_current_user();
    $uid  = $user ? (int) $user['id'] : 0;

    $in = [
        'is_active'                 => !empty($_POST['is_active']),
        'send_time'                 => $_POST['send_time'] ?? '',
        'skip_sunday'               => !empty($_POST['skip_sunday']),
        'delegate_user_id'          => (int) ($_POST['delegate_user_id'] ?? 0),
        'window_minutes'            => (int) ($_POST['window_minutes'] ?? 30),
        'recipient_type'            => $_POST['recipient_type'] ?? 'users',
        'recipient_user_ids'        => $_POST['recipient_user_ids'] ?? '[]',
        'recipient_conversation_id' => (int) ($_POST['recipient_conversation_id'] ?? 0),
        'caption'                   => $_POST['caption'] ?? '',
    ];

    // Nhóm nhận phải là nhóm mà NGƯỜI THỰC HIỆN thật sự tham gia — không tin id client gửi lên.
    if ($in['recipient_type'] === 'group'
        && !ar_group_ok_for_user($in['recipient_conversation_id'], $in['delegate_user_id'])) {
        echo json_encode(['ok' => false, 'message' => 'Người thực hiện không thuộc nhóm chat đã chọn.'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    $res = pae_save($in, $uid);
    echo json_encode(!empty($res['ok'])
        ? ['ok' => true, 'config' => pae_config()]
        : ['ok' => false, 'message' => $res['error']], JSON_UNESCAPED_UNICODE);
    exit;
}

/**
 * JSON: poller hỏi "tới giờ chưa". Ai online gọi cũng được — nhân tiện chạy quét lịch bị lỡ.
 * KHÔNG gate admin: người thực hiện có thể là nhân viên thường.
 */
function plan_auto_export_due_checkAction()
{
    pae_boot();
    header('Content-Type: application/json; charset=utf-8');

    $user = permission_current_user();
    $uid  = $user ? (int) $user['id'] : 0;
    if ($uid <= 0) { echo json_encode(['ok' => false]); exit; }

    pae_run_missed_sweep();
    $due = pae_due_for_user($uid);
    echo json_encode([
        'ok'  => true,
        'due' => $due ? [
            'id'        => (int) $due['id'],
            'settle_ms' => (int) $due['settle_ms'],
            'plan_date' => pae_target_date(),
        ] : null,
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

/**
 * JSON multipart: trình duyệt người thực hiện gửi ảnh vừa chụp lên.
 * Làm 2 việc theo đúng thứ tự: (1) CHUYỂN kế hoạch ngày mai, (2) gửi ảnh vào chat.
 *
 * VÌ SAO CHUYỂN TRƯỚC RỒI MỚI GỬI: chuyển kế hoạch mới là việc nghiệp vụ bắt buộc (đội nhà máy
 * sáng mai phải có kế hoạch để làm); gửi ảnh chỉ là thông báo. Gửi ảnh hỏng thì vẫn phải chốt
 * "đã chạy" cho phần chuyển, nếu không quét-lỡ sẽ báo admin rằng kế hoạch chưa chuyển — sai sự thật.
 */
function plan_auto_export_runAction()
{
    pae_boot();
    header('Content-Type: application/json; charset=utf-8');

    $user = permission_current_user();
    $uid  = $user ? (int) $user['id'] : 0;
    if ($uid <= 0) { echo json_encode(['ok' => false, 'message' => 'Chưa đăng nhập.'], JSON_UNESCAPED_UNICODE); exit; }

    $cfg = pae_config();
    if (!$cfg || (int) $cfg['delegate_user_id'] !== $uid) {
        echo json_encode(['ok' => false, 'message' => 'Bạn không phải người thực hiện của lịch này.'], JSON_UNESCAPED_UNICODE);
        exit;
    }
    if ((string) ($cfg['last_run_date'] ?? '') === date('Y-m-d')) {
        echo json_encode(['ok' => true, 'already' => true]); // đã chạy hôm nay → client quay lại
        exit;
    }

    $cid      = (int) $cfg['id'];
    $planDate = pae_target_date();

    // (1) Chuyển kế hoạch — phần bắt buộc.
    $exp = pae_export_plan_date($planDate);
    if (empty($exp['ok'])) {
        // Ngày mai không có sản phẩm nào: KHÔNG phải lỗi hệ thống, nhưng admin cần biết vì
        // sáng mai đội nhà máy sẽ không có kế hoạch. Chốt đã chạy để không nhắc lại cả buổi tối.
        pae_mark_run($cid);
        pae_log($cid, 'failed', [
            'actor_user_id' => $uid,
            'plan_date'     => $planDate,
            'note'          => $exp['error'] ?? 'Không có sản phẩm để xuất.',
        ]);
        notify_admins(
            'Kế hoạch sản xuất ngày mai đang trống',
            ($exp['error'] ?? '') . ' Lịch tự động đã chạy nhưng không có gì để chuyển.',
            'production_staff/long_term_production_plan',
            'general'
        );
        echo json_encode(['ok' => false, 'message' => $exp['error'] ?? 'Không có sản phẩm để xuất.'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    // Chốt "đã chạy" NGAY sau khi chuyển xong — phần gửi ảnh phía dưới hỏng cũng không làm
    // quét-lỡ báo nhầm là kế hoạch chưa được chuyển.
    pae_mark_run($cid);

    // (2) Gửi ảnh vào chat (tuỳ chọn — thiếu ảnh vẫn coi như chạy xong phần chính).
    if (empty($_FILES['image']) || ($_FILES['image']['error'] ?? 1) !== UPLOAD_ERR_OK) {
        pae_log($cid, 'exported', [
            'actor_user_id' => $uid, 'plan_date' => $planDate, 'item_count' => (int) $exp['item_count'],
            'note' => 'Đã chuyển kế hoạch, không kèm ảnh.',
        ]);
        echo json_encode(['ok' => true, 'exported' => true, 'sent' => false,
                          'item_count' => (int) $exp['item_count']], JSON_UNESCAPED_UNICODE);
        exit;
    }

    require_once __DIR__ . '/../../../libraries/chat.php';
    $file = chat_store_upload($_FILES['image']);
    if (empty($file['ok'])) {
        pae_log($cid, 'exported', [
            'actor_user_id' => $uid, 'plan_date' => $planDate, 'item_count' => (int) $exp['item_count'],
            'note' => 'Đã chuyển kế hoạch; lưu ảnh thất bại: ' . ($file['reason'] ?? ''),
        ]);
        echo json_encode(['ok' => true, 'exported' => true, 'sent' => false,
                          'message' => 'Đã chuyển kế hoạch nhưng không lưu được ảnh.'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    $res = pae_send_to_recipients($cfg, $file, '');
    if (!empty($res['ok'])) {
        pae_log($cid, 'sent', [
            'actor_user_id'     => $uid,
            'plan_date'         => $planDate,
            'item_count'        => (int) $exp['item_count'],
            'recipient_summary' => $res['recipient_summary'] ?? '',
            'message_ids'       => $res['message_ids'] ?? [],
            'attachment_file'   => $file['file_name'],
        ]);
        echo json_encode(['ok' => true, 'exported' => true, 'sent' => true,
                          'item_count' => (int) $exp['item_count'],
                          'recipient_summary' => $res['recipient_summary'] ?? ''], JSON_UNESCAPED_UNICODE);
        exit;
    }

    pae_log($cid, 'exported', [
        'actor_user_id' => $uid, 'plan_date' => $planDate, 'item_count' => (int) $exp['item_count'],
        'attachment_file' => $file['file_name'],
        'note' => 'Đã chuyển kế hoạch; gửi chat thất bại: ' . ($res['error'] ?? ''),
    ]);
    notify_admins(
        'Gửi ảnh kế hoạch sản xuất thất bại',
        'Kế hoạch ngày ' . date('d/m/Y', strtotime($planDate)) . ' ĐÃ được chuyển sang "Kế hoạch sản xuất hằng ngày", '
        . 'nhưng không gửi được vào chat: ' . ($res['error'] ?? 'lỗi gửi'),
        'production_staff/long_term_production_plan',
        'general'
    );
    echo json_encode(['ok' => true, 'exported' => true, 'sent' => false,
                      'message' => $res['error'] ?? 'Lỗi gửi chat.'], JSON_UNESCAPED_UNICODE);
    exit;
}
