(function () {
    'use strict';

    const CFG = window.INVENTORY_CONFIG || { baseUrl: '?mod=warehouse_outbound&controllers=warehouse_outbound&action=' };
    const INITIAL = window.INVENTORY_DATA || { planDate: '', history: [], typeExport: 'other_export_outbound' };

    const $search   = document.getElementById('search-product');
    const $dropdown = document.getElementById('search-dropdown');
    const $list     = document.getElementById('list-product');
    const $btnRec   = document.getElementById('btn-record');
    const $btnEdit  = document.getElementById('btn-edit');
    const $histBody = document.getElementById('history-tbody');
    const $histPager = document.getElementById('history-pagination');
    const $hfDateFrom = document.getElementById('hf-date-from');
    const $hfDateTo   = document.getElementById('hf-date-to');
    const $hfPageSize = document.getElementById('hf-page-size');
    const $hfReset    = document.getElementById('hf-reset');
    const $hfCount    = document.getElementById('hf-count');
    const $hfKeyword    = document.getElementById('hf-keyword');
    const $hfKeywordBtn = document.getElementById('hf-keyword-btn');
    const $hfKeywordPop = document.getElementById('hf-keyword-pop');
    const $banner   = document.getElementById('edit-batch-banner');
    const $bannerLb = document.getElementById('edit-batch-label');
    const $btnCancelEdit = document.getElementById('cancel-edit-batch');
    const $dateTime = document.getElementById('record-datetime');
    const $jeDebit  = document.getElementById('je-debit');
    const $jeCredit = document.getElementById('je-credit');
    const $jeAmount = document.getElementById('je-amount');

    let pageSize = 10; // số dòng/trang — đổi qua select #hf-page-size
    let historyPage = 1;
    let dropdownItems = [];
    let activeIdx = -1;
    const addedKeys = new Set();
    let editingBatchKey = null;
    // Bộ lọc lịch sử (client-side trên INITIAL.history đã nạp sẵn).
    const histFilter = { keyword: '', dateFrom: '', dateTo: '' };

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

    function formatAmount(n) {
        const v = Math.round(Number(n) || 0);
        return v > 0 ? v.toLocaleString('en-US') : '';
    }

    function autoResize(el) {
        if (!el || el.tagName !== 'TEXTAREA') return;
        el.style.height = 'auto';
        el.style.height = el.scrollHeight + 'px';
    }

    function itemKey(id, type) { return type + ':' + id; }

    // ---------- Datetime picker ----------
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

    // ---------- Search ----------
    const runSearch = debounce(function () {
        const kw = $search.value.trim();
        if (kw === '') { hideDropdown(); return; }
        postForm('search_product_or_material', { keyword: kw }).then(res => {
            renderDropdown(res.data || []);
        });
    }, 220);

    function renderDropdown(items) {
        dropdownItems = items;
        activeIdx = -1;
        if (!items.length) {
            $dropdown.innerHTML = '<li class="empty">Không tìm thấy hàng hóa</li>';
        } else {
            $dropdown.innerHTML = items.map((it, i) =>
                `<li data-id="${it.id}" data-type="${escapeHtml(it.type)}" data-idx="${i}">${escapeHtml(it.name)}</li>`
            ).join('');
        }
        $dropdown.classList.add('active');
    }

    function hideDropdown() {
        $dropdown.classList.remove('active');
        $dropdown.innerHTML = '';
        activeIdx = -1;
    }

    function selectDropdownItem(el) {
        const id   = parseInt(el.getAttribute('data-id'), 10);
        const type = el.getAttribute('data-type') || 'product';
        if (!id || Number.isNaN(id)) return;
        const key = itemKey(id, type);
        if (addedKeys.has(key)) {
            alert('Hàng hóa đã có trong danh sách.');
            hideDropdown();
            return;
        }
        postForm('get_item_defaults', { id: id, type: type }).then(res => {
            if (!res.success) { alert(res.message || 'Lỗi'); return; }
            addProductItem({
                id:        res.data.id,
                type:      res.data.type,
                name:      res.data.name,
                warehouse: res.data.warehouse,
                unit_cost: Number(res.data.unit_cost) || 0,
                quantity:  '',
                interpretation: '',
            });
            $search.value = '';
            hideDropdown();
            recomputeJE();
        });
    }

    $search.addEventListener('input', runSearch);
    $search.addEventListener('focus', function () {
        if ($search.value.trim() !== '' && dropdownItems.length) {
            $dropdown.classList.add('active');
        }
    });
    $search.addEventListener('keydown', function (e) {
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
            if (items[idx]) { e.preventDefault(); selectDropdownItem(items[idx]); }
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

    $dropdown.addEventListener('click', function (e) {
        const li = e.target.closest('li');
        if (!li || li.classList.contains('empty')) return;
        selectDropdownItem(li);
    });

    document.addEventListener('click', function (e) {
        if (!e.target.closest('.wp-search')) hideDropdown();
    });

    // ---------- Render product-item ----------
    function addProductItem(p) {
        const id   = p.id;
        const type = p.type || 'product';
        const key  = itemKey(id, type);
        const qty  = (p.quantity === 0 || p.quantity) ? p.quantity : '';
        const name = p.name || '';
        const warehouse = p.warehouse || (type === 'material' ? 'Kho NVL' : 'Kho TP');
        const unitCost  = Number(p.unit_cost) || 0;
        const interp = p.interpretation || '';
        const exportId = p.export_id ? p.export_id : '';

        const li = document.createElement('li');
        li.className = 'product-item';
        li.setAttribute('data-id', id);
        li.setAttribute('data-type', type);
        li.setAttribute('data-unit-cost', unitCost);
        if (exportId) li.setAttribute('data-export-id', exportId);
        li.innerHTML = `
            <button type="button" class="btn-remove-product" title="Xóa">×</button>
            <div class="wp-top-product">
                <div class="name-product">
                    <p>${escapeHtml(name)}</p>
                </div>
                <input type="text" class="input-quantity" value="${escapeHtml(qty)}" placeholder="SL">
            </div>
            <div class="inventory">
                <p>${escapeHtml(warehouse)}</p>
            </div>
            <div class="interpretation">
                <textarea class="input-interpretation" rows="1" placeholder="Nhập diễn giải...">${escapeHtml(interp)}</textarea>
            </div>
        `;

        li.querySelector('.btn-remove-product').addEventListener('click', () => {
            li.remove();
            addedKeys.delete(key);
            recomputeJE();
        });

        li.querySelector('.input-quantity').addEventListener('input', recomputeJE);

        const $interp = li.querySelector('.input-interpretation');
        $interp.addEventListener('input', () => autoResize($interp));
        requestAnimationFrame(() => autoResize($interp));

        $list.appendChild(li);
        addedKeys.add(key);
    }

    function clearList() {
        $list.innerHTML = '';
        addedKeys.clear();
    }

    // ---------- JE recompute ----------
    function recomputeJE() {
        let tpTotal = 0, nvlTotal = 0;
        $list.querySelectorAll('.product-item').forEach(li => {
            const type = li.getAttribute('data-type') || 'product';
            const unit = Number(li.getAttribute('data-unit-cost')) || 0;
            const qty  = parseFloat((li.querySelector('.input-quantity') || {}).value || '0') || 0;
            const amt  = unit * qty;
            if (type === 'material') nvlTotal += amt;
            else                      tpTotal  += amt;
        });

        const debits = [];
        const credits = [];
        if (tpTotal > 0)  { debits.push('632'); credits.push('155'); }
        if (nvlTotal > 0) { debits.push('621'); credits.push('152'); }

        $jeDebit.value  = debits.join(',');
        $jeCredit.value = credits.join(',');
        $jeAmount.value = formatAmount(tpTotal + nvlTotal);
    }

    function collectItemsForSubmit() {
        const items = [];
        $list.querySelectorAll('.product-item').forEach(li => {
            const id   = parseInt(li.getAttribute('data-id'), 10);
            const type = li.getAttribute('data-type') || 'product';
            const unit = Number(li.getAttribute('data-unit-cost')) || 0;
            const qty  = parseFloat((li.querySelector('.input-quantity') || {}).value || '0') || 0;
            const ip   = (li.querySelector('.input-interpretation') || {}).value || '';
            if (!id || qty <= 0) return;
            items.push({
                id: id,
                type: type,
                quantity: qty,
                unit_price: unit,
                total_amount: unit * qty,
                interpretation: ip,
            });
        });
        return items;
    }

    // ---------- Edit batch mode ----------
    function enterEditMode(batch) {
        editingBatchKey = batch.group_key;
        // Chỉ đổ lại đúng các nghiệp vụ kế toán đã nhập cho phiếu này (không phải toàn
        // bộ thư viện template). Phiếu cũ chưa có dữ liệu → reset 1 cụm trống.
        if (window.JE && window.JE.setEntries) window.JE.setEntries(batch.je_entries || []);
        const localVal = mysqlToLocalValue(batch.created_at);
        if (localVal) $dateTime.value = localVal;
        clearList();
        (batch.items || []).forEach(it => {
            const unitCost = (it.quantity > 0) ? ((Number(it.total_amount) || 0) / it.quantity) : (Number(it.unit_price) || 0);
            addProductItem({
                id:         it.id,
                type:       it.type,
                name:       it.name,
                warehouse:  it.warehouse,
                unit_cost:  unitCost,
                quantity:   it.quantity,
                interpretation: it.interpretation || '',
                export_id:  it.export_id,
            });
        });
        recomputeJE();
        $banner.style.display = 'flex';
        $bannerLb.textContent = batch.date_display;
        $btnRec.style.display = 'none';
        if ($btnEdit) $btnEdit.style.display = '';
        document.querySelector('.content').scrollIntoView({ behavior: 'smooth' });
    }

    function exitEditMode() {
        editingBatchKey = null;
        $banner.style.display = 'none';
        $btnRec.style.display = '';
        if ($btnEdit) $btnEdit.style.display = 'none';
        clearList();
        if (window.JE && window.JE.reset) window.JE.reset();
        recomputeJE();
    }

    $btnCancelEdit.addEventListener('click', (e) => {
        e.preventDefault();
        exitEditMode();
    });

    // ---------- Record / Edit ----------
    function flashActive(btn) {
        [$btnRec, $btnEdit].forEach(b => b && b.classList.remove('active'));
        if (btn) btn.classList.add('active');
    }

    $btnRec.addEventListener('click', () => {
        if (editingBatchKey) return;
        const items = collectItemsForSubmit();
        if (!items.length) {
            alert('Chưa có hàng hóa nào có số lượng hợp lệ để ghi.');
            return;
        }
        if (!confirm('Ghi xuất kho khác?')) return;
        flashActive($btnRec);
        postForm('record_other_outbound', {
            items:      JSON.stringify(items),
            created_at: pickerToMysql(),
            je_entries: (window.JE && window.JE.collectEntries) ? JSON.stringify(window.JE.collectEntries()) : '[]',
        }).then(res => {
            if (res && res.success) {
                if (window.appFlyToHistory) window.appFlyToHistory($btnRec);
                setTimeout(() => window.location.reload(), 950);
            } else {
                alert(res && res.message ? res.message : 'Có lỗi xảy ra.');
            }
        });
    });

    $btnEdit.addEventListener('click', () => {
        if (!editingBatchKey) {
            alert('Hãy chọn "Sửa" ở bảng Lịch sử để chỉnh nhóm cần sửa.');
            return;
        }
        const items = collectItemsForSubmit();
        if (!items.length) {
            alert('Không có dữ liệu để cập nhật.');
            return;
        }
        flashActive($btnEdit);
        postForm('edit_other_outbound_batch', {
            group_key:  editingBatchKey,
            items:      JSON.stringify(items),
            created_at: pickerToMysql(),
            je_entries: (window.JE && window.JE.collectEntries) ? JSON.stringify(window.JE.collectEntries()) : '[]',
        }).then(res => {
            if (res && res.success) {
                if (window.appFlyToHistory) window.appFlyToHistory($btnEdit);
                renderHistory(res.history || []);
                exitEditMode();
            } else {
                alert(res && res.message ? res.message : 'Có lỗi xảy ra.');
            }
        });
    });

    // ---------- History ----------
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
                    String(it.name || it.product_name || '').toLowerCase().indexOf(kw) !== -1);
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
        // data-group-key là khóa duy nhất → handler Sửa/Xóa tra cứu theo nó,
        // không phụ thuộc index (an toàn khi đang lọc/phân trang).
        $histBody.innerHTML = slice.map(b => {
            return `
                <tr data-group-key="${escapeHtml(b.group_key)}">
                    <td class="${b.date_color ? 'hist-date-' + b.date_color : ''}">${escapeHtml(b.date_display)}</td>
                    <td title="${escapeHtml(b.summary)}">${escapeHtml(b.summary)}</td>
                    <td class="history-actions">
                        <a href="#" class="edit-list-import">Sửa</a>
                        <span class="sep">|</span>
                        <a href="#" class="delete-list-import">Xóa</a>
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
        const out = [];
        if (total <= 7) { for (let i = 1; i <= total; i++) out.push(i); return out; }
        out.push(1);
        if (current > 3) out.push('...');
        const from = Math.max(2, current - 1);
        const to   = Math.min(total - 1, current + 1);
        for (let i = from; i <= to; i++) out.push(i);
        if (current < total - 2) out.push('...');
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
        const editLink = e.target.closest('.edit-list-import');
        const delLink  = e.target.closest('.delete-list-import');
        if (!editLink && !delLink) return;
        e.preventDefault();
        const tr = e.target.closest('tr');
        if (!tr) return;
        const gk = tr.getAttribute('data-group-key');
        const batch = (INITIAL.history || []).find(b => b.group_key === gk);
        if (!batch) return;

        if (editLink) {
            enterEditMode(batch);
            return;
        }
        if (delLink) {
            if (!confirm('Xóa nhóm xuất ngày "' + batch.date_display + '"? Tồn kho sẽ được hoàn lại.')) return;
            postForm('delete_other_outbound_batch', { group_key: batch.group_key }).then(res => {
                if (res && res.success) {
                    alert('Đã xóa ' + res.removed + ' dòng.');
                    renderHistory(res.history || []);
                    if (editingBatchKey === batch.group_key) exitEditMode();
                } else {
                    alert(res && res.message ? res.message : 'Có lỗi xảy ra.');
                }
            });
        }
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
        recomputeJE();
        setupHistoryFilter();
        renderHistory(INITIAL.history || []);
    }

    init();
})();
