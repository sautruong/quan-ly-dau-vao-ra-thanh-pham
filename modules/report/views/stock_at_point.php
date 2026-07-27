<?php
$today_text = 'Ngày ' . date('j') . ' tháng ' . date('n') . ' năm ' . date('Y');
$today_ymd  = date('Y-m-d');
?>
<!DOCTYPE html>
<html lang="vi">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Tồn tại một thời điểm</title>
    <link rel="icon" href="public/images/logo/logo_vat_png.png" type="image/png">
    <link rel="stylesheet" href="<?php echo asset_ver('public/css/reset.css'); ?>">
    <link rel="stylesheet" href="<?php echo asset_ver('public/css/all.css'); ?>">
    <link rel="stylesheet" href="<?php echo asset_ver('public/css/global.css'); ?>">
    <link rel="stylesheet" href="<?php echo asset_ver('public/css/inventory_management/dashboard.css'); ?>">
    <link rel="stylesheet" href="<?php echo asset_ver('public/css/report/finished_goods_inventory.css'); ?>">
    <link rel="stylesheet" href="<?php echo asset_ver('public/css/report/stock_at_point.css'); ?>">
    <link rel="stylesheet" href="<?php echo asset_ver('public/css/shared/app_shell.css'); ?>">
</head>

<body>
    <div id="wrapper">
        <?php get_sidebar('app'); ?>
        <?php get_header('app'); ?>
        <div class="header" style="display:none" aria-hidden="true">
            <input type="checkbox" id="menu-toggle" class="menu-toggle">
            <div class="wp-logo-title">
                <div class="logo">
                    <a href="?mod=home">
                        <img src="public/images/logo/logo_vat_png.png" alt="" style="width:40px">
                    </a>
                </div>
                <div class="title">
                    <h1>TỒN KHO</h1>
                </div>
                <label for="menu-toggle" class="menu-toggle-btn" aria-label="Mở menu">
                    <i class="fa-solid fa-bars"></i>
                </label>
            </div>
            <nav>
                <ul class="main-tab">
                    <li class="tab-item">
                        <a href="?mod=report&controllers=report&action=finished_goods_inventory">TỒN THÀNH PHẨM</a>
                    </li>
                    <li class="tab-item">
                        <a href="?mod=report&controllers=report&action=material_inventory">TỒN NGUYÊN VẬT LIỆU</a>
                    </li>
                    <li class="tab-item active">
                        <a href="?mod=report&controllers=report&action=stock_at_point">TỒN TẠI MỘT THỜI ĐIỂM</a>
                    </li>
                </ul>
            </nav>
            <div class="date">
                <p><?php echo $today_text; ?></p>
            </div>
        </div>

        <div class="content">
            <!-- Bộ chọn thời điểm -->
            <div class="sap-controls">
                <div class="sap-datepick">
                    <label for="sap-date">Chọn ngày:</label>
                    <input type="date" id="sap-date" value="<?php echo $today_ymd; ?>">
                </div>
                <div class="sap-modes">
                    <button type="button" id="sap-btn-start" class="sap-mode-btn">Đầu ngày</button>
                    <button type="button" id="sap-btn-end" class="sap-mode-btn active">Cuối ngày</button>
                </div>
                <div class="sap-status" id="sap-status">Đang tải…</div>
            </div>
            <p class="sap-hint">
                <i class="fa-solid fa-circle-info"></i>
                <b>Đầu ngày</b>: không tính phát sinh trong ngày đã chọn.
                <b>Cuối ngày</b>: tính luôn phát sinh trong ngày đã chọn.
            </p>

            <!-- Tồn thành phẩm -->
            <div class="sap-section">
                <div class="sap-section-head">
                    <h2>Tồn thành phẩm</h2>
                    <div class="sap-tools">
                        <input type="text" id="sap-search-product" class="sap-search" placeholder="Lọc thành phẩm…" autocomplete="off">
                        <span class="sap-export-label">Xuất Excel:</span>
                        <button type="button" class="sap-export" data-target="product" data-scope="all">Toàn bộ</button>
                        <button type="button" class="sap-export" data-target="product" data-scope="instock">Chỉ có tồn</button>
                    </div>
                </div>
                <table class="sap-table" id="sap-table-product">
                    <thead>
                        <tr>
                            <th style="width:56px">STT</th>
                            <th>Thành phẩm</th>
                            <th>Danh mục</th>
                            <th style="width:90px">ĐVT</th>
                            <th class="num" style="width:120px">Tồn</th>
                        </tr>
                    </thead>
                    <tbody></tbody>
                </table>
            </div>

            <!-- Tồn nguyên vật liệu -->
            <div class="sap-section">
                <div class="sap-section-head">
                    <h2>Tồn nguyên vật liệu</h2>
                    <div class="sap-tools">
                        <input type="text" id="sap-search-material" class="sap-search" placeholder="Lọc NVL…" autocomplete="off">
                        <span class="sap-export-label">Xuất Excel:</span>
                        <button type="button" class="sap-export" data-target="material" data-scope="all">Toàn bộ</button>
                        <button type="button" class="sap-export" data-target="material" data-scope="instock">Chỉ có tồn</button>
                    </div>
                </div>
                <table class="sap-table" id="sap-table-material">
                    <thead>
                        <tr>
                            <th style="width:56px">STT</th>
                            <th>Nguyên vật liệu</th>
                            <th>Nhà cung cấp</th>
                            <th style="width:90px">ĐVT</th>
                            <th class="num" style="width:120px">Tồn</th>
                        </tr>
                    </thead>
                    <tbody></tbody>
                </table>
            </div>
        </div>
    </div>

    <script>
        window.REPORT_CFG = { baseUrl: '?mod=report&controllers=report&action=' };
    </script>
    <script src="<?php echo asset_ver('public/js/report/stock_at_point.js'); ?>"></script>
    <script src="<?php echo asset_ver('public/js/shared/app_shell.js'); ?>"></script>
</body>

</html>
