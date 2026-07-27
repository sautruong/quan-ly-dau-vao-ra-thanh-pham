<?php
defined('APPPATH') OR exit('Không được quyền truy cập phần này');
/** @var array $products */
/** @var array $categories */
/** @var array $suppliers */
$products   = isset($products)   && is_array($products)   ? $products   : [];
$categories = isset($categories) && is_array($categories) ? $categories : [];
$suppliers  = isset($suppliers)  && is_array($suppliers)  ? $suppliers  : [];

$image_base = 'public/images/';
$ajax_base  = '?mod=admin_factory&controllers=admin&action=';
?>
<!DOCTYPE html>
<html lang="vi">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>QUẢN LÝ DANH SÁCH SẢN PHẨM</title>
    <link rel="icon" href="public/images/logo/logo_vat_png.png" type="image/png">
    <link rel="stylesheet" href="public/css/reset.css">
    <link rel="stylesheet" href="public/css/all.css">
    <link rel="stylesheet" href="public/css/global.css">
    <link rel="stylesheet" href="<?php echo asset_ver('public/css/inventory_management/dashboard.css'); ?>">
    <link rel="stylesheet" href="public/css/inventory_management/investment_products.css">
    <link rel="stylesheet" href="public/css/accounting/journal_entry.css">
    <link rel="stylesheet" href="<?php echo asset_ver('public/css/shared/app_shell.css'); ?>">
    <style>
        /* .content (dashboard.css) là flex column + align-items:center để canh giữa các
           cụm hẹp hơn nó — nhưng product-table thường RỘNG HƠN .content (nhiều cột min-width
           cộng lại). Khi 1 con flex bị canh giữa mà rộng hơn container, mép ĐẦU (trái) của nó
           bị tràn ra ngoài vùng cuộn — nhiều trình duyệt không cho kéo scroll tới mép đó được
           (chỉ kéo hết mép phải), nhìn như "mép trái bị che". Bỏ canh giữa riêng cho bảng này
           (ghim trái) + cho .content tự cuộn ngang khi cần → mép trái luôn ở đúng vị trí cuộn=0. */
        .content { overflow-x: auto; }
        .content .product-table { align-self: flex-start; }

        .product-table { width: 100%; border-collapse: collapse; font-size: 13px; }
        .product-table th, .product-table td { border: 1px solid #ccc; padding: 6px; vertical-align: middle; text-align: center; }
        .product-table thead th { background: #f0f3f7; font-weight: 600; text-align: center; }
        /* Input/select trong td: ẩn viền mặc định, chỉ hiện khi hover/focus. */
        .product-table input[type="text"], .product-table input[type="number"], .product-table select {
            width: 100%; box-sizing: border-box; padding: 4px 6px;
            border: 1px solid transparent; border-radius: 4px; background: transparent;
            transition: border-color .15s ease, background-color .15s ease, box-shadow .15s ease;
        }
        .product-table input[type="text"]:hover, .product-table input[type="number"]:hover, .product-table select:hover {
            border-color: #d0d4da; background: #fff;
        }
        .product-table input[type="text"]:focus, .product-table input[type="number"]:focus, .product-table select:focus {
            border-color: #16a34a; background: #fff; outline: none;
            box-shadow: 0 0 0 2px rgba(22, 163, 74, 0.15);
        }
        /* select (cột Danh mục / Loại sản phẩm) dùng chrome mặc định của trình duyệt —
           chrome đó tự vẽ thêm viền/hiệu ứng riêng khi hover, đè lên border-color ở trên
           và tạo cảm giác "rung" (2 lớp viền lệch nhau đổi liên tục). Tắt appearance mặc
           định + vẽ mũi tên riêng bằng CSS → chỉ còn đúng 1 lớp viền do ta kiểm soát. */
        .product-table select {
            -webkit-appearance: none;
            -moz-appearance: none;
            appearance: none;
            cursor: pointer;
            background-image: url("data:image/svg+xml;utf8,<svg xmlns='http://www.w3.org/2000/svg' width='10' height='6'><path d='M0 0l5 6 5-6z' fill='%23667085'/></svg>");
            background-repeat: no-repeat;
            background-position: right 8px center;
            padding-right: 22px;
        }
        .product-table .col-name      { min-width: 220px; }
        .product-table .col-common-name { min-width: 220px; }
        .product-table .col-unit      { min-width: 80px; text-align: center; }
        .product-table .col-unit input { text-align: center; }
        .product-table .col-image     { min-width: 170px; text-align: center; }
        .product-table .col-category  { min-width: 140px; }
        .product-table .col-pack-in   { min-width: 130px; }
        .product-table .col-pack-out  { min-width: 130px; }
        .product-table .col-weight    { min-width: 90px; }
        .product-table .col-nutri     { min-width: 70px;  text-align: center; }
        .product-table .col-newprod   { min-width: 70px;  text-align: center; }
        .product-table .col-price     { min-width: 120px; }
        .product-table .col-type      { min-width: 130px; }
        .product-table .col-supplier  { min-width: 200px; }

        /* Checkbox "Dinh dưỡng": tròn, xanh lá khi tích (thay cho checkbox vuông mặc định). */
        .product-table .col-nutri input[type="checkbox"] {
            -webkit-appearance: none;
            -moz-appearance: none;
            appearance: none;
            width: 18px; height: 18px;
            border: 1.5px solid #c2c8d0;
            border-radius: 50%;
            background: #fff;
            cursor: pointer;
            position: relative;
            vertical-align: middle;
            transition: background-color .15s ease, border-color .15s ease;
        }
        .product-table .col-nutri input[type="checkbox"]:hover { border-color: #16a34a; }
        .product-table .col-nutri input[type="checkbox"]:checked {
            background: #16a34a; border-color: #16a34a;
        }
        .product-table .col-nutri input[type="checkbox"]:checked::after {
            content: ""; position: absolute; left: 50%; top: 45%;
            width: 5px; height: 10px;
            border: solid #fff; border-width: 0 2px 2px 0;
            transform: translate(-50%, -50%) rotate(45deg);
        }

        /* Autocomplete nhà cung cấp (loại SP = Thương mại): input text + dropdown gợi ý. */
        .supplier-autocomplete { position: relative; }
        .supplier-dropdown {
            position: absolute; top: 100%; left: 0; right: 0; margin: 0; padding: 0;
            list-style: none; background: #fff; border: 1px solid #c2c8d0; border-top: none;
            max-height: 220px; overflow-y: auto; z-index: 50; display: none;
            box-shadow: 0 4px 10px rgba(0,0,0,0.08);
        }
        .supplier-dropdown li { padding: 6px 8px; cursor: pointer; font-size: 13px; }
        .supplier-dropdown li:hover, .supplier-dropdown li.active { background: #eef2f7; }
        .supplier-dropdown li.empty { color: #999; font-style: italic; cursor: default; }
        .modal-body .supplier-autocomplete { position: relative; }
        /* Nút trong .image-actions: chữ không xuống dòng (full width theo cột, xếp dọc),
           ẩn viền mặc định — chỉ hiện viền khi hover. */
        .btn-mini {
            padding: 4px 8px; font-size: 12px;
            border: 1px solid transparent; background: #fff; border-radius: 4px; cursor: pointer;
            white-space: nowrap;
        }
        .btn-mini:hover { background: #eef2f7; border-color: #c2c8d0; }
        /* "Xem hình ảnh" / "Chọn hình ảnh" thu gọn thành icon, xếp 1 hàng ngang. */
        .btn-mini.btn-icon {
            width: 30px; height: 30px; padding: 0;
            display: inline-flex; align-items: center; justify-content: center; font-size: 13px;
        }
        .image-actions { display: flex; flex-direction: row; align-items: center; justify-content: center; gap: 6px; }
        .supplier-fixed { color: #555; font-style: italic; padding: 4px 6px; }
        .save-flash { transition: background-color 0.4s ease; }
        .save-flash.ok  { background-color: #e6f7e6; }
        .save-flash.err { background-color: #fde2e2; }
        .toast {
            position: fixed; right: 16px; bottom: 16px;
            background: #333; color: #fff; padding: 10px 14px; border-radius: 4px;
            font-size: 13px; opacity: 0; transform: translateY(8px); transition: opacity .2s, transform .2s;
            z-index: 9999;
        }
        .toast.show { opacity: 1; transform: translateY(0); }
        .toast.err  { background: #b22; }
        .toolbar { display: flex; align-items: center; justify-content: space-between; margin: 0 0 10px; }
        .toolbar .search-input {
            padding: 6px 10px; min-width: 300px; border: 1px solid #c2c8d0;
            border-radius: 4px; font-size: 13px;
        }
        .btn-add {
            background: #096926; color: #fff; border: 0; padding: 7px 16px;
            border-radius: 4px; cursor: pointer; font-size: 13px;
        }
        .btn-add:hover { background: #128140; }
        .btn-delete {
            display: inline-flex; align-items: center; justify-content: center;
            width: 30px; height: 30px;
            background: #fff; color: #b22; border: 1px solid #e0a8a8;
            border-radius: 4px; cursor: pointer; font-size: 13px;
        }
        .btn-delete:hover { background: #fde2e2; }
        .col-action { min-width: 80px; text-align: center; }

        /* Modal */
        .modal-backdrop {
            position: fixed; inset: 0; background: rgba(0,0,0,0.4);
            display: none; align-items: flex-start; justify-content: center;
            z-index: 1000; padding: 40px 0; overflow-y: auto;
        }
        .modal-backdrop.show { display: flex; }
        .modal {
            background: #fff; border-radius: 6px; width: 640px; max-width: 90vw;
            box-shadow: 0 10px 30px rgba(0,0,0,0.2);
        }
        .modal-header { padding: 12px 16px; border-bottom: 1px solid #e1e4e8; font-weight: 600; font-size: 14px; }
        .modal-body   {
            padding: 16px; display: grid; grid-template-columns: 170px 1fr;
            gap: 10px 14px; align-items: center;
        }
        .modal-body label { font-size: 13px; }
        .modal-body input[type="text"], .modal-body input[type="number"], .modal-body select {
            width: 100%; box-sizing: border-box; padding: 6px 8px; border: 1px solid #c2c8d0; border-radius: 4px; font-size: 13px;
        }
        .modal-footer {
            padding: 12px 16px; border-top: 1px solid #e1e4e8;
            display: flex; justify-content: flex-end; gap: 8px;
        }
        .btn-cancel {
            background: #eee; border: 1px solid #c2c8d0; padding: 6px 14px;
            border-radius: 4px; cursor: pointer; font-size: 13px;
        }
        .btn-cancel:hover { background: #e2e2e2; }
    </style>
    <link rel="stylesheet" href="<?php echo asset_ver('public/css/shared/app_shell.css'); ?>">
</head>

<body>
    <div id="wrapper">
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
                    <h1>QUẢN LÝ DANH SÁCH</h1>
                </div>
                <label for="menu-toggle" class="menu-toggle-btn" aria-label="Mở menu">
                    <i class="fa-solid fa-bars"></i>
                </label>
            </div>
            <nav>
                <ul class="main-tab">
                    <li class="tab-item active">
                        <a href="?mod=admin_factory&controllers=admin&action=manage_product_list">Danh sách sản phẩm</a>
                    </li>
                    <li class="tab-item">
                        <a href="?mod=admin_factory&controllers=admin&action=manage_material_list">Danh sách NVL</a>
                    </li>
                    <li class="tab-item">
                        <a href="?mod=admin_factory&controllers=admin&action=manage_customer_list">Danh sách khách hàng</a>
                    </li>
                    <li class="tab-item">
                        <a href="?mod=admin_factory&controllers=admin&action=manage_supplier_list">Danh sách nhà cung cấp</a>
                    </li>
                </ul>
            </nav>
        </div>

        <div class="content">
            <div class="toolbar" data-sticky-top>
                <input type="text" class="search-input" id="searchInput" placeholder="Tìm theo tên sản phẩm...">
                <div class="toolbar-actions" style="display:flex;gap:8px;">
                    <button type="button" class="btn-export-excel" data-export-excel data-export-target=".product-table"
                        style="background:#1d6f42;color:#fff;border:0;padding:7px 14px;border-radius:4px;cursor:pointer;font-size:13px;">
                        <i class="fa-solid fa-file-excel"></i> Xuất Excel
                    </button>
                    <button type="button" class="btn-add" id="btnOpenAdd">+ Thêm</button>
                </div>
            </div>
            <table class="product-table" data-sticky-thead>
                <thead>
                    <tr>
                        <th class="col-name">Tên sản phẩm</th>
                        <th class="col-common-name">Tên thường gọi</th>
                        <th class="col-unit">Đơn vị</th>
                        <th class="col-image">Hình ảnh</th>
                        <th class="col-category">Danh mục</th>
                        <th class="col-pack-in">Quy cách bao bì trong</th>
                        <th class="col-pack-out">Quy cách bao bì ngoài</th>
                        <th class="col-weight">Khối lượng (kg)</th>
                        <th class="col-nutri">Dinh dưỡng</th>
                        <th class="col-newprod">Sản phẩm mới</th>
                        <th class="col-price">Giá xuất xưởng</th>
                        <th class="col-type">Loại sản phẩm</th>
                        <th class="col-supplier">Nhà cung cấp</th>
                        <th class="col-action">Thao tác</th>
                    </tr>
                </thead>
                <tbody>
                <?php if (empty($products)): ?>
                    <tr><td colspan="14" style="text-align:center; padding:16px;">Chưa có sản phẩm</td></tr>
                <?php else: foreach ($products as $p):
                    $pid          = (int) $p['id'];
                    $name         = htmlspecialchars((string) ($p['product_name'] ?? ''), ENT_QUOTES, 'UTF-8');
                    $common_raw   = trim((string) ($p['common_product_name'] ?? ''));
                    $common_name  = htmlspecialchars($common_raw !== '' ? $common_raw : (string) ($p['product_name'] ?? ''), ENT_QUOTES, 'UTF-8');
                    $unit         = htmlspecialchars((string) ($p['unit'] ?? ''), ENT_QUOTES, 'UTF-8');
                    $image_url    = (string) ($p['image_url'] ?? '');
                    $cat_id       = (int) ($p['category_id'] ?? 0);
                    $inner_spec   = htmlspecialchars((string) ($p['inner_packaging_spec'] ?? ''), ENT_QUOTES, 'UTF-8');
                    $outer_spec   = htmlspecialchars((string) ($p['outer_packaging_spec'] ?? ''), ENT_QUOTES, 'UTF-8');
                    $weight_kg    = $p['weight_kg'] !== null ? (float) $p['weight_kg'] : '';
                    $has_nutri    = (int) ($p['has_nutrition_fact'] ?? 0) === 1;
                    $is_new       = (int) ($p['is_new_product'] ?? 0) === 1;
                    $selling_price= $p['selling_price'] !== null ? (float) $p['selling_price'] : '';
                    $type         = (string) ($p['type'] ?? 'Sản xuất');
                    $supplier_id  = (int) ($p['supplier_id'] ?? 0);
                    $supplier_name= htmlspecialchars((string) ($p['supplier_name'] ?? ''), ENT_QUOTES, 'UTF-8');
                ?>
                    <tr data-product-id="<?php echo $pid; ?>">
                        <td class="col-name">
                            <input type="text" data-field="product_name" value="<?php echo $name; ?>">
                        </td>
                        <td class="col-common-name">
                            <input type="text" data-field="common_product_name" value="<?php echo $common_name; ?>" placeholder="Tên thường gọi...">
                        </td>
                        <td class="col-unit">
                            <input type="text" data-field="unit" value="<?php echo $unit; ?>">
                        </td>
                        <td class="col-image">
                            <div class="image-actions">
                                <button type="button" class="btn-mini btn-icon btn-view-image" title="Xem hình ảnh"
                                    data-image-url="<?php echo htmlspecialchars($image_url, ENT_QUOTES, 'UTF-8'); ?>"><i class="fa-solid fa-eye"></i></button>
                                <button type="button" class="btn-mini btn-icon btn-pick-image" title="Chọn hình ảnh"><i class="fa-solid fa-upload"></i></button>
                                <input type="file" class="file-input" accept="image/*" style="display:none;">
                            </div>
                        </td>
                        <td class="col-category">
                            <select data-field="category_id">
                                <option value="">-- Chọn --</option>
                                <?php foreach ($categories as $c):
                                    $cid_opt = (int) $c['id'];
                                    $cname   = htmlspecialchars((string) $c['category_name'], ENT_QUOTES, 'UTF-8');
                                    $sel     = $cid_opt === $cat_id ? ' selected' : '';
                                ?>
                                    <option value="<?php echo $cid_opt; ?>"<?php echo $sel; ?>><?php echo $cname; ?></option>
                                <?php endforeach; ?>
                            </select>
                        </td>
                        <td class="col-pack-in">
                            <input type="text" data-field="inner_packaging_spec" value="<?php echo $inner_spec; ?>">
                        </td>
                        <td class="col-pack-out">
                            <input type="text" data-field="outer_packaging_spec" value="<?php echo $outer_spec; ?>">
                        </td>
                        <td class="col-weight">
                            <input type="number" step="0.001" min="0" data-field="weight_kg" value="<?php echo $weight_kg === '' ? '' : htmlspecialchars((string) $weight_kg, ENT_QUOTES, 'UTF-8'); ?>">
                        </td>
                        <td class="col-nutri">
                            <input type="checkbox" data-field="has_nutrition_fact" <?php echo $has_nutri ? 'checked' : ''; ?>>
                        </td>
                        <td class="col-newprod">
                            <label class="app-round-check">
                                <input type="checkbox" data-field="is_new_product" <?php echo $is_new ? 'checked' : ''; ?>>
                                <span class="app-round-check-mark"><i class="fa-solid fa-check"></i></span>
                            </label>
                        </td>
                        <td class="col-price">
                            <input type="number" step="0.01" min="0" data-field="selling_price" value="<?php echo $selling_price === '' ? '' : htmlspecialchars((string) $selling_price, ENT_QUOTES, 'UTF-8'); ?>">
                        </td>
                        <td class="col-type">
                            <select data-field="type">
                                <option value="Sản xuất"<?php echo $type === 'Sản xuất' ? ' selected' : ''; ?>>Sản xuất</option>
                                <option value="Thương mại"<?php echo $type === 'Thương mại' ? ' selected' : ''; ?>>Thương mại</option>
                            </select>
                        </td>
                        <td class="col-supplier">
                            <span class="supplier-fixed"<?php echo $type === 'Sản xuất' ? '' : ' style="display:none;"'; ?>>VAT</span>
                            <div class="supplier-autocomplete"<?php echo $type === 'Sản xuất' ? ' style="display:none;"' : ''; ?>>
                                <input type="text" class="supplier-input"
                                    value="<?php echo $supplier_name; ?>"
                                    data-supplier-id="<?php echo $supplier_id; ?>"
                                    placeholder="Nhập tên nhà cung cấp..." autocomplete="off">
                                <ul class="supplier-dropdown"></ul>
                            </div>
                        </td>
                        <td class="col-action">
                            <button type="button" class="btn-delete" data-action="delete-product" title="Xóa">
                                <i class="fa-solid fa-trash"></i>
                            </button>
                        </td>
                    </tr>
                <?php endforeach; endif; ?>
                </tbody>
            </table>
        </div>
    </div>

    <!-- Modal: Thêm sản phẩm -->
    <div class="modal-backdrop" id="addModal">
        <div class="modal">
            <div class="modal-header">Thêm sản phẩm mới</div>
            <form id="addForm" enctype="multipart/form-data">
                <div class="modal-body">
                    <label>Tên sản phẩm</label>
                    <input type="text" name="product_name" required>

                    <label>Đơn vị</label>
                    <input type="text" name="unit">

                    <label>Hình ảnh</label>
                    <input type="file" name="image" accept="image/*">

                    <label>Danh mục</label>
                    <select name="category_id">
                        <option value="">-- Chọn --</option>
                        <?php foreach ($categories as $c):
                            $cid_opt = (int) $c['id'];
                            $cname   = htmlspecialchars((string) $c['category_name'], ENT_QUOTES, 'UTF-8');
                        ?>
                            <option value="<?php echo $cid_opt; ?>"><?php echo $cname; ?></option>
                        <?php endforeach; ?>
                    </select>

                    <label>Quy cách bao bì trong</label>
                    <input type="text" name="inner_packaging_spec">

                    <label>Quy cách bao bì ngoài</label>
                    <input type="text" name="outer_packaging_spec">

                    <label>Khối lượng (kg)</label>
                    <input type="number" step="0.001" min="0" name="weight_kg">

                    <label>Dinh dưỡng</label>
                    <input type="checkbox" name="has_nutrition_fact" value="1" style="width:auto;">

                    <label>Sản phẩm mới</label>
                    <label class="app-round-check">
                        <input type="checkbox" name="is_new_product" value="1">
                        <span class="app-round-check-mark"><i class="fa-solid fa-check"></i></span>
                    </label>

                    <label>Giá xuất xưởng</label>
                    <input type="number" step="0.01" min="0" name="selling_price">

                    <label>Loại sản phẩm</label>
                    <select name="type" id="addType">
                        <option value="Sản xuất">Sản xuất</option>
                        <option value="Thương mại">Thương mại</option>
                    </select>

                    <label>Nhà cung cấp</label>
                    <div>
                        <span id="addSupplierFixed" style="font-style:italic;color:#555;">VAT</span>
                        <div class="supplier-autocomplete" id="addSupplierWrap" style="display:none;">
                            <input type="text" id="addSupplierInput" placeholder="Nhập tên nhà cung cấp..." autocomplete="off">
                            <input type="hidden" name="supplier_id" id="addSupplierId">
                            <ul class="supplier-dropdown" id="addSupplierDropdown"></ul>
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn-cancel" id="btnCancelAdd">Hủy</button>
                    <button type="submit" class="btn-add">Thêm</button>
                </div>
            </form>
        </div>
    </div>

    <div id="toast" class="toast"></div>

    <script>
    (function () {
        var IMG_BASE  = <?php echo json_encode($image_base); ?>;
        var AJAX_BASE = <?php echo json_encode($ajax_base); ?>;
        var SUPPLIERS = <?php echo json_encode(array_map(function ($s) {
            return ['id' => (int) $s['id'], 'supplier_name' => (string) $s['supplier_name']];
        }, $suppliers), JSON_UNESCAPED_UNICODE); ?>;

        function escHtml(s) { return String(s == null ? '' : s).replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;'); }
        function escAttr(s) { return String(s == null ? '' : s).replace(/&/g, '&amp;').replace(/"/g, '&quot;'); }

        /* ----------- Autocomplete nhà cung cấp (client-side, danh sách đã nạp sẵn) -----------
         * ArrowUp/Down: di chuyển gợi ý đang chọn. Tab/Enter: chọn dòng đang active (hoặc dòng
         * đầu nếu chưa di chuyển). Escape: đóng dropdown. Click chuột: chọn trực tiếp. */
        function bindSupplierAutocomplete(input, dropdown, opts) {
            var activeIdx = -1;

            function filterSuppliers(kw) {
                var k = String(kw || '').trim().toLowerCase();
                if (k === '') return SUPPLIERS.slice(0, 20);
                return SUPPLIERS.filter(function (s) {
                    return s.supplier_name.toLowerCase().indexOf(k) !== -1;
                }).slice(0, 20);
            }

            function render(items) {
                activeIdx = -1;
                if (!items.length) {
                    dropdown.innerHTML = '<li class="empty">Không tìm thấy</li>';
                } else {
                    dropdown.innerHTML = items.map(function (s) {
                        return '<li data-id="' + s.id + '" data-name="' + escAttr(s.supplier_name) + '">' + escHtml(s.supplier_name) + '</li>';
                    }).join('');
                }
                dropdown.style.display = 'block';
            }

            function hide() {
                dropdown.style.display = 'none';
                dropdown.innerHTML = '';
                activeIdx = -1;
            }

            function pick(li) {
                var id   = li.getAttribute('data-id');
                var name = li.getAttribute('data-name');
                input.value = name;
                input.setAttribute('data-supplier-id', id);
                hide();
                if (opts && opts.onPick) opts.onPick(id, name);
            }

            input.addEventListener('input', function () {
                input.setAttribute('data-supplier-id', '');
                render(filterSuppliers(input.value));
            });
            input.addEventListener('focus', function () {
                render(filterSuppliers(input.value));
            });
            input.addEventListener('blur', function () {
                setTimeout(hide, 150); // delay để mousedown trên dropdown kịp xử lý
            });
            input.addEventListener('keydown', function (e) {
                if (dropdown.style.display !== 'block') return;
                var lis = dropdown.querySelectorAll('li:not(.empty)');
                if (!lis.length) return;
                if (e.key === 'ArrowDown') {
                    e.preventDefault();
                    activeIdx = (activeIdx + 1) % lis.length;
                } else if (e.key === 'ArrowUp') {
                    e.preventDefault();
                    activeIdx = (activeIdx - 1 + lis.length) % lis.length;
                } else if (e.key === 'Enter' || e.key === 'Tab') {
                    var idx = activeIdx >= 0 ? activeIdx : 0;
                    if (lis[idx]) {
                        e.preventDefault();
                        pick(lis[idx]);
                    }
                    return;
                } else if (e.key === 'Escape') {
                    hide();
                    return;
                } else {
                    return;
                }
                lis.forEach(function (li) { li.classList.remove('active'); });
                lis[activeIdx].classList.add('active');
                lis[activeIdx].scrollIntoView({ block: 'nearest' });
            });
            dropdown.addEventListener('mousedown', function (e) {
                var li = e.target.closest('li');
                if (!li || li.classList.contains('empty')) return;
                e.preventDefault(); // giữ focus, tránh blur trước khi chọn
                pick(li);
            });
        }

        /* Lọc bảng theo tên sản phẩm (client-side, contains, không phân biệt hoa thường). */
        var searchInput = document.getElementById('searchInput');
        if (searchInput) {
            searchInput.addEventListener('input', function () {
                var kw = searchInput.value.trim().toLowerCase();
                document.querySelectorAll('.product-table tbody tr[data-product-id]').forEach(function (tr) {
                    var inp  = tr.querySelector('input[data-field="product_name"]');
                    var name = inp ? inp.value.toLowerCase() : '';
                    tr.style.display = (kw === '' || name.indexOf(kw) !== -1) ? '' : 'none';
                });
            });
        }

        function showToast(msg, isErr) {
            var t = document.getElementById('toast');
            t.textContent = msg;
            t.classList.toggle('err', !!isErr);
            t.classList.add('show');
            clearTimeout(t._timer);
            t._timer = setTimeout(function () { t.classList.remove('show'); }, 1800);
        }

        function flash(cell, ok) {
            if (!cell) return;
            cell.classList.remove('save-flash', 'ok', 'err');
            void cell.offsetWidth;
            cell.classList.add('save-flash', ok ? 'ok' : 'err');
            setTimeout(function () {
                cell.classList.remove('save-flash', 'ok', 'err');
            }, 600);
        }

        function saveField(productId, field, value, cell) {
            var fd = new FormData();
            fd.append('product_id', productId);
            fd.append('field', field);
            fd.append('value', value);
            return fetch(AJAX_BASE + 'update_product_field', {
                method: 'POST',
                body: fd,
                credentials: 'same-origin'
            })
            .then(function (r) { return r.json(); })
            .then(function (res) {
                if (res && res.ok) {
                    flash(cell, true);
                } else {
                    flash(cell, false);
                    showToast((res && res.message) || 'Lưu thất bại', true);
                }
                return res;
            })
            .catch(function () {
                flash(cell, false);
                showToast('Lỗi kết nối', true);
            });
        }

        function rowProductId(el) {
            var tr = el.closest('tr');
            return tr ? parseInt(tr.getAttribute('data-product-id'), 10) : 0;
        }

        // Lưu khi input text/number rời focus (change).
        document.querySelectorAll('.product-table input[type="text"][data-field], .product-table input[type="number"][data-field]')
            .forEach(function (input) {
                input.addEventListener('change', function () {
                    var pid = rowProductId(input);
                    var field = input.getAttribute('data-field');
                    saveField(pid, field, input.value, input.closest('td'));
                });
            });

        // Checkbox dinh dưỡng.
        document.querySelectorAll('.product-table input[type="checkbox"][data-field]')
            .forEach(function (cb) {
                cb.addEventListener('change', function () {
                    var pid = rowProductId(cb);
                    saveField(pid, cb.getAttribute('data-field'), cb.checked ? '1' : '0', cb.closest('td'));
                });
            });

        // Select danh mục / loại sản phẩm.
        document.querySelectorAll('.product-table select[data-field]')
            .forEach(function (sel) {
                sel.addEventListener('change', function () {
                    var pid = rowProductId(sel);
                    var field = sel.getAttribute('data-field');
                    if (field === 'type') {
                        var tr = sel.closest('tr');
                        var fixedSpan     = tr.querySelector('.supplier-fixed');
                        var supplierWrap  = tr.querySelector('.supplier-autocomplete');
                        var supplierInput = supplierWrap ? supplierWrap.querySelector('.supplier-input') : null;
                        if (sel.value === 'Sản xuất') {
                            if (fixedSpan)  fixedSpan.style.display  = '';
                            if (supplierWrap) supplierWrap.style.display = 'none';
                            if (supplierInput) {
                                supplierInput.value = '';
                                supplierInput.setAttribute('data-supplier-id', '');
                            }
                        } else {
                            if (fixedSpan)  fixedSpan.style.display  = 'none';
                            if (supplierWrap) supplierWrap.style.display = '';
                        }
                    }
                    saveField(pid, field, sel.value, sel.closest('td'));
                });
            });

        // Autocomplete nhà cung cấp trên từng dòng (loại SP = Thương mại).
        document.querySelectorAll('.product-table .supplier-autocomplete').forEach(function (wrap) {
            var input    = wrap.querySelector('.supplier-input');
            var dropdown = wrap.querySelector('.supplier-dropdown');
            if (!input || !dropdown) return;
            bindSupplierAutocomplete(input, dropdown, {
                onPick: function (id) {
                    saveField(rowProductId(input), 'supplier_id', id, wrap.closest('td'));
                }
            });
        });

        // Xem hình ảnh.
        document.querySelectorAll('.btn-view-image').forEach(function (btn) {
            btn.addEventListener('click', function () {
                var url = btn.getAttribute('data-image-url');
                if (!url) {
                    showToast('Sản phẩm chưa có hình ảnh', true);
                    return;
                }
                window.open(IMG_BASE + url, '_blank');
            });
        });

        /* ----------- Xoá dòng ----------- */
        document.querySelectorAll('[data-action="delete-product"]').forEach(function (btn) {
            btn.addEventListener('click', function () {
                var pid = rowProductId(btn);
                if (!pid) return;
                var tr = btn.closest('tr');
                var nameInput = tr.querySelector('input[data-field="product_name"]');
                var name = nameInput ? nameInput.value : ('#' + pid);
                if (!confirm('Xóa sản phẩm "' + name + '"? Thao tác này không thể hoàn tác.')) return;
                var fd = new FormData();
                fd.append('product_id', pid);
                fetch(AJAX_BASE + 'delete_product', { method: 'POST', body: fd, credentials: 'same-origin' })
                    .then(function (r) { return r.json(); })
                    .then(function (res) {
                        if (res && res.ok) {
                            showToast('Đã xóa sản phẩm');
                            setTimeout(function () { location.reload(); }, 350);
                        } else {
                            showToast((res && res.message) || 'Xóa thất bại', true);
                        }
                    })
                    .catch(function () { showToast('Lỗi kết nối', true); });
            });
        });

        /* ----------- Modal Thêm ----------- */
        var addModal       = document.getElementById('addModal');
        var addForm        = document.getElementById('addForm');
        var addTypeSelect  = document.getElementById('addType');
        var addSupplierFix   = document.getElementById('addSupplierFixed');
        var addSupplierWrap  = document.getElementById('addSupplierWrap');
        var addSupplierInput = document.getElementById('addSupplierInput');
        var addSupplierId    = document.getElementById('addSupplierId');
        var addSupplierDropdown = document.getElementById('addSupplierDropdown');

        bindSupplierAutocomplete(addSupplierInput, addSupplierDropdown, {
            onPick: function (id) { addSupplierId.value = id; }
        });

        function resetAddSupplier() {
            addSupplierId.value = '';
            addSupplierInput.value = '';
            addSupplierInput.setAttribute('data-supplier-id', '');
        }

        function setAddSupplierMode() {
            if (addTypeSelect.value === 'Sản xuất') {
                addSupplierFix.style.display  = '';
                addSupplierWrap.style.display = 'none';
                resetAddSupplier();
            } else {
                addSupplierFix.style.display  = 'none';
                addSupplierWrap.style.display = '';
            }
        }
        addTypeSelect.addEventListener('change', setAddSupplierMode);

        document.getElementById('btnOpenAdd').addEventListener('click', function () {
            addForm.reset();
            resetAddSupplier();
            setAddSupplierMode();
            addModal.classList.add('show');
        });
        document.getElementById('btnCancelAdd').addEventListener('click', function () {
            addModal.classList.remove('show');
        });
        addModal.addEventListener('click', function (e) {
            if (e.target === addModal) addModal.classList.remove('show');
        });

        addForm.addEventListener('submit', function (e) {
            e.preventDefault();
            var fd = new FormData(addForm);
            // Checkbox unchecked => không có trong FormData; chuẩn hoá thành 0.
            if (!fd.has('has_nutrition_fact')) fd.set('has_nutrition_fact', '0');
            if (!fd.has('is_new_product')) fd.set('is_new_product', '0');
            fetch(AJAX_BASE + 'create_product', { method: 'POST', body: fd, credentials: 'same-origin' })
                .then(function (r) { return r.json(); })
                .then(function (res) {
                    if (res && res.ok) {
                        showToast('Đã thêm sản phẩm');
                        setTimeout(function () { location.reload(); }, 350);
                    } else {
                        showToast((res && res.message) || 'Thêm thất bại', true);
                    }
                })
                .catch(function () { showToast('Lỗi kết nối', true); });
        });

        // Chọn hình ảnh => mở file picker, upload, cập nhật url.
        document.querySelectorAll('.btn-pick-image').forEach(function (btn) {
            var fileInput = btn.parentNode.querySelector('.file-input');
            btn.addEventListener('click', function () { fileInput.click(); });
            fileInput.addEventListener('change', function () {
                if (!fileInput.files || !fileInput.files[0]) return;
                var pid = rowProductId(fileInput);
                var fd = new FormData();
                fd.append('product_id', pid);
                fd.append('image', fileInput.files[0]);
                fetch(AJAX_BASE + 'upload_product_image', { method: 'POST', body: fd, credentials: 'same-origin' })
                    .then(function (r) { return r.json(); })
                    .then(function (res) {
                        if (res && res.ok) {
                            var viewBtn = btn.parentNode.querySelector('.btn-view-image');
                            if (viewBtn) viewBtn.setAttribute('data-image-url', res.image_url);
                            flash(btn.closest('td'), true);
                            showToast('Đã cập nhật hình ảnh');
                        } else {
                            flash(btn.closest('td'), false);
                            showToast((res && res.message) || 'Upload thất bại', true);
                        }
                        fileInput.value = '';
                    })
                    .catch(function () {
                        flash(btn.closest('td'), false);
                        showToast('Lỗi kết nối', true);
                        fileInput.value = '';
                    });
            });
        });
    })();
    </script>
    <script src="public/js/admin_factory/data_export_excel.js"></script>
    <script src="<?php echo asset_ver('public/js/shared/app_shell.js'); ?>"></script>
    <script src="public/js/admin_factory/sticky_table.js"></script>
</body>

</html>
