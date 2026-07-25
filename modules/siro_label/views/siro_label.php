<!DOCTYPE html>
<html lang="vi">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Nhãn siro mẫu</title>
    <link rel="icon" href="public/images/logo/logo_vat_png.png" type="image/png">
    <link rel="stylesheet" href="public/css/reset.css">
    <link rel="stylesheet" href="public/css/all.css">
    <link rel="stylesheet" href="public/css/global.css">
    <link rel="stylesheet" href="<?php echo asset_ver('public/css/shared/app_shell.css'); ?>">
    <link rel="stylesheet" href="public/css/siro_label/siro_label.css">
</head>

<body>
    <div id="wrapper" class="has-sider">
        <?php get_sidebar('app'); ?>
        <?php get_header('app'); ?>

        <div class="content sl-content">

            <!-- ===== Khối Thiết lập ===== -->
            <div class="sl-setup">
                <h2 class="sl-block-title"><i class="fa-solid fa-sliders"></i> Thiết lập</h2>

                <div class="sl-setup-row">
                    <div class="sl-field sl-field-name">
                        <label class="sl-field-label" for="sl-name">Tên siro</label>
                        <input type="text" id="sl-name" class="sl-text-input" placeholder="vd: SIRO MÃNG CẦU"
                            autocomplete="off">
                    </div>

                    <div class="sl-field">
                        <label class="sl-field-label" for="sl-nsx-display">NSX</label>
                        <div class="sl-date-picker" id="sl-nsx-picker">
                            <button type="button" class="sl-date-display" id="sl-nsx-display"></button>
                            <input type="hidden" id="sl-nsx" value="<?= htmlspecialchars($today, ENT_QUOTES, 'UTF-8') ?>">
                            <div class="sl-date-cal" id="sl-nsx-cal"></div>
                        </div>
                    </div>

                    <div class="sl-field">
                        <label class="sl-field-label" for="sl-hsd-display">HSD</label>
                        <div class="sl-date-picker" id="sl-hsd-picker">
                            <button type="button" class="sl-date-display" id="sl-hsd-display"></button>
                            <input type="hidden" id="sl-hsd" value="">
                            <div class="sl-date-cal" id="sl-hsd-cal"></div>
                        </div>
                    </div>

                    <div class="sl-field">
                        <label class="sl-field-label" for="sl-qty">Số lượng tem</label>
                        <input type="text" inputmode="numeric" id="sl-qty" class="sl-num-input" value="1">
                    </div>
                </div>

                <div class="sl-setup-actions">
                    <button type="button" class="sl-btn sl-btn-reset" id="sl-btn-reset">
                        <i class="fa-solid fa-arrow-rotate-left"></i> Reset
                    </button>
                    <button type="button" class="sl-btn sl-btn-make" id="sl-btn-make">
                        <i class="fa-solid fa-tags"></i> Tạo tem
                    </button>
                </div>
            </div>

            <!-- ===== Khối Tem hiển thị ===== -->
            <div class="sl-generated">
                <div class="sl-generated-head">
                    <h2 class="sl-block-title"><i class="fa-solid fa-tags"></i> Tem hiển thị</h2>
                    <button type="button" class="sl-btn sl-btn-print" id="sl-btn-print">
                        <i class="fa-solid fa-print"></i> In A4
                    </button>
                </div>
                <div class="sl-grid" id="sl-grid">
                    <p class="sl-grid-empty" id="sl-grid-empty">Chưa có tem nào. Điền Thiết lập rồi bấm "Tạo tem".</p>
                </div>
            </div>

        </div>
    </div>

    <!-- Template 1 tem -->
    <template id="sl-tpl-card">
        <div class="sl-card-wrap">
            <div class="sl-card">
                <button type="button" class="sl-card-del" title="Xóa tem"><i class="fa-solid fa-xmark"></i></button>

                <img src="public/images/logo/logo_vat_png.png" alt="logo" class="sl-card-logo">

                <div class="sl-card-name" contenteditable="true" spellcheck="false"></div>

                <div class="sl-card-divider">★ ★ ★ ★ ★</div>

                <div class="sl-card-company">
                    <span class="sl-fixed-editable sl-fixed-company" data-field="company_line1"><span class="sl-fixed-text"></span><button type="button" class="sl-fixed-edit" title="Sửa (lưu dùng dài lâu)"><i class="fa-solid fa-pen"></i></button></span>
                    <span class="sl-card-addr"><span class="sl-label">Địa chỉ:</span><span class="sl-fixed-editable sl-fixed-address" data-field="address"><span class="sl-fixed-text"></span><button type="button" class="sl-fixed-edit" title="Sửa (lưu dùng dài lâu)"><i class="fa-solid fa-pen"></i></button></span></span>
                </div>

                <div class="sl-card-info">
                    <div class="sl-card-row">
                        <span class="sl-label">Bảo Quản:</span>
                        <span class="sl-fixed-editable" data-field="storage"><span class="sl-fixed-text sl-value"></span><button type="button" class="sl-fixed-edit" title="Sửa (lưu dùng dài lâu)"><i class="fa-solid fa-pen"></i></button></span>
                    </div>
                    <div class="sl-card-row">
                        <span class="sl-label">NSX:</span><span class="sl-value sl-card-nsx"></span>
                    </div>
                    <div class="sl-card-row">
                        <span class="sl-label">HSD:</span><span class="sl-value sl-card-hsd"></span>
                    </div>
                    <div class="sl-card-row">
                        <span class="sl-label">Hotline:</span>
                        <span class="sl-fixed-editable" data-field="hotline"><span class="sl-fixed-text sl-value"></span><button type="button" class="sl-fixed-edit" title="Sửa (lưu dùng dài lâu)"><i class="fa-solid fa-pen"></i></button></span>
                    </div>
                    <div class="sl-card-row">
                        <span class="sl-label">Khối lượng:</span>
                        <span class="sl-fixed-editable" data-field="volume"><span class="sl-fixed-text sl-value"></span><button type="button" class="sl-fixed-edit" title="Sửa (lưu dùng dài lâu)"><i class="fa-solid fa-pen"></i></button></span>
                    </div>
                    <div class="sl-card-row">
                        <span class="sl-label">Xuất xứ:</span>
                        <span class="sl-fixed-editable" data-field="origin"><span class="sl-fixed-text sl-value"></span><button type="button" class="sl-fixed-edit" title="Sửa (lưu dùng dài lâu)"><i class="fa-solid fa-pen"></i></button></span>
                    </div>
                </div>
            </div>
        </div>
    </template>

    <script>
        window.SL_CONFIG = {
            baseUrl: '?mod=siro_label&controllers=siro_label&action=',
            texts: <?= json_encode($texts, JSON_UNESCAPED_UNICODE) ?>
        };
    </script>
    <script src="public/js/siro_label/siro_label.js"></script>
    <script src="public/js/shared/check_database.js"></script>
    <script src="<?php echo asset_ver('public/js/shared/app_shell.js'); ?>"></script>
</body>

</html>
