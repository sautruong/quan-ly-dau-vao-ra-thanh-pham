<?php

/**
 * Lấy kế hoạch sản xuất (NVSX đã gửi xuống) để NV kho nhập vào tồn.
 */
function im_get_plans_for_inventory()
{
    $sql = "SELECT pp.id AS plan_id,
                   pp.product_id,
                   pp.quantity,
                   p.product_name
            FROM production_plans pp
            LEFT JOIN products p ON p.id = pp.product_id
            ORDER BY pp.id ASC";
    return db_fetch_array($sql) ?: [];
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
    return db_fetch_row("SELECT id, product_name FROM products WHERE id = $pid LIMIT 1") ?: null;
}

/**
 * Tìm sản phẩm thương mại kèm thông tin nhà cung cấp (page product_buy).
 * Trả về [{id, product_name, supplier_id, supplier_name}].
 */
function im_search_products_with_supplier($keyword)
{
    $keyword = trim($keyword);
    if ($keyword === '') return [];
    $kw = escape_string($keyword);
    $sql = "SELECT p.id,
                   p.product_name,
                   p.supplier_id,
                   s.supplier_name
            FROM products p
            LEFT JOIN suppliers s ON s.id = p.supplier_id
            WHERE p.type = 'Thương mại'
              AND p.product_name LIKE '%$kw%'
            ORDER BY p.product_name ASC
            LIMIT 15";
    return db_fetch_array($sql) ?: [];
}

/** Lấy 1 product thương mại kèm nhà cung cấp. */
function im_get_product_with_supplier($product_id)
{
    $pid = (int) $product_id;
    if ($pid <= 0) return null;
    $sql = "SELECT p.id,
                   p.product_name,
                   p.supplier_id,
                   s.supplier_name
            FROM products p
            LEFT JOIN suppliers s ON s.id = p.supplier_id
            WHERE p.id = $pid
            LIMIT 1";
    return db_fetch_row($sql) ?: null;
}

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
 */
function im_record_import($product_id, $qty, $interpretation = '', $created_at = null, $type_import = 'fg_receipt_production')
{
    $pid = (int) $product_id;
    $q   = (float) $qty;
    if ($pid <= 0) return false;

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

    db_insert('stock_imports', $data);
    return true;
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

        $names = array_map(function ($it) {
            return $it['product_name'] ?: ('#' . $it['product_id']);
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

        $ts = strtotime($g['created_at']);
        $date_display = $ts ? date('H:i:s d/m/Y', $ts) : $g['created_at'];

        $batches[] = [
            'group_key'    => $g['group_key'],
            'created_at'   => $g['created_at'],
            'date_display' => $date_display,
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

    $row = db_fetch_row("SELECT product_id, quantity FROM stock_imports WHERE id = $iid LIMIT 1");
    if (!$row) return false;

    $pid   = (int) $row['product_id'];
    $old   = (float) $row['quantity'];
    $new   = (float) $new_qty;
    $delta = $new - $old;

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
    return db_fetch_row("SELECT id, name, short_name, address, receiver, phone
                         FROM customers WHERE id = $cid LIMIT 1") ?: null;
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

        $ts = strtotime($g['created_at']);
        $date_display = $ts ? date('H:i:s d/m/Y', $ts) : $g['created_at'];

        $batches[] = [
            'group_key'      => $g['group_key'],
            'created_at'     => $g['created_at'],
            'date_display'   => $date_display,
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

    $cust = db_fetch_row("SELECT id, name, short_name FROM customers WHERE id = $cid LIMIT 1");
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

/* ============================================================
 *  SALES ISSUE — page sales_issue (Xuất kho bán hàng)
 *  Trừ tồn finished_goods_inventory + ghi stock_exports.
 * ============================================================ */

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

        $cust = db_fetch_row("SELECT name, short_name FROM customers WHERE id = $cid LIMIT 1");
        $cust_short = $cust ? ($cust['short_name'] ?: $cust['name']) : ('#' . $cid);

        $sql_items = "SELECT se.id AS export_id,
                             se.product_id,
                             se.quantity,
                             se.unit_price,
                             se.total_amount,
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

        $total = (float) $g['total'];
        $total_fmt = number_format($total, 0, ',', ',') . ' đ';
        $summary = 'Bán hàng ' . $cust_short . ' giá trị ' . $total_fmt;
        $max_len = 160;
        if (mb_strlen($summary, 'UTF-8') > $max_len) {
            $summary = mb_substr($summary, 0, $max_len, 'UTF-8') . '...';
        }

        $ts = strtotime($g['created_at']);
        $date_display = $ts ? date('H:i:s d/m/Y', $ts) : $g['created_at'];

        $batches[] = [
            'group_key'    => $g['group_key'],
            'created_at'   => $g['created_at'],
            'date_display' => $date_display,
            'customer_id'  => $cid,
            'customer_short' => $cust_short,
            'total'        => $total,
            'summary'      => $summary,
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
function im_handle_sales_return_item($sales_return_id, $method)
{
    $sid = (int) $sales_return_id;
    if ($sid <= 0) return false;
    $method = trim((string) $method);
    if ($method === '') return false;

    $row = db_fetch_row("SELECT product_id, quantity, handling_method FROM sales_returns WHERE id = $sid LIMIT 1");
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

/**
 * Lấy danh sách item đầu tư (investment_products) theo NGÀY (Y-m-d).
 * Source là stock_imports với type_import = 'fg_receipt_production' để đồng bộ với
 * sản lượng thực đã được nhập kho — gom theo product_id và SUM(quantity).
 * Mỗi item kèm materials (product_materials + giá nhập gần nhất) và system_price.
 */
function im_get_investment_items_for_date($date)
{
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

    $items = [];
    foreach ($rows as $r) {
        $pid = (int) $r['product_id'];
        $qty = (float) $r['quantity'];

        $p = db_fetch_row("SELECT product_name FROM products WHERE id = $pid LIMIT 1");
        if (!$p) continue;

        $price_row    = db_fetch_row("SELECT system_price FROM product_prices WHERE product_id = $pid LIMIT 1");
        $system_price = $price_row ? (float) $price_row['system_price'] : 0;

        $sql_m = "SELECT pm.material_id,
                         pm.quantity_required,
                         mi.material_name,
                         (SELECT mpp.purchase_price
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
                'quantity_required' => (float) $m['quantity_required'],
                'purchase_price'    => $m['purchase_price'] !== null ? (float) $m['purchase_price'] : 0,
            ];
        }, $mats);

        $items[] = [
            'product_id'   => $pid,
            'product_name' => $p['product_name'],
            'quantity'     => $qty,
            'system_price' => $system_price,
            'materials'    => $materials,
        ];
    }
    return $items;
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
function im_record_investment($items, $cost_price, $goods_value, $created_at = null)
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
    foreach ($items as $it) {
        $pid = (int) ($it['product_id'] ?? 0);
        if ($pid <= 0) continue;
        $mats = isset($it['materials']) && is_array($it['materials']) ? $it['materials'] : [];
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
        }
    }

    return $first_si_id;
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
function im_update_investment_batch($group_key, $items, $cost_price, $goods_value)
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
    if (!empty($si_rows)) {
        $ids = array_map(function ($r) { return (int) $r['id']; }, $si_rows);
        $ids_csv = implode(',', $ids);
        db_query("DELETE FROM production_costs_daily WHERE stock_imports_id IN ($ids_csv)");
        db_query("DELETE FROM stock_imports WHERE id IN ($ids_csv)");
        return count($ids);
    }
    return 0;
}
