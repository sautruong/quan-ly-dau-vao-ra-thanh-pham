/**
 * Popover LỊCH chọn ngày để XEM LẠI kế hoạch sản xuất hằng ngày (production_staff/plan_for_staff).
 *
 * Bấm nút ngày (#pf-date-btn) → xổ lịch tháng. Chỉ các ngày CÓ bản lưu lịch sử mới chọn được
 * (server: action plan_history_dates, bản lưu: libraries/plan_history.php). Chọn ngày = chuyển sang
 * ?plan_date=YYYY-MM-DD; trang dựng lại từ bản lưu, chỉ xem. Ngày "hiện hành" luôn chọn được và đưa
 * về trang kế hoạch đang chạy (sửa được).
 *
 * Dữ liệu cấu hình đọc từ data-* của nút (PHP đã dựng sẵn URL đúng kiểu URL gọn của hệ thống).
 */
(function () {
    'use strict';

    var btn = document.getElementById('pf-date-btn');
    var pop = document.getElementById('pf-cal-pop');
    if (!btn || !pop) return;

    var MONTH_NAMES = ['Tháng 1', 'Tháng 2', 'Tháng 3', 'Tháng 4', 'Tháng 5', 'Tháng 6',
        'Tháng 7', 'Tháng 8', 'Tháng 9', 'Tháng 10', 'Tháng 11', 'Tháng 12'];
    var DOW = ['T2', 'T3', 'T4', 'T5', 'T6', 'T7', 'CN']; // tuần bắt đầu từ thứ Hai (thói quen VN)

    var current  = btn.getAttribute('data-current') || '';   // ngày đang hiển thị
    var live     = btn.getAttribute('data-live') || '';      // ngày của kế hoạch hiện hành
    var urlTpl   = btn.getAttribute('data-url-tpl') || '';
    var urlLive  = btn.getAttribute('data-url-live') || '';
    var datesUrl = btn.getAttribute('data-dates-url') || '';

    function pad(n) { return (n < 10 ? '0' : '') + n; }
    function ymd(y, m, d) { return y + '-' + pad(m + 1) + '-' + pad(d); }       // m: 0-11
    function ym(y, m) { return y + '-' + pad(m + 1); }
    function esc(s) {
        return String(s == null ? '' : s).replace(/&/g, '&amp;').replace(/</g, '&lt;')
            .replace(/>/g, '&gt;').replace(/"/g, '&quot;');
    }

    var now = new Date();
    var todayStr = ymd(now.getFullYear(), now.getMonth(), now.getDate());

    // Tháng đang xem: tháng của ngày đang hiển thị (không parse được → tháng hiện tại).
    var view = (function () {
        var m = /^(\d{4})-(\d{2})-\d{2}$/.exec(current);
        return m ? { y: parseInt(m[1], 10), m: parseInt(m[2], 10) - 1 } : { y: now.getFullYear(), m: now.getMonth() };
    })();

    var cache = {};      // 'YYYY-MM' -> { 'YYYY-MM-DD': {items, tasks} }
    var state = {};      // 'YYYY-MM' -> 'loading' | 'ok' | 'error'
    var isOpen = false;

    /* ---------- dữ liệu ---------- */
    function loadMonth(y, m) {
        var key = ym(y, m);
        if (state[key] === 'ok' || state[key] === 'loading') return;
        state[key] = 'loading';
        render();
        fetch(datesUrl + '&month=' + encodeURIComponent(key), { credentials: 'same-origin' })
            .then(function (r) { return r.json(); })
            .then(function (res) {
                if (res && res.ok) {
                    cache[key] = res.days || {};
                    if (res.live_date) live = res.live_date;
                    state[key] = 'ok';
                } else { state[key] = 'error'; }
            })
            .catch(function () { state[key] = 'error'; })
            .then(function () { if (isOpen) render(); });
    }

    /* ---------- vẽ ---------- */
    function render() {
        var key = ym(view.y, view.m);
        var days = cache[key] || {};
        var st = state[key] || '';
        var first = (new Date(view.y, view.m, 1).getDay() + 6) % 7;   // 0 = thứ Hai
        var count = new Date(view.y, view.m + 1, 0).getDate();

        var h = '<div class="pf-cal-head">'
            + '<button type="button" class="pf-cal-nav" data-nav="-1" aria-label="Tháng trước"><i class="fa-solid fa-chevron-left"></i></button>'
            + '<div class="pf-cal-title">' + MONTH_NAMES[view.m] + ' / ' + view.y + '</div>'
            + '<button type="button" class="pf-cal-nav" data-nav="1" aria-label="Tháng sau"><i class="fa-solid fa-chevron-right"></i></button>'
            + '</div>';

        h += '<div class="pf-cal-dow">';
        DOW.forEach(function (d) { h += '<span>' + d + '</span>'; });
        h += '</div><div class="pf-cal-grid">';

        for (var i = 0; i < first; i++) h += '<span class="pf-cal-pad"></span>';
        for (var d = 1; d <= count; d++) {
            var ds = ymd(view.y, view.m, d);
            var info = days[ds];
            var isLive = (ds === live);
            var cls = 'pf-cal-day';
            if (info) cls += ' is-has';
            if (isLive) cls += ' is-live';
            if (ds === current) cls += ' is-cur';
            if (ds === todayStr) cls += ' is-today';
            var enabled = !!info || isLive;
            var tip = '';
            if (info) tip = info.items + ' sản phẩm' + (info.tasks ? ' · ' + info.tasks + ' việc khác' : '');
            if (isLive) tip = (tip ? tip + ' · ' : '') + 'kế hoạch hiện hành';
            h += '<button type="button" class="' + cls + '" data-date="' + ds + '"'
                + (enabled ? '' : ' disabled')
                + (tip ? ' title="' + esc(tip) + '"' : '')
                + ' aria-label="' + d + ' ' + MONTH_NAMES[view.m].toLowerCase() + (tip ? ', ' + esc(tip) : '') + '">'
                + '<span>' + d + '</span></button>';
        }
        h += '</div>';

        var status = '';
        if (st === 'loading') status = 'Đang tải…';
        else if (st === 'error') status = 'Không tải được lịch sử. <button type="button" class="pf-cal-retry">Thử lại</button>';
        else if (st === 'ok' && !Object.keys(days).length) status = 'Tháng này chưa có kế hoạch nào được lưu.';

        h += '<div class="pf-cal-status">' + status + '</div>'
            + '<div class="pf-cal-foot">'
            + '<span class="pf-cal-legend"><i class="pf-cal-dot"></i> Có kế hoạch đã lưu</span>'
            + '<button type="button" class="pf-cal-live-btn">Về hiện hành</button>'
            + '</div>';

        pop.innerHTML = h;
    }

    /* ---------- đóng / mở ---------- */
    function place() {
        // Popover canh giữa dưới nút; nếu tràn khỏi màn hình (điện thoại hẹp) thì dịch lại cho vừa.
        pop.style.transform = '';
        var r = pop.getBoundingClientRect();
        var pad8 = 8, shift = 0;
        if (r.left < pad8) shift = pad8 - r.left;
        else if (r.right > window.innerWidth - pad8) shift = (window.innerWidth - pad8) - r.right;
        if (shift) pop.style.transform = 'translateX(calc(-50% + ' + Math.round(shift) + 'px))';
    }

    function open() {
        isOpen = true;
        pop.hidden = false;
        btn.setAttribute('aria-expanded', 'true');
        btn.classList.add('is-open');
        render();
        place();
        loadMonth(view.y, view.m);
    }

    function close() {
        isOpen = false;
        pop.hidden = true;
        btn.setAttribute('aria-expanded', 'false');
        btn.classList.remove('is-open');
    }

    function go(date) {
        if (date === live) { window.location.href = urlLive; return; }
        if (date === current) { close(); return; }
        window.location.href = urlTpl.replace('__DATE__', encodeURIComponent(date));
    }

    /* ---------- sự kiện ---------- */
    btn.addEventListener('click', function (e) {
        e.stopPropagation();
        if (isOpen) close(); else open();
    });

    pop.addEventListener('click', function (e) {
        e.stopPropagation();
        var nav = e.target.closest('.pf-cal-nav');
        if (nav) {
            view.m += parseInt(nav.getAttribute('data-nav'), 10);
            if (view.m < 0) { view.m = 11; view.y--; }
            if (view.m > 11) { view.m = 0; view.y++; }
            render();
            loadMonth(view.y, view.m);
            return;
        }
        var day = e.target.closest('.pf-cal-day');
        if (day && !day.disabled) { go(day.getAttribute('data-date')); return; }
        if (e.target.closest('.pf-cal-live-btn')) { window.location.href = urlLive; return; }
        if (e.target.closest('.pf-cal-retry')) {
            state[ym(view.y, view.m)] = '';
            loadMonth(view.y, view.m);
        }
    });

    document.addEventListener('click', function () { if (isOpen) close(); });
    document.addEventListener('keydown', function (e) {
        if (e.key === 'Escape' && isOpen) { close(); btn.focus(); }
    });
    window.addEventListener('resize', function () { if (isOpen) place(); });

    // Tải trước tháng đang xem: lần mở đầu tiên đã có sẵn chấm "có kế hoạch", không nháy trạng thái trống.
    loadMonth(view.y, view.m);
})();
