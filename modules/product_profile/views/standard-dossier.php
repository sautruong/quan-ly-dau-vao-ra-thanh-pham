<?php global $config; ?>
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Bộ hồ sơ sản phẩm chuẩn</title>
    <link rel="icon" href="public/images/logo/logo_vat_png.png" type="image/png">
    <link rel="stylesheet" href="public/css/reset.css">
    <link rel="stylesheet" href="public/css/all.css">
    <link rel="stylesheet" href="public/css/global.css">
    <link rel="stylesheet" href="public/css/product_profile/detail.css">
    <link rel="stylesheet" href="public/css/product_profile/modal.css">
    <link rel="stylesheet" href="public/css/product_profile/standard.css">

    <script src="public/js/jquery-4.0.0.js" type="text/javascript" defer></script>
    <!-- modal dùng chung (mở/đóng) -->
    <script src="public/js/product_profile_modal.js" defer></script>
    <!-- rename file (cây bút) -->
    <script src="public/js/product_profile_dragdrop.js" defer></script>
    <!-- kéo sắp xếp + xóa thành phần -->
    <script src="public/js/product_profile_sortable.js" defer></script>
    <!-- tạo bộ hồ sơ (ghi cây thư mục) -->
    <script src="public/js/product_profile_dossier.js" defer></script>
    <!-- tìm kiếm + xóa file + thêm thành phần (AJAX) -->
    <script src="public/js/product_profile_standard.js" defer></script>
    <!-- modal "Hóa đơn, chứng từ còn thiếu" (chụp, in, lọc, tải ZIP) -->
    <script src="public/js/product_profile_standard_missing.js" defer></script>
    <!-- modal "Hóa đơn, chứng từ đã có" (chụp, in, lọc, tải ZIP) -->
    <script src="public/js/product_profile_standard_existing.js" defer></script>
    <!-- Chụp ảnh modal -->
    <script src="https://cdnjs.cloudflare.com/ajax/libs/html2canvas/1.4.1/html2canvas.min.js"></script>
    <!-- Nén ZIP tải xuống -->
    <script src="https://cdnjs.cloudflare.com/ajax/libs/jszip/3.10.1/jszip.min.js"></script>
    <link rel="stylesheet" href="<?php echo asset_ver('public/css/shared/app_shell.css'); ?>">
</head>
<style>
    #name-dashboard { display: flex; gap: 10px; align-items: center; }
    h1 { margin-bottom: 0; }
</style>

