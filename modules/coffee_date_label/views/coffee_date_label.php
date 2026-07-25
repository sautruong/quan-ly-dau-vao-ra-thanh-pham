<!DOCTYPE html>
<html lang="vi">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Tem date cà phê</title>
    <link rel="icon" href="public/images/logo/logo_vat_png.png" type="image/png">
    <link rel="stylesheet" href="public/css/reset.css">
    <link rel="stylesheet" href="public/css/all.css">
    <link rel="stylesheet" href="public/css/global.css">
    <link rel="stylesheet" href="<?php echo asset_ver('public/css/shared/app_shell.css'); ?>">
    <link rel="stylesheet" href="public/css/coffee_date_label/coffee_date_label.css">
</head>

<body>
    <div id="wrapper" class="has-sider">
        <?php get_sidebar('app'); ?>
        <?php get_header('app'); ?>

        <div class="content cdl-content">

            <!-- ===== Khối Thiết lập ===== -->
            <div class="cdl-setup">
                <h2 class="cdl-block-title"><i class="fa-solid fa-sliders"></i> Thiết lập</h2>

                <div class="cdl-setup-row">
                    <div class="cdl-field cdl-field-name">
                        <label class="cdl-field-label" for="cdl-name">Tên dòng sản phẩm</label>
                        <input type="text" id="cdl-name" class="cdl-text-input" placeholder="vd: Truyền Thống L1"
                            autocomplete="off">
                    </div>

                    <div class="cdl-field">
                        <label class="cdl-field-label" for="cdl-nsx-display">NSX</label>
                        <div class="cdl-date-picker" id="cdl-nsx-picker">
                            <button type="button" class="cdl-date-display" id="cdl-nsx-display"></button>
                            <input type="hidden" id="cdl-nsx" value="<?= htmlspecialchars($today, ENT_QUOTES, 'UTF-8') ?>">
                            <div class="cdl-date-cal" id="cdl-nsx-cal"></div>
                        </div>
                    </div>

                    <div class="cdl-field">
                        <label class="cdl-field-label" for="cdl-hsd-display">HSD</label>
                        <div class="cdl-date-picker" id="cdl-hsd-picker">
                            <button type="button" class="cdl-date-display" id="cdl-hsd-display"></button>
                            <input type="hidden" id="cdl-hsd" value="">
                            <div class="cdl-date-cal" id="cdl-hsd-cal"></div>
                        </div>
                    </div>

                    <div class="cdl-field">
                        <label class="cdl-field-label" for="cdl-qty">Số lượng tem</label>
                        <input type="number" id="cdl-qty" class="cdl-num-input" min="1" step="1" value="1">
                    </div>
                </div>

                <div class="cdl-setup-actions">
                    <button type="button" class="cdl-btn cdl-btn-reset" id="cdl-btn-reset">
                        <i class="fa-solid fa-arrow-rotate-left"></i> Reset
                    </button>
                    <button type="button" class="cdl-btn cdl-btn-make" id="cdl-btn-make">
                        <i class="fa-solid fa-tags"></i> Tạo tem
                    </button>
                </div>
            </div>

            <!-- ===== Khối Tem hiển thị ===== -->
            <div class="cdl-generated">
                <div class="cdl-generated-head">
                    <h2 class="cdl-block-title"><i class="fa-solid fa-tags"></i> Tem hiển thị</h2>
                    <button type="button" class="cdl-btn cdl-btn-print" id="cdl-btn-print">
                        <i class="fa-solid fa-print"></i> In A4
                    </button>
                </div>
                <div class="cdl-grid" id="cdl-grid">
                    <p class="cdl-grid-empty" id="cdl-grid-empty">Chưa có tem nào. Điền Thiết lập rồi bấm "Tạo tem".</p>
                </div>
            </div>

        </div>
    </div>

    <!-- Template 1 tem -->
    <template id="cdl-tpl-card">
        <div class="cdl-card-wrap">
            <div class="cdl-card">
                <img src="public/images/logo/logo_vat_png.png" alt="logo" class="cdl-card-logo">
                <div class="cdl-card-info">
                    <div class="cdl-card-name" contenteditable="true" spellcheck="false"></div>
                    <div class="cdl-card-row">
                        <span class="cdl-label">NSX:</span><span class="cdl-value cdl-card-nsx"></span>
                    </div>
                    <div class="cdl-card-row">
                        <span class="cdl-label">HSD:</span><span class="cdl-value cdl-card-hsd"></span>
                    </div>
                </div>
                <button type="button" class="cdl-card-del" title="Xóa tem"><i class="fa-solid fa-xmark"></i></button>
            </div>
        </div>
    </template>

    <script>
        window.CDL_CONFIG = {
            baseUrl: '?mod=coffee_date_label&controllers=coffee_date_label&action='
        };
    </script>
    <script src="public/js/coffee_date_label/coffee_date_label.js"></script>
    <script src="public/js/shared/check_database.js"></script>
    <script src="<?php echo asset_ver('public/js/shared/app_shell.js'); ?>"></script>
</body>

</html>
