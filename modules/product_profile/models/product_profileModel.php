<?php

/**
 * Tạo file_code từ tên file theo quy tắc:
 * - Lấy chữ cái đầu mỗi từ, tối đa 4 ký tự (viết hoa)
 * - Ghép với tháng.năm hiện tại
 * Ví dụ: "Bản thiết kế bao bì" → BTKB_04.26
 */
function generate_file_code($file_label)
{
    $words = preg_split('/\s+/', trim($file_label));
    $initials = '';
    foreach ($words as $word) {
        if (!empty($word)) {
            $initials .= mb_strtoupper(mb_substr($word, 0, 1, 'UTF-8'), 'UTF-8');
        }
    }
    $initials = mb_substr($initials, 0, 4, 'UTF-8');
    $month_year = date('m.y');
    return $initials . '_' . $month_year;
}

/**
 * Upload file vật lý và lưu thông tin vào database
 *
 * @param int    $product_id   ID sản phẩm
 * @param string $product_name Tên sản phẩm (dùng làm tên folder)
 * @param string $file_label   Tên file do người dùng đặt
 * @param array  $file         Mảng $_FILES['file_upload']
 * @return array ['success' => bool, 'message' => string]
 */
function upload_product_file($product_id, $product_name, $file_label, $file)
{
    global $conn;

    // 1. Kiểm tra file có được chọn không
    if (empty($file['name']) || $file['error'] !== UPLOAD_ERR_OK) {
        return ['success' => false, 'message' => 'Chưa chọn file hoặc file lỗi.'];
    }

    // 2. Xác định đường dẫn folder
    $upload_dir = 'public/uploads/product_profile/' . $product_name . '/';

    // 3. Tạo folder nếu chưa có
    if (!is_dir($upload_dir)) {
        mkdir($upload_dir, 0755, true);
    }

    // 4. Đặt tên file an toàn (giữ tên gốc, thay khoảng trắng bằng _)
    $original_name = basename($file['name']);
    $safe_filename  = str_replace(' ', '_', $original_name);
    $dest_path      = $upload_dir . $safe_filename;

    // 5. Di chuyển file vào hệ thống
    if (!move_uploaded_file($file['tmp_name'], $dest_path)) {
        return ['success' => false, 'message' => 'Không thể lưu file lên server.'];
    }

    // 6. Không nhập tên thì mặc định lấy theo tên file gốc (bỏ đuôi mở rộng).
    $label = trim((string) $file_label) !== '' ? $file_label : pathinfo($original_name, PATHINFO_FILENAME);

    // 7. Tạo file_code (4 chữ cái đầu mỗi từ + tháng.năm)
    $file_code = generate_file_code($label);

    // 8. Đường dẫn lưu vào DB (relative to public/uploads/)
    $db_file_path = 'product_profile/' . $product_name . '/' . $safe_filename;

    // 9. Lưu vào database
    $product_id_int = (int) $product_id;
    $file_code_safe  = mysqli_real_escape_string($conn, $file_code);
    $file_label_safe = mysqli_real_escape_string($conn, $label);
    $file_path_safe  = mysqli_real_escape_string($conn, $db_file_path);

    $sql = "INSERT INTO product_files (product_id, file_code, file_name, file_path)
            VALUES ($product_id_int, '$file_code_safe', '$file_label_safe', '$file_path_safe')";

    if (!mysqli_query($conn, $sql)) {
        return ['success' => false, 'message' => 'Lỗi lưu database: ' . mysqli_error($conn)];
    }

    return ['success' => true, 'message' => 'Thêm file thành công.'];
}
/**
 * Chuyển chuỗi tiếng Việt thành slug (không dấu, viết thường, dấu gạch ngang)
 * Ví dụ: "Công ty TNHH Hương Đi" → "cong-ty-tnhh-huong-di"
 */
function to_slug($string)
{
    $vietnamese = [
        'à','á','ạ','ả','ã','â','ầ','ấ','ậ','ẩ','ẫ','ă','ằ','ắ','ặ','ẳ','ẵ',
        'è','é','ẹ','ẻ','ẽ','ê','ề','ế','ệ','ể','ễ',
        'ì','í','ị','ỉ','ĩ',
        'ò','ó','ọ','ỏ','õ','ô','ồ','ố','ộ','ổ','ỗ','ơ','ờ','ớ','ợ','ở','ỡ',
        'ù','ú','ụ','ủ','ũ','ư','ừ','ứ','ự','ử','ữ',
        'ỳ','ý','ỵ','ỷ','ỹ',
        'đ',
        'À','Á','Ạ','Ả','Ã','Â','Ầ','Ấ','Ậ','Ẩ','Ẫ','Ă','Ằ','Ắ','Ặ','Ẳ','Ẵ',
        'È','É','Ẹ','Ẻ','Ẽ','Ê','Ề','Ế','Ệ','Ể','Ễ',
        'Ì','Í','Ị','Ỉ','Ĩ',
        'Ò','Ó','Ọ','Ỏ','Õ','Ô','Ồ','Ố','Ộ','Ổ','Ỗ','Ơ','Ờ','Ớ','Ợ','Ở','Ỡ',
        'Ù','Ú','Ụ','Ủ','Ũ','Ư','Ừ','Ứ','Ự','Ử','Ữ',
        'Ỳ','Ý','Ỵ','Ỷ','Ỹ',
        'Đ'
    ];
    $ascii = [
        'a','a','a','a','a','a','a','a','a','a','a','a','a','a','a','a','a',
        'e','e','e','e','e','e','e','e','e','e','e',
        'i','i','i','i','i',
        'o','o','o','o','o','o','o','o','o','o','o','o','o','o','o','o','o',
        'u','u','u','u','u','u','u','u','u','u','u',
        'y','y','y','y','y',
        'd',
        'A','A','A','A','A','A','A','A','A','A','A','A','A','A','A','A','A',
        'E','E','E','E','E','E','E','E','E','E','E',
        'I','I','I','I','I',
        'O','O','O','O','O','O','O','O','O','O','O','O','O','O','O','O','O',
        'U','U','U','U','U','U','U','U','U','U','U',
        'Y','Y','Y','Y','Y',
        'D'
    ];
    $string = str_replace($vietnamese, $ascii, $string);
    $string = mb_strtolower($string, 'UTF-8');
    $string = preg_replace('/[^a-z0-9\s-]/', '', $string);
    $string = preg_replace('/[\s-]+/', '-', $string);
    $string = trim($string, '-');
    return $string;
}

/**
 * Kiểm tra trùng tên file trong thư mục, nếu trùng thì thêm (1), (2)...
 */
function get_unique_filename($dir, $filename)
{
    $info = pathinfo($filename);
    $name = $info['filename'];
    $ext = isset($info['extension']) ? '.' . $info['extension'] : '';

    $final_name = $name . $ext;
    $counter = 1;

    while (file_exists($dir . $final_name)) {
        $final_name = $name . '(' . $counter . ')' . $ext;
        $counter++;
    }

    return $final_name;
}

function upload_supplier_file($supplier_id, $supplier_name, $file_label, $file)
{
    global $conn;

    // 1. Kiểm tra file có được chọn không
    if (empty($file['name']) || $file['error'] !== UPLOAD_ERR_OK) {
        return ['success' => false, 'message' => 'Chưa chọn file hoặc file lỗi.'];
    }

    // 2. Chuẩn hóa tên NCC thành slug
    $supplier_slug = to_slug($supplier_name);

    // 3. Xác định đường dẫn folder vật lý
    $upload_dir = 'public/uploads/composition_product/supplier/' . $supplier_slug . '/';

    // 4. Tạo folder nếu chưa có
    if (!is_dir($upload_dir)) {
        mkdir($upload_dir, 0755, true);
    }

    // 5. Chuẩn hóa tên file và kiểm tra trùng
    $original_name = basename($file['name']);
    $safe_filename = to_slug(pathinfo($original_name, PATHINFO_FILENAME));
    $ext = strtolower(pathinfo($original_name, PATHINFO_EXTENSION));
    if ($ext) $safe_filename .= '.' . $ext;

    $safe_filename = get_unique_filename($upload_dir, $safe_filename);
    $dest_path = $upload_dir . $safe_filename;

    // 6. Di chuyển file vào hệ thống
    if (!move_uploaded_file($file['tmp_name'], $dest_path)) {
        return ['success' => false, 'message' => 'Không thể lưu file lên server.'];
    }

    // 7. Đường dẫn lưu vào DB (relative to public/)
    $db_file_path = 'uploads/composition_product/supplier/' . $supplier_slug . '/' . $safe_filename;

    // 8. Lưu vào database
    $entity_id_int = (int) $supplier_id;
    $file_name_safe = mysqli_real_escape_string($conn, $file_label);
    $file_path_safe = mysqli_real_escape_string($conn, $db_file_path);

    $sql = "INSERT INTO files (file_name, file_path, entity_type, entity_id)
            VALUES ('$file_name_safe', '$file_path_safe', 'supplier', $entity_id_int)";

    if (!mysqli_query($conn, $sql)) {
        return ['success' => false, 'message' => 'Lỗi lưu database: ' . mysqli_error($conn)];
    }

    return ['success' => true, 'message' => 'Thêm file thành công.'];
}

