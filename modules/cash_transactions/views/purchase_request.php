<?php
/**
 * View "Đề nghị mua đồ" (cash_transactions/purchase_request) — thiết kế TẬP TRUNG MOBILE (khung chữ nhật dọc).
 * Nghiệp vụ: libraries/purchase_request.php · JS: public/js/cash_transactions/purchase_request.js
 *
 * 3 tab trong cùng 1 view:
 *   Lập đề nghị  — ô tìm/chọn hạng mục (hoặc "+ Thêm mục mua" = thêm thẳng vào danh sách đề nghị)
 *                  → mỗi hạng mục 1 card (SL + đơn vị) → Hủy / Lưu nháp / Gửi đề nghị
 *   Đơn của tôi  — quản lý các đơn mình đã lập (nháp, chờ duyệt, đã đặt mua, bị từ chối)
 *   Duyệt        — CHỈ ADMIN: chọn 1/nhiều đề nghị → gom thành 1 danh sách → sao chép ảnh → xác nhận đặt mua
 */
$catalog     = (isset($catalog) && is_array($catalog)) ? $catalog : [];
$counts      = (isset($counts) && is_array($counts)) ? $counts : ['mine_open' => 0, 'review' => 0];
$is_approver = !empty($is_approver);
$can_edit    = !isset($can_edit) || $can_edit;
$start_tab   = isset($start_tab) ? (string) $start_tab : '';
$me          = (isset($me) && is_array($me)) ? $me : ['id' => 0, 'name' => ''];
?>
<!DOCTYPE html>
<html lang="vi">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Đề nghị mua đồ</title>
    <link rel="icon" href="public/images/logo/logo_vat_png.png" type="image/png">
    <link rel="stylesheet" href="<?php echo asset_ver('public/css/reset.css'); ?>">
    <link rel="stylesheet" href="<?php echo asset_ver('public/css/all.css'); ?>">
    <link rel="stylesheet" href="<?php echo asset_ver('public/css/global.css'); ?>">
    <link rel="stylesheet" href="<?php echo asset_ver('public/css/cash_transactions/purchase_request.css'); ?>">
    <link rel="stylesheet" href="<?php echo asset_ver('public/css/shared/app_shell.css'); ?>">
</head>

