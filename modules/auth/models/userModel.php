<?php
/* ==========================================================================================
   CHUẨN HÓA DỮ LIỆU FORM ĐĂNG Nhập
========================================================================================== */

function check_validation_login($username, $password)
{
    // show_data($_POST);
    ##### CHUẨN HÓA DỮ LIỆU FORM: LẤY $username, $password
    // Tạo mảng lỗi $error, có lỗi đưa vào đầy
    $error = array();
    //-----------------------------------------------------------------------------
    ### username
    # Không bỏ trống
    if (empty($_POST['username'])) {
        $error['username'] = "Không được để trống tên đăng nhập";
    } else {
        // check lượng kí tự phải từ 6x32
        if (strlen($_POST['username']) < 6 or strlen($_POST['username']) > 32) {
            $error['username'] = 'Số lượng kí tự phải từ 6 đến 32';
            $username = $_POST['username'];
        } else {
            // check định dạng preg_match{"","",""}
            $partten = "/^[A-Za-z0-9_\.]{6,32}$/";
            if (!preg_match($partten, $_POST['username'], $matchs)) {
                $error['username'] = 'Tên đăng nhập sai định dạng';
                $username = $_POST['username'];
            } else {
                $username = $_POST['username'];
            }
        }
    }
    //-----------------------------------------------------------------------------
    # password
    // PD Tên mật khẩu không bỏ trống
    if (empty($_POST['password'])) {
        $error['password'] = 'Không được để trống mật khẩu';
    } else {
        if (strlen($_POST['password']) < 6 or strlen($_POST['password']) > 32) {
            $error['password'] = 'Độ dài mật khẩu từ 6 đến 32 kí tự';
            $password = $_POST['password'];
        } else {
            $partten = "/^[A-Za-z0-9!@#$%^&*()]{6,32}$/";
            if (!preg_match($partten, $_POST['password'], $matchs)) {
                $error['password'] = 'Mật khẩu không đúng định dạng';
                $password = $_POST['password'];
            } else {
                $password = $_POST['password'];
            }
        }
    }
    #status

    //-----------------------------------------------------------------------------
    ### Từ $error => kết luận
    //-----------------------------------------------------------------------------
    # Đưa list users ở db gán vào biến khác để làm việc, bằng hàm db_fetch_array
    // $list_users = db_fetch_array("SELECT * FROM `tbl_users`");
    // show_data($list_users);
    # Xét username, password có khớp với database không ($list_users)
    #KẾT LUẬN
    // -------------------------------------------------------------

    if (empty($error)) {
        //XỬ lý login
        // ---------------------------------------------------------------------------------
        // check username, pasword khop nhau trang thai phai active
        $sql = "SELECT * FROM tbl_users WHERE username = '{$username}' AND status = 'active'";
        $user = db_fetch_row($sql);
        // show_array($user);
        if (!$user) {
            $error['account'] = "Tài khoản không tồn tại";
            return $error;
        } elseif (md5($password) != $user['password']) {
            $error['account'] = "Mật khẩu không đúng";
            return $error;
        } else {
            // echo "chốt pasword ok";
            // Lưu trữ phiên đăng nhập
            $_SESSION['is_login'] = true;
            $_SESSION['user_login'] = $username;
            $_SESSION['fullname'] = $user['fullname'];
            //$_SESSION['fullname_login'] = check_login($username, $password, $list_users);
            // xem xét để lưu vào cookie 
            if (isset($_POST['remember-me'])) {
                $expire = time() + 2592000;
                setcookie('is_login', '1', [
                    'expires' => $expire,
                    'path' => '/',
                    'secure' => isset($_SERVER['HTTPS']),
                    'httponly' => true,
                    'samesite' => 'Lax'
                ]);
                setcookie('user_login', $username, [
                    'expires' => $expire,
                    'path' => '/',
                    'secure' => isset($_SERVER['HTTPS']),
                    'httponly' => true,
                    'samesite' => 'Lax'
                ]);
                setcookie('fullname', $user['fullname'], [
                    'expires' => $expire,
                    'path' => '/',
                    'secure' => isset($_SERVER['HTTPS']),
                    'httponly' => true,
                    'samesite' => 'Lax'
                ]);
            }
            return True;
        }

        // ---------------------------------------------------------------------------------
    } else {
        # Có lỗi về validation form
        // Chở thông tin lỗi từ model về controler, controler gửi qua view
        return $error; //về controller rồi
    }
}

