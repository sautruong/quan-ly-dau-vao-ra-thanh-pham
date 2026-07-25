/* =====================================================================
 *  Nhãn siro mẫu (siro_label)
 * ===================================================================== */
(function () {
    'use strict';

    var CFG = window.SL_CONFIG || { baseUrl: '?mod=siro_label&controllers=siro_label&action=', texts: {} };

    /* ---------- DOM ---------- */
    var $ = function (id) { return document.getElementById(id); };
    var $name = $('sl-name');
    var $qty = $('sl-qty');
    var $btnReset = $('sl-btn-reset'), $btnMake = $('sl-btn-make'), $btnPrint = $('sl-btn-print');
    var $grid = $('sl-grid'), $gridEmpty = $('sl-grid-empty');
    var $tpl = $('sl-tpl-card');

    /* ---------- State ---------- */
    var fixedTexts = {
        company_line1: (CFG.texts && CFG.texts.company_line1) || 'CÔNG TY TNHH',
        address:       (CFG.texts && CFG.texts.address) || '',
        storage:       (CFG.texts && CFG.texts.storage) || 'Nơi khô ráo, thoáng mát',
        hotline:       (CFG.texts && CFG.texts.hotline) || '0777 044 777',
        volume:        (CFG.texts && CFG.texts.volume) || '100 ML',
        origin:        (CFG.texts && CFG.texts.origin) || 'Việt Nam'
    };

    /* ---------- Utils ---------- */
    function post(action, data) {
        var body = new URLSearchParams();
        for (var k in data) body.append(k, data[k]);
        return fetch(CFG.baseUrl + action, {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8' },
            body: body.toString()
        }).then(function (r) { return r.json(); });
    }
    function updateGridEmpty() {
        $gridEmpty.style.display = $grid.querySelectorAll('.sl-card').length ? 'none' : 'block';
    }

    /* ====================================================================
     *  LỊCH CHỌN NGÀY (tự chế, xanh lá) — dùng chung cho NSX và HSD.
     * ==================================================================== */
    var MONTH_NAMES = ['Tháng 1', 'Tháng 2', 'Tháng 3', 'Tháng 4', 'Tháng 5', 'Tháng 6', 'Tháng 7', 'Tháng 8', 'Tháng 9', 'Tháng 10', 'Tháng 11', 'Tháng 12'];
    var WEEKDAYS = ['T2', 'T3', 'T4', 'T5', 'T6', 'T7', 'CN'];

    function pad2(n) { return String(n).padStart(2, '0'); }
    function fmtDateDisplay(y, m, d) { return pad2(d) + '/' + pad2(m) + '/' + y; }
    function parseISODate(v) {
        var m = /^(\d{4})-(\d{2})-(\d{2})$/.exec(String(v || ''));
        return m ? { y: +m[1], m: +m[2], d: +m[3] } : null;
    }
    function daysInMonth(y, m) { return new Date(y, m, 0).getDate(); }
    function isLeap(y) { return (y % 4 === 0 && y % 100 !== 0) || y % 400 === 0; }
    function addOneYear(sel) {
        var y = sel.y + 1, m = sel.m, d = sel.d;
        if (m === 2 && d === 29 && !isLeap(y)) d = 28;
        return { y: y, m: m, d: d };
    }

    function makeDatePicker(prefix, onChange) {
        var $hidden = $(prefix);
        var $display = $(prefix + '-display');
        var $cal = $(prefix + '-cal');
        var calViewY, calViewM;

        function setSelectedDate(y, m, d, silent) {
            $hidden.value = y + '-' + pad2(m) + '-' + pad2(d);
            $display.textContent = fmtDateDisplay(y, m, d);
            if (!silent && typeof onChange === 'function') onChange({ y: y, m: m, d: d });
        }
        function renderCalendar() {
            var sel = parseISODate($hidden.value);
            var t = new Date();
            var startWeekday = (new Date(calViewY, calViewM - 1, 1).getDay() + 6) % 7;
            var total = daysInMonth(calViewY, calViewM);
            var html = '<div class="sl-cal-head">'
                + '<button type="button" class="sl-cal-nav" data-nav="-1"><i class="fa-solid fa-chevron-left"></i></button>'
                + '<span class="sl-cal-title">' + MONTH_NAMES[calViewM - 1] + ' ' + calViewY + '</span>'
                + '<button type="button" class="sl-cal-nav" data-nav="1"><i class="fa-solid fa-chevron-right"></i></button>'
                + '</div><div class="sl-cal-week">' + WEEKDAYS.map(function (w) { return '<span>' + w + '</span>'; }).join('') + '</div>'
                + '<div class="sl-cal-grid">';
            for (var i = 0; i < startWeekday; i++) html += '<span class="sl-cal-day empty"></span>';
            for (var d = 1; d <= total; d++) {
                var isToday = t.getFullYear() === calViewY && (t.getMonth() + 1) === calViewM && t.getDate() === d;
                var isSel = sel && sel.y === calViewY && sel.m === calViewM && sel.d === d;
                html += '<span class="sl-cal-day' + (isToday ? ' today' : '') + (isSel ? ' selected' : '') + '" data-d="' + d + '">' + d + '</span>';
            }
            html += '</div>';
            $cal.innerHTML = html;
        }
        function openCal() {
            var sel = parseISODate($hidden.value) || (function () { var n = new Date(); return { y: n.getFullYear(), m: n.getMonth() + 1, d: n.getDate() }; })();
            calViewY = sel.y; calViewM = sel.m;
            renderCalendar();
            $cal.style.display = 'block';
        }
        function closeCal() { $cal.style.display = 'none'; }
        $display.addEventListener('click', function (e) {
            e.stopPropagation();
            if ($cal.style.display === 'block') closeCal(); else openCal();
        });
        $cal.addEventListener('click', function (e) {
            var nav = e.target.closest('[data-nav]');
            if (nav) {
                var dir = +nav.dataset.nav;
                calViewM += dir;
                if (calViewM < 1) { calViewM = 12; calViewY--; }
                else if (calViewM > 12) { calViewM = 1; calViewY++; }
                renderCalendar();
                return;
            }
            var day = e.target.closest('.sl-cal-day:not(.empty)');
            if (day) { setSelectedDate(calViewY, calViewM, +day.dataset.d); closeCal(); }
        });
        document.addEventListener('click', function (e) { if (!e.target.closest('.sl-date-picker')) closeCal(); });

        return {
            set: function (y, m, d, silent) { setSelectedDate(y, m, d, silent); },
            get: function () { return parseISODate($hidden.value); },
            initDisplay: function () {
                var init = parseISODate($hidden.value);
                if (init) $display.textContent = fmtDateDisplay(init.y, init.m, init.d);
            }
        };
    }

    var nsxPicker = makeDatePicker('sl-nsx', function (sel) {
        var hsd = addOneYear(sel);
        hsdPicker.set(hsd.y, hsd.m, hsd.d, true);
    });
    var hsdPicker = makeDatePicker('sl-hsd', null);

    nsxPicker.initDisplay();
    (function initHsdDefault() {
        var nsx = nsxPicker.get() || (function () { var n = new Date(); return { y: n.getFullYear(), m: n.getMonth() + 1, d: n.getDate() }; })();
        var hsd = addOneYear(nsx);
        hsdPicker.set(hsd.y, hsd.m, hsd.d, true);
    })();

    /* ====================================================================
     *  TẠO TEM
     * ==================================================================== */
    function addCard(name) {
        var wrap = $tpl.content.firstElementChild.cloneNode(true);
        wrap.dataset.product = name;
        var node = wrap.querySelector('.sl-card');
        node.querySelector('.sl-card-name').textContent = name;
        var nsx = nsxPicker.get(), hsd = hsdPicker.get();
        node.querySelector('.sl-card-nsx').textContent = nsx ? fmtDateDisplay(nsx.y, nsx.m, nsx.d) : '';
        node.querySelector('.sl-card-hsd').textContent = hsd ? fmtDateDisplay(hsd.y, hsd.m, hsd.d) : '';
        Array.prototype.forEach.call(node.querySelectorAll('.sl-fixed-editable'), function (el) {
            var field = el.dataset.field;
            el.querySelector('.sl-fixed-text').textContent = fixedTexts[field] || '';
        });
        node.querySelector('.sl-card-del').addEventListener('click', function () { wrap.remove(); updateGridEmpty(); refreshBoundaries(); });
        $grid.appendChild(wrap);
    }

    // Nhận diện chuyển sản phẩm KHÔNG bằng 1 dải ngăn cách chèn giữa (phá vỡ mạch tem nối
    // liền nhau khi in) — mà tô ĐỎ chính cạnh tiếp giáp giữa tem cuối của SP trước và tem
    // đầu của SP sau. Do flex-wrap tự ngắt hàng nên không biết trước 2 tem đó nằm cạnh nhau
    // (phải/trái) hay xếp chồng hàng dưới (dưới/trên) — tô cả 2 khả năng, cạnh nào thực sự
    // tiếp giáp thì hiện đỏ, cạnh còn lại (không có tem kề) vô hại vì chỉ là viền ngoài cùng.
    // Quét lại toàn bộ mỗi khi grid đổi (thêm/xoá tem) để không lệch nếu user xoá tem giữa chừng.
    function refreshBoundaries() {
        var wraps = $grid.querySelectorAll('.sl-card-wrap');
        Array.prototype.forEach.call(wraps, function (w) {
            w.classList.remove('sl-wrap-boundary-out', 'sl-wrap-boundary-in');
        });
        for (var i = 1; i < wraps.length; i++) {
            if (wraps[i].dataset.product !== wraps[i - 1].dataset.product) {
                wraps[i - 1].classList.add('sl-wrap-boundary-out');
                wraps[i].classList.add('sl-wrap-boundary-in');
            }
        }
    }

    // Chỉ cho gõ chữ số (bỏ spinner bước nhảy của input[type=number]).
    $qty.addEventListener('input', function () {
        var digits = $qty.value.replace(/[^0-9]/g, '');
        if (digits !== $qty.value) $qty.value = digits;
    });

    function createLabels() {
        var name = $name.value.trim();
        var qty = parseInt($qty.value, 10);
        if (name === '') { alert('Vui lòng nhập tên siro.'); return; }
        if (isNaN(qty) || qty <= 0) { alert('Số lượng tem phải lớn hơn 0.'); return; }
        for (var i = 0; i < qty; i++) addCard(name);
        updateGridEmpty();
        refreshBoundaries();
    }
    $btnMake.addEventListener('click', createLabels);
    // Enter tại ô Số lượng = bấm "Tạo tem".
    $qty.addEventListener('keydown', function (e) {
        if (e.key === 'Enter') { e.preventDefault(); createLabels(); }
    });

    /* ---------- Sửa tại chỗ các dòng cố định: hover hiện bút, sửa 1 lần lưu app_settings,
     * đồng bộ ngay mọi tem hiện có ---------- */
    function enterFixedEdit(wrap) {
        if (!wrap || wrap.classList.contains('editing')) return;
        var txt = wrap.querySelector('.sl-fixed-text');
        wrap.classList.add('editing');
        txt.dataset.orig = txt.textContent;
        txt.setAttribute('contenteditable', 'true');
        txt.focus();
        var range = document.createRange();
        range.selectNodeContents(txt);
        var sel = window.getSelection();
        sel.removeAllRanges();
        sel.addRange(range);
    }
    function commitFixedEdit(wrap) {
        if (!wrap || !wrap.classList.contains('editing')) return;
        var field = wrap.dataset.field;
        var txt = wrap.querySelector('.sl-fixed-text');
        wrap.classList.remove('editing');
        txt.removeAttribute('contenteditable');
        var val = (txt.textContent || '').replace(/\s+/g, ' ').trim();
        txt.textContent = val;
        if (!field || val === fixedTexts[field]) return;
        fixedTexts[field] = val;
        Array.prototype.forEach.call($grid.querySelectorAll('.sl-fixed-editable[data-field="' + field + '"] .sl-fixed-text'), function (t) { t.textContent = val; });
        post('save_fixed_text', { key: field, value: val }).then(function (res) {
            if (!res || !res.success) console.warn('Lưu thất bại:', res && res.message);
        });
    }
    $grid.addEventListener('click', function (e) {
        var wrap = e.target.closest('.sl-fixed-editable');
        if (wrap && !wrap.classList.contains('editing')) { e.preventDefault(); enterFixedEdit(wrap); }
    });
    $grid.addEventListener('blur', function (e) {
        if (e.target.classList && e.target.classList.contains('sl-fixed-text')) {
            commitFixedEdit(e.target.closest('.sl-fixed-editable'));
        }
    }, true);
    $grid.addEventListener('keydown', function (e) {
        if (!e.target.classList || !e.target.classList.contains('sl-fixed-text')) return;
        if (e.key === 'Enter') { e.preventDefault(); e.target.blur(); }
        else if (e.key === 'Escape') {
            e.target.textContent = e.target.dataset.orig || '';
            var wrap = e.target.closest('.sl-fixed-editable');
            wrap.classList.remove('editing');
            e.target.removeAttribute('contenteditable');
        }
    });

    /* ====================================================================
     *  Reset + In
     * ==================================================================== */
    $btnReset.addEventListener('click', function () {
        Array.prototype.forEach.call($grid.querySelectorAll('.sl-card-wrap'), function (c) { c.remove(); });
        updateGridEmpty();
    });
    $btnPrint.addEventListener('click', function () { window.print(); });

    updateGridEmpty();
})();
