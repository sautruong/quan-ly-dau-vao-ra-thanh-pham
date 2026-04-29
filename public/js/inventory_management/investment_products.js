(function () {
    'use strict';

    const CFG = window.INVENTORY_CONFIG || { baseUrl: '?mod=inventory_management&controllers=inventory_management&action=' };
    const INITIAL = window.INVENTORY_DATA || { items: [], planDate: '', history: [], typeImport: 'investment_production' };
    const TYPE_IMPORT = INITIAL.typeImport || 'investment_production';
    const PAGE_SIZE = 10;

    let editingBatchKey = null;
    let historyPage = 1;
    let currentItems = (INITIAL.items || []).slice(); // last items loaded by date

    const $list      = document.getElementById('list-product');
    const $btnRec    = document.getElementById('btn-record');
    const $btnEdit   = document.getElementById('btn-edit');
    const $histBody  = document.getElementById('history-tbody');
    const $histPager = document.getElementById('history-pagination');
    const $banner    = document.getElementById('edit-batch-banner');
    const $bannerLb  = document.getElementById('edit-batch-label');
    const $btnCancel = document.getElementById('cancel-edit-batch');
    const $dateTime  = document.getElementById('record-datetime');
    const $totalCost = document.querySelector('#total-list .total-list-value-cost');
    const $totalVal  = document.querySelector('#total-list-value .total-list-value-amount');

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

    function escapeHtml(s) {
        return String(s == null ? '' : s)
            .replace(/&/g, '&amp;').replace(/</g, '&lt;')
            .replace(/>/g, '&gt;').replace(/"/g, '&quot;').replace(/'/g, '&#39;');
    }

    function formatMoney(v) {
        const n = Math.round(Number(v) || 0);
        return n.toLocaleString('en-US') + ' đ';
    }

    function parseMoney(s) {
        return Number(String(s || '').replace(/[^\d.-]/g, '')) || 0;
    }

    function round2(n) {
        const x = Number(n) || 0;
        return Math.round(x * 100) / 100;
    }

    function formatQR2(v) { return round2(v).toFixed(2); }

    function formatTotalQty(v) {
        const n = Number(v) || 0;
        const r = Math.round(n * 100) / 100;
        return Number.isInteger(r) ? String(r) : r.toFixed(2).replace(/\.?0+$/, '');
    }

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
    function pickerToDateYMD() {
        const p = parseLocalValue($dateTime.value);
        if (!p) return '';
        return p.y + '-' + p.M + '-' + p.d;
    }
    function mysqlToLocalValue(s) {
        const m = /^(\d{4})-(\d{2})-(\d{2})[\sT](\d{2}):(\d{2}):(\d{2})$/.exec(String(s || ''));
        if (!m) return null;
        return `${m[1]}-${m[2]}-${m[3]}T${m[4]}:${m[5]}:${m[6]}`;
    }

    // ---------- Render product-item (FAQ-style materials) ----------
    function buildMaterialHTML(mat, productQty) {
        const qr        = Number(mat.quantity_required) || 0;
        const price     = Number(mat.purchase_price)    || 0;
        const totalQty  = qr * productQty;
        const totalCost = totalQty * price;
        return `
            <li class="material-item"
                data-material-id="${mat.material_id}"
                data-quantity-required="${qr}"
                data-purchase-price="${price}">
                <div class="name-material">
                    <p>${escapeHtml(mat.material_name)}</p>
                    <input type="text" class="input-formula" value="${formatQR2(qr)}x${productQty}" readonly>
                    <input type="text" class="input-total-qty" value="${formatTotalQty(totalQty)}">
                    <input type="text" class="input-cost" value="${formatMoney(totalCost)}" readonly>
                </div>
            </li>
        `;
    }

    function addProductItem(p) {
        const pid        = p.product_id;
        const productQty = Number(p.quantity) || 0;
        const sysPrice   = Number(p.system_price) || 0;
        const materials  = Array.isArray(p.materials) ? p.materials : [];

        const matsHtml = materials.map(m => buildMaterialHTML(m, productQty)).join('');

        const li = document.createElement('li');
        li.className = 'product-item';
        li.setAttribute('data-product-id', pid);
        li.setAttribute('data-system-price', sysPrice);
        li.setAttribute('data-quantity', productQty);
        li.innerHTML = `
            <button type="button" class="btn-remove-product" title="Xóa">×</button>
            <div class="wp-top-product">
                <div class="name-product">
                    <p>${escapeHtml(p.product_name || '')}</p>
                </div>
                <input type="text" class="input-quantity" value="${productQty}" placeholder="SL" readonly>
            </div>
            <div class="interpretation">
                <p class="interp-text">Nhập giá vốn sản xuất ${escapeHtml(p.product_name || '')}</p>
            </div>
            <div class="material-faq">
                <button type="button" class="material-faq-header" aria-expanded="false">
                    <span class="faq-label">Chi tiết nguyên liệu (${materials.length})</span>
                    <span class="faq-icon">▾</span>
                </button>
                <div class="material-faq-body">
                    <ul class="list-material">${matsHtml}</ul>
                </div>
            </div>
            <div class="sub-total">
                <div class="title-total"><p>Tổng vốn:</p></div>
                <div class="total"><p>0 đ</p></div>
                <div class="value-total"><p>Giá trị:</p></div>
                <div class="total"><p>0 đ</p></div>
            </div>
        `;

        li.querySelector('.btn-remove-product').addEventListener('click', () => {
            li.remove();
            recomputeAllTotals();
        });

        $list.appendChild(li);
        recomputeProductSubtotals(li);
    }

    function clearList() { $list.innerHTML = ''; }

    function renderListFrom(items) {
        clearList();
        (items || []).forEach(it => addProductItem(it));
        recomputeAllTotals();
    }

    // ---------- Recompute helpers ----------
    function recomputeMaterialRow($li, productQty) {
        const qr    = Number($li.getAttribute('data-quantity-required')) || 0;
        const price = Number($li.getAttribute('data-purchase-price')) || 0;
        const totalQty  = qr * productQty;
        const totalCost = totalQty * price;
        $li.querySelector('.input-formula').value   = formatQR2(qr) + 'x' + productQty;
        $li.querySelector('.input-total-qty').value = formatTotalQty(totalQty);
        $li.querySelector('.input-cost').value      = formatMoney(totalCost);
    }

    // Đọc total_qty từ chính .input-total-qty để tôn trọng đúng giá trị user đã nhập
    // (không reformat / không tính lại từ qr × productQty — tránh nhảy số).
    function readMaterialTotalQty($li) {
        const $inp = $li.querySelector('.input-total-qty');
        return parseFloat($inp ? $inp.value : '0') || 0;
    }

    function recomputeProductSubtotals($product) {
        const productQty = Number($product.getAttribute('data-quantity')) || 0;
        const sysPrice   = Number($product.getAttribute('data-system-price')) || 0;
        let subtotalCost = 0;
        $product.querySelectorAll('.material-item').forEach($li => {
            const totalQty = readMaterialTotalQty($li);
            const price    = Number($li.getAttribute('data-purchase-price')) || 0;
            subtotalCost += totalQty * price;
        });
        const totals = $product.querySelectorAll('.sub-total .total p');
        if (totals[0]) totals[0].textContent = formatMoney(subtotalCost);
        if (totals[1]) totals[1].textContent = formatMoney(productQty * sysPrice);
    }

    function recomputeGrandTotals() {
        let totalCost = 0, totalValue = 0;
        $list.querySelectorAll('.product-item').forEach($p => {
            const totals = $p.querySelectorAll('.sub-total .total p');
            if (totals[0]) totalCost  += parseMoney(totals[0].textContent);
            if (totals[1]) totalValue += parseMoney(totals[1].textContent);
        });
        if ($totalCost) $totalCost.textContent = formatMoney(totalCost);
        if ($totalVal)  $totalVal.textContent  = formatMoney(totalValue);
    }

    // Hàm tổng hợp: gọi khi bất kỳ thay đổi nào ảnh hưởng đến số liệu hiển thị.
    function recomputeAllTotals() {
        $list.querySelectorAll('.product-item').forEach($p => recomputeProductSubtotals($p));
        recomputeGrandTotals();
    }

    function readGrandTotals() {
        return {
            cost_price:  $totalCost ? parseMoney($totalCost.textContent) : 0,
            goods_value: $totalVal  ? parseMoney($totalVal.textContent)  : 0,
        };
    }

    // Build payload items[] có materials từ DOM hiện tại — dùng cho cả Ghi và Sửa.
    function collectItemsForPayload() {
        const items = [];
        $list.querySelectorAll('.product-item').forEach($p => {
            const pid = parseInt($p.getAttribute('data-product-id'), 10);
            if (!pid) return;
            const productQty = Number($p.getAttribute('data-quantity')) || 0;
            const materials = [];
            $p.querySelectorAll('.material-item').forEach($li => {
                const mid   = parseInt($li.getAttribute('data-material-id'), 10);
                if (!mid) return;
                const price     = Number($li.getAttribute('data-purchase-price')) || 0;
                const total_qty = readMaterialTotalQty($li);
                materials.push({
                    material_id: mid,
                    total_qty:   total_qty,
                    unit_price:  price,
                    total_cost:  total_qty * price,
                });
            });
            items.push({ product_id: pid, materials });
        });
        return items;
    }

    // ---------- Edit material total-qty (the "144" input) ----------
    $list.addEventListener('change', (e) => {
        const $inp = e.target.closest('.input-total-qty');
        if (!$inp) return;
        const $li      = $inp.closest('.material-item');
        const $product = $inp.closest('.product-item');
        if (!$li || !$product) return;

        const productQty = Number($product.getAttribute('data-quantity')) || 0;
        if (productQty <= 0) {
            alert('Số lượng sản phẩm không hợp lệ — chưa có dữ liệu nhập kho cho ngày này.');
            recomputeMaterialRow($li, productQty);
            return;
        }
        const newTotal = Number($inp.value);
        if (!isFinite(newTotal) || newTotal < 0) {
            recomputeMaterialRow($li, productQty);
            return;
        }
        // qty_required mới = total / quantity (tròn 2 chữ số) — chỉ dùng cho .input-formula
        // và lưu DB. KHÔNG ghi đè .input-total-qty (giữ đúng giá trị user vừa nhập).
        const newQR = round2(newTotal / productQty);
        $li.setAttribute('data-quantity-required', newQR);
        const price = Number($li.getAttribute('data-purchase-price')) || 0;
        $li.querySelector('.input-formula').value = formatQR2(newQR) + 'x' + productQty;
        $li.querySelector('.input-cost').value    = formatMoney(newTotal * price);
        recomputeAllTotals();

        const pid = parseInt($product.getAttribute('data-product-id'), 10);
        const mid = parseInt($li.getAttribute('data-material-id'), 10);
        postForm('update_material_qty', {
            product_id:        pid,
            material_id:       mid,
            quantity_required: newQR
        }).then(res => {
            if (!res || !res.success) {
                alert((res && res.message) ? res.message : 'Không lưu được giá trị.');
            }
        });
    });

    // ---------- FAQ toggle ----------
    $list.addEventListener('click', (e) => {
        const $hdr = e.target.closest('.material-faq-header');
        if (!$hdr) return;
        const $faq = $hdr.parentElement;
        const open = $faq.classList.toggle('open');
        $hdr.setAttribute('aria-expanded', open ? 'true' : 'false');
    });

    // ---------- Date-driven reload ----------
    function reloadItemsForCurrentDate() {
        const date = pickerToDateYMD();
        if (!date) return Promise.resolve([]);
        return postForm('get_investment_items_for_date', { date }).then(res => {
            const items = (res && res.success && Array.isArray(res.data)) ? res.data : [];
            currentItems = items;
            if (!editingBatchKey) renderListFrom(items);
            return items;
        });
    }

    // ---------- Edit batch mode ----------
    function enterEditMode(batch) {
        editingBatchKey = batch.group_key;
        const localVal = mysqlToLocalValue(batch.created_at);
        if (localVal) $dateTime.value = localVal;

        // Load list-material từ stock_exports lịch sử của batch
        postForm('get_investment_batch_detail', { group_key: batch.group_key }).then(res => {
            const items = (res && res.success && Array.isArray(res.data)) ? res.data : [];
            renderListFrom(items);
            $banner.style.display = 'flex';
            $bannerLb.textContent = batch.date_display;
            $btnRec.style.display = 'none';
            document.querySelector('.content').scrollIntoView({ behavior: 'smooth' });
        });
    }

    function exitEditMode() {
        editingBatchKey = null;
        $banner.style.display = 'none';
        $btnRec.style.display = '';
        renderListFrom(currentItems);
    }

    $btnCancel.addEventListener('click', (e) => {
        e.preventDefault();
        exitEditMode();
    });

    // ---------- Picker change ----------
    $dateTime.addEventListener('change', () => {
        if (editingBatchKey) return;
        reloadItemsForCurrentDate();
    });

    // ---------- Record / Edit buttons ----------
    function flashActive(btn) {
        [$btnRec, $btnEdit].forEach(b => b && b.classList.remove('active'));
        if (btn) btn.classList.add('active');
    }

    function checkDuplicatesThen(productIds, onOk) {
        if (!productIds.length) { onOk(); return; }
        postForm('check_investment_duplicates', {
            product_ids: JSON.stringify(productIds),
            plan_date:   pickerToDateYMD()
        }).then(res => {
            const dups = (res && res.data) || [];
            if (!dups.length) { onOk(); return; }
            const lines = dups.map(d => 'Sản phẩm "' + d.product_name + '" đã có phiếu giá vốn sản xuất ngày ' + d.date_vn + '.');
            const msg = lines.join('\n') + '\n\nVẫn ghi tiếp?';
            if (confirm(msg)) onOk();
        });
    }

    $btnRec.addEventListener('click', () => {
        if (editingBatchKey) return;
        const items = collectItemsForPayload();
        if (!items.length) {
            alert('Chưa có sản phẩm nào để ghi.');
            return;
        }
        const pids = items.map(it => it.product_id);
        checkDuplicatesThen(pids, () => {
            if (!confirm('Ghi giá vốn sản xuất các sản phẩm hiện tại?')) return;
            flashActive($btnRec);
            const totals = readGrandTotals();
            postForm('record_investment', {
                items:       JSON.stringify(items),
                cost_price:  totals.cost_price,
                goods_value: totals.goods_value,
                created_at:  pickerToMysql()
            }).then(res => {
                if (res && res.success) {
                    alert('Đã ghi phiếu giá vốn sản xuất.');
                    renderHistory(res.history || []);
                } else {
                    alert(res && res.message ? res.message : 'Có lỗi xảy ra.');
                }
            });
        });
    });

    $btnEdit.addEventListener('click', () => {
        if (!editingBatchKey) {
            alert('Hãy chọn "Sửa" ở bảng Lịch sử để chỉnh nhóm cần sửa.');
            return;
        }
        flashActive($btnEdit);
        const totals = readGrandTotals();
        const items  = collectItemsForPayload();
        postForm('edit_investment_batch', {
            group_key:   editingBatchKey,
            items:       JSON.stringify(items),
            cost_price:  totals.cost_price,
            goods_value: totals.goods_value
        }).then(res => {
            if (res && res.success) {
                alert('Đã lưu các thay đổi giá vốn của nhóm.');
                renderHistory(res.history || []);
                exitEditMode();
            } else {
                alert(res && res.message ? res.message : 'Có lỗi xảy ra.');
            }
        });
    });

    // ---------- History (with pagination) ----------
    function renderHistory(batches) {
        INITIAL.history = batches || [];
        historyPage = 1;
        renderHistoryPage();
    }

    function renderHistoryPage() {
        const data = INITIAL.history || [];
        const totalPages = Math.max(1, Math.ceil(data.length / PAGE_SIZE));
        if (historyPage > totalPages) historyPage = totalPages;
        if (historyPage < 1) historyPage = 1;

        if (!data.length) {
            $histBody.innerHTML = '<tr class="history-empty"><td colspan="3">Chưa có thao tác nào.</td></tr>';
            $histPager.innerHTML = '';
            return;
        }

        const start = (historyPage - 1) * PAGE_SIZE;
        const slice = data.slice(start, start + PAGE_SIZE);
        $histBody.innerHTML = slice.map((b, i) => {
            const idx = start + i;
            return `
                <tr data-group-key="${escapeHtml(b.group_key)}" data-idx="${idx}">
                    <td>${escapeHtml(b.date_display)}</td>
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

    $histPager.addEventListener('click', (e) => {
        const $btn = e.target.closest('.page-btn');
        if (!$btn || $btn.disabled) return;
        if ($btn.classList.contains('page-prev')) historyPage = Math.max(1, historyPage - 1);
        else if ($btn.classList.contains('page-next')) historyPage = historyPage + 1;
        else if ($btn.classList.contains('page-num')) historyPage = parseInt($btn.getAttribute('data-page'), 10) || 1;
        renderHistoryPage();
    });

    $histBody.addEventListener('click', (e) => {
        const editLink = e.target.closest('.edit-list-import');
        const delLink  = e.target.closest('.delete-list-import');
        if (!editLink && !delLink) return;
        e.preventDefault();
        const tr = e.target.closest('tr');
        if (!tr) return;
        const idx = parseInt(tr.getAttribute('data-idx'), 10);
        const batch = (INITIAL.history || [])[idx];
        if (!batch) return;

        if (editLink) { enterEditMode(batch); return; }
        if (delLink) {
            if (!confirm('Xóa nhóm "' + batch.date_display + '"? Tồn nguyên liệu sẽ được hoàn lại.')) return;
            postForm('delete_investment_batch', { group_key: batch.group_key }).then(res => {
                if (res && res.success) {
                    alert('Đã xóa.');
                    renderHistory(res.history || []);
                    if (editingBatchKey === batch.group_key) exitEditMode();
                } else {
                    alert(res && res.message ? res.message : 'Có lỗi xảy ra.');
                }
            });
        }
    });

    // ---------- Init ----------
    function init() {
        $dateTime.value = nowLocalValue();
        renderListFrom(currentItems);
        renderHistory(INITIAL.history || []);
    }

    init();
})();
