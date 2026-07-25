/* =====================================================================
 * Trang Lịch phóng to (full page) — ?mod=home&controllers=index&action=calendar_full
 * Độc lập với widget lịch mini trong header (app_shell.js) — dùng chung API evcal*.
 * ===================================================================== */
(function () {
    var grid       = document.getElementById('calfull-grid');
    var titleEl    = document.getElementById('calfull-title');
    var prevBtn    = document.getElementById('calfull-prev');
    var nextBtn    = document.getElementById('calfull-next');
    var todayBtn   = document.getElementById('calfull-today-btn');
    var viewToggle = document.getElementById('calfull-view-toggle');
    var monthViewEl= document.getElementById('calfull-month-view');
    var yearViewEl = document.getElementById('calfull-year-view');
    if (!grid) return;

    var API = '?mod=home&controllers=index&action=';
    var MONTH_WORD = ['Một', 'Hai', 'Ba', 'Tư', 'Năm', 'Sáu', 'Bảy', 'Tám', 'Chín', 'Mười', 'Mười Một', 'Mười Hai'];
    var WEEKDAY_FULL = ['Chủ Nhật', 'Thứ Hai', 'Thứ Ba', 'Thứ Tư', 'Thứ Năm', 'Thứ Sáu', 'Thứ Bảy'];
    var VISIBLE = 3;

    var now = new Date();
    var state = { year: now.getFullYear(), month: now.getMonth(), view: 'month', events: {}, yearCounts: {} };
    var editingTimeId = 0;
    var openAddDate = null;
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

    /* Lưới tháng (Thứ 2 đầu tuần, 5/6 hàng tùy tháng — giống Outlook). */
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

    function chipHtml(it) {
        var isAllDay = !it.event_time;
        var tm = isAllDay ? '·' : esc(it.event_time);
        return '<div class="calfull-chip' + (isAllDay ? ' is-allday' : '') + (it.done ? ' is-done' : '') + '" draggable="true" data-id="' + it.id + '" data-date="' + it.event_date + '">'
            + '<span class="tm" data-time="' + esc(it.event_time || '') + '" title="Bấm để đổi giờ nhắc">' + tm + '</span>'
            + '<span class="tx" title="Bấm để sửa nội dung">' + esc(it.content) + '</span>'
            + '<button type="button" class="calfull-chip-repeat' + (it.has_recur ? ' is-recur' : '') + '" title="' + (it.has_recur ? 'Đã thiết lập lặp lại — bấm để sửa' : 'Thiết lập lặp lại') + '"><i class="fa-solid fa-repeat"></i></button>'
            + '<button type="button" class="calfull-chip-del" title="Xóa sự kiện"><i class="fa-solid fa-xmark"></i></button>'
            + '</div>';
    }

    /* Gợi ý giờ khi thêm sự kiện mới trong 1 ngày: giờ sự kiện gần nhất (có giờ) + 15 phút;
       chưa có sự kiện nào trong ngày đó thì mặc định 09:00. */
    function suggestTime(dateStr) {
        var evs = (state.events[dateStr] || []).filter(function (it) { return it.event_time; });
        if (!evs.length) return '09:00';
        var last = evs[evs.length - 1];
        var parts = last.event_time.split(':');
        var mins = (parseInt(parts[0], 10) * 60 + parseInt(parts[1], 10) + 15) % 1440;
        return pad2(Math.floor(mins / 60)) + ':' + pad2(mins % 60);
    }

    function dayHtml(c, td) {
        var evs = state.events[c.date] || [];
        var shown = evs.slice(0, VISIBLE);
        var more = evs.length > VISIBLE
            ? '<div class="calfull-chip calfull-more" data-date="' + c.date + '" title="Xem tất cả ' + evs.length + ' sự kiện">...</div>' : '';
        var cls = 'calfull-day' + (c.otherMonth ? ' is-other' : '') + (c.date === td ? ' is-today' : '') + (c.date === openAddDate ? ' is-adding' : '');
        return '<div class="' + cls + '" data-date="' + c.date + '">'
            + '<div class="calfull-day-head">'
            + '<span class="calfull-daynum">' + c.d + '</span>'
            + '<button type="button" class="calfull-add-btn' + (c.date === openAddDate ? ' is-active' : '') + '" data-date="' + c.date + '" title="Thêm sự kiện / lời nhắc"><i class="fa-solid fa-plus"></i></button>'
            + '</div>'
            + '<div class="calfull-events">' + shown.map(chipHtml).join('') + more + '</div>'
            + '<div class="calfull-add-form">'
            + '<input type="text" class="calfull-add-input" placeholder="Nhập sự kiện / lời nhắc..." maxlength="500">'
            + '<div class="calfull-add-row" style="display:none">'
            + '<input type="time" class="calfull-add-time" value="' + (c.date === openAddDate ? suggestTime(c.date) : '') + '">'
            + '<button type="button" class="calfull-add-save">Lưu</button>'
            + '<button type="button" class="calfull-add-cancel" title="Hủy">&times;</button>'
            + '</div>'
            + '</div>'
            + '</div>';
    }

    function render() {
        if (titleEl) titleEl.textContent = monthLabel(state.year, state.month);
        var g = buildGrid(state.year, state.month);
        var td = todayStr();
        grid.innerHTML = g.cells.map(function (c) { return dayHtml(c, td); }).join('');
        if (openAddDate) {
            var cell = grid.querySelector('.calfull-day[data-date="' + openAddDate + '"] .calfull-add-input');
            if (cell) cell.focus();
        }
        return g;
    }

    function load() {
        var g = render();
        fetch(API + 'evcalRange&from=' + g.from + '&to=' + g.to, { credentials: 'same-origin' })
            .then(function (r) { return r.json(); })
            .then(function (res) {
                if (!res || !res.ok) return;
                var map = {};
                (res.data || []).forEach(function (it) { (map[it.event_date] = map[it.event_date] || []).push(it); });
                state.events = map;
                render();
                if (dayModalDate) renderDayModal();
            }).catch(function () {});
    }

    /* ---------- Xem "Theo năm": 12 card tháng, 6 card/dòng ---------- */
    function yearMonthCardHtml(y, m, td) {
        var g = buildGrid(y, m);
        var cells = g.cells.map(function (c) {
            var hasEv = (state.yearCounts[c.date] || 0) > 0;
            var cls = 'calfull-ymini-day' + (c.otherMonth ? ' is-other' : '') + (c.date === td ? ' is-today' : '');
            return '<span class="' + cls + '"><span class="num">' + c.d + '</span>' + (hasEv ? '<i class="dot"></i>' : '') + '</span>';
        }).join('');
        return '<div class="calfull-year-card" data-month="' + m + '">'
            + '<div class="calfull-year-card-title">Tháng ' + (m + 1) + '</div>'
            + '<div class="calfull-ymini-grid">' + cells + '</div>'
            + '</div>';
    }
    function renderYear() {
        if (titleEl) titleEl.textContent = 'Năm ' + state.year;
        var td = todayStr();
        var html = '';
        for (var m = 0; m < 12; m++) html += yearMonthCardHtml(state.year, m, td);
        yearViewEl.innerHTML = html;
    }
    function loadYear() {
        renderYear();
        var from = state.year + '-01-01', to = state.year + '-12-31';
        fetch(API + 'evcalRange&from=' + from + '&to=' + to, { credentials: 'same-origin' })
            .then(function (r) { return r.json(); })
            .then(function (res) {
                if (!res || !res.ok) return;
                var counts = {};
                (res.data || []).forEach(function (it) { counts[it.event_date] = (counts[it.event_date] || 0) + 1; });
                state.yearCounts = counts;
                renderYear();
            }).catch(function () {});
    }
    if (yearViewEl) yearViewEl.addEventListener('click', function (e) {
        var card = e.target.closest('.calfull-year-card');
        if (!card) return;
        state.month = parseInt(card.getAttribute('data-month'), 10);
        setView('month');
        load();
    });

    /* ---------- Chuyển đổi Theo tháng / Theo năm ---------- */
    function setView(view) {
        state.view = view;
        if (viewToggle) viewToggle.querySelectorAll('.calfull-view-btn').forEach(function (b) {
            b.classList.toggle('is-active', b.getAttribute('data-view') === view);
        });
        if (monthViewEl) monthViewEl.style.display = view === 'month' ? '' : 'none';
        if (yearViewEl) yearViewEl.style.display = view === 'year' ? '' : 'none';
    }
    if (viewToggle) viewToggle.addEventListener('click', function (e) {
        var btn = e.target.closest('.calfull-view-btn');
        if (!btn) return;
        var v = btn.getAttribute('data-view');
        if (v === state.view) return;
        setView(v);
        if (v === 'year') loadYear(); else load();
    });

    if (prevBtn) prevBtn.addEventListener('click', function () {
        if (state.view === 'year') { state.year--; loadYear(); return; }
        state.month--; if (state.month < 0) { state.month = 11; state.year--; } openAddDate = null; load();
    });
    if (nextBtn) nextBtn.addEventListener('click', function () {
        if (state.view === 'year') { state.year++; loadYear(); return; }
        state.month++; if (state.month > 11) { state.month = 0; state.year++; } openAddDate = null; load();
    });
    if (todayBtn) todayBtn.addEventListener('click', function () {
        var t = new Date(); state.year = t.getFullYear(); state.month = t.getMonth(); openAddDate = null;
        if (state.view === 'year') loadYear(); else load();
    });

    /* ---------- Thêm sự kiện trực tiếp trong ô ngày ---------- */
    grid.addEventListener('click', function (e) {
        var addBtn = e.target.closest('.calfull-add-btn');
        if (addBtn) {
            var d = addBtn.getAttribute('data-date');
            openAddDate = (openAddDate === d) ? null : d;
            render();
            return;
        }
        var cancelBtn = e.target.closest('.calfull-add-cancel');
        if (cancelBtn) { openAddDate = null; render(); return; }

        var saveBtn = e.target.closest('.calfull-add-save');
        if (saveBtn) {
            var dayEl = saveBtn.closest('.calfull-day');
            var inp   = dayEl.querySelector('.calfull-add-input');
            var timeInp = dayEl.querySelector('.calfull-add-time');
            var content = inp.value.trim();
            if (!content) return;
            post('evcalCreate', { date: dayEl.getAttribute('data-date'), time: timeInp.value || '', content: content }).then(function (res) {
                if (!res || !res.ok) return;
                openAddDate = null;
                load();
            }).catch(function () {});
            return;
        }

        var more = e.target.closest('.calfull-more');
        if (more) { openDayModal(more.getAttribute('data-date')); return; }

        chipAreaClick(e);
    });

    grid.addEventListener('input', function (e) {
        if (!e.target.classList.contains('calfull-add-input')) return;
        var row = e.target.closest('.calfull-add-form').querySelector('.calfull-add-row');
        row.style.display = e.target.value.trim() !== '' ? 'flex' : 'none';
    });
    grid.addEventListener('keydown', function (e) {
        if (!e.target.classList.contains('calfull-add-input')) return;
        if (e.key === 'Enter') {
            e.preventDefault();
            var btn = e.target.closest('.calfull-day').querySelector('.calfull-add-save');
            if (btn) btn.click();
        } else if (e.key === 'Escape') {
            openAddDate = null; render();
        }
    });

    /* ---------- Sửa nội dung 1 sự kiện (inline) ---------- */
    function startEditContent(chip) {
        var id = chip.getAttribute('data-id');
        var textEl = chip.querySelector('.tx');
        if (!textEl || chip.querySelector('.tx-edit')) return;
        var cur = textEl.textContent;
        var inp = document.createElement('input');
        inp.type = 'text'; inp.className = 'tx-edit'; inp.value = cur; inp.maxLength = 500;
        textEl.style.display = 'none';
        textEl.parentNode.insertBefore(inp, textEl);
        inp.focus(); inp.select();
        function finish(save) {
            var v = inp.value.trim();
            inp.remove();
            textEl.style.display = '';
            if (save && v && v !== cur) {
                textEl.textContent = v;
                post('evcalUpdate', { id: id, content: v }).catch(function () {});
            }
        }
        inp.addEventListener('keydown', function (ev) {
            if (ev.key === 'Enter') { ev.preventDefault(); finish(true); }
            else if (ev.key === 'Escape') { finish(false); }
        });
        inp.addEventListener('blur', function () { finish(true); });
        inp.addEventListener('click', function (ev) { ev.stopPropagation(); });
    }

    /* ---------- Thao tác trên 1 chip (giờ/nội dung/lặp/xóa) — dùng chung cho lưới tháng
       VÀ modal xem đầy đủ ngày (khi 1 ngày > 3 sự kiện). ---------- */
    function chipAreaClick(e) {
        var delBtn = e.target.closest('.calfull-chip-del');
        if (delBtn) {
            var chipD = delBtn.closest('.calfull-chip');
            post('evcalDelete', { id: chipD.getAttribute('data-id') }).then(function (res) {
                if (res && res.ok) {
                    load();
                    // Đồng bộ real-time số badge "hôm nay" trên mini lịch (app-cal) ở sidebar/header.
                    if (typeof window.appCalSetBadge === 'function') window.appCalSetBadge(res.today);
                }
            }).catch(function () {});
            return true;
        }
        var tm = e.target.closest('.calfull-chip .tm');
        if (tm) { openTimeModal(tm.closest('.calfull-chip')); return true; }

        var tx = e.target.closest('.calfull-chip .tx');
        if (tx) { startEditContent(tx.closest('.calfull-chip')); return true; }

        var repeatBtn = e.target.closest('.calfull-chip-repeat');
        if (repeatBtn) { openRecurModal(repeatBtn.closest('.calfull-chip')); return true; }
        return false;
    }

    /* ---------- Modal xem đầy đủ sự kiện trong 1 ngày ---------- */
    var dayModal       = document.getElementById('calfull-day-modal');
    var dayModalTitle  = document.getElementById('calfull-day-modal-title');
    var dayModalEvents = document.getElementById('calfull-day-modal-events');
    function renderDayModal() {
        if (!dayModalDate) return;
        var evs = state.events[dayModalDate] || [];
        if (dayModalTitle) dayModalTitle.textContent = dayTitleLabel(dayModalDate);
        dayModalEvents.innerHTML = evs.map(chipHtml).join('');
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
    document.querySelectorAll('[data-calfull-day-close]').forEach(function (el) { el.addEventListener('click', closeDayModal); });
    if (dayModalEvents) dayModalEvents.addEventListener('click', chipAreaClick);

    /* ---------- Modal đổi giờ nhắc ---------- */
    var timeModal    = document.getElementById('calfull-time-modal');
    var timeModalIn  = document.getElementById('calfull-time-modal-input');
    var timeModalNo  = document.getElementById('calfull-time-modal-noclock');
    var timeModalMsg = document.getElementById('calfull-time-modal-msg');
    var timeModalSave= document.getElementById('calfull-time-modal-save');
    function openTimeModal(chip) {
        if (!timeModal) return;
        editingTimeId = chip.getAttribute('data-id');
        var tmEl = chip.querySelector('.tm');
        var isAllDay = chip.classList.contains('is-allday');
        timeModalIn.value = tmEl ? (tmEl.getAttribute('data-time') || '') : '';
        timeModalNo.checked = !!isAllDay;
        timeModalIn.disabled = timeModalNo.checked;
        if (timeModalMsg) timeModalMsg.textContent = '';
        timeModal.classList.add('is-open'); timeModal.setAttribute('aria-hidden', 'false');
    }
    function closeTimeModal() {
        if (!timeModal) return;
        timeModal.classList.remove('is-open'); timeModal.setAttribute('aria-hidden', 'true');
        editingTimeId = 0;
    }
    if (timeModalNo) timeModalNo.addEventListener('change', function () { timeModalIn.disabled = timeModalNo.checked; });
    document.querySelectorAll('[data-calfull-time-close]').forEach(function (el) { el.addEventListener('click', closeTimeModal); });
    if (timeModalSave) timeModalSave.addEventListener('click', function () {
        if (!editingTimeId) return;
        var val = timeModalNo.checked ? '' : timeModalIn.value;
        if (!timeModalNo.checked && !val) { timeModalMsg.textContent = 'Chọn giờ hoặc tích "Không nhắc giờ".'; return; }
        post('evcalUpdate', { id: editingTimeId, time: val }).then(function (res) {
            if (!res || !res.ok) { timeModalMsg.textContent = 'Không lưu được.'; return; }
            closeTimeModal(); load();
        }).catch(function () { timeModalMsg.textContent = 'Lỗi kết nối.'; });
    });

    /* ---------- Modal thiết lập lặp lại (hằng ngày/tuần/tháng/năm) ---------- */
    var recurModal        = document.getElementById('calfull-recur-modal');
    var recurTabs         = document.getElementById('calfull-recur-tabs');
    var recurMsg          = document.getElementById('calfull-recur-msg');
    var recurSaveBtn      = document.getElementById('calfull-recur-save');
    var recurClearBtn     = document.getElementById('calfull-recur-clear');
    var recurDailyN       = document.getElementById('calfull-recur-daily-n');
    var recurWeeklyN      = document.getElementById('calfull-recur-weekly-n');
    var recurWeekdaysWrap = document.getElementById('calfull-recur-weekdays');
    var recurMonthlyN     = document.getElementById('calfull-recur-monthly-n');
    var recurMonthlyDay   = document.getElementById('calfull-recur-monthly-day');
    var recurYearlyN      = document.getElementById('calfull-recur-yearly-n');
    var recurYearlyDay    = document.getElementById('calfull-recur-yearly-day');
    var recurYearlyMonth  = document.getElementById('calfull-recur-yearly-month');
    var recurEditId = 0;
    var recurFreq = 'daily';

    function setRecurTab(freq) {
        recurFreq = freq;
        recurTabs.querySelectorAll('.calfull-recur-tab').forEach(function (t) {
            t.classList.toggle('is-active', t.getAttribute('data-freq') === freq);
        });
        recurModal.querySelectorAll('.calfull-recur-panel').forEach(function (p) {
            p.style.display = (p.getAttribute('data-panel') === freq) ? '' : 'none';
        });
    }
    if (recurTabs) recurTabs.addEventListener('click', function (e) {
        var tab = e.target.closest('.calfull-recur-tab');
        if (tab) setRecurTab(tab.getAttribute('data-freq'));
    });
    function updateDailyModeUI() {
        var checked = recurModal.querySelector('input[name="calfull-daily-mode"]:checked');
        recurDailyN.disabled = !checked || checked.value !== 'custom';
    }
    if (recurModal) recurModal.querySelectorAll('input[name="calfull-daily-mode"]').forEach(function (r) {
        r.addEventListener('change', updateDailyModeUI);
    });

    /** Điền lại các ô theo cấu hình lặp đã lưu (khi mở lại sự kiện đã có lặp). */
    function applyRecurRule(rule) {
        recurClearBtn.style.display = '';
        setRecurTab(rule.freq);
        if (rule.freq === 'daily') {
            var interval = rule.interval || 1;
            var mode = interval > 1 ? 'custom' : 'every';
            recurModal.querySelector('input[name="calfull-daily-mode"][value="' + mode + '"]').checked = true;
            recurDailyN.value = interval > 1 ? interval : 2;
            updateDailyModeUI();
        } else if (rule.freq === 'weekly') {
            recurWeeklyN.value = rule.interval || 1;
            var wd = rule.weekdays || [];
            recurWeekdaysWrap.querySelectorAll('input[type="checkbox"]').forEach(function (cb) {
                cb.checked = wd.indexOf(parseInt(cb.value, 10)) !== -1;
            });
        } else if (rule.freq === 'monthly') {
            recurMonthlyN.value = rule.interval || 1;
            recurMonthlyDay.value = rule.month_day || 1;
        } else if (rule.freq === 'yearly') {
            recurYearlyN.value = rule.interval || 1;
            recurYearlyDay.value = rule.year_day || 1;
            recurYearlyMonth.value = rule.year_month || 1;
        }
    }

    function openRecurModal(chip) {
        if (!recurModal) return;
        recurEditId = chip.getAttribute('data-id');
        var dateStr = chip.getAttribute('data-date');
        var d   = new Date(dateStr + 'T00:00:00');
        var dow = ((d.getDay() + 6) % 7) + 1; // 1=Thứ 2 .. 7=CN
        var dom = d.getDate();
        var mon = d.getMonth() + 1;

        /* Mặc định gợi ý theo ngày của sự kiện đang thiết lập. */
        setRecurTab('daily');
        recurModal.querySelector('input[name="calfull-daily-mode"][value="every"]').checked = true;
        recurDailyN.value = 2; recurDailyN.disabled = true;
        recurWeeklyN.value = 1;
        recurWeekdaysWrap.querySelectorAll('input[type="checkbox"]').forEach(function (cb) {
            cb.checked = parseInt(cb.value, 10) === dow;
        });
        recurMonthlyN.value = 1; recurMonthlyDay.value = dom;
        recurYearlyN.value = 1; recurYearlyDay.value = dom; recurYearlyMonth.value = mon;
        if (recurMsg) recurMsg.textContent = '';
        recurClearBtn.style.display = 'none';

        post('evcalRecurGet', { id: recurEditId }).then(function (res) {
            if (res && res.ok && res.rule) applyRecurRule(res.rule);
        }).catch(function () {});

        recurModal.classList.add('is-open'); recurModal.setAttribute('aria-hidden', 'false');
    }
    function closeRecurModal() {
        if (!recurModal) return;
        recurModal.classList.remove('is-open'); recurModal.setAttribute('aria-hidden', 'true');
        recurEditId = 0;
    }
    document.querySelectorAll('[data-calfull-recur-close]').forEach(function (el) { el.addEventListener('click', closeRecurModal); });

    if (recurSaveBtn) recurSaveBtn.addEventListener('click', function () {
        if (!recurEditId) return;
        var params = { id: recurEditId, freq: recurFreq };
        if (recurFreq === 'daily') {
            var mode = recurModal.querySelector('input[name="calfull-daily-mode"]:checked');
            params.interval = (mode && mode.value === 'custom') ? (parseInt(recurDailyN.value, 10) || 2) : 1;
        } else if (recurFreq === 'weekly') {
            var days = [];
            recurWeekdaysWrap.querySelectorAll('input[type="checkbox"]:checked').forEach(function (cb) { days.push(cb.value); });
            if (!days.length) { if (recurMsg) recurMsg.textContent = 'Chọn ít nhất 1 ngày trong tuần.'; return; }
            params.interval = parseInt(recurWeeklyN.value, 10) || 1;
            params.weekdays = days.join(',');
        } else if (recurFreq === 'monthly') {
            params.interval  = parseInt(recurMonthlyN.value, 10) || 1;
            params.month_day = parseInt(recurMonthlyDay.value, 10) || 1;
        } else if (recurFreq === 'yearly') {
            params.interval   = parseInt(recurYearlyN.value, 10) || 1;
            params.year_day   = parseInt(recurYearlyDay.value, 10) || 1;
            params.year_month = parseInt(recurYearlyMonth.value, 10) || 1;
        }
        post('evcalRecur', params).then(function (res) {
            if (!res || !res.ok) { if (recurMsg) recurMsg.textContent = (res && res.message) || 'Không lưu được.'; return; }
            closeRecurModal();
            load();
        }).catch(function () { if (recurMsg) recurMsg.textContent = 'Lỗi kết nối.'; });
    });
    if (recurClearBtn) recurClearBtn.addEventListener('click', function () {
        if (!recurEditId) return;
        post('evcalRecur', { id: recurEditId, clear: 1 }).then(function (res) {
            if (res && res.ok) { closeRecurModal(); load(); }
        }).catch(function () {});
    });

    /* ---------- Kéo-thả sự kiện qua ngày khác ---------- */
    var dragId = null, dragFromDate = null;
    grid.addEventListener('dragstart', function (e) {
        var chip = e.target.closest('.calfull-chip');
        if (!chip) return;
        dragId = chip.getAttribute('data-id');
        dragFromDate = chip.getAttribute('data-date');
        chip.classList.add('is-dragging');
        e.dataTransfer.effectAllowed = 'move';
        try { e.dataTransfer.setData('text/plain', dragId); } catch (err) {}
    });
    grid.addEventListener('dragend', function (e) {
        var chip = e.target.closest('.calfull-chip');
        if (chip) chip.classList.remove('is-dragging');
        grid.querySelectorAll('.drag-over').forEach(function (c) { c.classList.remove('drag-over'); });
    });
    grid.addEventListener('dragover', function (e) {
        var cell = e.target.closest('.calfull-day');
        if (!cell || !dragId) return;
        e.preventDefault();
        e.dataTransfer.dropEffect = 'move';
        grid.querySelectorAll('.drag-over').forEach(function (c) { if (c !== cell) c.classList.remove('drag-over'); });
        cell.classList.add('drag-over');
    });
    grid.addEventListener('drop', function (e) {
        var cell = e.target.closest('.calfull-day');
        if (!cell || !dragId) return;
        e.preventDefault();
        var newDate = cell.getAttribute('data-date');
        cell.classList.remove('drag-over');
        if (newDate === dragFromDate) { dragId = null; return; }
        post('evcalMove', { id: dragId, date: newDate }).then(function (res) {
            dragId = null;
            if (res && res.ok) {
                load();
                // Đồng bộ real-time số badge "hôm nay" trên mini lịch (app-cal) ở sidebar/header
                // — bao gồm cả tình huống kéo từ ngày khác vào hôm nay (badge tăng theo).
                if (typeof window.appCalSetBadge === 'function') window.appCalSetBadge(res.today);
            }
        }).catch(function () { dragId = null; });
    });

    /* Cho mini dropdown lịch trên header (app_shell.js) gọi để đồng bộ real-time
       khi 2 widget cùng hiển thị trên trang này (vd xóa sự kiện ở mini dropdown). */
    window.calfullReload = function () {
        if (state.view === 'year') loadYear(); else load();
    };

    load();
})();
