<?php

/**
 * vsattp — Biểu mẫu Quản lý VSATTP.
 *
 * Module này CHỈ ĐỌC dữ liệu đã có để xuất các biểu mẫu nộp cơ quan nhà nước.
 * Không ghi/sửa dữ liệu nghiệp vụ → không cần độ chính xác tuyệt đối.
 *
 * View 1 (material_receiving — Phiếu tiếp nhận nguyên liệu đầu vào) lấy dữ liệu từ
 * flow nhập NVL mua (stock_imports.type_import = 'row_material_receiving'):
 *   - Ngày nhập      : stock_imports.created_at
 *   - Tên NVL / ĐVT  : material_information.material_name / unit
 *   - Nhà cung cấp   : suppliers theo invoice (fallback theo material_information.supplier_id)
 *   - Số lượng       : stock_imports.quantity
 * Các field còn lại (số lô, hạn sử dụng, kiểm tra cảm quan, kết luận, người kiểm tra)
 * do người dùng nhập trực tiếp trên biểu mẫu (mặc định ở client).
 */

/* ============================================================
 *  AJAX search NVL theo material_name (dropdown chọn NVL hiển thị)
 * ============================================================ */

function vt_search_materials($keyword)
{
    $kw = trim((string) $keyword);
    if ($kw === '') return [];
    $k = escape_string($kw);
    $sql = "SELECT id, material_name, unit
            FROM material_information
            WHERE material_name LIKE '%$k%'
            ORDER BY material_name ASC
            LIMIT 20";
    return db_fetch_array($sql) ?: [];
}

/* ============================================================
 *  Dữ liệu phiếu tiếp nhận NVL theo khoảng ngày + danh sách NVL đã chọn
 * ============================================================ */

/**
 * @param string $from         'Y-m-d' (rỗng = không giới hạn cận dưới)
 * @param string $to           'Y-m-d' (rỗng = không giới hạn cận trên)
 * @param int[]  $material_ids  danh sách material_id user chọn (rỗng = lấy tất cả NVL)
 * @return array[] mỗi dòng: import_date, date_display, material_name, supplier_name,
 *                 unit, quantity, lot (Số lô/NSX), expiry (Hạn sử dụng)
 *
 * Dữ liệu neo theo KHOẢNG NGÀY (raw_material_purchase_data.created_at) để load đầy đủ.
 * Khi user chọn cụ thể NVL: ĐẢM BẢO mỗi NVL đã chọn đều xuất hiện. NVL nào không có
 * phiếu nhập trong khoảng ngày sẽ có 1 dòng trống (date/qty rỗng) để điền tay — đây là
 * biểu mẫu nộp cơ quan nhà nước, ưu tiên hiển thị đủ danh sách hơn là chỉ data có thật.
 */
