/**
 * Shared journal-entry handler cho 8 action có ghi bút toán.
 *
 * Yêu cầu HTML: file helper/accounting.php sinh sẵn các id:
 *   #je-transaction-name, #je-transaction-name-list (datalist),
 *   #je-debit, #je-debit-name, #je-credit, #je-credit-name,
 *   #je-amount, #je-description.
 *
 * Yêu cầu config: window.JE_CONFIG = { transactionGroup, ajaxBase }.
 */
(function () {
    if (!window.JE_CONFIG || !window.JE_CONFIG.transactionGroup) return;

    const GROUP    = window.JE_CONFIG.transactionGroup;
    const AJAX     = window.JE_CONFIG.ajaxBase || '?mod=accounting&controllers=accounting&action=';
    const DEBOUNCE = 500;

    document.addEventListener('DOMContentLoaded', function () {
        if (document.querySelector('[data-je-multi]')) initMulti();
        else init();
    });

    function init() {
        const els = collectEls();
        if (!els.txName) return; // chưa render journal entry trên page này

        // Hiển thị badge trạng thái save ở cuối block (ngoài các flex-row)
        const status = document.createElement('div');
        status.className = 'je-save-status';
        const block = els.txName.closest('.je-block') || els.description.parentNode;
        block.appendChild(status);
        els.status = status;

        let cache = []; // [{transaction_name, description, entries: {debit, credit}}]

        // 1. Load list_names ban đầu để render datalist
        loadList().then(list => {
            cache = list;
            renderDatalist(els.datalist, cache);
        }).catch(err => {
            console.error('[journal_entry] load list_names error:', err);
        });

        // 2. Khi user gõ vào transaction_name (hoặc chọn từ datalist) → match exact, auto-fill
        els.txName.addEventListener('input', function () {
            const v = this.value.trim();
            const match = cache.find(m => m.transaction_name === v);
            if (match) {
                fillFromMapping(els, match);
            }
        });

        // 3. Save trigger
        const debouncedSave = debounce(() => trySave(els, cache, refreshCache), DEBOUNCE);

        // transaction_name: save trên blur (xác nhận key)
        els.txName.addEventListener('blur', () => trySave(els, cache, refreshCache));

        // Các field khác: debounce 500ms
        ['debit', 'debitName', 'credit', 'creditName', 'amount', 'description'].forEach(key => {
            els[key].addEventListener('input', debouncedSave);
        });

        function refreshCache(saved) {
            // saved = { transaction_name, description, entries }
            const idx = cache.findIndex(m => m.transaction_name === saved.transaction_name);
            if (idx >= 0) cache[idx] = saved;
            else {
                cache.push(saved);
                renderDatalist(els.datalist, cache);
            }
        }
    }

    function collectEls() {
        return {
            txName:      document.getElementById('je-transaction-name'),
            datalist:    document.getElementById('je-transaction-name-list'),
            debit:       document.getElementById('je-debit'),
            debitName:   document.getElementById('je-debit-name'),
            credit:      document.getElementById('je-credit'),
            creditName:  document.getElementById('je-credit-name'),
            amount:      document.getElementById('je-amount'),
            description: document.getElementById('je-description'),
        };
    }

    function loadList() {
        const url = AJAX + 'list_names&transaction_group=' + encodeURIComponent(GROUP);
        return fetch(url, { credentials: 'same-origin' })
            .then(r => r.json())
            .then(j => (j && j.success && Array.isArray(j.data)) ? j.data : []);
    }

    function renderDatalist(datalist, list) {
        if (!datalist) return;
        datalist.innerHTML = '';
        list.forEach(m => {
            const opt = document.createElement('option');
            opt.value = m.transaction_name;
            datalist.appendChild(opt);
        });
    }

    function fillFromMapping(els, m) {
        const d = (m.entries && m.entries.debit)  || {};
        const c = (m.entries && m.entries.credit) || {};
        els.debit.value       = d.account_code   || '';
        els.debitName.value   = d.account_name   || '';
        els.credit.value      = c.account_code   || '';
        els.creditName.value  = c.account_name   || '';
        // Ưu tiên amount_formula bên debit; nếu trống thì credit
        const amt = d.amount_formula || c.amount_formula || '';
        // amountFromItems: trang tự tính "Giá trị" từ danh sách mặt hàng nhập → KHÔNG
        // nạp giá trị từ template khi chọn nghiệp vụ (vd other_row_material_receiving).
        if (amt && !(window.JE_CONFIG && window.JE_CONFIG.amountFromItems)) els.amount.value = amt;
        els.description.value = m.description || '';
    }

    function trySave(els, cache, onSaved) {
        const name = els.txName.value.trim();
        if (name === '') return; // bỏ qua nếu chưa có transaction_name

        const body = new URLSearchParams();
        body.set('transaction_group', GROUP);
        body.set('transaction_name',  name);
        body.set('description',       els.description.value);
        body.set('debit_code',        els.debit.value.trim());
        body.set('debit_name',        els.debitName.value);
        body.set('credit_code',       els.credit.value.trim());
        body.set('credit_name',       els.creditName.value);
        body.set('amount_formula',    els.amount.value.trim());

        setStatus(els.status, 'is-saving', 'Đang lưu...');

        fetch(AJAX + 'save', {
            method: 'POST',
            credentials: 'same-origin',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8' },
            body: body.toString(),
        })
        .then(r => r.json())
        .then(j => {
            if (j && j.success) {
                setStatus(els.status, 'is-ok', 'Đã lưu lúc ' + nowHM());
                onSaved({
                    transaction_name: name,
                    description: els.description.value,
                    entries: {
                        debit:  buildEntry(els.debit.value, els.debitName.value,  els.amount.value),
                        credit: buildEntry(els.credit.value, els.creditName.value, els.amount.value),
                    },
                });
            } else {
                setStatus(els.status, 'is-error', (j && j.message) || 'Lưu thất bại');
            }
        })
        .catch(err => {
            console.error('[journal_entry] save error:', err);
            setStatus(els.status, 'is-error', 'Lỗi kết nối khi lưu');
        });
    }

    function buildEntry(code, name, amount) {
        const c = (code || '').trim();
        if (c === '') return null;
        return { account_code: c, account_name: name || '', amount_formula: (amount || '').trim() };
    }

    function setStatus(el, cls, text) {
        if (!el) return;
        el.className = 'je-save-status ' + cls;
        el.textContent = text;
    }

    function nowHM() {
        const d = new Date();
        const pad = n => (n < 10 ? '0' + n : '' + n);
        return pad(d.getHours()) + ':' + pad(d.getMinutes()) + ':' + pad(d.getSeconds());
    }

    function debounce(fn, wait) {
        let t = null;
        return function () {
            const args = arguments, ctx = this;
            clearTimeout(t);
            t = setTimeout(() => fn.apply(ctx, args), wait);
        };
    }

    /* ============================================================
     *   MULTI MODE — nhiều bút toán / 1 vụ (THÊM BÚT TOÁN)
     *   Mỗi block là 1 .je-block trong .je-list; field theo class.
     *   Tái dùng loadList / renderDatalist / fillFromMapping / trySave.
     *   Expose window.JE { collectEntries, loadTemplatesAsBlocks, setEntries, reset, addBlock, firstAmountInput }.
     * ============================================================ */
    function initMulti() {
        const wrap = document.querySelector('[data-je-multi]');
        if (!wrap) return;
        const list     = wrap.querySelector('.je-list');
        const tpl      = wrap.querySelector('.je-block-template');
        const datalist = document.getElementById('je-transaction-name-list');
        const addBtn   = wrap.querySelector('.btn-add-je');
        if (!list || !tpl) return;

        let cache = []; // [{transaction_name, description, entries:{debit,credit}}]

        function blockEls(block) {
            return {
                txName:      block.querySelector('.je-transaction-name'),
                datalist:    datalist,
                debit:       block.querySelector('.je-debit'),
                debitName:   block.querySelector('.je-debit-name'),
                credit:      block.querySelector('.je-credit'),
                creditName:  block.querySelector('.je-credit-name'),
                amount:      block.querySelector('.je-amount'),
                description: block.querySelector('.je-description'),
                status:      block.querySelector('.je-save-status'),
            };
        }

        function refreshCache(saved) {
            const idx = cache.findIndex(m => m.transaction_name === saved.transaction_name);
            if (idx >= 0) cache[idx] = saved;
            else { cache.push(saved); renderDatalist(datalist, cache); }
        }

        function wireBlock(block) {
            const els = blockEls(block);
            if (!els.txName) return;
            if (!els.status) {
                const s = document.createElement('div');
                s.className = 'je-save-status';
                block.appendChild(s);
                els.status = s;
            }
            els.txName.addEventListener('input', function () {
                const v = this.value.trim();
                const m = cache.find(x => x.transaction_name === v);
                if (m) fillFromMapping(els, m);
            });
            // deferSave: trang tự lưu nghiệp vụ lúc "Ghi" (insert-if-new). Bỏ auto-save
            // theo blur/gõ để không "cập nhật"/ghi đè template trong khi đang nhập.
            if (window.JE_CONFIG && window.JE_CONFIG.deferSave) return;
            els.txName.addEventListener('blur', () => trySave(els, cache, refreshCache));
            const deb = debounce(() => trySave(els, cache, refreshCache), DEBOUNCE);
            ['debit', 'debitName', 'credit', 'creditName', 'amount', 'description'].forEach(k => {
                if (els[k]) els[k].addEventListener('input', deb);
            });
        }

        function newBlockNode() {
            return tpl.content.firstElementChild.cloneNode(true);
        }

        // Cụm ĐẦU (index 0) giữ id cũ + không cho xóa (đảm bảo tối thiểu 1 cụm và
        // không làm hỏng ref DOM mà JS từng trang đã cache). Chỉ cụm nhân bản có 'x'.
        function updateRemoveButtons() {
            list.querySelectorAll('.je-block').forEach((b, i) => {
                const rm = b.querySelector('.btn-remove-je');
                if (rm) rm.style.display = (i > 0) ? '' : 'none';
            });
        }

        function addBlock() {
            const node = newBlockNode();
            list.appendChild(node);
            wireBlock(node);
            updateRemoveButtons();
            return node;
        }

        function clearBlockFields(block) {
            const els = blockEls(block);
            ['txName', 'debit', 'debitName', 'credit', 'creditName', 'amount', 'description']
                .forEach(k => { if (els[k]) els[k].value = ''; });
        }

        function removeExtraBlocks() {
            const blocks = list.querySelectorAll('.je-block');
            for (let i = blocks.length - 1; i >= 1; i--) blocks[i].remove();
        }

        function fillBlock(node, m) {
            const els = blockEls(node);
            if (els.txName) els.txName.value = m.transaction_name || '';
            fillFromMapping(els, m);
        }

        function fillBlockFromEntry(node, en) {
            const els = blockEls(node);
            if (els.txName)      els.txName.value      = en.transaction_name || '';
            if (els.debit)       els.debit.value       = en.debit || en.debit_code || '';
            if (els.debitName)   els.debitName.value   = en.debit_name || '';
            if (els.credit)      els.credit.value      = en.credit || en.credit_code || '';
            if (els.creditName)  els.creditName.value  = en.credit_name || '';
            if (els.amount)      els.amount.value      = (en.amount != null ? en.amount : '');
            if (els.description) els.description.value = en.description || '';
        }

        function clearAmounts() {
            list.querySelectorAll('.je-amount').forEach(a => { a.value = ''; });
        }

        // Prefill từ lịch sử bút toán đã lưu của group: dựng lại TẤT CẢ cụm
        // (.je-block je-entry), nhưng bỏ trống "Giá trị" (#je-amount) để user tự
        // nhập cho phiếu hiện tại. Dùng lúc tải trang (ghi mới).
        function prefillFromHistory() {
            return loadList().then(l => {
                cache = l;
                renderDatalist(datalist, cache);
                removeExtraBlocks();
                let first = list.querySelector('.je-block');
                if (!first) first = addBlock();
                // TASK 4: prefillMode = 'latest' → chỉ nạp 1 bút toán user đã chọn gần nhất
                // (updated_at mới nhất); user bấm "+ BÚT TOÁN KẾ TOÁN" để thêm cụm khác.
                const mode = (window.JE_CONFIG && window.JE_CONFIG.prefillMode) || 'all';
                if (cache && cache.length) {
                    if (mode === 'latest') {
                        fillBlock(first, pickLatestTemplate(cache));
                    } else {
                        fillBlock(first, cache[0]);
                        for (let i = 1; i < cache.length; i++) fillBlock(addBlock(), cache[i]);
                    }
                } else {
                    clearBlockFields(first);
                }
                clearAmounts();
                updateRemoveButtons();
            });
        }

        // Chọn template có updated_at mới nhất (fallback: phần tử đầu nếu thiếu mốc thời gian).
        function pickLatestTemplate(arr) {
            const t = m => {
                const s = m && m.updated_at ? String(m.updated_at).replace(' ', 'T') : '';
                const v = s ? Date.parse(s) : NaN;
                return isFinite(v) ? v : -Infinity;
            };
            let best = arr[0], bestT = t(arr[0]);
            for (let i = 1; i < arr.length; i++) {
                const ti = t(arr[i]);
                if (ti >= bestT) { best = arr[i]; bestT = ti; }
            }
            return best;
        }

        // Nút xổ/thu cụm bút toán. Mặc định BUNG RA (expanded); click mới thu lại.
        const toggleBtn = wrap.querySelector('.btn-toggle-je');
        const collapse  = wrap.querySelector('.je-collapse');
        if (toggleBtn && collapse) {
            toggleBtn.addEventListener('click', function () {
                const collapsed = wrap.classList.toggle('je-collapsed');
                toggleBtn.setAttribute('aria-expanded', collapsed ? 'false' : 'true');
            });
        }

        // Làm nổi bật nút xổ/thu NGAY SAU KHI hiệu ứng .gdtp-trigger (ngày giờ ghi) dừng,
        // để hướng người dùng chú ý bút toán kế toán đang bị ẩn mặc định lúc vào page.
        if (toggleBtn) {
            document.addEventListener('gdtp:highlight-end', function onGdtpHighlightEnd() {
                document.removeEventListener('gdtp:highlight-end', onGdtpHighlightEnd);
                toggleBtn.classList.add('je-toggle-highlight');
                setTimeout(function () { toggleBtn.classList.remove('je-toggle-highlight'); }, 4000);
            });
        }

        // wire block(s) có sẵn
        list.querySelectorAll('.je-block').forEach(wireBlock);
        updateRemoveButtons();

        if (addBtn) addBtn.addEventListener('click', () => {
            const b = addBlock();
            const t = b.querySelector('.je-transaction-name');
            if (t) t.focus();
        });

        list.addEventListener('click', (e) => {
            const rm = e.target.closest('.btn-remove-je');
            if (!rm) return;
            // tối thiểu 1 cụm: chỉ xóa khi đang có >= 2 cụm
            if (list.querySelectorAll('.je-block').length > 1) {
                rm.closest('.je-block').remove();
                updateRemoveButtons();
            }
        });

        // Lúc tải trang: prefill form từ lịch sử lần ghi gần nhất (lấy hết các cụm,
        // bỏ trống Giá trị). Nếu chưa có lịch sử → 1 cụm trống như cũ.
        // prefillMode = 'none' → trang tự quản việc prefill (vd cash_transactions theo
        // Phân loại + Tài khoản): chỉ nạp datalist để autocomplete Diễn giải hoạt động.
        const initMode = (window.JE_CONFIG && window.JE_CONFIG.prefillMode) || 'all';
        if (initMode === 'none') {
            loadList().then(l => { cache = l; renderDatalist(datalist, cache); })
                .catch(err => console.error('[journal_entry multi] load list error:', err));
        } else {
            prefillFromHistory()
                // Báo cho trang chủ quản biết các cụm bút toán đã dựng xong (async) — trang nào
                // prefill dữ liệu hàng hóa TRƯỚC khi cụm kịp dựng (vd sales_issue prefill từ
                // sessionStorage của branch_orders) nghe event này để đồng bộ lại Giá trị.
                .then(() => document.dispatchEvent(new CustomEvent('je:blocks-ready')))
                .catch(err => console.error('[journal_entry multi] prefill error:', err));
        }

        function parseAmt(s) {
            return Number(String(s == null ? '' : s).replace(/[^\d.-]/g, '')) || 0;
        }

        window.JE = {
            // Thu thập tất cả bút toán đang nhập (bỏ block trống hoàn toàn).
            collectEntries() {
                const out = [];
                list.querySelectorAll('.je-block').forEach(b => {
                    const e = blockEls(b);
                    const name   = e.txName ? e.txName.value.trim() : '';
                    const debit  = e.debit  ? e.debit.value.trim()  : '';
                    const credit = e.credit ? e.credit.value.trim() : '';
                    const amount = parseAmt(e.amount ? e.amount.value : '');
                    if (!name && !debit && !credit && !amount) return;
                    out.push({
                        transaction_name: name,
                        debit: debit,
                        credit: credit,
                        amount: amount,
                        debit_name:  e.debitName  ? e.debitName.value.trim()  : '',
                        credit_name: e.creditName ? e.creditName.value.trim() : '',
                        description: e.description ? e.description.value.trim() : '',
                    });
                });
                return out;
            },
            // Sửa: dựng lại cụm từ các mẫu định khoản đã lưu của group (theo tên).
            // Giữ nguyên cụm đầu (có id) — chỉ điền lại; cụm thừa = clone.
            loadTemplatesAsBlocks() {
                return loadList().then(l => {
                    cache = l;
                    renderDatalist(datalist, cache);
                    removeExtraBlocks();
                    let first = list.querySelector('.je-block');
                    if (!first) first = addBlock();
                    if (cache && cache.length) {
                        fillBlock(first, cache[0]);
                        for (let i = 1; i < cache.length; i++) fillBlock(addBlock(), cache[i]);
                    } else {
                        clearBlockFields(first);
                    }
                    updateRemoveButtons();
                    document.dispatchEvent(new CustomEvent('je:blocks-ready'));
                });
            },
            // Dựng cụm từ danh sách entries tường minh (giữ cụm đầu).
            setEntries(entries) {
                entries = entries || [];
                removeExtraBlocks();
                let first = list.querySelector('.je-block');
                if (!first) first = addBlock();
                if (entries.length) {
                    fillBlockFromEntry(first, entries[0]);
                    for (let i = 1; i < entries.length; i++) fillBlockFromEntry(addBlock(), entries[i]);
                } else {
                    clearBlockFields(first);
                }
                updateRemoveButtons();
            },
            reset() {
                removeExtraBlocks();
                let first = list.querySelector('.je-block');
                if (!first) first = addBlock();
                else clearBlockFields(first);
                updateRemoveButtons();
            },
            firstAmountInput() {
                return list.querySelector('.je-block .je-amount');
            },
            addBlock: addBlock,
            prefillFromHistory: prefillFromHistory,
        };
    }
})();
