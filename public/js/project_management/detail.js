/* ===== Quản lý dự án — detail.js : core helpers + điều phối =====
 * Loaded LAST. Định nghĩa PMX core (post/esc/toast...) rồi khởi động
 * chat (PMChat), canvas (PMCanvas) và vòng poll.
 */
window.PMX = window.PMX || {};
(function () {
    'use strict';
    var PM = window.PM;
    var X = window.PMX;

    /* ---------- core helpers ---------- */
    X.base = PM.base;
    X.esc = function (s) {
        return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) {
            return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c];
        });
    };
    // Chuẩn hoá xuống dòng: CRLF/CR/LF đều thành 1 <br> (tránh nhân đôi dòng khi dán từ .txt Windows).
    X.nl2br = function (s) { return X.esc(s).replace(/\r\n|\r|\n/g, '<br>'); };

    // POST: data có thể là object hoặc FormData (cho upload file)
    X.post = function (action, data) {
        var opt = { method: 'POST' };
        if (data instanceof FormData) {
            opt.body = data;
        } else {
            var body = new URLSearchParams();
            Object.keys(data || {}).forEach(function (k) {
                var v = data[k];
                if (Array.isArray(v)) v = JSON.stringify(v);
                else if (v && typeof v === 'object') v = JSON.stringify(v);
                body.append(k, v);
            });
            opt.headers = { 'Content-Type': 'application/x-www-form-urlencoded' };
            opt.body = body;
        }
        return fetch(X.base + action, opt).then(function (r) { return r.json(); })
            .catch(function () { return { ok: false, message: 'Lỗi kết nối.' }; });
    };
    X.get = function (action, params) {
        var qs = Object.keys(params || {}).map(function (k) { return k + '=' + encodeURIComponent(params[k]); }).join('&');
        return fetch(X.base + action + (qs ? '&' + qs : '')).then(function (r) { return r.json(); })
            .catch(function () { return { ok: false }; });
    };

    var toastT;
    X.toast = function (msg) {
        var t = document.querySelector('.pm-toast');
        if (!t) { t = document.createElement('div'); t.className = 'pm-toast'; document.body.appendChild(t); }
        t.textContent = msg;
        clearTimeout(toastT);
        toastT = setTimeout(function () { if (t.parentNode) t.parentNode.removeChild(t); }, 2600);
    };

    X.initial = function (name) { return (name || '?').trim().charAt(0).toUpperCase(); };
    X.avatarHtml = function (name, url, cls) {
        cls = cls || 'pm-msg-avatar';
        if (url) return '<div class="' + cls + '"><img src="' + X.esc(url) + '" alt=""></div>';
        return '<div class="' + cls + '">' + X.esc(X.initial(name)) + '</div>';
    };
    X.fmtTime = function (dt) {
        if (!dt) return '';
        var d = new Date(dt.replace(' ', 'T'));
        if (isNaN(d)) return dt;
        var now = new Date();
        var hm = ('0' + d.getHours()).slice(-2) + ':' + ('0' + d.getMinutes()).slice(-2);
        if (d.toDateString() === now.toDateString()) return hm;
        return ('0' + d.getDate()).slice(-2) + '/' + ('0' + (d.getMonth() + 1)).slice(-2) + ' ' + hm;
    };

    /* ---------- shared state ---------- */
    X.state = {
        projectId: PM.projectId,
        sessionId: PM.firstSession,
        sessions: PM.sessions || [],
        members: PM.members || [],
        invited: (PM.invited || []).map(function (x) { return parseInt(x, 10); }),
        isLeader: PM.isLeader,
        meId: PM.meId
    };

    function sessionById(id) {
        id = parseInt(id, 10);
        for (var i = 0; i < X.state.sessions.length; i++) if (parseInt(X.state.sessions[i].id, 10) === id) return X.state.sessions[i];
        return null;
    }
    X.sessionById = sessionById;

    /* ---------- DOM refs ---------- */
    var $sessionName = document.getElementById('pm-session-name');
    var $memberCount = document.getElementById('pm-member-count');

    function renderSessionName() {
        var s = sessionById(X.state.sessionId);
        $sessionName.textContent = s ? s.name : '—';
    }

    /* ---------- Session switching ---------- */
    // Ghi nhớ session đang làm việc (cá nhân hóa theo user) để lần sau vào lại đúng.
    function recordActiveSession(sid) { if (sid) X.post('setActiveSession', { session_id: sid }); }

    X.switchSession = function (sid) {
        sid = parseInt(sid, 10);
        if (!sid || sid === X.state.sessionId) { closeAllPops(); return; }
        X.state.sessionId = sid;
        renderSessionName();
        renderSessionHistory();
        window.PMChat.switchSession(sid);
        window.PMCanvas.switchSession(sid);
        recordActiveSession(sid);
        closeAllPops();
    };

    // Ghi nhớ chính xác thời điểm rời trang (best-effort).
    window.addEventListener('beforeunload', function () {
        try {
            if (!X.state.sessionId || !navigator.sendBeacon) return;
            var fd = new FormData(); fd.append('session_id', X.state.sessionId);
            navigator.sendBeacon(X.base + 'setActiveSession', fd);
        } catch (e) {}
    });

    /* ---------- Session history popup ---------- */
    var $history = document.getElementById('pm-session-history');
    var $sessionList = document.getElementById('pm-session-list');

    function renderSessionHistory() {
        $sessionList.innerHTML = '';
        X.state.sessions.forEach(function (s) {
            var li = document.createElement('li');
            if (parseInt(s.id, 10) === X.state.sessionId) li.className = 'active';
            li.innerHTML = '<span class="pm-sess-title">' + X.esc(s.name) + '</span>' +
                '<span class="pm-sess-act">' +
                '<button data-act="rename" title="Đổi tên"><i class="fa-solid fa-pen"></i></button>' +
                '<button data-act="delete" title="Xóa"><i class="fa-solid fa-trash"></i></button></span>';
            li.querySelector('.pm-sess-title').addEventListener('click', function () { X.switchSession(s.id); });
            li.querySelector('[data-act=rename]').addEventListener('click', function (e) {
                e.stopPropagation();
                var name = prompt('Đổi tên session:', s.name);
                if (name === null) return;
                name = name.trim(); if (!name) return;
                X.post('sessionRename', { session_id: s.id, name: name }).then(function (res) {
                    if (res.ok) { X.state.sessions = res.sessions; renderSessionName(); renderSessionHistory(); }
                });
            });
            li.querySelector('[data-act=delete]').addEventListener('click', function (e) {
                e.stopPropagation();
                if (X.state.sessions.length <= 1) { X.toast('Phải còn ít nhất 1 session.'); return; }
                if (!confirm('Xóa session "' + s.name + '"? Toàn bộ tin nhắn & bản vẽ của session này sẽ mất.')) return;
                X.post('sessionDelete', { session_id: s.id }).then(function (res) {
                    if (!res.ok) { X.toast(res.message || 'Không xóa được.'); return; }
                    X.state.sessions = res.sessions;
                    if (parseInt(s.id, 10) === X.state.sessionId) X.switchSession(X.state.sessions[0].id);
                    else renderSessionHistory();
                });
            });
            $sessionList.appendChild(li);
        });
    }

    document.getElementById('pm-btn-history').addEventListener('click', function (e) {
        e.stopPropagation();
        var open = $history.style.display !== 'none';
        closeAllPops();
        if (!open) { renderSessionHistory(); $history.style.display = ''; }
    });

    // Menu mẫu khi tạo session mới
    var $tplMenu = document.createElement('div');
    $tplMenu.className = 'pm-tpl-menu'; $tplMenu.style.display = 'none';
    $tplMenu.innerHTML =
        '<div class="pm-tpl-head">Tạo session</div>' +
        '<button data-tpl=""><i class="fa-regular fa-file"></i> Trống</button>' +
        '<button data-tpl="db"><i class="fa-solid fa-table"></i> Mẫu: Thiết kế CSDL</button>' +
        '<button data-tpl="module"><i class="fa-solid fa-sitemap"></i> Mẫu: Sơ đồ module/view</button>' +
        '<button data-tpl="process"><i class="fa-solid fa-diagram-project"></i> Mẫu: Quy trình</button>';
    document.body.appendChild($tplMenu);
    function createSession(tpl) {
        $tplMenu.style.display = 'none';
        X.post('sessionCreate', { project_id: X.state.projectId, name: '', template: tpl || '' }).then(function (res) {
            if (!res.ok) { X.toast(res.message || 'Không tạo được session.'); return; }
            X.state.sessions = res.sessions;
            X.switchSession(res.session.id);
            X.toast('Đã tạo "' + res.session.name + '"');
        });
    }
    $tplMenu.querySelectorAll('button').forEach(function (b) {
        b.addEventListener('click', function () { createSession(b.dataset.tpl); });
    });
    document.getElementById('pm-btn-new-session').addEventListener('click', function (e) {
        e.stopPropagation();
        if ($tplMenu.style.display !== 'none') { $tplMenu.style.display = 'none'; return; }
        closeAllPops();
        var r = this.getBoundingClientRect();
        $tplMenu.style.display = '';
        $tplMenu.style.top = (r.bottom + 4) + 'px';
        $tplMenu.style.left = Math.max(10, Math.min(r.left, window.innerWidth - 250)) + 'px';
    });
    document.addEventListener('click', function (e) {
        if (!e.target.closest('.pm-tpl-menu') && !e.target.closest('#pm-btn-new-session')) $tplMenu.style.display = 'none';
    });

    // đổi tên session bằng nhấp đúp lên tiêu đề
    $sessionName.addEventListener('dblclick', function () {
        var s = sessionById(X.state.sessionId); if (!s) return;
        var name = prompt('Đổi tên session:', s.name);
        if (name === null) return; name = name.trim(); if (!name) return;
        X.post('sessionRename', { session_id: s.id, name: name }).then(function (res) {
            if (res.ok) { X.state.sessions = res.sessions; renderSessionName(); }
        });
    });

    /* ---------- Members panel ---------- */
    var $membersPop = document.getElementById('pm-members-pop');
    var $membersList = document.getElementById('pm-members-list');
    var $addWrap = document.getElementById('pm-add-member-wrap');
    var $userPick = document.getElementById('pm-user-pick');
    var $memberSearch = document.getElementById('pm-member-search');

    function renderMembers() {
        $memberCount.textContent = X.state.members.length;
        $membersList.innerHTML = '';
        var leaderCount = X.state.members.filter(function (m) { return m.is_leader; }).length;
        X.state.members.forEach(function (m) {
            var li = document.createElement('li');
            var menu = '';
            if (X.state.isLeader && m.user_id !== X.state.meId) {
                menu = '<div class="pm-mem-menu">' +
                    '<button data-act="leader" title="Trao quyền trưởng dự án"><i class="fa-solid fa-crown"></i></button>' +
                    (m.is_leader ? '' : '<button data-act="remove" title="Xóa khỏi dự án"><i class="fa-solid fa-user-minus"></i></button>') +
                    '</div>';
            }
            li.innerHTML = X.avatarHtml(m.fullname, m.avatar, 'pm-mem-avatar') +
                '<div class="pm-mem-info"><div class="pm-mem-name">' + X.esc(m.fullname) +
                (m.user_id === X.state.meId ? ' (bạn)' : '') + '</div>' +
                '<div class="pm-mem-role ' + (m.is_leader ? '' : 'member') + '">' + (m.is_leader ? 'Trưởng dự án' : 'Thành viên') + '</div></div>' + menu;
            var lb = li.querySelector('[data-act=leader]');
            if (lb) lb.addEventListener('click', function () {
                if (!confirm('Trao quyền trưởng dự án cho ' + m.fullname + '? Bạn sẽ thành thành viên thường.')) return;
                X.post('transferLeader', { project_id: X.state.projectId, user_id: m.user_id }).then(function (res) {
                    if (!res.ok) { X.toast(res.message || 'Lỗi'); return; }
                    X.state.members = res.members; X.state.isLeader = false; renderMembers(); X.toast('Đã trao quyền.');
                });
            });
            var rb = li.querySelector('[data-act=remove]');
            if (rb) rb.addEventListener('click', function () {
                if (!confirm('Xóa ' + m.fullname + ' khỏi dự án?')) return;
                X.post('removeMember', { project_id: X.state.projectId, user_id: m.user_id }).then(function (res) {
                    if (!res.ok) { X.toast(res.message || 'Lỗi'); return; }
                    X.state.members = res.members; renderMembers();
                });
            });
            // Bấm vào thành viên (trừ menu thao tác) → thẻ thông tin + xem avatar (kể cả chính mình).
            li.style.cursor = 'pointer';
            li.addEventListener('click', function (e) {
                if (e.target.closest('.pm-mem-menu')) return;
                if (window.UserCard) UserCard.show(m.user_id);
            });
            $membersList.appendChild(li);
        });
        $addWrap.style.display = X.state.isLeader ? '' : 'none';
    }

    function renderUserPick(filter) {
        filter = (filter || '').toLowerCase();
        var memberIds = X.state.members.map(function (m) { return m.user_id; });
        $userPick.innerHTML = '';
        (PM.allUsers || []).filter(function (u) {
            return memberIds.indexOf(u.id) === -1 && u.fullname.toLowerCase().indexOf(filter) !== -1;
        }).forEach(function (u) {
            var pending = X.state.invited.indexOf(u.id) !== -1;
            var li = document.createElement('li');
            li.innerHTML = X.avatarHtml(u.fullname, u.avatar, 'pm-mem-avatar') +
                '<div class="pm-mem-info"><div class="pm-mem-name">' + X.esc(u.fullname) + '</div></div>' +
                (pending
                    ? '<span class="pm-invite-status">Đã mời</span>'
                    : '<button type="button" class="pm-invite-btn">Mời</button>');
            if (!pending) li.querySelector('.pm-invite-btn').addEventListener('click', function (e) {
                e.stopPropagation();
                X.post('inviteMembers', { project_id: X.state.projectId, user_ids: [u.id] }).then(function (res) {
                    if (!res.ok) { X.toast(res.message || 'Lỗi'); return; }
                    X.state.members = res.members;
                    X.state.invited = (res.invited || []).map(function (x) { return parseInt(x, 10); });
                    renderMembers(); renderUserPick($memberSearch.value);
                    X.toast('Đã gửi lời mời tới ' + u.fullname);
                });
            });
            $userPick.appendChild(li);
        });
    }
    $memberSearch.addEventListener('input', function () { renderUserPick(this.value); });

    document.getElementById('pm-btn-members').addEventListener('click', function (e) {
        e.stopPropagation();
        var open = $membersPop.style.display !== 'none';
        closeAllPops();
        if (!open) {
            X.post('members', { project_id: X.state.projectId }).then(function (res) {
                if (res.ok) {
                    X.state.members = res.members; X.state.isLeader = res.is_leader;
                    X.state.invited = (res.invited || []).map(function (x) { return parseInt(x, 10); });
                }
                renderMembers(); renderUserPick(''); $membersPop.style.display = '';
            });
        }
    });
    $membersPop.querySelector('[data-close]').addEventListener('click', closeAllPops);

    /* ---------- Rời dự án (có chế độ im lặng; trưởng phải trao quyền) ---------- */
    var $leaveModal = document.getElementById('pm-leave-modal');
    var $leaveMsg = document.getElementById('pm-leave-msg');
    var $leaveNewWrap = document.getElementById('pm-leave-newleader');
    var $leaveSelect = document.getElementById('pm-leave-leader-select');
    var $leaveSilent = document.getElementById('pm-leave-silent');

    function openLeaveModal() {
        $leaveMsg.textContent = 'Bạn chắc chắn muốn rời dự án này?';
        $leaveNewWrap.style.display = 'none';
        $leaveSelect.innerHTML = '';
        $leaveSilent.checked = false;
        $leaveModal.style.display = '';
    }
    function closeLeaveModal() { $leaveModal.style.display = 'none'; }

    document.getElementById('pm-btn-leave').addEventListener('click', function () { closeAllPops(); openLeaveModal(); });
    document.getElementById('pm-leave-close').addEventListener('click', closeLeaveModal);
    document.getElementById('pm-leave-cancel').addEventListener('click', closeLeaveModal);
    $leaveModal.addEventListener('click', function (e) { if (e.target === $leaveModal) closeLeaveModal(); });

    document.getElementById('pm-leave-confirm').addEventListener('click', function () {
        var data = { project_id: X.state.projectId, silent: $leaveSilent.checked ? 1 : 0 };
        if ($leaveNewWrap.style.display !== 'none' && $leaveSelect.value) data.new_leader_id = $leaveSelect.value;
        var btn = this; btn.disabled = true;
        X.post('leaveProject', data).then(function (res) {
            btn.disabled = false;
            if (res.ok) {
                window.location.href = X.base + 'dashboard';
                return;
            }
            if (res.need_leader) {
                // Trưởng dự án: bắt chọn người trao quyền trước khi rời.
                $leaveMsg.textContent = 'Bạn là trưởng dự án — hãy trao quyền cho một thành viên trước khi rời.';
                $leaveSelect.innerHTML = '';
                (res.members || []).forEach(function (m) {
                    var op = document.createElement('option');
                    op.value = m.user_id; op.textContent = m.fullname;
                    $leaveSelect.appendChild(op);
                });
                $leaveNewWrap.style.display = '';
                return;
            }
            X.toast(res.message || 'Không rời được dự án.');
        });
    });

    /* ---------- Tìm kiếm tin nhắn (theo từ khóa, trong session hiện tại) ---------- */
    var $searchPop = document.getElementById('pm-search-pop');
    var $searchInput = document.getElementById('pm-search-input');
    var $searchResults = document.getElementById('pm-search-results');
    var $searchHint = document.getElementById('pm-search-hint');
    var searchTimer = null;

    function hiliteKw(text, kw) {
        var t = X.esc(text);
        if (!kw) return t;
        // escape regex đặc biệt trong từ khóa
        var safe = kw.replace(/[.*+?^${}()|[\]\\]/g, '\\$&');
        try { return t.replace(new RegExp('(' + safe + ')', 'gi'), '<mark>$1</mark>'); } catch (e) { return t; }
    }
    function renderSearch(results, kw) {
        $searchResults.innerHTML = '';
        if (!kw) { $searchHint.style.display = ''; $searchHint.textContent = 'Nhập từ khóa để tìm tin nhắn trong session này.'; return; }
        if (!results.length) { $searchHint.style.display = ''; $searchHint.textContent = 'Không tìm thấy tin nhắn nào khớp.'; return; }
        $searchHint.style.display = 'none';
        results.forEach(function (m) {
            var li = document.createElement('li');
            li.innerHTML = '<div class="pm-sr-top"><b>' + X.esc(m.sender_name) + '</b><span>' + X.fmtTime(m.created_at) + '</span></div>' +
                '<div class="pm-sr-body">' + hiliteKw(m.body || '[tin nhắn]', kw) + '</div>';
            li.addEventListener('click', function () { closeAllPops(); window.PMChat.scrollTo(m.id); });
            $searchResults.appendChild(li);
        });
    }
    function doSearch() {
        var q = $searchInput.value.trim();
        if (!q) { renderSearch([], ''); return; }
        X.get('search', { session_id: X.state.sessionId, q: q }).then(function (res) {
            if (res && res.ok) renderSearch(res.results || [], q);
        });
    }
    $searchInput.addEventListener('input', function () { clearTimeout(searchTimer); searchTimer = setTimeout(doSearch, 250); });
    $searchInput.addEventListener('keydown', function (e) { if (e.key === 'Enter') { clearTimeout(searchTimer); doSearch(); } });
    document.getElementById('pm-btn-search').addEventListener('click', function (e) {
        e.stopPropagation();
        var open = $searchPop.style.display !== 'none';
        closeAllPops();
        if (!open) { $searchPop.style.display = ''; $searchInput.value = ''; renderSearch([], ''); setTimeout(function () { $searchInput.focus(); }, 30); }
    });
    $searchPop.querySelector('[data-close-search]').addEventListener('click', closeAllPops);

    function closeAllPops() {
        $history.style.display = 'none';
        $membersPop.style.display = 'none';
        if ($searchPop) $searchPop.style.display = 'none';
        var emo = document.getElementById('pm-emoji-pop'); if (emo) emo.style.display = 'none';
    }
    document.addEventListener('click', function (e) {
        if (!e.target.closest('.pm-pop') && !e.target.closest('#pm-btn-history') &&
            !e.target.closest('#pm-btn-members') && !e.target.closest('.pm-msg-actions') &&
            !e.target.closest('#pm-tool-emoji') && !e.target.closest('.pm-emoji-pop')) {
            closeAllPops();
        }
    });
    X.closeAllPops = closeAllPops;

    /* ---------- Star filter ---------- */
    var $starBtn = document.getElementById('pm-btn-star-filter');
    $starBtn.addEventListener('click', function () {
        var on = $starBtn.classList.toggle('active');
        $starBtn.querySelector('i').className = on ? 'fa-solid fa-star' : 'fa-regular fa-star';
        window.PMChat.setStarFilter(on);
    });

    /* ---------- Resizer ---------- */
    (function () {
        var rez = document.getElementById('pm-resizer');
        var pane = document.getElementById('pm-chat-pane');
        var ws = document.getElementById('pm-ws');
        var dragging = false;
        rez.addEventListener('mousedown', function (e) { dragging = true; e.preventDefault(); document.body.style.userSelect = 'none'; });
        document.addEventListener('mousemove', function (e) {
            if (!dragging) return;
            var rect = ws.getBoundingClientRect();
            var w = e.clientX - rect.left;
            var min = 320, max = rect.width - 300;
            w = Math.max(min, Math.min(max, w));
            pane.style.width = w + 'px';
            pane.style.flex = '0 0 ' + w + 'px';
        });
        document.addEventListener('mouseup', function () { dragging = false; document.body.style.userSelect = ''; });
    })();

    /* ---------- Ẩn/hiện khối canvas (CỤC BỘ theo user — không ảnh hưởng người khác) ---------- */
    (function () {
        var ws = document.getElementById('pm-ws');
        var offBtn = document.getElementById('pm-btn-canvas-off');
        var showBtn = document.getElementById('pm-btn-show-canvas');
        if (!ws || !offBtn || !showBtn) return;
        var key = 'pm_canvas_off_' + X.state.projectId; // nhớ riêng cho trình duyệt của user này
        function setOff(off) {
            ws.classList.toggle('pm-canvas-off', off);
            showBtn.style.display = off ? '' : 'none';
            try { localStorage.setItem(key, off ? '1' : '0'); } catch (e) {}
        }
        offBtn.addEventListener('click', function () { setOff(true); });
        showBtn.addEventListener('click', function () { setOff(false); X.toast('Đã mở lại bản thiết kế.'); });
        try { if (localStorage.getItem(key) === '1') setOff(true); } catch (e) {} // khôi phục trạng thái cá nhân
    })();

    /* ---------- Reminder notifier (banner nổi bật, không tự biến mất ngay) ---------- */
    var firedRem = {};
    function reminderBannerWrap() {
        var w = document.getElementById('pm-rem-banners');
        if (!w) { w = document.createElement('div'); w.id = 'pm-rem-banners'; w.className = 'pm-rem-banners'; document.body.appendChild(w); }
        return w;
    }
    function showReminderBanner(r) {
        var wrap = reminderBannerWrap();
        var body = (r.message && r.message.body) ? r.message.body : '(tin nhắn)';
        var card = document.createElement('div'); card.className = 'pm-rem-card';
        card.innerHTML =
            '<div class="pm-rem-ic"><i class="fa-solid fa-bell"></i></div>' +
            '<div class="pm-rem-main">' +
            '<div class="pm-rem-title">Nhắc hẹn' + (r.note ? ': ' + X.esc(r.note) : '') + '</div>' +
            '<div class="pm-rem-sub">' + X.esc(r.project_name || '') + ' · ' + X.esc(r.session_name || '') + '</div>' +
            '<div class="pm-rem-quote">' + X.esc(String(body).slice(0, 90)) + '</div></div>' +
            '<div class="pm-rem-acts"><button type="button" class="pm-rem-view">Xem</button>' +
            '<button type="button" class="pm-rem-x"><i class="fa-solid fa-xmark"></i></button></div>';
        card.querySelector('.pm-rem-x').addEventListener('click', function () { card.remove(); });
        card.querySelector('.pm-rem-view').addEventListener('click', function () {
            card.remove();
            var msgId = r.message && r.message.id;
            if (r.session_id && parseInt(r.session_id, 10) !== X.state.sessionId) {
                X.switchSession(r.session_id);
                if (msgId) setTimeout(function () { window.PMChat.scrollTo(msgId); }, 700);
            } else if (msgId) { window.PMChat.scrollTo(msgId); }
        });
        wrap.appendChild(card);
        setTimeout(function () { if (card.parentNode) card.remove(); }, 45000); // tự ẩn sau 45s
    }
    X.handleReminders = function (list) {
        (list || []).forEach(function (r) {
            if (firedRem[r.id]) return;
            firedRem[r.id] = true;
            showReminderBanner(r);
            var txt = 'Nhắc hẹn' + (r.note ? ': ' + r.note : '') + (r.message && r.message.body ? ' — "' + r.message.body.slice(0, 40) + '"' : '');
            if (window.Notification && Notification.permission === 'granted') {
                try { new Notification('⏰ Nhắc hẹn dự án', { body: txt }); } catch (e) {}
            }
        });
    };
    if (window.Notification && Notification.permission === 'default') {
        try { Notification.requestPermission(); } catch (e) {}
    }

    /* ---------- Poll loop ---------- */
    var POLL_MS = 2800;
    function poll() {
        var sid = X.state.sessionId;
        if (!sid) return;
        X.get('poll', { session_id: sid, after_id: window.PMChat.lastId(), canvas_version: window.PMCanvas.version() })
            .then(function (res) {
                if (!res || !res.ok) return;
                if (X.state.sessionId !== sid) return; // session đã đổi giữa chừng
                window.PMChat.onPoll(res.messages || [], res.updates || {}, res.readers || []);
                window.PMChat.onPinned(res.pinned || []);
                window.PMCanvas.onPollVersion(res.canvas_version);
                X.handleReminders(res.reminders);
            });
    }
    // Nhịp nhanh khi đang mở phòng làm việc (chat + canvas), nhưng DỪNG khi tab bị ẩn
    // — xem AppPoll ở đầu public/js/shared/app_shell.js.
    if (window.AppPoll) window.AppPoll.every('pm-room', poll, { interval: POLL_MS, maxInterval: POLL_MS });
    else setInterval(poll, POLL_MS);

    /* ---------- Boot ---------- */
    renderSessionName();
    renderMembers();
    if (X.state.sessionId) {
        window.PMChat.init(X.state.sessionId);
        window.PMCanvas.init(X.state.sessionId);
        recordActiveSession(X.state.sessionId); // chốt session mở đầu (đã là session nhớ lần trước hoặc đầu tiên)
    } else {
        // dự án chưa có session (hiếm) — tạo 1 cái
        X.post('sessionCreate', { project_id: X.state.projectId, name: '' }).then(function (res) {
            if (res.ok) { X.state.sessions = res.sessions; X.switchSession(res.session.id); }
        });
    }
})();
