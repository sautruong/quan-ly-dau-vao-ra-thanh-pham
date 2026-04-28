(function () {
    'use strict';

    const CFG = window.INVENTORY_CONFIG || { baseUrl: '?mod=inventory_management&controllers=inventory_management&action=' };
    const INITIAL = window.INVENTORY_DATA || { items: [], planDate: '', history: [], typeImport: 'fg_receipt_production' };
    const TYPE_IMPORT = INITIAL.typeImport || 'fg_receipt_production';

    const addedProducts = new Set();
    let dropdownItems = [];
    let activeIdx = -1;
    let editingBatchKey = null; // null = chế độ Ghi mới; chuỗi = đang sửa batch này

    const $search   = document.getElementById('search-product');
    const $dropdown = document.getElementById('search-dropdown');
    const $list     = document.getElementById('list-product');
    const $btnRec   = document.getElementById('btn-record');
    const $btnEdit  = document.getElementById('btn-edit');
    const $histBody = document.getElementById('history-tbody');
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
        if (editingBatchKey) {
            alert('Đang ở chế độ sửa nhóm. Hãy Hủy để thêm sản phẩm mới.');
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
        } else if (e.key === 'Enter') {
            e.preventDefault();
            if (activeIdx >= 0) selectDropdownItem(items[activeIdx]);
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

        const li = document.createElement('li');
        li.className = 'product-item';
        li.setAttribute('data-product-id', pid);
        if (importId) li.setAttribute('data-import-id', importId);
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
            li.remove();
            addedProducts.delete(pid);
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

    function collectItemsForEdit() {
        const items = [];
        $list.querySelectorAll('.product-item').forEach(li => {
            const iid = parseInt(li.getAttribute('data-import-id'), 10);
            if (!iid) return;
            const qty = parseFloat((li.querySelector('.input-quantity') || {}).value || '0') || 0;
            const interp = (li.querySelector('.input-interpretation') || {}).value || '';
            items.push({ import_id: iid, quantity: qty, interpretation: interp });
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

    function exitEditMode() {
        editingBatchKey = null;
        $banner.style.display = 'none';
        $btnRec.style.display = '';
        clearList();
        reloadInitialPlans();
    }

    function reloadInitialPlans() {
        if (Array.isArray(INITIAL.items) && INITIAL.items.length) {
            INITIAL.items.forEach(it => {
                addProductItem({
                    product_id:   parseInt(it.product_id, 10),
                    product_name: it.product_name,
                    quantity:     it.quantity,
                    plan_date:    it.plan_date || INITIAL.planDate
                });
            });
        }
    }

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
        if (!confirm('Ghi sản lượng hiện tại vào tồn kho?')) return;

        const productIds = items.map(it => it.product_id).filter(Boolean);
        checkDuplicatesThen(productIds, () => {
            flashActive($btnRec);
            postForm('record_stock', {
                items:       JSON.stringify(items),
                created_at:  pickerToMysql(),
                type_import: TYPE_IMPORT
            }).then(res => {
                if (res && res.success) {
                    alert('Đã ghi ' + res.count + ' sản phẩm vào tồn kho.');
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
                alert('Đã cập nhật ' + res.count + ' dòng.');
                renderHistory(res.history || []);
                exitEditMode();
            } else {
                alert(res && res.message ? res.message : 'Có lỗi xảy ra.');
            }
        });
    });

    // ---------- History render + actions ----------
    function renderHistory(batches) {
        // cập nhật cache để edit dùng
        INITIAL.history = batches;
        if (!batches.length) {
            $histBody.innerHTML = '<tr class="history-empty"><td colspan="3">Chưa có thao tác nào.</td></tr>';
            return;
        }
        $histBody.innerHTML = batches.map((b, idx) => `
            <tr data-group-key="${escapeHtml(b.group_key)}" data-idx="${idx}">
                <td>${escapeHtml(b.date_display)}</td>
                <td title="${escapeHtml(b.summary)}">${escapeHtml(b.summary)}</td>
                <td class="history-actions">
                    <a href="#" class="edit-list-import">Sửa</a>
                    <span class="sep">|</span>
                    <a href="#" class="delete-list-import">Xóa</a>
                </td>
            </tr>
        `).join('');
    }

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

    // ---------- Init ----------
    function init() {
        $dateTime.value = nowLocalValue();
        INITIAL.planDate = pickerToDateVN();
        $dateTime.addEventListener('change', syncInterpretationsDate);

        reloadInitialPlans();
        renderHistory(INITIAL.history || []);
    }

    init();
})();
