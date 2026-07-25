<?php
/**
 * Header + main-tab dùng chung cho 7 biểu mẫu VSATTP.
 * Cách dùng trong view:
 *   <?php require __DIR__ . '/_tabs.php'; vt_render_header('material_receiving', 'BIỂU MẪU QL VSATTP'); ?>
 */

if (!function_exists('vt_tabs')) {
    function vt_tabs()
    {
        return [
            'material_receiving'    => 'Phiếu tiếp nhận nguyên liệu đầu vào',
            'production_log'        => 'Sổ sản xuất theo lô/mẻ',
            'process_control'       => 'Phiếu kiểm soát quá trình',
            'finished_goods_ledger' => 'Sổ nhập – xuất kho thành phẩm',
            'sanitation_log'        => 'Sổ vệ sinh nhà xưởng – thiết bị',
            'health_training_log'   => 'Sổ theo dõi sức khỏe & tập huấn nhân viên',
            'traceability'          => 'Hồ sơ truy xuất nguồn gốc lô sản phẩm',
            'product_stock'         => 'Tồn kho sản phẩm',
            'material_stock'        => 'Tồn kho nguyên liệu',
        ];
    }
}

if (!function_exists('vt_render_header')) {
    function vt_render_header($active = '', $h1 = 'BIỂU MẪU QL VSATTP')
    {
        $base = '?mod=vsattp&controllers=vsattp&action=';
        ?>
        <?php get_sidebar('app'); ?>
        <?php get_header('app'); ?>
        <div class="header" style="display:none" aria-hidden="true">
            <input type="checkbox" id="menu-toggle" class="menu-toggle">
            <div class="wp-logo-title">
                <div class="logo">
                    <a href="?mod=home">
                        <img src="public/images/logo/logo_vat_png.png" alt="" style="width:40px">
                    </a>
                </div>
                <div class="title">
                    <h1><?php echo htmlspecialchars($h1, ENT_QUOTES, 'UTF-8'); ?></h1>
                </div>
                <label for="menu-toggle" class="menu-toggle-btn" aria-label="Mở menu">
                    <i class="fa-solid fa-bars"></i>
                </label>
            </div>
            <nav>
                <ul class="main-tab">
                    <?php foreach (vt_tabs() as $key => $label) : ?>
                        <li class="tab-item<?php echo $key === $active ? ' active' : ''; ?>">
                            <a href="<?php echo $base . $key; ?>"><?php echo htmlspecialchars($label, ENT_QUOTES, 'UTF-8'); ?></a>
                        </li>
                    <?php endforeach; ?>
                </ul>
            </nav>
        </div>
        <?php
    }
}

/**
 * Khung nội dung dùng chung cho các biểu mẫu chạy bằng engine vsattp_table.js.
 * $opts: form_title, hasProduct(bool), hasDate(bool), manual(bool)
 */
