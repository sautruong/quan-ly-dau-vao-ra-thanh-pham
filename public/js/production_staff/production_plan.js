(function () {
    'use strict';

    const CFG = window.PRODUCTION_PLAN_CONFIG || { baseUrl: '?mod=production_staff&controllers=production_staff&action=' };
    const INITIAL = window.PRODUCTION_PLAN_DATA || { plans: [], tasks: [] };

    // ---------- State ----------
    const addedProducts = new Set();
    let tasks = [];
    let dropdownItems = [];
    let activeIdx = -1;

    // ---------- DOM refs ----------
    const $search       = document.getElementById('search-product');
    const $dropdown     = document.getElementById('search-dropdown');
    const $list         = document.getElementById('list-product');
    const $listTasks    = document.getElementById('list-other-work');
    const $btnAddOther  = document.getElementById('btn-add-other-work');
    const $otherForm    = document.getElementById('other-work-form');
    const $otherInput   = document.getElementById('other-work-input');
    const $btnAddTask   = document.getElementById('btn-add-task');
    const $btnCancel    = document.getElementById('btn-cancel-task');
    const $btnReset     = document.getElementById('btn-reset');
    const $btnSend      = document.getElementById('btn-sent-pan');
    const $colSelect    = document.getElementById('display-col');

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

    function applyCols() {
        const v = $colSelect ? $colSelect.value : '4';
        $list.classList.remove('cols-2', 'cols-4');
        $list.classList.add(v === '2' ? 'cols-2' : 'cols-4');
    }

    // ---------- Search dropdown ----------
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
            alert('Sản phẩm đã có trong kế hoạch.');
            hideDropdown();
            return;
        }
        postForm('get_product_data', { product_id: id }).then(res => {
            if (!res.success) { alert(res.message || 'Lỗi'); return; }
            addProductItem({
                product_id:   res.data.product_id,
                product_name: res.data.product_name,
                note:         res.data.note,
                quantity:     '',
                sample:       0,
                test:         0,
                check_inventory: 0,
                plan_id:      null
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
        const note = p.note || '';
        const hasNote = note !== '';
        const pid = p.product_id;
        const planId = p.plan_id || '';
        const qty = (p.quantity === 0 || p.quantity) ? p.quantity : '';
        const isChk = (v) => Number(v) === 1 ? 'checked' : '';

        const li = document.createElement('li');
        li.className = 'product-item';
        li.setAttribute('data-product-id', pid);
        if (planId) li.setAttribute('data-plan-id', planId);
        li.innerHTML = `
            <div class="wp-product-num">
                <div class="name-product">
                    <p><strong>${escapeHtml(p.product_name)}</strong></p>
                </div>
                <div class="num">
                    <input type="text" class="input-quantity" value="${escapeHtml(qty)}" placeholder="SL">
                </div>
            </div>
            <div class="list-QC">
                <div class="wp-ck">
                    <input type="checkbox" id="ck_sample_${pid}" data-qc="sample" ${isChk(p.sample)}>
                    <label for="ck_sample_${pid}">lấy mẫu</label>
                </div>
                <div class="wp-ck">
                    <input type="checkbox" id="ck_test_${pid}" data-qc="test" ${isChk(p.test)}>
                    <label for="ck_test_${pid}">Test</label>
                </div>
                <div class="wp-ck">
                    <input type="checkbox" id="ck_stock_${pid}" data-qc="check_inventory" ${isChk(p.check_inventory)}>
                    <label for="ck_stock_${pid}">Kiểm tồn</label>
                </div>
            </div>
            <div class="note">
                <strong>Lưu ý:</strong>
                <input type="text" class="input-note" value="${escapeHtml(note)}" placeholder="Nhập lưu ý...">
                <button type="button" class="note-delete" title="Xóa lưu ý" ${hasNote ? '' : 'style="display:none;"'}>×</button>
            </div>
        `;
        bindProductItem(li, pid);
        $list.appendChild(li);
        addedProducts.add(pid);
    }

    function bindProductItem(li, pid) {
        const $note = li.querySelector('.input-note');
        const $del  = li.querySelector('.note-delete');
        const $qcs  = li.querySelectorAll('.list-QC input[type="checkbox"]');

        // Auto-save note
        const saveNote = () => {
            const val = $note.value.trim();
            postForm('save_note', { product_id: pid, note: val }).then(res => {
                if (res && res.success) {
                    $del.style.display = val === '' ? 'none' : '';
                }
            });
        };
        $note.addEventListener('blur', saveNote);
        $note.addEventListener('keydown', (e) => {
            if (e.key === 'Enter') { e.preventDefault(); $note.blur(); }
        });

        $del.addEventListener('click', () => {
            postForm('delete_note', { product_id: pid }).then(res => {
                if (res && res.success) {
                    $note.value = '';
                    $del.style.display = 'none';
                }
            });
        });

        // Toggle class .active để đánh dấu checkbox đã chọn — đọc khi gửi plan
        $qcs.forEach(cb => {
            if (cb.checked) cb.classList.add('active');
            cb.addEventListener('change', () => {
                cb.classList.toggle('active', cb.checked);
            });
        });
    }

    // ---------- Other work form ----------
    $btnAddOther.addEventListener('click', () => {
        $otherForm.style.display = 'flex';
        $otherInput.focus();
    });

    $btnCancel.addEventListener('click', () => {
        $otherInput.value = '';
        $otherForm.style.display = 'none';
    });

    function addTask(desc) {
        desc = (desc || '').trim();
        if (desc === '') return;
        tasks.push(desc);
        renderTasks();
    }

    $btnAddTask.addEventListener('click', () => {
        addTask($otherInput.value);
        $otherInput.value = '';
        $otherInput.focus();
    });

    $otherInput.addEventListener('keydown', (e) => {
        if (e.key === 'Enter') { e.preventDefault(); $btnAddTask.click(); }
        else if (e.key === 'Escape') { $btnCancel.click(); }
    });

    function renderTasks() {
        $listTasks.innerHTML = tasks.map((t, i) => `
            <li class="other-work-item" data-idx="${i}">
                <div class="serial"><p>${i + 1}</p></div>
                <div class="content-other-work">
                    <input type="text" value="${escapeHtml(t)}" data-idx="${i}" class="input-task">
                </div>
                <button type="button" class="task-delete" data-idx="${i}" title="Xóa">×</button>
            </li>
        `).join('');
    }

    $listTasks.addEventListener('click', (e) => {
        const btn = e.target.closest('.task-delete');
        if (!btn) return;
        const idx = parseInt(btn.getAttribute('data-idx'), 10);
        tasks.splice(idx, 1);
        renderTasks();
    });

    $listTasks.addEventListener('change', (e) => {
        const inp = e.target.closest('.input-task');
        if (!inp) return;
        const idx = parseInt(inp.getAttribute('data-idx'), 10);
        tasks[idx] = inp.value.trim();
    });

    // ---------- Reset ----------
    $btnReset.addEventListener('click', () => {
        if (!confirm('Xóa toàn bộ kế hoạch hiện tại để lập bản kế hoạch mới?')) return;
        // Xóa trên DB luôn để lần render kế tiếp khớp với UI
        postForm('save_plan', { items: '[]', tasks: '[]', force_clear: 1 }).then(() => {
            $list.innerHTML = '';
            addedProducts.clear();
            tasks = [];
            renderTasks();
            $search.value = '';
            hideDropdown();
        });
    });

    // ---------- Send plan ----------
    $btnSend.addEventListener('click', () => {
        const items = [];
        $list.querySelectorAll('.product-item').forEach(li => {
            const pid = parseInt(li.getAttribute('data-product-id'), 10);
            const qty = parseInt((li.querySelector('.input-quantity') || {}).value || '0', 10) || 0;
            const note = (li.querySelector('.input-note') || {}).value || '';
            const sample = li.querySelector('[data-qc="sample"]').classList.contains('active') ? 1 : 0;
            const test   = li.querySelector('[data-qc="test"]').classList.contains('active') ? 1 : 0;
            const ck     = li.querySelector('[data-qc="check_inventory"]').classList.contains('active') ? 1 : 0;
            items.push({
                product_id: pid,
                quantity: qty,
                note: note,
                sample: sample,
                test: test,
                check_inventory: ck
            });
        });

        if (items.length === 0) {
            alert('Chưa có sản phẩm nào trong kế hoạch.');
            return;
        }

        const payload = {
            items: JSON.stringify(items),
            tasks: JSON.stringify(tasks)
        };
        postForm('save_plan', payload).then(res => {
            if (res && res.success) {
                alert('Đã gửi kế hoạch sản xuất cho NVSX.');
                window.location.href = '?mod=production_staff&controllers=production_staff&action=plan_for_staff';
            } else {
                alert(res.message || 'Có lỗi xảy ra khi lưu kế hoạch.');
            }
        });
    });

    // ---------- Init: load existing plan ----------
    function init() {
        if ($colSelect) $colSelect.addEventListener('change', applyCols);
        applyCols();

        if (Array.isArray(INITIAL.plans) && INITIAL.plans.length) {
            INITIAL.plans.forEach(p => {
                addProductItem({
                    product_id:   parseInt(p.product_id, 10),
                    product_name: p.product_name,
                    note:         p.pre_note || p.plan_note || '',
                    quantity:     p.quantity,
                    sample:       Number(p.sample) || 0,
                    test:         Number(p.test) || 0,
                    check_inventory: Number(p.check_inventory) || 0,
                    plan_id:      p.plan_id
                });
            });
        }
        if (Array.isArray(INITIAL.tasks) && INITIAL.tasks.length) {
            tasks = INITIAL.tasks.slice();
            renderTasks();
        }
    }

    init();
})();
