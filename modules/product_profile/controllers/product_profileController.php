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
    pp_ensure_nutrition_fact_confirmed_column();
    pp_ensure_standard_document_set_column();
    //Xử lý lấy danh sách tổng thể  theo nhóm = vòng lặp| xử lý lấy mảng $product từ database
    $sql = "SELECT
    pc.category_name,
    p.id AS product_id,
    p.product_name,
    p.has_nutrition_fact,
    p.nutrition_fact_confirmed,
    p.standard_document_set,
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

    $grouped = [];

    while ($row = mysqli_fetch_assoc($result)) {
        $category = $row['category_name'];
        $productId = $row['product_id'];

        // 1. Tạo category nếu chưa có
        if (!isset($grouped[$category])) {
            $grouped[$category] = [];
        }

        // 2. Tạo product nếu chưa có
        if (!isset($grouped[$category][$productId])) {
            $grouped[$category][$productId] = [
                'product_name' => $row['product_name'],
                'has_nutrition_fact' => $row['has_nutrition_fact'],
                'nutrition_fact_confirmed' => (int) $row['nutrition_fact_confirmed'],
                'image_url' => 'public/images/' . $row['image_url'],
                'is_standard' => (int) $row['standard_document_set'],
                'list_file' => []
            ];
        }

        // 3. Thêm file nếu có
        if (!empty($row['file_id'])) {
            $grouped[$category][$productId]['list_file'][] = [
                'file_id' => $row['file_id'],
                'file_code' => $row['file_code'],
                'file_name' => $row['file_name'],
                'file_path' => 'public/uploads/' . $row['file_path']
            ];
        }
    }

    // Danh mục cho form "Thêm sản phẩm" (modal)
    $categories = db_fetch_array("SELECT id, category_name, category_code FROM product_categories ORDER BY id");

    load_view('list', [
        'groups'         => $grouped,
        'categories'     => $categories,
        'affected_id'    => (int) ($_GET['affected_id'] ?? 0),
        'affected_table' => $_GET['affected_table'] ?? '',
        'form_error'     => $_GET['error'] ?? '',
        'open_add'       => (isset($_GET['open']) && $_GET['open'] === 'add_product'),
    ]);
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
        // Quay về danh sách kèm thông báo lỗi (form giờ là modal trên trang list)
        header("Location: ?mod=product_profile&controllers=product_profile&action=list&error=" . urlencode($response['message']));
        exit;
    }

    header("Location: ?mod=product_profile&controllers=product_profile&action=list&affected_table=product_files");
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

    header("Location: ?mod=product_profile&controllers=product_profile&action=product_detail&id=$product_id&affected_table=files");
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

/** AJAX: tìm sản phẩm theo tên — dùng cho ô "Tìm & đổi sản phẩm" ở trang Chi tiết sản phẩm. */
function search_products_for_detailAction()
{
    header('Content-Type: application/json');
    $keyword = trim($_POST['keyword'] ?? '');
    if ($keyword === '') { echo json_encode(['data' => []]); exit; }
    echo json_encode(['data' => pp_search_products_for_detail($keyword)]);
    exit;
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
        header("Location: ?mod=product_profile&controllers=product_profile&action=list&open=add_product&error=" . urlencode('Tên sản phẩm không được bỏ trống.'));
        exit;
    }

    // Validate danh mục
    if ($category_id <= 0) {
        header("Location: ?mod=product_profile&controllers=product_profile&action=list&open=add_product&error=" . urlencode('Vui lòng chọn danh mục.'));
        exit;
    }

    // Validate hình ảnh
    $file = $_FILES['product_image'] ?? null;
    if (empty($file['name']) || $file['error'] !== UPLOAD_ERR_OK) {
        header("Location: ?mod=product_profile&controllers=product_profile&action=list&open=add_product&error=" . urlencode('Vui lòng chọn hình ảnh.'));
        exit;
    }

    $allowed_ext = ['jpg', 'jpeg', 'png', 'gif', 'webp', 'bmp'];
    $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
    if (!in_array($ext, $allowed_ext)) {
        header("Location: ?mod=product_profile&controllers=product_profile&action=list&open=add_product&error=" . urlencode('Chỉ chấp nhận file hình ảnh (JPG, PNG, JPEG, GIF, WEBP, BMP).'));
        exit;
    }

    // Lấy category_code
    $sql_cat = "SELECT category_code FROM product_categories WHERE id = $category_id";
    $cat_result = db_fetch_array($sql_cat);
    if (empty($cat_result)) {
        header("Location: ?mod=product_profile&controllers=product_profile&action=list&open=add_product&error=" . urlencode('Danh mục không hợp lệ.'));
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
        header("Location: ?mod=product_profile&controllers=product_profile&action=list&open=add_product&error=" . urlencode('Không thể lưu hình ảnh lên server.'));
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

    header("Location: ?mod=product_profile&controllers=product_profile&action=list&affected_id=$product_id&affected_table=products");
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

    header("Location: ?mod=product_profile&controllers=product_profile&action=product_detail&id=$product_id&affected_table=products&affected_id=$product_id");
    exit;
}

