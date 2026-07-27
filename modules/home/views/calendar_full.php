<?php
defined('APPPATH') OR exit('Không được quyền truy cập phần này');
?>
<!DOCTYPE html>
<html lang="vi">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Lịch — Vua An Toàn</title>
    <link rel="icon" href="public/images/logo/logo_vat_png.png" type="image/png">
    <link rel="stylesheet" href="<?php echo asset_ver('public/css/reset.css'); ?>">
    <link rel="stylesheet" href="<?php echo asset_ver('public/css/all.css'); ?>">
    <link rel="stylesheet" href="<?php echo asset_ver('public/css/global.css'); ?>">
    <link rel="stylesheet" href="<?php echo asset_ver('public/css/shared/app_shell.css'); ?>">
    <link rel="stylesheet" href="<?php echo asset_ver('public/css/calendar_full.css'); ?>">
</head>

<body>
    <div id="wrapper" class="has-sider">
        <?php get_sidebar('app'); ?>
        <?php get_header('app'); ?>

        <div class="content calfull-page">
            <div class="calfull-head">
                <div class="calfull-nav-group">
                    <button type="button" class="app-cal-nav" id="calfull-prev" title="Tháng trước"><i class="fa-solid fa-chevron-left"></i></button>
                    <h2 class="calfull-title" id="calfull-title"></h2>
                    <button type="button" class="app-cal-nav" id="calfull-next" title="Tháng sau"><i class="fa-solid fa-chevron-right"></i></button>
                </div>
                <div class="calfull-view-toggle" id="calfull-view-toggle">
                    <button type="button" class="calfull-view-btn is-active" data-view="month">Theo tháng</button>
                    <button type="button" class="calfull-view-btn" data-view="year">Theo năm</button>
                </div>
                <button type="button" class="calfull-today-btn" id="calfull-today-btn">
                    <i class="fa-solid fa-calendar-day"></i> Hôm nay
                </button>
            </div>

            <div id="calfull-month-view">
                <div class="calfull-weekdays">
                    <span>Thứ 2</span><span>Thứ 3</span><span>Thứ 4</span><span>Thứ 5</span><span>Thứ 6</span><span>Thứ 7</span><span class="sun">CN</span>
                </div>
                <div class="calfull-grid" id="calfull-grid"></div>
            </div>
            <div id="calfull-year-view" class="calfull-year-grid" style="display:none;"></div>
        </div>
    </div>

    <!-- Modal đổi giờ nhắc của trang này (id riêng, khác modal trong header-app.php). -->
    <div class="app-modal" id="calfull-time-modal" aria-hidden="true">
        <div class="app-modal-overlay" data-calfull-time-close></div>
        <div class="app-modal-box app-cal-time-box">
            <div class="app-modal-head">
                <h3>Đổi giờ nhắc</h3>
                <button type="button" class="app-modal-close" data-calfull-time-close aria-label="Đóng">&times;</button>
            </div>
            <div class="app-modal-body">
                <label class="app-pw-label">Giờ nhắc</label>
                <input type="time" class="app-pw-input" id="calfull-time-modal-input">
                <label class="app-cal-time-modal-clear">
                    <input type="checkbox" id="calfull-time-modal-noclock"> Không nhắc giờ (sự kiện cả ngày)
                </label>
                <button type="button" class="app-btn app-btn-primary app-btn-block" id="calfull-time-modal-save">Lưu</button>
                <p class="app-pw-msg" id="calfull-time-modal-msg"></p>
            </div>
        </div>
    </div>

    <!-- Modal xem đầy đủ sự kiện trong 1 ngày (khi 1 ngày có nhiều hơn 3 sự kiện). -->
    <div class="app-modal" id="calfull-day-modal" aria-hidden="true">
        <div class="app-modal-overlay" data-calfull-day-close></div>
        <div class="app-modal-box calfull-day-box">
            <div class="app-modal-head">
                <h3 id="calfull-day-modal-title"></h3>
                <button type="button" class="app-modal-close" data-calfull-day-close aria-label="Đóng">&times;</button>
            </div>
            <div class="app-modal-body">
                <div class="calfull-events calfull-day-modal-events" id="calfull-day-modal-events"></div>
            </div>
        </div>
    </div>

    <!-- Modal thiết lập lặp lại 1 sự kiện (hằng ngày/tuần/tháng/năm) — tự sinh sẵn các dòng sự kiện trong 1 năm tới. -->
    <div class="app-modal" id="calfull-recur-modal" aria-hidden="true">
        <div class="app-modal-overlay" data-calfull-recur-close></div>
        <div class="app-modal-box calfull-recur-box">
            <div class="app-modal-head">
                <h3>Lặp lại sự kiện</h3>
                <button type="button" class="app-modal-close" data-calfull-recur-close aria-label="Đóng">&times;</button>
            </div>
            <div class="app-modal-body">
                <div class="calfull-recur-tabs" id="calfull-recur-tabs">
                    <button type="button" class="calfull-recur-tab is-active" data-freq="daily">Hằng ngày</button>
                    <button type="button" class="calfull-recur-tab" data-freq="weekly">Hằng tuần</button>
                    <button type="button" class="calfull-recur-tab" data-freq="monthly">Hằng tháng</button>
                    <button type="button" class="calfull-recur-tab" data-freq="yearly">Hằng năm</button>
                </div>

                <div class="calfull-recur-panel" data-panel="daily">
                    <label class="calfull-recur-radio">
                        <input type="radio" name="calfull-daily-mode" value="every" checked> Mỗi ngày
                    </label>
                    <label class="calfull-recur-radio">
                        <input type="radio" name="calfull-daily-mode" value="custom">
                        Mỗi <input type="number" min="2" max="365" value="2" class="calfull-recur-num" id="calfull-recur-daily-n" disabled> ngày
                    </label>
                </div>

                <div class="calfull-recur-panel" data-panel="weekly" style="display:none;">
                    <label class="calfull-recur-row">Mỗi <input type="number" min="1" max="52" value="1" class="calfull-recur-num" id="calfull-recur-weekly-n"> tuần</label>
                    <div class="calfull-recur-weekdays" id="calfull-recur-weekdays">
                        <label><input type="checkbox" value="1"> Thứ 2</label>
                        <label><input type="checkbox" value="2"> Thứ 3</label>
                        <label><input type="checkbox" value="3"> Thứ 4</label>
                        <label><input type="checkbox" value="4"> Thứ 5</label>
                        <label><input type="checkbox" value="5"> Thứ 6</label>
                        <label><input type="checkbox" value="6"> Thứ 7</label>
                        <label><input type="checkbox" value="7"> CN</label>
                    </div>
                </div>

                <div class="calfull-recur-panel" data-panel="monthly" style="display:none;">
                    <label class="calfull-recur-row">Mỗi <input type="number" min="1" max="24" value="1" class="calfull-recur-num" id="calfull-recur-monthly-n"> tháng</label>
                    <label class="calfull-recur-row">Vào ngày <input type="number" min="1" max="31" value="1" class="calfull-recur-num" id="calfull-recur-monthly-day"> hằng tháng</label>
                </div>

                <div class="calfull-recur-panel" data-panel="yearly" style="display:none;">
                    <label class="calfull-recur-row">Mỗi <input type="number" min="1" max="10" value="1" class="calfull-recur-num" id="calfull-recur-yearly-n"> năm</label>
                    <label class="calfull-recur-row">
                        Vào ngày <input type="number" min="1" max="31" value="1" class="calfull-recur-num" id="calfull-recur-yearly-day">
                        tháng <input type="number" min="1" max="12" value="1" class="calfull-recur-num" id="calfull-recur-yearly-month">
                    </label>
                </div>

                <p class="app-pw-msg" id="calfull-recur-msg"></p>
                <div class="calfull-recur-actions">
                    <button type="button" class="app-btn-link" id="calfull-recur-clear" style="display:none;">Xóa lặp lại</button>
                    <button type="button" class="app-btn app-btn-primary" id="calfull-recur-save">Lưu</button>
                </div>
            </div>
        </div>
    </div>

    <script src="<?php echo asset_ver('public/js/calendar_full.js'); ?>"></script>
    <script src="<?php echo asset_ver('public/js/shared/app_shell.js'); ?>"></script>
</body>

</html>
