/* ============================================================
 *  VSATTP — Phiếu tiếp nhận nguyên liệu đầu vào
 *  - Chọn khoảng ngày + chọn NVL (modal, search AJAX)
 *  - Lấy dữ liệu nhập NVL → bảng có field nhập tay (mặc định)
 *  - Phân trang client-side, sao chép "Người kiểm tra" xuống dưới
 *  - Xuất Excel (.xls) + In biểu mẫu
 * ============================================================ */
(function () {
    'use strict';

    var CFG = window.VSATTP_CFG || { baseUrl: '?mod=vsattp&controllers=vsattp&action=' };

    // ---- DOM ----
    var $from        = document.getElementById('vt-from');
    var $to          = document.getElementById('vt-to');
    var $openModal   = document.getElementById('vt-open-modal');
    var $modal       = document.getElementById('vt-modal');
    var $search      = document.getElementById('vt-search-material');
    var $dropdown    = document.getElementById('vt-search-dropdown');
    var $selected    = document.getElementById('vt-selected-list');
    var $showData    = document.getElementById('vt-show-data');
    var $tbody       = document.getElementById('vt-tbody');
    var $pagination  = document.getElementById('vt-pagination');
    var $perPage     = document.getElementById('vt-per-page');
    var $period      = document.getElementById('vt-period');
    var $exportExcel = document.getElementById('vt-export-excel');
    var $print       = document.getElementById('vt-print');

    // ---- State ----
    var selectedMaterials = [];   // [{id, material_name}]
    var rows    = [];             // dữ liệu bảng (mỗi dòng 1 object, gồm field nhập tay)
    var page    = 1;
    var perPage = 10;
    var searchTimer = null;

    // ---- Helpers ----
    function esc(s) {
        return String(s == null ? '' : s)
            .replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;').replace(/'/g, '&#39;');
    }

    function fmtQty(n) {
        if (n === '' || n === null || n === undefined) return '';
        var v = Number(n) || 0;
        return v.toLocaleString('vi-VN', { maximumFractionDigits: 2 });
    }

    function postForm(action, payload) {
        var fd = new FormData();
        Object.keys(payload || {}).forEach(function (k) { fd.append(k, payload[k]); });
        return fetch(CFG.baseUrl + action, {
            method: 'POST', body: fd, credentials: 'same-origin'
        }).then(function (r) { return r.json(); });
    }

    /* ========================================================
     *  Modal
     * ====================================================== */
    function openModal() {
        $modal.classList.add('open');
        $modal.setAttribute('aria-hidden', 'false');
        $search.focus();
    }
    function closeModal() {
        $modal.classList.remove('open');
        $modal.setAttribute('aria-hidden', 'true');
        hideDropdown();
    }

    $openModal.addEventListener('click', openModal);
    $modal.addEventListener('click', function (e) {
        if (e.target.hasAttribute('data-close-modal')) closeModal();
    });
    document.addEventListener('keydown', function (e) {
        if (e.key === 'Escape' && $modal.classList.contains('open')) closeModal();
    });

    /* ========================================================
     *  Search NVL (AJAX) + dropdown
     * ====================================================== */
    function hideDropdown() {
        $dropdown.classList.remove('active');
        $dropdown.innerHTML = '';
    }

    function runSearch() {
        var kw = $search.value.trim();
        if (kw === '') { hideDropdown(); return; }
        postForm('search_materials', { keyword: kw }).then(function (res) {
            var items = (res && res.data) || [];
            if (!items.length) {
                $dropdown.innerHTML = '<li class="empty">Không tìm thấy NVL</li>';
            } else {
                $dropdown.innerHTML = items.map(function (it) {
                    var added = selectedMaterials.some(function (m) { return m.id === Number(it.id); });
                    return '<li class="' + (added ? 'added' : '') + '"' +
                        ' data-id="' + Number(it.id) + '"' +
                        ' data-name="' + esc(it.material_name) + '">' +
                        esc(it.material_name) +
                        (added ? ' ✓' : '') + '</li>';
                }).join('');
            }
            $dropdown.classList.add('active');
        });
    }

    $search.addEventListener('input', function () {
        clearTimeout(searchTimer);
        searchTimer = setTimeout(runSearch, 200);
    });
    $search.addEventListener('focus', function () {
        if ($search.value.trim() !== '') runSearch();
    });

    $dropdown.addEventListener('mousedown', function (e) {
        var li = e.target.closest('li[data-id]');
        if (!li || li.classList.contains('added')) return;
        e.preventDefault();
        addMaterial(Number(li.getAttribute('data-id')), li.getAttribute('data-name'));
        $search.value = '';
        hideDropdown();
        $search.focus();
    });

    document.addEventListener('mousedown', function (e) {
        if (!$modal.classList.contains('open')) return;
        if (!$search.contains(e.target) && !$dropdown.contains(e.target)) hideDropdown();
    });

    /* ========================================================
     *  Danh sách NVL đã chọn (chips)
     * ====================================================== */
    function addMaterial(id, name) {
        if (selectedMaterials.some(function (m) { return m.id === id; })) return;
        selectedMaterials.push({ id: id, material_name: name });
        renderSelected();
    }
    function removeMaterial(id) {
        selectedMaterials = selectedMaterials.filter(function (m) { return m.id !== id; });
        renderSelected();
    }
    function renderSelected() {
        if (!selectedMaterials.length) {
            $selected.innerHTML = '<li class="vt-selected-empty">Chưa chọn NVL nào (để trống = lấy tất cả NVL).</li>';
            return;
        }
        $selected.innerHTML = selectedMaterials.map(function (m) {
            return '<li class="vt-chip" data-id="' + m.id + '">' +
                '<span>' + esc(m.material_name) + '</span>' +
                '<button type="button" title="Bỏ chọn">&times;</button></li>';
        }).join('');
    }
    $selected.addEventListener('click', function (e) {
        if (e.target.tagName !== 'BUTTON') return;
        var chip = e.target.closest('.vt-chip');
        if (chip) removeMaterial(Number(chip.getAttribute('data-id')));
    });

    /* ========================================================
     *  Hiển thị / nạp lại dữ liệu (neo theo khoảng ngày + NVL đã chọn)
     * ====================================================== */
    function loadData(closeAfter) {
        var ids = selectedMaterials.map(function (m) { return m.id; });
        return postForm('material_receiving_data', {
            from: $from.value,
            to: $to.value,
            material_ids: JSON.stringify(ids)
        }).then(function (res) {
            var data = (res && res.data) || [];
            rows = data.map(function (r) {
                return {
                    import_date:   r.import_date,
                    date_display:  r.date_display,
                    material_name: r.material_name,
                    supplier_name: r.supplier_name,
                    lot:           r.lot || '',       // Số lô/NSX = ngày nhập lùi ≤60 ngày
                    expiry:        r.expiry || '',    // Hạn sử dụng = NSX + 365 ngày
                    quantity:      r.quantity,
                    unit:          r.unit,
                    documents:     '',               // Giấy tờ kèm theo — bỏ trống
                    sensory:       'Đạt',            // Kiểm tra cảm quan — mặc định
                    conclusion:    'Nhận',           // Kết luận — mặc định
                    inspector:     ''                // Người kiểm tra — nhập tay
                };
            });
            page = 1;
            renderPeriod();
            renderTable();
            if (closeAfter) closeModal();
        });
    }

    $showData.addEventListener('click', function () { loadData(true); });

    /* ========================================================
     *  Render bảng + phân trang
     * ====================================================== */
    function renderPeriod() {
        var f = $from.value, t = $to.value;
        var txt = '';
        if (f && t) txt = 'Từ ngày ' + fmtDmy(f) + ' đến ngày ' + fmtDmy(t);
        else if (f) txt = 'Từ ngày ' + fmtDmy(f);
        else if (t) txt = 'Đến ngày ' + fmtDmy(t);
        $period.textContent = txt;
    }
    function fmtDmy(ymd) {
        var p = String(ymd).split('-');
        return p.length === 3 ? (p[2] + '/' + p[1] + '/' + p[0]) : ymd;
    }

    function getPerPage() {
        var v = parseInt($perPage.value, 10);
        return isNaN(v) ? 10 : v; // 0 = tất cả
    }

    function renderTable() {
        if (!rows.length) {
            $tbody.innerHTML = '<tr class="vt-empty-row"><td colspan="12">' +
                'Không có dữ liệu phù hợp với khoảng ngày / NVL đã chọn.</td></tr>';
            $pagination.innerHTML = '';
            return;
        }

        perPage = getPerPage();
        var total = rows.length;
        var pages = perPage === 0 ? 1 : Math.ceil(total / perPage);
        if (page > pages) page = pages;
        var start = perPage === 0 ? 0 : (page - 1) * perPage;
        var end   = perPage === 0 ? total : Math.min(start + perPage, total);

        var html = '';
        for (var i = start; i < end; i++) {
            html += rowHtml(rows[i], i);
        }
        $tbody.innerHTML = html;
        renderPagination(pages);
    }

    function rowHtml(r, idx) {
        var isFirst = idx === 0;
        return '<tr data-idx="' + idx + '">' +
            '<td>' + (idx + 1) + '</td>' +
            '<td>' + esc(r.date_display) + '</td>' +
            '<td class="vt-cell-name">' + esc(r.material_name) + '</td>' +
            '<td class="vt-cell-supplier">' + esc(r.supplier_name) + '</td>' +
            '<td>' + esc(r.lot) + '</td>' +
            '<td><input type="text" class="vt-cell-input" data-field="expiry" value="' + esc(r.expiry) + '"></td>' +
            '<td>' + fmtQty(r.quantity) + '</td>' +
            '<td>' + esc(r.unit) + '</td>' +
            '<td><input type="text" class="vt-cell-input" data-field="documents" value="' + esc(r.documents) + '"></td>' +
            '<td><input type="text" class="vt-cell-input" data-field="sensory" value="' + esc(r.sensory) + '"></td>' +
            '<td><input type="text" class="vt-cell-input" data-field="conclusion" value="' + esc(r.conclusion) + '"></td>' +
            '<td class="vt-cell-inspector">' +
                '<input type="text" class="vt-cell-input" data-field="inspector" value="' + esc(r.inspector) + '">' +
                (isFirst ? '<span class="vt-copy-down' + (r.inspector ? ' show' : '') + '">↓ Sao chép cho các dòng dưới</span>' : '') +
            '</td>' +
        '</tr>';
    }

    // Cập nhật state khi user gõ vào ô nhập tay
    $tbody.addEventListener('input', function (e) {
        var inp = e.target.closest('.vt-cell-input');
        if (!inp) return;
        var tr = inp.closest('tr[data-idx]');
        if (!tr) return;
        var idx = Number(tr.getAttribute('data-idx'));
        var field = inp.getAttribute('data-field');
        if (rows[idx]) rows[idx][field] = inp.value;

        // Dòng đầu + field inspector → hiện nút sao chép
        if (idx === 0 && field === 'inspector') {
            var btn = tr.querySelector('.vt-copy-down');
            if (btn) btn.classList.toggle('show', inp.value.trim() !== '');
        }
    });

    // Sao chép "Người kiểm tra" của dòng đầu cho tất cả dòng dưới
    $tbody.addEventListener('click', function (e) {
        var btn = e.target.closest('.vt-copy-down');
        if (!btn) return;
        var val = rows.length ? rows[0].inspector : '';
        for (var i = 1; i < rows.length; i++) rows[i].inspector = val;
        renderTable();
    });

    function renderPagination(pages) {
        if (pages <= 1) { $pagination.innerHTML = ''; return; }
        var html = '';
        html += '<button data-page="prev"' + (page === 1 ? ' disabled' : '') + '>‹</button>';
        for (var p = 1; p <= pages; p++) {
            html += '<button data-page="' + p + '"' + (p === page ? ' class="active"' : '') + '>' + p + '</button>';
        }
        html += '<button data-page="next"' + (page === pages ? ' disabled' : '') + '>›</button>';
        $pagination.innerHTML = html;
    }

    $pagination.addEventListener('click', function (e) {
        var btn = e.target.closest('button[data-page]');
        if (!btn || btn.disabled) return;
        var v = btn.getAttribute('data-page');
        var pages = perPage === 0 ? 1 : Math.ceil(rows.length / perPage);
        if (v === 'prev') page = Math.max(1, page - 1);
        else if (v === 'next') page = Math.min(pages, page + 1);
        else page = parseInt(v, 10);
        renderTable();
    });

    $perPage.addEventListener('change', function () { page = 1; renderTable(); });

    /* ========================================================
     *  Xuất Excel (.xls)
     * ====================================================== */
    function buildSheetTable() {
        var head = '<tr>' +
            '<th>STT</th><th>Ngày nhập</th><th>Tên nguyên vật liệu/phụ gia</th>' +
            '<th>Nhà cung cấp</th><th>Số lô/NSX</th><th>Hạn sử dụng</th>' +
            '<th>Số lượng</th><th>ĐVT</th><th>Giấy tờ kèm theo</th>' +
            '<th>Kiểm tra cảm quan</th><th>Kết luận</th><th>Người kiểm tra</th></tr>';
        var body = rows.map(function (r, i) {
            return '<tr>' +
                '<td>' + (i + 1) + '</td>' +
                '<td>' + esc(r.date_display) + '</td>' +
                '<td>' + esc(r.material_name) + '</td>' +
                '<td>' + esc(r.supplier_name) + '</td>' +
                '<td>' + esc(r.lot) + '</td>' +
                '<td>' + esc(r.expiry) + '</td>' +
                '<td>' + fmtQty(r.quantity) + '</td>' +
                '<td>' + esc(r.unit) + '</td>' +
                '<td>' + esc(r.documents) + '</td>' +
                '<td>' + esc(r.sensory) + '</td>' +
                '<td>' + esc(r.conclusion) + '</td>' +
                '<td>' + esc(r.inspector) + '</td>' +
            '</tr>';
        }).join('');
        return '<table border="1">' + head + body + '</table>';
    }

    // Cụm chữ ký dưới bảng — lấy đúng nội dung từ .vt-signatures trên trang.
    function buildSignatures() {
        var cols = [];
        document.querySelectorAll('#vt-sheet .vt-sign-col').forEach(function (c) {
            var role = c.querySelector('.vt-sign-role');
            var note = c.querySelector('.vt-sign-note');
            cols.push({
                role: role ? role.textContent : '',
                note: note ? note.textContent : ''
            });
        });
        if (!cols.length) return '';
        var roleRow = '<tr>' + cols.map(function (c) {
            return '<td align="center"><b>' + esc(c.role) + '</b></td>';
        }).join('') + '</tr>';
        var noteRow = '<tr>' + cols.map(function (c) {
            return '<td align="center"><i>' + esc(c.note) + '</i></td>';
        }).join('') + '</tr>';
        // dòng trống tạo khoảng cách giữa bảng dữ liệu và cụm chữ ký
        var spacer = '<tr><td colspan="' + cols.length + '">&nbsp;</td></tr>';
        return '<table>' + spacer + roleRow + noteRow + '</table>';
    }

    $exportExcel.addEventListener('click', function () {
        if (!rows.length) { alert('Chưa có dữ liệu để xuất.'); return; }
        var title = 'PHIẾU TIẾP NHẬN NGUYÊN LIỆU ĐẦU VÀO';
        var caption =
            '<table><tr><td><b>Công ty TNHH Vua An Toàn</b></td></tr>' +
            '<tr><td><b>' + title + '</b></td></tr>' +
            '<tr><td>' + esc($period.textContent) + '</td></tr></table>';
        var inner = caption + buildSheetTable() + buildSignatures();
        var html =
            '<html xmlns:o="urn:schemas-microsoft-com:office:office" ' +
            'xmlns:x="urn:schemas-microsoft-com:office:excel" ' +
            'xmlns="http://www.w3.org/TR/REC-html40">' +
            '<head><meta charset="UTF-8"></head><body>' + inner + '</body></html>';

        var blob = new Blob(['﻿', html], { type: 'application/vnd.ms-excel;charset=utf-8' });
        var url  = URL.createObjectURL(blob);
        var a    = document.createElement('a');
        a.href = url;
        a.download = 'Phieu_tiep_nhan_NVL.xls';
        document.body.appendChild(a);
        a.click();
        document.body.removeChild(a);
        setTimeout(function () { URL.revokeObjectURL(url); }, 0);
    });

    /* ========================================================
     *  In biểu mẫu — in toàn bộ dòng (bỏ phân trang tạm thời)
     * ====================================================== */
    $print.addEventListener('click', function () {
        if (!rows.length) { alert('Chưa có dữ liệu để in.'); return; }
        var savedPage = page, savedHtml = $tbody.innerHTML, savedPag = $pagination.innerHTML;
        $tbody.innerHTML = rows.map(function (r, i) { return rowHtml(r, i); }).join('');
        $pagination.innerHTML = '';
        window.print();
        // khôi phục lại trạng thái phân trang
        page = savedPage;
        $tbody.innerHTML = savedHtml;
        $pagination.innerHTML = savedPag;
    });

    // Đổi ngày → cập nhật nhãn + nạp lại dữ liệu qua AJAX (neo theo khoảng ngày)
    function onDateChange() { renderPeriod(); loadData(false); }
    $from.addEventListener('change', onDateChange);
    $to.addEventListener('change', onDateChange);

    // Khởi tạo: hiển thị nhãn + nạp dữ liệu theo khoảng ngày mặc định
    renderPeriod();
    loadData(false);
})();
