/**
 * auto_report_mine — trang tự phục vụ của người được uỷ quyền gửi báo cáo.
 * Nhận/Từ chối lời mời, tạm ngưng/bật lại phần việc của mình.
 */
(function () {
    'use strict';
    var ARM = window.ARM || {};
    var API = ARM.base || '?mod=report&controllers=report&action=';
    var configs = ARM.configs || [];
    var tbody = document.getElementById('arm-tbody');

    function esc(s) {
        return String(s == null ? '' : s)
            .replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;').replace(/'/g, '&#39;');
    }
    function postForm(action, params) {
        var b = new URLSearchParams();
        Object.keys(params).forEach(function (k) { b.append(k, params[k]); });
        return fetch(API + action, {
            method: 'POST', headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body: b.toString(), credentials: 'same-origin'
        }).then(function (r) { return r.json(); });
    }

    function statusBadge(c) {
        if (c.is_paused == 1) return '<span class="ar-badge ar-badge-paused">Tạm ngưng</span>';
        if (c.delegation_status === 'accepted') return '<span class="ar-badge ar-badge-on">Đang hoạt động</span>';
        if (c.delegation_status === 'declined') return '<span class="ar-badge ar-badge-off">Đã từ chối</span>';
        return '<span class="ar-badge ar-badge-wait">Chờ bạn xác nhận</span>';
    }
    /** Dòng nhỏ: hôm nay lịch đã chạy chưa / vì sao chưa (xem ar_decorate phía PHP). */
    function runNote(c) {
        if (!c.run_text) return '';
        return '<div class="ar-run ar-run-' + esc(c.run_state || '') + '">' + esc(c.run_text) + '</div>';
    }
    function actionsHtml(c) {
        if (c.delegation_status === 'pending') {
            return '<button type="button" class="ar-btn ar-btn-primary ar-sm" data-accept="' + c.id + '">Nhận</button> '
                + '<button type="button" class="ar-btn ar-sm" data-decline="' + c.id + '">Từ chối</button>';
        }
        if (c.delegation_status === 'accepted') {
            var paused = c.is_paused == 1;
            return '<button type="button" class="ar-btn ar-sm" data-pause="' + c.id + '" data-to="' + (paused ? 0 : 1) + '">'
                + (paused ? 'Bật lại' : 'Tạm ngưng') + '</button>';
        }
        return '<span style="color:#94a3b8">—</span>';
    }
    function render() {
        if (!configs.length) {
            tbody.innerHTML = '<tr class="ar-empty-row"><td colspan="5">Bạn chưa được uỷ quyền lịch nào.</td></tr>';
            return;
        }
        tbody.innerHTML = configs.map(function (c) {
            return '<tr>'
                + '<td><b>' + esc(c.title) + '</b></td>'
                + '<td class="ar-time">' + esc(c.send_time_hm) + '</td>'
                + '<td class="ar-recipient">' + esc(c.recipient_text) + '</td>'
                + '<td>' + statusBadge(c) + runNote(c) + '</td>'
                + '<td class="ar-col-act ar-mine-actions">' + actionsHtml(c) + '</td>'
                + '</tr>';
        }).join('');
    }
    function reload() {
        // Trang này không có endpoint list riêng cho delegate → tải lại trang cho chắc dữ liệu.
        location.reload();
    }

    tbody.addEventListener('click', function (e) {
        var acc = e.target.closest('[data-accept]');
        var dec = e.target.closest('[data-decline]');
        var pau = e.target.closest('[data-pause]');
        if (acc || dec) {
            var btn = acc || dec;
            btn.disabled = true;
            postForm('auto_report_respond', { config_id: btn.getAttribute(acc ? 'data-accept' : 'data-decline'), accept: acc ? 1 : 0 })
                .then(function (res) { if (res && res.ok) reload(); else btn.disabled = false; })
                .catch(function () { btn.disabled = false; });
            return;
        }
        if (pau) {
            pau.disabled = true;
            postForm('auto_report_toggle_pause', { id: pau.getAttribute('data-pause'), paused: pau.getAttribute('data-to') })
                .then(function (res) { if (res && res.ok) reload(); else pau.disabled = false; })
                .catch(function () { pau.disabled = false; });
            return;
        }
    });

    render();
})();
