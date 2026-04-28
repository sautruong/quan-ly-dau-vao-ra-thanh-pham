<!DOCTYPE html>
<html lang="vi">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Đăng ký thành công</title>

    <style>
        * {
            font-family: Arial, Helvetica, sans-serif;
        }

        body {
            margin: 0;
            background: #f4f6f9;
        }

        #wrapper {
            width: 100%;
            height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
        }

        #container {
            background: white;
            width: 500px;
            padding: 40px;
            border-radius: 6px;
            box-shadow: 0 0 10px rgba(0, 0, 0, 0.1);
            text-align: center;
        }

        h3 {
            font-size: 24px;
            color:green;
            margin-bottom: 15px;
        }

        p {
            font-size: 16px;
            margin: 6px 0;
        }

        a.btn {
            display: inline-block;
            margin-top: 20px;
            padding: 10px 18px;
            background: green;
            color: white;
            text-decoration: none;
            border-radius: 4px;
        }

        a.btn:hover {
            background:green;
        }
    </style>
</head>

<body>

    <div id="wrapper">
        <div id="container">
            <h3>Đăng ký thành công!</h3>
            <p>Thông tin tài khoản của bạn đã được hệ thống <strong>SAFEKING</strong> ghi nhận.</p>
            <p>Vui lòng chờ quản trị viên kích hoạt tài khoản trước khi đăng nhập.</p>

            <a href="?mod=auth&controllers=index&action=login" class="btn">
                Quay lại đăng nhập
            </a>
        </div>
    </div>

</body>

</html>