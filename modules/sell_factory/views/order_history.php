<?php

/** @var array $history */
/** @var array $product_groups */
$history        = isset($history) ? $history : ['rows' => [], 'total_pages' => 0, 'page' => 1, 'per_page' => 20, 'total' => 0];
$product_groups = isset($product_groups) && is_array($product_groups) ? $product_groups : [];
$per_page       = (int) ($history['per_page'] ?? 20);
$page           = (int) ($history['page'] ?? 1);
?>
<!DOCTYPE html>
<html lang="vi">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Lịch sử đặt hàng</title>
    <link rel="icon" href="public/images/logo/logo_vat_png.png" type="image/png">
    <link rel="stylesheet" href="<?php echo asset_ver('public/css/reset.css'); ?>">
    <link rel="stylesheet" href="<?php echo asset_ver('public/css/all.css'); ?>">
    <link rel="stylesheet" href="<?php echo asset_ver('public/css/global.css'); ?>">
    <link rel="stylesheet" href="<?php echo asset_ver('public/css/sell-factory/order-factory.css'); ?>">
    <link rel="stylesheet" href="<?php echo asset_ver('public/css/shared/app_shell.css'); ?>">
    <script src="<?php echo asset_ver('public/js/jquery-4.0.0.js'); ?>"></script>
</head>

