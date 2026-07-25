<?php

/** @var array|null $order */
/** @var array $customers */
$order     = isset($order) && is_array($order) ? $order : null;
$customers = isset($customers) && is_array($customers) ? $customers : [];

if (!function_exists('om_wt')) {
    function om_wt($n) { return rtrim(rtrim(number_format((float) $n, 2, ',', '.'), '0'), ','); }
}

$is_manual = ($order === null);
$accent    = (!$is_manual && !empty($order['secondary_color'])) ? $order['secondary_color'] : '#16a34a';
// Tên khách hàng: ưu tiên tên viết tắt.
$cust_short = $is_manual ? '' : trim((string) ($order['short_name'] ?? ''));
$cust_full  = $is_manual ? '' : (string) ($order['customer_name'] ?? '');
$cust_name  = $cust_short !== '' ? $cust_short : $cust_full;
$receiver  = $is_manual ? '' : (string) ($order['receiver'] ?? '');
$note      = $is_manual ? '' : (string) ($order['note'] ?? '');

// Ngày in (tiếng Việt).
$today_d = date('j'); $today_m = date('n'); $today_y = date('Y');

// Số kiện dự kiến (order mode).
$kien_line = '';
if (!$is_manual && !empty($order['kien_sum'])) {
    $parts = $order['kien_sum']['parts'] ?? [];
    $loose = (int) ($order['kien_sum']['loose'] ?? 0);
    $segs  = $parts;
    if ($loose > 0) $segs[] = $loose . ' SP lẻ';
    $kien_line = implode(' + ', $segs);
}
?>
<!DOCTYPE html>
<html lang="vi">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Phiếu soạn hàng</title>
    <link rel="icon" href="public/images/logo/logo_vat_png.png" type="image/png">
    <link rel="stylesheet" href="public/css/reset.css">
    <link rel="stylesheet" href="public/css/all.css">
    <link rel="stylesheet" href="public/css/global.css">
    <link rel="stylesheet" href="public/css/order_management/order_management.css">
    <link rel="stylesheet" href="<?php echo asset_ver('public/css/shared/app_shell.css'); ?>">
    <script src="public/js/jquery-4.0.0.js"></script>
</head>

