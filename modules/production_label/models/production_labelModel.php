<?php

/**
 * production_label — Tạo tem bao bì ngoài
 *
 * Nguồn dữ liệu (KHÔNG có bảng riêng — tem tạo ra để in rồi thôi, không lưu trữ):
 *   - products / product_categories   : tên SP, nhóm SP (màu viền tem lưu mẫu).
 *   - product_materials + material_information (classification = 'Nguyên liệu')
 *                                      : danh sách nguyên liệu dùng cho tem lưu mẫu.
 *   - material_inventory              : tồn hiện tại của từng NVL.
 *   - raw_material_purchase_data      : lần nhập kho gần đây nhất (số lượng + ngày).
 *   - product_info_basic              : quy cách bao bì trong/ngoài cho tem bao bì ngoài.
 *
 * Prefix hàm: pl_*.
 */

/* ============================================================
 *  Đăng ký view (tự chạy, không cần migration tay)
 * ============================================================ */

function pl_ensure_view_registered()
{
    if (db_num_rows("SHOW TABLES LIKE 'tbl_views'") <= 0) return;
    db_query("INSERT IGNORE INTO tbl_views (module, controller, action, label, group_label, sort)
              VALUES ('production_label','production_label','production_label',
                      'Tạo tem bao bì ngoài','SẢN XUẤT', 47)");
}

/* ============================================================
 *  Hotline trên tem bao bì ngoài — "sửa 1 lần dùng nhiều lần" (app_settings),
 *  giống pf_get_share_settings()/pf_save_share_setting() ở production_formula.
 * ============================================================ */

const PL_HOTLINE_DEFAULT = '0777 044 777';
const PL_HOTLINE_KEY     = 'production_label.hotline';

function pl_app_settings_ensure()
{
    static $done = false;
    if ($done) return;
    $done = true;
    db_query("CREATE TABLE IF NOT EXISTS app_settings (
        setting_key   VARCHAR(100) NOT NULL,
        setting_value TEXT DEFAULT NULL,
        PRIMARY KEY (setting_key)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci");
}

function pl_get_hotline()
{
    pl_app_settings_ensure();
    $k   = escape_string(PL_HOTLINE_KEY);
    $row = db_fetch_row("SELECT setting_value FROM app_settings WHERE setting_key = '$k' LIMIT 1");
    $v   = $row ? trim((string) $row['setting_value']) : '';
    return $v !== '' ? $v : PL_HOTLINE_DEFAULT;
}

function pl_save_hotline($value)
{
    pl_app_settings_ensure();
    $v  = trim((string) $value);
    $k  = escape_string(PL_HOTLINE_KEY);
    $exists = db_num_rows("SELECT 1 FROM app_settings WHERE setting_key = '$k'") > 0;
    if ($exists) {
        db_update('app_settings', ['setting_value' => $v], "setting_key = '$k'");
    } else {
        db_insert('app_settings', ['setting_key' => PL_HOTLINE_KEY, 'setting_value' => $v]);
    }
    return true;
}

/* ============================================================
 *  Helpers dùng chung
 * ============================================================ */

/** Tên hiển thị NVL: ưu tiên common_material_name (tên thường gọi), fallback material_name. */
function pl_material_display_name($row)
{
    $c = trim((string) ($row['common_material_name'] ?? ''));
    return $c !== '' ? $c : (string) ($row['material_name'] ?? '');
}

/** Tên hiển thị SP: ưu tiên common_product_name (tên thường gọi), fallback product_name. */
function pl_product_display_name($row)
{
    $c = trim((string) ($row['common_product_name'] ?? ''));
    return $c !== '' ? $c : (string) ($row['product_name'] ?? '');
}

/** Thêm cột products.common_product_name ("Tên thường gọi") nếu chưa có. */
function pl_ensure_product_common_name_column()
{
    $col = db_fetch_row("SELECT COLUMN_NAME FROM information_schema.COLUMNS
                         WHERE TABLE_SCHEMA = DATABASE()
                           AND TABLE_NAME = 'products'
                           AND COLUMN_NAME = 'common_product_name' LIMIT 1");
    if (!$col) {
        db_query("ALTER TABLE products ADD COLUMN common_product_name VARCHAR(255) DEFAULT NULL");
    }
}

/** Lưu tên thường gọi cho 1 sản phẩm (đổi tại card tem -> đồng bộ mọi nơi hiển thị). */
function pl_save_product_common_name($product_id, $value)
{
    pl_ensure_product_common_name_column();
    $pid = (int) $product_id;
    if ($pid <= 0) return false;
    $v = trim((string) $value);
    return db_update('products', ['common_product_name' => ($v !== '' ? $v : null)], "id = {$pid}");
}

/** Định dạng ngày kiểu "03 Tháng Bảy 2026", không phụ thuộc locale server. */
function pl_vn_date($date)
{
    $ts = is_string($date) ? strtotime($date) : (int) $date;
    if (!$ts) return '';
    $months = [
        1 => 'Một', 2 => 'Hai', 3 => 'Ba', 4 => 'Tư', 5 => 'Năm', 6 => 'Sáu',
        7 => 'Bảy', 8 => 'Tám', 9 => 'Chín', 10 => 'Mười', 11 => 'Mười Một', 12 => 'Mười Hai',
    ];
    return date('d', $ts) . ' Tháng ' . $months[(int) date('n', $ts)] . ' ' . date('Y', $ts);
}

/** Định dạng ngày ngắn dd/mm/yyyy cho các dòng nhập kho gần đây. */
function pl_short_date($date)
{
    $ts = is_string($date) ? strtotime($date) : (int) $date;
    return $ts ? date('d/m/Y', $ts) : '';
}

/** Nhóm SP -> mã màu viền tem lưu mẫu (đã đối chiếu tên nhóm thật trong product_categories). */
function pl_category_color_key($category_name)
{
    $c = mb_strtoupper(trim((string) $category_name), 'UTF-8');
    switch ($c) {
        case 'TRÀ':
        case 'ĐƯỜNG/SỐT':
            return 'black';
        case 'CÀ PHÊ/THẢO MỘC':
            return 'brown';
        case 'SIRO TRÁI CÂY':
            return 'gradient';
        case 'BỘT':
            return 'pale-yellow';
        default:
            return 'gray';
    }
}

/* ============================================================
 *  Danh sách sản xuất mặc định — mượn KHSX (production_plans) từ production_staff,
 *  giống nguồn dữ liệu plan_for_staff/production_plan, không cần require model đó
 *  (chỉ cần plan_id/product_id/product_name/quantity, không cần bao bì/QC).
 * ============================================================ */

function pl_get_khsx_products()
{
    pl_ensure_product_common_name_column();
    $rows = db_fetch_array(
        "SELECT pp.id AS plan_id, pp.product_id, pp.quantity,
                COALESCE(NULLIF(p.common_product_name, ''), p.product_name) AS product_name
         FROM production_plans pp
         LEFT JOIN products p ON p.id = pp.product_id
         ORDER BY pp.id ASC"
    ) ?: [];
    foreach ($rows as &$r) {
        $r['plan_id']    = (int) $r['plan_id'];
        $r['product_id'] = (int) $r['product_id'];
        $r['quantity']   = (int) $r['quantity'];
    }
    unset($r);
    return $rows;
}

/* ============================================================
 *  Search sản phẩm (dropdown keyword)
 * ============================================================ */

function pl_search_products($keyword)
{
    $kw = trim((string) $keyword);
    if ($kw === '') return [];
    $k = escape_string($kw);
    $sql = "SELECT p.id, p.product_name, p.category_id, pc.category_name
            FROM products p
            LEFT JOIN product_categories pc ON pc.id = p.category_id
            WHERE p.product_name LIKE '%$k%'
            ORDER BY p.product_name ASC
            LIMIT 15";
    $rows = db_fetch_array($sql) ?: [];
    foreach ($rows as &$r) {
        $r['id']        = (int) $r['id'];
        $r['color_key'] = pl_category_color_key($r['category_name']);
    }
    unset($r);
    return $rows;
}

/* ============================================================
 *  Tem lưu mẫu
 * ============================================================ */

function pl_get_sample_label_data($product_id, $date)
{
    $pid = (int) $product_id;
    $out = [
        'product_id'             => $pid,
        'product_name'           => '',
        'category_name'          => '',
        'color_key'              => 'gray',
        'production_date_display' => pl_vn_date($date !== '' ? $date : date('Y-m-d')),
        'ingredients'            => [],
    ];
    if ($pid <= 0) return $out;

    pl_ensure_product_common_name_column();
    $product = db_fetch_row(
        "SELECT p.product_name, p.common_product_name, pc.category_name
         FROM products p
         LEFT JOIN product_categories pc ON pc.id = p.category_id
         WHERE p.id = $pid LIMIT 1"
    );
    if (!$product) return $out;
    $out['product_name']  = pl_product_display_name($product);
    $out['category_name'] = (string) $product['category_name'];
    $out['color_key']     = pl_category_color_key($product['category_name']);

    $rows = db_fetch_array(
        "SELECT mi.id AS material_id, mi.material_name, mi.common_material_name,
                COALESCE(inv.quantity, 0) AS stock
         FROM product_materials pm
         JOIN material_information mi ON mi.id = pm.material_id
         LEFT JOIN material_inventory inv ON inv.material_id = mi.id
         WHERE pm.product_id = $pid AND mi.classification = 'Nguyên liệu'
         ORDER BY pm.sort_order ASC, pm.id ASC
         LIMIT 2"
    ) ?: [];

    $ingredients = [];
    foreach ($rows as $r) {
        $mid = (int) $r['material_id'];
        $last = db_fetch_row(
            "SELECT quantity, created_at FROM raw_material_purchase_data
             WHERE material_id = $mid ORDER BY created_at DESC LIMIT 1"
        );
        $ingredients[] = [
            'name'             => pl_material_display_name($r),
            'stock'            => (float) $r['stock'],
            'last_qty'         => $last ? (float) $last['quantity'] : null,
            'last_date_display' => $last ? pl_short_date($last['created_at']) : '',
        ];
    }
    $out['ingredients'] = $ingredients;
    return $out;
}

/* ============================================================
 *  Tem bao bì ngoài
 * ============================================================ */

/**
 * Tách chuỗi quy cách bao bì ngoài, vd "20 gói/thùng":
 * - pack_per_outer = số lượng gói/đơn vị trong 1 thùng|bao (lấy số đầu tiên vế trái).
 * - is_carton      = true nếu vế phải chứa "thùng" (cần in 2 mặt -> nhân đôi số tem).
 */
function pl_parse_outer_spec($spec)
{
    $spec = trim((string) $spec);
    if ($spec === '' || strpos($spec, '/') === false) {
        return ['pack_per_outer' => 0.0, 'is_carton' => false];
    }
    $parts = explode('/', $spec, 2);
    $left  = trim($parts[0]);
    $right = trim($parts[1] ?? '');
    $pack  = 0.0;
    if (preg_match('/(\d+(?:[.,]\d+)?)/', $left, $m)) {
        $pack = (float) str_replace(',', '.', $m[1]);
    }
    $is_carton = mb_stripos($right, 'thùng', 0, 'UTF-8') !== false;
    return ['pack_per_outer' => $pack, 'is_carton' => $is_carton];
}

/** LOT = yymmdd-{product_id 3 số}-{số mẻ 2 số}, vd 2026-07-03 / id 5 / mẻ 1 -> "260703-005-01". */
function pl_lot_code($product_id, $date, $batch)
{
    $ts = strtotime((string) $date);
    if (!$ts) return '';
    $ymd = date('ymd', $ts);
    $pid = str_pad((string) (int) $product_id, 3, '0', STR_PAD_LEFT);
    $b   = (int) preg_replace('/\D/', '', (string) $batch);
    if ($b <= 0) $b = 1;
    $bb  = str_pad((string) $b, 2, '0', STR_PAD_LEFT);
    return $ymd . '-' . $pid . '-' . $bb;
}

/**
 * Chia số lượng cần sản xuất đều cho $batch_count mẻ (chênh lệch tối đa 1 đơn vị,
 * phần dư ưu tiên dồn vào các mẻ đầu). Trả mảng số nguyên, index 0 = mẻ 1.
 */
function pl_split_qty_by_batches($qty_needed, $batch_count)
{
    $n = max(1, (int) $batch_count);
    $total = (int) round((float) $qty_needed);
    $base  = intdiv($total, $n);
    $rem   = $total - $base * $n;
    $out   = [];
    for ($i = 1; $i <= $n; $i++) {
        $out[] = $base + ($i <= $rem ? 1 : 0);
    }
    return $out;
}

/**
 * $batch_count = số mẻ sản xuất trong đợt này. Số lượng cần sản xuất được chia đều
 * cho từng mẻ, mỗi mẻ ra 1 mã LOT riêng (số mẻ trong LOT = thứ tự 1..N) kèm số tem
 * riêng của mẻ đó — KHÔNG còn 1 lot dùng chung cho cả đợt.
 */
function pl_get_outer_label_data($product_id, $date, $batch_count, $qty_needed)
{
    $pid  = (int) $product_id;
    $date = $date !== '' ? $date : date('Y-m-d');
    $qty  = (float) $qty_needed;
    $nb   = max(1, (int) $batch_count);
    $out  = [
        'product_id'              => $pid,
        'product_name'            => '',
        'spec_display'            => '',
        'production_date_display' => pl_vn_date($date),
        'batch_count'             => $nb,
        'total_label_count'       => 0,
        'groups'                  => [],
    ];
    if ($pid <= 0) return $out;

    pl_ensure_product_common_name_column();
    $product = db_fetch_row("SELECT product_name, common_product_name FROM products WHERE id = $pid LIMIT 1");
    if (!$product) return $out;
    $out['product_name'] = pl_product_display_name($product);

    $basic = db_fetch_row(
        "SELECT inner_packaging_spec, outer_packaging_spec
         FROM product_info_basic WHERE product_id = $pid LIMIT 1"
    );
    $inner_spec = $basic ? trim((string) $basic['inner_packaging_spec']) : '';
    $outer_spec = $basic ? trim((string) $basic['outer_packaging_spec']) : '';
    if ($inner_spec === '' && $outer_spec === '') return $out;

    $out['spec_display'] = trim($inner_spec . ($inner_spec !== '' && $outer_spec !== '' ? ' | ' : '') . $outer_spec);

    $parsed = pl_parse_outer_spec($outer_spec);
    if ($parsed['pack_per_outer'] <= 0 || $qty <= 0) return $out;

    $groups = [];
    $total  = 0;
    foreach (pl_split_qty_by_batches($qty, $nb) as $i => $bqty) {
        if ($bqty <= 0) continue;
        $bnum  = $i + 1;
        $count = (int) ceil($bqty / $parsed['pack_per_outer']);
        if ($parsed['is_carton']) $count *= 2;
        if ($count <= 0) continue;
        $groups[] = [
            'batch'       => $bnum,
            'lot'         => pl_lot_code($pid, $date, $bnum),
            'qty'         => $bqty,
            'label_count' => $count,
        ];
        $total += $count;
    }
    $out['groups']            = $groups;
    $out['total_label_count'] = $total;
    return $out;
}
