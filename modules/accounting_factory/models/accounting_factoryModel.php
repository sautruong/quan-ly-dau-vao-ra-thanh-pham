<?php
/* =====================================================================
 *  MODULE: KẾ TOÁN - NHÀ MÁY  (TASK 4)
 *  Luồng 1 nguyên vật liệu / 1 sản phẩm: tồn đầu/cuối ngày + biến động.
 *  Tồn theo phương pháp "trừ ngược từ tồn hiện tại" (giống stock_at_point):
 *    - Đầu ngày D : hoàn tác created_at >= 'D 00:00:00' (KHÔNG tính phát sinh ngày D).
 *    - Cuối ngày D: hoàn tác created_at >  'D 23:59:59' (TÍNH phát sinh ngày D).
 * ===================================================================== */

/** Chuẩn hoá 'Y-m-d'; rỗng/không hợp lệ → hôm nay. */
function af_sanitize_date($date)
{
    $d = trim((string) $date);
    if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $d)) {
        $t = strtotime($d);
        if ($t) return date('Y-m-d', $t);
    }
    return date('Y-m-d');
}

/** Điều kiện hoàn tác (phát sinh sau mốc). $mode: 'start'|'end'. */
function af_undo_cond($date_ymd, $mode)
{
    if ($mode === 'start') {
        return "created_at >= '" . escape_string($date_ymd . ' 00:00:00') . "'";
    }
    return "created_at > '" . escape_string($date_ymd . ' 23:59:59') . "'";
}

/** Tìm NVL hoặc thành phẩm theo keyword. $kind: 'material'|'product'. */
function af_search_items($keyword, $kind)
{
    $kw = '%' . escape_string(trim((string) $keyword)) . '%';
    if ($kind === 'material') {
        $rows = db_fetch_array(
            "SELECT id, material_name AS name, COALESCE(NULLIF(unit,''),'') AS unit
             FROM material_information WHERE material_name LIKE '$kw'
             ORDER BY material_name ASC LIMIT 20"
        );
    } else {
        $rows = db_fetch_array(
            "SELECT id, product_name AS name, COALESCE(NULLIF(unit,''),'') AS unit
             FROM products WHERE product_name LIKE '$kw'
             ORDER BY product_name ASC LIMIT 20"
        );
    }
    return $rows ?: [];
}

/** Tồn 1 NVL tại thời điểm (đầu/cuối ngày). */
function af_material_balance_at($material_id, $date_ymd, $mode)
{
    $mid  = (int) $material_id;
    $date = af_sanitize_date($date_ymd);
    $mode = ($mode === 'start') ? 'start' : 'end';
    $undo = af_undo_cond($date, $mode);
    $row  = db_fetch_row(
        "SELECT (minv.quantity
                 - COALESCE((SELECT SUM(quantity) FROM stock_imports WHERE material_id = $mid AND $undo), 0)
                 + COALESCE((SELECT SUM(quantity) FROM stock_exports WHERE material_id = $mid AND $undo), 0)
                ) AS qty_at
         FROM material_inventory minv WHERE minv.material_id = $mid LIMIT 1"
    );
    return $row ? (float) $row['qty_at'] : 0.0;
}

/** Tồn 1 thành phẩm tại thời điểm (đầu/cuối ngày). */
function af_product_balance_at($product_id, $date_ymd, $mode)
{
    $pid  = (int) $product_id;
    $date = af_sanitize_date($date_ymd);
    $mode = ($mode === 'start') ? 'start' : 'end';
    $undo = af_undo_cond($date, $mode);
    $row  = db_fetch_row(
        "SELECT (fgi.quantity
                 - COALESCE((SELECT SUM(quantity) FROM stock_imports WHERE product_id = $pid AND $undo), 0)
                 + COALESCE((SELECT SUM(quantity) FROM stock_exports WHERE product_id = $pid AND $undo), 0)
                ) AS qty_at
         FROM finished_goods_inventory fgi WHERE fgi.product_id = $pid LIMIT 1"
    );
    return $row ? (float) $row['qty_at'] : 0.0;
}

/** Lấy biến động stock_imports của 1 item theo loại, trong [from,to]. */
function af_imports_of($id_col, $id, $type_import, $lo, $hi)
{
    $idc = $id_col === 'material_id' ? 'material_id' : 'product_id';
    $tid = (int) $id;
    $ti  = escape_string($type_import);
    return db_fetch_array(
        "SELECT id, created_at, quantity, unit_price
         FROM stock_imports
         WHERE $idc = $tid AND type_import = '$ti'
           AND created_at BETWEEN '$lo' AND '$hi'
         ORDER BY created_at ASC"
    ) ?: [];
}

