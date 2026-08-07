<?php
/**
 * sell_factory model
 * - Truy vấn dữ liệu cho trang order_factory
 */

/**
 * Lấy danh sách sản phẩm có tồn > 0 nhóm theo danh mục.
 * Kèm thông tin quy đổi tồn theo quy cách bao bì ngoài.
 */
function sf_get_products_grouped_by_category()
{
    $sql = "SELECT
                p.id              AS product_id,
                COALESCE(NULLIF(p.common_product_name, ''), p.product_name) AS product_name,
                COALESCE(NULLIF(p.unit, ''), pib.unit) AS unit,
                p.image_url,
                pc.id             AS category_id,
                pc.category_name,
                fgi.quantity      AS qt_fgi,
                ops.outer_packaging_short_name,
                ops.quantity      AS qt_ops
            FROM finished_goods_inventory fgi
            INNER JOIN products p           ON p.id  = fgi.product_id
            INNER JOIN product_categories pc ON pc.id = p.category_id
            LEFT JOIN outer_packaging_specifications ops ON ops.product_id = p.id
            LEFT JOIN product_info_basic pib ON pib.product_id = p.id
            LEFT JOIN sf_product_display_order pdo ON pdo.product_id = p.id
            WHERE fgi.quantity > 0
            ORDER BY pc.id ASC, (pdo.sort_order IS NULL) ASC, pdo.sort_order ASC, p.product_name ASC";

    $rows = db_fetch_array($sql);

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
        $groups[$cid]['products'][] = [
            'product_id'                  => $r['product_id'],
            'product_name'                => $r['product_name'],
            'unit'                        => $r['unit'],
            'image_url'                   => $r['image_url'],
            'qt_fgi'                      => (int)$r['qt_fgi'],
            'qt_ops'                      => $r['qt_ops'] !== null ? (int)$r['qt_ops'] : 0,
            'outer_packaging_short_name'  => $r['outer_packaging_short_name'],
            'inventory_text'              => sf_format_inventory_convert(
                                                (int)$r['qt_fgi'],
                                                $r['qt_ops'] !== null ? (int)$r['qt_ops'] : 0,
                                                $r['outer_packaging_short_name'],
                                                $r['unit']
                                             ),
        ];
    }
    return array_values($groups);
}

/* ---------------------------------------------------------------------
 * THỨ TỰ HIỂN THỊ SẢN PHẨM (dùng chung — gộp SP tương đồng cạnh nhau).
 * ------------------------------------------------------------------- */

/** Bảo đảm bảng thứ tự hiển thị tồn tại. */
function sf_ensure_display_order_table()
{
    static $done = false;
    if ($done) return;
    $done = true;
    db_query("CREATE TABLE IF NOT EXISTS sf_product_display_order (
        product_id INT NOT NULL PRIMARY KEY,
        sort_order INT NULL
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci");
}

/**
 * Toàn bộ sản phẩm nhà máy + thứ tự hiển thị hiện tại (cho modal cài đặt).
 * Sắp xếp giống lưới tồn: nhóm -> số nhỏ trước -> trống xếp cuối -> A→B.
 */
function sf_get_all_products_for_order()
{
    sf_ensure_display_order_table();
    return db_fetch_array(
        "SELECT p.id AS product_id,
                COALESCE(NULLIF(p.common_product_name, ''), p.product_name) AS product_name,
                pc.category_name, pdo.sort_order
         FROM products p
         LEFT JOIN product_categories pc ON pc.id = p.category_id
         LEFT JOIN sf_product_display_order pdo ON pdo.product_id = p.id
         ORDER BY pc.id ASC, (pdo.sort_order IS NULL) ASC, pdo.sort_order ASC, p.product_name ASC"
    ) ?: [];
}

/** Ghi đè thứ tự hiển thị: $map = [product_id => sort_order|''|null]. */
function sf_save_display_order($map)
{
    sf_ensure_display_order_table();
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
            // Bỏ trống -> xóa để xếp cuối.
            db_delete('sf_product_display_order', "product_id = $pid");
        }
    }
    return true;
}

