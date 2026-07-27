<!DOCTYPE html>
<html lang="vi">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Quản lý dự án</title>
    <link rel="icon" href="public/images/logo/logo_vat_png.png" type="image/png">
    <link rel="stylesheet" href="<?php echo asset_ver('public/css/reset.css'); ?>">
    <link rel="stylesheet" href="<?php echo asset_ver('public/css/all.css'); ?>">
    <link rel="stylesheet" href="<?php echo asset_ver('public/css/global.css'); ?>">
    <link rel="stylesheet" href="<?php echo asset_ver('public/css/shared/app_shell.css'); ?>">
    <link rel="stylesheet" href="<?php echo asset_ver('public/css/project_management/dashboard.css'); ?>">
</head>

<body>
    <div id="wrapper" class="has-sider">
        <?php get_sidebar('app'); ?>
        <?php get_header('app'); ?>

        <div class="content">
            <div class="pm-toolbar">
                <button type="button" class="pm-btn-new" id="pm-new-project">
                    <i class="fa-solid fa-plus"></i> Tạo dự án
                </button>
                <div class="pm-toolbar-spacer"></div>
                <div class="pm-search">
                    <i class="fa-solid fa-magnifying-glass"></i>
                    <input type="text" id="pm-search" placeholder="Tìm dự án...">
                </div>
            </div>

            <div class="pm-grid" id="pm-grid">
                <!-- JS render card vào đây -->
            </div>
            <div class="pm-empty" id="pm-empty" style="display:none">
                <i class="fa-regular fa-folder-open"></i>
                <p>Chưa có dự án nào. Bấm <b>Tạo dự án</b> để bắt đầu.</p>
            </div>
        </div>
    </div>

    <!-- ===== Template card dự án ===== -->
    <template id="pm-card-tpl">
        <div class="pm-card" data-id="">
            <div class="pm-card-actions">
                <button type="button" class="pm-card-edit" title="Sửa tên / mô tả dự án"><i class="fa-solid fa-pen"></i></button>
                <button type="button" class="pm-card-del" title="Xóa dự án"><i class="fa-solid fa-trash"></i></button>
            </div>
            <div class="pm-card-top">
                <span class="pm-role-badge"></span>
                <h3 class="pm-card-name"></h3>
            </div>
            <p class="pm-card-desc"></p>
            <div class="pm-card-foot">
                <span class="pm-card-members"><i class="fa-solid fa-users"></i> <b class="pm-mc">0</b></span>
                <span class="pm-card-sessions"><i class="fa-solid fa-folder-open"></i> <b class="pm-sc">0</b> session</span>
            </div>
        </div>
    </template>

    <!-- ===== Modal tạo / sửa dự án ===== -->
    <div class="pm-modal" id="pm-modal" style="display:none">
        <div class="pm-modal-box">
            <div class="pm-modal-head">
                <h3 id="pm-modal-title">Tạo dự án mới</h3>
                <button type="button" class="pm-modal-close" id="pm-modal-close"><i class="fa-solid fa-xmark"></i></button>
            </div>
            <div class="pm-modal-body">
                <label>Tên dự án</label>
                <input type="text" id="pm-f-name" maxlength="250" placeholder="Nhập tên dự án...">
                <label>Mô tả ngắn</label>
                <textarea id="pm-f-desc" rows="3" placeholder="Mục tiêu, phạm vi của dự án..."></textarea>
            </div>
            <div class="pm-modal-foot">
                <button type="button" class="pm-btn-ghost" id="pm-cancel">Hủy</button>
                <button type="button" class="pm-btn-primary" id="pm-save">Lưu</button>
            </div>
        </div>
    </div>

    <script>
        window.PM_CONFIG = { baseUrl: '?mod=project_management&controllers=project&action=' };
        window.PM_DATA = { projects: <?php echo json_encode($projects ?? [], JSON_UNESCAPED_UNICODE); ?> };
    </script>
    <script src="<?php echo asset_ver('public/js/project_management/dashboard.js'); ?>"></script>
    <script src="<?php echo asset_ver('public/js/shared/app_shell.js'); ?>"></script>
</body>

</html>
