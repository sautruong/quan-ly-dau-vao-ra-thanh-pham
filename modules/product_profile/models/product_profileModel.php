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

    // 6. Tạo file_code (4 chữ cái đầu mỗi từ + tháng.năm)
    $file_code = generate_file_code($file_label);

    // 7. Đường dẫn lưu vào DB (relative to public/uploads/)
    $db_file_path = 'product_profile/' . $product_name . '/' . $safe_filename;

    // 8. Lưu vào database
    $product_id_int = (int) $product_id;
    $file_code_safe  = mysqli_real_escape_string($conn, $file_code);
    $file_label_safe = mysqli_real_escape_string($conn, $file_label);
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

    // 8. Lưu vào database
    $entity_id_int = (int) $material_id;
    $file_name_safe = mysqli_real_escape_string($conn, $file_label);
    $file_path_safe = mysqli_real_escape_string($conn, $db_file_path);

    $sql = "INSERT INTO files (file_name, file_path, entity_type, entity_id)
            VALUES ('$file_name_safe', '$file_path_safe', 'material', $entity_id_int)";

    if (!mysqli_query($conn, $sql)) {
        return ['success' => false, 'message' => 'Lỗi lưu database: ' . mysqli_error($conn)];
    }

    return ['success' => true, 'message' => 'Thêm file thành công.'];
}
// PAGE DETAIL
function get_info_product_basic_by_id($id)
{
    //Chuỗi truy vấn
    $sql = "SELECT ib.unit,
               ib.inner_packaging,
               ib.outer_packaging,
               ib.inner_packaging_spec,
               ib.outer_packaging_spec,
               products.image_url,
               products.product_name
        FROM product_info_basic AS ib
        LEFT JOIN products ON ib.product_id = products.id
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
function get_list_compositon($product_id)
{
    $sql = "SELECT bm.material_id,
                   mi.material_name,
                   mi.supplier_id as mi_supplier_id,
                   mi.id AS material_info_id,
                   s.id AS supplier_id,
                   s.supplier_name
            FROM bill_of_materials AS bm
            LEFT JOIN material_information AS mi 
                ON bm.material_id = mi.id
            LEFT JOIN suppliers AS s
                ON mi.supplier_id = s.id
            WHERE bm.product_id = $product_id";

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
    // show_array($files);

    // Gắn file vào từng phần tử
    foreach ($composition as &$item) {
        $m_id = $item['material_info_id'];
        $s_id = $item['supplier_id'];

        $item['files'] = [
            'supplier' => $files['supplier'][$s_id] ?? [],
            'material' => $files['material'][$m_id] ?? []
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
