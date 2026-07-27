<?php
defined('APPPATH') OR exit('Không được quyền truy cập phần này');
/** @var array $days */
$days          = isset($days) && is_array($days) ? $days : [];
$items_by_date = isset($items_by_date) && is_array($items_by_date) ? $items_by_date : [];
$tasks_by_date = isset($tasks_by_date) && is_array($tasks_by_date) ? $tasks_by_date : [];
$completed     = isset($completed) && is_array($completed) ? $completed : ['days' => [], 'count' => 0];
$backup_items  = isset($backup_items) && is_array($backup_items) ? $backup_items : [];
$backup_ttl    = isset($backup_ttl) ? (string) $backup_ttl : '1m';

/** Render 1 dòng sản phẩm. */
function ltp_render_item($it)
{
    $id   = (int) $it['id'];
    $pid  = (int) $it['product_id'];
    $name = htmlspecialchars((string) ($it['product_name'] ?: ('#' . $pid)), ENT_QUOTES, 'UTF-8');
    $unit = htmlspecialchars((string) ($it['unit'] ?? ''), ENT_QUOTES, 'UTF-8');
    $qty  = htmlspecialchars(rtrim(rtrim(number_format((float) $it['quantity'], 2, '.', ''), '0'), '.'), ENT_QUOTES, 'UTF-8');
    echo '<li class="ltp-item" data-id="' . $id . '" data-product-id="' . $pid . '" data-unit="' . $unit . '">'
        . '<span class="ltp-item-grip" draggable="true" title="Kéo để chuyển ngày"><i class="fa-solid fa-grip-vertical"></i></span>'
        . '<input type="text" class="ltp-item-name ltp-prod-search" value="' . $name . '" data-name="' . $name . '" autocomplete="off" spellcheck="false" title="Bấm để đổi sản phẩm">'
        . '<input type="text" class="ltp-item-qty" value="' . $qty . '" inputmode="decimal" title="Bấm để đổi số lượng">'
        . '<span class="ltp-item-del" title="Xóa">&times;</span>'
        . '</li>';
}

/** Render 1 dòng việc khác (nội dung là input — bấm sửa tại chỗ). */
function ltp_render_task($tk)
{
    $id = (int) $tk['id'];
    $content = htmlspecialchars((string) $tk['content'], ENT_QUOTES, 'UTF-8');
    echo '<li class="ltp-task" data-id="' . $id . '">'
        . '<span class="ltp-task-grip" draggable="true" title="Kéo để chuyển ngày"><i class="fa-solid fa-grip-vertical"></i></span>'
        . '<input type="text" class="ltp-task-content" value="' . $content . '" data-content="' . $content . '" placeholder="Nội dung việc..." title="Bấm để sửa">'
        . '<span class="ltp-task-del" title="Xóa">&times;</span>'
        . '</li>';
}

/** Render 1 dòng sản phẩm DỰ PHÒNG. */
function ltp_render_backup_item($b)
{
    $id   = (int) $b['id'];
    $pid  = (int) $b['product_id'];
    $name = htmlspecialchars((string) ($b['product_name'] ?: ('#' . $pid)), ENT_QUOTES, 'UTF-8');
    $qty  = htmlspecialchars(rtrim(rtrim(number_format((float) $b['quantity'], 2, '.', ''), '0'), '.'), ENT_QUOTES, 'UTF-8');
    $created = !empty($b['created_at']) ? htmlspecialchars(date('d/m/Y', strtotime((string) $b['created_at'])), ENT_QUOTES, 'UTF-8') : '';
    $note = htmlspecialchars((string) ($b['note'] ?? ''), ENT_QUOTES, 'UTF-8');
    echo '<li class="ltp-backup-item" data-id="' . $id . '" data-product-id="' . $pid . '">'
        . '<span class="ltp-bk-grip" draggable="true" title="Kéo để sắp xếp / đưa lên ngày"><i class="fa-solid fa-grip-vertical"></i></span>'
        . '<div class="ltp-bk-main">'
        .   '<input type="text" class="ltp-bk-name ltp-prod-search" value="' . $name . '" data-name="' . $name . '" autocomplete="off" spellcheck="false" placeholder="Tên sản phẩm..." title="Bấm để đổi sản phẩm">'
        .   '<div class="ltp-bk-meta">'
        .     '<span class="ltp-bk-date">' . ($created !== '' ? 'Tạo: ' . $created : '') . '</span>'
        .     '<input type="text" class="ltp-bk-note-input" placeholder="Nhập ghi chú.." value="' . $note . '" data-note="' . $note . '">'
        .   '</div>'
        . '</div>'
        . '<input type="text" class="ltp-bk-qty" value="' . $qty . '" inputmode="decimal" placeholder="SL" title="Bấm để đổi số lượng">'
        . '<span class="ltp-bk-del" title="Xóa">&times;</span>'
        . '</li>';
}

