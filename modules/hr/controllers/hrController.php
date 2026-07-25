<?php

// Hàm construct thả mặc định ở mọi nơi
function construct()
{
    load_model('Employee');
}

function dashboardAction()
{
    load_view("dashboard");
}
function listAction()
{
    global $table_hr_config;
    // show_array($table_hr_config);
    //------------------------------------------------------
    $config =  $table_hr_config['employees'];
    //Lấy danh sách trước khi qua view
    //Lấy columns
    $columns = get_column('employees');

    // bỏ cột ẩn
    $columns = array_diff($columns, $config['hidden']['list']); //Lấy array1 trừ đi những phần tử array2

    // map label
    $list_column = $config['labels'];
    $list_column['operation'] = "Thao tác";
    //show_array($list_column);
    # Lấy nội dung trong bảng
    $list_hr = get_list_content_table('employees', 'all');
    // Nhồi url:Sửa, xóa, in hợp đồng
    //show_array($list_hr);

    foreach ($list_hr as &$hr_item) {
        $hr_item['url_edit'] = "?mod=hr&controllers=hr&action=edit&id={$hr_item['id']}";
        $hr_item['url_delete'] = "?mod=hr&controllers=hr&action=delete&id={$hr_item['id']}";
        $hr_item['url_create_contract'] = "?mod=hr&controllers=hr&action=create_contract&id={$hr_item['id']}";
    }

    unset($hr_item);

    // show_array($list_hr);

    //Nhòi thêm url_edit, url_delete vào mảng $list_column
    //  show_array($list_column);
    $data['list_column'] = $list_column;
    $data['list_hr'] = $list_hr;
    //show_array($data);
    load_view("list", $data);
    //------------------------------------------------------
}
function organizationAction()
{
    load_view("organization");
}

function create_contractAction()
{
    // Lấy id nhân viên chuyển qua hàm ở module xử lý
    $id = $_GET['id'];
    $info_hr_for_contract = get_data_contract_hr_by_id($id);
    // Nhận kết quả thông tin Đầy đủ của  nhân viên
    //Chở qua giao diện ghép dữ liệu vào
    $data = $info_hr_for_contract;
    load_view("contact_hr", $data);
}

/** AJAX: lưu 1 trường cấu hình hợp đồng (BÊN A / Giám đốc). */
function save_contract_settingAction()
{
    header('Content-Type: application/json');
    $key = trim((string) ($_POST['key'] ?? ''));
    $val = (string) ($_POST['value'] ?? '');
    $ok  = contract_settings_save($key, $val);
    echo json_encode([
        'success' => $ok,
        'message' => $ok ? '' : 'Trường không hợp lệ.',
    ], JSON_UNESCAPED_UNICODE);
    exit;
}
function process_list_hrAction()
{
    //Nhận được data và xử lý: qua modules xử lý lấy list
    //show_array($_POST);
    global $table_hr_config;
    // show_array($table_hr_config);
    //------------------------------------------------------
    $config =  $table_hr_config[$_POST['table']];
    //Lấy danh sách trước khi qua view
    //Lấy columns
    $columns = get_column($_POST['table']);

    // if ($_POST['table'] != 'employees' && in_array('employee_id', $columns)) {
    //     $columns = insert_after($columns, 'employee_id', 'full_name');
    // };
    // bỏ cột ẩn
    $columns = array_diff($columns, $config['hidden']['list']); //Lấy array1 trừ đi những phần tử array2

    // map label
    $list_column = $config['labels'];
    $list_column['operation'] = "Thao tác";

    # Lấy nội dung trong bảng
    $list_hr = get_list_content_table($_POST['table'], $_POST['branch']);
    //show_array($list_hr);
    // Nhồi url:Sửa, xóa, in hợp đồng
    foreach ($list_hr as &$hr_item) {
        $hr_item['url_edit'] = "?mod=hr&controllers=hr&action=edit&id={$hr_item['id']}";
        $hr_item['url_delete'] = "?mod=hr&controllers=hr&action=delete&id={$hr_item['id']}";
        $hr_item['url_create_contract'] = "?mod=hr&controllers=hr&action=create_contract&id={$hr_item['id']}";
    }
    unset($hr_item);
    // show_array($list_hr);
    //Nhòi thêm url_edit, url_delete vào mảng $list_column
    //  show_array($list_column);
    $data['list_column'] = $list_column;
    $data['list_hr'] = $list_hr;
    //show_array($data);array_diff
    load_view("list_ajax", $data);
}

