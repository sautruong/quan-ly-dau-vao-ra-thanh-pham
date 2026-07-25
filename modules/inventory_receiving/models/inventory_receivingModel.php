<?php

require_once __DIR__ . '/../../../libraries/purchase_price_changes.php';
require_once __DIR__ . '/../../../libraries/material_issue_costing.php';
require_once __DIR__ . '/../../../libraries/print_settings.php';
// Tái dùng các hàm im_* xử lý thành phẩm (finished_goods_inventory, product_purchase_prices,
// purchased_finished_product_data...) — row_material_receiving giờ nhận cả dòng NVL lẫn
// dòng thành phẩm mua (gộp từ flow product_buy đã gỡ bỏ).
require_once __DIR__ . '/../../inventory_management/models/inventory_managementModel.php';
// Tái dùng om_* để khớp phiếu nhập vừa ghi với đơn đặt hàng NVL đã lưu (auto xác nhận
// "Đã nhận" + cảnh báo giá trị dự kiến khác giá trị thực nhập).
require_once __DIR__ . '/../../order_material/models/order_materialModel.php';
// Tái dùng oc_* để khớp dòng THÀNH PHẨM của phiếu với đơn cà phê (oc_orders) — cùng cơ chế
// auto "Đã nhận". Prefix oc_ không đụng độ om_ (khác với order_management).
require_once __DIR__ . '/../../order_coffee/models/order_coffeeModel.php';

/**
 * inventory_receiving — page row_material_receiving (Nhập mua hàng hóa: NVL + thành phẩm)
 *
 * Tái sử dụng các bảng đã có từ flow product_buy:
 *   - stock_imports                : 1 row / NVL (type_import='row_material_receiving')
 *                                    .quantity, .unit_price (= cell-price), .import_invoice_id
 *   - stock_import_invoices        : 1 row / phiếu (purchase_cost, inventory_value, supplier_id)
 *   - stock_import_purchase_costs  : 2 rows / stock_imports row
 *                                      (description='VAT {n}%', price=cell-vat-amount)
 *                                      (description='CP khác',  price=cell-other-cost)  ← chỉ ghi nếu > 0
 *   - material_inventory           : cộng tồn theo material_id
 *   - material_purchase_prices     : upsert purchase_price + purchase_price_includes_purchase_cost
 *   - transactions                 : 2 dòng (Dr 152 / Cr 331, amount = inventory_value = total-value-bill)
 *
 * Type import value: 'row_material_receiving' (string, không cần migrate enum).
 */

/* ============================================================
 *  Helpers chung
 * ============================================================ */

function ir_sanitize_datetime($dt)
{
    if ($dt === null) return null;
    $dt = trim((string) $dt);
    if ($dt === '') return null;
    $t = strtotime($dt);
    return $t ? date('Y-m-d H:i:s', $t) : null;
}

/**
 * Chuẩn hoá payload bút toán: ['debit','credit','amount'] (mọi field optional).
 * Trả null cho field user bỏ trống → để code-side fallback default.
 */
function ir_je_normalize($je)
{
    if (!is_array($je)) $je = [];
    $d = isset($je['debit'])  ? trim((string) $je['debit'])  : '';
    $c = isset($je['credit']) ? trim((string) $je['credit']) : '';
    $a = $je['amount'] ?? null;
    if (is_string($a)) $a = trim($a);
    return [
        'debit'  => $d !== '' ? $d : null,
        'credit' => $c !== '' ? $c : null,
        'amount' => ($a === null || $a === '') ? null : (float) $a,
    ];
}

function ir_je_resolve($je, $default_debit, $default_credit, $default_amount)
{
    $n = ir_je_normalize($je);
    return [
        'debit'  => $n['debit']  !== null ? $n['debit']  : $default_debit,
        'credit' => $n['credit'] !== null ? $n['credit'] : $default_credit,
        'amount' => $n['amount'] !== null ? $n['amount'] : (float) $default_amount,
    ];
}

/* ============================================================
 *  Print settings — thông tin cố định trên phiếu nhập kho in/Share.
 *  Delegate sang libraries/print_settings.php (dùng chung với phiếu xuất kho).
 * ============================================================ */

function ir_get_print_settings()            { return print_settings_get(); }
function ir_save_print_setting($key, $value){ return print_settings_save($key, $value); }

/* ============================================================
 *  AJAX search: supplier / material
 * ============================================================ */

function ir_search_suppliers($keyword)
{
    $kw = trim((string) $keyword);
    if ($kw === '') return [];
    $k = escape_string($kw);
    $sql = "SELECT id, supplier_name
            FROM suppliers
            WHERE supplier_name LIKE '%$k%'
            ORDER BY supplier_name ASC
            LIMIT 15";
    return db_fetch_array($sql) ?: [];
}

function ir_get_supplier($supplier_id)
{
    $sid = (int) $supplier_id;
    if ($sid <= 0) return null;
    return db_fetch_row("SELECT id, supplier_name FROM suppliers WHERE id = $sid LIMIT 1") ?: null;
}

function ir_search_materials($keyword)
{
    $kw = trim((string) $keyword);
    if ($kw === '') return [];
    $k = escape_string($kw);
    $sql = "SELECT id, material_name, unit
            FROM material_information
            WHERE material_name LIKE '%$k%'
            ORDER BY material_name ASC
            LIMIT 15";
    return db_fetch_array($sql) ?: [];
}

/**
 * Tìm "tên hàng hóa" gộp cả NVL (material_information) và thành phẩm (products).
 * Trả về mảng đồng nhất {id, name, unit, type} với type ∈ {'material','product'},
 * gộp theo tên (ASC), tối đa 15 kết quả.
 */
