<?php
defined('APPPATH') OR exit('Không được quyền truy cập phần này');
/** @var array $rows */
$rows = isset($rows) && is_array($rows) ? $rows : [];
$from = isset($from) ? (string) $from : '';
$to   = isset($to)   ? (string) $to   : '';
$invoices_map = isset($invoices_map) && is_array($invoices_map) ? $invoices_map : [];
$export_invoices_map = isset($export_invoices_map) && is_array($export_invoices_map) ? $export_invoices_map : [];
?>
<!DOCTYPE html>
<html lang="vi">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>DỮ LIỆU HOẠT ĐỘNG NHÀ MÁY</title>
    <link rel="icon" href="public/images/logo/logo_vat_png.png" type="image/png">
    <link rel="stylesheet" href="<?php echo asset_ver('public/css/reset.css'); ?>">
    <link rel="stylesheet" href="<?php echo asset_ver('public/css/all.css'); ?>">
    <link rel="stylesheet" href="<?php echo asset_ver('public/css/global.css'); ?>">
    <link rel="stylesheet" href="<?php echo asset_ver('public/css/inventory_management/dashboard.css'); ?>">
    <link rel="stylesheet" href="<?php echo asset_ver('public/css/inventory_management/investment_products.css'); ?>">
    <link rel="stylesheet" href="<?php echo asset_ver('public/css/accounting/journal_entry.css'); ?>">
    <link rel="stylesheet" href="<?php echo asset_ver('public/css/admin_factory/data_dashboard.css'); ?>">
    <link rel="stylesheet" href="<?php echo asset_ver('public/css/shared/invoice_upload.css'); ?>">
    <link rel="stylesheet" href="<?php echo asset_ver('public/css/admin_factory/value_detail.css'); ?>">
    <style>
        .product-table { width: 100%; border-collapse: collapse; font-size: 13px; }
        .product-table th, .product-table td { border: 1px solid #ccc; padding: 6px; vertical-align: middle; text-align: left; }
        .product-table thead th { background: #f0f3f7; font-weight: 600; text-align: center; }
        .product-table td.num { text-align: right; }
        .product-table td.center { text-align: center; }
        .datatable-wrap { margin-top: 8px; }
        .datatable-toolbar {
            display: flex; align-items: center; justify-content: space-between;
            margin: 0 0 10px; font-size: 13px;
        }
        .datatable-toolbar .rows-per-page { display: flex; align-items: center; gap: 8px; }
        .datatable-toolbar select {
            padding: 5px 8px; border: 1px solid #c2c8d0; border-radius: 4px; font-size: 13px;
        }
        .datatable-toolbar [data-rows-info] { color: #555; }
        .datatable-pagination {
            margin-top: 10px; display: flex; gap: 4px; justify-content: center; flex-wrap: wrap;
        }
        .page-btn {
            min-width: 32px; padding: 5px 10px; border: 1px solid #c2c8d0; background: #fff;
            border-radius: 4px; cursor: pointer; font-size: 13px;
        }
        .page-btn:hover:not([disabled]) { background: #eef2f7; }
        .page-btn.active { background: #096926; color: #fff; border-color: #096926; }
        .page-btn[disabled] { opacity: 0.4; cursor: not-allowed; }
        .page-ellipsis { padding: 5px 4px; color: #888; }
        .empty-row td { text-align: center; color: #888; padding: 24px 6px; }
        /* Vòng tròn nhận diện màu thứ cấp của khách hàng (secondary_color) */
        .cust-color-dot {
            display: inline-block; width: 14px; height: 14px; border-radius: 50%;
            margin-right: 7px; vertical-align: middle; flex: 0 0 auto;
            border: 1px solid rgba(0, 0, 0, .25); box-shadow: 0 0 0 1px #fff inset;
        }
        .vd-amount { font-weight: 600; }
        .so-weight-input {
            width: 88px; text-align: right; font: inherit; padding: 3px 6px;
            border: 1px solid transparent; border-radius: 4px; background: transparent;
            transition: border-color .15s, background .15s, box-shadow .15s;
        }
        .so-weight-input:hover { border-color: #d0d4da; background: #fff; }
        .so-weight-input:focus { border-color: #16a34a; background: #fff; outline: none; box-shadow: 0 0 0 2px rgba(22,163,74,.15); }
        .so-weight-input.saved { background: #e6f7e6; }
        .so-weight-input.err { background: #fde2e2; }
    </style>
    <link rel="stylesheet" href="<?php echo asset_ver('public/css/shared/app_shell.css'); ?>">
</head>

<body>
    <div id="wrapper" class="has-sider">
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
                    <h1>DL HOẠT ĐỘNG NHÀ MÁY</h1>
                </div>
                <label for="menu-toggle" class="menu-toggle-btn" aria-label="Mở menu">
                    <i class="fa-solid fa-bars"></i>
                </label>
            </div>
            <nav>
                <ul class="main-tab">
                    <li class="tab-item">
                        <a href="?mod=admin_factory&controllers=admin&action=finished_product_production_data">Dữ liệu nhập thành phẩm sản xuất</a>
                    </li>
                    <li class="tab-item">
                        <a href="?mod=admin_factory&controllers=admin&action=purchased_finished_product_data">Dữ liệu nhập thành phẩm mua hàng</a>
                    </li>
                    <li class="tab-item">
                        <a href="?mod=admin_factory&controllers=admin&action=raw_material_purchase_data">Dữ liệu nhập mua NVL</a>
                    </li>
                    <li class="tab-item">
                        <a href="?mod=admin_factory&controllers=admin&action=raw_material_production_issue_data">Dữ liệu xuất NVL dùng sản xuất</a>
                    </li>
                    <li class="tab-item">
                        <a href="?mod=admin_factory&controllers=admin&action=sale_inventory_issue_data">Dữ liệu xuất kho bán hàng</a>
                    </li>
                    <li class="tab-item">
                        <a href="?mod=admin_factory&controllers=admin&action=accounting_data">Dữ liệu kế toán</a>
                    </li>
                    <li class="tab-item">
                        <a href="?mod=admin_factory&controllers=admin&action=cash_transactions_data">Dữ liệu thu chi</a>
                    </li>
                    <li class="tab-item">
                        <a href="?mod=admin_factory&controllers=admin&action=purchase_order">Đơn mua</a>
                    </li>
                    <li class="tab-item active">
                        <a href="?mod=admin_factory&controllers=admin&action=sales_orders">Đơn bán</a>
                    </li>
                </ul>
            </nav>
        </div>

        <div class="content">
            <div class="data-actions" data-sticky-top>
                <form class="data-filter" method="get">
                    <input type="hidden" name="mod" value="admin_factory">
                    <input type="hidden" name="controllers" value="admin">
                    <input type="hidden" name="action" value="sales_orders">
                    <div class="filter-field">
                        <label for="filter-from">Từ ngày</label>
                        <input type="date" id="filter-from" name="from" value="<?php echo htmlspecialchars($from, ENT_QUOTES, 'UTF-8'); ?>">
                    </div>
                    <div class="filter-field">
                        <label for="filter-to">Đến ngày</label>
                        <input type="date" id="filter-to" name="to" value="<?php echo htmlspecialchars($to, ENT_QUOTES, 'UTF-8'); ?>">
                    </div>
                    <input type="hidden" name="customer_id" value="<?php echo (int) ($customer_id ?? 0); ?>">
                    <button type="submit" class="btn-filter">LỌC</button>
                    <button type="button" class="btn-export-excel" data-export-excel>Xuất file excel</button>
                </form>
            </div>
            <div class="datatable-wrap" data-paginate-wrap>
                <div class="datatable-toolbar" data-sticky-top>
                    <div class="rows-per-page">
                        <label for="rpp-so">Số hàng hiển thị:</label>
                        <select id="rpp-so" data-rows-per-page>
                            <option value="20" selected>20</option>
                            <option value="50">50</option>
                            <option value="100">100</option>
                            <option value="250">250</option>
                            <option value="500">500</option>
                        </select>
                    </div>
                    <span data-rows-info></span>
                </div>

                <table class="product-table" data-paginate data-sticky-thead>
                    <thead>
                        <tr>
                            <th>
                                <div class="wp-title">
                                    <div class="title">Tên khách hàng</div>
                                    <div class="btn-filter" data-filter-target="customer_id"
                                         data-search-type="customers" data-filter-title="Lọc tên khách hàng">
                                        <i class="fa-solid fa-filter"></i>
                                    </div>
                                </div>
                            </th>
                            <th>Giá trị hàng hóa</th>
                            <th>Khối lượng</th>
                            <th>Ngày tạo</th>
                            <th>Hóa đơn</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php
                        $tot_value = 0.0;
                        $tot_weight = 0.0;
                        if (empty($rows)): ?>
                            <tr class="empty-row">
                                <td colspan="5">Chưa có dữ liệu.</td>
                            </tr>
                            <?php else: foreach ($rows as $r):
                                $tot_value += (float) $r['value'];
                                $tot_weight += (float) ($r['weight_kg'] ?? 0);
                                // Tên KH: ưu tiên customers.name; nếu customer_id NULL thì lấy description.
                                $name = ($r['customer_id'] !== null && $r['customer_name'])
                                        ? $r['customer_name']
                                        : (string) ($r['description'] ?? '');
                                // Màu thứ cấp (secondary_color) để nhận diện khách hàng;
                                // chuẩn hóa về #RRGGBB, rỗng/sai → đen.
                                $sec_color = strtolower(trim((string) ($r['customer_color'] ?? '')));
                                if (!preg_match('/^#[0-9a-f]{6}$/', $sec_color)) { $sec_color = '#000000'; }
                                $value = number_format((float) $r['value'], 0, '.', ',');
                                $weight = (float) ($r['weight_kg'] ?? 0);
                                $weight_val = $weight > 0 ? rtrim(rtrim(number_format($weight, 2, '.', ''), '0'), '.') : '';
                                $ts    = strtotime($r['created_at'] ?? '');
                                $date  = $ts ? date('d/m/Y', $ts) : '';
                                // Hóa đơn tra theo (inv_type, id) vì id 2 nguồn có thể trùng nhau.
                                $inv_type = ($r['inv_type'] ?? '') === 'sales_export_invoice'
                                        ? 'sales_export_invoice' : 'sales_invoice';
                                $inv_list = $inv_type === 'sales_export_invoice'
                                        ? ($export_invoices_map[(int) $r['id']] ?? [])
                                        : ($invoices_map[(int) $r['id']] ?? []);
                            ?>
                                <tr>
                                    <td>
                                        <span class="cust-color-dot" title="Màu nhận diện khách hàng"
                                              style="background:<?php echo htmlspecialchars($sec_color, ENT_QUOTES, 'UTF-8'); ?>"></span>
                                        <?php echo htmlspecialchars($name, ENT_QUOTES, 'UTF-8'); ?>
                                    </td>
                                    <td class="num td-value-detail" data-value-detail="sales"
                                        data-customer-id="<?php echo (int) ($r['customer_id'] ?? 0); ?>"
                                        data-created-at="<?php echo htmlspecialchars((string) ($r['created_at'] ?? ''), ENT_QUOTES, 'UTF-8'); ?>"
                                        data-value="<?php echo (float) $r['value']; ?>">
                                        <span class="vd-eye" title="Xem dữ liệu nguồn"><i class="fa-solid fa-eye"></i></span>
                                        <span class="vd-amount" style="color:<?php echo htmlspecialchars($sec_color, ENT_QUOTES, 'UTF-8'); ?>"><?php echo htmlspecialchars($value, ENT_QUOTES, 'UTF-8'); ?></span>
                                    </td>
                                    <td class="num">
                                        <input type="text" class="so-weight-input" inputmode="decimal"
                                               value="<?php echo htmlspecialchars($weight_val, ENT_QUOTES, 'UTF-8'); ?>"
                                               data-inv-type="<?php echo htmlspecialchars($inv_type, ENT_QUOTES, 'UTF-8'); ?>"
                                               data-order-id="<?php echo (int) $r['id']; ?>"
                                               data-prev="<?php echo htmlspecialchars($weight_val, ENT_QUOTES, 'UTF-8'); ?>"
                                               placeholder="—" title="Sửa khối lượng (lưu khi rời ô)"> kg
                                    </td>
                                    <td class="center"><?php echo htmlspecialchars($date, ENT_QUOTES, 'UTF-8'); ?></td>
                                    <?php echo render_invoice_cell((int) $r['id'], $inv_type, $inv_list); ?>
                                </tr>
                        <?php endforeach;
                        endif; ?>
                    </tbody>
                </table>

                <div class="datatable-totals">
                    <div class="total-item">
                        <span class="label">Tổng giá trị hàng hóa:</span>
                        <span class="value"><?php echo number_format($tot_value, 0, '.', ','); ?></span>
                    </div>
                    <div class="total-item">
                        <span class="label">Tổng khối lượng:</span>
                        <span class="value" id="so-total-weight"><?php echo number_format($tot_weight, 2, '.', ','); ?> kg</span>
                    </div>
                </div>

                <div class="datatable-pagination" data-pagination></div>
            </div>
        </div>
    </div>

    <script src="<?php echo asset_ver('public/js/shared/invoice_dropzone.js'); ?>"></script>
    <script src="<?php echo asset_ver('public/js/admin_factory/row_invoice_cell.js'); ?>"></script>
    <script src="<?php echo asset_ver('public/js/admin_factory/datatable_pagination.js'); ?>"></script>
    <script src="<?php echo asset_ver('public/js/admin_factory/data_export_excel.js'); ?>"></script>
    <script src="<?php echo asset_ver('public/js/admin_factory/data_column_filter.js'); ?>"></script>
    <script src="<?php echo asset_ver('public/js/admin_factory/value_detail_modal.js'); ?>"></script>
    <script src="<?php echo asset_ver('public/js/shared/app_shell.js'); ?>"></script>
    <script src="<?php echo asset_ver('public/js/admin_factory/sticky_table.js'); ?>"></script>
    <script>
        // Sửa khối lượng từng đơn -> lưu DB khi rời ô (blur). Cập nhật lại tổng.
        (function () {
            var AJAX_BASE = '?mod=admin_factory&controllers=admin&action=';
            function parseNum(s) { var n = parseFloat(String(s == null ? '' : s).replace(/,/g, '').trim()); return isNaN(n) ? 0 : n; }
            function fmt2(n) { return n.toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 }); }

            function recalcTotal() {
                var total = 0;
                document.querySelectorAll('.so-weight-input').forEach(function (inp) { total += parseNum(inp.value); });
                var el = document.getElementById('so-total-weight');
                if (el) el.textContent = fmt2(total) + ' kg';
            }

            function flash(inp, cls) {
                inp.classList.remove('saved', 'err'); void inp.offsetWidth; inp.classList.add(cls);
                setTimeout(function () { inp.classList.remove(cls); }, 700);
            }

            function save(inp) {
                var prev = inp.getAttribute('data-prev') || '';
                if (inp.value.trim() === prev.trim()) return;
                var fd = new FormData();
                fd.append('inv_type', inp.getAttribute('data-inv-type'));
                fd.append('order_id', inp.getAttribute('data-order-id'));
                fd.append('weight', parseNum(inp.value));
                fetch(AJAX_BASE + 'update_sales_order_weight', { method: 'POST', body: fd, credentials: 'same-origin' })
                    .then(function (r) { return r.json(); })
                    .then(function (res) {
                        if (res && res.ok) { inp.setAttribute('data-prev', inp.value); flash(inp, 'saved'); }
                        else flash(inp, 'err');
                    })
                    .catch(function () { flash(inp, 'err'); });
            }

            document.querySelectorAll('.so-weight-input').forEach(function (inp) {
                inp.addEventListener('input', recalcTotal);
                inp.addEventListener('change', function () { save(inp); });
                inp.addEventListener('keydown', function (e) { if (e.key === 'Enter') { e.preventDefault(); inp.blur(); } });
            });
        })();
    </script>
</body>

</html>
