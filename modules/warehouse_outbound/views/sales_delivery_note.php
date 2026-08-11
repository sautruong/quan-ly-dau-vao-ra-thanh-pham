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
                    <li class="tab-item active">
                        <a href="?mod=warehouse_outbound&controllers=warehouse_outbound&action=sales_delivery_note">Xuất kho bán hàng</a>
                    </li>
                    <li class="tab-item">
                        <a href="?mod=warehouse_outbound&controllers=warehouse_outbound&action=other_warehouse_outbound">Xuất kho khác</a>
                    </li>
                    <li class="tab-item">
                        <a href="?mod=warehouse_outbound&controllers=warehouse_outbound&action=">Xuất kho nguyên vật liệu dùng sản xuất</a>
                    </li>
                </ul>
            </nav>
            <?php if (permission_can_check_db('warehouse_outbound', 'warehouse_outbound', 'sales_delivery_note')): ?>
            <div class="cdb-actions">
                <button type="button" class="btn-check-db"
                    data-tables="stock_exports,sales_inventory_issue_data,sales_warehouse_export_invoices,finished_goods_inventory,transactions">
                    <i class="fa-solid fa-database"></i> Check Database
                </button>
            </div>
            <?php endif; ?>
        </div>
        <div class="content">
            <div class="wp-top-row">
                <div class="wp-custom-imfo">
                    <div class="wp-customer">
                        <label for="customer">Tên khách hàng:</label>
                        <input type="text" id="customer" autocomplete="off" placeholder="Tìm theo tên khách hàng...">
                        <ul class="customer-dropdown" id="customer-dropdown"></ul>
                    </div>
                    <div class="wp-address">
                        <label for="address">Địa chỉ:</label>
                        <input type="text" id="address" readonly placeholder="(tự động hiển thị)">
                    </div>
                    <div class="wp-receiver">
                        <label for="receiver">Người nhận:</label>
                        <input type="text" id="receiver" readonly placeholder="(tự động hiển thị)">
                    </div>
                </div>
                <div class="wp-date-col">
                    <div class="wp-date-picker">
                        <label for="record-datetime">Ngày giờ ghi</label>
                        <input type="datetime-local" id="record-datetime" class="js-green-datetime js-green-datetime-highlight" step="1">
                    </div>
                    <!-- Lời nhắc "trước bốc hàng" (Điểm nhắc) áp dụng cho khách hàng vừa chọn -->
                    <div class="wp-branch-reminder" id="wp-branch-reminder" style="display:none;"></div>
                </div>
            </div>

            <div class="edit-batch-banner" id="edit-batch-banner" style="display:none;">
                <span>Đang sửa nhóm: <strong id="edit-batch-label"></strong></span>
                <a href="#" id="cancel-edit-batch">Hủy</a>
            </div>

            <?php render_journal_entry_block('sales_delivery_note', 'wp-journal-entry', true, 'all', 'auto', true); ?>

            <div class="list-sale-product">
                <table>
                    <thead>
                        <tr>
                            <td class="cell-stt">STT</td>
                            <td>Tên hàng hóa</td>
                            <td>Số lượng</td>
                            <td>Đơn vị</td>
                            <td>Kho</td>
                            <td>Khối lượng</td>
                            <td>Tổng khối lượng</td>
                            <td>Đơn giá</td>
                            <td>Thành tiền</td>
                            <td></td>
                        </tr>
                    </thead>
                    <tbody id="sale-tbody">
                    </tbody>
                </table>
                <?php /*
                  MOBILE: nút thêm dòng thủ công. Trên máy tính dòng mới tự sinh khi gõ xong dòng
                  cuối (ensureEmptyRow trong sales_issue.js), nhưng trên điện thoại luồng nhập
                  bị bàn phím ảo che và dòng trống lại bị dọn khi rời ô — nên cần nút bấm rõ ràng.
                  CSS ẩn nút này ở desktop.
                */ ?>
                <button type="button" class="btn-add-sale-row" id="btn-add-sale-row">
                    <i class="fa-solid fa-plus"></i> Thêm dòng
                </button>
                <div class="wp-total">
                    <div class="wp-weight">
                        <div class="label">Tổng khối lượng:</div>
                        <div class="result">
                            <input type="text" class="weight-total-input" id="total-weight-input"
                                   inputmode="decimal" value="0" title="Có thể chỉnh tay trước khi lưu"
                                   style="width:90px;text-align:right;font:inherit;font-weight:600;color:inherit;border:1px solid #c2c8d0;border-radius:5px;padding:2px 6px;background:#fff;"> kg
                        </div>
                    </div>
                    <div class="wp-value">
                        <div class="label">Giá trị hàng hóa:</div>
                        <div class="result">197,000,000 đ</div>
                    </div>
                </div>
            </div>
            <div class="wp-button">
                <div class="btn-record" id="btn-record">
                    <p>Ghi</p>
                </div>
                <div class="btn-edit" id="btn-edit" style="display:none">
                    <p>Sửa</p>
                </div>
                <div class="btn-print" id="btn-print">
                    <i class="fa-solid fa-print"></i>
                    <p>In đơn</p>
                </div>
            </div>

            <!-- Modal in phiếu xuất (Phiếu Xuất Kho Kiêm Vận Chuyển Nội Bộ) -->
            <div class="print-modal" id="print-modal" style="display:none;">
                <div class="print-modal-overlay" id="print-modal-overlay"></div>
                <div class="print-modal-content">
                    <div class="print-modal-toolbar no-print">
                        <button type="button" id="print-modal-do" class="btn-do-print">In</button>
                        <button type="button" id="print-modal-share-zalo" class="btn-share-zalo">Share Zalo</button>
                        <button type="button" id="print-modal-close" class="btn-close-print">Đóng</button>
                    </div>
                    <div class="print-sheet" id="print-sheet"></div>
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

    <script>
        window.INVENTORY_CONFIG = {
            baseUrl: '?mod=inventory_management&controllers=inventory_management&action='
        };
        window.INVENTORY_DATA = {
            planDate: <?php echo json_encode($plan_date ?? date('d/m/Y')); ?>,
            history: <?php echo json_encode($history ?? [], JSON_UNESCAPED_UNICODE); ?>,
            typeExport: <?php echo json_encode($type_export ?? 'sales_issue'); ?>
        };
        // Thông tin cố định in phiếu (sửa trực tiếp trên modal → lưu DB dùng dài lâu).
        window.PRINT_SETTINGS = <?php echo json_encode($print_settings ?? [], JSON_UNESCAPED_UNICODE); ?>;
    </script>
    <!-- html2canvas: chụp #print-sheet thành ảnh PNG để copy vào clipboard cho Share Zalo -->
    <script src="https://cdnjs.cloudflare.com/ajax/libs/html2canvas/1.4.1/html2canvas.min.js"></script>
    <script src="<?php echo asset_ver('public/js/accounting/journal_entry.js'); ?>"></script>
    <script src="<?php echo asset_ver('public/js/inventory_management/sales_issue.js'); ?>"></script>
    <script src="<?php echo asset_ver('public/js/shared/datetime_picker.js'); ?>"></script>
    <script src="<?php echo asset_ver('public/js/shared/check_database.js'); ?>"></script>
    <script src="<?php echo asset_ver('public/js/shared/app_shell.js'); ?>"></script>
</body>

</html>