if (!function_exists('vt_render_form_page')) {
    function vt_render_form_page($opts = [])
    {
        $form_title = $opts['form_title'] ?? 'BIỂU MẪU';
        $hasProduct = !empty($opts['hasProduct']);
        $hasDate    = !empty($opts['hasDate']);
        $manual     = !empty($opts['manual']);
        $today      = date('Y-m-d');
        ?>
        <div class="content">
            <?php if ($hasDate || $hasProduct) : ?>
            <div class="vt-controls">
                <?php if ($hasDate) : ?>
                <div class="vt-daterange">
                    <div class="vt-field">
                        <label for="vt-from">Từ ngày</label>
                        <input type="date" id="vt-from" value="<?php echo $today; ?>">
                    </div>
                    <div class="vt-field">
                        <label for="vt-to">Đến ngày</label>
                        <input type="date" id="vt-to" value="<?php echo $today; ?>">
                    </div>
                </div>
                <?php endif; ?>
                <?php if ($hasProduct) : ?>
                <button type="button" class="vt-btn vt-btn-primary" id="vt-open-modal">
                    <i class="fa-solid fa-list-check"></i> Chọn sản phẩm hiển thị
                </button>
                <?php endif; ?>
            </div>
            <?php endif; ?>

            <div class="vt-table-actions">
                <div class="vt-actions-left">
                    <?php if ($manual) : ?>
                    <button type="button" class="vt-btn vt-btn-primary" id="vt-add-row">
                        <i class="fa-solid fa-plus"></i> Thêm dòng
                    </button>
                    <?php endif; ?>
                    <div class="vt-page-size">
                        <label for="vt-per-page">Số dòng/trang</label>
                        <select id="vt-per-page">
                            <option value="10">10</option>
                            <option value="20">20</option>
                            <option value="50">50</option>
                            <option value="100">100</option>
                            <option value="0">Tất cả</option>
                        </select>
                    </div>
                </div>
                <div class="vt-actions-right">
                    <button type="button" class="vt-btn vt-btn-excel" id="vt-export-excel">
                        <i class="fa-solid fa-file-excel"></i> Xuất excel
                    </button>
                    <button type="button" class="vt-btn vt-btn-print" id="vt-print">
                        <i class="fa-solid fa-print"></i> In biểu mẫu
                    </button>
                </div>
            </div>

            <div class="vt-sheet" id="vt-sheet">
                <div class="vt-sheet-head">
                    <p class="vt-company">Công ty TNHH Vua An Toàn</p>
                    <h2 class="vt-form-title"><?php echo htmlspecialchars($form_title, ENT_QUOTES, 'UTF-8'); ?></h2>
                    <?php if ($hasDate) : ?><p class="vt-period" id="vt-period"></p><?php endif; ?>
                </div>

                <table class="vt-table" id="vt-table">
                    <thead id="vt-thead"></thead>
                    <tbody id="vt-tbody"></tbody>
                </table>

                <div class="vt-pagination" id="vt-pagination"></div>

                <div class="vt-signatures">
                    <div class="vt-sign-col">
                        <p class="vt-sign-role">Người lập biểu</p>
                        <p class="vt-sign-note">(ký, ghi rõ họ tên)</p>
                    </div>
                    <div class="vt-sign-col">
                        <p class="vt-sign-role">Người kiểm tra</p>
                        <p class="vt-sign-note">(ký, ghi rõ họ tên)</p>
                    </div>
                    <div class="vt-sign-col">
                        <p class="vt-sign-role">Phụ trách cơ sở</p>
                        <p class="vt-sign-note">(ký, ghi rõ họ tên)</p>
                    </div>
                </div>
            </div>
        </div>

        <?php if ($hasProduct) : ?>
        <div class="vt-modal" id="vt-modal" aria-hidden="true">
            <div class="vt-modal-overlay" data-close-modal></div>
            <div class="vt-modal-box">
                <div class="vt-modal-head">
                    <h3>Chọn sản phẩm hiển thị</h3>
                    <button type="button" class="vt-modal-close" data-close-modal aria-label="Đóng">&times;</button>
                </div>
                <div class="vt-modal-body">
                    <div class="vt-search">
                        <i class="fa-solid fa-magnifying-glass"></i>
                        <input type="text" id="vt-search-input" placeholder="Tìm theo tên sản phẩm..." autocomplete="off">
                        <ul class="vt-search-dropdown" id="vt-search-dropdown"></ul>
                    </div>
                    <p class="vt-selected-label">Danh sách sản phẩm đã chọn:</p>
                    <ul class="vt-selected-list" id="vt-selected-list">
                        <li class="vt-selected-empty">Chưa chọn sản phẩm nào (để trống = lấy tất cả).</li>
                    </ul>
                </div>
                <div class="vt-modal-foot">
                    <button type="button" class="vt-btn" data-close-modal>Đóng</button>
                    <button type="button" class="vt-btn vt-btn-primary" id="vt-show-data">
                        <i class="fa-solid fa-table"></i> Hiển thị dữ liệu
                    </button>
                </div>
            </div>
        </div>
        <?php endif; ?>
        <?php
    }
}