/**
 * Upload file cho nguyên liệu (material)
 */
function upload_material_file($material_id, $folder_name, $file_label, $file)
{
    global $conn;

    // 1. Kiểm tra file có được chọn không
    if (empty($file['name']) || $file['error'] !== UPLOAD_ERR_OK) {
        return ['success' => false, 'message' => 'Chưa chọn file hoặc file lỗi.'];
    }

    // 2. Chuẩn hóa tên folder từ input user
    $folder_slug = to_slug($folder_name);

    // 3. Xác định đường dẫn folder vật lý
    $upload_dir = 'public/uploads/composition_product/material/' . $folder_slug . '/';

    // 4. Tạo folder nếu chưa có
    if (!is_dir($upload_dir)) {
        mkdir($upload_dir, 0755, true);
    }

    // 5. Chuẩn hóa tên file và kiểm tra trùng
    $original_name = basename($file['name']);
    $safe_filename = to_slug(pathinfo($original_name, PATHINFO_FILENAME));
    $ext = strtolower(pathinfo($original_name, PATHINFO_EXTENSION));
    if ($ext) $safe_filename .= '.' . $ext;

    $safe_filename = get_unique_filename($upload_dir, $safe_filename);
    $dest_path = $upload_dir . $safe_filename;

    // 6. Di chuyển file vào hệ thống
    if (!move_uploaded_file($file['tmp_name'], $dest_path)) {
        return ['success' => false, 'message' => 'Không thể lưu file lên server.'];
    }

    // 7. Đường dẫn lưu vào DB (relative to public/)
    $db_file_path = 'uploads/composition_product/material/' . $folder_slug . '/' . $safe_filename;

    // 8. Lưu vào database — không nhập tên thì mặc định lấy theo tên file gốc (bỏ đuôi mở rộng).
    $entity_id_int = (int) $material_id;
    $label = trim((string) $file_label) !== '' ? $file_label : pathinfo($original_name, PATHINFO_FILENAME);
    $file_name_safe = mysqli_real_escape_string($conn, $label);
    $file_path_safe = mysqli_real_escape_string($conn, $db_file_path);

    $sql = "INSERT INTO files (file_name, file_path, entity_type, entity_id)
            VALUES ('$file_name_safe', '$file_path_safe', 'material', $entity_id_int)";

    if (!mysqli_query($conn, $sql)) {
        return ['success' => false, 'message' => 'Lỗi lưu database: ' . mysqli_error($conn)];
    }

    return ['success' => true, 'message' => 'Thêm file thành công.'];
}
/* ============================================================
   KÉO-THẢ FILE: lưu file + insert DB, TRẢ VỀ dữ liệu đầy đủ
   (id, file_name, file_path...) để JS render ngay không cần reload.
   ============================================================ */

// Lưu file cho SẢN PHẨM (table product_files) - dùng cho kéo-thả ở trang list
function store_product_file($product_id, $product_name, $file_label, $file)
{
    global $conn;

    if (empty($file['name']) || $file['error'] !== UPLOAD_ERR_OK) {
        return ['success' => false, 'message' => 'Chưa chọn file hoặc file lỗi.'];
    }

    $upload_dir = 'public/uploads/product_profile/' . $product_name . '/';
    if (!is_dir($upload_dir)) {
        mkdir($upload_dir, 0755, true);
    }

    // Giữ tên gốc (thay khoảng trắng bằng _), tránh ghi đè khi trùng tên
    $original_name = basename($file['name']);
    $safe_filename = get_unique_filename($upload_dir, str_replace(' ', '_', $original_name));
    $dest_path     = $upload_dir . $safe_filename;

    if (!move_uploaded_file($file['tmp_name'], $dest_path)) {
        return ['success' => false, 'message' => 'Không thể lưu file lên server.'];
    }

    $file_code    = generate_file_code($file_label);
    $db_file_path = 'product_profile/' . $product_name . '/' . $safe_filename;

    $product_id_int  = (int) $product_id;
    $file_code_safe  = mysqli_real_escape_string($conn, $file_code);
    $file_label_safe = mysqli_real_escape_string($conn, $file_label);
    $file_path_safe  = mysqli_real_escape_string($conn, $db_file_path);

    $sql = "INSERT INTO product_files (product_id, file_code, file_name, file_path)
            VALUES ($product_id_int, '$file_code_safe', '$file_label_safe', '$file_path_safe')";

    if (!mysqli_query($conn, $sql)) {
        return ['success' => false, 'message' => 'Lỗi lưu database: ' . mysqli_error($conn)];
    }

    return [
        'success'   => true,
        'id'        => mysqli_insert_id($conn),
        'file_name' => $file_label,
        'file_code' => $file_code,
        'file_path' => $db_file_path,                       // lưu trong DB
        'web_path'  => 'public/uploads/' . $db_file_path,   // dùng cho link mở file
    ];
}

// Lưu file cho THÀNH PHẦN (table files) - kéo-thả ở trang chi tiết
function store_composition_file($entity_type, $entity_id, $folder_name, $file_label, $file)
{
    global $conn;

    if (empty($file['name']) || $file['error'] !== UPLOAD_ERR_OK) {
        return ['success' => false, 'message' => 'Chưa chọn file hoặc file lỗi.'];
    }

    $entity_type = ($entity_type === 'supplier') ? 'supplier' : 'material';
    $folder_slug = to_slug($folder_name);
    $upload_dir  = 'public/uploads/composition_product/' . $entity_type . '/' . $folder_slug . '/';
    if (!is_dir($upload_dir)) {
        mkdir($upload_dir, 0755, true);
    }

    $original_name = basename($file['name']);
    $base = to_slug(pathinfo($original_name, PATHINFO_FILENAME));
    $ext  = strtolower(pathinfo($original_name, PATHINFO_EXTENSION));
    $safe_filename = $base . ($ext ? '.' . $ext : '');
    $safe_filename = get_unique_filename($upload_dir, $safe_filename);
    $dest_path     = $upload_dir . $safe_filename;

    if (!move_uploaded_file($file['tmp_name'], $dest_path)) {
        return ['success' => false, 'message' => 'Không thể lưu file lên server.'];
    }

    $db_file_path = 'uploads/composition_product/' . $entity_type . '/' . $folder_slug . '/' . $safe_filename;

    $entity_id_int    = (int) $entity_id;
    $file_name_safe   = mysqli_real_escape_string($conn, $file_label);
    $file_path_safe   = mysqli_real_escape_string($conn, $db_file_path);
    $entity_type_safe = mysqli_real_escape_string($conn, $entity_type);

    $sql = "INSERT INTO files (file_name, file_path, entity_type, entity_id)
            VALUES ('$file_name_safe', '$file_path_safe', '$entity_type_safe', $entity_id_int)";

    if (!mysqli_query($conn, $sql)) {
        return ['success' => false, 'message' => 'Lỗi lưu database: ' . mysqli_error($conn)];
    }

    return [
        'success'     => true,
        'id'          => mysqli_insert_id($conn),
        'file_name'   => $file_label,
        'file_path'   => $db_file_path,
        'entity_type' => $entity_type,
        'entity_id'   => $entity_id_int,
    ];
}

// PAGE DETAIL
function get_info_product_basic_by_id($id)
{
    $id = (int) $id;
    //Chuỗi truy vấn
    $sql = "SELECT ib.unit,
               mi_in.material_name  AS inner_packaging,
               mi_out.material_name AS outer_packaging,
               ib.inner_packaging_spec,
               ib.outer_packaging_spec,
               products.image_url,
               products.product_name
        FROM product_info_basic AS ib
        LEFT JOIN products ON ib.product_id = products.id
        LEFT JOIN material_information AS mi_in  ON mi_in.id  = ib.inner_packaging_id
        LEFT JOIN material_information AS mi_out ON mi_out.id = ib.outer_packaging_id
        WHERE ib.product_id = $id ";
    //Chạy chuỗi
    $result = db_fetch_array($sql);
    if (!empty($result)) {
        $result[0]['image_url'] = "public/images/" . $result[0]['image_url'];
        //Trả kết quả
        return $result;
    } else {
        return false;
    }
}

