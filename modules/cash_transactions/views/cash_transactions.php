<!DOCTYPE html>
<html lang="vi">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Nhập thu chi</title>
    <link rel="icon" href="public/images/logo/logo_vat_png.png" type="image/png">
    <link rel="stylesheet" href="public/css/reset.css">
    <link rel="stylesheet" href="public/css/all.css">
    <link rel="stylesheet" href="public/css/global.css">
    <link rel="stylesheet" href="public/css/inventory_management/dashboard.css">
    <link rel="stylesheet" href="public/css/accounting/journal_entry.css">
    <link rel="stylesheet" href="public/css/cash_transactions/cash_transactions.css">
    <link rel="stylesheet" href="<?php echo asset_ver('public/css/shared/app_shell.css'); ?>">
    <link rel="stylesheet" href="public/css/shared/datetime_picker.css">
    <link rel="stylesheet" href="public/css/shared/history_filter.css">
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
                    <h1>NHẬP THU CHI</h1>
                </div>
                <label for="menu-toggle" class="menu-toggle-btn" aria-label="Mở menu">
                    <i class="fa-solid fa-bars"></i>
                </label>
            </div>
            <nav>
                <ul class="main-tab">
                    <li class="tab-item active">
                        <a href="?mod=cash_transactions&controllers=cash_transactions&action=cash_transactions">Nhập thu chi</a>
                    </li>
                </ul>
            </nav>
            <?php if (!permission_is_view_only('cash_transactions', 'cash_transactions', 'cash_transactions')): ?>
            <div class="cdb-actions">
                <button type="button" class="btn-check-db"
                    data-tables="cash_transactions,transactions">
                    <i class="fa-solid fa-database"></i> Check Database
                </button>
            </div>
            <?php endif; ?>
        </div>

        <div class="content">
            <div class="wp-date-picker">
                <label for="record-datetime">Ngày giờ ghi</label>
                <input type="datetime-local" id="record-datetime" class="js-green-datetime js-green-datetime-highlight" step="1">
            </div>

            <div class="edit-batch-banner" id="edit-batch-banner" style="display:none;">
                <span>Đang sửa phiếu: <strong id="edit-batch-label"></strong></span>
                <a href="#" id="cancel-edit-batch">Hủy</a>
            </div>

            <?php render_journal_entry_block('cash_transactions', 'je-card', true, 'none', 'auto', true); ?>

            <div class="ct-form" id="ct-form">
                <div class="ct-form-row">
                    <label for="ct-description">Diễn giải</label>
                    <input type="text" id="ct-description" class="ct-input" placeholder="Nội dung thu / chi..." autocomplete="off">
                </div>
                <div class="ct-form-row">
                    <label for="ct-type">Phân loại</label>
                    <select id="ct-type" class="ct-input">
                        <option value="Chi">Chi</option>
                        <option value="Thu">Thu</option>
                    </select>
                </div>
                <div class="ct-form-row">
                    <label for="ct-account">Tài khoản</label>
                    <select id="ct-account" class="ct-input">
                        <option value="TM">TM — Tiền mặt</option>
                        <option value="TG">TG — Tiền gửi</option>
                    </select>
                </div>
                <div class="ct-form-row" id="ct-account-number-row" style="display:none;">
                    <label for="ct-account-number">Số tài khoản</label>
                    <input type="text" id="ct-account-number" class="ct-input" placeholder="Số tài khoản ngân hàng..." autocomplete="off">
                </div>
                <div class="ct-form-row">
                    <label for="ct-amount">Số tiền</label>
                    <input type="text" id="ct-amount" class="ct-input ct-input-amount" inputmode="numeric" placeholder="0" autocomplete="off">
                </div>
            </div>

            <div class="wp-button">
                <div class="btn-record" id="btn-record">
                    <p>Ghi</p>
                </div>
                <div class="btn-edit" id="btn-edit">
                    <p>Sửa</p>
                </div>
            </div>
            <div class="line"></div>
            <div class="history-bar">
                <div class="history">
                    <p>Lịch sử</p>
                </div>
                <div class="history-filter" id="history-filter">
                <div class="hf-group hf-daterange">
                    <span class="hf-cal-icon"><i class="fa-regular fa-calendar-days"></i></span>
                    <label for="hf-date-from">Từ ngày</label>
                    <input type="date" id="hf-date-from" class="hf-date">
                    <span class="hf-arrow"><i class="fa-solid fa-arrow-right-long"></i></span>
                    <label for="hf-date-to">đến ngày</label>
                    <input type="date" id="hf-date-to" class="hf-date">
                </div>
                <div class="hf-group hf-rows">
                    <label for="hf-page-size">Số dòng</label>
                    <select id="hf-page-size" class="hf-select">
                        <option value="10">10</option>
                        <option value="20">20</option>
                        <option value="50">50</option>
                        <option value="100">100</option>
                    </select>
                </div>
                <span class="hf-count" id="hf-count"></span>
                <button type="button" class="hf-reset" id="hf-reset" title="Bỏ tất cả bộ lọc">
                    <i class="fa-solid fa-rotate-left"></i> Bỏ lọc
                </button>
                </div>
            </div>
            <table class="history-table" id="history-table">
                <thead>
                    <tr>
                        <td>Ngày</td>
                        <td>
                            <div class="th-filterable">
                                <span>Diễn giải</span>
                                <button type="button" class="th-filter-btn" id="hf-keyword-btn" title="Lọc theo diễn giải">
                                    <i class="fa-solid fa-filter"></i>
                                </button>
                                <div class="th-filter-pop" id="hf-keyword-pop">
                                    <input type="text" id="hf-keyword" placeholder="Lọc theo diễn giải..." autocomplete="off">
                                </div>
                            </div>
                        </td>
                        <td>Thao tác</td>
                    </tr>
                </thead>
                <tbody id="history-tbody"></tbody>
            </table>
            <div class="history-pagination" id="history-pagination"></div>
        </div>


    </div>

    <script>
        window.CT_CONFIG = {
            baseUrl: '?mod=cash_transactions&controllers=cash_transactions&action='
        };
        window.CT_DATA = {
            history: <?php echo json_encode($history ?? [], JSON_UNESCAPED_UNICODE); ?>
        };
    </script>
    <script src="public/js/accounting/journal_entry.js"></script>
    <script src="public/js/cash_transactions/cash_transactions.js"></script>
    <script src="public/js/shared/check_database.js"></script>
    <script src="public/js/shared/datetime_picker.js"></script>
    <script src="<?php echo asset_ver('public/js/shared/app_shell.js'); ?>"></script>
</body>

</html>