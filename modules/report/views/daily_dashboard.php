<?php defined('APPPATH') OR exit('Không được quyền truy cập phần này'); ?>
<?php
$data = isset($data) && is_array($data) ? $data : [];

$d_output   = $data['output']          ?? ['months' => [], 'current' => 0, 'current_label' => '', 'efficiency' => ['avg_per_cong' => 0, 'total_cong' => 0, 'max_setting' => 150, 'value' => 0], 'regulation' => ['state' => 'on_dinh', 'label' => 'Ổn định', 'a' => 0, 'b' => 0], 'key_products' => []];
$d_imports  = $data['imports']         ?? ['summary' => ['count' => 0, 'inventory_value' => 0, 'purchase_cost' => 0, 'month_label' => ''], 'recent' => ['rows' => [], 'page' => 1, 'total_pages' => 1]];
$d_exports  = $data['exports']         ?? ['summary' => ['value' => 0, 'quantity' => 0, 'month_label' => ''], 'series' => ['months' => [], 'current' => 0, 'current_ym' => ''], 'series_qty' => ['months' => [], 'current' => 0, 'current_ym' => ''], 'axis' => ['min' => 0, 'step' => 0], 'today_by_customer' => []];
$d_prodDay  = $data['production_day']  ?? ['date' => date('Y-m-d'), 'label' => 'Sản xuất hôm nay', 'rows' => []];
$d_material = $data['material_orders'] ?? ['rows' => [], 'page' => 1, 'total_pages' => 1];
$d_branch   = $data['branch_orders']   ?? [];
$d_fund     = $data['fund']            ?? ['balance' => 0, 'recent' => ['rows' => [], 'page' => 1, 'total_pages' => 1]];
$d_attendance = $data['attendance_today'] ?? [];
$d_stockWatch = $data['stock_watch']   ?? ['products' => [], 'materials' => [], 'window' => 90, 'cover' => 14];
$d_priceChg   = $data['price_changes'] ?? ['rows' => [], 'page' => 1, 'total_pages' => 1, 'total' => 0];

