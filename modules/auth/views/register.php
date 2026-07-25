<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Đăng ký tài khoản hệ thống safeking</title>
    <link rel="icon" href="public/images/logo/logo_vat_png.png" type="image/png">
    <link rel="stylesheet" href="public/css/reset.css">
    <link rel="stylesheet" href="public/css/all.css">
    <link rel="stylesheet" href="public/css/style_register.css">
    <style>
        /* Lớp phủ loading khi submit đăng ký (đang gửi mã OTP qua email). */
        #app-loading {
            position: fixed;
            inset: 0;
            z-index: 9999;
            display: none;
            align-items: center;
            justify-content: center;
            flex-direction: column;
            gap: 16px;
            background: rgba(15, 23, 42, 0.45);
            backdrop-filter: blur(2px);
        }
        #app-loading.is-on { display: flex; }
        #app-loading .spinner {
            width: 56px;
            height: 56px;
            border: 5px solid rgba(255, 255, 255, 0.35);
            border-top-color: #fff;
            border-radius: 50%;
            animation: app-spin 0.8s linear infinite;
        }
        #app-loading .loading-text {
            color: #fff;
            font-size: 15px;
            font-weight: 600;
            font-family: Arial, Helvetica, sans-serif;
        }
        @keyframes app-spin { to { transform: rotate(360deg); } }
    </style>

</head>

<body>
    <div id="wrapper">
        <div id="container">
            <div id="info-brand">
                <div id="logo">
                    <img src="public/images/logo/logo_vat_png.png" alt="" width="23px" height="auto">
                    <h4 style="font-weight: bold;">Safe King</h4>

                </div>
                <div id="title_form">
                    <h3>Đăng ký tài khoản Safeking</h3>
                    <p>Tạo tài khoản để cùng các anh em trong nội bộ <strong>Công ty TNHH Vua An Toàn </strong> xây dựng một tập thể gắn kết, thông minh và bền vững.</p>
                </div>
            </div>
            <form action="?mod=auth&controllers=index&action=checkRegister" method="POST" id="form-register">
                <label for="fullname">Họ và tên</label>
                <input type="text" name="fullname" placeholder="Họ và tên" value="<?php if (!empty($user_import['fullname'])) {
                                                                                        echo $user_import['fullname'];
                                                                                    } ?>">

                <?php if (!empty($error['fullname'])) {
                    echo "<p class='error'>{$error['fullname']}</p>";
                } ?>


                <label for="">Ngày sinh</label>
                <div id="day-of-birth">
                    <select name="day">
                        <option value="" disabled selected hidden>Day</option>
                        <?php
                        for ($i = 1; $i <= 31; $i++) {
                            echo "<option value='$i'>$i</option>";
                        }
                        ?>
                    </select>
                    <select name="month">
                        <option value="" disabled selected hidden>Month</option>
                        <?php
                        for ($i = 1; $i <= 12; $i++) {
                            echo "<option value='$i'>$i</option>";
                        }
                        ?>
                    </select>
                    <select name="year">
                        <option value="" disabled selected hidden>Year</option>

                        <?php
                        for ($i = date('Y'); $i >= 1950; $i--) {
                            echo "<option value='$i'>$i</option>";
                        }
                        ?>
                    </select>
                    
                </div>
                <?php if (!empty($error['day'])) {
                    echo "<p class='error'>{$error['day']}</p>";
                } ?>
                <?php if (!empty($error['month'])) {
                    echo "<p class='error'>{$error['month']}</p>";
                } ?>
                <?php if (!empty($error['year'])) {
                    echo "<p class='error'>{$error['year']}</p>";
                } ?>

                <label for="gender">Giới tính</label>
                <select name="gender" id="gender">
                    <option value="" disabled selected hidden>Chọn giới tính</option>
                    <option value="M" <?php if (!empty($user_import['gender']) && $user_import['gender'] == 'M') echo 'selected'; ?>>Nam</option>
                    <option value="F" <?php if (!empty($user_import['gender']) && $user_import['gender'] == 'F') echo 'selected'; ?>>Nữ</option>
                </select>
                <?php if (!empty($error['gender'])) {
                    echo "<p class='error'>{$error['gender']}</p>";
                } ?>

                <label for="phone">Số điện thoại</label>
                <input type="text" id="phone" placeholder="Số điện thoại" name="phone" value="<?php if (!empty($user_import['phone'])) {
                                                                                                    echo $user_import['phone'];
                                                                                                } ?>">
                <?php if (!empty($error['phone'])) {
                    echo "<p class='error'>{$error['phone']}</p>";
                } ?>


                <label for="email">Email</label>
                <input type="text" id="email" placeholder="Email" name="email" value="<?php if (!empty($user_import['email'])) {
                                                                                            echo $user_import['email'];
                                                                                        } ?>">
                <?php if (!empty($error['email'])) {
                    echo "<p class='error'>{$error['email']}</p>";
                } ?>


                <label for="username">Tên đăng nhập</label>
                <input type="text" id="username" placeholder="Tên đăng nhập" name="username" value="<?php if (!empty($user_import['username'])) {
                                                                                                        echo $user_import['username'];
                                                                                                    } ?>">
                <?php if (!empty($error['username'])) {
                    echo "<p class='error'>{$error['username']}</p>";
                } ?>


                <label for="password">Mật khẩu</label>
                <div class="password-box">
                    <input type="password" id="password" placeholder="Mật khẩu" name="password" value="<?php if (!empty($user_import['password'])) {
                                                                                                            echo $user_import['password'];
                                                                                                        } ?>">
                    <span class="toggle-password" onclick="togglePassword()">
                        <i class="fa-solid fa-eye-slash" id="eyeIcon"></i>
                    </span>
                </div>
                
                <?php if (!empty($error['password'])) {
                    echo "<p class='error'>{$error['password']}</p>";
                } ?>

                <input type="submit" name="btn-register" value="Đăng ký" id="btn-register">
                <a href="?mod=auth&controllers=index&action=login" class="link_login">Tôi đã có một tài khoản</a>
            </form>
        </div>
    </div>

    <!-- Loading xoay tròn giữa màn hình lúc submit (đang xử lý + gửi mã OTP). -->
    <div id="app-loading" aria-hidden="true">
        <div class="spinner"></div>
        <div class="loading-text">Đang gửi mã xác thực tới email…</div>
    </div>
</body>
<script src="public/js/app_register.js"></script>

</html>