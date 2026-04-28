<?php

function construct()
{
    load_model('product_profile');
}
function dashboardAction()
{

    load_view('dashboard');
}
function listAction()
{
    global $conn;
    //Xử lý lấy danh sách tổng thể  theo nhóm = vòng lặp| xử lý lấy mảng $product từ database
    $sql = "SELECT 
    pc.category_name,
    p.id AS product_id,
    p.product_name,
    p.has_nutrition_fact,
    p.image_url,
    pf.id AS file_id,
    pf.file_code,
    pf.file_name,
    pf.file_path
FROM product_categories pc
LEFT JOIN products p ON p.category_id = pc.id
LEFT JOIN product_files pf ON pf.product_id = p.id
ORDER BY pc.category_name, p.id, pf.id";
    $result = mysqli_query($conn, $sql);

    $data = [];

    while ($row = mysqli_fetch_assoc($result)) {
        $category = $row['category_name'];
        $productId = $row['product_id'];

        // 1. Tạo category nếu chưa có
        if (!isset($data[$category])) {
            $data[$category] = [];
        }

        // 2. Tạo product nếu chưa có
        if (!isset($data[$category][$productId])) {
            $data[$category][$productId] = [
                'product_name' => $row['product_name'],
                'has_nutrition_fact' => $row['has_nutrition_fact'],
                'image_url' => 'public/images/' . $row['image_url'],
                'list_file' => []
            ];
        }

        // 3. Thêm file nếu có
        if (!empty($row['file_id'])) {
            $data[$category][$productId]['list_file'][] = [
                'file_id' => $row['file_id'],
                'file_code' => $row['file_code'],
                'file_name' => $row['file_name'],
                'file_path' => 'public/uploads/' . $row['file_path']
            ];
        }
    }
    //    show_array($data);

    load_view('list', $data);
}
function add_fileAction()
{
    $id = $_GET['id'];
    global $conn;
    //Lấy tên
    $sql = "SELECT
        p.product_name 
        FROM products as p
        WHERE p.id = $id
    ";
    $product_name = db_fetch_array($sql, $conn);
    $product_name[0]['id'] = $id;
    $data =  $product_name[0];
    // show_array($product_name);
    load_view('add-file',  $data);
}
function update_fileAction()
{
    $id_product = (int) $_GET['id_product'];
    $id_file = (int) $_GET['id_file'];
    global $conn;

    $sql = "SELECT
        p.product_name,
        pf.id AS file_id,
        pf.file_name,
        pf.file_path
        FROM products AS p
        LEFT JOIN product_files AS pf ON pf.product_id = p.id AND pf.id = $id_file
        WHERE p.id = $id_product
    ";
    $result = db_fetch_array($sql, $conn);
    $data = $result[0];
    $data['id_product'] = $id_product;
    $data['id_file'] = $id_file;
    load_view('update-file', $data);
}

function download_fileAction()
{
    global $conn;

    $id_file = (int) $_GET['id_file'];

    // Lấy thông tin file
    $sql = "SELECT file_name, file_path FROM product_files WHERE id = $id_file";
    $result = db_fetch_array($sql, $conn);

    if (empty($result)) {
        header("Location: ?mod=product_profile&controllers=product_profile&action=list");
        exit;
    }

    $file_path = 'public/uploads/' . $result[0]['file_path'];
    $file_name = $result[0]['file_name'];

    // Lấy extension từ file thực tế để ghép vào tên tải về
    $ext = pathinfo($file_path, PATHINFO_EXTENSION);
    $download_name = $file_name . '.' . $ext;

    if (!file_exists($file_path)) {
        header("Location: ?mod=product_profile&controllers=product_profile&action=list&error=" . urlencode('File không tồn tại trên server.'));
        exit;
    }

    // Force download - trình duyệt sẽ mở hộp thoại "Save As"
    header('Content-Description: File Transfer');
    header('Content-Type: application/octet-stream');
    header('Content-Disposition: attachment; filename="' . $download_name . '"');
    header('Content-Length: ' . filesize($file_path));
    header('Cache-Control: must-revalidate');
    header('Pragma: public');
    ob_end_clean();
    readfile($file_path);
    exit;
}

