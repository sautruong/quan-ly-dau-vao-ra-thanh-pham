<?php
defined('APPPATH') OR exit('Không được quyền truy cập phần này');
/**
 * View "Đơn hàng" — bản dành cho khách của /admin_factory/sales_orders.
 * KHÔNG viết chuỗi "base" kèm dấu nhỏ hơn ở bất kỳ đâu trong file này (kể cả trong chú thích):
 * nav_inject_base_tag() thấy chuỗi đó là bỏ chèn thẻ gốc -> mọi đường dẫn CSS/JS tương đối
 * hỏng hết dưới URL gọn.
 */
$rows       = isset($rows) && is_array($rows) ? $rows : [];
$inv_map    = isset($inv_map) && is_array($inv_map) ? $inv_map : [];
$from       = isset($from) ? (string) $from : '';
$to         = isset($to) ? (string) $to : '';
$inv_filter = isset($inv_filter) ? (string) $inv_filter : '';
$per_page   = isset($per_page) ? (int) $per_page : 25;
$scope      = isset($scope) && is_array($scope) ? $scope : ['mode' => 'none', 'customer_id' => 0, 'user_id' => 0];
$customers  = isset($customers) && is_array($customers) ? $customers : [];
$cur_cid    = isset($customer_id) ? (int) $customer_id : 0;
$is_admin   = $scope['mode'] === 'admin';
$me_id      = (int) $scope['user_id'];
$BASE       = '?mod=customer_orders&controllers=customer_orders&action=';
?>
<!DOCTYPE html>
<html lang="vi">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Đơn hàng</title>
    <link rel="icon" href="public/images/logo/logo_vat_png.png" type="image/png">
    <link rel="stylesheet" href="<?php echo asset_ver('public/css/reset.css'); ?>">
    <link rel="stylesheet" href="<?php echo asset_ver('public/css/all.css'); ?>">
    <link rel="stylesheet" href="<?php echo asset_ver('public/css/global.css'); ?>">
    <link rel="stylesheet" href="<?php echo asset_ver('public/css/admin_factory/data_dashboard.css'); ?>">
    <link rel="stylesheet" href="<?php echo asset_ver('public/css/shared/invoice_upload.css'); ?>">
    <link rel="stylesheet" href="<?php echo asset_ver('public/css/shared/app_shell.css'); ?>">
    <link rel="stylesheet" href="<?php echo asset_ver('public/css/customer_orders/orders.css'); ?>">
</head>