<body>
    <div id="wrapper">
        <?php get_sidebar('app'); ?>
        <?php get_header('app'); ?>

        <div class="om-page om-slip-page">
            <!-- ===== TOOLBAR (không in) ===== -->
            <div class="om-slip-toolbar no-print<?= $is_manual ? ' is-manual' : '' ?>">
                <?php if ($is_manual): ?>
                    <div class="om-slip-manual">
                        <!-- Khối 1: chọn chi nhánh (căn giữa) -->
                        <div class="om-field om-field-branch">
                            <label>Chi nhánh:</label>
                            <select id="ps-branch">
                                <option value="">— Chọn chi nhánh —</option>
                                <?php foreach ($customers as $c): ?>
                                    <option value="<?= (int) $c['id'] ?>"
                                            data-name="<?= htmlspecialchars((string) $c['name'], ENT_QUOTES, 'UTF-8') ?>"
                                            data-short="<?= htmlspecialchars((string) ($c['short_name'] ?? ''), ENT_QUOTES, 'UTF-8') ?>"
                                            data-receiver="<?= htmlspecialchars((string) ($c['receiver'] ?? ''), ENT_QUOTES, 'UTF-8') ?>"
                                            data-color="<?= htmlspecialchars((string) ($c['secondary_color'] ?? '#16a34a'), ENT_QUOTES, 'UTF-8') ?>">
                                        <?= htmlspecialchars((string) $c['name'], ENT_QUOTES, 'UTF-8') ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <!-- Khối 2: tìm sản phẩm + nút thao tác (căn giữa) -->
                        <div class="om-slip-manual-actions">
                            <div class="om-field om-field-search">
                                <label>Thêm sản phẩm:</label>
                                <div class="om-search-box">
                                    <input type="text" id="ps-search" autocomplete="off" placeholder="Nhập tên sản phẩm / NVL bao bì...">
                                    <ul class="om-suggest" id="ps-suggest"></ul>
                                </div>
                            </div>
                            <button type="button" class="om-btn om-btn-success" id="ps-confirm-outbound">
                                <i class="fa-solid fa-truck-ramp-box"></i> Xác nhận xuất kho
                            </button>
                            <button type="button" class="om-btn om-btn-ghost" id="ps-setting-btn" title="Thứ tự hiển thị phiếu soạn">
                                <i class="fa-solid fa-gear"></i> Setting
                            </button>
                            <button type="button" class="om-btn om-btn-primary" id="ps-print">
                                <i class="fa-solid fa-print"></i> In
                            </button>
                        </div>
                    </div>
                <?php else: ?>
                    <a class="om-btn om-btn-ghost" href="?mod=order_management&controllers=order_management&action=branch_orders">
                        <i class="fa-solid fa-arrow-left"></i> Danh sách đơn
                    </a>
                    <div class="om-slip-tb-right">
                        <button type="button" class="om-btn om-btn-success" id="ps-confirm-outbound">
                            <i class="fa-solid fa-truck-ramp-box"></i> Xác nhận xuất kho
                        </button>
                        <button type="button" class="om-btn om-btn-ghost" id="ps-setting-btn" title="Thứ tự hiển thị phiếu soạn">
                            <i class="fa-solid fa-gear"></i> Setting
                        </button>
                        <button type="button" class="om-btn om-btn-primary" id="ps-print">
                            <i class="fa-solid fa-print"></i> In
                        </button>
                    </div>
                <?php endif; ?>
            </div>

            <!-- ===== PHIẾU A4 (bọc trong vùng cuộn ngang + dọc) ===== -->
            <div class="om-slip-scroll">
            <div class="om-slip" id="om-slip" style="--om-accent: <?= htmlspecialchars($accent, ENT_QUOTES, 'UTF-8') ?>;">
                <div class="om-slip-head">
                    <div class="om-slip-head-main">
                        <div class="om-slip-title">PHIẾU SOẠN HÀNG</div>
                        <div class="om-slip-date">Ngày <?= $today_d ?> tháng <?= $today_m ?> năm <?= $today_y ?></div>
                    </div>
                    <div class="om-slip-info">
                        <div>Tên khách hàng: <b id="ps-cust"><?= htmlspecialchars($cust_name, ENT_QUOTES, 'UTF-8') ?></b></div>
                        <div>Người nhận: <b id="ps-receiver"><?= htmlspecialchars($receiver, ENT_QUOTES, 'UTF-8') ?></b></div>
                    </div>
                </div>

                <table class="om-slip-table">
                    <thead>
                        <tr>
                            <th style="width:42px;"><div class="ps-th-chip ps-th-accent">STT</div></th>
                            <th class="text-left"><div class="ps-th-chip ps-th-accent">Tên sản phẩm</div></th>
                            <th style="width:120px;" class="ps-qty-h"><div class="ps-th-chip ps-th-gray">Đặt hàng</div></th>
                            <th style="width:150px;"><div class="ps-th-chip ps-th-gray">Chung kiện</div></th>
                        </tr>
                    </thead>
                    <tbody id="ps-body">
                        <?php if (!$is_manual): $stt = 0; foreach ($order['items'] as $it): $stt++;
                            $isMat = !empty($it['material_id']);
                            $sortv = ($it['sort_order'] !== null && $it['sort_order'] !== '') ? (int) $it['sort_order'] : '';
                        ?>
                            <tr data-product-id="<?= $isMat ? 0 : (int) $it['product_id'] ?>"
                                data-material="<?= $isMat ? 1 : 0 ?>"
                                data-name="<?= htmlspecialchars((string) $it['product_name'], ENT_QUOTES, 'UTF-8') ?>"
                                data-sort="<?= $sortv ?>"
                                data-whole="<?= (int) $it['kien_whole'] ?>"
                                data-rem="<?= htmlspecialchars((string) $it['kien_rem'], ENT_QUOTES, 'UTF-8') ?>"
                                data-ops="<?= (int) $it['ops_qty'] ?>">
                                <td class="text-center ps-stt"><span class="ps-stt-badge"><?= $stt ?></span></td>
                                <td class="text-left">
                                    <div class="ps-pname">
                                        <?php if (!empty($it['is_short'])): ?><i class="fa-solid fa-triangle-exclamation ps-short-flag" title="Thiếu tồn: cần <?= om_wt($it['qt_order']) ?>, tồn <?= om_wt($it['stock']) ?>"></i><?php endif; ?>
                                        <?= htmlspecialchars(mb_strtoupper((string) $it['product_name'], 'UTF-8'), ENT_QUOTES, 'UTF-8') ?>
                                    </div>
                                    <div class="ps-sub">
                                        <?php if (!empty($it['pack_label'])): ?><span><?= htmlspecialchars((string) $it['pack_label'], ENT_QUOTES, 'UTF-8') ?></span><?php endif; ?>
                                        <span class="ps-tk">TK: <span class="ps-conv" data-kien="<?= htmlspecialchars((string) $it['inv_kien'], ENT_QUOTES, 'UTF-8') ?>" data-raw="<?= htmlspecialchars(om_wt($it['stock']), ENT_QUOTES, 'UTF-8') ?>"><?= htmlspecialchars((string) $it['inv_kien'], ENT_QUOTES, 'UTF-8') ?></span><span class="ps-conv-tg no-print" title="Quy đổi tồn ra số"><i class="fa-solid fa-right-left"></i></span></span>
                                        <?php if (!empty($it['reminder_note'])): ?><span class="ps-reminder"><i class="fa-solid fa-thumbtack"></i> <?= htmlspecialchars((string) $it['reminder_note'], ENT_QUOTES, 'UTF-8') ?></span><?php endif; ?>
                                    </div>
                                </td>
                                <td class="ps-qty"><span class="ps-check"></span><span class="ps-qty-val ps-conv" data-kien="<?= htmlspecialchars((string) $it['kien_text'], ENT_QUOTES, 'UTF-8') ?>" data-raw="<?= htmlspecialchars(om_wt($it['qt_order']), ENT_QUOTES, 'UTF-8') ?>"><?= htmlspecialchars((string) $it['kien_text'], ENT_QUOTES, 'UTF-8') ?></span><span class="ps-conv-tg no-print" title="Quy đổi ra số"><i class="fa-solid fa-right-left"></i></span></td>
                                <td class="ps-shapes">
                                    <?php if ((int) $it['kien_whole'] === 0): // chỉ SP lẻ (SL đặt < quy cách) ?>
                                        <span class="ps-shape ps-sq"></span>
                                        <span class="ps-shape ps-ci"></span>
                                        <span class="ps-shape ps-hex"></span>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; endif; ?>
                    </tbody>
                </table>

                <div class="ps-stock-warn no-print" id="ps-stock-warn" style="display:none;"></div>

                <div class="om-slip-foot">
                    <div class="om-slip-note"><span class="ps-note-label">Ghi chú:</span>
                        <input type="text" id="ps-note-input" class="ps-note-input no-print" value="<?= htmlspecialchars($note, ENT_QUOTES, 'UTF-8') ?>" placeholder="Nhập ghi chú...">
                        <span id="ps-note" class="ps-note-print"><?= htmlspecialchars($note, ENT_QUOTES, 'UTF-8') ?></span>
                    </div>
                    <div class="om-slip-kien">Số kiện dự kiến: <b id="ps-kien"><?= htmlspecialchars($kien_line, ENT_QUOTES, 'UTF-8') ?></b></div>
                </div>
            </div>
            </div>
        </div>
    </div>

    <!-- ===== MODAL: Thứ tự hiển thị phiếu soạn ===== -->
    <div class="om-modal-mask" id="ps-setting-modal" style="display:none;">
        <div class="om-modal-box ps-setting-box">
            <span class="om-modal-close" id="ps-setting-close">&times;</span>
            <h3 class="om-modal-title"><i class="fa-solid fa-gear"></i> Thứ tự hiển thị phiếu soạn</h3>
            <p class="om-modal-hint">Nhập số thứ tự (1, 2, 3…) — số nhỏ hiện trước; số trùng xếp theo A→B; bỏ trống xếp cuối. Trong mỗi nhóm quy cách, sản phẩm có số thứ tự sẽ xếp trước.</p>
            <input type="text" id="ps-setting-search" class="ps-setting-search" autocomplete="off" placeholder="Lọc theo tên sản phẩm...">
            <div class="ps-setting-list" id="ps-setting-list"><div class="ps-setting-loading">Đang tải…</div></div>
            <div class="om-modal-foot">
                <button type="button" class="om-btn om-btn-primary" id="ps-setting-save"><i class="fa-solid fa-floppy-disk"></i> Lưu thứ tự</button>
            </div>
        </div>
    </div>

    <script>
        window.OM_MANUAL = <?= $is_manual ? 'true' : 'false' ?>;
        window.OM_ORDER_ID = <?= $is_manual ? '0' : (int) ($order['id'] ?? 0) ?>;
    </script>
    <script src="public/js/order_management/picking_slip.js"></script>
    <script src="<?php echo asset_ver('public/js/shared/app_shell.js'); ?>"></script>
</body>

</html>
