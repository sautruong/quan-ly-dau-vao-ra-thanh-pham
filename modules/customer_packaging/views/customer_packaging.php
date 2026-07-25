<?php
/** Dữ liệu từ controller: $setups (danh sách thiết lập khách hàng+bao bì+sản phẩm). */
$setups = $setups ?? [];
?>
<!DOCTYPE html>
<html lang="vi">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>QL bao bì khách hàng</title>
    <link rel="icon" href="public/images/logo/logo_vat_png.png" type="image/png">
    <link rel="stylesheet" href="public/css/reset.css">
    <link rel="stylesheet" href="public/css/all.css">
    <link rel="stylesheet" href="public/css/global.css">
    <link rel="stylesheet" href="<?php echo asset_ver('public/css/shared/app_shell.css'); ?>">
    <link rel="stylesheet" href="public/css/customer_packaging/customer_packaging.css">
</head>

<body>
    <div id="wrapper" class="has-sider">
        <?php get_sidebar('app'); ?>
        <?php get_header('app'); ?>

        <div class="content cp-content">
            <div class="cp-grid">

                <!-- ============================================================ -->
                <!-- KHỐI 1: THIẾT LẬP KHÁCH HÀNG + BAO BÌ + SẢN PHẨM              -->
                <!-- ============================================================ -->
                <section class="cp-block">
                    <div class="cp-block-head">
                        <h2><i class="fa-solid fa-sliders"></i> Thiết lập bao bì khách hàng</h2>
                    </div>

                    <div class="cp-card cp-collapsible" id="cp-setup-faq">
                        <div class="cp-card-head cp-collapsible-head" id="cp-setup-faq-head">
                            <i class="fa-solid fa-box-open"></i> Thêm thiết lập mới
                            <i class="fa-solid fa-chevron-down cp-collapsible-toggle-ico"></i>
                        </div>
                        <div class="cp-collapsible-body" id="cp-setup-faq-body">
                            <div class="cp-field-row">
                                <div class="cp-field">
                                    <label>Tên khách hàng</label>
                                    <div class="cp-search-wrap">
                                        <i class="fa-solid fa-user cp-search-icon"></i>
                                        <input type="text" id="cp-setup-customer" class="cp-search-input" autocomplete="off"
                                            placeholder="Tự đặt tên khách hàng..." spellcheck="false">
                                        <ul class="cp-search-dropdown" id="cp-setup-customer-dropdown"></ul>
                                    </div>
                                </div>
                                <div class="cp-field">
                                    <label>Tên bao bì</label>
                                    <div class="cp-search-wrap">
                                        <i class="fa-solid fa-box cp-search-icon"></i>
                                        <input type="text" id="cp-setup-packaging" class="cp-search-input" autocomplete="off"
                                            placeholder="Tự đặt tên bao bì..." spellcheck="false">
                                        <ul class="cp-search-dropdown" id="cp-setup-packaging-dropdown"></ul>
                                    </div>
                                </div>
                            </div>
                            <div class="cp-field">
                                <label>Dùng cho sản phẩm</label>
                                <div class="cp-search-wrap">
                                    <i class="fa-solid fa-magnifying-glass cp-search-icon"></i>
                                    <input type="text" id="cp-setup-product" class="cp-search-input" autocomplete="off"
                                        placeholder="Tìm sản phẩm..." spellcheck="false">
                                    <ul class="cp-search-dropdown" id="cp-setup-product-dropdown"></ul>
                                </div>
                            </div>
                            <div class="cp-setup-actions">
                                <button type="button" class="cp-btn cp-btn-primary" id="cp-btn-save-setup">
                                    <i class="fa-solid fa-floppy-disk"></i> Thêm thiết lập
                                </button>
                            </div>
                        </div>
                    </div>

                    <div class="cp-card">
                        <div class="cp-card-head"><i class="fa-solid fa-list-check"></i> Danh sách đã thiết lập</div>
                        <div class="cp-setup-wrap">
                            <table class="cp-setup-table">
                                <thead>
                                    <tr>
                                        <th class="cp-st-customer">Khách hàng</th>
                                        <th class="cp-st-pkg">Bao bì</th>
                                        <th class="cp-st-prod">Sản phẩm</th>
                                        <th class="cp-st-act"></th>
                                    </tr>
                                </thead>
                                <tbody id="cp-setup-tbody"></tbody>
                            </table>
                        </div>
                    </div>
                </section>

                <!-- ============================================================ -->
                <!-- KHỐI 2: SỔ BAO BÌ THEO KHÁCH HÀNG                             -->
                <!-- ============================================================ -->
                <section class="cp-block">
                    <div class="cp-block-head">
                        <h2><i class="fa-solid fa-boxes-stacked"></i> Sổ bao bì theo khách hàng</h2>
                    </div>

                    <div class="cp-customer-search">
                        <div class="cp-search-wrap">
                            <i class="fa-solid fa-magnifying-glass cp-search-icon"></i>
                            <input type="text" id="cp-ledger-customer" class="cp-search-input" autocomplete="off"
                                placeholder="Chọn khách hàng để xem sổ bao bì..." spellcheck="false">
                            <ul class="cp-search-dropdown" id="cp-ledger-customer-dropdown"></ul>
                        </div>
                    </div>

                    <div class="cp-pkg-actions">
                        <button type="button" class="cp-tool-btn" id="cp-btn-receive" disabled>
                            <i class="fa-solid fa-truck-arrow-right"></i> Nhận bì từ khách
                        </button>
                        <button type="button" class="cp-tool-btn" id="cp-btn-usage" disabled>
                            <i class="fa-solid fa-box-open"></i> Xuất dùng cho SP
                        </button>
                        <button type="button" class="cp-tool-btn" id="cp-btn-loss" disabled>
                            <i class="fa-solid fa-circle-minus"></i> Hao hụt
                        </button>
                        <button type="button" class="cp-tool-btn" id="cp-btn-export" disabled
                            data-export-excel data-export-target="#cp-ledger-table">
                            <i class="fa-solid fa-file-excel"></i> Xuất Excel
                        </button>
                    </div>

                    <div class="cp-card">
                        <div class="cp-ledger-wrap cp-ledger-scroll">
                            <table class="cp-ledger-table" id="cp-ledger-table">
                                <thead>
                                    <tr>
                                        <th class="cp-lg-date">Ngày</th>
                                        <th class="cp-lg-name">Tên bao bì</th>
                                        <th class="cp-lg-content">Nội dung</th>
                                        <th class="cp-lg-qty">Số lượng</th>
                                        <th class="cp-lg-act"></th>
                                    </tr>
                                </thead>
                                <tbody id="cp-ledger-tbody">
                                    <tr class="cp-lg-empty"><td colspan="5">Chọn khách hàng để xem sổ bao bì.</td></tr>
                                </tbody>
                            </table>
                        </div>
                        <div class="cp-ledger-total" id="cp-ledger-total" style="display:none;"></div>
                        <div class="cp-mat-pager" id="cp-ledger-pager" style="display:none;">
                            <div class="cp-pager-size">
                                <label for="cp-ledger-page-size">Hiển thị</label>
                                <select id="cp-ledger-page-size">
                                    <option value="10">10</option>
                                    <option value="20" selected>20</option>
                                    <option value="50">50</option>
                                    <option value="0">Tất cả</option>
                                </select>
                                <span>dòng</span>
                            </div>
                            <div class="cp-pager-nav">
                                <button type="button" class="cp-pager-btn" id="cp-ledger-page-prev" title="Trang trước">
                                    <i class="fa-solid fa-chevron-left"></i>
                                </button>
                                <span class="cp-pager-info" id="cp-ledger-page-info">1/1</span>
                                <button type="button" class="cp-pager-btn" id="cp-ledger-page-next" title="Trang sau">
                                    <i class="fa-solid fa-chevron-right"></i>
                                </button>
                            </div>
                        </div>
                    </div>
                </section>

            </div>
        </div>
    </div>

    <!-- MODAL: Nghiệp vụ bao bì (nhận / xuất dùng / hao hụt) -->
    <div class="cp-modal-mask" id="cp-modal-entry" style="display:none;">
        <div class="cp-modal-box">
            <span class="cp-modal-close" data-close="cp-modal-entry">&times;</span>
            <h3 class="cp-modal-title"><i class="fa-solid fa-box"></i> <span id="cp-em-title">Nghiệp vụ bao bì</span></h3>
            <p class="cp-modal-hint" id="cp-em-hint"></p>

            <div class="cp-field">
                <label>Loại bao bì</label>
                <div class="cp-search-wrap">
                    <i class="fa-solid fa-box cp-search-icon"></i>
                    <input type="text" id="cp-em-packaging" class="cp-search-input" autocomplete="off"
                        placeholder="Chọn hoặc tự đặt tên bao bì..." spellcheck="false">
                    <ul class="cp-search-dropdown" id="cp-em-packaging-dropdown"></ul>
                </div>
            </div>

            <div class="cp-field" id="cp-em-product-field" style="display:none;">
                <label>Sản phẩm</label>
                <div class="cp-search-wrap">
                    <i class="fa-solid fa-magnifying-glass cp-search-icon"></i>
                    <input type="text" id="cp-em-product" class="cp-search-input" autocomplete="off"
                        placeholder="Tìm sản phẩm..." spellcheck="false">
                    <ul class="cp-search-dropdown" id="cp-em-product-dropdown"></ul>
                </div>
            </div>

            <div class="cp-field" id="cp-em-reason-field" style="display:none;">
                <label>Lý do hao hụt</label>
                <input type="text" id="cp-em-reason" class="cp-input" placeholder="VD: rách bao, thất lạc...">
            </div>

            <div class="cp-field-row">
                <div class="cp-field">
                    <label>Số lượng</label>
                    <input type="text" id="cp-em-qty" class="cp-input" inputmode="decimal" value="">
                </div>
                <div class="cp-field">
                    <label>Ngày</label>
                    <input type="date" id="cp-em-date" class="cp-input">
                </div>
            </div>

            <div class="cp-modal-foot">
                <button type="button" class="cp-btn cp-btn-primary" id="cp-em-apply">
                    <i class="fa-solid fa-check"></i> Ghi sổ
                </button>
            </div>
        </div>
    </div>

    <script>
        window.CP_CONFIG = {
            baseUrl: '?mod=customer_packaging&controllers=customer_packaging&action=',
            setups: <?php echo json_encode($setups, JSON_UNESCAPED_UNICODE); ?>
        };
    </script>
    <script src="public/js/customer_packaging/customer_packaging.js"></script>
    <script src="public/js/admin_factory/data_export_excel.js"></script>
    <script src="<?php echo asset_ver('public/js/shared/app_shell.js'); ?>"></script>
</body>

</html>
