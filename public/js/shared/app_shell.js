/* =====================================================================
   APP SHELL — tương tác sidebar trái + header.
   - Xổ/đóng menu cha.
   - Thu gọn sidebar (lưu localStorage).
   - Dropdown user, upload avatar.
   - Đẩy ô tìm kiếm có class .js-to-header lên header.
   - Toggle sidebar trên mobile.
   ===================================================================== */
(function () {
    'use strict';

    /* ---- 0. Hiệu ứng "bay vào Lịch sử": thay cho alert() xác nhận sau khi ghi dữ liệu.
       Gọi window.appFlyToHistory(originEl[, targetSelector]) ngay tại chỗ trước đây gọi
       alert('Đã ghi...') — icon nhỏ bay từ originEl (thường là nút Ghi/Cập nhật) tới khối
       .history-bar .history (nhãn "Lịch sử"), nhãn sáng lên 1 nhịp rồi tắt, không chặn thao
       tác bằng hộp thoại OK. Đặt ngoài DOMContentLoaded để gọi được ngay khi cần, không phải
       chờ app_shell.js khởi tạo xong (thực tế luôn chạy sau vì cần user bấm nút trước). */
    window.appFlyToHistory = function (originEl, targetSelector) {
        try {
            var target = document.querySelector(targetSelector || '.history-bar .history') || document.querySelector('.history');
            if (!originEl || !target) return;
            var oRect = originEl.getBoundingClientRect();
            var tRect = target.getBoundingClientRect();
            var size = 34;
            var startX = oRect.left + oRect.width / 2 - size / 2;
            var startY = oRect.top + oRect.height / 2 - size / 2;
            var dx = (tRect.left + tRect.width / 2) - (oRect.left + oRect.width / 2);
            var dy = (tRect.top + tRect.height / 2) - (oRect.top + oRect.height / 2);

            var fly = document.createElement('div');
            fly.className = 'app-fly-history';
            fly.style.left = startX + 'px';
            fly.style.top = startY + 'px';
            fly.style.setProperty('--fh-dx', dx + 'px');
            fly.style.setProperty('--fh-dy', dy + 'px');
            fly.innerHTML = '<i class="fa-solid fa-check"></i>';
            document.body.appendChild(fly);

            var landed = false;
            function land() {
                if (landed) return;
                landed = true;
                fly.remove();
                target.classList.add('app-history-glow');
                setTimeout(function () { target.classList.remove('app-history-glow'); }, 1100);
            }
            requestAnimationFrame(function () {
                requestAnimationFrame(function () { fly.classList.add('is-arrived'); });
            });
            fly.addEventListener('transitionend', land);
            setTimeout(land, 900); // phòng hờ transitionend không bắn (tab mất focus...)
        } catch (e) {}
    };

    document.addEventListener('DOMContentLoaded', function () {
        var body = document.body;

        /* ---- 1. Menu cha: click xổ menu con (accordion mềm) ---- */
        function bindSbParent(btn) {
            btn.addEventListener('click', function () {
                var group = btn.closest('.app-sb-group');
                if (!group) return;
                var open = group.classList.toggle('is-open');
                btn.setAttribute('aria-expanded', open ? 'true' : 'false');
            });
        }
        document.querySelectorAll('.app-sb-parent').forEach(bindSbParent);

        /* ---- 1a. Thu gọn sidebar: menu con hiện dạng flyout khi hover ----
           .app-sb-nav có overflow-y:auto (để cuộn được khi danh sách dài) nên
           flyout (position:absolute) sẽ bị scrollbar cắt mất nếu để nguyên trong
           DOM. Giải pháp: khi hover, "tách" (portal) .app-sb-children ra <body>,
           định vị bằng position:fixed theo toạ độ thật của nhóm đang hover, rồi
           trả về đúng chỗ cũ khi rời chuột. */
        var activeFlyout = null; // { children, placeholder, group }
        var flyoutHideTimer = null;
        function isSidebarCollapsed() { return body.classList.contains('app-sidebar-collapsed'); }
        function cancelHideFlyout() { clearTimeout(flyoutHideTimer); }
        // Có độ trễ nhỏ: chuột phải băng qua khoảng hở giữa icon và flyout (portal
        // ra ngoài .app-sb-group) — ẩn ngay lập tức sẽ đóng trước khi kịp rê tới.
        function scheduleHideFlyout() {
            clearTimeout(flyoutHideTimer);
            flyoutHideTimer = setTimeout(hideFlyoutNow, 220);
        }
        function showFlyout(group) {
            if (!isSidebarCollapsed()) return;
            cancelHideFlyout();
            var children = group.querySelector('.app-sb-children');
            if (!children) return;
            if (activeFlyout && activeFlyout.group === group) return;
            hideFlyoutNow();
            var rect = group.getBoundingClientRect();
            var placeholder = document.createComment('sb-flyout-placeholder');
            children.parentNode.insertBefore(placeholder, children);
            children.classList.add('app-sb-flyout');
            children.style.top = Math.max(4, rect.top) + 'px';
            children.style.left = (rect.right + 4) + 'px';
            document.body.appendChild(children);
            children.addEventListener('mouseenter', cancelHideFlyout);
            children.addEventListener('mouseleave', scheduleHideFlyout);
            activeFlyout = { children: children, placeholder: placeholder, group: group };
        }
        function hideFlyoutNow() {
            clearTimeout(flyoutHideTimer);
            if (!activeFlyout) return;
            var f = activeFlyout;
            activeFlyout = null;
            f.children.removeEventListener('mouseenter', cancelHideFlyout);
            f.children.removeEventListener('mouseleave', scheduleHideFlyout);
            f.children.classList.remove('app-sb-flyout');
            f.children.style.top = '';
            f.children.style.left = '';
            f.placeholder.parentNode.insertBefore(f.children, f.placeholder);
            f.placeholder.remove();
        }
        function bindGroupFlyout(group) {
            group.addEventListener('mouseenter', function () { showFlyout(group); });
            group.addEventListener('mouseleave', scheduleHideFlyout);
        }
        document.querySelectorAll('.app-sb-group').forEach(bindGroupFlyout);

        /* Chèn nhóm "Quản trị hệ thống" vào sidebar ngay khi user NHẬN bổ nhiệm admin phó
           (thời gian thực, không cần reload trang). */
        window.__injectAdminSidebar = function () {
            var nav = document.querySelector('.app-sb-nav');
            if (!nav) return;
            if (nav.querySelector('a[href*="action=manage_permissions"]')) return; // đã có
            var base = '?mod=admin_factory&controllers=admin&action=';
            var group = document.createElement('div');
            group.className = 'app-sb-group is-open';
            group.innerHTML =
                '<button type="button" class="app-sb-parent" aria-expanded="true">'
              + '<span class="app-sb-ic"><i class="fa-solid fa-user-shield"></i></span>'
              + '<span class="app-sb-txt">Quản trị hệ thống</span>'
              + '<span class="app-sb-caret"><i class="fa-solid fa-chevron-down"></i></span>'
              + '</button>'
              + '<ul class="app-sb-children">'
              + '<li class="app-sb-child"><a href="' + base + 'manage_permissions" title="Phân quyền người dùng">'
              + '<span class="app-sb-dot"><i class="fa-solid fa-circle"></i></span><span class="app-sb-txt">Phân quyền người dùng</span></a></li>'
              + '<li class="app-sb-child"><a href="' + base + 'manage_user_list" title="Quản lý người dùng">'
              + '<span class="app-sb-dot"><i class="fa-solid fa-circle"></i></span><span class="app-sb-txt">Quản lý người dùng</span></a></li>'
              + '</ul>';
            nav.appendChild(group);
            var p = group.querySelector('.app-sb-parent');
            if (p) bindSbParent(p);
            bindGroupFlyout(group);
        };

        /* ---- 1b. Giữ nguyên vị trí cuộn của sidebar khi điều hướng ----
           Khi bấm 1 mục (kể cả .app-sb-child.is-active) trang tải lại; mặc định nav
           bị cuộn về đầu. Lưu scrollTop và khôi phục lại để trải nghiệm liền mạch. */
        var sbNav = document.querySelector('.app-sb-nav');
        if (sbNav) {
            var SB_KEY = 'app_sb_scroll';
            try {
                var savedScroll = sessionStorage.getItem(SB_KEY);
                if (savedScroll !== null) sbNav.scrollTop = parseInt(savedScroll, 10) || 0;
            } catch (e) {}
            var saveSbScroll = function () {
                try { sessionStorage.setItem(SB_KEY, String(sbNav.scrollTop)); } catch (e) {}
            };
            // Lưu ngay khi bấm liên kết trong sidebar + phòng hờ trước khi rời trang.
            sbNav.addEventListener('click', function (e) {
                if (e.target.closest('a')) saveSbScroll();
            });
            window.addEventListener('beforeunload', saveSbScroll);
        }

        /* ---- 2. Thu gọn sidebar ---- */
        var collapseBtn = document.getElementById('app-sb-collapse');
        if (collapseBtn) {
            collapseBtn.addEventListener('click', function () {
                hideFlyoutNow();
                var collapsed = body.classList.toggle('app-sidebar-collapsed');
                try { localStorage.setItem('app_sb_collapsed', collapsed ? '1' : '0'); } catch (e) {}
            });
        }

        /* ---- 2a2. Giao diện sidebar (sở thích cá nhân, lưu localStorage): dropdown xổ ra khi
           bấm "Giao diện" gồm 2 tùy biến độc lập — màu nền tự chọn và màu chữ Sáng(trắng)/Tối(đen).
           Áp dụng qua CSS custom property --app-sb-* (xem app_shell.css) để không phải hard-code lại
           từng phần tử; chống nháy giao diện khi tải trang bằng script inline ngay sau <aside>
           (sidebar-app.php) + trong header-home.php áp lại y hệt trước DOMContentLoaded. */
        var sbTheme = document.getElementById('app-sb-theme');
        var sbThemeBtn = document.getElementById('app-sb-theme-btn');
        var sbThemeDropdown = document.getElementById('app-sb-theme-dropdown');
        if (sbTheme && sbThemeBtn && sbThemeDropdown) {
            var SB_BG_KEY = 'app_sb_bg_color', SB_TEXT_KEY = 'app_sb_text_mode';

            var bgPicker  = document.getElementById('app-sb-bg-picker');
            var textOpts  = sbThemeDropdown.querySelectorAll('.app-sb-text-opt');
            var resetBtn  = document.getElementById('app-sb-theme-reset');

            function applyBg(hex) {
                if (hex) body.style.setProperty('--app-sb-bg', hex);
                else body.style.removeProperty('--app-sb-bg');
                if (bgPicker) bgPicker.value = hex || '#ffffff';
            }
            function applyTextMode(mode) {
                if (mode === 'light') {
                    body.style.setProperty('--app-sb-text', '#fff');
                    body.style.setProperty('--app-sb-text-icon', '#fff');
                    body.style.setProperty('--app-sb-text-faint', 'rgba(255,255,255,.55)');
                    body.style.setProperty('--app-sb-hover-bg', 'rgba(255,255,255,.14)');
                    body.style.setProperty('--app-sb-hover-text', '#fff');
                    body.style.setProperty('--app-sb-border', 'rgba(255,255,255,.18)');
                } else if (mode === 'dark') {
                    body.style.setProperty('--app-sb-text', '#000');
                    body.style.setProperty('--app-sb-text-icon', '#000');
                    body.style.setProperty('--app-sb-text-faint', 'rgba(0,0,0,.55)');
                    body.style.setProperty('--app-sb-hover-bg', 'rgba(0,0,0,.06)');
                    body.style.setProperty('--app-sb-hover-text', '#000');
                    body.style.setProperty('--app-sb-border', 'rgba(0,0,0,.12)');
                } else {
                    ['--app-sb-text', '--app-sb-text-icon', '--app-sb-text-faint', '--app-sb-hover-bg', '--app-sb-hover-text', '--app-sb-border']
                        .forEach(function (v) { body.style.removeProperty(v); });
                }
                textOpts.forEach(function (b) { b.classList.toggle('is-active', b.getAttribute('data-text-mode') === mode); });
            }

            var savedBg = null, savedText = null;
            try {
                savedBg = localStorage.getItem(SB_BG_KEY);
                savedText = localStorage.getItem(SB_TEXT_KEY);
            } catch (e) {}
            applyBg(savedBg);
            applyTextMode(savedText);

            sbThemeBtn.addEventListener('click', function (e) {
                e.stopPropagation();
                sbTheme.classList.toggle('is-open');
            });
            sbThemeDropdown.addEventListener('click', function (e) { e.stopPropagation(); });
            document.addEventListener('click', function (e) {
                if (!sbTheme.contains(e.target)) sbTheme.classList.remove('is-open');
            });

            if (bgPicker) bgPicker.addEventListener('input', function () {
                applyBg(bgPicker.value);
                try { localStorage.setItem(SB_BG_KEY, bgPicker.value); } catch (e) {}
            });
            textOpts.forEach(function (b) {
                b.addEventListener('click', function () {
                    var mode = b.getAttribute('data-text-mode');
                    applyTextMode(mode);
                    try { localStorage.setItem(SB_TEXT_KEY, mode); } catch (e) {}
                });
            });
            if (resetBtn) resetBtn.addEventListener('click', function () {
                applyBg(null); applyTextMode(null);
                try {
                    localStorage.removeItem(SB_BG_KEY);
                    localStorage.removeItem(SB_TEXT_KEY);
                } catch (e) {}
            });
        }

        /* ---- 2a3. Sáng / Tối cho NỘI DUNG trang chi tiết (nút riêng trên header,
           khác nút "Giao diện" của sidebar ở trên — xem body.app-view-dark trong CSS) ---- */
        var viewThemeBtn = document.getElementById('app-view-theme-btn');
        if (viewThemeBtn) {
            var viewThemeIcon = viewThemeBtn.querySelector('i');
            var syncViewThemeIcon = function () {
                var dark = body.classList.contains('app-view-dark');
                viewThemeIcon.className = 'fa-solid ' + (dark ? 'fa-sun' : 'fa-moon');
                viewThemeBtn.title = dark ? 'Chuyển sang giao diện sáng' : 'Chuyển sang giao diện tối';
            };
            syncViewThemeIcon();
            viewThemeBtn.addEventListener('click', function () {
                var dark = body.classList.toggle('app-view-dark');
                try { localStorage.setItem('app_view_theme', dark ? 'dark' : 'light'); } catch (e) {}
                syncViewThemeIcon();
            });
        }

        /* ---- 2b. Ô tìm kiếm menu: lọc trực tiếp theo từ khóa + điều khiển bàn phím
           (mũi tên lên/xuống duyệt, Enter/Tab chọn — xem [[dropdown-keyboard-nav-default]]) ---- */
        var sbSearchInput = document.getElementById('app-sb-search-input');
        var sbSearchWrap = document.getElementById('app-sb-search');
        if (sbSearchInput && sbNav) {
            sbSearchInput.focus();
            // Click bất kỳ đâu trong khung (icon, khoảng đệm) cũng focus thẳng vào ô nhập —
            // không cần click trúng chính xác thẻ input mới gõ được.
            if (sbSearchWrap) sbSearchWrap.addEventListener('click', function () { sbSearchInput.focus(); });
            var sbActiveIdx = -1;
            function sbVisibleItems() {
                return Array.prototype.filter.call(sbNav.querySelectorAll('.app-sb-child'), function (c) {
                    return !c.classList.contains('sb-search-hidden');
                });
            }
            function sbHighlight(idx) {
                var els = sbVisibleItems();
                els.forEach(function (c) { c.classList.remove('is-kbd-active'); });
                if (idx >= 0 && els[idx]) {
                    els[idx].classList.add('is-kbd-active');
                    els[idx].scrollIntoView({ block: 'nearest' });
                }
            }
            function sbPick(el) {
                var a = el && el.querySelector('a');
                if (a) window.location.href = a.href;
            }
            sbSearchInput.addEventListener('input', function () {
                sbActiveIdx = -1;
                var kw = sbSearchInput.value.trim().toLowerCase();
                var groups = sbNav.querySelectorAll('.app-sb-group');
                if (!kw) {
                    groups.forEach(function (g) {
                        g.classList.remove('sb-search-hidden');
                        g.querySelectorAll('.app-sb-child').forEach(function (c) { c.classList.remove('sb-search-hidden', 'is-kbd-active'); });
                    });
                    return;
                }
                groups.forEach(function (g) {
                    var anyMatch = false;
                    g.querySelectorAll('.app-sb-child').forEach(function (c) {
                        var txt = (c.textContent || '').toLowerCase();
                        var match = txt.indexOf(kw) >= 0;
                        c.classList.toggle('sb-search-hidden', !match);
                        c.classList.remove('is-kbd-active');
                        if (match) anyMatch = true;
                    });
                    g.classList.toggle('sb-search-hidden', !anyMatch);
                    if (anyMatch) g.classList.add('is-open');
                });
            });
            sbSearchInput.addEventListener('keydown', function (e) {
                if (!sbSearchInput.value.trim()) return;
                var els = sbVisibleItems();
                if (!els.length) return;
                if (e.key === 'ArrowDown') {
                    e.preventDefault();
                    sbActiveIdx = (sbActiveIdx + 1) % els.length;
                    sbHighlight(sbActiveIdx);
                } else if (e.key === 'ArrowUp') {
                    e.preventDefault();
                    sbActiveIdx = (sbActiveIdx - 1 + els.length) % els.length;
                    sbHighlight(sbActiveIdx);
                } else if (e.key === 'Enter') {
                    if (sbActiveIdx >= 0) { e.preventDefault(); sbPick(els[sbActiveIdx]); }
                } else if (e.key === 'Tab') {
                    // Không preventDefault: chọn dòng đang tô sáng RỒI vẫn cho Tab chuyển focus tiếp.
                    if (sbActiveIdx >= 0) sbPick(els[sbActiveIdx]);
                } else if (e.key === 'Escape') {
                    sbActiveIdx = -1;
                    sbHighlight(-1);
                }
            });
        }

        /* ---- 3. Mobile: mở/đóng sidebar ---- */
        var burger = document.getElementById('app-header-burger');
        if (burger) {
            burger.addEventListener('click', function (e) {
                e.stopPropagation();
                body.classList.toggle('app-sidebar-mobile-open');
            });
        }
        document.addEventListener('click', function (e) {
            if (!body.classList.contains('app-sidebar-mobile-open')) return;
            var sb = document.getElementById('app-sidebar');
            if (sb && !sb.contains(e.target)) body.classList.remove('app-sidebar-mobile-open');
        });

        /* ---- 3b. Mobile: nút gom công cụ (Lịch/Điểm nhắc/Việc/Sáng-Tối) ----
           Bấm 1 mục -> mở đúng công cụ tương ứng (dropdown đã được CSS canh giữa
           màn hình trên mobile). Dùng setTimeout(...,0) để lượt click hiện tại kết
           thúc bubble (các handler đóng-khi-click-ngoài chạy xong) rồi mới .click()
           vào nút công cụ ẩn, tránh mở xong bị đóng ngay. */
        (function () {
            var toolsWrap = document.getElementById('app-header-tools');
            var toolsBtn  = document.getElementById('app-tools-btn');
            var toolsMenu = document.getElementById('app-tools-menu');
            if (!toolsWrap || !toolsBtn || !toolsMenu) return;
            toolsBtn.addEventListener('click', function (e) {
                e.stopPropagation();
                toolsWrap.classList.toggle('is-open');
            });
            document.addEventListener('click', function (e) {
                if (!toolsWrap.contains(e.target)) toolsWrap.classList.remove('is-open');
            });
            toolsMenu.querySelectorAll('.app-tools-item').forEach(function (item) {
                item.addEventListener('click', function (e) {
                    e.stopPropagation();
                    toolsWrap.classList.remove('is-open');
                    var target = document.getElementById(item.getAttribute('data-tools-target'));
                    if (target) setTimeout(function () { target.click(); }, 0);
                });
            });
        })();

        /* ---- 4. Dropdown user ---- */
        var user    = document.getElementById('app-user');
        var userBtn = document.getElementById('app-user-btn');
        if (user && userBtn) {
            userBtn.addEventListener('click', function (e) {
                e.stopPropagation();
                user.classList.toggle('is-open');
            });
            document.addEventListener('click', function (e) {
                if (!user.contains(e.target)) user.classList.remove('is-open');
            });
        }

        /* ---- 4b. Chuông thông báo (bell) ---- */
        (function () {
            var bell     = document.getElementById('app-bell');
            var bellBtn  = document.getElementById('app-bell-btn');
            var badge    = document.getElementById('app-bell-badge');
            var list     = document.getElementById('app-bell-list');
            var olderBtn = document.getElementById('app-bell-older');
            var clearBtn = document.getElementById('app-bell-clear');
            var readAll  = document.getElementById('app-bell-readall');
            var foot     = document.getElementById('app-bell-foot');
            if (!bell || !bellBtn || !list) return;

            var PAGE = 10, MORE = 5;                 // tải 10 đầu, "Trước đó" +5 mỗi lần
            var PM_BASE = '?mod=project_management&controllers=project&action=';
            var NOTI    = '?mod=home&controllers=index&action=';
            var items = [];                          // các thông báo đang hiển thị
            var total = 0;
            var lastSig = '';
            function sigOf(unread, tot, data) {
                return unread + ':' + tot + ':' + ((data && data[0]) ? data[0].id : 0) + ':' + ((data || []).length);
            }

            function setBadge(n) {
                n = parseInt(n, 10) || 0;
                if (!badge) return;
                if (n > 0) { badge.style.display = ''; badge.textContent = n > 99 ? '99+' : String(n); }
                else { badge.style.display = 'none'; badge.textContent = '0'; }
            }

            function esc(s) {
                return String(s == null ? '' : s)
                    .replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;')
                    .replace(/"/g, '&quot;').replace(/'/g, '&#39;');
            }
            function fmtTime(s) {
                var t = String(s || '').replace('T', ' ');
                var m = /^(\d{4})-(\d{2})-(\d{2})[ ](\d{2}):(\d{2})/.exec(t);
                if (!m) return t;
                var d = new Date(+m[1], +m[2] - 1, +m[3], +m[4], +m[5]);
                var now = new Date();
                var hm = m[4] + ':' + m[5];
                var days = Math.round((new Date(now.getFullYear(), now.getMonth(), now.getDate()) - new Date(+m[1], +m[2] - 1, +m[3])) / 86400000);
                if ((now - d) / 1000 < 60) return 'Vừa xong';
                if (days <= 0) return 'Hôm nay ' + hm;
                if (days === 1) return 'Hôm qua ' + hm;
                if (days <= 6) return days + ' ngày trước';
                if (days <= 13) return 'Tuần trước';
                if (days <= 29) return Math.floor(days / 7) + ' tuần trước';
                if (days <= 59) return 'Tháng trước';
                if (days <= 364) return Math.floor(days / 30) + ' tháng trước';
                return Math.floor(days / 365) + ' năm trước';
            }

            function initialOf(name) { return (String(name || '?').trim().charAt(0) || '?').toUpperCase(); }
            function itemHtml(it) {
                var unread = String(it.is_read) === '0' || it.is_read === 0;
                // Lời mời dự án còn hiệu lực (chưa đọc) → nút Tham gia / Từ chối ngay trên chuông.
                var isProjInvite = it.type === 'project_invite' && unread;
                // Lời mời cộng tác danh sách công việc → nút Nhận / Từ chối.
                var isTodoInvite = it.type === 'todo_invite' && unread;
                // Lời mời bổ nhiệm admin phó → nút Nhận / Từ chối.
                var isDeputyInvite = it.type === 'deputy_invite' && unread;
                // Lời mời uỷ quyền GỬI BÁO CÁO TỰ ĐỘNG → nút Nhận / Từ chối (link 'arcfg:<id>').
                var isAutoRep = it.type === 'autoreport_delegation' && unread;
                var arcfgM = /^arcfg:(\d+)$/.exec(it.link || '');
                var arCfg = arcfgM ? arcfgM[1] : '';
                var isInvite = isProjInvite || isTodoInvite || isDeputyInvite || (isAutoRep && arCfg);
                var pidM = /[?&]id=(\d+)/.exec(it.link || '');
                var pid = pidM ? pidM[1] : '';
                var lidM = /todo_list=(\d+)/.exec(it.link || '');
                var lid = lidM ? lidM[1] : '';
                // Lời nhắc lịch (bell-notifications <- calendar-widget) → nút "Nhắc lại".
                var evcalM = /^evcal:(\d+)$/.exec(it.link || '');
                var evcalId = evcalM ? evcalM[1] : '';
                var isCalReminder = it.type === 'calendar_reminder' && unread && evcalId;
                var actions = (isProjInvite && pid)
                    ? '<span class="app-bell-invite">'
                        + '<button type="button" class="app-bell-join" data-invite-accept data-pid="' + esc(pid) + '">Tham gia</button>'
                        + '<button type="button" class="app-bell-decline" data-invite-decline data-pid="' + esc(pid) + '">Từ chối</button>'
                        + '</span>'
                    : (isTodoInvite && lid)
                    ? '<span class="app-bell-invite">'
                        + '<button type="button" class="app-bell-join" data-todo-accept data-lid="' + esc(lid) + '">Nhận</button>'
                        + '<button type="button" class="app-bell-decline" data-todo-decline data-lid="' + esc(lid) + '">Từ chối</button>'
                        + '</span>'
                    : (isDeputyInvite)
                    ? '<span class="app-bell-invite">'
                        + '<button type="button" class="app-bell-join" data-deputy-accept>Nhận</button>'
                        + '<button type="button" class="app-bell-decline" data-deputy-decline>Từ chối</button>'
                        + '</span>'
                    : (isAutoRep && arCfg)
                    ? '<span class="app-bell-invite">'
                        + '<button type="button" class="app-bell-join" data-ar-accept data-cfg="' + esc(arCfg) + '">Nhận</button>'
                        + '<button type="button" class="app-bell-decline" data-ar-decline data-cfg="' + esc(arCfg) + '">Từ chối</button>'
                        + '</span>'
                    : (isCalReminder)
                    ? '<span class="app-bell-snooze">'
                        + '<button type="button" class="app-bell-snooze-btn" data-snooze-toggle>Nhắc lại</button>'
                        + '<div class="app-bell-snooze-menu">'
                            + '<button type="button" data-snooze-minutes="15">Sau 15 phút</button>'
                            + '<button type="button" data-snooze-minutes="60">Sau 1h</button>'
                            + '<button type="button" data-snooze-minutes="240">Sau 4h</button>'
                            + '<button type="button" data-snooze="1">Ngày mai</button>'
                            + '<button type="button" data-snooze="3">3 ngày sau</button>'
                            + '<button type="button" data-snooze="7">Tuần sau</button>'
                        + '</div>'
                        + '</span>'
                    : '';
                // Thông báo từ 1 user khác → avatar vào .ico, thời gian tô xanh lá.
                var fromUser = !!(it.actor_avatar || it.actor_name);
                var ico = it.actor_avatar
                    ? '<span class="ico ico-avatar"><img src="' + esc(it.actor_avatar) + '" alt=""></span>'
                    : (fromUser
                        ? '<span class="ico ico-avatar">' + esc(initialOf(it.actor_name)) + '</span>'
                        : '<span class="ico"><i class="fa-solid fa-bell"></i></span>');
                return '<div class="app-bell-item' + (unread ? ' is-unread' : '') + (isInvite ? ' is-invite' : '') + (fromUser ? ' from-user' : '') + '"'
                    + ' data-id="' + esc(it.id) + '"'
                    + (isInvite ? ' data-no-nav="1"' : '')
                    + (evcalId ? ' data-evcal-id="' + esc(evcalId) + '"' : '')
                    + (it.link ? ' data-link="' + esc(it.link) + '"' : '') + '>'
                    + ico
                    + '<span class="body">'
                    + '<span class="title">' + esc(it.title) + '</span>'
                    + (it.message ? '<span class="msg">' + esc(it.message) + '</span>' : '')
                    + '<span class="time' + (fromUser ? ' time-user' : '') + '">' + esc(fmtTime(it.created_at)) + '</span>'
                    + actions
                    + '</span>'
                    + (unread ? '<span class="dot"></span>' : '')
                    + '</div>';
            }
            function updateFoot() {
                if (foot) foot.style.display = items.length ? '' : 'none';
                if (olderBtn) olderBtn.style.display = (items.length < total) ? '' : 'none';
                if (clearBtn) clearBtn.style.display = items.length ? '' : 'none';
            }
            function render() {
                if (!items.length) { list.innerHTML = '<div class="app-bell-empty">Chưa có thông báo nào.</div>'; }
                else list.innerHTML = items.map(itemHtml).join('');
                updateFoot();
            }

            function fetchNotis(limit, offset) {
                return fetch(NOTI + 'notifications&limit=' + limit + '&offset=' + offset, { credentials: 'same-origin' })
                    .then(function (r) { return r.json(); });
            }
            function load() {
                var keep = Math.max(PAGE, items.length);   // giữ nguyên số đang xem khi mở lại/refresh
                fetchNotis(keep, 0).then(function (res) {
                    if (!res || !res.ok) return;
                    setBadge(res.unread); total = res.total || 0; items = res.data || [];
                    render(); lastSig = sigOf(res.unread, total, items);
                }).catch(function () {
                    list.innerHTML = '<div class="app-bell-empty">Không tải được thông báo.</div>';
                });
            }
            function loadOlder() {
                fetchNotis(MORE, items.length).then(function (res) {
                    if (!res || !res.ok) return;
                    setBadge(res.unread); total = res.total || total;
                    (res.data || []).forEach(function (it) { items.push(it); });
                    render(); lastSig = sigOf(res.unread, total, items);
                }).catch(function () {});
            }

            bellBtn.addEventListener('click', function (e) {
                e.stopPropagation();
                var open = bell.classList.toggle('is-open');
                if (open) load();
            });
            document.addEventListener('click', function (e) {
                if (!bell.contains(e.target)) bell.classList.remove('is-open');
            });

            var PM_BASE = '?mod=project_management&controllers=project&action=';
            function markItemRead(item) {
                var id = item.getAttribute('data-id');
                if (!item.classList.contains('is-unread')) return;
                var body = new URLSearchParams(); body.append('id', id);
                fetch('?mod=home&controllers=index&action=markNotificationRead', {
                    method: 'POST', headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                    body: body.toString(), credentials: 'same-origin'
                }).then(function (r) { return r.json(); }).then(function (res) {
                    if (res && res.ok) {
                        setBadge(res.unread);
                        item.classList.remove('is-unread');
                        var dot = item.querySelector('.dot'); if (dot) dot.remove();
                    }
                }).catch(function () {});
            }

            // Click 1 thông báo → đánh dấu đã đọc (trừ badge) + điều hướng nếu có link.
            list.addEventListener('click', function (e) {
                // Đóng menu "Nhắc lại" khác đang mở khi bấm ra ngoài nó (nhưng vẫn trong danh sách).
                if (!e.target.closest('.app-bell-snooze')) {
                    list.querySelectorAll('.app-bell-snooze.is-open').forEach(function (s) { s.classList.remove('is-open'); });
                }

                // Nút "Nhắc lại" (lời nhắc lịch) → xổ menu 15 phút / 1h / 4h / Ngày mai / 3 ngày sau / Tuần sau.
                var stoggle = e.target.closest('[data-snooze-toggle]');
                if (stoggle) {
                    e.stopPropagation();
                    var sspan = stoggle.closest('.app-bell-snooze');
                    if (sspan) {
                        var wasOpen = sspan.classList.contains('is-open');
                        list.querySelectorAll('.app-bell-snooze.is-open').forEach(function (s) { s.classList.remove('is-open'); });
                        if (!wasOpen) sspan.classList.add('is-open');
                    }
                    return;
                }

                // Chọn 1 mốc trong menu "Nhắc lại" (mốc ngắn theo phút HOẶC mốc ngày):
                // dịch lời nhắc lịch + đánh dấu đã đọc.
                var sopt = e.target.closest('[data-snooze], [data-snooze-minutes]');
                if (sopt) {
                    e.stopPropagation();
                    var sitem = sopt.closest('.app-bell-item');
                    var smenu = sopt.closest('.app-bell-snooze-menu');
                    var sminutes = sopt.getAttribute('data-snooze-minutes');
                    var sdays = sopt.getAttribute('data-snooze');
                    var sid   = sitem ? sitem.getAttribute('data-id') : '';
                    var seid  = sitem ? sitem.getAttribute('data-evcal-id') : '';
                    if (!sid || !seid) return;
                    var sbtns = smenu ? smenu.querySelectorAll('button') : [];
                    sbtns.forEach(function (b) { b.disabled = true; });
                    var sbody = new URLSearchParams();
                    sbody.append('notif_id', sid); sbody.append('evcal_id', seid);
                    if (sminutes) sbody.append('minutes', sminutes); else sbody.append('days', sdays);
                    fetch(NOTI + 'evcalSnooze', {
                        method: 'POST', headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                        body: sbody.toString(), credentials: 'same-origin'
                    }).then(function (r) { return r.json(); }).then(function (res) {
                        if (!res || !res.ok) { sbtns.forEach(function (b) { b.disabled = false; }); return; }
                        if (typeof res.unread === 'number') setBadge(res.unread);
                        if (sitem) {
                            sitem.classList.remove('is-unread');
                            var sdot = sitem.querySelector('.dot'); if (sdot) sdot.remove();
                            var sbox = sitem.querySelector('.app-bell-snooze'); if (sbox) sbox.remove();
                        }
                        // "Nhắc lại" dịch ngày lời nhắc lịch → đồng bộ real-time với mini lịch (app-cal)
                        // và view Lịch phóng to (calendar_full) nếu đang mở cùng trang, không cần tải lại trang.
                        if (typeof res.today === 'number' && typeof window.appCalSetBadge === 'function') window.appCalSetBadge(res.today);
                        if (typeof window.appCalReload === 'function') window.appCalReload();
                        if (typeof window.calfullReload === 'function') window.calfullReload();
                    }).catch(function () { sbtns.forEach(function (b) { b.disabled = false; }); });
                    return;
                }

                // Nút Nhận / Từ chối lời mời cộng tác danh sách công việc (todo)
                var tacc = e.target.closest('[data-todo-accept]');
                var tdec = e.target.closest('[data-todo-decline]');
                if (tacc || tdec) {
                    e.stopPropagation();
                    var tbtn = tacc || tdec;
                    var titem = tbtn.closest('.app-bell-item');
                    var tlid = tbtn.getAttribute('data-lid');
                    var tbody = new URLSearchParams();
                    tbody.append('list_id', tlid);
                    tbody.append('accept', tacc ? '1' : '0');
                    tbtn.disabled = true;
                    fetch(NOTI + 'todoRespondInvite', {
                        method: 'POST', headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                        body: tbody.toString(), credentials: 'same-origin'
                    }).then(function (r) { return r.json(); }).then(function (res) {
                        if (!res || !res.ok) { tbtn.disabled = false; return; }
                        if (titem) markItemRead(titem);
                        var tbox = titem && titem.querySelector('.app-bell-invite'); if (tbox) tbox.remove();
                        // Cập nhật ngay widget todo (thêm/bỏ tab danh sách vừa nhận).
                        if (window.__appTodoReload) window.__appTodoReload();
                    }).catch(function () { tbtn.disabled = false; });
                    return;
                }

                // Nút Nhận / Từ chối lời mời bổ nhiệm admin phó
                var dacc = e.target.closest('[data-deputy-accept]');
                var ddec = e.target.closest('[data-deputy-decline]');
                if (dacc || ddec) {
                    e.stopPropagation();
                    var dbtn = dacc || ddec;
                    var ditem = dbtn.closest('.app-bell-item');
                    var dbody = new URLSearchParams(); dbody.append('accept', dacc ? '1' : '0');
                    dbtn.disabled = true;
                    fetch('?mod=admin_factory&controllers=admin&action=deputy_respond', {
                        method: 'POST', headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                        body: dbody.toString(), credentials: 'same-origin'
                    }).then(function (r) { return r.json(); }).then(function (res) {
                        if (!res || !res.ok) { dbtn.disabled = false; return; }
                        if (ditem) markItemRead(ditem);
                        var dbox = ditem && ditem.querySelector('.app-bell-invite'); if (dbox) dbox.remove();
                        // Nhận: chèn nhóm "Quản trị hệ thống" vào sidebar ngay (không reload).
                        if (dacc && window.__injectAdminSidebar) window.__injectAdminSidebar();
                    }).catch(function () { dbtn.disabled = false; });
                    return;
                }

                // Nút Nhận / Từ chối uỷ quyền GỬI BÁO CÁO TỰ ĐỘNG
                var aracc = e.target.closest('[data-ar-accept]');
                var ardec = e.target.closest('[data-ar-decline]');
                if (aracc || ardec) {
                    e.stopPropagation();
                    var arbtn = aracc || ardec;
                    var aritem = arbtn.closest('.app-bell-item');
                    var arbody = new URLSearchParams();
                    arbody.append('config_id', arbtn.getAttribute('data-cfg'));
                    arbody.append('accept', aracc ? '1' : '0');
                    arbtn.disabled = true;
                    fetch('?mod=report&controllers=report&action=auto_report_respond', {
                        method: 'POST', headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                        body: arbody.toString(), credentials: 'same-origin'
                    }).then(function (r) { return r.json(); }).then(function (res) {
                        if (!res || !res.ok) { arbtn.disabled = false; return; }
                        if (aritem) markItemRead(aritem);
                        var arbox = aritem && aritem.querySelector('.app-bell-invite'); if (arbox) arbox.remove();
                        // Nhận xong → sang trang quản lý uỷ quyền của mình (để sau này tự tạm ngưng/bật lại).
                        if (aracc && res.link) { window.location.href = res.link; }
                    }).catch(function () { arbtn.disabled = false; });
                    return;
                }

                // Nút Tham gia / Từ chối lời mời dự án
                var acc = e.target.closest('[data-invite-accept]');
                var dec = e.target.closest('[data-invite-decline]');
                if (acc || dec) {
                    e.stopPropagation();
                    var btn = acc || dec;
                    var item0 = btn.closest('.app-bell-item');
                    var pid = btn.getAttribute('data-pid');
                    var body = new URLSearchParams(); body.append('project_id', pid);
                    btn.disabled = true;
                    fetch(PM_BASE + (acc ? 'acceptInvite' : 'declineInvite'), {
                        method: 'POST', headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                        body: body.toString(), credentials: 'same-origin'
                    }).then(function (r) { return r.json(); }).then(function (res) {
                        if (!res || !res.ok) { btn.disabled = false; return; }
                        if (item0) markItemRead(item0);
                        if (acc && res.link) { window.location.href = res.link; }
                        else {
                            // từ chối: bỏ cụm nút, để lại thông báo đã đọc
                            var box = item0 && item0.querySelector('.app-bell-invite'); if (box) box.remove();
                        }
                    }).catch(function () { btn.disabled = false; });
                    return;
                }

                var item = e.target.closest('.app-bell-item');
                if (!item) return;
                // Lời mời còn hiệu lực: chỉ phản hồi qua 2 nút, bấm vào thân không điều hướng/đọc.
                if (item.getAttribute('data-no-nav') === '1') return;
                var id = item.getAttribute('data-id');
                var link = item.getAttribute('data-link');
                if (item.classList.contains('is-unread')) {
                    var body = new URLSearchParams(); body.append('id', id);
                    fetch('?mod=home&controllers=index&action=markNotificationRead', {
                        method: 'POST', headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                        body: body.toString(), credentials: 'same-origin'
                    })
                    .then(function (r) { return r.json(); })
                    .then(function (res) {
                        if (res && res.ok) {
                            setBadge(res.unread);
                            item.classList.remove('is-unread');
                            var dot = item.querySelector('.dot'); if (dot) dot.remove();
                        }
                    }).catch(function () {});
                }
                // link 'evcal:<id>' / 'arcfg:<id>' chỉ là dữ liệu nội bộ (nút "Nhắc lại" / uỷ quyền báo cáo), không phải URL.
                if (link && !/^(evcal|arcfg):/.test(link)) window.location.href = link;
            });

            // "Đánh dấu tất cả đã đọc": đọc hết + bỏ qua mọi lời mời đang chờ (báo người mời), giữ lại danh sách.
            if (readAll) readAll.addEventListener('click', function (e) {
                e.stopPropagation();
                if (!items.length) return;
                readAll.disabled = true;
                fetch(PM_BASE + 'skipAllInvites', { method: 'POST', credentials: 'same-origin' })
                    .catch(function () {})
                    .then(function () {
                        return fetch(NOTI + 'markAllNotificationsRead', { method: 'POST', credentials: 'same-origin' })
                            .then(function (r) { return r.json(); });
                    })
                    .then(function (res) {
                        readAll.disabled = false;
                        if (res && res.ok) { setBadge(0); load(); } // tải lại để bỏ chấm chưa đọc + nút lời mời
                    })
                    .catch(function () { readAll.disabled = false; });
            });

            // "Trước đó": tải thêm 5 thông báo cũ hơn.
            if (olderBtn) olderBtn.addEventListener('click', function (e) { e.stopPropagation(); loadOlder(); });

            // "Xóa hết thông báo": = đã xem rồi xóa. Đồng thời bỏ qua mọi lời mời đang chờ (báo người mời).
            if (clearBtn) clearBtn.addEventListener('click', function (e) {
                e.stopPropagation();
                if (!items.length) return;
                clearBtn.disabled = true;
                fetch(PM_BASE + 'skipAllInvites', { method: 'POST', credentials: 'same-origin' })
                    .catch(function () {})
                    .then(function () {
                        return fetch(NOTI + 'deleteAllNotifications', { method: 'POST', credentials: 'same-origin' })
                            .then(function (r) { return r.json(); });
                    })
                    .then(function (res) {
                        clearBtn.disabled = false;
                        if (res && res.ok) { items = []; total = 0; setBadge(0); render(); lastSig = sigOf(0, 0, []); }
                    })
                    .catch(function () { clearBtn.disabled = false; });
            });

            /* ---- Real-time: poll chuông để thấy thông báo mới mà không cần reload ---- */
            (function () {
                function pollBell() {
                    var keep = Math.max(PAGE, items.length);
                    fetchNotis(keep, 0).then(function (res) {
                        if (!res || !res.ok) return;
                        setBadge(res.unread); total = res.total || 0;
                        var sig = sigOf(res.unread, total, res.data);
                        // chỉ render lại khi dropdown mở VÀ danh sách thực sự đổi (tránh phá thao tác đang bấm)
                        if (bell.classList.contains('is-open') && sig !== lastSig) {
                            items = res.data || []; render(); lastSig = sig;
                        }
                    }).catch(function () {});
                }
                setInterval(pollBell, 8000);
                setTimeout(pollBell, 2500); // kiểm tra sớm để bắt lời mời/kết quả vừa đến
            })();
        })();

        /* ---- 4c. Todo list (nhiều danh sách + chia sẻ cộng tác) ---- */
        (function () {
            var root    = document.getElementById('app-todo');
            var btn     = document.getElementById('app-todo-btn');
            var badge   = document.getElementById('app-todo-badge');
            var list    = document.getElementById('app-todo-list');
            var input   = document.getElementById('app-todo-input');
            if (!root || !btn || !list || !input) return;

            var tabset    = document.getElementById('app-todo-tabset');
            var moreWrap  = document.getElementById('app-todo-more-wrap');
            var moreBtn   = document.getElementById('app-todo-more-btn');
            var moreMenu  = document.getElementById('app-todo-more-menu');
            var addListBtn= document.getElementById('app-todo-add-list');
            var titleEl   = document.getElementById('app-todo-active-title');
            var ownerEl   = document.getElementById('app-todo-owner');
            var shareBtn  = document.getElementById('app-todo-share-btn');
            var leaveBtn  = document.getElementById('app-todo-leave-btn');
            var delListBtn= document.getElementById('app-todo-list-del');
            var setWrap   = document.querySelector('#app-todo .app-todo-setting-wrap');
            var setBtn    = document.getElementById('app-todo-setting-btn');

            var sharePanel  = document.getElementById('app-todo-share');
            var shareClose  = document.getElementById('app-todo-share-close');
            var shareSearch = document.getElementById('app-todo-share-search');
            var shareUsers  = document.getElementById('app-todo-share-users');
            var shareCanEdit= document.getElementById('app-todo-share-canedit');
            var shareSend   = document.getElementById('app-todo-share-send');
            var shareMsg    = document.getElementById('app-todo-share-msg');
            var shareMembers= document.getElementById('app-todo-share-members');

            var API = '?mod=home&controllers=index&action=';
            var state = { lists: [], activeId: 0, role: 'owner', me: 0, canEdit: true };
            var picked = {};            // user_id -> true (đang chọn để gửi)
            var lastItemsSig = '';

            function esc(s) {
                return String(s == null ? '' : s)
                    .replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;')
                    .replace(/"/g, '&quot;').replace(/'/g, '&#39;');
            }
            function fmtTime(s) {
                var t = String(s || '').replace('T', ' ');
                var m = /^(\d{4})-(\d{2})-(\d{2})[ ](\d{2}):(\d{2})/.exec(t);
                if (!m) return t;
                var d = new Date(+m[1], +m[2] - 1, +m[3], +m[4], +m[5]);
                var now = new Date();
                var hm = m[4] + ':' + m[5];
                var days = Math.round((new Date(now.getFullYear(), now.getMonth(), now.getDate()) - new Date(+m[1], +m[2] - 1, +m[3])) / 86400000);
                if ((now - d) / 1000 < 60) return 'Vừa xong';
                if (days <= 0) return 'Hôm nay ' + hm;
                if (days === 1) return 'Hôm qua ' + hm;
                if (days <= 6) return days + ' ngày trước';
                if (days <= 13) return 'Tuần trước';
                if (days <= 29) return Math.floor(days / 7) + ' tuần trước';
                if (days <= 59) return 'Tháng trước';
                if (days <= 364) return Math.floor(days / 30) + ' tháng trước';
                return Math.floor(days / 365) + ' năm trước';
            }
            function post(action, params) {
                var body = new URLSearchParams();
                if (params) Object.keys(params).forEach(function (k) {
                    if (Array.isArray(params[k])) params[k].forEach(function (v) { body.append(k + '[]', v); });
                    else body.append(k, params[k]);
                });
                return fetch(API + action, {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8' },
                    body: body.toString(), credentials: 'same-origin'
                }).then(function (r) { return r.json(); });
            }
            function setBadge(n) {
                n = parseInt(n, 10) || 0;
                if (!badge) return;
                if (n > 0) { badge.style.display = ''; badge.textContent = n > 99 ? '99+' : String(n); }
                else { badge.style.display = 'none'; }
            }
            function setMode(mode) {
                var r = root.querySelector('input[name="todo-clear-mode"][value="' + (mode || 'manual') + '"]');
                if (r) r.checked = true;
            }
            function activeList() {
                for (var i = 0; i < state.lists.length; i++) {
                    if (+state.lists[i].id === +state.activeId) return state.lists[i];
                }
                return null;
            }
            function avatarHtml(u) {
                return u.avatar
                    ? '<img src="' + esc(u.avatar) + '" alt="">'
                    : esc((String(u.name || '?').trim().charAt(0) || '?').toUpperCase());
            }

            /* ---------- Tabs danh sách (tối đa 3, dư gom vào "...") ---------- */
            function tabNum(l) {
                var n = parseInt(l.open_count, 10) || 0;
                return n > 0 ? '<span class="app-todo-tabnum">' + n + '</span>' : '';
            }
            function renderTabs() {
                if (!tabset) return;
                var ls = state.lists;
                var visible = ls.slice(0, 3);
                var overflow = ls.slice(3);
                tabset.innerHTML = visible.map(function (l) {
                    var shared = !l.is_owner;
                    return '<button type="button" class="app-todo-tab' + (+l.id === +state.activeId ? ' is-active' : '')
                        + (shared ? ' is-shared' : '') + '" data-list="' + esc(l.id) + '" title="' + esc(l.title) + '">'
                        + (shared ? '<i class="fa-solid fa-users"></i> ' : '')
                        + '<span class="tt">' + esc(l.title) + '</span>' + tabNum(l) + '</button>';
                }).join('');
                if (overflow.length && moreWrap && moreMenu) {
                    moreWrap.style.display = '';
                    moreMenu.innerHTML = overflow.map(function (l) {
                        return '<button type="button" class="app-todo-more-item' + (+l.id === +state.activeId ? ' is-active' : '')
                            + '" data-list="' + esc(l.id) + '">'
                            + (l.is_owner ? '' : '<i class="fa-solid fa-users"></i> ')
                            + esc(l.title) + tabNum(l) + '</button>';
                    }).join('');
                    moreBtn.classList.toggle('has-active', overflow.some(function (l) { return +l.id === +state.activeId; }));
                } else if (moreWrap) {
                    moreWrap.style.display = 'none';
                    if (moreMenu) moreMenu.innerHTML = '';
                }
            }
            function applyRole() {
                var l = activeList();
                state.role = l ? l.role : 'owner';
                var isOwner = state.role === 'owner';
                state.canEdit = l ? (isOwner || !!l.can_edit) : true;
                if (titleEl) {
                    titleEl.textContent = l ? l.title : 'Việc cần làm';
                    titleEl.classList.toggle('editable', isOwner);
                }
                if (ownerEl) ownerEl.textContent = (l && !isOwner && l.owner_name) ? ('• của ' + l.owner_name) : '';
                root.classList.toggle('is-owner', isOwner);
                root.classList.toggle('is-member', !!l && !isOwner);
                root.classList.toggle('is-view-only', !!l && !state.canEdit);
                root.querySelectorAll('.owner-only').forEach(function (el) { el.style.display = isOwner ? '' : 'none'; });
                root.querySelectorAll('.member-only').forEach(function (el) { el.style.display = isOwner ? 'none' : ''; });
            }

            /* ---------- Task ---------- */
            function itemHtml(it) {
                var done = String(it.is_done) === '1' || it.is_done === 1;
                var doneBy = (done && it.done_by_name)
                    ? '<div class="app-todo-doneby"><i class="fa-solid fa-circle-check"></i> ' + esc(it.done_by_name) + '</div>'
                    : '';
                return '<div class="app-todo-item' + (done ? ' done' : '') + '" data-id="' + esc(it.id) + '">'
                    + '<span class="app-todo-grip" title="Kéo để sắp xếp"><i class="fa-solid fa-grip-vertical"></i></span>'
                    + '<input type="checkbox" class="app-todo-check"' + (done ? ' checked' : '') + '>'
                    + '<div class="app-todo-body">'
                    + '<div class="app-todo-text">' + esc(it.content) + '</div>'
                    + '<div class="app-todo-time">' + esc(fmtTime(it.created_at)) + '</div>'
                    + doneBy
                    + '</div>'
                    + '<div class="app-todo-acts">'
                    + '<button type="button" class="app-todo-act edit" title="Sửa"><i class="fa-solid fa-pen"></i></button>'
                    + '<button type="button" class="app-todo-act del" title="Xóa"><i class="fa-solid fa-xmark"></i></button>'
                    + '</div>'
                    + '</div>';
            }
            function render(items) {
                if (!state.activeId) {
                    list.innerHTML = '<div class="app-todo-empty">Chưa có danh sách nào. Bấm <b>+</b> để tạo.</div>';
                    return;
                }
                if (!items || !items.length) {
                    list.innerHTML = '<div class="app-todo-empty">Chưa có việc nào. Thêm bên dưới ↓</div>';
                    return;
                }
                list.innerHTML = items.map(itemHtml).join('');
            }
            function itemsSig(items) {
                return (items || []).map(function (it) {
                    return it.id + ':' + it.is_done + ':' + it.done_by + ':' + it.sort_order + ':' + it.content;
                }).join('|');
            }

            /* ---------- Tải danh sách + task ---------- */
            function loadLists(keepActive) {
                return fetch(API + 'todoLists', { credentials: 'same-origin' })
                    .then(function (r) { return r.json(); })
                    .then(function (res) {
                        if (!res || !res.ok) return res;
                        state.lists = res.lists || [];
                        state.me = parseInt(res.me, 10) || 0;
                        setBadge(res.pending);
                        setMode(res.clear_mode);
                        if (!keepActive || !activeList()) {
                            state.activeId = state.lists.length ? +state.lists[0].id : 0;
                        }
                        renderTabs();
                        return res;
                    });
            }
            function loadItems() {
                applyRole();
                if (!state.activeId) { render([]); return; }
                fetch(API + 'todos&list_id=' + state.activeId, { credentials: 'same-origin' })
                    .then(function (r) { return r.json(); })
                    .then(function (res) {
                        if (!res || !res.ok) { list.innerHTML = '<div class="app-todo-empty">Không xem được danh sách này.</div>'; return; }
                        var l = activeList();
                        if (l) { l.role = res.role || l.role; l.can_edit = !!res.can_edit; }
                        applyRole();
                        setBadge(res.pending);
                        render(res.data || []);
                        lastItemsSig = itemsSig(res.data || []);
                    })
                    .catch(function () { list.innerHTML = '<div class="app-todo-empty">Không tải được.</div>'; });
            }
            function switchTo(id) {
                state.activeId = +id || 0;
                closeShare();
                if (moreWrap) moreWrap.classList.remove('is-open');
                renderTabs();
                loadItems();
            }
            // Expose để chuông gọi lại sau khi Nhận/Từ chối lời mời.
            window.__appTodoReload = function () {
                loadLists(true).then(function () {
                    if (root.classList.contains('is-open')) loadItems();
                });
            };

            /* ---------- Mở / đóng dropdown ---------- */
            var firstLoaded = false;
            btn.addEventListener('click', function (e) {
                e.stopPropagation();
                var open = root.classList.toggle('is-open');
                if (open) {
                    loadLists(firstLoaded).then(function () { firstLoaded = true; loadItems(); });
                    setTimeout(function () { input.focus(); }, 60);
                }
            });
            // Chỉ đóng khi click RA NGOÀI dropdown. Dùng cờ bắt ở pha capture vì các thao
            // tác bên trong (đổi tab, xóa task, gỡ thành viên…) vẽ lại innerHTML → phần tử
            // được click bị tách khỏi DOM, khiến root.contains(e.target) sai (tưởng là ngoài).
            var clickedInside = false;
            root.addEventListener('click', function () { clickedInside = true; }, true);
            document.addEventListener('click', function () {
                if (clickedInside) { clickedInside = false; return; }
                root.classList.remove('is-open');
                if (setWrap) setWrap.classList.remove('is-open');
                if (moreWrap) moreWrap.classList.remove('is-open');
            });

            /* ---------- Sự kiện tabs ---------- */
            if (tabset) tabset.addEventListener('click', function (e) {
                var tab = e.target.closest('.app-todo-tab');
                if (tab) switchTo(tab.getAttribute('data-list'));
            });
            if (moreBtn) moreBtn.addEventListener('click', function (e) {
                e.stopPropagation();
                moreWrap.classList.toggle('is-open');
            });
            if (moreMenu) moreMenu.addEventListener('click', function (e) {
                var it = e.target.closest('.app-todo-more-item');
                if (it) switchTo(it.getAttribute('data-list'));
            });
            if (addListBtn) addListBtn.addEventListener('click', function () {
                post('todoListCreate', { title: 'Danh sách mới' }).then(function (res) {
                    if (!res || !res.ok) return;
                    state.lists = res.lists || state.lists;
                    state.activeId = +res.list_id;
                    renderTabs();
                    loadItems();
                    setTimeout(startRenameTitle, 80);    // mở sửa tên ngay
                }).catch(function () {});
            });

            /* ---------- Đổi tên danh sách (chủ) ---------- */
            function startRenameTitle() {
                if (state.role !== 'owner' || !titleEl) return;
                if (root.querySelector('.app-todo-title-edit')) return;
                var cur = titleEl.textContent;
                var inp = document.createElement('input');
                inp.type = 'text'; inp.className = 'app-todo-title-edit'; inp.value = cur; inp.maxLength = 120;
                titleEl.style.display = 'none';
                titleEl.parentNode.insertBefore(inp, titleEl);
                inp.focus(); inp.select();
                function finish(save) {
                    var v = inp.value.trim();
                    inp.remove();
                    titleEl.style.display = '';
                    if (save && v && v !== cur) {
                        titleEl.textContent = v;
                        var l = activeList(); if (l) l.title = v;
                        renderTabs();
                        post('todoListRename', { list_id: state.activeId, title: v }).catch(function () {});
                    }
                }
                inp.addEventListener('keydown', function (ev) {
                    if (ev.key === 'Enter') { ev.preventDefault(); finish(true); }
                    else if (ev.key === 'Escape') { finish(false); }
                });
                inp.addEventListener('blur', function () { finish(true); });
            }
            if (titleEl) titleEl.addEventListener('click', function () {
                if (state.role === 'owner') startRenameTitle();
            });

            /* ---------- Xóa / Rời danh sách ---------- */
            if (delListBtn) delListBtn.addEventListener('click', function () {
                var l = activeList(); if (!l) return;
                if (!window.confirm('Xóa danh sách “' + l.title + '” cùng toàn bộ công việc? Người được chia sẻ cũng sẽ mất danh sách này.')) return;
                post('todoListDelete', { list_id: state.activeId }).then(function (res) {
                    if (!res || !res.ok) return;
                    state.lists = res.lists || [];
                    setBadge(res.pending);
                    switchTo(state.lists.length ? state.lists[0].id : 0);
                }).catch(function () {});
            });
            if (leaveBtn) leaveBtn.addEventListener('click', function () {
                var l = activeList(); if (!l) return;
                if (!window.confirm('Rời bỏ danh sách “' + l.title + '”? Bạn sẽ không còn thấy danh sách này.')) return;
                post('todoListLeave', { list_id: state.activeId }).then(function (res) {
                    if (!res || !res.ok) return;
                    state.lists = res.lists || [];
                    setBadge(res.pending);
                    switchTo(state.lists.length ? state.lists[0].id : 0);
                }).catch(function () {});
            });

            /* ---------- Panel chia sẻ (chủ) ---------- */
            var shareSearchTimer = null;
            function openShare() {
                if (!sharePanel) return;
                sharePanel.style.display = '';
                if (shareMsg) shareMsg.textContent = '';
                picked = {};
                if (shareSearch) shareSearch.value = '';
                if (shareCanEdit) shareCanEdit.checked = false;
                loadMembers('');
                setTimeout(function () { if (shareSearch) shareSearch.focus(); }, 50);
            }
            function closeShare() { if (sharePanel) sharePanel.style.display = 'none'; }
            function updateSend() { if (shareSend) shareSend.disabled = Object.keys(picked).length === 0; }
            function loadMembers(kw) {
                var url = API + 'todoMembers&list_id=' + state.activeId + (kw ? ('&keyword=' + encodeURIComponent(kw)) : '');
                fetch(url, { credentials: 'same-origin' })
                    .then(function (r) { return r.json(); })
                    .then(function (res) {
                        if (!res || !res.ok) return;
                        renderAssignable(res.assignable || []);
                        renderMembers(res.members || []);
                    }).catch(function () {});
            }
            function statusTag(st) {
                if (st === 'accepted') return '<span class="app-todo-tag ok">Đã nhận</span>';
                if (st === 'pending')  return '<span class="app-todo-tag wait">Đang chờ</span>';
                if (st === 'declined') return '<span class="app-todo-tag no">Đã từ chối</span>';
                if (st === 'left')     return '<span class="app-todo-tag no">Đã rời</span>';
                return '';
            }
            function renderAssignable(users) {
                if (!shareUsers) return;
                if (!users.length) { shareUsers.innerHTML = '<div class="app-todo-share-empty">Không tìm thấy người dùng.</div>'; updateSend(); return; }
                shareUsers.innerHTML = users.map(function (u) {
                    var st = u.status || '';
                    var locked = (st === 'accepted' || st === 'pending');
                    var checked = picked[u.user_id] ? ' checked' : '';
                    return '<label class="app-todo-share-user' + (locked ? ' is-locked' : '') + '">'
                        + '<input type="checkbox" class="app-todo-pick" value="' + esc(u.user_id) + '"' + checked + (locked ? ' disabled' : '') + '>'
                        + '<span class="av">' + avatarHtml(u) + '</span>'
                        + '<span class="nm">' + esc(u.name) + '<small>@' + esc(u.username) + '</small></span>'
                        + statusTag(st) + '</label>';
                }).join('');
                updateSend();
            }
            function renderMembers(members) {
                if (!shareMembers) return;
                var act = members.filter(function (m) { return m.status === 'accepted' || m.status === 'pending'; });
                if (!act.length) { shareMembers.innerHTML = '<div class="app-todo-share-empty">Chưa chia sẻ với ai.</div>'; return; }
                shareMembers.innerHTML = act.map(function (m) {
                    return '<div class="app-todo-member" data-uid="' + esc(m.user_id) + '">'
                        + '<span class="av">' + avatarHtml(m) + '</span>'
                        + '<span class="nm">' + esc(m.name) + '</span>'
                        + statusTag(m.status)
                        + '<label class="app-todo-member-canedit" title="Cho phép thêm, sửa nội dung">'
                        + '<input type="checkbox" class="app-todo-member-canedit-chk"' + (m.can_edit ? ' checked' : '') + '> Sửa</label>'
                        + '<button type="button" class="app-todo-member-remove" title="Gỡ người này">Gỡ</button>'
                        + '</div>';
                }).join('');
            }
            if (shareBtn) shareBtn.addEventListener('click', function (e) {
                e.stopPropagation();
                if (sharePanel && sharePanel.style.display === 'none') openShare(); else closeShare();
            });
            if (shareClose) shareClose.addEventListener('click', closeShare);
            if (shareUsers) shareUsers.addEventListener('change', function (e) {
                var chk = e.target.closest('.app-todo-pick');
                if (!chk) return;
                if (chk.checked) picked[chk.value] = true; else delete picked[chk.value];
                updateSend();
            });
            if (shareSearch) shareSearch.addEventListener('input', function () {
                clearTimeout(shareSearchTimer);
                var kw = shareSearch.value.trim();
                shareSearchTimer = setTimeout(function () { loadMembers(kw); }, 250);
            });
            if (shareSend) shareSend.addEventListener('click', function () {
                var ids = Object.keys(picked);
                if (!ids.length) return;
                shareSend.disabled = true;
                post('todoShare', { list_id: state.activeId, user_ids: ids, can_edit: (shareCanEdit && shareCanEdit.checked) ? 1 : 0 }).then(function (res) {
                    if (!res || !res.ok) { shareSend.disabled = false; return; }
                    picked = {};
                    if (shareMsg) shareMsg.textContent = 'Đã gửi tới ' + (res.sent || 0) + ' người.'
                        + ((res.errors && res.errors.length) ? ' (' + res.errors.join(' ') + ')' : '');
                    renderAssignable(res.assignable || []);
                    renderMembers(res.members || []);
                    loadLists(true).then(renderTabs);
                }).catch(function () { shareSend.disabled = false; });
            });
            if (shareMembers) shareMembers.addEventListener('click', function (e) {
                var rm = e.target.closest('.app-todo-member-remove');
                if (!rm) return;
                var row = rm.closest('.app-todo-member');
                var uid = row.getAttribute('data-uid');
                rm.disabled = true;
                post('todoUnshare', { list_id: state.activeId, user_id: uid }).then(function (res) {
                    if (!res || !res.ok) { rm.disabled = false; return; }
                    renderAssignable(res.assignable || []);
                    renderMembers(res.members || []);
                    loadLists(true).then(renderTabs);
                }).catch(function () { rm.disabled = false; });
            });
            if (shareMembers) shareMembers.addEventListener('change', function (e) {
                var chk = e.target.closest('.app-todo-member-canedit-chk');
                if (!chk) return;
                var row = chk.closest('.app-todo-member');
                var uid = row.getAttribute('data-uid');
                var want = chk.checked;
                chk.disabled = true;
                post('todoSetMemberEdit', { list_id: state.activeId, user_id: uid, can_edit: want ? 1 : 0 }).then(function (res) {
                    chk.disabled = false;
                    if (!res || !res.ok) { chk.checked = !want; return; }
                }).catch(function () { chk.disabled = false; chk.checked = !want; });
            });

            /* ---------- Thêm task: Enter ---------- */
            input.addEventListener('keydown', function (e) {
                if (e.key !== 'Enter') return;
                var v = input.value.trim();
                if (!v || !state.activeId || !state.canEdit) return;
                input.value = '';
                post('todoCreate', { list_id: state.activeId, content: v }).then(function (res) {
                    if (!res || !res.ok) return;
                    setBadge(res.pending);
                    var empty = list.querySelector('.app-todo-empty');
                    if (empty) list.innerHTML = '';
                    list.insertAdjacentHTML('afterbegin', itemHtml(res.item));
                    lastItemsSig = itemsSig(collectItems());
                }).catch(function () {});
            });

            function collectItems() {
                return Array.prototype.map.call(list.querySelectorAll('.app-todo-item'), function (it) {
                    return {
                        id: it.getAttribute('data-id'),
                        is_done: it.classList.contains('done') ? 1 : 0,
                        done_by: 0, sort_order: 0,
                        content: (it.querySelector('.app-todo-text') || {}).textContent || ''
                    };
                });
            }

            /* ---------- Thao tác task: tick xong / sửa / xóa ---------- */
            list.addEventListener('change', function (e) {
                var chk = e.target.closest('.app-todo-check');
                if (!chk) return;
                var item = chk.closest('.app-todo-item');
                var id = item.getAttribute('data-id');
                var done = chk.checked;
                item.classList.toggle('done', done);
                post('todoToggle', { list_id: state.activeId, id: id, done: done ? 1 : 0 }).then(function (res) {
                    if (res && res.ok) { setBadge(res.pending); loadItems(); }   // tải lại để hiện "ai đánh dấu"
                }).catch(function () {});
            });

            list.addEventListener('click', function (e) {
                var item = e.target.closest('.app-todo-item');
                if (!item) return;
                var id = item.getAttribute('data-id');
                if (e.target.closest('.app-todo-act.del')) {
                    post('todoDelete', { list_id: state.activeId, id: id }).then(function (res) {
                        if (!res || !res.ok) return;
                        setBadge(res.pending);
                        item.remove();
                        if (!list.querySelector('.app-todo-item')) render([]);
                        lastItemsSig = itemsSig(collectItems());
                    }).catch(function () {});
                    return;
                }
                if (e.target.closest('.app-todo-act.edit') || e.target.closest('.app-todo-text')) {
                    if (!state.canEdit) return;
                    startEdit(item, id);
                }
            });

            function startEdit(item, id) {
                var textEl = item.querySelector('.app-todo-text');
                if (!textEl || item.querySelector('.app-todo-edit')) return;
                var cur = textEl.textContent;
                var inp = document.createElement('input');
                inp.type = 'text'; inp.className = 'app-todo-edit'; inp.value = cur; inp.maxLength = 500;
                textEl.replaceWith(inp);
                inp.focus(); inp.setSelectionRange(cur.length, cur.length);
                function finish(save) {
                    var v = inp.value.trim();
                    var nv = (save && v) ? v : cur;
                    var newText = document.createElement('div');
                    newText.className = 'app-todo-text';
                    newText.textContent = nv;
                    inp.replaceWith(newText);
                    if (save && v && v !== cur) {
                        post('todoUpdate', { list_id: state.activeId, id: id, content: v }).then(function () {
                            lastItemsSig = itemsSig(collectItems());
                        }).catch(function () {});
                    }
                }
                inp.addEventListener('keydown', function (ev) {
                    if (ev.key === 'Enter') { ev.preventDefault(); finish(true); }
                    else if (ev.key === 'Escape') { finish(false); }
                });
                inp.addEventListener('blur', function () { finish(true); });
            }

            /* ---------- Nút công cụ: xóa hoàn thành / xóa tất cả ---------- */
            var clearDone = document.getElementById('app-todo-clear-done');
            var clearAll  = document.getElementById('app-todo-clear-all');
            if (clearDone) clearDone.addEventListener('click', function () {
                if (!state.activeId || clearDone.disabled) return;
                var doneEls = Array.prototype.slice.call(list.querySelectorAll('.app-todo-item.done'));
                if (!doneEls.length) return;
                clearDone.disabled = true;
                var STAGGER = 90, DURATION = 330;
                doneEls.forEach(function (el, i) {
                    el.style.maxHeight = el.scrollHeight + 'px';
                    el.offsetHeight;
                    setTimeout(function () { el.classList.add('is-removing'); }, i * STAGGER);
                });
                setTimeout(function () {
                    post('todoClearDone', { list_id: state.activeId }).then(function (res) {
                        clearDone.disabled = false;
                        if (res && res.ok) { setBadge(res.pending); loadItems(); }
                    }).catch(function () { clearDone.disabled = false; });
                }, (doneEls.length - 1) * STAGGER + DURATION);
            });
            if (clearAll) clearAll.addEventListener('click', function () {
                if (!state.activeId) return;
                if (!window.confirm('Xóa tất cả việc trong danh sách này?')) return;
                post('todoClearAll', { list_id: state.activeId }).then(function (res) {
                    if (res && res.ok) { setBadge(res.pending); render([]); lastItemsSig = ''; }
                }).catch(function () {});
            });

            /* ---------- Setting: chế độ tự xóa ---------- */
            if (setBtn && setWrap) {
                setBtn.addEventListener('click', function (e) {
                    e.stopPropagation();
                    setWrap.classList.toggle('is-open');
                });
            }
            root.querySelectorAll('input[name="todo-clear-mode"]').forEach(function (r) {
                r.addEventListener('change', function () {
                    post('todoSettings', { mode: r.value }).then(function (res) {
                        if (res && res.ok) loadItems();
                    }).catch(function () {});
                });
            });

            /* ---------- Kéo-thả sắp xếp task (kiểu Trello) ---------- */
            var dragEl = null;
            list.addEventListener('mousedown', function (e) {
                var grip = e.target.closest('.app-todo-grip');
                if (!grip) return;
                var item = grip.closest('.app-todo-item');
                if (item) item.setAttribute('draggable', 'true');
            });
            list.addEventListener('dragstart', function (e) {
                var item = e.target.closest('.app-todo-item');
                if (!item) return;
                dragEl = item;
                item.classList.add('is-dragging');
                e.dataTransfer.effectAllowed = 'move';
                try { e.dataTransfer.setData('text/plain', item.getAttribute('data-id')); } catch (err) {}
            });
            list.addEventListener('dragover', function (e) {
                e.preventDefault();
                e.dataTransfer.dropEffect = 'move';
                var over = e.target.closest('.app-todo-item');
                list.querySelectorAll('.app-todo-item.drag-over').forEach(function (it) {
                    if (it !== over) it.classList.remove('drag-over');
                });
                if (!over || over === dragEl) return;
                over.classList.add('drag-over');
                var rect = over.getBoundingClientRect();
                var after = (e.clientY - rect.top) > rect.height / 2;
                list.insertBefore(dragEl, after ? over.nextSibling : over);
            });
            list.addEventListener('dragend', function () {
                if (dragEl) dragEl.classList.remove('is-dragging');
                list.querySelectorAll('.drag-over').forEach(function (it) { it.classList.remove('drag-over'); });
                list.querySelectorAll('.app-todo-item[draggable]').forEach(function (it) { it.removeAttribute('draggable'); });
                dragEl = null;
                var ids = Array.prototype.map.call(list.querySelectorAll('.app-todo-item'), function (it) {
                    return it.getAttribute('data-id');
                });
                if (ids.length) post('todoReorder', { list_id: state.activeId, ids: ids }).then(function () {
                    lastItemsSig = itemsSig(collectItems());
                }).catch(function () {});
            });

            /* ---------- Real-time: polling ---------- */
            (function () {
                function poll() {
                    // Dropdown đóng: vẫn cập nhật BADGE để con số giảm theo thời gian thực
                    // (vd: người gửi tích xong 1 việc đã chia sẻ -> badge bên nhận tự giảm).
                    if (!root.classList.contains('is-open')) {
                        fetch(API + 'todoLists', { credentials: 'same-origin' })
                            .then(function (r) { return r.json(); })
                            .then(function (res) { if (res && res.ok) { state.lists = res.lists || state.lists; setBadge(res.pending); } })
                            .catch(function () {});
                        return;
                    }
                    loadLists(true).then(function () {
                        if (!activeList()) { switchTo(state.lists.length ? state.lists[0].id : 0); return; }
                        // Đang sửa task/tên hoặc panel chia sẻ mở → đừng vẽ lại để không phá thao tác.
                        if (list.querySelector('.app-todo-edit') || root.querySelector('.app-todo-title-edit')) return;
                        if (sharePanel && sharePanel.style.display !== 'none') { loadMembers(shareSearch ? shareSearch.value.trim() : ''); }
                        fetch(API + 'todos&list_id=' + state.activeId, { credentials: 'same-origin' })
                            .then(function (r) { return r.json(); })
                            .then(function (res) {
                                if (!res || !res.ok) return;
                                setBadge(res.pending);
                                var sig = itemsSig(res.data || []);
                                if (sig !== lastItemsSig) { applyRole(); render(res.data || []); lastItemsSig = sig; }
                            }).catch(function () {});
                    });
                }
                setInterval(poll, 5000);
            })();
        })();

        /* ---- 4d. Lịch (calendar) cá nhân — nút cạnh trái app-todo ---- */
        (function () {
            var root     = document.getElementById('app-cal');
            var btn      = document.getElementById('app-cal-btn');
            var badge    = document.getElementById('app-cal-badge');
            var grid     = document.getElementById('app-cal-grid');
            var titleEl  = document.getElementById('app-cal-title');
            var prevBtn  = document.getElementById('app-cal-prev');
            var nextBtn  = document.getElementById('app-cal-next');
            var todayBtn = document.getElementById('app-cal-today-btn');
            var zoomBtn  = document.getElementById('app-cal-zoom-btn');
            var dayTitle = document.getElementById('app-cal-day-title');
            var dayList  = document.getElementById('app-cal-day-events');
            var input    = document.getElementById('app-cal-input');
            var timeRow  = document.getElementById('app-cal-add-time-row');
            var timeInp  = document.getElementById('app-cal-time-input');
            var saveBtn  = document.getElementById('app-cal-save-btn');
            if (!root || !btn || !grid) return;

            var API = '?mod=home&controllers=index&action=';
            var WEEKDAY_FULL = ['Chủ Nhật', 'Thứ Hai', 'Thứ Ba', 'Thứ Tư', 'Thứ Năm', 'Thứ Sáu', 'Thứ Bảy'];
            var MONTH_WORD = ['Một', 'Hai', 'Ba', 'Tư', 'Năm', 'Sáu', 'Bảy', 'Tám', 'Chín', 'Mười', 'Mười Một', 'Mười Hai'];

            var now = new Date();
            var state = {
                year: now.getFullYear(), month: now.getMonth(),          // lưới mini đang hiển thị
                selected: fmtDate(now.getFullYear(), now.getMonth(), now.getDate()),
                monthCounts: {}                                          // 'Y-m-d' -> số sự kiện (chấm báo)
            };
            var editingTimeId = 0;
            var currentDayItems = []; // sự kiện của state.selected — dùng để gợi ý giờ khi thêm mới

            function pad2(n) { return (n < 10 ? '0' : '') + n; }
            function fmtDate(y, m, d) { return y + '-' + pad2(m + 1) + '-' + pad2(d); }
            function todayStr() { var t = new Date(); return fmtDate(t.getFullYear(), t.getMonth(), t.getDate()); }
            function parseDate(s) {
                var m = /^(\d{4})-(\d{2})-(\d{2})$/.exec(String(s || ''));
                return m ? new Date(+m[1], +m[2] - 1, +m[3]) : null;
            }
            function esc(s) {
                return String(s == null ? '' : s)
                    .replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;')
                    .replace(/"/g, '&quot;').replace(/'/g, '&#39;');
            }
            function monthLabel(y, m) { return 'Tháng ' + MONTH_WORD[m] + ' ' + y; }
            /* Gợi ý giờ khi thêm sự kiện mới: giờ sự kiện gần nhất (có giờ) của ngày đang chọn + 15 phút;
               chưa có sự kiện nào có giờ thì mặc định 09:00 (giống logic ở view Lịch phóng to). */
            function suggestTime(items) {
                var timed = (items || []).filter(function (it) { return it.event_time; });
                if (!timed.length) return '09:00';
                var last = timed[timed.length - 1];
                var parts = last.event_time.split(':');
                var mins = (parseInt(parts[0], 10) * 60 + parseInt(parts[1], 10) + 15) % 1440;
                return pad2(Math.floor(mins / 60)) + ':' + pad2(mins % 60);
            }
            function dayTitleLabel(s) {
                var d = parseDate(s); if (!d) return '';
                return WEEKDAY_FULL[d.getDay()] + ', ' + pad2(d.getDate()) + '/' + pad2(d.getMonth() + 1) + '/' + d.getFullYear();
            }
            function post(action, params) {
                var body = new URLSearchParams();
                if (params) Object.keys(params).forEach(function (k) { body.append(k, params[k]); });
                return fetch(API + action, {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8' },
                    body: body.toString(), credentials: 'same-origin'
                }).then(function (r) { return r.json(); });
            }
            function setBadge(n) {
                n = parseInt(n, 10) || 0;
                if (!badge) return;
                if (n > 0) { badge.style.display = ''; badge.textContent = n > 99 ? '99+' : String(n); }
                else { badge.style.display = 'none'; }
            }

            /* ---------- Lưới mini tháng ---------- */
            // Trả {cells:[{y,m,d,date,otherMonth}], from, to} — số tuần co giãn 5/6 theo tháng (kiểu Outlook).
            function buildGrid(y, m) {
                var first = new Date(y, m, 1);
                var firstIdx = (first.getDay() + 6) % 7; // Thứ 2 = 0
                var dim = new Date(y, m + 1, 0).getDate();
                var totalCells = Math.ceil((firstIdx + dim) / 7) * 7;
                var start = new Date(y, m, 1 - firstIdx);
                var cells = [];
                for (var i = 0; i < totalCells; i++) {
                    var d = new Date(start.getFullYear(), start.getMonth(), start.getDate() + i);
                    cells.push({ y: d.getFullYear(), mo: d.getMonth(), d: d.getDate(), date: fmtDate(d.getFullYear(), d.getMonth(), d.getDate()), otherMonth: d.getMonth() !== m });
                }
                return { cells: cells, from: cells[0].date, to: cells[cells.length - 1].date };
            }
            function renderGrid() {
                if (titleEl) titleEl.textContent = monthLabel(state.year, state.month);
                var g = buildGrid(state.year, state.month);
                var td = todayStr();
                grid.innerHTML = g.cells.map(function (c, i) {
                    var isSun = (i % 7) === 6;
                    var cls = 'app-cal-day'
                        + (c.otherMonth ? ' is-other' : '') + (isSun ? ' is-sun' : '')
                        + (c.date === td ? ' is-today' : '') + (c.date === state.selected ? ' is-selected' : '');
                    var dot = (state.monthCounts[c.date] > 0) ? '<span class="dot"></span>' : '';
                    return '<button type="button" class="' + cls + '" data-date="' + c.date + '">' + c.d + dot + '</button>';
                }).join('');
                return g;
            }
            function loadMonth() {
                var g = renderGrid();
                fetch(API + 'evcalRange&from=' + g.from + '&to=' + g.to, { credentials: 'same-origin' })
                    .then(function (r) { return r.json(); })
                    .then(function (res) {
                        if (!res || !res.ok) return;
                        var counts = {};
                        (res.data || []).forEach(function (it) { counts[it.event_date] = (counts[it.event_date] || 0) + 1; });
                        state.monthCounts = counts;
                        renderGrid();
                    }).catch(function () {});
            }

            /* ---------- Panel ngày đã chọn ---------- */
            function eventRowHtml(it) {
                var isAllDay = !it.event_time;
                var timeTxt = isAllDay ? 'Cả ngày' : esc(it.event_time);
                var done = !!it.done;
                return '<div class="app-cal-event' + (done ? ' is-done' : '') + '" data-id="' + it.id + '">'
                    + '<span class="app-cal-event-marker">'
                    + '<span class="app-cal-event-dot"></span>'
                    + '<label class="app-cal-event-check" title="Đánh dấu đã thực hiện">'
                    + '<input type="checkbox" class="app-cal-event-check-input"' + (done ? ' checked' : '') + '>'
                    + '<span class="app-cal-event-check-mark"><i class="fa-solid fa-check"></i></span>'
                    + '</label>'
                    + '</span>'
                    + '<div class="app-cal-event-body">'
                    + '<div class="app-cal-event-text" title="Bấm để sửa nội dung">' + esc(it.content) + '</div>'
                    + '<span class="app-cal-event-time' + (isAllDay ? ' is-allday' : '') + '" data-time="' + esc(it.event_time || '') + '" title="Bấm để đổi giờ nhắc">'
                    + '<i class="fa-regular fa-clock"></i> ' + timeTxt + '</span>'
                    + '</div>'
                    + '<button type="button" class="app-cal-event-del" title="Xóa"><i class="fa-solid fa-xmark"></i></button>'
                    + '</div>';
            }
            function renderDay(items) {
                currentDayItems = items || [];
                if (dayTitle) dayTitle.textContent = dayTitleLabel(state.selected);
                if (!items || !items.length) { dayList.innerHTML = '<div class="app-cal-day-empty">Chưa có sự kiện / lời nhắc nào.</div>'; return; }
                dayList.innerHTML = items.map(eventRowHtml).join('');
            }
            function loadDay() {
                fetch(API + 'evcalDay&date=' + state.selected, { credentials: 'same-origin' })
                    .then(function (r) { return r.json(); })
                    .then(function (res) { if (res && res.ok) renderDay(res.data || []); })
                    .catch(function () { dayList.innerHTML = '<div class="app-cal-day-empty">Không tải được.</div>'; });
            }
            function selectDate(dateStr) {
                state.selected = dateStr;
                var parts = /^(\d{4})-(\d{2})/.exec(dateStr);
                if (parts) { state.year = +parts[1]; state.month = +parts[2] - 1; }
                renderGrid();
                loadDay();
            }

            /* ---------- Mở / đóng dropdown ---------- */
            var firstLoaded = false;
            btn.addEventListener('click', function (e) {
                e.stopPropagation();
                var open = root.classList.toggle('is-open');
                if (open) {
                    if (!firstLoaded) { firstLoaded = true; selectDate(state.selected); }
                    loadMonth();
                }
            });
            var clickedInside = false;
            root.addEventListener('click', function () { clickedInside = true; }, true);
            document.addEventListener('click', function () {
                if (clickedInside) { clickedInside = false; return; }
                root.classList.remove('is-open');
            });

            grid.addEventListener('click', function (e) {
                var cell = e.target.closest('.app-cal-day');
                if (cell) selectDate(cell.getAttribute('data-date'));
            });
            if (prevBtn) prevBtn.addEventListener('click', function () {
                state.month--; if (state.month < 0) { state.month = 11; state.year--; }
                loadMonth();
            });
            if (nextBtn) nextBtn.addEventListener('click', function () {
                state.month++; if (state.month > 11) { state.month = 0; state.year++; }
                loadMonth();
            });
            if (todayBtn) todayBtn.addEventListener('click', function () {
                var t = new Date();
                state.year = t.getFullYear(); state.month = t.getMonth();
                selectDate(todayStr());
                loadMonth();
            });

            /* ---------- Thêm sự kiện / lời nhắc ---------- */
            function resetAdd() {
                input.value = '';
                timeInp.value = '';
                timeRow.style.display = 'none';
            }
            input.addEventListener('input', function () {
                var show = input.value.trim() !== '';
                timeRow.style.display = show ? 'flex' : 'none';
                if (show && !timeInp.value) timeInp.value = suggestTime(currentDayItems);
            });
            function doSave() {
                var content = input.value.trim();
                if (!content) return;
                post('evcalCreate', { date: state.selected, time: timeInp.value || '', content: content }).then(function (res) {
                    if (!res || !res.ok) return;
                    resetAdd();
                    setBadge(res.today);
                    loadDay();
                    loadMonth();
                    // Đồng bộ real-time với view Lịch phóng to nếu đang mở cùng trang.
                    if (typeof window.calfullReload === 'function') window.calfullReload();
                }).catch(function () {});
            }
            if (saveBtn) saveBtn.addEventListener('click', doSave);
            input.addEventListener('keydown', function (ev) {
                if (ev.key === 'Enter') { ev.preventDefault(); doSave(); }
            });

            /* ---------- Sửa nội dung / xóa / đổi giờ 1 sự kiện trong panel ngày ---------- */
            function startEditContent(row) {
                var id = row.getAttribute('data-id');
                var textEl = row.querySelector('.app-cal-event-text');
                if (!textEl || row.querySelector('.app-cal-event-edit')) return;
                var cur = textEl.textContent;
                var inp = document.createElement('input');
                inp.type = 'text'; inp.className = 'app-cal-event-edit'; inp.value = cur; inp.maxLength = 500;
                textEl.style.display = 'none';
                textEl.parentNode.insertBefore(inp, textEl);
                inp.focus(); inp.select();
                function finish(save) {
                    var v = inp.value.trim();
                    inp.remove();
                    textEl.style.display = '';
                    if (save && v && v !== cur) {
                        textEl.textContent = v;
                        post('evcalUpdate', { id: id, content: v }).catch(function () {});
                    }
                }
                inp.addEventListener('keydown', function (ev) {
                    if (ev.key === 'Enter') { ev.preventDefault(); finish(true); }
                    else if (ev.key === 'Escape') { finish(false); }
                });
                inp.addEventListener('blur', function () { finish(true); });
            }
            dayList.addEventListener('click', function (e) {
                var row = e.target.closest('.app-cal-event');
                if (!row) return;
                var id = row.getAttribute('data-id');
                if (e.target.closest('.app-cal-event-del')) {
                    post('evcalDelete', { id: id }).then(function (res) {
                        if (res && res.ok) {
                            setBadge(res.today); loadDay(); loadMonth();
                            // Đồng bộ real-time với view Lịch phóng to nếu đang mở cùng trang.
                            if (typeof window.calfullReload === 'function') window.calfullReload();
                        }
                    }).catch(function () {});
                    return;
                }
                if (e.target.closest('.app-cal-event-time')) { openTimeModal(id, row); return; }
                if (e.target.closest('.app-cal-event-text')) { startEditContent(row); return; }
            });

            /* ---------- Đánh dấu đã thực hiện (checkbox tròn, hover hiện) ---------- */
            dayList.addEventListener('change', function (e) {
                var chk = e.target.closest('.app-cal-event-check-input');
                if (!chk) return;
                var row = chk.closest('.app-cal-event');
                var id = row.getAttribute('data-id');
                var doneNow = chk.checked;
                row.classList.toggle('is-done', doneNow);
                post('evcalSetDone', { id: id, done: doneNow ? '1' : '0' }).then(function (res) {
                    if (!res || !res.ok) { chk.checked = !doneNow; row.classList.toggle('is-done', !doneNow); return; }
                    setBadge(res.today);
                    if (typeof window.calfullReload === 'function') window.calfullReload();
                }).catch(function () { chk.checked = !doneNow; row.classList.toggle('is-done', !doneNow); });
            });

            /* ---------- Modal đổi giờ nhắc ---------- */
            var timeModal   = document.getElementById('app-cal-time-modal');
            var timeModalIn = document.getElementById('app-cal-time-modal-input');
            var timeModalNo = document.getElementById('app-cal-time-modal-noclock');
            var timeModalMsg= document.getElementById('app-cal-time-modal-msg');
            var timeModalSave = document.getElementById('app-cal-time-modal-save');
            function openTimeModal(id, row) {
                if (!timeModal) return;
                editingTimeId = id;
                var chip = row.querySelector('.app-cal-event-time');
                var isAllDay = chip && chip.classList.contains('is-allday');
                timeModalIn.value = chip ? (chip.getAttribute('data-time') || '') : '';
                timeModalNo.checked = !!isAllDay;
                timeModalIn.disabled = timeModalNo.checked;
                if (timeModalMsg) timeModalMsg.textContent = '';
                timeModal.classList.add('is-open'); timeModal.setAttribute('aria-hidden', 'false');
            }
            function closeTimeModal() {
                if (!timeModal) return;
                timeModal.classList.remove('is-open'); timeModal.setAttribute('aria-hidden', 'true');
                editingTimeId = 0;
            }
            if (timeModalNo) timeModalNo.addEventListener('change', function () { timeModalIn.disabled = timeModalNo.checked; });
            document.querySelectorAll('[data-cal-time-close]').forEach(function (el) { el.addEventListener('click', closeTimeModal); });
            if (timeModalSave) timeModalSave.addEventListener('click', function () {
                if (!editingTimeId) return;
                var val = timeModalNo.checked ? '' : timeModalIn.value;
                if (!timeModalNo.checked && !val) { timeModalMsg.textContent = 'Chọn giờ hoặc tích "Không nhắc giờ".'; return; }
                post('evcalUpdate', { id: editingTimeId, time: val }).then(function (res) {
                    if (!res || !res.ok) { timeModalMsg.textContent = 'Không lưu được.'; return; }
                    closeTimeModal(); loadDay();
                }).catch(function () { timeModalMsg.textContent = 'Lỗi kết nối.'; });
            });

            /* ---------- "Phóng to": điều hướng sang trang Lịch đầy đủ (không phải modal) ---------- */
            if (zoomBtn) zoomBtn.addEventListener('click', function () {
                window.location.href = '?mod=home&controllers=index&action=calendar_full';
            });

            /* ---------- Nhắc lời nhắc bằng chuông: quét định kỳ, độc lập dropdown ---------- */
            (function () {
                function checkReminders() { post('evcalCheck', {}).catch(function () {}); }
                setInterval(checkReminders, 25000);
                setTimeout(checkReminders, 3000);
            })();

            /* Cho view Lịch phóng to (calendar_full.js) và menu "Nhắc lại" trên chuông
               gọi để đồng bộ real-time khi move/xóa/snooze sự kiện làm thay đổi
               số lượng sự kiện của "hôm nay" — không cần tải lại trang. */
            window.appCalSetBadge = setBadge;
            window.appCalReload = function () {
                loadMonth();
                if (root.classList.contains('is-open')) loadDay();
            };
        })();

        /* ---- 5. Đẩy ô tìm kiếm .js-to-header lên header ---- */
        var slot = document.getElementById('app-header-search');
        if (slot) {
            var pickups = document.querySelectorAll('.js-to-header');
            pickups.forEach(function (el) { slot.appendChild(el); });
        }

        /* ---- 5b. Đưa các cụm action ở header cũ (đã ẩn) lên header mới:
                   .cdb-actions (Check Database), .header-actions (Reset/Share/Check DB) ---- */
        var hdrRight = document.querySelector('.app-header-right');
        if (hdrRight) {
            document.querySelectorAll('.cdb-actions, .header-actions').forEach(function (grp) {
                grp.style.display = '';
                hdrRight.insertBefore(grp, hdrRight.firstChild);
            });
        }

        /* ---- 5c. "Check database" = ICON DÙNG CHUNG, đặt cạnh TRÁI chuông ----
           Quy ước toàn dự án: nút Check database ở MỌI view chỉ còn 1 icon database
           (tooltip "Database"), nằm ngay bên trái chuông thông báo. View cứ khai báo
           nút .btn-check-db ở đâu cũng được (toolbar, .cdb-actions, .header-actions,
           .detail-actions...), JS này gom lên header và bỏ phần nhãn chữ.
           Nút trong modal (.pp-modal-overlay) không tính — đó là nội dung modal.
           CHẠY NGOÀI if (hdrRight): app_shell.css ẩn sẵn .btn-check-db chưa gắn
           .app-cdb-icon (chống nháy "hiện ở toolbar rồi mới nhảy lên header"), nên
           trang không có header chung vẫn phải được gắn class để nút không mất hẳn. */
        (function () {
            var bellEl = document.getElementById('app-bell');
            document.querySelectorAll('.btn-check-db').forEach(function (btn) {
                if (btn.closest('.pp-modal-overlay') || btn.closest('.app-tools-menu')) return;
                // Tooltip tự vẽ (data-app-tip) chứ không dùng title native: title bị trễ
                // ~1s và mang kiểu của OS. Bỏ luôn title cũ để không hiện 2 tooltip.
                btn.removeAttribute('title');
                btn.setAttribute('data-app-tip', 'Database');
                btn.setAttribute('aria-label', 'Database');
                // Chỉ giữ icon: bỏ text node/nhãn, thiếu icon thì tự thêm.
                var ico = btn.querySelector('i');
                btn.innerHTML = '';
                btn.appendChild(ico || Object.assign(document.createElement('i'), { className: 'fa-solid fa-database' }));
                if (hdrRight) {
                    hdrRight.insertBefore(btn, (bellEl && bellEl.parentNode === hdrRight) ? bellEl : null);
                }
                // Gắn class SAU khi đã dời + rút gọn: đây cũng là công tắc hiện nút (CSS).
                btn.classList.add('app-cdb-icon');
            });
        })();

        if (hdrRight) {
            /* ---- 5d. MOBILE: gom action header cho gọn (Task R2-5).
               Chỉ đổi DOM chung; việc hiển thị (menu/dropdown) do CSS @media 768px quyết định,
               nên desktop không đổi. ---- */
            // (R2-5) Reset / In BC / Share KHSX -> gom sau 1 nút icon (dropdown).
            var actionsGrp = hdrRight.querySelector('.header-actions');
            if (actionsGrp) {
                var aWrap = document.createElement('div');
                aWrap.className = 'app-actions';
                var aBtn = document.createElement('button');
                aBtn.type = 'button';
                aBtn.className = 'app-actions-btn';
                aBtn.setAttribute('aria-label', 'Thao tác');
                aBtn.innerHTML = '<i class="fa-solid fa-ellipsis-vertical"></i>';
                hdrRight.insertBefore(aWrap, actionsGrp);
                aWrap.appendChild(aBtn);
                aWrap.appendChild(actionsGrp);
                aBtn.addEventListener('click', function (e) { e.stopPropagation(); aWrap.classList.toggle('is-open'); });
                document.addEventListener('click', function (e) { if (!aWrap.contains(e.target)) aWrap.classList.remove('is-open'); });
            }
        }

        /* ---- 6. Upload avatar ---- */
        var avatarInput = document.getElementById('app-avatar-input');
        if (avatarInput) {
            avatarInput.addEventListener('change', function () {
                if (!avatarInput.files || !avatarInput.files[0]) return;
                var fd = new FormData();
                fd.append('avatar', avatarInput.files[0]);
                fetch('?mod=home&controllers=index&action=uploadAvatar', {
                    method: 'POST', body: fd, credentials: 'same-origin'
                })
                .then(function (r) { return r.json(); })
                .then(function (res) {
                    if (res && res.ok && res.url) {
                        document.querySelectorAll('.app-avatar').forEach(function (av) {
                            av.classList.add('has-img');
                            av.innerHTML = '<img src="' + res.url + '?t=' + Date.now() + '" alt="avatar">';
                        });
                    } else {
                        alert((res && res.message) || 'Tải ảnh thất bại');
                    }
                })
                .catch(function () { alert('Lỗi kết nối khi tải ảnh'); });
            });
        }

        /* ---- 7. Sửa tên hiển thị (inline) ---- */
        var nameWrap = document.getElementById('app-user-card-name');
        var nameText = document.getElementById('app-user-name-text');
        var nameEdit = document.getElementById('app-user-name-edit');
        if (nameWrap && nameText && nameEdit) {
            nameEdit.addEventListener('click', function (e) {
                e.stopPropagation();
                if (nameWrap.querySelector('.app-user-name-input')) return;
                var current = nameText.textContent.trim();
                var input = document.createElement('input');
                input.type = 'text';
                input.className = 'app-user-name-input';
                input.value = current;
                input.maxLength = 100;
                nameText.style.display = 'none';
                nameEdit.style.display = 'none';
                nameWrap.insertBefore(input, nameEdit);
                input.focus();
                input.select();

                var done = false;
                function finish(save) {
                    if (done) return; done = true;
                    var val = input.value.trim();
                    nameText.style.display = '';
                    nameEdit.style.display = '';
                    if (input.parentNode) input.parentNode.removeChild(input);
                    if (!save || val === '' || val === current) return;
                    var fd = new FormData();
                    fd.append('fullname', val);
                    fetch('?mod=home&controllers=index&action=updateProfileName', {
                        method: 'POST', body: fd, credentials: 'same-origin'
                    })
                    .then(function (r) { return r.json(); })
                    .then(function (res) {
                        if (res && res.ok) {
                            nameText.textContent = val;
                            var hdrName = document.querySelector('.app-user-name');
                            if (hdrName) hdrName.textContent = val;
                            document.querySelectorAll('.app-avatar:not(.has-img)').forEach(function (av) {
                                av.textContent = val.charAt(0).toUpperCase();
                            });
                        } else {
                            alert((res && res.message) || 'Đổi tên thất bại');
                        }
                    })
                    .catch(function () { alert('Lỗi kết nối'); });
                }
                input.addEventListener('keydown', function (ev) {
                    if (ev.key === 'Enter') { ev.preventDefault(); finish(true); }
                    else if (ev.key === 'Escape') { finish(false); }
                });
                input.addEventListener('blur', function () { finish(true); });
            });
        }

        /* ---- 8. Đổi mật khẩu (gửi mã 6 ký tự -> xác thực -> đặt mật khẩu mới) ---- */
        var pwModal = document.getElementById('app-pw-modal');
        if (pwModal) {
            var AUTH = '?mod=auth&controllers=index&action=';
            var identifier = pwModal.getAttribute('data-identifier') || '';
            var openBtn  = document.getElementById('app-open-change-pw');
            var step1    = document.getElementById('app-pw-step-1');
            var step2    = document.getElementById('app-pw-step-2');
            var sendBtn  = document.getElementById('app-pw-send');
            var codeWrap = document.getElementById('app-pw-code-wrap');
            var resendBtn= document.getElementById('app-pw-resend');
            var timerEl  = document.getElementById('app-pw-timer');
            var verifyBtn= document.getElementById('app-pw-verify');
            var submitBtn= document.getElementById('app-pw-submit');
            var msg1     = document.getElementById('app-pw-msg-1');
            var msg2     = document.getElementById('app-pw-msg-2');
            var digits   = Array.prototype.slice.call(pwModal.querySelectorAll('.app-pw-digit'));
            var timer    = null;

            function setMsg(el, text, ok) {
                el.textContent = text || '';
                el.classList.remove('ok', 'err');
                if (text) el.classList.add(ok ? 'ok' : 'err');
            }
            function openModal() {
                pwModal.classList.add('is-open');
                step1.style.display = ''; step2.style.display = 'none';
                codeWrap.style.display = 'none';
                sendBtn.style.display = '';
                setMsg(msg1, ''); setMsg(msg2, '');
                digits.forEach(function (d) { d.value = ''; });
            }
            function closeModal() {
                pwModal.classList.remove('is-open');
                if (timer) { clearInterval(timer); timer = null; }
            }
            function getCode() { return digits.map(function (d) { return d.value.trim(); }).join(''); }

            function startTimer() {
                var left = 60;
                resendBtn.disabled = true;
                timerEl.textContent = 'Mã hết hạn nhập sau ' + left + 's';
                if (timer) clearInterval(timer);
                timer = setInterval(function () {
                    left--;
                    if (left <= 0) {
                        clearInterval(timer); timer = null;
                        timerEl.textContent = 'Mã đã hết hạn, vui lòng gửi lại.';
                        resendBtn.disabled = false;
                    } else {
                        timerEl.textContent = 'Mã hết hạn nhập sau ' + left + 's';
                    }
                }, 1000);
            }
            function sendCode() {
                sendBtn.disabled = true; resendBtn.disabled = true;
                setMsg(msg1, 'Đang gửi mã...', true);
                var fd = new FormData(); fd.append('identifier', identifier);
                fetch(AUTH + 'sendResetCode', { method: 'POST', body: fd, credentials: 'same-origin' })
                .then(function (r) { return r.json(); })
                .then(function (res) {
                    sendBtn.disabled = false;
                    if (res && res.ok) {
                        sendBtn.style.display = 'none';
                        codeWrap.style.display = '';
                        setMsg(msg1, res.message || 'Đã gửi mã.', true);
                        digits.forEach(function (d) { d.value = ''; });
                        if (digits[0]) digits[0].focus();
                        startTimer();
                    } else {
                        setMsg(msg1, (res && res.message) || 'Gửi mã thất bại', false);
                    }
                })
                .catch(function () { sendBtn.disabled = false; setMsg(msg1, 'Lỗi kết nối', false); });
            }

            if (openBtn) openBtn.addEventListener('click', function () {
                if (user) user.classList.remove('is-open');
                openModal();
            });
            pwModal.querySelectorAll('[data-pw-close]').forEach(function (el) {
                el.addEventListener('click', closeModal);
            });
            sendBtn.addEventListener('click', sendCode);
            resendBtn.addEventListener('click', sendCode);

            // Auto-advance + paste cho ô nhập mã
            digits.forEach(function (d, idx) {
                d.addEventListener('input', function () {
                    d.value = d.value.toUpperCase();
                    if (d.value && idx < digits.length - 1) digits[idx + 1].focus();
                });
                d.addEventListener('keydown', function (ev) {
                    if (ev.key === 'Backspace' && !d.value && idx > 0) digits[idx - 1].focus();
                });
                d.addEventListener('paste', function (ev) {
                    ev.preventDefault();
                    var t = (ev.clipboardData || window.clipboardData).getData('text').trim().toUpperCase();
                    for (var i = 0; i < digits.length; i++) digits[i].value = t.charAt(i) || '';
                    var last = Math.min(t.length, digits.length) - 1;
                    if (last >= 0) digits[Math.min(last, digits.length - 1)].focus();
                });
            });

            verifyBtn.addEventListener('click', function () {
                var code = getCode();
                if (code.length < 6) { setMsg(msg1, 'Vui lòng nhập đủ 6 ký tự.', false); return; }
                verifyBtn.disabled = true;
                var fd = new FormData(); fd.append('identifier', identifier); fd.append('activation_code', code);
                fetch(AUTH + 'verifyResetCode', { method: 'POST', body: fd, credentials: 'same-origin' })
                .then(function (r) { return r.json(); })
                .then(function (res) {
                    verifyBtn.disabled = false;
                    if (res && res.ok) {
                        if (timer) { clearInterval(timer); timer = null; }
                        step1.style.display = 'none'; step2.style.display = '';
                        setMsg(msg2, '');
                    } else {
                        setMsg(msg1, (res && res.message) || 'Mã không đúng', false);
                    }
                })
                .catch(function () { verifyBtn.disabled = false; setMsg(msg1, 'Lỗi kết nối', false); });
            });

            submitBtn.addEventListener('click', function () {
                var pw = document.getElementById('app-pw-new').value;
                var pc = document.getElementById('app-pw-confirm').value;
                if (!pw || pw.length < 6) { setMsg(msg2, 'Mật khẩu tối thiểu 6 ký tự.', false); return; }
                if (pw !== pc) { setMsg(msg2, 'Mật khẩu nhập lại không khớp.', false); return; }
                submitBtn.disabled = true;
                var fd = new FormData();
                fd.append('identifier', identifier);
                fd.append('activation_code', getCode());
                fd.append('password', pw);
                fd.append('password_confirm', pc);
                fetch(AUTH + 'resetPassword', { method: 'POST', body: fd, credentials: 'same-origin' })
                .then(function (r) { return r.json(); })
                .then(function (res) {
                    submitBtn.disabled = false;
                    if (res && res.ok) {
                        setMsg(msg2, 'Đổi mật khẩu thành công!', true);
                        setTimeout(closeModal, 1200);
                    } else {
                        setMsg(msg2, (res && res.message) || 'Đổi mật khẩu thất bại', false);
                    }
                })
                .catch(function () { submitBtn.disabled = false; setMsg(msg2, 'Lỗi kết nối', false); });
            });
        }

        /* ---- 8b. Cài đặt tài khoản (sửa hồ sơ + avatar) ---- */
        var acctModal = document.getElementById('app-account-modal');
        if (acctModal) {
            var acctOpen   = document.getElementById('app-open-account');
            var acctSave   = document.getElementById('app-account-save');
            var acctMsg    = document.getElementById('app-account-msg');
            var acctAvaInp = document.getElementById('app-account-avatar-input');

            function acctSetMsg(t, ok) {
                acctMsg.textContent = t || '';
                acctMsg.classList.remove('ok', 'err');
                if (t) acctMsg.classList.add(ok ? 'ok' : 'err');
            }
            function acctOpenModal() { acctModal.classList.add('is-open'); acctSetMsg(''); }
            function acctClose() { acctModal.classList.remove('is-open'); }

            if (acctOpen) acctOpen.addEventListener('click', function () {
                if (user) user.classList.remove('is-open');
                acctOpenModal();
            });
            acctModal.querySelectorAll('[data-account-close]').forEach(function (el) {
                el.addEventListener('click', acctClose);
            });

            // Nút "máy ảnh" (hover trên avatar) -> mở hộp chọn tệp.
            var acctCamBtn  = document.getElementById('app-account-ava-cam');
            if (acctCamBtn && acctAvaInp) {
                acctCamBtn.addEventListener('click', function () { acctAvaInp.click(); });
            }

            // Nút "con mắt" -> lightbox xem ảnh phóng to.
            var acctViewBtn = document.getElementById('app-account-ava-view');
            var avaLightbox = document.getElementById('app-ava-lightbox');
            var avaLbInner  = document.getElementById('app-ava-lightbox-inner');
            var avaLbClose  = document.getElementById('app-ava-lightbox-close');
            function openAvaLightbox() {
                if (!avaLightbox || !avaLbInner) return;
                var ava = document.querySelector('.app-account-avatar-img');
                var img = ava ? ava.querySelector('img') : null;
                if (img) {
                    avaLbInner.innerHTML = '<img src="' + img.getAttribute('src') + '" alt="avatar">';
                } else {
                    var letter = ava ? (ava.textContent || '?').trim() : '?';
                    avaLbInner.innerHTML = '<span class="app-ava-lightbox-letter">' + letter + '</span>';
                }
                avaLightbox.classList.add('is-open');
            }
            function closeAvaLightbox() { if (avaLightbox) avaLightbox.classList.remove('is-open'); }
            if (acctViewBtn) acctViewBtn.addEventListener('click', openAvaLightbox);
            if (avaLbClose)  avaLbClose.addEventListener('click', closeAvaLightbox);
            if (avaLightbox) avaLightbox.addEventListener('click', function (e) {
                if (e.target === avaLightbox) closeAvaLightbox();
            });
            document.addEventListener('keydown', function (e) {
                if (e.key === 'Escape' && avaLightbox && avaLightbox.classList.contains('is-open')) closeAvaLightbox();
            });

            // Đổi avatar (dùng lại endpoint uploadAvatar, cập nhật mọi .app-avatar).
            if (acctAvaInp) {
                acctAvaInp.addEventListener('change', function () {
                    if (!acctAvaInp.files || !acctAvaInp.files[0]) return;
                    var fd = new FormData();
                    fd.append('avatar', acctAvaInp.files[0]);
                    acctSetMsg('Đang tải ảnh...', true);
                    fetch('?mod=home&controllers=index&action=uploadAvatar', {
                        method: 'POST', body: fd, credentials: 'same-origin'
                    })
                    .then(function (r) { return r.json(); })
                    .then(function (res) {
                        if (res && res.ok && res.url) {
                            document.querySelectorAll('.app-avatar').forEach(function (av) {
                                av.classList.add('has-img');
                                av.innerHTML = '<img src="' + res.url + '?t=' + Date.now() + '" alt="avatar">';
                            });
                            acctSetMsg('Đã cập nhật ảnh đại diện.', true);
                        } else {
                            acctSetMsg((res && res.message) || 'Tải ảnh thất bại', false);
                        }
                    })
                    .catch(function () { acctSetMsg('Lỗi kết nối khi tải ảnh', false); });
                });
            }

            // Lưu hồ sơ.
            if (acctSave) acctSave.addEventListener('click', function () {
                var fullname = (document.getElementById('app-account-fullname').value || '').trim();
                var day      = document.getElementById('app-account-day').value;
                var month    = document.getElementById('app-account-month').value;
                var year     = document.getElementById('app-account-year').value;
                var gender   = document.getElementById('app-account-gender').value;
                var phone    = (document.getElementById('app-account-phone').value || '').trim();

                if (fullname === '') { acctSetMsg('Họ và tên không được để trống.', false); return; }
                if (!day || !month || !year) { acctSetMsg('Vui lòng chọn đủ ngày, tháng, năm sinh.', false); return; }
                if (!gender) { acctSetMsg('Vui lòng chọn giới tính.', false); return; }

                acctSave.disabled = true;
                acctSetMsg('Đang lưu...', true);
                var fd = new FormData();
                fd.append('fullname', fullname);
                fd.append('day', day);
                fd.append('month', month);
                fd.append('year', year);
                fd.append('gender', gender);
                fd.append('phone', phone);
                fetch('?mod=home&controllers=index&action=updateAccountSettings', {
                    method: 'POST', body: fd, credentials: 'same-origin'
                })
                .then(function (r) { return r.json(); })
                .then(function (res) {
                    acctSave.disabled = false;
                    if (res && res.ok) {
                        acctSetMsg(res.message || 'Đã lưu.', true);
                        // Cập nhật tên hiển thị ở header + dropdown + avatar chữ cái.
                        var hdrName = document.querySelector('.app-user-name');
                        if (hdrName) hdrName.textContent = res.fullname;
                        var cardName = document.getElementById('app-user-name-text');
                        if (cardName) cardName.textContent = res.fullname;
                        document.querySelectorAll('.app-avatar:not(.has-img)').forEach(function (av) {
                            av.textContent = (res.fullname || '?').charAt(0).toUpperCase();
                        });
                        setTimeout(acctClose, 1000);
                    } else {
                        acctSetMsg((res && res.message) || 'Lưu thất bại', false);
                    }
                })
                .catch(function () { acctSave.disabled = false; acctSetMsg('Lỗi kết nối', false); });
            });
        }

        /* ---- 8c. Cài đặt hệ thống (admin trưởng): dọn tin nhắn chat + giới hạn dung lượng ---- */
        var setModal = document.getElementById('app-settings-modal');
        if (setModal) {
            var setOpenBtn  = document.getElementById('app-sb-settings-btn');
            var setLoaded   = false;
            var setChatMsg  = document.getElementById('app-set-chat-msg');
            var setStoMsg   = document.getElementById('app-set-storage-msg');
            var setBase     = '?mod=admin_factory&controllers=admin&action=';

            function setMsgEl(el, text, ok) {
                el.textContent = text || '';
                el.classList.remove('ok', 'err');
                if (text) el.classList.add(ok ? 'ok' : 'err');
            }

            function loadSettings() {
                fetch(setBase + 'system_settings_get', { credentials: 'same-origin' })
                    .then(function (r) { return r.json(); })
                    .then(function (res) {
                        if (!res || !res.success) return;
                        var mode = res.data.chat_retention_mode || 'none';
                        var radio = setModal.querySelector('input[name="app-set-chat-mode"][value="' + mode + '"]');
                        if (radio) radio.checked = true;
                        var quota = document.getElementById('app-set-storage-quota');
                        if (quota) quota.value = res.data.storage_quota_mb || '';
                    });
            }

            function openSetModal() {
                setModal.classList.add('is-open');
                if (!setLoaded) { setLoaded = true; loadSettings(); loadPriorityList(); }
            }
            function closeSetModal() { setModal.classList.remove('is-open'); }

            if (setOpenBtn) setOpenBtn.addEventListener('click', openSetModal);
            setModal.querySelectorAll('[data-appset-close]').forEach(function (el) {
                el.addEventListener('click', closeSetModal);
            });

            setModal.querySelectorAll('.app-set-tab').forEach(function (tab) {
                tab.addEventListener('click', function () {
                    setModal.querySelectorAll('.app-set-tab').forEach(function (t) { t.classList.remove('is-active'); });
                    tab.classList.add('is-active');
                    var key = tab.dataset.setTab;
                    document.getElementById('app-set-pane-chat').style.display = key === 'chat' ? '' : 'none';
                    document.getElementById('app-set-pane-storage').style.display = key === 'storage' ? '' : 'none';
                });
            });

            var setChatSave = document.getElementById('app-set-chat-save');
            if (setChatSave) setChatSave.addEventListener('click', function () {
                var checked = setModal.querySelector('input[name="app-set-chat-mode"]:checked');
                if (!checked) { setMsgEl(setChatMsg, 'Vui lòng chọn 1 chế độ.', false); return; }
                setChatSave.disabled = true;
                var body = new URLSearchParams();
                body.append('key', 'chat_retention_mode');
                body.append('value', checked.value);
                fetch(setBase + 'system_settings_save', {
                    method: 'POST', headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                    body: body.toString(), credentials: 'same-origin'
                }).then(function (r) { return r.json(); }).then(function (res) {
                    setChatSave.disabled = false;
                    setMsgEl(setChatMsg, res && res.success ? 'Đã lưu cài đặt chat.' : 'Lưu thất bại.', res && res.success);
                }).catch(function () { setChatSave.disabled = false; setMsgEl(setChatMsg, 'Lỗi kết nối.', false); });
            });

            var setStoSave = document.getElementById('app-set-storage-save');
            if (setStoSave) setStoSave.addEventListener('click', function () {
                var val = parseInt(document.getElementById('app-set-storage-quota').value, 10);
                if (!val || val <= 0) { setMsgEl(setStoMsg, 'Vui lòng nhập số MB hợp lệ.', false); return; }
                setStoSave.disabled = true;
                var body = new URLSearchParams();
                body.append('key', 'storage_quota_mb');
                body.append('value', String(val));
                fetch(setBase + 'system_settings_save', {
                    method: 'POST', headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                    body: body.toString(), credentials: 'same-origin'
                }).then(function (r) { return r.json(); }).then(function (res) {
                    setStoSave.disabled = false;
                    setMsgEl(setStoMsg, res && res.success ? 'Đã lưu giới hạn dung lượng.' : 'Lưu thất bại.', res && res.success);
                }).catch(function () { setStoSave.disabled = false; setMsgEl(setStoMsg, 'Lỗi kết nối.', false); });
            });

            /* ---- Ưu tiên dung lượng riêng theo từng user ---- */
            var prioSearchInp = document.getElementById('app-set-priority-search');
            var prioUserHidden = document.getElementById('app-set-priority-user');
            var prioCombo     = document.getElementById('app-set-priority-combo');
            var prioComboList = document.getElementById('app-set-priority-combo-list');
            var prioQuotaInp = document.getElementById('app-set-priority-quota');
            var prioAddBtn   = document.getElementById('app-set-priority-add');
            var prioMsg      = document.getElementById('app-set-priority-msg');
            var prioList     = document.getElementById('app-set-priority-list');
            var prioUsers    = []; // { id, name } - toàn bộ user, nạp 1 lần khi mở modal
            var prioFiltered = [];
            var prioActiveIdx = -1;

            function escHtml(s) {
                return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) {
                    return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c];
                });
            }

            function renderPriorityList(overrides) {
                if (!overrides.length) { prioList.innerHTML = '<div class="app-set-hint">Chưa có người dùng nào được ưu tiên.</div>'; return; }
                prioList.innerHTML = overrides.map(function (o) {
                    return '<div class="app-set-priority-item" data-uid="' + o.user_id + '">'
                        + '<span class="name">' + escHtml(o.name) + '</span>'
                        + '<span class="quota">' + o.quota_mb + ' MB</span>'
                        + '<button type="button" class="rm" title="Gỡ ưu tiên"><i class="fa-solid fa-trash"></i></button>'
                        + '</div>';
                }).join('');
            }

            function loadPriorityList() {
                fetch(setBase + 'storage_priority_list', { credentials: 'same-origin' })
                    .then(function (r) { return r.json(); })
                    .then(function (res) {
                        if (!res || !res.success) return;
                        prioUsers = res.users || [];
                        renderPriorityList(res.overrides || []);
                    });
            }

            /* ---- Ô tìm kiếm người dùng dạng combobox: gõ lọc, mũi tên di chuyển,
               Tab/Enter để chọn ---- */
            function prioRenderCombo() {
                if (!prioFiltered.length) {
                    prioComboList.innerHTML = '<div class="app-combo-empty">Không tìm thấy người dùng.</div>';
                    prioComboList.style.display = '';
                    return;
                }
                prioComboList.innerHTML = prioFiltered.map(function (u, i) {
                    return '<div class="app-combo-item' + (i === prioActiveIdx ? ' is-active' : '') + '" data-idx="' + i + '">'
                        + escHtml(u.name) + '</div>';
                }).join('');
                prioComboList.style.display = '';
            }
            function prioCloseCombo() { prioComboList.style.display = 'none'; }
            function prioFilterCombo(kw) {
                kw = kw.trim().toLowerCase();
                prioFiltered = (kw === '' ? prioUsers.slice(0, 20)
                    : prioUsers.filter(function (u) { return u.name.toLowerCase().indexOf(kw) >= 0; }).slice(0, 20));
                prioActiveIdx = prioFiltered.length ? 0 : -1;
                prioRenderCombo();
            }
            function prioSelectUser(u) {
                prioUserHidden.value = u.id;
                prioSearchInp.value = u.name;
                prioCloseCombo();
            }

            if (prioSearchInp) {
                prioSearchInp.addEventListener('input', function () {
                    prioUserHidden.value = '';
                    prioFilterCombo(prioSearchInp.value);
                });
                prioSearchInp.addEventListener('focus', function () { prioFilterCombo(prioSearchInp.value); });
                prioSearchInp.addEventListener('keydown', function (e) {
                    if (e.key === 'ArrowDown') {
                        if (prioComboList.style.display === 'none') { prioFilterCombo(prioSearchInp.value); return; }
                        e.preventDefault();
                        if (prioFiltered.length) { prioActiveIdx = Math.min(prioFiltered.length - 1, prioActiveIdx + 1); prioRenderCombo(); }
                    } else if (e.key === 'ArrowUp') {
                        e.preventDefault();
                        if (prioFiltered.length) { prioActiveIdx = Math.max(0, prioActiveIdx - 1); prioRenderCombo(); }
                    } else if (e.key === 'Enter') {
                        if (prioComboList.style.display !== 'none' && prioActiveIdx >= 0 && prioFiltered[prioActiveIdx]) {
                            e.preventDefault();
                            prioSelectUser(prioFiltered[prioActiveIdx]);
                        }
                    } else if (e.key === 'Tab') {
                        if (prioComboList.style.display !== 'none' && prioActiveIdx >= 0 && prioFiltered[prioActiveIdx]) {
                            prioSelectUser(prioFiltered[prioActiveIdx]); // không preventDefault -> Tab vẫn chuyển focus tiếp
                        }
                    } else if (e.key === 'Escape') {
                        prioCloseCombo();
                    }
                });
                // mousedown (không phải click) để chạy trước khi input bị blur.
                prioComboList.addEventListener('mousedown', function (e) {
                    e.preventDefault();
                    var item = e.target.closest('.app-combo-item');
                    if (!item) return;
                    var idx = +item.dataset.idx;
                    if (prioFiltered[idx]) prioSelectUser(prioFiltered[idx]);
                });
                document.addEventListener('click', function (e) {
                    if (!e.target.closest('#app-set-priority-combo')) prioCloseCombo();
                });
            }

            if (prioAddBtn) prioAddBtn.addEventListener('click', function () {
                var uid = prioUserHidden.value;
                var quota = parseInt(prioQuotaInp.value, 10);
                if (!uid) { setMsgEl(prioMsg, 'Vui lòng chọn người dùng từ danh sách gợi ý.', false); return; }
                if (!quota || quota <= 0) { setMsgEl(prioMsg, 'Vui lòng nhập số MB hợp lệ.', false); return; }
                prioAddBtn.disabled = true;
                var body = new URLSearchParams();
                body.append('user_id', uid);
                body.append('quota_mb', String(quota));
                fetch(setBase + 'storage_priority_save', {
                    method: 'POST', headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                    body: body.toString(), credentials: 'same-origin'
                }).then(function (r) { return r.json(); }).then(function (res) {
                    prioAddBtn.disabled = false;
                    setMsgEl(prioMsg, res && res.success ? 'Đã lưu ưu tiên.' : 'Lưu thất bại.', res && res.success);
                    if (res && res.success) {
                        prioQuotaInp.value = '';
                        prioSearchInp.value = '';
                        prioUserHidden.value = '';
                        loadPriorityList();
                    }
                }).catch(function () { prioAddBtn.disabled = false; setMsgEl(prioMsg, 'Lỗi kết nối.', false); });
            });

            if (prioList) prioList.addEventListener('click', function (e) {
                var btn = e.target.closest('.rm');
                if (!btn) return;
                var item = btn.closest('.app-set-priority-item');
                var uid = item.getAttribute('data-uid');
                btn.disabled = true;
                var body = new URLSearchParams();
                body.append('user_id', uid);
                fetch(setBase + 'storage_priority_delete', {
                    method: 'POST', headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                    body: body.toString(), credentials: 'same-origin'
                }).then(function (r) { return r.json(); }).then(function (res) {
                    if (res && res.success) loadPriorityList();
                    else btn.disabled = false;
                }).catch(function () { btn.disabled = false; });
            });
        }

        /* ---- 9. Rời hệ thống (tự nguyện): cảnh báo -> gửi mã -> xác nhận -> logout ---- */
        var leaveModal = document.getElementById('app-leave-modal');
        if (leaveModal) {
            var AUTH2 = '?mod=auth&controllers=index&action=';
            var lOpen      = document.getElementById('app-open-leave');
            var lWarn      = document.getElementById('app-leave-step-warn');
            var lCodeStep  = document.getElementById('app-leave-step-code');
            var lContinue  = document.getElementById('app-leave-continue');
            var lSend      = document.getElementById('app-leave-send');
            var lCodeWrap  = document.getElementById('app-leave-code-wrap');
            var lResend    = document.getElementById('app-leave-resend');
            var lTimerEl   = document.getElementById('app-leave-timer');
            var lVerify    = document.getElementById('app-leave-verify');
            var lMsg       = document.getElementById('app-leave-msg');
            var lDigits    = Array.prototype.slice.call(leaveModal.querySelectorAll('.app-leave-digit'));
            var lTimer     = null;

            function lSetMsg(t, ok) { lMsg.textContent = t || ''; lMsg.classList.remove('ok', 'err'); if (t) lMsg.classList.add(ok ? 'ok' : 'err'); }
            function lOpenModal() {
                leaveModal.classList.add('is-open');
                lWarn.style.display = ''; lCodeStep.style.display = 'none';
                lCodeWrap.style.display = 'none'; lSend.style.display = '';
                lSetMsg(''); lDigits.forEach(function (d) { d.value = ''; });
            }
            function lClose() { leaveModal.classList.remove('is-open'); if (lTimer) { clearInterval(lTimer); lTimer = null; } }
            function lGetCode() { return lDigits.map(function (d) { return d.value.trim(); }).join(''); }
            function lStartTimer() {
                var left = 60; lResend.disabled = true; lTimerEl.textContent = 'Mã hết hạn sau ' + left + 's';
                if (lTimer) clearInterval(lTimer);
                lTimer = setInterval(function () {
                    left--;
                    if (left <= 0) { clearInterval(lTimer); lTimer = null; lTimerEl.textContent = 'Mã đã hết hạn, hãy gửi lại.'; lResend.disabled = false; }
                    else { lTimerEl.textContent = 'Mã hết hạn sau ' + left + 's'; }
                }, 1000);
            }
            function lSendCode() {
                lSend.disabled = true; lResend.disabled = true; lSetMsg('Đang gửi mã...', true);
                fetch(AUTH2 + 'leave_send_code', { method: 'POST', body: new FormData(), credentials: 'same-origin' })
                .then(function (r) { return r.json(); })
                .then(function (res) {
                    lSend.disabled = false;
                    if (res && res.ok) {
                        lSend.style.display = 'none'; lCodeWrap.style.display = '';
                        lSetMsg(res.message || 'Đã gửi mã.', true);
                        lDigits.forEach(function (d) { d.value = ''; });
                        if (lDigits[0]) lDigits[0].focus();
                        lStartTimer();
                    } else { lSetMsg((res && res.message) || 'Gửi mã thất bại', false); }
                })
                .catch(function () { lSend.disabled = false; lSetMsg('Lỗi kết nối', false); });
            }
            if (lOpen) lOpen.addEventListener('click', function () { if (user) user.classList.remove('is-open'); lOpenModal(); });
            leaveModal.querySelectorAll('[data-leave-close]').forEach(function (el) { el.addEventListener('click', lClose); });
            if (lContinue) lContinue.addEventListener('click', function () { lWarn.style.display = 'none'; lCodeStep.style.display = ''; });
            lSend.addEventListener('click', lSendCode);
            lResend.addEventListener('click', lSendCode);
            lDigits.forEach(function (d, idx) {
                d.addEventListener('input', function () { d.value = d.value.toUpperCase(); if (d.value && idx < lDigits.length - 1) lDigits[idx + 1].focus(); });
                d.addEventListener('keydown', function (ev) { if (ev.key === 'Backspace' && !d.value && idx > 0) lDigits[idx - 1].focus(); });
                d.addEventListener('paste', function (ev) {
                    ev.preventDefault();
                    var t = (ev.clipboardData || window.clipboardData).getData('text').trim().toUpperCase();
                    for (var i = 0; i < lDigits.length; i++) lDigits[i].value = t.charAt(i) || '';
                });
            });
            lVerify.addEventListener('click', function () {
                var code = lGetCode();
                if (code.length < 6) { lSetMsg('Vui lòng nhập đủ 6 ký tự.', false); return; }
                lVerify.disabled = true;
                var fd = new FormData(); fd.append('activation_code', code);
                fetch(AUTH2 + 'leave_confirm', { method: 'POST', body: fd, credentials: 'same-origin' })
                .then(function (r) { return r.json(); })
                .then(function (res) {
                    lVerify.disabled = false;
                    if (res && res.ok) {
                        if (lTimer) { clearInterval(lTimer); lTimer = null; }
                        window.__leftSystem = true;
                        lSetMsg('Đã rời hệ thống. Đang đăng xuất...', true);
                        setTimeout(function () { window.location.href = res.redirect || (AUTH2 + 'logout'); }, 1200);
                    } else { lSetMsg((res && res.message) || 'Mã không đúng', false); }
                })
                .catch(function () { lVerify.disabled = false; lSetMsg('Lỗi kết nối', false); });
            });
        }

        /* ---- 10. Poll trạng thái tài khoản: bị buộc rời -> modal + tự logout sau 3s ---- */
        var forcedModal = document.getElementById('app-forced-leave-modal');
        if (forcedModal) {
            var AUTH3 = '?mod=auth&controllers=index&action=';
            var forcedTriggered = false;
            var checkAccountState = function () {
                if (forcedTriggered || window.__leftSystem) return;
                fetch(AUTH3 + 'account_state', { credentials: 'same-origin' })
                .then(function (r) { return r.json(); })
                .then(function (res) {
                    if (res && res.ok && res.active === false && !forcedTriggered && !window.__leftSystem) {
                        forcedTriggered = true;
                        var txt = document.getElementById('app-forced-text');
                        if (txt) {
                            txt.textContent = (res.reason === 'inactive')
                                ? 'Tài khoản của bạn vừa bị ngưng hoạt động (đóng băng). Vui lòng liên hệ quản trị viên để kích hoạt lại.'
                                : 'Bạn vừa bị buộc rời hệ thống.';
                        }
                        forcedModal.classList.add('is-open');
                        var n = 3; var cEl = document.getElementById('app-forced-count');
                        var iv = setInterval(function () {
                            n--; if (cEl) cEl.textContent = n;
                            if (n <= 0) { clearInterval(iv); window.location.href = AUTH3 + 'logout'; }
                        }, 1000);
                    }
                })
                .catch(function () {});
            };
            setInterval(checkAccountState, 6000);
        }

        /* ---- 11. Phân quyền real-time: đổi quyền -> render lại menu + thông báo nổi;
                   mất quyền view đang xem -> đẩy về trang chủ. ---- */
        (function () {
            var nav = document.querySelector('.app-sb-nav');
            if (!nav) return;
            var HOME = '?mod=home&controllers=index&action=index';
            var PSTATE = '?mod=home&controllers=index&action=permissionState';

            // mod/ctl/action của trang hiện tại (đọc từ URL).
            function qp(k) {
                var m = new RegExp('[?&]' + k + '=([^&]*)').exec(window.location.search);
                return m ? decodeURIComponent(m[1].replace(/\+/g, ' ')) : '';
            }
            var curMod = qp('mod'), curCtl = qp('controllers'), curAct = qp('action');

            // Toast nổi (không cần xác nhận).
            function permToast(msg, kind) {
                var t = document.createElement('div');
                t.className = 'app-perm-toast' + (kind ? ' is-' + kind : '');
                t.innerHTML = '<i class="fa-solid ' +
                    (kind === 'warn' ? 'fa-triangle-exclamation' : 'fa-shield-halved') +
                    '"></i><span></span>';
                t.querySelector('span').textContent = msg;
                document.body.appendChild(t);
                requestAnimationFrame(function () { t.classList.add('show'); });
                setTimeout(function () {
                    t.classList.remove('show');
                    setTimeout(function () { if (t.parentNode) t.parentNode.removeChild(t); }, 350);
                }, kind === 'warn' ? 4000 : 3200);
                return t;
            }

            function esc2(s) {
                return String(s == null ? '' : s).replace(/&/g, '&amp;').replace(/</g, '&lt;')
                    .replace(/>/g, '&gt;').replace(/"/g, '&quot;');
            }
            // Viết hoa chữ cái đầu, còn lại thường (đồng bộ app_sentence_case).
            var SENTENCE_EXACT = { 'BIỂU MẪU QL VSATTP': 'Biểu mẫu QL VSATTP' };
            function sentence(s) {
                s = String(s || '').trim();
                if (!s) return s;
                if (SENTENCE_EXACT[s]) return SENTENCE_EXACT[s];
                return s.charAt(0).toUpperCase() + s.slice(1).toLowerCase();
            }

            // Render lại toàn bộ menu sidebar từ dữ liệu nhóm trả về.
            function renderMenu(groups) {
                var html = '';
                groups.forEach(function (g) {
                    var groupActive = g.items.some(function (it) {
                        return it.module === curMod && it.action === curAct;
                    });
                    html += '<div class="app-sb-group' + (groupActive ? ' is-open is-active' : '') + '">'
                        + '<button type="button" class="app-sb-parent" aria-expanded="' + (groupActive ? 'true' : 'false') + '">'
                        + '<span class="app-sb-ic"><i class="fa-solid ' + esc2(g.icon) + '"></i></span>'
                        + '<span class="app-sb-txt">' + esc2(sentence(g.display)) + '</span>'
                        + '<span class="app-sb-caret"><i class="fa-solid fa-chevron-down"></i></span>'
                        + '</button><ul class="app-sb-children">';
                    g.items.forEach(function (it) {
                        var active = (it.module === curMod && it.action === curAct);
                        html += '<li class="app-sb-child' + (active ? ' is-active' : '') + '">'
                            + '<a href="' + esc2(it.url) + '" title="' + esc2(it.label) + '">'
                            + '<span class="app-sb-dot"><i class="fa-solid fa-circle"></i></span>'
                            + '<span class="app-sb-txt">' + esc2(it.label) + '</span></a></li>';
                    });
                    html += '</ul></div>';
                });
                nav.innerHTML = html;
                nav.querySelectorAll('.app-sb-parent').forEach(bindSbParent);
            }

            var lastSig = null, redirecting = false;
            function pollPerm() {
                if (redirecting) return;
                fetch(PSTATE + '&cur_mod=' + encodeURIComponent(curMod)
                        + '&cur_ctl=' + encodeURIComponent(curCtl)
                        + '&cur_act=' + encodeURIComponent(curAct),
                    { credentials: 'same-origin' })
                .then(function (r) { return r.json(); })
                .then(function (res) {
                    if (!res || !res.ok) return;
                    // Mất quyền view đang xem -> báo + đẩy về trang chủ.
                    if (res.current_allowed === false && !redirecting) {
                        redirecting = true;
                        permToast('Bạn vừa bị thu hồi quyền truy cập chức năng này. Đang chuyển về trang chủ…', 'warn');
                        setTimeout(function () { window.location.href = HOME; }, 2200);
                        return;
                    }
                    // Lần đầu: ghi nhận chữ ký, không báo.
                    if (lastSig === null) { lastSig = res.sig; return; }
                    // Quyền đổi -> render lại menu + thông báo nổi.
                    if (res.sig !== lastSig) {
                        lastSig = res.sig;
                        if (!res.is_admin) {
                            renderMenu(res.groups || []);
                            permToast('Quyền truy cập của bạn vừa được cập nhật.');
                        }
                    }
                })
                .catch(function () {});
            }
            setInterval(pollPerm, 5000);
            setTimeout(pollPerm, 2000);
        })();

        /* ---- 4f. Điểm nhắc: 3 loại nhắc độc lập (nhập SX / KHSX / bốc hàng) ---- */
        (function () {
            var remind = document.getElementById('app-remind');
            var remindBtn = document.getElementById('app-remind-btn');
            if (!remind || !remindBtn) return;

            var ACT = '?mod=reminder_points&controllers=reminder_points&action=';
            var listCache = {}; // listKey -> items[] (chỉ để tránh render lỗi khi rỗng)

            function esc(s) {
                return String(s == null ? '' : s)
                    .replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;')
                    .replace(/"/g, '&quot;').replace(/'/g, '&#39;');
            }
            function postForm(action, payload) {
                var body = new URLSearchParams();
                Object.keys(payload || {}).forEach(function (k) { body.append(k, payload[k]); });
                return fetch(ACT + action, { method: 'POST', body: body, credentials: 'same-origin' })
                    .then(function (r) { return r.json(); });
            }
            function getJson(action, params) {
                var qs = Object.keys(params || {}).map(function (k) {
                    return encodeURIComponent(k) + '=' + encodeURIComponent(params[k]);
                }).join('&');
                return fetch(ACT + action + (qs ? '&' + qs : ''), { credentials: 'same-origin' })
                    .then(function (r) { return r.json(); });
            }

            /* ---- Mở/đóng dropdown (giống chuông/lịch) ---- */
            remindBtn.addEventListener('click', function (e) {
                e.stopPropagation();
                var open = remind.classList.toggle('is-open');
                if (open) loadTabData(activeTabKey());
            });
            document.addEventListener('click', function (e) {
                if (!remind.contains(e.target)) remind.classList.remove('is-open');
            });

            /* ---- Chuyển tab ---- */
            var tabs = remind.querySelectorAll('.app-remind-tab');
            var panes = remind.querySelectorAll('.app-remind-pane');
            function activeTabKey() {
                var t = remind.querySelector('.app-remind-tab.is-active');
                return t ? t.getAttribute('data-tab') : 'pre_input';
            }
            tabs.forEach(function (tabBtn) {
                tabBtn.addEventListener('click', function () {
                    tabs.forEach(function (t) { t.classList.remove('is-active'); });
                    tabBtn.classList.add('is-active');
                    var key = tabBtn.getAttribute('data-tab');
                    panes.forEach(function (p) { p.classList.toggle('is-active', p.getAttribute('data-pane') === key); });
                    loadTabData(key);
                });
            });

            /* ---- Sub-mode trong tab "Bốc hàng": theo chi nhánh / theo sản phẩm ---- */
            var pickupPane = remind.querySelector('.app-remind-pane[data-pane="pickup"]');
            var subBtns = pickupPane.querySelectorAll('.app-remind-submode-btn');
            subBtns.forEach(function (btn) {
                btn.addEventListener('click', function () {
                    subBtns.forEach(function (b) { b.classList.remove('is-active'); });
                    btn.classList.add('is-active');
                    var mode = btn.getAttribute('data-mode');
                    pickupPane.querySelectorAll('.app-remind-add[data-mode-pane]').forEach(function (el) {
                        el.style.display = (el.getAttribute('data-mode-pane') === mode) ? '' : 'none';
                    });
                    var lb = pickupPane.querySelector('.app-remind-list[data-list="pickup_branch"]');
                    var lp = pickupPane.querySelector('.app-remind-list[data-list="pickup_product"]');
                    if (lb) lb.style.display = (mode === 'branch') ? '' : 'none';
                    if (lp) lp.style.display = (mode === 'product') ? '' : 'none';
                });
            });

            function loadTabData(key) {
                if (key === 'pre_input') { loadList('pre_input'); return; }
                if (key === 'pre_plan') { loadList('pre_plan'); return; }
                if (key === 'pickup') { loadList('pickup_branch'); loadList('pickup_product'); }
            }

            /* ---- Danh sách: loại 1 (đơn giản, 1 dòng/sản phẩm, upsert) ---- */
            var SIMPLE_CFG = {
                pre_input: { list: 'rp_pre_input_list', save: 'rp_pre_input_save', del: 'rp_pre_input_delete' }
            };
            /* ---- Danh sách: loại 2 & 3 (theo id dòng — 1 sản phẩm/chi nhánh có thể có NHIỀU dòng,
               sửa/xóa riêng từng dòng theo id; loại 3 còn có thể là "TẤT CẢ") ---- */
            var ROWID_CFG = {
                pre_plan: { list: 'rp_pre_plan_list', update: 'rp_pre_plan_update', del: 'rp_pre_plan_delete', idField: 'product_id', nameField: 'product_name' },
                pickup_branch: { list: 'rp_pickup_branch_list', update: 'rp_pickup_branch_update', del: 'rp_pickup_branch_delete', idField: 'customer_id', nameField: 'customer_name', shortField: 'customer_short_name', colorField: 'customer_secondary_color' },
                pickup_product: { list: 'rp_pickup_product_list', update: 'rp_pickup_product_update', del: 'rp_pickup_product_delete', idField: 'product_id', nameField: 'product_name' }
            };

            function emptyHtml() { return '<div class="app-remind-empty">Chưa thiết lập nhắc nào.</div>'; }

            function bindEditableList(container, onSave, onDelete) {
                container.addEventListener('focus', function (e) {
                    if (e.target.classList && e.target.classList.contains('app-remind-item-text')) {
                        e.target.dataset.orig = e.target.textContent;
                    }
                }, true);
                container.addEventListener('keydown', function (e) {
                    if (!e.target.classList || !e.target.classList.contains('app-remind-item-text')) return;
                    if (e.key === 'Enter') { e.preventDefault(); e.target.blur(); }
                    else if (e.key === 'Escape') { e.target.textContent = e.target.dataset.orig || ''; e.target.blur(); }
                });
                container.addEventListener('blur', function (e) {
                    if (!e.target.classList || !e.target.classList.contains('app-remind-item-text')) return;
                    var row = e.target.closest('.app-remind-item');
                    var note = e.target.textContent.trim();
                    onSave(row, note);
                }, true);
                container.addEventListener('click', function (e) {
                    var btn = e.target.closest('.app-remind-item-del');
                    if (!btn) return;
                    if (!confirm('Xóa lời nhắc này?')) return;
                    onDelete(btn.closest('.app-remind-item'));
                });
            }

            function loadList(listKey) {
                var container = remind.querySelector('.app-remind-list[data-list="' + listKey + '"]');
                if (!container) return;
                var isSimple = !!SIMPLE_CFG[listKey];
                var action = isSimple ? SIMPLE_CFG[listKey].list : ROWID_CFG[listKey].list;
                getJson(action, {}).then(function (res) {
                    var items = (res && res.success) ? (res.items || []) : [];
                    listCache[listKey] = items;
                    if (!items.length) { container.innerHTML = emptyHtml(); return; }
                    if (isSimple) {
                        container.innerHTML = items.map(function (it) {
                            return '<div class="app-remind-item" data-product-id="' + it.product_id + '">'
                                + '<div class="app-remind-item-name">' + esc(it.product_name || ('#' + it.product_id)) + '</div>'
                                + '<div class="app-remind-item-text" contenteditable="true">' + esc(it.note || '') + '</div>'
                                + '<button type="button" class="app-remind-item-del" title="Xóa">×</button>'
                                + '</div>';
                        }).join('');
                    } else {
                        var cfg = ROWID_CFG[listKey];
                        container.innerHTML = items.map(function (it) {
                            var idVal = it[cfg.idField];
                            var isAll = (idVal === null || idVal === undefined || idVal === '');
                            // Có short_name (vd customer "PLHCM") -> hiện tên viết tắt, tô ĐÚNG màu thứ cấp
                            // riêng của chi nhánh đó (customers.secondary_color), tên đầy đủ xem qua title
                            // hover. Không có (vd sản phẩm) -> hiện tên đầy đủ như cũ, không tô màu.
                            var fullName = it[cfg.nameField] || ('#' + idVal);
                            var shortName = cfg.shortField ? (it[cfg.shortField] || '') : '';
                            var color = cfg.colorField ? (it[cfg.colorField] || '') : '';
                            var label = isAll ? 'TẤT CẢ' : esc(shortName || fullName);
                            var nameCls = 'app-remind-item-name' + (!isAll && shortName ? ' is-abbrev' : '');
                            var styleAttr = (!isAll && shortName && color) ? ' style="--rp-accent: ' + esc(color) + ';"' : '';
                            var titleAttr = (!isAll && shortName) ? ' title="' + esc(fullName) + '"' : '';
                            return '<div class="app-remind-item" data-id="' + it.id + '">'
                                + '<div class="' + nameCls + '"' + styleAttr + titleAttr + '>' + label + '</div>'
                                + '<div class="app-remind-item-text" contenteditable="true">' + esc(it.note || '') + '</div>'
                                + '<button type="button" class="app-remind-item-del" title="Xóa">×</button>'
                                + '</div>';
                        }).join('');
                    }
                }).catch(function () { container.innerHTML = '<div class="app-remind-empty">Không tải được.</div>'; });
            }

            Object.keys(SIMPLE_CFG).forEach(function (listKey) {
                var container = remind.querySelector('.app-remind-list[data-list="' + listKey + '"]');
                if (!container) return;
                var cfg = SIMPLE_CFG[listKey];
                bindEditableList(container, function (row, note) {
                    var pid = row.getAttribute('data-product-id');
                    postForm(cfg.save, { product_id: pid, note: note }).then(function () {
                        if (note === '') { row.remove(); if (!container.children.length) container.innerHTML = emptyHtml(); }
                    });
                }, function (row) {
                    var pid = row.getAttribute('data-product-id');
                    postForm(cfg.del, { product_id: pid }).then(function () {
                        row.remove();
                        if (!container.children.length) container.innerHTML = emptyHtml();
                    });
                });
            });
            Object.keys(ROWID_CFG).forEach(function (listKey) {
                var container = remind.querySelector('.app-remind-list[data-list="' + listKey + '"]');
                if (!container) return;
                var cfg = ROWID_CFG[listKey];
                bindEditableList(container, function (row, note) {
                    var id = row.getAttribute('data-id');
                    postForm(cfg.update, { id: id, note: note }).then(function () {
                        if (note === '') { row.remove(); if (!container.children.length) container.innerHTML = emptyHtml(); }
                    });
                }, function (row) {
                    var id = row.getAttribute('data-id');
                    postForm(cfg.del, { id: id }).then(function () {
                        row.remove();
                        if (!container.children.length) container.innerHTML = emptyHtml();
                    });
                });
            });

            /* ---- Ô tìm-chọn (product/customer), dùng chung cho cả 3 tab ---- */
            /* Điều khiển bàn phím mặc định cho input xổ dropdown gợi ý (xem [[dropdown-keyboard-nav-default]]):
               mũi tên lên/xuống để duyệt, Enter hoặc Tab để chọn dòng đang tô sáng, Escape để đóng. */
            function wirePicker(pickerEl, searchAction, onPick) {
                var input = pickerEl.querySelector('.app-remind-search');
                var suggest = pickerEl.querySelector('.app-remind-suggest');
                var timer = null;
                var activeIdx = -1;

                function items() { return suggest.querySelectorAll('.app-remind-suggest-item'); }
                function highlight(idx) {
                    var els = items();
                    els.forEach(function (el) { el.classList.remove('is-active'); });
                    if (idx >= 0 && els[idx]) {
                        els[idx].classList.add('is-active');
                        els[idx].scrollIntoView({ block: 'nearest' });
                    }
                }
                function closeSuggest() {
                    suggest.innerHTML = ''; suggest.classList.remove('is-open'); activeIdx = -1;
                }
                function pickItem(el) {
                    if (!el) return;
                    var sel = { id: el.getAttribute('data-id'), name: el.getAttribute('data-name'), short: el.getAttribute('data-short') || '', color: el.getAttribute('data-color') || '' };
                    onPick(sel);
                    closeSuggest();
                }

                input.addEventListener('input', function () {
                    pickerEl.removeAttribute('data-selected-id');
                    activeIdx = -1;
                    var kw = input.value.trim();
                    clearTimeout(timer);
                    if (!kw) { suggest.innerHTML = ''; suggest.classList.remove('is-open'); return; }
                    timer = setTimeout(function () {
                        getJson(searchAction, { keyword: kw }).then(function (res) {
                            var list = (res && res.items) || [];
                            suggest.innerHTML = list.length ? list.map(function (it) {
                                var name = it.product_name || it.name || '';
                                var short = it.short_name || '';
                                var color = it.secondary_color || '';
                                return '<div class="app-remind-suggest-item" data-id="' + it.id + '" data-name="' + esc(name) + '" data-short="' + esc(short) + '" data-color="' + esc(color) + '">' + esc(name) + '</div>';
                            }).join('') : '<div class="app-remind-suggest-empty">Không có kết quả</div>';
                            suggest.classList.add('is-open');
                            activeIdx = -1;
                        });
                    }, 250);
                });
                input.addEventListener('keydown', function (e) {
                    if (!suggest.classList.contains('is-open')) return;
                    var els = items();
                    if (!els.length) return;
                    if (e.key === 'ArrowDown') {
                        e.preventDefault();
                        activeIdx = (activeIdx + 1) % els.length;
                        highlight(activeIdx);
                    } else if (e.key === 'ArrowUp') {
                        e.preventDefault();
                        activeIdx = (activeIdx - 1 + els.length) % els.length;
                        highlight(activeIdx);
                    } else if (e.key === 'Enter') {
                        if (activeIdx >= 0) { e.preventDefault(); pickItem(els[activeIdx]); }
                    } else if (e.key === 'Tab') {
                        // Không preventDefault: chọn dòng đang tô sáng RỒI vẫn cho Tab chuyển focus tiếp.
                        if (activeIdx >= 0) pickItem(els[activeIdx]);
                    } else if (e.key === 'Escape') {
                        closeSuggest();
                    }
                });
                suggest.addEventListener('click', function (e) {
                    var item = e.target.closest('.app-remind-suggest-item');
                    if (!item) return;
                    e.stopPropagation(); // tránh bị listener "click ra ngoài" đóng nhầm cả dropdown
                    pickItem(item);
                });
                document.addEventListener('click', function (e) {
                    if (!pickerEl.contains(e.target)) closeSuggest();
                });
            }

            /* ---- Tab 1 & 2: form thêm nhắc theo 1 sản phẩm ---- */
            function wireSingleProductForm(paneKey, saveAction) {
                var pane = remind.querySelector('.app-remind-pane[data-pane="' + paneKey + '"]');
                if (!pane) return;
                var picker = pane.querySelector('.app-remind-picker');
                var textEl = pane.querySelector('.app-remind-text');
                var saveBtn = pane.querySelector('.app-remind-save-btn');
                wirePicker(picker, 'rp_search_product', function (sel) {
                    picker.setAttribute('data-selected-id', sel.id);
                    picker.querySelector('.app-remind-search').value = sel.name;
                });
                saveBtn.addEventListener('click', function () {
                    var pid = picker.getAttribute('data-selected-id');
                    var note = textEl.value.trim();
                    if (!pid) { alert('Vui lòng chọn sản phẩm.'); return; }
                    if (!note) { alert('Vui lòng nhập nội dung nhắc.'); return; }
                    postForm(saveAction, { product_id: pid, note: note }).then(function (res) {
                        if (res && res.success) {
                            textEl.value = '';
                            picker.querySelector('.app-remind-search').value = '';
                            picker.removeAttribute('data-selected-id');
                            loadList(paneKey);
                        } else if (res) { alert(res.message || 'Lưu thất bại.'); }
                    });
                });
            }
            wireSingleProductForm('pre_input', 'rp_pre_input_save');
            wireSingleProductForm('pre_plan', 'rp_pre_plan_add');

            /* ---- Tab 3: form thêm nhắc theo chi nhánh/sản phẩm (chọn nhiều hoặc TẤT CẢ) ---- */
            function wirePickupForm(addEl, opts) {
                var chosen = [];
                var allCheck = addEl.querySelector('.app-remind-all-check');
                var picker = addEl.querySelector('.app-remind-picker');
                var chosenBox = addEl.querySelector('.app-remind-chosen');
                var textEl = addEl.querySelector('.app-remind-text');
                var saveBtn = addEl.querySelector('.app-remind-save-btn');

                // Chip hiện tên viết tắt (short_name, vd "PLHCM") khi có — tô ĐÚNG màu thứ cấp riêng
                // của chi nhánh đó (customers.secondary_color, giống màu dùng ở order_management
                // .om-card-cust.is-short/--om-accent), không dùng 1 màu cố định chung cho mọi chi nhánh.
                // Không có short_name (vd sản phẩm) thì hiện tên đầy đủ như cũ, không tô màu.
                function renderChosen() {
                    chosenBox.innerHTML = chosen.map(function (c) {
                        var isAbbrev = !!c.short;
                        var label = isAbbrev ? c.short : c.name;
                        var style = (isAbbrev && c.color) ? ' style="--rp-accent: ' + esc(c.color) + ';"' : '';
                        return '<span class="app-remind-chip' + (isAbbrev ? ' is-abbrev' : '') + '"' + style + ' data-id="' + c.id + '" title="' + esc(c.name) + '">' + esc(label) + '<button type="button" class="app-remind-chip-del">×</button></span>';
                    }).join('');
                }
                chosenBox.addEventListener('click', function (e) {
                    var btn = e.target.closest('.app-remind-chip-del');
                    if (!btn) return;
                    e.stopPropagation();
                    var id = btn.closest('.app-remind-chip').getAttribute('data-id');
                    chosen = chosen.filter(function (c) { return String(c.id) !== String(id); });
                    renderChosen();
                });
                allCheck.addEventListener('change', function () {
                    var on = allCheck.checked;
                    picker.style.display = on ? 'none' : '';
                    chosenBox.style.display = on ? 'none' : '';
                });
                wirePicker(picker, opts.searchAction, function (sel) {
                    if (!chosen.some(function (c) { return String(c.id) === String(sel.id); })) {
                        chosen.push(sel);
                        renderChosen();
                    }
                    picker.querySelector('.app-remind-search').value = '';
                });
                saveBtn.addEventListener('click', function () {
                    var note = textEl.value.trim();
                    if (!note) { alert('Vui lòng nhập nội dung nhắc.'); return; }
                    var applyAll = allCheck.checked;
                    if (!applyAll && !chosen.length) { alert('Vui lòng chọn ít nhất 1 mục hoặc chọn Áp dụng TẤT CẢ.'); return; }
                    var payload = { note: note, apply_all: applyAll ? '1' : '0' };
                    payload[opts.idParam] = JSON.stringify(chosen.map(function (c) { return c.id; }));
                    postForm(opts.saveAction, payload).then(function (res) {
                        if (res && res.success) {
                            textEl.value = ''; chosen = []; renderChosen();
                            allCheck.checked = false;
                            picker.style.display = ''; chosenBox.style.display = '';
                            loadList(opts.listKey);
                        } else if (res) { alert(res.message || 'Lưu thất bại.'); }
                    });
                });
            }
            wirePickupForm(pickupPane.querySelector('.app-remind-add[data-mode-pane="branch"]'), {
                searchAction: 'rp_search_customer', saveAction: 'rp_pickup_branch_save', idParam: 'customer_ids', listKey: 'pickup_branch'
            });
            wirePickupForm(pickupPane.querySelector('.app-remind-add[data-mode-pane="product"]'), {
                searchAction: 'rp_search_product', saveAction: 'rp_pickup_product_save', idParam: 'product_ids', listKey: 'pickup_product'
            });
        })();
    });
})();

/* =====================================================================
 *  PWA — R2-11: nạp manifest + meta iOS + đăng ký service worker.
 *  Chạy trên mọi trang có app_shell.js. Base URL của app suy ra từ chính
 *  src của app_shell.js (đáng tin cậy cả khi dùng URL gọn /{mod}/{action}
 *  lẫn URL ?mod=..., và cả khi app nằm trong thư mục con).
 * ===================================================================== */
(function () {
    if (!('serviceWorker' in navigator)) return;

    var base = '';
    var scripts = document.getElementsByTagName('script');
    for (var i = 0; i < scripts.length; i++) {
        var s = scripts[i].src || '';
        var idx = s.indexOf('public/js/shared/app_shell.js');
        if (idx !== -1) { base = s.slice(0, idx); break; }   // -> ".../nvsxvat.vn/"
    }
    if (!base) { base = location.origin + location.pathname.replace(/[^/]*$/, ''); }

    var head = document.head || document.getElementsByTagName('head')[0];
    function addMeta(name, content) {
        if (document.querySelector('meta[name="' + name + '"]')) return;
        var m = document.createElement('meta'); m.name = name; m.content = content; head.appendChild(m);
    }
    function addLink(rel, href) {
        if (document.querySelector('link[rel="' + rel + '"]')) return;
        var l = document.createElement('link'); l.rel = rel; l.href = href; head.appendChild(l);
    }

    addLink('manifest', base + 'manifest.webmanifest');
    addMeta('theme-color', '#16a34a');
    addMeta('mobile-web-app-capable', 'yes');
    addMeta('apple-mobile-web-app-capable', 'yes');
    addMeta('apple-mobile-web-app-status-bar-style', 'black-translucent');
    addMeta('apple-mobile-web-app-title', 'Vua An Toàn');
    addLink('apple-touch-icon', base + 'public/images/logo/logo_vat_png.png');

    window.addEventListener('load', function () {
        navigator.serviceWorker.register(base + 'sw.js', { scope: base || './' }).catch(function () {});
    });
})();
