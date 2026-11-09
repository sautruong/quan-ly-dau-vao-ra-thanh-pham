<?php
/**
 * PARTIAL — View chồng lên "Đơn hàng" (khung .app-ov-* của app_shell.css).
 * Dùng CHUNG 2 nơi, cùng markup + cùng JS (public/js/sell-factory/order-factory.js):
 *   - sell_factory/order_factory : đơn đang soạn ("Đơn hiện tại")
 *   - sell_factory/order_history : sửa 1 đơn đã gửi ("Chỉnh sửa đơn hàng")
 * Khác nhau chỉ ở tiêu đề + nút chân view, JS tự đổi theo window.OF_PANEL.mode.
 *
 * Biến cần có trước khi include:
 *   $product_groups : nhóm sản phẩm còn tồn (cột trái)
 *   $last_branch    : ['id','name','short'] hoặc null
 *   $panel_mode     : 'order' (mặc định) | 'edit'
 */
$product_groups = isset($product_groups) && is_array($product_groups) ? $product_groups : [];
$last_branch    = isset($last_branch) && is_array($last_branch) ? $last_branch : null;
$panel_mode     = isset($panel_mode) ? $panel_mode : 'order';
$panel_edit     = ($panel_mode === 'edit');
// Cột đang ẩn tạm — cá nhân hóa theo user (xem sf_get_hidden_cols/save_hidden_colsAction).
$hidden_cols  = isset($hidden_cols) && is_array($hidden_cols) ? $hidden_cols : [];
$hideWeight   = in_array('weight', $hidden_cols, true);
$hideStock    = in_array('stock', $hidden_cols, true);
?>
<div class="app-ov-view of-order-view" id="of-order-view" style="display:none;" data-mode="<?= $panel_edit ? 'edit' : 'order' ?>"
     data-hidden-cols="<?= htmlspecialchars(implode(',', $hidden_cols), ENT_QUOTES, 'UTF-8') ?>">
    <div class="app-ov-head">
        <h2 class="app-ov-title">
            <i class="fa-solid <?= $panel_edit ? 'fa-pen-to-square' : 'fa-cart-shopping' ?>"></i>
            <span id="of-order-title"><?= $panel_edit ? 'Chỉnh sửa đơn hàng' : 'Đơn hiện tại' ?></span>
        </h2>
        <button type="button" class="app-ov-close" id="of-order-close"
                title="<?= $panel_edit ? 'Đóng, về Lịch sử đặt hàng' : 'Đóng, về Tồn thành phẩm nhà máy' ?>">&times;</button>
    </div>

    <div class="app-ov-body of-ov-2col">
        <!-- CỘT TRÁI: tồn kho nhà máy dạng dọc — bấm 1 dòng là bắn thẳng sang đơn bên phải
             (đỡ phải đóng view ra lưới tồn rồi mở lại khi khách muốn đặt thêm). -->
        <aside class="of-ov-stock" id="of-ov-stock">
            <div class="of-ov-stock-head">
                <div class="of-ov-stock-search">
                    <i class="fa-solid fa-magnifying-glass"></i>
                    <input type="text" id="of-stock-search" autocomplete="off" placeholder="Tìm sản phẩm trong kho...">
                    <button type="button" class="of-input-clear" data-clear="of-stock-search" title="Xóa từ khóa">&times;</button>
                </div>
            </div>
            <div class="of-ov-stock-list" id="of-ov-stock-list">
                <?php foreach ($product_groups as $g): ?>
                    <?php if (empty($g['products'])) continue; ?>
                    <div class="of-stock-group">
                        <div class="of-stock-group-title"><?= htmlspecialchars((string) $g['category_name'], ENT_QUOTES, 'UTF-8') ?></div>
                        <?php foreach ($g['products'] as $p):
                            $skey = htmlspecialchars(mb_strtolower((string) $p['product_name'], 'UTF-8'), ENT_QUOTES, 'UTF-8');
                        ?>
                            <div class="of-stock-row" data-product-id="<?= (int) $p['product_id'] ?>" data-search="<?= $skey ?>"
                                 title="<?= htmlspecialchars((string) $p['product_name'] . ' — tồn ' . (string) $p['inventory_text'] . ' (bấm để thêm vào đơn)', ENT_QUOTES, 'UTF-8') ?>">
                                <span class="of-stock-name"><?= htmlspecialchars((string) $p['product_name'], ENT_QUOTES, 'UTF-8') ?>
                                    <b>- <?= (int) $p['qt_fgi'] ?></b></span>
                                <span class="of-stock-lead"></span>
                                <span class="of-stock-inv"><?= htmlspecialchars((string) $p['inventory_text'], ENT_QUOTES, 'UTF-8') ?></span>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php endforeach; ?>
                <!-- SP ĐANG HẾT / TỒN ÂM: chỉ do JS đổ vào khi user gõ từ khoá ở ô tìm trên (bấm vẫn thêm vào đơn được). -->
                <div class="of-stock-group of-stock-out" id="of-ov-stock-out" style="display:none;"></div>
            </div>
            <p class="of-stock-empty" id="of-stock-empty" hidden>Không có sản phẩm phù hợp.</p>
        </aside>

        <!-- CỘT PHẢI: đơn hàng -->
        <div class="of-ov-main">
            <div class="of-ov-fields">
                <div class="wp-search-branch">
                    <input type="text" id="brand" autocomplete="off" placeholder="Chi nhánh..."
                           value="<?= htmlspecialchars((string) (($last_branch['short'] ?? '') !== '' ? $last_branch['short'] : ($last_branch['name'] ?? '')), ENT_QUOTES, 'UTF-8') ?>"
                           <?= $panel_edit ? 'readonly' : '' ?>>
                    <input type="hidden" id="brand_id" value="<?= (int) ($last_branch['id'] ?? 0) ?: '' ?>">
                    <!-- Tên đầy đủ vẫn được gửi lên server (dữ liệu đơn giữ nguyên như cũ); ô trên chỉ hiển thị tên viết tắt. -->
                    <input type="hidden" id="brand_full" value="<?= htmlspecialchars((string) ($last_branch['name'] ?? ''), ENT_QUOTES, 'UTF-8') ?>">
                    <ul class="dropdown-suggest" id="brand-suggest"></ul>
                </div>

                <div class="of-note-field">
                    <textarea id="order-note" rows="1" placeholder="Ghi chú cho nhà máy..."></textarea>
                </div>
            </div>

            <!-- 1 hàng: ô tìm SP (trái) — 2 nút ẩn/hiện cột + "Lấy số đặt gần đây" (phải). -->
            <div class="of-ov-tablebar">
                <div class="wp-search-goods">
                    <i class="fa-brands fa-sistrix"></i>
                    <input type="text" id="search-goods" autocomplete="off" placeholder="Thêm sản phẩm cần đặt">
                    <button type="button" class="of-input-clear" data-clear="search-goods" title="Xóa từ khóa">&times;</button>
                    <ul class="dropdown-suggest" id="goods-suggest"></ul>
                </div>
                <div class="of-ov-tablebar-actions">
                    <button type="button" class="of-ov-btn of-col-show" data-col="weight"<?= $hideWeight ? '' : ' hidden' ?>>
                        <i class="fa-regular fa-eye"></i> Khối lượng (kg)
                    </button>
                    <button type="button" class="of-ov-btn of-col-show" data-col="stock"<?= $hideStock ? '' : ' hidden' ?>>
                        <i class="fa-regular fa-eye"></i> Tồn nhà máy
                    </button>
                    <button type="button" class="of-ov-btn" id="of-recent-qty-btn">
                        <i class="fa-solid fa-clock-rotate-left"></i> Lấy số đặt gần đây
                    </button>
                    <span class="of-recent-confirm" id="of-recent-confirm" hidden>
                        <button type="button" class="of-ov-btn is-ok" id="of-recent-qty-ok"><i class="fa-solid fa-check"></i> Đồng ý</button>
                        <button type="button" class="of-ov-btn is-cancel" id="of-recent-qty-cancel"><i class="fa-solid fa-xmark"></i> Hủy</button>
                    </span>
                </div>
            </div>

            <div class="app-ov-scroll wp-list-order">
                <table id="order-table">
                    <thead>
                        <tr>
                            <td>Tên sản phẩm</td>
                            <td>Đơn vị</td>
                            <td>Số lượng đặt</td>
                            <td class="of-col-weight"<?= $hideWeight ? ' style="display:none"' : '' ?>>Khối lượng (kg)
                                <button type="button" class="of-col-hide" data-col="weight" title="Ẩn tạm cột này"><i class="fa-regular fa-eye-slash"></i></button>
                            </td>
                            <td class="of-col-stock"<?= $hideStock ? ' style="display:none"' : '' ?>>Tồn nhà máy
                                <button type="button" class="of-col-hide" data-col="stock" title="Ẩn tạm cột này"><i class="fa-regular fa-eye-slash"></i></button>
                            </td>
                            <td></td>
                        </tr>
                    </thead>
                    <tbody></tbody>
                </table>
            </div>

            <div class="order-inv-status" id="order-inv-status" hidden></div>
        </div>
    </div>

    <div class="app-ov-foot">
        <div class="of-ov-totals">
            <div class="weight-total"><p>Tổng khối lượng:</p><span id="weight-total-result">0 kg</span></div>
            <div class="value-total"><p>GTHH:</p><span id="value-total-result">0 đ</span></div>
        </div>
        <div class="wp-button">
            <?php if ($panel_edit): ?>
                <button class="share-factory" id="btn-save-edit"><i class="fa-solid fa-floppy-disk"></i> Lưu thay đổi</button>
            <?php else: ?>
                <button class="share-factory" id="btn-share-factory">Gửi nhà máy</button>
            <?php endif; ?>
            <button class="share-zalo" id="btn-share-zalo">Share Zalo</button>
            <button class="share-chat" id="btn-share-chat"><i class="fa-regular fa-comments"></i> Gửi qua chat</button>
        </div>
    </div>
</div>
