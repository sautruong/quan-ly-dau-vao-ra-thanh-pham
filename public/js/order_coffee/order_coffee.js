/* =====================================================================
 *  Đặt hàng cà phê (order_coffee)
 *  KHỐI 1: Thiết lập SP + Sổ bao bì  |  KHỐI 2: Đơn đặt hàng (theo nhóm NL)
 * ===================================================================== */
(function () {
    'use strict';

    var CFG = window.OC_CONFIG || { baseUrl: '?mod=order_coffee&controllers=order_coffee&action=', recipes: [], orders: [], signRoles: [], signs: [] };

    /* ---------- State ---------- */
    var supplier = null;            // { id, supplier_name }
    var recipes = Array.isArray(CFG.recipes) ? CFG.recipes.slice() : [];
    var editProduct = null;         // { id, name, unit } đang thiết lập
    var docGroups = [];             // [{product_id, product_name, order_qty, note, items:[{material_id,name,unit,qty}]}]
    var docOrderType = 'process';   // loại đơn đang dựng

    /* ---------- DOM ---------- */
    var $ = function (id) { return document.getElementById(id); };

    /* ---------- Utils ---------- */
    function post(action, data) {
        var body = new URLSearchParams();
        for (var k in data) body.append(k, data[k]);
        return fetch(CFG.baseUrl + action, {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8' },
            body: body.toString()
        }).then(function (r) { return r.json(); });
    }
    function postJSON(action, obj) {
        return fetch(CFG.baseUrl + action, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json; charset=UTF-8' },
            body: JSON.stringify(obj)
        }).then(function (r) { return r.json(); });
    }
    function debounce(fn, ms) { var t; return function () { var a = arguments, c = this; clearTimeout(t); t = setTimeout(function () { fn.apply(c, a); }, ms); }; }
    function esc(s) {
        return String(s == null ? '' : s).replace(/[&<>"]/g, function (c) {
            return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;' }[c];
        });
    }
    function num(n, dec) {
        if (dec === undefined) dec = 2;
        var v = Math.round((Number(n) + Number.EPSILON) * Math.pow(10, dec)) / Math.pow(10, dec);
        if (!isFinite(v)) v = 0;
        return v.toLocaleString('vi-VN', { maximumFractionDigits: dec });
    }
    function parseNum(s) { var v = parseFloat(String(s).replace(/\./g, '').replace(',', '.')); return isFinite(v) ? v : 0; }
    function money(n) { return Math.round(Number(n) || 0).toLocaleString('en-US') + ' đ'; }
    function formatDate(s) {
        if (!s) return '';
        var m = String(s).match(/(\d{4})-(\d{2})-(\d{2})[ T]?(\d{2})?:?(\d{2})?/);
        if (!m) return s;
        var d = m[3] + '/' + m[2] + '/' + m[1];
        if (m[4]) d += ' ' + m[4] + ':' + (m[5] || '00');
        return d;
    }

    /* ---------- Modal helpers ---------- */
    function openModal(id) { var m = $(id); if (m) m.style.display = 'flex'; }
    function closeModal(id) { var m = $(id); if (m) m.style.display = 'none'; }
    // Chỉ tự đóng modal khi CẢ mousedown lẫn click đều nhắm thẳng vào lớp nền (click ra ngoài thật sự).
    // Nếu không kiểm tra mousedown, một click bắt đầu trên item dropdown autocomplete (item đó bị ẩn
    // đi ngay trong lúc mousedown) có thể bị trình duyệt "chuyển hướng" target sang lớp nền ở bước
    // mouseup/click, khiến modal tự đóng dù người dùng đang chọn item chứ không hề bấm ra ngoài.
    var maskMousedownTarget = null;
    document.addEventListener('mousedown', function (e) { maskMousedownTarget = e.target; });
    document.addEventListener('click', function (e) {
        var c = e.target.getAttribute && e.target.getAttribute('data-close');
        if (c) closeModal(c);
        if (e.target.classList && e.target.classList.contains('oc-modal-mask') && maskMousedownTarget === e.target) {
            e.target.style.display = 'none';
        }
    });
    // Đóng dropdown khi click ra ngoài.
    document.addEventListener('click', function (e) {
        if (!e.target.closest('.oc-search-wrap')) {
            Array.prototype.forEach.call(document.querySelectorAll('.oc-search-dropdown.open'),
                function (d) { d.classList.remove('open'); });
        }
    });

    /* =====================================================================
     *  Autocomplete dùng chung (↑↓ / Tab / Enter)
     * ===================================================================== */
    function attachSearch(input, drop, action, onSelect) {
        var list = [], active = -1;
        function render() {
            if (!list.length) {
                drop.innerHTML = '<li class="oc-sd-empty">Không tìm thấy.</li>';
                drop.classList.add('open'); return;
            }
            var html = '';
            list.forEach(function (s, i) {
                var label = s.name || s.supplier_name || '';
                var sub = s.unit ? ('ĐV: ' + s.unit) : (s.phone_number || s.classification || '');
                html += '<li data-i="' + i + '"' + (i === active ? ' class="active"' : '') + '>'
                    + '<span>' + esc(label) + '</span>'
                    + (sub ? '<small>' + esc(sub) + '</small>' : '') + '</li>';
            });
            drop.innerHTML = html; drop.classList.add('open');
        }
        function close() { drop.classList.remove('open'); active = -1; }
        var doSearch = debounce(function () {
            post(action, { keyword: input.value.trim() }).then(function (res) {
                list = (res && res.data) || [];
                active = list.length ? 0 : -1;
                render();
            });
        }, 180);
        input.addEventListener('input', doSearch);
        input.addEventListener('focus', function () { if (input.value.trim() !== '') doSearch(); });
        input.addEventListener('keydown', function (e) {
            if (!drop.classList.contains('open')) { if (e.key === 'ArrowDown') doSearch(); return; }
            if (e.key === 'ArrowDown') { e.preventDefault(); active = Math.min(active + 1, list.length - 1); render(); }
            else if (e.key === 'ArrowUp') { e.preventDefault(); active = Math.max(active - 1, 0); render(); }
            else if (e.key === 'Enter' || e.key === 'Tab') {
                if (active >= 0 && list[active]) { e.preventDefault(); onSelect(list[active]); close(); }
            } else if (e.key === 'Escape') { close(); }
        });
        drop.addEventListener('mousedown', function (e) {
            var li = e.target.closest('li[data-i]'); if (!li) return;
            e.preventDefault();
            var i = parseInt(li.getAttribute('data-i'), 10);
            // close() được hoãn 1 tick: nếu ẩn dropdown ngay trong mousedown, item vừa bấm biến mất
            // khỏi layout trước khi mouseup xảy ra -> trình duyệt tính lại target của click thành
            // phần tử tổ tiên (có thể là lớp nền modal) -> tự đóng modal ngoài ý muốn.
            if (list[i]) { onSelect(list[i]); setTimeout(close, 0); }
        });
        return { close: close };
    }

    /** Autocomplete cục bộ (danh sách lấy từ hàm listFn(), không gọi server) — dùng cho chọn SP trong modal đặt hàng. */
    function attachLocalSearch(input, drop, listFn, onSelect) {
        var list = [], active = -1;
        function render() {
            if (!list.length) {
                drop.innerHTML = '<li class="oc-sd-empty">Không tìm thấy.</li>';
                drop.classList.add('open'); return;
            }
            var html = '';
            list.forEach(function (r, i) {
                html += '<li data-i="' + i + '"' + (i === active ? ' class="active"' : '') + '><span>'
                    + esc(r.product_name) + '</span></li>';
            });
            drop.innerHTML = html; drop.classList.add('open');
        }
        function close() { drop.classList.remove('open'); active = -1; }
        function search() {
            var kw = input.value.trim().toLowerCase();
            var all = listFn();
            list = kw === '' ? all.slice() : all.filter(function (r) { return (r.product_name || '').toLowerCase().indexOf(kw) !== -1; });
            active = list.length ? 0 : -1;
            render();
        }
        input.addEventListener('input', search);
        input.addEventListener('focus', search);
        input.addEventListener('keydown', function (e) {
            if (!drop.classList.contains('open')) { if (e.key === 'ArrowDown') search(); return; }
            if (e.key === 'ArrowDown') { e.preventDefault(); active = Math.min(active + 1, list.length - 1); render(); }
            else if (e.key === 'ArrowUp') { e.preventDefault(); active = Math.max(active - 1, 0); render(); }
            else if (e.key === 'Enter' || e.key === 'Tab') {
                if (active >= 0 && list[active]) { e.preventDefault(); onSelect(list[active]); close(); }
            } else if (e.key === 'Escape') { close(); }
        });
        drop.addEventListener('mousedown', function (e) {
            var li = e.target.closest('li[data-i]'); if (!li) return;
            e.preventDefault();
            var i = parseInt(li.getAttribute('data-i'), 10);
            // Hoãn close() 1 tick (xem lý do ở attachSearch) để tránh modal tự đóng khi bấm chọn
            // một item nằm tràn ra ngoài rìa dưới modal (item cuối của danh sách dài).
            if (list[i]) { onSelect(list[i]); setTimeout(close, 0); }
        });
        return { close: close };
    }

    /* =====================================================================
     *  Chọn nhà cung cấp (phiếu + sổ bao bì)
     * ===================================================================== */
    var supInput = $('oc-supplier-input'), supDrop = $('oc-supplier-dropdown');
    var docSupplier = $('oc-doc-supplier');
    var btnSupInfo = $('oc-btn-supplier-info');

    attachSearch(supInput, supDrop, 'search_suppliers', function (s) { selectSupplier(s); });

    /* Nhớ NCC đã chọn ở lần trước — vào lại trang không cần search lại, chỉ khi
       chủ động đổi NCC khác thì mới search lại. */
    var LAST_SUPPLIER_KEY = 'oc_last_supplier';
    function saveLastSupplier(s) {
        try { localStorage.setItem(LAST_SUPPLIER_KEY, JSON.stringify({ id: s.id, supplier_name: s.supplier_name })); } catch (e) { /* ignore */ }
    }

    function selectSupplier(s) {
        supplier = { id: s.id, supplier_name: s.supplier_name };
        supInput.value = s.supplier_name;
        docSupplier.textContent = s.supplier_name;
        btnSupInfo.disabled = false;
        ['oc-btn-pkg-opening', 'oc-btn-pkg-send', 'oc-btn-pkg-loss'].forEach(function (id) { $(id).disabled = false; });
        saveLastSupplier(supplier);
        loadPackagingBook();
    }

    (function restoreLastSupplier() {
        var raw = null;
        try { raw = localStorage.getItem(LAST_SUPPLIER_KEY); } catch (e) { /* ignore */ }
        if (!raw) return;
        var saved = null;
        try { saved = JSON.parse(raw); } catch (e) { /* ignore */ }
        if (saved && saved.id) selectSupplier(saved);
    })();

    /* Thông tin NCC */
    var SUP_FIELDS = [
        ['supplier_name', 'Tên NCC'], ['phone_number', 'Điện thoại'],
        ['email', 'Email'], ['website', 'Website'], ['address', 'Địa chỉ']
    ];
    btnSupInfo.addEventListener('click', function () {
        if (!supplier) return;
        post('supplier_info', { supplier_id: supplier.id }).then(function (res) {
            var d = (res && res.data) || {};
            var html = SUP_FIELDS.map(function (f) {
                return '<div class="oc-sup-field"><label>' + esc(f[1]) + '</label>'
                    + '<input type="text" data-field="' + f[0] + '" value="' + esc(d[f[0]] || '') + '"></div>';
            }).join('');
            html += '<div class="oc-sup-hint">Sửa trực tiếp — rời khỏi ô là tự lưu.</div>';
            $('oc-sup-info').innerHTML = html;
            openModal('oc-modal-supplier');
        });
    });
    $('oc-sup-info').addEventListener('change', function (e) {
        var inp = e.target.closest('input[data-field]');
        if (!inp || !supplier) return;
        var data = { supplier_id: supplier.id };
        data[inp.getAttribute('data-field')] = inp.value;
        post('update_supplier', data).then(function (res) {
            if (res && res.success) {
                inp.classList.add('is-saved');
                setTimeout(function () { inp.classList.remove('is-saved'); }, 1200);
                if (inp.getAttribute('data-field') === 'supplier_name') {
                    supplier.supplier_name = inp.value;
                    docSupplier.textContent = inp.value;
                    supInput.value = inp.value;
                }
            }
        });
    });

    /* =====================================================================
     *  KHỐI 1 — Thiết lập sản phẩm
     * ===================================================================== */
    var prodInput = $('oc-prod-input'), prodDrop = $('oc-prod-dropdown');
    var matsBox = $('oc-recipe-mats');
    var pkgInput = $('oc-pkg-input'), pkgDrop = $('oc-pkg-dropdown');
    var pkgChosen = null;           // { id, name } bao bì thiết lập
    var matRowTpl = $('oc-recipe-mat-tpl');

    attachSearch(prodInput, prodDrop, 'search_products', function (p) {
        editProduct = { id: p.id, name: p.name, unit: p.unit };
        prodInput.value = p.name;
    });
    attachSearch(pkgInput, pkgDrop, 'search_packaging', function (p) {
        pkgChosen = { id: p.id, name: p.name };
        pkgInput.value = p.name;
    });
    // Nếu xóa trắng ô bao bì -> bỏ chọn.
    pkgInput.addEventListener('input', function () { if (pkgInput.value.trim() === '') pkgChosen = null; });

    function addMatRow(prefill) {
        var row = matRowTpl.content.firstElementChild.cloneNode(true);
        var nameInp = row.querySelector('.oc-rm-name');
        var unitInp = row.querySelector('.oc-rm-unit');
        var qtyInp = row.querySelector('.oc-rm-qty');
        var drop = row.querySelector('.oc-rm-dropdown');
        attachSearch(nameInp, drop, 'search_materials', function (m) {
            nameInp.value = m.name;
            nameInp.setAttribute('data-mid', m.id);
            if (m.unit && !unitInp.value) unitInp.value = m.unit;
            qtyInp.focus();
        });
        nameInp.addEventListener('input', function () { nameInp.setAttribute('data-mid', '0'); });
        row.querySelector('.oc-rm-del').addEventListener('click', function () { row.remove(); });
        if (prefill) {
            nameInp.value = prefill.name || '';
            nameInp.setAttribute('data-mid', prefill.material_id || 0);
            unitInp.value = prefill.unit || '';
            qtyInp.value = prefill.qty ? num(prefill.qty, 3) : '';
        }
        matsBox.appendChild(row);
        return row;
    }

    $('oc-btn-add-mat').addEventListener('click', function () { addMatRow(); });

    function collectRecipe() {
        var mats = [];
        Array.prototype.forEach.call(matsBox.querySelectorAll('.oc-recipe-mat'), function (row) {
            var nameInp = row.querySelector('.oc-rm-name');
            var nm = nameInp.value.trim();
            if (nm === '') return;
            mats.push({
                material_id: parseInt(nameInp.getAttribute('data-mid'), 10) || 0,
                name: nm,
                unit: row.querySelector('.oc-rm-unit').value.trim(),
                qty: parseNum(row.querySelector('.oc-rm-qty').value)
            });
        });
        return {
            product_id: editProduct ? editProduct.id : 0,
            product_name: editProduct ? editProduct.name : prodInput.value.trim(),
            yield_qty: parseNum($('oc-yield').value) || 1,
            packaging_id: pkgChosen ? pkgChosen.id : 0,
            packaging_name: pkgChosen ? pkgChosen.name : '',
            note: $('oc-recipe-note').value.trim(),
            materials: mats
        };
    }

    function resetRecipeForm() {
        editProduct = null; pkgChosen = null;
        prodInput.value = ''; pkgInput.value = '';
        $('oc-yield').value = '1';
        $('oc-recipe-note').value = '';
        matsBox.innerHTML = '';
        addMatRow();
    }

    $('oc-btn-reset-recipe').addEventListener('click', resetRecipeForm);

    $('oc-btn-save-recipe').addEventListener('click', function () {
        var payload = collectRecipe();
        if (!payload.product_id) { alert('Chưa chọn sản phẩm.'); return; }
        if (!payload.materials.length) { alert('Chưa có nguyên liệu nào.'); return; }
        var btn = this; btn.disabled = true;
        postJSON('save_recipe', payload).then(function (res) {
            btn.disabled = false;
            if (res && res.success) {
                recipes = res.recipes || [];
                renderRecipeList();
                resetRecipeForm();
            } else { alert((res && res.message) || 'Lưu thiết lập thất bại.'); }
        });
    });

    /* Phân trang danh sách "Sản phẩm đã thiết lập" — số lượng hiển thị nhớ lại qua localStorage. */
    var RECIPE_PAGE_SIZE_KEY = 'oc_recipe_page_size';
    var recipePage = 1;
    var recipePageSize = (function () {
        var saved = null;
        try { saved = localStorage.getItem(RECIPE_PAGE_SIZE_KEY); } catch (e) { /* ignore */ }
        if (saved !== null) return parseInt(saved, 10) || 0;
        return parseInt(($('oc-recipe-page-size') || {}).value, 10) || 8;
    })();
    if ($('oc-recipe-page-size')) $('oc-recipe-page-size').value = String(recipePageSize);

    function renderRecipeList() {
        var box = $('oc-recipe-list');
        var pager = $('oc-recipe-pager');
        if (!recipes.length) {
            box.innerHTML = '<div class="oc-recipe-empty">Chưa có sản phẩm nào được thiết lập.</div>';
            if (pager) pager.style.display = 'none';
            return;
        }
        var pageSize = recipePageSize > 0 ? recipePageSize : recipes.length;
        var totalPages = Math.max(1, Math.ceil(recipes.length / pageSize));
        if (recipePage > totalPages) recipePage = totalPages;
        if (recipePage < 1) recipePage = 1;
        var start = (recipePage - 1) * pageSize;
        var pageItems = recipes.slice(start, start + pageSize);

        if (pager) {
            pager.style.display = 'flex';
            $('oc-recipe-page-info').textContent = recipePage + '/' + totalPages;
            $('oc-recipe-page-prev').disabled = recipePage <= 1;
            $('oc-recipe-page-next').disabled = recipePage >= totalPages;
        }

        box.innerHTML = '';
        pageItems.forEach(function (r) {
            var matsHtml = (r.materials || []).map(function (m) {
                return '<li>' + esc(m.name) + ' (' + num(m.qty, 3) + ' ' + esc(m.unit || '') + ')</li>';
            }).join('');
            var metaHtml = r.packaging_name ? ('Bao bì: ' + esc(r.packaging_name)) : '';
            var div = document.createElement('div');
            div.className = 'oc-recipe-item';
            div.innerHTML =
                '<div class="oc-recipe-item-head">'
                + '<span class="oc-recipe-item-name">' + esc(r.product_name) + '</span>'
                + '<span class="oc-recipe-item-act">'
                + '<button type="button" class="oc-ri-btn" data-act="edit"><i class="fa-solid fa-pen"></i></button>'
                + '<button type="button" class="oc-ri-btn danger" data-act="del"><i class="fa-solid fa-trash"></i></button>'
                + '</span></div>'
                + '<div class="oc-recipe-item-mats"><ul class="oc-ri-mats-list">' + matsHtml + '</ul>'
                + '<div class="oc-ri-total">Tổng sản phẩm: ' + num(r.yield_qty, 0) + ' thành phẩm</div></div>'
                + (metaHtml ? '<div class="oc-recipe-item-meta">' + metaHtml + '</div>' : '')
                + (r.note ? '<div class="oc-recipe-item-note"><i class="fa-solid fa-note-sticky"></i> ' + esc(r.note) + '</div>' : '');
            div.querySelector('[data-act="edit"]').addEventListener('click', function () { editRecipe(r); });
            div.querySelector('[data-act="del"]').addEventListener('click', function () { delRecipe(r); });
            box.appendChild(div);
        });
    }

    var recipePageSizeSel = $('oc-recipe-page-size');
    if (recipePageSizeSel) {
        recipePageSizeSel.addEventListener('change', function () {
            recipePageSize = parseInt(recipePageSizeSel.value, 10) || 0;
            recipePage = 1;
            try { localStorage.setItem(RECIPE_PAGE_SIZE_KEY, String(recipePageSize)); } catch (e) { /* ignore */ }
            renderRecipeList();
        });
    }
    var recipePagePrev = $('oc-recipe-page-prev');
    if (recipePagePrev) recipePagePrev.addEventListener('click', function () { recipePage--; renderRecipeList(); });
    var recipePageNext = $('oc-recipe-page-next');
    if (recipePageNext) recipePageNext.addEventListener('click', function () { recipePage++; renderRecipeList(); });

    // Khối "Thiết lập sản phẩm" dạng FAQ — mặc định thu lại, bấm tiêu đề để mở/đóng.
    var setupFaq = $('oc-setup-faq');
    $('oc-setup-faq-head').addEventListener('click', function () { setupFaq.classList.toggle('is-collapsed'); });

    // Khối "Sản phẩm đã thiết lập" dạng FAQ — mặc định thu lại, bấm tiêu đề để mở/đóng.
    var recipeListFaq = $('oc-recipe-list-faq');
    $('oc-recipe-list-faq-head').addEventListener('click', function () { recipeListFaq.classList.toggle('is-collapsed'); });

    function editRecipe(r) {
        setupFaq.classList.remove('is-collapsed');
        editProduct = { id: r.product_id, name: r.product_name, unit: '' };
        prodInput.value = r.product_name;
        $('oc-yield').value = num(r.yield_qty, 3);
        $('oc-recipe-note').value = r.note || '';
        pkgChosen = r.packaging_id ? { id: r.packaging_id, name: r.packaging_name } : null;
        pkgInput.value = r.packaging_name || '';
        matsBox.innerHTML = '';
        (r.materials || []).forEach(function (m) { addMatRow(m); });
        if (!r.materials || !r.materials.length) addMatRow();
        $('oc-prod-input').scrollIntoView({ behavior: 'smooth', block: 'center' });
    }

    function delRecipe(r) {
        if (!confirm('Xóa thiết lập "' + r.product_name + '"?')) return;
        post('delete_recipe', { id: r.id }).then(function (res) {
            if (res && res.success) { recipes = res.recipes || []; renderRecipeList(); }
        });
    }

    /* =====================================================================
     *  KHỐI 1 — Sổ bao bì tại NCC
     * ===================================================================== */
    function loadPackagingBook() {
        if (!supplier) return;
        ledgerPage = 1;
        post('packaging_book', { supplier_id: supplier.id }).then(function (res) {
            renderPackagingBook((res && res.data) || { entries: [], balances: [], total: 0 });
        });
    }

    /* Phân trang sổ bao bì — số dòng/trang nhớ lại qua localStorage. */
    var LEDGER_PAGE_SIZE_KEY = 'oc_ledger_page_size';
    var ledgerPage = 1;
    var lastBook = { entries: [], balances: [], total: 0 };
    var ledgerPageSize = (function () {
        var saved = null;
        try { saved = localStorage.getItem(LEDGER_PAGE_SIZE_KEY); } catch (e) { /* ignore */ }
        if (saved !== null) return parseInt(saved, 10) || 0;
        return parseInt(($('oc-ledger-page-size') || {}).value, 10) || 20;
    })();
    if ($('oc-ledger-page-size')) $('oc-ledger-page-size').value = String(ledgerPageSize);

    function pkgHeadRowHtml(b) {
        return '<tr class="oc-lg-pkg-head"><td colspan="4">'
            + esc(b.packaging_name || ('Bao bì #' + b.packaging_id))
            + ' — Số dư: <span class="' + (b.balance < 0 ? 'oc-lg-out' : 'oc-lg-in') + '">'
            + num(b.balance, 0) + '</span></td></tr>';
    }

    function renderPackagingBook(book) {
        lastBook = book || { entries: [], balances: [], total: 0 };
        var tb = $('oc-ledger-tbody');
        var pager = $('oc-ledger-pager');
        var balances = lastBook.balances || [], entries = lastBook.entries || [];
        if (!entries.length) {
            tb.innerHTML = '<tr class="oc-lg-empty"><td colspan="4">Chưa có nghiệp vụ bao bì nào với NCC này.</td></tr>';
            $('oc-ledger-total').style.display = 'none';
            if (pager) pager.style.display = 'none';
            return;
        }

        // Làm phẳng thành các dòng: 1 dòng header/nhóm bao bì + các dòng nghiệp vụ của nhóm đó,
        // để có thể phân trang theo số dòng như một bảng bình thường.
        var rows = [];
        balances.forEach(function (b) {
            rows.push({ kind: 'head', b: b });
            entries.filter(function (e) { return e.packaging_id === b.packaging_id; }).forEach(function (e) {
                rows.push({ kind: 'entry', e: e, b: b });
            });
        });

        var pageSize = ledgerPageSize > 0 ? ledgerPageSize : rows.length;
        var totalPages = Math.max(1, Math.ceil(rows.length / pageSize));
        if (ledgerPage > totalPages) ledgerPage = totalPages;
        if (ledgerPage < 1) ledgerPage = 1;
        var start = (ledgerPage - 1) * pageSize;
        var pageRows = rows.slice(start, start + pageSize);

        if (pager) {
            pager.style.display = 'flex';
            $('oc-ledger-page-info').textContent = ledgerPage + '/' + totalPages;
            $('oc-ledger-page-prev').disabled = ledgerPage <= 1;
            $('oc-ledger-page-next').disabled = ledgerPage >= totalPages;
        }

        var html = '';
        // Nếu trang bắt đầu giữa 1 nhóm (dòng đầu là nghiệp vụ), lặp lại header nhóm đó
        // để không mất ngữ cảnh đang xem bao bì nào.
        if (pageRows.length && pageRows[0].kind === 'entry') html += pkgHeadRowHtml(pageRows[0].b);
        pageRows.forEach(function (row) {
            if (row.kind === 'head') { html += pkgHeadRowHtml(row.b); return; }
            var e = row.e;
            var signCls = e.qty < 0 ? 'oc-lg-out' : 'oc-lg-in';
            var q = (e.qty < 0 ? '' : '+') + num(e.qty, 0);
            // Icon ảnh dẫn chứng (chụp tin nhắn xác nhận tồn với NCC) đặt DƯỚI ngày — click phóng to.
            var evHtml = '';
            (e.evidence || []).forEach(function (ev) {
                evHtml += '<span class="oc-lg-evid" data-id="' + ev.id + '" data-src="' + esc(ev.file_path) + '" title="Xem ảnh dẫn chứng"><i class="fa-solid fa-image"></i></span>';
            });
            html += '<tr>'
                + '<td class="oc-lg-date" data-entry-id="' + (e.id || 0) + '" title="Click rồi Ctrl+V để dán ảnh dẫn chứng chốt tồn">'
                + '<span class="oc-lg-date-text">' + esc(formatDate(e.entry_date || e.created_at)) + '</span>'
                + (evHtml ? '<span class="oc-lg-evid-wrap">' + evHtml + '</span>' : '')
                + '</td>'
                + '<td class="oc-lg-name">' + esc(e.packaging_name || '') + '</td>'
                + '<td class="oc-lg-content">' + esc(e.content || '') + '</td>'
                + '<td class="oc-lg-qty ' + signCls + '">' + q + '</td>'
                + '</tr>';
        });
        tb.innerHTML = html;
        var tot = $('oc-ledger-total');
        tot.style.display = 'block';
        tot.innerHTML = 'Tổng số lượng bao bì còn lại: ' + num(lastBook.total, 0) + ' cái';
    }

    var ledgerPageSizeSel = $('oc-ledger-page-size');
    if (ledgerPageSizeSel) {
        ledgerPageSizeSel.addEventListener('change', function () {
            ledgerPageSize = parseInt(ledgerPageSizeSel.value, 10) || 0;
            ledgerPage = 1;
            try { localStorage.setItem(LEDGER_PAGE_SIZE_KEY, String(ledgerPageSize)); } catch (e) { /* ignore */ }
            renderPackagingBook(lastBook);
        });
    }
    var ledgerPagePrev = $('oc-ledger-page-prev');
    if (ledgerPagePrev) ledgerPagePrev.addEventListener('click', function () { ledgerPage--; renderPackagingBook(lastBook); });
    var ledgerPageNext = $('oc-ledger-page-next');
    if (ledgerPageNext) ledgerPageNext.addEventListener('click', function () { ledgerPage++; renderPackagingBook(lastBook); });

    /* ---- Ảnh dẫn chứng chốt tồn: click ô Ngày -> Ctrl+V dán ảnh (chụp tin nhắn xác nhận
     * tồn với NCC); icon ảnh hiện dưới ngày, click icon phóng to (lightbox). ---- */
    var evidTargetEntry = 0; // entry_id của ô Ngày đang chờ dán

    function clearEvidTarget() {
        evidTargetEntry = 0;
        document.querySelectorAll('.oc-lg-date.is-paste-target').forEach(function (td) {
            td.classList.remove('is-paste-target');
        });
    }

    document.addEventListener('click', function (e) {
        // Click icon -> mở lightbox xem ảnh (ưu tiên trước click ô Ngày vì icon nằm trong ô).
        var evIcon = e.target.closest && e.target.closest('.oc-lg-evid');
        if (evIcon) {
            openEvidLightbox(evIcon.getAttribute('data-src'), +evIcon.getAttribute('data-id'));
            return;
        }
        var dateTd = e.target.closest && e.target.closest('td.oc-lg-date[data-entry-id]');
        if (dateTd) {
            var eid = +dateTd.getAttribute('data-entry-id');
            if (!eid) return;
            clearEvidTarget();
            evidTargetEntry = eid;
            dateTd.classList.add('is-paste-target');
            return;
        }
        // Click ra ngoài -> bỏ chế độ chờ dán.
        if (evidTargetEntry) clearEvidTarget();
    });

    document.addEventListener('paste', function (e) {
        if (!evidTargetEntry || !supplier) return;
        var items = (e.clipboardData && e.clipboardData.items) || [];
        var files = [];
        Array.prototype.forEach.call(items, function (it) {
            if (it.kind === 'file' && it.type.indexOf('image/') === 0) {
                var f = it.getAsFile();
                if (f) files.push(f);
            }
        });
        if (!files.length) return;
        e.preventDefault();
        var fd = new FormData();
        fd.append('entry_id', evidTargetEntry);
        fd.append('supplier_id', supplier.id);
        files.forEach(function (f) { fd.append('files[]', f, f.name || 'paste.png'); });
        clearEvidTarget();
        fetch(CFG.baseUrl + 'ledger_evidence_upload', { method: 'POST', body: fd, credentials: 'same-origin' })
            .then(function (r) { return r.json(); })
            .then(function (res) {
                if (res && res.success) renderPackagingBook(res.data || lastBook);
                else alert((res && res.message) || 'Không lưu được ảnh dẫn chứng.');
            })
            .catch(function () { alert('Lỗi kết nối khi lưu ảnh dẫn chứng.'); });
    });

    /* Lightbox phóng to ảnh dẫn chứng — dựng 1 lần, dùng lại. */
    var evidLb = null, evidLbImg = null, evidLbDel = null, evidLbCurId = 0;
    function ensureEvidLightbox() {
        if (evidLb) return;
        evidLb = document.createElement('div');
        evidLb.className = 'oc-evid-lightbox';
        evidLb.innerHTML = '<span class="oc-evid-lb-close">&times;</span>'
            + '<img src="" alt="">'
            + '<button type="button" class="oc-evid-lb-del"><i class="fa-solid fa-trash"></i> Xóa dẫn chứng</button>';
        document.body.appendChild(evidLb);
        evidLbImg = evidLb.querySelector('img');
        evidLbDel = evidLb.querySelector('.oc-evid-lb-del');
        evidLb.querySelector('.oc-evid-lb-close').addEventListener('click', closeEvidLightbox);
        evidLb.addEventListener('click', function (e) { if (e.target === evidLb) closeEvidLightbox(); });
        document.addEventListener('keydown', function (e) {
            if (e.key === 'Escape' && evidLb.style.display === 'flex') closeEvidLightbox();
        });
        evidLbDel.addEventListener('click', function () {
            if (!evidLbCurId || !supplier) return;
            if (!confirm('Xóa ảnh dẫn chứng này?')) return;
            post('ledger_evidence_delete', { evidence_id: evidLbCurId, supplier_id: supplier.id }).then(function (res) {
                closeEvidLightbox();
                if (res && res.success) renderPackagingBook(res.data || lastBook);
            });
        });
    }
    function openEvidLightbox(src, id) {
        ensureEvidLightbox();
        evidLbCurId = id || 0;
        evidLbImg.src = src;
        evidLb.style.display = 'flex';
    }
    function closeEvidLightbox() { if (evidLb) evidLb.style.display = 'none'; evidLbCurId = 0; }

    /* Modal nghiệp vụ bao bì */
    var pkgmInput = $('oc-pkgm-input'), pkgmDrop = $('oc-pkgm-dropdown');
    var pkgmChosen = null, pkgmType = 'opening';
    attachSearch(pkgmInput, pkgmDrop, 'search_packaging', function (p) {
        pkgmChosen = { id: p.id, name: p.name };
        pkgmInput.value = p.name;
    });
    var PKG_META = {
        opening: { title: 'Tồn đầu kỳ (NCC còn)', hint: 'Số bao bì NCC đang còn giữ tại thời điểm chốt sổ — cộng vào tồn.' },
        send: { title: 'Chuyển bì cho NCC', hint: 'Số bao bì mình (VAT) gửi qua NCC — cộng vào tồn.' },
        loss: { title: 'Trừ tồn bì NCC (hao hụt)', hint: 'NCC làm mất / hao hụt bao bì của mình — trừ khỏi tồn.' }
    };
    function openPkgModal(type) {
        if (!supplier) { alert('Chọn nhà cung cấp trước.'); return; }
        pkgmType = type; pkgmChosen = null;
        $('oc-pkgm-title').textContent = PKG_META[type].title;
        $('oc-pkgm-hint').textContent = PKG_META[type].hint;
        pkgmInput.value = ''; $('oc-pkgm-qty').value = '';
        $('oc-pkgm-date').value = new Date().toISOString().slice(0, 10);
        openModal('oc-modal-pkg');
    }
    $('oc-btn-pkg-opening').addEventListener('click', function () { openPkgModal('opening'); });
    $('oc-btn-pkg-send').addEventListener('click', function () { openPkgModal('send'); });
    $('oc-btn-pkg-loss').addEventListener('click', function () { openPkgModal('loss'); });

    $('oc-pkgm-apply').addEventListener('click', function () {
        if (!supplier) return;
        if (!pkgmChosen) { alert('Chọn loại bao bì.'); return; }
        var qty = parseNum($('oc-pkgm-qty').value);
        if (qty <= 0) { alert('Số lượng phải lớn hơn 0.'); return; }
        var btn = this; btn.disabled = true;
        post('packaging_add', {
            supplier_id: supplier.id,
            packaging_id: pkgmChosen.id,
            packaging_name: pkgmChosen.name,
            entry_type: pkgmType,
            qty: qty,
            entry_date: $('oc-pkgm-date').value
        }).then(function (res) {
            btn.disabled = false;
            if (res && res.success) {
                closeModal('oc-modal-pkg');
                ledgerPage = 1;
                renderPackagingBook(res.data || { entries: [], balances: [], total: 0 });
            } else { alert((res && res.message) || 'Ghi sổ thất bại.'); }
        });
    });

    /* =====================================================================
     *  Xuất báo cáo tồn bao bì (chọn NCC + loại bao bì + mốc -> phiếu kiểu .oc-doc)
     * ===================================================================== */
    var pkgrSupInput = $('oc-pkgrsel-supplier-input'), pkgrSupDrop = $('oc-pkgrsel-supplier-dropdown');
    var pkgrPkgInput = $('oc-pkgrsel-pkg-input'), pkgrPkgDrop = $('oc-pkgrsel-pkg-dropdown');
    var pkgrMilestoneField = $('oc-pkgrsel-milestone-field'), pkgrMilestoneSel = $('oc-pkgrsel-milestone');
    var pkgrMilestoneHint = $('oc-pkgrsel-milestone-hint');
    var pkgrChosenSupplier = null, pkgrChosenPkg = null, pkgrChosenMilestoneId = 0;
    // NCC + loại bao bì + tổng đang hiển thị trong modal báo cáo (dùng cho nút "Chốt tồn").
    var pkgrReportSupplier = null, pkgrReportPkg = null, pkgrReportTotal = 0;

    function loadMilestonesForSelection() {
        if (!pkgrChosenSupplier || !pkgrChosenPkg) {
            pkgrMilestoneField.style.display = 'none';
            pkgrMilestoneSel.innerHTML = '';
            pkgrChosenMilestoneId = 0;
            return;
        }
        post('packaging_milestones', { supplier_id: pkgrChosenSupplier.id, packaging_id: pkgrChosenPkg.id }).then(function (res) {
            var list = (res && res.data) || [];
            if (!list.length) {
                pkgrMilestoneField.style.display = 'none';
                pkgrMilestoneSel.innerHTML = '';
                pkgrChosenMilestoneId = 0;
                return;
            }
            pkgrMilestoneField.style.display = '';
            pkgrMilestoneSel.innerHTML = list.map(function (m) {
                var label = formatDate(m.entry_date) + ' — ' + (m.entry_type === 'confirm' ? 'Xác nhận tồn' : 'NCC còn') + ' (' + num(m.qty, 0) + ')';
                return '<option value="' + m.id + '">' + esc(label) + '</option>';
            }).join('');
            pkgrChosenMilestoneId = list[0].id;
            pkgrMilestoneSel.value = String(pkgrChosenMilestoneId);
            pkgrMilestoneHint.textContent = 'Mặc định lấy mốc mới nhất (' + formatDate(list[0].entry_date) + '). Chọn mốc cũ hơn để xem/xuất dữ liệu từ mốc đó.';
        });
    }
    pkgrMilestoneSel.addEventListener('change', function () {
        pkgrChosenMilestoneId = parseInt(pkgrMilestoneSel.value, 10) || 0;
    });

    attachSearch(pkgrSupInput, pkgrSupDrop, 'search_suppliers', function (s) {
        pkgrChosenSupplier = { id: s.id, name: s.supplier_name };
        pkgrSupInput.value = s.supplier_name;
        loadMilestonesForSelection();
    });
    pkgrSupInput.addEventListener('input', function () { if (pkgrSupInput.value.trim() === '') { pkgrChosenSupplier = null; loadMilestonesForSelection(); } });

    attachSearch(pkgrPkgInput, pkgrPkgDrop, 'search_packaging', function (p) {
        pkgrChosenPkg = { id: p.id, name: p.name };
        pkgrPkgInput.value = p.name;
        loadMilestonesForSelection();
    });
    pkgrPkgInput.addEventListener('input', function () { if (pkgrPkgInput.value.trim() === '') { pkgrChosenPkg = null; loadMilestonesForSelection(); } });

    /* Nhớ loại bao bì chọn ở lần xuất báo cáo gần nhất — gán sẵn, muốn đổi thì search lại. */
    var LAST_PKGREPORT_PKG_KEY = 'oc_last_pkgreport_pkg';
    function saveLastReportPkg(p) {
        try { localStorage.setItem(LAST_PKGREPORT_PKG_KEY, JSON.stringify({ id: p.id, name: p.name })); } catch (e) { /* ignore */ }
    }

    $('oc-btn-pkg-report').addEventListener('click', function () {
        if (supplier) {
            pkgrChosenSupplier = { id: supplier.id, name: supplier.supplier_name };
            pkgrSupInput.value = supplier.supplier_name;
        } else {
            pkgrChosenSupplier = null; pkgrSupInput.value = '';
        }
        var raw = null;
        try { raw = localStorage.getItem(LAST_PKGREPORT_PKG_KEY); } catch (e) { /* ignore */ }
        var savedPkg = null;
        try { savedPkg = raw ? JSON.parse(raw) : null; } catch (e) { /* ignore */ }
        if (savedPkg && savedPkg.id) {
            pkgrChosenPkg = savedPkg;
            pkgrPkgInput.value = savedPkg.name;
        } else {
            pkgrChosenPkg = null; pkgrPkgInput.value = '';
        }
        loadMilestonesForSelection();
        openModal('oc-modal-pkgreport-select');
    });

    $('oc-pkgrsel-view').addEventListener('click', function () {
        if (!pkgrChosenSupplier) { alert('Chọn nhà cung cấp.'); return; }
        if (!pkgrChosenPkg) { alert('Chọn loại bao bì.'); return; }
        saveLastReportPkg(pkgrChosenPkg);
        var btn = this; btn.disabled = true;
        post('packaging_report', {
            supplier_id: pkgrChosenSupplier.id,
            packaging_id: pkgrChosenPkg.id,
            milestone_id: pkgrChosenMilestoneId
        }).then(function (res) {
            btn.disabled = false;
            var data = (res && res.data) || { entries: [], total: 0 };
            renderPkgReport(pkgrChosenSupplier, pkgrChosenPkg, data);
            closeModal('oc-modal-pkgreport-select');
            openModal('oc-modal-pkgreport');
        });
    });

    function renderPkgReport(sup, pkg, data) {
        pkgrReportSupplier = sup; pkgrReportPkg = pkg; pkgrReportTotal = (data && data.total) || 0;
        $('oc-pkgreport-supplier').textContent = sup.name;
        $('oc-pkgreport-pkgname').textContent = pkg.name;
        var entries = (data && data.entries) || [];

        var tb = $('oc-pkgreport-tbody');
        if (!entries.length) {
            tb.innerHTML = '<tr class="oc-lg-empty"><td colspan="3">Chưa có nghiệp vụ nào với loại bao bì này.</td></tr>';
        } else {
            var html = '';
            entries.forEach(function (e) {
                var signCls = e.qty < 0 ? 'oc-lg-out' : 'oc-lg-in';
                var q = (e.qty < 0 ? '' : '+') + num(e.qty, 0);
                html += '<tr>'
                    + '<td>' + esc(formatDate(e.entry_date || e.created_at)) + '</td>'
                    + '<td>' + esc(e.content || '') + '</td>'
                    + '<td class="oc-lg-qty ' + signCls + '">' + q + '</td>'
                    + '</tr>';
            });
            html += '<tr class="oc-pkgr-total-row"><td colspan="3">Tổng số lượng bao bì còn lại: ' + num(pkgrReportTotal, 0) + ' cái</td></tr>';
            tb.innerHTML = html;
        }
    }

    /* "Chốt tồn": xác nhận tổng đang hiển thị khớp với tồn thực tế phía NCC -> ghi 1 mốc mới. */
    $('oc-btn-pkgreport-confirm').addEventListener('click', function () {
        if (!pkgrReportSupplier || !pkgrReportPkg) return;
        if (!confirm('Xác nhận tồn bao bì "' + pkgrReportPkg.name + '" tại NCC "' + pkgrReportSupplier.name
            + '" hiện là ' + num(pkgrReportTotal, 0) + ' cái, khớp với thực tế phía NCC?')) return;
        var btn = this; btn.disabled = true;
        post('packaging_confirm', {
            supplier_id: pkgrReportSupplier.id,
            packaging_id: pkgrReportPkg.id,
            packaging_name: pkgrReportPkg.name
        }).then(function (res) {
            btn.disabled = false;
            if (!res || !res.success) { alert((res && res.message) || 'Chốt tồn thất bại.'); return; }
            renderPkgReport(pkgrReportSupplier, pkgrReportPkg, res.report || { entries: [], total: 0 });
            if (supplier && supplier.id === pkgrReportSupplier.id) loadPackagingBook();
        });
    });

    /* Chụp & chia sẻ báo cáo tồn bao bì (html2canvas -> clipboard), cùng cơ chế với đơn đặt hàng. */
    $('oc-btn-pkgreport-share').addEventListener('click', function () {
        var sheet = $('oc-pkgreport-sheet');
        if (typeof window.html2canvas !== 'function') { alert('Không nạp được html2canvas.'); return; }
        var btn = this, orig = btn.innerHTML;
        btn.innerHTML = 'Đang xử lý...'; btn.disabled = true;
        sheet.classList.add('is-capturing');
        var SCALE = 2;
        window.html2canvas(sheet, { scale: SCALE, backgroundColor: null, useCORS: true }).then(function (docCanvas) {
            sheet.classList.remove('is-capturing');
            var M = Math.round(20 * SCALE);
            var out = document.createElement('canvas');
            out.width = docCanvas.width + M * 2;
            out.height = docCanvas.height + M * 2;
            var ctx = out.getContext('2d');
            ctx.fillStyle = '#ffffff';
            ctx.fillRect(0, 0, out.width, out.height);
            ctx.shadowColor = 'rgba(105, 111, 121, 0.6)';
            ctx.shadowBlur = 12 * SCALE;
            ctx.shadowOffsetX = -1 * SCALE;
            ctx.shadowOffsetY = -1 * SCALE;
            ctx.drawImage(docCanvas, M, M);
            out.toBlob(function (blob) {
                function restore() { btn.innerHTML = orig; btn.disabled = false; }
                if (navigator.clipboard && window.ClipboardItem) {
                    navigator.clipboard.write([new ClipboardItem({ 'image/png': blob })]).then(function () {
                        alert('Đã copy ảnh báo cáo tồn bao bì vào clipboard.\nMở app khác (Zalo, Messenger...) và bấm Ctrl+V để dán.');
                        restore();
                    }).catch(function () { downloadCanvas(out, 'ton-bao-bi-ncc.png'); restore(); });
                } else { downloadCanvas(out, 'ton-bao-bi-ncc.png'); restore(); }
            }, 'image/png');
        }).catch(function (err) { sheet.classList.remove('is-capturing'); alert('Lỗi tạo ảnh: ' + err.message); btn.innerHTML = orig; btn.disabled = false; });
    });

    /* =====================================================================
     *  KHỐI 2 — Modal đặt hàng (chọn nhiều SP) -> dựng nhóm NL
     * ===================================================================== */
    var ordTbody = $('oc-ord-tbody'), ordRowTpl = $('oc-ord-row-tpl');

    // Ghi chú nhập 1 lần dùng nhiều lần: lưu lại vào thiết lập SP để tái sử dụng.
    function persistRecipeNote(productId, note) {
        if (!productId) return;
        var r = recipes.filter(function (x) { return x.product_id === productId; })[0];
        if (r && (r.note || '') === note) return;   // không đổi -> bỏ qua
        if (r) r.note = note;
        post('update_recipe_note', { product_id: productId, note: note });
    }

    /** Thêm 1 dòng sản phẩm trong modal đặt hàng. insertAfterEl: chèn ngay sau dòng này (mặc định: cuối bảng). */
    function addOrdRow(insertAfterEl) {
        var tr = ordRowTpl.content.firstElementChild.cloneNode(true);
        var prodInp = tr.querySelector('.oc-ot-prod-input');
        var prodDrop = tr.querySelector('.oc-ot-prod-dropdown');
        var qtyInp = tr.querySelector('.oc-ot-qty-in');
        var noteInp = tr.querySelector('.oc-ot-note-in');

        attachLocalSearch(prodInp, prodDrop, function () { return recipes; }, function (r) {
            prodInp.value = r.product_name;
            prodInp.setAttribute('data-pid', r.product_id);
            noteInp.value = r.note || '';   // đổi SP -> ghi chú đổi theo đúng SP mới, không giữ ghi chú của SP cũ
            qtyInp.focus();
        });
        prodInp.addEventListener('input', function () { prodInp.setAttribute('data-pid', '0'); });

        // Enter tại ô SL thành phẩm -> chèn thêm 1 dòng mới để nhập tiếp.
        qtyInp.addEventListener('keydown', function (e) {
            if (e.key !== 'Enter') return;
            e.preventDefault();
            var newTr = addOrdRow(tr);
            newTr.querySelector('.oc-ot-prod-input').focus();
        });

        tr.querySelector('.oc-ord-del').addEventListener('click', function () { tr.remove(); });

        if (insertAfterEl && insertAfterEl.nextSibling) ordTbody.insertBefore(tr, insertAfterEl.nextSibling);
        else ordTbody.appendChild(tr);
        return tr;
    }

    // Dòng trống (chưa chọn SP, chưa nhập SL/ghi chú) tự ẩn khi rời khỏi dòng đó.
    ordTbody.addEventListener('focusout', function (e) {
        var tr = e.target.closest('.oc-ord-row');
        if (!tr) return;
        setTimeout(function () {
            if (!tr.parentNode || tr.contains(document.activeElement)) return;
            var prodInp = tr.querySelector('.oc-ot-prod-input');
            var pid = parseInt(prodInp.getAttribute('data-pid'), 10) || 0;
            var hasText = prodInp.value.trim() !== '' || tr.querySelector('.oc-ot-qty-in').value.trim() !== ''
                || tr.querySelector('.oc-ot-note-in').value.trim() !== '';
            if (!pid && !hasText) tr.remove();
        }, 0);
    });

    $('oc-btn-open-order').addEventListener('click', function () {
        if (!recipes.length) { alert('Chưa có sản phẩm nào được thiết lập. Hãy thiết lập trước.'); return; }
        ordTbody.innerHTML = '';
        if (docGroups.length) {
            // Đang có đơn dựng sẵn trên trang -> đổ lại vào modal để chủ động chỉnh sửa, không tạo dòng trống mới.
            var typeRadio = document.querySelector('input[name="oc-order-type"][value="' + docOrderType + '"]');
            if (typeRadio) typeRadio.checked = true;
            docGroups.forEach(function (g) {
                var tr = addOrdRow();
                var prodInp = tr.querySelector('.oc-ot-prod-input');
                prodInp.value = g.product_name;
                prodInp.setAttribute('data-pid', g.product_id);
                tr.querySelector('.oc-ot-qty-in').value = num(g.order_qty, 2);
                tr.querySelector('.oc-ot-note-in').value = g.note || '';
            });
        } else {
            docOrderType = (document.querySelector('input[name="oc-order-type"]:checked') || {}).value || 'process';
            addOrdRow();
        }
        openModal('oc-modal-order');
    });
    $('oc-btn-add-ord-row').addEventListener('click', function () { addOrdRow(); });

    $('oc-btn-create-order').addEventListener('click', function () {
        var type = (document.querySelector('input[name="oc-order-type"]:checked') || {}).value || 'process';
        var groups = [];
        Array.prototype.forEach.call(ordTbody.querySelectorAll('.oc-ord-row'), function (tr) {
            var pid = parseInt(tr.querySelector('.oc-ot-prod-input').getAttribute('data-pid'), 10) || 0;
            var oqty = parseNum(tr.querySelector('.oc-ot-qty-in').value);
            if (!pid || oqty <= 0) return;
            var r = recipes.filter(function (x) { return x.product_id === pid; })[0];
            if (!r) return;
            var yieldQty = r.yield_qty > 0 ? r.yield_qty : 1;
            var items = (r.materials || []).map(function (m) {
                return {
                    material_id: m.material_id,
                    name: m.name,
                    unit: m.unit,
                    qty: (parseFloat(m.qty) || 0) / yieldQty * oqty   // định mức/1 thành phẩm × SL đặt
                };
            });
            var typedNote = tr.querySelector('.oc-ot-note-in').value.trim();
            if (typedNote !== '') persistRecipeNote(pid, typedNote);   // lưu để tái dùng
            groups.push({
                product_id: pid,
                product_name: r.product_name,
                order_qty: oqty,
                note: typedNote || (r.note || ''),
                items: items
            });
        });
        if (!groups.length) { alert('Chưa chọn sản phẩm hoặc số lượng hợp lệ.'); return; }
        docOrderType = type;
        docGroups = groups;
        renderDoc();
        closeModal('oc-modal-order');
    });

    /* =====================================================================
     *  KHỐI 2 — Render phiếu theo nhóm
     * ===================================================================== */
    var docTbody = $('oc-doc-tbody');
    function renderDoc() {
        docTbody.innerHTML = '';
        if (!docGroups.length) {
            docTbody.innerHTML = '<div class="oc-doc-empty">Bấm "Đặt hàng" để tạo đơn từ các sản phẩm đã thiết lập.</div>';
            return;
        }
        docGroups.forEach(function (g, gi) {
            var group = document.createElement('div');
            group.className = 'oc-doc-group';

            var hr = document.createElement('div');
            hr.className = 'oc-doc-grouphead';
            hr.innerHTML = 'Nhóm ' + (gi + 1) + ' — ' + esc(g.product_name)
                + '<span class="oc-gh-badge">(đặt ' + num(g.order_qty, 0) + ' thành phẩm)</span>';
            group.appendChild(hr);

            g.items.forEach(function (it) {
                var row = document.createElement('div');
                row.className = 'oc-doc-row';
                row.innerHTML = '<div class="oc-dc-name">' + esc(it.name) + '</div>'
                    + '<div class="oc-dc-unit">' + esc(it.unit || '') + '</div>'
                    + '<div class="oc-dc-qty">' + num(it.qty, 3) + '</div>';
                group.appendChild(row);
            });

            var nr = document.createElement('div');
            nr.className = 'oc-doc-groupnote';
            nr.innerHTML = '<input type="text" class="oc-gn-input" placeholder="Ghi chú nhóm..." value="' + esc(g.note || '') + '">';
            var inp = nr.querySelector('.oc-gn-input');
            inp.addEventListener('input', function () { g.note = inp.value; });
            // Rời ô -> lưu lại ghi chú vào thiết lập SP (nhập 1 lần, dùng nhiều lần).
            inp.addEventListener('change', function () { persistRecipeNote(g.product_id, inp.value.trim()); });
            group.appendChild(nr);

            docTbody.appendChild(group);
        });
    }

    function collectOrder() {
        return {
            supplier_id: supplier ? supplier.id : 0,
            supplier_name: supplier ? supplier.supplier_name : (docSupplier.textContent || ''),
            order_type: docOrderType,
            note: '',
            groups: docGroups
        };
    }

    /* ---------- Lưu đơn ---------- */
    $('oc-btn-save-order').addEventListener('click', function () {
        var btn = this;
        if (!supplier) { shakeBtn(btn); alert('Chọn nhà cung cấp (Kính gửi) trước.'); return; }
        if (!docGroups.length) { shakeBtn(btn); return; }
        btn.disabled = true;
        postJSON('save_order', collectOrder()).then(function (res) {
            btn.disabled = false;
            if (res && res.success) {
                CFG.orders = res.orders || [];
                flashBtn(btn); flyToSaved(btn);
            } else { shakeBtn(btn); alert((res && res.message) || 'Lưu đơn thất bại.'); }
        });
    });

    $('oc-btn-clear-order').addEventListener('click', function () {
        if (!docGroups.length) return;
        if (!confirm('Xóa toàn bộ nội dung đơn hiện tại?')) return;
        docGroups = [];
        renderDoc();
    });

    /* ---------- Hiệu ứng ---------- */
    function shakeBtn(el) { el.classList.remove('oc-btn-shake'); void el.offsetWidth; el.classList.add('oc-btn-shake'); setTimeout(function () { el.classList.remove('oc-btn-shake'); }, 450); }
    function flashBtn(el) { el.classList.remove('oc-btn-flash'); void el.offsetWidth; el.classList.add('oc-btn-flash'); setTimeout(function () { el.classList.remove('oc-btn-flash'); }, 650); }
    function flyToSaved(fromEl) {
        var toEl = $('oc-btn-saved-orders');
        if (!fromEl || !toEl) return;
        var a = fromEl.getBoundingClientRect(), b = toEl.getBoundingClientRect();
        var fly = document.createElement('div');
        fly.className = 'oc-fly-icon';
        fly.innerHTML = '<i class="fa-solid fa-file-invoice"></i>';
        fly.style.left = (a.left + a.width / 2) + 'px';
        fly.style.top = (a.top + a.height / 2) + 'px';
        document.body.appendChild(fly);
        fly.getBoundingClientRect();
        var dx = (b.left + b.width / 2) - (a.left + a.width / 2);
        var dy = (b.top + b.height / 2) - (a.top + a.height / 2);
        fly.style.transform = 'translate(' + dx + 'px,' + dy + 'px) scale(.3)';
        fly.style.opacity = '0.15';
        setTimeout(function () {
            fly.remove();
            toEl.classList.add('oc-pulse');
            setTimeout(function () { toEl.classList.remove('oc-pulse'); }, 600);
        }, 720);
    }

    /* =====================================================================
     *  KHỐI 2 — Chụp & chia sẻ (html2canvas -> clipboard)
     * ===================================================================== */
    $('oc-btn-share-order').addEventListener('click', function () {
        if (!supplier) { alert('Vui lòng chọn nhà cung cấp trước khi chụp & chia sẻ.'); return; }
        if (!docGroups.length) { alert('Chưa có mặt hàng để chia sẻ.'); return; }
        var sheet = $('oc-doc-sheet');
        if (typeof window.html2canvas !== 'function') { alert('Không nạp được html2canvas.'); return; }
        var btn = this, orig = btn.innerHTML;
        btn.innerHTML = 'Đang xử lý...'; btn.disabled = true;
        sheet.classList.add('is-capturing');
        var SCALE = 2;
        window.html2canvas(sheet, { scale: SCALE, backgroundColor: null, useCORS: true }).then(function (docCanvas) {
            sheet.classList.remove('is-capturing');
            var M = Math.round(20 * SCALE);
            var out = document.createElement('canvas');
            out.width = docCanvas.width + M * 2;
            out.height = docCanvas.height + M * 2;
            var ctx = out.getContext('2d');
            ctx.fillStyle = '#ffffff';
            ctx.fillRect(0, 0, out.width, out.height);
            ctx.shadowColor = 'rgba(105, 111, 121, 0.6)';
            ctx.shadowBlur = 12 * SCALE;
            ctx.shadowOffsetX = -1 * SCALE;
            ctx.shadowOffsetY = -1 * SCALE;
            ctx.drawImage(docCanvas, M, M);
            var canvas = out;
            canvas.toBlob(function (blob) {
                function restore() { btn.innerHTML = orig; btn.disabled = false; }
                if (navigator.clipboard && window.ClipboardItem) {
                    navigator.clipboard.write([new ClipboardItem({ 'image/png': blob })]).then(function () {
                        alert('Đã copy ảnh đơn đặt hàng vào clipboard.\nMở app khác (Zalo, Messenger...) và bấm Ctrl+V để dán.');
                        restore();
                    }).catch(function () { downloadCanvas(canvas); restore(); });
                } else { downloadCanvas(canvas); restore(); }
            }, 'image/png');
        }).catch(function (err) { sheet.classList.remove('is-capturing'); alert('Lỗi tạo ảnh: ' + err.message); btn.innerHTML = orig; btn.disabled = false; });
    });
    function downloadCanvas(canvas, filename) {
        var a = document.createElement('a');
        a.href = canvas.toDataURL('image/png');
        a.download = filename || 'don-dat-hang-ca-phe.png';
        a.click();
    }

    /* =====================================================================
     *  KHỐI 2 — Đơn đã lưu
     * ===================================================================== */
    $('oc-btn-saved-orders').addEventListener('click', function () {
        renderOrders(CFG.orders || []);
        openModal('oc-modal-orders');
        post('list_orders', {}).then(function (res) {
            if (res && res.success) { CFG.orders = res.data; renderOrders(res.data); }
        });
    });

    function renderOrders(list) {
        var box = $('oc-orders-list');
        if (!list.length) { box.innerHTML = '<div class="oc-recipe-empty">Chưa có đơn nào được lưu.</div>'; return; }
        var html = '';
        list.forEach(function (o) {
            var prods = (o.groups || []).map(function (g) { return esc(g.product_name) + ' ×' + num(g.order_qty, 0); }).join(', ');
            var typeTxt = o.order_type === 'material' ? 'Đặt nguyên liệu' : 'Đặt gia công';
            html += '<div class="oc-order-card' + (o.received ? ' is-received' : '') + '" data-id="' + o.id + '">'
                + '<div class="oc-order-card-head">'
                + '<span class="oc-oc-sup">' + esc(o.supplier_name || '—') + '<span class="oc-oc-type">' + typeTxt + '</span></span>'
                + '<span class="oc-oc-badge">' + (o.received ? 'Đã nhận' : 'Chờ nhận') + '</span>'
                + '</div>'
                + '<div class="oc-oc-date">' + formatDate(o.created_at) + ' · ' + money(o.expected_import_value) + '</div>'
                + '<div class="oc-oc-items">' + prods + '</div>'
                + '<div class="oc-oc-actions">'
                + '<button class="oc-oc-reorder" data-id="' + o.id + '"><i class="fa-solid fa-rotate-left"></i> Xem lại</button>'
                + '<button class="oc-oc-recv" data-id="' + o.id + '" data-rc="' + (o.received ? 0 : 1) + '"><i class="fa-solid fa-check"></i> ' + (o.received ? 'Bỏ đã nhận' : 'Đã nhận đơn') + '</button>'
                + '<button class="oc-oc-del" data-id="' + o.id + '"><i class="fa-solid fa-trash-can"></i> Xóa</button>'
                + '</div></div>';
        });
        box.innerHTML = html;
    }

    $('oc-orders-list').addEventListener('click', function (e) {
        var btn = e.target.closest('button'); if (!btn) return;
        var id = parseInt(btn.getAttribute('data-id'), 10);
        if (btn.classList.contains('oc-oc-recv')) {
            post('set_received', { id: id, received: btn.getAttribute('data-rc') }).then(function (res) {
                if (res && res.success) { CFG.orders = res.orders; renderOrders(res.orders); }
            });
        } else if (btn.classList.contains('oc-oc-del')) {
            if (!confirm('Xóa đơn này?')) return;
            post('delete_order', { id: id }).then(function (res) {
                if (res && res.success) { CFG.orders = res.orders; renderOrders(res.orders); }
            });
        } else if (btn.classList.contains('oc-oc-reorder')) {
            post('order_detail', { id: id }).then(function (res) {
                if (!res || !res.success) { alert('Không tải được đơn.'); return; }
                loadOrderIntoDoc(res.data);
                closeModal('oc-modal-orders');
            });
        }
    });

    function loadOrderIntoDoc(o) {
        supplier = { id: o.supplier_id, supplier_name: o.supplier_name };
        supInput.value = o.supplier_name || '';
        docSupplier.textContent = o.supplier_name || '';
        btnSupInfo.disabled = false;
        ['oc-btn-pkg-opening', 'oc-btn-pkg-send', 'oc-btn-pkg-loss'].forEach(function (id) { $(id).disabled = false; });
        docOrderType = o.order_type || 'process';
        docGroups = (o.groups || []).map(function (g) {
            return {
                product_id: g.product_id, product_name: g.product_name,
                order_qty: parseFloat(g.order_qty) || 0, note: g.note || '',
                items: (g.items || []).map(function (it) {
                    return { material_id: it.material_id, name: it.name, unit: it.unit, qty: parseFloat(it.qty) || 0 };
                })
            };
        });
        renderDoc();
        loadPackagingBook();
    }

    /* =====================================================================
     *  Hover-to-edit (tiêu đề công ty) -> app_settings
     * ===================================================================== */
    function startEdit(el) {
        if (el.classList.contains('is-editing')) return;
        var key = el.getAttribute('data-key');
        var multiline = el.getAttribute('data-multiline') === '1';
        var textEl = el.querySelector('.oc-etext');
        var btn = el.querySelector('.oc-edit-btn');
        var cur = textEl.textContent;
        var inp = document.createElement(multiline ? 'textarea' : 'input');
        if (!multiline) inp.type = 'text';
        inp.className = 'oc-edit-input' + (multiline ? ' oc-edit-area' : '');
        inp.value = cur;
        if (multiline) inp.rows = Math.max(2, cur.split('\n').length);
        el.classList.add('is-editing');
        textEl.style.display = 'none';
        if (btn) btn.style.display = 'none';
        el.insertBefore(inp, btn || null);
        inp.focus();
        if (inp.select) inp.select();
        var done = false;
        function finish(commit) {
            if (done) return; done = true;
            var val = multiline ? inp.value.replace(/\s+$/, '') : inp.value.trim();
            if (commit && val !== cur) { textEl.textContent = val; post('save_setting', { key: key, value: val }); }
            inp.remove(); textEl.style.display = ''; if (btn) btn.style.display = ''; el.classList.remove('is-editing');
        }
        inp.addEventListener('keydown', function (ev) {
            if (ev.key === 'Enter' && multiline && ev.altKey) {
                ev.preventDefault();
                var s = inp.selectionStart, e = inp.selectionEnd;
                inp.value = inp.value.slice(0, s) + '\n' + inp.value.slice(e);
                inp.selectionStart = inp.selectionEnd = s + 1;
                inp.rows = Math.max(inp.rows, inp.value.split('\n').length);
            } else if (ev.key === 'Enter' && !ev.shiftKey) { ev.preventDefault(); inp.blur(); }
            else if (ev.key === 'Escape') { done = true; inp.remove(); textEl.style.display = ''; if (btn) btn.style.display = ''; el.classList.remove('is-editing'); }
        });
        inp.addEventListener('blur', function () { finish(true); });
    }
    Array.prototype.forEach.call(document.querySelectorAll('.oc-editable'), function (el) {
        var btn = el.querySelector('.oc-edit-btn');
        if (btn) btn.addEventListener('click', function (e) { e.preventDefault(); e.stopPropagation(); startEdit(el); });
    });

    /* =====================================================================
     *  Chữ ký động: render + cài đặt + kéo-thả
     * ===================================================================== */
    var signRoles = Array.isArray(CFG.signRoles) ? CFG.signRoles.slice() : ['Giám đốc', 'Kế toán trưởng', 'Thủ kho', 'Người lên đơn'];
    var signs = Array.isArray(CFG.signs) ? CFG.signs.map(function (s) { return { role: String(s.role || ''), name: String(s.name || '') }; }) : [];
    var signsBox = $('oc-doc-signs');

    function saveSigns() { post('save_setting', { key: 'order_coffee.signs', value: JSON.stringify(signs) }); }
    function saveRoles() { post('save_setting', { key: 'order_coffee.sign_roles', value: JSON.stringify(signRoles) }); }

    var dragFrom = null;
    function bindSignDrag(div) {
        div.addEventListener('dragstart', function () { dragFrom = parseInt(div.getAttribute('data-idx'), 10); div.classList.add('dragging'); });
        div.addEventListener('dragend', function () {
            div.classList.remove('dragging');
            Array.prototype.forEach.call(signsBox.querySelectorAll('.oc-sign'), function (s) { s.classList.remove('drag-over'); });
        });
        div.addEventListener('dragover', function (e) { e.preventDefault(); div.classList.add('drag-over'); });
        div.addEventListener('dragleave', function () { div.classList.remove('drag-over'); });
        div.addEventListener('drop', function (e) {
            e.preventDefault();
            var to = parseInt(div.getAttribute('data-idx'), 10);
            if (dragFrom === null || dragFrom === to) return;
            var tmp = signs[dragFrom]; signs[dragFrom] = signs[to]; signs[to] = tmp;
            dragFrom = null; renderSigns(); saveSigns();
        });
    }

    function startSignNameEdit(ed, idx) {
        if (ed.classList.contains('is-editing')) return;
        var textEl = ed.querySelector('.oc-etext');
        var btn = ed.querySelector('.oc-edit-btn');
        var cur = textEl.textContent;
        var inp = document.createElement('input');
        inp.type = 'text'; inp.className = 'oc-edit-input'; inp.value = cur;
        ed.classList.add('is-editing'); textEl.style.display = 'none'; if (btn) btn.style.display = 'none';
        ed.insertBefore(inp, btn || null); inp.focus(); inp.select();
        var done = false;
        function finish(commit) {
            if (done) return; done = true;
            var val = inp.value.trim();
            if (commit) { signs[idx].name = val; textEl.textContent = val; saveSigns(); }
            inp.remove(); textEl.style.display = ''; if (btn) btn.style.display = ''; ed.classList.remove('is-editing');
        }
        inp.addEventListener('keydown', function (ev) {
            if (ev.key === 'Enter') { ev.preventDefault(); inp.blur(); }
            else if (ev.key === 'Escape') { done = true; inp.remove(); textEl.style.display = ''; if (btn) btn.style.display = ''; ed.classList.remove('is-editing'); }
        });
        inp.addEventListener('blur', function () { finish(true); });
    }

    function renderSigns() {
        if (!signsBox) return;
        signsBox.innerHTML = '';
        signs.forEach(function (sg, idx) {
            var div = document.createElement('div');
            div.className = 'oc-sign';
            div.setAttribute('draggable', 'true');
            div.setAttribute('data-idx', idx);
            div.innerHTML = '<div class="oc-sign-role">' + esc(sg.role) + '</div>'
                + '<div class="oc-sign-note">(Ký, họ tên)</div>'
                + '<div class="oc-editable oc-sign-name"><span class="oc-etext" style="padding-left:24px;">' + esc(sg.name) + '</span>'
                + '<button type="button" class="oc-edit-btn" title="Sửa"><i class="fa-solid fa-pen"></i></button></div>';
            var ed = div.querySelector('.oc-sign-name');
            var btn = ed.querySelector('.oc-edit-btn');
            btn.addEventListener('click', function (e) { e.preventDefault(); e.stopPropagation(); startSignNameEdit(ed, idx); });
            bindSignDrag(div);
            signsBox.appendChild(div);
        });
    }

    function renderRoleList() {
        var ul = $('oc-sign-rolelist'); if (!ul) return;
        ul.innerHTML = '';
        signRoles.forEach(function (role, i) {
            var checked = signs.some(function (s) { return s.role === role; });
            var li = document.createElement('li');
            li.className = 'oc-sign-roleitem';
            li.innerHTML = '<input type="checkbox" ' + (checked ? 'checked' : '') + '>'
                + '<span class="oc-sign-rolename">' + esc(role) + '</span>'
                + '<div class="oc-sign-roleact">'
                + '<button type="button" class="oc-sign-rolebtn" data-act="edit" title="Đổi tên"><i class="fa-solid fa-pen"></i></button>'
                + '<button type="button" class="oc-sign-rolebtn danger" data-act="del" title="Xóa"><i class="fa-solid fa-trash"></i></button>'
                + '</div>';
            var cb = li.querySelector('input');
            cb.addEventListener('change', function () {
                if (cb.checked) { if (!signs.some(function (s) { return s.role === role; })) signs.push({ role: role, name: '' }); }
                else { signs = signs.filter(function (s) { return s.role !== role; }); }
                renderSigns(); saveSigns();
            });
            var nameEl = li.querySelector('.oc-sign-rolename');
            li.querySelector('[data-act="edit"]').addEventListener('click', function () {
                nameEl.setAttribute('contenteditable', 'true'); nameEl.focus();
                var r = document.createRange(); r.selectNodeContents(nameEl);
                var sel = window.getSelection(); sel.removeAllRanges(); sel.addRange(r);
            });
            nameEl.addEventListener('blur', function () {
                if (nameEl.getAttribute('contenteditable') !== 'true') return;
                nameEl.removeAttribute('contenteditable');
                var nv = nameEl.textContent.trim();
                if (nv && nv !== role) {
                    signRoles[i] = nv;
                    signs.forEach(function (s) { if (s.role === role) s.role = nv; });
                    saveRoles(); saveSigns(); renderRoleList(); renderSigns();
                } else { nameEl.textContent = role; }
            });
            nameEl.addEventListener('keydown', function (e) { if (e.key === 'Enter') { e.preventDefault(); nameEl.blur(); } });
            li.querySelector('[data-act="del"]').addEventListener('click', function () {
                if (!confirm('Xóa chức danh "' + role + '"?')) return;
                signRoles = signRoles.filter(function (r) { return r !== role; });
                signs = signs.filter(function (s) { return s.role !== role; });
                saveRoles(); saveSigns(); renderRoleList(); renderSigns();
            });
            ul.appendChild(li);
        });
    }

    function addRole() {
        var inp = $('oc-sign-newrole'); var v = inp.value.trim();
        if (!v) return;
        if (signRoles.indexOf(v) === -1) { signRoles.push(v); saveRoles(); }
        inp.value = ''; renderRoleList();
    }

    var signSettingBtn = $('oc-btn-sign-setting');
    if (signSettingBtn) signSettingBtn.addEventListener('click', function () { renderRoleList(); openModal('oc-modal-sign'); });
    var signAddBtn = $('oc-sign-addbtn');
    if (signAddBtn) signAddBtn.addEventListener('click', addRole);
    var signNewInp = $('oc-sign-newrole');
    if (signNewInp) signNewInp.addEventListener('keydown', function (e) { if (e.key === 'Enter') { e.preventDefault(); addRole(); } });

    /* ---------- Init ---------- */
    addMatRow();
    renderRecipeList();
    renderDoc();
    renderSigns();
})();