function ir_search_items($keyword)
{
    $kw = trim((string) $keyword);
    if ($kw === '') return [];
    $k = escape_string($kw);

    $mats = db_fetch_array("SELECT id, material_name AS name, unit
                            FROM material_information
                            WHERE material_name LIKE '%$k%'
                            ORDER BY material_name ASC
                            LIMIT 15") ?: [];
    $prods = db_fetch_array("SELECT id, product_name AS name, unit
                             FROM products
                             WHERE product_name LIKE '%$k%'
                             ORDER BY product_name ASC
                             LIMIT 15") ?: [];

    $out = [];
    foreach ($mats as $m) {
        $out[] = ['id' => (int) $m['id'], 'name' => $m['name'], 'unit' => $m['unit'] ?? '', 'type' => 'material'];
    }
    foreach ($prods as $p) {
        $out[] = ['id' => (int) $p['id'], 'name' => $p['name'], 'unit' => $p['unit'] ?? '', 'type' => 'product'];
    }
    usort($out, function ($a, $b) { return strcasecmp($a['name'], $b['name']); });
    return array_slice($out, 0, 15);
}

/**
 * Lấy thông tin 1 NVL kèm purchase_price mới nhất (cho autofill khi user
 * chọn từ dropdown .cell-name).
 */
/** Cập nhật unit cho 1 NVL trong material_information (khi user edit .cell-unit). */
function ir_update_material_unit($material_id, $unit)
{
    $mid = (int) $material_id;
    if ($mid <= 0) return false;
    $u = trim((string) $unit);
    db_update('material_information', ['unit' => $u], "id = $mid");
    return true;
}

function ir_get_material_info($material_id)
{
    $mid = (int) $material_id;
    if ($mid <= 0) return null;
    $m = db_fetch_row("SELECT id, material_name, unit
                       FROM material_information WHERE id = $mid LIMIT 1");
    if (!$m) return null;
    $p = db_fetch_row("SELECT purchase_price, purchase_price_includes_purchase_cost
                       FROM material_purchase_prices
                       WHERE material_id = $mid
                       ORDER BY last_updated_at DESC, id DESC
                       LIMIT 1");
    $price_pure = $p ? (float) $p['purchase_price'] : 0;
    $price_incl = $p && $p['purchase_price_includes_purchase_cost'] !== null
        ? (float) $p['purchase_price_includes_purchase_cost']
        : null;
    // Giá hiệu lực: ưu tiên purchase_price_includes_purchase_cost (>0); fallback purchase_price
    $price_effective = ($price_incl !== null && $price_incl > 0) ? $price_incl : $price_pure;
    return [
        'id'                                    => (int) $m['id'],
        'material_name'                         => $m['material_name'],
        'unit'                                  => $m['unit'] ?? '',
        'purchase_price'                        => $price_pure,
        'purchase_price_includes_purchase_cost' => $price_incl,
        'price_effective'                       => $price_effective,
        'type'                                  => 'material',
    ];
}

/**
 * Lấy thông tin 1 sản phẩm (thành phẩm) cho autofill khi user chọn từ dropdown
 * .cell-name — tương đương ir_get_material_info nhưng cho 'product'.
 * Giá hiệu lực ưu tiên price_including_tax (đã gồm CPMH), fallback latest_price.
 */
function ir_get_product_info($product_id)
{
    $pid = (int) $product_id;
    if ($pid <= 0) return null;
    $p = db_fetch_row("SELECT id, product_name, unit FROM products WHERE id = $pid LIMIT 1");
    if (!$p) return null;
    $latest = im_get_latest_purchase_price($pid);
    $price_pure = $latest ? (float) $latest['latest_price'] : 0.0;
    $price_incl_row = $latest ? db_fetch_row("SELECT price_including_tax FROM product_purchase_prices WHERE id = " . (int) $latest['id'] . " LIMIT 1") : null;
    $price_incl = $price_incl_row && $price_incl_row['price_including_tax'] !== null ? (float) $price_incl_row['price_including_tax'] : null;
    $price_effective = ($price_incl !== null && $price_incl > 0) ? $price_incl : $price_pure;
    return [
        'id'               => (int) $p['id'],
        'material_name'    => $p['product_name'],
        'unit'             => $p['unit'] ?? '',
        'purchase_price'   => $price_pure,
        'price_effective'  => $price_effective,
        'type'             => 'product',
    ];
}

/** Dispatch theo $type: 'material' -> ir_get_material_info, 'product' -> ir_get_product_info. */
function ir_get_item_info($type, $id)
{
    return $type === 'product' ? ir_get_product_info($id) : ir_get_material_info($id);
}

/* ============================================================
 *  Material purchase price + inventory
 * ============================================================ */

function ir_get_latest_material_price_id($material_id)
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
 * Lấy giá baseline (old_price) mới nhất của 1 NVL để đối chiếu biến động khi GHI.
 * Ưu tiên purchase_price_includes_purchase_cost (giá đã gồm CPMH); nếu cột này
 * NULL/'' — phần lớn NVL đăng ký TRƯỚC khi có cơ chế CPMH chỉ có purchase_price —
 * thì fallback sang purchase_price để vẫn phát hiện được biến động giá.
 * Trả null nếu chưa có row hoặc cả 2 cột đều rỗng/0.
 */
function ir_get_price_incl($material_id)
{
    $mid = (int) $material_id;
    if ($mid <= 0) return null;
    $row = db_fetch_row("SELECT purchase_price_includes_purchase_cost AS pit, purchase_price AS pp
                         FROM material_purchase_prices
                         WHERE material_id = $mid
                         ORDER BY last_updated_at DESC, id DESC LIMIT 1");
    if (!$row) return null;
    $base = ($row['pit'] === null || $row['pit'] === '') ? $row['pp'] : $row['pit'];
    if ($base === null || $base === '') return null;
    $base = (float) $base;
    return $base > 0 ? $base : null;
}

/**
 * Lấy giá đã gồm CPMH của lần nhập (batch row_material_receiving) GẦN NHẤT TRƯỚC mốc
 * $before_ca cho 1 NVL — tính lại từ stock_imports + CPMH:
 *   pit = (quantity * unit_price + Σ CPMH) / quantity = total / quantity.
 * Dùng làm old_price khi SỬA: $before_ca = group_key cũ → tự loại trừ batch đang sửa.
 */
function ir_prev_price_incl_before($material_id, $before_ca)
{
    $mid = (int) $material_id;
    if ($mid <= 0 || $before_ca === null || $before_ca === '') return null;
    $b = escape_string((string) $before_ca);
    $row = db_fetch_row("SELECT id, quantity, unit_price FROM stock_imports
                         WHERE material_id = $mid
                           AND type_import = 'row_material_receiving'
                           AND DATE_FORMAT(created_at, '%Y-%m-%d %H:%i:%s') < '$b'
                         ORDER BY created_at DESC, id DESC LIMIT 1");
    if (!$row) return null;
    $qty   = (float) $row['quantity'];
    $price = (float) $row['unit_price'];
    if ($qty <= 0) return $price > 0 ? $price : null;
    $sum = 0.0;
    foreach (db_fetch_array("SELECT price FROM stock_import_purchase_costs
                             WHERE stock_import_id = " . (int) $row['id']) ?: [] as $c) {
        $sum += (float) $c['price'];
    }
    $pit = ($qty * $price + $sum) / $qty;
    return $pit > 0 ? $pit : null;
}

/** Lấy material_name (cho nhãn modal biến động giá). */
function ir_get_material_name($material_id)
{
    $mid = (int) $material_id;
    if ($mid <= 0) return '#0';
    $row = db_fetch_row("SELECT material_name FROM material_information WHERE id = $mid LIMIT 1");
    return $row && $row['material_name'] !== null ? $row['material_name'] : ('#' . $mid);
}

/**
 * Danh sách sản phẩm dùng 1 NVL (product_materials) + giá vốn cũ/mới khi giá NVL đó
 * biến động — dùng cho modal "Giá vốn ảnh hưởng" (bấm từ modal biến động giá nhập).
 * Sắp theo |tỉ lệ biến động| giảm dần (sản phẩm bị ảnh hưởng nhiều nhất lên đầu).
 */
function ir_material_cost_impact($material_id, $old_price, $new_price)
{
    $mid = (int) $material_id;
    if ($mid <= 0) return [];

    $product_ids = db_fetch_array(
        "SELECT DISTINCT product_id FROM product_materials WHERE material_id = $mid"
    ) ?: [];

    $out = [];
    foreach ($product_ids as $r) {
        $calc = ir_product_cost_breakdown((int) $r['product_id'], $mid, $old_price, $new_price);
        if ($calc === null) continue;
        $out[] = [
            'product_id'   => $calc['product_id'],
            'product_name' => $calc['product_name'],
            'old_cost'     => round($calc['total_old'], 2),
            'new_cost'     => round($calc['total_new'], 2),
            'change_rate'  => $calc['change_rate'],
        ];
    }
    usort($out, function ($a, $b) {
        return abs((float) $b['change_rate']) <=> abs((float) $a['change_rate']);
    });
    return $out;
}

/**
 * Bảng thành phần (định mức × đơn giá) tính giá vốn cũ/mới của 1 sản phẩm khi
 * $material_id đổi giá — nguồn cho cả ir_material_cost_impact() và modal "giải
 * thích" (click tên sản phẩm). Đơn giá các NVL KHÔNG đổi lấy hiện hành
 * (ir_get_price_incl) — không truy hồi giá tại đúng thời điểm cũ.
 */
function ir_product_cost_breakdown($product_id, $material_id, $old_price, $new_price)
{
    $pid = (int) $product_id;
    $mid = (int) $material_id;
    if ($pid <= 0) return null;

    $prod = db_fetch_row(
        "SELECT id, COALESCE(NULLIF(common_product_name, ''), product_name) AS product_name
         FROM products WHERE id = $pid LIMIT 1"
    );
    if (!$prod) return null;

    $rows = db_fetch_array(
        "SELECT pm.material_id, pm.quantity_required,
                COALESCE(NULLIF(mi.common_material_name, ''), mi.material_name) AS material_name
         FROM product_materials pm
         JOIN material_information mi ON mi.id = pm.material_id
         WHERE pm.product_id = $pid
         ORDER BY pm.sort_order ASC, pm.id ASC"
    ) ?: [];

    $out_rows  = [];
    $total_old = 0.0;
    $total_new = 0.0;
    foreach ($rows as $r) {
        $rmid       = (int) $r['material_id'];
        $qty        = (float) $r['quantity_required'];
        $is_changed = ($rmid === $mid);
        $cur        = $is_changed ? 0.0 : (ir_get_price_incl($rmid) ?? 0.0);
        $price_old  = $is_changed ? (float) $old_price : $cur;
        $price_new  = $is_changed ? (float) $new_price : $cur;
        $line_old   = $qty * $price_old;
        $line_new   = $qty * $price_new;
        $total_old += $line_old;
        $total_new += $line_new;
        $out_rows[] = [
            'material_id'       => $rmid,
            'material_name'     => $r['material_name'],
            'quantity_required' => $qty,
            'price_old'         => $price_old,
            'price_new'         => $price_new,
            'line_old'          => $line_old,
            'line_new'          => $line_new,
            'is_changed'        => $is_changed,
        ];
    }

    $rate = ($total_old > 0) ? (($total_new - $total_old) / $total_old) * 100 : 0.0;

    return [
        'product_id'   => $pid,
        'product_name' => $prod['product_name'],
        'rows'         => $out_rows,
        'total_old'    => $total_old,
        'total_new'    => $total_new,
        'change_rate'  => round($rate, 2),
    ];
}

/* =====================================================================
 *  Check biến động giá (page price_change_check) — so giá NCC vừa báo với
 *  giá cũ TRƯỚC KHI quyết định mua, không ghi gì vào DB (chỉ tính preview).
 * ===================================================================== */

/**
 * Giá mua gần nhất của 1 sản phẩm mua trực tiếp (không qua công thức) — lấy thẳng
 * `stock_imports.unit_price` (KHÔNG gồm CPMH, khác `im_get_price_incl()` vốn đọc
 * `product_purchase_prices.price_including_tax`) của lần nhập gần nhất
 * (type_import='fg_receipt_purchase' — dòng thành phẩm mua trong phiếu
 * row_material_receiving, xem `ir_item_type()`). Trả kèm `date` cho tooltip.
 */
function pcc_product_last_price($product_id)
{
    $pid = (int) $product_id;
    if ($pid <= 0) return null;
    $row = db_fetch_row(
        "SELECT unit_price, created_at FROM stock_imports
         WHERE product_id = $pid AND type_import = 'fg_receipt_purchase'
         ORDER BY created_at DESC, id DESC LIMIT 1"
    );
    if (!$row || $row['unit_price'] === null || $row['unit_price'] === '') return null;
    return ['price' => (float) $row['unit_price'], 'date' => $row['created_at']];
}

/** Lịch sử biến động giá (bảng purchase_price_changes) của 1 sản phẩm hoặc 1 NVL, mới->cũ. */
function pcc_price_history($type, $id)
{
    $id = (int) $id;
    if ($id <= 0) return [];
    ppc_ensure_table();
    $field = ($type === 'product') ? 'product_id' : 'material_id';
    $rows = db_fetch_array(
        "SELECT old_price, new_price, change_rate, created_at
         FROM purchase_price_changes
         WHERE $field = $id
         ORDER BY created_at DESC, id DESC"
    ) ?: [];
    foreach ($rows as &$r) {
        $r['old_price']   = (float) $r['old_price'];
        $r['new_price']   = (float) $r['new_price'];
        $r['change_rate'] = (float) $r['change_rate'];
    }
    unset($r);
    return $rows;
}

/**
 * Upsert material_purchase_prices.purchase_price (+ purchase_price_includes_purchase_cost
 * nếu truyền). Khi update sẽ refresh luôn last_updated_at = NOW() để dòng vừa update
 * trở thành "latest" cho lần tra cứu sau.
 */
function ir_save_material_purchase_price($material_id, $price, $price_incl = null)
{
    $mid = (int) $material_id;
    // Không giới hạn 4 số thập phân — cho phép 5/6/7/8 số (khớp DECIMAL(20,8) của
    // material_purchase_prices.purchase_price). Trước đây ép (int) round() làm mất chính xác
    // Đơn giá nhập tay dạng "258.3333". round(...,8) chỉ để cắt phần đuôi lỗi làm tròn nhị phân
    // (vd 258.33330000000004) chứ không giới hạn số chữ số thập phân thực tế user nhập.
    $p   = round((float) $price, 8);
    if ($mid <= 0 || $p < 0) return 0;

    $rid = ir_get_latest_material_price_id($mid);
    $upd = ['purchase_price' => $p, 'last_updated_at' => date('Y-m-d H:i:s')];
    if ($price_incl !== null) {
        $upd['purchase_price_includes_purchase_cost'] = round((float) $price_incl, 8);
    }

    if ($rid > 0) {
        db_update('material_purchase_prices', $upd, "id = $rid");
        return $rid;
    }
    $data = array_merge(['material_id' => $mid], $upd);
    if ($price_incl === null) $data['purchase_price_includes_purchase_cost'] = $p;
    return (int) db_insert('material_purchase_prices', $data);
}

/** Cộng `delta` (có thể âm) vào material_inventory; tạo dòng mới nếu chưa có. */
function ir_adjust_material_inventory($material_id, $delta)
{
    $mid = (int) $material_id;
    $d   = (float) $delta;
    if ($mid <= 0 || $d == 0) return false;
    $row = db_fetch_row("SELECT id, quantity FROM material_inventory
                         WHERE material_id = $mid LIMIT 1");
    if ($row) {
        $new = (float) $row['quantity'] + $d;
        db_update('material_inventory', ['quantity' => $new], 'id = ' . (int) $row['id']);
    } else {
        db_insert('material_inventory', ['material_id' => $mid, 'quantity' => $d]);
    }
    return true;
}

/** Cộng `delta` (có thể âm) vào finished_goods_inventory; tạo dòng mới nếu chưa có. */
function ir_adjust_product_inventory($product_id, $delta)
{
    $pid = (int) $product_id;
    $d   = (float) $delta;
    if ($pid <= 0 || $d == 0) return false;
    $row = db_fetch_row("SELECT id, quantity FROM finished_goods_inventory
                         WHERE product_id = $pid LIMIT 1");
    if ($row) {
        $new = (float) $row['quantity'] + $d;
        db_update('finished_goods_inventory', ['quantity' => $new], 'id = ' . (int) $row['id']);
    } else {
        db_insert('finished_goods_inventory', ['product_id' => $pid, 'quantity' => $d]);
    }
    return true;
}

/* ============================================================
 *  Build interpretation + transactions
 * ============================================================ */

/** "Mua hàng {supplier_name} trị giá {amount} đ" — auto cắt 200 ký tự. */
function ir_build_interpretation($supplier_name, $total_value)
{
    $s = trim((string) $supplier_name);
    if ($s === '') $s = '(chưa có NCC)';
    $amt = number_format((int) round((float) $total_value), 0, ',', ',') . ' đ';
    $txt = 'Mua hàng ' . $s . ' trị giá ' . $amt;
    $max = 200;
    if (mb_strlen($txt, 'UTF-8') > $max) {
        $txt = mb_substr($txt, 0, $max, 'UTF-8') . '...';
    }
    return $txt;
}

/** Xóa transactions của 1 invoice (theo reference_type='row_material_receiving'). */
function ir_delete_transactions_for_invoice($invoice_id)
{
    $iid = (int) $invoice_id;
    if ($iid <= 0) return;
    db_query("DELETE FROM transactions
              WHERE reference_type = 'row_material_receiving'
                AND reference_id = $iid");
}

/* ============================================================
 *  raw_material_purchase_data — bảng tổng hợp NVL mua.
 *  created_at lưu DATE-only (Y-m-d 00:00:00) từ #record-datetime.
 *  Nhiều batch trùng (material, supplier, ngày) sẽ COLLIDE → UPSERT.
 * ============================================================ */

function ir_date_only_dt($dt)
{
    if ($dt === null) return null;
    $dt = trim((string) $dt);
    if ($dt === '') return null;
    $t = strtotime($dt);
    if (!$t) return null;
    return date('Y-m-d 00:00:00', $t);
}

function ir_rmp_write_batch($items, $supplier_id, $created_at)
{
    $sid  = (int) $supplier_id;
    $date = ir_date_only_dt($created_at);
    if ($date === null) $date = date('Y-m-d 00:00:00');
    $date_safe = escape_string($date);
    $sid_cond  = $sid > 0 ? "AND supplier_id = $sid" : '';
    foreach ((array) $items as $it) {
        $mid = (int) ($it['material_id'] ?? 0);
        $qty = (float) ($it['quantity']  ?? 0);
        if ($mid <= 0 || $qty <= 0) continue;
        $price      = (float) ($it['unit_price']  ?? 0);
        $vat_amount = (float) ($it['vat_amount']  ?? 0);
        $other_cost = (float) ($it['other_cost']  ?? 0);
        $amount     = $qty * $price;
        $total      = (float) ($it['total'] ?? ($amount + $vat_amount + $other_cost));
        db_query("DELETE FROM raw_material_purchase_data
                  WHERE material_id = $mid $sid_cond AND created_at = '$date_safe'");
        db_insert('raw_material_purchase_data', [
            'material_id'           => $mid,
            'supplier_id'           => $sid > 0 ? $sid : null,
            'quantity'              => $qty,
            'unit_price'            => $price,
            'amount'                => $amount,
            'vat_amount'            => $vat_amount,
            'other_cost'            => $other_cost,
            'total_inventory_value' => $total,
            'created_at'            => $date,
        ]);
    }
}

function ir_rmp_delete_batch($items, $supplier_id, $created_at)
{
    $sid  = (int) $supplier_id;
    $date = ir_date_only_dt($created_at);
    if ($date === null) return;
    $date_safe = escape_string($date);
    $sid_cond  = $sid > 0 ? "AND supplier_id = $sid" : '';
    foreach ((array) $items as $it) {
        $mid = (int) ($it['material_id'] ?? 0);
        if ($mid <= 0) continue;
        db_query("DELETE FROM raw_material_purchase_data
                  WHERE material_id = $mid $sid_cond AND created_at = '$date_safe'");
    }
}

/* ============================================================
 *  RECORD — Ghi 1 phiếu nhập NVL (btn-record)
 * ============================================================ */

/**
 * Ghi 1 phiếu nhập NVL mua.
 * Input:
 *   $items = [
 *     {
 *       material_id, quantity, unit_price, vat_rate, vat_amount,
 *       other_cost, amount, total
 *     }, ...
 *   ]
 *   $supplier_id, $created_at ('Y-m-d H:i:s'), $je (bút toán user-override).
 * Trả invoice_id (>0) hoặc 0.
 */
/** Suy loại dòng: 'material' (mặc định) hoặc 'product' theo item_type / id nào được truyền. */
function ir_item_type($it)
{
    $t = trim((string) ($it['item_type'] ?? ''));
    if ($t === 'product' || $t === 'material') return $t;
    return ((int) ($it['product_id'] ?? 0)) > 0 ? 'product' : 'material';
}

/**
 * Trích các dòng NVL (material_id, qty, price_incl = total/qty) từ payload items thô
 * của phiếu nhập — dùng để khớp với đơn đặt hàng NVL đã lưu (module order_material).
 */
function ir_extract_material_lines($items)
{
    $lines = [];
    foreach ((array) $items as $it) {
        if (ir_item_type($it) !== 'material') continue;
        $mid = (int) ($it['material_id'] ?? 0);
        $qty = (float) ($it['quantity'] ?? 0);
        $tot = (float) ($it['total'] ?? 0);
        if ($mid <= 0 || $qty <= 0) continue;
        $lines[] = ['material_id' => $mid, 'qty' => $qty, 'price_incl' => $qty > 0 ? $tot / $qty : 0.0];
    }
    return $lines;
}

/** Mirror ir_extract_material_lines() cho dòng THÀNH PHẨM — khớp đơn cà phê (oc_orders). */
function ir_extract_product_lines($items)
{
    $lines = [];
    foreach ((array) $items as $it) {
        if (ir_item_type($it) !== 'product') continue;
        $pid = (int) ($it['product_id'] ?? 0);
        $qty = (float) ($it['quantity'] ?? 0);
        $tot = (float) ($it['total'] ?? 0);
        if ($pid <= 0 || $qty <= 0) continue;
        $lines[] = ['product_id' => $pid, 'qty' => $qty, 'price_incl' => $qty > 0 ? $tot / $qty : 0.0];
    }
    return $lines;
}

/** Xây purchase_costs[] (shape của im_pfp_write_batch) từ 1 item hợp nhất (vat_amount + other_cost). */
function ir_item_purchase_costs($it)
{
    $costs = [];
    $vat_r = (float) ($it['vat_rate']   ?? 0);
    $vat_a = (float) ($it['vat_amount'] ?? 0);
    $oth   = (float) ($it['other_cost'] ?? 0);
    if ($vat_a > 0 || $vat_r > 0) {
        $costs[] = ['description' => 'VAT ' . rtrim(rtrim(number_format($vat_r, 2, '.', ''), '0'), '.') . '%', 'price' => $vat_a];
    }
    if ($oth > 0) {
        $costs[] = ['description' => 'CP khác', 'price' => $oth];
    }
    return $costs;
}

/** Gắn 'purchase_costs' (shape im_pfp_write_batch cần) vào từng item thành phẩm. */
function ir_with_purchase_costs($items)
{
    return array_map(function ($it) {
        $it['purchase_costs'] = ir_item_purchase_costs($it);
        return $it;
    }, (array) $items);
}

function ir_record_batch($items, $supplier_id, $created_at = null, $je = null)
{
    if (!is_array($items) || empty($items)) return 0;
    $sid = (int) $supplier_id;
    if ($sid <= 0) return 0;
    $ca  = ir_sanitize_datetime($created_at);

    // 1) Tổng để insert invoice
    $purchase_cost   = 0.0;  // Σ (vat + other_cost) = tổng CPMH
    $inventory_value = 0.0;  // Σ total = tổng giá trị đơn hàng
    foreach ($items as $it) {
        $vat   = (float) ($it['vat_amount']  ?? 0);
        $oth   = (float) ($it['other_cost']  ?? 0);
        $total = (float) ($it['total']       ?? 0);
        $purchase_cost   += $vat + $oth;
        $inventory_value += $total;
    }

    // 2) Insert stock_import_invoices
    $invoice_data = [
        'purchase_cost'   => $purchase_cost,
        'inventory_value' => $inventory_value,
        'supplier_id'     => $sid,
    ];
    if ($ca !== null) $invoice_data['created_at'] = $ca;
    $invoice_id = (int) db_insert('stock_import_invoices', $invoice_data);
    if ($invoice_id <= 0) return 0;

    // 2b) Insert warehouse_receipts (Hóa đơn nhập kho — bản kế toán)
    $wr_data = [
        'supplier_id'             => $sid,
        'warehouse_receipt_value' => $inventory_value,
        'purchasing_cost'         => $purchase_cost,
        'import_invoice_id'       => $invoice_id,
    ];
    if ($ca !== null) $wr_data['created_at'] = $ca;
    db_insert('warehouse_receipts', $wr_data);

    // 3) Resolve interpretation theo supplier_name + inventory_value
    $sup = ir_get_supplier($sid);
    $supplier_name = $sup ? $sup['supplier_name'] : '';
    $interp = ir_build_interpretation($supplier_name, $inventory_value);

    // 4) Từng dòng: stock_imports + CPMH rows + cộng tồn + upsert giá (rẽ nhánh NVL / thành phẩm)
    $material_items = [];
    $product_items  = [];
    foreach ($items as $it) {
        $type   = ir_item_type($it);
        $qty    = (float) ($it['quantity']  ?? 0);
        $price  = (float) ($it['unit_price'] ?? 0);
        $vat_r  = (float) ($it['vat_rate']   ?? 0);
        $oth    = (float) ($it['other_cost'] ?? 0);
        $note   = trim((string) ($it['other_cost_note'] ?? ''));
        $incl   = !empty($it['vat_includes_other_cost']) ? 1 : 0;
        $total  = (float) ($it['total']      ?? 0);
        $mid    = (int) ($it['material_id'] ?? 0);
        $pid    = (int) ($it['product_id']  ?? 0);
        $id     = $type === 'product' ? $pid : $mid;
        if ($id <= 0 || $qty <= 0) continue;

        $si_data = [
            'product_id'              => $type === 'product' ? $id : null,
            'material_id'             => $type === 'material' ? $id : null,
            'quantity'                => $qty,
            'unit_price'              => $price,
            'vat_includes_other_cost' => $incl,
            'interpretation'          => $interp,
            'type_import'             => $type === 'product' ? 'fg_receipt_purchase' : 'row_material_receiving',
            'import_invoice_id'       => $invoice_id,
        ];
        if ($ca !== null) $si_data['created_at'] = $ca;
        $si_id = (int) db_insert('stock_imports', $si_data);

        // CPMH rows: VAT (encode rate trong description) + CP khác (chỉ nếu > 0, kèm note)
        foreach (ir_item_purchase_costs($it) as $c) {
            $cpmh = ['stock_import_id' => $si_id, 'description' => $c['description'], 'price' => $c['price']];
            if ($c['description'] === 'CP khác' && $note !== '') $cpmh['note'] = $note;
            if ($ca !== null) $cpmh['created_at'] = $ca;
            db_insert('stock_import_purchase_costs', $cpmh);
        }

        $pit = $qty > 0 ? ($total / $qty) : $price;
        if ($type === 'product') {
            $product_items[] = $it;
            ir_adjust_product_inventory($id, $qty);
            $old_pit = im_get_price_incl($id);
            im_save_latest_purchase_price($id, $price, $ca, $pit);
            ppc_record($id, null, $old_pit, round($pit, 2), $ca, im_get_product_name($id));
        } else {
            $material_items[] = $it;
            ir_adjust_material_inventory($id, $qty);
            // Biến động: old = purchase_price_includes_purchase_cost đang lưu (đọc TRƯỚC upsert);
            //            new = pit (= total / quantity). Khác nhau → ghi purchase_price_changes.
            $old_pit = ir_get_price_incl($id);
            ir_save_material_purchase_price($id, $price, $pit);
            ppc_record(null, $id, $old_pit, round($pit), $ca, ir_get_material_name($id));
        }
    }

    // 5) Transactions: 2 dòng (Dr 152 / Cr 331, amount = inventory_value)
    $resolved = ir_je_resolve($je, '152', '331', $inventory_value);
    je_insert_pairs('row_material_receiving', $invoice_id, je_entries_from_payload($je, $resolved), $ca);

    // 6) raw_material_purchase_data (NVL) + purchased_finished_product_data (thành phẩm):
    //    1 row / item / supplier / ngày. im_pfp_write_batch cần shape 'purchase_costs'
    //    (VAT + CP khác) để tính other_cost/total_inventory_value — build từ item hợp nhất.
    ir_rmp_write_batch($material_items, $sid, $ca);
    im_pfp_write_batch(ir_with_purchase_costs($product_items), $sid, $ca);

    // 6b) Hook order_coffee: trừ tồn bao bì tại NCC theo thành phẩm gia công nhập kho.
    im_coffee_packaging_hook($product_items, $sid, $invoice_id, $ca);

    // 7) RECOST (Phần 2): nếu phiếu nhập được ghi với ngày trong quá khứ, các phiếu xuất
    //    dùng sản xuất sau mốc đó phải tính lại giá vốn theo 2 lớp. Chỉ áp dụng cho NVL.
    $recost_from = $ca !== null ? $ca : date('Y-m-d H:i:s');
    $recost_mids = [];
    foreach ($material_items as $it) { $m = (int) ($it['material_id'] ?? 0); if ($m > 0) $recost_mids[$m] = true; }
    foreach (array_keys($recost_mids) as $m) {
        mic_recost_material_issues($m, $recost_from);
    }

    return $invoice_id;
}

/* ============================================================
 *  HISTORY — danh sách + pagination + chi tiết 1 batch
 * ============================================================ */

/**
 * Đếm tổng số batch type_import='row_material_receiving'
 * (1 batch = 1 group theo created_at, gom các stock_imports cùng giây).
 */
function ir_count_batches()
{
    $row = db_fetch_row("SELECT COUNT(DISTINCT DATE_FORMAT(created_at, '%Y-%m-%d %H:%i:%s')) AS n
                         FROM stock_imports
                         WHERE type_import IN ('row_material_receiving','fg_receipt_purchase')");
    return $row ? (int) $row['n'] : 0;
}

/**
 * Lấy 1 trang lịch sử các batch row_material_receiving.
 * $page = 1-based, $per_page = 5.
 * Mỗi batch: { group_key, created_at, date_display, summary, invoice_id,
 *              supplier_id, supplier_name, items[] }
 */
function ir_get_history_page($page = 1, $per_page = 5)
{
    $page = max(1, (int) $page);
    $per  = max(1, (int) $per_page);
    $off  = ($page - 1) * $per;

    $sql_groups = "SELECT DATE_FORMAT(created_at, '%Y-%m-%d %H:%i:%s') AS group_key,
                          MIN(created_at) AS created_at
                   FROM stock_imports
                   WHERE type_import IN ('row_material_receiving','fg_receipt_purchase')
                   GROUP BY group_key
                   ORDER BY created_at DESC
                   LIMIT $per OFFSET $off";
    $groups = db_fetch_array($sql_groups) ?: [];

    $batches = [];
    foreach ($groups as $g) {
        $b = ir_get_batch($g['group_key']);
        if ($b) $batches[] = $b;
    }
    return $batches;
}

/**
 * Lấy chi tiết 1 batch theo group_key. Gồm cả dòng NVL (material_id) lẫn dòng thành
 * phẩm (product_id) — gộp từ flow product_buy đã gỡ bỏ (type_import='fg_receipt_purchase').
 * items[i]: { import_id, item_type, material_id, material_name, unit, quantity, unit_price,
 *             vat_rate, vat_amount, other_cost, other_cost_note, vat_includes_other_cost,
 *             warehouse_label, amount, total }
 */
function ir_get_batch($group_key)
{
    $key = escape_string((string) $group_key);
    if ($key === '') return null;

    $sql = "SELECT si.id AS import_id, si.material_id, si.product_id, si.quantity, si.unit_price,
                   si.vat_includes_other_cost,
                   si.interpretation, si.created_at, si.import_invoice_id,
                   COALESCE(m.material_name, p.product_name) AS item_name,
                   COALESCE(m.unit, p.unit) AS unit
            FROM stock_imports si
            LEFT JOIN material_information m ON m.id = si.material_id
            LEFT JOIN products p ON p.id = si.product_id
            WHERE DATE_FORMAT(si.created_at, '%Y-%m-%d %H:%i:%s') = '$key'
              AND si.type_import IN ('row_material_receiving','fg_receipt_purchase')
            ORDER BY si.id ASC";
    $rows = db_fetch_array($sql) ?: [];
    if (empty($rows)) return null;

    $invoice_id = (int) ($rows[0]['import_invoice_id'] ?? 0);
    $invoice = $invoice_id > 0
        ? db_fetch_row("SELECT id, purchase_cost, inventory_value, supplier_id, created_at
                        FROM stock_import_invoices WHERE id = $invoice_id LIMIT 1")
        : null;

    $supplier_id   = $invoice && $invoice['supplier_id'] !== null ? (int) $invoice['supplier_id'] : 0;
    $supplier_name = '';
    if ($supplier_id > 0) {
        $s = db_fetch_row("SELECT supplier_name FROM suppliers WHERE id = $supplier_id LIMIT 1");
        $supplier_name = $s ? $s['supplier_name'] : '';
    }

    $items = [];
    foreach ($rows as $r) {
        $iid = (int) $r['import_id'];
        $mid = (int) $r['material_id'];
        $pid = (int) $r['product_id'];
        $item_type = $pid > 0 ? 'product' : 'material';

        $cpmh = db_fetch_array("SELECT description, note, price
                                FROM stock_import_purchase_costs
                                WHERE stock_import_id = $iid
                                ORDER BY id ASC") ?: [];
        $vat_rate   = 0.0;
        $vat_amount = 0.0;
        $other_cost = 0.0;
        $other_cost_note = '';
        foreach ($cpmh as $c) {
            $desc = (string) $c['description'];
            $val  = (float) $c['price'];
            if (preg_match('/^VAT\s+([\d.]+)%/i', $desc, $m)) {
                $vat_rate   = (float) $m[1];
                $vat_amount = $val;
            } elseif (stripos($desc, 'CP khác') !== false || stripos($desc, 'CP khac') !== false) {
                $other_cost = $val;
                $other_cost_note = (string) ($c['note'] ?? '');
            }
        }
        $qty    = (float) $r['quantity'];
        $price  = (float) $r['unit_price'];
        $amount = $qty * $price;
        $total  = $amount + $vat_amount + $other_cost;

        $items[] = [
            'import_id'               => $iid,
            'item_type'               => $item_type,
            'material_id'             => $mid,
            'product_id'              => $pid,
            'material_name'           => $r['item_name'] ?: ('#' . ($item_type === 'product' ? $pid : $mid)),
            'unit'                    => $r['unit'] ?? '',
            'warehouse_label'         => $item_type === 'product' ? 'Kho TP' : 'Kho NVL',
            'quantity'                => $qty,
            'unit_price'              => $price,
            'vat_rate'                => $vat_rate,
            'vat_amount'              => $vat_amount,
            'vat_includes_other_cost' => (int) $r['vat_includes_other_cost'] === 1,
            'other_cost'              => $other_cost,
            'other_cost_note'         => $other_cost_note,
            'amount'                  => $amount,
            'total'                   => $total,
        ];
    }

    $hist_date    = history_date_display($rows[0]['created_at']);
    $date_display = $hist_date['text'];
    $date_color   = $hist_date['color'];

    $inventory_value = $invoice ? (float) $invoice['inventory_value'] : 0;
    $purchase_cost   = $invoice ? (float) $invoice['purchase_cost']   : 0;
    $summary = ir_build_interpretation($supplier_name, $inventory_value);

    return [
        'group_key'       => (string) $group_key,
        'created_at'      => $rows[0]['created_at'],
        'date_display'    => $date_display,
        'date_color'      => $date_color,
        'summary'         => $summary,
        'invoice_id'      => $invoice_id,
        'supplier_id'     => $supplier_id,
        'supplier_name'   => $supplier_name,
        'inventory_value' => $inventory_value,
        'purchase_cost'   => $purchase_cost,
        'items'           => $items,
    ];
}

/* ============================================================
 *  EDIT — sửa 1 batch (delete-and-reinsert per batch)
 * ============================================================ */

/**
 * Sửa 1 batch: strategy = rollback tồn cũ + xóa stock_imports cũ (cascade CPMH)
 * + re-insert toàn bộ batch.
 * Trả invoice_id (>0) hoặc 0.
 */
function ir_edit_batch($group_key, $items, $supplier_id, $created_at = null, $je = null)
{
    $old = ir_get_batch($group_key);
    if (!$old) return 0;
    $invoice_id      = (int) $old['invoice_id'];
    $old_supplier_id = (int) ($old['supplier_id'] ?? 0);
    $old_created_at  = (string) ($old['created_at'] ?? $group_key);

    $old_material_items = array_values(array_filter($old['items'], function ($oi) { return ($oi['item_type'] ?? 'material') === 'material'; }));
    $old_product_items  = array_values(array_filter($old['items'], function ($oi) { return ($oi['item_type'] ?? 'material') === 'product'; }));

    // 0) Biến động giá: capture old_price (lần nhập gần nhất TRƯỚC batch đang sửa) cho
    //    từng NVL/thành phẩm trong items mới, rồi xoá các dòng biến động cũ của batch này.
    $old_pit_map = []; // key = "material:5" hoặc "product:5"
    foreach ((array) $items as $it) {
        $type = ir_item_type($it);
        $id   = $type === 'product' ? (int) ($it['product_id'] ?? 0) : (int) ($it['material_id'] ?? 0);
        $key2 = $type . ':' . $id;
        if ($id > 0 && !array_key_exists($key2, $old_pit_map)) {
            $old_pit_map[$key2] = $type === 'product'
                ? im_prev_price_incl_before($id, $group_key)
                : ir_prev_price_incl_before($id, $group_key);
        }
    }
    ppc_delete_for_batch('material_id', array_column($old_material_items, 'material_id'), $old_created_at);
    ppc_delete_for_batch('product_id',  array_column($old_product_items,  'product_id'),  $old_created_at);

    // 1) Rollback tồn: material_inventory (NVL) / finished_goods_inventory (thành phẩm)
    foreach ($old_material_items as $oi) {
        ir_adjust_material_inventory((int) $oi['material_id'], -(float) $oi['quantity']);
    }
    foreach ($old_product_items as $oi) {
        ir_adjust_product_inventory((int) $oi['product_id'], -(float) $oi['quantity']);
    }

    // 1b) Xoá rows cũ trong raw_material_purchase_data / purchased_finished_product_data
    ir_rmp_delete_batch($old_material_items, $old_supplier_id, $old_created_at);
    im_pfp_delete_batch($old_product_items, $old_supplier_id, $old_created_at);

    // 2) Xóa stock_imports cũ (cascade sẽ tự dọn stock_import_purchase_costs)
    $key = escape_string($group_key);
    db_query("DELETE FROM stock_imports
              WHERE DATE_FORMAT(created_at, '%Y-%m-%d %H:%i:%s') = '$key'
                AND type_import IN ('row_material_receiving','fg_receipt_purchase')");

    // 3) Tính tổng mới
    $purchase_cost   = 0.0;
    $inventory_value = 0.0;
    foreach ((array) $items as $it) {
        $purchase_cost   += (float) ($it['vat_amount'] ?? 0) + (float) ($it['other_cost'] ?? 0);
        $inventory_value += (float) ($it['total']      ?? 0);
    }

    // 4) Update / insert stock_import_invoices (giữ id cũ nếu có)
    $sid = (int) $supplier_id;
    $ca  = ir_sanitize_datetime($created_at);
    $upd = [
        'purchase_cost'   => $purchase_cost,
        'inventory_value' => $inventory_value,
        'supplier_id'     => $sid > 0 ? $sid : null,
    ];
    if ($ca !== null) $upd['created_at'] = $ca;
    if ($invoice_id > 0) {
        db_update('stock_import_invoices', $upd, 'id = ' . $invoice_id);
    } else {
        $invoice_id = (int) db_insert('stock_import_invoices', $upd);
        if ($invoice_id <= 0) return 0;
    }

    // 4b) Upsert warehouse_receipts theo import_invoice_id
    $wr = [
        'supplier_id'             => $sid > 0 ? $sid : null,
        'warehouse_receipt_value' => $inventory_value,
        'purchasing_cost'         => $purchase_cost,
        'import_invoice_id'       => $invoice_id,
    ];
    if ($ca !== null) $wr['created_at'] = $ca;
    $wr_row = db_fetch_row("SELECT id FROM warehouse_receipts WHERE import_invoice_id = $invoice_id LIMIT 1");
    if ($wr_row) {
        db_update('warehouse_receipts', $wr, 'id = ' . (int) $wr_row['id']);
    } else {
        db_insert('warehouse_receipts', $wr);
    }

    // 5) Re-insert stock_imports + CPMH + cộng tồn + upsert giá (rẽ nhánh NVL / thành phẩm)
    $sup = ir_get_supplier($sid);
    $supplier_name = $sup ? $sup['supplier_name'] : '';
    $interp = ir_build_interpretation($supplier_name, $inventory_value);

    $new_material_items = [];
    $new_product_items  = [];
    foreach ((array) $items as $it) {
        $type   = ir_item_type($it);
        $qty    = (float) ($it['quantity']  ?? 0);
        $price  = (float) ($it['unit_price'] ?? 0);
        $note   = trim((string) ($it['other_cost_note'] ?? ''));
        $incl   = !empty($it['vat_includes_other_cost']) ? 1 : 0;
        $total  = (float) ($it['total']      ?? 0);
        $mid    = (int) ($it['material_id'] ?? 0);
        $pid    = (int) ($it['product_id']  ?? 0);
        $id     = $type === 'product' ? $pid : $mid;
        if ($id <= 0 || $qty <= 0) continue;

        $si_data = [
            'product_id'              => $type === 'product' ? $id : null,
            'material_id'             => $type === 'material' ? $id : null,
            'quantity'                => $qty,
            'unit_price'              => $price,
            'vat_includes_other_cost' => $incl,
            'interpretation'          => $interp,
            'type_import'             => $type === 'product' ? 'fg_receipt_purchase' : 'row_material_receiving',
            'import_invoice_id'       => $invoice_id,
        ];
        if ($ca !== null) $si_data['created_at'] = $ca;
        $si_id = (int) db_insert('stock_imports', $si_data);

        foreach (ir_item_purchase_costs($it) as $c) {
            $cpmh = ['stock_import_id' => $si_id, 'description' => $c['description'], 'price' => $c['price']];
            if ($c['description'] === 'CP khác' && $note !== '') $cpmh['note'] = $note;
            if ($ca !== null) $cpmh['created_at'] = $ca;
            db_insert('stock_import_purchase_costs', $cpmh);
        }

        $pit = $qty > 0 ? ($total / $qty) : $price;
        $key2 = $type . ':' . $id;
        $old_pit = $old_pit_map[$key2] ?? null;
        if ($type === 'product') {
            $new_product_items[] = $it;
            ir_adjust_product_inventory($id, $qty);
            im_save_latest_purchase_price($id, $price, $ca, $pit);
            ppc_record($id, null, $old_pit, round($pit, 2), $ca, im_get_product_name($id));
        } else {
            $new_material_items[] = $it;
            ir_adjust_material_inventory($id, $qty);
            ir_save_material_purchase_price($id, $price, $pit);
            ppc_record(null, $id, $old_pit, round($pit), $ca, ir_get_material_name($id));
        }
    }

    // 6) Transactions: xóa + insert lại
    ir_delete_transactions_for_invoice($invoice_id);
    $resolved = ir_je_resolve($je, '152', '331', $inventory_value);
    je_insert_pairs('row_material_receiving', $invoice_id, je_entries_from_payload($je, $resolved), $ca);

    // 7) raw_material_purchase_data / purchased_finished_product_data: re-write theo state mới.
    $ca_new = $ca !== null ? $ca : $old_created_at;
    ir_rmp_write_batch($new_material_items, $sid, $ca_new);
    im_pfp_write_batch(ir_with_purchase_costs($new_product_items), $sid, $ca_new);

    // 7b) Hook order_coffee: re-sync trừ tồn bao bì (idempotent theo invoice).
    im_coffee_packaging_hook($new_product_items, $sid, $invoice_id, $ca_new);

    // 8) RECOST (Phần 2): giá/số lượng nhập đã đổi → tính lại giá vốn các phiếu xuất
    //    dùng sản xuất (export_production) của các NVL liên quan, từ mốc nhập sớm nhất
    //    (min của ngày nhập cũ & mới) trở đi. Ghi đè đồng bộ các bảng phái sinh. Chỉ NVL.
    $recost_mids = [];
    foreach ($old_material_items as $oi) { $m = (int) ($oi['material_id'] ?? 0); if ($m > 0) $recost_mids[$m] = true; }
    foreach ($new_material_items as $it) { $m = (int) ($it['material_id'] ?? 0); if ($m > 0) $recost_mids[$m] = true; }
    $recost_from = $old_created_at;
    if ($ca !== null && strtotime((string) $ca) && strtotime((string) $ca) < strtotime((string) $old_created_at)) {
        $recost_from = $ca;
    }
    foreach (array_keys($recost_mids) as $m) {
        mic_recost_material_issues($m, $recost_from);
    }

    return $invoice_id;
}

/* ============================================================
 *  DELETE — xóa 1 batch (rollback tồn + xóa invoice + transactions)
 * ============================================================ */

function ir_delete_batch($group_key)
{
    $batch = ir_get_batch($group_key);
    if (!$batch) return 0;

    $material_items = array_values(array_filter($batch['items'], function ($it) { return ($it['item_type'] ?? 'material') === 'material'; }));
    $product_items  = array_values(array_filter($batch['items'], function ($it) { return ($it['item_type'] ?? 'material') === 'product'; }));
    $created_at_key = (string) ($batch['created_at'] ?? $group_key);
    $supplier_id    = (int) ($batch['supplier_id'] ?? 0);

    // 1) Rollback tồn: material_inventory (NVL) / finished_goods_inventory (thành phẩm)
    foreach ($material_items as $it) {
        ir_adjust_material_inventory((int) $it['material_id'], -(float) $it['quantity']);
    }
    foreach ($product_items as $it) {
        ir_adjust_product_inventory((int) $it['product_id'], -(float) $it['quantity']);
    }

    // 1b) raw_material_purchase_data / purchased_finished_product_data: xoá rows theo (item, supplier, ngày).
    ir_rmp_delete_batch($material_items, $supplier_id, $created_at_key);
    im_pfp_delete_batch($product_items, $supplier_id, $created_at_key);

    // 1c) purchase_price_changes: xoá các dòng biến động của batch này.
    ppc_delete_for_batch('material_id', array_column($material_items, 'material_id'), $created_at_key);
    ppc_delete_for_batch('product_id',  array_column($product_items,  'product_id'),  $created_at_key);

    // 1d) Hook order_coffee: gỡ các dòng trừ tồn bao bì sinh từ invoice này.
    $invoice_id = (int) $batch['invoice_id'];
    if ($invoice_id > 0 && function_exists('coffee_pkg_remove_by_invoice')) {
        coffee_pkg_remove_by_invoice($invoice_id);
    }

    // 2) Xóa stock_imports (cascade CPMH)
    $key = escape_string($group_key);
    db_query("DELETE FROM stock_imports
              WHERE DATE_FORMAT(created_at, '%Y-%m-%d %H:%i:%s') = '$key'
                AND type_import IN ('row_material_receiving','fg_receipt_purchase')");

    // 3) Xóa transactions + invoice
    if ($invoice_id > 0) {
        ir_delete_transactions_for_invoice($invoice_id);
        db_query("DELETE FROM stock_import_invoices WHERE id = $invoice_id");
    }

    // 4) RECOST (Phần 2): xóa 1 phiếu nhập = bỏ 1 lớp giá → tính lại giá vốn các phiếu
    //    xuất dùng sản xuất từ ngày nhập của phiếu vừa xóa trở đi. Chỉ áp dụng cho NVL.
    foreach ($material_items as $it) {
        $m = (int) ($it['material_id'] ?? 0);
        if ($m > 0) mic_recost_material_issues($m, $created_at_key);
    }

    return count($batch['items']);
}

/* ============================================================
 *  ============================================================
 *  PAGE: other_row_material_receiving (Nhập NVL khác)
 *  ============================================================
 *  - Không có supplier, không có invoice, không có CPMH/VAT/giá.
 *  - Chỉ cộng tồn material_inventory và ghi stock_imports.
 *  - type_import = 'other_row_material_receiving'.
 *  - Bút toán mặc định: Dr 152 / Cr 154 (user có thể override).
 *  - Mỗi item: { material_id, quantity, note }.
 *  - stock_imports.interpretation per-row = note nếu có, fallback general.
 *  ============================================================ */

/* ------------------------------------------------------------
 *  DIỄN GIẢI lịch sử = các tên nghiệp vụ (#je-transaction-name) ghép lại.
 *  Lưu riêng theo batch (group_key = created_at 'Y-m-d H:i:s') trong bảng phụ
 *  other_receiving_batch_summary — không đụng interpretation per-row (= diễn giải
 *  từng NVL). Cắt tối đa 160 ký tự, vượt thì thêm '..'.
 * ------------------------------------------------------------ */

/** Dựng chuỗi diễn giải từ JSON je_entries (mỗi entry có transaction_name). */
function ir2_summary_from_je($je_entries_raw)
{
    $names   = [];
    $decoded = json_decode((string) $je_entries_raw, true);
    if (is_array($decoded)) {
        foreach ($decoded as $e) {
            if (!is_array($e)) continue;
            $n = trim((string) ($e['transaction_name'] ?? ''));
            if ($n !== '') $names[] = $n;
        }
    }
    $full = implode(', ', $names);
    $max  = 160;
    if (mb_strlen($full, 'UTF-8') > $max) {
        $full = mb_substr($full, 0, $max, 'UTF-8') . '..';
    }
    return $full;
}

/** Tạo bảng phụ lưu diễn giải batch (1 lần / request). */
function ir2_ensure_summary_table()
{
    static $done = false;
    if ($done) return;
    db_query("CREATE TABLE IF NOT EXISTS other_receiving_batch_summary (
                group_key  VARCHAR(19) NOT NULL PRIMARY KEY,
                summary    VARCHAR(200) NOT NULL DEFAULT '',
                updated_at DATETIME NULL
              ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    $done = true;
}

function ir2_set_batch_summary($group_key, $summary)
{
    $gk = trim((string) $group_key);
    if ($gk === '') return;
    ir2_ensure_summary_table();
    $g   = escape_string($gk);
    $s   = escape_string((string) $summary);
    $now = date('Y-m-d H:i:s');
    db_query("REPLACE INTO other_receiving_batch_summary (group_key, summary, updated_at)
              VALUES ('$g', '$s', '$now')");
}

function ir2_get_batch_summary($group_key)
{
    $gk = trim((string) $group_key);
    if ($gk === '') return null;
    ir2_ensure_summary_table();
    $g   = escape_string($gk);
    $row = db_fetch_row("SELECT summary FROM other_receiving_batch_summary
                         WHERE group_key = '$g' LIMIT 1");
    return $row ? (string) $row['summary'] : null;
}

function ir2_delete_batch_summary($group_key)
{
    $gk = trim((string) $group_key);
    if ($gk === '') return;
    ir2_ensure_summary_table();
    $g = escape_string($gk);
    db_query("DELETE FROM other_receiving_batch_summary WHERE group_key = '$g'");
}

/** Xóa transactions của 1 batch other (theo reference_type + reference_id). */
function ir2_delete_transactions_for_batch($reference_id)
{
    $rid = (int) $reference_id;
    if ($rid <= 0) return;
    db_query("DELETE FROM transactions
              WHERE reference_type = 'other_row_material_receiving'
                AND reference_id = $rid");
}

/**
 * Tính tổng je-amount cho 1 list items.
 * Mỗi item: { material_id, quantity }.
 * Quy tắc: Σ qty * price_effective, trong đó:
 *   price_effective = purchase_price_includes_purchase_cost (nếu NOT NULL & > 0)
 *                     ngược lại = purchase_price
 * (lấy từ dòng material_purchase_prices mới nhất của material_id)
 */
function ir2_compute_je_amount($items)
{
    if (!is_array($items)) return 0.0;
    $total = 0.0;
    foreach ($items as $it) {
        $mid = (int) ($it['material_id'] ?? 0);
        $qty = (float) ($it['quantity']    ?? 0);
        if ($mid <= 0 || $qty <= 0) continue;
        $p = db_fetch_row("SELECT purchase_price, purchase_price_includes_purchase_cost
                           FROM material_purchase_prices
                           WHERE material_id = $mid
                           ORDER BY last_updated_at DESC, id DESC
                           LIMIT 1");
        if (!$p) continue;
        $incl = $p['purchase_price_includes_purchase_cost'];
        $price = ($incl !== null && (float) $incl > 0)
            ? (float) $incl
            : (float) $p['purchase_price'];
        $total += $qty * $price;
    }
    return $total;
}

/* ============================================================
 *  RECORD — Ghi 1 phiếu nhập NVL khác (btn-record)
 * ============================================================ */

/**
 * Ghi 1 phiếu nhập NVL khác.
 * $items = [ { material_id, quantity, note }, ... ]
 * $general_interp: nội dung input .general_interpretation
 * $created_at: 'Y-m-d H:i:s' (theo record-datetime)
 * $je: { debit, credit, amount } — override bút toán (optional)
 * Trả group_key (string 'Y-m-d H:i:s') hoặc '' nếu lỗi.
 */
function ir2_record_batch($items, $general_interp, $created_at = null, $je = null, $summary = '')
{
    if (!is_array($items) || empty($items)) return '';
    $ca = ir_sanitize_datetime($created_at);
    if ($ca === null) $ca = date('Y-m-d H:i:s');

    $general = trim((string) $general_interp);

    $first_si_id = 0;
    foreach ($items as $it) {
        $mid  = (int) ($it['material_id'] ?? 0);
        $qty  = (float) ($it['quantity']  ?? 0);
        $note = trim((string) ($it['note'] ?? ''));
        if ($mid <= 0 || $qty <= 0) continue;

        $interp = $note !== '' ? $note : $general;

        $si_data = [
            'product_id'        => null,
            'material_id'       => $mid,
            'quantity'          => $qty,
            'unit_price'        => 0,
            'interpretation'    => $interp,
            'type_import'       => 'other_row_material_receiving',
            'import_invoice_id' => null,
            'created_at'        => $ca,
        ];
        $si_id = (int) db_insert('stock_imports', $si_data);
        if ($first_si_id === 0 && $si_id > 0) $first_si_id = $si_id;

        ir_adjust_material_inventory($mid, $qty);
    }

    if ($first_si_id <= 0) return '';

    // Transactions: 2 dòng (Dr 152 / Cr 711, amount = user-input hoặc 0)
    $resolved = ir_je_resolve($je, '152', '711', 0);
    je_insert_pairs('other_row_material_receiving', $first_si_id, je_entries_from_payload($je, $resolved), $ca);

    // Diễn giải lịch sử = các tên nghiệp vụ ghép lại (đã cắt 160). Lưu theo group_key.
    if (trim((string) $summary) !== '') ir2_set_batch_summary($ca, $summary);

    return $ca; // group_key
}

/* ============================================================
 *  HISTORY — danh sách + pagination + chi tiết 1 batch
 * ============================================================ */

function ir2_count_batches()
{
    $row = db_fetch_row("SELECT COUNT(DISTINCT DATE_FORMAT(created_at, '%Y-%m-%d %H:%i:%s')) AS n
                         FROM stock_imports
                         WHERE type_import = 'other_row_material_receiving'");
    return $row ? (int) $row['n'] : 0;
}

function ir2_get_history_page($page = 1, $per_page = 5)
{
    $page = max(1, (int) $page);
    $per  = max(1, (int) $per_page);
    $off  = ($page - 1) * $per;

    $sql_groups = "SELECT DATE_FORMAT(created_at, '%Y-%m-%d %H:%i:%s') AS group_key,
                          MIN(created_at) AS created_at
                   FROM stock_imports
                   WHERE type_import = 'other_row_material_receiving'
                   GROUP BY group_key
                   ORDER BY created_at DESC
                   LIMIT $per OFFSET $off";
    $groups = db_fetch_array($sql_groups) ?: [];

    $batches = [];
    foreach ($groups as $g) {
        $b = ir2_get_batch($g['group_key']);
        if ($b) $batches[] = $b;
    }
    return $batches;
}

/**
 * Lấy chi tiết 1 batch other theo group_key.
 * items[i]: { import_id, material_id, material_name, unit, quantity, note }
 */
function ir2_get_batch($group_key)
{
    $key = escape_string((string) $group_key);
    if ($key === '') return null;

    $sql = "SELECT si.id AS import_id, si.material_id, si.quantity, si.interpretation,
                   si.created_at,
                   m.material_name, m.unit
            FROM stock_imports si
            LEFT JOIN material_information m ON m.id = si.material_id
            WHERE DATE_FORMAT(si.created_at, '%Y-%m-%d %H:%i:%s') = '$key'
              AND si.type_import = 'other_row_material_receiving'
            ORDER BY si.id ASC";
    $rows = db_fetch_array($sql) ?: [];
    if (empty($rows)) return null;

    // Lấy bút toán (nếu có) — reference_id = stock_imports.id đầu tiên trong batch
    $first_si_id = (int) $rows[0]['import_id'];
    $tx_amount = 0.0;
    $tx_debit  = '';
    $tx_credit = '';
    $tx_rows = db_fetch_array("SELECT account_code, type, amount FROM transactions
                                WHERE reference_type = 'other_row_material_receiving'
                                  AND reference_id = $first_si_id
                                ORDER BY id ASC") ?: [];
    foreach ($tx_rows as $t) {
        $tx_amount = (float) $t['amount'];
        if ($t['type'] === 'debit')  $tx_debit  = $t['account_code'];
        if ($t['type'] === 'credit') $tx_credit = $t['account_code'];
    }

    // Mô tả chung: lấy interpretation phổ biến nhất trong batch (thường tất cả các row giống nhau khi
    // user không override per-row). Đơn giản: dùng interpretation của row đầu.
    $general_interp = (string) ($rows[0]['interpretation'] ?? '');

    $items = [];
    foreach ($rows as $r) {
        $note = (string) ($r['interpretation'] ?? '');
        $mid = (int) $r['material_id'];
        // Lấy giá hiệu lực (cho recompute je_amount khi user sửa quantity)
        $price_effective = 0.0;
        if ($mid > 0) {
            $p = db_fetch_row("SELECT purchase_price, purchase_price_includes_purchase_cost
                               FROM material_purchase_prices
                               WHERE material_id = $mid
                               ORDER BY last_updated_at DESC, id DESC
                               LIMIT 1");
            if ($p) {
                $incl = $p['purchase_price_includes_purchase_cost'];
                if ($incl !== null && (float) $incl > 0) {
                    $price_effective = (float) $incl;
                } else {
                    $price_effective = (float) $p['purchase_price'];
                }
            }
        }
        $items[] = [
            'import_id'       => (int) $r['import_id'],
            'material_id'     => $mid,
            'material_name'   => $r['material_name'] ?: ('#' . $mid),
            'unit'            => $r['unit'] ?? '',
            'quantity'        => (float) $r['quantity'],
            'note'            => $note,
            'price_effective' => $price_effective,
        ];
    }

    $hist_date    = history_date_display($rows[0]['created_at']);
    $date_display = $hist_date['text'];
    $date_color   = $hist_date['color'];

    // Diễn giải lịch sử: ưu tiên các tên nghiệp vụ đã lưu (ghép từ #je-transaction-name);
    // fallback mô tả chung / mặc định nếu chưa có.
    $meta_summary = ir2_get_batch_summary((string) $group_key);
    $summary = ($meta_summary !== null && $meta_summary !== '')
        ? $meta_summary
        : ($general_interp !== '' ? $general_interp : 'Nhập NVL khác');

    return [
        'group_key'      => (string) $group_key,
        'created_at'     => $rows[0]['created_at'],
        'date_display'   => $date_display,
        'date_color'     => $date_color,
        'summary'        => $summary,
        'general_interp' => $general_interp,
        'je_debit'       => $tx_debit,
        'je_credit'      => $tx_credit,
        'je_amount'      => $tx_amount,
        'first_si_id'    => $first_si_id,
        'items'          => $items,
    ];
}

/* ============================================================
 *  EDIT — sửa 1 batch (rollback tồn cũ + re-insert)
 * ============================================================ */

function ir2_edit_batch($group_key, $items, $general_interp, $created_at = null, $je = null, $summary = '')
{
    $old = ir2_get_batch($group_key);
    if (!$old) return '';

    // 1) Rollback material_inventory
    foreach ($old['items'] as $oi) {
        ir_adjust_material_inventory((int) $oi['material_id'], -(float) $oi['quantity']);
    }

    // 2) Xóa transactions cũ (reference_id = first_si_id cũ)
    $old_first = (int) $old['first_si_id'];
    if ($old_first > 0) ir2_delete_transactions_for_batch($old_first);

    // 3) Xóa stock_imports cũ
    $key = escape_string($group_key);
    db_query("DELETE FROM stock_imports
              WHERE DATE_FORMAT(created_at, '%Y-%m-%d %H:%i:%s') = '$key'
                AND type_import = 'other_row_material_receiving'");

    // 3b) Bỏ diễn giải cũ (group_key có thể đổi nếu user sửa ngày giờ ghi)
    ir2_delete_batch_summary($group_key);

    // 4) Re-insert toàn bộ
    return ir2_record_batch($items, $general_interp, $created_at, $je, $summary);
}

/* ============================================================
 *  DELETE — xóa 1 batch (rollback tồn + xóa transactions)
 * ============================================================ */

function ir2_delete_batch($group_key)
{
    $batch = ir2_get_batch($group_key);
    if (!$batch) return 0;

    // 1) Rollback material_inventory
    foreach ($batch['items'] as $it) {
        ir_adjust_material_inventory((int) $it['material_id'], -(float) $it['quantity']);
    }

    // 2) Xóa transactions
    $first_si_id = (int) $batch['first_si_id'];
    if ($first_si_id > 0) ir2_delete_transactions_for_batch($first_si_id);

    // 3) Xóa stock_imports
    $key = escape_string($group_key);
    db_query("DELETE FROM stock_imports
              WHERE DATE_FORMAT(created_at, '%Y-%m-%d %H:%i:%s') = '$key'
                AND type_import = 'other_row_material_receiving'");

    // 4) Xóa diễn giải batch
    ir2_delete_batch_summary($group_key);

    return count($batch['items']);
}

/* ============================================================
 *  PHÂN TÍCH NVL — dữ liệu cho "Phiếu nhập kho kèm phân tích"
 * ============================================================ */

/**
 * Số hóa đơn mua hàng đính kèm của 1 lần nhập (theo import_invoice_id của
 * stock_imports → warehouse_receipts → warehouse_receipt_invoices).
 */
function ir_analysis_invoice_count($import_invoice_id)
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

/**
 * CPMH (tổng chi phí mua hàng) của lần nhập 1 NVL vào 1 ngày — tra stock_imports
 * cùng material_id + cùng DATE, rồi cộng stock_import_purchase_costs.
 * Trả: ['cpmh' => float, 'invoices' => int].
 */
function ir_analysis_import_extra($material_id, $date_only)
{
    $mid = (int) $material_id;
    $d   = escape_string((string) $date_only);
    if ($mid <= 0 || $d === '') return ['cpmh' => 0.0, 'invoices' => 0];
    $si = db_fetch_row("SELECT id, import_invoice_id FROM stock_imports
                        WHERE material_id = $mid
                          AND type_import = 'row_material_receiving'
                          AND DATE(created_at) = DATE('$d')
                        ORDER BY id DESC LIMIT 1");
    if (!$si) return ['cpmh' => 0.0, 'invoices' => 0];
    $sum = 0.0;
    foreach (db_fetch_array("SELECT price FROM stock_import_purchase_costs
                             WHERE stock_import_id = " . (int) $si['id']) ?: [] as $c) {
        $sum += (float) $c['price'];
    }
    return ['cpmh' => $sum, 'invoices' => ir_analysis_invoice_count($si['import_invoice_id'])];
}

/**
 * Lượng dùng NVL trong khoảng $days ngày gần nhất (từ production_materials,
 * lọc theo production_receipts.created_at).
 */
function ir_analysis_usage($material_id, $days)
{
    $mid = (int) $material_id;
    $dd  = (int) $days;
    if ($mid <= 0) return 0.0;
    $row = db_fetch_row("SELECT COALESCE(SUM(pm.quantity),0) AS used
                         FROM production_materials pm
                         JOIN production_receipts pr ON pr.id = pm.production_receipt_id
                         WHERE pm.material_id = $mid
                           AND pr.created_at >= (NOW() - INTERVAL $dd DAY)");
    return $row ? (float) $row['used'] : 0.0;
}

/**
 * Phân tích đầy đủ cho 1 danh sách material_id (truyền từ form đang nhập).
 * Trả map: material_id => {
 *   stock, recent[], usage{m1,m3,m6,y1}, products[]
 * }.
 */
/**
 * Định mức gần nhất của 1 NVL cho 1 sản phẩm — tham chiếu production_materials:
 * lấy lần sản xuất GẦN NHẤT của sản phẩm có dùng NVL này, định mức/đơn vị =
 *   production_materials.quantity / production_receipts.quantity.
 * Không có dữ liệu sản xuất → fallback quantity_required (product_materials).
 */
function ir_analysis_latest_norm($material_id, $product_id, $fallback = 0.0)
{
    $mid = (int) $material_id;
    $pid = (int) $product_id;
    if ($mid <= 0 || $pid <= 0) return (float) $fallback;
    $row = db_fetch_row("SELECT pm.quantity AS used, pr.quantity AS prod_qty
                         FROM production_materials pm
                         JOIN production_receipts pr ON pr.id = pm.production_receipt_id
                         WHERE pm.material_id = $mid AND pr.product_id = $pid
                         ORDER BY pr.created_at DESC, pm.id DESC
                         LIMIT 1");
    if (!$row) return (float) $fallback;
    $used = (float) $row['used'];
    $pq   = (float) $row['prod_qty'];
    return $pq > 0 ? ($used / $pq) : $used;
}

function ir_get_material_analysis($material_ids)
{
    $out = [];
    if (!is_array($material_ids)) return $out;
    foreach ($material_ids as $raw_mid) {
        $mid = (int) $raw_mid;
        if ($mid <= 0 || isset($out[$mid])) continue;

        // Tồn hiện tại
        $inv  = db_fetch_row("SELECT quantity FROM material_inventory WHERE material_id = $mid LIMIT 1");
        $stock = $inv ? (float) $inv['quantity'] : 0.0;

        // 5 lần nhập gần nhất (raw_material_purchase_data)
        $recent = [];
        $rows = db_fetch_array("SELECT quantity, unit_price, amount, vat_amount, other_cost,
                                       total_inventory_value, created_at
                                FROM raw_material_purchase_data
                                WHERE material_id = $mid
                                ORDER BY created_at DESC, id DESC
                                LIMIT 5") ?: [];
        foreach ($rows as $r) {
            $date_only = substr((string) $r['created_at'], 0, 10);
            $extra = ir_analysis_import_extra($mid, $date_only);
            $recent[] = [
                'date'      => $r['created_at'],
                'quantity'  => (float) $r['quantity'],
                'unit_price'=> (float) $r['unit_price'],
                'amount'    => (float) $r['amount'],
                'cpmh'      => (float) $extra['cpmh'],
                'other_cost'=> (float) $r['other_cost'],
                'total'     => (float) $r['total_inventory_value'],
                'invoices'  => (int) $extra['invoices'],
            ];
        }

        // Lượng dùng theo mốc thời gian
        $usage = [
            'm1' => ir_analysis_usage($mid, 30),
            'm3' => ir_analysis_usage($mid, 90),
            'm6' => ir_analysis_usage($mid, 180),
            'y1' => ir_analysis_usage($mid, 365),
        ];

        // Sản phẩm dùng NVL: tên SP, định mức (gần nhất từ production_materials), thành phẩm gần nhất.
        $products = [];
        $prows = db_fetch_array("SELECT pm.product_id, p.product_name, pm.quantity_required
                                 FROM product_materials pm
                                 JOIN products p ON p.id = pm.product_id
                                 WHERE pm.material_id = $mid
                                 ORDER BY p.product_name ASC") ?: [];
        foreach ($prows as $pr) {
            $pid = (int) $pr['product_id'];
            $fp = db_fetch_row("SELECT quantity FROM finished_product_production_data
                                WHERE product_id = $pid
                                ORDER BY created_at DESC, id DESC LIMIT 1");
            $products[] = [
                'product_name' => $pr['product_name'],
                'norm'         => ir_analysis_latest_norm($mid, $pid, (float) $pr['quantity_required']),
                'finished'     => $fp ? (float) $fp['quantity'] : 0.0,
            ];
        }

        $out[$mid] = [
            'stock'    => $stock,
            'recent'   => $recent,
            'usage'    => $usage,
            'products' => $products,
        ];
    }
    return $out;
}

/**
 * Phân tích thành phẩm (dòng product_id trong phiếu gộp) — tái dùng nguyên vẹn logic
 * đã có ở flow product_buy cũ (im_get_product_buy_analysis), không viết lại.
 */
function ir_get_product_analysis($product_ids, $ref_date = '')
{
    return function_exists('im_get_product_buy_analysis')
        ? im_get_product_buy_analysis($product_ids, $ref_date)
        : [];
}