<body>
    <div id="wrapper">
        <?php get_sidebar('app'); ?>
        <?php get_header('app'); ?>

        <div class="of-page">
            <div class="of-history-wrap">
                <div class="ofh-head">
                    <h2 class="of-block-title">Lịch sử đặt hàng của tôi</h2>
                    <div class="ofh-head-tools">
                        <label class="ofh-perpage">Số dòng
                            <select id="ofh-per-page">
                                <?php foreach ([10, 20, 50, 100] as $opt): ?>
                                    <option value="<?= $opt ?>" <?= $per_page === $opt ? 'selected' : '' ?>><?= $opt ?></option>
                                <?php endforeach; ?>
                            </select>
                        </label>
                        <span class="ofh-count">Tổng <b><?= (int) ($history['total'] ?? 0) ?></b> đơn</span>
                    </div>
                </div>

                <table class="of-history-table">
                    <thead>
                        <tr>
                            <th class="ofh-tick-col">
                                <label class="app-round-check" title="Chọn tất cả dòng trên trang này">
                                    <input type="checkbox" id="ofh-check-all">
                                    <span class="app-round-check-mark"><i class="fa-solid fa-check"></i></span>
                                </label>
                            </th>
                            <th>Ngày</th>
                            <th>Chi nhánh</th>
                            <th>Diễn giải</th>
                            <th>Ghi chú</th>
                            <th>Trạng thái</th>
                            <th class="text-center">Thao tác</th>
                        </tr>
                    </thead>
                    <tbody id="ofh-body">
                        <?php foreach ($history['rows'] as $h):
                            $picked = !empty($h['picked']);
                            $locked = !empty($h['locked']);
                            $skipped = !empty($h['factory_hidden']) && !$picked; // nhà máy đã bỏ qua
                            $editable = !$picked && !$locked && empty($h['factory_hidden']);
                            $can_redo = $picked || !empty($h['factory_hidden']);   // đã bốc / nhà máy đã xử lý
                            $cust_short = trim((string) ($h['customer_short'] ?? ''));
                            $cust_full  = (string) ($h['customer_name'] ?? '');
                        ?>
                            <tr data-id="<?= (int) $h['id'] ?>">
                                <td class="ofh-tick-col">
                                    <label class="app-round-check">
                                        <input type="checkbox" class="ofh-tick" value="<?= (int) $h['id'] ?>">
                                        <span class="app-round-check-mark"><i class="fa-solid fa-check"></i></span>
                                    </label>
                                </td>
                                <td class="ofh-date"><?= date('H:i d/m/Y', strtotime($h['created_at'])) ?></td>
                                <td title="<?= htmlspecialchars($cust_full, ENT_QUOTES, 'UTF-8') ?>">
                                    <?= htmlspecialchars($cust_short !== '' ? $cust_short : $cust_full, ENT_QUOTES, 'UTF-8') ?>
                                </td>
                                <td><?= htmlspecialchars((string) $h['description'], ENT_QUOTES, 'UTF-8') ?></td>
                                <td><?= htmlspecialchars((string) ($h['note'] ?? ''), ENT_QUOTES, 'UTF-8') ?></td>
                                <td>
                                    <?php if ($picked): ?><span class="ofh-badge picked">Đã bốc</span>
                                    <?php elseif ($skipped): ?><span class="ofh-badge skipped">Nhà máy đã bỏ qua</span>
                                    <?php elseif (!empty($h['confirmed'])): ?><span class="ofh-badge confirmed">Đã xác nhận</span>
                                    <?php elseif ($locked): ?><span class="ofh-badge locked">Đã khóa</span>
                                    <?php else: ?><span class="ofh-badge open">Có thể sửa</span><?php endif; ?>
                                    <?php if (!empty($h['pickup_time'])): ?>
                                        <div class="ofh-pickup">Hẹn bốc: <?= date('H:i d/m/Y', strtotime($h['pickup_time'])) ?></div>
                                    <?php endif; ?>
                                </td>
                                <td class="text-center">
                                    <?php if ($editable): ?>
                                        <a href="#" class="ofh-edit" data-id="<?= (int) $h['id'] ?>">Chỉnh sửa</a>
                                    <?php endif; ?>
                                    <?php if ($can_redo): ?>
                                        <a href="?mod=sell_factory&controllers=sell_factory&action=order_factory" class="ofh-redo" data-id="<?= (int) $h['id'] ?>">Đặt lại</a>
                                    <?php endif; ?>
                                    <a href="#" class="btn-delete-history ofh-del" data-id="<?= (int) $h['id'] ?>">Xóa</a>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                        <?php if (empty($history['rows'])): ?>
                            <tr><td colspan="7" style="text-align:center;color:gray;padding:24px;">Bạn chưa có đơn đặt hàng nào.</td></tr>
                        <?php endif; ?>
                    </tbody>
                </table>

                <div class="of-history-pagination" id="ofh-pager">
                    <?php if (($history['total_pages'] ?? 0) > 1): ?>
                        <?php for ($i = 1; $i <= $history['total_pages']; $i++): ?>
                            <a href="?mod=sell_factory&controllers=sell_factory&action=order_history&page=<?= $i ?>&per=<?= $per_page ?>"
                               class="page-link <?= $i === $page ? 'active' : '' ?>"><?= $i ?></a>
                        <?php endfor; ?>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>

    <!-- Thanh xóa hàng loạt: chỉ hiện khi có dòng được tick. -->
    <div class="ofh-bulkbar" id="ofh-bulkbar" hidden>
        <span class="ofh-bulk-count"><b id="ofh-bulk-n">0</b> đơn đang chọn</span>
        <button type="button" class="ofh-bulk-clear" id="ofh-bulk-clear">Bỏ chọn</button>
        <button type="button" class="ofh-bulk-del" id="ofh-bulk-del"><i class="fa-solid fa-trash-can"></i> Xóa</button>
    </div>

    <!-- View chồng lên "Chỉnh sửa đơn hàng" — CÙNG markup/JS với "Đơn hiện tại" bên order_factory. -->
    <?php $panel_mode = 'edit'; include __DIR__ . '/_order_panel.php'; ?>

    <!-- 3 modal phụ trợ mà panel dùng tới (xem ảnh SP / Share Zalo / Gửi qua chat) -->
    <div class="modal-mask" id="modal-info" style="display:none;">
        <div class="modal-box">
            <span class="modal-close" data-close="modal-info">&times;</span>
            <div class="modal-info-body">
                <div class="modal-img"><img id="m-info-image" src="" alt=""></div>
                <div class="modal-info-detail">
                    <h3 id="m-info-name"></h3>
                    <p><strong>Đơn vị:</strong> <span id="m-info-unit"></span></p>
                    <p><strong>Tồn kho:</strong> <span id="m-info-qty"></span></p>
                    <p><strong>Quy cách trong:</strong> <span id="m-info-inner"></span></p>
                    <p><strong>Quy cách ngoài:</strong> <span id="m-info-outer"></span></p>
                    <p><strong>Giá hệ thống:</strong> <span id="m-info-price"></span></p>
                </div>
            </div>
        </div>
    </div>

    <div class="modal-mask" id="modal-zalo" style="display:none;">
        <div class="modal-box">
            <span class="modal-close" data-close="modal-zalo">&times;</span>
            <div id="zalo-capture" class="zalo-capture">
                <div class="zalo-customer"><strong>Tên khách hàng:</strong> <span id="zalo-customer-name"></span></div>
                <div class="zalo-title">ĐẶT HÀNG NHÀ MÁY</div>
                <table class="zalo-table">
                    <thead>
                        <tr><th>Tên sản phẩm</th><th>Đơn vị</th><th>Số lượng</th><th>Khối lượng (kg)</th></tr>
                    </thead>
                    <tbody id="zalo-body"></tbody>
                </table>
                <div class="zalo-weight-total">Tổng khối lượng: <span id="zalo-weight-total-value">0</span> kg</div>
            </div>
            <div class="modal-zalo-footer">
                <button class="btn-capture-zalo" id="btn-capture-zalo">Chụp hóa đơn</button>
            </div>
        </div>
    </div>

    <div class="modal-mask of-chat-modal" id="modal-share-chat" style="display:none;">
        <div class="modal-box of-chat-box">
            <span class="modal-close" data-close="modal-share-chat">&times;</span>
            <div class="of-chat-head">
                <h3><i class="fa-regular fa-comments"></i> Gửi đơn qua chat</h3>
                <p class="of-chat-hint">Chọn người nhận — ảnh hoá đơn sẽ được gửi vào hộp thoại chat với họ.</p>
            </div>
            <input type="text" class="of-chat-filter" id="of-chat-filter" autocomplete="off" placeholder="Tìm tên người nhận...">
            <div class="of-chat-list" id="of-chat-list"><div class="of-chat-loading">Đang tải danh bạ…</div></div>
            <input type="text" class="of-chat-note" id="of-chat-note" autocomplete="off" placeholder="Lời nhắn (tuỳ chọn)">
            <div class="of-chat-foot">
                <span class="of-chat-status" id="of-chat-status"></span>
                <button type="button" class="of-chat-send" id="of-chat-send">Gửi</button>
            </div>
        </div>
    </div>

    <script src="https://cdnjs.cloudflare.com/ajax/libs/html2canvas/1.4.1/html2canvas.min.js"></script>
    <script>
    (function ($) {
        var BASE = '?mod=sell_factory&controllers=sell_factory&action=';

        /* ---------- Xóa 1 đơn ---------- */
        $(document).on('click', '.ofh-del', function (e) {
            e.preventDefault();
            var id = $(this).data('id');
            if (!confirm('Xóa đơn này khỏi lịch sử? Thao tác không thể hoàn tác.')) return;
            var $row = $(this).closest('tr');
            $.post(BASE + 'delete_history', { id: id }, function (res) {
                if (res && res.ok) { $row.remove(); syncBulkBar(); }
                else alert((res && res.msg) || 'Không xóa được.');
            }, 'json').fail(function () { alert('Lỗi mạng/Server.'); });
        });

        /* ---------- Đặt lại ---------- */
        $(document).on('click', '.ofh-redo', function (e) {
            e.preventDefault();
            try { sessionStorage.setItem('sf_reorder_id', $(this).data('id')); } catch (err) {}
            window.location.href = $(this).attr('href');
        });

        /* ---------- Tick chọn nhiều + xóa hàng loạt ---------- */
        function syncBulkBar() {
            var n = $('#ofh-body .ofh-tick:checked').length;
            $('#ofh-bulk-n').text(n);
            $('#ofh-bulkbar').prop('hidden', n === 0);
            var total = $('#ofh-body .ofh-tick').length;
            $('#ofh-check-all').prop('checked', total > 0 && n === total);
        }
        $(document).on('change', '#ofh-check-all', function () {
            $('#ofh-body .ofh-tick').prop('checked', $(this).is(':checked'));
            syncBulkBar();
        });
        $(document).on('change', '.ofh-tick', syncBulkBar);
        $(document).on('click', '#ofh-bulk-clear', function () {
            $('#ofh-body .ofh-tick, #ofh-check-all').prop('checked', false);
            syncBulkBar();
        });
        $(document).on('click', '#ofh-bulk-del', function () {
            var ids = $('#ofh-body .ofh-tick:checked').map(function () { return this.value; }).get();
            if (!ids.length) return;
            if (!confirm('Xóa ' + ids.length + ' đơn đã chọn khỏi lịch sử? Thao tác không thể hoàn tác.')) return;
            var $btn = $(this).prop('disabled', true);
            $.post(BASE + 'delete_history_bulk', { ids: ids.join(',') }, function (res) {
                $btn.prop('disabled', false);
                if (!res || !res.ok) { alert((res && res.msg) || 'Không xóa được.'); return; }
                $.each(ids, function (i, id) { $('#ofh-body tr[data-id="' + id + '"]').remove(); });
                syncBulkBar();
            }, 'json').fail(function () { $btn.prop('disabled', false); alert('Lỗi mạng/Server.'); });
        });

        /* ---------- Số dòng hiển thị ---------- */
        $(document).on('change', '#ofh-per-page', function () {
            window.location.href = BASE + 'order_history&page=1&per=' + encodeURIComponent($(this).val());
        });

        /* ---------- Realtime: nhà máy khóa/bốc/xác nhận/bỏ qua -> cập nhật ngay ---------- */
        function statusBadge(picked, locked, confirmed, skipped) {
            if (picked)    return '<span class="ofh-badge picked">Đã bốc</span>';
            if (skipped)   return '<span class="ofh-badge skipped">Nhà máy đã bỏ qua</span>';
            if (confirmed) return '<span class="ofh-badge confirmed">Đã xác nhận</span>';
            if (locked)    return '<span class="ofh-badge locked">Đã khóa</span>';
            return '<span class="ofh-badge open">Có thể sửa</span>';
        }
        function refreshStatuses() {
            $.getJSON(BASE + 'order_statuses', function (res) {
                if (!res || !res.ok) return;
                $.each(res.data || [], function (i, s) {
                    var id = Number(s.id);
                    var $row = $('#ofh-body tr[data-id="' + id + '"]');
                    if (!$row.length) return;
                    // Đang mở view sửa CHÍNH đơn này -> đừng đụng để không phá thao tác.
                    if (window.ofEditingOrderId && Number(window.ofEditingOrderId) === id
                        && $('#of-order-view').is(':visible')) return;

                    var picked = Number(s.picked) === 1, locked = Number(s.locked) === 1,
                        confirmed = Number(s.confirmed) === 1, fhidden = Number(s.factory_hidden) === 1;
                    var skipped = fhidden && !picked;
                    var editable = !picked && !locked && !fhidden;
                    var canRedo = picked || fhidden;

                    var $st = $row.find('td').eq(5);           // +1 cột tick so với bản cũ
                    var $pickup = $st.find('.ofh-pickup');
                    $st.html(statusBadge(picked, locked, confirmed, skipped));
                    if ($pickup.length) $st.append($pickup);

                    var act = '';
                    if (editable) act += '<a href="#" class="ofh-edit" data-id="' + id + '">Chỉnh sửa</a> ';
                    if (canRedo)  act += '<a href="?mod=sell_factory&controllers=sell_factory&action=order_factory" class="ofh-redo" data-id="' + id + '">Đặt lại</a> ';
                    act += '<a href="#" class="btn-delete-history ofh-del" data-id="' + id + '">Xóa</a>';
                    $row.find('td').eq(6).html(act);
                });
            });
        }
        if (window.AppPoll) window.AppPoll.every('ofh-status', refreshStatuses, { interval: 20000, maxInterval: 60000 });
        else setInterval(refreshStatuses, 20000);
    })(jQuery);
    </script>
    <script src="<?php echo asset_ver('public/js/sell-factory/order-factory.js'); ?>"></script>
    <script src="<?php echo asset_ver('public/js/shared/app_shell.js'); ?>"></script>
</body>

</html>