function get_pricing_policy_by_product_id($product_id)
{
    $sql = "SELECT cost_price, system_price, retail_price
            FROM pricing_policies
            WHERE product_id = $product_id
            LIMIT 1";
    $result = db_fetch_array($sql);
    if (!empty($result)) {
        return $result[0];
    }
    return false;
}
/** Cột suppliers.short_name (tên viết tắt NCC) — idempotent, giống admin_ensure_supplier_short_name_column(). */
function pp_ensure_supplier_short_name_column()
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

/**
 * Cột suppliers.check_profile — "Thiết lập NCC": bật (1, mặc định) = có XÉT hồ sơ nhà cung cấp này
 * (hiện khối "Hồ sơ nhà cung cấp" ở trang Chi tiết + tính vào "Hóa đơn, chứng từ còn thiếu"); tắt (0)
 * = KHÔNG xét (ẩn khối, bỏ qua khi tính thiếu hồ sơ). Áp dụng TOÀN HỆ THỐNG (theo NCC, không theo
 * từng sản phẩm) — idempotent, giống pp_ensure_supplier_short_name_column().
 */
function pp_ensure_supplier_check_profile_column()
{
    static $done = false;
    if ($done) return;
    $done = true;
    db_query("ALTER TABLE suppliers ADD COLUMN IF NOT EXISTS check_profile TINYINT(1) NOT NULL DEFAULT 1");
}

/** Toàn bộ NCC + trạng thái "Xét hồ sơ NCC" (modal "Thiết lập NCC"). */
function pp_list_suppliers_check_profile()
{
    pp_ensure_supplier_short_name_column();
    pp_ensure_supplier_check_profile_column();
    return db_fetch_array(
        "SELECT id, COALESCE(NULLIF(short_name, ''), supplier_name) AS display_name, check_profile
         FROM suppliers
         ORDER BY display_name ASC"
    ) ?: [];
}

/** Bật/tắt "Xét hồ sơ NCC" cho 1 nhà cung cấp. */
function pp_set_supplier_check_profile($supplier_id, $value)
{
    pp_ensure_supplier_check_profile_column();
    $supplier_id = (int) $supplier_id;
    if ($supplier_id <= 0) return false;
    return db_update('suppliers', ['check_profile' => $value ? 1 : 0], "id = $supplier_id");
}

/** Cột products.common_product_name ("Tên thường gọi") — idempotent, giống admin_ensure_product_common_name_column(). */
function pp_ensure_product_common_name_column()
{
    static $done = false;
    if ($done) return;
    $done = true;
    $existed = db_num_rows("SHOW COLUMNS FROM products LIKE 'common_product_name'") > 0;
    if (!$existed) {
        db_query("ALTER TABLE products ADD COLUMN common_product_name VARCHAR(255) DEFAULT NULL");
    }
}

/**
 * Cột products.nutrition_fact_confirmed — cờ WORKFLOW "đã xác nhận công bố dinh dưỡng xong", TÁCH BIỆT
 * với products.has_nutrition_fact (cờ "thuộc loại SP cần/có công bố dinh dưỡng"). Idempotent.
 */
function pp_ensure_nutrition_fact_confirmed_column()
{
    static $done = false;
    if ($done) return;
    $done = true;
    $existed = db_num_rows("SHOW COLUMNS FROM products LIKE 'nutrition_fact_confirmed'") > 0;
    if (!$existed) {
        db_query("ALTER TABLE products ADD COLUMN nutrition_fact_confirmed TINYINT(1) NOT NULL DEFAULT 0");
    }
}

/** AJAX: bật/tắt cờ "đã xác nhận công bố dinh dưỡng" cho 1 sản phẩm. */
function pp_set_nutrition_fact_confirmed($product_id, $confirmed)
{
    pp_ensure_nutrition_fact_confirmed_column();
    $product_id = (int) $product_id;
    if ($product_id <= 0) return false;
    return db_update('products', ['nutrition_fact_confirmed' => $confirmed ? 1 : 0], "id = $product_id");
}

/**
 * Cột products.standard_document_set — cờ "Bộ hồ sơ chuẩn" chuyển từ chỗ dựa vào
 * product_files.standard_document_sets (chỉ tồn tại khi ĐÃ có ít nhất 1 file) sang lưu thẳng trên
 * products, để cho phép tích chọn NGAY CẢ KHI sản phẩm chưa có file nào (user sẽ bổ sung file sau).
 * Backfill 1 lần từ dữ liệu product_files hiện có để không mất trạng thái các sản phẩm đã đánh dấu trước đó.
 */
function pp_ensure_standard_document_set_column()
{
    static $done = false;
    if ($done) return;
    $done = true;
    $existed = db_num_rows("SHOW COLUMNS FROM products LIKE 'standard_document_set'") > 0;
    if (!$existed) {
        db_query("ALTER TABLE products ADD COLUMN standard_document_set TINYINT(1) NOT NULL DEFAULT 0");
        db_query(
            "UPDATE products p SET p.standard_document_set = 1
             WHERE EXISTS (SELECT 1 FROM product_files pf WHERE pf.product_id = p.id AND pf.standard_document_sets = '1')"
        );
    }
}

/** AJAX: bật/tắt cờ "Bộ hồ sơ chuẩn" cho 1 sản phẩm — không đòi hỏi đã có file. */
function pp_set_standard_document_set($product_id, $value)
{
    global $conn;
    pp_ensure_standard_document_set_column();
    $product_id = (int) $product_id;
    if ($product_id <= 0) return false;
    db_update('products', ['standard_document_set' => $value ? 1 : 0], "id = $product_id");
    // Đồng bộ luôn các file hiện có (nếu có) để không lệch dữ liệu, dù không còn là nguồn xác định chính.
    mysqli_query($conn, "UPDATE product_files SET standard_document_sets = '" . ($value ? '1' : '0') . "' WHERE product_id = $product_id");
    return true;
}

/** Tìm sản phẩm theo tên (dùng cho ô tìm & đổi sản phẩm xem ở trang Chi tiết). */
function pp_search_products_for_detail($keyword)
{
    pp_ensure_product_common_name_column();
    global $conn;
    $keyword_safe = mysqli_real_escape_string($conn, trim((string) $keyword));
    // product_name trả kèm để nơi nào cần ĐÚNG tên trên nhãn (modal "Xem thành phần")
    // không phải gọi thêm 1 query nữa.
    $sql = "SELECT id, product_name, COALESCE(NULLIF(common_product_name, ''), product_name) AS display_name
            FROM products
            WHERE product_name LIKE '%$keyword_safe%' OR common_product_name LIKE '%$keyword_safe%'
            ORDER BY display_name
            LIMIT 20";
    return db_fetch_array($sql) ?: [];
}

