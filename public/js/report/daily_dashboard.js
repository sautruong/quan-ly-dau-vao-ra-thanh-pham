(function () {
    'use strict';

    var CFG = window.REPORT_CFG || { baseUrl: '?mod=report&controllers=report&action=' };
    var INITIAL = window.DD2_INITIAL || { output: { months: [] }, exports: { series: { months: [], current: 0 } }, production_day: { date: '', rows: [] } };
    var currentCustomerId = 0;
    var currentSupplierId = 0;
    // Ngày "hôm nay" phía server (khớp date('Y-m-d') dùng để render bullet lần đầu ở PHP view) — dùng
    // chung cho bullet Nhập kho/Quỹ khi render lại qua AJAX phân trang, tránh lệch múi giờ nếu chỉ dùng Date() phía trình duyệt.
    var TODAY_ISO = (INITIAL.production_day && INITIAL.production_day.date) || new Date().toISOString().slice(0, 10);

    /* ---------------- Helpers ---------------- */
    function escapeHtml(s) {
        return String(s == null ? '' : s)
            .replace(/&/g, '&amp;').replace(/</g, '&lt;')
            .replace(/>/g, '&gt;').replace(/"/g, '&quot;').replace(/'/g, '&#39;');
    }

    // Dòng "SP thiếu tồn" của Chi nhánh đặt hàng: escape trước rồi tô màu riêng phần "(thiếu N)"
    // (SP không tra được công thức/NVL — xem rp_dd_branch_order_shortage_lines() phía PHP).
    function escapeShortageLine(l) {
        return escapeHtml(l).replace(/(\(thiếu[^)]*\))$/, '<span class="dd2-shortage-qty">$1</span>');
    }

    function fmtNum(v) {
        var n = Number(v) || 0;
        var rounded = Math.round(n);
        if (Math.abs(n - rounded) < 1e-9) n = rounded;
        else n = parseFloat(n.toFixed(2));
        return n.toLocaleString('en-US');
    }
    function fmtMoney(v) { return fmtNum(v) + ' đ'; }

    // Badge số lượng cạnh tiêu đề card ("Sản xuất hôm nay/7 sản phẩm", "Đặt hàng nguyên liệu/6 đơn hàng")
    // — chỉ hiện khi count > 5, chữ mờ/nhỏ hơn tiêu đề (xem .dd2-title-count).
    function setCardCount(elId, count, unit) {
        var el = document.getElementById(elId);
        if (!el) return;
        var n = Number(count) || 0;
        if (n > 5) { el.hidden = false; el.textContent = '/' + fmtNum(n) + ' ' + unit; }
        else { el.hidden = true; el.textContent = ''; }
    }

    function postForm(action, payload) {
        var body = new URLSearchParams();
        Object.keys(payload || {}).forEach(function (k) { body.append(k, payload[k]); });
        return fetch(CFG.baseUrl + action, {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body: body.toString()
        }).then(function (r) { return r.json(); });
    }

    function debounce(fn, wait) {
        var t = null;
        return function () {
            var ctx = this, args = arguments;
            clearTimeout(t);
            t = setTimeout(function () { fn.apply(ctx, args); }, wait);
        };
    }

    /* ---------------- Phân trang batch (< 1 2 3 4 >) ---------------- */
    function renderBatchPager(container, page, totalPages, onGoto) {
        if (!container) return;
        page = parseInt(page, 10) || 1;
        totalPages = parseInt(totalPages, 10) || 1;
        if (totalPages <= 1) { container.innerHTML = ''; return; }
        var batchSize = 4;
        var batchStart = Math.floor((page - 1) / batchSize) * batchSize + 1;
        var batchEnd = Math.min(batchStart + batchSize - 1, totalPages);

        function btn(p, label, opts) {
            opts = opts || {};
            return '<button type="button" class="dd2-page-btn' + (opts.active ? ' active' : '') +
                '" data-page="' + p + '"' + (opts.disabled ? ' disabled' : '') + '>' + label + '</button>';
        }
        var html = '';
        html += btn(Math.max(1, batchStart - 1), '‹', { disabled: batchStart <= 1 });
        for (var p = batchStart; p <= batchEnd; p++) html += btn(p, String(p), { active: p === page });
        html += btn(Math.min(totalPages, batchStart + batchSize), '›', { disabled: batchEnd >= totalPages });
        container.innerHTML = html;
        container.onclick = function (e) {
            var b = e.target.closest && e.target.closest('[data-page]');
            if (!b || b.disabled) return;
            onGoto(parseInt(b.getAttribute('data-page'), 10) || 1);
        };
    }

    /* ---------------- Render danh sách theo trang (AJAX) ---------------- */
    function rowsImports(list) {
        if (!list || !list.length) return '<p class="dd2-empty">Chưa có hóa đơn nhập kho.</p>';
        return list.map(function (r) {
            var cpmh = r.purchase_cost > 0 ? ' - <span class="dd2-cost is-warn">CPMH: ' + fmtMoney(r.purchase_cost) + '</span>' : '';
            var isToday = r.date_iso === TODAY_ISO;
            return '<div class="dd2-import-row">' +
                '<div class="dd2-import-row-top">' +
                '<div class="dd2-name-cell">' +
                '<span class="dd2-row-bullet ' + (isToday ? 'dd2-color-today' : 'dd2-color-gray') + '"></span>' +
                '<button type="button" class="dd2-entity-link" data-supplier-id="' + r.supplier_id + '" data-date-iso="' + escapeHtml(r.date_iso) + '">' + escapeHtml(r.supplier_label) + '</button>' +
                '</div>' +
                '<span class="dd2-value" data-items=\'' + JSON.stringify(r.items || []).replace(/'/g, '&#39;') + '\' data-total="' + escapeHtml(fmtMoney(r.inventory_value)) + '">' + fmtMoney(r.inventory_value) + '</span>' +
                '</div>' +
                '<span class="dd2-date-chip' + (isToday ? ' is-today' : '') + '">' + escapeHtml(r.date_label) + cpmh + '</span>' +
                '</div>';
        }).join('');
    }

    function rowsExports(list) {
        if (!list || !list.length) return '<p class="dd2-empty">Chưa có phiếu xuất kho.</p>';
        return list.map(function (r) {
            var style = r.color ? ' style="--dd2-accent:' + escapeHtml(r.color) + ';"' : '';
            // Khách lấy nhiều đơn trong cùng 1 ngày -> kèm giờ lấy hàng để phân biệt từng phiếu
            // (giá trị mỗi dòng giờ là của RIÊNG phiếu đó, không còn là tổng cả ngày).
            var multi = Number(r.same_day_count) > 1 && r.time_label;
            var timeChip = multi ? '<span class="dd2-time-chip" title="Giờ lấy hàng — khách có ' + r.same_day_count + ' đơn trong ngày">' + escapeHtml(r.time_label) + '</span>' : '';
            return '<div class="dd2-export-row' + (multi ? ' is-multi-day' : '') + '"' + style + '>' +
                '<div class="dd2-name-cell">' +
                '<button type="button" class="dd2-entity-link" data-customer-id="' + r.customer_id + '" data-date-iso="' + escapeHtml(r.date_iso) + '">' + escapeHtml(r.customer_label) + '</button>' +
                '<span class="dd2-date-chip">/ ' + escapeHtml(r.date_label) + '</span>' + timeChip +
                '</div>' +
                '<span class="dd2-qty">' + fmtNum(r.quantity) + ' SP</span>' +
                '<span class="dd2-weight">' + fmtNum(r.weight) + ' kg</span>' +
                '<span class="dd2-value dd2-export-value" data-items=\'' + JSON.stringify(r.items || []).replace(/'/g, '&#39;') + '\' data-total="' + escapeHtml(fmtMoney(r.value)) + '">' + fmtMoney(r.value) + '</span>' +
                '</div>';
        }).join('');
    }

    function rowsFund(list) {
        if (!list || !list.length) return '<p class="dd2-empty">Chưa có giao dịch.</p>';
        return list.map(function (tx) {
            var isToday = tx.date_iso === TODAY_ISO;
            return '<div class="dd2-fund-row ' + (tx.is_income ? 'is-income' : '') + '">' +
                '<span class="dd2-row-bullet ' + (isToday ? 'dd2-color-today' : 'dd2-color-gray') + '"></span>' +
                '<span class="dd2-fund-desc" title="' + escapeHtml(tx.description) + '">' + escapeHtml(tx.description) + '</span>' +
                '<span class="dd2-fund-amount">' + fmtMoney(tx.amount) + '</span>' +
                '<span class="dd2-fund-date' + (isToday ? ' is-today' : '') + '">' + (isToday ? 'Hôm nay' : escapeHtml(tx.date_label)) + '</span>' +
                '</div>';
        }).join('');
    }

    function rowsMaterialOrders(list) {
        if (!list || !list.length) return '<p class="dd2-empty">Không có đơn nào đang chờ.</p>';
        // Mirror markup PHP (daily_dashboard.php khối "Đặt hàng nguyên liệu"): trạng thái Đã nhận
        // (stamp) + khớp/lệch giá trị — để đổi trang AJAX không mất các trạng thái này.
        return list.map(function (o) {
            var isMatch = o.value_match === true;
            var isMismatch = o.value_match === false;
            var valueHtml;
            if (isMismatch) {
                // Giá thực nhận đứng TRÊN, giá dự kiến (gạch đỏ) nằm DƯỚI (2026-07-23).
                valueHtml = '<span class="dd2-mo-value-new">' + fmtMoney(o.actual_value) + '</span>' +
                    '<span class="dd2-mo-value-old">' + fmtMoney(o.expected_value) + '</span>';
            } else {
                valueHtml = fmtMoney(isMatch ? o.actual_value : o.expected_value);
            }
            return '<div class="dd2-mo-row' + (o.received ? ' is-received' : '') + '">' +
                '<span class="dd2-mo-bullet dd2-color-' + o.age.color + (isMatch ? ' is-match' : '') + '"></span>' +
                '<div class="dd2-mo-main"><div class="dd2-mo-top">' +
                '<b class="dd2-mo-supplier' + (isMatch ? ' is-match' : '') + '">' + escapeHtml(o.supplier_name) + '</b>' +
                '<span class="dd2-mo-age dd2-color-' + o.age.color + '">/ ' + escapeHtml(o.age.text) + '</span>' +
                '<span class="dd2-mo-value-wrap"><span class="dd2-mo-item-count' + (isMatch ? ' is-match' : '') + '" data-price-breakdown=\'' + JSON.stringify(o.price_breakdown || []).replace(/'/g, '&#39;') + '\'>' + valueHtml + '</span></span>' +
                '</div><div class="dd2-mo-sub" title="' + escapeHtml(o.items_description) + '">' + escapeHtml(o.items_description) + '</div></div>' +
                (o.received ? '<span class="dd2-mo-stamp">Đã nhận</span>' : '') +
                '</div>';
        }).join('');
    }

    /** Danh sách ĐẦY ĐỦ đơn NVL (mọi trạng thái) — khác rowsMaterialOrders() vì không có o.age (chỉ dùng cho card đang chờ). */
    function rowsMaterialOrdersFull(list) {
        if (!list || !list.length) return '<p class="dd2-empty">Không có đơn nào.</p>';
        return list.map(function (o) {
            return '<div class="dd2-mo-row">' +
                '<span class="dd2-mo-bullet ' + (o.received ? 'dd2-color-gray' : 'dd2-color-today') + '"></span>' +
                '<div class="dd2-mo-main"><div class="dd2-mo-top">' +
                '<b class="dd2-mo-supplier">' + escapeHtml(o.supplier_name) + '</b>' +
                '<span class="dd2-mo-age dd2-color-gray">/ ' + escapeHtml(o.date_label) + (o.received ? ' · Đã nhận' : '') + '</span>' +
                '<span class="dd2-mo-item-count">' + fmtMoney(o.expected_value) + '</span>' +
                '</div><div class="dd2-mo-sub" title="' + escapeHtml(o.items_description) + '">' + escapeHtml(o.items_description) + '</div></div></div>';
        }).join('');
    }

    /** Modal "Sản lượng" đầy đủ: Ngày | Sản phẩm (tên hệ thống) | Số lượng | Giá vốn | Giá trị hàng hóa. */
    function rowsOutputFull(list) {
        if (!list || !list.length) return '<p class="dd2-empty">Chưa có dữ liệu.</p>';
        return '<table class="dd2-cost-table dd2-output-table"><thead><tr><th>Ngày</th><th>Sản phẩm</th><th>Số lượng</th><th>Giá vốn</th><th>Giá trị hàng hóa</th></tr></thead><tbody>' +
            list.map(function (r) {
                return '<tr><td>' + escapeHtml(r.date_label) + '</td><td>' + escapeHtml(r.product_name) + '</td><td>' + fmtNum(r.quantity) + '</td><td>' + fmtMoney(r.cost) + '</td><td>' + fmtMoney(r.value) + '</td></tr>';
            }).join('') + '</tbody></table>';
    }

    /** Modal "Sản xuất" đầy đủ cột: SL/GVSX/Giá trị/Biên độ/Cảnh báo/Tồn hiện tại. Xuất kho 1-3-6 tháng
     *  (2026-07-17) dồn xuống 1 dòng nhỏ NGAY DƯỚI tên sản phẩm (thay vì 3 cột riêng) để bớt cột, khỏi
     *  cuộn ngang — mỗi số có tooltip riêng ghi rõ đúng mốc tháng khi hover. */
    function rowsProductionFull(list) {
        if (!list || !list.length) return '<p class="dd2-empty">Chưa có sản phẩm nào.</p>';
        return '<table class="dd2-cost-table dd2-prod-full-table"><thead><tr>' +
            '<th>Tên sản phẩm</th><th>SL</th><th>GVSX</th><th>Giá trị</th><th>Biên độ</th><th>Cảnh báo</th><th>Tồn hiện tại</th>' +
            '</tr></thead><tbody>' +
            list.map(function (r) {
                var marginText = (r.margin === null || r.margin === undefined) ? '—' : (Math.round(r.margin * 10000) / 100) + '%';
                return '<tr><td>' + escapeHtml(r.name) +
                    '<div class="dd2-prod-full-issue">Xuất kho: ' +
                    '<span title="Xuất kho 1 tháng">' + escapeHtml(r.issue_1m) + '</span> | ' +
                    '<span title="Xuất kho 3 tháng">' + escapeHtml(r.issue_3m) + '</span> | ' +
                    '<span title="Xuất kho 6 tháng">' + escapeHtml(r.issue_6m) + '</span>' +
                    '</div></td>' +
                    '<td>' + fmtNum(r.quantity) + '</td><td>' + fmtMoney(r.cost) + '</td>' +
                    '<td>' + fmtMoney(r.value) + '</td><td>' + marginText + '</td>' +
                    '<td><span class="dd2-warn-dot ' + (r.warn_class || '') + '"></span></td>' +
                    '<td>' + fmtNum(r.stock_current) + '</td></tr>';
            }).join('') + '</tbody></table>';
    }

    /** Modal "Chi nhánh đặt hàng" đầy đủ, kèm dòng giải thích thiếu NVL nếu có. */
    function rowsBranchFull(list) {
        if (!list || !list.length) return '<p class="dd2-empty">Chưa có đơn hàng nào.</p>';
        return list.map(function (o) {
            var lines = (o.shortage_lines || []).map(function (l) {
                return '<div class="dd2-branch-shortage-line"><i class="fa-solid fa-triangle-exclamation"></i> ' + escapeShortageLine(l) + '</div>';
            }).join('');
            return '<div class="dd2-group-row dd2-group-row-block">' +
                '<div class="dd2-group-row-head"><span>' + escapeHtml(o.cust_short || o.customer_name || ('#' + o.id)) + '</span>' +
                '<span>' + fmtMoney((o.stats && o.stats.value_total) || 0) + '</span></div>' +
                (lines ? '<div class="dd2-branch-shortage">' + lines + '</div>' : '') +
                '</div>';
        }).join('');
    }

    function loadImportsPage(page) {
        return postForm('daily_dashboard_imports', { page: page, supplier_id: currentSupplierId }).then(function (res) {
            if (!res || !res.success) return;
            document.getElementById('dd2-imports-list').innerHTML = rowsImports(res.recent.rows);
            renderBatchPager(document.querySelector('[data-dd2-pager="imports"]'), res.recent.page, res.recent.total_pages, loadImportsPage);
            document.getElementById('dd2-import-count').textContent = res.summary.count;
            document.getElementById('dd2-import-value').textContent = fmtMoney(res.summary.inventory_value);
            document.getElementById('dd2-import-cpmh').textContent = fmtMoney(res.summary.purchase_cost);
        });
    }

    // Chart "Xuất kho" có 2 chế độ hiển thị (Doanh thu / Số lượng), chọn qua tab trong modal
    // "Thiết lập giá trị tạm (Xuất kho)" — giữ cache cả 2 series để đổi tab không cần gọi lại API.
    var exportChartMode = 'value';
    var lastExportSeries = (INITIAL.exports && INITIAL.exports.series) || { months: [], current: 0 };
    var lastExportSeriesQty = (INITIAL.exports && INITIAL.exports.series_qty) || { months: [], current: 0 };
    var exportAxis = (INITIAL.exports && INITIAL.exports.axis) || { min: 0, step: 0 };
    function renderCurrentExportChart() {
        var s = exportChartMode === 'quantity' ? lastExportSeriesQty : lastExportSeries;
        renderExportChart(s.months, s.current, exportChartMode, exportAxis);
        renderExportTodayBlock();
    }

    // Khối nổi "Today" trên biểu đồ Xuất kho — đơn xuất bán HÔM NAY, gộp theo khách hàng. Giá trị
    // hiển thị theo chế độ đang chọn (exportChartMode: value/quantity), giống chart. Không phụ thuộc
    // bộ lọc khách hàng của chart (luôn hiện TẤT CẢ khách hàng có đơn hôm nay).
    var exportTodayByCustomer = (INITIAL.exports && INITIAL.exports.today_by_customer) || [];
    function renderExportTodayBlock() {
        var el = document.getElementById('dd2-export-today');
        if (!el) return;
        if (!exportTodayByCustomer.length) { el.hidden = true; el.innerHTML = ''; return; }
        var rows = exportTodayByCustomer.map(function (c) {
            var val = exportChartMode === 'quantity' ? (fmtNum(c.quantity) + ' SP') : fmtMoney(c.value);
            var color = c.color || '#374151';
            return '<div class="dd2-export-today-row">+ <span class="dd2-export-today-cust" style="color:' + escapeHtml(color) + '">'
                + escapeHtml(c.customer_label) + '</span>: <span class="dd2-export-today-val">' + val + '</span></div>';
        }).join('');
        el.innerHTML = '<div class="dd2-export-today-title">Today</div>' + rows;
        el.hidden = false;
    }

    /** Cập nhật lại khối "Xuất kho" (stats + chart) sau khi đổi khách hàng lọc / lưu giá trị tạm. */
    function applyExportBlock(res) {
        document.getElementById('dd2-export-total-value').textContent = fmtMoney(res.summary.value);
        document.getElementById('dd2-export-month-label').textContent = res.summary.month_label;
        document.getElementById('dd2-export-count-line').textContent = res.summary.count + ' đơn hàng · ' + fmtNum(res.summary.quantity) + ' SP';
        lastExportSeries = res.series;
        if (res.series_qty) lastExportSeriesQty = res.series_qty;
        if (res.axis) exportAxis = res.axis;
        renderCurrentExportChart();
    }
    function reloadExportBlock() {
        return postForm('daily_dashboard_exports', { customer_id: currentCustomerId }).then(function (res) {
            if (res && res.success) applyExportBlock(res);
        });
    }

    function loadFundPage(page) {
        return postForm('daily_dashboard_fund', { page: page }).then(function (res) {
            if (!res || !res.success) return;
            document.getElementById('dd2-fund-recent-list').innerHTML = rowsFund(res.data.rows);
            renderBatchPager(document.querySelector('[data-dd2-pager="fund"]'), res.data.page, res.data.total_pages, loadFundPage);
        });
    }

    function loadMaterialOrdersPage(page) {
        return postForm('daily_dashboard_material_orders', { page: page }).then(function (res) {
            if (!res || !res.success) return;
            document.getElementById('dd2-material-orders-list').innerHTML = rowsMaterialOrders(res.data.rows);
            renderBatchPager(document.querySelector('[data-dd2-pager="material_orders"]'), res.data.page, res.data.total_pages, loadMaterialOrdersPage);
            setCardCount('dd2-material-count', res.data.total, 'đơn hàng');
        });
    }

    /* ---------------- Khối "Theo dõi tồn kho" (hàng dưới cùng) ---------------- */
    var SW_MIN_ROWS = 6;   // khớp $SW_MIN_ROWS phía PHP (2 dòng x 3 cột)
    var swWindow = 90, swCover = 14;   // cập nhật lại theo dữ liệu server mỗi lần nạp

    // Mirror dd2_battery() phía PHP.
    function batteryHtml(m) {
        var on = parseInt(m.segments, 10) || 0;
        var tip = 'Đã dùng ' + swWindow + ' ngày: ' + fmtNum(m.used) +
            ' · TB/ngày: ' + fmtNum(Math.round(m.avg_day * 10) / 10) +
            ' · Đủ dùng ' + swCover + ' ngày cần: ' + fmtNum(Math.round(m.target)) +
            ' · Đang có: ' + fmtNum(m.percent) + '%';
        var segs = '';
        for (var i = 1; i <= 10; i++) segs += '<i class="dd2-bat-seg' + (i <= on ? ' is-on' : '') + '"></i>';
        return '<span class="dd2-bat dd2-bat-' + escapeHtml(m.level) + '" title="' + escapeHtml(tip) + '">' +
            '<span class="dd2-bat-body">' + segs + '</span><span class="dd2-bat-cap"></span></span>';
    }

    // Mirror dd2_stock_watch_row() phía PHP.
    function swRowHtml(m, kind) {
        var tip = kind === 'material' ? 'Xem sản phẩm đang dùng nguyên liệu này' : 'Xem nguyên vật liệu của sản phẩm này';
        return '<div class="dd2-sw-row" data-kind="' + kind + '" data-id="' + m.id + '" title="' + tip + '">' +
            batteryHtml(m) +
            '<span class="dd2-sw-main"><span class="dd2-sw-name-row">' +
            '<span class="dd2-sw-name" title="' + escapeHtml(m.name) + '">' + escapeHtml(m.name) + '</span>' +
            '<button type="button" class="dd2-sw-hide" title="Ẩn khỏi khối (không xét nữa)"><i class="fa-solid fa-eye-slash"></i></button>' +
            '</span>' +
            '<span class="dd2-sw-stock dd2-sw-' + escapeHtml(m.level) + '">' + fmtNum(m.stock) +
            (m.unit ? ' ' + escapeHtml(m.unit) : '') + '</span></span></div>';
    }

    function swListHtml(list, kind, emptyText) {
        if (!list || !list.length) return '<p class="dd2-empty">' + emptyText + '</p>';
        var html = '';
        for (var i = 0; i < Math.max(SW_MIN_ROWS, list.length); i++) {
            html += list[i] ? swRowHtml(list[i], kind) : '<div class="dd2-sw-row is-empty"></div>';
        }
        return html;
    }

    function renderStockWatch(data) {
        if (!data) return;
        swWindow = data.window || swWindow;
        swCover = data.cover || swCover;
        var pBox = document.getElementById('dd2-sw-products');
        var mBox = document.getElementById('dd2-sw-materials');
        if (pBox) pBox.innerHTML = swListHtml(data.products, 'product', 'Chưa có dữ liệu bán hàng.');
        if (mBox) mBox.innerHTML = swListHtml(data.materials, 'material', 'Chưa có dữ liệu xuất dùng.');
        // Đồng bộ mốc đang chọn trong modal cài đặt (nếu đang mở).
        document.querySelectorAll('#dd2-sw-cover-opts .dd2-sw-cover-opt').forEach(function (b) {
            b.classList.toggle('is-active', parseInt(b.getAttribute('data-days'), 10) === parseInt(data.cover, 10));
        });
    }

    /* ---- Modal chi tiết: SP -> NVL trong công thức / NVL -> SP đang dùng ---- */
    var swDetailModal = document.getElementById('dd2-sw-detail-modal');
    var swHiddenModal = document.getElementById('dd2-sw-hidden-modal');

    function openSwDetail(kind, id, name) {
        if (!swDetailModal) return;
        var isMat = kind === 'material';
        document.getElementById('dd2-sw-detail-title').textContent = name || 'Chi tiết';
        document.getElementById('dd2-sw-detail-sub').textContent = isMat
            ? 'Các sản phẩm đang dùng nguyên liệu này và tồn kho thành phẩm hiện tại.'
            : 'Nguyên vật liệu trong công thức sản phẩm này và tồn kho hiện tại.';
        document.getElementById('dd2-sw-detail-body').innerHTML = '<p class="dd2-empty">Đang tải...</p>';
        swDetailModal.classList.add('is-open');
        swDetailModal.setAttribute('aria-hidden', 'false');

        postForm('daily_dashboard_sw_detail', { kind: kind, id: id }).then(function (res) {
            var box = document.getElementById('dd2-sw-detail-body');
            if (!res || !res.success) { box.innerHTML = '<p class="dd2-empty">' + escapeHtml((res && res.message) || 'Không tải được dữ liệu.') + '</p>'; return; }
            var items = (res.data && res.data.items) || [];
            if (!items.length) {
                box.innerHTML = '<p class="dd2-empty">' + (isMat
                    ? 'Chưa có sản phẩm nào dùng nguyên liệu này.'
                    : 'Sản phẩm này chưa có công thức nguyên vật liệu.') + '</p>';
                return;
            }
            // Chiều NVL -> SP có thêm cột "Mẻ dùng" = lượng NVL này trong công thức mẻ ĐẦU TIÊN của SP.
            box.innerHTML = '<table class="dd2-pci-table"><thead><tr>' +
                '<th>' + (isMat ? 'Tên sản phẩm' : 'Nguyên vật liệu') + '</th>' +
                '<th class="num">Định mức</th>' +
                (isMat ? '<th class="num">Mẻ dùng</th>' : '') +
                '<th class="num">Tồn hiện tại</th>' +
                '</tr></thead><tbody>' + items.map(function (it) {
                    var low = Number(it.stock) <= 0;
                    var nu = it.need_unit || it.unit;   // định mức luôn theo đơn vị NGUYÊN LIỆU
                    var batch = '';
                    if (isMat) {
                        batch = it.batch_text
                            ? '<td class="num" title="' + escapeHtml(it.batch_label || 'Công thức mẻ đầu tiên') + '">' +
                              escapeHtml(it.batch_text) + '</td>'
                            : '<td class="num dd2-pci-flat">—</td>';
                    }
                    return '<tr><td>' + escapeHtml(it.name) + '</td>' +
                        '<td class="num">' + fmtNum(it.need) + (nu ? ' ' + escapeHtml(nu) : '') + '</td>' +
                        batch +
                        '<td class="num' + (low ? ' dd2-pci-up' : '') + '">' + fmtNum(it.stock) +
                        (it.unit ? ' ' + escapeHtml(it.unit) : '') + '</td></tr>';
                }).join('') + '</tbody></table>';
        }).catch(function () {
            document.getElementById('dd2-sw-detail-body').innerHTML = '<p class="dd2-empty">Lỗi kết nối.</p>';
        });
    }

    function doSwHide(kind, id) {
        return postForm('daily_dashboard_sw_hide', { kind: kind, id: id, hidden: 1 }).then(function (res) {
            if (!res || !res.success) { alert((res && res.message) || 'Không ẩn được mục này.'); return; }
            renderStockWatch(res.data);
        }).catch(function () {});
    }

    /* ---- Modal bánh răng: danh sách đang ẩn + bật lại ---- */
    function renderHiddenList(items) {
        var box = document.getElementById('dd2-sw-hidden-body');
        if (!box) return;
        if (!items || !items.length) { box.innerHTML = '<p class="dd2-empty">Không có mục nào đang bị ẩn.</p>'; return; }
        box.innerHTML = '<table class="dd2-pci-table"><thead><tr><th>Tên</th><th>Nhóm</th><th class="num">Thao tác</th></tr></thead><tbody>' +
            items.map(function (it) {
                return '<tr><td>' + escapeHtml(it.name) + '</td>' +
                    '<td>' + (it.kind === 'material' ? 'Nguyên liệu' : 'Thành phẩm') + '</td>' +
                    '<td class="num"><button type="button" class="dd2-sw-unhide" data-kind="' + it.kind + '" data-id="' + it.id + '">' +
                    '<i class="fa-solid fa-eye"></i> Xét lại</button></td></tr>';
            }).join('') + '</tbody></table>';
    }

    function openSwHidden() {
        if (!swHiddenModal) return;
        document.getElementById('dd2-sw-hidden-body').innerHTML = '<p class="dd2-empty">Đang tải...</p>';
        swHiddenModal.classList.add('is-open');
        swHiddenModal.setAttribute('aria-hidden', 'false');
        postForm('daily_dashboard_sw_hidden_list', {}).then(function (res) {
            if (res && res.success) renderHiddenList(res.items);
        }).catch(function () {});
    }

    function wireStockWatch() {
        // Bấm ô -> modal chi tiết; bấm nút mắt-gạch -> ẩn (không mở modal).
        ['dd2-sw-products', 'dd2-sw-materials'].forEach(function (id) {
            var box = document.getElementById(id);
            if (!box) return;
            box.addEventListener('click', function (e) {
                var row = e.target.closest && e.target.closest('.dd2-sw-row');
                if (!row || row.classList.contains('is-empty')) return;
                var kind = row.getAttribute('data-kind');
                var itemId = parseInt(row.getAttribute('data-id'), 10) || 0;
                if (e.target.closest('.dd2-sw-hide')) { e.stopPropagation(); doSwHide(kind, itemId); return; }
                var nameEl = row.querySelector('.dd2-sw-name');
                openSwDetail(kind, itemId, nameEl ? nameEl.textContent : '');
            });
        });
        if (swDetailModal) {
            swDetailModal.querySelectorAll('[data-dd2-sw-close]').forEach(function (el) {
                el.addEventListener('click', function () {
                    swDetailModal.classList.remove('is-open');
                    swDetailModal.setAttribute('aria-hidden', 'true');
                });
            });
        }
        var swSettingsBtn = document.getElementById('dd2-sw-settings-btn');
        if (swSettingsBtn) swSettingsBtn.addEventListener('click', openSwHidden);
        if (swHiddenModal) {
            swHiddenModal.querySelectorAll('[data-dd2-swh-close]').forEach(function (el) {
                el.addEventListener('click', function () {
                    swHiddenModal.classList.remove('is-open');
                    swHiddenModal.setAttribute('aria-hidden', 'true');
                });
            });
            swHiddenModal.addEventListener('click', function (e) {
                // Đổi mốc thời gian đảm bảo bán/dùng -> tính lại pin cho cả 2 danh sách.
                var cov = e.target.closest && e.target.closest('.dd2-sw-cover-opt');
                if (cov) {
                    if (cov.classList.contains('is-active')) return;
                    postForm('daily_dashboard_sw_save_cover', { days: cov.getAttribute('data-days') }).then(function (res) {
                        if (!res || !res.success) { alert((res && res.message) || 'Không lưu được mốc thời gian.'); return; }
                        renderStockWatch(res.data);
                    }).catch(function () {});
                    return;
                }
                var b = e.target.closest && e.target.closest('.dd2-sw-unhide');
                if (!b) return;
                postForm('daily_dashboard_sw_hide', {
                    kind: b.getAttribute('data-kind'), id: b.getAttribute('data-id'), hidden: 0
                }).then(function (res) {
                    if (!res || !res.success) { alert((res && res.message) || 'Không bật lại được.'); return; }
                    renderStockWatch(res.data);
                    renderHiddenList(res.hidden_list);
                }).catch(function () {});
            });
        }
    }

    /* ---------------- Khối "Biến động giá nhập" (hàng dưới cùng) ---------------- */
    // Số dòng/trang — phải khớp RP_DD_PRICE_CHANGES_PER_PAGE phía PHP (đệm dòng rỗng cho card
    // luôn cao bằng nhau giữa các trang).
    var PC_PAGE_SIZE = 6;
    // Mirror bản render PHP trong daily_dashboard.php (cùng convention với rowsMaterialOrders).
    function rowsPriceChanges(list) {
        if (!list || !list.length) {
            return '<tr><td colspan="5" class="dd2-pc-empty">Chưa ghi nhận biến động giá nhập.</td></tr>';
        }
        var html = '';
        for (var i = 0; i < Math.max(PC_PAGE_SIZE, list.length); i++) {
            var c = list[i];
            if (!c) { html += '<tr class="dd2-pc-row is-empty"><td colspan="5"></td></tr>'; continue; }
            var isMat = c.kind === 'material';
            html += '<tr class="dd2-pc-row' + (isMat ? ' is-clickable' : '') + '"' +
                ' data-kind="' + escapeHtml(c.kind) + '" data-item-id="' + c.item_id + '"' +
                ' data-name="' + escapeHtml(c.name) + '" data-old="' + c.old_price + '" data-new="' + c.new_price + '"' +
                ' title="' + (isMat ? 'Bấm để xem sản phẩm bị ảnh hưởng giá vốn' : 'Biến động giá mua thành phẩm (không tính giá vốn sản xuất)') + '">' +
                '<td class="dd2-pc-date' + (c.date_iso === TODAY_ISO ? ' is-today' : '') + '">' + escapeHtml(c.date_label) + '</td>' +
                '<td class="dd2-pc-name" title="' + escapeHtml(c.name) + '">' + escapeHtml(c.name) + '</td>' +
                '<td class="dd2-pc-old">' + fmtMoney(c.old_price) + '</td>' +
                '<td class="dd2-pc-new">' + fmtMoney(c.new_price) + '</td>' +
                '<td class="dd2-pc-rate ' + (c.is_up ? 'is-up' : 'is-down') + '">' +
                '<i class="fa-solid fa-caret-' + (c.is_up ? 'up' : 'down') + '"></i> ' + fmtNum(c.change_rate) + '%</td>' +
                '</tr>';
        }
        return html;
    }

    function loadPriceChangesPage(page) {
        return postForm('daily_dashboard_price_changes', { page: page }).then(function (res) {
            if (!res || !res.success) return;
            document.getElementById('dd2-price-changes-body').innerHTML = rowsPriceChanges(res.data.rows);
            renderBatchPager(document.querySelector('[data-dd2-pager="price_changes"]'), res.data.page, res.data.total_pages, loadPriceChangesPage);
        });
    }

    /* ---- Modal "Giá vốn ảnh hưởng": dùng lại 2 endpoint của view row_material_receiving ---- */
    var IR_URL = '?mod=inventory_receiving&controllers=inventory_receiving&action=';
    var pciModal = document.getElementById('dd2-price-impact-modal');
    var pciCtx = null;   // {material_id, name, old_price, new_price} của dòng đang xem

    function postIr(action, payload) {
        var body = new URLSearchParams();
        Object.keys(payload || {}).forEach(function (k) { body.append(k, payload[k]); });
        return fetch(IR_URL + action, {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body: body.toString(),
            credentials: 'same-origin'
        }).then(function (r) { return r.json(); });
    }

    function closePriceImpactModal() {
        if (!pciModal) return;
        pciModal.classList.remove('is-open');
        pciModal.setAttribute('aria-hidden', 'true');
    }

    function rateCell(rate) {
        var r = Number(rate) || 0;
        var cls = r > 0 ? 'dd2-pci-up' : (r < 0 ? 'dd2-pci-down' : 'dd2-pci-flat');
        var ico = r > 0 ? '<i class="fa-solid fa-caret-up"></i> ' : (r < 0 ? '<i class="fa-solid fa-caret-down"></i> ' : '');
        return '<span class="' + cls + '">' + ico + fmtNum(r) + '%</span>';
    }

    // Lớp 1: danh sách sản phẩm dùng NVL này + giá vốn cũ/mới.
    function openPriceImpactModal(ctx) {
        if (!pciModal) return;
        pciCtx = ctx;
        document.getElementById('dd2-pci-back').hidden = true;
        document.getElementById('dd2-pci-title').textContent = 'Giá vốn ảnh hưởng';
        document.getElementById('dd2-pci-sub').innerHTML = escapeHtml(ctx.name) + ': ' +
            fmtMoney(ctx.old_price) + ' → <b>' + fmtMoney(ctx.new_price) + '</b>';
        document.getElementById('dd2-pci-body').innerHTML = '<p class="dd2-empty">Đang tải...</p>';
        pciModal.classList.add('is-open');
        pciModal.setAttribute('aria-hidden', 'false');

        postIr('ajax_material_cost_impact', {
            material_id: ctx.material_id, old_price: ctx.old_price, new_price: ctx.new_price
        }).then(function (res) {
            var box = document.getElementById('dd2-pci-body');
            if (!res || !res.success) { box.innerHTML = '<p class="dd2-empty">' + escapeHtml((res && res.message) || 'Không tải được dữ liệu.') + '</p>'; return; }
            var items = res.items || [];
            if (!items.length) { box.innerHTML = '<p class="dd2-empty">Chưa có sản phẩm nào dùng nguyên liệu này trong công thức.</p>'; return; }
            box.innerHTML = '<table class="dd2-pci-table"><thead><tr>' +
                '<th>Tên sản phẩm</th><th class="num">Giá vốn cũ</th><th class="num">Giá vốn mới</th><th class="num">Tỉ lệ biến động</th>' +
                '</tr></thead><tbody>' + items.map(function (it) {
                    return '<tr><td><button type="button" class="dd2-pci-product" data-product-id="' + it.product_id + '">' +
                        escapeHtml(it.product_name) + '</button></td>' +
                        '<td class="num">' + fmtMoney(it.old_cost) + '</td>' +
                        '<td class="num">' + fmtMoney(it.new_cost) + '</td>' +
                        '<td class="num">' + rateCell(it.change_rate) + '</td></tr>';
                }).join('') + '</tbody></table>';
        }).catch(function () {
            document.getElementById('dd2-pci-body').innerHTML = '<p class="dd2-empty">Lỗi kết nối.</p>';
        });
    }

    // Lớp 2: giải thích chi tiết giá vốn 1 sản phẩm (bấm tên sản phẩm ở lớp 1).
    function openCostBreakdown(productId) {
        if (!pciCtx) return;
        var box = document.getElementById('dd2-pci-body');
        box.innerHTML = '<p class="dd2-empty">Đang tải...</p>';
        document.getElementById('dd2-pci-back').hidden = false;

        postIr('ajax_product_cost_breakdown', {
            product_id: productId, material_id: pciCtx.material_id,
            old_price: pciCtx.old_price, new_price: pciCtx.new_price
        }).then(function (res) {
            if (!res || !res.success) { box.innerHTML = '<p class="dd2-empty">' + escapeHtml((res && res.message) || 'Không tải được dữ liệu.') + '</p>'; return; }
            document.getElementById('dd2-pci-title').textContent = res.product_name || 'Chi tiết giá vốn';
            var rows = (res.rows || []).map(function (m) {
                var changed = !!m.is_changed;
                return '<tr' + (changed ? ' class="dd2-pci-row-changed"' : '') + '>' +
                    '<td>' + escapeHtml(m.material_name) + (changed ? '<span class="dd2-pci-tag">biến động</span>' : '') + '</td>' +
                    '<td class="num">' + fmtNum(m.quantity_required) + '</td>' +
                    '<td class="num">' + fmtMoney(m.price_old) + '</td>' +
                    '<td class="num">' + fmtMoney(m.price_new) + '</td>' +
                    '<td class="num">' + fmtMoney(m.line_old) + '</td>' +
                    '<td class="num">' + fmtMoney(m.line_new) + '</td></tr>';
            }).join('');
            box.innerHTML = '<table class="dd2-pci-table"><thead><tr>' +
                '<th>Thành phần</th><th class="num">Định mức</th><th class="num">Đơn giá cũ</th>' +
                '<th class="num">Đơn giá mới</th><th class="num">Thành tiền cũ</th><th class="num">Thành tiền mới</th>' +
                '</tr></thead><tbody>' + rows + '</tbody>' +
                '<tfoot><tr class="dd2-pci-total"><td colspan="4">Giá vốn</td>' +
                '<td class="num">' + fmtMoney(res.total_old) + '</td>' +
                '<td class="num">' + fmtMoney(res.total_new) + ' ' + rateCell(res.change_rate) + '</td></tr></tfoot></table>';
        }).catch(function () { box.innerHTML = '<p class="dd2-empty">Lỗi kết nối.</p>'; });
    }

    function wirePriceChanges() {
        var body = document.getElementById('dd2-price-changes-body');
        if (body) {
            body.addEventListener('click', function (e) {
                var tr = e.target.closest && e.target.closest('.dd2-pc-row.is-clickable');
                if (!tr) return;
                openPriceImpactModal({
                    material_id: parseInt(tr.getAttribute('data-item-id'), 10) || 0,
                    name: tr.getAttribute('data-name') || '',
                    old_price: parseFloat(tr.getAttribute('data-old')) || 0,
                    new_price: parseFloat(tr.getAttribute('data-new')) || 0
                });
            });
        }
        if (pciModal) {
            pciModal.addEventListener('click', function (e) {
                if (e.target.closest('[data-dd2-pci-close]')) { closePriceImpactModal(); return; }
                var back = e.target.closest('#dd2-pci-back');
                if (back) { openPriceImpactModal(pciCtx); return; }
                var p = e.target.closest('.dd2-pci-product');
                if (p) openCostBreakdown(parseInt(p.getAttribute('data-product-id'), 10) || 0);
            });
        }
    }

    /* ---------------- Modal: danh sách ĐẦY ĐỦ dùng chung (sidebar 7 icon + nút "..."/"Sales Order") ---------------- */
    var fullListModal = document.getElementById('dd2-full-list-modal');
    var flSearchInput = document.getElementById('dd2-full-list-search');
    var flFilterSelect = document.getElementById('dd2-full-list-filter');
    var flTotalsEl = document.getElementById('dd2-full-list-totals');
    var flToolsEl = document.getElementById('dd2-full-list-tools');
    var flFromInput = document.getElementById('dd2-full-list-from');
    var flToInput = document.getElementById('dd2-full-list-to');
    var flRangeClear = document.getElementById('dd2-full-list-range-clear');
    var flPageSizeSel = document.getElementById('dd2-full-list-pagesize');
    var fullListAction = '', fullListParams = {}, fullListRenderer = null, fullListTotals = null;

    /** opts: { searchParam, searchPlaceholder, filterParam, dateRange, pageSize,
     *          totals: function(data){return htmlString} } — tất cả tùy chọn, bỏ trống thì ẩn. */
    function openFullListModal(title, action, renderer, extraParams, opts) {
        if (!fullListModal) return;
        opts = opts || {};
        var flBox = fullListModal.querySelector('.dd2-full-list-box');
        if (flBox) flBox.classList.remove('is-wide');
        document.getElementById('dd2-full-list-title').textContent = title;
        fullListAction = action;
        fullListRenderer = renderer;
        fullListParams = extraParams || {};
        fullListTotals = opts.totals || null;

        // Thanh lọc ngày + số dòng/trang: chỉ hiện cho danh sách nào khai dùng.
        wireFullListTools(opts);

        if (opts.searchParam) {
            flSearchInput.hidden = false;
            flSearchInput.placeholder = opts.searchPlaceholder || 'Tìm kiếm...';
            flSearchInput.value = '';
            flSearchInput.oninput = debounce(function () {
                fullListParams[opts.searchParam] = flSearchInput.value.trim();
                loadFullListPage(1);
            }, 300);
        } else if (flSearchInput) {
            flSearchInput.hidden = true;
            flSearchInput.oninput = null;
        }

        if (opts.filterParam && flFilterSelect) {
            flFilterSelect.hidden = false;
            flFilterSelect.value = '';
            flFilterSelect.onchange = function () {
                fullListParams[opts.filterParam] = flFilterSelect.value;
                loadFullListPage(1);
            };
        } else if (flFilterSelect) {
            flFilterSelect.hidden = true;
            flFilterSelect.onchange = null;
        }

        fullListModal.classList.add('is-open');
        fullListModal.setAttribute('aria-hidden', 'false');
        loadFullListPage(1);
    }
    function closeFullListModal() { if (fullListModal) { fullListModal.classList.remove('is-open'); fullListModal.setAttribute('aria-hidden', 'true'); } }

    /** Bật/tắt + nối dây thanh lọc "từ ngày → đến ngày" và "số dòng/trang".
     *  Mọi thay đổi đều nạp lại từ trang 1 (đổi bộ lọc mà giữ trang cũ dễ ra trang trắng). */
    function wireFullListTools(opts) {
        var useRange = !!opts.dateRange, usePage = !!opts.pageSize;
        if (flToolsEl) flToolsEl.hidden = !(useRange || usePage);
        var rangeBox = flToolsEl ? flToolsEl.querySelector('.dd2-fl-range') : null;
        var pageBox = flToolsEl ? flToolsEl.querySelector('.dd2-fl-pagesize') : null;
        if (rangeBox) rangeBox.hidden = !useRange;
        if (pageBox) pageBox.hidden = !usePage;

        if (useRange && flFromInput && flToInput) {
            flFromInput.value = '';
            flToInput.value = '';
            delete fullListParams.from;
            delete fullListParams.to;
            var onRange = function () {
                var f = flFromInput.value, t = flToInput.value;
                // Chặn khoảng ngược: chọn "đến ngày" trước "từ ngày" thì kéo đầu kia theo.
                if (f && t && f > t) { if (this === flFromInput) { flToInput.value = f; t = f; } else { flFromInput.value = t; f = t; } }
                fullListParams.from = f;
                fullListParams.to = t;
                if (flRangeClear) flRangeClear.hidden = !(f || t);
                loadFullListPage(1);
            };
            flFromInput.onchange = onRange;
            flToInput.onchange = onRange;
            if (flRangeClear) {
                flRangeClear.hidden = true;
                flRangeClear.onclick = function () {
                    flFromInput.value = ''; flToInput.value = '';
                    fullListParams.from = ''; fullListParams.to = '';
                    flRangeClear.hidden = true;
                    loadFullListPage(1);
                };
            }
        } else {
            if (flFromInput) flFromInput.onchange = null;
            if (flToInput) flToInput.onchange = null;
            if (flRangeClear) flRangeClear.onclick = null;
        }

        if (usePage && flPageSizeSel) {
            flPageSizeSel.value = '10';
            fullListParams.per_page = 10;
            flPageSizeSel.onchange = function () {
                fullListParams.per_page = parseInt(flPageSizeSel.value, 10) || 10;
                loadFullListPage(1);
            };
        } else {
            if (flPageSizeSel) flPageSizeSel.onchange = null;
            delete fullListParams.per_page;
        }
    }

    function loadFullListPage(page) {
        var params = Object.assign({ page: page }, fullListParams);
        document.getElementById('dd2-full-list-body').innerHTML = '<p class="dd2-empty">Đang tải...</p>';
        postForm(fullListAction, params).then(function (res) {
            if (!res || !res.success) return;
            var data = res.data;
            var body = document.getElementById('dd2-full-list-body');
            body.innerHTML = fullListRenderer(data.rows);
            body.scrollTop = 0;   // đổi trang/bộ lọc thì xem lại từ dòng đầu
            renderBatchPager(document.getElementById('dd2-full-list-pager'), data.page, data.total_pages, loadFullListPage);
            if (fullListTotals && flTotalsEl) {
                flTotalsEl.hidden = false;
                flTotalsEl.innerHTML = fullListTotals(data);
            } else if (flTotalsEl) {
                flTotalsEl.hidden = true;
            }
        });
    }

    /** Modal "Sản xuất" đầy đủ — dùng chung endpoint với điều hướng ngày trên card, luôn theo ngày đang xem. */
    function openProductionFullModal() {
        if (!fullListModal) return;
        var flBox = fullListModal.querySelector('.dd2-full-list-box');
        if (flBox) flBox.classList.add('is-wide');
        document.getElementById('dd2-full-list-title').textContent = 'Sản xuất';
        document.getElementById('dd2-full-list-pager').innerHTML = '';
        document.getElementById('dd2-full-list-body').innerHTML = '<p class="dd2-empty">Đang tải...</p>';
        if (flSearchInput) { flSearchInput.hidden = true; flSearchInput.oninput = null; }
        if (flFilterSelect) { flFilterSelect.hidden = true; flFilterSelect.onchange = null; }
        if (flTotalsEl) flTotalsEl.hidden = true;
        fullListModal.classList.add('is-open');
        fullListModal.setAttribute('aria-hidden', 'false');
        postForm('daily_dashboard_production_full', { date: prodCurrentDate }).then(function (res) {
            if (!res || !res.success) return;
            document.getElementById('dd2-full-list-title').textContent = res.label;
            document.getElementById('dd2-full-list-body').innerHTML = rowsProductionFull(res.rows);
            if (flTotalsEl) {
                var rows = res.rows || [];
                if (rows.length) {
                    var sumQty = 0, sumCost = 0, sumValue = 0;
                    rows.forEach(function (r) {
                        sumQty += Number(r.quantity) || 0;
                        sumCost += Number(r.cost) || 0;
                        sumValue += Number(r.value) || 0;
                    });
                    flTotalsEl.innerHTML = 'SL: <b>' + fmtNum(sumQty) + '</b> &nbsp;·&nbsp; GVSX: <b>' + fmtMoney(sumCost) + '</b> &nbsp;·&nbsp; Giá trị: <b>' + fmtMoney(sumValue) + '</b>';
                    flTotalsEl.hidden = false;
                } else {
                    flTotalsEl.hidden = true;
                }
            }
        });
    }

    function flImportsTotals(data) {
        var t = data.totals || {};
        return 'GTNK: <b>' + fmtMoney(t.inventory_value) + '</b> &nbsp;·&nbsp; CPMH: <b>' + fmtMoney(t.purchase_cost) + '</b>';
    }
    // Tổng tính trên TOÀN BỘ phiếu khớp bộ lọc hiện tại (mọi trang), không phải toàn thời gian.
    function flExportsTotals(data) {
        var t = data.totals || {};
        var scope = (data.from || data.to)
            ? (fmtDateVn(data.from) || 'đầu kỳ') + ' → ' + (fmtDateVn(data.to) || 'nay')
            : 'tất cả';
        return '<span class="dd2-totals-scope">' + escapeHtml(scope) + ' · ' + fmtNum(t.count || 0) + ' phiếu</span>' +
            'SL: <b>' + fmtNum(t.quantity) + '</b> &nbsp;·&nbsp; Khối lượng: <b>' + fmtNum(t.weight) + ' kg</b> &nbsp;·&nbsp; Doanh thu: <b>' + fmtMoney(t.value) + '</b>';
    }

    /** '2026-07-25' -> '25/07/2026'; rỗng/không hợp lệ -> ''. */
    function fmtDateVn(iso) {
        if (!iso || !/^\d{4}-\d{2}-\d{2}$/.test(iso)) return '';
        var p = iso.split('-');
        return p[2] + '/' + p[1] + '/' + p[0];
    }
    function flOutputTotals(data) {
        var t = data.totals || {};
        return 'Tổng SL: <b>' + fmtNum(t.quantity) + '</b> &nbsp;·&nbsp; Tổng giá vốn: <b>' + fmtMoney(t.cost) + '</b> &nbsp;·&nbsp; Tổng giá trị hàng hóa: <b>' + fmtMoney(t.value) + '</b>';
    }

    function wireSidebarIcons() {
        var map = {
            imports: ['Nhập kho', 'daily_dashboard_imports_full', rowsImports, { searchParam: 'keyword', searchPlaceholder: 'Tìm nhà cung cấp...', totals: flImportsTotals }],
            exports: ['Xuất kho', 'daily_dashboard_exports_full', rowsExports, { searchParam: 'keyword', searchPlaceholder: 'Tìm khách hàng...', dateRange: true, pageSize: true, totals: flExportsTotals }],
            output: ['Sản lượng', 'daily_dashboard_output_full', rowsOutputFull, { searchParam: 'keyword', searchPlaceholder: 'Tìm sản phẩm...', totals: flOutputTotals }],
            material_orders: ['Đặt hàng nguyên liệu', 'daily_dashboard_material_orders_full', rowsMaterialOrdersFull, {}],
            branch: ['Chi nhánh đặt hàng', 'daily_dashboard_branch_full', rowsBranchFull, {}],
            fund: ['Quỹ', 'daily_dashboard_fund_full', rowsFund, { filterParam: 'type' }]
        };
        document.querySelectorAll('.dd2-sb-icon[data-dd2-side]').forEach(function (btn) {
            var side = btn.getAttribute('data-dd2-side');
            if (side === 'production') { btn.addEventListener('click', openProductionFullModal); return; }
            var cfg = map[side];
            if (cfg) btn.addEventListener('click', function () { openFullListModal(cfg[0], cfg[1], cfg[2], {}, cfg[3]); });
        });
    }

    /* ---------------- Điều hướng ngày — card "Sản xuất..." ---------------- */
    var prodDayOffset = 0;
    var prodCurrentDate = (INITIAL.production_day && INITIAL.production_day.date) || new Date().toISOString().slice(0, 10);

    // Phân trang 5 SP/trang, ẩn mặc định chỉ hiện khi hover (giống Nhập kho/Quỹ) — vì
    // daily_dashboard_production_full đã trả về TOÀN BỘ SP trong ngày 1 lần, phân trang
    // ở đây cắt mảng phía client, không gọi lại API khi đổi trang.
    var PROD_PAGE_SIZE = 5;
    var prodRowsAll = (INITIAL.production_day && INITIAL.production_day.rows) || [];
    var prodPage = 1;

    function renderProductionPage() {
        var body = document.getElementById('dd2-products-body');
        if (!body) return;
        setCardCount('dd2-production-count', prodRowsAll.length, 'sản phẩm');
        var totalPages = Math.max(1, Math.ceil(prodRowsAll.length / PROD_PAGE_SIZE));
        if (prodPage > totalPages) prodPage = totalPages;
        var start = (prodPage - 1) * PROD_PAGE_SIZE;
        var pageRows = prodRowsAll.slice(start, start + PROD_PAGE_SIZE);
        var rowCount = Math.max(PROD_PAGE_SIZE, pageRows.length);
        var sumQty = 0, sumCost = 0;
        prodRowsAll.forEach(function (p) { sumQty += Number(p.quantity) || 0; sumCost += Number(p.cost) || 0; });
        var html = '';
        for (var i = 0; i < rowCount; i++) {
            var p = pageRows[i];
            if (p) {
                var imgCell = p.image_url
                    ? '<img class="dd2-prod-img" src="public/images/' + escapeHtml(p.image_url) + '" alt="">'
                    : '<span class="dd2-prod-img dd2-prod-img-empty"><i class="fa-solid fa-box"></i></span>';
                html += '<tr class="dd2-prod-row" data-product-id="' + p.product_id + '">' +
                    '<td class="dd2-prod-name">' + imgCell + '<span class="dd2-prod-name-text">' + escapeHtml(p.name) + '</span></td>' +
                    '<td>' + fmtNum(p.quantity) + '</td>' +
                    '<td class="dd2-prod-cost" data-product-id="' + p.product_id + '">' + fmtMoney(p.cost) +
                    ' <span class="dd2-warn-dot ' + (p.warn_class || '') + '"></span></td></tr>';
            } else {
                html += '<tr class="dd2-prod-row is-empty"><td colspan="3"></td></tr>';
            }
        }
        body.innerHTML = html;
        var table = body.closest('table');
        var tfoot = table ? table.querySelector('tfoot') : null;
        if (tfoot) tfoot.innerHTML = '<tr><td>Tổng:</td><td>' + fmtNum(sumQty) + '</td><td>' + fmtMoney(sumCost) + '</td></tr>';
        renderBatchPager(document.querySelector('[data-dd2-pager="production"]'), prodPage, totalPages, function (p) {
            prodPage = p;
            renderProductionPage();
        });
    }

    function renderProductionTable(rows) {
        prodRowsAll = rows || [];
        prodPage = 1;
        renderProductionPage();
    }

    function loadProductionDay(offset) {
        var d = new Date();
        d.setDate(d.getDate() - offset);
        var iso = d.toISOString().slice(0, 10);
        postForm('daily_dashboard_production_full', { date: iso }).then(function (res) {
            if (!res || !res.success) return;
            prodDayOffset = offset;
            prodCurrentDate = iso;
            document.getElementById('dd2-production-title-text').textContent = res.label;
            renderProductionTable(res.rows);
            var nextBtn = document.getElementById('dd2-day-next');
            if (nextBtn) nextBtn.disabled = (prodDayOffset === 0);
        });
    }

    function wireDayNav() {
        var prev = document.getElementById('dd2-day-prev');
        var next = document.getElementById('dd2-day-next');
        if (prev) prev.addEventListener('click', function () { loadProductionDay(prodDayOffset + 1); });
        if (next) next.addEventListener('click', function () { if (prodDayOffset > 0) loadProductionDay(prodDayOffset - 1); });
    }

    /* ---------------- Chart: Sản lượng (bar sọc + cột max đậm + dot tháng hiện tại) ---------------- */
    var charts = {};

    function stripePattern(ctx, base, stripe) {
        var c = document.createElement('canvas');
        c.width = 8; c.height = 8;
        var pctx = c.getContext('2d');
        pctx.fillStyle = base;
        pctx.fillRect(0, 0, 8, 8);
        pctx.strokeStyle = stripe;
        pctx.lineWidth = 2.5;
        pctx.beginPath();
        pctx.moveTo(0, 8); pctx.lineTo(8, 0);
        pctx.moveTo(-2, 2); pctx.lineTo(2, -2);
        pctx.moveTo(6, 10); pctx.lineTo(10, 6);
        pctx.stroke();
        return ctx.createPattern(c, 'repeat');
    }

    function renderOutputChart(months, currentValue) {
        var el = document.getElementById('dd2-chart-output');
        if (!el || typeof Chart === 'undefined') return;
        var ctx = el.getContext('2d');
        var values = months.map(function (m) { return Number(m.value) || 0; });
        var labels = months.map(function (m) { return m.label; });
        var maxIdx = values.indexOf(Math.max.apply(null, values));
        var normalFill = stripePattern(ctx, '#cdebd7', '#8fcda1');
        var maxFill = stripePattern(ctx, '#0a741c', '#4c7b54');
        var barColors = values.map(function (v, i) { return i === maxIdx ? maxFill : normalFill; });

        // Vẽ tay toàn bộ marker (không qua scale/dataset) để kiểm soát chính xác vị trí:
        //  - bullet xanh + giá trị trên đỉnh cột max.
        //  - bullet đỏ tháng hiện tại NEO THẲNG vào chart.chartArea.right (mép phải thật của
        //    vùng vẽ), chỉ lùi vào đúng bán kính+viền để không bị cắt hình — không phụ thuộc
        //    scale/padding nào khác nên luôn sát mép phải bất kể số cột hay bề rộng chart.
        var markerPlugin = {
            id: 'dd2Markers',
            afterDatasetsDraw: function (chart) {
                var ctx2 = chart.ctx;
                var meta = chart.getDatasetMeta(0);

                if (meta && meta.data[maxIdx]) {
                    var bar = meta.data[maxIdx];
                    // Giá trị cột max: badge nền xanh lá bo tròn + đuôi tam giác chỉ xuống (giống
                    // "bong bóng" trỏ vào bullet bên dưới) — thay cho chữ trơn trước đây.
                    var badgeText = fmtNum(values[maxIdx]);
                    ctx2.save();
                    ctx2.font = 'bold 12px Arial';
                    var textW = ctx2.measureText(badgeText).width;
                    var padX = 8, padY = 5, tailH = 6;
                    var boxW = textW + padX * 2, boxH = 12 + padY * 2;
                    var boxCenterY = bar.y - 16 - tailH - boxH / 2;
                    var boxX = bar.x - boxW / 2, boxY = boxCenterY - boxH / 2;
                    roundRectPath(ctx2, boxX, boxY, boxW, boxH, 6);
                    ctx2.fillStyle = '#0a741c';
                    ctx2.fill();
                    ctx2.beginPath();
                    ctx2.moveTo(bar.x - tailH, boxY + boxH);
                    ctx2.lineTo(bar.x + tailH, boxY + boxH);
                    ctx2.lineTo(bar.x, boxY + boxH + tailH);
                    ctx2.closePath();
                    ctx2.fill();
                    ctx2.fillStyle = '#fff';
                    ctx2.textAlign = 'center';
                    ctx2.textBaseline = 'middle';
                    ctx2.fillText(badgeText, bar.x, boxCenterY + 1);
                    ctx2.textBaseline = 'alphabetic';
                    ctx2.beginPath();
                    ctx2.arc(bar.x, bar.y, 6, 0, Math.PI * 2);
                    ctx2.fillStyle = '#16a34a';
                    ctx2.fill();
                    ctx2.lineWidth = 2;
                    ctx2.strokeStyle = '#fff';
                    ctx2.stroke();
                    ctx2.restore();
                }

                var yScale = chart.scales.y;
                if (yScale) {
                    var inset = 8; // bán kính 6 + viền 2 — vừa đủ để không bị cắt bởi mép canvas.
                    var dotX = chart.chartArea.right - inset;
                    var dotY = yScale.getPixelForValue(currentValue);
                    ctx2.save();
                    ctx2.fillStyle = '#b91c1c';
                    ctx2.font = 'bold 11px Arial';
                    ctx2.textAlign = 'right';
                    ctx2.fillText(fmtNum(currentValue), dotX - 10, dotY + 4);
                    ctx2.beginPath();
                    ctx2.arc(dotX, dotY, 6, 0, Math.PI * 2);
                    ctx2.fillStyle = '#dc2626';
                    ctx2.fill();
                    ctx2.lineWidth = 2;
                    ctx2.strokeStyle = '#fff';
                    ctx2.stroke();
                    ctx2.restore();
                }

                // Nhãn tháng dưới trục hoành tự vẽ tay (ticks mặc định đã tắt) — cho phép ghép thêm
                // số năm viết gọn (vd DEC25) cỡ chữ bằng 1/2 tên tháng, sát ngay sau, cho tháng thuộc
                // năm trước; các tháng cùng năm hiện tại chỉ vẽ tên tháng như cũ.
                if (meta) {
                    var labelY = chart.chartArea.bottom + 10;
                    meta.data.forEach(function (bar, i) {
                        var mObj = months[i] || {};
                        var mainText = String(mObj.label || '');
                        var yearText = String(mObj.year_short || '');
                        ctx2.save();
                        ctx2.fillStyle = '#6b7280';
                        ctx2.textBaseline = 'top';
                        if (yearText) {
                            ctx2.font = '11px Arial';
                            var mainW = ctx2.measureText(mainText).width;
                            ctx2.font = '6px Arial';
                            var yearW = ctx2.measureText(yearText).width;
                            var startX = bar.x - (mainW + yearW) / 2;
                            ctx2.textAlign = 'left';
                            ctx2.font = '11px Arial';
                            ctx2.fillText(mainText, startX, labelY);
                            ctx2.font = '6px Arial';
                            ctx2.fillText(yearText, startX + mainW, labelY + 3);
                        } else {
                            ctx2.textAlign = 'center';
                            ctx2.font = '11px Arial';
                            ctx2.fillText(mainText, bar.x, labelY);
                        }
                        ctx2.restore();
                    });
                }
            }
        };

        if (charts.output) { charts.output.destroy(); }
        charts.output = new Chart(ctx, {
            type: 'bar',
            data: {
                labels: labels,
                datasets: [
                    {
                        type: 'bar',
                        data: values,
                        backgroundColor: barColors,
                        borderRadius: 20,
                        borderSkipped: false,
                        barThickness: 39,
                        order: 2
                    }
                ]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                resizeDelay: 100,
                // Chừa chỗ phía trên cho badge giá trị cột max (vẽ tay, cao ~40px kể cả đuôi tam
                // giác) — không có khoảng này, cột cao gần sát đỉnh canvas sẽ làm badge bị cắt/ẩn
                // mất phần trên do vẽ ra ngoài vùng canvas.
                layout: { padding: { top: 44, bottom: 18 } },
                plugins: {
                    legend: { display: false },
                    tooltip: {
                        callbacks: {
                            label: function (ctx2) { return fmtNum(ctx2.parsed.y); }
                        }
                    }
                },
                scales: {
                    y: {
                        beginAtZero: true,
                        border: { display: false },
                        ticks: {
                            stepSize: 2000,
                            callback: function (v) { return v === 0 ? '0' : (v / 1000) + 'k'; }
                        },
                        grid: {
                            color: '#e5e7eb',
                            borderDash: [4, 4],
                            drawTicks: false
                        }
                    },
                    x: { grid: { display: false }, border: { display: false }, ticks: { display: false } }
                }
            },
            plugins: [markerPlugin]
        });
    }

    /* ---------------- Chart: Xuất kho (smoothed line 3px + gradient area + bullet max/hiện tại + "Sales Order") ---------------- */
    function renderExportChart(months, currentValue, mode, axis) {
        var el = document.getElementById('dd2-chart-export');
        if (!el || typeof Chart === 'undefined') return;
        var ctx = el.getContext('2d');
        var fmtVal = mode === 'quantity' ? function (v) { return fmtNum(v) + ' SP'; } : fmtMoney;
        axis = axis || { min: 0, step: 0 };
        var axisStep = Number(axis.step) || 0;
        var values = (months || []).map(function (m) { return Number(m.value) || 0; });
        var labels = (months || []).map(function (m) { return m.label; });
        var maxIdx = values.indexOf(Math.max.apply(null, values));

        // "jan, 2026" cho dòng tháng dưới chỉ số max (ym dạng "2026-01" -> lấy năm; label đã có sẵn "JAN").
        var maxMonthText = '';
        if (months && months[maxIdx]) {
            var maxYm = String(months[maxIdx].ym || '');
            var maxYear = maxYm.split('-')[0] || '';
            maxMonthText = String(months[maxIdx].label || '').toLowerCase() + (maxYear ? ', ' + maxYear : '');
        }

        var gradient = ctx.createLinearGradient(0, 0, 0, el.parentElement.clientHeight || 200);
        gradient.addColorStop(0, 'rgba(10,116,28,0.55)');
        // Thêm điểm dừng ở 0.8 (2026-07-17: vẫn còn hơi mờ mờ ở 2 góc dưới dù đáy đã alpha=0) — ép
        // đường cong fade "lõm" xuống nhanh hơn ở đoạn gần đáy thay vì tuyến tính đều, để phần 1/5
        // dưới cùng gần như trong suốt hẳn trước khi chạm đáy, không chỉ đúng 1 điểm mép đáy mới về 0.
        gradient.addColorStop(0.8, 'rgba(10,116,28,0.03)');
        // Đáy gradient về hẳn alpha=0 (thay vì 0.06) — tại đúng mép dưới/2 góc bo tròn của
        // .dd2-chart-box, nếu còn chút màu (dù rất nhạt) sẽ lộ ra như 1 vệt góc vuông sắc nét đè lên
        // phần bo tròn (mắt tinh vẫn nhận ra), vì fill do Chart.js vẽ là 1 khối vuông thực (không tự bo
        // góc theo CSS). Về 0 tuyệt đối thì không còn màu gì ở đó để lộ góc, nhìn liền mạch với góc bo.
        gradient.addColorStop(1, 'rgba(10,116,28,0)');

        var markerPlugin = {
            id: 'dd2ExportMarkers',
            afterDatasetsDraw: function (chart) {
                var ctx2 = chart.ctx;
                var meta = chart.getDatasetMeta(0);
                if (meta && meta.data[maxIdx]) {
                    var pt = meta.data[maxIdx];
                    // Max nằm ở điểm ĐẦU/CUỐI -> canh lệch hẳn sang phải/trái so với bullet để
                    // không bị tràn ra ngoài khối (không còn đủ chỗ để canh giữa như bình thường).
                    var align = 'center', anchorX = pt.x;
                    if (maxIdx === 0) { align = 'left'; anchorX = pt.x + 10; }
                    else if (maxIdx === values.length - 1) { align = 'right'; anchorX = pt.x - 10; }
                    ctx2.save();
                    ctx2.textAlign = align;
                    ctx2.fillStyle = '#0a741c';
                    ctx2.font = 'bold 12px Arial';
                    ctx2.fillText(fmtNum(values[maxIdx]), anchorX, pt.y - 24);
                    if (maxMonthText) {
                        ctx2.fillStyle = '#6b7280';
                        ctx2.font = '10px Arial';
                        ctx2.fillText(maxMonthText, anchorX, pt.y - 11);
                    }
                    ctx2.beginPath();
                    ctx2.arc(pt.x, pt.y, 5, 0, Math.PI * 2);
                    ctx2.fillStyle = '#16a34a';
                    ctx2.fill();
                    ctx2.lineWidth = 2;
                    ctx2.strokeStyle = '#fff';
                    ctx2.stroke();
                    ctx2.restore();
                }
                var yScale = chart.scales.y;
                if (yScale) {
                    var inset = 8;
                    var dotX = chart.chartArea.right - inset;
                    var dotY = yScale.getPixelForValue(currentValue);
                    ctx2.save();
                    ctx2.fillStyle = '#b91c1c';
                    ctx2.font = 'bold 11px Arial';
                    ctx2.textAlign = 'right';
                    ctx2.fillText(fmtNum(currentValue), dotX - 10, dotY + 4);
                    ctx2.beginPath();
                    ctx2.arc(dotX, dotY, 6, 0, Math.PI * 2);
                    ctx2.fillStyle = '#dc2626';
                    ctx2.fill();
                    ctx2.lineWidth = 2;
                    ctx2.strokeStyle = '#fff';
                    ctx2.stroke();
                    ctx2.restore();
                }
            }
        };

        if (charts.exportChart) charts.exportChart.destroy();
        charts.exportChart = new Chart(ctx, {
            type: 'line',
            data: {
                labels: labels,
                datasets: [{
                    data: values,
                    borderColor: '#0a741c',
                    borderWidth: 3,
                    tension: 0.4,
                    fill: true,
                    backgroundColor: gradient,
                    pointRadius: function (c) { return c.dataIndex === maxIdx ? 5 : 0; },
                    // Hầu hết điểm có pointRadius=0 (chỉ hiện qua marker vẽ tay) — nới rộng vùng bắt
                    // hover (hitRadius) để "khi hover vào hiển thị giá trị" hoạt động dọc theo cả line,
                    // không chỉ đúng 2 điểm có bullet vẽ sẵn (max/hiện tại).
                    pointHitRadius: 20,
                    pointBackgroundColor: '#dc2626',
                    pointBorderColor: '#fff',
                    pointHoverRadius: 6
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                resizeDelay: 100,
                animation: false,
                // mode:'index' + intersect:false -> hover theo trục X bất kể có đúng lên điểm hay không.
                interaction: { mode: 'index', intersect: false },
                // Bỏ padding trái/phải: ưu tiên chart full-width sát mép card (không bị bóp lại
                // để chừa lề tooltip nữa) — cột đầu/cuối đã tự canh lệch trái/phải riêng ở
                // markerPlugin bên trên, tooltip gốc Chart.js tự lật hướng theo khoảng trống thực tế.
                layout: { padding: { top: 40 } },
                plugins: {
                    legend: { display: false },
                    tooltip: {
                        callbacks: {
                            title: function () { return ''; },
                            label: function (c) { return [c.label, fmtVal(c.parsed.y)]; }
                        }
                    }
                },
                scales: {
                    x: { display: false },
                    // Trục ẩn mặc định; chỉ hiện khi người dùng tự thiết lập mốc bắt đầu + bước nhảy
                    // (modal "Thiết lập giá trị tạm") — bước nhảy = 0 nghĩa là chưa cấu hình -> ẩn.
                    y: axisStep <= 0 ? { display: false, beginAtZero: true } : {
                        display: true,
                        min: Number(axis.min) || 0,
                        border: { display: false },
                        ticks: {
                            stepSize: axisStep,
                            color: '#9ca3af',
                            font: { size: 10 },
                            callback: function (v) { return fmtVal(v); }
                        },
                        grid: { color: '#e5e7eb', borderDash: [4, 4], drawTicks: false }
                    }
                }
            },
            plugins: [markerPlugin]
        });
    }

    /* ---------------- Modal: cài đặt hiệu quả ---------------- */
    var settingsModal = document.getElementById('dd2-settings-modal');
    function openSettingsModal() { if (settingsModal) { settingsModal.classList.add('is-open'); settingsModal.setAttribute('aria-hidden', 'false'); } }
    function closeSettingsModal() { if (settingsModal) { settingsModal.classList.remove('is-open'); settingsModal.setAttribute('aria-hidden', 'true'); } }

    function wireKeyProductPicker() {
        var addBtn = document.getElementById('dd2-add-key-product');
        var addRow = document.getElementById('dd2-kp-add-row');
        var input = document.getElementById('dd2-kp-search');
        var suggest = document.getElementById('dd2-kp-suggest');
        if (!addBtn || !addRow || !input || !suggest) return;
        var activeIdx = -1;

        addBtn.addEventListener('click', function () {
            addRow.hidden = !addRow.hidden;
            if (!addRow.hidden) input.focus();
        });

        function items() { return suggest.querySelectorAll('.app-remind-suggest-item'); }
        function closeSuggest() { suggest.innerHTML = ''; suggest.classList.remove('is-open'); activeIdx = -1; }
        function highlight(idx) {
            var els = items();
            els.forEach(function (el) { el.classList.remove('is-active'); });
            if (idx >= 0 && els[idx]) { els[idx].classList.add('is-active'); els[idx].scrollIntoView({ block: 'nearest' }); }
        }
        function addKeyProductRow(id, name) {
            var list = document.getElementById('dd2-key-products-list');
            if (list.querySelector('[data-product-id="' + id + '"]')) return;
            var row = document.createElement('div');
            row.className = 'dd2-kp-row';
            row.setAttribute('data-product-id', id);
            row.innerHTML = '<span class="dd2-kp-name">' + escapeHtml(name) + '</span>' +
                '<input type="number" class="dd2-kp-min" min="0" step="1" value="0">' +
                '<button type="button" class="dd2-kp-remove" title="Xóa"><i class="fa-solid fa-trash"></i></button>';
            list.appendChild(row);
        }
        function pick(el) {
            if (!el) return;
            addKeyProductRow(el.getAttribute('data-id'), el.getAttribute('data-name'));
            input.value = '';
            closeSuggest();
            addRow.hidden = true;
        }

        input.addEventListener('input', debounce(function () {
            var kw = input.value.trim();
            if (!kw) { closeSuggest(); return; }
            postForm('fev_search_products', { keyword: kw }).then(function (res) {
                var list = (res && res.data) || [];
                suggest.innerHTML = list.length ? list.map(function (it) {
                    return '<div class="app-remind-suggest-item" data-id="' + it.id + '" data-name="' + escapeHtml(it.label) + '">' + escapeHtml(it.label) + '</div>';
                }).join('') : '<div class="app-remind-suggest-empty">Không có kết quả</div>';
                suggest.classList.add('is-open');
                activeIdx = -1;
            });
        }, 250));
        input.addEventListener('keydown', function (e) {
            if (!suggest.classList.contains('is-open')) return;
            var els = items();
            if (!els.length) return;
            if (e.key === 'ArrowDown') { e.preventDefault(); activeIdx = (activeIdx + 1) % els.length; highlight(activeIdx); }
            else if (e.key === 'ArrowUp') { e.preventDefault(); activeIdx = (activeIdx - 1 + els.length) % els.length; highlight(activeIdx); }
            else if (e.key === 'Enter') { if (activeIdx >= 0) { e.preventDefault(); pick(els[activeIdx]); } }
            else if (e.key === 'Tab') { if (activeIdx >= 0) pick(els[activeIdx]); }
            else if (e.key === 'Escape') { closeSuggest(); }
        });
        suggest.addEventListener('click', function (e) {
            var item = e.target.closest('.app-remind-suggest-item');
            if (!item) return;
            e.stopPropagation();
            pick(item);
        });
        document.addEventListener('click', function (e) {
            if (!addRow.contains(e.target) && e.target !== addBtn) closeSuggest();
        });

        document.getElementById('dd2-key-products-list').addEventListener('click', function (e) {
            var rm = e.target.closest('.dd2-kp-remove');
            if (!rm) return;
            rm.closest('.dd2-kp-row').remove();
        });
    }

    function saveSettings() {
        var maxOutput = document.getElementById('dd2-max-output-input').value;
        var items = Array.prototype.map.call(document.querySelectorAll('#dd2-key-products-list .dd2-kp-row'), function (row) {
            return {
                product_id: row.getAttribute('data-product-id'),
                min_quantity: row.querySelector('.dd2-kp-min').value
            };
        });
        postForm('daily_dashboard_save_settings', { max_output: maxOutput, items: JSON.stringify(items) }).then(function (res) {
            if (!res || !res.success) return;
            applyOutputBlock(res.data);
            closeSettingsModal();
        });
    }

    /** Cập nhật lại toàn bộ khối "Sản lượng" (số hiện tại, hiệu quả, điều tiết, chart) sau khi lưu cài đặt/giá trị tạm. */
    function applyOutputBlock(out) {
        document.getElementById('dd2-output-current').textContent = fmtNum(out.current);
        document.getElementById('dd2-efficiency-value').textContent = Number(out.efficiency.value).toFixed(1);
        var regEl = document.getElementById('dd2-regulation-value');
        regEl.className = 'dd2-state-' + out.regulation.state;
        regEl.textContent = out.regulation.label + ' (' + Number(out.regulation.a).toFixed(1) + ')';
        renderOutputChart(out.months, out.current);
        refreshRegulationAfterSettings(out.regulation);
    }

    /** Cài đặt SP chủ lực / giá trị tạm vừa đổi -> số liệu điều tiết đã cache theo kỳ hết hiệu lực.
     *  Phải gắn theo ĐÚNG reg.period, không gán cứng 'month': kỳ đang chọn được lưu ở app_settings
     *  nên rp_dd_output_block() có thể trả về số liệu của kỳ 30/60/90. */
    function refreshRegulationAfterSettings(reg) {
        regPeriodCache = {};
        if (reg && reg.period) regPeriodCache[reg.period] = reg;
        var group = document.getElementById('dd2-reg-period-group');
        var active = group && group.querySelector('.dd2-toggle-btn.active');
        var period = active ? active.getAttribute('data-dd2-reg-period') : 'month';
        if (period === 'month') renderRegulation(reg);
        else selectRegulationPeriod(period);
    }

    /* ---------------- Modal: thiết lập giá trị tạm — Sản lượng ---------------- */
    var tempValuesModal = document.getElementById('dd2-temp-values-modal');
    function openTempValuesModal() { if (tempValuesModal) { tempValuesModal.classList.add('is-open'); tempValuesModal.setAttribute('aria-hidden', 'false'); } }
    function closeTempValuesModal() { if (tempValuesModal) { tempValuesModal.classList.remove('is-open'); tempValuesModal.setAttribute('aria-hidden', 'true'); } }

    function saveTempValues() {
        var items = Array.prototype.map.call(document.querySelectorAll('#dd2-temp-values-list .dd2-tv-row'), function (row) {
            return { ym: row.getAttribute('data-ym'), value: row.querySelector('.dd2-tv-input').value };
        });
        postForm('daily_dashboard_save_month_overrides', { items: JSON.stringify(items) }).then(function (res) {
            if (!res || !res.success) return;
            applyOutputBlock(res.data);
            closeTempValuesModal();
        });
    }

    /* ---------------- Modal: thiết lập giá trị tạm — Xuất kho (2 tab Doanh thu/Số lượng) ---------------- */
    var exportTempValuesModal = document.getElementById('dd2-export-temp-values-modal');
    function openExportTempValuesModal() { if (exportTempValuesModal) { exportTempValuesModal.classList.add('is-open'); exportTempValuesModal.setAttribute('aria-hidden', 'false'); } }
    function closeExportTempValuesModal() { if (exportTempValuesModal) { exportTempValuesModal.classList.remove('is-open'); exportTempValuesModal.setAttribute('aria-hidden', 'true'); } }

    /** Chọn tab trong modal cũng đổi luôn chế độ hiển thị của chart chính (preview ngay, không cần Lưu). */
    function wireExportTvTabs() {
        var tabs = document.querySelectorAll('[data-dd2-export-tv-tab]');
        var revList = document.getElementById('dd2-export-temp-values-list');
        var qtyList = document.getElementById('dd2-export-qty-temp-values-list');
        if (!tabs.length || !revList || !qtyList) return;
        tabs.forEach(function (tab) {
            tab.addEventListener('click', function () {
                tabs.forEach(function (t) { t.classList.remove('active'); });
                tab.classList.add('active');
                var mode = tab.getAttribute('data-dd2-export-tv-tab');
                revList.hidden = mode !== 'value';
                qtyList.hidden = mode !== 'quantity';
                exportChartMode = mode;
                renderCurrentExportChart();
            });
        });
    }

    function saveExportTempValues() {
        var items = Array.prototype.map.call(document.querySelectorAll('#dd2-export-temp-values-list .dd2-tv-row'), function (row) {
            return { ym: row.getAttribute('data-ym'), value: row.querySelector('.dd2-tv-input').value };
        });
        var itemsQty = Array.prototype.map.call(document.querySelectorAll('#dd2-export-qty-temp-values-list .dd2-tv-row'), function (row) {
            return { ym: row.getAttribute('data-ym'), value: row.querySelector('.dd2-tv-input').value };
        });
        var axisMinInp = document.getElementById('dd2-export-axis-min');
        var axisStepInp = document.getElementById('dd2-export-axis-step');
        postForm('daily_dashboard_save_export_month_overrides', {
            items: JSON.stringify(items), items_qty: JSON.stringify(itemsQty), customer_id: currentCustomerId,
            axis_min: axisMinInp ? axisMinInp.value : 0, axis_step: axisStepInp ? axisStepInp.value : 0
        }).then(function (res) {
            if (!res || !res.success) return;
            applyExportBlock(res);
            closeExportTempValuesModal();
        });
    }

    /* ---------------- Modal: chi tiết sản phẩm ---------------- */
    var productModal = document.getElementById('dd2-product-modal');
    function openProductModal() { if (productModal) { productModal.classList.add('is-open'); productModal.setAttribute('aria-hidden', 'false'); } }
    function closeProductModal() { if (productModal) { productModal.classList.remove('is-open'); productModal.setAttribute('aria-hidden', 'true'); } }

    function kv(label, value) {
        return '<div class="dd2-kv"><span class="k">' + escapeHtml(label) + '</span><span class="v">' + value + '</span></div>';
    }

    function renderProductModal(data) {
        document.getElementById('dd2-product-modal-title').textContent = data.product_name || 'Sản phẩm';
        var marginText = (data.margin === null || data.margin === undefined) ? '—' : (Math.round(data.margin * 10000) / 100) + '%';
        var body = '<div class="dd2-kv-grid">' +
            kv('Số lượng', fmtNum(data.quantity)) +
            kv('Giá vốn', fmtMoney(data.cost)) +
            kv('Giá trị', fmtMoney(data.value)) +
            kv('Biên độ', marginText) +
            '<div class="dd2-kv dd2-kv-convertible"><span class="k">Tồn kho hiện tại</span><span class="v">' +
            '<span id="dd2-stock-value">' + escapeHtml(data.stock_text || '—') + '</span>' +
            '<i class="fa-solid fa-repeat dd2-qd-icon" id="dd2-stock-qd" title="Quy đổi"></i></span></div>' +
            '</div>';
        body += '<p class="dd2-sub-title">Xuất kho</p>';
        body += '<div class="dd2-toggle-group">' +
            '<button type="button" class="dd2-toggle-btn active" data-issue="issue_1m">1 Tháng</button>' +
            '<button type="button" class="dd2-toggle-btn" data-issue="issue_3m">3 Tháng</button>' +
            '<button type="button" class="dd2-toggle-btn" data-issue="issue_6m">6 Tháng</button>' +
            '</div>';
        body += '<div class="dd2-issue-value-wrap"><div class="dd2-issue-value" id="dd2-issue-value">' + escapeHtml(data.issue_1m_pack || '—') + '</div>' +
            '<i class="fa-solid fa-repeat dd2-qd-icon" id="dd2-issue-qd" title="Quy đổi"></i></div>';
        var bodyEl = document.getElementById('dd2-product-modal-body');
        bodyEl.innerHTML = body;

        var stockMode = 'pack';
        var stockQd = document.getElementById('dd2-stock-qd');
        if (stockQd) stockQd.addEventListener('click', function () {
            stockMode = stockMode === 'pack' ? 'raw' : 'pack';
            document.getElementById('dd2-stock-value').textContent = (stockMode === 'pack' ? data.stock_text : data.stock_text_raw) || '—';
        });

        var issueKey = 'issue_1m';
        var issueMode = 'pack';
        function renderIssueValue() {
            var field = issueKey + (issueMode === 'pack' ? '_pack' : '');
            document.getElementById('dd2-issue-value').textContent = data[field] || '—';
        }
        bodyEl.querySelectorAll('.dd2-toggle-btn').forEach(function (btn) {
            btn.addEventListener('click', function () {
                bodyEl.querySelectorAll('.dd2-toggle-btn').forEach(function (b) { b.classList.remove('active'); });
                btn.classList.add('active');
                issueKey = btn.getAttribute('data-issue');
                renderIssueValue();
            });
        });
        var issueQd = document.getElementById('dd2-issue-qd');
        if (issueQd) issueQd.addEventListener('click', function () {
            issueMode = issueMode === 'pack' ? 'raw' : 'pack';
            renderIssueValue();
        });
    }

    /* ---------------- Modal: chi tiết NVL của giá vốn sản xuất ---------------- */
    var costModal = document.getElementById('dd2-cost-modal');
    function openCostModal() { if (costModal) { costModal.classList.add('is-open'); costModal.setAttribute('aria-hidden', 'false'); } }
    function closeCostModal() { if (costModal) { costModal.classList.remove('is-open'); costModal.setAttribute('aria-hidden', 'true'); } }

    function renderCostModal(data) {
        document.getElementById('dd2-cost-modal-title').textContent = data.product_name || 'Giá vốn sản xuất';
        var mats = data.materials || [];
        var html;
        if (!mats.length) {
            html = '<p class="dd2-empty">Chưa có dữ liệu NVL xuất dùng hôm nay.</p>';
        } else {
            html = '<table class="dd2-cost-table"><thead><tr><th>NVL</th><th>SL</th><th>Đơn giá</th><th>Thành tiền</th></tr></thead><tbody>' +
                mats.map(function (m) {
                    return '<tr><td>' + escapeHtml(m.name) + '</td><td>' + fmtNum(m.quantity) + ' ' + escapeHtml(m.unit) + '</td>' +
                        '<td>' + fmtMoney(m.unit_price) + '</td><td>' + fmtMoney(m.amount) + '</td></tr>';
                }).join('') +
                '</tbody><tfoot><tr><td colspan="3">Tổng</td><td>' + fmtMoney(data.total) + '</td></tr></tfoot></table>';
        }
        document.getElementById('dd2-cost-modal-body').innerHTML = html;
    }

    /* ---------------- Modal: nhóm theo NCC (7 hóa đơn / CPMH) ---------------- */
    var importGroupModal = document.getElementById('dd2-import-group-modal');
    function openImportGroupModal(title, field) {
        document.getElementById('dd2-import-group-title').textContent = title;
        document.getElementById('dd2-import-group-body').innerHTML = '<p class="dd2-empty">Đang tải...</p>';
        importGroupModal.classList.add('is-open'); importGroupModal.setAttribute('aria-hidden', 'false');
        postForm('daily_dashboard_imports_group_modal', { field: field }).then(function (res) {
            var list = (res && res.data) || [];
            var body = document.getElementById('dd2-import-group-body');
            if (!list.length) { body.innerHTML = '<p class="dd2-empty">Chưa có dữ liệu.</p>'; return; }
            body.innerHTML = list.map(function (r) {
                var val = field === 'cost' ? r.cost : r.value;
                return '<div class="dd2-group-row"><span>' + escapeHtml(r.supplier_label) + ' (' + r.count + ')</span><span>' + fmtMoney(val) + '</span></div>';
            }).join('');
        });
    }
    function closeImportGroupModal() { if (importGroupModal) { importGroupModal.classList.remove('is-open'); importGroupModal.setAttribute('aria-hidden', 'true'); } }

    /* ---------------- Modal: ảnh hóa đơn ---------------- */
    var attModal = document.getElementById('dd2-att-modal');
    var attGrid = document.getElementById('dd2-att-grid');
    function isImgUrl(u) { return /\.(jpg|jpeg|png|gif|webp)$/i.test(String(u || '')); }
    function openAttModal(images) {
        if (!attModal) return;
        if (!images.length) {
            attGrid.innerHTML = '<div class="dd2-att-empty">Chưa có hình ảnh lưu cho ngày này.</div>';
        } else {
            attGrid.innerHTML = images.map(function (a) {
                var inner = isImgUrl(a.file_url) ? '<img src="' + escapeHtml(a.file_url) + '" alt="hóa đơn">' : '<div class="dd2-att-pdf"><i class="fa-solid fa-file-pdf"></i></div>';
                return '<div class="dd2-att-item" data-view="' + escapeHtml(a.file_url) + '">' + inner + '</div>';
            }).join('');
        }
        attModal.classList.add('is-open'); attModal.setAttribute('aria-hidden', 'false');
    }
    function closeAttModal() { if (attModal) { attModal.classList.remove('is-open'); attModal.setAttribute('aria-hidden', 'true'); } }

    /* ---------------- Picker: chọn khách hàng (khối Xuất kho) ---------------- */
    function wireCustomerPicker() {
        var btn = document.getElementById('dd2-export-customer-btn');
        var pop = document.getElementById('dd2-customer-picker');
        var input = document.getElementById('dd2-customer-search');
        var suggest = document.getElementById('dd2-customer-suggest');
        var clearBtn = document.getElementById('dd2-customer-clear');
        if (!btn || !pop || !input || !suggest) return;
        var activeIdx = -1;

        btn.addEventListener('click', function () { pop.hidden = !pop.hidden; if (!pop.hidden) input.focus(); });

        function items() { return suggest.querySelectorAll('.app-remind-suggest-item'); }
        function closeSuggest() { suggest.innerHTML = ''; suggest.classList.remove('is-open'); activeIdx = -1; }
        function highlight(idx) {
            var els = items();
            els.forEach(function (el) { el.classList.remove('is-active'); });
            if (idx >= 0 && els[idx]) { els[idx].classList.add('is-active'); els[idx].scrollIntoView({ block: 'nearest' }); }
        }
        function pick(el) {
            if (!el) return;
            currentCustomerId = parseInt(el.getAttribute('data-id'), 10) || 0;
            input.value = el.getAttribute('data-name');
            closeSuggest();
            pop.hidden = true;
            reloadExportBlock();
        }

        input.addEventListener('input', debounce(function () {
            var kw = input.value.trim();
            if (!kw) { closeSuggest(); return; }
            postForm('fev_search_customers', { keyword: kw }).then(function (res) {
                var list = (res && res.data) || [];
                suggest.innerHTML = list.length ? list.map(function (it) {
                    return '<div class="app-remind-suggest-item" data-id="' + it.id + '" data-name="' + escapeHtml(it.label) + '">' + escapeHtml(it.label) + '</div>';
                }).join('') : '<div class="app-remind-suggest-empty">Không có kết quả</div>';
                suggest.classList.add('is-open');
                activeIdx = -1;
            });
        }, 250));
        input.addEventListener('keydown', function (e) {
            if (!suggest.classList.contains('is-open')) return;
            var els = items();
            if (!els.length) return;
            if (e.key === 'ArrowDown') { e.preventDefault(); activeIdx = (activeIdx + 1) % els.length; highlight(activeIdx); }
            else if (e.key === 'ArrowUp') { e.preventDefault(); activeIdx = (activeIdx - 1 + els.length) % els.length; highlight(activeIdx); }
            else if (e.key === 'Enter') { if (activeIdx >= 0) { e.preventDefault(); pick(els[activeIdx]); } }
            else if (e.key === 'Tab') { if (activeIdx >= 0) pick(els[activeIdx]); }
            else if (e.key === 'Escape') { closeSuggest(); }
        });
        suggest.addEventListener('click', function (e) {
            var item = e.target.closest('.app-remind-suggest-item');
            if (!item) return;
            e.stopPropagation();
            pick(item);
        });
        if (clearBtn) clearBtn.addEventListener('click', function () {
            currentCustomerId = 0; input.value = ''; pop.hidden = true;
            reloadExportBlock();
        });
        document.addEventListener('click', function (e) {
            if (!pop.contains(e.target) && e.target !== btn) { pop.hidden = true; closeSuggest(); }
        });
    }

    /* ---------------- Modal: cập nhật tồn đầu quỹ ---------------- */
    var fundOpeningModal = document.getElementById('dd2-fund-opening-modal');
    function openFundOpeningModal() { if (fundOpeningModal) { fundOpeningModal.classList.add('is-open'); fundOpeningModal.setAttribute('aria-hidden', 'false'); } }
    function closeFundOpeningModal() { if (fundOpeningModal) { fundOpeningModal.classList.remove('is-open'); fundOpeningModal.setAttribute('aria-hidden', 'true'); } }

    /* ---------------- Modal: giải thích Hiệu quả / Điều tiết (nội dung đã render sẵn ở PHP view, chỉ toggle hiện/ẩn) ---------------- */
    var efficiencyModal = document.getElementById('dd2-efficiency-modal');
    function openEfficiencyModal() { if (efficiencyModal) { efficiencyModal.classList.add('is-open'); efficiencyModal.setAttribute('aria-hidden', 'false'); } }
    function closeEfficiencyModal() { if (efficiencyModal) { efficiencyModal.classList.remove('is-open'); efficiencyModal.setAttribute('aria-hidden', 'true'); } }
    var regulationModal = document.getElementById('dd2-regulation-modal');
    function openRegulationModal() { if (regulationModal) { regulationModal.classList.add('is-open'); regulationModal.setAttribute('aria-hidden', 'false'); } }
    function closeRegulationModal() { if (regulationModal) { regulationModal.classList.remove('is-open'); regulationModal.setAttribute('aria-hidden', 'true'); } }

    /* Kỳ tính chỉ số a của modal Điều tiết: 'month' (mặc định, khớp thẻ ngoài trang) | '30' | '60' | '90' ngày.
       Chỉ đổi nội dung TRONG modal — thẻ "Điều tiết" ngoài trang luôn giữ kỳ tháng này. */
    var regPeriodCache = {};   // period -> data đã tải (mở lại modal không gọi API lần nữa)
    var regPeriodBusy = false;

    function renderRegulation(d) {
        if (!d) return;
        var noteEl = document.getElementById('dd2-reg-period-note');
        if (noteEl) noteEl.textContent = d.period_note || '';
        var outEl = document.getElementById('dd2-reg-output');
        if (outEl) outEl.textContent = fmtNum(d.output);
        var expEl = document.getElementById('dd2-reg-export');
        if (expEl) expEl.textContent = fmtNum(d.export_qty);
        var aEl = document.getElementById('dd2-reg-a');
        if (aEl) aEl.textContent = (Number(d.a) || 0).toFixed(2);
        // b không phụ thuộc kỳ, nhưng vẫn cập nhật để modal không lệch sau khi lưu lại cài đặt SP chủ lực.
        var belowEl = document.getElementById('dd2-reg-key-below');
        if (belowEl) belowEl.textContent = Number(d.key_below) || 0;
        var totalEl = document.getElementById('dd2-reg-key-total');
        if (totalEl) totalEl.textContent = Number(d.key_total) || 0;
        var bEl = document.getElementById('dd2-reg-b');
        if (bEl) bEl.textContent = (Number(d.b) || 0).toFixed(2);
        var bPctEl = document.getElementById('dd2-reg-b-pct');
        if (bPctEl) bPctEl.textContent = Math.round((Number(d.b) || 0) * 100);
        var stEl = document.getElementById('dd2-reg-state');
        if (stEl) { stEl.textContent = d.label || ''; stEl.className = 'dd2-state-' + (d.state || 'on_dinh'); }
        var warnEl = document.getElementById('dd2-reg-period-warn');
        if (warnEl) warnEl.hidden = (d.period || 'month') === 'month';

        // ÁP DỤNG LUÔN ra thẻ "Điều tiết" ngoài trang (kỳ chọn đã được lưu ở app_settings nên
        // tải lại trang vẫn giữ, ảnh chụp báo cáo cũng theo kỳ này).
        var cardEl = document.getElementById('dd2-regulation-value');
        if (cardEl) {
            cardEl.className = 'dd2-state-' + (d.state || 'on_dinh');
            cardEl.textContent = (d.label || '') + ' (' + (Number(d.a) || 0).toFixed(1) + ')';
            cardEl.title = 'Kỳ tính: ' + (d.period_note || '') + ' — bấm để đổi';
        }
    }

    function selectRegulationPeriod(period) {
        var group = document.getElementById('dd2-reg-period-group');
        if (!group || regPeriodBusy) return;
        group.querySelectorAll('[data-dd2-reg-period]').forEach(function (b) {
            b.classList.toggle('active', b.getAttribute('data-dd2-reg-period') === period);
        });
        if (regPeriodCache[period]) { renderRegulation(regPeriodCache[period]); return; }

        var aEl = document.getElementById('dd2-reg-a');
        if (aEl) aEl.textContent = '...';
        regPeriodBusy = true;
        postForm('daily_dashboard_regulation', { period: period }).then(function (res) {
            regPeriodBusy = false;
            if (res && res.success && res.data) {
                regPeriodCache[period] = res.data;
                renderRegulation(res.data);
            } else if (aEl) {
                aEl.textContent = '—';
            }
        }).catch(function () {
            regPeriodBusy = false;
            if (aEl) aEl.textContent = '—';
        });
    }

    function wireRegulationPeriods() {
        var group = document.getElementById('dd2-reg-period-group');
        if (!group) return;
        // Kỳ mặc định đã render sẵn ở PHP view -> nạp vào cache để bấm qua lại không gọi API thừa.
        var initial = group.querySelector('.dd2-toggle-btn.active');
        var initialKey = initial ? initial.getAttribute('data-dd2-reg-period') : 'month';
        if (INITIAL.output && INITIAL.output.regulation) regPeriodCache[initialKey] = INITIAL.output.regulation;
        group.addEventListener('click', function (e) {
            var btn = e.target.closest('[data-dd2-reg-period]');
            if (btn) selectRegulationPeriod(btn.getAttribute('data-dd2-reg-period'));
        });
    }

    /* ---------------- Modal: nhân sự vắng hôm nay (nội dung đã render sẵn ở PHP view, chỉ toggle hiện/ẩn) ---------------- */
    var attendanceModal = document.getElementById('dd2-attendance-modal');
    function openAttendanceModal() { if (attendanceModal) { attendanceModal.classList.add('is-open'); attendanceModal.setAttribute('aria-hidden', 'false'); } }
    function closeAttendanceModal() { if (attendanceModal) { attendanceModal.classList.remove('is-open'); attendanceModal.setAttribute('aria-hidden', 'true'); } }

    /* ---------------- Modal: chi tiết đơn hàng chi nhánh (click 1 trong 4 card) ---------------- */
    var branchDetailModal = document.getElementById('dd2-branch-detail-modal');
    function openBranchDetailModal() { if (branchDetailModal) { branchDetailModal.classList.add('is-open'); branchDetailModal.setAttribute('aria-hidden', 'false'); } }
    function closeBranchDetailModal() { if (branchDetailModal) { branchDetailModal.classList.remove('is-open'); branchDetailModal.setAttribute('aria-hidden', 'true'); } }

    function wireBranchCards() {
        var list = document.getElementById('dd2-branch-orders-list');
        if (!list) return;
        list.addEventListener('click', function (e) {
            var card = e.target.closest('.dd2-branch-card');
            if (!card) return;
            var name = card.querySelector('.dd2-branch-name');
            document.getElementById('dd2-branch-detail-title').textContent = name ? name.textContent.replace(/:$/, '') : 'Chi tiết đơn hàng';
            var lines = [];
            try { lines = JSON.parse(card.getAttribute('data-shortage') || '[]'); } catch (err) { lines = []; }
            var items = [];
            try { items = JSON.parse(card.getAttribute('data-items') || '[]'); } catch (err2) { items = []; }
            var body = document.getElementById('dd2-branch-detail-body');
            var html = '';
            if (!lines.length) {
                html += '<p class="dd2-empty">Đơn này đủ tồn kho cho toàn bộ sản phẩm.</p>';
            } else {
                html += '<div class="dd2-branch-shortage">' + lines.map(function (l) {
                    return '<div class="dd2-branch-shortage-line"><i class="fa-solid fa-triangle-exclamation"></i> ' + escapeShortageLine(l) + '</div>';
                }).join('') + '</div>';
            }
            // Danh sách đầy đủ toàn bộ SP của đơn (không chỉ dòng thiếu tồn) — hiển thị dưới list thiếu,
            // bọc trong khối cuộn dọc riêng (đơn nhiều SP không kéo dài cả modal).
            if (items.length) {
                html += '<div class="dd2-branch-full-head">Danh sách đơn hàng</div>' +
                    '<div class="dd2-branch-full-scroll"><table class="dd2-cost-table dd2-branch-full-table"><thead><tr><th>Tên sản phẩm</th><th>SL đặt</th><th>Tồn</th><th>Thành tiền</th></tr></thead><tbody>' +
                    items.map(function (it) {
                        var qtyText = fmtNum(it.qty) + (it.unit ? ' ' + escapeHtml(it.unit) : '');
                        var stockText = fmtNum(it.stock) + (it.unit ? ' ' + escapeHtml(it.unit) : '');
                        var rowClass = it.is_short ? ' class="is-short"' : '';
                        return '<tr' + rowClass + '><td>' + escapeHtml(it.name) + '</td><td>' + qtyText + '</td><td>' + stockText + '</td><td>' + fmtMoney(it.value) + '</td></tr>';
                    }).join('') + '</tbody></table></div>';
            }
            body.innerHTML = html;
            openBranchDetailModal();
        });
    }

    /* ---------------- Modal: giải thích giá trị dự kiến 1 đơn "Đặt hàng nguyên liệu" (click .dd2-mo-item-count) ---------------- */
    var moPriceModal = document.getElementById('dd2-mo-price-modal');
    function openMoPriceModal() { if (moPriceModal) { moPriceModal.classList.add('is-open'); moPriceModal.setAttribute('aria-hidden', 'false'); } }
    function closeMoPriceModal() { if (moPriceModal) { moPriceModal.classList.remove('is-open'); moPriceModal.setAttribute('aria-hidden', 'true'); } }

    function wireMoPriceClick() {
        var list = document.getElementById('dd2-material-orders-list');
        if (!list) return;
        list.addEventListener('click', function (e) {
            var el = e.target.closest('.dd2-mo-item-count');
            if (!el) return;
            var row = el.closest('.dd2-mo-row');
            var supplierEl = row ? row.querySelector('.dd2-mo-supplier') : null;
            document.getElementById('dd2-mo-price-title').textContent = 'Giá trị nhập kho dự kiến đơn ' + (supplierEl ? supplierEl.textContent : '');
            var rows = [];
            try { rows = JSON.parse(el.getAttribute('data-price-breakdown') || '[]'); } catch (err) { rows = []; }
            var body = document.getElementById('dd2-mo-price-body');
            if (!rows.length) {
                body.innerHTML = '<p class="dd2-empty">Không có dữ liệu chi tiết.</p>';
            } else {
                var total = 0;
                var trs = rows.map(function (r) {
                    var lineTotal = (Number(r.qty) || 0) * (Number(r.price) || 0);
                    total += lineTotal;
                    return '<tr><td>' + escapeHtml(r.name) + '</td><td>' + fmtNum(r.qty) + (r.unit ? ' ' + escapeHtml(r.unit) : '') + '</td><td>' + fmtMoney(r.price) + '</td><td>' + fmtMoney(lineTotal) + '</td></tr>';
                }).join('');
                body.innerHTML = '<table class="dd2-cost-table"><thead><tr><th>Tên nguyên liệu</th><th>Số lượng</th><th>Đơn giá</th><th>Thành tiền</th></tr></thead><tbody>' +
                    trs + '</tbody><tfoot><tr><td colspan="3">Tổng cộng</td><td>' + fmtMoney(total) + '</td></tr></tfoot></table>';
            }
            openMoPriceModal();
        });
    }

    /* ---------------- Modal: giải thích giá trị nhập kho 1 hóa đơn (click .dd2-value khối "Nhập kho") ---------------- */
    var importValueModal = document.getElementById('dd2-import-value-modal');
    function openImportValueModal() { if (importValueModal) { importValueModal.classList.add('is-open'); importValueModal.setAttribute('aria-hidden', 'false'); } }
    function closeImportValueModal() { if (importValueModal) { importValueModal.classList.remove('is-open'); importValueModal.setAttribute('aria-hidden', 'true'); } }

    function wireImportValueClick() {
        var list = document.getElementById('dd2-imports-list');
        if (!list) return;
        list.addEventListener('click', function (e) {
            var el = e.target.closest('.dd2-value');
            if (!el) return;
            var items = [];
            try { items = JSON.parse(el.getAttribute('data-items') || '[]'); } catch (err) { items = []; }
            var total = el.getAttribute('data-total') || fmtMoney(0);
            var body = document.getElementById('dd2-import-value-body');
            if (!items.length) {
                body.innerHTML = '<p class="dd2-empty">Không có dữ liệu chi tiết.</p>';
            } else {
                var trs = items.map(function (it) {
                    return '<tr><td>' + escapeHtml(it.name) + '</td><td>' + escapeHtml(it.unit) + '</td><td>' + fmtMoney(it.price) + '</td><td>' + fmtMoney(it.cpmh) + '</td><td>' + fmtMoney(it.value) + '</td></tr>';
                }).join('');
                body.innerHTML = '<table class="dd2-cost-table"><thead><tr><th>Tên hàng hóa</th><th>Đơn vị</th><th>Đơn giá nhập</th><th>CPMH</th><th>Giá trị nhập kho</th></tr></thead><tbody>' +
                    trs + '</tbody><tfoot><tr><td colspan="4">Tổng giá trị nhập kho</td><td>' + escapeHtml(total) + '</td></tr></tfoot></table>';
            }
            openImportValueModal();
        });
    }

    /* ---------------- Modal: giải thích giá trị xuất kho 1 phiếu (click .dd2-value khối "Xuất kho",
       chỉ render trong modal "danh sách đầy đủ" — xem rowsExports()) ---------------- */
    var exportValueModal = document.getElementById('dd2-export-value-modal');
    function openExportValueModal() { if (exportValueModal) { exportValueModal.classList.add('is-open'); exportValueModal.setAttribute('aria-hidden', 'false'); } }
    function closeExportValueModal() { if (exportValueModal) { exportValueModal.classList.remove('is-open'); exportValueModal.setAttribute('aria-hidden', 'true'); } }

    function wireExportValueClick() {
        var list = document.getElementById('dd2-full-list-body');
        if (!list) return;
        list.addEventListener('click', function (e) {
            var el = e.target.closest('.dd2-export-value');
            if (!el) return;
            var items = [];
            try { items = JSON.parse(el.getAttribute('data-items') || '[]'); } catch (err) { items = []; }
            var total = el.getAttribute('data-total') || fmtMoney(0);
            var body = document.getElementById('dd2-export-value-body');
            if (!items.length) {
                body.innerHTML = '<p class="dd2-empty">Không có dữ liệu chi tiết.</p>';
            } else {
                var trs = items.map(function (it) {
                    return '<tr><td>' + escapeHtml(it.name) + '</td><td>' + escapeHtml(it.unit) + '</td><td>' + fmtNum(it.qty) + '</td><td>' + fmtMoney(it.price) + '</td><td>' + fmtMoney(it.value) + '</td></tr>';
                }).join('');
                body.innerHTML = '<table class="dd2-cost-table"><thead><tr><th>Tên sản phẩm</th><th>Đơn vị</th><th>Số lượng</th><th>Đơn giá</th><th>Thành tiền</th></tr></thead><tbody>' +
                    trs + '</tbody><tfoot><tr><td colspan="4">Tổng giá trị xuất kho</td><td>' + escapeHtml(total) + '</td></tr></tfoot></table>';
            }
            openExportValueModal();
        });
    }

    function saveFundOpening() {
        var value = document.getElementById('dd2-fund-opening-input').value;
        postForm('daily_dashboard_save_fund_opening_balance', { value: value }).then(function (res) {
            if (!res || !res.success) return;
            document.querySelector('.dd2-fund-balance-inline').textContent = fmtMoney(res.balance);
            closeFundOpeningModal();
        });
    }

    /* ---------------- Picker: chọn nhà cung cấp (khối Nhập kho) ---------------- */
    function wireSupplierPicker() {
        var btn = document.getElementById('dd2-import-supplier-btn');
        var pop = document.getElementById('dd2-supplier-picker');
        var input = document.getElementById('dd2-supplier-search');
        var suggest = document.getElementById('dd2-supplier-suggest');
        var clearBtn = document.getElementById('dd2-supplier-clear');
        if (!btn || !pop || !input || !suggest) return;
        var activeIdx = -1;

        btn.addEventListener('click', function () { pop.hidden = !pop.hidden; if (!pop.hidden) input.focus(); });

        function items() { return suggest.querySelectorAll('.app-remind-suggest-item'); }
        function closeSuggest() { suggest.innerHTML = ''; suggest.classList.remove('is-open'); activeIdx = -1; }
        function highlight(idx) {
            var els = items();
            els.forEach(function (el) { el.classList.remove('is-active'); });
            if (idx >= 0 && els[idx]) { els[idx].classList.add('is-active'); els[idx].scrollIntoView({ block: 'nearest' }); }
        }
        function pick(el) {
            if (!el) return;
            currentSupplierId = parseInt(el.getAttribute('data-id'), 10) || 0;
            input.value = el.getAttribute('data-name');
            closeSuggest();
            pop.hidden = true;
            loadImportsPage(1);
        }

        input.addEventListener('input', debounce(function () {
            var kw = input.value.trim();
            if (!kw) { closeSuggest(); return; }
            postForm('fev_search_suppliers', { keyword: kw }).then(function (res) {
                var list = (res && res.data) || [];
                suggest.innerHTML = list.length ? list.map(function (it) {
                    return '<div class="app-remind-suggest-item" data-id="' + it.id + '" data-name="' + escapeHtml(it.label) + '">' + escapeHtml(it.label) + '</div>';
                }).join('') : '<div class="app-remind-suggest-empty">Không có kết quả</div>';
                suggest.classList.add('is-open');
                activeIdx = -1;
            });
        }, 250));
        input.addEventListener('keydown', function (e) {
            if (!suggest.classList.contains('is-open')) return;
            var els = items();
            if (!els.length) return;
            if (e.key === 'ArrowDown') { e.preventDefault(); activeIdx = (activeIdx + 1) % els.length; highlight(activeIdx); }
            else if (e.key === 'ArrowUp') { e.preventDefault(); activeIdx = (activeIdx - 1 + els.length) % els.length; highlight(activeIdx); }
            else if (e.key === 'Enter') { if (activeIdx >= 0) { e.preventDefault(); pick(els[activeIdx]); } }
            else if (e.key === 'Tab') { if (activeIdx >= 0) pick(els[activeIdx]); }
            else if (e.key === 'Escape') { closeSuggest(); }
        });
        suggest.addEventListener('click', function (e) {
            var item = e.target.closest('.app-remind-suggest-item');
            if (!item) return;
            e.stopPropagation();
            pick(item);
        });
        if (clearBtn) clearBtn.addEventListener('click', function () {
            currentSupplierId = 0; input.value = ''; pop.hidden = true;
            loadImportsPage(1);
        });
        document.addEventListener('click', function (e) {
            if (!pop.contains(e.target) && e.target !== btn) { pop.hidden = true; closeSuggest(); }
        });
    }

    /* ---------------- Logo/brand header (sửa tại chỗ, chỉ riêng trang này) ---------------- */
    function wireLogoCaption() {
        var text = document.getElementById('dd2-hdr-brand-text');
        var editBtn = document.getElementById('dd2-hdr-brand-edit');
        if (!text || !editBtn) return;
        editBtn.addEventListener('click', function () {
            text.contentEditable = 'true';
            text.focus();
            document.execCommand('selectAll', false, null);
        });
        text.addEventListener('blur', function () {
            text.contentEditable = 'false';
            var val = text.textContent.trim();
            if (!val) { val = 'VUA AN TOÀN'; text.textContent = val; }
            postForm('daily_dashboard_save_logo_caption', { value: val });
        });
        text.addEventListener('keydown', function (e) {
            if (e.key === 'Enter') { e.preventDefault(); text.blur(); }
            else if (e.key === 'Escape') { text.contentEditable = 'false'; }
        });
    }

    /* ---------------- Init ---------------- */
    function init() {
        renderOutputChart(INITIAL.output.months || [], INITIAL.output.current || 0);
        renderCurrentExportChart();

        var impPager = document.querySelector('[data-dd2-pager="imports"]');
        if (impPager) renderBatchPager(impPager, impPager.getAttribute('data-page'), impPager.getAttribute('data-total-pages'), loadImportsPage);
        var moPager = document.querySelector('[data-dd2-pager="material_orders"]');
        if (moPager) renderBatchPager(moPager, moPager.getAttribute('data-page'), moPager.getAttribute('data-total-pages'), loadMaterialOrdersPage);
        var fundPager = document.querySelector('[data-dd2-pager="fund"]');
        if (fundPager) renderBatchPager(fundPager, fundPager.getAttribute('data-page'), fundPager.getAttribute('data-total-pages'), loadFundPage);
        var pcPager = document.querySelector('[data-dd2-pager="price_changes"]');
        if (pcPager) renderBatchPager(pcPager, pcPager.getAttribute('data-page'), pcPager.getAttribute('data-total-pages'), loadPriceChangesPage);
        wirePriceChanges();
        wireStockWatch();
        renderProductionPage();

        // Cài đặt hiệu quả
        var settingsBtn = document.getElementById('dd2-settings-btn');
        if (settingsBtn) settingsBtn.addEventListener('click', openSettingsModal);
        document.querySelectorAll('[data-dd2-settings-close]').forEach(function (el) { el.addEventListener('click', closeSettingsModal); });
        var saveBtn = document.getElementById('dd2-settings-save');
        if (saveBtn) saveBtn.addEventListener('click', saveSettings);
        wireKeyProductPicker();

        // Thiết lập giá trị tạm — Sản lượng
        var tempValuesBtn = document.getElementById('dd2-temp-values-btn');
        if (tempValuesBtn) tempValuesBtn.addEventListener('click', function () { closeSettingsModal(); openTempValuesModal(); });
        document.querySelectorAll('[data-dd2-temp-values-close]').forEach(function (el) { el.addEventListener('click', closeTempValuesModal); });
        var tempValuesSaveBtn = document.getElementById('dd2-temp-values-save');
        if (tempValuesSaveBtn) tempValuesSaveBtn.addEventListener('click', saveTempValues);

        // Thiết lập giá trị tạm — Xuất kho
        var exportSettingsBtn = document.getElementById('dd2-export-settings-btn');
        if (exportSettingsBtn) exportSettingsBtn.addEventListener('click', openExportTempValuesModal);
        document.querySelectorAll('[data-dd2-export-temp-values-close]').forEach(function (el) { el.addEventListener('click', closeExportTempValuesModal); });
        var exportTvSaveBtn = document.getElementById('dd2-export-temp-values-save');
        if (exportTvSaveBtn) exportTvSaveBtn.addEventListener('click', saveExportTempValues);
        wireExportTvTabs();

        // Modal sản phẩm (click tên SP) / modal chi tiết NVL (click giá vốn) — cost cell ưu tiên trước, chặn nổi bọt lên row.
        document.querySelectorAll('[data-dd2-product-close]').forEach(function (el) { el.addEventListener('click', closeProductModal); });
        document.querySelectorAll('[data-dd2-cost-close]').forEach(function (el) { el.addEventListener('click', closeCostModal); });
        document.addEventListener('click', function (e) {
            var costCell = e.target.closest && e.target.closest('.dd2-prod-cost');
            if (costCell) {
                var cpid = costCell.getAttribute('data-product-id');
                document.getElementById('dd2-cost-modal-body').innerHTML = '<p class="dd2-empty">Đang tải...</p>';
                openCostModal();
                postForm('daily_dashboard_product_cost_breakdown', { product_id: cpid, date: prodCurrentDate }).then(function (res) {
                    if (res && res.success) renderCostModal(res.data);
                    else document.getElementById('dd2-cost-modal-body').innerHTML = '<p class="dd2-empty">Không tải được dữ liệu.</p>';
                });
                return;
            }
            var row = e.target.closest && e.target.closest('.dd2-prod-row:not(.is-empty)');
            if (!row) return;
            var pid = row.getAttribute('data-product-id');
            document.getElementById('dd2-product-modal-body').innerHTML = '<p class="dd2-empty">Đang tải...</p>';
            openProductModal();
            postForm('daily_dashboard_product_detail', { product_id: pid, date: prodCurrentDate }).then(function (res) {
                if (res && res.success) renderProductModal(res.data);
                else document.getElementById('dd2-product-modal-body').innerHTML = '<p class="dd2-empty">Không tải được dữ liệu.</p>';
            });
        });

        // Cập nhật tồn đầu quỹ
        var fundOpeningBtn = document.getElementById('dd2-fund-opening-btn');
        if (fundOpeningBtn) fundOpeningBtn.addEventListener('click', openFundOpeningModal);
        document.querySelectorAll('[data-dd2-fund-opening-close]').forEach(function (el) { el.addEventListener('click', closeFundOpeningModal); });
        var fundOpeningSaveBtn = document.getElementById('dd2-fund-opening-save');
        if (fundOpeningSaveBtn) fundOpeningSaveBtn.addEventListener('click', saveFundOpening);

        // Modal giải thích Hiệu quả / Điều tiết
        var efficiencyValueEl = document.getElementById('dd2-efficiency-value');
        if (efficiencyValueEl) efficiencyValueEl.addEventListener('click', openEfficiencyModal);
        document.querySelectorAll('[data-dd2-efficiency-close]').forEach(function (el) { el.addEventListener('click', closeEfficiencyModal); });
        var regulationValueEl = document.getElementById('dd2-regulation-value');
        if (regulationValueEl) regulationValueEl.addEventListener('click', openRegulationModal);
        document.querySelectorAll('[data-dd2-regulation-close]').forEach(function (el) { el.addEventListener('click', closeRegulationModal); });
        wireRegulationPeriods();

        // Chấm công: click khối "Vắng" ở đầu trang xem danh sách đầy đủ
        var attendanceBtn = document.getElementById('dd2-attendance-btn');
        if (attendanceBtn) attendanceBtn.addEventListener('click', openAttendanceModal);
        document.querySelectorAll('[data-dd2-attendance-close]').forEach(function (el) { el.addEventListener('click', closeAttendanceModal); });

        // Chi nhánh đặt hàng: click card xem SP thiếu tồn + lý do
        wireBranchCards();
        document.querySelectorAll('[data-dd2-branch-detail-close]').forEach(function (el) { el.addEventListener('click', closeBranchDetailModal); });

        // Đặt hàng nguyên liệu: click giá trị dự kiến xem chi tiết NVL/SP × đơn giá
        wireMoPriceClick();
        document.querySelectorAll('[data-dd2-mo-price-close]').forEach(function (el) { el.addEventListener('click', closeMoPriceModal); });

        // Nhập kho: click giá trị nhập kho xem chi tiết từng mặt hàng
        wireImportValueClick();
        document.querySelectorAll('[data-dd2-import-value-close]').forEach(function (el) { el.addEventListener('click', closeImportValueModal); });

        // Xuất kho (modal danh sách đầy đủ): click giá trị xuất kho xem chi tiết từng sản phẩm
        wireExportValueClick();
        document.querySelectorAll('[data-dd2-export-value-close]').forEach(function (el) { el.addEventListener('click', closeExportValueModal); });

        // Modal nhóm theo NCC
        var countBtn = document.getElementById('dd2-import-count-btn');
        if (countBtn) countBtn.addEventListener('click', function () { openImportGroupModal('Số hóa đơn theo nhà cung cấp', 'count'); });
        var cpmhBtn = document.getElementById('dd2-import-cpmh-btn');
        if (cpmhBtn) cpmhBtn.addEventListener('click', function () { openImportGroupModal('Chi phí mua hàng theo nhà cung cấp', 'cost'); });
        document.querySelectorAll('[data-dd2-import-group-close]').forEach(function (el) { el.addEventListener('click', closeImportGroupModal); });

        // Modal ảnh hóa đơn (click tên NCC/KH)
        document.querySelectorAll('[data-dd2-att-close]').forEach(function (el) { el.addEventListener('click', closeAttModal); });
        if (attGrid) attGrid.addEventListener('click', function (e) {
            var item = e.target.closest('.dd2-att-item[data-view]');
            if (item && window.InvoiceViewer) window.InvoiceViewer.open(item.getAttribute('data-view'));
        });
        document.addEventListener('click', function (e) {
            var supBtn = e.target.closest && e.target.closest('.dd2-entity-link[data-supplier-id]');
            var cusBtn = e.target.closest && e.target.closest('.dd2-entity-link[data-customer-id]');
            if (supBtn) {
                var sid = supBtn.getAttribute('data-supplier-id');
                var d1 = supBtn.getAttribute('data-date-iso');
                postForm('fev_invoices_by_supplier_date', { supplier_id: sid, date: d1 }).then(function (res) {
                    openAttModal((res && res.ok) ? (res.data || []) : []);
                });
            } else if (cusBtn) {
                var cid = cusBtn.getAttribute('data-customer-id');
                var d2 = cusBtn.getAttribute('data-date-iso');
                postForm('fev_invoices_by_customer_date', { customer_id: cid, date: d2 }).then(function (res) {
                    openAttModal((res && res.ok) ? (res.data || []) : []);
                });
            }
        });

        // Modal danh sách đầy đủ dùng chung + sidebar
        document.querySelectorAll('[data-dd2-full-list-close]').forEach(function (el) { el.addEventListener('click', closeFullListModal); });
        wireSidebarIcons();
        var importMoreBtn = document.getElementById('dd2-import-more-btn');
        if (importMoreBtn) importMoreBtn.addEventListener('click', function () {
            openFullListModal('Nhập kho', 'daily_dashboard_imports_full', rowsImports, {}, { searchParam: 'keyword', searchPlaceholder: 'Tìm nhà cung cấp...', totals: flImportsTotals });
        });
        var salesOrderBtn = document.getElementById('dd2-export-sales-order-btn');
        if (salesOrderBtn) salesOrderBtn.addEventListener('click', function () {
            openFullListModal('Xuất kho', 'daily_dashboard_exports_full', rowsExports, {}, { searchParam: 'keyword', searchPlaceholder: 'Tìm khách hàng...', dateRange: true, pageSize: true, totals: flExportsTotals });
        });

        wireDayNav();
        wireCustomerPicker();
        wireSupplierPicker();
        wireLogoCaption();
        wireCaptureButton();

        // Mở bằng ?auto_send=<id> → tự chụp & gửi báo cáo rồi quay lại (xem auto_report_poller.js).
        var __arQs = new URLSearchParams(location.search);
        if (__arQs.has('auto_send')) {
            runAutoSend(parseInt(__arQs.get('auto_send'), 10));
        }
    }

    /* ---------------- Chụp báo cáo (nay tự gồm app-header vì header đã lồng trong .dd2-content) ---------------- */
    function roundRectPath(ctx, x, y, w, h, r) {
        ctx.beginPath();
        ctx.moveTo(x + r, y);
        ctx.arcTo(x + w, y, x + w, y + h, r);
        ctx.arcTo(x + w, y + h, x, y + h, r);
        ctx.arcTo(x, y + h, x, y, r);
        ctx.arcTo(x, y, x + w, y, r);
        ctx.closePath();
    }

    // Tạo ảnh PNG (Blob) của .dd2-content: chụp html2canvas rồi tự vẽ nền/bo góc/đổ bóng.
    // Tách riêng để DÙNG CHUNG cho nút "Chụp" (copy clipboard) và luồng GỬI TỰ ĐỘNG (POST lên server).
    // viewportH/viewportW: cỡ viewport dùng cho bản CLONE của html2canvas — mặc định lấy
    // window.innerHeight/innerWidth, nhưng luồng chụp ở MOBILE phải truyền đúng khung đã ghim
    // (1920x1080). Bắt buộc vì window.inner* lúc đó là VISUAL viewport (trình duyệt khoá tỉ lệ
    // thu nhỏ tối thiểu nên đứng ~1560) — để mặc định thì clone bố trí ở 1560, ảnh hẹp hơn thật
    // và các cột tên hàng hóa bị cắt chữ.
    async function buildReportBlob(viewportH, viewportW, srcDoc) {
        if (typeof window.html2canvas !== 'function') {
            throw new Error('Không nạp được html2canvas (kiểm tra mạng).');
        }
        // srcDoc: tài liệu nguồn — mặc định là trang hiện tại, luồng chụp mobile truyền vào
        // document CỦA IFRAME khung cứng 1920x950 (xem captureViaFrame).
        var doc = srcDoc || document;
        var contentEl = doc.querySelector('.dd2-content');
        if (!contentEl) throw new Error('Không tìm thấy nội dung báo cáo.');

        var scale = 2;
        // 2026-07-28: trang có thêm hàng 3 nên .dd2-main cuộn dọc -> ảnh chụp phải lấy CẢ phần
        // đang bị cuộn khuất. Cách làm: đo phần dư (scrollHeight - clientHeight) để nới chiều cao
        // vùng chụp, còn bản CLONE của html2canvas thì mở khóa overflow + ghim chiều cao từng hàng
        // bằng px đo được từ DOM thật (nếu để calc(50%) trong clone cao hơn, các hàng sẽ giãn theo
        // và lệch bố cục). Nhờ overflow:visible trên clone, ảnh cũng KHÔNG dính thanh cuộn vật lý.
        var mainEl = doc.querySelector('.dd2-main');
        var overflowH = mainEl ? Math.max(0, mainEl.scrollHeight - mainEl.clientHeight) : 0;
        var rowHeights = [];
        if (mainEl) {
            mainEl.querySelectorAll(':scope > .dd2-row').forEach(function (r) {
                rowHeights.push(r.getBoundingClientRect().height);
            });
        }
        // 2 card của hàng 3 có thể đang cuộn bên trong (màn hình thấp) -> nới thêm đúng phần bị
        // khuất để ảnh có đủ nội dung (hàng 3 là hàng CUỐI nên cho cao tự do được). Lấy MAX vì
        // 2 card cùng hàng sẽ cùng cao theo card cần nhiều chỗ nhất.
        var scrollBoxes = ['.dd2-pc-wrap', '.dd2-sw-cols'];
        var pcExtra = 0;
        scrollBoxes.forEach(function (sel) {
            var el = doc.querySelector(sel);
            if (el) pcExtra = Math.max(pcExtra, el.scrollHeight - el.clientHeight);
        });

        // Chụp riêng nội dung, tự vẽ nền/bo góc/đổ bóng ở bước ghép (html2canvas hay cắt box-shadow).
        var contentCanvas = await window.html2canvas(contentEl, {
            backgroundColor: '#ffffff', scale: scale, useCORS: true, logging: false,
            height: contentEl.offsetHeight + overflowH + pcExtra,
            windowWidth: viewportW || window.innerWidth,
            windowHeight: (viewportH || window.innerHeight) + overflowH + pcExtra,
            // Nút chụp giữ NGUYÊN (icon camera không mờ) trong ảnh — chỉ bỏ disabled trên bản clone.
            // Xem ghi chú cũ: KHÔNG đổi cấu trúc DOM nút này trong onclone (html2canvas render sai).
            onclone: function (clonedDoc) {
                var b = clonedDoc.getElementById('dd2-capture-btn');
                if (b) b.disabled = false;

                var cw = clonedDoc.getElementById('wrapper');
                if (cw) { cw.style.height = 'auto'; cw.style.overflow = 'visible'; }
                var cc = clonedDoc.querySelector('.dd2-content');
                if (cc) { cc.style.height = 'auto'; cc.style.overflow = 'visible'; }
                var cm = clonedDoc.querySelector('.dd2-main');
                if (cm) {
                    cm.style.height = 'auto';
                    cm.style.overflow = 'visible';       // -> ảnh không có thanh cuộn
                    var last = rowHeights.length - 1;
                    cm.querySelectorAll(':scope > .dd2-row').forEach(function (r, i) {
                        if (!rowHeights[i]) return;
                        r.style.flex = '0 0 auto';
                        // Hàng cuối được phép cao hơn để chứa hết bảng đang bị cuộn; các hàng
                        // trên phải ghim đúng chiều cao thật, nếu không bố cục sẽ giãn lệch.
                        if (i === last) { r.style.height = 'auto'; r.style.minHeight = rowHeights[i] + 'px'; }
                        else { r.style.height = rowHeights[i] + 'px'; }
                    });
                }
                scrollBoxes.forEach(function (sel) {
                    var el = clonedDoc.querySelector(sel);
                    if (el) { el.style.overflow = 'visible'; el.style.height = 'auto'; }
                });
                // Sidebar icon neo theo chiều cao màn hình -> kéo dài theo nội dung để nhóm nút
                // dưới cùng không bị "trôi" giữa ảnh.
                var cs = clonedDoc.querySelector('.dd2-sidebar');
                if (cs) cs.style.height = 'auto';

                // html2canvas KHÔNG clone <canvas> bằng cloneNode mà tạo canvas MỚI (chỉ chép
                // bitmap) nên inline style width/height Chart.js đặt bị mất -> canvas lấy cỡ CSS
                // mặc định = cỡ bitmap. Màn hình devicePixelRatio > 1 (phone, retina) có bitmap
                // gấp đôi CSS nên biểu đồ trong ảnh bị phóng to và cắt mất phần bên phải.
                // Ghim lại đúng cỡ CSS đo từ DOM thật (khớp theo thứ tự, clone giữ nguyên thứ tự DOM).
                var liveCanvas = contentEl.querySelectorAll('canvas');
                var cloneRoot = clonedDoc.querySelector('.dd2-content') || clonedDoc;
                var cloneCanvas = cloneRoot.querySelectorAll('canvas');
                liveCanvas.forEach(function (lc, i) {
                    var cc = cloneCanvas[i];
                    if (!cc) return;
                    var rect = lc.getBoundingClientRect();
                    if (!rect.width || !rect.height) return;
                    cc.style.width = rect.width + 'px';
                    cc.style.height = rect.height + 'px';
                });
            }
        });

        var margin = 48 * scale;
        var radius = 12 * 1.3 * scale; // bo góc +30%
        var w = contentCanvas.width + margin * 2;
        var h = contentCanvas.height + margin * 2;
        var out = document.createElement('canvas');
        out.width = w;
        out.height = h;
        var ctx = out.getContext('2d');

        var grad = ctx.createLinearGradient(0, 0, 0, h);
        grad.addColorStop(0, '#e5e7eb');
        grad.addColorStop(1, '#ced1d7'); // #9ca3af nhạt hơn 50% (pha trắng)
        ctx.fillStyle = grad;
        ctx.fillRect(0, 0, w, h);

        ctx.save();
        ctx.shadowColor = 'rgba(0,0,0,0.22)';
        ctx.shadowBlur = 26 * scale;
        ctx.shadowOffsetY = 8 * scale;
        roundRectPath(ctx, margin, margin, contentCanvas.width, contentCanvas.height, radius);
        ctx.fillStyle = '#ffffff';
        ctx.fill();
        ctx.restore();

        ctx.save();
        roundRectPath(ctx, margin, margin, contentCanvas.width, contentCanvas.height, radius);
        ctx.clip();
        ctx.drawImage(contentCanvas, margin, margin);
        ctx.restore();

        return await new Promise(function (resolve, reject) {
            out.toBlob(function (b) { b ? resolve(b) : reject(new Error('toBlob trả về null')); }, 'image/png');
        });
    }

    /* ---------------- Chụp báo cáo trên MOBILE ----------------
       Ở phone/tablet bố cục dashboard xếp dọc (media query ≤1200px) nên ảnh chụp khác hẳn bản
       desktop. Luồng mobile: mở modal -> đổi tạm <meta name="viewport"> sang width=1440 (media
       query tính theo LAYOUT VIEWPORT nên mọi rule desktop tự áp lại và Chart.js responsive vẽ
       lại canvas đúng cỡ, khác hẳn cách phóng to ảnh mobile cho ra chart mờ) + class
       .dd2-capturing ghim khung 900px -> chụp -> trả viewport về cũ -> xem trước ảnh và
       Chia sẻ / Tải về / Sao chép (clipboard ảnh trên phone thường không dùng được). */
    // 1920x1080 = khung cố định của ảnh báo cáo (giống cách phiếu in dùng khổ A4 cứng 210mm:
    // ảnh ra như nhau ở mọi thiết bị). Ở bề rộng này không sản phẩm/hàng hóa nào bị cắt tên.
    var CAPTURE_DESKTOP_W = 1920;
    var CAPTURE_DESKTOP_H = 950;
    var captureModal = document.getElementById('dd2-capture-modal');
    var captureBlob = null;
    var captureBlobUrl = '';

    /** Bố cục dashboard đổi sang xếp dọc từ 1200px trở xuống — dưới mốc này mới cần ép desktop. */
    function needsDesktopCapture() { return window.innerWidth <= 1200; }

    function captureFileName() {
        var d = new Date();
        var p = function (n) { return (n < 10 ? '0' : '') + n; };
        return 'bao-cao-' + d.getFullYear() + '-' + p(d.getMonth() + 1) + '-' + p(d.getDate()) + '.png';
    }

    function openCaptureModal() { if (captureModal) { captureModal.classList.add('is-open'); captureModal.setAttribute('aria-hidden', 'false'); } }
    function closeCaptureModal() {
        if (captureModal) { captureModal.classList.remove('is-open'); captureModal.setAttribute('aria-hidden', 'true'); }
        if (captureBlobUrl) { URL.revokeObjectURL(captureBlobUrl); captureBlobUrl = ''; }
        captureBlob = null;
        var prev = document.getElementById('dd2-capture-preview');
        if (prev) prev.innerHTML = '';
        var acts = document.getElementById('dd2-capture-actions');
        if (acts) acts.hidden = true;
    }

    function setCaptureStatus(text, spinning) {
        var el = document.getElementById('dd2-capture-status');
        if (el) el.textContent = text;
        var prev = document.getElementById('dd2-capture-preview');
        if (prev && spinning) prev.innerHTML = '<div class="dd2-capture-spin"></div>';
    }

    /** Đổi tạm viewport + ghim khung về tỉ lệ desktop. Trả hàm khôi phục nguyên trạng. */
    function forceDesktopLayout() {
        var meta = document.querySelector('meta[name="viewport"]');
        var prev = meta ? meta.getAttribute('content') : null;
        // Trình duyệt di động tự kẹp tỉ lệ thu nhỏ tối thiểu (Chrome mặc định 0.25). Với máy 412px
        // mà đòi khung 1920 thì cần tỉ lệ 0.21 < 0.25 -> có máy kẹp luôn LAYOUT viewport lại
        // (~1648px) hoặc bỏ qua hẳn yêu cầu. Phải khai initial-scale vừa khít + minimum-scale rất
        // nhỏ để trình duyệt không kẹp.
        var deviceW = layoutViewportWidth() || 390;
        var fit = Math.max(0.05, Math.min(1, deviceW / CAPTURE_DESKTOP_W));
        if (meta) {
            meta.setAttribute('content', 'width=' + CAPTURE_DESKTOP_W
                + ', initial-scale=' + fit.toFixed(3) + ', minimum-scale=0.05, maximum-scale=5');
        }
        document.documentElement.classList.add('dd2-capturing');
        return function () {
            if (meta) meta.setAttribute('content', prev || 'width=device-width, initial-scale=1.0');
            document.documentElement.classList.remove('dd2-capturing');
            // Chart.js bám ResizeObserver, nhưng bắn thêm resize cho chắc khi trả về bố cục mobile.
            window.dispatchEvent(new Event('resize'));
        };
    }

    /** ĐO documentElement.clientWidth (LAYOUT viewport — cái mà media query dùng), KHÔNG đo
     *  window.innerWidth: đó là VISUAL viewport, bị tỉ lệ thu nhỏ tối thiểu ghim lại (~1560) nên
     *  đo nhầm là tưởng ép viewport thất bại. */
    function layoutViewportWidth() {
        return document.documentElement.clientWidth || window.innerWidth || 0;
    }

    /** Chờ trình duyệt bố trí lại (tối đa ~2s). KHÔNG đòi đúng 1920: chỉ cần vượt mốc 1200px là
     *  dashboard đã về bố cục 3 cột của desktop; máy nào bị kẹp còn ~1650px thì ảnh hẹp hơn ảnh
     *  mẫu chút nhưng vẫn ĐÚNG bố cục, hơn hẳn dải dọc dài thượt. Trả về bề rộng đo được (0 = hỏng)
     *  để truyền đúng con số đó cho html2canvas thay vì đoán. */
    var DESKTOP_LAYOUT_MIN_W = 1240; // > mốc 1200px mà dashboard chuyển sang xếp dọc
    async function waitDesktopViewport() {
        for (var i = 0; i < 10; i++) {
            if (layoutViewportWidth() >= DESKTOP_LAYOUT_MIN_W) return layoutViewportWidth();
            await waitMs(200);
        }
        var w = layoutViewportWidth();
        return w >= DESKTOP_LAYOUT_MIN_W ? w : 0;
    }

    function waitMs(ms) { return new Promise(function (r) { setTimeout(r, ms); }); }

    /** Sau khi đổi viewport phải ép Chart.js đo lại: ResizeObserver của nó chạy trễ/nhảy bước nên
     *  canvas dễ bị giữ cỡ cũ -> chart phóng to và bị cắt mép trong ảnh chụp. */
    async function resizeChartsForCapture() {
        Object.keys(charts).forEach(function (k) {
            var c = charts[k];
            if (c && typeof c.resize === 'function') { c.resize(); c.update('none'); }
        });
        await waitMs(250);
    }

    function showCaptureResult(blob, forcedDesktop) {
        captureBlob = blob;
        if (captureBlobUrl) URL.revokeObjectURL(captureBlobUrl);
        captureBlobUrl = URL.createObjectURL(blob);

        var prev = document.getElementById('dd2-capture-preview');
        if (prev) prev.innerHTML = '<img src="' + captureBlobUrl + '" alt="Ảnh báo cáo">';
        setCaptureStatus(forcedDesktop === false
            ? 'Trình duyệt này không đổi được bố cục — ảnh theo bố cục màn hình hiện tại. Chọn cách gửi đi:'
            : 'Ảnh đã chụp theo bố cục desktop ' + CAPTURE_DESKTOP_W + 'x' + CAPTURE_DESKTOP_H + '. Chọn cách gửi đi:', false);

        var file = null;
        try { file = new File([blob], captureFileName(), { type: 'image/png' }); } catch (e) { file = null; }
        var canShare = !!(file && navigator.canShare && navigator.canShare({ files: [file] }) && navigator.share);
        var canCopy = !!(navigator.clipboard && typeof window.ClipboardItem === 'function');

        var acts = document.getElementById('dd2-capture-actions');
        if (acts) acts.hidden = false;
        var shareBtn = document.getElementById('dd2-capture-share');
        if (shareBtn) {
            shareBtn.hidden = !canShare;
            shareBtn.onclick = function () {
                navigator.share({ files: [file], title: 'Báo cáo sản xuất' }).catch(function () {});
            };
        }
        var dlBtn = document.getElementById('dd2-capture-download');
        if (dlBtn) {
            dlBtn.onclick = function () {
                var a = document.createElement('a');
                a.href = captureBlobUrl;
                a.download = captureFileName();
                document.body.appendChild(a);
                a.click();
                document.body.removeChild(a);
            };
        }
        var copyBtn = document.getElementById('dd2-capture-copy');
        if (copyBtn) {
            copyBtn.hidden = !canCopy;
            copyBtn.onclick = function () {
                navigator.clipboard.write([new window.ClipboardItem({ 'image/png': captureBlob })])
                    .then(function () { setCaptureStatus('Đã copy ảnh vào clipboard.', false); })
                    .catch(function () { setCaptureStatus('Trình duyệt không cho copy ảnh — hãy dùng "Tải ảnh".', false); });
            };
        }
    }

    /** CÁCH CHẮC ĂN NHẤT: nạp lại chính trang này vào một IFRAME có bề rộng CỨNG 1920x950 rồi chụp
     *  nội dung trong đó. Viewport của iframe CHÍNH LÀ kích thước iframe nên media query desktop
     *  chắc chắn kích hoạt và Chart.js vẽ canvas đúng cỡ desktop — không phụ thuộc chút nào vào
     *  việc thiết bị có chịu đổi thẻ <meta viewport> hay không (đã gặp máy thật bỏ qua hoàn toàn:
     *  đo được vẫn 384px). Đúng tinh thần #print-sheet khổ A4 cứng của phiếu in. */
    async function captureViaFrame(onStatus) {
        onStatus('Đang dựng khung ' + CAPTURE_DESKTOP_W + 'x' + CAPTURE_DESKTOP_H + '…');
        var frame = document.createElement('iframe');
        frame.setAttribute('aria-hidden', 'true');
        frame.setAttribute('tabindex', '-1');
        // Đặt ngoài màn hình chứ KHÔNG dùng display:none/visibility:hidden — phải được bố trí thật
        // thì Chart.js mới đo được kích thước và vẽ biểu đồ.
        frame.style.cssText = 'position:fixed;top:0;left:-' + (CAPTURE_DESKTOP_W + 100) + 'px;'
            + 'width:' + CAPTURE_DESKTOP_W + 'px;height:' + CAPTURE_DESKTOP_H + 'px;'
            + 'border:0;opacity:0;pointer-events:none;z-index:-1;';
        // URL sạch của chính view này. KHÔNG kéo theo query hiện tại: ?auto_send= sẽ khiến trang
        // trong iframe tự chụp-gửi rồi điều hướng, thành vòng lặp.
        frame.src = '?mod=report&controllers=report&action=daily_dashboard&dd2frame=1';
        document.body.appendChild(frame);
        try {
            await new Promise(function (resolve, reject) {
                var timer = setTimeout(function () { reject(new Error('khung tải quá lâu')); }, 45000);
                frame.addEventListener('load', function () { clearTimeout(timer); resolve(); }, { once: true });
                frame.addEventListener('error', function () { clearTimeout(timer); reject(new Error('khung lỗi')); }, { once: true });
            });
            var fdoc = frame.contentDocument;
            if (!fdoc) throw new Error('không đọc được nội dung khung');
            onStatus('Đang vẽ biểu đồ…');
            // Chờ nội dung + 2 biểu đồ vẽ xong (chart có animation).
            for (var i = 0; i < 40; i++) {
                var c = fdoc.querySelector('#dd2-chart-output');
                if (fdoc.querySelector('.dd2-content') && c && c.getBoundingClientRect().width > 100) break;
                await waitMs(200);
            }
            // Chờ font nạp xong: html2canvas tự đo chữ, font chưa sẵn sàng là số đo lệch -> chữ
            // tràn/ mất. Máy chậm mới lộ, máy nhanh thì font đã có sẵn trong bộ đệm.
            try { if (fdoc.fonts && fdoc.fonts.ready) await fdoc.fonts.ready; } catch (e) {}
            await waitMs(2200);
            // ÉP Chart.js TRONG IFRAME đo lại — nếu nó vẽ lúc bố cục chưa đứng yên (máy chậm, hoặc
            // #wrapper còn chạy transition padding-left chừa chỗ sidebar) thì canvas giữ cỡ cũ,
            // biểu đồ trong ảnh bị CO LẠI so với thẻ và mốc giá trị rơi ra ngoài. Trang cha đã làm
            // việc này qua resizeChartsForCapture(), khung iframe trước đây bị bỏ sót.
            try {
                var FW = frame.contentWindow;
                if (FW && FW.Chart) {
                    fdoc.querySelectorAll('canvas').forEach(function (cv) {
                        var ch = typeof FW.Chart.getChart === 'function' ? FW.Chart.getChart(cv) : null;
                        if (ch) { ch.resize(); ch.update('none'); }
                    });
                    await waitMs(500);
                }
            } catch (e) { console.warn('Không ép được Chart.js trong khung:', e); }
            onStatus('Đang dựng ảnh…');
            return await buildReportBlob(CAPTURE_DESKTOP_H, CAPTURE_DESKTOP_W, fdoc);
        } finally {
            if (frame.parentNode) frame.parentNode.removeChild(frame);
        }
    }

    /** Chụp theo KHUNG CỐ ĐỊNH desktop — trả {blob, forced, width, via}.
     *  Thử lần lượt: (1) ép viewport tại chỗ (nhanh, không phải nạp lại trang) ->
     *  (2) iframe khung cứng (chắc ăn, chậm hơn) -> (3) đành chụp bố cục hiện tại.
     *  Có thể ép chọn cách bằng ?dd2capture=viewport|frame|inline để hỗ trợ từ xa. */
    async function captureDesktopFramedBlob(onStatus) {
        onStatus = onStatus || function () {};
        var mode = '';
        try { mode = new URLSearchParams(location.search).get('dd2capture') || ''; } catch (e) { mode = ''; }

        // (1) Ép viewport tại chỗ
        if (mode !== 'frame' && mode !== 'inline') {
            onStatus('Đang cố định bố cục ' + CAPTURE_DESKTOP_W + 'x' + CAPTURE_DESKTOP_H + '…');
            var restore = forceDesktopLayout();
            var gotW = await waitDesktopViewport();
            if (gotW) {
                try {
                    // #wrapper có transition padding-left 0.22s (chừa chỗ sidebar) -> chờ bố cục
                    // đứng yên rồi mới đo, nếu không chiều cao từng hàng ghim vào clone sẽ lệch.
                    await waitMs(400);
                    await resizeChartsForCapture();
                    onStatus('Đang dựng ảnh…');
                    // Truyền ĐÚNG bề rộng đo được, không truyền hằng số.
                    var b1 = await buildReportBlob(CAPTURE_DESKTOP_H, gotW);
                    return { blob: b1, forced: true, width: gotW, via: 'viewport' };
                } finally {
                    restore();
                }
            }
            restore();
            console.warn('Thiết bị bỏ qua thẻ viewport (đo được ' + layoutViewportWidth() + 'px) -> chuyển sang khung iframe');
        }

        // (2) Iframe khung cứng
        if (mode !== 'inline') {
            try {
                var b2 = await captureViaFrame(onStatus);
                return { blob: b2, forced: true, width: CAPTURE_DESKTOP_W, via: 'iframe' };
            } catch (err) {
                console.warn('Chụp qua khung iframe không thành công:', err);
            }
        }

        // (3) Cùng đường: chụp bố cục đang có, báo rõ thay vì trả ảnh bố cục hỏng.
        await waitMs(200);
        onStatus('Đang dựng ảnh…');
        var b3 = await buildReportBlob();
        return { blob: b3, forced: false, width: layoutViewportWidth(), via: 'inline' };
    }

    /** Toast ngắn ở đáy màn hình (không phải modal — tự tắt, không cần bấm gì). */
    function captureToast(text, isError) {
        var el = document.createElement('div');
        el.className = 'dd2-capture-toast' + (isError ? ' is-error' : '');
        el.textContent = text;
        document.body.appendChild(el);
        setTimeout(function () { el.classList.add('is-out'); }, 3200);
        setTimeout(function () { if (el.parentNode) el.parentNode.removeChild(el); }, 3700);
    }

    /** Mở modal xem trước — CHỈ dùng khi clipboard không dùng được, để không mất công chụp. */
    function captureFallbackToModal(blob, forced, reason) {
        openCaptureModal();
        showCaptureResult(blob, forced);
        if (reason) {
            var el = document.getElementById('dd2-capture-status');
            if (el) el.textContent = reason + ' Dùng nút bên dưới để gửi ảnh:';
        }
    }

    function wireCaptureButton() {
        var btn = document.getElementById('dd2-capture-btn');
        if (!btn) return;
        document.querySelectorAll('[data-dd2-capture-close]').forEach(function (el) { el.addEventListener('click', closeCaptureModal); });

        // KHÔNG để handler này là async: Safari/iOS huỷ quyền ghi clipboard nếu navigator.clipboard.write
        // được gọi SAU một lần await. Cách hợp lệ: gọi write ngay trong cử chỉ chạm và truyền PROMISE
        // của blob vào ClipboardItem (đặc tả cho phép), ảnh dựng xong lúc nào clipboard nhận lúc đó.
        btn.addEventListener('click', function () {
            if (typeof window.html2canvas !== 'function') {
                alert('Không nạp được html2canvas. Kiểm tra mạng rồi thử lại.');
                return;
            }
            if (btn.disabled) return;
            var canCopy = !!(navigator.clipboard && typeof window.ClipboardItem === 'function');

            // ----- DESKTOP: giữ nguyên luồng cũ (copy clipboard + alert) -----
            if (!needsDesktopCapture()) {
                if (!canCopy) { alert('Trình duyệt không hỗ trợ copy ảnh vào clipboard.'); return; }
                btn.disabled = true;
                buildReportBlob()
                    .then(function (blob) { return navigator.clipboard.write([new window.ClipboardItem({ 'image/png': blob })]); })
                    .then(function () { alert('Đã copy ảnh báo cáo vào clipboard.\nBấm Ctrl+V để dán vào ứng dụng bạn muốn.'); })
                    .catch(function (err) {
                        console.error('Capture report error:', err);
                        alert('Không thể chụp báo cáo: ' + (err && err.message ? err.message : err));
                    })
                    .then(function () { btn.disabled = false; });
                return;
            }

            // ----- MOBILE: chụp theo khung desktop rồi copy THẲNG vào clipboard, KHÔNG mở modal -----
            btn.disabled = true;
            var overlay = autoOverlay('Đang chụp báo cáo…');
            // autoOverlay() dựng 3 con: [0] vòng xoay, [1] dòng chữ, [2] <style> -> phải lấy children[1],
            // lastElementChild sẽ trúng thẻ <style>.
            var setOverlayText = function (t) {
                var box = overlay.children[1];
                if (box) box.textContent = t;
            };
            var result = null;
            var shot = captureDesktopFramedBlob(setOverlayText).then(function (r) { result = r; return r.blob; });
            var finish = function () {
                if (overlay && overlay.parentNode) overlay.parentNode.removeChild(overlay);
                btn.disabled = false;
            };
            var failed = function (err) {
                console.error('Capture report error:', err);
                finish();
                captureToast('Không chụp được: ' + (err && err.message ? err.message : err), true);
            };

            // clipboard.write / new ClipboardItem có thể NÉM LỖI ĐỒNG BỘ (vd tài liệu không có
            // focus) chứ không phải trả promise bị reject -> phải bọc try/catch, nếu không overlay
            // sẽ treo và không nhánh nào chạy tiếp.
            var writeToClipboard = function () {
                try {
                    return navigator.clipboard.write([new window.ClipboardItem({ 'image/png': shot })]);
                } catch (e) {
                    return Promise.reject(e);
                }
            };

            if (canCopy) {
                writeToClipboard()
                    .then(function () {
                        finish();
                        // Ghi kèm bề rộng khung + cách đã dùng (viewport/iframe/inline) — manh mối
                        // để chẩn đoán từ xa trên máy thật của người dùng.
                        captureToast(result && result.forced === false
                            ? 'Đã copy ảnh nhưng KHÔNG dựng được bố cục desktop (khung ' + result.width
                                + 'px). Báo lại giúp tôi dòng này.'
                            : 'Đã copy ảnh báo cáo (khung ' + (result ? result.width : '?') + 'px, cách: '
                                + (result ? result.via : '?') + '). Sang ứng dụng khác rồi dán.');
                    })
                    .catch(function (err) {
                        // Ảnh vẫn có thể đã dựng xong — đừng bỏ phí, mở modal cho tải/chia sẻ.
                        console.warn('Clipboard write không thành công:', err);
                        shot.then(function (blob) {
                            finish();
                            captureFallbackToModal(blob, result && result.forced, 'Trình duyệt không cho copy ảnh vào bộ nhớ tạm.');
                        }).catch(failed);
                    });
            } else {
                shot.then(function (blob) {
                    finish();
                    captureFallbackToModal(blob, result && result.forced, 'Trình duyệt không hỗ trợ copy ảnh vào bộ nhớ tạm.');
                }).catch(failed);
            }
        });
    }

    /* -------- GỬI TỰ ĐỘNG: trang mở bằng ?auto_send=<id> (do auto_report_poller điều hướng tới) -------- */
    function autoReturn() {
        var url = '';
        try { url = sessionStorage.getItem('ar_return_url') || ''; } catch (e) {}
        try { sessionStorage.removeItem('ar_return_url'); } catch (e) {}
        location.replace(url || '?mod=home&controllers=index&action=index');
    }

    function autoOverlay(text) {
        var el = document.createElement('div');
        el.setAttribute('style',
            'position:fixed;inset:0;z-index:2147483000;background:rgba(15,23,42,.72);color:#fff;' +
            'display:flex;flex-direction:column;gap:14px;align-items:center;justify-content:center;' +
            'font-size:16px;font-family:inherit');
        el.innerHTML = '<div style="width:46px;height:46px;border:4px solid rgba(255,255,255,.3);' +
            'border-top-color:#16a34a;border-radius:50%;animation:ar-spin 1s linear infinite"></div>' +
            '<div>' + text + '</div>' +
            '<style>@keyframes ar-spin{to{transform:rotate(360deg)}}</style>';
        document.body.appendChild(el);
        return el;
    }

    async function runAutoSend(cfgId) {
        var params = new URLSearchParams(location.search);
        var settle = parseInt(params.get('settle'), 10);
        if (!settle || settle < 1500) settle = 4000;
        var base = (window.REPORT_CFG && window.REPORT_CFG.baseUrl) || '?mod=report&controllers=report&action=';
        var overlay = autoOverlay('Đang chụp & gửi báo cáo tự động…');
        try {
            // Chờ trang tải xong + biểu đồ/ảnh ổn định trước khi chụp.
            if (document.readyState !== 'complete') {
                await new Promise(function (r) { window.addEventListener('load', r, { once: true }); });
            }
            await new Promise(function (r) { setTimeout(r, settle); });

            // Gửi tự động cũng phải ra ảnh bố cục desktop dù máy đang mở là phone.
            var blob;
            if (needsDesktopCapture()) {
                var restoreVp = forceDesktopLayout();
                try {
                    if (await waitDesktopViewport()) {
                        await waitMs(400);
                        await resizeChartsForCapture();
                        blob = await buildReportBlob(CAPTURE_DESKTOP_H, CAPTURE_DESKTOP_W);
                    } else {
                        restoreVp();
                        restoreVp = function () {};
                        await waitMs(300);
                        blob = await buildReportBlob();
                    }
                } finally {
                    restoreVp();
                }
            } else {
                blob = await buildReportBlob();
            }
            var fd = new FormData();
            fd.append('config_id', String(cfgId));
            fd.append('image', blob, 'bao-cao.png');
            var res = await fetch(base + 'auto_report_receive', {
                method: 'POST', body: fd, credentials: 'same-origin'
            }).then(function (r) { return r.json(); });
            if (!res || !res.ok) { console.error('Auto-send failed:', res && res.message); }
        } catch (err) {
            console.error('Auto-send error:', err);
            // Lỗi (mất mạng/html2canvas...) → không gửi được; quét-lỡ phía server sẽ báo admin.
        } finally {
            if (overlay && overlay.parentNode) overlay.parentNode.removeChild(overlay);
            autoReturn();
        }
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }
})();
