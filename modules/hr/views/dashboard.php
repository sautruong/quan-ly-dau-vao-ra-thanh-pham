<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>index hr</title>
    <link rel="icon" href="public/images/logo/logo_vat_png.png" type="image/png">
    <link rel="stylesheet" href="public/css/reset.css">
    <link rel="stylesheet" href="public/css/all.css">
     <link rel="stylesheet" href="public/css/global.css">
    <link rel="stylesheet" href="public/css/style_hr_dashboard.css">
         <!--Định nghĩa thư viện js-->
    <script src="public/js/jquery-4.0.0.js" type="text/javascript" defer></script>
        <!--js của menu sidebarleft-->
    <script src="public/js/menu_sidebar_left.js" defer></script>
    <link rel="stylesheet" href="<?php echo asset_ver('public/css/shared/app_shell.css'); ?>">
</head>

<body>
    <div id="wrapper">
        <!-- Require Sidebar-left -->
         <?php require "layouts/sidebar-left.php"; ?>
        <div id="sidebar-right">
            <!-- Require Sidebar-right -->
             <?php require "layouts/top-sidebar-right.php"; ?>
            <div class="main-content">
                <h3>Vùng chứa nội dung trang tổng quan</h3>
            </div>
        </div>
    </div>
    <script src="<?php echo asset_ver('public/js/shared/app_shell.js'); ?>"></script>
</body>

</html>