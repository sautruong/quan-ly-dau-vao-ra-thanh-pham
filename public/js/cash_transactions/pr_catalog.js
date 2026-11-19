/**
 * "Nhập thu chi" → xây DANH SÁCH ĐỀ NGHỊ MUA ĐỒ.
 *   · Mỗi dòng lịch sử có "Thêm DS đề nghị" (mở modal: Mục chi · Hạng mục chi · ảnh) / "Gỡ DS đề nghị".
 *   · Nút "QL danh sách đề nghị" (cạnh trái bộ lọc từ ngày–đến ngày): thêm/sửa/xóa mục chi + hạng mục.
 * Cần pr_common.js (window.PR) và window.CtHistory (xuất từ cash_transactions.js).
 */
(function () {
    'use strict';
    if (!window.PR) return;

    var $tbody  = document.getElementById('history-tbody');
    var $manage = document.getElementById('pr-manage-btn');
    var esc = PR.esc;

    PR.get('pr_catalog').then(function (res) { if (res.ok) PR.setCatalog(res.catalog); });

    /* Modal THÊM / SỬA 1 hạng mục: dùng chung với màn "Đề nghị mua đồ" → PR.itemModal (pr_common.js).
       o: {item?, cashId?, name?, categoryId?, onSaved(item)} */
    function openItemModal(o) { return PR.itemModal(o); }

    /* ------------------------------------------------------------------
       Nút trên từng dòng lịch sử: Thêm DS đề nghị / Gỡ DS đề nghị
       ------------------------------------------------------------------ */
    if ($tbody) $tbody.addEventListener('click', function (e) {
        var add = e.target.closest('.pr-add-link');
        var rem = e.target.closest('.pr-remove-link');
        if (!add && !rem) return;
        e.preventDefault();
        var tr = e.target.closest('tr');
        var id = tr ? parseInt(tr.getAttribute('data-id'), 10) : 0;
        var row = window.CtHistory && CtHistory.getRow(id);
        if (!row) return;

        if (add) {
            openItemModal({
                cashId: id,
                name: row.description,
                onSaved: function (item) { CtHistory.setPrItem(id, item.id); }
            });
        } else {
            PR.confirm('Gỡ "' + row.description + '" khỏi danh sách đề nghị mua đồ? Các đề nghị đang chờ vẫn giữ nguyên.',
                { okText: 'Gỡ', danger: true, title: 'Gỡ DS đề nghị' }).then(function (yes) {
                if (!yes) return;
                PR.api('pr_item_remove_by_cash', { cash_id: id }).then(function (res) {
                    if (!res.ok) { PR.toast(res.message || 'Không gỡ được.', 'err'); return; }
                    PR.setCatalog(res.catalog);
                    CtHistory.setPrItem(id, 0);
                    PR.toast('Đã gỡ khỏi danh sách đề nghị.');
                });
            });
        }
    });

    /* ------------------------------------------------------------------
       QL danh sách đề nghị
       ------------------------------------------------------------------ */
    function norm(s) {
        return String(s || '').toLowerCase().normalize('NFD').replace(/[̀-ͯ]/g, '').replace(/đ/g, 'd');
    }

    function openManage() {
        var m = PR.modal({
            title: 'Quản lý danh sách đề nghị mua đồ', cls: 'pr-modal-lg',
            onClose: function () {
                // Hạng mục có thể đã bị xóa/đổi trong lúc quản lý → làm tươi nút Thêm/Gỡ trên lịch sử.
                PR.get('get_history').then(function (res) { if (res.success && window.CtHistory) CtHistory.replace(res.data); });
            }
        });
        m.body.innerHTML =
            '<div class="pr-ql-tools">' +
                '<div class="pr-ql-search"><i class="fa-solid fa-magnifying-glass"></i>' +
                    '<input type="text" class="pr-input" id="pr-ql-q" placeholder="Tìm hạng mục…" autocomplete="off"></div>' +
                '<button type="button" class="pr-btn pr-btn-ghost" data-act="add-cat"><i class="fa-solid fa-folder-plus"></i> Thêm mục chi</button>' +
                '<button type="button" class="pr-btn pr-btn-primary" data-act="add-item"><i class="fa-solid fa-plus"></i> Thêm hạng mục</button>' +
            '</div><div class="pr-ql-list" id="pr-ql-list"></div>';
        m.foot.innerHTML = '<button type="button" class="pr-btn pr-btn-ghost" data-act="close">Đóng</button>';

        var $list = m.body.querySelector('#pr-ql-list');
        var $q = m.body.querySelector('#pr-ql-q');

        function render() {
            var q = norm($q.value.trim());
            var html = '';
            PR.catalog.forEach(function (c) {
                var items = (c.items || []).filter(function (it) { return !q || norm(it.name).indexOf(q) !== -1; });
                if (q && !items.length) return;
                html += '<div class="pr-ql-cat" data-cat="' + c.id + '">' +
                    '<div class="pr-ql-cat-h"><strong>' + esc(c.name) + '</strong><span class="pr-ql-count">' + (c.items || []).length + ' hạng mục</span>' +
                    '<span class="pr-ql-cat-act">' +
                        '<button type="button" class="pr-icon-btn" data-act="edit-cat" title="Sửa tên mục chi"><i class="fa-solid fa-pen"></i></button>' +
                        '<button type="button" class="pr-icon-btn pr-icon-danger" data-act="del-cat" title="Xóa mục chi (khi đã hết hạng mục)"><i class="fa-solid fa-trash"></i></button>' +
                    '</span></div>';
                if (!items.length) {
                    html += '<div class="pr-ql-empty">Chưa có hạng mục nào.</div>';
                }
                items.forEach(function (it) {
                    html += '<div class="pr-ql-item" data-item="' + it.id + '">' +
                        '<span class="pr-ql-thumb">' + (it.image_url
                            ? '<img src="' + esc(it.image_url) + '" alt="" loading="lazy">'
                            : '<i class="fa-regular fa-image"></i>') + '</span>' +
                        '<span class="pr-ql-name">' + esc(it.name) + '</span>' +
                        '<span class="pr-ql-item-act">' +
                            '<button type="button" class="pr-icon-btn" data-act="edit-item" title="Sửa"><i class="fa-solid fa-pen"></i></button>' +
                            '<button type="button" class="pr-icon-btn pr-icon-danger" data-act="del-item" title="Xóa khỏi danh sách"><i class="fa-solid fa-trash"></i></button>' +
                        '</span></div>';
                });
                html += '</div>';
            });
            $list.innerHTML = html || '<div class="pr-ql-empty">Chưa có mục chi nào. Bấm "Thêm mục chi" để bắt đầu.</div>';
        }
        render();
        $q.addEventListener('input', render);

        function catOf(el) {
            var c = el.closest('.pr-ql-cat');
            var id = c ? +c.getAttribute('data-cat') : 0;
            return PR.catalog.filter(function (x) { return +x.id === id; })[0] || null;
        }

        m.el.addEventListener('click', function (e) {
            var b = e.target.closest('[data-act]');
            if (!b) return;
            var act = b.getAttribute('data-act');

            if (act === 'close') { m.close(); return; }
            if (act === 'add-cat') {
                PR.ask('Thêm mục chi', { placeholder: 'Tên mục chi, vd: Đồ dùng y tế', okText: 'Thêm' }).then(function (name) {
                    if (name === null) return;
                    PR.api('pr_category_save', { id: 0, name: name }).then(function (res) {
                        if (!res.ok) { PR.toast(res.message || 'Không thêm được.', 'err'); return; }
                        PR.setCatalog(res.catalog); render(); PR.toast('Đã thêm mục chi.');
                    });
                });
            } else if (act === 'add-item') {
                openItemModal({ onSaved: render });
            } else if (act === 'edit-cat') {
                var c = catOf(b); if (!c) return;
                PR.ask('Sửa tên mục chi', { value: c.name }).then(function (name) {
                    if (name === null || name === c.name) return;
                    PR.api('pr_category_save', { id: c.id, name: name }).then(function (res) {
                        if (!res.ok) { PR.toast(res.message || 'Không sửa được.', 'err'); return; }
                        PR.setCatalog(res.catalog); render(); PR.toast('Đã đổi tên mục chi.');
                    });
                });
            } else if (act === 'del-cat') {
                var c2 = catOf(b); if (!c2) return;
                PR.confirm('Xóa mục chi "' + c2.name + '"?', { okText: 'Xóa', danger: true }).then(function (yes) {
                    if (!yes) return;
                    PR.api('pr_category_delete', { id: c2.id }).then(function (res) {
                        if (!res.ok) { PR.toast(res.message || 'Không xóa được.', 'err'); return; }
                        PR.setCatalog(res.catalog); render(); PR.toast('Đã xóa mục chi.');
                    });
                });
            } else if (act === 'edit-item') {
                var it = PR.findItem(b.closest('.pr-ql-item').getAttribute('data-item'));
                if (it) openItemModal({ item: it, onSaved: render });
            } else if (act === 'del-item') {
                var it2 = PR.findItem(b.closest('.pr-ql-item').getAttribute('data-item'));
                if (!it2) return;
                PR.confirm('Xóa "' + it2.name + '" khỏi danh sách đề nghị? Các đề nghị đang chờ vẫn giữ nguyên.',
                    { okText: 'Xóa', danger: true }).then(function (yes) {
                    if (!yes) return;
                    PR.api('pr_item_delete', { id: it2.id }).then(function (res) {
                        if (!res.ok) { PR.toast(res.message || 'Không xóa được.', 'err'); return; }
                        PR.setCatalog(res.catalog); render(); PR.toast('Đã xóa hạng mục.');
                    });
                });
            }
        });
    }

    if ($manage) $manage.addEventListener('click', openManage);
})();
