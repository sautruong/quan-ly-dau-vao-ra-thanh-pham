(function () {
    'use strict';

    const CFG = window.INVENTORY_CONFIG || { baseUrl: '?mod=inventory_management&controllers=inventory_management&action=' };
    const INITIAL = window.INVENTORY_DATA || { planDate: '', history: [], typeImport: 'sales_return_receipt' };

    const REASONS = [
        'Rách bao bì',
        'Cận date',
        'Hết date',
        'Lỗi kĩ thuật',
        'Chất lượng không đạt',
        'Lý do khác',
    ];
    const HANDLE_METHODS = [
        'Nhập kho trực tiếp',
        'Thay bao bì',
        'Phối chế lô sản xuất mới',
        'Tiêu hủy',
    ];
    const PENDING = 'Chờ xử lý';
    const ADD_STOCK_METHODS = ['Nhập kho trực tiếp', 'Thay bao bì'];

    // mode: 'create' | 'edit' | 'handle'
    let mode = 'create';
    let editingBatch = null;     // {group_key, customer_id, items, ...}
    const addedProducts = new Set();
    let dropdownItems = [];
    let activeIdx = -1;
    let custDropdownItems = [];
    let custActiveIdx = -1;
    let selectedCustomer = null; // {id, name, short_name}

    const $cust          = document.getElementById('customer');
    const $custDropdown  = document.getElementById('customer-dropdown');
    const $custShort     = document.getElementById('custom-acronym');
    const $dateTime      = document.getElementById('record-datetime');

    const $search        = document.getElementById('search-product');
    const $dropdown      = document.getElementById('search-dropdown');
    const $list          = document.getElementById('list-product');
    const $btnRec        = document.getElementById('btn-record');
    const $btnEdit       = document.getElementById('btn-edit-confirm');
    const $btnHandle     = document.getElementById('btn-handle-confirm');
    const $histBody      = document.getElementById('history-tbody');
    const $histPager     = document.getElementById('history-pagination');
    let pageSize = 10; // số dòng/trang — đổi qua select #hf-page-size
    let historyPage = 1;
    // Bộ lọc lịch sử (client-side trên INITIAL.history đã nạp sẵn).
    const histFilter = { keyword: '', dateFrom: '', dateTo: '' };
    const $hfDateFrom   = document.getElementById('hf-date-from');
    const $hfDateTo     = document.getElementById('hf-date-to');
    const $hfPageSize   = document.getElementById('hf-page-size');
    const $hfReset      = document.getElementById('hf-reset');
    const $hfCount      = document.getElementById('hf-count');
    const $hfKeyword    = document.getElementById('hf-keyword');
    const $hfKeywordBtn = document.getElementById('hf-keyword-btn');
    const $hfKeywordPop = document.getElementById('hf-keyword-pop');
    const $banner        = document.getElementById('edit-batch-banner');
    const $bannerLabel   = document.getElementById('edit-batch-label');
    const $modeLabel     = document.getElementById('edit-mode-label');
    const $btnCancelEdit = document.getElementById('cancel-edit-batch');

    // ---------- Journal Entry (form GHI BÚT TOÁN KẾ TOÁN) ----------
    const JE_DEFAULTS = { debit: '5212', credit: '131' };
    const $jeDebit  = document.getElementById('je-debit');
    const $jeCredit = document.getElementById('je-credit');
    const $jeAmount = document.getElementById('je-amount');

    function jeFormatAmount(n) {
        const v = Math.round(Number(n) || 0);
        return v > 0 ? v.toLocaleString('en-US') : '';
    }
    function jeParseAmount(s) {
        return Number(String(s == null ? '' : s).replace(/[^\d.-]/g, '')) || 0;
    }
    function jePayload() {
        const p = {
            je_debit:  ($jeDebit  && $jeDebit.value.trim())  || '',
            je_credit: ($jeCredit && $jeCredit.value.trim()) || '',
            je_amount: jeParseAmount($jeAmount && $jeAmount.value)
        };
        if (window.JE && window.JE.collectEntries) p.je_entries = JSON.stringify(window.JE.collectEntries());
        return p;
    }
    function setupJe() {
        if (!$jeDebit || !$jeCredit || !$jeAmount) return;
        if (!$jeDebit.value)  $jeDebit.value  = JE_DEFAULTS.debit;
        if (!$jeCredit.value) $jeCredit.value = JE_DEFAULTS.credit;
        $jeAmount.addEventListener('blur', () => {
            $jeAmount.value = jeFormatAmount(jeParseAmount($jeAmount.value));
        });
        $jeAmount.addEventListener('focus', () => {
            const n = jeParseAmount($jeAmount.value);
            $jeAmount.value = n > 0 ? String(n) : '';
        });
    }

    // ---------- Helpers ----------
    function postForm(action, payload) {
        const body = new URLSearchParams();
        Object.keys(payload || {}).forEach(k => body.append(k, payload[k]));
        return fetch(CFG.baseUrl + action, {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body: body.toString()
        }).then(r => r.json());
    }

    function debounce(fn, wait) {
        let t;
        return function () {
            const ctx = this, args = arguments;
            clearTimeout(t);
            t = setTimeout(() => fn.apply(ctx, args), wait);
        };
    }

    function escapeHtml(s) {
        return String(s == null ? '' : s)
            .replace(/&/g, '&amp;').replace(/</g, '&lt;')
            .replace(/>/g, '&gt;').replace(/"/g, '&quot;').replace(/'/g, '&#39;');
    }

    function fmtQty(q) {
        const n = Number(q);
        if (!isFinite(n)) return '';
        return (Math.round(n * 100) / 100).toString();
    }

    // ---------- Datetime helpers ----------
    function pad2(n) { return String(n).padStart(2, '0'); }

    function nowLocalValue() {
        const d = new Date();
        return d.getFullYear() + '-' + pad2(d.getMonth() + 1) + '-' + pad2(d.getDate())
             + 'T' + pad2(d.getHours()) + ':' + pad2(d.getMinutes()) + ':' + pad2(d.getSeconds());
    }

    function parseLocalValue(v) {
        const m = /^(\d{4})-(\d{2})-(\d{2})T(\d{2}):(\d{2})(?::(\d{2}))?$/.exec(String(v || ''));
        if (!m) return null;
        return { y: m[1], M: m[2], d: m[3], h: m[4], min: m[5], s: m[6] || '00' };
    }

    function pickerToDateVN() {
        const p = parseLocalValue($dateTime.value);
        if (!p) return INITIAL.planDate || '';
        return p.d + '/' + p.M + '/' + p.y;
    }

    function pickerToMysql() {
        const p = parseLocalValue($dateTime.value);
        if (!p) return '';
        return p.y + '-' + p.M + '-' + p.d + ' ' + p.h + ':' + p.min + ':' + p.s;
    }

    function mysqlToLocalValue(s) {
        const m = /^(\d{4})-(\d{2})-(\d{2})[\sT](\d{2}):(\d{2}):(\d{2})$/.exec(String(s || ''));
        if (!m) return null;
        return `${m[1]}-${m[2]}-${m[3]}T${m[4]}:${m[5]}:${m[6]}`;
    }

    function buildInterpretation(shortName, dateVN) {
        const sn = (shortName && shortName.trim() !== '') ? shortName : '(KH)';
        return 'Hàng trả của ' + sn + ' ngày ' + dateVN;
    }

    // ---------- Customer search ----------
    const runCustomerSearch = debounce(function () {
        const kw = $cust.value.trim();
        if (kw === '') { hideCustomerDropdown(); return; }
        postForm('search_customer', { keyword: kw }).then(res => {
            renderCustomerDropdown(res.data || []);
        });
    }, 220);

    function renderCustomerDropdown(items) {
        custDropdownItems = items;
        custActiveIdx = -1;
        if (!items.length) {
            $custDropdown.innerHTML = '<li class="empty">Không tìm thấy khách hàng</li>';
        } else {
            $custDropdown.innerHTML = items.map((it, i) => {
                const sn = it.short_name ? ' — ' + escapeHtml(it.short_name) : '';
                return `<li data-id="${it.id}" data-idx="${i}">${escapeHtml(it.name)}${sn}</li>`;
            }).join('');
        }
        $custDropdown.classList.add('active');
    }

    function hideCustomerDropdown() {
        $custDropdown.classList.remove('active');
        $custDropdown.innerHTML = '';
        custActiveIdx = -1;
    }

    function selectCustomerItem(el) {
        const id = parseInt(el.getAttribute('data-id'), 10);
        if (!id) return;
        postForm('get_customer', { customer_id: id }).then(res => {
            if (!res.success) { alert(res.message || 'Lỗi'); return; }
            selectedCustomer = res.data;
            $cust.value = selectedCustomer.name;
            $custShort.value = selectedCustomer.short_name || '';
            // Đồng bộ interpretation hiện có
            syncInterpretations();
            hideCustomerDropdown();
        });
    }

    $cust.addEventListener('input', () => {
        // Khi user gõ lại, reset selection nếu name không khớp
        if (selectedCustomer && $cust.value.trim() !== selectedCustomer.name) {
            selectedCustomer = null;
            $custShort.value = '';
        }
        runCustomerSearch();
    });
    $cust.addEventListener('focus', () => {
        if ($cust.value.trim() !== '' && custDropdownItems.length) {
            $custDropdown.classList.add('active');
        }
    });
    $cust.addEventListener('keydown', (e) => {
        if (!$custDropdown.classList.contains('active')) return;
        const items = $custDropdown.querySelectorAll('li:not(.empty)');
        if (!items.length) return;
        if (e.key === 'ArrowDown') {
            e.preventDefault();
            custActiveIdx = (custActiveIdx + 1) % items.length;
        } else if (e.key === 'ArrowUp') {
            e.preventDefault();
            custActiveIdx = (custActiveIdx - 1 + items.length) % items.length;
        } else if (e.key === 'Enter' || e.key === 'Tab') {
            // Enter hoặc Tab đều chọn item đang active (hoặc item đầu nếu chưa di chuyển).
            const idx = custActiveIdx >= 0 ? custActiveIdx : 0;
            if (items[idx]) { e.preventDefault(); selectCustomerItem(items[idx]); }
            return;
        } else if (e.key === 'Escape') {
            hideCustomerDropdown();
            return;
        } else {
            return;
        }
        items.forEach(li => li.classList.remove('active'));
        items[custActiveIdx].classList.add('active');
        items[custActiveIdx].scrollIntoView({ block: 'nearest' });
    });

    $custDropdown.addEventListener('click', (e) => {
        const li = e.target.closest('li');
        if (!li || li.classList.contains('empty')) return;
        selectCustomerItem(li);
    });

    document.addEventListener('click', (e) => {
        if (!e.target.closest('.wp-customer')) hideCustomerDropdown();
        if (!e.target.closest('.wp-search')) hideDropdown();
    });

    // ---------- Product search ----------
    const runProductSearch = debounce(function () {
        const kw = $search.value.trim();
        if (kw === '') { hideDropdown(); return; }
        postForm('search_product', { keyword: kw }).then(res => {
            renderDropdown(res.data || []);
        });
    }, 220);

    function renderDropdown(items) {
        dropdownItems = items;
        activeIdx = -1;
        if (!items.length) {
            $dropdown.innerHTML = '<li class="empty">Không tìm thấy sản phẩm</li>';
        } else {
            $dropdown.innerHTML = items.map((it, i) =>
                `<li data-id="${it.id}" data-idx="${i}">${escapeHtml(it.product_name)}</li>`
            ).join('');
        }
        $dropdown.classList.add('active');
    }

    function hideDropdown() {
        $dropdown.classList.remove('active');
        $dropdown.innerHTML = '';
        activeIdx = -1;
    }

    function selectProductDropdownItem(el) {
        const id = parseInt(el.getAttribute('data-id'), 10);
        if (!id) return;
        if (mode === 'handle') {
            alert('Đang ở chế độ xử lý. Hãy Hủy để thêm sản phẩm mới.');
            hideDropdown();
            return;
        }
        if (addedProducts.has(id)) {
            alert('Sản phẩm đã có trong danh sách.');
            hideDropdown();
            return;
        }
        postForm('get_product', { product_id: id }).then(res => {
            if (!res.success) { alert(res.message || 'Lỗi'); return; }
            addProductItem({
                product_id:   res.data.product_id,
                product_name: res.data.product_name,
                quantity:     '',
                reason:       '',
            });
            $search.value = '';
            hideDropdown();
        });
    }

    $search.addEventListener('input', runProductSearch);
    $search.addEventListener('focus', () => {
        if ($search.value.trim() !== '' && dropdownItems.length) {
            $dropdown.classList.add('active');
        }
    });
    $search.addEventListener('keydown', (e) => {
        if (!$dropdown.classList.contains('active')) return;
        const items = $dropdown.querySelectorAll('li:not(.empty)');
        if (!items.length) return;
        if (e.key === 'ArrowDown') {
            e.preventDefault();
            activeIdx = (activeIdx + 1) % items.length;
        } else if (e.key === 'ArrowUp') {
            e.preventDefault();
            activeIdx = (activeIdx - 1 + items.length) % items.length;
        } else if (e.key === 'Enter' || e.key === 'Tab') {
            // Enter hoặc Tab đều chọn item đang active (hoặc item đầu nếu chưa di chuyển).
            const idx = activeIdx >= 0 ? activeIdx : 0;
            if (items[idx]) { e.preventDefault(); selectProductDropdownItem(items[idx]); }
            return;
        } else if (e.key === 'Escape') {
            hideDropdown();
            return;
        } else {
            return;
        }
        items.forEach(li => li.classList.remove('active'));
        items[activeIdx].classList.add('active');
        items[activeIdx].scrollIntoView({ block: 'nearest' });
    });

    $dropdown.addEventListener('click', (e) => {
        const li = e.target.closest('li');
        if (!li || li.classList.contains('empty')) return;
        selectProductDropdownItem(li);
    });

    // ---------- Render product-item ----------
    function reasonRadiosHtml(uid, selectedReason, disabled) {
        return REASONS.map((r, i) => {
            const checked = selectedReason === r ? 'checked' : '';
            const wrapCls = selectedReason === r ? 'active' : '';
            const dis = disabled ? 'disabled' : '';
            return `
                <div class="wp-radio-${i + 1} ${wrapCls}">
                    <input type="radio" name="reason-${uid}" id="reason-${uid}-${i}" value="${escapeHtml(r)}" ${checked} ${dis}>
                    <label for="reason-${uid}-${i}">${escapeHtml(r)}</label>
                </div>`;
        }).join('');
    }

    function handleRadiosHtml(uid, selectedMethod) {
        return HANDLE_METHODS.map((m, i) => {
            const checked = selectedMethod === m ? 'checked' : '';
            const wrapCls = selectedMethod === m ? 'active' : '';
            return `
                <div class="wp-handle-${i + 1} ${wrapCls}">
                    <input type="radio" name="handle-${uid}" id="handle-${uid}-${i}" value="${escapeHtml(m)}" ${checked}>
                    <label for="handle-${uid}-${i}">${escapeHtml(m)}</label>
                </div>`;
        }).join('');
    }

    let _uidCounter = 0;
    function nextUid() { return 'p' + (++_uidCounter); }

    /**
     * Xoá 1 product-item.
     *  - mode 'create': chỉ xoá khỏi DOM (chưa ghi DB).
     *  - mode 'edit' / 'handle': gọi API xoá DB + điều chỉnh tồn (nếu trước đã cộng).
     *    Nếu list trống sau khi xoá → thoát chế độ.
     */
    function removeItem(li) {
        const pid  = parseInt(li.getAttribute('data-product-id'), 10);
        const name = (li.querySelector('.name-product p') || {}).textContent || '';

        if (mode === 'create') {
            li.remove();
            addedProducts.delete(pid);
            return;
        }

        const sid = parseInt(li.getAttribute('data-sales-return-id'), 10);
        const iid = parseInt(li.getAttribute('data-import-id'), 10) || 0;
        if (!sid) {
            li.remove();
            addedProducts.delete(pid);
            return;
        }

        if (!confirm('Xóa "' + name + '" khỏi nhóm? Tồn kho sẽ được điều chỉnh tương ứng.')) return;

        postForm('delete_sales_return_item', {
            sales_return_id: sid,
            import_id:       iid,
        }).then(res => {
            if (!res || !res.success) {
                alert((res && res.message) ? res.message : 'Có lỗi xảy ra.');
                return;
            }
            li.remove();
            addedProducts.delete(pid);
            renderHistory(res.history || []);
            if ($list.children.length === 0) exitEditMode();
        });
    }

    function addProductItem(p) {
        const pid = p.product_id;
        const name = p.product_name || '';
        const qty = (p.quantity === 0 || p.quantity) ? p.quantity : '';
        const reason = p.reason || '';
        const sid = p.sales_return_id ? p.sales_return_id : '';
        const iid = p.import_id ? p.import_id : '';
        const handlingMethod = p.handling_method || '';
        const uid = nextUid();

        const li = document.createElement('li');
        li.className = 'product-item';
        li.setAttribute('data-product-id', pid);
        li.setAttribute('data-uid', uid);
        if (sid) li.setAttribute('data-sales-return-id', sid);
        if (iid) li.setAttribute('data-import-id', iid);
        if (handlingMethod) li.setAttribute('data-handling-method', handlingMethod);

        // Phần tag "Đã ..." nếu đã xử lý (chỉ hiện ở mode edit hoặc create-readonly)
        const handledTagHtml = (handlingMethod && handlingMethod !== PENDING)
            ? `<div class="handled-tag">Đã ${escapeHtml(handlingMethod.toLowerCase())}</div>`
            : '';

        let bodyHtml = '';
        if (mode === 'handle') {
            // Hiện reason readonly + nhóm radio chọn cách thức xử lý
            bodyHtml = `
                <div class="reason-readonly">Lý do trả: <strong>${escapeHtml(reason || '(không rõ)')}</strong></div>
                <div class="wp-handling">
                    ${handleRadiosHtml(uid, handlingMethod && handlingMethod !== PENDING ? handlingMethod : '')}
                </div>
            `;
        } else {
            // create | edit: nhóm reason
            bodyHtml = `
                <div class="wp-reason">
                    ${reasonRadiosHtml(uid, reason, false)}
                </div>
                ${handledTagHtml}
            `;
        }

        // Handle mode: dòng CHƯA xử lý cho sửa SL xử lý đợt này (tối đa = SL gốc) — phần
        // còn lại tự tách dòng "Chờ xử lý" để đợt sản xuất sau xử lý tiếp. Dòng đã xử lý
        // rồi thì SL vẫn readonly (chỉ đổi được cách thức cho toàn bộ dòng).
        const isPendingHandle = mode === 'handle' && (!handlingMethod || handlingMethod === PENDING);
        const qtyReadonly = mode === 'handle' && !isPendingHandle;
        const maxAttr = isPendingHandle ? ` data-max-qty="${escapeHtml(qty)}" title="SL xử lý đợt này — tối đa ${escapeHtml(qty)}, phần còn lại chờ đợt sau"` : '';

        li.innerHTML = `
            <button type="button" class="btn-remove-product" title="Xóa">×</button>
            <div class="wp-name-num-product">
                <div class="name-product">
                    <p>${escapeHtml(name)}</p>
                </div>
                <div class="num-return">
                    <input type="text" class="input-quantity" value="${escapeHtml(qty)}" placeholder="SL"${maxAttr} ${qtyReadonly ? 'readonly' : ''}>
                    ${isPendingHandle ? `<span class="qty-max-hint">/ ${escapeHtml(qty)}</span>` : ''}
                </div>
            </div>
            ${bodyHtml}
        `;

        // Chặn vượt SL gốc ngay khi rời ô (blur/change): > max -> kéo về max; <= 0 -> trả về max.
        if (isPendingHandle) {
            const $qtyInp = li.querySelector('.input-quantity');
            $qtyInp.addEventListener('change', () => {
                const max = parseFloat($qtyInp.getAttribute('data-max-qty')) || 0;
                let v = parseFloat($qtyInp.value) || 0;
                if (v > max || v <= 0) v = max;
                $qtyInp.value = v;
            });
        }

        const $btnRm = li.querySelector('.btn-remove-product');
        $btnRm.addEventListener('click', () => removeItem(li));

        // Toggle .active cho .wp-reason / .wp-handling khi user click radio
        attachRadioGroupActive(li, '.wp-reason');
        attachRadioGroupActive(li, '.wp-handling');

        $list.appendChild(li);
        addedProducts.add(pid);
    }

    function attachRadioGroupActive(scope, groupSel) {
        const group = scope.querySelector(groupSel);
        if (!group) return;
        group.addEventListener('change', (e) => {
            if (!e.target.matches('input[type="radio"]')) return;
            group.querySelectorAll(':scope > div').forEach(d => d.classList.remove('active'));
            const wrap = e.target.closest('div');
            if (wrap) wrap.classList.add('active');
        });
        // Click vào ô bao quanh radio cũng tick được
        group.addEventListener('click', (e) => {
            const wrap = e.target.closest(':scope > div');
            if (!wrap || wrap.querySelector('input').disabled) return;
            const radio = wrap.querySelector('input[type="radio"]');
            if (!radio || radio.checked) return;
            radio.checked = true;
            radio.dispatchEvent(new Event('change', { bubbles: true }));
        });
    }

    function clearList() {
        $list.innerHTML = '';
        addedProducts.clear();
    }

    function syncInterpretations() {
        // Không hiển thị input interpretation — chỉ build lúc gửi.
        // Hàm này giữ chỗ cho tương lai nếu cần.
    }

    // ---------- Collect ----------
    function collectItemsForRecord() {
        const items = [];
        const dateVN = pickerToDateVN();
        const sn = (selectedCustomer && selectedCustomer.short_name) ? selectedCustomer.short_name : '';
        const interp = buildInterpretation(sn, dateVN);
        $list.querySelectorAll('.product-item').forEach(li => {
            const pid = parseInt(li.getAttribute('data-product-id'), 10);
            const qty = parseFloat((li.querySelector('.input-quantity') || {}).value || '0') || 0;
            const reasonEl = li.querySelector('.wp-reason input[type="radio"]:checked');
            const reason = reasonEl ? reasonEl.value : '';
            items.push({ product_id: pid, quantity: qty, reason: reason, interpretation: interp });
        });
        return items;
    }

    /**
     * Trong edit mode: thu cả items cũ (có sales_return_id) và items mới chèn thêm
     * (chỉ có product_id). Server sẽ phân biệt update vs insert.
     */
    function collectItemsForEdit() {
        const items = [];
        const dateVN = pickerToDateVN();
        const sn = (selectedCustomer && selectedCustomer.short_name) ? selectedCustomer.short_name : '';
        const interp = buildInterpretation(sn, dateVN);
        $list.querySelectorAll('.product-item').forEach(li => {
            const pid = parseInt(li.getAttribute('data-product-id'), 10);
            const sid = parseInt(li.getAttribute('data-sales-return-id'), 10) || 0;
            const iid = parseInt(li.getAttribute('data-import-id'), 10) || 0;
            const qty = parseFloat((li.querySelector('.input-quantity') || {}).value || '0') || 0;
            const reasonEl = li.querySelector('.wp-reason input[type="radio"]:checked');
            const reason = reasonEl ? reasonEl.value : '';
            items.push({
                sales_return_id: sid,
                import_id:       iid,
                product_id:      pid,
                quantity:        qty,
                reason:          reason,
                interpretation:  interp,
            });
        });
        return items;
    }

    function collectItemsForHandle() {
        const items = [];
        $list.querySelectorAll('.product-item').forEach(li => {
            const sid = parseInt(li.getAttribute('data-sales-return-id'), 10);
            if (!sid) return;
            const methodEl = li.querySelector('.wp-handling input[type="radio"]:checked');
            const method = methodEl ? methodEl.value : '';
            if (!method) return; // bỏ qua sản phẩm chưa chọn cách thức
            const item = { sales_return_id: sid, method: method };
            // SL xử lý đợt này (chỉ dòng đang "Chờ xử lý" có data-max-qty) — nhỏ hơn SL gốc
            // thì server tách dòng, phần còn lại giữ "Chờ xử lý" cho đợt sản xuất sau.
            const $qtyInp = li.querySelector('.input-quantity[data-max-qty]');
            if ($qtyInp) {
                const max = parseFloat($qtyInp.getAttribute('data-max-qty')) || 0;
                let q = parseFloat($qtyInp.value) || 0;
                if (q > max || q <= 0) q = max;
                item.qty = q;
            }
            items.push(item);
        });
        return items;
    }

    // ---------- Mode switching ----------
    function setMode(newMode) {
        mode = newMode;
        $btnRec.style.display    = (mode === 'create') ? '' : 'none';
        $btnEdit.style.display   = (mode === 'edit')   ? '' : 'none';
        $btnHandle.style.display = (mode === 'handle') ? '' : 'none';
        // Search vẫn hiển thị trong create + edit (cho phép chèn thêm SP khi sửa).
        // Ẩn ở handle vì handle chỉ chọn cách xử lý cho list hiện có.
        const $wpSearch = document.querySelector('.wp-sales-return .wp-search');
        if ($wpSearch) $wpSearch.style.display = (mode === 'handle') ? 'none' : '';
    }

    function enterEditMode(batch) {
        editingBatch = batch;
        setMode('edit');
        if (window.JE && window.JE.loadTemplatesAsBlocks) window.JE.loadTemplatesAsBlocks();
        // Set customer
        selectedCustomer = {
            id:         batch.customer_id,
            name:       batch.customer_name,
            short_name: batch.customer_short,
        };
        $cust.value = batch.customer_name;
        $custShort.value = batch.customer_short || '';
        // Set datetime
        const localVal = mysqlToLocalValue(batch.created_at);
        if (localVal) $dateTime.value = localVal;

        clearList();
        batch.items.forEach(it => addProductItem({
            product_id:      it.product_id,
            product_name:    it.product_name,
            quantity:        it.quantity,
            reason:          it.reason,
            sales_return_id: it.sales_return_id,
            import_id:       it.import_id,
            handling_method: it.handling_method,
        }));

        $banner.style.display = 'flex';
        $modeLabel.textContent = 'sửa';
        $bannerLabel.textContent = batch.date_display + ' · ' + (batch.customer_short || batch.customer_name);
        document.querySelector('.content').scrollIntoView({ behavior: 'smooth' });
    }

    function enterHandleMode(batch) {
        editingBatch = batch;
        setMode('handle');
        selectedCustomer = {
            id:         batch.customer_id,
            name:       batch.customer_name,
            short_name: batch.customer_short,
        };
        $cust.value = batch.customer_name;
        $custShort.value = batch.customer_short || '';
        const localVal = mysqlToLocalValue(batch.created_at);
        if (localVal) $dateTime.value = localVal;

        clearList();
        batch.items.forEach(it => addProductItem({
            product_id:      it.product_id,
            product_name:    it.product_name,
            quantity:        it.quantity,
            reason:          it.reason,
            sales_return_id: it.sales_return_id,
            import_id:       it.import_id,
            handling_method: it.handling_method,
        }));

        $banner.style.display = 'flex';
        $modeLabel.textContent = 'xử lý';
        $bannerLabel.textContent = batch.date_display + ' · ' + (batch.customer_short || batch.customer_name);
        document.querySelector('.content').scrollIntoView({ behavior: 'smooth' });
    }

    function exitEditMode() {
        editingBatch = null;
        setMode('create');
        $banner.style.display = 'none';
        clearList();
        $cust.value = '';
        $custShort.value = '';
        selectedCustomer = null;
        $dateTime.value = nowLocalValue();
        if (window.JE && window.JE.reset) window.JE.reset();
    }

    $btnCancelEdit.addEventListener('click', (e) => {
        e.preventDefault();
        exitEditMode();
    });

    // ---------- Record / Edit / Handle ----------
    function flashActive(btn) {
        [$btnRec, $btnEdit, $btnHandle].forEach(b => b && b.classList.remove('active'));
        if (btn) btn.classList.add('active');
    }

    $btnRec.addEventListener('click', () => {
        if (mode !== 'create') return;
        if (!selectedCustomer || !selectedCustomer.id) {
            alert('Hãy chọn khách hàng từ danh sách trước.');
            $cust.focus();
            return;
        }
        const items = collectItemsForRecord().filter(it => it.quantity > 0);
        if (!items.length) {
            alert('Chưa có sản phẩm nào có số lượng hợp lệ để ghi.');
            return;
        }
        // Cảnh báo nếu có item chưa chọn lý do
        const noReason = items.filter(it => !it.reason);
        if (noReason.length) {
            if (!confirm('Có ' + noReason.length + ' sản phẩm chưa chọn lý do. Vẫn ghi?')) return;
        }
        if (!confirm('Ghi nhập hàng bán trả lại của KH "' + selectedCustomer.name + '"?')) return;

        flashActive($btnRec);
        postForm('record_sales_return', Object.assign({
            customer_id: selectedCustomer.id,
            items:       JSON.stringify(items),
            created_at:  pickerToMysql(),
        }, jePayload())).then(res => {
            if (res && res.success) {
                if (window.appFlyToHistory) window.appFlyToHistory($btnRec);
                setTimeout(() => window.location.reload(), 950);
            } else {
                alert(res && res.message ? res.message : 'Có lỗi xảy ra.');
            }
        });
    });

    $btnEdit.addEventListener('click', () => {
        if (mode !== 'edit') return;
        const items = collectItemsForEdit().filter(it => it.quantity > 0 || it.sales_return_id);
        if (!items.length) {
            alert('Không có dòng nào để cập nhật.');
            return;
        }
        flashActive($btnEdit);
        postForm('edit_sales_return', Object.assign({
            items:       JSON.stringify(items),
            customer_id: editingBatch ? editingBatch.customer_id : '',
            created_at:  pickerToMysql(),
        }, jePayload())).then(res => {
            if (res && res.success) {
                if (window.appFlyToHistory) window.appFlyToHistory($btnEdit);
                renderHistory(res.history || []);
                exitEditMode();
            } else {
                alert(res && res.message ? res.message : 'Có lỗi xảy ra.');
            }
        });
    });

    $btnHandle.addEventListener('click', () => {
        if (mode !== 'handle') return;
        const items = collectItemsForHandle();
        if (!items.length) {
            alert('Chưa có sản phẩm nào được chọn cách thức xử lý.');
            return;
        }
        flashActive($btnHandle);
        postForm('handle_sales_return', {
            items: JSON.stringify(items),
        }).then(res => {
            if (res && res.success) {
                if (window.appFlyToHistory) window.appFlyToHistory($btnHandle);
                renderHistory(res.history || []);
                exitEditMode();
            } else {
                alert(res && res.message ? res.message : 'Có lỗi xảy ra.');
            }
        });
    });

    // ---------- History ----------
    /**
     * Tính trạng thái xử lý của 1 batch:
     *   'done'    — toàn bộ items đã có handling_method khác "Chờ xử lý" (tích xanh)
     *   'partial' — có 1 phần đã xử lý (icon vàng)
     *   'pending' — chưa item nào được xử lý (chéo đỏ)
     */
    function batchStatus(items) {
        if (!items || !items.length) return 'pending';
        let handled = 0;
        items.forEach(it => {
            const m = (it.handling_method || '').trim();
            if (m && m !== PENDING) handled++;
        });
        if (handled === 0) return 'pending';
        if (handled === items.length) return 'done';
        return 'partial';
    }

    function statusIconHtml(status) {
        if (status === 'done') {
            return '<span class="status-icon status-done" title="Đã xử lý hết"><i class="fa-solid fa-circle-check"></i></span>';
        }
        if (status === 'partial') {
            return '<span class="status-icon status-partial" title="Đã xử lý một phần"><i class="fa-solid fa-circle-exclamation"></i></span>';
        }
        return '<span class="status-icon status-pending" title="Chưa xử lý"><i class="fa-solid fa-circle-xmark"></i></span>';
    }

    function renderHistory(batches) {
        INITIAL.history = batches || [];
        historyPage = 1;
        renderHistoryPage();
    }

    // Ngày của batch dạng 'YYYY-MM-DD'. Ưu tiên created_at ('YYYY-MM-DD HH:MM:SS');
    // fallback parse từ date_display ('HH:ii:ss dd/mm/yyyy' hoặc 'dd/mm/yyyy').
    function batchDateYMD(b) {
        const iso = /^(\d{4})-(\d{2})-(\d{2})/.exec(String(b && b.created_at ? b.created_at : ''));
        if (iso) return iso[1] + '-' + iso[2] + '-' + iso[3];
        const vn = /(\d{1,2})\/(\d{1,2})\/(\d{4})/.exec(String(b && b.date_display ? b.date_display : ''));
        if (vn) return vn[3] + '-' + pad2(vn[2]) + '-' + pad2(vn[1]);
        return '';
    }

    // Áp dụng bộ lọc (từ khóa + khoảng ngày) lên INITIAL.history.
    function getFilteredHistory() {
        const all = INITIAL.history || [];
        const kw = histFilter.keyword.trim().toLowerCase();
        const from = histFilter.dateFrom;
        const to   = histFilter.dateTo;
        if (!kw && !from && !to) return all;
        return all.filter(b => {
            if (kw) {
                const inSummary = String(b.summary || '').toLowerCase().indexOf(kw) !== -1;
                const inItems = Array.isArray(b.items) && b.items.some(it =>
                    String(it.product_name || '').toLowerCase().indexOf(kw) !== -1);
                if (!inSummary && !inItems) return false;
            }
            if (from || to) {
                const d = batchDateYMD(b);
                if (from && d < from) return false;
                if (to && d > to) return false;
            }
            return true;
        });
    }

    function updateFilterUI() {
        const active = histFilter.keyword.trim() !== '';
        if ($hfKeywordBtn) $hfKeywordBtn.classList.toggle('active', active);
    }

    function renderHistoryPage() {
        const total = (INITIAL.history || []).length;
        const data = getFilteredHistory();
        const totalPages = Math.max(1, Math.ceil(data.length / pageSize));
        if (historyPage > totalPages) historyPage = totalPages;
        if (historyPage < 1) historyPage = 1;

        if ($hfCount) {
            $hfCount.textContent = data.length === total
                ? (total + ' nhóm')
                : (data.length + '/' + total + ' nhóm');
        }
        updateFilterUI();

        if (!data.length) {
            const msg = total ? 'Không có nhóm nào khớp bộ lọc.' : 'Chưa có thao tác nào.';
            $histBody.innerHTML = '<tr class="history-empty"><td colspan="4">' + msg + '</td></tr>';
            if ($histPager) $histPager.innerHTML = '';
            return;
        }
        const start = (historyPage - 1) * pageSize;
        const slice = data.slice(start, start + pageSize);
        // data-group-key + data-customer-id là khóa tra cứu batch (handler Sửa/Xóa/Xử lý
        // dùng .find() theo chúng) → an toàn khi đang lọc/phân trang, không phụ thuộc index.
        $histBody.innerHTML = slice.map(b => {
            const status = batchStatus(b.items || []);
            const secondAction = (status === 'done')
                ? '<a href="#" class="delete-list-return">Xóa</a>'
                : '<a href="#" class="handle-list-return">Xử lý</a>';
            return `
                <tr data-group-key="${escapeHtml(b.group_key)}" data-customer-id="${b.customer_id}">
                    <td class="${b.date_color ? 'hist-date-' + b.date_color : ''}">${escapeHtml(b.date_display)}</td>
                    <td class="col-status">${statusIconHtml(status)}</td>
                    <td title="${escapeHtml(b.summary)}">${escapeHtml(b.summary)}</td>
                    <td class="history-actions">
                        <a href="#" class="edit-list-return">Sửa</a>
                        <span class="sep">|</span>
                        ${secondAction}
                    </td>
                </tr>`;
        }).join('');
        renderPager(totalPages);
    }

    function renderPager(totalPages) {
        if (!$histPager) return;
        if (totalPages <= 1) { $histPager.innerHTML = ''; return; }
        const parts = [];
        parts.push(`<button type="button" class="page-btn page-prev" ${historyPage === 1 ? 'disabled' : ''}>«</button>`);
        const range = pageRange(historyPage, totalPages);
        range.forEach(p => {
            if (p === '...') parts.push('<span class="page-ellipsis">…</span>');
            else parts.push(`<button type="button" class="page-btn page-num ${p === historyPage ? 'active' : ''}" data-page="${p}">${p}</button>`);
        });
        parts.push(`<button type="button" class="page-btn page-next" ${historyPage === totalPages ? 'disabled' : ''}>»</button>`);
        $histPager.innerHTML = parts.join('');
    }

    function pageRange(current, total) {
        // Kiểu "1 2 3 4 .. 17 .. 30" (yêu cầu R2-4).
        const out = [];
        if (total <= 7) { for (let i = 1; i <= total; i++) out.push(i); return out; }
        let start = current <= 1 ? 1 : current - 1;
        let end = start + 3;
        if (end >= total - 1) { start = Math.max(1, total - 4); for (let i = start; i <= total; i++) out.push(i); return out; }
        for (let i = start; i <= end; i++) out.push(i);
        out.push('...');
        const mid = Math.round((end + total) / 2);
        if (mid > end && mid < total) { out.push(mid); out.push('...'); }
        out.push(total);
        return out;
    }

    if ($histPager) {
        $histPager.addEventListener('click', (e) => {
            const $btn = e.target.closest('.page-btn');
            if (!$btn || $btn.disabled) return;
            if ($btn.classList.contains('page-prev')) historyPage = Math.max(1, historyPage - 1);
            else if ($btn.classList.contains('page-next')) historyPage = historyPage + 1;
            else if ($btn.classList.contains('page-num')) historyPage = parseInt($btn.getAttribute('data-page'), 10) || 1;
            renderHistoryPage();
        });
    }

    $histBody.addEventListener('click', (e) => {
        const editLink   = e.target.closest('.edit-list-return');
        const handleLink = e.target.closest('.handle-list-return');
        const deleteLink = e.target.closest('.delete-list-return');
        if (!editLink && !handleLink && !deleteLink) return;
        e.preventDefault();
        const tr = e.target.closest('tr');
        if (!tr) return;
        const groupKey = tr.getAttribute('data-group-key');
        const cid      = parseInt(tr.getAttribute('data-customer-id'), 10);

        if (deleteLink) {
            const cached = (INITIAL.history || []).find(h =>
                h.group_key === groupKey && Number(h.customer_id) === cid);
            const label = cached ? (cached.date_display + ' · ' + (cached.customer_short || '')) : groupKey;
            if (!confirm('Xóa nhóm "' + label + '"? Tồn kho và bút toán liên quan sẽ được hoàn lại.')) return;
            postForm('delete_sales_return_batch', { group_key: groupKey, customer_id: cid }).then(res => {
                if (!res || !res.success) {
                    alert((res && res.message) ? res.message : 'Có lỗi xảy ra.');
                    return;
                }
                alert('Đã xóa ' + (res.removed || 0) + ' dòng.');
                renderHistory(res.history || []);
                if (editingBatch && editingBatch.group_key === groupKey
                    && Number(editingBatch.customer_id) === cid) {
                    exitEditMode();
                }
            });
            return;
        }

        postForm('get_sales_return_batch', { group_key: groupKey, customer_id: cid }).then(res => {
            if (!res || !res.success) {
                alert((res && res.message) ? res.message : 'Có lỗi xảy ra.');
                return;
            }
            const batch = res.data;
            // Bổ sung date_display từ history cache (server không trả lại field này trong getBatch)
            const cached = (INITIAL.history || []).find(h =>
                h.group_key === groupKey && Number(h.customer_id) === cid);
            batch.date_display = cached ? cached.date_display : batch.created_at;

            if (editLink)   enterEditMode(batch);
            if (handleLink) enterHandleMode(batch);
        });
    });

    // ---------- History filter wiring ----------
    function setupHistoryFilter() {
        if ($hfPageSize) {
            pageSize = parseInt($hfPageSize.value, 10) || 10;
            $hfPageSize.addEventListener('change', () => {
                pageSize = parseInt($hfPageSize.value, 10) || 10;
                historyPage = 1;
                renderHistoryPage();
            });
        }
        if ($hfDateFrom) {
            $hfDateFrom.addEventListener('change', () => {
                histFilter.dateFrom = $hfDateFrom.value;
                historyPage = 1;
                renderHistoryPage();
            });
        }
        if ($hfDateTo) {
            $hfDateTo.addEventListener('change', () => {
                histFilter.dateTo = $hfDateTo.value;
                historyPage = 1;
                renderHistoryPage();
            });
        }
        if ($hfKeyword) {
            $hfKeyword.addEventListener('input', () => {
                histFilter.keyword = $hfKeyword.value;
                historyPage = 1;
                renderHistoryPage();
            });
        }
        // Mở/đóng popover phễu
        if ($hfKeywordBtn && $hfKeywordPop) {
            $hfKeywordBtn.addEventListener('click', (e) => {
                e.stopPropagation();
                const open = $hfKeywordPop.classList.toggle('open');
                if (open && $hfKeyword) $hfKeyword.focus();
            });
            $hfKeywordPop.addEventListener('click', (e) => e.stopPropagation());
            document.addEventListener('click', () => $hfKeywordPop.classList.remove('open'));
            $hfKeyword && $hfKeyword.addEventListener('keydown', (e) => {
                if (e.key === 'Escape') $hfKeywordPop.classList.remove('open');
            });
        }
        if ($hfReset) {
            $hfReset.addEventListener('click', () => {
                histFilter.keyword = '';
                histFilter.dateFrom = '';
                histFilter.dateTo = '';
                if ($hfKeyword)  $hfKeyword.value = '';
                if ($hfDateFrom) $hfDateFrom.value = '';
                if ($hfDateTo)   $hfDateTo.value = '';
                if ($hfKeywordPop) $hfKeywordPop.classList.remove('open');
                historyPage = 1;
                renderHistoryPage();
            });
        }
    }

    // ---------- Init ----------
    function init() {
        $dateTime.value = nowLocalValue();
        INITIAL.planDate = pickerToDateVN();
        setupJe();
        setupHistoryFilter();
        renderHistory(INITIAL.history || []);
        setMode('create');
    }

    init();
})();
