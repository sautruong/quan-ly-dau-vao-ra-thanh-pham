<?php defined('APPPATH') OR exit('Không được quyền truy cập phần này'); ?>
<!DOCTYPE html>
<html lang="vi">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Luồng một sản phẩm</title>
    <link rel="icon" href="public/images/logo/logo_vat_png.png" type="image/png">
    <link rel="stylesheet" href="public/css/reset.css">
    <link rel="stylesheet" href="public/css/all.css">
    <link rel="stylesheet" href="public/css/global.css">
    <link rel="stylesheet" href="public/css/inventory_management/dashboard.css">
    <link rel="stylesheet" href="public/css/accounting_factory/flow.css">
    <link rel="stylesheet" href="<?php echo asset_ver('public/css/shared/app_shell.css'); ?>">
</head>

<body>
    <div id="wrapper">
        <?php get_sidebar('app'); ?>
        <?php get_header('app'); ?>
        <div class="content">
            <div class="af-wrap">
                <div class="af-controls">
                    <div class="af-field af-search">
                        <label for="af-keyword">Sản phẩm</label>
                        <input type="text" id="af-keyword" autocomplete="off" placeholder="Tìm sản phẩm theo tên...">
                        <ul class="af-dropdown" id="af-dropdown"></ul>
                    </div>
                    <div class="af-field">
                        <label for="af-from">Từ ngày</label>
                        <input type="date" id="af-from" value="<?php echo htmlspecialchars($today ?? date('Y-m-d')); ?>">
                    </div>
                    <div class="af-field">
                        <label for="af-to">Đến ngày</label>
                        <input type="date" id="af-to" value="<?php echo htmlspecialchars($today ?? date('Y-m-d')); ?>">
                    </div>
                    <button type="button" class="af-btn-pdf" id="af-pdf" disabled>
                        <i class="fa-solid fa-file-pdf"></i> Xuất PDF
                    </button>
                </div>

                <div class="af-empty" id="af-empty">Chọn sản phẩm và khoảng thời gian để xem luồng.</div>

                <div class="af-report" id="af-report">
                    <div class="af-sheet-head">
                        <p class="af-company">Công ty TNHH Vua An Toàn</p>
                        <h2>LUỒNG MỘT SẢN PHẨM</h2>
                        <p class="af-meta" id="af-meta"></p>
                    </div>

                    <div class="af-balance">
                        <div><div class="lbl">Tồn đầu kỳ (đầu ngày <span id="af-from-lbl"></span>)</div><div class="val" id="af-opening">0</div></div>
                        <div style="text-align:right;"><div class="lbl">Tồn cuối kỳ (cuối ngày <span id="af-to-lbl"></span>)</div><div class="val" id="af-closing">0</div></div>
                    </div>

                    <div class="af-section">
                        <h3>Nhập kho sản xuất</h3>
                        <table class="af-table">
                            <thead><tr><th>Ngày</th><th>Số lượng</th><th>Giá vốn</th></tr></thead>
                            <tbody id="af-nhap-sx"></tbody>
                            <tfoot><tr><td class="l">Tổng</td><td id="af-sx-qty">0</td><td id="af-sx-val">0 đ</td></tr></tfoot>
                        </table>
                    </div>

                    <div class="af-section">
                        <h3>Nhập kho khác</h3>
                        <table class="af-table">
                            <thead><tr><th>Ngày</th><th>Số lượng</th><th>Giá trị</th></tr></thead>
                            <tbody id="af-nhap-khac"></tbody>
                            <tfoot><tr><td class="l">Tổng</td><td id="af-nkk-qty">0</td><td id="af-nkk-val">0 đ</td></tr></tfoot>
                        </table>
                    </div>

                    <div class="af-section">
                        <h3>Nhập hàng bán trả lại</h3>
                        <table class="af-table">
                            <thead><tr><th>Ngày</th><th>Số lượng</th><th>Giá trị hàng trả</th></tr></thead>
                            <tbody id="af-ban-tra"></tbody>
                            <tfoot><tr><td class="l">Tổng</td><td id="af-bt-qty">0</td><td id="af-bt-val">0 đ</td></tr></tfoot>
                        </table>
                    </div>

                    <div class="af-section">
                        <h3>Xuất kho bán hàng</h3>
                        <table class="af-table">
                            <thead><tr><th>Ngày</th><th>Số lượng</th><th>Giá trị</th></tr></thead>
                            <tbody id="af-xuat-ban"></tbody>
                            <tfoot><tr><td class="l">Tổng</td><td id="af-xb-qty">0</td><td id="af-xb-val">0 đ</td></tr></tfoot>
                        </table>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <script>
        window.AF_CONFIG = {
            kind: 'product',
            baseUrl: '?mod=accounting_factory&controllers=accounting_factory&action='
        };
    </script>
    <script src="public/js/accounting_factory/flow.js"></script>
    <script src="<?php echo asset_ver('public/js/shared/app_shell.js'); ?>"></script>
</body>

</html>