/**
 * Lấy toàn bộ nhóm sản phẩm (cho select lọc nhóm ở trang order_factory).
 */
function sf_get_all_categories()
{
    return db_fetch_array(
        "SELECT id, category_name FROM product_categories ORDER BY id ASC"
    ) ?: [];
}

/**
 * GỬI ẢNH ĐƠN HÀNG VÀO CHAT (hộp thoại 1-1 với đồng nghiệp).
 * Cùng công thức với fm_share_to_chat() / ar_send_to_recipients(): lưu tệp vào kho của chat,
 * chèn 1 tin loại 'image' rồi gắn dòng chat_attachments.
 *
 * @param int    $uid        người gửi (user đang đăng nhập)
 * @param array  $file       phần tử của $_FILES (ảnh PNG do html2canvas dựng)
 * @param array  $target_ids danh sách user nhận
 * @param string $note       lời nhắn kèm theo (rỗng thì dùng câu mặc định)
 * @return array ['ok'=>bool, 'sent'=>int, 'message'=>string]
 */
function sf_share_order_to_chat($uid, $file, array $target_ids, $note = '')
{
    $uid = (int) $uid;
    if ($uid <= 0) return ['ok' => false, 'message' => 'Chưa đăng nhập.'];

    require_once APPPATH . DIRECTORY_SEPARATOR . 'libraries' . DIRECTORY_SEPARATOR . 'chat.php';
    chat_ensure_tables();

    $ids = [];
    foreach ($target_ids as $t) {
        $t = (int) $t;
        if ($t > 0 && $t !== $uid && !in_array($t, $ids, true)) $ids[] = $t;
    }
    if (!$ids) return ['ok' => false, 'message' => 'Chưa chọn người nhận.'];

    $stored = chat_store_upload($file);
    if (empty($stored['ok'])) {
        return ['ok' => false, 'message' => 'Không lưu được ảnh: ' . ($stored['reason'] ?? '')];
    }

    $body = trim((string) $note);
    if ($body === '') $body = '🧾 Đơn đặt hàng nhà máy — ' . date('d/m/Y H:i');

    // Mọi người nhận DÙNG CHUNG 1 tệp trên đĩa (giống ar_send_one) — không nhân bản vô ích.
    $att = [
        'file_name'     => (string) $stored['file_name'],
        'original_name' => (string) $stored['original_name'],
        'mime'          => (string) $stored['mime'],
        'size'          => (int) $stored['size'],
        'is_image'      => (int) $stored['is_image'] ? 1 : 0,
    ];

    $sent = 0;
    foreach ($ids as $t) {
        $cid = chat_get_or_create_direct($uid, $t);
        if ($cid <= 0) continue;
        $mid = (int) chat_insert_message($cid, $uid, $body, 'image');
        if ($mid <= 0) continue;
        $row = $att;
        $row['message_id'] = $mid;
        db_insert('chat_attachments', $row);
        chat_mark_read($cid, $uid, $mid); // bản của người gửi coi như đã đọc
        $sent++;
    }

    if ($sent === 0) return ['ok' => false, 'message' => 'Không gửi được cho người nhận nào.'];
    return ['ok' => true, 'sent' => $sent];
}

/**
 * DANH SÁCH SẢN PHẨM CỦA MẺ SẢN XUẤT GẦN NHẤT — dùng để "chớp sáng" ô sản phẩm khi vào
 * trang order_factory, cho người đặt hàng thấy ngay hôm rồi nhà máy vừa làm ra những gì.
 *
 * Cách xác định "gần nhất": lấy NGÀY sản xuất mới nhất còn trong
 * finished_product_production_data rồi trả về mọi sản phẩm của đúng ngày đó.
 * KHÔNG trừ lùi theo thứ trong tuần: nhà máy nghỉ Chủ nhật nên MAX(ngày) tự rơi vào thứ Bảy,
 * và cách này cũng tự đúng cho cả ngày lễ / đợt nghỉ dài — không phải bảo trì luật ngày nghỉ.
 *
 * @return array danh sách product_id (int), có thể rỗng nếu bảng chưa có dữ liệu.
 */
