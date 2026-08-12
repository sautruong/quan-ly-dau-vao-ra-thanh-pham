<?php

/**
 * KHO — view "Soạn hàng".
 * Toàn bộ danh sách hàng do JS vẽ từ JSON (WPK_SLIP) để mỗi lần AJAX xong chỉ
 * chạy đúng MỘT hàm render — không có 2 bản markup PHP/JS lệch nhau.
 *
 * @var array      $slips
 * @var array|null $slip
 */
$slips = isset($slips) && is_array($slips) ? $slips : [];
$slip  = isset($slip) && is_array($slip) ? $slip : null;

if (!function_exists('wpk_esc')) {
    function wpk_esc($s) { return htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8'); }
}
$accent = $slip && !empty($slip['accent']) ? (string) $slip['accent'] : '#16a34a';
?>
<!DOCTYPE html>
<html lang="vi">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Soạn hàng</title>
    <link rel="icon" href="public/images/logo/logo_vat_png.png" type="image/png">
    <link rel="stylesheet" href="<?php echo asset_ver('public/css/reset.css'); ?>">
    <link rel="stylesheet" href="<?php echo asset_ver('public/css/all.css'); ?>">
    <link rel="stylesheet" href="<?php echo asset_ver('public/css/global.css'); ?>">
    <link rel="stylesheet" href="<?php echo asset_ver('public/css/shared/app_shell.css'); ?>">
    <link rel="stylesheet" href="<?php echo asset_ver('public/css/warehouse/picking_task.css'); ?>">
    <script src="<?php echo asset_ver('public/js/jquery-4.0.0.js'); ?>"></script>
</head>

<body>
    <div id="wrapper">
        <?php get_sidebar('app'); ?>
        <?php get_header('app'); ?>

        <div class="wpk-page" style="--wpk-accent: <?php echo wpk_esc($accent); ?>;">

            <div class="wpk-page-head">
                <h1><i class="fa-solid fa-dolly"></i> Soạn hàng</h1>
                <span class="wpk-page-sub">Phiếu soạn admin gửi xuống — bốc xong tích vào từng dòng.</span>
            </div>

            <!-- Chọn phiếu đang soạn -->
            <div class="wpk-chips" id="wpk-chips">
                <?php foreach ($slips as $s):
                    $done   = ((string) $s['status'] === 'done');
                    $active = $slip && (int) $s['id'] === (int) $slip['id'];
                    $tot    = (int) $s['total_items'];
                    $pk     = (int) $s['picked_items'];
                ?>
                    <button type="button" class="wpk-chip<?php echo $active ? ' is-active' : ''; ?><?php echo $done ? ' is-done' : ''; ?>"
                            data-slip-id="<?php echo (int) $s['id']; ?>">
                        <span class="wpk-chip-name"><?php echo wpk_esc($s['label']); ?></span>
                        <span class="wpk-chip-count"><?php echo $pk; ?>/<?php echo $tot; ?></span>
                        <?php if ($done): ?><i class="fa-solid fa-circle-check"></i><?php endif; ?>
                    </button>
                <?php endforeach; ?>
            </div>

            <?php if (!$slip): ?>
                <p class="wpk-empty">Chưa có phiếu soạn nào. Admin gửi phiếu từ trang <b>Đơn hàng từ chi nhánh</b>.</p>
            <?php else: ?>

                <div class="wpk-slip" id="wpk-slip" data-slip-id="<?php echo (int) $slip['id']; ?>">

                    <div class="wpk-info">
                        <div class="wpk-info-row"><span>Khách hàng:</span><b id="wpk-cust"><?php echo wpk_esc(trim((string) $slip['customer_short']) !== '' ? $slip['customer_short'] : $slip['customer_name']); ?></b></div>
                        <div class="wpk-info-row"><span>Người nhận:</span><b><?php
                            $rc = trim((string) $slip['receiver']);
                            $ph = trim((string) $slip['phone']);
                            echo wpk_esc($rc . ($ph !== '' ? ' - ' . $ph : ''));
                        ?></b></div>
                        <div class="wpk-info-row"><span>Địa chỉ:</span><b><?php echo wpk_esc($slip['address']); ?></b></div>
                    </div>

                    <div class="wpk-toolbar">
                        <div class="wpk-search-box">
                            <input type="text" id="wpk-search" autocomplete="off" placeholder="Thêm sản phẩm / NVL vào phiếu...">
                            <ul class="wpk-suggest" id="wpk-suggest"></ul>
                        </div>
                    </div>

                    <div class="wpk-hint no-print">
                        <i class="fa-solid fa-hand-pointer"></i>
                        Gạt <b>tên hàng sang phải</b> để đánh số chung kiện · gạt <b>ô số lượng sang trái</b> để nhập số bốc khác.
                    </div>

                    <div class="wpk-table-head">
                        <span>Tên sản phẩm</span>
                        <span>Đặt hàng</span>
                    </div>

                    <div class="wpk-list" id="wpk-list"></div>

                    <div class="wpk-removed" id="wpk-removed" hidden>
                        <div class="wpk-removed-head"><i class="fa-solid fa-trash-can"></i> Đã gỡ khỏi phiếu <span id="wpk-removed-count"></span></div>
                        <div class="wpk-removed-list" id="wpk-removed-list"></div>
                    </div>

                    <div class="wpk-kien" id="wpk-kien"></div>

                    <div class="wpk-warn" id="wpk-warn" hidden></div>

                    <div class="wpk-foot">
                        <div class="wpk-foot-note">
                            <span>Ghi chú:</span>
                            <input type="text" id="wpk-note" value="<?php echo wpk_esc($slip['note']); ?>" placeholder="Nhập ghi chú...">
                        </div>
                        <div class="wpk-foot-sum">Số kiện dự kiến: <b id="wpk-sum"></b></div>
                        <button type="button" class="wpk-btn wpk-btn-done" id="wpk-finish">
                            <i class="fa-solid fa-circle-check"></i> Soạn xong
                        </button>
                    </div>
                </div>

            <?php endif; ?>
        </div>
    </div>

    <!-- MODAL: thông tin hàng hóa (bấm vào tên sản phẩm) -->
    <div class="wpk-modal-mask" id="wpk-info-modal" hidden>
        <div class="wpk-modal-box">
            <button type="button" class="wpk-modal-close" id="wpk-info-close">&times;</button>
            <h3 class="wpk-modal-title" id="wpk-info-name"></h3>
            <div class="wpk-info-lines" id="wpk-info-lines"></div>
        </div>
    </div>

    <script>
        window.WPK_SLIP = <?php echo $slip ? json_encode($slip, JSON_UNESCAPED_UNICODE) : 'null'; ?>;
    </script>
    <script src="<?php echo asset_ver('public/js/warehouse/picking_task.js'); ?>"></script>
    <script src="<?php echo asset_ver('public/js/shared/app_shell.js'); ?>"></script>
</body>

</html>