function ready_add_fileAction()
{
    global $conn;

    $product_id = (int) $_GET['id'];
    $file_label = trim($_POST['file_label'] ?? '');

    // Lấy product_name để đặt tên folder
    $sql = "SELECT product_name FROM products WHERE id = $product_id";
    $result = db_fetch_array($sql, $conn);
    $product_name = $result[0]['product_name'] ?? '';

    // Gọi hàm model xử lý upload + insert DB
    $response = upload_product_file($product_id, $product_name, $file_label, $_FILES['file_upload']);

    if (!$response['success']) {
        // Trả về form với thông báo lỗi
        header("Location: ?mod=product_profile&controllers=product_profile&action=add_file&id=$product_id&error=" . urlencode($response['message']));
        exit;
    }

    header("Location: ?mod=product_profile&controllers=product_profile&action=list");
    exit;
}
function ready_add_file_supplierAction()
{
    global $conn;

    $supplier_id = (int) $_GET['id'];
    $product_id = (int) ($_GET['product_id'] ?? 0);
    $file_label = trim($_POST['file_label'] ?? '');

    // Lấy tên nhà cung cấp để đặt tên folder
    $sql = "SELECT supplier_name FROM suppliers WHERE id = $supplier_id";
    $result = db_fetch_array($sql, $conn);
    $supplier_name = $result[0]['supplier_name'] ?? '';

    // Gọi hàm model xử lý upload + insert DB
    $response = upload_supplier_file($supplier_id, $supplier_name, $file_label, $_FILES['file_upload']);

    if (!$response['success']) {
        header("Location: ?mod=product_profile&controllers=product_profile&action=add_file_supplier&id=$supplier_id&product_id=$product_id&error=" . urlencode($response['message']));
        exit;
    }

    header("Location: ?mod=product_profile&controllers=product_profile&action=product_detail&id=$product_id");
    exit;
}

function delete_fileAction()
{
    global $conn;

    $id_file = (int) $_GET['id_file'];

    // 1. Lấy file_path trước khi xóa
    $sql = "SELECT file_path FROM product_files WHERE id = $id_file";
    $result = db_fetch_array($sql, $conn);

    if (!empty($result)) {
        // 2. Xóa file vật lý
        $file_path = 'public/uploads/' . $result[0]['file_path'];
        if (file_exists($file_path)) {
            unlink($file_path);
        }

        // 3. Xóa record trong database
        $sql_delete = "DELETE FROM product_files WHERE id = $id_file";
        mysqli_query($conn, $sql_delete);
    }

    // 4. Quay về danh sách
    header("Location: ?mod=product_profile&controllers=product_profile&action=list");
    exit;
}

function ready_update_fileAction()
{
    global $conn;

    $id_product = (int) $_GET['id_product'];
    $id_file = (int) $_GET['id_file'];
    $file_label = trim($_POST['file_label'] ?? '');

    // 1. Lấy thông tin file cũ + tên sản phẩm
    $sql = "SELECT p.product_name, pf.file_path
            FROM product_files pf
            JOIN products p ON p.id = pf.product_id
            WHERE pf.id = $id_file AND pf.product_id = $id_product";
    $result = db_fetch_array($sql, $conn);

    if (empty($result)) {
        header("Location: ?mod=product_profile&controllers=product_profile&action=list&error=" . urlencode('Không tìm thấy file.'));
        exit;
    }

    $product_name = $result[0]['product_name'];
    $old_file_path = 'public/uploads/' . $result[0]['file_path'];

    // 2. Xóa file vật lý cũ
    if (file_exists($old_file_path)) {
        unlink($old_file_path);
    }

    // 3. Xóa record cũ trong database
    $sql_delete = "DELETE FROM product_files WHERE id = $id_file AND product_id = $id_product";
    mysqli_query($conn, $sql_delete);

    // 4. Upload file mới bằng hàm có sẵn
    $response = upload_product_file($id_product, $product_name, $file_label, $_FILES['file_upload']);

    if (!$response['success']) {
        header("Location: ?mod=product_profile&controllers=product_profile&action=update_file&id_product=$id_product&id_file=$id_file&error=" . urlencode($response['message']));
        exit;
    }

    header("Location: ?mod=product_profile&controllers=product_profile&action=list");
    exit;
}
function add_productAction()
{
    // Lấy danh mục từ DB
    $sql = "SELECT id, category_name, category_code FROM product_categories ORDER BY id";
    $categories = db_fetch_array($sql);
    $data = ['categories' => $categories];
    load_view('add-product', $data);
}

function search_materialAction()
{
    global $conn;
    header('Content-Type: application/json');

    $keyword = trim($_POST['keyword'] ?? '');
    if (empty($keyword)) {
        echo json_encode(['data' => []]);
        exit;
    }

    $keyword_safe = mysqli_real_escape_string($conn, $keyword);
    $sql = "SELECT mi.id, mi.material_name, s.supplier_name
            FROM material_information AS mi
            LEFT JOIN suppliers AS s ON mi.supplier_id = s.id
            WHERE mi.material_name LIKE '%$keyword_safe%'
            LIMIT 20";
    $result = db_fetch_array($sql, $conn);

    echo json_encode(['data' => $result ?: []]);
    exit;
}

