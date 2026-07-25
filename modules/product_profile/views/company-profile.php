<?php global $config; ?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Hồ sơ doanh nghiệp</title>
    <link rel="icon" href="public/images/logo/logo_vat_png.png" type="image/png">
    <link rel="stylesheet" href="public/css/reset.css">
    <link rel="stylesheet" href="public/css/all.css">
    <link rel="stylesheet" href="public/css/global.css">
    <link rel="stylesheet" href="public/css/product_profile/list.css">
    <link rel="stylesheet" href="public/css/product_profile/modal.css">
    <link rel="stylesheet" href="public/css/product_profile/company.css">

    <script src="public/js/jquery-4.0.0.js" type="text/javascript" defer></script>
    <script src="public/js/product_profile_modal.js" defer></script>
    <script src="public/js/product_profile_company.js" defer></script>
    <link rel="stylesheet" href="<?php echo asset_ver('public/css/shared/app_shell.css'); ?>">
</head>
<style>
    #name-dashboard { display: flex; gap: 10px; align-items: center; }
    h1 { margin-bottom: 0; }
</style>

<body>
    <div id="wrapper">
        <div id="sidebar-right">
            <?php require "layouts/top-sidebar-right.php"; ?>
            <div class="main-content">

                <div class="cp-toolbar">
                    <button type="button" class="btn-add-company" data-pp-open="#add-company-modal">
                        <i class="fa-solid fa-plus"></i> Thêm hồ sơ
                    </button>
                </div>

                <div class="company-profile-list">
                    <?php if (empty($groups)): ?>
                        <p class="cp-empty">Chưa có hồ sơ doanh nghiệp nào. Bấm "Thêm hồ sơ" để bắt đầu.</p>
                    <?php else: ?>
                        <?php foreach ($groups as $gname => $files): ?>
                            <div class="cp-group" data-group-name="<?= htmlspecialchars($gname, ENT_QUOTES) ?>">
                                <div class="cp-group-title">
                                    <i class="fa-solid fa-award cp-emblem"></i>
                                    <span><?= htmlspecialchars($gname) ?></span>
                                </div>
                                <ul class="cp-files">
                                    <?php foreach ($files as $f): ?>
                                        <li class="cp-file" data-file-id="<?= $f['id'] ?>">
                                            <div class="cp-file-main">
                                                <a class="cp-file-name" href="<?= $config['base_url'] . $f['file_path'] ?>" target="_blank">
                                                    <?= htmlspecialchars($f['file_name']) ?>
                                                </a>
                                                <div class="cp-file-ops">
                                                    <a href="?mod=product_profile&controllers=product_profile&action=download_composition_file&file_id=<?= $f['id'] ?>" title="Tải xuống">
                                                        <i class="fa-solid fa-download"></i>
                                                    </a>
                                                    <button type="button" class="cp-delete-file" data-file-id="<?= $f['id'] ?>" title="Xóa file">
                                                        <i class="fa-regular fa-trash-can"></i>
                                                    </button>
                                                </div>
                                            </div>
                                            <div class="cp-file-date">Ngày tải lên: <?= !empty($f['created_at']) ? date('d/m/Y H:i', strtotime($f['created_at'])) : '' ?></div>
                                        </li>
                                    <?php endforeach; ?>
                                </ul>
                                <div class="cp-group-foot">
                                    <button type="button" class="cp-upload-btn" data-cp-upload>
                                        <i class="fa-solid fa-cloud-arrow-up"></i> Tải file lên
                                    </button>
                                    <input type="file" class="cp-upload-input" multiple hidden>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>

    <!-- MODAL THÊM HỒ SƠ DOANH NGHIỆP -->
    <div class="pp-modal-overlay" id="add-company-modal">
        <div class="pp-modal">
            <div class="pp-modal-header">
                <h3>Thêm hồ sơ</h3>
                <button type="button" class="pp-modal-close" aria-label="Đóng">&times;</button>
            </div>
            <div class="pp-modal-body">
                <form id="company-form" class="cp-form">
                    <input type="text" id="cp-group-name" class="cp-name-input" placeholder="Đặt tên hồ sơ" autocomplete="off">

                    <div class="cp-dropzone" id="cp-dropzone">
                        <i class="fa-solid fa-cloud-arrow-up"></i>
                        <p>Kéo &amp; thả file vào đây hoặc bấm để chọn</p>
                        <input type="file" id="cp-file-input" multiple hidden>
                    </div>

                    <ul class="cp-selected" id="cp-selected"></ul>

                    <button type="submit" class="btn-submit cp-submit">Thêm</button>
                </form>
            </div>
        </div>
    </div>
    <script src="<?php echo asset_ver('public/js/shared/app_shell.js'); ?>"></script>
</body>

</html>
