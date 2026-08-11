(function () {
    'use strict';

    const CFG = window.INVENTORY_CONFIG || { baseUrl: '?mod=inventory_management&controllers=inventory_management&action=' };
    const INITIAL = window.INVENTORY_DATA || { planDate: '', history: [], typeExport: 'sales_issue' };

    // Defaults — fallback khi DB chưa có dữ liệu.
    const DEFAULT_UNIT      = 'Gói';
    const DEFAULT_WAREHOUSE = 'Kho TP';      // hiển thị khi item là product
    const MATERIAL_WAREHOUSE = 'Kho NVL';    // hiển thị khi item là material
    const DEFAULT_WEIGHT    = 0;             // fallback khi product_weights không có
    const DEFAULT_PRICE     = 0;             // fallback khi branch_*_selling_prices không có

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
    const $dateTime      = document.getElementById('record-datetime');

    // Nhắc "trước bốc hàng" (Điểm nhắc) theo khách hàng — chỉ tồn tại ở sales_delivery_note.php,
    // các view khác dùng chung file JS này sẽ có $branchReminder = null -> mọi hàm dưới tự bỏ qua.
    const $branchReminder = document.getElementById('wp-branch-reminder');
    function renderBranchReminders(list) {
        if (!$branchReminder) return;
        if (!list || !list.length) { $branchReminder.style.display = 'none'; $branchReminder.innerHTML = ''; return; }
        $branchReminder.innerHTML = list.map((r, i) => `
            <div class="wp-branch-reminder-item" data-id="${r.id}">
                <span class="wp-branch-reminder-stt">${i + 1}</span>
                <span class="wp-branch-reminder-text">${escapeHtml(r.note)}</span>
                <button type="button" class="wp-branch-reminder-del" data-id="${r.id}" title="Đã thực hiện — xóa lời nhắc này"><i class="fa-solid fa-xmark"></i></button>
            </div>`).join('');
        $branchReminder.style.display = 'flex';
    }
    function loadBranchReminders(customerId) {
        if (!$branchReminder) return;
        if (!customerId) { renderBranchReminders([]); return; }
        postForm('get_branch_pickup_reminders', { customer_id: customerId })
            .then(res => renderBranchReminders(res && res.success ? res.data : []))
            .catch(() => {});
    }
    if ($branchReminder) {
        $branchReminder.addEventListener('click', e => {
            const btn = e.target.closest('.wp-branch-reminder-del');
            if (!btn) return;
            const id = parseInt(btn.getAttribute('data-id'), 10);
            if (!id) return;
            const item = btn.closest('.wp-branch-reminder-item');
            postForm('delete_branch_pickup_reminder', { id }).then(res => {
                if (!res || !res.success) { alert((res && res.message) || 'Không xóa được lời nhắc.'); return; }
                if (item) item.remove();
                if (!$branchReminder.querySelector('.wp-branch-reminder-item')) $branchReminder.style.display = 'none';
            });
        });
    }

    const $tbody         = document.getElementById('sale-tbody');
    const $totalWeight   = document.querySelector('.wp-total .wp-weight .result');
    const $totalValue    = document.querySelector('.wp-total .wp-value .result');
    // Tổng khối lượng có thể là INPUT (sửa tay trước khi lưu) hoặc text.
    const $totalWeightInput = $totalWeight ? $totalWeight.querySelector('input') : null;
    let weightManual = false;          // user đã chỉnh tay tổng khối lượng?
    let weightLastRows = -1;           // số dòng lần tính trước (đổi -> tính lại tươi)
    if ($totalWeightInput) {
        $totalWeightInput.addEventListener('input', function () { weightManual = true; });
    }
    // Đọc tổng khối lượng từ input (nếu có) hoặc text.
    function readTotalWeight() {
        if ($totalWeightInput) return parseNumberFromText($totalWeightInput.value);
        return parseNumberFromText($totalWeight ? $totalWeight.textContent : '0');
    }

    const $btnRec        = document.getElementById('btn-record');
    const $btnEdit       = document.getElementById('btn-edit');

    const $jeDebit       = document.getElementById('je-debit');
    const $jeCredit      = document.getElementById('je-credit');
    const $jeAmount      = document.getElementById('je-amount');

    // TASK 3b: tự tính giá vốn hàng bán (632) qua AJAX, điền vào .je-amount của khối Nợ=632.
    const COGS_DEBIT = '632';
    let lastTotalV   = 0;
    let cogsTimer    = null;

    function collectCogsItems() {
        const items = [];
        $tbody.querySelectorAll('tr').forEach(tr => {
            const id   = parseInt(tr.getAttribute('data-product-id'), 10) || 0;
            const type = tr.getAttribute('data-item-type') || 'product';
            const qty  = parseFloat(tr.querySelector('.quantity').value) || 0;
            if (id > 0 && qty > 0) items.push({ id: id, type: type || 'product', qty: qty });
        });
        return items;
    }

    // Trả về list ô .je-amount thuộc khối có Nợ = 632 (cả khối đầu #je-amount).
    function cogsAmountInputs() {
        const out = [];
        document.querySelectorAll('.je-entry').forEach(b => {
            const d = b.querySelector('.je-debit');
            const a = b.querySelector('.je-amount');
            if (a && d && (d.value || '').trim() === COGS_DEBIT) out.push(a);
        });
        // Chế độ single (không có .je-entry): dựa vào #je-debit.
        if (!out.length && $jeAmount && $jeDebit && ($jeDebit.value || '').trim() === COGS_DEBIT) {
            out.push($jeAmount);
        }
        return out;
    }

    function fillCogs() {
        const targets = cogsAmountInputs();
        if (!targets.length) return;
        const items = collectCogsItems();
        if (!items.length) { targets.forEach(a => { a.value = fmtMoney(0); }); return; }
        if (cogsTimer) clearTimeout(cogsTimer);
        cogsTimer = setTimeout(() => {
            postForm('compute_sales_cogs', { items: JSON.stringify(items) }).then(res => {
                if (!res || !res.success) return;
                const v = fmtMoney(Number(res.cogs) || 0);
                cogsAmountInputs().forEach(a => { a.value = v; });
            });
        }, 250);
    }

    // Khối doanh thu (Nợ ≠ 632) = giá trị hàng hóa; khối Nợ = 632 = giá vốn (AJAX).
    function syncJeAmounts(totalV) {
        if ($jeAmount) {
            const firstDebit = $jeDebit ? ($jeDebit.value || '').trim() : '';
            if (firstDebit !== COGS_DEBIT) $jeAmount.value = fmtMoney(totalV);
        }
        fillCogs();
    }

    // Khi user gõ tài khoản Nợ (vd 632) → tính lại ô giá trị tương ứng.
    document.addEventListener('input', (e) => {
        if (e.target && e.target.classList && e.target.classList.contains('je-debit')) {
            syncJeAmounts(lastTotalV);
        }
    });

    // Các cụm bút toán dựng ASYNC (journal_entry.js fetch template rồi mới fill Nợ/Có) —
    // với luồng prefill từ branch_orders ("Xác nhận xuất kho" -> sessionStorage), recalcTotals
    // chạy TRƯỚC khi cụm kịp dựng nên 2 ô Giá trị (doanh thu + giá vốn 632) bị bỏ trống.
    // Nghe event này để đồng bộ lại sau khi cụm đã sẵn sàng.
    document.addEventListener('je:blocks-ready', () => syncJeAmounts(lastTotalV));

    const $histBody      = document.getElementById('history-tbody');
    const $histPager     = document.getElementById('history-pagination');
    const $hfDateFrom    = document.getElementById('hf-date-from');
    const $hfDateTo      = document.getElementById('hf-date-to');
    const $hfPageSize    = document.getElementById('hf-page-size');
    const $hfReset       = document.getElementById('hf-reset');
    const $hfCount       = document.getElementById('hf-count');
    const $hfKeyword     = document.getElementById('hf-keyword');
    const $hfKeywordBtn  = document.getElementById('hf-keyword-btn');
    const $hfKeywordPop  = document.getElementById('hf-keyword-pop');
    const $banner        = document.getElementById('edit-batch-banner');
    const $bannerLabel   = document.getElementById('edit-batch-label');
    const $btnCancelEdit = document.getElementById('cancel-edit-batch');
    let pageSize = 10; // số dòng/trang — đổi qua select #hf-page-size
    let historyPage = 1;
    // Bộ lọc lịch sử (client-side trên INITIAL.history đã nạp sẵn).
    const histFilter = { keyword: '', dateFrom: '', dateTo: '' };

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

    /** Thông báo nhẹ tự ẩn sau vài giây — thay cho alert() khi ghi/sửa gặp hàng hóa thiếu tồn
     *  (không cần bấm OK, dữ liệu đã được xử lý xong, chỉ là thông tin để user biết). */
    function showAutoToast(msg) {
        const el = document.createElement('div');
        el.className = 'wp-shortage-toast';
        el.textContent = msg;
        document.body.appendChild(el);
        requestAnimationFrame(() => el.classList.add('show'));
        setTimeout(() => {
            el.classList.remove('show');
            setTimeout(() => el.remove(), 250);
        }, 3200);
    }

    function fmtMoney(n) {
        // TASK 3: làm tròn 0 chữ số thập phân (đồng) — áp cho cả .je-amount.
        const v = Math.round(Number(n) || 0);
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

    // ---------- Datetime picker (#record-datetime) ----------
    function nowLocalValue() {
        const d = new Date();
        return d.getFullYear() + '-' + pad2(d.getMonth() + 1) + '-' + pad2(d.getDate())
             + 'T' + pad2(d.getHours()) + ':' + pad2(d.getMinutes()) + ':' + pad2(d.getSeconds());
    }
    // Giá trị created_at gửi server = thời điểm trên picker; fallback về hiện tại nếu trống/sai.
    function pickerToMysql() {
        const v = $dateTime ? String($dateTime.value || '') : '';
        const m = /^(\d{4})-(\d{2})-(\d{2})T(\d{2}):(\d{2})(?::(\d{2}))?$/.exec(v);
        if (!m) return nowMysql();
        return `${m[1]}-${m[2]}-${m[3]} ${m[4]}:${m[5]}:${m[6] || '00'}`;
    }
    function mysqlToLocalValue(s) {
        const m = /^(\d{4})-(\d{2})-(\d{2})[\sT](\d{2}):(\d{2}):(\d{2})$/.exec(String(s || ''));
        if (!m) return null;
        return `${m[1]}-${m[2]}-${m[3]}T${m[4]}:${m[5]}:${m[6]}`;
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
            loadBranchReminders(selectedCustomer.id);
            hideCustomerDropdown();
            ensureEmptyRow();
            // Auto chuyển focus sang ô "Tìm hàng hóa" của dòng trống cuối để nhập tiếp ngay
            // (address/receiver chỉ tự hiển thị, không cần dừng lại ở đó).
            const rows = $tbody.querySelectorAll('tr');
            const $name = rows.length ? rows[rows.length - 1].querySelector('.name_product') : null;
            if ($name) $name.focus();
        });
    }

    $cust.addEventListener('input', () => {
        if (selectedCustomer && $cust.value.trim() !== selectedCustomer.name) {
            selectedCustomer = null;
            $address.value = '';
            $receiver.value = '';
            loadBranchReminders(null);
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
            // Enter hoặc Tab đều chọn gợi ý đang tô sáng (hoặc gợi ý đầu nếu chưa di chuyển).
            const idx = custActiveIdx >= 0 ? custActiveIdx : 0;
            if (items[idx]) {
                e.preventDefault();
                selectCustomerItem(items[idx]);
            }
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
        tr.setAttribute('data-item-type', opts.item_type || ''); // 'product' | 'material' | ''
        const warehouse = opts.item_type === 'material' ? MATERIAL_WAREHOUSE : DEFAULT_WAREHOUSE;
        const weight    = (opts.weight_kg != null) ? Number(opts.weight_kg) : DEFAULT_WEIGHT;
        const price     = (opts.unit_price != null) ? Number(opts.unit_price) : DEFAULT_PRICE;
        tr.setAttribute('data-unit-price', String(price));
        const unit = (opts.unit != null && opts.unit !== '') ? String(opts.unit) : DEFAULT_UNIT;
        tr.innerHTML = `
            <td class="cell-stt"></td>
            <td><input type="text" class="name_product" autocomplete="off" placeholder="Tìm hàng hóa..." value="${escapeHtml(opts.product_name || '')}"></td>
            <td><input type="text" class="quantity" inputmode="numeric" value="${opts.quantity != null ? escapeHtml(String(opts.quantity)) : ''}"></td>
            <td class="cell-unit"><input type="text" class="unit-input" value="${escapeHtml(unit)}"></td>
            <td class="cell-warehouse">${escapeHtml(warehouse)}</td>
            <td class="cell-weight"><input type="text" class="weight-input" inputmode="decimal" value="${escapeHtml(String(weight))}"></td>
            <td class="cell-total-weight">0</td>
            <td class="cell-price"><input type="text" class="price-input" inputmode="decimal" value="${escapeHtml(String(price))}"></td>
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
        const $w  = tr.querySelector('.weight-input');
        const w   = $w ? (parseFloat($w.value) || 0) : 0;
        const $p  = tr.querySelector('.price-input');
        const p   = $p ? parseNumberFromText($p.value) : parseFloat(tr.getAttribute('data-unit-price')) || 0;
        if ($p) tr.setAttribute('data-unit-price', String(p));
        const totalW = qty * w;
        const amount = qty * p;
        tr.querySelector('.cell-total-weight').textContent = fmtNumber(totalW);
        tr.querySelector('.cell-amount').textContent = fmtMoney(amount);
        tr.setAttribute('data-amount', amount);
        tr.setAttribute('data-total-weight', totalW);
    }

    function recalcTotals() {
        let totalW = 0, totalV = 0;
        const rows = $tbody.querySelectorAll('tr');
        rows.forEach((tr, i) => {
            const $stt = tr.querySelector('.cell-stt');
            if ($stt) $stt.textContent = String(i + 1);
            recalcRow(tr);
            totalW += parseFloat(tr.getAttribute('data-total-weight')) || 0;
            totalV += parseFloat(tr.getAttribute('data-amount')) || 0;
        });
        // Số dòng đổi (thêm/bớt hàng) -> tính lại tươi, bỏ cờ chỉnh tay.
        if (rows.length !== weightLastRows) { weightManual = false; weightLastRows = rows.length; }
        if ($totalWeightInput) {
            if (!weightManual) $totalWeightInput.value = String(Math.round(totalW));
        } else if ($totalWeight) {
            $totalWeight.textContent = fmtNumber(Math.round(totalW)) + ' kg';
        }
        if ($totalValue)  $totalValue.textContent  = fmtMoney(totalV);
        lastTotalV = totalV;
        syncJeAmounts(totalV);
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

    // Bất kỳ dòng nào đang trống (chưa nhập gì) mà rời khỏi (click/focus ra ngoài dòng) →
    // tự ẩn, kể cả dòng trống cuối cùng. Muốn nhập thêm: click vào .quantity của 1 dòng đã có
    // hàng rồi Enter để chèn dòng mới ngay sau (xem handler Enter trong .quantity bên trên).
    $tbody.addEventListener('focusout', (e) => {
        const tr = e.target.closest('tr');
        if (!tr) return;
        setTimeout(() => {
            if (tr.contains(document.activeElement)) return; // vẫn còn trong dòng này
            if (!isRowEmpty(tr)) return;
            const rows = $tbody.querySelectorAll('tr');
            if (rows.length <= 1) return;                 // luôn giữ ít nhất 1 dòng để còn chỗ bắt đầu nhập
            tr.remove();
            recalcTotals();
        }, 0);
    });

    // Lưu unit về DB khi user blur khỏi .unit-input (chỉ khi đã chọn hàng hóa).
    $tbody.addEventListener('change', (e) => {
        if (!e.target.classList.contains('unit-input')) return;
        const tr = e.target.closest('tr');
        if (!tr) return;
        const id   = parseInt(tr.getAttribute('data-product-id'), 10) || 0;
        const type = tr.getAttribute('data-item-type') || '';
        if (!id || !type) return;
        const unit = (e.target.value || '').trim();
        postForm('update_item_unit', { id: id, type: type, unit: unit });
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
        if (e.target.classList.contains('weight-input') || e.target.classList.contains('price-input')) {
            // User chỉnh khối lượng/đơn giá → cập nhật cell-total-weight và tổng giá trị
            recalcRow(tr);
            recalcTotals();
        }
        if (e.target.classList.contains('name_product')) {
            // user gõ lại → reset product_id + type
            tr.setAttribute('data-product-id', '');
            tr.setAttribute('data-item-type', '');
            tr.classList.remove('row-shortage');
            tr.removeAttribute('title');
            runProductSearch(e.target);
        }
    });

    // Alt+Shift+ArrowDown: chèn thêm 1 dòng hàng hóa ngay sau dòng hiện tại.
    $tbody.addEventListener('keydown', (e) => {
        if (!(e.altKey && e.shiftKey && e.key === 'ArrowDown')) return;
        e.preventDefault();
        const curTr = e.target.closest('tr');
        const newTr = makeRow();
        if (curTr) curTr.after(newTr); else $tbody.appendChild(newTr);
        recalcTotals();
        const nameInp = newTr.querySelector('.name_product');
        if (nameInp) nameInp.focus();
    });

    // .quantity: Enter → chèn dòng mới NGAY SAU dòng hiện tại (không phải dòng cuối bảng).
    // Tab → như cũ, nhảy tới dòng trống ở cuối để tiếp tục nhập.
    $tbody.addEventListener('keydown', (e) => {
        const tr = e.target.closest('tr');
        if (!tr) return;
        if (e.target.classList.contains('quantity')) {
            const val = (e.target.value || '').trim();
            const pid = parseInt(tr.getAttribute('data-product-id'), 10) || 0;
            if (val === '' || !pid) return;
            if (e.key === 'Enter') {
                e.preventDefault();
                const newTr = makeRow();
                tr.after(newTr);
                recalcTotals();
                const nameInp = newTr.querySelector('.name_product');
                if (nameInp) nameInp.focus();
            } else if (e.key === 'Tab') {
                e.preventDefault();
                ensureEmptyRow();
                const last = $tbody.querySelectorAll('tr');
                const next = last[last.length - 1];
                const nextName = next.querySelector('.name_product');
                if (nextName) nextName.focus();
            }
        }
        // Enter trong .name_product → dùng để chọn item dropdown (handled trong product search keydown)
    });

    // ---------- Product / Material search (gắn theo input đang focus) ----------
    // Trả về items dạng {id, name, type} — type ∈ {'product','material'}.
    // Backend fallback sang material_information nếu products không có kết quả.
    const runProductSearch = debounce(function (inputEl) {
        const kw = (inputEl.value || '').trim();
        activeProductInput = inputEl;
        if (kw === '') { hideProductDropdown(); return; }
        postForm('search_product_or_material', { keyword: kw }).then(res => {
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
            $prodDropdown.innerHTML = '<li class="empty">Không tìm thấy hàng hóa</li>';
        } else {
            $prodDropdown.innerHTML = items.map((it, i) => {
                const badge = it.type === 'material'
                    ? ' <span class="item-type-badge material">NVL</span>'
                    : ' <span class="item-type-badge product">TP</span>';
                return `<li data-id="${it.id}" data-type="${escapeHtml(it.type || 'product')}" data-idx="${i}">${escapeHtml(it.name)}${badge}</li>`;
            }).join('');
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
        const id   = parseInt(el.getAttribute('data-id'), 10);
        const type = el.getAttribute('data-type') || 'product';
        if (!id || !activeProductInput) return;
        const tr = activeProductInput.closest('tr');
        // Lấy text gốc (không gồm badge)
        const liClone = el.cloneNode(true);
        const badge = liClone.querySelector('.item-type-badge');
        if (badge) badge.remove();
        const text = (liClone.textContent || '').trim();
        // Kiểm tra trùng cùng (id, type) trong các dòng khác
        const rows = $tbody.querySelectorAll('tr');
        for (const r of rows) {
            if (r === tr) continue;
            const pid  = parseInt(r.getAttribute('data-product-id'), 10) || 0;
            const ptyp = r.getAttribute('data-item-type') || '';
            if (pid === id && ptyp === type) {
                alert('Hàng hóa "' + text + '" đã có trong danh sách.');
                hideProductDropdown();
                return;
            }
        }
        tr.setAttribute('data-product-id', id);
        tr.setAttribute('data-item-type', type);
        activeProductInput.value = text;
        hideProductDropdown();

        // Cập nhật warehouse cell theo type ngay (UX nhanh hơn)
        const $wh = tr.querySelector('.cell-warehouse');
        if ($wh) $wh.textContent = (type === 'material') ? MATERIAL_WAREHOUSE : DEFAULT_WAREHOUSE;

        // Gọi server lấy weight_kg + selling_price + unit mặc định
        postForm('get_item_defaults', { id: id, type: type }).then(res => {
            if (!res || !res.success) return;
            const d = res.data || {};
            const w = Number(d.weight_kg) || 0;
            const p = Number(d.unit_price) || 0;
            const $weightInp = tr.querySelector('.weight-input');
            if ($weightInp) $weightInp.value = w;
            tr.setAttribute('data-unit-price', String(p));
            const $priceInput = tr.querySelector('.price-input');
            if ($priceInput) $priceInput.value = String(p || '');
            if ($wh && d.warehouse) $wh.textContent = d.warehouse;
            const $unitInp = tr.querySelector('.unit-input');
            if ($unitInp) {
                $unitInp.value = (d.unit != null && d.unit !== '') ? d.unit : DEFAULT_UNIT;
            }
            recalcRow(tr);
            recalcTotals();
        });

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
        } else if (e.key === 'Enter' || e.key === 'Tab') {
            // Enter hoặc Tab đều chọn item đang active (hoặc item đầu nếu chưa di chuyển).
            const idx = prodActiveIdx >= 0 ? prodActiveIdx : 0;
            if (items[idx]) {
                e.preventDefault();
                selectProductItem(items[idx]);
            }
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
    // Trả về items hỗn hợp gồm cả product (TP) và material (NVL).
    // Mỗi item gắn tr_ref + type để xử lý kết quả từ server.
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
            const id   = parseInt(tr.getAttribute('data-product-id'), 10) || 0;
            const type = tr.getAttribute('data-item-type') || 'product';
            const qty  = parseFloat(tr.querySelector('.quantity').value) || 0;
            if (id <= 0 || qty <= 0) return;
            const $priceInput = tr.querySelector('.price-input');
            const price = $priceInput ? parseNumberFromText($priceInput.value) : parseFloat(tr.getAttribute('data-unit-price')) || 0;
            const totalAmount = qty * price;
            items.push({
                tr_ref:        tr,
                id:            id,
                type:          type === 'material' ? 'material' : 'product',
                quantity:      qty,
                unit_price:    price,
                total_amount:  totalAmount,
                interpretation: interp,
            });
        });
        return { items };
    }

    /** Bóc số từ chuỗi đã format ("197,000,000 đ" → 197000000). */
    function parseNumberFromText(s) {
        if (s == null) return 0;
        const digits = String(s).replace(/[^0-9.-]/g, '');
        return parseFloat(digits) || 0;
    }

    function flashActive(btn) {
        [$btnRec, $btnEdit].forEach(b => b && b.classList.remove('active'));
        if (btn) btn.classList.add('active');
    }

    $btnRec.addEventListener('click', () => {
        if (typeof editing !== 'undefined' && editing && editing.group_key) {
            // Đang sửa batch — đã ẩn nút, nhưng vẫn chặn an toàn.
            return;
        }
        if (!selectedCustomer || !selectedCustomer.id) {
            alert('Hãy chọn khách hàng từ danh sách trước.');
            $cust.focus();
            return;
        }
        const { items: collected } = collectItems();
        if (!collected.length) {
            alert('Chưa có hàng hóa nào hợp lệ để ghi.');
            return;
        }
        // Reset trạng thái cảnh báo cũ
        $tbody.querySelectorAll('tr').forEach(tr => {
            tr.classList.remove('row-shortage');
            tr.removeAttribute('title');
        });

        // Ghi thẳng, không hỏi xác nhận OK — phản hồi bằng hiệu ứng icon bay vào "Lịch sử"
        // + nhãn "Lịch sử" chớp sáng khi ghi thành công (appFlyToHistory bên dưới).
        flashActive($btnRec);

        const payloadItems = collected.map(it => ({
            id:             it.id,
            type:           it.type,
            quantity:       it.quantity,
            unit_price:     it.unit_price,
            total_amount:   it.total_amount,
            interpretation: it.interpretation,
        }));

        // Tổng từ UI để ghi sales_warehouse_export_invoices.
        const totalQty    = collected.reduce((s, it) => s + (Number(it.quantity) || 0), 0);
        const totalWeight = readTotalWeight();
        const totalValue  = parseNumberFromText($totalValue  ? $totalValue.textContent  : '0');
        const jeAmount    = parseNumberFromText($jeAmount    ? $jeAmount.value          : '0');

        postForm('record_sales_issue', {
            customer_id:    selectedCustomer.id,
            items:          JSON.stringify(payloadItems),
            created_at:     pickerToMysql(),
            total_quantity: totalQty,
            weight:         totalWeight,
            goods_value:    totalValue,
            je_debit:       $jeDebit  ? ($jeDebit.value  || '').trim() : '',
            je_credit:      $jeCredit ? ($jeCredit.value || '').trim() : '',
            je_amount:      jeAmount,
            je_entries:     (window.JE && window.JE.collectEntries) ? JSON.stringify(window.JE.collectEntries()) : '[]',
        }).then(res => {
            if (!res || !res.success) {
                alert(res && res.message ? res.message : 'Có lỗi xảy ra.');
                return;
            }
            // recorded/shortages dùng cặp (id, type) làm khoá
            const keyOf = (id, type) => (type === 'material' ? 'm:' : 'p:') + id;
            const shortageMap = new Map();
            (res.shortages || []).forEach(s => shortageMap.set(keyOf(Number(s.id), s.type || 'product'), s));

            // Highlight row thiếu hàng để user biết dòng nào cần bổ sung tồn
            collected.forEach(it => {
                const tr = it.tr_ref;
                const k = keyOf(it.id, it.type);
                if (shortageMap.has(k)) {
                    const s = shortageMap.get(k);
                    tr.classList.add('row-shortage');
                    tr.title = 'Thiếu tồn: ' + s.available + ' < cần ' + s.requested;
                }
            });

            renderHistory(res.history || []);

            if (res.shortage_batch) {
                // Cả batch không được ghi do có hàng hóa thiếu tồn.
                // Lịch sử chỉ lưu stock_exports với marker; UI giữ nguyên các dòng để user sửa.
                ensureEmptyRow();
                const shortCount = (res.shortages || []).length;
                showAutoToast('Chưa ghi dữ liệu do có ' + shortCount + ' hàng hóa đang thiếu tồn. '
                    + 'Lịch sử đã lưu lại để bổ sung sau (xem dòng được làm nổi bật trong bảng Lịch sử).');
                return;
            }

            // Không thiếu tồn → đã ghi đầy đủ. Xoá các dòng UI vì batch đã thành công.
            const recordedSet = new Set((res.recorded || []).map(r => keyOf(Number(r.id), r.type || 'product')));
            collected.forEach(it => {
                if (recordedSet.has(keyOf(it.id, it.type))) {
                    it.tr_ref.remove();
                }
            });
            ensureEmptyRow();

            if (window.appFlyToHistory) window.appFlyToHistory($btnRec);
            // TASK 1: ghi thành công (không thiếu tồn) → reload lấy ngày giờ ghi mới.
            setTimeout(() => window.location.reload(), 950);
        });
    });

    // ---------- Print: PHIẾU XUẤT KHO KIÊM VẬN CHUYỂN NỘI BỘ ----------
    const $btnPrint    = document.getElementById('btn-print');
    const $printModal  = document.getElementById('print-modal');
    const $printSheet  = document.getElementById('print-sheet');
    const $printClose  = document.getElementById('print-modal-close');
    const $printDo     = document.getElementById('print-modal-do');
    const $printOverlay = document.getElementById('print-modal-overlay');

    // Thông tin cố định in phiếu (sửa trực tiếp trên modal → lưu DB dùng dài lâu).
    const PRINT_SETTINGS = Object.assign({
        company_name:    'CÔNG TY TNHH VUA AN TOÀN',
        company_address: '1/13Z Ấp Tiền Lân, Xã Bà Điểm, Thành Phố Hồ Chí Minh, Việt Nam.',
        signer_preparer: 'TRƯƠNG NGỌC SÁU',
    }, window.PRINT_SETTINGS || {});

    // 1 trường sửa-tại-chỗ: text + cây bút (hiện khi hover). Empty → gạch chân để bấm.
    function prEditable(key) {
        const v = String(PRINT_SETTINGS[key] == null ? '' : PRINT_SETTINGS[key]).trim();
        return '<span class="pr-editable' + (v ? '' : ' is-empty') + '" data-setting="' + key + '">' +
                   '<span class="pr-ed-text">' + escapeHtml(v) + '</span>' +
                   '<i class="fa-solid fa-pen pr-edit-pen" title="Sửa (lưu dùng dài lâu)"></i>' +
               '</span>';
    }

    function prEnterEdit($wrap) {
        if (!$wrap || $wrap.classList.contains('editing')) return;
        const $txt = $wrap.querySelector('.pr-ed-text');
        $wrap.classList.add('editing');
        $wrap.classList.remove('is-empty');
        $txt.dataset.orig = $txt.textContent;
        $txt.setAttribute('contenteditable', 'true');
        $txt.focus();
        const range = document.createRange();
        range.selectNodeContents($txt);
        const sel = window.getSelection();
        sel.removeAllRanges();
        sel.addRange(range);
    }

    function prCommitEdit($wrap) {
        if (!$wrap || !$wrap.classList.contains('editing')) return;
        const $txt = $wrap.querySelector('.pr-ed-text');
        $wrap.classList.remove('editing');
        $txt.removeAttribute('contenteditable');
        const key = $wrap.getAttribute('data-setting');
        const val = ($txt.textContent || '').replace(/\s+/g, ' ').trim();
        $txt.textContent = val;
        if (!val) $wrap.classList.add('is-empty');
        if (key && val !== ($txt.dataset.orig || '')) {
            PRINT_SETTINGS[key] = val;
            postForm('save_print_setting', { key: key, value: val }).then(res => {
                if (!res || !res.success) console.warn('Lưu thông tin in thất bại:', res && res.message);
            }).catch(() => console.warn('Lỗi mạng khi lưu thông tin in.'));
        }
    }

    if ($printSheet) {
        $printSheet.addEventListener('click', e => {
            const $wrap = e.target.closest('.pr-editable');
            if ($wrap && !$wrap.classList.contains('editing')) { e.preventDefault(); prEnterEdit($wrap); }
        });
        $printSheet.addEventListener('blur', e => {
            if (e.target.classList && e.target.classList.contains('pr-ed-text')) {
                prCommitEdit(e.target.closest('.pr-editable'));
            }
        }, true);
        $printSheet.addEventListener('keydown', e => {
            if (!e.target.classList || !e.target.classList.contains('pr-ed-text')) return;
            if (e.key === 'Enter') { e.preventDefault(); e.target.blur(); }
            else if (e.key === 'Escape') {
                const $wrap = e.target.closest('.pr-editable');
                e.target.textContent = e.target.dataset.orig || '';
                $wrap.classList.remove('editing');
                e.target.removeAttribute('contenteditable');
                if (!e.target.textContent) $wrap.classList.add('is-empty');
            }
        });
    }

    // Đọc số → chữ tiếng Việt (đủ cho số tiền VND, không vượt hàng tỷ).
    function numberToVnWords(num) {
        num = Math.round(Number(num) || 0);
        if (num === 0) return 'Không đồng';
        const ones = ['không','một','hai','ba','bốn','năm','sáu','bảy','tám','chín'];
        function readTriple(n, full) {
            const tr = String(n).padStart(3, '0');
            const h = +tr[0], t = +tr[1], u = +tr[2];
            const parts = [];
            if (h !== 0 || full) {
                parts.push(ones[h] + ' trăm');
            }
            if (t === 0) {
                if (u !== 0) {
                    if (h !== 0 || full) parts.push('linh');
                    parts.push(ones[u]);
                }
            } else if (t === 1) {
                parts.push('mười');
                if (u === 1) parts.push('một');
                else if (u === 5) parts.push('lăm');
                else if (u !== 0) parts.push(ones[u]);
            } else {
                parts.push(ones[t] + ' mươi');
                if (u === 1) parts.push('mốt');
                else if (u === 5) parts.push('lăm');
                else if (u !== 0) parts.push(ones[u]);
            }
            return parts.join(' ');
        }
        const groups = [];
        let n = num;
        while (n > 0) { groups.unshift(n % 1000); n = Math.floor(n / 1000); }
        const scales = ['', 'nghìn', 'triệu', 'tỷ'];
        const out = [];
        const len = groups.length;
        for (let i = 0; i < len; i++) {
            const g = groups[i];
            const scaleIdx = len - 1 - i;
            if (g === 0) continue;
            const isFull = (i !== 0);
            out.push(readTriple(g, isFull) + (scales[scaleIdx] ? ' ' + scales[scaleIdx] : ''));
        }
        let s = out.join(' ').replace(/\s+/g, ' ').trim();
        s = s.charAt(0).toUpperCase() + s.slice(1);
        return s + ' đồng';
    }

    function collectAllPrintRows() {
        const rows = [];
        $tbody.querySelectorAll('tr').forEach(tr => {
            const pid  = parseInt(tr.getAttribute('data-product-id'), 10) || 0;
            const type = tr.getAttribute('data-item-type') || 'product';
            const name = (tr.querySelector('.name_product').value || '').trim();
            const qty  = parseFloat(tr.querySelector('.quantity').value) || 0;
            const price = parseFloat(tr.getAttribute('data-unit-price')) || 0;
            const $unitInp = tr.querySelector('.unit-input');
            const unit  = $unitInp ? (($unitInp.value || '').trim() || DEFAULT_UNIT) : DEFAULT_UNIT;
            if (pid <= 0 || qty <= 0) return;
            rows.push({
                code:  (type === 'material' ? 'NVL-' : 'TP-') + pid,
                name:  name,
                unit:  unit,
                qty:   qty,
                price: price,
                total: qty * price,
            });
        });
        return rows;
    }

    function buildPrintHtml() {
        const cust = selectedCustomer ? selectedCustomer.name : '';
        const addr = selectedCustomer ? (selectedCustomer.address || '') : '';
        const today = new Date();
        const dd = pad2(today.getDate()), mm = pad2(today.getMonth() + 1), yyyy = today.getFullYear();
        const rows = collectAllPrintRows();
        let sumTotal = 0;
        const trsHtml = rows.map((r, i) => {
            sumTotal += r.total;
            return `
                <tr>
                    <td class="center">${i + 1}</td>
                    <td class="center">${escapeHtml(r.code)}</td>
                    <td class="pname">${escapeHtml(r.name)}</td>
                    <td class="center">${escapeHtml(r.unit)}</td>
                    <td class="num">${fmtNumber(r.qty)}</td>
                    <td class="num">${fmtNumber(r.price)}</td>
                    <td class="num">${fmtNumber(r.total)}</td>
                </tr>
            `;
        }).join('');

        return `
            <div class="pr-header">
                <table>
                    <tr><td class="label">Tên người xuất hàng:</td><td>${prEditable('company_name')}</td></tr>
                    <tr><td class="label">Theo lệnh điều động số:</td><td></td></tr>
                    <tr><td class="label">Địa chỉ kho xuất hàng:</td><td>${prEditable('company_address')}</td></tr>
                    <tr><td class="label">Tên người vận chuyển:</td><td></td></tr>
                    <tr><td class="label">Phương tiện vận chuyển:</td><td></td></tr>
                    <tr><td class="label">Mã số thuế người xuất hàng:</td><td>0313334177</td></tr>
                </table>
            </div>

            <h2 class="pr-title">PHIẾU XUẤT KHO KIÊM VẬN CHUYỂN NỘI BỘ</h2>
            <div class="pr-date">Ngày ${dd} tháng ${mm} năm ${yyyy}</div>

            <div class="pr-receiver">
                <div class="row"><span class="lab">Tên người nhận hàng:</span>${escapeHtml(cust)}</div>
                <div class="row"><span class="lab">Địa chỉ:</span>${escapeHtml(addr)}</div>
                <div class="row"><span class="lab">Mã số thuế:</span>0313334177</div>
            </div>

            <table class="pr-table">
                <thead>
                    <tr>
                        <th style="width:5%">STT</th>
                        <th style="width:10%">Mã số</th>
                        <th class="pname">Tên nhãn hiệu, quy cách, phẩm chất vật tư (sản phẩm, hàng hóa)</th>
                        <th style="width:8%">Đơn vị tính</th>
                        <th style="width:9%">Số lượng xuất</th>
                        <th style="width:11%">Đơn giá</th>
                        <th style="width:13%">Thành tiền</th>
                    </tr>
                </thead>
                <tbody>
                    ${trsHtml || '<tr><td colspan="7" class="center"><em>(chưa có hàng hóa)</em></td></tr>'}
                </tbody>
            </table>

            <div class="pr-total-row">Tổng số tiền (Bằng số): ${fmtMoney(sumTotal)}</div>
            <div class="pr-words">Số tiền bằng chữ: ${escapeHtml(numberToVnWords(sumTotal))}</div>

            <div class="pr-signature">
                <div class="signer">
                    <div class="role">Người lập phiếu</div>
                    <div class="sub">(Ký tên)</div>
                    <div class="name">${prEditable('signer_preparer')}</div>
                </div>
            </div>
        `;
    }

    function openPrintModal() {
        $printSheet.innerHTML = buildPrintHtml();
        $printModal.style.display = 'flex';
    }
    function closePrintModal() {
        $printModal.style.display = 'none';
    }

    if ($btnPrint) {
        $btnPrint.addEventListener('click', () => {
            openPrintModal();
        });
    }
    if ($printClose)   $printClose.addEventListener('click', closePrintModal);
    if ($printOverlay) $printOverlay.addEventListener('click', closePrintModal);
    if ($printDo)      $printDo.addEventListener('click', () => { window.print(); });

    // Share Zalo: chụp #print-sheet → copy PNG vào clipboard → mở Zalo PC qua zalo://
    // User chỉ cần Ctrl+V trong cửa sổ chat Zalo để dán ảnh phiếu xuất.
    const $printShareZalo = document.getElementById('print-modal-share-zalo');
    if ($printShareZalo) {
        $printShareZalo.addEventListener('click', async () => {
            const $sheet = document.getElementById('print-sheet');
            if (!$sheet) return;
            if (typeof window.html2canvas !== 'function') {
                alert('Không nạp được html2canvas. Kiểm tra mạng rồi thử lại.');
                return;
            }
            if (!navigator.clipboard || typeof window.ClipboardItem !== 'function') {
                alert('Trình duyệt không hỗ trợ copy ảnh vào clipboard.');
                return;
            }

            const origLabel = $printShareZalo.textContent;
            $printShareZalo.textContent = 'Đang xử lý...';
            $printShareZalo.disabled = true;
            try {
                // 1) Render print-sheet thành canvas (scale 2 cho rõ nét khi paste).
                const canvas = await window.html2canvas($sheet, {
                    backgroundColor: '#ffffff',
                    scale: 2,
                    useCORS: true,
                    logging: false,
                });

                // 2) Canvas → Blob PNG → ClipboardItem.
                const blob = await new Promise((resolve, reject) => {
                    canvas.toBlob(b => b ? resolve(b) : reject(new Error('toBlob trả về null')), 'image/png');
                });
                await navigator.clipboard.write([
                    new ClipboardItem({ 'image/png': blob })
                ]);

                // 3) Mở Zalo PC bằng URI scheme zalo:// — KHÔNG fallback sang web.
                // Sử dụng iframe ẩn để tránh điều hướng trang hiện tại.
                const launcher = document.createElement('iframe');
                launcher.style.display = 'none';
                launcher.src = 'zalo://';
                document.body.appendChild(launcher);
                setTimeout(() => launcher.remove(), 1500);

                alert('Đã copy ảnh phiếu xuất vào clipboard.\nMở cửa sổ chat trong Zalo và bấm Ctrl+V để dán.');
            } catch (err) {
                console.error('Share Zalo error:', err);
                alert('Không thể chia sẻ: ' + (err && err.message ? err.message : err));
            } finally {
                $printShareZalo.textContent = origLabel;
                $printShareZalo.disabled = false;
            }
        });
    }

    // ---------- History (paginated) ----------
    function renderHistory(batches) {
        INITIAL.history = batches || [];
        historyPage = 1;
        renderHistoryPage();
    }

    // Màu hợp lệ dạng #rrggbb (chống injection); rỗng/sai → đen.
    function sanitizeColor(c) {
        return /^#[0-9a-fA-F]{6}$/.test(String(c || '')) ? String(c).toLowerCase() : '#000000';
    }

    // Diễn giải lịch sử: tô màu tên viết tắt KH + số tiền theo "Màu nhận diện thứ cấp".
    // Dòng thiếu tồn giữ nguyên text gốc (không có khách hàng/số tiền để tô).
    function renderSummaryHtml(b) {
        if (b.is_shortage || !b.customer_short || !b.total_fmt) {
            return escapeHtml(b.summary);
        }
        const color = sanitizeColor(b.customer_color);
        const sn  = `<span style="color:${color};font-weight:500">${escapeHtml(b.customer_short)}</span>`;
        const amt = `<span style="color:${color};font-weight:500">${escapeHtml(b.total_fmt)}</span>`;
        return 'Bán hàng ' + sn + ' giá trị ' + amt;
    }

    // Ngày của batch dạng 'YYYY-MM-DD'. Ưu tiên created_at/group_key
    // ('YYYY-MM-DD HH:MM:SS'); fallback parse từ date_display
    // ('HH:ii:ss dd/mm/yyyy' hoặc 'dd/mm/yyyy'). So sánh chuỗi 'YYYY-MM-DD'
    // đúng thứ tự nên dùng trực tiếp cho khoảng ngày.
    function batchDateYMD(b) {
        const src = (b && b.created_at) ? b.created_at : (b && b.group_key ? b.group_key : '');
        const iso = /^(\d{4})-(\d{2})-(\d{2})/.exec(String(src));
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
                    String(it.name || '').toLowerCase().indexOf(kw) !== -1);
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
            $histBody.innerHTML = '<tr class="history-empty"><td colspan="3">' + msg + '</td></tr>';
            if ($histPager) $histPager.innerHTML = '';
            return;
        }
        const start = (historyPage - 1) * pageSize;
        const slice = data.slice(start, start + pageSize);
        $histBody.innerHTML = slice.map(b => {
            const shortClass = b.is_shortage ? ' history-row-shortage' : '';
            return `
            <tr class="${shortClass}" data-group-key="${escapeHtml(b.group_key)}" data-customer-id="${b.customer_id}" data-shortage="${b.is_shortage ? '1' : '0'}">
                <td class="${b.date_color ? 'hist-date-' + b.date_color : ''}">${escapeHtml(b.date_display)}</td>
                <td title="${escapeHtml(b.summary)}">${renderSummaryHtml(b)}</td>
                <td class="history-actions">
                    <a href="#" class="edit-list-issue">Sửa</a>
                    <span class="sep">|</span>
                    <a href="#" class="delete-list-issue">Xóa</a>
                </td>
            </tr>
            `;
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

    // ---------- Edit / Delete batch ----------
    // editing.group_key + editing.customer_id: nếu set → đang sửa batch tương ứng.
    let editing = { group_key: null, customer_id: 0 };

    function enterEditMode(batch) {
        editing.group_key   = batch.group_key;
        editing.customer_id = batch.customer && batch.customer.id ? batch.customer.id : 0;
        if (window.JE && window.JE.loadTemplatesAsBlocks) window.JE.loadTemplatesAsBlocks();

        // Khi sửa: nạp "ngày giờ ghi" theo created_at của batch trong DB (group_key = 'Y-m-d H:i:s').
        if ($dateTime) {
            const lv = mysqlToLocalValue(batch.group_key);
            if (lv) $dateTime.value = lv;
            if ($dateTime._dtpRefresh) $dateTime._dtpRefresh();
        }

        // Đẩy thông tin khách hàng vào form
        if (batch.customer) {
            selectedCustomer = {
                id:         batch.customer.id,
                name:       batch.customer.name,
                short_name: batch.customer.short_name,
                address:    batch.customer.address,
                receiver:   batch.customer.receiver,
                phone:      batch.customer.phone,
            };
            $cust.value     = selectedCustomer.name;
            $address.value  = selectedCustomer.address || '';
            $receiver.value = buildReceiverDisplay(selectedCustomer);
            loadBranchReminders(selectedCustomer.id);
        }

        // Render từng dòng item vào table
        $tbody.innerHTML = '';
        (batch.items || []).forEach(it => {
            const tr = makeRow({
                product_id:    it.id,
                item_type:     it.type,
                product_name:  it.name,
                quantity:      it.quantity,
                weight_kg:     it.weight_kg,
                unit_price:    it.unit_price,
                unit:          it.unit,
            });
            tr.setAttribute('data-export-id', String(it.export_id || 0));
            const $wh = tr.querySelector('.cell-warehouse');
            if ($wh && it.warehouse) $wh.textContent = it.warehouse;
            $tbody.appendChild(tr);
        });
        ensureEmptyRow();
        recalcTotals();

        // Banner + nút Sửa nổi bật
        $banner.style.display = 'flex';
        $bannerLabel.textContent = batch.group_key;
        // Nút "Sửa" chỉ xuất hiện khi đang sửa batch từ lịch sử.
        $btnEdit.style.display = '';
        flashActive($btnEdit);
        $btnRec.style.display = 'none';
        document.querySelector('.content').scrollIntoView({ behavior: 'smooth' });
    }

    function exitEditMode() {
        editing = { group_key: null, customer_id: 0 };
        $banner.style.display = 'none';
        $btnRec.style.display = '';
        // Thoát chế độ sửa → ẩn nút "Sửa" lại.
        $btnEdit.style.display = 'none';
        if (window.JE && window.JE.reset) window.JE.reset();
        [$btnRec, $btnEdit].forEach(b => b && b.classList.remove('active'));
        // Reset form về trạng thái rỗng
        $tbody.innerHTML = '';
        ensureEmptyRow();
        selectedCustomer = null;
        $cust.value     = '';
        $address.value  = '';
        $receiver.value = '';
        loadBranchReminders(null);
        if ($dateTime) { $dateTime.value = nowLocalValue(); if ($dateTime._dtpRefresh) $dateTime._dtpRefresh(); }
        recalcTotals();
    }

    function collectItemsForEdit() {
        const items = [];
        const dateStr = (function () {
            const d = new Date();
            return pad2(d.getDate()) + '/' + pad2(d.getMonth() + 1) + '/' + d.getFullYear();
        })();
        const sn = selectedCustomer && selectedCustomer.short_name
            ? selectedCustomer.short_name : (selectedCustomer ? selectedCustomer.name : '');
        const interp = 'Bán hàng ' + (sn || 'KH') + ' ngày ' + dateStr;
        $tbody.querySelectorAll('tr').forEach(tr => {
            const id    = parseInt(tr.getAttribute('data-product-id'), 10) || 0;
            const type  = tr.getAttribute('data-item-type') || 'product';
            const eid   = parseInt(tr.getAttribute('data-export-id'), 10) || 0;
            const qty   = parseFloat(tr.querySelector('.quantity').value) || 0;
            if (id <= 0 || qty <= 0) return;
            const $priceInput = tr.querySelector('.price-input');
            const price = $priceInput ? parseNumberFromText($priceInput.value) : parseFloat(tr.getAttribute('data-unit-price')) || 0;
            items.push({
                tr_ref:        tr,
                export_id:     eid,
                id:            id,
                type:          type === 'material' ? 'material' : 'product',
                quantity:      qty,
                unit_price:    price,
                total_amount:  qty * price,
                interpretation: interp,
            });
        });
        return items;
    }

    $histBody.addEventListener('click', (e) => {
        const editLink = e.target.closest('.edit-list-issue');
        const delLink  = e.target.closest('.delete-list-issue');
        const tr = e.target.closest('tr');
        if (!tr) return;
        // Tra cứu batch theo data-group-key (khóa duy nhất) thay vì index —
        // an toàn khi đang lọc/phân trang.
        const gk = tr.getAttribute('data-group-key') || '';
        const batch = (INITIAL.history || []).find(b => b.group_key === gk);
        if (!batch) return;
        const cid = (batch.customer && batch.customer.id)
            ? batch.customer.id
            : (parseInt(tr.getAttribute('data-customer-id'), 10) || 0);
        if (!gk || !cid) return;

        if (editLink) {
            e.preventDefault();
            postForm('get_sales_issue_batch', { group_key: gk, customer_id: cid }).then(res => {
                if (!res || !res.success) {
                    alert((res && res.message) ? res.message : 'Không lấy được dữ liệu.');
                    return;
                }
                enterEditMode(res.data || {});
            });
        }
        if (delLink) {
            e.preventDefault();
            if (!confirm('Xóa phiếu xuất kho bán hàng này? Tồn kho sẽ được hoàn lại.')) return;
            postForm('delete_sales_issue_batch', { group_key: gk, customer_id: cid }).then(res => {
                if (!res || !res.success) {
                    alert((res && res.message) ? res.message : 'Xóa không thành công.');
                    return;
                }
                renderHistory(res.history || []);
                if (editing.group_key === gk && editing.customer_id === cid) exitEditMode();
                alert('Đã xóa phiếu.');
            });
        }
    });

    $btnEdit.addEventListener('click', () => {
        if (!editing.group_key) {
            alert('Chọn "Sửa" ở lịch sử trước.');
            return;
        }
        if (!selectedCustomer || !selectedCustomer.id) {
            alert('Thiếu khách hàng.');
            return;
        }
        const items = collectItemsForEdit();
        if (!items.length) {
            alert('Phiếu phải có ít nhất 1 hàng hóa.');
            return;
        }
        if (!confirm('Lưu các thay đổi cho phiếu này?')) return;

        const totalQty    = items.reduce((s, it) => s + (Number(it.quantity) || 0), 0);
        const totalWeight = readTotalWeight();
        const totalValue  = parseNumberFromText($totalValue  ? $totalValue.textContent  : '0');
        const jeAmount    = parseNumberFromText($jeAmount    ? $jeAmount.value          : '0');

        flashActive($btnEdit);

        postForm('edit_sales_issue_batch', {
            group_key:      editing.group_key,
            customer_id:    editing.customer_id,
            created_at:     pickerToMysql(),
            items:          JSON.stringify(items.map(it => ({
                export_id:      it.export_id,
                id:             it.id,
                type:           it.type,
                quantity:       it.quantity,
                unit_price:     it.unit_price,
                total_amount:   it.total_amount,
                interpretation: it.interpretation,
            }))),
            total_quantity: totalQty,
            weight:         totalWeight,
            goods_value:    totalValue,
            je_debit:       $jeDebit  ? ($jeDebit.value  || '').trim() : '',
            je_credit:      $jeCredit ? ($jeCredit.value || '').trim() : '',
            je_amount:      jeAmount,
            je_entries:     (window.JE && window.JE.collectEntries) ? JSON.stringify(window.JE.collectEntries()) : '[]',
        }).then(res => {
            if (!res || !res.success) {
                alert((res && res.message) ? res.message : 'Sửa không thành công.');
                return;
            }
            const shorts = res.shortages || [];
            if (shorts.length) {
                // Highlight các dòng thiếu tồn để user biết cần bổ sung tồn rồi sửa lại
                const keyOf = (id, type) => (type === 'material' ? 'm:' : 'p:') + id;
                const map = new Map(shorts.map(s => [keyOf(Number(s.id), s.type || 'product'), s]));
                items.forEach(it => {
                    const s = map.get(keyOf(it.id, it.type));
                    if (s) {
                        it.tr_ref.classList.add('row-shortage');
                        it.tr_ref.title = 'Thiếu tồn: ' + (s.available || '?') + ' < cần ' + s.requested;
                    }
                });
            }
            renderHistory(res.history || []);
            if (res.shortage_batch) {
                // Batch vẫn ở trạng thái thiếu tồn — chỉ lưu lịch sử stock_exports với marker;
                // KHÔNG ghi invoice / transactions. Giữ form mở để user tiếp tục sửa.
                showAutoToast('Chưa ghi dữ liệu do có ' + shorts.length + ' hàng hóa đang thiếu tồn. '
                    + 'Lịch sử đã lưu lại để bổ sung sau (xem dòng được làm nổi bật trong bảng Lịch sử).');
                return;
            }
            if (window.appFlyToHistory) window.appFlyToHistory($btnEdit);
            exitEditMode();
        });
    });

    if ($btnCancelEdit) {
        $btnCancelEdit.addEventListener('click', (e) => {
            e.preventDefault();
            exitEditMode();
        });
    }

    // ---------- TASK 3: con mắt + modal giải thích con số .je-amount ----------
    const $jeExplainModal = (function () {
        const ov = document.createElement('div');
        ov.className = 'je-explain-modal';
        ov.style.display = 'none';
        ov.innerHTML =
            '<div class="je-explain-overlay"></div>' +
            '<div class="je-explain-content">' +
                '<div class="je-explain-head">' +
                    '<h3 class="je-explain-title">Giải thích con số</h3>' +
                    '<button type="button" class="je-explain-close" title="Đóng">&times;</button>' +
                '</div>' +
                '<div class="je-explain-body"></div>' +
            '</div>';
        document.body.appendChild(ov);
        const hide = () => { ov.style.display = 'none'; };
        ov.querySelector('.je-explain-overlay').addEventListener('click', hide);
        ov.querySelector('.je-explain-close').addEventListener('click', hide);
        document.addEventListener('keydown', (e) => { if (e.key === 'Escape') hide(); });
        return ov;
    })();

    function openJeExplain(title, html) {
        $jeExplainModal.querySelector('.je-explain-title').textContent = title;
        $jeExplainModal.querySelector('.je-explain-body').innerHTML = html;
        $jeExplainModal.style.display = 'flex';
    }

    // Hover vào 1 ô .je-amount → chèn nút con mắt (CSS lo ẩn/hiện theo hover).
    document.addEventListener('mouseover', (e) => {
        const amt = e.target.closest('.je-amount');
        if (!amt) return;
        const row = amt.closest('.je-row-amount') || amt.parentElement;
        if (!row || row.querySelector('.je-amount-eye')) return;
        row.classList.add('je-amount-has-eye');
        const eye = document.createElement('button');
        eye.type = 'button';
        eye.className = 'je-amount-eye';
        eye.title = 'Giải thích vì sao có con số này';
        eye.innerHTML = '<i class="fa-solid fa-eye"></i>';
        eye.addEventListener('click', (ev) => { ev.preventDefault(); explainAmount(amt); });
        row.appendChild(eye);
    });

    function currentRowsForExplain() {
        const rows = [];
        $tbody.querySelectorAll('tr').forEach(tr => {
            const id   = parseInt(tr.getAttribute('data-product-id'), 10) || 0;
            const type = tr.getAttribute('data-item-type') || 'product';
            const name = (tr.querySelector('.name_product').value || '').trim();
            const qty  = parseFloat(tr.querySelector('.quantity').value) || 0;
            const $p   = tr.querySelector('.price-input');
            const price = $p ? parseNumberFromText($p.value) : parseFloat(tr.getAttribute('data-unit-price')) || 0;
            if (id <= 0 || qty <= 0) return;
            rows.push({ id: id, type: type === 'material' ? 'material' : 'product', name: name, qty: qty, price: price });
        });
        return rows;
    }

    function explainAmount(amtInput) {
        const block = amtInput.closest('.je-entry') || amtInput.closest('.je-block');
        const debitEl = block ? block.querySelector('.je-debit') : null;
        const debit = debitEl ? (debitEl.value || '').trim() : ($jeDebit ? ($jeDebit.value || '').trim() : '');
        const rows = currentRowsForExplain();
        if (debit === COGS_DEBIT) explainCogs(rows);
        else explainRevenue(rows);
    }

    function explainRevenue(rows) {
        let sum = 0;
        const trs = rows.map(r => {
            const line = r.qty * r.price; sum += line;
            return '<tr><td>' + escapeHtml(r.name) + '</td>' +
                   '<td class="num">' + fmtNumber(r.qty) + '</td>' +
                   '<td class="num">' + fmtMoney(r.price) + '</td>' +
                   '<td class="num">' + fmtMoney(line) + '</td></tr>';
        }).join('') || '<tr><td colspan="4" class="center"><em>(chưa có hàng hóa)</em></td></tr>';
        const html =
            '<p>Giá trị bút toán (doanh thu) = <b>tổng (Số lượng × Đơn giá)</b> của các dòng hàng hóa đang bán:</p>' +
            '<table class="je-explain-table"><thead><tr><th>Mặt hàng</th><th>SL</th><th>Đơn giá</th><th>Thành tiền</th></tr></thead>' +
            '<tbody>' + trs + '</tbody>' +
            '<tfoot><tr><td colspan="3" class="num"><b>Tổng cộng</b></td><td class="num"><b>' + fmtMoney(sum) + '</b></td></tr></tfoot></table>' +
            '<p class="je-explain-note">Số tiền được làm tròn 0 chữ số thập phân (đồng).</p>';
        openJeExplain('Giải thích: Giá trị hàng hóa (doanh thu)', html);
    }

    function explainCogs(rows) {
        const items = rows.map(r => ({ id: r.id, type: r.type, qty: r.qty }));
        openJeExplain('Giải thích: Giá vốn hàng bán (632)', '<p>Đang tính giá vốn...</p>');
        postForm('explain_sales_cogs', { items: JSON.stringify(items) }).then(res => {
            const $body = $jeExplainModal.querySelector('.je-explain-body');
            if (!res || !res.success || !res.data) { $body.innerHTML = '<p>Không tính được giá vốn.</p>'; return; }
            const d = res.data;
            const nameByKey = {};
            rows.forEach(r => { nameByKey[r.type + ':' + r.id] = r.name; });
            const trs = (d.rows || []).map(it => {
                const nm = nameByKey[it.type + ':' + it.id] || ('#' + it.id);
                const sub = it.type === 'product'
                    ? '(NVL ' + fmtMoney(it.material_cost) + ' + CP SX ' + fmtMoney(it.overhead) + ')'
                    : '(giá vốn tồn kho)';
                const badge = it.type === 'material' ? 'NVL' : 'TP';
                return '<tr><td>' + escapeHtml(nm) + ' <span class="je-explain-badge">' + badge + '</span></td>' +
                       '<td class="num">' + fmtNumber(it.qty) + '</td>' +
                       '<td class="num">' + fmtMoney(it.unit_cost) + '<br><span class="je-explain-sub">' + sub + '</span></td>' +
                       '<td class="num">' + fmtMoney(it.line_cost) + '</td></tr>';
            }).join('') || '<tr><td colspan="4" class="center"><em>(chưa có hàng hóa)</em></td></tr>';
            $body.innerHTML =
                '<p>Giá vốn hàng bán (TK 632) = <b>tổng (Giá vốn đơn vị × Số lượng)</b> của từng mặt hàng xuất bán.</p>' +
                '<ul class="je-explain-rules">' +
                '<li><b>Thành phẩm (TP):</b> giá vốn NVL/đơn vị (theo định mức) + chi phí sản xuất ' + fmtMoney(d.rate) + '/đơn vị.</li>' +
                '<li><b>Nguyên vật liệu (NVL):</b> đơn giá vốn tồn kho hiện tại.</li>' +
                '</ul>' +
                '<table class="je-explain-table"><thead><tr><th>Mặt hàng</th><th>SL</th><th>Giá vốn đơn vị</th><th>Thành tiền</th></tr></thead>' +
                '<tbody>' + trs + '</tbody>' +
                '<tfoot><tr><td colspan="3" class="num"><b>Tổng giá vốn</b></td><td class="num"><b>' + fmtMoney(d.total) + '</b></td></tr></tfoot></table>' +
                '<p class="je-explain-note">Số tiền được làm tròn 0 chữ số thập phân (đồng).</p>';
        });
    }

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

    /**
     * Prefill từ phiếu soạn / branch_orders (Xác nhận xuất kho): đọc sessionStorage
     * 'wo_prefill_order' do order_management ghi trước khi redirect sang đây.
     */
    function prefillFromSession() {
        let raw = null;
        try { raw = sessionStorage.getItem('wo_prefill_order'); } catch (e) { raw = null; }
        if (!raw) return;
        try { sessionStorage.removeItem('wo_prefill_order'); } catch (e) {}
        let data;
        try { data = JSON.parse(raw); } catch (e) { return; }
        if (!data || !Array.isArray(data.items) || !data.items.length) return;

        // Khách hàng: lấy đầy đủ (địa chỉ/người nhận) theo id nếu có.
        if (data.customer_id) {
            postForm('get_customer_full', { customer_id: data.customer_id }).then(res => {
                if (res && res.success) {
                    selectedCustomer = res.data;
                    $cust.value = selectedCustomer.name;
                    $address.value = selectedCustomer.address || '';
                    $receiver.value = buildReceiverDisplay(selectedCustomer);
                    loadBranchReminders(selectedCustomer.id);
                } else if (data.customer_name) {
                    $cust.value = data.customer_name;
                }
            }).catch(() => { if (data.customer_name) $cust.value = data.customer_name; });
        } else if (data.customer_name) {
            $cust.value = data.customer_name;
        }

        // Hàng hóa: dựng từng dòng từ đơn (user xem lại rồi bấm "Ghi").
        $tbody.innerHTML = '';
        data.items.forEach(it => {
            const tr = makeRow({
                product_id:   it.product_id || '',
                item_type:    it.item_type || 'product',
                product_name: it.product_name || '',
                quantity:     it.quantity,
                unit:         it.unit,
                weight_kg:    it.weight_kg,
                unit_price:   it.unit_price
            });
            $tbody.appendChild(tr);
            recalcRow(tr);
        });
        ensureEmptyRow();
        recalcTotals();
    }

    // ---------- Init ----------
    function init() {
        if ($dateTime) { $dateTime.value = nowLocalValue(); if ($dateTime._dtpRefresh) $dateTime._dtpRefresh(); }
        setupHistoryFilter();
        renderHistory(INITIAL.history || []);
        ensureEmptyRow();
        prefillFromSession();
    }

    /* ---------------------------------------------------------------------------------
       NÚT "THÊM DÒNG" (mobile) — anh Sáu chốt 11/8/2026.
       Trên máy tính dòng mới tự sinh khi gõ xong dòng cuối (ensureEmptyRow), nhưng trên điện
       thoại bàn phím ảo che mất dòng cuối và dòng trống lại bị dọn ngay khi rời ô, nên phải có
       nút bấm rõ ràng.
       Luôn thêm MỘT dòng mới rồi đưa con trỏ vào ô tên hàng của dòng đó — đỡ một lần chạm.
       Nút không có trong mọi view dùng file này nên phải kiểm tồn tại trước khi gắn.
       --------------------------------------------------------------------------------- */
    var $btnAddRow = document.getElementById('btn-add-sale-row');
    if ($btnAddRow) {
        $btnAddRow.addEventListener('click', function () {
            var tr = makeRow();
            $tbody.appendChild(tr);
            recalcTotals();
            var o = tr.querySelector('.name_product');
            if (o) { o.focus(); tr.scrollIntoView({ behavior: 'smooth', block: 'center' }); }
        });
    }

    init();
})();