/* ==========================================================================================
   CHUẨN HÓA DỮ LIỆU FORM ĐĂNG KÝ
   ========================================================================================== */

function check_validation_register($data)
{
    $error = array();
    ### fullname
    $fullname = trim($data['fullname']);
    if (empty($fullname)) {
        $error['fullname'] = "Không được để trống";
    } elseif (strlen($fullname) < 2 || strlen($fullname) > 100) {
        $error['fullname'] = "Họ tên phải từ 2 - 100 ký tự";
    } elseif (preg_match('/[0-9]/', $fullname)) {
        $error['fullname'] = "Họ tên không được chứa số";
    } elseif (preg_match('/[^a-zA-ZÀ-ỹ\s]/u', $fullname)) {
        $error['fullname'] = "Họ tên chứa ký tự không hợp lệ";
    }
    ### day
    $day = $_POST['day'] ?? '';

    if (empty($day)) {
        $error['day'] = "Không được để trống ngày";
    } elseif (!is_numeric($day)) {
        $error['day'] = "Ngày phải là số";
    } elseif ($day < 1 || $day > 31) {
        $error['day'] = "Ngày phải từ 1 đến 31";
    }
    ### month
    $month = $_POST['month'] ?? '';

    if (empty($month)) {
        $error['month'] = "Không được để trống tháng";
    } elseif (!is_numeric($month)) {
        $error['month'] = "Tháng phải là số";
    } elseif ($month < 1 || $month > 12) {
        $error['month'] = "Tháng phải từ 1 đến 12";
    }
    ### year
    $year = $_POST['year'] ?? '';

    if (empty($year)) {
        $error['year'] = "Không được để trống năm";
    } elseif (!is_numeric($year)) {
        $error['year'] = "Năm phải là số";
    } elseif (strlen($year) != 4) {
        $error['year'] = "Năm phải có 4 chữ số";
    }
    ### Giới tính
    $gender = $data['gender'] ?? '';

    if (empty($gender)) {
        $error['gender'] = "Vui lòng chọn giới tính";
    }
    ### phone — có thể bỏ trống; nếu có nhập thì phải đúng định dạng
    $phone = trim($data['phone']);

    if ($phone !== '' && !preg_match('/^(03|05|07|08|09)[0-9]{8}$/', $phone)) {
        $error['phone'] = "Số điện thoại không hợp lệ";
    }
    ### email
    $email = strtolower(trim($data['email']));

    if (empty($email)) {
        $error['email'] = "Email không được để trống";
    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $error['email'] = "Email không đúng định dạng";
    } elseif (strlen($email) > 100) {
        $error['email'] = "Email quá dài";
    } else {
        // Email đã đăng ký rồi thì không cho đăng ký nữa.
        $esc_email = escape_string($email);
        if (db_num_rows("SELECT id FROM tbl_users WHERE LOWER(email) = LOWER('{$esc_email}')") > 0) {
            $error['email'] = "Email đã tồn tại trong hệ thống";
        }
    }
    //-----------------------------------------------------------------------------
    ### username
    $username = trim($data['username']);

    if (empty($username)) {
        $error['username'] = "Tên đăng nhập không được để trống";
    } elseif (strlen($username) < 6 || strlen($username) > 32) {
        $error['username'] = "Username phải từ 6 đến 32 ký tự";
    } elseif (!preg_match('/^[a-zA-Z0-9_]+$/', $username)) {
        $error['username'] = "Username chỉ được chứa chữ, số và dấu _";
    } else {
        // kiểm tra username tồn tại trong DB
        $sql = "SELECT id FROM tbl_users WHERE username = '{$username}'";

        if (db_num_rows($sql) > 0) {
            $error['username'] = "Tài khoản đã tồn tại trong hệ thống";
        }
    }

    //-----------------------------------------------------------------------------
    # password
    // PD Tên mật khẩu không bỏ trống
    $password = $data['password'];

    if (empty($password)) {
        $error['password'] = "Mật khẩu không được để trống";
    } elseif (strlen($password) < 6) {
        $error['password'] = "Mật khẩu phải từ 6 ký tự trở lên";
    } elseif (preg_match('/\s/', $password)) {
        $error['password'] = "Mật khẩu không được chứa khoảng trắng";
    } elseif (preg_match('/[^\x00-\x7F]/', $password)) {
        $error['password'] = "Mật khẩu không được chứa tiếng Việt có dấu";
    } elseif (!preg_match('/[A-Za-z]/', $password) || !preg_match('/[0-9]/', $password)) {
        $error['password'] = "Mật khẩu phải có chữ và số";
    }

    //-----------------------------------------------------------------------------
    ### Từ $error => kết luận
    if (empty($error)) {
        $result = array(
            'stt' => True,
            'user_import' => $data
        );
        return $result;
    } else {
        # Có lỗi về validation form
        // Chở thông tin lỗi từ model về controler, controler gửi qua view
        # trả kết quả gồm: cái người dùng nhập ($data) và mảng lỗi
        $result = array(
            'stt' => false,
            'user_import' => $data,
            'error' => $error
        );
        return $result; //về controller rồi
    }

    //-----------------------------------------------------------------------------
}
/* ==========================================================================================
   MÃ KÍCH HOẠT (giống OTP) — 6 ký tự chữ & số, gửi qua email lúc đăng ký, hiệu lực 24h
========================================================================================== */
function generate_activation_code($length = 6)
{
    // Bỏ các ký tự dễ nhầm (0/O, 1/I) cho dễ đọc khi nhập tay.
    $chars = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';
    $max   = strlen($chars) - 1;
    $code  = '';
    for ($i = 0; $i < $length; $i++) {
        $code .= $chars[random_int(0, $max)];
    }
    return $code;
}

