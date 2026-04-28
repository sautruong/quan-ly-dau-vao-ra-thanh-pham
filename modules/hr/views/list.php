<?php
// show_array($data);
?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>index hr</title>
    <link rel="stylesheet" href="public/css/reset.css">
    <link rel="stylesheet" href="public/css/all.css">
    <link rel="stylesheet" href="public/css/global.css">
    <link rel="stylesheet" href="public/css/style_hr_dashboard.css">
    <!--js của menu sidebarleft-->
    <script src="public/js/menu_sidebar_left.js" defer></script>

    <!--Định nghĩa thư viện js-->
    <script src="public/js/jquery-4.0.0.js" type="text/javascript" defer></script>
    <script src="public/js/hr.js" defer></script>
</head>

<body>
    <div id="wrapper">
        <!-- Require Sidebar-left -->
        <?php require "layouts/sidebar-left.php"; ?>
        <div id="sidebar-right">
            <!-- Require Sidebar-right -->
            <?php require "layouts/top-sidebar-right.php"; ?>
            <div class="main-content">
                <div id="cb-select-box">
                    <form action="" method="POST">
                        <select name="select-branch" class="filter select-branch">
                            <option value="all">Tất cả nhân sự</option>
                            <option value="headquarters">Trụ sở chính</option>
                            <option value="HCM">Hồ Chí Minh</option>
                            <option value="HN">Hà Nội</option>
                            <option value="DN">Đà Nẵng</option>
                            <option value="CT">Cần Thơ</option>
                        </select>
                        <select name="select-info-hr" class="filter select-info-hr">
                            <option value="employees">Thông tin chính</option>
                            <option value="employee_basic_info">Thông tin căn bản</option>
                            <option value="employee_work_info">Thông tin làm việc</option>
                            <option value="employee_identity">Thông tin định danh</option>
                            <option value="employee_bank">Ngân hàng</option>
                            <option value="employee_education">Học vấn</option>
                            <option value="employee_insurance">Bảo hiểm xã hội</option>
                            <option value="employee_contract">Hợp đồng</option>
                            <option value="employee_organization">Tổ chức</option>
                            <option value="employee_management">Quản lý</option>
                            <option value="employee_documents">Lưu hồ sơ</option>
                        </select>
                    </form>
                </div>
                <!-- Bảng danh sách nhân viên -->

                <div id="container-table">
                    <div id="table-content">
                        <?php require "list_ajax.php"; ?> <!-- 👈 render lần đầu -->
                    </div>

                </div>

            </div>
        </div>
    </div> 
</body>

</html>