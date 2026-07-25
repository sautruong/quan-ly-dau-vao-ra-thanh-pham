/* =====================================================================
 *  HEADER TRANG CHỦ — menu cha kéo-thả + giới hạn 8 + ">>", dropdown
 *  hover, tìm menu con, cài đặt hình nền (thư viện + upload cá nhân),
 *  slideshow nền crossfade.
 * ===================================================================== */
(function () {
    'use strict';

    var ACT = '?mod=home&controllers=index&action=';
    var MAX_MAIN = 8;

    function postForm(action, params) {
        var body = new URLSearchParams();
        Object.keys(params || {}).forEach(function (k) {
            var v = params[k];
            if (Array.isArray(v)) { v.forEach(function (x) { body.append(k + '[]', x); }); }
            else { body.append(k, v == null ? '' : v); }
        });
        return fetch(ACT + action, {
            method: 'POST', credentials: 'same-origin', body: body
        }).then(function (r) { return r.json(); });
    }

    function postFile(action, fieldName, file, extra) {
        var fd = new FormData();
        fd.append(fieldName, file);
        Object.keys(extra || {}).forEach(function (k) { fd.append(k, extra[k]); });
        return fetch(ACT + action, { method: 'POST', credentials: 'same-origin', body: fd })
            .then(function (r) { return r.json(); });
    }

    function getJson(action) {
        return fetch(ACT + action, { credentials: 'same-origin' }).then(function (r) { return r.json(); });
    }

    /** Toast cảnh báo nhỏ, giữa dưới màn hình (dùng cho "Thanh dấu trang đã full" v.v.). */
    function toastWarn(text) {
        var t = document.createElement('div');
        t.textContent = text;
        t.style.cssText = 'position:fixed;left:50%;bottom:28px;transform:translate(-50%,16px);'
            + 'background:#b45309;color:#fff;padding:12px 18px;border-radius:10px;font-size:14px;font-weight:600;'
            + 'box-shadow:0 10px 30px rgba(0,0,0,.25);z-index:99999;opacity:0;transition:opacity .3s,transform .3s;';
        document.body.appendChild(t);
        requestAnimationFrame(function () { t.style.opacity = '1'; t.style.transform = 'translate(-50%,0)'; });
        setTimeout(function () { t.style.opacity = '0'; setTimeout(function () { t.remove(); }, 350); }, 2200);
    }

    document.addEventListener('DOMContentLoaded', function () {
        initNavMenu();
        initSearch();
        initBgSet();
        initBgSlideshow();
        initWelcomeFlash();
    });

    /* =====================================================================
     *  1. MENU CHA: kéo-thả + giới hạn 8 + ">>" + dropdown hover/click
     *  Thành viên hàng chính vs ẩn trong ">>" do người dùng tự kéo-thả /
     *  bấm ghim quyết định (không tự động lấp đầy về 8) — chỉ chặn khi
     *  hàng chính đã đủ MAX_MAIN.
     * ===================================================================== */
    function initNavMenu() {
        var nav = document.getElementById('home-nav');
        var moreWrap = document.getElementById('home-nav-more');
        var moreBtn = document.getElementById('home-nav-more-btn');
        var moreList = document.getElementById('home-nav-more-list');
        if (!nav || !moreWrap || !moreBtn || !moreList) return;

        function allKeys() {
            return Array.prototype.slice.call(nav.children).concat(Array.prototype.slice.call(moreList.children))
                .map(function (el) { return el.getAttribute('data-key'); });
        }
        function saveState() {
            postForm('menuOrderSave', { order: allKeys(), bar_count: nav.children.length });
        }
        function syncMoreVisibility() {
            moreWrap.style.display = moreList.children.length ? '' : 'none';
        }

        /* ---- Đóng mọi dropdown menu cha (trừ 1 cái đang mở, nếu có) ---- */
        function closeAllNav(except) {
            nav.querySelectorAll('.home-nav-item.is-open').forEach(function (el) {
                if (el !== except) el.classList.remove('is-open');
            });
            moreList.querySelectorAll('.home-nav-item.is-open').forEach(function (el) {
                if (el !== except) el.classList.remove('is-open');
            });
            if (moreWrap !== except) moreWrap.classList.remove('is-open');
        }

        /* Click vào tên nhóm (hàng chính hoặc trong ">>") → toggle; click nút ghim → đưa về
           hàng chính (nếu chưa đủ 8); hover đã lo mở dropdown hàng chính qua CSS. */
        document.getElementById('home-nav-wrap').addEventListener('click', function (e) {
            var pin = e.target.closest('.home-nav-pin');
            if (pin) {
                e.stopPropagation();
                if (nav.children.length >= MAX_MAIN) { toastWarn('Thanh dấu trang đã full.'); return; }
                var pinItem = pin.closest('.home-nav-item');
                if (pinItem) {
                    nav.appendChild(pinItem);
                    syncMoreVisibility();
                    saveState();
                }
                return;
            }
            var more = e.target.closest('#home-nav-more-btn');
            if (more) {
                e.stopPropagation();
                var open = moreWrap.classList.toggle('is-open');
                if (open) closeAllNav(moreWrap);
                return;
            }
            var btn = e.target.closest('.home-nav-item:not(.home-nav-more) > .home-nav-btn');
            if (!btn) return;
            e.stopPropagation();
            var item = btn.parentElement;
            var open2 = item.classList.toggle('is-open');
            if (open2) closeAllNav(item);
        });
        document.addEventListener('click', function () { closeAllNav(null); });

        /* ---- Kéo-thả đổi vị trí / chuyển hàng chính <-> ">>" ---- */
        var dragEl = null;
        var dragBlockedNotified = false;
        function isDraggableItem(el) { return el && el.classList.contains('home-nav-item') && !el.classList.contains('home-nav-more'); }

        [nav, moreList].forEach(function (container) {
            container.addEventListener('dragstart', function (e) {
                var item = e.target.closest('.home-nav-item');
                if (!isDraggableItem(item)) { e.preventDefault(); return; }
                dragEl = item;
                dragBlockedNotified = false;
                item.classList.add('is-dragging');
                e.dataTransfer.effectAllowed = 'move';
                try { e.dataTransfer.setData('text/plain', item.getAttribute('data-key') || ''); } catch (err) {}
            });
            container.addEventListener('dragend', function () {
                if (dragEl) dragEl.classList.remove('is-dragging');
                dragEl = null;
                syncMoreVisibility();
                saveState();
            });
            container.addEventListener('dragover', function (e) {
                if (!dragEl) return;
                // Kéo từ ngoài (">>" hoặc hàng chính) vào hàng chính khi đã đủ 8 -> chặn.
                if (container === nav && dragEl.parentElement !== nav && nav.children.length >= MAX_MAIN) {
                    if (!dragBlockedNotified) { dragBlockedNotified = true; toastWarn('Thanh dấu trang đã full.'); }
                    return;
                }
                e.preventDefault();
                var horizontal = container === nav;
                var after = getDragAfterElement(container, horizontal ? e.clientX : e.clientY, horizontal);
                if (after == null) container.appendChild(dragEl);
                else container.insertBefore(dragEl, after);
            });
            container.addEventListener('drop', function (e) { e.preventDefault(); });
        });

        /* Thả thẳng lên nút ">>" (kể cả khi đang đóng) để ẩn 1 thẻ hàng chính vào trong. */
        moreBtn.addEventListener('dragover', function (e) {
            if (!dragEl) return;
            e.preventDefault();
            if (dragEl.parentElement !== moreList) moreList.appendChild(dragEl);
        });
        moreBtn.addEventListener('drop', function (e) { e.preventDefault(); });

        function getDragAfterElement(container, coord, horizontal) {
            var items = Array.prototype.slice.call(container.querySelectorAll('.home-nav-item:not(.is-dragging)'));
            var closest = { offset: -Infinity, el: null };
            items.forEach(function (el) {
                var box = el.getBoundingClientRect();
                var center = horizontal ? (box.left + box.width / 2) : (box.top + box.height / 2);
                var offset = coord - center;
                if (offset < 0 && offset > closest.offset) { closest = { offset: offset, el: el }; }
            });
            return closest.el;
        }
    }

    /* =====================================================================
     *  2. TÌM MENU CON (client-side, không AJAX)
     * ===================================================================== */
    function initSearch() {
        var wrap = document.getElementById('home-search');
        var btn = document.getElementById('home-search-btn');
        var input = document.getElementById('home-search-input');
        var results = document.getElementById('home-search-results');
        if (!wrap || !btn || !input || !results) return;

        btn.addEventListener('click', function (e) {
            e.stopPropagation();
            var open = wrap.classList.toggle('is-open');
            if (open) { input.value = ''; results.innerHTML = ''; setTimeout(function () { input.focus(); }, 30); }
        });
        wrap.addEventListener('click', function (e) { e.stopPropagation(); });
        document.addEventListener('click', function () { wrap.classList.remove('is-open'); });

        function esc(s) {
            return String(s == null ? '' : s).replace(/&/g, '&amp;').replace(/</g, '&lt;')
                .replace(/>/g, '&gt;').replace(/"/g, '&quot;');
        }

        // Điều khiển bàn phím: mũi tên lên/xuống duyệt, Enter/Tab chọn (xem [[dropdown-keyboard-nav-default]])
        var hsActiveIdx = -1;
        function hsItems() {
            return Array.prototype.slice.call(results.querySelectorAll('a'));
        }
        function hsHighlight(idx) {
            var els = hsItems();
            els.forEach(function (a) { a.classList.remove('is-kbd-active'); });
            if (idx >= 0 && els[idx]) {
                els[idx].classList.add('is-kbd-active');
                els[idx].scrollIntoView({ block: 'nearest' });
            }
        }
        function hsPick(a) {
            if (a) window.location.href = a.href;
        }

        input.addEventListener('input', function () {
            hsActiveIdx = -1;
            var kw = input.value.trim().toLowerCase();
            if (!kw) { results.innerHTML = ''; return; }
            var links = document.querySelectorAll('.home-nav-drop a');
            var out = [];
            links.forEach(function (a) {
                var label = a.textContent || '';
                var group = a.getAttribute('data-group') || '';
                if (label.toLowerCase().indexOf(kw) >= 0 || group.toLowerCase().indexOf(kw) >= 0) {
                    out.push({ label: label, group: group, url: a.getAttribute('href') });
                }
            });
            if (!out.length) { results.innerHTML = '<div class="home-search-empty">Không tìm thấy menu phù hợp.</div>'; return; }
            results.innerHTML = out.slice(0, 40).map(function (r) {
                return '<a href="' + esc(r.url) + '"><span class="hs-label">' + esc(r.label) + '</span>'
                    + '<span class="hs-group">' + esc(r.group) + '</span></a>';
            }).join('');
        });

        input.addEventListener('keydown', function (e) {
            var els = hsItems();
            if (!els.length) return;
            if (e.key === 'ArrowDown') {
                e.preventDefault();
                hsActiveIdx = (hsActiveIdx + 1) % els.length;
                hsHighlight(hsActiveIdx);
            } else if (e.key === 'ArrowUp') {
                e.preventDefault();
                hsActiveIdx = (hsActiveIdx - 1 + els.length) % els.length;
                hsHighlight(hsActiveIdx);
            } else if (e.key === 'Enter') {
                if (hsActiveIdx >= 0) { e.preventDefault(); hsPick(els[hsActiveIdx]); }
            } else if (e.key === 'Tab') {
                // Không preventDefault: chọn dòng đang tô sáng RỒI vẫn cho Tab chuyển focus tiếp.
                if (hsActiveIdx >= 0) hsPick(els[hsActiveIdx]);
            } else if (e.key === 'Escape') {
                hsActiveIdx = -1;
                hsHighlight(-1);
            }
        });
    }

    /* =====================================================================
     *  3. CÀI ĐẶT HÌNH NỀN
     * ===================================================================== */
    function initBgSet() {
        var btn = document.getElementById('home-bgset-btn');
        var modal = document.getElementById('home-bgset-modal');
        if (!btn || !modal) return;

        var slidesBox = document.getElementById('home-bgset-slides');
        var libBox = document.getElementById('home-bgset-library');
        var libAddWrap = document.getElementById('home-bgset-lib-add-wrap');
        var libAddInput = document.getElementById('home-bgset-lib-add-input');
        var uploadInput = document.getElementById('home-bgset-upload-input');
        var msg = document.getElementById('home-bgset-msg');
        var maxSlides = 3;
        var isAdmin = false;

        function esc(s) {
            return String(s == null ? '' : s).replace(/&/g, '&amp;').replace(/</g, '&lt;')
                .replace(/>/g, '&gt;').replace(/"/g, '&quot;');
        }
        function setMsg(text, isError) {
            msg.textContent = text || '';
            msg.style.color = isError ? '#dc2626' : '#15803d';
        }

        function renderSlides(slides) {
            var html = slides.map(function (s) {
                return '<div class="home-bgset-slide" draggable="true" data-id="' + s.id + '" style="background-image:url(\'' + esc(s.url) + '\')">'
                    + '<button type="button" class="home-bgset-remove" data-remove-slide="' + s.id + '" title="Xóa"><i class="fa-solid fa-xmark"></i></button>'
                    + '</div>';
            }).join('');
            if (slides.length < maxSlides) {
                html += '<div class="home-bgset-slide home-bgset-slide-add" title="Chọn ảnh từ thư viện hoặc tải lên"><i class="fa-solid fa-plus"></i></div>';
            }
            slidesBox.innerHTML = html || '<div class="home-bgset-empty">Chưa chọn hình nào — chọn từ thư viện hoặc tải ảnh lên bên dưới.</div>';
            applyBgLayer(slides);
            bindSlideDrag();
        }

        function renderLibrary(library) {
            libAddWrap.style.display = isAdmin ? '' : 'none';
            if (!library.length) { libBox.innerHTML = '<div class="home-bgset-empty">Thư viện chưa có hình nào.</div>'; return; }
            libBox.innerHTML = library.map(function (l) {
                var removeBtn = isAdmin
                    ? '<button type="button" class="home-bgset-remove" data-remove-lib="' + l.id + '" title="Xóa khỏi thư viện"><i class="fa-solid fa-xmark"></i></button>'
                    : '';
                return '<div class="home-bgset-lib-item" data-add-lib="' + l.id + '" style="background-image:url(\'' + esc(l.url) + '\')">' + removeBtn + '</div>';
            }).join('');
        }

        function load() {
            getJson('bgSettings').then(function (res) {
                if (!res || !res.ok) return;
                isAdmin = !!res.is_admin;
                maxSlides = res.max || 3;
                renderSlides(res.slides || []);
                renderLibrary(res.library || []);
            });
        }

        btn.addEventListener('click', function () {
            modal.classList.add('is-open');
            modal.setAttribute('aria-hidden', 'false');
            setMsg('');
            load();
        });
        modal.querySelectorAll('[data-bgset-close]').forEach(function (el) {
            el.addEventListener('click', function () {
                modal.classList.remove('is-open');
                modal.setAttribute('aria-hidden', 'true');
            });
        });

        slidesBox.addEventListener('click', function (e) {
            var rm = e.target.closest('[data-remove-slide]');
            if (rm) {
                postForm('bgSlideRemove', { slide_id: rm.getAttribute('data-remove-slide') }).then(function (res) {
                    if (res && res.ok) { renderSlides(res.slides || []); setMsg('Đã xóa hình.'); }
                    else setMsg((res && res.message) || 'Không xóa được.', true);
                });
                return;
            }
            if (e.target.closest('.home-bgset-slide-add')) {
                uploadInput.click();
            }
        });

        libBox.addEventListener('click', function (e) {
            var rmLib = e.target.closest('[data-remove-lib]');
            if (rmLib) {
                if (!confirm('Xóa hình này khỏi thư viện dùng chung? Hình sẽ mất khỏi hình nền của mọi người đang dùng nó.')) return;
                postForm('bgLibraryRemove', { id: rmLib.getAttribute('data-remove-lib') }).then(function (res) {
                    if (res && res.ok) { renderLibrary(res.library || []); renderSlides(res.slides || []); setMsg('Đã xóa khỏi thư viện.'); }
                    else setMsg((res && res.message) || 'Không xóa được.', true);
                });
                return;
            }
            var addLib = e.target.closest('[data-add-lib]');
            if (addLib) {
                postForm('bgSlideAdd', { library_id: addLib.getAttribute('data-add-lib') }).then(function (res) {
                    if (res && res.ok) { renderSlides(res.slides || []); setMsg('Đã thêm hình nền.'); }
                    else setMsg((res && res.message) || 'Không thêm được.', true);
                });
            }
        });

        /** Chặn sớm ảnh không nằm ngang trước khi upload. */
        function checkLandscape(file) {
            return new Promise(function (resolve) {
                var img = new Image();
                var url = URL.createObjectURL(file);
                img.onload = function () { URL.revokeObjectURL(url); resolve(img.width / img.height >= 1.2); };
                img.onerror = function () { URL.revokeObjectURL(url); resolve(true); };
                img.src = url;
            });
        }

        uploadInput.addEventListener('change', function () {
            var file = uploadInput.files && uploadInput.files[0];
            uploadInput.value = '';
            if (!file) return;
            checkLandscape(file).then(function (ok) {
                if (!ok) { setMsg('Vui lòng chọn ảnh nằm ngang (chữ nhật ngang) để tránh vỡ hình.', true); return; }
                postFile('bgSlideUpload', 'file', file).then(function (res) {
                    if (res && res.ok) { renderSlides(res.slides || []); setMsg('Đã thêm hình nền.'); }
                    else setMsg((res && res.message) || 'Không tải lên được.', true);
                });
            });
        });

        libAddInput.addEventListener('change', function () {
            var file = libAddInput.files && libAddInput.files[0];
            libAddInput.value = '';
            if (!file) return;
            checkLandscape(file).then(function (ok) {
                if (!ok) { setMsg('Vui lòng chọn ảnh nằm ngang (chữ nhật ngang) để tránh vỡ hình.', true); return; }
                postFile('bgLibraryAdd', 'file', file).then(function (res) {
                    if (res && res.ok) { renderLibrary(res.library || []); setMsg('Đã thêm vào thư viện.'); }
                    else setMsg((res && res.message) || 'Không thêm được.', true);
                });
            });
        });

        /* ---- Kéo-thả đổi thứ tự slideshow (chỉ trong slidesBox) ---- */
        var dragEl = null;
        function bindSlideDrag() {
            slidesBox.querySelectorAll('.home-bgset-slide[draggable="true"]').forEach(function (el) {
                el.addEventListener('dragstart', function () { dragEl = el; el.classList.add('is-dragging'); });
                el.addEventListener('dragend', function () {
                    el.classList.remove('is-dragging');
                    dragEl = null;
                    var ids = Array.prototype.slice.call(slidesBox.querySelectorAll('.home-bgset-slide[data-id]')).map(function (s) { return s.getAttribute('data-id'); });
                    postForm('bgSlideReorder', { ids: ids }).then(function (res) { if (res && res.ok) applyBgLayer(res.slides || []); });
                });
            });
        }
        slidesBox.addEventListener('dragover', function (e) {
            if (!dragEl) return;
            e.preventDefault();
            var items = Array.prototype.slice.call(slidesBox.querySelectorAll('.home-bgset-slide[draggable="true"]:not(.is-dragging)'));
            var closest = { offset: -Infinity, el: null };
            items.forEach(function (el) {
                var box = el.getBoundingClientRect();
                var offset = e.clientX - (box.left + box.width / 2);
                if (offset < 0 && offset > closest.offset) { closest = { offset: offset, el: el }; }
            });
            if (closest.el == null) slidesBox.insertBefore(dragEl, slidesBox.querySelector('.home-bgset-slide-add'));
            else slidesBox.insertBefore(dragEl, closest.el);
        });
    }

    /* =====================================================================
     *  4. SLIDESHOW NỀN (crossfade ~6s)
     * ===================================================================== */
    var bgTimer = null;
    function applyBgLayer(slides) {
        var layer = document.getElementById('home-bg-layer');
        if (!layer) return;
        if (bgTimer) { clearInterval(bgTimer); bgTimer = null; }
        if (!slides || !slides.length) { layer.innerHTML = ''; return; }
        layer.innerHTML = slides.map(function (s, i) {
            return '<div class="home-bg-img' + (i === 0 ? ' is-active' : '') + '" style="background-image:url(\'' + s.url.replace(/'/g, "%27") + '\')"></div>';
        }).join('');
        if (slides.length > 1) {
            var idx = 0;
            bgTimer = setInterval(function () {
                var imgs = layer.querySelectorAll('.home-bg-img');
                if (!imgs.length) return;
                imgs[idx].classList.remove('is-active');
                idx = (idx + 1) % imgs.length;
                imgs[idx].classList.add('is-active');
            }, 6000);
        }
    }

    function initBgSlideshow() {
        if (window.__HOME_BG_SLIDES && window.__HOME_BG_SLIDES.length) {
            applyBgLayer(window.__HOME_BG_SLIDES);
        }
    }

    /* =====================================================================
     *  5. "Welcome {tên}" — mờ->sáng, giữ 3 giây rồi ẩn lại (1 lần / lượt tải trang)
     * ===================================================================== */
    function initWelcomeFlash() {
        var el = document.getElementById('home-welcome-flash');
        if (!el) return;
        setTimeout(function () { el.classList.add('is-visible'); }, 150);
        setTimeout(function () { el.classList.remove('is-visible'); }, 150 + 900 + 3000);
    }
})();
