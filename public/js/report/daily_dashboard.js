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
            return '<div class="dd2-export-row"' + style + '>' +
                '<div class="dd2-name-cell">' +
                '<button type="button" class="dd2-entity-link" data-customer-id="' + r.customer_id + '" data-date-iso="' + escapeHtml(r.date_iso) + '">' + escapeHtml(r.customer_label) + '</button>' +
                '<span class="dd2-date-chip">/ ' + escapeHtml(r.date_label) + '</span>' +
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

    /* ---------------- Modal: danh sách ĐẦY ĐỦ dùng chung (sidebar 7 icon + nút "..."/"Sales Order") ---------------- */
    var fullListModal = document.getElementById('dd2-full-list-modal');
    var flSearchInput = document.getElementById('dd2-full-list-search');
    var flFilterSelect = document.getElementById('dd2-full-list-filter');
    var flTotalsEl = document.getElementById('dd2-full-list-totals');
    var fullListAction = '', fullListParams = {}, fullListRenderer = null, fullListTotals = null;

    /** opts: { searchParam, searchPlaceholder, filterParam, totals: function(data){return htmlString} } — tất cả tùy chọn, bỏ trống thì ẩn. */
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
    function loadFullListPage(page) {
        var params = Object.assign({ page: page }, fullListParams);
        document.getElementById('dd2-full-list-body').innerHTML = '<p class="dd2-empty">Đang tải...</p>';
        postForm(fullListAction, params).then(function (res) {
            if (!res || !res.success) return;
            var data = res.data;
            document.getElementById('dd2-full-list-body').innerHTML = fullListRenderer(data.rows);
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
    function flExportsTotals(data) {
        var t = data.totals || {};
        return 'SL: <b>' + fmtNum(t.quantity) + '</b> &nbsp;·&nbsp; Khối lượng: <b>' + fmtNum(t.weight) + ' kg</b> &nbsp;·&nbsp; Doanh thu: <b>' + fmtMoney(t.value) + '</b>';
    }
    function flOutputTotals(data) {
        var t = data.totals || {};
        return 'Tổng SL: <b>' + fmtNum(t.quantity) + '</b> &nbsp;·&nbsp; Tổng giá vốn: <b>' + fmtMoney(t.cost) + '</b> &nbsp;·&nbsp; Tổng giá trị hàng hóa: <b>' + fmtMoney(t.value) + '</b>';
    }

    function wireSidebarIcons() {
        var map = {
            imports: ['Nhập kho', 'daily_dashboard_imports_full', rowsImports, { searchParam: 'keyword', searchPlaceholder: 'Tìm nhà cung cấp...', totals: flImportsTotals }],
            exports: ['Xuất kho', 'daily_dashboard_exports_full', rowsExports, { searchParam: 'keyword', searchPlaceholder: 'Tìm khách hàng...', totals: flExportsTotals }],
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
            openFullListModal('Xuất kho', 'daily_dashboard_exports_full', rowsExports, {}, { searchParam: 'keyword', searchPlaceholder: 'Tìm khách hàng...', totals: flExportsTotals });
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
    async function buildReportBlob() {
        if (typeof window.html2canvas !== 'function') {
            throw new Error('Không nạp được html2canvas (kiểm tra mạng).');
        }
        var contentEl = document.querySelector('.dd2-content');
        if (!contentEl) throw new Error('Không tìm thấy nội dung báo cáo.');

        var scale = 2;
        // Chụp riêng nội dung, tự vẽ nền/bo góc/đổ bóng ở bước ghép (html2canvas hay cắt box-shadow).
        var contentCanvas = await window.html2canvas(contentEl, {
            backgroundColor: '#ffffff', scale: scale, useCORS: true, logging: false,
            // Nút chụp giữ NGUYÊN (icon camera không mờ) trong ảnh — chỉ bỏ disabled trên bản clone.
            // Xem ghi chú cũ: KHÔNG đổi cấu trúc DOM nút này trong onclone (html2canvas render sai).
            onclone: function (clonedDoc) {
                var b = clonedDoc.getElementById('dd2-capture-btn');
                if (b) b.disabled = false;
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

    function wireCaptureButton() {
        var btn = document.getElementById('dd2-capture-btn');
        if (!btn) return;
        btn.addEventListener('click', async function () {
            if (typeof window.html2canvas !== 'function') {
                alert('Không nạp được html2canvas. Kiểm tra mạng rồi thử lại.');
                return;
            }
            if (!navigator.clipboard || typeof window.ClipboardItem !== 'function') {
                alert('Trình duyệt không hỗ trợ copy ảnh vào clipboard.');
                return;
            }
            // CHỈ dùng thuộc tính disabled (CSS :disabled có sẵn) làm dấu hiệu "đang xử lý".
            btn.disabled = true;
            try {
                var blob = await buildReportBlob();
                await navigator.clipboard.write([new window.ClipboardItem({ 'image/png': blob })]);
                alert('Đã copy ảnh báo cáo vào clipboard.\nBấm Ctrl+V để dán vào ứng dụng bạn muốn.');
            } catch (err) {
                console.error('Capture report error:', err);
                alert('Không thể chụp báo cáo: ' + (err && err.message ? err.message : err));
            } finally {
                btn.disabled = false;
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

            var blob = await buildReportBlob();
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
