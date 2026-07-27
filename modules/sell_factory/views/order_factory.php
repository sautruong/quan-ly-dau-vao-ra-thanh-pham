<?php

/** @var array $product_groups */
/** @var array $categories */
/** @var array $history */
/** @var array|null $last_branch */
$product_groups = isset($product_groups) ? $product_groups : [];
$categories     = isset($categories) && is_array($categories) ? $categories : [];
$history        = isset($history) ? $history : ['rows' => [], 'total_pages' => 0, 'page' => 1];
$last_branch    = isset($last_branch) && is_array($last_branch) ? $last_branch : null;

/* ----- Gom nhóm theo tên để tách "TRÀ" và 4 nhóm còn lại ----- */
$by_name = [];
foreach ($product_groups as $g) {
    $by_name[mb_strtoupper(trim((string) $g['category_name']), 'UTF-8')] = $g;
}
$tea = $by_name['TRÀ'] ?? null;
if ($tea === null && !empty($product_groups)) {
    // Fallback: nhóm nhiều sản phẩm nhất coi như TRÀ.
    usort($product_groups, fn($a, $b) => count($b['products']) <=> count($a['products']));
    $tea = $product_groups[0];
    $by_name[mb_strtoupper(trim((string) $tea['category_name']), 'UTF-8')] = $tea;
}
$tea_key = $tea ? mb_strtoupper(trim((string) $tea['category_name']), 'UTF-8') : '';

// 4 nhóm dưới theo thứ tự yêu cầu; nhóm lạ (nếu có) nối vào cuối.
$bottom_order = ['BỘT', 'ĐƯỜNG/SỐT', 'SIRO TRÁI CÂY', 'CÀ PHÊ/THẢO MỘC'];
$bottom = [];
foreach ($bottom_order as $nm) {
    if (isset($by_name[$nm])) { $bottom[] = $by_name[$nm]; }
}
foreach ($by_name as $k => $g) {
    if ($k === $tea_key) continue;
    if (!in_array($k, $bottom_order, true)) { $bottom[] = $g; }
}

/* ----- Chia 1 nhóm thành N cột (ceil đều, cột cuối nhận phần dư) ----- */
if (!function_exists('of_split_columns')) {
    function of_split_columns($items, $cols = 4)
    {
        $n   = count($items);
        $per = (int) ceil($n / max(1, $cols));
        $per = max(1, $per);
        $out = [];
        for ($c = 0; $c < $cols; $c++) {
            $out[$c] = array_slice($items, $c * $per, $per);
        }
        return $out;
    }
}

/* ----- Render 1 dòng sản phẩm (dùng chung TRÀ + 4 nhóm dưới) ----- */
if (!function_exists('of_render_product')) {
    function of_render_product($p, $category_id)
    {
        $pid   = (int) $p['product_id'];
        $name  = htmlspecialchars((string) $p['product_name'], ENT_QUOTES, 'UTF-8');
        $unit  = htmlspecialchars((string) $p['unit'], ENT_QUOTES, 'UTF-8');
        $qty   = (int) $p['qt_fgi'];
        $inv   = htmlspecialchars((string) $p['inventory_text'], ENT_QUOTES, 'UTF-8');
        $skey  = htmlspecialchars(mb_strtolower((string) $p['product_name'], 'UTF-8'), ENT_QUOTES, 'UTF-8');
        echo '<div class="of-prod product-item btn-order-product" data-product-id="' . $pid . '"'
            . ' data-product-name="' . $name . '" data-unit="' . $unit . '"'
            . ' data-category-id="' . (int) $category_id . '" data-search="' . $skey . '"'
            . ' title="Thêm vào giỏ hàng">'
            . '<span class="of-prod-name">' . $name . ' <b>- ' . $qty . '</b></span>'
            . '<span class="of-prod-lead"></span>'
            . '<span class="of-prod-inv">' . $inv . '</span>'
            . '</div>';
    }
}
?>
<!DOCTYPE html>
<html lang="vi">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Đặt hàng nhà máy</title>
    <link rel="icon" href="public/images/logo/logo_vat_png.png" type="image/png">
    <link rel="stylesheet" href="<?php echo asset_ver('public/css/reset.css'); ?>">
    <link rel="stylesheet" href="<?php echo asset_ver('public/css/all.css'); ?>">
    <link rel="stylesheet" href="<?php echo asset_ver('public/css/global.css'); ?>">
    <link rel="stylesheet" href="<?php echo asset_ver('public/css/sell-factory/order-factory.css'); ?>">
    <link rel="stylesheet" href="<?php echo asset_ver('public/css/shared/app_shell.css'); ?>">
    <script src="<?php echo asset_ver('public/js/jquery-4.0.0.js'); ?>"></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/html2canvas/1.4.1/html2canvas.min.js"></script>
</head>

