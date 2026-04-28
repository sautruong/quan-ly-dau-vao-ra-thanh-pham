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
    return ['fg_receipt_production', 'fg_receipt_purchase', 'other_receipt', 'sales_return_receipt'];
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
        $summary = 'Nhập kho ' . implode(', ', $names);
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
