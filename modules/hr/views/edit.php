<?php

require_once 'helper/hr_helper.php';
$id = $_GET['id'];
global $table_hr_config;

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
    <link rel="stylesheet" href="public/css/style_edit_hr.css">
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
                <form method="POST" action="?mod=hr&controllers=hr&action=update&id=<?php echo $id ?>">
                    <?php foreach ($table_hr_config as $table => $config): ?>
                        <div class="group-table">

                            <h4 class="group-title">
                                <?= get_table_title($table) ?>
                            </h4>

                            <div class="form-grid">
                                <?php
                                $hiddenFields = $config['hidden']['form'] ?? [];
                                foreach ($config['labels'] as $column => $label): ?>
                                    <?php if (in_array($column, $hiddenFields)) continue; ?>
                                    <div class="form-group">
                                        <label><?= $label ?></label>

                                        <?= render_input($table, $column, $data[$table][$column] ?? '') ?>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        </div>
                    <?php endforeach; ?>

                    <input type="submit" value="Cập nhật" id="btn-submit">
                </form>
            </div>
        </div>
    </div>
    <script src="<?php echo asset_ver('public/js/shared/app_shell.js'); ?>"></script>
</body>

</html>