/**
 * Thành phần DÙNG CHUNG cho "Đề nghị mua đồ" (window.PR):
 *   - màn "Nhập thu chi"  : thêm/gỡ hạng mục vào danh sách + QL danh sách đề nghị (pr_catalog.js)
 *   - màn "Đề nghị mua đồ": lập đề nghị + duyệt/gom/đặt mua (purchase_request.js)
 * Nghiệp vụ nằm ở libraries/purchase_request.php (action AJAX trong cash_transactionsController.php).
 */
(function (w) {
    'use strict';

    var BASE = '?mod=cash_transactions&controllers=cash_transactions&action=';
    var PR = w.PR = w.PR || {};
    PR.catalog = [];            // [{id,name,item_count,items:[{id,name,category_id,category_name,image_url}]}]

    /* ---------------- tiện ích ---------------- */
    PR.esc = function (s) {
        return String(s == null ? '' : s).replace(/&/g, '&amp;').replace(/</g, '&lt;')
            .replace(/>/g, '&gt;').replace(/"/g, '&quot;').replace(/'/g, '&#39;');
    };

    /** Gọi action của controller. data: object | FormData. Luôn trả object (lỗi mạng → {ok:false,message}). */
    PR.api = function (action, data, opts) {
        opts = opts || {};
        var init = { credentials: 'same-origin' };
        var url = BASE + action;
        if (opts.method === 'GET') {
            var qs = new URLSearchParams(data || {}).toString();
            if (qs) url += '&' + qs;
        } else {
            init.method = 'POST';
            if (data instanceof FormData) {
                init.body = data;
            } else {
                var b = new URLSearchParams();
                Object.keys(data || {}).forEach(function (k) { b.append(k, data[k]); });
                init.headers = { 'Content-Type': 'application/x-www-form-urlencoded' };
                init.body = b.toString();
            }
        }
        return fetch(url, init)
            .then(function (r) { return r.json(); })
            .catch(function () { return { ok: false, message: 'Lỗi mạng hoặc máy chủ — thử lại sau.' }; });
    };
    PR.get = function (action, params) { return PR.api(action, params, { method: 'GET' }); };

    PR.setCatalog = function (c) { if (Array.isArray(c)) PR.catalog = c; };

    PR.findItem = function (id) {
        id = +id;
        for (var i = 0; i < PR.catalog.length; i++) {
            var its = PR.catalog[i].items || [];
            for (var j = 0; j < its.length; j++) if (+its[j].id === id) return its[j];
        }
        return null;
    };

    PR.toast = function (msg, kind) {
        var t = document.createElement('div');
        t.className = 'pr-toast' + (kind ? ' pr-toast-' + kind : '');
        t.textContent = msg;
        // Nhiều toast cùng lúc thì xếp chồng lên nhau (mỗi cái cao ~52px) thay vì đè lên nhau.
        t.style.marginBottom = (document.querySelectorAll('.pr-toast').length * 52) + 'px';
        document.body.appendChild(t);
        requestAnimationFrame(function () { t.classList.add('show'); });
        setTimeout(function () {
            t.classList.remove('show');
            setTimeout(function () { t.remove(); }, 300);
        }, kind === 'err' ? 3800 : 2400);
    };

    /* ---------------- modal ---------------- */
    var stack = [];
    document.addEventListener('keydown', function (e) {
        if (e.key !== 'Escape' || !stack.length) return;
        var top = stack[stack.length - 1];
        if (top && top.dismissible) top.close();
    });

    /**
     * Mở modal. o: {title, cls, dismissible(=true), onClose}. Trả {el, body, foot, close, setTitle}.
     * cls: 'pr-modal-sm' (hộp nhỏ giữa màn) | 'pr-modal-sheet' (tràn dưới như bottom-sheet trên điện thoại) | 'pr-modal-lg'.
     */
    PR.modal = function (o) {
        o = o || {};
        var back = document.createElement('div');
        back.className = 'pr-backdrop';
        back.innerHTML =
            '<div class="pr-modal ' + (o.cls || '') + '" role="dialog" aria-modal="true">' +
                '<div class="pr-modal-bar"><span class="pr-modal-title"></span>' +
                '<button type="button" class="pr-modal-x" aria-label="Đóng">&times;</button></div>' +
                '<div class="pr-modal-body"></div><div class="pr-modal-foot"></div>' +
            '</div>';
        document.body.appendChild(back);
        document.body.classList.add('pr-noscroll');

        var self = {
            el: back,
            body: back.querySelector('.pr-modal-body'),
            foot: back.querySelector('.pr-modal-foot'),
            dismissible: o.dismissible !== false,
            setTitle: function (t) { back.querySelector('.pr-modal-title').textContent = t; },
            close: function () {
                var i = stack.indexOf(self);
                if (i === -1) return;
                stack.splice(i, 1);
                back.remove();
                if (!stack.length) document.body.classList.remove('pr-noscroll');
                if (o.onClose) o.onClose();
            }
        };
        self.setTitle(o.title || '');
        back.querySelector('.pr-modal-x').addEventListener('click', self.close);
        back.addEventListener('mousedown', function (e) {
            if (e.target === back && self.dismissible) self.close();
        });
        stack.push(self);
        requestAnimationFrame(function () { back.classList.add('show'); });
        return self;
    };

    /** Hộp xác nhận → Promise<boolean>. o: {title, okText, cancelText, danger} */
    PR.confirm = function (msg, o) {
        o = o || {};
        return new Promise(function (resolve) {
            var done = false;
            var m = PR.modal({
                title: o.title || 'Xác nhận', cls: 'pr-modal-sm',
                onClose: function () { if (!done) { done = true; resolve(false); } }
            });
            m.body.innerHTML = '<p class="pr-confirm-msg">' + PR.esc(msg) + '</p>';
            m.foot.innerHTML =
                '<button type="button" class="pr-btn pr-btn-ghost" data-r="0">' + PR.esc(o.cancelText || 'Hủy') + '</button>' +
                '<button type="button" class="pr-btn ' + (o.danger ? 'pr-btn-danger' : 'pr-btn-primary') + '" data-r="1">' + PR.esc(o.okText || 'Đồng ý') + '</button>';
            m.foot.addEventListener('click', function (e) {
                var b = e.target.closest('[data-r]');
                if (!b) return;
                done = true;
                m.close();
                resolve(b.getAttribute('data-r') === '1');
            });
        });
    };

    /** Hộp nhập 1 dòng/nhiều dòng → Promise<string|null>. o: {value, placeholder, okText, multiline, required} */
    PR.ask = function (title, o) {
        o = o || {};
        return new Promise(function (resolve) {
            var done = false;
            var m = PR.modal({
                title: title, cls: 'pr-modal-sm',
                onClose: function () { if (!done) { done = true; resolve(null); } }
            });
            m.body.innerHTML = o.multiline
                ? '<textarea class="pr-input pr-ask-in" rows="3" maxlength="500" placeholder="' + PR.esc(o.placeholder || '') + '">' + PR.esc(o.value || '') + '</textarea>'
                : '<input type="text" class="pr-input pr-ask-in" maxlength="255" placeholder="' + PR.esc(o.placeholder || '') + '" value="' + PR.esc(o.value || '') + '">';
            m.foot.innerHTML =
                '<button type="button" class="pr-btn pr-btn-ghost" data-r="0">Hủy</button>' +
                '<button type="button" class="pr-btn pr-btn-primary" data-r="1">' + PR.esc(o.okText || 'Lưu') + '</button>';
            var inp = m.body.querySelector('.pr-ask-in');
            setTimeout(function () { inp.focus(); inp.select && inp.select(); }, 60);
            function ok() {
                var v = inp.value.trim();
                if (o.required !== false && v === '') { inp.focus(); return; }
                done = true; m.close(); resolve(v);
            }
            m.foot.addEventListener('click', function (e) {
                var b = e.target.closest('[data-r]');
                if (!b) return;
                if (b.getAttribute('data-r') === '1') ok(); else m.close();
            });
            if (!o.multiline) inp.addEventListener('keydown', function (e) { if (e.key === 'Enter') { e.preventDefault(); ok(); } });
        });
    };

    /* ---------------- ảnh: nén trước khi tải lên ---------------- */
    /** File ảnh → Blob JPEG ≤ 1280px (nền trắng cho PNG trong suốt). Reject nếu không đọc được. */
    PR.compressImage = function (file) {
        return new Promise(function (resolve, reject) {
            if (!file || !/^image\//.test(file.type || '')) { reject(new Error('Không phải ảnh')); return; }
            var url = URL.createObjectURL(file);
            var img = new Image();
            img.onload = function () {
                var max = 1280, r = Math.min(1, max / Math.max(img.width, img.height));
                var cw = Math.max(1, Math.round(img.width * r)), ch = Math.max(1, Math.round(img.height * r));
                var c = document.createElement('canvas');
                c.width = cw; c.height = ch;
                var ctx = c.getContext('2d');
                ctx.fillStyle = '#fff'; ctx.fillRect(0, 0, cw, ch);
                ctx.drawImage(img, 0, 0, cw, ch);
                URL.revokeObjectURL(url);
                c.toBlob(function (b) { b ? resolve(b) : reject(new Error('Không nén được ảnh')); }, 'image/jpeg', 0.85);
            };
            img.onerror = function () { URL.revokeObjectURL(url); reject(new Error('Không đọc được ảnh')); };
            img.src = url;
        });
    };

    /**
     * Vùng tải ảnh: KÉO THẢ · CHỌN TỆP · Ctrl+V DÁN · (điện thoại) nút CHỤP.
     * container: phần tử sẽ chứa vùng. o: {url (ảnh hiện có)}.
     * Trả {appendTo(fd), changed(), destroy()} — appendTo ghi 'image' (nếu có ảnh mới) hoặc 'remove_image' (nếu bỏ ảnh cũ).
     */
    PR.imageZone = function (container, o) {
        o = o || {};
        var st = { blob: null, previewUrl: o.url || '', removed: false };
        container.innerHTML =
            '<div class="pr-img' + (o.big ? ' pr-img-big' : '') + '">' +
                '<div class="pr-img-drop" tabindex="0" role="button" aria-label="Vùng tải ảnh">' +
                    '<div class="pr-img-ph"><i class="fa-solid fa-cloud-arrow-up"></i>' +
                        '<span>' + (o.big ? 'Chưa có hình' : 'Kéo thả ảnh vào đây') + '</span><small>' +
                        (o.big ? 'Chọn tệp, chụp ảnh hoặc dán (Ctrl+V)' : 'hoặc bấm Ctrl+V để dán ảnh') + '</small></div>' +
                    '<img class="pr-img-prev" alt="Ảnh hạng mục" hidden>' +
                    '<button type="button" class="pr-img-clear" title="Bỏ ảnh" hidden>&times;</button>' +
                '</div>' +
                '<div class="pr-img-btns">' +
                    '<button type="button" class="pr-btn pr-btn-ghost pr-btn-file"><i class="fa-regular fa-folder-open"></i> Chọn tệp</button>' +
                    '<button type="button" class="pr-btn pr-btn-ghost pr-btn-cam"><i class="fa-solid fa-camera"></i> Chụp</button>' +
                    // Nút "Gỡ hình" tường minh (modal xem hình): chỉ hiện khi đang có hình.
                    (o.removeBtn ? '<button type="button" class="pr-btn pr-btn-ghost pr-btn-rm" hidden><i class="fa-regular fa-trash-can"></i> Gỡ hình</button>' : '') +
                '</div>' +
                '<input type="file" accept="image/*" class="pr-f-file" hidden>' +
                '<input type="file" accept="image/*" capture="environment" class="pr-f-cam" hidden>' +
            '</div>';
        var drop = container.querySelector('.pr-img-drop');
        var prev = container.querySelector('.pr-img-prev');
        var ph   = container.querySelector('.pr-img-ph');
        var clr  = container.querySelector('.pr-img-clear');
        var fFile = container.querySelector('.pr-f-file');
        var fCam  = container.querySelector('.pr-f-cam');
        var rmBtn = container.querySelector('.pr-btn-rm');

        function paint() {
            var has = !!st.previewUrl;
            prev.hidden = !has; clr.hidden = !has; ph.hidden = has;
            if (rmBtn) rmBtn.hidden = !has;
            if (has) prev.src = st.previewUrl;
            drop.classList.toggle('has-img', has);
        }
        function take(file) {
            PR.compressImage(file).then(function (blob) {
                if (st.blob && st.previewUrl.indexOf('blob:') === 0) URL.revokeObjectURL(st.previewUrl);
                st.blob = blob; st.removed = false;
                st.previewUrl = URL.createObjectURL(blob);
                paint();
            }).catch(function (err) { PR.toast(err.message || 'Không dùng được ảnh này.', 'err'); });
        }
        function imageFrom(list) {
            for (var i = 0; i < list.length; i++) {
                var f = list[i].kind === 'file' ? list[i].getAsFile() : list[i];
                if (f && /^image\//.test(f.type || '')) return f;
            }
            return null;
        }

        container.querySelector('.pr-btn-file').addEventListener('click', function () { fFile.click(); });
        container.querySelector('.pr-btn-cam').addEventListener('click', function () { fCam.click(); });
        [fFile, fCam].forEach(function (inp) {
            inp.addEventListener('change', function () {
                if (inp.files && inp.files[0]) take(inp.files[0]);
                inp.value = '';
            });
        });
        drop.addEventListener('click', function (e) { if (!e.target.closest('.pr-img-clear')) drop.focus(); });
        function clearImage(e) {
            if (e) e.stopPropagation();
            if (st.blob && st.previewUrl.indexOf('blob:') === 0) URL.revokeObjectURL(st.previewUrl);
            st.blob = null; st.previewUrl = ''; st.removed = !!o.url;
            paint();
        }
        clr.addEventListener('click', clearImage);
        if (rmBtn) rmBtn.addEventListener('click', clearImage);
        ['dragenter', 'dragover'].forEach(function (ev) {
            drop.addEventListener(ev, function (e) { e.preventDefault(); drop.classList.add('is-over'); });
        });
        ['dragleave', 'drop'].forEach(function (ev) {
            drop.addEventListener(ev, function (e) { e.preventDefault(); drop.classList.remove('is-over'); });
        });
        drop.addEventListener('drop', function (e) {
            var f = e.dataTransfer && imageFrom(e.dataTransfer.files || []);
            if (f) take(f); else PR.toast('Hãy thả một tệp ảnh.', 'err');
        });
        // Ctrl+V: chỉ chặn khi clipboard có ẢNH — dán chữ vào ô tên vẫn bình thường.
        function onPaste(e) {
            var cd = e.clipboardData;
            if (!cd || !cd.items) return;
            var f = imageFrom(cd.items);
            if (!f) return;
            e.preventDefault();
            take(f);
            PR.toast('Đã dán ảnh từ clipboard.');
        }
        document.addEventListener('paste', onPaste);
        paint();

        return {
            changed: function () { return !!st.blob || st.removed; },
            appendTo: function (fd) {
                if (st.blob) fd.append('image', st.blob, 'photo.jpg');
                else if (st.removed) fd.append('remove_image', '1');
            },
            destroy: function () {
                document.removeEventListener('paste', onPaste);
                if (st.blob && st.previewUrl.indexOf('blob:') === 0) URL.revokeObjectURL(st.previewUrl);
            }
        };
    };

    /* ---------------- ô chọn Mục chi (select + thêm/sửa/xóa) ---------------- */
    /**
     * container: phần tử chứa. o: {value, manage(=true), blankLabel, onChange(id), onCatalog(catalog)}.
     * Trả {value(), set(id), refresh()}.
     */
    PR.categoryField = function (container, o) {
        o = o || {};
        var manage = o.manage !== false;
        container.innerHTML =
            '<div class="pr-catfield">' +
                '<select class="pr-input pr-cat-sel"></select>' +
                (manage
                    ? '<div class="pr-cat-actions">' +
                        '<button type="button" class="pr-icon-btn" data-act="add" title="Thêm mục chi"><i class="fa-solid fa-plus"></i></button>' +
                        '<button type="button" class="pr-icon-btn" data-act="edit" title="Sửa tên mục chi đang chọn"><i class="fa-solid fa-pen"></i></button>' +
                        '<button type="button" class="pr-icon-btn pr-icon-danger" data-act="del" title="Xóa mục chi đang chọn"><i class="fa-solid fa-trash"></i></button>' +
                      '</div>'
                    : '') +
            '</div>';
        var sel = container.querySelector('.pr-cat-sel');

        function render(selected) {
            var h = '<option value="">' + PR.esc(o.blankLabel || '— Chọn mục chi —') + '</option>';
            PR.catalog.forEach(function (c) {
                h += '<option value="' + c.id + '">' + PR.esc(c.name) + '</option>';
            });
            sel.innerHTML = h;
            sel.value = selected ? String(selected) : '';
            if (sel.value !== (selected ? String(selected) : '')) sel.value = '';
        }
        function changed(catalog) {
            if (catalog) PR.setCatalog(catalog);
            if (o.onCatalog) o.onCatalog(PR.catalog);
        }
        render(o.value);
        sel.addEventListener('change', function () { if (o.onChange) o.onChange(+sel.value || 0); });

        if (manage) container.querySelector('.pr-cat-actions').addEventListener('click', function (e) {
            var b = e.target.closest('[data-act]');
            if (!b) return;
            var act = b.getAttribute('data-act');
            var cur = +sel.value || 0;
            var curCat = PR.catalog.filter(function (c) { return +c.id === cur; })[0];

            if (act === 'add') {
                PR.ask('Thêm mục chi', { placeholder: 'Tên mục chi, vd: Đồ dùng y tế', okText: 'Thêm' }).then(function (name) {
                    if (name === null) return;
                    PR.api('pr_category_save', { id: 0, name: name }).then(function (res) {
                        if (!res.ok) { PR.toast(res.message || 'Không thêm được mục chi.', 'err'); return; }
                        changed(res.catalog); render(res.id);
                        if (o.onChange) o.onChange(+res.id);
                        PR.toast('Đã thêm mục chi.');
                    });
                });
            } else if (act === 'edit') {
                if (!curCat) { PR.toast('Hãy chọn mục chi cần sửa.', 'err'); return; }
                PR.ask('Sửa tên mục chi', { value: curCat.name, okText: 'Lưu' }).then(function (name) {
                    if (name === null || name === curCat.name) return;
                    PR.api('pr_category_save', { id: cur, name: name }).then(function (res) {
                        if (!res.ok) { PR.toast(res.message || 'Không sửa được.', 'err'); return; }
                        changed(res.catalog); render(cur);
                        PR.toast('Đã đổi tên mục chi.');
                    });
                });
            } else if (act === 'del') {
                if (!curCat) { PR.toast('Hãy chọn mục chi cần xóa.', 'err'); return; }
                PR.confirm('Xóa mục chi "' + curCat.name + '"?', { okText: 'Xóa', danger: true }).then(function (yes) {
                    if (!yes) return;
                    PR.api('pr_category_delete', { id: cur }).then(function (res) {
                        if (!res.ok) { PR.toast(res.message || 'Không xóa được.', 'err'); return; }
                        changed(res.catalog); render(0);
                        if (o.onChange) o.onChange(0);
                        PR.toast('Đã xóa mục chi.');
                    });
                });
            }
        });

        return {
            value: function () { return +sel.value || 0; },
            set: function (id) { render(id); },
            refresh: function () { render(+sel.value || 0); }
        };
    };

    /**
     * Modal THÊM / SỬA 1 hạng mục: Mục chi (chọn + thêm/sửa/xóa) · Hạng mục chi · Ảnh (kéo thả / chọn tệp / Ctrl+V / Chụp).
     * Dùng chung cho "Nhập thu chi" (Thêm DS đề nghị, QL danh sách) và màn "Đề nghị mua đồ" (+ Thêm mục mua, + Thêm ở bộ chọn).
     * o: {item? (sửa), cashId?, name?, categoryId?, manageCats(=true: cho thêm/sửa/xóa mục chi ngay trong modal), onSaved(item)}
     * Trả modal, hoặc null nếu chưa thể thêm (chưa có mục chi mà người dùng không được tạo).
     */
    PR.lastCategoryId = 0;
    PR.itemModal = function (o) {
        o = o || {};
        var editing = !!(o.item && o.item.id);
        var manageCats = o.manageCats !== false;
        if (!editing && !manageCats && !PR.catalog.length) {
            PR.toast('Chưa có mục chi nào — nhờ người quản lý tạo mục chi ở màn "Nhập thu chi" trước.', 'err');
            return null;
        }
        var zone = null;
        var m = PR.modal({
            title: editing ? 'Sửa hạng mục đề nghị' : (o.title || 'Thêm vào danh sách đề nghị mua đồ'),
            cls: 'pr-modal-sheet',
            onClose: function () { if (zone) zone.destroy(); }
        });
        m.body.innerHTML =
            '<div class="pr-field"><label>Mục chi</label><div class="f-cat"></div></div>' +
            '<div class="pr-field"><label for="pr-f-name">Hạng mục chi</label>' +
                '<input type="text" id="pr-f-name" class="pr-input f-name" maxlength="255" autocomplete="off" ' +
                'placeholder="Tên hạng mục, vd: Khẩu trang y tế"></div>' +
            '<div class="pr-field"><label>Ảnh minh họa <span class="pr-opt">(không bắt buộc — giúp nhận ra đúng món cần mua)</span></label>' +
                '<div class="f-img"></div></div>';
        var okText = editing ? 'Lưu' : 'Thêm vào danh sách';
        m.foot.innerHTML =
            '<button type="button" class="pr-btn pr-btn-ghost" data-r="cancel">Hủy</button>' +
            '<button type="button" class="pr-btn pr-btn-primary" data-r="save">' + okText + '</button>';

        var $name = m.body.querySelector('.f-name');
        $name.value = editing ? o.item.name : (o.name || '');
        var startCat = editing ? o.item.category_id : (o.categoryId || PR.lastCategoryId || 0);
        var cat = PR.categoryField(m.body.querySelector('.f-cat'), { value: startCat, manage: manageCats });
        zone = PR.imageZone(m.body.querySelector('.f-img'), { url: editing ? o.item.image_url : '' });
        setTimeout(function () { $name.focus(); }, 80);

        var saving = false;
        function save() {
            if (saving) return;
            var name = $name.value.trim();
            if (!cat.value()) { PR.toast('Hãy chọn mục chi.', 'err'); return; }
            if (!name) { PR.toast('Hãy nhập hạng mục chi.', 'err'); $name.focus(); return; }
            var fd = new FormData();
            fd.append('id', editing ? o.item.id : 0);
            fd.append('category_id', cat.value());
            fd.append('name', name);
            if (o.cashId) fd.append('source_cash_id', o.cashId);
            zone.appendTo(fd);

            saving = true;
            var btn = m.foot.querySelector('[data-r="save"]');
            btn.disabled = true; btn.textContent = 'Đang lưu…';
            PR.api('pr_item_save', fd).then(function (res) {
                saving = false; btn.disabled = false; btn.textContent = okText;
                if (!res.ok) { PR.toast(res.message || 'Không lưu được.', 'err'); return; }
                PR.setCatalog(res.catalog);
                if (!editing) PR.lastCategoryId = +res.item.category_id || 0;   // lần thêm sau mặc định đúng mục chi vừa dùng
                m.close();
                PR.toast(editing ? 'Đã lưu hạng mục.' : 'Đã thêm vào danh sách đề nghị.');
                if (o.onSaved) o.onSaved(res.item);
            });
        }
        m.foot.addEventListener('click', function (e) {
            var b = e.target.closest('[data-r]');
            if (!b) return;
            if (b.getAttribute('data-r') === 'save') save(); else m.close();
        });
        $name.addEventListener('keydown', function (e) { if (e.key === 'Enter') { e.preventDefault(); save(); } });
        return m;
    };

    /** Xem ảnh phóng to (bấm ảnh hạng mục). */
    PR.zoomImage = function (url) {
        if (!url) return;
        var m = PR.modal({ title: '', cls: 'pr-modal-zoom' });
        m.body.innerHTML = '<img class="pr-zoom-img" src="' + PR.esc(url) + '" alt="">';
    };
})(window);
