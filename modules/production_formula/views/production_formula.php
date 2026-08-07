<?php
// Thông tin tiêu đề phiếu Share (sửa 1 lần dùng nhiều lần — app_settings).
$pf_share = function_exists('pf_get_share_settings')
    ? pf_get_share_settings()
    : ['company_name' => 'VUA AN TOÀN', 'company_address' => '1/13Z Ấp Tiền Lân, Xã Bà Điểm, TP Hồ Chí Minh, Việt Nam'];
?>
<!DOCTYPE html>
<html lang="vi">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Công thức sản xuất</title>
    <link rel="icon" href="public/images/logo/logo_vat_png.png" type="image/png">
    <link rel="stylesheet" href="<?php echo asset_ver('public/css/reset.css'); ?>">
    <link rel="stylesheet" href="<?php echo asset_ver('public/css/all.css'); ?>">
    <link rel="stylesheet" href="<?php echo asset_ver('public/css/global.css'); ?>">
    <link rel="stylesheet" href="<?php echo asset_ver('public/css/shared/app_shell.css'); ?>">
    <link rel="stylesheet" href="<?php echo asset_ver('public/css/production_formula/production_formula.css'); ?>">
</head>

<body>
    <div id="wrapper" class="has-sider">
        <?php get_sidebar('app'); ?>
        <?php get_header('app'); ?>

        <div class="content pf-content">
            <!-- ===== Thanh tìm kiếm + công cụ ===== -->
            <div class="pf-search-bar">
                <div class="pf-search-wrap">
                    <i class="fa-solid fa-magnifying-glass pf-search-icon"></i>
                    <input type="text" id="pf-search" class="pf-search-input"
                        placeholder="Tìm sản phẩm cần xem công thức..." autocomplete="off">
                    <ul class="pf-search-dropdown" id="pf-search-dropdown"></ul>
                </div>

                <!-- Xem nhanh tồn (giống long_term_production_plan) -->
                <div class="pf-stock-lookup" id="pf-stock-lookup">
                    <input type="text" class="pf-stock-input" id="pf-stock-input" autocomplete="off"
                        spellcheck="false" placeholder="Nhập tên...">
                    <ul class="pf-stock-suggest" id="pf-stock-suggest"></ul>
                </div>
                <button type="button" class="pf-tool-btn" id="pf-stock-material" title="Xem nhanh tồn 1 nguyên vật liệu">
                    <i class="fa-solid fa-boxes-stacked"></i> Xem tồn NVL
                </button>
                <button type="button" class="pf-tool-btn" id="pf-stock-product" title="Xem nhanh tồn 1 thành phẩm">
                    <i class="fa-solid fa-box"></i> Xem tồn thành phẩm
                </button>
                <button type="button" class="pf-tool-btn" id="pf-gallery-btn" disabled
                    title="Xem toàn bộ hình ảnh nguyên liệu của công thức đang mở">
                    <i class="fa-solid fa-images"></i> Xem hình ảnh nguyên liệu
                </button>
                <?php if (permission_can_check_db('production_formula', 'production_formula', 'production_formula')): ?>
                <button type="button" class="btn-check-db pf-tool-btn"
                    data-tables="product_materials,material_information,material_inventory,product_recipe_notes,product_batch_recipes,product_batch_recipe_items,material_images,products">
                    <i class="fa-solid fa-database"></i> Check Database
                </button>
                <?php endif; ?>
            </div>

            <!-- ===== Đã xem gần đây ===== -->
            <div class="pf-recent-wrap" id="pf-recent-wrap" style="display:none;">
                <span class="pf-recent-label">Đã xem gần đây:</span>
                <div class="pf-recent-list" id="pf-recent-list"></div>
            </div>

            <!-- ===== Tabs ===== -->
            <div class="pf-tabs" id="pf-tabs">
                <button type="button" class="pf-tab active" data-tab="unit">Công thức 1 đơn vị</button>
                <button type="button" class="pf-tab" data-tab="batch">Công thức mẻ sản xuất</button>
            </div>

            <!-- Trạng thái rỗng -->
            <div class="pf-empty" id="pf-empty">
                <i class="fa-solid fa-flask-vial"></i>
                <p>Chọn một sản phẩm ở ô tìm kiếm để xem công thức sản xuất.</p>
            </div>

            <!-- ===== Khu vực chính ===== -->
            <div class="pf-main" id="pf-main" style="display:none;">

                <!-- ---------- TAB 1: Công thức 1 đơn vị ---------- -->
                <div class="pf-panel pf-panel-unit active" data-panel="unit">
                    <div class="pf-layout">
                        <div class="pf-recipe-card" id="pf-share-target">
                            <div class="pf-recipe-head">
                                <h2 class="pf-product-name" id="pf-product-name">—</h2>
                                <span class="pf-multiplier-badge" id="pf-multiplier-badge" style="display:none;"></span>
                                <button type="button" class="pf-unit-edit-toggle" id="pf-unit-edit-toggle"
                                    title="Chỉnh sửa công thức 1 đơn vị">
                                    <i class="fa-solid fa-lock"></i>
                                </button>
                            </div>

                            <table class="pf-recipe-table" id="pf-recipe-table">
                                <thead>
                                    <tr>
                                        <th class="pf-col-stt">STT</th>
                                        <th class="pf-col-name">
                                            <span class="pf-th-label">Nguyên liệu</span>
                                            <button type="button" class="pf-name-toggle" title="Chuyển đổi tên nguyên liệu (tên phổ thông ↔ tên thường gọi)">
                                                <i class="fa-solid fa-right-left"></i>
                                            </button>
                                        </th>
                                        <th class="pf-col-unit">Đơn vị</th>
                                        <th class="pf-col-qty">
                                            <span class="pf-qty-th-wrap">
                                                <span class="pf-qty-th-label">Số lượng</span>
                                                <span class="pf-qty-dec-ctrl" id="pf-qty-dec-ctrl" title="Số thập phân đang hiển thị">
                                                    <button type="button" class="pf-qty-dec-btn" id="pf-qty-dec-minus" title="Giảm số thập phân hiển thị">&larr;</button>
                                                    <button type="button" class="pf-qty-dec-btn" id="pf-qty-dec-plus" title="Tăng số thập phân hiển thị">&rarr;</button>
                                                </span>
                                            </span>
                                        </th>
                                        <th class="pf-col-act">Thao tác</th>
                                    </tr>
                                </thead>
                                <tbody id="pf-recipe-tbody"></tbody>
                            </table>

                            <div class="pf-add-row-wrap">
                                <button type="button" class="pf-add-row-btn" id="pf-add-item-btn">
                                    <i class="fa-solid fa-plus"></i> Thêm thành phần
                                </button>
                            </div>

                            <div class="pf-note-row">
                                <label for="pf-note">Ghi chú:</label>
                                <textarea id="pf-note" class="pf-note-input" rows="1"
                                    placeholder="Nhập ghi chú công thức.. (Alt+Enter để xuống dòng)"></textarea>
                                <span class="pf-note-status" id="pf-note-status"></span>
                            </div>

                            <div class="pf-total" id="pf-total">
                                Tổng thành phẩm: <strong><span id="pf-total-qty">1</span> <span id="pf-total-unit"></span></strong>
                            </div>
                        </div>

                        <aside class="pf-controls">
                            <h3 class="pf-controls-title"><i class="fa-solid fa-sliders"></i> Bảng điều khiển</h3>

                            <div class="pf-control-group">
                                <span class="pf-control-label">Nhân công thức theo mẻ</span>
                                <div class="pf-multipliers" id="pf-multipliers">
                                    <button type="button" class="pf-mul-btn active" data-mul="1">x1</button>
                                    <button type="button" class="pf-mul-btn" data-mul="2">x2</button>
                                    <button type="button" class="pf-mul-btn" data-mul="3">x3</button>
                                    <button type="button" class="pf-mul-btn" data-mul="4">x4</button>
                                    <button type="button" class="pf-mul-btn" data-mul="5">x5</button>
                                    <button type="button" class="pf-mul-btn" data-mul="10">x10</button>
                                    <button type="button" class="pf-mul-btn" data-mul="20">x20</button>
                                    <button type="button" class="pf-mul-btn" data-mul="50">x50</button>
                                    <button type="button" class="pf-mul-btn" data-mul="100">x100</button>
                                    <button type="button" class="pf-mul-btn" data-mul="200">x200</button>
                                </div>
                                <div class="pf-mul-custom">
                                    <label for="pf-mul-input">Hệ số khác</label>
                                    <input type="number" id="pf-mul-input" min="1" step="1" placeholder="vd: 7">
                                </div>
                            </div>

                            <div class="pf-save-batch-wrap" id="pf-save-batch-wrap" style="display:none;">
                                <input type="text" id="pf-batch-name" class="pf-batch-name-input"
                                    placeholder="Đặt tên công thức..." autocomplete="off">
                                <button type="button" class="pf-save-batch" id="pf-save-batch">
                                    <i class="fa-solid fa-floppy-disk"></i> Lưu công thức mẻ
                                </button>
                            </div>

                            <div class="pf-control-group">
                                <label class="pf-checkbox">
                                    <input type="checkbox" id="pf-only-material">
                                    <span>Chỉ hiển thị nguyên liệu</span>
                                </label>
                                <p class="pf-control-hint">Ẩn bao bì trong, bao bì ngoài, nhãn.</p>
                            </div>

                            <div class="pf-control-group">
                                <button type="button" class="pf-share-btn" id="pf-share-btn">
                                    <i class="fa-solid fa-share-nodes"></i> Share công thức
                                </button>
                            </div>

                            <div class="pf-stock-legend">
                                <span class="pf-dot pf-dot-warn"></span> Vượt tồn kho hiện tại
                            </div>
                        </aside>
                    </div>
                </div>

                <!-- ---------- TAB 2: Công thức mẻ sản xuất ---------- -->
                <div class="pf-panel pf-panel-batch" data-panel="batch">
                    <div class="pf-batch-scroll">
                        <div class="pf-batch-list-wrap">
                            <h3 class="pf-batch-title">Các công thức mẻ đã lưu</h3>
                            <div class="pf-batch-list" id="pf-batch-list"></div>
                        </div>
                        <div class="pf-batch-detail" id="pf-batch-detail" style="display:none;">
                            <div class="pf-batch-layout">
                                <div class="pf-recipe-card" id="pf-batch-share-target">
                                    <div class="pf-recipe-head">
                                        <h2 class="pf-product-name" id="pf-batch-product-name">—</h2>
                                        <span class="pf-multiplier-badge" id="pf-batch-mul-badge"></span>
                                    </div>
                                    <table class="pf-recipe-table">
                                        <thead>
                                            <tr>
                                                <th class="pf-col-stt">STT</th>
                                                <th class="pf-col-name">
                                                    <span class="pf-th-label">Nguyên liệu</span>
                                                    <button type="button" class="pf-name-toggle" title="Chuyển đổi tên nguyên liệu (tên phổ thông ↔ tên thường gọi)">
                                                        <i class="fa-solid fa-right-left"></i>
                                                    </button>
                                                </th>
                                                <th class="pf-col-unit pf-th-center">Đơn vị</th>
                                                <th class="pf-col-qty pf-th-center">Số lượng</th>
                                                <th class="pf-col-act"></th>
                                            </tr>
                                        </thead>
                                        <tbody id="pf-batch-tbody"></tbody>
                                    </table>
                                    <div class="pf-add-row-wrap">
                                        <button type="button" class="pf-add-row-btn" id="pf-batch-add-item-btn"
                                            title="Chọn nguyên liệu có sẵn trong danh mục hoặc gõ tên tự do">
                                            <i class="fa-solid fa-plus"></i> Thêm thành phần
                                        </button>
                                    </div>
                                    <div class="pf-batch-output-row" id="pf-batch-output-row">
                                        <label for="pf-batch-output-input">Tổng sản phẩm:</label>
                                        <input type="text" inputmode="decimal" id="pf-batch-output-input" class="pf-batch-output-input"
                                            placeholder="Tự động" title="Ghi đè tổng số thành phẩm thực tế tạo thành (để trống = tự tính theo hệ số nhân)">
                                        <span class="pf-batch-output-unit" id="pf-batch-output-unit"></span>
                                        <span class="pf-batch-output-status" id="pf-batch-output-status"></span>
                                    </div>
                                    <div class="pf-note-row pf-note-readonly" id="pf-batch-note-row">
                                        <label>Ghi chú:</label>
                                        <span id="pf-batch-note"></span>
                                        <span class="pf-batch-note-actions" id="pf-batch-note-actions">
                                            <button type="button" class="pf-batch-note-edit" id="pf-batch-note-edit" title="Sửa ghi chú"><i class="fa-solid fa-pen"></i></button>
                                            <button type="button" class="pf-batch-note-del" id="pf-batch-note-del" title="Xóa ghi chú"><i class="fa-solid fa-trash"></i></button>
                                        </span>
                                        <button type="button" class="pf-batch-note-add" id="pf-batch-note-add">
                                            <i class="fa-solid fa-plus"></i> Thêm ghi chú
                                        </button>
                                    </div>
                                    <div class="pf-total">
                                        Tổng thành phẩm: <strong><span id="pf-batch-total-qty">—</span> <span id="pf-batch-total-unit"></span></strong>
                                    </div>
                                </div>

                                <aside class="pf-batch-controls">
                                    <div class="pf-control-group pf-batch-scale-wrap">
                                        <span class="pf-control-label">Nhân công thức trước khi xem/share (không lưu)</span>
                                        <div class="pf-multipliers" id="pf-batch-multipliers">
                                            <button type="button" class="pf-mul-btn active" data-mul="1">x1</button>
                                            <button type="button" class="pf-mul-btn" data-mul="2">x2</button>
                                            <button type="button" class="pf-mul-btn" data-mul="3">x3</button>
                                            <button type="button" class="pf-mul-btn" data-mul="4">x4</button>
                                            <button type="button" class="pf-mul-btn" data-mul="5">x5</button>
                                            <button type="button" class="pf-mul-btn" data-mul="10">x10</button>
                                            <button type="button" class="pf-mul-btn" data-mul="20">x20</button>
                                            <button type="button" class="pf-mul-btn" data-mul="50">x50</button>
                                            <button type="button" class="pf-mul-btn" data-mul="100">x100</button>
                                            <button type="button" class="pf-mul-btn" data-mul="200">x200</button>
                                        </div>
                                        <div class="pf-mul-custom">
                                            <label for="pf-batch-mul-input">Hệ số khác</label>
                                            <input type="number" id="pf-batch-mul-input" min="1" step="1" placeholder="vd: 7">
                                        </div>
                                    </div>

                                    <div class="pf-batch-actions">
                                        <button type="button" class="pf-share-btn" id="pf-batch-share-btn">
                                            <i class="fa-solid fa-share-nodes"></i> Share công thức
                                        </button>
                                        <button type="button" class="pf-batch-dup" id="pf-batch-dup">
                                            <i class="fa-solid fa-copy"></i> Nhân bản
                                        </button>
                                        <button type="button" class="pf-batch-delete" id="pf-batch-delete">
                                            <i class="fa-solid fa-trash"></i> Xóa công thức mẻ
                                        </button>
                                    </div>
                                </aside>
                            </div>
                        </div>
                    </div>
                </div>

            </div>
        </div>
    </div>

    <!-- ===== Modal Share (phong cách hóa đơn) ===== -->
    <div class="pf-modal" id="pf-modal" style="display:none;">
        <div class="pf-modal-overlay" id="pf-modal-overlay"></div>
        <div class="pf-modal-content">
            <div class="pf-modal-toolbar">
                <button type="button" class="pf-modal-share" id="pf-modal-share">
                    <i class="fa-solid fa-camera"></i> Share
                </button>
                <button type="button" class="pf-modal-close" id="pf-modal-close">Đóng</button>
            </div>
            <div class="pf-share-sheet" id="pf-share-sheet">
                <div class="pf-share-deco pf-share-deco-top"></div>
                <div class="pf-share-header">
                    <div class="pf-share-group-a">
                        <div class="pf-share-logo-wrap">
                            <img src="public/images/logo/logo_vat_png.png" alt="logo" class="pf-share-logo">
                        </div>
                        <div class="pf-share-titles">
                            <h1 class="pf-share-editable" data-share-key="company_name"><span class="pf-share-etext"><?php echo htmlspecialchars($pf_share['company_name'], ENT_QUOTES, 'UTF-8'); ?></span><button type="button" class="pf-share-edit" title="Sửa nội dung"><i class="fa-solid fa-pen"></i></button></h1>
                            <h4 class="pf-share-editable" data-share-key="company_address" data-multiline="1"><span class="pf-share-etext"><?php echo htmlspecialchars($pf_share['company_address'], ENT_QUOTES, 'UTF-8'); ?></span><button type="button" class="pf-share-edit" title="Sửa nội dung"><i class="fa-solid fa-pen"></i></button></h4>
                        </div>
                    </div>
                    <div class="pf-share-doctitle">CÔNG THỨC SẢN XUẤT</div>
                </div>
                <div class="pf-share-rope"></div>
                <h2 class="pf-share-product-title" id="pf-share-product">—</h2>
                <table class="pf-share-table">
                    <thead>
                        <tr>
                            <th class="ss-stt">STT</th>
                            <th class="ss-name">Thành phần</th>
                            <th class="ss-unit">Đơn vị</th>
                            <th class="ss-qty">Số lượng</th>
                        </tr>
                    </thead>
                    <tbody id="pf-share-tbody"></tbody>
                </table>
                <div class="pf-share-total" id="pf-share-total"></div>
                <div class="pf-share-note" id="pf-share-note" style="display:none;"></div>
                <div class="pf-share-deco pf-share-deco-bottom"></div>
            </div>
        </div>
    </div>

    <!-- ===== Modal Hình ảnh nguyên liệu ===== -->
    <div class="pf-img-modal" id="pf-img-modal" style="display:none;">
        <div class="pf-img-overlay" id="pf-img-overlay"></div>
        <div class="pf-img-content">
            <div class="pf-img-head">
                <h3 id="pf-img-title">Hình ảnh nguyên liệu</h3>
                <button type="button" class="pf-img-close" id="pf-img-close">&times;</button>
            </div>
            <div class="pf-img-gallery" id="pf-img-gallery"></div>
            <div class="pf-img-foot">
                <label class="pf-img-add">
                    <i class="fa-solid fa-plus"></i> Thêm ảnh
                    <input type="file" id="pf-img-input" accept="image/*" multiple hidden>
                </label>
                <span class="pf-img-hint"><i class="fa-regular fa-clipboard"></i> Hoặc copy ảnh rồi bấm <b>Ctrl + V</b> để dán</span>
                <span class="pf-img-status" id="pf-img-status"></span>
            </div>
        </div>
    </div>

    <!-- ===== Modal Quy đổi đơn vị (tab Công thức mẻ sản xuất) ===== -->
    <div class="pf-unit-conv-modal" id="pf-unit-conv-modal" style="display:none;">
        <div class="pf-unit-conv-overlay" id="pf-unit-conv-overlay"></div>
        <div class="pf-unit-conv-content">
            <div class="pf-unit-conv-head">
                <h3>Quy đổi đơn vị</h3>
                <button type="button" class="pf-unit-conv-close" id="pf-unit-conv-close">&times;</button>
            </div>
            <div class="pf-unit-conv-body">
                <div class="pf-unit-conv-row">
                    <label>Đơn vị hiện tại (đvht):</label>
                    <span id="pf-uc-base-unit">—</span>
                </div>
                <div class="pf-unit-conv-row">
                    <label for="pf-uc-conv-unit">Đơn vị quy đổi (đvqđ):</label>
                    <input type="text" id="pf-uc-conv-unit" autocomplete="off" placeholder="vd: thùng">
                </div>
                <div class="pf-unit-conv-row">
                    <label for="pf-uc-ratio">Tỉ lệ đvqđ/đvht:</label>
                    <input type="number" id="pf-uc-ratio" min="0" step="any" placeholder="vd: 25">
                </div>
            </div>
            <div class="pf-unit-conv-foot">
                <button type="button" class="pf-uc-ok" id="pf-uc-ok">OK</button>
            </div>
        </div>
    </div>

    <!-- ===== Modal Thông tin nguyên liệu (click tên NVL, tab Công thức mẻ sản xuất) ===== -->
    <div class="pf-matinfo-modal" id="pf-matinfo-modal" style="display:none;">
        <div class="pf-matinfo-overlay" id="pf-matinfo-overlay"></div>
        <div class="pf-matinfo-content">
            <div class="pf-matinfo-head">
                <h3 id="pf-matinfo-name">—</h3>
                <button type="button" class="pf-matinfo-close" id="pf-matinfo-close">&times;</button>
            </div>
            <div class="pf-matinfo-body">
                <div class="pf-matinfo-row">
                    <label>Tên hệ thống:</label>
                    <span id="pf-matinfo-sysname">—</span>
                </div>
                <div class="pf-matinfo-row">
                    <label>Đơn vị:</label>
                    <span id="pf-matinfo-unit">—</span>
                </div>
                <div class="pf-matinfo-row">
                    <label>Tồn kho hiện tại:</label>
                    <span id="pf-matinfo-stock">—</span>
                </div>
                <div class="pf-matinfo-row pf-matinfo-usage-row">
                    <label>Định mức dùng:</label>
                    <div class="pf-matinfo-usage">
                        <div class="pf-matinfo-usage-item"><span class="pf-matinfo-usage-label">1 tháng</span><span id="pf-matinfo-use1m" class="pf-matinfo-usage-val">—</span></div>
                        <div class="pf-matinfo-usage-item"><span class="pf-matinfo-usage-label">3 tháng</span><span id="pf-matinfo-use3m" class="pf-matinfo-usage-val">—</span></div>
                        <div class="pf-matinfo-usage-item"><span class="pf-matinfo-usage-label">6 tháng</span><span id="pf-matinfo-use6m" class="pf-matinfo-usage-val">—</span></div>
                    </div>
                </div>
                <div class="pf-matinfo-row pf-matinfo-products-row">
                    <label>Các sản phẩm dùng NL này:</label>
                    <ul class="pf-matinfo-products" id="pf-matinfo-products"></ul>
                </div>
            </div>
        </div>
    </div>

    <!-- ===== Modal Gallery ảnh nguyên liệu (cả công thức) ===== -->
    <div class="pf-gallery-modal" id="pf-gallery-modal" style="display:none;">
        <div class="pf-gallery-overlay" id="pf-gallery-overlay"></div>
        <div class="pf-gallery-content">
            <div class="pf-gallery-toolbar">
                <h3>Hình ảnh nguyên liệu</h3>
                <div class="pf-gallery-actions">
                    <button type="button" class="pf-gallery-share" id="pf-gallery-share">
                        <i class="fa-solid fa-camera"></i> Share list hình ảnh
                    </button>
                    <button type="button" class="pf-gallery-close" id="pf-gallery-close">Đóng</button>
                </div>
            </div>
            <div class="pf-gallery-sheet" id="pf-gallery-sheet">
                <h4 class="pf-gallery-sheet-title">Hình ảnh nguyên liệu — <span id="pf-gallery-product-name"></span></h4>
                <div class="pf-gallery-grid" id="pf-gallery-grid"></div>
            </div>
        </div>
    </div>

    <!-- Lightbox xem ảnh lớn: xoay trái/phải, lăn chuột phóng to/thu nhỏ, share qua chat -->
    <div class="pf-lightbox" id="pf-lightbox" style="display:none;">
        <div class="pf-lightbox-toolbar no-print">
            <button type="button" class="pf-lightbox-btn" id="pf-lightbox-rotate-left" title="Xoay trái"><i class="fa-solid fa-rotate-left"></i></button>
            <button type="button" class="pf-lightbox-btn" id="pf-lightbox-rotate-right" title="Xoay phải"><i class="fa-solid fa-rotate-right"></i></button>
            <button type="button" class="pf-lightbox-btn" id="pf-lightbox-zoom-out" title="Thu nhỏ"><i class="fa-solid fa-magnifying-glass-minus"></i></button>
            <button type="button" class="pf-lightbox-btn" id="pf-lightbox-zoom-in" title="Phóng to"><i class="fa-solid fa-magnifying-glass-plus"></i></button>
            <button type="button" class="pf-lightbox-btn" id="pf-lightbox-reset" title="Đặt lại"><i class="fa-solid fa-arrows-rotate"></i></button>
            <button type="button" class="pf-lightbox-btn pf-lightbox-share-btn" id="pf-lightbox-share"><i class="fa-solid fa-share-nodes"></i> Share qua chat</button>
        </div>
        <span class="pf-lightbox-close" id="pf-lightbox-close">&times;</span>
        <div class="pf-lightbox-stage" id="pf-lightbox-stage">
            <img id="pf-lightbox-img" src="" alt="">
        </div>
    </div>

    <!-- Template 1 dòng công thức -->
    <template id="pf-row-template">
        <tr class="pf-row" draggable="false">
            <td class="pf-cell-stt">
                <span class="pf-drag-handle" title="Kéo để sắp xếp"><i class="fa-solid fa-grip-vertical"></i></span>
                <span class="pf-stt-num"></span>
            </td>
            <td class="pf-cell-name">
                <div class="pf-name-wrap">
                    <input type="text" class="pf-name-input" autocomplete="off" placeholder="Tên nguyên liệu...">
                    <ul class="pf-name-dropdown"></ul>
                </div>
            </td>
            <td class="pf-cell-unit">
                <input type="text" class="pf-unit-input" autocomplete="off" placeholder="đv">
            </td>
            <td class="pf-cell-qty">
                <input type="text" class="pf-qty-input" inputmode="decimal" value="0">
                <span class="pf-qty-warn" title="Vượt tồn kho"><i class="fa-solid fa-triangle-exclamation"></i></span>
            </td>
            <td class="pf-cell-act">
                <button type="button" class="pf-norm-btn" title="Thay đổi định mức (quy đổi tam suất các thành phần còn lại)">
                    <i class="fa-solid fa-scale-balanced"></i>
                </button>
                <button type="button" class="pf-img-btn" title="Hình ảnh nguyên liệu">
                    <i class="fa-solid fa-image"></i>
                    <span class="pf-img-count"></span>
                </button>
                <button type="button" class="pf-del-row" title="Xóa thành phần này">
                    <i class="fa-solid fa-trash"></i>
                </button>
            </td>
        </tr>
    </template>

    <script>
        window.PF_CONFIG = {
            baseUrl: '?mod=production_formula&controllers=production_formula&action='
        };
    </script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/html2canvas/1.4.1/html2canvas.min.js"></script>
    <script src="<?php echo asset_ver('public/js/production_formula/production_formula.js'); ?>"></script>
    <script src="<?php echo asset_ver('public/js/shared/check_database.js'); ?>"></script>
    <script src="<?php echo asset_ver('public/js/shared/app_shell.js'); ?>"></script>
</body>

</html>
