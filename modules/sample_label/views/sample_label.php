<!DOCTYPE html>
<html lang="vi">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Tạo tem gửi mẫu</title>
    <link rel="icon" href="public/images/logo/logo_vat_png.png" type="image/png">
    <link rel="stylesheet" href="public/css/reset.css">
    <link rel="stylesheet" href="public/css/all.css">
    <link rel="stylesheet" href="public/css/global.css">
    <link rel="stylesheet" href="<?php echo asset_ver('public/css/shared/app_shell.css'); ?>">
    <link rel="stylesheet" href="public/css/sample_label/sample_label.css">
</head>

<body>
    <div id="wrapper">
        <?php get_sidebar('app'); ?>
        <?php get_header('app'); ?>

        <div class="content sl-content">

            <div class="sl-toolbar">
                <div class="sl-search-wrap">
                    <label class="sl-field-label">Tìm sản phẩm</label>
                    <i class="fa-solid fa-magnifying-glass sl-search-icon"></i>
                    <input type="text" id="sl-search" class="sl-search-input"
                        placeholder="Nhập tên sản phẩm rồi Tab/Enter để thêm (chọn được nhiều lần)..." autocomplete="off">
                    <ul class="sl-search-dropdown" id="sl-search-dropdown"></ul>
                </div>
                <div class="sl-toolbar-actions">
                    <button type="button" class="sl-btn sl-btn-reset" id="sl-btn-reset">
                        <i class="fa-solid fa-arrow-rotate-left"></i> Xóa tất cả
                    </button>
                    <button type="button" class="sl-btn sl-btn-print" id="sl-btn-print">
                        <i class="fa-solid fa-print"></i> In A4
                    </button>
                    <button type="button" class="btn-check-db" data-tables="products,app_settings">
                        <i class="fa-solid fa-database"></i> Check Database
                    </button>
                </div>
            </div>

            <div class="sl-grid" id="sl-grid">
                <p class="sl-grid-empty" id="sl-grid-empty">Chưa có tem nào. Tìm sản phẩm ở ô trên để thêm.</p>
            </div>

        </div>
    </div>

    <!-- Template 1 tem -->
    <template id="sl-tpl-card">
        <div class="sl-card-wrap">
            <div class="sl-card">
                <div class="sl-card-bar sl-card-bar-top"></div>
                <button type="button" class="sl-card-del" title="Xóa tem"><i class="fa-solid fa-xmark"></i></button>
                <div class="sl-card-body">
                    <div class="sl-card-head">
                        <div class="sl-card-logo-wrap">
                            <img src="public/images/logo/logo_vat_png.png" alt="logo" class="sl-card-logo">
                        </div>
                        <div class="sl-card-brand">VUA AN TOÀN</div>
                    </div>
                    <div class="sl-card-title">CÔNG TY TNHH VUA AN TOÀN KÍNH GỬI MẪU</div>

                    <div class="sl-card-row">
                        <span class="sl-card-label">Tên sản phẩm:</span>
                        <span class="sl-inline-editable">
                            <span class="sl-inline-text sl-card-name" spellcheck="false"></span>
                            <button type="button" class="sl-inline-edit" title="Sửa tên"><i class="fa-solid fa-pen"></i></button>
                        </span>
                    </div>
                    <div class="sl-card-row">
                        <span class="sl-card-label">Ngày sản xuất:</span>
                        <span class="sl-inline-editable">
                            <span class="sl-inline-text sl-card-date" spellcheck="false"></span>
                            <button type="button" class="sl-inline-edit" title="Sửa ngày"><i class="fa-solid fa-pen"></i></button>
                        </span>
                    </div>
                    <div class="sl-card-row">
                        <span class="sl-card-label">Hạn dùng:</span>
                        <span class="sl-shelf-toggle">
                            <button type="button" class="sl-shelf-opt active" data-shelf="12 tháng">12 tháng</button>
                            <button type="button" class="sl-shelf-opt" data-shelf="2 năm">2 năm</button>
                        </span>
                    </div>
                    <div class="sl-card-row">
                        <span class="sl-card-label">Khối lượng:</span>
                        <span class="sl-fixed-editable" data-field="quantity">
                            <span class="sl-fixed-text"></span>
                            <button type="button" class="sl-fixed-edit" title="Sửa (lưu dùng dài lâu)"><i class="fa-solid fa-pen"></i></button>
                        </span>
                    </div>
                    <div class="sl-card-row sl-card-row-block">
                        <span class="sl-card-label">Ghi chú:</span>
                        <span class="sl-fixed-editable" data-field="note">
                            <span class="sl-fixed-text"></span>
                            <button type="button" class="sl-fixed-edit" title="Sửa (lưu dùng dài lâu)"><i class="fa-solid fa-pen"></i></button>
                        </span>
                    </div>
                    <div class="sl-card-row sl-card-row-block">
                        <span class="sl-card-label">Cảnh báo:</span>
                        <span class="sl-fixed-editable" data-field="warning">
                            <span class="sl-fixed-text"></span>
                            <button type="button" class="sl-fixed-edit" title="Sửa (lưu dùng dài lâu)"><i class="fa-solid fa-pen"></i></button>
                        </span>
                    </div>
                    <div class="sl-card-row sl-card-maker">
                        <span class="sl-card-label">Nhà sản xuất:</span>
                        <span class="sl-card-maker-name">Công ty TNHH Vua An Toàn.</span>
                    </div>
                </div>
                <div class="sl-card-bar sl-card-bar-bottom"></div>
            </div>
        </div>
    </template>

    <script>
        window.SL_CONFIG = {
            baseUrl: '?mod=sample_label&controllers=sample_label&action=',
            texts: <?= json_encode($texts, JSON_UNESCAPED_UNICODE) ?>
        };
    </script>
    <script src="public/js/sample_label/sample_label.js"></script>
    <script src="public/js/shared/check_database.js"></script>
    <script src="<?php echo asset_ver('public/js/shared/app_shell.js'); ?>"></script>
</body>

</html>
