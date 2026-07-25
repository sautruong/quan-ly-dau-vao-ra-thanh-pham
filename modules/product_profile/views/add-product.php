<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Thêm sản phẩm</title>
    <link rel="icon" href="public/images/logo/logo_vat_png.png" type="image/png">
    <link rel="stylesheet" href="public/css/reset.css">
    <link rel="stylesheet" href="public/css/all.css">
    <link rel="stylesheet" href="public/css/global.css">
    <link rel="stylesheet" href="public/css/product_profile/list.css">

    <!--js của menu sidebarleft-->
    <script src="public/js/menu_sidebar_left.js" defer></script>
    <script src="public/js/jquery-4.0.0.js" type="text/javascript" defer></script>
    <link rel="stylesheet" href="<?php echo asset_ver('public/css/shared/app_shell.css'); ?>">
</head>
<style>
    /* KHUNG */
    .wp-content-add-product {
        width: 55%;
        background: #fff;
        padding: 32px;
        border-radius: 14px;
        box-shadow: 0 6px 18px rgba(0, 0, 0, 0.08);
        transition: 0.3s;
    }

    .wp-content-add-product:hover {
        box-shadow: 0 10px 25px rgba(0, 0, 0, 0.12);
    }

    /* TITLE */
    .name-title {
        font-size: 20px;
        font-weight: 700;
        color: #2e7d32;
        text-align: center;
        margin-bottom: 22px;
        letter-spacing: 1px;
    }

    /* FORM */
    .wp-content-add-product form {
        display: flex;
        flex-direction: column;
        gap: 18px;
    }

    /* SECTION TITLE */
    .section-title {
        font-size: 15px;
        font-weight: 700;
        color: #2e7d32;
        padding: 8px 0;
        border-bottom: 2px solid #e8f5e9;
        margin-top: 10px;
        margin-bottom: 4px;
    }

    /* GROUP */
    .wp-name-product,
    .wp-choose-category,
    .wp-upload,
    .wp-input-row {
        display: flex;
        align-items: center;
        gap: 10px;
    }

    /* LABEL */
    label {
        min-width: 160px;
        font-size: 14px;
        color: #444;
        margin: 0;
    }

    /* INPUT TEXT */
    input[type="text"] {
        flex: 1;
        padding: 8px 6px;
        border: none;
        border-bottom: 1.5px solid #ddd;
        outline: none;
        transition: 0.25s;
        font-size: 14px;
    }

    input[type="text"]:focus {
        border-bottom: 1.5px solid #78d675;
    }

    /* SELECT */
    select {
        flex: 1;
        padding: 9px 10px;
        border: 1.5px solid #ddd;
        border-radius: 8px;
        outline: none;
        font-size: 14px;
        color: #333;
        background: #fff;
        cursor: pointer;
        transition: 0.25s;
        appearance: auto;
    }

    select:focus {
        border-color: #78d675;
    }

    /* UPLOAD ROW */
    #file_name {
        flex: 1;
        background: #f8f9fa;
        border-radius: 8px;
        padding: 8px;
        border: 1px solid #eee;
    }

    #btn_choose {
        padding: 8px 14px;
        border: none;
        border-radius: 8px;
        background: #cdf0c6;
        color: green;
        cursor: pointer;
        transition: 0.25s;
    }

    #btn_choose:hover {
        background: Green;
        color: #fff;
        transform: translateY(-1px);
    }

    /* SUBMIT */
    .btn-submit {
        padding: 10px;
        border: none;
        border-radius: 10px;
        background: green;
        color: #fff;
        font-weight: 600;
        cursor: pointer;
        transition: 0.25s;
        margin-top: 10px;
    }

    .btn-submit:hover {
        background: green;
        transform: translateY(-2px);
        box-shadow: 0 6px 15px rgba(139, 199, 133, 0.3);
    }

    .btn-submit:active,
    #btn_choose:active {
        transform: scale(0.96);
    }

    /* ERROR */
    .error-msg {
        color: #d32f2f;
        font-size: 13px;
        background: #ffeaea;
        padding: 8px 12px;
        border-radius: 8px;
        text-align: center;
    }

    /* SEARCH MATERIAL */
    .wp-search-material {
        position: relative;
    }

    .wp-search-material input[type="text"] {
        width: 100%;
        padding: 10px 12px;
        border: 1.5px solid #ddd;
        border-radius: 8px;
        font-size: 14px;
        outline: none;
        transition: 0.25s;
    }

    .wp-search-material input[type="text"]:focus {
        border-color: #78d675;
    }

    .search-results {
        position: absolute;
        top: 100%;
        left: 0;
        right: 0;
        background: #fff;
        border: 1px solid #ddd;
        border-top: none;
        border-radius: 0 0 8px 8px;
        max-height: 220px;
        overflow-y: auto;
        z-index: 100;
        display: none;
        box-shadow: 0 4px 12px rgba(0, 0, 0, 0.1);
    }

    .search-results .result-item {
        padding: 10px 14px;
        cursor: pointer;
        font-size: 14px;
        border-bottom: 1px solid #f0f0f0;
        transition: 0.15s;
    }

    .search-results .result-item:hover {
        background: #e8f5e9;
    }

    .search-results .result-item:last-child {
        border-bottom: none;
    }

    .search-results .no-result {
        padding: 10px 14px;
        font-size: 13px;
        color: #999;
    }

    /* SELECTED MATERIALS */
    .selected-materials {
        display: flex;
        flex-direction: column;
        gap: 8px;
        margin-top: 8px;
    }

    .selected-material-item {
        display: flex;
        align-items: center;
        justify-content: space-between;
        background: #f1f8e9;
        border: 1px solid #c8e6c9;
        border-radius: 8px;
        padding: 10px 14px;
        gap: 10px;
    }

    .selected-material-item .material-info {
        display: flex;
        flex-direction: column;
        gap: 2px;
    }

    .selected-material-item .material-name {
        font-weight: 600;
        font-size: 14px;
        color: #333;
    }

    .selected-material-item .supplier-name {
        font-size: 12px;
        color: #777;
    }

    .selected-material-item .btn-remove {
        background: none;
        border: none;
        color: #d32f2f;
        cursor: pointer;
        font-size: 16px;
        padding: 4px 8px;
        border-radius: 4px;
        transition: 0.2s;
    }

    .selected-material-item .btn-remove:hover {
        background: #ffeaea;
    }
