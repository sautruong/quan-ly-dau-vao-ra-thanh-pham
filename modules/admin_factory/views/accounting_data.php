<?php
defined('APPPATH') OR exit('Không được quyền truy cập phần này');
/** @var array $rows */
$rows = isset($rows) && is_array($rows) ? $rows : [];
$from = isset($from) ? (string) $from : '';
$to   = isset($to)   ? (string) $to   : '';
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
                    <li class="tab-item active">
                        <a href="?mod=admin_factory&controllers=admin&action=accounting_data">Dữ liệu kế toán</a>
                    </li>
                    <li class="tab-item">
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
                    <input type="hidden" name="action" value="accounting_data">
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
                        <label for="rpp-acc">Số hàng hiển thị:</label>
                        <select id="rpp-acc" data-rows-per-page>
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
                            <th>Nghiệp vụ</th>
                            <th>Nợ</th>
                            <th>Có</th>
                            <th>Giá trị</th>
                            <th>Mô tả</th>
                            <th>Ngày tạo</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($rows)): ?>
                            <tr class="empty-row"><td colspan="6">Chưa có dữ liệu.</td></tr>
                        <?php else: foreach ($rows as $r):
                            $tname  = (string) ($r['transaction_name'] ?? '');
                            $debit  = (string) ($r['debit_code']  ?? '');
                            $credit = (string) ($r['credit_code'] ?? '');
                            $amount = (string) ($r['amount_formula'] ?? '');
                            $desc   = (string) ($r['description'] ?? '');
                            $ts     = strtotime($r['updated_at'] ?? '');
                            $date   = $ts ? date('d/m/Y', $ts) : '';
                        ?>
                            <tr>
                                <td><?php echo htmlspecialchars($tname,  ENT_QUOTES, 'UTF-8'); ?></td>
                                <td class="center"><?php echo htmlspecialchars($debit,  ENT_QUOTES, 'UTF-8'); ?></td>
                                <td class="center"><?php echo htmlspecialchars($credit, ENT_QUOTES, 'UTF-8'); ?></td>
                                <td class="num"><?php echo htmlspecialchars($amount, ENT_QUOTES, 'UTF-8'); ?></td>
                                <td><?php echo htmlspecialchars($desc,   ENT_QUOTES, 'UTF-8'); ?></td>
                                <td class="center"><?php echo htmlspecialchars($date,  ENT_QUOTES, 'UTF-8'); ?></td>
                            </tr>
                        <?php endforeach; endif; ?>
                    </tbody>
                </table>

                <div class="datatable-pagination" data-pagination></div>
            </div>
        </div>
    </div>

    <script src="<?php echo asset_ver('public/js/admin_factory/datatable_pagination.js'); ?>"></script>
    <script src="<?php echo asset_ver('public/js/admin_factory/data_export_excel.js'); ?>"></script>
    <script src="<?php echo asset_ver('public/js/shared/app_shell.js'); ?>"></script>
    <script src="<?php echo asset_ver('public/js/admin_factory/sticky_table.js'); ?>"></script>
</body>

</html>
