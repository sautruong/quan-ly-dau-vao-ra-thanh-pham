<?php
defined('APPPATH') OR exit('Không được quyền truy cập phần này');
/** @var array $suppliers */
$suppliers = isset($suppliers) && is_array($suppliers) ? $suppliers : [];
$ajax_base = '?mod=admin_factory&controllers=admin&action=';
?>
<!DOCTYPE html>
<html lang="vi">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>QUẢN LÝ DANH SÁCH NHÀ CUNG CẤP</title>
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
        /* Input/select trong td: ẩn viền mặc định, chỉ hiện khi hover/focus. */
        .admin-table input[type="text"], .admin-table input[type="number"], .admin-table select {
            width: 100%; box-sizing: border-box; padding: 4px 6px;
            border: 1px solid transparent; border-radius: 4px; background: transparent;
            transition: border-color .15s ease, background-color .15s ease, box-shadow .15s ease;
        }
        .admin-table input[type="text"]:hover, .admin-table input[type="number"]:hover, .admin-table select:hover {
            border-color: #d0d4da; background: #fff;
        }
        .admin-table input[type="text"]:focus, .admin-table input[type="number"]:focus, .admin-table select:focus {
            border-color: #16a34a; background: #fff; outline: none;
            box-shadow: 0 0 0 2px rgba(22, 163, 74, 0.15);
        }
        .col-name    { min-width: 260px; }
        .col-short-name { min-width: 160px; }
        .col-address { min-width: 260px; }
        .col-email   { min-width: 220px; }
        .col-phone   { min-width: 140px; }
        .col-web     { min-width: 220px; }
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
        .btn-delete, .btn-view-info {
            width: 30px; height: 30px; border-radius: 50%; cursor: pointer; font-size: 13px;
            display: inline-flex; align-items: center; justify-content: center; margin: 0 2px;
        }
        .btn-delete { background: #fff; color: #b22; border: 1px solid #e0a8a8; }
        .btn-delete:hover { background: #fde2e2; }
        .btn-view-info { background: #fff; color: #096926; border: 1px solid #a8d0b0; }
        .btn-view-info:hover { background: #e6f7e6; }
        .col-action { min-width: 80px; }
        .admin-table td.col-action { text-align: center; }

        /* Modal: Xem thông tin nhà cung cấp */
        .modal-view-info .modal-header { font-size: 16px; }
        .view-info-body { display: flex; flex-direction: column; gap: 12px; padding: 16px; }
        .view-info-row { display: flex; align-items: center; gap: 10px; font-size: 14px; color: #333; }
        .view-info-row i { width: 18px; text-align: center; color: #096926; }

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
        .modal-body input[type="text"] {
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
                    <li class="tab-item">
                        <a href="?mod=admin_factory&controllers=admin&action=manage_product_list">Danh sách sản phẩm</a>
                    </li>
                    <li class="tab-item">
                        <a href="?mod=admin_factory&controllers=admin&action=manage_material_list">Danh sách NVL</a>
                    </li>
                    <li class="tab-item">
                        <a href="?mod=admin_factory&controllers=admin&action=manage_customer_list">Danh sách khách hàng</a>
                    </li>
                    <li class="tab-item active">
                        <a href="?mod=admin_factory&controllers=admin&action=manage_supplier_list">Danh sách nhà cung cấp</a>
                    </li>
                </ul>
            </nav>
        </div>

        <div class="content">
            <div class="toolbar" data-sticky-top>
                <input type="text" class="search-input" id="searchInput" placeholder="Tìm theo tên nhà cung cấp...">
                <div class="toolbar-actions" style="display:flex;gap:8px;">
                    <button type="button" class="btn-export-excel" data-export-excel data-export-target=".admin-table"
                        style="background:#1d6f42;color:#fff;border:0;padding:7px 14px;border-radius:4px;cursor:pointer;font-size:13px;">
                        <i class="fa-solid fa-file-excel"></i> Xuất Excel
                    </button>
                    <button type="button" class="btn-add" id="btnOpenAdd">+ Thêm</button>
                </div>
            </div>
            <table class="admin-table" data-sticky-thead>
                <thead>
                    <tr>
                        <th class="col-name">Tên nhà cung cấp</th>
                        <th class="col-short-name">Tên viết tắt</th>
                        <th class="col-address">Địa chỉ</th>
                        <th class="col-email">Email</th>
                        <th class="col-phone">Số điện thoại</th>
                        <th class="col-web">Website</th>
                        <th class="col-action">Thao tác</th>
                    </tr>
                </thead>
                <tbody>
                <?php if (empty($suppliers)): ?>
                    <tr><td colspan="7" style="text-align:center; padding:16px;">Chưa có nhà cung cấp</td></tr>
                <?php else: foreach ($suppliers as $s):
                    $sid     = (int) $s['id'];
                    $name    = htmlspecialchars((string) ($s['supplier_name'] ?? ''), ENT_QUOTES, 'UTF-8');
                    $short   = htmlspecialchars((string) ($s['short_name'] ?? ''), ENT_QUOTES, 'UTF-8');
                    $address = htmlspecialchars((string) ($s['address'] ?? ''), ENT_QUOTES, 'UTF-8');
                    $email   = htmlspecialchars((string) ($s['email'] ?? ''), ENT_QUOTES, 'UTF-8');
                    $phone   = htmlspecialchars((string) ($s['phone_number'] ?? ''), ENT_QUOTES, 'UTF-8');
                    $website = htmlspecialchars((string) ($s['website'] ?? ''), ENT_QUOTES, 'UTF-8');
                ?>
                    <tr data-supplier-id="<?php echo $sid; ?>">
                        <td class="col-name">
                            <input type="text" data-field="supplier_name" value="<?php echo $name; ?>">
                        </td>
                        <td class="col-short-name">
                            <input type="text" data-field="short_name" value="<?php echo $short; ?>">
                        </td>
                        <td class="col-address">
                            <input type="text" data-field="address" value="<?php echo $address; ?>">
                        </td>
                        <td class="col-email">
                            <input type="text" data-field="email" value="<?php echo $email; ?>">
                        </td>
                        <td class="col-phone">
                            <input type="text" data-field="phone_number" value="<?php echo $phone; ?>">
                        </td>
                        <td class="col-web">
                            <input type="text" data-field="website" value="<?php echo $website; ?>">
                        </td>
                        <td class="col-action">
                            <button type="button" class="btn-view-info" data-action="view-supplier-info" title="Xem thông tin">
                                <i class="fa-solid fa-circle-info"></i>
                            </button>
                            <button type="button" class="btn-delete" data-action="delete-supplier" title="Xóa">
                                <i class="fa-solid fa-trash"></i>
                            </button>
                        </td>
                    </tr>
                <?php endforeach; endif; ?>
                </tbody>
            </table>
        </div>
    </div>

    <!-- Modal: Thêm nhà cung cấp -->
    <div class="modal-backdrop" id="addModal">
        <div class="modal">
            <div class="modal-header">Thêm nhà cung cấp mới</div>
            <form id="addForm">
                <div class="modal-body">
                    <label>Tên nhà cung cấp</label>
                    <input type="text" name="supplier_name" required>

                    <label>Tên viết tắt</label>
                    <input type="text" name="short_name">

                    <label>Địa chỉ</label>
                    <input type="text" name="address">

                    <label>Email</label>
                    <input type="text" name="email">

                    <label>Số điện thoại</label>
                    <input type="text" name="phone_number">

                    <label>Website</label>
                    <input type="text" name="website">
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn-cancel" id="btnCancelAdd">Hủy</button>
                    <button type="submit" class="btn-add">Thêm</button>
                </div>
            </form>
        </div>
    </div>

    <!-- Modal: Xem thông tin nhà cung cấp -->
    <div class="modal-backdrop" id="viewInfoModal">
        <div class="modal modal-view-info">
            <div class="modal-header" id="viewInfoName">Nhà cung cấp</div>
            <div class="view-info-body">
                <div class="view-info-row" id="viewInfoAddressRow"><i class="fa-solid fa-location-dot"></i> <span id="viewInfoAddress"></span></div>
                <div class="view-info-row" id="viewInfoEmailRow"><i class="fa-solid fa-envelope"></i> <span id="viewInfoEmail"></span></div>
                <div class="view-info-row" id="viewInfoPhoneRow"><i class="fa-solid fa-phone"></i> <span id="viewInfoPhone"></span></div>
                <div class="view-info-row" id="viewInfoWebsiteRow"><i class="fa-solid fa-globe"></i> <span id="viewInfoWebsite"></span></div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn-cancel" id="btnCloseViewInfo">Đóng</button>
            </div>
        </div>
    </div>

    <div id="toast" class="toast"></div>

    <script>
    (function () {
        var AJAX_BASE = <?php echo json_encode($ajax_base); ?>;

        /* Lọc bảng theo tên nhà cung cấp. */
        var searchInput = document.getElementById('searchInput');
        if (searchInput) {
            searchInput.addEventListener('input', function () {
                var kw = searchInput.value.trim().toLowerCase();
                document.querySelectorAll('.admin-table tbody tr[data-supplier-id]').forEach(function (tr) {
                    var inp  = tr.querySelector('input[data-field="supplier_name"]');
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
            setTimeout(function () { cell.classList.remove('save-flash', 'ok', 'err'); }, 600);
        }
        function rowSupplierId(el) {
            var tr = el.closest('tr');
            return tr ? parseInt(tr.getAttribute('data-supplier-id'), 10) : 0;
        }
        function saveField(supplierId, field, value, cell) {
            var fd = new FormData();
            fd.append('supplier_id', supplierId);
            fd.append('field', field);
            fd.append('value', value);
            return fetch(AJAX_BASE + 'update_supplier_field', { method: 'POST', body: fd, credentials: 'same-origin' })
                .then(function (r) { return r.json(); })
                .then(function (res) {
                    if (res && res.ok) flash(cell, true);
                    else { flash(cell, false); showToast((res && res.message) || 'Lưu thất bại', true); }
                })
                .catch(function () { flash(cell, false); showToast('Lỗi kết nối', true); });
        }

        document.querySelectorAll('.admin-table input[data-field]').forEach(function (input) {
            input.addEventListener('change', function () {
                saveField(rowSupplierId(input), input.getAttribute('data-field'), input.value, input.closest('td'));
            });
        });

        /* ----------- Xem thông tin nhà cung cấp ----------- */
        var viewInfoModal = document.getElementById('viewInfoModal');
        function setInfoRow(rowId, spanId, value) {
            var row = document.getElementById(rowId);
            if (!value) { row.style.display = 'none'; return; }
            row.style.display = '';
            document.getElementById(spanId).textContent = value;
        }
        document.querySelectorAll('[data-action="view-supplier-info"]').forEach(function (btn) {
            btn.addEventListener('click', function () {
                var tr = btn.closest('tr');
                var get = function (field) {
                    var el = tr.querySelector('input[data-field="' + field + '"]');
                    return el ? el.value.trim() : '';
                };
                document.getElementById('viewInfoName').textContent = get('supplier_name') || 'Nhà cung cấp #' + rowSupplierId(btn);
                setInfoRow('viewInfoAddressRow', 'viewInfoAddress', get('address'));
                setInfoRow('viewInfoEmailRow', 'viewInfoEmail', get('email'));
                setInfoRow('viewInfoPhoneRow', 'viewInfoPhone', get('phone_number'));
                setInfoRow('viewInfoWebsiteRow', 'viewInfoWebsite', get('website'));
                viewInfoModal.classList.add('show');
            });
        });
        document.getElementById('btnCloseViewInfo').addEventListener('click', function () {
            viewInfoModal.classList.remove('show');
        });
        viewInfoModal.addEventListener('click', function (e) {
            if (e.target === viewInfoModal) viewInfoModal.classList.remove('show');
        });

        /* ----------- Xoá dòng ----------- */
        document.querySelectorAll('[data-action="delete-supplier"]').forEach(function (btn) {
            btn.addEventListener('click', function () {
                var sid = rowSupplierId(btn);
                if (!sid) return;
                var tr = btn.closest('tr');
                var nameInput = tr.querySelector('input[data-field="supplier_name"]');
                var name = nameInput ? nameInput.value : ('#' + sid);
                if (!confirm('Xóa nhà cung cấp "' + name + '"? Thao tác này không thể hoàn tác.')) return;
                var fd = new FormData();
                fd.append('supplier_id', sid);
                fetch(AJAX_BASE + 'delete_supplier', { method: 'POST', body: fd, credentials: 'same-origin' })
                    .then(function (r) { return r.json(); })
                    .then(function (res) {
                        if (res && res.ok) {
                            showToast('Đã xóa nhà cung cấp');
                            setTimeout(function () { location.reload(); }, 350);
                        } else {
                            showToast((res && res.message) || 'Xóa thất bại', true);
                        }
                    })
                    .catch(function () { showToast('Lỗi kết nối', true); });
            });
        });

        /* ----------- Modal Thêm ----------- */
        var addModal = document.getElementById('addModal');
        var addForm  = document.getElementById('addForm');
        document.getElementById('btnOpenAdd').addEventListener('click', function () {
            addForm.reset();
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
            fetch(AJAX_BASE + 'create_supplier', { method: 'POST', body: fd, credentials: 'same-origin' })
                .then(function (r) { return r.json(); })
                .then(function (res) {
                    if (res && res.ok) {
                        showToast('Đã thêm nhà cung cấp');
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
    <script src="<?php echo asset_ver('public/js/admin_factory/sticky_table.js'); ?>"></script>
</body>

</html>
