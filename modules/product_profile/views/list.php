<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Danh sách sản phẩm</title>
    <link rel="icon" href="public/images/logo/logo_vat_png.png" type="image/png">
    <link rel="stylesheet" href="public/css/reset.css">
    <link rel="stylesheet" href="public/css/all.css">
    <link rel="stylesheet" href="public/css/global.css">
    <link rel="stylesheet" href="<?php echo asset_ver('public/css/product_profile/list.css'); ?>">
    <link rel="stylesheet" href="public/css/product_profile/modal.css">
    <!-- Lightbox xem ảnh dùng chung -->
    <link rel="stylesheet" href="public/css/shared/invoice_upload.css">

    <!-- Thư viện -->
    <script src="public/js/jquery-4.0.0.js" type="text/javascript" defer></script>
    <!--js của menu sidebarleft-->
    <!-- <script src="public/js/menu_sidebar_left.js" defer></script> -->
    <!-- Modal dùng chung (Thêm SP, Check DB...) -->
    <script src="public/js/product_profile_modal.js" defer></script>
    <!-- Kéo-thả file vào card sản phẩm -->
    <script src="public/js/product_profile_dragdrop.js" defer></script>
    <!-- Tạo bộ hồ sơ (ghi cây thư mục ra máy) -->
    <script src="public/js/product_profile_dossier.js" defer></script>
    <!-- Modal "Tần suất" (dùng chung với trang Chi tiết sản phẩm) -->
    <script src="public/js/product_profile_frequency.js" defer></script>
    <!-- Lightbox xem ảnh dùng chung (InvoiceViewer) -->
    <script src="public/js/shared/invoice_dropzone.js" defer></script>
    <!-- Đổi ảnh sản phẩm: chọn tệp / dán Ctrl+V / kéo-thả trên .img_product -->
    <script src="public/js/product_profile_image.js" defer></script>
    <!-- Nén ZIP cho modal "Tần suất" (tải xuống tất cả file) -->
    <script src="https://cdnjs.cloudflare.com/ajax/libs/jszip/3.10.1/jszip.min.js"></script>
    <link rel="stylesheet" href="<?php echo asset_ver('public/css/shared/app_shell.css'); ?>">
</head>
<style>
    #name-dashboard {
        display: flex;
        gap: 10px;
        align-items: center;
    }

    h1 {
        margin-bottom: 0;
    }
</style>