<body>
    <div id="wrapper">
        <div id="sidebar-right">
            <?php require "layouts/top-sidebar-right.php"; ?>
            <div class="main-content">

                <div class="sd-toolbar">
                    <div class="search-box">
                        <i class="fa-solid fa-magnifying-glass search-icon"></i>
                        <input type="text" id="sd-search" class="search-input" placeholder="Tìm sản phẩm trong bộ hồ sơ chuẩn...">
                    </div>
                    <select id="sd-nutrition-filter" class="wp-nutrition-filter" title="Lọc theo trạng thái công bố dinh dưỡng">
                        <option value="all">Tất cả</option>
                        <option value="has">SP có công bố dinh dưỡng</option>
                        <option value="confirmed">SP đã công bố dinh dưỡng</option>
                    </select>
                    <button type="button" class="btn-missing-docs" id="sd-btn-missing-docs" title="Xem hóa đơn, chứng từ còn thiếu (toàn bộ Bộ hồ sơ chuẩn)">
                        <i class="fa-solid fa-triangle-exclamation"></i> Hóa đơn, chứng từ còn thiếu
                    </button>
                    <button type="button" class="btn-existing-docs" id="sd-btn-existing-docs" title="Xem hóa đơn, chứng từ đã có (toàn bộ Bộ hồ sơ chuẩn)">
                        <i class="fa-solid fa-circle-check"></i> Hóa đơn, chứng từ đã có
                    </button>
                    <div class="sd-count"><strong><?= count($products) ?></strong> bộ hồ sơ sản phẩm chuẩn.</div>
                </div>

                <?php if (empty($products)): ?>
                    <p class="sd-empty">Chưa có sản phẩm nào trong bộ hồ sơ chuẩn. Vào tab "Danh sách sản phẩm" và tích "Bộ hồ sơ chuẩn".</p>
                <?php else: ?>
                    <div class="sd-grid">
                        <?php foreach ($products as $prod): ?>
                            <div class="sd-card" data-product-id="<?= $prod['product_id'] ?>" data-product-name="<?= mb_strtolower($prod['product_name'], 'UTF-8') ?>"
                                data-has-nutrition="<?= $prod['has_nutrition_fact'] == 1 ? 1 : 0 ?>"
                                data-nutrition-confirmed="<?= !empty($prod['nutrition_fact_confirmed']) ? 1 : 0 ?>">
                                <div class="sd-card-head">
                                    <?php if (!empty($prod['is_complete'])): ?>
                                        <i class="fa-solid fa-circle-check sd-complete-badge" title="Hồ sơ đầy đủ"></i>
                                    <?php endif; ?>
                                    <a class="sd-product-name" href="?mod=product_profile&controllers=product_profile&action=product_detail&id=<?= $prod['product_id'] ?>" title="Xem chi tiết sản phẩm"><?= htmlspecialchars($prod['product_name']) ?></a>
                                </div>

                                <div class="sd-card-body">
                                <!-- Hồ sơ sản phẩm -->
                                <div class="sd-section">
                                    <div class="sd-section-title">Hồ sơ sản phẩm</div>
                                    <ul class="list-file">
                                        <?php foreach ($prod['files'] as $f): ?>
                                            <li class="file-item">
                                                <div class="name-file file-name-edit">
                                                    <a href="<?= htmlspecialchars($f['web_path']) ?>" target="_blank"><?= htmlspecialchars($f['file_name']) ?></a>
                                                    <a href="#" class="btn-edit-filename" data-kind="product" data-file-id="<?= $f['id'] ?>" title="Sửa tên file"><i class="fa-solid fa-pen"></i></a>
                                                </div>
                                                <div class="operation">
                                                    <a href="?mod=product_profile&controllers=product_profile&action=download_file&id_file=<?= $f['id'] ?>" title="Tải xuống"><i class="fa-solid fa-download"></i></a>
                                                    <button type="button" class="sd-del-product-file" data-file-id="<?= $f['id'] ?>" title="Xóa file"><i class="fa-regular fa-trash-can"></i></button>
                                                </div>
                                            </li>
                                        <?php endforeach; ?>
                                        <?php if (empty($prod['files'])): ?><li class="sd-none">Chưa có file.</li><?php endif; ?>
                                    </ul>
                                </div>

                                <!-- Thành phần cấu tạo -->
                                <div class="sd-section">
                                    <div class="sd-section-title sd-section-title-row">
                                        <span>Thành phần cấu tạo</span>
                                        <button type="button" class="sd-add-material" data-pp-open="#sd-add-composition-modal" data-product-id="<?= $prod['product_id'] ?>" title="Thêm thành phần">
                                            <i class="fa-solid fa-plus"></i>
                                        </button>
                                    </div>
                                    <ul class="list-ingredient" data-product-id="<?= $prod['product_id'] ?>">
                                        <?php foreach ($prod['composition'] as $item): ?>
                                            <li class="ingredient-item" data-bom-id="<?= $item['bom_id'] ?>">
                                                <?php
                                                    // Hiển thị tên thường gọi NVL + tên viết tắt NCC (fallback về tên đầy đủ nếu chưa đặt).
                                                    $sd_material_display = trim((string) ($item['common_material_name'] ?? '')) !== '' ? $item['common_material_name'] : $item['material_name'];
                                                    $sd_supplier_display = trim((string) ($item['supplier_short_name'] ?? '')) !== '' ? $item['supplier_short_name'] : $item['supplier_name'];
                                                ?>
                                                <div class="name-ingredient">
                                                    <span class="drag-handle" title="Kéo để sắp xếp"><i class="fa-solid fa-grip-vertical"></i></span>
                                                    <p><strong><?= htmlspecialchars($sd_material_display) ?></strong> - <?= htmlspecialchars($sd_supplier_display) ?></p>
                                                    <button type="button" class="btn-delete-composition" data-bom-id="<?= $item['bom_id'] ?>" data-name="<?= htmlspecialchars($item['material_name'], ENT_QUOTES) ?>" title="Xóa thành phần"><i class="fa-solid fa-xmark"></i></button>
                                                </div>
                                                <div class="container-content-ingredient">
                                                    <?php /* "Thiết lập NCC" tắt "Xét hồ sơ" cho NCC này -> ẩn cả khối "Hồ sơ nhà cung cấp" (giống product_detail). */ ?>
                                                    <div class="wp-supplier-profile"<?= empty($item['supplier_check_profile']) ? ' style="display:none"' : '' ?>>
                                                        <div class="wp-title">
                                                            <div class="level-1"><p> - Hồ sơ nhà cung cấp</p></div>
                                                        </div>
                                                        <ul class="list-file">
                                                            <?php foreach (($item['files']['supplier'] ?? []) as $fs): ?>
                                                                <li class="file-item">
                                                                    <div class="level-2">--</div>
                                                                    <div class="name-file file-name-edit">
                                                                        <a href="<?= $config['base_url'] . $fs['file_path'] ?>" target="_blank"><?= htmlspecialchars($fs['file_name']) ?></a>
                                                                        <a href="#" class="btn-edit-filename" data-kind="composition" data-file-id="<?= $fs['id'] ?>" title="Sửa tên file"><i class="fa-solid fa-pen"></i></a>
                                                                    </div>
                                                                    <div class="operation">
                                                                        <a href="?mod=product_profile&controllers=product_profile&action=download_composition_file&file_id=<?= $fs['id'] ?>&product_id=<?= $prod['product_id'] ?>" title="Tải xuống"><i class="fa-solid fa-download"></i></a>
                                                                        <button type="button" class="sd-del-comp-file" data-file-id="<?= $fs['id'] ?>" title="Xóa file"><i class="fa-regular fa-trash-can"></i></button>
                                                                    </div>
                                                                </li>
                                                            <?php endforeach; ?>
                                                        </ul>
                                                    </div>
                                                    <div class="wp-row-materials">
                                                        <div class="wp-title">
                                                            <div class="level-1"><p> - Hồ sơ nguyên liệu</p></div>
                                                        </div>
                                                        <ul class="list-file">
                                                            <?php foreach (($item['files']['material'] ?? []) as $fm): ?>
                                                                <li class="file-item">
                                                                    <div class="level-2">--</div>
                                                                    <div class="name-file file-name-edit">
                                                                        <a href="<?= $config['base_url'] . $fm['file_path'] ?>" target="_blank"><?= htmlspecialchars($fm['file_name']) ?></a>
                                                                        <a href="#" class="btn-edit-filename" data-kind="composition" data-file-id="<?= $fm['id'] ?>" title="Sửa tên file"><i class="fa-solid fa-pen"></i></a>
                                                                    </div>
                                                                    <div class="operation">
                                                                        <a href="?mod=product_profile&controllers=product_profile&action=download_composition_file&file_id=<?= $fm['id'] ?>&product_id=<?= $prod['product_id'] ?>" title="Tải xuống"><i class="fa-solid fa-download"></i></a>
                                                                        <button type="button" class="sd-del-comp-file" data-file-id="<?= $fm['id'] ?>" title="Xóa file"><i class="fa-regular fa-trash-can"></i></button>
                                                                    </div>
                                                                </li>
                                                            <?php endforeach; ?>
                                                        </ul>
                                                    </div>
                                                    <div class="wp-invoice">
                                                        <div class="wp-title">
                                                            <div class="level-1"><p> - Hóa đơn</p></div>
                                                        </div>
                                                        <ul class="list-file list-invoice">
                                                            <?php foreach (($item['files']['invoice'] ?? []) as $finv): ?>
                                                                <li class="file-item invoice-item">
                                                                    <div class="level-2">--</div>
                                                                    <div class="name-file">
                                                                        <span class="file-name-edit">
                                                                            <a href="<?= $config['base_url'] . $finv['file_path'] ?>" target="_blank"><?= htmlspecialchars($finv['file_name']) ?></a>
                                                                            <a href="#" class="btn-edit-filename" data-kind="invoice" data-file-id="<?= $finv['id'] ?>" title="Sửa tên hóa đơn"><i class="fa-solid fa-pen"></i></a>
                                                                        </span>
                                                                        <span class="invoice-uploaded">Tải lên: <?= date('d/m/Y', strtotime($finv['uploaded_at'])) ?></span>
                                                                        <span class="invoice-remind<?= $finv['is_overdue'] ? ' is-overdue' : '' ?>">
                                                                            <i class="fa-solid fa-bell"></i> Nhắc: <?= date('d/m/Y', strtotime($finv['remind_at'])) ?><?= $finv['is_overdue'] ? ' — Cảnh báo!' : '' ?>
                                                                        </span>
                                                                    </div>
                                                                    <div class="operation">
                                                                        <a href="?mod=product_profile&controllers=product_profile&action=download_material_invoice&invoice_id=<?= $finv['id'] ?>&product_id=<?= $prod['product_id'] ?>" title="Tải xuống hóa đơn"><i class="fa-solid fa-download"></i></a>
                                                                        <button type="button" class="sd-del-invoice" data-invoice-id="<?= $finv['id'] ?>" title="Xóa hóa đơn"><i class="fa-regular fa-trash-can"></i></button>
                                                                    </div>
                                                                </li>
                                                            <?php endforeach; ?>
                                                            <?php if (empty($item['files']['invoice'])): ?><li class="sd-none">Chưa có hóa đơn.</li><?php endif; ?>
                                                        </ul>
                                                    </div>
                                                </div>
                                            </li>
                                        <?php endforeach; ?>
                                        <?php if (empty($prod['composition'])): ?><li class="sd-none">Chưa có thành phần.</li><?php endif; ?>
                                    </ul>
                                </div>
                                </div><!-- /.sd-card-body -->

                                <div class="sd-card-footer">
                                    <button type="button" class="btn-create-dossier"
                                        data-product-id="<?= $prod['product_id'] ?>"
                                        data-product-name="<?= htmlspecialchars($prod['product_name'], ENT_QUOTES) ?>">
                                        Tạo bộ hồ sơ
                                    </button>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>

            </div>
        </div>
    </div>

    <!-- MODAL THÊM THÀNH PHẦN (cho card trong bộ hồ sơ chuẩn) -->
    <div class="pp-modal-overlay" id="sd-add-composition-modal">
        <div class="pp-modal">
            <div class="pp-modal-header">
                <h3>Thêm thành phần</h3>
                <button type="button" class="pp-modal-close" aria-label="Đóng">&times;</button>
            </div>
            <div class="pp-modal-body">
                <div class="wp-content-add-product">
                    <div class="wp-search-material sd-msearch">
                        <input type="text" class="sd-msearch-input" placeholder="Tìm nguyên liệu... (có thể chọn nhiều)" autocomplete="off">
                        <div class="search-results sd-msearch-results"></div>
                    </div>
                    <div class="selected-materials sd-msearch-selected"></div>
                    <button type="button" class="btn-submit sd-add-comp-submit">THÊM</button>
                </div>
            </div>
        </div>
    </div>

    <!-- ============================================================
         MODAL "HÓA ĐƠN, CHỨNG TỪ CÒN THIẾU" — toàn bộ Bộ hồ sơ chuẩn (nhiều sản phẩm),
         nạp qua AJAX lúc mở (giống modal Tần suất) vì dữ liệu tổng hợp nhiều sản phẩm.
         ============================================================ -->
    <div class="pp-modal-overlay" id="sd-missing-modal">
        <div class="pp-modal pp-modal--wide">
            <div class="pp-modal-header">
                <h3><i class="fa-solid fa-triangle-exclamation"></i> Hóa đơn, chứng từ còn thiếu</h3>
                <div class="pp-modal-header-actions">
                    <select id="sd-missing-filter" title="Lọc theo loại hồ sơ thiếu">
                        <option value="all">Tất cả</option>
                        <option value="product">Hồ sơ sản phẩm</option>
                        <option value="material">Hồ sơ nguyên liệu</option>
                        <option value="supplier">Hồ sơ nhà cung cấp</option>
                        <option value="invoice">Hóa đơn</option>
                    </select>
                    <button type="button" class="btn-download-freq" id="sd-missing-download" title="Tải ZIP toàn bộ file hiện có của các sản phẩm ĐANG HIỂN THỊ trên trang">
                        <i class="fa-solid fa-file-zipper"></i> Tải xuống
                    </button>
                    <button type="button" class="btn-print-missing" id="sd-missing-print" title="In khổ A4">
                        <i class="fa-solid fa-print"></i> In
                    </button>
                    <button type="button" class="btn-capture-missing" id="sd-missing-capture" title="Chụp ảnh để Ctrl+V dán sang app khác">
                        <i class="fa-solid fa-camera"></i> Chụp
                    </button>
                    <button type="button" class="pp-modal-close" aria-label="Đóng">&times;</button>
                </div>
            </div>
            <div class="pp-modal-body" id="sd-missing-capture-area">
                <div id="sd-missing-summary" class="freq-summary"></div>
                <div id="sd-missing-body"><p class="freq-loading">Đang tải...</p></div>
            </div>
        </div>
    </div>

    <!-- ============================================================
         MODAL "HÓA ĐƠN, CHỨNG TỪ ĐÃ CÓ" — toàn bộ Bộ hồ sơ chuẩn (nhiều sản phẩm), nạp qua AJAX lúc mở,
         dùng lại đúng action ajax_all_files_standard (không product_ids -> mặc định TOÀN BỘ sản phẩm chuẩn).
         ============================================================ -->
    <div class="pp-modal-overlay" id="sd-existing-modal">
        <div class="pp-modal pp-modal--wide">
            <div class="pp-modal-header">
                <h3><i class="fa-solid fa-circle-check"></i> Hóa đơn, chứng từ đã có</h3>
                <div class="pp-modal-header-actions">
                    <select id="sd-existing-filter" title="Lọc theo loại hồ sơ">
                        <option value="all">Tất cả</option>
                        <option value="product">Hồ sơ sản phẩm</option>
                        <option value="material">Hồ sơ nguyên liệu</option>
                        <option value="supplier">Hồ sơ nhà cung cấp</option>
                        <option value="invoice">Hóa đơn</option>
                    </select>
                    <button type="button" class="btn-download-freq" id="sd-existing-download" title="Tải ZIP toàn bộ file hiện có của các sản phẩm ĐANG HIỂN THỊ trên trang">
                        <i class="fa-solid fa-file-zipper"></i> Tải xuống
                    </button>
                    <button type="button" class="btn-print-missing" id="sd-existing-print" title="In khổ A4">
                        <i class="fa-solid fa-print"></i> In
                    </button>
                    <button type="button" class="btn-capture-missing" id="sd-existing-capture" title="Chụp ảnh để Ctrl+V dán sang app khác">
                        <i class="fa-solid fa-camera"></i> Chụp
                    </button>
                    <button type="button" class="pp-modal-close" aria-label="Đóng">&times;</button>
                </div>
            </div>
            <div class="pp-modal-body" id="sd-existing-capture-area">
                <div id="sd-existing-summary" class="freq-summary"></div>
                <div id="sd-existing-body"><p class="freq-loading">Đang tải...</p></div>
            </div>
        </div>
    </div>

    <!-- ============================================================
         MODAL "SẢN PHẨM DÙNG NGUYÊN LIỆU NÀY" — mở từ click tên nguyên liệu trong bảng ở modal
         "Hóa đơn, chứng từ đã có", chỉ liệt kê sản phẩm TRONG Bộ hồ sơ chuẩn (đúng phạm vi trang này).
         ============================================================ -->
    <div class="pp-modal-overlay" id="sd-material-products-modal">
        <div class="pp-modal">
            <div class="pp-modal-header">
                <h3><i class="fa-solid fa-list-check"></i> Sản phẩm dùng nguyên liệu này</h3>
                <div class="pp-modal-header-actions">
                    <button type="button" class="btn-print-missing" id="sd-mp-print" title="In khổ A4">
                        <i class="fa-solid fa-print"></i> In
                    </button>
                    <button type="button" class="pp-modal-close" aria-label="Đóng">&times;</button>
                </div>
            </div>
            <div class="pp-modal-body" id="sd-mp-capture-area">
                <p class="missing-docs-product-name">Nguyên liệu: <strong id="sd-mp-material-name"></strong></p>
                <div id="sd-mp-body"><p class="freq-loading">Đang tải...</p></div>
            </div>
        </div>
    </div>

    <script src="<?php echo asset_ver('public/js/shared/app_shell.js'); ?>"></script>
</body>

</html>
