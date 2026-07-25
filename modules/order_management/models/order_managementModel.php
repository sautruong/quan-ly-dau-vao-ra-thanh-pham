<?php
/**
 * order_management model — phía NHÀ MÁY nhận đơn từ chi nhánh.
 * Tái dùng bảng factory_order_sales_history (do sell_factory "Gửi nhà máy" đẩy vào).
 *
 * Quy ước ký hiệu kiện (bao bì ngoài): Bao -> B, Thùng -> T, còn lại -> K.
 */

/** Nhắc "trước bốc hàng" theo chi nhánh (Điểm nhắc) — mảng note áp dụng cho $customer_id. */
function om_get_branch_pickup_reminders($customer_id)
{
    require_once __DIR__ . '/../../../libraries/reminder_points.php';
    rp_ensure_tables();
    return rp_get_branch_reminders_for_customer($customer_id);
}

/** Nhắc "trước bốc hàng" theo sản phẩm (Điểm nhắc) — 1 note áp dụng cho $product_id. */
function om_get_product_pickup_note($product_id)
{
    require_once __DIR__ . '/../../../libraries/reminder_points.php';
    rp_ensure_tables();
    return rp_get_product_pickup_note($product_id);
}

/* =====================================================================
 *  "XEM HÀNG THIẾU" (modal Xem đơn hàng) — đối chiếu công thức (product_materials)
 *  với tồn NVL để quyết định có sản xuất bù đủ số thiếu hay không.
 * ===================================================================== */

/**
 * Nhân định mức từng thành phần công thức (product_materials) của 1 SP với
 * số lượng đang thiếu ($shortage_qty) rồi đối chiếu tồn NVL hiện có.
 * Trả về danh sách thành phần + quyết định tổng ('enough' true/false) + lý do.
 */
function om_check_shortage_recipe($product_id, $shortage_qty)
{
    $pid  = (int) $product_id;
    $need = (float) $shortage_qty;
    if ($pid <= 0 || $need <= 0) return ['ok' => false, 'msg' => 'Thiếu dữ liệu'];

    $rows = db_fetch_array(
        "SELECT pm.material_id, pm.quantity_required AS quantity,
                COALESCE(NULLIF(mi.common_material_name, ''), mi.material_name) AS material_name,
                mi.unit, COALESCE(inv.quantity, 0) AS stock
         FROM product_materials pm
         JOIN material_information mi ON mi.id = pm.material_id
         LEFT JOIN material_inventory inv ON inv.material_id = pm.material_id
         WHERE pm.product_id = $pid
         ORDER BY pm.sort_order ASC, pm.id ASC"
    ) ?: [];

    if (!$rows) return ['ok' => true, 'has_recipe' => false, 'components' => [], 'enough' => null, 'reason' => ''];

    $components  = [];
    $short_lines = [];
    $idx = 0;
    foreach ($rows as $r) {
        $idx++;
        $required = (float) $r['quantity'] * $need;
        $stock    = (float) $r['stock'];
        $short    = round($required - $stock, 3);
        if ($short < 0) $short = 0;
        $name = (string) $r['material_name'];
        $unit = (string) $r['unit'];
        $components[] = [
            'idx'      => $idx,
            'name'     => $name,
            'unit'     => $unit,
            'required' => $required,
            'stock'    => $stock,
            'short'    => $short,
            'enough'   => $short <= 0,
        ];
        if ($short > 0) {
            $short_lines[] = 'Thành phần số ' . $idx . ' (' . $name . ') bị thiếu tồn '
                . rtrim(rtrim(number_format($short, 2, '.', ''), '0'), '.') . ($unit !== '' ? ' ' . $unit : '');
        }
    }
    $enough = empty($short_lines);
    return [
        'ok'         => true,
        'has_recipe' => true,
        'components' => $components,
        'enough'     => $enough,
        'reason'     => $enough ? '' : ('Không sản xuất đủ do ' . implode('; ', $short_lines) . '.'),
    ];
}

/* =====================================================================
 *  QUY ĐỔI KIỆN
 * ===================================================================== */

/** Ký hiệu kiện từ tên bao bì ngoài. */
function om_kien_letter($short_name)
{
    $s = mb_strtolower(trim((string) $short_name), 'UTF-8');
    if ($s === '') return '';
    if (mb_strpos($s, 'bao') !== false) return 'B';
    if (mb_strpos($s, 'thùng') !== false || mb_strpos($s, 'thung') !== false) return 'T';
    return 'K';
}

