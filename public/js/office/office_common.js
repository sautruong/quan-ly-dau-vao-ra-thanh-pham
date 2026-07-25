/* =====================================================================
 *  OFFICE — office_common.js
 *  Tiện ích dùng chung cho trang editor (Docs + Sheets): gọi AJAX, toast,
 *  modal, đổi tên, chia sẻ, lịch sử phiên bản, presence (khóa mềm),
 *  xuất vào Quản lý file, in.
 * ===================================================================== */
(function () {
    'use strict';

    var ACT = '?mod=office&controllers=office&action=';

    function $(s, r) { return (r || document).querySelector(s); }
    function $all(s, r) { return Array.prototype.slice.call((r || document).querySelectorAll(s)); }
    function esc(s) {
        return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) {
            return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c];
        });
    }
    function el(html) { var t = document.createElement('template'); t.innerHTML = html.trim(); return t.content.firstChild; }

    function toast(msg, ok) {
        var t = $('#of-toast');
        if (!t) return;
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

    document.addEventListener('DOMContentLoaded', function () {
        var closeBtn = $('#of-modal-close');
        if (closeBtn) closeBtn.addEventListener('click', closeModal);
        var overlay = $('#of-modal');
        if (overlay) overlay.addEventListener('click', function (e) { if (e.target === overlay) closeModal(); });
    });

    var boot = {};
    try { boot = JSON.parse(($('#of-boot') || {}).textContent || '{}'); } catch (e) {}

    var OE = {
        api: api, toast: toast, esc: esc, el: el, openModal: openModal, closeModal: closeModal,
        boot: boot,
        docId: boot.id || 0,
        doc: boot.doc || null,
        me: boot.me || { id: 0, name: '' },
        canEdit: boot.doc ? (boot.doc.my_permission === 'owner' || boot.doc.my_permission === 'edit') : false,
        baseVersion: boot.doc ? (boot.doc.version || 1) : 1,
        dirty: false,
        _stopTypingTimer: null,
        _getContent: null,
        _applyContent: null,
        _isEditingFn: null,
        _lastTypingPing: 0,

        /** JS gọi 1 lần khi khởi tạo editor: fn trả nội dung hiện tại (string). */
        registerContentGetter: function (fn) { this._getContent = fn; },
        /** JS gọi khi cần áp nội dung mới vào editor (ví dụ sau khi khôi phục phiên bản / đồng bộ real-time). */
        registerContentSetter: function (fn) { this._applyContent = fn; },
        /** JS gọi 1 lần: fn trả true nếu người dùng đang thao tác trực tiếp trong vùng soạn thảo
         *  (đang gõ / đang sửa 1 ô) — dùng để KHÔNG ghi đè nội dung khi tự động đồng bộ real-time. */
        registerIsEditingCheck: function (fn) { this._isEditingFn = fn; },

        setSaveState: function (text) {
            var s = $('#of-ed-savestate');
            if (s) s.textContent = text;
        },

        /** Bật/tắt sáng nút "Lưu" — sáng khi có thay đổi chưa đẩy lên DB. */
        _setSaveBtnState: function (dirty) {
            var btn = $('#of-ed-save');
            if (!btn) return;
            btn.disabled = !dirty;
            btn.classList.toggle('is-dirty', !!dirty);
        },

        /** Gọi khi nội dung vừa thay đổi (gõ chữ, sửa ô, đổi định dạng...): CHỈ đánh dấu có
         *  thay đổi chưa lưu + báo "đang soạn" cho người khác — KHÔNG tự đẩy lên DB. Việc lưu
         *  thật sự chỉ xảy ra khi người dùng bấm nút "Lưu" (xem save()). */
        scheduleAutosave: function () {
            if (!this.canEdit) return;
            var self = this;
            this.dirty = true;
            this._setSaveBtnState(true);
            this.setSaveState('Có thay đổi chưa lưu');
            this._pingTyping(1);
            // Nhãn "đang soạn" chỉ hiện khi đang thực sự gõ — hết 2.5s im lặng thì tắt (không
            // ảnh hưởng tới nút Lưu/trạng thái dirty, chỉ là chỉ báo cho người khác biết).
            clearTimeout(this._stopTypingTimer);
            this._stopTypingTimer = setTimeout(function () { self._pingTyping(0); }, 2500);
        },

        initSaveButton: function () {
            var self = this;
            var btn = $('#of-ed-save');
            if (!btn) return;
            this._setSaveBtnState(false);
            btn.addEventListener('click', function () { self.save(); });
        },

        /** Ctrl+S (hay Cmd+S trên Mac) khi con trỏ đang ở TRONG vùng làm việc (`.of-ed-page` —
         *  toolbar/tiêu đề/hộp công thức/trang Docs/lưới Sheets, xem 4.3 CLAUDE.md của module)
         *  = bấm nút "Lưu". Phải chặn `e.preventDefault()` trước, nếu không trình duyệt sẽ tự
         *  mở hộp thoại "Lưu trang web" mặc định của nó (không liên quan tới nút Lưu của app).
         *  Ô Sheets (`<td>`) KHÔNG nhận focus DOM thật khi chỉ click chọn (không double-click để
         *  sửa) — lúc đó `document.activeElement`/`e.target` rơi về `document.body`, mà body lại
         *  là CHA của `.of-ed-page` chứ không phải con, nên `page.contains(e.target)` sẽ luôn sai
         *  và tắt mất phím tắt ngay cả khi đang thao tác trong bảng tính. Coi `document.body`
         *  (tức "không có gì đang thật sự giữ focus") cũng là đang ở vùng làm việc. */
        initSaveShortcut: function () {
            var self = this;
            document.addEventListener('keydown', function (e) {
                if (!self.canEdit) return;
                if (!(e.ctrlKey || e.metaKey) || e.key.toLowerCase() !== 's') return;
                var page = $('.of-ed-page');
                var t = e.target;
                if (!page || !t) return;
                if (t !== document.body && !page.contains(t)) return;
                e.preventDefault();
                self.save();
            });
        },

        /** manual=true (gọi từ nút "Lưu phiên bản" trên toolbar): sau khi lưu còn tạo thêm 1
         *  bản ghi lịch sử có ghi chú. Ai bấm "Lưu" thì nội dung của người đó được ghi vào DB
         *  (không tự merge) — người xem khác sẽ thấy bản mới nhất ở lần poll kế tiếp. */
        /** cb (không bắt buộc): gọi lại SAU KHI lưu THÀNH CÔNG — dùng khi cần điều hướng đi nơi
         *  khác ngay sau khi lưu xong (xem initBackGuard bên dưới), không gọi nếu lỗi/xung đột
         *  version (ở lại trang để người dùng tự xử lý thay vì rời đi với lỗi treo). */
        save: function (manual, note, cb) {
            var self = this;
            if (!this.canEdit || !this._getContent) return;
            var content = this._getContent();
            this.setSaveState('Đang lưu...');
            api('save', { id: this.docId, content: content, base_version: this.baseVersion }, function (j) {
                if (j && j.success) {
                    self.baseVersion = j.version;
                    self.dirty = false;
                    self._setSaveBtnState(false);
                    clearTimeout(self._stopTypingTimer);
                    self._pingTyping(0);
                    self.setSaveState('Đã lưu lúc ' + new Date().toLocaleTimeString('vi-VN', { hour: '2-digit', minute: '2-digit' }));
                    if (manual) {
                        api('save_version_manual', { id: self.docId, note: note || '' }, function () {
                            toast('Đã lưu phiên bản.');
                        });
                    }
                    if (cb) cb();
                } else if (j && j.conflict) {
                    self.setSaveState('⚠ Tài liệu đã được cập nhật — tải lại trang để xem bản mới nhất.');
                    toast('Tài liệu đã được người khác cập nhật ở nơi khác.', false);
                } else {
                    self.setSaveState('Lỗi khi lưu.');
                    toast((j && j.message) || 'Không lưu được.', false);
                }
            });
        },

        /* ---------------- Tiêu đề (đổi tên real-time giữa các người xem) ---------------- */
        initTitle: function () {
            var self = this;
            var input = $('#of-ed-title');
            if (!input) return;
            input.addEventListener('blur', function () {
                var v = input.value.trim();
                if (!v || !self.canEdit) return;
                api('rename', { id: self.docId, title: v }, function (j) {
                    if (j && j.success) document.title = v;
                });
            });
            input.addEventListener('keydown', function (e) { if (e.key === 'Enter') { e.preventDefault(); input.blur(); } });
        },

        /* ---------------- Presence (khóa mềm) + "đang nhập" real-time ---------------- */
        renderPresence: function (users) {
            var box = $('#of-ed-presence');
            if (!box) return;
            if (!users || !users.length) { box.innerHTML = ''; return; }
            var typing = users.filter(function (u) { return u.is_typing; }).map(function (u) { return esc(u.name || 'Người dùng'); });
            var idle = users.filter(function (u) { return !u.is_typing; }).map(function (u) { return esc(u.name || 'Người dùng'); });
            var parts = [];
            if (typing.length) parts.push('<span class="of-ed-typing">' + typing.join(', ') + ' đang soạn…</span>');
            if (idle.length) parts.push('<span>' + idle.join(', ') + ' đang mở</span>');
            box.innerHTML = '<i class="fa-solid fa-circle-user"></i> ' + parts.join(' · ');
        },

        /** Gộp xử lý kết quả của mọi lần poll (tick định kỳ hoặc ping "đang nhập"): cập nhật
         *  presence, đồng bộ tiêu đề nếu người khác vừa đổi, và tự tải nội dung mới khi mình
         *  đang rảnh tay (không gõ dở, không có thay đổi cục bộ chưa lưu). */
        _applyPoll: function (j) {
            var self = this;
            this.renderPresence(j.active_users);

            var titleInput = $('#of-ed-title');
            if (titleInput && typeof j.title === 'string' && document.activeElement !== titleInput && titleInput.value !== j.title) {
                titleInput.value = j.title;
                document.title = j.title;
            }

            var editing = this._isEditingFn ? this._isEditingFn() : false;
            if (j.version > this.baseVersion && !this.dirty && !editing && this._applyContent) {
                api('get', { id: this.docId }, function (r) {
                    if (r && r.success) {
                        self.baseVersion = r.doc.version;
                        self._applyContent(r.doc.content);
                        self.setSaveState('Đã cập nhật nội dung mới lúc ' + new Date().toLocaleTimeString('vi-VN', { hour: '2-digit', minute: '2-digit' }));
                    }
                });
            }
        },

        /** typingVal: 1 = báo đang nhập (throttle ~1.2s/lần), 0 = báo đã ngừng nhập. */
        _pingTyping: function (typingVal) {
            var self = this;
            var now = Date.now();
            if (typingVal === 1) {
                if (this._lastTypingPing && now - this._lastTypingPing < 1200) return;
            }
            this._lastTypingPing = now;
            api('poll', { id: this.docId, typing: typingVal }, function (j) { if (j && j.success) self._applyPoll(j); });
        },

        startPresencePoll: function () {
            var self = this;
            if (!this.docId) return;
            function tick() {
                // Không gửi tham số typing ở tick định kỳ — chỉ giữ kết nối + đọc dữ liệu,
                // tránh vô tình xóa trạng thái "đang nhập" đang có giữa 2 lần gõ.
                api('poll', { id: self.docId }, function (j) { if (j && j.success) self._applyPoll(j); });
            }
            tick();
            setInterval(tick, 3000);
        },

        /* ---------------- Chia sẻ ---------------- */
        initShare: function () {
            var self = this;
            var btn = $('#of-ed-share');
            if (!btn) return;
            btn.addEventListener('click', function () {
                // Nếu còn thay đổi chưa lưu, lưu trước khi mở chia sẻ — nếu không, người vừa
                // được chia sẻ mở ra sẽ thấy bản CŨ (bản đã lưu gần nhất trong DB), vì giờ
                // không còn autosave tự động nữa.
                if (self.dirty) { self.save(); toast('Đã lưu thay đổi trước khi chia sẻ.'); }
                self.openShareModal();
            });
        },
        openShareModal: function () {
            var self = this;
            api('users', {}, function (uj) {
                api('share_list', { id: self.docId }, function (sj) {
                    var users = (uj && uj.data) || [];
                    var shares = (sj && sj.data) || [];
                    var sharedIds = {};
                    shares.forEach(function (s) { sharedIds[s.user_id] = s; });
                    var userOpts = users.filter(function (u) { return !sharedIds[u.id]; })
                        .map(function (u) {
                            return '<label class="of-share-row"><input type="checkbox" value="' + u.id + '"> ' + esc(u.name) + '</label>';
                        }).join('') || '<div class="of-muted">Không còn ai để chia sẻ thêm.</div>';
                    var existRows = shares.map(function (s) {
                        return '<div class="of-share-exist">' +
                            '<span>' + esc(s.name) + '</span>' +
                            permSelectHtml(s.share_id, s.permission) +
                            '<button type="button" class="of-link of-share-revoke" data-id="' + s.share_id + '">Gỡ</button>' +
                            '</div>';
                    }).join('') || '<div class="of-muted">Chưa chia sẻ với ai.</div>';

                    openModal('Chia sẻ tài liệu', '' +
                        '<div class="of-share-list">' + userOpts + '</div>' +
                        '<div class="of-share-perm-row">Quyền: <select id="of-share-perm">' +
                        '<option value="view">Chỉ xem</option><option value="comment">Bình luận</option><option value="edit">Sửa</option>' +
                        '</select></div>' +
                        '<button type="button" class="of-btn of-btn-primary" id="of-share-submit">Chia sẻ</button>' +
                        '<hr class="of-share-sep">' +
                        '<div class="of-share-existing">' + existRows + '</div>');

                    var submit = $('#of-share-submit');
                    if (submit) submit.addEventListener('click', function () {
                        var targets = $all('.of-share-row input:checked').map(function (c) { return c.value; });
                        if (!targets.length) { toast('Chưa chọn người nhận.', false); return; }
                        var perm = $('#of-share-perm').value;
                        api('share', { id: self.docId, targets: targets, permission: perm }, function (j) {
                            if (j && j.success) { toast('Đã chia sẻ.'); closeModal(); }
                            else toast((j && j.message) || 'Không chia sẻ được.', false);
                        });
                    });
                    $all('.of-share-revoke').forEach(function (b) {
                        b.addEventListener('click', function () {
                            api('revoke_share', { share_id: b.dataset.id }, function (j) {
                                if (j && j.success) self.openShareModal();
                            });
                        });
                    });
                    $all('.of-share-perm-select').forEach(function (sel) {
                        sel.addEventListener('change', function () {
                            api('change_permission', { share_id: sel.dataset.id, permission: sel.value }, function (j) {
                                if (j && j.success) toast('Đã đổi quyền.');
                                else { toast('Không đổi được quyền.', false); self.openShareModal(); }
                            });
                        });
                    });
                });
            });
        },

        /* ---------------- Lịch sử phiên bản ---------------- */
        initHistory: function () {
            var self = this;
            var btn = $('#of-ed-history');
            if (!btn) return;
            btn.addEventListener('click', function () { self.openHistoryModal(); });
        },
        openHistoryModal: function () {
            var self = this;
            api('versions', { id: this.docId }, function (j) {
                var rows = (j && j.data) || [];
                var body = rows.length ? rows.map(function (v) {
                    return '<div class="of-version-row">' +
                        '<div><b>Phiên bản #' + v.version_no + '</b> — ' + esc(v.editor_name || '') + '</div>' +
                        '<div class="of-muted">' + esc(v.created_at) + (v.note ? ' · ' + esc(v.note) : '') + '</div>' +
                        (self.canEdit ? '<button type="button" class="of-btn of-version-restore" data-id="' + v.id + '">Khôi phục</button>' : '') +
                        '</div>';
                }).join('') : '<div class="of-muted">Chưa có phiên bản nào được lưu.</div>';
                openModal('Lịch sử phiên bản', body);
                $all('.of-version-restore').forEach(function (b) {
                    b.addEventListener('click', function () {
                        if (!confirm('Khôi phục về phiên bản này? Nội dung hiện tại sẽ được thay thế.')) return;
                        api('version_restore', { id: self.docId, version_id: b.dataset.id }, function (r) {
                            if (r && r.success) {
                                self.baseVersion = r.version;
                                if (self._applyContent) self._applyContent(r.content);
                                closeModal();
                                toast('Đã khôi phục phiên bản.');
                            } else toast('Không khôi phục được.', false);
                        });
                    });
                });
            });
        },

        /* ---------------- Xuất vào Quản lý file ---------------- */
        initExport: function () {
            var self = this;
            var btn = $('#of-ed-export');
            if (!btn) return;
            btn.addEventListener('click', function () {
                api('export_to_fm', { id: self.docId }, function (j) {
                    if (j && j.success) toast('Đã đưa vào Quản lý file.');
                    else toast('Không xuất được.', false);
                });
            });
        },

        /* ---------------- In ---------------- */
        initPrint: function () {
            var btn = $('#of-ed-print');
            if (!btn) return;
            btn.addEventListener('click', function () { window.print(); });
        },

        /** Ctrl+lăn chuột trong đúng vùng trang (Docs)/lưới (Sheets) = phóng to/thu nhỏ CHỈ vùng
         *  đó, không phải Ctrl+lăn chuột mặc định của trình duyệt (zoom cả giao diện). Gắn listener
         *  trực tiếp lên khung CUỘN bọc ngoài (`.of-doc-scroll`/`.of-sheet-scroll` — xem editor.php),
         *  KHÔNG gắn lên document: nhờ vậy Ctrl+lăn chuột ở nơi khác (sidebar, toolbar...) vẫn dùng
         *  đúng zoom mặc định của trình duyệt như bình thường, chỉ vùng làm việc mới bị chặn lại.
         *  Dùng CSS `zoom` (không phải transform:scale) trên chính phần tử NỘI DUNG (#of-doc-editor/
         *  #of-sheet-wrap, khác với khung cuộn ngoài) — `zoom` ảnh hưởng layout thật nên khung cuộn
         *  ngoài tự tính lại đúng scrollHeight/scrollWidth theo nội dung đã phóng to, không cần bù
         *  trừ gì thêm; quan trọng hơn với Sheets: toạ độ chuột (e.clientX/Y) đọc được trong
         *  bindGrid()/isNearCellEdge()... vẫn khớp đúng ô/viền đang zoom (khác transform:scale, nơi
         *  toạ độ chuột và toạ độ đã scale bị lệch nhau, phải tự bù trừ ở mọi chỗ dùng toạ độ). */
        initZoom: function () {
            var isSheet = this.doc && this.doc.type === 'sheet';
            var target = $(isSheet ? '#of-sheet-wrap' : '#of-doc-editor');
            var scrollHost = target && target.closest(isSheet ? '.of-sheet-scroll' : '.of-doc-scroll');
            var indicator = $('#of-ed-zoom');
            if (!target || !scrollHost) return;
            var ZOOM_MIN = 0.5, ZOOM_MAX = 2, ZOOM_STEP = 0.1;
            var zoom = 1;
            function apply() {
                target.style.zoom = zoom;
                if (indicator) indicator.textContent = Math.round(zoom * 100) + '%';
            }
            scrollHost.addEventListener('wheel', function (e) {
                if (!e.ctrlKey) return;
                e.preventDefault();
                zoom = Math.max(ZOOM_MIN, Math.min(ZOOM_MAX, zoom + (e.deltaY < 0 ? ZOOM_STEP : -ZOOM_STEP)));
                zoom = Math.round(zoom * 100) / 100; // tránh dồn sai số thập phân sau nhiều lần lăn
                apply();
            }, { passive: false });
            if (indicator) indicator.addEventListener('click', function () { zoom = 1; apply(); });
        },

        /** Nút "Quay lại" (mũi tên đầu trang) là điều hướng NỘI BỘ app nên tự chặn được bằng JS
         *  và hỏi Có/Không rõ ràng — khác với đóng tab/gõ URL khác/bấm menu sidebar (những cách
         *  rời trang mà trình duyệt không cho tùy biến nút/nội dung hộp thoại), các trường hợp
         *  đó vẫn chỉ dựa vào `beforeunload` mặc định của trình duyệt ở dưới. */
        initBackGuard: function () {
            var self = this;
            var link = $('#of-ed-back');
            if (!link) return;
            link.addEventListener('click', function (e) {
                if (!self.dirty) return;
                e.preventDefault();
                var href = link.getAttribute('href');
                openModal('Có thay đổi chưa lưu', '' +
                    '<p>Bạn có muốn lưu thay đổi trước khi rời trang không?</p>' +
                    '<div class="of-backguard-actions">' +
                    '<button type="button" class="of-btn" id="of-backguard-no">Không lưu</button>' +
                    '<button type="button" class="of-btn of-btn-primary" id="of-backguard-yes">Lưu và rời đi</button>' +
                    '</div>');
                $('#of-backguard-no').addEventListener('click', function () { window.location.href = href; });
                $('#of-backguard-yes').addEventListener('click', function () {
                    var btn = $('#of-backguard-yes');
                    btn.disabled = true;
                    btn.textContent = 'Đang lưu...';
                    self.save(false, '', function () { window.location.href = href; });
                });
            });
        },

        /** Gọi 1 lần khi trang editor tải xong: gắn toàn bộ hành vi dùng chung. */
        initChrome: function () {
            var self = this;
            this.initTitle();
            this.initSaveButton();
            this.initSaveShortcut();
            this.initShare();
            this.initHistory();
            this.initExport();
            this.initPrint();
            this.initBackGuard();
            this.initZoom();
            this.startPresencePoll();
            // Lưu là thao tác THỦ CÔNG (nút "Lưu") — rời trang khi còn thay đổi chưa lưu sẽ mất,
            // nên cảnh báo trước khi đóng tab/tải lại. Đây là "lưới an toàn" chung cho MỌI cách
            // rời trang (đóng tab, gõ URL khác, bấm sidebar...) — riêng nút back tự vẽ ở trên đã
            // được chặn sớm hơn (initBackGuard) nên sẽ không rơi xuống hộp thoại mặc định này.
            window.addEventListener('beforeunload', function (e) {
                if (self.dirty) { e.preventDefault(); e.returnValue = ''; }
            });
        }
    };

    function permLabel(p) {
        return p === 'edit' ? 'Sửa' : (p === 'comment' ? 'Bình luận' : 'Chỉ xem');
    }

    /** Dropdown đổi quyền ngay tại danh sách "đã chia sẻ" — chọn giá trị khác gọi change_permission luôn. */
    function permSelectHtml(shareId, current) {
        return '<select class="of-share-perm-select" data-id="' + shareId + '">' +
            ['view', 'comment', 'edit'].map(function (p) {
                return '<option value="' + p + '"' + (p === current ? ' selected' : '') + '>' + permLabel(p) + '</option>';
            }).join('') + '</select>';
    }

    window.OfficeEditor = OE;
})();