function ready_add_productAction()
{
    global $conn;

    $product_name = trim($_POST['product_name'] ?? '');
    $category_id = (int) ($_POST['category_id'] ?? 0);

    // Validate tên sản phẩm
    if (empty($product_name)) {
        header("Location: ?mod=product_profile&controllers=product_profile&action=add_product&error=" . urlencode('Tên sản phẩm không được bỏ trống.'));
        exit;
    }

    // Validate danh mục
    if ($category_id <= 0) {
        header("Location: ?mod=product_profile&controllers=product_profile&action=add_product&error=" . urlencode('Vui lòng chọn danh mục.'));
        exit;
    }

    // Validate hình ảnh
    $file = $_FILES['product_image'] ?? null;
    if (empty($file['name']) || $file['error'] !== UPLOAD_ERR_OK) {
        header("Location: ?mod=product_profile&controllers=product_profile&action=add_product&error=" . urlencode('Vui lòng chọn hình ảnh.'));
        exit;
    }

    $allowed_ext = ['jpg', 'jpeg', 'png', 'gif', 'webp', 'bmp'];
    $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
    if (!in_array($ext, $allowed_ext)) {
        header("Location: ?mod=product_profile&controllers=product_profile&action=add_product&error=" . urlencode('Chỉ chấp nhận file hình ảnh (JPG, PNG, JPEG, GIF, WEBP, BMP).'));
        exit;
    }

    // Lấy category_code
    $sql_cat = "SELECT category_code FROM product_categories WHERE id = $category_id";
    $cat_result = db_fetch_array($sql_cat);
    if (empty($cat_result)) {
        header("Location: ?mod=product_profile&controllers=product_profile&action=add_product&error=" . urlencode('Danh mục không hợp lệ.'));
        exit;
    }
    $category_code = $cat_result[0]['category_code'];

    // Insert product (chưa có image_url, sẽ update sau khi có id)
    $product_name_safe = mysqli_real_escape_string($conn, $product_name);
    $sql_insert = "INSERT INTO products (category_id, product_name, has_nutrition_fact)
                   VALUES ($category_id, '$product_name_safe', 0)";
    mysqli_query($conn, $sql_insert);
    $product_id = mysqli_insert_id($conn);

    // Tạo product_code: VAT_TP_<id>
    $product_code = 'VAT_TP_' . $product_id;

    // Tạo thư mục lưu hình ảnh: public/images/product_profile/<category_code>/
    $upload_dir = 'public/images/product_profile/' . $category_code . '/';
    if (!is_dir($upload_dir)) {
        mkdir($upload_dir, 0755, true);
    }

    // Đặt tên file an toàn (thay khoảng trắng bằng -)
    $safe_filename = str_replace(' ', '-', mb_strtoupper($product_name, 'UTF-8')) . '.' . $ext;
    $dest_path = $upload_dir . $safe_filename;

    if (!move_uploaded_file($file['tmp_name'], $dest_path)) {
        header("Location: ?mod=product_profile&controllers=product_profile&action=add_product&error=" . urlencode('Không thể lưu hình ảnh lên server.'));
        exit;
    }

    // image_url lưu trong DB (relative to public/images/)
    $image_url = 'product_profile/' . $category_code . '/' . $safe_filename;
    $image_url_safe = mysqli_real_escape_string($conn, $image_url);
    $product_code_safe = mysqli_real_escape_string($conn, $product_code);

    $sql_update = "UPDATE products SET product_code = '$product_code_safe', image_url = '$image_url_safe' WHERE id = $product_id";
    mysqli_query($conn, $sql_update);

    // Insert thông tin căn bản vào product_info_basic
    $unit = mysqli_real_escape_string($conn, trim($_POST['unit'] ?? ''));
    $inner_packaging = mysqli_real_escape_string($conn, trim($_POST['inner_packaging'] ?? ''));
    $outer_packaging = mysqli_real_escape_string($conn, trim($_POST['outer_packaging'] ?? ''));
    $inner_packaging_spec = mysqli_real_escape_string($conn, trim($_POST['inner_packaging_spec'] ?? ''));
    $outer_packaging_spec = mysqli_real_escape_string($conn, trim($_POST['outer_packaging_spec'] ?? ''));

    $sql_basic = "INSERT INTO product_info_basic (product_id, unit, inner_packaging, outer_packaging, inner_packaging_spec, outer_packaging_spec)
                  VALUES ($product_id, '$unit', '$inner_packaging', '$outer_packaging', '$inner_packaging_spec', '$outer_packaging_spec')";
    mysqli_query($conn, $sql_basic);

    // Insert chính sách giá vào pricing_policies
    $cost_price = floatval($_POST['cost_price'] ?? 0);
    $system_price = floatval($_POST['system_price'] ?? 0);
    $retail_price = floatval($_POST['retail_price'] ?? 0);

    if ($cost_price > 0 || $system_price > 0 || $retail_price > 0) {
        $sql_pricing = "INSERT INTO pricing_policies (product_id, cost_price, system_price, retail_price)
                        VALUES ($product_id, $cost_price, $system_price, $retail_price)";
        mysqli_query($conn, $sql_pricing);
    }

    // Insert thành phần cấu tạo vào bill_of_materials
    if (!empty($_POST['material_ids'])) {
        foreach ($_POST['material_ids'] as $material_id_val) {
            $material_id_int = (int) $material_id_val;
            if ($material_id_int > 0) {
                $sql_bom = "INSERT INTO bill_of_materials (product_id, material_id) VALUES ($product_id, $material_id_int)";
                mysqli_query($conn, $sql_bom);
            }
        }
    }

    header("Location: ?mod=product_profile&controllers=product_profile&action=list");
    exit;
}
function edit_productAction()
{
    global $conn;
    $id = (int) $_GET['id'];

    // Lấy thông tin sản phẩm
    $sql_product = "SELECT p.id, p.product_name, p.category_id, p.image_url
                    FROM products p WHERE p.id = $id";
    $product = db_fetch_array($sql_product, $conn);
    if (empty($product)) {
        header("Location: ?mod=product_profile&controllers=product_profile&action=list");
        exit;
    }

    // Lấy danh mục
    $sql_cat = "SELECT id, category_name, category_code FROM product_categories ORDER BY id";
    $categories = db_fetch_array($sql_cat, $conn);

    // Lấy thông tin căn bản
    $sql_basic = "SELECT unit, inner_packaging, outer_packaging, inner_packaging_spec, outer_packaging_spec
                  FROM product_info_basic WHERE product_id = $id";
    $basic = db_fetch_array($sql_basic, $conn);

    // Lấy thành phần cấu tạo
    $sql_bom = "SELECT mi.id, mi.material_name, s.supplier_name
                FROM bill_of_materials bm
                LEFT JOIN material_information mi ON bm.material_id = mi.id
                LEFT JOIN suppliers s ON mi.supplier_id = s.id
                WHERE bm.product_id = $id";
    $materials = db_fetch_array($sql_bom, $conn);

    // Lấy chính sách giá
    $sql_pricing = "SELECT cost_price, system_price, retail_price FROM pricing_policies WHERE product_id = $id LIMIT 1";
    $pricing = db_fetch_array($sql_pricing, $conn);

    $data = [
        'product' => $product[0],
        'categories' => $categories,
        'basic' => !empty($basic) ? $basic[0] : [],
        'materials' => $materials ?: [],
        'pricing' => !empty($pricing) ? $pricing[0] : []
    ];

    load_view('edit-product', $data);
}

