<?php
defined('APPPATH') OR exit('Không được quyền truy cập phần này');
/** @var array $customers */
$customers = isset($customers) && is_array($customers) ? $customers : [];
$ajax_base = '?mod=admin_factory&controllers=admin&action=';
?>
<!DOCTYPE html>
<html lang="vi">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>QUẢN LÝ DANH SÁCH KHÁCH HÀNG</title>
    <link rel="icon" href="public/images/logo/logo_vat_png.png" type="image/png">
    <link rel="stylesheet" href="public/css/reset.css">
    <link rel="stylesheet" href="public/css/all.css">
    <link rel="stylesheet" href="public/css/global.css">
    <link rel="stylesheet" href="<?php echo asset_ver('public/css/inventory_management/dashboard.css'); ?>">
    <link rel="stylesheet" href="public/css/inventory_management/investment_products.css">
    <link rel="stylesheet" href="public/css/accounting/journal_entry.css">
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
        .col-name        { min-width: 240px; }
        .col-short-name  { min-width: 140px; }
        .col-address     { min-width: 280px; }
        .col-receiver    { min-width: 180px; }
        .col-phone       { min-width: 130px; }
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
        .col-action { min-width: 80px; text-align: center; }

        /* ----- Màu nhận diện thứ cấp ----- */
        .col-color { min-width: 150px; text-align: center; }
        .color-picker {
            display: inline-flex; align-items: center; gap: 8px;
            padding: 4px 10px; border: 1px solid #d0d4da; border-radius: 6px;
            cursor: pointer; background: #fff; transition: border-color .15s ease, box-shadow .15s ease;
        }
        .color-picker:hover { border-color: #16a34a; box-shadow: 0 0 0 2px rgba(22,163,74,0.12); }
        .color-swatch {
            width: 20px; height: 20px; border-radius: 50%;
            border: 1px solid rgba(0,0,0,0.15); flex-shrink: 0; display: inline-block;
        }
        .color-text { font-size: 12px; color: #374151; }
        /* Ẩn input color gốc — click vào label vẫn mở bảng RGB của trình duyệt. */
        .color-picker input[type="color"] {
            position: absolute; width: 1px; height: 1px; opacity: 0; pointer-events: none;
        }

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
                    <li class="tab-item active">
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
                <input type="text" class="search-input" id="searchInput" placeholder="Tìm theo tên khách hàng...">
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
                        <th class="col-name">Tên khách hàng</th>
                        <th class="col-short-name">Tên viết tắt</th>
                        <th class="col-address">Địa chỉ</th>
                        <th class="col-receiver">Người nhận</th>
                        <th class="col-phone">Số điện thoại</th>
                        <th class="col-color">Màu nhận diện thứ cấp</th>
                        <th class="col-action">Thao tác</th>
                    </tr>
                </thead>
                <tbody>
                <?php if (empty($customers)): ?>
                    <tr><td colspan="7" style="text-align:center; padding:16px;">Chưa có khách hàng</td></tr>
                <?php else: foreach ($customers as $c):
                    $cid       = (int) $c['id'];
                    $name      = htmlspecialchars((string) ($c['name'] ?? ''), ENT_QUOTES, 'UTF-8');
                    $short     = htmlspecialchars((string) ($c['short_name'] ?? ''), ENT_QUOTES, 'UTF-8');
                    $address   = htmlspecialchars((string) ($c['address'] ?? ''), ENT_QUOTES, 'UTF-8');
                    $receiver  = htmlspecialchars((string) ($c['receiver'] ?? ''), ENT_QUOTES, 'UTF-8');
                    $phone     = htmlspecialchars((string) ($c['phone'] ?? ''), ENT_QUOTES, 'UTF-8');
                    $color     = (string) ($c['secondary_color'] ?? '');
                    if (!preg_match('/^#[0-9a-fA-F]{6}$/', $color)) { $color = '#000000'; }
                    $color     = htmlspecialchars($color, ENT_QUOTES, 'UTF-8');
                ?>
                    <tr data-customer-id="<?php echo $cid; ?>">
                        <td class="col-name">
                            <input type="text" data-field="name" value="<?php echo $name; ?>">
                        </td>
                        <td class="col-short-name">
                            <input type="text" data-field="short_name" value="<?php echo $short; ?>">
                        </td>
                        <td class="col-address">
                            <input type="text" data-field="address" value="<?php echo $address; ?>">
                        </td>
                        <td class="col-receiver">
                            <input type="text" data-field="receiver" value="<?php echo $receiver; ?>">
                        </td>
                        <td class="col-phone">
                            <input type="text" data-field="phone" value="<?php echo $phone; ?>">
                        </td>
                        <td class="col-color">
                            <label class="color-picker" title="Chọn màu nhận diện cho chi nhánh">
                                <span class="color-swatch" style="background:<?php echo $color; ?>"></span>
                                <span class="color-text">Chọn màu</span>
                                <input type="color" data-field="secondary_color" value="<?php echo $color; ?>">
                            </label>
                        </td>
                        <td class="col-action">
                            <button type="button" class="btn-delete" data-action="delete-customer">Xóa</button>
                        </td>
                    </tr>
                <?php endforeach; endif; ?>
                </tbody>
            </table>
        </div>
    </div>

    <!-- Modal: Thêm khách hàng -->
    <div class="modal-backdrop" id="addModal">
        <div class="modal">
            <div class="modal-header">Thêm khách hàng mới</div>
            <form id="addForm">
                <div class="modal-body">
                    <label>Tên khách hàng</label>
                    <input type="text" name="name" required>

                    <label>Tên viết tắt</label>
                    <input type="text" name="short_name">

                    <label>Địa chỉ</label>
                    <input type="text" name="address">

                    <label>Người nhận</label>
                    <input type="text" name="receiver">

                    <label>Số điện thoại</label>
                    <input type="text" name="phone">

                    <label>Màu nhận diện thứ cấp</label>
                    <label class="color-picker" title="Chọn màu nhận diện cho chi nhánh" style="justify-self:start;">
                        <span class="color-swatch" style="background:#000000"></span>
                        <span class="color-text">Chọn màu</span>
                        <input type="color" name="secondary_color" value="#000000">
                    </label>
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

        /* Lọc bảng theo tên khách hàng. */
        var searchInput = document.getElementById('searchInput');
        if (searchInput) {
            searchInput.addEventListener('input', function () {
                var kw = searchInput.value.trim().toLowerCase();
                document.querySelectorAll('.admin-table tbody tr[data-customer-id]').forEach(function (tr) {
                    var inp  = tr.querySelector('input[data-field="name"]');
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
        function rowCustomerId(el) {
            var tr = el.closest('tr');
            return tr ? parseInt(tr.getAttribute('data-customer-id'), 10) : 0;
        }
        function saveField(customerId, field, value, cell) {
            var fd = new FormData();
            fd.append('customer_id', customerId);
            fd.append('field', field);
            fd.append('value', value);
            return fetch(AJAX_BASE + 'update_customer_field', { method: 'POST', body: fd, credentials: 'same-origin' })
                .then(function (r) { return r.json(); })
                .then(function (res) {
                    if (res && res.ok) flash(cell, true);
                    else { flash(cell, false); showToast((res && res.message) || 'Lưu thất bại', true); }
                })
                .catch(function () { flash(cell, false); showToast('Lỗi kết nối', true); });
        }

        document.querySelectorAll('.admin-table input[data-field]').forEach(function (input) {
            input.addEventListener('change', function () {
                saveField(rowCustomerId(input), input.getAttribute('data-field'), input.value, input.closest('td'));
            });
        });

        /* Đồng bộ ô màu (swatch) khi chọn màu — cả lúc kéo (input) lẫn khi đóng (change). */
        function syncSwatch(colorInput) {
            var sw = colorInput.parentNode.querySelector('.color-swatch');
            if (sw) sw.style.background = colorInput.value;
        }
        document.querySelectorAll('input[type="color"]').forEach(function (ci) {
            ci.addEventListener('input', function () { syncSwatch(ci); });
            ci.addEventListener('change', function () { syncSwatch(ci); });
        });

        /* ----------- Xoá dòng ----------- */
        document.querySelectorAll('[data-action="delete-customer"]').forEach(function (btn) {
            btn.addEventListener('click', function () {
                var cid = rowCustomerId(btn);
                if (!cid) return;
                var tr = btn.closest('tr');
                var nameInput = tr.querySelector('input[data-field="name"]');
                var name = nameInput ? nameInput.value : ('#' + cid);
                if (!confirm('Xóa khách hàng "' + name + '"? Thao tác này không thể hoàn tác.')) return;
                var fd = new FormData();
                fd.append('customer_id', cid);
                fetch(AJAX_BASE + 'delete_customer', { method: 'POST', body: fd, credentials: 'same-origin' })
                    .then(function (r) { return r.json(); })
                    .then(function (res) {
                        if (res && res.ok) {
                            showToast('Đã xóa khách hàng');
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
            fetch(AJAX_BASE + 'create_customer', { method: 'POST', body: fd, credentials: 'same-origin' })
                .then(function (r) { return r.json(); })
                .then(function (res) {
                    if (res && res.ok) {
                        showToast('Đã thêm khách hàng');
                        setTimeout(function () { location.reload(); }, 350);
                    } else {
                        showToast((res && res.message) || 'Thêm thất bại', true);
                    }
                })
                .catch(function () { showToast('Lỗi kết nối', true); });
        });
    })();
    </script>
    <script src="public/js/admin_factory/data_export_excel.js"></script>
    <script src="<?php echo asset_ver('public/js/shared/app_shell.js'); ?>"></script>
    <script src="public/js/admin_factory/sticky_table.js"></script>
</body>

</html>
