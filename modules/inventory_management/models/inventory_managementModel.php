<?php

require_once __DIR__ . '/../../../libraries/purchase_price_changes.php';
require_once __DIR__ . '/../../../libraries/material_issue_costing.php';
require_once __DIR__ . '/../../../libraries/coffee_packaging.php';
require_once __DIR__ . '/../../../libraries/tea_scent_group.php';

/**
 * Hook order_coffee: sau khi nhập kho thành phẩm (mua/gia công) của 1 NCC,
 * nếu SP có thiết lập công thức kèm bao bì -> trừ tồn bao bì phía NCC.
 * Bọc guard + idempotent theo invoice (xem libraries/coffee_packaging.php).
 */
function im_coffee_packaging_hook($items, $supplier_id, $invoice_id, $created_at)
{
    if (!function_exists('coffee_order_on_finished_received')) return;
    foreach ((array) $items as $it) {
        $pid = (int) ($it['product_id'] ?? 0);
        $qty = (float) ($it['quantity'] ?? 0);
        if ($pid > 0 && $qty > 0) {
            coffee_order_on_finished_received($pid, $qty, $supplier_id, $created_at, $invoice_id);
        }
    }
}

/**
 * Chuẩn hoá payload bút toán user-input từ POST.
 * Input: ['debit' => '156', 'credit' => '338', 'amount' => 12345] (mọi key đều optional).
 * Output: ['debit' => string|null, 'credit' => string|null, 'amount' => float|null].
 * Giá trị null hoặc rỗng → cho phép fallback về default code-side.
 */
function im_je_normalize($je)
{
    if (!is_array($je)) $je = [];
    $debit  = isset($je['debit'])  ? trim((string) $je['debit'])  : '';
    $credit = isset($je['credit']) ? trim((string) $je['credit']) : '';
    $amt_in = $je['amount'] ?? null;
    if (is_string($amt_in)) $amt_in = trim($amt_in);
    $amount = ($amt_in === null || $amt_in === '') ? null : (float) $amt_in;
    return [
        'debit'  => $debit  !== '' ? $debit  : null,
        'credit' => $credit !== '' ? $credit : null,
        'amount' => $amount,
    ];
}

/**
 * Trộn bút toán user-input lên cặp default. Bất kỳ field nào user bỏ trống đều
 * fallback sang default. Trả ['debit', 'credit', 'amount'] đã giải quyết.
 */
function im_je_resolve($je, $default_debit, $default_credit, $default_amount)
{
    $n = im_je_normalize($je);
    return [
        'debit'  => $n['debit']  !== null ? $n['debit']  : $default_debit,
        'credit' => $n['credit'] !== null ? $n['credit'] : $default_credit,
        'amount' => $n['amount'] !== null ? $n['amount'] : (float) $default_amount,
    ];
}

/**
 * Lấy kế hoạch sản xuất (NVSX đã gửi xuống) để NV kho nhập vào tồn.
 */
function im_get_plans_for_inventory()
{
    // Ẩn các sản phẩm đã bị gỡ (nút ×) trong NGÀY hôm nay — cùng dấu "đã gỡ" mà
    // investment_products dùng, nên gỡ ở 1 trang là ẩn ở cả 2 (xem im_remove_day_product).
    im_ensure_dismissed_products_table();
    $today = escape_string(date('Y-m-d'));
    $sql = "SELECT pp.id AS plan_id,
                   pp.product_id,
                   pp.quantity,
                   p.product_name
            FROM production_plans pp
            LEFT JOIN products p ON p.id = pp.product_id
            WHERE pp.product_id NOT IN (
                SELECT product_id FROM im_dismissed_fg_products WHERE dismiss_date = '$today'
            )
            ORDER BY pp.id ASC";
    return db_fetch_array($sql) ?: [];
}

/**
 * Cập nhật production_plans.quantity theo plan_id (ưu tiên) hoặc product_id (fallback).
 * Trả true nếu có ít nhất 1 dòng được cập nhật, false nếu không tìm thấy/không hợp lệ.
 */
function im_update_plan_quantity($plan_id, $product_id, $quantity)
{
    $pid_plan = (int) $plan_id;
    $pid_prod = (int) $product_id;
    $q        = (int) $quantity;
    if ($q < 0) return false;

    if ($pid_plan > 0) {
        db_update('production_plans', ['quantity' => $q], "id = $pid_plan");
        return true;
    }
    if ($pid_prod > 0) {
        $row = db_fetch_row("SELECT id FROM production_plans WHERE product_id = $pid_prod ORDER BY id ASC LIMIT 1");
        if (!$row) return false;
        db_update('production_plans', ['quantity' => $q], 'id = ' . (int) $row['id']);
        return true;
    }
    return false;
}

/** Tìm sản phẩm theo keyword (dùng cho .wp-search). */
function im_search_products($keyword)
{
    $keyword = trim($keyword);
    if ($keyword === '') return [];
    $kw = escape_string($keyword);
    $sql = "SELECT id, product_name
            FROM products
            WHERE product_name LIKE '%$kw%'
            ORDER BY product_name ASC
            LIMIT 15";
    return db_fetch_array($sql) ?: [];
}

function im_get_product($product_id)
{
    $pid = (int) $product_id;
    if ($pid <= 0) return null;
    return db_fetch_row("SELECT id, product_name, unit FROM products WHERE id = $pid LIMIT 1") ?: null;
}

/**
 * Tìm sản phẩm thương mại kèm thông tin nhà cung cấp (page product_buy).
 * Trả về [{id, product_name, supplier_id, supplier_name}].
 */
/** Lấy tồn hiện tại trong finished_goods_inventory. */
function im_get_current_stock($product_id)
{
    $pid = (int) $product_id;
    if ($pid <= 0) return 0;
    $row = db_fetch_row("SELECT quantity FROM finished_goods_inventory WHERE product_id = $pid LIMIT 1");
    return $row ? (float) $row['quantity'] : 0;
}

/**
 * Chuẩn hoá 1 chuỗi datetime bất kỳ về 'Y-m-d H:i:s' (hoặc null nếu rỗng/không parse được).
 */
function im_sanitize_datetime($dt)
{
    if ($dt === null) return null;
    $dt = trim((string) $dt);
    if ($dt === '') return null;
    $t = strtotime($dt);
    if (!$t) return null;
    return date('Y-m-d H:i:s', $t);
}

/**
 * Chuẩn hoá 1 chuỗi ngày bất kỳ về 'Y-m-d' (hoặc null nếu rỗng/không parse được).
 */
function im_sanitize_date($date)
{
    if ($date === null) return null;
    $date = trim((string) $date);
    if ($date === '') return null;
    $t = strtotime($date);
    if (!$t) return null;
    return date('Y-m-d', $t);
}

/** Các giá trị hợp lệ cho stock_imports.type_import. */
function im_valid_type_imports()
{
    return ['fg_receipt_production', 'fg_receipt_purchase', 'other_receipt', 'sales_return_receipt', 'investment_production'];
}

function im_normalize_type_import($type)
{
    $type = (string) $type;
    return in_array($type, im_valid_type_imports(), true) ? $type : 'fg_receipt_production';
}

/**
 * Cộng `qty` vào tồn, ghi 1 dòng stock_imports kèm interpretation.
 * $created_at : 'Y-m-d H:i:s' (tuỳ chọn) — dùng khi user chọn thời điểm ghi từ picker.
 * $type_import: fg_receipt_production | fg_receipt_purchase | other_receipt.
 * Trả id của stock_imports vừa insert (>0), hoặc 0 nếu lỗi.
 */
function im_record_import($product_id, $qty, $interpretation = '', $created_at = null, $type_import = 'fg_receipt_production')
{
    $pid = (int) $product_id;
    $q   = (float) $qty;
    if ($pid <= 0) return 0;

    $existing = db_fetch_row("SELECT id, quantity FROM finished_goods_inventory WHERE product_id = $pid LIMIT 1");
    if ($existing) {
        $new_stock = (float) $existing['quantity'] + $q;
        db_update('finished_goods_inventory', ['quantity' => $new_stock], "product_id = $pid");
    } else {
        db_insert('finished_goods_inventory', [
            'product_id' => $pid,
            'quantity'   => $q
        ]);
    }

    $data = [
        'product_id'     => $pid,
        'quantity'       => $q,
        'interpretation' => $interpretation,
        'type_import'    => im_normalize_type_import($type_import),
    ];
    $ca = im_sanitize_datetime($created_at);
    if ($ca !== null) $data['created_at'] = $ca;

    $si_id = (int) db_insert('stock_imports', $data);

    // Dashboard "Nhập thành phẩm sản xuất" → upsert quantity vào
    // finished_product_production_data theo (product_id, ngày của record-datetime).
    if (im_normalize_type_import($type_import) === 'fg_receipt_production') {
        im_fpp_upsert_quantity($pid, $q, $ca);
        // Ghi lại phiếu nhập -> bỏ dấu "đã gỡ" của ngày để sản phẩm hiện lại (dashboard + investment).
        im_clear_dismissed_product($pid, $ca);
    }

    return $si_id;
}

/**
 * Tính giá vốn 1 đơn vị sản phẩm = SUM(pm.quantity_required * đơn giá NVL)
 * với đơn giá NVL = COALESCE(purchase_price_includes_purchase_cost, purchase_price)
 * của dòng material_purchase_prices mới nhất (ưu tiên giá đã gồm chi phí mua).
 * Material chưa có giá → tính 0.
 */
function im_compute_product_cost_per_unit($product_id)
{
    $pid = (int) $product_id;
    if ($pid <= 0) return 0.0;
    $sql = "SELECT pm.quantity_required,
                   (SELECT COALESCE(mpp.purchase_price_includes_purchase_cost, mpp.purchase_price)
                    FROM material_purchase_prices mpp
                    WHERE mpp.material_id = pm.material_id
                    ORDER BY mpp.last_updated_at DESC, mpp.id DESC
                    LIMIT 1) AS purchase_price
            FROM product_materials pm
            WHERE pm.product_id = $pid";
    $rows = db_fetch_array($sql) ?: [];
    $sum = 0.0;
    foreach ($rows as $r) {
        $qr    = (float) $r['quantity_required'];
        $price = $r['purchase_price'] !== null ? (float) $r['purchase_price'] : 0.0;
        $sum  += $qr * $price;
    }
    return $sum;
}

/**
 * Lấy danh sách stock_imports.id của 1 batch other_receipt theo group_key.
 * group_key = DATE_FORMAT(created_at, '%Y-%m-%d %H:%i:%s').
 */