function ready_edit_productAction()
{
    global $conn;

    $product_id = (int) $_GET['id'];
    $product_name = trim($_POST['product_name'] ?? '');
    $category_id = (int) ($_POST['category_id'] ?? 0);

    // Validate
    if (empty($product_name)) {
        header("Location: ?mod=product_profile&controllers=product_profile&action=edit_product&id=$product_id&error=" . urlencode('Tên sản phẩm không được bỏ trống.'));
        exit;
    }
    if ($category_id <= 0) {
        header("Location: ?mod=product_profile&controllers=product_profile&action=edit_product&id=$product_id&error=" . urlencode('Vui lòng chọn danh mục.'));
        exit;
    }

    $product_name_safe = mysqli_real_escape_string($conn, $product_name);

    // Xử lý hình ảnh nếu có upload mới
    $file = $_FILES['product_image'] ?? null;
    if (!empty($file['name']) && $file['error'] === UPLOAD_ERR_OK) {
        $allowed_ext = ['jpg', 'jpeg', 'png', 'gif', 'webp', 'bmp'];
        $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
        if (!in_array($ext, $allowed_ext)) {
            header("Location: ?mod=product_profile&controllers=product_profile&action=edit_product&id=$product_id&error=" . urlencode('Chỉ chấp nhận file hình ảnh.'));
            exit;
        }

        // Lấy category_code
        $sql_cat = "SELECT category_code FROM product_categories WHERE id = $category_id";
        $cat_result = db_fetch_array($sql_cat, $conn);
        $category_code = $cat_result[0]['category_code'] ?? '';

        // Xóa hình cũ
        $sql_old = "SELECT image_url FROM products WHERE id = $product_id";
        $old = db_fetch_array($sql_old, $conn);
        if (!empty($old[0]['image_url'])) {
            $old_path = 'public/images/' . $old[0]['image_url'];
            if (file_exists($old_path)) unlink($old_path);
        }

        // Upload hình mới
        $upload_dir = 'public/images/product_profile/' . $category_code . '/';
        if (!is_dir($upload_dir)) mkdir($upload_dir, 0755, true);

        $safe_filename = str_replace(' ', '-', mb_strtoupper($product_name, 'UTF-8')) . '.' . $ext;
        $dest_path = $upload_dir . $safe_filename;
        move_uploaded_file($file['tmp_name'], $dest_path);

        $image_url = 'product_profile/' . $category_code . '/' . $safe_filename;
        $image_url_safe = mysqli_real_escape_string($conn, $image_url);

        $sql_update = "UPDATE products SET product_name = '$product_name_safe', category_id = $category_id, image_url = '$image_url_safe' WHERE id = $product_id";
    } else {
        $sql_update = "UPDATE products SET product_name = '$product_name_safe', category_id = $category_id WHERE id = $product_id";
    }
    mysqli_query($conn, $sql_update);

    // Cập nhật thông tin căn bản
    $unit = mysqli_real_escape_string($conn, trim($_POST['unit'] ?? ''));
    $inner_packaging = mysqli_real_escape_string($conn, trim($_POST['inner_packaging'] ?? ''));
    $outer_packaging = mysqli_real_escape_string($conn, trim($_POST['outer_packaging'] ?? ''));
    $inner_packaging_spec = mysqli_real_escape_string($conn, trim($_POST['inner_packaging_spec'] ?? ''));
    $outer_packaging_spec = mysqli_real_escape_string($conn, trim($_POST['outer_packaging_spec'] ?? ''));

    // Kiểm tra đã có bản ghi chưa
    $sql_check = "SELECT product_id FROM product_info_basic WHERE product_id = $product_id";
    $exists = db_fetch_array($sql_check, $conn);

    if (!empty($exists)) {
        $sql_basic = "UPDATE product_info_basic SET
                        unit = '$unit',
                        inner_packaging = '$inner_packaging',
                        outer_packaging = '$outer_packaging',
                        inner_packaging_spec = '$inner_packaging_spec',
                        outer_packaging_spec = '$outer_packaging_spec'
                      WHERE product_id = $product_id";
    } else {
        $sql_basic = "INSERT INTO product_info_basic (product_id, unit, inner_packaging, outer_packaging, inner_packaging_spec, outer_packaging_spec)
                      VALUES ($product_id, '$unit', '$inner_packaging', '$outer_packaging', '$inner_packaging_spec', '$outer_packaging_spec')";
    }
    mysqli_query($conn, $sql_basic);

    // Cập nhật chính sách giá
    $cost_price = floatval($_POST['cost_price'] ?? 0);
    $system_price = floatval($_POST['system_price'] ?? 0);
    $retail_price = floatval($_POST['retail_price'] ?? 0);

    $sql_check_pricing = "SELECT product_id FROM pricing_policies WHERE product_id = $product_id";
    $pricing_exists = db_fetch_array($sql_check_pricing, $conn);

    if (!empty($pricing_exists)) {
        $sql_pricing = "UPDATE pricing_policies SET cost_price = $cost_price, system_price = $system_price, retail_price = $retail_price WHERE product_id = $product_id";
    } else {
        $sql_pricing = "INSERT INTO pricing_policies (product_id, cost_price, system_price, retail_price) VALUES ($product_id, $cost_price, $system_price, $retail_price)";
    }
    mysqli_query($conn, $sql_pricing);

    // Cập nhật thành phần cấu tạo: xóa cũ, thêm mới
    $sql_delete_bom = "DELETE FROM bill_of_materials WHERE product_id = $product_id";
    mysqli_query($conn, $sql_delete_bom);

    if (!empty($_POST['material_ids'])) {
        foreach ($_POST['material_ids'] as $material_id_val) {
            $material_id_int = (int) $material_id_val;
            if ($material_id_int > 0) {
                $sql_bom = "INSERT INTO bill_of_materials (product_id, material_id) VALUES ($product_id, $material_id_int)";
                mysqli_query($conn, $sql_bom);
            }
        }
    }

    header("Location: ?mod=product_profile&controllers=product_profile&action=product_detail&id=$product_id");
    exit;
}

