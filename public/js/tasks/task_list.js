/* =====================================================================
 *  task_list.js — Việc cần làm / Danh sách công việc (giống Google Tasks)
 *  - Mỗi nhóm (card) dàn ngang; tạo / đổi tên / xóa / xóa việc đã xong.
 *  - Việc lever 1 (có nhắc hẹn) + việc lever 2 (việc con).
 *  - Kéo-thả việc giữa các nhóm kiểu Trello.
 *  - Đánh dấu hoàn thành -> dồn xuống "Đã hoàn thành (n)".
 *  Dữ liệu cá nhân hóa: backend tự lọc theo user đăng nhập.
 * ===================================================================== */
(function () {
    'use strict';

    var CFG = window.TK_CONFIG || { baseUrl: '?mod=tasks&controllers=tasks&action=' };
    var state = { groups: (window.TK_DATA && window.TK_DATA.groups) || [] };

    /* ---------------- DOM refs ---------------- */
    var board       = document.getElementById('tk-board');
    var cardTpl     = document.getElementById('tk-card-tpl');
    var itemTpl     = document.getElementById('tk-item-tpl');
    var subTpl      = document.getElementById('tk-subitem-tpl');

    /* ---------------- API ---------------- */
    function api(action, payload) {
        var body = new URLSearchParams();
        Object.keys(payload || {}).forEach(function (k) {
            var v = payload[k];
            body.append(k, (v === null || v === undefined) ? '' : v);
        });
        return fetch(CFG.baseUrl + action, {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            credentials: 'same-origin',
            body: body.toString()
        }).then(function (r) { return r.json(); });
    }

    /* ---------------- State helpers ---------------- */
    function findGroup(gid) {
        gid = +gid;
        for (var i = 0; i < state.groups.length; i++)
            if (state.groups[i].id === gid) return state.groups[i];
        return null;
    }
    // Tìm item (lever 1 hoặc lever 2) + thông tin vị trí.
    function locateItem(id) {
        id = +id;
        for (var g = 0; g < state.groups.length; g++) {
            var items = state.groups[g].items;
            for (var i = 0; i < items.length; i++) {
                if (items[i].id === id) return { group: state.groups[g], list: items, idx: i, item: items[i], parent: null };
                var ch = items[i].children || [];
                for (var j = 0; j < ch.length; j++)
                    if (ch[j].id === id) return { group: state.groups[g], list: ch, idx: j, item: ch[j], parent: items[i] };
            }
        }
        return null;
    }

    /* ---------------- Date helpers (nhắc hẹn) ---------------- */
    function toLocalInput(mysql) {
        if (!mysql) return '';
        return mysql.replace(' ', 'T').slice(0, 16);
    }
    function fmtRemind(mysql) {
        if (!mysql) return '';
        var d = new Date(mysql.replace(' ', 'T'));
        if (isNaN(d)) return mysql;
        var p = function (n) { return (n < 10 ? '0' : '') + n; };
        return p(d.getDate()) + '/' + p(d.getMonth() + 1) + ' ' + p(d.getHours()) + ':' + p(d.getMinutes());
    }
    function isOverdue(mysql) {
        if (!mysql) return false;
        var d = new Date(mysql.replace(' ', 'T'));
        return !isNaN(d) && d.getTime() < Date.now();
    }

    /* =================================================================
     *  RENDER
     * ================================================================= */
    function renderBoard() {
        board.innerHTML = '';
        state.groups.forEach(function (g) { board.appendChild(buildCard(g)); });
    }

    function rebuildCard(gid) {
        var g = findGroup(gid);
        if (!g) return;
        var old = board.querySelector('.tk-card[data-group-id="' + gid + '"]');
        var fresh = buildCard(g);
        if (old) board.replaceChild(fresh, old);
        else board.appendChild(fresh);
    }

    function buildCard(g) {
        var card = cardTpl.content.firstElementChild.cloneNode(true);
        card.dataset.groupId = g.id;
        card.querySelector('.tk-card-title').textContent = g.title;

        var list = card.querySelector('.tk-list');
        var doneList = card.querySelector('.tk-done-list');
        var doneWrap = card.querySelector('.tk-done-wrap');
        var doneCount = 0;

        (g.items || []).forEach(function (it) {
            if (it.is_done) {
                doneList.appendChild(buildItem(it, true));
                doneCount++;
            } else {
                list.appendChild(buildItem(it, false));
            }
        });

        if (doneCount > 0) {
            doneWrap.hidden = false;
            card.querySelector('.tk-done-count').textContent = doneCount;
        }
        return card;
    }

    function buildItem(it, isDoneRender) {
        var li = itemTpl.content.firstElementChild.cloneNode(true);
        li.dataset.id = it.id;
        var check = li.querySelector('.tk-check');
        var text  = li.querySelector('.tk-item-text');
        text.textContent = it.content;
        if (it.is_done) { li.classList.add('done'); check.classList.add('checked'); }

        // Nhắc hẹn (chỉ lever 1) — chuông trên .tk-item-row chỉ hiện khi hover, trừ khi đã có nhắc hẹn (has-remind).
        if (it.remind_at) {
            var tag = li.querySelector('.tk-remind-tag');
            tag.hidden = false;
            tag.textContent = '🔔 ' + fmtRemind(it.remind_at);
            var remindBtn = li.querySelector('.tk-item-remind-btn');
            remindBtn.classList.add('has-remind');
            if (isOverdue(it.remind_at) && !it.is_done) {
                tag.classList.add('overdue');
                remindBtn.classList.add('overdue');
            }
        }

        // Lever 2 — luôn hiển thị (không đóng mở)
        var sublist = li.querySelector('.tk-sublist');
        sublist.dataset.parent = it.id;
        (it.children || []).forEach(function (c) { sublist.appendChild(buildSub(c)); });

        // Nếu render trong khu "đã hoàn thành": gọn — ẩn ô thêm việc con.
        if (isDoneRender) {
            li.querySelector('.tk-item-text').style.cursor = 'default';
            li.querySelector('.tk-sub-input').hidden = true;
        }
        return li;
    }

    function buildSub(c) {
        var li = subTpl.content.firstElementChild.cloneNode(true);
        li.dataset.id = c.id;
        li.querySelector('.tk-subitem-text').textContent = c.content;
        if (c.is_done) { li.classList.add('done'); li.querySelector('.tk-check').classList.add('checked'); }
        // Mô tả sẵn có của việc lever 2.
        if (c.description) {
            var d = li.querySelector('.tk-sub-desc');
            d.value = c.description;
            d.hidden = false;
        }
        return li;
    }

    /* =================================================================
     *  TẠO NHÓM
     * ================================================================= */
    document.getElementById('tk-new-group').addEventListener('click', function () {
        api('groupCreate', { title: 'Danh sách của tôi' }).then(function (res) {
            if (!res.success) return alert(res.message || 'Lỗi.');
            state.groups.push(res.group);
            rebuildCard(res.group.id);
            // Vào đổi tên ngay.
            startRename(board.querySelector('.tk-card[data-group-id="' + res.group.id + '"]'));
        });
    });

    /* =================================================================
     *  EVENT DELEGATION trên board
     * ================================================================= */
    board.addEventListener('click', function (e) {
        var card = e.target.closest('.tk-card');
        if (!card) return;
        var gid = +card.dataset.groupId;

        // --- Menu kebab ---
        if (e.target.closest('.tk-card-menu-btn')) {
            e.stopPropagation();
            var menu = card.querySelector('.tk-card-menu');
            closeAllMenus(menu);
            menu.classList.toggle('open');
            return;
        }
        var menuItem = e.target.closest('.tk-card-menu li');
        if (menuItem) {
            card.querySelector('.tk-card-menu').classList.remove('open');
            handleMenuAction(card, gid, menuItem.dataset.act);
            return;
        }

        // --- Nút "Thêm việc cần làm" ---
        if (e.target.closest('.tk-add-task')) {
            openAddBox(card);
            return;
        }
        if (e.target.closest('.tk-add-cancel')) {
            closeAddBox(card);
            return;
        }

        // --- Toggle "Đã hoàn thành (n)" ---
        if (e.target.closest('.tk-done-toggle')) {
            var dw = card.querySelector('.tk-done-wrap');
            dw.classList.toggle('open');
            card.querySelector('.tk-done-list').hidden = !dw.classList.contains('open');
            return;
        }

        // --- Check item (lever 1 & 2) ---
        var check = e.target.closest('.tk-check');
        if (check) {
            var li = check.closest('.tk-item, .tk-subitem');
            toggleItem(+li.dataset.id);
            return;
        }

        // --- Xóa item ---
        if (e.target.closest('.tk-item-del')) {
            var li2 = e.target.closest('.tk-item, .tk-subitem');
            deleteItem(+li2.dataset.id);
            return;
        }

        // --- Bút sửa tiêu đề (hover .tk-item-row / .tk-subitem-row) ---
        if (e.target.closest('.tk-item-edit-btn')) {
            var liEdit = e.target.closest('.tk-item');
            if (liEdit) startItemTitleEdit(liEdit);
            return;
        }
        if (e.target.closest('.tk-subitem-edit-btn')) {
            var subEdit = e.target.closest('.tk-subitem');
            if (subEdit) startSubTitleEdit(subEdit);
            return;
        }

        // --- Chuông nhắc hẹn (hover .tk-item-row) -> mở vùng sửa, focus thẳng ô nhắc hẹn ---
        if (e.target.closest('.tk-item-remind-btn')) {
            var liRemind = e.target.closest('.tk-item');
            if (liRemind && !liRemind.closest('.tk-done-list')) openItemEdit(liRemind, 'remind');
            return;
        }

        // --- Click text lever 1 -> hiện vùng sửa (mô tả + nhắc hẹn) ---
        var txt = e.target.closest('.tk-item-text');
        if (txt) {
            var liItem = txt.closest('.tk-item');
            if (liItem && !liItem.closest('.tk-done-list')) openItemEdit(liItem);
            return;
        }
        // --- Click text lever 2 -> hiện ô nhập mô tả ---
        var stxt = e.target.closest('.tk-subitem-text');
        if (stxt) {
            openSubDesc(stxt.closest('.tk-subitem'));
            return;
        }
    });

    // Nhấp đúp tiêu đề -> đổi tên
    board.addEventListener('dblclick', function (e) {
        var title = e.target.closest('.tk-card-title');
        if (title) startRename(title.closest('.tk-card'));
    });

    document.addEventListener('click', function (e) {
        if (!e.target.closest('.tk-card-menu-wrap')) closeAllMenus(null);
    });

    function closeAllMenus(except) {
        board.querySelectorAll('.tk-card-menu.open').forEach(function (m) {
            if (m !== except) m.classList.remove('open');
        });
    }

    /* =================================================================
     *  MENU NHÓM: đổi tên / xóa / xóa việc hoàn thành
     * ================================================================= */
    function handleMenuAction(card, gid, act) {
        if (act === 'rename') {
            startRename(card);
        } else if (act === 'delete') {
            if (!confirm('Xóa nhóm công việc này và toàn bộ việc bên trong?')) return;
            api('groupDelete', { id: gid }).then(function (res) {
                if (!res.success) return alert('Lỗi.');
                state.groups = state.groups.filter(function (g) { return g.id !== gid; });
                card.remove();
            });
        } else if (act === 'clear-done') {
            api('groupClearDone', { id: gid }).then(function (res) {
                if (!res.success) return alert('Lỗi.');
                var g = findGroup(gid);
                if (g) {
                    g.items = g.items.filter(function (it) { return !it.is_done; });
                    g.items.forEach(function (it) {
                        it.children = (it.children || []).filter(function (c) { return !c.is_done; });
                    });
                    rebuildCard(gid);
                }
            });
        }
    }

    function startRename(card) {
        var titleEl = card.querySelector('.tk-card-title');
        if (titleEl.querySelector('input')) return;
        var cur = titleEl.textContent;
        titleEl.innerHTML = '';
        var inp = document.createElement('input');
        inp.type = 'text';
        inp.value = cur;
        inp.maxLength = 255;
        titleEl.appendChild(inp);
        inp.focus();
        inp.select();

        function commit(save) {
            var val = inp.value.trim();
            if (!save || val === '') { titleEl.textContent = cur; return; }
            titleEl.textContent = val;
            var g = findGroup(card.dataset.groupId);
            if (g) g.title = val;
            api('groupRename', { id: card.dataset.groupId, title: val });
        }
        inp.addEventListener('keydown', function (ev) {
            if (ev.key === 'Enter') { ev.preventDefault(); commit(true); }
            else if (ev.key === 'Escape') { commit(false); }
        });
        inp.addEventListener('blur', function () { commit(true); });
    }

    /* =================================================================
     *  SỬA TIÊU ĐỀ VIỆC (bút hiện khi hover .tk-item-row / .tk-subitem-row)
     * ================================================================= */
    function startItemTitleEdit(li) {
        var loc = locateItem(+li.dataset.id);
        if (!loc) return;
        editTitleInline(li.querySelector('.tk-item-text'), loc.item, li);
    }
    function startSubTitleEdit(li) {
        var loc = locateItem(+li.dataset.id);
        if (!loc) return;
        editTitleInline(li.querySelector('.tk-subitem-text'), loc.item, li);
    }
    // Dùng chung cho cả lever 1 (.tk-item-text) và lever 2 (.tk-subitem-text) — cùng field content.
    function editTitleInline(textEl, item, li) {
        if (textEl.querySelector('input')) return;
        var cur = textEl.textContent;
        textEl.innerHTML = '';
        var inp = document.createElement('input');
        inp.type = 'text';
        inp.value = cur;
        inp.maxLength = 500;
        textEl.appendChild(inp);
        inp.focus();
        inp.select();

        function commit(save) {
            var val = inp.value.trim();
            if (!save || val === '') { textEl.textContent = cur; return; }
            textEl.textContent = val;
            item.content = val;
            api('itemUpdate', { id: item.id, content: val });
        }
        inp.addEventListener('click', function (ev) { ev.stopPropagation(); });
        inp.addEventListener('keydown', function (ev) {
            if (ev.key === 'Enter') { ev.preventDefault(); commit(true); }
            else if (ev.key === 'Escape') { commit(false); }
        });
        inp.addEventListener('blur', function () { commit(true); });
    }

    /* =================================================================
     *  THÊM VIỆC LEVER 1
     * ================================================================= */
    function openAddBox(card) {
        var box = card.querySelector('.tk-add-box');
        box.hidden = false;
        var inp = box.querySelector('.tk-add-input');
        inp.value = '';
        inp.focus();   // (#2) focus sẵn để gõ ngay — nhắc hẹn không còn đặt ở đây, dời sang chuông hover trên .tk-item-row
    }
    function closeAddBox(card) {
        card.querySelector('.tk-add-box').hidden = true;
    }

    // Click ra ngoài -> ẩn ô thêm việc nếu để trống.
    board.addEventListener('focusout', function (e) {
        var addBox = e.target.closest('.tk-add-box');
        if (addBox) {
            setTimeout(function () {
                if (!addBox.contains(document.activeElement)) addBox.hidden = true;
            }, 150);
        }
    });

    board.addEventListener('keydown', function (e) {
        // Enter trong ô thêm việc lever 1
        if (e.target.classList.contains('tk-add-input') && e.key === 'Enter') {
            e.preventDefault();
            var card = e.target.closest('.tk-card');
            var content = e.target.value.trim();
            if (!content) return;
            addLever1(card, content);
            e.target.value = '';
            e.target.focus();
            return;
        }
        // Enter trong ô thêm việc lever 2
        if (e.target.classList.contains('tk-sub-input') && e.key === 'Enter') {
            e.preventDefault();
            var content2 = e.target.value.trim();
            if (!content2) return;
            var parentLi = e.target.closest('.tk-item');
            addLever2(parentLi, content2);
            e.target.value = '';
            e.target.focus();
        }
    });

    function addLever1(card, content) {
        var gid = +card.dataset.groupId;
        api('itemCreate', { group_id: gid, parent_id: 0, content: content, remind_at: '' })
            .then(function (res) {
                if (!res.success) return alert(res.message || 'Lỗi.');
                var g = findGroup(gid);
                if (g) g.items.push(res.item);
                // Chèn vào DOM (cuối danh sách active)
                card.querySelector('.tk-list').appendChild(buildItem(res.item, false));
            });
    }

    function addLever2(parentLi, content) {
        var pid = +parentLi.dataset.id;
        var loc = locateItem(pid);
        if (!loc) return;
        api('itemCreate', { group_id: loc.group.id, parent_id: pid, content: content })
            .then(function (res) {
                if (!res.success) return alert(res.message || 'Lỗi.');
                loc.item.children = loc.item.children || [];
                loc.item.children.push(res.item);
                parentLi.querySelector('.tk-sublist').appendChild(buildSub(res.item));
            });
    }

    /* =================================================================
     *  TOGGLE / DELETE
     * ================================================================= */
    function toggleItem(id) {
        var loc = locateItem(id);
        if (!loc) return;
        var done = loc.item.is_done ? 0 : 1;
        loc.item.is_done = done;
        if (loc.parent === null) {
            // Lever 1: kéo theo việc con + đổi khu active/done -> rebuild card.
            (loc.item.children || []).forEach(function (c) { c.is_done = done; });
            rebuildCard(loc.group.id);
        } else {
            // Lever 2: chỉ gạch bỏ tại chỗ.
            var li = board.querySelector('.tk-subitem[data-id="' + id + '"]');
            if (li) {
                li.classList.toggle('done', !!done);
                li.querySelector('.tk-check').classList.toggle('checked', !!done);
            }
        }
        api('itemToggle', { id: id, done: done });
    }

    function deleteItem(id) {
        var loc = locateItem(id);
        if (!loc) return;
        loc.list.splice(loc.idx, 1);
        api('itemDelete', { id: id }).then(function () {
            rebuildCard(loc.group.id);
        });
    }

    /* =================================================================
     *  SỬA (mô tả + nhắc hẹn) — lever 1 ; mô tả — lever 2
     * ================================================================= */
    // Click nội dung lever 1 -> hiện vùng sửa, focus ô mô tả.
    function openItemEdit(li, focusField) {
        var loc = locateItem(+li.dataset.id);
        if (!loc) return;
        var edit = li.querySelector('.tk-item-edit');
        edit.hidden = false;

        var desc = edit.querySelector('.tk-desc');
        desc.value = loc.item.description || '';

        var remindInput = edit.querySelector('.tk-detail-remind-input');
        var remindClear = edit.querySelector('.tk-remind-clear');
        remindInput.value = toLocalInput(loc.item.remind_at);
        remindClear.hidden = !loc.item.remind_at;

        if (focusField === 'remind') remindInput.focus(); else desc.focus();

        // Lưu mô tả + (nếu trống & không có nhắc hẹn) ẩn vùng sửa khi rời ô.
        desc.onblur = function () {
            var v = desc.value.trim();
            if (v !== (loc.item.description || '')) {
                loc.item.description = v;
                api('itemUpdate', { id: loc.item.id, description: v });
            }
            maybeCollapseEdit(li, loc.item);
        };
        remindInput.onchange = function () {
            loc.item.remind_at = remindInput.value ? remindInput.value.replace('T', ' ') + ':00' : null;
            remindClear.hidden = !remindInput.value;
            api('itemUpdate', { id: loc.item.id, remind_at: remindInput.value });
            refreshRemindTag(li, loc.item);
        };
        remindClear.onclick = function () {
            remindInput.value = '';
            loc.item.remind_at = null;
            remindClear.hidden = true;
            api('itemUpdate', { id: loc.item.id, remind_at: '' });
            refreshRemindTag(li, loc.item);
        };
    }

    // Ẩn vùng sửa khi click ra ngoài nếu không có mô tả lẫn nhắc hẹn.
    function maybeCollapseEdit(li, item) {
        setTimeout(function () {
            var edit = li.querySelector('.tk-item-edit');
            if (!edit || edit.contains(document.activeElement)) return;
            var hasDesc = (item.description || '').trim() !== '';
            if (!hasDesc && !item.remind_at) edit.hidden = true;
        }, 150);
    }

    // Click nội dung lever 2 -> hiện ô nhập mô tả, ẩn lại nếu để trống.
    function openSubDesc(li) {
        var loc = locateItem(+li.dataset.id);
        if (!loc) return;
        var d = li.querySelector('.tk-sub-desc');
        d.hidden = false;
        d.value = loc.item.description || '';
        d.focus();
        d.onblur = function () {
            var v = d.value.trim();
            if (v !== (loc.item.description || '')) {
                loc.item.description = v;
                api('itemUpdate', { id: loc.item.id, description: v });
            }
            if (v === '') d.hidden = true;
        };
    }

    function refreshRemindTag(li, item) {
        var tag = li.querySelector('.tk-remind-tag');
        var remindBtn = li.querySelector('.tk-item-remind-btn');
        if (item.remind_at) {
            tag.hidden = false;
            tag.textContent = '🔔 ' + fmtRemind(item.remind_at);
            remindBtn.classList.add('has-remind');
            var od = isOverdue(item.remind_at) && !item.is_done;
            tag.classList.toggle('overdue', od);
            remindBtn.classList.toggle('overdue', od);
        } else {
            tag.hidden = true;
            remindBtn.classList.remove('has-remind', 'overdue');
        }
    }

    /* =================================================================
     *  KÉO-THẢ (Trello): việc giữa các nhóm + sắp xếp
     * ================================================================= */
    var dragId = null, dragHasChildren = false;

    // Giữ nguyên kích thước mọi card trong lúc kéo (không cho co lại).
    function freezeCards(on) {
        board.querySelectorAll('.tk-card').forEach(function (c) {
            c.style.minHeight = on ? (c.offsetHeight + 'px') : '';
        });
    }

    board.addEventListener('dragstart', function (e) {
        // Đang gõ trong ô nhập / mô tả -> không khởi động kéo.
        if (e.target.closest('input, textarea')) { e.preventDefault(); return; }

        var li = e.target.closest('.tk-item, .tk-subitem');
        if (li) {
            dragId = +li.dataset.id;
            var loc = locateItem(dragId);
            dragHasChildren = !!(loc && loc.item.children && loc.item.children.length);
            li.classList.add('tk-dragging');
            freezeCards(true);   // (#7) card không tự co lại khi kéo
            e.dataTransfer.effectAllowed = 'move';
            e.dataTransfer.setData('text/plain', String(dragId));
            return;
        }
        // Kéo cả card nhóm (sắp xếp hàng ngang)
        var head = e.target.closest('.tk-card-head');
        if (head) {
            var card = head.closest('.tk-card');
            dragId = null;
            card.classList.add('tk-card-dragging');
            e.dataTransfer.effectAllowed = 'move';
            e.dataTransfer.setData('text/plain', 'card:' + card.dataset.groupId);
        }
    });

    board.addEventListener('dragend', function () {
        board.querySelectorAll('.tk-dragging, .tk-card-dragging').forEach(function (el) {
            el.classList.remove('tk-dragging', 'tk-card-dragging');
        });
        board.querySelectorAll('.tk-drop-hover, .tk-card-drop-target').forEach(function (el) {
            el.classList.remove('tk-drop-hover', 'tk-card-drop-target');
        });
        freezeCards(false);
        dragId = null; dragHasChildren = false;
    });

    board.addEventListener('dragover', function (e) {
        // Sắp xếp card
        if (dragId === null) {
            var overCard = e.target.closest('.tk-card');
            if (overCard && !overCard.classList.contains('tk-card-dragging')) {
                e.preventDefault();
            }
            return;
        }
        // Kéo item: chỉ thả vào list lever1, hoặc sublist (nếu item không có con)
        var cont = e.target.closest('.tk-list, .tk-sublist');
        if (!cont) return;
        if (cont.classList.contains('tk-sublist') && dragHasChildren) return; // tránh lồng 3 cấp
        if (cont.closest('.tk-done-list')) return;
        e.preventDefault();
        board.querySelectorAll('.tk-drop-hover').forEach(function (el) { el.classList.remove('tk-drop-hover'); });
        cont.classList.add('tk-drop-hover');

        var after = getDragAfter(cont, e.clientY);
        var dragging = board.querySelector('.tk-dragging');
        if (!dragging) return;
        if (after == null) cont.appendChild(dragging);
        else cont.insertBefore(dragging, after);
    });

    board.addEventListener('drop', function (e) {
        e.preventDefault();
        // --- Thả CARD (sắp xếp nhóm) ---
        var raw = e.dataTransfer.getData('text/plain');
        if (raw && raw.indexOf('card:') === 0) {
            var gidMoved = +raw.slice(5);
            var dragging = board.querySelector('.tk-card-dragging');
            var overCard = e.target.closest('.tk-card');
            if (dragging && overCard && overCard !== dragging) {
                var rect = overCard.getBoundingClientRect();
                if (e.clientX < rect.left + rect.width / 2) board.insertBefore(dragging, overCard);
                else board.insertBefore(dragging, overCard.nextSibling);
            }
            // Cập nhật thứ tự state + lưu.
            var orderG = Array.prototype.map.call(board.querySelectorAll('.tk-card'), function (c) { return +c.dataset.groupId; });
            state.groups.sort(function (a, b) { return orderG.indexOf(a.id) - orderG.indexOf(b.id); });
            api('groupsReorder', { ids: JSON.stringify(orderG) });
            return;
        }

        // --- Thả ITEM ---
        var cont = e.target.closest('.tk-list, .tk-sublist');
        if (!cont || dragId === null) return;
        if (cont.classList.contains('tk-sublist') && dragHasChildren) return;

        var targetCard = cont.closest('.tk-card');
        var targetGid = +targetCard.dataset.groupId;
        var targetParent = cont.classList.contains('tk-sublist') ? +cont.dataset.parent : 0;

        // Di chuyển trong state.
        var loc = locateItem(dragId);
        if (!loc) return;
        var moved = loc.item;
        loc.list.splice(loc.idx, 1);                // gỡ khỏi vị trí cũ
        moved.parent_id = targetParent;
        moved.group_id = targetGid;

        var destList;
        if (targetParent === 0) {
            destList = findGroup(targetGid).items;
        } else {
            var pLoc = locateItem(targetParent);
            destList = (pLoc.item.children = pLoc.item.children || []);
        }
        // Vị trí chèn = theo thứ tự DOM hiện tại.
        var domOrder = Array.prototype.map.call(cont.children, function (n) { return +n.dataset.id; });
        destList.push(moved);
        destList.sort(function (a, b) { return domOrder.indexOf(a.id) - domOrder.indexOf(b.id); });

        // Lưu thứ tự thùng đích.
        api('reorder', {
            group_id: targetGid,
            parent_id: targetParent,
            ids: JSON.stringify(domOrder)
        }).then(function () {
            // Rebuild cả 2 card để render lại đúng cấu trúc/khu hoàn thành.
            rebuildCard(loc.group.id);
            if (targetGid !== loc.group.id) rebuildCard(targetGid);
        });
    });

    function getDragAfter(container, y) {
        var els = Array.prototype.slice.call(
            container.querySelectorAll('.tk-item:not(.tk-dragging), .tk-subitem:not(.tk-dragging)')
        );
        var closest = { offset: -Infinity, el: null };
        els.forEach(function (child) {
            var box = child.getBoundingClientRect();
            var offset = y - box.top - box.height / 2;
            if (offset < 0 && offset > closest.offset) closest = { offset: offset, el: child };
        });
        return closest.el;
    }

    /* =================================================================
     *  NHẮC HẸN — quét định kỳ, thông báo việc tới hạn
     * ================================================================= */
    var notified = {};
    function checkReminders() {
        var now = Date.now();
        state.groups.forEach(function (g) {
            (g.items || []).forEach(function (it) {
                if (it.is_done || !it.remind_at || notified[it.id]) return;
                var d = new Date(it.remind_at.replace(' ', 'T'));
                if (!isNaN(d) && d.getTime() <= now) {
                    notified[it.id] = true;
                    // Tô đỏ tag.
                    var li = board.querySelector('.tk-item[data-id="' + it.id + '"]');
                    if (li) refreshRemindTag(li, it);
                    if (window.Notification && Notification.permission === 'granted') {
                        try { new Notification('Việc cần làm tới hạn', { body: it.content }); } catch (e) {}
                    }
                }
            });
        });
    }
    if (window.Notification && Notification.permission === 'default') {
        try { Notification.requestPermission(); } catch (e) {}
    }
    // Nhắc việc tới hạn: giữ 30s nhưng dừng khi tab bị ẩn (AppPoll — app_shell.js).
    if (window.AppPoll) window.AppPoll.every('task-reminders', checkReminders, { interval: 30000, maxInterval: 30000 });
    else setInterval(checkReminders, 30000);

    /* ---------------- Khởi tạo ---------------- */
    renderBoard();
    checkReminders();
})();
