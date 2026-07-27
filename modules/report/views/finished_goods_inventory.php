<?php
/** @var array $product_groups */
$product_groups = isset($product_groups) && is_array($product_groups) ? $product_groups : [];
$is_admin  = !empty($is_admin);
$ajax_base = '?mod=report&controllers=report&action=';

/* ----- Gom nhóm theo tên để tách "TRÀ" và các nhóm còn lại (giống order_factory) ----- */
$by_name = [];
foreach ($product_groups as $g) {
    $by_name[mb_strtoupper(trim((string) $g['category_name']), 'UTF-8')] = $g;
}
$tea = $by_name['TRÀ'] ?? null;
if ($tea === null && !empty($product_groups)) {
    usort($product_groups, fn($a, $b) => count($b['products']) <=> count($a['products']));
    $tea = $product_groups[0];
    $by_name[mb_strtoupper(trim((string) $tea['category_name']), 'UTF-8')] = $tea;
}
$tea_key = $tea ? mb_strtoupper(trim((string) $tea['category_name']), 'UTF-8') : '';

$bottom_order = ['BỘT', 'ĐƯỜNG/SỐT', 'SIRO TRÁI CÂY', 'CÀ PHÊ/THẢO MỘC'];
$bottom = [];
foreach ($bottom_order as $nm) {
    if (isset($by_name[$nm])) { $bottom[] = $by_name[$nm]; }
}
foreach ($by_name as $k => $g) {
    if ($k === $tea_key) continue;
    if (!in_array($k, $bottom_order, true)) { $bottom[] = $g; }
}

/* ----- Chia 1 nhóm thành N cột ----- */
if (!function_exists('fgi_split_columns')) {
    function fgi_split_columns($items, $cols = 4)
    {
        $n   = count($items);
        $per = max(1, (int) ceil($n / max(1, $cols)));
        $out = [];
        for ($c = 0; $c < $cols; $c++) {
            $out[$c] = array_slice($items, $c * $per, $per);
        }
        return $out;
    }
}

/* ----- Render 1 dòng sản phẩm (KHÔNG giỏ hàng, chỉ xem tồn) -----
 * draggable="true" + data-category-id: cho phép kéo-giữ chuột đổi vị trí hiển thị
 * với 1 sản phẩm khác CÙNG danh mục (xem JS phần "kéo-thả cá nhân hóa"). */
