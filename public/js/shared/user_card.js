/* =====================================================================
   UserCard — thẻ thông tin người dùng dùng chung (chat-widget + project_management).
   Hiển thị card kiểu .app-user-card: avatar, họ tên, username, email, vai trò.
   Click avatar trong card → phóng to ảnh đại diện ra giữa màn hình.

   Cách dùng:
     UserCard.setApi('?mod=chat&controllers=chat&action=userCard'); // host cấu hình 1 lần
     UserCard.show(userId);                                          // mở thẻ
   Endpoint trả: { ok:true, user:{ id, fullname, username, email, avatar, is_admin } }
   ===================================================================== */
window.UserCard = (function () {
    'use strict';
    var api = null;
    var cache = {};
    var root = null, boxWrap = null, avatarEl = null, zoom = null, zoomImg = null;
    var curAvatar = '';

    function esc(s) {
        return String(s == null ? '' : s)
            .replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;').replace(/'/g, '&#39;');
    }
    function initial(name) { name = String(name || '').trim(); return name ? name.charAt(0).toUpperCase() : '?'; }

    function ensureDom() {
        if (root) return;
        root = document.createElement('div');
        root.className = 'ucard-modal';
        root.innerHTML =
            '<div class="ucard-overlay" data-uc-close></div>' +
            '<div class="ucard-box" role="dialog">' +
                '<div class="ucard-card">' +
                    '<div class="ucard-avatar" id="ucard-avatar" title="Bấm để xem ảnh"></div>' +
                    '<div class="ucard-info">' +
                        '<div class="ucard-name" id="ucard-name"></div>' +
                        '<div class="ucard-rows" id="ucard-rows"></div>' +
                    '</div>' +
                '</div>' +
            '</div>' +
            '<div class="ucard-zoom" id="ucard-zoom"><img alt="" id="ucard-zoom-img"></div>';
        document.body.appendChild(root);
        boxWrap  = root.querySelector('.ucard-box');
        avatarEl = root.querySelector('#ucard-avatar');
        zoom     = root.querySelector('#ucard-zoom');
        zoomImg  = root.querySelector('#ucard-zoom-img');

        root.querySelectorAll('[data-uc-close]').forEach(function (el) {
            el.addEventListener('click', close);
        });
        // Bấm avatar trong card → phóng to (chỉ khi có ảnh thật).
        avatarEl.addEventListener('click', function () {
            if (!curAvatar) return;
            zoomImg.src = curAvatar;
            zoom.classList.add('is-open');
        });
        zoom.addEventListener('click', function () { zoom.classList.remove('is-open'); });
        document.addEventListener('keydown', function (e) {
            if (e.key !== 'Escape') return;
            if (zoom.classList.contains('is-open')) zoom.classList.remove('is-open');
            else if (root.classList.contains('is-open')) close();
        });
    }

    function render(u) {
        ensureDom();
        curAvatar = u.avatar || '';
        avatarEl.innerHTML = curAvatar
            ? '<img src="' + esc(curAvatar) + '" alt="">'
            : esc(initial(u.fullname));
        avatarEl.classList.toggle('has-img', !!curAvatar);
        root.querySelector('#ucard-name').textContent = u.fullname || 'Người dùng';
        var rows = '';
        if (u.username) rows += '<div class="ucard-row"><i class="fa-solid fa-user"></i> ' + esc(u.username) + '</div>';
        if (u.email)    rows += '<div class="ucard-row"><i class="fa-solid fa-envelope"></i> ' + esc(u.email) + '</div>';
        rows += '<div class="ucard-row"><i class="fa-solid fa-id-badge"></i> '
              + (u.is_admin ? 'Quản trị viên' : 'Thành viên') + '</div>';
        root.querySelector('#ucard-rows').innerHTML = rows;
        root.classList.add('is-open');
    }

    function close() { if (root) root.classList.remove('is-open'); }

    function show(userId) {
        userId = parseInt(userId, 10) || 0;
        if (userId <= 0) return;
        if (!api) { console.warn('UserCard.api chưa được cấu hình.'); return; }
        if (cache[userId]) { render(cache[userId]); return; }
        ensureDom();
        // Mở khung rỗng "đang tải" để phản hồi tức thì.
        root.querySelector('#ucard-name') && (root.querySelector('#ucard-name').textContent = 'Đang tải…');
        root.querySelector('#ucard-rows').innerHTML = '';
        avatarEl.innerHTML = ''; avatarEl.classList.remove('has-img'); curAvatar = '';
        root.classList.add('is-open');

        var url = api + (api.indexOf('?') === -1 ? '?' : '&') + 'user_id=' + userId;
        fetch(url, { credentials: 'same-origin' })
            .then(function (r) { return r.json(); })
            .then(function (res) {
                if (!res || !res.ok || !res.user) { close(); return; }
                cache[userId] = res.user;
                render(res.user);
            })
            .catch(function () { close(); });
    }

    return {
        setApi: function (a) { api = a; },
        show: show,
        close: close
    };
})();