function im_get_other_receipt_import_ids($group_key)
{
    $key = escape_string((string) $group_key);
    if ($key === '') return [];
    $rows = db_fetch_array("SELECT id FROM stock_imports
                            WHERE type_import = 'other_receipt'
                              AND DATE_FORMAT(created_at, '%Y-%m-%d %H:%i:%s') = '$key'") ?: [];
    return array_map(function ($r) { return (int) $r['id']; }, $rows);
}

/** Xóa các dòng transactions của 1 batch other_receipt (theo danh sách stock_imports.id). */
function im_delete_other_receipt_transactions(array $import_ids)
{
    $ids = array_values(array_filter(array_map('intval', $import_ids), function ($v) { return $v > 0; }));
    if (empty($ids)) return;
    $ids_sql = implode(',', $ids);
    db_query("DELETE FROM transactions
              WHERE reference_type = 'other_receipt'
                AND reference_id IN ($ids_sql)");
}

/**
 * Đồng bộ 2 dòng bút toán (Dr 156 / Cr 338) cho 1 batch other_receipt:
 * - Xóa các dòng transactions cũ theo danh sách import_ids của batch.
 * - Tính lại $sum_total = SUM(qty * giá_vốn_1_sp) từ stock_imports.
 * - Insert lại 2 dòng (reference_id = id stock_imports nhỏ nhất của batch).
 *
 * $created_at: 'Y-m-d H:i:s' (tuỳ chọn) — đồng bộ thời điểm với batch.
 */
function im_sync_other_receipt_transactions(array $import_ids, $created_at = null, $je = null)
{
    im_delete_other_receipt_transactions($import_ids);

    $first_si_id = 0;
    $sum_total   = 0.0;
    foreach ($import_ids as $iid) {
        $iid = (int) $iid;
        if ($iid <= 0) continue;
        $row = db_fetch_row("SELECT product_id, quantity FROM stock_imports WHERE id = $iid LIMIT 1");
        if (!$row) continue;
        if ($first_si_id === 0) $first_si_id = $iid;
        $sum_total += (float) $row['quantity'] * im_compute_product_cost_per_unit((int) $row['product_id']);
    }

    if ($first_si_id <= 0) return;

    $resolved = im_je_resolve($je, '156', '338', $sum_total);
    $ca = im_sanitize_datetime($created_at);
    je_insert_pairs('other_receipt', $first_si_id, je_entries_from_payload($je, $resolved), $ca);
}

/**
 * Tìm các sản phẩm đã được ghi vào stock_imports trong cùng ngày (DATE(created_at)).
 * Nếu $type_import được truyền, chỉ xét các dòng cùng loại (cùng page).
 * Trả về mảng [{product_id, product_name, date_vn}].
 */
function im_find_duplicate_imports($product_ids, $date, $type_import = null)
{
    if (!is_array($product_ids) || empty($product_ids)) return [];
    $d = im_sanitize_date($date);
    if ($d === null) return [];

    $ids = [];
    foreach ($product_ids as $id) {
        $i = (int) $id;
        if ($i > 0) $ids[$i] = $i;
    }
    if (empty($ids)) return [];

    $ids_sql = implode(',', array_values($ids));
    $d_safe  = escape_string($d);

    $where_type = '';
    if ($type_import !== null && $type_import !== '') {
        $t_safe = escape_string(im_normalize_type_import($type_import));
        $where_type = " AND si.type_import = '$t_safe'";
    }

    $sql = "SELECT si.product_id,
                   p.product_name,
                   MIN(si.created_at) AS created_at
            FROM stock_imports si
            LEFT JOIN products p ON p.id = si.product_id
            WHERE si.product_id IN ($ids_sql)
              AND DATE(si.created_at) = '$d_safe'
              $where_type
            GROUP BY si.product_id, p.product_name
            ORDER BY p.product_name ASC";
    $rows = db_fetch_array($sql) ?: [];

    return array_map(function ($r) {
        $ts = strtotime($r['created_at']);
        return [
            'product_id'   => (int) $r['product_id'],
            'product_name' => $r['product_name'] ?: ('#' . (int) $r['product_id']),
            'date_vn'      => $ts ? date('d/m/Y', $ts) : '',
        ];
    }, $rows);
}

/**
 * Lấy các "batch" gần nhất từ stock_imports, group theo created_at (độ chính xác giây).
 * $type_import: nếu truyền thì chỉ lấy batch cùng loại (để tách lịch sử theo page).
 * Trả về tối đa $limit batch.
 * Mỗi batch: {group_key, created_at, date_display, summary, items: [...]}
 */
function im_get_recent_batches($limit = 5, $type_import = null)
{
    $limit = (int) $limit;
    if ($limit <= 0) $limit = 5;

    $where_type = '';
    $where_type_item = '';
    if ($type_import !== null && $type_import !== '') {
        $t_safe = escape_string(im_normalize_type_import($type_import));
        $where_type      = " WHERE type_import = '$t_safe'";
        $where_type_item = " AND si.type_import = '$t_safe'";
    }

    $sql_groups = "SELECT DATE_FORMAT(created_at, '%Y-%m-%d %H:%i:%s') AS group_key,
                          MIN(created_at) AS created_at,
                          COUNT(*) AS n
                   FROM stock_imports
                   $where_type
                   GROUP BY group_key
                   ORDER BY created_at DESC
                   LIMIT $limit";
    $groups = db_fetch_array($sql_groups) ?: [];

    $batches = [];
    foreach ($groups as $g) {
        $key_safe = escape_string($g['group_key']);
        $sql_items = "SELECT si.id AS import_id,
                             si.product_id,
                             si.quantity,
                             si.interpretation,
                             si.created_at,
                             p.product_name
                      FROM stock_imports si
                      LEFT JOIN products p ON p.id = si.product_id
                      WHERE DATE_FORMAT(si.created_at, '%Y-%m-%d %H:%i:%s') = '$key_safe'
                      $where_type_item
                      ORDER BY si.id ASC";
        $items = db_fetch_array($sql_items) ?: [];

        // "Tên SP {số lượng}" — số lượng bỏ số 0 thừa (142 thay vì 142.00).
        $names = array_map(function ($it) {
            $label = $it['product_name'] ?: ('#' . $it['product_id']);
            $q = (float) $it['quantity'];
            $qstr = (floor($q) == $q)
                ? (string) (int) $q
                : rtrim(rtrim(number_format($q, 3, '.', ''), '0'), '.');
            return $label . ' ' . $qstr;
        }, $items);
        // investment_production lưu sẵn diễn giải đầy đủ trong stock_imports.interpretation
        // (built bởi im_build_investment_interpretation), nên ưu tiên dùng trực tiếp.
        if ($type_import === 'investment_production' && !empty($items[0]['interpretation'])) {
            $summary = $items[0]['interpretation'];
        } else {
            $prefix  = ($type_import === 'investment_production')
                ? 'Nhập giá vốn sản xuất các sản phẩm '
                : 'Nhập kho ';
            $summary = $prefix . implode(', ', $names);
        }
        $max_len = 160;
        if (mb_strlen($summary, 'UTF-8') > $max_len) {
            $summary = mb_substr($summary, 0, $max_len, 'UTF-8') . '...';
        }

        $hist_date    = history_date_display($g['created_at']);
        $date_display = $hist_date['text'];
        $date_color   = $hist_date['color'];

        $batches[] = [
            'group_key'    => $g['group_key'],
            'created_at'   => $g['created_at'],
            'date_display' => $date_display,
            'date_color'   => $date_color,
            'summary'      => $summary,
            'items'        => array_map(function ($it) {
                return [
                    'import_id'      => (int) $it['import_id'],
                    'product_id'     => (int) $it['product_id'],
                    'product_name'   => $it['product_name'],
                    'quantity'       => (float) $it['quantity'],
                    'interpretation' => $it['interpretation'] ?: '',
                ];
            }, $items),
        ];
    }
    return $batches;
}

/** Lấy 1 batch theo group_key (tạo từ created_at 'Y-m-d H:i:s'). */
function im_get_batch($group_key, $type_import = null)
{
    $key = escape_string($group_key);
    $where_type = '';
    if ($type_import !== null && $type_import !== '') {
        $t_safe = escape_string(im_normalize_type_import($type_import));
        $where_type = " AND si.type_import = '$t_safe'";
    }
    $sql = "SELECT si.id AS import_id,
                   si.product_id,
                   si.quantity,
                   si.interpretation,
                   si.created_at,
                   p.product_name
            FROM stock_imports si
            LEFT JOIN products p ON p.id = si.product_id
            WHERE DATE_FORMAT(si.created_at, '%Y-%m-%d %H:%i:%s') = '$key'
              $where_type
            ORDER BY si.id ASC";
    $rows = db_fetch_array($sql) ?: [];
    return array_map(function ($r) {
        return [
            'import_id'      => (int) $r['import_id'],
            'product_id'     => (int) $r['product_id'],
            'product_name'   => $r['product_name'],
            'quantity'       => (float) $r['quantity'],
            'interpretation' => $r['interpretation'] ?: '',
        ];
    }, $rows);
}

/**
 * Sửa 1 dòng stock_imports (theo import_id), đồng thời điều chỉnh finished_goods_inventory
 * bằng delta = new_qty - old_qty.
 * $created_at (tuỳ chọn): nếu có, đồng thời cập nhật lại created_at của dòng.
 */
function im_update_import_item($import_id, $new_qty, $new_interp, $created_at = null)
{
    $iid = (int) $import_id;
    if ($iid <= 0) return false;

    $row = db_fetch_row("SELECT product_id, quantity, type_import, created_at FROM stock_imports WHERE id = $iid LIMIT 1");
    if (!$row) return false;

    $pid   = (int) $row['product_id'];
    $old   = (float) $row['quantity'];
    $new   = (float) $new_qty;
    $delta = $new - $old;
    $row_type    = (string) ($row['type_import'] ?? '');
    $row_created = (string) ($row['created_at']  ?? '');

    if ($delta != 0) {
        $stock_row = db_fetch_row("SELECT quantity FROM finished_goods_inventory WHERE product_id = $pid LIMIT 1");
        if ($stock_row) {
            $new_stock = (float) $stock_row['quantity'] + $delta;
            db_update('finished_goods_inventory', ['quantity' => $new_stock], "product_id = $pid");
        } else {
            db_insert('finished_goods_inventory', [
                'product_id' => $pid,
                'quantity'   => $new
            ]);
        }
    }

    $update = [
        'quantity'       => $new,
        'interpretation' => $new_interp
    ];
    $ca = im_sanitize_datetime($created_at);
    if ($ca !== null) $update['created_at'] = $ca;

    db_update('stock_imports', $update, "id = $iid");

    // Dashboard "Nhập thành phẩm sản xuất" sync ngược lại
    // finished_product_production_data theo (product_id, ngày).
    // Nếu user đổi datetime → xoá row ở ngày cũ trước rồi upsert sang ngày mới.
    if ($row_type === 'fg_receipt_production') {
        $new_dt = $ca !== null ? $ca : $row_created;
        if ($ca !== null && $row_created !== '') {
            $d_old = im_date_only_dt($row_created);
            $d_new = im_date_only_dt($ca);
            if ($d_old !== null && $d_old !== $d_new) {
                im_fpp_delete($pid, $row_created);
            }
        }
        im_fpp_upsert_quantity($pid, $new, $new_dt);
    }

    return true;
}

/**
 * Xóa toàn bộ stock_imports của 1 batch (group_key) và trừ lại số lượng ra khỏi
 * finished_goods_inventory. Nếu truyền $type_import thì chỉ xoá các dòng cùng loại.
 */
function im_delete_batch($group_key, $type_import = null)
{
    $items = im_get_batch($group_key, $type_import);
    if (empty($items)) return 0;

    foreach ($items as $it) {
        $pid = (int) $it['product_id'];
        $qty = (float) $it['quantity'];
        $stock_row = db_fetch_row("SELECT quantity FROM finished_goods_inventory WHERE product_id = $pid LIMIT 1");
        if ($stock_row) {
            $new_stock = (float) $stock_row['quantity'] - $qty;
            db_update('finished_goods_inventory', ['quantity' => $new_stock], "product_id = $pid");
        }
    }

    // Dashboard delete → xoá row tương ứng trong finished_product_production_data.
    if ($type_import !== null && im_normalize_type_import($type_import) === 'fg_receipt_production') {
        foreach ($items as $it) {
            im_fpp_delete((int) $it['product_id'], $group_key);
        }
    }

    $key = escape_string($group_key);
    $where_type = '';
    if ($type_import !== null && $type_import !== '') {
        $t_safe = escape_string(im_normalize_type_import($type_import));
        $where_type = " AND type_import = '$t_safe'";
    }
    db_query("DELETE FROM stock_imports
              WHERE DATE_FORMAT(created_at, '%Y-%m-%d %H:%i:%s') = '$key'
                $where_type");
    return count($items);
}

/* ============================================================
 *  GỠ SẢN PHẨM KHỎI DANH SÁCH NGÀY (nút × ở dashboard + investment_products)
 *  "Xóa hẳn dữ liệu ngày đó": xóa phiếu nhập thành phẩm (fg_receipt_production)
 *  của (product, ngày), trừ lại finished_goods_inventory, xóa dòng sản lượng
 *  finished_product_production_data của ngày, và ghi 1 dấu "đã gỡ" để dashboard
 *  (nạp danh sách từ production_plans) cũng ẩn theo. Nhờ vậy gỡ ở 1 trang là
 *  đồng bộ ẩn ở cả 2 trang, refresh không nạp lại.
 * ============================================================ */

/** Bảng đánh dấu sản phẩm đã bị gỡ khỏi danh sách nhập theo NGÀY (dashboard dùng để ẩn). */
function im_ensure_dismissed_products_table()
{
    static $done = false;
    if ($done) return;
    $done = true;
    db_query("CREATE TABLE IF NOT EXISTS im_dismissed_fg_products (
        id           INT(11) NOT NULL AUTO_INCREMENT,
        product_id   INT(11) NOT NULL,
        dismiss_date DATE NOT NULL,
        created_at   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (id),
        UNIQUE KEY uq_product_date (product_id, dismiss_date)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci");
}

/** Bỏ dấu "đã gỡ" của (product, ngày) — gọi khi ghi lại phiếu nhập thành phẩm để
 *  sản phẩm hiện lại trên dashboard. $created_at: 'Y-m-d H:i:s'/null (=hôm nay). */
function im_clear_dismissed_product($product_id, $created_at = null)
{
    $pid = (int) $product_id;
    if ($pid <= 0) return;
    im_ensure_dismissed_products_table();
    $d = im_sanitize_date($created_at !== null ? $created_at : date('Y-m-d'));
    if ($d === null) $d = date('Y-m-d');
    $d_safe = escape_string($d);
    db_query("DELETE FROM im_dismissed_fg_products WHERE product_id = $pid AND dismiss_date = '$d_safe'");
}

/**
 * Gỡ HẲN 1 sản phẩm khỏi danh sách nhập của NGÀY $date (Y-m-d):
 *   - Trừ lại finished_goods_inventory theo tổng SL fg_receipt_production của (product, ngày).
 *   - Xóa các dòng stock_imports fg_receipt_production của (product, ngày).
 *   - Xóa dòng sản lượng finished_product_production_data của (product, ngày).
 *   - Ghi dấu "đã gỡ" (product, ngày) để dashboard (nạp từ production_plans) ẩn theo.
 * investment_products tự ẩn nhờ không còn phiếu nhập; dashboard ẩn nhờ dấu "đã gỡ".
 */
function im_remove_day_product($product_id, $date)
{
    $pid = (int) $product_id;
    if ($pid <= 0)   return ['success' => false, 'message' => 'Thiếu sản phẩm.'];
    $d = im_sanitize_date($date);
    if ($d === null) return ['success' => false, 'message' => 'Ngày không hợp lệ.'];
    $d_safe = escape_string($d);

    // Tổng SL đã nhập của (product, ngày) để trừ lại tồn.
    $sum_row = db_fetch_row("SELECT COALESCE(SUM(quantity), 0) AS q, COUNT(*) AS c
                             FROM stock_imports
                             WHERE product_id = $pid
                               AND type_import = 'fg_receipt_production'
                               AND DATE(created_at) = '$d_safe'");
    $qty   = $sum_row ? (float) $sum_row['q'] : 0.0;
    $count = $sum_row ? (int) $sum_row['c'] : 0;

    // Trừ lại finished_goods_inventory theo đúng số đã cộng khi nhập.
    if ($qty != 0) {
        $stock_row = db_fetch_row("SELECT quantity FROM finished_goods_inventory WHERE product_id = $pid LIMIT 1");
        if ($stock_row) {
            db_update('finished_goods_inventory',
                ['quantity' => (float) $stock_row['quantity'] - $qty],
                "product_id = $pid");
        }
    }

    // Xóa phiếu nhập thành phẩm của ngày + dòng sản lượng theo ngày.
    if ($count > 0) {
        db_query("DELETE FROM stock_imports
                  WHERE product_id = $pid
                    AND type_import = 'fg_receipt_production'
                    AND DATE(created_at) = '$d_safe'");
    }
    im_fpp_delete($pid, $d . ' 00:00:00');

    // Ghi dấu "đã gỡ" để dashboard ẩn sản phẩm này trong ngày.
    im_ensure_dismissed_products_table();
    db_query("INSERT IGNORE INTO im_dismissed_fg_products (product_id, dismiss_date, created_at)
              VALUES ($pid, '$d_safe', NOW())");

    return ['success' => true, 'removed' => $count, 'quantity' => $qty];
}

/* ============================================================
 *  SALES RETURN — page sales_return_receipt
 *  Hàng bán bị KH trả lại, đẩy vào kho tạm và xử lý sau.
 * ============================================================ */

/** Tìm khách hàng theo name (lọc cho ô #customer). */
function im_search_customers($keyword)
{
    $keyword = trim($keyword);
    if ($keyword === '') return [];
    $kw = escape_string($keyword);
    $sql = "SELECT id, name, short_name
            FROM customers
            WHERE name LIKE '%$kw%' OR short_name LIKE '%$kw%'
            ORDER BY name ASC
            LIMIT 15";
    return db_fetch_array($sql) ?: [];
}

/** Lấy 1 khách hàng theo id. */
function im_get_customer($customer_id)
{
    $cid = (int) $customer_id;
    if ($cid <= 0) return null;
    // secondary_color: dùng để tô màu nhận diện tên viết tắt của khách trên phiếu
    // (cùng màu đang dùng ở card đơn hàng / phiếu soạn hàng).
    return db_fetch_row("SELECT id, name, short_name, address, receiver, phone, secondary_color
                         FROM customers WHERE id = $cid LIMIT 1") ?: null;
}

/** Nhắc "trước bốc hàng" theo chi nhánh (Điểm nhắc) — mảng note áp dụng cho $customer_id.
 *  Dùng ở sales_delivery_note.php sau khi chọn khách hàng — xem [[reminder-points-module]]. */
function im_get_branch_pickup_reminders($customer_id)
{
    require_once __DIR__ . '/../../../libraries/reminder_points.php';
    rp_ensure_tables();
    return rp_get_branch_reminders_for_customer($customer_id);
}

/** Xóa 1 lời nhắc "trước bốc hàng" — nghĩa là đã thực thi/đã đọc. */
function im_delete_branch_pickup_reminder($id)
{
    require_once __DIR__ . '/../../../libraries/reminder_points.php';
    rp_ensure_tables();
    return rp_delete_branch_reminder($id);
}

/** Cộng vào tồn finished_goods_inventory (dùng khi handle = nhập kho trực tiếp / thay bao bì). */
function im_add_to_inventory($product_id, $qty)
{
    $pid = (int) $product_id;
    $q   = (float) $qty;
    if ($pid <= 0 || $q == 0) return false;

    $existing = db_fetch_row("SELECT id, quantity FROM finished_goods_inventory WHERE product_id = $pid LIMIT 1");
    if ($existing) {
        $new_stock = (float) $existing['quantity'] + $q;
        db_update('finished_goods_inventory', ['quantity' => $new_stock], "product_id = $pid");
    } else {
        db_insert('finished_goods_inventory', [
            'product_id' => $pid,
            'quantity'   => $q,
        ]);
    }
    return true;
}

/**
 * Ghi 1 dòng vào sales_returns + 1 dòng vào stock_imports (kho tạm — KHÔNG cộng tồn).
 * Trả về [sales_return_id, import_id] hoặc false nếu lỗi.
 */
function im_record_sales_return($product_id, $customer_id, $qty, $reason, $interpretation, $created_at = null)
{
    $pid = (int) $product_id;
    $cid = (int) $customer_id;
    $q   = (float) $qty;
    if ($pid <= 0 || $cid <= 0 || $q <= 0) return false;

    $ca = im_sanitize_datetime($created_at);

    $sr_data = [
        'product_id'      => $pid,
        'customer_id'     => $cid,
        'quantity'        => $q,
        'reason'          => (string) $reason,
        'handling_method' => 'Chờ xử lý',
    ];
    if ($ca !== null) $sr_data['created_at'] = $ca;
    $sr_id = db_insert('sales_returns', $sr_data);

    $si_data = [
        'product_id'     => $pid,
        'quantity'       => $q,
        'interpretation' => (string) $interpretation,
        'type_import'    => 'sales_return_receipt',
    ];
    if ($ca !== null) $si_data['created_at'] = $ca;
    $si_id = db_insert('stock_imports', $si_data);

    return ['sales_return_id' => (int) $sr_id, 'import_id' => (int) $si_id];
}

/**
 * Lấy các batch sales_return gần nhất, group theo created_at + customer_id.
 * Mỗi batch trả về:
 *   group_key, created_at, date_display, customer_id, customer_name, customer_short,
 *   summary, items: [{sales_return_id, product_id, product_name, quantity, reason, handling_method}]
 */
function im_get_recent_sales_return_batches($limit = 5)
{
    $limit = (int) $limit;
    if ($limit <= 0) $limit = 5;

    $sql_groups = "SELECT DATE_FORMAT(created_at, '%Y-%m-%d %H:%i:%s') AS group_key,
                          customer_id,
                          MIN(created_at) AS created_at,
                          COUNT(*) AS n
                   FROM sales_returns
                   GROUP BY group_key, customer_id
                   ORDER BY created_at DESC
                   LIMIT $limit";
    $groups = db_fetch_array($sql_groups) ?: [];

    $batches = [];
    foreach ($groups as $g) {
        $key_safe = escape_string($g['group_key']);
        $cid      = (int) $g['customer_id'];

        $cust = db_fetch_row("SELECT name, short_name FROM customers WHERE id = $cid LIMIT 1");
        $cust_name  = $cust ? $cust['name']       : ('#' . $cid);
        $cust_short = $cust ? ($cust['short_name'] ?: $cust['name']) : ('#' . $cid);

        $sql_items = "SELECT sr.id AS sales_return_id,
                             sr.product_id,
                             sr.quantity,
                             sr.reason,
                             sr.handling_method,
                             sr.created_at,
                             p.product_name
                      FROM sales_returns sr
                      LEFT JOIN products p ON p.id = sr.product_id
                      WHERE DATE_FORMAT(sr.created_at, '%Y-%m-%d %H:%i:%s') = '$key_safe'
                        AND sr.customer_id = $cid
                      ORDER BY sr.id ASC";
        $items_raw = db_fetch_array($sql_items) ?: [];

        $items = [];
        $name_qty_pairs = [];
        foreach ($items_raw as $it) {
            $name = $it['product_name'] ?: ('#' . (int) $it['product_id']);
            $qty  = rtrim(rtrim(number_format((float) $it['quantity'], 2, '.', ''), '0'), '.');
            $items[] = [
                'sales_return_id' => (int) $it['sales_return_id'],
                'product_id'      => (int) $it['product_id'],
                'product_name'    => $name,
                'quantity'        => (float) $it['quantity'],
                'reason'          => $it['reason'] ?: '',
                'handling_method' => $it['handling_method'] ?: '',
            ];
            $name_qty_pairs[] = $name . ' ' . $qty;
        }

        $summary = 'Nhập hàng trả ' . $cust_short . ': ' . implode(', ', $name_qty_pairs);
        $max_len = 160;
        if (mb_strlen($summary, 'UTF-8') > $max_len) {
            $summary = mb_substr($summary, 0, $max_len, 'UTF-8') . '...';
        }

        $hist_date    = history_date_display($g['created_at']);
        $date_display = $hist_date['text'];
        $date_color   = $hist_date['color'];

        $batches[] = [
            'group_key'      => $g['group_key'],
            'created_at'     => $g['created_at'],
            'date_display'   => $date_display,
            'date_color'     => $date_color,
            'customer_id'    => $cid,
            'customer_name'  => $cust_name,
            'customer_short' => $cust_short,
            'summary'        => $summary,
            'items'          => $items,
        ];
    }
    return $batches;
}

/** Lấy 1 batch theo group_key + customer_id (gồm items chi tiết kèm import_id). */
function im_get_sales_return_batch($group_key, $customer_id)
{
    $key = escape_string($group_key);
    $cid = (int) $customer_id;
    if ($cid <= 0 || $key === '') return null;

    $cust = db_fetch_row("SELECT id, name, short_name, secondary_color FROM customers WHERE id = $cid LIMIT 1");
    if (!$cust) return null;

    $sql = "SELECT sr.id AS sales_return_id,
                   sr.product_id,
                   sr.quantity,
                   sr.reason,
                   sr.handling_method,
                   sr.created_at,
                   p.product_name
            FROM sales_returns sr
            LEFT JOIN products p ON p.id = sr.product_id
            WHERE DATE_FORMAT(sr.created_at, '%Y-%m-%d %H:%i:%s') = '$key'
              AND sr.customer_id = $cid
            ORDER BY sr.id ASC";
    $rows = db_fetch_array($sql) ?: [];
    if (empty($rows)) return null;

    $created_at = $rows[0]['created_at'];

    $items = array_map(function ($r) use ($key) {
        $pid = (int) $r['product_id'];
        $key_safe = escape_string($key);
        // ghép import_id từ stock_imports cùng product + cùng created_at + type sales_return
        $imp = db_fetch_row("SELECT id FROM stock_imports
                             WHERE product_id = $pid
                               AND DATE_FORMAT(created_at, '%Y-%m-%d %H:%i:%s') = '$key_safe'
                               AND type_import = 'sales_return_receipt'
                             ORDER BY id ASC
                             LIMIT 1");
        return [
            'sales_return_id' => (int) $r['sales_return_id'],
            'import_id'       => $imp ? (int) $imp['id'] : 0,
            'product_id'      => (int) $r['product_id'],
            'product_name'    => $r['product_name'] ?: ('#' . (int) $r['product_id']),
            'quantity'        => (float) $r['quantity'],
            'reason'          => $r['reason'] ?: '',
            'handling_method' => $r['handling_method'] ?: '',
        ];
    }, $rows);

    return [
        'group_key'      => $group_key,
        'created_at'     => $created_at,
        'customer_id'    => (int) $cust['id'],
        'customer_name'  => $cust['name'],
        'customer_short' => $cust['short_name'] ?: $cust['name'],
        // Màu nhận diện khách để tô tên viết tắt khi mở lại phiếu ở chế độ Sửa.
        'customer_color' => preg_match('/^#[0-9a-fA-F]{6}$/', (string) ($cust['secondary_color'] ?? ''))
            ? strtolower($cust['secondary_color']) : '',
        'items'          => $items,
    ];
}

/**
 * Cập nhật 1 dòng sales_return (qty + reason).
 * - stock_imports.quantity luôn được cập nhật theo qty mới.
 * - finished_goods_inventory chỉ thay đổi nếu handling_method đã từng cộng tồn
 *   (Nhập kho trực tiếp / Thay bao bì): delta = new_qty - old_qty.
 *   Các phương án không cộng tồn (Phối chế / Tiêu hủy / Chờ xử lý) → không đụng tồn.
 */
function im_update_sales_return_item($sales_return_id, $import_id, $new_qty, $new_reason, $new_interpretation)
{
    $sid = (int) $sales_return_id;
    if ($sid <= 0) return false;
    $row = db_fetch_row("SELECT product_id, quantity, handling_method FROM sales_returns WHERE id = $sid LIMIT 1");
    if (!$row) return false;

    $pid    = (int) $row['product_id'];
    $old_q  = (float) $row['quantity'];
    $new_q  = (float) $new_qty;
    $delta  = $new_q - $old_q;
    $method = (string) $row['handling_method'];

    $methods_add_stock = ['Nhập kho trực tiếp', 'Thay bao bì'];
    if ($delta != 0 && in_array($method, $methods_add_stock, true)) {
        im_add_to_inventory($pid, $delta);
    }

    db_update('sales_returns', [
        'quantity' => $new_q,
        'reason'   => (string) $new_reason,
    ], "id = $sid");

    $iid = (int) $import_id;
    if ($iid > 0) {
        db_update('stock_imports', [
            'quantity'       => $new_q,
            'interpretation' => (string) $new_interpretation,
        ], "id = $iid");
    }
    return true;
}

/**
 * Xóa 1 dòng sales_return + dòng stock_imports đi kèm.
 * Nếu handling_method trước đó đã cộng tồn (Nhập kho trực tiếp / Thay bao bì)
 * → trừ qty ra khỏi finished_goods_inventory để không bị tồn ảo.
 */
function im_delete_sales_return_item($sales_return_id, $import_id = 0)
{
    $sid = (int) $sales_return_id;
    if ($sid <= 0) return false;
    $row = db_fetch_row("SELECT product_id, quantity, handling_method FROM sales_returns WHERE id = $sid LIMIT 1");
    if (!$row) return false;

    $pid    = (int) $row['product_id'];
    $qty    = (float) $row['quantity'];
    $method = (string) $row['handling_method'];

    $methods_add_stock = ['Nhập kho trực tiếp', 'Thay bao bì'];
    if (in_array($method, $methods_add_stock, true)) {
        im_add_to_inventory($pid, -$qty);
    }

    db_query("DELETE FROM sales_returns WHERE id = $sid");

    $iid = (int) $import_id;
    if ($iid > 0) {
        db_query("DELETE FROM stock_imports WHERE id = $iid AND type_import = 'sales_return_receipt'");
    }
    return true;
}

/* ---------- sales_return: bút toán (transactions) ---------- */

/** Lấy customer_id + group_key của batch chứa $sales_return_id. */
function im_get_sales_return_batch_key($sales_return_id)
{
    $sid = (int) $sales_return_id;
    if ($sid <= 0) return null;
    $row = db_fetch_row("SELECT customer_id,
                                DATE_FORMAT(created_at, '%Y-%m-%d %H:%i:%s') AS group_key,
                                created_at
                         FROM sales_returns WHERE id = $sid LIMIT 1");
    if (!$row) return null;
    return [
        'customer_id' => (int) $row['customer_id'],
        'group_key'   => $row['group_key'],
        'created_at'  => $row['created_at'],
    ];
}

/** Lấy list sales_returns.id của 1 batch (group_key + customer_id). */
function im_get_sales_return_ids_for_batch($group_key, $customer_id)
{
    $key = escape_string((string) $group_key);
    $cid = (int) $customer_id;
    if ($key === '' || $cid <= 0) return [];
    $rows = db_fetch_array("SELECT id FROM sales_returns
                            WHERE customer_id = $cid
                              AND DATE_FORMAT(created_at, '%Y-%m-%d %H:%i:%s') = '$key'
                            ORDER BY id ASC") ?: [];
    return array_map(function ($r) { return (int) $r['id']; }, $rows);
}

/** Xóa các dòng transactions của 1 batch sales_return_receipt theo list sales_return_ids. */
function im_delete_sales_return_transactions(array $sales_return_ids)
{
    $ids = array_values(array_filter(array_map('intval', $sales_return_ids), function ($v) { return $v > 0; }));
    if (empty($ids)) return;
    $ids_sql = implode(',', $ids);
    db_query("DELETE FROM transactions
              WHERE reference_type = 'sales_return_receipt'
                AND reference_id IN ($ids_sql)");
}

/**
 * Đồng bộ bút toán cho 1 batch sales_return:
 *  - Doanh thu hàng trả: Dr 5212 / Cr 131  (amount = SUM(qty * system_price))
 *  - Giá vốn (theo handling_method từng item):
 *      'Nhập kho trực tiếp' / 'Thay bao bì' → Dr 155 / Cr 632
 *      'Phối chế lô sản xuất mới' / 'Tiêu hủy' → Dr 811 / Cr 632
 *    amount = SUM(qty * giá_vốn_1_sp) gom theo nhóm.
 *  Xóa hết transactions cũ của batch trước khi insert lại.
 *  reference_id = id sales_returns nhỏ nhất của batch.
 */
function im_sync_sales_return_transactions($group_key, $customer_id, $je = null)
{
    $key = (string) $group_key;
    $cid = (int) $customer_id;
    if ($key === '' || $cid <= 0) return;

    $key_safe = escape_string($key);
    $rows = db_fetch_array(
        "SELECT sr.id, sr.product_id, sr.quantity, sr.handling_method, sr.created_at,
                pp.system_price
         FROM sales_returns sr
         LEFT JOIN product_prices pp ON pp.product_id = sr.product_id
         WHERE sr.customer_id = $cid
           AND DATE_FORMAT(sr.created_at, '%Y-%m-%d %H:%i:%s') = '$key_safe'
         ORDER BY sr.id ASC"
    ) ?: [];

    $ids = array_map(function ($r) { return (int) $r['id']; }, $rows);
    im_delete_sales_return_transactions($ids);
    if (empty($rows)) return;

    $first_id   = (int) $rows[0]['id'];
    $created_at = $rows[0]['created_at'];

    $methods_add_stock = ['Nhập kho trực tiếp', 'Thay bao bì'];
    $methods_no_stock  = ['Phối chế lô sản xuất mới', 'Tiêu hủy'];

    $revenue_total       = 0.0;
    $cost_add_stock      = 0.0;
    $cost_no_stock       = 0.0;
    foreach ($rows as $r) {
        $pid    = (int) $r['product_id'];
        $qty    = (float) $r['quantity'];
        $method = (string) $r['handling_method'];
        $sp     = $r['system_price'] !== null ? (float) $r['system_price'] : 0.0;

        $revenue_total += $qty * $sp;

        $cost_unit = im_compute_product_cost_per_unit($pid);
        if (in_array($method, $methods_add_stock, true)) {
            $cost_add_stock += $qty * $cost_unit;
        } elseif (in_array($method, $methods_no_stock, true)) {
            $cost_no_stock  += $qty * $cost_unit;
        }
    }

    $tx_base = [
        'reference_type' => 'sales_return_receipt',
        'reference_id'   => $first_id,
    ];
    $ca = im_sanitize_datetime($created_at);
    if ($ca !== null) $tx_base['created_at'] = $ca;

    // Cặp chính (doanh thu hàng trả) — user override / đa bút toán qua $je.
    $resolved = im_je_resolve($je, '5212', '131', $revenue_total);
    je_insert_pairs('sales_return_receipt', $first_id, je_entries_from_payload($je, $resolved), $ca);
    if ($cost_add_stock > 0) {
        $base = array_merge($tx_base, ['amount' => $cost_add_stock]);
        db_insert('transactions', array_merge($base, ['account_code' => '155', 'type' => 'debit']));
        db_insert('transactions', array_merge($base, ['account_code' => '632', 'type' => 'credit']));
    }
    if ($cost_no_stock > 0) {
        $base = array_merge($tx_base, ['amount' => $cost_no_stock]);
        db_insert('transactions', array_merge($base, ['account_code' => '811', 'type' => 'debit']));
        db_insert('transactions', array_merge($base, ['account_code' => '632', 'type' => 'credit']));
    }
}

/** Tiện ích: sync theo bất kỳ sales_return_id nào còn lại trong batch. */
function im_sync_sales_return_transactions_by_id($any_sales_return_id, $je = null)
{
    $info = im_get_sales_return_batch_key($any_sales_return_id);
    if (!$info) return;
    im_sync_sales_return_transactions($info['group_key'], $info['customer_id'], $je);
}

/**
 * Xóa toàn bộ 1 batch sales_return: items + stock_imports + transactions.
 * Nếu item đã ở trạng thái "Nhập kho trực tiếp" / "Thay bao bì" thì trừ tồn ra.
 * Trả số dòng sales_returns đã xóa.
 */
function im_delete_sales_return_batch($group_key, $customer_id)
{
    $key = escape_string((string) $group_key);
    $cid = (int) $customer_id;
    if ($key === '' || $cid <= 0) return 0;

    $rows = db_fetch_array("SELECT id, product_id, quantity, handling_method
                            FROM sales_returns
                            WHERE customer_id = $cid
                              AND DATE_FORMAT(created_at, '%Y-%m-%d %H:%i:%s') = '$key'") ?: [];
    if (empty($rows)) return 0;

    $methods_add_stock = ['Nhập kho trực tiếp', 'Thay bao bì'];
    $sids = [];
    foreach ($rows as $r) {
        $sid = (int) $r['id'];
        $sids[] = $sid;
        $pid    = (int) $r['product_id'];
        $qty    = (float) $r['quantity'];
        $method = (string) $r['handling_method'];
        if (in_array($method, $methods_add_stock, true)) {
            im_add_to_inventory($pid, -$qty);
        }
    }

    im_delete_sales_return_transactions($sids);

    db_query("DELETE FROM sales_returns
              WHERE customer_id = $cid
                AND DATE_FORMAT(created_at, '%Y-%m-%d %H:%i:%s') = '$key'");
    db_query("DELETE FROM stock_imports
              WHERE type_import = 'sales_return_receipt'
                AND DATE_FORMAT(created_at, '%Y-%m-%d %H:%i:%s') = '$key'");

    return count($rows);
}

/* ============================================================
 *  SALES ISSUE — page sales_issue (Xuất kho bán hàng)
 *  Trừ tồn finished_goods_inventory + ghi stock_exports.
 * ============================================================ */

/**
 * Marker đặt vào stock_exports.interpretation cho item thuộc batch thiếu tồn.
 * Khi batch có thiếu tồn: chỉ lưu lịch sử vào stock_exports với marker này,
 * KHÔNG trừ tồn / KHÔNG ghi sales_warehouse_export_invoices / KHÔNG ghi transactions.
 * Dùng cùng marker để: (1) override summary lịch sử, (2) bỏ qua khi rollback tồn.
 */
function im_shortage_interp_text()
{
    return 'Chưa ghi dữ liệu do có hàng hóa đang thiếu tồn';
}

/**
 * Ghi 1 dòng stock_exports cho item thuộc batch thiếu tồn (chỉ lưu lịch sử).
 * KHÔNG đụng tồn finished_goods_inventory / material_inventory.
 * $type: 'product' | 'material'.
 */
function im_record_shortage_export($id, $type, $customer_id, $qty, $unit_price = 0, $total_amount = 0, $created_at = null)
{
    $i   = (int) $id;
    $cid = (int) $customer_id;
    $q   = (float) $qty;
    if ($i <= 0 || $cid <= 0 || $q <= 0) return 0;

    $is_material = ($type === 'material');
    $data = [
        'product_id'     => $is_material ? null : $i,
        'material_id'    => $is_material ? $i  : null,
        'customer_id'    => $cid,
        'quantity'       => $q,
        'unit_price'     => (float) $unit_price,
        'total_amount'   => (float) $total_amount,
        'interpretation' => im_shortage_interp_text(),
        'type_export'    => 'sales_issue',
    ];
    $ca = im_sanitize_datetime($created_at);
    if ($ca !== null) $data['created_at'] = $ca;

    return (int) db_insert('stock_exports', $data);
}

/**
 * Trừ tồn finished_goods_inventory + ghi 1 dòng stock_exports.
 * Trả về:
 *   ['ok' => true,  'export_id' => N]
 *   ['ok' => false, 'reason' => 'shortage', 'available' => x, 'requested' => y]
 *   ['ok' => false, 'reason' => 'invalid']
 */
function im_record_sales_issue($product_id, $customer_id, $qty, $unit_price = 0, $total_amount = 0, $interpretation = '', $created_at = null)
{
    $pid = (int) $product_id;
    $cid = (int) $customer_id;
    $q   = (float) $qty;
    if ($pid <= 0 || $cid <= 0 || $q <= 0) {
        return ['ok' => false, 'reason' => 'invalid'];
    }

    $row = db_fetch_row("SELECT id, quantity FROM finished_goods_inventory WHERE product_id = $pid LIMIT 1");
    $available = $row ? (float) $row['quantity'] : 0;

    if ($available < $q) {
        return [
            'ok'        => false,
            'reason'    => 'shortage',
            'available' => $available,
            'requested' => $q,
        ];
    }

    $new_stock = $available - $q;
    db_update('finished_goods_inventory', ['quantity' => $new_stock], "product_id = $pid");

    $data = [
        'product_id'     => $pid,
        'customer_id'    => $cid,
        'quantity'       => $q,
        'unit_price'     => (float) $unit_price,
        'total_amount'   => (float) $total_amount,
        'interpretation' => (string) $interpretation,
        'type_export'    => 'sales_issue',
    ];
    $ca = im_sanitize_datetime($created_at);
    if ($ca !== null) $data['created_at'] = $ca;

    $eid = db_insert('stock_exports', $data);

    // sales_inventory_issue_data: 1 row / (product, customer, ngày)
    im_sii_write_item([
        'id'           => $pid,
        'type'         => 'product',
        'quantity'     => $q,
        'unit_price'   => $unit_price,
        'total_amount' => $total_amount,
    ], $cid, $ca !== null ? $ca : $created_at);

    return ['ok' => true, 'export_id' => (int) $eid];
}

/**
 * Lấy các batch xuất kho bán hàng gần nhất.
 * Group theo (created_at, customer_id) — 1 phiếu = 1 lần Ghi.
 */
function im_get_recent_sales_issue_batches($limit = 5)
{
    $limit = (int) $limit;
    if ($limit <= 0) $limit = 5;

    $sql_groups = "SELECT DATE_FORMAT(created_at, '%Y-%m-%d %H:%i:%s') AS group_key,
                          customer_id,
                          MIN(created_at) AS created_at,
                          SUM(total_amount) AS total
                   FROM stock_exports
                   WHERE type_export = 'sales_issue'
                   GROUP BY group_key, customer_id
                   ORDER BY created_at DESC
                   LIMIT $limit";
    $groups = db_fetch_array($sql_groups) ?: [];

    $batches = [];
    foreach ($groups as $g) {
        $key_safe = escape_string($g['group_key']);
        $cid      = (int) $g['customer_id'];

        $cust = db_fetch_row("SELECT name, short_name, secondary_color FROM customers WHERE id = $cid LIMIT 1");
        $cust_short = $cust ? ($cust['short_name'] ?: $cust['name']) : ('#' . $cid);
        // Màu nhận diện chi nhánh (admin đặt ở manage_customer_list); mặc định đen.
        $cust_color = '#000000';
        if ($cust && preg_match('/^#[0-9a-fA-F]{6}$/', (string) ($cust['secondary_color'] ?? ''))) {
            $cust_color = strtolower($cust['secondary_color']);
        }

        $sql_items = "SELECT se.id AS export_id,
                             se.product_id,
                             se.quantity,
                             se.unit_price,
                             se.total_amount,
                             se.interpretation,
                             p.product_name
                      FROM stock_exports se
                      LEFT JOIN products p ON p.id = se.product_id
                      WHERE DATE_FORMAT(se.created_at, '%Y-%m-%d %H:%i:%s') = '$key_safe'
                        AND se.customer_id = $cid
                        AND se.type_export = 'sales_issue'
                      ORDER BY se.id ASC";
        $items_raw = db_fetch_array($sql_items) ?: [];

        $items = array_map(function ($it) {
            return [
                'export_id'    => (int) $it['export_id'],
                'product_id'   => (int) $it['product_id'],
                'product_name' => $it['product_name'] ?: ('#' . (int) $it['product_id']),
                'quantity'     => (float) $it['quantity'],
                'unit_price'   => (float) $it['unit_price'],
                'total_amount' => (float) $it['total_amount'],
            ];
        }, $items_raw);

        // Batch thiếu tồn nếu có bất kỳ row nào mang marker shortage interpretation.
        $shortage_text = im_shortage_interp_text();
        $is_shortage = false;
        foreach ($items_raw as $it) {
            if (trim((string) ($it['interpretation'] ?? '')) === $shortage_text) {
                $is_shortage = true;
                break;
            }
        }

        $total = (float) $g['total'];
        $total_fmt = number_format($total, 0, ',', ',') . ' đ';
        $summary = $is_shortage
            ? $shortage_text
            : ('Bán hàng ' . $cust_short . ' giá trị ' . $total_fmt);
        $max_len = 160;
        if (mb_strlen($summary, 'UTF-8') > $max_len) {
            $summary = mb_substr($summary, 0, $max_len, 'UTF-8') . '...';
        }

        $hist_date    = history_date_display($g['created_at']);
        $date_display = $hist_date['text'];
        $date_color   = $hist_date['color'];

        $batches[] = [
            'group_key'    => $g['group_key'],
            'created_at'   => $g['created_at'],
            'date_display' => $date_display,
            'date_color'   => $date_color,
            'customer_id'  => $cid,
            'customer_short' => $cust_short,
            'customer_color' => $cust_color,
            'total'        => $total,
            'total_fmt'    => $total_fmt,
            'summary'      => $summary,
            'is_shortage'  => $is_shortage,
            'items'        => $items,
        ];
    }
    return $batches;
}

/**
 * Áp dụng cách thức xử lý cho 1 dòng sales_return.
 * - "Nhập kho trực tiếp" / "Thay bao bì": cộng qty vào finished_goods_inventory.
 * - "Phối chế lô sản xuất mới" / "Tiêu hủy": chỉ cập nhật handling_method.
 * Bất kỳ trường hợp nào cũng cập nhật handling_method = $method.
 */
/**
 * Xử lý 1 dòng hàng trả. $handle_qty = null (hoặc >= SL dòng) -> xử lý TOÀN BỘ như trước.
 * 0 < $handle_qty < SL dòng (chỉ áp dụng khi dòng còn "Chờ xử lý") -> XỬ LÝ MỘT PHẦN:
 * tách dòng — phần đã xử lý thành 1 dòng MỚI (cùng batch: cùng product/customer/reason/
 * created_at) mang $method; dòng gốc giảm SL còn lại, vẫn "Chờ xử lý" để đợt sản xuất
 * sau xử lý tiếp. Mỗi đợt xử lý là 1 dòng riêng nên có thể chia nhiều giai đoạn.
 */
function im_handle_sales_return_item($sales_return_id, $method, $handle_qty = null)
{
    $sid = (int) $sales_return_id;
    if ($sid <= 0) return false;
    $method = trim((string) $method);
    if ($method === '') return false;

    $row = db_fetch_row("SELECT product_id, customer_id, quantity, reason, handling_method, created_at
                         FROM sales_returns WHERE id = $sid LIMIT 1");
    if (!$row) return false;

    $pid = (int) $row['product_id'];
    $qty = (float) $row['quantity'];
    $cur = (string) $row['handling_method'];

    $methods_add_stock = ['Nhập kho trực tiếp', 'Thay bao bì'];
    $methods_no_stock  = ['Phối chế lô sản xuất mới', 'Tiêu hủy'];
    $allowed = array_merge($methods_add_stock, $methods_no_stock);
    if (!in_array($method, $allowed, true)) return false;

    $cur_added = in_array($cur, $methods_add_stock, true);
    $new_added = in_array($method, $methods_add_stock, true);

    // Xử lý MỘT PHẦN: chỉ cho dòng đang "Chờ xử lý" (dòng đã xử lý rồi chỉ đổi được
    // cách thức cho toàn bộ, không tách thêm).
    $hq = $handle_qty !== null ? (float) $handle_qty : null;
    if ($hq !== null && $hq > 0 && $hq < $qty && !$cur_added && !in_array($cur, $methods_no_stock, true)) {
        // Dòng gốc giữ phần CHƯA xử lý.
        db_update('sales_returns', ['quantity' => $qty - $hq], "id = $sid");
        // Dòng mới = phần vừa xử lý đợt này (cùng batch nhờ cùng created_at + customer).
        db_insert('sales_returns', [
            'product_id'      => $pid,
            'customer_id'     => (int) $row['customer_id'],
            'quantity'        => $hq,
            'reason'          => (string) $row['reason'],
            'handling_method' => $method,
            'created_at'      => (string) $row['created_at'],
        ]);
        if ($new_added) im_add_to_inventory($pid, $hq);
        return true;
    }

    if (!$cur_added && $new_added) {
        // Lần đầu cộng vào tồn
        im_add_to_inventory($pid, $qty);
    } elseif ($cur_added && !$new_added) {
        // Trước đã cộng, giờ chuyển sang phương án không cộng → trừ ra
        im_add_to_inventory($pid, -$qty);
    }
    // còn lại: cur_added && new_added (đã cộng rồi, không cộng nữa)
    //          hoặc !cur_added && !new_added (chưa cộng & vẫn không cộng)

    db_update('sales_returns', ['handling_method' => $method], "id = $sid");
    return true;
}

/* ============================================================
 *  INVESTMENT PRODUCTION — page investment_products
 *  Nhập giá vốn sản xuất: cập nhật product_materials.quantity_required
 *  + ghi 1 batch lịch sử (stock_imports type='investment_production').
 *  KHÔNG ảnh hưởng finished_goods_inventory.
 * ============================================================ */

/** Thêm cột products.exclude_production_cost (SP như "mẫu" không tính "Chi phí sản xuất"). */
function im_ensure_exclude_production_cost_column()
{
    $col = db_fetch_row("SELECT COLUMN_NAME FROM information_schema.COLUMNS
                         WHERE TABLE_SCHEMA = DATABASE()
                           AND TABLE_NAME = 'products'
                           AND COLUMN_NAME = 'exclude_production_cost' LIMIT 1");
    if (!$col) {
        db_query("ALTER TABLE products ADD COLUMN exclude_production_cost TINYINT(1) NOT NULL DEFAULT 0");
    }
}

/** 1 sản phẩm có bị loại khỏi "Chi phí sản xuất" (overhead/đơn vị) hay không. */
function im_product_excludes_production_cost($product_id)
{
    im_ensure_exclude_production_cost_column();
    $pid = (int) $product_id;
    if ($pid <= 0) return false;
    $row = db_fetch_row("SELECT exclude_production_cost FROM products WHERE id = $pid LIMIT 1");
    return $row && (int) $row['exclude_production_cost'] === 1;
}

/** Bật/tắt loại trừ "Chi phí sản xuất" cho 1 sản phẩm — nút × trên dòng "Chi phí sản xuất"
 *  ở investment_products.php (SP như "mẫu" không tính overhead). Ảnh hưởng hồi tố cả
 *  TASK 3a (investment_products) lẫn TASK 3b (sales_delivery_note COGS) — xem
 *  im_sales_cogs_for_items()/im_sales_cogs_breakdown_for_items(). */
function im_set_exclude_production_cost($product_id, $exclude)
{
    im_ensure_exclude_production_cost_column();
    $pid = (int) $product_id;
    if ($pid <= 0) return false;
    return db_update('products', ['exclude_production_cost' => $exclude ? 1 : 0], "id = {$pid}");
}

/**
 * Lấy danh sách item đầu tư (investment_products) theo NGÀY (Y-m-d).
 * Source là stock_imports với type_import = 'fg_receipt_production' để đồng bộ với
 * sản lượng thực đã được nhập kho — gom theo product_id và SUM(quantity).
 * Mỗi item kèm materials (product_materials + giá nhập gần nhất) và system_price.
 */
function im_get_investment_items_for_date($date)
{
    require_once __DIR__ . '/../../../libraries/reminder_points.php';
    require_once __DIR__ . '/../../../libraries/customer_packaging.php';
    rp_ensure_tables();
    im_ensure_exclude_production_cost_column();

    $d = im_sanitize_date($date);
    if ($d === null) return [];
    $d_safe = escape_string($d);

    $sql = "SELECT si.product_id, SUM(si.quantity) AS quantity
            FROM stock_imports si
            WHERE si.type_import = 'fg_receipt_production'
              AND DATE(si.created_at) = '$d_safe'
            GROUP BY si.product_id
            ORDER BY si.product_id ASC";
    $rows = db_fetch_array($sql) ?: [];

    // Bản đồ product_id -> combo khách hàng/bao bì đã thiết lập (module customer_packaging),
    // dùng để hiện banner nhắc "Trừ bao bì khách hàng nếu có". Không bao giờ được phép làm
    // hỏng luồng nhập giá vốn nếu module chưa dùng/bảng chưa có (cp_products_setup_map tự guard).
    $cpMap = cp_products_setup_map(array_map(function ($r) { return (int) $r['product_id']; }, $rows));

    $items = [];
    foreach ($rows as $r) {
        $pid = (int) $r['product_id'];
        $qty = (float) $r['quantity'];

        $p = db_fetch_row("SELECT product_name, exclude_production_cost FROM products WHERE id = $pid LIMIT 1");
        if (!$p) continue;

        $price_row    = db_fetch_row("SELECT system_price FROM product_prices WHERE product_id = $pid LIMIT 1");
        $system_price = $price_row ? (float) $price_row['system_price'] : 0;

        $sql_m = "SELECT pm.material_id,
                         pm.quantity_required,
                         mi.material_name,
                         mi.classification,
                         (SELECT COALESCE(mpp.purchase_price_includes_purchase_cost, mpp.purchase_price)
                          FROM material_purchase_prices mpp
                          WHERE mpp.material_id = pm.material_id
                          ORDER BY mpp.last_updated_at DESC, mpp.id DESC
                          LIMIT 1) AS purchase_price
                  FROM product_materials pm
                  LEFT JOIN material_information mi ON mi.id = pm.material_id
                  WHERE pm.product_id = $pid
                  ORDER BY pm.id ASC";
        $mats = db_fetch_array($sql_m) ?: [];
        $materials = array_map(function ($m) {
            return [
                'material_id'       => (int) $m['material_id'],
                'material_name'     => $m['material_name'] ?: ('#' . (int) $m['material_id']),
                'classification'    => (string) ($m['classification'] ?? ''),
                'quantity_required' => (float) $m['quantity_required'],
                'purchase_price'    => $m['purchase_price'] !== null ? (float) $m['purchase_price'] : 0,
            ];
        }, $mats);

        $items[] = [
            'product_id'    => $pid,
            'product_name'  => $p['product_name'],
            'quantity'      => $qty,
            'system_price'  => $system_price,
            'materials'     => $materials,
            'reminder_note' => rp_get_pre_input_note($pid),
            'customer_pkg'  => $cpMap[$pid] ?? [],
            'exclude_production_cost' => (int) $p['exclude_production_cost'] === 1,
        ];
    }
    return $items;
}

/**
 * TASK 2 (investment_products): truy vấn NGƯỢC tìm 1 lần sản xuất TRƯỚC ĐÓ của cùng
 * $product_id có sản lượng (finished_product_production_data.quantity) lệch ≤ 3% so với
 * $quantity (iqt = số thành phẩm của lần nhập hiện tại), rồi trả lại danh sách NVL đã
 * xuất (raw_material_production_issue_data) của lần đó.
 *
 * Mục đích: tái sử dụng các total_qty NVL của lần sản xuất có số lượng SP tương đồng để
 * khỏi phải chỉnh tay (cùng lượng NVL → ra số sản phẩm gần giống nhau).
 *
 * Tỷ lệ lệch = (quantity − iqt) / quantity × 100  (theo đúng công thức yêu cầu);
 * khớp khi nằm trong [−3%, 3%]. Lấy bản ghi GẦN NHẤT (created_at DESC) trong ngưỡng,
 * KHÔNG tính ngày hiện tại ($exclude_date) để không tự đối chiếu chính nó.
 *
 * @return array ['matched'=>bool, 'source_date'=>?string (Y-m-d), 'source_quantity'=>float,
 *                'deviation'=>float (%), 'materials'=>[['material_id','total_qty','unit_price'], ...]]
 */
function im_get_similar_production_issue($product_id, $quantity, $exclude_date = '')
{
    $pid  = (int) $product_id;
    $iqt  = (float) $quantity;
    $none = ['matched' => false, 'source_date' => null, 'source_quantity' => 0, 'deviation' => 0, 'materials' => []];
    if ($pid <= 0 || $iqt <= 0) return $none;

    $iqt_sql = (float) $iqt; // số thuần — an toàn để nội suy vào SQL

    // Loại trừ ngày hiện tại (nếu parse được) để không đối chiếu với chính lần nhập này.
    $cond_excl = '';
    $ed = im_sanitize_date($exclude_date);
    if ($ed !== null) $cond_excl = "AND DATE(f.created_at) <> '" . escape_string($ed) . "'";

    // Lần sản xuất gần nhất có sản lượng > 0, có xuất NVL, và lệch ≤ 3%.
    $sql = "SELECT f.quantity, DATE(f.created_at) AS d
            FROM finished_product_production_data f
            WHERE f.product_id = $pid
              AND f.quantity > 0
              $cond_excl
              AND ABS((f.quantity - $iqt_sql) / f.quantity) <= 0.03
              AND EXISTS (
                  SELECT 1 FROM raw_material_production_issue_data r
                  WHERE r.product_id = f.product_id
                    AND DATE(r.created_at) = DATE(f.created_at)
                    AND r.quantity > 0
              )
            ORDER BY f.created_at DESC, f.id DESC
            LIMIT 1";
    $row = db_fetch_row($sql);
    if (!$row) return $none;

    $src_qty  = (float) $row['quantity'];
    $src_date = (string) $row['d'];
    $d_safe   = escape_string($src_date);

    $sql_m = "SELECT material_id, quantity AS total_qty, unit_price
              FROM raw_material_production_issue_data
              WHERE product_id = $pid AND DATE(created_at) = '$d_safe' AND quantity > 0
              ORDER BY id ASC";
    $mats = db_fetch_array($sql_m) ?: [];
    $materials = array_map(function ($m) {
        return [
            'material_id' => (int) $m['material_id'],
            'total_qty'   => (float) $m['total_qty'],
            'unit_price'  => $m['unit_price'] !== null ? (float) $m['unit_price'] : 0,
        ];
    }, $mats);

    if (empty($materials)) return $none;

    return [
        'matched'         => true,
        'source_date'     => $src_date,
        'source_quantity' => $src_qty,
        'deviation'       => $src_qty > 0 ? round(($src_qty - $iqt) / $src_qty * 100, 2) : 0,
        'materials'       => $materials,
    ];
}

/** Cập nhật product_materials.quantity_required theo (product_id, material_id). */
function im_update_product_material_qty($product_id, $material_id, $new_qty_required)
{
    $pid = (int) $product_id;
    $mid = (int) $material_id;
    if ($pid <= 0 || $mid <= 0) return false;
    $row = db_fetch_row("SELECT id FROM product_materials
                         WHERE product_id = $pid AND material_id = $mid
                         LIMIT 1");
    if (!$row) return false;
    $iid = (int) $row['id'];
    db_update('product_materials', ['quantity_required' => (float) $new_qty_required], "id = $iid");
    return true;
}

/**
 * Tìm NVL theo keyword cho dropdown gợi ý ở .name-material (page investment_products).
 * Trả mỗi item kèm purchase_price mới nhất để autofill giá ngay khi user chọn.
 */
function im_search_materials($keyword)
{
    $kw = trim((string) $keyword);
    if ($kw === '') return [];
    $k   = escape_string($kw);
    $sql = "SELECT mi.id,
                   mi.material_name,
                   mi.classification,
                   (SELECT COALESCE(mpp.purchase_price_includes_purchase_cost, mpp.purchase_price)
                    FROM material_purchase_prices mpp
                    WHERE mpp.material_id = mi.id
                    ORDER BY mpp.last_updated_at DESC, mpp.id DESC
                    LIMIT 1) AS purchase_price
            FROM material_information mi
            WHERE mi.material_name LIKE '%$k%'
            ORDER BY mi.material_name ASC
            LIMIT 15";
    $rows = db_fetch_array($sql) ?: [];
    return array_map(function ($r) {
        return [
            'id'             => (int) $r['id'],
            'material_name'  => $r['material_name'],
            'classification' => (string) ($r['classification'] ?? ''),
            'purchase_price' => $r['purchase_price'] !== null ? (float) $r['purchase_price'] : 0,
        ];
    }, $rows);
}

/**
 * Cập nhật toàn bộ định mức (BOM) của 1 product trong product_materials theo
 * danh sách hiện trên giao diện — kiểu "tìm kiếm & thay thế":
 *   - Xoá hết product_materials của product_id rồi insert lại theo đúng thứ tự
 *     hiển thị (id tăng dần ⇒ giữ thứ tự top→bottom khi đọc ORDER BY pm.id ASC).
 *   - Dedupe theo material_id (giữ vị trí xuất hiện đầu tiên, value cuối cùng).
 *   - product_materials.id không bị tham chiếu FK bởi bảng nào nên xoá/insert an toàn.
 * $materials = [{material_id, quantity_required}, ...]. Trả false nếu rỗng (không
 * cho phép xoá sạch định mức bằng cách gửi list trống).
 */
function im_update_product_materials($product_id, $materials)
{
    $pid = (int) $product_id;
    if ($pid <= 0) return false;

    $clean = [];
    foreach ((array) $materials as $m) {
        $mid = (int) ($m['material_id'] ?? 0);
        if ($mid <= 0) continue;
        $qr = (float) ($m['quantity_required'] ?? 0);
        if ($qr < 0) $qr = 0;
        $clean[$mid] = $qr;
    }
    if (empty($clean)) return false;

    db_delete('product_materials', "product_id = $pid");
    foreach ($clean as $mid => $qr) {
        db_insert('product_materials', [
            'product_id'        => $pid,
            'material_id'       => $mid,
            'quantity_required' => $qr,
        ]);
    }
    return true;
}

/**
 * Build interpretation cho 1 batch investment theo product_ids: 'Nhập giá vốn sản
 * xuất các sản phẩm A, B, C' — tự cắt 160 ký tự (kèm dấu ...).
 */
function im_build_investment_interpretation($product_ids)
{
    $pids = [];
    foreach ((array) $product_ids as $id) {
        $i = (int) $id;
        if ($i > 0) $pids[$i] = $i;
    }
    if (empty($pids)) return '';
    $ids_csv = implode(',', $pids);
    $rows = db_fetch_array("SELECT id, product_name FROM products WHERE id IN ($ids_csv)") ?: [];
    // Giữ thứ tự theo $pids
    $name_by_id = [];
    foreach ($rows as $r) {
        $name_by_id[(int) $r['id']] = $r['product_name'] ?: ('#' . (int) $r['id']);
    }
    $names = [];
    foreach ($pids as $pid) {
        if (isset($name_by_id[$pid])) $names[] = $name_by_id[$pid];
    }
    $summary = 'Nhập giá vốn sản xuất các sản phẩm ' . implode(', ', $names);
    $max_len = 160;
    if (mb_strlen($summary, 'UTF-8') > $max_len) {
        $summary = mb_substr($summary, 0, $max_len, 'UTF-8') . '...';
    }
    return $summary;
}

/** Lấy id của material_purchase_prices (dòng mới nhất theo last_updated_at + id) cho 1 material. */
function im_get_latest_material_price_id($material_id)
{
    $mid = (int) $material_id;
    if ($mid <= 0) return 0;
    $row = db_fetch_row("SELECT id FROM material_purchase_prices
                         WHERE material_id = $mid
                         ORDER BY last_updated_at DESC, id DESC
                         LIMIT 1");
    return $row ? (int) $row['id'] : 0;
}

/**
 * Upsert giá mua ĐÃ GỒM CHI PHÍ MUA HÀNG cho 1 material trong material_purchase_prices.
 * Khi user sửa .input-cost ở page investment_products: tổng giá vốn / total_qty = đơn giá
 * đã bao gồm chi phí mua → ghi vào cột purchase_price_includes_purchase_cost (KHÔNG đụng
 * purchase_price). Lúc load giá lên view ưu tiên cột này (COALESCE), null mới lấy purchase_price.
 * - Nếu đã có row mới nhất cho material_id → update purchase_price_includes_purchase_cost.
 * - Nếu chưa có → insert row mới.
 * Trả id của row đã update/insert.
 */
function im_save_material_purchase_price($material_id, $price)
{
    $mid = (int) $material_id;
    $p   = (int) round((float) $price);
    if ($mid <= 0 || $p < 0) return 0;

    $rid = im_get_latest_material_price_id($mid);
    if ($rid > 0) {
        db_update('material_purchase_prices', ['purchase_price_includes_purchase_cost' => $p], "id = $rid");
        return $rid;
    }
    return (int) db_insert('material_purchase_prices', [
        'material_id'                           => $mid,
        'purchase_price_includes_purchase_cost' => $p,
    ]);
}

/** Cộng `delta` (có thể âm) vào tồn material; tự tạo dòng nếu chưa có. */
function im_adjust_material_inventory($material_id, $delta)
{
    $mid = (int) $material_id;
    $d   = (float) $delta;
    if ($mid <= 0 || $d == 0) return false;
    $row = db_fetch_row("SELECT id, quantity FROM material_inventory
                         WHERE material_id = $mid LIMIT 1");
    if ($row) {
        $new_qty = (float) $row['quantity'] + $d;
        db_update('material_inventory', ['quantity' => $new_qty], 'id = ' . (int) $row['id']);
    } else {
        db_insert('material_inventory', [
            'material_id' => $mid,
            'quantity'    => $d,
        ]);
    }
    return true;
}

/**
 * Đối chiếu tồn NVL trước khi xuất sản xuất (gọi TRƯỚC im_record_investment, tức
 * TRƯỚC khi material_inventory bị trừ, để n1 là tồn thật tại thời điểm Ghi).
 * Gộp total_qty theo material_id trên toàn bộ $items (nhiều product có thể dùng
 * chung 1 NVL trong cùng 1 lần Ghi).
 * n1 = tồn hiện tại, q1 = tổng SL xuất, k = n1 - q1.
 *   k < 0            → 'insufficient' (không đủ tồn để xuất).
 *   k*100/n1 < 50    → 'low' (tồn còn lại dưới 50%, sắp hết).
 * Trả về [{material_id, material_name, unit, n1, q1, k, type}, ...].
 */
function im_check_material_stock_warnings($items)
{
    if (!is_array($items) || empty($items)) return [];

    $qty_by_material = [];
    foreach ($items as $it) {
        $mats = isset($it['materials']) && is_array($it['materials']) ? $it['materials'] : [];
        foreach ($mats as $m) {
            $mid       = (int) ($m['material_id'] ?? 0);
            $total_qty = (float) ($m['total_qty']  ?? 0);
            if ($mid <= 0 || $total_qty <= 0) continue;
            $qty_by_material[$mid] = ($qty_by_material[$mid] ?? 0) + $total_qty;
        }
    }
    if (empty($qty_by_material)) return [];

    $warnings = [];
    foreach ($qty_by_material as $mid => $q1) {
        $row = db_fetch_row("SELECT quantity FROM material_inventory WHERE material_id = $mid LIMIT 1");
        $n1  = $row ? (float) $row['quantity'] : 0.0;
        $k   = $n1 - $q1;

        $type = null;
        if ($k < 0) {
            $type = 'insufficient';
        } elseif ($n1 > 0 && ($k * 100 / $n1) < 50) {
            $type = 'low';
        }
        if ($type === null) continue;

        $info = db_fetch_row("SELECT material_name, common_material_name, unit FROM material_information WHERE id = $mid LIMIT 1");
        $materialName = $info && $info['material_name'] !== null ? $info['material_name'] : ('#' . $mid);
        $commonName   = $info ? trim((string) ($info['common_material_name'] ?? '')) : '';
        $warnings[] = [
            'material_id'   => $mid,
            'material_name' => $materialName,
            'common_name'   => $commonName !== '' ? $commonName : $materialName,
            'unit'          => $info && $info['unit'] !== null ? $info['unit'] : '',
            'n1'            => $n1,
            'q1'            => $q1,
            'k'             => $k,
            'type'          => $type,
        ];
    }
    return $warnings;
}

/**
 * Trùng phiếu giá vốn sản xuất theo (product_id, ngày). Dùng cho cảnh báo trước khi Ghi.
 * Trả [{product_id, product_name, date_vn}, ...].
 */
function im_find_duplicate_investments($product_ids, $date)
{
    if (!is_array($product_ids) || empty($product_ids)) return [];
    $d = im_sanitize_date($date);
    if ($d === null) return [];
    $ids = [];
    foreach ($product_ids as $id) {
        $i = (int) $id;
        if ($i > 0) $ids[$i] = $i;
    }
    if (empty($ids)) return [];
    $ids_sql = implode(',', array_values($ids));
    $d_safe  = escape_string($d);

    $sql = "SELECT si.product_id,
                   p.product_name,
                   MIN(si.created_at) AS created_at
            FROM stock_imports si
            LEFT JOIN products p ON p.id = si.product_id
            WHERE si.product_id IN ($ids_sql)
              AND DATE(si.created_at) = '$d_safe'
              AND si.type_import = 'investment_production'
            GROUP BY si.product_id, p.product_name";
    $rows = db_fetch_array($sql) ?: [];
    return array_map(function ($r) {
        $ts = strtotime($r['created_at']);
        return [
            'product_id'   => (int) $r['product_id'],
            'product_name' => $r['product_name'] ?: ('#' . (int) $r['product_id']),
            'date_vn'      => $ts ? date('d/m/Y', $ts) : '',
            // group_key của phiếu cũ trùng (dùng cho nút "Ghi đè" -> im_delete_investment_batch).
            'group_key'    => $r['created_at'],
        ];
    }, $rows);
}

/**
 * Ghi 1 phiếu "Nhập giá vốn sản xuất". Đồng bộ 4 bảng:
 *   - stock_imports : N rows (1 row / product), type='investment_production',
 *                     interpretation = diễn giải full đã auto-build.
 *   - production_costs_daily : 1 row, stock_imports_id = id của row stock_imports
 *                              đầu tiên (làm khoá tham chiếu).
 *   - stock_exports : M rows (1 row / material / product), type='export_production'.
 *                     quantity = total_qty (input-total-qty), unit_price = purchase_price.
 *   - material_inventory : trừ tồn từng material theo total_qty (tạo mới nếu chưa có).
 *
 * Input shape:
 *   $items = [
 *     [
 *       'product_id' => int,
 *       'materials'  => [
 *         ['material_id'=>int, 'total_qty'=>float, 'unit_price'=>float, 'total_cost'=>float],
 *         ...
 *       ]
 *     ], ...
 *   ]
 *
 * Trả về stock_imports_id của row đầu tiên (khoá batch), hoặc 0 nếu fail.
 */
function im_record_investment($items, $cost_price, $goods_value, $created_at = null, $je = null)
{
    if (!is_array($items) || empty($items)) return 0;

    // Build product list theo thứ tự được truyền
    $product_ids = [];
    foreach ($items as $it) {
        $pid = (int) ($it['product_id'] ?? 0);
        if ($pid > 0) $product_ids[] = $pid;
    }
    if (empty($product_ids)) return 0;

    $ca      = im_sanitize_datetime($created_at);
    $summary = im_build_investment_interpretation($product_ids);

    // 1) Insert N stock_imports rows
    $first_si_id = 0;
    foreach ($product_ids as $pid) {
        $si_data = [
            'product_id'     => $pid,
            'quantity'       => 0,
            'interpretation' => $summary,
            'type_import'    => 'investment_production',
        ];
        if ($ca !== null) $si_data['created_at'] = $ca;
        $iid = (int) db_insert('stock_imports', $si_data);
        if ($first_si_id === 0 && $iid > 0) $first_si_id = $iid;
    }
    if ($first_si_id <= 0) return 0;

    // 2) Insert 1 production_costs_daily row
    $pcd_data = [
        'stock_imports_id' => $first_si_id,
        'cost_price'       => (float) $cost_price,
        'goods_value'      => (float) $goods_value,
    ];
    if ($ca !== null) $pcd_data['created_at'] = $ca;
    db_insert('production_costs_daily', $pcd_data);

    // 3) Insert M stock_exports rows + 4) trừ material_inventory
    //    + 5) Insert production_receipts (1 dòng / product) + production_materials
    //    + 6) Insert 2 dòng transactions (Dr 155 / Cr 152) cho cả batch
    $first_pr_id     = 0;
    $grand_cost      = 0.0;
    foreach ($items as $it) {
        $pid = (int) ($it['product_id'] ?? 0);
        if ($pid <= 0) continue;
        $mats = isset($it['materials']) && is_array($it['materials']) ? $it['materials'] : [];

        // production_receipts: 1 row / product (total_cost = sub-total[1], expected_value = sub-total[2])
        $product_qty       = (float) ($it['product_qty']    ?? 0);
        $product_total     = (float) ($it['total_cost']     ?? 0);
        $product_expected  = (float) ($it['expected_value'] ?? 0);
        $pr_data = [
            'product_id'     => $pid,
            'quantity'       => $product_qty,
            'total_cost'     => $product_total,
            'expected_value' => $product_expected,
        ];
        if ($ca !== null) $pr_data['created_at'] = $ca;
        $pr_id = (int) db_insert('production_receipts', $pr_data);
        if ($first_pr_id === 0 && $pr_id > 0) $first_pr_id = $pr_id;
        $grand_cost += $product_total;

        foreach ($mats as $m) {
            $mid       = (int) ($m['material_id'] ?? 0);
            $total_qty = (float) ($m['total_qty']  ?? 0);
            if ($mid <= 0 || $total_qty <= 0) continue;
            $unit_price  = (float) ($m['unit_price']  ?? 0);
            $total_cost  = (float) ($m['total_cost']  ?? ($total_qty * $unit_price));

            $se_data = [
                'product_id'     => $pid,
                'material_id'    => $mid,
                'customer_id'    => null,
                'quantity'       => $total_qty,
                'unit_price'     => $unit_price,
                'total_amount'   => $total_cost,
                'interpretation' => $summary,
                'type_export'    => 'export_production',
            ];
            if ($ca !== null) $se_data['created_at'] = $ca;
            db_insert('stock_exports', $se_data);

            im_adjust_material_inventory($mid, -$total_qty);

            // production_materials: 1 row / material / product
            if ($pr_id > 0) {
                $mpp_id = im_get_latest_material_price_id($mid);
                db_insert('production_materials', [
                    'production_receipt_id' => $pr_id,
                    'material_id'           => $mid,
                    'quantity'              => $total_qty,
                    'material_price_id'     => $mpp_id,
                    'total_cost'            => $total_cost,
                ]);
            }
        }
    }

    // 6) transactions: 2 dòng cho cả batch (Dr 155 thành phẩm / Cr 152 nguyên liệu).
    // Default amount: cost_price (POST) → tổng total_cost của các product → 0.
    // User có thể override Nợ/Có/Giá trị qua form GHI BÚT TOÁN KẾ TOÁN ($je).
    if ($first_pr_id > 0) {
        $default_amount = (float) $cost_price;
        if ($default_amount <= 0) $default_amount = $grand_cost;
        $resolved = im_je_resolve($je, '155', '152', $default_amount);
        je_insert_pairs('production', $first_pr_id, je_entries_from_payload($je, $resolved), $ca);
    }

    // 7) finished_product_production_data.production_cost += per-product total_cost
    //    raw_material_production_issue_data: 1 row / material / product.
    foreach ($items as $it) {
        $pid = (int) ($it['product_id'] ?? 0);
        if ($pid <= 0) continue;
        $product_total = (float) ($it['total_cost'] ?? 0);
        im_fpp_upsert_production_cost($pid, $product_total, $ca);
        im_rmpi_replace_for_product($pid, isset($it['materials']) ? $it['materials'] : [], $ca);
    }

    // Hook tea_scent_group: trừ NVL "kiểm soát" (nếu SP có thiết lập) theo product_qty.
    // group_key phải khớp CHÍNH XÁC với DATE_FORMAT(created_at,...) mà im_update/delete
    // dùng để định vị batch -> lấy lại created_at thật của row vừa insert thay vì tự build.
    if ($first_si_id > 0 && function_exists('tsg_on_investment_saved')) {
        $gk_row = db_fetch_row("SELECT DATE_FORMAT(created_at, '%Y-%m-%d %H:%i:%s') AS gk
                                 FROM stock_imports WHERE id = $first_si_id LIMIT 1");
        if ($gk_row && $gk_row['gk']) tsg_on_investment_saved($gk_row['gk'], $items);
    }

    return $first_si_id;
}

/**
 * Trả về các batch fg_receipt_production có ÍT NHẤT 1 sản phẩm chưa được ghi
 * investment_production cho cùng ngày. Cấu trúc giống im_get_recent_batches,
 * có thêm 'needs_investment' = true và 'type_import' = 'fg_receipt_production'.
 * items[] chỉ liệt kê các product_id còn thiếu giá vốn của batch đó.
 */
function im_get_pending_fg_batches_for_investment($limit = 100)
{
    $limit = (int) $limit;
    if ($limit <= 0) $limit = 100;

    $sql_groups = "SELECT DATE_FORMAT(created_at, '%Y-%m-%d %H:%i:%s') AS group_key,
                          MIN(created_at) AS created_at
                   FROM stock_imports
                   WHERE type_import = 'fg_receipt_production'
                   GROUP BY group_key
                   ORDER BY created_at DESC
                   LIMIT $limit";
    $groups = db_fetch_array($sql_groups) ?: [];

    $batches = [];
    foreach ($groups as $g) {
        $key_safe = escape_string($g['group_key']);
        $sql_items = "SELECT si.id AS import_id,
                             si.product_id,
                             si.quantity,
                             si.interpretation,
                             si.created_at,
                             p.product_name
                      FROM stock_imports si
                      LEFT JOIN products p ON p.id = si.product_id
                      WHERE DATE_FORMAT(si.created_at, '%Y-%m-%d %H:%i:%s') = '$key_safe'
                        AND si.type_import = 'fg_receipt_production'
                      ORDER BY si.id ASC";
        $items = db_fetch_array($sql_items) ?: [];
        if (empty($items)) continue;

        $date_only = date('Y-m-d', strtotime($g['created_at']));
        $d_safe    = escape_string($date_only);

        $pids = [];
        foreach ($items as $it) {
            $pid = (int) $it['product_id'];
            if ($pid > 0) $pids[$pid] = $pid;
        }
        if (empty($pids)) continue;
        $ids_sql = implode(',', array_values($pids));

        $sql_inv = "SELECT DISTINCT product_id
                    FROM stock_imports
                    WHERE type_import = 'investment_production'
                      AND DATE(created_at) = '$d_safe'
                      AND product_id IN ($ids_sql)";
        $inv_rows = db_fetch_array($sql_inv) ?: [];
        $covered = [];
        foreach ($inv_rows as $r) $covered[(int) $r['product_id']] = true;

        $missing = [];
        foreach ($items as $it) {
            $pid = (int) $it['product_id'];
            if (!isset($covered[$pid])) $missing[] = $it;
        }
        if (empty($missing)) continue;

        // Batch đã có ÍT NHẤT 1 sản phẩm được nhập giá vốn rồi (thường do sản phẩm
        // thiếu là vừa được thêm vào nhóm lúc Sửa ở dashboard) → đây là bổ sung,
        // không phải lần nhập giá vốn đầu tiên của cả nhóm.
        $is_supplement = !empty($covered);

        $names = array_map(function ($it) {
            return $it['product_name'] ?: ('#' . (int) $it['product_id']);
        }, $missing);
        $summary = ($is_supplement ? 'Cần nhập giá vốn bổ sung cho ' : 'Cần nhập giá vốn cho ') . implode(', ', $names);
        $max_len = 160;
        if (mb_strlen($summary, 'UTF-8') > $max_len) {
            $summary = mb_substr($summary, 0, $max_len, 'UTF-8') . '...';
        }

        $hist_date    = history_date_display($g['created_at']);
        $date_display = $hist_date['text'];
        $date_color   = $hist_date['color'];

        $batches[] = [
            'group_key'        => $g['group_key'],
            'created_at'       => $g['created_at'],
            'date_display'     => $date_display,
            'date_color'       => $date_color,
            'summary'          => $summary,
            'needs_investment' => true,
            'is_supplement'    => $is_supplement,
            'type_import'      => 'fg_receipt_production',
            'items'            => array_map(function ($it) {
                return [
                    'import_id'      => (int) $it['import_id'],
                    'product_id'     => (int) $it['product_id'],
                    'product_name'   => $it['product_name'],
                    'quantity'       => (float) $it['quantity'],
                    'interpretation' => $it['interpretation'] ?: '',
                ];
            }, $missing),
        ];
    }
    return $batches;
}

/**
 * Trả lịch sử cho page investment_products: gộp investment_production +
 * các batch fg_receipt_production còn thiếu giá vốn, sort theo created_at DESC,
 * cắt theo $limit.
 */
function im_get_investment_history_with_pending($limit = 100)
{
    $limit = (int) $limit;
    if ($limit <= 0) $limit = 100;
    $a = im_get_recent_batches($limit, 'investment_production');
    $b = im_get_pending_fg_batches_for_investment($limit);
    $merged = array_merge($a, $b);
    usort($merged, function ($x, $y) {
        $tx = strtotime($x['created_at'] ?? '');
        $ty = strtotime($y['created_at'] ?? '');
        if ($ty === $tx) return 0;
        return ($ty < $tx) ? -1 : 1;
    });
    if (count($merged) > $limit) $merged = array_slice($merged, 0, $limit);
    return $merged;
}

/** Lấy danh sách stock_exports rows (type=export_production) cho 1 group_key. */
function im_get_export_production_rows($group_key)
{
    $key = escape_string($group_key);
    if ($key === '') return [];
    $sql = "SELECT id, product_id, material_id, quantity, unit_price, total_amount
            FROM stock_exports
            WHERE DATE_FORMAT(created_at, '%Y-%m-%d %H:%i:%s') = '$key'
              AND type_export = 'export_production'
            ORDER BY id ASC";
    return db_fetch_array($sql) ?: [];
}

/**
 * Lấy chi tiết 1 batch investment để render lại trang ở chế độ Sửa.
 * Lấy material values từ stock_exports (giá trị HISTORY tại thời điểm Ghi),
 * KHÔNG dùng product_materials hiện tại (đã có thể thay đổi sau đó).
 *
 * Trả mảng items shape giống im_get_investment_items_for_date:
 *   [{ product_id, product_name, quantity, system_price,
 *      materials: [{material_id, material_name, quantity_required, purchase_price}] }, ...]
 *
 * Ở đây quantity_required = stock_exports.quantity / product_quantity (để công thức
 * "qr × qty = total_qty" khớp lại đúng giá trị history).
 */
function im_get_investment_batch_detail($group_key)
{
    $key = escape_string($group_key);
    if ($key === '') return [];

    // 1) Lấy product_ids của batch từ stock_imports (cùng created_at + type)
    $sql_si = "SELECT DISTINCT product_id, created_at
               FROM stock_imports
               WHERE DATE_FORMAT(created_at, '%Y-%m-%d %H:%i:%s') = '$key'
                 AND type_import = 'investment_production'";
    $si_rows = db_fetch_array($sql_si) ?: [];
    if (empty($si_rows)) return [];

    $created_at_full = $si_rows[0]['created_at'];
    $date_ymd        = date('Y-m-d', strtotime($created_at_full));

    // 2) Tính SUM(quantity) fg_receipt_production cho mỗi product trong NGÀY đó
    //    để khớp với "input-quantity" hiện trên giao diện.
    $items_by_date = [];
    foreach (im_get_investment_items_for_date($date_ymd) as $it) {
        $items_by_date[(int) $it['product_id']] = $it;
    }

    // 3) Override materials theo stock_exports cho batch
    $rows_ex = im_get_export_production_rows($group_key);
    $by_product = [];
    foreach ($rows_ex as $r) {
        $pid = (int) $r['product_id'];
        $by_product[$pid][] = $r;
    }

    $items = [];
    foreach ($si_rows as $sr) {
        $pid = (int) $sr['product_id'];
        $base = $items_by_date[$pid] ?? null;
        if (!$base) {
            // Fallback: vẫn dựng item cơ bản kể cả ngày đó không còn fg_receipt_production
            $p  = db_fetch_row("SELECT product_name FROM products WHERE id = $pid LIMIT 1");
            if (!$p) continue;
            $pp = db_fetch_row("SELECT system_price FROM product_prices WHERE product_id = $pid LIMIT 1");
            $base = [
                'product_id'   => $pid,
                'product_name' => $p['product_name'],
                'quantity'     => 0,
                'system_price' => $pp ? (float) $pp['system_price'] : 0,
                'materials'    => [],
            ];
        }
        $product_qty = (float) $base['quantity'];

        $materials = [];
        foreach (($by_product[$pid] ?? []) as $m) {
            $mid       = (int) $m['material_id'];
            $total_qty = (float) $m['quantity'];
            $price     = (float) $m['unit_price'];
            $qr        = $product_qty > 0 ? ($total_qty / $product_qty) : 0;
            $minfo     = db_fetch_row("SELECT material_name FROM material_information WHERE id = $mid LIMIT 1");
            $materials[] = [
                'material_id'       => $mid,
                'material_name'     => ($minfo && $minfo['material_name']) ? $minfo['material_name'] : ('#' . $mid),
                'quantity_required' => $qr,
                'purchase_price'    => $price,
            ];
        }

        $items[] = [
            'product_id'   => $pid,
            'product_name' => $base['product_name'],
            'quantity'     => $product_qty,
            'system_price' => (float) $base['system_price'],
            'materials'    => $materials,
            'exclude_production_cost' => (bool) ($base['exclude_production_cost'] ?? false),
        ];
    }
    return $items;
}

/**
 * Cập nhật 1 batch investment đang ở chế độ Sửa:
 *   - Đối chiếu old (stock_exports trong batch) vs new (items hiện tại):
 *       delta = new_total_qty - old_total_qty cho mỗi material.
 *       Trừ delta vào material_inventory (delta>0 nghĩa dùng thêm → tồn giảm).
 *   - Update stock_exports rows tương ứng với new values; chèn rows mới nếu material chưa có.
 *     (Không xoá row cũ — quantity new=0 vẫn được cập nhật để giữ history.)
 *   - Update production_costs_daily totals.
 */
function im_update_investment_batch($group_key, $items, $cost_price, $goods_value, $je = null)
{
    $key = escape_string($group_key);
    if ($key === '') return false;

    // Locate batch
    $first_si = db_fetch_row("SELECT id FROM stock_imports
                              WHERE DATE_FORMAT(created_at, '%Y-%m-%d %H:%i:%s') = '$key'
                                AND type_import = 'investment_production'
                              ORDER BY id ASC LIMIT 1");
    if (!$first_si) return false;
    $first_si_id = (int) $first_si['id'];

    // Snapshot old by (product_id, material_id) → row
    $old_by_key = [];
    foreach (im_get_export_production_rows($key) as $r) {
        $k = (int) $r['product_id'] . ':' . (int) $r['material_id'];
        $old_by_key[$k] = $r;
    }

    // Walk new items
    foreach ((array) $items as $it) {
        $pid = (int) ($it['product_id'] ?? 0);
        if ($pid <= 0) continue;
        $mats = isset($it['materials']) && is_array($it['materials']) ? $it['materials'] : [];
        foreach ($mats as $m) {
            $mid       = (int) ($m['material_id'] ?? 0);
            $new_qty   = (float) ($m['total_qty']  ?? 0);
            if ($mid <= 0 || $new_qty < 0) continue;
            $unit_price = (float) ($m['unit_price'] ?? 0);
            $total_cost = (float) ($m['total_cost'] ?? ($new_qty * $unit_price));

            $k = $pid . ':' . $mid;
            $old = $old_by_key[$k] ?? null;
            $old_qty = $old ? (float) $old['quantity'] : 0;
            $delta   = $new_qty - $old_qty; // > 0 → dùng thêm → tồn giảm
            if ($delta != 0) im_adjust_material_inventory($mid, -$delta);

            if ($old) {
                db_update('stock_exports', [
                    'quantity'     => $new_qty,
                    'unit_price'   => $unit_price,
                    'total_amount' => $total_cost,
                ], 'id = ' . (int) $old['id']);
            } else if ($new_qty > 0) {
                db_insert('stock_exports', [
                    'product_id'   => $pid,
                    'material_id'  => $mid,
                    'customer_id'  => null,
                    'quantity'     => $new_qty,
                    'unit_price'   => $unit_price,
                    'total_amount' => $total_cost,
                    'type_export'  => 'export_production',
                    // created_at lấy từ group_key gốc để gom cùng batch
                    'created_at'   => $key,
                ]);
            }
        }
    }

    // Cập nhật production_costs_daily
    $existing = db_fetch_row("SELECT id FROM production_costs_daily
                              WHERE stock_imports_id = $first_si_id LIMIT 1");
    if ($existing) {
        db_update('production_costs_daily', [
            'cost_price'  => (float) $cost_price,
            'goods_value' => (float) $goods_value,
        ], 'id = ' . (int) $existing['id']);
    } else {
        db_insert('production_costs_daily', [
            'stock_imports_id' => $first_si_id,
            'cost_price'       => (float) $cost_price,
            'goods_value'      => (float) $goods_value,
        ]);
    }

    // Đồng bộ production_receipts / production_materials / transactions:
    // delete-and-reinsert theo batch (created_at = group_key) — đơn giản & chính xác.
    $old_pr_rows = db_fetch_array("SELECT id FROM production_receipts
                                   WHERE DATE_FORMAT(created_at, '%Y-%m-%d %H:%i:%s') = '$key'") ?: [];
    if (!empty($old_pr_rows)) {
        $old_pr_ids = array_map(function ($r) { return (int) $r['id']; }, $old_pr_rows);
        $old_pr_csv = implode(',', $old_pr_ids);
        db_query("DELETE FROM production_materials WHERE production_receipt_id IN ($old_pr_csv)");
        db_query("DELETE FROM transactions
                  WHERE reference_type = 'production'
                    AND reference_id IN ($old_pr_csv)");
        db_query("DELETE FROM production_receipts WHERE id IN ($old_pr_csv)");
    }

    $first_pr_id = 0;
    $grand_cost  = 0.0;
    foreach ((array) $items as $it) {
        $pid = (int) ($it['product_id'] ?? 0);
        if ($pid <= 0) continue;

        $product_qty      = (float) ($it['product_qty']    ?? 0);
        $product_total    = (float) ($it['total_cost']     ?? 0);
        $product_expected = (float) ($it['expected_value'] ?? 0);

        $pr_data = [
            'product_id'     => $pid,
            'quantity'       => $product_qty,
            'total_cost'     => $product_total,
            'expected_value' => $product_expected,
            'created_at'     => $key,
        ];
        $pr_id = (int) db_insert('production_receipts', $pr_data);
        if ($first_pr_id === 0 && $pr_id > 0) $first_pr_id = $pr_id;
        $grand_cost += $product_total;

        $mats = isset($it['materials']) && is_array($it['materials']) ? $it['materials'] : [];
        foreach ($mats as $m) {
            $mid       = (int) ($m['material_id'] ?? 0);
            $total_qty = (float) ($m['total_qty']  ?? 0);
            if ($mid <= 0 || $total_qty <= 0 || $pr_id <= 0) continue;
            $unit_price = (float) ($m['unit_price'] ?? 0);
            $total_cost = (float) ($m['total_cost'] ?? ($total_qty * $unit_price));
            $mpp_id     = im_get_latest_material_price_id($mid);
            db_insert('production_materials', [
                'production_receipt_id' => $pr_id,
                'material_id'           => $mid,
                'quantity'              => $total_qty,
                'material_price_id'     => $mpp_id,
                'total_cost'            => $total_cost,
            ]);
        }
    }

    if ($first_pr_id > 0) {
        $default_amount = (float) $cost_price;
        if ($default_amount <= 0) $default_amount = $grand_cost;
        $resolved = im_je_resolve($je, '155', '152', $default_amount);
        je_insert_pairs('production', $first_pr_id, je_entries_from_payload($je, $resolved), $key);
    }

    // Sync finished_product_production_data.production_cost + raw_material_production_issue_data.
    // Dùng $key (= group_key gốc 'Y-m-d H:i:s') làm reference cho ngày.
    foreach ((array) $items as $it) {
        $pid = (int) ($it['product_id'] ?? 0);
        if ($pid <= 0) continue;
        $product_total = (float) ($it['total_cost'] ?? 0);
        im_fpp_upsert_production_cost($pid, $product_total, $key);
        im_rmpi_replace_for_product($pid, isset($it['materials']) ? $it['materials'] : [], $key);
    }

    // Hook tea_scent_group: idempotent theo group_key -> xoá usage cũ của batch rồi ghi lại.
    if (function_exists('tsg_on_investment_saved')) tsg_on_investment_saved($group_key, $items);

    return true;
}

/**
 * Xóa 1 batch investment theo group_key — đồng bộ 4 bảng:
 *   - Cộng lại quantity vào material_inventory (rollback).
 *   - Xóa stock_exports type=export_production của batch.
 *   - Xóa production_costs_daily theo stock_imports_id.
 *   - Xóa stock_imports rows.
 * KHÔNG đụng finished_goods_inventory.
 */
function im_delete_investment_batch($group_key)
{
    $key = escape_string($group_key);
    if ($key === '') return 0;

    // 0) Lấy product_ids của batch TRƯỚC khi delete để reset
    //    finished_product_production_data.production_cost và xoá rmpi.
    $batch_pids = db_fetch_array("SELECT DISTINCT product_id FROM stock_imports
                                  WHERE DATE_FORMAT(created_at, '%Y-%m-%d %H:%i:%s') = '$key'
                                    AND type_import = 'investment_production'") ?: [];
    foreach ($batch_pids as $r) {
        $pid = (int) $r['product_id'];
        if ($pid <= 0) continue;
        im_fpp_reset_production_cost($pid, $group_key);
        im_rmpi_delete_for_product($pid, $group_key);
    }

    // 1) Rollback material_inventory bằng tổng quantity của stock_exports
    $exp_rows = im_get_export_production_rows($key);
    foreach ($exp_rows as $r) {
        $mid = (int) $r['material_id'];
        $qty = (float) $r['quantity'];
        if ($mid > 0 && $qty != 0) im_adjust_material_inventory($mid, $qty);
    }

    // 2) Xóa stock_exports
    db_query("DELETE FROM stock_exports
              WHERE DATE_FORMAT(created_at, '%Y-%m-%d %H:%i:%s') = '$key'
                AND type_export = 'export_production'");

    // 3) Xóa stock_imports + production_costs_daily
    $si_rows = db_fetch_array("SELECT id FROM stock_imports
                               WHERE DATE_FORMAT(created_at, '%Y-%m-%d %H:%i:%s') = '$key'
                                 AND type_import = 'investment_production'") ?: [];
    $count = 0;
    if (!empty($si_rows)) {
        $ids = array_map(function ($r) { return (int) $r['id']; }, $si_rows);
        $ids_csv = implode(',', $ids);
        db_query("DELETE FROM production_costs_daily WHERE stock_imports_id IN ($ids_csv)");
        db_query("DELETE FROM stock_imports WHERE id IN ($ids_csv)");
        $count = count($ids);
    }

    // 4) Xóa production_receipts + production_materials + transactions của batch
    $pr_rows = db_fetch_array("SELECT id FROM production_receipts
                               WHERE DATE_FORMAT(created_at, '%Y-%m-%d %H:%i:%s') = '$key'") ?: [];
    if (!empty($pr_rows)) {
        $pr_ids = array_map(function ($r) { return (int) $r['id']; }, $pr_rows);
        $pr_csv = implode(',', $pr_ids);
        db_query("DELETE FROM production_materials WHERE production_receipt_id IN ($pr_csv)");
        db_query("DELETE FROM transactions
                  WHERE reference_type = 'production'
                    AND reference_id IN ($pr_csv)");
        db_query("DELETE FROM production_receipts WHERE id IN ($pr_csv)");
    }

    // Hook tea_scent_group: gỡ toàn bộ usage của batch (rollback tồn NVL kiểm soát).
    if (function_exists('tsg_on_investment_deleted')) tsg_on_investment_deleted($group_key);

    return $count;
}

/* ============================================================
 *  PRODUCT BUY — page product_buy (Nhập thành phẩm mua hàng)
 *  Quản lý:
 *    - product_purchase_prices : đơn giá nhập gần nhất (latest_price) per product
 *    - stock_import_invoices   : phiếu nhập tổng (purchase_cost, inventory_value, supplier)
 *    - stock_import_purchase_costs : các CPMH (chi phí mua hàng) per stock_imports row
 *    - stock_imports           : 1 row / sản phẩm (giữ luồng cũ, type=fg_receipt_purchase)
 *    - finished_goods_inventory: cộng tồn (giữ luồng cũ)
 * ============================================================ */

/**
 * Lấy price_including_tax (giá đã gồm CPMH) mới nhất của 1 product trong
 * product_purchase_prices. Dùng làm old_price khi GHI (lúc này row chưa bị upsert
 * nên giá trị đang lưu chính là "lần gần nhất" trước phiếu hiện tại).
 * Trả null nếu chưa có / NULL.
 */
function im_get_price_incl($product_id)
{
    $pid = (int) $product_id;
    if ($pid <= 0) return null;
    $row = db_fetch_row("SELECT price_including_tax FROM product_purchase_prices
                         WHERE product_id = $pid
                         ORDER BY created_at DESC, id DESC LIMIT 1");
    if (!$row || $row['price_including_tax'] === null || $row['price_including_tax'] === '') return null;
    return (float) $row['price_including_tax'];
}

/**
 * Lấy price_including_tax của lần nhập (batch fg_receipt_purchase) GẦN NHẤT TRƯỚC
 * mốc $before_ca cho 1 product — tính lại từ stock_imports + CPMH:
 *   pit = (quantity * unit_price + Σ CPMH) / quantity = total / quantity.
 * Dùng làm old_price khi SỬA: $before_ca = group_key (created_at cũ của batch đang sửa)
 * nên tự loại trừ chính batch này ("truy vấn lần tiếp đó nữa").
 * Trả null nếu không có batch trước đó.
 */
function im_prev_price_incl_before($product_id, $before_ca)
{
    $pid = (int) $product_id;
    if ($pid <= 0 || $before_ca === null || $before_ca === '') return null;
    $b = escape_string((string) $before_ca);
    $row = db_fetch_row("SELECT id, quantity, unit_price FROM stock_imports
                         WHERE product_id = $pid
                           AND type_import = 'fg_receipt_purchase'
                           AND DATE_FORMAT(created_at, '%Y-%m-%d %H:%i:%s') < '$b'
                         ORDER BY created_at DESC, id DESC LIMIT 1");
    if (!$row) return null;
    $qty   = (float) $row['quantity'];
    $price = (float) $row['unit_price'];
    if ($qty <= 0) return $price;
    $sum = 0.0;
    foreach (db_fetch_array("SELECT price FROM stock_import_purchase_costs
                             WHERE stock_import_id = " . (int) $row['id']) ?: [] as $c) {
        $sum += (float) $c['price'];
    }
    return ($qty * $price + $sum) / $qty;
}

/** Lấy product_name (cho nhãn modal biến động giá). */
function im_get_product_name($product_id)
{
    $pid = (int) $product_id;
    if ($pid <= 0) return '#0';
    $row = db_fetch_row("SELECT product_name FROM products WHERE id = $pid LIMIT 1");
    return $row && $row['product_name'] !== null ? $row['product_name'] : ('#' . $pid);
}

/** Lấy latest_price của 1 product trong product_purchase_prices (mới nhất theo created_at + id). */
function im_get_latest_purchase_price($product_id)
{
    $pid = (int) $product_id;
    if ($pid <= 0) return null;
    $row = db_fetch_row("SELECT id, latest_price, created_at
                         FROM product_purchase_prices
                         WHERE product_id = $pid
                         ORDER BY created_at DESC, id DESC
                         LIMIT 1");
    if (!$row) return null;
    return [
        'id'           => (int) $row['id'],
        'latest_price' => (float) $row['latest_price'],
        'created_at'   => $row['created_at'],
    ];
}

/**
 * Upsert latest_price (+ price_including_tax) cho 1 product trong product_purchase_prices.
 * Nếu đã có row cho product_id → update các cột truyền vào + created_at.
 * Nếu chưa có → insert row mới.
 *
 * $price_including_tax (tuỳ chọn): giá đã bao gồm CPMH = .total / .input-quantity.
 *   Truyền null nếu chỉ muốn cập nhật latest_price (không đụng cột này).
 *   Khi insert mới mà không truyền → mặc định = $price (cột NOT NULL trong DB).
 */
function im_save_latest_purchase_price($product_id, $price, $created_at = null, $price_including_tax = null)
{
    $pid = (int) $product_id;
    $p   = (float) $price;
    if ($pid <= 0 || $p < 0) return false;
    $ca  = im_sanitize_datetime($created_at);
    $pit = $price_including_tax !== null ? (float) $price_including_tax : null;

    $row = db_fetch_row("SELECT id FROM product_purchase_prices
                         WHERE product_id = $pid
                         ORDER BY id DESC LIMIT 1");
    if ($row) {
        $upd = ['latest_price' => $p];
        if ($pit !== null) $upd['price_including_tax'] = $pit;
        if ($ca !== null)  $upd['created_at'] = $ca;
        db_update('product_purchase_prices', $upd, 'id = ' . (int) $row['id']);
        return (int) $row['id'];
    }
    $data = [
        'product_id'          => $pid,
        'latest_price'        => $p,
        'price_including_tax' => $pit !== null ? $pit : $p,
    ];
    if ($ca !== null) $data['created_at'] = $ca;
    return (int) db_insert('product_purchase_prices', $data);
}

/* ============================================================
 *   SALES DELIVERY NOTE — extras (products + material fallback)
 * ============================================================ */

/**
 * Tìm trong products theo keyword; nếu không có kết quả thì fallback sang
 * material_information. Trả về mảng đồng nhất shape {id, name, type}.
 * type = 'product' | 'material'.
 */
function im_search_products_or_materials($keyword)
{
    $keyword = trim($keyword);
    if ($keyword === '') return [];
    $kw  = escape_string($keyword);
    $sql = "SELECT id, product_name AS name
            FROM products
            WHERE product_name LIKE '%$kw%'
            ORDER BY product_name ASC
            LIMIT 15";
    $rows = db_fetch_array($sql) ?: [];
    if (!empty($rows)) {
        return array_map(function ($r) {
            return ['id' => (int) $r['id'], 'name' => $r['name'], 'type' => 'product'];
        }, $rows);
    }
    $sql2 = "SELECT id, material_name AS name
             FROM material_information
             WHERE material_name LIKE '%$kw%'
             ORDER BY material_name ASC
             LIMIT 15";
    $rows2 = db_fetch_array($sql2) ?: [];
    return array_map(function ($r) {
        return ['id' => (int) $r['id'], 'name' => $r['name'], 'type' => 'material'];
    }, $rows2);
}

/** Lấy weight_kg mới nhất của 1 product từ product_weights. 0 nếu không có. */
function im_get_product_weight($product_id)
{
    $pid = (int) $product_id;
    if ($pid <= 0) return 0.0;
    $row = db_fetch_row("SELECT weight_kg FROM product_weights
                         WHERE product_id = $pid
                         ORDER BY id DESC LIMIT 1");
    return $row ? (float) $row['weight_kg'] : 0.0;
}

/** Lấy selling_price mới nhất từ branch_product_selling_prices. 0 nếu không có. */
function im_get_product_selling_price($product_id)
{
    $pid = (int) $product_id;
    if ($pid <= 0) return 0.0;
    $row = db_fetch_row("SELECT selling_price FROM branch_product_selling_prices
                         WHERE product_id = $pid
                         ORDER BY id DESC LIMIT 1");
    return $row ? (float) $row['selling_price'] : 0.0;
}

/** Lấy selling_price mới nhất từ branch_material_selling_prices. 0 nếu không có. */
function im_get_material_selling_price($material_id)
{
    $mid = (int) $material_id;
    if ($mid <= 0) return 0.0;
    $row = db_fetch_row("SELECT selling_price FROM branch_material_selling_prices
                         WHERE material_id = $mid
                         ORDER BY id DESC LIMIT 1");
    return $row ? (float) $row['selling_price'] : 0.0;
}

/** Lấy 1 material theo id. */
function im_get_material($material_id)
{
    $mid = (int) $material_id;
    if ($mid <= 0) return null;
    return db_fetch_row("SELECT id, material_code, material_name, unit
                         FROM material_information WHERE id = $mid LIMIT 1") ?: null;
}

/**
 * Cập nhật unit cho 1 product hoặc material (gọi từ ô .cell-unit editable).
 * $type: 'product' → products.unit; 'material' → material_information.unit.
 * Trả true nếu DB có ít nhất 1 dòng được cập nhật.
 */
function im_update_item_unit($id, $type, $unit)
{
    $i = (int) $id;
    if ($i <= 0) return false;
    $u = trim((string) $unit);

    if ($type === 'material') {
        db_update('material_information', ['unit' => $u], "id = $i");
    } else {
        db_update('products', ['unit' => $u], "id = $i");
    }
    return true;
}

/**
 * Trừ tồn material_inventory + ghi 1 dòng stock_exports (type='sales_issue',
 * product_id = NULL, material_id != NULL) cho phiếu xuất kho bán hàng NVL.
 * Trả về cùng shape với im_record_sales_issue.
 */
function im_record_sales_issue_material($material_id, $customer_id, $qty, $unit_price = 0, $total_amount = 0, $interpretation = '', $created_at = null)
{
    $mid = (int) $material_id;
    $cid = (int) $customer_id;
    $q   = (float) $qty;
    if ($mid <= 0 || $cid <= 0 || $q <= 0) {
        return ['ok' => false, 'reason' => 'invalid'];
    }

    $row = db_fetch_row("SELECT id, quantity FROM material_inventory WHERE material_id = $mid LIMIT 1");
    $available = $row ? (float) $row['quantity'] : 0;

    if ($available < $q) {
        return [
            'ok'        => false,
            'reason'    => 'shortage',
            'available' => $available,
            'requested' => $q,
        ];
    }

    im_adjust_material_inventory($mid, -$q);

    $data = [
        'product_id'     => null,
        'material_id'    => $mid,
        'customer_id'    => $cid,
        'quantity'       => $q,
        'unit_price'     => (float) $unit_price,
        'total_amount'   => (float) $total_amount,
        'interpretation' => (string) $interpretation,
        'type_export'    => 'sales_issue',
    ];
    $ca = im_sanitize_datetime($created_at);
    if ($ca !== null) $data['created_at'] = $ca;

    $eid = db_insert('stock_exports', $data);

    // sales_inventory_issue_data: 1 row / (material, customer, ngày)
    im_sii_write_item([
        'id'           => $mid,
        'type'         => 'material',
        'quantity'     => $q,
        'unit_price'   => $unit_price,
        'total_amount' => $total_amount,
    ], $cid, $ca !== null ? $ca : $created_at);

    return ['ok' => true, 'export_id' => (int) $eid];
}

/**
 * Ghi 1 dòng sales_warehouse_export_invoices cho 1 phiếu xuất kho bán hàng.
 * Trả id của row vừa insert (>0), hoặc 0 nếu input không hợp lệ.
 */
function im_record_sales_delivery_invoice($customer_id, $quantity, $weight, $goods_value, $created_at = null)
{
    $cid = (int) $customer_id;
    if ($cid <= 0) return 0;

    $data = [
        'quantity'    => (float) $quantity,
        'weight'      => (float) $weight,
        'goods_value' => (float) $goods_value,
        'customer_id' => $cid,
    ];
    $ca = im_sanitize_datetime($created_at);
    if ($ca !== null) $data['created_at'] = $ca;

    return (int) db_insert('sales_warehouse_export_invoices', $data);
}

/**
 * Ghi 2 dòng transactions (Dr/Cr) cho 1 phiếu xuất kho bán hàng.
 * Default: Dr 131 / Cr 511, amount = goods_value. User có thể override qua $je.
 * reference_type = 'sales_delivery_note', reference_id = invoice_id.
 */
function im_record_sales_delivery_transactions($invoice_id, $goods_value, $created_at = null, $je = null)
{
    $iid = (int) $invoice_id;
    if ($iid <= 0) return false;

    $resolved = im_je_resolve($je, '131', '511', (float) $goods_value);
    $ca = im_sanitize_datetime($created_at);
    je_insert_pairs('sales_delivery_note', $iid, je_entries_from_payload($je, $resolved), $ca);
    return true;
}

/**
 * Tìm sales_warehouse_export_invoices của 1 batch theo (customer_id, created_at).
 * Trả id của invoice (>0), hoặc 0 nếu không tìm thấy.
 */
function im_find_sales_delivery_invoice($customer_id, $created_at)
{
    $cid = (int) $customer_id;
    $ca  = im_sanitize_datetime($created_at);
    if ($cid <= 0 || $ca === null) return 0;
    $ca_safe = escape_string($ca);
    $row = db_fetch_row("SELECT id FROM sales_warehouse_export_invoices
                         WHERE customer_id = $cid
                           AND DATE_FORMAT(created_at, '%Y-%m-%d %H:%i:%s') = '$ca_safe'
                         LIMIT 1");
    return $row ? (int) $row['id'] : 0;
}

/**
 * Lấy chi tiết 1 batch xuất kho bán hàng (group_key + customer_id) để render
 * form Sửa. Trả [items, invoice, customer], items có đủ field hiển thị bảng:
 * {export_id, id (pid hoặc mid), type, name, unit, warehouse, quantity, weight_kg, unit_price, total_amount}.
 * weight_kg: nếu type=product lấy từ product_weights, type=material → 0.
 */
function im_get_sales_issue_batch($group_key, $customer_id)
{
    $cid = (int) $customer_id;
    $gk  = trim((string) $group_key);
    if ($cid <= 0 || $gk === '') return null;
    $gk_safe = escape_string($gk);

    $sql = "SELECT se.id AS export_id,
                   se.product_id,
                   se.material_id,
                   se.quantity,
                   se.unit_price,
                   se.total_amount,
                   p.product_name,
                   p.unit AS product_unit,
                   m.material_name,
                   m.unit AS material_unit
            FROM stock_exports se
            LEFT JOIN products p             ON p.id = se.product_id
            LEFT JOIN material_information m ON m.id = se.material_id
            WHERE DATE_FORMAT(se.created_at, '%Y-%m-%d %H:%i:%s') = '$gk_safe'
              AND se.customer_id = $cid
              AND se.type_export = 'sales_issue'
            ORDER BY se.id ASC";
    $rows = db_fetch_array($sql) ?: [];

    $items = [];
    foreach ($rows as $r) {
        $mid = (int) ($r['material_id'] ?? 0);
        $pid = (int) ($r['product_id']  ?? 0);
        $is_material = $mid > 0;
        $id   = $is_material ? $mid : $pid;
        $type = $is_material ? 'material' : 'product';
        $items[] = [
            'export_id'    => (int) $r['export_id'],
            'id'           => $id,
            'type'         => $type,
            'name'         => $is_material ? ($r['material_name'] ?: ('#' . $mid)) : ($r['product_name'] ?: ('#' . $pid)),
            'unit'         => $is_material ? ($r['material_unit'] ?: '') : ($r['product_unit'] ?: ''),
            'warehouse'    => $is_material ? 'Kho NVL' : 'Kho TP',
            'quantity'     => (float) $r['quantity'],
            'weight_kg'    => $is_material ? 0.0 : im_get_product_weight($pid),
            'unit_price'   => (float) $r['unit_price'],
            'total_amount' => (float) $r['total_amount'],
        ];
    }

    $invoice_id  = im_find_sales_delivery_invoice($cid, $gk);
    $invoice_row = $invoice_id > 0
        ? db_fetch_row("SELECT id, quantity, weight, goods_value FROM sales_warehouse_export_invoices WHERE id = $invoice_id LIMIT 1")
        : null;

    $cust = db_fetch_row("SELECT id, name, short_name, address, receiver, phone
                          FROM customers WHERE id = $cid LIMIT 1") ?: null;

    return [
        'group_key' => $gk,
        'items'     => $items,
        'invoice'   => $invoice_row ? [
            'id'          => (int) $invoice_row['id'],
            'quantity'    => (float) $invoice_row['quantity'],
            'weight'      => (float) $invoice_row['weight'],
            'goods_value' => (float) $invoice_row['goods_value'],
        ] : null,
        'customer'  => $cust ? [
            'id'         => (int) $cust['id'],
            'name'       => $cust['name'],
            'short_name' => $cust['short_name'] ?: '',
            'address'    => $cust['address']    ?? '',
            'receiver'   => $cust['receiver']   ?? '',
            'phone'      => $cust['phone']      ?? '',
        ] : null,
    ];
}

/**
 * Hoàn lại tồn kho từ 1 dòng stock_exports (cộng quantity trở lại
 * finished_goods_inventory hoặc material_inventory).
 */
function im_rollback_stock_export_row($export_id)
{
    $eid = (int) $export_id;
    if ($eid <= 0) return false;
    $row = db_fetch_row("SELECT product_id, material_id, quantity, interpretation FROM stock_exports WHERE id = $eid LIMIT 1");
    if (!$row) return false;
    // Shortage row: chưa từng trừ tồn → không rollback (tránh cộng dư).
    if (trim((string) ($row['interpretation'] ?? '')) === im_shortage_interp_text()) {
        return true;
    }
    $pid = (int) ($row['product_id'] ?? 0);
    $mid = (int) ($row['material_id'] ?? 0);
    $qty = (float) $row['quantity'];
    if ($mid > 0) {
        im_adjust_material_inventory($mid, $qty);
    } elseif ($pid > 0) {
        // Cộng trả vào finished_goods_inventory
        $cur = db_fetch_row("SELECT id, quantity FROM finished_goods_inventory WHERE product_id = $pid LIMIT 1");
        if ($cur) {
            db_update('finished_goods_inventory', ['quantity' => (float) $cur['quantity'] + $qty], 'id = ' . (int) $cur['id']);
        } else {
            db_insert('finished_goods_inventory', ['product_id' => $pid, 'quantity' => $qty]);
        }
    }
    return true;
}

/** Trừ tồn 1 product theo qty; trả true nếu OK, false nếu thiếu tồn. */
function im_subtract_product_stock($product_id, $qty)
{
    $pid = (int) $product_id;
    $q   = (float) $qty;
    if ($pid <= 0 || $q <= 0) return false;
    $row = db_fetch_row("SELECT id, quantity FROM finished_goods_inventory WHERE product_id = $pid LIMIT 1");
    $avail = $row ? (float) $row['quantity'] : 0;
    if ($avail < $q) return false;
    db_update('finished_goods_inventory', ['quantity' => $avail - $q], 'id = ' . (int) $row['id']);
    return true;
}

/** Trừ tồn 1 material theo qty; trả true nếu OK, false nếu thiếu tồn. */
function im_subtract_material_stock($material_id, $qty)
{
    $mid = (int) $material_id;
    $q   = (float) $qty;
    if ($mid <= 0 || $q <= 0) return false;
    $row = db_fetch_row("SELECT id, quantity FROM material_inventory WHERE material_id = $mid LIMIT 1");
    $avail = $row ? (float) $row['quantity'] : 0;
    if ($avail < $q) return false;
    db_update('material_inventory', ['quantity' => $avail - $q], 'id = ' . (int) $row['id']);
    return true;
}

/**
 * Sửa 1 batch xuất kho bán hàng:
 *   - $items: [{export_id (0 nếu mới), id, type, quantity, unit_price, total_amount, interpretation}, ...]
 *   - $created_at: group_key mới của batch (datetime picker; có thể trùng $group_key nếu user không đổi).
 *   - Bảng đồng bộ: stock_exports, finished_goods_inventory, material_inventory,
 *                   sales_warehouse_export_invoices, transactions.
 * Trả ['ok'=>bool, 'invoice_id'=>int, 'shortages'=>[...]].
 */
function im_edit_sales_issue_batch($group_key, $customer_id, $items, $total_qty, $weight, $goods_value, $created_at, $je = null)
{
    $cid = (int) $customer_id;
    $gk  = trim((string) $group_key);
    if ($cid <= 0 || $gk === '') return ['ok' => false, 'message' => 'Thiếu group_key/customer_id.'];
    if (!is_array($items)) $items = [];

    $ca_new  = im_sanitize_datetime($created_at) ?: $gk;
    $gk_safe = escape_string($gk);

    // 1) Wipe sạch batch cũ: rollback tồn (im_rollback_stock_export_row tự bỏ qua
    //    các dòng shortage) + xoá stock_exports + xoá invoice + xoá transactions.
    $invoice_id_old = im_find_sales_delivery_invoice($cid, $gk);
    $existing = db_fetch_array("SELECT id FROM stock_exports
                                WHERE DATE_FORMAT(created_at, '%Y-%m-%d %H:%i:%s') = '$gk_safe'
                                  AND customer_id = $cid
                                  AND type_export = 'sales_issue'") ?: [];
    foreach ($existing as $r) {
        $eid = (int) $r['id'];
        if ($eid <= 0) continue;
        im_rollback_stock_export_row($eid);
        db_query("DELETE FROM stock_exports WHERE id = $eid");
    }
    if ($invoice_id_old > 0) {
        db_query("DELETE FROM transactions
                  WHERE reference_type = 'sales_delivery_note'
                    AND reference_id   = " . (int) $invoice_id_old);
        db_query("DELETE FROM sales_warehouse_export_invoices WHERE id = " . (int) $invoice_id_old);
    }

    // 1b) Xoá rows cũ trong sales_inventory_issue_data theo (customer, ngày cũ).
    //     Sau khi rewrite ở phase 3 (qua im_record_sales_issue/_material),
    //     rows mới sẽ được insert lại với ngày của $ca_new.
    im_sii_delete_batch_by_key($gk, $cid);

    // 2) Chuẩn hoá payload + interp mặc định.
    $interp_dt  = date('d/m/Y', strtotime($ca_new));
    $cust       = db_fetch_row("SELECT name, short_name FROM customers WHERE id = $cid LIMIT 1");
    $sn         = $cust ? ($cust['short_name'] ?: $cust['name']) : ('KH#' . $cid);
    $interp_def = 'Bán hàng ' . $sn . ' ngày ' . $interp_dt;

    $validated = [];
    $shortages = [];
    foreach ($items as $it) {
        $id     = (int) ($it['id'] ?? 0);
        $type   = trim((string) ($it['type'] ?? 'product'));
        $qty    = (float) ($it['quantity'] ?? 0);
        $price  = (float) ($it['unit_price'] ?? 0);
        $total  = (float) ($it['total_amount'] ?? 0);
        $interp = trim((string) ($it['interpretation'] ?? ''));
        if ($interp === '') $interp = $interp_def;
        if ($id <= 0 || $qty <= 0) continue;

        // Probe tồn để biết batch có thiếu tồn không (vì đã rollback dòng cũ ở B1,
        // tồn hiện tại đã phản ánh trạng thái "không có batch này").
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

    // 3) Ghi lại batch theo trạng thái mới.
    $invoice_id = 0;
    if (!empty($shortages)) {
        // Batch thiếu tồn: chỉ lưu stock_exports với marker; KHÔNG ghi invoice/transactions.
        foreach ($validated as $iv) {
            im_record_shortage_export($iv['id'], $iv['type'], $cid, $iv['qty'], $iv['price'], $iv['total'], $ca_new);
        }
    } else {
        foreach ($validated as $iv) {
            if ($iv['type'] === 'material') {
                im_record_sales_issue_material($iv['id'], $cid, $iv['qty'], $iv['price'], $iv['total'], $iv['interp'], $ca_new);
            } else {
                im_record_sales_issue($iv['id'], $cid, $iv['qty'], $iv['price'], $iv['total'], $iv['interp'], $ca_new);
            }
        }
        if (!empty($validated)) {
            $invoice_id = im_record_sales_delivery_invoice($cid, $total_qty, $weight, $goods_value, $ca_new);
            if ($invoice_id > 0) {
                im_record_sales_delivery_transactions($invoice_id, $goods_value, $ca_new, $je);
            }
        }
    }

    return [
        'ok'             => true,
        'invoice_id'     => $invoice_id,
        'shortages'      => $shortages,
        'shortage_batch' => !empty($shortages),
    ];
}

/**
 * Xoá toàn bộ 1 batch xuất kho bán hàng:
 *   - rollback tồn theo từng stock_exports row
 *   - xoá stock_exports
 *   - xoá sales_warehouse_export_invoices
 *   - xoá transactions liên quan
 * Trả số dòng stock_exports đã xoá.
 */
function im_delete_sales_issue_batch($group_key, $customer_id)
{
    $cid = (int) $customer_id;
    $gk  = trim((string) $group_key);
    if ($cid <= 0 || $gk === '') return 0;
    $gk_safe = escape_string($gk);

    $rows = db_fetch_array("SELECT id FROM stock_exports
                            WHERE DATE_FORMAT(created_at, '%Y-%m-%d %H:%i:%s') = '$gk_safe'
                              AND customer_id = $cid
                              AND type_export = 'sales_issue'") ?: [];
    $removed = 0;
    foreach ($rows as $r) {
        $eid = (int) $r['id'];
        if ($eid <= 0) continue;
        im_rollback_stock_export_row($eid);
        db_query("DELETE FROM stock_exports WHERE id = $eid");
        $removed++;
    }

    $invoice_id = im_find_sales_delivery_invoice($cid, $gk);
    if ($invoice_id > 0) {
        db_query("DELETE FROM transactions
                  WHERE reference_type = 'sales_delivery_note'
                    AND reference_id   = $invoice_id");
        db_query("DELETE FROM sales_warehouse_export_invoices WHERE id = $invoice_id");
    }

    // sales_inventory_issue_data: xoá rows theo (customer, ngày của group_key).
    im_sii_delete_batch_by_key($gk, $cid);

    return $removed;
}

/* ============================================================
 *  DATA-AGGREGATION TABLES — finished_product_production_data,
 *  raw_material_production_issue_data, purchased_finished_product_data,
 *  sales_inventory_issue_data.
 *
 *  created_at lưu DATE-only (Y-m-d 00:00:00) lấy từ #record-datetime.
 *  Nhiều batch trùng (entity, supplier/customer, ngày) sẽ COLLIDE
 *  → coi như UPSERT/REPLACE per (entity, ngày).
 * ============================================================ */

/** Chuẩn hoá chuỗi datetime → 'Y-m-d 00:00:00' (chỉ giữ phần ngày). */
function im_date_only_dt($dt)
{
    if ($dt === null) return null;
    $dt = trim((string) $dt);
    if ($dt === '') return null;
    $t = strtotime($dt);
    if (!$t) return null;
    return date('Y-m-d 00:00:00', $t);
}

/* --- finished_product_production_data --------------------------------
 *   dashboard (fg_receipt_production): upsert quantity per (product, date)
 *   investment_products: upsert production_cost per (product, date)
 */

function im_fpp_upsert_quantity($product_id, $quantity, $created_at)
{
    $pid = (int) $product_id;
    if ($pid <= 0) return;
    $date = im_date_only_dt($created_at);
    if ($date === null) $date = date('Y-m-d 00:00:00');
    $date_safe = escape_string($date);
    $row = db_fetch_row("SELECT id FROM finished_product_production_data
                         WHERE product_id = $pid AND created_at = '$date_safe' LIMIT 1");
    if ($row) {
        db_update('finished_product_production_data',
            ['quantity' => (float) $quantity],
            'id = ' . (int) $row['id']);
    } else {
        db_insert('finished_product_production_data', [
            'product_id'      => $pid,
            'quantity'        => (float) $quantity,
            'production_cost' => 0,
            'created_at'      => $date,
        ]);
    }
}

function im_fpp_upsert_production_cost($product_id, $production_cost, $created_at)
{
    $pid = (int) $product_id;
    if ($pid <= 0) return;
    $date = im_date_only_dt($created_at);
    if ($date === null) $date = date('Y-m-d 00:00:00');
    $date_safe = escape_string($date);
    $row = db_fetch_row("SELECT id FROM finished_product_production_data
                         WHERE product_id = $pid AND created_at = '$date_safe' LIMIT 1");
    if ($row) {
        db_update('finished_product_production_data',
            ['production_cost' => (float) $production_cost],
            'id = ' . (int) $row['id']);
    } else {
        db_insert('finished_product_production_data', [
            'product_id'      => $pid,
            'quantity'        => 0,
            'production_cost' => (float) $production_cost,
            'created_at'      => $date,
        ]);
    }
}

function im_fpp_delete($product_id, $created_at)
{
    $pid = (int) $product_id;
    if ($pid <= 0) return;
    $date = im_date_only_dt($created_at);
    if ($date === null) return;
    $date_safe = escape_string($date);
    db_query("DELETE FROM finished_product_production_data
              WHERE product_id = $pid AND created_at = '$date_safe'");
}

function im_fpp_reset_production_cost($product_id, $created_at)
{
    $pid = (int) $product_id;
    if ($pid <= 0) return;
    $date = im_date_only_dt($created_at);
    if ($date === null) return;
    $date_safe = escape_string($date);
    db_query("UPDATE finished_product_production_data SET production_cost = 0
              WHERE product_id = $pid AND created_at = '$date_safe'");
}

/* --- raw_material_production_issue_data ------------------------------
 *   investment_products: ghi xuất NVL sản xuất.
 *   Strategy: wipe rows (product_id, date) rồi insert lại theo $materials.
 */

function im_rmpi_replace_for_product($product_id, $materials, $created_at)
{
    $pid = (int) $product_id;
    if ($pid <= 0) return;
    $date = im_date_only_dt($created_at);
    if ($date === null) $date = date('Y-m-d 00:00:00');
    $date_safe = escape_string($date);
    db_query("DELETE FROM raw_material_production_issue_data
              WHERE product_id = $pid AND created_at = '$date_safe'");
    foreach ((array) $materials as $m) {
        $mid = (int) ($m['material_id'] ?? 0);
        $qty = (float) ($m['total_qty']  ?? 0);
        if ($mid <= 0 || $qty <= 0) continue;
        $price = (float) ($m['unit_price'] ?? 0);
        $amt   = $qty * $price;
        db_insert('raw_material_production_issue_data', [
            'material_id' => $mid,
            'product_id'  => $pid,
            'quantity'    => $qty,
            'unit_price'  => $price,
            'amount'      => $amt,
            'created_at'  => $date,
        ]);
    }
}

function im_rmpi_delete_for_product($product_id, $created_at)
{
    $pid = (int) $product_id;
    if ($pid <= 0) return;
    $date = im_date_only_dt($created_at);
    if ($date === null) return;
    $date_safe = escape_string($date);
    db_query("DELETE FROM raw_material_production_issue_data
              WHERE product_id = $pid AND created_at = '$date_safe'");
}

/* --- purchased_finished_product_data ---------------------------------
 *   product_buy: ghi mua thành phẩm per (product, supplier, date).
 *   other_cost = Σ purchase_costs[i].price; total_inventory_value = amount + other_cost.
 */

function im_pfp_write_batch($items, $supplier_id, $created_at)
{
    $sid = (int) $supplier_id;
    $date = im_date_only_dt($created_at);
    if ($date === null) $date = date('Y-m-d 00:00:00');
    $date_safe = escape_string($date);
    $sid_cond  = $sid > 0 ? "AND supplier_id = $sid" : '';
    foreach ((array) $items as $it) {
        $pid = (int) ($it['product_id'] ?? 0);
        $qty = (float) ($it['quantity']  ?? 0);
        if ($pid <= 0 || $qty <= 0) continue;
        $price = (float) ($it['unit_price'] ?? 0);
        $other_cost = 0.0;
        foreach ((array) ($it['purchase_costs'] ?? []) as $c) {
            $other_cost += (float) ($c['price'] ?? 0);
        }
        $amount    = $qty * $price;
        $total_inv = $amount + $other_cost;
        db_query("DELETE FROM purchased_finished_product_data
                  WHERE product_id = $pid $sid_cond AND created_at = '$date_safe'");
        db_insert('purchased_finished_product_data', [
            'product_id'            => $pid,
            'supplier_id'           => $sid > 0 ? $sid : null,
            'quantity'              => $qty,
            'unit_price'            => $price,
            'amount'                => $amount,
            'other_cost'            => $other_cost,
            'total_inventory_value' => $total_inv,
            'created_at'            => $date,
        ]);
    }
}

function im_pfp_delete_batch($items, $supplier_id, $created_at)
{
    $sid  = (int) $supplier_id;
    $date = im_date_only_dt($created_at);
    if ($date === null) return;
    $date_safe = escape_string($date);
    $sid_cond  = $sid > 0 ? "AND supplier_id = $sid" : '';
    foreach ((array) $items as $it) {
        $pid = (int) ($it['product_id'] ?? 0);
        if ($pid <= 0) continue;
        db_query("DELETE FROM purchased_finished_product_data
                  WHERE product_id = $pid $sid_cond AND created_at = '$date_safe'");
    }
}

/* --- sales_inventory_issue_data --------------------------------------
 *   sales_delivery_note: ghi 1 row / item / customer / ngày.
 *   item.type ∈ {product, material} → cột product_id hoặc material_id.
 */

function im_sii_write_item($item, $customer_id, $created_at)
{
    $id   = (int) ($item['id'] ?? $item['product_id'] ?? $item['material_id'] ?? 0);
    $type = trim((string) ($item['type'] ?? 'product'));
    $qty  = (float) ($item['quantity'] ?? 0);
    if ($id <= 0 || $qty <= 0) return;
    $cid  = (int) $customer_id;
    $date = im_date_only_dt($created_at);
    if ($date === null) $date = date('Y-m-d 00:00:00');
    $date_safe = escape_string($date);

    $price       = (float) ($item['unit_price']   ?? 0);
    $amount      = (float) ($item['total_amount'] ?? ($qty * $price));
    $is_material = ($type === 'material');
    $id_col      = $is_material ? 'material_id' : 'product_id';
    $cid_cond    = $cid > 0 ? "AND customer_id = $cid" : '';

    db_query("DELETE FROM sales_inventory_issue_data
              WHERE $id_col = $id $cid_cond AND created_at = '$date_safe'");
    db_insert('sales_inventory_issue_data', [
        'product_id'  => $is_material ? null : $id,
        'material_id' => $is_material ? $id  : null,
        'customer_id' => $cid > 0 ? $cid : null,
        'warehouse'   => $is_material ? 'Kho NVL' : 'Kho TP',
        'quantity'    => $qty,
        'unit_price'  => $price,
        'amount'      => $amount,
        'created_at'  => $date,
    ]);
}

/** Wipe sales_inventory_issue_data rows trùng (customer, ngày-của-group_key). */
function im_sii_delete_batch_by_key($group_key, $customer_id)
{
    $cid = (int) $customer_id;
    $key = trim((string) $group_key);
    if ($key === '') return;
    $t = strtotime($key);
    if (!$t) return;
    $date = date('Y-m-d 00:00:00', $t);
    $date_safe = escape_string($date);
    $cid_cond  = $cid > 0 ? "AND customer_id = $cid" : '';
    db_query("DELETE FROM sales_inventory_issue_data
              WHERE created_at = '$date_safe' $cid_cond");
}

/* =====================================================================
 *  TASK 3b — GIÁ VỐN HÀNG BÁN (632) cho phiếu xuất kho bán hàng.
 *  - NVL (Kho NVL): đơn giá vốn = COALESCE(includes_purchase_cost, purchase_price)
 *    của bản ghi material_purchase_prices mới nhất.
 *  - Thành phẩm: Σ(quantity_required × đơn giá vốn NVL) + đơn giá chi phí sản xuất (rate).
 * ===================================================================== */

/** Đơn giá vốn 1 NVL — ưu tiên giá mua đã gồm chi phí mua; trống (NULL/0) thì lấy purchase_price. */
function im_material_cogs_unit_price($material_id)
{
    $mid = (int) $material_id;
    if ($mid <= 0) return 0.0;
    $row = db_fetch_row(
        "SELECT purchase_price,
                purchase_price_includes_purchase_cost AS inc
         FROM material_purchase_prices
         WHERE material_id = $mid
         ORDER BY last_updated_at DESC, id DESC
         LIMIT 1"
    );
    if (!$row) return 0.0;
    $inc = $row['inc'];
    if ($inc !== null && (float) $inc != 0.0) {
        return (float) $inc;
    }
    return $row['purchase_price'] !== null ? (float) $row['purchase_price'] : 0.0;
}

/** Giá vốn NVL cho 1 đơn vị thành phẩm = Σ(quantity_required × đơn giá vốn NVL). */
function im_product_material_cost_per_unit_incl($product_id)
{
    $pid = (int) $product_id;
    if ($pid <= 0) return 0.0;
    $rows = db_fetch_array(
        "SELECT quantity_required, material_id FROM product_materials WHERE product_id = $pid"
    ) ?: [];
    $sum = 0.0;
    foreach ($rows as $r) {
        $sum += (float) $r['quantity_required'] * im_material_cogs_unit_price((int) $r['material_id']);
    }
    return $sum;
}

/** Tổng giá vốn hàng bán (632) cho danh sách item xuất. $items: [{id,type,qty}], type∈{material,product}. */
function im_sales_cogs_for_items(array $items)
{
    $rate  = function_exists('production_cost_rate') ? production_cost_rate() : 6000.0;
    $total = 0.0;
    foreach ($items as $it) {
        $id   = (int) ($it['id'] ?? 0);
        $qty  = (float) ($it['qty'] ?? 0);
        $type = (string) ($it['type'] ?? '');
        if ($id <= 0 || $qty <= 0) continue;
        if ($type === 'material') {
            $total += im_material_cogs_unit_price($id) * $qty;
        } else {
            $overhead = im_product_excludes_production_cost($id) ? 0.0 : $rate;
            $total += (im_product_material_cost_per_unit_incl($id) + $overhead) * $qty;
        }
    }
    return $total;
}

/**
 * TASK 3: bóc tách chi tiết giá vốn hàng bán (632) theo từng mặt hàng để modal "con mắt"
 * giải thích con số. Cùng công thức với im_sales_cogs_for_items nhưng trả breakdown.
 * @return array ['rate'=>float, 'total'=>float, 'rows'=>[
 *     ['id','type','qty','material_cost','overhead','unit_cost','line_cost'], ...]]
 */
function im_sales_cogs_breakdown_for_items(array $items)
{
    $rate  = function_exists('production_cost_rate') ? production_cost_rate() : 6000.0;
    $rows  = [];
    $total = 0.0;
    foreach ($items as $it) {
        $id   = (int) ($it['id'] ?? 0);
        $qty  = (float) ($it['qty'] ?? 0);
        $type = (string) ($it['type'] ?? '');
        if ($id <= 0 || $qty <= 0) continue;
        if ($type === 'material') {
            $material_cost = im_material_cogs_unit_price($id);
            $overhead      = 0.0;
            $unit          = $material_cost;
        } else {
            $material_cost = im_product_material_cost_per_unit_incl($id);
            $overhead      = im_product_excludes_production_cost($id) ? 0.0 : $rate;
            $unit          = $material_cost + $overhead;
        }
        $line   = $unit * $qty;
        $total += $line;
        $rows[] = [
            'id'            => $id,
            'type'          => $type === 'material' ? 'material' : 'product',
            'qty'           => $qty,
            'material_cost' => $material_cost,
            'overhead'      => $overhead,
            'unit_cost'     => $unit,
            'line_cost'     => $line,
        ];
    }
    return ['rate' => $rate, 'total' => $total, 'rows' => $rows];
}

/* ============================================================
 *  PHÂN TÍCH SẢN PHẨM MUA — "Phiếu nhập kho kèm phân tích" (product_buy)
 * ============================================================ */

/** Số hóa đơn (purchase) đính kèm 1 lần nhập thành phẩm (qua import_invoice_id). */
function im_analysis_invoice_count($import_invoice_id)
{
    $iid = (int) $import_invoice_id;
    if ($iid <= 0) return 0;
    $row = db_fetch_row("SELECT COUNT(wri.id) AS c
                         FROM warehouse_receipts wr
                         JOIN warehouse_receipt_invoices wri ON wri.wr_id = wr.id
                         WHERE wr.import_invoice_id = $iid
                           AND wri.type = 'purchase_invoice'");
    return $row ? (int) $row['c'] : 0;
}

/** CPMH + số hóa đơn của lần nhập 1 product vào 1 ngày (stock_imports + purchase_costs). */
function im_analysis_import_extra($product_id, $date_only)
{
    $pid = (int) $product_id;
    $d   = escape_string((string) $date_only);
    if ($pid <= 0 || $d === '') return ['cpmh' => 0.0, 'invoices' => 0];
    $si = db_fetch_row("SELECT id, import_invoice_id FROM stock_imports
                        WHERE product_id = $pid
                          AND type_import = 'fg_receipt_purchase'
                          AND DATE(created_at) = DATE('$d')
                        ORDER BY id DESC LIMIT 1");
    if (!$si) return ['cpmh' => 0.0, 'invoices' => 0];
    $sum = 0.0;
    foreach (db_fetch_array("SELECT price FROM stock_import_purchase_costs
                             WHERE stock_import_id = " . (int) $si['id']) ?: [] as $c) {
        $sum += (float) $c['price'];
    }
    return ['cpmh' => $sum, 'invoices' => im_analysis_invoice_count($si['import_invoice_id'])];
}

/**
 * Lượng xuất kho (sales_inventory_issue_data) trong $days ngày gần nhất,
 * tính LŨY KẾ từ mốc (ref_date - $days) đến ref_date.
 * $ref_date: 'Y-m-d' (ngày trên phiếu); rỗng → dùng ngày hiện tại.
 * So sánh theo DATE() để bao trọn cả ngày ở 2 biên (created_at lưu 00:00:00).
 */
function im_analysis_export_qty($product_id, $days, $ref_date = '')
{
    $pid = (int) $product_id;
    $dd  = (int) $days;
    if ($pid <= 0) return 0.0;
    $ref = trim((string) $ref_date);
    $ref_expr = ($ref !== '' && preg_match('/^\d{4}-\d{2}-\d{2}$/', $ref))
        ? "DATE('" . escape_string($ref) . "')"
        : "CURDATE()";
    $row = db_fetch_row("SELECT COALESCE(SUM(quantity),0) AS q
                         FROM sales_inventory_issue_data
                         WHERE product_id = $pid
                           AND DATE(created_at) >  ($ref_expr - INTERVAL $dd DAY)
                           AND DATE(created_at) <= $ref_expr");
    return $row ? (float) $row['q'] : 0.0;
}

/**
 * Phân tích đầy đủ cho danh sách product_id (page product_buy).
 * Trả map: product_id => { stock, recent[], export_qty{m1..y1}, exports[] }.
 */
function im_get_product_buy_analysis($product_ids, $ref_date = '')
{
    $out = [];
    if (!is_array($product_ids)) return $out;
    foreach ($product_ids as $raw) {
        $pid = (int) $raw;
        if ($pid <= 0 || isset($out[$pid])) continue;

        // Tồn hiện tại
        $stock = im_get_current_stock($pid);

        // 5 lần nhập gần nhất (purchased_finished_product_data)
        $recent = [];
        $rows = db_fetch_array("SELECT quantity, unit_price, amount, total_inventory_value, created_at
                                FROM purchased_finished_product_data
                                WHERE product_id = $pid
                                ORDER BY created_at DESC, id DESC LIMIT 5") ?: [];
        foreach ($rows as $r) {
            $extra = im_analysis_import_extra($pid, substr((string) $r['created_at'], 0, 10));
            $recent[] = [
                'date'       => $r['created_at'],
                'quantity'   => (float) $r['quantity'],
                'unit_price' => (float) $r['unit_price'],
                'amount'     => (float) $r['amount'],
                'cpmh'       => (float) $extra['cpmh'],
                'total'      => (float) $r['total_inventory_value'],
                'invoices'   => (int) $extra['invoices'],
            ];
        }

        // Lượng xuất kho theo mốc (lũy kế tính từ ngày trên phiếu)
        $export_qty = [
            'm1' => im_analysis_export_qty($pid, 30,  $ref_date),
            'm3' => im_analysis_export_qty($pid, 90,  $ref_date),
            'm6' => im_analysis_export_qty($pid, 180, $ref_date),
            'y1' => im_analysis_export_qty($pid, 365, $ref_date),
        ];

        // Chi tiết xuất gần đây (ngày, SL, đơn giá, thành tiền, khách hàng = tên viết tắt)
        $exports = [];
        $erows = db_fetch_array("SELECT s.quantity, s.unit_price, s.amount, s.created_at,
                                        COALESCE(NULLIF(c.short_name, ''), c.name) AS customer_name
                                 FROM sales_inventory_issue_data s
                                 LEFT JOIN customers c ON c.id = s.customer_id
                                 WHERE s.product_id = $pid
                                 ORDER BY s.created_at DESC, s.id DESC LIMIT 5") ?: [];
        foreach ($erows as $e) {
            $exports[] = [
                'date'       => $e['created_at'],
                'quantity'   => (float) $e['quantity'],
                'unit_price' => (float) $e['unit_price'],
                'amount'     => (float) $e['amount'],
                'customer'   => $e['customer_name'] ?? '',
            ];
        }

        $out[$pid] = [
            'stock'      => $stock,
            'recent'     => $recent,
            'export_qty' => $export_qty,
            'exports'    => $exports,
        ];
    }
    return $out;
}
