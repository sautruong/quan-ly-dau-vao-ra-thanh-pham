/**
 * plan_auto_export.js — XUẤT KẾ HOẠCH SẢN XUẤT ĐỊNH KỲ (view long_term_production_plan).
 *
 * Hai phần độc lập trong cùng file:
 *   A. MODAL "Cài đặt"  — chỉ admin (nút #ltp-auto-setting chỉ render cho admin).
 *   B. POLLER            — chạy cho MỌI user mở trang này; chỉ người được chọn làm "người thực
 *                          hiện" mới thật sự tới lượt (server quyết định, xem pae_due_for_user).
 *
 * VÌ SAO POLLER NẰM Ở TRANG NÀY chứ không phải poller toàn cục như auto_report: ảnh cần chụp
 * chính là BOARD của trang này. Đặt ở poller toàn cục thì tới giờ phải điều hướng người dùng
 * sang đây rồi chụp rồi trả về — thêm một lớp phức tạp mà không được gì, vì người thực hiện vốn
 * là người của đội kế hoạch, ngày nào cũng mở đúng trang này. Đổi lại: KHÔNG mở trang này thì
 * lịch không chạy — nên quá cửa sổ chờ, server báo chuông cho admin (pae_run_missed_sweep).
 */
(function () {
    'use strict';

    var BASE = (window.LTP_CONFIG && window.LTP_CONFIG.baseUrl)
        || '?mod=production_staff&controllers=production_staff&action=';

    /** Nhịp hỏi server. 60s là đủ: cửa sổ chờ mặc định 30 phút. */
    var POLL_MS = 60000;
    /** Đếm ngược trước khi chụp — để người đang thao tác kịp bấm Huỷ. */
    var COUNTDOWN_S = 5;

    function post(action, data) {
        var body = new FormData();
        Object.keys(data || {}).forEach(function (k) { body.append(k, data[k]); });
        return fetch(BASE + action, { method: 'POST', body: body, credentials: 'same-origin' })
            .then(function (r) { return r.json(); })
            .catch(function () { return null; });
    }

    function getJson(action, qs) {
        return fetch(BASE + action + (qs ? '&' + qs : ''), { credentials: 'same-origin' })
            .then(function (r) { return r.json(); })
            .catch(function () { return null; });
    }

    /* =================================================================
     *  A. MODAL CÀI ĐẶT
     * ================================================================= */

    var openBtn = document.getElementById('ltp-auto-setting');
    var modal   = document.getElementById('ltp-modal-auto');

    if (openBtn && modal) {
        var elActive   = document.getElementById('pae-active');
        var elTime     = document.getElementById('pae-time');
        var elWindow   = document.getElementById('pae-window');
        var elSunday   = document.getElementById('pae-skip-sunday');
        var elDelegate = document.getElementById('pae-delegate');
        var elUsers    = document.getElementById('pae-users');
        var elGroup    = document.getElementById('pae-group');
        var elCaption  = document.getElementById('pae-caption');
        var elState    = document.getElementById('pae-state');
        var elMsg      = document.getElementById('pae-msg');
        var elSave     = document.getElementById('pae-save');

        function rtype() {
            var r = modal.querySelector('input[name="pae-rtype"]:checked');
            return r ? r.value : 'users';
        }

        function applyRtype() {
            var isGroup = rtype() === 'group';
            elUsers.style.display = isGroup ? 'none' : '';
            elGroup.style.display = isGroup ? '' : 'none';
        }

        modal.querySelectorAll('input[name="pae-rtype"]').forEach(function (r) {
            r.addEventListener('change', applyRtype);
        });

        function fillSelect(sel, list, selectedIds) {
            sel.innerHTML = '';
            (list || []).forEach(function (o) {
                var op = document.createElement('option');
                op.value = String(o.id);
                op.textContent = o.name + (o.member_count ? ' (' + o.member_count + ' người)' : '');
                if (selectedIds && selectedIds.indexOf(String(o.id)) !== -1) op.selected = true;
                sel.appendChild(op);
            });
        }

        /** Mô tả trạng thái lịch bằng lời — không có dòng này thì "sao chưa chạy" rất khó soi. */
        function describeState(cfg) {
            if (!cfg) return 'Chưa cấu hình lần nào.';
            if (!Number(cfg.is_active)) return 'Đang TẮT.';
            var t = String(cfg.send_time || '').slice(0, 5);
            if (cfg.last_run_date === new Date().toISOString().slice(0, 10)) {
                return 'Hôm nay ĐÃ chạy lúc ' + String(cfg.last_run_at || '').slice(11, 16) + ' — mai mới chạy tiếp.';
            }
            return 'Đang bật, giờ chạy ' + t + '. Đổi giờ sẽ mở khoá chạy lại ngay hôm nay.';
        }

        var loaded = false;
        function load(delegateId) {
            return getJson('plan_auto_export_config', delegateId ? 'delegate_user_id=' + delegateId : '')
                .then(function (res) {
                    if (!res || !res.ok) return;
                    var cfg = res.config;

                    // Người thực hiện — nạp trước vì danh sách NHÓM phụ thuộc người này.
                    if (!loaded) {
                        fillSelect(elDelegate, res.users, cfg ? [String(cfg.delegate_user_id)] : []);
                    }

                    var selectedGroup = cfg ? [String(cfg.recipient_conversation_id)] : [];
                    fillSelect(elGroup, res.groups, selectedGroup);

                    if (!loaded && cfg) {
                        elActive.checked = !!Number(cfg.is_active);
                        elTime.value     = String(cfg.send_time || '16:50:00').slice(0, 5);
                        elWindow.value   = cfg.window_minutes || 30;
                        elSunday.checked = !!Number(cfg.skip_sunday);
                        elCaption.value  = cfg.caption || '';
                        var rt = cfg.recipient_type === 'group' ? 'group' : 'users';
                        var radio = modal.querySelector('input[name="pae-rtype"][value="' + rt + '"]');
                        if (radio) radio.checked = true;
                        var uids = [];
                        try { uids = (JSON.parse(cfg.recipient_user_ids || '[]') || []).map(String); } catch (e) { uids = []; }
                        fillSelect(elUsers, res.users, uids);
                    } else if (!loaded) {
                        fillSelect(elUsers, res.users, []);
                    }

                    elState.textContent = describeState(cfg);
                    applyRtype();
                    loaded = true;
                });
        }

        // Đổi người thực hiện → nạp lại danh sách nhóm của CHÍNH người đó (không thì lưu sẽ bị
        // server từ chối vì "người thực hiện không thuộc nhóm đã chọn").
        elDelegate.addEventListener('change', function () {
            getJson('plan_auto_export_config', 'delegate_user_id=' + elDelegate.value)
                .then(function (res) {
                    if (res && res.ok) fillSelect(elGroup, res.groups, []);
                });
        });

        /* Hiện/ẩn bằng class .open — .ltp-modal-mask mặc định display:none, .open đổi thành
           flex và canh giữa. Bật/tắt style.display sẽ đè lên display:flex của .open và làm
           hộp mất luôn phần canh giữa. */
        function openModal() {
            modal.classList.add('open');
            load(null);
        }
        function closeModal() { modal.classList.remove('open'); elMsg.textContent = ''; }

        openBtn.addEventListener('click', openModal);
        modal.addEventListener('click', function (e) {
            if (e.target === modal || (e.target.dataset && e.target.dataset.close === 'ltp-modal-auto')) closeModal();
        });
        document.addEventListener('keydown', function (e) {
            if (e.key === 'Escape' && modal.classList.contains('open')) closeModal();
        });

        elSave.addEventListener('click', function () {
            var uids = Array.prototype.slice.call(elUsers.selectedOptions || []).map(function (o) { return Number(o.value); });
            elSave.disabled = true;
            elMsg.textContent = 'Đang lưu...';
            post('plan_auto_export_save', {
                is_active: elActive.checked ? 1 : '',
                send_time: elTime.value,
                skip_sunday: elSunday.checked ? 1 : '',
                delegate_user_id: elDelegate.value || 0,
                window_minutes: elWindow.value || 30,
                recipient_type: rtype(),
                recipient_user_ids: JSON.stringify(uids),
                recipient_conversation_id: elGroup.value || 0,
                caption: elCaption.value || ''
            }).then(function (res) {
                elSave.disabled = false;
                if (res && res.ok) {
                    elMsg.textContent = 'Đã lưu.';
                    elState.textContent = describeState(res.config);
                    setTimeout(function () { elMsg.textContent = ''; }, 2500);
                } else {
                    elMsg.textContent = (res && res.message) || 'Lưu thất bại.';
                }
            });
        });
    }

    /* =================================================================
     *  B. POLLER + CHỤP
     * ================================================================= */

    /** Nạp html2canvas theo yêu cầu — không bắt mọi lượt mở board phải tải thư viện chụp. */
    function ensureHtml2Canvas() {
        if (window.html2canvas) return Promise.resolve(true);
        return new Promise(function (resolve) {
            var s = document.createElement('script');
            s.src = 'https://cdnjs.cloudflare.com/ajax/libs/html2canvas/1.4.1/html2canvas.min.js';
            s.onload = function () { resolve(!!window.html2canvas); };
            s.onerror = function () { resolve(false); };
            document.head.appendChild(s);
        });
    }

    function cardOf(dateStr) {
        return document.querySelector('.ltp-card[data-date="' + dateStr + '"]');
    }

    /**
     * Chụp card của ngày mai. Trả Blob hoặc null.
     *
     * Card có thể đang bị THU GỌN theo tuần: cơ chế FAQ tuần gắn class `ltp-week-hidden` lên
     * CHÍNH CARD (không phải lên nhóm bao ngoài — xem long_term_production_plan.js). Phải gỡ tạm
     * rồi trả lại nguyên trạng, nếu không html2canvas chụp phần tử display:none và ra ảnh trắng.
     */
    function captureCard(dateStr) {
        return ensureHtml2Canvas().then(function (ok) {
            var card = cardOf(dateStr);
            if (!ok || !card) return null;

            var wasHidden = card.classList.contains('ltp-week-hidden');
            if (wasHidden) card.classList.remove('ltp-week-hidden');
            var restore = function () { if (wasHidden) card.classList.add('ltp-week-hidden'); };

            return window.html2canvas(card, { backgroundColor: '#ffffff', scale: 2, logging: false })
                .then(function (canvas) {
                    restore();
                    return new Promise(function (res) { canvas.toBlob(res, 'image/png'); });
                })
                .catch(function () { restore(); return null; });
        });
    }

    function toast(text, onCancel) {
        var box = document.createElement('div');
        box.className = 'pae-toast';
        box.innerHTML = '<span class="pae-toast-text"></span>'
                      + '<button type="button" class="pae-toast-cancel">Huỷ</button>';
        box.querySelector('.pae-toast-text').textContent = text;
        box.querySelector('.pae-toast-cancel').addEventListener('click', function () {
            box.remove();
            if (onCancel) onCancel();
        });
        document.body.appendChild(box);
        return box;
    }

    function run(due) {
        var planDate = due.plan_date;
        var left = COUNTDOWN_S;
        var cancelled = false;

        var box = toast('', function () {
            cancelled = true;
            // Huỷ = bỏ qua HÔM NAY ở tab này, không nổ lại liên tục mỗi nhịp poll.
            try { sessionStorage.setItem('pae_skip_' + todayStr(), '1'); } catch (e) { /* private mode */ }
        });

        function tick() {
            if (cancelled) return;
            if (left <= 0) {
                box.remove();
                doCapture();
                return;
            }
            box.querySelector('.pae-toast-text').textContent =
                'Tự xuất kế hoạch ngày ' + planDate.split('-').reverse().join('/') + ' sau ' + left + 's…';
            left--;
            setTimeout(tick, 1000);
        }
        tick();

        function doCapture() {
            captureCard(planDate).then(function (blob) {
                var body = new FormData();
                if (blob) body.append('image', blob, 'ke-hoach-' + planDate + '.png');
                return fetch(BASE + 'plan_auto_export_run', {
                    method: 'POST', body: body, credentials: 'same-origin'
                }).then(function (r) { return r.json(); });
            }).then(function (res) {
                if (!res) return;
                var t = res.ok
                    ? (res.sent ? 'Đã xuất kế hoạch và gửi ảnh vào chat.'
                                : 'Đã xuất kế hoạch (chưa gửi được ảnh).')
                    : ((res.message) || 'Xuất kế hoạch thất bại.');
                var b = toast(t, null);
                setTimeout(function () { b.remove(); }, 6000);
            }).catch(function () { /* im lặng — quét-lỡ phía server sẽ báo admin */ });
        }
    }

    function todayStr() { return new Date().toISOString().slice(0, 10); }

    function poll() {
        var skipped = false;
        try { skipped = sessionStorage.getItem('pae_skip_' + todayStr()) === '1'; } catch (e) { skipped = false; }
        if (skipped) return;

        getJson('plan_auto_export_due_check', '').then(function (res) {
            if (res && res.ok && res.due) {
                // Chốt luôn để 2 nhịp poll chồng nhau không nổ 2 lần.
                try { sessionStorage.setItem('pae_skip_' + todayStr(), '1'); } catch (e) { /* noop */ }
                run(res.due);
            }
        });
    }

    // Chỉ chạy khi đang ở đúng board (có #ltp-board) — file này chỉ nhúng ở đây, nhưng kiểm
    // thêm cho chắc phòng khi sau này ai đó nhúng nhầm chỗ.
    if (document.getElementById('ltp-board')) {
        setTimeout(poll, 5000);
        setInterval(poll, POLL_MS);
    }
})();
