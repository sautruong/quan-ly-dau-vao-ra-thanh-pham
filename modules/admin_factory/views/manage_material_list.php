<?php
defined('APPPATH') OR exit('Không được quyền truy cập phần này');
/** @var array $materials */
/** @var array $suppliers */
$materials = isset($materials) && is_array($materials) ? $materials : [];
$suppliers = isset($suppliers) && is_array($suppliers) ? $suppliers : [];
$ajax_base = '?mod=admin_factory&controllers=admin&action=';
$classifications = function_exists('admin_material_classification_options')
    ? admin_material_classification_options()
    : ['Nguyên liệu', 'Bao bì trong', 'Bao bì ngoài'];
?>
<!DOCTYPE html>
<html lang="vi">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>QUẢN LÝ DANH SÁCH NGUYÊN VẬT LIỆU</title>
    <link rel="icon" href="public/images/logo/logo_vat_png.png" type="image/png">
    <link rel="stylesheet" href="<?php echo asset_ver('public/css/reset.css'); ?>">
    <link rel="stylesheet" href="<?php echo asset_ver('public/css/all.css'); ?>">
    <link rel="stylesheet" href="<?php echo asset_ver('public/css/global.css'); ?>">
    <link rel="stylesheet" href="<?php echo asset_ver('public/css/inventory_management/dashboard.css'); ?>">
    <link rel="stylesheet" href="<?php echo asset_ver('public/css/inventory_management/investment_products.css'); ?>">
    <link rel="stylesheet" href="<?php echo asset_ver('public/css/accounting/journal_entry.css'); ?>">
    <style>
        .admin-table { width: 100%; border-collapse: collapse; font-size: 13px; }
        .admin-table th, .admin-table td { border: 1px solid #ccc; padding: 6px; vertical-align: middle; text-align: left; }
        .admin-table thead th { background: #f0f3f7; font-weight: 600; text-align: center; }
        /* Input text/number trong td: ẩn viền mặc định, chỉ hiện khi hover/focus. */
        .admin-table input[type="text"], .admin-table input[type="number"] {
            width: 100%; box-sizing: border-box; padding: 4px 6px;
            border: 1px solid transparent; border-radius: 4px; background: transparent;
            transition: border-color .15s ease, background-color .15s ease, box-shadow .15s ease;
        }
        .admin-table input[type="text"]:hover, .admin-table input[type="number"]:hover {
            border-color: #d0d4da; background: #fff;
        }
        .admin-table input[type="text"]:focus, .admin-table input[type="number"]:focus {
            border-color: #16a34a; background: #fff; outline: none;
            box-shadow: 0 0 0 2px rgba(22, 163, 74, 0.15);
        }
        /* Select Phân loại: viền cố định, KHÔNG đổi khi hover (tránh rung lắc). */
        .admin-table select {
            width: 100%; box-sizing: border-box; padding: 4px 6px;
            border: 1px solid #d0d4da; border-radius: 4px; background: #fff;
        }
        .admin-table select:focus {
            border-color: #16a34a; outline: none;
            box-shadow: 0 0 0 2px rgba(22, 163, 74, 0.15);
        }

        /* Canh giữa: Đơn vị, Giá mua, Giá mua gồm CPMH, Thao tác. */
        .admin-table .is-center,
        .admin-table .is-center input { text-align: center; }

        /* Cố định cụm toolbar + tiêu đề bảng khi cuộn. */
        .content .toolbar {
            position: sticky; top: 0; z-index: 30;
            background: #fff; margin: 0; padding: 8px 0 10px; min-height: 34px;
        }
        /* top hơi nhỏ hơn chiều cao toolbar để thead nằm sát ngay dưới, không hở. */
        .admin-table thead th {
            position: sticky; top: 48px; z-index: 20;
            box-shadow: inset 0 1px 0 #ccc, inset 0 -1px 0 #ccc;
        }
        /* Dải trắng phủ NGAY TRÊN tiêu đề: che các dòng tbody khi cuộn xuyên qua
           khoảng hở giữa toolbar và thead. Dính cùng thead (th là containing block sticky).
           Tràn -1px mỗi bên để phủ luôn đường border dọc giữa các cột (border-collapse). */
        .admin-table thead th::before {
            content: ""; position: absolute; left: -1px; right: -1px; bottom: 100%;
            height: 60px; background: #fff;
        }
        .col-name      { min-width: 280px; }
        .col-unit      { min-width: 80px; }
        .col-classify  { min-width: 150px; position: relative; }
        .col-supplier  { min-width: 250px; position: relative; }
        .col-price     { min-width: 120px; }

        /* Header có icon lọc: tiêu đề + nút lọc nằm ngang.
           (vị trí sticky của thead th khai báo bên dưới cũng làm containing block cho popup) */
        .th-flex { display: inline-flex; align-items: center; justify-content: center; gap: 6px; }
        .col-filter-btn {
            cursor: pointer; color: #8a93a0; font-size: 12px; padding: 2px;
            border-radius: 3px; line-height: 1;
        }
        .col-filter-btn:hover { color: #096926; background: #e6efe9; }
        .col-filter-btn.is-active { color: #096926; }
        .col-filter-pop {
            position: absolute; top: 100%; left: 50%; transform: translateX(-50%);
            margin-top: 4px; background: #fff; border: 1px solid #c2c8d0; border-radius: 6px;
            box-shadow: 0 6px 18px rgba(0,0,0,0.15); z-index: 200; width: 230px;
            display: none; text-align: left; font-weight: 400;
        }
        .col-filter-pop.show { display: block; }
        .cfp-list { max-height: 220px; overflow-y: auto; padding: 6px 0; }
        .cfp-list label {
            display: flex; align-items: center; gap: 8px; padding: 5px 12px;
            font-size: 13px; cursor: pointer;
        }
        .cfp-list label:hover { background: #f0f3f7; }
        .cfp-list input[type="checkbox"] { width: auto; margin: 0; }
        .cfp-foot {
            display: flex; justify-content: space-between; gap: 8px;
            padding: 8px 12px; border-top: 1px solid #e1e4e8;
        }
        .cfp-foot button {
            flex: 1; padding: 5px 8px; border-radius: 4px; cursor: pointer; font-size: 12px;
        }
        .cfp-clear { background: #eee; border: 1px solid #c2c8d0; }
        .cfp-apply { background: #096926; color: #fff; border: 0; }

        /* Bộ lọc Nhà cung cấp: ô nhập + dropdown gợi ý. */
        .cfp-sup-search-wrap { padding: 10px 12px 4px; position: relative; }
        .cfp-sup-search {
            width: 100%; box-sizing: border-box; padding: 6px 8px;
            border: 1px solid #c2c8d0; border-radius: 4px; font-size: 13px;
        }
        .cfp-sup-search:focus { border-color: #096926; outline: none; }
        .cfp-sup-list { max-height: 200px; overflow-y: auto; margin: 4px 0 2px; }
        .cfp-sup-item { padding: 6px 12px; font-size: 13px; cursor: pointer; }
        .cfp-sup-item:hover, .cfp-sup-item.active { background: #eef2f7; }
        .cfp-sup-item.empty { color: #999; font-style: italic; cursor: default; }
        .cfp-sup-chosen { padding: 2px 12px 6px; font-size: 12px; color: #096926; min-height: 14px; }

        /* Nút sao chép xuống (giống "fill handle" của Excel). */
        .col-classify { padding-right: 18px !important; }
        .fill-down {
            position: absolute; right: 3px; bottom: 3px; width: 15px; height: 15px;
            display: none; align-items: center; justify-content: center;
            background: #096926; color: #fff; border-radius: 3px; cursor: pointer;
            font-size: 10px; line-height: 1; user-select: none;
        }
        .col-classify:hover .fill-down { display: inline-flex; }
        .fill-down:hover { background: #128140; }
        .supplier-dropdown {
            position: absolute; top: 100%; left: 0; right: 0; background: #fff;
            border: 1px solid #c2c8d0; border-top: none; max-height: 220px; overflow-y: auto;
            z-index: 50; display: none; box-shadow: 0 4px 10px rgba(0,0,0,0.08);
        }
        .supplier-dropdown .item { padding: 6px 8px; cursor: pointer; font-size: 13px; }
        .supplier-dropdown .item:hover, .supplier-dropdown .item.active { background: #eef2f7; }
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
            background: #fff; color: #b22; border: 1px solid #e0a8a8;
            padding: 4px 10px; border-radius: 4px; cursor: pointer; font-size: 12px;
        }
        .btn-delete:hover { background: #fde2e2; }
        .btn-icon-delete {
            background: #fff; color: #b22; border: 1px solid #e0a8a8;
            width: 30px; height: 30px; border-radius: 4px; cursor: pointer;
            font-size: 13px; display: inline-flex; align-items: center; justify-content: center;
        }
        .btn-icon-delete:hover { background: #fde2e2; }
        .col-action { min-width: 80px; text-align: center; }

        .modal-backdrop {
            position: fixed; inset: 0; background: rgba(0,0,0,0.4);
            display: none; align-items: flex-start; justify-content: center;
            z-index: 1000; padding: 40px 0; overflow-y: auto;
        }
        .modal-backdrop.show { display: flex; }
        .modal {
            background: #fff; border-radius: 6px; width: 600px; max-width: 90vw;
            box-shadow: 0 10px 30px rgba(0,0,0,0.2);
        }
        .modal-header { padding: 12px 16px; border-bottom: 1px solid #e1e4e8; font-weight: 600; font-size: 14px; }
        .modal-body   {
            padding: 16px; display: grid; grid-template-columns: 170px 1fr;
            gap: 10px 14px; align-items: center;
        }
        .modal-body label { font-size: 13px; }
        .modal-body input[type="text"], .modal-body input[type="number"] {
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
        .modal-supplier-wrap { position: relative; }
        .modal-supplier-wrap .supplier-dropdown { top: 100%; }
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
                    <li class="tab-item">
                        <a href="?mod=admin_factory&controllers=admin&action=manage_product_list">Danh sách sản phẩm</a>
                    </li>
                    <li class="tab-item active">
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
            <div class="toolbar">
                <input type="text" class="search-input" id="searchInput" placeholder="Tìm theo tên nguyên vật liệu...">
                <div class="toolbar-actions" style="display:flex;gap:8px;">
                    <button type="button" class="btn-export-excel" data-export-excel data-export-target=".admin-table"
                        style="background:#1d6f42;color:#fff;border:0;padding:7px 14px;border-radius:4px;cursor:pointer;font-size:13px;">
                        <i class="fa-solid fa-file-excel"></i> Xuất Excel
                    </button>
                    <button type="button" class="btn-add" id="btnOpenAdd">+ Thêm</button>
                </div>
            </div>
            <table class="admin-table">
                <thead>
                    <tr>
                        <th class="col-name">Tên nguyên vật liệu</th>
                        <th class="col-name">Tên thường gọi</th>
                        <th class="col-name">Tên trên nhãn</th>
                        <th class="col-unit is-center">Đơn vị</th>
                        <th class="col-classify">
                            <span class="th-flex">Phân loại
                                <span class="col-filter-btn" data-filter-col="classification" title="Lọc phân loại"><i class="fa-solid fa-filter"></i></span>
                            </span>
                        </th>
                        <th class="col-supplier">
                            <span class="th-flex">Nhà cung cấp
                                <span class="col-filter-btn" data-filter-col="supplier" title="Lọc nhà cung cấp"><i class="fa-solid fa-filter"></i></span>
                            </span>
                        </th>
                        <th class="col-price is-center">Giá mua</th>
                        <th class="col-price is-center">Giá mua gồm CPMH</th>
                        <th class="col-price">Giá xuất xưởng</th>
                        <th class="col-action is-center">Thao tác</th>
                    </tr>
                </thead>
                <tbody>
                <?php if (empty($materials)): ?>
                    <tr><td colspan="10" style="text-align:center; padding:16px;">Chưa có nguyên vật liệu</td></tr>
                <?php else: foreach ($materials as $m):
                    $mid       = (int) $m['id'];
                    $name      = htmlspecialchars((string) ($m['material_name'] ?? ''), ENT_QUOTES, 'UTF-8');
                    $common    = htmlspecialchars((string) ($m['common_material_name'] ?? ''), ENT_QUOTES, 'UTF-8');
                    $label     = htmlspecialchars((string) ($m['label_name'] ?? ''), ENT_QUOTES, 'UTF-8');
                    $unit      = htmlspecialchars((string) ($m['unit'] ?? ''), ENT_QUOTES, 'UTF-8');
                    $classify  = (string) ($m['classification'] ?? '');
                    $sup_id    = (int) ($m['supplier_id'] ?? 0);
                    $sup_name  = htmlspecialchars((string) ($m['supplier_name'] ?? ''), ENT_QUOTES, 'UTF-8');
                    $price     = $m['purchase_price'] !== null ? (float) $m['purchase_price'] : '';
                    $price_inc = $m['purchase_price_includes_purchase_cost'] !== null ? (float) $m['purchase_price_includes_purchase_cost'] : '';
                    $sell      = $m['selling_price'] !== null ? (float) $m['selling_price'] : '';
                ?>
                    <tr data-material-id="<?php echo $mid; ?>">
                        <td class="col-name">
                            <input type="text" data-field="material_name" value="<?php echo $name; ?>">
                        </td>
                        <td class="col-name">
                            <input type="text" data-field="common_material_name" value="<?php echo $common; ?>" placeholder="Tên thường gọi...">
                        </td>
                        <td class="col-name">
                            <input type="text" data-field="label_name" value="<?php echo $label; ?>" placeholder="Tên in trên nhãn...">
                        </td>
                        <td class="col-unit is-center">
                            <input type="text" data-field="unit" value="<?php echo $unit; ?>">
                        </td>
                        <td class="col-classify">
                            <select data-field="classification">
                                <option value="">--</option>
                                <?php foreach ($classifications as $opt):
                                    $optEsc = htmlspecialchars($opt, ENT_QUOTES, 'UTF-8'); ?>
                                    <option value="<?php echo $optEsc; ?>" <?php echo $classify === $opt ? 'selected' : ''; ?>><?php echo $optEsc; ?></option>
                                <?php endforeach; ?>
                            </select>
                            <span class="fill-down" title="Sao chép xuống các dòng trống bên dưới (giống Excel)"><i class="fa-solid fa-arrow-down"></i></span>
                        </td>
                        <td class="col-supplier">
                            <input type="text" class="supplier-input" data-field="supplier_name"
                                value="<?php echo $sup_name; ?>"
                                data-supplier-id="<?php echo $sup_id; ?>"
                                autocomplete="off">
                            <div class="supplier-dropdown"></div>
                        </td>
                        <td class="col-price is-center">
                            <input type="text" data-field="purchase_price"
                                value="<?php echo $price === '' ? '' : htmlspecialchars((string) $price, ENT_QUOTES, 'UTF-8'); ?>">
                        </td>
                        <td class="col-price is-center">
                            <input type="text" data-field="purchase_price_includes_purchase_cost"
                                value="<?php echo $price_inc === '' ? '' : htmlspecialchars((string) $price_inc, ENT_QUOTES, 'UTF-8'); ?>">
                        </td>
                        <td class="col-price">
                            <input type="text" data-field="selling_price"
                                value="<?php echo $sell === '' ? '' : htmlspecialchars((string) $sell, ENT_QUOTES, 'UTF-8'); ?>">
                        </td>
                        <td class="col-action is-center">
                            <button type="button" class="btn-icon-delete" data-action="delete-material" title="Xóa">
                                <i class="fa-solid fa-trash"></i>
                            </button>
                        </td>
                    </tr>
                <?php endforeach; endif; ?>
                </tbody>
            </table>
        </div>
    </div>

    <!-- Modal: Thêm nguyên vật liệu -->
    <div class="modal-backdrop" id="addModal">
        <div class="modal">
            <div class="modal-header">Thêm nguyên vật liệu mới</div>
            <form id="addForm">
                <div class="modal-body">
                    <label>Tên nguyên vật liệu</label>
                    <input type="text" name="material_name" required>

                    <label>Tên trên nhãn</label>
                    <input type="text" name="label_name" placeholder="Có thể bỏ trống">

                    <label>Đơn vị</label>
                    <input type="text" name="unit">

                    <label>Phân loại</label>
                    <select name="classification" style="width:100%;box-sizing:border-box;padding:6px 8px;border:1px solid #c2c8d0;border-radius:4px;font-size:13px;">
                        <option value="">-- Chọn phân loại --</option>
                        <?php foreach ($classifications as $opt):
                            $optEsc = htmlspecialchars($opt, ENT_QUOTES, 'UTF-8'); ?>
                            <option value="<?php echo $optEsc; ?>"><?php echo $optEsc; ?></option>
                        <?php endforeach; ?>
                    </select>

                    <label>Nhà cung cấp</label>
                    <div class="modal-supplier-wrap">
                        <input type="text" id="addSupplierInput" autocomplete="off" placeholder="Nhập để tìm...">
                        <input type="hidden" name="supplier_id" id="addSupplierId">
                        <div class="supplier-dropdown" id="addSupplierDropdown"></div>
                    </div>

                    <label>Giá mua</label>
                    <input type="number" step="0.01" min="0" name="purchase_price">

                    <label>Giá mua gồm CPMH</label>
                    <input type="number" step="0.01" min="0" name="purchase_price_includes_purchase_cost">

                    <label>Giá xuất xưởng</label>
                    <input type="number" step="0.01" min="0" name="selling_price">
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
        var AJAX_BASE = <?php echo json_encode($ajax_base); ?>;

        /* ----------- Hiển thị dòng: kết hợp ô tìm kiếm + bộ lọc cột ----------- */
        // kw: từ khóa tên (contains).
        // classification: Set giá trị được phép | null (không lọc).
        // supplier: tên NCC được chọn (so khớp chính xác) | null (không lọc).
        var rowState = { kw: '', classification: null, supplier: null };

        function allRows() {
            return document.querySelectorAll('.admin-table tbody tr[data-material-id]');
        }
        function rowClassification(tr) {
            var s = tr.querySelector('select[data-field="classification"]');
            return s ? s.value : '';
        }
        function rowSupplier(tr) {
            var i = tr.querySelector('.supplier-input');
            return i ? i.value.trim() : '';
        }
        function applyRowVisibility() {
            allRows().forEach(function (tr) {
                var inp  = tr.querySelector('input[data-field="material_name"]');
                var name = inp ? inp.value.toLowerCase() : '';
                var okKw  = rowState.kw === '' || name.indexOf(rowState.kw) !== -1;
                var okCls = !rowState.classification || rowState.classification.has(rowClassification(tr));
                var okSup = (rowState.supplier === null) || rowSupplier(tr) === rowState.supplier;
                tr.style.display = (okKw && okCls && okSup) ? '' : 'none';
            });
        }

        /* Lọc bảng theo tên nguyên vật liệu (client-side, contains, không phân biệt hoa thường). */
        var searchInput = document.getElementById('searchInput');
        if (searchInput) {
            searchInput.addEventListener('input', function () {
                rowState.kw = searchInput.value.trim().toLowerCase();
                applyRowVisibility();
            });
        }

        function escHtml(s) { return String(s).replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;'); }
        function escAttr(s) { return String(s).replace(/"/g, '&quot;').replace(/&/g, '&amp;'); }

        function closeFilterPops() {
            document.querySelectorAll('.col-filter-pop').forEach(function (p) { p.remove(); });
        }

        /* ----------- Bộ lọc cột Phân loại (checkbox kiểu Excel) ----------- */
        function openFilterPop(btn, col) {
            // Tập giá trị phân biệt đang có trong cột.
            var values = [], seen = {};
            allRows().forEach(function (tr) {
                var v = rowClassification(tr);
                var key = v === '' ? '__EMPTY__' : v;
                if (!seen[key]) { seen[key] = true; values.push(v); }
            });
            values.sort(function (a, b) {
                if (a === '') return 1;
                if (b === '') return -1;
                return a.localeCompare(b, 'vi');
            });

            var active = rowState[col]; // Set | null
            var listHtml = values.map(function (v) {
                var checked = (!active || active.has(v)) ? 'checked' : '';
                var label = v === '' ? '(Trống)' : v;
                return '<label><input type="checkbox" value="' + escAttr(v) + '" ' + checked + '>' + escHtml(label) + '</label>';
            }).join('');

            var pop = document.createElement('div');
            pop.className = 'col-filter-pop show';
            pop.innerHTML =
                '<div class="cfp-list">' +
                    '<label style="border-bottom:1px solid #eee;font-weight:600;"><input type="checkbox" class="cfp-all" ' + (!active ? 'checked' : '') + '>(Chọn tất cả)</label>' +
                    listHtml +
                '</div>' +
                '<div class="cfp-foot"><button type="button" class="cfp-clear">Bỏ lọc</button><button type="button" class="cfp-apply">Lọc</button></div>';
            btn.closest('th').appendChild(pop);
            pop.addEventListener('click', function (e) { e.stopPropagation(); });

            var allCb   = pop.querySelector('.cfp-all');
            var itemCbs = Array.prototype.slice.call(pop.querySelectorAll('.cfp-list input:not(.cfp-all)'));
            allCb.addEventListener('change', function () {
                itemCbs.forEach(function (cb) { cb.checked = allCb.checked; });
            });
            itemCbs.forEach(function (cb) {
                cb.addEventListener('change', function () {
                    allCb.checked = itemCbs.every(function (c) { return c.checked; });
                });
            });

            pop.querySelector('.cfp-apply').addEventListener('click', function () {
                var picked = itemCbs.filter(function (cb) { return cb.checked; }).map(function (cb) { return cb.value; });
                if (picked.length === itemCbs.length) {
                    rowState[col] = null;
                    btn.classList.remove('is-active');
                } else {
                    rowState[col] = new Set(picked);
                    btn.classList.add('is-active');
                }
                applyRowVisibility();
                closeFilterPops();
            });
            pop.querySelector('.cfp-clear').addEventListener('click', function () {
                rowState[col] = null;
                btn.classList.remove('is-active');
                applyRowVisibility();
                closeFilterPops();
            });
        }

        /* ----------- Bộ lọc cột Nhà cung cấp (ô nhập + dropdown gợi ý) ----------- */
        function openSupplierFilterPop(btn) {
            var pop = document.createElement('div');
            pop.className = 'col-filter-pop show';
            pop.innerHTML =
                '<div class="cfp-sup-search-wrap">' +
                    '<input type="text" class="cfp-sup-search" placeholder="Nhập tên nhà cung cấp...">' +
                '</div>' +
                '<div class="cfp-sup-chosen"></div>' +
                '<div class="cfp-sup-list"></div>' +
                '<div class="cfp-foot"><button type="button" class="cfp-clear">Bỏ lọc</button><button type="button" class="cfp-apply">Lọc</button></div>';
            btn.closest('th').appendChild(pop);
            pop.addEventListener('click', function (e) { e.stopPropagation(); });

            var search  = pop.querySelector('.cfp-sup-search');
            var list    = pop.querySelector('.cfp-sup-list');
            var chosenEl = pop.querySelector('.cfp-sup-chosen');
            var chosen  = rowState.supplier; // tên đang lọc (nếu có)

            if (chosen) { search.value = chosen; chosenEl.textContent = 'Đang chọn: ' + chosen; }

            function renderItems(items) {
                if (!items.length) {
                    list.innerHTML = '<div class="cfp-sup-item empty">Không tìm thấy</div>';
                    return;
                }
                list.innerHTML = items.map(function (s) {
                    return '<div class="cfp-sup-item" data-name="' + escAttr(s.supplier_name) + '">' +
                           escHtml(s.supplier_name) + '</div>';
                }).join('');
            }

            var timer;
            function doSearch() {
                clearTimeout(timer);
                timer = setTimeout(function () {
                    fetchSuppliers(search.value).then(renderItems);
                }, 180);
            }
            search.addEventListener('input', function () {
                chosen = '';                       // gõ lại → bỏ chọn cũ cho tới khi click
                chosenEl.textContent = '';
                doSearch();
            });

            list.addEventListener('click', function (e) {
                var item = e.target.closest('.cfp-sup-item');
                if (!item || item.classList.contains('empty')) return;
                chosen = item.getAttribute('data-name');
                search.value = chosen;
                chosenEl.textContent = 'Đang chọn: ' + chosen;
            });

            pop.querySelector('.cfp-apply').addEventListener('click', function () {
                var name = chosen || search.value.trim();
                if (name === '') {                 // không nhập gì → coi như bỏ lọc
                    rowState.supplier = null;
                    btn.classList.remove('is-active');
                } else {
                    rowState.supplier = name;
                    btn.classList.add('is-active');
                }
                applyRowVisibility();
                closeFilterPops();
            });
            pop.querySelector('.cfp-clear').addEventListener('click', function () {
                rowState.supplier = null;
                btn.classList.remove('is-active');
                applyRowVisibility();
                closeFilterPops();
            });

            search.focus();
            fetchSuppliers('').then(renderItems);  // gợi ý ban đầu
        }

        document.querySelectorAll('.col-filter-btn').forEach(function (btn) {
            btn.addEventListener('click', function (e) {
                e.stopPropagation();
                var col = btn.getAttribute('data-filter-col');
                var wasOpen = btn.closest('th').querySelector('.col-filter-pop');
                closeFilterPops();
                if (wasOpen) return;
                if (col === 'supplier') openSupplierFilterPop(btn);
                else openFilterPop(btn, col);
            });
        });
        document.addEventListener('click', closeFilterPops);

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
            setTimeout(function () { cell.classList.remove('save-flash', 'ok', 'err'); }, 600);
        }
        function rowMaterialId(el) {
            var tr = el.closest('tr');
            return tr ? parseInt(tr.getAttribute('data-material-id'), 10) : 0;
        }
        function saveField(materialId, field, value, cell) {
            var fd = new FormData();
            fd.append('material_id', materialId);
            fd.append('field', field);
            fd.append('value', value);
            return fetch(AJAX_BASE + 'update_material_field', { method: 'POST', body: fd, credentials: 'same-origin' })
                .then(function (r) { return r.json(); })
                .then(function (res) {
                    if (res && res.ok) flash(cell, true);
                    else { flash(cell, false); showToast((res && res.message) || 'Lưu thất bại', true); }
                })
                .catch(function () { flash(cell, false); showToast('Lỗi kết nối', true); });
        }

        // Inputs (trừ supplier_name) — lưu khi change.
        document.querySelectorAll('.admin-table input[data-field]:not(.supplier-input)')
            .forEach(function (input) {
                input.addEventListener('change', function () {
                    saveField(rowMaterialId(input), input.getAttribute('data-field'), input.value, input.closest('td'));
                });
            });

        /* ----------- Định dạng tiền tệ cho 3 cột giá -----------
           Focus: hiện số thô để sửa. Blur: hiện "125,000 đ".
           Sự kiện 'change' (lưu giá trị) luôn fires trước 'blur', nên giá trị lưu
           xuống server vẫn là số thô, không dính định dạng. */
        function moneyRaw(str) {
            if (!str) return '';
            return String(str).replace(/[^0-9.\-]/g, '');
        }
        function moneyDisplay(str) {
            var raw = moneyRaw(str);
            if (raw === '') return '';
            var num = parseFloat(raw);
            if (isNaN(num)) return '';
            var parts = num.toString().split('.');
            parts[0] = parts[0].replace(/\B(?=(\d{3})+(?!\d))/g, ',');
            return parts.join('.') + ' đ';
        }
        document.querySelectorAll(
            '.admin-table input[data-field="purchase_price"],' +
            '.admin-table input[data-field="purchase_price_includes_purchase_cost"],' +
            '.admin-table input[data-field="selling_price"]'
        ).forEach(function (input) {
            input.value = moneyDisplay(input.value);
            input.addEventListener('focus', function () { input.value = moneyRaw(input.value); });
            input.addEventListener('blur', function () { input.value = moneyDisplay(input.value); });
        });

        // Select Phân loại — lưu khi change.
        document.querySelectorAll('.admin-table select[data-field="classification"]')
            .forEach(function (sel) {
                sel.addEventListener('change', function () {
                    saveField(rowMaterialId(sel), 'classification', sel.value, sel.closest('td'));
                });
            });

        /* ----------- Sao chép Phân loại xuống dưới (giống "fill handle" Excel) -----------
           Lấy giá trị ô hiện tại, điền xuống các dòng (đang hiển thị) bên dưới; chỉ điền
           vào dòng còn trống, gặp dòng đã có dữ liệu thì dừng. */
        document.querySelectorAll('.fill-down').forEach(function (handle) {
            handle.addEventListener('click', function (e) {
                e.stopPropagation();
                var td  = handle.closest('td');
                var tr  = td.closest('tr');
                var sel = td.querySelector('select[data-field="classification"]');
                var val = sel ? sel.value : '';
                if (val === '') { showToast('Ô này chưa có phân loại để sao chép', true); return; }

                var rows = Array.prototype.slice.call(allRows());
                var idx  = rows.indexOf(tr);
                var count = 0;
                for (var i = idx + 1; i < rows.length; i++) {
                    var r = rows[i];
                    if (r.style.display === 'none') continue;       // bỏ qua dòng đang bị lọc ẩn
                    var s = r.querySelector('select[data-field="classification"]');
                    if (!s) continue;
                    if (s.value !== '') break;                       // gặp dòng đã có dữ liệu → dừng
                    s.value = val;
                    saveField(rowMaterialId(s), 'classification', val, s.closest('td'));
                    count++;
                }
                if (count > 0) showToast('Đã sao chép "' + val + '" xuống ' + count + ' dòng');
                else showToast('Không có dòng trống bên dưới để sao chép', true);
            });
        });

        /* ----------- Autocomplete nhà cung cấp ----------- */
        var debounceTimers = new WeakMap();

        function fetchSuppliers(keyword) {
            var fd = new FormData();
            fd.append('keyword', keyword);
            return fetch(AJAX_BASE + 'search_suppliers', { method: 'POST', body: fd, credentials: 'same-origin' })
                .then(function (r) { return r.json(); })
                .then(function (res) { return (res && res.data) ? res.data : []; });
        }

        function renderDropdown(dropdown, items) {
            if (!items.length) {
                dropdown.innerHTML = '<div class="item" data-empty="1" style="color:#999;font-style:italic;">Không tìm thấy</div>';
            } else {
                dropdown.innerHTML = items.map(function (s) {
                    return '<div class="item" data-id="' + s.id + '">' +
                           s.supplier_name.replace(/</g, '&lt;') + '</div>';
                }).join('');
            }
            dropdown.style.display = 'block';
        }

        document.querySelectorAll('.supplier-input').forEach(function (input) {
            var td        = input.closest('td');
            var dropdown  = td.querySelector('.supplier-dropdown');

            input.addEventListener('input', function () {
                clearTimeout(debounceTimers.get(input));
                debounceTimers.set(input, setTimeout(function () {
                    fetchSuppliers(input.value).then(function (items) {
                        renderDropdown(dropdown, items);
                    });
                }, 180));
            });

            input.addEventListener('focus', function () {
                fetchSuppliers(input.value).then(function (items) {
                    renderDropdown(dropdown, items);
                });
            });

            input.addEventListener('blur', function () {
                // delay để click vào item kịp xử lý
                setTimeout(function () { dropdown.style.display = 'none'; }, 150);
            });

            dropdown.addEventListener('mousedown', function (e) {
                var item = e.target.closest('.item');
                if (!item || item.getAttribute('data-empty')) return;
                var id   = item.getAttribute('data-id');
                var name = item.textContent;
                input.value = name;
                input.setAttribute('data-supplier-id', id);
                dropdown.style.display = 'none';
                saveField(rowMaterialId(input), 'supplier_id', id, td);
            });
        });

        /* ----------- Xoá dòng ----------- */
        document.querySelectorAll('[data-action="delete-material"]').forEach(function (btn) {
            btn.addEventListener('click', function () {
                var mid = rowMaterialId(btn);
                if (!mid) return;
                var tr = btn.closest('tr');
                var nameInput = tr.querySelector('input[data-field="material_name"]');
                var name = nameInput ? nameInput.value : ('#' + mid);
                if (!confirm('Xóa NVL "' + name + '"? Thao tác này không thể hoàn tác.')) return;
                var fd = new FormData();
                fd.append('material_id', mid);
                fetch(AJAX_BASE + 'delete_material', { method: 'POST', body: fd, credentials: 'same-origin' })
                    .then(function (r) { return r.json(); })
                    .then(function (res) {
                        if (res && res.ok) {
                            showToast('Đã xóa NVL');
                            setTimeout(function () { location.reload(); }, 350);
                        } else {
                            showToast((res && res.message) || 'Xóa thất bại', true);
                        }
                    })
                    .catch(function () { showToast('Lỗi kết nối', true); });
            });
        });

        /* ----------- Modal Thêm + autocomplete supplier ----------- */
        var addModal             = document.getElementById('addModal');
        var addForm              = document.getElementById('addForm');
        var addSupplierInput     = document.getElementById('addSupplierInput');
        var addSupplierId        = document.getElementById('addSupplierId');
        var addSupplierDropdown  = document.getElementById('addSupplierDropdown');

        document.getElementById('btnOpenAdd').addEventListener('click', function () {
            addForm.reset();
            addSupplierId.value = '';
            addSupplierDropdown.style.display = 'none';
            addModal.classList.add('show');
        });
        document.getElementById('btnCancelAdd').addEventListener('click', function () {
            addModal.classList.remove('show');
        });
        addModal.addEventListener('click', function (e) {
            if (e.target === addModal) addModal.classList.remove('show');
        });

        var addDebounceTimer;
        addSupplierInput.addEventListener('input', function () {
            clearTimeout(addDebounceTimer);
            addSupplierId.value = ''; // user vừa gõ — invalid id cho tới khi chọn
            addDebounceTimer = setTimeout(function () {
                fetchSuppliers(addSupplierInput.value).then(function (items) {
                    renderDropdown(addSupplierDropdown, items);
                });
            }, 180);
        });
        addSupplierInput.addEventListener('focus', function () {
            fetchSuppliers(addSupplierInput.value).then(function (items) {
                renderDropdown(addSupplierDropdown, items);
            });
        });
        addSupplierInput.addEventListener('blur', function () {
            setTimeout(function () { addSupplierDropdown.style.display = 'none'; }, 150);
        });
        addSupplierDropdown.addEventListener('mousedown', function (e) {
            var item = e.target.closest('.item');
            if (!item || item.getAttribute('data-empty')) return;
            addSupplierId.value     = item.getAttribute('data-id');
            addSupplierInput.value  = item.textContent;
            addSupplierDropdown.style.display = 'none';
        });

        addForm.addEventListener('submit', function (e) {
            e.preventDefault();
            var fd = new FormData(addForm);
            fetch(AJAX_BASE + 'create_material', { method: 'POST', body: fd, credentials: 'same-origin' })
                .then(function (r) { return r.json(); })
                .then(function (res) {
                    if (res && res.ok) {
                        showToast('Đã thêm nguyên vật liệu');
                        setTimeout(function () { location.reload(); }, 350);
                    } else {
                        showToast((res && res.message) || 'Thêm thất bại', true);
                    }
                })
                .catch(function () { showToast('Lỗi kết nối', true); });
        });
    })();
    </script>
    <script src="<?php echo asset_ver('public/js/admin_factory/data_export_excel.js'); ?>"></script>
    <script src="<?php echo asset_ver('public/js/shared/app_shell.js'); ?>"></script>
</body>

</html>