/* ==========================================================================================
   DỌN TÀI KHOẢN RÁC
   - Tài khoản 'pending' quá 24h (đăng ký nhưng chưa kích hoạt) sẽ bị xóa.
   - Tài khoản 'inactive' (admin "Ngưng hoạt động") quá 24h kể từ lúc bị ngưng cũng bị xóa.
   - Gọi tự động (lazy) mỗi khi truy cập module auth / có người đăng ký mới.
   - Trả về tổng số dòng bị xóa.
========================================================================================== */
function delete_expired_pending_users()
{
    // Mốc: quá 24h.
    $limit = escape_string(date('Y-m-d H:i:s', time() - 24 * 60 * 60));

    // 1) pending quá 24h kể từ lúc đăng ký (created_at).
    $deleted = db_delete(
        'tbl_users',
        "status = 'pending' AND created_at IS NOT NULL AND created_at < '{$limit}'"
    );

    // 2) inactive quá 24h kể từ lúc admin ngưng (status_changed_at).
    $deleted += db_delete(
        'tbl_users',
        "status = 'inactive' AND status_changed_at IS NOT NULL AND status_changed_at < '{$limit}'"
    );

    return $deleted;
}

/* ==========================================================================================
   QUÊN MẬT KHẨU — luồng 3 bước
   B1: tìm user theo TÊN ĐĂNG NHẬP hoặc EMAIL → phát mã, gửi email
   B2: nhập 6 ký tự mã → đối chiếu
   B3: nhập mật khẩu mới → đổi mật khẩu (khi mã đúng & còn hạn 24h)
========================================================================================== */

/** Tìm 1 user theo username HOẶC email (email so sánh không phân biệt hoa/thường). */
function find_user_by_identifier($identifier)
{
    $identifier = trim((string) $identifier);
    if ($identifier === '') {
        return null;
    }
    $esc = escape_string($identifier);
    $sql = "SELECT * FROM tbl_users
            WHERE username = '{$esc}' OR LOWER(email) = LOWER('{$esc}')
            LIMIT 1";
    $row = db_fetch_row($sql);
    return $row ?: null;
}

/** Sinh mã kích hoạt mới (hiệu lực 24h) cho 1 user và lưu vào DB. Trả về mã. */
function issue_activation_code($user_id)
{
    $code    = generate_activation_code();
    $expired = date('Y-m-d H:i:s', time() + 24 * 60 * 60);
    $uid     = (int) $user_id;
    db_update('tbl_users', [
        'activation_code' => $code,
        'code_expired_at' => $expired,
    ], "id = {$uid}");
    return $code;
}