function delete_productAction()
{
    global $conn;

    $id = (int) $_GET['id'];

    // 1. Lấy tất cả file_path của sản phẩm này để xóa file vật lý
    $sql_files = "SELECT file_path FROM product_files WHERE product_id = $id";
    $files = db_fetch_array($sql_files);

    foreach ($files as $file) {
        $file_path = 'public/uploads/' . $file['file_path'];
        if (file_exists($file_path)) {
            unlink($file_path);
        }
    }

    // 2. Xóa tất cả record trong product_files (bảng con)
    $sql_delete_files = "DELETE FROM product_files WHERE product_id = $id";
    mysqli_query($conn, $sql_delete_files);

    // 3. Lấy image_url để xóa hình ảnh sản phẩm
    $sql_product = "SELECT image_url FROM products WHERE id = $id";
    $product = db_fetch_array($sql_product);
    if (!empty($product)) {
        $image_path = 'public/images/' . $product[0]['image_url'];
        if (file_exists($image_path)) {
            unlink($image_path);
        }
    }

    // 4. Xóa sản phẩm (bảng cha)
    $sql_delete_product = "DELETE FROM products WHERE id = $id";
    mysqli_query($conn, $sql_delete_product);

    header("Location: ?mod=product_profile&controllers=product_profile&action=list");
    exit;
}
function product_detailAction()
{
    $id = (int)$_GET['id'];
    # Lấy tông tin căn bản sản phẩm
    $info_product_basic = get_info_product_basic_by_id($id);

    # Lấy thành list thành phần cấu tạo và nhà cung cấp
    $composition_of_product = get_list_compositon($id);

    # Lấy chính sách giá từ bảng pricing_policies
    $pricing = get_pricing_policy_by_product_id($id);

    if ($info_product_basic == false) {
        $data['info'] = "";
    } else {
        $data['info'] = $info_product_basic;
    }

    if ($composition_of_product == false) {
        $data['composition'] = "";
    } else {
        $data['composition'] = $composition_of_product;
    }

    $data['pricing'] = $pricing ?: ['cost_price' => 0, 'system_price' => 0, 'retail_price' => 0];
    $data['product_id'] = $id;
    load_view('detail', $data);

}
function process_table_priceAction()
{
    header('Content-Type: application/json');

    $actionType = $_POST['action_type'] ?? '';

    if ($actionType === 'calc_step') {
        // ===== TÍNH BƯỚC NHẢY: từ 3 giá → 3 step =====
        $capital   = floatval($_POST['capital_price'] ?? 0);
        $wholesale = floatval($_POST['wholesale_price'] ?? 0);
        $retail    = floatval($_POST['retail_price'] ?? 0);

        // (4) = (2) / (1)
        $step12 = ($capital > 0) ? $wholesale / $capital : 0;
        // (5) = (3) / (2)
        $step23 = ($wholesale > 0) ? $retail / $wholesale : 0;
        // (6) = (3) / (1)
        $step13 = ($capital > 0) ? $retail / $capital : 0;

        echo json_encode([
            'success' => true,
            'data' => [
                'step_1_2' => round($step12, 2),
                'step_2_3' => round($step23, 2),
                'step_1_3' => round($step13, 2),
            ]
        ]);
    } elseif ($actionType === 'calc_price') {
        // ===== TÍNH GIÁ: giá vốn CỐ ĐỊNH, từ step → tính wholesale & retail =====
        $capital = floatval($_POST['capital_price'] ?? 0);
        $step12  = floatval($_POST['step_1_2'] ?? 0);
        $step23  = floatval($_POST['step_2_3'] ?? 0);
        $step13  = floatval($_POST['step_1_3'] ?? 0);

        // (2) = (1) * (4)
        $wholesale = ($capital > 0 && $step12 > 0) ? $capital * $step12 : 0;
        // (3) = (1) * (6)  — ưu tiên step13 (trực tiếp từ giá vốn)
        // nếu không có step13, dùng (2) * (5)
        if ($capital > 0 && $step13 > 0) {
            $retail = $capital * $step13;
        } elseif ($wholesale > 0 && $step23 > 0) {
            $retail = $wholesale * $step23;
        } else {
            $retail = 0;
        }

        echo json_encode([
            'success' => true,
            'data' => [
                'wholesale_price' => round($wholesale / 1000) * 1000,
                'retail_price'    => round($retail / 1000) * 1000,
            ]
        ]);
    } else {
        echo json_encode(['success' => false, 'message' => 'Invalid action_type']);
    }

    exit;
}
function update_pricing_policyAction()
{
    header('Content-Type: application/json');
    global $conn;

    $product_id = (int) ($_POST['product_id'] ?? 0);
    $system_price = floatval($_POST['system_price'] ?? 0);
    $retail_price = floatval($_POST['retail_price'] ?? 0);

    if ($product_id <= 0) {
        echo json_encode(['success' => false, 'message' => 'Invalid product_id']);
        exit;
    }

    // Lấy cost_price hiện tại
    $sql_check = "SELECT cost_price, system_price, retail_price FROM pricing_policies WHERE product_id = $product_id LIMIT 1";
    $existing = db_fetch_array($sql_check, $conn);

    if (!empty($existing)) {
        $cost_price = floatval($existing[0]['cost_price']);
        $system_price_safe = floatval($system_price);
        $retail_price_safe = floatval($retail_price);

        $sql = "UPDATE pricing_policies SET system_price = $system_price_safe, retail_price = $retail_price_safe WHERE product_id = $product_id";
        mysqli_query($conn, $sql);
    } else {
        $cost_price = 0;
    }

    // Tính các step
    $step_1_2 = ($cost_price > 0) ? round($system_price / $cost_price, 2) : 0;
    $step_2_3 = ($system_price > 0) ? round($retail_price / $system_price, 2) : 0;
    $step_1_3 = ($cost_price > 0) ? round($retail_price / $cost_price, 2) : 0;

    echo json_encode([
        'success' => true,
        'data' => [
            'step_1_2' => number_format($step_1_2, 2),
            'step_2_3' => number_format($step_2_3, 2),
            'step_1_3' => number_format($step_1_3, 2),
        ]
    ]);
    exit;
}

