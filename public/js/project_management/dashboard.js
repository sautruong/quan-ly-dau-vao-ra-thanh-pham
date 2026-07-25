/* ===== Quản lý dự án — Dashboard ===== */
(function () {
    'use strict';
    var BASE = (window.PM_CONFIG && window.PM_CONFIG.baseUrl) || '?mod=project_management&controllers=project&action=';
    var projects = (window.PM_DATA && window.PM_DATA.projects) || [];

    var $grid = document.getElementById('pm-grid');
    var $empty = document.getElementById('pm-empty');
    var $tpl = document.getElementById('pm-card-tpl');
    var $search = document.getElementById('pm-search');

    function esc(s) { return String(s == null ? '' : s).replace(/[&<>"]/g, function (c) { return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;' }[c]; }); }

    function post(action, data) {
        var body = new URLSearchParams();
        Object.keys(data || {}).forEach(function (k) { body.append(k, data[k]); });
        return fetch(BASE + action, { method: 'POST', headers: { 'Content-Type': 'application/x-www-form-urlencoded' }, body: body })
            .then(function (r) { return r.json(); });
    }

    function detailUrl(id) { return BASE + 'detail&id=' + id; }
    // action= cho detail: BASE đã có 'action=', cần ghép 'detail&id='. BASE kết thúc bằng 'action='.

    function render(list) {
        $grid.innerHTML = '';
        if (!list.length) { $empty.style.display = ''; return; }
        $empty.style.display = 'none';
        list.forEach(function (p) {
            var node = $tpl.content.firstElementChild.cloneNode(true);
            node.dataset.id = p.id;
            node.querySelector('.pm-card-name').textContent = p.name;
            node.querySelector('.pm-card-desc').textContent = p.description || 'Không có mô tả.';
            var badge = node.querySelector('.pm-role-badge');
            if (p.my_role !== 'leader') badge.classList.add('member');
            node.querySelector('.pm-mc').textContent = p.member_count;
            node.querySelector('.pm-sc').textContent = p.session_count || 0;
            // Click card = mở dự án.
            node.addEventListener('click', function () { window.location.href = detailUrl(p.id); });
            // Cụm thao tác (chỉ trưởng dự án): sửa + xóa.
            var actions = node.querySelector('.pm-card-actions');
            if (p.my_role !== 'leader') { actions.style.display = 'none'; }
            else {
                node.querySelector('.pm-card-edit').addEventListener('click', function (e) { e.stopPropagation(); openEdit(p, node); });
                var del = node.querySelector('.pm-card-del');
                del.addEventListener('click', function (e) {
                    e.stopPropagation();
                    if (!confirm('Xóa dự án "' + p.name + '"? Dự án sẽ bị gỡ khỏi danh sách.')) return;
                    del.disabled = true;
                    post('archiveProject', { project_id: p.id }).then(function (res) {
                        if (res.ok) {
                            projects = projects.filter(function (x) { return x.id !== p.id; });
                            node.remove();
                            if (!projects.length) $empty.style.display = '';
                        } else { del.disabled = false; alert(res.message || 'Không xóa được dự án.'); }
                    });
                });
            }
            $grid.appendChild(node);
        });
    }

    render(projects);

    // Tìm kiếm
    if ($search) {
        $search.addEventListener('input', function () {
            var q = this.value.trim().toLowerCase();
            render(projects.filter(function (p) {
                return (p.name + ' ' + (p.description || '')).toLowerCase().indexOf(q) !== -1;
            }));
        });
    }

    /* ===== Modal tạo / sửa dự án ===== */
    var $modal = document.getElementById('pm-modal');
    var $modalTitle = document.getElementById('pm-modal-title');
    var $name = document.getElementById('pm-f-name');
    var $desc = document.getElementById('pm-f-desc');
    var $save = document.getElementById('pm-save');
    var editingId = 0, editingNode = null;

    function openCreate() {
        editingId = 0; editingNode = null;
        $modalTitle.textContent = 'Tạo dự án mới'; $save.textContent = 'Lưu';
        $name.value = ''; $desc.value = '';
        $modal.style.display = ''; setTimeout(function () { $name.focus(); }, 50);
    }
    function openEdit(p, node) {
        editingId = p.id; editingNode = node;
        $modalTitle.textContent = 'Sửa dự án'; $save.textContent = 'Lưu thay đổi';
        $name.value = p.name; $desc.value = p.description || '';
        $modal.style.display = ''; setTimeout(function () { $name.focus(); }, 50);
    }
    function closeModal() { $modal.style.display = 'none'; }

    document.getElementById('pm-new-project').addEventListener('click', openCreate);
    document.getElementById('pm-modal-close').addEventListener('click', closeModal);
    document.getElementById('pm-cancel').addEventListener('click', closeModal);
    $modal.addEventListener('click', function (e) { if (e.target === $modal) closeModal(); });

    $save.addEventListener('click', function () {
        var name = $name.value.trim();
        if (!name) { $name.focus(); return; }
        var dsc = $desc.value.trim();
        var btn = this; btn.disabled = true;
        if (editingId) {
            post('updateProject', { project_id: editingId, name: name, description: dsc }).then(function (res) {
                btn.disabled = false;
                if (!res.ok) { alert(res.message || 'Không lưu được.'); return; }
                var p = projects.filter(function (x) { return x.id === editingId; })[0];
                if (p) { p.name = name; p.description = dsc; }
                if (editingNode) {
                    editingNode.querySelector('.pm-card-name').textContent = name;
                    editingNode.querySelector('.pm-card-desc').textContent = dsc || 'Không có mô tả.';
                }
                closeModal();
            });
        } else {
            post('createProject', { name: name, description: dsc }).then(function (res) {
                btn.disabled = false;
                if (res.ok) { window.location.href = detailUrl(res.project.id); }
                else alert(res.message || 'Không tạo được dự án.');
            });
        }
    });

    $name.addEventListener('keydown', function (e) { if (e.key === 'Enter') $save.click(); });
})();
