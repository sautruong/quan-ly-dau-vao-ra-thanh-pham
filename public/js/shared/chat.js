/* =====================================================================
   CHAT WIDGET — logic phía client (Messenger góc phải dưới).
   Yêu cầu: #chat-widget (xem layout/chat-widget.php).
   Endpoint: ?mod=chat&controllers=chat&action=<...>
   Tính năng: chat 1-1/nhóm, ảnh/tệp, tìm kiếm, thu hồi, cảm xúc, đã xem,
   thêm/đuổi/rời thành viên, tắt thông báo, xóa hội thoại, nhắc hẹn,
   đổi tên/ảnh nhóm, emoji, tự bung khi có tin mới.
   ===================================================================== */
(function () {
    'use strict';

    var API = '?mod=chat&controllers=chat&action=';
    var REACTIONS = ['👍', '❤️', '😂', '😮', '😢']; // like, tim, cười, bất ngờ, khóc
    var EMOJIS = ('😀 😁 😂 🤣 😊 😍 😘 😎 🤩 🥳 😴 😅 😇 🙂 🙃 😉 😋 😜 🤪 🤔 '
        + '🤗 🤭 😐 😑 😶 🙄 😏 😣 😥 😮 😯 😪 😫 😴 😌 😛 🤤 😒 😓 😔 '
        + '👍 👎 👌 ✌️ 🤞 🤙 👏 🙌 🙏 💪 🔥 ✨ 🎉 🎊 ❤️ 🧡 💛 💚 💙 💜 '
        + '💔 💕 💯 ✅ ❌ ⭐ 🌟 ☀️ 🌈 🍀 🌹 🎁 ☕ 🍺 🍕 🍰 ⚽ 🚀 📌 ⏰').split(' ');

    document.addEventListener('DOMContentLoaded', function () {
        var widget = document.getElementById('chat-widget');
        if (!widget) return;

        var ME = parseInt(widget.getAttribute('data-me-id'), 10) || 0;

        /* --- refs --- */
        var launcher   = document.getElementById('chat-launcher');
        var badge      = document.getElementById('chat-launcher-badge');
        var panel      = document.getElementById('chat-panel');
        var homeView   = document.getElementById('chat-home');
        var roomView   = document.getElementById('chat-room');
        var groupView  = document.getElementById('chat-group-create');

        var recentList   = document.getElementById('chat-recent-list');
        var contactsList = document.getElementById('chat-contacts-list');
        var homeSearch   = document.getElementById('chat-home-search');

        var msgsBox    = document.getElementById('chat-messages');
        var roomName   = document.getElementById('chat-room-name');
        var roomNameEdit = document.getElementById('chat-room-name-edit');
        var roomSub    = document.getElementById('chat-room-sub');
        var roomAvatar = document.getElementById('chat-room-avatar');
        var roomAvatarInput = document.getElementById('chat-room-avatar-input');
        var inputText  = document.getElementById('chat-input-text');
        var sendBtn    = document.getElementById('chat-send-btn');
        var inputImage = document.getElementById('chat-input-image');
        var inputFile  = document.getElementById('chat-input-file');
        var attachPrev = document.getElementById('chat-attach-preview');

        var roomSearchWrap  = document.getElementById('chat-room-search');
        var roomSearchInput = document.getElementById('chat-room-search-input');

        var overlay      = document.getElementById('chat-room-overlay');
        var overlayTitle = document.getElementById('chat-overlay-title');
        var overlayBody  = document.getElementById('chat-overlay-body');
        var overlayFoot  = document.getElementById('chat-overlay-foot');
        var overlaySubmit= document.getElementById('chat-overlay-submit');

        var emojiBtn = document.getElementById('chat-emoji-btn');
        var emojiPop = document.getElementById('chat-emoji-pop');

        var formatBtn = document.getElementById('chat-format-btn');
        var formatPop = document.getElementById('chat-format-pop');

        var ctxMenu  = document.getElementById('chat-ctx-menu');
        var toastWrap= document.getElementById('chat-toast-wrap');

        var reminderModal = document.getElementById('chat-reminder-modal');
        var reminderNote  = document.getElementById('chat-reminder-note');
        var reminderWhen  = document.getElementById('chat-reminder-when');
        var reminderSubmit= document.getElementById('chat-reminder-submit');

        var replyBar      = document.getElementById('chat-reply-bar');
        var replyBarName  = document.getElementById('chat-reply-bar-name');
        var replyBarText  = document.getElementById('chat-reply-bar-text');

        var settingsBtn   = document.getElementById('chat-settings');
        var settingsModal = document.getElementById('chat-settings-modal');
        var setHideBubble = document.getElementById('set-hide-bubble');
        var setShowOnline = document.getElementById('set-show-online');
        var setNotifySys  = document.getElementById('set-notify-system');
        var setNotifyToast= document.getElementById('set-notify-toast');
        var setMuteCurrent= document.getElementById('set-mute-current');
        var setMuteOptions= document.getElementById('set-mute-options');

        var leaveModal    = document.getElementById('chat-leave-modal');
        var leaveAdminWrap= document.getElementById('chat-leave-admin');
        var leaveSuccessor= document.getElementById('chat-leave-successor');
        var leaveSilent   = document.getElementById('chat-leave-silent');
        var leaveSubmit   = document.getElementById('chat-leave-submit');

        var pasteModal    = document.getElementById('chat-paste-modal');
        var pasteAsText   = document.getElementById('chat-paste-as-text');
        var pasteAsImage  = document.getElementById('chat-paste-as-image');

        var shareModal    = document.getElementById('chat-share-modal');
        var shareList     = document.getElementById('chat-share-list');
        var shareSearch   = document.getElementById('chat-share-search');
        var shareCount    = document.getElementById('chat-share-count');
        var shareSubmit   = document.getElementById('chat-share-submit');
        // Đưa modal chia sẻ ra body để thoát stacking-context của #chat-widget (z-index 99990),
        // nhờ đó nó (z-index 100003) nổi trên cửa sổ xem ảnh (z-index 100000).
        if (shareModal && shareModal.parentNode !== document.body) document.body.appendChild(shareModal);

        /* --- state --- */
        var current = null;
        var oldestId = 0, newestId = 0;
        var hasMore = false, loadingMore = false;
        var pendingFiles = [];
        var pollTimer = null, globalTimer = null;
        var contactsCache = [], convCache = {};
        var meBrief = null;            // thông tin bản thân (lấy từ action contacts) — cho highlight @mình
        var lastReaders = [];
        var lastIncomingId = -1;       // -1 = chưa khởi tạo (lần poll đầu chỉ lấy mốc)
        var reminderMid = 0;
        var replyTo = null;            // {id, sender_name, preview} — tin đang được trả lời
        var overlayMode = '';          // 'members' | 'addmember'
        var inputFocused = false;      // chỉ tính "đã xem" khi focus ô nhập
        var notif = { mute_all: false, in_system: true, toast: true }; // trạng thái thông báo (poll cập nhật)
        var leaveConvId = 0;           // hội thoại đang ở luồng "rời nhóm"
        // Tài khoản hệ thống ("Safe King"): {id, name, can_chat, topics[], is_admin}.
        // Nạp kèm action contacts/conversations — dùng để ghim danh bạ + hiện khối cài đặt admin.
        var botState = null;

        // "Ẩn bóng chat": gắn bóng chat lên cạnh chuông (.app-header-right). Lưu cục bộ theo user.
        var headerRight = document.querySelector('.app-header-right');
        var dockBtn = null, dockBadge = null; // nút chat + badge trên header (tạo khi cần)
        var lastUnread = 0;            // số tin chưa đọc gần nhất (đồng bộ 2 badge)
        var HIDE_KEY = 'chat_hide_bubble_' + ME;
        var hideBubble = false;
        try { hideBubble = localStorage.getItem(HIDE_KEY) === '1'; } catch (e) {}
        // Trang tự yêu cầu ẩn bóng chat mặc định (vd daily_dashboard) — chỉ đổi giá trị khởi tạo
        // trong bộ nhớ, KHÔNG ghi localStorage, nên user bấm mở lại vẫn hoạt động bình thường
        // trong phiên này; lần tới quay lại trang đó sẽ lại tự ẩn.
        if (window.DD2_FORCE_CHAT_DOCK) hideBubble = true;

        /* ============ tiện ích ============ */
        function esc(s) {
            return String(s == null ? '' : s)
                .replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;')
                .replace(/"/g, '&quot;').replace(/'/g, '&#39;');
        }
        function initial(name) { name = String(name || '').trim(); return name ? name.charAt(0).toUpperCase() : '?'; }
        function avatarHtml(av, name, cls) {
            cls = cls || '';
            if (av) return '<span class="chat-avatar ' + cls + '"><img src="' + esc(av) + '" alt=""></span>';
            return '<span class="chat-avatar ' + cls + '">' + esc(initial(name)) + '</span>';
        }
        // Avatar kèm chấm xanh "đang online" ở góc dưới-phải (kiểu Messenger).
        function presenceAvatarHtml(av, name, online) {
            return '<span class="chat-avatar-wrap">' + avatarHtml(av, name)
                + (online ? '<span class="chat-online-dot" title="Đang hoạt động"></span>' : '') + '</span>';
        }
        function groupAvatarHtml(av, name, cls) {
            cls = (cls || '') + ' group';
            if (av) return '<span class="chat-avatar ' + cls + '"><img src="' + esc(av) + '" alt=""></span>';
            return '<span class="chat-avatar ' + cls + '"><i class="fa-solid fa-user-group"></i></span>';
        }
        // Avatar tài khoản hệ thống (khiên) — phân biệt ngay với người thật.
        function botAvatarHtml(cls) {
            return '<span class="chat-avatar chat-avatar-bot ' + (cls || '') + '"><i class="fa-solid fa-shield-halved"></i></span>';
        }
        // Avatar người gửi 1 tin nhắn: tài khoản hệ thống dùng khiên thay chữ cái đầu.
        function msgAvatarHtml(m) {
            var isBotSender = (botState && parseInt(m.sender_id, 10) === parseInt(botState.id, 10))
                || (current && current.is_bot && parseInt(m.sender_id, 10) === parseInt(current.user_id, 10));
            return isBotSender ? botAvatarHtml() : avatarHtml(m.sender_avatar, m.sender_name);
        }
        function fmtTime(s) {
            var m = /^(\d{4})-(\d{2})-(\d{2})[ T](\d{2}):(\d{2})/.exec(String(s || ''));
            return m ? (m[4] + ':' + m[5]) : String(s || '');
        }
        function fmtDay(s) {
            var m = /^(\d{4})-(\d{2})-(\d{2})/.exec(String(s || ''));
            return m ? (m[3] + '/' + m[2] + '/' + m[1]) : String(s || '');
        }
        function dayKey(s) { var m = /^(\d{4})-(\d{2})-(\d{2})/.exec(String(s || '')); return m ? m[0] : ''; }
        function fmtSize(n) {
            n = parseInt(n, 10) || 0;
            if (n < 1024) return n + ' B';
            if (n < 1048576) return (n / 1024).toFixed(1) + ' KB';
            return (n / 1048576).toFixed(1) + ' MB';
        }
        // Đuôi file (vd ".doc") lấy từ tên gốc — hiện cạnh dung lượng trong khung tệp đính kèm.
        function fileExt(name) {
            var m = /\.([a-z0-9]+)$/i.exec(String(name || '').trim());
            return m ? ('.' + m[1].toLowerCase()) : '';
        }
        function api(action, opts) {
            return fetch(API + action, Object.assign({ credentials: 'same-origin' }, opts || {}))
                .then(function (r) { return r.json(); });
        }
        function form(obj) {
            var fd = new URLSearchParams();
            Object.keys(obj).forEach(function (k) { fd.append(k, obj[k]); });
            return { method: 'POST', headers: { 'Content-Type': 'application/x-www-form-urlencoded' }, body: fd.toString() };
        }

        /* ============ panel ============ */
        function showView(which) {
            homeView.style.display  = which === 'home'  ? 'flex' : 'none';
            roomView.style.display  = which === 'room'  ? 'flex' : 'none';
            groupView.style.display = which === 'group' ? 'flex' : 'none';
        }
        function openPanel() {
            panel.classList.add('is-open');
            panel.setAttribute('aria-hidden', 'false');
            widget.classList.add('is-panel-open'); // mobile: ẩn bóng chat khi panel toàn màn hình
            showView('home');
            loadConversations();
            loadContacts();
        }
        function closePanel() {
            panel.classList.remove('is-open');
            panel.setAttribute('aria-hidden', 'true');
            widget.classList.remove('is-panel-open');
            stopPoll();
        }
        launcher.addEventListener('click', function () {
            if (panel.classList.contains('is-open')) closePanel(); else openPanel();
        });
        document.getElementById('chat-close').addEventListener('click', closePanel);
        document.getElementById('chat-room-close').addEventListener('click', closePanel);

        /* ============ tabs ============ */
        var tabs = widget.querySelectorAll('.chat-tab');
        tabs.forEach(function (tab) {
            tab.addEventListener('click', function () {
                tabs.forEach(function (t) { t.classList.remove('is-active'); });
                tab.classList.add('is-active');
                var name = tab.getAttribute('data-tab');
                recentList.style.display   = name === 'recent' ? '' : 'none';
                contactsList.style.display = name === 'contacts' ? '' : 'none';
                if (name === 'contacts') loadContacts(); // làm mới trạng thái online
                applyHomeFilter();
            });
        });
        homeSearch.addEventListener('input', applyHomeFilter);
        function applyHomeFilter() {
            var q = homeSearch.value.trim().toLowerCase();
            var list = recentList.style.display === 'none' ? contactsList : recentList;
            list.querySelectorAll('.chat-item').forEach(function (it) {
                var nm = (it.getAttribute('data-name') || '').toLowerCase();
                it.style.display = (!q || nm.indexOf(q) !== -1) ? '' : 'none';
            });
        }

        /* ============ danh sách hội thoại ============ */
        function loadConversations(cb) {
            return api('conversations').then(function (res) {
                if (!res || !res.ok) return;
                if (res.bot) botState = res.bot;
                setBadge(res.unread_total);
                renderConversations(res.conversations || []);
                if (typeof cb === 'function') cb();
            }).catch(function () {});
        }
        function renderConversations(items) {
            convCache = {};
            items.forEach(function (c) { convCache[c.id] = c; });
            if (!items.length) {
                recentList.innerHTML = '<div class="chat-empty">Chưa có cuộc trò chuyện nào.<br>Mở “Danh bạ” để bắt đầu.</div>';
                return;
            }
            recentList.innerHTML = items.map(function (c) {
                var av = c.type === 'group' ? groupAvatarHtml(c.avatar, c.name)
                       : (c.is_bot ? botAvatarHtml() : avatarHtml(c.avatar, c.name));
                var preview = c.last_message || '';
                if (c.type === 'group' && c.last_sender && preview) preview = c.last_sender + ': ' + preview;
                var badgeHtml = c.unread > 0 ? '<span class="chat-item-badge">' + (c.unread > 99 ? '99+' : c.unread) + '</span>' : '';
                var mute = c.muted_until ? ' <i class="fa-solid fa-bell-slash chat-item-muted"></i>' : '';
                var grp  = c.type === 'group' ? ' <i class="fa-solid fa-user-group" style="font-size:11px;color:#999"></i>' : '';
                if (c.is_bot) grp = ' <span class="chat-bot-tag">Hệ thống</span>';
                // Badge cảm xúc kiểu Zalo: "{emoji} {tên người thả}" dưới dòng tin nhắn,
                // chỉ khi người khác vừa thả lên tin của mình và mình chưa mở xem.
                var rb = c.reaction_badge
                    ? '<div class="chat-item-reaction"><span class="emo">' + esc(c.reaction_badge.emoji) + '</span> '
                        + esc(c.reaction_badge.name) + '</div>'
                    : '';
                return '<div class="chat-item' + (c.unread > 0 ? ' is-unread' : '') + '"'
                    + ' data-conv="' + c.id + '" data-type="' + esc(c.type) + '" data-name="' + esc(c.name) + '">'
                    + av
                    + '<div class="chat-item-main">'
                    + '<div class="chat-item-name">' + esc(c.name) + grp + mute + '</div>'
                    + '<div class="chat-item-sub">' + esc(preview) + '</div>'
                    + rb
                    + '</div>'
                    + '<div class="chat-item-side">'
                    + '<span class="chat-item-time">' + esc(c.last_time ? fmtTime(c.last_time) : '') + '</span>'
                    + badgeHtml + '</div>'
                    + '<button type="button" class="chat-item-more" title="Tùy chọn"><i class="fa-solid fa-ellipsis"></i></button>'
                    + '</div>';
            }).join('');
            recentList.querySelectorAll('.chat-item').forEach(function (it) {
                it.addEventListener('click', function (e) {
                    if (e.target.closest('.chat-item-more')) return;
                    openConversation(parseInt(it.getAttribute('data-conv'), 10));
                });
                var more = it.querySelector('.chat-item-more');
                if (more) more.addEventListener('click', function (e) {
                    e.stopPropagation();
                    openCtxMenu(more, parseInt(it.getAttribute('data-conv'), 10));
                });
            });
            applyHomeFilter();
        }

        /* ============ danh bạ ============ */
        // Danh bạ NGƯỜI THẬT (bỏ tài khoản hệ thống): dùng cho tạo nhóm / thêm thành viên /
        // gợi ý @nhắc tên / chia sẻ tin — bot chỉ trò chuyện 1-1 nên không xuất hiện ở đó.
        function humanContacts() {
            return contactsCache.filter(function (u) { return !u.is_bot; });
        }
        function loadContacts(cb) {
            return api('contacts').then(function (res) {
                if (!res || !res.ok) return;
                if (res.me) meBrief = res.me;
                if (res.bot) botState = res.bot;
                contactsCache = res.contacts || [];
                renderContacts(contactsCache);
                if (typeof cb === 'function') cb();
            }).catch(function () {});
        }
        function renderContacts(items) {
            if (!items.length) { contactsList.innerHTML = '<div class="chat-empty">Không có người dùng nào khác.</div>'; return; }
            contactsList.innerHTML = items.map(function (u) {
                // Tài khoản hệ thống: ghim đầu danh bạ, không đặt biệt danh / không trạng thái online.
                if (u.is_bot) {
                    return '<div class="chat-item chat-item-bot" data-user="' + u.id + '"'
                        + ' data-name="' + esc(u.fullname + ' ' + u.username) + '">'
                        + botAvatarHtml()
                        + '<div class="chat-item-main">'
                        + '<div class="chat-item-name-row"><span class="chat-item-name">' + esc(u.fullname) + '</span>'
                        + '<span class="chat-bot-tag">Hệ thống</span></div>'
                        + '<div class="chat-item-sub">' + esc(u.bot_desc || 'Tài khoản hệ thống') + '</div>'
                        + '</div></div>';
                }
                var alias = (u.alias || '').trim();
                var display = alias || u.fullname;
                // Sub: đang online → "Đang hoạt động"; có biệt danh → tên thật; còn lại → @username.
                var sub = u.online ? '<span class="chat-online-text">Đang hoạt động</span>'
                    : (alias ? esc(u.fullname) : '@' + esc(u.username));
                // data-name gộp cả biệt danh + tên thật + username để tìm kiếm vẫn ra.
                var searchKey = display + ' ' + u.fullname + ' ' + u.username;
                return '<div class="chat-item" data-user="' + u.id + '" data-name="' + esc(searchKey) + '"'
                    + ' data-fullname="' + esc(u.fullname) + '" data-alias="' + esc(alias) + '">'
                    + presenceAvatarHtml(u.avatar, u.fullname, u.online)
                    + '<div class="chat-item-main">'
                    + '<div class="chat-item-name-row">'
                    + '<span class="chat-item-name">' + esc(display) + '</span>'
                    + '<button type="button" class="chat-contact-rename" title="Đặt tên gợi nhớ"><i class="fa-solid fa-pen"></i></button>'
                    + '</div>'
                    + '<div class="chat-item-sub">' + sub + '</div></div></div>';
            }).join('');
            contactsList.querySelectorAll('.chat-item').forEach(function (it) {
                it.addEventListener('click', function (e) {
                    if (e.target.closest('.chat-contact-rename') || e.target.closest('.chat-contact-name-input')) return;
                    openDirect(parseInt(it.getAttribute('data-user'), 10));
                });
                var pen = it.querySelector('.chat-contact-rename');
                if (pen) pen.addEventListener('click', function (e) { e.stopPropagation(); editContactAlias(it); });
            });
            applyHomeFilter();
        }

        // Sửa biệt danh 1 liên hệ ngay tại chỗ (riêng tư cho user hiện tại).
        function editContactAlias(it) {
            var nameRow = it.querySelector('.chat-item-name-row');
            if (!nameRow || nameRow.querySelector('.chat-contact-name-input')) return;
            var nameEl = nameRow.querySelector('.chat-item-name');
            var pen = nameRow.querySelector('.chat-contact-rename');
            var uid = parseInt(it.getAttribute('data-user'), 10);
            var fullname = it.getAttribute('data-fullname') || '';
            var input = document.createElement('input');
            input.type = 'text';
            input.className = 'chat-contact-name-input';
            input.value = it.getAttribute('data-alias') || '';
            input.placeholder = fullname || 'Tên gợi nhớ…';
            input.maxLength = 150;
            nameEl.style.display = 'none';
            if (pen) pen.style.display = 'none';
            nameRow.insertBefore(input, nameEl);
            input.focus(); input.select();
            var done = false;
            function finish(save) {
                if (done) return; done = true;
                var val = input.value.trim();
                if (input.parentNode) input.parentNode.removeChild(input);
                nameEl.style.display = ''; if (pen) pen.style.display = '';
                if (!save) return;
                // Để trống = gỡ biệt danh (trở về tên thật).
                api('renameContact', form({ contact_id: uid, alias: val })).then(function (res) {
                    if (!res || !res.ok) { alert((res && res.message) || 'Không đổi được tên'); return; }
                    var alias = (res.alias || '').trim();
                    contactsCache.forEach(function (u) { if (u.id === uid) u.alias = alias; });
                    renderContacts(contactsCache); // vẽ lại để cập nhật tên + dòng phụ
                }).catch(function () {});
            }
            input.addEventListener('keydown', function (ev) {
                if (ev.key === 'Enter') { ev.preventDefault(); finish(true); }
                else if (ev.key === 'Escape') finish(false);
            });
            input.addEventListener('blur', function () { finish(true); });
        }

        /* ============ mở hội thoại ============ */
        function openDirect(userId) {
            api('open', form({ user_id: userId })).then(function (res) {
                if (!res || !res.ok) { alert((res && res.message) || 'Không mở được hội thoại'); return; }
                enterRoom(res.conversation);
            }).catch(function () {});
        }
        function openConversation(convId) {
            var meta = convCache[convId];
            if (!meta) {
                var el = recentList.querySelector('.chat-item[data-conv="' + convId + '"]');
                meta = { id: convId, type: el ? el.getAttribute('data-type') : 'direct', name: el ? el.getAttribute('data-name') : '' };
            }
            enterRoom(meta);
        }
        // mở theo id (dùng cho auto-bung + toast nhắc hẹn).
        // msgId (tùy chọn): sau khi vào phòng sẽ cuộn + nháy sáng đúng tin được nhắc.
        function openConversationById(convId, msgId) {
            if (!panel.classList.contains('is-open')) { panel.classList.add('is-open'); panel.setAttribute('aria-hidden', 'false'); }
            var go = function () {
                if (!convCache[convId]) return;
                enterRoom(convCache[convId]);
                if (msgId) setTimeout(function () { jumpToMessage(msgId); }, 650);
            };
            if (convCache[convId]) go(); else loadConversations(go);
        }

        function configureRoomChrome(conv) {
            var isGroup = conv.type === 'group';
            roomNameEdit.style.display = isGroup ? '' : 'none';
            roomAvatar.classList.toggle('can-edit', isGroup);
            if (isGroup) {
                roomSub.textContent = (conv.member_count || (conv.members ? conv.members.length : 0)) + ' thành viên';
            } else if (conv.is_bot) {
                roomSub.textContent = 'Tài khoản hệ thống · luôn sẵn sàng';
            } else {
                roomSub.textContent = '@' + (conv.username || '');
            }
            // Hội thoại với hệ thống: không thêm thành viên (không phải nhóm người dùng).
            var addMemberBtn = document.getElementById('chat-room-add-member');
            if (addMemberBtn) addMemberBtn.style.display = conv.is_bot ? 'none' : '';
        }

        function enterRoom(conv) {
            current = conv;
            oldestId = 0; newestId = 0; hasMore = false; lastReaders = [];
            clearPending(); hideEmoji(); closeOverlay(); clearReply();
            roomName.textContent = conv.name || 'Hội thoại';
            roomAvatar.innerHTML = (conv.type === 'group' ? groupAvatarHtml(conv.avatar, conv.name, 'sm')
                    : (conv.is_bot ? botAvatarHtml('sm') : avatarHtml(conv.avatar, conv.name, 'sm')))
                + '<span class="chat-room-avatar-cam"><i class="fa-solid fa-camera"></i></span>';
            configureRoomChrome(conv);
            msgsBox.innerHTML = '<div class="chat-empty">Đang tải…</div>';
            showView('room');
            hideRoomSearch();
            loadMessages();
            startPoll();
            // Auto-focus ô nhập khi chọn hội thoại để soạn tin nhanh hơn;
            // trigger listener 'focus' của inputText nên vẫn tính "đã xem" như cũ.
            setTimeout(function () { inputText.focus(); }, 0);
        }

        document.getElementById('chat-back').addEventListener('click', function () {
            stopPoll(); current = null; showView('home'); loadConversations();
        });

        /* ============ tin nhắn ============ */
        function loadMessages() {
            if (!current) return;
            api('messages&conversation_id=' + current.id + '&limit=20').then(function (res) {
                if (!res || !res.ok) { msgsBox.innerHTML = '<div class="chat-empty">' + esc((res && res.message) || 'Lỗi tải tin nhắn') + '</div>'; return; }
                hasMore = res.has_more;
                renderMessages(res.messages || [], 'replace');
                if (res.messages && res.messages.length) {
                    oldestId = res.messages[0].id;
                    newestId = res.messages[res.messages.length - 1].id;
                }
                lastReaders = res.readers || [];
                renderSeen();
                scrollToBottom();
                refreshBadge();
            }).catch(function () {});
        }
        function loadOlder() {
            if (!current || !hasMore || loadingMore || oldestId <= 0) return;
            loadingMore = true;
            var prevH = msgsBox.scrollHeight;
            api('messages&conversation_id=' + current.id + '&before_id=' + oldestId + '&limit=20').then(function (res) {
                loadingMore = false;
                if (!res || !res.ok) return;
                var msgs = res.messages || [];
                if (!msgs.length) { hasMore = false; removeLoadMore(); return; }
                oldestId = msgs[0].id;
                hasMore = msgs.length >= 20;
                renderMessages(msgs, 'prepend');
                renderSeen();
                msgsBox.scrollTop = msgsBox.scrollHeight - prevH;
            }).catch(function () { loadingMore = false; });
        }
        function pollNew() {
            if (!current) return;
            api('messages&conversation_id=' + current.id + '&after_id=' + newestId + '&limit=50').then(function (res) {
                if (!res || !res.ok) return;
                if (res.messages && res.messages.length) {
                    var atBottom = isAtBottom();
                    renderMessages(res.messages, 'append');
                    newestId = res.messages[res.messages.length - 1].id;
                    if (atBottom) scrollToBottom();
                    // Đang focus ô nhập → tin mới được tính là đã xem ngay.
                    if (inputFocused) markCurrentRead();
                }
                applyUpdates(res.updates);                 // đồng bộ cảm xúc/thu hồi 2 phía
                lastReaders = res.readers || lastReaders;
                renderSeen();
            }).catch(function () {});
        }

        function reactionsHtml(m) {
            if (!m.reactions || !m.reactions.length) return '';
            return '<div class="chat-reactions">' + m.reactions.map(function (r) {
                var who = (r.users && r.users.length) ? r.users.map(esc).join(', ') : '';
                var tip = who ? '<span class="chat-reaction-tip">' + who + '</span>' : '';
                return '<span class="chat-reaction-chip' + (r.mine ? ' mine' : '') + '" data-emoji="' + esc(r.emoji) + '">'
                    + esc(r.emoji) + '<span class="cnt">' + r.count + '</span>' + tip + '</span>';
            }).join('') + '</div>';
        }
        function actionsHtml(m) {
            var btns = '<button class="act-reply" title="Trả lời"><i class="fa-solid fa-reply"></i></button>'
                     + '<button class="act-react" title="Cảm xúc">😊</button>'
                     + '<button class="act-forward" title="Chia sẻ"><i class="fa-solid fa-share"></i></button>'
                     + '<button class="act-remind" title="Nhắc hẹn"><i class="fa-regular fa-clock"></i></button>';
            // Chỉ cho thu hồi khi còn trong cửa sổ 1 giờ (cờ tính ở máy chủ).
            if (m.can_recall) btns += '<button class="act-recall" title="Thu hồi"><i class="fa-solid fa-rotate-left"></i></button>';
            // Chỉ NGƯỜI NHẬN mới xóa (phía mình); người gửi vẫn còn giữ tin nhắn.
            if (m.can_delete) btns += '<button class="act-delete" title="Xóa"><i class="fa-solid fa-trash"></i></button>';
            return '<div class="chat-msg-actions">' + btns + '</div>';
        }
        // Cụm nút gợi ý do tài khoản hệ thống gửi kèm ("Có phải bạn hỏi về…").
        // Bấm 1 nút = gửi đúng câu đó kèm lựa chọn đã chốt (bot_pick) nên không phải đoán lại.
        // o = {t:nhãn, k:chủ đề, e:loại thực thể(p/m/s/c), i:id, f/u:kỳ từ/đến}.
        // 'p' là field đời cũ (chỉ product_id) — vẫn phát ra để tin nhắn cũ bấm lại được.
        function botOptionsHtml(m) {
            if (!m.bot_options || !m.bot_options.length) return '';
            var used = m.bot_used ? ' is-used' : '';
            return '<div class="chat-bot-opts' + used + '" data-src="' + m.id + '">'
                + m.bot_options.map(function (o) {
                    return '<button type="button" class="chat-bot-opt"'
                        + ' data-k="' + esc(o.k || '') + '" data-p="' + (parseInt(o.p, 10) || 0) + '"'
                        + ' data-e="' + esc(o.e || '') + '" data-i="' + (parseInt(o.i, 10) || 0) + '"'
                        + ' data-f="' + esc(o.f || '') + '" data-u="' + esc(o.u || '') + '"'
                        + (m.bot_used ? ' disabled' : '') + '>' + esc(o.t || '') + '</button>';
                }).join('') + '</div>';
        }
        // Trích dẫn tin gốc (kiểu Zalo) — bấm để nhảy về tin đó.
        function replyQuoteHtml(m) {
            if (!m.reply_to) return '';
            return '<div class="chat-reply-quote" data-target="' + m.reply_to.id + '">'
                + '<span class="r-name">' + esc(m.reply_to.sender_name) + '</span>'
                + '<span class="r-text">' + esc(m.reply_to.preview) + '</span></div>';
        }
        function bubbleHtml(m) {
            if (m.type === 'system') {
                return '<div class="chat-msg-system" data-id="' + m.id + '">' + esc(m.body) + '</div>';
            }
            var isMe = parseInt(m.sender_id, 10) === ME;

            if (m.recalled) {
                return '<div class="chat-msg' + (isMe ? ' is-me' : '') + '" data-id="' + m.id + '" data-sender="' + m.sender_id + '">'
                    + (isMe ? '' : msgAvatarHtml(m))
                    + '<div class="chat-msg-col"><div class="chat-bubble is-recalled">Tin nhắn đã thu hồi</div>'
                    + '<div class="chat-msg-time">' + esc(fmtTime(m.created_at)) + '</div></div></div>';
            }
            // Tôi (người nhận) đã tự xóa tin này — chỉ ẩn phía tôi, người gửi vẫn còn giữ nguyên.
            if (m.deleted_for_me) {
                return '<div class="chat-msg' + (isMe ? ' is-me' : '') + '" data-id="' + m.id + '" data-sender="' + m.sender_id + '">'
                    + (isMe ? '' : msgAvatarHtml(m))
                    + '<div class="chat-msg-col"><div class="chat-bubble is-recalled">Tin nhắn đã xóa</div>'
                    + '<div class="chat-msg-time">' + esc(fmtTime(m.created_at)) + '</div></div></div>';
            }

            var addLibBtn = function (a) {
                if (a.in_library) {
                    return '<span class="chat-att-addlib is-saved" title="Đã lưu vào thư viện"><i class="fa-solid fa-check"></i></span>';
                }
                return '<button type="button" class="chat-att-addlib" data-att-id="' + a.id + '" title="Thêm vào thư viện của tôi">'
                    + '<i class="fa-solid fa-folder-plus"></i></button>';
            };
            var atts = (m.attachments || []).map(function (a) {
                if (a.is_image) return '<div class="chat-att-wrap">'
                    + '<img class="chat-att-img" src="' + esc(a.url) + '" alt="" data-full="' + esc(a.url) + '"'
                    + ' data-mid="' + m.id + '" data-sender-id="' + m.sender_id + '"'
                    + ' data-sender-name="' + esc(m.sender_name || '') + '" data-sender-avatar="' + esc(m.sender_avatar || '')
                    + '" data-time="' + esc(m.created_at || '') + '">'
                    + addLibBtn(a) + '</div>';
                return '<div class="chat-att-wrap">'
                    + '<a class="chat-att-file" href="' + esc(a.url) + '" target="_blank" download="' + esc(a.original_name) + '">'
                    + '<i class="fa-solid fa-file-arrow-down"></i>'
                    + '<span class="meta"><span class="nm">' + esc(a.original_name) + '</span>'
                    + '<span class="sz">' + fmtSize(a.size) + (fileExt(a.original_name) ? (' - ' + fileExt(a.original_name)) : '') + '</span></span></a>'
                    + addLibBtn(a) + '</div>';
            }).join('');
            var bodyHtml = (m.body && m.body.trim() !== '') ? '<div class="chat-bubble">' + highlightMentions(applyTextFormatDisplay(esc(m.body))) + '</div>' : '';
            var senderTag = (!isMe && current && current.type === 'group') ? '<div class="chat-msg-sender">' + esc(m.sender_name) + '</div>' : '';
            var fwdTag = m.forwarded ? '<div class="chat-fwd-tag"><i class="fa-solid fa-share"></i> Đã chia sẻ</div>' : '';
            var hasReax = !!(m.reactions && m.reactions.length);
            return '<div class="chat-msg' + (isMe ? ' is-me' : '') + (hasReax ? ' has-reactions' : '') + '" data-id="' + m.id + '" data-sender="' + m.sender_id + '">'
                + (isMe ? '' : msgAvatarHtml(m))
                + '<div class="chat-msg-col">'
                + actionsHtml(m) + senderTag + fwdTag + replyQuoteHtml(m) + bodyHtml + atts
                + botOptionsHtml(m)
                + reactionsHtml(m)
                + '<div class="chat-msg-time">' + esc(fmtTime(m.created_at)) + '</div>'
                + '</div></div>';
        }

        function renderMessages(msgs, mode) {
            if (mode === 'replace') {
                if (!msgs.length) { msgsBox.innerHTML = '<div class="chat-empty">Chưa có tin nhắn. Hãy gửi lời chào!</div>'; return; }
                msgsBox.innerHTML = buildWithDays(msgs);
                if (hasMore) addLoadMore();
            } else if (mode === 'append') {
                var frag = document.createElement('div');
                frag.innerHTML = msgs.map(bubbleHtml).join('');
                while (frag.firstChild) msgsBox.appendChild(frag.firstChild);
            } else if (mode === 'prepend') {
                var frag2 = document.createElement('div');
                frag2.innerHTML = buildWithDays(msgs);
                var ref = msgsBox.firstChild;
                while (frag2.firstChild) msgsBox.insertBefore(frag2.firstChild, ref);
                if (hasMore) addLoadMore();
            }
            bindImageZoom();
        }
        function buildWithDays(msgs) {
            var html = '', prevDay = '';
            msgs.forEach(function (m) {
                var dk = dayKey(m.created_at);
                if (dk && dk !== prevDay) { html += '<div class="chat-day-sep"><span>' + esc(fmtDay(m.created_at)) + '</span></div>'; prevDay = dk; }
                html += bubbleHtml(m);
            });
            return html;
        }
        function addLoadMore() {
            if (msgsBox.querySelector('.chat-load-more')) return;
            var d = document.createElement('div');
            d.className = 'chat-load-more';
            d.innerHTML = '<button type="button">Tải tin cũ hơn</button>';
            d.querySelector('button').addEventListener('click', loadOlder);
            msgsBox.insertBefore(d, msgsBox.firstChild);
        }
        function removeLoadMore() { var d = msgsBox.querySelector('.chat-load-more'); if (d) d.remove(); }
        msgsBox.addEventListener('scroll', function () { if (msgsBox.scrollTop <= 30) loadOlder(); });
        function scrollToBottom() { msgsBox.scrollTop = msgsBox.scrollHeight; }
        function isAtBottom() { return msgsBox.scrollHeight - msgsBox.scrollTop - msgsBox.clientHeight < 60; }

        /* ============ "Đã xem" (seen) ============ */
        // "Đã xem" CHỈ hiện dưới TIN CUỐI CÙNG và CHỈ khi đó là tin của tôi,
        // được người kia đọc tới. Gửi/nhận tin mới → tin cuối đổi, người kia
        // chưa đọc tin mới → cụm "Đã xem" cũ tự ẩn, chờ lượt đọc tiếp theo.
        function renderSeen() {
            msgsBox.querySelectorAll('.chat-seen').forEach(function (e) { e.remove(); });
            if (!lastReaders || !lastReaders.length) return;

            var msgEls = msgsBox.querySelectorAll('.chat-msg[data-id]');
            if (!msgEls.length) return;
            var lastEl = msgEls[msgEls.length - 1];          // tin mới nhất (không tính system)
            if (!lastEl.classList.contains('is-me')) return; // chỉ gắn dưới tin của tôi
            var lastId = parseInt(lastEl.getAttribute('data-id'), 10) || 0;

            // chỉ giữ người đã đọc TỚI tin cuối cùng
            var seen = lastReaders.filter(function (r) { return r.last_read_message_id >= lastId; });
            if (!seen.length) return;

            var avs = seen.map(function (r) { return avatarHtml(r.avatar, r.fullname); }).join('');
            lastEl.insertAdjacentHTML('afterend',
                '<div class="chat-seen">' + avs + '</div>');
        }

        /* ============ thao tác trên 1 tin (delegation) ============ */
        msgsBox.addEventListener('click', function (e) {
            // bấm vào "@tên" → thẻ thông tin người được nhắc
            var men = e.target.closest('.chat-mention');
            if (men && window.UserCard) { UserCard.show(men.getAttribute('data-uid')); return; }

            // bấm vào ô trích dẫn trả lời → nhảy về tin gốc
            var rq = e.target.closest('.chat-reply-quote');
            if (rq) { jumpToMessage(parseInt(rq.getAttribute('data-target'), 10)); return; }

            // bấm 1 nút gợi ý của tài khoản hệ thống
            var botOpt = e.target.closest('.chat-bot-opt');
            if (botOpt) { pickBotOption(botOpt); return; }

            var msgEl = e.target.closest('.chat-msg');

            // bấm avatar người gửi → thẻ thông tin
            var av = e.target.closest('.chat-avatar');
            if (av && msgEl && av.parentElement === msgEl && window.UserCard) {
                var sid = parseInt(msgEl.getAttribute('data-sender'), 10);
                var isBotSender = botState && sid === parseInt(botState.id, 10);
                // Tài khoản hệ thống không có thẻ thông tin cá nhân để xem.
                if (sid && sid !== ME && !isBotSender) { UserCard.show(sid); return; }
            }

            // chia sẻ tin này
            if (e.target.closest('.act-forward') && msgEl) { openShare(parseInt(msgEl.getAttribute('data-id'), 10)); return; }

            // trả lời tin này
            if (e.target.closest('.act-reply') && msgEl) { startReplyFromEl(msgEl); return; }

            // chip cảm xúc đã có → bấm để bật/tắt cùng emoji
            var chip = e.target.closest('.chat-reaction-chip');
            if (chip && msgEl) { doReact(parseInt(msgEl.getAttribute('data-id'), 10), chip.getAttribute('data-emoji'), msgEl); return; }

            // nút mở bảng chọn cảm xúc
            if (e.target.closest('.act-react') && msgEl) { toggleReactPop(msgEl); return; }
            // chọn 1 emoji trong bảng
            var rb = e.target.closest('.chat-react-pop button');
            if (rb && msgEl) { doReact(parseInt(msgEl.getAttribute('data-id'), 10), rb.textContent, msgEl); removeReactPop(); return; }
            // nhắc hẹn
            if (e.target.closest('.act-remind') && msgEl) { openReminder(parseInt(msgEl.getAttribute('data-id'), 10), msgEl); return; }
            // thu hồi
            if (e.target.closest('.act-recall') && msgEl) { doRecall(parseInt(msgEl.getAttribute('data-id'), 10)); return; }
            // xóa (phía người nhận, chỉ ẩn phía mình)
            if (e.target.closest('.act-delete') && msgEl) { doDeleteForMe(parseInt(msgEl.getAttribute('data-id'), 10), msgEl); return; }

            // thêm file đính kèm vào "Quản lý file" (Đã lưu từ chia sẻ)
            var addLib = e.target.closest('.chat-att-addlib');
            if (addLib) { doAddToLibrary(addLib); return; }
        });

        function doAddToLibrary(btn) {
            if (btn.disabled) return;
            btn.disabled = true;
            var fd = new URLSearchParams();
            fd.append('attachment_id', btn.getAttribute('data-att-id'));
            fetch('?mod=file_management&controllers=file_management&action=add_from_chat', {
                method: 'POST', headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                body: fd.toString(), credentials: 'same-origin'
            }).then(function (r) { return r.json(); }).then(function (res) {
                if (res && res.success) {
                    btn.classList.add('is-done');
                    btn.title = 'Đã thêm vào thư viện của tôi';
                    btn.innerHTML = '<i class="fa-solid fa-check"></i>';
                    // Nếu trang Quản lý file đang mở (chat nổi trên mọi trang) -> cập nhật
                    // ngay "Đã lưu từ chia sẻ", không cần tải lại cả trang.
                    if (window.__fmReload) { try { window.__fmReload(); } catch (e) {} }
                } else {
                    btn.disabled = false;
                    alert((res && res.message) || 'Không thêm được vào thư viện.');
                }
            }).catch(function () { btn.disabled = false; alert('Lỗi kết nối.'); });
        }

        function removeReactPop() { var p = msgsBox.querySelector('.chat-react-pop'); if (p) p.remove(); }
        function toggleReactPop(msgEl) {
            var existing = msgEl.querySelector('.chat-react-pop');
            removeReactPop();
            if (existing) return;
            var pop = document.createElement('div');
            pop.className = 'chat-react-pop';
            pop.innerHTML = REACTIONS.map(function (em) { return '<button type="button">' + em + '</button>'; }).join('');
            msgEl.querySelector('.chat-msg-col').appendChild(pop);
        }
        function doReact(mid, emoji, msgEl) {
            api('react', form({ message_id: mid, emoji: emoji })).then(function (res) {
                if (!res || !res.ok) return;
                setReactions(msgEl, res.reactions || []); // cập nhật ngay phía mình
            }).catch(function () {});
        }
        // Gắn/ cập nhật chip cảm xúc vào 1 tin (ngay trước dòng thời gian).
        function setReactions(el, arr) {
            var col = el.querySelector('.chat-msg-col'); if (!col) return;
            var old = col.querySelector('.chat-reactions'); if (old) old.remove();
            if (arr && arr.length) {
                var tmp = document.createElement('div');
                tmp.innerHTML = reactionsHtml({ reactions: arr });
                var node = tmp.firstChild;
                var timeEl = col.querySelector('.chat-msg-time');
                if (timeEl) col.insertBefore(node, timeEl); else col.appendChild(node);
                el.classList.add('has-reactions');   // có cảm xúc → ẩn dòng thời gian
            } else {
                el.classList.remove('has-reactions');
            }
        }
        // Đồng bộ cảm xúc + thu hồi cho các tin đang hiển thị (gọi khi poll).
        function applyUpdates(u) {
            if (!u) return;
            (u.recalled || []).forEach(function (mid) {
                var el = msgsBox.querySelector('.chat-msg[data-id="' + mid + '"]');
                if (el && !el.querySelector('.is-recalled')) {
                    el.classList.remove('has-reactions');
                    var timeEl = el.querySelector('.chat-msg-time');
                    var t = timeEl ? timeEl.textContent : '';
                    var col = el.querySelector('.chat-msg-col');
                    if (col) col.innerHTML = '<div class="chat-bubble is-recalled">Tin nhắn đã thu hồi</div>'
                        + '<div class="chat-msg-time">' + esc(t) + '</div>';
                }
            });
            var rmap = u.reactions || {};
            // bỏ chip ở những tin nay không còn cảm xúc
            msgsBox.querySelectorAll('.chat-reactions').forEach(function (rc) {
                var mEl = rc.closest('.chat-msg'); if (!mEl) return;
                if (!rmap[mEl.getAttribute('data-id')]) { rc.remove(); mEl.classList.remove('has-reactions'); }
            });
            Object.keys(rmap).forEach(function (mid) {
                var el = msgsBox.querySelector('.chat-msg[data-id="' + mid + '"]');
                if (el) setReactions(el, rmap[mid]);
            });
        }
        function doRecall(mid) {
            api('recall', form({ message_id: mid })).then(function (res) {
                if (!res || !res.ok) { alert((res && res.message) || 'Không thu hồi được'); return; }
                loadMessages(); // tải lại để hiển thị trạng thái đã thu hồi
            }).catch(function () {});
        }
        // Người nhận tự xóa 1 tin phía mình — người gửi vẫn còn giữ nguyên tin.
        // Không hỏi xác nhận — hiệu ứng mờ dần nhẹ nhàng rồi mới đổi thành "Tin nhắn đã xóa".
        function doDeleteForMe(mid, msgEl) {
            if (!mid) return;
            if (msgEl) msgEl.classList.add('chat-msg-vanishing');
            api('deleteMessage', form({ message_id: mid })).then(function (res) {
                if (!res || !res.ok) {
                    if (msgEl) msgEl.classList.remove('chat-msg-vanishing');
                    alert((res && res.message) || 'Không xóa được');
                    return;
                }
                if (!msgEl) return;
                var apply = function () {
                    msgEl.classList.remove('has-reactions', 'chat-msg-vanishing');
                    var col = msgEl.querySelector('.chat-msg-col');
                    var timeEl = msgEl.querySelector('.chat-msg-time');
                    var t = timeEl ? timeEl.textContent : '';
                    if (col) col.innerHTML = '<div class="chat-bubble is-recalled chat-bubble-fadein">Tin nhắn đã xóa</div>'
                        + '<div class="chat-msg-time">' + esc(t) + '</div>';
                };
                setTimeout(apply, 220); // chờ hiệu ứng mờ dần chạy xong
            }).catch(function () { if (msgEl) msgEl.classList.remove('chat-msg-vanishing'); });
        }

        /* ============ trả lời 1 tin (kiểu Zalo) ============ */
        // Lấy thông tin tin được trả lời từ phần tử DOM rồi mở thanh "đang trả lời".
        function startReplyFromEl(msgEl) {
            var mid = parseInt(msgEl.getAttribute('data-id'), 10);
            if (!mid) return;
            var isMe = msgEl.classList.contains('is-me');
            var senderEl = msgEl.querySelector('.chat-msg-sender');
            var name = isMe ? 'Chính bạn'
                : (senderEl ? senderEl.textContent : ((current && current.name) || 'Người dùng'));
            var bubble = msgEl.querySelector('.chat-bubble:not(.is-recalled)');
            var preview = bubble && bubble.textContent.trim() !== '' ? bubble.textContent : '[Tệp đính kèm]';
            setReplyTo({ id: mid, sender_name: name, preview: preview });
        }
        function setReplyTo(info) {
            replyTo = info;
            replyBarName.textContent = info.sender_name;
            var t = String(info.preview || '');
            replyBarText.textContent = t.length > 90 ? t.slice(0, 90) + '…' : t;
            replyBar.style.display = 'flex';
            inputText.focus();
        }
        function clearReply() { replyTo = null; replyBar.style.display = 'none'; }
        document.getElementById('chat-reply-bar-cancel').addEventListener('click', clearReply);

        // Nhảy về tin gốc; nếu chưa tải tới thì cuộn tải tin cũ rồi thử lại.
        function jumpToMessage(id, tries) {
            tries = tries || 0;
            var el = msgsBox.querySelector('.chat-msg[data-id="' + id + '"]');
            if (el) {
                el.scrollIntoView({ block: 'center', behavior: 'smooth' });
                el.classList.add('chat-msg-flash');
                setTimeout(function () { el.classList.remove('chat-msg-flash'); }, 1600);
                return;
            }
            if (hasMore && tries < 25) {
                loadOlder();
                setTimeout(function () { jumpToMessage(id, tries + 1); }, 350);
            }
        }

        /* ============ gửi tin ============ */
        /* ============ tài khoản hệ thống (Safe King) ============ */
        /* Vẽ tin vừa gửi + (nếu có) các câu trả lời của hệ thống trong cùng 1 lượt.
           Câu trả lời KHÔNG đổ ra một lần: mỗi tin lần lượt hiện "đang soạn tin…"
           rồi chạy dần từng dòng, giống người thật đang gõ. Toàn bộ chỉ là hiệu
           ứng phía client — tin đã nằm sẵn trong DB từ lúc máy chủ trả về. */
        function appendSentAndBot(res) {
            if (res.message) {
                renderMessages([res.message], 'append');
                newestId = Math.max(newestId, parseInt(res.message.id, 10) || 0);
            }
            var bots = (res.bot_replies || []).filter(Boolean);
            renderSeen();
            scrollToBottom();
            if (!bots.length) return;

            // Chốt newestId NGAY (không đợi diễn hoạt xong): vòng poll chạy song song,
            // nếu để nguyên nó sẽ kéo lại đúng các tin này và vẽ trùng lần thứ hai.
            newestId = Math.max(newestId, parseInt(bots[bots.length - 1].id, 10) || 0);
            markCurrentRead();       // đang hiện trước mắt → coi như đã xem, tránh badge ảo
            playBotReplies(bots, 0, current ? current.id : 0);
        }

        /* Lần lượt từng câu trả lời: chấm "đang soạn" -> vẽ bong bóng -> chạy dần dòng. */
        function playBotReplies(list, idx, convId) {
            if (!current || current.id !== convId) return;   // user đã chuyển hội thoại
            if (idx >= list.length) { renderSeen(); return; }
            var m = list[idx];
            var stick = isAtBottom();

            var typing = showTypingBubble(m);
            if (stick) scrollToBottom();
            // Tin càng dài "gõ" càng lâu, nhưng chặn trần để không ai phải chờ.
            var think = Math.min(1500, 450 + (m.body || '').length * 5);
            setTimeout(function () {
                if (typing && typing.parentNode) typing.remove();
                if (!current || current.id !== convId) return;
                renderMessages([m], 'append');
                var el = msgsBox.querySelector('.chat-msg[data-id="' + m.id + '"]');
                revealBubbleLines(el, stick, function () {
                    playBotReplies(list, idx + 1, convId);
                });
            }, think);
        }

        /** Bong bóng 3 chấm nhấp nháy của tài khoản hệ thống (không có data-id). */
        function showTypingBubble(m) {
            var d = document.createElement('div');
            d.className = 'chat-msg chat-msg-typing';
            d.innerHTML = msgAvatarHtml(m)
                + '<div class="chat-msg-col"><div class="chat-bubble chat-typing">'
                + '<span></span><span></span><span></span></div></div>';
            msgsBox.appendChild(d);
            return d;
        }

        /* Hiện dần nội dung theo TỪNG DÒNG (không phải từng ký tự): nội dung bot
           là báo cáo nhiều dòng, gõ từng ký tự vừa lâu vừa làm vỡ các thẻ [b].
           .chat-bubble dùng white-space:pre-wrap nên xuống dòng là ký tự '\n'
           thật — cắt theo '\n' an toàn vì thẻ định dạng không bao giờ vắt dòng. */
        function revealBubbleLines(msgEl, stick, done) {
            var bubble = msgEl ? msgEl.querySelector('.chat-bubble') : null;
            if (!bubble) { if (done) done(); return; }

            var full = bubble.innerHTML;
            var lines = full.split('\n');
            if (lines.length < 2) {                       // 1 dòng: hiện luôn, khỏi giật
                if (stick) scrollToBottom();
                if (done) done();
                return;
            }
            msgEl.classList.add('is-revealing');          // giấu cụm nút cho tới khi gõ xong
            bubble.innerHTML = '';

            var i = 0;
            var step = Math.max(40, Math.min(150, Math.round(1100 / lines.length)));
            var timer = setInterval(function () {
                if (!msgEl.isConnected) { clearInterval(timer); return; }
                i++;
                bubble.innerHTML = lines.slice(0, i).join('\n');
                if (stick) scrollToBottom();
                if (i >= lines.length) {
                    clearInterval(timer);
                    msgEl.classList.remove('is-revealing');
                    if (stick) scrollToBottom();
                    if (done) done();
                }
            }, step);
        }
        // Bấm 1 nút gợi ý: gửi đúng nhãn nút làm tin nhắn + kèm lựa chọn đã chốt.
        function pickBotOption(btn) {
            if (btn.disabled || !current) return;
            var wrap = btn.closest('.chat-bot-opts');
            var src  = wrap ? (parseInt(wrap.getAttribute('data-src'), 10) || 0) : 0;
            var label = btn.textContent;
            if (wrap) {
                wrap.classList.add('is-used');
                wrap.querySelectorAll('.chat-bot-opt').forEach(function (b) { b.disabled = true; });
            }
            btn.classList.add('is-picked');

            var fd = new FormData();
            fd.append('conversation_id', current.id);
            fd.append('body', label);
            fd.append('bot_pick', JSON.stringify({
                k: btn.getAttribute('data-k') || '',
                p: parseInt(btn.getAttribute('data-p'), 10) || 0,
                e: btn.getAttribute('data-e') || '',
                i: parseInt(btn.getAttribute('data-i'), 10) || 0,
                f: btn.getAttribute('data-f') || '',
                u: btn.getAttribute('data-u') || '',
                src: src
            }));
            api('send', { method: 'POST', body: fd }).then(function (res) {
                if (!res || !res.ok) { alert((res && res.message) || 'Gửi thất bại'); return; }
                appendSentAndBot(res);
            }).catch(function () { alert('Lỗi kết nối khi gửi'); });
        }

        function sendMessage() {
            if (!current) return;
            var body = serializeEditable(inputText).trim();
            if (body === '' && pendingFiles.length === 0) return;
            var fd = new FormData();
            fd.append('conversation_id', current.id);
            fd.append('body', body);
            if (replyTo && replyTo.id) fd.append('reply_to_id', replyTo.id);
            // @nhắc tên: gom id người có "@<họ tên>" xuất hiện trong nội dung.
            var mentions = [];
            mentionCandidates().forEach(function (c) {
                if (body.indexOf('@' + c.name) !== -1) mentions.push(c.id);
            });
            if (mentions.length) fd.append('mentions', JSON.stringify(mentions));
            pendingFiles.forEach(function (f) { fd.append('files[]', f); });

            sendBtn.disabled = true;
            api('send', { method: 'POST', body: fd }).then(function (res) {
                sendBtn.disabled = false;
                if (!res || !res.ok) { alert((res && res.message) || 'Gửi thất bại'); return; }
                inputText.innerHTML = ''; clearPending(); hideEmoji(); clearReply();
                appendSentAndBot(res);   // tin của tôi + câu trả lời của tài khoản hệ thống (nếu có)
                if (res.skipped && res.skipped.length) alert('Một số tệp không gửi được:\n• ' + res.skipped.join('\n• '));
            }).catch(function () { sendBtn.disabled = false; alert('Lỗi kết nối khi gửi'); });
        }
        sendBtn.addEventListener('click', sendMessage);
        inputText.addEventListener('keydown', function (e) {
            // Khi bảng gợi ý @ đang mở: điều hướng bằng phím, không gửi tin.
            if (mState.open) {
                if (e.key === 'ArrowDown') { e.preventDefault(); mState.active = (mState.active + 1) % mState.items.length; renderMention(); return; }
                if (e.key === 'ArrowUp')   { e.preventDefault(); mState.active = (mState.active - 1 + mState.items.length) % mState.items.length; renderMention(); return; }
                if (e.key === 'Enter' || e.key === 'Tab') { e.preventDefault(); pickMention(mState.items[mState.active]); return; }
                if (e.key === 'Escape') { hideMention(); return; }
            }
            // Shift+Enter / Alt+Enter = xuống dòng trong ô soạn tin; Enter (không kèm phím
            // gì) = gửi. Luôn tự chèn '\n' thủ công (không để trình duyệt tự xử lý Enter)
            // để nội dung ô soạn chỉ gồm text + các thẻ định dạng do chính widget tạo ra.
            if (e.key === 'Enter' && (e.shiftKey || e.altKey)) { e.preventDefault(); insertAtCursor(inputText, '\n'); return; }
            if (e.key === 'Enter') { e.preventDefault(); sendMessage(); }
        });
        // contenteditable tự giãn theo CSS min/max-height + overflow-y (không cần đo
        // scrollHeight thủ công như textarea) — giữ hàm rỗng để các chỗ gọi cũ không vỡ.
        function autoGrow() {}

        /* Mobile: nút "Gửi" chỉ hiện khi có chữ hoặc có tệp đính kèm; nút "..." mở
           nhóm công cụ phụ (ảnh/tệp/định dạng). Trên desktop các quy tắc CSS này vô hại. */
        var composeRow  = widget.querySelector('.chat-compose-row');
        var moreBtn     = document.getElementById('chat-more-btn');
        var composeTools = document.getElementById('chat-compose-tools');
        function updateComposeState() {
            if (!composeRow) return;
            var hasText = inputText.textContent.trim() !== '';
            composeRow.classList.toggle('chat-has-text', hasText || pendingFiles.length > 0);
        }
        if (moreBtn && composeTools) {
            moreBtn.addEventListener('click', function (e) {
                e.stopPropagation();
                composeTools.classList.toggle('is-open');
            });
            // Chọn xong 1 công cụ thì đóng popover.
            composeTools.addEventListener('click', function () { composeTools.classList.remove('is-open'); });
            document.addEventListener('click', function (e) {
                if (composeTools.classList.contains('is-open')
                    && !composeTools.contains(e.target) && !moreBtn.contains(e.target)) {
                    composeTools.classList.remove('is-open');
                }
            });
        }

        inputText.addEventListener('input', function () {
            // Chuẩn hóa về rỗng thật sự (không còn <br> rác) để CSS :empty hiện placeholder.
            if (inputText.textContent === '' && inputText.innerHTML !== '') inputText.innerHTML = '';
            updateMention();
            updateComposeState();
        });
        inputText.addEventListener('blur', function () { setTimeout(hideMention, 150); });

        // "Đã xem" chỉ tính khi người nhận focus vào ô nhập tin.
        function markCurrentRead() {
            if (!current) return;
            api('markRead', form({ conversation_id: current.id })).then(function (res) {
                if (res && res.ok) setBadge(res.unread_total);
            }).catch(function () {});
        }
        inputText.addEventListener('focus', function () { inputFocused = true; markCurrentRead(); });
        inputText.addEventListener('blur',  function () { inputFocused = false; });

        /* ============ đính kèm ============ */
        inputImage.addEventListener('change', function () { addFiles(inputImage.files); inputImage.value = ''; });
        inputFile.addEventListener('change', function () { addFiles(inputFile.files); inputFile.value = ''; });
        inputText.addEventListener('paste', function (e) {
            var cd = e.clipboardData || {};
            var items = cd.items || [];
            var imgFiles = [], otherFiles = [];
            for (var i = 0; i < items.length; i++) {
                if (items[i].kind !== 'file') continue;
                var f = items[i].getAsFile();
                if (!f) continue;
                if (/^image\//.test(f.type)) imgFiles.push(f); else otherFiles.push(f);
            }
            // Luôn tự chèn phần TEXT THUẦN (chặn dán HTML/rich-text từ nguồn ngoài như Word,
            // trang web...) để nội dung ô soạn chỉ gồm text + định dạng do chính widget tạo ra.
            e.preventDefault();
            var text = typeof cd.getData === 'function' ? cd.getData('text/plain') : '';
            if (otherFiles.length) addFiles(otherFiles); // tệp không phải ảnh -> đính kèm thẳng như trước
            // Clipboard có cả ảnh lẫn chữ (vd copy vùng chọn trong Excel: kèm theo bitmap + text)
            // -> hỏi trước khi dán, tránh dán luôn cả 2 như trước đây.
            if (imgFiles.length && text && text.trim()) openPasteChoice(imgFiles, text);
            else if (imgFiles.length) addFiles(imgFiles);
            else if (text) document.execCommand('insertText', false, text);
        });
        // Modal chọn "Gửi dạng hình" / "Gửi dạng chữ" khi paste có cả ảnh và chữ.
        var pastePending = null;
        function openPasteChoice(files, text) {
            pastePending = { files: files, text: text };
            pasteModal.classList.add('is-open');
        }
        function closePasteChoice() { pasteModal.classList.remove('is-open'); pastePending = null; }
        pasteModal.querySelectorAll('[data-paste-close]').forEach(function (el) { el.addEventListener('click', closePasteChoice); });
        pasteAsImage.addEventListener('click', function () {
            if (pastePending) addFiles(pastePending.files);
            closePasteChoice();
        });
        pasteAsText.addEventListener('click', function () {
            if (pastePending) { inputText.focus(); document.execCommand('insertText', false, pastePending.text); }
            closePasteChoice();
        });
        // Kéo-thả file từ máy tính vào ô soạn để đính kèm gửi (thay vì phải bấm "Gửi tệp").
        ['dragenter', 'dragover'].forEach(function (evt) {
            inputText.addEventListener(evt, function (e) {
                if (!e.dataTransfer || e.dataTransfer.types.indexOf('Files') === -1) return;
                e.preventDefault();
                e.dataTransfer.dropEffect = 'copy';
                inputText.classList.add('is-dragover');
            });
        });
        ['dragleave', 'dragend'].forEach(function (evt) {
            inputText.addEventListener(evt, function () { inputText.classList.remove('is-dragover'); });
        });
        inputText.addEventListener('drop', function (e) {
            if (!e.dataTransfer || !e.dataTransfer.files || !e.dataTransfer.files.length) return;
            e.preventDefault(); // chặn trình duyệt tự chèn file/ảnh vào nội dung contenteditable
            inputText.classList.remove('is-dragover');
            addFiles(e.dataTransfer.files);
        });
        /* Ảnh chọn từ thư viện điện thoại thường độ phân giải rất cao (nhiều MB)
         * -> POST nặng, dễ rớt mạng/timeout trên 3G-4G hoặc vượt giới hạn máy chủ,
         * gây "Lỗi kết nối khi gửi". Nén/thu nhỏ ảnh ngay trên trình duyệt trước
         * khi đưa vào hàng chờ gửi. Bỏ qua GIF (mất hiệu ứng động), SVG (ảnh vector),
         * ảnh đã đủ nhỏ, hoặc khi trình duyệt không giải mã được (vd HEIC) -> gửi
         * file gốc như cũ, không chặn thao tác gửi.
         */
        var IMG_COMPRESS_MAX_DIM = 1600;
        var IMG_COMPRESS_QUALITY = 0.82;
        var IMG_COMPRESS_SKIP_UNDER = 1.5 * 1024 * 1024;
        function compressImageFile(file) {
            if (!/^image\//.test(file.type) || file.type === 'image/gif' || file.type === 'image/svg+xml'
                || file.size <= IMG_COMPRESS_SKIP_UNDER) return Promise.resolve(file);
            return new Promise(function (resolve) {
                var url = URL.createObjectURL(file);
                var img = new Image();
                img.onload = function () {
                    URL.revokeObjectURL(url);
                    var w = img.naturalWidth, h = img.naturalHeight;
                    if (!w || !h) { resolve(file); return; }
                    var scale = Math.min(1, IMG_COMPRESS_MAX_DIM / Math.max(w, h));
                    var cw = Math.max(1, Math.round(w * scale)), ch = Math.max(1, Math.round(h * scale));
                    var canvas = document.createElement('canvas');
                    canvas.width = cw; canvas.height = ch;
                    var ctx = canvas.getContext('2d');
                    ctx.fillStyle = '#fff'; ctx.fillRect(0, 0, cw, ch); // nền trắng thay vì đen khi ảnh có vùng trong suốt
                    ctx.drawImage(img, 0, 0, cw, ch);
                    canvas.toBlob(function (blob) {
                        if (!blob || blob.size >= file.size) { resolve(file); return; }
                        var name = file.name.replace(/\.\w+$/, '') + '.jpg';
                        resolve(new File([blob], name, { type: 'image/jpeg' }));
                    }, 'image/jpeg', IMG_COMPRESS_QUALITY);
                };
                img.onerror = function () { URL.revokeObjectURL(url); resolve(file); };
                img.src = url;
            });
        }
        function addFiles(fileList) {
            var files = Array.prototype.slice.call(fileList);
            Promise.all(files.map(compressImageFile)).then(function (results) {
                results.forEach(function (f) {
                    if (f.size > 25 * 1024 * 1024) { alert('Tệp "' + f.name + '" vượt 25MB.'); return; }
                    pendingFiles.push(f);
                });
                renderPending();
            });
        }
        function clearPending() { pendingFiles = []; renderPending(); }
        function renderPending() {
            updateComposeState();  // mobile: cập nhật hiển thị nút "Gửi" theo số tệp đính kèm
            if (!pendingFiles.length) { attachPrev.style.display = 'none'; attachPrev.innerHTML = ''; return; }
            attachPrev.style.display = 'flex'; attachPrev.innerHTML = '';
            pendingFiles.forEach(function (f, idx) {
                var chip = document.createElement('div');
                chip.className = 'chat-attach-chip';
                if (/^image\//.test(f.type)) { var img = document.createElement('img'); img.src = URL.createObjectURL(f); chip.appendChild(img); }
                else { var fc = document.createElement('div'); fc.className = 'file-chip'; fc.innerHTML = '<i class="fa-solid fa-file"></i><span class="nm">' + esc(f.name) + '</span>'; chip.appendChild(fc); }
                var rm = document.createElement('button');
                rm.className = 'rm'; rm.innerHTML = '&times;'; rm.title = 'Bỏ';
                rm.addEventListener('click', function () { pendingFiles.splice(idx, 1); renderPending(); });
                chip.appendChild(rm);
                attachPrev.appendChild(chip);
            });
        }

        /* ============ emoji (soạn tin) ============ */
        // "mousedown"+preventDefault: mở/đóng bảng emoji KHÔNG làm ô soạn (contenteditable)
        // mất focus/con trỏ đang đứng — nếu không, lúc chèn emoji con trỏ sẽ nhảy về cuối.
        emojiBtn.addEventListener('mousedown', function (e) {
            e.preventDefault();
            e.stopPropagation();
            if (emojiPop.style.display === 'none' || !emojiPop.style.display) showEmoji(); else hideEmoji();
        });
        function showEmoji() {
            if (!emojiPop.dataset.built) {
                emojiPop.innerHTML = EMOJIS.map(function (em) { return '<button type="button">' + em + '</button>'; }).join('');
                emojiPop.dataset.built = '1';
                // "mousedown"+preventDefault (không phải "click") để ô soạn KHÔNG mất focus
                // trước khi chèn — insertAtCursor cần focus/selection còn nguyên trong contenteditable.
                emojiPop.addEventListener('mousedown', function (e) {
                    var b = e.target.closest('button'); if (!b) return;
                    e.preventDefault();
                    insertAtCursor(inputText, b.textContent);
                });
            }
            emojiPop.style.display = 'flex';
        }
        function hideEmoji() { emojiPop.style.display = 'none'; }
        // Chèn text tại vị trí con trỏ trong ô soạn (contenteditable), tự thay thế vùng
        // đang bôi đen nếu có — cần inputText đang giữ focus (gọi từ handler "mousedown"
        // + preventDefault để không bị mất focus/selection trước khi chèn).
        function insertAtCursor(el, text) {
            el.focus();
            document.execCommand('insertText', false, text);
        }

        /* ============ định dạng chữ (đậm/nghiêng/gạch chân/màu) cho text đang bôi đen ============ */
        // "mousedown" + preventDefault (thay vì "click") để KHÔNG làm ô nhập mất focus/mất
        // vùng bôi đen khi bấm nút hoặc chọn 1 kiểu định dạng trong bảng.
        var CHAT_FMT_COLORS = { red: '#ef4444', yellow: '#b45309', blue: '#2563eb', green: '#16a34a' };
        if (formatBtn && formatPop) {
            formatBtn.addEventListener('mousedown', function (e) {
                e.preventDefault();
                e.stopPropagation();
                if (formatPop.style.display === 'none' || !formatPop.style.display) formatPop.style.display = 'flex';
                else formatPop.style.display = 'none';
            });
            formatPop.addEventListener('mousedown', function (e) {
                var b = e.target.closest('.chat-fmt-btn');
                if (!b) return;
                e.preventDefault();
                e.stopPropagation();
                applyTextFormat(b.getAttribute('data-fmt'), b.getAttribute('data-color'));
            });
        }
        // Áp định dạng NGAY trên vùng đang bôi đen trong ô soạn (đổi màu/đậm/nghiêng/gạch
        // chân hiển thị thật, không phải chèn mã [b].. thô) — dùng execCommand của chính
        // trình duyệt vì nó xử lý đúng mọi kiểu vùng chọn (nhiều dòng, lồng nhau...) mà
        // không cần tự viết lại logic thao tác Range/Selection phức tạp.
        function applyTextFormat(kind, color) {
            var sel = window.getSelection();
            if (!sel || sel.isCollapsed || !inputText.contains(sel.anchorNode)) return; // chưa bôi đen gì thì bỏ qua
            inputText.focus();
            if (kind === 'color') document.execCommand('foreColor', false, CHAT_FMT_COLORS[color]);
            else if (kind === 'b') document.execCommand('bold', false, null);
            else if (kind === 'i') document.execCommand('italic', false, null);
            else if (kind === 'u') document.execCommand('underline', false, null);
        }
        // Đọc nội dung ô soạn (contenteditable, đã định dạng B/I/U/màu bằng thẻ HTML thật)
        // ngược lại thành plain text kèm mã [b][i][u][color=..] để lưu/gửi lên server —
        // server/các tin nhắn cũ vẫn lưu dạng plain text (mã hóa, tìm kiếm được).
        function serializeEditable(root) {
            var colorRgbMap = null;
            function buildColorRgbMap() {
                colorRgbMap = {};
                var probe = document.createElement('span');
                probe.style.display = 'none';
                document.body.appendChild(probe);
                Object.keys(CHAT_FMT_COLORS).forEach(function (k) {
                    probe.style.color = CHAT_FMT_COLORS[k];
                    colorRgbMap[getComputedStyle(probe).color] = k;
                });
                probe.remove();
            }
            function colorKeyOf(el) {
                var raw = (el.style && el.style.color) ? el.style.color : (el.color || '');
                if (!raw) return null;
                if (!colorRgbMap) buildColorRgbMap();
                if (colorRgbMap[raw]) return colorRgbMap[raw];
                var probe = document.createElement('span');
                probe.style.display = 'none';
                probe.style.color = raw;
                document.body.appendChild(probe);
                var norm = getComputedStyle(probe).color;
                probe.remove();
                return colorRgbMap[norm] || null;
            }
            function walk(node) {
                if (node.nodeType === 3) return node.nodeValue; // text node
                if (node.nodeType !== 1) return '';
                var tag = node.tagName;
                if (tag === 'BR') return '\n';
                var inner = '';
                Array.prototype.forEach.call(node.childNodes, function (c) { inner += walk(c); });
                if (tag === 'DIV' || tag === 'P') inner = '\n' + inner; // phòng khi dán/trình duyệt tạo khối theo dòng
                if (tag === 'B' || tag === 'STRONG') inner = '[b]' + inner + '[/b]';
                if (tag === 'I' || tag === 'EM') inner = '[i]' + inner + '[/i]';
                if (tag === 'U') inner = '[u]' + inner + '[/u]';
                var ck = colorKeyOf(node);
                if (ck) inner = '[color=' + ck + ']' + inner + '[/color]';
                return inner;
            }
            var out = '';
            Array.prototype.forEach.call(root.childNodes, function (c) { out += walk(c); });
            return out.replace(/^\n/, '');
        }
        // Diễn giải mã [b][i][u][color=..] thành HTML — chạy trên chuỗi ĐÃ esc() nên an
        // toàn (không thể chèn thẻ HTML tùy ý, chỉ 4 kiểu định dạng cố định này). Dùng khi
        // HIỂN THỊ bong bóng tin nhắn đã gửi (khác ô soạn — ô soạn định dạng trực tiếp).
        function applyTextFormatDisplay(html) {
            html = html.replace(/\[color=(red|yellow|blue|green)\]([\s\S]*?)\[\/color\]/g,
                '<span class="chat-fmt-color-$1">$2</span>');
            html = html.replace(/\[b\]([\s\S]*?)\[\/b\]/g, '<strong>$1</strong>');
            html = html.replace(/\[i\]([\s\S]*?)\[\/i\]/g, '<em>$1</em>');
            html = html.replace(/\[u\]([\s\S]*?)\[\/u\]/g, '<span class="chat-fmt-underline">$1</span>');
            return html;
        }

        /* ============ tìm trong hội thoại ============ */
        document.getElementById('chat-room-search-btn').addEventListener('click', function () {
            if (roomSearchWrap.style.display === 'none') { roomSearchWrap.style.display = 'flex'; roomSearchInput.focus(); }
            else hideRoomSearch();
        });
        document.getElementById('chat-room-search-clear').addEventListener('click', hideRoomSearch);
        function hideRoomSearch() { roomSearchWrap.style.display = 'none'; roomSearchInput.value = ''; if (current) loadMessages(); }
        var searchDebounce = null;
        roomSearchInput.addEventListener('input', function () {
            if (searchDebounce) clearTimeout(searchDebounce);
            var q = roomSearchInput.value.trim();
            searchDebounce = setTimeout(function () {
                if (!current) return;
                if (q === '') { loadMessages(); return; }
                api('search&conversation_id=' + current.id + '&q=' + encodeURIComponent(q)).then(function (res) {
                    if (!res || !res.ok) return;
                    var msgs = res.results || [];
                    if (!msgs.length) { msgsBox.innerHTML = '<div class="chat-empty">Không tìm thấy tin nhắn nào.</div>'; return; }
                    msgs.sort(function (a, b) { return a.id - b.id; });
                    msgsBox.innerHTML = '<div class="chat-day-sep"><span>Kết quả tìm kiếm</span></div>' + msgs.map(bubbleHtml).join('');
                    bindImageZoom();
                }).catch(function () {});
            }, 300);
        });

        /* ============ tạo nhóm ============ */
        var groupSelected = {};
        document.getElementById('chat-new-group').addEventListener('click', openGroupCreate);
        document.getElementById('chat-group-back').addEventListener('click', function () { showView('home'); });
        document.getElementById('chat-group-close').addEventListener('click', closePanel);
        var groupSearch  = document.getElementById('chat-group-search');
        var groupMembers = document.getElementById('chat-group-members');
        var groupNameInp = document.getElementById('chat-group-name');
        var groupCount   = document.getElementById('chat-group-count');

        function openGroupCreate() {
            groupSelected = {}; groupNameInp.value = ''; groupSearch.value = ''; updateGroupCount();
            showView('group');
            if (!contactsCache.length) { groupMembers.innerHTML = '<div class="chat-empty">Đang tải danh bạ…</div>'; loadContacts(function () { renderGroupMembers(humanContacts()); }); }
            else renderGroupMembers(humanContacts());
        }
        function renderGroupMembers(items) {
            if (!items.length) { groupMembers.innerHTML = '<div class="chat-empty">Không có người dùng.</div>'; return; }
            groupMembers.innerHTML = items.map(function (u) {
                return '<label class="chat-item" data-name="' + esc(u.fullname) + '">'
                    + avatarHtml(u.avatar, u.fullname)
                    + '<div class="chat-item-main"><div class="chat-item-name">' + esc(u.fullname) + '</div>'
                    + '<div class="chat-item-sub">@' + esc(u.username) + '</div></div>'
                    + '<span class="chat-member-check"><input type="checkbox" value="' + u.id + '" ' + (groupSelected[u.id] ? 'checked' : '') + '></span></label>';
            }).join('');
            groupMembers.querySelectorAll('input[type=checkbox]').forEach(function (cb) {
                cb.addEventListener('change', function () { if (cb.checked) groupSelected[cb.value] = true; else delete groupSelected[cb.value]; updateGroupCount(); });
            });
        }
        groupSearch.addEventListener('input', function () {
            var q = groupSearch.value.trim().toLowerCase();
            renderGroupMembers(humanContacts().filter(function (u) { return !q || (u.fullname + ' ' + u.username).toLowerCase().indexOf(q) !== -1; }));
        });
        function updateGroupCount() { groupCount.textContent = 'Đã chọn ' + Object.keys(groupSelected).length; }
        document.getElementById('chat-group-submit').addEventListener('click', function () {
            var name = groupNameInp.value.trim(), ids = Object.keys(groupSelected);
            if (name === '') { alert('Vui lòng nhập tên nhóm.'); return; }
            if (ids.length < 1) { alert('Chọn ít nhất 1 thành viên.'); return; }
            var fd = new FormData(); fd.append('name', name); ids.forEach(function (id) { fd.append('members[]', id); });
            api('createGroup', { method: 'POST', body: fd }).then(function (res) {
                if (!res || !res.ok) { alert((res && res.message) || 'Tạo nhóm thất bại'); return; }
                enterRoom(res.conversation);
            }).catch(function () { alert('Lỗi kết nối'); });
        });

        /* ============ overlay: thành viên / thêm thành viên ============ */
        document.getElementById('chat-overlay-back').addEventListener('click', closeOverlay);
        function closeOverlay() { overlay.style.display = 'none'; overlayMode = ''; }

        // Xem danh sách thành viên (click vào sub)
        roomSub.addEventListener('click', function () {
            if (!current || current.type !== 'group') return;
            openMembersOverlay();
        });
        function openMembersOverlay() {
            overlayMode = 'members';
            overlayTitle.textContent = 'Thành viên nhóm';
            overlayFoot.style.display = 'none';
            overlayBody.innerHTML = '<div class="chat-empty">Đang tải…</div>';
            overlay.style.display = 'flex';
            api('members&conversation_id=' + current.id).then(function (res) {
                if (!res || !res.ok) { overlayBody.innerHTML = '<div class="chat-empty">Không tải được.</div>'; return; }
                var iAmAdmin = !!res.is_admin;
                overlayBody.innerHTML = (res.members || []).map(function (m) {
                    var role = m.is_admin ? '<span class="chat-member-role">Trưởng nhóm</span>' : '';
                    var kick = (iAmAdmin && !m.is_admin && m.id !== ME)
                        ? '<button type="button" class="chat-member-kick" data-uid="' + m.id + '">Đuổi</button>' : '';
                    return '<div class="chat-item chat-member-row" data-uid="' + m.id + '" data-name="' + esc(m.fullname) + '">'
                        + avatarHtml(m.avatar, m.fullname)
                        + '<div class="chat-item-main"><div class="chat-item-name">' + esc(m.fullname) + role + '</div>'
                        + '<div class="chat-item-sub">@' + esc(m.username) + '</div></div>' + kick + '</div>';
                }).join('');
                // Bấm vào 1 thành viên (trừ nút "Đuổi") → thẻ thông tin + xem avatar.
                overlayBody.querySelectorAll('.chat-member-row').forEach(function (row) {
                    row.addEventListener('click', function (e) {
                        if (e.target.closest('.chat-member-kick')) return;
                        if (window.UserCard) UserCard.show(row.getAttribute('data-uid'));
                    });
                });
                overlayBody.querySelectorAll('.chat-member-kick').forEach(function (b) {
                    b.addEventListener('click', function () {
                        var uid = b.getAttribute('data-uid');
                        if (!confirm('Đuổi thành viên này khỏi nhóm?')) return;
                        api('removeMember', form({ conversation_id: current.id, user_id: uid })).then(function (r) {
                            if (!r || !r.ok) { alert((r && r.message) || 'Không đuổi được'); return; }
                            if (r.conversation) { current = r.conversation; configureRoomChrome(current); }
                            openMembersOverlay();
                        });
                    });
                });
            }).catch(function () {});
        }

        // Thêm thành viên
        document.getElementById('chat-room-add-member').addEventListener('click', openAddMemberOverlay);
        var addSelected = {};
        function openAddMemberOverlay() {
            if (!current) return;
            addSelected = {};
            overlayMode = 'addmember';
            overlayTitle.textContent = 'Thêm thành viên';
            overlayFoot.style.display = '';
            overlaySubmit.textContent = 'Thêm';
            overlay.style.display = 'flex';
            overlayBody.innerHTML = '<div class="chat-empty">Đang tải…</div>';
            var existing = {};
            (current.members || []).forEach(function (m) { existing[m.id] = true; });
            if (current.type === 'direct' && current.user_id) existing[current.user_id] = true;
            var fill = function () {
                var avail = humanContacts().filter(function (u) { return !existing[u.id]; });
                if (!avail.length) { overlayBody.innerHTML = '<div class="chat-empty">Không còn ai để thêm.</div>'; return; }
                overlayBody.innerHTML = avail.map(function (u) {
                    return '<label class="chat-item" data-name="' + esc(u.fullname) + '">'
                        + avatarHtml(u.avatar, u.fullname)
                        + '<div class="chat-item-main"><div class="chat-item-name">' + esc(u.fullname) + '</div>'
                        + '<div class="chat-item-sub">@' + esc(u.username) + '</div></div>'
                        + '<span class="chat-member-check"><input type="checkbox" value="' + u.id + '"></span></label>';
                }).join('');
                overlayBody.querySelectorAll('input[type=checkbox]').forEach(function (cb) {
                    cb.addEventListener('change', function () { if (cb.checked) addSelected[cb.value] = true; else delete addSelected[cb.value]; });
                });
            };
            if (!contactsCache.length) loadContacts(fill); else fill();
        }
        overlaySubmit.addEventListener('click', function () {
            if (overlayMode !== 'addmember' || !current) return;
            var ids = Object.keys(addSelected);
            if (!ids.length) { alert('Chọn ít nhất 1 người.'); return; }
            var fd = new FormData(); fd.append('conversation_id', current.id); ids.forEach(function (id) { fd.append('members[]', id); });
            api('addMembers', { method: 'POST', body: fd }).then(function (res) {
                if (!res || !res.ok) { alert((res && res.message) || 'Không thêm được'); return; }
                if (res.conversation) { current = res.conversation; roomName.textContent = current.name; configureRoomChrome(current); }
                closeOverlay();
                loadMessages();
            }).catch(function () {});
        });

        /* ============ đổi tên nhóm ============ */
        roomNameEdit.addEventListener('click', function () {
            if (!current || current.type !== 'group') return;
            var wrap = roomName.parentNode;
            if (wrap.querySelector('.chat-room-name-input')) return;
            var input = document.createElement('input');
            input.type = 'text'; input.className = 'chat-room-name-input'; input.value = roomName.textContent; input.maxLength = 150;
            roomName.style.display = 'none'; roomNameEdit.style.display = 'none';
            wrap.insertBefore(input, roomNameEdit);
            input.focus(); input.select();
            var done = false;
            function finish(save) {
                if (done) return; done = true;
                var val = input.value.trim();
                roomName.style.display = ''; roomNameEdit.style.display = '';
                if (input.parentNode) input.parentNode.removeChild(input);
                if (!save || val === '' || val === roomName.textContent) return;
                api('renameGroup', form({ conversation_id: current.id, name: val })).then(function (res) {
                    if (res && res.ok) { roomName.textContent = res.name || val; if (current) current.name = roomName.textContent; }
                    else alert((res && res.message) || 'Không đổi được tên');
                });
            }
            input.addEventListener('keydown', function (ev) { if (ev.key === 'Enter') { ev.preventDefault(); finish(true); } else if (ev.key === 'Escape') finish(false); });
            input.addEventListener('blur', function () { finish(true); });
        });

        /* ============ đổi ảnh nhóm ============ */
        roomAvatar.addEventListener('click', function () {
            if (current && current.type === 'group') { roomAvatarInput.click(); return; }
            // Hội thoại 1-1: bấm avatar trên thanh tiêu đề → thẻ thông tin người kia.
            if (current && current.user_id && window.UserCard) UserCard.show(current.user_id);
        });
        roomAvatarInput.addEventListener('change', function () {
            if (!current || !roomAvatarInput.files || !roomAvatarInput.files[0]) return;
            var fd = new FormData(); fd.append('conversation_id', current.id); fd.append('avatar', roomAvatarInput.files[0]);
            api('updateGroupAvatar', { method: 'POST', body: fd }).then(function (res) {
                roomAvatarInput.value = '';
                if (!res || !res.ok) { alert((res && res.message) || 'Không đổi được ảnh'); return; }
                if (current) current.avatar = res.avatar;
                roomAvatar.innerHTML = groupAvatarHtml(res.avatar, current.name, 'sm') + '<span class="chat-room-avatar-cam"><i class="fa-solid fa-camera"></i></span>';
            }).catch(function () {});
        });

        /* ============ menu "..." trên hội thoại ============ */
        var AUTO_DELETE_MODES = [
            { mode: '1h', seconds: 3600,    label: 'Xóa sau 1 giờ' },
            { mode: '4h', seconds: 14400,   label: 'Xóa sau 4 giờ' },
            { mode: '1d', seconds: 86400,   label: 'Xóa sau 1 ngày' },
            { mode: '1w', seconds: 604800,  label: 'Xóa sau 1 tuần' },
            { mode: '1m', seconds: 2592000, label: 'Xóa sau 1 tháng' }
        ];
        function openCtxMenu(anchor, convId) {
            var c = convCache[convId] || {};
            var muted = !!c.muted_until;
            var isGroup = c.type === 'group';
            var autoSec = c.auto_delete_seconds || 0;
            var autoHtml = '<div class="chat-ctx-label">Tự xóa tin nhắn (phía bạn)</div>'
                + AUTO_DELETE_MODES.map(function (o) {
                    var active = autoSec === o.seconds;
                    return '<button class="chat-ctx-item" data-act="autodelete" data-mode="' + o.mode + '">'
                        + '<i class="fa-regular fa-hourglass-half"></i> ' + o.label
                        + (active ? '<i class="fa-solid fa-check caret"></i>' : '') + '</button>';
                }).join('')
                + (autoSec ? '<button class="chat-ctx-item" data-act="autodelete" data-mode="off"><i class="fa-solid fa-ban"></i> Tắt tự xóa</button>' : '');
            var html = ''
                + '<div class="chat-ctx-label">Tắt thông báo</div>'
                + '<button class="chat-ctx-item" data-act="mute" data-mode="1h"><i class="fa-regular fa-clock"></i> Trong 1 giờ</button>'
                + '<button class="chat-ctx-item" data-act="mute" data-mode="4h"><i class="fa-regular fa-clock"></i> Trong 4 giờ</button>'
                + '<button class="chat-ctx-item" data-act="mute" data-mode="forever"><i class="fa-solid fa-bell-slash"></i> Cho đến khi được mở lại</button>'
                + (muted ? '<button class="chat-ctx-item" data-act="mute" data-mode="off"><i class="fa-solid fa-bell"></i> Bật lại thông báo</button>' : '')
                + '<div class="chat-ctx-sub"></div>'
                + autoHtml
                + '<div class="chat-ctx-sub"></div>'
                + '<button class="chat-ctx-item danger" data-act="delete"><i class="fa-solid fa-trash"></i> Xóa hội thoại</button>'
                + (isGroup ? '<button class="chat-ctx-item danger" data-act="leave"><i class="fa-solid fa-right-from-bracket"></i> Rời nhóm</button>' : '');
            ctxMenu.innerHTML = html;
            ctxMenu.style.display = 'block';
            var r = anchor.getBoundingClientRect();
            var mw = 220, mh = ctxMenu.offsetHeight;
            var left = Math.min(r.left, window.innerWidth - mw - 8);
            var top = r.bottom + mh > window.innerHeight ? (r.top - mh) : r.bottom + 4;
            ctxMenu.style.left = Math.max(8, left) + 'px';
            ctxMenu.style.top = Math.max(8, top) + 'px';

            ctxMenu.querySelectorAll('.chat-ctx-item').forEach(function (b) {
                b.addEventListener('click', function (e) {
                    e.stopPropagation();
                    var act = b.getAttribute('data-act');
                    if (act === 'mute') {
                        api('mute', form({ conversation_id: convId, mode: b.getAttribute('data-mode') })).then(function () { loadConversations(); });
                    } else if (act === 'autodelete') {
                        api('setAutoDelete', form({ conversation_id: convId, mode: b.getAttribute('data-mode') })).then(function () { loadConversations(); });
                    } else if (act === 'delete') {
                        if (confirm('Xóa hội thoại này? Lịch sử cũ sẽ bị ẩn ở phía bạn.'))
                            api('deleteConversation', form({ conversation_id: convId })).then(function () { if (current && current.id === convId) { current = null; showView('home'); } loadConversations(); });
                    } else if (act === 'leave') {
                        openLeaveModal(convId);
                    }
                    closeCtxMenu();
                });
            });
        }
        function closeCtxMenu() { ctxMenu.style.display = 'none'; ctxMenu.innerHTML = ''; }
        document.addEventListener('click', function (e) {
            if (ctxMenu.style.display === 'block' && !e.target.closest('.chat-ctx-menu') && !e.target.closest('.chat-item-more')) closeCtxMenu();
            if (emojiPop.style.display === 'flex' && !e.target.closest('#chat-emoji-pop') && !e.target.closest('#chat-emoji-btn')) hideEmoji();
            if (formatPop && formatPop.style.display === 'flex' && !e.target.closest('#chat-format-pop') && !e.target.closest('#chat-format-btn')) formatPop.style.display = 'none';
            if (!e.target.closest('.chat-msg')) removeReactPop();
        });

        /* ============ nhắc hẹn ============ */
        function pad(n) { return (n < 10 ? '0' : '') + n; }
        function toLocalInput(d) {
            return d.getFullYear() + '-' + pad(d.getMonth() + 1) + '-' + pad(d.getDate()) + 'T' + pad(d.getHours()) + ':' + pad(d.getMinutes());
        }
        function openReminder(mid, msgEl) {
            reminderMid = mid;
            // Lấy lại nội dung tin nhắn để người dùng có thể sửa.
            var bubble = msgEl.querySelector('.chat-bubble:not(.is-recalled)');
            reminderNote.value = (bubble && bubble.textContent.trim() !== '') ? bubble.textContent : '';
            var d = new Date(Date.now() + 3600 * 1000);
            reminderWhen.value = toLocalInput(d);
            reminderModal.classList.add('is-open');
            setTimeout(function () { reminderNote.focus(); }, 50);
        }
        function closeReminder() { reminderModal.classList.remove('is-open'); reminderMid = 0; }
        reminderModal.querySelectorAll('[data-reminder-close]').forEach(function (el) { el.addEventListener('click', closeReminder); });
        reminderModal.querySelectorAll('.chat-reminder-quick button').forEach(function (b) {
            b.addEventListener('click', function () {
                var secs = parseInt(b.getAttribute('data-quick'), 10) || 3600;
                reminderWhen.value = toLocalInput(new Date(Date.now() + secs * 1000));
            });
        });
        reminderSubmit.addEventListener('click', function () {
            if (!reminderMid) return;
            var v = reminderWhen.value;
            if (!v) { alert('Chọn thời gian.'); return; }
            var note = reminderNote.value.trim();
            var at = v.replace('T', ' ') + ':00';
            api('setReminder', form({ message_id: reminderMid, remind_at: at, note: note })).then(function (res) {
                if (!res || !res.ok) { alert((res && res.message) || 'Không đặt được nhắc hẹn'); return; }
                closeReminder();
                showToast({ tt: 'Đã đặt nhắc hẹn', bd: 'Sẽ nhắc lúc ' + at.slice(0, 16), cv: '' }, 0);
            }).catch(function () {});
        });

        /* ============ toast nhắc hẹn ============ */
        function showToast(data, convId, msgId) {
            var t = document.createElement('div');
            t.className = 'chat-toast';
            t.innerHTML = '<div class="tt"><i class="fa-solid fa-bell"></i>' + esc(data.tt) + '</div>'
                + '<div class="bd">' + esc(data.bd) + '</div>'
                + (data.cv ? '<div class="cv">' + esc(data.cv) + '</div>' : '');
            if (convId) t.addEventListener('click', function () { openConversationById(convId, msgId); t.remove(); });
            toastWrap.appendChild(t);
            setTimeout(function () { if (t.parentNode) t.remove(); }, 12000);
        }

        /* ============ cài đặt chat ============ */
        settingsBtn.addEventListener('click', openSettings);
        settingsModal.querySelectorAll('[data-settings-close]').forEach(function (el) { el.addEventListener('click', closeSettings); });
        function closeSettings() { settingsModal.classList.remove('is-open'); }
        function openSettings() {
            settingsModal.classList.add('is-open');
            api('settings').then(function (res) { if (res && res.ok) applySettingsToUI(res.settings); }).catch(function () {});
            loadBotAdmin();   // khối "Tài khoản hệ thống" (tự ẩn nếu không phải admin)
        }
        function applySettingsToUI(s) {
            if (setHideBubble) setHideBubble.checked = hideBubble; // trạng thái cục bộ (không lưu ở máy chủ)
            if (!s) return;
            setShowOnline.checked  = !!s.show_online;
            setNotifySys.checked   = !!s.notify_in_system;
            setNotifyToast.checked = !!s.notify_toast;
            renderMuteState(s.mute_all_until);
            // đồng bộ trạng thái notif phía JS (gate auto-open/toast).
            notif.in_system = !!s.notify_in_system;
            notif.toast     = !!s.notify_toast;
            notif.mute_all  = !!(s.mute_all_until && s.mute_all_until !== '');
        }
        function renderMuteState(until) {
            var on = until && until !== '';
            var txt;
            if (!on) txt = 'Thông báo đang BẬT';
            else if (String(until).indexOf('2099') === 0) txt = 'Đang tắt thông báo đến khi được mở lại';
            else txt = 'Đang tắt thông báo đến ' + String(until).slice(0, 16).replace('T', ' ');
            setMuteCurrent.textContent = txt;
            setMuteCurrent.className = 'chat-set-current' + (on ? ' is-muted' : '');
            var offBtn = setMuteOptions.querySelector('[data-mute="off"]');
            if (offBtn) offBtn.style.display = on ? '' : 'none';
        }
        function saveSettings(data) {
            return api('saveSettings', form(data)).then(function (res) {
                if (res && res.ok) applySettingsToUI(res.settings);
            }).catch(function () {});
        }
        if (setHideBubble) setHideBubble.addEventListener('change', function () { setHideBubbleState(setHideBubble.checked); });
        setShowOnline.addEventListener('change', function () { saveSettings({ show_online: setShowOnline.checked ? 1 : 0 }); });
        setNotifySys.addEventListener('change', function () { saveSettings({ notify_in_system: setNotifySys.checked ? 1 : 0 }); });
        setNotifyToast.addEventListener('change', function () { saveSettings({ notify_toast: setNotifyToast.checked ? 1 : 0 }); });
        setMuteOptions.querySelectorAll('.chat-set-opt').forEach(function (b) {
            b.addEventListener('click', function () { saveSettings({ mute_mode: b.getAttribute('data-mute') }); });
        });

        /* ---- Cài đặt tài khoản hệ thống (chỉ admin) ---- */
        var botAdminBox  = document.getElementById('chat-bot-admin');
        var botNameInput = document.getElementById('chat-bot-name');
        var botNameSave  = document.getElementById('chat-bot-name-save');
        var botAclBox    = document.getElementById('chat-bot-acl');
        var botTopics    = [];

        function loadBotAdmin() {
            if (!botAdminBox) return;
            api('botAdmin').then(function (res) {
                // Không phải admin → máy chủ trả ok=false, khối này ở lại trạng thái ẩn.
                if (!res || !res.ok) { botAdminBox.style.display = 'none'; return; }
                botAdminBox.style.display = '';
                botTopics = res.topics || [];
                if (botNameInput) botNameInput.value = res.name || '';
                renderBotAcl(res.users || []);
            }).catch(function () {});
        }
        function renderBotAcl(users) {
            if (!botAclBox) return;
            if (!users.length) { botAclBox.innerHTML = '<div class="chat-empty">Chưa có người dùng nào.</div>'; return; }
            var head = '<div class="chat-bot-acl-row is-head"><span class="who">Người dùng</span>'
                + botTopics.map(function (t) { return '<span class="tp">' + esc(t.label) + '</span>'; }).join('')
                + '</div>';
            botAclBox.innerHTML = head + users.map(function (u) {
                var cells = botTopics.map(function (t) {
                    var on = u.topics.indexOf(t.key) !== -1;
                    // Admin luôn đủ quyền → tick sẵn và khóa lại, không cần lưu.
                    return '<span class="tp"><label class="chat-bot-chk">'
                        + '<input type="checkbox" data-user="' + u.id + '" data-topic="' + esc(t.key) + '"'
                        + (on ? ' checked' : '') + (u.is_admin ? ' disabled' : '') + '>'
                        + '<span class="box"></span></label></span>';
                }).join('');
                return '<div class="chat-bot-acl-row" data-user="' + u.id + '">'
                    + '<span class="who"><b>' + esc(u.fullname) + '</b>'
                    + (u.is_admin ? '<i class="tag">quản trị</i>' : '<i class="sub">@' + esc(u.username) + '</i>')
                    + '</span>' + cells + '</div>';
            }).join('');

            botAclBox.querySelectorAll('input[type=checkbox]').forEach(function (cb) {
                cb.addEventListener('change', function () { saveBotAccess(parseInt(cb.getAttribute('data-user'), 10)); });
            });
        }
        // Lưu cả hàng (danh sách chủ đề đang tick) — ghi đè quyền của user đó.
        function saveBotAccess(userId) {
            var row = botAclBox.querySelector('.chat-bot-acl-row[data-user="' + userId + '"]');
            if (!row) return;
            var fd = new URLSearchParams();
            fd.append('user_id', userId);
            row.querySelectorAll('input[type=checkbox]').forEach(function (cb) {
                if (cb.checked) fd.append('topics[]', cb.getAttribute('data-topic'));
            });
            row.classList.add('is-saving');
            api('botSaveAccess', {
                method: 'POST', headers: { 'Content-Type': 'application/x-www-form-urlencoded' }, body: fd.toString()
            }).then(function (res) {
                row.classList.remove('is-saving');
                if (!res || !res.ok) { alert((res && res.message) || 'Không lưu được quyền'); return; }
                row.classList.add('is-saved');
                setTimeout(function () { row.classList.remove('is-saved'); }, 1200);
                loadContacts();   // người vừa được cấp/thu quyền sẽ thấy/mất bot trong danh bạ
            }).catch(function () { row.classList.remove('is-saving'); });
        }
        if (botNameSave) botNameSave.addEventListener('click', function () {
            var name = (botNameInput.value || '').trim();
            if (!name) { alert('Nhập tên tài khoản hệ thống.'); return; }
            botNameSave.disabled = true;
            api('botSaveName', form({ name: name })).then(function (res) {
                botNameSave.disabled = false;
                if (!res || !res.ok) { alert((res && res.message) || 'Không đổi được tên'); return; }
                botNameInput.value = res.name;
                loadContacts(); loadConversations();
                if (current && current.is_bot) roomName.textContent = res.name;
            }).catch(function () { botNameSave.disabled = false; });
        });

        /* ============ rời nhóm (chọn trưởng mới + im lặng) ============ */
        leaveModal.querySelectorAll('[data-leave-close]').forEach(function (el) { el.addEventListener('click', closeLeave); });
        function closeLeave() { leaveModal.classList.remove('is-open'); leaveConvId = 0; }
        function fillSuccessor(members) {
            leaveAdminWrap.style.display = '';
            leaveSuccessor.innerHTML = (members || []).filter(function (m) { return m.id !== ME; })
                .map(function (m) { return '<option value="' + m.id + '">' + esc(m.fullname) + '</option>'; }).join('');
        }
        function openLeaveModal(convId) {
            leaveConvId = convId;
            leaveSilent.checked = false;
            var c = convCache[convId] || {};
            var members = c.members || [];
            var admins = members.filter(function (m) { return m.is_admin; });
            // Tôi là trưởng nhóm duy nhất và còn người ở lại → phải chọn người kế nhiệm.
            var needSuccessor = c.is_admin && admins.length <= 1 && members.length > 1;
            if (needSuccessor) fillSuccessor(members);
            else { leaveAdminWrap.style.display = 'none'; leaveSuccessor.innerHTML = ''; }
            leaveModal.classList.add('is-open');
        }
        leaveSubmit.addEventListener('click', function () {
            if (!leaveConvId) return;
            var data = { conversation_id: leaveConvId, silent: leaveSilent.checked ? 1 : 0 };
            if (leaveAdminWrap.style.display !== 'none' && leaveSuccessor.value) data.new_admin_id = leaveSuccessor.value;
            api('leave', form(data)).then(function (res) {
                if (!res || !res.ok) {
                    if (res && res.need_admin) { fillSuccessor(res.members); alert(res.message || 'Hãy chọn người kế nhiệm.'); return; }
                    alert((res && res.message) || 'Không thể rời nhóm'); return;
                }
                var cid = leaveConvId;
                closeLeave();
                if (current && current.id === cid) { current = null; showView('home'); }
                loadConversations();
            }).catch(function () {});
        });

        /* ============ trình xem ảnh (lightbox nâng cao) ============
         * Thumbnail các ảnh đã gửi (phải) + zoom (nút / Ctrl+lăn chuột) +
         * kéo xem khi phóng to + tải xuống + chia sẻ + avatar/giờ người gửi. */
        var viewer = document.createElement('div');
        viewer.className = 'chat-viewer';
        viewer.innerHTML =
            '<div class="chat-viewer-main">'
            +   '<div class="chat-viewer-topbar">'
            +     '<div class="chat-viewer-sender">'
            +       '<span class="chat-viewer-avatar"></span>'
            +       '<span class="chat-viewer-who"><b class="chat-viewer-name"></b><span class="chat-viewer-time"></span></span>'
            +     '</div>'
            +     '<button type="button" class="chat-viewer-close" title="Đóng"><i class="fa-solid fa-xmark"></i></button>'
            +   '</div>'
            +   '<div class="chat-viewer-stage">'
            +     '<img class="chat-viewer-img" alt="" draggable="false">'
            +   '</div>'
            +   '<div class="chat-viewer-toolbar">'
            +     '<button type="button" data-vt="out" title="Thu nhỏ"><i class="fa-solid fa-magnifying-glass-minus"></i></button>'
            +     '<span class="chat-viewer-zoom">100%</span>'
            +     '<button type="button" data-vt="in" title="Phóng to"><i class="fa-solid fa-magnifying-glass-plus"></i></button>'
            +     '<button type="button" data-vt="reset" title="Cỡ gốc"><i class="fa-solid fa-expand"></i></button>'
            +     '<span class="chat-viewer-sep"></span>'
            +     '<button type="button" data-vt="rot-left" title="Xoay trái"><i class="fa-solid fa-rotate-left"></i></button>'
            +     '<button type="button" data-vt="rot-right" title="Xoay phải"><i class="fa-solid fa-rotate-right"></i></button>'
            +     '<span class="chat-viewer-sep"></span>'
            +     '<button type="button" data-vt="download" title="Tải ảnh xuống"><i class="fa-solid fa-download"></i></button>'
            +     '<button type="button" data-vt="share" title="Chia sẻ ảnh"><i class="fa-solid fa-share"></i></button>'
            +   '</div>'
            + '</div>'
            + '<div class="chat-viewer-thumbs"></div>';
        document.body.appendChild(viewer);

        var vImg = viewer.querySelector('.chat-viewer-img');
        var vStage = viewer.querySelector('.chat-viewer-stage');
        var vThumbs = viewer.querySelector('.chat-viewer-thumbs');
        var vZoomLabel = viewer.querySelector('.chat-viewer-zoom');
        var vAvatar = viewer.querySelector('.chat-viewer-avatar');
        var vName = viewer.querySelector('.chat-viewer-name');
        var vTime = viewer.querySelector('.chat-viewer-time');
        var vState = { list: [], idx: 0, scale: 1, tx: 0, ty: 0, rot: 0, drag: false, sx: 0, sy: 0 };
        var V_MIN = 0.2, V_MAX = 8;

        function vApply() {
            vImg.style.transform = 'translate(' + vState.tx + 'px,' + vState.ty + 'px) scale(' + vState.scale + ') rotate(' + vState.rot + 'deg)';
            vZoomLabel.textContent = Math.round(vState.scale * 100) + '%';
            vStage.classList.toggle('is-zoomed', vState.scale > 1);
        }
        // Xoay ảnh 90° mỗi lần bấm — chỉ đổi góc nhìn, không ảnh hưởng file gốc (tải xuống vẫn nguyên bản).
        function vRotate(delta) { vState.rot = (vState.rot + delta + 360) % 360; vApply(); }
        function vSetZoom(scale, cx, cy) {
            scale = Math.max(V_MIN, Math.min(V_MAX, scale));
            if (cx !== undefined) { // phóng quanh con trỏ
                var ratio = scale / vState.scale;
                vState.tx = cx - (cx - vState.tx) * ratio;
                vState.ty = cy - (cy - vState.ty) * ratio;
            }
            vState.scale = scale;
            if (scale <= 1) { vState.tx = 0; vState.ty = 0; } // về cỡ gốc thì canh giữa
            vApply();
        }
        function vReset() { vState.scale = 1; vState.tx = 0; vState.ty = 0; vApply(); }

        function vCollect() {
            var imgs = [];
            msgsBox.querySelectorAll('.chat-att-img').forEach(function (img) {
                var sid = parseInt(img.getAttribute('data-sender-id'), 10) || 0;
                var name = img.getAttribute('data-sender-name') || '';
                var av = img.getAttribute('data-sender-avatar') || '';
                if (sid === ME && meBrief) { if (!name) name = meBrief.fullname; if (!av) av = meBrief.avatar; }
                imgs.push({
                    url: img.getAttribute('data-full') || img.src,
                    mid: parseInt(img.getAttribute('data-mid'), 10) || 0,
                    senderName: name, senderAvatar: av, time: img.getAttribute('data-time') || ''
                });
            });
            return imgs;
        }
        function vRenderThumbs() {
            vThumbs.innerHTML = '';
            vState.list.forEach(function (it, i) {
                var t = document.createElement('button');
                t.type = 'button';
                t.className = 'chat-viewer-thumb' + (i === vState.idx ? ' is-active' : '');
                t.innerHTML = '<img src="' + esc(it.url) + '" alt="">';
                t.addEventListener('click', function () { vShow(i); });
                vThumbs.appendChild(t);
            });
        }
        function vShow(i) {
            if (i < 0 || i >= vState.list.length) return;
            vState.idx = i;
            var it = vState.list[i];
            vImg.src = it.url;
            vAvatar.innerHTML = avatarHtml(it.senderAvatar, it.senderName);
            vName.textContent = it.senderName || '';
            vTime.textContent = it.time ? (fmtDay(it.time) + ' ' + fmtTime(it.time)) : '';
            vState.rot = 0; // ảnh mới -> về góc xoay mặc định
            vReset();
            vThumbs.querySelectorAll('.chat-viewer-thumb').forEach(function (el, k) { el.classList.toggle('is-active', k === i); });
            var active = vThumbs.querySelector('.is-active');
            if (active) active.scrollIntoView({ block: 'nearest' });
        }
        function openViewer(url) {
            vState.list = vCollect();
            var idx = 0;
            for (var i = 0; i < vState.list.length; i++) { if (vState.list[i].url === url) { idx = i; break; } }
            viewer.classList.toggle('no-thumbs', vState.list.length <= 1);
            viewer.classList.add('is-open'); // hiện trước, để scrollIntoView tính được vị trí (nếu không sẽ kẹt ở hình đầu)
            vRenderThumbs();
            vShow(idx);
        }
        function closeViewer() { viewer.classList.remove('is-open'); }
        function vDownload() {
            var it = vState.list[vState.idx]; if (!it) return;
            var a = document.createElement('a');
            a.href = it.url; a.download = (it.url.split('?')[0].split('/').pop() || 'image');
            document.body.appendChild(a); a.click(); a.remove();
        }

        viewer.querySelector('.chat-viewer-toolbar').addEventListener('click', function (e) {
            var b = e.target.closest('button'); if (!b) return;
            var vt = b.getAttribute('data-vt');
            if (vt === 'in') vSetZoom(vState.scale * 1.25);
            else if (vt === 'out') vSetZoom(vState.scale / 1.25);
            else if (vt === 'reset') vReset();
            else if (vt === 'rot-left') vRotate(-90);
            else if (vt === 'rot-right') vRotate(90);
            else if (vt === 'download') vDownload();
            else if (vt === 'share') { var it = vState.list[vState.idx]; if (it && it.mid) openShare(it.mid); }
        });
        viewer.querySelector('.chat-viewer-close').addEventListener('click', closeViewer);
        viewer.addEventListener('click', function (e) {
            if (vState.dragged) { vState.dragged = false; return; } // vừa kéo ảnh → không đóng
            if (e.target === viewer || e.target === vStage) closeViewer();
        });

        // Lăn chuột → zoom quanh con trỏ (không cần giữ Ctrl)
        vStage.addEventListener('wheel', function (e) {
            e.preventDefault();
            var rect = vStage.getBoundingClientRect();
            var cx = e.clientX - rect.left - rect.width / 2;
            var cy = e.clientY - rect.top - rect.height / 2;
            vSetZoom(vState.scale * (e.deltaY < 0 ? 1.15 : 1 / 1.15), cx, cy);
        }, { passive: false });
        // Nhấn giữ chuột để kéo xem ảnh (khi đã phóng to)
        vImg.addEventListener('mousedown', function (e) {
            if (vState.scale <= 1) return;
            e.preventDefault();
            vState.drag = true; vState.dragged = false;
            vState.sx = e.clientX - vState.tx; vState.sy = e.clientY - vState.ty;
            vStage.classList.add('is-grabbing');
        });
        document.addEventListener('mousemove', function (e) {
            if (!vState.drag) return;
            vState.dragged = true;
            vState.tx = e.clientX - vState.sx; vState.ty = e.clientY - vState.sy; vApply();
        });
        document.addEventListener('mouseup', function () { vState.drag = false; vStage.classList.remove('is-grabbing'); });
        // Phím tắt: Esc đóng, ← → đổi ảnh, +/- zoom
        document.addEventListener('keydown', function (e) {
            if (!viewer.classList.contains('is-open')) return;
            if (e.key === 'Escape') closeViewer();
            else if (e.key === 'ArrowRight') vShow(vState.idx + 1);
            else if (e.key === 'ArrowLeft') vShow(vState.idx - 1);
            else if (e.key === '+' || e.key === '=') vSetZoom(vState.scale * 1.25);
            else if (e.key === '-' || e.key === '_') vSetZoom(vState.scale / 1.25);
        });

        function bindImageZoom() {
            msgsBox.querySelectorAll('.chat-att-img').forEach(function (img) {
                if (img.__zoom) return; img.__zoom = true;
                img.addEventListener('click', function () { openViewer(img.getAttribute('data-full') || img.src); });
            });
        }

        /* ============ @nhắc tên trong cửa sổ chat ============ */
        // Danh sách người có thể nhắc (người khác): danh bạ + thành viên nhóm hiện tại.
        function mentionCandidates() {
            var map = {}, out = [];
            function add(id, name) {
                id = parseInt(id, 10);
                if (!id || id === ME || !name) return;
                if (map[id]) return; map[id] = 1;
                out.push({ id: id, name: name });
            }
            humanContacts().forEach(function (u) { add(u.id, u.fullname); });
            if (current) {
                if (current.type === 'group' && current.members) current.members.forEach(function (m) { add(m.id, m.fullname); });
                else if (current.user_id) add(current.user_id, current.real_name || current.name);
            }
            return out;
        }
        // Tên cần tô màu trong tin (kể cả biệt danh & chính mình) → {id, name}.
        function mentionRoster() {
            var seen = {}, out = [];
            function add(id, name) { if (!name || seen[name]) return; seen[name] = 1; out.push({ id: id, name: name }); }
            humanContacts().forEach(function (u) { add(u.id, u.fullname); if (u.alias) add(u.id, u.alias); });
            if (current && current.type === 'group' && current.members) current.members.forEach(function (m) { add(m.id, m.fullname); });
            if (current && current.user_id) add(current.user_id, current.real_name || current.name);
            if (meBrief) add(meBrief.id, meBrief.fullname);
            return out;
        }
        // Tô màu "@tên" (chuỗi ĐÃ esc) + gắn data-uid để bấm xem thẻ thông tin.
        function highlightMentions(html) {
            mentionRoster()
                .sort(function (a, b) { return b.name.length - a.name.length; }) // tên dài trước
                .forEach(function (r) {
                    var token = '@' + esc(r.name);
                    if (html.indexOf(token) === -1) return;
                    html = html.split(token).join('<span class="chat-mention" data-uid="' + r.id + '">' + token + '</span>');
                });
            return html;
        }

        // Bảng gợi ý @ (gắn vào body, định vị nổi trên ô nhập).
        var mentionPop = document.createElement('div');
        mentionPop.className = 'chat-mention-pop';
        mentionPop.style.display = 'none';
        document.body.appendChild(mentionPop);
        var mState = { open: false, node: null, start: 0, items: [], active: 0 };

        // Vị trí con trỏ hiện tại trong ô soạn (contenteditable), CHỈ hợp lệ khi con trỏ
        // đang đứng trong 1 text node thuần (gõ "@tên" luôn xảy ra trong 1 text node liền
        // mạch) — trả null nếu con trỏ không nằm trong inputText hoặc không phải text node.
        function caretTextNode() {
            var sel = window.getSelection();
            if (!sel || !sel.rangeCount) return null;
            var node = sel.focusNode;
            if (!node || node.nodeType !== 3 || !inputText.contains(node)) return null;
            return { node: node, offset: sel.focusOffset };
        }
        function hideMention() { mState.open = false; mentionPop.style.display = 'none'; }
        function updateMention() {
            var info = caretTextNode();
            if (!info) { hideMention(); return; }
            var before = info.node.textContent.slice(0, info.offset);
            var m = before.match(/@([^\s@]*)$/);
            if (!m) { hideMention(); return; }
            var q = m[1].toLowerCase();
            var list = mentionCandidates().filter(function (c) { return c.name.toLowerCase().indexOf(q) !== -1; }).slice(0, 6);
            if (!list.length) { hideMention(); return; }
            mState.open = true; mState.node = info.node; mState.start = info.offset - m[0].length;
            mState.items = list; mState.active = 0;
            renderMention();
            var r = inputText.getBoundingClientRect();
            mentionPop.style.display = '';
            mentionPop.style.left = r.left + 'px';
            mentionPop.style.top = (r.top - mentionPop.offsetHeight - 6) + 'px';
        }
        function renderMention() {
            mentionPop.innerHTML = '';
            mState.items.forEach(function (mem, i) {
                var it = document.createElement('div');
                it.className = 'chat-mention-item' + (i === mState.active ? ' active' : '');
                it.innerHTML = avatarHtml('', mem.name, 'sm') + '<span>' + esc(mem.name) + '</span>';
                it.addEventListener('mousedown', function (e) { e.preventDefault(); pickMention(mem); });
                mentionPop.appendChild(it);
            });
        }
        function pickMention(mem) {
            if (!mem) { hideMention(); return; }
            var node = mState.node;
            if (!node || !inputText.contains(node)) { hideMention(); return; }
            var info = caretTextNode();
            var caret = (info && info.node === node) ? info.offset : node.textContent.length;
            var insert = '@' + mem.name + ' ';
            var text = node.textContent;
            node.textContent = text.slice(0, mState.start) + insert + text.slice(caret);
            // Đặt lại con trỏ ngay sau đoạn "@Tên " vừa chèn.
            var pos = Math.min(mState.start + insert.length, node.textContent.length);
            var range = document.createRange();
            range.setStart(node, pos);
            range.collapse(true);
            var sel = window.getSelection();
            sel.removeAllRanges();
            sel.addRange(range);
            hideMention();
            inputText.focus();
        }

        /* ============ chia sẻ lại tin nhắn (3 tab) ============ */
        var shareMsgId = 0, shareTab = 'recent';
        var selConv = {}, selUser = {};

        function openShare(mid) {
            shareMsgId = mid; selConv = {}; selUser = {}; shareTab = 'recent';
            if (shareSearch) shareSearch.value = '';
            shareModal.querySelectorAll('.chat-share-tab').forEach(function (t) {
                t.classList.toggle('is-active', t.getAttribute('data-share-tab') === 'recent');
            });
            updateShareCount();
            shareModal.classList.add('is-open');
            shareList.innerHTML = '<div class="chat-empty">Đang tải…</div>';
            loadConversations(function () { renderShareList(); });
            if (!contactsCache.length) loadContacts(function () { if (shareTab === 'contacts') renderShareList(); });
        }
        function closeShare() { shareModal.classList.remove('is-open'); }
        function updateShareCount() {
            var n = Object.keys(selConv).length + Object.keys(selUser).length;
            if (shareCount) shareCount.textContent = 'Đã chọn ' + n;
        }
        function shareRow(kind, id, name, avHtml, selected) {
            return '<div class="chat-share-item' + (selected ? ' is-selected' : '') + '" data-kind="' + kind + '" data-id="' + id + '">'
                + avHtml
                + '<div class="chat-item-main"><div class="chat-item-name">' + esc(name) + '</div></div>'
                + '<span class="chat-share-check"><i class="fa-solid fa-check"></i></span></div>';
        }
        function renderShareList() {
            var q = (shareSearch ? shareSearch.value : '').trim().toLowerCase();
            var html = '';
            if (shareTab === 'contacts') {
                humanContacts().forEach(function (u) {
                    var nm = (u.alias || u.fullname);
                    if (q && (nm + ' ' + u.fullname + ' ' + u.username).toLowerCase().indexOf(q) === -1) return;
                    html += shareRow('user', u.id, nm, avatarHtml(u.avatar, u.fullname), !!selUser[u.id]);
                });
            } else {
                // Bỏ hội thoại với tài khoản hệ thống — chia sẻ tin sang đó không có ý nghĩa.
                var convs = Object.keys(convCache).map(function (k) { return convCache[k]; })
                    .filter(function (c) { return !c.is_bot; });
                if (shareTab === 'groups') convs = convs.filter(function (c) { return c.type === 'group'; });
                convs.forEach(function (c) {
                    if (q && (c.name || '').toLowerCase().indexOf(q) === -1) return;
                    var av = c.type === 'group' ? groupAvatarHtml(c.avatar, c.name) : avatarHtml(c.avatar, c.name);
                    html += shareRow('conv', c.id, c.name, av, !!selConv[c.id]);
                });
            }
            shareList.innerHTML = html || '<div class="chat-empty">Không có mục nào.</div>';
        }
        if (shareModal) {
            shareModal.querySelectorAll('[data-share-close]').forEach(function (el) { el.addEventListener('click', closeShare); });
            shareModal.querySelectorAll('.chat-share-tab').forEach(function (tab) {
                tab.addEventListener('click', function () {
                    shareTab = tab.getAttribute('data-share-tab');
                    shareModal.querySelectorAll('.chat-share-tab').forEach(function (t) { t.classList.remove('is-active'); });
                    tab.classList.add('is-active');
                    renderShareList();
                });
            });
            if (shareSearch) shareSearch.addEventListener('input', renderShareList);
            shareList.addEventListener('click', function (e) {
                var it = e.target.closest('.chat-share-item'); if (!it) return;
                var kind = it.getAttribute('data-kind'), id = parseInt(it.getAttribute('data-id'), 10);
                if (kind === 'user') { if (selUser[id]) delete selUser[id]; else selUser[id] = true; }
                else { if (selConv[id]) delete selConv[id]; else selConv[id] = true; }
                it.classList.toggle('is-selected');
                updateShareCount();
            });
            shareSubmit.addEventListener('click', function () {
                var convIds = Object.keys(selConv), userIds = Object.keys(selUser);
                if (!convIds.length && !userIds.length) { alert('Chọn ít nhất một nơi để chia sẻ.'); return; }
                var fd = new FormData();
                fd.append('message_id', shareMsgId);
                convIds.forEach(function (id) { fd.append('conversation_ids[]', id); });
                userIds.forEach(function (id) { fd.append('user_ids[]', id); });
                shareSubmit.disabled = true;
                api('forward', { method: 'POST', body: fd }).then(function (res) {
                    shareSubmit.disabled = false;
                    if (!res || !res.ok) { alert((res && res.message) || 'Chia sẻ thất bại'); return; }
                    closeShare();
                    showToast({ tt: 'Đã chia sẻ', bd: 'Đã chia sẻ tới ' + (res.count || 0) + ' nơi.', cv: '' }, 0);
                    loadConversations();
                    if (current) pollNew();
                }).catch(function () { shareSubmit.disabled = false; alert('Lỗi kết nối'); });
            });
        }

        /* ============ ẩn bóng chat → gắn lên cạnh chuông ============ */
        // Tạo nút chat trên .app-header-right (bên trái #app-bell), chỉ tạo 1 lần.
        function buildDock() {
            if (dockBtn || !headerRight) return;
            var wrap = document.createElement('div');
            wrap.className = 'app-chat-dock';
            wrap.id = 'app-chat-dock';
            wrap.innerHTML = '<button type="button" class="app-chat-dock-btn" aria-label="Tin nhắn"'
                + ' title="Đưa bóng chat về góc phải màn hình">'
                + '<i class="fa-solid fa-comment-dots"></i>'
                + '<span class="app-chat-dock-badge" style="display:none;">0</span></button>';
            var bellEl = document.getElementById('app-bell');
            if (bellEl) headerRight.insertBefore(wrap, bellEl); else headerRight.appendChild(wrap);
            dockBtn = wrap;
            dockBadge = wrap.querySelector('.app-chat-dock-badge');
            // Bấm → trả bóng chat về góc phải, trở lại trạng thái mặc định.
            wrap.querySelector('.app-chat-dock-btn').addEventListener('click', function () {
                if (setHideBubble) setHideBubble.checked = false;
                setHideBubbleState(false);
            });
        }
        function syncDockBadge() {
            if (!dockBadge) return;
            if (lastUnread > 0) { dockBadge.style.display = ''; dockBadge.textContent = lastUnread > 99 ? '99+' : lastUnread; }
            else dockBadge.style.display = 'none';
        }
        // Áp dụng trạng thái ẩn/hiện bóng chat. Chỉ ẩn được khi có header để gắn nút.
        function applyDockState() {
            var on = hideBubble && !!headerRight;
            widget.classList.toggle('is-docked', on);
            if (on) { buildDock(); closePanel(); }
            if (dockBtn) dockBtn.style.display = on ? '' : 'none';
            syncDockBadge();
        }
        function setHideBubbleState(on) {
            hideBubble = !!on;
            try { localStorage.setItem(HIDE_KEY, hideBubble ? '1' : '0'); } catch (e) {}
            applyDockState();
        }

        /* ============ poll ============ */
        function startPoll() { stopPoll(); pollTimer = setInterval(pollNew, 4000); }
        function stopPoll() { if (pollTimer) { clearInterval(pollTimer); pollTimer = null; } }
        function setBadge(n) {
            n = parseInt(n, 10) || 0;
            lastUnread = n;
            if (n > 0) { badge.style.display = ''; badge.textContent = n > 99 ? '99+' : n; }
            else badge.style.display = 'none';
            syncDockBadge();
        }
        function refreshBadge() { api('unread').then(function (res) { if (res && res.ok) setBadge(res.unread_total); }).catch(function () {}); }

        // Poll toàn cục: badge + tự bung khi có tin mới + nhắc hẹn tới hạn.
        function globalPoll() {
            api('poll').then(function (res) {
                if (!res || !res.ok) return;
                setBadge(res.unread_total);
                if (res.notif) notif = res.notif;   // đồng bộ trạng thái thông báo

                // Có tin nhắn mới (người khác gửi) & chưa đang xem hội thoại đó.
                if (res.latest && res.latest.message_id) {
                    if (lastIncomingId < 0) {
                        lastIncomingId = res.latest.message_id; // lần đầu: chỉ ghi mốc
                    } else if (res.latest.message_id > lastIncomingId) {
                        lastIncomingId = res.latest.message_id;
                        var viewingThis = panel.classList.contains('is-open')
                            && current && current.id === res.latest.conversation_id
                            && roomView.style.display !== 'none';
                        // Tắt thông báo toàn hộp chat, hoặc đang ẩn bóng chat (gắn trên header)
                        // → chỉ cập nhật số tin chưa đọc trên badge, không tự bung/đẩy hội thoại.
                        if (!viewingThis && !notif.mute_all && !hideBubble) {
                            // Thông báo nổi (chat toast)
                            if (notif.toast) {
                                showToast({ tt: res.latest.conv_name || 'Tin nhắn mới',
                                    bd: (res.latest.sender_name ? res.latest.sender_name + ': ' : '') + res.latest.preview, cv: '' },
                                    res.latest.conversation_id, res.latest.message_id);
                            }
                            // Chỉ hiển thị trong hệ thống → tự bung/đẩy hội thoại trong khung chat.
                            if (notif.in_system) openConversationById(res.latest.conversation_id);
                        }
                    }
                }

                // Nhắc hẹn tới hạn → toast (ưu tiên nội dung người dùng đã nhập).
                var due = res.due_reminders || [];
                due.forEach(function (rm) {
                    var body = rm.message ? (rm.message.recalled ? 'Tin đã thu hồi'
                        : (rm.message.body && rm.message.body.trim() !== '' ? rm.message.body : '[Tệp đính kèm]')) : '';
                    var preview = (rm.note && rm.note.trim() !== '') ? rm.note : body;
                    showToast({ tt: 'Nhắc hẹn tin nhắn', bd: preview, cv: rm.conv_name },
                        rm.conversation_id, rm.message_id);
                });
                // Giống khi nhận TIN MỚI: nếu có nhắc hẹn tới hạn mà chưa mở đúng hội
                // thoại (hoặc đang ẩn khung chat) → tự bung khung chat tới tin được nhắc.
                if (due.length && !hideBubble) {
                    var last = due[due.length - 1];
                    var viewingThis = panel.classList.contains('is-open')
                        && current && current.id === last.conversation_id
                        && roomView.style.display !== 'none';
                    if (!viewingThis) openConversationById(last.conversation_id, last.message_id);
                }
            }).catch(function () {});
        }

        // Áp dụng trạng thái "ẩn bóng chat" đã lưu (nếu có) trước khi poll.
        applyDockState();

        // Khởi động poll toàn cục (luôn chạy, kể cả khi panel đóng).
        globalPoll();
        globalTimer = setInterval(globalPoll, 6000);
    });
})();
