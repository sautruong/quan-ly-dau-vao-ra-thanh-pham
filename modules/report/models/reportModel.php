<?php
/**
 * report model
 * - Truy vấn dữ liệu cho trang finished_goods_inventory
 */

/** Đăng ký/di chuyển 3 view tồn kho vào nhóm menu "TỒN KHO" (idempotent — chạy ở trang chính). */
function rp_ensure_inventory_views_group()
{
    if (db_num_rows("SHOW TABLES LIKE 'tbl_views'") <= 0) return;
    db_query("INSERT INTO tbl_views (module, controller, action, label, group_label, sort)
              VALUES ('report','report','finished_goods_inventory','Tồn kho thành phẩm','TỒN KHO', 60)
              ON DUPLICATE KEY UPDATE group_label = VALUES(group_label), sort = VALUES(sort)");
    db_query("INSERT INTO tbl_views (module, controller, action, label, group_label, sort)
              VALUES ('report','report','material_inventory','Tồn kho nguyên vật liệu','TỒN KHO', 61)
              ON DUPLICATE KEY UPDATE group_label = VALUES(group_label), sort = VALUES(sort)");
    db_query("INSERT INTO tbl_views (module, controller, action, label, group_label, sort)
              VALUES ('report','report','stock_at_point','Tồn kho tại thời điểm','TỒN KHO', 62)
              ON DUPLICATE KEY UPDATE group_label = VALUES(group_label), sort = VALUES(sort)");
}

/**
 * Lấy danh sách sản phẩm có tồn > 0 nhóm theo danh mục (kèm quy đổi bao bì ngoài).
 * Trả về mảng [{category_id, category_name, products: [{...}]}].
 * $user_id > 0: ưu tiên thứ tự CÁ NHÂN HÓA (kéo-thả đổi vị trí ngay trên lưới) của user đó
 * trong từng danh mục — sản phẩm đã có thứ tự cá nhân lên trước, xếp theo thứ tự đó; các sản
 * phẩm còn lại (chưa từng kéo) rơi xuống dưới, xếp theo thứ tự chung (tab "Thứ tự hiển thị").
 */
function rp_fgi_get_products_grouped_by_category($user_id = 0)
{
    rp_fgi_ensure_display_order_table();
    rp_fgi_ensure_personal_display_order_table();
    rp_ensure_product_common_name_column();
    $uid = (int) $user_id;
    $sql = "SELECT
                p.id                          AS product_id,
                COALESCE(NULLIF(p.common_product_name, ''), p.product_name) AS product_name,
                COALESCE(NULLIF(p.unit, ''), '') AS unit,
                pc.id                         AS category_id,
                pc.category_name,
                fgi.quantity                  AS qt_fgi,
                ops.outer_packaging_short_name,
                ops.quantity                  AS qt_ops
            FROM finished_goods_inventory fgi
            INNER JOIN products           p   ON p.id  = fgi.product_id
            INNER JOIN product_categories pc  ON pc.id = p.category_id
            LEFT  JOIN outer_packaging_specifications ops ON ops.product_id = p.id
            LEFT  JOIN sf_product_display_order pdo ON pdo.product_id = p.id
            LEFT  JOIN sf_product_display_order_personal pdop ON pdop.product_id = p.id AND pdop.user_id = $uid
            WHERE fgi.quantity > 0
            ORDER BY pc.id ASC,
                     (pdop.sort_order IS NULL) ASC, pdop.sort_order ASC,
                     (pdo.sort_order IS NULL) ASC, pdo.sort_order ASC,
                     p.product_name ASC";

    $rows = db_fetch_array($sql) ?: [];

    $groups = [];
    foreach ($rows as $r) {
        $cid = $r['category_id'];
        if (!isset($groups[$cid])) {
            $groups[$cid] = [
                'category_id'   => $cid,
                'category_name' => $r['category_name'],
                'products'      => [],
            ];
        }
        $qt_fgi = (int) $r['qt_fgi'];
        $qt_ops = $r['qt_ops'] !== null ? (int) $r['qt_ops'] : 0;
        $groups[$cid]['products'][] = [
            'product_id'                 => $r['product_id'],
            'product_name'               => $r['product_name'],
            'unit'                       => $r['unit'],
            'qt_fgi'                     => $qt_fgi,
            'qt_ops'                     => $qt_ops,
            'outer_packaging_short_name' => $r['outer_packaging_short_name'],
            'pack_conv_text'             => rp_fgi_format_pack_conv(
                                                $qt_fgi,
                                                $qt_ops,
                                                $r['outer_packaging_short_name'],
                                                $r['unit']
                                            ),
        ];
    }
    return array_values($groups);
}

/**
 * Quy đổi tồn theo quy cách bao bì ngoài.
 *  - qt_fgi  < qt_ops  →  "qt_fgi unit"            (vd: "7 gói")
 *  - phần dư = 0       →  "whole ops_name"         (vd: "6 Bao")
 *  - phần dư > 0       →  "whole ops_name rem unit" (vd: "6 Bao 12 gói")
 */
function rp_fgi_format_pack_conv($qt_fgi, $qt_ops, $ops_name, $unit)
{
    $unit     = trim((string) $unit);
    $ops_name = trim((string) $ops_name);

    if ($qt_ops <= 0 || $qt_fgi < $qt_ops) {
        return $qt_fgi . ' ' . $unit;
    }
    $whole     = intdiv($qt_fgi, $qt_ops);
    $remainder = $qt_fgi % $qt_ops;

    if ($remainder === 0) {
        return $whole . ' ' . $ops_name;
    }
    return $whole . ' ' . $ops_name . ' ' . $remainder . ' ' . $unit;
}

/* ============================================================
 *  Thứ tự hiển thị sản phẩm (dùng chung bảng sf_product_display_order
 *  với order_factory — sửa 1 nơi áp dụng cả 2 trang).
 * ============================================================ */

function rp_fgi_ensure_display_order_table()
{
    static $done = false;
    if ($done) return;
    $done = true;
    db_query("CREATE TABLE IF NOT EXISTS sf_product_display_order (
        product_id INT NOT NULL PRIMARY KEY,
        sort_order INT NULL
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci");
}

/** Toàn bộ sản phẩm + thứ tự hiển thị hiện tại (cho modal cài đặt). */
function rp_fgi_all_products_for_order()
{
    rp_fgi_ensure_display_order_table();
    return db_fetch_array(
        "SELECT p.id AS product_id, p.product_name,
                pc.category_name, pdo.sort_order
         FROM products p
         LEFT JOIN product_categories pc ON pc.id = p.category_id
         LEFT JOIN sf_product_display_order pdo ON pdo.product_id = p.id
         ORDER BY pc.id ASC, (pdo.sort_order IS NULL) ASC, pdo.sort_order ASC, p.product_name ASC"
    ) ?: [];
}

/** Ghi đè thứ tự hiển thị: $map = [product_id => sort_order|''|null]. */
function rp_fgi_save_display_order($map)
{
    rp_fgi_ensure_display_order_table();
    if (!is_array($map)) return false;
    foreach ($map as $pid => $order) {
        $pid = (int) $pid;
        if ($pid <= 0) continue;
        $has = ($order !== '' && $order !== null && is_numeric($order));
        if ($has) {
            $ord = (int) $order;
            $exists = db_num_rows("SELECT 1 FROM sf_product_display_order WHERE product_id = $pid") > 0;
            if ($exists) db_update('sf_product_display_order', ['sort_order' => $ord], "product_id = $pid");
            else db_insert('sf_product_display_order', ['product_id' => $pid, 'sort_order' => $ord]);
        } else {
            db_delete('sf_product_display_order', "product_id = $pid");
        }
    }
    return true;
}

/* ============================================================
 *  Thứ tự hiển thị CÁ NHÂN HÓA (kéo-giữ chuột đổi vị trí 2 sản phẩm ngay
 *  trên lưới .of-inventory) — riêng theo từng user, KHÔNG ảnh hưởng thứ
 *  tự chung (sf_product_display_order) mà các user khác/tab cài đặt vẫn
 *  thấy. Sản phẩm chưa từng kéo thì dùng lại thứ tự chung làm nền.
 * ============================================================ */

function rp_fgi_ensure_personal_display_order_table()
{
    static $done = false;
    if ($done) return;
    $done = true;
    db_query("CREATE TABLE IF NOT EXISTS sf_product_display_order_personal (
        user_id    INT NOT NULL,
        product_id INT NOT NULL,
        sort_order INT NOT NULL,
        PRIMARY KEY (user_id, product_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci");
}

/**
 * Ghi lại thứ tự cá nhân cho 1 danh mục (sau khi user kéo đổi vị trí 2 sản phẩm):
 * $ordered_ids = TOÀN BỘ product_id trong danh mục đó, theo đúng thứ tự hiển thị
 * mới trên lưới — đánh lại sort_order 1..N. Phải ghi cả danh mục (không chỉ 2 sản
 * phẩm vừa đổi chỗ), nếu không 2 sản phẩm đó sẽ nhảy lên đầu (đứng trước các sản
 * phẩm chưa có thứ tự cá nhân) do cách so sánh NULL trong rp_fgi_get_products_grouped_by_category().
 */
function rp_fgi_save_personal_display_order($user_id, $ordered_ids)
{
    rp_fgi_ensure_personal_display_order_table();
    $uid = (int) $user_id;
    if ($uid <= 0 || !is_array($ordered_ids)) return false;
    $i = 1;
    foreach ($ordered_ids as $pid) {
        $pid = (int) $pid;
        if ($pid <= 0) continue;
        db_query("INSERT INTO sf_product_display_order_personal (user_id, product_id, sort_order)
                  VALUES ($uid, $pid, $i)
                  ON DUPLICATE KEY UPDATE sort_order = VALUES(sort_order)");
        $i++;
    }
    return true;
}

/* ============================================================
 *  Điều chỉnh tồn kho thủ công (tab "Điều chỉnh tồn kho" — chỉ admin,
 *  gate quyền ở controller). Sửa thẳng finished_goods_inventory.quantity,
 *  áp dụng cho MỌI sản phẩm (kể cả sản phẩm đang tồn 0, không lọt lưới
 *  chính vì lưới chính chỉ hiện quantity > 0).
 * ============================================================ */

/** Toàn bộ sản phẩm + tồn kho hiện tại (cho tab "Điều chỉnh tồn kho"). */
function rp_fgi_all_products_with_stock()
{
    return db_fetch_array(
        "SELECT p.id AS product_id,
                COALESCE(NULLIF(p.common_product_name, ''), p.product_name) AS product_name,
                pc.category_name,
                COALESCE(fgi.quantity, 0) AS quantity
         FROM products p
         LEFT JOIN product_categories pc ON pc.id = p.category_id
         LEFT JOIN finished_goods_inventory fgi ON fgi.product_id = p.id
         ORDER BY pc.id ASC, p.product_name ASC"
    ) ?: [];
}

/** Ghi đè tồn kho: $map = [product_id => quantity]. Bỏ qua giá trị không hợp lệ/âm. */
function rp_fgi_save_stock_adjustment($map)
{
    if (!is_array($map)) return false;
    foreach ($map as $pid => $qty) {
        $pid = (int) $pid;
        if ($pid <= 0 || !is_numeric($qty)) continue;
        $q = max(0, (int) $qty);
        $exists = db_num_rows("SELECT 1 FROM finished_goods_inventory WHERE product_id = $pid") > 0;
        if ($exists) db_update('finished_goods_inventory', ['quantity' => $q], "product_id = $pid");
        else db_insert('finished_goods_inventory', ['product_id' => $pid, 'quantity' => $q]);
    }
    return true;
}

/* ============================================================
 *  Nhóm sản phẩm "đã lâu chưa bán" — dựa 2 LÔ sản xuất gần nhất
 *  của sản phẩm (production_receipts). Tham số $months: 1|3|6|12.
 *
 *  Gọi: a = tồn hiện tại (fgi.quantity)
 *       b, d1 = số lượng & ngày của lô sản xuất gần nhất
 *       c, d2 = số lượng & ngày của lô sản xuất trước đó
 *  k = a - b:
 *    - k > 0  (tồn nhiều hơn cả lô gần nhất → còn ≥ 2 lô trong tồn)
 *              → ngày được xét = d2
 *    - k <= 0 (tồn nằm gọn trong lô gần nhất → chỉ 1 lô)
 *              → ngày được xét = d1
 *  Nếu không có d2 (chưa đủ 2 lô) thì luôn dùng d1 dù k > 0.
 *  Điều kiện lọc: còn tồn (> 0) VÀ ngày được xét cách hiện tại
 *  > $months tháng (hoặc sản phẩm chưa từng sản xuất).
 * ============================================================ */
function rp_fgi_long_unsold($months)
{
    $m = (int) $months;
    if (!in_array($m, [1, 3, 6, 12], true)) $m = 3;
    rp_fgi_ensure_discontinued_table();
    rp_ensure_product_common_name_column();

    $sql = "SELECT p.id AS product_id,
                   COALESCE(NULLIF(p.common_product_name, ''), p.product_name) AS product_name,
                   COALESCE(NULLIF(p.unit, ''), '') AS unit,
                   COALESCE(pc.category_name, 'Chưa phân loại') AS category_name,
                   fgi.quantity AS qt_fgi,
                   ops.outer_packaging_short_name,
                   ops.quantity AS qt_ops,
                   r1.quantity   AS last_qty,
                   r1.created_at AS last_prod,
                   r2.quantity   AS prev_qty,
                   r2.created_at AS prev_prod
            FROM finished_goods_inventory fgi
            INNER JOIN products p ON p.id = fgi.product_id
            LEFT JOIN product_categories pc ON pc.id = p.category_id
            LEFT JOIN outer_packaging_specifications ops ON ops.product_id = p.id
            LEFT JOIN (
                SELECT product_id, quantity, created_at,
                       ROW_NUMBER() OVER (PARTITION BY product_id ORDER BY created_at DESC, id DESC) AS rn
                FROM production_receipts
            ) r1 ON r1.product_id = p.id AND r1.rn = 1
            LEFT JOIN (
                SELECT product_id, quantity, created_at,
                       ROW_NUMBER() OVER (PARTITION BY product_id ORDER BY created_at DESC, id DESC) AS rn
                FROM production_receipts
            ) r2 ON r2.product_id = p.id AND r2.rn = 2
            WHERE fgi.quantity > 0
              AND p.id NOT IN (SELECT product_id FROM fgi_discontinued_products)";

    $rows = db_fetch_array($sql) ?: [];
    $threshold_t = strtotime("-$m months");

    $out = [];
    foreach ($rows as $r) {
        $qt_fgi   = (int) $r['qt_fgi'];
        $qt_ops   = $r['qt_ops'] !== null ? (int) $r['qt_ops'] : 0;
        $last_qty = $r['last_qty'] !== null ? (float) $r['last_qty'] : null;
        $last     = $r['last_prod'];
        $prev     = $r['prev_prod'];

        $k = $last_qty !== null ? ($qt_fgi - $last_qty) : null;
        $considered = ($k !== null && $k > 0 && $prev) ? $prev : $last;
        $considered_t = $considered ? strtotime($considered) : 0;

        if ($considered_t !== 0 && $considered_t >= $threshold_t) continue;

        $out[] = [
            'product_id'     => (int) $r['product_id'],
            'product_name'   => $r['product_name'],
            'category_name'  => $r['category_name'],
            'unit'           => $r['unit'],
            'qt_fgi'         => $qt_fgi,
            'pack_conv_text' => rp_fgi_format_pack_conv($qt_fgi, $qt_ops, $r['outer_packaging_short_name'], $r['unit']),
            'last_prod'      => $considered,
            'last_prod_text' => $considered_t ? date('d/m/Y', $considered_t) : 'Chưa từng sản xuất',
            'days_ago'       => $considered_t ? (int) floor((time() - $considered_t) / 86400) : null,
        ];
    }

    usort($out, function ($a, $b) {
        if (($a['last_prod'] === null) !== ($b['last_prod'] === null)) {
            return $a['last_prod'] === null ? -1 : 1;
        }
        $cmp = strcmp((string) $a['last_prod'], (string) $b['last_prod']);
        if ($cmp !== 0) return $cmp;
        return strcmp($a['product_name'], $b['product_name']);
    });

    return $out;
}

/* ---- Thẻ "Ngưng bán" cho sản phẩm (ẩn khỏi nhóm "đã lâu chưa bán") ---- */

function rp_fgi_ensure_discontinued_table()
{
    static $done = false;
    if ($done) return;
    $done = true;
    db_query("CREATE TABLE IF NOT EXISTS fgi_discontinued_products (
        product_id INT NOT NULL PRIMARY KEY,
        created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci");
}

/** Gắn/bỏ thẻ "Ngưng bán" cho 1 sản phẩm. */
function rp_fgi_set_discontinued($product_id, $on)
{
    rp_fgi_ensure_discontinued_table();
    $pid = (int) $product_id;
    if ($pid <= 0) return false;
    if ($on) {
        if (db_num_rows("SELECT 1 FROM fgi_discontinued_products WHERE product_id = $pid") === 0) {
            db_insert('fgi_discontinued_products', ['product_id' => $pid]);
        }
    } else {
        db_delete('fgi_discontinued_products', "product_id = $pid");
    }
    return true;
}

/** Danh sách sản phẩm đang "Ngưng bán" (kèm tồn + lần sản xuất gần nhất). */
function rp_fgi_discontinued_list()
{
    rp_fgi_ensure_discontinued_table();
    rp_ensure_product_common_name_column();
    $sql = "SELECT p.id AS product_id,
                   COALESCE(NULLIF(p.common_product_name, ''), p.product_name) AS product_name,
                   COALESCE(NULLIF(p.unit, ''), '') AS unit,
                   COALESCE(pc.category_name, 'Chưa phân loại') AS category_name,
                   COALESCE(fgi.quantity, 0) AS qt_fgi,
                   ops.outer_packaging_short_name, ops.quantity AS qt_ops,
                   d.created_at
            FROM fgi_discontinued_products d
            INNER JOIN products p ON p.id = d.product_id
            LEFT JOIN product_categories pc ON pc.id = p.category_id
            LEFT JOIN finished_goods_inventory fgi ON fgi.product_id = p.id
            LEFT JOIN outer_packaging_specifications ops ON ops.product_id = p.id
            ORDER BY p.product_name ASC";
    $rows = db_fetch_array($sql) ?: [];
    $out = [];
    foreach ($rows as $r) {
        $qt_fgi = (int) $r['qt_fgi'];
        $qt_ops = $r['qt_ops'] !== null ? (int) $r['qt_ops'] : 0;
        $out[] = [
            'product_id'     => (int) $r['product_id'],
            'product_name'   => $r['product_name'],
            'category_name'  => $r['category_name'],
            'unit'           => $r['unit'],
            'qt_fgi'         => $qt_fgi,
            'pack_conv_text' => rp_fgi_format_pack_conv($qt_fgi, $qt_ops, $r['outer_packaging_short_name'], $r['unit']),
        ];
    }
    return $out;
}

/**
 * Lấy danh sách NVL có tồn > 0 nhóm theo nhà cung cấp.
 * Trả về mảng [{supplier_id, supplier_name, materials: [{...}]}].
 *
 * $keyword (tuỳ chọn): lọc theo material_name (LIKE %kw%).
 */
function rp_mi_get_materials_grouped_by_supplier($keyword = '')
{
    $kw   = trim((string) $keyword);
    $cond = '';
    if ($kw !== '') {
        $kw_safe = escape_string($kw);
        $cond = " AND mi.material_name LIKE '%$kw_safe%'";
    }

    $sql = "SELECT
                mi.id            AS material_id,
                mi.material_name,
                mi.unit,
                s.id             AS supplier_id,
                s.supplier_name,
                minv.quantity    AS qt
            FROM material_inventory minv
            INNER JOIN material_information mi ON mi.id = minv.material_id
            LEFT  JOIN suppliers s             ON s.id  = mi.supplier_id
            WHERE minv.quantity > 0
              $cond
            ORDER BY s.id ASC, mi.material_name ASC";

    $rows = db_fetch_array($sql) ?: [];

    $groups = [];
    foreach ($rows as $r) {
        $sid = $r['supplier_id'] !== null ? (int) $r['supplier_id'] : 0;
        if (!isset($groups[$sid])) {
            $groups[$sid] = [
                'supplier_id'   => $sid,
                'supplier_name' => $r['supplier_name'] !== null ? $r['supplier_name'] : 'Chưa có nhà cung cấp',
                'materials'     => [],
            ];
        }
        $groups[$sid]['materials'][] = [
            'material_id'    => (int) $r['material_id'],
            'material_name'  => $r['material_name'],
            'unit'           => trim((string) $r['unit']),
            'quantity'       => (float) $r['qt'],
            'inventory_text' => rp_mi_format_inventory((float) $r['qt'], $r['unit']),
        ];
    }
    return array_values($groups);
}

/** Admin sửa trực tiếp tồn kho NVL (cột "Tồn kho" ở view material_inventory). */
function rp_mi_update_quantity($material_id, $quantity)
{
    $mid = (int) $material_id;
    if ($mid <= 0) return false;
    db_update('material_inventory', ['quantity' => (float) $quantity], "material_id = $mid");
    return true;
}

/**
 * Format tồn NVL: "{quantity} {unit}". Bỏ phần thập phân nếu là số nguyên.
 */
function rp_mi_format_inventory($qty, $unit)
{
    $unit = trim((string) $unit);
    $q = (float) $qty;
    $q_text = ($q == (int) $q) ? (string) (int) $q : rtrim(rtrim(number_format($q, 3, '.', ''), '0'), '.');
    return $q_text . ' ' . $unit;
}

/**
 * Toàn bộ NVL đang có dòng tồn kho (kể cả hết = 0 hoặc âm) ở dạng bảng phẳng
 * (Tên NVL | NCC | đơn vị | phân loại | tồn kho). Lọc/sắp xếp/nhóm xử lý phía client.
 */
function rp_mi_get_materials_table()
{
    rp_mi_ensure_display_order_table();
    $sql = "SELECT
                mi.id            AS material_id,
                mi.material_name,
                COALESCE(NULLIF(mi.unit, ''), '') AS unit,
                COALESCE(NULLIF(mi.classification, ''), 'Chưa phân loại') AS classification,
                COALESCE(NULLIF(s.supplier_name, ''), 'Chưa có nhà cung cấp') AS supplier_name,
                minv.quantity    AS qt
            FROM material_inventory minv
            INNER JOIN material_information mi ON mi.id = minv.material_id
            LEFT  JOIN suppliers s             ON s.id  = mi.supplier_id
            LEFT  JOIN mi_material_display_order mdo ON mdo.material_id = mi.id
            ORDER BY (mdo.sort_order IS NULL) ASC, mdo.sort_order ASC, mi.material_name ASC";

    $rows = db_fetch_array($sql) ?: [];
    $out = [];
    foreach ($rows as $r) {
        $out[] = [
            'material_id'    => (int) $r['material_id'],
            'material_name'  => $r['material_name'],
            'supplier_name'  => $r['supplier_name'],
            'unit'           => trim((string) $r['unit']),
            'classification' => $r['classification'],
            'quantity'       => (float) $r['qt'],
            'inventory_text' => rp_mi_format_inventory((float) $r['qt'], $r['unit']),
        ];
    }
    return $out;
}

/* ============================================================
 *  Thứ tự hiển thị NVL (bảng riêng mi_material_display_order),
 *  logic giống thứ tự hiển thị sản phẩm tồn thành phẩm.
 * ============================================================ */

function rp_mi_ensure_display_order_table()
{
    static $done = false;
    if ($done) return;
    $done = true;
    db_query("CREATE TABLE IF NOT EXISTS mi_material_display_order (
        material_id INT NOT NULL PRIMARY KEY,
        sort_order  INT NULL
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci");
}

/** Toàn bộ NVL + thứ tự hiển thị hiện tại (cho modal cài đặt). */
function rp_mi_all_materials_for_order()
{
    rp_mi_ensure_display_order_table();
    return db_fetch_array(
        "SELECT mi.id AS material_id, mi.material_name,
                COALESCE(NULLIF(s.supplier_name, ''), 'Chưa có nhà cung cấp') AS supplier_name,
                mdo.sort_order
         FROM material_information mi
         LEFT JOIN suppliers s ON s.id = mi.supplier_id
         LEFT JOIN mi_material_display_order mdo ON mdo.material_id = mi.id
         ORDER BY (mdo.sort_order IS NULL) ASC, mdo.sort_order ASC, mi.material_name ASC"
    ) ?: [];
}

/** Ghi đè thứ tự hiển thị NVL: $map = [material_id => sort_order|''|null]. */
function rp_mi_save_display_order($map)
{
    rp_mi_ensure_display_order_table();
    if (!is_array($map)) return false;
    foreach ($map as $mid => $order) {
        $mid = (int) $mid;
        if ($mid <= 0) continue;
        $has = ($order !== '' && $order !== null && is_numeric($order));
        if ($has) {
            $ord = (int) $order;
            $exists = db_num_rows("SELECT 1 FROM mi_material_display_order WHERE material_id = $mid") > 0;
            if ($exists) db_update('mi_material_display_order', ['sort_order' => $ord], "material_id = $mid");
            else db_insert('mi_material_display_order', ['material_id' => $mid, 'sort_order' => $ord]);
        } else {
            db_delete('mi_material_display_order', "material_id = $mid");
        }
    }
    return true;
}

/* ============================================================
 *  NVL "đã lâu chưa dùng" — dựa 2 LẦN NHẬP gần nhất của NVL
 *  (raw_material_purchase_data). Tham số $months: 1|3|6|12.
 *
 *  Gọi: a = tồn hiện tại (minv.quantity)
 *       b, d1 = số lượng & ngày của lần nhập gần nhất
 *       c, d2 = số lượng & ngày của lần nhập trước đó
 *  k = a - b:
 *    - k > 0  (tồn nhiều hơn cả lần nhập gần nhất → còn ≥ 2 lô trong tồn)
 *              → ngày được xét = d2
 *    - k <= 0 (tồn nằm gọn trong lần nhập gần nhất → chỉ 1 lô)
 *              → ngày được xét = d1
 *  Nếu không có d2 (chưa đủ 2 lần nhập) thì luôn dùng d1 dù k > 0.
 *  Điều kiện lọc: còn tồn (> 0) VÀ ngày được xét cách hiện tại
 *  > $months tháng (hoặc NVL chưa từng nhập kho).
 * ============================================================ */
function rp_mi_long_unused($months)
{
    $m = (int) $months;
    if (!in_array($m, [1, 3, 6, 12], true)) $m = 3;
    rp_mi_ensure_discontinued_table();

    $sql = "SELECT
                mi.id            AS material_id,
                mi.material_name,
                COALESCE(NULLIF(mi.unit, ''), '') AS unit,
                COALESCE(NULLIF(mi.classification, ''), 'Chưa phân loại') AS classification,
                COALESCE(NULLIF(s.supplier_name, ''), 'Chưa có nhà cung cấp') AS supplier_name,
                minv.quantity    AS qt,
                r1.quantity      AS last_qty,
                r1.created_at    AS last_import,
                r2.quantity      AS prev_qty,
                r2.created_at    AS prev_import
            FROM material_inventory minv
            INNER JOIN material_information mi ON mi.id = minv.material_id
            LEFT  JOIN suppliers s             ON s.id  = mi.supplier_id
            LEFT JOIN (
                SELECT material_id, quantity, created_at,
                       ROW_NUMBER() OVER (PARTITION BY material_id ORDER BY created_at DESC, id DESC) AS rn
                FROM raw_material_purchase_data
            ) r1 ON r1.material_id = mi.id AND r1.rn = 1
            LEFT JOIN (
                SELECT material_id, quantity, created_at,
                       ROW_NUMBER() OVER (PARTITION BY material_id ORDER BY created_at DESC, id DESC) AS rn
                FROM raw_material_purchase_data
            ) r2 ON r2.material_id = mi.id AND r2.rn = 2
            WHERE minv.quantity > 0
              AND mi.id NOT IN (SELECT material_id FROM mi_discontinued_materials)";

    $rows = db_fetch_array($sql) ?: [];
    $threshold_t = strtotime("-$m months");

    $out = [];
    foreach ($rows as $r) {
        $qt       = (float) $r['qt'];
        $last_qty = $r['last_qty'] !== null ? (float) $r['last_qty'] : null;
        $last     = $r['last_import'];
        $prev     = $r['prev_import'];

        $k = $last_qty !== null ? ($qt - $last_qty) : null;
        $considered = ($k !== null && $k > 0 && $prev) ? $prev : $last;
        $considered_t = $considered ? strtotime($considered) : 0;

        if ($considered_t !== 0 && $considered_t >= $threshold_t) continue;

        $out[] = [
            'material_id'    => (int) $r['material_id'],
            'material_name'  => $r['material_name'],
            'supplier_name'  => $r['supplier_name'],
            'unit'           => trim((string) $r['unit']),
            'classification' => $r['classification'],
            'quantity'       => $qt,
            'inventory_text' => rp_mi_format_inventory($qt, $r['unit']),
            'last_used'      => $considered,
            'last_used_text' => $considered_t ? date('d/m/Y', $considered_t) : 'Chưa từng nhập kho',
            'days_ago'       => $considered_t ? (int) floor((time() - $considered_t) / 86400) : null,
        ];
    }

    usort($out, function ($a, $b) {
        if (($a['last_used'] === null) !== ($b['last_used'] === null)) {
            return $a['last_used'] === null ? -1 : 1;
        }
        $cmp = strcmp((string) $a['last_used'], (string) $b['last_used']);
        if ($cmp !== 0) return $cmp;
        return strcmp($a['material_name'], $b['material_name']);
    });

    return $out;
}

/* ---- Thẻ "Ngưng dùng" cho NVL (ẩn khỏi nhóm "đã lâu chưa dùng") ---- */

function rp_mi_ensure_discontinued_table()
{
    static $done = false;
    if ($done) return;
    $done = true;
    db_query("CREATE TABLE IF NOT EXISTS mi_discontinued_materials (
        material_id INT NOT NULL PRIMARY KEY,
        created_at  TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci");
}

/** Gắn/bỏ thẻ "Ngưng dùng" cho 1 NVL. */
function rp_mi_set_discontinued($material_id, $on)
{
    rp_mi_ensure_discontinued_table();
    $mid = (int) $material_id;
    if ($mid <= 0) return false;
    if ($on) {
        if (db_num_rows("SELECT 1 FROM mi_discontinued_materials WHERE material_id = $mid") === 0) {
            db_insert('mi_discontinued_materials', ['material_id' => $mid]);
        }
    } else {
        db_delete('mi_discontinued_materials', "material_id = $mid");
    }
    return true;
}

/** Danh sách NVL đang "Ngưng dùng" (kèm tồn + lần xuất dùng gần nhất). */
function rp_mi_discontinued_list()
{
    rp_mi_ensure_discontinued_table();
    $sql = "SELECT mi.id AS material_id, mi.material_name,
                   COALESCE(NULLIF(mi.unit, ''), '') AS unit,
                   COALESCE(NULLIF(mi.classification, ''), 'Chưa phân loại') AS classification,
                   COALESCE(NULLIF(s.supplier_name, ''), 'Chưa có nhà cung cấp') AS supplier_name,
                   COALESCE(minv.quantity, 0) AS qt
            FROM mi_discontinued_materials d
            INNER JOIN material_information mi ON mi.id = d.material_id
            LEFT JOIN suppliers s ON s.id = mi.supplier_id
            LEFT JOIN material_inventory minv ON minv.material_id = mi.id
            ORDER BY mi.material_name ASC";
    $rows = db_fetch_array($sql) ?: [];
    $out = [];
    foreach ($rows as $r) {
        $out[] = [
            'material_id'    => (int) $r['material_id'],
            'material_name'  => $r['material_name'],
            'supplier_name'  => $r['supplier_name'],
            'unit'           => trim((string) $r['unit']),
            'classification' => $r['classification'],
            'quantity'       => (float) $r['qt'],
            'inventory_text' => rp_mi_format_inventory((float) $r['qt'], $r['unit']),
        ];
    }
    return $out;
}

/* ============================================================
 *  XÓA VĨNH VIỄN sản phẩm / NVL khỏi danh mục + dữ liệu cấu hình
 *  liên quan. GIỮ LẠI dữ liệu nhập/xuất (stock_imports, stock_exports,
 *  production_receipts, raw_material_production_issue_data, ...).
 * ============================================================ */

function rp_table_exists($table)
{
    $t = escape_string((string) $table);
    return db_num_rows("SELECT 1 FROM information_schema.TABLES
                        WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = '$t'") > 0;
}

/** Xóa 1 sản phẩm + dữ liệu cấu hình liên quan (giữ lại nhập/xuất). */
function rp_fgi_delete_product($product_id)
{
    $pid = (int) $product_id;
    if ($pid <= 0) return false;

    // Công thức mẻ: xóa items theo batch trước (khác bảng nên IN subquery hợp lệ).
    if (rp_table_exists('product_batch_recipe_items') && rp_table_exists('product_batch_recipes')) {
        db_query("DELETE FROM product_batch_recipe_items
                  WHERE batch_id IN (SELECT id FROM product_batch_recipes WHERE product_id = $pid)");
    }
    $tables = [
        'finished_goods_inventory', 'bill_of_materials', 'branch_product_selling_prices',
        'fgi_discontinued_products', 'long_term_production_backup', 'long_term_production_plans',
        'om_slip_display_order', 'outer_packaging_specifications', 'pre_production_notes',
        'pricing_policies', 'production_plans', 'product_batch_recipes', 'product_files',
        'product_info_basic', 'product_materials', 'product_prices', 'product_purchase_prices',
        'product_recipe_notes', 'product_weights', 'purchase_price_changes', 'sf_product_display_order',
    ];
    foreach ($tables as $t) { if (rp_table_exists($t)) db_delete($t, "product_id = $pid"); }
    db_delete('products', "id = $pid");
    return true;
}

/** Xóa 1 NVL + dữ liệu cấu hình liên quan (giữ lại nhập/xuất). */
function rp_mi_delete_material($material_id)
{
    $mid = (int) $material_id;
    if ($mid <= 0) return false;

    $tables = [
        'material_inventory', 'bill_of_materials', 'branch_material_selling_prices',
        'material_images', 'material_purchase_prices', 'material_supplier_map',
        'mi_discontinued_materials', 'mi_material_display_order', 'product_batch_recipe_items',
        'product_materials', 'purchase_price_changes',
    ];
    foreach ($tables as $t) { if (rp_table_exists($t)) db_delete($t, "material_id = $mid"); }
    db_delete('material_information', "id = $mid");
    return true;
}

/* ============================================================
 *  TAB: stock_at_point (Tồn tại một thời điểm)
 *  Phương pháp "trừ ngược từ tồn hiện tại":
 *     tồn(T) = tồn_hiện_tại
 *              − Σ stock_imports(created_at trong khoảng "sau T")
 *              + Σ stock_exports(created_at trong khoảng "sau T")
 *  Tức hoàn tác mọi chuyển động xảy ra SAU mốc T.
 *  - Đầu ngày D : T = ngay trước 00:00:00 ngày D → hoàn tác created_at >= 'D 00:00:00'
 *                 (KHÔNG tính phát sinh trong ngày D).
 *  - Cuối ngày D: T = 23:59:59 ngày D → hoàn tác created_at > 'D 23:59:59'
 *                 (TÍNH luôn phát sinh trong ngày D).
 * ============================================================ */

/** Chuẩn hoá 'Y-m-d'; rỗng/không hợp lệ → hôm nay. */
function rp_sap_sanitize_date($date)
{
    $d = trim((string) $date);
    if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $d)) {
        $t = strtotime($d);
        if ($t) return date('Y-m-d', $t);
    }
    return date('Y-m-d');
}

/**
 * Điều kiện SQL chọn các dòng ledger cần HOÀN TÁC (phát sinh sau mốc T).
 * $mode: 'start' (đầu ngày) | 'end' (cuối ngày).
 */
function rp_sap_undo_cond($date_ymd, $mode)
{
    if ($mode === 'start') {
        $t = escape_string($date_ymd . ' 00:00:00');
        return "created_at >= '$t'";
    }
    $t = escape_string($date_ymd . ' 23:59:59');
    return "created_at > '$t'";
}

/** Tồn thành phẩm tại thời điểm. Trả [{product_id, name, category, unit, quantity}]. */
function rp_sap_products_at($date_ymd, $mode)
{
    $date_ymd = rp_sap_sanitize_date($date_ymd);
    $mode     = ($mode === 'start') ? 'start' : 'end';
    $undo     = rp_sap_undo_cond($date_ymd, $mode);

    $sql = "SELECT p.id AS product_id, p.product_name,
                   COALESCE(NULLIF(p.unit, ''), '') AS unit,
                   COALESCE(pc.category_name, 'Chưa phân loại') AS category_name,
                   (fgi.quantity - COALESCE(imp.q, 0) + COALESCE(exp.q, 0)) AS qty_at
            FROM finished_goods_inventory fgi
            INNER JOIN products p ON p.id = fgi.product_id
            LEFT JOIN product_categories pc ON pc.id = p.category_id
            LEFT JOIN (SELECT product_id, SUM(quantity) q FROM stock_imports
                       WHERE product_id IS NOT NULL AND $undo
                       GROUP BY product_id) imp ON imp.product_id = p.id
            LEFT JOIN (SELECT product_id, SUM(quantity) q FROM stock_exports
                       WHERE product_id IS NOT NULL AND $undo
                       GROUP BY product_id) exp ON exp.product_id = p.id
            ORDER BY category_name ASC, p.product_name ASC";

    $rows = db_fetch_array($sql) ?: [];
    $out = [];
    foreach ($rows as $r) {
        $out[] = [
            'product_id' => (int) $r['product_id'],
            'name'       => $r['product_name'],
            'category'   => $r['category_name'],
            'unit'       => $r['unit'],
            'quantity'   => (float) $r['qty_at'],
        ];
    }
    return $out;
}

/** Tồn NVL tại thời điểm. Trả [{material_id, name, supplier, unit, quantity}]. */
function rp_sap_materials_at($date_ymd, $mode)
{
    $date_ymd = rp_sap_sanitize_date($date_ymd);
    $mode     = ($mode === 'start') ? 'start' : 'end';
    $undo     = rp_sap_undo_cond($date_ymd, $mode);

    $sql = "SELECT mi.id AS material_id, mi.material_name,
                   COALESCE(NULLIF(mi.unit, ''), '') AS unit,
                   COALESCE(s.supplier_name, 'Chưa có nhà cung cấp') AS supplier_name,
                   (minv.quantity - COALESCE(imp.q, 0) + COALESCE(exp.q, 0)) AS qty_at
            FROM material_inventory minv
            INNER JOIN material_information mi ON mi.id = minv.material_id
            LEFT JOIN suppliers s ON s.id = mi.supplier_id
            LEFT JOIN (SELECT material_id, SUM(quantity) q FROM stock_imports
                       WHERE material_id IS NOT NULL AND $undo
                       GROUP BY material_id) imp ON imp.material_id = mi.id
            LEFT JOIN (SELECT material_id, SUM(quantity) q FROM stock_exports
                       WHERE material_id IS NOT NULL AND $undo
                       GROUP BY material_id) exp ON exp.material_id = mi.id
            ORDER BY supplier_name ASC, mi.material_name ASC";

    $rows = db_fetch_array($sql) ?: [];
    $out = [];
    foreach ($rows as $r) {
        $out[] = [
            'material_id' => (int) $r['material_id'],
            'name'        => $r['material_name'],
            'supplier'    => $r['supplier_name'],
            'unit'        => $r['unit'],
            'quantity'    => (float) $r['qty_at'],
        ];
    }
    return $out;
}

/* ============================================================
 *  PHÂN TÍCH 1 SẢN PHẨM (modal trong finished_goods_inventory)
 *  - Tồn kho hiện tại            : finished_goods_inventory
 *  - Ngày sản xuất gần đây + SL  : finished_product_production_data
 *  - Xuất kho 1/3/6 tháng        : sales_inventory_issue_data
 *    (mốc = NOW() − 30 / 90 / 180 ngày)
 * ============================================================ */
function rp_fgi_product_analysis($product_id)
{
    $pid = (int) $product_id;
    if ($pid <= 0) return null;
    rp_ensure_product_common_name_column();

    // Thông tin SP + tồn hiện tại (quy đổi quy cách bao bì ngoài).
    $info = db_fetch_row(
        "SELECT COALESCE(NULLIF(p.common_product_name, ''), p.product_name) AS product_name,
                COALESCE(NULLIF(p.unit, ''), '') AS unit,
                COALESCE(pc.category_name, 'Chưa phân loại') AS category_name,
                COALESCE(fgi.quantity, 0) AS qt_fgi,
                ops.outer_packaging_short_name, ops.quantity AS qt_ops
         FROM products p
         LEFT JOIN product_categories pc ON pc.id = p.category_id
         LEFT JOIN finished_goods_inventory fgi ON fgi.product_id = p.id
         LEFT JOIN outer_packaging_specifications ops ON ops.product_id = p.id
         WHERE p.id = $pid LIMIT 1"
    );
    if (!$info) return null;

    $qt_fgi = (int) $info['qt_fgi'];
    $qt_ops = $info['qt_ops'] !== null ? (int) $info['qt_ops'] : 0;
    $unit   = trim((string) $info['unit']);

    // Lần sản xuất gần nhất + số lượng mẻ đó.
    $last = db_fetch_row(
        "SELECT quantity, created_at FROM finished_product_production_data
         WHERE product_id = $pid ORDER BY created_at DESC, id DESC LIMIT 1"
    );
    $last_t   = $last && $last['created_at'] ? strtotime($last['created_at']) : 0;
    $last_qty = $last ? (float) $last['quantity'] : 0;

    // Xuất kho theo mốc thời gian (đơn giản hoá: NOW − N ngày).
    $issued = function ($days) use ($pid) {
        $d = (int) $days;
        $row = db_fetch_row(
            "SELECT COALESCE(SUM(quantity), 0) AS s FROM sales_inventory_issue_data
             WHERE product_id = $pid AND created_at >= (NOW() - INTERVAL $d DAY)"
        );
        return (float) ($row['s'] ?? 0);
    };
    $iss1 = $issued(30);
    $iss3 = $issued(90);
    $iss6 = $issued(180);

    $fmt = function ($q) use ($unit) {
        $q_text = ($q == (int) $q) ? (string) (int) $q
            : rtrim(rtrim(number_format($q, 2, '.', ''), '0'), '.');
        return $q_text . ($unit !== '' ? ' ' . $unit : '');
    };

    $opsName = (string) $info['outer_packaging_short_name'];
    $pack = function ($q) use ($qt_ops, $opsName, $unit) { return rp_fgi_format_pack_conv((int) round($q), $qt_ops, $opsName, $unit); };

    return [
        'product_id'      => $pid,
        'product_name'    => (string) $info['product_name'],
        'category_name'   => (string) $info['category_name'],
        'unit'            => $unit,
        'stock_qty'       => $qt_fgi,
        'stock_text'      => rp_fgi_format_pack_conv($qt_fgi, $qt_ops, $opsName, $unit),
        'stock_text_raw'  => $fmt($qt_fgi),
        'last_prod_date'  => $last_t ? date('d/m/Y', $last_t) : '—',
        'last_prod_qty'   => $last ? $fmt($last_qty) : '—',
        'issue_1m'        => $fmt($iss1),
        'issue_3m'        => $fmt($iss3),
        'issue_6m'        => $fmt($iss6),
        'issue_1m_pack'   => $pack($iss1),
        'issue_3m_pack'   => $pack($iss3),
        'issue_6m_pack'   => $pack($iss6),
        'issue_1m_raw'    => $iss1,
        'issue_3m_raw'    => $iss3,
        'issue_6m_raw'    => $iss6,
    ];
}

/** Đảm bảo cột products.common_product_name tồn tại (idempotent — xem [[product-common-name]]). */
function rp_ensure_product_common_name_column()
{
    static $done = false;
    if ($done) return;
    $done = true;
    $col = db_fetch_row("SHOW COLUMNS FROM products LIKE 'common_product_name'");
    if (!$col) {
        db_query("ALTER TABLE products ADD COLUMN common_product_name VARCHAR(255) NULL AFTER product_name");
    }
}

/** Lưu "Tên thường gọi" của 1 sản phẩm (sửa tại chỗ trong modal phân tích sản phẩm). */
function rp_fgi_save_product_common_name($product_id, $value)
{
    rp_ensure_product_common_name_column();
    $pid = (int) $product_id;
    if ($pid <= 0) return false;
    $v = trim((string) $value);
    return db_update('products', ['common_product_name' => ($v !== '' ? $v : null)], "id = {$pid}");
}

/* ============================================================
 *  PHÂN TÍCH 1 NVL (modal trong material_inventory)
 *  - Tồn kho hiện tại         : material_inventory
 *  - Ngày mua gần đây + SL    : raw_material_purchase_data
 *  - Dùng 1/3/6 tháng         : raw_material_production_issue_data
 * ============================================================ */
function rp_mi_material_analysis($material_id)
{
    $mid = (int) $material_id;
    if ($mid <= 0) return null;

    $info = db_fetch_row(
        "SELECT mi.material_name,
                COALESCE(NULLIF(mi.unit, ''), '') AS unit,
                COALESCE(NULLIF(mi.classification, ''), 'Chưa phân loại') AS classification,
                COALESCE(NULLIF(s.supplier_name, ''), 'Chưa có nhà cung cấp') AS supplier_name,
                COALESCE(minv.quantity, 0) AS qt
         FROM material_information mi
         LEFT JOIN suppliers s ON s.id = mi.supplier_id
         LEFT JOIN material_inventory minv ON minv.material_id = mi.id
         WHERE mi.id = $mid LIMIT 1"
    );
    if (!$info) return null;

    $unit = trim((string) $info['unit']);

    // Lần mua gần nhất + số lượng.
    $last = db_fetch_row(
        "SELECT quantity, created_at FROM raw_material_purchase_data
         WHERE material_id = $mid ORDER BY created_at DESC, id DESC LIMIT 1"
    );
    $last_t   = $last && $last['created_at'] ? strtotime($last['created_at']) : 0;
    $last_qty = $last ? (float) $last['quantity'] : 0;

    // Dùng (xuất sản xuất) theo mốc thời gian.
    $used = function ($days) use ($mid) {
        $d = (int) $days;
        $row = db_fetch_row(
            "SELECT COALESCE(SUM(quantity), 0) AS s FROM raw_material_production_issue_data
             WHERE material_id = $mid AND created_at >= (NOW() - INTERVAL $d DAY)"
        );
        return (float) ($row['s'] ?? 0);
    };

    return [
        'material_id'    => $mid,
        'material_name'  => (string) $info['material_name'],
        'classification' => (string) $info['classification'],
        'supplier_name'  => (string) $info['supplier_name'],
        'unit'           => $unit,
        'stock_text'     => rp_mi_format_inventory((float) $info['qt'], $unit),
        'last_buy_date'  => $last_t ? date('d/m/Y', $last_t) : '—',
        'last_buy_qty'   => $last ? rp_mi_format_inventory($last_qty, $unit) : '—',
        'use_1m'         => rp_mi_format_inventory($used(30), $unit),
        'use_3m'         => rp_mi_format_inventory($used(90), $unit),
        'use_6m'         => rp_mi_format_inventory($used(180), $unit),
    ];
}

/* =====================================================================
 *  SỰ KIỆN NHÀ MÁY (factory_events) — báo cáo dạng lịch, chỉ đọc.
 *  Prefix hàm: rp_fev_*.
 * =====================================================================*/

function rp_fev_ensure_view_registered()
{
    if (db_num_rows("SHOW TABLES LIKE 'tbl_views'") <= 0) return;
    db_query("INSERT INTO tbl_views (module, controller, action, label, group_label, sort)
              VALUES ('report','report','factory_events','Sự kiện nhà máy','BÁO CÁO', 63)
              ON DUPLICATE KEY UPDATE group_label = VALUES(group_label), sort = VALUES(sort)");
}

/** suppliers.short_name có thể chưa tồn tại nếu admin_factory chưa từng chạy trong phiên này. */
function rp_fev_ensure_supplier_short_name_column()
{
    static $done = false;
    if ($done) return;
    $done = true;
    $existed = db_num_rows("SHOW COLUMNS FROM suppliers LIKE 'short_name'") > 0;
    db_query("ALTER TABLE suppliers ADD COLUMN IF NOT EXISTS short_name VARCHAR(100) DEFAULT NULL");
    if (!$existed) {
        db_query("UPDATE suppliers SET short_name = supplier_name WHERE short_name IS NULL OR short_name = ''");
    }
}

/** Chuẩn hóa mã màu HEX về '#rrggbb'; rỗng/không hợp lệ -> đen. */
function rp_fev_normalize_hex_color($value)
{
    $v = strtolower(trim((string) $value));
    if (preg_match('/^#?([0-9a-f]{6})$/', $v, $m)) return '#' . $m[1];
    return '#000000';
}

/** 155000000 -> "155 triệu" ; 9580000 -> "9.58 triệu" ; 132500000 -> "132.5 triệu". */
function rp_fev_format_million($value)
{
    $m = round(((float) $value) / 1000000, 2);
    $s = rtrim(rtrim(number_format($m, 2, '.', ''), '0'), '.');
    if ($s === '' || $s === '-') $s = '0';
    return $s . ' triệu';
}

/** Số lượng gọn: bỏ phần thập phân dư (5000.00 -> "5000", 12.50 -> "12.5"). */
function rp_fev_format_qty($value)
{
    $v = (float) $value;
    $s = rtrim(rtrim(number_format($v, 2, '.', ''), '0'), '.');
    return $s === '' || $s === '-' ? '0' : $s;
}

function rp_fev_ids_csv($ids)
{
    $ids = array_values(array_unique(array_filter(array_map('intval', (array) $ids), function ($v) { return $v > 0; })));
    return $ids ? implode(',', $ids) : '';
}

/* ---------------- Tìm kiếm gợi ý (autocomplete) ---------------- */

function rp_fev_search_suppliers($keyword)
{
    rp_fev_ensure_supplier_short_name_column();
    $kw = trim((string) $keyword);
    if ($kw === '') return [];
    $k = escape_string($kw);
    $rows = db_fetch_array(
        "SELECT id, supplier_name, short_name FROM suppliers
         WHERE supplier_name LIKE '%$k%' OR short_name LIKE '%$k%'
         ORDER BY supplier_name ASC LIMIT 20"
    ) ?: [];
    $out = [];
    foreach ($rows as $r) {
        $label = trim((string) $r['short_name']) !== '' ? $r['short_name'] : $r['supplier_name'];
        $out[] = ['id' => (int) $r['id'], 'label' => (string) $label];
    }
    return $out;
}

function rp_fev_search_materials($keyword)
{
    $kw = trim((string) $keyword);
    if ($kw === '') return [];
    $k = escape_string($kw);
    $rows = db_fetch_array(
        "SELECT id, material_name, common_material_name FROM material_information
         WHERE material_name LIKE '%$k%' OR common_material_name LIKE '%$k%'
         ORDER BY material_name ASC LIMIT 20"
    ) ?: [];
    $out = [];
    foreach ($rows as $r) {
        $label = trim((string) $r['common_material_name']) !== '' ? $r['common_material_name'] : $r['material_name'];
        $out[] = ['id' => (int) $r['id'], 'label' => (string) $label];
    }
    return $out;
}

function rp_fev_search_customers($keyword)
{
    $kw = trim((string) $keyword);
    if ($kw === '') return [];
    $k = escape_string($kw);
    $rows = db_fetch_array(
        "SELECT id, name, short_name, secondary_color FROM customers
         WHERE name LIKE '%$k%' OR short_name LIKE '%$k%'
         ORDER BY name ASC LIMIT 20"
    ) ?: [];
    $out = [];
    foreach ($rows as $r) {
        $label = trim((string) $r['short_name']) !== '' ? $r['short_name'] : $r['name'];
        $out[] = ['id' => (int) $r['id'], 'label' => (string) $label, 'color' => (string) ($r['secondary_color'] ?? '')];
    }
    return $out;
}

function rp_fev_search_products($keyword)
{
    $kw = trim((string) $keyword);
    if ($kw === '') return [];
    $k = escape_string($kw);
    $rows = db_fetch_array(
        "SELECT id, product_name, common_product_name FROM products
         WHERE product_name LIKE '%$k%' OR common_product_name LIKE '%$k%'
         ORDER BY product_name ASC LIMIT 20"
    ) ?: [];
    $out = [];
    foreach ($rows as $r) {
        $label = trim((string) $r['common_product_name']) !== '' ? $r['common_product_name'] : $r['product_name'];
        $out[] = ['id' => (int) $r['id'], 'label' => (string) $label];
    }
    return $out;
}

/** "Nhập kho - Hàng hóa": gợi ý cả NVL lẫn sản phẩm thương mại. id trả về dạng "material:ID"/"product:ID". */
function rp_fev_search_goods($keyword)
{
    $kw = trim((string) $keyword);
    if ($kw === '') return [];
    $k = escape_string($kw);
    $out = [];
    $mats = db_fetch_array(
        "SELECT id, material_name, common_material_name FROM material_information
         WHERE material_name LIKE '%$k%' OR common_material_name LIKE '%$k%'
         ORDER BY material_name ASC LIMIT 15"
    ) ?: [];
    foreach ($mats as $r) {
        $label = trim((string) $r['common_material_name']) !== '' ? $r['common_material_name'] : $r['material_name'];
        $out[] = ['id' => 'material:' . (int) $r['id'], 'label' => (string) $label];
    }
    $prods = db_fetch_array(
        "SELECT id, product_name, common_product_name FROM products
         WHERE product_name LIKE '%$k%' OR common_product_name LIKE '%$k%'
         ORDER BY product_name ASC LIMIT 15"
    ) ?: [];
    foreach ($prods as $r) {
        $label = trim((string) $r['common_product_name']) !== '' ? $r['common_product_name'] : $r['product_name'];
        $out[] = ['id' => 'product:' . (int) $r['id'], 'label' => (string) $label];
    }
    return $out;
}

/** Tách mảng id dạng "material:ID"/"product:ID" thành 2 csv số nguyên: [material_csv, product_csv]. */
function rp_fev_split_goods_ids($ids)
{
    $mat = [];
    $prod = [];
    foreach ((array) $ids as $id) {
        if (preg_match('/^material:(\d+)$/', (string) $id, $m)) $mat[] = (int) $m[1];
        elseif (preg_match('/^product:(\d+)$/', (string) $id, $m)) $prod[] = (int) $m[1];
    }
    return [rp_fev_ids_csv($mat), rp_fev_ids_csv($prod)];
}

/* ---------------- Truy vấn sự kiện theo từng loại ---------------- */
/* Mỗi hàm trả về mảng các dòng: ['dt' => datetime, 'date' => 'Y-m-d', 'dot' => '#hex',
   'text' => string, 'text_html' => string, 'chip_bg' => '#hex'|null, 'click' => array|null]. */

function rp_fev_nk_supplier_events($from, $to, $ids)
{
    $csv = rp_fev_ids_csv($ids);
    if ($csv === '') return [];
    rp_fev_ensure_supplier_short_name_column();
    $rows = db_fetch_array(
        "SELECT i.id, i.supplier_id, i.inventory_value, i.created_at,
                s.supplier_name, s.short_name
         FROM stock_import_invoices i
         INNER JOIN suppliers s ON s.id = i.supplier_id
         WHERE i.supplier_id IN ($csv) AND i.created_at >= '$from 00:00:00' AND i.created_at <= '$to 23:59:59'
         ORDER BY i.created_at ASC"
    ) ?: [];
    $out = [];
    foreach ($rows as $r) {
        $name = trim((string) $r['short_name']) !== '' ? $r['short_name'] : $r['supplier_name'];
        $valText = rp_fev_format_million($r['inventory_value']);
        $out[] = [
            'dt'        => $r['created_at'],
            'date'      => date('Y-m-d', strtotime($r['created_at'])),
            'dot'       => '#16a34a',
            'chip_bg'   => '#dcfce7',
            'text'      => 'NK ' . $name . ' ' . $valText,
            'text_html' => 'NK ' . htmlspecialchars($name, ENT_QUOTES, 'UTF-8')
                            . ' <strong class="fev-amt" style="color:#16a34a">' . htmlspecialchars($valText, ENT_QUOTES, 'UTF-8') . '</strong>',
            'click'     => ['kind' => 'invoices_by_supplier_date', 'supplier_id' => (int) $r['supplier_id'], 'date' => date('Y-m-d', strtotime($r['created_at']))],
        ];
    }
    return $out;
}

/** "Nhập kho - Hàng hóa": gộp raw_material_purchase_data (NVL) + purchased_finished_product_data (sản phẩm thương mại). */
function rp_fev_nk_goods_events($from, $to, $ids)
{
    list($matCsv, $prodCsv) = rp_fev_split_goods_ids($ids);
    $out = [];

    if ($matCsv !== '') {
        $rows = db_fetch_array(
            "SELECT d.id, d.material_id, d.supplier_id, d.quantity, d.created_at,
                    m.material_name, m.common_material_name, m.unit
             FROM raw_material_purchase_data d
             INNER JOIN material_information m ON m.id = d.material_id
             WHERE d.material_id IN ($matCsv) AND d.created_at >= '$from 00:00:00' AND d.created_at <= '$to 23:59:59'
             ORDER BY d.created_at ASC"
        ) ?: [];
        foreach ($rows as $r) {
            $name = trim((string) $r['common_material_name']) !== '' ? $r['common_material_name'] : $r['material_name'];
            $qty  = rp_fev_format_qty($r['quantity']) . trim((string) $r['unit']);
            $out[] = [
                'dt'        => $r['created_at'],
                'date'      => date('Y-m-d', strtotime($r['created_at'])),
                'dot'       => '#16a34a',
                'chip_bg'   => '#dcfce7',
                'text'      => 'NK ' . $name . ' ' . $qty,
                'text_html' => 'NK ' . htmlspecialchars($name, ENT_QUOTES, 'UTF-8')
                                . ' <strong class="fev-amt" style="color:#16a34a">' . htmlspecialchars($qty, ENT_QUOTES, 'UTF-8') . '</strong>',
                'click'     => ['kind' => 'invoices_by_supplier_date', 'supplier_id' => (int) $r['supplier_id'], 'date' => date('Y-m-d', strtotime($r['created_at']))],
            ];
        }
    }

    if ($prodCsv !== '') {
        $rows = db_fetch_array(
            "SELECT d.id, d.product_id, d.supplier_id, d.quantity, d.created_at,
                    p.product_name, p.common_product_name, p.unit
             FROM purchased_finished_product_data d
             INNER JOIN products p ON p.id = d.product_id
             WHERE d.product_id IN ($prodCsv) AND d.created_at >= '$from 00:00:00' AND d.created_at <= '$to 23:59:59'
             ORDER BY d.created_at ASC"
        ) ?: [];
        foreach ($rows as $r) {
            $name = trim((string) $r['common_product_name']) !== '' ? $r['common_product_name'] : $r['product_name'];
            $qty  = rp_fev_format_qty($r['quantity']) . trim((string) $r['unit']);
            $out[] = [
                'dt'        => $r['created_at'],
                'date'      => date('Y-m-d', strtotime($r['created_at'])),
                'dot'       => '#16a34a',
                'chip_bg'   => '#dcfce7',
                'text'      => 'NK ' . $name . ' ' . $qty,
                'text_html' => 'NK ' . htmlspecialchars($name, ENT_QUOTES, 'UTF-8')
                                . ' <strong class="fev-amt" style="color:#16a34a">' . htmlspecialchars($qty, ENT_QUOTES, 'UTF-8') . '</strong>',
                'click'     => ['kind' => 'invoices_by_supplier_date', 'supplier_id' => (int) $r['supplier_id'], 'date' => date('Y-m-d', strtotime($r['created_at']))],
            ];
        }
    }

    return $out;
}

function rp_fev_tt_supplier_events($from, $to, $ids)
{
    $csv = rp_fev_ids_csv($ids);
    if ($csv === '') return [];
    rp_fev_ensure_supplier_short_name_column();
    $rows = db_fetch_array(
        "SELECT p.id, p.supplier_id, p.amount, p.created_at,
                s.supplier_name, s.short_name
         FROM supplier_payments p
         INNER JOIN suppliers s ON s.id = p.supplier_id
         WHERE p.supplier_id IN ($csv) AND p.created_at >= '$from 00:00:00' AND p.created_at <= '$to 23:59:59'
         ORDER BY p.created_at ASC"
    ) ?: [];
    $out = [];
    foreach ($rows as $r) {
        $name = trim((string) $r['short_name']) !== '' ? $r['short_name'] : $r['supplier_name'];
        $valText = rp_fev_format_million($r['amount']);
        $out[] = [
            'dt'        => $r['created_at'],
            'date'      => date('Y-m-d', strtotime($r['created_at'])),
            'dot'       => '#d97706',
            'chip_bg'   => '#fef3c7',
            'text'      => 'TT ' . $name . ' ' . $valText,
            'text_html' => 'TT ' . htmlspecialchars($name, ENT_QUOTES, 'UTF-8')
                            . ' <strong class="fev-amt" style="color:#d97706">' . htmlspecialchars($valText, ENT_QUOTES, 'UTF-8') . '</strong>',
            'click'     => ['kind' => 'payment_attachments', 'payment_id' => (int) $r['id']],
        ];
    }
    return $out;
}

/** "Xuất kho - Khách hàng": gộp sales_orders (lịch sử) + sales_warehouse_export_invoices (phiếu xuất bán mới). */
function rp_fev_xk_customer_events($from, $to, $ids)
{
    $csv = rp_fev_ids_csv($ids);
    if ($csv === '') return [];

    $rows1 = db_fetch_array(
        "SELECT o.id, o.customer_id, o.value, o.created_at,
                c.name, c.short_name, c.secondary_color
         FROM sales_orders o
         INNER JOIN customers c ON c.id = o.customer_id
         WHERE o.customer_id IN ($csv) AND o.created_at >= '$from 00:00:00' AND o.created_at <= '$to 23:59:59'
         ORDER BY o.created_at ASC"
    ) ?: [];
    $rows2 = db_fetch_array(
        "SELECT s.id, s.customer_id, (s.goods_value * 1000000) AS value, s.created_at,
                c.name, c.short_name, c.secondary_color
         FROM sales_warehouse_export_invoices s
         INNER JOIN customers c ON c.id = s.customer_id
         WHERE s.customer_id IN ($csv) AND s.created_at >= '$from 00:00:00' AND s.created_at <= '$to 23:59:59'
         ORDER BY s.created_at ASC"
    ) ?: [];

    $out = [];
    foreach ([['rows' => $rows1, 'kind' => 'sales_invoices'], ['rows' => $rows2, 'kind' => 'sales_export_invoices']] as $branch) {
        foreach ($branch['rows'] as $r) {
            $name = trim((string) $r['short_name']) !== '' ? $r['short_name'] : $r['name'];
            $hasColor = preg_match('/^#[0-9a-f]{6}$/i', (string) ($r['secondary_color'] ?? '')) === 1;
            $color = $hasColor ? rp_fev_normalize_hex_color($r['secondary_color']) : '#000000';
            $valText = rp_fev_format_million($r['value']);
            $out[] = [
                'dt'        => $r['created_at'],
                'date'      => date('Y-m-d', strtotime($r['created_at'])),
                'dot'       => '#dc2626',
                'text'      => 'XK ' . $name . ' ' . $valText,
                'text_html' => 'XK <span style="color:' . $color . '">' . htmlspecialchars($name, ENT_QUOTES, 'UTF-8') . '</span> '
                                . htmlspecialchars($valText, ENT_QUOTES, 'UTF-8'),
                'click'     => ['kind' => $branch['kind'], 'sales_order_id' => (int) $r['id']],
            ];
        }
    }
    return $out;
}

function rp_fev_sx_product_events($from, $to, $ids)
{
    $csv = rp_fev_ids_csv($ids);
    if ($csv === '') return [];
    $rows = db_fetch_array(
        "SELECT d.id, d.product_id, d.quantity, d.created_at,
                p.product_name, p.common_product_name
         FROM finished_product_production_data d
         INNER JOIN products p ON p.id = d.product_id
         WHERE d.product_id IN ($csv) AND d.created_at >= '$from 00:00:00' AND d.created_at <= '$to 23:59:59'
         ORDER BY d.created_at ASC"
    ) ?: [];
    $out = [];
    foreach ($rows as $r) {
        $name = trim((string) $r['common_product_name']) !== '' ? $r['common_product_name'] : $r['product_name'];
        $qtyText = rp_fev_format_qty($r['quantity']);
        $out[] = [
            'dt'        => $r['created_at'],
            'date'      => date('Y-m-d', strtotime($r['created_at'])),
            'dot'       => '#16a34a',
            'text'      => 'SX ' . $name . ' ' . $qtyText,
            'text_html' => 'SX ' . htmlspecialchars($name, ENT_QUOTES, 'UTF-8')
                            . ' <strong class="fev-amt" style="color:#16a34a">' . htmlspecialchars($qtyText, ENT_QUOTES, 'UTF-8') . '</strong>',
            'click'     => null,
        ];
    }
    return $out;
}

function rp_fev_xk_product_events($from, $to, $ids)
{
    $csv = rp_fev_ids_csv($ids);
    if ($csv === '') return [];
    $rows = db_fetch_array(
        "SELECT s.id, s.product_id, s.customer_id, s.quantity, s.created_at,
                c.name AS customer_name, c.short_name AS customer_short_name, c.secondary_color
         FROM sales_inventory_issue_data s
         INNER JOIN customers c ON c.id = s.customer_id
         WHERE s.product_id IN ($csv) AND s.created_at >= '$from 00:00:00' AND s.created_at <= '$to 23:59:59'
         ORDER BY s.created_at ASC"
    ) ?: [];
    $out = [];
    foreach ($rows as $r) {
        $cname = trim((string) $r['customer_short_name']) !== '' ? $r['customer_short_name'] : $r['customer_name'];
        $hasColor = preg_match('/^#[0-9a-f]{6}$/i', (string) ($r['secondary_color'] ?? '')) === 1;
        $color = $hasColor ? rp_fev_normalize_hex_color($r['secondary_color']) : '#000000';
        $qtyText = rp_fev_format_qty($r['quantity']);
        $eventDate = date('Y-m-d', strtotime($r['created_at']));
        $out[] = [
            'dt'          => $r['created_at'],
            'date'        => $eventDate,
            'dot'         => '#dc2626',
            'text'        => 'XK ' . $qtyText . ' (' . $cname . ')',
            'text_html'   => 'XK ' . htmlspecialchars($qtyText, ENT_QUOTES, 'UTF-8')
                              . ' (<span style="color:' . $color . '">' . htmlspecialchars($cname, ENT_QUOTES, 'UTF-8') . '</span>)',
            'click'       => ['kind' => 'invoices_by_customer_date', 'customer_id' => (int) $r['customer_id'], 'date' => $eventDate],
        ];
    }
    return $out;
}

/** Ảnh hóa đơn nhập kho của 1 NCC trong 1 ngày (khớp chính xác hoặc suy luận theo NVL/hàng hóa). */
function rp_fev_invoice_images_by_supplier_date($supplier_id, $date)
{
    $sid = (int) $supplier_id;
    $d   = escape_string((string) $date);
    if ($sid <= 0 || $d === '') return [];
    $invoices = db_fetch_array(
        "SELECT id FROM stock_import_invoices WHERE supplier_id = $sid AND DATE(created_at) = '$d'"
    ) ?: [];
    $images = [];
    foreach ($invoices as $inv) {
        $wr_id = wri_wr_id_by_invoice((int) $inv['id']);
        if ($wr_id > 0) {
            foreach (wri_list($wr_id, 'purchase_invoice') as $img) $images[] = $img;
        }
    }
    return $images;
}

/** Ảnh hóa đơn xuất bán của 1 khách hàng trong 1 ngày (suy luận theo NVL export sản phẩm — sales_inventory_issue_data không có FK đơn/hóa đơn). */
function rp_fev_invoice_images_by_customer_date($customer_id, $date)
{
    $cid = (int) $customer_id;
    $d   = escape_string((string) $date);
    if ($cid <= 0 || $d === '') return [];
    $images = [];
    $orders = db_fetch_array(
        "SELECT id FROM sales_orders WHERE customer_id = $cid AND DATE(created_at) = '$d'"
    ) ?: [];
    foreach ($orders as $o) {
        foreach (wri_list((int) $o['id'], 'sales_invoice') as $img) $images[] = $img;
    }
    $exports = db_fetch_array(
        "SELECT id FROM sales_warehouse_export_invoices WHERE customer_id = $cid AND DATE(created_at) = '$d'"
    ) ?: [];
    foreach ($exports as $e) {
        foreach (wri_list((int) $e['id'], 'sales_export_invoice') as $img) $images[] = $img;
    }
    return $images;
}

/** Ảnh đính kèm của 1 phiếu chi NCC (supplier_payment_attachments). */
function rp_fev_payment_attachments($payment_id)
{
    $pid = (int) $payment_id;
    if ($pid <= 0) return [];
    return db_fetch_array(
        "SELECT id, file_url FROM supplier_payment_attachments WHERE payment_id = $pid ORDER BY id ASC"
    ) ?: [];
}

/** Gộp toàn bộ sự kiện theo bộ lọc, nhóm theo ngày, sắp xếp theo thời gian ghi dữ liệu. */
function rp_fev_range($from, $to, array $filters)
{
    $from = escape_string((string) $from);
    $to   = escape_string((string) $to);
    $all  = [];

    if (!empty($filters['nk_supplier'])) $all = array_merge($all, rp_fev_nk_supplier_events($from, $to, $filters['nk_supplier']));
    if (!empty($filters['nk_goods']))    $all = array_merge($all, rp_fev_nk_goods_events($from, $to, $filters['nk_goods']));
    if (!empty($filters['tt_supplier'])) $all = array_merge($all, rp_fev_tt_supplier_events($from, $to, $filters['tt_supplier']));
    if (!empty($filters['xk_customer'])) $all = array_merge($all, rp_fev_xk_customer_events($from, $to, $filters['xk_customer']));
    if (!empty($filters['sx_product']))  $all = array_merge($all, rp_fev_sx_product_events($from, $to, $filters['sx_product']));
    if (!empty($filters['xk_product']))  $all = array_merge($all, rp_fev_xk_product_events($from, $to, $filters['xk_product']));

    $byDate = [];
    foreach ($all as $ev) $byDate[$ev['date']][] = $ev;
    foreach ($byDate as &$list) {
        usort($list, function ($a, $b) { return strcmp($a['dt'], $b['dt']); });
    }
    unset($list);
    return $byDate;
}

/* ============================================================
 *  DASHBOARD: daily_dashboard (BC SẢN XUẤT HẰNG NGÀY) — v2
 *  6 khối cố định (không phụ thuộc time-picker):
 *   1. Sản lượng (5 tháng gần nhất + hiệu quả + điều tiết cung/cầu)
 *   2. Nhập kho theo NCC (tháng hiện tại)
 *   3. Xuất kho theo KH (tháng hiện tại + 7 tháng trước)
 *   4. Sản xuất hôm nay
 *   5. Đặt hàng nguyên liệu đang chờ (NVL + cà phê)
 *   6. Chi nhánh đặt hàng (tái dùng om_get_branch_orders) + Quỹ
 * ============================================================ */

/** Đăng ký menu (nhóm BÁO CÁO) + gỡ menu "BÁO CÁO SẢN XUẤT" cũ. Idempotent. */
function rp_dd_ensure_view_registered()
{
    if (db_num_rows("SHOW TABLES LIKE 'tbl_views'") <= 0) return;
    db_query(
        "INSERT INTO tbl_views (module, controller, action, label, group_label, sort)
         VALUES ('report','report','daily_dashboard','BC sản xuất trong ngày','BÁO CÁO', 63)
         ON DUPLICATE KEY UPDATE label = VALUES(label), group_label = VALUES(group_label), sort = VALUES(sort)"
    );
    db_query("DELETE FROM tbl_views WHERE module = 'report' AND action = 'production_daily'");
}

/** Bảng cấu hình "sản phẩm chủ lực" (tồn tối thiểu) — idempotent. */
function rp_dd_ensure_tables()
{
    static $done = false;
    if ($done) return;
    $done = true;
    db_query("CREATE TABLE IF NOT EXISTS app_settings (
        setting_key   VARCHAR(100) NOT NULL PRIMARY KEY,
        setting_value TEXT DEFAULT NULL
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci");
    db_query("CREATE TABLE IF NOT EXISTS key_product_stock_settings (
        product_id   INT NOT NULL PRIMARY KEY,
        min_quantity DECIMAL(15,3) NOT NULL DEFAULT 0,
        updated_at   TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci");
    db_query("CREATE TABLE IF NOT EXISTS daily_dashboard_month_overrides (
        ym         CHAR(7)       NOT NULL PRIMARY KEY,
        value      DECIMAL(15,2) NOT NULL DEFAULT 0,
        updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci");
    rp_dd_ensure_month_overrides_metric_column();
}

/** Cột phân loại "output"/"export" trong daily_dashboard_month_overrides (idempotent). PK đổi thành (ym, metric). */
function rp_dd_ensure_month_overrides_metric_column()
{
    static $done = false;
    if ($done) return;
    $done = true;
    $col = db_fetch_row("SHOW COLUMNS FROM daily_dashboard_month_overrides LIKE 'metric'");
    if (!$col) {
        db_query("ALTER TABLE daily_dashboard_month_overrides ADD COLUMN metric VARCHAR(20) NOT NULL DEFAULT 'output' AFTER ym");
        db_query("ALTER TABLE daily_dashboard_month_overrides DROP PRIMARY KEY, ADD PRIMARY KEY (ym, metric)");
    }
}

/** Điều kiện SQL "DATE(col) BETWEEN from AND to" (đã escape). */
function rp_dd_range_cond($column, $from, $to)
{
    $f = escape_string($from);
    $t = escape_string($to);
    return "DATE($column) BETWEEN '$f' AND '$t'";
}

/** Đếm số phần tử JSON (order_items/groups) — trả 0 nếu không decode được. */
function rp_dd_json_count($json)
{
    $d = json_decode((string) $json, true);
    return is_array($d) ? count($d) : 0;
}

/** Tên viết tắt tháng tiếng Anh (không phụ thuộc locale hệ điều hành). */
function rp_dd_month_short($m)
{
    static $labels = ['', 'Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun', 'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec'];
    $m = (int) $m;
    return $labels[$m] ?? '';
}

/** "04 jul,26" — ngày rút gọn dùng cho các dòng "gần đây". */
function rp_dd_short_date($datetime)
{
    $ts = strtotime((string) $datetime);
    if (!$ts) return '';
    return strtolower(date('d M,y', $ts));
}

/** In hoa ký tự đầu mỗi từ (tên sản phẩm hiển thị ở khối Sản xuất hôm nay). */
function rp_dd_title_case($s)
{
    $s = trim((string) $s);
    if ($s === '') return $s;
    return mb_convert_case($s, MB_CASE_TITLE, 'UTF-8');
}

/** Class màu bullet cảnh báo giá vốn — cùng ngưỡng với investment_products (inventory_management). */
function rp_dd_margin_dot_class($k)
{
    if ($k === null) return '';
    if ($k < 0.15) return 'dot-red';
    if ($k < 0.25) return 'dot-yellow';
    if ($k <= 1) return '';
    return 'dot-green';
}

/* ============================================================
 *  1) SẢN LƯỢNG — hiệu quả sản xuất + điều tiết cung/cầu
 * ============================================================ */

/** Đọc/lưu ngưỡng "sản lượng tối đa 1 ngày/1 công" (app_settings, key riêng của dashboard). */
function rp_dd_get_max_output_setting()
{
    rp_dd_ensure_tables();
    $row = db_fetch_row("SELECT setting_value FROM app_settings WHERE setting_key = 'daily_dashboard.max_output_per_worker' LIMIT 1");
    if ($row && $row['setting_value'] !== null && $row['setting_value'] !== '') return (float) $row['setting_value'];
    return 150.0;
}

function rp_dd_save_max_output_setting($value)
{
    rp_dd_ensure_tables();
    $v = escape_string((string) max(0, (float) $value));
    $exists = db_num_rows("SELECT 1 FROM app_settings WHERE setting_key = 'daily_dashboard.max_output_per_worker'") > 0;
    if ($exists) db_update('app_settings', ['setting_value' => $v], "setting_key = 'daily_dashboard.max_output_per_worker'");
    else db_insert('app_settings', ['setting_key' => 'daily_dashboard.max_output_per_worker', 'setting_value' => $v]);
    return true;
}

/** Danh sách "sản phẩm chủ lực" đã cấu hình + tồn kho hiện tại (finished_goods_inventory). */
function rp_dd_key_products()
{
    rp_dd_ensure_tables();
    $rows = db_fetch_array(
        "SELECT k.product_id, k.min_quantity,
                COALESCE(NULLIF(p.common_product_name, ''), p.product_name) AS name,
                COALESCE(fgi.quantity, 0) AS stock_qty
         FROM key_product_stock_settings k
         LEFT JOIN products p ON p.id = k.product_id
         LEFT JOIN finished_goods_inventory fgi ON fgi.product_id = k.product_id
         ORDER BY name ASC"
    ) ?: [];
    $out = [];
    foreach ($rows as $r) {
        $out[] = [
            'product_id'   => (int) $r['product_id'],
            'name'         => (string) ($r['name'] ?: ('#' . $r['product_id'])),
            'min_quantity' => (float) $r['min_quantity'],
            'stock_qty'    => (float) $r['stock_qty'],
            'is_below'     => (float) $r['stock_qty'] < (float) $r['min_quantity'],
        ];
    }
    return $out;
}

/** Lưu danh sách sản phẩm chủ lực (upsert theo product_id, xoá dòng bị bỏ khỏi danh sách). */
function rp_dd_save_key_products(array $items)
{
    rp_dd_ensure_tables();
    $ids = [];
    foreach ($items as $it) {
        $pid = (int) ($it['product_id'] ?? 0);
        if ($pid <= 0) continue;
        $min = (float) ($it['min_quantity'] ?? 0);
        $ids[] = $pid;
        $exists = db_num_rows("SELECT 1 FROM key_product_stock_settings WHERE product_id = $pid") > 0;
        if ($exists) db_update('key_product_stock_settings', ['min_quantity' => $min], "product_id = $pid");
        else db_insert('key_product_stock_settings', ['product_id' => $pid, 'min_quantity' => $min]);
    }
    if ($ids) {
        $inList = implode(',', $ids);
        db_query("DELETE FROM key_product_stock_settings WHERE product_id NOT IN ($inList)");
    } else {
        db_query("DELETE FROM key_product_stock_settings");
    }
    return true;
}

/** Đọc toàn bộ giá trị "tạm" (ym => value) đã thiết lập theo $metric ('output'|'export'), ưu tiên hơn dữ liệu tính từ DB. */
function rp_dd_get_month_overrides($metric = 'output')
{
    rp_dd_ensure_tables();
    $m = escape_string((string) $metric);
    $rows = db_fetch_array("SELECT ym, value FROM daily_dashboard_month_overrides WHERE metric = '$m'") ?: [];
    $out = [];
    foreach ($rows as $r) $out[(string) $r['ym']] = (float) $r['value'];
    return $out;
}

/** Lưu/xoá giá trị tạm cho từng tháng theo $metric. $items = [{ym:'2026-07', value:'' | số}]; value rỗng -> xoá override (quay lại tính từ DB). */
function rp_dd_save_month_overrides(array $items, $metric = 'output')
{
    rp_dd_ensure_tables();
    $m = escape_string((string) $metric);
    foreach ($items as $it) {
        $ym = trim((string) ($it['ym'] ?? ''));
        if (!preg_match('/^\d{4}-\d{2}$/', $ym)) continue;
        $raw = $it['value'] ?? '';
        $ymSafe = escape_string($ym);
        if ($raw === '' || $raw === null) {
            db_query("DELETE FROM daily_dashboard_month_overrides WHERE ym = '$ymSafe' AND metric = '$m'");
            continue;
        }
        $v = escape_string((string) (float) $raw);
        $exists = db_num_rows("SELECT 1 FROM daily_dashboard_month_overrides WHERE ym = '$ymSafe' AND metric = '$m'") > 0;
        if ($exists) db_update('daily_dashboard_month_overrides', ['value' => $v], "ym = '$ymSafe' AND metric = '$m'");
        else db_insert('daily_dashboard_month_overrides', ['ym' => $ym, 'metric' => $metric, 'value' => $v]);
    }
    return true;
}

/** Sản lượng của 1 tháng: ưu tiên giá trị tạm đã thiết lập, không có thì tính từ py_calc_output_qty(). */
function rp_dd_output_value_for_month($year, $month, $overrides = null)
{
    $ym = sprintf('%04d-%02d', (int) $year, (int) $month);
    if ($overrides === null) $overrides = rp_dd_get_month_overrides('output');
    if (array_key_exists($ym, $overrides)) return (float) $overrides[$ym];
    return py_calc_output_qty((int) $year, (int) $month);
}

/** 6 tháng gần nhất (không tính tháng hiện tại) + giá trị sản lượng tháng hiện tại. */
function rp_dd_output_series()
{
    $y = (int) date('Y');
    $m = (int) date('n');
    $overrides = rp_dd_get_month_overrides();
    $months = [];
    // 8 tháng trước + tháng hiện tại (2026-07-15: mở rộng từ 6 lên 8 tháng trước theo yêu cầu).
    for ($i = 8; $i >= 1; $i--) {
        $ts = mktime(0, 0, 0, $m - $i, 1, $y);
        $yy = (int) date('Y', $ts);
        $mm = (int) date('n', $ts);
        $months[] = [
            'ym'         => sprintf('%04d-%02d', $yy, $mm),
            'label'      => strtoupper(rp_dd_month_short($mm)),
            // Tháng thuộc năm khác năm hiện tại -> hiện thêm số năm viết gọn (2 số) cạnh tên tháng,
            // vd DEC25/NOV25 — rỗng nếu cùng năm hiện tại (đa số trường hợp).
            'year_short' => $yy !== $y ? substr((string) $yy, -2) : '',
            'value'      => rp_dd_output_value_for_month($yy, $mm, $overrides),
        ];
    }
    $currentYm = sprintf('%04d-%02d', $y, $m);
    return [
        'months'     => $months,
        'current'    => rp_dd_output_value_for_month($y, $m, $overrides),
        'current_ym' => $currentYm,
    ];
}

/** Tổng "công" tháng hiện tại: nhân viên (job_title='Nhân viên') thuộc chi nhánh cấu hình payroll,
 *  mark='x' tính 1 công, mark='half' tính 0.5 công. */
/** Tổng công đã chấm TÍNH ĐẾN HÔM NAY (month-to-date, khớp "Sản lượng tháng" cũng tính đến hiện tại
 *  — xem rp_dd_output_series()) của các nhân viên (role "Nhân viên"). 2026-07-17: SỬA LẠI hoàn toàn —
 *  bản cũ COUNT(mark='x') từ payroll_timesheet_entries luôn ra 0 vì bảng này CHỈ lưu NGOẠI LỆ
 *  (off/half), ngày công bình thường KHÔNG có dòng nào (views timesheet tự hiển thị "x" mặc định
 *  khi không có dòng, không phải đọc từ DB) — nay mirror đúng logic py_calc_attendance() (nguồn đã
 *  đúng, payroll module dùng) nhưng CẮT còn đến hôm nay thay vì cả tháng (py_calc_attendance() vốn
 *  tính cho quyết toán cuối tháng nên lấy trọn 31 ngày, không phù hợp cho work-in-progress). */
function rp_dd_total_cong($year, $month)
{
    if (!function_exists('py_employees_for_branch') || !function_exists('py_normalize_job_title')
        || !function_exists('py_month_days') || !function_exists('py_timesheet_marks')
        || !function_exists('py_calc_effective_mark')) return 0.0;
    $y = (int) $year;
    $m = (int) $month;
    $employees = py_employees_for_branch();
    $ids = [];
    foreach ($employees as $e) {
        if (py_normalize_job_title($e['job_title'] ?? '') === 'nhan_vien') $ids[] = (int) $e['id'];
    }
    if (!$ids) return 0.0;

    $days = array_filter(py_month_days($y, $m), function ($d) { return $d['is_past'] || $d['is_today']; });
    $marksMap = py_timesheet_marks($ids, $y, $m);

    $total = 0.0;
    foreach ($ids as $eid) {
        $empMarks = $marksMap[$eid] ?? [];
        foreach ($days as $day) {
            $stored = $empMarks[$day['date']]['mark'] ?? null;
            $mark = py_calc_effective_mark($day['is_sunday'], $stored);
            if ($mark === 'x') $total += 1;
            elseif ($mark === 'half') $total += 0.5;
        }
    }
    return $total;
}

/** Hiệu quả = round(round(sản_lượng_tháng/tổng_công) / ngưỡng_cài_đặt * 10, 2). */
function rp_dd_efficiency($current_output)
{
    $y = (int) date('Y');
    $m = (int) date('n');
    $total_cong = rp_dd_total_cong($y, $m);
    $avg = $total_cong > 0 ? round($current_output / $total_cong) : 0.0;
    $max = rp_dd_get_max_output_setting();
    $value = $max > 0 ? round($avg / $max * 10, 1) : 0.0;
    return ['avg_per_cong' => $avg, 'total_cong' => $total_cong, 'max_setting' => $max, 'value' => $value];
}

/**
 * Điều tiết cung/cầu:
 *  a = round(sản_xuất_tháng / xuất_kho_tháng * 10, 2)
 *  b = (số SP chủ lực dưới tồn tối thiểu) / (tổng số SP chủ lực đã cấu hình)
 *  cung yếu: a<9 && b>0.5 | vượt cầu: a>9 && b>0.5 | còn lại: ổn định.
 */
function rp_dd_regulation()
{
    $y = (int) date('Y');
    $m = (int) date('n');
    $output = rp_dd_output_value_for_month($y, $m);
    $exportRow = db_fetch_row(
        "SELECT COALESCE(SUM(quantity), 0) AS q FROM sales_warehouse_export_invoices
         WHERE YEAR(created_at) = $y AND MONTH(created_at) = $m"
    );
    $exportQty = $exportRow ? (float) $exportRow['q'] : 0.0;
    $a = $exportQty > 0 ? round($output / $exportQty * 10, 1) : 0.0;

    $keyProducts = rp_dd_key_products();
    $total = count($keyProducts);
    $below = 0;
    foreach ($keyProducts as $kp) if ($kp['is_below']) $below++;
    $b = $total > 0 ? $below / $total : 0.0;

    if ($a < 9 && $b > 0.5)      $state = 'cung_yeu';
    elseif ($a > 9 && $b > 0.5)  $state = 'vuot_cau';
    else                         $state = 'on_dinh';

    $labels = ['cung_yeu' => 'Cung yếu', 'vuot_cau' => 'Vượt cầu', 'on_dinh' => 'Ổn định'];
    return [
        'state' => $state, 'label' => $labels[$state], 'a' => $a, 'b' => $b,
        // Số thô để hiển thị giải thích công thức ở modal (click .dd2-regulation-value) — không đổi ý nghĩa 'a'/'b' đã có.
        'output' => $output, 'export_qty' => $exportQty, 'key_total' => $total, 'key_below' => $below,
    ];
}

/** Gộp toàn bộ khối "Sản lượng" cho controller/view. */
function rp_dd_output_block()
{
    $series = rp_dd_output_series();
    $overridesMap = rp_dd_get_month_overrides();

    // Danh sách tháng cho modal "Thiết lập giá trị tạm": 6 tháng cột biểu đồ + tháng hiện tại.
    $overrideMonths = $series['months'];
    $overrideMonths[] = ['ym' => $series['current_ym'], 'label' => strtoupper(rp_dd_month_short((int) date('n'))), 'value' => $series['current']];
    foreach ($overrideMonths as &$om) {
        $om['has_override']   = array_key_exists($om['ym'], $overridesMap);
        $om['override_value'] = $om['has_override'] ? $overridesMap[$om['ym']] : null;
    }
    unset($om);

    return [
        'months'          => $series['months'],
        'current'         => $series['current'],
        'current_label'   => strtolower(rp_dd_month_short((int) date('n'))) . ', ' . date('Y'),
        'efficiency'      => rp_dd_efficiency($series['current']),
        'regulation'      => rp_dd_regulation(),
        'key_products'    => rp_dd_key_products(),
        'override_months' => $overrideMonths,
    ];
}

/* ============================================================
 *  2) NHẬP KHO (theo NCC, tháng hiện tại)
 * ============================================================ */

function rp_dd_imports_month_summary($supplier_id = null)
{
    $sid = (int) $supplier_id;
    $from = date('Y-m-01');
    $to   = date('Y-m-t');
    $cond = rp_dd_range_cond('created_at', $from, $to);
    $extra = $sid > 0 ? " AND supplier_id = $sid" : '';
    $r = db_fetch_row(
        "SELECT COUNT(*) AS cnt, COALESCE(SUM(inventory_value), 0) AS value, COALESCE(SUM(purchase_cost), 0) AS cost
         FROM stock_import_invoices WHERE $cond$extra"
    );
    return [
        'count'           => $r ? (int) $r['cnt'] : 0,
        'inventory_value' => $r ? (float) $r['value'] : 0.0,
        'purchase_cost'   => $r ? (float) $r['cost'] : 0.0,
        'month_label'     => strtolower(rp_dd_month_short((int) date('n'))) . ', ' . date('Y'),
    ];
}

/** Chi tiết từng mặt hàng của 1 hóa đơn nhập kho — stock_import_invoices KHÔNG có FK tới dòng
 *  chi tiết (không invoice_id), nên khớp qua (supplier_id, DATE(created_at)) giống tiền lệ
 *  rp_dd_export_invoice_value_map() cho Xuất kho (đã verify không có supplier nào >1 hóa đơn
 *  cùng ngày trong tháng hiện tại). CPMH mỗi dòng = vat_amount + other_cost (đúng công thức
 *  ir_record_batch() dùng để tính purchase_cost tổng của invoice — xem inventory_receivingModel.php).
 *  Gotcha: raw_material_purchase_data UPSERT theo (material_id, supplier_id, ngày) — nếu sau này có
 *  hóa đơn KHÁC cùng NCC/ngày đè lên cùng material_id, dòng của hóa đơn CŨ sẽ mất (hiếm, nhưng khiến
 *  tổng cộng dòng chi tiết có thể lệch invoice.inventory_value — modal vẫn ưu tiên hiện đúng tổng
 *  invoice, không tự tính lại tổng từ các dòng). */
function rp_dd_import_invoice_items($supplier_id, $date_iso)
{
    $sid = (int) $supplier_id;
    $d = escape_string((string) $date_iso);
    if ($sid <= 0 || $d === '') return [];
    $rows = db_fetch_array(
        "SELECT rmp.quantity, rmp.unit_price, rmp.vat_amount, rmp.other_cost, rmp.total_inventory_value,
                COALESCE(NULLIF(mi.common_material_name, ''), mi.material_name) AS name, mi.unit
         FROM raw_material_purchase_data rmp
         LEFT JOIN material_information mi ON mi.id = rmp.material_id
         WHERE rmp.supplier_id = $sid AND DATE(rmp.created_at) = '$d'"
    ) ?: [];
    $out = [];
    foreach ($rows as $r) {
        $out[] = [
            'name'      => (string) ($r['name'] ?? ''),
            'unit'      => (string) ($r['unit'] ?? ''),
            'qty'       => (float) $r['quantity'],
            'price'     => (float) $r['unit_price'],
            'cpmh'      => (float) $r['vat_amount'] + (float) $r['other_cost'],
            'value'     => (float) $r['total_inventory_value'],
        ];
    }
    return $out;
}

function rp_dd_imports_recent($page = 1, $per_page = 3, $supplier_id = null)
{
    $page     = max(1, (int) $page);
    $per_page = max(1, (int) $per_page);
    $offset   = ($page - 1) * $per_page;
    $sid = (int) $supplier_id;
    $from = date('Y-m-01');
    $to   = date('Y-m-t');
    $cond = rp_dd_range_cond('i.created_at', $from, $to);
    $extra = $sid > 0 ? " AND i.supplier_id = $sid" : '';
    $rows = db_fetch_array(
        "SELECT i.id, i.supplier_id, i.inventory_value, i.purchase_cost, i.created_at,
                COALESCE(NULLIF(s.short_name, ''), s.supplier_name) AS supplier_label
         FROM stock_import_invoices i
         LEFT JOIN suppliers s ON s.id = i.supplier_id
         WHERE $cond$extra
         ORDER BY i.created_at DESC
         LIMIT $per_page OFFSET $offset"
    ) ?: [];
    $total = (int) db_num_rows("SELECT id FROM stock_import_invoices i WHERE $cond$extra");
    $out = [];
    foreach ($rows as $r) {
        $dateIso = date('Y-m-d', strtotime($r['created_at']));
        $out[] = [
            'id'              => (int) $r['id'],
            'supplier_id'     => (int) $r['supplier_id'],
            'supplier_label'  => (string) ($r['supplier_label'] ?: ('#' . $r['supplier_id'])),
            'inventory_value' => (float) $r['inventory_value'],
            'purchase_cost'   => (float) $r['purchase_cost'],
            'date_label'      => rp_dd_short_date($r['created_at']),
            'date_iso'        => $dateIso,
            'items'           => rp_dd_import_invoice_items((int) $r['supplier_id'], $dateIso),
        ];
    }
    return ['rows' => $out, 'page' => $page, 'per_page' => $per_page, 'total' => $total, 'total_pages' => max(1, (int) ceil($total / $per_page))];
}

/** Danh sách ĐẦY ĐỦ (mọi thời gian, không chỉ tháng hiện tại) cho modal sidebar "Nhập kho" — mirror rp_dd_imports_recent() bỏ điều kiện tháng.
 *  $keyword: lọc theo tên nhà cung cấp (LIKE, cả short_name lẫn supplier_name). Trả kèm 'totals' = SUM GTNK/CPMH của TOÀN BỘ kết quả lọc (không chỉ trang hiện tại). */
function rp_dd_imports_all($page = 1, $per_page = 10, $supplier_id = null, $keyword = '')
{
    $page     = max(1, (int) $page);
    $per_page = max(1, (int) $per_page);
    $offset   = ($page - 1) * $per_page;
    $sid = (int) $supplier_id;
    $conds = [];
    if ($sid > 0) $conds[] = "i.supplier_id = $sid";
    $kw = trim((string) $keyword);
    if ($kw !== '') {
        $kwEsc = escape_string($kw);
        $conds[] = "(s.short_name LIKE '%$kwEsc%' OR s.supplier_name LIKE '%$kwEsc%')";
    }
    $extra = $conds ? ('WHERE ' . implode(' AND ', $conds)) : '';
    $rows = db_fetch_array(
        "SELECT i.id, i.supplier_id, i.inventory_value, i.purchase_cost, i.created_at,
                COALESCE(NULLIF(s.short_name, ''), s.supplier_name) AS supplier_label
         FROM stock_import_invoices i
         LEFT JOIN suppliers s ON s.id = i.supplier_id
         $extra
         ORDER BY i.created_at DESC
         LIMIT $per_page OFFSET $offset"
    ) ?: [];
    $total = (int) db_num_rows("SELECT i.id FROM stock_import_invoices i LEFT JOIN suppliers s ON s.id = i.supplier_id $extra");
    $totalsRow = db_fetch_row(
        "SELECT COALESCE(SUM(i.inventory_value), 0) AS gtnk, COALESCE(SUM(i.purchase_cost), 0) AS cpmh
         FROM stock_import_invoices i LEFT JOIN suppliers s ON s.id = i.supplier_id $extra"
    );
    $out = [];
    foreach ($rows as $r) {
        $out[] = [
            'id'              => (int) $r['id'],
            'supplier_id'     => (int) $r['supplier_id'],
            'supplier_label'  => (string) ($r['supplier_label'] ?: ('#' . $r['supplier_id'])),
            'inventory_value' => (float) $r['inventory_value'],
            'purchase_cost'   => (float) $r['purchase_cost'],
            'date_label'      => rp_dd_short_date($r['created_at']),
            'date_iso'        => date('Y-m-d', strtotime($r['created_at'])),
        ];
    }
    return [
        'rows' => $out, 'page' => $page, 'per_page' => $per_page, 'total' => $total, 'total_pages' => max(1, (int) ceil($total / $per_page)),
        'totals' => ['inventory_value' => $totalsRow ? (float) $totalsRow['gtnk'] : 0.0, 'purchase_cost' => $totalsRow ? (float) $totalsRow['cpmh'] : 0.0],
    ];
}

/** Modal "7 hoá đơn" (field=count) / "CPMH" (field=cost) — nhóm theo NCC, tháng hiện tại. */
function rp_dd_imports_group_by_supplier($field)
{
    $orderBy = $field === 'cost' ? 'cost' : 'cnt';
    $from = date('Y-m-01');
    $to   = date('Y-m-t');
    $cond = rp_dd_range_cond('i.created_at', $from, $to);
    $rows = db_fetch_array(
        "SELECT i.supplier_id, COALESCE(NULLIF(s.short_name, ''), s.supplier_name) AS supplier_label,
                COUNT(*) AS cnt, COALESCE(SUM(i.inventory_value), 0) AS value, COALESCE(SUM(i.purchase_cost), 0) AS cost
         FROM stock_import_invoices i
         LEFT JOIN suppliers s ON s.id = i.supplier_id
         WHERE $cond
         GROUP BY i.supplier_id, supplier_label
         ORDER BY $orderBy DESC"
    ) ?: [];
    $out = [];
    foreach ($rows as $r) {
        $out[] = [
            'supplier_id'    => (int) $r['supplier_id'],
            'supplier_label' => (string) ($r['supplier_label'] ?: ('#' . $r['supplier_id'])),
            'count'          => (int) $r['cnt'],
            'value'          => (float) $r['value'],
            'cost'           => (float) $r['cost'],
        ];
    }
    return $out;
}

/* ============================================================
 *  3) XUẤT KHO (theo KH, tháng hiện tại + 7 tháng trước)
 * ============================================================ */

/** sales_warehouse_export_invoices.goods_value được nhập theo đơn vị TRIỆU đồng (đã xác nhận
 *  đối chiếu thực tế: hàng "goods_value=5.51" ứng với 5,510,000đ) — nhân 1.000.000 khi hiển thị. */
function rp_dd_goods_value_scale()
{
    return 1000000;
}

function rp_dd_exports_month_total($customer_id = null)
{
    $cid = (int) $customer_id;
    $from = date('Y-m-01');
    $to   = date('Y-m-t');
    $cond = rp_dd_range_cond('created_at', $from, $to);
    $extra = $cid > 0 ? " AND customer_id = $cid" : '';
    $r = db_fetch_row("SELECT COUNT(*) AS cnt, COALESCE(SUM(quantity), 0) AS qty
                        FROM sales_warehouse_export_invoices WHERE $cond$extra");
    return [
        'count'       => $r ? (int) $r['cnt'] : 0,
        'value'       => rp_dd_exports_real_value_for_month((int) date('Y'), (int) date('n'), $customer_id),
        'quantity'    => $r ? (float) $r['qty'] : 0.0,
        'month_label' => strtolower(rp_dd_month_short((int) date('n'))) . ', ' . date('Y'),
    ];
}

/** Giá trị xuất kho THỰC TÍNH (không qua override) của 1 tháng bất kỳ — nguồn sales_inventory_issue_data
 *  (SL x đơn giá theo từng dòng SP). KHÔNG dùng SUM(goods_value) ở sales_warehouse_export_invoices
 *  vì cột đó nhập tay, đã xác minh có dòng lệch đơn vị (vd ghi 496 thay vì 0.496) khiến tổng bị thổi phồng gấp đôi.
 *  2026-07-18: bỏ điều kiện warehouse='Kho TP' — 1 đơn hàng bán có thể có dòng xuất thẳng từ Kho NVL
 *  (bán nguyên liệu, xem get_item_defaultsAction 'type'=material), lọc riêng Kho TP làm sót giá trị. */
function rp_dd_exports_real_value_for_month($year, $month, $customer_id = null)
{
    $cid = (int) $customer_id;
    $from = sprintf('%04d-%02d-01', (int) $year, (int) $month);
    $to   = date('Y-m-t', strtotime($from));
    $cond = rp_dd_range_cond('created_at', $from, $to);
    $extra = $cid > 0 ? " AND customer_id = $cid" : '';
    $rv = db_fetch_row("SELECT COALESCE(SUM(amount), 0) AS value
                         FROM sales_inventory_issue_data
                         WHERE $cond$extra");
    return $rv ? (float) $rv['value'] : 0.0;
}

/** Giá trị xuất kho của 1 tháng: ưu tiên giá trị tạm đã thiết lập (metric='export'), không có thì tính thực. */
function rp_dd_export_value_for_month($year, $month, $overrides = null, $customer_id = null)
{
    $ym = sprintf('%04d-%02d', (int) $year, (int) $month);
    if ($overrides === null) $overrides = rp_dd_get_month_overrides('export');
    if (array_key_exists($ym, $overrides)) return (float) $overrides[$ym];
    return rp_dd_exports_real_value_for_month((int) $year, (int) $month, $customer_id);
}

/** 6 tháng gần nhất (không tính tháng hiện tại) + giá trị xuất kho tháng hiện tại — mirror rp_dd_output_series(). */
function rp_dd_exports_series_7m($customer_id = null)
{
    $y = (int) date('Y');
    $m = (int) date('n');
    $overrides = rp_dd_get_month_overrides('export');
    $months = [];
    for ($i = 6; $i >= 1; $i--) {
        $ts = mktime(0, 0, 0, $m - $i, 1, $y);
        $yy = (int) date('Y', $ts);
        $mm = (int) date('n', $ts);
        $months[] = [
            'ym'    => sprintf('%04d-%02d', $yy, $mm),
            'label' => strtoupper(rp_dd_month_short($mm)),
            'value' => rp_dd_export_value_for_month($yy, $mm, $overrides, $customer_id),
        ];
    }
    $currentYm = sprintf('%04d-%02d', $y, $m);
    return [
        'months'     => $months,
        'current'    => rp_dd_export_value_for_month($y, $m, $overrides, $customer_id),
        'current_ym' => $currentYm,
    ];
}

/** Số lượng (SL) xuất kho THỰC TÍNH của 1 tháng — nguồn sales_warehouse_export_invoices.quantity
 *  (khác value: value quy ra tiền qua sales_inventory_issue_data, quantity thì đọc thẳng SL). */
function rp_dd_exports_qty_real_value_for_month($year, $month, $customer_id = null)
{
    $cid = (int) $customer_id;
    $from = sprintf('%04d-%02d-01', (int) $year, (int) $month);
    $to   = date('Y-m-t', strtotime($from));
    $cond = rp_dd_range_cond('created_at', $from, $to);
    $extra = $cid > 0 ? " AND customer_id = $cid" : '';
    $r = db_fetch_row("SELECT COALESCE(SUM(quantity), 0) AS qty FROM sales_warehouse_export_invoices WHERE $cond$extra");
    return $r ? (float) $r['qty'] : 0.0;
}

/** SL xuất kho của 1 tháng: ưu tiên giá trị tạm đã thiết lập (metric='export_qty'), không có thì tính thực. */
function rp_dd_export_qty_value_for_month($year, $month, $overrides = null, $customer_id = null)
{
    $ym = sprintf('%04d-%02d', (int) $year, (int) $month);
    if ($overrides === null) $overrides = rp_dd_get_month_overrides('export_qty');
    if (array_key_exists($ym, $overrides)) return (float) $overrides[$ym];
    return rp_dd_exports_qty_real_value_for_month((int) $year, (int) $month, $customer_id);
}

/** 6 tháng gần nhất + SL xuất kho tháng hiện tại — mirror rp_dd_exports_series_7m() nhưng theo SL thay vì Doanh thu. */
function rp_dd_exports_qty_series_7m($customer_id = null)
{
    $y = (int) date('Y');
    $m = (int) date('n');
    $overrides = rp_dd_get_month_overrides('export_qty');
    $months = [];
    for ($i = 6; $i >= 1; $i--) {
        $ts = mktime(0, 0, 0, $m - $i, 1, $y);
        $yy = (int) date('Y', $ts);
        $mm = (int) date('n', $ts);
        $months[] = [
            'ym'    => sprintf('%04d-%02d', $yy, $mm),
            'label' => strtoupper(rp_dd_month_short($mm)),
            'value' => rp_dd_export_qty_value_for_month($yy, $mm, $overrides, $customer_id),
        ];
    }
    $currentYm = sprintf('%04d-%02d', $y, $m);
    return [
        'months'     => $months,
        'current'    => rp_dd_export_qty_value_for_month($y, $m, $overrides, $customer_id),
        'current_ym' => $currentYm,
    ];
}

/** Giá trị THỰC của các hóa đơn xuất kho, khớp theo customer_id + ngày (sales_inventory_issue_data không có
 *  FK hóa đơn — xem rp_dd_exports_real_value_for_month). goods_value ở sales_warehouse_export_invoices là nhập
 *  tay, đã xác minh có dòng lệch đơn vị nên KHÔNG dùng làm nguồn chính, chỉ fallback khi không khớp được ngày nào.
 *  2026-07-18: bỏ warehouse='Kho TP' — 1 đơn có thể có dòng xuất thẳng Kho NVL (bán nguyên liệu), lọc riêng
 *  Kho TP làm sót giá trị dòng đó (VD: đơn PLHCM 07/07 thiếu 1 dòng NVL). */
function rp_dd_export_invoice_value_map(array $rows)
{
    $pairs = [];
    foreach ($rows as $r) {
        $cid = (int) $r['customer_id'];
        $d = date('Y-m-d', strtotime($r['created_at']));
        $pairs[$cid . '|' . $d] = true;
    }
    if (!$pairs) return [];
    $conds = [];
    foreach (array_keys($pairs) as $key) {
        list($cid, $d) = explode('|', $key);
        $conds[] = "(customer_id = " . (int) $cid . " AND DATE(created_at) = '" . escape_string($d) . "')";
    }
    $rowsV = db_fetch_array(
        "SELECT customer_id, DATE(created_at) AS d, SUM(amount) AS amt
         FROM sales_inventory_issue_data
         WHERE " . implode(' OR ', $conds) . "
         GROUP BY customer_id, DATE(created_at)"
    ) ?: [];
    $map = [];
    foreach ($rowsV as $rv) {
        $map[(int) $rv['customer_id'] . '|' . $rv['d']] = (float) $rv['amt'];
    }
    return $map;
}

/** Chi tiết từng dòng của 1 phiếu xuất kho — sales_warehouse_export_invoices KHÔNG có FK tới dòng
 *  chi tiết, nên khớp qua (customer_id, DATE(created_at)) giống rp_dd_export_invoice_value_map().
 *  Item có thể là product_id (Kho TP) HOẶC material_id (Kho NVL, bán nguyên liệu) — item.type ở
 *  im_sii_write_item() — join cả 2 bảng, COALESCE lấy tên/đơn vị của nhánh nào khớp. */
function rp_dd_export_invoice_items($customer_id, $date_iso)
{
    $cid = (int) $customer_id;
    $d = escape_string((string) $date_iso);
    if ($cid <= 0 || $d === '') return [];
    $rows = db_fetch_array(
        "SELECT s.quantity, s.unit_price, s.amount,
                COALESCE(NULLIF(p.common_product_name, ''), p.product_name, NULLIF(mi.common_material_name, ''), mi.material_name) AS name,
                COALESCE(p.unit, mi.unit) AS unit
         FROM sales_inventory_issue_data s
         LEFT JOIN products p ON p.id = s.product_id
         LEFT JOIN material_information mi ON mi.id = s.material_id
         WHERE s.customer_id = $cid AND DATE(s.created_at) = '$d'"
    ) ?: [];
    $out = [];
    foreach ($rows as $r) {
        $out[] = [
            'name'  => (string) ($r['name'] ?? ''),
            'unit'  => (string) ($r['unit'] ?? ''),
            'qty'   => (float) $r['quantity'],
            'price' => (float) $r['unit_price'],
            'value' => (float) $r['amount'],
        ];
    }
    return $out;
}

function rp_dd_exports_recent($page = 1, $per_page = 3, $customer_id = null)
{
    $page     = max(1, (int) $page);
    $per_page = max(1, (int) $per_page);
    $offset   = ($page - 1) * $per_page;
    $cid = (int) $customer_id;
    $from = date('Y-m-01');
    $to   = date('Y-m-t');
    $cond = rp_dd_range_cond('e.created_at', $from, $to);
    $extra = $cid > 0 ? " AND e.customer_id = $cid" : '';
    $rows = db_fetch_array(
        "SELECT e.id, e.customer_id, e.goods_value, e.weight, e.created_at,
                COALESCE(NULLIF(c.short_name, ''), c.name) AS customer_label, c.secondary_color
         FROM sales_warehouse_export_invoices e
         LEFT JOIN customers c ON c.id = e.customer_id
         WHERE $cond$extra
         ORDER BY e.created_at DESC
         LIMIT $per_page OFFSET $offset"
    ) ?: [];
    $total = (int) db_num_rows("SELECT id FROM sales_warehouse_export_invoices e WHERE $cond$extra");
    $valueMap = rp_dd_export_invoice_value_map($rows);
    $out = [];
    foreach ($rows as $r) {
        $key = (int) $r['customer_id'] . '|' . date('Y-m-d', strtotime($r['created_at']));
        $out[] = [
            'id'             => (int) $r['id'],
            'customer_id'    => (int) $r['customer_id'],
            'customer_label' => (string) ($r['customer_label'] ?: ('#' . $r['customer_id'])),
            'color'          => (string) ($r['secondary_color'] ?: ''),
            'weight'         => (float) $r['weight'],
            'value'          => $valueMap[$key] ?? ((float) $r['goods_value'] * rp_dd_goods_value_scale()),
            'date_label'     => rp_dd_short_date($r['created_at']),
            'date_iso'       => date('Y-m-d', strtotime($r['created_at'])),
        ];
    }
    return ['rows' => $out, 'page' => $page, 'per_page' => $per_page, 'total' => $total, 'total_pages' => max(1, (int) ceil($total / $per_page))];
}

/** Đơn xuất bán HÔM NAY, gộp theo khách hàng — khối nổi trên biểu đồ Xuất kho (daily_dashboard.php,
 *  "+ {khách hàng viết tắt}: {giá trị}"). Giá trị lấy từ sales_inventory_issue_data (chính xác hơn
 *  goods_value đã làm tròn 2 số thập phân/triệu) qua rp_dd_export_invoice_value_map() — cùng cơ chế
 *  đã fix cho rp_dd_exports_recent()/rp_dd_exports_all() (xem "Bug 3" trong memory dashboard). */
function rp_dd_exports_today_by_customer()
{
    $today = date('Y-m-d');
    $rows = db_fetch_array(
        "SELECT e.customer_id, SUM(e.quantity) AS qty, SUM(e.goods_value) AS goods_value_sum,
                COALESCE(NULLIF(c.short_name, ''), c.name) AS customer_label, c.secondary_color
         FROM sales_warehouse_export_invoices e
         LEFT JOIN customers c ON c.id = e.customer_id
         WHERE DATE(e.created_at) = '$today'
         GROUP BY e.customer_id
         ORDER BY e.customer_id ASC"
    ) ?: [];
    if (!$rows) return [];

    // rp_dd_export_invoice_value_map() khớp theo (customer_id, ngày) rồi SUM(amount) — đưa cả nhóm
    // qua 1 lượt, không cần lặp từng khách hàng.
    $probeRows = array_map(function ($r) use ($today) {
        return ['customer_id' => $r['customer_id'], 'created_at' => $today];
    }, $rows);
    $valueMap = rp_dd_export_invoice_value_map($probeRows);

    $out = [];
    foreach ($rows as $r) {
        $cid = (int) $r['customer_id'];
        $key = $cid . '|' . $today;
        $out[] = [
            'customer_id'    => $cid,
            'customer_label' => (string) ($r['customer_label'] ?: ('#' . $cid)),
            'color'          => (string) ($r['secondary_color'] ?: ''),
            'quantity'       => (float) $r['qty'],
            'value'          => $valueMap[$key] ?? ((float) $r['goods_value_sum'] * rp_dd_goods_value_scale()),
        ];
    }
    return $out;
}

/** Danh sách ĐẦY ĐỦ (mọi thời gian) cho modal sidebar/"Sales Order" — mirror rp_dd_exports_recent() bỏ điều kiện tháng.
 *  $keyword: lọc theo tên khách hàng (LIKE). Trả kèm 'totals' = SUM SL/khối lượng/doanh thu của TOÀN BỘ kết quả lọc. */
function rp_dd_exports_all($page = 1, $per_page = 10, $customer_id = null, $keyword = '')
{
    $page     = max(1, (int) $page);
    $per_page = max(1, (int) $per_page);
    $offset   = ($page - 1) * $per_page;
    $cid = (int) $customer_id;
    $conds = [];
    if ($cid > 0) $conds[] = "e.customer_id = $cid";
    $kw = trim((string) $keyword);
    if ($kw !== '') {
        $kwEsc = escape_string($kw);
        $conds[] = "(c.short_name LIKE '%$kwEsc%' OR c.name LIKE '%$kwEsc%')";
    }
    $extra = $conds ? ('WHERE ' . implode(' AND ', $conds)) : '';
    $rows = db_fetch_array(
        "SELECT e.id, e.customer_id, e.quantity, e.goods_value, e.weight, e.created_at,
                COALESCE(NULLIF(c.short_name, ''), c.name) AS customer_label, c.secondary_color
         FROM sales_warehouse_export_invoices e
         LEFT JOIN customers c ON c.id = e.customer_id
         $extra
         ORDER BY e.created_at DESC
         LIMIT $per_page OFFSET $offset"
    ) ?: [];
    $total = (int) db_num_rows("SELECT e.id FROM sales_warehouse_export_invoices e LEFT JOIN customers c ON c.id = e.customer_id $extra");

    // Totals trên TOÀN BỘ kết quả lọc (không chỉ trang hiện tại) — value phải qua value-map (không SUM(goods_value) trực tiếp).
    $allRows = db_fetch_array(
        "SELECT e.customer_id, e.quantity, e.weight, e.goods_value, e.created_at
         FROM sales_warehouse_export_invoices e LEFT JOIN customers c ON c.id = e.customer_id $extra"
    ) ?: [];
    $allValueMap = rp_dd_export_invoice_value_map($allRows);
    $sumQty = 0.0; $sumWeight = 0.0; $sumValue = 0.0;
    foreach ($allRows as $ar) {
        $key = (int) $ar['customer_id'] . '|' . date('Y-m-d', strtotime($ar['created_at']));
        $sumQty += (float) $ar['quantity'];
        $sumWeight += (float) $ar['weight'];
        $sumValue += $allValueMap[$key] ?? ((float) $ar['goods_value'] * rp_dd_goods_value_scale());
    }

    $valueMap = rp_dd_export_invoice_value_map($rows);
    $out = [];
    foreach ($rows as $r) {
        $dateIso = date('Y-m-d', strtotime($r['created_at']));
        $key = (int) $r['customer_id'] . '|' . $dateIso;
        $out[] = [
            'id'             => (int) $r['id'],
            'customer_id'    => (int) $r['customer_id'],
            'customer_label' => (string) ($r['customer_label'] ?: ('#' . $r['customer_id'])),
            'color'          => (string) ($r['secondary_color'] ?: ''),
            'quantity'       => (float) $r['quantity'],
            'weight'         => (float) $r['weight'],
            'value'          => $valueMap[$key] ?? ((float) $r['goods_value'] * rp_dd_goods_value_scale()),
            'date_label'     => rp_dd_short_date($r['created_at']),
            'date_iso'       => $dateIso,
            'items'          => rp_dd_export_invoice_items((int) $r['customer_id'], $dateIso),
        ];
    }
    return [
        'rows' => $out, 'page' => $page, 'per_page' => $per_page, 'total' => $total, 'total_pages' => max(1, (int) ceil($total / $per_page)),
        'totals' => ['quantity' => $sumQty, 'weight' => $sumWeight, 'value' => $sumValue],
    ];
}

/* ============================================================
 *  4) SẢN XUẤT HÔM NAY
 * ============================================================ */

/** Danh sách sản phẩm sản xuất trong khoảng (tên phổ thông/SL/giá vốn/giá trị).
 *  Lưu ý: 1 sản phẩm/1 ngày có thể có NHIỀU dòng production_receipts nếu người dùng lưu lại nhiều lần
 *  (sửa/nhập lại) trên investment_products — các dòng cũ là bản nháp bị thay thế, KHÔNG được cộng dồn.
 *  finished_product_production_data luôn chỉ giữ 1 dòng/ngày (giá trị cuối cùng) nên dùng nó làm chuẩn:
 *  mỗi (product_id, ngày) chỉ lấy dòng production_receipts MỚI NHẤT rồi mới cộng dồn qua các ngày trong khoảng. */
function rp_dd_products($from, $to)
{
    rp_ensure_product_common_name_column();
    $condF = rp_dd_range_cond('f.created_at', $from, $to);
    $condR = rp_dd_range_cond('created_at', $from, $to);
    $sql = "SELECT f.product_id,
                   COALESCE(NULLIF(p.common_product_name, ''), p.product_name) AS product_name,
                   p.image_url,
                   COALESCE(SUM(f.quantity), 0) AS qty,
                   COALESCE(pr.cost, 0)  AS cost,
                   COALESCE(pr.value, 0) AS value
            FROM finished_product_production_data f
            LEFT JOIN products p ON p.id = f.product_id
            LEFT JOIN (
                SELECT product_id, SUM(total_cost) AS cost, SUM(expected_value) AS value
                FROM (
                    SELECT product_id, total_cost, expected_value,
                           ROW_NUMBER() OVER (PARTITION BY product_id, DATE(created_at) ORDER BY created_at DESC) AS rn
                    FROM production_receipts WHERE $condR
                ) latest
                WHERE rn = 1
                GROUP BY product_id
            ) pr ON pr.product_id = f.product_id
            WHERE $condF
            GROUP BY f.product_id, product_name, p.image_url, pr.cost, pr.value
            ORDER BY value DESC";
    $rows = db_fetch_array($sql) ?: [];
    $out = [];
    foreach ($rows as $r) {
        $cost   = (float) $r['cost'];
        $value  = (float) $r['value'];
        $margin = $cost > 0 ? round(($value - $cost) / $cost, 4) : null;
        $out[] = [
            'product_id' => (int) $r['product_id'],
            'name'       => $r['product_name'] ?: ('#' . (int) $r['product_id']),
            'image_url'  => trim((string) $r['image_url']),
            'quantity'   => (float) $r['qty'],
            'cost'       => $cost,
            'value'      => $value,
            'margin'     => $margin,
        ];
    }
    return $out;
}

/** Nhãn tiêu đề card "Sản xuất..." theo số ngày lùi so với hôm nay (0/1/2/≥3). */
function rp_dd_production_day_label($date)
{
    $days = (int) round((strtotime(date('Y-m-d')) - strtotime(date('Y-m-d', strtotime((string) $date)))) / 86400);
    if ($days <= 0) return 'Sản xuất hôm nay';
    if ($days === 1) return 'Sản xuất hôm qua';
    if ($days === 2) return 'Sản xuất hôm trước';
    return 'Sản xuất ngày ' . date('d/m/y', strtotime((string) $date));
}

/** Danh sách SP sản xuất 1 ngày cụ thể — đủ cột cho modal "Sản xuất" (gộp rp_fgi_product_analysis()),
 *  dùng chung cho cả điều hướng ngày trên card "Sản xuất hôm nay" (chỉ lấy subset field cũ). */
function rp_dd_production_day_detail($date)
{
    $d = date('Y-m-d', strtotime((string) $date));
    $rows = rp_dd_products($d, $d);
    $out = [];
    foreach ($rows as $r) {
        $analysis = function_exists('rp_fgi_product_analysis') ? rp_fgi_product_analysis($r['product_id']) : null;
        $stockQty = $analysis['stock_qty'] ?? 0;
        $out[] = [
            'product_id'    => $r['product_id'],
            'name'          => rp_dd_title_case($r['name']),
            'image_url'     => $r['image_url'],
            'quantity'      => $r['quantity'],
            'cost'          => $r['cost'],
            'value'         => $r['value'],
            'margin'        => $r['margin'],
            'warn_class'    => rp_dd_margin_dot_class($r['margin']),
            'stock_current' => (float) $stockQty + (float) $r['quantity'],
            'issue_1m'      => $analysis['issue_1m'] ?? '—',
            'issue_3m'      => $analysis['issue_3m'] ?? '—',
            'issue_6m'      => $analysis['issue_6m'] ?? '—',
        ];
    }
    // Ưu tiên hiển thị SP sản xuất NHIỀU trước (số lượng giảm dần). Dùng chung cho cả
    // lần tải đầu lẫn khi điều hướng ngày (controller daily_dashboard_production_full
    // cũng gọi hàm này) nên chỉ cần sắp ở đây là mọi nơi đồng bộ.
    usort($out, function ($a, $b) {
        return (float) $b['quantity'] <=> (float) $a['quantity'];
    });
    return $out;
}

/** Danh sách ĐẦY ĐỦ (từng dòng sản xuất, KHÔNG gộp theo SP như rp_dd_products()) cho modal "Sản lượng"
 *  sidebar: Ngày | Sản phẩm (tên hệ thống, không COALESCE common name) | Số lượng | Giá vốn | Giá trị
 *  hàng hóa — tháng hiện tại, mới nhất trước. Giá vốn lấy thẳng finished_product_production_data.production_cost
 *  (đã là giá vốn của đúng dòng product+ngày đó). Giá trị hàng hóa (expected_value) không có sẵn trên bảng
 *  này — join sang production_receipts theo (product_id, ngày), lấy dòng mới nhất/ngày (cùng quy ước với
 *  rp_dd_products()), vì finished_product_production_data vốn unique theo (product_id, ngày) do upsert. */
function rp_dd_output_rows_month($page = 1, $per_page = 10, $keyword = '')
{
    $page     = max(1, (int) $page);
    $per_page = max(1, (int) $per_page);
    $offset   = ($page - 1) * $per_page;
    $from = date('Y-m-01');
    $to   = date('Y-m-t');
    $cond  = rp_dd_range_cond('f.created_at', $from, $to);
    $condR = rp_dd_range_cond('created_at', $from, $to);
    $kw = trim((string) $keyword);
    if ($kw !== '') {
        $cond .= " AND p.product_name LIKE '%" . escape_string($kw) . "%'";
    }
    $joinValue = "LEFT JOIN (
        SELECT product_id, DATE(created_at) AS d, SUM(expected_value) AS value
        FROM (
            SELECT product_id, created_at, expected_value,
                   ROW_NUMBER() OVER (PARTITION BY product_id, DATE(created_at) ORDER BY created_at DESC) AS rn
            FROM production_receipts WHERE $condR
        ) latest
        WHERE rn = 1
        GROUP BY product_id, d
    ) pr ON pr.product_id = f.product_id AND pr.d = DATE(f.created_at)";

    $rows = db_fetch_array(
        "SELECT f.created_at, f.quantity, f.production_cost, p.product_name, COALESCE(pr.value, 0) AS goods_value
         FROM finished_product_production_data f
         LEFT JOIN products p ON p.id = f.product_id
         $joinValue
         WHERE $cond
         ORDER BY f.created_at DESC
         LIMIT $per_page OFFSET $offset"
    ) ?: [];
    $total = (int) db_num_rows("SELECT f.id FROM finished_product_production_data f LEFT JOIN products p ON p.id = f.product_id WHERE $cond");
    $totalsRow = db_fetch_row(
        "SELECT COALESCE(SUM(f.quantity), 0) AS qty, COALESCE(SUM(f.production_cost), 0) AS cost, COALESCE(SUM(pr.value), 0) AS value
         FROM finished_product_production_data f
         LEFT JOIN products p ON p.id = f.product_id
         $joinValue
         WHERE $cond"
    );
    $out = [];
    foreach ($rows as $r) {
        $out[] = [
            'date_label'   => date('d/m/Y', strtotime($r['created_at'])),
            'product_name' => (string) ($r['product_name'] ?: '—'),
            'quantity'     => (float) $r['quantity'],
            'cost'         => (float) $r['production_cost'],
            'value'        => (float) $r['goods_value'],
        ];
    }
    return [
        'rows' => $out, 'page' => $page, 'per_page' => $per_page, 'total' => $total, 'total_pages' => max(1, (int) ceil($total / $per_page)),
        'totals' => [
            'quantity' => $totalsRow ? (float) $totalsRow['qty']   : 0.0,
            'cost'     => $totalsRow ? (float) $totalsRow['cost']  : 0.0,
            'value'    => $totalsRow ? (float) $totalsRow['value'] : 0.0,
        ],
    ];
}

/** Modal drill-down 1 sản phẩm — tồn kho hiện tại + xuất kho 1/3/6 tháng (tái dùng rp_fgi_product_analysis). */
function rp_dd_product_detail($product_id, $date = null)
{
    $pid = (int) $product_id;
    if ($pid <= 0) return null;
    $d = $date ? date('Y-m-d', strtotime((string) $date)) : date('Y-m-d');

    $row = null;
    foreach (rp_dd_products($d, $d) as $r) {
        if ($r['product_id'] === $pid) { $row = $r; break; }
    }
    $analysis = function_exists('rp_fgi_product_analysis') ? rp_fgi_product_analysis($pid) : null;
    if (!$row && !$analysis) return null;

    return [
        'product_id'   => $pid,
        'product_name' => $row ? rp_dd_title_case($row['name']) : ($analysis ? $analysis['product_name'] : ''),
        'quantity'     => $row ? $row['quantity'] : 0.0,
        'cost'         => $row ? $row['cost'] : 0.0,
        'value'        => $row ? $row['value'] : 0.0,
        'margin'       => $row ? $row['margin'] : null,
        'stock_text'      => $analysis['stock_text'] ?? '—',
        'stock_text_raw'  => $analysis['stock_text_raw'] ?? '—',
        'issue_1m'        => $analysis['issue_1m'] ?? '—',
        'issue_3m'        => $analysis['issue_3m'] ?? '—',
        'issue_6m'        => $analysis['issue_6m'] ?? '—',
        'issue_1m_pack'   => $analysis['issue_1m_pack'] ?? '—',
        'issue_3m_pack'   => $analysis['issue_3m_pack'] ?? '—',
        'issue_6m_pack'   => $analysis['issue_6m_pack'] ?? '—',
    ];
}

/* ============================================================
 *  5) ĐẶT HÀNG NGUYÊN LIỆU đang chờ (NVL + cà phê, hidden=0 AND received=0)
 * ============================================================ */

/** Nhãn + màu tuổi đơn: hôm nay xanh lá, 27-28 vàng, 29-30 đỏ, còn lại xám. */
function rp_dd_order_age_label($days)
{
    $days = (int) $days;
    if ($days <= 0)  return ['text' => 'Hôm nay',            'color' => 'today'];
    if ($days === 1) return ['text' => 'Hôm qua',            'color' => 'gray'];
    if ($days >= 29) return ['text' => $days . ' ngày trước', 'color' => 'danger'];
    if ($days >= 27) return ['text' => $days . ' ngày trước', 'color' => 'warn'];
    return ['text' => $days . ' ngày trước', 'color' => 'gray'];
}

/** Danh sách NVL phẳng (material_id/name/unit/qty) từ 1 đơn — gộp cả 2 dạng
 *  material_purchase_orders (mảng phẳng) và oc_orders (mảng nhóm groups[].items[]). */
function rp_dd_flatten_order_items($decoded, $src)
{
    if (!is_array($decoded)) return [];
    $out = [];
    if ($src === 'coffee') {
        foreach ($decoded as $group) {
            foreach ((array) ($group['items'] ?? []) as $it) {
                $out[] = [
                    'material_id' => (int) ($it['material_id'] ?? 0),
                    'name'        => (string) ($it['name'] ?? ''),
                    'unit'        => (string) ($it['unit'] ?? ''),
                    'qty'         => (float) ($it['qty'] ?? 0),
                ];
            }
        }
    } else {
        foreach ($decoded as $it) {
            $out[] = [
                'material_id' => (int) ($it['material_id'] ?? 0),
                'name'        => (string) ($it['name'] ?? ''),
                'unit'        => (string) ($it['unit'] ?? ''),
                'qty'         => (float) ($it['qty'] ?? 0),
            ];
        }
    }
    return $out;
}

/** Đơn giá NVL gần nhất theo từng material_id (từ raw_material_purchase_data). */
/** Giá mua gần nhất (đã gồm CPMH) theo material_id — mirror ĐÚNG logic om_material_price_map()
 *  (module order_material, nguồn chuẩn) thay vì raw_material_purchase_data.unit_price như trước
 *  (2026-07-17: phát hiện lệch giá trị "Đặt hàng nguyên liệu" so với modal "Đơn đặt hàng đã lưu"
 *  của order_material vì đọc nhầm bảng — raw_material_purchase_data không phải nguồn giá chuẩn,
 *  material_purchase_prices mới đúng, ưu tiên dòng có purchase_price_includes_purchase_cost trước). */
function rp_dd_latest_material_prices(array $material_ids)
{
    $ids = array_values(array_unique(array_filter(array_map('intval', $material_ids))));
    if (!$ids) return [];
    $idList = implode(',', $ids);
    $rows = db_fetch_array(
        "SELECT material_id, price FROM (
            SELECT material_id,
                   COALESCE(purchase_price_includes_purchase_cost, purchase_price) AS price,
                   ROW_NUMBER() OVER (
                       PARTITION BY material_id
                       ORDER BY (purchase_price_includes_purchase_cost IS NOT NULL) DESC, last_updated_at DESC, id DESC
                   ) AS rn
            FROM material_purchase_prices
            WHERE material_id IN ($idList)
         ) t WHERE rn = 1"
    ) ?: [];
    $out = [];
    foreach ($rows as $r) {
        $out[(int) $r['material_id']] = $r['price'] !== null ? (float) $r['price'] : 0.0;
    }
    return $out;
}

/** Tên thường gọi theo từng material_id (COALESCE common_material_name, material_name — quy ước
 *  dùng chung toàn app, xem [[product-common-name]]). Dùng để hiển thị "items_description" của đơn
 *  đặt hàng NVL/cà phê thay cho tên lưu cứng lúc tạo đơn (đọc lại table thay vì name đã lưu trong
 *  order_items JSON, để tự động khớp nếu tên thường gọi được đổi về sau). */
function rp_dd_material_common_names(array $material_ids)
{
    $ids = array_values(array_unique(array_filter(array_map('intval', $material_ids))));
    if (!$ids) return [];
    $idList = implode(',', $ids);
    $rows = db_fetch_array(
        "SELECT id, material_name, common_material_name FROM material_information WHERE id IN ($idList)"
    ) ?: [];
    $out = [];
    foreach ($rows as $r) {
        $label = trim((string) $r['common_material_name']) !== '' ? $r['common_material_name'] : $r['material_name'];
        $out[(int) $r['id']] = (string) $label;
    }
    return $out;
}

/** Giá nhập kho (đã gồm CPMH) gần nhất theo từng product_id (từ product_purchase_prices) — dùng để định giá
 *  đơn cà phê (oc_orders) theo SẢN PHẨM đặt (order_qty của từng nhóm), KHÔNG theo NVL cấu thành như đơn NVL
 *  thường — xem [[order-coffee-module]]. */
function rp_dd_latest_product_prices(array $product_ids)
{
    $ids = array_values(array_unique(array_filter(array_map('intval', $product_ids))));
    if (!$ids) return [];
    $idList = implode(',', $ids);
    $rows = db_fetch_array(
        "SELECT product_id, price_including_tax FROM product_purchase_prices
         WHERE product_id IN ($idList) ORDER BY created_at DESC, id DESC"
    ) ?: [];
    $out = [];
    foreach ($rows as $r) {
        $pid = (int) $r['product_id'];
        if (!isset($out[$pid])) $out[$pid] = (float) $r['price_including_tax'];
    }
    return $out;
}

/** "Hương ổi - 1kg, Trà BOP - 60kg, ..." (2026-07-17: đổi từ "Tên (SL đơn vị)" sang "Tên - SLđơn vị"
 *  theo tên thường gọi) — mô tả đầy đủ, cắt hiển thị bằng CSS ellipsis. */
function rp_dd_order_items_description(array $items)
{
    $parts = [];
    foreach ($items as $it) {
        if ($it['name'] === '') continue;
        $qty = ($it['qty'] == (int) $it['qty']) ? (string) (int) $it['qty'] : rtrim(rtrim(number_format($it['qty'], 2, '.', ''), '0'), '.');
        $parts[] = $it['name'] . ' - ' . $qty . $it['unit'];
    }
    return implode(', ', $parts);
}

/**
 * Đảm bảo material_purchase_orders có đủ cột snapshot dùng cho card "Đặt hàng nguyên liệu".
 * KHÔNG gọi om_ensure_tables() (module order_material) từ report vì cả 2 module order_material
 * và order_management đều dùng chung tiền tố hàm "om_*" — require chung 1 request sẽ đụng độ tên
 * hàm (vd om_get_order() khai báo ở cả 2 nơi) và gây "Cannot redeclare". Tự ALTER TABLE riêng ở đây,
 * idempotent như các module khác (vd payrollModel.php).
 */
function rp_dd_ensure_material_order_columns()
{
    static $done = false;
    if ($done) return;
    $done = true;
    if (db_num_rows("SHOW TABLES LIKE 'material_purchase_orders'") <= 0) return;
    db_query("ALTER TABLE material_purchase_orders ADD COLUMN IF NOT EXISTS expected_value_snapshot DECIMAL(15,2) DEFAULT NULL AFTER received_at");
    db_query("ALTER TABLE material_purchase_orders ADD COLUMN IF NOT EXISTS actual_value_snapshot   DECIMAL(15,2) DEFAULT NULL AFTER expected_value_snapshot");
    // Đơn cà phê (oc_orders) cũng có cặp snapshot tương đương (expected chốt lúc LƯU đơn,
    // actual chốt lúc phiếu nhập khớp đơn) — cùng lý do không require order_coffee model ở đây.
    if (db_num_rows("SHOW TABLES LIKE 'oc_orders'") > 0) {
        db_query("ALTER TABLE oc_orders ADD COLUMN IF NOT EXISTS expected_value DECIMAL(15,2) DEFAULT NULL AFTER received_at");
        db_query("ALTER TABLE oc_orders ADD COLUMN IF NOT EXISTS actual_value   DECIMAL(15,2) DEFAULT NULL AFTER expected_value");
    }
}

function rp_dd_pending_material_orders($page = 1, $per_page = 6)
{
    $page     = max(1, (int) $page);
    $per_page = max(1, (int) $per_page);
    rp_dd_ensure_material_order_columns();

    // Đơn đã "Đã nhận" (tự động xác nhận khi khớp phiếu ở inventory_receiving) vẫn hiển thị thêm
    // 1 ngày (chưa ẩn ngay) để card kịp show trạng thái "ĐÃ NHẬN" — xem rp_dd_pending_material_orders().
    $rows1 = db_fetch_array(
        "SELECT o.id, o.supplier_id, COALESCE(NULLIF(s.short_name, ''), o.supplier_name) AS supplier_label,
                o.order_items AS items_json, o.created_at, o.received, o.received_at,
                o.expected_value_snapshot, o.actual_value_snapshot, 'material' AS src
         FROM material_purchase_orders o
         LEFT JOIN suppliers s ON s.id = o.supplier_id
         WHERE o.hidden = 0 AND (o.received = 0 OR o.received_at >= (NOW() - INTERVAL 1 DAY))"
    ) ?: [];
    $rows2 = [];
    if (db_num_rows("SHOW TABLES LIKE 'oc_orders'") > 0) {
        // Cùng cửa sổ hiển thị với đơn NVL: đơn "Đã nhận" còn hiện thêm 1 ngày để kịp show stamp.
        $rows2 = db_fetch_array(
            "SELECT o.id, o.supplier_id, COALESCE(NULLIF(s.short_name, ''), o.supplier_name) AS supplier_label,
                    o.groups AS items_json, o.created_at, o.received, o.received_at,
                    o.expected_value AS expected_value_snapshot, o.actual_value AS actual_value_snapshot, 'coffee' AS src
             FROM oc_orders o
             LEFT JOIN suppliers s ON s.id = o.supplier_id
             WHERE o.hidden = 0 AND (o.received = 0 OR o.received_at >= (NOW() - INTERVAL 1 DAY))"
        ) ?: [];
    }
    $all = array_merge($rows1, $rows2);
    $todayTs = strtotime(date('Y-m-d'));

    // Gom tất cả material_id (đơn NVL + đơn cà phê, cả 2 đều có material_id sau khi flatten) / product_id
    // (đơn cà phê) cần tra đơn giá gần nhất + tên thường gọi (1 query/loại cho cả trang thay vì N).
    $flatByRow = [];
    $allMaterialIds = [];
    $allProductIds = [];
    foreach ($all as $idx => $r) {
        $decoded = json_decode((string) $r['items_json'], true);
        $flat = rp_dd_flatten_order_items($decoded, $r['src']);
        $flatByRow[$idx] = $flat;
        foreach ($flat as $it) if ($it['material_id'] > 0) $allMaterialIds[] = $it['material_id'];
        if ($r['src'] === 'coffee') {
            foreach ((array) $decoded as $g) if ((int) ($g['product_id'] ?? 0) > 0) $allProductIds[] = (int) $g['product_id'];
        }
    }
    $priceMap = rp_dd_latest_material_prices($allMaterialIds);
    $productPriceMap = rp_dd_latest_product_prices($allProductIds);
    $commonNameMap = rp_dd_material_common_names($allMaterialIds);

    $list = [];
    foreach ($all as $idx => $r) {
        $received = !empty($r['received']);
        $ts = strtotime(date('Y-m-d', strtotime($r['created_at'])));
        $days = (int) round(($todayTs - $ts) / 86400);
        // Đơn đã "Đã nhận" tự có cửa sổ hiển thị riêng (1 ngày kể từ received_at, lọc ở SQL) —
        // không áp cắt "quá 30 ngày kể từ lúc ĐẶT" cho trường hợp này (đơn có thể đặt lâu rồi mới nhận).
        if ($days > 30 && !$received) continue;
        $flat = $flatByRow[$idx];
        $expectedValue = 0.0;
        $priceBreakdown = [];
        if ($r['src'] === 'coffee') {
            // Đơn cà phê: định giá theo SẢN PHẨM đặt (order_qty × giá nhập gần nhất của SP), không theo NVL cấu thành —
            // nên breakdown giải thích cũng theo SẢN PHẨM (không phải NVL) để khớp đúng cách tính ra expected_value.
            $decoded = json_decode((string) $r['items_json'], true);
            foreach ((array) $decoded as $g) {
                $pid = (int) ($g['product_id'] ?? 0);
                $qty = (float) ($g['order_qty'] ?? 0);
                if ($pid <= 0 || $qty <= 0) continue;
                $price = $productPriceMap[$pid] ?? 0.0;
                $expectedValue += $qty * $price;
                $priceBreakdown[] = ['name' => (string) ($g['product_name'] ?? ''), 'qty' => $qty, 'unit' => '', 'price' => $price];
            }
            // Đơn cà phê chốt expected_value NGAY LÚC LƯU (oc_save_order) — ưu tiên snapshot để giá
            // trị dự kiến CỐ ĐỊNH cho đối soát, không trôi khi giá nhập mới ghi đè product_purchase_prices.
            if ($r['expected_value_snapshot'] !== null) $expectedValue = (float) $r['expected_value_snapshot'];
        } else {
            foreach ($flat as $it) {
                $price = $priceMap[$it['material_id']] ?? 0.0;
                $expectedValue += $it['qty'] * $price;
                $name = ($it['material_id'] > 0 && !empty($commonNameMap[$it['material_id']])) ? $commonNameMap[$it['material_id']] : $it['name'];
                $priceBreakdown[] = ['name' => $name, 'qty' => $it['qty'], 'unit' => $it['unit'], 'price' => $price];
            }
        }
        $flatDisplay = array_map(function ($it) use ($commonNameMap) {
            if ($it['material_id'] > 0 && !empty($commonNameMap[$it['material_id']])) $it['name'] = $commonNameMap[$it['material_id']];
            return $it;
        }, $flat);

        // Đã nhận + có snapshot (tự động khớp phiếu ở inventory_receiving) -> ưu tiên hiển thị ĐÚNG giá
        // trị lúc đặt/lúc nhận thay vì giá trị dự kiến tính lại theo giá NVL hiện tại (có thể đã đổi sau khi nhận).
        $actualValue = null;
        $valueMatch  = null;
        if ($received && $r['expected_value_snapshot'] !== null && $r['actual_value_snapshot'] !== null) {
            $expectedValue = (float) $r['expected_value_snapshot'];
            $actualValue   = (float) $r['actual_value_snapshot'];
            // So khớp theo VNĐ làm tròn (không phải bằng tuyệt đối) — VND không có phần lẻ và giá trị
            // dự kiến/thực nhận đều cộng dồn qua nhiều dòng NVL (mỗi dòng có thể lệch vài đồng do làm
            // tròn đơn giá), nên so bằng float tuyệt đối (0.01) gần như luôn ra "lệch" dù số hiển thị
            // (đã làm tròn, xem dd2_money()) giống hệt nhau — khiến trạng thái "khớp" không bao giờ lên.
            $valueMatch = (int) round($actualValue) === (int) round($expectedValue);
        }

        $list[] = [
            'id'              => (int) $r['id'],
            'src'             => $r['src'],
            'supplier_name'   => (string) ($r['supplier_label'] ?: '#' . $r['supplier_id']),
            'item_label'      => count($flat) . ($r['src'] === 'coffee' ? ' nhóm NL' : ' mặt hàng'),
            'items_description' => rp_dd_order_items_description($flatDisplay),
            'expected_value'  => $expectedValue,
            'price_breakdown' => $priceBreakdown,
            'days_old'        => $days,
            'created_at'      => $r['created_at'],
            'age'             => rp_dd_order_age_label($days),
            'received'        => $received,
            'actual_value'    => $actualValue,
            'value_match'     => $valueMatch,
        ];
    }
    // Thứ tự: đơn đặt hôm nay xếp trước (mới nhất trước trong nhóm này); các đơn còn lại xếp
    // theo CŨ NHẤT trước rồi lần lượt tới đơn mới hơn (2026-07-17).
    usort($list, function ($a, $b) {
        $aToday = $a['days_old'] <= 0;
        $bToday = $b['days_old'] <= 0;
        if ($aToday !== $bToday) return $aToday ? -1 : 1;
        if ($aToday) return strtotime($b['created_at']) <=> strtotime($a['created_at']);
        return strtotime($a['created_at']) <=> strtotime($b['created_at']);
    });

    $total  = count($list);
    $offset = ($page - 1) * $per_page;
    $pageRows = array_slice($list, $offset, $per_page);
    return ['rows' => $pageRows, 'page' => $page, 'per_page' => $per_page, 'total' => $total, 'total_pages' => max(1, (int) ceil($total / $per_page))];
}

/** Danh sách ĐẦY ĐỦ đơn đặt hàng NVL/cà phê (mọi trạng thái, không chỉ đang chờ ≤30 ngày) cho modal
 *  sidebar "Đặt hàng nguyên liệu" — mirror rp_dd_pending_material_orders() bỏ AND received=0 + bỏ cắt 30 ngày. */
function rp_dd_material_orders_all($page = 1, $per_page = 10)
{
    $page     = max(1, (int) $page);
    $per_page = max(1, (int) $per_page);

    $rows1 = db_fetch_array(
        "SELECT o.id, o.supplier_id, COALESCE(NULLIF(s.short_name, ''), o.supplier_name) AS supplier_label,
                o.order_items AS items_json, o.created_at, o.received, 'material' AS src
         FROM material_purchase_orders o
         LEFT JOIN suppliers s ON s.id = o.supplier_id
         WHERE o.hidden = 0"
    ) ?: [];
    $rows2 = [];
    if (db_num_rows("SHOW TABLES LIKE 'oc_orders'") > 0) {
        $rows2 = db_fetch_array(
            "SELECT o.id, o.supplier_id, COALESCE(NULLIF(s.short_name, ''), o.supplier_name) AS supplier_label,
                    o.groups AS items_json, o.created_at, o.received,
                    o.expected_value AS expected_value_snapshot, 'coffee' AS src
             FROM oc_orders o
             LEFT JOIN suppliers s ON s.id = o.supplier_id
             WHERE o.hidden = 0"
        ) ?: [];
    }
    $all = array_merge($rows1, $rows2);
    $todayTs = strtotime(date('Y-m-d'));

    $flatByRow = [];
    $allMaterialIds = [];
    $allProductIds = [];
    foreach ($all as $idx => $r) {
        $decoded = json_decode((string) $r['items_json'], true);
        $flat = rp_dd_flatten_order_items($decoded, $r['src']);
        $flatByRow[$idx] = $flat;
        foreach ($flat as $it) if ($it['material_id'] > 0) $allMaterialIds[] = $it['material_id'];
        if ($r['src'] === 'coffee') {
            foreach ((array) $decoded as $g) if ((int) ($g['product_id'] ?? 0) > 0) $allProductIds[] = (int) $g['product_id'];
        }
    }
    $priceMap = rp_dd_latest_material_prices($allMaterialIds);
    $productPriceMap = rp_dd_latest_product_prices($allProductIds);
    $commonNameMap = rp_dd_material_common_names($allMaterialIds);

    $list = [];
    foreach ($all as $idx => $r) {
        $ts = strtotime(date('Y-m-d', strtotime($r['created_at'])));
        $days = (int) round(($todayTs - $ts) / 86400);
        $flat = $flatByRow[$idx];
        $expectedValue = 0.0;
        if ($r['src'] === 'coffee') {
            if (($r['expected_value_snapshot'] ?? null) !== null) {
                $expectedValue = (float) $r['expected_value_snapshot'];
            } else {
                $decoded = json_decode((string) $r['items_json'], true);
                foreach ((array) $decoded as $g) {
                    $pid = (int) ($g['product_id'] ?? 0);
                    $qty = (float) ($g['order_qty'] ?? 0);
                    if ($pid <= 0 || $qty <= 0) continue;
                    $expectedValue += $qty * ($productPriceMap[$pid] ?? 0.0);
                }
            }
        } else {
            foreach ($flat as $it) {
                $price = $priceMap[$it['material_id']] ?? 0.0;
                $expectedValue += $it['qty'] * $price;
            }
        }
        $flatDisplay = array_map(function ($it) use ($commonNameMap) {
            if ($it['material_id'] > 0 && !empty($commonNameMap[$it['material_id']])) $it['name'] = $commonNameMap[$it['material_id']];
            return $it;
        }, $flat);
        $list[] = [
            'id'                 => (int) $r['id'],
            'src'                => $r['src'],
            'supplier_name'      => (string) ($r['supplier_label'] ?: '#' . $r['supplier_id']),
            'received'           => (int) $r['received'],
            'item_label'         => count($flat) . ($r['src'] === 'coffee' ? ' nhóm NL' : ' mặt hàng'),
            'items_description'  => rp_dd_order_items_description($flatDisplay),
            'expected_value'     => $expectedValue,
            'days_old'           => $days,
            'created_at'         => $r['created_at'],
            'date_label'         => date('d/m/Y', strtotime($r['created_at'])),
        ];
    }
    usort($list, function ($a, $b) { return strtotime($b['created_at']) <=> strtotime($a['created_at']); });

    $total  = count($list);
    $offset = ($page - 1) * $per_page;
    $pageRows = array_slice($list, $offset, $per_page);
    return ['rows' => $pageRows, 'page' => $page, 'per_page' => $per_page, 'total' => $total, 'total_pages' => max(1, (int) ceil($total / $per_page))];
}

/* ============================================================
 *  6) QUỸ (cash_transactions, lũy kế toàn bộ lịch sử)
 * ============================================================ */

/** Đọc/lưu "tồn đầu" của Quỹ — cộng vào lũy kế Thu-Chi để ra số dư hiện tại. */
function rp_dd_get_fund_opening_balance()
{
    rp_dd_ensure_tables();
    $row = db_fetch_row("SELECT setting_value FROM app_settings WHERE setting_key = 'daily_dashboard.fund_opening_balance' LIMIT 1");
    return ($row && $row['setting_value'] !== null && $row['setting_value'] !== '') ? (float) $row['setting_value'] : 0.0;
}

function rp_dd_save_fund_opening_balance($value)
{
    rp_dd_ensure_tables();
    $v = escape_string((string) (float) $value);
    $exists = db_num_rows("SELECT 1 FROM app_settings WHERE setting_key = 'daily_dashboard.fund_opening_balance'") > 0;
    if ($exists) db_update('app_settings', ['setting_value' => $v], "setting_key = 'daily_dashboard.fund_opening_balance'");
    else db_insert('app_settings', ['setting_key' => 'daily_dashboard.fund_opening_balance', 'setting_value' => $v]);
    return true;
}

/** Chú thích logo trang dashboard này (mặc định "VUA AN TOÀN"), sửa tại chỗ, chỉ áp dụng riêng trang này. */
function rp_dd_get_logo_caption()
{
    rp_dd_ensure_tables();
    $row = db_fetch_row("SELECT setting_value FROM app_settings WHERE setting_key = 'daily_dashboard.logo_caption' LIMIT 1");
    return ($row && $row['setting_value'] !== null && $row['setting_value'] !== '') ? (string) $row['setting_value'] : 'VUA AN TOÀN';
}

function rp_dd_save_logo_caption($value)
{
    rp_dd_ensure_tables();
    $v = escape_string(trim((string) $value));
    $exists = db_num_rows("SELECT 1 FROM app_settings WHERE setting_key = 'daily_dashboard.logo_caption'") > 0;
    if ($exists) db_update('app_settings', ['setting_value' => $v], "setting_key = 'daily_dashboard.logo_caption'");
    else db_insert('app_settings', ['setting_key' => 'daily_dashboard.logo_caption', 'setting_value' => $v]);
    return true;
}

/** Mốc bắt đầu + bước nhảy trục giá trị chart Xuất kho (thiết lập tay qua modal, mặc định ẩn trục khi step=0). */
function rp_dd_get_export_axis_setting()
{
    rp_dd_ensure_tables();
    $row = db_fetch_row("SELECT setting_value FROM app_settings WHERE setting_key = 'daily_dashboard.export_axis' LIMIT 1");
    $decoded = $row ? json_decode((string) $row['setting_value'], true) : null;
    return [
        'min'  => (is_array($decoded) && isset($decoded['min'])) ? (float) $decoded['min'] : 0.0,
        'step' => (is_array($decoded) && isset($decoded['step'])) ? (float) $decoded['step'] : 0.0,
    ];
}

function rp_dd_save_export_axis_setting($min, $step)
{
    rp_dd_ensure_tables();
    $v = escape_string(json_encode(['min' => (float) $min, 'step' => max(0, (float) $step)]));
    $exists = db_num_rows("SELECT 1 FROM app_settings WHERE setting_key = 'daily_dashboard.export_axis'") > 0;
    if ($exists) db_update('app_settings', ['setting_value' => $v], "setting_key = 'daily_dashboard.export_axis'");
    else db_insert('app_settings', ['setting_key' => 'daily_dashboard.export_axis', 'setting_value' => $v]);
    return true;
}

function rp_dd_fund_balance()
{
    $rows = db_fetch_array("SELECT transaction_type, COALESCE(SUM(amount), 0) AS total FROM cash_transactions GROUP BY transaction_type") ?: [];
    $thu = 0.0;
    $chi = 0.0;
    foreach ($rows as $r) {
        if ($r['transaction_type'] === 'Thu') $thu = (float) $r['total'];
        if ($r['transaction_type'] === 'Chi') $chi = (float) $r['total'];
    }
    return rp_dd_get_fund_opening_balance() + $thu - $chi;
}

/** $type: 'Thu'|'Chi'|null (null/'' = tất cả) — lọc theo loại giao dịch. */
function rp_dd_fund_recent($page = 1, $per_page = 2, $type = null)
{
    $page     = max(1, (int) $page);
    $per_page = max(1, (int) $per_page);
    $offset   = ($page - 1) * $per_page;
    $type = (string) $type;
    $extra = ($type === 'Thu' || $type === 'Chi') ? " WHERE transaction_type = '" . escape_string($type) . "'" : '';
    $rows = db_fetch_array(
        "SELECT description, transaction_type, amount, created_at FROM cash_transactions
         $extra ORDER BY created_at DESC LIMIT $per_page OFFSET $offset"
    ) ?: [];
    $total = (int) db_num_rows("SELECT id FROM cash_transactions $extra");
    $out = [];
    foreach ($rows as $r) {
        $out[] = [
            'description' => (string) $r['description'],
            'is_income'   => $r['transaction_type'] === 'Thu',
            'amount'      => (float) $r['amount'],
            'date_label'  => date('d/m/Y', strtotime($r['created_at'])),
            'date_iso'    => date('Y-m-d', strtotime($r['created_at'])),
        ];
    }
    return ['rows' => $out, 'page' => $page, 'per_page' => $per_page, 'total' => $total, 'total_pages' => max(1, (int) ceil($total / $per_page))];
}

/** Danh sách ĐẦY ĐỦ thu/chi cho modal sidebar "Quỹ" — rp_dd_fund_recent() vốn đã không giới hạn theo ngày, chỉ đổi $per_page. */
function rp_dd_fund_all($page = 1, $per_page = 10, $type = null)
{
    return rp_dd_fund_recent($page, $per_page, $type);
}

/* ============================================================
 *  TỔNG HỢP TOÀN BỘ DASHBOARD (bootstrap lần render đầu)
 * ============================================================ */

/** % "hàng đang có" của 1 đơn chi nhánh — quy đổi theo SỐ LƯỢNG (không phải đếm SP): tổng
 *  min(đặt, tồn) / tổng đặt, dùng lại field 'qt_order'/'stock' đã có sẵn từ om_enrich_item().
 *  Đơn không có item nào (rỗng) coi như đủ 100% (không có gì để thiếu). */
function rp_dd_branch_order_fulfill_percent($order)
{
    $ordered = 0.0;
    $avail   = 0.0;
    foreach ($order['items'] ?? [] as $it) {
        $qty = (float) ($it['qt_order'] ?? 0);
        if ($qty <= 0) continue;
        $stock = max(0.0, (float) ($it['stock'] ?? 0));
        $ordered += $qty;
        $avail   += min($qty, $stock);
    }
    if ($ordered <= 0) return 100.0;
    return round($avail / $ordered * 100, 1);
}

/** Đơn hàng chi nhánh CHƯA BỐC (picked=0) — om_get_branch_orders() trả cả đã bốc/chưa bốc
 *  (dùng chung cho views branch_orders), ở đây chỉ hiển thị đơn còn đang chờ xử lý. Gắn kèm
 *  % hàng đang có + dòng giải thích thiếu NVL (dùng cho card "Chi nhánh đặt hàng" v4, 2026-07-17:
 *  4 card lưới 2x2, click card mở modal xem SP thiếu + lý do). */
function rp_dd_branch_orders_pending($limit = 4)
{
    if (!function_exists('om_get_branch_orders')) return [];
    $lim = max(1, (int) $limit);
    $batch = om_get_branch_orders(1, 50)['rows'] ?? [];
    $out = [];
    foreach ($batch as $o) {
        if (!empty($o['picked'])) continue;
        $o['fulfill_percent'] = rp_dd_branch_order_fulfill_percent($o);
        $o['shortage_lines']  = rp_dd_branch_order_shortage_lines($o);
        $out[] = $o;
        if (count($out) >= $lim) break;
    }
    return $out;
}

/** Danh sách ĐẦY ĐỦ đơn hàng chi nhánh (mọi trạng thái) cho modal sidebar "Chi nhánh đặt hàng" —
 *  gọi thẳng om_get_branch_orders() (đã đủ items[]/stats/customer...), gắn thêm dòng giải thích thiếu NVL. */
function rp_dd_branch_orders_all($page = 1, $per_page = 10)
{
    if (!function_exists('om_get_branch_orders')) return ['rows' => [], 'page' => 1, 'per_page' => $per_page, 'total' => 0, 'total_pages' => 1];
    $res = om_get_branch_orders($page, $per_page);
    foreach ($res['rows'] as &$o) $o['shortage_lines'] = rp_dd_branch_order_shortage_lines($o);
    unset($o);
    return $res;
}

/** Với 1 item đã enrich (product_id, qt_order, is_short — xem om_enrich_item()) — nếu is_short,
 *  nhân công thức x1 (pf_get_recipe, product_materials) theo qt_order rồi so với tồn NVL
 *  (material_inventory), trả về danh sách NVL không đủ để sản xuất bù. */
function rp_dd_item_shortage_materials($item)
{
    if (empty($item['is_short']) || empty($item['product_id'])) return [];
    if (!function_exists('pf_get_recipe')) return [];
    $recipe = pf_get_recipe((int) $item['product_id']);
    $qty = (float) ($item['qt_order'] ?? 0);
    $out = [];
    foreach ($recipe as $ing) {
        $required = (float) $ing['quantity'] * $qty;
        if ($required > (float) $ing['stock']) {
            $out[] = [
                'name'      => $ing['display_name'],
                'required'  => $required,
                'stock'     => (float) $ing['stock'],
                'shortfall' => $required - (float) $ing['stock'],
            ];
        }
    }
    return $out;
}

/** Chuỗi "Tên SP thiếu NVL1, NVL2" cho từng SP thiếu tồn trong 1 đơn chi nhánh — dùng chung cho
 *  card "Chi nhánh đặt hàng" (khi ≤2 đơn) và modal danh sách đầy đủ.
 *  2026-07-18: SP không tra được công thức (chưa thiết lập product_materials, ví dụ hàng mua sẵn
 *  không tự sản xuất) trước đây bị "continue" bỏ qua hoàn toàn dù is_short=true, khiến số lượng
 *  SP thiếu hiển thị (VD: PLCT thiếu 4) không khớp số dòng thực hiện. Giờ fallback ghi tên SP kèm
 *  số lượng thiếu "(thiếu N)" — FE tô màu khác phần trong ngoặc, xem dd2-shortage-qty ở JS. */
function rp_dd_branch_order_shortage_lines($order)
{
    $lines = [];
    foreach ($order['items'] ?? [] as $it) {
        if (empty($it['is_short'])) continue;
        $mats = rp_dd_item_shortage_materials($it);
        if ($mats) {
            $names = array_map(function ($m) { return $m['name']; }, $mats);
            $lines[] = ($it['product_name'] ?? '') . ' thiếu ' . implode(', ', $names);
            continue;
        }
        $shortfall = max(0.0, (float) ($it['qt_order'] ?? 0) - (float) ($it['stock'] ?? 0));
        $q_text = ($shortfall == (int) $shortfall) ? (string) (int) $shortfall : rtrim(rtrim(number_format($shortfall, 2, '.', ''), '0'), '.');
        $unit = trim((string) ($it['unit'] ?? ''));
        $lines[] = ($it['product_name'] ?? '') . ' (thiếu ' . $q_text . ($unit !== '' ? ' ' . $unit : '') . ')';
    }
    return $lines;
}

/** Modal chi tiết NVL đã dùng SX hôm nay của 1 sản phẩm — giải thích con số "giá vốn sản xuất". */
/** Modal chi tiết NVL — $date mặc định hôm nay nhưng PHẢI truyền đúng ngày đang xem trên card
 *  "Sản xuất" (điều hướng ngày) khi gọi từ đó, nếu không sẽ luôn hiện dữ liệu của hôm nay dù
 *  đang xem ngày khác. */
function rp_dd_product_cost_breakdown($product_id, $date = null)
{
    $pid = (int) $product_id;
    if ($pid <= 0) return null;
    $d = $date ? date('Y-m-d', strtotime((string) $date)) : date('Y-m-d');
    $cond = rp_dd_range_cond('r.created_at', $d, $d);
    $product = db_fetch_row("SELECT product_name FROM products WHERE id = $pid LIMIT 1");
    $rows = db_fetch_array(
        "SELECT r.material_id, COALESCE(NULLIF(mi.common_material_name, ''), mi.material_name) AS material_name, mi.unit,
                SUM(r.quantity) AS qty, SUM(r.amount) AS amount
         FROM raw_material_production_issue_data r
         LEFT JOIN material_information mi ON mi.id = r.material_id
         WHERE r.product_id = $pid AND $cond
         GROUP BY r.material_id, material_name, mi.unit
         ORDER BY amount DESC"
    ) ?: [];
    $out = [];
    $total = 0.0;
    foreach ($rows as $r) {
        $qty = (float) $r['qty'];
        $amount = (float) $r['amount'];
        $total += $amount;
        $out[] = [
            'material_id'   => (int) $r['material_id'],
            'name'          => (string) ($r['material_name'] ?: ('#' . $r['material_id'])),
            'unit'          => trim((string) $r['unit']),
            'quantity'      => $qty,
            'unit_price'    => $qty > 0 ? $amount / $qty : 0.0,
            'amount'        => $amount,
        ];
    }
    // GVSX hiển thị ở khối "Sản xuất" = giá vốn NVL + CPSX (SL sản xuất trong ngày x đơn giá CPSX) —
    // thêm dòng này để tổng của modal khớp với GVSX trên bảng, tương tự cách investment_products hiển thị.
    $condF = rp_dd_range_cond('created_at', $d, $d);
    $qtyRow = db_fetch_row("SELECT COALESCE(SUM(quantity), 0) AS qty FROM finished_product_production_data WHERE product_id = $pid AND $condF");
    $cpsxQty = $qtyRow ? (float) $qtyRow['qty'] : 0.0;
    if ($cpsxQty > 0) {
        $rate = production_cost_rate();
        $cpsxAmount = $cpsxQty * $rate;
        $total += $cpsxAmount;
        $out[] = [
            'material_id'   => 0,
            'name'          => 'Chi phí sản xuất',
            'unit'          => '',
            'quantity'      => $cpsxQty,
            'unit_price'    => $rate,
            'amount'        => $cpsxAmount,
        ];
    }
    return [
        'product_id'   => $pid,
        'product_name' => $product ? (string) $product['product_name'] : ('#' . $pid),
        'materials'    => $out,
        'total'        => $total,
    ];
}

/**
 * Nhân sự "vắng" hôm nay (off/nửa công) — dùng cho khối "Chấm công" ở đầu trang daily_dashboard.
 * Chỉ tính mark 'off'/'half' có dòng lưu trong payroll_timesheet_entries — đi làm đủ (mặc định
 * 'x' khi không có dòng) không hiển thị; 'holiday' (Lễ/Tết áp dụng chung) và 'dash' (không tính
 * công, vd tăng ca CN) cũng không phải "vắng" nên bỏ qua. Dùng chung nguồn nhân sự/chấm công với
 * payroll module (py_employees_for_branch/py_timesheet_marks) — xem rp_dd_total_cong() ở trên.
 */
function rp_dd_attendance_today()
{
    if (!function_exists('py_employees_for_branch') || !function_exists('py_timesheet_marks')) return [];
    $today = date('Y-m-d');
    $employees = py_employees_for_branch();
    $ids = array_column($employees, 'id');
    if (!$ids) return [];
    $marksMap = py_timesheet_marks($ids, (int) date('Y'), (int) date('n'));

    $markLabels = ['off' => 'Nghỉ', 'half' => 'Nửa công'];
    $out = [];
    foreach ($employees as $emp) {
        $eid = (int) $emp['id'];
        $entry = $marksMap[$eid][$today] ?? null;
        $mark = $entry['mark'] ?? null;
        if (!in_array($mark, ['off', 'half'], true)) continue;
        $reason = trim((string) ($entry['reason'] ?? ''));
        $out[] = [
            'employee_id' => $eid,
            'full_name'   => (string) $emp['full_name'],
            'mark'        => $mark,
            'reason'      => $reason !== '' ? $reason : $markLabels[$mark],
        ];
    }
    return $out;
}

function rp_dd_dashboard_bootstrap()
{
    rp_dd_ensure_tables();
    $today = date('Y-m-d');
    return [
        'output'          => rp_dd_output_block(),
        'imports'         => ['summary' => rp_dd_imports_month_summary(), 'recent' => rp_dd_imports_recent(1, 3)],
        // 'recent' (mini-list) đã gỡ khỏi card Xuất kho, thay bằng chart — chỉ còn cần summary + series.
        'exports'         => ['summary' => rp_dd_exports_month_total(), 'series' => rp_dd_exports_series_7m(), 'series_qty' => rp_dd_exports_qty_series_7m(), 'axis' => rp_dd_get_export_axis_setting(), 'today_by_customer' => rp_dd_exports_today_by_customer()],
        'production_day'  => ['date' => $today, 'label' => rp_dd_production_day_label($today), 'rows' => rp_dd_production_day_detail($today)],
        'material_orders' => rp_dd_pending_material_orders(1, 6),
        'branch_orders'   => rp_dd_branch_orders_pending(4),
        'fund'            => ['balance' => rp_dd_fund_balance(), 'recent' => rp_dd_fund_recent(1, 3)],
        'logo_caption'    => rp_dd_get_logo_caption(),
        'attendance_today'=> rp_dd_attendance_today(),
    ];
}
