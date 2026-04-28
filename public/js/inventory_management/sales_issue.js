(function () {
    'use strict';

    const CFG = window.INVENTORY_CONFIG || { baseUrl: '?mod=inventory_management&controllers=inventory_management&action=' };
    const INITIAL = window.INVENTORY_DATA || { planDate: '', history: [], typeExport: 'sales_issue' };

    // Demo defaults — chưa có DB cho các field này, tạm cố định.
    const DEFAULT_UNIT     = 'Gói';
    const DEFAULT_WAREHOUSE = 'Kho TP';
    const DEFAULT_WEIGHT    = 1;          // khối lượng / 1 đơn vị (kg)
    const DEFAULT_PRICE     = 100000;     // đơn giá

    let selectedCustomer = null; // {id, name, short_name, address, receiver, phone}

    let custDropdownItems = [];
    let custActiveIdx = -1;

    // dropdown sản phẩm bám theo input đang focus (mỗi dòng có 1 .name_product)
    let prodDropdownItems = [];
    let prodActiveIdx = -1;
    let activeProductInput = null;

    const $cust          = document.getElementById('customer');
    const $custDropdown  = document.getElementById('customer-dropdown');
    const $address       = document.getElementById('address');
    const $receiver      = document.getElementById('receiver');

    const $tbody         = document.getElementById('sale-tbody');
    const $totalWeight   = document.querySelector('.wp-total .wp-weight .result');
    const $totalValue    = document.querySelector('.wp-total .wp-value .result');

    const $btnRec        = document.getElementById('btn-record');
    const $btnEdit       = document.getElementById('btn-edit');

    const $histBody      = document.getElementById('history-tbody');
    const $banner        = document.getElementById('edit-batch-banner');
    const $bannerLabel   = document.getElementById('edit-batch-label');
    const $btnCancelEdit = document.getElementById('cancel-edit-batch');

    // 1 dropdown sản phẩm dùng chung, gắn vào body, định vị tuyệt đối dưới input đang gõ
    const $prodDropdown = document.createElement('ul');
    $prodDropdown.className = 'customer-dropdown product-suggest-dropdown';
    $prodDropdown.style.position = 'absolute';
    $prodDropdown.style.zIndex = '20';
    $prodDropdown.style.maxWidth = '320px';
    document.body.appendChild($prodDropdown);

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

    function fmtMoney(n) {
        const v = Number(n) || 0;
        return v.toLocaleString('vi-VN') + ' đ';
    }

    function fmtNumber(n) {
        const v = Number(n) || 0;
        return v.toLocaleString('vi-VN');
    }

    function pad2(n) { return String(n).padStart(2, '0'); }

    function nowMysql() {
        const d = new Date();
        return d.getFullYear() + '-' + pad2(d.getMonth() + 1) + '-' + pad2(d.getDate())
             + ' ' + pad2(d.getHours()) + ':' + pad2(d.getMinutes()) + ':' + pad2(d.getSeconds());
    }

    function buildReceiverDisplay(c) {
        const r = (c && c.receiver) ? c.receiver.trim() : '';
        const p = (c && c.phone)    ? c.phone.trim()    : '';
        if (r && p) return r + ' - ' + p;
        return r || p || '';
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
        postForm('get_customer_full', { customer_id: id }).then(res => {
            if (!res.success) { alert(res.message || 'Lỗi'); return; }
            selectedCustomer = res.data;
            $cust.value = selectedCustomer.name;
            $address.value = selectedCustomer.address || '';
            $receiver.value = buildReceiverDisplay(selectedCustomer);
            hideCustomerDropdown();
            ensureEmptyRow();
        });
    }

    $cust.addEventListener('input', () => {
        if (selectedCustomer && $cust.value.trim() !== selectedCustomer.name) {
            selectedCustomer = null;
            $address.value = '';
            $receiver.value = '';
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
        } else if (e.key === 'Enter') {
            e.preventDefault();
            if (custActiveIdx >= 0) selectCustomerItem(items[custActiveIdx]);
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
        if (!e.target.closest('.name_product') && !e.target.closest('.product-suggest-dropdown')) hideProductDropdown();
    });

    // ---------- Row management ----------
    function makeRow(opts) {
        opts = opts || {};
        const tr = document.createElement('tr');
        tr.setAttribute('data-product-id', opts.product_id || '');
        tr.innerHTML = `
            <td><input type="text" class="name_product" autocomplete="off" placeholder="Tìm sản phẩm..." value="${escapeHtml(opts.product_name || '')}"></td>
            <td><input type="text" class="quantity" inputmode="numeric" value="${opts.quantity != null ? escapeHtml(String(opts.quantity)) : ''}"></td>
            <td class="cell-unit">${escapeHtml(DEFAULT_UNIT)}</td>
            <td class="cell-warehouse">${escapeHtml(DEFAULT_WAREHOUSE)}</td>
            <td class="cell-weight">${DEFAULT_WEIGHT}</td>
            <td class="cell-total-weight">0</td>
            <td class="cell-price">${fmtMoney(DEFAULT_PRICE)}</td>
            <td class="cell-amount">0 đ</td>
            <td><button type="button" class="btn-remove-row" title="Xóa dòng">×</button></td>
        `;
        return tr;
    }

    function isRowEmpty(tr) {
        const pid  = parseInt(tr.getAttribute('data-product-id'), 10) || 0;
        const name = (tr.querySelector('.name_product').value || '').trim();
        const qty  = (tr.querySelector('.quantity').value || '').trim();
        return !pid && !name && !qty;
    }

    /** Đảm bảo luôn có 1 dòng trống ở cuối để user nhập tiếp. */
    function ensureEmptyRow() {
        const rows = $tbody.querySelectorAll('tr');
        if (!rows.length || !isRowEmpty(rows[rows.length - 1])) {
            $tbody.appendChild(makeRow());
        }
        recalcTotals();
    }

    function recalcRow(tr) {
        const qty = parseFloat(tr.querySelector('.quantity').value) || 0;
        const w   = parseFloat(tr.querySelector('.cell-weight').textContent) || 0;
        const p   = DEFAULT_PRICE;
        const totalW = qty * w;
        const amount = qty * p;
        tr.querySelector('.cell-total-weight').textContent = fmtNumber(totalW);
        tr.querySelector('.cell-amount').textContent = fmtMoney(amount);
        tr.setAttribute('data-amount', amount);
        tr.setAttribute('data-total-weight', totalW);
    }

    function recalcTotals() {
        let totalW = 0, totalV = 0;
        $tbody.querySelectorAll('tr').forEach(tr => {
            recalcRow(tr);
            totalW += parseFloat(tr.getAttribute('data-total-weight')) || 0;
            totalV += parseFloat(tr.getAttribute('data-amount')) || 0;
        });
        if ($totalWeight) $totalWeight.textContent = fmtNumber(totalW) + ' kg';
        if ($totalValue)  $totalValue.textContent  = fmtMoney(totalV);
    }

    // Click X xoá dòng / typing trong .quantity / .name_product
    $tbody.addEventListener('click', (e) => {
        const btn = e.target.closest('.btn-remove-row');
        if (!btn) return;
        const tr = btn.closest('tr');
        if (!tr) return;
        tr.remove();
        ensureEmptyRow();
    });

    $tbody.addEventListener('input', (e) => {
        const tr = e.target.closest('tr');
        if (!tr) return;

        if (e.target.classList.contains('quantity')) {
            // Cảnh báo tồn được làm sạch khi user gõ lại
            tr.classList.remove('row-shortage');
            tr.removeAttribute('title');
            recalcRow(tr);
            recalcTotals();
        }
        if (e.target.classList.contains('name_product')) {
            // user gõ lại → reset product_id
            tr.setAttribute('data-product-id', '');
            tr.classList.remove('row-shortage');
            tr.removeAttribute('title');
            runProductSearch(e.target);
        }
    });

    // Khi nhấn Enter trong .quantity → tạo dòng mới (hoặc focus dòng trống tiếp theo)
    $tbody.addEventListener('keydown', (e) => {
        const tr = e.target.closest('tr');
        if (!tr) return;
        if (e.target.classList.contains('quantity')) {
            if (e.key === 'Enter' || e.key === 'Tab') {
                const val = (e.target.value || '').trim();
                const pid = parseInt(tr.getAttribute('data-product-id'), 10) || 0;
                if (val !== '' && pid) {
                    e.preventDefault();
                    ensureEmptyRow();
                    const last = $tbody.querySelectorAll('tr');
                    const next = last[last.length - 1];
                    const nextName = next.querySelector('.name_product');
                    if (nextName) nextName.focus();
                }
            }
        }
        // Enter trong .name_product → dùng để chọn item dropdown (handled trong product search keydown)
    });

    // ---------- Product search (gắn theo input đang focus) ----------
    const runProductSearch = debounce(function (inputEl) {
        const kw = (inputEl.value || '').trim();
        activeProductInput = inputEl;
        if (kw === '') { hideProductDropdown(); return; }
        postForm('search_product', { keyword: kw }).then(res => {
            renderProductDropdown(res.data || [], inputEl);
        });
    }, 220);

    function positionProductDropdown(inputEl) {
        const r = inputEl.getBoundingClientRect();
        $prodDropdown.style.top  = (window.scrollY + r.bottom + 4) + 'px';
        $prodDropdown.style.left = (window.scrollX + r.left) + 'px';
        $prodDropdown.style.minWidth = r.width + 'px';
    }

    function renderProductDropdown(items, inputEl) {
        prodDropdownItems = items;
        prodActiveIdx = -1;
        if (!items.length) {
            $prodDropdown.innerHTML = '<li class="empty">Không tìm thấy sản phẩm</li>';
        } else {
            $prodDropdown.innerHTML = items.map((it, i) =>
                `<li data-id="${it.id}" data-idx="${i}">${escapeHtml(it.product_name)}</li>`
            ).join('');
        }
        positionProductDropdown(inputEl);
        $prodDropdown.classList.add('active');
    }

    function hideProductDropdown() {
        $prodDropdown.classList.remove('active');
        $prodDropdown.innerHTML = '';
        prodActiveIdx = -1;
        activeProductInput = null;
    }

    function selectProductItem(el) {
        const id = parseInt(el.getAttribute('data-id'), 10);
        if (!id || !activeProductInput) return;
        const tr = activeProductInput.closest('tr');
        const text = el.textContent || '';
        // Kiểm tra trùng product trong các dòng khác
        const rows = $tbody.querySelectorAll('tr');
        for (const r of rows) {
            if (r === tr) continue;
            const pid = parseInt(r.getAttribute('data-product-id'), 10) || 0;
            if (pid === id) {
                alert('Sản phẩm "' + text + '" đã có trong danh sách.');
                hideProductDropdown();
                return;
            }
        }
        tr.setAttribute('data-product-id', id);
        activeProductInput.value = text;
        hideProductDropdown();
        // Auto chuyển focus sang ô .quantity của cùng dòng
        const $qty = tr.querySelector('.quantity');
        if ($qty) { $qty.focus(); $qty.select(); }
    }

    $tbody.addEventListener('focusin', (e) => {
        if (e.target.classList.contains('name_product')) {
            activeProductInput = e.target;
            const kw = (e.target.value || '').trim();
            if (kw && prodDropdownItems.length) {
                positionProductDropdown(e.target);
                $prodDropdown.classList.add('active');
            }
        }
    });

    $tbody.addEventListener('keydown', (e) => {
        if (!e.target.classList.contains('name_product')) return;
        if (!$prodDropdown.classList.contains('active')) return;
        const items = $prodDropdown.querySelectorAll('li:not(.empty)');
        if (!items.length) return;
        if (e.key === 'ArrowDown') {
            e.preventDefault();
            prodActiveIdx = (prodActiveIdx + 1) % items.length;
        } else if (e.key === 'ArrowUp') {
            e.preventDefault();
            prodActiveIdx = (prodActiveIdx - 1 + items.length) % items.length;
        } else if (e.key === 'Enter') {
            e.preventDefault();
            if (prodActiveIdx >= 0) selectProductItem(items[prodActiveIdx]);
            return;
        } else if (e.key === 'Escape') {
            hideProductDropdown();
            return;
        } else {
            return;
        }
        items.forEach(li => li.classList.remove('active'));
        items[prodActiveIdx].classList.add('active');
        items[prodActiveIdx].scrollIntoView({ block: 'nearest' });
    });

    $prodDropdown.addEventListener('click', (e) => {
        const li = e.target.closest('li');
        if (!li || li.classList.contains('empty')) return;
        selectProductItem(li);
    });

    window.addEventListener('scroll', () => {
        if ($prodDropdown.classList.contains('active') && activeProductInput) {
            positionProductDropdown(activeProductInput);
        }
    }, true);
    window.addEventListener('resize', () => {
        if ($prodDropdown.classList.contains('active') && activeProductInput) {
            positionProductDropdown(activeProductInput);
        }
    });

    // ---------- Collect & Record ----------
    function collectItems() {
        const items = [];
        const dateStr = (function () {
            const d = new Date();
            return pad2(d.getDate()) + '/' + pad2(d.getMonth() + 1) + '/' + d.getFullYear();
        })();
        const sn = selectedCustomer && selectedCustomer.short_name
            ? selectedCustomer.short_name : (selectedCustomer ? selectedCustomer.name : '');
        const interp = 'Bán hàng ' + (sn || 'KH') + ' ngày ' + dateStr;

        $tbody.querySelectorAll('tr').forEach(tr => {
            const pid = parseInt(tr.getAttribute('data-product-id'), 10) || 0;
            const qty = parseFloat(tr.querySelector('.quantity').value) || 0;
            if (pid <= 0 || qty <= 0) return;
            const totalAmount = qty * DEFAULT_PRICE;
            items.push({
                tr_ref:        tr,           // tạm giữ để highlight cảnh báo (loại trước khi gửi)
                product_id:    pid,
                quantity:      qty,
                unit_price:    DEFAULT_PRICE,
                total_amount:  totalAmount,
                interpretation: interp,
            });
        });
        return items;
    }

    function flashActive(btn) {
        [$btnRec, $btnEdit].forEach(b => b && b.classList.remove('active'));
        if (btn) btn.classList.add('active');
    }

    $btnRec.addEventListener('click', () => {
        if (!selectedCustomer || !selectedCustomer.id) {
            alert('Hãy chọn khách hàng từ danh sách trước.');
            $cust.focus();
            return;
        }
        const collected = collectItems();
        if (!collected.length) {
            alert('Chưa có sản phẩm nào hợp lệ để ghi.');
            return;
        }
        // Reset trạng thái cảnh báo cũ
        $tbody.querySelectorAll('tr').forEach(tr => {
            tr.classList.remove('row-shortage');
            tr.removeAttribute('title');
        });

        if (!confirm('Ghi xuất kho bán hàng cho "' + selectedCustomer.name + '" với '
                     + collected.length + ' sản phẩm?')) return;

        flashActive($btnRec);

        const payloadItems = collected.map(it => ({
            product_id:    it.product_id,
            quantity:      it.quantity,
            unit_price:    it.unit_price,
            total_amount:  it.total_amount,
            interpretation: it.interpretation,
        }));

        postForm('record_sales_issue', {
            customer_id: selectedCustomer.id,
            items:       JSON.stringify(payloadItems),
            created_at:  nowMysql(),
        }).then(res => {
            if (!res || !res.success) {
                alert(res && res.message ? res.message : 'Có lỗi xảy ra.');
                return;
            }
            const recordedSet = new Set((res.recorded || []).map(Number));
            const shortageMap = new Map();
            (res.shortages || []).forEach(s => shortageMap.set(Number(s.product_id), s));

            // Highlight row thiếu hàng
            collected.forEach(it => {
                const tr = it.tr_ref;
                if (shortageMap.has(it.product_id)) {
                    const s = shortageMap.get(it.product_id);
                    tr.classList.add('row-shortage');
                    tr.title = 'Thiếu hàng: tồn ' + s.available + ' < cần ' + s.requested;
                }
            });

            // Xoá các dòng đã ghi thành công, giữ lại các dòng thiếu để user check
            const remaining = [];
            collected.forEach(it => {
                if (recordedSet.has(it.product_id)) {
                    it.tr_ref.remove();
                } else {
                    remaining.push(it);
                }
            });

            renderHistory(res.history || []);
            ensureEmptyRow();

            const okCount = (res.recorded || []).length;
            const shortCount = (res.shortages || []).length;
            let msg = 'Đã ghi ' + okCount + ' sản phẩm.';
            if (shortCount > 0) {
                msg += '\n\nCó ' + shortCount + ' sản phẩm KHÔNG ghi được do thiếu tồn — '
                     + 'các dòng đỏ phía trên cần được kiểm tra.';
            }
            alert(msg);
        });
    });

    $btnEdit.addEventListener('click', () => {
        alert('Chức năng sửa sẽ được bổ sung sau.');
    });

    // ---------- History ----------
    function renderHistory(batches) {
        INITIAL.history = batches;
        if (!batches.length) {
            $histBody.innerHTML = '<tr class="history-empty"><td colspan="3">Chưa có thao tác nào.</td></tr>';
            return;
        }
        $histBody.innerHTML = batches.map(b => `
            <tr data-group-key="${escapeHtml(b.group_key)}" data-customer-id="${b.customer_id}">
                <td>${escapeHtml(b.date_display)}</td>
                <td title="${escapeHtml(b.summary)}">${escapeHtml(b.summary)}</td>
                <td class="history-actions">
                    <a href="#" class="edit-list-issue">Sửa</a>
                    <span class="sep">|</span>
                    <a href="#" class="delete-list-issue">Xóa</a>
                </td>
            </tr>
        `).join('');
    }

    $histBody.addEventListener('click', (e) => {
        const editLink = e.target.closest('.edit-list-issue');
        const delLink  = e.target.closest('.delete-list-issue');
        if (editLink) {
            e.preventDefault();
            alert('Chức năng sửa sẽ được bổ sung sau.');
        }
        if (delLink) {
            e.preventDefault();
            alert('Chức năng xóa sẽ được bổ sung sau.');
        }
    });

    if ($btnCancelEdit) {
        $btnCancelEdit.addEventListener('click', (e) => {
            e.preventDefault();
            $banner.style.display = 'none';
        });
    }

    // ---------- Init ----------
    function init() {
        renderHistory(INITIAL.history || []);
        ensureEmptyRow();
    }

    init();
})();