function get_list_compositon($product_id)
{
    pp_ensure_supplier_short_name_column();
    pp_ensure_supplier_check_profile_column();
    $sql = "SELECT bm.id AS bom_id,
                   bm.material_id,
                   mi.material_name,
                   mi.common_material_name,
                   mi.unit AS material_unit,
                   mi.supplier_id as mi_supplier_id,
                   mi.id AS material_info_id,
                   s.id AS supplier_id,
                   s.supplier_name,
                   s.short_name AS supplier_short_name,
                   COALESCE(s.check_profile, 1) AS supplier_check_profile,
                   (SELECT r.created_at FROM raw_material_purchase_data r
                      WHERE r.material_id = mi.id
                      ORDER BY r.created_at DESC, r.id DESC LIMIT 1) AS last_purchase_date,
                   (SELECT r.quantity FROM raw_material_purchase_data r
                      WHERE r.material_id = mi.id
                      ORDER BY r.created_at DESC, r.id DESC LIMIT 1) AS last_purchase_qty
            FROM bill_of_materials AS bm
            LEFT JOIN material_information AS mi
                ON bm.material_id = mi.id
            LEFT JOIN suppliers AS s
                ON mi.supplier_id = s.id
            WHERE bm.product_id = $product_id
            ORDER BY bm.position, bm.id";

    $composition = db_fetch_array($sql);
    if(empty( $composition)){
        return false;
    }
    // show_array( $composition);
    // Lấy list id để query file
    $material_ids = array_column($composition, 'material_info_id');
    $supplier_ids = array_column($composition, 'supplier_id');

    // Bước 2: lấy file
    $files = get_files_by_entities($material_ids, $supplier_ids);
    $invoices = get_invoices_by_material_ids($material_ids);
    // show_array($files);

    // Gắn file vào từng phần tử
    foreach ($composition as &$item) {
        $m_id = $item['material_info_id'];
        $s_id = $item['supplier_id'];

        $item['files'] = [
            'supplier' => $files['supplier'][$s_id] ?? [],
            'material' => $files['material'][$m_id] ?? [],
            'invoice'  => $invoices[$m_id] ?? [],
        ];

        // Giữ lại material_info_id và supplier_id để dùng trong view
    }


    return $composition;
}
function get_files_by_entities($material_ids, $supplier_ids)
{
    $material_ids = implode(',', array_map('intval', $material_ids));
    $supplier_ids = implode(',', array_map('intval', $supplier_ids));

    $sql = "SELECT id, file_name, file_path, entity_type, entity_id
            FROM files
            WHERE
                (entity_type = 'material' AND entity_id IN ($material_ids))
                OR
                (entity_type = 'supplier' AND entity_id IN ($supplier_ids))";

    $rows = db_fetch_array($sql);

    $result = [
        'material' => [],
        'supplier' => []
    ];

    foreach ($rows as $row) {
        $type = $row['entity_type'];
        $id   = $row['entity_id'];

        $file_data = [
            'id' => $row['id'],
            'file_name' => $row['file_name'],
            'file_path' => $row['file_path']
        ];

        if ($type == 'supplier') {
            $result['supplier'][$id][] = $file_data;
        }

        if ($type == 'material') {
            $result['material'][$id][] = $file_data;
        }
    }

    return $result;
}

/* ============================================================
   HÓA ĐƠN MUA HÀNG (theo nguyên liệu) — sibling của "Hồ sơ nguyên liệu"/
   "Hồ sơ nhà cung cấp" trong khối Thành phần cấu tạo ở trang Chi tiết.
   Gắn theo material_info_id (dùng chung mọi sản phẩm có cùng NVL này,
   giống cách "Hồ sơ nguyên liệu" đang hoạt động) — KHÁC bảng `files`
   (polymorphic entity_type/entity_id) vì hóa đơn cần thêm ngày tải lên
   + ngày nhắc hẹn cảnh báo hết hạn.
   ============================================================ */

/** Bảng material_invoices — idempotent, không có migration .sql riêng. */
function pp_ensure_invoice_table()
{
    static $done = false;
    if ($done) return;
    $done = true;
    db_query("CREATE TABLE IF NOT EXISTS material_invoices (
        id INT AUTO_INCREMENT PRIMARY KEY,
        material_info_id INT NOT NULL,
        file_name VARCHAR(255) NOT NULL,
        file_path VARCHAR(255) NOT NULL,
        uploaded_at DATE NOT NULL,
        remind_at DATE NOT NULL,
        created_at DATETIME NOT NULL,
        INDEX idx_material_info_id (material_info_id)
    )");
}

/** Upload 1 hóa đơn mua hàng cho nguyên liệu — remind_at mặc định = ngày tải lên + 1 năm (cảnh báo). */
function upload_material_invoice($material_id, $folder_name, $file_label, $file)
{
    global $conn;
    pp_ensure_invoice_table();

    if (empty($file['name']) || $file['error'] !== UPLOAD_ERR_OK) {
        return ['success' => false, 'message' => 'Chưa chọn file hoặc file lỗi.'];
    }

    $folder_slug = to_slug($folder_name);
    $upload_dir = 'public/uploads/composition_product/invoice/' . $folder_slug . '/';
    if (!is_dir($upload_dir)) {
        mkdir($upload_dir, 0755, true);
    }

    $original_name = basename($file['name']);
    $safe_filename = to_slug(pathinfo($original_name, PATHINFO_FILENAME));
    $ext = strtolower(pathinfo($original_name, PATHINFO_EXTENSION));
    if ($ext) $safe_filename .= '.' . $ext;

    $safe_filename = get_unique_filename($upload_dir, $safe_filename);
    $dest_path = $upload_dir . $safe_filename;

    if (!move_uploaded_file($file['tmp_name'], $dest_path)) {
        return ['success' => false, 'message' => 'Không thể lưu file lên server.'];
    }

    $db_file_path = 'uploads/composition_product/invoice/' . $folder_slug . '/' . $safe_filename;
    $label = $file_label !== '' ? $file_label : pathinfo($original_name, PATHINFO_FILENAME);

    $material_id_int = (int) $material_id;
    $file_name_safe  = mysqli_real_escape_string($conn, $label);
    $file_path_safe  = mysqli_real_escape_string($conn, $db_file_path);

    $sql = "INSERT INTO material_invoices (material_info_id, file_name, file_path, uploaded_at, remind_at, created_at)
            VALUES ($material_id_int, '$file_name_safe', '$file_path_safe', CURDATE(), DATE_ADD(CURDATE(), INTERVAL 1 YEAR), NOW())";

    if (!mysqli_query($conn, $sql)) {
        return ['success' => false, 'message' => 'Lỗi lưu database: ' . mysqli_error($conn)];
    }

    return ['success' => true, 'message' => 'Thêm hóa đơn thành công.'];
}

/** Lấy hóa đơn theo danh sách material_info_id, nhóm sẵn theo material_info_id (giống get_files_by_entities). */
function get_invoices_by_material_ids($material_ids)
{
    pp_ensure_invoice_table();
    $ids = implode(',', array_filter(array_map('intval', $material_ids)));
    if ($ids === '') return [];

    $sql = "SELECT id, material_info_id, file_name, file_path, uploaded_at, remind_at
            FROM material_invoices
            WHERE material_info_id IN ($ids)
            ORDER BY uploaded_at DESC, id DESC";
    $rows = db_fetch_array($sql) ?: [];

    $today = date('Y-m-d');
    $result = [];
    foreach ($rows as $row) {
        $row['is_overdue'] = ($row['remind_at'] <= $today);
        $result[$row['material_info_id']][] = $row;
    }
    return $result;
}

/** Xóa 1 hóa đơn (file vật lý + record). */
function pp_delete_material_invoice($invoice_id)
{
    pp_ensure_invoice_table();
    $invoice_id = (int) $invoice_id;
    if ($invoice_id <= 0) return false;

    $row = db_fetch_row("SELECT file_path FROM material_invoices WHERE id = $invoice_id");
    if ($row) {
        $path = 'public/' . $row['file_path'];
        if (file_exists($path)) unlink($path);
        db_query("DELETE FROM material_invoices WHERE id = $invoice_id");
    }
    return true;
}

/** Sửa ngày nhắc hẹn (cảnh báo hết hạn) của 1 hóa đơn — user tự chỉnh lại mốc mặc định +1 năm nếu cần. */
function pp_update_invoice_remind($invoice_id, $remind_at)
{
    pp_ensure_invoice_table();
    $invoice_id = (int) $invoice_id;
    $remind_at = trim((string) $remind_at);
    if ($invoice_id <= 0 || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $remind_at)) return false;
    return db_update('material_invoices', ['remind_at' => $remind_at], "id = $invoice_id");
}

/**
 * Danh sách hồ sơ/hóa đơn còn thiếu của 1 sản phẩm — dùng cho modal "Hóa đơn còn thiếu".
 * Nhận vào $composition đã có sẵn (từ get_list_compositon), tránh query lại.
 * Tên hiển thị ưu tiên common_material_name / short_name cho gọn (theo yêu cầu).
 * $has_product_files: sản phẩm (product_files) có file nào chưa — false thì báo thêm 1 dòng thiếu riêng.
 */
