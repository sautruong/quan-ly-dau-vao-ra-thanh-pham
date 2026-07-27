<?php
/** @var array $materials */
$materials = isset($materials) && is_array($materials) ? $materials : [];
$ajax_base = '?mod=report&controllers=report&action=';
?>
<!DOCTYPE html>
<html lang="vi">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Tồn nguyên vật liệu</title>
    <link rel="icon" href="public/images/logo/logo_vat_png.png" type="image/png">
    <link rel="stylesheet" href="<?php echo asset_ver('public/css/reset.css'); ?>">
    <link rel="stylesheet" href="<?php echo asset_ver('public/css/all.css'); ?>">
    <link rel="stylesheet" href="<?php echo asset_ver('public/css/global.css'); ?>">
    <link rel="stylesheet" href="<?php echo asset_ver('public/css/shared/app_shell.css'); ?>">
    <style>
        /* Header cố định trên cùng + vùng nội dung cuộn dọc (app shell .has-sider). */
        #wrapper.has-sider > .content.mi-page { overflow-y: auto; overflow-x: hidden; }
        /* padding-top = 0 để toolbar sticky dính sát mép trên (không hở dải trắng). */
        .mi-page { padding: 0 18px 40px; }
        .mi-toolbar { display: flex; align-items: center; gap: 12px; flex-wrap: wrap; margin-bottom: 14px; }
        .mi-search { position: relative; flex: 1; max-width: 420px; min-width: 220px; }
        .mi-search i { position: absolute; left: 13px; top: 50%; transform: translateY(-50%); color: #8a93a0; font-size: 14px; }
        .mi-search input { width: 100%; box-sizing: border-box; padding: 10px 12px 10px 36px; border: 1px solid #c2c8d0; border-radius: 9px; font-size: 14px; }
        .mi-search input:focus { outline: none; border-color: #16a34a; box-shadow: 0 0 0 3px rgba(22,163,74,.12); }
        .mi-count { color: #6b7280; font-size: 13px; }

        .mi-table { width: 100%; border-collapse: collapse; font-size: 13px; }
        .mi-table th, .mi-table td { border: 1px solid #d8dde3; padding: 8px 10px; text-align: left; vertical-align: middle; }
        .mi-table thead th { background: #f0f3f7; font-weight: 600; position: relative; user-select: none; }
        .mi-table td.num, .mi-table th.num { text-align: right; }
        .mi-table td.center, .mi-table th.center { text-align: center; }
        .mi-table tbody tr:nth-child(even) td { background: #fafbfc; }

        /* Cột "Tên nguyên vật liệu": đen, weight 500 mặc định — hover mới đổi màu để biết bấm được (mở phân tích). */
        .mi-name-cell { cursor: pointer; color: #1f2733; font-weight: 500; transition: color .15s ease; }
        .mi-name-cell:hover { color: #096926; }

        /* Cột "Tồn kho": chỉ tồn ÂM mới tô đỏ, còn lại đen bình thường; riêng admin hover hiện
           viền xanh, click để sửa tại chỗ, click ra ngoài là lưu (giữ nguyên viền lưới mặc định
           của bảng, chỉ đổi màu viền khi hover). */
        #mi-table td.num.mi-qty-neg { color: #b91c1c; font-weight: 600; }
        .mi-table td.mi-qty-cell { cursor: pointer; border-radius: 3px; transition: border-color .15s ease, background .15s ease; }
        .mi-table td.mi-qty-cell:hover { border-color: #096926; background: #eef7f0; }
        .mi-qty-input { width: 90px; padding: 3px 6px; border: 1px solid #096926; border-radius: 4px; text-align: right; font-size: 13px; box-sizing: border-box; }
        .mi-qty-input:focus { outline: none; box-shadow: 0 0 0 2px rgba(9,105,38,.15); }
        .mi-qty-input::-webkit-outer-spin-button,
        .mi-qty-input::-webkit-inner-spin-button { -webkit-appearance: none; margin: 0; }
        .mi-qty-input { -moz-appearance: textfield; }
        .mi-th-inner { display: inline-flex; align-items: center; gap: 6px; }
        .mi-sort { cursor: pointer; }
        .mi-sort .fa-sort, .mi-sort .fa-sort-up, .mi-sort .fa-sort-down { color: #9aa3af; font-size: 11px; }
        .mi-sort.is-asc .fa-sort, .mi-sort.is-desc .fa-sort { display: none; }
        .mi-filter-btn { cursor: pointer; color: #8a93a0; font-size: 12px; padding: 2px; border-radius: 3px; }
        .mi-filter-btn:hover { color: #096926; background: #e6efe9; }
        .mi-filter-btn.is-active { color: #096926; }
        .mi-pop {
            position: absolute; top: 100%; left: 0; margin-top: 4px; background: #fff;
            border: 1px solid #c2c8d0; border-radius: 6px; box-shadow: 0 6px 18px rgba(0,0,0,.15);
            z-index: 50; width: 240px; display: none; font-weight: 400;
        }
        .mi-pop.show { display: block; }
        .mi-pop-list { max-height: 240px; overflow-y: auto; padding: 6px 0; }
        .mi-pop-list label { display: flex; align-items: center; gap: 8px; padding: 5px 12px; font-size: 13px; cursor: pointer; }
        .mi-pop-list label:hover { background: #f0f3f7; }
        .mi-pop-list input { width: auto; margin: 0; }
        .mi-pop-foot { display: flex; gap: 8px; padding: 8px 12px; border-top: 1px solid #e1e4e8; }
        .mi-pop-foot button { flex: 1; padding: 5px 8px; border-radius: 4px; cursor: pointer; font-size: 12px; }
        .mi-pop-clear { background: #eee; border: 1px solid #c2c8d0; }
        .mi-pop-apply { background: #096926; color: #fff; border: 0; }
        /* Bộ lọc NCC dạng input + dropdown (chọn bằng Enter/Tab) */
        .mi-pop-search-wrap { padding: 10px 12px 6px; }
        .mi-pop-search { width: 100%; box-sizing: border-box; padding: 7px 9px; border: 1px solid #c2c8d0; border-radius: 5px; font-size: 13px; }
        .mi-pop-search:focus { outline: none; border-color: #096926; box-shadow: 0 0 0 2px rgba(9,105,38,.15); }
        .mi-pop-suggest { max-height: 220px; overflow-y: auto; padding: 2px 0; }
        .mi-sug-item { padding: 7px 12px; font-size: 13px; cursor: pointer; }
        .mi-sug-item:hover, .mi-sug-item.active { background: #eaf3ec; }
        .mi-sug-empty { padding: 8px 12px; font-size: 13px; color: #999; font-style: italic; }
        .mi-empty { text-align: center; color: gray; padding: 22px; }

        /* Nhóm NVL đã lâu chưa dùng */
        .mi-unused { margin-top: 22px; padding-top: 16px; border-top: 2px dashed #d1d5db; }
        .mi-unused-head { display: flex; align-items: center; gap: 12px; flex-wrap: wrap; margin-bottom: 12px; }
        .mi-unused-head h2 { margin: 0; font-size: 16px; font-weight: 700; color: #b45309; text-transform: uppercase; }
        .mi-unused-head .sub { color: #6b7280; font-size: 13px; }
        .mi-unused-head select { padding: 8px 12px; border: 1px solid #c2c8d0; border-radius: 8px; font-size: 13px; background: #fff; }
        .mi-unused .last { color: #b91c1c; font-weight: 600; }
        .mi-unused-loading, .mi-unused-empty { text-align: center; color: gray; padding: 14px; }

        /* Nút icon cài đặt thứ tự hiển thị */
        .mi-toolbar-actions { margin-left: auto; display: flex; align-items: center; gap: 8px; }
        .mi-tool-btn {
            background: transparent; border: none; color: #344; font-size: 20px; width: 40px; height: 40px;
            border-radius: 9px; cursor: pointer; display: inline-flex; align-items: center; justify-content: center;
        }
        .mi-tool-btn:hover { color: #096926; background: #eef2f7; }

        /* Modal cài đặt thứ tự hiển thị NVL */
        .mi-modal { position: fixed; inset: 0; z-index: 1300; display: none; align-items: center; justify-content: center; }
        .mi-modal.show { display: flex; }
        .mi-modal-overlay { position: absolute; inset: 0; background: rgba(0,0,0,.45); }
        .mi-modal-box {
            position: relative; z-index: 1; background: #fff; border-radius: 12px; width: 560px; max-width: 94vw;
            max-height: 86vh; display: flex; flex-direction: column; box-shadow: 0 20px 60px rgba(0,0,0,.3); padding: 18px;
        }
        .mi-modal-close { position: absolute; top: 12px; right: 16px; font-size: 24px; color: #999; cursor: pointer; line-height: 1; }
        .mi-modal-box h3 { margin: 0 0 4px; font-size: 16px; color: #096926; }
        .mi-modal-hint { color: #6b7280; font-size: 13px; margin: 0 0 10px; }
        .mi-set-tools { margin-bottom: 10px; }
        .mi-set-tools input { width: 100%; box-sizing: border-box; padding: 8px 10px; border: 1px solid #c2c8d0; border-radius: 6px; font-size: 13px; }
        .mi-set-list { flex: 1; overflow-y: auto; border: 1px solid #e1e5ea; border-radius: 6px; }
        .mi-set-table { width: 100%; border-collapse: collapse; font-size: 13px; }
        .mi-set-table th, .mi-set-table td { border-bottom: 1px solid #eef0f3; padding: 7px 10px; text-align: left; }
        .mi-set-table thead th { position: sticky; top: 0; background: #f0f3f7; }
        .mi-set-cat { font-size: 12px; color: #8a93a0; }
        .mi-set-order-input { width: 70px; padding: 5px 7px; border: 1px solid #c2c8d0; border-radius: 5px; }
        .mi-set-foot { margin-top: 12px; text-align: right; }
        .mi-btn-save { background: #096926; color: #fff; border: 0; padding: 9px 18px; border-radius: 8px; font-weight: 600; cursor: pointer; }
        .mi-btn-save:hover { background: #0b7d2e; }
        .mi-set-loading { padding: 18px; text-align: center; color: gray; }

        /* Cố định toolbar + thead bảng chính, chỉ cuộn tbody.
           thead top = chiều cao toolbar (đặt bằng JS) để dính sát, không hở.
           border-collapse: separate (thay vì collapse) — với collapse, border giữa
           thead/tbody bị gộp chung 1 đường nên khi cuộn, nội dung tbody có thể "xuyên"
           qua khe hở dưới thead 1 vài px (lỗi render sticky+collapse trên Chrome). */
        #mi-table { border-collapse: separate; border-spacing: 0; }
        .mi-toolbar { position: sticky; top: 0; z-index: 30; background: #fff; padding: 14px 0 10px; margin: 0; }
        #mi-table thead th { position: sticky; top: 0; z-index: 20; box-shadow: inset 0 -1px 0 #d8dde3; }

        /* Nút thao tác Ngưng dùng / Dùng lại */
        .mi-act-btn { padding: 4px 10px; border-radius: 6px; font-size: 12px; font-weight: 600; cursor: pointer; }
        .mi-act-stop { color: #b45309; border: 1px solid #f1d9b5; background: #fffaf2; }
        .mi-act-stop:hover { background: #fdeccd; }
        .mi-act-resume { color: #096926; border: 1px solid #bfe6cd; background: #eef7f0; }
        .mi-act-resume:hover { background: #dcefe2; }
        .mi-act-del { color: #b91c1c; border: 1px solid #f0c4c4; background: #fff; margin-left: 6px; }
        .mi-act-del:hover { background: #fde2e2; }

        /* Danh sách ngưng dùng (FAQ: click tiêu đề mới xổ ra) */
        .mi-discontinued { margin-top: 22px; padding-top: 16px; border-top: 2px dashed #d1d5db; }
        .mi-disc-head { display: flex; align-items: center; gap: 10px; flex-wrap: wrap; margin-bottom: 12px; cursor: pointer; user-select: none; }
        .mi-disc-caret { color: #6b7280; transition: transform .15s; }
        .mi-discontinued.is-open .mi-disc-caret { transform: rotate(90deg); }
        .mi-disc-head h2 { margin: 0; font-size: 16px; font-weight: 700; color: #6b7280; text-transform: uppercase; }
        .mi-disc-body { margin-top: 4px; }
        .mi-disc-body input { padding: 8px 10px; border: 1px solid #c2c8d0; border-radius: 8px; font-size: 13px; min-width: 220px; margin-bottom: 10px; }
        .mi-disc-empty, .mi-disc-loading { text-align: center; color: gray; padding: 14px; }
    </style>
</head>

<body>
    <div id="wrapper" class="has-sider">
        <?php get_sidebar('app'); ?>
        <?php get_header('app'); ?>

        <div class="content mi-page">
            <div class="mi-toolbar">
                <div class="mi-search">
                    <i class="fa-solid fa-magnifying-glass"></i>
                    <input type="text" id="mi-search" autocomplete="off" placeholder="Lọc NVL theo từ khóa...">
                </div>
                <span class="mi-count" id="mi-count"></span>
                <div class="mi-toolbar-actions">
                    <button type="button" class="mi-tool-btn" id="mi-setting-btn" title="Cài đặt thứ tự hiển thị">
                        <i class="fa-solid fa-gear"></i>
                    </button>
                </div>
            </div>

            <table class="mi-table" id="mi-table">
                <thead>
                    <tr>
                        <th><span class="mi-th-inner mi-sort" data-sort="material_name">Tên nguyên vật liệu <i class="fa-solid fa-sort"></i></span></th>
                        <th>
                            <span class="mi-th-inner">
                                <span class="mi-sort" data-sort="supplier_name">Nhà cung cấp <i class="fa-solid fa-sort"></i></span>
                                <span class="mi-filter-btn" data-filter="supplier_name" title="Lọc nhà cung cấp"><i class="fa-solid fa-filter"></i></span>
                            </span>
                        </th>
                        <th class="center"><span class="mi-th-inner mi-sort" data-sort="unit">Đơn vị <i class="fa-solid fa-sort"></i></span></th>
                        <th class="center">
                            <span class="mi-th-inner">
                                <span class="mi-sort" data-sort="classification">Phân loại <i class="fa-solid fa-sort"></i></span>
                                <span class="mi-filter-btn" data-filter="classification" title="Lọc phân loại"><i class="fa-solid fa-filter"></i></span>
                            </span>
                        </th>
                        <th class="num"><span class="mi-th-inner mi-sort" data-sort="quantity">Tồn kho <i class="fa-solid fa-sort"></i></span></th>
                    </tr>
                </thead>
                <tbody id="mi-tbody"></tbody>
            </table>

            <!-- ============ Nhóm NVL đã lâu chưa dùng ============ -->
            <section class="mi-unused">
                <div class="mi-unused-head">
                    <h2><i class="fa-solid fa-hourglass-half"></i> Nhóm nguyên liệu đã lâu chưa dùng</h2>
                    <label for="mi-unused-months" class="sub">Còn tồn nhưng lần nhập gần nhất cách đây hơn:</label>
                    <select id="mi-unused-months">
                        <option value="1">1 tháng</option>
                        <option value="3" selected>3 tháng</option>
                        <option value="6">6 tháng</option>
                        <option value="12">1 năm</option>
                    </select>
                    <label for="mi-unused-datefilter" class="sub">Hiển thị:</label>
                    <select id="mi-unused-datefilter">
                        <option value="all">Tất cả</option>
                        <option value="dated">Chỉ dòng có ngày</option>
                    </select>
                    <span class="sub" id="mi-unused-count"></span>
                </div>
                <table class="mi-table">
                    <thead>
                        <tr>
                            <th>Tên NVL</th>
                            <th>Nhà cung cấp</th>
                            <th>Đơn vị</th>
                            <th>Phân loại</th>
                            <th class="num">Tồn kho</th>
                            <th>Nhập gần nhất</th>
                            <th>Thao tác</th>
                        </tr>
                    </thead>
                    <tbody id="mi-unused-tbody">
                        <tr><td colspan="7" class="mi-unused-loading">Đang tải…</td></tr>
                    </tbody>
                </table>
            </section>

            <!-- ============ Danh sách ngưng dùng (FAQ) ============ -->
            <section class="mi-discontinued" id="mi-discontinued">
                <div class="mi-disc-head" id="mi-disc-toggle">
                    <i class="fa-solid fa-chevron-right mi-disc-caret"></i>
                    <h2><i class="fa-solid fa-ban"></i> Danh sách ngưng dùng</h2>
                    <span class="sub" id="mi-disc-count"></span>
                </div>
                <div class="mi-disc-body" id="mi-disc-body" style="display:none;">
                    <input type="text" id="mi-disc-search" autocomplete="off" placeholder="Lọc theo tên NVL...">
                    <table class="mi-table">
                        <thead>
                            <tr>
                                <th>Tên NVL</th>
                                <th>Nhà cung cấp</th>
                                <th>Đơn vị</th>
                                <th>Phân loại</th>
                                <th class="num">Tồn kho</th>
                                <th>Thao tác</th>
                            </tr>
                        </thead>
                        <tbody id="mi-disc-tbody">
                            <tr><td colspan="6" class="mi-disc-loading">Đang tải…</td></tr>
                        </tbody>
                    </table>
                </div>
            </section>
        </div>
    </div>

    <!-- ============ MODAL : CÀI ĐẶT THỨ TỰ HIỂN THỊ NVL ============ -->
    <div class="mi-modal" id="mi-setting-modal">
        <div class="mi-modal-overlay" data-mi-close></div>
        <div class="mi-modal-box">
            <span class="mi-modal-close" data-mi-close>&times;</span>
            <h3><i class="fa-solid fa-gear"></i> Thứ tự hiển thị nguyên vật liệu</h3>
            <p class="mi-modal-hint">Nhập số thứ tự (1, 2, 3…) — số nhỏ hiện trước; bỏ trống xếp cuối (theo A→B). Giúp gộp các NVL tương đồng cạnh nhau.</p>
            <div class="mi-set-tools">
                <input type="text" id="mi-setting-search" autocomplete="off" placeholder="Lọc theo tên NVL...">
            </div>
            <div class="mi-set-list" id="mi-setting-list">
                <div class="mi-set-loading">Đang tải…</div>
            </div>
            <div class="mi-set-foot">
                <button type="button" class="mi-btn-save" id="mi-setting-save">Lưu thứ tự</button>
            </div>
        </div>
    </div>

    <!-- ============ MODAL : PHÂN TÍCH NVL ============ -->
    <div class="mi-modal" id="mi-analysis-modal">
        <div class="mi-modal-overlay" data-an-close></div>
        <div class="mi-modal-box" style="width:460px;">
            <span class="mi-modal-close" data-an-close>&times;</span>
            <div class="mi-an-head">
                <h3><i class="fa-solid fa-chart-pie"></i> Phân tích nguyên vật liệu</h3>
                <div class="mi-an-title" id="mi-an-name">—</div>
                <div class="mi-an-cat" id="mi-an-sub"></div>
            </div>
            <div class="mi-an-body" id="mi-an-body"><div class="mi-an-loading">Đang tải…</div></div>
            <div class="mi-set-foot">
                <button type="button" class="mi-btn-save" id="mi-an-excel" disabled>
                    <i class="fa-solid fa-file-excel"></i> Xuất Excel
                </button>
            </div>
        </div>
    </div>

    <style>
        .mi-an-head { padding: 2px 0 12px; border-bottom: 1px solid #eef0f3; margin-bottom: 14px; }
        .mi-an-head h3 { margin: 0 0 6px; }
        .mi-an-title { font-size: 18px; font-weight: 700; color: #1f2733; }
        .mi-an-cat { font-size: 13px; color: #6b7280; margin-top: 2px; }
        .mi-an-loading { color: gray; text-align: center; padding: 24px; }
        .mi-an-grid { display: flex; flex-direction: column; gap: 10px; }
        .mi-an-row {
            display: flex; align-items: center; justify-content: space-between; gap: 12px;
            padding: 11px 14px; border: 1px solid #e6e9ee; border-radius: 10px; background: #fafbfc;
        }
        .mi-an-row .lab { font-size: 13.5px; color: #44505f; font-weight: 600; }
        .mi-an-row .val { font-size: 15px; color: #0f5132; font-weight: 700; text-align: right; }
        .mi-an-row.is-stock { background: #eaf7ef; border-color: #bfe6cd; }
        .mi-an-row.is-stock .val { font-size: 17px; }
        .mi-an-subnote { font-size: 11px; color: #98a2b3; font-weight: 500; }
        #mi-an-excel:disabled { opacity: .55; cursor: not-allowed; }
    </style>

    <script>
        (function () {
            var BASE = <?php echo json_encode($ajax_base); ?>;
            var DATA = <?php echo json_encode(array_values($materials), JSON_UNESCAPED_UNICODE); ?>;
            var IS_ADMIN = <?php echo !empty($is_admin) ? 'true' : 'false'; ?>;

            // Số tồn kho: bỏ phần thập phân nếu là số nguyên (không ghép đuôi đơn vị).
            function fmtQty(q) {
                var n = Number(q) || 0;
                if (Math.round(n) === n) return String(Math.round(n));
                return n.toFixed(3).replace(/0+$/, '').replace(/\.$/, '');
            }

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

            // sortKey '' = giữ thứ tự hiển thị đã cài đặt (server trả theo mi_material_display_order).
            // Khóa lọc trùng tên cột data (supplier_name / classification) để khớp data-filter.
            var state = { kw: '', supplier_name: null, classification: null, sortKey: '', sortDir: 1 };

            var $tbody = document.getElementById('mi-tbody');
            var $count = document.getElementById('mi-count');

            function filtered() {
                return DATA.filter(function (r) {
                    if (state.kw && String(r.material_name || '').toLowerCase().indexOf(state.kw) === -1) return false;
                    if (state.supplier_name && !state.supplier_name.has(r.supplier_name)) return false;
                    if (state.classification && !state.classification.has(r.classification)) return false;
                    return true;
                });
            }
            function sortRows(rows) {
                var k = state.sortKey, dir = state.sortDir;
                if (!k) return rows;   // giữ nguyên thứ tự hiển thị đã cài đặt
                return rows.slice().sort(function (a, b) {
                    var va = a[k], vb = b[k];
                    if (k === 'quantity') { va = Number(va) || 0; vb = Number(vb) || 0; return (va - vb) * dir; }
                    return String(va || '').localeCompare(String(vb || ''), 'vi') * dir;
                });
            }
            function render() {
                var rows = sortRows(filtered());
                if (!rows.length) {
                    $tbody.innerHTML = '<tr><td colspan="5" class="mi-empty">Không có nguyên vật liệu phù hợp.</td></tr>';
                } else {
                    $tbody.innerHTML = rows.map(function (r) {
                        var negCls = (Number(r.quantity) < 0) ? ' mi-qty-neg' : '';
                        var qtyCell = IS_ADMIN
                            ? '<td class="num mi-qty-cell' + negCls + '" data-material-id="' + r.material_id + '" data-qty="' + r.quantity + '" title="Bấm để sửa tồn kho">' + fmtQty(r.quantity) + '</td>'
                            : '<td class="num' + negCls + '">' + fmtQty(r.quantity) + '</td>';
                        return '<tr>'
                            + '<td class="mi-name-cell" data-material-id="' + r.material_id + '">' + escHtml(r.material_name) + '</td>'
                            + '<td>' + escHtml(r.supplier_name) + '</td>'
                            + '<td class="center">' + escHtml(r.unit) + '</td>'
                            + '<td class="center">' + escHtml(r.classification) + '</td>'
                            + qtyCell
                            + '</tr>';
                    }).join('');
                }
                if ($count) $count.textContent = rows.length + '/' + DATA.length + ' nguyên vật liệu';
            }

            /* ---- Search ---- */
            var $search = document.getElementById('mi-search');
            if ($search) $search.addEventListener('input', function () { state.kw = (this.value || '').trim().toLowerCase(); render(); });
            if ($search) $search.focus();

            /* ---- Sort ---- */
            document.querySelectorAll('#mi-table thead .mi-sort').forEach(function (el) {
                el.addEventListener('click', function () {
                    var key = el.getAttribute('data-sort');
                    if (state.sortKey === key) { state.sortDir = -state.sortDir; }
                    else { state.sortKey = key; state.sortDir = 1; }
                    document.querySelectorAll('#mi-table thead .mi-sort').forEach(function (s) {
                        s.classList.remove('is-asc', 'is-desc');
                        var ic = s.querySelector('i'); if (ic) ic.className = 'fa-solid fa-sort';
                    });
                    el.classList.add(state.sortDir === 1 ? 'is-asc' : 'is-desc');
                    var icon = el.querySelector('i'); if (icon) icon.className = 'fa-solid ' + (state.sortDir === 1 ? 'fa-sort-up' : 'fa-sort-down');
                    render();
                });
            });

            /* ---- Bộ lọc nhóm (nhà cung cấp / phân loại) ---- */
            function distinctValues(key) {
                var seen = {}, out = [];
                DATA.forEach(function (r) { var v = r[key]; if (!seen[v]) { seen[v] = true; out.push(v); } });
                out.sort(function (a, b) { return String(a).localeCompare(String(b), 'vi'); });
                return out;
            }
            function closePops() { document.querySelectorAll('.mi-pop').forEach(function (p) { p.remove(); }); }
            function openFilter(btn, key) {
                var values = distinctValues(key);
                var active = state[key];
                var pop = document.createElement('div');
                pop.className = 'mi-pop show';
                var items = values.map(function (v) {
                    var checked = (!active || active.has(v)) ? 'checked' : '';
                    return '<label><input type="checkbox" value="' + escAttr(v) + '" ' + checked + '>' + escHtml(v) + '</label>';
                }).join('');
                pop.innerHTML = '<div class="mi-pop-list">'
                    + '<label style="border-bottom:1px solid #eee;font-weight:600;"><input type="checkbox" class="mi-pop-all" ' + (!active ? 'checked' : '') + '>(Chọn tất cả)</label>'
                    + items + '</div>'
                    + '<div class="mi-pop-foot"><button type="button" class="mi-pop-clear">Bỏ lọc</button><button type="button" class="mi-pop-apply">Lọc</button></div>';
                btn.closest('th').appendChild(pop);
                pop.addEventListener('click', function (e) { e.stopPropagation(); });

                var allCb = pop.querySelector('.mi-pop-all');
                var cbs = Array.prototype.slice.call(pop.querySelectorAll('.mi-pop-list input:not(.mi-pop-all)'));
                allCb.addEventListener('change', function () { cbs.forEach(function (c) { c.checked = allCb.checked; }); });
                cbs.forEach(function (c) { c.addEventListener('change', function () { allCb.checked = cbs.every(function (x) { return x.checked; }); }); });

                pop.querySelector('.mi-pop-apply').addEventListener('click', function () {
                    var picked = cbs.filter(function (c) { return c.checked; }).map(function (c) { return c.value; });
                    if (picked.length === cbs.length) { state[key] = null; btn.classList.remove('is-active'); }
                    else { state[key] = new Set(picked); btn.classList.add('is-active'); }
                    render(); closePops();
                });
                pop.querySelector('.mi-pop-clear').addEventListener('click', function () {
                    state[key] = null; btn.classList.remove('is-active'); render(); closePops();
                });
            }
            /* Bộ lọc Nhà cung cấp: ô input + dropdown gợi ý, chọn bằng Enter/Tab. */
            function openSupplierFilter(btn, key) {
                var values = distinctValues(key);
                var pop = document.createElement('div');
                pop.className = 'mi-pop show';
                pop.innerHTML = '<div class="mi-pop-search-wrap"><input type="text" class="mi-pop-search" placeholder="Nhập tên nhà cung cấp..."></div>'
                    + '<div class="mi-pop-suggest"></div>'
                    + '<div class="mi-pop-foot"><button type="button" class="mi-pop-clear">Bỏ lọc</button></div>';
                btn.closest('th').appendChild(pop);
                pop.addEventListener('click', function (e) { e.stopPropagation(); });

                var inp = pop.querySelector('.mi-pop-search');
                var sug = pop.querySelector('.mi-pop-suggest');
                var items = [], active = -1;
                function highlight() {
                    sug.querySelectorAll('.mi-sug-item').forEach(function (el, i) {
                        el.classList.toggle('active', i === active);
                        if (i === active) el.scrollIntoView({ block: 'nearest' });
                    });
                }
                function renderSug() {
                    var kw = (inp.value || '').trim().toLowerCase();
                    items = values.filter(function (v) { return kw === '' || String(v).toLowerCase().indexOf(kw) !== -1; });
                    active = items.length ? 0 : -1;
                    sug.innerHTML = items.length
                        ? items.map(function (v, i) { return '<div class="mi-sug-item' + (i === active ? ' active' : '') + '" data-i="' + i + '">' + escHtml(v) + '</div>'; }).join('')
                        : '<div class="mi-sug-empty">Không tìm thấy</div>';
                }
                function pick(v) {
                    state[key] = new Set([v]); btn.classList.add('is-active'); render(); closePops();
                }
                inp.addEventListener('input', renderSug);
                inp.addEventListener('keydown', function (e) {
                    if (e.key === 'ArrowDown') { e.preventDefault(); active = Math.min(active + 1, items.length - 1); highlight(); }
                    else if (e.key === 'ArrowUp') { e.preventDefault(); active = Math.max(active - 1, 0); highlight(); }
                    else if (e.key === 'Enter' || e.key === 'Tab') { if (active >= 0 && items[active]) { e.preventDefault(); pick(items[active]); } }
                    else if (e.key === 'Escape') { closePops(); }
                });
                sug.addEventListener('click', function (e) { var it = e.target.closest('.mi-sug-item'); if (it) pick(items[+it.getAttribute('data-i')]); });
                pop.querySelector('.mi-pop-clear').addEventListener('click', function () {
                    state[key] = null; btn.classList.remove('is-active'); render(); closePops();
                });
                if (state[key] && state[key].size === 1) inp.value = Array.from(state[key])[0];
                renderSug();
                inp.focus();
            }
            document.querySelectorAll('.mi-filter-btn').forEach(function (btn) {
                btn.addEventListener('click', function (e) {
                    e.stopPropagation();
                    var key = btn.getAttribute('data-filter');
                    var open = btn.closest('th').querySelector('.mi-pop');
                    closePops();
                    if (open) return;
                    if (key === 'supplier_name') openSupplierFilter(btn, key);
                    else openFilter(btn, key);
                });
            });
            document.addEventListener('click', closePops);

            render();

            /* ---- Sửa tồn kho tại chỗ (chỉ admin): hover hiện viền, click mở input,
               click ra ngoài (blur) là lưu qua AJAX + đồng bộ lại DATA để sort/lọc đúng. ---- */
            if (IS_ADMIN) {
                $tbody.addEventListener('click', function (e) {
                    var cell = e.target.closest('.mi-qty-cell');
                    if (!cell || cell.querySelector('input')) return;
                    var mid = cell.getAttribute('data-material-id');
                    var oldVal = cell.getAttribute('data-qty');
                    cell.innerHTML = '<input type="number" step="any" class="mi-qty-input" value="' + oldVal + '">';
                    var inp = cell.querySelector('input');
                    inp.focus(); inp.select();
                    inp.addEventListener('blur', function () { commitQtyEdit(cell, mid, inp.value, oldVal); });
                    inp.addEventListener('keydown', function (ev) {
                        if (ev.key === 'Enter') { ev.preventDefault(); inp.blur(); }
                        else if (ev.key === 'Escape') { inp.value = oldVal; inp.blur(); }
                    });
                });
            }
            function commitQtyEdit(cell, materialId, newVal, oldVal) {
                var q = parseFloat(newVal);
                if (isNaN(q)) q = parseFloat(oldVal) || 0;
                cell.textContent = fmtQty(q);
                cell.setAttribute('data-qty', q);
                cell.classList.toggle('mi-qty-neg', q < 0);
                if (q === parseFloat(oldVal)) return; // không đổi -> khỏi gọi AJAX
                for (var i = 0; i < DATA.length; i++) {
                    if (String(DATA[i].material_id) === String(materialId)) { DATA[i].quantity = q; break; }
                }
                var fd = new FormData(); fd.append('material_id', materialId); fd.append('quantity', q);
                fetch(BASE + 'mi_update_quantity', { method: 'POST', body: fd, credentials: 'same-origin' })
                    .then(function (r) { return r.json(); })
                    .then(function (res) { if (!res || !res.ok) alert((res && res.message) || 'Không lưu được tồn kho.'); })
                    .catch(function () { alert('Lỗi mạng/Server.'); });
            }

            /* ---- Nhóm NVL đã lâu chưa dùng ---- */
            var $months = document.getElementById('mi-unused-months');
            var $dateFilter = document.getElementById('mi-unused-datefilter');
            var $uBody  = document.getElementById('mi-unused-tbody');
            var $uCount = document.getElementById('mi-unused-count');
            var unusedData = [];

            // Bộ lọc "Tất cả / Chỉ dòng có ngày" — nhớ qua localStorage (cố định đến khi user đổi).
            var DF_KEY = 'mi_unused_datefilter';
            try { var saved = localStorage.getItem(DF_KEY); if (saved && $dateFilter) $dateFilter.value = saved; } catch (e) {}

            function renderUnused() {
                var onlyDated = $dateFilter && $dateFilter.value === 'dated';
                var rows = unusedData.filter(function (r) { return !onlyDated || r.days_ago != null; });
                if ($uCount) $uCount.textContent = rows.length ? ('(' + rows.length + ' nguyên liệu)') : '';
                if (!rows.length) { $uBody.innerHTML = '<tr><td colspan="7" class="mi-unused-empty">Không có nguyên liệu phù hợp trong mốc này.</td></tr>'; return; }
                $uBody.innerHTML = rows.map(function (r) {
                    var ago = (r.days_ago != null) ? (r.last_used_text + ' (' + relTime(r.days_ago) + ')') : r.last_used_text;
                    return '<tr>'
                        + '<td>' + escHtml(r.material_name) + '</td>'
                        + '<td>' + escHtml(r.supplier_name) + '</td>'
                        + '<td>' + escHtml(r.unit) + '</td>'
                        + '<td>' + escHtml(r.classification) + '</td>'
                        + '<td class="num">' + escHtml(r.inventory_text) + '</td>'
                        + '<td class="last">' + escHtml(ago) + '</td>'
                        + '<td><button type="button" class="mi-act-btn mi-act-stop" data-stop="' + r.material_id + '">Ngưng dùng</button></td>'
                        + '</tr>';
                }).join('');
            }
            function loadUnused() {
                $uBody.innerHTML = '<tr><td colspan="7" class="mi-unused-loading">Đang tải…</td></tr>';
                var fd = new FormData(); fd.append('months', $months.value);
                fetch(BASE + 'mi_long_unused', { method: 'POST', body: fd, credentials: 'same-origin' })
                    .then(function (r) { return r.json(); })
                    .then(function (res) {
                        if (!res || !res.ok) { $uBody.innerHTML = '<tr><td colspan="7" class="mi-unused-empty">Không tải được.</td></tr>'; return; }
                        unusedData = res.data || [];
                        renderUnused();
                    })
                    .catch(function () { $uBody.innerHTML = '<tr><td colspan="7" class="mi-unused-empty">Lỗi kết nối.</td></tr>'; });
            }
            if ($months) $months.addEventListener('change', loadUnused);
            if ($dateFilter) $dateFilter.addEventListener('change', function () {
                try { localStorage.setItem(DF_KEY, $dateFilter.value); } catch (e) {}
                renderUnused();
            });
            loadUnused();

            /* ---- Danh sách ngưng dùng + Dùng lại ---- */
            var $discBody = document.getElementById('mi-disc-tbody');
            var $discSearch = document.getElementById('mi-disc-search');
            var $discCount = document.getElementById('mi-disc-count');
            var discData = [];
            function renderDisc() {
                var kw = ($discSearch && $discSearch.value || '').trim().toLowerCase();
                var rows = discData.filter(function (r) { return kw === '' || String(r.material_name || '').toLowerCase().indexOf(kw) !== -1; });
                if ($discCount) $discCount.textContent = rows.length ? ('(' + rows.length + ')') : '';
                if (!rows.length) { $discBody.innerHTML = '<tr><td colspan="6" class="mi-disc-empty">Chưa có nguyên liệu nào bị ngưng dùng.</td></tr>'; return; }
                $discBody.innerHTML = rows.map(function (r) {
                    return '<tr>'
                        + '<td>' + escHtml(r.material_name) + '</td>'
                        + '<td>' + escHtml(r.supplier_name) + '</td>'
                        + '<td>' + escHtml(r.unit) + '</td>'
                        + '<td>' + escHtml(r.classification) + '</td>'
                        + '<td class="num">' + escHtml(r.inventory_text) + '</td>'
                        + '<td style="white-space:nowrap;"><button type="button" class="mi-act-btn mi-act-resume" data-resume="' + r.material_id + '">Dùng lại</button>'
                        + '<button type="button" class="mi-act-btn mi-act-del" data-del="' + r.material_id + '" data-name="' + escAttr(r.material_name) + '">Xóa</button></td>'
                        + '</tr>';
                }).join('');
            }
            function loadDisc() {
                $discBody.innerHTML = '<tr><td colspan="6" class="mi-disc-loading">Đang tải…</td></tr>';
                fetch(BASE + 'mi_discontinued_list', { credentials: 'same-origin' })
                    .then(function (r) { return r.json(); })
                    .then(function (res) {
                        if (!res || !res.ok) { $discBody.innerHTML = '<tr><td colspan="6" class="mi-disc-empty">Không tải được.</td></tr>'; return; }
                        discData = res.data || [];
                        renderDisc();
                    })
                    .catch(function () { $discBody.innerHTML = '<tr><td colspan="6" class="mi-disc-empty">Lỗi kết nối.</td></tr>'; });
            }
            if ($discSearch) $discSearch.addEventListener('input', renderDisc);
            loadDisc();

            function setDiscontinued(materialId, on) {
                var fd = new FormData(); fd.append('material_id', materialId); fd.append('on', on ? 1 : 0);
                return fetch(BASE + 'mi_set_discontinued', { method: 'POST', body: fd, credentials: 'same-origin' })
                    .then(function (r) { return r.json(); })
                    .then(function () { loadUnused(); loadDisc(); });
            }
            function deleteMaterial(materialId, name) {
                if (!confirm('Xóa vĩnh viễn nguyên vật liệu "' + name + '"?\n\nThao tác này xóa NVL và dữ liệu cấu hình liên quan (chỉ giữ lại dữ liệu nhập/xuất) và KHÔNG THỂ HOÀN TÁC.')) return;
                var fd = new FormData(); fd.append('material_id', materialId);
                fetch(BASE + 'mi_delete_material', { method: 'POST', body: fd, credentials: 'same-origin' })
                    .then(function (r) { return r.json(); })
                    .then(function (res) {
                        if (res && res.ok) { location.reload(); }
                        else { alert('Xóa thất bại.'); }
                    })
                    .catch(function () { alert('Lỗi kết nối.'); });
            }
            $uBody.addEventListener('click', function (e) {
                var btn = e.target.closest('[data-stop]'); if (!btn) return;
                setDiscontinued(+btn.getAttribute('data-stop'), true);
            });
            $discBody.addEventListener('click', function (e) {
                var del = e.target.closest('[data-del]');
                if (del) { deleteMaterial(+del.getAttribute('data-del'), del.getAttribute('data-name') || ''); return; }
                var btn = e.target.closest('[data-resume]'); if (!btn) return;
                setDiscontinued(+btn.getAttribute('data-resume'), false);
            });

            /* ---- FAQ: click tiêu đề mới xổ danh sách ngưng dùng ---- */
            var $discSection = document.getElementById('mi-discontinued');
            var $discToggle = document.getElementById('mi-disc-toggle');
            var $discBodyWrap = document.getElementById('mi-disc-body');
            if ($discToggle) $discToggle.addEventListener('click', function () {
                var open = $discSection.classList.toggle('is-open');
                $discBodyWrap.style.display = open ? '' : 'none';
            });

            /* ---- Đồng bộ vị trí dính của thead = chiều cao toolbar (không hở dải trắng) ---- */
            function syncStickyTop() {
                var tb = document.querySelector('.mi-toolbar'); if (!tb) return;
                var h = tb.offsetHeight;
                document.querySelectorAll('#mi-table thead th').forEach(function (th) { th.style.top = h + 'px'; });
            }
            syncStickyTop();
            window.addEventListener('resize', syncStickyTop);

            /* ---- Modal cài đặt thứ tự hiển thị NVL ---- */
            var $modal = document.getElementById('mi-setting-modal');
            function openModal() { if ($modal) $modal.classList.add('show'); }
            function closeModal() { if ($modal) $modal.classList.remove('show'); }
            document.querySelectorAll('[data-mi-close]').forEach(function (el) { el.addEventListener('click', closeModal); });

            var settingLoaded = false;
            var $setBtn = document.getElementById('mi-setting-btn');
            if ($setBtn) $setBtn.addEventListener('click', function () {
                openModal();
                if (settingLoaded) return;
                fetch(BASE + 'mi_display_order_list', { credentials: 'same-origin' })
                    .then(function (r) { return r.json(); })
                    .then(function (res) {
                        var list = document.getElementById('mi-setting-list');
                        if (!res || !res.ok) { list.innerHTML = '<div class="mi-set-loading">Không tải được.</div>'; return; }
                        var html = '<table class="mi-set-table"><thead><tr><th>Tên NVL</th><th style="width:90px;">Thứ tự</th></tr></thead><tbody>';
                        res.data.forEach(function (m) {
                            var nm = String(m.material_name || '');
                            var sup = m.supplier_name ? '<div class="mi-set-cat">' + escHtml(m.supplier_name) + '</div>' : '';
                            html += '<tr data-name="' + escAttr(nm.toLowerCase()) + '">'
                                + '<td>' + escHtml(nm) + sup + '</td>'
                                + '<td><input type="number" class="mi-set-order-input" min="1" step="1" data-material-id="' + m.material_id + '" value="' + (m.sort_order != null ? m.sort_order : '') + '"></td>'
                                + '</tr>';
                        });
                        html += '</tbody></table>';
                        list.innerHTML = html;
                        settingLoaded = true;
                    });
            });
            var $setSearch = document.getElementById('mi-setting-search');
            if ($setSearch) $setSearch.addEventListener('input', function () {
                var kw = (this.value || '').trim().toLowerCase();
                document.querySelectorAll('#mi-setting-list .mi-set-table tbody tr').forEach(function (tr) {
                    tr.style.display = (kw === '' || String(tr.getAttribute('data-name')).indexOf(kw) !== -1) ? '' : 'none';
                });
            });
            var $setSave = document.getElementById('mi-setting-save');
            if ($setSave) $setSave.addEventListener('click', function () {
                var orders = {};
                document.querySelectorAll('#mi-setting-list .mi-set-order-input').forEach(function (inp) {
                    orders[inp.getAttribute('data-material-id')] = inp.value;
                });
                var btn = this; btn.disabled = true; btn.textContent = 'Đang lưu...';
                var fd = new FormData(); fd.append('orders', JSON.stringify(orders));
                fetch(BASE + 'mi_save_display_order', { method: 'POST', body: fd, credentials: 'same-origin' })
                    .then(function (r) { return r.json(); })
                    .then(function (res) {
                        if (res && res.ok) { location.reload(); }
                        else { alert('Không lưu được.'); btn.disabled = false; btn.textContent = 'Lưu thứ tự'; }
                    })
                    .catch(function () { alert('Lỗi mạng/Server.'); btn.disabled = false; btn.textContent = 'Lưu thứ tự'; });
            });

            /* ---- Modal phân tích NVL (click vào tên NVL) ---- */
            var $anModal = document.getElementById('mi-analysis-modal');
            var $anName  = document.getElementById('mi-an-name');
            var $anSub   = document.getElementById('mi-an-sub');
            var $anBody  = document.getElementById('mi-an-body');
            var $anExcel = document.getElementById('mi-an-excel');
            var curAnalysis = null;

            function anRow(lab, val, extra, sub) {
                return '<div class="mi-an-row' + (extra || '') + '">'
                    + '<span class="lab">' + escHtml(lab) + (sub ? ' <span class="mi-an-subnote">' + escHtml(sub) + '</span>' : '') + '</span>'
                    + '<span class="val">' + escHtml(val) + '</span></div>';
            }
            function openAnModal() { if ($anModal) $anModal.classList.add('show'); }
            function closeAnModal() { if ($anModal) $anModal.classList.remove('show'); }
            document.querySelectorAll('[data-an-close]').forEach(function (el) { el.addEventListener('click', closeAnModal); });

            function loadAnalysis(materialId) {
                openAnModal();
                $anName.textContent = 'Đang tải…'; $anSub.textContent = '';
                $anBody.innerHTML = '<div class="mi-an-loading">Đang tải…</div>';
                $anExcel.disabled = true; curAnalysis = null;
                var fd = new FormData(); fd.append('material_id', materialId);
                fetch(BASE + 'mi_material_analysis', { method: 'POST', body: fd, credentials: 'same-origin' })
                    .then(function (r) { return r.json(); })
                    .then(function (res) {
                        if (!res || !res.ok) { $anBody.innerHTML = '<div class="mi-an-loading">Không tải được dữ liệu.</div>'; return; }
                        var d = res.data; curAnalysis = d;
                        $anName.textContent = d.material_name || '—';
                        $anSub.textContent = [d.classification, d.supplier_name].filter(Boolean).join('  •  ');
                        $anBody.innerHTML = '<div class="mi-an-grid">'
                            + anRow('Tồn kho hiện tại', d.stock_text, ' is-stock')
                            + anRow('Ngày mua gần đây', d.last_buy_date + (d.last_buy_qty && d.last_buy_qty !== '—' ? '  •  ' + d.last_buy_qty : ''))
                            + anRow('Dùng 1 tháng', d.use_1m, '', '(30 ngày)')
                            + anRow('Dùng 3 tháng', d.use_3m, '', '(90 ngày)')
                            + anRow('Dùng 6 tháng', d.use_6m, '', '(180 ngày)')
                            + '</div>';
                        $anExcel.disabled = false;
                    })
                    .catch(function () { $anBody.innerHTML = '<div class="mi-an-loading">Lỗi kết nối.</div>'; });
            }
            $tbody.addEventListener('click', function (e) {
                var cell = e.target.closest('.mi-name-cell'); if (!cell) return;
                var mid = cell.getAttribute('data-material-id'); if (!mid) return;
                loadAnalysis(mid);
            });
            if ($anExcel) $anExcel.addEventListener('click', function () {
                if (!curAnalysis) return;
                var d = curAnalysis;
                exportAnalysisExcel('Phan_tich_NVL', d.material_name, [
                    ['Nguyên vật liệu', d.material_name],
                    ['Phân loại', d.classification],
                    ['Nhà cung cấp', d.supplier_name],
                    ['Tồn kho hiện tại', d.stock_text],
                    ['Ngày mua gần đây', d.last_buy_date],
                    ['Số lượng mua gần nhất', d.last_buy_qty],
                    ['Dùng 1 tháng (30 ngày)', d.use_1m],
                    ['Dùng 3 tháng (90 ngày)', d.use_3m],
                    ['Dùng 6 tháng (180 ngày)', d.use_6m]
                ]);
            });
        })();
    </script>
    <script src="<?php echo asset_ver('public/js/shared/app_shell.js'); ?>"></script>
</body>

</html>
