<!DOCTYPE html>
<html lang="vi">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Tạo tem nguyên liệu trà</title>
    <link rel="icon" href="public/images/logo/logo_vat_png.png" type="image/png">
    <link rel="stylesheet" href="<?php echo asset_ver('public/css/reset.css'); ?>">
    <link rel="stylesheet" href="<?php echo asset_ver('public/css/all.css'); ?>">
    <link rel="stylesheet" href="<?php echo asset_ver('public/css/global.css'); ?>">
    <link rel="stylesheet" href="<?php echo asset_ver('public/css/shared/app_shell.css'); ?>">
    <link rel="stylesheet" href="<?php echo asset_ver('public/css/tea_label/tea_label.css'); ?>">
</head>

<body>
    <div id="wrapper" class="has-sider">
        <?php get_sidebar('app'); ?>
        <?php get_header('app'); ?>

        <div class="content tl-content">

            <!-- ===== Khối Thiết lập ===== -->
            <div class="tl-setup">
                <h2 class="tl-block-title"><i class="fa-solid fa-sliders"></i> Thiết lập</h2>

                <div class="tl-setup-row">
                    <div class="tl-search-wrap">
                        <label class="tl-field-label">Tên nguyên liệu</label>
                        <i class="fa-solid fa-magnifying-glass tl-search-icon"></i>
                        <input type="text" id="tl-material" class="tl-search-input"
                            placeholder="Nhập tên nguyên liệu..." autocomplete="off">
                        <ul class="tl-search-dropdown" id="tl-material-dropdown"></ul>
                    </div>

                    <div class="tl-search-wrap">
                        <label class="tl-field-label">Nhà cung cấp</label>
                        <i class="fa-solid fa-magnifying-glass tl-search-icon"></i>
                        <input type="text" id="tl-supplier" class="tl-search-input"
                            placeholder="Tìm nhà cung cấp..." autocomplete="off">
                        <ul class="tl-search-dropdown" id="tl-supplier-dropdown"></ul>
                        <div class="tl-supplier-address" id="tl-supplier-address"></div>
                    </div>
                </div>

                <div class="tl-setup-row">
                    <div class="tl-field">
                        <label class="tl-field-label">Loại trà</label>
                        <div class="tl-tea-types" id="tl-tea-types">
                            <button type="button" class="tl-tea-btn active" data-tea="Trà đen">Trà đen</button>
                            <button type="button" class="tl-tea-btn" data-tea="Trà ô long">Trà ô long</button>
                            <button type="button" class="tl-tea-btn" data-tea="Trà xanh">Trà xanh</button>
                        </div>
                    </div>

                    <div class="tl-field">
                        <label class="tl-field-label" for="tl-qty">Số lượng nhập</label>
                        <input type="number" id="tl-qty" class="tl-num-input" min="0" step="1" placeholder="vd: 300">
                    </div>

                    <div class="tl-field">
                        <label class="tl-field-label" for="tl-spec">Chia quy cách (kg/bao)</label>
                        <input type="number" id="tl-spec" class="tl-num-input" min="1" step="1" placeholder="vd: 30">
                    </div>

                    <div class="tl-field">
                        <label class="tl-field-label" for="tl-date-display">Ngày sản xuất</label>
                        <div class="tl-date-picker" id="tl-date-picker">
                            <button type="button" class="tl-date-display" id="tl-date-display"></button>
                            <input type="hidden" id="tl-date" value="<?= htmlspecialchars($today, ENT_QUOTES, 'UTF-8') ?>">
                            <div class="tl-date-cal" id="tl-date-cal"></div>
                        </div>
                    </div>
                </div>

                <div class="tl-setup-actions">
                    <button type="button" class="tl-btn tl-btn-reset" id="tl-btn-reset">
                        <i class="fa-solid fa-arrow-rotate-left"></i> Reset
                    </button>
                    <button type="button" class="tl-btn tl-btn-make" id="tl-btn-make">
                        <i class="fa-solid fa-tags"></i> Tạo tem
                    </button>
                </div>
            </div>

            <!-- ===== Khối Tem hiển thị ===== -->
            <div class="tl-generated">
                <div class="tl-generated-head">
                    <h2 class="tl-block-title"><i class="fa-solid fa-tags"></i> Tem hiển thị</h2>
                    <button type="button" class="tl-btn tl-btn-print" id="tl-btn-print">
                        <i class="fa-solid fa-print"></i> In A4
                    </button>
                </div>
                <div class="tl-grid" id="tl-grid">
                    <p class="tl-grid-empty" id="tl-grid-empty">Chưa có tem nào. Điền Thiết lập rồi bấm "Tạo tem".</p>
                </div>
            </div>

        </div>
    </div>

    <!-- ===== Modal xem trước khi in ===== -->
    <div class="tl-print-modal" id="tl-print-modal" aria-hidden="true">
        <div class="tl-print-modal-overlay" id="tl-print-modal-overlay"></div>
        <div class="tl-print-modal-box">
            <div class="tl-print-modal-head">
                <h3><i class="fa-solid fa-print"></i> Xem trước khi in</h3>
                <button type="button" class="tl-print-modal-close" id="tl-print-modal-close"><i class="fa-solid fa-xmark"></i></button>
            </div>
            <p class="tl-print-modal-hint">Đường nét đứt đỏ đánh dấu vị trí chuyển sang nguyên liệu khác.</p>
            <div class="tl-print-modal-body">
                <div class="tl-print-preview" id="tl-print-preview"></div>
            </div>
            <div class="tl-print-modal-foot">
                <button type="button" class="tl-btn tl-btn-reset" id="tl-print-modal-cancel">Đóng</button>
                <button type="button" class="tl-btn tl-btn-make" id="tl-print-modal-go"><i class="fa-solid fa-print"></i> In ngay</button>
            </div>
        </div>
    </div>

    <!-- Template 1 tem -->
    <template id="tl-tpl-card">
        <div class="tl-card-wrap">
            <div class="tl-card">
                <div class="tl-card-bar tl-card-bar-top"></div>
                <button type="button" class="tl-card-del" title="Xóa tem"><i class="fa-solid fa-xmark"></i></button>
                <div class="tl-card-body">
                    <div class="tl-card-name" contenteditable="true" spellcheck="false"></div>

                    <div class="tl-card-row">
                        <span class="tl-card-label">Công dụng:</span>
                        <span class="tl-fixed-editable" data-field="usage"><span class="tl-fixed-text"></span><button type="button" class="tl-fixed-edit" title="Sửa (lưu dùng dài lâu)"><i class="fa-solid fa-pen"></i></button></span>
                    </div>
                    <div class="tl-card-row">
                        <span class="tl-card-label">Ngày sản xuất:</span>
                        <span class="tl-card-date"></span>
                    </div>
                    <div class="tl-card-row">
                        <span class="tl-card-label">Hạn sử dụng:</span>
                        <span class="tl-fixed-editable" data-field="shelf_life"><span class="tl-fixed-text"></span><button type="button" class="tl-fixed-edit" title="Sửa (lưu dùng dài lâu)"><i class="fa-solid fa-pen"></i></button></span>
                    </div>
                    <div class="tl-card-row">
                        <span class="tl-card-label">Thành phần:</span>
                        <span class="tl-card-composition" contenteditable="true"></span>
                    </div>
                    <div class="tl-card-row">
                        <span class="tl-card-label">Bảo quản:</span>
                        <span class="tl-fixed-editable" data-field="storage"><span class="tl-fixed-text"></span><button type="button" class="tl-fixed-edit" title="Sửa (lưu dùng dài lâu)"><i class="fa-solid fa-pen"></i></button></span>
                    </div>

                    <div class="tl-card-company"></div>
                    <div class="tl-card-supplier-address"></div>
                </div>
                <div class="tl-card-bar tl-card-bar-bottom"></div>
            </div>
        </div>
    </template>

    <script>
        window.TL_CONFIG = {
            baseUrl: '?mod=tea_label&controllers=tea_label&action=',
            texts: <?= json_encode($texts, JSON_UNESCAPED_UNICODE) ?>
        };
    </script>
    <script src="<?php echo asset_ver('public/js/tea_label/tea_label.js'); ?>"></script>
    <script src="<?php echo asset_ver('public/js/shared/check_database.js'); ?>"></script>
    <script src="<?php echo asset_ver('public/js/shared/app_shell.js'); ?>"></script>
</body>

</html>