/** Số nguyên có dấu phẩy ngăn cách ngàn (bỏ phần thập phân .00 nếu chẵn). */
function dd2_num($v)
{
    $v = (float) $v;
    if (abs($v - round($v)) < 1e-9) return number_format(round($v), 0, '.', ',');
    return rtrim(rtrim(number_format($v, 2, '.', ','), '0'), '.');
}
function dd2_money($v) { return dd2_num($v) . ' đ'; }
/** Số thô không dấu phẩy — dùng cho value="" của input[type=number] (dd2_num có dấu phẩy, trình duyệt sẽ coi là giá trị không hợp lệ và bỏ trống). */
function dd2_num_raw($v) { return str_replace(',', '', dd2_num($v)); }
/** Quy đổi đơn vị triệu, 2 chữ số thập phân, dấu phẩy thập phân kiểu VN. VD: 94030000 -> "94,03 tr". */
function dd2_money_million($v) { return number_format(((float) $v) / 1000000, 2, ',', '.') . ' tr'; }
function dd2_esc($s) { return htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8'); }
/** Hex -> rgba() string. Dùng thay CSS color-mix()/color() vì html2canvas (chụp báo cáo) không parse được các hàm màu mới. */
function dd2_hex_to_rgba($hex, $alpha)
{
    $hex = ltrim((string) $hex, '#');
    if (strlen($hex) === 3) $hex = $hex[0] . $hex[0] . $hex[1] . $hex[1] . $hex[2] . $hex[2];
    if (strlen($hex) !== 6 || !ctype_xdigit($hex)) $hex = '16a34a';
    $r = hexdec(substr($hex, 0, 2));
    $g = hexdec(substr($hex, 2, 2));
    $b = hexdec(substr($hex, 4, 2));
    return 'rgba(' . $r . ',' . $g . ',' . $b . ',' . $alpha . ')';
}
/** Nhãn thời gian đặt hàng cho card "Chi nhánh đặt hàng" (2026-07-17): Hôm nay/Hôm qua/N ngày
 *  trước (2-6)/tuần trước (7+, mirror format_time_ago_vi() nhưng KHÔNG kèm giờ vì card chật).
 *  Màu: hôm nay=xanh lá, 1-3 ngày=xanh dương, từ 4 ngày trở lên (kể cả tuần trước)=đỏ. */
function dd2_branch_age($created_at)
{
    $days = (int) floor((strtotime(date('Y-m-d')) - strtotime(date('Y-m-d', strtotime($created_at)))) / 86400);
    if ($days <= 0) return ['text' => 'Hôm nay', 'color' => 'today'];
    if ($days === 1) return ['text' => 'Hôm qua', 'color' => 'blue'];
    if ($days <= 3) return ['text' => $days . ' ngày trước', 'color' => 'blue'];
    if ($days <= 6) return ['text' => $days . ' ngày trước', 'color' => 'danger'];
    if ($days <= 13) return ['text' => 'Tuần trước', 'color' => 'danger'];
    return ['text' => (int) floor($days / 7) . ' tuần trước', 'color' => 'danger'];
}

/* ---- Khối "Theo dõi tồn kho": pin dung lượng 10 vạch (xem rp_dd_sw_metrics()) ----
   >50% xanh / 25-50% vàng / <25% đỏ; vạch chưa lấp đầy để xám. */
function dd2_battery($m)
{
    $on  = (int) ($m['segments'] ?? 0);
    $tip = 'Đã dùng ' . RP_DD_SW_WINDOW_DAYS . ' ngày: ' . dd2_num($m['used'])
         . ' · TB/ngày: ' . dd2_num(round($m['avg_day'], 1))
         . ' · Đủ dùng ' . rp_dd_sw_cover_days() . ' ngày cần: ' . dd2_num(round($m['target']))
         . ' · Đang có: ' . dd2_num($m['percent']) . '%';
    $h = '<span class="dd2-bat dd2-bat-' . dd2_esc($m['level']) . '" title="' . dd2_esc($tip) . '">';
    $h .= '<span class="dd2-bat-body">';
    for ($i = 1; $i <= 10; $i++) $h .= '<i class="dd2-bat-seg' . ($i <= $on ? ' is-on' : '') . '"></i>';
    $h .= '</span><span class="dd2-bat-cap"></span></span>';
    return $h;
}

/** 1 ô "{pin} {tên} / {tồn kho hiện tại}" của khối Theo dõi tồn kho (tên trên, tồn dưới).
 *  Bấm vào ô -> modal chi tiết; hover vào tên -> hiện nút mắt-gạch để ẩn khỏi khối. */
function dd2_stock_watch_row($m, $kind)
{
    $tip = $kind === 'material' ? 'Xem sản phẩm đang dùng nguyên liệu này' : 'Xem nguyên vật liệu của sản phẩm này';
    return '<div class="dd2-sw-row" data-kind="' . dd2_esc($kind) . '" data-id="' . (int) $m['id'] . '" title="' . $tip . '">'
        . dd2_battery($m)
        . '<span class="dd2-sw-main">'
        . '<span class="dd2-sw-name-row">'
        . '<span class="dd2-sw-name" title="' . dd2_esc($m['name']) . '">' . dd2_esc($m['name']) . '</span>'
        . '<button type="button" class="dd2-sw-hide" title="Ẩn khỏi khối (không xét nữa)"><i class="fa-solid fa-eye-slash"></i></button>'
        . '</span>'
        . '<span class="dd2-sw-stock dd2-sw-' . dd2_esc($m['level']) . '">' . dd2_num($m['stock'])
        . ($m['unit'] !== '' ? ' ' . dd2_esc($m['unit']) : '') . '</span>'
        . '</span></div>';
}

$MIN_PROD_ROWS = 5;
$PROD_PAGE_SIZE = 5;
$SW_MIN_ROWS   = 4;   // đệm cho 2 cột "Theo dõi tồn kho" luôn cao bằng nhau
$today_label = strtolower(rp_dd_month_short((int) date('n')));
$page_date_label = date('d') . ' ' . $today_label . ', ' . date('Y');

$eff = $d_output['efficiency'];
$reg = $d_output['regulation'];

// Khối "Chấm công" (dd2-page-head): text rút gọn tối đa 3 nhân sự, quá 3 thêm "...".
$attCount = count($d_attendance);
$attShown = array_slice($d_attendance, 0, 3);
$attParts = [];
foreach ($attShown as $a) { $attParts[] = dd2_esc($a['full_name']) . ' - ' . dd2_esc($a['reason']); }
$attText = implode('; ', $attParts) . ($attCount > 3 ? '...' : '');

// Giá trị tạm cho Xuất kho (metric='export'/'export_qty') — mirror rp_dd_output_block()['override_months'],
// tính thẳng ở view vì chỉ dùng riêng cho modal "Thiết lập giá trị tạm (Xuất kho)" (2 tab: Doanh thu/Số lượng).
$exportOverridesMap = rp_dd_get_month_overrides('export');
$exportOverrideMonths = $d_exports['series']['months'];
$exportOverrideMonths[] = ['ym' => $d_exports['series']['current_ym'], 'label' => strtoupper($today_label), 'value' => $d_exports['series']['current']];

$exportQtyOverridesMap = rp_dd_get_month_overrides('export_qty');
$exportQtyOverrideMonths = $d_exports['series_qty']['months'];
$exportQtyOverrideMonths[] = ['ym' => $d_exports['series_qty']['current_ym'], 'label' => strtoupper($today_label), 'value' => $d_exports['series_qty']['current']];
?>
<!DOCTYPE html>
<html lang="vi">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>BC sản xuất hằng ngày</title>
    <link rel="icon" href="public/images/logo/logo_vat_png.png" type="image/png">
    <link rel="stylesheet" href="<?php echo asset_ver('public/css/reset.css'); ?>">
    <link rel="stylesheet" href="<?php echo asset_ver('public/css/all.css'); ?>">
    <link rel="stylesheet" href="<?php echo asset_ver('public/css/global.css'); ?>">
    <link rel="stylesheet" href="<?php echo asset_ver('public/css/shared/app_shell.css'); ?>">
    <link rel="stylesheet" href="<?php echo asset_ver('public/css/shared/invoice_upload.css'); ?>">
    <link rel="stylesheet" href="<?php echo asset_ver('public/css/report/daily_dashboard.css'); ?>">
</head>

<body>
    <div id="wrapper">
        <?php get_sidebar('app'); ?>
        <div class="content dd2-content">
            <?php get_header('app'); ?>

            <div class="dd2-body">
                <!-- SIDEBAR ICON riêng của dashboard: 3 nhóm nền trắng bo góc (2026-07-15: gộp nhóm
                     Nhập/Xuất kho + Sản lượng/Sản xuất thành 1 nhóm 4 icon chung background). -->
                <div class="dd2-sidebar">
                    <div class="dd2-sb-group">
                        <button type="button" class="dd2-sb-icon" data-dd2-side="imports" title="Nhập kho — xem đầy đủ"><i class="fa-solid fa-truck-ramp-box"></i></button>
                        <button type="button" class="dd2-sb-icon" data-dd2-side="exports" title="Xuất kho — xem đầy đủ"><i class="fa-solid fa-truck-fast"></i></button>
                        <button type="button" class="dd2-sb-icon" data-dd2-side="output" title="Sản lượng — xem đầy đủ"><i class="fa-solid fa-chart-column"></i></button>
                        <button type="button" class="dd2-sb-icon" data-dd2-side="production" title="Sản xuất — xem đầy đủ"><i class="fa-solid fa-industry"></i></button>
                    </div>
                    <div class="dd2-sb-group">
                        <button type="button" class="dd2-sb-icon" data-dd2-side="fund" title="Quỹ — xem đầy đủ"><i class="fa-solid fa-wallet"></i></button>
                        <button type="button" class="dd2-sb-icon" data-dd2-side="material_orders" title="Đặt hàng nguyên liệu — xem đầy đủ"><i class="fa-solid fa-boxes-stacked"></i></button>
                        <button type="button" class="dd2-sb-icon" data-dd2-side="branch" title="Chi nhánh đặt hàng — xem đầy đủ"><i class="fa-solid fa-store"></i></button>
                    </div>
                    <div class="dd2-sb-group dd2-sb-group-bottom">
                        <button type="button" class="dd2-sb-icon" id="dd2-capture-btn" title="Chụp báo cáo (Ctrl+V để dán)"><i class="fa-solid fa-camera"></i></button>
                        <button type="button" class="dd2-sb-icon" id="dd2-settings-btn" title="Cài đặt chỉ số hiệu quả"><i class="fa-solid fa-gear"></i></button>
                    </div>
                </div>

                <div class="dd2-main">
                    <div class="dd2-page-head">
                        <?php if (!empty($d_attendance)): ?>
                            <button type="button" class="dd2-attendance-wrap" id="dd2-attendance-btn" title="Xem danh sách nhân sự vắng hôm nay">
                                <i class="fa-solid fa-user-clock"></i>
                                <span class="dd2-attendance-text">Vắng (<?php echo $attCount; ?>): <?php echo $attText; ?></span>
                            </button>
                        <?php endif; ?>
                        <div class="dd2-page-date-wrap">
                            <i class="fa-solid fa-calendar-days"></i>
                            <span class="dd2-page-date"><b class="dd2-pd-brand">Production Report</b> <?php echo dd2_esc($page_date_label); ?></span>
                        </div>
                    </div>

                    <!-- HÀNG TRÊN -->
                    <div class="dd2-row">

                        <!-- 1. NHẬP KHO -->
                        <div class="dd2-card dd2-col-import">
                            <div class="dd2-card-head">
                                <p class="dd2-title"><i class="fa-solid fa-truck-ramp-box"></i> Nhập kho <span class="dd2-month-label" id="dd2-import-month-label"><?php echo dd2_esc($d_imports['summary']['month_label']); ?></span></p>
                                <button type="button" class="dd2-icon-btn" id="dd2-import-supplier-btn" title="Chọn nhà cung cấp">
                                    <i class="fa-solid fa-truck"></i>
                                </button>
                            </div>
                            <div class="dd2-picker-pop" id="dd2-supplier-picker" hidden>
                                <div class="app-remind-picker">
                                    <input type="text" class="app-remind-search" id="dd2-supplier-search" placeholder="Tìm nhà cung cấp...">
                                    <div class="app-remind-suggest" id="dd2-supplier-suggest"></div>
                                </div>
                                <button type="button" class="dd2-btn-ghost" id="dd2-supplier-clear">Bỏ lọc (xem tất cả)</button>
                            </div>
                            <div class="dd2-import-stats">
                                <div class="dd2-import-stats-top">
                                    <button type="button" class="dd2-stat-link" id="dd2-import-count-btn"><span id="dd2-import-count"><?php echo (int) $d_imports['summary']['count']; ?></span> hóa đơn</button>
                                    <button type="button" class="dd2-import-more-btn" id="dd2-import-more-btn" title="Xem đầy đủ"><i class="fa-solid fa-ellipsis"></i></button>
                                </div>
                                <div class="dd2-import-main" id="dd2-import-value"><?php echo dd2_money($d_imports['summary']['inventory_value']); ?></div>
                                <button type="button" class="dd2-stat-link" id="dd2-import-cpmh-btn">CPMH: <span id="dd2-import-cpmh"><?php echo dd2_money($d_imports['summary']['purchase_cost']); ?></span></button>
                            </div>
                            <!-- <div class="dd2-list-head dd2-import-list-head"><span>Nhà cung cấp</span><span>GTNK</span><span>CPMH</span></div> -->
                            <div class="dd2-list" id="dd2-imports-list">
                                <?php if (empty($d_imports['recent']['rows'])): ?>
                                    <p class="dd2-empty">Chưa có hóa đơn nhập kho.</p>
                                <?php else: foreach ($d_imports['recent']['rows'] as $r): ?>
                                    <div class="dd2-import-row">
                                        <div class="dd2-import-row-top">
                                            <div class="dd2-name-cell">
                                                <span class="dd2-row-bullet <?php echo $r['date_iso'] === date('Y-m-d') ? 'dd2-color-today' : 'dd2-color-gray'; ?>"></span>
                                                <button type="button" class="dd2-entity-link" data-supplier-id="<?php echo (int) $r['supplier_id']; ?>" data-date-iso="<?php echo dd2_esc($r['date_iso']); ?>">
                                                    <?php echo dd2_esc($r['supplier_label']); ?>
                                                </button>
                                            </div>
                                            <span class="dd2-value" data-items="<?php echo dd2_esc(json_encode($r['items'] ?? [], JSON_UNESCAPED_UNICODE)); ?>" data-total="<?php echo dd2_esc(dd2_money($r['inventory_value'])); ?>"><?php echo dd2_money($r['inventory_value']); ?></span>
                                        </div>
                                        <span class="dd2-date-chip<?php echo $r['date_iso'] === date('Y-m-d') ? ' is-today' : ''; ?>"><?php echo dd2_esc($r['date_label']); ?><?php if ($r['purchase_cost'] > 0): ?> - <span class="dd2-cost is-warn">CPMH: <?php echo dd2_money($r['purchase_cost']); ?></span><?php endif; ?></span>
                                    </div>
                                <?php endforeach; endif; ?>
                            </div>
                            <div class="dd2-pagination" data-dd2-pager="imports" data-page="<?php echo (int) $d_imports['recent']['page']; ?>" data-total-pages="<?php echo (int) $d_imports['recent']['total_pages']; ?>"></div>
                        </div>

                        <!-- 2. SẢN LƯỢNG -->
                        <div class="dd2-card dd2-col-output">
                            <div class="dd2-card-head">
                                <p class="dd2-title"><i class="fa-solid fa-chart-column"></i> Sản lượng</p>
                            </div>
                            <div class="dd2-output-value">
                                <span class="dd2-output-num" id="dd2-output-current"><?php echo dd2_num($d_output['current']); ?></span>
                                <span class="dd2-output-label" id="dd2-output-current-label"><?php echo dd2_esc($d_output['current_label']); ?></span>
                            </div>
                            <div class="dd2-chart-box"><canvas id="dd2-chart-output"></canvas></div>
                            <div class="dd2-output-stats-line">
                                <span class="dd2-stat-inline">Hiệu quả: <b id="dd2-efficiency-value"><?php echo number_format((float) $eff['value'], 1); ?></b></span>
                                <span class="dd2-stat-inline">Điều tiết: <b id="dd2-regulation-value" class="dd2-state-<?php echo dd2_esc($reg['state']); ?>"><?php echo dd2_esc($reg['label']); ?> (<?php echo number_format((float) $reg['a'], 1); ?>)</b></span>
                            </div>
                        </div>

                        <!-- 3. XUẤT KHO -->
                        <div class="dd2-card dd2-col-export">
                            <div class="dd2-card-head">
                                <p class="dd2-title"><i class="fa-solid fa-truck-fast"></i> Xuất kho <span class="dd2-month-label" id="dd2-export-month-label"><?php echo dd2_esc($d_exports['summary']['month_label']); ?></span></p>
                                <button type="button" class="dd2-icon-btn" id="dd2-export-settings-btn" title="Thiết lập giá trị tạm">
                                    <i class="fa-solid fa-sliders"></i>
                                </button>
                                <button type="button" class="dd2-icon-btn" id="dd2-export-customer-btn" title="Chọn khách hàng">
                                    <i class="fa-solid fa-user-tag"></i>
                                </button>
                            </div>
                            <div class="dd2-picker-pop" id="dd2-customer-picker" hidden>
                                <div class="app-remind-picker">
                                    <input type="text" class="app-remind-search" id="dd2-customer-search" placeholder="Tìm khách hàng...">
                                    <div class="app-remind-suggest" id="dd2-customer-suggest"></div>
                                </div>
                                <button type="button" class="dd2-btn-ghost" id="dd2-customer-clear">Bỏ lọc (xem tất cả)</button>
                            </div>
                            <div class="dd2-import-stats">
                                <div class="dd2-import-stats-top">
                                    <span class="dd2-stat-link" id="dd2-export-count-line"><?php echo (int) $d_exports['summary']['count']; ?> đơn hàng · <?php echo dd2_num($d_exports['summary']['quantity']); ?> SP</span>
                                </div>
                                <div class="dd2-import-main" id="dd2-export-total-value"><?php echo dd2_money($d_exports['summary']['value']); ?></div>
                            </div>
                            <div class="dd2-chart-box dd2-chart-line">
                                <canvas id="dd2-chart-export"></canvas>
                                <div class="dd2-export-today" id="dd2-export-today" hidden></div>
                                <button type="button" class="dd2-chart-cta" id="dd2-export-sales-order-btn">Sales Order</button>
                            </div>
                        </div>
                    </div>

                    <!-- HÀNG DƯỚI -->
                    <div class="dd2-row">

                        <!-- 4. SẢN XUẤT (điều hướng ngày) -->
                        <?php $dd2_prod_count = count($d_prodDay['rows'] ?? []); ?>
                        <div class="dd2-card dd2-col-production">
                            <div class="dd2-card-head">
                                <p class="dd2-title"><i class="fa-solid fa-industry"></i> <span id="dd2-production-title-text"><?php echo dd2_esc($d_prodDay['label']); ?></span><span class="dd2-title-count" id="dd2-production-count"<?php echo $dd2_prod_count > 5 ? '' : ' hidden'; ?>><?php echo $dd2_prod_count > 5 ? '/' . dd2_num($dd2_prod_count) . ' sản phẩm' : ''; ?></span></p>
                                <div class="dd2-day-nav">
                                    <button type="button" class="dd2-day-nav-btn" id="dd2-day-prev" title="Ngày trước"><i class="fa-solid fa-chevron-left"></i></button>
                                    <button type="button" class="dd2-day-nav-btn" id="dd2-day-next" title="Ngày sau" disabled><i class="fa-solid fa-chevron-right"></i></button>
                                </div>
                            </div>
                            <div class="dd2-prod-table-wrap">
                                <table class="dd2-prod-table">
                                    <thead><tr><th>Tên sản phẩm</th><th>SL</th><th>GVSX</th></tr></thead>
                                    <tbody id="dd2-products-body">
                                        <?php
                                        $prodRows = $d_prodDay['rows'] ?? [];
                                        $sumQty = 0.0; $sumCost = 0.0;
                                        foreach ($prodRows as $pr) { $sumQty += (float) $pr['quantity']; $sumCost += (float) $pr['cost']; }
                                        $prodPageRows = array_slice($prodRows, 0, $PROD_PAGE_SIZE);
                                        $rowCount = max($MIN_PROD_ROWS, count($prodPageRows));
                                        for ($i = 0; $i < $rowCount; $i++):
                                            $p = $prodPageRows[$i] ?? null;
                                        ?>
                                            <?php if ($p): ?>
                                                <tr class="dd2-prod-row" data-product-id="<?php echo (int) $p['product_id']; ?>">
                                                    <td class="dd2-prod-name">
                                                        <div class="dd2-prod-name-inner">
                                                            <?php if (!empty($p['image_url'])): ?>
                                                                <img class="dd2-prod-img" src="public/images/<?php echo dd2_esc($p['image_url']); ?>" alt="">
                                                            <?php else: ?>
                                                                <span class="dd2-prod-img dd2-prod-img-empty"><i class="fa-solid fa-box"></i></span>
                                                            <?php endif; ?>
                                                            <span class="dd2-prod-name-text"><?php echo dd2_esc($p['name']); ?></span>
                                                        </div>
                                                    </td>
                                                    <td><?php echo dd2_num($p['quantity']); ?></td>
                                                    <td class="dd2-prod-cost" data-product-id="<?php echo (int) $p['product_id']; ?>">
                                                        <?php echo dd2_money($p['cost']); ?>
                                                        <span class="dd2-warn-dot <?php echo dd2_esc($p['warn_class']); ?>"></span>
                                                    </td>
                                                </tr>
                                            <?php else: ?>
                                                <tr class="dd2-prod-row is-empty">
                                                    <td colspan="3"></td>
                                                </tr>
                                            <?php endif; ?>
                                        <?php endfor; ?>
                                    </tbody>
                                    <tfoot>
                                        <tr>
                                            <td>Tổng:</td>
                                            <td><?php echo dd2_num($sumQty); ?></td>
                                            <td><?php echo dd2_money($sumCost); ?></td>
                                        </tr>
                                    </tfoot>
                                </table>
                                <div class="dd2-pagination" data-dd2-pager="production" data-page="1" data-total-pages="<?php echo max(1, (int) ceil(count($prodRows) / $PROD_PAGE_SIZE)); ?>"></div>
                            </div>
                        </div>

                        <!-- 5. ĐẶT HÀNG NGUYÊN LIỆU -->
                        <?php $dd2_mo_count = (int) ($d_material['total'] ?? 0); ?>
                        <div class="dd2-card dd2-col-material-order">
                            <div class="dd2-card-head"><p class="dd2-title"><i class="fa-solid fa-boxes-stacked"></i> Đặt hàng nguyên liệu<span class="dd2-title-count" id="dd2-material-count"<?php echo $dd2_mo_count > 5 ? '' : ' hidden'; ?>><?php echo $dd2_mo_count > 5 ? '/' . dd2_num($dd2_mo_count) . ' đơn hàng' : ''; ?></span></p></div>
                            <div class="dd2-list" id="dd2-material-orders-list">
                                <?php if (empty($d_material['rows'])): ?>
                                    <p class="dd2-empty">Không có đơn nào đang chờ.</p>
                                <?php else: foreach ($d_material['rows'] as $o):
                                    $moReceived = !empty($o['received']);
                                    $moMatch    = $o['value_match'] ?? null; // true=khớp / false=lệch / null=chưa nhận hoặc không có snapshot để so
                                ?>
                                    <div class="dd2-mo-row<?php echo $moReceived ? ' is-received' : ''; ?>">
                                        <span class="dd2-mo-bullet dd2-color-<?php echo dd2_esc($o['age']['color']); ?><?php echo $moMatch === true ? ' is-match' : ''; ?>"></span>
                                        <div class="dd2-mo-main">
                                            <div class="dd2-mo-top">
                                                <b class="dd2-mo-supplier<?php echo $moMatch === true ? ' is-match' : ''; ?>"><?php echo dd2_esc($o['supplier_name']); ?></b>
                                                <span class="dd2-mo-age dd2-color-<?php echo dd2_esc($o['age']['color']); ?>">/ <?php echo dd2_esc($o['age']['text']); ?></span>
                                                <span class="dd2-mo-value-wrap">
                                                    <span class="dd2-mo-item-count<?php echo $moMatch === true ? ' is-match' : ''; ?>" data-price-breakdown="<?php echo dd2_esc(json_encode($o['price_breakdown'] ?? [], JSON_UNESCAPED_UNICODE)); ?>">
                                                        <?php if ($moMatch === false): ?>
                                                            <span class="dd2-mo-value-new"><?php echo dd2_money($o['actual_value']); ?></span>
                                                            <span class="dd2-mo-value-old"><?php echo dd2_money($o['expected_value']); ?></span>
                                                        <?php else: ?>
                                                            <?php echo dd2_money($moMatch === true ? $o['actual_value'] : $o['expected_value']); ?>
                                                        <?php endif; ?>
                                                    </span>
                                                </span>
                                            </div>
                                            <div class="dd2-mo-sub" title="<?php echo dd2_esc($o['items_description']); ?>"><?php echo dd2_esc($o['items_description']); ?></div>
                                        </div>
                                        <?php if ($moReceived): ?>
                                            <span class="dd2-mo-stamp">Đã nhận</span>
                                        <?php endif; ?>
                                    </div>
                                <?php endforeach; endif; ?>
                            </div>
                            <div class="dd2-pagination" data-dd2-pager="material_orders" data-page="<?php echo (int) $d_material['page']; ?>" data-total-pages="<?php echo (int) $d_material['total_pages']; ?>"></div>
                        </div>

                        <!-- 6. CHI NHÁNH ĐẶT HÀNG + QUỸ -->
                        <div class="dd2-col-group">
                            <div class="dd2-card dd2-sub-card">
                                <div class="dd2-card-head"><p class="dd2-title"><i class="fa-solid fa-store"></i> Chi nhánh đặt hàng</p></div>
                                <div class="dd2-list dd2-branch-grid" id="dd2-branch-orders-list">
                                    <?php if (empty($d_branch)): ?>
                                        <p class="dd2-empty">Chưa có đơn hàng nào từ chi nhánh.</p>
                                    <?php else: foreach ($d_branch as $o):
                                        $color = !empty($o['secondary_color']) ? $o['secondary_color'] : '#16a34a';
                                        $custLabel = trim((string) ($o['cust_short'] ?? '')) !== '' ? $o['cust_short'] : ($o['customer_name'] ?: 'Khách lẻ');
                                        $pct = (float) ($o['fulfill_percent'] ?? 100);
                                        $pctColor = $pct >= 95 ? 'today' : ($pct >= 90 ? 'warn' : 'danger');
                                        $age = dd2_branch_age($o['created_at']);
                                        // Danh sách ĐẦY ĐỦ sản phẩm của đơn (không chỉ dòng thiếu tồn) — hiển thị bên dưới
                                        // list thiếu trong modal chi tiết (click card), xem wireBranchCards() ở JS.
                                        $fullItems = array_map(function ($it) {
                                            return [
                                                'name'     => (string) ($it['product_name'] ?? ''),
                                                'qty'      => (float) ($it['qt_order'] ?? 0),
                                                'unit'     => (string) ($it['unit'] ?? ''),
                                                'stock'    => (float) ($it['stock'] ?? 0),
                                                'value'    => (float) ($it['line_value'] ?? 0),
                                                'is_short' => !empty($it['is_short']),
                                            ];
                                        }, $o['items'] ?? []);
                                    ?>
                                        <div class="dd2-branch-card" data-order-id="<?php echo (int) $o['id']; ?>" data-shortage="<?php echo dd2_esc(json_encode($o['shortage_lines'] ?? [], JSON_UNESCAPED_UNICODE)); ?>" data-items="<?php echo dd2_esc(json_encode($fullItems, JSON_UNESCAPED_UNICODE)); ?>" style="--dd2-accent: <?php echo dd2_esc($color); ?>; --dd2-accent-glow: <?php echo dd2_esc(dd2_hex_to_rgba($color, 0.22)); ?>; --dd2-accent-soft: <?php echo dd2_esc(dd2_hex_to_rgba($color, 0.1)); ?>;">
                                            <div class="dd2-branch-line1">
                                                <span class="dd2-branch-name-group">
                                                    <span class="dd2-branch-bullet"></span>
                                                    <b class="dd2-branch-name"><?php echo dd2_esc($custLabel); ?>:</b>
                                                </span>
                                                <span class="dd2-branch-stats-group">
                                                    <span class="dd2-branch-weight"><?php echo dd2_num(round($o['stats']['weight_total'] ?? 0)); ?>kg</span>
                                                    <span class="dd2-branch-sep">-</span>
                                                    <span class="dd2-branch-value"><?php echo dd2_money($o['stats']['value_total'] ?? 0); ?></span>
                                                </span>
                                            </div>
                                            <div class="dd2-branch-line2">
                                                <span class="dd2-branch-age dd2-color-<?php echo $age['color']; ?>"><?php echo dd2_esc($age['text']); ?></span>
                                                <span class="dd2-branch-fulfill dd2-color-<?php echo $pctColor; ?>">Đang có: <?php echo number_format($pct, 0); ?>%</span>
                                            </div>
                                        </div>
                                    <?php endforeach; endif; ?>
                                </div>
                            </div>

                            <div class="dd2-card dd2-sub-card">
                                <div class="dd2-card-head">
                                    <p class="dd2-title"><i class="fa-solid fa-wallet"></i> Quỹ</p>
                                    <b class="dd2-fund-balance-inline"><?php echo dd2_money($d_fund['balance']); ?></b>
                                    <button type="button" class="dd2-icon-btn" id="dd2-fund-opening-btn" title="Cập nhật tồn đầu quỹ">
                                        <i class="fa-solid fa-coins"></i>
                                    </button>
                                </div>
                                <div class="dd2-list" id="dd2-fund-recent-list">
                                    <?php if (empty($d_fund['recent']['rows'])): ?>
                                        <p class="dd2-empty">Chưa có giao dịch.</p>
                                    <?php else: foreach ($d_fund['recent']['rows'] as $tx):
                                        $fundIsToday = $tx['date_iso'] === date('Y-m-d');
                                    ?>
                                        <div class="dd2-fund-row <?php echo $tx['is_income'] ? 'is-income' : ''; ?>">
                                            <span class="dd2-row-bullet <?php echo $fundIsToday ? 'dd2-color-today' : 'dd2-color-gray'; ?>"></span>
                                            <span class="dd2-fund-desc" title="<?php echo dd2_esc($tx['description']); ?>"><?php echo dd2_esc($tx['description']); ?></span>
                                            <span class="dd2-fund-amount"><?php echo dd2_money($tx['amount']); ?></span>
                                            <span class="dd2-fund-date<?php echo $fundIsToday ? ' is-today' : ''; ?>"><?php echo $fundIsToday ? 'Hôm nay' : dd2_esc($tx['date_label']); ?></span>
                                        </div>
                                    <?php endforeach; endif; ?>
                                </div>
                                <div class="dd2-pagination" data-dd2-pager="fund" data-page="<?php echo (int) $d_fund['recent']['page']; ?>" data-total-pages="<?php echo (int) $d_fund['recent']['total_pages']; ?>"></div>
                            </div>
                        </div>
                    </div>

                    <!-- HÀNG DƯỚI CÙNG (2026-07-28) — 2 khối bề rộng bằng nhau, nằm dưới màn hình
                         đầu tiên nên trang có thêm cuộn dọc (.dd2-main overflow-y:auto). -->
                    <div class="dd2-row dd2-row-extra">

                        <!-- 7. THEO DÕI TỒN KHO (pin dung lượng) -->
                        <div class="dd2-card dd2-col-stock-watch">
                            <div class="dd2-card-head">
                                <p class="dd2-title"><i class="fa-solid fa-battery-three-quarters"></i> Theo dõi tồn kho</p>
                                <button type="button" class="dd2-icon-btn" id="dd2-sw-settings-btn" title="Danh sách đang ẩn — bật lại để xét">
                                    <i class="fa-solid fa-gear"></i>
                                </button>
                            </div>
                            <!-- 2 khối con xếp TRÊN/DƯỚI, mỗi khối 4 mục dàn 2 cột (2 mục/dòng);
                                 chỉ có 1 đường kẻ xám mờ ngăn giữa 2 khối con. -->
                            <div class="dd2-sw-cols">
                                <div class="dd2-sw-col">
                                    <p class="dd2-sw-sub">Tồn kho thành phẩm</p>
                                    <div class="dd2-sw-list" id="dd2-sw-products">
                                        <?php
                                        $swP = $d_stockWatch['products'];
                                        if (empty($swP)): ?>
                                            <p class="dd2-empty">Chưa có dữ liệu bán hàng.</p>
                                        <?php else:
                                            for ($i = 0; $i < max($SW_MIN_ROWS, count($swP)); $i++):
                                                if (isset($swP[$i])) { echo dd2_stock_watch_row($swP[$i], 'product'); }
                                                else { echo '<div class="dd2-sw-row is-empty"></div>'; }
                                            endfor;
                                        endif; ?>
                                    </div>
                                </div>
                                <div class="dd2-sw-col">
                                    <p class="dd2-sw-sub">Tồn kho nguyên liệu</p>
                                    <div class="dd2-sw-list" id="dd2-sw-materials">
                                        <?php
                                        $swM = $d_stockWatch['materials'];
                                        if (empty($swM)): ?>
                                            <p class="dd2-empty">Chưa có dữ liệu xuất dùng.</p>
                                        <?php else:
                                            for ($i = 0; $i < max($SW_MIN_ROWS, count($swM)); $i++):
                                                if (isset($swM[$i])) { echo dd2_stock_watch_row($swM[$i], 'material'); }
                                                else { echo '<div class="dd2-sw-row is-empty"></div>'; }
                                            endfor;
                                        endif; ?>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <!-- 8. BIẾN ĐỘNG GIÁ NHẬP -->
                        <div class="dd2-card dd2-col-price-change">
                            <div class="dd2-card-head">
                                <p class="dd2-title"><i class="fa-solid fa-money-bill-trend-up"></i> Biến động giá nhập</p>
                            </div>
                            <div class="dd2-pc-wrap">
                                <table class="dd2-pc-table">
                                    <thead>
                                        <tr><th>Ngày</th><th>Tên hàng hóa</th><th>Giá cũ</th><th>Giá mới</th><th>Tỷ lệ</th></tr>
                                    </thead>
                                    <tbody id="dd2-price-changes-body">
                                        <?php
                                        $pcRows = $d_priceChg['rows'];
                                        if (empty($pcRows)): ?>
                                            <tr><td colspan="5" class="dd2-pc-empty">Chưa ghi nhận biến động giá nhập.</td></tr>
                                        <?php else:
                                            for ($i = 0; $i < max(RP_DD_PRICE_CHANGES_PER_PAGE, count($pcRows)); $i++):
                                                $c = $pcRows[$i] ?? null;
                                                if (!$c): ?>
                                                    <tr class="dd2-pc-row is-empty"><td colspan="5"></td></tr>
                                                <?php continue; endif;
                                                $isMat = $c['kind'] === 'material';
                                                $pcToday = $c['date_iso'] === date('Y-m-d');
                                            ?>
                                                <tr class="dd2-pc-row<?php echo $isMat ? ' is-clickable' : ''; ?>"
                                                    data-kind="<?php echo dd2_esc($c['kind']); ?>"
                                                    data-item-id="<?php echo (int) $c['item_id']; ?>"
                                                    data-name="<?php echo dd2_esc($c['name']); ?>"
                                                    data-old="<?php echo dd2_num_raw($c['old_price']); ?>"
                                                    data-new="<?php echo dd2_num_raw($c['new_price']); ?>"
                                                    title="<?php echo $isMat ? 'Bấm để xem sản phẩm bị ảnh hưởng giá vốn' : 'Biến động giá mua thành phẩm (không tính giá vốn sản xuất)'; ?>">
                                                    <td class="dd2-pc-date<?php echo $pcToday ? ' is-today' : ''; ?>"><?php echo dd2_esc($c['date_label']); ?></td>
                                                    <td class="dd2-pc-name" title="<?php echo dd2_esc($c['name']); ?>"><?php echo dd2_esc($c['name']); ?></td>
                                                    <td class="dd2-pc-old"><?php echo dd2_money($c['old_price']); ?></td>
                                                    <td class="dd2-pc-new"><?php echo dd2_money($c['new_price']); ?></td>
                                                    <td class="dd2-pc-rate <?php echo $c['is_up'] ? 'is-up' : 'is-down'; ?>">
                                                        <i class="fa-solid fa-caret-<?php echo $c['is_up'] ? 'up' : 'down'; ?>"></i>
                                                        <?php echo dd2_num($c['change_rate']); ?>%
                                                    </td>
                                                </tr>
                                            <?php endfor;
                                        endif; ?>
                                    </tbody>
                                </table>
                            </div>
                            <div class="dd2-pagination" data-dd2-pager="price_changes" data-page="<?php echo (int) $d_priceChg['page']; ?>" data-total-pages="<?php echo (int) $d_priceChg['total_pages']; ?>"></div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- MODAL: cài đặt chỉ số hiệu quả -->
    <div class="app-modal" id="dd2-settings-modal" aria-hidden="true">
        <div class="app-modal-overlay" data-dd2-settings-close></div>
        <div class="app-modal-box dd2-settings-box">
            <div class="app-modal-head">
                <h3>Cài đặt chỉ số hiệu quả</h3>
                <button type="button" class="app-modal-close" data-dd2-settings-close aria-label="Đóng">&times;</button>
            </div>
            <div class="app-modal-body">
                <label class="dd2-form-label">Sản lượng tối đa 1 ngày / 1 công</label>
                <input type="number" id="dd2-max-output-input" class="dd2-form-input" min="0" step="1" value="<?php echo (float) $eff['max_setting']; ?>">

                <button type="button" class="dd2-btn-ghost dd2-temp-values-btn" id="dd2-temp-values-btn"><i class="fa-solid fa-sliders"></i> Thiết lập giá trị tạm</button>

                <div class="dd2-key-products-head">
                    <span>Sản phẩm chủ lực (tồn tối thiểu)</span>
                    <button type="button" class="dd2-btn-ghost" id="dd2-add-key-product"><i class="fa-solid fa-plus"></i> Thêm</button>
                </div>
                <div id="dd2-key-products-list">
                    <?php foreach ($d_output['key_products'] as $kp): ?>
                        <div class="dd2-kp-row" data-product-id="<?php echo (int) $kp['product_id']; ?>">
                            <span class="dd2-kp-name"><?php echo dd2_esc($kp['name']); ?></span>
                            <input type="number" class="dd2-kp-min" min="0" step="1" value="<?php echo (float) $kp['min_quantity']; ?>">
                            <button type="button" class="dd2-kp-remove" title="Xóa"><i class="fa-solid fa-trash"></i></button>
                        </div>
                    <?php endforeach; ?>
                </div>
                <div class="dd2-kp-add-row" id="dd2-kp-add-row" hidden>
                    <div class="app-remind-picker">
                        <input type="text" class="app-remind-search" id="dd2-kp-search" placeholder="Tìm sản phẩm...">
                        <div class="app-remind-suggest" id="dd2-kp-suggest"></div>
                    </div>
                </div>

                <button type="button" class="dd2-btn-primary" id="dd2-settings-save">Lưu cài đặt</button>
            </div>
        </div>
    </div>

    <!-- MODAL: thiết lập giá trị tạm — Sản lượng (ưu tiên hơn dữ liệu tính từ DB) -->
    <div class="app-modal" id="dd2-temp-values-modal" aria-hidden="true">
        <div class="app-modal-overlay" data-dd2-temp-values-close></div>
        <div class="app-modal-box dd2-settings-box">
            <div class="app-modal-head">
                <h3>Thiết lập giá trị tạm</h3>
                <button type="button" class="app-modal-close" data-dd2-temp-values-close aria-label="Đóng">&times;</button>
            </div>
            <div class="app-modal-body">
                <p class="dd2-form-hint">Ưu tiên giá trị nhập ở đây thay vì tính từ dữ liệu; để trống ô nào thì tháng đó tự lấy lại từ dữ liệu.</p>
                <div id="dd2-temp-values-list">
                    <?php foreach ($d_output['override_months'] as $om): ?>
                        <div class="dd2-tv-row" data-ym="<?php echo dd2_esc($om['ym']); ?>">
                            <span class="dd2-tv-label"><?php echo dd2_esc($om['label']); ?></span>
                            <input type="number" class="dd2-tv-input" step="0.01" placeholder="<?php echo dd2_num($om['value']); ?>" value="<?php echo dd2_num_raw($om['has_override'] ? $om['override_value'] : $om['value']); ?>">
                        </div>
                    <?php endforeach; ?>
                </div>
                <button type="button" class="dd2-btn-primary" id="dd2-temp-values-save">Lưu giá trị tạm</button>
            </div>
        </div>
    </div>

    <!-- MODAL: thiết lập giá trị tạm — Xuất kho (2 tab Doanh thu/Số lượng, dùng chung bảng overrides qua cột metric 'export'/'export_qty') -->
    <div class="app-modal" id="dd2-export-temp-values-modal" aria-hidden="true">
        <div class="app-modal-overlay" data-dd2-export-temp-values-close></div>
        <div class="app-modal-box dd2-settings-box">
            <div class="app-modal-head">
                <h3>Thiết lập giá trị tạm (Xuất kho)</h3>
                <button type="button" class="app-modal-close" data-dd2-export-temp-values-close aria-label="Đóng">&times;</button>
            </div>
            <div class="app-modal-body">
                <div class="dd2-toggle-group">
                    <button type="button" class="dd2-toggle-btn active" data-dd2-export-tv-tab="value">Doanh thu</button>
                    <button type="button" class="dd2-toggle-btn" data-dd2-export-tv-tab="quantity">Số lượng</button>
                </div>
                <p class="dd2-form-hint">Ưu tiên giá trị nhập ở đây thay vì tính từ dữ liệu; để trống ô nào thì tháng đó tự lấy lại từ dữ liệu. Tab đang chọn quyết định biểu đồ Xuất kho hiển thị theo Doanh thu hay Số lượng.</p>
                <div class="dd2-field-row">
                    <div class="dd2-field">
                        <label class="dd2-form-label">Mốc bắt đầu trục</label>
                        <input type="number" id="dd2-export-axis-min" class="dd2-form-input" step="any" value="<?php echo dd2_num($d_exports['axis']['min']); ?>">
                    </div>
                    <div class="dd2-field">
                        <label class="dd2-form-label">Bước nhảy (0 = ẩn trục)</label>
                        <input type="number" id="dd2-export-axis-step" class="dd2-form-input" min="0" step="any" value="<?php echo dd2_num($d_exports['axis']['step']); ?>">
                    </div>
                </div>
                <div id="dd2-export-temp-values-list">
                    <?php foreach ($exportOverrideMonths as $om):
                        $hasOv = array_key_exists($om['ym'], $exportOverridesMap);
                    ?>
                        <div class="dd2-tv-row" data-ym="<?php echo dd2_esc($om['ym']); ?>">
                            <span class="dd2-tv-label"><?php echo dd2_esc($om['label']); ?></span>
                            <input type="number" class="dd2-tv-input" step="0.01" placeholder="<?php echo dd2_num($om['value']); ?>" value="<?php echo dd2_num_raw($hasOv ? $exportOverridesMap[$om['ym']] : $om['value']); ?>">
                        </div>
                    <?php endforeach; ?>
                </div>
                <div id="dd2-export-qty-temp-values-list" hidden>
                    <?php foreach ($exportQtyOverrideMonths as $om):
                        $hasOv = array_key_exists($om['ym'], $exportQtyOverridesMap);
                    ?>
                        <div class="dd2-tv-row" data-ym="<?php echo dd2_esc($om['ym']); ?>">
                            <span class="dd2-tv-label"><?php echo dd2_esc($om['label']); ?></span>
                            <input type="number" class="dd2-tv-input" step="0.01" placeholder="<?php echo dd2_num($om['value']); ?>" value="<?php echo dd2_num_raw($hasOv ? $exportQtyOverridesMap[$om['ym']] : $om['value']); ?>">
                        </div>
                    <?php endforeach; ?>
                </div>
                <button type="button" class="dd2-btn-primary" id="dd2-export-temp-values-save">Lưu giá trị tạm</button>
            </div>
        </div>
    </div>

    <!-- MODAL: cập nhật tồn đầu quỹ -->
    <div class="app-modal" id="dd2-fund-opening-modal" aria-hidden="true">
        <div class="app-modal-overlay" data-dd2-fund-opening-close></div>
        <div class="app-modal-box">
            <div class="app-modal-head">
                <h3>Cập nhật tồn đầu quỹ</h3>
                <button type="button" class="app-modal-close" data-dd2-fund-opening-close aria-label="Đóng">&times;</button>
            </div>
            <div class="app-modal-body">
                <label class="dd2-form-label">Tồn đầu (số dư trước khi bắt đầu ghi sổ thu chi)</label>
                <input type="number" id="dd2-fund-opening-input" class="dd2-form-input" step="1" value="<?php echo (float) rp_dd_get_fund_opening_balance(); ?>">
                <button type="button" class="dd2-btn-primary" id="dd2-fund-opening-save">Lưu</button>
            </div>
        </div>
    </div>

    <!-- MODAL: giải thích chỉ số "Hiệu quả" (click .dd2-efficiency-value) -->
    <div class="app-modal" id="dd2-efficiency-modal" aria-hidden="true">
        <div class="app-modal-overlay" data-dd2-efficiency-close></div>
        <div class="app-modal-box">
            <div class="app-modal-head">
                <h3>Chỉ số hiệu quả</h3>
                <button type="button" class="app-modal-close" data-dd2-efficiency-close aria-label="Đóng">&times;</button>
            </div>
            <div class="app-modal-body">
                <ul class="dd2-explain-list">
                    <li>Sản lượng tháng (đến hiện tại): <b><?php echo dd2_num($d_output['current']); ?></b></li>
                    <li>Tổng công đã chấm trong tháng: <b><?php echo dd2_num($eff['total_cong']); ?></b></li>
                    <li>→ Sản lượng bình quân / công = làm tròn(<?php echo dd2_num($d_output['current']); ?> ÷ <?php echo dd2_num($eff['total_cong']); ?>) = <b><?php echo dd2_num($eff['avg_per_cong']); ?></b></li>
                    <li>Ngưỡng cài đặt (sản lượng tối đa 1 ngày / 1 công): <b><?php echo dd2_num($eff['max_setting']); ?></b></li>
                    <li>→ Hiệu quả = làm tròn(<?php echo dd2_num($eff['avg_per_cong']); ?> ÷ <?php echo dd2_num($eff['max_setting']); ?> × 10, 2) = <b><?php echo number_format((float) $eff['value'], 2); ?></b></li>
                </ul>
            </div>
        </div>
    </div>

    <!-- MODAL: giải thích trạng thái "Điều tiết" cung/cầu (click .dd2-regulation-value) -->
    <div class="app-modal" id="dd2-regulation-modal" aria-hidden="true">
        <div class="app-modal-overlay" data-dd2-regulation-close></div>
        <div class="app-modal-box">
            <div class="app-modal-head">
                <h3>Chỉ số điều tiết</h3>
                <button type="button" class="app-modal-close" data-dd2-regulation-close aria-label="Đóng">&times;</button>
            </div>
            <div class="app-modal-body">
                <p class="dd2-form-hint">Tương quan cung/cầu</p>
                <p>a = sản lượng/xuất kho = <?php echo dd2_num($reg['output']); ?>/<?php echo dd2_num($reg['export_qty']); ?> = <b><?php echo number_format((float) $reg['a'], 2); ?></b> (1)</p>
                <p class="dd2-form-hint">Hệ số sản phẩm chủ lực</p>
                <p>b = Số sp chủ lực / tổng số sp chủ lực = <?php echo (int) $reg['key_below']; ?>/<?php echo (int) $reg['key_total']; ?> = <b><?php echo number_format((float) $reg['b'], 2); ?></b> (<?php echo number_format($reg['b'] * 100, 0); ?>%) (2)</p>
                <ul class="dd2-explain-list">
                    <li>Với a &lt; 9 và b &gt; 0.5 ⇒ <b>Cung yếu</b></li>
                    <li>a &gt; 9 và b &gt; 0.5 ⇒ <b>Vượt cầu</b></li>
                    <li>Còn lại ⇒ <b>Ổn định</b></li>
                </ul>
                <p>Từ (1) và (2) suy ra chỉ số điều tiết đang ở trạng thái <b class="dd2-state-<?php echo dd2_esc($reg['state']); ?>"><?php echo dd2_esc($reg['label']); ?></b>.</p>
            </div>
        </div>
    </div>

    <!-- MODAL: danh sách nhân sự vắng hôm nay (click khối "Chấm công" ở đầu trang) -->
    <div class="app-modal" id="dd2-attendance-modal" aria-hidden="true">
        <div class="app-modal-overlay" data-dd2-attendance-close></div>
        <div class="app-modal-box">
            <div class="app-modal-head">
                <h3>Nhân sự vắng hôm nay</h3>
                <button type="button" class="app-modal-close" data-dd2-attendance-close aria-label="Đóng">&times;</button>
            </div>
            <div class="app-modal-body">
                <ul class="dd2-attendance-list">
                    <?php foreach ($d_attendance as $a): ?>
                        <li><b><?php echo dd2_esc($a['full_name']); ?></b> — <?php echo dd2_esc($a['reason']); ?></li>
                    <?php endforeach; ?>
                </ul>
            </div>
        </div>
    </div>

    <!-- MODAL: chi tiết đơn hàng chi nhánh (click 1 trong 4 card "Chi nhánh đặt hàng") — SP thiếu tồn + lý do -->
    <div class="app-modal" id="dd2-branch-detail-modal" aria-hidden="true">
        <div class="app-modal-overlay" data-dd2-branch-detail-close></div>
        <div class="app-modal-box dd2-branch-detail-box">
            <div class="app-modal-head">
                <h3 id="dd2-branch-detail-title">Chi tiết đơn hàng</h3>
                <button type="button" class="app-modal-close" data-dd2-branch-detail-close aria-label="Đóng">&times;</button>
            </div>
            <div class="app-modal-body" id="dd2-branch-detail-body">
                <p class="dd2-empty">Đang tải...</p>
            </div>
        </div>
    </div>

    <!-- MODAL: giải thích giá trị dự kiến của 1 đơn "Đặt hàng nguyên liệu" (click .dd2-mo-item-count) -->
    <div class="app-modal" id="dd2-mo-price-modal" aria-hidden="true">
        <div class="app-modal-overlay" data-dd2-mo-price-close></div>
        <div class="app-modal-box dd2-mo-price-box">
            <div class="app-modal-head">
                <h3 id="dd2-mo-price-title">Giá trị nhập kho dự kiến</h3>
                <button type="button" class="app-modal-close" data-dd2-mo-price-close aria-label="Đóng">&times;</button>
            </div>
            <div class="app-modal-body" id="dd2-mo-price-body">
                <p class="dd2-empty">Đang tải...</p>
            </div>
        </div>
    </div>

    <!-- MODAL: giải thích giá trị nhập kho của 1 hóa đơn (click .dd2-value ở khối "Nhập kho") -->
    <div class="app-modal" id="dd2-import-value-modal" aria-hidden="true">
        <div class="app-modal-overlay" data-dd2-import-value-close></div>
        <div class="app-modal-box dd2-mo-price-box">
            <div class="app-modal-head">
                <h3>Giá trị nhập kho</h3>
                <button type="button" class="app-modal-close" data-dd2-import-value-close aria-label="Đóng">&times;</button>
            </div>
            <div class="app-modal-body" id="dd2-import-value-body">
                <p class="dd2-empty">Đang tải...</p>
            </div>
        </div>
    </div>

    <!-- MODAL: giải thích giá trị xuất kho của 1 phiếu (click .dd2-value ở khối "Xuất kho") -->
    <div class="app-modal" id="dd2-export-value-modal" aria-hidden="true">
        <div class="app-modal-overlay" data-dd2-export-value-close></div>
        <div class="app-modal-box dd2-mo-price-box">
            <div class="app-modal-head">
                <h3>Giá trị xuất kho</h3>
                <button type="button" class="app-modal-close" data-dd2-export-value-close aria-label="Đóng">&times;</button>
            </div>
            <div class="app-modal-body" id="dd2-export-value-body">
                <p class="dd2-empty">Đang tải...</p>
            </div>
        </div>
    </div>

    <!-- MODAL: chi tiết NVL của giá vốn sản xuất -->
    <div class="app-modal" id="dd2-cost-modal" aria-hidden="true">
        <div class="app-modal-overlay" data-dd2-cost-close></div>
        <div class="app-modal-box dd2-product-box">
            <div class="app-modal-head">
                <h3 id="dd2-cost-modal-title">Giá vốn sản xuất</h3>
                <button type="button" class="app-modal-close" data-dd2-cost-close aria-label="Đóng">&times;</button>
            </div>
            <div class="app-modal-body" id="dd2-cost-modal-body">
                <p class="dd2-empty">Đang tải...</p>
            </div>
        </div>
    </div>

    <!-- MODAL: chi tiết sản phẩm sản xuất hôm nay -->
    <div class="app-modal" id="dd2-product-modal" aria-hidden="true">
        <div class="app-modal-overlay" data-dd2-product-close></div>
        <div class="app-modal-box dd2-product-box">
            <div class="app-modal-head">
                <h3 id="dd2-product-modal-title">Sản phẩm</h3>
                <button type="button" class="app-modal-close" data-dd2-product-close aria-label="Đóng">&times;</button>
            </div>
            <div class="app-modal-body" id="dd2-product-modal-body">
                <p class="dd2-empty">Đang tải...</p>
            </div>
        </div>
    </div>

    <!-- MODAL: danh sách nhóm theo NCC (7 hóa đơn / CPMH) -->
    <div class="app-modal" id="dd2-import-group-modal" aria-hidden="true">
        <div class="app-modal-overlay" data-dd2-import-group-close></div>
        <div class="app-modal-box">
            <div class="app-modal-head">
                <h3 id="dd2-import-group-title">Theo nhà cung cấp</h3>
                <button type="button" class="app-modal-close" data-dd2-import-group-close aria-label="Đóng">&times;</button>
            </div>
            <div class="app-modal-body" id="dd2-import-group-body">
                <p class="dd2-empty">Đang tải...</p>
            </div>
        </div>
    </div>

    <!-- MODAL: ảnh hóa đơn (NCC/KH) -->
    <div class="app-modal" id="dd2-att-modal" aria-hidden="true">
        <div class="app-modal-overlay" data-dd2-att-close></div>
        <div class="app-modal-box dd2-att-box">
            <div class="app-modal-head">
                <h3>Hình ảnh hóa đơn</h3>
                <button type="button" class="app-modal-close" data-dd2-att-close aria-label="Đóng">&times;</button>
            </div>
            <div class="app-modal-body">
                <div class="dd2-att-grid" id="dd2-att-grid"></div>
            </div>
        </div>
    </div>

    <!-- MODAL: danh sách ĐẦY ĐỦ dùng chung (sidebar 6 icon + nút "..."/"Sales Order") -->
    <div class="app-modal" id="dd2-full-list-modal" aria-hidden="true">
        <div class="app-modal-overlay" data-dd2-full-list-close></div>
        <div class="app-modal-box dd2-full-list-box">
            <div class="app-modal-head">
                <h3 id="dd2-full-list-title">Danh sách</h3>
                <div class="dd2-full-list-head-actions">
                    <input type="text" class="dd2-full-list-search" id="dd2-full-list-search" placeholder="Tìm kiếm..." hidden>
                    <select class="dd2-full-list-filter" id="dd2-full-list-filter" hidden>
                        <option value="">Tất cả</option>
                        <option value="Thu">Thu</option>
                        <option value="Chi">Chi</option>
                    </select>
                    <button type="button" class="app-modal-close" data-dd2-full-list-close aria-label="Đóng">&times;</button>
                </div>
            </div>
            <div class="app-modal-body">
                <div id="dd2-full-list-body"><p class="dd2-empty">Đang tải...</p></div>
                <div class="dd2-full-list-totals" id="dd2-full-list-totals" hidden></div>
                <div class="dd2-pagination" id="dd2-full-list-pager"></div>
            </div>
        </div>
    </div>

    <!-- MODAL: chi tiết 1 mục của khối "Theo dõi tồn kho" (bấm vào ô).
         SP -> NVL trong công thức + tồn NVL; NVL -> SP đang dùng nó + tồn thành phẩm. -->
    <div class="app-modal" id="dd2-sw-detail-modal" aria-hidden="true">
        <div class="app-modal-overlay" data-dd2-sw-close></div>
        <div class="app-modal-box dd2-sw-detail-box">
            <div class="app-modal-head">
                <h3 id="dd2-sw-detail-title">Chi tiết</h3>
                <button type="button" class="app-modal-close" data-dd2-sw-close aria-label="Đóng">&times;</button>
            </div>
            <div class="app-modal-body">
                <p class="dd2-pci-sub" id="dd2-sw-detail-sub"></p>
                <div id="dd2-sw-detail-body"><p class="dd2-empty">Đang tải...</p></div>
            </div>
        </div>
    </div>

    <!-- MODAL: danh sách mục đang ẩn khỏi khối "Theo dõi tồn kho" (nút bánh răng) -->
    <div class="app-modal" id="dd2-sw-hidden-modal" aria-hidden="true">
        <div class="app-modal-overlay" data-dd2-swh-close></div>
        <div class="app-modal-box dd2-sw-hidden-box">
            <div class="app-modal-head">
                <h3>Cài đặt "Theo dõi tồn kho"</h3>
                <button type="button" class="app-modal-close" data-dd2-swh-close aria-label="Đóng">&times;</button>
            </div>
            <div class="app-modal-body">
                <!-- Mốc thời gian đảm bảo: đổi mức 100% của pin (mặc định 2 tuần) -->
                <div class="dd2-sw-set-block">
                    <div class="dd2-sw-set-name">Mốc đảm bảo bán / dùng</div>
                    <div class="dd2-sw-set-desc">Tồn hiện tại đủ bán (hoặc đủ dùng) trong bao lâu thì coi là đầy pin 100%.
                        Mức chuẩn = sản lượng trung bình 1 ngày (tính trên <?php echo (int) $d_stockWatch['window']; ?> ngày gần nhất) × số ngày chọn dưới đây.</div>
                    <div class="dd2-sw-cover-opts" id="dd2-sw-cover-opts">
                        <?php foreach ($d_stockWatch['cover_options'] as $opt): ?>
                            <button type="button" class="dd2-sw-cover-opt<?php echo (int) $opt['days'] === (int) $d_stockWatch['cover'] ? ' is-active' : ''; ?>"
                                    data-days="<?php echo (int) $opt['days']; ?>"><?php echo dd2_esc($opt['label']); ?></button>
                        <?php endforeach; ?>
                    </div>
                </div>

                <div class="dd2-sw-set-block">
                    <div class="dd2-sw-set-name">Đang ẩn khỏi khối</div>
                    <div class="dd2-sw-set-desc">Các mục dưới đây không được xét vào khối. Bấm "Xét lại" để đưa trở lại danh sách.</div>
                    <div id="dd2-sw-hidden-body"><p class="dd2-empty">Đang tải...</p></div>
                </div>
            </div>
        </div>
    </div>

    <!-- MODAL: "Giá vốn ảnh hưởng" — bấm 1 dòng biến động giá NVL ở khối "Biến động giá nhập".
         2 lớp: (1) danh sách SP bị ảnh hưởng, (2) giải thích chi tiết giá vốn 1 SP. Dùng lại
         nguyên 2 endpoint có sẵn của view row_material_receiving (module inventory_receiving):
         ajax_material_cost_impact / ajax_product_cost_breakdown. -->
    <div class="app-modal" id="dd2-price-impact-modal" aria-hidden="true">
        <div class="app-modal-overlay" data-dd2-pci-close></div>
        <div class="app-modal-box dd2-pci-box">
            <div class="app-modal-head">
                <button type="button" class="dd2-pci-back" id="dd2-pci-back" hidden title="Quay lại danh sách"><i class="fa-solid fa-arrow-left"></i></button>
                <h3 id="dd2-pci-title">Giá vốn ảnh hưởng</h3>
                <button type="button" class="app-modal-close" data-dd2-pci-close aria-label="Đóng">&times;</button>
            </div>
            <div class="app-modal-body">
                <p class="dd2-pci-sub" id="dd2-pci-sub"></p>
                <div id="dd2-pci-body"><p class="dd2-empty">Đang tải...</p></div>
            </div>
        </div>
    </div>

    <script>
        // Trang này mặc định ẩn bóng chat (dock lên header) cho tới khi chủ động bấm mở lại — chỉ áp dụng trong phiên/trang này, không lưu localStorage.
        window.DD2_FORCE_CHAT_DOCK = true;
        window.REPORT_CFG = { baseUrl: '?mod=report&controllers=report&action=' };
        window.DD2_INITIAL = <?php echo json_encode([
            'output'         => $d_output,
            'exports'        => ['series' => $d_exports['series'], 'series_qty' => $d_exports['series_qty'], 'axis' => $d_exports['axis'], 'today_by_customer' => $d_exports['today_by_customer']],
            'production_day' => $d_prodDay,
        ], JSON_UNESCAPED_UNICODE); ?>;
    </script>
    <script src="<?php echo asset_ver('public/js/chart.min.js'); ?>"></script>
    <script src="<?php echo asset_ver('public/js/shared/app_shell.js'); ?>"></script>
    <script src="<?php echo asset_ver('public/js/shared/invoice_dropzone.js'); ?>"></script>
    <!-- html2canvas: chụp header + nội dung báo cáo thành ảnh PNG để copy vào clipboard (nút "máy ảnh") -->
    <script src="https://cdnjs.cloudflare.com/ajax/libs/html2canvas/1.4.1/html2canvas.min.js"></script>
    <script src="<?php echo asset_ver('public/js/report/daily_dashboard.js'); ?>"></script>
</body>

</html>
