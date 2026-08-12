<?php

/** @var array $data */
$data = isset($data) ? $data : ['rows' => [], 'total_pages' => 0, 'page' => 1, 'customers' => []];
if (!isset($data['customers']) || !is_array($data['customers'])) $data['customers'] = [];
if (!isset($data['auto_delete_days'])) $data['auto_delete_days'] = 0;
if (!isset($data['slip_map']) || !is_array($data['slip_map'])) $data['slip_map'] = [];

if (!function_exists('om_money')) {
    function om_money($n) { return number_format((float) $n, 0, ',', '.'); }
    function om_wt($n)    { return rtrim(rtrim(number_format((float) $n, 2, ',', '.'), '0'), ','); }
}
?>
<!DOCTYPE html>
<html lang="vi">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Đơn hàng từ chi nhánh</title>
    <link rel="icon" href="public/images/logo/logo_vat_png.png" type="image/png">
    <link rel="stylesheet" href="<?php echo asset_ver('public/css/reset.css'); ?>">
    <link rel="stylesheet" href="<?php echo asset_ver('public/css/all.css'); ?>">
    <link rel="stylesheet" href="<?php echo asset_ver('public/css/global.css'); ?>">
    <link rel="stylesheet" href="<?php echo asset_ver('public/css/order_management/order_management.css'); ?>">
    <link rel="stylesheet" href="<?php echo asset_ver('public/css/shared/app_shell.css'); ?>">
    <script src="<?php echo asset_ver('public/js/jquery-4.0.0.js'); ?>"></script>
</head>