function delete_productAction()
{
    global $conn;
    header('Content-Type: application/json');

    $id = (int) ($_GET['id'] ?? $_POST['id'] ?? 0);
    if ($id <= 0) {
        echo json_encode(['success' => false, 'message' => 'Thiếu id sản phẩm.']);
        exit;
    }

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

    // 3. Lấy image_url để xóa hình ảnh sản phẩm (bỏ qua khi sản phẩm chưa có ảnh)
    $sql_product = "SELECT image_url FROM products WHERE id = $id";
    $product = db_fetch_array($sql_product);
    if (!empty($product) && !empty($product[0]['image_url'])) {
        $image_path = 'public/images/' . $product[0]['image_url'];
        if (is_file($image_path)) {
            unlink($image_path);
        }
    }

    // 4. Xóa sản phẩm (bảng cha) -> có thể bị chặn bởi ràng buộc khóa ngoại
    //    (vd sản phẩm còn tồn kho ở finished_goods_inventory) -> báo lỗi thân thiện thay vì crash.
    try {
        mysqli_query($conn, "DELETE FROM products WHERE id = $id");
    } catch (mysqli_sql_exception $e) {
        if ($e->getCode() == 1451) {
            echo json_encode(['success' => false, 'message' => 'Không thể xóa: sản phẩm đang có dữ liệu liên quan (vd tồn kho) ở nơi khác trong hệ thống.']);
            exit;
        }
        echo json_encode(['success' => false, 'message' => 'Lỗi xóa sản phẩm: ' . $e->getMessage()]);
        exit;
    }

    echo json_encode(['success' => true]);
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

    # Thông tin sản phẩm dùng cho modal "Xem thông tin sản phẩm" (luôn có, kể cả khi chưa có product_info_basic)
    global $conn;
    pp_ensure_standard_document_set_column();
    $detail = db_fetch_array(
        "SELECT p.product_name, p.image_url, p.category_id,
                pc.category_code,
                ib.unit, ib.inner_packaging_id, ib.outer_packaging_id,
                ib.inner_packaging_spec, ib.outer_packaging_spec,
                mi_in.material_name  AS inner_packaging_name,
                mi_out.material_name AS outer_packaging_name,
                p.standard_document_set AS is_standard
         FROM products p
         LEFT JOIN product_categories pc ON pc.id = p.category_id
         LEFT JOIN product_info_basic ib ON ib.product_id = p.id
         LEFT JOIN material_information mi_in  ON mi_in.id  = ib.inner_packaging_id
         LEFT JOIN material_information mi_out ON mi_out.id = ib.outer_packaging_id
         WHERE p.id = $id"
    );
    $data['detail'] = $detail[0] ?? [];

    # File hồ sơ của chính sản phẩm (product_files) — dải chip ngang dưới tiêu đề + kiểm tra "còn thiếu".
    $product_files = db_fetch_array("SELECT id, file_name, file_path FROM product_files WHERE product_id = $id ORDER BY id") ?: [];
    foreach ($product_files as &$pf) { $pf['web_path'] = 'public/uploads/' . $pf['file_path']; }
    unset($pf);
    $data['product_files'] = $product_files;

    # Hồ sơ/hóa đơn còn thiếu (modal "Hóa đơn còn thiếu") — tính từ composition đã lấy ở trên, không query lại.
    $data['missing_docs'] = pp_get_missing_docs($composition_of_product ?: [], count($product_files) > 0);

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

    header("Location: ?mod=product_profile&controllers=product_profile&action=product_detail&id=$product_id&affected_table=files");
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

    header("Location: ?mod=product_profile&controllers=product_profile&action=product_detail&id=$product_id&affected_table=files");
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

/* ============================================================
   HÓA ĐƠN MUA HÀNG (theo nguyên liệu) — mở qua modal cfile dùng chung
   (#composition-file-modal), submit thẳng vào action này.
   ============================================================ */
function ready_add_material_invoiceAction()
{
    global $conn;

    $material_id = (int) $_GET['id'];
    $product_id  = (int) ($_GET['product_id'] ?? 0);
    $file_label  = trim($_POST['file_label'] ?? '');
    $folder_name = trim($_POST['folder_name'] ?? '');

    if (empty($folder_name)) {
        $sql = "SELECT material_name FROM material_information WHERE id = $material_id";
        $result = db_fetch_array($sql, $conn);
        $folder_name = $result[0]['material_name'] ?? 'unknown';
    }

    $response = upload_material_invoice($material_id, $folder_name, $file_label, $_FILES['file_upload']);

    if (!$response['success']) {
        header("Location: ?mod=product_profile&controllers=product_profile&action=product_detail&id=$product_id&error=" . urlencode($response['message']));
        exit;
    }

    header("Location: ?mod=product_profile&controllers=product_profile&action=product_detail&id=$product_id&affected_table=material_invoices");
    exit;
}

function download_material_invoiceAction()
{
    global $conn;

    $invoice_id = (int) $_GET['invoice_id'];
    $product_id = (int) ($_GET['product_id'] ?? 0);

    $sql = "SELECT file_name, file_path FROM material_invoices WHERE id = $invoice_id";
    $result = db_fetch_array($sql, $conn);

    if (empty($result)) {
        header("Location: ?mod=product_profile&controllers=product_profile&action=product_detail&id=$product_id");
        exit;
    }

    $file_path = 'public/' . $result[0]['file_path'];
    $file_name = $result[0]['file_name'];
    $ext = pathinfo($file_path, PATHINFO_EXTENSION);
    $download_name = $file_name . '.' . $ext;

    if (!file_exists($file_path)) {
        header("Location: ?mod=product_profile&controllers=product_profile&action=product_detail&id=$product_id&error=" . urlencode('File không tồn tại trên server.'));
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

function delete_material_invoiceAction()
{
    $invoice_id = (int) $_GET['invoice_id'];
    $product_id = (int) ($_GET['product_id'] ?? 0);

    pp_delete_material_invoice($invoice_id);

    header("Location: ?mod=product_profile&controllers=product_profile&action=product_detail&id=$product_id");
    exit;
}

/** AJAX: xóa 1 hóa đơn KHÔNG redirect (dùng ở standard-dossier.php, ở lại trang giống ajax_delete_file). */
function ajax_delete_material_invoiceAction()
{
    header('Content-Type: application/json');
    $invoice_id = (int) ($_POST['invoice_id'] ?? 0);
    if ($invoice_id <= 0) {
        echo json_encode(['success' => false, 'message' => 'Thiếu invoice_id.']);
        exit;
    }
    pp_delete_material_invoice($invoice_id);
    echo json_encode(['success' => true]);
    exit;
}

/** AJAX: "Hóa đơn, chứng từ còn thiếu" cho TẤT CẢ sản phẩm trong Bộ hồ sơ chuẩn (standard-dossier.php). */
function ajax_missing_docs_standardAction()
{
    header('Content-Type: application/json');
    echo json_encode(['products' => pp_get_missing_docs_for_standard_set()]);
    exit;
}

/** AJAX: toàn bộ file hiện có của các sản phẩm chỉ định (product_ids[]) — dữ liệu để client nén ZIP. */
function ajax_all_files_standardAction()
{
    header('Content-Type: application/json');
    $ids = $_POST['product_ids'] ?? $_GET['product_ids'] ?? [];
    if (!is_array($ids)) $ids = [];
    echo json_encode(['products' => pp_get_all_files_for_products($ids)]);
    exit;
}

/** AJAX: sản phẩm (trong Bộ hồ sơ chuẩn) nào dùng 1 nguyên liệu cụ thể — click tên NVL ở modal "Hóa đơn, chứng từ đã có". */
function ajax_standard_products_using_materialAction()
{
    header('Content-Type: application/json');
    $material_info_id = (int) ($_GET['material_info_id'] ?? $_POST['material_info_id'] ?? 0);
    echo json_encode(['products' => pp_get_standard_products_using_material($material_info_id)]);
    exit;
}

/** AJAX: sửa ngày nhắc hẹn (cảnh báo hết hạn) của 1 hóa đơn. */
function ajax_update_invoice_remindAction()
{
    header('Content-Type: application/json');
    $ok = pp_update_invoice_remind($_POST['invoice_id'] ?? 0, $_POST['remind_at'] ?? '');
    echo json_encode(['success' => (bool) $ok]);
    exit;
}

/** AJAX: đổi tên 1 hóa đơn (giống ajax_rename_composition_file nhưng bảng material_invoices). */
function ajax_rename_material_invoiceAction()
{
    global $conn;
    header('Content-Type: application/json');

    $file_id   = (int) ($_POST['file_id'] ?? 0);
    $file_name = trim($_POST['file_name'] ?? '');
    if ($file_id <= 0) {
        echo json_encode(['success' => false, 'message' => 'Thiếu file_id.']);
        exit;
    }

    $name_safe = mysqli_real_escape_string($conn, $file_name);
    mysqli_query($conn, "UPDATE material_invoices SET file_name = '$name_safe' WHERE id = $file_id");

    echo json_encode(['success' => true, 'file_name' => $file_name]);
    exit;
}

/** AJAX: tần suất lặp lại file hồ sơ toàn hệ thống — nạp lúc mở modal "Tần suất" (không tính sẵn mỗi lần tải trang chi tiết). */
/**
 * AJAX: modal "Tần suất".
 * - Có product_id (trang Chi tiết sản phẩm) -> giới hạn theo NCC/NVL của riêng sản phẩm đó, trả 3 nhóm
 *   đầy đủ luôn (dữ liệu nhỏ, không cần phân trang).
 * - Không có product_id (trang Danh sách sản phẩm) -> toàn hệ thống, trả dạng PHẲNG có phân trang.
 *   all=1 -> bỏ qua phân trang, trả hết (dùng khi bấm "Tải xuống" để không giới hạn theo trang đang xem).
 */
function ajax_file_frequencyAction()
{
    header('Content-Type: application/json');
    $product_id = (int) ($_GET['product_id'] ?? $_POST['product_id'] ?? 0);

    if ($product_id > 0) {
        $composition = get_list_compositon($product_id) ?: [];
        $supplier_ids = array_values(array_unique(array_filter(array_column($composition, 'supplier_id'))));
        $material_ids = array_values(array_unique(array_filter(array_column($composition, 'material_info_id'))));
        echo json_encode(['mode' => 'grouped', 'groups' => pp_get_file_frequency($supplier_ids, $material_ids, [$product_id])]);
        exit;
    }

    $min_freq = max(1, (int) ($_GET['min_freq'] ?? $_POST['min_freq'] ?? 1));
    $group_key = trim((string) ($_GET['group'] ?? $_POST['group'] ?? ''));
    $flat = pp_get_file_frequency_flat($min_freq, $group_key !== '' ? $group_key : null);

    if (!empty($_GET['all']) || !empty($_POST['all'])) {
        echo json_encode(['mode' => 'flat', 'items' => $flat, 'total' => count($flat)]);
        exit;
    }

    $per_page = 20;
    $page = max(1, (int) ($_GET['page'] ?? $_POST['page'] ?? 1));
    $total = count($flat);
    $total_pages = max(1, (int) ceil($total / $per_page));
    $page = min($page, $total_pages);
    $slice = array_slice($flat, ($page - 1) * $per_page, $per_page);

    echo json_encode([
        'mode' => 'flat',
        'items' => $slice,
        'total' => $total,
        'page' => $page,
        'total_pages' => $total_pages,
    ]);
    exit;
}

/**
 * AJAX: modal "Xem thành phần" — chuỗi thành phần in nhãn của các sản phẩm được chọn.
 * POST product_ids[] (hoặc chuỗi id cách nhau dấu phẩy).
 * regenerate=1 -> bỏ qua bản sửa tay, dựng lại theo công thức sản xuất.
 */
function ajax_label_ingredientsAction()
{
    header('Content-Type: application/json');
    $ids = $_POST['product_ids'] ?? $_GET['product_ids'] ?? [];
    if (!is_array($ids)) {
        $ids = array_filter(array_map('trim', explode(',', (string) $ids)), 'strlen');
    }
    $force = !empty($_POST['regenerate']) || !empty($_GET['regenerate']);
    echo json_encode(['success' => true, 'items' => pp_get_label_ingredients($ids, $force)]);
    exit;
}

/** AJAX: lưu chuỗi thành phần user sửa tay (để trống = quay về dựng theo công thức). */
function ajax_save_label_ingredientsAction()
{
    header('Content-Type: application/json');
    $product_id = (int) ($_POST['product_id'] ?? 0);
    if ($product_id <= 0) {
        echo json_encode(['success' => false, 'message' => 'Thiếu product_id.']);
        exit;
    }
    $text = (string) ($_POST['text'] ?? '');
    pp_save_label_ingredients($product_id, $text);
    // Trả lại dòng đã chuẩn hóa để client hiển thị đúng ngay (chuỗi lưu có thể đã được
    // chỉnh hoa/thường, hoặc bị quy về NULL vì trùng bản dựng từ công thức).
    $items = pp_get_label_ingredients([$product_id]);
    echo json_encode(['success' => true, 'item' => $items[0] ?? null]);
    exit;
}

/**
 * AJAX: Xem dữ liệu thật trong database để kiểm tra logic.
 * - 10 dòng/trang, có phân trang.
 * - Dòng "vừa bị tác động" (affected_id) được đẩy lên đầu.
 */
function check_databaseAction()
{
    header('Content-Type: application/json');

    // Whitelist các bảng được phép xem (tránh truy vấn tùy tiện)
    $allowed = [
        'products', 'product_categories', 'product_info_basic', 'pricing_policies',
        'bill_of_materials', 'product_files', 'files', 'material_information', 'suppliers',
        'material_invoices', 'product_materials'
    ];

    $table = $_POST['table'] ?? 'products';
    if (!in_array($table, $allowed, true)) {
        echo json_encode(['success' => false, 'message' => 'Bảng không hợp lệ.']);
        exit;
    }

    $page = max(1, (int) ($_POST['page'] ?? 1));
    $per_page = 10;
    $offset = ($page - 1) * $per_page;
    $affected_id = (int) ($_POST['affected_id'] ?? 0);

    // Tổng số dòng -> tính số trang
    $count_rows = db_fetch_array("SELECT COUNT(*) AS c FROM `$table`");
    $total = (int) ($count_rows[0]['c'] ?? 0);
    $total_pages = $total > 0 ? (int) ceil($total / $per_page) : 1;

    // Sắp xếp: dòng vừa tác động lên đầu, phần còn lại mới nhất trước
    if ($affected_id > 0) {
        $order = "ORDER BY (id = $affected_id) DESC, id DESC";
    } else {
        $order = "ORDER BY id DESC";
    }

    $rows = db_fetch_array("SELECT * FROM `$table` $order LIMIT $per_page OFFSET $offset");
    $columns = !empty($rows) ? array_keys($rows[0]) : [];

    echo json_encode([
        'success'     => true,
        'table'       => $table,
        'columns'     => $columns,
        'rows'        => $rows,
        'page'        => $page,
        'per_page'    => $per_page,
        'total'       => $total,
        'total_pages' => $total_pages,
        'affected_id' => $affected_id,
    ]);
    exit;
}

/**
 * Thay thế một thành phần cấu tạo (đổi material_id trong bill_of_materials).
 * Nhận: old_material_id (POST), material_id mới (POST), product_id (GET).
 */
function ready_replace_composition_materialAction()
{
    global $conn;

    $product_id      = (int) ($_GET['product_id'] ?? 0);
    $old_material_id = (int) ($_POST['old_material_id'] ?? 0);
    $new_material_id = (int) ($_POST['material_id'] ?? 0);

    if ($product_id <= 0 || $old_material_id <= 0 || $new_material_id <= 0) {
        header("Location: ?mod=product_profile&controllers=product_profile&action=product_detail&id=$product_id&error=" . urlencode('Thiếu thông tin để thay thế thành phần.'));
        exit;
    }

    if ($old_material_id === $new_material_id) {
        header("Location: ?mod=product_profile&controllers=product_profile&action=product_detail&id=$product_id");
        exit;
    }

    // Nếu nguyên liệu mới đã có sẵn trong sản phẩm -> chỉ gỡ nguyên liệu cũ (tránh trùng)
    $exists = db_fetch_array("SELECT id FROM bill_of_materials WHERE product_id = $product_id AND material_id = $new_material_id");
    if (!empty($exists)) {
        mysqli_query($conn, "DELETE FROM bill_of_materials WHERE product_id = $product_id AND material_id = $old_material_id");
    } else {
        mysqli_query($conn, "UPDATE bill_of_materials SET material_id = $new_material_id WHERE product_id = $product_id AND material_id = $old_material_id");
    }

    header("Location: ?mod=product_profile&controllers=product_profile&action=product_detail&id=$product_id&affected_table=bill_of_materials");
    exit;
}

/**
 * Thêm một thành phần cấu tạo vào sản phẩm (insert bill_of_materials).
 * Nhận: material_id (POST), product_id (GET). Sắp xuống cuối (position = max+1).
 */
function ready_add_compositionAction()
{
    global $conn;

    $product_id = (int) ($_GET['product_id'] ?? 0);

    // Nhận danh sách nguyên liệu user vừa chọn (chọn nhiều)
    $material_ids = $_POST['material_ids'] ?? [];
    if (!is_array($material_ids)) {
        $material_ids = [];
    }
    // Tương thích ngược nếu chỉ gửi 1 material_id
    if (empty($material_ids) && !empty($_POST['material_id'])) {
        $material_ids = [$_POST['material_id']];
    }

    // Lọc id hợp lệ, loại trùng trong chính danh sách gửi lên
    $ids = [];
    foreach ($material_ids as $m) {
        $m = (int) $m;
        if ($m > 0) {
            $ids[$m] = true;
        }
    }

    if ($product_id <= 0 || empty($ids)) {
        header("Location: ?mod=product_profile&controllers=product_profile&action=product_detail&id=$product_id&error=" . urlencode('Vui lòng chọn ít nhất một nguyên liệu để thêm thành phần.'));
        exit;
    }

    // Vị trí bắt đầu = sau phần tử cuối hiện có
    $max = db_fetch_array("SELECT COALESCE(MAX(position), 0) AS m FROM bill_of_materials WHERE product_id = $product_id");
    $pos = (int) ($max[0]['m'] ?? 0);

    foreach (array_keys($ids) as $material_id) {
        // Tránh trùng thành phần đã có trong sản phẩm
        $exists = db_fetch_array("SELECT id FROM bill_of_materials WHERE product_id = $product_id AND material_id = $material_id");
        if (empty($exists)) {
            $pos++;
            mysqli_query($conn, "INSERT INTO bill_of_materials (product_id, material_id, position) VALUES ($product_id, $material_id, $pos)");
        }
    }

    header("Location: ?mod=product_profile&controllers=product_profile&action=product_detail&id=$product_id&affected_table=bill_of_materials");
    exit;
}

/**
 * (AJAX) Thêm nhiều thành phần cho sản phẩm — dùng ở 'Bộ hồ sơ chuẩn'.
 * Nhận product_id + material_ids[]. Trả JSON để JS xử lý (reload).
 */
function ajax_add_compositionAction()
{
    global $conn;
    header('Content-Type: application/json');

    $product_id = (int) ($_POST['product_id'] ?? 0);
    $material_ids = $_POST['material_ids'] ?? [];
    if (!is_array($material_ids)) $material_ids = [];

    $ids = [];
    foreach ($material_ids as $m) {
        $m = (int) $m;
        if ($m > 0) $ids[$m] = true;
    }

    if ($product_id <= 0 || empty($ids)) {
        echo json_encode(['success' => false, 'message' => 'Vui lòng chọn ít nhất một nguyên liệu.']);
        exit;
    }

    $max = db_fetch_array("SELECT COALESCE(MAX(position), 0) AS m FROM bill_of_materials WHERE product_id = $product_id");
    $pos = (int) ($max[0]['m'] ?? 0);
    $added = 0;
    foreach (array_keys($ids) as $material_id) {
        $exists = db_fetch_array("SELECT id FROM bill_of_materials WHERE product_id = $product_id AND material_id = $material_id");
        if (empty($exists)) {
            $pos++;
            mysqli_query($conn, "INSERT INTO bill_of_materials (product_id, material_id, position) VALUES ($product_id, $material_id, $pos)");
            $added++;
        }
    }

    echo json_encode(['success' => true, 'added' => $added]);
    exit;
}

/**
 * Lưu thứ tự kéo-thả các thành phần (cập nhật position trong bill_of_materials).
 * Nhận: product_id, order[] = danh sách bom_id theo thứ tự mới.
 */
function ajax_reorder_compositionAction()
{
    global $conn;
    header('Content-Type: application/json');

    $product_id = (int) ($_POST['product_id'] ?? 0);
    $order = $_POST['order'] ?? [];

    if ($product_id <= 0 || !is_array($order) || empty($order)) {
        echo json_encode(['success' => false, 'message' => 'Dữ liệu sắp xếp không hợp lệ.']);
        exit;
    }

    $pos = 0;
    foreach ($order as $bom_id) {
        $bom_id = (int) $bom_id;
        if ($bom_id > 0) {
            mysqli_query($conn, "UPDATE bill_of_materials SET position = $pos WHERE id = $bom_id AND product_id = $product_id");
            $pos++;
        }
    }

    echo json_encode(['success' => true, 'count' => $pos]);
    exit;
}

/**
 * Xóa một thành phần khỏi sản phẩm (xóa row trong bill_of_materials).
 * Nhận: bom_id, product_id (POST). Không xóa file của NCC/nguyên liệu
 * vì file thuộc về thực thể dùng chung, không thuộc riêng sản phẩm.
 */
function ajax_delete_compositionAction()
{
    global $conn;
    header('Content-Type: application/json');

    $bom_id     = (int) ($_POST['bom_id'] ?? 0);
    $product_id = (int) ($_POST['product_id'] ?? 0);

    if ($bom_id <= 0 || $product_id <= 0) {
        echo json_encode(['success' => false, 'message' => 'Thiếu thông tin để xóa thành phần.']);
        exit;
    }

    mysqli_query($conn, "DELETE FROM bill_of_materials WHERE id = $bom_id AND product_id = $product_id");

    echo json_encode(['success' => true, 'deleted' => mysqli_affected_rows($conn)]);
    exit;
}

/* ============================================================
   (Task 1.1) CẬP NHẬT THÔNG TIN SẢN PHẨM (modal Xem thông tin)
   ============================================================ */

// Cập nhật 1 trường trong product_info_basic (auto-save khi user sửa)
function ajax_update_product_basicAction()
{
    global $conn;
    header('Content-Type: application/json');

    $product_id = (int) ($_POST['product_id'] ?? 0);
    $field = $_POST['field'] ?? '';
    $value = $_POST['value'] ?? '';

    $allowed = ['unit', 'inner_packaging_spec', 'outer_packaging_spec', 'inner_packaging_id', 'outer_packaging_id'];
    if ($product_id <= 0 || !in_array($field, $allowed, true)) {
        echo json_encode(['success' => false, 'message' => 'Dữ liệu không hợp lệ.']);
        exit;
    }

    // Đảm bảo đã có bản ghi product_info_basic
    $exists = db_fetch_array("SELECT id FROM product_info_basic WHERE product_id = $product_id");
    if (empty($exists)) {
        mysqli_query($conn, "INSERT INTO product_info_basic (product_id) VALUES ($product_id)");
    }

    $resp = ['success' => true];

    if (substr($field, -3) === '_id') {
        // Bao bì trong/ngoài: lưu id nguyên liệu (FK material_information)
        $v = (int) $value;
        $set = $v > 0 ? (string) $v : 'NULL';
        mysqli_query($conn, "UPDATE product_info_basic SET $field = $set WHERE product_id = $product_id");
        $resp['name'] = '';
        if ($v > 0) {
            $r = db_fetch_array("SELECT material_name FROM material_information WHERE id = $v");
            $resp['name'] = $r[0]['material_name'] ?? '';
        }
    } else {
        $v = mysqli_real_escape_string($conn, trim($value));
        mysqli_query($conn, "UPDATE product_info_basic SET $field = '$v' WHERE product_id = $product_id");
    }

    echo json_encode($resp);
    exit;
}

// Cập nhật / thêm hình ảnh sản phẩm (copy file vật lý + update products.image_url)
function ajax_update_product_imageAction()
{
    global $conn;
    header('Content-Type: application/json');

    $product_id = (int) ($_POST['product_id'] ?? 0);
    if ($product_id <= 0) {
        echo json_encode(['success' => false, 'message' => 'Thiếu product_id.']);
        exit;
    }

    $file = $_FILES['file'] ?? null;
    if (empty($file) || empty($file['name']) || $file['error'] !== UPLOAD_ERR_OK) {
        echo json_encode(['success' => false, 'message' => 'Không nhận được hình ảnh.']);
        exit;
    }

    $allowed_ext = ['jpg', 'jpeg', 'png', 'gif', 'webp', 'bmp'];
    $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
    if (!in_array($ext, $allowed_ext)) {
        echo json_encode(['success' => false, 'message' => 'Chỉ chấp nhận file hình ảnh (JPG, PNG, JPEG, GIF, WEBP, BMP).']);
        exit;
    }

    $p = db_fetch_array("SELECT p.product_name, p.image_url, pc.category_code
                         FROM products p LEFT JOIN product_categories pc ON pc.id = p.category_id
                         WHERE p.id = $product_id");
    if (empty($p)) {
        echo json_encode(['success' => false, 'message' => 'Sản phẩm không tồn tại.']);
        exit;
    }
    $product_name  = $p[0]['product_name'];
    $category_code = $p[0]['category_code'] ?: 'misc';
    $old_image     = $p[0]['image_url'];

    $upload_dir = 'public/images/product_profile/' . $category_code . '/';
    if (!is_dir($upload_dir)) {
        mkdir($upload_dir, 0755, true);
    }
    $safe_filename = str_replace(' ', '-', mb_strtoupper($product_name, 'UTF-8')) . '.' . $ext;
    $dest = $upload_dir . $safe_filename;

    if (!move_uploaded_file($file['tmp_name'], $dest)) {
        echo json_encode(['success' => false, 'message' => 'Không thể lưu hình ảnh lên server.']);
        exit;
    }

    // Xóa ảnh cũ nếu khác file mới
    if (!empty($old_image)) {
        $old_path = 'public/images/' . $old_image;
        if ($old_path !== $dest && file_exists($old_path)) {
            @unlink($old_path);
        }
    }

    $image_url = 'product_profile/' . $category_code . '/' . $safe_filename;
    $image_url_safe = mysqli_real_escape_string($conn, $image_url);
    mysqli_query($conn, "UPDATE products SET image_url = '$image_url_safe' WHERE id = $product_id");

    echo json_encode(['success' => true, 'web_path' => 'public/images/' . $image_url]);
    exit;
}

/* ============================================================
   (Task 2) MANIFEST "BỘ HỒ SƠ" — trả cây file để JS ghi ra thư mục
   ============================================================ */
function ajax_dossier_manifestAction()
{
    global $config;
    header('Content-Type: application/json');

    $product_id = (int) ($_POST['product_id'] ?? 0);
    if ($product_id <= 0) {
        echo json_encode(['success' => false, 'message' => 'Thiếu product_id.']);
        exit;
    }

    $base = $config['base_url'] ?? '';

    // Tên file kèm đuôi thật (lấy từ file_path)
    $mk = function ($file_name, $file_path) {
        $ext = pathinfo($file_path, PATHINFO_EXTENSION);
        $nm = ($file_name !== '' && $file_name !== null) ? $file_name : pathinfo($file_path, PATHINFO_FILENAME);
        return $ext ? ($nm . '.' . $ext) : $nm;
    };

    $p = db_fetch_array("SELECT product_name FROM products WHERE id = $product_id");
    if (empty($p)) {
        echo json_encode(['success' => false, 'message' => 'Sản phẩm không tồn tại.']);
        exit;
    }
    $product_name = $p[0]['product_name'];

    // File của sản phẩm (product_files)
    $product_files = [];
    foreach (db_fetch_array("SELECT file_name, file_path FROM product_files WHERE product_id = $product_id") as $f) {
        $product_files[] = ['name' => $mk($f['file_name'], $f['file_path']), 'url' => $base . 'uploads/' . $f['file_path']];
    }

    // Thành phần (tái dùng model) -> file NCC + file nguyên liệu
    $ingredients = [];
    $comp = get_list_compositon($product_id);
    if (is_array($comp)) {
        foreach ($comp as $item) {
            $sup = [];
            foreach (($item['files']['supplier'] ?? []) as $f) {
                $sup[] = ['name' => $mk($f['file_name'], $f['file_path']), 'url' => $base . $f['file_path']];
            }
            $mat = [];
            foreach (($item['files']['material'] ?? []) as $f) {
                $mat[] = ['name' => $mk($f['file_name'], $f['file_path']), 'url' => $base . $f['file_path']];
            }
            $ingredients[] = [
                'name' => trim($item['material_name'] . ' - ' . $item['supplier_name']),
                'supplier_files' => $sup,
                'material_files' => $mat,
            ];
        }
    }

    echo json_encode([
        'success'       => true,
        'product_name'  => $product_name,
        'product_files' => $product_files,
        'ingredients'   => $ingredients,
    ]);
    exit;
}

/* ============================================================
   (Task 2) Đánh dấu "Bộ hồ sơ chuẩn" cho sản phẩm
   -> cập nhật product_files.standard_document_sets cho mọi file của SP
   ============================================================ */
function ajax_toggle_standard_setAction()
{
    header('Content-Type: application/json');

    $product_id = (int) ($_POST['product_id'] ?? 0);
    $value = (($_POST['value'] ?? '0') === '1');
    if ($product_id <= 0) {
        echo json_encode(['success' => false, 'message' => 'Thiếu product_id.']);
        exit;
    }

    pp_set_standard_document_set($product_id, $value);
    echo json_encode(['success' => true]);
    exit;
}

/** AJAX: bật/tắt cờ "đã xác nhận công bố dinh dưỡng" (workflow riêng, KHÁC has_nutrition_fact). */
function ajax_toggle_nutrition_fact_confirmedAction()
{
    header('Content-Type: application/json');
    $product_id = (int) ($_POST['product_id'] ?? 0);
    $value = (($_POST['value'] ?? '0') === '1');
    $ok = pp_set_nutrition_fact_confirmed($product_id, $value);
    echo json_encode(['success' => (bool) $ok]);
    exit;
}

/* ============================================================
   "THIẾT LẬP NCC" — bật/tắt xét hồ sơ nhà cung cấp (toàn hệ thống)
   ============================================================ */

/** AJAX: toàn bộ NCC + trạng thái "Xét hồ sơ NCC" (modal "Thiết lập NCC"). */
function ajax_list_suppliers_check_profileAction()
{
    header('Content-Type: application/json');
    echo json_encode(['success' => true, 'data' => pp_list_suppliers_check_profile()]);
    exit;
}

/** AJAX: bật/tắt "Xét hồ sơ NCC" cho 1 nhà cung cấp. */
function ajax_toggle_supplier_check_profileAction()
{
    header('Content-Type: application/json');
    $supplier_id = (int) ($_POST['supplier_id'] ?? 0);
    $value = (($_POST['value'] ?? '0') === '1');
    if ($supplier_id <= 0) {
        echo json_encode(['success' => false, 'message' => 'Thiếu supplier_id.']);
        exit;
    }
    $ok = pp_set_supplier_check_profile($supplier_id, $value);
    echo json_encode(['success' => (bool) $ok]);
    exit;
}

/* ============================================================
   (Task 1) HỒ SƠ DOANH NGHIỆP (entity_type = 'company_profile')
   ============================================================ */

// View: gom file theo group_name
function company_profileAction()
{
    $rows = db_fetch_array(
        "SELECT id, file_name, file_path, group_name, created_at
         FROM files
         WHERE entity_type = 'company_profile'
         ORDER BY group_name, created_at, id"
    );

    $groups = [];
    foreach ($rows as $r) {
        $g = (isset($r['group_name']) && $r['group_name'] !== '' && $r['group_name'] !== null) ? $r['group_name'] : 'Khác';
        $groups[$g][] = $r;
    }

    load_view('company-profile', ['groups' => $groups]);
}

// Thêm hồ sơ doanh nghiệp: 1 tên hồ sơ + nhiều file
function ajax_add_company_profileAction()
{
    global $conn;
    header('Content-Type: application/json');

    $group_name = trim($_POST['group_name'] ?? '');
    if ($group_name === '') {
        echo json_encode(['success' => false, 'message' => 'Vui lòng đặt tên hồ sơ.']);
        exit;
    }
    if (empty($_FILES['files']) || empty($_FILES['files']['name'][0])) {
        echo json_encode(['success' => false, 'message' => 'Vui lòng chọn ít nhất một file.']);
        exit;
    }

    $slug = to_slug($group_name);
    if ($slug === '') $slug = 'ho-so';
    $dir = 'public/uploads/company_profile/' . $slug . '/';
    if (!is_dir($dir)) {
        mkdir($dir, 0755, true);
    }

    $names = $_FILES['files']['name'];
    $count = 0;
    for ($i = 0; $i < count($names); $i++) {
        if ($_FILES['files']['error'][$i] !== UPLOAD_ERR_OK) continue;

        $orig = basename($_FILES['files']['name'][$i]);
        $base = to_slug(pathinfo($orig, PATHINFO_FILENAME));
        $ext  = strtolower(pathinfo($orig, PATHINFO_EXTENSION));
        if ($base === '') $base = 'file';
        $safe = $base . ($ext ? '.' . $ext : '');
        $safe = get_unique_filename($dir, $safe);
        $dest = $dir . $safe;

        if (!move_uploaded_file($_FILES['files']['tmp_name'][$i], $dest)) continue;

        $db_path = 'uploads/company_profile/' . $slug . '/' . $safe;
        $label   = pathinfo($orig, PATHINFO_FILENAME); // tên hiển thị = tên file (bỏ đuôi)

        $fn = mysqli_real_escape_string($conn, $label);
        $fp = mysqli_real_escape_string($conn, $db_path);
        $gn = mysqli_real_escape_string($conn, $group_name);
        mysqli_query($conn, "INSERT INTO files (file_name, file_path, entity_type, entity_id, group_name)
                             VALUES ('$fn', '$fp', 'company_profile', NULL, '$gn')");
        $count++;
    }

    echo json_encode(['success' => true, 'count' => $count]);
    exit;
}

// Xóa 1 file trong bảng files (dùng cho company profile & bộ hồ sơ chuẩn)
function ajax_delete_fileAction()
{
    global $conn;
    header('Content-Type: application/json');

    $file_id = (int) ($_POST['file_id'] ?? 0);
    if ($file_id <= 0) {
        echo json_encode(['success' => false, 'message' => 'Thiếu file_id.']);
        exit;
    }

    $r = db_fetch_array("SELECT file_path FROM files WHERE id = $file_id");
    if (!empty($r)) {
        $p = 'public/' . $r[0]['file_path'];
        if (file_exists($p)) {
            @unlink($p);
        }
        mysqli_query($conn, "DELETE FROM files WHERE id = $file_id");
    }
    echo json_encode(['success' => true]);
    exit;
}

/* ============================================================
   (Task 3) BỘ HỒ SƠ SẢN PHẨM CHUẨN
   Các sản phẩm có product_files.standard_document_sets = '1'
   ============================================================ */
function standard_dossierAction()
{
    pp_ensure_nutrition_fact_confirmed_column();
    pp_ensure_standard_document_set_column();
    $prods = db_fetch_array(
        "SELECT p.id, p.product_name, p.has_nutrition_fact, p.nutrition_fact_confirmed
         FROM products p
         WHERE p.standard_document_set = 1
         ORDER BY p.product_name"
    );

    $products = [];
    foreach ($prods as $p) {
        $pid = (int) $p['id'];
        $files = db_fetch_array("SELECT id, file_name, file_path FROM product_files WHERE product_id = $pid ORDER BY id");
        foreach ($files as &$f) {
            $f['web_path'] = 'public/uploads/' . $f['file_path'];
        }
        unset($f);

        $comp = get_list_compositon($pid);

        // "Hồ sơ đầy đủ" (icon tích xanh cạnh tên SP): TẤT CẢ cây thư mục đều có file — chính là
        // pp_get_missing_docs() trả về rỗng (đã tự bỏ qua NCC đang tắt "Xét hồ sơ" ở Thiết lập NCC).
        $is_complete = empty(pp_get_missing_docs($comp ?: [], count($files) > 0));

        $products[] = [
            'product_id'   => $pid,
            'product_name' => $p['product_name'],
            'has_nutrition_fact' => (int) $p['has_nutrition_fact'],
            'nutrition_fact_confirmed' => (int) $p['nutrition_fact_confirmed'],
            'files'        => $files,
            'composition'  => $comp ?: [],
            'is_complete'  => $is_complete,
        ];
    }

    load_view('standard-dossier', ['products' => $products]);
}

// Xóa 1 file sản phẩm trong product_files (AJAX - dùng cho bộ hồ sơ chuẩn)
function ajax_delete_product_fileAction()
{
    global $conn;
    header('Content-Type: application/json');

    $file_id = (int) ($_POST['file_id'] ?? 0);
    if ($file_id <= 0) {
        echo json_encode(['success' => false, 'message' => 'Thiếu file_id.']);
        exit;
    }

    $r = db_fetch_array("SELECT file_path FROM product_files WHERE id = $file_id");
    if (!empty($r)) {
        $p = 'public/uploads/' . $r[0]['file_path'];
        if (file_exists($p)) {
            @unlink($p);
        }
        mysqli_query($conn, "DELETE FROM product_files WHERE id = $file_id");
    }
    echo json_encode(['success' => true]);
    exit;
}

/* ============================================================
   KÉO-THẢ FILE (AJAX) — trả JSON để JS render ngay
   ============================================================ */

// (2) Kéo-thả file vào card sản phẩm -> product_files
function ajax_upload_product_fileAction()
{
    header('Content-Type: application/json');

    $product_id = (int) ($_POST['product_id'] ?? 0);
    if ($product_id <= 0) {
        echo json_encode(['success' => false, 'message' => 'Thiếu product_id.']);
        exit;
    }

    $file = $_FILES['file'] ?? null;
    if (empty($file) || empty($file['name'])) {
        echo json_encode(['success' => false, 'message' => 'Không nhận được file.']);
        exit;
    }

    // Lấy tên sản phẩm để đặt tên thư mục
    $res = db_fetch_array("SELECT product_name FROM products WHERE id = $product_id");
    $product_name = $res[0]['product_name'] ?? '';
    if ($product_name === '') {
        echo json_encode(['success' => false, 'message' => 'Sản phẩm không tồn tại.']);
        exit;
    }

    // Tên do người dùng nhập ở modal "Thêm file" (nếu có) -> mặc định lấy theo tên file (bỏ phần đuôi)
    $label = trim($_POST['file_label'] ?? '');
    if ($label === '') {
        $label = pathinfo($file['name'], PATHINFO_FILENAME);
    }

    $result = store_product_file($product_id, $product_name, $label, $file);
    if ($result['success']) {
        $result['product_id'] = $product_id;
    }
    echo json_encode($result);
    exit;
}

// (2) Sửa tên file sản phẩm -> cập nhật product_files.file_name
function ajax_rename_product_fileAction()
{
    global $conn;
    header('Content-Type: application/json');

    $file_id   = (int) ($_POST['file_id'] ?? 0);
    $file_name = trim($_POST['file_name'] ?? '');
    if ($file_id <= 0) {
        echo json_encode(['success' => false, 'message' => 'Thiếu file_id.']);
        exit;
    }

    $name_safe = mysqli_real_escape_string($conn, $file_name);
    mysqli_query($conn, "UPDATE product_files SET file_name = '$name_safe' WHERE id = $file_id");

    echo json_encode(['success' => true, 'file_name' => $file_name]);
    exit;
}

// (3) Kéo-thả file vào vùng NCC / nguyên liệu ở trang chi tiết -> files
function ajax_upload_composition_fileAction()
{
    global $config;
    header('Content-Type: application/json');

    $entity_type = (($_POST['entity_type'] ?? '') === 'supplier') ? 'supplier' : 'material';
    $entity_id   = (int) ($_POST['entity_id'] ?? 0);
    $product_id  = (int) ($_POST['product_id'] ?? 0);
    $folder_name = trim($_POST['folder_name'] ?? '');

    if ($entity_id <= 0) {
        echo json_encode(['success' => false, 'message' => 'Thiếu entity_id.']);
        exit;
    }

    $file = $_FILES['file'] ?? null;
    if (empty($file) || empty($file['name'])) {
        echo json_encode(['success' => false, 'message' => 'Không nhận được file.']);
        exit;
    }

    // Tên thư mục: ưu tiên từ JS, không có thì lấy theo tên thực thể trong DB
    if ($folder_name === '') {
        if ($entity_type === 'supplier') {
            $r = db_fetch_array("SELECT supplier_name AS n FROM suppliers WHERE id = $entity_id");
        } else {
            $r = db_fetch_array("SELECT material_name AS n FROM material_information WHERE id = $entity_id");
        }
        $folder_name = $r[0]['n'] ?? 'unknown';
    }

    // Tên ban đầu = tên file (bỏ đuôi) để user sửa
    $label = pathinfo($file['name'], PATHINFO_FILENAME);

    $result = store_composition_file($entity_type, $entity_id, $folder_name, $label, $file);
    if ($result['success']) {
        $base = $config['base_url'] ?? '';
        $result['web_path']   = $base . $result['file_path'];
        $result['product_id'] = $product_id;
    }
    echo json_encode($result);
    exit;
}

// (3) Sửa tên file thành phần -> cập nhật files.file_name
function ajax_rename_composition_fileAction()
{
    global $conn;
    header('Content-Type: application/json');

    $file_id   = (int) ($_POST['file_id'] ?? 0);
    $file_name = trim($_POST['file_name'] ?? '');
    if ($file_id <= 0) {
        echo json_encode(['success' => false, 'message' => 'Thiếu file_id.']);
        exit;
    }

    $name_safe = mysqli_real_escape_string($conn, $file_name);
    mysqli_query($conn, "UPDATE files SET file_name = '$name_safe' WHERE id = $file_id");

    echo json_encode(['success' => true, 'file_name' => $file_name]);
    exit;
}