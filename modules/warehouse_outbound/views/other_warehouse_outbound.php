<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Xuất kho</title>
    <link rel="icon" href="public/images/logo/logo_vat_png.png" type="image/png">
    <link rel="stylesheet" href="<?php echo asset_ver('public/css/reset.css'); ?>">
    <link rel="stylesheet" href="<?php echo asset_ver('public/css/all.css'); ?>">
    <link rel="stylesheet" href="<?php echo asset_ver('public/css/global.css'); ?>">
    <link rel="stylesheet" href="<?php echo asset_ver('public/css/inventory_management/dashboard.css'); ?>">
    <link rel="stylesheet" href="<?php echo asset_ver('public/css/inventory_management/sales_issue.css'); ?>">
    <link rel="stylesheet" href="<?php echo asset_ver('public/css/warehouse_outbound/other_warehouse_outbound.css'); ?>">
    <link rel="stylesheet" href="<?php echo asset_ver('public/css/accounting/journal_entry.css'); ?>">

    <link rel="stylesheet" href="<?php echo asset_ver('public/css/shared/app_shell.css'); ?>">
    <link rel="stylesheet" href="<?php echo asset_ver('public/css/shared/datetime_picker.css'); ?>">
    <link rel="stylesheet" href="<?php echo asset_ver('public/css/shared/history_filter.css'); ?>">
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
                    <h1>XUẤT KHO</h1>
                </div>
                <label for="menu-toggle" class="menu-toggle-btn" aria-label="Mở menu">
                    <i class="fa-solid fa-bars"></i>
                </label>
            </div>
            <nav>
                <ul class="main-tab">
                    <li class="tab-item">
                        <a href="?mod=warehouse_outbound&controllers=warehouse_outbound&action=sales_delivery_note">Xuất kho bán hàng</a>
                    </li>
                    <li class="tab-item active">
                        <a href="?mod=warehouse_outbound&controllers=warehouse_outbound&action=other_warehouse_outbound">Xuất kho khác</a>
                    </li>
                    <li class="tab-item">
                        <a href="?mod=warehouse_outbound&controllers=warehouse_outbound&action=">Xuất kho nguyên vật liệu dùng sản xuất</a>
                    </li>
                </ul>
            </nav>
            <?php if (!permission_is_view_only('warehouse_outbound', 'warehouse_outbound', 'other_warehouse_outbound')): ?>
            <div class="cdb-actions">
                <button type="button" class="btn-check-db"
                    data-tables="stock_exports,finished_goods_inventory,transactions">
                    <i class="fa-solid fa-database"></i> Check Database
                </button>
            </div>
            <?php endif; ?>
        </div>
        <div class="content">
            <div class="wp-toolbar">
                <div class="wp-search">
                    <i class="fa-solid fa-magnifying-glass"></i>
                    <input type="text" id="search-product" name="search-product" placeholder="Tìm kiếm hàng hóa.." autocomplete="off">
                    <ul class="search-dropdown" id="search-dropdown"></ul>
                </div>

                <div class="wp-date-picker">
                    <label for="record-datetime">Ngày giờ ghi</label>
                    <input type="datetime-local" id="record-datetime" class="js-green-datetime js-green-datetime-highlight" step="1">
                </div>
            </div>

            <?php // TASK 4: chỉ nạp bút toán user chọn gần nhất; bấm "+ BÚT TOÁN KẾ TOÁN" để thêm.
            // save_mode='on_record': lưu nghiệp vụ lúc "Ghi" (insert-if-new), không auto-ghi-đè khi gõ.
            render_journal_entry_block('other_warehouse_outbound', 'je-card', true, 'latest', 'on_record', true); ?>

            <div class="edit-batch-banner" id="edit-batch-banner" style="display:none;">
                <span>Đang sửa nhóm: <strong id="edit-batch-label"></strong></span>
                <a href="#" id="cancel-edit-batch">Hủy</a>
            </div>

            <ul class="list-product" id="list-product"></ul>

            <div class="wp-button">
                <div class="btn-record" id="btn-record">
                    <p>Ghi</p>
                </div>
                <div class="btn-edit" id="btn-edit" style="display:none;">
                    <p>Sửa</p>
                </div>
            </div>

            <div class="history-wrap">
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
                                    <button type="button" class="th-filter-btn" id="hf-keyword-btn" title="Lọc theo sản phẩm đã xuất">
                                        <i class="fa-solid fa-filter"></i>
                                    </button>
                                    <div class="th-filter-pop" id="hf-keyword-pop">
                                        <input type="text" id="hf-keyword" placeholder="Lọc sản phẩm đã xuất..." autocomplete="off">
                                    </div>
                                </div>
                            </td>
                            <td>Thao tác</td>
                        </tr>
                    </thead>

                    <tbody id="history-tbody">
                    </tbody>
                </table>
                <div class="history-pagination" id="history-pagination"></div>
            </div>
        </div>
    </div>

    <script>
        window.INVENTORY_CONFIG = {
            baseUrl: '?mod=warehouse_outbound&controllers=warehouse_outbound&action='
        };
        window.INVENTORY_DATA = {
            planDate: <?php echo json_encode($plan_date ?? date('d/m/Y')); ?>,
            history: <?php echo json_encode($history ?? [], JSON_UNESCAPED_UNICODE); ?>,
            typeExport: <?php echo json_encode($type_export ?? 'other_export_outbound'); ?>
        };
    </script>
    <script src="<?php echo asset_ver('public/js/accounting/journal_entry.js'); ?>"></script>
    <script src="<?php echo asset_ver('public/js/warehouse_outbound/other_warehouse_outbound.js'); ?>"></script>


    <script src="<?php echo asset_ver('public/js/shared/check_database.js'); ?>"></script>
    <script src="<?php echo asset_ver('public/js/shared/datetime_picker.js'); ?>"></script>
    <script src="<?php echo asset_ver('public/js/shared/app_shell.js'); ?>"></script>
</body>

</html>
