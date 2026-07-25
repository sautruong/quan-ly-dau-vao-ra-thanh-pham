(function () {
    'use strict';

    const CFG = window.INVENTORY_CONFIG || { baseUrl: '?mod=inventory_management&controllers=inventory_management&action=' };
    const INITIAL = window.INVENTORY_DATA || { items: [], planDate: '', history: [], typeImport: 'fg_receipt_production' };
    const TYPE_IMPORT = INITIAL.typeImport || 'fg_receipt_production';

    const addedProducts = new Set();
    let dropdownItems = [];
    let activeIdx = -1;
    let editingBatchKey = null; // null = chế độ Ghi mới; chuỗi = đang sửa batch này
    let historyPage = 1;
    let pageSize = 10; // số dòng/trang — đổi qua select #hf-page-size
    // Bộ lọc lịch sử (client-side trên INITIAL.history đã nạp sẵn).
    const histFilter = { keyword: '', dateFrom: '', dateTo: '' };

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

    // Textarea tự giãn chiều dọc theo nội dung
    function autoResize(el) {
        if (!el || el.tagName !== 'TEXTAREA') return;
        el.style.height = 'auto';
        el.style.height = el.scrollHeight + 'px';
    }

    function buildInterpretation(name, date) {
        return 'Nhập kho sản lượng ' + name + ' ngày ' + date;
    }

    // ---------- Datetime picker helpers ----------
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

    function pickerToDateYMD() {
        const p = parseLocalValue($dateTime.value);
        if (!p) return '';
        return p.y + '-' + p.M + '-' + p.d;
    }

    // Thay thế chuỗi "dd/mm/yyyy" trong mọi .input-interpretation bằng ngày mới
    function syncInterpretationsDate() {
        const dateVN = pickerToDateVN();
        INITIAL.planDate = dateVN;
        const dateRe = /\b\d{1,2}\/\d{1,2}\/\d{2,4}\b/;
        $list.querySelectorAll('.input-interpretation').forEach(inp => {
            if (dateRe.test(inp.value)) {
                inp.value = inp.value.replace(dateRe, dateVN);
                autoResize(inp);
            }
        });
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

    function selectDropdownItem(el) {
        const id = parseInt(el.getAttribute('data-id'), 10);
        if (!id || Number.isNaN(id)) return;
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
                plan_date:    res.data.plan_date || INITIAL.planDate
            });
            $search.value = '';
            hideDropdown();
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
        const pid = p.product_id;
        const qty = (p.quantity === 0 || p.quantity) ? p.quantity : '';
        const name = p.product_name || '';
        // Ngày trong .input-interpretation luôn bám theo #record-datetime tại thời điểm thêm,
        // fallback về plan_date từ server nếu picker chưa init.
        const date = pickerToDateVN() || p.plan_date || INITIAL.planDate || '';
        const interp = (p.interpretation && p.interpretation !== '') ? p.interpretation : buildInterpretation(name, date);
        const importId = p.import_id ? p.import_id : '';
        const planId   = p.plan_id   ? p.plan_id   : '';

        const li = document.createElement('li');
        li.className = 'product-item';
        li.setAttribute('data-product-id', pid);
        if (importId) li.setAttribute('data-import-id', importId);
        if (planId)   li.setAttribute('data-plan-id', planId);
        li.innerHTML = `
            <button type="button" class="btn-remove-product" title="Xóa">×</button>
            <div class="wp-top-product">
                <div class="name-product">
                    <p>${escapeHtml(name)}</p>
                </div>
                <input type="text" class="input-quantity" value="${escapeHtml(qty)}" placeholder="SL">
            </div>
            <div class="interpretation">
                <textarea class="input-interpretation" rows="1">${escapeHtml(interp)}</textarea>
            </div>
        `;

        li.querySelector('.btn-remove-product').addEventListener('click', () => {
            // Đang SỬA nhóm lịch sử: chỉ bỏ dòng khỏi form (lưu bằng nút "Sửa"),
            // không đụng phiếu nhập thành phẩm của ngày.
            if (editingBatchKey) { li.remove(); addedProducts.delete(pid); return; }
            if (!confirm('Gỡ "' + (name || '') + '" khỏi ngày này?\n\n'
                + 'Sẽ XÓA phiếu nhập thành phẩm của sản phẩm trong ngày (nếu có) và TRỪ LẠI tồn kho. '
                + 'Sản phẩm cũng biến mất ở trang "Nhập giá vốn sản xuất". Không hồi phục được.')) return;
            postForm('remove_day_product', {
                product_id: pid,
                date: pickerToDateYMD(),
                source: 'dashboard'
            }).then(res => {
                if (!res || !res.success) { alert((res && res.message) || 'Gỡ thất bại.'); return; }
                li.remove();
                addedProducts.delete(pid);
            }).catch(() => alert('Lỗi kết nối khi gỡ.'));
        });

        $list.appendChild(li);
        addedProducts.add(pid);

        const $interp = li.querySelector('.input-interpretation');
        $interp.addEventListener('input', () => autoResize($interp));
        // Sau khi append xong mới resize để scrollHeight hợp lệ
        requestAnimationFrame(() => autoResize($interp));
    }

    function clearList() {
        $list.innerHTML = '';
        addedProducts.clear();
    }

    function collectItemsForRecord() {
        const items = [];
        $list.querySelectorAll('.product-item').forEach(li => {
            const pid = parseInt(li.getAttribute('data-product-id'), 10);
            const qty = parseFloat((li.querySelector('.input-quantity') || {}).value || '0') || 0;
            const interp = (li.querySelector('.input-interpretation') || {}).value || '';
            items.push({ product_id: pid, quantity: qty, interpretation: interp });
        });
        return items;
    }

    // import_id > 0 → cập nhật dòng đã có; import_id = 0 (kèm product_id) → dòng
    // sản phẩm mới được thêm trong lúc đang sửa nhóm, backend sẽ chèn thêm.
    function collectItemsForEdit() {
        const items = [];
        $list.querySelectorAll('.product-item').forEach(li => {
            const iid = parseInt(li.getAttribute('data-import-id'), 10) || 0;
            const pid = parseInt(li.getAttribute('data-product-id'), 10) || 0;
            if (!iid && !pid) return;
            const qty = parseFloat((li.querySelector('.input-quantity') || {}).value || '0') || 0;
            const interp = (li.querySelector('.input-interpretation') || {}).value || '';
            items.push({ import_id: iid, product_id: pid, quantity: qty, interpretation: interp });
        });
        return items;
    }

    // ---------- Edit batch mode ----------
    function enterEditMode(batch) {
        editingBatchKey = batch.group_key;
        // Đồng bộ picker về thời điểm gốc của batch
        const localVal = mysqlToLocalValue(batch.created_at);
        if (localVal) {
            $dateTime.value = localVal;
            INITIAL.planDate = pickerToDateVN();
        }
        clearList();
        batch.items.forEach(it => {
            addProductItem({
                product_id:     it.product_id,
                product_name:   it.product_name,
                quantity:       it.quantity,
                interpretation: it.interpretation,
                import_id:      it.import_id,
                plan_date:      INITIAL.planDate
            });
        });
        $banner.style.display = 'flex';
        $bannerLb.textContent = batch.date_display;
        $btnRec.style.display = 'none'; // tránh user ghi nhầm thành batch mới
        document.querySelector('.content').scrollIntoView({ behavior: 'smooth' });
    }

    // keepEdits=true → giữ nguyên list-product user vừa sửa (không nạp lại
    // production_plans từ server). Dùng sau khi user nhấn Sửa thành công.
    function exitEditMode(keepEdits) {
        editingBatchKey = null;
        $banner.style.display = 'none';
        $btnRec.style.display = '';
        if (keepEdits) return;
        clearList();
        reloadInitialPlans();
    }

    function reloadInitialPlans() {
        if (Array.isArray(INITIAL.items) && INITIAL.items.length) {
            INITIAL.items.forEach(it => {
                addProductItem({
                    plan_id:      it.plan_id ? parseInt(it.plan_id, 10) : 0,
                    product_id:   parseInt(it.product_id, 10),
                    product_name: it.product_name,
                    quantity:     it.quantity,
                    plan_date:    it.plan_date || INITIAL.planDate
                });
            });
        }
    }

    // Khi user đổi .input-quantity ở chế độ Ghi (đang hiển thị plans), persist
    // ngay vào production_plans.quantity. Bỏ qua khi đang sửa batch (input lúc đó
    // gắn data-import-id, là số lượng của 1 dòng stock_imports — không phải plan).
    $list.addEventListener('change', (e) => {
        const $inp = e.target.closest('.input-quantity');
        if (!$inp) return;
        const $li = $inp.closest('.product-item');
        if (!$li || editingBatchKey) return;

        const planId = parseInt($li.getAttribute('data-plan-id'), 10) || 0;
        const pid    = parseInt($li.getAttribute('data-product-id'), 10) || 0;
        if (!planId && !pid) return;

        const qty = parseInt($inp.value, 10);
        if (!isFinite(qty) || qty < 0) return;

        // Sản phẩm có thể nằm ngoài production_plans (user thêm thủ công) — fail
        // im lặng cũng OK, vì btn-record vẫn ghi được vào stock_imports + tồn kho.
        postForm('update_plan_quantity', {
            plan_id:    planId,
            product_id: pid,
            quantity:   qty
        });
    });

    $btnCancelEdit.addEventListener('click', (e) => {
        e.preventDefault();
        exitEditMode();
    });

    // ---------- Record & Edit buttons ----------
    function flashActive(btn) {
        [$btnRec, $btnEdit].forEach(b => b && b.classList.remove('active'));
        if (btn) btn.classList.add('active');
    }

    function checkDuplicatesThen(productIds, onOk) {
        if (!productIds.length) { onOk(); return; }
        postForm('check_duplicates', {
            product_ids: JSON.stringify(productIds),
            plan_date:   pickerToDateYMD(),
            type_import: TYPE_IMPORT
        }).then(res => {
            const dups = (res && res.data) || [];
            if (!dups.length) { onOk(); return; }
            const lines = dups.map(d => 'Sản phẩm "' + d.product_name + '" đã được ghi trong ngày ' + d.date_vn + '.');
            const msg = lines.join('\n') + '\n\nBạn vẫn muốn ghi tiếp?';
            if (confirm(msg)) onOk();
        });
    }

    $btnRec.addEventListener('click', () => {
        if (editingBatchKey) return; // ẩn rồi nhưng vẫn chặn an toàn
        const items = collectItemsForRecord().filter(it => it.quantity > 0);
        if (!items.length) {
            alert('Chưa có sản phẩm nào có số lượng hợp lệ để ghi.');
            return;
        }
        const productIds = items.map(it => it.product_id).filter(Boolean);
        checkDuplicatesThen(productIds, () => {
            flashActive($btnRec);
            postForm('record_stock', {
                items:       JSON.stringify(items),
                created_at:  pickerToMysql(),
                type_import: TYPE_IMPORT
            }).then(res => {
                if (res && res.success) {
                    // Thay alert xác nhận bằng hiệu ứng bay vào khối "Lịch sử" — reload lại trang
                    // (lấy ngày giờ ghi mới + làm sạch danh sách) sau khi hiệu ứng chạy xong.
                    if (window.appFlyToHistory) window.appFlyToHistory($btnRec);
                    setTimeout(() => window.location.reload(), 950);
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
        const items = collectItemsForEdit();
        if (!items.length) {
            alert('Không có dòng nào để cập nhật.');
            return;
        }
        flashActive($btnEdit);
        postForm('edit_batch_stock', {
            items:       JSON.stringify(items),
            created_at:  pickerToMysql(),
            type_import: TYPE_IMPORT
        }).then(res => {
            if (res && res.success) {
                if (window.appFlyToHistory) window.appFlyToHistory($btnEdit);
                renderHistory(res.history || []);
                // Giữ nguyên list user vừa sửa — không revert về production_plans.
                exitEditMode(true);
            } else {
                alert(res && res.message ? res.message : 'Có lỗi xảy ra.');
            }
        });
    });

    // ---------- History render + actions ----------
    function renderHistory(batches) {
        // cập nhật cache để edit dùng
        INITIAL.history = batches || [];
        historyPage = 1;
        renderHistoryPage();
    }

    // Ngày của batch dạng 'YYYY-MM-DD'. Ưu tiên created_at ('YYYY-MM-DD HH:MM:SS');
    // fallback parse từ date_display ('HH:ii:ss dd/mm/yyyy' hoặc 'dd/mm/yyyy').
    // So sánh chuỗi 'YYYY-MM-DD' đúng thứ tự nên dùng trực tiếp cho khoảng ngày.
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
            if (p === '...') {
                parts.push('<span class="page-ellipsis">…</span>');
            } else {
                parts.push(`<button type="button" class="page-btn page-num ${p === historyPage ? 'active' : ''}" data-page="${p}">${p}</button>`);
            }
        });

        parts.push(`<button type="button" class="page-btn page-next" ${historyPage === totalPages ? 'disabled' : ''}>»</button>`);
        $histPager.innerHTML = parts.join('');
    }

    function pageRange(current, total) {
        const out = [];
        if (total <= 7) {
            for (let i = 1; i <= total; i++) out.push(i);
            return out;
        }
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
            if (!confirm('Xóa nhóm nhập ngày "' + batch.date_display + '"? Tồn kho sẽ bị trừ tương ứng.')) return;
            postForm('delete_batch_stock', { group_key: batch.group_key, type_import: TYPE_IMPORT }).then(res => {
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
        INITIAL.planDate = pickerToDateVN();
        $dateTime.addEventListener('change', syncInterpretationsDate);

        setupHistoryFilter();

        // TASK 1: KHÔNG auto-load danh sách thành phẩm theo kế hoạch nữa —
        // để user tự tìm và chọn ở ô tìm kiếm. (Trước đây gọi reloadInitialPlans()).
        renderHistory(INITIAL.history || []);
    }

    init();
})();
