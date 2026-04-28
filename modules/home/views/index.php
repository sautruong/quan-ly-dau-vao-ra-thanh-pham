<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Trang chủ</title>
    <link rel="stylesheet" href="public/css/reset.css">
    <link rel="stylesheet" href="public/css/all.css">
    <link rel="stylesheet" href="public/css/global.css">
    <link rel="stylesheet" href="public/css/style_home.css">
</head>

<body>
    <div id="wrapper">
        <div id="content-home">
            <div class="wp-brand-tab">
                <div class="branch">
                    <div class="logo">
                        <img src="public/images/logo/logo_vat_png.png" alt="CÔNG TY TNHH VUA AN TOÀN">
                    </div>
                    <div class="name_branch">[NMSX_VAT]</div>
                </div>

                <div class="body-content">
                    <ul class="main-tab">
                        <li class="tab-item">
                            <a href="?mod=production_staff&controllers=production_staff&action=plan_for_staff">
                                Nhân viên sản xuất
                            </a>
                        </li>
                        <li class="tab-item">
                            <a href="">
                                Bán hàng
                            </a>
                        </li>
                        <li class="tab-item">
                            <a href="">
                                Kế toán
                            </a>
                        </li>
                        <li class="tab-item">
                            <a href="">
                                Ban giám đốc
                            </a>
                        </li>
                    </ul>
                    <div class="expermore">
                        <a href="?mod=home&controller=index&action=expermore" class="expermore">
                            <p>Expermore</p>
                            <i class="fa-solid fa-arrow-right-long"></i>
                        </a>
                    </div>
                </div>
            </div>

            <div class="wp-logout">
                <a href="?mod=auth&controller=index&action=logout" class="logout">
                    <p>Đăng xuất</p>
                    <i class="fa-solid fa-arrow-right-from-bracket"></i>
                </a>
            </div>
        </div>
    </div>
</body>


</html>