/* =====================================================================
 *  OFFICE — list.js
 *  Danh sách dạng thẻ dùng chung cho Docs và Sheets (tab Của tôi /
 *  Được chia sẻ / Đã chia sẻ): tạo mới, đổi tên tại chỗ (hover hiện bút
 *  chì), xóa, gắn sao, chia sẻ ngay trên thẻ (nội bộ hoặc qua Chat),
 *  thêm/bỏ thư viện, rời chia sẻ để dọn dẹp, tải xuống (.doc/.xlsx / PDF).
 * ===================================================================== */
(function () {
    'use strict';

    var ACT = '?mod=office&controllers=office&action=';
    var EDITOR = ACT + 'editor&id=';

    // `selected`: map id (string) => true — các thẻ đang tích chọn để xóa hàng loạt, giữ nguyên
    // xuyên suốt các lần render()/đổi tab (chỉ xóa khi bulkDelete() thành công), KHÔNG reset mỗi
    // lần vẽ lại danh sách.
    var state = { type: 'doc', mine: [], shared: [], byme: [], tab: 'mine', selected: {} };

    function $(s, r) { return (r || document).querySelector(s); }
    function $all(s, r) { return Array.prototype.slice.call((r || document).querySelectorAll(s)); }
    function esc(s) {
        return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) {
            return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c];
        });
    }

    /** 0-based (0=A, 1=B, ..., 26=AA...) → "A".."Z".."AA"... — dùng để đọc ô tiêu đề (row 1) của
     *  1 sheet lúc dò cột [key] cho "Trộn file", không phụ thuộc sheets_editor.js (list.js không
     *  nạp file đó). */
    function colLetter(c) {
        var s = '', n = c;
        do { s = String.fromCharCode(65 + (n % 26)) + s; n = Math.floor(n / 26) - 1; } while (n >= 0);
        return s;
    }

    function toast(msg, ok) {
        var t = $('#of-toast');
        t.textContent = msg;
        t.className = 'of-toast show' + (ok === false ? ' err' : '');
        clearTimeout(toast._t);
        toast._t = setTimeout(function () { t.className = 'of-toast'; }, 2600);
    }

    function api(action, data, cb) {
        var opt = {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8' },
            body: Object.keys(data || {}).map(function (k) {
                var v = data[k];
                if (Array.isArray(v) || (v && typeof v === 'object')) v = JSON.stringify(v);
                return encodeURIComponent(k) + '=' + encodeURIComponent(v == null ? '' : v);
            }).join('&')
        };
        fetch(ACT + action, opt).then(function (r) { return r.json(); })
            .then(function (j) { cb && cb(j); })
            .catch(function () { toast('Lỗi kết nối máy chủ.', false); });
    }

    function openModal(title, bodyHtml) {
        $('#of-modal-title').textContent = title;
        $('#of-modal-body').innerHTML = bodyHtml;
        $('#of-modal').style.display = 'flex';
    }
    function closeModal() { $('#of-modal').style.display = 'none'; }

    function icon() { return state.type === 'sheet' ? 'fa-table-cells' : 'fa-file-lines'; }
    function permLabel(p) { return p === 'edit' ? 'Sửa' : (p === 'comment' ? 'Bình luận' : 'Chỉ xem'); }
    function permSelectHtml(shareId, current) {
        return '<select class="of-share-perm-select" data-id="' + shareId + '">' +
            ['view', 'comment', 'edit'].map(function (p) {
                return '<option value="' + p + '"' + (p === current ? ' selected' : '') + '>' + permLabel(p) + '</option>';
            }).join('') + '</select>';
    }

    function fmtDate(s) {
        if (!s) return '';
        var d = new Date(s.replace(' ', 'T'));
        if (isNaN(d.getTime())) return s;
        return d.toLocaleString('vi-VN', { day: '2-digit', month: '2-digit', year: 'numeric', hour: '2-digit', minute: '2-digit' });
    }

    /* ---------------- Thẻ (card) ---------------- */
    function actionsHtml(item, tab) {
        var isOwnerTab = (tab === 'mine' || tab === 'byme');
        var dlHref = ACT + 'download&id=' + item.id;
        var pdfHref = EDITOR + item.id + '&autoprint=1';
        var html = '';
        if (isOwnerTab) {
            html += '<button type="button" class="of-card-act of-card-share" data-id="' + item.id + '" title="Chia sẻ"><i class="fa-solid fa-user-plus"></i></button>';
        }
        if (tab === 'shared') {
            html += '<button type="button" class="of-card-act of-card-lib' + (item.in_my_library ? ' is-on' : '') + '" data-id="' + item.share_id + '" title="Thêm/bỏ khỏi thư viện của tôi"><i class="fa-' + (item.in_my_library ? 'solid' : 'regular') + ' fa-bookmark"></i></button>';
            html += '<button type="button" class="of-card-act of-card-leave" data-id="' + item.share_id + '" title="Xóa khỏi danh sách của tôi"><i class="fa-solid fa-trash-can"></i></button>';
        }
        var dlExt = state.type === 'sheet' ? '.xlsx' : '.doc';
        html += '<a class="of-card-act" href="' + dlHref + '" title="Tải xuống" download="' + esc(item.title || 'tai_lieu') + dlExt + '"><i class="fa-solid fa-download"></i></a>';
        html += '<a class="of-card-act" href="' + pdfHref + '" title="Xuất PDF (in)" target="_blank" rel="noopener"><i class="fa-solid fa-file-pdf"></i></a>';
        return '<div class="of-card-actions">' + html + '</div>';
    }

    function card(item, tab) {
        var isOwnerTab = (tab === 'mine' || tab === 'byme');
        var star = isOwnerTab
            ? (item.is_starred
                ? '<i class="fa-solid fa-star of-card-star is-on" data-id="' + item.id + '"></i>'
                : '<i class="fa-regular fa-star of-card-star" data-id="' + item.id + '"></i>')
            : '';
        // Checkbox chọn nhiều để xóa hàng loạt (thay cho click-phải-chọn-xóa cũ) — chỉ ở tab
        // sở hữu (mine/byme), đặt cạnh phải sao (xem CSS .of-card-check). Giữ nguyên trạng thái
        // tích qua state.selected để không mất lựa chọn khi render() lại (đổi tab, reload...).
        var checkbox = isOwnerTab
            ? '<label class="of-card-check app-round-check" title="Chọn để xóa hàng loạt">' +
                '<input type="checkbox" class="of-card-select" data-id="' + item.id + '"' + (state.selected[item.id] ? ' checked' : '') + '>' +
                '<span class="app-round-check-mark"><i class="fa-solid fa-check"></i></span>' +
              '</label>'
            : '';
        var sub = tab === 'shared' ? esc(item.owner_name || '') : fmtDate(item.updated_at);
        var badge = item.permission ? '<span class="of-card-perm">' + permLabel(item.permission) + '</span>' : '';
        var pencil = isOwnerTab
            ? '<i class="fa-solid fa-pen of-card-rename-pencil" data-id="' + item.id + '" title="Đổi tên"></i>' : '';
        return '<div class="of-card" data-id="' + item.id + '">' +
            star + checkbox +
            '<div class="of-card-open" data-id="' + item.id + '">' +
            '<div class="of-card-icon"><i class="fa-solid ' + icon() + '"></i></div>' +
            '<div class="of-card-title"><span class="of-card-title-text">' + esc(item.title) + '</span>' + pencil + '</div>' +
            '<div class="of-card-sub">' + sub + badge + '</div>' +
            '</div>' +
            actionsHtml(item, tab) +
            '</div>';
    }

    function render() {
        $all('.of-chip').forEach(function (c) { c.classList.toggle('is-active', c.dataset.tab === state.tab); });
        $('#of-tab-mine').style.display = state.tab === 'mine' ? '' : 'none';
        $('#of-tab-shared').style.display = state.tab === 'shared' ? '' : 'none';
        $('#of-tab-byme').style.display = state.tab === 'byme' ? '' : 'none';

        $('#of-grid-mine').innerHTML = state.mine.map(function (i) { return card(i, 'mine'); }).join('');
        $('#of-empty-mine').style.display = state.mine.length ? 'none' : '';

        $('#of-grid-shared').innerHTML = state.shared.map(function (i) { return card(i, 'shared'); }).join('');
        $('#of-empty-shared').style.display = state.shared.length ? 'none' : '';

        $('#of-grid-byme').innerHTML = state.byme.map(function (i) { return card(i, 'byme'); }).join('');
        $('#of-empty-byme').style.display = state.byme.length ? 'none' : '';

        bindCardEvents();
        updateBulkActionsUi();
    }

    function bindCardEvents() {
        $all('.of-card-open').forEach(function (c) {
            c.addEventListener('click', function () { window.location.href = EDITOR + c.dataset.id; });
        });
        $all('.of-card-star').forEach(function (s) {
            s.addEventListener('click', function (e) {
                e.stopPropagation();
                api('star_toggle', { id: s.dataset.id }, function () { reload(); });
            });
        });
        $all('.of-card-share').forEach(function (b) {
            b.addEventListener('click', function (e) {
                e.stopPropagation(); e.preventDefault();
                openShareModalFor(b.dataset.id);
            });
        });
        $all('.of-card-lib').forEach(function (b) {
            b.addEventListener('click', function (e) {
                e.stopPropagation(); e.preventDefault();
                api('toggle_library', { share_id: b.dataset.id }, function (j) { if (j && j.success) reload(); });
            });
        });
        $all('.of-card-leave').forEach(function (b) {
            b.addEventListener('click', function (e) {
                e.stopPropagation(); e.preventDefault();
                if (!confirm('Xóa khỏi danh sách của bạn? (Chủ sở hữu vẫn giữ tài liệu của họ)')) return;
                api('leave_share', { share_id: b.dataset.id }, function (j) {
                    if (j && j.success) { toast('Đã xóa khỏi danh sách.'); reload(); }
                });
            });
        });
        $all('.of-card-act[href]').forEach(function (a) {
            a.addEventListener('click', function (e) { e.stopPropagation(); });
        });
        $all('.of-card-rename-pencil').forEach(function (p) {
            p.addEventListener('click', function (e) {
                e.stopPropagation(); e.preventDefault();
                startInlineRename(p);
            });
        });
        // Chọn nhiều để xóa hàng loạt (thay cho click-phải-chọn-xóa cũ) — xem bulkDelete()/
        // updateBulkActionsUi() và nút #of-bulk-delete-btn trong bindToolbar().
        $all('.of-card-select').forEach(function (cb) {
            cb.addEventListener('click', function (e) { e.stopPropagation(); });
            cb.addEventListener('change', function () {
                if (cb.checked) state.selected[cb.dataset.id] = true; else delete state.selected[cb.dataset.id];
                updateBulkActionsUi();
            });
        });
    }

    function startInlineRename(pencil) {
        var titleDiv = pencil.closest('.of-card-title');
        var span = titleDiv.querySelector('.of-card-title-text');
        var old = span.textContent;
        var input = document.createElement('input');
        input.type = 'text';
        input.className = 'of-card-title-input';
        input.value = old;
        titleDiv.replaceChild(input, span);
        pencil.style.display = 'none';
        input.focus();
        input.select();
        input.addEventListener('click', function (e) { e.stopPropagation(); });
        input.addEventListener('mousedown', function (e) { e.stopPropagation(); });
        function commit() {
            var v = input.value.trim();
            if (v && v !== old) {
                api('rename', { id: pencil.dataset.id, title: v }, function (j) {
                    if (!j || !j.success) toast('Không đổi được tên.', false);
                    reload();
                });
            } else {
                reload();
            }
        }
        input.addEventListener('blur', commit);
        input.addEventListener('keydown', function (e) {
            if (e.key === 'Enter') { e.preventDefault(); input.blur(); }
            else if (e.key === 'Escape') { input.value = old; input.blur(); }
        });
    }

    /* ---------------- Chọn nhiều -> xóa/chia sẻ hàng loạt ---------------- */
    function updateBulkActionsUi() {
        var count = Object.keys(state.selected).length;
        var delBtn = $('#of-bulk-delete-btn');
        if (delBtn) {
            var delCount = $('#of-bulk-delete-count');
            if (delCount) delCount.textContent = count;
            delBtn.style.display = count > 0 ? '' : 'none';
        }
        var shareBtn = $('#of-bulk-share-btn');
        if (shareBtn) shareBtn.style.display = count > 0 ? '' : 'none';
    }

    function bulkDelete() {
        var ids = Object.keys(state.selected);
        if (!ids.length) return;
        if (!confirm('Xóa vĩnh viễn ' + ids.length + ' mục đã chọn? Không thể hoàn tác.')) return;
        var remaining = ids.length, failed = 0;
        ids.forEach(function (id) {
            api('delete', { id: id }, function (j) {
                if (!j || !j.success) failed++;
                remaining--;
                if (remaining === 0) {
                    state.selected = {};
                    updateBulkActionsUi();
                    if (failed) toast('Không xóa được ' + failed + ' mục.', false);
                    reload();
                }
            });
        });
    }

    /** Chia sẻ hàng loạt — chia sẻ nội bộ ("tại đây") hoặc qua Chat cho TẤT CẢ mục đang tích,
     *  cùng 1 danh sách người nhận/quyền (hoặc 1 người nhận Chat) — lặp gọi đúng action `share`/
     *  `share_to_chat` sẵn có cho từng id, không viết action mới. */
    function openBulkShareModal() {
        var ids = Object.keys(state.selected);
        if (!ids.length) return;
        api('users', {}, function (uj) {
            var users = (uj && uj.data) || [];
            var userOpts = users.map(function (u) {
                return '<label class="of-share-row"><input type="checkbox" value="' + u.id + '"> ' + esc(u.name) + '</label>';
            }).join('') || '<div class="of-muted">Không có ai khác trong hệ thống.</div>';
            var chatOpts = '<option value="">— Chọn người nhận —</option>' +
                users.map(function (u) { return '<option value="' + u.id + '">' + esc(u.name) + '</option>'; }).join('');

            openModal('Chia sẻ ' + ids.length + ' mục đã chọn', '' +
                '<div class="of-share-tabs">' +
                '<button type="button" class="of-share-tab is-active" data-pane="internal">Chia sẻ tại đây</button>' +
                '<button type="button" class="of-share-tab" data-pane="chat">Chia sẻ qua Chat</button>' +
                '</div>' +
                '<div class="of-share-pane" data-pane="internal">' +
                '<div class="of-share-list">' + userOpts + '</div>' +
                '<div class="of-share-perm-row">Quyền: <select id="of-bulkshare-perm">' +
                '<option value="view">Chỉ xem</option><option value="comment">Bình luận</option><option value="edit">Sửa</option>' +
                '</select></div>' +
                '<button type="button" class="of-btn of-btn-primary" id="of-bulkshare-submit">Chia sẻ</button>' +
                '</div>' +
                '<div class="of-share-pane" data-pane="chat" style="display:none;">' +
                '<label class="of-share-chat-label">Người nhận</label>' +
                '<select id="of-bulkchat-target" class="of-tb-select">' + chatOpts + '</select>' +
                '<textarea id="of-bulkchat-note" class="of-share-chat-note" placeholder="Lời nhắn (không bắt buộc)"></textarea>' +
                '<button type="button" class="of-btn of-btn-primary" id="of-bulkchat-submit">Gửi qua Chat</button>' +
                '</div>');

            $all('.of-share-tab').forEach(function (t) {
                t.addEventListener('click', function () {
                    $all('.of-share-tab').forEach(function (x) { x.classList.toggle('is-active', x === t); });
                    $all('.of-share-pane').forEach(function (p) { p.style.display = p.dataset.pane === t.dataset.pane ? '' : 'none'; });
                });
            });

            $('#of-bulkshare-submit').addEventListener('click', function () {
                var targets = $all('.of-share-row input:checked').map(function (c) { return c.value; });
                if (!targets.length) { toast('Chưa chọn người nhận.', false); return; }
                var perm = $('#of-bulkshare-perm').value;
                var remaining = ids.length, failed = 0;
                ids.forEach(function (id) {
                    api('share', { id: id, targets: targets, permission: perm }, function (j) {
                        if (!j || !j.success) failed++;
                        remaining--;
                        if (remaining === 0) {
                            toast(failed ? ('Đã chia sẻ, ' + failed + ' mục lỗi.') : 'Đã chia sẻ.', !failed);
                            closeModal();
                            reload();
                        }
                    });
                });
            });

            $('#of-bulkchat-submit').addEventListener('click', function () {
                var target = $('#of-bulkchat-target').value;
                if (!target) { toast('Chưa chọn người nhận.', false); return; }
                var note = $('#of-bulkchat-note').value;
                var remaining = ids.length, failed = 0;
                ids.forEach(function (id) {
                    api('share_to_chat', { id: id, target_id: target, note: note }, function (j) {
                        if (!j || !j.success) failed++;
                        remaining--;
                        if (remaining === 0) {
                            toast(failed ? ('Đã gửi, ' + failed + ' mục lỗi.') : 'Đã gửi qua Chat.', !failed);
                            closeModal();
                        }
                    });
                });
            });
        });
    }

    /* ---------------- Chia sẻ (nội bộ + qua Chat) ---------------- */
    /** onBack (không bắt buộc): truyền khi mở modal chia sẻ này TỪ 1 danh sách khác (vd danh
     *  sách kết quả "Trộn file") — hiện thêm link "Quay lại danh sách" ở đầu modal, và tự động
     *  gọi lại onBack() thay vì đóng hẳn modal sau khi gửi Chat thành công, để user chia sẻ tiếp
     *  các file khác trong CÙNG danh sách đó mà không phải mở lại từ đầu. */
    function openShareModalFor(docId, onBack) {
        api('users', {}, function (uj) {
            api('share_list', { id: docId }, function (sj) {
                var users = (uj && uj.data) || [];
                var shares = (sj && sj.data) || [];
                var sharedIds = {};
                shares.forEach(function (s) { sharedIds[s.user_id] = s; });
                var userOpts = users.map(function (u) {
                    return '<label class="of-share-row">' +
                        '<input type="checkbox" value="' + u.id + '"' + (sharedIds[u.id] ? ' disabled' : '') + '> ' +
                        esc(u.name) + (sharedIds[u.id] ? ' <span class="of-muted">(đã chia sẻ)</span>' : '') +
                        '</label>';
                }).join('') || '<div class="of-muted">Không có ai khác trong hệ thống.</div>';
                var existRows = shares.map(function (s) {
                    return '<div class="of-share-exist"><span>' + esc(s.name) + '</span>' +
                        permSelectHtml(s.share_id, s.permission) +
                        '<button type="button" class="of-link of-share-revoke" data-id="' + s.share_id + '">Gỡ</button></div>';
                }).join('') || '<div class="of-muted">Chưa chia sẻ với ai.</div>';
                var chatOpts = '<option value="">— Chọn người nhận —</option>' +
                    users.map(function (u) { return '<option value="' + u.id + '">' + esc(u.name) + '</option>'; }).join('');

                openModal('Chia sẻ', '' +
                    (onBack ? '<button type="button" class="of-link of-share-back" style="margin-bottom:10px;">← Quay lại danh sách</button>' : '') +
                    '<div class="of-share-tabs">' +
                    '<button type="button" class="of-share-tab is-active" data-pane="internal">Chia sẻ tại đây</button>' +
                    '<button type="button" class="of-share-tab" data-pane="chat">Chia sẻ qua Chat</button>' +
                    '</div>' +
                    '<div class="of-share-pane" data-pane="internal">' +
                    '<div class="of-share-list">' + userOpts + '</div>' +
                    '<div class="of-share-perm-row">Quyền: <select id="of-share-perm">' +
                    '<option value="view">Chỉ xem</option><option value="comment">Bình luận</option><option value="edit">Sửa</option>' +
                    '</select></div>' +
                    '<button type="button" class="of-btn of-btn-primary" id="of-share-submit">Chia sẻ</button>' +
                    '<hr class="of-share-sep">' +
                    '<div class="of-share-existing">' + existRows + '</div>' +
                    '</div>' +
                    '<div class="of-share-pane" data-pane="chat" style="display:none;">' +
                    '<label class="of-share-chat-label">Người nhận</label>' +
                    '<select id="of-chat-target" class="of-tb-select">' + chatOpts + '</select>' +
                    '<textarea id="of-chat-note" class="of-share-chat-note" placeholder="Lời nhắn (không bắt buộc)"></textarea>' +
                    '<button type="button" class="of-btn of-btn-primary" id="of-chat-submit">Gửi qua Chat</button>' +
                    '</div>');

                var backBtn = $('.of-share-back');
                if (backBtn) backBtn.addEventListener('click', function () { onBack(); });

                $all('.of-share-tab').forEach(function (t) {
                    t.addEventListener('click', function () {
                        $all('.of-share-tab').forEach(function (x) { x.classList.toggle('is-active', x === t); });
                        $all('.of-share-pane').forEach(function (p) { p.style.display = p.dataset.pane === t.dataset.pane ? '' : 'none'; });
                    });
                });

                var submit = $('#of-share-submit');
                if (submit) submit.addEventListener('click', function () {
                    var targets = $all('.of-share-row input:checked').map(function (c) { return c.value; });
                    if (!targets.length) { toast('Chưa chọn người nhận.', false); return; }
                    var perm = $('#of-share-perm').value;
                    api('share', { id: docId, targets: targets, permission: perm }, function (j) {
                        if (j && j.success) { toast('Đã chia sẻ.'); openShareModalFor(docId); reload(); }
                        else toast((j && j.message) || 'Không chia sẻ được.', false);
                    });
                });
                $all('.of-share-revoke').forEach(function (b) {
                    b.addEventListener('click', function () {
                        api('revoke_share', { share_id: b.dataset.id }, function (j) {
                            if (j && j.success) openShareModalFor(docId);
                        });
                    });
                });
                $all('.of-share-perm-select').forEach(function (sel) {
                    sel.addEventListener('change', function () {
                        api('change_permission', { share_id: sel.dataset.id, permission: sel.value }, function (j) {
                            if (j && j.success) toast('Đã đổi quyền.');
                            else { toast('Không đổi được quyền.', false); openShareModalFor(docId); }
                        });
                    });
                });
                var chatSubmit = $('#of-chat-submit');
                if (chatSubmit) chatSubmit.addEventListener('click', function () {
                    var target = $('#of-chat-target').value;
                    if (!target) { toast('Chưa chọn người nhận.', false); return; }
                    var note = $('#of-chat-note').value;
                    api('share_to_chat', { id: docId, target_id: target, note: note }, function (j) {
                        if (j && j.success) {
                            toast('Đã gửi qua Chat.');
                            if (onBack) onBack(); else closeModal();
                        } else toast((j && j.message) || 'Không gửi được.', false);
                    });
                });
            });
        });
    }

    /* ---------------- "Trộn file" (mail-merge Docs ⇄ Sheets) ---------------- */
    /** Bước 1: chọn văn bản mẫu (Docs) + dữ liệu (Sheets) + cột làm tên chính + tiêu đề. Luôn lấy
     *  danh sách "Của tôi" theo type='doc'/'sheet' TRỰC TIẾP (không phụ thuộc state.type hiện
     *  tại của trang) để nút hoạt động giống nhau dù đang ở trang Docs hay Sheets. */
    function openMergeSetupModal() {
        api('list', { type: 'doc' }, function (dj) {
            api('list', { type: 'sheet' }, function (sj) {
                var docs = (dj && dj.mine) || [];
                var sheets = (sj && sj.mine) || [];
                if (!docs.length || !sheets.length) {
                    openModal('Trộn file', '<div class="of-muted">Cần có ít nhất 1 văn bản Docs ' +
                        '(mẫu) và 1 bảng Sheets (dữ liệu) trong mục "Của tôi" trước khi trộn file.</div>');
                    return;
                }
                var tplOpts = docs.map(function (d) { return '<option value="' + d.id + '">' + esc(d.title) + '</option>'; }).join('');
                var sheetOpts = sheets.map(function (s) { return '<option value="' + s.id + '">' + esc(s.title) + '</option>'; }).join('');
                var extSheetOpts = '<option value="">— Không dùng —</option>' + sheetOpts;
                openModal('Trộn file', '' +
                    '<div class="of-merge-field"><label>Văn bản mẫu (Docs)</label>' +
                    '<select id="of-merge-tpl" class="of-tb-select">' + tplOpts + '</select></div>' +
                    '<div class="of-merge-field"><label>Dữ liệu (Sheets)</label>' +
                    '<select id="of-merge-sheet" class="of-tb-select">' + sheetOpts + '</select></div>' +
                    '<div class="of-merge-field"><label>Cột làm tên chính</label>' +
                    '<select id="of-merge-namecol" class="of-tb-select"><option value="">— Đang tải —</option></select></div>' +
                    '<div class="of-merge-field"><label>Đặt tên file</label>' +
                    '<input type="text" id="of-merge-prefix" placeholder="Ví dụ: Bản thông báo dinh dưỡng "></div>' +
                    '<div class="of-merge-field"><label>Sheet ngoại (tùy chọn — trộn dữ liệu dạng bảng "[table]")</label>' +
                    '<select id="of-merge-ext-sheet" class="of-tb-select">' + extSheetOpts + '</select></div>' +
                    '<div class="of-merge-field" id="of-merge-ext-fields-wrap" style="display:none;">' +
                    '<label>Field chính (cột Sheets dữ liệu dùng để khớp)</label>' +
                    '<select id="of-merge-main-join" class="of-tb-select"></select>' +
                    '<label style="margin-top:8px;">Field ngoại (cột Sheet ngoại dùng để khớp)</label>' +
                    '<select id="of-merge-ext-join" class="of-tb-select"><option value="">— Đang tải —</option></select>' +
                    '</div>' +
                    '<button type="button" class="of-btn of-btn-primary" id="of-merge-confirm">Xác nhận</button>');

                var mainSheetKeys = [];

                /** Đọc dòng tiêu đề (row 1) của 1 sheet (id truyền vào), trả về mảng key đã bỏ
                 *  ngoặc vuông qua callback — dùng chung cho cả sheet CHÍNH lẫn sheet NGOẠI, chỉ
                 *  khác id truyền vào. */
                function loadSheetKeys(sheetId, cb) {
                    api('get', { id: sheetId }, function (gj) {
                        var keys = [];
                        try {
                            var content = JSON.parse((gj && gj.doc && gj.doc.content) || '{}');
                            var cols = content.cols || 0;
                            var cells = content.cells || {};
                            for (var c = 0; c < cols; c++) {
                                var ref = colLetter(c) + '1';
                                var v = cells[ref] ? String(cells[ref].v || '') : '';
                                var m = /^\[(.+)\]$/.exec(v.trim());
                                if (m) keys.push(m[1]);
                            }
                        } catch (e) {}
                        cb(keys);
                    });
                }
                function keysToOptions(keys, emptyLabel) {
                    return keys.length
                        ? keys.map(function (k) { return '<option value="' + esc(k) + '">' + esc(k) + '</option>'; }).join('')
                        : '<option value="">' + esc(emptyLabel) + '</option>';
                }

                function loadNameCols() {
                    var sel = $('#of-merge-namecol');
                    sel.innerHTML = '<option value="">— Đang tải —</option>';
                    loadSheetKeys($('#of-merge-sheet').value, function (keys) {
                        mainSheetKeys = keys;
                        sel.innerHTML = keysToOptions(keys, '— Sheets này chưa có cột [tên_cột] —');
                        // Field chính (nếu khối Sheet ngoại đang mở) lấy CHUNG danh sách cột này —
                        // không gọi lại API, chỉ đổ lại đúng lúc sheet chính vừa đổi.
                        var mainJoinSel = $('#of-merge-main-join');
                        if (mainJoinSel) mainJoinSel.innerHTML = keysToOptions(keys, '— Không có cột [tên_cột] —');
                    });
                }
                $('#of-merge-sheet').addEventListener('change', loadNameCols);
                loadNameCols();

                $('#of-merge-ext-sheet').addEventListener('change', function () {
                    var extId = $('#of-merge-ext-sheet').value;
                    var wrap = $('#of-merge-ext-fields-wrap');
                    if (!extId) { wrap.style.display = 'none'; return; }
                    wrap.style.display = '';
                    $('#of-merge-main-join').innerHTML = keysToOptions(mainSheetKeys, '— Không có cột [tên_cột] —');
                    var extJoinSel = $('#of-merge-ext-join');
                    extJoinSel.innerHTML = '<option value="">— Đang tải —</option>';
                    loadSheetKeys(extId, function (keys) {
                        extJoinSel.innerHTML = keysToOptions(keys, '— Sheet này chưa có cột [tên_cột] —');
                    });
                });

                $('#of-merge-confirm').addEventListener('click', function () {
                    var nameCol = $('#of-merge-namecol').value;
                    if (!nameCol) { toast('Chưa chọn cột làm tên chính.', false); return; }
                    var extSheetId = $('#of-merge-ext-sheet').value;
                    if (extSheetId && (!$('#of-merge-main-join').value || !$('#of-merge-ext-join').value)) {
                        toast('Đã chọn Sheet ngoại — cần chọn đủ Field chính và Field ngoại.', false);
                        return;
                    }
                    var btn = $('#of-merge-confirm');
                    btn.disabled = true; btn.textContent = 'Đang trộn...';
                    api('mail_merge', {
                        template_id: $('#of-merge-tpl').value,
                        sheet_id: $('#of-merge-sheet').value,
                        title_prefix: $('#of-merge-prefix').value,
                        name_col: nameCol,
                        ext_sheet_id: extSheetId,
                        main_join_col: extSheetId ? $('#of-merge-main-join').value : '',
                        ext_join_col: extSheetId ? $('#of-merge-ext-join').value : ''
                    }, function (j) {
                        if (j && j.success) { renderMergeResults(j.data || [], false); return; }
                        toast((j && j.message) || 'Không trộn được file.', false);
                        btn.disabled = false; btn.textContent = 'Xác nhận';
                    });
                });
            });
        });
    }

    /** Submit 1 <form method="post" target="_blank"> ẩn để tải file — dùng khi payload cần gửi
     *  (vd nội dung đầy đủ nhiều file CHƯA lưu) quá lớn cho query string (GET). Trình duyệt tự xử
     *  lý tải file qua header Content-Disposition của response, không cần code Blob/fetch. */
    function postDownload(action, data) {
        var form = document.createElement('form');
        form.method = 'POST';
        form.action = ACT + action;
        form.target = '_blank';
        form.style.display = 'none';
        Object.keys(data).forEach(function (k) {
            var input = document.createElement('input');
            input.type = 'hidden';
            input.name = k;
            input.value = data[k];
            form.appendChild(input);
        });
        document.body.appendChild(form);
        form.submit();
        setTimeout(function () { document.body.removeChild(form); }, 1000);
    }

    /** Bước 2: danh sách file vừa trộn ra.
     *  isSaved=false (mặc định, vừa trộn xong): CHƯA lưu thành tài liệu Docs thật — mỗi phần tử
     *  trong `list` chỉ có {title,content}, chưa có id. Chỉ có "Tải xuống hết" (.zip, POST nội
     *  dung thô) + "Lưu vào Docs" (chỉ lúc này mới thật sự ghi DB) + xóa khỏi danh sách xem trước
     *  (client-only, chưa có gì trong DB để xóa). KHÔNG có tải/thư viện/chat từng file riêng lẻ —
     *  2 hành động đó cần tài liệu đã tồn tại thật.
     *  isSaved=true (sau khi bấm "Lưu vào Docs"): `list` giờ có id thật — hiện đầy đủ như tài
     *  liệu Docs bình thường (tải theo id/thư viện/chat/xóa thật), tái dùng nguyên action sẵn có. */
    function renderMergeResults(list, isSaved) {
        if (!isSaved) {
            var rowsPreview = list.map(function (item, idx) {
                return '<div class="of-merge-result-row" data-idx="' + idx + '">' +
                    '<span class="of-merge-result-title">' + esc(item.title) + '</span>' +
                    '<span class="of-merge-result-actions">' +
                    '<button type="button" class="of-merge-act-del-preview" data-idx="' + idx + '" title="Xóa khỏi danh sách"><i class="fa-solid fa-trash-can"></i></button>' +
                    '</span></div>';
            }).join('');

            openModal('Kết quả trộn file — bản xem trước (' + list.length + ')', '' +
                (list.length ? '<button type="button" class="of-btn of-merge-dl-all" id="of-merge-dl-preview">' +
                    '<i class="fa-solid fa-file-zipper"></i> Tải xuống hết (.zip)</button>' : '') +
                (list.length ? '<button type="button" class="of-btn of-btn-primary of-merge-save-btn" id="of-merge-save-btn">' +
                    '<i class="fa-solid fa-floppy-disk"></i> Lưu vào Docs</button>' : '') +
                '<div class="of-merge-result-list">' + (rowsPreview || '<div class="of-muted">Không có dòng dữ liệu hợp lệ nào để trộn — kiểm tra lại cột tên chính có dữ liệu không.</div>') + '</div>');

            var dlPreviewBtn = $('#of-merge-dl-preview');
            if (dlPreviewBtn) dlPreviewBtn.addEventListener('click', function () {
                postDownload('mail_merge_zip_preview', { items: JSON.stringify(list) });
            });
            var saveBtn = $('#of-merge-save-btn');
            if (saveBtn) saveBtn.addEventListener('click', function () {
                saveBtn.disabled = true; saveBtn.textContent = 'Đang lưu...';
                api('mail_merge_save', { items: JSON.stringify(list) }, function (j) {
                    if (j && j.success) {
                        toast('Đã lưu vào Docs.');
                        renderMergeResults(j.data || [], true);
                        reload();
                    } else {
                        toast((j && j.message) || 'Không lưu được.', false);
                        saveBtn.disabled = false; saveBtn.innerHTML = '<i class="fa-solid fa-floppy-disk"></i> Lưu vào Docs';
                    }
                });
            });
            $all('.of-merge-act-del-preview').forEach(function (b) {
                b.addEventListener('click', function () {
                    list.splice(parseInt(b.dataset.idx, 10), 1);
                    renderMergeResults(list, false);
                });
            });
            return;
        }

        var ids = list.map(function (i) { return i.id; }).join(',');
        var zipHref = ACT + 'mail_merge_zip&ids=' + ids;
        var rows = list.map(function (item) {
            var dlHref = ACT + 'download&id=' + item.id;
            return '<div class="of-merge-result-row" data-id="' + item.id + '">' +
                '<span class="of-merge-result-title">' + esc(item.title) + '</span>' +
                '<span class="of-merge-result-actions">' +
                '<a href="' + dlHref + '" title="Tải xuống" download="' + esc(item.title) + '.doc"><i class="fa-solid fa-download"></i></a>' +
                '<button type="button" class="of-merge-act-lib" data-id="' + item.id + '" title="Chuyển vào thư viện của tôi"><i class="fa-solid fa-folder-plus"></i></button>' +
                '<button type="button" class="of-merge-act-chat" data-id="' + item.id + '" title="Share qua Chat"><i class="fa-solid fa-paper-plane"></i></button>' +
                '<button type="button" class="of-merge-act-del" data-id="' + item.id + '" title="Xóa"><i class="fa-solid fa-trash-can"></i></button>' +
                '</span></div>';
        }).join('');

        openModal('Kết quả trộn file (' + list.length + ')', '' +
            (list.length ? '<a class="of-btn of-btn-primary of-merge-dl-all" href="' + zipHref + '" download>' +
                '<i class="fa-solid fa-file-zipper"></i> Tải xuống hết (.zip)</a>' : '') +
            '<div class="of-merge-result-list">' + (rows || '<div class="of-muted">Không có dòng dữ liệu hợp lệ nào để trộn — kiểm tra lại cột tên chính có dữ liệu không.</div>') + '</div>');

        $all('.of-merge-act-del').forEach(function (b) {
            b.addEventListener('click', function () {
                if (!confirm('Xóa vĩnh viễn file này? Không thể hoàn tác.')) return;
                api('delete', { id: b.dataset.id }, function (j) {
                    if (j && j.success) {
                        var row = $('.of-merge-result-row[data-id="' + b.dataset.id + '"]');
                        if (row) row.remove();
                        // Xoá luôn khỏi mảng `list` (không chỉ DOM) — nếu không, lần sau quay lại
                        // danh sách này qua onBack (sau khi chia sẻ 1 file khác) sẽ vẽ nhầm lại
                        // ĐÚNG dòng vừa xoá do renderMergeResults(list) đọc lại mảng cũ.
                        var idx = list.findIndex(function (i) { return String(i.id) === String(b.dataset.id); });
                        if (idx !== -1) list.splice(idx, 1);
                    } else toast('Không xóa được.', false);
                });
            });
        });
        $all('.of-merge-act-lib').forEach(function (b) {
            b.addEventListener('click', function () {
                api('export_to_fm', { id: b.dataset.id }, function (j) {
                    toast(j && j.success ? 'Đã đưa vào Quản lý file.' : 'Không đưa được vào thư viện.', j && j.success);
                });
            });
        });
        $all('.of-merge-act-chat').forEach(function (b) {
            b.addEventListener('click', function () {
                // Truyền onBack = quay lại ĐÚNG danh sách kết quả này (không đóng hẳn modal như
                // trước) — cho phép chia sẻ lần lượt nhiều file trong cùng 1 lượt trộn mà không
                // phải mở lại từ đầu.
                openShareModalFor(b.dataset.id, function () { renderMergeResults(list, true); });
            });
        });
    }

    function reload() {
        api('list', { type: state.type }, function (j) {
            if (j && j.success) {
                state.mine = j.mine; state.shared = j.shared; state.byme = j.byme;
                render();
            }
        });
    }

    function bindToolbar() {
        $all('.of-chip').forEach(function (c) {
            c.addEventListener('click', function () { state.tab = c.dataset.tab; render(); });
        });
        var createBtn = $('#of-create-btn');
        createBtn.addEventListener('click', function () {
            api('create', { type: state.type, title: '' }, function (j) {
                if (j && j.success && j.doc) window.location.href = EDITOR + j.doc.id;
                else toast('Không tạo được.', false);
            });
        });
        var mergeBtn = $('#of-merge-btn');
        if (mergeBtn) mergeBtn.addEventListener('click', openMergeSetupModal);
        var bulkDeleteBtn = $('#of-bulk-delete-btn');
        if (bulkDeleteBtn) bulkDeleteBtn.addEventListener('click', bulkDelete);
        var bulkShareBtn = $('#of-bulk-share-btn');
        if (bulkShareBtn) bulkShareBtn.addEventListener('click', openBulkShareModal);
        var closeBtn = $('#of-modal-close');
        if (closeBtn) closeBtn.addEventListener('click', closeModal);
        var overlay = $('#of-modal');
        if (overlay) overlay.addEventListener('click', function (e) { if (e.target === overlay) closeModal(); });
    }

    /** Khua chuột kéo 1 khung chữ nhật trên vùng thẻ (.of-grid) để chọn NHIỀU thẻ cùng lúc, thay
     *  vì phải tích từng checkbox — thẻ nào giao với khung kéo thì tự tích (CỘNG DỒN vào lựa
     *  chọn đang có, không thay thế, để kéo được nhiều lượt rời nhau mà không mất lựa chọn cũ).
     *  Gắn 1 LẦN lúc khởi động (không phải mỗi lần render()) vì bản thân .of-grid là khung CỐ
     *  ĐỊNH, chỉ có innerHTML (các thẻ bên trong) bị thay mỗi lần render() — không cần gắn lại.
     *  Tab "Được chia sẻ" không có checkbox (không sở hữu, không xóa/chia sẻ hàng loạt được) nên
     *  tự bỏ qua (kiểm tra có ít nhất 1 .of-card-select trong grid trước khi bắt đầu kéo).
     *
     *  QUAN TRỌNG: .of-card giãn kín từng ô CSS Grid (chỉ chừa khe hở 14px giữa các thẻ) nên nếu
     *  chỉ cho bắt đầu kéo từ vùng NGOÀI .of-card, người dùng gần như luôn bấm trúng 1 thẻ trước
     *  và tính năng có cảm giác "không hoạt động". Vì vậy CHO PHÉP bắt đầu kéo từ BẤT KỲ đâu
     *  trong .of-grid kể cả trên thẻ, chỉ loại trừ các nút/điều khiển tương tác cụ thể (sao,
     *  checkbox, bút sửa tên, tải xuống/PDF) — và chỉ THẬT SỰ vào "chế độ kéo" (tạo khung, chặn
     *  click mở tài liệu) sau khi chuột đã di chuyển quá 5px, để click thường (không kéo) vẫn mở
     *  tài liệu bình thường như trước. */
    function bindDragSelect() {
        var DRAG_THRESHOLD = 5;
        $all('.of-grid').forEach(function (grid) {
            grid.addEventListener('mousedown', function (e) {
                if (e.button !== 0) return; // chỉ chuột trái
                if (e.target.closest('.of-card-star, .of-card-check, .of-card-rename-pencil, .of-card-actions, .of-card-title-input')) return;
                if (!grid.querySelector('.of-card-select')) return;
                var startX = e.clientX, startY = e.clientY;
                var dragging = false;
                var box = null;
                function updateBox(x2, y2) {
                    box.style.left = Math.min(startX, x2) + 'px';
                    box.style.top = Math.min(startY, y2) + 'px';
                    box.style.width = Math.abs(x2 - startX) + 'px';
                    box.style.height = Math.abs(y2 - startY) + 'px';
                }
                function onMove(ev) {
                    if (!dragging) {
                        if (Math.abs(ev.clientX - startX) < DRAG_THRESHOLD && Math.abs(ev.clientY - startY) < DRAG_THRESHOLD) return;
                        dragging = true;
                        box = document.createElement('div');
                        box.className = 'of-drag-select-box';
                        document.body.appendChild(box);
                        updateBox(startX, startY);
                    }
                    ev.preventDefault();
                    updateBox(ev.clientX, ev.clientY);
                    var rect = box.getBoundingClientRect();
                    grid.querySelectorAll('.of-card-select').forEach(function (cb) {
                        if (cb.checked) return;
                        var cRect = cb.closest('.of-card').getBoundingClientRect();
                        var intersects = !(cRect.right < rect.left || cRect.left > rect.right ||
                            cRect.bottom < rect.top || cRect.top > rect.bottom);
                        if (intersects) { cb.checked = true; state.selected[cb.dataset.id] = true; }
                    });
                    updateBulkActionsUi();
                }
                function onUp() {
                    document.removeEventListener('mousemove', onMove);
                    document.removeEventListener('mouseup', onUp);
                    if (box && box.parentNode) box.parentNode.removeChild(box);
                    if (dragging) {
                        // Vừa kéo xong (đã di chuyển >5px) -> chặn sự kiện click sắp bắn ra ngay
                        // sau đó để KHÔNG vô tình mở tài liệu nơi bắt đầu/kết thúc kéo.
                        var blockNextClick = function (ce) { ce.stopPropagation(); ce.preventDefault(); };
                        grid.addEventListener('click', blockNextClick, true);
                        setTimeout(function () { grid.removeEventListener('click', blockNextClick, true); }, 0);
                    }
                }
                document.addEventListener('mousemove', onMove);
                document.addEventListener('mouseup', onUp);
            });
        });
    }

    function boot() {
        try {
            var raw = JSON.parse($('#of-boot').textContent || '{}');
            state.type = raw.type || 'doc';
            state.mine = raw.mine || [];
            state.shared = raw.shared || [];
            state.byme = raw.byme || [];
        } catch (e) {}
        bindToolbar();
        bindDragSelect();
        render();
    }

    document.addEventListener('DOMContentLoaded', boot);
})();
