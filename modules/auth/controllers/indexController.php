<?php
# Khai báo sử dụng session
session_start();
ob_start();
function construct()
{
    // load model nếu cần
    load_model('user');
}
# Gọi qua trang đăng nhập
function loginAction()
{
    load_view('login');
}
function checkLoginAction() // Từ bên form khi submit có cái link
{
    $username = $_POST['username'];
    $password = $_POST['password'];
    // echo $username;
    // echo $password;
    $result = check_validation_login($username, $password);
    // show_array($result);
    // ---------------------------------------------------------------------
    if ($result === true) {
        
        // đăng nhập thành công
        header('Location: ?mod=home&controllers=index');
    } else {

        // Chở qua loginView cái mảng lỗi
        $error = $result;
        $data['error'] = $error;
        //Chở qua loginView cái $username,$passowrd
        $data['username'] = $username;
        $data['password'] = $password;
        load_view('login', $data);
    }
    // ---------------------------------------------------------------------
}

/* ==========================================================================================
    Chuyển hướng registerView
========================================================================================== */
function registerAction()
{
    // Điều khiển qua model xử lý
    load_view('register');
}
function infomationAction()
{
    // Điều khiển qua model xử lý
    load_view('infomation');
}

/* ==========================================================================================
   CHUẨN HÓA DỮ LIỆU FORM ĐĂNG KÝ
   - Gửi qua Model xử lý
========================================================================================== */
function checkRegisterAction() // Từ bên form khi submit có cái link
{
    // Làm sạch dữ liệu
    $data = [
        'fullname' => $_POST['fullname'] ?? '',
        'day' => $_POST['day'] ?? '',
        'month' => $_POST['month'] ?? '',
        'year' => $_POST['year'] ?? '',
        'gender' => $_POST['gender'] ?? '',
        'phone' => $_POST['phone'] ?? '',
        'email' => $_POST['email'] ?? '',
        'username' => $_POST['username'] ?? '',
        'password' => $_POST['password'] ?? ''
    ];
    // Gửi cả mảng qua module xử lý
    $result = check_validation_register($data);
    # Kết quả result
    # + True: nhồi vào database (pending)
    # + $error: Chở qua view để show lên
    if ($result['stt'] === true) { // được
        // Lấy kq user_import
        $user_import = $result['user_import'];
        // -----------------------
        // show_array($user_import);
        // -----------------------
        $data = array(
            'fullname' => $user_import['fullname'],
            'dateofbirth' => $user_import['year'] . '-' . $user_import['month'] . '-' . $user_import['day'],
            'gender' => ($user_import['gender'] === 'M') ? "Nam" : "Nữ",
            'phone' => $user_import['phone'],
            'email' => $user_import['email'],
            'username' => $user_import['username'],
            'password' => md5($_POST['password']),
            'status' => 'pending'
        );
        // Kiểm tra kết nối với datase
        // dùng hàm db_insert tạo sẵn để nhồi vào db
        db_insert("tbl_users", $data);
        // Thông báo cho người dùng
        load_view('infomation');
    } else {
        // Chở qua loginView cái mảng lỗi
        $error = $result['error'];
        $user_import = $result['user_import'];
        $transport_data['error'] = $error;
        $transport_data['user_import'] = $user_import;
        //Chở qua loginView cái 
        load_view('register', $transport_data);
    }
}
/* ==========================================================================================
   LOGOUT
   - Gửi qua Model Để xử lý logout
========================================================================================== */
function logoutAction() {
    //qua module xử lý, trả kết quả là tên user_login
    $result = logout_process(); //user_login
    //Chuyển hướng, chở theo data mang tên username để nhồi vào form login
        $data['username'] = $result;
        load_view('login', $data);
   
}
