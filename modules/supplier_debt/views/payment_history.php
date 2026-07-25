<!DOCTYPE html>
<html lang="vi">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Ghi thanh toán — Công nợ nhà cung cấp</title>
    <link rel="icon" href="public/images/logo/logo_vat_png.png" type="image/png">
    <link rel="stylesheet" href="public/css/reset.css">
    <link rel="stylesheet" href="public/css/all.css">
    <link rel="stylesheet" href="public/css/global.css">
    <link rel="stylesheet" href="public/css/inventory_management/dashboard.css">
    <link rel="stylesheet" href="public/css/shared/invoice_upload.css">
    <link rel="stylesheet" href="public/css/inventory_receiving/print_receipt.css">
    <link rel="stylesheet" href="<?php echo asset_ver('public/css/shared/app_shell.css'); ?>">
    <link rel="stylesheet" href="public/css/shared/datetime_picker.css">
    <link rel="stylesheet" href="public/css/shared/history_filter.css">
    <link rel="stylesheet" href="public/css/supplier_debt/payment_history.css">
</head>

<body>
    <div id="wrapper" class="has-sider">
        <?php get_sidebar('app'); ?>
        <?php get_header('app'); ?>

        <div class="content">
            <!-- ============ PHẦN 1: NHẬP DỮ LIỆU ============ -->
            <div class="sd-form-card">
                <div class="sd-form-grid">
                    <div class="sd-field">
                        <label for="record-datetime">Ngày giờ ghi</label>
                        <input type="datetime-local" id="record-datetime" class="js-green-datetime" step="1">
                    </div>
                    <div class="sd-field">
                        <label for="search-suppliers">Tên nhà cung cấp</label>
                        <div class="wp-search">
                            <i class="fa-solid fa-magnifying-glass"></i>
                            <input type="text" id="search-suppliers" name="search-suppliers"
                                placeholder="Tìm / chọn nhà cung cấp..." autocomplete="off">
                            <ul class="search-dropdown" id="search-dropdown"></ul>
                        </div>
                    </div>
                    <div class="sd-field">
                        <label for="sd-amount">Giá trị</label>
                        <input type="text" id="sd-amount" class="sd-input-amount" inputmode="numeric"
                            placeholder="0" autocomplete="off">
                    </div>
                </div>

                <div class="sd-field sd-field-full">
                    <label for="sd-note">Diễn giải</label>
                    <textarea id="sd-note" class="sd-textarea" rows="2"
                        placeholder="Nhập diễn giải..."></textarea>
                </div>

                <div class="sd-field sd-field-full">
                    <label>Hình ảnh đính kèm <span class="sd-hint">(click vùng rồi Ctrl+V để dán, hoặc kéo thả / chọn tệp)</span></label>
                    <div class="wp-invoice-upload" id="invoice-upload">
                        <button type="button" class="iu-saved-badge" id="iu-saved-badge" style="display:none;"
                            title="Ảnh đã lưu — bấm để xem">
                            <i class="fa-solid fa-receipt"></i> <span class="iu-saved-count">0</span>
                        </button>
                        <div class="iu-dropzone">
                            <i class="fa-solid fa-paste"></i>
                            <p>Dán ảnh (Ctrl+V) hoặc kéo thả vào đây</p>
                            <button type="button" class="iu-btn-choose">Chọn tệp</button>
                            <input type="file" class="iu-input" multiple accept="image/*,.pdf" hidden>
                        </div>
                        <div class="iu-preview"></div>
                        <div class="iu-saved-list" id="iu-saved-list" style="display:none;"></div>
                    </div>
                </div>

                <div class="sd-actions">
                    <button type="button" class="sd-btn-create" id="btn-create">
                        <i class="fa-solid fa-file-invoice-dollar"></i> Tạo phiếu chi
                    </button>
                    <button type="button" class="sd-btn-record" id="btn-ghi">
                        <i class="fa-solid fa-floppy-disk"></i> Ghi
                    </button>
                    <button type="button" class="sd-btn-update" id="btn-update" style="display:none;">
                        <i class="fa-solid fa-floppy-disk"></i> Cập nhật phiếu chi
                    </button>
                    <div class="edit-batch-banner" id="edit-batch-banner" style="display:none;">
                        <span>Đang sửa phiếu: <strong id="edit-batch-label"></strong></span>
                        <a href="#" id="cancel-edit-batch">Hủy</a>
                    </div>
                </div>
            </div>

            <!-- ============ LỊCH SỬ THANH TOÁN ============ -->
            <div class="sd-history-card">
                <div class="sd-history-head">
                    <h3>Ghi thanh toán</h3>
                    <div class="sd-history-tools">
                        <div class="history-filter" id="history-filter">
                            <div class="hf-group hf-daterange">
                                <span class="hf-cal-icon"><i class="fa-regular fa-calendar-days"></i></span>
                                <label for="hf-date-from">Từ ngày</label>
                                <input type="date" id="hf-date-from" class="hf-date">
                                <span class="hf-arrow"><i class="fa-solid fa-arrow-right-long"></i></span>
                                <label for="hf-date-to">đến ngày</label>
                                <input type="date" id="hf-date-to" class="hf-date">
                            </div>
                        </div>
                        <label class="sd-perpage">
                            Hiển thị
                            <select id="per-page">
                                <option value="5">5</option>
                                <option value="10">10</option>
                                <option value="20">20</option>
                                <option value="50">50</option>
                                <option value="100">100</option>
                            </select>
                            dòng
                        </label>
                    </div>
                </div>

                <table class="sd-table" id="history-table">
                    <thead>
                        <tr>
                            <th style="width:18%">Ngày</th>
                            <th>
                                <div class="th-filterable">
                                    <span>Nhà cung cấp</span>
                                    <button type="button" class="th-filter-btn" id="sd-supplier-funnel" title="Lọc theo nhà cung cấp">
                                        <i class="fa-solid fa-filter"></i>
                                    </button>
                                    <div class="th-filter-pop" id="sd-supplier-pop">
                                        <div class="wp-search sd-filter-search">
                                            <i class="fa-solid fa-magnifying-glass"></i>
                                            <input type="text" id="filter-supplier" placeholder="Lọc theo nhà cung cấp..." autocomplete="off">
                                            <ul class="search-dropdown" id="filter-dropdown"></ul>
                                            <button type="button" class="sd-filter-clear" id="filter-clear" style="display:none;" title="Bỏ lọc">&times;</button>
                                        </div>
                                    </div>
                                </div>
                            </th>
                            <th class="num" style="width:18%">Số tiền</th>
                            <th style="width:10%" class="center">Đính kèm</th>
                            <th style="width:16%" class="center">Thao tác</th>
                        </tr>
                    </thead>
                    <tbody id="history-tbody"></tbody>
                </table>
                <div class="sd-pagination" id="history-pagination"></div>
            </div>

            <!-- ============ MODAL: PHIẾU CHI (in / share) ============ -->
            <div class="print-modal" id="print-modal" style="display:none;">
                <div class="print-modal-overlay" id="print-modal-overlay"></div>
                <div class="print-modal-content">
                    <div class="print-modal-toolbar no-print">
                        <button type="button" id="print-modal-signcfg" class="btn-sign-cfg" title="Cài đặt vùng ký">
                            <i class="fa-solid fa-signature"></i> Vùng ký
                        </button>
                        <button type="button" id="print-modal-do" class="btn-do-print">In</button>
                        <button type="button" id="print-modal-share" class="btn-share">Share</button>
                        <button type="button" id="print-modal-close" class="btn-close-print">Đóng</button>
                    </div>
                    <div class="print-sheet" id="print-sheet"></div>
                </div>
            </div>

            <!-- ============ MODAL: Cài đặt vùng ký (chức danh phía công ty) ============ -->
            <div class="sd-modal" id="sign-setting-modal" style="display:none;">
                <div class="sd-modal-overlay" id="sign-setting-overlay"></div>
                <div class="sd-modal-content sd-modal-signcfg">
                    <div class="sd-modal-head">
                        <h3>Cài đặt vùng ký</h3>
                        <button type="button" class="sd-modal-close" id="sign-setting-close">&times;</button>
                    </div>
                    <div class="sd-modal-body">
                        <p class="sd-signcfg-hint">Tích chọn chức danh để hiển thị cụm chữ ký trên phiếu chi. Có thể đổi tên, xóa, hoặc thêm chức danh mới. Trên phiếu, kéo-thả các cụm chữ ký để đổi vị trí.</p>
                        <ul class="sd-signcfg-list" id="sign-setting-list"></ul>
                        <div class="sd-signcfg-addrow">
                            <input type="text" id="sign-setting-newrole" autocomplete="off" placeholder="Thêm chức danh mới...">
                            <button type="button" class="sd-btn-create" id="sign-setting-addbtn"><i class="fa-solid fa-plus"></i> Thêm</button>
                        </div>
                    </div>
                </div>
            </div>

            <!-- ============ MODAL: Xem ảnh đính kèm của 1 phiếu ============ -->
            <div class="sd-modal" id="att-modal" style="display:none;">
                <div class="sd-modal-overlay" id="att-overlay"></div>
                <div class="sd-modal-content sd-modal-att">
                    <div class="sd-modal-head">
                        <h3 id="att-title">Ảnh thanh toán</h3>
                        <button type="button" class="sd-modal-close" id="att-close">&times;</button>
                    </div>
                    <div class="sd-modal-body">
                        <div class="sd-att-grid" id="att-grid"></div>
                    </div>
                </div>
            </div>

            <!-- ============ MODAL: Các lần mua của 1 NCC ============ -->
            <div class="sd-modal" id="purchases-modal" style="display:none;">
                <div class="sd-modal-overlay" id="purchases-overlay"></div>
                <div class="sd-modal-content">
                    <div class="sd-modal-head">
                        <h3 id="purchases-title">Lịch sử mua hàng</h3>
                        <button type="button" class="sd-modal-close" id="purchases-close">&times;</button>
                    </div>
                    <div class="sd-modal-body">
                        <div class="sd-modal-tools">
                            <label class="sd-perpage">
                                Hiển thị
                                <select id="purchases-per-page">
                                    <option value="5">5</option>
                                    <option value="10">10</option>
                                    <option value="20">20</option>
                                    <option value="50">50</option>
                                </select>
                                dòng
                            </label>
                        </div>
                        <table class="sd-table">
                            <thead>
                                <tr>
                                    <th style="width:22%">Ngày</th>
                                    <th>Loại</th>
                                    <th style="width:24%" class="num">Giá trị đơn</th>
                                    <th style="width:20%" class="num">CPMH</th>
                                </tr>
                            </thead>
                            <tbody id="purchases-tbody"></tbody>
                        </table>
                        <div class="sd-pagination" id="purchases-pagination"></div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <script>
        window.SD_CONFIG = {
            baseUrl: '?mod=supplier_debt&controllers=supplier_debt&action='
        };
        window.SD_DATA = {
            history: <?php echo json_encode($history ?? [], JSON_UNESCAPED_UNICODE); ?>,
            historyTotal: <?php echo (int) ($history_total ?? 0); ?>
        };
        window.PRINT_SETTINGS = <?php echo json_encode($print_settings ?? [], JSON_UNESCAPED_UNICODE); ?>;
        window.SIGN_CONFIG = <?php echo json_encode($sign_config ?? ['sign_roles' => [], 'signs' => []], JSON_UNESCAPED_UNICODE); ?>;
    </script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/html2canvas/1.4.1/html2canvas.min.js"></script>
    <script src="public/js/shared/invoice_dropzone.js"></script>
    <script src="public/js/supplier_debt/payment_history.js"></script>
    <script src="public/js/shared/check_database.js"></script>
    <script src="public/js/shared/datetime_picker.js"></script>
    <script src="<?php echo asset_ver('public/js/shared/app_shell.js'); ?>"></script>
</body>

</html>