function pp_get_missing_docs($composition, $has_product_files = true)
{
    $out = [];
    if (!$has_product_files) {
        $out[] = [
            'bom_id' => 0,
            'material_name' => '(Sản phẩm)',
            'missing' => [['label' => 'Hồ sơ liên quan đến sản phẩm', 'entity_type' => '', 'entity_name' => '', 'kind' => 'product']],
        ];
    }
    if (empty($composition)) return $out;
    foreach ($composition as $item) {
        // Tên nguyên liệu ở modal này dùng TÊN HỆ THỐNG (material_name chính thức trong material_information),
        // KHÔNG ưu tiên common_material_name như standard-dossier/production_label — để in ra đối chiếu đúng
        // với tên đã đăng ký trong hệ thống.
        $material_display = $item['material_name'];
        $supplier_display = trim((string) ($item['supplier_short_name'] ?? '')) !== ''
            ? $item['supplier_short_name'] : $item['supplier_name'];

        // entity_type dùng để tô màu khác nhau trong view (NCC vs NVL) khi hiển thị tên trong nháy đơn.
        // kind dùng riêng cho bộ lọc theo LOẠI hồ sơ thiếu (product/supplier/material/invoice) — tách biệt
        // với entity_type vì "Thiếu hóa đơn" cũng gắn entity_type=material (tô cùng màu tên NVL) nhưng cần
        // lọc RIÊNG khỏi "Thiếu hồ sơ nguyên liệu".
        // NCC bị tắt "Xét hồ sơ" (Thiết lập NCC) -> không tính "Thiếu hồ sơ nhà cung cấp" cho NCC đó.
        $check_supplier_profile = !array_key_exists('supplier_check_profile', $item) || (int) $item['supplier_check_profile'] !== 0;
        $missing = [];
        if ($check_supplier_profile && empty($item['files']['supplier'])) {
            $missing[] = ['label' => 'Thiếu hồ sơ nhà cung cấp', 'entity_type' => 'supplier', 'entity_name' => $supplier_display, 'kind' => 'supplier'];
        }
        if (empty($item['files']['material'])) {
            $missing[] = ['label' => 'Thiếu hồ sơ nguyên liệu', 'entity_type' => 'material', 'entity_name' => $material_display, 'kind' => 'material'];
        }
        if (empty($item['files']['invoice'])) {
            $missing[] = ['label' => 'Thiếu hóa đơn mua hàng', 'entity_type' => 'material', 'entity_name' => $material_display, 'kind' => 'invoice'];
        }
        if ($missing) {
            $out[] = ['bom_id' => $item['bom_id'], 'material_name' => $material_display, 'material_info_id' => $item['material_info_id'], 'missing' => $missing];
        }
    }
    return $out;
}

/**
 * Tổng hợp "Hóa đơn, chứng từ còn thiếu" cho TẤT CẢ sản phẩm trong Bộ hồ sơ chuẩn (multi-product) —
 * dùng cho modal cùng tên ở standard-dossier.php. Chỉ trả về sản phẩm CÓ thiếu (bỏ qua sản phẩm đã đủ).
 */
function pp_get_missing_docs_for_standard_set()
{
    pp_ensure_standard_document_set_column();
    $products = db_fetch_array(
        "SELECT id, product_name FROM products WHERE standard_document_set = 1 ORDER BY product_name"
    ) ?: [];

    $result = [];
    foreach ($products as $p) {
        $pid = (int) $p['id'];
        $file_count = (int) (db_fetch_row("SELECT COUNT(*) AS c FROM product_files WHERE product_id = $pid")['c'] ?? 0);
        $composition = get_list_compositon($pid) ?: [];
        $missing = pp_get_missing_docs($composition, $file_count > 0);
        if (!empty($missing)) {
            $result[] = [
                'product_id' => $pid,
                'product_name' => $p['product_name'],
                'items' => $missing,
            ];
        }
    }
    return $result;
}

/**
 * Toàn bộ file HIỆN CÓ (không phải file thiếu) của các sản phẩm chỉ định — dùng để nén ZIP gửi nhà in từ
 * modal "Hóa đơn, chứng từ còn thiếu" ở standard-dossier.php. Cấu trúc trả về khớp với cây thư mục ZIP:
 * <product_name>/Hồ sơ sản phẩm/..., <product_name>/Thành phần cấu tạo/{Hồ sơ nhà cung cấp,Hồ sơ nguyên
 * liệu,Hóa đơn}/... — mỗi file kèm download_url + ext để client tải & đặt tên đúng khi nén.
 */
function pp_get_all_files_for_products($product_ids = null)
{
    $product_ids = array_values(array_unique(array_filter(array_map('intval', (array) $product_ids))));
    // Không truyền id cụ thể -> mặc định TOÀN BỘ sản phẩm trong Bộ hồ sơ chuẩn (dùng cho chế độ xem hệ
    // thống của modal "Hóa đơn, chứng từ đã có"; khi tải ZIP thì luôn truyền id cụ thể từ các card đang hiện).
    if (!$product_ids) {
        pp_ensure_standard_document_set_column();
        $product_ids = array_column(db_fetch_array("SELECT id FROM products WHERE standard_document_set = 1") ?: [], 'id');
        $product_ids = array_map('intval', $product_ids);
    }
    if (!$product_ids) return [];

    $base = '?mod=product_profile&controllers=product_profile&action=';
    $result = [];
    foreach ($product_ids as $pid) {
        $prow = db_fetch_row("SELECT product_name FROM products WHERE id = $pid");
        if (!$prow) continue;

        $product_files = db_fetch_array("SELECT id, file_name, file_path FROM product_files WHERE product_id = $pid ORDER BY id") ?: [];
        $pfiles = [];
        foreach ($product_files as $f) {
            $pfiles[] = [
                'id' => $f['id'],
                'kind' => 'product',
                'display_name' => $f['file_name'],
                'ext' => pathinfo($f['file_path'], PATHINFO_EXTENSION),
                'download_url' => $base . 'download_file&id_file=' . $f['id'],
            ];
        }

        $composition = get_list_compositon($pid) ?: [];
        $comp_out = [];
        foreach ($composition as $item) {
            $mat_display = trim((string) ($item['common_material_name'] ?? '')) !== '' ? $item['common_material_name'] : $item['material_name'];
            $sup = [];
            foreach (($item['files']['supplier'] ?? []) as $f) {
                $sup[] = ['id' => $f['id'], 'kind' => 'supplier', 'display_name' => $f['file_name'], 'ext' => pathinfo($f['file_path'], PATHINFO_EXTENSION), 'download_url' => $base . 'download_composition_file&file_id=' . $f['id'] . '&product_id=' . $pid];
            }
            $mat = [];
            foreach (($item['files']['material'] ?? []) as $f) {
                $mat[] = ['id' => $f['id'], 'kind' => 'material', 'display_name' => $f['file_name'], 'ext' => pathinfo($f['file_path'], PATHINFO_EXTENSION), 'download_url' => $base . 'download_composition_file&file_id=' . $f['id'] . '&product_id=' . $pid];
            }
            $inv = [];
            foreach (($item['files']['invoice'] ?? []) as $f) {
                $inv_display = $f['file_name'];
                if (pp_is_uuid_like($inv_display)) $inv_display = 'HĐ ' . $mat_display . ': ' . $inv_display;
                $inv[] = ['id' => $f['id'], 'kind' => 'invoice', 'display_name' => $inv_display, 'ext' => pathinfo($f['file_path'], PATHINFO_EXTENSION), 'download_url' => $base . 'download_material_invoice&invoice_id=' . $f['id'] . '&product_id=' . $pid];
            }
            if ($sup || $mat || $inv) {
                $comp_out[] = ['material_name' => $mat_display, 'material_info_id' => $item['material_info_id'], 'supplier' => $sup, 'material' => $mat, 'invoice' => $inv];
            }
        }

        if ($pfiles || $comp_out) {
            $result[] = [
                'product_id' => $pid,
                'product_name' => $prow['product_name'],
                'product_files' => $pfiles,
                'composition' => $comp_out,
            ];
        }
    }
    return $result;
}

/**
 * Danh sách sản phẩm TRONG BỘ HỒ SƠ CHUẨN có dùng 1 nguyên liệu cụ thể (theo material_info_id) — dùng cho
 * modal "click tên nguyên liệu -> xem sản phẩm nào dùng" ở modal "Hóa đơn, chứng từ đã có" (standard-dossier.php).
 */
function pp_get_standard_products_using_material($material_info_id)
{
    pp_ensure_standard_document_set_column();
    $material_info_id = (int) $material_info_id;
    if ($material_info_id <= 0) return [];

    return db_fetch_array(
        "SELECT DISTINCT p.id AS product_id, p.product_name
         FROM bill_of_materials bm
         JOIN products p ON p.id = bm.product_id
         WHERE bm.material_id = $material_info_id AND p.standard_document_set = 1
         ORDER BY p.product_name"
    ) ?: [];
}