<body>
    <div id="wrapper">
        <!-- Require Sidebar-left -->
        <?php //require "layouts/sidebar-left.php"; ?>
        <div id="sidebar-right">

            <!-- Require Sidebar-right -->
            <?php require "layouts/top-sidebar-right.php"; ?>
            <div class="main-content">
                <div class="wp-search">
                    <div class="search-box">
                        <i class="fa-solid fa-magnifying-glass search-icon"></i>
                        <input type="text" id="search-product" class="search-input" placeholder="Tìm kiếm sản phẩm...">
                    </div>
                    <!-- Bộ lọc "Đã công bố dinh dưỡng" -->
                    <select id="nutrition-filter-select" class="wp-nutrition-filter" title="Lọc theo trạng thái công bố dinh dưỡng">
                        <option value="all">Tất cả</option>
                        <option value="has">SP có công bố dinh dưỡng</option>
                        <option value="confirmed">SP đã công bố dinh dưỡng</option>
                        <option value="standard">Bộ hồ sơ chuẩn</option>
                    </select>
                    <!-- (6) Nút kiểm tra database -->
                    <button type="button" class="btn-check-db" data-pp-open="#checkdb-modal"
                        data-default-table="<?= htmlspecialchars(!empty($affected_table) ? $affected_table : 'products') ?>"
                        data-affected-id="<?= (int) ($affected_id ?? 0) ?>"
                        data-affected-table="<?= htmlspecialchars($affected_table ?? '') ?>">
                        <i class="fa-solid fa-database"></i> Check database
                    </button>
                    <!-- Tần suất lặp lại file hồ sơ (toàn hệ thống, có phân trang) -->
                    <button type="button" class="btn-frequency" id="btn-file-frequency" title="Xem tần suất lặp lại của file hồ sơ (toàn hệ thống)">
                        <i class="fa-solid fa-chart-column"></i> Tần suất
                    </button>
                </div>

                <?php if (!empty($form_error) && empty($open_add)): ?>
                    <p class="error-msg"><?= htmlspecialchars($form_error) ?></p>
                <?php endif; ?>
                <!-- HIỂN THỊ DANH SÁCH SẢN PHẨM -->
                <!-- Vòng lặp ngoài lấy danh mục -->
                <div class="container-category">
                    <?php foreach ($groups as $key => $category): ?>
                        <div class="wp-title-category">
                            <div class="title-categogy">
                                <h3><?= $key ?></h3>
                            </div>
                            <?php $firstKey = array_key_first($groups); ?>
                            <?php if ($key === $firstKey): ?>
                                <!-- GHI CHÚ: "Sản phẩm có công bố dinh dưỡng"-->
                                <div class="container-note-nutrition-fact">
                                    <div class="nutrition-facts">
                                        <i class="fa-solid fa-circle-check"></i>
                                    </div>
                                    <div class="content-note">
                                        <p>Sản phẩm có công bố dinh dưỡng</p>
                                    </div>
                                </div>
                            <?php endif; ?>
                        </div>
                        <!-- Vòng lặp trong lấy sản phẩm -->
                        <div id="wrapper-product">
                            <?php foreach ($category as $productId => $product_item): ?>

                                <div class="container-product" data-product-name="<?= mb_strtolower($product_item['product_name'], 'UTF-8') ?>"
                                    data-dropzone="product" data-product-id="<?= $productId ?>"
                                    data-has-nutrition="<?= $product_item['has_nutrition_fact'] == 1 ? 1 : 0 ?>"
                                    data-nutrition-confirmed="<?= !empty($product_item['nutrition_fact_confirmed']) ? 1 : 0 ?>"
                                    data-is-standard="<?= !empty($product_item['is_standard']) ? 1 : 0 ?>">
                                    <div class="wp-title-product">
                                        <div class="img_product">
                                            <img src="<?= $product_item['image_url'] ?>" alt="" class="js-product-photo">
                                            <div class="img-product-overlay">
                                                <?php if ($product_item['image_url'] !== 'public/images/'): ?>
                                                    <button type="button" class="img-action-btn img-action-view" title="Xem ảnh phóng to">
                                                        <i class="fa-solid fa-eye"></i>
                                                    </button>
                                                <?php endif; ?>
                                                <label class="img-action-btn img-action-camera" title="Đổi ảnh (chọn tệp / Ctrl+V dán / kéo-thả)">
                                                    <i class="fa-solid fa-camera"></i>
                                                    <input type="file" class="js-product-photo-input" accept="image/*" hidden>
                                                </label>
                                            </div>
                                        </div>
                                        <div class="wp-title-button">
                                            <div class="container-title-product">
                                                <div class="title-product">
                                                    <a href="?mod=product_profile&controllers=product_profile&action=product_detail&id=<?= $productId ?> ">
                                                        <?= $product_item['product_name'] ?>
                                                    </a>

                                                </div>
                                                <div class="nutrition-status-group">
                                                    <label class="standard-set-check" title="Đánh dấu sản phẩm thuộc bộ hồ sơ chuẩn">
                                                        <span class="app-round-check app-round-check-blue">
                                                            <input type="checkbox" class="chk-standard-set" data-product-id="<?= $productId ?>" <?= !empty($product_item['is_standard']) ? 'checked' : '' ?>>
                                                            <span class="app-round-check-mark"><i class="fa-solid fa-check"></i></span>
                                                        </span>
                                                    </label>
                                                    <?php if ($product_item['has_nutrition_fact'] == 1): ?>
                                                        <label class="nutrition-confirm-check" title="Đã công bố dinh dưỡng">
                                                            <span class="app-round-check app-round-check-orange">
                                                                <input type="checkbox" class="chk-nutrition-confirmed" data-product-id="<?= $productId ?>" <?= !empty($product_item['nutrition_fact_confirmed']) ? 'checked' : '' ?>>
                                                                <span class="app-round-check-mark"><i class="fa-solid fa-check"></i></span>
                                                            </span>
                                                        </label>
                                                        <div class="nutrition-facts" title="Sản phẩm có công bố dinh dưỡng">
                                                            <i class="fa-solid fa-circle-check"></i>
                                                        </div>
                                                    <?php endif; ?>
                                                </div>
                                            </div>
                                            <div class="container-button">
                                                <div class="wp-create-dossier">
                                                    <button type="button" class="btn-create-dossier" title="Tạo bộ hồ sơ" aria-label="Tạo bộ hồ sơ"
                                                        data-product-id="<?= $productId ?>"
                                                        data-product-name="<?= htmlspecialchars($product_item['product_name'], ENT_QUOTES) ?>">
                                                        <i class="fa-solid fa-folder-plus"></i>
                                                    </button>
                                                </div>
                                                <div class="wp-add-file">
                                                    <button type="button" class="btn-add-file js-open-pfile" title="Thêm file" aria-label="Thêm file"
                                                        data-product-id="<?= $productId ?>"
                                                        data-subtitle="Thêm file cho sản phẩm: <?= htmlspecialchars($product_item['product_name'], ENT_QUOTES) ?>">
                                                        <i class="fa-solid fa-paperclip"></i>
                                                    </button>
                                                </div>
                                                <div class="wp-delete-product">
                                                    <a href="#" class="btn-delete-product js-delete-product" title="Xóa sản phẩm" aria-label="Xóa sản phẩm"
                                                        data-product-id="<?= $productId ?>">
                                                        <i class="fa-solid fa-trash-can"></i>
                                                    </a>
                                                </div>
                                            </div>
                                        </div>
                                    </div>
                                    <div class="content-product">

                                        <div class="wp-list-file">
                                            <ul class="main-file">
                                                <!-- Chạy vòng lặp laod list_file ra -->
                                                <?php foreach ($product_item['list_file'] as $file_item): ?>
                                                    <li class="file-item">
                                                        <div class="file-name-edit">
                                                            <a href="<?= $file_item['file_path'] ?>" target="_blank">
                                                                <p class="title-file"><?= $file_item['file_name'] ?></p>
                                                            </a>
                                                            <a href="#" class="btn-edit-filename" data-file-id="<?= $file_item['file_id'] ?>" title="Sửa tên file">
                                                                <i class="fa-solid fa-pen"></i>
                                                            </a>
                                                        </div>
                                                        <div class="wp-icon-processing-file">
                                                            <a href="?mod=product_profile&controllers=product_profile&action=download_file&id_file=<?= $file_item['file_id'] ?>">
                                                                <i class="fa-solid fa-download"></i>
                                                            </a>
                                                            <a href="?mod=product_profile&controllers=product_profile&action=update_file&id_product=<?= $productId ?>&id_file=<?= $file_item['file_id'] ?>">
                                                                <i class="fa-solid fa-file-arrow-up"></i>
                                                            </a>
                                                            <a href="?mod=product_profile&controllers=product_profile&action=delete_file&id_file=<?= $file_item['file_id'] ?>"
                                                                onclick="return confirm('Bạn có thật sự muốn xóa file này?')">
                                                                <i class="fa-solid fa-trash-can"></i>
                                                            </a>
                                                        </div>
                                                    </li>
                                                <?php endforeach; ?>
                                            </ul>
                                        </div>
                                    </div>
                                </div>

                            <?php endforeach; ?>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>

        </div>
    </div>

    <!-- ============================================================
         (2) MODAL THÊM SẢN PHẨM  (#container-add mở modal này)
         ============================================================ -->
    <div class="pp-modal-overlay" id="add-product-modal" <?= !empty($open_add) ? 'data-pp-autoopen="1"' : '' ?>>
        <div class="pp-modal">
            <div class="pp-modal-header">
                <h3>THÊM SẢN PHẨM</h3>
                <button type="button" class="pp-modal-close" aria-label="Đóng">&times;</button>
            </div>
            <div class="pp-modal-body">
                <div class="wp-content-add-product">
                    <?php if (!empty($form_error)): ?>
                        <p class="error-msg"><?= htmlspecialchars($form_error) ?></p>
                    <?php endif; ?>

                    <form enctype="multipart/form-data"
                        action="?mod=product_profile&controllers=product_profile&action=ready_add_product"
                        method="POST" id="form-add-product">

                        <!-- Tên sản phẩm -->
                        <div class="wp-name-product">
                            <label for="product_name">Tên sản phẩm:</label>
                            <input type="text" id="product_name" name="product_name" required>
                        </div>

                        <!-- Danh mục -->
                        <div class="wp-choose-category">
                            <label for="category_id">Chọn danh mục:</label>
                            <select name="category_id" id="category_id" required>
                                <option value="">-- Chọn danh mục --</option>
                                <?php foreach ($categories as $cat): ?>
                                    <option value="<?= $cat['id'] ?>"><?= htmlspecialchars($cat['category_name']) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <!-- Hình ảnh -->
                        <div class="wp-upload">
                            <input type="file" name="product_image" id="file_upload" accept=".jpg,.jpeg,.png,.gif,.webp,.bmp" hidden>
                            <input type="text" class="file-name-display" placeholder="Chưa chọn hình ảnh" readonly>
                            <button type="button" class="btn-choose">Chọn hình ảnh</button>
                        </div>

                        <!-- THÔNG TIN CĂN BẢN -->
                        <p class="section-title">Thông tin căn bản</p>

                        <div class="wp-input-row">
                            <label for="unit">Đơn vị:</label>
                            <input type="text" id="unit" name="unit" placeholder="VD: Kg, Hộp, Chai...">
                        </div>

                        <div class="wp-input-row">
                            <label for="inner_packaging">Bao bì trong:</label>
                            <input type="text" id="inner_packaging" name="inner_packaging" placeholder="Nhập bao bì trong">
                        </div>

                        <div class="wp-input-row">
                            <label for="outer_packaging">Bao bì ngoài:</label>
                            <input type="text" id="outer_packaging" name="outer_packaging" placeholder="Nhập bao bì ngoài">
                        </div>

                        <div class="wp-input-row">
                            <label for="inner_packaging_spec">Quy cách bao bì trong:</label>
                            <input type="text" id="inner_packaging_spec" name="inner_packaging_spec" placeholder="Nhập quy cách bao bì trong">
                        </div>

                        <div class="wp-input-row">
                            <label for="outer_packaging_spec">Quy cách bao bì ngoài:</label>
                            <input type="text" id="outer_packaging_spec" name="outer_packaging_spec" placeholder="Nhập quy cách bao bì ngoài">
                        </div>

                        <!-- CHÍNH SÁCH GIÁ -->
                        <p class="section-title">Chính sách giá</p>

                        <div class="wp-input-row">
                            <label for="cost_price">Giá vốn:</label>
                            <input type="text" id="cost_price" name="cost_price" placeholder="Nhập giá vốn">
                        </div>

                        <div class="wp-input-row">
                            <label for="system_price">Giá xuất hệ thống Passion Link:</label>
                            <input type="text" id="system_price" name="system_price" placeholder="Nhập giá xuất hệ thống">
                        </div>

                        <div class="wp-input-row">
                            <label for="retail_price">Giá bán lẻ:</label>
                            <input type="text" id="retail_price" name="retail_price" placeholder="Nhập giá bán lẻ">
                        </div>

                        <!-- THÀNH PHẦN CẤU TẠO -->
                        <p class="section-title">Thành phần cấu tạo</p>

                        <div class="wp-search-material material-search" data-mode="multi">
                            <input type="text" class="material-search-input" placeholder="Tìm kiếm nguyên liệu..." autocomplete="off">
                            <div class="search-results"></div>
                        </div>

                        <div class="selected-materials">
                            <!-- JS render material đã chọn -->
                        </div>

                        <button type="submit" class="btn-submit">THÊM SẢN PHẨM</button>
                    </form>
                </div>
            </div>
        </div>
    </div>

    <!-- ============================================================
         MODAL THÊM FILE CHO SẢN PHẨM (ghi vào product_files + copy file)
         ============================================================ -->
    <div class="pp-modal-overlay" id="product-file-modal">
        <div class="pp-modal">
            <div class="pp-modal-header">
                <h3>Thêm file cho sản phẩm</h3>
                <button type="button" class="pp-modal-close" aria-label="Đóng">&times;</button>
            </div>
            <div class="pp-modal-body">
                <div class="wp-content-addfile">
                    <p class="pfile-subtitle"></p>
                    <form enctype="multipart/form-data" method="POST" id="pfile-form" action="">
                        <div class="wp-name-file">
                            <label for="pfile_label">Tên file:</label>
                            <input type="text" id="pfile_label" name="file_label">
                        </div>
                        <div class="wp-upload">
                            <input type="file" name="file_upload" hidden>
                            <input type="text" class="file-name-display" placeholder="Chưa chọn file" readonly>
                            <button type="button" class="btn-choose">Chọn file</button>
                        </div>
                        <button type="submit" class="btn-submit">Thêm</button>
                    </form>
                </div>
            </div>
        </div>
    </div>

    <!-- ============================================================
         (6) MODAL CHECK DATABASE
         ============================================================ -->
    <div class="pp-modal-overlay" id="checkdb-modal">
        <div class="pp-modal pp-modal--wide">
            <div class="pp-modal-header">
                <h3><i class="fa-solid fa-database"></i> Kiểm tra Database</h3>
                <button type="button" class="pp-modal-close" aria-label="Đóng">&times;</button>
            </div>
            <div class="pp-modal-body">
                <div class="checkdb-toolbar">
                    <label>Bảng:</label>
                    <select class="checkdb-table-select">
                        <option value="products">products (sản phẩm)</option>
                        <option value="product_categories">product_categories (danh mục)</option>
                        <option value="product_files">product_files (file sản phẩm)</option>
                    </select>
                    <span class="checkdb-meta"></span>
                </div>
                <div class="checkdb-table-wrap"></div>
                <div class="checkdb-pagination"></div>
            </div>
        </div>
    </div>

    <!-- ============================================================
         MODAL "TẦN SUẤT" — toàn hệ thống, có phân trang (nạp qua AJAX)
         ============================================================ -->
    <div class="pp-modal-overlay" id="frequency-modal">
        <div class="pp-modal pp-modal--wide">
            <div class="pp-modal-header">
                <h3><i class="fa-solid fa-chart-column"></i> Tần suất lặp lại hồ sơ (toàn hệ thống)</h3>
                <div class="pp-modal-header-actions">
                    <select id="freq-group-filter" title="Lọc theo nhóm hồ sơ">
                        <option value="all">Tất cả</option>
                        <option value="supplier">Hồ sơ nhà cung cấp</option>
                        <option value="material">Hồ sơ nguyên liệu</option>
                        <option value="invoice">Hóa đơn</option>
                        <option value="company">Hồ sơ doanh nghiệp</option>
                        <option value="product">Hồ sơ liên quan sản phẩm</option>
                    </select>
                    <select id="freq-filter-select" title="Lọc theo tần suất">
                        <option value="0">Tất cả</option>
                        <option value="2">&gt;2</option>
                        <option value="3">&gt;3</option>
                        <option value="5">&gt;5</option>
                    </select>
                    <button type="button" class="btn-download-freq" id="freq-download-zip" title="Tải xuống tất cả (ZIP), theo đúng bộ lọc + các dòng đã loại bỏ">
                        <i class="fa-solid fa-file-zipper"></i> Tải xuống
                    </button>
                    <button type="button" class="pp-modal-close" aria-label="Đóng">&times;</button>
                </div>
            </div>
            <div class="pp-modal-body">
                <div class="freq-summary" id="freq-summary"></div>
                <table class="freq-table">
                    <thead>
                        <tr>
                            <th>Nhóm</th>
                            <th>Tên file</th>
                            <th class="freq-count-col">Tần suất</th>
                        </tr>
                    </thead>
                    <tbody id="freq-table-body">
                        <tr><td colspan="3" class="freq-loading">Đang tải...</td></tr>
                    </tbody>
                </table>
                <div class="freq-pagination checkdb-pagination" id="freq-pagination"></div>
            </div>
        </div>
    </div>

    <script>
        document.addEventListener('DOMContentLoaded', function () {
            // (2) Nút "Thêm sản phẩm" trên thanh trên -> mở modal thay vì sang view khác
            var addLink = document.querySelector('#container-add a');
            if (addLink) {
                addLink.addEventListener('click', function (e) {
                    e.preventDefault();
                    var modal = document.getElementById('add-product-modal');
                    if (modal) {
                        modal.classList.add('is-open');
                        document.body.style.overflow = 'hidden';
                    }
                });
            }

            // "Thêm file" trên mỗi card -> mở modal, điền action + tiêu đề theo sản phẩm
            document.querySelectorAll('.js-open-pfile').forEach(function (btn) {
                btn.addEventListener('click', function () {
                    var modal = document.getElementById('product-file-modal');
                    if (!modal) return;
                    modal.querySelector('#pfile-form').dataset.productId = this.dataset.productId || '';
                    modal.querySelector('.pfile-subtitle').textContent = this.dataset.subtitle || '';
                    modal.querySelector('#pfile_label').value = '';
                    modal.querySelector('.file-name-display').value = '';
                    modal.querySelector('input[type="file"]').value = '';
                    modal.classList.add('is-open');
                    document.body.style.overflow = 'hidden';
                });
            });

            // (AJAX) Submit "Thêm file" -> không reload trang, giữ nguyên trạng thái tìm kiếm/lọc hiện tại
            var pfileForm = document.getElementById('pfile-form');
            if (pfileForm) {
                pfileForm.addEventListener('submit', function (e) {
                    e.preventDefault();
                    var productId = this.dataset.productId;
                    var fileInput = this.querySelector('input[type="file"]');
                    var file = fileInput && fileInput.files[0];
                    if (!productId || !file) {
                        alert('Vui lòng chọn file.');
                        return;
                    }

                    var fd = new FormData();
                    fd.append('product_id', productId);
                    fd.append('file', file);
                    fd.append('file_label', document.getElementById('pfile_label').value || '');

                    var submitBtn = this.querySelector('.btn-submit');
                    if (submitBtn) submitBtn.disabled = true;

                    fetch('?mod=product_profile&controllers=product_profile&action=ajax_upload_product_file', {
                        method: 'POST',
                        body: fd
                    }).then(function (r) { return r.json(); }).then(function (res) {
                        if (!res || !res.success) {
                            alert((res && res.message) || 'Thêm file thất bại.');
                            return;
                        }
                        var list = document.querySelector('.container-product[data-product-id="' + productId + '"] .main-file');
                        if (list) list.insertAdjacentHTML('beforeend', buildPfileItemHtml(res, productId));
                        document.getElementById('product-file-modal').classList.remove('is-open');
                        document.body.style.overflow = '';
                    }).catch(function () {
                        alert('Lỗi kết nối khi tải file.');
                    }).finally(function () {
                        if (submitBtn) submitBtn.disabled = false;
                    });
                });
            }

            function buildPfileItemHtml(res, productId) {
                var ACT = '?mod=product_profile&controllers=product_profile&action=';
                var name = (res.file_name || '').replace(/</g, '&lt;').replace(/>/g, '&gt;');
                return '<li class="file-item">'
                    + '<div class="file-name-edit">'
                    + '<a href="' + (res.web_path || '#') + '" target="_blank"><p class="title-file">' + name + '</p></a>'
                    + '<a href="#" class="btn-edit-filename" data-file-id="' + res.id + '" title="Sửa tên file"><i class="fa-solid fa-pen"></i></a>'
                    + '</div>'
                    + '<div class="wp-icon-processing-file">'
                    + '<a href="' + ACT + 'download_file&id_file=' + res.id + '"><i class="fa-solid fa-download"></i></a>'
                    + '<a href="' + ACT + 'update_file&id_product=' + productId + '&id_file=' + res.id + '"><i class="fa-solid fa-file-arrow-up"></i></a>'
                    + '<a href="' + ACT + 'delete_file&id_file=' + res.id + '" onclick="return confirm(\'Bạn có thật sự muốn xóa file này?\')"><i class="fa-solid fa-trash-can"></i></a>'
                    + '</div>'
                    + '</li>';
            }

            // (AJAX) Xóa sản phẩm -> hỏi xác nhận, xóa xong thì card biến mất nhẹ nhàng
            // (fade + thu nhỏ) thay vì reload trang, và không crash khi bị ràng buộc khóa ngoại.
            document.addEventListener('click', function (e) {
                var btn = e.target.closest('.js-delete-product');
                if (!btn) return;
                e.preventDefault();
                if (!confirm('Bạn có thực sự muốn xóa sản phẩm này?')) return;

                var card = btn.closest('.container-product');
                var productId = btn.dataset.productId;

                fetch('?mod=product_profile&controllers=product_profile&action=delete_product&id=' + encodeURIComponent(productId))
                    .then(function (r) { return r.json(); })
                    .then(function (res) {
                        if (!res || !res.success) {
                            alert((res && res.message) || 'Xóa sản phẩm thất bại.');
                            return;
                        }
                        if (!card) return;
                        card.classList.add('is-removing');
                        card.addEventListener('transitionend', function () { card.remove(); }, { once: true });
                        setTimeout(function () { if (card.parentNode) card.remove(); }, 400);
                    })
                    .catch(function () { alert('Lỗi kết nối khi xóa sản phẩm.'); });
            });

            // (Task 2) Checkbox 'Bộ hồ sơ chuẩn' -> cập nhật products.standard_document_set (không đòi hỏi đã có file)
            document.querySelectorAll('.chk-standard-set').forEach(function (chk) {
                chk.addEventListener('change', function () {
                    var self = this;
                    var pid = this.dataset.productId;
                    var val = this.checked ? '1' : '0';
                    fetch('?mod=product_profile&controllers=product_profile&action=ajax_toggle_standard_set', {
                        method: 'POST',
                        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                        body: 'product_id=' + encodeURIComponent(pid) + '&value=' + val
                    }).then(function (r) { return r.json(); }).then(function (res) {
                        if (!res.success) {
                            alert(res.message || 'Lỗi cập nhật.');
                            self.checked = !self.checked;
                            return;
                        }
                        var card = self.closest('.container-product');
                        if (card) card.setAttribute('data-is-standard', val);
                    }).catch(function () {
                        alert('Lỗi kết nối.');
                        self.checked = !self.checked;
                    });
                });
            });

            // (Task 3) Checkbox tròn cam "Đã công bố dinh dưỡng" -> cập nhật products.nutrition_fact_confirmed
            document.querySelectorAll('.chk-nutrition-confirmed').forEach(function (chk) {
                chk.addEventListener('change', function () {
                    var self = this;
                    var pid = this.dataset.productId;
                    var val = this.checked ? '1' : '0';
                    fetch('?mod=product_profile&controllers=product_profile&action=ajax_toggle_nutrition_fact_confirmed', {
                        method: 'POST',
                        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                        body: 'product_id=' + encodeURIComponent(pid) + '&value=' + val
                    }).then(function (r) { return r.json(); }).then(function (res) {
                        if (!res.success) {
                            alert('Lỗi cập nhật.');
                            self.checked = !self.checked;
                            return;
                        }
                        var card = self.closest('.container-product');
                        if (card) card.setAttribute('data-nutrition-confirmed', val);
                    }).catch(function () {
                        alert('Lỗi kết nối.');
                        self.checked = !self.checked;
                    });
                });
            });

            // Lọc tìm kiếm sản phẩm + lọc theo trạng thái công bố dinh dưỡng
            var input = document.getElementById('search-product');
            var nutritionFilter = document.getElementById('nutrition-filter-select');
            var products = document.querySelectorAll('.container-product');
            var categories = document.querySelectorAll('.container-category > .wp-title-category');
            var productWrappers = document.querySelectorAll('#wrapper-product');

            function applyFilters() {
                var keyword = input.value.toLowerCase().trim();
                var mode = nutritionFilter.value;

                products.forEach(function (card) {
                    var name = card.getAttribute('data-product-name');
                    var matchKeyword = name.indexOf(keyword) !== -1;
                    var matchNutrition = true;
                    if (mode === 'has') {
                        matchNutrition = card.getAttribute('data-has-nutrition') === '1';
                    } else if (mode === 'confirmed') {
                        matchNutrition = card.getAttribute('data-nutrition-confirmed') === '1';
                    } else if (mode === 'standard') {
                        matchNutrition = card.getAttribute('data-is-standard') === '1';
                    }
                    card.style.display = (matchKeyword && matchNutrition) ? '' : 'none';
                });

                // Ẩn danh mục nếu tất cả sản phẩm bên trong đều bị ẩn
                productWrappers.forEach(function (wrapper, index) {
                    var visibleCards = wrapper.querySelectorAll('.container-product:not([style*="display: none"])');
                    var hasVisible = visibleCards.length > 0;
                    wrapper.style.display = hasVisible ? '' : 'none';
                    if (categories[index]) {
                        categories[index].style.display = hasVisible ? '' : 'none';
                    }
                });
            }

            input.addEventListener('input', applyFilters);
            nutritionFilter.addEventListener('change', applyFilters);
        });
    </script>
    <script src="<?php echo asset_ver('public/js/shared/app_shell.js'); ?>"></script>
</body>

</html>