<body>
    <div id="wrapper" class="has-sider">
        <?php get_sidebar('app'); ?>
        <?php get_header('app'); ?>

        <div class="content">
            <?php if ($scope['mode'] === 'none'): ?>
                <div class="co-notice">
                    <i class="fa-solid fa-circle-info"></i>
                    Tài khoản của bạn chưa được định danh khách hàng nên chưa có đơn hàng nào để xem.
                    Vui lòng liên hệ quản trị viên để được gán khách hàng.
                </div>
            <?php else: ?>

            <div class="data-actions" data-sticky-top>
                <form class="data-filter" method="get">
                    <input type="hidden" name="mod" value="customer_orders">
                    <input type="hidden" name="controllers" value="customer_orders">
                    <input type="hidden" name="action" value="orders">
                    <div class="filter-field">
                        <label for="co-from">Từ ngày</label>
                        <input type="date" id="co-from" name="from" value="<?php echo co_esc($from); ?>">
                    </div>
                    <div class="filter-field">
                        <label for="co-to">Đến ngày</label>
                        <input type="date" id="co-to" name="to" value="<?php echo co_esc($to); ?>">
                    </div>
                    <div class="filter-field">
                        <label for="co-inv">Hóa đơn</label>
                        <select id="co-inv" name="inv">
                            <option value="">Tất cả</option>
                            <option value="has"  <?php echo $inv_filter === 'has'  ? 'selected' : ''; ?>>Đã tải hóa đơn</option>
                            <option value="none" <?php echo $inv_filter === 'none' ? 'selected' : ''; ?>>Chưa tải hóa đơn</option>
                        </select>
                    </div>
                    <?php if ($is_admin): ?>
                    <div class="filter-field">
                        <label for="co-cus">Khách hàng</label>
                        <select id="co-cus" name="customer_id">
                            <option value="0">Tất cả khách hàng</option>
                            <?php foreach ($customers as $c): ?>
                                <option value="<?php echo (int) $c['id']; ?>" <?php echo (int) $c['id'] === $cur_cid ? 'selected' : ''; ?>>
                                    <?php echo co_esc($c['name']); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <?php endif; ?>
                    <div class="filter-field">
                        <label for="co-per">Số dòng</label>
                        <select id="co-per" name="per">
                            <?php foreach ([10, 25, 50, 100] as $n): ?>
                                <option value="<?php echo $n; ?>" <?php echo $n === $per_page ? 'selected' : ''; ?>><?php echo $n; ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <button type="submit" class="btn-filter">LỌC</button>
                    <button type="button" class="btn-check-db"
                            data-tables="sales_orders,sales_warehouse_export_invoices,warehouse_receipt_invoices,stock_exports,customers">
                        <i class="fa-solid fa-database"></i> Check database
                    </button>
                </form>
            </div>

            <div class="datatable-wrap">
                <table class="co-table" id="co-table">
                    <thead>
                        <tr>
                            <th>Ngày</th>
                            <th>Giá trị hàng hóa</th>
                            <th>Khối lượng</th>
                            <th>Hóa đơn</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php
                        $tot_value = 0.0;
                        $tot_weight = 0.0;
                        if (empty($rows)): ?>
                            <tr class="co-empty-row"><td colspan="4">Chưa có đơn hàng nào.</td></tr>
                        <?php else: foreach ($rows as $r):
                            $tot_value  += (float) $r['value'];
                            $tot_weight += (float) ($r['weight_kg'] ?? 0);
                            $inv_type = ($r['inv_type'] ?? '') === 'sales_export_invoice'
                                      ? 'sales_export_invoice' : 'sales_invoice';
                            $files = $inv_map[$inv_type][(int) $r['id']] ?? [];
                            $ts    = strtotime((string) ($r['created_at'] ?? ''));
                            $date  = $ts ? date('d/m/Y', $ts) : '';
                        ?>
                            <tr class="co-row"
                                data-inv-type="<?php echo co_esc($inv_type); ?>"
                                data-id="<?php echo (int) $r['id']; ?>"
                                data-created-at="<?php echo co_esc((string) ($r['created_at'] ?? '')); ?>"
                                data-date="<?php echo co_esc($date); ?>"
                                data-customer="<?php echo co_esc((string) ($r['customer_name'] ?? '')); ?>">
                                <td class="co-date"><?php echo co_esc($date); ?></td>
                                <td class="num"><?php echo co_money($r['value']); ?></td>
                                <td class="num"><?php echo co_weight($r['weight_kg'] ?? 0); ?></td>
                                <td class="co-inv-cell"
                                    data-inv-type="<?php echo co_esc($inv_type); ?>"
                                    data-id="<?php echo (int) $r['id']; ?>">
                                    <?php
                                    /* Tích xanh cạnh TRÁI trong ô khi đơn ĐÃ có hóa đơn. Cả PHP (lần
                                       render đầu) và JS (sau khi tải/xóa) đều phải cập nhật dấu này. */
                                    ?>
                                    <span class="co-inv-check<?php echo $files ? ' is-on' : ''; ?>" title="Đã có hóa đơn">
                                        <i class="fa-solid fa-circle-check"></i>
                                    </span>
                                    <span class="co-inv-thumbs">
                                        <?php foreach ($files as $f):
                                            $laCuaToi = $f['upload_source'] === 'customer' && (int) $f['uploaded_by'] === $me_id;
                                            $xoaDuoc  = $is_admin || $laCuaToi;
                                        ?>
                                            <span class="co-thumb" data-inv-id="<?php echo (int) $f['id']; ?>"
                                                  data-src="<?php echo co_esc($f['file_url']); ?>"
                                                  data-can-delete="<?php echo $xoaDuoc ? '1' : '0'; ?>"
                                                  title="<?php echo $f['upload_source'] === 'customer' ? 'Bạn tải lên' : 'Nhà máy tải lên'; ?>">
                                                <img src="<?php echo co_esc($f['file_url']); ?>" alt="Hóa đơn" loading="lazy">
                                            </span>
                                        <?php endforeach; ?>
                                    </span>
                                    <button type="button" class="co-inv-add" title="Thêm hóa đơn (chọn tệp, kéo thả hoặc Ctrl+V)">
                                        <i class="fa-solid fa-plus"></i>
                                    </button>
                                    <input type="file" class="co-inv-file" accept="image/*,application/pdf" multiple hidden>
                                </td>
                            </tr>
                        <?php endforeach; endif; ?>
                    </tbody>
                </table>

                <div class="co-foot">
                    <div class="co-totals">
                        <span>Tổng giá trị: <b><?php echo co_money($tot_value); ?></b></span>
                        <span>Tổng khối lượng: <b><?php echo co_weight($tot_weight); ?></b></span>
                        <span>Số đơn: <b id="co-count"><?php echo count($rows); ?></b></span>
                    </div>
                    <div class="co-pagination" id="co-pagination"></div>
                </div>
            </div>

            <?php endif; ?>
        </div>
    </div>

    <!-- Modal chi tiết đơn (bấm vào dòng) -->
    <div class="co-modal" id="co-detail-modal" aria-hidden="true">
        <div class="co-modal-overlay" data-co-close></div>
        <div class="co-modal-box">
            <div class="co-modal-head">
                <h3 id="co-detail-title">Chi tiết đơn hàng</h3>
                <button type="button" class="co-modal-close" data-co-close aria-label="Đóng">&times;</button>
            </div>
            <div class="co-modal-body">
                <table class="co-detail-table">
                    <thead>
                        <tr>
                            <th>Tên hàng hóa</th><th>Đơn vị</th><th>Số lượng</th><th>Đơn giá</th><th>Thành tiền</th>
                        </tr>
                    </thead>
                    <tbody id="co-detail-body"><tr><td colspan="5">Đang tải…</td></tr></tbody>
                    <tfoot id="co-detail-foot"></tfoot>
                </table>
            </div>
        </div>
    </div>

    <!-- Lightbox xem hóa đơn -->
    <div class="co-viewer" id="co-viewer" aria-hidden="true">
        <div class="co-viewer-bar">
            <span class="co-viewer-pos" id="co-viewer-pos"></span>
            <span class="co-viewer-tools">
                <button type="button" data-cv="prev" title="Ảnh trước"><i class="fa-solid fa-chevron-left"></i></button>
                <button type="button" data-cv="next" title="Ảnh sau"><i class="fa-solid fa-chevron-right"></i></button>
                <button type="button" data-cv="rotl" title="Xoay trái"><i class="fa-solid fa-rotate-left"></i></button>
                <button type="button" data-cv="rotr" title="Xoay phải"><i class="fa-solid fa-rotate-right"></i></button>
                <button type="button" data-cv="zoomout" title="Thu nhỏ"><i class="fa-solid fa-magnifying-glass-minus"></i></button>
                <button type="button" data-cv="zoomin" title="Phóng to"><i class="fa-solid fa-magnifying-glass-plus"></i></button>
                <button type="button" data-cv="download" title="Tải xuống"><i class="fa-solid fa-download"></i></button>
                <button type="button" data-cv="share" title="Gửi qua chat"><i class="fa-regular fa-comments"></i></button>
                <button type="button" data-cv="add" title="Thêm hóa đơn"><i class="fa-solid fa-plus"></i></button>
                <button type="button" data-cv="del" title="Xóa hóa đơn"><i class="fa-solid fa-trash"></i></button>
                <button type="button" data-cv="close" title="Đóng"><i class="fa-solid fa-xmark"></i></button>
            </span>
        </div>
        <div class="co-viewer-stage" id="co-viewer-stage">
            <img id="co-viewer-img" alt="Hóa đơn" draggable="false">
        </div>
    </div>

    <!-- Modal chọn người nhận để gửi hóa đơn qua chat -->
    <div class="co-modal" id="co-share-modal" aria-hidden="true">
        <div class="co-modal-overlay" data-co-close></div>
        <div class="co-modal-box co-share-box">
            <div class="co-modal-head">
                <h3><i class="fa-regular fa-comments"></i> Gửi hóa đơn qua chat</h3>
                <button type="button" class="co-modal-close" data-co-close aria-label="Đóng">&times;</button>
            </div>
            <div class="co-modal-body">
                <input type="text" class="co-share-filter" id="co-share-filter" autocomplete="off" placeholder="Tìm tên người nhận...">
                <div class="co-share-list" id="co-share-list"><div class="co-share-empty">Đang tải danh bạ…</div></div>
                <input type="text" class="co-share-note" id="co-share-note" autocomplete="off" placeholder="Lời nhắn (tuỳ chọn)">
                <div class="co-share-foot">
                    <span id="co-share-status"></span>
                    <button type="button" class="co-share-send" id="co-share-send">Gửi</button>
                </div>
            </div>
        </div>
    </div>

    <script>
        window.CO = {
            base: <?php echo json_encode($BASE); ?>,
            perPage: <?php echo (int) $per_page; ?>,
            isAdmin: <?php echo $is_admin ? 'true' : 'false'; ?>,
            me: <?php echo $me_id; ?>
        };
    </script>
    <script src="<?php echo asset_ver('public/js/customer_orders/orders.js'); ?>"></script>
    <script src="<?php echo asset_ver('public/js/shared/app_shell.js'); ?>"></script>
    <script src="<?php echo asset_ver('public/js/shared/check_database.js'); ?>"></script>
</body>

</html>