function add_file_supplierAction()
{
    $id = (int) $_GET['id'];
    $product_id = (int) ($_GET['product_id'] ?? 0);

    global $conn;
    $sql = "SELECT supplier_name FROM suppliers WHERE id = $id";
    $result = db_fetch_array($sql, $conn);

    $data = $result[0];
    $data['id'] = $id;
    $data['product_id'] = $product_id;
    load_view('add-file-supplier', $data);
}

function add_file_materialAction()
{
    $id = (int) $_GET['id']; // material_id
    $product_id = (int) ($_GET['product_id'] ?? 0);

    global $conn;
    $sql = "SELECT material_name FROM material_information WHERE id = $id";
    $result = db_fetch_array($sql, $conn);

    $data = $result[0];
    $data['id'] = $id;
    $data['product_id'] = $product_id;
    load_view('add-file-material', $data);
}

function ready_add_file_materialAction()
{
    global $conn;

    $material_id = (int) $_GET['id'];
    $product_id = (int) ($_GET['product_id'] ?? 0);
    $file_label = trim($_POST['file_label'] ?? '');
    $folder_name = trim($_POST['folder_name'] ?? '');

    if (empty($folder_name)) {
        $sql = "SELECT material_name FROM material_information WHERE id = $material_id";
        $result = db_fetch_array($sql, $conn);
        $folder_name = $result[0]['material_name'] ?? 'unknown';
    }

    $response = upload_material_file($material_id, $folder_name, $file_label, $_FILES['file_upload']);

    if (!$response['success']) {
        header("Location: ?mod=product_profile&controllers=product_profile&action=add_file_material&id=$material_id&product_id=$product_id&error=" . urlencode($response['message']));
        exit;
    }

    header("Location: ?mod=product_profile&controllers=product_profile&action=product_detail&id=$product_id");
    exit;
}

