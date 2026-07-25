/* =====================================================================
 *  QL bao bì khách hàng (customer_packaging)
 *  KHỐI 1: Thiết lập khách hàng + bao bì + sản phẩm
 *  KHỐI 2: Sổ bao bì theo khách hàng (nhận / xuất dùng / hao hụt)
 * ===================================================================== */
(function () {
    'use strict';

    var CFG = window.CP_CONFIG || { baseUrl: '?mod=customer_packaging&controllers=customer_packaging&action=', setups: [] };

    var currentCustomer = null; // tên khách hàng đang xem sổ (string)
    var lastBook = { types: [], entries: [], total: 0 };
    var ledgerPage = 1;
    var LEDGER_PAGE_SIZE_KEY = 'cp_ledger_page_size';
    var ledgerPageSize = (function () {
        var v = 0;
        try { v = parseInt(localStorage.getItem(LEDGER_PAGE_SIZE_KEY), 10); } catch (e) { /* ignore */ }
        return isFinite(v) && v >= 0 ? v : 20;
    })();

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
    function formatDate(s) {
        if (!s) return '';
        var m = String(s).match(/(\d{4})-(\d{2})-(\d{2})/);
        if (!m) return s;
        return m[3] + '/' + m[2] + '/' + m[1];
    }

    /* ---------- Modal helpers ---------- */
    function openModal(id) { var m = $(id); if (m) m.style.display = 'flex'; }
    function closeModal(id) { var m = $(id); if (m) m.style.display = 'none'; }
    // Chỉ tự đóng modal khi CẢ mousedown lẫn click đều nhắm thẳng vào lớp nền (tránh bug
    // item dropdown tràn khỏi modal box bị tính nhầm target -> xem order_coffee.js).
    var maskMousedownTarget = null;
    document.addEventListener('mousedown', function (e) { maskMousedownTarget = e.target; });
    document.addEventListener('click', function (e) {
        var c = e.target.getAttribute && e.target.getAttribute('data-close');
        if (c) closeModal(c);
        if (e.target.classList && e.target.classList.contains('cp-modal-mask') && maskMousedownTarget === e.target) {
            e.target.style.display = 'none';
        }
    });
    document.addEventListener('click', function (e) {
        if (!e.target.closest('.cp-search-wrap')) {
            Array.prototype.forEach.call(document.querySelectorAll('.cp-search-dropdown.open'),
                function (d) { d.classList.remove('open'); });
        }
    });

    /* =====================================================================
     *  Autocomplete dùng chung (↑↓ chọn dòng, Enter chọn, Tab chọn NHƯNG vẫn
     *  cho chuyển focus tiếp, Escape đóng) — theo quy ước chung mới nhất.
     * ===================================================================== */
    function attachSearch(input, drop, action, extraParamsFn, onSelect) {
        var list = [], active = -1;
        function render() {
            if (!list.length) {
                drop.innerHTML = '<li class="cp-sd-empty">Không tìm thấy.</li>';
                drop.classList.add('open'); return;
            }
            var html = '';
            list.forEach(function (s, i) {
                var label = s.name || '';
                var sub = s.unit ? ('ĐV: ' + s.unit) : '';
                html += '<li data-i="' + i + '"' + (i === active ? ' class="active"' : '') + '>'
                    + '<span>' + esc(label) + '</span>'
                    + (sub ? '<small>' + esc(sub) + '</small>' : '') + '</li>';
            });
            drop.innerHTML = html; drop.classList.add('open');
        }
        function close() { drop.classList.remove('open'); active = -1; }
        function highlight(idx) { active = idx; render(); }
        var doSearch = debounce(function () {
            var extra = (extraParamsFn && extraParamsFn()) || {};
            var params = Object.assign({ keyword: input.value.trim() }, extra);
            post(action, params).then(function (res) {
                list = (res && res.data) || [];
                active = list.length ? 0 : -1;
                render();
            });
        }, 180);
        input.addEventListener('input', function () { input.removeAttribute('data-selected-id'); doSearch(); });
        input.addEventListener('focus', function () { doSearch(); });
        input.addEventListener('keydown', function (e) {
            if (!drop.classList.contains('open')) { if (e.key === 'ArrowDown') doSearch(); return; }
            if (e.key === 'ArrowDown') { e.preventDefault(); highlight(Math.min(active + 1, list.length - 1)); }
            else if (e.key === 'ArrowUp') { e.preventDefault(); highlight(Math.max(active - 1, 0)); }
            else if (e.key === 'Enter') {
                if (active >= 0 && list[active]) { e.preventDefault(); onSelect(list[active]); close(); }
            } else if (e.key === 'Tab') {
                // Không preventDefault: chọn dòng đang tô sáng RỒI vẫn cho Tab chuyển focus tiếp.
                if (active >= 0 && list[active]) { onSelect(list[active]); close(); }
            } else if (e.key === 'Escape') { close(); }
        });
        drop.addEventListener('mousedown', function (e) {
            var li = e.target.closest('li[data-i]'); if (!li) return;
            e.preventDefault();
            var i = parseInt(li.getAttribute('data-i'), 10);
            if (list[i]) { onSelect(list[i]); setTimeout(close, 0); }
        });
        return { close: close };
    }

    /* =====================================================================
     *  KHỐI 1: Thiết lập khách hàng + bao bì + sản phẩm
     * ===================================================================== */
    var setupCustomerInput = $('cp-setup-customer'), setupCustomerDrop = $('cp-setup-customer-dropdown');
    var setupPackagingInput = $('cp-setup-packaging'), setupPackagingDrop = $('cp-setup-packaging-dropdown');
    var setupProductInput = $('cp-setup-product'), setupProductDrop = $('cp-setup-product-dropdown');
    var setupChosenProduct = null;

    /**
     * Autocomplete gợi ý TEXT THUẦN (khách hàng/bao bì tự đặt) — action trả về
     * mảng string thô (customer_suggest/packaging_suggest), khác attachSearch()
     * vốn nhận mảng object {id,name,...}. Chọn = điền thẳng string vào input.
     */
    function attachSuggestWrapped(input, drop, action, extraParamsFn) {
        var list = [], active = -1;
        function render() {
            if (!list.length) {
                drop.innerHTML = '<li class="cp-sd-empty">Không có gợi ý (tự đặt tên mới).</li>';
                drop.classList.add('open'); return;
            }
            var html = '';
            list.forEach(function (s, i) {
                html += '<li data-i="' + i + '"' + (i === active ? ' class="active"' : '') + '><span>' + esc(s) + '</span></li>';
            });
            drop.innerHTML = html; drop.classList.add('open');
        }
        function close() { drop.classList.remove('open'); active = -1; }
        var doSearch = debounce(function () {
            var extra = (extraParamsFn && extraParamsFn()) || {};
            var params = Object.assign({ keyword: input.value.trim() }, extra);
            post(action, params).then(function (res) {
                list = (res && res.data) || [];
                active = -1;
                render();
            });
        }, 180);
        input.addEventListener('input', doSearch);
        input.addEventListener('focus', doSearch);
        input.addEventListener('keydown', function (e) {
            if (!drop.classList.contains('open')) { if (e.key === 'ArrowDown') doSearch(); return; }
            if (e.key === 'ArrowDown') { e.preventDefault(); active = Math.min(active + 1, list.length - 1); render(); }
            else if (e.key === 'ArrowUp') { e.preventDefault(); active = Math.max(active - 1, 0); render(); }
            else if (e.key === 'Enter') { if (active >= 0) { e.preventDefault(); input.value = list[active]; close(); } }
            else if (e.key === 'Tab') { if (active >= 0) { input.value = list[active]; close(); } }
            else if (e.key === 'Escape') { close(); }
        });
        drop.addEventListener('mousedown', function (e) {
            var li = e.target.closest('li[data-i]'); if (!li) return;
            e.preventDefault();
            var i = parseInt(li.getAttribute('data-i'), 10);
            if (list[i] !== undefined) { input.value = list[i]; setTimeout(close, 0); }
        });
    }

    attachSuggestWrapped(setupCustomerInput, setupCustomerDrop, 'customer_suggest', function () { return {}; });
    attachSuggestWrapped(setupPackagingInput, setupPackagingDrop, 'packaging_suggest', function () {
        return { customer_name: setupCustomerInput.value.trim() };
    });
    attachSearch(setupProductInput, setupProductDrop, 'search_products', function () { return {}; }, function (p) {
        setupChosenProduct = { id: p.id, name: p.name };
        setupProductInput.value = p.name;
    });
    setupProductInput.addEventListener('input', function () { setupChosenProduct = null; });

    function renderSetupTable(rows) {
        var tb = $('cp-setup-tbody');
        if (!rows || !rows.length) {
            tb.innerHTML = '<tr class="cp-st-empty"><td colspan="4">Chưa có thiết lập nào.</td></tr>';
            return;
        }
        var html = '';
        rows.forEach(function (r) {
            html += '<tr>'
                + '<td>' + esc(r.customer_name) + '</td>'
                + '<td>' + esc(r.packaging_name) + '</td>'
                + '<td>' + esc(r.product_name || ('#' + r.product_id)) + '</td>'
                + '<td class="cp-st-act"><button type="button" class="cp-st-del" data-id="' + r.setup_id + '" title="Xóa">&times;</button></td>'
                + '</tr>';
        });
        tb.innerHTML = html;
    }
    renderSetupTable(CFG.setups);

    $('cp-setup-tbody').addEventListener('click', function (e) {
        var btn = e.target.closest('.cp-st-del'); if (!btn) return;
        if (!confirm('Xóa thiết lập này?')) return;
        post('setup_delete', { id: btn.getAttribute('data-id') }).then(function (res) {
            if (res && res.success) renderSetupTable(res.setups || []);
        });
    });

    $('cp-btn-save-setup').addEventListener('click', function () {
        var cn = setupCustomerInput.value.trim();
        var pn = setupPackagingInput.value.trim();
        if (!cn) { alert('Nhập tên khách hàng.'); return; }
        if (!pn) { alert('Nhập tên bao bì.'); return; }
        if (!setupChosenProduct) { alert('Chọn sản phẩm dùng bao bì này.'); return; }
        var btn = this; btn.disabled = true;
        post('setup_add', {
            customer_name: cn,
            packaging_name: pn,
            product_id: setupChosenProduct.id,
            product_name: setupChosenProduct.name
        }).then(function (res) {
            btn.disabled = false;
            if (res && res.success) {
                renderSetupTable(res.setups || []);
                setupProductInput.value = '';
                setupChosenProduct = null;
            } else {
                alert((res && res.message) || 'Lưu thiết lập thất bại.');
            }
        });
    });

    // FAQ thu/mở.
    var setupFaq = $('cp-setup-faq');
    $('cp-setup-faq-head').addEventListener('click', function () { setupFaq.classList.toggle('is-collapsed'); });

    /* =====================================================================
     *  KHỐI 2: Sổ bao bì theo khách hàng
     * ===================================================================== */
    var ledgerCustomerInput = $('cp-ledger-customer'), ledgerCustomerDrop = $('cp-ledger-customer-dropdown');

    function setCurrentCustomer(name) {
        var cn = (name || '').trim();
        if (!cn) return;
        currentCustomer = cn;
        ledgerCustomerInput.value = cn;
        ['cp-btn-receive', 'cp-btn-usage', 'cp-btn-loss', 'cp-btn-export'].forEach(function (id) { $(id).disabled = false; });
        loadLedgerBook();
    }
    attachSuggestWrapped(ledgerCustomerInput, ledgerCustomerDrop, 'customer_suggest', function () { return {}; });
    ledgerCustomerInput.addEventListener('keydown', function (e) {
        if (e.key === 'Enter' && !ledgerCustomerDrop.classList.contains('open')) {
            setCurrentCustomer(ledgerCustomerInput.value);
        }
    });
    ledgerCustomerDrop.addEventListener('mousedown', function (e) {
        var li = e.target.closest('li[data-i]'); if (!li) return;
        setTimeout(function () { setCurrentCustomer(ledgerCustomerInput.value); }, 0);
    });

    function loadLedgerBook() {
        if (!currentCustomer) return;
        ledgerPage = 1;
        post('ledger_book', { customer_name: currentCustomer }).then(function (res) {
            renderLedgerBook((res && res.data) || { types: [], entries: [], total: 0 });
        });
    }

    function pkgHeadRowHtml(t) {
        return '<tr class="cp-lg-pkg-head"><td colspan="4">'
            + esc(t.packaging_name || ('Loại #' + t.type_id))
            + ' — Số dư: <span class="' + (t.balance < 0 ? 'cp-lg-out' : 'cp-lg-in') + '">' + num(t.balance, 0) + '</span>'
            + '</td><td class="cp-lg-act"><button type="button" class="cp-lg-confirm-btn" data-pkgname="' + esc(t.packaging_name) + '">Chốt tồn</button></td></tr>';
    }

    function renderLedgerBook(book) {
        lastBook = book || { types: [], entries: [], total: 0 };
        var tb = $('cp-ledger-tbody');
        var pager = $('cp-ledger-pager');
        var types = lastBook.types || [], entries = lastBook.entries || [];
        if (!types.length) {
            tb.innerHTML = '<tr class="cp-lg-empty"><td colspan="5">Chưa có nghiệp vụ bao bì nào với khách hàng này.</td></tr>';
            $('cp-ledger-total').style.display = 'none';
            if (pager) pager.style.display = 'none';
            return;
        }
        var rows = [];
        types.forEach(function (t) {
            rows.push({ kind: 'head', t: t });
            entries.filter(function (e) { return e.type_id === t.type_id; }).forEach(function (e) {
                rows.push({ kind: 'entry', e: e, t: t });
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
            $('cp-ledger-page-info').textContent = ledgerPage + '/' + totalPages;
            $('cp-ledger-page-prev').disabled = ledgerPage <= 1;
            $('cp-ledger-page-next').disabled = ledgerPage >= totalPages;
        }
        var html = '';
        if (pageRows.length && pageRows[0].kind === 'entry') html += pkgHeadRowHtml(pageRows[0].t);
        pageRows.forEach(function (row) {
            if (row.kind === 'head') { html += pkgHeadRowHtml(row.t); return; }
            var e = row.e;
            var signCls = e.qty < 0 ? 'cp-lg-out' : 'cp-lg-in';
            var q = (e.qty < 0 ? '' : '+') + num(e.qty, 0);
            html += '<tr>'
                + '<td class="cp-lg-date">' + esc(formatDate(e.entry_date || e.created_at)) + '</td>'
                + '<td class="cp-lg-name">' + esc(e.product_name || '') + '</td>'
                + '<td class="cp-lg-content">' + esc(e.content || '') + '</td>'
                + '<td class="cp-lg-qty ' + signCls + '">' + q + '</td>'
                + '<td></td>'
                + '</tr>';
        });
        tb.innerHTML = html;
        var tot = $('cp-ledger-total');
        tot.style.display = 'block';
        tot.innerHTML = 'Tổng số lượng bao bì còn lại: ' + num(lastBook.total, 0) + ' cái';
    }

    $('cp-ledger-tbody').addEventListener('click', function (e) {
        var btn = e.target.closest('.cp-lg-confirm-btn'); if (!btn) return;
        if (!currentCustomer) return;
        var pn = btn.getAttribute('data-pkgname');
        if (!confirm('Chốt tồn "' + pn + '" theo số dư hiện tại?')) return;
        post('confirm', { customer_name: currentCustomer, packaging_name: pn }).then(function (res) {
            if (res && res.success) { ledgerPage = 1; renderLedgerBook(res.data || lastBook); }
            else alert((res && res.message) || 'Chốt tồn thất bại.');
        });
    });

    var ledgerPageSizeSel = $('cp-ledger-page-size');
    if (ledgerPageSizeSel) {
        ledgerPageSizeSel.value = String(ledgerPageSize);
        ledgerPageSizeSel.addEventListener('change', function () {
            ledgerPageSize = parseInt(ledgerPageSizeSel.value, 10) || 0;
            ledgerPage = 1;
            try { localStorage.setItem(LEDGER_PAGE_SIZE_KEY, String(ledgerPageSize)); } catch (e) { /* ignore */ }
            renderLedgerBook(lastBook);
        });
    }
    $('cp-ledger-page-prev').addEventListener('click', function () { ledgerPage--; renderLedgerBook(lastBook); });
    $('cp-ledger-page-next').addEventListener('click', function () { ledgerPage++; renderLedgerBook(lastBook); });

    /* =====================================================================
     *  Modal: Nghiệp vụ bao bì (nhận / xuất dùng / hao hụt)
     * ===================================================================== */
    var emPackagingInput = $('cp-em-packaging'), emPackagingDrop = $('cp-em-packaging-dropdown');
    var emProductInput = $('cp-em-product'), emProductDrop = $('cp-em-product-dropdown');
    var emChosenProduct = null;
    var emType = 'receive';

    attachSuggestWrapped(emPackagingInput, emPackagingDrop, 'packaging_suggest', function () {
        return { customer_name: currentCustomer || '' };
    });
    attachSearch(emProductInput, emProductDrop, 'search_products', function () { return {}; }, function (p) {
        emChosenProduct = { id: p.id, name: p.name };
        emProductInput.value = p.name;
    });
    emProductInput.addEventListener('input', function () { emChosenProduct = null; });

    var EM_META = {
        receive: { title: 'Nhận bì từ khách', hint: 'Khách hàng chuyển bao bì qua cho nhà máy — cộng vào tồn.' },
        usage:   { title: 'Xuất dùng cho sản phẩm', hint: 'Dùng bao bì để đóng gói 1 sản phẩm cụ thể — trừ khỏi tồn.' },
        loss:    { title: 'Hao hụt bao bì', hint: 'Bao bì bị hư hỏng / thất lạc — trừ khỏi tồn.' }
    };
    function openEntryModal(type) {
        if (!currentCustomer) { alert('Chọn khách hàng trước.'); return; }
        emType = type;
        emChosenProduct = null;
        $('cp-em-title').textContent = EM_META[type].title;
        $('cp-em-hint').textContent = EM_META[type].hint;
        emPackagingInput.value = ''; emProductInput.value = ''; $('cp-em-qty').value = ''; $('cp-em-reason').value = '';
        $('cp-em-date').value = new Date().toISOString().slice(0, 10);
        $('cp-em-product-field').style.display = type === 'usage' ? '' : 'none';
        $('cp-em-reason-field').style.display = type === 'loss' ? '' : 'none';
        openModal('cp-modal-entry');
    }
    $('cp-btn-receive').addEventListener('click', function () { openEntryModal('receive'); });
    $('cp-btn-usage').addEventListener('click', function () { openEntryModal('usage'); });
    $('cp-btn-loss').addEventListener('click', function () { openEntryModal('loss'); });

    $('cp-em-apply').addEventListener('click', function () {
        if (!currentCustomer) return;
        var pn = emPackagingInput.value.trim();
        if (!pn) { alert('Nhập/chọn tên bao bì.'); return; }
        var qty = parseNum($('cp-em-qty').value);
        if (qty <= 0) { alert('Số lượng phải lớn hơn 0.'); return; }
        if (emType === 'usage' && !emChosenProduct) { alert('Chọn sản phẩm dùng bao bì.'); return; }
        var payload = {
            customer_name: currentCustomer,
            packaging_name: pn,
            entry_type: emType,
            qty: qty,
            entry_date: $('cp-em-date').value
        };
        if (emType === 'usage') { payload.product_id = emChosenProduct.id; payload.product_name = emChosenProduct.name; }
        if (emType === 'loss') payload.reason = $('cp-em-reason').value.trim();

        var btn = this; btn.disabled = true;
        post('entry_add', payload).then(function (res) {
            btn.disabled = false;
            if (res && res.success) {
                closeModal('cp-modal-entry');
                ledgerPage = 1;
                renderLedgerBook(res.data || lastBook);
            } else {
                alert((res && res.message) || 'Ghi sổ thất bại.');
            }
        });
    });
})();
