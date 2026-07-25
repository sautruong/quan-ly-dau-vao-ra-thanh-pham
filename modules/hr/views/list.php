<?php
// show_array($data);
?>
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
    <!--js của menu sidebarleft-->
    <script src="public/js/menu_sidebar_left.js" defer></script>

    <!--Định nghĩa thư viện js-->
    <script src="public/js/jquery-4.0.0.js" type="text/javascript" defer></script>
    <script src="public/js/hr.js" defer></script>
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
                <div class="js-to-header">
                    <button type="button" id="btn-open-add-hr" class="btn-add-hr">
                        <i class="fa-solid fa-user-plus"></i> Thêm nhân sự
                    </button>
                </div>
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

    <!-- Modal Hợp đồng lao động (nhúng trang create_contract bằng iframe) -->
    <div class="hr-modal" id="hr-contract-modal" aria-hidden="true">
        <div class="hr-modal-overlay" data-contract-close></div>
        <div class="hr-modal-box hr-contract-box">
            <div class="hr-modal-head">
                <h3>Hợp đồng lao động</h3>
                <button type="button" class="hr-modal-close" data-contract-close aria-label="Đóng">&times;</button>
            </div>
            <div class="hr-modal-body" style="padding:0;">
                <iframe id="hr-contract-frame" title="Hợp đồng lao động"
                    style="width:100%; height:78vh; border:0; display:block; background:#e0e0e0;"></iframe>
            </div>
        </div>
    </div>

    <!-- Modal Thêm nhân sự (nhúng form của trang add) -->
    <div class="hr-modal" id="hr-add-modal" aria-hidden="true">
        <div class="hr-modal-overlay" data-hr-close></div>
        <div class="hr-modal-box">
            <div class="hr-modal-head">
                <h3>Thêm nhân sự</h3>
                <button type="button" class="hr-modal-close" data-hr-close aria-label="Đóng">&times;</button>
            </div>
            <div class="hr-modal-body">
                <?php require "_add_form.php"; ?>
            </div>
        </div>
    </div>

    <style>
        .btn-add-hr { display:inline-flex; align-items:center; gap:7px; background:#096926; color:#fff;
            border:0; padding:8px 16px; border-radius:8px; cursor:pointer; font-size:14px; font-weight:600; }
        .btn-add-hr:hover { background:#0c7d2e; }
        .hr-modal { position:fixed; inset:0; z-index:2000; display:none; }
        .hr-modal.is-open { display:block; }
        .hr-modal-overlay { position:absolute; inset:0; background:rgba(17,24,39,.5); }
        .hr-modal-box { position:relative; margin:5vh auto 0; width:900px; max-width:94vw; max-height:90vh;
            overflow:auto; background:#fff; border-radius:14px; box-shadow:0 10px 30px rgba(0,0,0,.25); }
        .hr-modal-head { position:sticky; top:0; background:#fff; display:flex; align-items:center;
            justify-content:space-between; padding:14px 20px; border-bottom:1px solid #eee; z-index:1; }
        .hr-modal-head h3 { margin:0; font-size:17px; }
        .hr-modal-close { border:0; background:transparent; font-size:24px; line-height:1; cursor:pointer; color:#6b7280; }
        .hr-modal-close:hover { color:#111827; }
        .hr-modal-body { padding:18px 20px 22px; }
        /* Style form "Thêm nhân sự" trong modal (style_edit_hr.css không nạp ở list) */
        .hr-modal-body .group-table { margin-bottom:18px; border:1px solid #eef0f2; border-radius:10px; padding:14px 16px; background:#fff; }
        .hr-modal-body .group-title { margin:0 0 12px; font-size:14px; font-weight:700; color:#096926;
            padding-bottom:8px; border-bottom:1px solid #eef0f2; }
        .hr-modal-body .form-grid { display:grid; grid-template-columns:repeat(auto-fill, minmax(220px,1fr)); gap:12px 16px; }
        .hr-modal-body .form-group { display:flex; flex-direction:column; gap:5px; min-width:0; }
        .hr-modal-body .form-group label { font-size:12.5px; font-weight:600; color:#374151; }
        .hr-modal-body input, .hr-modal-body select, .hr-modal-body textarea {
            width:100%; box-sizing:border-box; padding:8px 10px; border:1.5px solid #e5e7eb;
            border-radius:8px; font-size:13.5px; outline:none; font-family:inherit; background:#fff; color:#111827; }
        .hr-modal-body input:focus, .hr-modal-body select:focus, .hr-modal-body textarea:focus {
            border-color:#16a34a; box-shadow:0 0 0 3px rgba(22,163,74,.15); }
        .hr-modal-body #btn-submit { margin-top:6px; background:#096926; color:#fff; border:0;
            padding:11px 24px; border-radius:8px; font-size:14px; font-weight:600; cursor:pointer; }
        .hr-modal-body #btn-submit:hover { background:#0c7d2e; }
        .hr-contract-box { width:1000px; max-width:96vw; }
        #container-table .btn-open-contract { cursor:pointer; }
    </style>
    <script>
        (function () {
            var modal = document.getElementById('hr-add-modal');
            var openBtn = document.getElementById('btn-open-add-hr');
            if (openBtn) openBtn.addEventListener('click', function () { modal.classList.add('is-open'); });
            modal.querySelectorAll('[data-hr-close]').forEach(function (el) {
                el.addEventListener('click', function () { modal.classList.remove('is-open'); });
            });
        })();

        // Mở hợp đồng lao động dạng modal (iframe) thay vì tab mới.
        (function () {
            var cmodal = document.getElementById('hr-contract-modal');
            var cframe = document.getElementById('hr-contract-frame');
            if (!cmodal || !cframe) return;
            // Bắt sự kiện click trên bảng (table render lại bằng AJAX nên dùng delegation).
            document.addEventListener('click', function (e) {
                var link = e.target.closest('.btn-open-contract');
                if (!link) return;
                e.preventDefault();
                var url = link.getAttribute('data-contract-url') || link.getAttribute('href');
                if (!url) return;
                cframe.src = url;
                cmodal.classList.add('is-open');
            });
            cmodal.querySelectorAll('[data-contract-close]').forEach(function (el) {
                el.addEventListener('click', function () {
                    cmodal.classList.remove('is-open');
                    cframe.src = 'about:blank';
                });
            });
        })();
    </script>
    <script src="<?php echo asset_ver('public/js/shared/app_shell.js'); ?>"></script>
</body>

</html>