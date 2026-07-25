(function () {
    'use strict';

    const CFG = window.INVENTORY_CONFIG || { baseUrl: '?mod=inventory_receiving&controllers=inventory_receiving&action=' };
    const INITIAL = window.INVENTORY_DATA || { history: [], historyTotal: 0 };
    const JE_DEFAULTS = { debit: '152', credit: '711' };

    // ---------- State ----------
    let historyPage = 1;
    let pageSize = 10; // số dòng/trang — đổi qua select #hf-page-size
    // Bộ lọc lịch sử (client-side trên INITIAL.history đã nạp sẵn).
    const histFilter = { keyword: '', dateFrom: '', dateTo: '' };
    let editingGroupKey = null;   // null = chế độ Ghi mới; string = đang Sửa
    let jeAmountTouched = false;  // user đã tự gõ vào #je-amount → ngừng auto-sync

    // ---------- DOM refs ----------
    const $dateTime    = document.getElementById('record-datetime');
    const $general     = document.getElementById('general-interpretation');
    const $tbody       = document.getElementById('material-tbody');
    const $rowTpl      = document.getElementById('material-row-template');
    const $btnAddRow   = document.getElementById('btn-add-row');
    const $btnRec      = document.getElementById('btn-record');
    const $btnEdit     = document.getElementById('btn-edit');
    const $histBody    = document.getElementById('history-tbody');
    const $histPager   = document.getElementById('history-pagination');
    const $hfDateFrom  = document.getElementById('hf-date-from');
    const $hfDateTo    = document.getElementById('hf-date-to');
    const $hfPageSize  = document.getElementById('hf-page-size');
    const $hfReset     = document.getElementById('hf-reset');
    const $hfCount     = document.getElementById('hf-count');
    const $hfKeyword    = document.getElementById('hf-keyword');
    const $hfKeywordBtn = document.getElementById('hf-keyword-btn');
    const $hfKeywordPop = document.getElementById('hf-keyword-pop');
    const $banner      = document.getElementById('edit-batch-banner');
    const $bannerLabel = document.getElementById('edit-batch-label');
    const $cancelEdit  = document.getElementById('cancel-edit-batch');
    const $jeDebit     = document.getElementById('je-debit');
    const $jeCredit    = document.getElementById('je-credit');
    const $jeAmount    = document.getElementById('je-amount');

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
       Journal entry (bút toán kế toán)
       ============================================ */
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
    }

    /**
     * Tính tổng = Σ (quantity * price_effective) qua AJAX.
     * Server lấy price_effective từ material_purchase_prices:
     *   ưu tiên purchase_price_includes_purchase_cost (>0), fallback purchase_price.
     * Bỏ qua nếu user đã tự gõ vào #je-amount (jeAmountTouched).
     * Debounced 250ms để không spam khi gõ liên tục.
     */
    const recalcJeAmount = debounce(function () {
        if (jeAmountTouched || !$jeAmount) return;
        const items = [];
        $tbody.querySelectorAll('tr').forEach($tr => {
            const mid = parseInt($tr.querySelector('.cell-name').dataset.materialId || '0', 10);
            const qty = parseNum($tr.querySelector('.cell-quantity').value);
            if (mid > 0 && qty > 0) items.push({ material_id: mid, quantity: qty });
        });
        if (items.length === 0) {
            $jeAmount.value = '';
            return;
        }
        postForm('compute_other_je_amount', { items: JSON.stringify(items) }).then(res => {
            if (!res || res.success === false) return;
            if (jeAmountTouched) return; // user touched while AJAX was inflight
            const total = +res.amount || 0;
            $jeAmount.value = total > 0 ? Math.round(total).toLocaleString('en-US') : '';
        });
    }, 250);

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

    function applyRowPrefill($tr, p) {
        const inpName = $tr.querySelector('.cell-name');
        inpName.value = p.material_name || '';
        inpName.dataset.materialId = p.material_id || 0;
        $tr.querySelector('.cell-unit').value     = p.unit || '';
        $tr.querySelector('.cell-quantity').value = fmtQty(p.quantity || 0);
        $tr.querySelector('.cell-note').value     = p.note || '';
    }

    /* ============================================
       Material dropdown (per row, on .cell-name input)
       ============================================ */
    function showMaterialDropdown($tr, items) {
        const $dd = $tr.querySelector('.material-dropdown');
        if (!items.length) {
            $dd.innerHTML = '<li class="empty">Không tìm thấy NVL</li>';
        } else {
            $dd.innerHTML = items.map(it =>
                `<li data-id="${it.id}" data-unit="${escapeHtml(it.unit || '')}">${escapeHtml(it.material_name)}</li>`
            ).join('');
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
        postForm('search_materials', { keyword: kw }).then(res => {
            showMaterialDropdown($tr, res.data || []);
        });
    }, 220);

    function pickMaterial($tr, mid) {
        postForm('get_material_info', { material_id: mid }).then(res => {
            if (!res.success) { alert(res.message || 'Lỗi'); return; }
            const d = res.data;
            const $name = $tr.querySelector('.cell-name');
            $name.value = d.material_name;
            $name.dataset.materialId = d.id;
            $tr.querySelector('.cell-unit').value = d.unit || '';
            hideMaterialDropdown($tr);
            recalcJeAmount();
        });
    }

    /* ============================================
       Collect items / Save / Edit / Delete
       ============================================ */
    function collectItems() {
        const items = [];
        const errors = [];
        $tbody.querySelectorAll('tr').forEach(($tr, idx) => {
            const mid = parseInt($tr.querySelector('.cell-name').dataset.materialId || '0', 10);
            const name = $tr.querySelector('.cell-name').value.trim();
            const qty = parseNum($tr.querySelector('.cell-quantity').value);
            const note = $tr.querySelector('.cell-note').value.trim();
            if (!mid || !name) { errors.push(`Dòng ${idx + 1}: chưa chọn NVL.`); return; }
            if (qty <= 0)      { errors.push(`Dòng ${idx + 1}: số lượng phải > 0.`); return; }
            items.push({
                material_id: mid,
                quantity:    qty,
                note:        note,
            });
        });
        return { items, errors };
    }

    function doRecord() {
        const { items, errors } = collectItems();
        if (errors.length) { alert(errors.join('\n')); return; }
        if (!items.length) { alert('Chưa có dòng NVL nào.'); return; }

        const payload = Object.assign({
            items:                  JSON.stringify(items),
            general_interpretation: $general.value.trim(),
            created_at:             pickerToMysql(),
        }, jePayload());

        postForm('record_other_row_material', payload).then(res => {
            if (!res.success) { alert(res.message || 'Ghi không thành công.'); return; }
            if (window.appFlyToHistory) window.appFlyToHistory($btnRec);
            // TASK 1: reload lại trang để lấy ngày giờ ghi mới.
            setTimeout(() => window.location.reload(), 950);
        });
    }

    function doEdit() {
        if (!editingGroupKey) { alert('Chưa chọn nhóm để sửa.'); return; }
        const { items, errors } = collectItems();
        if (errors.length) { alert(errors.join('\n')); return; }
        if (!items.length) { alert('Chưa có dòng NVL nào.'); return; }

        const payload = Object.assign({
            group_key:              editingGroupKey,
            items:                  JSON.stringify(items),
            general_interpretation: $general.value.trim(),
            created_at:             pickerToMysql(),
        }, jePayload());

        postForm('edit_other_row_material', payload).then(res => {
            if (!res.success) { alert(res.message || 'Sửa không thành công.'); return; }
            if (window.appFlyToHistory) window.appFlyToHistory($btnEdit);
            applyHistoryResponse(res);
            clearForm();
        });
    }

    function loadBatchForEdit(groupKey) {
        postForm('get_other_batch', { group_key: groupKey }).then(res => {
            if (!res.success) { alert(res.message || 'Không tìm thấy nhóm.'); return; }
            const b = res.data;
            editingGroupKey = b.group_key;
            if (window.JE && window.JE.loadTemplatesAsBlocks) window.JE.loadTemplatesAsBlocks();
            $general.value = b.general_interp || '';
            const loc = mysqlToLocalValue(b.created_at);
            if (loc) $dateTime.value = loc;
            // JE: hiển thị giá trị đã lưu, cho phép recompute khi user sửa quantity
            if (b.je_debit)  $jeDebit.value  = b.je_debit;
            if (b.je_credit) $jeCredit.value = b.je_credit;
            jeAmountTouched = false;
            $jeAmount.value = b.je_amount > 0 ? Math.round(b.je_amount).toLocaleString('en-US') : '';
            // Rows
            $tbody.innerHTML = '';
            b.items.forEach(it => addRow(it));
            if ($tbody.children.length === 0) addRow();
            // Banner
            $banner.style.display = '';
            $bannerLabel.textContent = b.date_display + ' — ' + (b.summary || '');
            // TASK 1: nút "Sửa" chỉ hiện khi đang sửa 1 nhóm từ lịch sử; ẩn nút "Ghi".
            $btnEdit.style.display = '';
            $btnRec.style.display  = 'none';
            window.scrollTo({ top: 0, behavior: 'smooth' });
        });
    }

    function doDelete(groupKey, label) {
        if (!confirm(`Xác nhận xóa phiếu "${label}"?\n\nThao tác này sẽ rollback tồn kho và xóa toàn bộ dữ liệu liên quan.`)) return;
        postForm('delete_other_row_material', { group_key: groupKey }).then(res => {
            if (!res.success) { alert(res.message || 'Xóa không thành công.'); return; }
            applyHistoryResponse(res);
            if (editingGroupKey === groupKey) clearForm();
        });
    }

    function clearForm() {
        editingGroupKey = null;
        $general.value = '';
        $tbody.innerHTML = '';
        addRow();
        $dateTime.value = nowLocalValue();
        $banner.style.display = 'none';
        $bannerLabel.textContent = '';
        // TASK 1: chế độ Ghi mới → hiện nút "Ghi", ẩn nút "Sửa".
        $btnRec.style.display  = '';
        $btnEdit.style.display = 'none';
        if (window.JE && window.JE.reset) window.JE.reset();
        $jeDebit.value  = JE_DEFAULTS.debit;
        $jeCredit.value = JE_DEFAULTS.credit;
        $jeAmount.value = '';
        jeAmountTouched = false;
    }

    /* ============================================
       History render + filter + pagination (client-side)
       ============================================ */
    // Sau Sửa/Xóa server trả về history mới → cập nhật cache & render lại từ trang 1.
    function applyHistoryResponse(res) {
        if (res.history) INITIAL.history = res.history || [];
        historyPage = 1;
        renderHistoryPage();
    }

    // Ngày của batch dạng 'YYYY-MM-DD'. Ưu tiên created_at ('YYYY-MM-DD HH:MM:SS');
    // fallback parse từ date_display ('HH:ii:ss dd/mm/yyyy'). So sánh chuỗi đúng thứ tự.
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
                <td>${escapeHtml(b.summary)}</td>
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

    /* ============================================
       History filter wiring
       ============================================ */
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

    /* ============================================
       Wire-up events
       ============================================ */
    function init() {
        $dateTime.value = nowLocalValue();
        setupJe();
        clearForm();
        setupHistoryFilter();
        renderHistoryPage();

        // Add row
        $btnAddRow.addEventListener('click', () => {
            const $newTr = addRow();
            const $newName = $newTr.querySelector('.cell-name');
            if ($newName) $newName.focus();
        });

        // Row events (delegate on tbody)
        $tbody.addEventListener('input', e => {
            const $tr = e.target.closest('tr');
            if (!$tr) return;
            if (e.target.classList.contains('cell-name')) {
                const $name = $tr.querySelector('.cell-name');
                $name.dataset.materialId = '0';
                recalcJeAmount();
                const kw = e.target.value.trim();
                if (kw === '') { hideMaterialDropdown($tr); return; }
                runMaterialSearch($tr, kw);
            } else if (e.target.classList.contains('cell-quantity')) {
                recalcJeAmount();
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
            } else if (e.key === 'Enter' || e.key === 'Tab') {
                const pick = idx >= 0 ? idx : 0;
                if (lis[pick]) { e.preventDefault(); pickMaterial($tr, +lis[pick].getAttribute('data-id')); }
            } else if (e.key === 'Escape') {
                hideMaterialDropdown($tr);
            }
        });
        $tbody.addEventListener('mousedown', e => {
            const li = e.target.closest('.material-dropdown li');
            if (li && !li.classList.contains('empty')) {
                e.preventDefault();
                const $tr = li.closest('tr');
                pickMaterial($tr, +li.getAttribute('data-id'));
            }
        });
        $tbody.addEventListener('blur', e => {
            const $tr = e.target.closest('tr');
            if (!$tr) return;
            // Sync unit về DB khi rời .cell-unit
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
        $tbody.addEventListener('click', e => {
            const btn = e.target.closest('.btn-remove-row');
            if (btn) {
                const $tr = btn.closest('tr');
                $tr.remove();
                if ($tbody.children.length === 0) addRow();
                recalcJeAmount();
            }
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

        // History pagination
        $histPager.addEventListener('click', e => {
            const btn = e.target.closest('.page-btn');
            if (!btn || btn.hasAttribute('disabled')) return;
            const p = parseInt(btn.getAttribute('data-page'), 10);
            if (p >= 1) { historyPage = p; renderHistoryPage(); }
        });
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }
})();