<body>
    <div id="wrapper" class="pr-page">
        <?php get_sidebar('app'); ?>
        <?php get_header('app'); ?>
        <div class="wp-header-content">
            <div class="content pr-content">

                <div class="pr-tabs" role="tablist">
                    <button type="button" class="pr-tab" data-tab="new" role="tab">
                        <i class="fa-solid fa-cart-plus"></i><span>Lập đề nghị</span>
                    </button>
                    <button type="button" class="pr-tab" data-tab="mine" role="tab">
                        <i class="fa-solid fa-clipboard-list"></i><span>Đơn của tôi</span>
                        <em class="pr-badge" id="pr-badge-mine" hidden></em>
                    </button>
                    <?php if ($is_approver): ?>
                    <button type="button" class="pr-tab" data-tab="review" role="tab">
                        <i class="fa-solid fa-stamp"></i><span>Duyệt</span>
                        <em class="pr-badge pr-badge-alert" id="pr-badge-review" hidden></em>
                    </button>
                    <?php endif; ?>
                </div>

                <!-- ============ TAB: LẬP ĐỀ NGHỊ ============ -->
                <section class="pr-pane" id="pr-pane-new" hidden>
                    <div class="pr-editing" id="pr-editing" hidden></div>

                    <?php if (!$can_edit): ?>
                        <div class="pr-readonly-note"><i class="fa-solid fa-lock"></i> Bạn chỉ có quyền xem — không thể lập hoặc gửi đề nghị.</div>
                    <?php endif; ?>

                    <!-- Ô đôi: tìm HOẶC chọn từ danh sách -->
                    <div class="pr-pick">
                        <div class="pr-pick-search">
                            <i class="fa-solid fa-magnifying-glass"></i>
                            <input type="text" id="pr-search" class="pr-input" placeholder="Tìm hạng mục cần mua…" autocomplete="off">
                            <ul class="pr-suggest" id="pr-suggest" hidden></ul>
                        </div>
                        <button type="button" class="pr-pick-list" id="pr-open-list" title="Chọn từ danh sách đề nghị">
                            <i class="fa-solid fa-list"></i><span>Chọn</span>
                        </button>
                    </div>

                    <div class="pr-subbar">
                        <button type="button" class="pr-link-btn" id="pr-add-item"><i class="fa-solid fa-plus"></i> Thêm mục mua</button>
                        <label class="pr-urgent"><input type="checkbox" id="pr-urgent"><span><i class="fa-solid fa-bolt"></i> Cần gấp</span></label>
                    </div>

                    <div class="pr-cards" id="pr-cards"></div>
                    <div class="pr-empty" id="pr-cards-empty">
                        <i class="fa-solid fa-basket-shopping"></i>
                        <p>Chưa có mục nào.<br>Tìm hoặc bấm <b>Chọn</b> để thêm hạng mục cần mua.</p>
                    </div>

                    <div class="pr-note-wrap" id="pr-note-wrap" hidden>
                        <label for="pr-note">Ghi chú chung <span class="pr-opt">(không bắt buộc)</span></label>
                        <textarea id="pr-note" class="pr-input" rows="2" maxlength="500" placeholder="Vd: cần trước thứ Sáu…"></textarea>
                    </div>
                </section>

                <!-- ============ TAB: ĐƠN CỦA TÔI ============ -->
                <section class="pr-pane" id="pr-pane-mine" hidden>
                    <div class="pr-list" id="pr-mine-list"></div>
                    <div class="pr-pager-wrap" id="pr-mine-pager"></div>
                </section>

                <!-- ============ TAB: DUYỆT (admin) ============ -->
                <?php if ($is_approver): ?>
                <section class="pr-pane" id="pr-pane-review" hidden>
                    <div class="pr-seg" id="pr-review-seg">
                        <button type="button" class="pr-seg-btn is-on" data-mode="submitted">Chờ duyệt</button>
                        <button type="button" class="pr-seg-btn" data-mode="done">Đã xử lý (30 ngày)</button>
                    </div>
                    <label class="pr-selall" id="pr-selall-wrap" hidden>
                        <input type="checkbox" id="pr-selall"> <span>Chọn tất cả</span>
                    </label>
                    <div class="pr-list" id="pr-review-list"></div>
                    <div class="pr-pager-wrap" id="pr-review-pager"></div>
                </section>
                <?php endif; ?>

                <datalist id="pr-units">
                    <option value="bịch"><option value="cái"><option value="hộp"><option value="chai"><option value="cuộn">
                    <option value="gói"><option value="kg"><option value="thùng"><option value="bộ"><option value="ram"><option value="cây">
                </datalist>
            </div>
        </div>

        <!-- Chân cố định theo tab đang mở -->
        <div class="pr-footer" id="pr-footer-new" hidden>
            <button type="button" class="pr-btn pr-btn-ghost" id="pr-cancel">Hủy</button>
            <button type="button" class="pr-btn pr-btn-outline" id="pr-save-draft">Lưu nháp</button>
            <button type="button" class="pr-btn pr-btn-primary" id="pr-submit"><i class="fa-solid fa-paper-plane"></i> Gửi đề nghị</button>
        </div>
        <?php if ($is_approver): ?>
        <div class="pr-footer" id="pr-footer-review" hidden>
            <button type="button" class="pr-btn pr-btn-primary pr-btn-block" id="pr-batch" disabled>
                <i class="fa-solid fa-layer-group"></i><span>Gom &amp; gửi đặt mua (<span id="pr-batch-n">0</span>)</span>
            </button>
        </div>
        <?php endif; ?>
    </div>

    <script>
        window.PR_BOOT = <?php echo json_encode([
            'catalog'    => $catalog,
            'print'      => (isset($print) && is_array($print)) ? $print : [],
            'counts'     => $counts,
            'isApprover' => $is_approver,
            'canEdit'    => (bool) $can_edit,
            'canManageCats' => !empty($can_manage_cats),
            'startTab'   => $start_tab,
            'me'         => $me,
        ], JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP); ?>;
    </script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/html2canvas/1.4.1/html2canvas.min.js"></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/jspdf/2.5.1/jspdf.umd.min.js"></script>
    <script src="<?php echo asset_ver('public/js/cash_transactions/pr_common.js'); ?>"></script>
    <script src="<?php echo asset_ver('public/js/cash_transactions/purchase_request.js'); ?>"></script>
    <script src="<?php echo asset_ver('public/js/shared/app_shell.js'); ?>"></script>
</body>

</html>
