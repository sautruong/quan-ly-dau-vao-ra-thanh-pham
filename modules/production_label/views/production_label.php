<!DOCTYPE html>
<html lang="vi">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Tạo tem bao bì ngoài</title>
    <link rel="icon" href="public/images/logo/logo_vat_png.png" type="image/png">
    <link rel="stylesheet" href="public/css/reset.css">
    <link rel="stylesheet" href="public/css/all.css">
    <link rel="stylesheet" href="public/css/global.css">
    <link rel="stylesheet" href="<?php echo asset_ver('public/css/shared/app_shell.css'); ?>">
    <link rel="stylesheet" href="public/css/production_label/production_label.css">
</head>

<body>
    <div id="wrapper" class="has-sider">
        <?php get_sidebar('app'); ?>
        <?php get_header('app'); ?>

        <div class="content pl-content">

            <!-- ===== Khối Thiết lập ===== -->
            <div class="pl-setup">
                <h2 class="pl-block-title"><i class="fa-solid fa-sliders"></i> Thiết lập</h2>

                <div class="pl-setup-row">
                    <div class="pl-search-wrap">
                        <label class="pl-field-label">Sản phẩm</label>
                        <i class="fa-solid fa-magnifying-glass pl-search-icon"></i>
                        <input type="text" id="pl-search" class="pl-search-input"
                            placeholder="Tìm sản phẩm..." autocomplete="off">
                        <ul class="pl-search-dropdown" id="pl-search-dropdown"></ul>
                    </div>

                    <div class="pl-field">
                        <label class="pl-field-label" for="pl-date-display">Ngày sản xuất</label>
                        <div class="pl-date-picker" id="pl-date-picker">
                            <button type="button" class="pl-date-display" id="pl-date-display"></button>
                            <input type="hidden" id="pl-date" value="<?= htmlspecialchars($today, ENT_QUOTES, 'UTF-8') ?>">
                            <div class="pl-date-cal" id="pl-date-cal"></div>
                        </div>
                    </div>

                    <div class="pl-field pl-field-batch">
                        <label class="pl-field-label" for="pl-batch">Số mẻ sản xuất</label>
                        <input type="text" id="pl-batch" class="pl-batch-input" value="1" maxlength="2" inputmode="numeric">
                    </div>

                    <div class="pl-field pl-field-qty">
                        <label class="pl-field-label" for="pl-qty-expected">SLSX Dự kiến</label>
                        <input type="number" id="pl-qty-expected" class="pl-qty-expected-input" min="1" placeholder="vd: 100">
                    </div>

                    <button type="button" class="pl-btn pl-btn-add-list" id="pl-btn-add-list" title="Thêm sản phẩm đang chọn vào danh sách sản xuất">
                        <i class="fa-solid fa-plus"></i> Thêm vào danh sách
                    </button>
                </div>

                <!-- ===== Danh sách sản xuất: mặc định lấy từ KHSX (plan_for_staff), có thể thêm/sửa/xóa ===== -->
                <div class="pl-list-wrap">
                    <h3 class="pl-list-title"><i class="fa-solid fa-list-check"></i> Danh sách sản xuất</h3>
                    <ul class="pl-list" id="pl-list">
                        <?php foreach ($khsx_products as $it): ?>
                            <li class="pl-list-item" data-product-id="<?= (int) $it['product_id'] ?>">
                                <span class="pl-list-name"><?= htmlspecialchars($it['product_name'], ENT_QUOTES, 'UTF-8') ?></span>
                                <label class="pl-list-field">Mẻ
                                    <input type="text" class="pl-list-batch" value="1" maxlength="2" inputmode="numeric">
                                </label>
                                <label class="pl-list-field">SLSX Dự kiến
                                    <input type="number" class="pl-list-qty" value="<?= (int) $it['quantity'] ?>" min="1" step="1">
                                </label>
                                <label class="pl-list-field pl-list-sample-field">
                                    <span class="app-round-check">
                                        <input type="checkbox" class="pl-list-sample-check">
                                        <span class="app-round-check-mark"><i class="fa-solid fa-check"></i></span>
                                    </span>
                                    Tạo tem lưu mẫu
                                </label>
                                <button type="button" class="pl-list-del" title="Xóa khỏi danh sách"><i class="fa-solid fa-xmark"></i></button>
                            </li>
                        <?php endforeach; ?>
                        <li class="pl-list-empty" id="pl-list-empty" <?= empty($khsx_products) ? '' : 'style="display:none;"' ?>>Danh sách sản xuất đang trống.</li>
                    </ul>
                </div>

                <div class="pl-setup-actions">
                    <button type="button" class="pl-btn pl-btn-reset" id="pl-btn-reset">
                        <i class="fa-solid fa-arrow-rotate-left"></i> Reset
                    </button>
                    <button type="button" class="pl-btn pl-btn-sample" id="pl-btn-sample">
                        <i class="fa-solid fa-vial"></i> Tạo tem lưu mẫu
                    </button>
                    <button type="button" class="pl-btn pl-btn-outer" id="pl-btn-outer">
                        <i class="fa-solid fa-tags"></i> Tạo tem bao bì ngoài
                    </button>
                </div>
            </div>

            <!-- ===== Khối Tem được tạo ===== -->
            <div class="pl-generated">
                <div class="pl-generated-head">
                    <h2 class="pl-block-title"><i class="fa-solid fa-tags"></i> Tem được tạo</h2>
                    <button type="button" class="pl-btn pl-btn-print" id="pl-btn-print">
                        <i class="fa-solid fa-print"></i> In A4
                    </button>
                </div>
                <p class="pl-sample-warning" id="pl-sample-warning" style="display:none;">
                    <i class="fa-solid fa-triangle-exclamation"></i> Có tem lưu mẫu — nên in giấy decal để dán mẫu.
                </p>
                <div class="pl-grid" id="pl-grid">
                    <p class="pl-grid-empty" id="pl-grid-empty">Chưa có tem nào. Chọn sản phẩm rồi bấm "Tạo tem lưu mẫu", hoặc bấm "Tạo tem bao bì ngoài" để tạo tem hàng loạt theo Danh sách sản xuất.</p>
                </div>
            </div>

        </div>
    </div>

    <!-- Template tem lưu mẫu -->
    <template id="pl-tpl-sample">
        <div class="pl-card-wrap">
            <div class="pl-card pl-card-sample">
                <div class="pl-card-bar pl-card-bar-top"></div>
                <button type="button" class="pl-card-del" title="Xóa tem"><i class="fa-solid fa-xmark"></i></button>
                <div class="pl-card-body">
                    <div class="pl-card-head">
                        <img src="public/images/logo/logo_vat_png.png" alt="logo" class="pl-card-logo">
                        <div class="pl-card-name" contenteditable="true" spellcheck="false"></div>
                    </div>
                    <table class="pl-card-ing"><tbody></tbody></table>
                    <div class="pl-card-date"></div>
                </div>
                <div class="pl-card-bar pl-card-bar-bottom"></div>
            </div>
        </div>
    </template>

    <!-- Template tem bao bì ngoài -->
    <template id="pl-tpl-outer">
        <div class="pl-card-wrap">
            <div class="pl-card pl-card-outer">
                <div class="pl-card-bar pl-card-bar-top"></div>
                <button type="button" class="pl-card-del" title="Xóa tem"><i class="fa-solid fa-xmark"></i></button>
                <div class="pl-card-body">
                    <div class="pl-card-head">
                        <img src="public/images/logo/logo_vat_png.png" alt="logo" class="pl-card-logo">
                        <div class="pl-card-name" contenteditable="true" spellcheck="false"></div>
                    </div>
                    <div class="pl-card-lot"></div>
                    <div class="pl-card-spec"></div>
                    <div class="pl-card-date"></div>
                    <div class="pl-card-hotline pl-hotline-editable">
                        <span class="pl-hotline-label">Hotline: </span><span class="pl-hotline-text"></span><button type="button" class="pl-hotline-edit" title="Sửa hotline (lưu dùng dài lâu)"><i class="fa-solid fa-pen"></i></button>
                    </div>
                </div>
                <div class="pl-card-bar pl-card-bar-bottom"></div>
            </div>
        </div>
    </template>

    <script>
        window.PL_CONFIG = {
            baseUrl: '?mod=production_label&controllers=production_label&action=',
            hotline: <?= json_encode($hotline, JSON_UNESCAPED_UNICODE) ?>
        };
    </script>
    <script src="public/js/production_label/production_label.js"></script>
    <script src="public/js/shared/check_database.js"></script>
    <script src="<?php echo asset_ver('public/js/shared/app_shell.js'); ?>"></script>
</body>

</html>
