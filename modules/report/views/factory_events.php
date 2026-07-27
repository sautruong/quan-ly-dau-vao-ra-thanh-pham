<?php
defined('APPPATH') OR exit('Không được quyền truy cập phần này');
?>
<!DOCTYPE html>
<html lang="vi">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Sự kiện nhà máy — Vua An Toàn</title>
    <link rel="icon" href="public/images/logo/logo_vat_png.png" type="image/png">
    <link rel="stylesheet" href="<?php echo asset_ver('public/css/reset.css'); ?>">
    <link rel="stylesheet" href="<?php echo asset_ver('public/css/all.css'); ?>">
    <link rel="stylesheet" href="<?php echo asset_ver('public/css/global.css'); ?>">
    <link rel="stylesheet" href="<?php echo asset_ver('public/css/shared/app_shell.css'); ?>">
    <link rel="stylesheet" href="<?php echo asset_ver('public/css/calendar_full.css'); ?>">
    <link rel="stylesheet" href="<?php echo asset_ver('public/css/shared/invoice_upload.css'); ?>">
    <link rel="stylesheet" href="<?php echo asset_ver('public/css/report/factory_events.css'); ?>">
</head>

<body>
    <div id="wrapper" class="has-sider">
        <?php get_sidebar('app'); ?>
        <?php get_header('app'); ?>

        <div class="content calfull-page fev-page">
            <!-- MOBILE: gom bộ lọc vào 1 khối bật/tắt (kiểu FAQ) — Task 9 -->
            <button type="button" class="fev-filter-toggle" id="fev-filter-toggle" aria-expanded="false">
                <i class="fa-solid fa-sliders"></i>
                <span>Bộ lọc sự kiện</span>
                <i class="fa-solid fa-chevron-down fev-filter-toggle-caret"></i>
            </button>
            <div class="fev-filters" id="fev-filters">
                <div class="fev-filter-row" data-type="nk_supplier" data-search="suppliers">
                    <label class="fev-filter-check">
                        <input type="checkbox" class="fev-check">
                        <span class="fev-dot" style="background:#16a34a"></span>
                        Nhập kho - Nhà cung cấp
                    </label>
                    <div class="fev-combo" style="display:none"></div>
                </div>
                <div class="fev-filter-row" data-type="nk_goods" data-search="goods">
                    <label class="fev-filter-check">
                        <input type="checkbox" class="fev-check">
                        <span class="fev-dot" style="background:#16a34a"></span>
                        Nhập kho - Hàng hóa
                    </label>
                    <div class="fev-combo" style="display:none"></div>
                </div>
                <div class="fev-filter-row" data-type="tt_supplier" data-search="suppliers">
                    <label class="fev-filter-check">
                        <input type="checkbox" class="fev-check">
                        <span class="fev-dot" style="background:#d97706"></span>
                        Thanh toán - Nhà cung cấp
                    </label>
                    <div class="fev-combo" style="display:none"></div>
                </div>
                <div class="fev-filter-row" data-type="xk_customer" data-search="customers">
                    <label class="fev-filter-check">
                        <input type="checkbox" class="fev-check">
                        <span class="fev-dot" style="background:#dc2626"></span>
                        Xuất kho - Khách hàng
                    </label>
                    <div class="fev-combo" style="display:none"></div>
                </div>
                <div class="fev-filter-row" data-type="sx_product" data-search="products">
                    <label class="fev-filter-check">
                        <input type="checkbox" class="fev-check">
                        <span class="fev-dot" style="background:#16a34a"></span>
                        Sản xuất - Thành phẩm
                    </label>
                    <div class="fev-combo" style="display:none"></div>
                </div>
                <div class="fev-filter-row" data-type="xk_product" data-search="products">
                    <label class="fev-filter-check">
                        <input type="checkbox" class="fev-check">
                        <span class="fev-dot" style="background:#dc2626"></span>
                        Xuất kho - Thành phẩm
                    </label>
                    <div class="fev-combo" style="display:none"></div>
                </div>
            </div>

            <div class="calfull-head">
                <div class="calfull-nav-group">
                    <button type="button" class="app-cal-nav" id="fev-prev" title="Tháng trước"><i class="fa-solid fa-chevron-left"></i></button>
                    <h2 class="calfull-title" id="fev-title"></h2>
                    <button type="button" class="app-cal-nav" id="fev-next" title="Tháng sau"><i class="fa-solid fa-chevron-right"></i></button>
                </div>
                <button type="button" class="calfull-today-btn" id="fev-today-btn">
                    <i class="fa-solid fa-calendar-day"></i> Hôm nay
                </button>
            </div>

            <div class="calfull-weekdays">
                <span>Thứ 2</span><span>Thứ 3</span><span>Thứ 4</span><span>Thứ 5</span><span>Thứ 6</span><span>Thứ 7</span><span class="sun">CN</span>
            </div>
            <div class="calfull-grid fev-grid" id="fev-grid"></div>
        </div>
    </div>

    <!-- Modal: xem đầy đủ sự kiện trong 1 ngày -->
    <div class="app-modal" id="fev-day-modal" aria-hidden="true">
        <div class="app-modal-overlay" data-fev-day-close></div>
        <div class="app-modal-box calfull-day-box">
            <div class="app-modal-head">
                <h3 id="fev-day-modal-title"></h3>
                <button type="button" class="app-modal-close" data-fev-day-close aria-label="Đóng">&times;</button>
            </div>
            <div class="app-modal-body">
                <div class="calfull-events fev-day-modal-events" id="fev-day-modal-events"></div>
            </div>
        </div>
    </div>

    <!-- Modal: thư viện ảnh hóa đơn/thanh toán -->
    <div class="app-modal" id="fev-att-modal" aria-hidden="true">
        <div class="app-modal-overlay" data-fev-att-close></div>
        <div class="app-modal-box fev-att-box">
            <div class="app-modal-head">
                <h3>Hình ảnh đính kèm</h3>
                <button type="button" class="app-modal-close" data-fev-att-close aria-label="Đóng">&times;</button>
            </div>
            <div class="app-modal-body">
                <div class="fev-att-grid" id="fev-att-grid"></div>
            </div>
        </div>
    </div>

    <script src="<?php echo asset_ver('public/js/shared/invoice_dropzone.js'); ?>"></script>
    <script src="<?php echo asset_ver('public/js/report/factory_events.js'); ?>"></script>
    <script src="<?php echo asset_ver('public/js/shared/app_shell.js'); ?>"></script>
</body>

</html>