/**
 * Quy đổi 1 số lượng (đơn vị nhỏ) sang ký hiệu kiện.
 *  vd 90 gói, 30 gói/bao -> "3B"; 65 chai, 12 chai/thùng -> "5T 5".
 * Trả ['text','whole','letter','rem'].
 */
function om_qty_to_kien($qty, $ops_qty, $short_name, $unit = '')
{
    $qty     = (float) $qty;
    $ops_qty = (float) $ops_qty;
    $unit    = trim((string) $unit);

    if ($ops_qty <= 0) {
        // Không có quy cách ngoài -> giữ nguyên đơn vị nhỏ.
        $n = rtrim(rtrim(number_format($qty, 2, '.', ''), '0'), '.');
        return ['text' => $n . ($unit !== '' ? ' ' . $unit : ''), 'whole' => 0, 'letter' => '', 'rem' => $qty];
    }
    $letter = om_kien_letter($short_name);
    $whole  = (int) floor($qty / $ops_qty);
    $rem    = $qty - $whole * $ops_qty;
    $rem_n  = rtrim(rtrim(number_format($rem, 2, '.', ''), '0'), '.');

    if ($whole === 0) {
        // Chưa đủ 1 kiện -> hiển thị số lẻ theo đơn vị nhỏ (vd "7 gói").
        $text = $rem_n . ($unit !== '' ? ' ' . $unit : '');
    } else {
        $text = $whole . $letter;
        if ($rem > 0) $text .= ' ' . $rem_n;
    }
    return ['text' => $text, 'whole' => $whole, 'letter' => $letter, 'rem' => $rem];
}

/** Tồn kho quy đổi sang kiện, dạng "23B 11". */
function om_inventory_kien($stock, $ops_qty, $short_name, $unit = '')
{
    return om_qty_to_kien($stock, $ops_qty, $short_name, $unit)['text'];
}

/* =====================================================================
 *  THÔNG TIN SẢN PHẨM (cho làm giàu đơn + nhập tay phiếu soạn)
 * ===================================================================== */

/** Bao bì ngoài + tồn hiện tại + đơn vị của 1 sản phẩm. */
function om_get_product_packaging($product_id)
{
    $product_id = (int) $product_id;
    $sql = "SELECT
                p.id AS product_id,
                COALESCE(NULLIF(p.common_product_name, ''), p.product_name) AS product_name,
                COALESCE(NULLIF(p.unit, ''), pib.unit) AS unit,
                COALESCE(ops.quantity, 0)   AS ops_qty,
                ops.outer_packaging_short_name AS short_name,
                COALESCE(fgi.quantity, 0)   AS stock,
                COALESCE(pw.weight_kg, 0)   AS weight_kg,
                COALESCE(pp.system_price,0) AS system_price
            FROM products p
            LEFT JOIN product_info_basic pib ON pib.product_id = p.id
            LEFT JOIN outer_packaging_specifications ops ON ops.product_id = p.id
            LEFT JOIN finished_goods_inventory fgi ON fgi.product_id = p.id
            LEFT JOIN product_weights pw ON pw.product_id = p.id
            LEFT JOIN product_prices  pp ON pp.product_id = p.id
            WHERE p.id = $product_id
            LIMIT 1";
    return db_fetch_row($sql);
}

/** Thông tin SP cho 1 dòng phiếu soạn nhập tay (gồm quy đổi kiện). */
function om_get_product_for_slip($product_id)
{
    $row = om_get_product_packaging($product_id);
    if (!$row) return null;
    $row['item_type'] = 'product';
    $row['ops_qty']    = (int) $row['ops_qty'];
    $row['stock']      = (float) $row['stock'];
    $row['weight_kg']  = (float) ($row['weight_kg'] ?? 0);
    $row['system_price'] = (float) ($row['system_price'] ?? 0);
    $row['letter']     = om_kien_letter($row['short_name']);
    $row['inv_kien']   = om_inventory_kien($row['stock'], $row['ops_qty'], $row['short_name'], $row['unit']);
    $row['pack_label'] = ($row['ops_qty'] > 0 && $row['short_name'])
        ? ($row['ops_qty'] . ' ' . $row['unit'] . '/' . mb_strtolower($row['short_name'], 'UTF-8'))
        : '';
    $smap = om_slip_order_map([(int) $row['product_id']]);
    $row['sort_order'] = $smap[(int) $row['product_id']] ?? null;
    $row['reminder_note'] = om_get_product_pickup_note((int) $row['product_id']);
    return $row;
}