/** Lấy biến động stock_exports của 1 item theo loại, trong [from,to]. */
function af_exports_of($id_col, $id, $type_export, $lo, $hi)
{
    $idc = $id_col === 'material_id' ? 'material_id' : 'product_id';
    $tid = (int) $id;
    $te  = escape_string($type_export);
    return db_fetch_array(
        "SELECT id, created_at, quantity, unit_price, total_amount
         FROM stock_exports
         WHERE $idc = $tid AND type_export = '$te'
           AND created_at BETWEEN '$lo' AND '$hi'
         ORDER BY created_at ASC"
    ) ?: [];
}

/**
 * Luồng 1 NVL — 3 bảng độc lập, mỗi bảng tự tính tổng SL + tổng giá trị,
 * render động theo khoảng ngày user chọn. Tồn = truy vấn ngược (ledger).
 *   - Nhập kho (mua NVL)      : raw_material_purchase_data
 *   - Nhập kho khác           : stock_imports (other_row_material_receiving)
 *   - Xuất NVL dùng sản xuất  : production_materials JOIN production_receipts (lấy ngày)
 */
function af_material_flow($material_id, $from, $to)
{
    $mid = (int) $material_id;
    $f   = af_sanitize_date($from);
    $t   = af_sanitize_date($to);
    $lo  = escape_string($f . ' 00:00:00');
    $hi  = escape_string($t . ' 23:59:59');

    // Nhập kho (mua NVL) ← raw_material_purchase_data
    $nhap = [];  $sumQ = 0.0;  $sumV = 0.0;
    $rows = db_fetch_array(
        "SELECT created_at, quantity, total_inventory_value
         FROM raw_material_purchase_data
         WHERE material_id = $mid AND created_at BETWEEN '$lo' AND '$hi'
         ORDER BY created_at ASC"
    ) ?: [];
    foreach ($rows as $r) {
        $q = (float) $r['quantity'];
        $v = (float) $r['total_inventory_value'];
        $sumQ += $q;  $sumV += $v;
        $nhap[] = ['date' => $r['created_at'], 'qty' => $q, 'value' => $v];
    }

    // Nhập kho khác ← stock_imports
    $khac = [];  $sumQK = 0.0;  $sumVK = 0.0;
    foreach (af_imports_of('material_id', $mid, 'other_row_material_receiving', $lo, $hi) as $r) {
        $q = (float) $r['quantity'];
        $v = $q * (float) ($r['unit_price'] ?? 0);
        $sumQK += $q;  $sumVK += $v;
        $khac[] = ['date' => $r['created_at'], 'qty' => $q, 'value' => $v];
    }

    // Xuất NVL dùng sản xuất ← production_materials (ngày từ production_receipts)
    $xuat = [];  $sumQX = 0.0;  $sumVX = 0.0;
    $rows = db_fetch_array(
        "SELECT pr.created_at, pm.quantity, pm.total_cost
         FROM production_materials pm
         JOIN production_receipts pr ON pr.id = pm.production_receipt_id
         WHERE pm.material_id = $mid AND pr.created_at BETWEEN '$lo' AND '$hi'
         ORDER BY pr.created_at ASC"
    ) ?: [];
    foreach ($rows as $r) {
        $q = (float) $r['quantity'];
        $v = (float) $r['total_cost'];
        $sumQX += $q;  $sumVX += $v;
        $xuat[] = ['date' => $r['created_at'], 'qty' => $q, 'value' => $v];
    }

    return [
        'opening'   => af_material_balance_at($mid, $f, 'start'),
        'closing'   => af_material_balance_at($mid, $t, 'end'),
        'nhap_kho'  => ['rows' => $nhap, 'sum_qty' => $sumQ,  'sum_val' => $sumV],
        'nhap_khac' => ['rows' => $khac, 'sum_qty' => $sumQK, 'sum_val' => $sumVK],
        'xuat_sx'   => ['rows' => $xuat, 'sum_qty' => $sumQX, 'sum_val' => $sumVX],
    ];
}

/** Giá vốn 1 lô nhập sản xuất: ưu tiên production_costs_daily.cost_price của bản ghi
 *  investment_production cùng product+created_at; fallback = qty × (vốn NVL/đv + rate). */
function af_production_cost_for($product_id, $created_at, $qty)
{
    $pid = (int) $product_id;
    $ca  = escape_string($created_at);
    $row = db_fetch_row(
        "SELECT pcd.cost_price
         FROM stock_imports iv
         JOIN production_costs_daily pcd ON pcd.stock_imports_id = iv.id
         WHERE iv.type_import = 'investment_production'
           AND iv.product_id = $pid AND iv.created_at = '$ca'
         LIMIT 1"
    );
    if ($row && $row['cost_price'] !== null) {
        return (float) $row['cost_price'];
    }
    // Fallback: tính từ định mức NVL + đơn giá chi phí sản xuất.
    if (function_exists('im_product_material_cost_per_unit_incl')) {
        $rate = function_exists('production_cost_rate') ? production_cost_rate() : 6000.0;
        return (im_product_material_cost_per_unit_incl($pid) + $rate) * (float) $qty;
    }
    return 0.0;
}

