<?php
// show_array($error);
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Đăng nhập tài khoản hệ thống Safeking</title>
    <link rel="stylesheet" href="public/css/reset.css">
    <link rel="stylesheet" href="public/css/style_login.css">
    <!-- Responsive -->
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link rel="stylesheet" href="public/css/all.css">
</head>
<body>
    <div id="wrapper">
        <div id="container">
            <div id="wp-brand">
                <img src="public/images/logo/lolo_vat_png.png" alt="" width="40px" height="auto">
                <h4>NMSX_VAT</h4>
                <!-- <h5 style="font-weight: 300;">Enterprise Management System</h5> -->
            </div>
            <div id="wp-login">
                <div id="wp-title-login">
                    <h2>Đăng nhập</h2>
                    <p>Hệ thống quản lý nhà máy sản xuất <strong>Vua An Toàn</strong>. Nơi lưu trữ tài sản chiến
                        lượt, tối ưu vận hành và chuẩn hóa quy trình.</p>
                </div>
                <div id="wp-form-login">
                    <form action="?mod=auth&controllers=index&action=checkLogin" method="POST" id="form-login">
                        <input type="text" placeholder="Tên đăng nhập" name="username" value="<?php if (!empty($username)) echo $username; ?>">
                        <?php if (!empty($error['username'])) echo "<p class='error'> {$error['username']} </p>" ?>
                        
                        <div class="password-box">
                            <input type="password" placeholder="Mật khẩu" name="password" id="passowrd">
                            <span class="toggle-password" onclick="togglePassword()">
                                <i class="fa-solid fa-eye-slash" id="eyeIcon"></i>
                            </span>
                        </div>
                        <?php if (!empty($error['password'])) echo "<p class='error'> {$error['password']} </p>" ?>
                        <div id="wp-check-remember-me">
                            <input type="checkbox" id="remember-me" name="remember-me">
                            <label for="remember-me">Ghi nhớ tên đăng nhập mật khẩu</label>
                        </div>
                        <?php if (!empty($error['account'])) echo "<p class='error'> {$error['account']} </p>" ?>
                        <input type="submit" value="Đăng nhập" id="btn-login" name="btn_login">
                    </form>
                    <a href="?mod=auth&controllers=index&action=register" class="link_register">Đăng ký tài khoản</a>
                </div>
            </div>
        </div>
    </div>
</body>
<script src="public/js/app_register.js"></script>

</html>