/** 1 chuỗi có đúng dạng UUID (tên file tự sinh, chưa được người dùng đổi tên) hay không. */
function pp_is_uuid_like($s)
{
    return (bool) preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i', trim((string) $s));
}

/**
 * Danh sách ĐẦY ĐỦ file hồ sơ kèm tần suất — dùng cho modal "Tần suất". Trả về TOÀN BỘ file hiện có
 * (không lọc ngưỡng ở đây), nhóm theo file_name và đếm tần suất lặp lại theo từng nhóm:
 * - NCC/NVL (bảng files): đếm số ENTITY khác nhau (nhà cung cấp/nguyên liệu) đặt cùng 1 tên file.
 * - Hóa đơn (material_invoices): đếm số material_info_id khác nhau dùng chung 1 tên file.
 * - Hồ sơ doanh nghiệp (files, entity_type=company_profile) + Hồ sơ sản phẩm (product_files): đếm số
 *   product/entity khác nhau đặt cùng tên — thường = 1 vì đây vốn là tài liệu riêng theo từng sản phẩm.
 * Bộ lọc ngưỡng (Tất cả/>2/>3/>5) áp dụng phía trên (pp_get_file_frequency_flat() hoặc client-side ở
 * modal grouped) — hàm này luôn trả ĐỦ dữ liệu để "Tất cả" thực sự là tất cả, không thiếu file đơn lẻ.
 * $sample_id = 1 id đại diện (nhỏ nhất) để tải xuống — mỗi tên chỉ tải 1 bản đại diện, không tải hết bản trùng.
 *
 * $supplier_ids/$material_ids/$product_ids (mảng id hoặc null): truyền vào để giới hạn phạm vi theo 1 SẢN
 * PHẨM cụ thể (chỉ tính trong số NCC/NVL/file mà sản phẩm đó đang dùng) — dùng ở trang Chi tiết sản phẩm.
 * Truyền null (mặc định) = tính TOÀN HỆ THỐNG — dùng ở trang Danh sách sản phẩm.
 */
function pp_get_file_frequency($supplier_ids = null, $material_ids = null, $product_ids = null)
{
    $supplier_where = "entity_type = 'supplier'";
    if (is_array($supplier_ids)) {
        $ids = implode(',', array_map('intval', $supplier_ids));
        $supplier_where .= ' AND entity_id IN (' . ($ids !== '' ? $ids : '0') . ')';
    }
    $material_where = "entity_type = 'material'";
    $invoice_where = '1=1';
    if (is_array($material_ids)) {
        $ids = implode(',', array_map('intval', $material_ids));
        $material_where .= ' AND entity_id IN (' . ($ids !== '' ? $ids : '0') . ')';
        $invoice_where = 'material_info_id IN (' . ($ids !== '' ? $ids : '0') . ')';
    }
    $product_where = '1=1';
    if (is_array($product_ids)) {
        $ids = implode(',', array_map('intval', $product_ids));
        $product_where = 'product_id IN (' . ($ids !== '' ? $ids : '0') . ')';
    }

    $supplier = db_fetch_array(
        "SELECT file_name, COUNT(DISTINCT entity_id) AS freq, MIN(id) AS sample_id
         FROM files WHERE $supplier_where
         GROUP BY file_name ORDER BY freq DESC, file_name"
    ) ?: [];

    $material = db_fetch_array(
        "SELECT file_name, COUNT(DISTINCT entity_id) AS freq, MIN(id) AS sample_id
         FROM files WHERE $material_where
         GROUP BY file_name ORDER BY freq DESC, file_name"
    ) ?: [];

    pp_ensure_invoice_table();
    $invoice = db_fetch_array(
        "SELECT file_name, COUNT(DISTINCT material_info_id) AS freq, MIN(id) AS sample_id
         FROM material_invoices WHERE $invoice_where
         GROUP BY file_name ORDER BY freq DESC, file_name"
    ) ?: [];

    // Hồ sơ doanh nghiệp (entity_type = 'company_profile' trong CHÍNH bảng files) — tài liệu chung mọi sản phẩm.
    $company = db_fetch_array(
        "SELECT file_name, COUNT(*) AS freq, MIN(id) AS sample_id
         FROM files WHERE entity_type = 'company_profile'
         GROUP BY file_name ORDER BY freq DESC, file_name"
    ) ?: [];

    // Hồ sơ liên quan SẢN PHẨM (bảng product_files) — chip hiển thị dưới <h1> ở trang Chi tiết, gộp vào đây
    // để tiện tải hàng loạt gửi nhà in.
    $product = db_fetch_array(
        "SELECT file_name, COUNT(DISTINCT product_id) AS freq, MIN(id) AS sample_id
         FROM product_files WHERE $product_where
         GROUP BY file_name ORDER BY freq DESC, file_name"
    ) ?: [];

    // Gắn phần mở rộng file (để đặt đúng tên khi nén .zip phía client) — tra lại file_path theo sample_id.
    $file_ids = array_merge(array_column($supplier, 'sample_id'), array_column($material, 'sample_id'), array_column($company, 'sample_id'));
    $file_paths = [];
    if ($file_ids) {
        $ids = implode(',', array_map('intval', $file_ids));
        foreach (db_fetch_array("SELECT id, file_path FROM files WHERE id IN ($ids)") ?: [] as $r) {
            $file_paths[$r['id']] = $r['file_path'];
        }
    }
    $invoice_paths = [];
    $invoice_material_names = []; // sample_id -> tên nguyên liệu (viết tắt) của dòng hóa đơn đại diện
    $invoice_ids = array_column($invoice, 'sample_id');
    if ($invoice_ids) {
        $ids2 = implode(',', array_map('intval', $invoice_ids));
        foreach (db_fetch_array("SELECT id, file_path FROM material_invoices WHERE id IN ($ids2)") ?: [] as $r) {
            $invoice_paths[$r['id']] = $r['file_path'];
        }
        foreach (db_fetch_array(
            "SELECT inv.id, COALESCE(NULLIF(mi.common_material_name, ''), mi.material_name) AS mat_name
             FROM material_invoices inv
             LEFT JOIN material_information mi ON mi.id = inv.material_info_id
             WHERE inv.id IN ($ids2)"
        ) ?: [] as $r) {
            $invoice_material_names[$r['id']] = $r['mat_name'];
        }
    }
    $product_paths = [];
    $product_sample_ids = array_column($product, 'sample_id');
    if ($product_sample_ids) {
        $ids3 = implode(',', array_map('intval', $product_sample_ids));
        foreach (db_fetch_array("SELECT id, file_path FROM product_files WHERE id IN ($ids3)") ?: [] as $r) {
            $product_paths[$r['id']] = $r['file_path'];
        }
    }

    $base = '?mod=product_profile&controllers=product_profile&action=';
    foreach ($supplier as &$s) {
        $s['kind'] = 'supplier';
        $s['file_id'] = $s['sample_id'];
        $s['ext'] = pathinfo($file_paths[$s['sample_id']] ?? '', PATHINFO_EXTENSION);
        $s['download_url'] = $base . 'download_composition_file&file_id=' . $s['sample_id'];
        $s['display_name'] = $s['file_name'];
    }
    foreach ($material as &$m) {
        $m['kind'] = 'material';
        $m['file_id'] = $m['sample_id'];
        $m['ext'] = pathinfo($file_paths[$m['sample_id']] ?? '', PATHINFO_EXTENSION);
        $m['download_url'] = $base . 'download_composition_file&file_id=' . $m['sample_id'];
        $m['display_name'] = $m['file_name'];
    }
    foreach ($invoice as &$i) {
        $i['kind'] = 'invoice';
        $i['file_id'] = $i['sample_id'];
        $i['ext'] = pathinfo($invoice_paths[$i['sample_id']] ?? '', PATHINFO_EXTENSION);
        $i['download_url'] = $base . 'download_material_invoice&invoice_id=' . $i['sample_id'];
        // Tên hóa đơn dạng mã tự sinh (chưa đổi tên) -> ghép thêm tên nguyên liệu cho dễ nhận biết.
        // Đã đổi tên (không còn dạng UUID nữa) -> giữ nguyên tên gốc của file, không ghép cú pháp này.
        if (pp_is_uuid_like($i['file_name'])) {
            $mat_name = $invoice_material_names[$i['sample_id']] ?? '';
            $i['display_name'] = $mat_name !== '' ? ('HĐ ' . $mat_name . ': ' . $i['file_name']) : $i['file_name'];
        } else {
            $i['display_name'] = $i['file_name'];
        }
    }
    foreach ($company as &$c) {
        $c['kind'] = 'company';
        $c['file_id'] = $c['sample_id'];
        $c['ext'] = pathinfo($file_paths[$c['sample_id']] ?? '', PATHINFO_EXTENSION);
        $c['download_url'] = $base . 'download_composition_file&file_id=' . $c['sample_id'];
        $c['display_name'] = $c['file_name'];
    }
    foreach ($product as &$p) {
        $p['kind'] = 'product';
        $p['file_id'] = $p['sample_id'];
        $p['ext'] = pathinfo($product_paths[$p['sample_id']] ?? '', PATHINFO_EXTENSION);
        $p['download_url'] = $base . 'download_file&id_file=' . $p['sample_id'];
        $p['display_name'] = $p['file_name'];
    }
    unset($s, $m, $i, $c, $p);

    return [
        ['key' => 'supplier', 'label' => 'Hồ sơ nhà cung cấp', 'items' => $supplier],
        ['key' => 'material', 'label' => 'Hồ sơ nguyên liệu', 'items' => $material],
        ['key' => 'invoice',  'label' => 'Hóa đơn', 'items' => $invoice],
        ['key' => 'company',  'label' => 'Hồ sơ doanh nghiệp', 'items' => $company],
        ['key' => 'product',  'label' => 'Hồ sơ liên quan sản phẩm', 'items' => $product],
    ];
}

