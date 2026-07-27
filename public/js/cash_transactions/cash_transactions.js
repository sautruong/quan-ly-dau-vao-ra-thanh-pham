(function () {
    'use strict';

    const CFG = window.CT_CONFIG || { baseUrl: '?mod=cash_transactions&controllers=cash_transactions&action=' };
    const INITIAL = window.CT_DATA || { history: [] };

    let editingId = null;
    let historyPage = 1;
    let pageSize = 10; // số dòng/trang — đổi qua select #hf-page-size
    let history = (INITIAL.history || []).slice();
    // Bộ lọc lịch sử (client-side trên mảng history đã nạp sẵn).
    const histFilter = { keyword: '', dateFrom: '', dateTo: '' };

    const $desc      = document.getElementById('ct-description');
    const $type      = document.getElementById('ct-type');
    const $account   = document.getElementById('ct-account');
    const $accNumRow = document.getElementById('ct-account-number-row');
    const $accNum    = document.getElementById('ct-account-number');
    const $amount    = document.getElementById('ct-amount');

    const $btnRec    = document.getElementById('btn-record');
    const $btnEdit   = document.getElementById('btn-edit');
    const $dateTime  = document.getElementById('record-datetime');
    const $histBody  = document.getElementById('history-tbody');
    const $histPager = document.getElementById('history-pagination');
    const $banner    = document.getElementById('edit-batch-banner');
    const $bannerLb  = document.getElementById('edit-batch-label');
    const $btnCancel = document.getElementById('cancel-edit-batch');
    const $hfDateFrom   = document.getElementById('hf-date-from');
    const $hfDateTo     = document.getElementById('hf-date-to');
    const $hfPageSize   = document.getElementById('hf-page-size');
    const $hfReset      = document.getElementById('hf-reset');
    const $hfCount      = document.getElementById('hf-count');
    const $hfKeyword    = document.getElementById('hf-keyword');
    const $hfKeywordBtn = document.getElementById('hf-keyword-btn');
    const $hfKeywordPop = document.getElementById('hf-keyword-pop');

    // je-card fields (render bởi helper/accounting.php)
    const $jeDebit  = document.getElementById('je-debit');
    const $jeCredit = document.getElementById('je-credit');
    const $jeAmount = document.getElementById('je-amount');

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

    function parseMoney(s) {
        return Number(String(s == null ? '' : s).replace(/[^\d.-]/g, '')) || 0;
    }

    function formatMoney(v) {
        const n = Math.round(Number(v) || 0);
        return n.toLocaleString('en-US') + ' đ';
    }

    function formatMoneyPlain(v) {
        const n = Math.round(Number(v) || 0);
        return n > 0 ? n.toLocaleString('en-US') : '';
    }

    // ---------- Datetime picker ----------
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

    // ---------- Account TM/TG toggle ----------
    function syncAccountNumberVisibility() {
        const isTG = $account.value === 'TG';
        $accNumRow.style.display = isTG ? '' : 'none';
        if (!isTG) $accNum.value = '';
    }

    // ---------- Prefill bút toán theo Phân loại (Thu/Chi) + Tài khoản (TM/TG) ----------
    // Truy vấn phiếu gần nhất CÙNG loại + tài khoản rồi dựng lại các cụm bút toán của nó
    // (bỏ trống Giá trị). Không chạy khi đang ở chế độ Sửa.
    function applyRecentJournal() {
        if (editingId) return;
        if (!window.JE || typeof window.JE.setEntries !== 'function') return;
        postForm('recent_journal', {
            transaction_type: $type.value,
            account_name:     $account.value
        }).then(res => {
            if (res && res.success && Array.isArray(res.data) && res.data.length) {
                window.JE.setEntries(res.data);
            } else if (typeof window.JE.reset === 'function') {
                window.JE.reset(); // chưa có phiếu khớp → 1 cụm trống
            }
            syncJeAmount();
        }).catch(() => {});
    }

    // ---------- je-card amount sync (block bút toán đầu tiên theo Số tiền) ----------
    function firstJeAmountEl() {
        if (window.JE && typeof window.JE.firstAmountInput === 'function') return window.JE.firstAmountInput();
        return $jeAmount; // fallback single-mode
    }
    function syncJeAmount() {
        const el = firstJeAmountEl();
        if (!el) return;
        const amt = parseMoney($amount.value);
        if (amt > 0) el.value = formatMoneyPlain(amt);
    }

    // Gửi tất cả bút toán (je_entries) + giữ je_debit/credit/amount của entry đầu cho tương thích.
    function jePayload() {
        const entries = (window.JE && typeof window.JE.collectEntries === 'function') ? window.JE.collectEntries() : [];
        const first = entries[0] || {};
        return {
            je_entries: JSON.stringify(entries),
            je_debit:   first.debit  || '',
            je_credit:  first.credit || '',
            je_amount:  first.amount || 0
        };
    }

    // ---------- Form read / reset ----------
    function readForm() {
        return {
            description:      $desc.value.trim(),
            transaction_type: $type.value,
            account_name:     $account.value,
            account_number:   $account.value === 'TG' ? $accNum.value.trim() : '',
            amount:           parseMoney($amount.value),
            created_at:       pickerToMysql()
        };
    }

    function validateForm(f) {
        if (f.description === '') return 'Chưa nhập Diễn giải.';
        if (['Thu', 'Chi'].indexOf(f.transaction_type) < 0) return 'Phân loại không hợp lệ.';
        if (['TM', 'TG'].indexOf(f.account_name) < 0) return 'Tài khoản không hợp lệ.';
        if (f.account_name === 'TG' && f.account_number === '') return 'Chọn TG thì phải nhập Số tài khoản.';
        if (!(f.amount > 0)) return 'Số tiền phải lớn hơn 0.';
        return '';
    }

    function resetForm() {
        $desc.value = '';
        $type.value = 'Chi';
        $account.value = 'TM';
        $accNum.value = '';
        $amount.value = '';
        syncAccountNumberVisibility();
        $dateTime.value = nowLocalValue();
        // Về 1 block bút toán trống.
        if (window.JE && typeof window.JE.reset === 'function') window.JE.reset();
    }

    function flashActive(btn) {
        [$btnRec, $btnEdit].forEach(b => b && b.classList.remove('active'));
        if (btn) btn.classList.add('active');
    }

    // ---------- Edit mode ----------
    function enterEditMode(row) {
        editingId = row.id;
        $desc.value = row.description || '';
        $type.value = row.transaction_type === 'Thu' ? 'Thu' : 'Chi';
        $account.value = row.account_name === 'TG' ? 'TG' : 'TM';
        syncAccountNumberVisibility();
        if (row.account_name === 'TG') $accNum.value = row.account_number || '';
        $amount.value = formatMoneyPlain(row.amount);
        // Sửa: nạp lại CHÍNH các bút toán đã ghi của phiếu này (đúng & khớp Giá trị),
        // không dùng mẫu chung. Mỗi cụm giữ Diễn giải + Mô tả nghiệp vụ riêng.
        if (window.JE && typeof window.JE.setEntries === 'function') {
            postForm('journal_entries', { id: row.id }).then(res => {
                if (res && res.success && Array.isArray(res.data) && res.data.length) {
                    window.JE.setEntries(res.data); // setEntries giữ nguyên Giá trị từng cụm
                } else if (typeof window.JE.reset === 'function') {
                    window.JE.reset();
                }
            }).catch(() => {});
        }
        const loc = mysqlToLocalValue(row.created_at);
        if (loc) $dateTime.value = loc;

        $banner.style.display = 'flex';
        $bannerLb.textContent = (row.date_display || '') + ' — ' + (row.description || '');
        $btnRec.style.display = 'none';
        document.querySelector('.content').scrollIntoView({ behavior: 'smooth' });
    }

    function exitEditMode() {
        editingId = null;
        $banner.style.display = 'none';
        $btnRec.style.display = '';
        flashActive(null);
        resetForm();
    }

    // ---------- Record / Edit ----------
    $btnRec.addEventListener('click', () => {
        if (editingId) return;
        const f = readForm();
        const err = validateForm(f);
        if (err) { alert(err); return; }
        flashActive($btnRec);
        postForm('record', Object.assign(f, jePayload())).then(res => {
            if (res && res.success) {
                if (window.appFlyToHistory) window.appFlyToHistory($btnRec);
                // TASK 1: reload lại trang để lấy ngày giờ ghi mới.
                setTimeout(() => window.location.reload(), 950);
            } else {
                alert(res && res.message ? res.message : 'Có lỗi xảy ra.');
                flashActive(null);
            }
        });
    });

    $btnEdit.addEventListener('click', () => {
        if (!editingId) {
            alert('Hãy chọn "Sửa" ở bảng Lịch sử để chỉnh phiếu cần sửa.');
            return;
        }
        const f = readForm();
        const err = validateForm(f);
        if (err) { alert(err); return; }
        flashActive($btnEdit);
        postForm('edit', Object.assign({ id: editingId }, f, jePayload())).then(res => {
            if (res && res.success) {
                if (window.appFlyToHistory) window.appFlyToHistory($btnEdit);
                history = res.history || [];
                renderHistoryPage();
                exitEditMode();
            } else {
                alert(res && res.message ? res.message : 'Có lỗi xảy ra.');
                flashActive(null);
            }
        });
    });

    $btnCancel.addEventListener('click', (e) => {
        e.preventDefault();
        exitEditMode();
    });

    // ---------- History render + pagination ----------
    // Dựng đúng chuỗi "Diễn giải" mà user thấy ở cột summary (để khớp khi lọc từ khóa).
    function buildSummary(b) {
        const isThu = b.transaction_type === 'Thu';
        const sign = isThu ? '+' : '−';
        return b.transaction_type + ' · ' + b.account_name
            + (b.account_number ? ' (' + b.account_number + ')' : '')
            + ' · ' + sign + formatMoney(b.amount) + ' — ' + b.description;
    }

    // Ngày của phiếu dạng 'YYYY-MM-DD'. Ưu tiên created_at ('YYYY-MM-DD HH:MM:SS');
    // fallback parse từ date_display ('HH:ii:ss dd/mm/yyyy' hoặc 'dd/mm/yyyy').
    function batchDateYMD(b) {
        const iso = /^(\d{4})-(\d{2})-(\d{2})/.exec(String(b && b.created_at ? b.created_at : ''));
        if (iso) return iso[1] + '-' + iso[2] + '-' + iso[3];
        const vn = /(\d{1,2})\/(\d{1,2})\/(\d{4})/.exec(String(b && b.date_display ? b.date_display : ''));
        if (vn) return vn[3] + '-' + pad2(vn[2]) + '-' + pad2(vn[1]);
        return '';
    }

    // Áp dụng bộ lọc (từ khóa + khoảng ngày) lên mảng history.
    function getFilteredHistory() {
        const all = history || [];
        const kw = histFilter.keyword.trim().toLowerCase();
        const from = histFilter.dateFrom;
        const to   = histFilter.dateTo;
        if (!kw && !from && !to) return all;
        return all.filter(b => {
            if (kw) {
                // Khớp với đúng nội dung user thấy ở cột Diễn giải.
                if (buildSummary(b).toLowerCase().indexOf(kw) === -1) return false;
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
        const total = (history || []).length;
        const data = getFilteredHistory();
        const totalPages = Math.max(1, Math.ceil(data.length / pageSize));
        if (historyPage > totalPages) historyPage = totalPages;
        if (historyPage < 1) historyPage = 1;

        if ($hfCount) {
            $hfCount.textContent = data.length === total
                ? (total + ' phiếu')
                : (data.length + '/' + total + ' phiếu');
        }
        updateFilterUI();

        if (!data.length) {
            const msg = total ? 'Không có phiếu nào khớp bộ lọc.' : 'Chưa có phiếu thu chi nào.';
            $histBody.innerHTML = '<tr class="history-empty"><td colspan="3">' + msg + '</td></tr>';
            $histPager.innerHTML = '';
            return;
        }

        const start = (historyPage - 1) * pageSize;
        const slice = data.slice(start, start + pageSize);
        // data-id là khóa ổn định → handler Sửa/Xóa tra cứu theo nó,
        // không phụ thuộc index (an toàn khi đang lọc/phân trang).
        $histBody.innerHTML = slice.map((b) => {
            const isThu = b.transaction_type === 'Thu';
            const summary = buildSummary(b);
            return `
                <tr data-id="${b.id}"${isThu ? ' class="ct-row-thu"' : ''}>
                    <td class="${b.date_color ? 'hist-date-' + b.date_color : ''}">${escapeHtml(b.date_display)}</td>
                    <td class="ct-history-summary${isThu ? ' ct-type-thu' : ''}" title="${escapeHtml(summary)}">${escapeHtml(summary)}</td>
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
        pageRange(historyPage, totalPages).forEach(p => {
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
        const id = parseInt(tr.getAttribute('data-id'), 10);
        const row = (history || []).find(r => +r.id === id);
        if (!row) return;

        if (editLink) { enterEditMode(row); return; }
        if (delLink) {
            if (!confirm('Xóa phiếu "' + row.description + '" (' + row.date_display + ')? Bút toán liên quan cũng bị xóa.')) return;
            postForm('delete', { id: row.id }).then(res => {
                if (res && res.success) {
                    history = res.history || [];
                    renderHistoryPage();
                    if (editingId === row.id) exitEditMode();
                } else {
                    alert(res && res.message ? res.message : 'Có lỗi xảy ra.');
                }
            });
        }
    });

    // ---------- Dropdown chọn nghiệp vụ đã nhập (right-click trên .je-transaction-name) ----------
    // Xổ danh sách các nghiệp vụ (Diễn giải nghiệp vụ) đã nhập trước đây, LỌC theo Phân loại
    // (.ct-type) + Tài khoản (#ct-account) hiện tại. Chọn 1 → điền cụm bút toán đó vào block.
    let $nvPanel    = null;   // panel dropdown dùng chung
    let nvOptions   = [];     // dữ liệu nghiệp vụ đang hiển thị
    let nvTarget    = null;   // .je-block đang được điền

    function fillJeBlock(block, e) {
        if (!block) return;
        const set = (sel, val) => { const el = block.querySelector(sel); if (el) el.value = val; };
        set('.je-transaction-name', e.transaction_name || '');
        set('.je-debit',       e.debit || e.debit_code || '');
        set('.je-debit-name',  e.debit_name || '');
        set('.je-credit',      e.credit || e.credit_code || '');
        set('.je-credit-name', e.credit_name || '');
        set('.je-description', e.description || '');
        // Giá trị để trống (gợi ý) — đồng bộ Số tiền vào block đầu nếu có.
        syncJeAmount();
    }

    function hideNvPanel() {
        if ($nvPanel) $nvPanel.style.display = 'none';
        nvTarget = null;
    }

    function buildNvPanel() {
        const p = document.createElement('div');
        p.className = 'ct-nv-dropdown';
        p.style.display = 'none';
        document.body.appendChild(p);
        document.addEventListener('click', (ev) => { if (!p.contains(ev.target)) hideNvPanel(); });
        document.addEventListener('keydown', (ev) => { if (ev.key === 'Escape') hideNvPanel(); });
        window.addEventListener('resize', hideNvPanel);
        p.addEventListener('click', (ev) => {
            const item = ev.target.closest('.ct-nv-item');
            if (!item) return;
            const idx = parseInt(item.getAttribute('data-idx'), 10);
            const entry = nvOptions[idx];
            if (entry) fillJeBlock(nvTarget, entry);
            hideNvPanel();
        });
        return p;
    }

    function positionNvPanel(panel, input) {
        const r = input.getBoundingClientRect();
        panel.style.left     = (window.scrollX + r.left) + 'px';
        panel.style.top      = (window.scrollY + r.bottom + 2) + 'px';
        panel.style.minWidth = r.width + 'px';
    }

    function renderNvPanel(panel) {
        if (!nvOptions.length) {
            panel.innerHTML = '<div class="ct-nv-empty">Chưa có nghiệp vụ nào cho lựa chọn này.</div>';
            return;
        }
        panel.innerHTML = nvOptions.map((e, i) => {
            const name = escapeHtml(e.transaction_name || '(không tên)');
            const acc  = [e.debit, e.credit].filter(Boolean).join(' / ');
            const desc = e.description ? ' · ' + escapeHtml(e.description) : '';
            return '<div class="ct-nv-item" data-idx="' + i + '" title="' + escapeHtml(e.transaction_name || '') + '">'
                +     '<span class="ct-nv-name">' + name + '</span>'
                +     '<span class="ct-nv-sub">' + escapeHtml(acc) + desc + '</span>'
                +  '</div>';
        }).join('');
    }

    function openNvDropdown(input) {
        nvTarget = input.closest('.je-block');
        const panel = $nvPanel || ($nvPanel = buildNvPanel());
        panel.innerHTML = '<div class="ct-nv-loading">Đang tải nghiệp vụ…</div>';
        positionNvPanel(panel, input);
        panel.style.display = 'block';
        postForm('journal_name_options', {
            transaction_type: $type.value,
            account_name:     $account.value
        }).then(res => {
            nvOptions = (res && res.success && Array.isArray(res.data)) ? res.data : [];
            renderNvPanel(panel);
            positionNvPanel(panel, input);
        }).catch(() => {
            nvOptions = [];
            renderNvPanel(panel);
        });
    }

    document.addEventListener('contextmenu', (e) => {
        const input = e.target.closest('.je-transaction-name');
        if (!input) return;
        e.preventDefault(); // chặn menu chuột phải mặc định, xổ dropdown nghiệp vụ thay thế
        openNvDropdown(input);
    });

    // ---------- Wire up ----------
    $account.addEventListener('change', () => { syncAccountNumberVisibility(); applyRecentJournal(); });
    $type.addEventListener('change', applyRecentJournal);
    $amount.addEventListener('input', syncJeAmount);
    $amount.addEventListener('blur', () => { $amount.value = formatMoneyPlain(parseMoney($amount.value)); });

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

    function init() {
        $dateTime.value = nowLocalValue();
        syncAccountNumberVisibility();
        setupHistoryFilter();
        renderHistoryPage();
    }

    init();
    // window.JE được khởi tạo trong DOMContentLoaded của journal_entry.js (nạp trước file này)
    // → prefill bút toán theo Phân loại + Tài khoản hiện tại sau khi JE sẵn sàng.
    document.addEventListener('DOMContentLoaded', applyRecentJournal);
})();
