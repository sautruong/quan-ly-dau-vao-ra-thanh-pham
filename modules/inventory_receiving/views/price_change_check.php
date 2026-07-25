<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Check biến động giá</title>
    <link rel="icon" href="public/images/logo/logo_vat_png.png" type="image/png">
    <link rel="stylesheet" href="public/css/reset.css">
    <link rel="stylesheet" href="public/css/all.css">
    <link rel="stylesheet" href="public/css/global.css">
    <!-- Mượn .ppc-modal*/.ppc-table/.pci-*/.pcb-* (modal biến động giá + giải thích giá vốn) -->
    <link rel="stylesheet" href="public/css/inventory_receiving/row_material_receiving.css">
    <link rel="stylesheet" href="public/css/inventory_receiving/price_change_check.css">
    <link rel="stylesheet" href="<?php echo asset_ver('public/css/shared/app_shell.css'); ?>">
</head>

<body>
    <div id="wrapper">
        <?php get_sidebar('app'); ?>
        <?php get_header('app'); ?>

        <div class="content">
            <div class="pcc-page-title">
                <h1><i class="fa-solid fa-magnifying-glass-dollar"></i> Check biến động giá</h1>
                <p class="pcc-page-hint">Nhập giá nhà cung cấp vừa báo để xem trước ảnh hưởng đến giá vốn/giá mua — trước khi quyết định đặt hàng.</p>
            </div>

            <div class="pcc-toolbar">
                <div class="pcc-field pcc-field-search">
                    <label>Tìm nguyên vật liệu / thành phẩm</label>
                    <div class="pcc-search-wrap" id="pcc-search-wrap">
                        <i class="fa-solid fa-magnifying-glass"></i>
                        <input type="text" id="pcc-search-input" placeholder="Nhập tên NVL hoặc thành phẩm..." autocomplete="off">
                        <ul class="pcc-search-dropdown" id="pcc-search-dropdown"></ul>
                    </div>
                    <div class="pcc-selected-chip" id="pcc-selected-chip" style="display:none;">
                        <span class="pcc-selected-type" id="pcc-selected-type"></span>
                        <span class="pcc-selected-name" id="pcc-selected-name"></span>
                        <button type="button" id="pcc-selected-clear" title="Bỏ chọn"><i class="fa-solid fa-xmark"></i></button>
                    </div>
                </div>
                <div class="pcc-field pcc-field-price">
                    <label for="pcc-new-price">Giá NCC báo</label>
                    <input type="text" id="pcc-new-price" inputmode="decimal" placeholder="Nhập giá NCC báo...">
                </div>
                <button type="button" class="pcc-btn-check" id="pcc-btn-check" disabled>
                    <i class="fa-solid fa-magnifying-glass"></i> Kiểm tra
                </button>
            </div>

            <div class="pcc-result" id="pcc-result"></div>
        </div>
    </div>

    <!-- Modal lịch sử biến động giá (purchase_price_changes) -->
    <div class="ppc-modal-overlay" id="pcc-history-modal-overlay">
        <div class="ppc-modal">
            <div class="ppc-modal-head">
                <h3 class="pcc-history-title">Lịch sử biến động giá</h3>
                <button type="button" class="ppc-modal-close" title="Đóng">×</button>
            </div>
            <div class="ppc-modal-body">
                <table class="ppc-table">
                    <thead><tr><th>Ngày biến động</th><th class="center">Giá cũ</th><th class="center">Giá mới</th><th class="center">Tỉ lệ biến động</th></tr></thead>
                    <tbody id="pcc-history-tbody"></tbody>
                </table>
            </div>
            <div class="ppc-modal-foot"><button type="button" class="ppc-modal-ok">Đóng</button></div>
        </div>
    </div>

    <!-- Modal giải thích giá vốn (thành phần/định mức/đơn giá/thành tiền) — trường hợp NVL -->
    <div class="ppc-modal-overlay" id="pcc-breakdown-modal-overlay">
        <div class="ppc-modal pcb-modal">
            <div class="ppc-modal-head">
                <h3 class="pcc-breakdown-title">Giải thích giá vốn</h3>
                <button type="button" class="ppc-modal-close" title="Đóng">×</button>
            </div>
            <div class="ppc-modal-body">
                <table class="ppc-table pcb-table">
                    <thead><tr>
                        <th>Thành phần</th><th class="center">Định mức</th>
                        <th class="center">Đơn giá cũ</th><th class="center">Đơn giá mới</th>
                        <th class="center">Thành tiền cũ</th><th class="center">Thành tiền mới</th>
                    </tr></thead>
                    <tbody id="pcc-breakdown-tbody"></tbody>
                    <tfoot id="pcc-breakdown-tfoot"></tfoot>
                </table>
            </div>
            <div class="ppc-modal-foot"><button type="button" class="ppc-modal-ok">Đóng</button></div>
        </div>
    </div>

    <script>
        window.PCC_CONFIG = {
            baseUrl: '?mod=inventory_receiving&controllers=inventory_receiving&action='
        };
    </script>
    <script src="public/js/inventory_receiving/price_change_check.js"></script>
    <script src="<?php echo asset_ver('public/js/shared/app_shell.js'); ?>"></script>
</body>

</html>
