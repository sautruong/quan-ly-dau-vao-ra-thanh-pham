<?php
# Khai báo sử dụng session
session_start();
ob_start();

# Thư viện gửi email (PHPMailer) — dùng cho mã kích hoạt / quên mật khẩu
require_once __DIR__ . '/../../../libraries/sendmail.php';
# Thư viện thông báo (chuông) — đẩy báo cho admin khi có đăng ký mới
require_once __DIR__ . '/../../../libraries/notifications.php';
# Thư viện vòng đời tài khoản — "Rời hệ thống" (xóa dữ liệu cá nhân hóa)
require_once __DIR__ . '/../../../libraries/user_lifecycle.php';

function construct()
{
    // load model nếu cần
    load_model('user');
    // Dọn tài khoản rác: pending quá 24h chưa kích hoạt thì tự xóa.
    delete_expired_pending_users();
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
    # + True: phát OTP, chuyển sang bước xác thực email
    # + $error: Chở qua view để show lên
    if ($result['stt'] === true) { // hợp lệ
        $user_import = $result['user_import'];

        // Phát mã OTP 6 ký tự, HIỆU LỰC 60 GIÂY — gửi tới email người dùng nhập.
        // Tài khoản CHƯA được tạo; chỉ lưu tạm vào session, tạo sau khi nhập đúng OTP.
        $otp = generate_activation_code();
        $_SESSION['reg_otp']        = $otp;
        $_SESSION['reg_otp_expire'] = time() + 60;
        $_SESSION['reg_data']       = $user_import;

        $otp_sent = send_register_otp($user_import['email'], $user_import['fullname'], $otp);

        // Sang view nhập OTP (kèm email đã che + số giây còn lại + cờ gửi mail thành công).
        load_view('register_otp', [
            'email_masked' => mask_email($user_import['email']),
            'expires_in'   => 60,
            'otp_sent'     => $otp_sent,
        ]);
    } else {
        // Chở qua registerView mảng lỗi + dữ liệu đã nhập
        $transport_data['error']       = $result['error'];
        $transport_data['user_import'] = $result['user_import'];
        load_view('register', $transport_data);
    }
}

/** Gửi email chứa mã OTP xác thực đăng ký (hiệu lực 60 giây). */
function send_register_otp($email, $fullname, $otp)
{
    $content = "Xin chào <b>{$fullname}</b>,<br><br>"
        . "Bạn đang đăng ký tài khoản trên hệ thống SAFEKING.<br>"
        . "Mã xác thực (OTP) của bạn là: <h2 style='letter-spacing:6px;'>{$otp}</h2>"
        . "Mã này chỉ có hiệu lực trong vòng <b>60 giây</b>. "
        . "Vui lòng nhập mã để hoàn tất đăng ký. Nếu không phải bạn, hãy bỏ qua email này.";
    return send_mail($email, $fullname, 'Mã OTP xác thực đăng ký SAFEKING', $content);
}

/**
 * Khi có thành viên đăng ký mới: đẩy thông báo (chuông) cho mọi admin và
 * gửi email báo admin biết để kích hoạt tài khoản.
 */
