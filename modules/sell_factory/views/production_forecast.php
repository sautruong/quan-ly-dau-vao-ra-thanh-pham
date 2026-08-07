<?php

/** @var array $days */
$days = isset($days) && is_array($days) ? $days : [];
?>
<!DOCTYPE html>
<html lang="vi">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>KHSX dự kiến</title>
    <link rel="icon" href="public/images/logo/logo_vat_png.png" type="image/png">
    <link rel="stylesheet" href="<?php echo asset_ver('public/css/reset.css'); ?>">
    <link rel="stylesheet" href="<?php echo asset_ver('public/css/all.css'); ?>">
    <link rel="stylesheet" href="<?php echo asset_ver('public/css/global.css'); ?>">
    <link rel="stylesheet" href="<?php echo asset_ver('public/css/sell-factory/order-factory.css'); ?>">
    <link rel="stylesheet" href="<?php echo asset_ver('public/css/production_staff/long_term_production_plan.css'); ?>">
    <link rel="stylesheet" href="<?php echo asset_ver('public/css/shared/app_shell.css'); ?>">
    <style>
        /* App shell: full width (order-factory.css đặt .inventory-factory = 50%) */
        #wrapper { display: block; background: #fff; }
        .inventory-factory { width: 100% !important; }
        .pf-wrap { padding: 14px 18px; }
        .pf-board .ltp-card { cursor: default; }
        /* Mobile: card ngày bị rút gọn còn tiêu đề (long_term_production_plan.css @media 768px)
           -> bấm để mở modal xem danh sách, nên phải trả lại con trỏ "bấm được". */
        @media (max-width: 768px) {
            .pf-board .ltp-card { cursor: pointer; }
        }
        .pf-empty-day { color: #aab1ba; font-size: 13px; padding: 6px 2px; }
        /* Forecast hiển thị kèm đơn vị nên không bó hẹp ô số lượng như bảng nhập */
        .pf-board .ltp-item-name { font-size: 12px; }
        .pf-board .ltp-item-qty { width: auto; font-size: 12px; }
    </style>
</head>

<body>
    <div id="wrapper">
        <?php get_sidebar('app'); ?>
        <?php get_header('app'); ?>

        <div class="of-page">
            <?php /* Đã gỡ thanh tab — 3 view đã thành 3 mục menu cấp 2 ở sidebar. */ ?>
            <div class="pf-wrap">
                <div class="ltp-page-head">
                    <h1>KẾ HOẠCH SẢN XUẤT DỰ KIẾN</h1>
                    <p class="ltp-hint">Lịch sản xuất dự kiến của nhà máy theo tuần (gồm 2 ngày trước, hôm nay và các ngày tới).</p>
                </div>

                <div class="ltp-board pf-board">
                    <?php $pf_first = true; foreach ($days as $d):
                        $items = $d['items'] ?? [];
                        $card_cls = '';
                        if (!empty($d['is_sunday'])) $card_cls .= ' is-sunday';
                        if (!empty($d['is_past']))   $card_cls .= ' is-past';
                        if (!empty($d['is_today']))  $card_cls .= ' is-today';
                        // Card đầu tiên đặt đúng cột theo thứ (1=Thứ 2 ... 7=Chủ nhật).
                        $card_style = $pf_first ? ' style="grid-column-start:' . (int) date('N', strtotime($d['date'])) . '"' : '';
                        $pf_first = false;
                    ?>
                        <div class="ltp-card<?php echo $card_cls; ?>"<?php echo $card_style; ?>>
                            <div class="ltp-card-head">
                                <div class="ltp-day" style="margin-bottom:0;">
                                    <span class="wd"><?php echo htmlspecialchars($d['weekday'], ENT_QUOTES, 'UTF-8'); ?></span>
                                    <span class="dt">(<?php echo htmlspecialchars($d['display'], ENT_QUOTES, 'UTF-8'); ?>)</span>
                                    <?php if (!empty($d['is_today'])): ?>
                                        <span class="ltp-tag ltp-tag-today">HÔM NAY</span>
                                    <?php elseif (!empty($d['is_past'])): ?>
                                        <span class="ltp-tag ltp-tag-past">Đã qua</span>
                                    <?php endif; ?>
                                </div>
                            </div>
                            <div class="ltp-card-body">
                                <div class="ltp-cluster ltp-products">
                                    <?php if (empty($items)): ?>
                                        <div class="pf-empty-day">Chưa có kế hoạch.</div>
                                    <?php else: ?>
                                        <ul class="ltp-list">
                                            <?php foreach ($items as $it):
                                                $nm = htmlspecialchars((string) ($it['product_name'] ?: ('#' . (int) $it['product_id'])), ENT_QUOTES, 'UTF-8');
                                                $q  = rtrim(rtrim(number_format((float) $it['quantity'], 2, '.', ''), '0'), '.');
                                                $unit = htmlspecialchars((string) ($it['unit'] ?? ''), ENT_QUOTES, 'UTF-8');
                                            ?>
                                                <li class="ltp-item" style="padding-right:8px;">
                                                    <span class="ltp-item-name"><?php echo $nm; ?></span>
                                                    <span class="ltp-item-qty"><?php echo htmlspecialchars($q, ENT_QUOTES, 'UTF-8'); ?> <?php echo $unit; ?></span>
                                                </li>
                                            <?php endforeach; ?>
                                        </ul>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>
        </div>
    </div>

    <?php require LAYOUTPATH . DIRECTORY_SEPARATOR . 'day-plan-modal.php'; ?>

    <script src="<?php echo asset_ver('public/js/shared/day_plan_modal.js'); ?>"></script>
    <script src="<?php echo asset_ver('public/js/shared/app_shell.js'); ?>"></script>
</body>

</html>