/** Nhãn tuần tương đối so với hôm nay (Tuần trước / Tuần này / Tuần sau / N tuần …). */
function ltp_week_label($monday_ts, $today_monday_ts)
{
    $off = (int) round(($monday_ts - $today_monday_ts) / (7 * 86400));
    if ($off === 0)  return 'Tuần này';
    if ($off === -1) return 'Tuần trước';
    if ($off === 1)  return 'Tuần sau';
    return $off < 0 ? (abs($off) . ' tuần trước') : ($off . ' tuần sau');
}
$ltp_today_monday_ts = strtotime('monday this week', strtotime(date('Y-m-d')));
?>
<!DOCTYPE html>
<html lang="vi">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Kế hoạch sản xuất dài ngày</title>
    <link rel="icon" href="public/images/logo/logo_vat_png.png" type="image/png">
    <link rel="stylesheet" href="<?php echo asset_ver('public/css/reset.css'); ?>">
    <link rel="stylesheet" href="<?php echo asset_ver('public/css/all.css'); ?>">
    <link rel="stylesheet" href="<?php echo asset_ver('public/css/global.css'); ?>">
    <link rel="stylesheet" href="<?php echo asset_ver('public/css/production_staff/long_term_production_plan.css'); ?>">
    <link rel="stylesheet" href="<?php echo asset_ver('public/css/shared/app_shell.css'); ?>">
</head>

