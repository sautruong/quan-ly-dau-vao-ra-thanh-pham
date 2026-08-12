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
    <link rel="stylesheet" href="<?php echo asset_ver('public/css/shared/history_filter.css'); ?>">
    <link rel="stylesheet" href="<?php echo asset_ver('public/css/warehouse/picking_task.css'); ?>">
    <script src="<?php echo asset_ver('public/js/jquery-4.0.0.js'); ?>"></script>
</head>

<body>
    <div id="wrapper">
        <?php get_sidebar('app'); ?>
        <?php get_header('app'); ?>

        <div class="wpk-page" style="--wpk-accent: <?php echo wpk_esc($accent); ?>;">


            <!-- Chọn phiếu đang soạn — JS dựng để bộ đếm "PLCT 3/9" cập nhật ngay khi tích,
                 và đổi phiếu không phải tải lại cả trang. -->
            <div class="wpk-chips" id="wpk-chips"></div>

            <p class="wpk-empty" id="wpk-empty"<?php echo $slip ? ' hidden' : ''; ?>>Chưa có yêu cầu soạn hàng nào</p>

                <div class="wpk-slip" id="wpk-slip" data-slip-id="<?php echo $slip ? (int) $slip['id'] : 0; ?>"<?php echo $slip ? '' : ' hidden'; ?>>

                    <div class="wpk-info">
                        <div class="wpk-info-row"><span>Khách hàng:</span><b id="wpk-cust"><?php echo $slip ? wpk_esc(trim((string) $slip['customer_short']) !== '' ? $slip['customer_short'] : $slip['customer_name']) : ''; ?></b></div>
                        <div class="wpk-info-row"><span>Người nhận:</span><b id="wpk-receiver"><?php
                            if ($slip) {
                                $rc = trim((string) $slip['receiver']);
                                $ph = trim((string) $slip['phone']);
                                echo wpk_esc($rc . ($ph !== '' ? ' - ' . $ph : ''));
                            }
                        ?></b></div>
                        <div class="wpk-info-row"><span>Địa chỉ:</span><b id="wpk-address"><?php echo $slip ? wpk_esc($slip['address']) : ''; ?></b></div>
                    </div>

                    <div class="wpk-toolbar" id="wpk-toolbar">
                        <div class="wpk-search-box">
                            <input type="text" id="wpk-search" autocomplete="off" placeholder="Thêm sản phẩm / NVL vào phiếu...">
                            <ul class="wpk-suggest" id="wpk-suggest"></ul>
                        </div>
                    </div>

                    <div class="wpk-table-head">
                        <span id="wpk-total-label">TỔNG 0 SP</span>
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
                            <input type="text" id="wpk-note" value="<?php echo $slip ? wpk_esc($slip['note']) : ''; ?>" placeholder="Nhập ghi chú...">
                        </div>
                        <div class="wpk-foot-sum">Số kiện dự kiến: <b id="wpk-sum"></b></div>
                        <button type="button" class="wpk-btn wpk-btn-done" id="wpk-finish">
                            <i class="fa-solid fa-circle-check"></i> Soạn xong
                        </button>
                    </div>
                </div>

            <!-- ===== LỊCH SỬ SOẠN HÀNG — dùng khối lọc ngày + phân trang chung ===== -->
            <div class="wpk-history">
                <div class="history-bar">
                    <div class="history"><p>Lịch sử soạn hàng</p></div>
                    <div class="history-filter" id="history-filter">
                        <div class="hf-group hf-daterange">
                            <span class="hf-cal-icon"><i class="fa-regular fa-calendar-days"></i></span>
                            <label for="hf-date-from">Từ ngày</label>
                            <input type="date" id="hf-date-from" class="hf-date">
                            <span class="hf-arrow"><i class="fa-solid fa-arrow-right-long"></i></span>
                            <label for="hf-date-to">đến ngày</label>
                            <input type="date" id="hf-date-to" class="hf-date">
                        </div>
                        <div class="hf-group hf-rows">
                            <label for="hf-page-size">Số dòng</label>
                            <select id="hf-page-size" class="hf-select">
                                <option value="4" selected>4</option>
                                <option value="10">10</option>
                                <option value="20">20</option>
                                <option value="50">50</option>
                                <option value="100">100</option>
                            </select>
                        </div>
                        <span class="hf-count" id="hf-count"></span>
                        <button type="button" class="hf-reset" id="hf-reset" title="Bỏ tất cả bộ lọc">
                            <i class="fa-solid fa-rotate-left"></i> Bỏ lọc
                        </button>
                    </div>
                </div>
                <div class="wpk-hist-cards" id="wpk-hist-body"></div>
                <div class="hf-pager" id="wpk-hist-pager"></div>
            </div>
        </div>
    </div>

    <!-- MODAL: xem lại 1 phiếu trong lịch sử (chỉ đọc) -->
    <div class="wpk-modal-mask" id="wpk-hist-modal" hidden>
        <div class="wpk-modal-box wpk-hist-box">
            <button type="button" class="wpk-modal-close" id="wpk-hist-close">&times;</button>
            <h3 class="wpk-modal-title" id="wpk-hist-title"></h3>
            <div class="wpk-hist-meta" id="wpk-hist-meta"></div>
            <div class="wpk-hist-scroll">
                <table class="wpk-hist-items">
                    <thead><tr><td>Tên sản phẩm</td><td>Đơn đặt</td><td>Thực soạn</td><td>Kiện</td></tr></thead>
                    <tbody id="wpk-hist-items"></tbody>
                </table>
            </div>
            <div class="wpk-hist-foot" id="wpk-hist-foot"></div>
        </div>
    </div>

    <!-- MODAL: đánh số chung kiện (bấm vào tên hàng) -->
    <div class="wpk-modal-mask" id="wpk-group-modal" hidden>
        <div class="wpk-modal-box">
            <button type="button" class="wpk-modal-close" data-wpk-close="wpk-group-modal">&times;</button>
            <h3 class="wpk-modal-title"><i class="fa-solid fa-box"></i> Chung kiện số</h3>
            <p class="wpk-modal-sub" id="wpk-group-sub"></p>
            <input type="number" class="wpk-modal-input" id="wpk-group-val" min="1" step="1"
                   inputmode="numeric" placeholder="Nhập số kiện chung...">
            <div class="wpk-modal-foot">
                <button type="button" class="wpk-mbtn wpk-mbtn-ghost" id="wpk-group-clear">Bỏ chung kiện</button>
                <button type="button" class="wpk-mbtn wpk-mbtn-ok" id="wpk-group-save">Lưu</button>
            </div>
        </div>
    </div>

    <!-- MODAL: đổi số lượng bốc thực tế (bấm đúp vào ô số lượng) -->
    <div class="wpk-modal-mask" id="wpk-qty-modal" hidden>
        <div class="wpk-modal-box">
            <button type="button" class="wpk-modal-close" data-wpk-close="wpk-qty-modal">&times;</button>
            <h3 class="wpk-modal-title"><i class="fa-solid fa-pen-to-square"></i> Bốc thực tế</h3>
            <p class="wpk-modal-sub" id="wpk-qty-sub"></p>
            <div class="wpk-modal-inline">
                <input type="number" class="wpk-modal-input" id="wpk-qty-val" min="0" step="1" inputmode="decimal">
                <span class="wpk-modal-unit" id="wpk-qty-unit"></span>
            </div>
            <p class="wpk-modal-hint" id="wpk-qty-preview"></p>
            <div class="wpk-modal-foot">
                <button type="button" class="wpk-mbtn wpk-mbtn-ghost" data-wpk-close="wpk-qty-modal">Đóng</button>
                <button type="button" class="wpk-mbtn wpk-mbtn-ok" id="wpk-qty-save">Lưu</button>
            </div>
        </div>
    </div>


    <script>
        window.WPK_SLIP  = <?php echo $slip ? json_encode($slip, JSON_UNESCAPED_UNICODE) : 'null'; ?>;
        window.WPK_SLIPS = <?php echo json_encode($slips, JSON_UNESCAPED_UNICODE); ?>;
        window.WPK_ME    = <?php echo json_encode(isset($me) ? $me : ['id' => 0], JSON_UNESCAPED_UNICODE); ?>;
    </script>
    <script src="<?php echo asset_ver('public/js/warehouse/picking_task.js'); ?>"></script>
    <script src="<?php echo asset_ver('public/js/shared/app_shell.js'); ?>"></script>
</body>

</html>
