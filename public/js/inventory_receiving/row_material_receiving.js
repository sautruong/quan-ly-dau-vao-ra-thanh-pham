(function () {
    'use strict';

    const CFG = window.INVENTORY_CONFIG || { baseUrl: '?mod=inventory_receiving&controllers=inventory_receiving&action=' };
    const INITIAL = window.INVENTORY_DATA || { history: [], historyTotal: 0 };
    // INITIAL.history nạp TOÀN BỘ batch (view truyền per_page = tổng) → lọc/phân trang
    // chạy hoàn toàn client-side, không gọi thêm endpoint get_history.
    let pageSize = 10;                     // số dòng/trang — đổi qua select #hf-page-size
    // Bộ lọc lịch sử (client-side trên INITIAL.history đã nạp sẵn).
    const histFilter = { keyword: '', dateFrom: '', dateTo: '' };
    const JE_DEFAULTS = { debit: '152', credit: '331' };

    // ---------- State ----------
    let selectedSupplier = null;          // { id, supplier_name }
    let historyPage = 1;
    let editingGroupKey = null;            // null = chế độ Ghi mới; string = đang Sửa
    let jeAmountTouched = false;

    // dropdown state cho .wp-search (suppliers)
    let supDropdownItems = [];
    let supActiveIdx = -1;

    // ---------- DOM refs ----------
    const $search       = document.getElementById('search-suppliers');
    const $dropdown     = document.getElementById('search-dropdown');
    const $listSupplier = document.getElementById('list-supplier');
    const $dateTime     = document.getElementById('record-datetime');
    const $tbody        = document.getElementById('material-tbody');
    const $rowTpl       = document.getElementById('material-row-template');
    const $btnAddRow    = document.getElementById('btn-add-row');
    const $btnRec       = document.getElementById('btn-record');
    const $btnEdit      = document.getElementById('btn-edit');
    const $totalValBill = document.querySelector('.total-value-bill .result');
    const $totalPurCost = document.querySelector('.total-purchase-cost .result');
    const $histBody     = document.getElementById('history-tbody');
    const $histPager    = document.getElementById('history-pagination');
    const $hfDateFrom   = document.getElementById('hf-date-from');
    const $hfDateTo     = document.getElementById('hf-date-to');
    const $hfPageSize   = document.getElementById('hf-page-size');
    const $hfReset      = document.getElementById('hf-reset');
    const $hfCount      = document.getElementById('hf-count');
    const $hfKeyword    = document.getElementById('hf-keyword');
    const $hfKeywordBtn = document.getElementById('hf-keyword-btn');
    const $hfKeywordPop = document.getElementById('hf-keyword-pop');
    const $banner       = document.getElementById('edit-batch-banner');
    const $bannerLabel  = document.getElementById('edit-batch-label');
    const $cancelEdit   = document.getElementById('cancel-edit-batch');
    const $jeDebit      = document.getElementById('je-debit');
    const $jeCredit     = document.getElementById('je-credit');
    const $jeAmount     = document.getElementById('je-amount');

    // Modal in PHIẾU NHẬP KHO
    const $btnPrint     = document.getElementById('btn-print');
    const $btnPrintWrap = document.getElementById('btn-print-wrap');
    const $btnPrintMenu = document.getElementById('btn-print-menu');
    const $printModal   = document.getElementById('print-modal');
    const $printSheet   = document.getElementById('print-sheet');
    const $printClose   = document.getElementById('print-modal-close');
    const $printOverlay = document.getElementById('print-modal-overlay');
    const $printDo      = document.getElementById('print-modal-do');
    const $printShare   = document.getElementById('print-modal-share');

    // Thông tin cố định in phiếu (sửa trực tiếp trên modal → lưu DB dùng dài lâu).
    const PRINT_SETTINGS = Object.assign({
        company_name:       'CÔNG TY TNHH VUA AN TOÀN',
        company_address:    '1/13Z Ấp Tiền Lân, Xã Bà Điểm, Thành Phố Hồ Chí Minh, Việt Nam.',
        signer_preparer:    'TRƯƠNG NGỌC SÁU',
        signer_deliverer:   '',
        signer_storekeeper: '',
        signer_accountant:  '',
    }, window.PRINT_SETTINGS || {});

    // Vùng kéo-thả hóa đơn mua hàng (staging, upload sau khi Ghi/Sửa)
    const invoiceDz = (window.InvoiceDropzone && document.getElementById('invoice-upload'))
        ? window.InvoiceDropzone.create(document.getElementById('invoice-upload'))
        : null;

    function uploadInvoices(invoiceId) {
        if (!invoiceDz || !invoiceId) return;
        const files = invoiceDz.getFiles();
        if (!files.length) return;
        const fd = new FormData();
        fd.append('invoice_id', invoiceId);
        files.forEach(f => fd.append('files[]', f));
        fetch(CFG.baseUrl + 'upload_purchase_invoice', { method: 'POST', body: fd, credentials: 'same-origin' })
            .then(r => r.json())
            .then(res => {
                if (res && res.ok) {
                    invoiceDz.clear();
                    if (res.errors && res.errors.length) alert(res.errors.join('\n'));
                } else {
                    alert((res && res.message) || 'Tải hóa đơn thất bại (phiếu đã được ghi).');
                }
            })
            .catch(() => alert('Lỗi mạng khi tải hóa đơn (phiếu đã được ghi).'));
    }

    /* ============================================
       Hóa đơn ĐÃ LƯU — load lại khi Sửa (badge số ở góc phải, bấm để xem)
       ============================================ */
    const $invSavedBadge = document.getElementById('iu-saved-badge');
    const $invSavedCount = $invSavedBadge ? $invSavedBadge.querySelector('.iu-saved-count') : null;
    const $invSavedList  = document.getElementById('iu-saved-list');
    const IU_IMG_EXT = ['jpg', 'jpeg', 'png', 'gif', 'webp'];
    function iuIsImg(u) { return IU_IMG_EXT.indexOf(String(u || '').split('.').pop().toLowerCase()) !== -1; }

    function renderSavedInvoices(list) {
        list = list || [];
        if (!$invSavedBadge || !$invSavedList) return;
        if (!list.length) {
            $invSavedBadge.style.display = 'none';
            $invSavedList.style.display  = 'none';
            $invSavedList.innerHTML = '';
            if ($invSavedCount) $invSavedCount.textContent = '0';
            return;
        }
        if ($invSavedCount) $invSavedCount.textContent = String(list.length);
        $invSavedBadge.style.display = '';
        $invSavedList.style.display  = 'none';   // ẩn cho tới khi bấm badge
        $invSavedList.innerHTML = list.map(function (it) {
            var url   = it.file_url;
            var inner = iuIsImg(url)
                ? '<img src="' + url + '" data-view="' + url + '" alt="hóa đơn">'
                : '<i class="fa-solid fa-file-pdf iu-file-ico" data-view="' + url + '"></i>';
            return '<div class="iu-thumb">' + inner + '</div>';
        }).join('');
    }

    function loadSavedInvoices(invoiceId) {
        renderSavedInvoices([]);
        if (!invoiceId) return;
        postForm('list_purchase_invoices', { invoice_id: invoiceId })
            .then(function (res) { if (res && res.ok) renderSavedInvoices(res.data || []); })
            .catch(function () {});
    }

    if ($invSavedBadge) {
        $invSavedBadge.addEventListener('click', function () {
            if (!$invSavedList) return;
            $invSavedList.style.display = ($invSavedList.style.display === 'none') ? 'flex' : 'none';
        });
    }
    if ($invSavedList) {
        $invSavedList.addEventListener('click', function (e) {
            var el = e.target.closest('[data-view]');
            if (!el) return;
            var url = el.getAttribute('data-view');
            if (window.InvoiceViewer) window.InvoiceViewer.open(url);
            else window.open(url, '_blank');
        });
    }

    /* ============================================
       Helpers
       ============================================ */
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

    function parseNum(s) {
        return Number(String(s == null ? '' : s).replace(/[^\d.-]/g, '')) || 0;
    }

    function fmtMoney(v) {
        const n = Math.round(Number(v) || 0);
        return n.toLocaleString('en-US') + ' đ';
    }

    function fmtMoneyPlain(v) {
        const n = Math.round(Number(v) || 0);
        return n.toLocaleString('en-US');
    }

    // Đơn giá (.cell-price): KHÔNG giới hạn 4 số thập phân (cho phép 5/6/7/8 số), KHÔNG làm tròn
    // về số nguyên như fmtMoney — để lưu/hiển thị chính xác đơn giá lẻ kiểu "258.3333333". Khớp
    // cột DECIMAL(20,8) material_purchase_prices.purchase_price / stock_imports.unit_price.
    // Vẫn cắt ở 8 số (round) chỉ để bỏ đuôi lỗi làm tròn nhị phân của JS (vd 258.33330000000004),
    // không phải giới hạn số chữ số thập phân user được nhập.
    function fmtPrice(v) {
        const n = Math.round((Number(v) || 0) * 1e8) / 1e8;
        return n.toLocaleString('en-US', { maximumFractionDigits: 8 }) + ' đ';
    }

    function fmtQty(v) {
        const n = Number(v) || 0;
        if (Math.abs(n - Math.round(n)) < 1e-9) return String(Math.round(n));
        return String(parseFloat(n.toFixed(4)));
    }

    /* ============================================
       Datetime picker
       ============================================ */
    function pad2(n) { return String(n).padStart(2, '0'); }
    function nowLocalValue() {
        const d = new Date();
        return d.getFullYear() + '-' + pad2(d.getMonth() + 1) + '-' + pad2(d.getDate())
             + 'T' + pad2(d.getHours()) + ':' + pad2(d.getMinutes()) + ':' + pad2(d.getSeconds());
    }
    function pickerToMysql() {
        const m = /^(\d{4})-(\d{2})-(\d{2})T(\d{2}):(\d{2})(?::(\d{2}))?$/.exec(String($dateTime.value || ''));
        if (!m) return '';
        return `${m[1]}-${m[2]}-${m[3]} ${m[4]}:${m[5]}:${m[6] || '00'}`;
    }
    function mysqlToLocalValue(s) {
        const m = /^(\d{4})-(\d{2})-(\d{2})[\sT](\d{2}):(\d{2}):(\d{2})$/.exec(String(s || ''));
        if (!m) return null;
        return `${m[1]}-${m[2]}-${m[3]}T${m[4]}:${m[5]}:${m[6]}`;
    }

    /* ============================================
       Journal entry (bút toán kế toán) — top-right
       ============================================ */
    function jeSyncAmountFromTotal() {
        if (jeAmountTouched || !$jeAmount) return;
        const total = parseNum($totalValBill.textContent);
        $jeAmount.value = total > 0 ? Math.round(total).toLocaleString('en-US') : '';
    }
    function jePayload() {
        const p = {
            je_debit:  ($jeDebit  && $jeDebit.value.trim())  || '',
            je_credit: ($jeCredit && $jeCredit.value.trim()) || '',
            je_amount: parseNum($jeAmount && $jeAmount.value),
        };
        if (window.JE && window.JE.collectEntries) p.je_entries = JSON.stringify(window.JE.collectEntries());
        return p;
    }
    function setupJe() {
        if (!$jeDebit || !$jeCredit || !$jeAmount) return;
        if (!$jeDebit.value)  $jeDebit.value  = JE_DEFAULTS.debit;
        if (!$jeCredit.value) $jeCredit.value = JE_DEFAULTS.credit;
        $jeAmount.addEventListener('input', () => { jeAmountTouched = true; });
        $jeAmount.addEventListener('blur', () => {
            const n = parseNum($jeAmount.value);
            $jeAmount.value = n > 0 ? Math.round(n).toLocaleString('en-US') : '';
        });
        $jeAmount.addEventListener('focus', () => {
            const n = parseNum($jeAmount.value);
            $jeAmount.value = n > 0 ? String(Math.round(n)) : '';
        });
        jeSyncAmountFromTotal();
    }

    /* ============================================
       Supplier search dropdown (#search-suppliers)
       ============================================ */
    const runSupplierSearch = debounce(function () {
        const kw = $search.value.trim();
        if (kw === '') { hideSupDropdown(); return; }
        postForm('search_suppliers', { keyword: kw }).then(res => {
            renderSupDropdown(res.data || []);
        });
    }, 220);

    function renderSupDropdown(items) {
        supDropdownItems = items;
        supActiveIdx = -1;
        if (!items.length) {
            $dropdown.innerHTML = '<li class="empty">Không tìm thấy NCC</li>';
        } else {
            $dropdown.innerHTML = items.map((it, i) =>
                `<li data-id="${it.id}" data-idx="${i}">${escapeHtml(it.supplier_name)}</li>`
            ).join('');
        }
        $dropdown.classList.add('active');
    }

    function hideSupDropdown() {
        $dropdown.classList.remove('active');
        $dropdown.innerHTML = '';
        supActiveIdx = -1;
    }

    function pickSupplier(el) {
        const id = parseInt(el.getAttribute('data-id'), 10);
        if (!id || Number.isNaN(id)) return;
        const it = supDropdownItems.find(x => +x.id === id);
        if (!it) return;
        setSelectedSupplier({ id: id, supplier_name: it.supplier_name });
        hideSupDropdown();
        // Chọn xong NCC → focus luôn vào ô tên NVL của dòng đầu để nhập tiếp.
        const $firstName = $tbody.querySelector('tr .cell-name');
        if ($firstName) $firstName.focus();
    }

    function setSelectedSupplier(sup) {
        selectedSupplier = sup;
        renderSupplierPill();
    }

    function renderSupplierPill() {
        // Mỗi phiếu nhập kho chỉ 1 nhà cung cấp → gán thẳng tên NCC vào ô tìm kiếm,
        // không hiển thị pill trong #list-supplier nữa.
        if ($listSupplier) $listSupplier.innerHTML = '';
        $search.value = selectedSupplier ? selectedSupplier.supplier_name : '';
        // Đã chọn NCC → in đậm 600 cho ô tìm kiếm.
        $search.classList.toggle('is-selected', !!selectedSupplier);
    }

    /* ============================================
       Material rows (table)
       ============================================ */
    function addRow(prefill) {
        const frag = $rowTpl.content.cloneNode(true);
        const $tr = frag.querySelector('tr');
        if (prefill) applyRowPrefill($tr, prefill);
        $tbody.appendChild($tr);
        return $tr;
    }

    /** Đổ nhãn "Kho NVL"/"Kho TP" theo loại dòng ('material'|'product'|''). */
    function setWarehouseCell($tr, itemType) {
        const $wh = $tr.querySelector('.cell-warehouse');
        if (!$wh) return;
        $wh.textContent = itemType === 'product' ? 'Kho TP' : (itemType === 'material' ? 'Kho NVL' : '—');
    }

    /** Icon giải thích CP khác chỉ hiện khi giá trị > 0. */
    function updateNoteIconVisibility($tr) {
        const $wrap = $tr.querySelector('.cell-other-cost-wrap');
        if (!$wrap) return;
        const val = parseNum($tr.querySelector('.cell-other-cost').value);
        $wrap.classList.toggle('has-note-icon', val > 0);
    }

    /** Đóng mọi popup giải thích CP khác đang mở (click ra ngoài / mở popup khác). */
    function closeAllNotePops() {
        document.querySelectorAll('.cell-other-cost-wrap.note-open').forEach($w => $w.classList.remove('note-open'));
    }

    /** Chớp sáng 1 ô sau khi giá trị được tính lại (vd tick "Gồm CP khác"). */
    function flashCell($el) {
        if (!$el) return;
        $el.classList.remove('flash-recalc');
        void $el.offsetWidth; // restart animation
        $el.classList.add('flash-recalc');
        $el.addEventListener('animationend', function handler() {
            $el.classList.remove('flash-recalc');
            $el.removeEventListener('animationend', handler);
        });
    }

    function applyRowPrefill($tr, p) {
        const inpName = $tr.querySelector('.cell-name');
        const itemType = p.item_type === 'product' ? 'product' : 'material';
        inpName.value = p.material_name || '';
        inpName.dataset.itemType   = itemType;
        inpName.dataset.materialId = itemType === 'material' ? (p.material_id || 0) : 0;
        inpName.dataset.productId  = itemType === 'product'  ? (p.product_id  || 0) : 0;
        setWarehouseCell($tr, itemType);
        $tr.querySelector('.cell-unit').value       = p.unit || '';
        $tr.querySelector('.cell-quantity').value   = fmtQty(p.quantity || 0);
        $tr.querySelector('.cell-price').value      = fmtPrice(p.unit_price || 0);
        $tr.querySelector('.cell-vat').value        = fmtQty(p.vat_rate || 0);
        $tr.querySelector('.cell-other-cost').value = fmtMoney(p.other_cost || 0);
        const $vatCheck = $tr.querySelector('.cell-vat-incl-check');
        if ($vatCheck) $vatCheck.checked = !!p.vat_includes_other_cost;
        const $note = $tr.querySelector('.cell-note-input');
        if ($note) $note.value = p.other_cost_note || '';
        recalcRow($tr);
    }

    function recalcRow($tr, opts) {
        opts = opts || {};
        const qty   = parseNum($tr.querySelector('.cell-quantity').value);
        const price = parseNum($tr.querySelector('.cell-price').value);
        const vatR  = parseNum($tr.querySelector('.cell-vat').value);
        const oth   = parseNum($tr.querySelector('.cell-other-cost').value);
        const $vatCheck = $tr.querySelector('.cell-vat-incl-check');
        const inclOther = !!($vatCheck && $vatCheck.checked);
        const amount     = qty * price;
        const vatBase    = inclOther ? (amount + oth) : amount;
        const vatAmount  = vatBase * (vatR / 100);
        const total      = amount + vatAmount + oth;
        $tr.querySelector('.cell-amount').textContent = fmtMoney(amount);
        const $vatAmountCell = $tr.querySelector('.cell-vat-amount');
        $vatAmountCell.textContent = fmtMoney(vatAmount);
        $tr.querySelector('.cell-total').textContent  = fmtMoney(total);
        updateNoteIconVisibility($tr);
        const $vatWrap = $tr.querySelector('.cell-vat-wrap');
        if ($vatWrap) $vatWrap.classList.toggle('has-vat-toggle', vatR > 0);
        if (opts.flash) flashCell($vatAmountCell);
        recalcTotals();
    }

    function recalcTotals() {
        let sumTotal = 0, sumPurchaseCost = 0;
        $tbody.querySelectorAll('tr').forEach($tr => {
            sumTotal        += parseNum($tr.querySelector('.cell-total').textContent);
            sumPurchaseCost += parseNum($tr.querySelector('.cell-vat-amount').textContent)
                             + parseNum($tr.querySelector('.cell-other-cost').value);
        });
        $totalValBill.textContent = fmtMoney(sumTotal);
        $totalPurCost.textContent = fmtMoney(sumPurchaseCost);
        jeSyncAmountFromTotal();
    }

    /* ============================================
       Material dropdown (per row, on .cell-name input)
       ============================================ */
    function showMaterialDropdown($tr, items) {
        const $dd = $tr.querySelector('.material-dropdown');
        if (!items.length) {
            $dd.innerHTML = '<li class="empty">Không tìm thấy hàng hóa</li>';
        } else {
            $dd.innerHTML = items.map(it => {
                const type = it.type === 'product' ? 'product' : 'material';
                const badge = type === 'product' ? 'TP' : 'NVL';
                return `<li data-id="${it.id}" data-type="${type}" data-unit="${escapeHtml(it.unit || '')}">` +
                       `<span class="item-dd-badge item-dd-badge-${type}">${badge}</span>` +
                       `${escapeHtml(it.name)}</li>`;
            }).join('');
        }
        $dd.classList.add('active');
    }

    function hideMaterialDropdown($tr) {
        const $dd = $tr.querySelector('.material-dropdown');
        if ($dd) { $dd.classList.remove('active'); $dd.innerHTML = ''; }
    }

    // Đánh dấu item đang chọn trong dropdown NVL (điều hướng bằng phím mũi tên).
    function setMatActive(lis, idx) {
        lis.forEach(li => li.classList.remove('active'));
        if (lis[idx]) { lis[idx].classList.add('active'); lis[idx].scrollIntoView({ block: 'nearest' }); }
    }

    const runMaterialSearch = debounce(function ($tr, kw) {
        postForm('search_items', { keyword: kw }).then(res => {
            showMaterialDropdown($tr, res.data || []);
        });
    }, 220);

    function pickMaterial($tr, id, type) {
        type = type === 'product' ? 'product' : 'material';
        postForm('get_item_info', { item_type: type, item_id: id }).then(res => {
            if (!res.success) { alert(res.message || 'Lỗi'); return; }
            const d = res.data;
            const $name = $tr.querySelector('.cell-name');
            $name.value = d.material_name;
            $name.dataset.itemType   = type;
            $name.dataset.materialId = type === 'material' ? d.id : 0;
            $name.dataset.productId  = type === 'product'  ? d.id : 0;
            setWarehouseCell($tr, type);
            $tr.querySelector('.cell-unit').value = d.unit || '';
            $tr.querySelector('.cell-price').value = fmtPrice(d.purchase_price || 0);
            hideMaterialDropdown($tr);
            recalcRow($tr);
            // Chọn bằng Tab: focus đã sang ô kế tiếp trước khi response về — việc set .value ở trên
            // xóa mất vùng bôi đen mặc định của trình duyệt. Bôi đen lại để user gõ đè được ngay.
            const $focused = document.activeElement;
            if ($focused && $tr.contains($focused) && typeof $focused.select === 'function' && $focused !== $name) {
                $focused.select();
            }
        });
    }

    /* ============================================
       Collect items / Save / Edit / Delete
       ============================================ */
    function collectItems() {
        const items = [];
        const errors = [];
        $tbody.querySelectorAll('tr').forEach(($tr, idx) => {
            const $nameInp = $tr.querySelector('.cell-name');
            const itemType = $nameInp.dataset.itemType === 'product' ? 'product' : 'material';
            const mid = itemType === 'material' ? parseInt($nameInp.dataset.materialId || '0', 10) : 0;
            const pid = itemType === 'product'  ? parseInt($nameInp.dataset.productId  || '0', 10) : 0;
            const name = $nameInp.value.trim();
            const qty = parseNum($tr.querySelector('.cell-quantity').value);
            const price = parseNum($tr.querySelector('.cell-price').value);
            const vatR = parseNum($tr.querySelector('.cell-vat').value);
            const oth = parseNum($tr.querySelector('.cell-other-cost').value);
            const note = (($tr.querySelector('.cell-note-input') || {}).value || '').trim();
            const inclOther = !!(($tr.querySelector('.cell-vat-incl-check') || {}).checked);
            if ((!mid && !pid) || !name) { errors.push(`Dòng ${idx + 1}: chưa chọn hàng hóa.`); return; }
            if (qty <= 0)       { errors.push(`Dòng ${idx + 1}: số lượng phải > 0.`); return; }
            const amount = qty * price;
            const vatBase = inclOther ? (amount + oth) : amount;
            const vatAmount = vatBase * (vatR / 100);
            const total = amount + vatAmount + oth;
            items.push({
                item_type:   itemType,
                material_id: mid,
                product_id:  pid,
                quantity:    qty,
                unit_price:  price,
                vat_rate:    vatR,
                vat_amount:  Math.round(vatAmount),
                vat_includes_other_cost: inclOther ? 1 : 0,
                other_cost:  oth,
                other_cost_note: note,
                amount:      amount,
                total:       Math.round(total),
            });
        });
        return { items, errors };
    }

    function doRecord() {
        if (!selectedSupplier) { alert('Chưa chọn nhà cung cấp.'); return; }
        const { items, errors } = collectItems();
        if (errors.length) { alert(errors.join('\n')); return; }
        if (!items.length)  { alert('Chưa có dòng NVL nào.'); return; }

        const payload = Object.assign({
            items:       JSON.stringify(items),
            supplier_id: selectedSupplier.id,
            created_at:  pickerToMysql(),
        }, jePayload());

        postForm('record_row_material', payload).then(res => {
            if (!res.success) { alert(res.message || 'Ghi không thành công.'); return; }
            uploadInvoices(res.invoice_id);
            applyHistoryResponse(res);
            clearForm();
            showPriceChangeModal(res.price_changes || [], res.order_matches || (res.order_match ? [res.order_match] : []));
        });
    }

    function doEdit() {
        if (!editingGroupKey) { alert('Chưa chọn nhóm để sửa.'); return; }
        if (!selectedSupplier) { alert('Chưa chọn nhà cung cấp.'); return; }
        const { items, errors } = collectItems();
        if (errors.length) { alert(errors.join('\n')); return; }
        if (!items.length)  { alert('Chưa có dòng NVL nào.'); return; }

        const payload = Object.assign({
            group_key:   editingGroupKey,
            items:       JSON.stringify(items),
            supplier_id: selectedSupplier.id,
            created_at:  pickerToMysql(),
        }, jePayload());

        postForm('edit_row_material', payload).then(res => {
            if (!res.success) { alert(res.message || 'Sửa không thành công.'); return; }
            uploadInvoices(res.invoice_id);
            applyHistoryResponse(res);
            clearForm();
            showPriceChangeModal(res.price_changes || [], res.order_matches || (res.order_match ? [res.order_match] : []));
        });
    }

    function loadBatchForEdit(groupKey) {
        postForm('get_batch', { group_key: groupKey }).then(res => {
            if (!res.success) { alert(res.message || 'Không tìm thấy nhóm.'); return; }
            const b = res.data;
            // Set state
            editingGroupKey = b.group_key;
            setEditMode(true);   // chế độ Sửa: hiện nút "Sửa", ẩn "Ghi"
            setSelectedSupplier({ id: b.supplier_id, supplier_name: b.supplier_name });
            const loc = mysqlToLocalValue(b.created_at);
            if (loc) $dateTime.value = loc;
            // Render rows
            $tbody.innerHTML = '';
            b.items.forEach(it => addRow(it));
            jeAmountTouched = false;
            recalcTotals();

            // Khôi phục cụm bút toán Nợ 152 / Có 331 + GIÁ TRỊ ĐÃ GHI.
            // je-amount = inventory_value (tổng giá trị đơn hàng lúc ghi) lấy qua ajax get_batch.
            // Đặt SAU khi templates load xong để không bị ghi đè (tránh race bất đồng bộ).
            const restoreJeAmount = () => {
                jeAmountTouched = false;
                if ($jeDebit  && !$jeDebit.value.trim())  $jeDebit.value  = JE_DEFAULTS.debit;
                if ($jeCredit && !$jeCredit.value.trim()) $jeCredit.value = JE_DEFAULTS.credit;
                const recorded = Number(b.inventory_value);
                const v = (recorded > 0) ? recorded : parseNum($totalValBill.textContent);
                if ($jeAmount) $jeAmount.value = v > 0 ? Math.round(v).toLocaleString('en-US') : '';
            };
            if (window.JE && window.JE.loadTemplatesAsBlocks) {
                window.JE.loadTemplatesAsBlocks().then(restoreJeAmount).catch(restoreJeAmount);
            } else {
                restoreJeAmount();
            }
            // Hóa đơn đã lưu của phiếu này → badge số ở góc phải vùng hóa đơn.
            loadSavedInvoices(b.invoice_id);

            // Banner
            $banner.style.display = '';
            $bannerLabel.textContent = b.date_display + ' — ' + (b.supplier_name || '(NCC)');
            window.scrollTo({ top: 0, behavior: 'smooth' });
        });
    }

    function doDelete(groupKey, label) {
        if (!confirm(`Xác nhận xóa phiếu "${label}"?\n\nThao tác này sẽ rollback tồn kho và xóa toàn bộ dữ liệu liên quan.`)) return;
        postForm('delete_row_material', { group_key: groupKey }).then(res => {
            if (!res.success) { alert(res.message || 'Xóa không thành công.'); return; }
            applyHistoryResponse(res);
            // Nếu đang sửa chính batch vừa xóa → reset form
            if (editingGroupKey === groupKey) clearForm();
        });
    }

    /** Chuyển hiển thị nút theo chế độ: Ghi mới (chỉ "Ghi") / Sửa (chỉ "Sửa"). */
    function setEditMode(on) {
        if ($btnEdit) $btnEdit.style.display = on ? '' : 'none';
        if ($btnRec)  $btnRec.style.display  = on ? 'none' : '';
    }

    function clearForm() {
        editingGroupKey = null;
        setEditMode(false);   // chế độ Ghi mới: ẩn nút "Sửa"
        selectedSupplier = null;
        $listSupplier.innerHTML = '';
        $search.value = '';
        $search.classList.remove('is-selected');
        $tbody.innerHTML = '';
        addRow();
        $dateTime.value = nowLocalValue();
        $banner.style.display = 'none';
        $bannerLabel.textContent = '';
        jeAmountTouched = false;
        if (window.JE && window.JE.reset) window.JE.reset();
        $jeDebit.value  = JE_DEFAULTS.debit;
        $jeCredit.value = JE_DEFAULTS.credit;
        if (invoiceDz) invoiceDz.clear();
        renderSavedInvoices([]);   // ẩn badge + danh sách hóa đơn đã lưu (chế độ Ghi mới)
        recalcTotals();
    }

    /* ============================================
       Modal biến động giá nhập NVL
       ============================================ */
    function fmtRate(r) {
        const n = Number(r) || 0;
        const sign = n > 0 ? '+' : '';
        return sign + (Math.round(n * 100) / 100).toLocaleString('en-US') + '%';
    }

    // orderMatches: mảng đơn đặt hàng vừa được tự động xác nhận "Đã nhận" (1 phiếu có thể
    // trả cho NHIỀU đơn của cùng NCC). Vẫn nhận 1 object đơn lẻ cho tương thích bản cũ.
    function showPriceChangeModal(changes, orderMatches) {
        changes = Array.isArray(changes) ? changes : [];
        if (orderMatches && !Array.isArray(orderMatches)) orderMatches = [orderMatches];
        orderMatches = Array.isArray(orderMatches) ? orderMatches.filter(Boolean) : [];
        if (!changes.length && !orderMatches.length) {
            return;
        }
        let ov = document.getElementById('ppc-modal-overlay');
        if (!ov) {
            ov = document.createElement('div');
            ov.id = 'ppc-modal-overlay';
            ov.className = 'ppc-modal-overlay';
            ov.innerHTML =
                '<div class="ppc-modal">' +
                    '<div class="ppc-modal-head">' +
                        '<h3>Nguyên vật liệu biến động giá nhập</h3>' +
                        '<button type="button" class="ppc-modal-close" title="Đóng">×</button>' +
                    '</div>' +
                    '<div class="ppc-order-match"></div>' +
                    '<div class="ppc-modal-body">' +
                        '<table class="ppc-table">' +
                            '<thead><tr><th>NVL</th><th class="center">Giá cũ</th><th class="center">Giá mới</th><th class="center">Biến động</th><th class="center">Thao tác</th></tr></thead>' +
                            '<tbody></tbody>' +
                        '</table>' +
                    '</div>' +
                    '<div class="ppc-modal-foot"><button type="button" class="ppc-modal-ok">Đóng</button></div>' +
                '</div>';
            document.body.appendChild(ov);
            const close = () => ov.classList.remove('active');
            ov.addEventListener('click', e => {
                if (e.target === ov || e.target.closest('.ppc-modal-close') || e.target.closest('.ppc-modal-ok')) { close(); return; }
                const impactBtn = e.target.closest('.ppc-impact-btn');
                if (impactBtn) {
                    showCostImpactModal(
                        impactBtn.getAttribute('data-material-id'),
                        impactBtn.getAttribute('data-old-price'),
                        impactBtn.getAttribute('data-new-price'),
                        impactBtn.getAttribute('data-material-name')
                    );
                }
            });
            document.addEventListener('keydown', e => { if (e.key === 'Escape') close(); });
        }

        ov.querySelector('.ppc-modal-body').style.display = changes.length ? '' : 'none';
        ov.querySelector('tbody').innerHTML = changes.map(c => {
            const up = Number(c.change_rate) > 0;
            const mid = c.material_id;
            return '<tr>' +
                '<td>' + escapeHtml(c.name) + '</td>' +
                '<td class="num center">' + fmtMoney(c.old_price) + '</td>' +
                '<td class="num center">' + fmtMoney(c.new_price) + '</td>' +
                '<td class="num center ' + (up ? 'up' : 'down') + '">' + fmtRate(c.change_rate) + '</td>' +
                '<td class="center">' +
                    (mid
                        ? '<button type="button" class="ppc-impact-btn" title="Giá vốn ảnh hưởng" ' +
                            'data-material-id="' + mid + '" data-old-price="' + c.old_price + '" data-new-price="' + c.new_price + '" ' +
                            'data-material-name="' + escapeHtml(c.name) + '"><i class="fa-solid fa-sack-dollar"></i></button>'
                        : '') +
                '</td>' +
            '</tr>';
        }).join('');

        const omBox = ov.querySelector('.ppc-order-match');
        if (orderMatches.length) {
            let html = '';
            // Nhiều đơn cùng về 1 phiếu -> thêm 1 dòng tổng để user đối chiếu nhanh.
            if (orderMatches.length > 1) {
                const gExp = Number(orderMatches[0].group_expected_total) || 0;
                const gAct = Number(orderMatches[0].group_actual_total) || 0;
                const gDiff = Math.round(gAct) - Math.round(gExp);
                html +=
                    '<div class="ppc-om-card' + (gDiff !== 0 ? ' has-diff' : '') + '">' +
                        '<div class="ppc-om-title"><i class="fa-solid fa-layer-group"></i> ' +
                            orderMatches.length + ' đơn đặt hàng của ' + escapeHtml(orderMatches[0].supplier_name || '') +
                            ' cùng về 1 phiếu nhập này.</div>' +
                        '<div class="ppc-om-row">Tổng dự kiến: <b>' + fmtMoney(gExp) + '</b>' +
                            ' &nbsp;·&nbsp; Tổng thực nhập: <b>' + fmtMoney(gAct) + '</b>' +
                            (gDiff === 0 ? ' &nbsp;·&nbsp; khớp giá trị.' : '') +
                        '</div>' +
                    '</div>';
            }
            html += orderMatches.map(om => {
                const diff = Number(om.diff) || 0;
                const diffCls = diff > 0 ? 'up' : (diff < 0 ? 'down' : '');
                return '<div class="ppc-om-card' + (diff !== 0 ? ' has-diff' : '') + '">' +
                    '<div class="ppc-om-title"><i class="fa-solid fa-circle-check"></i> Đơn đặt hàng #' + om.order_id +
                        ' (' + escapeHtml(om.supplier_name || '') + ', ' + om.item_count + ' mặt hàng)' +
                        ' đã tự động xác nhận "Đã nhận".</div>' +
                    '<div class="ppc-om-row">Dự kiến: <b>' + fmtMoney(om.expected_value) + '</b>' +
                        ' &nbsp;·&nbsp; Thực nhập: <b>' + fmtMoney(om.actual_value) + '</b>' +
                        (diff !== 0
                            ? ' &nbsp;·&nbsp; <span class="ppc-om-warn ' + diffCls + '">' +
                                '<i class="fa-solid fa-triangle-exclamation"></i> Chênh ' + fmtMoney(Math.abs(diff)) +
                                ' (' + fmtRate(om.diff_rate) + ') so với dự kiến — NVL đang biến động giá.</span>'
                            : '') +
                    '</div>' +
                '</div>';
            }).join('');
            omBox.innerHTML = html;
            omBox.style.display = '';
        } else {
            omBox.innerHTML = '';
            omBox.style.display = 'none';
        }

        ov.classList.add('active');
    }

    /* ============================================
       Modal "Giá vốn ảnh hưởng" — danh sách sản phẩm dùng 1 NVL vừa đổi giá
       (mở từ nút icon trong modal biến động giá nhập ở trên).
       ============================================ */
    function showCostImpactModal(materialId, oldPrice, newPrice, materialName) {
        let ov = document.getElementById('pci-modal-overlay');
        if (!ov) {
            ov = document.createElement('div');
            ov.id = 'pci-modal-overlay';
            ov.className = 'ppc-modal-overlay';
            ov.innerHTML =
                '<div class="ppc-modal pci-modal">' +
                    '<div class="ppc-modal-head">' +
                        '<h3 class="pci-modal-title">Giá vốn ảnh hưởng</h3>' +
                        '<button type="button" class="ppc-modal-close" title="Đóng">×</button>' +
                    '</div>' +
                    '<div class="ppc-modal-body">' +
                        '<table class="ppc-table">' +
                            '<thead><tr>' +
                                '<th>Tên sản phẩm</th><th class="center">Giá vốn cũ</th>' +
                                '<th class="center">Giá vốn mới</th><th class="center">Tỉ lệ biến động</th>' +
                            '</tr></thead>' +
                            '<tbody></tbody>' +
                        '</table>' +
                    '</div>' +
                    '<div class="ppc-modal-foot"><button type="button" class="ppc-modal-ok">Đóng</button></div>' +
                '</div>';
            document.body.appendChild(ov);
            const close = () => ov.classList.remove('active');
            ov.addEventListener('click', e => {
                if (e.target === ov || e.target.closest('.ppc-modal-close') || e.target.closest('.ppc-modal-ok')) { close(); return; }
                const nameBtn = e.target.closest('.pci-product-name');
                if (nameBtn) {
                    showCostBreakdownModal(
                        nameBtn.getAttribute('data-product-id'),
                        ov.dataset.materialId, ov.dataset.oldPrice, ov.dataset.newPrice,
                        nameBtn.textContent.trim()
                    );
                }
            });
            document.addEventListener('keydown', e => { if (e.key === 'Escape') close(); });
        }

        // Lưu vào dataset để handler click tên sản phẩm (đã bind 1 lần ở trên) luôn đọc
        // đúng NVL/giá đang xem — modal được tái dùng (không tạo lại DOM) giữa các lần mở.
        ov.dataset.materialId = materialId;
        ov.dataset.oldPrice = oldPrice;
        ov.dataset.newPrice = newPrice;
        ov.querySelector('.pci-modal-title').textContent = 'Giá vốn ảnh hưởng' + (materialName ? ' — ' + materialName : '');
        ov.querySelector('tbody').innerHTML = '<tr><td colspan="4" style="text-align:center;color:#888;">Đang tải...</td></tr>';
        ov.classList.add('active');

        postForm('ajax_material_cost_impact', { material_id: materialId, old_price: oldPrice, new_price: newPrice }).then(res => {
            if (!res || !res.success) {
                ov.querySelector('tbody').innerHTML = '<tr><td colspan="4" style="text-align:center;color:#c00;">' +
                    escapeHtml((res && res.message) || 'Lỗi tải dữ liệu.') + '</td></tr>';
                return;
            }
            const items = res.items || [];
            if (!items.length) {
                ov.querySelector('tbody').innerHTML = '<tr><td colspan="4" style="text-align:center;color:#888;">Không có sản phẩm nào dùng nguyên liệu này.</td></tr>';
                return;
            }
            ov.querySelector('tbody').innerHTML = items.map(it => {
                const up = Number(it.change_rate) > 0;
                return '<tr>' +
                    '<td><button type="button" class="pci-product-name" data-product-id="' + it.product_id + '">' +
                        escapeHtml(it.product_name) + '</button></td>' +
                    '<td class="num center">' + fmtMoney(it.old_cost) + '</td>' +
                    '<td class="num center">' + fmtMoney(it.new_cost) + '</td>' +
                    '<td class="num center ' + (up ? 'up' : 'down') + '">' + fmtRate(it.change_rate) + '</td>' +
                '</tr>';
            }).join('');
        });
    }

    /* ============================================
       Modal "Giải thích giá vốn" — thành phần/định mức/đơn giá/thành tiền cũ & mới
       của 1 sản phẩm (mở từ click tên sản phẩm trong modal "Giá vốn ảnh hưởng").
       ============================================ */
    function showCostBreakdownModal(productId, materialId, oldPrice, newPrice, productName) {
        let ov = document.getElementById('pcb-modal-overlay');
        if (!ov) {
            ov = document.createElement('div');
            ov.id = 'pcb-modal-overlay';
            ov.className = 'ppc-modal-overlay';
            ov.innerHTML =
                '<div class="ppc-modal pcb-modal">' +
                    '<div class="ppc-modal-head">' +
                        '<h3 class="pcb-modal-title">Giải thích giá vốn</h3>' +
                        '<button type="button" class="ppc-modal-close" title="Đóng">×</button>' +
                    '</div>' +
                    '<div class="ppc-modal-body">' +
                        '<table class="ppc-table pcb-table">' +
                            '<thead><tr>' +
                                '<th>Thành phần</th><th class="center">Định mức</th>' +
                                '<th class="center">Đơn giá cũ</th><th class="center">Đơn giá mới</th>' +
                                '<th class="center">Thành tiền cũ</th><th class="center">Thành tiền mới</th>' +
                            '</tr></thead>' +
                            '<tbody></tbody>' +
                            '<tfoot></tfoot>' +
                        '</table>' +
                    '</div>' +
                    '<div class="ppc-modal-foot"><button type="button" class="ppc-modal-ok">Đóng</button></div>' +
                '</div>';
            document.body.appendChild(ov);
            const close = () => ov.classList.remove('active');
            ov.addEventListener('click', e => {
                if (e.target === ov || e.target.closest('.ppc-modal-close') || e.target.closest('.ppc-modal-ok')) close();
            });
            document.addEventListener('keydown', e => { if (e.key === 'Escape') close(); });
        }

        ov.querySelector('.pcb-modal-title').textContent = 'Giải thích giá vốn' + (productName ? ' — ' + productName : '');
        ov.querySelector('tbody').innerHTML = '<tr><td colspan="6" style="text-align:center;color:#888;">Đang tải...</td></tr>';
        ov.querySelector('tfoot').innerHTML = '';
        ov.classList.add('active');

        postForm('ajax_product_cost_breakdown', { product_id: productId, material_id: materialId, old_price: oldPrice, new_price: newPrice }).then(res => {
            if (!res || !res.success) {
                ov.querySelector('tbody').innerHTML = '<tr><td colspan="6" style="text-align:center;color:#c00;">' +
                    escapeHtml((res && res.message) || 'Lỗi tải dữ liệu.') + '</td></tr>';
                return;
            }
            const rows = res.rows || [];
            ov.querySelector('tbody').innerHTML = rows.map(r => {
                return '<tr class="' + (r.is_changed ? 'pcb-row-changed' : '') + '">' +
                    '<td>' + escapeHtml(r.material_name) + (r.is_changed ? ' <span class="pcb-changed-tag">biến động</span>' : '') + '</td>' +
                    '<td class="num center">' + fmtQty(r.quantity_required) + '</td>' +
                    '<td class="num center">' + fmtPrice(r.price_old) + '</td>' +
                    '<td class="num center">' + fmtPrice(r.price_new) + '</td>' +
                    '<td class="num center">' + fmtMoney(r.line_old) + '</td>' +
                    '<td class="num center">' + fmtMoney(r.line_new) + '</td>' +
                '</tr>';
            }).join('');
            const up = Number(res.change_rate) > 0;
            ov.querySelector('tfoot').innerHTML =
                '<tr class="pcb-total-row">' +
                    '<td colspan="4">Tổng giá vốn (<span class="' + (up ? 'up' : 'down') + '">' + fmtRate(res.change_rate) + '</span>)</td>' +
                    '<td class="num center">' + fmtMoney(res.total_old) + '</td>' +
                    '<td class="num center">' + fmtMoney(res.total_new) + '</td>' +
                '</tr>';
        });
    }

    /* ============================================
       History render + pagination
       ============================================ */
    // Sau khi Ghi/Sửa/Xóa: server trả về danh sách batch mới → cập nhật cache toàn bộ
    // và render lại (giữ nguyên bộ lọc, nhảy về trang 1).
    function applyHistoryResponse(res) {
        if (res.history) INITIAL.history = res.history || [];
        historyPage = 1;
        renderHistoryPage();
    }

    // Ngày của batch dạng 'YYYY-MM-DD'. Ưu tiên created_at ('YYYY-MM-DD HH:MM:SS');
    // fallback parse từ date_display ('... dd/mm/yyyy'). So sánh chuỗi 'YYYY-MM-DD'
    // đúng thứ tự nên dùng trực tiếp cho khoảng ngày.
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
                    String(it.material_name || '').toLowerCase().indexOf(kw) !== -1);
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
            const msg = total ? 'Không có nhóm nào khớp bộ lọc.' : 'Chưa có lịch sử.';
            $histBody.innerHTML = `<tr class="history-empty"><td colspan="3">${msg}</td></tr>`;
            if ($histPager) $histPager.innerHTML = '';
            return;
        }

        const start = (historyPage - 1) * pageSize;
        const slice = data.slice(start, start + pageSize);
        // data-group-key là khóa duy nhất → handler Sửa/Xóa tra cứu theo nó,
        // không phụ thuộc index (an toàn khi đang lọc/phân trang).
        $histBody.innerHTML = slice.map(b => `
            <tr data-group-key="${escapeHtml(b.group_key)}">
                <td class="${b.date_color ? 'hist-date-' + b.date_color : ''}">${escapeHtml(b.date_display)}</td>
                <td title="${escapeHtml(b.summary)}">${escapeHtml(b.summary)}</td>
                <td class="history-actions">
                    <a href="#" class="edit-batch" data-action="edit">Sửa</a>
                    <span class="sep">|</span>
                    <a href="#" class="delete-batch delete-list-import" data-action="delete">Xóa</a>
                </td>
            </tr>
        `).join('');
        renderHistoryPager(totalPages);
    }

    function renderHistoryPager(totalPages) {
        if (totalPages <= 1) { $histPager.innerHTML = ''; return; }
        const cur = historyPage;
        const buttons = [];
        buttons.push(`<button class="page-btn" data-page="${cur - 1}" ${cur <= 1 ? 'disabled' : ''}>‹</button>`);
        for (let p = 1; p <= totalPages; p++) {
            if (p === 1 || p === totalPages || Math.abs(p - cur) <= 2) {
                buttons.push(`<button class="page-btn ${p === cur ? 'active' : ''}" data-page="${p}">${p}</button>`);
            } else if (p === cur - 3 || p === cur + 3) {
                buttons.push(`<span class="page-ellipsis">…</span>`);
            }
        }
        buttons.push(`<button class="page-btn" data-page="${cur + 1}" ${cur >= totalPages ? 'disabled' : ''}>›</button>`);
        $histPager.innerHTML = buttons.join('');
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
        // Mở/đóng popover phễu trên header "Diễn giải".
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

    /* ============================================
       In PHIẾU NHẬP KHO — modal A4 + Share (chụp ảnh → clipboard)
       ============================================ */

    // Đọc số → chữ tiếng Việt (đủ cho số tiền VND, tới hàng tỷ).
    function numberToVnWords(num) {
        num = Math.round(Number(num) || 0);
        if (num === 0) return 'Không đồng';
        const ones = ['không','một','hai','ba','bốn','năm','sáu','bảy','tám','chín'];
        function readTriple(n, full) {
            const tr = String(n).padStart(3, '0');
            const h = +tr[0], t = +tr[1], u = +tr[2];
            const parts = [];
            if (h !== 0 || full) parts.push(ones[h] + ' trăm');
            if (t === 0) {
                if (u !== 0) { if (h !== 0 || full) parts.push('linh'); parts.push(ones[u]); }
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
            if (g === 0) continue;
            out.push(readTriple(g, i !== 0) + (scales[len - 1 - i] ? ' ' + scales[len - 1 - i] : ''));
        }
        let s = out.join(' ').replace(/\s+/g, ' ').trim();
        return s.charAt(0).toUpperCase() + s.slice(1) + ' đồng';
    }

    function printDateParts() {
        const m = /^(\d{4})-(\d{2})-(\d{2})/.exec(String($dateTime.value || ''));
        if (m) return { dd: m[3], mm: m[2], yyyy: m[1] };
        const d = new Date();
        return { dd: pad2(d.getDate()), mm: pad2(d.getMonth() + 1), yyyy: String(d.getFullYear()) };
    }

    function collectPrintRows() {
        const rows = [];
        $tbody.querySelectorAll('tr').forEach($tr => {
            const $nameInp = $tr.querySelector('.cell-name');
            const itemType = $nameInp.dataset.itemType === 'product' ? 'product' : 'material';
            const mid = parseInt($nameInp.dataset.materialId || '0', 10);
            const pid = parseInt($nameInp.dataset.productId  || '0', 10);
            const id  = itemType === 'product' ? pid : mid;
            const name = ($nameInp.value || '').trim();
            const unit = ($tr.querySelector('.cell-unit').value || '').trim();
            const qty  = parseNum($tr.querySelector('.cell-quantity').value);
            if (!name || qty <= 0) return;
            rows.push({
                type:      itemType,
                code:      id > 0 ? (itemType === 'product' ? 'TP-' : 'NVL-') + id : '',
                name:      name,
                unit:      unit,
                qty:       qty,
                price:     parseNum($tr.querySelector('.cell-price').value),
                vatAmount: parseNum($tr.querySelector('.cell-vat-amount').textContent),
                otherCost: parseNum($tr.querySelector('.cell-other-cost').value),
                total:     parseNum($tr.querySelector('.cell-total').textContent),
            });
        });
        return rows;
    }

    /** Nhãn "Nhập tại kho" động theo các loại dòng đang có trong phiếu. */
    function warehouseSummaryLabel(rows) {
        const hasMat  = rows.some(r => r.type === 'material');
        const hasProd = rows.some(r => r.type === 'product');
        if (hasMat && hasProd) return 'Kho NVL & Kho TP';
        if (hasProd) return 'Kho TP';
        return 'Kho NVL';
    }

    // 1 trường sửa-tại-chỗ: text + cây bút (hiện khi hover). Empty → có gạch chân để bấm.
    function editableField(key) {
        const v = String(PRINT_SETTINGS[key] == null ? '' : PRINT_SETTINGS[key]).trim();
        return `<span class="pr-editable${v ? '' : ' is-empty'}" data-setting="${key}">` +
                   `<span class="pr-ed-text">${escapeHtml(v)}</span>` +
                   `<i class="fa-solid fa-pen pr-edit-pen" title="Sửa (lưu dùng dài lâu)"></i>` +
               `</span>`;
    }

    function buildPrintSheet() {
        const rows     = collectPrintRows();
        const supplier = selectedSupplier ? selectedSupplier.supplier_name : '';
        const d        = printDateParts();
        const sumTotal = parseNum($totalValBill.textContent);
        const sumPur   = parseNum($totalPurCost.textContent);

        let trs = rows.map((r, i) => `
            <tr>
                <td class="center">${i + 1}</td>
                <td>${escapeHtml(r.name)}</td>
                <td class="center">${escapeHtml(r.code)}</td>
                <td class="center">${escapeHtml(r.unit)}</td>
                <td class="num">${fmtQty(r.qty)}</td>
                <td class="num">${fmtMoneyPlain(r.price)}</td>
                <td class="num">${fmtMoneyPlain(r.vatAmount)}</td>
                <td class="num">${fmtMoneyPlain(r.otherCost)}</td>
                <td class="num">${fmtMoneyPlain(r.total)}</td>
            </tr>`).join('');
        if (!trs) trs = '<tr><td colspan="9" class="center"><em>(chưa có nguyên vật liệu)</em></td></tr>';

        return `
            <div class="pr-company">
                <div class="pr-co-name">${editableField('company_name')}</div>
                <div class="pr-co-addr">${editableField('company_address')}</div>
            </div>
            <h2 class="pr-title">PHIẾU NHẬP KHO</h2>
            <div class="pr-date">Ngày ${d.dd} tháng ${d.mm} năm ${d.yyyy}</div>
            <div class="pr-meta">
                <div class="row"><span class="lab">Nhà cung cấp:</span>${escapeHtml(supplier)}</div>
                <div class="row"><span class="lab">Nhập tại kho:</span>${escapeHtml(warehouseSummaryLabel(rows))}</div>
            </div>
            <table class="pr-table">
                <thead>
                    <tr>
                        <th style="width:5%">STT</th>
                        <th>Tên nguyên vật liệu, hàng hóa</th>
                        <th style="width:10%">Mã số</th>
                        <th style="width:8%">Đơn vị</th>
                        <th style="width:9%">Số lượng</th>
                        <th style="width:12%">Đơn giá</th>
                        <th style="width:11%">Giá VAT</th>
                        <th style="width:11%">CP khác</th>
                        <th style="width:13%">Thành tiền</th>
                    </tr>
                </thead>
                <tbody>${trs}</tbody>
            </table>
            <div class="pr-totals">
                <div class="row"><span>Tổng CPMH:</span><span class="val">${fmtMoney(sumPur)}</span></div>
                <div class="row"><span>Tổng giá trị đơn hàng:</span><span class="val">${fmtMoney(sumTotal)}</span></div>
            </div>
            <div class="pr-words">Số tiền bằng chữ: ${escapeHtml(numberToVnWords(sumTotal))}</div>
            <div class="pr-signatures">
                <div class="signer"><div class="role">Người lập biểu</div><div class="sub">(Ký, họ tên)</div><div class="name">${editableField('signer_preparer')}</div></div>
                <div class="signer"><div class="role">Người giao hàng</div><div class="sub">(Ký, họ tên)</div><div class="name">${editableField('signer_deliverer')}</div></div>
                <div class="signer"><div class="role">Thủ kho</div><div class="sub">(Ký, họ tên)</div><div class="name">${editableField('signer_storekeeper')}</div></div>
                <div class="signer"><div class="role">Kế toán trưởng</div><div class="sub">(Ký, họ tên)</div><div class="name">${editableField('signer_accountant')}</div></div>
            </div>
        `;
    }

    /* ---- Phân tích NVL + thành phẩm (chỉ cho "Phiếu nhập kho kèm phân tích") ---- */
    function collectAnalysisIds() {
        const materialIds = [];
        const productIds = [];
        $tbody.querySelectorAll('tr').forEach($tr => {
            const $nameInp = $tr.querySelector('.cell-name');
            const name = ($nameInp.value || '').trim();
            if (!name) return;
            if ($nameInp.dataset.itemType === 'product') {
                const pid = parseInt($nameInp.dataset.productId || '0', 10);
                if (pid > 0 && productIds.indexOf(pid) === -1) productIds.push(pid);
            } else {
                const mid = parseInt($nameInp.dataset.materialId || '0', 10);
                if (mid > 0 && materialIds.indexOf(mid) === -1) materialIds.push(mid);
            }
        });
        return { materialIds, productIds };
    }

    function fmtDateVN(s) {
        const m = /^(\d{4})-(\d{2})-(\d{2})/.exec(String(s || ''));
        return m ? (m[3] + '/' + m[2] + '/' + m[1]) : String(s || '');
    }

    function buildAnalysisForMaterial(name, qtyIn, a) {
        const stock    = a ? (Number(a.stock) || 0) : 0;
        const after    = stock + (Number(qtyIn) || 0);
        const recent   = (a && a.recent) || [];
        const usage    = (a && a.usage) || { m1: 0, m3: 0, m6: 0, y1: 0 };
        const products = (a && a.products) || [];

        let recentRows = recent.map(r => `
            <tr>
                <td class="center">${escapeHtml(fmtDateVN(r.date))}</td>
                <td class="num">${fmtQty(r.quantity)}</td>
                <td class="num">${fmtMoneyPlain(r.unit_price)}</td>
                <td class="num">${fmtMoneyPlain(r.amount)}</td>
                <td class="num">${fmtMoneyPlain(r.cpmh)}</td>
                <td class="num">${fmtMoneyPlain(r.other_cost)}</td>
                <td class="num">${fmtMoneyPlain(r.total)}</td>
                <td class="center">${r.invoices > 0 ? '<i class="fa-solid fa-paperclip"></i> ' + r.invoices : '—'}</td>
            </tr>`).join('');
        if (!recentRows) recentRows = '<tr><td colspan="8" class="center"><em>(chưa có lần nhập nào)</em></td></tr>';

        let prodRows = products.map(p => `
            <tr>
                <td>${escapeHtml(p.product_name)}</td>
                <td class="num">${fmtQty(p.norm)}</td>
                <td class="num">${fmtQty(p.finished)}</td>
            </tr>`).join('');
        if (!prodRows) prodRows = '<tr><td colspan="3" class="center"><em>(không có sản phẩm dùng NVL này)</em></td></tr>';

        return `
            <div class="pr-an-block">
                <div class="pr-an-name">▸ ${escapeHtml(name)}</div>
                <div class="pr-an-stock">
                    <span>Tồn trước nhập: <b>${fmtQty(stock)}</b></span>
                    <span>Tồn sau nhập: <b>${fmtQty(after)}</b></span>
                </div>
                <div class="pr-an-sub">5 lần nhập gần nhất</div>
                <table class="pr-table pr-an-table">
                    <thead>
                        <tr>
                            <th>Ngày</th><th>Số lượng</th><th>Đơn giá</th><th>Thành tiền</th>
                            <th>CPMH</th><th>CP khác</th><th>Tổng GTNK</th><th>Hóa đơn</th>
                        </tr>
                    </thead>
                    <tbody>${recentRows}</tbody>
                </table>
                <div class="pr-an-sub">Lượng dùng (sản xuất)</div>
                <table class="pr-table pr-an-table">
                    <thead>
                        <tr><th>1 tháng</th><th>3 tháng</th><th>6 tháng</th><th>1 năm</th></tr>
                    </thead>
                    <tbody>
                        <tr>
                            <td class="num">${fmtQty(usage.m1)}</td>
                            <td class="num">${fmtQty(usage.m3)}</td>
                            <td class="num">${fmtQty(usage.m6)}</td>
                            <td class="num">${fmtQty(usage.y1)}</td>
                        </tr>
                    </tbody>
                </table>
                <div class="pr-an-sub">Các sản phẩm dùng nguyên vật liệu</div>
                <table class="pr-table pr-an-table">
                    <thead>
                        <tr><th>Tên sản phẩm</th><th>Định mức (gần nhất)</th><th>Thành phẩm (gần nhất)</th></tr>
                    </thead>
                    <tbody>${prodRows}</tbody>
                </table>
            </div>`;
    }

    /** Phân tích 1 thành phẩm — mirror buildAnalysisForMaterial, dùng field export_qty/exports. */
    function buildAnalysisForProduct(name, qtyIn, a) {
        const stock   = a ? (Number(a.stock) || 0) : 0;
        const after   = stock + (Number(qtyIn) || 0);
        const recent  = (a && a.recent) || [];
        const eq      = (a && a.export_qty) || { m1: 0, m3: 0, m6: 0, y1: 0 };
        const exports = (a && a.exports) || [];

        let recentRows = recent.map(r => `
            <tr>
                <td class="center">${escapeHtml(fmtDateVN(r.date))}</td>
                <td class="num">${fmtQty(r.quantity)}</td>
                <td class="num">${fmtMoneyPlain(r.unit_price)}</td>
                <td class="num">${fmtMoneyPlain(r.amount)}</td>
                <td class="num">${fmtMoneyPlain(r.cpmh)}</td>
                <td class="num">${fmtMoneyPlain(r.total)}</td>
                <td class="center">${r.invoices > 0 ? '<i class="fa-solid fa-paperclip"></i> ' + r.invoices : '—'}</td>
            </tr>`).join('');
        if (!recentRows) recentRows = '<tr><td colspan="7" class="center"><em>(chưa có lần nhập nào)</em></td></tr>';

        let expRows = exports.map(e => `
            <tr>
                <td class="center">${escapeHtml(fmtDateVN(e.date))}</td>
                <td class="num">${fmtQty(e.quantity)}</td>
                <td class="num">${fmtMoneyPlain(e.unit_price)}</td>
                <td class="num">${fmtMoneyPlain(e.amount)}</td>
                <td>${escapeHtml(e.customer)}</td>
            </tr>`).join('');
        if (!expRows) expRows = '<tr><td colspan="5" class="center"><em>(chưa có lần xuất nào)</em></td></tr>';

        return `
            <div class="pr-an-block">
                <div class="pr-an-name">▸ ${escapeHtml(name)}</div>
                <div class="pr-an-stock">
                    <span>Tồn trước nhập: <b>${fmtQty(stock)}</b></span>
                    <span>Tồn sau nhập: <b>${fmtQty(after)}</b></span>
                </div>
                <div class="pr-an-sub">5 lần nhập gần nhất</div>
                <table class="pr-table pr-an-table">
                    <thead>
                        <tr>
                            <th>Ngày</th><th>Số lượng</th><th>Đơn giá</th><th>Thành tiền</th>
                            <th>CPMH</th><th>Tổng GTNK</th><th>Hóa đơn</th>
                        </tr>
                    </thead>
                    <tbody>${recentRows}</tbody>
                </table>
                <div class="pr-an-sub">Lượng xuất kho theo mốc thời gian</div>
                <table class="pr-table pr-an-table">
                    <thead>
                        <tr><th>1 tháng</th><th>3 tháng</th><th>6 tháng</th><th>1 năm</th></tr>
                    </thead>
                    <tbody>
                        <tr>
                            <td class="num">${fmtQty(eq.m1)}</td>
                            <td class="num">${fmtQty(eq.m3)}</td>
                            <td class="num">${fmtQty(eq.m6)}</td>
                            <td class="num">${fmtQty(eq.y1)}</td>
                        </tr>
                    </tbody>
                </table>
                <div class="pr-an-sub">Chi tiết xuất kho gần đây</div>
                <table class="pr-table pr-an-table">
                    <thead>
                        <tr><th>Ngày</th><th>Số lượng</th><th>Đơn giá</th><th>Thành tiền</th><th>Khách hàng</th></tr>
                    </thead>
                    <tbody>${expRows}</tbody>
                </table>
            </div>`;
    }

    function buildAnalysisSection(dataMap) {
        const blocks = [];
        $tbody.querySelectorAll('tr').forEach($tr => {
            const $nameInp = $tr.querySelector('.cell-name');
            const name = ($nameInp.value || '').trim();
            const qty  = parseNum($tr.querySelector('.cell-quantity').value);
            if (!name) return;
            if ($nameInp.dataset.itemType === 'product') {
                const pid = parseInt($nameInp.dataset.productId || '0', 10);
                if (!pid) return;
                blocks.push(buildAnalysisForProduct(name, qty, dataMap['p' + pid] || null));
            } else {
                const mid = parseInt($nameInp.dataset.materialId || '0', 10);
                if (!mid) return;
                blocks.push(buildAnalysisForMaterial(name, qty, dataMap['m' + mid] || null));
            }
        });
        if (!blocks.length) {
            blocks.push('<p class="center"><em>(chưa có hàng hóa đã chọn từ danh mục để phân tích)</em></p>');
        }
        return `
            <div class="pr-an-divider"></div>
            <h3 class="pr-an-title">PHÂN TÍCH HÀNG HÓA</h3>
            ${blocks.join('')}`;
    }

    function openPrintModal(mode) {
        if (!$printSheet || !$printModal) return;
        $printSheet.innerHTML = buildPrintSheet();
        $printModal.style.display = 'flex';
        if (mode !== 'analysis') return;

        // Bổ sung phần phân tích bên dưới (tải dữ liệu rồi append).
        const { materialIds, productIds } = collectAnalysisIds();
        const $loading = document.createElement('div');
        $loading.className = 'pr-an-loading';
        $loading.innerHTML = '<div class="pr-an-divider"></div><p class="center"><em>Đang tải phân tích…</em></p>';
        $printSheet.appendChild($loading);
        if (!materialIds.length && !productIds.length) {
            $loading.outerHTML = buildAnalysisSection({});
            return;
        }
        postForm('analysis_row_material', { material_ids: JSON.stringify(materialIds), product_ids: JSON.stringify(productIds) })
            .then(res => {
                const map = (res && res.success && res.data) ? res.data : {};
                $loading.outerHTML = buildAnalysisSection(map);
            })
            .catch(() => { $loading.outerHTML = buildAnalysisSection({}); });
    }
    function closePrintModal() {
        if ($printModal) $printModal.style.display = 'none';
    }

    /* ---- Sửa tại chỗ các trường cố định ---- */
    function enterEdit($wrap) {
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

    function commitEdit($wrap) {
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

    function setupPrintModal() {
        // Nút In = dropdown 2 lựa chọn (Phiếu nhập kho / kèm phân tích).
        // Click bất kỳ đâu trong .btn-print-wrap đều ăn (chọn menu hoặc xổ/đóng).
        if ($btnPrintWrap && $btnPrintMenu) {
            $btnPrintWrap.addEventListener('click', e => {
                e.stopPropagation();
                const $li = e.target.closest('li[data-print-mode]');
                if ($li) {
                    $btnPrintWrap.classList.remove('open');
                    openPrintModal($li.getAttribute('data-print-mode'));
                    return;
                }
                $btnPrintWrap.classList.toggle('open');
            });
            document.addEventListener('click', e => {
                if ($btnPrintWrap && !$btnPrintWrap.contains(e.target)) $btnPrintWrap.classList.remove('open');
            });
        } else if ($btnPrint) {
            $btnPrint.addEventListener('click', () => openPrintModal('plain'));
        }
        if ($printClose)   $printClose.addEventListener('click', closePrintModal);
        if ($printOverlay) $printOverlay.addEventListener('click', closePrintModal);
        if ($printDo)      $printDo.addEventListener('click', () => window.print());
        if ($printShare)   $printShare.addEventListener('click', sharePrintSheet);
        if (!$printSheet) return;

        $printSheet.addEventListener('click', e => {
            const $wrap = e.target.closest('.pr-editable');
            if ($wrap && !$wrap.classList.contains('editing')) { e.preventDefault(); enterEdit($wrap); }
        });
        $printSheet.addEventListener('blur', e => {
            if (e.target.classList && e.target.classList.contains('pr-ed-text')) {
                commitEdit(e.target.closest('.pr-editable'));
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
        document.addEventListener('keydown', e => {
            if (e.key === 'Escape' && $printModal && $printModal.style.display !== 'none') closePrintModal();
        });
    }

    // Share: chụp #print-sheet → PNG → clipboard. User Ctrl+V sang Zalo/Messenger...
    async function sharePrintSheet() {
        if (typeof window.html2canvas !== 'function') {
            alert('Không nạp được html2canvas. Kiểm tra mạng rồi thử lại.');
            return;
        }
        if (!navigator.clipboard || typeof window.ClipboardItem !== 'function') {
            alert('Trình duyệt không hỗ trợ copy ảnh vào clipboard.');
            return;
        }
        const orig = $printShare.textContent;
        $printShare.textContent = 'Đang xử lý...';
        $printShare.disabled = true;
        try {
            const canvas = await window.html2canvas($printSheet, {
                backgroundColor: '#ffffff', scale: 2, useCORS: true, logging: false,
            });
            const blob = await new Promise((res, rej) => {
                canvas.toBlob(b => b ? res(b) : rej(new Error('toBlob trả về null')), 'image/png');
            });
            await navigator.clipboard.write([ new ClipboardItem({ 'image/png': blob }) ]);
            alert('Đã copy ảnh phiếu nhập kho vào clipboard.\nMở app khác (Zalo, Messenger...) và bấm Ctrl+V để dán.');
        } catch (err) {
            console.error('Share error:', err);
            alert('Không thể chia sẻ: ' + (err && err.message ? err.message : err));
        } finally {
            $printShare.textContent = orig;
            $printShare.disabled = false;
        }
    }

    /* ============================================
       Wire-up events
       ============================================ */
    function init() {
        // Datetime: now
        $dateTime.value = nowLocalValue();

        // JE defaults
        setupJe();

        // Modal in phiếu nhập kho
        setupPrintModal();

        // Initial render
        clearForm();  // wipes editing state, adds 1 empty row, sets datetime, sets JE defaults
        setupHistoryFilter();
        renderHistoryPage();

        // Supplier search
        $search.addEventListener('input', runSupplierSearch);
        $search.addEventListener('focus', runSupplierSearch);
        $search.addEventListener('keydown', e => {
            if (!$dropdown.classList.contains('active')) return;
            const lis = $dropdown.querySelectorAll('li:not(.empty)');
            if (e.key === 'ArrowDown') {
                e.preventDefault();
                supActiveIdx = Math.min(supActiveIdx + 1, lis.length - 1);
                updateSupActive(lis);
            } else if (e.key === 'ArrowUp') {
                e.preventDefault();
                supActiveIdx = Math.max(supActiveIdx - 1, 0);
                updateSupActive(lis);
            } else if (e.key === 'Enter' || e.key === 'Tab') {
                // Enter hoặc Tab đều chọn item đang active (hoặc item đầu nếu chưa di chuyển).
                const idx = supActiveIdx >= 0 ? supActiveIdx : 0;
                if (lis[idx]) { e.preventDefault(); pickSupplier(lis[idx]); }
            } else if (e.key === 'Escape') {
                hideSupDropdown();
            }
        });
        $dropdown.addEventListener('mousedown', e => {
            const li = e.target.closest('li');
            if (li && !li.classList.contains('empty')) {
                e.preventDefault();
                pickSupplier(li);
            }
        });
        document.addEventListener('click', e => {
            if (!$search.contains(e.target) && !$dropdown.contains(e.target)) hideSupDropdown();
        });

        // Remove supplier pill
        $listSupplier.addEventListener('click', e => {
            if (e.target.closest('.btn-remove-supplier')) setSelectedSupplier(null);
        });

        // Datetime change → resync interpretation auto-built ở backend; ko cần xử lý client
        $dateTime.addEventListener('change', () => {});

        // Add row
        $btnAddRow.addEventListener('click', () => {
            addRow();
            recalcTotals();
        });

        // Row events (delegate on tbody)
        $tbody.addEventListener('input', e => {
            const $tr = e.target.closest('tr');
            if (!$tr) return;
            if (e.target.classList.contains('cell-name')) {
                e.target.dataset.itemType   = '';
                e.target.dataset.materialId = '0';
                e.target.dataset.productId  = '0';
                setWarehouseCell($tr, '');
                const kw = e.target.value.trim();
                if (kw === '') { hideMaterialDropdown($tr); return; }
                runMaterialSearch($tr, kw);
            } else if (
                e.target.classList.contains('cell-quantity') ||
                e.target.classList.contains('cell-price')    ||
                e.target.classList.contains('cell-vat')      ||
                e.target.classList.contains('cell-other-cost')
            ) {
                recalcRow($tr);
            }
        });
        // Tick/bỏ tick "Gồm CP khác" → tính lại VAT + hiệu ứng chớp sáng.
        $tbody.addEventListener('change', e => {
            const $tr = e.target.closest('tr');
            if (!$tr) return;
            if (e.target.classList.contains('cell-vat-incl-check')) {
                recalcRow($tr, { flash: true });
            }
        });
        // Bàn phím: điều hướng dropdown NVL trên .cell-name + Alt+Shift+↓ chèn dòng.
        $tbody.addEventListener('keydown', e => {
            const $tr = e.target.closest('tr');
            if (!$tr) return;
            // Alt+Shift+ArrowDown: chèn thêm 1 dòng NVL (phụ trợ nút "+ Thêm dòng").
            if (e.altKey && e.shiftKey && e.key === 'ArrowDown') {
                e.preventDefault();
                const $newTr = addRow();
                recalcTotals();
                const $newName = $newTr.querySelector('.cell-name');
                if ($newName) $newName.focus();
                return;
            }
            // Điều hướng dropdown gợi ý NVL (mũi tên / Enter / Tab) trên .cell-name.
            if (!e.target.classList.contains('cell-name')) return;
            const $dd = $tr.querySelector('.material-dropdown');
            if (!$dd || !$dd.classList.contains('active')) return;
            const lis = $dd.querySelectorAll('li:not(.empty)');
            if (!lis.length) return;
            let idx = -1;
            lis.forEach((li, i) => { if (li.classList.contains('active')) idx = i; });
            if (e.key === 'ArrowDown') {
                e.preventDefault();
                setMatActive(lis, (idx + 1) % lis.length);
            } else if (e.key === 'ArrowUp') {
                e.preventDefault();
                setMatActive(lis, (idx - 1 + lis.length) % lis.length);
            } else if (e.key === 'Enter') {
                const pick = idx >= 0 ? idx : 0;
                if (lis[pick]) { e.preventDefault(); pickMaterial($tr, +lis[pick].getAttribute('data-id'), lis[pick].getAttribute('data-type')); }
            } else if (e.key === 'Tab') {
                // Tab: chọn item đang active NHƯNG không preventDefault — để trình duyệt tự chuyển
                // focus sang ô kế tiếp (bôi đen sẵn nội dung ô đó, xem re-select trong pickMaterial).
                const pick = idx >= 0 ? idx : 0;
                if (lis[pick]) pickMaterial($tr, +lis[pick].getAttribute('data-id'), lis[pick].getAttribute('data-type'));
            } else if (e.key === 'Escape') {
                hideMaterialDropdown($tr);
            }
        });
        $tbody.addEventListener('mousedown', e => {
            // chọn hàng hóa (NVL/thành phẩm) trong dropdown
            const li = e.target.closest('.material-dropdown li');
            if (li && !li.classList.contains('empty')) {
                e.preventDefault();
                const $tr = li.closest('tr');
                pickMaterial($tr, +li.getAttribute('data-id'), li.getAttribute('data-type'));
            }
        });
        $tbody.addEventListener('blur', e => {
            const $tr = e.target.closest('tr');
            if (!$tr) return;
            // Khi rời .cell-price → đồng bộ giá lên DB (live update material_purchase_prices)
            if (e.target.classList.contains('cell-price')) {
                const mid = parseInt($tr.querySelector('.cell-name').dataset.materialId || '0', 10);
                const price = parseNum(e.target.value);
                if (mid > 0 && price >= 0) {
                    postForm('update_material_price', { material_id: mid, price: price });
                }
            }
            // Đơn giá: giữ tối đa 4 chữ số thập phân (không làm tròn số nguyên). CP khác: vẫn làm
            // tròn tiền tệ như cũ (không thuộc phạm vi sửa lần này).
            if (e.target.classList.contains('cell-price')) {
                e.target.value = fmtPrice(parseNum(e.target.value));
            } else if (e.target.classList.contains('cell-other-cost')) {
                e.target.value = fmtMoney(parseNum(e.target.value));
            }
            // Khi rời .cell-unit → đồng bộ unit lên material_information
            if (e.target.classList.contains('cell-unit')) {
                const mid = parseInt($tr.querySelector('.cell-name').dataset.materialId || '0', 10);
                if (mid > 0) {
                    postForm('update_material_unit', { material_id: mid, unit: e.target.value.trim() });
                }
            }
            // Hide material dropdown khi rời .cell-name (delay để mousedown chọn được)
            if (e.target.classList.contains('cell-name')) {
                setTimeout(() => hideMaterialDropdown($tr), 150);
            }
        }, true);
        // Đơn giá / CP khác: bỏ định dạng "đ" + dấu phẩy khi vào ô để gõ số cho dễ.
        $tbody.addEventListener('focus', e => {
            if (e.target.classList.contains('cell-price') || e.target.classList.contains('cell-other-cost')) {
                const n = parseNum(e.target.value);
                e.target.value = n > 0 ? String(n) : '';
            }
        }, true);
        $tbody.addEventListener('click', e => {
            const btn = e.target.closest('.btn-remove-row');
            if (btn) {
                const $tr = btn.closest('tr');
                $tr.remove();
                if ($tbody.children.length === 0) addRow();
                recalcTotals();
                return;
            }
            // Icon giải thích CP khác: click để mở/đóng popup textarea.
            const noteIcon = e.target.closest('.cell-note-icon');
            if (noteIcon) {
                e.stopPropagation();
                const $wrap = noteIcon.closest('.cell-other-cost-wrap');
                const wasOpen = $wrap.classList.contains('note-open');
                closeAllNotePops();
                if (!wasOpen) {
                    $wrap.classList.add('note-open');
                    const $ta = $wrap.querySelector('.cell-note-input');
                    if ($ta) $ta.focus();
                }
            }
        });
        document.addEventListener('click', e => {
            if (!e.target.closest('.cell-other-cost-wrap')) closeAllNotePops();
        });

        // Record / Edit
        $btnRec.addEventListener('click', () => {
            if (editingGroupKey) {
                alert('Đang ở chế độ sửa. Hãy bấm "Sửa" để cập nhật, hoặc Hủy.');
                return;
            }
            doRecord();
        });
        $btnEdit.addEventListener('click', () => {
            if (!editingGroupKey) {
                alert('Chưa chọn nhóm để sửa. Hãy bấm "Sửa" ở 1 dòng lịch sử trước.');
                return;
            }
            doEdit();
        });
        $cancelEdit.addEventListener('click', e => {
            e.preventDefault();
            clearForm();
        });

        // History actions
        $histBody.addEventListener('click', e => {
            const a = e.target.closest('a[data-action]');
            if (!a) return;
            e.preventDefault();
            const $tr = a.closest('tr');
            const gk = $tr.getAttribute('data-group-key');
            const label = $tr.children[0].textContent + ' — ' + $tr.children[1].textContent;
            if (a.getAttribute('data-action') === 'edit') {
                loadBatchForEdit(gk);
            } else if (a.getAttribute('data-action') === 'delete') {
                doDelete(gk, label);
            }
        });

        // History pagination (client-side trên INITIAL.history đã lọc).
        $histPager.addEventListener('click', e => {
            const btn = e.target.closest('.page-btn');
            if (!btn || btn.hasAttribute('disabled')) return;
            const p = parseInt(btn.getAttribute('data-page'), 10);
            if (p >= 1) { historyPage = p; renderHistoryPage(); }
        });
    }

    function updateSupActive(lis) {
        lis.forEach((li, i) => li.classList.toggle('active', i === supActiveIdx));
        if (supActiveIdx >= 0 && lis[supActiveIdx]) {
            lis[supActiveIdx].scrollIntoView({ block: 'nearest' });
        }
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }
})();
