<?php
/** Dữ liệu từ controller: $groups (danh sách nhóm trà ủ hương). */
$groups = $groups ?? [];
?>
<!DOCTYPE html>
<html lang="vi">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>QL nhóm trà ủ hương</title>
    <link rel="icon" href="public/images/logo/logo_vat_png.png" type="image/png">
    <link rel="stylesheet" href="<?php echo asset_ver('public/css/reset.css'); ?>">
    <link rel="stylesheet" href="<?php echo asset_ver('public/css/all.css'); ?>">
    <link rel="stylesheet" href="<?php echo asset_ver('public/css/global.css'); ?>">
    <link rel="stylesheet" href="<?php echo asset_ver('public/css/shared/app_shell.css'); ?>">
    <link rel="stylesheet" href="<?php echo asset_ver('public/css/tea_scent_group/tea_scent_group.css'); ?>">
</head>

<body>
    <div id="wrapper" class="has-sider">
        <?php get_sidebar('app'); ?>
        <?php get_header('app'); ?>

        <div class="content tsg-content">
            <div class="tsg-grid">

                <!-- ============================================================ -->
                <!-- KHỐI 1: DANH SÁCH NHÓM TRÀ Ủ HƯƠNG                             -->
                <!-- ============================================================ -->
                <section class="tsg-block tsg-block-list">
                    <div class="tsg-block-head">
                        <h2><i class="fa-solid fa-mortar-pestle"></i> Nhóm trà ủ hương</h2>
                    </div>

                    <div class="tsg-card tsg-collapsible" id="tsg-add-faq">
                        <div class="tsg-card-head tsg-collapsible-head" id="tsg-add-faq-head">
                            <i class="fa-solid fa-plus"></i> Thêm nhóm mới
                            <i class="fa-solid fa-chevron-down tsg-collapsible-toggle-ico"></i>
                        </div>
                        <div class="tsg-collapsible-body" id="tsg-add-faq-body">
                            <div class="tsg-field">
                                <label>Nguyên liệu kiểm soát</label>
                                <div class="tsg-search-wrap">
                                    <i class="fa-solid fa-leaf tsg-search-icon"></i>
                                    <input type="text" id="tsg-add-material" class="tsg-search-input" autocomplete="off"
                                        placeholder="Tìm nguyên liệu..." spellcheck="false">
                                    <ul class="tsg-search-dropdown" id="tsg-add-material-dropdown"></ul>
                                </div>
                            </div>
                            <div class="tsg-field-row">
                                <div class="tsg-field">
                                    <label>Ngưỡng cảnh báo</label>
                                    <input type="text" id="tsg-add-threshold" class="tsg-input" inputmode="decimal" value="4">
                                </div>
                                <div class="tsg-field">
                                    <label>Ghi chú</label>
                                    <input type="text" id="tsg-add-note" class="tsg-input" placeholder="Không bắt buộc">
                                </div>
                            </div>

                            <div class="tsg-card" id="tsg-addsetup-card">
                                <div class="tsg-card-head"><i class="fa-solid fa-boxes-stacked"></i> Sản phẩm dùng nguyên liệu này</div>
                                <div class="tsg-field-row">
                                    <div class="tsg-field tsg-field-grow">
                                        <label>Sản phẩm</label>
                                        <div class="tsg-search-wrap">
                                            <i class="fa-solid fa-magnifying-glass tsg-search-icon"></i>
                                            <input type="text" id="tsg-addsetup-product" class="tsg-search-input" autocomplete="off"
                                                placeholder="Tìm sản phẩm..." spellcheck="false">
                                            <ul class="tsg-search-dropdown" id="tsg-addsetup-product-dropdown"></ul>
                                        </div>
                                    </div>
                                    <div class="tsg-field">
                                        <label>Tỉ lệ dùng (%)</label>
                                        <input type="text" id="tsg-addsetup-ratio" class="tsg-input" inputmode="decimal" placeholder="VD 50">
                                    </div>
                                    <div class="tsg-field tsg-field-btn">
                                        <button type="button" class="tsg-btn tsg-btn-primary" id="tsg-btn-add-addsetup">Thêm</button>
                                    </div>
                                </div>
                                <div class="tsg-setup-wrap">
                                    <table class="tsg-setup-table">
                                        <thead>
                                            <tr>
                                                <th>Sản phẩm</th>
                                                <th class="tsg-st-ratio">Tỉ lệ dùng</th>
                                                <th class="tsg-st-act"></th>
                                            </tr>
                                        </thead>
                                        <tbody id="tsg-addsetup-tbody"></tbody>
                                    </table>
                                </div>
                            </div>

                            <div class="tsg-setup-actions">
                                <button type="button" class="tsg-btn tsg-btn-primary" id="tsg-btn-add-group">
                                    <i class="fa-solid fa-floppy-disk"></i> Tạo nhóm
                                </button>
                            </div>
                        </div>
                    </div>

                    <div class="tsg-group-list" id="tsg-group-list"></div>
                </section>

                <!-- ============================================================ -->
                <!-- KHỐI 2: CHI TIẾT NHÓM                                          -->
                <!-- ============================================================ -->
                <section class="tsg-block tsg-block-detail" id="tsg-detail">
                    <div class="tsg-detail-empty" id="tsg-detail-empty">
                        <i class="fa-solid fa-hand-pointer"></i>
                        <p>Chọn 1 nhóm bên trái để xem chi tiết.</p>
                    </div>

                    <div class="tsg-detail-body" id="tsg-detail-body" style="display:none;">
                        <div class="tsg-block-head">
                            <h2><i class="fa-solid fa-layer-group"></i> <span id="tsg-detail-title">Chi tiết nhóm</span></h2>
                            <button type="button" class="tsg-btn tsg-btn-danger" id="tsg-btn-delete-group" title="Xóa nhóm">
                                <i class="fa-solid fa-trash"></i> Xóa nhóm
                            </button>
                        </div>

                        <div class="tsg-detail-summary" id="tsg-detail-summary"></div>

                        <div class="tsg-card">
                            <div class="tsg-card-head"><i class="fa-solid fa-bell"></i> Ngưỡng cảnh báo</div>
                            <div class="tsg-field-row">
                                <div class="tsg-field">
                                    <label>Ngưỡng (số lần)</label>
                                    <input type="text" id="tsg-threshold-input" class="tsg-input" inputmode="decimal">
                                </div>
                                <div class="tsg-field tsg-field-btn">
                                    <button type="button" class="tsg-btn tsg-btn-primary" id="tsg-btn-save-threshold">Lưu</button>
                                </div>
                            </div>
                        </div>

                        <div class="tsg-card">
                            <div class="tsg-card-head"><i class="fa-solid fa-boxes-stacked"></i> Sản phẩm dùng nguyên liệu này</div>
                            <div class="tsg-field-row">
                                <div class="tsg-field tsg-field-grow">
                                    <label>Sản phẩm</label>
                                    <div class="tsg-search-wrap">
                                        <i class="fa-solid fa-magnifying-glass tsg-search-icon"></i>
                                        <input type="text" id="tsg-setup-product" class="tsg-search-input" autocomplete="off"
                                            placeholder="Tìm sản phẩm..." spellcheck="false">
                                        <ul class="tsg-search-dropdown" id="tsg-setup-product-dropdown"></ul>
                                    </div>
                                </div>
                                <div class="tsg-field">
                                    <label>Tỉ lệ dùng (%)</label>
                                    <span class="tsg-tip" data-tsg-tip="Tỉ lệ dùng = số kg NVL kiểm soát cho 1 đơn vị thành phẩm. VD dùng 0,5kg NVL cho 1kg thành phẩm thì nhập 50.">
                                        <input type="text" id="tsg-setup-ratio" class="tsg-input" inputmode="decimal" placeholder="VD 50">
                                    </span>
                                </div>
                                <div class="tsg-field tsg-field-btn">
                                    <button type="button" class="tsg-btn tsg-btn-primary" id="tsg-btn-add-setup">Thêm</button>
                                </div>
                            </div>
                            <div class="tsg-setup-wrap">
                                <table class="tsg-setup-table">
                                    <thead>
                                        <tr>
                                            <th>Sản phẩm</th>
                                            <th class="tsg-st-ratio">Tỉ lệ dùng</th>
                                            <th class="tsg-st-act"></th>
                                        </tr>
                                    </thead>
                                    <tbody id="tsg-setup-tbody"></tbody>
                                </table>
                            </div>
                        </div>

                        <div class="tsg-card">
                            <div class="tsg-card-head"><i class="fa-solid fa-flag-checkered"></i> Mốc tồn đầu ban đầu</div>
                            <div class="tsg-field-row">
                                <div class="tsg-field">
                                    <label>Tồn đầu hiện có</label>
                                    <input type="text" id="tsg-opening-qty" class="tsg-input" inputmode="decimal">
                                </div>
                                <div class="tsg-field">
                                    <label>Ngày</label>
                                    <input type="date" id="tsg-opening-date" class="tsg-input">
                                </div>
                                <div class="tsg-field tsg-field-btn">
                                    <button type="button" class="tsg-btn tsg-btn-primary" id="tsg-btn-save-opening">Lưu mốc</button>
                                </div>
                            </div>
                            <div class="tsg-opening-hint" id="tsg-opening-hint"></div>
                        </div>

                        <div class="tsg-card">
                            <div class="tsg-card-head"><i class="fa-solid fa-truck-ramp-box"></i> Nhập thêm</div>
                            <div class="tsg-field-row">
                                <div class="tsg-field">
                                    <label>Số lượng</label>
                                    <input type="text" id="tsg-receipt-qty" class="tsg-input" inputmode="decimal">
                                </div>
                                <div class="tsg-field">
                                    <label>Ngày</label>
                                    <input type="date" id="tsg-receipt-date" class="tsg-input">
                                </div>
                                <div class="tsg-field tsg-field-grow">
                                    <label>Ghi chú</label>
                                    <input type="text" id="tsg-receipt-note" class="tsg-input" placeholder="Không bắt buộc">
                                </div>
                                <div class="tsg-field tsg-field-btn">
                                    <button type="button" class="tsg-btn tsg-btn-primary" id="tsg-btn-add-receipt">Nhập kho</button>
                                </div>
                            </div>
                            <div class="tsg-setup-wrap tsg-receipt-wrap">
                                <table class="tsg-setup-table">
                                    <thead>
                                        <tr>
                                            <th>Ngày</th>
                                            <th>Ghi chú</th>
                                            <th class="tsg-st-ratio">Số lượng</th>
                                        </tr>
                                    </thead>
                                    <tbody id="tsg-receipt-tbody"></tbody>
                                </table>
                            </div>
                        </div>

                        <div class="tsg-card">
                            <div class="tsg-card-head"><i class="fa-solid fa-book"></i> Sổ / lịch sử đầy đủ</div>
                            <div class="tsg-ledger-wrap tsg-ledger-scroll">
                                <table class="tsg-ledger-table" id="tsg-ledger-table">
                                    <thead>
                                        <tr>
                                            <th class="tsg-lg-date">Ngày</th>
                                            <th>Nội dung</th>
                                            <th class="tsg-lg-qty">+/-</th>
                                            <th class="tsg-lg-qty">Tồn lũy kế</th>
                                        </tr>
                                    </thead>
                                    <tbody id="tsg-ledger-tbody"></tbody>
                                </table>
                            </div>
                        </div>
                    </div>
                </section>

            </div>
        </div>
    </div>

    <script>
        window.TSG_CONFIG = {
            baseUrl: '?mod=tea_scent_group&controllers=tea_scent_group&action=',
            groups: <?php echo json_encode($groups, JSON_UNESCAPED_UNICODE); ?>
        };
    </script>
    <script src="<?php echo asset_ver('public/js/tea_scent_group/tea_scent_group.js'); ?>"></script>
    <script src="<?php echo asset_ver('public/js/shared/app_shell.js'); ?>"></script>
</body>

</html>