<body>
    <div id="wrapper">
        <?php get_sidebar('app'); ?>
        <?php get_header('app'); ?>

        <div class="om-page">
            <div class="om-page-head">
                <h1>Đơn hàng từ chi nhánh</h1>
                <div class="om-page-head-actions">
                    <button type="button" class="om-btn om-btn-primary" id="om-new-order-btn">
                        <i class="fa-solid fa-plus"></i> Thêm đơn hàng thủ công
                    </button>
                    <a class="om-btn om-btn-ghost" href="?mod=order_management&controllers=order_management&action=picking_slip">
                        <i class="fa-solid fa-pen-to-square"></i> Phiếu soạn nhập tay
                    </a>
                    <button type="button" class="om-btn om-btn-ghost" id="om-auto-delete-btn" title="Tự xóa đơn đã bốc theo thời gian">
                        <i class="fa-solid fa-gear"></i> Cài đặt
                    </button>
                </div>
            </div>

            <?php if (empty($data['rows'])): ?>
                <p class="om-empty">Chưa có đơn hàng nào từ chi nhánh.</p>
            <?php endif; ?>

            <div class="om-cards">
                <?php foreach ($data['rows'] as $o):
                    $color   = !empty($o['secondary_color']) ? $o['secondary_color'] : '#16a34a';
                    $stats   = $o['stats'];
                    $picked  = !empty($o['picked']);
                    $locked  = !empty($o['locked']);
                    $rel     = format_time_ago_vi($o['created_at']);
                    $pickup_val = !empty($o['pickup_time']) ? date('Y-m-d\TH:i', strtotime($o['pickup_time'])) : '';

                    $shorts = [];
                    foreach ($o['items'] as $it) {
                        if (!empty($it['is_short'])) {
                            $shorts[] = ['name' => (string) $it['product_name'], 'lack' => $it['qt_order'] - $it['stock'], 'unit' => (string) $it['unit']];
                        }
                    }
                    // Tên hiển thị: ưu tiên tên viết tắt (PLHCM, PLDN...) — style theo màu thứ cấp.
                    $cust_short = trim((string) ($o['cust_short'] ?? ''));
                    $cust_full  = (string) ($o['customer_name'] ?: 'Khách lẻ');
                    $cust_label = $cust_short !== '' ? $cust_short : $cust_full;
                    $modal_items = [];
                    foreach ($o['items'] as $idx => $it) {
                        $modal_items[] = [
                            'idx'   => $idx,
                            'pid'   => !empty($it['material_id']) ? (int) $it['material_id'] : (int) $it['product_id'],
                            'type'  => !empty($it['material_id']) ? 'material' : 'product',
                            'name'  => (string) $it['product_name'],
                            'qty'   => (float) $it['qt_order'],
                            'unit'  => (string) $it['unit'],
                            'weight_kg' => (float) ($it['weight_kg'] ?? 0),
                            'price' => (float) $it['system_price'],
                            'value' => (float) $it['line_value'],
                            'short' => !empty($it['is_short']),
                            'lack'  => !empty($it['is_short']) ? ($it['qt_order'] - $it['stock']) : 0,
                            'stock' => (float) $it['stock'],
                            'is_material' => !empty($it['material_id']),
                        ];
                    }
                    $payload = [
                        'id'       => (int) $o['id'],
                        'customer' => $cust_label, // ưu tiên tên viết tắt — dùng chung cho cả modal Xem đơn + Hàng thiếu
                        'items'    => $modal_items,
                        'weight'   => (float) $stats['weight_total'],
                        'value'    => (float) $stats['value_total'],
                        'locked'   => $locked ? 1 : 0,
                        'picked'   => $picked ? 1 : 0,
                    ];
                ?>
                    <div class="om-card<?= $picked ? ' is-picked' : '' ?><?= $locked ? ' is-locked' : '' ?><?= !empty($o['confirmed']) ? ' is-confirmed' : '' ?>"
                         data-id="<?= (int) $o['id'] ?>"
                         data-customer-id="<?= (int) $o['customer_id'] ?>"
                         style="--om-accent: <?= htmlspecialchars($color, ENT_QUOTES, 'UTF-8') ?>;"
                         data-order='<?= htmlspecialchars(json_encode($payload, JSON_UNESCAPED_UNICODE), ENT_QUOTES, 'UTF-8') ?>'>

                        <button type="button" class="om-card-del" title="Xóa đơn">&times;</button>
                        <?php if ($picked): ?><span class="om-picked-flag"><i class="fa-solid fa-circle-check"></i> Đã bốc</span><?php endif; ?>

                        <div class="om-card-top">
                            <span class="om-cust-dot"></span>
                            <div class="om-card-cust<?= $cust_short !== '' ? ' is-short' : '' ?>" title="<?= htmlspecialchars($cust_full, ENT_QUOTES, 'UTF-8') ?>">
                                <?= htmlspecialchars($cust_label, ENT_QUOTES, 'UTF-8') ?>
                            </div>
                        </div>

                        <?php if ($picked && !empty($o['confirmed']) && !empty($o['branch_reminders'])): ?>
                            <div class="om-branch-reminder">
                                <?php foreach ($o['branch_reminders'] as $rem): ?>
                                    <div class="om-branch-reminder-item"><i class="fa-solid fa-thumbtack"></i> <?= htmlspecialchars((string) $rem['note'], ENT_QUOTES, 'UTF-8') ?></div>
                                <?php endforeach; ?>
                            </div>
                        <?php endif; ?>

                        <div class="om-card-rows">
                            <div class="om-card-row"><i class="fa-regular fa-clock"></i> <?= htmlspecialchars($rel, ENT_QUOTES, 'UTF-8') ?></div>
                            <?php if (!empty($o['receiver'])): ?>
                                <div class="om-card-row"><i class="fa-solid fa-user"></i> <?= htmlspecialchars((string) $o['receiver'], ENT_QUOTES, 'UTF-8') ?></div>
                            <?php endif; ?>
                        </div>

                        <div class="om-card-short">
                            <?php if (!empty($shorts)): ?>
                                <div class="om-card-short-head"><i class="fa-solid fa-triangle-exclamation"></i> Thiếu tồn: <b><?= count($shorts) ?> SP</b></div>
                                <ul class="om-card-short-list">
                                    <?php foreach ($shorts as $s): ?>
                                        <li><?= htmlspecialchars($s['name'], ENT_QUOTES, 'UTF-8') ?> thiếu <b><?= om_wt($s['lack']) ?></b> <?= htmlspecialchars($s['unit'], ENT_QUOTES, 'UTF-8') ?></li>
                                    <?php endforeach; ?>
                                </ul>
                            <?php else: ?>
                                <div class="om-card-enough"><i class="fa-solid fa-circle-check"></i> Đủ tồn toàn bộ</div>
                            <?php endif; ?>
                        </div>

                        <div class="om-card-totals">
                            <div><span>Khối lượng</span><b><?= om_money($stats['weight_total']) ?> kg</b></div>
                            <div><span>Giá trị</span><b><?= om_money($stats['value_total']) ?> đ</b></div>
                        </div>

                        <div class="om-card-pickup">
                            <label>Hẹn bốc:</label>
                            <div class="om-card-pick">
                                <input type="datetime-local" class="om-pickup-input" value="<?= htmlspecialchars($pickup_val, ENT_QUOTES, 'UTF-8') ?>">
                                <button type="button" class="om-send-pickup" title="Gửi thông báo cho bán hàng"><i class="fa-solid fa-paper-plane"></i></button>
                            </div>
                        </div>

                        <?php
                        // Trạng thái phiếu soạn đã gửi xuống kho (nếu có).
                        $slip = $data['slip_map'][(int) $o['id']] ?? null;
                        $slip_status = $slip ? (string) $slip['status'] : '';
                        $slip_done   = ($slip_status === 'done');
                        $slip_synced = $slip ? !empty($slip['synced']) : false;
                        if ($slip_synced)      { $send_cls = ' is-synced';  $send_title = 'Đã cập nhật đơn theo phiếu soạn — bấm để gửi thêm phiếu mới'; $send_icon = 'fa-paper-plane'; }
                        elseif ($slip_done)    { $send_cls = ' is-done';    $send_title = 'Kho đã soạn xong — bấm để xem & cập nhật đơn'; $send_icon = 'fa-clipboard-check'; }
                        elseif ($slip)         { $send_cls = ' is-doing';   $send_title = 'Kho đang soạn — bấm để xem tiến độ'; $send_icon = 'fa-clipboard-list'; }
                        else                   { $send_cls = '';            $send_title = 'Gửi phiếu soạn cho nhân viên kho'; $send_icon = 'fa-paper-plane'; }
                        ?>
                        <div class="om-card-actions">
                            <button type="button"
                                    class="om-btn om-btn-sm om-btn-icon om-send-picking<?= $send_cls ?>"
                                    data-slip-id="<?= $slip ? (int) $slip['id'] : 0 ?>"
                                    data-slip-status="<?= htmlspecialchars($slip_status, ENT_QUOTES, 'UTF-8') ?>"
                                    title="<?= htmlspecialchars($send_title, ENT_QUOTES, 'UTF-8') ?>">
                                <i class="fa-solid <?= $send_icon ?>"></i>
                            </button>
                            <a class="om-btn om-btn-primary om-btn-sm om-btn-icon"
                               href="?mod=order_management&controllers=order_management&action=picking_slip&order_id=<?= (int) $o['id'] ?>"
                               title="Tạo phiếu soạn">
                                <i class="fa-solid fa-clipboard-list"></i>
                            </a>
                            <button type="button" class="om-btn om-btn-ghost om-btn-sm om-btn-icon om-view-order" title="Xem đơn"><i class="fa-solid fa-eye"></i></button>
                            <button type="button" class="om-btn om-btn-success om-btn-sm om-btn-icon om-confirm-outbound" title="Xác nhận xuất kho">
                                <i class="fa-solid fa-truck-ramp-box"></i>
                            </button>
                            <button type="button" class="om-lock-btn om-btn-icon<?= $locked ? ' is-on' : '' ?>" title="<?= $locked ? 'Đã khóa' : 'Khóa đơn' ?>">
                                <i class="fa-solid <?= $locked ? 'fa-lock' : 'fa-lock-open' ?>"></i>
                            </button>
                        </div>

                        <div class="om-card-checks">
                            <div class="om-card-confirm">
                                <label class="om-confirm-toggle"><input type="checkbox" class="om-confirm-check" <?= !empty($o['confirmed']) ? 'checked' : '' ?>> Xác nhận đơn hàng</label>
                            </div>
                            <div class="om-card-flags">
                                <label class="om-picked-toggle"><input type="checkbox" class="om-picked-check" <?= $picked ? 'checked' : '' ?>> Đã bốc</label>
                            </div>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>

            <?php if ($data['total_pages'] > 1): ?>
                <div class="om-pagination">
                    <?php for ($i = 1; $i <= $data['total_pages']; $i++): ?>
                        <a href="?mod=order_management&controllers=order_management&action=branch_orders&page=<?= $i ?>"
                           class="<?= $i === $data['page'] ? 'active' : '' ?>"><?= $i ?></a>
                    <?php endfor; ?>
                </div>
            <?php endif; ?>
        </div>
    </div>

    <!-- ====== MODAL: XEM ĐƠN HÀNG (sửa số lượng được) ====== -->
    <div class="om-modal-mask" id="om-order-modal" style="display:none;">
        <div class="om-modal-box om-order-box">
            <span class="om-modal-close" id="om-order-close">&times;</span>
            <div id="om-order-capture">
                <div class="om-modal-title-row">
                    <h3 class="om-modal-title"><i class="fa-solid fa-receipt"></i> <span id="om-modal-cust"></span></h3>
                    <button type="button" class="om-btn om-btn-ghost om-btn-sm no-capture" id="om-view-shortage-btn">
                        <i class="fa-solid fa-triangle-exclamation"></i> Xem hàng thiếu
                    </button>
                </div>
                <div class="om-modal-warn" id="om-modal-warn" style="display:none;"></div>
                <table class="om-items">
                    <thead>
                        <tr>
                            <th class="text-left">Tên sản phẩm</th>
                            <th>Số lượng</th>
                            <th>Khối lượng</th>
                            <th>Giá bán</th>
                            <th>Giá trị</th>
                            <th class="no-capture">Trạng thái</th>
                        </tr>
                    </thead>
                    <tbody id="om-modal-body"></tbody>
                    <tfoot>
                        <tr>
                            <td class="text-left"><b>Tổng cộng</b></td>
                            <td></td>
                            <td><b id="om-modal-weight"></b></td>
                            <td></td>
                            <td><b id="om-modal-value"></b></td>
                            <td></td>
                        </tr>
                    </tfoot>
                </table>
            </div>
            <div class="om-modal-add">
                <div class="om-search-box">
                    <input type="text" id="om-add-search" class="om-add-input" autocomplete="off"
                           placeholder="+ Thêm sản phẩm / NVL — gõ tên hoặc mã rồi chọn...">
                    <ul class="om-suggest" id="om-add-suggest"></ul>
                </div>
            </div>
            <div class="om-modal-foot">
                <button type="button" class="om-btn om-btn-ghost" id="om-order-capture-btn" title="Chụp đơn hàng gửi khách"><i class="fa-solid fa-camera"></i> Chụp</button>
                <button type="button" class="om-btn om-btn-primary" id="om-save-qty"><i class="fa-solid fa-floppy-disk"></i> Lưu &amp; gửi bán hàng</button>
            </div>
        </div>
    </div>

    <!-- ====== MODAL: XEM HÀNG THIẾU ====== -->
    <div class="om-modal-mask" id="om-shortage-modal" style="display:none;">
        <div class="om-modal-box om-shortage-box">
            <span class="om-modal-close" id="om-shortage-close">&times;</span>
            <div id="om-shortage-capture">
                <h3 class="om-modal-title"><i class="fa-solid fa-triangle-exclamation"></i> Hàng thiếu — <span id="om-shortage-cust"></span></h3>
                <table class="om-items om-shortage-table" id="om-shortage-table">
                    <thead>
                        <tr>
                            <th class="text-left">Tên sản phẩm</th>
                            <th>Số lượng đặt</th>
                            <th>Tồn hiện có</th>
                            <th>Thiếu</th>
                            <th>Dự kiến ngày có lại</th>
                            <th class="no-capture">&nbsp;</th>
                        </tr>
                    </thead>
                    <tbody id="om-shortage-body"></tbody>
                </table>
                <p class="om-shortage-empty" id="om-shortage-empty" style="display:none;">Đơn này không có sản phẩm thiếu tồn.</p>
            </div>
            <div class="om-modal-foot">
                <button type="button" class="om-btn om-btn-ghost" id="om-shortage-cancel">Đóng</button>
                <button type="button" class="om-btn om-btn-primary" id="om-shortage-capture-btn"><i class="fa-solid fa-camera"></i> Chụp</button>
            </div>
        </div>
    </div>

    <!-- ====== MODAL: ĐỐI CHIẾU CÔNG THỨC (click ô Thiếu) ====== -->
    <div class="om-modal-mask" id="om-recipe-modal" style="display:none;">
        <div class="om-modal-box om-recipe-box">
            <span class="om-modal-close" id="om-recipe-close">&times;</span>
            <h3 class="om-modal-title"><i class="fa-solid fa-flask"></i> Đối chiếu công thức — <span id="om-recipe-pname"></span></h3>
            <p class="om-modal-hint" id="om-recipe-hint"></p>
            <div class="om-recipe-verdict" id="om-recipe-verdict"></div>
            <table class="om-items om-recipe-table" id="om-recipe-table" style="display:none;">
                <thead>
                    <tr>
                        <th>#</th>
                        <th class="text-left">Thành phần</th>
                        <th>Cần (đm × thiếu)</th>
                        <th>Tồn hiện có</th>
                        <th>Trạng thái</th>
                    </tr>
                </thead>
                <tbody id="om-recipe-body"></tbody>
            </table>
        </div>
    </div>

    <!-- ====== MODAL: THÊM ĐƠN HÀNG THỦ CÔNG ====== -->
    <div class="om-modal-mask" id="om-new-order-modal" style="display:none;">
        <div class="om-modal-box">
            <span class="om-modal-close" id="om-new-order-close">&times;</span>
            <h3 class="om-modal-title"><i class="fa-solid fa-plus"></i> Thêm đơn hàng thủ công</h3>
            <div class="om-new-order-row">
                <select id="om-new-customer">
                    <option value="">— Chọn chi nhánh —</option>
                    <?php foreach ($data['customers'] as $c): ?>
                        <option value="<?= (int) $c['id'] ?>"><?= htmlspecialchars((string) $c['name'], ENT_QUOTES, 'UTF-8') ?></option>
                    <?php endforeach; ?>
                </select>
                <input type="date" id="om-new-order-date" title="Ngày tạo đơn" value="<?= date('Y-m-d') ?>">
            </div>
            <div class="om-new-order-note">
                <input type="text" id="om-new-order-note" placeholder="Nhập ghi chú..." autocomplete="off">
            </div>
            <table class="om-items">
                <thead>
                    <tr>
                        <th class="text-left">Tên sản phẩm</th>
                        <th>Số lượng</th>
                        <th>Giá bán</th>
                        <th>Giá trị</th>
                        <th>Trạng thái</th>
                    </tr>
                </thead>
                <tbody id="om-new-modal-body"></tbody>
                <tfoot>
                    <tr>
                        <td class="text-left"><b>Tổng cộng</b></td>
                        <td></td>
                        <td></td>
                        <td><b id="om-new-modal-value">0 đ</b></td>
                        <td></td>
                    </tr>
                </tfoot>
            </table>
            <div class="om-modal-add">
                <button type="button" class="om-add-row-btn" id="om-new-add-row-btn">
                    <i class="fa-solid fa-plus"></i> Thêm sản phẩm / NVL
                </button>
            </div>
            <div class="om-modal-foot">
                <button type="button" class="om-btn om-btn-primary" id="om-save-new-order"><i class="fa-solid fa-floppy-disk"></i> Tạo đơn hàng</button>
            </div>
        </div>
    </div>

    <!-- ====== MODAL: CÀI ĐẶT — TỰ XÓA ĐƠN ĐÃ BỐC ====== -->
    <div class="om-modal-mask" id="om-auto-delete-modal" style="display:none;">
        <div class="om-modal-box om-auto-delete-box">
            <span class="om-modal-close" id="om-auto-delete-close">&times;</span>
            <h3 class="om-modal-title"><i class="fa-solid fa-gear"></i> Tự xóa đơn đã bốc</h3>
            <p class="om-modal-hint">Đơn đã đánh dấu "Đã bốc" sẽ tự động bị xóa khỏi danh sách sau khoảng thời gian chọn, tính từ lúc đánh dấu đã bốc.</p>
            <div class="om-auto-delete-options">
                <?php
                $ad_opts = [0 => 'Không tự xóa', 1 => 'Xóa sau 1 ngày', 3 => 'Xóa sau 3 ngày', 7 => 'Xóa sau 7 ngày', 30 => 'Xóa sau 1 tháng'];
                foreach ($ad_opts as $ad_val => $ad_label):
                ?>
                    <label class="om-auto-delete-opt">
                        <input type="radio" name="om-auto-delete" value="<?= $ad_val ?>" <?= ((int) $data['auto_delete_days'] === $ad_val) ? 'checked' : '' ?>>
                        <?= htmlspecialchars($ad_label, ENT_QUOTES, 'UTF-8') ?>
                    </label>
                <?php endforeach; ?>
            </div>
            <div class="om-modal-foot">
                <button type="button" class="om-btn om-btn-primary" id="om-auto-delete-save"><i class="fa-solid fa-floppy-disk"></i> Lưu cài đặt</button>
            </div>
        </div>
    </div>

    <!-- ====== MODAL: XEM PHIẾU SOẠN NHÂN VIÊN KHO ĐÃ CHỐT ======
         Không sửa được ở đây — admin chỉ xem rồi quyết định có lấy số của kho ghi
         đè lên đơn hay không ("Cập nhật đơn hàng"). -->
    <div class="om-modal-mask" id="om-picking-modal" style="display:none;">
        <div class="om-modal-box om-picking-box">
            <span class="om-modal-close" id="om-picking-close">&times;</span>
            <h3 class="om-modal-title"><i class="fa-solid fa-dolly"></i> Phiếu soạn — <span id="om-picking-cust"></span></h3>
            <p class="om-modal-hint" id="om-picking-state"></p>
            <table class="om-items om-picking-table">
                <thead>
                    <tr>
                        <th class="text-left">Tên sản phẩm</th>
                        <th>Đơn đặt</th>
                        <th>Thực soạn</th>
                        <th>Chung kiện</th>
                        <th>Chênh lệch</th>
                    </tr>
                </thead>
                <tbody id="om-picking-body"></tbody>
            </table>
            <div class="om-picking-kien" id="om-picking-kien"></div>
            <div class="om-picking-removed" id="om-picking-removed" style="display:none;">
                <div class="om-picking-removed-head"><i class="fa-solid fa-trash-can"></i> Nhân viên đã gỡ khỏi phiếu — bấm để cho vào lại</div>
                <div id="om-picking-removed-list"></div>
            </div>
            <div class="om-modal-foot">
                <span class="om-picking-sum" id="om-picking-sum"></span>
                <button type="button" class="om-btn om-btn-primary" id="om-picking-sync">
                    <i class="fa-solid fa-rotate"></i> Cập nhật đơn hàng
                </button>
            </div>
        </div>
    </div>

    <!-- ====== MODAL: XÁC NHẬN "ĐÃ BỐC" TRƯỚC KHI XUẤT KHO ====== -->
    <div class="om-modal-mask" id="om-outbound-confirm-modal" style="display:none;">
        <div class="om-modal-box om-outbound-confirm-box">
            <span class="om-modal-close" id="om-outbound-confirm-close">&times;</span>
            <h3 class="om-modal-title"><i class="fa-solid fa-truck-ramp-box"></i> Xác nhận xuất kho</h3>
            <p class="om-modal-hint">Đơn này chưa đánh dấu "Đã bốc". Tiếp tục sẽ tự động đánh dấu "Đã bốc" và chuyển sang phiếu xuất kho bán hàng.</p>
            <div class="om-modal-foot">
                <button type="button" class="om-btn om-btn-ghost" id="om-outbound-confirm-cancel">Hủy</button>
                <button type="button" class="om-btn om-btn-primary" id="om-outbound-confirm-ok">OK, đã bốc</button>
            </div>
        </div>
    </div>

    <script src="https://cdnjs.cloudflare.com/ajax/libs/html2canvas/1.4.1/html2canvas.min.js"></script>
    <script src="<?php echo asset_ver('public/js/order_management/branch_orders.js'); ?>"></script>
    <script src="<?php echo asset_ver('public/js/shared/app_shell.js'); ?>"></script>
</body>

</html>