if (!function_exists('fgi_render_product')) {
    function fgi_render_product($p, $category_id = 0)
    {
        $name = htmlspecialchars((string) $p['product_name'], ENT_QUOTES, 'UTF-8');
        $qty  = (int) $p['qt_fgi'];
        $inv  = htmlspecialchars((string) $p['pack_conv_text'], ENT_QUOTES, 'UTF-8');
        $skey = htmlspecialchars(mb_strtolower((string) $p['product_name'], 'UTF-8'), ENT_QUOTES, 'UTF-8');
        echo '<div class="of-prod" data-search="' . $skey . '" data-product-id="' . (int) $p['product_id'] . '" data-category-id="' . (int) $category_id . '" draggable="true" style="cursor:pointer;">'
            . '<span class="of-prod-name"><span class="of-prod-name-text">' . $name . '</span> <b>- ' . $qty . '</b></span>'
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
    <title>Tồn thành phẩm</title>
    <link rel="icon" href="public/images/logo/logo_vat_png.png" type="image/png">
    <link rel="stylesheet" href="<?php echo asset_ver('public/css/reset.css'); ?>">
    <link rel="stylesheet" href="<?php echo asset_ver('public/css/all.css'); ?>">
    <link rel="stylesheet" href="<?php echo asset_ver('public/css/global.css'); ?>">
    <link rel="stylesheet" href="<?php echo asset_ver('public/css/sell-factory/order-factory.css'); ?>">
    <link rel="stylesheet" href="<?php echo asset_ver('public/css/shared/app_shell.css'); ?>">
    <!-- Chụp ảnh tồn kho (header + content) để Ctrl+V dán sang app khác -->
    <script src="https://cdnjs.cloudflare.com/ajax/libs/html2canvas/1.4.1/html2canvas.min.js"></script>
    <style>
        /* Cho cả trang cuộn dọc (header cố định trên cùng nhờ .has-sider). */
        #wrapper.has-sider > .content.of-page { overflow-y: auto; overflow-x: hidden; }
        .of-inventory { max-height: none; overflow: visible; }

        /* Nhóm sản phẩm đã lâu chưa bán */
        .fgi-unsold {
            margin-top: 6px; padding: 16px 4px 24px;
            border-top: 2px dashed var(--color-gray-300, #d1d5db);
        }
        .fgi-unsold-head {
            display: flex; align-items: center; gap: 14px; flex-wrap: wrap; margin-bottom: 14px;
        }
        .fgi-unsold-head h2 {
            margin: 0; font-size: 17px; font-weight: 700; color: #b45309; text-transform: uppercase;
        }
        .fgi-unsold-head .sub { color: #6b7280; font-size: 13px; }
        .fgi-unsold-head select {
            padding: 8px 12px; border: 1px solid #c2c8d0; border-radius: 8px; font-size: 13px; background: #fff;
        }
        .fgi-unsold-grid {
            display: grid; grid-template-columns: repeat(3, 1fr); gap: 10px 18px;
        }
        @media (max-width: 1100px) { .fgi-unsold-grid { grid-template-columns: repeat(2, 1fr); } }
        @media (max-width: 700px)  { .fgi-unsold-grid { grid-template-columns: 1fr; } }
        .fgi-unsold-card {
            border: 1px solid #f1d9b5; background: #fffaf2; border-radius: 10px; padding: 10px 12px;
        }
        .fgi-unsold-card .nm { font-weight: 600; color: #1f2733; }
        .fgi-unsold-card .cat { font-size: 12px; color: #9a6a16; margin: 2px 0 6px; }
        .fgi-unsold-card .row { display: flex; justify-content: space-between; font-size: 13px; color: #444; }
        .fgi-unsold-card .row .stock { color: #03021a; font-weight: 600; }
        .fgi-unsold-card .last { color: #b91c1c; font-weight: 600; }
        .fgi-unsold-empty, .fgi-unsold-loading { color: gray; padding: 16px; text-align: center; }
        .fgi-card-act { margin-top: 8px; text-align: right; }
        .fgi-act-btn { padding: 4px 10px; border-radius: 6px; font-size: 12px; font-weight: 600; cursor: pointer; }
        .fgi-act-stop { color: #b45309; border: 1px solid #f1d9b5; background: #fff; }
        .fgi-act-stop:hover { background: #fdeccd; }
        .fgi-act-resume { color: #096926; border: 1px solid #bfe6cd; background: #fff; }
        .fgi-act-resume:hover { background: #dcefe2; }
        .fgi-act-del { color: #b91c1c; border: 1px solid #f0c4c4; background: #fff; margin-left: 6px; }
        .fgi-act-del:hover { background: #fde2e2; }

        /* Danh sách ngưng bán (FAQ: click tiêu đề mới xổ ra) */
        .fgi-discontinued { margin-top: 6px; padding: 16px 4px 30px; border-top: 2px dashed var(--color-gray-300, #d1d5db); }
        .fgi-disc-head { display: flex; align-items: center; gap: 10px; flex-wrap: wrap; margin-bottom: 14px; cursor: pointer; user-select: none; }
        .fgi-disc-caret { color: #6b7280; transition: transform .15s; }
        .fgi-discontinued.is-open .fgi-disc-caret { transform: rotate(90deg); }
        .fgi-disc-head h2 { margin: 0; font-size: 16px; font-weight: 700; color: #6b7280; text-transform: uppercase; }
        .fgi-disc-body input { padding: 8px 12px; border: 1px solid #c2c8d0; border-radius: 8px; font-size: 13px; min-width: 220px; margin-bottom: 12px; }
        .fgi-disc-grid { display: grid; grid-template-columns: repeat(3, 1fr); gap: 10px 18px; }
        @media (max-width: 1100px) { .fgi-disc-grid { grid-template-columns: repeat(2, 1fr); } }
        @media (max-width: 700px)  { .fgi-disc-grid { grid-template-columns: 1fr; } }
        .fgi-disc-card { border: 1px solid #d8dde3; background: #fafbfc; border-radius: 10px; padding: 10px 12px; }
        .fgi-disc-card .nm { font-weight: 600; color: #1f2733; }
        .fgi-disc-card .cat { font-size: 12px; color: #6b7280; margin: 2px 0 6px; }
        .fgi-disc-card .row { display: flex; justify-content: space-between; font-size: 13px; color: #444; }
        .fgi-disc-empty, .fgi-disc-loading { color: gray; padding: 16px; text-align: center; }
    </style>
</head>

<body>
    <div id="wrapper" class="has-sider">
        <?php get_sidebar('app'); ?>
        <?php get_header('app'); ?>

        <div class="content of-page">
            <div class="of-toolbar">
                <div class="of-search">
                    <i class="fa-solid fa-magnifying-glass"></i>
                    <input type="text" id="of-search-input" autocomplete="off"
                           placeholder="Tìm sản phẩm theo tên...">
                </div>
                <span class="of-result-info" id="of-result-info"></span>

                <div class="of-toolbar-actions">
                    <button type="button" class="of-tool-btn" id="of-capture-btn" title="Chụp ảnh tồn kho (Ctrl+V dán sang app khác)">
                        <i class="fa-solid fa-camera"></i>
                    </button>
                    <button type="button" class="of-tool-btn" id="of-setting-btn" title="Cài đặt thứ tự hiển thị">
                        <i class="fa-solid fa-gear"></i>
                    </button>
                </div>
            </div>

            <div class="of-inventory" id="of-inventory">
                <?php if ($tea && !empty($tea['products'])): ?>
                    <section class="of-block of-block-tea">
                        <h2 class="of-block-title"><?= htmlspecialchars((string) $tea['category_name'], ENT_QUOTES, 'UTF-8') ?></h2>
                        <div class="of-cols of-cols-4">
                            <?php foreach (fgi_split_columns($tea['products'], 4) as $col): ?>
                                <div class="of-col">
                                    <?php foreach ($col as $p) fgi_render_product($p, $tea['category_id']); ?>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    </section>
                <?php endif; ?>

                <?php if (!empty($bottom)): ?>
                    <section class="of-block of-block-rest">
                        <div class="of-cols of-cols-4 of-cols-groups">
                            <?php foreach ($bottom as $g): ?>
                                <div class="of-col of-group-col">
                                    <h2 class="of-group-title"><?= htmlspecialchars((string) $g['category_name'], ENT_QUOTES, 'UTF-8') ?></h2>
                                    <?php foreach ($g['products'] as $p) fgi_render_product($p, $g['category_id']); ?>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    </section>
                <?php endif; ?>

                <?php if (empty($product_groups)): ?>
                    <p class="of-empty">Chưa có sản phẩm nào còn tồn kho.</p>
                <?php endif; ?>

                <p class="of-empty" id="of-empty-search" style="display:none;">Không có sản phẩm phù hợp.</p>
            </div>

            <!-- ============ Nhóm sản phẩm đã lâu chưa bán ============ -->
            <section class="fgi-unsold">
                <div class="fgi-unsold-head">
                    <h2><i class="fa-solid fa-hourglass-half"></i> Nhóm sản phẩm đã lâu chưa bán</h2>
                    <label for="fgi-unsold-months" class="sub">Còn tồn nhưng lần sản xuất gần nhất cách đây hơn:</label>
                    <select id="fgi-unsold-months">
                        <option value="1">1 tháng</option>
                        <option value="3" selected>3 tháng</option>
                        <option value="6">6 tháng</option>
                        <option value="12">1 năm</option>
                    </select>
                    <label for="fgi-unsold-datefilter" class="sub">Hiển thị:</label>
                    <select id="fgi-unsold-datefilter">
                        <option value="all">Tất cả</option>
                        <option value="dated">Chỉ SP có ngày sx</option>
                    </select>
                    <span class="sub" id="fgi-unsold-count"></span>
                </div>
                <div class="fgi-unsold-grid" id="fgi-unsold-list">
                    <div class="fgi-unsold-loading">Đang tải…</div>
                </div>
            </section>

            <!-- ============ Danh sách ngưng bán (FAQ) ============ -->
            <section class="fgi-discontinued" id="fgi-discontinued">
                <div class="fgi-disc-head" id="fgi-disc-toggle">
                    <i class="fa-solid fa-chevron-right fgi-disc-caret"></i>
                    <h2><i class="fa-solid fa-ban"></i> Danh sách ngưng bán</h2>
                    <span class="sub" id="fgi-disc-count"></span>
                </div>
                <div class="fgi-disc-body" id="fgi-disc-body" style="display:none;">
                    <input type="text" id="fgi-disc-search" autocomplete="off" placeholder="Lọc theo tên sản phẩm...">
                    <div class="fgi-disc-grid" id="fgi-disc-list">
                        <div class="fgi-disc-loading">Đang tải…</div>
                    </div>
                </div>
            </section>
        </div>
    </div>

    <!-- ============ MODAL : CÀI ĐẶT THỨ TỰ HIỂN THỊ ============ -->
    <div class="modal-mask of-setting-modal" id="modal-setting" style="display:none;">
        <div class="modal-box of-setting-box">
            <span class="modal-close" data-close="modal-setting">&times;</span>
            <div class="of-setting-head">
                <h3><i class="fa-solid fa-gear"></i> Thứ tự hiển thị sản phẩm</h3>
            </div>

            <?php if ($is_admin): ?>
            <div class="of-setting-tabs" id="of-setting-tabs">
                <button type="button" class="of-setting-tab active" data-tab="order">Thứ tự hiển thị</button>
                <button type="button" class="of-setting-tab" data-tab="stock">Điều chỉnh tồn kho</button>
            </div>
            <?php endif; ?>

            <div class="of-setting-panel" data-panel="order">
                <p class="of-setting-hint">Nhập số thứ tự (1, 2, 3…) — số nhỏ hiện trước; bỏ trống xếp cuối. Giúp gộp các sản phẩm tương đồng cạnh nhau.</p>
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

            <?php if ($is_admin): ?>
            <div class="of-setting-panel" data-panel="stock" style="display:none;">
                <p class="of-setting-hint">Sửa trực tiếp số lượng tồn kho hiện tại của sản phẩm (chỉ admin).</p>
                <div class="of-setting-tools">
                    <input type="text" id="of-stock-search" autocomplete="off" placeholder="Tìm sản phẩm theo tên...">
                </div>
                <div class="of-setting-list" id="of-stock-list">
                    <div class="of-setting-loading">Đang tải…</div>
                </div>
                <div class="of-setting-foot">
                    <button type="button" class="of-btn-save" id="of-stock-save">Cập nhật</button>
                </div>
            </div>
            <?php endif; ?>
        </div>
    </div>

    <!-- ============ MODAL : PHÂN TÍCH SẢN PHẨM ============ -->
    <div class="modal-mask fgi-analysis-modal" id="modal-analysis" style="display:none;">
        <div class="modal-box fgi-analysis-box">
            <span class="modal-close" data-close="modal-analysis">&times;</span>
            <div class="fgi-an-head">
                <h3><i class="fa-solid fa-chart-pie"></i> Phân tích sản phẩm</h3>
                <div class="fgi-an-title" id="fgi-an-name">
                    <span class="fgi-an-name-text">—</span>
                    <button type="button" class="fgi-an-name-edit" id="fgi-an-name-edit-btn" title="Sửa tên thường gọi"><i class="fa-solid fa-pen"></i></button>
                </div>
                <div class="fgi-an-cat" id="fgi-an-cat"></div>
            </div>
            <div class="fgi-an-body" id="fgi-an-body">
                <div class="fgi-an-loading">Đang tải…</div>
            </div>
            <div class="fgi-an-foot">
                <button type="button" class="of-btn-save fgi-an-excel" id="fgi-an-excel" disabled>
                    <i class="fa-solid fa-file-excel"></i> Xuất Excel
                </button>
            </div>
        </div>
    </div>

    <style>
        .fgi-analysis-box { max-width: 460px; width: 92vw; }
        .fgi-an-head { padding: 4px 0 12px; border-bottom: 1px solid #eef0f3; margin-bottom: 14px; }
        .fgi-an-head h3 { margin: 0 0 6px; font-size: 16px; color: #096926; }
        .fgi-an-title { font-size: 18px; font-weight: 700; color: #1f2733; display: flex; align-items: center; gap: 6px; }
        .fgi-an-name-text[contenteditable="true"] { outline: 1px dashed #096926; border-radius: 4px; padding: 0 4px; background: #f3faf5; }
        .fgi-an-name-edit {
            opacity: 0; transition: opacity .15s; border: none; background: transparent; color: #6b7280;
            font-size: 13px; cursor: pointer; padding: 2px 4px; flex: 0 0 auto;
        }
        .fgi-an-title:hover .fgi-an-name-edit,
        .fgi-an-title.editing .fgi-an-name-edit { opacity: 1; }
        .fgi-an-cat { font-size: 13px; color: #6b7280; margin-top: 2px; }
        .fgi-an-loading { color: gray; text-align: center; padding: 24px; }
        .fgi-an-grid { display: flex; flex-direction: column; gap: 10px; }
        .fgi-an-row {
            display: flex; align-items: center; justify-content: space-between; gap: 12px;
            padding: 11px 14px; border: 1px solid #e6e9ee; border-radius: 10px; background: #fafbfc;
        }
        .fgi-an-row .lab { font-size: 13.5px; color: #44505f; font-weight: 600; }
        .fgi-an-row .val { font-size: 15px; color: #0f5132; font-weight: 700; text-align: right; }
        .fgi-an-row.is-stock { background: #eaf7ef; border-color: #bfe6cd; }
        .fgi-an-row.is-stock .val { font-size: 17px; }
        .fgi-an-sub { font-size: 11px; color: #98a2b3; font-weight: 500; }
        .fgi-an-foot { margin-top: 16px; text-align: right; }
        .fgi-an-excel { background: #1d6f42; }
        .fgi-an-excel:disabled { opacity: .55; cursor: not-allowed; }
    </style>

    <script>
        (function () {
            var BASE = <?php echo json_encode($ajax_base); ?>;

            /* ---------- Chụp ảnh tồn kho (chỉ .of-inventory, có khung bo góc + tiêu đề) -> Ctrl+V ---------- */
            function fgiToast(msg) {
                var t = document.createElement('div');
                t.className = 'of-capture-toast';
                t.textContent = msg;
                document.body.appendChild(t);
                requestAnimationFrame(function () { t.classList.add('show'); });
                setTimeout(function () {
                    t.classList.remove('show');
                    setTimeout(function () { t.remove(); }, 300);
                }, 2200);
            }
            function fgiFallbackDownload(canvas) {
                var a = document.createElement('a');
                a.href = canvas.toDataURL('image/png');
                a.download = 'Ton_kho_thanh_pham_' + new Date().toISOString().slice(0, 10) + '.png';
                document.body.appendChild(a); a.click(); a.remove();
                fgiToast('Trình duyệt không hỗ trợ copy ảnh — đã tải file PNG về máy.');
            }
            var $captureBtn = document.getElementById('of-capture-btn');
            if ($captureBtn) $captureBtn.addEventListener('click', function () {
                if (typeof html2canvas !== 'function') { alert('Không tải được thư viện chụp ảnh.'); return; }
                var inventory = document.getElementById('of-inventory');
                if (!inventory) { alert('Không tìm thấy nội dung để chụp.'); return; }

                var btn = $captureBtn, oldHtml = btn.innerHTML;
                btn.disabled = true;
                btn.innerHTML = '<i class="fa-solid fa-spinner fa-spin"></i>';

                // Bọc tạm .of-inventory vào 1 khung có bo góc/đệm/đổ bóng + tiêu đề, CHỈ để chụp —
                // không tách nút .of-inventory ra khỏi vị trí thật (chỉ chèn thêm 1 lớp bọc quanh nó),
                // nên không ảnh hưởng CSS/JS khác đang tham chiếu tới nó (search, drag-drop...).
                var anchor = document.createComment('fgi-capture-anchor');
                inventory.parentNode.insertBefore(anchor, inventory);
                var wrap = document.createElement('div');
                wrap.className = 'fgi-capture-wrap';
                var title = document.createElement('h2');
                title.className = 'fgi-capture-title';
                title.textContent = 'TỒN KHO THÀNH PHẨM - VUA AN TOÀN FACTORY';
                wrap.appendChild(title);
                wrap.appendChild(inventory);
                anchor.parentNode.insertBefore(wrap, anchor);

                function restore() {
                    anchor.parentNode.insertBefore(inventory, anchor);
                    wrap.remove();
                    anchor.remove();
                }

                html2canvas(wrap, { scale: 2, backgroundColor: '#ffffff', useCORS: true }).then(function (canvas) {
                    restore();
                    canvas.toBlob(function (blob) {
                        if (!blob) { fgiFallbackDownload(canvas); finish(); return; }
                        if (navigator.clipboard && window.ClipboardItem) {
                            navigator.clipboard.write([new ClipboardItem({ 'image/png': blob })])
                                .then(function () {
                                    fgiToast('Đã copy ảnh tồn kho. Mở Zalo/Messenger và nhấn Ctrl+V để gửi.');
                                    finish();
                                })
                                .catch(function () { fgiFallbackDownload(canvas); finish(); });
                        } else {
                            fgiFallbackDownload(canvas);
                            finish();
                        }
                    }, 'image/png');
                }).catch(function (err) {
                    restore();
                    console.error('Chụp tồn kho lỗi:', err);
                    alert('Lỗi khi chụp ảnh.');
                    finish();
                });

                function finish() { btn.disabled = false; btn.innerHTML = oldHtml; }
            });

            /* ---------- Tìm sản phẩm theo tên ---------- */
            var $search = document.getElementById('of-search-input');
            var $info   = document.getElementById('of-result-info');
            var $emptySearch = document.getElementById('of-empty-search');
            var prods = Array.prototype.slice.call(document.querySelectorAll('#of-inventory .of-prod'));

            function applySearch() {
                var kw = ($search.value || '').trim().toLowerCase();
                var visible = 0;
                prods.forEach(function (el) {
                    var ok = kw === '' || (el.getAttribute('data-search') || '').indexOf(kw) !== -1;
                    el.style.display = ok ? '' : 'none';
                    if (ok) visible++;
                });
                // Ẩn nhóm/cột rỗng khi đang tìm.
                document.querySelectorAll('#of-inventory .of-group-col').forEach(function (col) {
                    var any = col.querySelector('.of-prod:not([style*="display: none"])');
                    col.style.display = (kw !== '' && !any) ? 'none' : '';
                });
                document.querySelectorAll('#of-inventory .of-block').forEach(function (bl) {
                    var any = bl.querySelector('.of-prod:not([style*="display: none"])');
                    bl.style.display = (kw !== '' && !any) ? 'none' : '';
                });
                if ($emptySearch) $emptySearch.style.display = (kw !== '' && visible === 0) ? '' : 'none';
                if ($info) $info.textContent = kw === '' ? '' : (visible + ' sản phẩm phù hợp');
            }
            if ($search) $search.addEventListener('input', applySearch);

            /* ---------- Modal cài đặt thứ tự hiển thị ---------- */
            function openModal(id) { var m = document.getElementById(id); if (m) m.style.display = 'flex'; }
            function closeModal(id) { var m = document.getElementById(id); if (m) m.style.display = 'none'; }
            document.querySelectorAll('[data-close]').forEach(function (b) {
                b.addEventListener('click', function () { closeModal(b.getAttribute('data-close')); });
            });
            document.querySelectorAll('.modal-mask').forEach(function (m) {
                // Modal cài đặt (#modal-setting) không tự tắt khi click ra ngoài — chỉ tắt bằng nút "x",
                // tránh mất thao tác đang dở (lọc/nhập tồn kho) khi lỡ tay bấm ra ngoài.
                if (m.id === 'modal-setting') return;
                m.addEventListener('click', function (e) { if (e.target === m) m.style.display = 'none'; });
            });

            var settingLoaded = false;
            var $setBtn = document.getElementById('of-setting-btn');
            if ($setBtn) $setBtn.addEventListener('click', function () {
                openModal('modal-setting');
                if (settingLoaded) return;
                fetch(BASE + 'fgi_display_order_list', { credentials: 'same-origin' })
                    .then(function (r) { return r.json(); })
                    .then(function (res) {
                        var list = document.getElementById('of-setting-list');
                        if (!res || !res.ok) { list.innerHTML = '<div class="of-setting-loading">Không tải được.</div>'; return; }
                        var html = '<table class="of-set-table"><thead><tr><th>Tên sản phẩm</th><th style="width:90px;">Thứ tự</th></tr></thead><tbody>';
                        res.data.forEach(function (p) {
                            var nm = String(p.product_name || '');
                            var cat = p.category_name ? '<div class="of-set-cat">' + escHtml(p.category_name) + '</div>' : '';
                            html += '<tr data-name="' + escAttr(nm.toLowerCase()) + '">'
                                + '<td>' + escHtml(nm) + cat + '</td>'
                                + '<td><input type="number" class="of-set-order-input" min="1" step="1" data-product-id="' + p.product_id + '" value="' + (p.sort_order != null ? p.sort_order : '') + '"></td>'
                                + '</tr>';
                        });
                        html += '</tbody></table>';
                        list.innerHTML = html;
                        settingLoaded = true;
                    });
            });
            var $setSearch = document.getElementById('of-setting-search');
            if ($setSearch) $setSearch.addEventListener('input', function () {
                var kw = (this.value || '').trim().toLowerCase();
                document.querySelectorAll('#of-setting-list .of-set-table tbody tr').forEach(function (tr) {
                    tr.style.display = (kw === '' || String(tr.getAttribute('data-name')).indexOf(kw) !== -1) ? '' : 'none';
                });
            });
            var $setSave = document.getElementById('of-setting-save');
            if ($setSave) $setSave.addEventListener('click', function () {
                var orders = {};
                document.querySelectorAll('#of-setting-list .of-set-order-input').forEach(function (inp) {
                    orders[inp.getAttribute('data-product-id')] = inp.value;
                });
                var btn = this; btn.disabled = true; btn.textContent = 'Đang lưu...';
                var fd = new FormData(); fd.append('orders', JSON.stringify(orders));
                fetch(BASE + 'fgi_save_display_order', { method: 'POST', body: fd, credentials: 'same-origin' })
                    .then(function (r) { return r.json(); })
                    .then(function (res) {
                        if (res && res.ok) { location.reload(); }
                        else { alert('Không lưu được.'); btn.disabled = false; btn.textContent = 'Lưu thứ tự'; }
                    })
                    .catch(function () { alert('Lỗi mạng/Server.'); btn.disabled = false; btn.textContent = 'Lưu thứ tự'; });
            });

            /* ---------- Chuyển tab trong modal cài đặt (chỉ admin có tab "Điều chỉnh tồn kho") ---------- */
            var $setTabs = document.getElementById('of-setting-tabs');
            if ($setTabs) $setTabs.addEventListener('click', function (e) {
                var b = e.target.closest('.of-setting-tab'); if (!b) return;
                var tab = b.getAttribute('data-tab');
                $setTabs.querySelectorAll('.of-setting-tab').forEach(function (t) { t.classList.toggle('active', t === b); });
                document.querySelectorAll('.of-setting-panel').forEach(function (p) {
                    p.style.display = (p.getAttribute('data-panel') === tab) ? '' : 'none';
                });
                if (tab === 'stock') loadStockAdjust();
            });

            /* ---------- Tab "Điều chỉnh tồn kho" (chỉ admin) ---------- */
            var stockLoaded = false;
            function loadStockAdjust() {
                if (stockLoaded) return;
                var list = document.getElementById('of-stock-list');
                fetch(BASE + 'fgi_stock_adjust_list', { credentials: 'same-origin' })
                    .then(function (r) { return r.json(); })
                    .then(function (res) {
                        if (!res || !res.ok) { list.innerHTML = '<div class="of-setting-loading">' + escHtml((res && res.message) || 'Không tải được.') + '</div>'; return; }
                        var html = '<table class="of-set-table"><thead><tr><th>Tên sản phẩm</th><th style="width:110px;">Tồn kho</th></tr></thead><tbody>';
                        res.data.forEach(function (p) {
                            var nm = String(p.product_name || '');
                            var cat = p.category_name ? '<div class="of-set-cat">' + escHtml(p.category_name) + '</div>' : '';
                            html += '<tr data-name="' + escAttr(nm.toLowerCase()) + '">'
                                + '<td>' + escHtml(nm) + cat + '</td>'
                                + '<td><input type="text" inputmode="numeric" class="of-stock-input" data-product-id="' + p.product_id + '" value="' + (p.quantity != null ? p.quantity : 0) + '"></td>'
                                + '</tr>';
                        });
                        html += '</tbody></table>';
                        list.innerHTML = html;
                        stockLoaded = true;
                    })
                    .catch(function () { list.innerHTML = '<div class="of-setting-loading">Lỗi kết nối.</div>'; });
            }
            var $stockSearch = document.getElementById('of-stock-search');
            if ($stockSearch) $stockSearch.addEventListener('input', function () {
                var kw = (this.value || '').trim().toLowerCase();
                document.querySelectorAll('#of-stock-list .of-set-table tbody tr').forEach(function (tr) {
                    tr.style.display = (kw === '' || String(tr.getAttribute('data-name')).indexOf(kw) !== -1) ? '' : 'none';
                });
            });

            /* ---------- Điều khiển mũi tên: từ ô tìm nhảy xuống lần lượt từng ô Tồn kho
             * đang hiển thị (theo kết quả lọc), mỗi lần nhảy tới thì bôi đen số sẵn có. ---- */
            function visibleStockInputs() {
                return Array.prototype.filter.call(
                    document.querySelectorAll('#of-stock-list .of-stock-input'),
                    function (inp) { return inp.closest('tr').style.display !== 'none'; }
                );
            }
            function focusStockInput(inp) { if (!inp) return; inp.focus(); inp.select(); }
            if ($stockSearch) $stockSearch.addEventListener('keydown', function (e) {
                if (e.key !== 'ArrowDown') return;
                e.preventDefault();
                focusStockInput(visibleStockInputs()[0]);
            });
            var $stockListForNav = document.getElementById('of-stock-list');
            if ($stockListForNav) $stockListForNav.addEventListener('keydown', function (e) {
                var inp = e.target.closest('.of-stock-input'); if (!inp) return;
                if (e.key !== 'ArrowDown' && e.key !== 'ArrowUp') return;
                e.preventDefault();
                var list = visibleStockInputs();
                var idx = list.indexOf(inp);
                if (e.key === 'ArrowDown') {
                    if (idx > -1 && idx < list.length - 1) focusStockInput(list[idx + 1]);
                } else {
                    if (idx > 0) focusStockInput(list[idx - 1]);
                    else if ($stockSearch) focusStockInput($stockSearch);
                }
            });
            var $stockSave = document.getElementById('of-stock-save');
            if ($stockSave) $stockSave.addEventListener('click', function () {
                var quantities = {};
                document.querySelectorAll('#of-stock-list .of-stock-input').forEach(function (inp) {
                    quantities[inp.getAttribute('data-product-id')] = inp.value;
                });
                var btn = this; btn.disabled = true; btn.textContent = 'Đang lưu...';
                var fd = new FormData(); fd.append('quantities', JSON.stringify(quantities));
                fetch(BASE + 'fgi_save_stock_adjustment', { method: 'POST', body: fd, credentials: 'same-origin' })
                    .then(function (r) { return r.json(); })
                    .then(function (res) {
                        if (res && res.ok) { location.reload(); }
                        else { alert((res && res.message) || 'Không lưu được.'); btn.disabled = false; btn.textContent = 'Cập nhật'; }
                    })
                    .catch(function () { alert('Lỗi mạng/Server.'); btn.disabled = false; btn.textContent = 'Cập nhật'; });
            });

            /* ---------- Kéo-giữ chuột đổi vị trí hiển thị (cá nhân hóa, cùng danh mục) ----------
             * Kéo 1 sản phẩm thả lên 1 sản phẩm khác CÙNG danh mục -> đổi chỗ 2 sản phẩm ngay
             * trên lưới, lưu thứ tự CÁ NHÂN cho riêng user đang đăng nhập (không ảnh hưởng thứ
             * tự chung mà admin cấu hình ở tab "Thứ tự hiển thị"). */
            (function () {
                var $inv = document.getElementById('of-inventory');
                if (!$inv) return;
                var dragEl = null;

                $inv.addEventListener('dragstart', function (e) {
                    var card = e.target.closest('.of-prod'); if (!card) return;
                    dragEl = card;
                    card.classList.add('of-dragging');
                    e.dataTransfer.effectAllowed = 'move';
                    try { e.dataTransfer.setData('text/plain', card.getAttribute('data-product-id') || ''); } catch (err) {}
                });
                $inv.addEventListener('dragend', function () {
                    if (dragEl) dragEl.classList.remove('of-dragging');
                    $inv.querySelectorAll('.of-drag-over').forEach(function (el) { el.classList.remove('of-drag-over'); });
                    dragEl = null;
                });
                function sameCategory(card) {
                    return card && dragEl && card !== dragEl && card.getAttribute('data-category-id') === dragEl.getAttribute('data-category-id');
                }
                $inv.addEventListener('dragover', function (e) {
                    var card = e.target.closest('.of-prod');
                    if (!sameCategory(card)) return;
                    e.preventDefault();
                    e.dataTransfer.dropEffect = 'move';
                });
                $inv.addEventListener('dragenter', function (e) {
                    var card = e.target.closest('.of-prod');
                    if (sameCategory(card)) card.classList.add('of-drag-over');
                });
                $inv.addEventListener('dragleave', function (e) {
                    var card = e.target.closest('.of-prod'); if (card) card.classList.remove('of-drag-over');
                });
                $inv.addEventListener('drop', function (e) {
                    var card = e.target.closest('.of-prod');
                    if (!sameCategory(card)) return;
                    e.preventDefault();
                    card.classList.remove('of-drag-over');
                    swapProducts(dragEl, card, card.getAttribute('data-category-id'));
                });

                function swapProducts(a, b, catId) {
                    // Đổi chỗ 2 node trong DOM ngay (phản hồi tức thì, không chờ server).
                    var aNext = a.nextSibling, aParent = a.parentNode;
                    var bNext = b.nextSibling, bParent = b.parentNode;
                    bParent.insertBefore(a, bNext);
                    aParent.insertBefore(b, aNext);

                    // Ghi lại TOÀN BỘ thứ tự của danh mục này (mọi cột/khối chứa danh mục đó)
                    // theo đúng thứ tự hiển thị mới -> lưu thứ tự cá nhân cho user hiện tại.
                    var ids = Array.prototype.map.call(
                        $inv.querySelectorAll('.of-prod[data-category-id="' + catId + '"]'),
                        function (el) { return el.getAttribute('data-product-id'); }
                    );
                    var fd = new FormData(); fd.append('ordered_ids', JSON.stringify(ids));
                    fetch(BASE + 'fgi_swap_display_order', { method: 'POST', body: fd, credentials: 'same-origin' });
                }
            })();

            function escHtml(s) { return String(s == null ? '' : s).replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;'); }
            function escAttr(s) { return String(s == null ? '' : s).replace(/"/g, '&quot;').replace(/&/g, '&amp;'); }
            // Xuất 1 bảng [ [nhãn, giá trị], ... ] ra file Excel (.xls dạng HTML table).
            function exportAnalysisExcel(fileBase, title, rows) {
                var body = rows.map(function (r) {
                    return '<tr><td style="font-weight:bold;background:#eaf7ef;">' + escHtml(r[0]) + '</td><td>' + escHtml(r[1]) + '</td></tr>';
                }).join('');
                var html = '<html xmlns:o="urn:schemas-microsoft-com:office:office" xmlns:x="urn:schemas-microsoft-com:office:excel">'
                    + '<head><meta charset="UTF-8"></head><body>'
                    + '<table border="1" cellspacing="0" cellpadding="5">'
                    + '<tr><th colspan="2" style="font-size:15px;">' + escHtml(title || '') + '</th></tr>'
                    + body + '</table></body></html>';
                var blob = new Blob(['﻿' + html], { type: 'application/vnd.ms-excel' });
                var a = document.createElement('a');
                a.href = URL.createObjectURL(blob);
                a.download = (fileBase || 'export') + '_' + (title || '').replace(/[^\p{L}\p{N}]+/gu, '_').slice(0, 40) + '.xls';
                document.body.appendChild(a); a.click();
                setTimeout(function () { URL.revokeObjectURL(a.href); a.remove(); }, 100);
            }
            // Khoảng thời gian dạng "X ngày/tháng/năm trước".
            function relTime(days) {
                if (days == null) return '';
                if (days < 30) return days + ' ngày trước';
                if (days < 365) return Math.max(1, Math.round(days / 30)) + ' tháng trước';
                return Math.floor(days / 365) + ' năm trước';
            }

            /* ---------- Nhóm sản phẩm đã lâu chưa bán ---------- */
            var $months = document.getElementById('fgi-unsold-months');
            var $dateFilter = document.getElementById('fgi-unsold-datefilter');
            var $list   = document.getElementById('fgi-unsold-list');
            var $count  = document.getElementById('fgi-unsold-count');
            var unsoldData = [];

            // Bộ lọc "Tất cả / Chỉ SP có ngày sx" — nhớ qua localStorage (cố định đến khi user đổi).
            var DF_KEY = 'fgi_unsold_datefilter';
            try { var saved = localStorage.getItem(DF_KEY); if (saved && $dateFilter) $dateFilter.value = saved; } catch (e) {}

            function renderUnsold() {
                var onlyDated = $dateFilter && $dateFilter.value === 'dated';
                var rows = unsoldData.filter(function (p) { return !onlyDated || p.days_ago != null; });
                if ($count) $count.textContent = rows.length ? ('(' + rows.length + ' sản phẩm)') : '';
                if (!rows.length) { $list.innerHTML = '<div class="fgi-unsold-empty">Không có sản phẩm phù hợp trong mốc này.</div>'; return; }
                $list.innerHTML = rows.map(function (p) {
                    var ago = (p.days_ago != null) ? (p.last_prod_text + ' (' + relTime(p.days_ago) + ')') : p.last_prod_text;
                    return '<div class="fgi-unsold-card">'
                        + '<div class="nm">' + escHtml(p.product_name) + '</div>'
                        + '<div class="cat">' + escHtml(p.category_name) + '</div>'
                        + '<div class="row"><span>Tồn kho:</span> <span class="stock">' + escHtml(p.pack_conv_text) + '</span></div>'
                        + '<div class="row"><span>Sản xuất gần nhất:</span> <span class="last">' + escHtml(ago) + '</span></div>'
                        + '<div class="fgi-card-act"><button type="button" class="fgi-act-btn fgi-act-stop" data-stop="' + p.product_id + '">Ngưng bán</button></div>'
                        + '</div>';
                }).join('');
            }
            function loadUnsold() {
                $list.innerHTML = '<div class="fgi-unsold-loading">Đang tải…</div>';
                var fd = new FormData(); fd.append('months', $months.value);
                fetch(BASE + 'fgi_long_unsold', { method: 'POST', body: fd, credentials: 'same-origin' })
                    .then(function (r) { return r.json(); })
                    .then(function (res) {
                        if (!res || !res.ok) { $list.innerHTML = '<div class="fgi-unsold-empty">Không tải được.</div>'; return; }
                        unsoldData = res.data || [];
                        renderUnsold();
                    })
                    .catch(function () { $list.innerHTML = '<div class="fgi-unsold-empty">Lỗi kết nối.</div>'; });
            }
            if ($months) $months.addEventListener('change', loadUnsold);
            if ($dateFilter) $dateFilter.addEventListener('change', function () {
                try { localStorage.setItem(DF_KEY, $dateFilter.value); } catch (e) {}
                renderUnsold();
            });
            loadUnsold();

            /* ---------- Danh sách ngưng bán + Bán lại ---------- */
            var $discList = document.getElementById('fgi-disc-list');
            var $discSearch = document.getElementById('fgi-disc-search');
            var $discCount = document.getElementById('fgi-disc-count');
            var discData = [];
            function renderDisc() {
                var kw = ($discSearch && $discSearch.value || '').trim().toLowerCase();
                var rows = discData.filter(function (p) { return kw === '' || String(p.product_name || '').toLowerCase().indexOf(kw) !== -1; });
                if ($discCount) $discCount.textContent = rows.length ? ('(' + rows.length + ')') : '';
                if (!rows.length) { $discList.innerHTML = '<div class="fgi-disc-empty">Chưa có sản phẩm nào bị ngưng bán.</div>'; return; }
                $discList.innerHTML = rows.map(function (p) {
                    return '<div class="fgi-disc-card">'
                        + '<div class="nm">' + escHtml(p.product_name) + '</div>'
                        + '<div class="cat">' + escHtml(p.category_name) + '</div>'
                        + '<div class="row"><span>Tồn kho:</span> <span class="stock">' + escHtml(p.pack_conv_text) + '</span></div>'
                        + '<div class="fgi-card-act"><button type="button" class="fgi-act-btn fgi-act-resume" data-resume="' + p.product_id + '">Bán lại</button>'
                        + '<button type="button" class="fgi-act-btn fgi-act-del" data-del="' + p.product_id + '" data-name="' + escAttr(p.product_name) + '">Xóa</button></div>'
                        + '</div>';
                }).join('');
            }
            function loadDisc() {
                $discList.innerHTML = '<div class="fgi-disc-loading">Đang tải…</div>';
                fetch(BASE + 'fgi_discontinued_list', { credentials: 'same-origin' })
                    .then(function (r) { return r.json(); })
                    .then(function (res) {
                        if (!res || !res.ok) { $discList.innerHTML = '<div class="fgi-disc-empty">Không tải được.</div>'; return; }
                        discData = res.data || [];
                        renderDisc();
                    })
                    .catch(function () { $discList.innerHTML = '<div class="fgi-disc-empty">Lỗi kết nối.</div>'; });
            }
            if ($discSearch) $discSearch.addEventListener('input', renderDisc);
            loadDisc();

            function setDiscontinued(productId, on) {
                var fd = new FormData(); fd.append('product_id', productId); fd.append('on', on ? 1 : 0);
                return fetch(BASE + 'fgi_set_discontinued', { method: 'POST', body: fd, credentials: 'same-origin' })
                    .then(function (r) { return r.json(); })
                    .then(function () { loadUnsold(); loadDisc(); });
            }
            function deleteProduct(productId, name) {
                if (!confirm('Xóa vĩnh viễn sản phẩm "' + name + '"?\n\nThao tác này xóa sản phẩm và dữ liệu cấu hình liên quan (chỉ giữ lại dữ liệu nhập/xuất) và KHÔNG THỂ HOÀN TÁC.')) return;
                var fd = new FormData(); fd.append('product_id', productId);
                fetch(BASE + 'fgi_delete_product', { method: 'POST', body: fd, credentials: 'same-origin' })
                    .then(function (r) { return r.json(); })
                    .then(function (res) {
                        if (res && res.ok) { location.reload(); }
                        else { alert('Xóa thất bại.'); }
                    })
                    .catch(function () { alert('Lỗi kết nối.'); });
            }
            $list.addEventListener('click', function (e) {
                var btn = e.target.closest('[data-stop]'); if (!btn) return;
                setDiscontinued(+btn.getAttribute('data-stop'), true);
            });
            $discList.addEventListener('click', function (e) {
                var del = e.target.closest('[data-del]');
                if (del) { deleteProduct(+del.getAttribute('data-del'), del.getAttribute('data-name') || ''); return; }
                var btn = e.target.closest('[data-resume]'); if (!btn) return;
                setDiscontinued(+btn.getAttribute('data-resume'), false);
            });

            /* ---------- Modal phân tích sản phẩm ---------- */
            var $anName = document.getElementById('fgi-an-name');
            var $anNameText = document.querySelector('#fgi-an-name .fgi-an-name-text');
            var $anNameEditBtn = document.getElementById('fgi-an-name-edit-btn');
            var $anCat  = document.getElementById('fgi-an-cat');
            var $anBody = document.getElementById('fgi-an-body');
            var $anExcel = document.getElementById('fgi-an-excel');
            var curAnalysis = null;

            function rowHtml(lab, val, extra, sub) {
                return '<div class="fgi-an-row' + (extra || '') + '">'
                    + '<span class="lab">' + escHtml(lab) + (sub ? ' <span class="fgi-an-sub">' + escHtml(sub) + '</span>' : '') + '</span>'
                    + '<span class="val">' + escHtml(val) + '</span></div>';
            }
            function renderAnalysis(d) {
                $anNameText.textContent = d.product_name || '—';
                $anCat.textContent  = d.category_name || '';
                $anBody.innerHTML = '<div class="fgi-an-grid">'
                    + rowHtml('Tồn kho hiện tại', d.stock_text, ' is-stock')
                    + rowHtml('Ngày sản xuất gần đây', d.last_prod_date + (d.last_prod_qty && d.last_prod_qty !== '—' ? '  •  ' + d.last_prod_qty : ''))
                    + rowHtml('Xuất kho 1 tháng', d.issue_1m, '', '(30 ngày)')
                    + rowHtml('Xuất kho 3 tháng', d.issue_3m, '', '(90 ngày)')
                    + rowHtml('Xuất kho 6 tháng', d.issue_6m, '', '(180 ngày)')
                    + '</div>';
            }
            function openAnalysis(productId) {
                openModal('modal-analysis');
                $anNameText.textContent = 'Đang tải…'; $anCat.textContent = '';
                $anBody.innerHTML = '<div class="fgi-an-loading">Đang tải…</div>';
                $anExcel.disabled = true; curAnalysis = null;
                var fd = new FormData(); fd.append('product_id', productId);
                fetch(BASE + 'fgi_product_analysis', { method: 'POST', body: fd, credentials: 'same-origin' })
                    .then(function (r) { return r.json(); })
                    .then(function (res) {
                        if (!res || !res.ok) { $anBody.innerHTML = '<div class="fgi-an-loading">Không tải được dữ liệu.</div>'; return; }
                        curAnalysis = res.data;
                        renderAnalysis(res.data);
                        $anExcel.disabled = false;
                    })
                    .catch(function () { $anBody.innerHTML = '<div class="fgi-an-loading">Lỗi kết nối.</div>'; });
            }
            // Click vào 1 sản phẩm -> mở phân tích.
            document.getElementById('of-inventory').addEventListener('click', function (e) {
                var card = e.target.closest('.of-prod'); if (!card) return;
                var pid = card.getAttribute('data-product-id'); if (!pid) return;
                openAnalysis(pid);
            });
            // Xuất Excel phân tích hiện tại.
            if ($anExcel) $anExcel.addEventListener('click', function () {
                if (!curAnalysis) return;
                var d = curAnalysis;
                var rows = [
                    ['Sản phẩm', d.product_name],
                    ['Phân loại', d.category_name],
                    ['Tồn kho hiện tại', d.stock_text],
                    ['Ngày sản xuất gần đây', d.last_prod_date],
                    ['Số lượng sản xuất gần nhất', d.last_prod_qty],
                    ['Xuất kho 1 tháng (30 ngày)', d.issue_1m],
                    ['Xuất kho 3 tháng (90 ngày)', d.issue_3m],
                    ['Xuất kho 6 tháng (180 ngày)', d.issue_6m]
                ];
                exportAnalysisExcel('Phan_tich_san_pham', d.product_name, rows);
            });

            /* ---- Sửa "Tên thường gọi" tại chỗ trong modal phân tích (hover hiện bút chì,
             * bấm bút -> contenteditable, lưu khi rời ô). Lưu vào products.common_product_name
             * và đồng bộ ngay sang tên hiển thị ở lưới tồn kho phía sau modal. ---- */
            function enterAnNameEdit() {
                if (!curAnalysis || $anName.classList.contains('editing')) return;
                $anName.classList.add('editing');
                $anNameText.dataset.orig = $anNameText.textContent;
                $anNameText.setAttribute('contenteditable', 'true');
                $anNameText.focus();
                var range = document.createRange();
                range.selectNodeContents($anNameText);
                var sel = window.getSelection();
                sel.removeAllRanges();
                sel.addRange(range);
            }
            function commitAnNameEdit() {
                if (!$anName.classList.contains('editing')) return;
                $anName.classList.remove('editing');
                $anNameText.removeAttribute('contenteditable');
                var val = ($anNameText.textContent || '').replace(/\s+/g, ' ').trim();
                var orig = $anNameText.dataset.orig || '';
                if (!val) val = orig;
                $anNameText.textContent = val;
                if (!curAnalysis || val === orig) return;
                var pid = curAnalysis.product_id;
                curAnalysis.product_name = val;
                var fd = new FormData(); fd.append('product_id', pid); fd.append('value', val);
                fetch(BASE + 'fgi_save_product_common_name', { method: 'POST', body: fd, credentials: 'same-origin' })
                    .then(function (r) { return r.json(); })
                    .then(function (res) {
                        if (!res || !res.ok) return;
                        document.querySelectorAll('.of-prod[data-product-id="' + pid + '"]').forEach(function (card) {
                            var nameEl = card.querySelector('.of-prod-name-text');
                            if (nameEl) nameEl.textContent = val;
                            card.setAttribute('data-search', val.toLowerCase());
                        });
                    });
            }
            if ($anNameEditBtn) $anNameEditBtn.addEventListener('click', function (e) {
                e.preventDefault(); e.stopPropagation();
                enterAnNameEdit();
            });
            if ($anNameText) {
                $anNameText.addEventListener('blur', commitAnNameEdit);
                $anNameText.addEventListener('keydown', function (e) {
                    if (e.key === 'Enter') { e.preventDefault(); e.target.blur(); }
                    else if (e.key === 'Escape') {
                        e.target.textContent = e.target.dataset.orig || '';
                        $anName.classList.remove('editing');
                        e.target.removeAttribute('contenteditable');
                    }
                });
            }

            /* ---- FAQ: click tiêu đề mới xổ danh sách ngưng bán ---- */
            var $discSection = document.getElementById('fgi-discontinued');
            var $discToggle = document.getElementById('fgi-disc-toggle');
            var $discBodyWrap = document.getElementById('fgi-disc-body');
            if ($discToggle) $discToggle.addEventListener('click', function () {
                var open = $discSection.classList.toggle('is-open');
                $discBodyWrap.style.display = open ? '' : 'none';
            });
        })();
    </script>
    <script src="<?php echo asset_ver('public/js/shared/app_shell.js'); ?>"></script>
</body>

</html>
