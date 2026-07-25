/**
 * auto_report_admin — trang quản trị danh sách lịch "Gửi báo cáo tự động".
 * Render bảng từ window.AR.configs, thêm/sửa qua modal, tạm ngưng/xoá, tải lại từ server.
 */
(function () {
    'use strict';
    var AR = window.AR || {};
    var API = AR.base || '?mod=report&controllers=report&action=';
    var users = AR.users || [];
    var configs = AR.configs || [];

    var tbody = document.getElementById('ar-tbody');
    var modal = document.getElementById('ar-modal');

    function esc(s) {
        return String(s == null ? '' : s)
            .replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;').replace(/'/g, '&#39;');
    }
    function post(action, body) {
        var opt = { method: 'POST', credentials: 'same-origin' };
        if (body instanceof FormData) { opt.body = body; }
        else { opt.headers = { 'Content-Type': 'application/x-www-form-urlencoded' }; opt.body = body; }
        return fetch(API + action, opt).then(function (r) { return r.json(); });
    }

    /* ---------- Bảng ---------- */
    function statusBadge(cfg) {
        if (cfg.is_paused == 1) return '<span class="ar-badge ar-badge-paused">Tạm ngưng</span>';
        var st = cfg.delegation_status;
        if (st === 'accepted')  return '<span class="ar-badge ar-badge-on">Đang hoạt động</span>';
        if (st === 'declined')  return '<span class="ar-badge ar-badge-off">Đã từ chối</span>';
        return '<span class="ar-badge ar-badge-wait">Chờ xác nhận</span>';
    }
    function renderTable() {
        if (!configs.length) {
            tbody.innerHTML = '<tr class="ar-empty-row"><td colspan="6">Chưa có lịch nào. Bấm "Thêm lịch" để tạo.</td></tr>';
            return;
        }
        tbody.innerHTML = configs.map(function (c) {
            var paused = c.is_paused == 1;
            return '<tr>'
                + '<td><b>' + esc(c.title) + '</b></td>'
                + '<td class="ar-time">' + esc(c.send_time_hm) + '</td>'
                + '<td>' + esc(c.delegate_name) + '</td>'
                + '<td class="ar-recipient">' + esc(c.recipient_text) + '</td>'
                + '<td>' + statusBadge(c) + '</td>'
                + '<td class="ar-col-act">'
                + '<button type="button" class="ar-mini" data-edit="' + c.id + '" title="Sửa"><i class="fa-solid fa-pen"></i></button>'
                + '<button type="button" class="ar-mini" data-pause="' + c.id + '" data-to="' + (paused ? 0 : 1) + '" title="' + (paused ? 'Bật lại' : 'Tạm ngưng') + '">'
                + '<i class="fa-solid ' + (paused ? 'fa-play' : 'fa-pause') + '"></i></button>'
                + '<button type="button" class="ar-mini ar-mini-danger" data-del="' + c.id + '" title="Xoá"><i class="fa-solid fa-trash"></i></button>'
                + '</td>'
                + '</tr>';
        }).join('');
    }

    function reloadList() {
        return fetch(API + 'auto_report_list', { credentials: 'same-origin' })
            .then(function (r) { return r.json(); })
            .then(function (res) { if (res && res.ok) { configs = res.configs || []; renderTable(); } });
    }

    /* ---------- Modal ---------- */
    var fId = document.getElementById('ar-f-id');
    var fTitle = document.getElementById('ar-f-title');
    var fTime = document.getElementById('ar-f-time');
    var fWindow = document.getElementById('ar-f-window');
    var fDelegate = document.getElementById('ar-f-delegate');
    var fUsers = document.getElementById('ar-f-users');
    var fGroup = document.getElementById('ar-f-group');
    var fCaption = document.getElementById('ar-f-caption');
    var usersWrap = document.getElementById('ar-users-wrap');
    var groupWrap = document.getElementById('ar-group-wrap');
    var errBox = document.getElementById('ar-modal-err');

    function fillUserSelects() {
        var opts = users.map(function (u) { return '<option value="' + u.id + '">' + esc(u.name) + '</option>'; }).join('');
        fDelegate.innerHTML = '<option value="">— Chọn tài khoản —</option>' + opts;
        fUsers.innerHTML = opts;
    }
    function currentRtype() {
        var r = document.querySelector('input[name="ar-rtype"]:checked');
        return r ? r.value : 'users';
    }
    function applyRtype() {
        var t = currentRtype();
        usersWrap.hidden = (t !== 'users');
        groupWrap.hidden = (t !== 'group');
    }
    function loadGroups(delegateId, selectedGroupId) {
        fGroup.innerHTML = '<option value="">— Đang tải nhóm… —</option>';
        if (!delegateId) { fGroup.innerHTML = '<option value="">— Chọn người uỷ quyền trước —</option>'; return; }
        fetch(API + 'auto_report_recipients_feed&delegate_user_id=' + delegateId, { credentials: 'same-origin' })
            .then(function (r) { return r.json(); })
            .then(function (res) {
                var groups = (res && res.ok) ? (res.groups || []) : [];
                if (!groups.length) { fGroup.innerHTML = '<option value="">— Người này chưa ở nhóm chat nào —</option>'; return; }
                fGroup.innerHTML = '<option value="">— Chọn nhóm —</option>' + groups.map(function (g) {
                    return '<option value="' + g.id + '">' + esc(g.name) + ' (' + g.member_count + ')</option>';
                }).join('');
                if (selectedGroupId) fGroup.value = String(selectedGroupId);
            });
    }

    function openModal(cfg) {
        errBox.hidden = true; errBox.textContent = '';
        fillUserSelects();
        if (cfg) {
            document.getElementById('ar-modal-title').textContent = 'Sửa lịch gửi';
            fId.value = cfg.id;
            fTitle.value = cfg.title || '';
            fTime.value = cfg.send_time_hm || '17:00';
            fWindow.value = cfg.window_minutes || 30;
            fDelegate.value = String(cfg.delegate_user_id || '');
            fCaption.value = cfg.caption || '';
            var t = cfg.recipient_type === 'group' ? 'group' : 'users';
            var radio = document.querySelector('input[name="ar-rtype"][value="' + t + '"]');
            if (radio) radio.checked = true;
            applyRtype();
            var ids = cfg.recipient_user_ids_arr || [];
            Array.prototype.forEach.call(fUsers.options, function (o) { o.selected = ids.indexOf(parseInt(o.value, 10)) >= 0; });
            loadGroups(cfg.delegate_user_id, cfg.recipient_conversation_id);
        } else {
            document.getElementById('ar-modal-title').textContent = 'Thêm lịch gửi';
            fId.value = ''; fTitle.value = ''; fTime.value = '17:00'; fWindow.value = 30;
            fDelegate.value = ''; fCaption.value = '';
            document.querySelector('input[name="ar-rtype"][value="users"]').checked = true;
            applyRtype();
            Array.prototype.forEach.call(fUsers.options, function (o) { o.selected = false; });
            fGroup.innerHTML = '<option value="">— Chọn người uỷ quyền trước —</option>';
        }
        modal.hidden = false;
    }
    function closeModal() { modal.hidden = true; }

    function save() {
        errBox.hidden = true;
        var t = currentRtype();
        var fd = new FormData();
        fd.append('id', fId.value || '0');
        fd.append('title', fTitle.value.trim());
        fd.append('send_time', fTime.value);
        fd.append('window_minutes', fWindow.value);
        fd.append('delegate_user_id', fDelegate.value);
        fd.append('recipient_type', t);
        fd.append('caption', fCaption.value.trim());
        if (t === 'group') {
            fd.append('recipient_conversation_id', fGroup.value || '0');
        } else {
            var ids = Array.prototype.filter.call(fUsers.options, function (o) { return o.selected; })
                .map(function (o) { return parseInt(o.value, 10); });
            fd.append('recipient_user_ids', JSON.stringify(ids));
        }
        var btn = document.getElementById('ar-save-btn');
        btn.disabled = true;
        post('auto_report_save', fd).then(function (res) {
            btn.disabled = false;
            if (!res || !res.ok) { errBox.textContent = (res && res.message) || 'Lỗi lưu.'; errBox.hidden = false; return; }
            closeModal();
            reloadList();
        }).catch(function () { btn.disabled = false; errBox.textContent = 'Lỗi kết nối.'; errBox.hidden = false; });
    }

    /* ---------- Sự kiện ---------- */
    document.getElementById('ar-add-btn').addEventListener('click', function () { openModal(null); });
    document.getElementById('ar-save-btn').addEventListener('click', save);
    modal.addEventListener('click', function (e) { if (e.target.hasAttribute('data-ar-close')) closeModal(); });
    document.querySelectorAll('input[name="ar-rtype"]').forEach(function (r) { r.addEventListener('change', applyRtype); });
    fDelegate.addEventListener('change', function () { if (currentRtype() === 'group') loadGroups(fDelegate.value, 0); });

    tbody.addEventListener('click', function (e) {
        var ed = e.target.closest('[data-edit]');
        var pa = e.target.closest('[data-pause]');
        var de = e.target.closest('[data-del]');
        if (ed) {
            var id = parseInt(ed.getAttribute('data-edit'), 10);
            var cfg = configs.filter(function (c) { return parseInt(c.id, 10) === id; })[0];
            if (cfg) openModal(cfg);
            return;
        }
        if (pa) {
            var b = new URLSearchParams();
            b.append('id', pa.getAttribute('data-pause'));
            b.append('paused', pa.getAttribute('data-to'));
            pa.disabled = true;
            post('auto_report_toggle_pause', b.toString()).then(function () { reloadList(); });
            return;
        }
        if (de) {
            if (!confirm('Xoá lịch gửi này?')) return;
            var b2 = new URLSearchParams(); b2.append('id', de.getAttribute('data-del'));
            post('auto_report_delete', b2.toString()).then(function () { reloadList(); });
            return;
        }
    });

    renderTable();
})();
