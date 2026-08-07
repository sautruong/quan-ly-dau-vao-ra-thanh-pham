<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Nhập NVL khác</title>
    <link rel="icon" href="public/images/logo/logo_vat_png.png" type="image/png">
    <link rel="stylesheet" href="<?php echo asset_ver('public/css/reset.css'); ?>">
    <link rel="stylesheet" href="<?php echo asset_ver('public/css/all.css'); ?>">
    <link rel="stylesheet" href="<?php echo asset_ver('public/css/global.css'); ?>">
    <link rel="stylesheet" href="<?php echo asset_ver('public/css/inventory_management/dashboard.css'); ?>">
    <link rel="stylesheet" href="<?php echo asset_ver('public/css/inventory_receiving/row_material_receiving.css'); ?>">
    <link rel="stylesheet" href="<?php echo asset_ver('public/css/inventory_receiving/other_row_material_receiving.css'); ?>">
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
                    <h1>NHẬP KHO</h1>
                </div>
                <label for="menu-toggle" class="menu-toggle-btn" aria-label="Mở menu">
                    <i class="fa-solid fa-bars"></i>
                </label>
            </div>
            <nav>
                <ul class="main-tab">
                    <li class="tab-item">
                        <a href="<?php echo nav_url('inventory_management', 'inventory_management', 'dashboard'); ?>">Nhập thành phẩm sản xuất</a>
                    </li>
                    <li class="tab-item">
                        <a href="<?php echo nav_url('inventory_management', 'inventory_management', 'investment_products'); ?>">Nhập giá vốn sản xuất</a>
                    </li>
                    <li class="tab-item">
                        <a href="<?php echo nav_url('inventory_management', 'inventory_management', 'other_receipt'); ?>">Nhập kho khác</a>
                    </li>
                    <li class="tab-item">
                        <a href="<?php echo nav_url('inventory_management', 'inventory_management', 'sales_return_receipt'); ?>">Nhập hàng bán trả lại</a>
                    </li>
                    <li class="tab-item">
                        <a href="?mod=inventory_receiving&controllers=inventory_receiving&action=row_material_receiving">Nhập mua hàng hóa</a>
                    </li>
                    <li class="tab-item active">
                        <a href="?mod=inventory_receiving&controllers=inventory_receiving&action=other_row_material_receiving">Nhập NVL (khác)</a>
                    </li>

                </ul>
            </nav>
            <?php if (permission_can_check_db('inventory_receiving', 'inventory_receiving', 'other_row_material_receiving')): ?>
            <div class="cdb-actions">
                <button type="button" class="btn-check-db"
                    data-tables="stock_imports,warehouse_receipts,raw_material_purchase_data,material_inventory,material_information">
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
                <span>Đang sửa nhóm: <strong id="edit-batch-label"></strong></span>
                <a href="#" id="cancel-edit-batch">Hủy</a>
            </div>

            <?php render_journal_entry_block('other_row_material_receiving', 'je-card', true, 'none', 'auto', true); ?>
            <script>
                // Trang này: 1 cụm bút toán trống lúc tải (prefill_mode='none').
                // Khi user chọn nghiệp vụ, KHÔNG nạp "Giá trị" từ template — giá trị chỉ
                // được tính từ danh sách NVL nhập bên dưới bảng (recalcJeAmount).
                window.JE_CONFIG = window.JE_CONFIG || {};
                window.JE_CONFIG.amountFromItems = true;
            </script>

            <div class="wp-list-material wp-list-material-other">
                <div class="general_interpretation">
                    <input type="text" id="general-interpretation" placeholder="Nhập mô tả chung">
                </div>
                <table>
                    <thead>
                        <tr>
                            <th>Tên nguyên vật liệu</th>
                            <th>Đơn vị</th>
                            <th>Số lượng</th>
                            <th>Diễn giải</th>
                            <th class="col-action"></th>
                        </tr>
                    </thead>
                    <tbody id="material-tbody"></tbody>
                </table>
                <div class="total">
                    <div class="btn-add-row" id="btn-add-row">
                        <i class="fa-solid fa-plus"></i>
                        <span>Thêm dòng</span>
                    </div>
                </div>
            </div>
            <div class="wp-button">
                <div class="btn-record" id="btn-record">
                    <p>Ghi</p>
                </div>
                <div class="btn-edit" id="btn-edit" style="display:none;">
                    <p>Sửa</p>
                </div>
            </div>
            <!-- Cụm history + template nằm TRONG .content để cuộn dọc chung với form. -->
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
                                    <button type="button" class="th-filter-btn" id="hf-keyword-btn" title="Lọc theo NVL đã nhập">
                                        <i class="fa-solid fa-filter"></i>
                                    </button>
                                    <div class="th-filter-pop" id="hf-keyword-pop">
                                        <input type="text" id="hf-keyword" placeholder="Lọc NVL đã nhập..." autocomplete="off">
                                    </div>
                                </div>
                            </td>
                            <td>Thao tác</td>
                        </tr>
                    </thead>
                    <tbody id="history-tbody"></tbody>
                </table>
                <div class="history-pagination" id="history-pagination"></div>

                <template id="material-row-template">
                    <tr>
                        <td>
                            <div class="cell-name-wrap">
                                <input type="text" class="cell-input cell-name" autocomplete="off" placeholder="Nhập tên NVL...">
                                <ul class="material-dropdown"></ul>
                            </div>
                        </td>
                        <td><input type="text" class="cell-input cell-unit" autocomplete="off" placeholder="đv"></td>
                        <td><input type="text" class="cell-input cell-quantity" value="0" inputmode="decimal"></td>
                        <td><input type="text" class="cell-input cell-note" autocomplete="off" placeholder="Diễn giải..."></td>
                        <td class="cell-action">
                            <button type="button" class="btn-remove-row" title="Xóa dòng">&times;</button>
                        </td>
                    </tr>
                </template>
            </div>
        </div>
    </div>

    <script>
        window.INVENTORY_CONFIG = {
            baseUrl: '?mod=inventory_receiving&controllers=inventory_receiving&action='
        };
        window.INVENTORY_DATA = {
            history:     <?php echo json_encode($history ?? [], JSON_UNESCAPED_UNICODE); ?>,
            historyTotal:<?php echo json_encode($history_total ?? 0); ?>,
            typeImport:  'other_row_material_receiving'
        };
    </script>
    <script src="<?php echo asset_ver('public/js/accounting/journal_entry.js'); ?>"></script>
    <script src="<?php echo asset_ver('public/js/inventory_receiving/other_row_material_receiving.js'); ?>"></script>
    <script src="<?php echo asset_ver('public/js/shared/check_database.js'); ?>"></script>
    <script src="<?php echo asset_ver('public/js/shared/datetime_picker.js'); ?>"></script>
    <script src="<?php echo asset_ver('public/js/shared/app_shell.js'); ?>"></script>
</body>

</html>