/**
 * Bản "phẳng" của pp_get_file_frequency() — gộp tất cả nhóm thành 1 mảng, sắp theo tần suất giảm dần,
 * dùng cho modal "Tần suất" ở trang Danh sách (toàn hệ thống, có phân trang nên cần 1 danh sách phẳng
 * để cắt trang thay vì nhiều danh sách riêng theo nhóm). $min_freq=1 (mặc định) = "Tất cả", không lọc gì.
 */
function pp_get_file_frequency_flat($min_freq = 1, $group_key = null)
{
    $groups = pp_get_file_frequency();
    $flat = [];
    foreach ($groups as $g) {
        if ($group_key && $group_key !== 'all' && $g['key'] !== $group_key) continue;
        foreach ($g['items'] as $it) {
            if ((int) $it['freq'] < $min_freq) continue;
            $it['group_key'] = $g['key'];
            $it['group_label'] = $g['label'];
            $flat[] = $it;
        }
    }
    usort($flat, function ($a, $b) {
        return ((int) $b['freq'] <=> (int) $a['freq']) ?: strcmp($a['file_name'], $b['file_name']);
    });
    return $flat;
}

/* =====================================================================
 *  "XEM THÀNH PHẦN" — chuỗi thành phần in trên nhãn sản phẩm
 *  ---------------------------------------------------------------------
 *  Nguồn: công thức 1 đơn vị sản phẩm (product_materials) + "Tên trên nhãn"
 *  (material_information.label_name, nhập ở admin_factory/manage_material_list).
 *  Quy tắc dựng chuỗi: xem pp_build_label_ingredients().
 *  Kết quả user sửa tay được lưu vào products.label_ingredients; khi cột này
 *  trống thì chuỗi luôn được dựng lại từ công thức (nút "Lấy theo công thức").
 * =====================================================================*/

/** Cột material_information.label_name ("Tên trên nhãn") — idempotent, giống bản ở adminModel. */
function pp_ensure_material_label_name_column()
{
    static $done = false;
    if ($done) return;
    $done = true;
    $existed = db_num_rows("SHOW COLUMNS FROM material_information LIKE 'label_name'") > 0;
    if (!$existed) {
        db_query("ALTER TABLE material_information ADD COLUMN label_name VARCHAR(255) DEFAULT NULL");
    }
}

/** Cột products.label_ingredients — chuỗi thành phần user đã sửa/chốt tay (NULL = theo công thức). */
function pp_ensure_label_ingredients_column()
{
    static $done = false;
    if ($done) return;
    $done = true;
    $existed = db_num_rows("SHOW COLUMNS FROM products LIKE 'label_ingredients'") > 0;
    if (!$existed) {
        db_query("ALTER TABLE products ADD COLUMN label_ingredients TEXT DEFAULT NULL");
    }
}

/**
 * Hệ số quy đổi đơn vị về đơn vị nhỏ (g/ml) để so sánh "dùng nhiều / dùng ít" giữa các
 * nguyên liệu khai báo khác đơn vị trong cùng 1 công thức. Đơn vị lạ coi như hệ số 1.
 */
function pp_label_unit_factor($unit)
{
    $u = mb_strtolower(trim((string) $unit), 'UTF-8');
    $u = str_replace([' ', '.'], '', $u);
    $big = ['kg', 'kgs', 'kilogram', 'kilo', 'l', 'lit', 'lít', 'liter', 'litre', 'lite'];
    return in_array($u, $big, true) ? 1000.0 : 1.0;
}

/** ucfirst có dấu (mb) — chữ đầu của thành phần đầu tiên viết hoa như trên nhãn. */
function pp_label_ucfirst($s)
{
    if ($s === '') return $s;
    return mb_strtoupper(mb_substr($s, 0, 1, 'UTF-8'), 'UTF-8') . mb_substr($s, 1, null, 'UTF-8');
}

/**
 * lcfirst có dấu (mb) — CHỈ hạ chữ cái đầu của thành phần, phần còn lại giữ nguyên
 * y như user nhập ở "Tên trên nhãn" (mã phụ gia trong ngoặc kiểu "(INS 102)", tên
 * riêng viết hoa giữa chuỗi... không bị đổi).
 */
function pp_label_lcfirst($s)
{
    if ($s === '') return $s;
    return mb_strtolower(mb_substr($s, 0, 1, 'UTF-8'), 'UTF-8') . mb_substr($s, 1, null, 'UTF-8');
}

/**
 * Chuẩn hóa chữ hoa/thường cho CẢ chuỗi thành phần (áp dụng cho bản dựng từ công thức
 * LẪN bản user sửa tay, để nhãn luôn đúng quy ước):
 * - Chỉ thành phần ĐẦU TIÊN (ngay sau "Thành phần: ") viết hoa chữ cái đầu.
 * - Mọi thành phần sau dấu phẩy: hạ ĐÚNG 1 chữ cái đầu về chữ thường.
 * - Chỉ đổi chữ cái đầu nên mã phụ gia trong ngoặc "(INS 110)" giữ nguyên như user nhập.
 * Cắt thành phần theo dấu phẩy Ở NGOÀI NGOẶC — nếu cắt theo mọi dấu phẩy thì cụm đã gộp
 * "chất bảo quản (INS 202, INS 211)" sẽ bị xé làm 2.
 */
function pp_label_normalize_case($text)
{
    $text = trim((string) $text);
    if ($text === '') return '';

    $has_prefix = (bool) preg_match('/^\s*Thành phần\s*:\s*(.*)$/us', $text, $m);
    $body = $has_prefix ? $m[1] : $text;

    $parts = [];
    $buf   = '';
    $depth = 0;
    $len   = mb_strlen($body, 'UTF-8');
    for ($i = 0; $i < $len; $i++) {
        $ch = mb_substr($body, $i, 1, 'UTF-8');
        if ($ch === '(') {
            $depth++;
        } elseif ($ch === ')') {
            $depth = max(0, $depth - 1);
        } elseif ($ch === ',' && $depth === 0) {
            $parts[] = $buf;
            $buf = '';
            continue;
        }
        $buf .= $ch;
    }
    $parts[] = $buf;

    $out = [];
    foreach ($parts as $p) {
        $p = trim($p);
        if ($p === '') continue;
        $out[] = empty($out) ? pp_label_ucfirst($p) : pp_label_lcfirst($p);
    }
    if (empty($out)) return '';

    return ($has_prefix ? 'Thành phần: ' : '') . implode(', ', $out);
}

/** Phần trăm hiển thị: số nguyên khi tròn, còn lại 1 số lẻ (dấu phẩy thập phân kiểu VN). */
function pp_label_format_percent($p)
{
    $r = round((float) $p, 1);
    if (abs($r - round($r)) < 0.05) {
        return (string) (int) round($r);
    }
    return str_replace('.', ',', rtrim(number_format($r, 1, '.', ''), '0'));
}