/** Thông tin 1 NVL bao bì cho 1 dòng phiếu soạn nhập tay (không có quy cách ngoài). */
function om_get_material_for_slip($material_id)
{
    $mid = (int) $material_id;
    // Giá bán NVL cho chi nhánh = selling_price mới nhất trong branch_material_selling_prices —
    // CÙNG nguồn với sf_get_material_order_info() (sell_factory). Trước đây hardcode 0 làm mọi
    // dòng NVL thêm qua order_management (Xem đơn/đơn thủ công/phiếu soạn) mất giá trị.
    $row = db_fetch_row(
        "SELECT m.id AS product_id, m.material_name AS product_name, m.unit,
                COALESCE(minv.quantity, 0) AS stock,
                COALESCE((
                    SELECT b.selling_price
                    FROM branch_material_selling_prices b
                    WHERE b.material_id = m.id
                    ORDER BY b.updated_at DESC, b.id DESC
                    LIMIT 1
                ), 0) AS system_price
         FROM material_information m
         LEFT JOIN material_inventory minv ON minv.material_id = m.id
         WHERE m.id = $mid LIMIT 1"
    );
    if (!$row) return null;
    $row['item_type']    = 'material';
    $row['ops_qty']      = 0;
    $row['short_name']   = '';
    $row['letter']       = '';
    $row['stock']        = (float) $row['stock'];
    $row['weight_kg']    = 0.0;
    $row['system_price'] = (float) $row['system_price'];
    $row['inv_kien']     = om_inventory_kien($row['stock'], 0, '', $row['unit']);
    $row['pack_label']   = '';
    $row['sort_order']   = null; // NVL luôn ở nhóm cuối, không gắn thứ tự
    return $row;
}

/**
 * Auto-complete cho nhập tay phiếu soạn: products + NVL bao bì (trong/ngoài).
 * Trả {type:'product'|'material', product_id, name, unit}.
 */
function om_search_products($keyword)
{
    $keyword = escape_string($keyword);
    $sql = "SELECT 'product' AS type, p.id AS product_id,
                   COALESCE(NULLIF(p.common_product_name, ''), p.product_name) COLLATE utf8mb4_general_ci AS name,
                   COALESCE(NULLIF(p.unit, ''), pib.unit) COLLATE utf8mb4_general_ci AS unit
            FROM products p
            LEFT JOIN product_info_basic pib ON pib.product_id = p.id
            WHERE p.product_name LIKE '%$keyword%' OR p.product_code LIKE '%$keyword%'
               OR p.common_product_name LIKE '%$keyword%'
            UNION ALL
            SELECT 'material' AS type, m.id AS product_id,
                   m.material_name COLLATE utf8mb4_general_ci AS name,
                   m.unit COLLATE utf8mb4_general_ci AS unit
            FROM material_information m
            WHERE m.classification IN ('Bao bì trong', 'Bao bì ngoài')
              AND (m.material_name LIKE '%$keyword%' OR m.material_code LIKE '%$keyword%')
            ORDER BY name ASC
            LIMIT 20";
    return db_fetch_array($sql) ?: [];
}

/**
 * Dữ liệu prefill cho phiếu xuất kho bán hàng (sales_delivery_note) từ 1 đơn.
 * Trả {customer_id, customer_name, items:[{product_id,item_type,product_name,quantity,unit,weight_kg,unit_price}]}.
 */
function om_get_order_prefill($id)
{
    $o = om_get_order($id);
    if (!$o) return null;
    $items = [];
    foreach ($o['items'] as $it) {
        $isMat = !empty($it['material_id']);
        $name  = (string) $it['product_name'];
        // Chứng từ xuất kho phải ghi TÊN PHỔ THÔNG (products.product_name chính thức) —
        // khác với product_name ở trên vốn ưu tiên "tên thường gọi" (common_product_name)
        // chỉ để tiện nhận diện trên phiếu soạn nội bộ.
        if (!$isMat && (int) $it['product_id'] > 0) {
            $official = om_get_official_product_name((int) $it['product_id']);
            if ($official !== '') $name = $official;
        }
        $items[] = [
            'product_id'   => $isMat ? (int) $it['material_id'] : (int) $it['product_id'],
            'item_type'    => $isMat ? 'material' : 'product',
            'product_name' => $name,
            'quantity'     => (float) $it['qt_order'],
            'unit'         => (string) $it['unit'],
            'weight_kg'    => (float) $it['weight_kg'],
            'unit_price'   => (float) $it['system_price'],
        ];
    }
    return [
        'customer_id'   => (int) ($o['customer_id'] ?? 0),
        'customer_name' => (string) ($o['customer_name'] ?? ''),
        'items'         => $items,
    ];
}