function editAction()
{
    //Lấy dữ liệu từ database


    global $table_hr_config;
    global $conn;

    $id = $_GET['id'] ?? 0;

    if (!$id) {
        die("Không tìm thấy ID nhân sự");
    }

    $data = [];
    foreach ($table_hr_config as $table => $config) {
        $where = ($table === 'employees') ? "id = $id" : "employee_id = $id";
        $sql = "SELECT * FROM $table WHERE $where LIMIT 1";

        $result = mysqli_query($conn, $sql);

        if ($result && $row = $result->fetch_assoc()) {
            $data[$table] = $row;
        } else {
            $data[$table] = [];
        }
    }

    load_view("edit", $data);
}

function updateAction()
{
    // echo "đây là hàm undate";
    global $conn, $table_hr_config;

    if ($_SERVER['REQUEST_METHOD'] !== 'POST') return; //nếu không phải post thì thoát

    $id = $_GET['id'] ?? 0;
    if (!$id) {
        die("Không có ID hợp lệ");
    }

    foreach ($_POST as $table => $fields) {

        if (!isset($table_hr_config[$table])) continue;

        $set = [];

        foreach ($fields as $column => $value) {
            // ❌ bỏ qua employee_code
            if ($table === 'employees' && $column === 'employee_code') {
                continue;
            }

            $value = $conn->real_escape_string($value);
            $set[] = "$column = '$value'";
        }

        if (empty($set)) continue;

        $setString = implode(', ', $set);

        $where = ($table === 'employees')
            ? "id = $id"
            : "employee_id = $id";

        $sql = "UPDATE $table SET $setString WHERE $where";

        mysqli_query($conn, $sql);
    }

    // redirect về edit
    header("Location: ?mod=hr&controllers=hr&action=edit&id=$id");
    exit;
}
function addAction()
{
    global $table_hr_config;

    // KHÔNG có data → form rỗng
    $data = [];
    load_view("add");
}
function insertAction()
{
    global $conn, $table_hr_config;

    if ($_SERVER['REQUEST_METHOD'] !== 'POST') return;

    $employee_id = null;

    foreach ($_POST as $table => $fields) {

        if (!isset($table_hr_config[$table])) continue;

        $columns = [];
        $values = [];

        foreach ($fields as $column => $value) {

            // bỏ qua field readonly nếu có
            if ($column === 'id') continue;

            $columns[] = $column;
            $values[] = "'" . $conn->real_escape_string($value) . "'";
        }

        // bảng chính insert trước
        if ($table === 'employees') {

            $sql = "INSERT INTO employees (" . implode(',', $columns) . ")
                    VALUES (" . implode(',', $values) . ")";

            mysqli_query($conn, $sql);

            $employee_id = $conn->insert_id;
        }
    }

    // insert bảng con
    foreach ($_POST as $table => $fields) {

        if ($table === 'employees') continue;

        if (!isset($table_hr_config[$table])) continue;

        $columns = ['employee_id'];
        $values = [$employee_id];

        foreach ($fields as $column => $value) {

            $columns[] = $column;
            $values[] = "'" . $conn->real_escape_string($value) . "'";
        }

        $sql = "INSERT INTO $table (" . implode(',', $columns) . ")
                VALUES (" . implode(',', $values) . ")";

        mysqli_query($conn, $sql);
    }

    // redirect sang edit
    header("Location: ?mod=hr&controllers=hr&action=edit&id=$employee_id");
    exit;
}
function deleteAction()
{
    global $conn;
    if (!isset($_GET['id']) || !is_numeric($_GET['id'])) {
        die("ID không hợp lệ");
    }

    $id = (int) $_GET['id'];
    //Xử lý xóa nhân sự theo id




    // Bắt đầu transaction (rất quan trọng)
    $conn->begin_transaction();

    try {
        // 1. Xóa bảng con trước
        $tables = [
            'employee_basic_info',
            'employee_work_info',
            'employee_identity',
            'employee_bank',
            'employee_education',
            'employee_insurance',
            'employee_contract',
            'employee_organization',
            'employee_management',
            'employee_documents',           
        ];

        foreach ($tables as $table) {
            $stmt = $conn->prepare("DELETE FROM $table WHERE employee_id = ?");
            $stmt->bind_param("i", $id);
            $stmt->execute();
        }

        // 2. Xóa bảng cha
        $stmt2 = $conn->prepare("DELETE FROM employees WHERE id = ?");
        $stmt2->bind_param("i", $id);
        $stmt2->execute();

        // Commit nếu OK
        $conn->commit();

        // Redirect
        header("Location: ?mod=hr&controller=hr&action=list");
        exit();
    } catch (Exception $e) {
        // Nếu lỗi → rollback
        $conn->rollback();
        echo "Lỗi khi xóa: " . $e->getMessage();
    }
}
