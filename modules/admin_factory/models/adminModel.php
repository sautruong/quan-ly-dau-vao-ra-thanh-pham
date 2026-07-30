<?php
defined('APPPATH') OR exit('Không được quyền truy cập phần này');

/**
 * Lấy danh sách sản phẩm kèm thông tin liên quan để hiển thị bảng quản lý.
 * Join các bảng: product_categories, product_info_basic, product_weights,
 * branch_product_selling_prices, suppliers.
 */
/**
 * Thêm cột products.common_product_name ("Tên thường gọi") nếu chưa có.
 */
function admin_ensure_product_common_name_column()
{
    $col = db_fetch_row("SELECT COLUMN_NAME FROM information_schema.COLUMNS
                         WHERE TABLE_SCHEMA = DATABASE()
                           AND TABLE_NAME = 'products'
                           AND COLUMN_NAME = 'common_product_name' LIMIT 1");
    if (!$col) {
        db_query("ALTER TABLE products ADD COLUMN common_product_name VARCHAR(255) DEFAULT NULL");
    }
}

/**
 * Thêm cột products.is_new_product ("Sản phẩm mới") nếu chưa có.
 */
function admin_ensure_product_is_new_column()
{
    $col = db_fetch_row("SELECT COLUMN_NAME FROM information_schema.COLUMNS
                         WHERE TABLE_SCHEMA = DATABASE()
                           AND TABLE_NAME = 'products'
                           AND COLUMN_NAME = 'is_new_product' LIMIT 1");
    if (!$col) {
        db_query("ALTER TABLE products ADD COLUMN is_new_product TINYINT(1) NOT NULL DEFAULT 0");
    }
}

function admin_get_product_list()
{
    admin_ensure_product_common_name_column();
    admin_ensure_product_is_new_column();
    $sql = "
        SELECT
            p.id,
            p.product_name,
            p.common_product_name,
            p.unit,
            p.image_url,
            p.category_id,
            p.has_nutrition_fact,
            p.is_new_product,
            p.type,
            p.supplier_id,
            pc.category_name,
            pib.id              AS info_basic_id,
            pib.inner_packaging_spec,
            pib.outer_packaging_spec,
            pw.id               AS weight_id,
            pw.weight_kg,
            bsp.id              AS selling_price_id,
            bsp.selling_price,
            s.supplier_name
        FROM products p
        LEFT JOIN product_categories pc            ON p.category_id  = pc.id
        LEFT JOIN product_info_basic pib           ON pib.product_id = p.id
        LEFT JOIN product_weights pw               ON pw.product_id  = p.id
        LEFT JOIN branch_product_selling_prices bsp ON bsp.product_id = p.id
        LEFT JOIN suppliers s                      ON p.supplier_id  = s.id
        ORDER BY p.id ASC
    ";
    return db_fetch_array($sql);
}

function admin_get_categories()
{
    return db_fetch_array("SELECT id, category_name FROM product_categories ORDER BY category_name ASC");
}

function admin_get_suppliers()
{
    return db_fetch_array("SELECT id, supplier_name FROM suppliers ORDER BY supplier_name ASC");
}

function admin_get_product_by_id($product_id)
{
    $pid = (int) $product_id;
    return db_fetch_row("SELECT * FROM products WHERE id = {$pid}");
}

/**
 * Cập nhật trường trên bảng products.
 */
function admin_update_products_field($product_id, $field, $value)
{
    $allow = ['product_name', 'common_product_name', 'unit', 'image_url', 'category_id', 'has_nutrition_fact', 'is_new_product', 'type', 'supplier_id'];
    if (!in_array($field, $allow, true)) {
        return false;
    }
    if ($field === 'common_product_name') {
        admin_ensure_product_common_name_column();
    }
    if ($field === 'is_new_product') {
        admin_ensure_product_is_new_column();
    }
    $pid = (int) $product_id;
    if ($value === null || $value === '') {
        $data = [$field => null];
    } else {
        $data = [$field => $value];
    }
    return db_update('products', $data, "id = {$pid}");
}

/**
 * Cập nhật hoặc tạo mới bản ghi product_info_basic cho 1 sản phẩm.
 */
function admin_upsert_product_info_basic($product_id, $field, $value)
{
    $allow = ['inner_packaging_spec', 'outer_packaging_spec'];
    if (!in_array($field, $allow, true)) {
        return false;
    }
    $pid    = (int) $product_id;
    $exists = db_fetch_row("SELECT id FROM product_info_basic WHERE product_id = {$pid} LIMIT 1");
    if ($exists) {
        return db_update('product_info_basic', [$field => $value], "product_id = {$pid}");
    }
    return db_insert('product_info_basic', ['product_id' => $pid, $field => $value]);
}

/**
 * Cập nhật hoặc tạo mới khối lượng sản phẩm.
 */
function admin_upsert_product_weight($product_id, $weight_kg)
{
    $pid    = (int) $product_id;
    $w      = (float) $weight_kg;
    $exists = db_fetch_row("SELECT id FROM product_weights WHERE product_id = {$pid} LIMIT 1");
    if ($exists) {
        return db_update('product_weights', ['weight_kg' => $w], "product_id = {$pid}");
    }
    return db_insert('product_weights', ['product_id' => $pid, 'weight_kg' => $w]);
}

/**
 * Cập nhật hoặc tạo mới giá xuất xưởng.
 */
function admin_upsert_selling_price($product_id, $selling_price)
{
    $pid    = (int) $product_id;
    $price  = (float) $selling_price;
    $exists = db_fetch_row("SELECT id FROM branch_product_selling_prices WHERE product_id = {$pid} LIMIT 1");
    if ($exists) {
        return db_update('branch_product_selling_prices', ['selling_price' => $price], "product_id = {$pid}");
    }
    return db_insert('branch_product_selling_prices', ['product_id' => $pid, 'selling_price' => $price]);
}

/**
 * Cập nhật hoặc tạo mới system_price trên product_prices (đồng bộ theo selling_price).
 * product_prices đi theo products (1 row / product) — nếu product chưa có row thì tự thêm.
 */
function admin_upsert_system_price($product_id, $system_price)
{
    $pid    = (int) $product_id;
    $price  = ($system_price === null || $system_price === '') ? null : (float) $system_price;
    $exists = db_fetch_row("SELECT id FROM product_prices WHERE product_id = {$pid} LIMIT 1");
    if ($exists) {
        return db_update('product_prices', ['system_price' => $price], "product_id = {$pid}");
    }
    return db_insert('product_prices', ['product_id' => $pid, 'system_price' => $price]);
}

/**
 * Lưu file ảnh upload vào public/images/product_profile và trả về đường dẫn tương đối
 * (cùng dạng với cột image_url hiện có).
 */
function admin_save_product_image_upload($product_id, $file)
{
    if (!isset($file['error']) || $file['error'] !== UPLOAD_ERR_OK) {
        return ['ok' => false, 'message' => 'Tải file thất bại.'];
    }
    $allowed = ['jpg', 'jpeg', 'png', 'gif', 'webp'];
    $ext     = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
    if (!in_array($ext, $allowed, true)) {
        return ['ok' => false, 'message' => 'Định dạng ảnh không hợp lệ.'];
    }

    $product = admin_get_product_by_id($product_id);
    if (!$product) {
        return ['ok' => false, 'message' => 'Không tìm thấy sản phẩm.'];
    }

    // Thư mục con theo category_code (nếu có) cho gọn gàng — fallback general.
    $cat_code = 'general';
    if (!empty($product['category_id'])) {
        $cat = db_fetch_row("SELECT category_code FROM product_categories WHERE id = " . (int) $product['category_id']);
        if ($cat && !empty($cat['category_code'])) {
            $cat_code = $cat['category_code'];
        }
    }

    $rel_dir = 'product_profile' . DIRECTORY_SEPARATOR . $cat_code;
    $abs_dir = APPPATH . DIRECTORY_SEPARATOR . 'public' . DIRECTORY_SEPARATOR . 'images' . DIRECTORY_SEPARATOR . $rel_dir;
    if (!is_dir($abs_dir)) {
        @mkdir($abs_dir, 0777, true);
    }

    $safe_name = preg_replace('/[^A-Za-z0-9_\-]/', '_', pathinfo($file['name'], PATHINFO_FILENAME));
    $filename  = 'p' . (int) $product_id . '_' . $safe_name . '_' . time() . '.' . $ext;
    $abs_path  = $abs_dir . DIRECTORY_SEPARATOR . $filename;
    if (!move_uploaded_file($file['tmp_name'], $abs_path)) {
        return ['ok' => false, 'message' => 'Không thể lưu file vào máy chủ.'];
    }

    // Xoá file vật lý cũ (nếu nằm trong public/images/product_profile).
    if (!empty($product['image_url'])) {
        $old_rel = str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $product['image_url']);
        $old_abs = APPPATH . DIRECTORY_SEPARATOR . 'public' . DIRECTORY_SEPARATOR . 'images' . DIRECTORY_SEPARATOR . $old_rel;
        if (is_file($old_abs) && strpos($old_abs, 'product_profile') !== false) {
            @unlink($old_abs);
        }
    }

    $relative = str_replace(DIRECTORY_SEPARATOR, '/', $rel_dir . DIRECTORY_SEPARATOR . $filename);
    admin_update_products_field($product_id, 'image_url', $relative);
    return ['ok' => true, 'image_url' => $relative];
}

/* =====================================================================
 *  MATERIALS — manage_material_list
 * =====================================================================*/

function admin_get_material_list()
{
    // Bảo đảm cột phân loại tồn tại (auto-migrate, giống suppliers.address).
    admin_ensure_material_classification_column();
    admin_ensure_material_label_name_column();
    // Bảo đảm mỗi material_information đều có 1 dòng material_purchase_prices
    // (material_information là bảng chính). Sau khi đồng bộ mới đọc danh sách.
    admin_sync_material_purchase_prices();

    // Mỗi material có thể có nhiều dòng giá (lịch sử) — chỉ lấy dòng mới nhất
    // để mỗi material chỉ hiện 1 hàng và là dòng được sửa khi user chỉnh giá.
    $sql = "
        SELECT
            mi.id,
            mi.material_name,
            mi.common_material_name,
            mi.label_name,
            mi.unit,
            mi.classification,
            mi.supplier_id,
            s.supplier_name,
            mpp.purchase_price,
            mpp.purchase_price_includes_purchase_cost,
            bmsp.selling_price
        FROM material_information mi
        LEFT JOIN suppliers s ON mi.supplier_id = s.id
        LEFT JOIN material_purchase_prices mpp
               ON mpp.id = (
                   SELECT m2.id FROM material_purchase_prices m2
                   WHERE m2.material_id = mi.id
                   ORDER BY m2.last_updated_at DESC, m2.id DESC
                   LIMIT 1
               )
        LEFT JOIN branch_material_selling_prices bmsp ON bmsp.material_id = mi.id
        ORDER BY mi.id ASC
    ";
    return db_fetch_array($sql);
}

/**
 * Thêm cột material_information.classification nếu chưa có.
 * Lưu nhãn phân loại: 'Nguyên liệu' | 'Bao bì trong' | 'Bao bì ngoài' | 'Nhãn' | NULL.
 */
function admin_ensure_material_classification_column()
{
    $col = db_fetch_row("SELECT COLUMN_NAME FROM information_schema.COLUMNS
                         WHERE TABLE_SCHEMA = DATABASE()
                           AND TABLE_NAME = 'material_information'
                           AND COLUMN_NAME = 'classification' LIMIT 1");
    if (!$col) {
        db_query("ALTER TABLE material_information ADD COLUMN classification VARCHAR(50) DEFAULT NULL");
    }
}

/**
 * Thêm cột material_information.label_name ("Tên trên nhãn") nếu chưa có.
 * Tên dùng để in lên nhãn sản phẩm (bảng thành phần) — có thể bỏ trống, khi trống thì
 * nơi dùng sẽ lùi về common_material_name rồi material_name.
 */
function admin_ensure_material_label_name_column()
{
    static $done = false;
    if ($done) {
        return;
    }
    $done = true;
    $existed = db_num_rows("SHOW COLUMNS FROM material_information LIKE 'label_name'") > 0;
    if (!$existed) {
        db_query("ALTER TABLE material_information ADD COLUMN label_name VARCHAR(255) DEFAULT NULL");
    }
}

/**
 * Đồng bộ material_purchase_prices theo material_information (bảng chính):
 * mỗi material_information có id nhưng chưa có dòng material_purchase_prices nào
 * thì tự thêm 1 dòng mặc định (purchase_price = 0, includes CPMH = 0).
 */
function admin_sync_material_purchase_prices()
{
    $missing = db_fetch_array("
        SELECT mi.id
        FROM material_information mi
        LEFT JOIN material_purchase_prices mpp ON mpp.material_id = mi.id
        WHERE mpp.id IS NULL
    ");
    if (!is_array($missing) || empty($missing)) {
        return;
    }
    foreach ($missing as $row) {
        $mid = (int) $row['id'];
        if ($mid <= 0) {
            continue;
        }
        admin_safe_insert('material_purchase_prices', [
            'material_id'                           => $mid,
            'purchase_price'                        => 0,
            'purchase_price_includes_purchase_cost' => 0,
        ]);
    }
}

function admin_search_suppliers_by_name($keyword, $limit = 20)
{
    $kw    = escape_string(trim((string) $keyword));
    $limit = max(1, min(50, (int) $limit));
    if ($kw === '') {
        return db_fetch_array("SELECT id, supplier_name FROM suppliers ORDER BY supplier_name ASC LIMIT {$limit}");
    }
    return db_fetch_array("
        SELECT id, supplier_name
        FROM suppliers
        WHERE supplier_name LIKE '%{$kw}%'
        ORDER BY supplier_name ASC
        LIMIT {$limit}
    ");
}

/* ---------------------------------------------------------------------
 *  SEARCH theo tên cho bộ lọc cột (.btn-filter) ở 5 view dữ liệu.
 *  Mỗi hàm trả mảng [{id, name}] (đã chuẩn hóa khóa 'id','name').
 * ------------------------------------------------------------------- */

function admin_search_products_by_name($keyword, $limit = 20)
{
    $kw    = escape_string(trim((string) $keyword));
    $limit = max(1, min(50, (int) $limit));
    $where = $kw === '' ? '' : "WHERE product_name LIKE '%{$kw}%'";
    return db_fetch_array("SELECT id, product_name AS name FROM products {$where}
                           ORDER BY product_name ASC LIMIT {$limit}");
}

function admin_search_materials_by_name($keyword, $limit = 20)
{
    $kw    = escape_string(trim((string) $keyword));
    $limit = max(1, min(50, (int) $limit));
    $where = $kw === '' ? '' : "WHERE material_name LIKE '%{$kw}%'";
    return db_fetch_array("SELECT id, material_name AS name FROM material_information {$where}
                           ORDER BY material_name ASC LIMIT {$limit}");
}

function admin_search_customers_by_name($keyword, $limit = 20)
{
    $kw    = escape_string(trim((string) $keyword));
    $limit = max(1, min(50, (int) $limit));
    $where = $kw === '' ? '' : "WHERE name LIKE '%{$kw}%'";
    return db_fetch_array("SELECT id, name FROM customers {$where}
                           ORDER BY name ASC LIMIT {$limit}");
}

/**
 * "Hàng hóa" = sản phẩm + NVL gộp lại (cột "Tên hàng hóa" của xuất bán).
 * value trả về dạng "product:ID" / "material:ID" để lọc đúng cột nguồn.
 */
function admin_search_goods_by_name($keyword, $limit = 20)
{
    $prods = admin_search_products_by_name($keyword, $limit);
    $mats  = admin_search_materials_by_name($keyword, $limit);
    $out   = [];
    foreach ($prods as $p) {
        $out[] = ['value' => 'product:' . (int) $p['id'], 'name' => $p['name'] . ' (SP)'];
    }
    foreach ($mats as $m) {
        $out[] = ['value' => 'material:' . (int) $m['id'], 'name' => $m['name'] . ' (NVL)'];
    }
    return $out;
}

/**
 * Dispatcher dùng cho AJAX: trả list [{value, name}] theo loại bộ lọc.
 * 'value' là giá trị gán vào hidden input của form lọc.
 */
function admin_search_filter_options($type, $keyword, $limit = 20)
{
    switch ($type) {
        case 'products':
            $rows = admin_search_products_by_name($keyword, $limit);
            break;
        case 'materials':
            $rows = admin_search_materials_by_name($keyword, $limit);
            break;
        case 'customers':
            $rows = admin_search_customers_by_name($keyword, $limit);
            break;
        case 'suppliers':
            $rows = admin_search_suppliers_by_name($keyword, $limit);
            break;
        case 'goods':
            return admin_search_goods_by_name($keyword, $limit);
        default:
            return [];
    }
    // Chuẩn hóa {value, name}: value = id, name = cột tên tương ứng.
    $out = [];
    foreach ($rows as $r) {
        $name = $r['name'] ?? ($r['supplier_name'] ?? '');
        $out[] = ['value' => (string) (int) $r['id'], 'name' => (string) $name];
    }
    return $out;
}

function admin_material_classification_options()
{
    return ['Nguyên liệu', 'Bao bì trong', 'Bao bì ngoài', 'Nhãn'];
}

function admin_update_material_field($material_id, $field, $value)
{
    $allow = ['material_name', 'common_material_name', 'label_name', 'unit', 'supplier_id', 'classification'];
    if (!in_array($field, $allow, true)) {
        return false;
    }
    if ($field === 'label_name') {
        admin_ensure_material_label_name_column();
    }
    $mid = (int) $material_id;
    if ($field === 'supplier_id') {
        $value = ((int) $value > 0) ? (int) $value : null;
    } elseif ($field === 'classification') {
        // Chỉ chấp nhận 1 trong các nhãn hợp lệ, ngược lại để trống (NULL).
        $value = in_array($value, admin_material_classification_options(), true) ? (string) $value : null;
    } else {
        $value = $value === null ? null : (string) $value;
    }
    return db_update('material_information', [$field => $value], "id = {$mid}");
}

function admin_upsert_material_purchase_price($material_id, $field, $value)
{
    $allow = ['purchase_price', 'purchase_price_includes_purchase_cost'];
    if (!in_array($field, $allow, true)) {
        return false;
    }
    $mid = (int) $material_id;
    // Cả 2 cột đều NOT NULL — để trống quy về 0.
    $v   = ($value === '' || $value === null) ? 0 : (float) $value;

    // Cập nhật đúng dòng giá mới nhất của material (cùng dòng đang hiển thị),
    // không đụng tới các dòng lịch sử khác.
    $latest = db_fetch_row("SELECT id FROM material_purchase_prices
                            WHERE material_id = {$mid}
                            ORDER BY last_updated_at DESC, id DESC
                            LIMIT 1");
    if ($latest) {
        return db_update('material_purchase_prices', [$field => $v], "id = " . (int) $latest['id']);
    }
    // Chưa có dòng nào → tạo mới, cột còn lại để 0.
    $data = [
        'material_id'                           => $mid,
        'purchase_price'                        => 0,
        'purchase_price_includes_purchase_cost' => 0,
    ];
    $data[$field] = $v;
    return db_insert('material_purchase_prices', $data);
}

function admin_upsert_material_selling_price($material_id, $selling_price)
{
    $mid    = (int) $material_id;
    $v      = (float) $selling_price;
    $exists = db_fetch_row("SELECT id FROM branch_material_selling_prices WHERE material_id = {$mid} LIMIT 1");
    if ($exists) {
        return db_update('branch_material_selling_prices', ['selling_price' => $v], "material_id = {$mid}");
    }
    return db_insert('branch_material_selling_prices', ['material_id' => $mid, 'selling_price' => $v]);
}

/* =====================================================================
 *  CUSTOMERS — manage_customer_list
 * =====================================================================*/

function admin_get_customer_list()
{
    return db_fetch_array("SELECT id, name, short_name, address, receiver, phone, secondary_color FROM customers ORDER BY id ASC");
}

function admin_update_customer_field($customer_id, $field, $value)
{
    // secondary_color: mã màu nhận diện chi nhánh (mặc định đen cho khách lẻ).
    $allow = ['name', 'short_name', 'address', 'receiver', 'phone', 'secondary_color'];
    if (!in_array($field, $allow, true)) {
        return false;
    }
    $cid = (int) $customer_id;
    // name NOT NULL — không cho trống.
    if ($field === 'name' && trim((string) $value) === '') {
        return false;
    }
    // Chuẩn hóa màu về dạng #RRGGBB; rỗng/sai → về đen.
    if ($field === 'secondary_color') {
        $value = admin_normalize_hex_color($value);
    }
    return db_update('customers', [$field => (string) $value], "id = {$cid}");
}

/** Chuẩn hóa mã màu HEX về '#rrggbb' (chữ thường). Không hợp lệ → '#000000'. */
function admin_normalize_hex_color($value)
{
    $v = strtolower(trim((string) $value));
    if (preg_match('/^#?([0-9a-f]{6})$/', $v, $m)) {
        return '#' . $m[1];
    }
    return '#000000';
}

/* =====================================================================
 *  SUPPLIERS — manage_supplier_list
 * =====================================================================*/

function admin_ensure_supplier_address_column()
{
    static $done = false;
    if ($done) return;
    $done = true;
    db_query("ALTER TABLE suppliers ADD COLUMN IF NOT EXISTS address VARCHAR(255) DEFAULT NULL");
}

/** Cột "Tên viết tắt" (dùng cho views Sự kiện nhà máy); mặc định backfill = supplier_name. */
function admin_ensure_supplier_short_name_column()
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

function admin_get_supplier_list()
{
    admin_ensure_supplier_address_column();
    admin_ensure_supplier_short_name_column();
    return db_fetch_array("SELECT id, supplier_name, short_name, address, email, phone_number, website FROM suppliers ORDER BY id ASC");
}

function admin_update_supplier_field($supplier_id, $field, $value)
{
    admin_ensure_supplier_address_column();
    admin_ensure_supplier_short_name_column();
    $allow = ['supplier_name', 'short_name', 'address', 'email', 'phone_number', 'website'];
    if (!in_array($field, $allow, true)) {
        return false;
    }
    $sid = (int) $supplier_id;
    return db_update('suppliers', [$field => (string) $value], "id = {$sid}");
}

/* =====================================================================
 *  USERS — manage_user_list (Quản lý người dùng)
 * =====================================================================*/

/** Trạng thái hợp lệ của tài khoản. Chỉ 'active' mới đăng nhập được. */
function admin_user_status_options()
{
    return [
        'active'   => 'Đang hoạt động',
        'pending'  => 'Chờ kích hoạt',
        'inactive' => 'Ngưng hoạt động',
    ];
}

/**
 * Dọn tài khoản rác (đồng bộ với module auth):
 *  - 'pending' quá 24h chưa kích hoạt (theo created_at)
 *  - 'inactive' (Ngưng hoạt động) quá 24h kể từ lúc bị ngưng (theo status_changed_at)
 */
function admin_purge_expired_pending_users()
{
    $limit = date('Y-m-d H:i:s', time() - 24 * 60 * 60);
    $deleted = db_delete(
        'tbl_users',
        "status = 'pending' AND created_at IS NOT NULL AND created_at < '{$limit}'"
    );
    $deleted += db_delete(
        'tbl_users',
        "status = 'inactive' AND status_changed_at IS NOT NULL AND status_changed_at < '{$limit}'"
    );
    // 'left' (Đã rời hệ thống) quá 24h -> xóa hẳn tài khoản.
    $left = db_fetch_array(
        "SELECT id FROM tbl_users
         WHERE status = 'left' AND status_changed_at IS NOT NULL AND status_changed_at < '{$limit}'"
    ) ?: [];
    foreach ($left as $r) {
        $uid = (int) $r['id'];
        if (function_exists('lifecycle_purge_personal_data')) {
            lifecycle_purge_personal_data($uid);   // dọn nốt dữ liệu cá nhân hóa còn sót
        }
        $deleted += db_delete('tbl_users', "id = {$uid}");
    }
    return $deleted;
}

/* =====================================================================
 *  PHÂN LOẠI USER (Phòng sản xuất / Kinh doanh / Ban giám đốc ...).
 *  Catalog dùng chung: user_classifications. tbl_users.classification_id trỏ tới.
 * =====================================================================*/

/** Tạo bảng catalog + cột classification_id trên tbl_users (1 lần / request). */
function admin_ensure_user_classification_schema()
{
    static $done = false;
    if ($done) return;
    $done = true;
    db_query("CREATE TABLE IF NOT EXISTS user_classifications (
        id         INT(11) NOT NULL AUTO_INCREMENT,
        name       VARCHAR(100) NOT NULL,
        created_at DATETIME NOT NULL,
        PRIMARY KEY (id),
        UNIQUE KEY uq_name (name)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci");
    $col = db_fetch_row("SHOW COLUMNS FROM tbl_users LIKE 'classification_id'");
    if (!$col) {
        db_query("ALTER TABLE tbl_users ADD classification_id INT(11) NOT NULL DEFAULT 0");
    }
}

/** Danh sách phân loại sẵn có: [ {id, name}, ... ]. */
function admin_get_user_classifications()
{
    admin_ensure_user_classification_schema();
    return db_fetch_array("SELECT id, name FROM user_classifications ORDER BY name ASC") ?: [];
}

/** Tìm id theo tên (không phân biệt hoa thường); $create=true thì tạo mới nếu chưa có. */
function admin_classification_id_by_name($name, $create = false)
{
    admin_ensure_user_classification_schema();
    $name = trim((string) $name);
    if ($name === '') return 0;
    if (mb_strlen($name, 'UTF-8') > 100) $name = mb_substr($name, 0, 100, 'UTF-8');
    $esc = escape_string($name);
    $row = db_fetch_row("SELECT id FROM user_classifications WHERE LOWER(name) = LOWER('{$esc}') LIMIT 1");
    if ($row) return (int) $row['id'];
    if (!$create) return 0;
    db_insert('user_classifications', ['name' => $name, 'created_at' => date('Y-m-d H:i:s')]);
    $row = db_fetch_row("SELECT id FROM user_classifications WHERE LOWER(name) = LOWER('{$esc}') LIMIT 1");
    return $row ? (int) $row['id'] : 0;
}

/**
 * Gán phân loại cho 1 user theo TÊN. Trả mảng kết quả.
 *  - name rỗng -> gỡ phân loại (classification_id = 0).
 *  - name mới + $allow_create=false -> ['ok'=>false,'is_new'=>true] để FE hỏi xác nhận.
 */
function admin_set_user_classification($user_id, $name, $allow_create = false)
{
    admin_ensure_user_classification_schema();
    $uid  = (int) $user_id;
    $name = trim((string) $name);
    if ($uid <= 0) return ['ok' => false, 'message' => 'Thiếu người dùng.'];
    if ($name === '') {
        db_update('tbl_users', ['classification_id' => 0], "id = {$uid}");
        return ['ok' => true, 'id' => 0, 'name' => ''];
    }
    $cid = admin_classification_id_by_name($name, false);
    if ($cid <= 0) {
        if (!$allow_create) return ['ok' => false, 'is_new' => true, 'name' => $name];
        $cid = admin_classification_id_by_name($name, true);
        if ($cid <= 0) return ['ok' => false, 'message' => 'Không tạo được phân loại.'];
    }
    db_update('tbl_users', ['classification_id' => $cid], "id = {$uid}");
    $row = db_fetch_row("SELECT name FROM user_classifications WHERE id = {$cid} LIMIT 1");
    return ['ok' => true, 'id' => $cid, 'name' => $row ? $row['name'] : $name, 'created' => true];
}

function admin_get_user_list()
{
    // Trước khi liệt kê, dọn tài khoản rác để danh sách luôn chính xác.
    admin_purge_expired_pending_users();
    admin_ensure_user_classification_schema();
    return db_fetch_array(
        "SELECT u.id, u.fullname, u.dateofbirth, u.gender, u.phone, u.email, u.username,
                u.status, u.avatar, u.role, u.classification_id,
                c.name AS classification_name
         FROM tbl_users u
         LEFT JOIN user_classifications c ON c.id = u.classification_id
         WHERE u.status IS NULL OR u.status <> 'system'
         ORDER BY u.id ASC"
    );
}

/** Cập nhật trạng thái 1 tài khoản. Trả về false nếu trạng thái không hợp lệ. */
function admin_update_user_status($user_id, $status)
{
    $status = (string) $status;
    if (!array_key_exists($status, admin_user_status_options())) {
        return false;
    }
    $uid = (int) $user_id;
    // Ghi lại mốc đổi trạng thái: dùng để tự xóa tài khoản 'inactive' quá 24h.
    return db_update('tbl_users', [
        'status'            => $status,
        'status_changed_at' => date('Y-m-d H:i:s'),
    ], "id = {$uid}");
}

/* =====================================================================
 *  CHUYỂN QUYỀN ADMIN — gửi OTP tới admin hiện tại, khớp mới chuyển.
 * =====================================================================*/

/** Lấy 1 user theo id (id, fullname, username, email, status, role). */
function admin_get_user_by_id($user_id)
{
    $uid = (int) $user_id;
    if ($uid <= 0) return null;
    return db_fetch_row(
        "SELECT id, fullname, username, email, status, role
         FROM tbl_users WHERE id = {$uid} LIMIT 1"
    ) ?: null;
}

/** User đủ điều kiện làm admin mới: đang active, chưa phải admin, khác admin hiện tại. */
function admin_get_active_users_for_transfer($exclude_id = 0)
{
    $ex = (int) $exclude_id;
    return db_fetch_array(
        "SELECT id, fullname, username, email
         FROM tbl_users
         WHERE status = 'active' AND role <> 'admin' AND id <> {$ex}
         ORDER BY fullname ASC, id ASC"
    ) ?: [];
}

/** Sinh mã OTP 6 ký tự chữ HOA + số (bỏ ký tự dễ nhầm: 0/O, 1/I). */
function admin_generate_otp($length = 6)
{
    $alphabet = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';
    $max = strlen($alphabet) - 1;
    $code = '';
    for ($i = 0; $i < $length; $i++) {
        $code .= $alphabet[random_int(0, $max)];
    }
    return $code;
}

/** Che bớt email cho thông báo: abc@x.com -> a***@x.com. */
function admin_mask_email($email)
{
    $email = (string) $email;
    $at = strpos($email, '@');
    if ($at === false) return $email;
    $name = substr($email, 0, $at);
    $dom  = substr($email, $at);
    $keep = $name !== '' ? substr($name, 0, 1) : '';
    return $keep . '***' . $dom;
}

/**
 * Chuyển quyền admin: thăng $to_user_id thành admin, hạ $from_admin_id xuống 'user'.
 * Kiểm tra: tân binh đang active & chưa là admin; người chuyển đang là admin.
 * Trả mảng ['ok'=>bool, 'message'=>...].
 */
function admin_transfer_role($from_admin_id, $to_user_id)
{
    $from = (int) $from_admin_id;
    $to   = (int) $to_user_id;
    if ($from <= 0 || $to <= 0 || $from === $to) {
        return ['ok' => false, 'message' => 'Tham số chuyển quyền không hợp lệ.'];
    }
    $cur = admin_get_user_by_id($from);
    if (!$cur || $cur['role'] !== 'admin') {
        return ['ok' => false, 'message' => 'Tài khoản hiện tại không phải admin.'];
    }
    $target = admin_get_user_by_id($to);
    if (!$target) {
        return ['ok' => false, 'message' => 'Không tìm thấy tài khoản admin mới.'];
    }
    if ($target['status'] !== 'active') {
        return ['ok' => false, 'message' => 'Admin mới phải là tài khoản đang hoạt động (active).'];
    }
    if ($target['role'] === 'admin') {
        return ['ok' => false, 'message' => 'Tài khoản này đã là admin.'];
    }
    db_update('tbl_users', ['role' => 'admin'], "id = {$to}");
    db_update('tbl_users', ['role' => 'user'],  "id = {$from}");
    return ['ok' => true, 'message' => 'Đã chuyển quyền admin.'];
}

/* =====================================================================
 *  SAFE QUERY — chạy DELETE/INSERT mà không exit khi FK constraint chặn.
 * =====================================================================*/

function admin_safe_query($sql)
{
    global $conn;
    $r = @mysqli_query($conn, $sql);
    if (!$r) {
        return ['ok' => false, 'error' => mysqli_error($conn)];
    }
    return ['ok' => true];
}

function admin_safe_insert($table, $data)
{
    global $conn;
    $fields = "(" . implode(", ", array_keys($data)) . ")";
    $values = "";
    foreach ($data as $field => $value) {
        if ($value === null) {
            $values .= "NULL, ";
        } else {
            $values .= "'" . mysqli_real_escape_string($conn, $value) . "', ";
        }
    }
    $values = substr($values, 0, -2);
    $sql    = "INSERT INTO {$table} {$fields} VALUES({$values})";
    $ok     = @mysqli_query($conn, $sql);
    if (!$ok) {
        return ['ok' => false, 'error' => mysqli_error($conn)];
    }
    return ['ok' => true, 'insert_id' => mysqli_insert_id($conn)];
}

/* =====================================================================
 *  CREATE — sản phẩm
 * =====================================================================*/

function admin_create_product($data, $image_file = null)
{
    $name = trim((string) ($data['product_name'] ?? ''));
    if ($name === '') {
        return ['ok' => false, 'message' => 'Tên sản phẩm không được trống.'];
    }
    $type = (string) ($data['type'] ?? 'Sản xuất');
    if (!in_array($type, ['Sản xuất', 'Thương mại'], true)) {
        $type = 'Sản xuất';
    }
    $cat_id = (int) ($data['category_id'] ?? 0);
    $sup_id = ($type === 'Thương mại') ? (int) ($data['supplier_id'] ?? 0) : 0;
    $has_nu = ((string) ($data['has_nutrition_fact'] ?? '0') === '1') ? 1 : 0;
    $is_new = ((string) ($data['is_new_product'] ?? '0') === '1') ? 1 : 0;

    admin_ensure_product_is_new_column();
    $row = [
        'product_name'       => $name,
        'unit'               => trim((string) ($data['unit'] ?? '')),
        'category_id'        => $cat_id > 0 ? $cat_id : null,
        'image_url'          => null,
        'has_nutrition_fact' => $has_nu,
        'is_new_product'     => $is_new,
        'type'               => $type,
        'supplier_id'        => $sup_id > 0 ? $sup_id : null,
    ];
    $r = admin_safe_insert('products', $row);
    if (!$r['ok']) {
        return ['ok' => false, 'message' => 'Không thể tạo sản phẩm: ' . $r['error']];
    }
    $pid = (int) $r['insert_id'];

    $inner = trim((string) ($data['inner_packaging_spec'] ?? ''));
    $outer = trim((string) ($data['outer_packaging_spec'] ?? ''));
    if ($inner !== '' || $outer !== '') {
        admin_safe_insert('product_info_basic', [
            'product_id'           => $pid,
            'inner_packaging_spec' => $inner !== '' ? $inner : null,
            'outer_packaging_spec' => $outer !== '' ? $outer : null,
        ]);
    }

    if (isset($data['weight_kg']) && $data['weight_kg'] !== '') {
        admin_safe_insert('product_weights', [
            'product_id' => $pid,
            'weight_kg'  => (float) $data['weight_kg'],
        ]);
    }

    $selling_price = (isset($data['selling_price']) && $data['selling_price'] !== '') ? (float) $data['selling_price'] : null;
    if ($selling_price !== null) {
        admin_safe_insert('branch_product_selling_prices', [
            'product_id'    => $pid,
            'selling_price' => $selling_price,
        ]);
    }

    // product_prices đi theo products (bảng chính) — luôn tạo 1 row, system_price = selling_price.
    admin_safe_insert('product_prices', [
        'product_id'   => $pid,
        'system_price' => $selling_price,
    ]);

    // Upload ảnh nếu user chọn file.
    if (is_array($image_file) && isset($image_file['error']) && $image_file['error'] === UPLOAD_ERR_OK) {
        admin_save_product_image_upload($pid, $image_file);
    }

    return ['ok' => true, 'id' => $pid];
}

/* =====================================================================
 *  CREATE — material
 * =====================================================================*/

function admin_create_material($data)
{
    $name = trim((string) ($data['material_name'] ?? ''));
    if ($name === '') {
        return ['ok' => false, 'message' => 'Tên nguyên vật liệu không được trống.'];
    }
    admin_ensure_material_classification_column();
    admin_ensure_material_label_name_column();
    $supplier_id    = (int) ($data['supplier_id'] ?? 0);
    $classification = (string) ($data['classification'] ?? '');
    $classification = in_array($classification, admin_material_classification_options(), true) ? $classification : null;
    $label_name     = trim((string) ($data['label_name'] ?? ''));
    $row = [
        'material_name'  => $name,
        'label_name'     => $label_name !== '' ? $label_name : null,
        'unit'           => trim((string) ($data['unit'] ?? '')),
        'classification' => $classification,
        'supplier_id'    => $supplier_id > 0 ? $supplier_id : null,
    ];
    $r = admin_safe_insert('material_information', $row);
    if (!$r['ok']) {
        return ['ok' => false, 'message' => 'Không thể tạo NVL: ' . $r['error']];
    }
    $mid = (int) $r['insert_id'];

    // Luôn tạo 1 dòng material_purchase_prices đồng bộ với material vừa thêm
    // (material_information là bảng chính). 2 cột giá đều NOT NULL → để trống = 0.
    $has_pp     = isset($data['purchase_price']) && $data['purchase_price'] !== '';
    $has_pp_inc = isset($data['purchase_price_includes_purchase_cost']) && $data['purchase_price_includes_purchase_cost'] !== '';
    admin_safe_insert('material_purchase_prices', [
        'material_id'                           => $mid,
        'purchase_price'                        => $has_pp ? (float) $data['purchase_price'] : 0,
        'purchase_price_includes_purchase_cost' => $has_pp_inc ? (float) $data['purchase_price_includes_purchase_cost'] : 0,
    ]);

    if (isset($data['selling_price']) && $data['selling_price'] !== '') {
        admin_safe_insert('branch_material_selling_prices', [
            'material_id'    => $mid,
            'selling_price'  => (float) $data['selling_price'],
        ]);
    }
    return ['ok' => true, 'id' => $mid];
}

/* =====================================================================
 *  CREATE — customer
 * =====================================================================*/

function admin_create_customer($data)
{
    $name = trim((string) ($data['name'] ?? ''));
    if ($name === '') {
        return ['ok' => false, 'message' => 'Tên khách hàng không được trống.'];
    }
    $row = [
        'name'            => $name,
        'short_name'      => trim((string) ($data['short_name'] ?? '')),
        'address'         => trim((string) ($data['address'] ?? '')),
        'receiver'        => trim((string) ($data['receiver'] ?? '')),
        'phone'           => trim((string) ($data['phone'] ?? '')),
        'secondary_color' => admin_normalize_hex_color($data['secondary_color'] ?? '#000000'),
    ];
    $r = admin_safe_insert('customers', $row);
    if (!$r['ok']) {
        return ['ok' => false, 'message' => 'Không thể tạo khách hàng: ' . $r['error']];
    }
    return ['ok' => true, 'id' => (int) $r['insert_id']];
}

/* =====================================================================
 *  CREATE — supplier
 * =====================================================================*/

function admin_create_supplier($data)
{
    admin_ensure_supplier_address_column();
    admin_ensure_supplier_short_name_column();
    $name = trim((string) ($data['supplier_name'] ?? ''));
    if ($name === '') {
        return ['ok' => false, 'message' => 'Tên nhà cung cấp không được trống.'];
    }
    $row = [
        'supplier_name' => $name,
        'short_name'    => trim((string) ($data['short_name'] ?? '')) ?: $name,
        'address'       => trim((string) ($data['address'] ?? '')),
        'email'         => trim((string) ($data['email'] ?? '')),
        'phone_number'  => trim((string) ($data['phone_number'] ?? '')),
        'website'       => trim((string) ($data['website'] ?? '')),
    ];
    $r = admin_safe_insert('suppliers', $row);
    if (!$r['ok']) {
        return ['ok' => false, 'message' => 'Không thể tạo nhà cung cấp: ' . $r['error']];
    }
    return ['ok' => true, 'id' => (int) $r['insert_id']];
}

/* =====================================================================
 *  DELETE — sản phẩm, material, customer, supplier
 *  Xoá các bảng phụ "thuộc về" entity trước, rồi xoá bản ghi chính.
 *  Nếu bản ghi chính đang được tham chiếu bởi bảng nghiệp vụ khác,
 *  FK constraint sẽ chặn và trả về thông báo cho user.
 * =====================================================================*/

function admin_delete_product($id)
{
    $id = (int) $id;
    $p  = admin_get_product_by_id($id);
    if (!$p) {
        return ['ok' => false, 'message' => 'Sản phẩm không tồn tại.'];
    }

    // Bảng phụ 1-N "thuộc về" sản phẩm.
    admin_safe_query("DELETE FROM product_info_basic            WHERE product_id = {$id}");
    admin_safe_query("DELETE FROM product_weights               WHERE product_id = {$id}");
    admin_safe_query("DELETE FROM branch_product_selling_prices WHERE product_id = {$id}");
    admin_safe_query("DELETE FROM product_prices                WHERE product_id = {$id}");
    admin_safe_query("DELETE FROM product_purchase_prices       WHERE product_id = {$id}");
    admin_safe_query("DELETE FROM product_files                 WHERE product_id = {$id}");
    admin_safe_query("DELETE FROM product_materials             WHERE product_id = {$id}");
    admin_safe_query("DELETE FROM outer_packaging_specifications WHERE product_id = {$id}");
    admin_safe_query("DELETE FROM pricing_policies              WHERE product_id = {$id}");

    $r = admin_safe_query("DELETE FROM products WHERE id = {$id}");
    if (!$r['ok']) {
        return ['ok' => false, 'message' => 'Không thể xoá sản phẩm — đang được sử dụng ở bảng nghiệp vụ khác.'];
    }

    // Xoá file ảnh vật lý nếu nằm trong public/images/product_profile.
    if (!empty($p['image_url'])) {
        $rel = str_replace(['/', '\\'], DIRECTORY_SEPARATOR, (string) $p['image_url']);
        $abs = APPPATH . DIRECTORY_SEPARATOR . 'public' . DIRECTORY_SEPARATOR . 'images' . DIRECTORY_SEPARATOR . $rel;
        if (is_file($abs) && strpos($abs, 'product_profile') !== false) {
            @unlink($abs);
        }
    }
    return ['ok' => true];
}

function admin_delete_material($id)
{
    $id = (int) $id;
    admin_safe_query("DELETE FROM material_purchase_prices       WHERE material_id = {$id}");
    admin_safe_query("DELETE FROM branch_material_selling_prices WHERE material_id = {$id}");
    admin_safe_query("DELETE FROM material_supplier_map          WHERE material_id = {$id}");
    $r = admin_safe_query("DELETE FROM material_information WHERE id = {$id}");
    if (!$r['ok']) {
        return ['ok' => false, 'message' => 'Không thể xoá NVL — đang được sử dụng ở bảng nghiệp vụ khác.'];
    }
    return ['ok' => true];
}

function admin_delete_customer($id)
{
    $id = (int) $id;
    $r  = admin_safe_query("DELETE FROM customers WHERE id = {$id}");
    if (!$r['ok']) {
        return ['ok' => false, 'message' => 'Không thể xoá khách hàng — đang được sử dụng ở phiếu bán hàng / xuất kho.'];
    }
    return ['ok' => true];
}

function admin_delete_supplier($id)
{
    $id = (int) $id;
    $r  = admin_safe_query("DELETE FROM suppliers WHERE id = {$id}");
    if (!$r['ok']) {
        return ['ok' => false, 'message' => 'Không thể xoá nhà cung cấp — đang được sử dụng ở phiếu nhập / NVL.'];
    }
    return ['ok' => true];
}

/* =====================================================================
 *  DATA-AGGREGATION TABLES (read-only) — 7 view trong admin_factory.
 *  Mỗi hàm trả về toàn bộ dataset (đã join sang tên product/material/
 *  supplier/customer), ORDER BY created_at DESC. View sẽ render hết
 *  ra <tbody>, JS lo paginate client-side.
 *
 *  Lọc theo khoảng ngày: nhận $from, $to dạng 'YYYY-MM-DD' (rỗng = bỏ qua).
 * =====================================================================*/

/**
 * Sinh mệnh đề lọc ngày an toàn cho 1 cột thời gian.
 * Trả về chuỗi điều kiện (không kèm WHERE/AND) hoặc '' nếu không lọc.
 */
function admin_date_filter_cond($column, $from, $to)
{
    $cond = [];
    $from = trim((string) $from);
    $to   = trim((string) $to);
    if ($from !== '' && preg_match('/^\d{4}-\d{2}-\d{2}$/', $from)) {
        $cond[] = "DATE({$column}) >= '" . escape_string($from) . "'";
    }
    if ($to !== '' && preg_match('/^\d{4}-\d{2}-\d{2}$/', $to)) {
        $cond[] = "DATE({$column}) <= '" . escape_string($to) . "'";
    }
    return $cond ? implode(' AND ', $cond) : '';
}

function admin_get_finished_product_production_data($from = '', $to = '', $product_id = 0)
{
    $cond = [];
    if ($c = admin_date_filter_cond('f.created_at', $from, $to)) $cond[] = $c;
    if ((int) $product_id > 0) $cond[] = "f.product_id = " . (int) $product_id;
    $where = $cond ? "WHERE " . implode(' AND ', $cond) : '';
    $sql = "SELECT f.id, f.product_id, f.quantity, f.production_cost, f.created_at,
                   p.product_name
            FROM finished_product_production_data f
            LEFT JOIN products p ON p.id = f.product_id
            {$where}
            ORDER BY f.created_at DESC, f.id DESC";
    return db_fetch_array($sql) ?: [];
}

function admin_get_purchased_finished_product_data($from = '', $to = '', $product_id = 0, $supplier_id = 0)
{
    $cond = [];
    if ($c = admin_date_filter_cond('f.created_at', $from, $to)) $cond[] = $c;
    if ((int) $product_id > 0)  $cond[] = "f.product_id = " . (int) $product_id;
    if ((int) $supplier_id > 0) $cond[] = "f.supplier_id = " . (int) $supplier_id;
    $where = $cond ? "WHERE " . implode(' AND ', $cond) : '';
    $sql = "SELECT f.id, f.product_id, f.supplier_id, f.quantity, f.unit_price,
                   f.amount, f.other_cost, f.total_inventory_value, f.created_at,
                   p.product_name, s.supplier_name
            FROM purchased_finished_product_data f
            LEFT JOIN products  p ON p.id = f.product_id
            LEFT JOIN suppliers s ON s.id = f.supplier_id
            {$where}
            ORDER BY f.created_at DESC, f.id DESC";
    return db_fetch_array($sql) ?: [];
}

function admin_get_raw_material_purchase_data($from = '', $to = '', $material_id = 0, $supplier_id = 0)
{
    $cond = [];
    if ($c = admin_date_filter_cond('r.created_at', $from, $to)) $cond[] = $c;
    if ((int) $material_id > 0) $cond[] = "r.material_id = " . (int) $material_id;
    if ((int) $supplier_id > 0) $cond[] = "r.supplier_id = " . (int) $supplier_id;
    $where = $cond ? "WHERE " . implode(' AND ', $cond) : '';
    $sql = "SELECT r.id, r.material_id, r.supplier_id, r.quantity, r.unit_price,
                   r.amount, r.vat_amount, r.other_cost, r.total_inventory_value, r.created_at,
                   m.material_name, s.supplier_name
            FROM raw_material_purchase_data r
            LEFT JOIN material_information m ON m.id = r.material_id
            LEFT JOIN suppliers s            ON s.id = r.supplier_id
            {$where}
            ORDER BY r.created_at DESC, r.id DESC";
    return db_fetch_array($sql) ?: [];
}

function admin_get_raw_material_production_issue_data($from = '', $to = '', $material_id = 0, $product_id = 0)
{
    $cond = [];
    if ($c = admin_date_filter_cond('r.created_at', $from, $to)) $cond[] = $c;
    if ((int) $material_id > 0) $cond[] = "r.material_id = " . (int) $material_id;
    if ((int) $product_id > 0)  $cond[] = "r.product_id = " . (int) $product_id;
    $where = $cond ? "WHERE " . implode(' AND ', $cond) : '';
    $sql = "SELECT r.id, r.material_id, r.product_id, r.quantity, r.unit_price, r.amount, r.created_at,
                   m.material_name, p.product_name
            FROM raw_material_production_issue_data r
            LEFT JOIN material_information m ON m.id = r.material_id
            LEFT JOIN products             p ON p.id = r.product_id
            {$where}
            ORDER BY r.created_at DESC, r.id DESC";
    return db_fetch_array($sql) ?: [];
}

function admin_get_sale_inventory_issue_data($from = '', $to = '', $goods = '', $customer_id = 0)
{
    $cond = [];
    if ($c = admin_date_filter_cond('s.created_at', $from, $to)) $cond[] = $c;
    // $goods dạng "product:5" hoặc "material:3" (cột "Tên hàng hóa" có thể là SP hoặc NVL).
    if (is_string($goods) && strpos($goods, ':') !== false) {
        list($g_type, $g_id) = explode(':', $goods, 2);
        $g_id = (int) $g_id;
        if ($g_id > 0 && $g_type === 'product')  $cond[] = "s.product_id = {$g_id}";
        if ($g_id > 0 && $g_type === 'material') $cond[] = "s.material_id = {$g_id}";
    }
    if ((int) $customer_id > 0) $cond[] = "s.customer_id = " . (int) $customer_id;
    $where = $cond ? "WHERE " . implode(' AND ', $cond) : '';
    $sql = "SELECT s.id, s.product_id, s.material_id, s.customer_id, s.warehouse,
                   s.quantity, s.unit_price, s.amount, s.created_at,
                   p.product_name, m.material_name, c.name AS customer_name
            FROM sales_inventory_issue_data s
            LEFT JOIN products             p ON p.id = s.product_id
            LEFT JOIN material_information m ON m.id = s.material_id
            LEFT JOIN customers            c ON c.id = s.customer_id
            {$where}
            ORDER BY s.created_at DESC, s.id DESC";
    return db_fetch_array($sql) ?: [];
}

/**
 * Dữ liệu kế toán: gom 1 dòng / nghiệp vụ từ accounting_transaction_mapping
 * kèm account_code Nợ/Có và amount_formula lấy từ accounting_transaction_entry.
 * Mỗi mapping có 1 entry 'debit' + 1 entry 'credit'.
 */
function admin_get_accounting_data($from = '', $to = '')
{
    $cond  = admin_date_filter_cond('m.updated_at', $from, $to);
    $where = $cond ? "WHERE {$cond}" : '';
    $sql = "SELECT m.id,
                   m.transaction_name,
                   m.description,
                   m.updated_at,
                   MAX(CASE WHEN e.entry_type = 'debit'  THEN e.account_code END) AS debit_code,
                   MAX(CASE WHEN e.entry_type = 'credit' THEN e.account_code END) AS credit_code,
                   COALESCE(
                       MAX(CASE WHEN e.entry_type = 'debit'  THEN e.amount_formula END),
                       MAX(CASE WHEN e.entry_type = 'credit' THEN e.amount_formula END)
                   ) AS amount_formula
            FROM accounting_transaction_mapping m
            LEFT JOIN accounting_transaction_entry e ON e.mapping_id = m.id
            {$where}
            GROUP BY m.id, m.transaction_name, m.description, m.updated_at
            ORDER BY m.updated_at DESC, m.id DESC";
    return db_fetch_array($sql) ?: [];
}

/**
 * Dữ liệu thu chi: nội dung bảng cash_transactions (mới → cũ).
 */
function admin_get_cash_transactions_data($from = '', $to = '', $type = '', $account = '')
{
    $cond = [];
    if ($c = admin_date_filter_cond('created_at', $from, $to)) $cond[] = $c;
    if (in_array($type, ['Thu', 'Chi'], true))  $cond[] = "transaction_type = '" . escape_string($type) . "'";
    if (in_array($account, ['TM', 'TG'], true)) $cond[] = "account_name = '" . escape_string($account) . "'";
    $where = $cond ? "WHERE " . implode(' AND ', $cond) : '';
    $sql = "SELECT id, description, transaction_type, account_name,
                   account_number, amount, created_at
            FROM cash_transactions
            {$where}
            ORDER BY created_at DESC, id DESC";
    return db_fetch_array($sql) ?: [];
}

/**
 * Đơn mua: nội dung bảng warehouse_receipts (mới → cũ), kèm tên NCC.
 */
function admin_get_purchase_order_data($from = '', $to = '', $supplier_id = 0)
{
    $cond = [];
    if ($c = admin_date_filter_cond('w.created_at', $from, $to)) $cond[] = $c;
    if ((int) $supplier_id > 0) $cond[] = "w.supplier_id = " . (int) $supplier_id;
    $where = $cond ? "WHERE " . implode(' AND ', $cond) : '';
    $sql = "SELECT w.id, w.supplier_id, w.warehouse_receipt_value,
                   w.purchasing_cost, w.created_at,
                   s.supplier_name
            FROM warehouse_receipts w
            LEFT JOIN suppliers s ON s.id = w.supplier_id
            {$where}
            ORDER BY w.created_at DESC, w.id DESC";
    return db_fetch_array($sql) ?: [];
}

/**
 * Đơn bán: GỘP 2 nguồn (mới → cũ), tên KH lấy customers.name theo customer_id.
 *   - sales_orders                    : lịch sử / nhập tay (value lưu theo ĐỒNG).
 *   - sales_warehouse_export_invoices : phiếu xuất kho bán hàng mới. goods_value
 *                                       lưu theo TRIỆU + ĐÃ LÀM TRÒN 2 số lẻ nên KHÔNG
 *                                       dùng làm giá trị tiền. Giá trị thật = SUM(total_amount)
 *                                       các dòng stock_exports cùng (customer_id, created_at,
 *                                       type_export='sales_issue', không phải dòng thiếu tồn).
 *                                       Chỉ fallback goods_value×1.000.000 cho phiếu cũ không
 *                                       còn line-item khớp (vd dữ liệu import 14/05).
 * Mỗi dòng kèm:
 *   - source   : 'order' | 'export' (để debug / phân biệt nguồn).
 *   - inv_type : type hóa đơn tương ứng để cột "Hóa đơn" tra đúng namespace
 *                ('sales_invoice' cho sales_orders, 'sales_export_invoice' cho phiếu xuất).
 * Lưu ý: id giữa 2 bảng có thể trùng nhau → view phải tra hóa đơn theo (inv_type, id).
 */
/** Bảng lưu khối lượng do user chỉnh tay (đè khối lượng tính tự động). */
function admin_ensure_sales_weight_override_table()
{
    static $done = false;
    if ($done) return;
    $done = true;
    db_query("CREATE TABLE IF NOT EXISTS sales_order_weight_overrides (
        inv_type   VARCHAR(40) NOT NULL,
        order_id   INT NOT NULL,
        weight_kg  DECIMAL(15,3) NOT NULL DEFAULT 0,
        updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        PRIMARY KEY (inv_type, order_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci");
}

/** Lưu khối lượng chỉnh tay cho 1 dòng đơn bán (upsert theo inv_type + id). */
function admin_save_sales_order_weight($inv_type, $order_id, $weight)
{
    $valid = ['sales_invoice', 'sales_export_invoice'];
    if (!in_array($inv_type, $valid, true)) return false;
    $oid = (int) $order_id;
    if ($oid <= 0) return false;
    admin_ensure_sales_weight_override_table();
    $it = escape_string($inv_type);
    $w  = (float) $weight; if ($w < 0) $w = 0;
    $exists = db_num_rows("SELECT 1 FROM sales_order_weight_overrides
                           WHERE inv_type = '$it' AND order_id = $oid") > 0;
    if ($exists) {
        db_update('sales_order_weight_overrides', ['weight_kg' => $w], "inv_type = '$it' AND order_id = $oid");
    } else {
        db_insert('sales_order_weight_overrides', ['inv_type' => $inv_type, 'order_id' => $oid, 'weight_kg' => $w]);
    }
    return true;
}

function admin_get_sales_orders_data($from = '', $to = '', $customer_id = 0)
{
    admin_ensure_sales_weight_override_table();
    // --- Nhánh 1: sales_orders (lịch sử) ---
    $cond1 = [];
    if ($c = admin_date_filter_cond('o.created_at', $from, $to)) $cond1[] = $c;
    if ((int) $customer_id > 0) $cond1[] = "o.customer_id = " . (int) $customer_id;
    $where1 = $cond1 ? "WHERE " . implode(' AND ', $cond1) : '';
    $sql1 = "SELECT o.id, o.customer_id, o.description, o.value, o.created_at,
                    c.name AS customer_name, c.short_name AS customer_short_name,
                    c.secondary_color AS customer_color,
                    COALESCE(
                        wo.weight_kg,
                        (SELECT SUM(e.quantity * COALESCE(pw.weight_kg, 0))
                         FROM stock_exports e
                         LEFT JOIN product_weights pw ON pw.product_id = e.product_id
                         WHERE e.customer_id = o.customer_id
                           AND e.created_at  = o.created_at
                           AND e.type_export = 'sales_issue'),
                        0
                    ) AS weight_kg,
                    'order' AS source, 'sales_invoice' AS inv_type
             FROM sales_orders o
             LEFT JOIN customers c ON c.id = o.customer_id
             LEFT JOIN sales_order_weight_overrides wo
                    ON wo.inv_type = 'sales_invoice' AND wo.order_id = o.id
             {$where1}";

    // --- Nhánh 2: sales_warehouse_export_invoices (phiếu xuất bán mới) ---
    $cond2 = [];
    if ($c = admin_date_filter_cond('s.created_at', $from, $to)) $cond2[] = $c;
    if ((int) $customer_id > 0) $cond2[] = "s.customer_id = " . (int) $customer_id;
    $where2 = $cond2 ? "WHERE " . implode(' AND ', $cond2) : '';
    // Marker dòng thiếu tồn (đồng bộ với im_shortage_interp_text() bên module
    // inventory_management) — inline literal vì module này không load model đó.
    $shortage = escape_string('Chưa ghi dữ liệu do có hàng hóa đang thiếu tồn');
    $sql2 = "SELECT s.id, s.customer_id, NULL AS description,
                    COALESCE(
                        (SELECT SUM(e.total_amount)
                         FROM stock_exports e
                         WHERE e.customer_id = s.customer_id
                           AND e.created_at  = s.created_at
                           AND e.type_export = 'sales_issue'
                           AND e.interpretation <> '{$shortage}'),
                        s.goods_value * 1000000
                    ) AS value, s.created_at,
                    c.name AS customer_name, c.short_name AS customer_short_name,
                    c.secondary_color AS customer_color,
                    COALESCE(
                        wo.weight_kg,
                        (SELECT SUM(e.quantity * COALESCE(pw.weight_kg, 0))
                         FROM stock_exports e
                         LEFT JOIN product_weights pw ON pw.product_id = e.product_id
                         WHERE e.customer_id = s.customer_id
                           AND e.created_at  = s.created_at
                           AND e.type_export = 'sales_issue'),
                        0
                    ) AS weight_kg,
                    'export' AS source, 'sales_export_invoice' AS inv_type
             FROM sales_warehouse_export_invoices s
             LEFT JOIN customers c ON c.id = s.customer_id
             LEFT JOIN sales_order_weight_overrides wo
                    ON wo.inv_type = 'sales_export_invoice' AND wo.order_id = s.id
             {$where2}";

    $sql = "SELECT * FROM ( {$sql1} UNION ALL {$sql2} ) t
            ORDER BY t.created_at DESC, t.id DESC";
    return db_fetch_array($sql) ?: [];
}

/**
 * Lấy map hóa đơn theo wr_id cho 1 danh sách id + 1 type.
 * Trả: [ wr_id => [ {id, file_url, created_at}, ... ] ].
 * Dùng để preload cột "Hóa đơn" cho purchase_order / sales_orders (1 query).
 */
function admin_get_invoices_map($type, $ids)
{
    $valid = ['purchase_invoice', 'sales_invoice', 'sales_export_invoice'];
    if (!in_array($type, $valid, true)) return [];
    $ids = array_values(array_unique(array_filter(array_map('intval', (array) $ids))));
    if (empty($ids)) return [];

    $t       = escape_string($type);
    $id_list = implode(',', $ids);
    $rows = db_fetch_array(
        "SELECT id, wr_id, file_url, created_at
         FROM warehouse_receipt_invoices
         WHERE type = '$t' AND wr_id IN ($id_list)
         ORDER BY id ASC"
    ) ?: [];

    $map = [];
    foreach ($rows as $r) {
        $map[(int) $r['wr_id']][] = [
            'id'         => (int) $r['id'],
            'file_url'   => $r['file_url'],
            'created_at' => $r['created_at'],
        ];
    }
    return $map;
}

/* =====================================================================
 *  LỊCH SỬ THANH TOÁN NCC (module supplier_debt) — view dữ liệu.
 *  Nguồn: supplier_payments + supplier_payment_attachments.
 * =====================================================================*/

/** Danh sách phiếu chi NCC trong khoảng ngày (lọc theo NCC nếu có), mới → cũ. */
function admin_get_supplier_payments($from = '', $to = '', $supplier_id = 0)
{
    $cond = [];
    if ($c = admin_date_filter_cond('sp.created_at', $from, $to)) $cond[] = $c;
    if ((int) $supplier_id > 0) $cond[] = "sp.supplier_id = " . (int) $supplier_id;
    $where = $cond ? "WHERE " . implode(' AND ', $cond) : '';
    $sql = "SELECT sp.id, sp.supplier_id, sp.amount, sp.note, sp.created_at,
                   s.supplier_name
            FROM supplier_payments sp
            LEFT JOIN suppliers s ON s.id = sp.supplier_id
            {$where}
            ORDER BY sp.created_at DESC, sp.id DESC";
    return db_fetch_array($sql) ?: [];
}

/**
 * Map ảnh đính kèm theo payment_id cho 1 danh sách id.
 * Trả: [ payment_id => [ {id, file_url, created_at}, ... ] ].
 */
function admin_get_payment_attachments_map($ids)
{
    $ids = array_values(array_unique(array_filter(array_map('intval', (array) $ids))));
    if (empty($ids)) return [];
    $id_list = implode(',', $ids);
    $rows = db_fetch_array(
        "SELECT id, payment_id, file_url, created_at
         FROM supplier_payment_attachments
         WHERE payment_id IN ($id_list)
         ORDER BY id ASC"
    ) ?: [];
    $map = [];
    foreach ($rows as $r) {
        $map[(int) $r['payment_id']][] = [
            'id'         => (int) $r['id'],
            'file_url'   => $r['file_url'],
            'created_at' => $r['created_at'],
        ];
    }
    return $map;
}

/* =====================================================================
 *  GIẢI THÍCH GIÁ TRỊ THEO DÒNG ("con mắt" — xem dữ liệu nguồn).
 *  - purchase_order : "Giá trị nhập kho" của 1 warehouse_receipts.
 *  - sales_orders   : "Giá trị hàng hóa" của 1 đơn bán.
 * =====================================================================*/

/**
 * Chi tiết các dòng cấu thành "Giá trị nhập kho" của 1 warehouse_receipts.
 * Nguồn: warehouse_receipts.import_invoice_id -> stock_imports (cùng invoice),
 *        kèm CPMH theo dòng (stock_import_purchase_costs: VAT / CP khác).
 * Trả: [ id, supplier_name, created_at, value, cost, lines[] ] hoặc null.
 *   line = { name, kind, quantity, unit_price, amount, costs[], cost_sum, total }
 */
function admin_get_purchase_value_detail($wr_id)
{
    $wr_id = (int) $wr_id;
    if ($wr_id <= 0) return null;

    $wr = db_fetch_row(
        "SELECT w.id, w.warehouse_receipt_value, w.purchasing_cost, w.created_at,
                w.import_invoice_id, s.supplier_name
         FROM warehouse_receipts w
         LEFT JOIN suppliers s ON s.id = w.supplier_id
         WHERE w.id = $wr_id LIMIT 1"
    );
    if (!$wr) return null;

    $inv_id = (int) ($wr['import_invoice_id'] ?? 0);
    $lines  = [];
    if ($inv_id > 0) {
        $rows = db_fetch_array(
            "SELECT si.id, si.product_id, si.material_id, si.quantity, si.unit_price,
                    p.product_name, m.material_name
             FROM stock_imports si
             LEFT JOIN products             p ON p.id = si.product_id
             LEFT JOIN material_information m ON m.id = si.material_id
             WHERE si.import_invoice_id = $inv_id
             ORDER BY si.id ASC"
        ) ?: [];

        // CPMH theo từng dòng (VAT, CP khác) — 1 query.
        $cost_map = [];
        $si_ids   = array_values(array_filter(array_map('intval', array_column($rows, 'id'))));
        if ($si_ids) {
            $idlist = implode(',', $si_ids);
            $costs  = db_fetch_array(
                "SELECT stock_import_id, description, price
                 FROM stock_import_purchase_costs
                 WHERE stock_import_id IN ($idlist)
                 ORDER BY id ASC"
            ) ?: [];
            foreach ($costs as $c) {
                $cost_map[(int) $c['stock_import_id']][] = [
                    'description' => (string) $c['description'],
                    'price'       => (float) $c['price'],
                ];
            }
        }

        foreach ($rows as $r) {
            $qty    = (float) $r['quantity'];
            $price  = (float) $r['unit_price'];
            $amount = $qty * $price;
            $cs     = $cost_map[(int) $r['id']] ?? [];
            $cost_sum = 0.0;
            foreach ($cs as $c) $cost_sum += $c['price'];
            $lines[] = [
                'name'       => $r['product_name'] ?: ($r['material_name'] ?: '—'),
                'kind'       => $r['product_id'] ? 'Thành phẩm' : 'Nguyên vật liệu',
                'quantity'   => $qty,
                'unit_price' => $price,
                'amount'     => $amount,
                'costs'      => $cs,
                'cost_sum'   => $cost_sum,
                'total'      => $amount + $cost_sum,
            ];
        }
    }

    return [
        'id'            => (int) $wr['id'],
        'supplier_name' => (string) ($wr['supplier_name'] ?: ''),
        'created_at'    => (string) $wr['created_at'],
        'value'         => (float) $wr['warehouse_receipt_value'],
        'cost'          => (float) $wr['purchasing_cost'],
        'lines'         => $lines,
    ];
}

/**
 * Chi tiết các dòng cấu thành "Giá trị hàng hóa" của 1 đơn bán.
 * Nguồn: stock_exports (type_export='sales_issue') khớp (customer_id, created_at),
 *        bỏ dòng marker thiếu tồn (đồng bộ admin_get_sales_orders_data()).
 * Trả: lines[] = { name, kind, quantity, unit_price, total }.
 */
function admin_get_sales_value_detail($customer_id, $created_at)
{
    $cid = (int) $customer_id;
    $ca  = trim((string) $created_at);
    if ($cid <= 0 || $ca === '') return [];

    $ca_safe  = escape_string($ca);
    $shortage = escape_string('Chưa ghi dữ liệu do có hàng hóa đang thiếu tồn');
    $rows = db_fetch_array(
        "SELECT e.id, e.product_id, e.material_id, e.quantity, e.unit_price, e.total_amount,
                p.product_name, m.material_name
         FROM stock_exports e
         LEFT JOIN products             p ON p.id = e.product_id
         LEFT JOIN material_information m ON m.id = e.material_id
         WHERE e.customer_id = $cid
           AND e.created_at  = '$ca_safe'
           AND e.type_export = 'sales_issue'
           AND e.interpretation <> '$shortage'
         ORDER BY e.id ASC"
    ) ?: [];

    $lines = [];
    foreach ($rows as $r) {
        $lines[] = [
            'name'       => $r['product_name'] ?: ($r['material_name'] ?: '—'),
            'kind'       => $r['product_id'] ? 'Thành phẩm' : 'Nguyên vật liệu',
            'quantity'   => (float) $r['quantity'],
            'unit_price' => (float) $r['unit_price'],
            'total'      => (float) $r['total_amount'],
        ];
    }
    return $lines;
}