/**
 * Gộp các thành phần TRÙNG THUỘC TÍNH CHỨC NĂNG: phần trước dấu ngoặc giống nhau thì
 * viết 1 lần, nội dung trong ngoặc dồn lại cách nhau dấu phẩy, giữ vị trí lần xuất hiện
 * đầu tiên. Ví dụ:
 *   "chất bảo quản (INS 202)", "chất bảo quản (INS 211)"
 *   -> "chất bảo quản (INS 202, INS 211)"
 * Ngoặc chứa phần trăm (vd "Đường (78%)") KHÔNG gộp — đó là hàm lượng, không phải chức năng.
 */
function pp_label_merge_same_function(array $items)
{
    $slots     = [];
    $by_prefix = [];
    $by_plain  = [];
    foreach ($items as $item) {
        $item = trim($item);
        if ($item === '') continue;
        // Lấy prefix ngắn nhất + toàn bộ nội dung trong ngoặc CUỐI (cho phép ngoặc lồng,
        // vd "chất chống đông vón (INS 341 (iii))").
        if (preg_match('/^(.+?)\s*\((.*)\)$/u', $item, $m)) {
            $prefix = trim($m[1]);
            $inner  = trim($m[2]);
            if ($inner !== '' && mb_strpos($inner, '%') === false) {
                $key = mb_strtolower($prefix, 'UTF-8');
                if (isset($by_prefix[$key])) {
                    $i = $by_prefix[$key];
                    if (!in_array($inner, $slots[$i]['parens'], true)) {
                        $slots[$i]['parens'][] = $inner;
                    }
                    continue;
                }
                $by_prefix[$key] = count($slots);
                $slots[] = ['prefix' => $prefix, 'parens' => [$inner]];
                continue;
            }
        }
        // Không có ngoặc chức năng -> giữ nguyên, chỉ chống trùng y nguyên.
        $pk = mb_strtolower($item, 'UTF-8');
        if (isset($by_plain[$pk])) continue;
        $by_plain[$pk] = true;
        $slots[] = ['plain' => $item];
    }

    $out = [];
    foreach ($slots as $s) {
        $out[] = isset($s['plain'])
            ? $s['plain']
            : $s['prefix'] . ' (' . implode(', ', $s['parens']) . ')';
    }
    return $out;
}

/**
 * Dựng chuỗi "Thành phần: ..." từ công thức 1 đơn vị sản phẩm (product_materials).
 * - Tên hiển thị: label_name ("Tên trên nhãn") -> common_material_name -> material_name.
 * - Thứ tự: dùng nhiều viết trước, dùng ít viết sau (quy đổi kg/l về g/ml khi so sánh).
 * - Nguyên liệu chiếm TRÊN 50% khối lượng thì ghi kèm phần trăm trong ngoặc.
 * - Bao bì / nhãn (classification 'Bao bì trong' | 'Bao bì ngoài' | 'Nhãn') không phải
 *   thành phần nên bị loại; NVL chưa phân loại vẫn tính (mặc định coi là nguyên liệu).
 * - Gộp các thành phần cùng thuộc tính chức năng — xem pp_label_merge_same_function().
 * - Chỉ chữ cái đầu tiên của cả chuỗi viết hoa; các thành phần sau dấu phẩy hạ về chữ
 *   thường (chỉ chữ cái đầu, mã phụ gia trong ngoặc giữ nguyên như user nhập).
 * Trả về '' khi sản phẩm chưa có công thức.
 */
function pp_build_label_ingredients($product_id)
{
    pp_ensure_material_label_name_column();
    $pid = (int) $product_id;
    if ($pid <= 0) return '';

    $rows = db_fetch_array("
        SELECT pm.quantity_required AS qty,
               mi.material_name,
               mi.common_material_name,
               mi.label_name,
               mi.unit,
               mi.classification
        FROM product_materials pm
        JOIN material_information mi ON mi.id = pm.material_id
        WHERE pm.product_id = $pid
        ORDER BY pm.sort_order ASC, pm.id ASC
    ") ?: [];

    $skip = ['Bao bì trong', 'Bao bì ngoài', 'Nhãn'];
    $list = [];
    $total = 0.0;
    foreach ($rows as $i => $r) {
        if (in_array((string) ($r['classification'] ?? ''), $skip, true)) continue;
        $name = trim((string) ($r['label_name'] ?? ''));
        if ($name === '') $name = trim((string) ($r['common_material_name'] ?? ''));
        if ($name === '') $name = trim((string) ($r['material_name'] ?? ''));
        if ($name === '') continue;

        $qty = (float) $r['qty'] * pp_label_unit_factor($r['unit'] ?? '');
        $total += $qty;
        $list[] = ['name' => $name, 'qty' => $qty, 'pos' => $i];
    }
    if (empty($list)) return '';

    // Dùng nhiều trước; bằng nhau thì giữ thứ tự công thức (sort_order).
    usort($list, function ($a, $b) {
        if ($a['qty'] === $b['qty']) return $a['pos'] <=> $b['pos'];
        return $b['qty'] <=> $a['qty'];
    });

    $items = [];
    foreach ($list as $it) {
        $percent = $total > 0 ? ($it['qty'] / $total * 100) : 0;
        $items[] = $percent > 50
            ? $it['name'] . ' (' . pp_label_format_percent($percent) . '%)'
            : $it['name'];
    }
    $items = pp_label_merge_same_function($items);
    if (empty($items)) return '';

    // Chữ hoa/thường do pp_label_normalize_case() lo (dùng chung với bản sửa tay).
    $text = pp_label_normalize_case('Thành phần: ' . implode(', ', $items));
    if (!in_array(mb_substr($text, -1, 1, 'UTF-8'), ['.', '!', '?'], true)) {
        $text .= '.';
    }
    return $text;
}

/**
 * Dữ liệu cho bảng modal "Xem thành phần" của nhiều sản phẩm.
 * Mỗi dòng: id, product_name, generated (dựng từ công thức), saved (bản sửa tay),
 * text (bản để hiển thị = saved nếu có, ngược lại generated), is_saved.
 * $force_generate = true -> bỏ qua bản sửa tay (nút "Lấy theo công thức sản xuất").
 */
function pp_get_label_ingredients($product_ids, $force_generate = false)
{
    pp_ensure_label_ingredients_column();
    $ids = [];
    foreach ((array) $product_ids as $id) {
        $id = (int) $id;
        if ($id > 0) $ids[$id] = $id;
    }
    if (empty($ids)) return [];

    $in   = implode(',', $ids);
    $rows = db_fetch_array("SELECT id, product_name, label_ingredients
                            FROM products WHERE id IN ($in)") ?: [];
    // Giữ đúng thứ tự user chọn.
    $by_id = [];
    foreach ($rows as $r) {
        $by_id[(int) $r['id']] = $r;
    }

    $out = [];
    foreach ($ids as $id) {
        if (!isset($by_id[$id])) continue;
        $r = $by_id[$id];
        // Bản sửa tay cũng chạy qua chuẩn hóa hoa/thường: quy ước viết nhãn áp cho MỌI
        // chuỗi hiển thị, không riêng bản dựng từ công thức (nhờ vậy các bản đã lưu từ
        // trước cũng tự đúng, không cần migrate dữ liệu).
        $saved     = pp_label_normalize_case((string) ($r['label_ingredients'] ?? ''));
        $generated = pp_build_label_ingredients($id);
        $use_saved = !$force_generate && trim($saved) !== '';
        $out[] = [
            'id'           => $id,
            'product_name' => (string) $r['product_name'],
            'generated'    => $generated,
            'saved'        => $saved,
            'text'         => $use_saved ? $saved : $generated,
            'is_saved'     => $use_saved,
        ];
    }
    return $out;
}

/**
 * Lưu chuỗi thành phần user sửa tay. Chuỗi trống -> NULL, tức là quay về "luôn dựng lại
 * theo công thức sản xuất" (đúng hành vi nút "Lấy theo công thức sản xuất").
 * Lưu bản ĐÃ chuẩn hóa hoa/thường; và nếu sửa xong y hệt bản dựng từ công thức thì cũng
 * lưu NULL — tránh "ghim" nhầm 1 bản tĩnh chỉ vì user bấm vào ô rồi rời ra.
 */
function pp_save_label_ingredients($product_id, $text)
{
    pp_ensure_label_ingredients_column();
    $pid = (int) $product_id;
    if ($pid <= 0) return false;
    $text = pp_label_normalize_case($text);
    if ($text !== '' && $text === pp_build_label_ingredients($pid)) {
        $text = '';
    }
    db_update('products', ['label_ingredients' => $text === '' ? null : $text], "id = $pid");
    return true;
}