function replace_composition_fileAction()
{
    global $conn;

    $file_id = (int) $_GET['file_id'];
    $product_id = (int) ($_GET['product_id'] ?? 0);

    $sql = "SELECT f.id, f.file_name, f.file_path, f.entity_type, f.entity_id
            FROM files f
            WHERE f.id = $file_id";
    $result = db_fetch_array($sql, $conn);

    if (empty($result)) {
        header("Location: ?mod=product_profile&controllers=product_profile&action=product_detail&id=$product_id");
        exit;
    }

    $data = $result[0];
    $data['product_id'] = $product_id;

    // Lấy tên entity (supplier hoặc material)
    if ($data['entity_type'] === 'supplier') {
        $sql_entity = "SELECT supplier_name AS entity_name FROM suppliers WHERE id = " . (int)$data['entity_id'];
    } else {
        $sql_entity = "SELECT material_name AS entity_name FROM material_information WHERE id = " . (int)$data['entity_id'];
    }
    $entity_result = db_fetch_array($sql_entity, $conn);
    $data['entity_name'] = $entity_result[0]['entity_name'] ?? '';

    load_view('replace-composition-file', $data);
}

function ready_replace_composition_fileAction()
{
    global $conn;

    $file_id = (int) $_GET['file_id'];
    $product_id = (int) ($_GET['product_id'] ?? 0);
    $file_label = trim($_POST['file_label'] ?? '');

    // 1. Lấy thông tin file cũ
    $sql = "SELECT id, file_name, file_path, entity_type, entity_id FROM files WHERE id = $file_id";
    $result = db_fetch_array($sql, $conn);

    if (empty($result)) {
        header("Location: ?mod=product_profile&controllers=product_profile&action=product_detail&id=$product_id&error=" . urlencode('Không tìm thấy file.'));
        exit;
    }

    $old_file = $result[0];
    $old_physical_path = 'public/' . $old_file['file_path'];
    $entity_type = $old_file['entity_type'];
    $entity_id = (int) $old_file['entity_id'];

    // 2. Xóa file vật lý cũ
    if (file_exists($old_physical_path)) {
        unlink($old_physical_path);
    }

    // 3. Xóa record cũ trong database
    $sql_delete = "DELETE FROM files WHERE id = $file_id";
    mysqli_query($conn, $sql_delete);

    // 4. Upload file mới theo đúng entity_type
    if ($entity_type === 'supplier') {
        $sql_name = "SELECT supplier_name FROM suppliers WHERE id = $entity_id";
        $name_result = db_fetch_array($sql_name, $conn);
        $entity_name = $name_result[0]['supplier_name'] ?? '';
        $response = upload_supplier_file($entity_id, $entity_name, $file_label, $_FILES['file_upload']);
    } else {
        $folder_name = trim($_POST['folder_name'] ?? '');
        if (empty($folder_name)) {
            $sql_name = "SELECT material_name FROM material_information WHERE id = $entity_id";
            $name_result = db_fetch_array($sql_name, $conn);
            $folder_name = $name_result[0]['material_name'] ?? 'unknown';
        }
        $response = upload_material_file($entity_id, $folder_name, $file_label, $_FILES['file_upload']);
    }

    if (!$response['success']) {
        header("Location: ?mod=product_profile&controllers=product_profile&action=product_detail&id=$product_id&error=" . urlencode($response['message']));
        exit;
    }

    header("Location: ?mod=product_profile&controllers=product_profile&action=product_detail&id=$product_id");
    exit;
}

