/* =====================================================================
 *  Tem date cà phê (coffee_date_label)
 * ===================================================================== */
(function () {
    'use strict';

    var CFG = window.CDL_CONFIG || { baseUrl: '?mod=coffee_date_label&controllers=coffee_date_label&action=' };

    /* ---------- DOM ---------- */
    var $ = function (id) { return document.getElementById(id); };
    var $name = $('cdl-name');
    var $qty = $('cdl-qty');
    var $btnReset = $('cdl-btn-reset'), $btnMake = $('cdl-btn-make'), $btnPrint = $('cdl-btn-print');
    var $grid = $('cdl-grid'), $gridEmpty = $('cdl-grid-empty');
    var $tpl = $('cdl-tpl-card');

    function updateGridEmpty() {
        $gridEmpty.style.display = $grid.querySelectorAll('.cdl-card').length ? 'none' : 'block';
    }

    /* ====================================================================
     *  LỊCH CHỌN NGÀY (tự chế, xanh lá) — dùng chung cho cả NSX và HSD,
     *  mỗi lịch có state riêng nhưng cùng 1 bộ hàm dựng theo prefix truyền vào.
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
            var html = '<div class="cdl-cal-head">'
                + '<button type="button" class="cdl-cal-nav" data-nav="-1"><i class="fa-solid fa-chevron-left"></i></button>'
                + '<span class="cdl-cal-title">' + MONTH_NAMES[calViewM - 1] + ' ' + calViewY + '</span>'
                + '<button type="button" class="cdl-cal-nav" data-nav="1"><i class="fa-solid fa-chevron-right"></i></button>'
                + '</div><div class="cdl-cal-week">' + WEEKDAYS.map(function (w) { return '<span>' + w + '</span>'; }).join('') + '</div>'
                + '<div class="cdl-cal-grid">';
            for (var i = 0; i < startWeekday; i++) html += '<span class="cdl-cal-day empty"></span>';
            for (var d = 1; d <= total; d++) {
                var isToday = t.getFullYear() === calViewY && (t.getMonth() + 1) === calViewM && t.getDate() === d;
                var isSel = sel && sel.y === calViewY && sel.m === calViewM && sel.d === d;
                html += '<span class="cdl-cal-day' + (isToday ? ' today' : '') + (isSel ? ' selected' : '') + '" data-d="' + d + '">' + d + '</span>';
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
            var day = e.target.closest('.cdl-cal-day:not(.empty)');
            if (day) { setSelectedDate(calViewY, calViewM, +day.dataset.d); closeCal(); }
        });
        document.addEventListener('click', function (e) { if (!e.target.closest('.cdl-date-picker')) closeCal(); });

        return {
            set: function (y, m, d, silent) { setSelectedDate(y, m, d, silent); },
            get: function () { return parseISODate($hidden.value); },
            initDisplay: function () {
                var init = parseISODate($hidden.value);
                if (init) $display.textContent = fmtDateDisplay(init.y, init.m, init.d);
            }
        };
    }

    var nsxPicker = makeDatePicker('cdl-nsx', function (sel) {
        var hsd = addOneYear(sel);
        hsdPicker.set(hsd.y, hsd.m, hsd.d, true);
    });
    var hsdPicker = makeDatePicker('cdl-hsd', null);

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
        var node = wrap.querySelector('.cdl-card');
        node.querySelector('.cdl-card-name').textContent = name;
        var nsx = nsxPicker.get(), hsd = hsdPicker.get();
        node.querySelector('.cdl-card-nsx').textContent = nsx ? fmtDateDisplay(nsx.y, nsx.m, nsx.d) : '';
        node.querySelector('.cdl-card-hsd').textContent = hsd ? fmtDateDisplay(hsd.y, hsd.m, hsd.d) : '';
        node.querySelector('.cdl-card-del').addEventListener('click', function () { wrap.remove(); updateGridEmpty(); });
        $grid.appendChild(wrap);
    }

    // Vách ngăn nhận diện: chèn trước lô tem mới nếu grid đã có tem của SẢN PHẨM KHÁC trước đó,
    // để lúc xem trước/in A4 dễ nhận ra chỗ chuyển sang sản phẩm khác (nhất là khi in nhiều đợt gộp chung).
    function addDividerIfProductChanged(name) {
        var lastWrap = $grid.querySelector('.cdl-card-wrap:last-child');
        if (!lastWrap || lastWrap.dataset.product === name) return;
        var div = document.createElement('div');
        div.className = 'cdl-divider';
        div.innerHTML = '<span class="cdl-divider-text"><i class="fa-solid fa-arrow-turn-down"></i> ' +
            'Chuyển sản phẩm: ' + name.replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;') + '</span>';
        $grid.appendChild(div);
    }

    $btnMake.addEventListener('click', function () {
        var name = $name.value.trim();
        var qty = parseInt($qty.value, 10);
        if (name === '') { alert('Vui lòng nhập tên dòng sản phẩm.'); return; }
        if (isNaN(qty) || qty <= 0) { alert('Số lượng tem phải lớn hơn 0.'); return; }
        addDividerIfProductChanged(name);
        for (var i = 0; i < qty; i++) addCard(name);
        updateGridEmpty();
    });

    $btnReset.addEventListener('click', function () {
        Array.prototype.forEach.call($grid.querySelectorAll('.cdl-card-wrap, .cdl-divider'), function (c) { c.remove(); });
        updateGridEmpty();
    });
    $btnPrint.addEventListener('click', function () { window.print(); });

    updateGridEmpty();
})();
