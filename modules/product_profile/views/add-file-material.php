<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Thêm file nguyên liệu</title>
    <link rel="icon" href="public/images/logo/logo_vat_png.png" type="image/png">
    <link rel="stylesheet" href="public/css/reset.css">
    <link rel="stylesheet" href="public/css/all.css">
    <link rel="stylesheet" href="public/css/global.css">
    <link rel="stylesheet" href="<?php echo asset_ver('public/css/product_profile/list.css'); ?>">

    <script src="public/js/jquery-4.0.0.js" type="text/javascript" defer></script>
    <script src="public/js/menu_sidebar_left.js" defer></script>
    <link rel="stylesheet" href="<?php echo asset_ver('public/css/shared/app_shell.css'); ?>">
</head>
<style>
    .wp-content-addfile {
        width: 40%;
        background: #fff;
        padding: 22px;
        border-radius: 14px;
        box-shadow: 0 6px 18px rgba(0, 0, 0, 0.08);
        transition: 0.3s;
    }
    .wp-content-addfile:hover {
        box-shadow: 0 10px 25px rgba(0, 0, 0, 0.12);
    }
    .wp-content-addfile p {
        font-size: 15px;
        margin-bottom: 18px;
    }
    .wp-content-addfile strong {
        color: green;
    }
    .wp-content-addfile form {
        display: flex;
        flex-direction: column;
        gap: 18px;
    }
    .wp-name-file {
        display: flex;
        align-items: center;
        gap: 10px;
    }
    label {
        min-width: 120px;
        font-size: 14px;
        color: #444;
        margin: 0;
    }
    input[type="text"] {
        flex: 1;
        padding: 8px 6px;
        border: none;
        border-bottom: 1.5px solid #ddd;
        outline: none;
        transition: 0.25s;
    }
    input[type="text"]:focus {
        border-bottom: 1.5px solid #78d675;
    }
    .wp-upload {
        display: flex;
        gap: 10px;
        align-items: center;
    }
    #file_name {
        flex: 1;
        background: #f8f9fa;
        border-radius: 8px;
        padding: 8px;
        border: 1px solid #eee;
    }
    #btn_choose {
        padding: 8px 14px;
        border: none;
        border-radius: 8px;
        background: #cdf0c6;
        color: green;
        cursor: pointer;
        transition: 0.25s;
    }
    #btn_choose:hover {
        background: Green;
        color: #fff;
        transform: translateY(-1px);
    }
    .btn-submit {
        padding: 10px;
        border: none;
        border-radius: 10px;
        background: green;
        color: #fff;
        font-weight: 600;
        cursor: pointer;
        transition: 0.25s;
    }
    .btn-submit:hover {
        background: green;
        transform: translateY(-2px);
        box-shadow: 0 6px 15px rgba(139, 199, 133, 0.3);
    }
    .btn-submit:active,
    #btn_choose:active {
        transform: scale(0.96);
    }
</style>

<body>
    <div id="wrapper">
        <?php require "layouts/sidebar-left.php"; ?>
        <div id="sidebar-right">
            <?php require "layouts/top-sidebar-right.php"; ?>
            <div class="main-content">
                <div class="wp-content-addfile">
                    <p>Bổ sung file cho nguyên liệu: <strong><?= mb_strtoupper($data['material_name']) ?></strong></p>
                    <form enctype="multipart/form-data" action="?mod=product_profile&controllers=product_profile&action=ready_add_file_material&id=<?= $data['id'] ?>&product_id=<?= $data['product_id'] ?>" method="POST">
                        <div class="wp-name-file">
                            <label for="name_file">Đặt tên file:</label>
                            <input type="text" id="name_file" name="file_label" required>
                        </div>
                        <div class="wp-name-file">
                            <label for="folder_name">Tên thư mục:</label>
                            <input type="text" id="folder_name" name="folder_name" value="<?= htmlspecialchars($data['material_name']) ?>" required>
                        </div>
                        <div class="wp-upload">
                            <input type="file" name="file_upload" id="file_upload" hidden>
                            <input type="text" id="file_name" placeholder="Chưa chọn file" readonly>
                            <button type="button" id="btn_choose">Chọn file</button>
                        </div>
                        <button type="submit" class="btn-submit">THÊM</button>
                    </form>
                </div>
            </div>
        </div>
    </div>

    <script defer>
        document.addEventListener('DOMContentLoaded', function () {
            document.getElementById('btn_choose').addEventListener('click', function () {
                document.getElementById('file_upload').click();
            });
            document.getElementById('file_upload').addEventListener('change', function () {
                var file = this.files[0];
                if (file) {
                    document.getElementById('file_name').value = file.name;
                }
            });
        });
    </script>
    <script src="<?php echo asset_ver('public/js/shared/app_shell.js'); ?>"></script>
</body>

</html>