function sf_get_recent_production_product_ids()
{
    $row = db_fetch_row(
        "SELECT DATE(MAX(created_at)) AS d FROM finished_product_production_data"
    );
    $ngay = $row && !empty($row['d']) ? $row['d'] : '';
    if ($ngay === '') return [];

    $rows = db_fetch_array(
        "SELECT DISTINCT product_id
         FROM finished_product_production_data
         WHERE DATE(created_at) = '" . escape_string($ngay) . "'
           AND product_id > 0"
    ) ?: [];

    $ids = [];
    foreach ($rows as $r) $ids[] = (int) $r['product_id'];
    return $ids;
}

/**
 * Quy đổi tồn theo quy cách bao bì ngoài
 *  - qt_fgi  : tồn (finished_goods_inventory.quantity)
 *  - qt_ops  : số đơn vị/1 bao bì ngoài (outer_packaging_specifications.quantity)
 *  - ops_name: tên ngắn bao bì ngoài (outer_packaging_short_name)
 *  - unit    : đơn vị nhỏ nhất (products.unit)
 */
function sf_format_inventory_convert($qt_fgi, $qt_ops, $ops_name, $unit)
{
    $unit     = trim((string)$unit);
    $ops_name = trim((string)$ops_name);

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

/**
 * Lấy chi tiết sản phẩm cho modal info
 */
function sf_get_product_detail($product_id)
{
    $product_id = (int)$product_id;
    $sql = "SELECT
                p.id               AS product_id,
                COALESCE(NULLIF(p.common_product_name, ''), p.product_name) AS product_name,
                COALESCE(NULLIF(p.unit, ''), pib.unit) AS unit,
                p.image_url,
                fgi.quantity       AS quantity,
                pib.inner_packaging_spec,
                pib.outer_packaging_spec,
                pp.system_price
            FROM products p
            LEFT JOIN finished_goods_inventory fgi ON fgi.product_id = p.id
            LEFT JOIN product_info_basic       pib ON pib.product_id = p.id
            LEFT JOIN product_prices           pp  ON pp.product_id  = p.id
            WHERE p.id = $product_id
            LIMIT 1";
    return db_fetch_row($sql);
}

/**
 * Lấy thông tin tối thiểu để thêm sản phẩm vào danh sách đặt
 */
function sf_get_product_order_info($product_id)
{
    $product_id = (int)$product_id;
    $sql = "SELECT
                p.id          AS product_id,
                COALESCE(NULLIF(p.common_product_name, ''), p.product_name) AS product_name,
                COALESCE(NULLIF(p.unit, ''), pib.unit) AS unit,
                COALESCE(pw.weight_kg, 0)    AS weight_kg,
                COALESCE(pp.system_price, 0) AS system_price
            FROM products p
            LEFT JOIN product_info_basic pib ON pib.product_id = p.id
            LEFT JOIN product_weights    pw  ON pw.product_id  = p.id
            LEFT JOIN product_prices     pp  ON pp.product_id  = p.id
            WHERE p.id = $product_id
            LIMIT 1";
    return db_fetch_row($sql);
}

/**
 * Sản phẩm ĐANG HẾT (không có tồn) khớp keyword — chỉ dùng khi search.
 * Lưới tồn vốn chỉ hiện SP có tồn; hàm này bù các SP hết để user vẫn đặt được.
 */
function sf_search_out_of_stock($keyword)
{
    $keyword = escape_string($keyword);
    $sql = "SELECT p.id AS product_id,
                   COALESCE(NULLIF(p.common_product_name, ''), p.product_name) AS product_name,
                   COALESCE(NULLIF(p.unit, ''), pib.unit) AS unit
            FROM products p
            LEFT JOIN product_info_basic pib ON pib.product_id = p.id
            LEFT JOIN finished_goods_inventory fgi ON fgi.product_id = p.id
            WHERE (p.product_name LIKE '%$keyword%' OR p.product_code LIKE '%$keyword%')
              AND COALESCE(fgi.quantity, 0) <= 0
            ORDER BY p.product_name ASC
            LIMIT 20";
    return db_fetch_array($sql) ?: [];
}

/**
 * Auto-complete khách hàng theo keyword (name)
 */
function sf_search_customers($keyword)
{
    $keyword = escape_string($keyword);
    $sql = "SELECT id, name, short_name
            FROM customers
            WHERE name LIKE '%$keyword%' OR short_name LIKE '%$keyword%'
            ORDER BY name ASC
            LIMIT 10";
    return db_fetch_array($sql);
}

/**
 * Auto-complete cho ô "Thêm sản phẩm cần đặt".
 * - Sản phẩm: toàn bộ products.
 * - Nguyên vật liệu: CHỈ những material có trong branch_material_selling_prices
 *   (đối tượng được bán theo giá selling_price ở đó).
 * Trả mảng chuẩn hóa: { type:'product'|'material', ref_id, name, unit }.
 */
function sf_search_products($keyword)
{
    $keyword = escape_string($keyword);
    // COLLATE để hợp nhất products (general_ci) và material_information (unicode_ci) trong UNION.
    $sql = "SELECT 'product' AS type, p.id AS ref_id,
                   COALESCE(NULLIF(p.common_product_name, ''), p.product_name) COLLATE utf8mb4_general_ci AS name,
                   COALESCE(NULLIF(p.unit, ''), pib.unit) COLLATE utf8mb4_general_ci AS unit
            FROM products p
            LEFT JOIN product_info_basic pib ON pib.product_id = p.id
            WHERE p.product_name LIKE '%$keyword%' OR p.product_code LIKE '%$keyword%'
            UNION ALL
            SELECT 'material' AS type, m.id AS ref_id,
                   m.material_name COLLATE utf8mb4_general_ci AS name,
                   m.unit COLLATE utf8mb4_general_ci AS unit
            FROM material_information m
            WHERE m.id IN (SELECT material_id FROM branch_material_selling_prices)
              AND (m.material_name LIKE '%$keyword%' OR m.material_code LIKE '%$keyword%')
            ORDER BY name ASC
            LIMIT 15";
    return db_fetch_array($sql);
}

/**
 * Thông tin tối thiểu để thêm 1 NGUYÊN VẬT LIỆU vào đơn đặt.
 * Giá = selling_price mới nhất trong branch_material_selling_prices.
 */
function sf_get_material_order_info($material_id)
{
    $material_id = (int) $material_id;
    $sql = "SELECT m.id AS material_id,
                   m.material_name AS product_name,
                   m.unit,
                   0 AS weight_kg,
                   COALESCE((
                       SELECT b.selling_price
                       FROM branch_material_selling_prices b
                       WHERE b.material_id = m.id
                       ORDER BY b.updated_at DESC, b.id DESC
                       LIMIT 1
                   ), 0) AS system_price
            FROM material_information m
            WHERE m.id = $material_id
            LIMIT 1";
    return db_fetch_row($sql);
}

/* ---------------------------------------------------------------------
 * CÁ NHÂN HÓA: mỗi user có lịch sử đặt hàng riêng + ghi nhớ chi nhánh.
 * ------------------------------------------------------------------- */

/** Id user đang đăng nhập (0 nếu chưa đăng nhập). */
function sf_current_user_id()
{
    if (!function_exists('permission_current_user')) return 0;
    $u = permission_current_user();
    return (int) ($u['id'] ?? 0);
}

/** Bảo đảm bảng app_settings tồn tại (dùng cho ghi nhớ chi nhánh). */
function sf_ensure_settings_table()
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

/** Chi nhánh ghi nhớ gần nhất của user. Trả ['id'=>.., 'name'=>..] hoặc null. */
function sf_get_last_branch($user_id)
{
    $user_id = (int) $user_id;
    if ($user_id <= 0) return null;
    sf_ensure_settings_table();
    $key = escape_string('sell_factory.last_branch.' . $user_id);
    $row = db_fetch_row("SELECT setting_value FROM app_settings WHERE setting_key = '$key' LIMIT 1");
    if (!$row || $row['setting_value'] === null || $row['setting_value'] === '') return null;
    $data = json_decode($row['setting_value'], true);
    return is_array($data) ? $data : null;
}

/** Ghi nhớ chi nhánh của user (dùng nhiều lần đến khi đổi lại). */
function sf_set_last_branch($user_id, $customer_id, $customer_name)
{
    $user_id = (int) $user_id;
    if ($user_id <= 0) return false;
    sf_ensure_settings_table();
    $full = 'sell_factory.last_branch.' . $user_id;
    $fk   = escape_string($full);
    $val  = json_encode([
        'id'   => $customer_id !== null && $customer_id !== '' ? (int) $customer_id : null,
        'name' => (string) $customer_name,
    ], JSON_UNESCAPED_UNICODE);
    $exists = db_num_rows("SELECT 1 FROM app_settings WHERE setting_key = '$fk'") > 0;
    if ($exists) {
        db_update('app_settings', ['setting_value' => $val], "setting_key = '$fk'");
    } else {
        db_insert('app_settings', ['setting_key' => $full, 'setting_value' => $val]);
    }
    return true;
}

/**
 * Bảo đảm các cột cờ ẩn 2 chiều + xác nhận đơn tồn tại.
 *  - factory_hidden: nhà máy ẩn đơn (không ảnh hưởng lịch sử bán hàng).
 *  - seller_hidden : bán hàng ẩn đơn khỏi lịch sử của mình.
 *  - confirmed     : nhà máy đã "Xác nhận đơn hàng".
 */
function sf_ensure_order_flags()
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

/**
 * Bán hàng ẩn 1 đơn khỏi lịch sử của mình (ràng buộc user).
 * Nếu nhà máy CHƯA khóa đơn -> ẩn luôn ở phía nhà máy (branch_orders).
 * Lịch sử đặt hàng thuộc quyền bán hàng nên không xóa cứng.
 */
function sf_delete_history_row($id, $user_id = 0)
{
    $id = (int) $id;
    if ($id <= 0) return false;
    sf_ensure_order_flags();
    $cond = "id = $id";
    $user_id = (int) $user_id;
    if ($user_id > 0) $cond .= " AND user_id = $user_id";

    $row = db_fetch_row("SELECT locked FROM factory_order_sales_history WHERE $cond LIMIT 1");
    if (!$row) return false;

    $data = ['seller_hidden' => 1];
    if (empty($row['locked'])) $data['factory_hidden'] = 1; // nhà máy chưa khóa -> ẩn luôn bên nhà máy
    db_update('factory_order_sales_history', $data, $cond);
    return true;
}

/**
 * Lưu lịch sử đơn hàng nhà máy (gắn user_id + ghi chú đơn).
 */
function sf_save_factory_order($customer_id, $customer_name, $items, $weight_total, $value_total, $user_id = 0, $note = '')
{
    $description = 'order nhà máy ' . number_format($weight_total, 0, ',', '.') . 'kg - ' .
                   number_format($value_total, 0, ',', '.') . ' đ';

    $data = [
        'user_id'       => (int) $user_id > 0 ? (int) $user_id : NULL,
        'customer_id'   => $customer_id !== null ? (int)$customer_id : NULL,
        'customer_name' => $customer_name,
        'order_items'   => json_encode($items, JSON_UNESCAPED_UNICODE),
        'weight_total'  => $weight_total,
        'value_total'   => $value_total,
        'description'   => $description,
        'note'          => (string) $note,
        'status'        => 'new',
    ];
    return db_insert('factory_order_sales_history', $data);
}

/**
 * Lấy lịch sử (có phân trang) — CHỈ của 1 user.
 */
function sf_get_history($page = 1, $per_page = 5, $user_id = 0)
{
    sf_ensure_order_flags();
    $page     = max(1, (int)$page);
    $per_page = max(1, (int)$per_page);
    $offset   = ($page - 1) * $per_page;
    $user_id  = (int) $user_id;
    // Chỉ lịch sử của user + chưa bị bán hàng ẩn.
    $where    = $user_id > 0 ? "WHERE user_id = $user_id AND seller_hidden = 0" : "WHERE 1=0";

    $rows = db_fetch_array(
        "SELECT id, customer_id, customer_name, order_items,
                weight_total, value_total, description, note, status,
                picked, locked, pickup_time, confirmed, factory_hidden, created_at
         FROM factory_order_sales_history
         $where
         ORDER BY created_at DESC, id DESC
         LIMIT $per_page OFFSET $offset"
    );
    $total = (int)db_num_rows("SELECT id FROM factory_order_sales_history $where");
    return [
        'rows'        => $rows ?: [],
        'total'       => $total,
        'page'        => $page,
        'per_page'    => $per_page,
        'total_pages' => (int)ceil($total / $per_page),
    ];
}

/**
 * Lấy 1 dòng lịch sử để "Đặt lại" (ràng buộc user).
 */
function sf_get_history_row($id, $user_id = 0)
{
    $id  = (int)$id;
    $user_id = (int) $user_id;
    $cond = "id = $id";
    if ($user_id > 0) $cond .= " AND user_id = $user_id";
    $row = db_fetch_row(
        "SELECT id, customer_id, customer_name, order_items, weight_total, value_total,
                description, note, status, picked, locked, pickup_time, created_at
         FROM factory_order_sales_history WHERE $cond LIMIT 1"
    );
    if ($row && !empty($row['order_items'])) {
        $row['order_items'] = json_decode($row['order_items'], true);
    }
    return $row;
}

/**
 * Trạng thái hiện tại các đơn của 1 user (cho order_history poll realtime):
 * picked / locked / confirmed / factory_hidden. Chỉ đơn chưa bị bán hàng ẩn.
 */
function sf_get_order_statuses($user_id)
{
    $user_id = (int) $user_id;
    if ($user_id <= 0) return [];
    sf_ensure_order_flags();
    return db_fetch_array(
        "SELECT id, picked, locked, confirmed, factory_hidden
         FROM factory_order_sales_history
         WHERE user_id = $user_id AND seller_hidden = 0"
    ) ?: [];
}

/** Đơn còn cho bán hàng sửa không: của chính user, CHƯA bốc, CHƯA khóa. */
function sf_order_is_editable($id, $user_id)
{
    $id = (int) $id; $user_id = (int) $user_id;
    if ($id <= 0 || $user_id <= 0) return false;
    $row = db_fetch_row(
        "SELECT picked, locked FROM factory_order_sales_history
         WHERE id = $id AND user_id = $user_id LIMIT 1"
    );
    if (!$row) return false;
    return empty($row['picked']) && empty($row['locked']);
}

/** Bán hàng cập nhật đơn (thêm/đổi/xóa SP). Chỉ khi còn editable. */
function sf_update_order($id, $user_id, $items, $weight_total, $value_total, $note = '')
{
    if (!sf_order_is_editable($id, $user_id)) return false;
    $id = (int) $id;
    $description = 'order nhà máy ' . number_format($weight_total, 0, ',', '.') . 'kg - ' .
                   number_format($value_total, 0, ',', '.') . ' đ';
    db_update('factory_order_sales_history', [
        'order_items'  => json_encode($items, JSON_UNESCAPED_UNICODE),
        'weight_total' => $weight_total,
        'value_total'  => $value_total,
        'description'  => $description,
        'note'         => (string) $note,
    ], "id = $id");
    return true;
}

/* =====================================================================
 *  KHSX DỰ KIẾN + ĐỐI CHIẾU TỒN ĐƠN HÀNG
 *  Dùng lại bảng long_term_production_plans (module production_staff) +
 *  helper ltp_* (controller đã require model production_staff).
 * =====================================================================*/

/**
 * KHSX dự kiến cho đội bán hàng: ĐỒNG BỘ đúng các ngày của bảng
 * long_term_production_plan (cùng cửa sổ board_start..board_end, cùng cờ
 * is_today/is_past), CHỈ hiển thị sản phẩm (không show việc khác).
 * Trả: [ {date, weekday, display, is_sunday, is_today, is_past, items[]} ].
 */
function sf_get_production_forecast()
{
    $days = ltp_build_plan_window(); // y hệt cửa sổ của long_term_production_plan
    $from = $days[0]['date'];
    $to   = $days[count($days) - 1]['date'];
    $items_by_date = ltp_get_items_grouped($from, $to);

    foreach ($days as &$d) {
        $d['items'] = $items_by_date[$d['date']] ?? [];
    }
    unset($d);
    return $days;
}

/**
 * Đối chiếu tồn nhà máy cho 1 đơn đặt hàng (chỉ xét sản phẩm).
 * $items = [ {product_id, qty}, ... ].
 * Trả: { all_enough, lines: [ {product_id, product_name, unit, order_qty,
 *        stock, shortage, plan:{weekday,display,quantity}|null} ] } — chỉ gồm dòng THIẾU.
 */
function sf_check_order_inventory($items)
{
    $today = date('Y-m-d');
    $t     = escape_string($today);
    $lines = [];

    foreach ((array) $items as $it) {
        $pid = (int) ($it['product_id'] ?? 0);
        $qty = (float) ($it['qty'] ?? 0);
        if ($pid <= 0 || $qty <= 0) continue;

        $p = db_fetch_row("SELECT COALESCE(NULLIF(common_product_name, ''), product_name) AS product_name, unit FROM products WHERE id = $pid LIMIT 1");
        if (!$p) continue;

        $sr    = db_fetch_row("SELECT quantity FROM finished_goods_inventory WHERE product_id = $pid LIMIT 1");
        $stock = $sr ? (float) $sr['quantity'] : 0.0;
        $shortage = $qty - $stock;
        if ($shortage <= 0) continue; // đủ tồn -> bỏ qua

        // Lịch sản xuất kế tiếp (>= hôm nay) cho sản phẩm này, nếu có.
        $plan = db_fetch_row(
            "SELECT plan_date, quantity
             FROM long_term_production_plans
             WHERE product_id = $pid AND plan_date >= '$t'
             ORDER BY plan_date ASC, sort_order ASC, id ASC
             LIMIT 1"
        );
        $plan_info = null;
        if ($plan) {
            $plan_info = [
                'weekday'  => ltp_weekday_vi($plan['plan_date']),
                'display'  => date('j/n/Y', strtotime($plan['plan_date'])),
                'quantity' => (float) $plan['quantity'],
            ];
        }

        $lines[] = [
            'product_id'   => $pid,
            'product_name' => (string) ($p['product_name'] ?: ('#' . $pid)),
            'unit'         => (string) ($p['unit'] ?? ''),
            'order_qty'    => $qty,
            'stock'        => $stock,
            'shortage'     => $shortage,
            'plan'         => $plan_info,
        ];
    }

    return ['all_enough' => empty($lines), 'lines' => $lines];
}
