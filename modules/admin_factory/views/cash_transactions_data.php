<?php
defined('APPPATH') OR exit('Không được quyền truy cập phần này');
/** @var array $rows */
$rows = isset($rows) && is_array($rows) ? $rows : [];
$from = isset($from) ? (string) $from : '';
$to   = isset($to)   ? (string) $to   : '';
$f_type    = isset($type)    ? (string) $type    : '';   // lọc Phân loại (Thu/Chi)
$f_account = isset($account) ? (string) $account : '';   // lọc Tài khoản (TM/TG)

// URL giữ nguyên các tham số hiện tại, ghi đè cột muốn lọc (giá trị '' = bỏ lọc).
$ct_base = ['mod' => 'admin_factory', 'controllers' => 'admin', 'action' => 'cash_transactions_data',
            'from' => $from, 'to' => $to, 'type' => $f_type, 'account' => $f_account];
$ct_filter_url = function (array $override) use ($ct_base) {
    $p = array_filter(array_merge($ct_base, $override), function ($v) { return $v !== '' && $v !== null; });
    return '?' . http_build_query($p);
};

// Tổng số tiền của toàn bộ dòng đang lọc (mọi trang).
$ct_total = 0.0;
foreach ($rows as $r) { $ct_total += (float) ($r['amount'] ?? 0); }
$ct_total_fmt = number_format($ct_total, 0, '.', ',');
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
        /* Funnel lọc theo cột */
        .th-filter { display: inline-flex; align-items: center; gap: 6px; justify-content: center; }
        .col-filter-btn {
            border: none; background: transparent; cursor: pointer; padding: 2px 4px;
            color: #8a929c; font-size: 12px; border-radius: 4px; line-height: 1;
        }
        .col-filter-btn:hover { background: #e3e8ef; color: #444; }
        .col-filter-btn.is-active { color: #096926; }
        .col-filter-menu {
            position: absolute; z-index: 1000; display: none; min-width: 150px;
            background: #fff; border: 1px solid #c2c8d0; border-radius: 6px;
            box-shadow: 0 6px 20px rgba(0,0,0,0.15); padding: 4px;
        }
        .col-filter-menu.open { display: block; }
        .col-filter-menu a {
            display: block; padding: 7px 10px; font-size: 13px; color: #333;
            text-decoration: none; border-radius: 4px;
        }
        .col-filter-menu a:hover { background: #eef2f7; }
        .col-filter-menu a.active { background: #096926; color: #fff; font-weight: 600; }
        /* Nổi bật phiếu Thu */
        .product-table tbody tr.row-thu td { font-weight: 600; }
        /* Dòng tổng */
        .product-table tfoot .total-row td {
            background: #f0f3f7; font-weight: 700; color: #003c7c;
        }
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
                    <li class="tab-item active">
                        <a href="?mod=admin_factory&controllers=admin&action=cash_transactions_data">Dữ liệu thu chi</a>
                    </li>
                    <li class="tab-item">
                        <a href="?mod=admin_factory&controllers=admin&action=purchase_order">Đơn mua</a>
                    </li>
                    <li class="tab-item">
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
                    <input type="hidden" name="action" value="cash_transactions_data">
                    <input type="hidden" name="type" value="<?php echo htmlspecialchars($f_type, ENT_QUOTES, 'UTF-8'); ?>">
                    <input type="hidden" name="account" value="<?php echo htmlspecialchars($f_account, ENT_QUOTES, 'UTF-8'); ?>">
                    <div class="filter-field">
                        <label for="filter-from">Từ ngày</label>
                        <input type="date" id="filter-from" name="from" value="<?php echo htmlspecialchars($from, ENT_QUOTES, 'UTF-8'); ?>">
                    </div>
                    <div class="filter-field">
                        <label for="filter-to">Đến ngày</label>
                        <input type="date" id="filter-to" name="to" value="<?php echo htmlspecialchars($to, ENT_QUOTES, 'UTF-8'); ?>">
                    </div>
                    <button type="submit" class="btn-filter">LỌC</button>
                    <button type="button" class="btn-export-excel" data-export-excel>Xuất file excel</button>
                </form>
            </div>
            <div class="datatable-wrap" data-paginate-wrap>
                <div class="datatable-toolbar" data-sticky-top>
                    <div class="rows-per-page">
                        <label for="rpp-ct">Số hàng hiển thị:</label>
                        <select id="rpp-ct" data-rows-per-page>
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
                            <th>Diễn giải</th>
                            <th>
                                <span class="th-filter">
                                    <span class="th-label">Phân loại</span>
                                    <button type="button" class="col-filter-btn<?php echo $f_type !== '' ? ' is-active' : ''; ?>"
                                            data-col-filter="type" aria-label="Lọc phân loại" title="Lọc phân loại">
                                        <i class="fa-solid fa-filter"></i>
                                    </button>
                                </span>
                            </th>
                            <th>
                                <span class="th-filter">
                                    <span class="th-label">Tài khoản</span>
                                    <button type="button" class="col-filter-btn<?php echo $f_account !== '' ? ' is-active' : ''; ?>"
                                            data-col-filter="account" aria-label="Lọc tài khoản" title="Lọc tài khoản">
                                        <i class="fa-solid fa-filter"></i>
                                    </button>
                                </span>
                            </th>
                            <th>Số tài khoản</th>
                            <th>Số tiền</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($rows)): ?>
                            <tr class="empty-row"><td colspan="5">Chưa có dữ liệu.</td></tr>
                        <?php else: foreach ($rows as $r):
                            $desc   = (string) ($r['description'] ?? '');
                            $type   = (string) ($r['transaction_type'] ?? '');
                            $acc    = (string) ($r['account_name'] ?? '');
                            $accNo  = (string) ($r['account_number'] ?? '');
                            $amount = number_format((float) ($r['amount'] ?? 0), 0, '.', ',');
                        ?>
                            <tr<?php echo $type === 'Thu' ? ' class="row-thu"' : ''; ?>>
                                <td><?php echo htmlspecialchars($desc,   ENT_QUOTES, 'UTF-8'); ?></td>
                                <td class="center"><?php echo htmlspecialchars($type,  ENT_QUOTES, 'UTF-8'); ?></td>
                                <td class="center"><?php echo htmlspecialchars($acc,   ENT_QUOTES, 'UTF-8'); ?></td>
                                <td class="center"><?php echo htmlspecialchars($accNo, ENT_QUOTES, 'UTF-8'); ?></td>
                                <td class="num"><?php echo htmlspecialchars($amount,   ENT_QUOTES, 'UTF-8'); ?></td>
                            </tr>
                        <?php endforeach; endif; ?>
                    </tbody>
                    <tfoot>
                        <tr class="total-row">
                            <td colspan="4" class="num">Tổng số tiền</td>
                            <td class="num"><?php echo htmlspecialchars($ct_total_fmt, ENT_QUOTES, 'UTF-8'); ?></td>
                        </tr>
                    </tfoot>
                </table>

                <!-- Menu lọc cột (đặt NGOÀI bảng để không lẫn vào nội dung xuất Excel) -->
                <div class="col-filter-menu" data-col-filter-menu="type">
                    <a href="<?php echo htmlspecialchars($ct_filter_url(['type' => '']),    ENT_QUOTES, 'UTF-8'); ?>" class="<?php echo $f_type === ''    ? 'active' : ''; ?>">Tất cả</a>
                    <a href="<?php echo htmlspecialchars($ct_filter_url(['type' => 'Thu']), ENT_QUOTES, 'UTF-8'); ?>" class="<?php echo $f_type === 'Thu' ? 'active' : ''; ?>">Thu</a>
                    <a href="<?php echo htmlspecialchars($ct_filter_url(['type' => 'Chi']), ENT_QUOTES, 'UTF-8'); ?>" class="<?php echo $f_type === 'Chi' ? 'active' : ''; ?>">Chi</a>
                </div>
                <div class="col-filter-menu" data-col-filter-menu="account">
                    <a href="<?php echo htmlspecialchars($ct_filter_url(['account' => '']),   ENT_QUOTES, 'UTF-8'); ?>" class="<?php echo $f_account === ''   ? 'active' : ''; ?>">Tất cả</a>
                    <a href="<?php echo htmlspecialchars($ct_filter_url(['account' => 'TM']), ENT_QUOTES, 'UTF-8'); ?>" class="<?php echo $f_account === 'TM' ? 'active' : ''; ?>">TM — Tiền mặt</a>
                    <a href="<?php echo htmlspecialchars($ct_filter_url(['account' => 'TG']), ENT_QUOTES, 'UTF-8'); ?>" class="<?php echo $f_account === 'TG' ? 'active' : ''; ?>">TG — Tiền gửi</a>
                </div>

                <div class="datatable-pagination" data-pagination></div>
            </div>
        </div>
    </div>

    <script src="<?php echo asset_ver('public/js/admin_factory/datatable_pagination.js'); ?>"></script>
    <script src="<?php echo asset_ver('public/js/admin_factory/data_export_excel.js'); ?>"></script>
    <script src="<?php echo asset_ver('public/js/shared/app_shell.js'); ?>"></script>
    <script>
        // Funnel lọc cột: mở/đóng menu, định vị dưới icon. Mỗi lựa chọn là 1 link (lọc server-side).
        (function () {
            var openMenu = null;
            function closeMenu() {
                if (openMenu) { openMenu.classList.remove('open'); openMenu = null; }
            }
            document.addEventListener('click', function (e) {
                var btn = e.target.closest('.col-filter-btn');
                if (btn) {
                    e.preventDefault();
                    e.stopPropagation();
                    var key  = btn.getAttribute('data-col-filter');
                    var menu = document.querySelector('.col-filter-menu[data-col-filter-menu="' + key + '"]');
                    if (!menu) return;
                    var wasOpen = (menu === openMenu);
                    closeMenu();
                    if (wasOpen) return;
                    var r = btn.getBoundingClientRect();
                    menu.style.left = (window.scrollX + r.left) + 'px';
                    menu.style.top  = (window.scrollY + r.bottom + 4) + 'px';
                    menu.classList.add('open');
                    openMenu = menu;
                    return;
                }
                if (!e.target.closest('.col-filter-menu')) closeMenu();
            });
            document.addEventListener('keydown', function (e) { if (e.key === 'Escape') closeMenu(); });
            window.addEventListener('resize', closeMenu);
        })();
    </script>
    <script src="<?php echo asset_ver('public/js/admin_factory/sticky_table.js'); ?>"></script>
</body>

</html>
