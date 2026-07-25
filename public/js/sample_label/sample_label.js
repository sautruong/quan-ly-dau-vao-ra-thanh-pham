(function () {
    'use strict';

    var CFG = window.SL_CONFIG || { baseUrl: '?mod=sample_label&controllers=sample_label&action=', texts: {} };
    var fixedTexts = CFG.texts || {};

    function post(action, payload) {
        var body = new URLSearchParams();
        Object.keys(payload || {}).forEach(function (k) { body.append(k, payload[k]); });
        return fetch(CFG.baseUrl + action, {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body: body.toString()
        }).then(function (r) { return r.json(); });
    }

    function escapeHtml(s) {
        return String(s == null ? '' : s)
            .replace(/&/g, '&amp;').replace(/</g, '&lt;')
            .replace(/>/g, '&gt;').replace(/"/g, '&quot;').replace(/'/g, '&#39;');
    }

    var $search   = document.getElementById('sl-search');
    var $dropdown = document.getElementById('sl-search-dropdown');
    var $grid     = document.getElementById('sl-grid');
    var $gridEmpty= document.getElementById('sl-grid-empty');
    var $tpl      = document.getElementById('sl-tpl-card');
    var $btnPrint = document.getElementById('sl-btn-print');
    var $btnReset = document.getElementById('sl-btn-reset');

    var activeIdx = -1;
    var searchTimer = null;

    function updateGridEmpty() {
        $gridEmpty.style.display = $grid.querySelectorAll('.sl-card-wrap').length ? 'none' : '';
    }

    /* ====================================================================
     *  Ô tìm kiếm sản phẩm — chọn nhiều lần liên tiếp (mỗi lần chọn = 1 tem mới)
     * ==================================================================== */
    function hideDropdown() {
        $dropdown.classList.remove('active');
        $dropdown.innerHTML = '';
        activeIdx = -1;
    }

    function renderDropdown(items) {
        activeIdx = -1;
        if (!items.length) {
            $dropdown.innerHTML = '<li class="empty">Không tìm thấy sản phẩm</li>';
        } else {
            $dropdown.innerHTML = items.map(function (it, i) {
                return '<li data-idx="' + i + '" data-name="' + escapeHtml(it.name) + '">' + escapeHtml(it.name) + '</li>';
            }).join('');
        }
        $dropdown.classList.add('active');
    }

    function pad2(n) { return String(n).padStart(2, '0'); }
    function todayDisplay() {
        var d = new Date();
        return pad2(d.getDate()) + '/' + pad2(d.getMonth() + 1) + '/' + d.getFullYear();
    }

    function addCard(name) {
        var wrap = $tpl.content.firstElementChild.cloneNode(true);
        wrap.querySelector('.sl-card-name').textContent = name;
        wrap.querySelector('.sl-card-date').textContent = todayDisplay();
        Array.prototype.forEach.call(wrap.querySelectorAll('.sl-fixed-editable'), function (el) {
            var field = el.dataset.field;
            el.querySelector('.sl-fixed-text').textContent = fixedTexts[field] || '';
        });
        wrap.querySelector('.sl-card-del').addEventListener('click', function () {
            wrap.remove();
            updateGridEmpty();
        });
        $grid.appendChild(wrap);
        updateGridEmpty();
    }

    function selectItem(name) {
        addCard(name);
        $search.value = '';
        hideDropdown();
        $search.focus();
    }

    $search.addEventListener('input', function () {
        var kw = $search.value.trim();
        clearTimeout(searchTimer);
        if (kw === '') { hideDropdown(); return; }
        searchTimer = setTimeout(function () {
            post('search_product', { keyword: kw }).then(function (res) {
                renderDropdown(res.data || []);
            });
        }, 220);
    });

    $search.addEventListener('keydown', function (e) {
        if (!$dropdown.classList.contains('active')) return;
        var lis = $dropdown.querySelectorAll('li:not(.empty)');
        if (!lis.length) return;
        if (e.key === 'ArrowDown') {
            e.preventDefault();
            activeIdx = (activeIdx + 1) % lis.length;
        } else if (e.key === 'ArrowUp') {
            e.preventDefault();
            activeIdx = (activeIdx - 1 + lis.length) % lis.length;
        } else if (e.key === 'Enter' || e.key === 'Tab') {
            if (activeIdx >= 0) {
                e.preventDefault();
                selectItem(lis[activeIdx].getAttribute('data-name'));
            }
            return;
        } else if (e.key === 'Escape') {
            hideDropdown();
            return;
        } else {
            return;
        }
        lis.forEach(function (li) { li.classList.remove('active'); });
        lis[activeIdx].classList.add('active');
        lis[activeIdx].scrollIntoView({ block: 'nearest' });
    });

    $dropdown.addEventListener('click', function (e) {
        var li = e.target.closest('li');
        if (!li || li.classList.contains('empty')) return;
        selectItem(li.getAttribute('data-name'));
    });

    document.addEventListener('click', function (e) {
        if (!e.target.closest('.sl-search-wrap')) hideDropdown();
    });

    /* ====================================================================
     *  Hạn dùng: toggle 2 tùy chọn (12 tháng / 1 năm), riêng theo từng tem
     * ==================================================================== */
    $grid.addEventListener('click', function (e) {
        var opt = e.target.closest('.sl-shelf-opt');
        if (!opt || opt.classList.contains('active')) return;
        var group = opt.closest('.sl-shelf-toggle');
        Array.prototype.forEach.call(group.querySelectorAll('.sl-shelf-opt'), function (b) { b.classList.remove('active'); });
        opt.classList.add('active');
    });

    /* ====================================================================
     *  Sửa tại chỗ Khối lượng/Ghi chú/Cảnh báo: hover hiện bút, sửa 1 lần
     *  lưu app_settings, đồng bộ ngay mọi tem đang hiển thị (như tea_label).
     * ==================================================================== */
    function enterFixedEdit(wrap) {
        if (!wrap || wrap.classList.contains('editing')) return;
        var txt = wrap.querySelector('.sl-fixed-text');
        wrap.classList.add('editing');
        txt.dataset.orig = txt.textContent;
        txt.setAttribute('contenteditable', 'true');
        txt.focus();
        var range = document.createRange();
        range.selectNodeContents(txt);
        var sel = window.getSelection();
        sel.removeAllRanges();
        sel.addRange(range);
    }

    function commitFixedEdit(wrap) {
        if (!wrap || !wrap.classList.contains('editing')) return;
        var field = wrap.dataset.field;
        var txt = wrap.querySelector('.sl-fixed-text');
        wrap.classList.remove('editing');
        txt.removeAttribute('contenteditable');
        var val = (txt.textContent || '').replace(/\s+/g, ' ').trim();
        txt.textContent = val;
        if (!field || val === fixedTexts[field]) return;
        fixedTexts[field] = val;
        Array.prototype.forEach.call(
            $grid.querySelectorAll('.sl-fixed-editable[data-field="' + field + '"] .sl-fixed-text'),
            function (t) { t.textContent = val; }
        );
        post('save_fixed_text', { key: field, value: val }).then(function (res) {
            if (!res || !res.success) console.warn('Lưu thất bại:', res && res.message);
        });
    }

    $grid.addEventListener('click', function (e) {
        var wrap = e.target.closest('.sl-fixed-editable');
        if (wrap && !wrap.classList.contains('editing')) { e.preventDefault(); enterFixedEdit(wrap); }
    });
    $grid.addEventListener('blur', function (e) {
        if (e.target.classList && e.target.classList.contains('sl-fixed-text')) {
            commitFixedEdit(e.target.closest('.sl-fixed-editable'));
        }
    }, true);
    $grid.addEventListener('keydown', function (e) {
        if (!e.target.classList || !e.target.classList.contains('sl-fixed-text')) return;
        if (e.key === 'Enter') { e.preventDefault(); e.target.blur(); }
        else if (e.key === 'Escape') {
            e.target.textContent = e.target.dataset.orig || '';
            var wrap = e.target.closest('.sl-fixed-editable');
            wrap.classList.remove('editing');
            e.target.removeAttribute('contenteditable');
        }
    });

    /* ====================================================================
     *  Sửa tại chỗ Tên sản phẩm / Ngày sản xuất: hover hiện bút, sửa RIÊNG
     *  theo từng tem — không lưu server, không đồng bộ chéo card (khác
     *  .sl-fixed-editable ở trên). Tên có thể gõ tự do, không cần khớp DB.
     * ==================================================================== */
    function enterInlineEdit(wrap) {
        if (!wrap || wrap.classList.contains('editing')) return;
        var txt = wrap.querySelector('.sl-inline-text');
        wrap.classList.add('editing');
        txt.dataset.orig = txt.textContent;
        txt.setAttribute('contenteditable', 'true');
        txt.focus();
        var range = document.createRange();
        range.selectNodeContents(txt);
        var sel = window.getSelection();
        sel.removeAllRanges();
        sel.addRange(range);
    }

    function commitInlineEdit(wrap) {
        if (!wrap || !wrap.classList.contains('editing')) return;
        var txt = wrap.querySelector('.sl-inline-text');
        wrap.classList.remove('editing');
        txt.removeAttribute('contenteditable');
        var val = (txt.textContent || '').replace(/\s+/g, ' ').trim();
        txt.textContent = val === '' ? (txt.dataset.orig || '') : val;
    }

    $grid.addEventListener('click', function (e) {
        var wrap = e.target.closest('.sl-inline-editable');
        if (wrap && !wrap.classList.contains('editing')) { e.preventDefault(); enterInlineEdit(wrap); }
    });
    $grid.addEventListener('blur', function (e) {
        if (e.target.classList && e.target.classList.contains('sl-inline-text')) {
            commitInlineEdit(e.target.closest('.sl-inline-editable'));
        }
    }, true);
    $grid.addEventListener('keydown', function (e) {
        if (!e.target.classList || !e.target.classList.contains('sl-inline-text')) return;
        if (e.key === 'Enter') { e.preventDefault(); e.target.blur(); }
        else if (e.key === 'Escape') {
            e.target.textContent = e.target.dataset.orig || '';
            var wrap = e.target.closest('.sl-inline-editable');
            wrap.classList.remove('editing');
            e.target.removeAttribute('contenteditable');
        }
    });

    /* ====================================================================
     *  Xóa tất cả + In A4 (in thẳng lưới tem, giống production_label)
     * ==================================================================== */
    $btnReset.addEventListener('click', function () {
        if (!$grid.querySelectorAll('.sl-card-wrap').length) return;
        if (!confirm('Xóa tất cả tem đang hiển thị?')) return;
        Array.prototype.forEach.call($grid.querySelectorAll('.sl-card-wrap'), function (c) { c.remove(); });
        updateGridEmpty();
    });

    $btnPrint.addEventListener('click', function () {
        if (!$grid.querySelectorAll('.sl-card-wrap').length) { alert('Chưa có tem nào để in.'); return; }
        window.print();
    });

    updateGridEmpty();
})();
