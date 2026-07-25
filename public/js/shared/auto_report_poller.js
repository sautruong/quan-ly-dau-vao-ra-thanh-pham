/**
 * auto_report_poller — hẹn giờ GỬI BÁO CÁO TỰ ĐỘNG phía client.
 *
 * Nạp toàn cục (chat-widget.php) trên mọi trang app khi đã đăng nhập. Vì việc "chụp"
 * báo cáo cần chạy trong trình duyệt và hệ thống không có cron, poller này thay vai trò
 * hẹn giờ: khi tới khung giờ + người dùng hiện tại là người được uỷ quyền (accepted, chưa
 * tạm ngưng) và đang online → hiện thông báo nổi đếm ngược 5s rồi tự mở trang daily_dashboard
 * (?auto_send=<id>) để chụp và gửi, xong tự quay lại trang đang xem.
 *
 * Server (auto_report_due_check) mới là bên quyết định "đến giờ" (giờ máy chủ, giờ VN);
 * client chỉ thực thi. Idempotency dựa vào chốt server (last_sent_date) — poller chỉ thêm
 * chống lặp trong-tab để tránh nổ lại sau khi người dùng bấm Huỷ.
 */
(function () {
    'use strict';

    // Đang Ở trang chụp (?auto_send=) → không poll (tránh vòng lặp). daily_dashboard.js lo phần chụp.
    if (/[?&]auto_send=/.test(location.search)) return;

    var API = '?mod=report&controllers=report&action=';
    var POLL_MS = 60000;      // 60s
    var COUNTDOWN = 5;        // giây báo trước
    var firing = false;       // đang trong quá trình đếm ngược / điều hướng

    function skey(id, date) { return 'ar_fired_' + id + '_' + date; }

    /* ---------- Toast đếm ngược (tự chứa, không phụ thuộc CSS ngoài) ---------- */
    function injectStyle() {
        if (document.getElementById('ar-poller-style')) return;
        var css =
            '.ar-toast{position:fixed;top:18px;left:50%;transform:translateX(-50%);z-index:2147483000;' +
            'background:#0f172a;color:#fff;border-radius:14px;padding:14px 18px;max-width:min(92vw,420px);' +
            'box-shadow:0 12px 34px rgba(0,0,0,.35);font-family:inherit;display:flex;gap:12px;align-items:center;' +
            'animation:ar-in .25s ease}' +
            '@keyframes ar-in{from{opacity:0;transform:translate(-50%,-12px)}to{opacity:1;transform:translate(-50%,0)}}' +
            '.ar-toast .ar-ico{flex:0 0 auto;width:38px;height:38px;border-radius:50%;background:#16a34a;' +
            'display:flex;align-items:center;justify-content:center;font-size:18px}' +
            '.ar-toast .ar-tx{flex:1 1 auto;line-height:1.35;font-size:14px}' +
            '.ar-toast .ar-tx b{display:block;font-size:14px;margin-bottom:2px}' +
            '.ar-toast .ar-tx small{opacity:.75}' +
            '.ar-toast .ar-num{color:#4ade80;font-weight:700}' +
            '.ar-toast .ar-cancel{flex:0 0 auto;background:rgba(255,255,255,.12);color:#fff;border:0;border-radius:9px;' +
            'padding:8px 12px;cursor:pointer;font-size:13px}' +
            '.ar-toast .ar-cancel:hover{background:rgba(255,255,255,.22)}';
        var st = document.createElement('style');
        st.id = 'ar-poller-style';
        st.textContent = css;
        document.head.appendChild(st);
    }

    function startCountdown(due) {
        firing = true;
        injectStyle();
        var left = COUNTDOWN;
        var el = document.createElement('div');
        el.className = 'ar-toast';
        el.innerHTML =
            '<span class="ar-ico">📤</span>' +
            '<span class="ar-tx"><b>Tự động gửi báo cáo</b>' +
            '<small>"' + escapeHtml(due.title || 'Báo cáo') + '" sẽ được chụp &amp; gửi sau ' +
            '<span class="ar-num">' + left + '</span>s…</small></span>' +
            '<button type="button" class="ar-cancel">Huỷ</button>';
        document.body.appendChild(el);
        var numEl = el.querySelector('.ar-num');

        var timer = setInterval(function () {
            left -= 1;
            if (numEl) numEl.textContent = String(Math.max(0, left));
            if (left <= 0) {
                clearInterval(timer);
                proceed(due, el);
            }
        }, 1000);

        el.querySelector('.ar-cancel').addEventListener('click', function () {
            clearInterval(timer);
            try { sessionStorage.setItem(skey(due.config_id, due.date), '1'); } catch (e) {}
            if (el.parentNode) el.parentNode.removeChild(el);
            firing = false;
        });
    }

    function proceed(due, el) {
        try { sessionStorage.setItem(skey(due.config_id, due.date), '1'); } catch (e) {}
        try { sessionStorage.setItem('ar_return_url', location.href); } catch (e) {}
        if (el) {
            var tx = el.querySelector('.ar-tx');
            if (tx) tx.innerHTML = '<b>Đang mở báo cáo…</b><small>Vui lòng chờ, sẽ tự quay lại trang này.</small>';
            var c = el.querySelector('.ar-cancel'); if (c) c.remove();
        }
        var settle = parseInt(due.settle_ms, 10) || 4000;
        location.href = API + 'daily_dashboard&auto_send=' + encodeURIComponent(due.config_id) + '&settle=' + settle;
    }

    function escapeHtml(s) {
        return String(s == null ? '' : s)
            .replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;').replace(/'/g, '&#39;');
    }

    /* ---------- Poll ---------- */
    function poll() {
        if (firing) return;
        fetch(API + 'auto_report_due_check', { credentials: 'same-origin' })
            .then(function (r) { return r.json(); })
            .then(function (res) {
                if (!res || !res.ok || !res.due || !res.due.length) return;
                var due = res.due[0];
                var seen = false;
                try { seen = sessionStorage.getItem(skey(due.config_id, due.date)) === '1'; } catch (e) {}
                if (seen) return;               // đã nổ/đã huỷ trong tab này hôm nay
                startCountdown(due);
            })
            .catch(function () {});
    }

    setTimeout(poll, 5000);        // kiểm tra sớm sau khi trang tải
    setInterval(poll, POLL_MS);
})();
