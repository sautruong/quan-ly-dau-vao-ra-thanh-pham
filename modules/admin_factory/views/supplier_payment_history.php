<?php
defined('APPPATH') OR exit('Không được quyền truy cập phần này');
/** @var array $rows */
$rows = isset($rows) && is_array($rows) ? $rows : [];
$from = isset($from) ? (string) $from : '';
$to   = isset($to)   ? (string) $to   : '';
$attachments_map = isset($attachments_map) && is_array($attachments_map) ? $attachments_map : [];
?>
<!DOCTYPE html>
<html lang="vi">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>DỮ LIỆU HOẠT ĐỘNG NHÀ MÁY</title>
    <link rel="icon" href="public/images/logo/logo_vat_png.png" type="image/png">
    <link rel="stylesheet" href="public/css/reset.css">
    <link rel="stylesheet" href="public/css/all.css">
    <link rel="stylesheet" href="public/css/global.css">
    <link rel="stylesheet" href="<?php echo asset_ver('public/css/inventory_management/dashboard.css'); ?>">
    <link rel="stylesheet" href="public/css/inventory_management/investment_products.css">
    <link rel="stylesheet" href="public/css/accounting/journal_entry.css">
    <link rel="stylesheet" href="public/css/admin_factory/data_dashboard.css">
    <link rel="stylesheet" href="public/css/shared/invoice_upload.css">
    <link rel="stylesheet" href="public/css/admin_factory/value_detail.css">
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
                    <li class="tab-item active">
                        <a href="?mod=admin_factory&controllers=admin&action=supplier_payment_history">Lịch sử thanh toán NCC</a>
                    </li>
                </ul>
            </nav>
        </div>

        <div class="content">
            <div class="data-actions" data-sticky-top>
                <form class="data-filter" method="get">
                    <input type="hidden" name="mod" value="admin_factory">
                    <input type="hidden" name="controllers" value="admin">
                    <input type="hidden" name="action" value="supplier_payment_history">
                    <div class="filter-field">
                        <label for="filter-from">Từ ngày</label>
                        <input type="date" id="filter-from" name="from" value="<?php echo htmlspecialchars($from, ENT_QUOTES, 'UTF-8'); ?>">
                    </div>
                    <div class="filter-field">
                        <label for="filter-to">Đến ngày</label>
                        <input type="date" id="filter-to" name="to" value="<?php echo htmlspecialchars($to, ENT_QUOTES, 'UTF-8'); ?>">
                    </div>
                    <input type="hidden" name="supplier_id" value="<?php echo (int) ($supplier_id ?? 0); ?>">
                    <button type="submit" class="btn-filter">LỌC</button>
                    <button type="button" class="btn-export-excel" data-export-excel>Xuất file excel</button>
                </form>
            </div>
            <div class="datatable-wrap" data-paginate-wrap>
                <div class="datatable-toolbar" data-sticky-top>
                    <div class="rows-per-page">
                        <label for="rpp-sp">Số hàng hiển thị:</label>
                        <select id="rpp-sp" data-rows-per-page>
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
                                    <div class="title">Tên nhà cung cấp</div>
                                    <div class="btn-filter" data-filter-target="supplier_id"
                                         data-search-type="suppliers" data-filter-title="Lọc tên nhà cung cấp">
                                        <i class="fa-solid fa-filter"></i>
                                    </div>
                                </div>
                            </th>
                            <th>Giá trị thanh toán</th>
                            <th>Ngày thanh toán</th>
                            <th>Hình ảnh thanh toán</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php
                        $tot_value = 0.0;
                        if (empty($rows)): ?>
                            <tr class="empty-row">
                                <td colspan="4">Chưa có dữ liệu.</td>
                            </tr>
                            <?php else: foreach ($rows as $r):
                                $tot_value += (float) $r['amount'];
                                $name  = $r['supplier_name'] ?: ('#' . (int) $r['supplier_id']);
                                $value = number_format((float) $r['amount'], 0, '.', ',');
                                $ts    = strtotime($r['created_at'] ?? '');
                                $date  = $ts ? date('d/m/Y H:i', $ts) : '';
                            ?>
                                <tr>
                                    <td><?php echo htmlspecialchars($name, ENT_QUOTES, 'UTF-8'); ?></td>
                                    <td class="num"><?php echo htmlspecialchars($value, ENT_QUOTES, 'UTF-8'); ?></td>
                                    <td class="center"><?php echo htmlspecialchars($date, ENT_QUOTES, 'UTF-8'); ?></td>
                                    <?php echo render_invoice_cell((int) $r['id'], 'supplier_payment', $attachments_map[(int) $r['id']] ?? [], '', 'supplier_payment'); ?>
                                </tr>
                        <?php endforeach;
                        endif; ?>
                    </tbody>
                </table>

                <div class="datatable-totals">
                    <div class="total-item">
                        <span class="label">Tổng giá trị thanh toán:</span>
                        <span class="value"><?php echo number_format($tot_value, 0, '.', ','); ?></span>
                    </div>
                </div>

                <div class="datatable-pagination" data-pagination></div>
            </div>
        </div>
    </div>

    <script src="public/js/shared/invoice_dropzone.js"></script>
    <script src="public/js/admin_factory/row_invoice_cell.js"></script>
    <script src="public/js/admin_factory/datatable_pagination.js"></script>
    <script src="public/js/admin_factory/data_export_excel.js"></script>
    <script src="public/js/admin_factory/data_column_filter.js"></script>
    <script src="<?php echo asset_ver('public/js/shared/app_shell.js'); ?>"></script>
    <script src="public/js/admin_factory/sticky_table.js"></script>
</body>

</html>