function notify_new_member_to_admins($u)
{
    $fullname = (string) ($u['fullname'] ?? '');
    $username = (string) ($u['username'] ?? '');
    $email    = strtolower(trim((string) ($u['email'] ?? '')));

    // 1) Đẩy chuông cho tất cả admin.
    $title = 'Có thành viên đăng ký mới';
    $msg   = $fullname . ' (' . $username . ' – ' . $email . ') vừa đăng ký, đang chờ kích hoạt.';
    $link  = '?mod=admin_factory&controllers=admin&action=manage_user_list';
    if (function_exists('notify_admins')) {
        notify_admins($title, $msg, $link, 'new_member');
    }

    // 2) Gửi email cho từng admin.
    $admins = db_fetch_array("SELECT fullname, email FROM tbl_users
                              WHERE role = 'admin' AND email <> ''") ?: [];
    $content = "Hệ thống vừa ghi nhận một thành viên đăng ký mới:<br><br>"
        . "- Họ tên: <b>" . htmlspecialchars($fullname) . "</b><br>"
        . "- Tên đăng nhập: <b>" . htmlspecialchars($username) . "</b><br>"
        . "- Email: <b>" . htmlspecialchars($email) . "</b><br><br>"
        . "Tài khoản đang ở trạng thái <b>chờ kích hoạt</b>. "
        . "Vui lòng đăng nhập trang quản trị để xét duyệt/kích hoạt tài khoản này.";
    foreach ($admins as $ad) {
        send_mail($ad['email'], $ad['fullname'] ?: 'Quản trị viên', 'Có thành viên đăng ký mới', $content);
    }
}

/**
 * AJAX: xác thực OTP đăng ký. Khớp & còn hạn 60s → tạo tài khoản 'pending'.
 */
function verifyRegisterAction()
{
    header('Content-Type: application/json; charset=utf-8');

    $otp = strtoupper(trim((string) ($_POST['otp'] ?? '')));

    if (empty($_SESSION['reg_data']) || empty($_SESSION['reg_otp'])) {
        echo json_encode(['ok' => false, 'expired' => true, 'message' => 'Phiên đăng ký đã hết hạn, vui lòng đăng ký lại'], JSON_UNESCAPED_UNICODE);
        exit;
    }
    if (time() > (int) ($_SESSION['reg_otp_expire'] ?? 0)) {
        echo json_encode(['ok' => false, 'expired' => true, 'message' => 'Mã OTP đã hết hiệu lực (quá 60 giây). Vui lòng gửi lại mã.'], JSON_UNESCAPED_UNICODE);
        exit;
    }
    if (strlen($otp) !== 6 || $otp !== strtoupper((string) $_SESSION['reg_otp'])) {
        echo json_encode(['ok' => false, 'message' => 'Mã OTP không đúng'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    // Khớp → tạo tài khoản. Kiểm tra lần cuối phòng trùng (email/username vừa bị người khác dùng).
    $u         = $_SESSION['reg_data'];
    $esc_email = escape_string(strtolower(trim($u['email'])));
    $esc_user  = escape_string($u['username']);
    if (db_num_rows("SELECT id FROM tbl_users WHERE LOWER(email) = LOWER('{$esc_email}') OR username = '{$esc_user}'") > 0) {
        unset($_SESSION['reg_otp'], $_SESSION['reg_otp_expire'], $_SESSION['reg_data']);
        echo json_encode(['ok' => false, 'expired' => true, 'message' => 'Email hoặc tên đăng nhập vừa được người khác sử dụng. Vui lòng đăng ký lại.'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    // Mã kích hoạt 24h dùng cho chức năng "Quên mật khẩu" về sau.
    $activation_code = generate_activation_code();
    $code_expired_at = date('Y-m-d H:i:s', time() + 24 * 60 * 60);

    db_insert('tbl_users', [
        'fullname'        => $u['fullname'],
        'dateofbirth'     => $u['year'] . '-' . $u['month'] . '-' . $u['day'],
        'gender'          => $u['gender'],
        'phone'           => $u['phone'],
        'email'           => strtolower(trim($u['email'])),
        'username'        => $u['username'],
        'password'        => md5($u['password']),
        'status'          => 'pending',
        'created_at'      => date('Y-m-d H:i:s'),
        'activation_code' => $activation_code,
        'code_expired_at' => $code_expired_at,
    ]);

    // Báo admin có thành viên mới: đẩy chuông (bell) + gửi email để admin kích hoạt.
    notify_new_member_to_admins($u);

    unset($_SESSION['reg_otp'], $_SESSION['reg_otp_expire'], $_SESSION['reg_data']);

    echo json_encode([
        'ok'       => true,
        'redirect' => '?mod=auth&controllers=index&action=infomation',
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

/** AJAX: gửi lại mã OTP đăng ký (làm mới hiệu lực 60 giây). */
function resendRegisterOtpAction()
{
    header('Content-Type: application/json; charset=utf-8');

    if (empty($_SESSION['reg_data'])) {
        echo json_encode(['ok' => false, 'expired' => true, 'message' => 'Phiên đăng ký đã hết hạn, vui lòng đăng ký lại'], JSON_UNESCAPED_UNICODE);
        exit;
    }
    $u   = $_SESSION['reg_data'];
    $otp = generate_activation_code();
    $_SESSION['reg_otp']        = $otp;
    $_SESSION['reg_otp_expire'] = time() + 60;

    $sent = send_register_otp($u['email'], $u['fullname'], $otp);

    echo json_encode([
        'ok'           => (bool) $sent,
        'message'      => $sent
            ? ('Đã gửi lại mã OTP tới email ' . mask_email($u['email']) . '. Nếu không thấy, kiểm tra mục Spam/Quảng cáo.')
            : 'Gửi email thất bại. Vui lòng thử lại sau ít phút.',
        'expires_in'   => 60,
    ], JSON_UNESCAPED_UNICODE);
    exit;
}
/* ==========================================================================================
   ĐĂNG KÝ NHANH BẰNG EMAIL ("Đăng nhập bằng tài khoản gmail") — luồng 2 bước qua AJAX (JSON)
   B1: nhập email -> gửi OTP 60s -> nhập OTP xác thực.
   B2: đặt tên đăng nhập + mật khẩu -> tạo tài khoản 'pending' với hồ sơ mặc định.
   Hồ sơ mặc định: fullname = phần trước '@' của email; dateofbirth = <năm hiện tại>-01-01;
                   gender = 'M' (Nam); phone = '' (có thể trống).
========================================================================================== */

/** B1a: gửi OTP tới email người dùng nhập (hiệu lực 60 giây). */
function gmailSendOtpAction()
{
    header('Content-Type: application/json; charset=utf-8');

    $email = strtolower(trim((string) ($_POST['email'] ?? '')));
    if ($email === '') {
        echo json_encode(['ok' => false, 'message' => 'Vui lòng nhập email'], JSON_UNESCAPED_UNICODE);
        exit;
    }
    if (!filter_var($email, FILTER_VALIDATE_EMAIL) || strlen($email) > 100) {
        echo json_encode(['ok' => false, 'message' => 'Email không đúng định dạng'], JSON_UNESCAPED_UNICODE);
        exit;
    }
    // Email đã có tài khoản thì không cho đăng ký mới.
    $esc_email = escape_string($email);
    if (db_num_rows("SELECT id FROM tbl_users WHERE LOWER(email) = LOWER('{$esc_email}')") > 0) {
        echo json_encode(['ok' => false, 'message' => 'Email đã tồn tại trong hệ thống. Vui lòng đăng nhập.'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    $fullname = gmail_fullname_from_email($email);
    $otp = generate_activation_code();
    $_SESSION['gmail_otp']        = $otp;
    $_SESSION['gmail_otp_expire'] = time() + 60;
    $_SESSION['gmail_email']      = $email;
    unset($_SESSION['gmail_verified']);

    $sent = send_register_otp($email, $fullname, $otp);

    echo json_encode([
        'ok'         => (bool) $sent,
        'message'    => $sent
            ? ('Mã xác thực đã gửi tới email ' . mask_email($email) . '. Nếu không thấy, kiểm tra mục Spam/Quảng cáo.')
            : 'Gửi email thất bại. Vui lòng thử lại sau ít phút.',
        'expires_in' => 60,
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

/** B1b: gửi lại OTP (làm mới hiệu lực 60 giây). */
function gmailResendOtpAction()
{
    header('Content-Type: application/json; charset=utf-8');

    $email = strtolower(trim((string) ($_SESSION['gmail_email'] ?? '')));
    if ($email === '') {
        echo json_encode(['ok' => false, 'expired' => true, 'message' => 'Phiên đã hết hạn, vui lòng nhập lại email'], JSON_UNESCAPED_UNICODE);
        exit;
    }
    $otp = generate_activation_code();
    $_SESSION['gmail_otp']        = $otp;
    $_SESSION['gmail_otp_expire'] = time() + 60;

    $sent = send_register_otp($email, gmail_fullname_from_email($email), $otp);

    echo json_encode([
        'ok'         => (bool) $sent,
        'message'    => $sent
            ? ('Đã gửi lại mã tới ' . mask_email($email) . '.')
            : 'Gửi email thất bại. Vui lòng thử lại sau ít phút.',
        'expires_in' => 60,
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

/** B1c: xác thực OTP. Khớp & còn hạn 60s -> cho sang bước đặt tên/mật khẩu. */
function gmailVerifyOtpAction()
{
    header('Content-Type: application/json; charset=utf-8');

    $otp = strtoupper(trim((string) ($_POST['otp'] ?? '')));
    if (empty($_SESSION['gmail_email']) || empty($_SESSION['gmail_otp'])) {
        echo json_encode(['ok' => false, 'expired' => true, 'message' => 'Phiên đã hết hạn, vui lòng nhập lại email'], JSON_UNESCAPED_UNICODE);
        exit;
    }
    if (time() > (int) ($_SESSION['gmail_otp_expire'] ?? 0)) {
        echo json_encode(['ok' => false, 'expired' => true, 'message' => 'Mã đã hết hiệu lực (quá 60 giây). Vui lòng gửi lại mã.'], JSON_UNESCAPED_UNICODE);
        exit;
    }
    if (strlen($otp) !== 6 || $otp !== strtoupper((string) $_SESSION['gmail_otp'])) {
        echo json_encode(['ok' => false, 'message' => 'Mã xác thực không đúng'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    $_SESSION['gmail_verified'] = true;
    echo json_encode([
        'ok'       => true,
        'fullname' => gmail_fullname_from_email($_SESSION['gmail_email']),
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

/** B2: tạo tài khoản 'pending' với hồ sơ mặc định, sau khi đã xác thực email. */
function gmailRegisterAction()
{
    header('Content-Type: application/json; charset=utf-8');

    if (empty($_SESSION['gmail_verified']) || empty($_SESSION['gmail_email'])) {
        echo json_encode(['ok' => false, 'expired' => true, 'message' => 'Bạn chưa xác thực email. Vui lòng thực hiện lại.'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    $email            = strtolower(trim((string) $_SESSION['gmail_email']));
    $username         = trim((string) ($_POST['username'] ?? ''));
    $password         = (string) ($_POST['password'] ?? '');
    $password_confirm = (string) ($_POST['password_confirm'] ?? '');

    // --- Validate username (đồng bộ check_validation_register) ---
    if ($username === '') {
        echo json_encode(['ok' => false, 'message' => 'Tên đăng nhập không được để trống'], JSON_UNESCAPED_UNICODE);
        exit;
    }
    if (strlen($username) < 6 || strlen($username) > 32) {
        echo json_encode(['ok' => false, 'message' => 'Username phải từ 6 đến 32 ký tự'], JSON_UNESCAPED_UNICODE);
        exit;
    }
    if (!preg_match('/^[a-zA-Z0-9_]+$/', $username)) {
        echo json_encode(['ok' => false, 'message' => 'Username chỉ được chứa chữ, số và dấu _'], JSON_UNESCAPED_UNICODE);
        exit;
    }
    $esc_user = escape_string($username);
    if (db_num_rows("SELECT id FROM tbl_users WHERE username = '{$esc_user}'") > 0) {
        echo json_encode(['ok' => false, 'message' => 'Tài khoản đã tồn tại trong hệ thống'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    // --- Validate mật khẩu (đồng bộ check_validation_register) ---
    if (strlen($password) < 6) {
        echo json_encode(['ok' => false, 'message' => 'Mật khẩu phải từ 6 ký tự trở lên'], JSON_UNESCAPED_UNICODE);
        exit;
    }
    if (preg_match('/\s/', $password)) {
        echo json_encode(['ok' => false, 'message' => 'Mật khẩu không được chứa khoảng trắng'], JSON_UNESCAPED_UNICODE);
        exit;
    }
    if (preg_match('/[^\x00-\x7F]/', $password)) {
        echo json_encode(['ok' => false, 'message' => 'Mật khẩu không được chứa tiếng Việt có dấu'], JSON_UNESCAPED_UNICODE);
        exit;
    }
    if (!preg_match('/[A-Za-z]/', $password) || !preg_match('/[0-9]/', $password)) {
        echo json_encode(['ok' => false, 'message' => 'Mật khẩu phải có chữ và số'], JSON_UNESCAPED_UNICODE);
        exit;
    }
    if ($password !== $password_confirm) {
        echo json_encode(['ok' => false, 'message' => 'Mật khẩu nhập lại không khớp'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    // --- Phòng email vừa bị người khác dùng ---
    $esc_email = escape_string($email);
    if (db_num_rows("SELECT id FROM tbl_users WHERE LOWER(email) = LOWER('{$esc_email}')") > 0) {
        unset($_SESSION['gmail_otp'], $_SESSION['gmail_otp_expire'], $_SESSION['gmail_email'], $_SESSION['gmail_verified']);
        echo json_encode(['ok' => false, 'expired' => true, 'message' => 'Email vừa được người khác sử dụng. Vui lòng thực hiện lại.'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    // --- Tạo tài khoản 'pending' với hồ sơ mặc định ---
    $fullname        = gmail_fullname_from_email($email);
    $activation_code = generate_activation_code();
    $code_expired_at = date('Y-m-d H:i:s', time() + 24 * 60 * 60);

    db_insert('tbl_users', [
        'fullname'        => $fullname,
        'dateofbirth'     => date('Y') . '-01-01',
        'gender'          => 'M',
        'phone'           => '',
        'email'           => $email,
        'username'        => $username,
        'password'        => md5($password),
        'status'          => 'pending',
        'created_at'      => date('Y-m-d H:i:s'),
        'activation_code' => $activation_code,
        'code_expired_at' => $code_expired_at,
    ]);

    // Báo admin: đẩy chuông + email kích hoạt.
    notify_new_member_to_admins([
        'fullname' => $fullname,
        'username' => $username,
        'email'    => $email,
    ]);

    unset($_SESSION['gmail_otp'], $_SESSION['gmail_otp_expire'], $_SESSION['gmail_email'], $_SESSION['gmail_verified']);

    echo json_encode([
        'ok'       => true,
        'redirect' => '?mod=auth&controllers=index&action=infomation',
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

/** Họ tên mặc định = phần trước dấu '@' của email (bỏ mọi đuôi domain). */
function gmail_fullname_from_email($email)
{
    $email = (string) $email;
    $at    = strpos($email, '@');
    $name  = $at === false ? $email : substr($email, 0, $at);
    $name  = trim($name);
    return $name !== '' ? $name : 'Người dùng';
}

/* ==========================================================================================
   QUÊN MẬT KHẨU — luồng 3 bước qua AJAX (trả JSON)
========================================================================================== */

/** Che bớt email để hiển thị: nguyenvana@gmail.com → ng******a@gmail.com */
function mask_email($email)
{
    $email = (string) $email;
    $at = strpos($email, '@');
    if ($at === false) {
        return $email;
    }
    $name   = substr($email, 0, $at);
    $domain = substr($email, $at);
    $len    = strlen($name);
    if ($len <= 2) {
        $masked = substr($name, 0, 1) . str_repeat('*', max(1, $len - 1));
    } else {
        $masked = substr($name, 0, 2) . str_repeat('*', $len - 3) . substr($name, -1);
    }
    return $masked . $domain;
}

/** B1: Gửi mã kích hoạt tới email của user (tìm theo username hoặc email). */
function sendResetCodeAction()
{
    header('Content-Type: application/json; charset=utf-8');

    $identifier = trim((string) ($_POST['identifier'] ?? ''));
    if ($identifier === '') {
        echo json_encode(['ok' => false, 'message' => 'Vui lòng nhập tên đăng nhập hoặc email'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    $user = find_user_by_identifier($identifier);
    if (!$user) {
        echo json_encode(['ok' => false, 'message' => 'Tên đăng nhập hoặc email không tồn tại trong hệ thống'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    // Phát mã mới (hiệu lực 24h) và gửi qua email.
    $code = issue_activation_code($user['id']);
    $content = "Xin chào <b>{$user['fullname']}</b>,<br><br>"
        . "Bạn (hoặc ai đó) vừa yêu cầu đặt lại mật khẩu cho tài khoản <b>{$user['username']}</b> trên hệ thống SAFEKING.<br>"
        . "Mã kích hoạt của bạn là: <h2 style='letter-spacing:4px;'>{$code}</h2>"
        . "Mã này có hiệu lực trong vòng <b>24 giờ</b>. Nếu không phải bạn yêu cầu, vui lòng bỏ qua email này.";
    send_mail($user['email'], $user['fullname'], 'Mã đặt lại mật khẩu SAFEKING', $content);

    echo json_encode([
        'ok'      => true,
        'message' => 'Mã kích hoạt đã được gửi đến email ' . mask_email($user['email']),
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

/** B2: Đối chiếu 6 ký tự mã người dùng nhập. */
function verifyResetCodeAction()
{
    header('Content-Type: application/json; charset=utf-8');

    $identifier = $_POST['identifier'] ?? '';
    $code       = $_POST['activation_code'] ?? '';

    $result = verify_reset_code($identifier, $code);
    if ($result['stt'] === true) {
        echo json_encode(['ok' => true], JSON_UNESCAPED_UNICODE);
    } else {
        echo json_encode(['ok' => false, 'message' => $result['error']], JSON_UNESCAPED_UNICODE);
    }
    exit;
}

/** B3: Đổi mật khẩu mới (xác thực lại mã). */
function resetPasswordAction()
{
    header('Content-Type: application/json; charset=utf-8');

    $identifier       = $_POST['identifier'] ?? '';
    $code             = $_POST['activation_code'] ?? '';
    $password         = $_POST['password'] ?? '';
    $password_confirm = $_POST['password_confirm'] ?? '';

    $result = reset_password_process($identifier, $code, $password, $password_confirm);

    if ($result['stt'] === true) {
        echo json_encode(['ok' => true, 'message' => 'Đổi mật khẩu thành công. Mời bạn đăng nhập lại.'], JSON_UNESCAPED_UNICODE);
    } else {
        echo json_encode(['ok' => false, 'message' => $result['error']], JSON_UNESCAPED_UNICODE);
    }
    exit;
}

/* ==========================================================================================
   RỜI HỆ THỐNG (tự nguyện) — nhân viên thôi việc rời khỏi hệ thống.
   B1 gửi mã 6 ký tự qua email; B2 nhập mã -> xóa dữ liệu cá nhân hóa + đánh dấu 'left'.
========================================================================================== */

/** User đang đăng nhập (theo session). null nếu chưa đăng nhập. */
function auth_current_session_user()
{
    $login = $_SESSION['user_login'] ?? ($_COOKIE['user_login'] ?? '');
    $login = trim((string) $login);
    if ($login === '') return null;
    return find_user_by_identifier($login);
}

/** AJAX: gửi mã xác nhận rời hệ thống tới email của chính user đang đăng nhập. */
function leave_send_codeAction()
{
    header('Content-Type: application/json; charset=utf-8');
    $user = auth_current_session_user();
    if (!$user) {
        echo json_encode(['ok' => false, 'message' => 'Phiên đăng nhập không hợp lệ.'], JSON_UNESCAPED_UNICODE);
        exit;
    }
    $code = issue_activation_code($user['id']);
    $content = "Xin chào <b>{$user['fullname']}</b>,<br><br>"
        . "Bạn vừa yêu cầu <b>RỜI HỆ THỐNG</b> SAFEKING với tài khoản <b>{$user['username']}</b>.<br>"
        . "Mã xác nhận của bạn là: <h2 style='letter-spacing:4px;'>{$code}</h2>"
        . "Lưu ý: sau khi xác nhận, dữ liệu cá nhân hóa của bạn sẽ bị xóa và tài khoản sẽ tự xóa trong 24 giờ. "
        . "Nếu không phải bạn yêu cầu, vui lòng bỏ qua email này.";
    send_mail($user['email'], $user['fullname'], 'Mã xác nhận rời hệ thống SAFEKING', $content);
    echo json_encode([
        'ok'      => true,
        'message' => 'Mã xác nhận đã gửi tới email ' . mask_email($user['email']),
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

/** AJAX: xác nhận rời hệ thống bằng mã 6 ký tự -> xóa dữ liệu + đánh dấu 'left'. */
function leave_confirmAction()
{
    header('Content-Type: application/json; charset=utf-8');
    $user = auth_current_session_user();
    if (!$user) {
        echo json_encode(['ok' => false, 'message' => 'Phiên đăng nhập không hợp lệ.'], JSON_UNESCAPED_UNICODE);
        exit;
    }
    $code   = (string) ($_POST['activation_code'] ?? '');
    $result = verify_reset_code($user['username'], $code);
    if (($result['stt'] ?? false) !== true) {
        echo json_encode(['ok' => false, 'message' => $result['error'] ?? 'Mã không đúng'], JSON_UNESCAPED_UNICODE);
        exit;
    }
    $uid   = (int) $user['id'];
    $uname = trim((string) ($user['fullname'] ?? '')) ?: (string) $user['username'];
    lifecycle_mark_user_left($uid);
    if (function_exists('notify_admins')) {
        notify_admins('Có thành viên rời hệ thống',
            $uname . ' (' . $user['username'] . ') vừa tự rời hệ thống. Tài khoản sẽ tự xóa trong 24 giờ.',
            '?mod=admin_factory&controllers=admin&action=manage_user_list', 'member_left');
    }
    echo json_encode([
        'ok'       => true,
        'redirect' => '?mod=auth&controllers=index&action=logout',
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

/**
 * AJAX (polling): tài khoản đang đăng nhập còn hiệu lực không.
 * - status='left'     -> buộc rời hệ thống.
 * - status='inactive' -> bị ngưng hoạt động (đóng băng) -> cũng phải logout.
 * Trả 'reason' để client hiện đúng thông báo.
 */
function account_stateAction()
{
    header('Content-Type: application/json; charset=utf-8');
    $user = auth_current_session_user();
    if (!$user) {
        echo json_encode(['ok' => true, 'active' => false, 'reason' => 'gone'], JSON_UNESCAPED_UNICODE);
        exit;
    }
    $status = (string) ($user['status'] ?? '');
    $active = !in_array($status, ['left', 'inactive'], true);
    $reason = $active ? '' : ($status === 'inactive' ? 'inactive' : 'left');
    echo json_encode(['ok' => true, 'active' => $active, 'reason' => $reason], JSON_UNESCAPED_UNICODE);
    exit;
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