<body>
    <div id="wrapper" class="has-sider">
        <?php get_sidebar('app'); ?>
        <?php get_header('app'); ?>

        <div class="content">
            <div class="ltp-toolbar">
                <div class="ltp-stock-lookup" id="ltp-stock-lookup">
                    <input type="text" class="ltp-stock-input" id="ltp-stock-input" autocomplete="off"
                           spellcheck="false" placeholder="Nhập tên nguyên vật liệu..">
                    <ul class="ltp-stock-suggest" id="ltp-stock-suggest"></ul>
                </div>
                <button type="button" class="ltp-btn ltp-stock-btn" id="ltp-stock-material"
                        title="Xem nhanh tồn 1 nguyên vật liệu">Xem tồn NVL</button>
                <button type="button" class="ltp-btn ltp-stock-btn" id="ltp-stock-product"
                        title="Xem nhanh tồn 1 thành phẩm">Xem tồn thành phẩm</button>
                <button type="button" class="ltp-btn ltp-btn-add-day" id="ltp-add-day-first" title="Thêm 1 ngày vào đầu board">+ Thêm đầu</button>
                <button type="button" class="ltp-btn ltp-btn-add-day" id="ltp-add-day" title="Thêm 1 ngày vào cuối board">+ Thêm cuối</button>
            </div>
            <div class="ltp-board" id="ltp-board">
                <?php $ltp_cur_week = null; foreach ($days as $d):
                    $date  = $d['date'];
                    $items = $items_by_date[$date] ?? [];
                    $tasks = $tasks_by_date[$date] ?? [];
                    // Gom theo tuần (thứ 2 đầu tuần) -> mỗi tuần là 1 nhóm FAQ.
                    $mon_ts   = strtotime('monday this week', strtotime($date));
                    $mon      = date('Y-m-d', $mon_ts);
                    $new_week = ($mon !== $ltp_cur_week);
                    if ($new_week):
                        $ltp_cur_week = $mon;
                        $wk_label = ltp_week_label($mon_ts, $ltp_today_monday_ts);
                ?>
                    <div class="ltp-week-head" data-week="<?php echo htmlspecialchars($mon, ENT_QUOTES, 'UTF-8'); ?>" title="Bấm để thu gọn / mở">
                        <i class="fa-solid fa-chevron-down ltp-week-caret"></i>
                        <span class="ltp-week-title"><?php echo htmlspecialchars($wk_label, ENT_QUOTES, 'UTF-8'); ?></span>
                    </div>
                <?php endif; ?>
                    <?php
                    $card_cls = '';
                    if (!empty($d['is_sunday'])) $card_cls .= ' is-sunday';
                    if (!empty($d['is_past']))   $card_cls .= ' is-past';
                    if (!empty($d['is_today']))  $card_cls .= ' is-today';
                    // Card đầu mỗi tuần đặt đúng cột theo thứ (1=Thứ 2 ... 7=Chủ nhật) -> lịch tuần.
                    $card_style = $new_week ? ' style="grid-column-start:' . (int) date('N', strtotime($date)) . '"' : '';
                    ?>
                    <div class="ltp-card<?php echo $card_cls; ?>"<?php echo $card_style; ?>
                         draggable="true" data-date="<?php echo htmlspecialchars($date, ENT_QUOTES, 'UTF-8'); ?>"
                         data-week="<?php echo htmlspecialchars($mon, ENT_QUOTES, 'UTF-8'); ?>">
                        <div class="ltp-card-head">
                            <span class="ltp-card-del" title="Xóa ngày (đầu/cuối: xóa card; giữa: xóa nội dung)">&times;</span>
                            <div class="ltp-day">
                                <span class="wd"><?php echo htmlspecialchars($d['weekday'], ENT_QUOTES, 'UTF-8'); ?></span>
                                <span class="dt">(<?php echo htmlspecialchars($d['display'], ENT_QUOTES, 'UTF-8'); ?>)</span>
                                <?php if (!empty($d['is_today'])): ?>
                                    <span class="ltp-tag ltp-tag-today">HÔM NAY</span>
                                <?php elseif (!empty($d['is_past'])): ?>
                                    <span class="ltp-tag ltp-tag-past">Đã qua</span>
                                <?php endif; ?>
                            </div>
                            <div class="ltp-card-actions">
                                <button type="button" class="ltp-btn ltp-btn-add-product">+ Sản phẩm</button>
                                <button type="button" class="ltp-btn ltp-btn-add-task">Việc khác</button>
                            </div>
                        </div>

                        <div class="ltp-card-body">
                            <div class="ltp-cluster ltp-products">
                                <ul class="ltp-list ltp-product-list">
                                    <?php foreach ($items as $it) ltp_render_item($it); ?>
                                </ul>
                            </div>
                            <div class="ltp-cluster ltp-tasks">
                                <div class="ltp-cluster-title">Việc khác:</div>
                                <ul class="ltp-list ltp-task-list">
                                    <?php foreach ($tasks as $tk) ltp_render_task($tk); ?>
                                </ul>
                            </div>
                        </div>
                        <div class="ltp-card-foot">
                            <button type="button" class="ltp-btn ltp-export-plan"
                                    title="Xuất kế hoạch ngày này sang trang BC KHSX cho nhân viên">
                                <i class="fa-solid fa-paper-plane"></i> XUẤT KH
                            </button>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>

            <!-- Khối dưới: Đã hoàn thành (trái) + Kế hoạch SX dự phòng (phải) -->
            <div class="ltp-completed" id="ltp-completed">
                <div class="ltp-completed-main">
                    <div class="ltp-completed-head">
                        <button type="button" class="ltp-completed-toggle">
                            <i class="fa-solid fa-chevron-right"></i>
                            Đã hoàn thành (<span class="ltp-completed-count"><?php echo (int) $completed['count']; ?></span>)
                        </button>
                        <!-- TASK 5: reset toàn bộ danh sách đã hoàn thành -->
                        <button type="button" class="ltp-btn ltp-completed-clear" id="ltp-completed-clear"
                                title="Xóa toàn bộ danh sách đã hoàn thành">
                            <i class="fa-solid fa-trash"></i> Xóa
                        </button>
                    </div>
                    <div class="ltp-completed-body" hidden>
                        <?php if (empty($completed['days'])): ?>
                            <p class="ltp-empty">Chưa có ngày nào hoàn thành.</p>
                        <?php else: foreach ($completed['days'] as $cd): ?>
                            <div class="ltp-completed-day">
                                <div class="ltp-completed-date">
                                    <?php echo htmlspecialchars($cd['weekday'] . ' (' . $cd['display'] . ')', ENT_QUOTES, 'UTF-8'); ?>
                                </div>
                                <ul class="ltp-completed-list">
                                    <?php foreach ($cd['items'] as $it):
                                        $nm = htmlspecialchars((string) ($it['product_name'] ?: ('#' . (int) $it['product_id'])), ENT_QUOTES, 'UTF-8');
                                        $q  = rtrim(rtrim(number_format((float) $it['quantity'], 2, '.', ''), '0'), '.');
                                    ?>
                                        <li><i class="fa-solid fa-check"></i> <?php echo $nm; ?>: <?php echo htmlspecialchars($q, ENT_QUOTES, 'UTF-8'); ?></li>
                                    <?php endforeach; ?>
                                    <?php foreach ($cd['tasks'] as $tk): ?>
                                        <li class="is-task"><i class="fa-solid fa-check"></i> <?php echo htmlspecialchars((string) $tk['content'], ENT_QUOTES, 'UTF-8'); ?></li>
                                    <?php endforeach; ?>
                                </ul>
                            </div>
                        <?php endforeach; endif; ?>
                    </div>
                </div>

                <!-- Kế hoạch sản xuất dự phòng -->
                <div class="ltp-backup" id="ltp-backup">
                    <div class="ltp-backup-head">
                        <span class="ltp-backup-title"><i class="fa-solid fa-box-archive"></i>
                            <span class="ltp-bk-full">Kế hoạch sản xuất dự phòng</span>
                            <span class="ltp-bk-short">KHSX Dự phòng</span>
                        </span>
                        <div class="ltp-backup-actions">
                            <button type="button" class="ltp-btn ltp-backup-add" id="ltp-backup-add">+ Sản phẩm</button>
                            <div class="ltp-backup-setting-wrap">
                                <button type="button" class="ltp-btn ltp-backup-setting-btn" id="ltp-backup-setting-btn"
                                        title="Cài đặt thời gian tự xóa"><i class="fa-solid fa-gear"></i></button>
                                <div class="ltp-backup-setting-menu" id="ltp-backup-setting-menu">
                                    <div class="ltp-backup-setting-label">Tự động xóa sau (tính từ ngày tạo)</div>
                                    <?php foreach (['1w' => '1 tuần', '2w' => '2 tuần', '1m' => '1 tháng'] as $code => $lbl): ?>
                                        <label class="ltp-backup-setting-opt">
                                            <input type="radio" name="ltp-bk-ttl" value="<?php echo $code; ?>" <?php echo $backup_ttl === $code ? 'checked' : ''; ?>>
                                            <span><?php echo $lbl; ?></span>
                                        </label>
                                    <?php endforeach; ?>
                                </div>
                            </div>
                        </div>
                    </div>
                    <ul class="ltp-list ltp-backup-list" id="ltp-backup-list">
                        <?php foreach ($backup_items as $b) ltp_render_backup_item($b); ?>
                    </ul>
                    <p class="ltp-backup-empty"<?php echo empty($backup_items) ? '' : ' hidden'; ?>>Chưa có sản phẩm dự phòng.</p>
                </div>
            </div>
        </div>
    </div>

    <!-- Modal: thêm sản phẩm -->
    <div class="ltp-modal-mask" id="ltp-modal-product">
        <div class="ltp-modal-box">
            <div class="ltp-modal-head">
                <h3>Thêm sản phẩm — <span class="ltp-modal-date"></span></h3>
                <span class="ltp-modal-close" data-close="ltp-modal-product">&times;</span>
            </div>
            <div class="ltp-modal-body">
                <div class="ltp-search-wrap">
                    <input type="text" id="ltp-product-search" autocomplete="off" placeholder="Tìm sản phẩm theo tên...">
                    <ul class="ltp-suggest" id="ltp-product-suggest"></ul>
                </div>
                <div class="ltp-staged" id="ltp-staged">
                    <div class="ltp-staged-empty">Chưa chọn sản phẩm nào.</div>
                </div>
            </div>
            <div class="ltp-modal-foot">
                <button type="button" class="ltp-btn ltp-btn-primary" id="ltp-add-products-confirm">Thêm</button>
            </div>
        </div>
    </div>

    <!-- Modal: việc khác -->
    <div class="ltp-modal-mask" id="ltp-modal-task">
        <div class="ltp-modal-box">
            <div class="ltp-modal-head">
                <h3>Việc khác — <span class="ltp-modal-date"></span></h3>
                <span class="ltp-modal-close" data-close="ltp-modal-task">&times;</span>
            </div>
            <div class="ltp-modal-body">
                <textarea id="ltp-task-content" rows="4" placeholder="Nhập nội dung công việc..."></textarea>
            </div>
            <div class="ltp-modal-foot">
                <button type="button" class="ltp-btn ltp-btn-primary" id="ltp-add-task-confirm">Thêm</button>
            </div>
        </div>
    </div>

    <!-- MOBILE: modal xem công việc + việc khác của 1 ngày (Task 7) -->
    <div class="app-modal" id="ltp-day-modal" aria-hidden="true">
        <div class="app-modal-overlay" data-ltp-day-close></div>
        <div class="app-modal-box">
            <div class="app-modal-head">
                <h3 id="ltp-day-modal-title"></h3>
                <button type="button" class="app-modal-close" data-ltp-day-close aria-label="Đóng">&times;</button>
            </div>
            <div class="app-modal-body" id="ltp-day-modal-body"></div>
        </div>
    </div>

    <script>
        window.LTP_CONFIG = { baseUrl: '?mod=production_staff&controllers=production_staff&action=' };
    </script>
    <script src="<?php echo asset_ver('public/js/production_staff/long_term_production_plan.js'); ?>"></script>
    <script src="<?php echo asset_ver('public/js/shared/app_shell.js'); ?>"></script>
</body>

</html>
