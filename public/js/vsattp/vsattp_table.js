/* ============================================================
 *  VSATTP — Generic table engine cho các biểu mẫu theo sản phẩm.
 *  Dùng chung cho: production_log, process_control, finished_goods_ledger,
 *  sanitation_log, health_training_log (manual), traceability.
 *
 *  State chia sẻ giữa các view qua localStorage 'vsattp_shared':
 *    { productIds:[], products:[{id,name}], from:'YYYY-MM-DD', to:'YYYY-MM-DD' }
 *  → Sản phẩm/khoảng ngày chọn ở 1 view sẽ đồng bộ sang các view liên kết.
 *
 *  Cấu hình: window.VsattpTable({ action, title, hasProduct, hasDate, manual, columns })
 *    columns[i] = { kind:'stt'|'auto'|'input', key, label, width, numeric, default, copyDown }
 * ============================================================ */
(function () {
    'use strict';

    window.VsattpTable = function (cfg) {
        var CFG = window.VSATTP_CFG || { baseUrl: '?mod=vsattp&controllers=vsattp&action=' };
        var STATE_KEY = 'vsattp_shared';
        var COMPANY = 'Công ty TNHH Vua An Toàn';

        var byId = function (id) { return document.getElementById(id); };
        var $from = byId('vt-from'), $to = byId('vt-to'), $period = byId('vt-period');
        var $openModal = byId('vt-open-modal'), $modal = byId('vt-modal'),
            $searchInput = byId('vt-search-input'), $dropdown = byId('vt-search-dropdown'),
            $selected = byId('vt-selected-list'), $showData = byId('vt-show-data');
        var $thead = byId('vt-thead'), $tbody = byId('vt-tbody'),
            $pagination = byId('vt-pagination'), $perPage = byId('vt-per-page');
        var $exportExcel = byId('vt-export-excel'), $print = byId('vt-print'), $addRow = byId('vt-add-row');

        var rows = [], page = 1, perPage = 10, copied = {}, searchTimer = null, activeIdx = -1;
        var cols = cfg.columns || [];

        /* ---------- helpers ---------- */
        function esc(s) {
            return String(s == null ? '' : s)
                .replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;')
                .replace(/"/g, '&quot;').replace(/'/g, '&#39;');
        }
        function fmtQty(n) {
            if (n === '' || n === null || n === undefined) return '';
            var v = Number(n);
            if (isNaN(v)) return esc(n);
            return v.toLocaleString('vi-VN', { maximumFractionDigits: 2 });
        }
        function pad(n) { return n < 10 ? '0' + n : '' + n; }
        function today() { var d = new Date(); return d.getFullYear() + '-' + pad(d.getMonth() + 1) + '-' + pad(d.getDate()); }
        function fmtDmy(ymd) { var p = String(ymd).split('-'); return p.length === 3 ? (p[2] + '/' + p[1] + '/' + p[0]) : ymd; }
        function postForm(action, payload) {
            var fd = new FormData();
            Object.keys(payload || {}).forEach(function (k) { fd.append(k, payload[k]); });
            return fetch(CFG.baseUrl + action, { method: 'POST', body: fd, credentials: 'same-origin' })
                .then(function (r) { return r.json(); });
        }

        /* ---------- shared state ---------- */
        function loadState() { try { var s = JSON.parse(localStorage.getItem(STATE_KEY)); if (s && typeof s === 'object') return s; } catch (e) {} return {}; }
        function saveState() { try { localStorage.setItem(STATE_KEY, JSON.stringify(state)); } catch (e) {} }
        var state = loadState();
        state.productIds = state.productIds || [];
        state.products = state.products || [];
        if (!state.from) state.from = today();
        if (!state.to) state.to = today();

        /* ---------- thead ---------- */
        function buildHead() {
            if (!$thead) return;
            $thead.innerHTML = '<tr>' + cols.map(function (c) {
                var w = c.width ? ' style="width:' + c.width + '"' : '';
                return '<th' + w + '>' + esc(c.label) + '</th>';
            }).join('') + '</tr>';
        }

        /* ---------- map dữ liệu server → row state ---------- */
        function mapRows(data) {
            copied = {};
            return data.map(function (r) {
                var row = {};
                cols.forEach(function (c) {
                    if (c.kind === 'stt') return;
                    if (c.kind === 'input') {
                        row[c.key] = (r[c.key] != null && r[c.key] !== '') ? r[c.key] : (c.default != null ? c.default : '');
                    } else {
                        row[c.key] = r[c.key] != null ? r[c.key] : '';
                    }
                });
                return row;
            });
        }
        function blankRow() {
            var row = {};
            cols.forEach(function (c) {
                if (c.kind === 'stt') return;
                row[c.key] = (c.kind === 'input' && c.default != null) ? c.default : '';
            });
            return row;
        }

        /* ---------- render bảng ---------- */
        function getPerPage() { var v = parseInt($perPage ? $perPage.value : '10', 10); return isNaN(v) ? 10 : v; }
        function lastPage() { perPage = getPerPage(); return perPage === 0 ? 1 : Math.max(1, Math.ceil(rows.length / perPage)); }

        function cellHtml(c, row, gIdx) {
            if (c.kind === 'stt') return '<td>' + (gIdx + 1) + '</td>';
            if (c.kind === 'input') {
                var val = row[c.key] != null ? row[c.key] : '';
                var copyBtn = '';
                if (c.copyDown && gIdx === 0) {
                    var show = String(val).trim() !== '' && !copied[c.key];
                    copyBtn = '<span class="vt-copy-down' + (show ? ' show' : '') + '" data-field="' + c.key + '">↓ Sao chép cho các dòng dưới</span>';
                }
                var cls = c.copyDown ? ' class="vt-cell-inspector"' : '';
                return '<td' + cls + '><input type="text" class="vt-cell-input" data-field="' + c.key + '" value="' + esc(val) + '">' + copyBtn + '</td>';
            }
            var v = c.numeric ? fmtQty(row[c.key]) : esc(row[c.key]);
            return '<td class="' + (c.cls || '') + '">' + v + '</td>';
        }
        function rowHtml(row, gIdx) {
            return '<tr data-idx="' + gIdx + '">' + cols.map(function (c) { return cellHtml(c, row, gIdx); }).join('') + '</tr>';
        }
        function renderTable() {
            if (!rows.length) {
                $tbody.innerHTML = '<tr class="vt-empty-row"><td colspan="' + cols.length + '">' +
                    (cfg.manual ? 'Chưa có dòng nào. Bấm “Thêm dòng” để nhập.' : 'Không có dữ liệu phù hợp với lựa chọn.') + '</td></tr>';
                if ($pagination) $pagination.innerHTML = '';
                return;
            }
            perPage = getPerPage();
            var total = rows.length;
            var pages = perPage === 0 ? 1 : Math.ceil(total / perPage);
            if (page > pages) page = pages;
            var start = perPage === 0 ? 0 : (page - 1) * perPage;
            var end = perPage === 0 ? total : Math.min(start + perPage, total);
            var html = '';
            for (var i = start; i < end; i++) html += rowHtml(rows[i], i);
            $tbody.innerHTML = html;
            renderPagination(pages);
        }
        function renderPagination(pages) {
            if (!$pagination) return;
            if (pages <= 1) { $pagination.innerHTML = ''; return; }
            var html = '<button data-page="prev"' + (page === 1 ? ' disabled' : '') + '>‹</button>';
            for (var p = 1; p <= pages; p++) html += '<button data-page="' + p + '"' + (p === page ? ' class="active"' : '') + '>' + p + '</button>';
            html += '<button data-page="next"' + (page === pages ? ' disabled' : '') + '>›</button>';
            $pagination.innerHTML = html;
        }
        function renderPeriod() {
            if (!$period) return;
            if (!cfg.hasDate) { $period.textContent = ''; return; }
            var f = $from ? $from.value : '', t = $to ? $to.value : '';
            $period.textContent = (f && t) ? ('Từ ngày ' + fmtDmy(f) + ' đến ngày ' + fmtDmy(t))
                : f ? ('Từ ngày ' + fmtDmy(f)) : t ? ('Đến ngày ' + fmtDmy(t)) : '';
        }

        /* ---------- nạp dữ liệu ---------- */
        function loadData(closeAfter) {
            if (cfg.manual || !cfg.action) { renderPeriod(); renderTable(); return Promise.resolve(); }
            return postForm(cfg.action, {
                from: state.from, to: state.to,
                product_ids: JSON.stringify(state.productIds)
            }).then(function (res) {
                rows = mapRows((res && res.data) || []);
                page = 1;
                renderPeriod();
                renderTable();
                if (closeAfter) closeModal();
            });
        }

        /* ---------- tbody: input + copy-down ---------- */
        if ($tbody) {
            $tbody.addEventListener('input', function (e) {
                var inp = e.target.closest('.vt-cell-input');
                if (!inp) return;
                var tr = inp.closest('tr[data-idx]'); if (!tr) return;
                var idx = Number(tr.getAttribute('data-idx'));
                var field = inp.getAttribute('data-field');
                if (rows[idx]) rows[idx][field] = inp.value;
                if (idx === 0) {
                    var btn = tr.querySelector('.vt-copy-down[data-field="' + field + '"]');
                    if (btn && !copied[field]) btn.classList.toggle('show', inp.value.trim() !== '');
                }
            });
            $tbody.addEventListener('click', function (e) {
                var btn = e.target.closest('.vt-copy-down');
                if (!btn) return;
                var field = btn.getAttribute('data-field');
                var val = rows.length ? rows[0][field] : '';
                for (var i = 1; i < rows.length; i++) rows[i][field] = val;
                copied[field] = true;          // sao chép xong → tắt button
                renderTable();
            });
        }
        if ($pagination) {
            $pagination.addEventListener('click', function (e) {
                var btn = e.target.closest('button[data-page]'); if (!btn || btn.disabled) return;
                var v = btn.getAttribute('data-page'); var pages = lastPage();
                if (v === 'prev') page = Math.max(1, page - 1);
                else if (v === 'next') page = Math.min(pages, page + 1);
                else page = parseInt(v, 10);
                renderTable();
            });
        }
        if ($perPage) $perPage.addEventListener('change', function () { page = 1; renderTable(); });

        /* ---------- chọn sản phẩm (modal) ---------- */
        function openModal() { if ($modal) { $modal.classList.add('open'); $modal.setAttribute('aria-hidden', 'false'); if ($searchInput) $searchInput.focus(); } }
        function closeModal() { if ($modal) { $modal.classList.remove('open'); $modal.setAttribute('aria-hidden', 'true'); hideDropdown(); } }
        function hideDropdown() { if ($dropdown) { $dropdown.classList.remove('active'); $dropdown.innerHTML = ''; } activeIdx = -1; }
        function dropdownItems() { return $dropdown ? $dropdown.querySelectorAll('li[data-id]') : []; }
        function highlightDropdown() {
            var items = dropdownItems();
            for (var i = 0; i < items.length; i++) items[i].classList.toggle('kbd-active', i === activeIdx);
            if (activeIdx >= 0 && items[activeIdx]) items[activeIdx].scrollIntoView({ block: 'nearest' });
        }
        function pickDropdownItem(li) {
            if (!li || li.classList.contains('added')) return;
            addProduct(Number(li.getAttribute('data-id')), li.getAttribute('data-name'));
            $searchInput.value = ''; hideDropdown(); $searchInput.focus();
        }

        function renderSelected() {
            if (!$selected) return;
            if (!state.products.length) {
                $selected.innerHTML = '<li class="vt-selected-empty">Chưa chọn sản phẩm nào (để trống = lấy tất cả).</li>';
                return;
            }
            $selected.innerHTML = state.products.map(function (m) {
                return '<li class="vt-chip" data-id="' + m.id + '"><span>' + esc(m.name) + '</span>' +
                    '<button type="button" title="Bỏ chọn">&times;</button></li>';
            }).join('');
        }
        function addProduct(id, name) {
            if (state.productIds.indexOf(id) !== -1) return;
            state.productIds.push(id);
            state.products.push({ id: id, name: name });
            saveState(); renderSelected();
        }
        function removeProduct(id) {
            state.productIds = state.productIds.filter(function (x) { return x !== id; });
            state.products = state.products.filter(function (m) { return m.id !== id; });
            saveState(); renderSelected();
        }
        function runSearch() {
            if (!$searchInput) return;
            var kw = $searchInput.value.trim();
            activeIdx = -1;
            if (kw === '') { hideDropdown(); return; }
            postForm('search_products', { keyword: kw }).then(function (res) {
                var items = (res && res.data) || [];
                if (!items.length) { $dropdown.innerHTML = '<li class="empty">Không tìm thấy sản phẩm</li>'; }
                else {
                    $dropdown.innerHTML = items.map(function (it) {
                        var added = state.productIds.indexOf(Number(it.id)) !== -1;
                        return '<li class="' + (added ? 'added' : '') + '" data-id="' + Number(it.id) +
                            '" data-name="' + esc(it.product_name) + '">' + esc(it.product_name) + (added ? ' ✓' : '') + '</li>';
                    }).join('');
                }
                activeIdx = -1;
                $dropdown.classList.add('active');
            });
        }
        if ($openModal) $openModal.addEventListener('click', openModal);
        if ($modal) $modal.addEventListener('click', function (e) { if (e.target.hasAttribute('data-close-modal')) closeModal(); });
        if ($searchInput) {
            $searchInput.addEventListener('input', function () { clearTimeout(searchTimer); searchTimer = setTimeout(runSearch, 200); });
            $searchInput.addEventListener('focus', function () { if ($searchInput.value.trim() !== '') runSearch(); });
            // Điều khiển bàn phím cho dropdown gợi ý (xem [[dropdown-keyboard-nav-default]]):
            // mũi tên lên/xuống để duyệt, Enter hoặc Tab để chọn dòng đang tô sáng, Escape để đóng.
            $searchInput.addEventListener('keydown', function (e) {
                if (!$dropdown || !$dropdown.classList.contains('active')) return;
                var items = dropdownItems();
                if (!items.length) return;
                if (e.key === 'ArrowDown') { e.preventDefault(); activeIdx = (activeIdx + 1) % items.length; highlightDropdown(); }
                else if (e.key === 'ArrowUp') { e.preventDefault(); activeIdx = (activeIdx - 1 + items.length) % items.length; highlightDropdown(); }
                else if (e.key === 'Enter') { if (activeIdx >= 0) { e.preventDefault(); pickDropdownItem(items[activeIdx]); } }
                else if (e.key === 'Tab') { if (activeIdx >= 0) pickDropdownItem(items[activeIdx]); }
                else if (e.key === 'Escape') { hideDropdown(); }
            });
        }
        if ($dropdown) {
            $dropdown.addEventListener('mousedown', function (e) {
                var li = e.target.closest('li[data-id]'); if (!li) return;
                e.preventDefault();
                pickDropdownItem(li);
            });
        }
        if ($selected) {
            $selected.addEventListener('click', function (e) {
                if (e.target.tagName !== 'BUTTON') return;
                var chip = e.target.closest('.vt-chip'); if (chip) removeProduct(Number(chip.getAttribute('data-id')));
            });
        }
        if ($showData) $showData.addEventListener('click', function () { loadData(true); });
        document.addEventListener('mousedown', function (e) {
            if (!$modal || !$modal.classList.contains('open')) return;
            if ($searchInput && !$searchInput.contains(e.target) && $dropdown && !$dropdown.contains(e.target)) hideDropdown();
        });
        document.addEventListener('keydown', function (e) { if (e.key === 'Escape') closeModal(); });

        /* ---------- date range ---------- */
        function onDateChange() {
            if ($from) state.from = $from.value;
            if ($to) state.to = $to.value;
            saveState(); renderPeriod(); loadData(false);
        }
        if ($from) $from.addEventListener('change', onDateChange);
        if ($to) $to.addEventListener('change', onDateChange);

        /* ---------- manual: thêm dòng ---------- */
        if ($addRow) {
            $addRow.addEventListener('click', function () {
                rows.push(blankRow());
                page = lastPage();
                renderTable();
            });
        }

        /* ---------- Export Excel + In ---------- */
        function buildSignatures() {
            var c = [];
            document.querySelectorAll('#vt-sheet .vt-sign-col').forEach(function (col) {
                var role = col.querySelector('.vt-sign-role'), note = col.querySelector('.vt-sign-note');
                c.push({ role: role ? role.textContent : '', note: note ? note.textContent : '' });
            });
            if (!c.length) return '';
            var roleRow = '<tr>' + c.map(function (x) { return '<td align="center"><b>' + esc(x.role) + '</b></td>'; }).join('') + '</tr>';
            var noteRow = '<tr>' + c.map(function (x) { return '<td align="center"><i>' + esc(x.note) + '</i></td>'; }).join('') + '</tr>';
            return '<table><tr><td colspan="' + c.length + '">&nbsp;</td></tr>' + roleRow + noteRow + '</table>';
        }
        function buildExportTable() {
            var head = '<tr>' + cols.map(function (c) { return '<th>' + esc(c.label) + '</th>'; }).join('') + '</tr>';
            var body = rows.map(function (row, i) {
                return '<tr>' + cols.map(function (c) {
                    if (c.kind === 'stt') return '<td>' + (i + 1) + '</td>';
                    var v = c.numeric ? fmtQty(row[c.key]) : esc(row[c.key]);
                    return '<td>' + v + '</td>';
                }).join('') + '</tr>';
            }).join('');
            return '<table border="1">' + head + body + '</table>';
        }
        if ($exportExcel) {
            $exportExcel.addEventListener('click', function () {
                if (!rows.length) { alert('Chưa có dữ liệu để xuất.'); return; }
                var caption = '<table><tr><td><b>' + esc(COMPANY) + '</b></td></tr>' +
                    '<tr><td><b>' + esc(cfg.title) + '</b></td></tr>' +
                    ($period && $period.textContent ? '<tr><td>' + esc($period.textContent) + '</td></tr>' : '') + '</table>';
                var inner = caption + buildExportTable() + buildSignatures();
                var html = '<html xmlns:o="urn:schemas-microsoft-com:office:office" ' +
                    'xmlns:x="urn:schemas-microsoft-com:office:excel" xmlns="http://www.w3.org/TR/REC-html40">' +
                    '<head><meta charset="UTF-8"></head><body>' + inner + '</body></html>';
                var blob = new Blob(['﻿', html], { type: 'application/vnd.ms-excel;charset=utf-8' });
                var url = URL.createObjectURL(blob), a = document.createElement('a');
                a.href = url;
                a.download = (cfg.title || 'bieu_mau').replace(/[\\/:*?"<>|]+/g, '_').replace(/\s+/g, '_') + '.xls';
                document.body.appendChild(a); a.click(); document.body.removeChild(a);
                setTimeout(function () { URL.revokeObjectURL(url); }, 0);
            });
        }
        if ($print) {
            $print.addEventListener('click', function () {
                if (!rows.length) { alert('Chưa có dữ liệu để in.'); return; }
                var savedPage = page, savedHtml = $tbody.innerHTML, savedPag = $pagination ? $pagination.innerHTML : '';
                $tbody.innerHTML = rows.map(function (r, i) { return rowHtml(r, i); }).join('');
                if ($pagination) $pagination.innerHTML = '';
                window.print();
                page = savedPage; $tbody.innerHTML = savedHtml;
                if ($pagination) $pagination.innerHTML = savedPag;
            });
        }

        /* ---------- init ---------- */
        buildHead();
        if ($from) $from.value = state.from;
        if ($to) $to.value = state.to;
        renderSelected();
        renderPeriod();
        if (cfg.manual) { rows = [blankRow()]; renderTable(); }
        else loadData(false);
    };
})();
