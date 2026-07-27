<?php
/** Dữ liệu từ controller: $doc (tiêu đề phiếu + người ký), $recipes, $orders. */
$doc     = $doc     ?? ['company_name' => 'VUA AN TOÀN', 'company_address' => '', 'sign_roles' => [], 'signs' => []];
$recipes = $recipes ?? [];
$orders  = $orders  ?? [];
$oc_today = 'Ngày ' . date('j') . ' tháng ' . date('m') . ' năm ' . date('Y');
?>
<!DOCTYPE html>
<html lang="vi">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Đặt hàng cà phê</title>
    <link rel="icon" href="public/images/logo/logo_vat_png.png" type="image/png">
    <link rel="stylesheet" href="<?php echo asset_ver('public/css/reset.css'); ?>">
    <link rel="stylesheet" href="<?php echo asset_ver('public/css/all.css'); ?>">
    <link rel="stylesheet" href="<?php echo asset_ver('public/css/global.css'); ?>">
    <link rel="stylesheet" href="<?php echo asset_ver('public/css/shared/app_shell.css'); ?>">
    <link rel="stylesheet" href="<?php echo asset_ver('public/css/order_coffee/order_coffee.css'); ?>">
</head>

<body>
    <div id="wrapper" class="has-sider">
        <?php get_sidebar('app'); ?>
        <?php get_header('app'); ?>

        <div class="content oc-content">
            <div class="oc-grid">

                <!-- ============================================================ -->
                <!-- KHỐI 1: BẢNG ĐIỀU KHIỂN & THIẾT LẬP                          -->
                <!-- ============================================================ -->
                <section class="oc-block oc-block-setup">
                    <div class="oc-block-head">
                        <h2><i class="fa-solid fa-sliders"></i> Bảng điều khiển &amp; thiết lập</h2>
                    </div>

                    <!-- Chọn nhà cung cấp (dùng cho cả phiếu & sổ bao bì) -->
                    <div class="oc-supplier-search">
                        <div class="oc-search-wrap">
                            <i class="fa-solid fa-magnifying-glass oc-search-icon"></i>
                            <input type="text" id="oc-supplier-input" class="oc-search-input" autocomplete="off"
                                placeholder="Tìm nhà cung cấp..." spellcheck="false">
                            <ul class="oc-search-dropdown" id="oc-supplier-dropdown"></ul>
                        </div>
                        <button type="button" class="oc-tool-btn" id="oc-btn-supplier-info" disabled
                            title="Xem thông tin nhà cung cấp">
                            <i class="fa-solid fa-circle-info"></i> Thông tin NCC
                        </button>
                    </div>
                    <!-- ----- Thiết lập sản phẩm (dạng FAQ, mặc định thu lại) ----- -->
                    <div class="oc-card oc-collapsible is-collapsed" id="oc-setup-faq">
                        <div class="oc-card-head oc-collapsible-head" id="oc-setup-faq-head">
                            <i class="fa-solid fa-mug-hot"></i> Thiết lập sản phẩm
                            <i class="fa-solid fa-chevron-down oc-collapsible-toggle-ico"></i>
                        </div>

                        <div class="oc-collapsible-body" id="oc-setup-faq-body">
                            <div class="oc-field">
                                <label>Sản phẩm</label>
                                <div class="oc-search-wrap">
                                    <i class="fa-solid fa-magnifying-glass oc-search-icon"></i>
                                    <input type="text" id="oc-prod-input" class="oc-search-input" autocomplete="off"
                                        placeholder="Chọn sản phẩm..." spellcheck="false">
                                    <ul class="oc-search-dropdown" id="oc-prod-dropdown"></ul>
                                </div>
                            </div>

                            <div class="oc-field">
                                <label>Thành phần (nguyên liệu)</label>
                                <div class="oc-recipe-mats" id="oc-recipe-mats"></div>
                                <button type="button" class="oc-mini-btn" id="oc-btn-add-mat">
                                    <i class="fa-solid fa-plus"></i> Thêm nguyên liệu
                                </button>
                            </div>

                            <div class="oc-field-row">
                                <div class="oc-field">
                                    <label>Bao bì dùng (tùy chọn)</label>
                                    <div class="oc-search-wrap">
                                        <i class="fa-solid fa-box oc-search-icon"></i>
                                        <input type="text" id="oc-pkg-input" class="oc-search-input" autocomplete="off"
                                            placeholder="Bao bì gửi NCC gia công..." spellcheck="false">
                                        <ul class="oc-search-dropdown" id="oc-pkg-dropdown"></ul>
                                    </div>
                                </div>
                                <div class="oc-field oc-field-sm">
                                    <label>Số thành phẩm tạo thành</label>
                                    <input type="text" id="oc-yield" class="oc-input" inputmode="decimal" value="1"
                                        title="Số thành phẩm tạo ra từ lượng nguyên liệu đã nhập (để chuẩn hóa định mức/1 thành phẩm)">
                                </div>
                            </div>

                            <div class="oc-field">
                                <label>Ghi chú sản phẩm (gửi kèm đơn)</label>
                                <textarea id="oc-recipe-note" class="oc-input" rows="2"
                                    placeholder="Ghi chú cho sản phẩm này khi gửi đơn đặt hàng..."></textarea>
                            </div>

                            <div class="oc-setup-actions">
                                <button type="button" class="oc-btn oc-btn-primary" id="oc-btn-save-recipe">
                                    <i class="fa-solid fa-floppy-disk"></i> Lưu thiết lập
                                </button>
                                <button type="button" class="oc-btn oc-btn-ghost" id="oc-btn-reset-recipe">
                                    <i class="fa-solid fa-rotate-left"></i> Làm mới
                                </button>
                            </div>
                        </div>
                    </div>

                    <!-- Danh sách SP đã thiết lập (dạng FAQ, mặc định thu lại) -->
                    <div class="oc-card oc-collapsible is-collapsed" id="oc-recipe-list-faq">
                        <div class="oc-card-head oc-collapsible-head" id="oc-recipe-list-faq-head">
                            <i class="fa-solid fa-list-check"></i> Sản phẩm đã thiết lập
                            <i class="fa-solid fa-chevron-down oc-collapsible-toggle-ico"></i>
                        </div>
                        <div class="oc-collapsible-body" id="oc-recipe-list-faq-body">
                            <div class="oc-recipe-list" id="oc-recipe-list"></div>
                            <div class="oc-mat-pager" id="oc-recipe-pager" style="display:none;">
                                <div class="oc-pager-size">
                                    <label for="oc-recipe-page-size">Hiển thị</label>
                                    <select id="oc-recipe-page-size">
                                        <option value="2">2</option>
                                        <option value="4">4</option>
                                        <option value="8" selected>8</option>
                                        <option value="12">12</option>
                                        <option value="20">20</option>
                                        <option value="0">Tất cả</option>
                                    </select>
                                    <span>sản phẩm</span>
                                </div>
                                <div class="oc-pager-nav">
                                    <button type="button" class="oc-pager-btn" id="oc-recipe-page-prev" title="Trang trước">
                                        <i class="fa-solid fa-chevron-left"></i>
                                    </button>
                                    <span class="oc-pager-info" id="oc-recipe-page-info">1/1</span>
                                    <button type="button" class="oc-pager-btn" id="oc-recipe-page-next" title="Trang sau">
                                        <i class="fa-solid fa-chevron-right"></i>
                                    </button>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- ----- Theo dõi bao bì tại NCC ----- -->
                    <div class="oc-card">
                        <div class="oc-card-head">
                            <i class="fa-solid fa-boxes-stacked"></i> Tồn bao bì tại NCC
                        </div>
                        <div class="oc-pkg-actions">
                            <button type="button" class="oc-tool-btn" id="oc-btn-pkg-opening" disabled>
                                <i class="fa-solid fa-flag"></i> Tồn đầu kỳ (NCC còn)
                            </button>
                            <button type="button" class="oc-tool-btn" id="oc-btn-pkg-send" disabled>
                                <i class="fa-solid fa-truck-arrow-right"></i> Chuyển bì cho NCC
                            </button>
                            <button type="button" class="oc-tool-btn" id="oc-btn-pkg-loss" disabled>
                                <i class="fa-solid fa-circle-minus"></i> Trừ tồn bì NCC
                            </button>
                            <button type="button" class="oc-tool-btn" id="oc-btn-pkg-report">
                                <i class="fa-solid fa-file-export"></i> Xuất báo cáo
                            </button>
                        </div>
                        <div class="oc-ledger-wrap oc-ledger-scroll">
                            <table class="oc-ledger-table">
                                <thead>
                                    <tr>
                                        <th class="oc-lg-date">Ngày</th>
                                        <th class="oc-lg-name">Tên bao bì</th>
                                        <th class="oc-lg-content">Nội dung</th>
                                        <th class="oc-lg-qty">Số lượng</th>
                                    </tr>
                                </thead>
                                <tbody id="oc-ledger-tbody">
                                    <tr class="oc-lg-empty"><td colspan="4">Chọn nhà cung cấp để xem sổ bao bì.</td></tr>
                                </tbody>
                            </table>
                        </div>
                        <div class="oc-ledger-total" id="oc-ledger-total" style="display:none;"></div>
                        <div class="oc-mat-pager" id="oc-ledger-pager" style="display:none;">
                            <div class="oc-pager-size">
                                <label for="oc-ledger-page-size">Hiển thị</label>
                                <select id="oc-ledger-page-size">
                                    <option value="10">10</option>
                                    <option value="20" selected>20</option>
                                    <option value="50">50</option>
                                    <option value="100">100</option>
                                    <option value="0">Tất cả</option>
                                </select>
                                <span>dòng</span>
                            </div>
                            <div class="oc-pager-nav">
                                <button type="button" class="oc-pager-btn" id="oc-ledger-page-prev" title="Trang trước">
                                    <i class="fa-solid fa-chevron-left"></i>
                                </button>
                                <span class="oc-pager-info" id="oc-ledger-page-info">1/1</span>
                                <button type="button" class="oc-pager-btn" id="oc-ledger-page-next" title="Trang sau">
                                    <i class="fa-solid fa-chevron-right"></i>
                                </button>
                            </div>
                        </div>
                    </div>
                </section>

                <!-- Thanh kéo chia đôi 2 khối (cố định bề ngang "Đơn đặt hàng") -->
                <div class="oc-resizer rs-resizer" data-target="#oc-order-block" data-storage-key="oc_order_block_width"
                    data-min="360" data-max="900"></div>

                <!-- ============================================================ -->
                <!-- KHỐI 2: ĐƠN ĐẶT HÀNG                                         -->
                <!-- ============================================================ -->
                <section class="oc-block oc-block-order" id="oc-order-block">
                    <div class="oc-block-head">
                        <h2><i class="fa-solid fa-file-invoice"></i> Đơn đặt hàng</h2>
                        <div class="oc-order-tools">
                            <button type="button" class="oc-tool-btn oc-tool-accent" id="oc-btn-open-order">
                                <i class="fa-solid fa-cart-plus"></i> Đặt hàng
                            </button>
                            <button type="button" class="oc-tool-btn" id="oc-btn-saved-orders">
                                <i class="fa-solid fa-clock-rotate-left"></i> Đơn đã lưu
                            </button>
                            <button type="button" class="oc-tool-btn" id="oc-btn-sign-setting" title="Cài đặt chữ ký">
                                <i class="fa-solid fa-signature"></i> Chữ ký
                            </button>
                        </div>
                    </div>

                    <!-- Phiếu đặt hàng (vùng được chụp/share) -->
                    <div class="oc-doc" id="oc-doc-sheet">
                        <div class="oc-doc-header">
                            <div class="oc-doc-brand">
                                <div class="oc-doc-logo-badge">
                                    <img class="oc-doc-logo" src="public/images/logo/logo_vat_png.png" alt="logo">
                                </div>
                                <div class="oc-doc-company">
                                    <div class="oc-editable oc-doc-name" data-key="order_coffee.company_name">
                                        <span class="oc-etext"><?php echo htmlspecialchars($doc['company_name']); ?></span>
                                        <button type="button" class="oc-edit-btn" title="Sửa"><i class="fa-solid fa-pen"></i></button>
                                    </div>
                                    <div class="oc-editable oc-doc-enname" data-key="order_coffee.company_enname">
                                        <span class="oc-etext"><?php echo htmlspecialchars($doc['company_enname']); ?></span>
                                        <button type="button" class="oc-edit-btn" title="Sửa"><i class="fa-solid fa-pen"></i></button>
                                    </div>
                                </div>
                            </div>

                            <div class="oc-doc-contact">
                                <div class="oc-editable oc-doc-addr" data-key="order_coffee.company_address" data-multiline="1">
                                    <span class="oc-etext"><?php echo htmlspecialchars($doc['company_address']); ?></span>
                                    <button type="button" class="oc-edit-btn" title="Sửa địa chỉ"><i class="fa-solid fa-pen"></i></button>
                                </div>
                                <div class="oc-editable oc-doc-phone" data-key="order_coffee.company_phone">
                                    <i class="fa-solid fa-phone oc-doc-ico"></i>
                                    <span class="oc-etext"><?php echo htmlspecialchars($doc['company_phone']); ?></span>
                                    <button type="button" class="oc-edit-btn" title="Sửa SĐT"><i class="fa-solid fa-pen"></i></button>
                                </div>
                                <div class="oc-editable oc-doc-email" data-key="order_coffee.company_email">
                                    <i class="fa-solid fa-envelope oc-doc-ico"></i>
                                    <span class="oc-etext"><?php echo htmlspecialchars($doc['company_email']); ?></span>
                                    <button type="button" class="oc-edit-btn" title="Sửa email"><i class="fa-solid fa-pen"></i></button>
                                </div>
                            </div>
                        </div>

                        <div class="oc-doc-rope"></div>

                        <div class="oc-doc-title"><span>ĐƠN ĐẶT HÀNG</span></div>

                        <div class="oc-doc-to">
                            Kính gửi: <strong id="oc-doc-supplier">.................................</strong>
                        </div>
                        <div class="oc-doc-intro">
                            Công ty chúng tôi có nhu cầu đặt hàng tại Quý công ty với danh sách các mặt hàng sau:
                        </div>

                        <div class="oc-doc-table" id="oc-doc-table">
                            <div class="oc-doc-thead">
                                <div class="oc-dc-name">TÊN HÀNG HÓA</div>
                                <div class="oc-dc-unit">ĐƠN VỊ</div>
                                <div class="oc-dc-qty">SỐ LƯỢNG</div>
                            </div>
                            <div class="oc-doc-groups" id="oc-doc-tbody">
                                <div class="oc-doc-empty">Bấm "Đặt hàng" để tạo đơn từ các sản phẩm đã thiết lập.</div>
                            </div>
                        </div>

                        <div class="oc-doc-thanks">
                            Rất mong nhận được hàng sớm từ Quý công ty. Chân thành cảm ơn!
                        </div>

                        <div class="oc-doc-signs" id="oc-doc-signs"></div>

                        <div class="oc-doc-footer">
                            <div class="oc-doc-bottom">
                                <div class="oc-doc-footband">
                                    <span class="oc-doc-foottri"></span>
                                </div>
                                <div class="oc-doc-date"><?php echo htmlspecialchars($oc_today); ?></div>
                            </div>
                        </div>
                        <div class="oc-doc-bottombar"></div>
                    </div>

                    <div class="oc-order-actions">
                        <button type="button" class="oc-btn oc-btn-primary" id="oc-btn-save-order">
                            <i class="fa-solid fa-floppy-disk"></i> Lưu đơn
                        </button>
                        <button type="button" class="oc-btn oc-btn-share" id="oc-btn-share-order">
                            <i class="fa-solid fa-share-nodes"></i> Chụp &amp; chia sẻ
                        </button>
                        <button type="button" class="oc-btn oc-btn-ghost" id="oc-btn-clear-order">
                            <i class="fa-solid fa-trash-can"></i> Xóa nội dung
                        </button>
                    </div>
                </section>

            </div>
        </div>
    </div>

    <!-- ============================================================ -->
    <!-- MODAL: Đặt hàng (chọn nhiều SP + số lượng)                    -->
    <!-- ============================================================ -->
    <div class="oc-modal-mask" id="oc-modal-order" style="display:none;">
        <div class="oc-modal-box oc-modal-lg">
            <span class="oc-modal-close" data-close="oc-modal-order">&times;</span>
            <h3 class="oc-modal-title"><i class="fa-solid fa-cart-plus"></i> Tạo đơn đặt hàng</h3>

            <div class="oc-ord-type">
                <label class="oc-radio">
                    <input type="radio" name="oc-order-type" value="process" checked>
                    <span><i class="fa-solid fa-industry"></i> Đặt gia công</span>
                </label>
                <label class="oc-radio">
                    <input type="radio" name="oc-order-type" value="material">
                    <span><i class="fa-solid fa-wheat-awn"></i> Đặt nguyên liệu</span>
                </label>
            </div>

            <table class="oc-ord-table">
                <thead>
                    <tr>
                        <th class="oc-ot-prod">Sản phẩm</th>
                        <th class="oc-ot-qty">SL thành phẩm</th>
                        <th class="oc-ot-note">Ghi chú</th>
                        <th class="oc-ot-act"></th>
                    </tr>
                </thead>
                <tbody id="oc-ord-tbody"></tbody>
            </table>
            <button type="button" class="oc-mini-btn" id="oc-btn-add-ord-row">
                <i class="fa-solid fa-plus"></i> Thêm sản phẩm
            </button>

            <div class="oc-modal-foot">
                <button type="button" class="oc-btn oc-btn-primary" id="oc-btn-create-order">
                    <i class="fa-solid fa-file-circle-plus"></i> Tạo đơn đặt hàng
                </button>
            </div>
        </div>
    </div>

    <!-- MODAL: Nghiệp vụ bao bì (tồn đầu / gửi / hao hụt) -->
    <div class="oc-modal-mask" id="oc-modal-pkg" style="display:none;">
        <div class="oc-modal-box">
            <span class="oc-modal-close" data-close="oc-modal-pkg">&times;</span>
            <h3 class="oc-modal-title"><i class="fa-solid fa-box"></i> <span id="oc-pkgm-title">Nghiệp vụ bao bì</span></h3>
            <p class="oc-modal-hint" id="oc-pkgm-hint"></p>
            <div class="oc-field">
                <label>Loại bao bì</label>
                <div class="oc-search-wrap">
                    <i class="fa-solid fa-box oc-search-icon"></i>
                    <input type="text" id="oc-pkgm-input" class="oc-search-input" autocomplete="off"
                        placeholder="Chọn loại bao bì..." spellcheck="false">
                    <ul class="oc-search-dropdown" id="oc-pkgm-dropdown"></ul>
                </div>
            </div>
            <div class="oc-field-row">
                <div class="oc-field">
                    <label>Số lượng</label>
                    <input type="text" id="oc-pkgm-qty" class="oc-input" inputmode="decimal" value="">
                </div>
                <div class="oc-field">
                    <label>Ngày</label>
                    <input type="date" id="oc-pkgm-date" class="oc-input">
                </div>
            </div>
            <div class="oc-modal-foot">
                <button type="button" class="oc-btn oc-btn-primary" id="oc-pkgm-apply">
                    <i class="fa-solid fa-check"></i> Ghi sổ
                </button>
            </div>
        </div>
    </div>

    <!-- MODAL: Thông tin nhà cung cấp -->
    <div class="oc-modal-mask" id="oc-modal-supplier" style="display:none;">
        <div class="oc-modal-box">
            <span class="oc-modal-close" data-close="oc-modal-supplier">&times;</span>
            <h3 class="oc-modal-title"><i class="fa-solid fa-truck-field"></i> Thông tin nhà cung cấp</h3>
            <div class="oc-sup-info" id="oc-sup-info"></div>
        </div>
    </div>

    <!-- MODAL: Đơn đã lưu -->
    <div class="oc-modal-mask" id="oc-modal-orders" style="display:none;">
        <div class="oc-modal-box oc-modal-lg">
            <span class="oc-modal-close" data-close="oc-modal-orders">&times;</span>
            <h3 class="oc-modal-title"><i class="fa-solid fa-clock-rotate-left"></i> Đơn đặt hàng đã lưu</h3>
            <div class="oc-orders-list" id="oc-orders-list"></div>
        </div>
    </div>

    <!-- MODAL: Chọn NCC + loại bao bì để xuất báo cáo tồn bao bì -->
    <div class="oc-modal-mask" id="oc-modal-pkgreport-select" style="display:none;">
        <div class="oc-modal-box">
            <span class="oc-modal-close" data-close="oc-modal-pkgreport-select">&times;</span>
            <h3 class="oc-modal-title"><i class="fa-solid fa-file-export"></i> Xuất báo cáo tồn bao bì</h3>
            <div class="oc-field">
                <label>Nhà cung cấp</label>
                <div class="oc-search-wrap">
                    <i class="fa-solid fa-truck-field oc-search-icon"></i>
                    <input type="text" id="oc-pkgrsel-supplier-input" class="oc-search-input" autocomplete="off"
                        placeholder="Chọn nhà cung cấp..." spellcheck="false">
                    <ul class="oc-search-dropdown" id="oc-pkgrsel-supplier-dropdown"></ul>
                </div>
            </div>
            <div class="oc-field">
                <label>Loại bao bì</label>
                <div class="oc-search-wrap">
                    <i class="fa-solid fa-box oc-search-icon"></i>
                    <input type="text" id="oc-pkgrsel-pkg-input" class="oc-search-input" autocomplete="off"
                        placeholder="Chọn loại bao bì..." spellcheck="false">
                    <ul class="oc-search-dropdown" id="oc-pkgrsel-pkg-dropdown"></ul>
                </div>
            </div>
            <div class="oc-field" id="oc-pkgrsel-milestone-field" style="display:none;">
                <label>Chọn mốc</label>
                <select id="oc-pkgrsel-milestone" class="oc-input"></select>
                <p class="oc-modal-hint" id="oc-pkgrsel-milestone-hint"></p>
            </div>
            <div class="oc-modal-foot">
                <button type="button" class="oc-btn oc-btn-primary" id="oc-pkgrsel-view">
                    <i class="fa-solid fa-eye"></i> Xem báo cáo
                </button>
            </div>
        </div>
    </div>

    <!-- MODAL: Báo cáo tồn bao bì tại NCC (dạng phiếu, chụp & chia sẻ / xuất excel) -->
    <div class="oc-modal-mask" id="oc-modal-pkgreport" style="display:none;">
        <div class="oc-modal-box oc-modal-lg">
            <span class="oc-modal-close" data-close="oc-modal-pkgreport">&times;</span>

            <div class="oc-doc" id="oc-pkgreport-sheet">
                <div class="oc-doc-header">
                    <div class="oc-doc-brand">
                        <div class="oc-doc-logo-badge">
                            <img class="oc-doc-logo" src="public/images/logo/logo_vat_png.png" alt="logo">
                        </div>
                        <div class="oc-doc-company">
                            <div class="oc-doc-name"><span class="oc-etext"><?php echo htmlspecialchars($doc['company_name']); ?></span></div>
                            <div class="oc-doc-enname"><span class="oc-etext"><?php echo htmlspecialchars($doc['company_enname']); ?></span></div>
                        </div>
                    </div>
                    <div class="oc-doc-contact">
                        <div class="oc-editable oc-doc-addr"><span class="oc-etext"><?php echo htmlspecialchars($doc['company_address']); ?></span></div>
                        <div class="oc-editable oc-doc-phone">
                            <i class="fa-solid fa-phone oc-doc-ico"></i>
                            <span class="oc-etext"><?php echo htmlspecialchars($doc['company_phone']); ?></span>
                        </div>
                        <div class="oc-editable oc-doc-email">
                            <i class="fa-solid fa-envelope oc-doc-ico"></i>
                            <span class="oc-etext"><?php echo htmlspecialchars($doc['company_email']); ?></span>
                        </div>
                    </div>
                </div>

                <div class="oc-doc-rope"></div>

                <div class="oc-doc-title"><span>TỒN BAO BÌ Ở NCC</span></div>

                <div class="oc-doc-to">Kính gửi: <strong id="oc-pkgreport-supplier">.................................</strong></div>
                <div class="oc-doc-to">Tên bao bì: <strong id="oc-pkgreport-pkgname">.................................</strong></div>

                <div class="oc-pkgr-wrap">
                    <table class="oc-pkgr-table" id="oc-pkgreport-table">
                        <thead>
                            <tr>
                                <th>Ngày</th>
                                <th>Nội dung</th>
                                <th>Số lượng</th>
                            </tr>
                        </thead>
                        <tbody id="oc-pkgreport-tbody"></tbody>
                    </table>
                </div>

                <div class="oc-doc-footer">
                    <div class="oc-doc-bottom">
                        <div class="oc-doc-footband">
                            <span class="oc-doc-foottri"></span>
                        </div>
                        <div class="oc-doc-date"><?php echo htmlspecialchars($oc_today); ?></div>
                    </div>
                </div>
                <div class="oc-doc-bottombar"></div>
            </div>

            <div class="oc-order-actions">
                <button type="button" class="oc-btn oc-btn-primary" id="oc-btn-pkgreport-confirm">
                    <i class="fa-solid fa-check-double"></i> Chốt tồn
                </button>
                <button type="button" class="oc-btn oc-btn-share" id="oc-btn-pkgreport-share">
                    <i class="fa-solid fa-share-nodes"></i> Chụp &amp; chia sẻ
                </button>
                <button type="button" class="oc-btn oc-btn-ghost" id="oc-btn-pkgreport-excel"
                    data-export-excel data-export-target="#oc-pkgreport-table">
                    <i class="fa-solid fa-file-excel"></i> Xuất Excel
                </button>
            </div>
        </div>
    </div>

    <!-- MODAL: Cài đặt chữ ký -->
    <div class="oc-modal-mask" id="oc-modal-sign" style="display:none;">
        <div class="oc-modal-box">
            <span class="oc-modal-close" data-close="oc-modal-sign">&times;</span>
            <h3 class="oc-modal-title"><i class="fa-solid fa-signature"></i> Cài đặt chữ ký</h3>
            <p class="oc-modal-hint">Tích chọn chức danh để hiển thị cụm chữ ký trên phiếu. Có thể đổi tên, xóa, hoặc thêm chức danh mới. Trên phiếu, kéo-thả các cụm chữ ký để đổi vị trí.</p>
            <ul class="oc-sign-rolelist" id="oc-sign-rolelist"></ul>
            <div class="oc-sign-addrow">
                <input type="text" id="oc-sign-newrole" autocomplete="off" placeholder="Thêm chức danh mới...">
                <button type="button" class="oc-btn oc-btn-primary" id="oc-sign-addbtn"><i class="fa-solid fa-plus"></i> Thêm</button>
            </div>
        </div>
    </div>

    <!-- Template: dòng nguyên liệu trong thiết lập -->
    <template id="oc-recipe-mat-tpl">
        <div class="oc-recipe-mat">
            <div class="oc-search-wrap oc-rm-search">
                <input type="text" class="oc-search-input oc-rm-name" autocomplete="off" placeholder="Nguyên liệu..." spellcheck="false">
                <ul class="oc-search-dropdown oc-rm-dropdown"></ul>
            </div>
            <input type="text" class="oc-input oc-rm-unit" placeholder="ĐV">
            <input type="text" class="oc-input oc-rm-qty" inputmode="decimal" placeholder="SL">
            <button type="button" class="oc-rm-del" title="Bỏ">&times;</button>
        </div>
    </template>

    <!-- Template: dòng sản phẩm trong modal đặt hàng -->
    <template id="oc-ord-row-tpl">
        <tr class="oc-ord-row">
            <td class="oc-ot-prod">
                <div class="oc-search-wrap oc-ot-prod-wrap">
                    <input type="text" class="oc-search-input oc-ot-prod-input" data-pid="0" autocomplete="off"
                        placeholder="Chọn sản phẩm..." spellcheck="false">
                    <ul class="oc-search-dropdown oc-ot-prod-dropdown"></ul>
                </div>
            </td>
            <td class="oc-ot-qty"><input type="text" class="oc-input oc-ot-qty-in" inputmode="decimal" value="" placeholder="0"></td>
            <td class="oc-ot-note"><input type="text" class="oc-input oc-ot-note-in" placeholder="Ghi chú nhóm..."></td>
            <td class="oc-ot-act"><button type="button" class="oc-ord-del" title="Bỏ">&times;</button></td>
        </tr>
    </template>

    <script>
        window.OC_CONFIG = {
            baseUrl: '?mod=order_coffee&controllers=order_coffee&action=',
            recipes: <?php echo json_encode($recipes, JSON_UNESCAPED_UNICODE); ?>,
            orders: <?php echo json_encode($orders, JSON_UNESCAPED_UNICODE); ?>,
            signRoles: <?php echo json_encode($doc['sign_roles'], JSON_UNESCAPED_UNICODE); ?>,
            signs: <?php echo json_encode($doc['signs'], JSON_UNESCAPED_UNICODE); ?>
        };
    </script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/html2canvas/1.4.1/html2canvas.min.js"></script>
    <script src="<?php echo asset_ver('public/js/order_coffee/order_coffee.js'); ?>"></script>
    <script src="<?php echo asset_ver('public/js/admin_factory/data_export_excel.js'); ?>"></script>
    <script src="<?php echo asset_ver('public/js/shared/app_shell.js'); ?>"></script>
    <script src="<?php echo asset_ver('public/js/shared/resizable_split.js'); ?>"></script>
</body>

</html>