function delete_composition_fileAction()
{
    global $conn;

    $file_id = (int) $_GET['file_id'];
    $product_id = (int) ($_GET['product_id'] ?? 0);

    // 1. Lấy thông tin file
    $sql = "SELECT file_path FROM files WHERE id = $file_id";
    $result = db_fetch_array($sql, $conn);

    if (!empty($result)) {
        // 2. Xóa file vật lý
        $file_path = 'public/' . $result[0]['file_path'];
        if (file_exists($file_path)) {
            unlink($file_path);
        }

        // 3. Xóa record trong database
        $sql_delete = "DELETE FROM files WHERE id = $file_id";
        mysqli_query($conn, $sql_delete);
    }

    // 4. Quay về trang detail
    header("Location: ?mod=product_profile&controllers=product_profile&action=product_detail&id=$product_id");
    exit;
}

function download_composition_fileAction()
{
    global $conn;

    $file_id = (int) $_GET['file_id'];

    $sql = "SELECT file_name, file_path FROM files WHERE id = $file_id";
    $result = db_fetch_array($sql, $conn);

    if (empty($result)) {
        header("Location: ?mod=product_profile&controllers=product_profile&action=product_detail&id=" . (int)($_GET['product_id'] ?? 0));
        exit;
    }

    $file_path = 'public/' . $result[0]['file_path'];
    $file_name = $result[0]['file_name'];

    $ext = pathinfo($file_path, PATHINFO_EXTENSION);
    $download_name = $file_name . '.' . $ext;

    if (!file_exists($file_path)) {
        header("Location: ?mod=product_profile&controllers=product_profile&action=product_detail&id=" . (int)($_GET['product_id'] ?? 0) . "&error=" . urlencode('File không tồn tại trên server.'));
        exit;
    }

    header('Content-Description: File Transfer');
    header('Content-Type: application/octet-stream');
    header('Content-Disposition: attachment; filename="' . $download_name . '"');
    header('Content-Length: ' . filesize($file_path));
    header('Cache-Control: must-revalidate');
    header('Pragma: public');
    ob_end_clean();
    readfile($file_path);
    exit;
}