/** Tên phổ thông chính thức (products.product_name) — dùng cho chứng từ xuất kho. */
function om_get_official_product_name($product_id)
{
    $row = db_fetch_row('SELECT product_name FROM products WHERE id = ' . (int) $product_id . ' LIMIT 1');
    return $row ? (string) $row['product_name'] : '';
}

/* =====================================================================
 *  THỨ TỰ HIỂN THỊ SẢN PHẨM TRÊN PHIẾU SOẠN (nhà máy tự cấu hình)
 *  Giống cơ chế sf_product_display_order của order_factory nhưng bảng riêng.
 * ===================================================================== */

function om_ensure_slip_order_table()
{
    static $done = false;
    if ($done) return;
    $done = true;
    db_query("CREATE TABLE IF NOT EXISTS om_slip_display_order (
        product_id INT NOT NULL PRIMARY KEY,
        sort_order INT NULL
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci");
}

/** Toàn bộ SP + thứ tự hiển thị hiện tại (cho modal cài đặt phiếu soạn). */
function om_get_products_with_slip_order()
{
    om_ensure_slip_order_table();
    return db_fetch_array(
        "SELECT p.id AS product_id, p.product_name, pc.category_name, pdo.sort_order
         FROM products p
         LEFT JOIN product_categories pc ON pc.id = p.category_id
         LEFT JOIN om_slip_display_order pdo ON pdo.product_id = p.id
         ORDER BY pc.id ASC, (pdo.sort_order IS NULL) ASC, pdo.sort_order ASC, p.product_name ASC"
    ) ?: [];
}

/** Ghi đè thứ tự hiển thị phiếu soạn. $map = [product_id => sort|''|null]. */
function om_save_slip_order($map)
{
    om_ensure_slip_order_table();
    if (!is_array($map)) return false;
    foreach ($map as $pid => $order) {
        $pid = (int) $pid;
        if ($pid <= 0) continue;
        $has = ($order !== '' && $order !== null && is_numeric($order));
        if ($has) {
            $ord = (int) $order;
            $exists = db_num_rows("SELECT 1 FROM om_slip_display_order WHERE product_id = $pid") > 0;
            if ($exists) db_update('om_slip_display_order', ['sort_order' => $ord], "product_id = $pid");
            else db_insert('om_slip_display_order', ['product_id' => $pid, 'sort_order' => $ord]);
        } else {
            db_delete('om_slip_display_order', "product_id = $pid");
        }
    }
    return true;
}

/** Map [product_id => sort_order] cho danh sách product id. */
function om_slip_order_map($pids)
{
    om_ensure_slip_order_table();
    $ids = array_values(array_unique(array_filter(array_map('intval', (array) $pids), static fn($v) => $v > 0)));
    if (!$ids) return [];
    $in   = implode(',', $ids);
    $rows = db_fetch_array("SELECT product_id, sort_order FROM om_slip_display_order WHERE product_id IN ($in)") ?: [];
    $map  = [];
    foreach ($rows as $r) $map[(int) $r['product_id']] = $r['sort_order'];
    return $map;
}

/* =====================================================================
 *  CÀI ĐẶT: tự xóa (ẩn) đơn đã bốc sau X ngày — dùng chung bảng key-value
 *  app_settings (giống print_settings.php / system_settings.php), prefix 'om.'.
 * ===================================================================== */

function om_settings_ensure_table()
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

// key app_settings: 'om.auto_delete_picked_days'; giá trị hợp lệ: 0 (tắt), 1, 3, 7, 30 ngày.

/** Số ngày sau khi "đã bốc" thì tự xóa (ẩn) đơn; 0 = tắt (mặc định). */
function om_get_auto_delete_days()
{
    om_settings_ensure_table();
    $row = db_fetch_row("SELECT setting_value FROM app_settings WHERE setting_key = 'om.auto_delete_picked_days' LIMIT 1");
    $v = $row ? (int) $row['setting_value'] : 0;
    return in_array($v, [0, 1, 3, 7, 30], true) ? $v : 0;
}

function om_set_auto_delete_days($days)
{
    om_settings_ensure_table();
    $days = (int) $days;
    if (!in_array($days, [0, 1, 3, 7, 30], true)) return false;
    $exists = db_num_rows("SELECT 1 FROM app_settings WHERE setting_key = 'om.auto_delete_picked_days'") > 0;
    if ($exists) db_update('app_settings', ['setting_value' => (string) $days], "setting_key = 'om.auto_delete_picked_days'");
    else db_insert('app_settings', ['setting_key' => 'om.auto_delete_picked_days', 'setting_value' => (string) $days]);
    return true;
}

/**
 * Tự xóa (ẩn, không xóa cứng — giống om_delete_order) các đơn đã đánh dấu
 * "Đã bốc" quá X ngày theo cài đặt. Date-driven: gọi mỗi lần tải trang
 * branch_orders, không cần cron.
 */
function om_apply_auto_delete_picked()
{
    $days = om_get_auto_delete_days();
    if ($days <= 0) return;
    om_ensure_order_flags();
    $rows = db_fetch_array(
        "SELECT id FROM factory_order_sales_history
         WHERE factory_hidden = 0 AND picked = 1 AND picked_at IS NOT NULL
           AND picked_at <= DATE_SUB(NOW(), INTERVAL $days DAY)"
    ) ?: [];
    foreach ($rows as $r) om_delete_order((int) $r['id']);
}

/** Danh sách chi nhánh (customers) cho select khi in phiếu nhập tay. */
function om_get_customers()
{
    return db_fetch_array(
        "SELECT id, name, short_name, receiver, secondary_color FROM customers ORDER BY name ASC"
    ) ?: [];
}

/* =====================================================================
 *  ĐƠN HÀNG TỪ CHI NHÁNH
 * ===================================================================== */

/** Làm giàu 1 dòng item của đơn: bao bì, tồn, quy đổi kiện, cờ thiếu tồn. */
function om_enrich_item($it)
{
    $pid  = (int) ($it['product_id'] ?? 0);
    $qty  = (float) ($it['qt_order'] ?? 0);
    $name = (string) ($it['product_name'] ?? '');
    $unit = (string) ($it['unit'] ?? '');

    $ops_qty = 0; $short = ''; $stock = 0.0;
    if ($pid > 0) {
        $pk = om_get_product_packaging($pid);
        if ($pk) {
            $ops_qty = (int) $pk['ops_qty'];
            $short   = (string) $pk['short_name'];
            $stock   = (float) $pk['stock'];
            if ($unit === '') $unit = (string) $pk['unit'];
            // Ưu tiên tên thường gọi hiện tại (COALESCE trong om_get_product_packaging) thay vì tên đã lưu lúc đặt đơn.
            if ($pk['product_name'] !== '' && $pk['product_name'] !== null) $name = (string) $pk['product_name'];
        }
    }

    $kien = om_qty_to_kien($qty, $ops_qty, $short, $unit);
    return [
        'product_id'   => $pid,
        'material_id'  => $it['material_id'] ?? null,
        'product_name' => $name,
        'unit'         => $unit,
        'qt_order'     => $qty,
        'weight_kg'    => (float) ($it['weight_kg'] ?? 0),
        'system_price' => (float) ($it['system_price'] ?? 0),
        'line_weight'  => (float) ($it['line_weight'] ?? ($qty * (float) ($it['weight_kg'] ?? 0))),
        'line_value'   => (float) ($it['line_value'] ?? ($qty * (float) ($it['system_price'] ?? 0))),
        'ops_qty'      => $ops_qty,
        'short_name'   => $short,
        'letter'       => om_kien_letter($short),
        'stock'        => $stock,
        'kien_text'    => $kien['text'],
        'kien_whole'   => $kien['whole'],
        'kien_rem'     => $kien['rem'],
        'inv_kien'     => om_inventory_kien($stock, $ops_qty, $short, $unit),
        'pack_label'   => ($ops_qty > 0 && $short) ? ($ops_qty . ' ' . $unit . '/' . mb_strtolower($short, 'UTF-8')) : '',
        'is_short'     => ($pid > 0 && $qty > $stock),
        'reminder_note' => ($pid > 0) ? om_get_product_pickup_note($pid) : '',
    ];
}

/** Thống kê tổng từ danh sách item đã làm giàu. */
function om_order_stats($items)
{
    $w = 0; $v = 0; $short = 0;
    foreach ($items as $it) {
        $w += (float) $it['line_weight'];
        $v += (float) $it['line_value'];
        if (!empty($it['is_short'])) $short++;
    }
    return ['weight_total' => $w, 'value_total' => $v, 'short_count' => $short];
}

/** Gộp "số kiện dự kiến": tổng whole theo letter + đếm SP lẻ. */
function om_kien_summary($items)
{
    $by_letter = []; $le = 0;
    foreach ($items as $it) {
        $w = (int) ($it['kien_whole'] ?? 0);
        $l = (string) ($it['letter'] ?? '');
        if ($w > 0 && $l !== '') {
            $by_letter[$l] = ($by_letter[$l] ?? 0) + $w;
        }
        // SP lẻ: có phần dư hoặc không đủ 1 kiện.
        if (!empty($it['kien_rem']) && $it['kien_rem'] > 0) $le++;
        elseif ($w === 0) $le++;
    }
    $parts = [];
    foreach ($by_letter as $l => $n) $parts[] = $n . $l;
    return ['parts' => $parts, 'loose' => $le];
}

/** 1 đơn hàng (đã làm giàu item + stats + màu chi nhánh + người nhận). */
function om_get_order($id)
{
    $id = (int) $id;
    $row = db_fetch_row(
        "SELECT h.id, h.user_id, h.customer_id, h.customer_name, h.order_items,
                h.weight_total, h.value_total, h.description, h.note, h.status, h.created_at,
                c.secondary_color, c.receiver, c.short_name, c.name AS c_name
         FROM factory_order_sales_history h
         LEFT JOIN customers c ON c.id = h.customer_id
         WHERE h.id = $id LIMIT 1"
    );
    if (!$row) return null;

    $raw = !empty($row['order_items']) ? json_decode($row['order_items'], true) : [];
    $items = [];
    foreach ((array) $raw as $it) $items[] = om_enrich_item($it);

    // Gắn thứ tự hiển thị (cấu hình phiếu soạn) cho từng SP.
    $pids = [];
    foreach ($items as $it) if (!empty($it['product_id'])) $pids[] = (int) $it['product_id'];
    $smap = om_slip_order_map($pids);
    foreach ($items as &$it) {
        $pid = (int) ($it['product_id'] ?? 0);
        $it['sort_order'] = ($pid > 0 && array_key_exists($pid, $smap)) ? $smap[$pid] : null;
    }
    unset($it);

    $row['items']   = $items;
    $row['stats']   = om_order_stats($items);
    $row['kien_sum'] = om_kien_summary($items);
    unset($row['order_items']);
    return $row;
}

/** Danh sách đơn hàng từ chi nhánh (phân trang), đã làm giàu để hiển thị. */
function om_get_branch_orders($page = 1, $per_page = 10)
{
    $page     = max(1, (int) $page);
    $per_page = max(1, (int) $per_page);
    $offset   = ($page - 1) * $per_page;

    om_ensure_order_flags();
    $rows = db_fetch_array(
        "SELECT h.id, h.user_id, h.customer_id, h.customer_name, h.order_items,
                h.weight_total, h.value_total, h.note, h.status, h.created_at,
                h.picked, h.picked_at, h.pickup_time, h.locked, h.confirmed,
                c.secondary_color, c.receiver, c.short_name AS cust_short
         FROM factory_order_sales_history h
         LEFT JOIN customers c ON c.id = h.customer_id
         WHERE h.factory_hidden = 0
         ORDER BY h.picked ASC, h.created_at DESC, h.id DESC
         LIMIT $per_page OFFSET $offset"
    ) ?: [];

    $orders = [];
    foreach ($rows as $r) {
        $raw   = !empty($r['order_items']) ? json_decode($r['order_items'], true) : [];
        $items = [];
        foreach ((array) $raw as $it) $items[] = om_enrich_item($it);
        $r['items'] = $items;
        $r['stats'] = om_order_stats($items);
        $r['branch_reminders'] = om_get_branch_pickup_reminders((int) $r['customer_id']);
        unset($r['order_items']);
        $orders[] = $r;
    }

    $total = (int) db_num_rows("SELECT id FROM factory_order_sales_history WHERE factory_hidden = 0");
    return [
        'rows'        => $orders,
        'total'       => $total,
        'page'        => $page,
        'per_page'    => $per_page,
        'total_pages' => (int) ceil($total / $per_page),
    ];
}

/**
 * Nhà máy tự tạo 1 đơn hàng thủ công cho 1 chi nhánh (không qua bán hàng gửi lên).
 * $items: [{type:'product'|'material', product_id, product_name, unit, system_price, weight_kg, qty}, ...]
 * Dùng lại đúng cấu trúc order_items như om_update_quantities() để om_enrich_item() đọc được.
 */
function om_create_manual_order($customer_id, $items, $order_date = '', $note = '')
{
    $customer_id = (int) $customer_id;
    if ($customer_id <= 0 || !is_array($items) || !$items) return false;

    $cust = db_fetch_row("SELECT name FROM customers WHERE id = $customer_id LIMIT 1");
    if (!$cust) return false;

    $order_items = [];
    $wt = 0; $val = 0;
    foreach ($items as $a) {
        $q   = (float) ($a['qty'] ?? 0);
        $pid = (int) ($a['product_id'] ?? 0);
        if ($q <= 0 || $pid <= 0) continue;
        $type  = ($a['type'] ?? 'product') === 'material' ? 'material' : 'product';
        $wkg   = (float) ($a['weight_kg'] ?? 0);
        $price = (float) ($a['system_price'] ?? 0);
        $lw = $q * $wkg;
        $lv = $q * $price;
        $order_items[] = [
            'product_id'   => $type === 'material' ? null : $pid,
            'material_id'  => $type === 'material' ? $pid : null,
            'type'         => $type,
            'product_name' => (string) ($a['product_name'] ?? ''),
            'unit'         => (string) ($a['unit'] ?? ''),
            'qt_order'     => $q,
            'weight_kg'    => $wkg,
            'system_price' => $price,
            'line_weight'  => $lw,
            'line_value'   => $lv,
        ];
        $wt  += $lw;
        $val += $lv;
    }
    if (!$order_items) return false;

    om_ensure_order_flags();
    $description = 'order nhà máy ' . number_format($wt, 0, ',', '.') . 'kg - ' . number_format($val, 0, ',', '.') . ' đ';
    $row = [
        'user_id'       => null,
        'customer_id'   => $customer_id,
        'customer_name' => $cust['name'],
        'order_items'   => json_encode($order_items, JSON_UNESCAPED_UNICODE),
        'weight_total'  => $wt,
        'value_total'   => $val,
        'description'   => $description,
        'note'          => $note !== '' ? $note : 'Đơn thủ công (nhà máy tự nhập)',
        'status'        => 'new',
    ];
    // Ngày tạo đơn nhà máy tự chọn -> giữ nguyên giờ hiện tại, chỉ đổi phần ngày
    // (để thứ tự "mới nhất trước" trong cùng ngày vẫn hợp lý).
    if ($order_date !== '' && preg_match('/^\d{4}-\d{2}-\d{2}$/', $order_date)) {
        $row['created_at'] = $order_date . ' ' . date('H:i:s');
    }
    return db_insert('factory_order_sales_history', $row);
}

/* =====================================================================
 *  TRẠNG THÁI ĐƠN (nhà máy thao tác) + cập nhật số lượng
 * ===================================================================== */

/**
 * Bảo đảm các cột cờ ẩn 2 chiều + xác nhận đơn tồn tại (idempotent).
 * Trùng logic với sf_ensure_order_flags() bên sell_factory.
 */
function om_ensure_order_flags()
{
    static $done = false;
    if ($done) return;
    $done = true;
    if (!db_fetch_row("SHOW COLUMNS FROM factory_order_sales_history LIKE 'factory_hidden'"))
        db_query("ALTER TABLE factory_order_sales_history ADD factory_hidden TINYINT(1) NOT NULL DEFAULT 0");
    if (!db_fetch_row("SHOW COLUMNS FROM factory_order_sales_history LIKE 'seller_hidden'"))
        db_query("ALTER TABLE factory_order_sales_history ADD seller_hidden TINYINT(1) NOT NULL DEFAULT 0");
    if (!db_fetch_row("SHOW COLUMNS FROM factory_order_sales_history LIKE 'confirmed'"))
        db_query("ALTER TABLE factory_order_sales_history ADD confirmed TINYINT(1) NOT NULL DEFAULT 0");
}

/** Meta tối thiểu của 1 đơn (cho kiểm tra/đẩy chuông). */
function om_get_order_meta($id)
{
    $id = (int) $id;
    return db_fetch_row(
        "SELECT id, user_id, customer_name, picked, locked, confirmed, pickup_time
         FROM factory_order_sales_history WHERE id = $id LIMIT 1"
    );
}

/** Nhà máy "Xác nhận đơn hàng" / bỏ xác nhận. */
function om_set_confirmed($id, $confirmed)
{
    $id = (int) $id;
    if ($id <= 0) return false;
    om_ensure_order_flags();
    db_update('factory_order_sales_history', ['confirmed' => $confirmed ? 1 : 0], "id = $id");
    return true;
}

/** Đánh dấu đã bốc / bỏ đánh dấu. */
function om_set_picked($id, $picked)
{
    $id = (int) $id;
    if ($id <= 0) return false;
    $p = $picked ? 1 : 0;
    db_update('factory_order_sales_history', [
        'picked'    => $p,
        'picked_at' => $p ? date('Y-m-d H:i:s') : null,
    ], "id = $id");
    return true;
}

/** Khóa / mở khóa đơn (khóa = không cho bán hàng sửa). */
function om_set_lock($id, $locked)
{
    $id = (int) $id;
    if ($id <= 0) return false;
    db_update('factory_order_sales_history', ['locked' => $locked ? 1 : 0], "id = $id");
    return true;
}

/**
 * Nhà máy ẩn 1 đơn khỏi danh sách của mình.
 * KHÔNG xóa cứng: lịch sử đặt hàng thuộc quyền quản lý của bán hàng nên giữ nguyên.
 */
function om_delete_order($id)
{
    $id = (int) $id;
    if ($id <= 0) return false;
    om_ensure_order_flags();
    db_update('factory_order_sales_history', ['factory_hidden' => 1], "id = $id");
    return true;
}

/** Lưu ngày giờ bốc (nhà máy hẹn). */
function om_set_pickup($id, $pickup_time)
{
    $id = (int) $id;
    if ($id <= 0) return false;
    $ts = $pickup_time ? date('Y-m-d H:i:s', strtotime($pickup_time)) : null;
    db_update('factory_order_sales_history', ['pickup_time' => $ts], "id = $id");
    return true;
}

/**
 * Nhà máy điều chỉnh số lượng các dòng -> cập nhật order_items + tổng.
 * $qty_map    = [index => qty]. Giữ nguyên thông tin SP, chỉ đổi qt_order.
 * $additions  = [ {type, product_id, product_name, unit, system_price, weight_kg, qty}, ... ]
 *               các dòng SP/NVL mới nhà máy bổ sung vào đơn.
 * $removed    = [index, ...] các dòng nhà máy xóa khỏi đơn.
 */
function om_update_quantities($id, $qty_map, $additions = [], $removed = [])
{
    $id = (int) $id;
    if ($id <= 0 || !is_array($qty_map)) return false;
    $row = db_fetch_row("SELECT order_items FROM factory_order_sales_history WHERE id = $id LIMIT 1");
    if (!$row) return false;
    $items = json_decode((string) $row['order_items'], true);
    if (!is_array($items)) return false;

    $removed = is_array($removed) ? array_map('intval', $removed) : [];

    // Cập nhật SL + bỏ các dòng đã xóa; tái lập chỉ số tuần tự.
    $kept = [];
    foreach ($items as $i => $it) {
        if (in_array((int) $i, $removed, true)) continue; // dòng đã xóa
        if (array_key_exists((string) $i, $qty_map) || array_key_exists($i, $qty_map)) {
            $q = (float) ($qty_map[$i] ?? $qty_map[(string) $i] ?? 0);
            $it['qt_order']    = $q;
            $it['line_weight'] = $q * (float) ($it['weight_kg'] ?? 0);
            $it['line_value']  = $q * (float) ($it['system_price'] ?? 0);
        }
        $kept[] = $it;
    }
    $items = $kept;

    // Bổ sung các dòng mới (giữ cùng cấu trúc với item gốc trong order_items).
    if (is_array($additions)) {
        foreach ($additions as $a) {
            $q = (float) ($a['qty'] ?? 0);
            $pid = (int) ($a['product_id'] ?? 0);
            if ($q <= 0 || $pid <= 0) continue;
            $type  = ($a['type'] ?? 'product') === 'material' ? 'material' : 'product';
            $wkg   = (float) ($a['weight_kg'] ?? 0);
            $price = (float) ($a['system_price'] ?? 0);
            $items[] = [
                'product_id'   => $type === 'material' ? null : $pid,
                'material_id'  => $type === 'material' ? $pid : null,
                'type'         => $type,
                'product_name' => (string) ($a['product_name'] ?? ''),
                'unit'         => (string) ($a['unit'] ?? ''),
                'qt_order'     => $q,
                'weight_kg'    => $wkg,
                'system_price' => $price,
                'line_weight'  => $q * $wkg,
                'line_value'   => $q * $price,
            ];
        }
    }

    // Tính lại tổng trên danh sách cuối.
    $wt = 0; $val = 0;
    foreach ($items as $it) {
        $wt  += (float) ($it['line_weight'] ?? 0);
        $val += (float) ($it['line_value'] ?? 0);
    }

    db_update('factory_order_sales_history', [
        'order_items'  => json_encode($items, JSON_UNESCAPED_UNICODE),
        'weight_total' => $wt,
        'value_total'  => $val,
        'description'  => 'order nhà máy ' . number_format($wt, 0, ',', '.') . 'kg - ' . number_format($val, 0, ',', '.') . ' đ',
    ], "id = $id");
    return true;
}