/**
 * Luồng 1 sản phẩm — 4 bảng độc lập, mỗi bảng tự tính tổng. Tồn = truy vấn ngược.
 *   - Nhập kho sản xuất       : finished_product_production_data (giá vốn = production_cost)
 *   - Nhập kho khác           : stock_imports (other_receipt)
 *   - Nhập hàng bán trả lại   : sales_returns (giá trị = SL × giá vốn/đv)
 *   - Xuất kho bán hàng       : sales_inventory_issue_data (giá trị = amount)
 */
function af_product_flow($product_id, $from, $to)
{
    $pid = (int) $product_id;
    $f   = af_sanitize_date($from);
    $t   = af_sanitize_date($to);
    $lo  = escape_string($f . ' 00:00:00');
    $hi  = escape_string($t . ' 23:59:59');

    // Nhập kho sản xuất ← finished_product_production_data
    $sx = [];  $sumQ = 0.0;  $sumVS = 0.0;
    $rows = db_fetch_array(
        "SELECT created_at, quantity, production_cost
         FROM finished_product_production_data
         WHERE product_id = $pid AND created_at BETWEEN '$lo' AND '$hi'
         ORDER BY created_at ASC"
    ) ?: [];
    foreach ($rows as $r) {
        $q = (float) $r['quantity'];
        $v = (float) $r['production_cost'];
        $sumQ += $q;  $sumVS += $v;
        $sx[] = ['date' => $r['created_at'], 'qty' => $q, 'value' => $v];
    }

    // Nhập kho khác ← stock_imports
    $khac = [];  $sumQK = 0.0;  $sumVK = 0.0;
    foreach (af_imports_of('product_id', $pid, 'other_receipt', $lo, $hi) as $r) {
        $q = (float) $r['quantity'];
        $v = $q * (float) ($r['unit_price'] ?? 0);
        $sumQK += $q;  $sumVK += $v;
        $khac[] = ['date' => $r['created_at'], 'qty' => $q, 'value' => $v];
    }

    // Nhập hàng bán trả lại ← sales_returns (giá trị = SL × giá vốn/đv)
    $cogsUnit = function_exists('im_product_material_cost_per_unit_incl')
        ? im_product_material_cost_per_unit_incl($pid) + (function_exists('production_cost_rate') ? production_cost_rate() : 6000.0)
        : 0.0;
    $tra = [];  $sumQT = 0.0;  $sumVT = 0.0;
    $rows = db_fetch_array(
        "SELECT created_at, quantity FROM sales_returns
         WHERE product_id = $pid AND created_at BETWEEN '$lo' AND '$hi'
         ORDER BY created_at ASC"
    ) ?: [];
    foreach ($rows as $r) {
        $q = (float) $r['quantity'];
        $v = $q * $cogsUnit;
        $sumQT += $q;  $sumVT += $v;
        $tra[] = ['date' => $r['created_at'], 'qty' => $q, 'value' => $v];
    }

    // Xuất kho bán hàng ← sales_inventory_issue_data
    $ban = [];  $sumQB = 0.0;  $sumVB = 0.0;
    $rows = db_fetch_array(
        "SELECT created_at, quantity, amount FROM sales_inventory_issue_data
         WHERE product_id = $pid AND created_at BETWEEN '$lo' AND '$hi'
         ORDER BY created_at ASC"
    ) ?: [];
    foreach ($rows as $r) {
        $q = (float) $r['quantity'];
        $v = (float) $r['amount'];
        $sumQB += $q;  $sumVB += $v;
        $ban[] = ['date' => $r['created_at'], 'qty' => $q, 'value' => $v];
    }

    return [
        'opening'   => af_product_balance_at($pid, $f, 'start'),
        'closing'   => af_product_balance_at($pid, $t, 'end'),
        'nhap_sx'   => ['rows' => $sx,   'sum_qty' => $sumQ,  'sum_val' => $sumVS],
        'nhap_khac' => ['rows' => $khac, 'sum_qty' => $sumQK, 'sum_val' => $sumVK],
        'ban_tra'   => ['rows' => $tra,  'sum_qty' => $sumQT, 'sum_val' => $sumVT],
        'xuat_ban'  => ['rows' => $ban,  'sum_qty' => $sumQB, 'sum_val' => $sumVB],
    ];
}