</style>

<body>
    <div id="wrapper">
        <?php require "layouts/sidebar-left.php"; ?>
        <div id="sidebar-right">
            <?php require "layouts/top-sidebar-right.php"; ?>
            <div class="main-content">
                <div class="wp-content-add-product">
                    <p class="name-title">THÊM SẢN PHẨM</p>

                    <?php if (!empty($_GET['error'])): ?>
                        <p class="error-msg"><?= htmlspecialchars($_GET['error']) ?></p>
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
                                <?php foreach ($data['categories'] as $cat): ?>
                                    <option value="<?= $cat['id'] ?>"><?= htmlspecialchars($cat['category_name']) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <!-- Hình ảnh -->
                        <div class="wp-upload">
                            <input type="file" name="product_image" id="file_upload" accept=".jpg,.jpeg,.png,.gif,.webp,.bmp" hidden>
                            <input type="text" id="file_name" placeholder="Chưa chọn hình ảnh" readonly>
                            <button type="button" id="btn_choose">Chọn hình ảnh</button>
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

                        <div class="wp-search-material">
                            <input type="text" id="search-material" placeholder="Tìm kiếm nguyên liệu..." autocomplete="off">
                            <div class="search-results" id="search-results"></div>
                        </div>

                        <div class="selected-materials" id="selected-materials">
                            <!-- JS sẽ render các material đã chọn -->
                        </div>

                        <button type="submit" class="btn-submit">THÊM SẢN PHẨM</button>
                    </form>
                </div>
            </div>
        </div>
    </div>

    <script defer>
        document.addEventListener('DOMContentLoaded', function () {
            // === Upload hình ảnh ===
            document.getElementById('btn_choose').addEventListener('click', function () {
                document.getElementById('file_upload').click();
            });

            document.getElementById('file_upload').addEventListener('change', function () {
                var file = this.files[0];
                if (file) {
                    var allowed = ['jpg', 'jpeg', 'png', 'gif', 'webp', 'bmp'];
                    var ext = file.name.split('.').pop().toLowerCase();
                    if (allowed.indexOf(ext) === -1) {
                        alert('Chỉ chấp nhận file hình ảnh (JPG, PNG, JPEG, GIF, WEBP, BMP)');
                        this.value = '';
                        document.getElementById('file_name').value = '';
                        return;
                    }
                    document.getElementById('file_name').value = file.name;
                }
            });

            // === Search material ===
            var searchInput = document.getElementById('search-material');
            var searchResults = document.getElementById('search-results');
            var selectedContainer = document.getElementById('selected-materials');
            var selectedMaterials = []; // [{id, material_name, supplier_name}]
            var searchTimeout = null;

            searchInput.addEventListener('input', function () {
                var keyword = this.value.trim();
                clearTimeout(searchTimeout);

                if (keyword.length < 1) {
                    searchResults.style.display = 'none';
                    return;
                }

                searchTimeout = setTimeout(function () {
                    $.ajax({
                        url: '?mod=product_profile&controllers=product_profile&action=search_material',
                        method: 'POST',
                        data: { keyword: keyword },
                        dataType: 'json',
                        success: function (res) {
                            searchResults.innerHTML = '';
                            if (res.data && res.data.length > 0) {
                                res.data.forEach(function (item) {
                                    // Bỏ qua item đã chọn
                                    var alreadySelected = selectedMaterials.some(function (m) {
                                        return m.id == item.id;
                                    });
                                    if (alreadySelected) return;

                                    var div = document.createElement('div');
                                    div.className = 'result-item';
                                    div.textContent = item.material_name + ' — ' + (item.supplier_name || 'Không có NCC');
                                    div.dataset.id = item.id;
                                    div.dataset.materialName = item.material_name;
                                    div.dataset.supplierName = item.supplier_name || '';
                                    div.addEventListener('click', function () {
                                        addMaterial({
                                            id: this.dataset.id,
                                            material_name: this.dataset.materialName,
                                            supplier_name: this.dataset.supplierName
                                        });
                                        searchInput.value = '';
                                        searchResults.style.display = 'none';
                                    });
                                    searchResults.appendChild(div);
                                });

                                if (searchResults.children.length === 0) {
                                    searchResults.innerHTML = '<div class="no-result">Tất cả kết quả đã được chọn</div>';
                                }
                                searchResults.style.display = 'block';
                            } else {
                                searchResults.innerHTML = '<div class="no-result">Không tìm thấy nguyên liệu</div>';
                                searchResults.style.display = 'block';
                            }
                        }
                    });
                }, 300);
            });

            // Ẩn kết quả khi click ra ngoài
            document.addEventListener('click', function (e) {
                if (!searchInput.contains(e.target) && !searchResults.contains(e.target)) {
                    searchResults.style.display = 'none';
                }
            });

            function addMaterial(material) {
                selectedMaterials.push(material);
                renderSelectedMaterials();
            }

            function removeMaterial(id) {
                selectedMaterials = selectedMaterials.filter(function (m) {
                    return m.id != id;
                });
                renderSelectedMaterials();
            }

            function renderSelectedMaterials() {
                selectedContainer.innerHTML = '';
                selectedMaterials.forEach(function (m) {
                    var div = document.createElement('div');
                    div.className = 'selected-material-item';
                    div.innerHTML =
                        '<input type="hidden" name="material_ids[]" value="' + m.id + '">' +
                        '<div class="material-info">' +
                            '<span class="material-name">' + m.material_name + '</span>' +
                            '<span class="supplier-name">NCC: ' + (m.supplier_name || 'Không có') + '</span>' +
                        '</div>' +
                        '<button type="button" class="btn-remove" data-id="' + m.id + '">' +
                            '<i class="fa-solid fa-xmark"></i>' +
                        '</button>';
                    selectedContainer.appendChild(div);
                });

                // Gắn event cho nút xóa
                selectedContainer.querySelectorAll('.btn-remove').forEach(function (btn) {
                    btn.addEventListener('click', function () {
                        removeMaterial(this.dataset.id);
                    });
                });
            }
        });
    </script>
    <script src="<?php echo asset_ver('public/js/shared/app_shell.js'); ?>"></script>
</body>

</html>