<body>
    <div id="wrapper">
        <?php get_sidebar('app'); ?>
        <?php get_header('app'); ?>

        <div class="of-page">
            <nav class="main-menu of-tabs">
                <a href="?mod=sell_factory&controllers=sell_factory&action=order_factory" class="active">TỒN THÀNH PHẨM NHÀ MÁY</a>
                <a href="?mod=sell_factory&controllers=sell_factory&action=production_forecast">KHSX DỰ KIẾN</a>
                <a href="?mod=sell_factory&controllers=sell_factory&action=order_history">LỊCH SỬ ĐẶT HÀNG</a>
            </nav>

            <div class="of-toolbar">
                <div class="of-search">
                    <i class="fa-solid fa-magnifying-glass"></i>
                    <input type="text" id="of-search-input" autocomplete="off"
                           placeholder="Tìm sản phẩm theo tên...">
                </div>
                <span class="of-result-info" id="of-result-info"></span>

                <div class="of-toolbar-actions">
                    <button type="button" class="of-tool-btn" id="of-setting-btn" title="Cài đặt thứ tự hiển thị">
                        <i class="fa-solid fa-gear"></i>
                    </button>

                    <div class="of-cart" id="of-cart">
                        <button type="button" class="of-tool-btn of-cart-btn" id="of-cart-btn" title="Giỏ hàng">
                            <i class="fa-solid fa-cart-shopping"></i>
                            <span class="of-cart-count" id="of-cart-count">0</span>
                        </button>
                        <div class="of-cart-dropdown" id="of-cart-dropdown">
                            <button type="button" class="of-cart-current" id="of-cart-current">
                                <span><i class="fa-solid fa-receipt"></i> Đơn hàng hiện tại</span>
                                <span class="of-cart-cur-count" id="of-cart-cur-count">0</span>
                            </button>
                            <div class="of-cart-prev">
                                <div class="of-cart-prev-head">Trước đó</div>
                                <ul class="of-recent-list" id="of-recent-list">
                                    <?php foreach ($history['rows'] as $h):
                                        $d = date('d/m/Y', strtotime($h['created_at']));
                                    ?>
                                        <li>
                                            <a href="#" class="btn-reorder of-recent-desc" data-id="<?= (int) $h['id'] ?>">Đặt hàng <?= $d ?> — <?= htmlspecialchars((string) $h['description'], ENT_QUOTES, 'UTF-8') ?></a>
                                            <span class="of-recent-del btn-delete-history" data-id="<?= (int) $h['id'] ?>" title="Xóa đơn">&times;</span>
                                        </li>
                                    <?php endforeach; ?>
                                    <?php if (empty($history['rows'])): ?>
                                        <li class="of-recent-empty">Chưa có đơn nào.</li>
                                    <?php endif; ?>
                                </ul>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <div class="of-inventory" id="of-inventory">
                <!-- ============ KHỐI TRÀ : 4 cột ============ -->
                <?php if ($tea && !empty($tea['products'])): ?>
                    <section class="of-block of-block-tea">
                        <h2 class="of-block-title"><?= htmlspecialchars((string) $tea['category_name'], ENT_QUOTES, 'UTF-8') ?></h2>
                        <div class="of-cols of-cols-4">
                            <?php foreach (of_split_columns($tea['products'], 4) as $col): ?>
                                <div class="of-col">
                                    <?php foreach ($col as $p) of_render_product($p, $tea['category_id']); ?>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    </section>
                <?php endif; ?>

                <!-- ============ 4 NHÓM CÒN LẠI : mỗi nhóm 1 cột ============ -->
                <?php if (!empty($bottom)): ?>
                    <section class="of-block of-block-rest">
                        <div class="of-cols of-cols-4 of-cols-groups">
                            <?php foreach ($bottom as $g): ?>
                                <div class="of-col of-group-col">
                                    <h2 class="of-group-title"><?= htmlspecialchars((string) $g['category_name'], ENT_QUOTES, 'UTF-8') ?></h2>
                                    <?php foreach ($g['products'] as $p) of_render_product($p, $g['category_id']); ?>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    </section>
                <?php endif; ?>

                <?php if (empty($product_groups)): ?>
                    <p class="of-empty">Chưa có sản phẩm nào còn tồn kho.</p>
                <?php endif; ?>

                <!-- SP đang hết: chỉ render khi search, để user vẫn đặt được -->
                <div class="of-out-stock" id="of-out-stock" style="display:none;"></div>

                <p class="of-empty" id="of-empty-search" style="display:none;">Không có sản phẩm phù hợp.</p>
            </div>
        </div>

    </div>

    <!-- ============ MODAL : ĐƠN HIỆN TẠI (order-factory) ============ -->
    <div class="modal-mask of-cart-modal" id="modal-cart" style="display:none;">
        <div class="modal-box of-cart-box">
            <span class="modal-close" data-close="modal-cart">&times;</span>
            <div class="of-cart-head">
                <h3><i class="fa-solid fa-cart-shopping"></i> Đơn hiện tại</h3>
            </div>

            <div class="of-cart-body">
                <div class="wp-search-branch">
                    <label for="brand">Chi nhánh:</label>
                    <input type="text" id="brand" autocomplete="off" placeholder="Tìm tên chi nhánh..."
                           value="<?= htmlspecialchars((string) ($last_branch['name'] ?? ''), ENT_QUOTES, 'UTF-8') ?>">
                    <input type="hidden" id="brand_id" value="<?= (int) ($last_branch['id'] ?? 0) ?: '' ?>">
                    <ul class="dropdown-suggest" id="brand-suggest"></ul>
                </div>

                <div class="of-note-field">
                    <label for="order-note">Ghi chú đơn hàng:</label>
                    <textarea id="order-note" rows="2" placeholder="Ghi chú cho nhà máy"></textarea>
                </div>

                <div class="wp-search-goods">
                    <i class="fa-brands fa-sistrix"></i>
                    <input type="text" id="search-goods" autocomplete="off" placeholder="Thêm sản phẩm cần đặt">
                    <ul class="dropdown-suggest" id="goods-suggest"></ul>
                </div>

                <div class="wp-list-order">
                    <table id="order-table">
                        <thead>
                            <tr>
                                <td>Tên sản phẩm</td>
                                <td>Đơn vị</td>
                                <td>Số lượng</td>
                                <td>Khối lượng (kg)</td>
                                <td></td>
                            </tr>
                        </thead>
                        <tbody></tbody>
                    </table>

                    <div class="wp-total">
                        <div class="weight-total">
                            <div class="label"><p>Tổng khối lượng:</p></div>
                            <div class="result"><span id="weight-total-result">0 kg</span></div>
                        </div>
                        <div class="value-total">
                            <div class="label"><p>GTHH:</p></div>
                            <div class="result"><span id="value-total-result">0 đ</span></div>
                        </div>
                    </div>

                    <div class="wp-button">
                        <button class="share-factory" id="btn-share-factory">Gửi nhà máy</button>
                        <button class="share-zalo" id="btn-share-zalo">Share Zalo</button>
                    </div>

                    <div class="order-inv-status" id="order-inv-status" hidden></div>
                </div>
            </div>
        </div>
    </div>

    <!-- ============ MODAL : CÀI ĐẶT THỨ TỰ HIỂN THỊ ============ -->
    <div class="modal-mask of-setting-modal" id="modal-setting" style="display:none;">
        <div class="modal-box of-setting-box">
            <span class="modal-close" data-close="modal-setting">&times;</span>
            <div class="of-setting-head">
                <h3><i class="fa-solid fa-gear"></i> Thứ tự hiển thị sản phẩm</h3>
                <p class="of-setting-hint">Nhập số thứ tự (1, 2, 3…) — số nhỏ hiện trước; số trùng xếp theo A→B; bỏ trống xếp cuối. Giúp gộp các sản phẩm tương đồng cạnh nhau.</p>
            </div>
            <div class="of-setting-tools">
                <input type="text" id="of-setting-search" autocomplete="off" placeholder="Lọc theo tên sản phẩm...">
            </div>
            <div class="of-setting-list" id="of-setting-list">
                <div class="of-setting-loading">Đang tải…</div>
            </div>
            <div class="of-setting-foot">
                <button type="button" class="of-btn-save" id="of-setting-save">Lưu thứ tự</button>
            </div>
        </div>
    </div>

    <!-- ============== MODAL : PRODUCT INFO ============== -->
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

    <!-- ============== MODAL : SHARE ZALO ============== -->
    <div class="modal-mask" id="modal-zalo" style="display:none;">
        <div class="modal-box">
            <span class="modal-close" data-close="modal-zalo">&times;</span>
            <div id="zalo-capture" class="zalo-capture">
                <div class="zalo-customer"><strong>Tên khách hàng:</strong> <span id="zalo-customer-name"></span></div>
                <div class="zalo-title">ĐẶT HÀNG NHÀ MÁY</div>
                <table class="zalo-table">
                    <thead>
                        <tr>
                            <th>Tên sản phẩm</th>
                            <th>Đơn vị</th>
                            <th>Số lượng</th>
                            <th>Khối lượng (kg)</th>
                        </tr>
                    </thead>
                    <tbody id="zalo-body"></tbody>
                </table>
                <div class="zalo-weight-total">
                    Tổng khối lượng: <span id="zalo-weight-total-value">0</span> kg
                </div>
            </div>
            <div class="modal-zalo-footer">
                <button class="btn-capture-zalo" id="btn-capture-zalo">Chụp hóa đơn</button>
            </div>
        </div>
    </div>

    <script src="<?php echo asset_ver('public/js/sell-factory/order-factory.js'); ?>"></script>
    <script src="<?php echo asset_ver('public/js/shared/app_shell.js'); ?>"></script>
</body>

</html>
