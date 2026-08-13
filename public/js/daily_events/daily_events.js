/* =====================================================================
 *  daily_events.js — "Thảo luận" (Tường kiểu Facebook).
 *  Đăng bài (nội dung + ảnh/file), gắn thẻ user khác (đẩy chuông), thả
 *  emoji, bình luận + trả lời (thụt lề, lồng tối đa 1 cấp), hashtag để
 *  tìm lại bài. Toàn bộ AJAX gọi các action wall_* trong
 *  modules/daily_events/controllers/daily_eventsController.php, logic
 *  thật ở libraries/daily_events_wall.php.
 * ===================================================================== */
(function () {
    'use strict';

    var ACT = '?mod=daily_events&controllers=daily_events&action=';
    var BOOT = window.__DEW_BOOT__ || { me: { id: 0, is_admin: false }, feed: { rows: [], total: 0, page: 1, per_page: 10 }, permalink: null };
    var ME = BOOT.me || { id: 0, is_admin: false };
    var QUICK_EMOJIS = ['👍', '❤️', '😂', '😮', '😢'];

    var state = {
        rows: (BOOT.feed && BOOT.feed.rows) || [],
        total: (BOOT.feed && BOOT.feed.total) || 0,
        page: (BOOT.feed && BOOT.feed.page) || 1,
        perPage: (BOOT.feed && BOOT.feed.per_page) || 10,
        hashtag: '',
        keyword: ''
    };
    var myAvatarUrl = '';
    var composerTags = [];   // [{id, fullname}]
    var commentFiles = {};   // key -> File[] đang chờ gửi kèm bình luận/trả lời

    /* ---------------- Helpers cơ bản ---------------- */
    function $(sel, ctx) { return (ctx || document).querySelector(sel); }
    function $all(sel, ctx) { return Array.prototype.slice.call((ctx || document).querySelectorAll(sel)); }
    function esc(s) {
        return String(s == null ? '' : s)
            .replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;').replace(/'/g, '&#39;');
    }
    function debounce(fn, wait) {
        var t;
        return function () {
            var ctx = this, args = arguments;
            clearTimeout(t);
            t = setTimeout(function () { fn.apply(ctx, args); }, wait);
        };
    }
    /** Debounce theo TỪNG phần tử (giữ timer riêng trên chính el) — dùng khi wiring qua
     *  1 listener delegation duy nhất cho nhiều ô nhập khác nhau (vd nhiều ô sửa bài). */
    function debounceByEl(el, fn, wait) {
        clearTimeout(el.__dewTimer);
        el.__dewTimer = setTimeout(fn, wait);
    }

    /** Tách ô tìm kiếm 1 ô thành {hashtag, keyword}:
     *  - "#tag phần còn lại" -> lọc theo #tag VÀ tìm "phần còn lại" trong nội dung.
     *  - "#tag" (không có gì theo sau) -> chỉ lọc theo #tag (như cũ).
     *  - "chữ thường" (không bắt đầu bằng #) -> tìm theo nội dung trên TOÀN BỘ bài, không giới hạn hashtag. */
    function parseSearchInput(raw) {
        raw = String(raw || '').trim();
        var m = raw.match(/^#([\p{L}\p{N}_]+)\s*/u);
        if (m) return { hashtag: m[1].toLowerCase(), keyword: raw.slice(m[0].length).trim() };
        return { hashtag: '', keyword: raw };
    }

    function api(action, data, isForm) {
        var opt = { method: 'POST' };
        if (isForm) {
            opt.body = data; // FormData
        } else {
            var parts = [];
            Object.keys(data || {}).forEach(function (k) {
                var v = data[k];
                if (Array.isArray(v)) {
                    v.forEach(function (item) { parts.push(encodeURIComponent(k + '[]') + '=' + encodeURIComponent(item)); });
                } else {
                    parts.push(encodeURIComponent(k) + '=' + encodeURIComponent(v == null ? '' : v));
                }
            });
            opt.headers = { 'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8' };
            opt.body = parts.join('&');
        }
        return fetch(ACT + action, opt).then(function (r) { return r.json(); })
            .catch(function () { toast('Lỗi kết nối máy chủ.', false); return null; });
    }

    function toast(msg, ok) {
        var el = document.createElement('div');
        el.className = 'dew-toast' + (ok === false ? ' is-err' : '');
        el.textContent = msg;
        document.body.appendChild(el);
        requestAnimationFrame(function () { el.classList.add('show'); });
        setTimeout(function () { el.classList.remove('show'); setTimeout(function () { el.remove(); }, 250); }, 2200);
    }

    /* ---------------- Thời gian tương đối ---------------- */
    function parseServerDate(s) {
        if (!s) return new Date();
        var m = String(s).match(/(\d{4})-(\d{2})-(\d{2})[ T](\d{2}):(\d{2}):(\d{2})/);
        if (!m) return new Date(s);
        return new Date(+m[1], +m[2] - 1, +m[3], +m[4], +m[5], +m[6]);
    }
    function pad2(n) { return (n < 10 ? '0' : '') + n; }
    function timeAgo(s) {
        var d = parseServerDate(s);
        var diff = Math.floor((Date.now() - d.getTime()) / 1000);
        if (diff < 30) return 'Vừa xong';
        if (diff < 60) return diff + ' giây trước';
        var mnt = Math.floor(diff / 60);
        if (mnt < 60) return mnt + ' phút trước';
        var h = Math.floor(mnt / 60);
        if (h < 24) return h + ' giờ trước';
        var day = Math.floor(h / 24);
        if (day === 1) return 'Hôm qua';
        if (day < 7) return day + ' ngày trước';
        return pad2(d.getDate()) + '/' + pad2(d.getMonth() + 1) + '/' + d.getFullYear();
    }

    /* ---------------- Định dạng chữ [b]/[i]/[u]/[color=..] — cùng quy ước với chat ---------------- */
    // Chạy trên text ĐÃ esc() (an toàn XSS: chỉ 4 mẫu cố định được thay, không chèn HTML tự do).
    function applyTextFormatDisplay(html) {
        html = html.replace(/\[color=(red|yellow|blue|green)\]([\s\S]*?)\[\/color\]/g, '<span class="dew-fmt-color-$1">$2</span>');
        html = html.replace(/\[b\]([\s\S]*?)\[\/b\]/g, '<strong>$1</strong>');
        html = html.replace(/\[i\]([\s\S]*?)\[\/i\]/g, '<em>$1</em>');
        html = html.replace(/\[u\]([\s\S]*?)\[\/u\]/g, '<span class="dew-fmt-underline">$1</span>');
        return html;
    }

    var DEW_FMT_COLORS = { red: '#ef4444', yellow: '#b45309', blue: '#2563eb', green: '#16a34a' };

    /** Chèn text thuần tại vị trí con trỏ trong 1 ô contenteditable đang giữ focus. */
    function insertAtCursor(el, text) {
        el.focus();
        document.execCommand('insertText', false, text);
    }

    /* --- Trạng thái ĐANG BẬT của các nút định dạng (anh Sáu chốt 13/8/2026) ---
       Nút nào đang áp dụng thì tô nền (.is-active) để biết chữ sắp gõ sẽ ra kiểu gì;
       bấm lại chính nút đó là tắt (B/I/U tự toggle; màu thì đổi ngược về ĐEN). */
    var rgbOfColorCache = null;
    /** '#ef4444' -> 'rgb(239, 68, 68)' (chuẩn hóa qua getComputedStyle để so khớp với
     *  giá trị queryCommandValue('foreColor') do trình duyệt trả về). */
    function normalizeColor(raw) {
        if (!raw) return '';
        var probe = document.createElement('span');
        probe.style.display = 'none';
        probe.style.color = raw;
        document.body.appendChild(probe);
        var out = getComputedStyle(probe).color;
        probe.remove();
        return out;
    }
    function rgbOfColor(key) {
        if (!rgbOfColorCache) {
            rgbOfColorCache = {};
            Object.keys(DEW_FMT_COLORS).forEach(function (k) { rgbOfColorCache[k] = normalizeColor(DEW_FMT_COLORS[k]); });
        }
        return rgbOfColorCache[key] || '';
    }
    function cmdState(name) {
        try { return !!document.queryCommandState(name); } catch (e) { return false; }
    }
    function currentForeColor() {
        try { return normalizeColor(document.queryCommandValue('foreColor')); } catch (e) { return ''; }
    }
    /** Vẽ lại trạng thái bật/tắt cho mọi nút trong 1 bảng định dạng, theo con trỏ hiện tại. */
    function syncFormatButtons(pop) {
        if (!pop) return;
        var cur = currentForeColor();
        var st = { b: cmdState('bold'), i: cmdState('italic'), u: cmdState('underline') };
        $all('.dew-fmt-btn', pop).forEach(function (btn) {
            var f = btn.getAttribute('data-fmt');
            var on = f === 'color' ? (cur !== '' && cur === rgbOfColor(btn.getAttribute('data-color'))) : !!st[f];
            btn.classList.toggle('is-active', on);
        });
    }
    /** Bảng định dạng đang gắn với 1 ô soạn (ô bình luận có bảng riêng, ô soạn bài dùng #dew-format-pop). */
    function formatPopOf(editable) {
        if (!editable) return null;
        if (editable.id === 'dew-compose-input') return $('#dew-format-pop');
        var wrap = editable.closest('.dew-comment-compose-wrap');
        return wrap ? wrap.querySelector('.dew-comment-fmt-pop') : null;
    }

    /** Áp định dạng THẬT (đậm/nghiêng/gạch chân/màu) lên đoạn đang bôi đen — hoặc lên
     *  ĐOẠN CHỮ SẮP GÕ nếu chưa bôi đen gì (execCommand giữ trạng thái cho lần gõ kế
     *  tiếp), giống hệt ô soạn tin nhắn của chat (xem public/js/shared/chat.js). */
    function applyTextFormat(editable, kind, color) {
        var sel = window.getSelection();
        if (!sel || !sel.rangeCount || !editable.contains(sel.anchorNode)) moveCursorToEnd(editable);
        else editable.focus();
        if (kind === 'color') {
            // Màu không tự toggle như B/I/U -> đang là màu đó mà bấm lại thì trả chữ về đen.
            var isOn = currentForeColor() === rgbOfColor(color);
            document.execCommand('foreColor', false, isOn ? '#000000' : DEW_FMT_COLORS[color]);
        }
        else if (kind === 'b') document.execCommand('bold', false, null);
        else if (kind === 'i') document.execCommand('italic', false, null);
        else if (kind === 'u') document.execCommand('underline', false, null);
        syncFormatButtons(formatPopOf(editable));
    }

    /** Đọc nội dung ô soạn (đã định dạng bằng thẻ HTML thật) ngược lại thành plain text
     *  kèm mã [b][i][u][color=..] để lưu/gửi lên server — xem applyTextFormatDisplay()
     *  ở chiều ngược lại khi hiển thị. Giống hệt serializeEditable() của chat.js. */
    function serializeEditable(root) {
        var colorRgbMap = null;
        function buildColorRgbMap() {
            colorRgbMap = {};
            var probe = document.createElement('span');
            probe.style.display = 'none';
            document.body.appendChild(probe);
            Object.keys(DEW_FMT_COLORS).forEach(function (k) {
                probe.style.color = DEW_FMT_COLORS[k];
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
            if (node.nodeType === 3) return node.nodeValue;
            if (node.nodeType !== 1) return '';
            var tag = node.tagName;
            if (tag === 'BR') return '\n';
            var inner = '';
            Array.prototype.forEach.call(node.childNodes, function (c) { inner += walk(c); });
            if (tag === 'DIV' || tag === 'P') inner = '\n' + inner;
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

    /** Nội dung raw ([b]/[i]/[u]/[color=..]) -> HTML để đổ vào 1 ô contenteditable đang SỬA
     *  (hiển thị định dạng THẬT thay vì thẻ ngoặc vuông thô) — không linkify #hashtag ở đây
     *  (tránh chèn <a> làm rối lúc gõ tiếp/serialize lại qua serializeEditable()). */
    function rawContentToEditableHtml(content) {
        return applyTextFormatDisplay(esc(content)).replace(/\n/g, '<br>');
    }
    /** Đưa con trỏ về cuối nội dung 1 ô contenteditable đang giữ focus. */
    function moveCursorToEnd(el) {
        el.focus();
        var range = document.createRange();
        range.selectNodeContents(el);
        range.collapse(false);
        var sel = window.getSelection();
        sel.removeAllRanges();
        sel.addRange(range);
    }

    /* ---------------- Nội dung: định dạng + xuống dòng + #hashtag click được ---------------- */
    function linkify(text) {
        var out = applyTextFormatDisplay(esc(text));
        out = out.replace(/\n/g, '<br>');
        out = out.replace(/#([\p{L}\p{N}_]{2,100})/gu, function (m, tag) {
            return '<a href="#" class="dew-hashtag" data-tag="' + esc(tag.toLowerCase()) + '">#' + esc(tag) + '</a>';
        });
        return out;
    }

    function avatarHtml(user, extraClass) {
        var av = (user && user.avatar) || '';
        var cls = 'dew-avatar' + (extraClass ? ' ' + extraClass : '');
        if (av) return '<img class="' + cls + '" src="' + esc(av) + '" alt="">';
        return '<span class="' + cls + ' dew-avatar-fallback"><i class="fa-solid fa-circle-user"></i></span>';
    }

    /* ---------------- Avatar người được gắn thẻ (tối đa 4, dư hiện +N) ---------------- */
    function tagAvatarsHtml(tags) {
        if (!tags || !tags.length) return '';
        var MAX = 4;
        var shown = tags.slice(0, MAX);
        var extra = tags.length - MAX;
        var html = shown.map(function (t) {
            if (t.avatar) return '<img class="dew-tag-avatar" src="' + esc(t.avatar) + '" alt="" title="' + esc(t.fullname) + '">';
            return '<span class="dew-tag-avatar dew-avatar-fallback" title="' + esc(t.fullname) + '"><i class="fa-solid fa-circle-user"></i></span>';
        }).join('');
        if (extra > 0) {
            var moreNames = tags.slice(MAX).map(function (t) { return t.fullname; }).join(', ');
            html += '<span class="dew-tag-avatar-more" title="' + esc(moreNames) + '">+' + extra + '</span>';
        }
        return '<div class="dew-post-tags"><i class="fa-solid fa-tag"></i> Đã gắn thẻ: <span class="dew-tag-avatars">' + html + '</span></div>';
    }

    /** Chip [{user_id,fullname}] (từ dữ liệu bài) hoặc [{id,fullname}] (từ data-orig-tags) -> HTML chip gắn thẻ khi sửa. */
    function editTagChipsHtml(tags) {
        return (tags || []).map(function (t) {
            var uid = t.user_id != null ? t.user_id : t.id;
            return '<span class="dew-tag-chip" data-id="' + uid + '">' + esc(t.fullname) + '<button type="button" data-id="' + uid + '">&times;</button></span>';
        }).join('');
    }

    /* ---------------- Ảnh / file đính kèm ---------------- */
    // data-att-id: id bản ghi de_wall_attachments — cần cho "Chia sẻ qua chat" ở khung xem ảnh.
    function renderAttachments(list) {
        if (!list || !list.length) return '';
        return '<div class="dew-media">' + list.map(function (a) {
            if (a.is_image) {
                return '<div class="dew-media-item dew-media-img"><img src="' + esc(a.file_url) + '" data-view="' + esc(a.file_url) + '"'
                    + ' data-att-id="' + (a.id || 0) + '" data-name="' + esc(a.name || '') + '" alt=""></div>';
            }
            return '<a class="dew-media-item dew-media-file" href="' + esc(a.file_url) + '" target="_blank" rel="noopener">' +
                '<i class="fa-solid fa-file-lines"></i><span>' + esc(a.name || 'Tệp đính kèm') + '</span></a>';
        }).join('') + '</div>';
    }

    /* ---------------- Cảm xúc (post + comment) ---------------- */
    function reactionsHtml(targetType, id, reactions) {
        if (!reactions || !reactions.length) return '';
        return '<div class="dew-reactions">' + reactions.map(function (r) {
            var who = (r.users && r.users.length) ? r.users.map(esc).join(', ') : '';
            var tip = who ? '<span class="dew-reaction-tip">' + who + '</span>' : '';
            return '<span class="dew-reaction-chip' + (r.mine ? ' mine' : '') + '" data-tt="' + targetType + '" data-tid="' + id + '" data-emoji="' + esc(r.emoji) + '">'
                + esc(r.emoji) + '<span class="cnt">' + r.count + '</span>' + tip + '</span>';
        }).join('') + '</div>';
    }
    function reactPopHtml(targetType, id) {
        return '<div class="dew-react-pop" data-tt="' + targetType + '" data-tid="' + id + '">' + QUICK_EMOJIS.map(function (em) {
            return '<button type="button" class="dew-react-pop-btn" data-tt="' + targetType + '" data-tid="' + id + '" data-emoji="' + em + '">' + em + '</button>';
        }).join('') + '</div>';
    }

    /* ---------------- Bình luận + trả lời (thụt lề, tối đa 1 cấp) ---------------- */
    function commentHtml(c, isReply) {
        var cls = 'dew-comment' + (isReply ? ' dew-comment-reply' : '');
        var moreMenu = c.can_delete
            ? '<div class="dew-comment-more-wrap">'
                + '<button type="button" class="dew-comment-more-btn" title="Tùy chọn"><i class="fa-solid fa-ellipsis"></i></button>'
                + '<div class="dew-comment-more-menu">'
                    + '<button type="button" class="dew-comment-del" data-id="' + c.id + '"><i class="fa-solid fa-trash"></i> Xóa</button>'
                + '</div>'
              + '</div>'
            : '';
        var replyBtn = !isReply ? '<button type="button" class="dew-comment-replybtn" data-id="' + c.id + '">Trả lời</button>' : '';
        var repliesHtml = (!isReply && c.replies && c.replies.length)
            ? '<div class="dew-comment-replies">' + c.replies.map(function (r) { return commentHtml(r, true); }).join('') + '</div>' : '';
        var replyBox = !isReply
            ? '<div class="dew-reply-compose" data-parent-id="' + c.id + '" style="display:none;">'
                + composeRowHtml('reply:' + c.id)
              + '</div>' : '';
        return '<div class="' + cls + '" data-id="' + c.id + '">'
            + avatarHtml(c.user, 'dew-avatar-sm')
            + '<div class="dew-comment-main">'
                + '<div class="dew-comment-bubble-row">'
                    + '<div class="dew-comment-bubble">'
                        + '<div class="dew-comment-name">' + esc(c.user.fullname) + '</div>'
                        + '<div class="dew-comment-text">' + linkify(c.content) + '</div>'
                    + '</div>'
                    + moreMenu
                + '</div>'
                + renderAttachments(c.attachments)
                + '<div class="dew-comment-meta">'
                    + '<span class="dew-comment-time">' + timeAgo(c.created_at) + '</span>'
                    + '<span class="dew-reactbtn-wrap">'
                        + '<button type="button" class="dew-comment-reactbtn" data-tt="comment" data-tid="' + c.id + '">Thích</button>'
                        + reactPopHtml('comment', c.id)
                    + '</span>'
                    + replyBtn
                    + reactionsHtml('comment', c.id, c.reactions)
                + '</div>'
                + replyBox
                + repliesHtml
            + '</div>'
        + '</div>';
    }

    function commentFmtPopHtml() {
        return '<div class="dew-comment-fmt-pop" style="display:none;">'
            + '<button type="button" class="dew-fmt-btn" data-fmt="b" title="Đậm"><b>B</b></button>'
            + '<button type="button" class="dew-fmt-btn" data-fmt="i" title="Nghiêng"><i>I</i></button>'
            + '<button type="button" class="dew-fmt-btn" data-fmt="u" title="Gạch chân"><u>U</u></button>'
            + '<span class="dew-fmt-sep"></span>'
            + '<button type="button" class="dew-fmt-btn dew-fmt-color" data-fmt="color" data-color="red" title="Đỏ" style="color:#ef4444;">A</button>'
            + '<button type="button" class="dew-fmt-btn dew-fmt-color" data-fmt="color" data-color="yellow" title="Vàng đậm" style="color:#b45309;">A</button>'
            + '<button type="button" class="dew-fmt-btn dew-fmt-color" data-fmt="color" data-color="blue" title="Xanh dương" style="color:#2563eb;">A</button>'
            + '<button type="button" class="dew-fmt-btn dew-fmt-color" data-fmt="color" data-color="green" title="Xanh lá" style="color:#16a34a;">A</button>'
        + '</div>';
    }
    /** Ô soạn bình luận / trả lời.
     *  - KHÔNG có avatar bên trái (anh Sáu chốt 13/8/2026): avatar chỉ xuất hiện ở bình
     *    luận ĐÃ đăng, còn ô nhập để trống cho rộng, nhất là trên điện thoại.
     *  - Ngoài ô nhập chỉ chừa nút CHỤP ẢNH (mở thẳng camera điện thoại). Cụm A / đính
     *    kèm / gửi nằm sau nút "..." — bấm mới xổ ra. */
    function composeRowHtml(key) {
        var n = (commentFiles[key] || []).length;
        return '<div class="dew-comment-compose" data-key="' + esc(key) + '">'
            + '<div class="dew-comment-compose-wrap">'
                + commentFmtPopHtml()
                + '<div class="dew-comment-compose-body">'
                    + '<div class="dew-comment-input dew-comment-input-editable" contenteditable="true" data-placeholder="Viết bình luận..."></div>'
                    + '<span class="dew-comment-filecount"' + (n ? '' : ' style="display:none;"') + '>' + n + ' tệp</span>'
                    + '<button type="button" class="dew-comment-camera" title="Chụp ảnh"><i class="fa-solid fa-camera"></i></button>'
                    + '<input type="file" class="dew-comment-file-input" multiple hidden>'
                    + '<input type="file" class="dew-comment-cam-input" accept="image/*" capture="environment" hidden>'
                    + '<div class="dew-comment-tools">'
                        + '<button type="button" class="dew-comment-fmt-toggle" title="Định dạng chữ"><i class="fa-solid fa-font"></i></button>'
                        + '<button type="button" class="dew-comment-attach" title="Đính kèm ảnh/file"><i class="fa-solid fa-paperclip"></i></button>'
                        + '<button type="button" class="dew-comment-send" title="Gửi"><i class="fa-solid fa-paper-plane"></i></button>'
                    + '</div>'
                    + '<button type="button" class="dew-comment-tools-toggle" title="Thêm tùy chọn"><i class="fa-solid fa-ellipsis"></i></button>'
                + '</div>'
            + '</div>'
        + '</div>';
    }

    /* ---------------- Bài đăng ---------------- */
    function postHtml(p) {
        var canPin = p.my_tag !== null && p.my_tag !== undefined;
        var pinBar = '';
        if (canPin) {
            pinBar = '<div class="dew-pin-bar"><button type="button" class="dew-btn dew-pin-btn" data-post-id="' + p.id + '">'
                + (p.my_tag.pinned
                    ? '<i class="fa-solid fa-thumbtack"></i> Gỡ khỏi tường của bạn'
                    : '<i class="fa-solid fa-thumbtack"></i> Gắn vào tường của bạn')
                + '</button></div>';
        }
        var tagsHtml = tagAvatarsHtml(p.tags);
        var editBtn = p.can_edit
            ? '<button type="button" class="dew-post-edit" data-id="' + p.id + '" title="Còn ' + Math.ceil((p.edit_seconds_left || 0) / 60) + ' phút để sửa"><i class="fa-solid fa-pen"></i></button>'
            : '';
        var delBtn = p.can_delete ? '<button type="button" class="dew-post-del" data-id="' + p.id + '" title="Xóa bài"><i class="fa-solid fa-trash"></i></button>' : '';

        // Chỉ hiện bình luận mới nhất; các bình luận cũ hơn ẩn sau nút "Xem thêm bình luận" ở trên cùng.
        var allComments = p.comments || [];
        var latestComment = allComments.length ? allComments[allComments.length - 1] : null;
        var olderComments = allComments.length > 1 ? allComments.slice(0, -1) : [];
        var moreToggle = olderComments.length
            ? '<button type="button" class="dew-comments-more" data-count="' + olderComments.length + '">'
                + '<i class="fa-solid fa-chevron-up"></i> Xem thêm ' + olderComments.length + ' bình luận</button>'
            : '';
        var olderHtml = olderComments.length
            ? '<div class="dew-comments-older" style="display:none;">' + olderComments.map(function (c) { return commentHtml(c, false); }).join('') + '</div>'
            : '';
        var latestHtml = latestComment ? commentHtml(latestComment, false) : '';

        var editBox = p.can_edit
            ? '<div class="dew-post-editbox" style="display:none;">'
                + '<div class="dew-edit-input" contenteditable="true" data-orig="' + esc(p.content) + '">' + rawContentToEditableHtml(p.content) + '</div>'
                + '<div class="dew-tag-picker dew-edit-tagpicker" data-orig-tags="' + esc(JSON.stringify((p.tags || []).map(function (t) { return { id: t.user_id, fullname: t.fullname }; }))) + '">'
                    + '<div class="dew-tag-chips dew-edit-tag-chips">' + editTagChipsHtml(p.tags) + '</div>'
                    + '<div class="dew-search-wrap">'
                        + '<i class="fa-solid fa-user-tag dew-search-icon"></i>'
                        + '<input type="text" class="dew-search-input dew-edit-tag-input" placeholder="Gắn thẻ người dùng khác..." autocomplete="off" spellcheck="false">'
                        + '<ul class="dew-dropdown dew-edit-tag-dropdown"></ul>'
                    + '</div>'
                + '</div>'
                + '<div class="wp-invoice-upload dew-dropzone dew-edit-dropzone">'
                    + '<div class="iu-dropzone">'
                        + '<i class="fa-solid fa-paste"></i>'
                        + '<p>Dán ảnh (Ctrl+V) hoặc kéo thả ảnh/file vào đây</p>'
                        + '<button type="button" class="iu-btn-choose">Chọn tệp</button>'
                        + '<button type="button" class="dew-cam-btn" title="Chụp ảnh từ điện thoại"><i class="fa-solid fa-camera"></i> Chụp ảnh</button>'
                        + '<input type="file" class="iu-input" multiple hidden>'
                        + '<input type="file" class="dew-cam-input" accept="image/*" capture="environment" hidden>'
                    + '</div>'
                    + '<div class="iu-preview"></div>'
                + '</div>'
                + '<div class="dew-edit-actions">'
                    + '<button type="button" class="dew-btn dew-edit-cancel">Hủy</button>'
                    + '<button type="button" class="dew-btn dew-btn-primary dew-edit-save" data-id="' + p.id + '">Lưu</button>'
                + '</div>'
              + '</div>'
            : '';

        return '<article class="dew-post" data-id="' + p.id + '">'
            + '<div class="dew-post-head">'
                + avatarHtml(p.user)
                + '<div class="dew-post-headinfo">'
                    + '<div class="dew-post-name">' + esc(p.user.fullname) + '</div>'
                    + '<div class="dew-post-time">' + timeAgo(p.created_at) + '</div>'
                + '</div>'
                + editBtn + delBtn
            + '</div>'
            + (p.content || p.can_edit ? '<div class="dew-post-content">' + linkify(p.content) + '</div>' : '')
            + editBox
            + renderAttachments(p.attachments)
            + tagsHtml
            + pinBar
            + reactionsHtml('post', p.id, p.reactions)
            + '<div class="dew-post-actions">'
                + '<button type="button" class="dew-post-reactbtn" data-tt="post" data-tid="' + p.id + '"><i class="fa-regular fa-face-smile"></i> Thích</button>'
                + '<button type="button" class="dew-post-commentbtn"><i class="fa-regular fa-comment"></i> Bình luận (' + p.comment_count + ')</button>'
                + '<button type="button" class="dew-post-sharebtn" data-id="' + p.id + '"><i class="fa-solid fa-share-nodes"></i> Chia sẻ</button>'
            + '</div>'
            + '<div class="dew-comments">'
                + moreToggle
                + olderHtml
                + latestHtml
                + composeRowHtml('post:' + p.id)
            + '</div>'
        + '</article>';
    }

    /* ---------------- Render Tường ---------------- */
    function renderFeed() {
        var feed = $('#dew-feed');
        feed.innerHTML = state.rows.map(postHtml).join('');
        $('#dew-feed-empty').style.display = state.rows.length ? 'none' : '';
        $('#dew-load-more').style.display = state.rows.length < state.total ? '' : 'none';
        rememberSignatures();
    }

    function renderPermalink() {
        var box = $('#dew-permalink');
        var p = BOOT.permalink;
        if (!p) { box.style.display = 'none'; box.innerHTML = ''; return; }
        box.style.display = '';
        box.innerHTML = '<div class="dew-permalink-badge"><i class="fa-solid fa-link"></i> Bài viết bạn được gắn thẻ (mở từ thông báo)</div>' + postHtml(p);
        rememberSignatures();
    }

    /* ---------------- Realtime: poll cảm xúc/bình luận cho các bài đang hiển thị ---------------- */
    var postSignatures = {}; // id -> chữ ký nội dung lần vẽ gần nhất (so lệch để biết cần vẽ lại)

    /** Bỏ các trường "trôi" theo thời gian (không liên quan nội dung) trước khi so sánh. */
    function postSignature(p) {
        var clone = JSON.parse(JSON.stringify(p));
        delete clone.edit_seconds_left;
        delete clone.can_edit;
        return JSON.stringify(clone);
    }

    function rememberSignatures() {
        state.rows.forEach(function (p) { postSignatures[p.id] = postSignature(p); });
        if (BOOT.permalink) postSignatures[BOOT.permalink.id] = postSignature(BOOT.permalink);
    }

    /** Lưu lại nội dung đang gõ dở (chưa gửi) trong các ô bình luận/trả lời của 1 bài, để khôi phục sau khi vẽ lại. */
    function saveComposeState(postEl) {
        var map = {};
        $all('.dew-comment-compose', postEl).forEach(function (box) {
            var key = box.getAttribute('data-key');
            var input = box.querySelector('.dew-comment-input');
            var bodyEl = box.querySelector('.dew-comment-compose-body');
            var toolsOpen = !!bodyEl && bodyEl.classList.contains('is-tools-open');
            if ((input && input.innerHTML) || toolsOpen) {
                map[key] = {
                    html: input ? input.innerHTML : '',
                    focused: document.activeElement === input,
                    toolsOpen: toolsOpen
                };
            }
        });
        return map;
    }
    function restoreComposeState(postEl, map) {
        Object.keys(map).forEach(function (key) {
            var box = $all('.dew-comment-compose', postEl).filter(function (b) { return b.getAttribute('data-key') === key; })[0];
            var input = box && box.querySelector('.dew-comment-input');
            if (!input) return;
            input.innerHTML = map[key].html;
            if (map[key].toolsOpen) {
                var bodyEl = box.querySelector('.dew-comment-compose-body');
                if (bodyEl) bodyEl.classList.add('is-tools-open');
            }
            if (map[key].focused) moveCursorToEnd(input);
        });
    }

    /** Vẽ lại đúng 1 bài (không đụng các bài khác) — dùng cho poll realtime + sau khi thêm/xóa bình luận/cảm xúc. */
    function updatePostInPlace(p) {
        var findIn = function (root) { return root.querySelector('.dew-post[data-id="' + p.id + '"]'); };
        var el = findIn($('#dew-feed')) || findIn($('#dew-permalink'));
        if (!el) return;

        // Đang sửa dở bài -> bỏ qua lượt này, tránh mất nội dung đang gõ.
        var editBox = el.querySelector('.dew-post-editbox');
        if (editBox && editBox.style.display !== 'none') return;

        var openReplyIds = $all('.dew-reply-compose', el)
            .filter(function (b) { return b.style.display !== 'none'; })
            .map(function (b) { return b.getAttribute('data-parent-id'); });
        var olderBoxEl = el.querySelector('.dew-comments-older');
        var olderWasOpen = !!olderBoxEl && olderBoxEl.style.display !== 'none';
        var saved = saveComposeState(el);

        var wrap = document.createElement('div');
        wrap.innerHTML = postHtml(p);
        var newEl = wrap.firstChild;
        el.replaceWith(newEl);

        openReplyIds.forEach(function (pid) {
            var box = newEl.querySelector('.dew-reply-compose[data-parent-id="' + pid + '"]');
            if (box) box.style.display = '';
        });
        if (olderWasOpen) {
            var newOlder = newEl.querySelector('.dew-comments-older');
            var newToggle = newEl.querySelector('.dew-comments-more');
            if (newOlder) newOlder.style.display = '';
            if (newToggle) newToggle.innerHTML = '<i class="fa-solid fa-chevron-down"></i> Ẩn bớt bình luận';
        }
        restoreComposeState(newEl, saved);
        postSignatures[p.id] = postSignature(p);
    }

    // postId -> mốc thời gian thay đổi cục bộ gần nhất (đăng/sửa/xóa bình luận, cảm xúc...).
    // Poll có thể mất vài trăm ms để về tới nơi; nếu trong lúc đó máy này đã tự cập nhật
    // mới hơn rồi thì bỏ bản poll đó đi (tránh "giật lùi" nội dung vừa gửi, xem yêu cầu
    // "sau khi trả lời bình luận .dew-comment-compose tự nhảy xuống dưới").
    var lastLocalTouch = {};
    function touchLocal(postId) { lastLocalTouch[postId] = Date.now(); }

    // Trả Promise<boolean>: true = có bài đổi (AppPoll dùng để đặt lại chu kỳ nhanh).
    function pollFeed() {
        var ids = state.rows.map(function (r) { return r.id; });
        if (BOOT.permalink) ids.push(BOOT.permalink.id);
        if (!ids.length) return false;
        var requestedAt = Date.now();
        return api('wall_poll', { ids: ids }).then(function (j) {
            if (!j || !j.success) return false;
            var coDoi = false;
            (j.rows || []).forEach(function (p) {
                if (lastLocalTouch[p.id] && lastLocalTouch[p.id] > requestedAt) return; // đã có bản mới hơn rồi
                if (postSignatures[p.id] === postSignature(p)) return; // không đổi gì -> khỏi vẽ lại
                var idx = state.rows.findIndex(function (r) { return r.id === p.id; });
                if (idx >= 0) state.rows[idx] = p;
                if (BOOT.permalink && BOOT.permalink.id === p.id) BOOT.permalink = p;
                updatePostInPlace(p);
                coDoi = true;
            });
            return coDoi;
        });
    }

    /* Chu kỳ 15s (trước 5s), tự giãn tới 45s khi cả Tường không có gì mới, và DỪNG HẲN
       khi tab/PWA bị ẩn — quay lại màn hình là đồng bộ ngay 1 lượt (AppPoll, app_shell.js). */
    var pollTimer = null, pollJob = null;
    function startPoll() {
        stopPoll();
        if (window.AppPoll) pollJob = window.AppPoll.every('dew-feed', pollFeed, { interval: 15000, maxInterval: 45000 });
        else pollTimer = setInterval(pollFeed, 15000);
    }
    function stopPoll() {
        if (pollJob) { pollJob.stop(); pollJob = null; }
        if (pollTimer) { clearInterval(pollTimer); pollTimer = null; }
    }

    /* ---------------- Tải trang / tìm theo hashtag ---------------- */
    function reloadFeed(page) {
        return api('wall_post_list', { page: page || 1, hashtag: state.hashtag, keyword: state.keyword }).then(function (j) {
            if (!j || !j.success) return;
            if ((page || 1) === 1) state.rows = j.rows || [];
            else state.rows = state.rows.concat(j.rows || []);
            state.total = j.total || 0;
            state.page = j.page || 1;
            renderFeed();
        });
    }

    /* ---------------- Modal đăng bài ---------------- */
    function openComposeModal() {
        var modal = $('#dew-compose-modal');
        modal.classList.add('is-open');
        modal.setAttribute('aria-hidden', 'false');
        setTimeout(function () { $('#dew-compose-input').focus(); }, 50);
    }
    function closeComposeModal() {
        var modal = $('#dew-compose-modal');
        modal.classList.remove('is-open');
        modal.setAttribute('aria-hidden', 'true');
    }

    /* ---------------- Ô soạn bài ---------------- */
    var composeDropzone = null;

    function renderComposerTags() {
        $('#dew-compose-tag-chips').innerHTML = composerTags.map(function (t) {
            return '<span class="dew-tag-chip">' + esc(t.fullname) + '<button type="button" data-id="' + t.id + '">&times;</button></span>';
        }).join('');
    }

    function wireTagPicker(inputEl, dropdownEl, onPick) {
        var items = [];
        var activeIdx = -1;

        function close() { dropdownEl.innerHTML = ''; dropdownEl.classList.remove('is-open'); activeIdx = -1; items = []; }
        function highlight() {
            $all('li', dropdownEl).forEach(function (li, i) { li.classList.toggle('is-active', i === activeIdx); });
        }
        function pick(i) {
            var it = items[i];
            if (!it) return;
            onPick(it);
            inputEl.value = '';
            close();
        }
        var search = debounce(function () {
            var kw = inputEl.value.trim();
            if (!kw) { close(); return; }
            api('search_users', { keyword: kw }).then(function (j) {
                items = (j && j.data) || [];
                dropdownEl.innerHTML = items.map(function (it, i) {
                    return '<li data-idx="' + i + '">' + esc(it.fullname) + '</li>';
                }).join('') || '<li class="dew-dropdown-empty">Không có kết quả</li>';
                dropdownEl.classList.add('is-open');
                activeIdx = -1;
            });
        }, 250);
        inputEl.addEventListener('input', search);
        inputEl.addEventListener('keydown', function (e) {
            if (!dropdownEl.classList.contains('is-open') || !items.length) return;
            if (e.key === 'ArrowDown') { e.preventDefault(); activeIdx = (activeIdx + 1) % items.length; highlight(); }
            else if (e.key === 'ArrowUp') { e.preventDefault(); activeIdx = (activeIdx - 1 + items.length) % items.length; highlight(); }
            else if (e.key === 'Enter' || e.key === 'Tab') { if (activeIdx >= 0) { e.preventDefault(); pick(activeIdx); } }
            else if (e.key === 'Escape') { close(); }
        });
        dropdownEl.addEventListener('click', function (e) {
            var li = e.target.closest('li[data-idx]');
            if (li) pick(parseInt(li.getAttribute('data-idx'), 10));
        });
        document.addEventListener('click', function (e) {
            if (e.target !== inputEl && !dropdownEl.contains(e.target)) close();
        });
    }

    /** Gợi ý #hashtag đã dùng trước đây khi đang gõ trong ô soạn bài (bộ nhớ hashtag). */
    function wireHashtagSuggest(editable, dropdownEl) {
        var items = [];
        var activeIdx = -1;
        var pendingLen = 0; // độ dài "#..." đang gõ (kể cả dấu #), để xóa lùi khi chọn gợi ý

        function isOpen() { return dropdownEl.classList.contains('is-open'); }
        function close() { dropdownEl.innerHTML = ''; dropdownEl.classList.remove('is-open'); items = []; activeIdx = -1; pendingLen = 0; }
        function highlight() { $all('li', dropdownEl).forEach(function (li, i) { li.classList.toggle('is-active', i === activeIdx); }); }

        /** Text thuần từ đầu ô soạn tới vị trí con trỏ hiện tại (bỏ qua thẻ định dạng). */
        function textBeforeCaret() {
            var sel = window.getSelection();
            if (!sel.rangeCount || !editable.contains(sel.anchorNode)) return '';
            var caret = sel.getRangeAt(0).cloneRange();
            caret.collapse(true);
            var pre = document.createRange();
            pre.selectNodeContents(editable);
            pre.setEnd(caret.endContainer, caret.endOffset);
            return pre.toString();
        }

        function pick(i) {
            var tag = items[i];
            if (tag == null) return;
            editable.focus();
            // Xóa lùi đúng "#..." đang gõ rồi chèn tag đầy đủ — execCommand tự xử lý đúng
            // dù đoạn đó cắt ngang nhiều node định dạng, khỏi phải tự thao tác Range thủ công.
            for (var n = 0; n < pendingLen; n++) document.execCommand('delete', false, null);
            document.execCommand('insertText', false, '#' + tag + ' ');
            close();
        }
        var search = debounce(function () {
            var before = textBeforeCaret();
            var m = before.match(/#([\p{L}\p{N}_]{0,100})$/u);
            if (!m) { close(); return; }
            pendingLen = m[0].length;
            api('wall_hashtag_suggest', { keyword: m[1] }).then(function (j) {
                items = (j && j.data) || [];
                if (!items.length) { close(); return; }
                dropdownEl.innerHTML = items.map(function (tag, i) {
                    return '<li data-idx="' + i + '">#' + esc(tag) + '</li>';
                }).join('');
                dropdownEl.classList.add('is-open');
                activeIdx = -1;
            });
        }, 200);
        editable.addEventListener('input', search);
        dropdownEl.addEventListener('click', function (e) {
            var li = e.target.closest('li[data-idx]');
            if (li) pick(parseInt(li.getAttribute('data-idx'), 10));
        });
        document.addEventListener('click', function (e) {
            if (e.target !== editable && !dropdownEl.contains(e.target)) close();
        });

        return {
            // true = đã xử lý phím này (điều hướng/chọn gợi ý) -> nơi gọi không nên xử lý thêm (vd Enter đăng bài).
            handleKeydown: function (e) {
                if (!isOpen() || !items.length) return false;
                if (e.key === 'ArrowDown') { e.preventDefault(); activeIdx = (activeIdx + 1) % items.length; highlight(); return true; }
                if (e.key === 'ArrowUp') { e.preventDefault(); activeIdx = (activeIdx - 1 + items.length) % items.length; highlight(); return true; }
                if (e.key === 'Enter' && !e.altKey && !e.shiftKey) { e.preventDefault(); if (activeIdx >= 0) pick(activeIdx); else close(); return true; }
                if (e.key === 'Escape') { close(); return true; }
                return false;
            }
        };
    }

    function submitPost() {
        var input = $('#dew-compose-input');
        var content = serializeEditable(input).trim();
        var files = composeDropzone ? composeDropzone.getFiles() : [];
        var msgEl = $('#dew-compose-msg');
        msgEl.textContent = '';
        if (!content && !files.length) { msgEl.textContent = 'Vui lòng nhập nội dung hoặc đính kèm ảnh/file.'; return; }

        var btn = $('#dew-compose-submit');
        btn.disabled = true;
        api('wall_post_add', {
            content: content,
            tagged: composerTags.map(function (t) { return t.id; }),
            has_attachments: files.length ? 1 : 0
        }).then(function (res) {
            if (!res || !res.success) { toast((res && res.message) || 'Không thể đăng bài.', false); btn.disabled = false; return; }
            var afterUpload = files.length ? uploadFiles('post', res.id, files) : Promise.resolve();
            afterUpload.then(function () {
                input.innerHTML = '';
                composerTags = [];
                renderComposerTags();
                if (composeDropzone) composeDropzone.clear();
                btn.disabled = false;
                closeComposeModal();
                reloadFeed(1);
                toast('Đã đăng bài.');
            });
        });
    }

    function uploadFiles(ownerType, ownerId, files) {
        if (!files || !files.length) return Promise.resolve();
        var fd = new FormData();
        fd.append('owner_type', ownerType);
        fd.append('owner_id', ownerId);
        files.forEach(function (f) { fd.append('files[]', f); });
        return api('wall_upload_add', fd, true);
    }

    /* ---------------- Bình luận: gửi + đính kèm ---------------- */
    function handleCommentSend(box) {
        var key = box.getAttribute('data-key');
        var isReply = key.indexOf('reply:') === 0;
        var postId, parentId = 0;
        if (isReply) {
            parentId = parseInt(key.split(':')[1], 10);
            postId = parseInt(box.closest('.dew-post').getAttribute('data-id'), 10);
        } else {
            postId = parseInt(key.split(':')[1], 10);
        }
        var input = $('.dew-comment-input', box);
        var content = serializeEditable(input).trim();
        var files = commentFiles[key] || [];
        if (!content && !files.length) return;

        api('wall_comment_add', { post_id: postId, content: content, parent_id: parentId }).then(function (res) {
            if (!res || !res.success) { toast((res && res.message) || 'Không thể gửi bình luận.', false); return; }
            var afterUpload = files.length ? uploadFiles('comment', res.id, files) : Promise.resolve();
            afterUpload.then(function () {
                delete commentFiles[key];
                input.innerHTML = '';
                refreshPost(postId);
            });
        });
    }

    /** Ẩn lại các ô "Trả lời" đang mở mà chưa gõ gì (và chưa đính kèm tệp) khi click ra ngoài. */
    function closeEmptyReplyBoxes(exceptEl) {
        $all('.dew-reply-compose').forEach(function (box) {
            if (box === exceptEl || box.style.display === 'none') return;
            var compose = box.querySelector('.dew-comment-compose');
            var input = box.querySelector('.dew-comment-input');
            var key = compose ? compose.getAttribute('data-key') : null;
            var hasFiles = key && commentFiles[key] && commentFiles[key].length;
            if (input && !input.textContent.trim() && !hasFiles) box.style.display = 'none';
        });
    }

    /* ---------------- Sửa bài: nội dung + gắn thẻ ---------------- */
    function editBoxTagIds(box) {
        return $all('.dew-edit-tag-chips .dew-tag-chip', box).map(function (c) { return parseInt(c.getAttribute('data-id'), 10); });
    }
    function editBoxOrigTagIds(box) {
        var picker = box.querySelector('.dew-edit-tagpicker');
        if (!picker) return [];
        try { return (JSON.parse(picker.getAttribute('data-orig-tags')) || []).map(function (t) { return t.id; }); }
        catch (e) { return []; }
    }
    function resetEditTagChips(box) {
        var picker = box.querySelector('.dew-edit-tagpicker');
        var chipsEl = box.querySelector('.dew-edit-tag-chips');
        if (!picker || !chipsEl) return;
        var orig;
        try { orig = JSON.parse(picker.getAttribute('data-orig-tags')) || []; } catch (e) { orig = []; }
        chipsEl.innerHTML = editTagChipsHtml(orig);
    }
    function editBoxDropzone(box) {
        if (box.__editDropzone) return box.__editDropzone;
        var root = box.querySelector('.dew-edit-dropzone');
        if (!root || !window.InvoiceDropzone) return null;
        box.__editDropzone = window.InvoiceDropzone.create(root);
        return box.__editDropzone;
    }
    function editBoxChanged(box) {
        var ta = box.querySelector('.dew-edit-input');
        if (serializeEditable(ta).trim() !== (ta.getAttribute('data-orig') || '').trim()) return true;
        var cur = editBoxTagIds(box).slice().sort();
        var orig = editBoxOrigTagIds(box).slice().sort();
        if (cur.length !== orig.length) return true;
        for (var i = 0; i < cur.length; i++) if (cur[i] !== orig[i]) return true;
        if (box.__editDropzone && box.__editDropzone.count()) return true;
        return false;
    }
    function saveEditBox(box, postId) {
        var newContent = serializeEditable(box.querySelector('.dew-edit-input')).trim();
        var tagIds = editBoxTagIds(box);
        var dz = box.__editDropzone;
        var files = dz ? dz.getFiles() : [];
        api('wall_post_update', { id: postId, content: newContent, tagged: tagIds }).then(function (res) {
            if (!res || !res.success) { toast((res && res.message) || 'Không thể lưu.', false); return; }
            var afterUpload = files.length ? uploadFiles('post', postId, files) : Promise.resolve();
            afterUpload.then(function () {
                if (dz) dz.clear();
                refreshPost(postId);
                toast('Đã lưu thay đổi.');
            });
        });
    }
    function cancelEditBox(box) {
        var ta = box.querySelector('.dew-edit-input');
        ta.innerHTML = rawContentToEditableHtml(ta.getAttribute('data-orig') || '');
        resetEditTagChips(box);
        if (box.__editDropzone) box.__editDropzone.clear();
    }
    /** Click ra ngoài ô đang sửa: có thay đổi -> tự lưu; không đổi gì -> hủy về ban đầu. */
    function closeEditBoxesIfOpen(exceptBox) {
        $all('.dew-post-editbox').forEach(function (box) {
            if (box === exceptBox || box.style.display === 'none') return;
            var postEl = box.closest('.dew-post');
            var postId = postEl ? parseInt(postEl.getAttribute('data-id'), 10) : 0;
            var changed = editBoxChanged(box);
            box.style.display = 'none';
            var contentEl = postEl && postEl.querySelector('.dew-post-content');
            if (contentEl) contentEl.style.display = '';
            if (changed) saveEditBox(box, postId);
            else cancelEditBox(box);
        });
    }

    /** Tải lại 1 bài (sau khi thêm bình luận/trả lời/xóa) — cập nhật tại chỗ, không vẽ lại cả Tường. */
    function refreshPost(postId) {
        api('wall_post_get', { id: postId }).then(function (j) {
            if (!j || !j.success || !j.data) { reloadFeed(1); return; }
            touchLocal(postId); // đánh dấu vừa có thay đổi tại máy này -> bản poll cũ hơn đang bay về sẽ bị bỏ qua
            var idx = state.rows.findIndex(function (r) { return r.id === postId; });
            if (idx >= 0) state.rows[idx] = j.data;
            if (BOOT.permalink && BOOT.permalink.id === postId) BOOT.permalink = j.data;
            updatePostInPlace(j.data);
        });
    }

    /* =====================================================================
     *  KHUNG XEM ẢNH (anh Sáu chốt 13/8/2026)
     *  Phóng to / thu nhỏ (lăn chuột, chụm 2 ngón, chạm 2 lần, nút +/−), kéo
     *  để xê dịch, xoay, TẢI XUỐNG và CHIA SẺ QUA CHAT. Viết riêng cho Tường
     *  (không dùng InvoiceViewer của hóa đơn) vì cần thêm 2 nút cuối + thao
     *  tác cảm ứng cho điện thoại.
     * ===================================================================== */
    var DewViewer = (function () {
        var box = null, img = null, stage = null, zoomLabel = null, nameLabel = null;
        var scale = 1, tx = 0, ty = 0, rot = 0;
        var cur = { url: '', attId: 0, name: '' };
        var MIN = 1, MAX = 8;
        var drag = null;          // {x, y, tx, ty} khi đang kéo bằng chuột/1 ngón
        var pinch = null;         // {dist, scale, cx, cy, tx, ty} khi đang chụm 2 ngón
        var lastTap = 0;

        function apply() {
            img.style.transform = 'translate(' + tx + 'px,' + ty + 'px) scale(' + scale + ') rotate(' + rot + 'deg)';
            if (zoomLabel) zoomLabel.textContent = Math.round(scale * 100) + '%';
        }
        function reset() { scale = 1; tx = 0; ty = 0; rot = 0; apply(); }
        function zoomAt(next, cx, cy) {
            next = Math.min(MAX, Math.max(MIN, next));
            var rect = img.getBoundingClientRect();
            var offX = cx - (rect.left + rect.width / 2);
            var offY = cy - (rect.top + rect.height / 2);
            if (next === MIN) { tx = 0; ty = 0; }
            else {
                var ratio = next / scale;
                tx = (tx - offX) * ratio + offX;
                ty = (ty - offY) * ratio + offY;
            }
            scale = next;
            apply();
        }
        function centerZoom(next) {
            var rect = stage.getBoundingClientRect();
            zoomAt(next, rect.left + rect.width / 2, rect.top + rect.height / 2);
        }

        function download() {
            if (!cur.url) return;
            var a = document.createElement('a');
            a.href = cur.url;
            a.download = cur.name || cur.url.split('/').pop() || 'hinh-anh';
            a.rel = 'noopener';
            document.body.appendChild(a);
            a.click();
            a.remove();
        }

        function share() {
            if (!cur.attId) { toast('Ảnh này chưa lưu trên máy chủ nên không chia sẻ được.', false); return; }
            DewShare.open({
                mode: 'chat',
                attachmentId: cur.attId,
                title: 'Chia sẻ ảnh qua chat',
                hint: 'Chọn người nhận — ảnh sẽ được gửi thành một tin nhắn.'
            });
        }

        function ensure() {
            if (box) return box;
            box = document.createElement('div');
            box.className = 'dew-viewer';
            box.innerHTML =
                '<div class="dew-viewer-bar">'
                    + '<span class="dew-viewer-name"></span>'
                    + '<div class="dew-viewer-tools">'
                        + '<button type="button" data-act="zoomout" title="Thu nhỏ"><i class="fa-solid fa-magnifying-glass-minus"></i></button>'
                        + '<span class="dew-viewer-zoom">100%</span>'
                        + '<button type="button" data-act="zoomin" title="Phóng to"><i class="fa-solid fa-magnifying-glass-plus"></i></button>'
                        + '<button type="button" data-act="rotate" title="Xoay"><i class="fa-solid fa-rotate-right"></i></button>'
                        + '<button type="button" data-act="download" title="Tải xuống"><i class="fa-solid fa-download"></i></button>'
                        + '<button type="button" data-act="share" title="Chia sẻ qua chat"><i class="fa-solid fa-share-nodes"></i></button>'
                        + '<button type="button" data-act="close" title="Đóng (Esc)"><i class="fa-solid fa-xmark"></i></button>'
                    + '</div>'
                + '</div>'
                + '<div class="dew-viewer-stage"><img src="" alt="" draggable="false"></div>';
            document.body.appendChild(box);
            img = box.querySelector('img');
            stage = box.querySelector('.dew-viewer-stage');
            zoomLabel = box.querySelector('.dew-viewer-zoom');
            nameLabel = box.querySelector('.dew-viewer-name');

            box.querySelector('.dew-viewer-tools').addEventListener('click', function (e) {
                var btn = e.target.closest('button[data-act]');
                if (!btn) return;
                var act = btn.getAttribute('data-act');
                if (act === 'zoomin') centerZoom(scale * 1.3);
                else if (act === 'zoomout') centerZoom(scale / 1.3);
                else if (act === 'rotate') { rot = (rot + 90) % 360; apply(); }
                else if (act === 'download') download();
                else if (act === 'share') share();
                else if (act === 'close') hide();
            });
            // Bấm ra vùng nền (không trúng ảnh) = đóng.
            stage.addEventListener('click', function (e) { if (e.target === stage) hide(); });
            document.addEventListener('keydown', function (e) {
                if (!box.classList.contains('is-open')) return;
                if (e.key === 'Escape') hide();
                else if (e.key === '+' || e.key === '=') centerZoom(scale * 1.3);
                else if (e.key === '-') centerZoom(scale / 1.3);
            });

            /* --- Chuột: lăn để zoom, giữ + kéo để xê dịch --- */
            stage.addEventListener('wheel', function (e) {
                e.preventDefault();
                zoomAt(scale * (e.deltaY < 0 ? 1.15 : 1 / 1.15), e.clientX, e.clientY);
            }, { passive: false });
            img.addEventListener('mousedown', function (e) {
                e.preventDefault();
                drag = { x: e.clientX, y: e.clientY, tx: tx, ty: ty };
                img.classList.add('is-dragging');
            });
            document.addEventListener('mousemove', function (e) {
                if (!drag) return;
                tx = drag.tx + (e.clientX - drag.x);
                ty = drag.ty + (e.clientY - drag.y);
                apply();
            });
            document.addEventListener('mouseup', function () {
                if (!drag) return;
                drag = null;
                img.classList.remove('is-dragging');
            });
            img.addEventListener('dblclick', function (e) {
                e.preventDefault();
                if (scale > 1.05) reset(); else zoomAt(2.5, e.clientX, e.clientY);
            });

            /* --- Cảm ứng: 1 ngón kéo, 2 ngón chụm để zoom, chạm 2 lần để phóng nhanh --- */
            function dist(t) {
                var dx = t[0].clientX - t[1].clientX, dy = t[0].clientY - t[1].clientY;
                return Math.sqrt(dx * dx + dy * dy);
            }
            stage.addEventListener('touchstart', function (e) {
                if (e.touches.length === 2) {
                    pinch = { dist: dist(e.touches), scale: scale };
                    drag = null;
                } else if (e.touches.length === 1) {
                    var t = e.touches[0];
                    drag = { x: t.clientX, y: t.clientY, tx: tx, ty: ty };
                    var now = Date.now();
                    if (now - lastTap < 300) {
                        if (scale > 1.05) reset(); else zoomAt(2.5, t.clientX, t.clientY);
                        lastTap = 0;
                    } else lastTap = now;
                }
            }, { passive: true });
            stage.addEventListener('touchmove', function (e) {
                if (pinch && e.touches.length === 2) {
                    e.preventDefault();
                    var d = dist(e.touches);
                    if (pinch.dist > 0) {
                        var cx = (e.touches[0].clientX + e.touches[1].clientX) / 2;
                        var cy = (e.touches[0].clientY + e.touches[1].clientY) / 2;
                        zoomAt(pinch.scale * (d / pinch.dist), cx, cy);
                    }
                } else if (drag && e.touches.length === 1 && scale > 1.02) {
                    e.preventDefault(); // chỉ chặn cuộn khi đang phóng to (mới có gì để kéo)
                    tx = drag.tx + (e.touches[0].clientX - drag.x);
                    ty = drag.ty + (e.touches[0].clientY - drag.y);
                    apply();
                }
            }, { passive: false });
            stage.addEventListener('touchend', function (e) {
                if (e.touches.length === 0) { drag = null; pinch = null; }
            }, { passive: true });

            return box;
        }

        function hide() {
            if (!box) return;
            box.classList.remove('is-open');
            document.body.classList.remove('dew-noscroll');
            drag = null; pinch = null;
        }

        /** el = thẻ <img data-view=... data-att-id=... data-name=...>, hoặc chuỗi URL. */
        function open(el) {
            ensure();
            if (typeof el === 'string') cur = { url: el, attId: 0, name: '' };
            else cur = {
                url: el.getAttribute('data-view') || el.src,
                attId: parseInt(el.getAttribute('data-att-id'), 10) || 0,
                name: el.getAttribute('data-name') || ''
            };
            nameLabel.textContent = cur.name || '';
            img.src = cur.url;
            reset();
            box.classList.add('is-open');
            document.body.classList.add('dew-noscroll');
        }

        return { open: open, hide: hide };
    })();

    /* =====================================================================
     *  MODAL CHỌN NGƯỜI — dùng chung cho 2 việc:
     *   - mode 'post': chia sẻ lại BÀI ĐÃ ĐĂNG cho user khác (họ vào xem +
     *     bình luận được, nhận chuông).
     *   - mode 'chat': gửi ẢNH đang xem qua CHAT cho user khác.
     * ===================================================================== */
    var DewShare = (function () {
        var box = null, listEl = null, searchEl = null, submitEl = null, titleEl = null, hintEl = null, countEl = null;
        var opts = {}, picked = {}, items = [];

        function render() {
            listEl.innerHTML = items.length
                ? items.map(function (u) {
                    return '<button type="button" class="dew-share-item' + (picked[u.id] ? ' is-picked' : '') + '" data-id="' + u.id + '">'
                        + '<span class="dew-share-item-name">' + esc(u.fullname) + '</span>'
                        + '<i class="fa-solid fa-check"></i></button>';
                }).join('')
                : '<div class="dew-share-empty">Không tìm thấy người dùng.</div>';
            var n = Object.keys(picked).length;
            countEl.textContent = n ? 'Đã chọn ' + n + ' người' : '';
            submitEl.disabled = !n;
        }

        var search = debounce(function () {
            api('search_users', { keyword: searchEl.value.trim() }).then(function (j) {
                items = (j && j.data) || [];
                render();
            });
        }, 250);

        function ensure() {
            if (box) return box;
            box = document.createElement('div');
            box.className = 'dew-modal dew-share-modal';
            box.innerHTML =
                '<div class="dew-modal-overlay" data-share-close></div>'
                + '<div class="dew-modal-box">'
                    + '<div class="dew-modal-head">'
                        + '<h3 class="dew-share-title">Chia sẻ</h3>'
                        + '<button type="button" class="dew-modal-close" data-share-close aria-label="Đóng">&times;</button>'
                    + '</div>'
                    + '<div class="dew-modal-body">'
                        + '<p class="dew-share-hint"></p>'
                        + '<div class="dew-search-wrap">'
                            + '<i class="fa-solid fa-magnifying-glass dew-search-icon"></i>'
                            + '<input type="text" class="dew-search-input dew-share-search" placeholder="Tìm người dùng..." autocomplete="off" spellcheck="false">'
                        + '</div>'
                        + '<div class="dew-share-list"></div>'
                        + '<div class="dew-share-foot">'
                            + '<span class="dew-share-count"></span>'
                            + '<button type="button" class="dew-btn dew-btn-primary dew-share-submit"><i class="fa-solid fa-paper-plane"></i> Chia sẻ</button>'
                        + '</div>'
                    + '</div>'
                + '</div>';
            document.body.appendChild(box);
            listEl = box.querySelector('.dew-share-list');
            searchEl = box.querySelector('.dew-share-search');
            submitEl = box.querySelector('.dew-share-submit');
            titleEl = box.querySelector('.dew-share-title');
            hintEl = box.querySelector('.dew-share-hint');
            countEl = box.querySelector('.dew-share-count');

            $all('[data-share-close]', box).forEach(function (el) { el.addEventListener('click', close); });
            searchEl.addEventListener('input', search);
            listEl.addEventListener('click', function (e) {
                var it = e.target.closest('.dew-share-item');
                if (!it) return;
                var id = parseInt(it.getAttribute('data-id'), 10);
                if (picked[id]) delete picked[id]; else picked[id] = true;
                render();
            });
            submitEl.addEventListener('click', submit);
            document.addEventListener('keydown', function (e) {
                if (e.key === 'Escape' && box.classList.contains('is-open')) close();
            });
            return box;
        }

        function submit() {
            var ids = Object.keys(picked).map(Number);
            if (!ids.length) return;
            submitEl.disabled = true;
            var call = opts.mode === 'chat'
                ? api('wall_share_to_chat', { attachment_id: opts.attachmentId, user_ids: ids })
                : api('wall_share_add', { post_id: opts.postId, user_ids: ids });
            call.then(function (res) {
                submitEl.disabled = false;
                if (!res || !res.success) { toast((res && res.message) || 'Không chia sẻ được.', false); return; }
                toast(res.message || 'Đã chia sẻ.');
                close();
                if (opts.mode === 'post' && opts.postId) refreshPost(opts.postId);
            });
        }

        function open(o) {
            ensure();
            opts = o || {};
            opts.postId = opts.postId || 0;
            picked = {};
            items = [];
            titleEl.textContent = opts.title || 'Chia sẻ';
            hintEl.textContent = opts.hint || '';
            searchEl.value = '';
            render();
            box.classList.add('is-open');
            api('search_users', { keyword: '' }).then(function (j) {
                items = (j && j.data) || [];
                render();
            });
            setTimeout(function () { searchEl.focus(); }, 50);
        }
        function close() { if (box) box.classList.remove('is-open'); }

        return { open: open, close: close };
    })();

    /* ---------------- Sự kiện (event delegation) ---------------- */
    function wireGlobalEvents() {
        // Xem ảnh của bài / bình luận -> khung xem riêng của Tường (zoom, tải, chia sẻ chat).
        // Ảnh xem trước trong ô soạn (.iu-preview) KHÔNG đi đường này — vẫn do
        // InvoiceDropzone tự mở bằng InvoiceViewer (ảnh tạm, chưa có trên máy chủ).
        document.addEventListener('click', function (e) {
            if (!e.target.closest('#dew-feed, #dew-permalink')) return;
            var img = e.target.closest('img[data-view]');
            if (img) DewViewer.open(img);
        });

        document.addEventListener('click', function (e) {
            var root = e.target.closest('#dew-feed, #dew-permalink');
            if (!root) {
                // đóng popup emoji + menu "..." khi click ra ngoài
                $all('.dew-react-pop.is-open').forEach(function (p) { p.classList.remove('is-open'); });
                $all('.dew-comment-more-menu.is-open').forEach(function (m) { m.classList.remove('is-open'); });
                closeEmptyReplyBoxes(null);
                closeEditBoxesIfOpen(null);
                return;
            }

            // Đóng menu "..." nếu click ra ngoài chính nó (nhưng vẫn trong Tường).
            if (!e.target.closest('.dew-comment-more-wrap')) {
                $all('.dew-comment-more-menu.is-open').forEach(function (m) { m.classList.remove('is-open'); });
            }
            // Đóng các ô trả lời đang mở mà chưa gõ gì, nếu click ra ngoài chính ô đó.
            closeEmptyReplyBoxes(e.target.closest('.dew-reply-compose'));
            // Ô đang sửa bài: click ra ngoài chính ô đó -> tự lưu (nếu có đổi) hoặc hủy.
            closeEditBoxesIfOpen(e.target.closest('.dew-post-editbox'));

            var hashtag = e.target.closest('.dew-hashtag');
            if (hashtag) {
                e.preventDefault();
                var tag = hashtag.getAttribute('data-tag');
                $('#dew-hashtag-search').value = '#' + tag;
                state.hashtag = tag; state.keyword = '';
                reloadFeed(1);
                return;
            }

            var reactBtn = e.target.closest('.dew-post-reactbtn');
            if (reactBtn) {
                e.preventDefault();
                var existing = reactBtn.parentNode.querySelector('.dew-react-pop');
                $all('.dew-react-pop.is-open').forEach(function (p) { if (p !== existing) p.classList.remove('is-open'); });
                if (existing) { existing.classList.toggle('is-open'); return; }
                var pop = document.createElement('div');
                pop.innerHTML = reactPopHtml(reactBtn.getAttribute('data-tt'), reactBtn.getAttribute('data-tid'));
                var popEl = pop.firstChild;
                popEl.classList.add('is-open');
                reactBtn.parentNode.style.position = 'relative';
                reactBtn.parentNode.appendChild(popEl);
                return;
            }

            // "Thích" của bình luận: hover đã hiện sẵn bảng emoji (CSS) để chọn; bấm thẳng
            // vào chữ "Thích" = thả nhanh 👍 (bấm lại = gỡ), giống Facebook.
            var commentReactBtn = e.target.closest('.dew-comment-reactbtn');
            if (commentReactBtn) {
                e.preventDefault();
                var ctt = commentReactBtn.getAttribute('data-tt');
                var ctid = parseInt(commentReactBtn.getAttribute('data-tid'), 10);
                api('wall_react', { target_type: ctt, target_id: ctid, emoji: '👍' }).then(function () {
                    var postEl0 = commentReactBtn.closest('.dew-post');
                    if (postEl0) refreshPost(parseInt(postEl0.getAttribute('data-id'), 10));
                });
                return;
            }

            var moreBtn2 = e.target.closest('.dew-comment-more-btn');
            if (moreBtn2) {
                e.preventDefault();
                var menu = moreBtn2.nextElementSibling;
                var wasOpen = menu.classList.contains('is-open');
                $all('.dew-comment-more-menu.is-open').forEach(function (m) { m.classList.remove('is-open'); });
                if (!wasOpen) menu.classList.add('is-open');
                return;
            }

            var popBtn = e.target.closest('.dew-react-pop-btn, .dew-reaction-chip');
            if (popBtn) {
                e.preventDefault();
                var tt = popBtn.getAttribute('data-tt');
                var tid = parseInt(popBtn.getAttribute('data-tid'), 10);
                var emoji = popBtn.getAttribute('data-emoji');
                api('wall_react', { target_type: tt, target_id: tid, emoji: emoji }).then(function () {
                    var postEl = e.target.closest('.dew-post');
                    var postId = postEl ? parseInt(postEl.getAttribute('data-id'), 10) : tid;
                    refreshPost(postId);
                });
                $all('.dew-react-pop.is-open').forEach(function (p) { p.classList.remove('is-open'); });
                return;
            }

            var commentToggle = e.target.closest('.dew-post-commentbtn');
            if (commentToggle) {
                var box = commentToggle.closest('.dew-post').querySelector('.dew-comments > .dew-comment-compose .dew-comment-input');
                if (box) box.focus();
                return;
            }

            var moreBtn = e.target.closest('.dew-comments-more');
            if (moreBtn) {
                var olderBox = moreBtn.parentNode.querySelector('.dew-comments-older');
                if (olderBox) {
                    var willOpen = olderBox.style.display === 'none';
                    olderBox.style.display = willOpen ? '' : 'none';
                    moreBtn.innerHTML = willOpen
                        ? '<i class="fa-solid fa-chevron-down"></i> Ẩn bớt bình luận'
                        : '<i class="fa-solid fa-chevron-up"></i> Xem thêm ' + moreBtn.getAttribute('data-count') + ' bình luận';
                }
                return;
            }

            var replyBtn = e.target.closest('.dew-comment-replybtn');
            if (replyBtn) {
                var replyBox = replyBtn.closest('.dew-comment').querySelector('.dew-reply-compose');
                if (replyBox) {
                    replyBox.style.display = replyBox.style.display === 'none' ? '' : 'none';
                    if (replyBox.style.display !== 'none') replyBox.querySelector('.dew-comment-input').focus();
                }
                return;
            }

            var sendBtn = e.target.closest('.dew-comment-send');
            if (sendBtn) { handleCommentSend(sendBtn.closest('.dew-comment-compose')); return; }

            var attachBtn = e.target.closest('.dew-comment-attach');
            if (attachBtn) { attachBtn.closest('.dew-comment-compose').querySelector('.dew-comment-file-input').click(); return; }

            // Chụp ảnh trực tiếp bằng camera điện thoại rồi đính kèm luôn vào bình luận.
            var camBtn = e.target.closest('.dew-comment-camera');
            if (camBtn) { camBtn.closest('.dew-comment-compose').querySelector('.dew-comment-cam-input').click(); return; }

            // "..." xổ ra cụm A / đính kèm / gửi (mặc định giấu để ô nhập rộng).
            var toolsBtn = e.target.closest('.dew-comment-tools-toggle');
            if (toolsBtn) {
                var bodyEl = toolsBtn.closest('.dew-comment-compose-body');
                var willOpen = !bodyEl.classList.contains('is-tools-open');
                $all('.dew-comment-compose-body.is-tools-open').forEach(function (b) { b.classList.remove('is-tools-open'); });
                bodyEl.classList.toggle('is-tools-open', willOpen);
                return;
            }

            // Xóa bình luận: KHÔNG hỏi xác nhận (anh Sáu chốt 13/8/2026) — bình luận tan
            // biến nhẹ nhàng tại chỗ, vẽ lại bài sau khi hiệu ứng chạy xong.
            var delComment = e.target.closest('.dew-comment-del');
            if (delComment) {
                var postEl2 = delComment.closest('.dew-post');
                var postId2 = postEl2 ? parseInt(postEl2.getAttribute('data-id'), 10) : 0;
                var cmtEl = delComment.closest('.dew-comment');
                if (cmtEl) cmtEl.classList.add('is-vanishing');
                $all('.dew-comment-more-menu.is-open').forEach(function (m) { m.classList.remove('is-open'); });
                api('wall_comment_delete', { id: delComment.getAttribute('data-id') }).then(function (res) {
                    if (!res || !res.success) {
                        if (cmtEl) cmtEl.classList.remove('is-vanishing');
                        toast((res && res.message) || 'Không thể xóa.', false);
                        return;
                    }
                    setTimeout(function () { refreshPost(postId2); }, 260); // chờ hiệu ứng tan biến
                });
                return;
            }

            // Chia sẻ lại bài (kể cả bài đăng từ lâu) cho user khác vào xem + bình luận.
            var shareBtn = e.target.closest('.dew-post-sharebtn');
            if (shareBtn) {
                DewShare.open({
                    mode: 'post',
                    postId: parseInt(shareBtn.getAttribute('data-id'), 10),
                    title: 'Chia sẻ bài viết',
                    hint: 'Người được chọn sẽ nhận thông báo và vào xem, bình luận được bài này.'
                });
                return;
            }

            var editBtn2 = e.target.closest('.dew-post-edit');
            if (editBtn2) {
                var postEl3 = editBtn2.closest('.dew-post');
                var box3 = postEl3.querySelector('.dew-post-editbox');
                closeEditBoxesIfOpen(box3); // đóng ô sửa khác (nếu có) trước khi mở ô này
                postEl3.querySelector('.dew-post-content').style.display = 'none';
                box3.style.display = '';
                editBoxDropzone(box3);
                moveCursorToEnd(box3.querySelector('.dew-edit-input'));
                return;
            }

            var editCancel = e.target.closest('.dew-edit-cancel');
            if (editCancel) {
                var box4 = editCancel.closest('.dew-post-editbox');
                cancelEditBox(box4);
                box4.style.display = 'none';
                box4.closest('.dew-post').querySelector('.dew-post-content').style.display = '';
                return;
            }

            var editSave = e.target.closest('.dew-edit-save');
            if (editSave) {
                var pidEdit = parseInt(editSave.getAttribute('data-id'), 10);
                var box5 = editSave.closest('.dew-post-editbox');
                box5.style.display = 'none';
                box5.closest('.dew-post').querySelector('.dew-post-content').style.display = '';
                saveEditBox(box5, pidEdit);
                return;
            }

            var editTagPick = e.target.closest('.dew-edit-tag-dropdown li[data-id]');
            if (editTagPick) {
                var pickerBox = editTagPick.closest('.dew-edit-tagpicker');
                var chipsEl = pickerBox.querySelector('.dew-edit-tag-chips');
                var uid = editTagPick.getAttribute('data-id');
                if (!chipsEl.querySelector('.dew-tag-chip[data-id="' + uid + '"]')) {
                    var chip = document.createElement('span');
                    chip.className = 'dew-tag-chip';
                    chip.setAttribute('data-id', uid);
                    chip.innerHTML = esc(editTagPick.getAttribute('data-name')) + '<button type="button" data-id="' + uid + '">&times;</button>';
                    chipsEl.appendChild(chip);
                }
                var tagInputEl = pickerBox.querySelector('.dew-edit-tag-input');
                tagInputEl.value = '';
                var tagDropdownEl = pickerBox.querySelector('.dew-edit-tag-dropdown');
                tagDropdownEl.innerHTML = '';
                tagDropdownEl.classList.remove('is-open');
                return;
            }

            var editTagRemove = e.target.closest('.dew-edit-tag-chips button[data-id]');
            if (editTagRemove) {
                editTagRemove.closest('.dew-tag-chip').remove();
                return;
            }

            var delPost = e.target.closest('.dew-post-del');
            if (delPost) {
                if (!confirm('Xóa bài viết này? Không thể hoàn tác.')) return;
                var pid = parseInt(delPost.getAttribute('data-id'), 10);
                api('wall_post_delete', { id: pid }).then(function (res) {
                    if (!res || !res.success) { toast((res && res.message) || 'Không thể xóa.', false); return; }
                    state.rows = state.rows.filter(function (r) { return r.id !== pid; });
                    renderFeed();
                    if (BOOT.permalink && BOOT.permalink.id === pid) { BOOT.permalink = null; renderPermalink(); }
                    toast('Đã xóa bài viết.');
                });
                return;
            }

            var pinBtn = e.target.closest('.dew-pin-btn');
            if (pinBtn) {
                var ppid = parseInt(pinBtn.getAttribute('data-post-id'), 10);
                api('wall_pin_toggle', { post_id: ppid }).then(function (res) {
                    if (!res || !res.success) { toast((res && res.message) || 'Không thể cập nhật.', false); return; }
                    toast(res.pinned ? 'Đã gắn vào tường của bạn.' : 'Đã gỡ khỏi tường của bạn.');
                    if (BOOT.permalink && BOOT.permalink.id === ppid) {
                        BOOT.permalink.my_tag = { pinned: res.pinned };
                        renderPermalink();
                    }
                    reloadFeed(1); // ghim/gỡ đều làm thay đổi danh sách "Tường của tôi"
                });
                return;
            }
        });

        // Thêm file vào hàng chờ đính kèm của 1 ô bình luận/trả lời + cập nhật số đếm hiển thị.
        function addCommentFiles(box, files) {
            if (!files.length) return;
            var key = box.getAttribute('data-key');
            commentFiles[key] = (commentFiles[key] || []).concat(files);
            var cnt = box.querySelector('.dew-comment-filecount');
            cnt.textContent = commentFiles[key].length + ' tệp';
            cnt.style.display = '';
            // Đã có tệp chờ gửi -> xổ luôn cụm nút để thấy nút Gửi (khỏi phải bấm "..." lần nữa).
            var bodyEl = box.querySelector('.dew-comment-compose-body');
            if (bodyEl) bodyEl.classList.add('is-tools-open');
        }

        // Chọn tệp (hoặc ảnh vừa chụp bằng camera) cho bình luận / trả lời.
        document.addEventListener('change', function (e) {
            var input = e.target.closest('.dew-comment-file-input, .dew-comment-cam-input');
            if (!input || !input.files || !input.files.length) return;
            addCommentFiles(input.closest('.dew-comment-compose'), Array.prototype.slice.call(input.files));
            input.value = '';
        });

        /* ---- Nút "Chụp ảnh" cạnh "Chọn tệp" của ô soạn bài / ô sửa bài ----
           Mở thẳng camera điện thoại (accept=image/* + capture), ảnh chụp xong đẩy vào
           đúng hàng chờ của dropzone dùng chung qua hook __iuAddFiles (invoice_dropzone.js). */
        document.addEventListener('click', function (e) {
            var btn = e.target.closest('.dew-cam-btn');
            if (!btn) return;
            e.preventDefault();
            var zone = btn.closest('.dew-dropzone');
            var camInput = zone && zone.querySelector('.dew-cam-input');
            if (camInput) camInput.click();
        });
        document.addEventListener('change', function (e) {
            var input = e.target.closest('.dew-cam-input');
            if (!input || !input.files || !input.files.length) return;
            var zone = input.closest('.dew-dropzone');
            if (zone && zone.__iuAddFiles) zone.__iuAddFiles(input.files);
            input.value = '';
        });

        // Dán ảnh (Ctrl+V) trực tiếp vào ô bình luận -> đính kèm luôn, giống ô soạn bài.
        // Ảnh dán từ clipboard thường không có tên -> tự đặt tên hợp lệ theo đuôi mime.
        var dewPasteSeq = 0;
        var DEW_PASTE_IMG_EXT = ['jpg', 'jpeg', 'png', 'gif', 'webp'];
        function renameClipboardImage(blob) {
            var ex = String((blob.type || '').split('/')[1] || 'png').toLowerCase();
            if (ex === 'jpeg') ex = 'jpg';
            if (DEW_PASTE_IMG_EXT.indexOf(ex) === -1) ex = 'png';
            var name = 'paste_' + (++dewPasteSeq) + '.' + ex;
            try { return new File([blob], name, { type: blob.type || 'image/png' }); }
            catch (err) { try { blob.name = name; } catch (e2) {} return blob; }
        }
        document.addEventListener('paste', function (e) {
            var input = e.target.closest('.dew-comment-input');
            if (!input) return;
            var cd = e.clipboardData || window.clipboardData;
            if (!cd) return;
            var items = cd.items || [];
            var imgFiles = [];
            for (var i = 0; i < items.length; i++) {
                if (items[i].kind === 'file' && items[i].type && items[i].type.indexOf('image/') === 0) {
                    var f = items[i].getAsFile();
                    if (f) imgFiles.push(renameClipboardImage(f));
                }
            }
            if (!imgFiles.length) return; // clipboard chỉ có text -> để trình duyệt tự dán chữ như bình thường
            e.preventDefault();
            addCommentFiles(input.closest('.dew-comment-compose'), imgFiles);
        });

        // Enter để gửi bình luận nhanh; Alt+Enter xuống dòng (không gửi). Tự chèn '\n' thủ công
        // (contenteditable) thay vì để trình duyệt tự xử lý Enter — giống ô soạn bài.
        document.addEventListener('keydown', function (e) {
            var input = e.target.closest('.dew-comment-input');
            if (!input || e.key !== 'Enter') return;
            e.preventDefault();
            if (e.altKey) { insertAtCursor(input, '\n'); return; }
            handleCommentSend(input.closest('.dew-comment-compose'));
        });

        // Enter để lưu bài đang sửa; Alt+Enter xuống dòng (không lưu). Tự chèn '\n' thủ công
        // (contenteditable) thay vì để trình duyệt tự xử lý Enter — giống ô soạn bài.
        document.addEventListener('keydown', function (e) {
            var ta = e.target.closest('.dew-edit-input');
            if (!ta || e.key !== 'Enter') return;
            e.preventDefault();
            if (e.altKey) { insertAtCursor(ta, '\n'); return; }
            var box = ta.closest('.dew-post-editbox');
            var postEl = box.closest('.dew-post');
            var postId = parseInt(postEl.getAttribute('data-id'), 10);
            box.style.display = 'none';
            postEl.querySelector('.dew-post-content').style.display = '';
            saveEditBox(box, postId);
        });

        // Chuẩn hóa về rỗng thật sự để CSS :empty hiện lại placeholder (giống ô soạn bài).
        // Tự giãn chiều cao theo nội dung (đa dòng) đã có sẵn qua CSS min/max-height.
        document.addEventListener('input', function (e) {
            var input = e.target.closest('.dew-comment-input');
            if (!input) return;
            if (input.textContent === '' && input.innerHTML !== '') input.innerHTML = '';
        });

        // Gắn thẻ người dùng khi đang sửa bài (mỗi ô sửa tự debounce riêng qua debounceByEl).
        document.addEventListener('input', function (e) {
            var input = e.target.closest('.dew-edit-tag-input');
            if (!input) return;
            debounceByEl(input, function () {
                var kw = input.value.trim();
                var dropdownEl = input.closest('.dew-search-wrap').querySelector('.dew-edit-tag-dropdown');
                if (!kw) { dropdownEl.innerHTML = ''; dropdownEl.classList.remove('is-open'); return; }
                api('search_users', { keyword: kw }).then(function (j) {
                    var items = (j && j.data) || [];
                    dropdownEl.innerHTML = items.map(function (it) {
                        return '<li data-id="' + it.id + '" data-name="' + esc(it.fullname) + '">' + esc(it.fullname) + '</li>';
                    }).join('') || '<li class="dew-dropdown-empty">Không có kết quả</li>';
                    dropdownEl.classList.add('is-open');
                });
            }, 250);
        });
    }

    /* ---------------- Khởi tạo ---------------- */
    function setAvatarEl(id) {
        var el = $(id);
        if (!el) return;
        if (myAvatarUrl) el.src = myAvatarUrl;
        else el.replaceWith(Object.assign(document.createElement('span'), { className: 'dew-avatar dew-avatar-fallback', innerHTML: '<i class="fa-solid fa-circle-user"></i>' }));
    }

    function init() {
        myAvatarUrl = (ME.avatar || '');
        setAvatarEl('#dew-my-avatar-2');

        // Ô soạn bài gọn đã đổi thành nút icon nằm ngay cạnh ô tìm bài theo #hashtag.
        $('#dew-composer-btn').addEventListener('click', openComposeModal);
        $('#dew-compose-modal-overlay').addEventListener('click', closeComposeModal);
        $('#dew-compose-modal-close').addEventListener('click', closeComposeModal);
        document.addEventListener('keydown', function (e) {
            if (e.key === 'Escape' && $('#dew-compose-modal').classList.contains('is-open')) closeComposeModal();
        });

        composeDropzone = window.InvoiceDropzone ? window.InvoiceDropzone.create($('#dew-compose-dropzone')) : null;

        wireTagPicker($('#dew-compose-tag-input'), $('#dew-compose-tag-dropdown'), function (u) {
            if (composerTags.some(function (t) { return t.id === u.id; })) return;
            composerTags.push({ id: u.id, fullname: u.fullname });
            renderComposerTags();
        });
        $('#dew-compose-tag-chips').addEventListener('click', function (e) {
            var btn = e.target.closest('button[data-id]');
            if (!btn) return;
            var id = parseInt(btn.getAttribute('data-id'), 10);
            composerTags = composerTags.filter(function (t) { return t.id !== id; });
            renderComposerTags();
        });
        $('#dew-compose-submit').addEventListener('click', submitPost);

        var hashtagSuggest = wireHashtagSuggest($('#dew-compose-input'), $('#dew-hashtag-suggest'));
        // Enter LUÔN xuống dòng — KHÔNG đăng bài (chỉ đăng khi bấm nút "Đăng bài"), trừ khi
        // đang chọn gợi ý #hashtag (hashtagSuggest xử lý trước, xem handleKeydown). Tự chèn
        // '\n' thủ công (không để trình duyệt tự xử lý Enter trong contenteditable) để nội
        // dung ô soạn chỉ gồm text + thẻ định dạng do chính widget tạo ra — giống chat.js.
        $('#dew-compose-input').addEventListener('keydown', function (e) {
            if (hashtagSuggest.handleKeydown(e)) return;
            if (e.key === 'Enter') { e.preventDefault(); insertAtCursor(this, '\n'); }
        });
        // Chuẩn hóa về rỗng thật sự để CSS :empty hiện lại placeholder (giống chat.js).
        $('#dew-compose-input').addEventListener('input', function () {
            if (this.textContent === '' && this.innerHTML !== '') this.innerHTML = '';
        });

        // Định dạng chữ [b]/[i]/[u]/[color] cho đoạn đang bôi đen trong ô soạn bài.
        // "mousedown" + preventDefault (không phải "click") để KHÔNG làm ô soạn mất
        // focus/mất vùng bôi đen trước khi bấm được nút định dạng — giống chat.js.
        $('#dew-format-btn').addEventListener('mousedown', function (e) {
            e.preventDefault();
            e.stopPropagation();
            var pop = $('#dew-format-pop');
            var willShow = pop.style.display === 'none';
            pop.style.display = willShow ? 'flex' : 'none';
            if (willShow) syncFormatButtons(pop); // vẽ đúng nút nào đang bật tại vị trí con trỏ
        });
        $('#dew-format-pop').addEventListener('mousedown', function (e) {
            var btn = e.target.closest('.dew-fmt-btn');
            if (!btn) return;
            e.preventDefault();
            e.stopPropagation();
            applyTextFormat($('#dew-compose-input'), btn.getAttribute('data-fmt'), btn.getAttribute('data-color'));
        });
        document.addEventListener('click', function (e) {
            if (!e.target.closest('#dew-format-pop') && !e.target.closest('#dew-format-btn')) {
                $('#dew-format-pop').style.display = 'none';
            }
        });

        // Định dạng chữ cho Ô BÌNH LUẬN/TRẢ LỜI — cùng cơ chế như ô soạn bài, nhưng có nhiều
        // ô cùng lúc trên trang (mỗi bình luận/trả lời 1 ô riêng) nên dùng event delegation.
        document.addEventListener('mousedown', function (e) {
            var toggleBtn = e.target.closest('.dew-comment-fmt-toggle');
            if (toggleBtn) {
                e.preventDefault();
                e.stopPropagation();
                var wrap = toggleBtn.closest('.dew-comment-compose-wrap');
                var pop = wrap.querySelector('.dew-comment-fmt-pop');
                $all('.dew-comment-fmt-pop').forEach(function (p) { if (p !== pop) p.style.display = 'none'; });
                var willShowPop = pop.style.display === 'none';
                pop.style.display = willShowPop ? 'flex' : 'none';
                if (willShowPop) syncFormatButtons(pop);
                return;
            }
            var fmtBtn = e.target.closest('.dew-comment-fmt-pop .dew-fmt-btn');
            if (fmtBtn) {
                e.preventDefault();
                e.stopPropagation();
                var input = fmtBtn.closest('.dew-comment-compose-wrap').querySelector('.dew-comment-input');
                applyTextFormat(input, fmtBtn.getAttribute('data-fmt'), fmtBtn.getAttribute('data-color'));
            }
        });
        document.addEventListener('click', function (e) {
            if (!e.target.closest('.dew-comment-fmt-pop') && !e.target.closest('.dew-comment-fmt-toggle')) {
                $all('.dew-comment-fmt-pop').forEach(function (p) { p.style.display = 'none'; });
            }
        });

        // Con trỏ nhảy chỗ / bôi đen đoạn khác -> vẽ lại trạng thái bật của bảng định dạng
        // đang mở (nút nào đang áp dụng cho vị trí hiện tại thì có nền).
        document.addEventListener('selectionchange', function () {
            var el = document.activeElement;
            if (!el || !el.classList) return;
            if (!el.classList.contains('dew-comment-input') && el.id !== 'dew-compose-input') return;
            var pop = formatPopOf(el);
            if (pop && pop.style.display !== 'none') syncFormatButtons(pop);
        });

        $('#dew-load-more').addEventListener('click', function () { reloadFeed(state.page + 1); });

        var hashInput = $('#dew-hashtag-search');
        var hashClear = $('#dew-hashtag-clear');
        hashInput.addEventListener('input', debounce(function () {
            var parsed = parseSearchInput(hashInput.value);
            state.hashtag = parsed.hashtag;
            state.keyword = parsed.keyword;
            hashClear.style.display = hashInput.value.trim() ? '' : 'none';
            reloadFeed(1);
        }, 300));
        hashClear.addEventListener('click', function () {
            hashInput.value = ''; state.hashtag = ''; state.keyword = ''; hashClear.style.display = 'none'; reloadFeed(1);
        });

        wireGlobalEvents();
        renderPermalink();
        renderFeed();
        startPoll();
    }

    document.addEventListener('DOMContentLoaded', init);
})();