/**
 * Đối chiếu mã kích hoạt của 1 user (theo identifier).
 * Trả về ['stt'=>true,'user'=>...] hoặc ['stt'=>false,'error'=>...].
 */
function verify_reset_code($identifier, $code)
{
    $code = strtoupper(trim((string) $code));
    $user = find_user_by_identifier($identifier);
    if (!$user) {
        return ['stt' => false, 'error' => 'Tài khoản không tồn tại'];
    }
    if (strlen($code) !== 6) {
        return ['stt' => false, 'error' => 'Vui lòng nhập đủ 6 ký tự mã'];
    }
    if (empty($user['activation_code']) || strtoupper($user['activation_code']) !== $code) {
        return ['stt' => false, 'error' => 'Mã kích hoạt không đúng'];
    }
    if (empty($user['code_expired_at']) || strtotime($user['code_expired_at']) < time()) {
        return ['stt' => false, 'error' => 'Mã kích hoạt đã hết hiệu lực'];
    }
    return ['stt' => true, 'user' => $user];
}

/**
 * Đổi mật khẩu cuối luồng: xác thực lại mã rồi cập nhật mật khẩu mới.
 * Trả về ['stt' => true] hoặc ['stt' => false, 'error' => '...'].
 */
function reset_password_process($identifier, $code, $password, $password_confirm)
{
    // --- Kiểm tra rỗng ---
    if ($password === '' || $password_confirm === '') {
        return ['stt' => false, 'error' => 'Vui lòng nhập đầy đủ mật khẩu'];
    }

    // --- Kiểm tra định dạng mật khẩu mới (đồng bộ với lúc đăng ký) ---
    if (strlen($password) < 6) {
        return ['stt' => false, 'error' => 'Mật khẩu phải từ 6 ký tự trở lên'];
    } elseif (preg_match('/\s/', $password)) {
        return ['stt' => false, 'error' => 'Mật khẩu không được chứa khoảng trắng'];
    } elseif (preg_match('/[^\x00-\x7F]/', $password)) {
        return ['stt' => false, 'error' => 'Mật khẩu không được chứa tiếng Việt có dấu'];
    } elseif (!preg_match('/[A-Za-z]/', $password) || !preg_match('/[0-9]/', $password)) {
        return ['stt' => false, 'error' => 'Mật khẩu phải có chữ và số'];
    }

    // --- Mật khẩu nhập lại phải khớp ---
    if ($password !== $password_confirm) {
        return ['stt' => false, 'error' => 'Mật khẩu nhập lại không khớp'];
    }

    // --- Xác thực lại mã kích hoạt (chống bỏ qua bước 2) ---
    $check = verify_reset_code($identifier, $code);
    if ($check['stt'] !== true) {
        return $check;
    }

    // --- Hợp lệ: đổi mật khẩu & vô hiệu mã (dùng một lần) ---
    $uid = (int) $check['user']['id'];
    db_update('tbl_users', [
        'password'        => md5($password),
        'activation_code' => null,
        'code_expired_at' => null,
    ], "id = {$uid}");

    return ['stt' => true];
}

/* ==========================================================================================
   XỬ LÝ PHẦN LOGOUT
   ========================================================================================== */
function logout_process()
{
    // show_array($_SESSION);
    // lấy lại user_login
    if (!empty($_SESSION['user_login'])) {
        $result = $_SESSION['user_login'];
    }
    // unset session, cokies
    // Xóa toàn bộ session
    $_SESSION = [];

    // Hủy session
    session_destroy();

    // Xóa cookie session
    $expire = time() - 2592000;
    setcookie('is_login', '', [
        'expires' => $expire,
        'path' => '/',
        'secure' => isset($_SERVER['HTTPS']),
        'httponly' => true,
        'samesite' => 'Lax'
    ]);
    setcookie('user_login', '', [
        'expires' => $expire,
        'path' => '/',
        'secure' => isset($_SERVER['HTTPS']),
        'httponly' => true,
        'samesite' => 'Lax'
    ]);
    if (!empty($result)) {
        return $result;
    }
}
