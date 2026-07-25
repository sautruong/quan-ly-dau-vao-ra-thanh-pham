/* =====================================================================
 * Sự kiện nhà máy (báo cáo dạng lịch, chỉ đọc)
 * ?mod=report&controllers=report&action=factory_events
 * Dựa trên khung lưới tháng của calendar_full.js, bỏ mọi thao tác
 * thêm/sửa/kéo-thả vì đây là trang báo cáo chỉ xem.
 * ===================================================================== */
(function () {
    var grid = document.getElementById('fev-grid');
    if (!grid) return;

    var titleEl  = document.getElementById('fev-title');
    var prevBtn  = document.getElementById('fev-prev');
    var nextBtn  = document.getElementById('fev-next');
    var todayBtn = document.getElementById('fev-today-btn');

    var API = '?mod=report&controllers=report&action=';
    var MONTH_WORD = ['Một', 'Hai', 'Ba', 'Tư', 'Năm', 'Sáu', 'Bảy', 'Tám', 'Chín', 'Mười', 'Mười Một', 'Mười Hai'];
    var WEEKDAY_FULL = ['Chủ Nhật', 'Thứ Hai', 'Thứ Ba', 'Thứ Tư', 'Thứ Năm', 'Thứ Sáu', 'Thứ Bảy'];
    var VISIBLE = 3;

    var now = new Date();
    var state = { year: now.getFullYear(), month: now.getMonth(), events: {} };
    var dayModalDate = null;

    function pad2(n) { return (n < 10 ? '0' : '') + n; }
    function fmtDate(y, m, d) { return y + '-' + pad2(m + 1) + '-' + pad2(d); }
    function todayStr() { var t = new Date(); return fmtDate(t.getFullYear(), t.getMonth(), t.getDate()); }
    function esc(s) {
        return String(s == null ? '' : s)
            .replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;').replace(/'/g, '&#39;');
    }
    function monthLabel(y, m) { return 'Tháng ' + MONTH_WORD[m] + ' ' + y; }
    function dayTitleLabel(dateStr) {
        var d = new Date(dateStr + 'T00:00:00');
        return WEEKDAY_FULL[d.getDay()] + ', ' + pad2(d.getDate()) + '/' + pad2(d.getMonth() + 1) + '/' + d.getFullYear();
    }
    function post(action, params) {
        var body = new URLSearchParams();
        if (params) Object.keys(params).forEach(function (k) { body.append(k, params[k]); });
        return fetch(API + action, {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8' },
            body: body.toString(), credentials: 'same-origin'
        }).then(function (r) { return r.json(); });
    }

    /* ---------------- Lưới tháng (giống calendar_full) ---------------- */
    function buildGrid(y, m) {
        var first = new Date(y, m, 1);
        var firstIdx = (first.getDay() + 6) % 7;
        var dim = new Date(y, m + 1, 0).getDate();
        var totalCells = Math.ceil((firstIdx + dim) / 7) * 7;
        var start = new Date(y, m, 1 - firstIdx);
        var cells = [];
        for (var i = 0; i < totalCells; i++) {
            var d = new Date(start.getFullYear(), start.getMonth(), start.getDate() + i);
            cells.push({ d: d.getDate(), date: fmtDate(d.getFullYear(), d.getMonth(), d.getDate()), otherMonth: d.getMonth() !== m });
        }
        return { cells: cells, from: cells[0].date, to: cells[cells.length - 1].date };
    }

    function chipInner(it) {
        return it.text_html ? it.text_html : esc(it.text);
    }
    function chipHtml(it, idx) {
        var bgStyle = it.chip_bg ? ' style="background:' + esc(it.chip_bg) + '"' : '';
        return '<div class="fev-chip" data-idx="' + idx + '" data-date="' + it.date + '" title="Bấm để xem chi tiết"' + bgStyle + '>'
            + '<span class="fev-bullet" style="background:' + esc(it.dot || '#888') + '"></span>'
            + '<span class="fev-tx">' + chipInner(it) + '</span>'
            + '</div>';
    }

    function dayHtml(c, td) {
        var evs = state.events[c.date] || [];
        var shown = evs.slice(0, VISIBLE);
        var more = evs.length > VISIBLE
            ? '<div class="fev-chip fev-more" data-date="' + c.date + '" title="Xem tất cả ' + evs.length + ' sự kiện">...</div>' : '';
        var cls = 'calfull-day' + (c.otherMonth ? ' is-other' : '') + (c.date === td ? ' is-today' : '');
        return '<div class="' + cls + '" data-date="' + c.date + '">'
            + '<div class="calfull-day-head"><span class="calfull-daynum">' + c.d + '</span></div>'
            + '<div class="calfull-events">' + shown.map(function (it, i) { return chipHtml(it, i); }).join('') + more + '</div>'
            + '</div>';
    }

    function render() {
        if (titleEl) titleEl.textContent = monthLabel(state.year, state.month);
        var g = buildGrid(state.year, state.month);
        var td = todayStr();
        grid.innerHTML = g.cells.map(function (c) { return dayHtml(c, td); }).join('');
        return g;
    }

    /* ---------------- Bộ lọc: 6 checkbox + combobox tìm-chọn nhiều ---------------- */
    var filterState = {}; // { type: [ {id, label, color}, ... ] }
    var filterOn = {};    // { type: bool }

    function currentFilters() {
        var out = {};
        Object.keys(filterOn).forEach(function (type) {
            if (filterOn[type] && (filterState[type] || []).length) {
                out[type] = filterState[type].map(function (it) { return it.id; });
            }
        });
        return out;
    }

    /* Combobox tìm-chọn nhiều: input debounce -> dropdown -> ArrowUp/Down/Enter/Tab/Escape. */
    function createMultiCombo(container, searchAction, onChange) {
        var selected = [];
        container.innerHTML =
            '<input type="text" class="fev-combo-input" placeholder="Tìm và chọn...">' +
            '<ul class="fev-combo-drop" style="display:none"></ul>' +
            '<div class="fev-combo-chips"></div>';
        var input = container.querySelector('.fev-combo-input');
        var drop  = container.querySelector('.fev-combo-drop');
        var chips = container.querySelector('.fev-combo-chips');
        var items = [];
        var activeIdx = -1;
        var t = null;

        function renderChips() {
            chips.innerHTML = selected.map(function (s, i) {
                var style = s.color ? ' style="color:' + esc(s.color) + '"' : '';
                return '<span class="fev-chip-tag"><span' + style + '>' + esc(s.label) + '</span>'
                    + '<button type="button" data-i="' + i + '" title="Bỏ chọn">&times;</button></span>';
            }).join('');
        }
        function isSelected(id) { return selected.some(function (s) { return s.id === id; }); }
        function toggle(item) {
            var i = selected.findIndex(function (s) { return s.id === item.id; });
            if (i === -1) selected.push(item); else selected.splice(i, 1);
            renderChips();
            onChange(selected.slice());
        }
        chips.addEventListener('click', function (e) {
            var btn = e.target.closest('button[data-i]');
            if (!btn) return;
            selected.splice(parseInt(btn.getAttribute('data-i'), 10), 1);
            renderChips();
            onChange(selected.slice());
        });

        function renderDrop() {
            if (!items.length) { drop.style.display = 'none'; drop.innerHTML = ''; return; }
            drop.innerHTML = items.map(function (it, i) {
                var cls = 'fev-combo-opt' + (i === activeIdx ? ' is-active' : '') + (isSelected(it.id) ? ' is-selected' : '');
                return '<li class="' + cls + '" data-i="' + i + '">' + (isSelected(it.id) ? '✓ ' : '') + esc(it.label) + '</li>';
            }).join('');
            drop.style.display = '';
        }
        function search(kw) {
            if (!kw) { items = []; activeIdx = -1; renderDrop(); return; }
            post(searchAction, { keyword: kw }).then(function (res) {
                items = (res && res.ok) ? (res.data || []) : [];
                activeIdx = items.length ? 0 : -1;
                renderDrop();
            }).catch(function () {});
        }
        input.addEventListener('input', function () {
            clearTimeout(t);
            var kw = input.value.trim();
            t = setTimeout(function () { search(kw); }, 250);
        });
        input.addEventListener('keydown', function (e) {
            if (e.key === 'ArrowDown') { e.preventDefault(); if (items.length) activeIdx = (activeIdx + 1) % items.length; renderDrop(); }
            else if (e.key === 'ArrowUp') { e.preventDefault(); if (items.length) activeIdx = (activeIdx - 1 + items.length) % items.length; renderDrop(); }
            else if (e.key === 'Enter' || e.key === 'Tab') {
                if (activeIdx >= 0 && items[activeIdx]) {
                    if (e.key === 'Enter') e.preventDefault();
                    toggle(items[activeIdx]);
                    input.value = ''; items = []; activeIdx = -1; renderDrop();
                }
            } else if (e.key === 'Escape') { items = []; renderDrop(); }
        });
        drop.addEventListener('click', function (e) {
            var li = e.target.closest('.fev-combo-opt');
            if (!li) return;
            var it = items[parseInt(li.getAttribute('data-i'), 10)];
            if (it) { toggle(it); input.value = ''; input.focus(); items = []; renderDrop(); }
        });
        document.addEventListener('click', function (e) {
            if (!container.contains(e.target)) { items = []; renderDrop(); }
        });

        return { getSelected: function () { return selected.slice(); } };
    }

    document.querySelectorAll('.fev-filter-row').forEach(function (row) {
        var type = row.getAttribute('data-type');
        var searchAction = 'fev_search_' + row.getAttribute('data-search');
        var check = row.querySelector('.fev-check');
        var comboEl = row.querySelector('.fev-combo');
        filterOn[type] = false;
        filterState[type] = [];

        var combo = createMultiCombo(comboEl, searchAction, function (selected) {
            filterState[type] = selected;
            load();
        });

        check.addEventListener('change', function () {
            filterOn[type] = check.checked;
            comboEl.style.display = check.checked ? '' : 'none';
            if (check.checked) {
                var inputEl = comboEl.querySelector('.fev-combo-input');
                if (inputEl) inputEl.focus();
            }
            load();
        });
    });

    /* ---------------- Tải sự kiện theo tháng đang xem ---------------- */
    function load() {
        var g = render();
        post('fev_range', { from: g.from, to: g.to, filters: JSON.stringify(currentFilters()) }).then(function (res) {
            if (!res || !res.ok) return;
            state.events = res.data || {};
            render();
            if (dayModalDate) renderDayModal();
        }).catch(function () {});
    }

    if (prevBtn) prevBtn.addEventListener('click', function () {
        state.month--; if (state.month < 0) { state.month = 11; state.year--; } load();
    });
    if (nextBtn) nextBtn.addEventListener('click', function () {
        state.month++; if (state.month > 11) { state.month = 0; state.year++; } load();
    });
    if (todayBtn) todayBtn.addEventListener('click', function () {
        var t = new Date(); state.year = t.getFullYear(); state.month = t.getMonth(); load();
    });

    /* ---------------- Modal xem đầy đủ 1 ngày ---------------- */
    var dayModal       = document.getElementById('fev-day-modal');
    var dayModalTitle  = document.getElementById('fev-day-modal-title');
    var dayModalEvents = document.getElementById('fev-day-modal-events');
    function renderDayModal() {
        if (!dayModalDate) return;
        var evs = state.events[dayModalDate] || [];
        if (dayModalTitle) dayModalTitle.textContent = dayTitleLabel(dayModalDate);
        dayModalEvents.innerHTML = evs.map(function (it, i) { return chipHtml(it, i); }).join('');
    }
    function openDayModal(dateStr) {
        if (!dayModal) return;
        dayModalDate = dateStr;
        renderDayModal();
        dayModal.classList.add('is-open'); dayModal.setAttribute('aria-hidden', 'false');
    }
    function closeDayModal() {
        if (!dayModal) return;
        dayModal.classList.remove('is-open'); dayModal.setAttribute('aria-hidden', 'true');
        dayModalDate = null;
    }
    document.querySelectorAll('[data-fev-day-close]').forEach(function (el) { el.addEventListener('click', closeDayModal); });

    /* ---------------- Modal thư viện ảnh ---------------- */
    var attModal = document.getElementById('fev-att-modal');
    var attGrid  = document.getElementById('fev-att-grid');
    function isImgUrl(u) { return /\.(jpg|jpeg|png|gif|webp)$/i.test(String(u || '')); }
    function openAttModal(images) {
        if (!attModal) return;
        if (!images.length) {
            attGrid.innerHTML = '<div class="fev-att-empty">Chưa có hình ảnh lưu cho sự kiện này.</div>';
        } else {
            attGrid.innerHTML = images.map(function (a) {
                var inner = isImgUrl(a.file_url) ? '<img src="' + esc(a.file_url) + '" alt="hóa đơn">' : '<div class="fev-att-pdf"><i class="fa-solid fa-file-pdf"></i></div>';
                return '<div class="fev-att-item" data-view="' + esc(a.file_url) + '">' + inner + '</div>';
            }).join('');
        }
        attModal.classList.add('is-open'); attModal.setAttribute('aria-hidden', 'false');
    }
    function closeAttModal() {
        if (!attModal) return;
        attModal.classList.remove('is-open'); attModal.setAttribute('aria-hidden', 'true');
    }
    document.querySelectorAll('[data-fev-att-close]').forEach(function (el) { el.addEventListener('click', closeAttModal); });
    if (attGrid) attGrid.addEventListener('click', function (e) {
        var item = e.target.closest('.fev-att-item[data-view]');
        if (item && window.InvoiceViewer) window.InvoiceViewer.open(item.getAttribute('data-view'));
    });

    var ATT_ENDPOINTS = {
        invoices_by_supplier_date: { action: 'fev_invoices_by_supplier_date', params: function (c) { return { supplier_id: c.supplier_id, date: c.date }; } },
        payment_attachments:       { action: 'fev_payment_attachments',       params: function (c) { return { payment_id: c.payment_id }; } },
        sales_invoices:            { action: 'fev_sales_invoices',           params: function (c) { return { sales_order_id: c.sales_order_id }; } },
        sales_export_invoices:     { action: 'fev_sales_export_invoices',    params: function (c) { return { sales_order_id: c.sales_order_id }; } },
        invoices_by_customer_date: { action: 'fev_invoices_by_customer_date', params: function (c) { return { customer_id: c.customer_id, date: c.date }; } }
    };
    function handleChipClick(item) {
        if (!item || !item.click || !item.click.kind) return;
        var ep = ATT_ENDPOINTS[item.click.kind];
        if (!ep) return;
        post(ep.action, ep.params(item.click)).then(function (res) {
            openAttModal((res && res.ok) ? (res.data || []) : []);
        }).catch(function () { openAttModal([]); });
    }

    /* Click chip trên lưới tháng lẫn trong modal ngày đều dùng chung logic tra cứu sự kiện theo ngày+index. */
    function onChipAreaClick(e) {
        var more = e.target.closest('.fev-more');
        if (more) { openDayModal(more.getAttribute('data-date')); return; }
        var chip = e.target.closest('.fev-chip[data-idx]');
        if (!chip) return;
        var date = chip.getAttribute('data-date');
        var idx  = parseInt(chip.getAttribute('data-idx'), 10);
        var evs  = state.events[date] || [];
        handleChipClick(evs[idx]);
    }
    grid.addEventListener('click', onChipAreaClick);
    dayModalEvents.addEventListener('click', onChipAreaClick);

    load();
})();
