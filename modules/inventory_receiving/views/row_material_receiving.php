<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Nhập mua hàng hóa</title>
    <link rel="icon" href="public/images/logo/logo_vat_png.png" type="image/png">
    <link rel="stylesheet" href="public/css/reset.css">
    <link rel="stylesheet" href="public/css/all.css">
    <link rel="stylesheet" href="public/css/global.css">
    <link rel="stylesheet" href="public/css/inventory_management/dashboard.css">
    <link rel="stylesheet" href="public/css/inventory_receiving/row_material_receiving.css">
    <link rel="stylesheet" href="public/css/accounting/journal_entry.css">
    <link rel="stylesheet" href="public/css/shared/invoice_upload.css">
    <link rel="stylesheet" href="public/css/inventory_receiving/print_receipt.css">

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
                    <li class="tab-item active">
                        <a href="?mod=inventory_receiving&controllers=inventory_receiving&action=row_material_receiving">Nhập mua hàng hóa</a>
                    </li>
                    <li class="tab-item">
                        <a href="?mod=inventory_receiving&controllers=inventory_receiving&action=other_row_material_receiving">Nhập NVL (khác)</a>
                    </li>

                </ul>
            </nav>
            <?php if (!permission_is_view_only('inventory_receiving', 'inventory_receiving', 'row_material_receiving')): ?>
            <div class="cdb-actions">
                <button type="button" class="btn-check-db"
                    data-tables="stock_imports,stock_import_invoices,warehouse_receipts,stock_import_purchase_costs,raw_material_purchase_data,material_inventory,material_purchase_prices,purchase_price_changes,material_information,products,finished_goods_inventory,product_purchase_prices,purchased_finished_product_data,transactions,accounting_transaction_mapping,accounting_transaction_entry">
                    <i class="fa-solid fa-database"></i> Check Database
                </button>
            </div>
            <?php endif; ?>
        </div>

        <div class="content">
            <div class="rmr-top">
                <!-- Cụm 1: tìm/chọn nhà cung cấp + ngày giờ ghi (canh trái) -->
                <div class="rmr-cluster1">
                    <div class="wp-search">
                        <i class="fa-solid fa-magnifying-glass"></i>
                        <input type="text" id="search-suppliers" name="search-suppliers" placeholder="Tìm / chọn nhà cung cấp..." autocomplete="off">
                        <ul class="search-dropdown" id="search-dropdown"></ul>
                    </div>
                    <div class="wp-date-picker">
                        <label for="record-datetime">Ngày giờ ghi</label>
                        <input type="datetime-local" id="record-datetime" class="js-green-datetime js-green-datetime-highlight" step="1">
                    </div>
                </div>

                <!-- Hóa đơn mua hàng: đưa lên trên, canh phải, ngang hàng cụm 1 -->
                <div class="wp-invoice-upload" id="invoice-upload">
                    <!-- Badge số hóa đơn đã lưu (hiện khi Sửa); click để bung danh sách xem -->
                    <button type="button" class="iu-saved-badge" id="iu-saved-badge" style="display:none;" title="Hóa đơn đã lưu — bấm để xem">
                        <i class="fa-solid fa-receipt"></i> <span class="iu-saved-count">0</span>
                    </button>
                    <div class="iu-dropzone">
                        <i class="fa-solid fa-paste"></i>
                        <p>Copy HDMH và dán vào đây</p>
                        <button type="button" class="iu-btn-choose">Chọn tệp</button>
                        <input type="file" class="iu-input" multiple accept="image/*,.pdf" hidden>
                    </div>
                    <div class="iu-preview"></div>
                    <div class="iu-saved-list" id="iu-saved-list" style="display:none;"></div>
                </div>
            </div>

            <div class="edit-batch-banner" id="edit-batch-banner" style="display:none;">
                <span>Đang sửa nhóm: <strong id="edit-batch-label"></strong></span>
                <a href="#" id="cancel-edit-batch">Hủy</a>
            </div>

            <?php render_journal_entry_block('row_material_receiving', 'je-card', true, 'all', 'auto', true); ?>

            <ul class="list-supplier" id="list-supplier"></ul>

            <div class="wp-list-material">
                <table>
                    <thead>
                        <tr>
                            <th>Tên hàng hóa</th>
                            <th>Kho</th>
                            <th>Đơn vị</th>
                            <th>Số lượng</th>
                            <th>Đơn giá</th>
                            <th>Thành tiền</th>
                            <th>VAT (%)</th>
                            <th>Giá VAT</th>
                            <th>CP khác</th>
                            <th>Tổng GTNK</th>
                        </tr>
                    </thead>
                    <tbody id="material-tbody"></tbody>
                </table>
                <div class="total">
                    <div class="btn-add-row" id="btn-add-row">
                        <i class="fa-solid fa-plus"></i>
                        <span>Thêm dòng</span>
                    </div>
                    <div class="total-summary">
                        <div class="total-purchase-cost">
                            <p>Tổng CPMH:</p>
                            <span class="result">0 đ</span>
                        </div>
                        <div class="total-value-bill">
                            <p>Tổng giá trị đơn hàng:</p>
                            <span class="result">0 đ</span>
                        </div>
                    </div>
                </div>
            </div>
            <div class="wp-button">
                <div class="btn-record" id="btn-record">
                    <p>Ghi</p>
                </div>
                <div class="btn-edit" id="btn-edit">
                    <p>Sửa</p>
                </div>
                <div class="btn-print-wrap" id="btn-print-wrap">
                    <div class="btn-print" id="btn-print" title="In phiếu nhập kho">
                        <i class="fa-solid fa-print"></i>
                        <p>In</p>
                        <i class="fa-solid fa-caret-down btn-print-caret"></i>
                    </div>
                    <ul class="btn-print-menu" id="btn-print-menu">
                        <li data-print-mode="plain"><i class="fa-solid fa-file-lines"></i> Phiếu nhập kho</li>
                        <li data-print-mode="analysis"><i class="fa-solid fa-chart-column"></i> Phiếu nhập kho kèm phân tích</li>
                    </ul>
                </div>
            </div>

            <!-- Modal in PHIẾU NHẬP KHO (A4, tự phân trang). Nội dung render bằng JS. -->
            <div class="print-modal" id="print-modal" style="display:none;">
                <div class="print-modal-overlay" id="print-modal-overlay"></div>
                <div class="print-modal-content">
                    <div class="print-modal-toolbar no-print">
                        <button type="button" id="print-modal-do" class="btn-do-print">In</button>
                        <button type="button" id="print-modal-share" class="btn-share">Share</button>
                        <button type="button" id="print-modal-close" class="btn-close-print">Đóng</button>
                    </div>
                    <div class="print-sheet" id="print-sheet"></div>
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
                                <button type="button" class="btn-remove-row" title="Xóa dòng">&times;</button>
                                <input type="text" class="cell-input cell-name" autocomplete="off" placeholder="Nhập tên hàng hóa">
                                <ul class="material-dropdown"></ul>
                            </div>
                        </td>
                        <td class="cell-fixed cell-warehouse">—</td>
                        <td><input type="text" class="cell-input cell-unit" autocomplete="off" placeholder="đv"></td>
                        <td><input type="text" class="cell-input cell-quantity" value="0" inputmode="decimal"></td>
                        <td><input type="text" class="cell-input cell-price" value="0" inputmode="decimal"></td>
                        <td class="cell-fixed cell-amount">0</td>
                        <td>
                            <div class="cell-vat-wrap">
                                <input type="text" class="cell-input cell-vat" value="0" inputmode="numeric">
                                <label class="cell-vat-incl" title="Tính VAT luôn trên CP khác">
                                    <input type="checkbox" class="cell-vat-incl-check">
                                    <span>Gồm CP khác</span>
                                </label>
                            </div>
                        </td>
                        <td class="cell-fixed cell-vat-amount">0</td>
                        <td>
                            <div class="cell-other-cost-wrap">
                                <input type="text" class="cell-input cell-other-cost" value="0" inputmode="numeric">
                                <i class="fa-solid fa-comment-dots cell-note-icon" title="Giải thích CP khác"></i>
                                <div class="cell-note-pop">
                                    <textarea class="cell-note-input" rows="3" placeholder="VD: gia công đóng gói, phí shipper..."></textarea>
                                </div>
                            </div>
                        </td>
                        <td class="cell-fixed cell-total">0</td>
                    </tr>
                </template>
            </div>
        </div>
    </div>

    <script>
        window.INVENTORY_CONFIG = {
            baseUrl: '?mod=inventory_receiving&controllers=inventory_receiving&action='
        };
        // Bộ lọc lịch sử chạy client-side trên TOÀN BỘ history đã nạp sẵn (không gọi
        // thêm endpoint). Nạp full bằng cách tái dùng model với per_page = tổng số batch.
        window.INVENTORY_DATA = {
            history:     <?php echo json_encode(ir_get_history_page(1, max(1, (int) ($history_total ?? 0))), JSON_UNESCAPED_UNICODE); ?>,
            historyTotal:<?php echo json_encode($history_total ?? 0); ?>,
            typeImport:  'row_material_receiving'
        };
        // Thông tin cố định in phiếu (sửa trực tiếp trên modal → lưu lại dùng dài lâu).
        window.PRINT_SETTINGS = <?php echo json_encode($print_settings ?? [], JSON_UNESCAPED_UNICODE); ?>;
    </script>
    <!-- html2canvas: chụp #print-sheet thành PNG để Share (Ctrl+V sang app khác). -->
    <script src="https://cdnjs.cloudflare.com/ajax/libs/html2canvas/1.4.1/html2canvas.min.js"></script>
    <script src="public/js/accounting/journal_entry.js"></script>
    <script src="public/js/shared/invoice_dropzone.js"></script>
    <script src="public/js/inventory_receiving/row_material_receiving.js"></script>
    <script src="public/js/shared/check_database.js"></script>
    <script src="public/js/shared/datetime_picker.js"></script>
    <script src="<?php echo asset_ver('public/js/shared/app_shell.js'); ?>"></script>
</body>

</html>