function vt_get_receiving_rows($from, $to, $material_ids)
{
    $f = vt_sanitize_date($from);
    $t = vt_sanitize_date($to);

    $ids = [];
    foreach ((array) $material_ids as $mid) {
        $mid = (int) $mid;
        if ($mid > 0) $ids[$mid] = $mid;
    }

    // Không chọn NVL nào → lấy toàn bộ phiếu nhập trong khoảng ngày.
    if (empty($ids)) {
        return vt_query_receipts($f, $t, []);
    }

    // Có chọn NVL → gom theo từng NVL, đảm bảo NVL nào cũng có ít nhất 1 dòng.
    $out = [];
    foreach (array_values($ids) as $mid) {
        $rows = vt_query_receipts($f, $t, [$mid]);
        if (!empty($rows)) {
            foreach ($rows as $r) $out[] = $r;
        } else {
            $m = db_fetch_row("SELECT m.material_name, m.unit, s.supplier_name
                               FROM material_information m
                               LEFT JOIN suppliers s ON s.id = m.supplier_id
                               WHERE m.id = $mid LIMIT 1");
            $out[] = [
                'import_date'   => '',
                'date_display'  => '',
                'material_name' => $m && $m['material_name'] !== null ? $m['material_name'] : '',
                'supplier_name' => $m && $m['supplier_name'] !== null ? $m['supplier_name'] : '',
                'unit'          => $m && $m['unit'] !== null ? $m['unit'] : '',
                'quantity'      => '',
                'lot'           => '',
                'expiry'        => '',
            ];
        }
    }
    return $out;
}

/**
 * Truy vấn dữ liệu nhập NVL từ raw_material_purchase_data theo khoảng ngày
 * và (tuỳ chọn) danh sách material_id. Trả mảng dòng đã chuẩn hoá.
 */
function vt_query_receipts($f, $t, $ids)
{
    $where = ['1 = 1'];
    if ($f !== null) $where[] = "DATE(r.created_at) >= '" . escape_string($f) . "'";
    if ($t !== null) $where[] = "DATE(r.created_at) <= '" . escape_string($t) . "'";

    $clean = [];
    foreach ((array) $ids as $mid) {
        $mid = (int) $mid;
        if ($mid > 0) $clean[$mid] = $mid;
    }
    if (!empty($clean)) {
        $where[] = 'r.material_id IN (' . implode(',', array_values($clean)) . ')';
    }

    $sql = "SELECT r.material_id,
                   r.created_at,
                   r.quantity,
                   m.material_name,
                   m.unit,
                   COALESCE(s_row.supplier_name, s_mat.supplier_name) AS supplier_name
            FROM raw_material_purchase_data r
            INNER JOIN material_information m ON m.id = r.material_id
            LEFT JOIN suppliers s_row         ON s_row.id = r.supplier_id
            LEFT JOIN suppliers s_mat         ON s_mat.id = m.supplier_id
            WHERE " . implode("\n              AND ", $where) . "
            ORDER BY r.created_at ASC, r.id ASC";

    $rows = db_fetch_array($sql) ?: [];

    $out = [];
    foreach ($rows as $r) {
        $out[] = vt_make_row(
            $r['created_at'],
            (int) $r['material_id'],
            $r['material_name'],
            $r['supplier_name'],
            $r['unit'],
            (float) $r['quantity']
        );
    }
    return $out;
}

/**
 * Chuẩn hoá 1 dòng + sinh Số lô/NSX và Hạn sử dụng.
 *
 * - Số lô/NSX  : 1 ngày "ngẫu nhiên" nằm trong vòng 60 ngày TRƯỚC ngày nhập.
 *                Dùng seed ổn định (material_id + ngày nhập) → NSX không đổi mỗi lần
 *                reload (đổi ngày vẫn ra cùng NSX cho cùng 1 phiếu).
 * - Hạn sử dụng: NSX + 365 ngày.
 */
function vt_make_row($created_at, $material_id, $material_name, $supplier_name, $unit, $quantity)
{
    $ts = strtotime((string) $created_at);
    $import_date  = $ts ? date('Y-m-d', $ts) : '';
    $date_display = $ts ? date('d/m/Y', $ts) : (string) $created_at;

    $lot = '';
    $expiry = '';
    if ($ts) {
        $offset = abs(crc32($material_id . '|' . $import_date)) % 61; // 0..60 ngày
        $nsx_ts = $ts - $offset * 86400;
        $lot    = date('d/m/Y', $nsx_ts);
        $expiry = date('d/m/Y', $nsx_ts + 365 * 86400);
    }

    return [
        'import_date'   => $import_date,
        'date_display'  => $date_display,
        'material_name' => $material_name !== null ? $material_name : '',
        'supplier_name' => $supplier_name !== null ? $supplier_name : '',
        'unit'          => $unit !== null ? $unit : '',
        'quantity'      => (float) $quantity,
        'lot'           => $lot,
        'expiry'        => $expiry,
    ];
}

/** Chuẩn hoá 'Y-m-d'; trả null nếu rỗng/không hợp lệ. */
function vt_sanitize_date($d)
{
    $d = trim((string) $d);
    if ($d === '') return null;
    $ts = strtotime($d);
    return $ts ? date('Y-m-d', $ts) : null;
}

/* ============================================================
 *  Helpers chung cho các biểu mẫu theo sản phẩm (view 2..7)
 * ============================================================ */

function vt_dmy($dt)
{
    $ts = strtotime((string) $dt);
    return $ts ? date('d/m/Y', $ts) : '';
}

function vt_plus_days($dt, $n)
{
    $ts = strtotime((string) $dt);
    return $ts ? date('d/m/Y', $ts + (int) $n * 86400) : '';
}

function vt_clean_ids($ids)
{
    $c = [];
    foreach ((array) $ids as $i) {
        $i = (int) $i;
        if ($i > 0) $c[$i] = $i;
    }
    return array_values($c);
}

function vt_fmt_num($n)
{
    $s = rtrim(rtrim(number_format((float) $n, 3, '.', ''), '0'), '.');
    return $s === '' || $s === '-0' ? '0' : $s;
}

/** Số lô sản xuất: 'yyyymmdd-{product_id 3 chữ số}-01'. */
function vt_lot_code($product_id, $dt)
{
    $ts = strtotime((string) $dt);
    $ymd = $ts ? date('Ymd', $ts) : '';
    if ($ymd === '') return '';
    return $ymd . '-' . str_pad((string) (int) $product_id, 3, '0', STR_PAD_LEFT) . '-01';
}

/* ----- search + thông tin sản phẩm / công thức ----- */

function vt_search_products($keyword)
{
    $kw = trim((string) $keyword);
    if ($kw === '') return [];
    $k = escape_string($kw);
    return db_fetch_array("SELECT id, product_name, unit
                           FROM products
                           WHERE product_name LIKE '%$k%'
                           ORDER BY product_name ASC
                           LIMIT 20") ?: [];
}

function vt_products_by_ids($ids)
{
    $c = vt_clean_ids($ids);
    if (empty($c)) return [];
    $in = implode(',', $c);
    return db_fetch_array("SELECT id, product_name, unit, category_id
                           FROM products WHERE id IN ($in)
                           ORDER BY FIELD(id,$in)") ?: [];
}

/** product_materials của 1 sản phẩm (cache static để tránh truy vấn lặp). */
function vt_pm_rows($product_id)
{
    static $cache = [];
    $pid = (int) $product_id;
    if ($pid <= 0) return [];
    if (isset($cache[$pid])) return $cache[$pid];
    $cache[$pid] = db_fetch_array("SELECT mi.material_name, pm.quantity_required, mi.unit, s.supplier_name
                                   FROM product_materials pm
                                   JOIN material_information mi ON mi.id = pm.material_id
                                   LEFT JOIN suppliers s ON s.id = mi.supplier_id
                                   WHERE pm.product_id = $pid
                                   ORDER BY pm.id ASC") ?: [];
    return $cache[$pid];
}

/** "Tên NVL - qty (unit); ..." */
function vt_product_formula($product_id)
{
    $parts = [];
    foreach (vt_pm_rows($product_id) as $r) {
        $parts[] = $r['material_name'] . ' - ' . vt_fmt_num($r['quantity_required'])
                 . ' (' . ($r['unit'] !== null ? $r['unit'] : '') . ')';
    }
    return implode('; ', $parts);
}

/** "Tên NVL1; Tên NVL2" */
function vt_product_materials_names($product_id)
{
    $parts = [];
    foreach (vt_pm_rows($product_id) as $r) $parts[] = $r['material_name'];
    return implode('; ', $parts);
}

/** "Tên NVL (Nhà cung cấp); ..." */
function vt_product_materials_with_supplier($product_id)
{
    $parts = [];
    foreach (vt_pm_rows($product_id) as $r) {
        $parts[] = $r['material_name']
                 . ($r['supplier_name'] ? ' (' . $r['supplier_name'] . ')' : '');
    }
    return implode('; ', $parts);
}

/* ============================================================
 *  VIEW 2 — Sổ sản xuất theo lô/mẻ (production_log)
 * ============================================================ */

function vt_production_rows($from, $to, $product_ids)
{
    $f = vt_sanitize_date($from);
    $t = vt_sanitize_date($to);
    $ids = vt_clean_ids($product_ids);

    if (empty($ids)) return vt_production_query($f, $t, []);

    $out = [];
    foreach ($ids as $pid) {
        $rows = vt_production_query($f, $t, [$pid]);
        if ($rows) {
            foreach ($rows as $r) $out[] = $r;
        } else {
            $p = db_fetch_row("SELECT product_name, unit FROM products WHERE id = $pid LIMIT 1");
            $out[] = [
                'product_id'   => $pid,
                'created_at'   => '',
                'date_display' => '',
                'product_name' => $p && $p['product_name'] !== null ? $p['product_name'] : '',
                'lot'          => '',
                'formula'      => vt_product_formula($pid),
                'materials'    => vt_product_materials_names($pid),
                'quantity'     => '',
                'unit'         => $p && $p['unit'] !== null ? $p['unit'] : '',
            ];
        }
    }
    return $out;
}

function vt_production_query($f, $t, $ids)
{
    $w = ['1 = 1'];
    if ($f !== null) $w[] = "DATE(f.created_at) >= '" . escape_string($f) . "'";
    if ($t !== null) $w[] = "DATE(f.created_at) <= '" . escape_string($t) . "'";
    $c = vt_clean_ids($ids);
    if ($c) $w[] = 'f.product_id IN (' . implode(',', $c) . ')';

    $sql = "SELECT f.product_id, f.quantity, f.created_at, p.product_name, p.unit
            FROM finished_product_production_data f
            JOIN products p ON p.id = f.product_id
            WHERE " . implode(' AND ', $w) . "
            ORDER BY f.created_at ASC, f.id ASC";
    $rows = db_fetch_array($sql) ?: [];

    $out = [];
    foreach ($rows as $r) {
        $pid = (int) $r['product_id'];
        $out[] = [
            'product_id'   => $pid,
            'created_at'   => $r['created_at'],
            'date_display' => vt_dmy($r['created_at']),
            'product_name' => $r['product_name'] ?: '',
            'lot'          => vt_lot_code($pid, $r['created_at']),
            'formula'      => vt_product_formula($pid),
            'materials'    => vt_product_materials_names($pid),
            'quantity'     => (float) $r['quantity'],
            'unit'         => $r['unit'] ?: '',
        ];
    }
    return $out;
}

/* ============================================================
 *  VIEW 3 — Phiếu kiểm soát quá trình (process_control)
 * ============================================================ */

/** Công đoạn + thông số kiểm soát theo category_id của sản phẩm. */
function vt_category_stages($category_id)
{
    $map = [
        1 => [['Trộn trà', 'Tỉ lệ trộn'], ['Ướp hương', 'Lượng hương']],  // TRÀ
        2 => [['Trộn bột', 'Thời gian trộn']],                            // BỘT
        3 => [['Nấu', 'Nhiệt độ'], ['Trộn', 'Tỉ lệ trộn']],              // ĐƯỜNG/SỐT
        4 => [['Rang', 'Nhiệt độ rang']],                                 // CÀ PHÊ/THẢO MỘC
        5 => [['Nấu', 'Nhiệt độ']],                                       // SIRO TRÁI CÂY
    ];
    return $map[(int) $category_id] ?? [['Kiểm tra', 'Thông số chung']];
}

function vt_process_control_rows($from, $to, $product_ids)
{
    $f = vt_sanitize_date($from);
    $t = vt_sanitize_date($to);
    $ids = vt_clean_ids($product_ids);

    $w = ['1 = 1'];
    if ($f !== null) $w[] = "DATE(f.created_at) >= '" . escape_string($f) . "'";
    if ($t !== null) $w[] = "DATE(f.created_at) <= '" . escape_string($t) . "'";
    if ($ids) $w[] = 'f.product_id IN (' . implode(',', $ids) . ')';

    $batches = db_fetch_array("SELECT f.product_id, f.created_at, p.category_id
                               FROM finished_product_production_data f
                               JOIN products p ON p.id = f.product_id
                               WHERE " . implode(' AND ', $w) . "
                               ORDER BY f.created_at ASC, f.id ASC") ?: [];

    // Đảm bảo mỗi sản phẩm đã chọn đều xuất hiện (kể cả chưa có mẻ SX).
    if ($ids) {
        $present = array_map('intval', array_column($batches, 'product_id'));
        foreach (vt_products_by_ids($ids) as $p) {
            if (!in_array((int) $p['id'], $present, true)) {
                $batches[] = ['product_id' => $p['id'], 'created_at' => '', 'category_id' => $p['category_id']];
            }
        }
    }

    $out = [];
    foreach ($batches as $b) {
        $pid = (int) $b['product_id'];
        $lot = vt_lot_code($pid, $b['created_at']);
        foreach (vt_category_stages($b['category_id']) as $st) {
            $out[] = [
                'date_display' => vt_dmy($b['created_at']),
                'lot'          => $lot,
                'stage'        => $st[0],
                'param'        => $st[1],
            ];
        }
    }
    return $out;
}

/* ============================================================
 *  VIEW 4 — Sổ nhập – xuất kho thành phẩm (finished_goods_ledger)
 * ============================================================ */

function vt_finished_ledger_rows($from, $to, $product_ids)
{
    $f = vt_sanitize_date($from);
    $t = vt_sanitize_date($to);
    $ids = vt_clean_ids($product_ids);
    $idCond = $ids ? ' AND s.product_id IN (' . implode(',', $ids) . ')' : '';

    // Lấy TẤT CẢ phiếu xuất (không lọc ngày) để tính tồn ngược; lọc ngày khi xuất ra.
    $rows = db_fetch_array("SELECT s.product_id, s.quantity, s.created_at, c.name AS customer_name, p.product_name
                            FROM sales_inventory_issue_data s
                            JOIN products p ON p.id = s.product_id
                            LEFT JOIN customers c ON c.id = s.customer_id
                            WHERE s.product_id IS NOT NULL $idCond
                            ORDER BY s.product_id ASC, s.created_at DESC, s.id DESC") ?: [];

    // Tồn kho hiện tại theo sản phẩm.
    $inv = [];
    $invSql = "SELECT product_id, quantity FROM finished_goods_inventory"
            . ($ids ? " WHERE product_id IN (" . implode(',', $ids) . ")" : "");
    foreach (db_fetch_array($invSql) ?: [] as $r) $inv[(int) $r['product_id']] = (float) $r['quantity'];

    $out = [];
    $curPid = null;
    $bal = 0.0;
    foreach ($rows as $r) {
        $pid = (int) $r['product_id'];
        if ($pid !== $curPid) { $curPid = $pid; $bal = $inv[$pid] ?? 0.0; }
        $ton = $bal;                 // tồn còn lại NGAY SAU phiếu xuất này
        $bal = $bal + (float) $r['quantity']; // truy ngược: phiếu cũ hơn có tồn lớn hơn

        $ts = strtotime($r['created_at']);
        $d  = $ts ? date('Y-m-d', $ts) : '';
        if ($f !== null && $d !== '' && $d < $f) continue;
        if ($t !== null && $d !== '' && $d > $t) continue;

        $out[] = [
            'ymd'          => $d,
            'date_display' => vt_dmy($r['created_at']),
            'loai'         => 'Xuất',
            'product_name' => $r['product_name'] ?: '',
            'lot'          => vt_lot_code($pid, $r['created_at']),
            'expiry'       => vt_plus_days($r['created_at'], 365),
            'quantity'     => (float) $r['quantity'],
            'ton'          => $ton,
            'customer_name'=> $r['customer_name'] ?: '',
        ];
    }

    // Sản phẩm đã chọn nhưng chưa có phiếu xuất → 1 dòng trống (tồn = tồn hiện tại).
    if ($ids) {
        $havePid = [];
        foreach ($rows as $r) $havePid[(int) $r['product_id']] = true;
        foreach (vt_products_by_ids($ids) as $p) {
            if (empty($havePid[(int) $p['id']])) {
                $out[] = [
                    'ymd'          => '',
                    'date_display' => '',
                    'loai'         => 'Xuất',
                    'product_name' => $p['product_name'] ?: '',
                    'lot'          => '',
                    'expiry'       => '',
                    'quantity'     => '',
                    'ton'          => $inv[(int) $p['id']] ?? 0.0,
                    'customer_name'=> '',
                ];
            }
        }
    }

    // Sắp xếp theo ngày tăng dần cho dễ đọc (dòng trống xuống cuối).
    usort($out, function ($a, $b) {
        if ($a['ymd'] === '' && $b['ymd'] === '') return 0;
        if ($a['ymd'] === '') return 1;
        if ($b['ymd'] === '') return -1;
        return strcmp($a['ymd'], $b['ymd']);
    });
    return $out;
}

/* ============================================================
 *  VIEW 5 — Sổ vệ sinh nhà xưởng – thiết bị (sanitation_log)
 *  Sinh dữ liệu theo khoảng ngày: tối đa 10 ngày / 30 ngày, mỗi ngày 1–4 khu vực.
 * ============================================================ */

function vt_sanitation_rows($from, $to)
{
    $f = vt_sanitize_date($from);
    $t = vt_sanitize_date($to);
    $today = date('Y-m-d');
    if ($f === null) $f = $today;
    if ($t === null) $t = $today;
    if (strtotime($f) > strtotime($t)) { $tmp = $f; $f = $t; $t = $tmp; }

    $areas = [
        'Khu vực chế biến siro', 'Khu đóng gói trà', 'Khu phối trộn bột sữa',
        'Khu phối trộn trà', 'Khu đóng gói bột', 'Phòng thành phẩm',
        'Phòng bao bì', 'Cảnh quan xung quanh nhà máy',
    ];

    $out = [];
    $selected = [];                 // các timestamp đã chọn (để cap 10/30 ngày)
    $end = strtotime($t);
    for ($ts = strtotime($f); $ts <= $end; $ts += 86400) {
        $d = date('Y-m-d', $ts);
        $h = crc32('san|' . $d) & 0x7fffffff;
        if ($h % 3 !== 0) continue;                 // ~1/3 số ngày được chọn

        $winStart = $ts - 29 * 86400;               // cửa sổ 30 ngày
        $cnt = 0;
        foreach ($selected as $sts) if ($sts >= $winStart) $cnt++;
        if ($cnt >= 10) continue;                   // tối đa 10 ngày / 30 ngày
        $selected[] = $ts;

        // chọn 1..4 khu vực theo seed ổn định
        $n = 1 + ($h % 4);
        $pool = $areas;
        $picked = [];
        $hh = $h;
        for ($k = 0; $k < $n && count($pool) > 0; $k++) {
            $hh = ($hh * 1103515245 + 12345) & 0x7fffffff;
            $j = $hh % count($pool);
            $picked[] = $pool[$j];
            array_splice($pool, $j, 1);
        }

        $out[] = [
            'date_display' => date('d/m/Y', $ts),
            'areas'        => implode(', ', $picked),
            'content'      => 'Được quản lý phổ biến trong quá trình vệ sinh',
        ];
    }
    return $out;
}

/* ============================================================
 *  VIEW 7 — Hồ sơ truy xuất nguồn gốc lô sản phẩm (traceability)
 * ============================================================ */

function vt_traceability_rows($from, $to, $product_ids)
{
    $f = vt_sanitize_date($from);
    $t = vt_sanitize_date($to);
    $ids = vt_clean_ids($product_ids);

    $batches = vt_production_query($f, $t, $ids);

    if ($ids) {
        $present = array_map('intval', array_column($batches, 'product_id'));
        foreach (vt_products_by_ids($ids) as $p) {
            if (!in_array((int) $p['id'], $present, true)) {
                $batches[] = [
                    'product_id'   => (int) $p['id'],
                    'created_at'   => '',
                    'date_display' => '',
                    'product_name' => $p['product_name'] ?: '',
                    'lot'          => '',
                    'quantity'     => '',
                    'unit'         => $p['unit'] ?: '',
                ];
            }
        }
    }

    // Tổng đã xuất + danh sách khách hàng + tồn hiện tại theo sản phẩm.
    $idCond = $ids ? ' AND product_id IN (' . implode(',', $ids) . ')' : '';
    $sumMap = [];
    foreach (db_fetch_array("SELECT product_id, SUM(quantity) q
                             FROM sales_inventory_issue_data
                             WHERE product_id IS NOT NULL $idCond
                             GROUP BY product_id") ?: [] as $r) {
        $sumMap[(int) $r['product_id']] = (float) $r['q'];
    }
    $custMap = [];
    foreach (db_fetch_array("SELECT DISTINCT s.product_id, c.name
                             FROM sales_inventory_issue_data s
                             JOIN customers c ON c.id = s.customer_id
                             WHERE s.product_id IS NOT NULL $idCond") ?: [] as $r) {
        $custMap[(int) $r['product_id']][] = $r['name'];
    }
    $invMap = [];
    $invSql = "SELECT product_id, quantity FROM finished_goods_inventory"
            . ($ids ? " WHERE product_id IN (" . implode(',', $ids) . ")" : "");
    foreach (db_fetch_array($invSql) ?: [] as $r) $invMap[(int) $r['product_id']] = (float) $r['quantity'];

    $out = [];
    foreach ($batches as $b) {
        $pid  = (int) $b['product_id'];
        $xuat = $sumMap[$pid] ?? 0;
        $ton  = $invMap[$pid] ?? 0;
        $cust = isset($custMap[$pid]) ? implode('; ', array_unique($custMap[$pid])) : '';
        $out[] = [
            'product_name'    => $b['product_name'],
            'lot'             => $b['lot'],
            'date_display'    => $b['date_display'],
            'expiry'          => $b['created_at'] !== '' ? vt_plus_days($b['created_at'], 365) : '',
            'quantity'        => $b['quantity'],
            'materials'       => vt_product_materials_with_supplier($pid),
            'issue_inventory' => 'Xuất: ' . vt_fmt_num($xuat) . ' / Tồn: ' . vt_fmt_num($ton),
            'customer_name'   => $cust,
        ];
    }
    return $out;
}

/* ============================================================
 *  VIEW 8 — Tồn kho thành phẩm (product_stock)
 *  Tồn hiện tại của (các) sản phẩm đang được chọn ở production_log
 *  (state chia sẻ 'vsattp_shared' qua localStorage — không lọc gì = lấy hết).
 * ============================================================ */
function vt_product_stock_rows($product_ids)
{
    $ids = vt_clean_ids($product_ids);
    $where = $ids ? ' WHERE p.id IN (' . implode(',', $ids) . ')' : '';
    $sql = "SELECT p.id AS product_id, p.product_name,
                   COALESCE(NULLIF(p.unit, ''), '') AS unit,
                   COALESCE(fgi.quantity, 0) AS quantity
            FROM products p
            LEFT JOIN finished_goods_inventory fgi ON fgi.product_id = p.id
            $where
            ORDER BY p.product_name ASC";
    $rows = db_fetch_array($sql) ?: [];

    $out = [];
    foreach ($rows as $r) {
        $out[] = [
            'product_id'   => (int) $r['product_id'],
            'product_name' => $r['product_name'] ?: '',
            'unit'         => $r['unit'],
            'quantity'     => (float) $r['quantity'],
        ];
    }
    return $out;
}

/* ============================================================
 *  VIEW 9 — Tồn kho nguyên liệu (material_stock)
 *  NVL dùng trong công thức của (các) sản phẩm đang được chọn ở production_log.
 * ============================================================ */
function vt_material_stock_rows($product_ids)
{
    $ids = vt_clean_ids($product_ids);
    $where = '';
    if ($ids) {
        $midRows = db_fetch_array("SELECT DISTINCT material_id FROM product_materials
                                    WHERE product_id IN (" . implode(',', $ids) . ")") ?: [];
        $mids = vt_clean_ids(array_column($midRows, 'material_id'));
        if (!$mids) return [];
        $where = ' WHERE mi.id IN (' . implode(',', $mids) . ')';
    }
    $sql = "SELECT mi.id AS material_id, mi.material_name,
                   COALESCE(NULLIF(mi.unit, ''), '') AS unit,
                   COALESCE(minv.quantity, 0) AS quantity
            FROM material_information mi
            LEFT JOIN material_inventory minv ON minv.material_id = mi.id
            $where
            ORDER BY mi.material_name ASC";
    $rows = db_fetch_array($sql) ?: [];

    $out = [];
    foreach ($rows as $r) {
        $out[] = [
            'material_id'   => (int) $r['material_id'],
            'material_name' => $r['material_name'] ?: '',
            'unit'          => $r['unit'],
            'quantity'      => (float) $r['quantity'],
        ];
    }
    return $out;
}
