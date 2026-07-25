/* ===== Quản lý dự án — chat.js : phòng chat nâng cao ===== */
window.PMX = window.PMX || {}; // tạo sớm để mọi module dùng chung 1 tham chiếu (detail.js sẽ gắn method)
window.PMChat = (function () {
    'use strict';
    var X = window.PMX;
    var PM = window.PM;
    var EMOJIS = ['👍','❤️','😀','😂','😮','😢','🙏','🎉','🔥','✅','❌','👏','💡','⭐','🚀','👀'];

    // Tô đậm @TênThànhViên trong nội dung (htmlEscaped đã qua nl2br/esc) + gắn data-uid để bấm xem thẻ.
    function highlightMentions(html) {
        (X.state.members || []).slice().sort(function (a, b) {
            return (b.fullname || '').length - (a.fullname || '').length; // tên dài trước
        }).forEach(function (m) {
            var name = X.esc(m.fullname);
            if (!name) return;
            html = html.split('@' + name).join('<span class="pm-mention" data-uid="' + m.user_id + '">@' + name + '</span>');
        });
        return html;
    }

    var $list = document.getElementById('pm-msg-list');
    var $wrap = document.getElementById('pm-messages');
    var $loadMore = document.getElementById('pm-load-more');
    var $input = document.getElementById('pm-input');
    var $send = document.getElementById('pm-send');
    var $file = document.getElementById('pm-file');
    var $attachPrev = document.getElementById('pm-attach-preview');
    var $replyBar = document.getElementById('pm-reply-bar');
    var $emojiPop = document.getElementById('pm-emoji-pop');

    var state = {
        sid: 0, maxId: 0, oldestId: 0, starFilter: false,
        els: {},           // id -> element
        order: [],         // mảng id theo thứ tự
        replyTo: null,     // {id, name, preview}
        attachments: [],   // File[]
        reminderTarget: 0, forwardTarget: 0, starTarget: null,
        readers: [],       // [{user_id, fullname, avatar, last_read_message_id}]
        inputFocused: false
    };

    /* ---------------- render 1 tin ---------------- */
    function bubbleInner(m) {
        if (m.recalled) return '<div class="pm-bubble recalled"><i class="fa-solid fa-ban"></i> Tin nhắn đã được thu hồi</div>';
        var html = '<div class="pm-bubble">';
        if (m.forwarded) html += '<div class="pm-fwd-tag"><i class="fa-solid fa-share"></i> Đã chia sẻ</div>';
        if (m.reply_to) {
            html += '<div class="pm-reply-quote" data-reply-jump="' + m.reply_to.id + '" title="Bấm để tới tin gốc"><b>' + X.esc(m.reply_to.sender_name) + '</b>' + X.esc(m.reply_to.preview) + '</div>';
        }
        if (m.body) html += '<div class="pm-text">' + highlightMentions(X.nl2br(m.body)) + '</div>';
        if (m.payload && m.payload.shape_link) {
            html += '<div class="pm-shapelink" data-shape-link="' + X.esc(m.payload.shape_link) + '"><i class="fa-solid fa-vector-square"></i> Xem hình trên bản thiết kế</div>';
        }

        // các loại đặc biệt
        if (m.type === 'checklist') html += renderChecklist(m);
        else if (m.type === 'vote') html += renderVote(m);
        else if (m.type === 'table') html += renderTable(m.payload);
        else if (m.type === 'tree') html += renderTree(m.payload);
        else if (m.type === 'process') html += renderProcess(m.payload);
        else if (m.type === 'canvas') html += renderCanvasCard(m);

        // Đính kèm. Bấm vào TỆP = MỞ ĐỌC ngay trên tab mới (KHÔNG tải xuống):
        // bỏ thuộc tính download="" — chính nó ép trình duyệt tải file về thay vì hiển thị.
        // File nằm ở public/uploads/project/ do Apache phục vụ tĩnh kèm đúng Content-Type và
        // KHÔNG có Content-Disposition, nên PDF/TXT/ảnh... mở đọc thẳng trong trình duyệt.
        // Định dạng trình duyệt không đọc được (xlsx/docx...) vẫn tự tải về — hành vi mặc định,
        // nên thêm nút tải riêng cho mọi tệp để không mất đường tải chủ động.
        (m.attachments || []).forEach(function (a) {
            if (a.is_image) html += '<img class="pm-att-img" src="' + X.esc(a.url) + '" data-img="' + X.esc(a.url) + '" data-name="' + X.esc(a.original_name || '') + '" alt="">';
            else html += '<span class="pm-att-file-wrap">' +
                '<a class="pm-att-file" href="' + X.esc(a.url) + '" target="_blank" rel="noopener" title="Bấm để mở đọc">' +
                '<i class="fa-solid fa-file"></i> ' + X.esc(a.original_name) + '</a>' +
                '<a class="pm-att-dl" href="' + X.esc(a.url) + '" download="' + X.esc(a.original_name) + '" title="Tải xuống">' +
                '<i class="fa-solid fa-download"></i></a></span>';
        });
        html += '</div>';

        // reactions
        if (m.reactions && m.reactions.length) {
            html += '<div class="pm-reactions">';
            m.reactions.forEach(function (r) {
                var who = (r.users && r.users.length) ? r.users.map(X.esc).join(', ') : '';
                var tip = who ? '<span class="pm-reaction-tip">' + who + '</span>' : '';
                html += '<span class="pm-reaction' + (r.mine ? ' mine' : '') + '" data-react="' + X.esc(r.emoji) + '">' + r.emoji + ' ' + r.count + tip + '</span>';
            });
            html += '</div>';
        }
        return html;
    }

    function renderChecklist(m) {
        var items = m.checklist || [];
        if (!items.length) return '';
        var h = '<div class="pm-checklist">';
        items.forEach(function (it) {
            h += '<label class="pm-checklist-row' + (it.is_done ? ' done' : '') + '">' +
                '<input type="checkbox" data-check="' + it.id + '"' + (it.is_done ? ' checked' : '') + '>' +
                '<span>' + X.esc(it.content) + (it.is_done && it.done_by_name ? '<span class="by">✓ ' + X.esc(it.done_by_name) + '</span>' : '') + '</span></label>';
        });
        return h + '</div>';
    }
    function renderTable(p) {
        if (!p) return '';
        var cols = p.columns || ['Field', 'Kiểu', 'Ghi chú'];
        var rows = p.rows || [];
        var h = '<table class="pm-dbtable">';
        if (p.name) h += '<caption><i class="fa-solid fa-table"></i> ' + X.esc(p.name) + '</caption>';
        h += '<tr>'; cols.forEach(function (c) { h += '<th>' + X.esc(c) + '</th>'; }); h += '</tr>';
        rows.forEach(function (r) { h += '<tr>'; cols.forEach(function (_, i) { h += '<td>' + X.esc(r[i] || '') + '</td>'; }); h += '</tr>'; });
        return h + '</table>';
    }
    function renderTree(p) {
        if (!p || !p.nodes) return '';
        // nodes: [{level, label}] -> dựng cây lồng theo level
        function build(nodes, start, level) {
            var h = '<ul>'; var i = start;
            while (i < nodes.length) {
                var n = nodes[i];
                if (n.level < level) break;
                if (n.level > level) { i++; continue; }
                h += '<li><span class="node"><i class="fa-solid fa-folder"></i> ' + X.esc(n.label) + '</span>';
                // con
                var childStart = i + 1;
                if (childStart < nodes.length && nodes[childStart].level > level) {
                    var inner = build(nodes, childStart, level + 1);
                    h += inner.html;
                    i = inner.next - 1;
                }
                h += '</li>';
                i++;
            }
            return { html: h + '</ul>', next: i };
        }
        return '<div class="pm-tree">' + build(p.nodes, 0, 0).html + '</div>';
    }
    function renderProcess(p) {
        if (!p || !p.steps) return '';
        var h = '<div class="pm-process">';
        p.steps.forEach(function (s, i) {
            if (i > 0) h += '<div class="pm-process-arrow"><i class="fa-solid fa-arrow-down"></i></div>';
            h += '<div class="pm-process-step"><span class="num">' + (i + 1) + '</span><span>' + X.esc(s) + '</span></div>';
        });
        return h + '</div>';
    }
    function renderCanvasCard(m) {
        var p = m.payload || {};
        var img = p.thumb ? '<img src="' + X.esc(p.thumb) + '" alt="">' : '';
        return '<div class="pm-canvas-card" data-canvas-session="' + (p.session_id || '') + '">' + img +
            '<div class="cap"><i class="fa-solid fa-pen-ruler"></i> Bản thiết kế · ' + (p.shape_count || 0) + ' hình — bấm để xem</div></div>';
    }

    function renderVote(m) {
        var p = m.payload || {}; var opts = p.options || []; var votes = m.votes || {};
        var total = 0; opts.forEach(function (_, i) { var v = votes[i]; if (v) total += v.count; });
        var h = '<div class="pm-vote">';
        h += '<div class="pm-vote-head"><i class="fa-solid fa-square-poll-vertical"></i> Bình chọn · ' + (p.multi ? 'chọn nhiều' : 'chọn một') + '</div>';
        opts.forEach(function (opt, i) {
            var v = votes[i] || { count: 0, mine: false, voters: [] };
            var pct = total > 0 ? Math.round(v.count * 100 / total) : 0;
            h += '<div class="pm-vote-opt' + (v.mine ? ' mine' : '') + '" data-vote-opt="' + i + '">' +
                '<div class="pm-vote-bar" style="width:' + pct + '%"></div>' +
                '<span class="pm-vote-label">' + X.esc(opt) + '</span>' +
                '<span class="pm-vote-count" title="' + X.esc((v.voters || []).join(', ')) + '">' + v.count + '</span></div>';
        });
        return h + '</div>';
    }

    function actionsBar(m) {
        if (m.type === 'system' || m.recalled) return '';
        var h = '<div class="pm-msg-actions">';
        h += '<button data-a="star" class="' + (m.starred ? 'starred' : '') + '" title="Gắn sao"><i class="fa-' + (m.starred ? 'solid' : 'regular') + ' fa-star"></i></button>';
        h += '<button data-a="pin" class="' + (m.pinned ? 'pinned' : '') + '" title="Ghim tin nhắn"><i class="fa-solid fa-thumbtack"></i></button>';
        h += '<button data-a="react" title="Cảm xúc"><i class="fa-regular fa-face-smile"></i></button>';
        h += '<button data-a="reply" title="Trả lời"><i class="fa-solid fa-reply"></i></button>';
        h += '<button data-a="forward" title="Chia sẻ lại"><i class="fa-solid fa-share"></i></button>';
        h += '<button data-a="remind" title="Nhắc hẹn"><i class="fa-regular fa-clock"></i></button>';
        if (m.can_recall) h += '<button data-a="recall" title="Thu hồi"><i class="fa-solid fa-rotate-left"></i></button>';
        return h + '</div>';
    }

    function buildEl(m) {
        var el = document.createElement('div');
        var mine = m.sender_id === PM.meId;
        el.className = 'pm-msg' + (mine ? ' mine' : '') + (m.type === 'system' ? ' system' : '');
        el.dataset.id = m.id;
        el.dataset.sender = m.sender_id;
        if (m.type === 'system') {
            el.innerHTML = '<div class="pm-msg-col"><div class="pm-bubble system">' + X.esc(m.body) + '</div></div>';
            return el;
        }
        // Khi lọc tin gắn sao: hiện mô tả (nếu có) ngay dưới phần meta.
        var starNote = (state.starFilter && m.star_note) ? '<div class="pm-star-note">' + X.nl2br(m.star_note) + '</div>' : '';
        el.innerHTML = X.avatarHtml(m.sender_name, m.sender_avatar) +
            '<div class="pm-msg-col">' +
            '<div class="pm-msg-meta">' + X.esc(m.sender_name) + ' · ' + X.fmtTime(m.created_at) + '</div>' +
            starNote + bubbleInner(m) + actionsBar(m) + '</div>';
        wireMsg(el, m);
        return el;
    }

    function wireMsg(el, m) {
        // ảnh -> lightbox
        el.querySelectorAll('[data-img]').forEach(function (img) {
            img.addEventListener('click', function () { openLightbox(img.dataset.img, m.id, img.dataset.name); });
        });
        // checklist tick
        el.querySelectorAll('[data-check]').forEach(function (cb) {
            cb.addEventListener('change', function () {
                X.post('checklistToggle', { item_id: cb.dataset.check, done: cb.checked ? 1 : 0 });
                cb.closest('.pm-checklist-row').classList.toggle('done', cb.checked);
            });
        });
        // reaction chip -> toggle
        el.querySelectorAll('[data-react]').forEach(function (chip) {
            chip.addEventListener('click', function () { doReact(m.id, chip.dataset.react); });
        });
        // canvas card -> click để xem FULL bản thiết kế (render từ snapshot shapes)
        var cc = el.querySelector('[data-canvas-session]');
        if (cc) {
            var pl = m.payload || {};
            cc.style.cursor = 'zoom-in';
            cc.addEventListener('click', function () {
                if (pl.shapes && pl.shapes.length) {
                    X.toast('Đang mở bản thiết kế...');
                    window.PMCanvas.renderShapesPNG(pl.shapes, 1800).then(function (url) {
                        url ? openLightbox(url, m.id) : (pl.thumb ? openLightbox(pl.thumb, m.id) : X.toast('Không tạo được ảnh.'));
                    });
                } else if (pl.thumb) { openLightbox(pl.thumb, m.id); }
                else { X.toast('Chưa có nội dung để xem.'); }
            });
        }
        // vote: bấm 1 lựa chọn
        el.querySelectorAll('[data-vote-opt]').forEach(function (op) {
            op.addEventListener('click', function () { doVote(m.id, parseInt(op.dataset.voteOpt, 10)); });
        });
        // bảng: bấm để phóng to dạng ảnh
        var tbl = el.querySelector('.pm-dbtable');
        if (tbl) { tbl.style.cursor = 'zoom-in'; tbl.addEventListener('click', function () { openZoom(tbl.outerHTML); }); }
        // link tới hình trên canvas
        var sl = el.querySelector('[data-shape-link]');
        if (sl) sl.addEventListener('click', function () { window.PMCanvas.focusShape(sl.dataset.shapeLink); });
        // bấm trích dẫn trả lời -> nhảy về tin gốc
        var rq = el.querySelector('[data-reply-jump]');
        if (rq) rq.addEventListener('click', function (e) { e.stopPropagation(); window.PMChat.scrollTo(parseInt(rq.dataset.replyJump, 10)); });
        // action bar
        var bar = el.querySelector('.pm-msg-actions');
        if (bar) bar.addEventListener('click', function (e) {
            var b = e.target.closest('button'); if (!b) return;
            onAction(b.dataset.a, m, b);
        });
        // bấm "@tên" → thẻ thông tin người được nhắc
        el.querySelectorAll('.pm-mention').forEach(function (men) {
            men.addEventListener('click', function (e) {
                e.stopPropagation();
                if (window.UserCard) UserCard.show(men.dataset.uid);
            });
        });
        // bấm avatar người gửi → thẻ thông tin
        var avatar = el.querySelector('.pm-msg-avatar');
        if (avatar && m.sender_id !== PM.meId) {
            avatar.style.cursor = 'pointer';
            avatar.addEventListener('click', function () { if (window.UserCard) UserCard.show(m.sender_id); });
        }
    }

    function onAction(a, m, btn) {
        if (a === 'star') {
            if (m.starred) doStar(m, btn, '');   // đang gắn sao → bỏ sao ngay
            else openStarModal(m, btn);           // chưa gắn → mở hộp nhập mô tả
        } else if (a === 'pin') {
            X.post('pinToggle', { message_id: m.id }).then(function (res) {
                if (!res.ok) return;
                m.pinned = res.pinned;
                btn.classList.toggle('pinned', res.pinned);
                var el2 = state.els[m.id]; if (el2 && el2._data) el2._data.pinned = res.pinned;
                fetchPinned();
            });
        } else if (a === 'react') {
            openEmoji(btn, function (emo) { doReact(m.id, emo); });
        } else if (a === 'reply') {
            setReply(m);
        } else if (a === 'forward') {
            openForward(m.id);
        } else if (a === 'remind') {
            openReminder(m.id);
        } else if (a === 'recall') {
            if (!confirm('Thu hồi tin nhắn này?')) return;
            X.post('recall', { message_id: m.id }).then(function (res) {
                if (res.ok) { m.recalled = true; replaceEl(m); }
                else X.toast(res.message || 'Không thu hồi được.');
            });
        }
    }

    function doReact(id, emo) {
        X.post('react', { message_id: id, emoji: emo }).then(function (res) {
            if (res.ok) applyReactions(id, res.reactions);
        });
    }

    function doVote(id, idx) {
        X.post('voteToggle', { message_id: id, opt_index: idx }).then(function (res) {
            if (res.ok) applyVotes(id, res.votes);
        });
    }
    function applyVotes(id, votes) {
        var el = state.els[id]; if (!el || !el._data) return;
        el._data.votes = votes; replaceEl(el._data);
    }

    /* ---------------- gắn sao + mô tả ---------------- */
    var $starModal = document.getElementById('pm-star-modal');
    var $starNote = document.getElementById('pm-star-note');
    function openStarModal(m, btn) {
        state.starTarget = { m: m, btn: btn };
        $starNote.value = '';
        $starModal.style.display = '';
        setTimeout(function () { $starNote.focus(); }, 30);
    }
    function closeStarModal() { $starModal.style.display = 'none'; state.starTarget = null; }
    function doStar(m, btn, note) {
        X.post('starToggle', { message_id: m.id, note: note || '' }).then(function (res) {
            if (!res.ok) return;
            m.starred = res.starred;
            m.star_note = res.starred ? (res.note || '') : '';
            btn.classList.toggle('starred', res.starred);
            btn.innerHTML = '<i class="fa-' + (res.starred ? 'solid' : 'regular') + ' fa-star"></i>';
            if (state.starFilter) { res.starred ? replaceEl(m) : removeEl(m.id); } // cập nhật/ẩn mô tả trong bộ lọc sao
        });
    }
    if ($starModal) {
        document.getElementById('pm-star-close').addEventListener('click', closeStarModal);
        document.getElementById('pm-star-cancel').addEventListener('click', closeStarModal);
        $starModal.addEventListener('click', function (e) { if (e.target === $starModal) closeStarModal(); });
        document.getElementById('pm-star-save').addEventListener('click', function () {
            if (!state.starTarget) return;
            var t = state.starTarget;
            doStar(t.m, t.btn, $starNote.value.trim());
            closeStarModal();
        });
    }

    /* ---------------- DOM helpers ---------------- */
    function atBottom() { return $wrap.scrollHeight - $wrap.scrollTop - $wrap.clientHeight < 80; }
    function scrollBottom() { $wrap.scrollTop = $wrap.scrollHeight; }

    function appendMsg(m, toTop) {
        if (state.els[m.id]) { replaceEl(m); return; }
        var el = buildEl(m);
        state.els[m.id] = el;
        if (toTop) { $list.insertBefore(el, $list.firstChild); state.order.unshift(m.id); }
        else { $list.appendChild(el); state.order.push(m.id); }
        if (m.id > state.maxId) state.maxId = m.id;
        if (!state.oldestId || m.id < state.oldestId) state.oldestId = m.id;
        el._data = m;
    }
    function replaceEl(m) {
        var old = state.els[m.id]; if (!old) { appendMsg(m); return; }
        var el = buildEl(m); el._data = m;
        old.parentNode.replaceChild(el, old); state.els[m.id] = el;
    }
    function removeEl(id) {
        var el = state.els[id]; if (el && el.parentNode) el.parentNode.removeChild(el);
        delete state.els[id];
        var i = state.order.indexOf(id); if (i !== -1) state.order.splice(i, 1);
    }
    function applyReactions(id, reactions) {
        var el = state.els[id]; if (!el || !el._data) return;
        el._data.reactions = reactions; replaceEl(el._data);
    }

    /* ---------------- emoji popup ---------------- */
    function openEmoji(anchor, cb) {
        X.closeAllPops();
        $emojiPop.innerHTML = '';
        EMOJIS.forEach(function (e) {
            var b = document.createElement('button'); b.textContent = e;
            b.addEventListener('click', function () { $emojiPop.style.display = 'none'; cb(e); });
            $emojiPop.appendChild(b);
        });
        var r = anchor.getBoundingClientRect();
        $emojiPop.style.display = '';
        var top = r.top - $emojiPop.offsetHeight - 6;
        if (top < 10) top = r.bottom + 6;
        $emojiPop.style.top = top + 'px';
        $emojiPop.style.left = Math.max(10, Math.min(r.left, window.innerWidth - 310)) + 'px';
    }

    /* ---------------- reply ---------------- */
    function setReply(m) {
        state.replyTo = { id: m.id, name: m.sender_name, preview: (m.body || X.esc('[Đính kèm]')).slice(0, 80) };
        document.getElementById('pm-reply-name').textContent = m.sender_name;
        document.getElementById('pm-reply-preview').textContent = ' ' + state.replyTo.preview;
        $replyBar.style.display = '';
        $input.focus();
    }
    function clearReply() { state.replyTo = null; $replyBar.style.display = 'none'; }
    document.getElementById('pm-reply-cancel').addEventListener('click', clearReply);

    /* ---------------- reminder modal (10 phút / 1 tiếng / ngày mai / tùy chọn) ---------------- */
    var $remModal = document.getElementById('pm-reminder-modal');
    var $remQuick = document.getElementById('pm-rem-quick');
    var $remCustom = document.getElementById('pm-rem-custom');
    var $remAt = document.getElementById('pm-reminder-at');
    var $remWhen = document.getElementById('pm-rem-when');
    var $remNote = document.getElementById('pm-reminder-note');

    function fmtDT(d) { var p = function (n) { return ('0' + n).slice(-2); }; return d.getFullYear() + '-' + p(d.getMonth() + 1) + '-' + p(d.getDate()) + ' ' + p(d.getHours()) + ':' + p(d.getMinutes()) + ':00'; }
    function humanDT(d) {
        var p = function (n) { return ('0' + n).slice(-2); };
        var now = new Date(), hm = p(d.getHours()) + ':' + p(d.getMinutes());
        if (d.toDateString() === now.toDateString()) return 'hôm nay ' + hm;
        var tmr = new Date(now); tmr.setDate(now.getDate() + 1);
        if (d.toDateString() === tmr.toDateString()) return 'ngày mai ' + hm;
        return p(d.getDate()) + '/' + p(d.getMonth() + 1) + ' ' + hm;
    }
    function setRemWhen(d) { state.reminderAt = d ? fmtDT(d) : null; $remWhen.textContent = d ? ('⏰ Sẽ nhắc vào ' + humanDT(d)) : ''; }

    function openReminder(id) {
        state.reminderTarget = id; state.reminderAt = null;
        $remQuick.querySelectorAll('button').forEach(function (b) { b.classList.remove('active'); });
        $remCustom.style.display = 'none'; $remAt.value = ''; $remNote.value = ''; $remWhen.textContent = '';
        $remModal.style.display = '';
    }
    function closeReminder() { $remModal.style.display = 'none'; }

    $remQuick.querySelectorAll('button').forEach(function (b) {
        b.addEventListener('click', function () {
            $remQuick.querySelectorAll('button').forEach(function (x) { x.classList.remove('active'); });
            b.classList.add('active');
            var q = b.dataset.quick;
            if (q === 'custom') {
                $remCustom.style.display = '';
                // gợi ý mặc định: +1 giờ
                var def = new Date(Date.now() + 60 * 60000);
                var p = function (n) { return ('0' + n).slice(-2); };
                $remAt.value = def.getFullYear() + '-' + p(def.getMonth() + 1) + '-' + p(def.getDate()) + 'T' + p(def.getHours()) + ':' + p(def.getMinutes());
                setRemWhen($remAt.value ? new Date($remAt.value) : null);
                setTimeout(function () { $remAt.focus(); }, 30);
                return;
            }
            $remCustom.style.display = 'none';
            var d;
            if (q === '10m') d = new Date(Date.now() + 10 * 60000);
            else if (q === '1h') d = new Date(Date.now() + 60 * 60000);
            else { d = new Date(); d.setDate(d.getDate() + 1); d.setHours(8, 0, 0, 0); } // ngày mai 8:00
            setRemWhen(d);
        });
    });
    $remAt.addEventListener('input', function () { setRemWhen($remAt.value ? new Date($remAt.value) : null); });

    document.getElementById('pm-reminder-close').addEventListener('click', closeReminder);
    document.getElementById('pm-reminder-cancel').addEventListener('click', closeReminder);
    $remModal.addEventListener('click', function (e) { if (e.target === $remModal) closeReminder(); });
    document.getElementById('pm-reminder-save').addEventListener('click', function () {
        if (!state.reminderAt) { X.toast('Hãy chọn thời điểm nhắc.'); return; }
        X.post('setReminder', { message_id: state.reminderTarget, remind_at: state.reminderAt, note: $remNote.value }).then(function (res) {
            if (res.ok) { X.toast('✓ Đã đặt nhắc hẹn ' + ($remWhen.textContent.replace('⏰ Sẽ nhắc vào ', '') || '') + '.'); closeReminder(); }
            else X.toast(res.message || 'Lỗi.');
        });
    });

    /* ---------------- forward modal (2 nhóm đích: session dự án + chat hệ thống) ---------------- */
    var $fwModal = document.getElementById('pm-forward-modal');
    var $fwList = document.getElementById('pm-forward-list');
    var $fwChatList = document.getElementById('pm-fw-chat-list');
    var $fwChatSearch = document.getElementById('pm-fw-chat-search');
    var $fwChatNote = document.getElementById('pm-fw-chat-note');

    // Nhóm 1: chia sẻ sang 1 SESSION trong dự án (giữ nguyên hành vi cũ).
    function renderFwSessions() {
        $fwList.innerHTML = '';
        X.state.sessions.forEach(function (s) {
            var li = document.createElement('li');
            li.innerHTML = '<i class="fa-solid fa-folder"></i> ' + X.esc(s.name) + (parseInt(s.id, 10) === state.sid ? ' (hiện tại)' : '');
            li.addEventListener('click', function () {
                X.post('forward', { message_id: state.forwardTarget, to_session_id: s.id }).then(function (res) {
                    $fwModal.style.display = 'none';
                    if (res.ok) X.toast('Đã chia sẻ sang "' + s.name + '".');
                    else X.toast(res.message || 'Lỗi.');
                });
            });
            $fwList.appendChild(li);
        });
    }

    // Nhóm 2: chia sẻ QUA CHAT HỆ THỐNG tới 1 người. Danh sách người dùng lấy sẵn từ
    // PM.allUsers (đã bootstrap vào trang, không cần AJAX riêng) — mirror recipe fm_share_to_chat.
    function renderFwChatUsers(filter) {
        filter = (filter || '').toLowerCase();
        $fwChatList.innerHTML = '';
        (PM.allUsers || []).filter(function (u) {
            return u.fullname.toLowerCase().indexOf(filter) !== -1
                || (u.username || '').toLowerCase().indexOf(filter) !== -1;
        }).forEach(function (u) {
            var li = document.createElement('li');
            li.innerHTML = X.avatarHtml(u.fullname, u.avatar, 'pm-fw-ava') +
                '<span class="pm-fw-uname">' + X.esc(u.fullname) + '</span>';
            li.addEventListener('click', function () {
                X.post('shareToChat', {
                    message_id: state.forwardTarget,
                    target_uid: u.id,
                    note: $fwChatNote.value
                }).then(function (res) {
                    $fwModal.style.display = 'none';
                    if (res.ok) X.toast('Đã chia sẻ qua chat tới ' + u.fullname + '.');
                    else X.toast(res.message || 'Lỗi.');
                });
            });
            $fwChatList.appendChild(li);
        });
    }

    function openForward(id) {
        state.forwardTarget = id;
        // Mở lại mặc định ở tab "session" mỗi lần mở modal.
        switchFwTab('session');
        renderFwSessions();
        $fwChatSearch.value = '';
        $fwChatNote.value = '';
        renderFwChatUsers('');
        $fwModal.style.display = '';
    }

    function switchFwTab(which) {
        Array.prototype.forEach.call($fwModal.querySelectorAll('.pm-fw-tab'), function (b) {
            b.classList.toggle('is-active', b.dataset.fwtab === which);
        });
        document.getElementById('pm-fw-pane-session').style.display = (which === 'session') ? '' : 'none';
        document.getElementById('pm-fw-pane-chat').style.display = (which === 'chat') ? '' : 'none';
    }
    Array.prototype.forEach.call($fwModal.querySelectorAll('.pm-fw-tab'), function (b) {
        b.addEventListener('click', function () { switchFwTab(b.dataset.fwtab); });
    });
    $fwChatSearch.addEventListener('input', function () { renderFwChatUsers($fwChatSearch.value); });
    document.getElementById('pm-forward-close').addEventListener('click', function () { $fwModal.style.display = 'none'; });

    /* ---------------- lightbox ảnh: lăn chuột phóng to/thu nhỏ, xoay, tải xuống, chia sẻ qua chat ---------------- */
    var $lb = document.getElementById('pm-lightbox');
    var $lbStage = document.getElementById('pm-lightbox-stage');
    var $lbImg = document.getElementById('pm-lightbox-img');
    var $lbZoomLabel = document.getElementById('pm-lightbox-zoom');
    var $lbToolbar = document.getElementById('pm-lightbox-toolbar');
    var $lbShareBtn = $lbToolbar.querySelector('[data-lbt="share"]');
    var LB_MIN = 0.2, LB_MAX = 8;
    var lb = { mid: 0, name: '', scale: 1, tx: 0, ty: 0, rot: 0, drag: false, sx: 0, sy: 0 };

    function lbApply() {
        $lbImg.style.transform = 'translate(' + lb.tx + 'px,' + lb.ty + 'px) scale(' + lb.scale + ') rotate(' + lb.rot + 'deg)';
        $lbZoomLabel.textContent = Math.round(lb.scale * 100) + '%';
        $lbStage.classList.toggle('is-zoomed', lb.scale > 1.001);
    }
    function lbSetZoom(scale) {
        lb.scale = Math.max(LB_MIN, Math.min(LB_MAX, scale));
        if (lb.scale <= 1) { lb.tx = 0; lb.ty = 0; }
        lbApply();
    }
    function lbRotate(delta) { lb.rot = (lb.rot + delta + 360) % 360; lbApply(); }
    function lbReset() { lb.scale = 1; lb.tx = 0; lb.ty = 0; lb.rot = 0; lbApply(); }
    function lbDownload() {
        if (!$lbImg.src) return;
        var a = document.createElement('a');
        a.href = $lbImg.src;
        a.download = lb.name || ($lbImg.src.split('?')[0].split('/').pop() || 'image');
        document.body.appendChild(a); a.click(); a.remove();
    }
    function openLightbox(src, mid, name) {
        lb = { mid: mid || 0, name: name || '', scale: 1, tx: 0, ty: 0, rot: 0, drag: false, sx: 0, sy: 0 };
        $lbImg.src = src;
        $lbShareBtn.style.display = lb.mid ? '' : 'none';
        lbApply();
        $lb.style.display = '';
    }
    function closeLightbox() { $lb.style.display = 'none'; }
    document.getElementById('pm-lightbox-close').addEventListener('click', closeLightbox);
    // bấm nền tối (ngoài ảnh) để đóng — chỉ khi chưa phóng to, tránh đóng nhầm lúc đang kéo xem ảnh lớn.
    $lbStage.addEventListener('click', function (e) { if (e.target === $lbStage && lb.scale <= 1) closeLightbox(); });
    $lbToolbar.addEventListener('click', function (e) {
        var b = e.target.closest('button'); if (!b) return;
        var t = b.dataset.lbt;
        if (t === 'in') lbSetZoom(lb.scale + 0.25);
        else if (t === 'out') lbSetZoom(lb.scale - 0.25);
        else if (t === 'reset') lbReset();
        else if (t === 'rot-left') lbRotate(-90);
        else if (t === 'rot-right') lbRotate(90);
        else if (t === 'download') lbDownload();
        else if (t === 'share' && lb.mid) { closeLightbox(); openForward(lb.mid); }
    });
    // lăn chuột để phóng to/thu nhỏ quanh vị trí đang xem
    $lbStage.addEventListener('wheel', function (e) {
        if ($lb.style.display === 'none') return;
        e.preventDefault();
        lbSetZoom(lb.scale + (e.deltaY < 0 ? 0.15 : -0.15));
    }, { passive: false });
    // kéo để xem ảnh khi đã phóng to
    $lbStage.addEventListener('mousedown', function (e) {
        if (lb.scale <= 1) return;
        e.preventDefault();
        lb.drag = true; lb.sx = e.clientX - lb.tx; lb.sy = e.clientY - lb.ty;
        $lbStage.classList.add('is-grabbing');
    });
    document.addEventListener('mousemove', function (e) {
        if (!lb.drag) return;
        lb.tx = e.clientX - lb.sx; lb.ty = e.clientY - lb.sy;
        lbApply();
    });
    document.addEventListener('mouseup', function () { lb.drag = false; $lbStage.classList.remove('is-grabbing'); });
    document.addEventListener('keydown', function (e) {
        if ($lb.style.display === 'none') return;
        if (e.key === 'Escape') closeLightbox();
        else if (e.key === '+' || e.key === '=') lbSetZoom(lb.scale + 0.25);
        else if (e.key === '-') lbSetZoom(lb.scale - 0.25);
    });

    /* ---------------- zoom bảng (xem dạng ảnh lớn) ---------------- */
    var $zoom = document.getElementById('pm-zoom');
    var $zoomBox = document.getElementById('pm-zoom-box');
    function openZoom(html) { $zoomBox.innerHTML = html; $zoom.style.display = ''; }
    $zoom.addEventListener('click', function (e) { if (e.target === $zoom) $zoom.style.display = 'none'; });

    /* ---------------- thanh tin nhắn đã ghim ---------------- */
    var $pinnedBar = document.getElementById('pm-pinned-bar');
    var $pinnedList = document.getElementById('pm-pinned-list');
    function pinPreview(m) {
        if (m.body) return m.body;
        var map = { image: '[Hình ảnh]', file: '[Tệp]', checklist: '[Todo List]', vote: '[Bình chọn]', table: '[Bảng]', tree: '[Sơ đồ cây]', process: '[Quy trình]', canvas: '[Bản thiết kế]' };
        return map[m.type] || '[Tin nhắn]';
    }
    function renderPinned(list) {
        if (!list || !list.length) { $pinnedBar.style.display = 'none'; $pinnedList.innerHTML = ''; return; }
        $pinnedBar.style.display = '';
        $pinnedList.innerHTML = '';
        list.forEach(function (m) {
            var item = document.createElement('div'); item.className = 'pm-pinned-item';
            item.innerHTML = '<span class="pm-pinned-text"><b>' + X.esc(m.sender_name) + ':</b> ' + X.esc(pinPreview(m).slice(0, 70)) + '</span>' +
                '<button type="button" class="pm-unpin" title="Tháo ghim"><i class="fa-solid fa-xmark"></i></button>';
            item.querySelector('.pm-pinned-text').addEventListener('click', function () { api.scrollTo(m.id); });
            item.querySelector('.pm-unpin').addEventListener('click', function (e) {
                e.stopPropagation();
                X.post('pinToggle', { message_id: m.id }).then(function () {
                    var el = state.els[m.id]; if (el && el._data) { el._data.pinned = false; replaceEl(el._data); }
                    fetchPinned();
                });
            });
            $pinnedList.appendChild(item);
        });
    }
    function fetchPinned() { X.get('pinned', { session_id: state.sid }).then(function (res) { if (res.ok) renderPinned(res.pinned); }); }

    /* ---------------- "Đã xem" (chỉ avatar, gắn dưới TIN CỦA TÔI) ---------------- */
    function setReaders(readers) {
        state.readers = readers || [];
        renderSeen();
    }
    // Chỉ tính "đã xem" khi user focus ô nhập tin (không tự đánh dấu ở poll).
    function markRead() {
        if (!state.sid) return;
        X.post('markRead', { session_id: state.sid }).then(function (res) {
            if (res && res.ok) setReaders(res.readers);
        });
    }
    function lastRealMessage() {
        for (var i = state.order.length - 1; i >= 0; i--) {
            var el = state.els[state.order[i]];
            if (el && el._data && el._data.type !== 'system') return el;
        }
        return null;
    }
    function renderSeen() {
        var old = $list.querySelector('.pm-seen'); if (old) old.remove();
        if (!state.readers.length || !state.order.length) return;
        var lastEl = lastRealMessage();
        // "Đã xem" CHỈ gắn dưới tin của TÔI (phía nhận không hiện avatar người gửi).
        if (!lastEl || !lastEl._data || lastEl._data.sender_id !== PM.meId) return;
        var lastId = lastEl._data.id;
        var seen = state.readers.filter(function (r) { return r.last_read_message_id >= lastId; });
        if (!seen.length) return;
        var box = document.createElement('div');
        box.className = 'pm-seen';
        box.innerHTML = seen.map(function (r) {
            return X.avatarHtml(r.fullname, r.avatar, 'pm-seen-avatar');
        }).join('');
        box.title = 'Đã xem: ' + seen.map(function (r) { return r.fullname; }).join(', ');
        lastEl.insertAdjacentElement('afterend', box);
    }

    /* ---------------- grip kéo mở rộng ô nhập (đặt ở trên) ---------------- */
    (function () {
        var grip = document.getElementById('pm-composer-grip');
        if (!grip) return;
        var dragging = false, startY = 0, startH = 0;
        grip.addEventListener('mousedown', function (e) {
            dragging = true; startY = e.clientY; startH = $input.offsetHeight;
            e.preventDefault(); document.body.style.userSelect = 'none';
        });
        document.addEventListener('mousemove', function (e) {
            if (!dragging) return;
            var h = Math.max(44, Math.min(400, startH + (startY - e.clientY))); // kéo lên = cao hơn
            $input.style.height = h + 'px';
        });
        document.addEventListener('mouseup', function () { dragging = false; document.body.style.userSelect = ''; });
    })();

    /* ---------------- gửi tin ---------------- */
    function renderAttachPrev() {
        $attachPrev.innerHTML = '';
        state.attachments.forEach(function (f, i) {
            var chip = document.createElement('span'); chip.className = 'pm-attach-chip';
            if (f.type.indexOf('image/') === 0) {
                var url = URL.createObjectURL(f);
                chip.innerHTML = '<img src="' + url + '"> ' + X.esc(f.name.slice(0, 18));
            } else {
                chip.innerHTML = '<i class="fa-solid fa-file"></i> ' + X.esc(f.name.slice(0, 22));
            }
            var x = document.createElement('button'); x.innerHTML = '<i class="fa-solid fa-xmark"></i>';
            x.addEventListener('click', function () { state.attachments.splice(i, 1); renderAttachPrev(); });
            chip.appendChild(x);
            $attachPrev.appendChild(chip);
        });
    }

    function send(extra) {
        extra = extra || {};
        var body = ($input.value || '').trim();
        var type = extra.type || 'text';
        var hasSpecial = extra.payload !== undefined;
        if (!body && !state.attachments.length && !hasSpecial) return;

        var fd = new FormData();
        fd.append('session_id', state.sid);
        fd.append('type', type);
        fd.append('body', body);
        if (state.replyTo) fd.append('reply_to_id', state.replyTo.id);
        if (hasSpecial) fd.append('payload', typeof extra.payload === 'string' ? extra.payload : JSON.stringify(extra.payload));
        // @nhắc tên: gom id thành viên có tên xuất hiện sau '@' trong nội dung.
        var mentions = [];
        (X.state.members || []).forEach(function (m) { if (body.indexOf('@' + m.fullname) !== -1) mentions.push(m.user_id); });
        if (mentions.length) fd.append('mentions', JSON.stringify(mentions));
        state.attachments.forEach(function (f) { fd.append('files[]', f); });

        $send.disabled = true;
        X.post('send', fd).then(function (res) {
            $send.disabled = false;
            if (!res.ok) { X.toast(res.message || 'Không gửi được.'); return; }
            $input.value = ''; $input.style.height = '';
            state.attachments = []; renderAttachPrev();
            clearReply();
            if (res.skipped && res.skipped.length) X.toast('Bỏ qua: ' + res.skipped.join(', '));
            if (res.message && !state.starFilter) { appendMsg(res.message); renderSeen(); scrollBottom(); }
            else if (res.message) { state.maxId = Math.max(state.maxId, res.message.id); }
        });
    }

    $send.addEventListener('click', function () { send(); });
    function autoGrow() { /* để trống: người dùng tự kéo chỉnh chiều cao (resize: vertical) */ }

    /* ----- @mention autocomplete ----- */
    var $mention = document.createElement('div');
    $mention.className = 'pm-mention-pop'; $mention.style.display = 'none';
    document.body.appendChild($mention);
    var mState = { open: false, start: 0, items: [], active: 0 };

    function hideMention() { mState.open = false; $mention.style.display = 'none'; }
    function updateMention() {
        var val = $input.value, caret = $input.selectionStart;
        var before = val.slice(0, caret);
        var m = before.match(/@([^\s@]*)$/);
        if (!m) { hideMention(); return; }
        var q = m[1].toLowerCase();
        var list = (X.state.members || []).filter(function (mem) { return mem.fullname.toLowerCase().indexOf(q) !== -1; }).slice(0, 6);
        if (!list.length) { hideMention(); return; }
        mState.open = true; mState.start = caret - m[0].length; mState.items = list; mState.active = 0;
        renderMention();
        var r = $input.getBoundingClientRect();
        $mention.style.display = '';
        $mention.style.left = r.left + 'px';
        $mention.style.top = (r.top - $mention.offsetHeight - 4) + 'px';
    }
    function renderMention() {
        $mention.innerHTML = '';
        mState.items.forEach(function (mem, i) {
            var it = document.createElement('div');
            it.className = 'pm-mention-item' + (i === mState.active ? ' active' : '');
            it.innerHTML = X.avatarHtml(mem.fullname, mem.avatar, 'pm-mem-avatar') + '<span>' + X.esc(mem.fullname) + '</span>';
            it.addEventListener('mousedown', function (e) { e.preventDefault(); pickMention(mem); });
            $mention.appendChild(it);
        });
    }
    function pickMention(mem) {
        var val = $input.value, caret = $input.selectionStart;
        var insert = '@' + mem.fullname + ' ';
        $input.value = val.slice(0, mState.start) + insert + val.slice(caret);
        var pos = mState.start + insert.length;
        $input.setSelectionRange(pos, pos);
        hideMention(); autoGrow(); $input.focus();
    }
    $input.addEventListener('input', function () { autoGrow(); updateMention(); });
    $input.addEventListener('keydown', function (e) {
        if (mState.open) {
            if (e.key === 'ArrowDown') { e.preventDefault(); mState.active = (mState.active + 1) % mState.items.length; renderMention(); return; }
            if (e.key === 'ArrowUp') { e.preventDefault(); mState.active = (mState.active - 1 + mState.items.length) % mState.items.length; renderMention(); return; }
            if (e.key === 'Enter' || e.key === 'Tab') { e.preventDefault(); pickMention(mState.items[mState.active]); return; }
            if (e.key === 'Escape') { hideMention(); return; }
        }
        if (e.key === 'Enter') {
            if (e.altKey) { // Alt+Enter = xuống dòng
                e.preventDefault();
                var s = $input.selectionStart, en = $input.selectionEnd, v = $input.value;
                $input.value = v.slice(0, s) + '\n' + v.slice(en);
                $input.selectionStart = $input.selectionEnd = s + 1;
            } else { e.preventDefault(); send(); } // Enter = gửi
        }
    });
    $input.addEventListener('blur', function () { setTimeout(hideMention, 150); });

    // "Đã xem" tính theo việc focus ô nhập tin.
    $input.addEventListener('focus', function () { state.inputFocused = true; markRead(); });
    $input.addEventListener('blur', function () { state.inputFocused = false; });

    // đính kèm
    document.getElementById('pm-tool-attach').addEventListener('click', function () { $file.click(); });
    $file.addEventListener('change', function () {
        Array.prototype.forEach.call($file.files, function (f) { state.attachments.push(f); });
        $file.value = ''; renderAttachPrev();
    });
    // Dán ảnh từ bộ nhớ tạm (Ctrl+V) ngay tại ô nhập -> đưa vào đính kèm chờ gửi.
    $input.addEventListener('paste', function (e) {
        var items = (e.clipboardData && e.clipboardData.items) || [];
        var added = 0;
        for (var i = 0; i < items.length; i++) {
            if (items[i].kind === 'file' && items[i].type.indexOf('image/') === 0) {
                var f = items[i].getAsFile();
                if (!f) continue;
                var ext = ((f.type.split('/')[1]) || 'png').replace(/[^a-z0-9]/gi, '');
                var named = new File([f], 'paste_' + Date.now() + '_' + i + '.' + ext, { type: f.type });
                state.attachments.push(named); added++;
            }
        }
        if (added) { e.preventDefault(); renderAttachPrev(); X.toast('Đã dán ' + added + ' ảnh — bấm gửi để chia sẻ.'); return; }
        // Văn bản từ .txt (Windows) dùng CRLF/CR → chuẩn hoá về LF, nếu không textarea + bubble sẽ nhân đôi dòng.
        var text = (e.clipboardData && (e.clipboardData.getData('text/plain') || e.clipboardData.getData('text'))) || '';
        if (text.indexOf('\r') !== -1) {
            e.preventDefault();
            var norm = text.replace(/\r\n|\r/g, '\n');
            var s = $input.selectionStart, en = $input.selectionEnd, v = $input.value;
            $input.value = v.slice(0, s) + norm + v.slice(en);
            var pos = s + norm.length;
            $input.setSelectionRange(pos, pos);
            autoGrow(); updateMention();
        }
    });
    // emoji vào ô nhập
    document.getElementById('pm-tool-emoji').addEventListener('click', function () {
        openEmoji(this, function (e) { $input.value += e; $input.focus(); autoGrow(); });
    });
    // 4 trình soạn
    document.querySelectorAll('.pm-tool[data-composer]').forEach(function (btn) {
        btn.addEventListener('click', function () {
            window.PMComposers.open(btn.dataset.composer, function (type, payload) {
                send({ type: type, payload: payload });
            });
        });
    });

    /* ---------------- load ---------------- */
    function clearAll() { $list.innerHTML = ''; state.els = {}; state.order = []; state.maxId = 0; state.oldestId = 0; state.readers = []; }

    function loadInitial() {
        clearAll();
        X.get('messages', { session_id: state.sid, starred: state.starFilter ? 1 : 0 }).then(function (res) {
            if (!res.ok) return;
            res.messages.forEach(function (m) { appendMsg(m); });
            $loadMore.style.display = res.has_more ? '' : 'none';
            scrollBottom();
            setReaders(res.readers);
        });
        fetchPinned();
    }
    $loadMore.querySelector('button').addEventListener('click', function () {
        if (!state.oldestId) return;
        var keepH = $wrap.scrollHeight;
        X.get('messages', { session_id: state.sid, before_id: state.oldestId, starred: state.starFilter ? 1 : 0 }).then(function (res) {
            if (!res.ok) return;
            res.messages.reverse().forEach(function (m) { appendMsg(m, true); });
            $loadMore.style.display = res.has_more ? '' : 'none';
            $wrap.scrollTop = $wrap.scrollHeight - keepH;
        });
    });

    /* ---------------- poll apply ---------------- */
    function applyUpdates(u) {
        if (!u) return;
        // reactions
        if (u.reactions) Object.keys(u.reactions).forEach(function (id) {
            if (state.els[id] && state.els[id]._data) { state.els[id]._data.reactions = u.reactions[id]; replaceEl(state.els[id]._data); }
        });
        // recalled
        (u.recalled || []).forEach(function (id) {
            if (state.els[id] && state.els[id]._data && !state.els[id]._data.recalled) {
                state.els[id]._data.recalled = true; replaceEl(state.els[id]._data);
            }
        });
        // starred set
        if (u.starred) {
            var sset = {}; u.starred.forEach(function (id) { sset[id] = true; });
            state.order.forEach(function (id) {
                var el = state.els[id]; if (!el || !el._data) return;
                var want = !!sset[id];
                if (!!el._data.starred !== want) { el._data.starred = want; replaceEl(el._data); }
                if (state.starFilter && !want) removeEl(id);
            });
        }
        // checklist
        if (u.checklist) Object.keys(u.checklist).forEach(function (id) {
            if (state.els[id] && state.els[id]._data && state.els[id]._data.type === 'checklist') {
                state.els[id]._data.checklist = u.checklist[id]; replaceEl(state.els[id]._data);
            }
        });
        // pinned set
        if (u.pinned) {
            var pset = {}; u.pinned.forEach(function (id) { pset[id] = true; });
            state.order.forEach(function (id) {
                var el = state.els[id]; if (!el || !el._data) return;
                var want = !!pset[id];
                if (!!el._data.pinned !== want) { el._data.pinned = want; replaceEl(el._data); }
            });
        }
        // votes
        if (u.votes) Object.keys(u.votes).forEach(function (id) {
            if (state.els[id] && state.els[id]._data && state.els[id]._data.type === 'vote') {
                state.els[id]._data.votes = u.votes[id]; replaceEl(state.els[id]._data);
            }
        });
    }

    /* ---------------- public API ---------------- */
    var api = {
        init: function (sid) { state.sid = sid; state.starFilter = false; loadInitial(); },
        switchSession: function (sid) {
            state.sid = sid;
            // reset star filter UI
            var sb = document.getElementById('pm-btn-star-filter');
            sb.classList.remove('active'); sb.querySelector('i').className = 'fa-regular fa-star';
            state.starFilter = false;
            clearReply();
            loadInitial();
        },
        setStarFilter: function (on) { state.starFilter = on; loadInitial(); },
        lastId: function () { return state.maxId; },
        scrollTo: function (id) {
            var el = state.els[id]; if (!el) { X.toast('Tin nhắn không còn trong khung hiện tại.'); return; }
            el.scrollIntoView({ behavior: 'smooth', block: 'center' });
            el.classList.add('pm-flash'); setTimeout(function () { el.classList.remove('pm-flash'); }, 1600);
        },
        sendShapeNote: function (shapeId, text) {
            var fd = new FormData();
            fd.append('session_id', state.sid); fd.append('type', 'text');
            fd.append('body', text); fd.append('shape_ref', shapeId);
            return X.post('send', fd).then(function (res) {
                if (res.ok && res.message && !state.starFilter) { appendMsg(res.message); scrollBottom(); }
                return res.ok ? res.message : null;
            });
        },
        onPoll: function (messages, updates, readers) {
            var stick = atBottom();
            var gotNew = false;
            (messages || []).forEach(function (m) {
                if (m.id > state.maxId) state.maxId = m.id;
                if (state.starFilter) { if (m.starred && !state.els[m.id]) appendMsg(m); }
                else if (!state.els[m.id]) { appendMsg(m); gotNew = true; }
            });
            applyUpdates(updates);
            setReaders(readers !== undefined ? readers : state.readers);
            if (stick) scrollBottom();
            // có tin mới về trong khi đang focus ô nhập → cũng tính là đã xem
            if (gotNew && state.inputFocused) markRead();
        },
        onPinned: function (list) { renderPinned(list); }
    };
    return api;
})();
