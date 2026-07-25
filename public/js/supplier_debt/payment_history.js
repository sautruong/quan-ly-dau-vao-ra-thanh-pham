/* =====================================================================
 *  payment_history.js — Công nợ NCC / Lịch sử thanh toán
 *  - Tạo phiếu chi (lưu + đính kèm ảnh) → mở modal PHIẾU CHI (In/Share)
 *  - Lịch sử: lọc theo NCC, sửa, xóa, phân trang, chọn số dòng
 *  - Click tên NCC → modal các lần mua (gần nhất trước, có phân trang)
 * ===================================================================== */
(function () {
    'use strict';

    var CFG = window.SD_CONFIG || { baseUrl: '?mod=supplier_debt&controllers=supplier_debt&action=' };
    var INITIAL = window.SD_DATA || { history: [], historyTotal: 0 };

    var PRINT_SETTINGS = Object.assign({
        company_name:      'CÔNG TY TNHH VUA AN TOÀN',
        company_enname:    'SAFE KING Co,.LTD',
        company_address:   '1/13Z Ấp Tiền Lân, Xã Bà Điểm, Thành Phố Hồ Chí Minh, Việt Nam.',
        company_phone:     '',
        company_email:     '',
        signer_director:   'LÊ HỮU TRÍ',
        signer_accountant: '',
        signer_preparer:   'TRƯƠNG NGỌC SÁU',
    }, window.PRINT_SETTINGS || {});

    // Vùng ký (chức danh phía công ty) — động: thêm/sửa/xóa/kéo sắp xếp.
    // "Người nhận tiền" KHÔNG nằm trong đây — luôn là 1 dòng ký cố định, để trống tên.
    var SIGN_CFG_DEFAULT_ROLES = ['Giám đốc', 'Kế toán trưởng', 'Người lập phiếu'];
    var SIGN_CFG = window.SIGN_CONFIG || { sign_roles: SIGN_CFG_DEFAULT_ROLES, signs: [] };
    var signRoles = (Array.isArray(SIGN_CFG.sign_roles) && SIGN_CFG.sign_roles.length)
        ? SIGN_CFG.sign_roles.slice() : SIGN_CFG_DEFAULT_ROLES.slice();
    var signs = Array.isArray(SIGN_CFG.signs)
        ? SIGN_CFG.signs.map(function (s) { return { role: String(s.role || ''), name: String(s.name || '') }; })
        : [];

    /* ---------------- State ---------------- */
    var selectedSupplier = null;          // { id, supplier_name, address }
    var editingId = null;
    var histPage = 1, histPerPage = 5, histFilterId = 0, histTotal = INITIAL.historyTotal | 0;
    var histDateFrom = '', histDateTo = '';   // khoảng ngày lọc (YYYY-MM-DD)
    var currentVoucher = null;            // dữ liệu phiếu chi đang hiển thị trên modal

    // dropdown nhập liệu
    var supItems = [], supActive = -1;
    // dropdown lọc
    var filtItems = [];
    // modal purchases
    var purSupplierId = 0, purPage = 1, purPerPage = 5, purTotal = 0, purSupplierName = '';

    /* ---------------- DOM ---------------- */
    var $ = function (id) { return document.getElementById(id); };
    var $dt        = $('record-datetime');
    var $search    = $('search-suppliers');
    var $dropdown  = $('search-dropdown');
    var $amount    = $('sd-amount');
    var $note      = $('sd-note');
    var $btnCreate = $('btn-create');
    var $btnGhi    = $('btn-ghi');
    var $btnUpdate = $('btn-update');
    var $banner    = $('edit-batch-banner');
    var $bannerLbl = $('edit-batch-label');
    var $cancelEdit= $('cancel-edit-batch');

    var $filter    = $('filter-supplier');
    var $filterDd  = $('filter-dropdown');
    var $filterClr = $('filter-clear');
    var $perPage   = $('per-page');
    var $histBody  = $('history-tbody');
    var $histPager = $('history-pagination');
    var $hfDateFrom = $('hf-date-from');
    var $hfDateTo   = $('hf-date-to');
    var $supFunnel  = $('sd-supplier-funnel');  // phễu lọc trên cột Nhà cung cấp
    var $supPop     = $('sd-supplier-pop');     // popover chứa ô tìm NCC

    // print modal
    var $printModal = $('print-modal');
    var $printSheet = $('print-sheet');
    var $printClose = $('print-modal-close');
    var $printOv    = $('print-modal-overlay');
    var $printDo    = $('print-modal-do');
    var $printShare = $('print-modal-share');

    // attachments viewer modal
    var $attModal = $('att-modal');
    var $attOv    = $('att-overlay');
    var $attClose = $('att-close');
    var $attTitle = $('att-title');
    var $attGrid  = $('att-grid');

    // purchases modal
    var $purModal   = $('purchases-modal');
    var $purOv      = $('purchases-overlay');
    var $purClose   = $('purchases-close');
    var $purTitle   = $('purchases-title');
    var $purBody    = $('purchases-tbody');
    var $purPager   = $('purchases-pagination');
    var $purPerPage = $('purchases-per-page');

    // dropzone (staging)
    var dz = (window.InvoiceDropzone && $('invoice-upload'))
        ? window.InvoiceDropzone.create($('invoice-upload')) : null;

    /* ---------------- Helpers ---------------- */
    function postForm(action, payload) {
        var body = new URLSearchParams();
        Object.keys(payload || {}).forEach(function (k) { body.append(k, payload[k]); });
        return fetch(CFG.baseUrl + action, {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            credentials: 'same-origin',
            body: body.toString()
        }).then(function (r) { return r.json(); });
    }
    function debounce(fn, wait) {
        var t;
        return function () {
            var ctx = this, args = arguments;
            clearTimeout(t);
            t = setTimeout(function () { fn.apply(ctx, args); }, wait);
        };
    }
    function escapeHtml(s) {
        return String(s == null ? '' : s)
            .replace(/&/g, '&amp;').replace(/</g, '&lt;')
            .replace(/>/g, '&gt;').replace(/"/g, '&quot;').replace(/'/g, '&#39;');
    }
    function parseNum(s) { return Number(String(s == null ? '' : s).replace(/[^\d.-]/g, '')) || 0; }
    function fmtMoney(v) { return (Math.round(Number(v) || 0)).toLocaleString('en-US') + ' đ'; }
    function fmtMoneyPlain(v) { return (Math.round(Number(v) || 0)).toLocaleString('en-US'); }
    function pad2(n) { return String(n).padStart(2, '0'); }

    function nowLocalValue() {
        var d = new Date();
        return d.getFullYear() + '-' + pad2(d.getMonth() + 1) + '-' + pad2(d.getDate())
             + 'T' + pad2(d.getHours()) + ':' + pad2(d.getMinutes()) + ':' + pad2(d.getSeconds());
    }
    function pickerToMysql() {
        var m = /^(\d{4})-(\d{2})-(\d{2})T(\d{2}):(\d{2})(?::(\d{2}))?$/.exec(String($dt.value || ''));
        if (!m) return '';
        return m[1] + '-' + m[2] + '-' + m[3] + ' ' + m[4] + ':' + m[5] + ':' + (m[6] || '00');
    }
    function mysqlToLocal(s) {
        var m = /^(\d{4})-(\d{2})-(\d{2})[\sT](\d{2}):(\d{2}):(\d{2})$/.exec(String(s || ''));
        return m ? (m[1] + '-' + m[2] + '-' + m[3] + 'T' + m[4] + ':' + m[5] + ':' + m[6]) : null;
    }
    function datePartsFrom(mysql) {
        var m = /^(\d{4})-(\d{2})-(\d{2})/.exec(String(mysql || ''));
        if (m) return { dd: m[3], mm: m[2], yyyy: m[1] };
        var d = new Date();
        return { dd: pad2(d.getDate()), mm: pad2(d.getMonth() + 1), yyyy: String(d.getFullYear()) };
    }

    /* ---- Số tiền bằng chữ (tiếng Việt) ---- */
    function numberToVnWords(num) {
        num = Math.round(Number(num) || 0);
        if (num === 0) return 'Không đồng';
        var ones = ['không','một','hai','ba','bốn','năm','sáu','bảy','tám','chín'];
        function readTriple(n, full) {
            var tr = String(n).padStart(3, '0');
            var h = +tr[0], t = +tr[1], u = +tr[2];
            var parts = [];
            if (h !== 0 || full) parts.push(ones[h] + ' trăm');
            if (t === 0) {
                if (u !== 0) { if (h !== 0 || full) parts.push('linh'); parts.push(ones[u]); }
            } else if (t === 1) {
                parts.push('mười');
                if (u === 1) parts.push('một');
                else if (u === 5) parts.push('lăm');
                else if (u !== 0) parts.push(ones[u]);
            } else {
                parts.push(ones[t] + ' mươi');
                if (u === 1) parts.push('mốt');
                else if (u === 5) parts.push('lăm');
                else if (u !== 0) parts.push(ones[u]);
            }
            return parts.join(' ');
        }
        var groups = [], n = num;
        while (n > 0) { groups.unshift(n % 1000); n = Math.floor(n / 1000); }
        var scales = ['', 'nghìn', 'triệu', 'tỷ'];
        var out = [], len = groups.length;
        for (var i = 0; i < len; i++) {
            var g = groups[i];
            if (g === 0) continue;
            out.push(readTriple(g, i !== 0) + (scales[len - 1 - i] ? ' ' + scales[len - 1 - i] : ''));
        }
        var s = out.join(' ').replace(/\s+/g, ' ').trim();
        return s.charAt(0).toUpperCase() + s.slice(1) + ' đồng';
    }

    /* ---------------- Amount input ---------------- */
    function setupAmount() {
        $amount.addEventListener('blur', function () {
            var n = parseNum($amount.value);
            $amount.value = n > 0 ? Math.round(n).toLocaleString('en-US') : '';
        });
        $amount.addEventListener('focus', function () {
            var n = parseNum($amount.value);
            $amount.value = n > 0 ? String(Math.round(n)) : '';
        });
    }

    /* ---------------- Đính kèm ---------------- */
    var $savedBadge = $('iu-saved-badge');
    var $savedCount = $savedBadge ? $savedBadge.querySelector('.iu-saved-count') : null;
    var $savedList  = $('iu-saved-list');
    var IMG = ['jpg', 'jpeg', 'png', 'gif', 'webp'];
    function isImg(u) { return IMG.indexOf(String(u || '').split('.').pop().toLowerCase()) !== -1; }

    function renderSaved(list) {
        list = list || [];
        if (!$savedBadge || !$savedList) return;
        if (!list.length) {
            $savedBadge.style.display = 'none';
            $savedList.style.display = 'none';
            $savedList.innerHTML = '';
            if ($savedCount) $savedCount.textContent = '0';
            return;
        }
        if ($savedCount) $savedCount.textContent = String(list.length);
        $savedBadge.style.display = '';
        $savedList.style.display = 'none';
        $savedList.innerHTML = list.map(function (it) {
            var u = it.file_url;
            var inner = isImg(u)
                ? '<img src="' + u + '" data-view="' + u + '" alt="đính kèm">'
                : '<i class="fa-solid fa-file-pdf iu-file-ico" data-view="' + u + '"></i>';
            return '<div class="iu-thumb">' + inner + '</div>';
        }).join('');
    }
    function loadSaved(paymentId) {
        renderSaved([]);
        if (!paymentId) return;
        postForm('list_attachments', { payment_id: paymentId })
            .then(function (res) { if (res && res.ok) renderSaved(res.data || []); })
            .catch(function () {});
    }
    if ($savedBadge) $savedBadge.addEventListener('click', function () {
        if ($savedList) $savedList.style.display = ($savedList.style.display === 'none') ? 'flex' : 'none';
    });
    if ($savedList) $savedList.addEventListener('click', function (e) {
        var el = e.target.closest('[data-view]');
        if (!el) return;
        var u = el.getAttribute('data-view');
        if (window.InvoiceViewer) window.InvoiceViewer.open(u); else window.open(u, '_blank');
    });

    function uploadAttachments(paymentId) {
        if (!dz || !paymentId) return Promise.resolve();
        var files = dz.getFiles();
        if (!files.length) return Promise.resolve();
        var fd = new FormData();
        fd.append('payment_id', paymentId);
        files.forEach(function (f) { fd.append('files[]', f); });
        return fetch(CFG.baseUrl + 'upload_attachments', { method: 'POST', body: fd, credentials: 'same-origin' })
            .then(function (r) { return r.json(); })
            .then(function (res) {
                if (res && res.ok) {
                    dz.clear();
                    if (res.errors && res.errors.length) alert(res.errors.join('\n'));
                } else {
                    alert((res && res.message) || 'Tải ảnh thất bại.');
                }
            })
            .catch(function () { alert('Lỗi mạng khi tải ảnh.'); });
    }

    /* ---------------- Supplier search (entry form) ---------------- */
    var runSupSearch = debounce(function () {
        var kw = $search.value.trim();
        if (kw === '') { hideSupDd(); return; }
        postForm('search_suppliers', { keyword: kw }).then(function (res) { renderSupDd(res.data || []); });
    }, 220);

    function renderSupDd(items) {
        supItems = items; supActive = -1;
        $dropdown.innerHTML = items.length
            ? items.map(function (it, i) { return '<li data-id="' + it.id + '" data-idx="' + i + '">' + escapeHtml(it.supplier_name) + '</li>'; }).join('')
            : '<li class="empty">Không tìm thấy NCC</li>';
        $dropdown.classList.add('active');
    }
    function hideSupDd() { $dropdown.classList.remove('active'); $dropdown.innerHTML = ''; supActive = -1; }
    function pickSup(el) {
        var id = parseInt(el.getAttribute('data-id'), 10);
        var it = supItems.find(function (x) { return +x.id === id; });
        if (!it) return;
        selectedSupplier = { id: id, supplier_name: it.supplier_name, address: it.address || '' };
        $search.value = it.supplier_name;
        $search.classList.add('is-selected');
        hideSupDd();
        // Chọn xong NCC → focus luôn vào ô Giá trị để nhập tiếp.
        if ($amount) $amount.focus();
    }

    /* ---------------- Create / Update ---------------- */
    function validateForm() {
        if (!selectedSupplier) { alert('Vui lòng chọn nhà cung cấp.'); return null; }
        var amount = parseNum($amount.value);
        if (amount <= 0) { alert('Vui lòng nhập giá trị (số tiền) hợp lệ.'); return null; }
        return {
            supplier_id: selectedSupplier.id,
            amount: amount,
            note: ($note ? $note.value : '').trim(),
            created_at: pickerToMysql()
        };
    }

    // "Tạo phiếu chi" — chỉ mở modal xem trước, KHÔNG ghi vào database
    function previewVoucher() {
        var data = validateForm();
        if (!data) return;
        openVoucher({
            supplier_id: selectedSupplier.id,
            supplierName: selectedSupplier.supplier_name,
            address: selectedSupplier.address || '',
            amount: data.amount,
            parts: datePartsFrom(data.created_at)
        });
    }

    // "Ghi" — ghi phiếu chi (kèm diễn giải + ảnh) vào database
    function saveVoucher() {
        var data = validateForm();
        if (!data) return;
        $btnGhi.disabled = true;
        postForm('record', data).then(function (res) {
            if (!res || !res.success) { alert((res && res.message) || 'Ghi phiếu chi thất bại.'); return; }
            return uploadAttachments(res.id).then(function () {
                resetForm();
                reloadHistory(1);
            });
        }).catch(function () { alert('Lỗi mạng khi ghi phiếu chi.'); })
          .finally(function () { $btnGhi.disabled = false; });
    }

    function updateVoucher() {
        if (!editingId) return;
        var data = validateForm();
        if (!data) return;
        data.id = editingId;
        $btnUpdate.disabled = true;
        postForm('edit', data).then(function (res) {
            if (!res || !res.success) { alert((res && res.message) || 'Cập nhật thất bại.'); return; }
            return uploadAttachments(editingId).then(function () {
                exitEdit();
                reloadHistory(histPage);
            });
        }).catch(function () { alert('Lỗi mạng khi cập nhật.'); })
          .finally(function () { $btnUpdate.disabled = false; });
    }

    function resetForm() {
        selectedSupplier = null;
        $search.value = '';
        $search.classList.remove('is-selected');
        $amount.value = '';
        if ($note) $note.value = '';
        $dt.value = nowLocalValue();
        if (dz) dz.clear();
        renderSaved([]);
    }
    function enterEdit(row) {
        editingId = row.id;
        selectedSupplier = { id: row.supplier_id, supplier_name: row.supplier_name, address: '' };
        // lấy address mới nhất
        postForm('get_supplier', { supplier_id: row.supplier_id }).then(function (res) {
            if (res && res.success && selectedSupplier) selectedSupplier.address = res.data.address || '';
        });
        $search.value = row.supplier_name;
        $search.classList.add('is-selected');
        $amount.value = Math.round(row.amount).toLocaleString('en-US');
        if ($note) $note.value = row.note || '';
        var local = mysqlToLocal(row.created_at);
        if (local) $dt.value = local;
        if (dz) dz.clear();
        loadSaved(row.id);
        $btnCreate.style.display = 'none';
        $btnGhi.style.display = 'none';
        $btnUpdate.style.display = '';
        $banner.style.display = '';
        $bannerLbl.textContent = '#' + row.id + ' — ' + row.supplier_name;
        window.scrollTo({ top: 0, behavior: 'smooth' });
    }
    function exitEdit() {
        editingId = null;
        $btnCreate.style.display = '';
        $btnGhi.style.display = '';
        $btnUpdate.style.display = 'none';
        $banner.style.display = 'none';
        resetForm();
    }

    /* ---------------- History render ---------------- */
    function updateSupplierFunnel() {
        if ($supFunnel) $supFunnel.classList.toggle('active', histFilterId > 0);
    }

    function reloadHistory(page) {
        histPage = page || 1;
        histPerPage = parseInt($perPage.value, 10) || 5;
        updateSupplierFunnel();
        postForm('get_history', { page: histPage, per_page: histPerPage, supplier_id: histFilterId, date_from: histDateFrom, date_to: histDateTo })
            .then(function (res) {
                if (!res || !res.success) return;
                histTotal = res.total | 0;
                renderHistory(res.rows || []);
            });
    }
    function renderHistory(rows) {
        if (!rows.length) {
            $histBody.innerHTML = '<tr><td colspan="5" class="sd-empty">Chưa có phiếu chi nào.</td></tr>';
        } else {
            $histBody.innerHTML = rows.map(function (r) {
                var att = r.attachments > 0
                    ? '<span class="sd-att-badge" data-att="1" title="Xem ảnh thanh toán"><i class="fa-solid fa-paperclip"></i>' + r.attachments + '</span>'
                    : '<span class="sd-att-none">—</span>';
                var note = r.note
                    ? '<div class="sd-row-note">' + escapeHtml(r.note) + '</div>'
                    : '';
                return '<tr data-id="' + r.id + '">' +
                    '<td>' + escapeHtml(r.date_display) + '</td>' +
                    '<td><a class="sd-supplier-link" data-sid="' + r.supplier_id + '" data-name="' + escapeHtml(r.supplier_name) + '">' +
                        escapeHtml(r.supplier_name || '(không rõ)') + '</a>' + note + '</td>' +
                    '<td class="num sd-amount-cell">' + fmtMoney(r.amount) + '</td>' +
                    '<td class="center">' + att + '</td>' +
                    '<td class="center"><div class="sd-row-actions">' +
                        '<button class="sd-act-print" title="In phiếu chi"><i class="fa-solid fa-print"></i></button>' +
                        '<button class="sd-act-edit" title="Sửa"><i class="fa-solid fa-pen"></i></button>' +
                        '<button class="sd-act-del" title="Xóa"><i class="fa-solid fa-trash"></i></button>' +
                    '</div></td>' +
                '</tr>';
            }).join('');
        }
        renderPager($histPager, histPage, histTotal, histPerPage, function (p) { reloadHistory(p); });
    }

    function renderPager($el, page, total, perPage, onGo) {
        var pages = Math.max(1, Math.ceil(total / perPage));
        if (pages <= 1) { $el.innerHTML = ''; return; }
        var html = '';
        html += '<button data-go="' + (page - 1) + '"' + (page <= 1 ? ' disabled' : '') + '>&laquo;</button>';
        var start = Math.max(1, page - 2), end = Math.min(pages, start + 4);
        start = Math.max(1, end - 4);
        if (start > 1) html += '<button data-go="1">1</button>' + (start > 2 ? '<span class="sd-page-info">…</span>' : '');
        for (var i = start; i <= end; i++) {
            html += '<button data-go="' + i + '"' + (i === page ? ' class="active"' : '') + '>' + i + '</button>';
        }
        if (end < pages) html += (end < pages - 1 ? '<span class="sd-page-info">…</span>' : '') + '<button data-go="' + pages + '">' + pages + '</button>';
        html += '<button data-go="' + (page + 1) + '"' + (page >= pages ? ' disabled' : '') + '>&raquo;</button>';
        html += '<span class="sd-page-info">' + total + ' phiếu</span>';
        $el.innerHTML = html;
        $el.onclick = function (e) {
            var b = e.target.closest('button[data-go]');
            if (!b || b.disabled) return;
            var p = parseInt(b.getAttribute('data-go'), 10);
            if (p >= 1 && p <= pages) onGo(p);
        };
    }

    function deletePayment(id) {
        if (!confirm('Xóa phiếu chi này? Ảnh đính kèm cũng sẽ bị xóa.')) return;
        postForm('delete', { id: id }).then(function (res) {
            if (res && res.success) {
                if (editingId === id) exitEdit();
                reloadHistory(histPage);
            }
        });
    }

    /* ---------------- Filter (history by supplier) ---------------- */
    var runFilterSearch = debounce(function () {
        var kw = $filter.value.trim();
        if (kw === '') { $filterDd.classList.remove('active'); $filterDd.innerHTML = ''; return; }
        postForm('search_suppliers', { keyword: kw }).then(function (res) {
            filtItems = res.data || [];
            $filterDd.innerHTML = filtItems.length
                ? filtItems.map(function (it) { return '<li data-id="' + it.id + '">' + escapeHtml(it.supplier_name) + '</li>'; }).join('')
                : '<li class="empty">Không tìm thấy NCC</li>';
            $filterDd.classList.add('active');
        });
    }, 220);

    /* ---------------- Print modal: PHIẾU CHI ---------------- */
    function editableField(key, multiline) {
        var v = String(PRINT_SETTINGS[key] == null ? '' : PRINT_SETTINGS[key]).trim();
        return '<span class="pr-editable' + (v ? '' : ' is-empty') + '" data-setting="' + key + '"' +
                   (multiline ? ' data-multiline="1"' : '') + '>' +
                   '<span class="pr-ed-text">' + escapeHtml(v) + '</span>' +
                   '<i class="fa-solid fa-pen pr-edit-pen" title="Sửa (lưu dùng dài lâu)"></i>' +
               '</span>';
    }
    function editableAddr(v) {
        v = String(v == null ? '' : v).trim();
        return '<span class="pr-editable' + (v ? '' : ' is-empty') + '" data-supplier-addr="1">' +
                   '<span class="pr-ed-text">' + escapeHtml(v) + '</span>' +
                   '<i class="fa-solid fa-pen pr-edit-pen" title="Sửa địa chỉ NCC"></i>' +
               '</span>';
    }

    function buildVoucherSheet(v) {
        var words = numberToVnWords(v.amount);
        return '' +
            '<div class="pc-company">' +
                '<div class="pc-brand">' +
                    '<div class="pc-logo-badge"><img class="pc-logo" src="public/images/logo/logo_vat_png.png" alt="logo"></div>' +
                    '<div class="pc-co-textwrap">' +
                        '<div class="pc-co-name">' + editableField('company_name') + '</div>' +
                        '<div class="pc-co-enname">' + editableField('company_enname') + '</div>' +
                    '</div>' +
                '</div>' +
                '<div class="pc-contact">' +
                    '<div class="pc-co-addr">' + editableField('company_address', true) + '</div>' +
                    '<div class="pc-co-line"><i class="fa-solid fa-phone pc-co-ico"></i>' + editableField('company_phone') + '</div>' +
                    '<div class="pc-co-line"><i class="fa-solid fa-envelope pc-co-ico"></i>' + editableField('company_email') + '</div>' +
                '</div>' +
            '</div>' +
            '<div class="pc-rope"></div>' +
            '<h2 class="pr-title pc-title-big">PHIẾU CHI</h2>' +
            '<div class="pr-date">Ngày ' + v.parts.dd + ' tháng ' + v.parts.mm + ' năm ' + v.parts.yyyy + '</div>' +
            '<div class="pc-rows">' +
                '<div class="row"><span class="lab">Họ và tên người nhận tiền:</span><span class="pc-receiver-name">' + escapeHtml(v.supplierName) + '</span></div>' +
                '<div class="row"><span class="lab">Địa chỉ:</span>' + editableAddr(v.address) + '</div>' +
                '<div class="row"><span class="lab">Lý do chi:</span>chi tiền cho ' + escapeHtml(v.supplierName) + '</div>' +
                '<div class="row"><span class="lab">Số tiền:</span><b>' + fmtMoney(v.amount) + '</b></div>' +
                '<div class="row pc-words"><span class="lab">Số tiền bằng chữ:</span>' + escapeHtml(words) + '</div>' +
            '</div>' +
            '<div class="pc-date-right">Ngày ' + v.parts.dd + ' tháng ' + v.parts.mm + ' năm ' + v.parts.yyyy + '</div>' +
            '<div class="pr-signatures" id="pr-signatures"></div>' +
            '<div class="pc-received">Đã nhận đủ số tiền (Viết bằng chữ): ' + escapeHtml(words) + '.</div>';
    }

    /* ---- Vùng ký: render động + kéo-thả sắp xếp + cài đặt chức danh ---- */
    function saveSigns() { postForm('save_sign_setting', { key: 'signs', value: JSON.stringify(signs) }); }
    function saveRoles() { postForm('save_sign_setting', { key: 'sign_roles', value: JSON.stringify(signRoles) }); }

    var signDragFrom = null;
    function bindSignDrag(div) {
        div.addEventListener('dragstart', function () {
            signDragFrom = parseInt(div.getAttribute('data-idx'), 10);
            div.classList.add('dragging');
        });
        div.addEventListener('dragend', function () {
            div.classList.remove('dragging');
            var box = div.parentElement;
            if (box) Array.prototype.forEach.call(box.querySelectorAll('.signer'), function (s) { s.classList.remove('drag-over'); });
        });
        div.addEventListener('dragover', function (e) { e.preventDefault(); div.classList.add('drag-over'); });
        div.addEventListener('dragleave', function () { div.classList.remove('drag-over'); });
        div.addEventListener('drop', function (e) {
            e.preventDefault();
            div.classList.remove('drag-over');
            var to = parseInt(div.getAttribute('data-idx'), 10);
            if (signDragFrom === null || signDragFrom === to || isNaN(to)) return;
            var tmp = signs[signDragFrom]; signs[signDragFrom] = signs[to]; signs[to] = tmp;
            signDragFrom = null;
            renderSignatures(); saveSigns();
        });
    }

    // Cụm chữ ký phía công ty (động, kéo-thả) + "Người nhận tiền" cố định, luôn để trống tên.
    function renderSignatures() {
        var box = $printSheet ? $printSheet.querySelector('#pr-signatures') : null;
        if (!box) return;
        var html = signs.map(function (sg, idx) {
            var sub = sg.role === 'Giám đốc' ? '(Ký, họ tên, đóng dấu)' : '(Ký, họ tên)';
            return '<div class="signer" draggable="true" data-idx="' + idx + '">' +
                '<div class="role">' + escapeHtml(sg.role) + '</div>' +
                '<div class="sub">' + sub + '</div>' +
                '<div class="name"><span class="pr-editable' + (sg.name ? '' : ' is-empty') + '" data-sign-idx="' + idx + '">' +
                    '<span class="pr-ed-text">' + escapeHtml(sg.name) + '</span>' +
                    '<i class="fa-solid fa-pen pr-edit-pen" title="Sửa tên"></i>' +
                '</span></div>' +
            '</div>';
        }).join('');
        html += '<div class="signer"><div class="role">Người nhận tiền</div><div class="sub">(Ký, họ tên)</div><div class="name">&nbsp;</div></div>';
        box.innerHTML = html;
        Array.prototype.forEach.call(box.querySelectorAll('.signer[draggable="true"]'), bindSignDrag);
    }

    function renderSignRoleList() {
        var ul = $('sign-setting-list'); if (!ul) return;
        ul.innerHTML = signRoles.map(function (role, i) {
            var checked = signs.some(function (s) { return s.role === role; });
            return '<li class="sd-signcfg-item" data-i="' + i + '">' +
                '<input type="checkbox" class="sd-signcfg-cb"' + (checked ? ' checked' : '') + '>' +
                '<span class="sd-signcfg-name">' + escapeHtml(role) + '</span>' +
                '<div class="sd-signcfg-act">' +
                    '<button type="button" class="sd-signcfg-btn" data-act="edit" title="Đổi tên"><i class="fa-solid fa-pen"></i></button>' +
                    '<button type="button" class="sd-signcfg-btn danger" data-act="del" title="Xóa"><i class="fa-solid fa-trash"></i></button>' +
                '</div>' +
            '</li>';
        }).join('');
    }

    function addSignRole() {
        var inp = $('sign-setting-newrole'); if (!inp) return;
        var v = inp.value.trim();
        if (!v) return;
        if (signRoles.indexOf(v) === -1) { signRoles.push(v); saveRoles(); }
        inp.value = ''; renderSignRoleList();
    }

    function openVoucher(v) {
        currentVoucher = v;
        if (!$printSheet || !$printModal) return;
        $printSheet.innerHTML = buildVoucherSheet(v);
        renderSignatures();
        $printModal.style.display = 'flex';
    }
    function closeVoucher() { if ($printModal) $printModal.style.display = 'none'; }

    // mở phiếu chi từ 1 dòng lịch sử (cần fetch address)
    function openVoucherFromRow(row) {
        postForm('get_supplier', { supplier_id: row.supplier_id }).then(function (res) {
            var addr = (res && res.success) ? (res.data.address || '') : '';
            openVoucher({
                supplier_id: row.supplier_id,
                supplierName: row.supplier_name,
                address: addr,
                amount: row.amount,
                parts: datePartsFrom(row.created_at)
            });
        });
    }

    /* ---- sửa tại chỗ các trường cố định ---- */
    function enterFieldEdit($wrap) {
        if (!$wrap || $wrap.classList.contains('editing')) return;
        var $txt = $wrap.querySelector('.pr-ed-text');
        $wrap.classList.add('editing');
        $wrap.classList.remove('is-empty');
        $txt.dataset.orig = $txt.textContent;
        $txt.setAttribute('contenteditable', 'true');
        $txt.focus();
        var range = document.createRange();
        range.selectNodeContents($txt);
        var sel = window.getSelection();
        sel.removeAllRanges();
        sel.addRange(range);
    }
    function commitFieldEdit($wrap) {
        if (!$wrap || !$wrap.classList.contains('editing')) return;
        var $txt = $wrap.querySelector('.pr-ed-text');
        $wrap.classList.remove('editing');
        $txt.removeAttribute('contenteditable');
        // Trường nhiều dòng (Alt+Enter chèn \n) -> chỉ gom khoảng trắng/tab trong TỪNG dòng,
        // giữ nguyên \n (nếu gom hết \s+ như trường 1 dòng thì xuống dòng sẽ mất khi commit).
        var raw = $txt.textContent || '';
        var val = $wrap.hasAttribute('data-multiline')
            ? raw.split('\n').map(function (line) { return line.replace(/[ \t]+/g, ' ').trim(); }).join('\n').replace(/\n{3,}/g, '\n\n').trim()
            : raw.replace(/\s+/g, ' ').trim();
        $txt.textContent = val;
        if (!val) $wrap.classList.add('is-empty');
        if (val === ($txt.dataset.orig || '')) return;

        if ($wrap.hasAttribute('data-supplier-addr')) {
            if (currentVoucher) {
                currentVoucher.address = val;
                if (selectedSupplier && selectedSupplier.id === currentVoucher.supplier_id) selectedSupplier.address = val;
                postForm('save_supplier_address', { supplier_id: currentVoucher.supplier_id, address: val })
                    .catch(function () {});
            }
            return;
        }
        if ($wrap.hasAttribute('data-sign-idx')) {
            var idx = parseInt($wrap.getAttribute('data-sign-idx'), 10);
            if (signs[idx]) { signs[idx].name = val; saveSigns(); }
            return;
        }
        var key = $wrap.getAttribute('data-setting');
        if (key) {
            PRINT_SETTINGS[key] = val;
            postForm('save_print_setting', { key: key, value: val })
                .then(function (res) { if (!res || !res.success) console.warn('Lưu thất bại:', res && res.message); })
                .catch(function () {});
        }
    }
    function setupPrintModal() {
        if ($printClose) $printClose.addEventListener('click', closeVoucher);
        if ($printOv)    $printOv.addEventListener('click', closeVoucher);
        if ($printDo)    $printDo.addEventListener('click', function () { window.print(); });
        if ($printShare) $printShare.addEventListener('click', shareSheet);
        if (!$printSheet) return;
        $printSheet.addEventListener('click', function (e) {
            var $wrap = e.target.closest('.pr-editable');
            if ($wrap && !$wrap.classList.contains('editing')) { e.preventDefault(); enterFieldEdit($wrap); }
        });
        $printSheet.addEventListener('blur', function (e) {
            if (e.target.classList && e.target.classList.contains('pr-ed-text')) commitFieldEdit(e.target.closest('.pr-editable'));
        }, true);
        $printSheet.addEventListener('keydown', function (e) {
            if (!e.target.classList || !e.target.classList.contains('pr-ed-text')) return;
            var $mlWrap = e.target.closest('.pr-editable[data-multiline="1"]');
            if (e.key === 'Enter' && e.altKey && $mlWrap) {
                // Alt+Enter trên trường nhiều dòng (địa chỉ) -> chèn xuống dòng tại vị trí con trỏ.
                e.preventDefault();
                var sel = window.getSelection();
                if (sel && sel.rangeCount) {
                    var range = sel.getRangeAt(0);
                    range.deleteContents();
                    var brk = document.createTextNode('\n');
                    range.insertNode(brk);
                    range.setStartAfter(brk);
                    range.setEndAfter(brk);
                    sel.removeAllRanges();
                    sel.addRange(range);
                }
            }
            else if (e.key === 'Enter') { e.preventDefault(); e.target.blur(); }
            else if (e.key === 'Escape') {
                var $wrap = e.target.closest('.pr-editable');
                e.target.textContent = e.target.dataset.orig || '';
                $wrap.classList.remove('editing');
                e.target.removeAttribute('contenteditable');
                if (!e.target.textContent) $wrap.classList.add('is-empty');
            }
        });
        document.addEventListener('keydown', function (e) {
            if (e.key === 'Escape' && $printModal && $printModal.style.display !== 'none') closeVoucher();
        });

        /* ---- Modal: Cài đặt vùng ký ---- */
        var $signModal    = $('sign-setting-modal');
        var $signModalCls = $('sign-setting-close');
        var $signModalOv  = $('sign-setting-overlay');
        var $signCfgBtn   = $('print-modal-signcfg');
        var $signList     = $('sign-setting-list');
        var $signAddBtn   = $('sign-setting-addbtn');
        var $signNewInp   = $('sign-setting-newrole');

        function openSignModal() { renderSignRoleList(); if ($signModal) $signModal.style.display = 'flex'; }
        function closeSignModal() { if ($signModal) $signModal.style.display = 'none'; }
        if ($signCfgBtn)   $signCfgBtn.addEventListener('click', openSignModal);
        if ($signModalCls) $signModalCls.addEventListener('click', closeSignModal);
        if ($signModalOv)  $signModalOv.addEventListener('click', closeSignModal);
        document.addEventListener('keydown', function (e) {
            if (e.key === 'Escape' && $signModal && $signModal.style.display !== 'none') closeSignModal();
        });

        if ($signList) {
            $signList.addEventListener('change', function (e) {
                var cb = e.target.closest('.sd-signcfg-cb'); if (!cb) return;
                var li = cb.closest('li'); var i = parseInt(li.getAttribute('data-i'), 10);
                var role = signRoles[i];
                if (cb.checked) { if (!signs.some(function (s) { return s.role === role; })) signs.push({ role: role, name: '' }); }
                else { signs = signs.filter(function (s) { return s.role !== role; }); }
                renderSignatures(); saveSigns();
            });
            $signList.addEventListener('click', function (e) {
                var li = e.target.closest('li'); if (!li) return;
                var i = parseInt(li.getAttribute('data-i'), 10);
                var role = signRoles[i];
                var nameEl = li.querySelector('.sd-signcfg-name');
                if (e.target.closest('[data-act="edit"]')) {
                    nameEl.setAttribute('contenteditable', 'true'); nameEl.focus();
                    var r = document.createRange(); r.selectNodeContents(nameEl);
                    var sel = window.getSelection(); sel.removeAllRanges(); sel.addRange(r);
                } else if (e.target.closest('[data-act="del"]')) {
                    if (!confirm('Xóa chức danh "' + role + '"?')) return;
                    signRoles = signRoles.filter(function (r) { return r !== role; });
                    signs = signs.filter(function (s) { return s.role !== role; });
                    saveRoles(); saveSigns(); renderSignRoleList(); renderSignatures();
                }
            });
            $signList.addEventListener('blur', function (e) {
                var nameEl = e.target.closest('.sd-signcfg-name');
                if (!nameEl || nameEl.getAttribute('contenteditable') !== 'true') return;
                nameEl.removeAttribute('contenteditable');
                var li = nameEl.closest('li'); var i = parseInt(li.getAttribute('data-i'), 10);
                var role = signRoles[i];
                var nv = nameEl.textContent.trim();
                if (nv && nv !== role) {
                    signRoles[i] = nv;
                    signs.forEach(function (s) { if (s.role === role) s.role = nv; });
                    saveRoles(); saveSigns(); renderSignRoleList(); renderSignatures();
                } else {
                    nameEl.textContent = role;
                }
            }, true);
            $signList.addEventListener('keydown', function (e) {
                var nameEl = e.target.closest('.sd-signcfg-name');
                if (nameEl && nameEl.getAttribute('contenteditable') === 'true' && e.key === 'Enter') { e.preventDefault(); nameEl.blur(); }
            });
        }
        if ($signAddBtn) $signAddBtn.addEventListener('click', addSignRole);
        if ($signNewInp) $signNewInp.addEventListener('keydown', function (e) { if (e.key === 'Enter') { e.preventDefault(); addSignRole(); } });
    }

    async function shareSheet() {
        if (typeof window.html2canvas !== 'function') { alert('Không nạp được html2canvas.'); return; }
        if (!navigator.clipboard || typeof window.ClipboardItem !== 'function') { alert('Trình duyệt không hỗ trợ copy ảnh.'); return; }
        var orig = $printShare.textContent;
        $printShare.textContent = 'Đang xử lý...';
        $printShare.disabled = true;
        try {
            var canvas = await window.html2canvas($printSheet, { backgroundColor: '#ffffff', scale: 2, useCORS: true, logging: false });
            var blob = await new Promise(function (res, rej) { canvas.toBlob(function (b) { b ? res(b) : rej(new Error('toBlob null')); }, 'image/png'); });
            await navigator.clipboard.write([ new ClipboardItem({ 'image/png': blob }) ]);
            alert('Đã copy ảnh phiếu chi vào clipboard.\nMở Zalo/Messenger... và Ctrl+V để dán.');
        } catch (err) {
            alert('Không thể chia sẻ: ' + (err && err.message ? err.message : err));
        } finally {
            $printShare.textContent = orig;
            $printShare.disabled = false;
        }
    }

    /* ---------------- Attachments viewer modal ---------------- */
    function openAtt(paymentId) {
        $attGrid.innerHTML = '<div class="sd-att-empty">Đang tải…</div>';
        $attModal.style.display = 'flex';
        postForm('list_attachments', { payment_id: paymentId }).then(function (res) {
            var list = (res && res.ok) ? (res.data || []) : [];
            if (!list.length) { $attGrid.innerHTML = '<div class="sd-att-empty">Không có ảnh đính kèm.</div>'; return; }
            $attGrid.innerHTML = list.map(function (a) {
                var u = a.file_url;
                var inner = isImg(u)
                    ? '<img src="' + u + '" alt="ảnh thanh toán">'
                    : '<div class="sd-att-pdf"><i class="fa-solid fa-file-pdf"></i></div>';
                return '<div class="sd-att-item" data-view="' + u + '">' + inner + '</div>';
            }).join('');
        }).catch(function () { $attGrid.innerHTML = '<div class="sd-att-empty">Lỗi tải ảnh.</div>'; });
    }
    function closeAtt() { $attModal.style.display = 'none'; }

    /* ---------------- Purchases modal ---------------- */
    function openPurchases(sid, name) {
        purSupplierId = sid; purSupplierName = name; purPage = 1;
        purPerPage = parseInt($purPerPage.value, 10) || 5;
        $purTitle.textContent = 'Lịch sử mua hàng — ' + name;
        $purModal.style.display = 'flex';
        loadPurchases(1);
    }
    function closePurchases() { $purModal.style.display = 'none'; }
    function loadPurchases(page) {
        purPage = page || 1;
        purPerPage = parseInt($purPerPage.value, 10) || 5;
        postForm('get_supplier_purchases', { supplier_id: purSupplierId, page: purPage, per_page: purPerPage })
            .then(function (res) {
                if (!res || !res.success) return;
                purTotal = res.total | 0;
                renderPurchases(res.rows || []);
            });
    }
    function renderPurchases(rows) {
        if (!rows.length) {
            $purBody.innerHTML = '<tr><td colspan="4" class="sd-empty">Chưa có lần mua nào.</td></tr>';
        } else {
            $purBody.innerHTML = rows.map(function (r) {
                return '<tr>' +
                    '<td>' + escapeHtml(r.date_display) + '</td>' +
                    '<td>' + escapeHtml(r.type_label) + '</td>' +
                    '<td class="num">' + fmtMoneyPlain(r.total) + '</td>' +
                    '<td class="num">' + fmtMoneyPlain(r.purchase_cost) + '</td>' +
                '</tr>';
            }).join('');
        }
        renderPager($purPager, purPage, purTotal, purPerPage, function (p) { loadPurchases(p); });
    }

    /* ---------------- Wire-up ---------------- */
    function init() {
        $dt.value = nowLocalValue();
        setupAmount();
        setupPrintModal();
        renderHistory(INITIAL.history || []);

        // supplier search
        $search.addEventListener('input', runSupSearch);
        $search.addEventListener('focus', runSupSearch);
        $search.addEventListener('keydown', function (e) {
            if (!$dropdown.classList.contains('active')) return;
            var lis = $dropdown.querySelectorAll('li:not(.empty)');
            if (e.key === 'ArrowDown') { e.preventDefault(); supActive = Math.min(supActive + 1, lis.length - 1); upd(lis); }
            else if (e.key === 'ArrowUp') { e.preventDefault(); supActive = Math.max(supActive - 1, 0); upd(lis); }
            else if (e.key === 'Enter' || e.key === 'Tab') {
                // Enter hoặc Tab đều chọn item đang active (hoặc item đầu nếu chưa di chuyển).
                var idx = supActive >= 0 ? supActive : 0;
                if (lis[idx]) { e.preventDefault(); pickSup(lis[idx]); }
            }
            else if (e.key === 'Escape') { hideSupDd(); }
        });
        function upd(lis) { lis.forEach(function (li, i) { li.classList.toggle('active', i === supActive); }); }
        $dropdown.addEventListener('mousedown', function (e) {
            var li = e.target.closest('li');
            if (li && !li.classList.contains('empty')) { e.preventDefault(); pickSup(li); }
        });
        document.addEventListener('click', function (e) {
            if (!$search.contains(e.target) && !$dropdown.contains(e.target)) hideSupDd();
            if (!$filter.contains(e.target) && !$filterDd.contains(e.target)) { $filterDd.classList.remove('active'); }
            if ($supPop && $supFunnel && !$supPop.contains(e.target) && !$supFunnel.contains(e.target)) {
                $supPop.classList.remove('open');
            }
        });

        // buttons
        $btnCreate.addEventListener('click', previewVoucher);
        $btnGhi.addEventListener('click', saveVoucher);
        $btnUpdate.addEventListener('click', updateVoucher);
        $cancelEdit.addEventListener('click', function (e) { e.preventDefault(); exitEdit(); });

        // history actions
        $histBody.addEventListener('click', function (e) {
            var $tr = e.target.closest('tr[data-id]');
            if (!$tr) return;
            var id = parseInt($tr.getAttribute('data-id'), 10);
            var link = e.target.closest('.sd-supplier-link');
            if (link) { openPurchases(parseInt(link.getAttribute('data-sid'), 10), link.getAttribute('data-name')); return; }
            if (e.target.closest('.sd-att-badge')) { openAtt(id); return; }
            if (e.target.closest('.sd-act-del')) { deletePayment(id); return; }
            var isEdit = !!e.target.closest('.sd-act-edit');
            var isPrint = !!e.target.closest('.sd-act-print');
            if (!isEdit && !isPrint) return;   // click ngoài nút → bỏ qua (tránh fetch thừa)
            postForm('get_payment', { id: id }).then(function (res) {
                if (!res || !res.success) return;
                if (isEdit) enterEdit(res.data);
                else if (isPrint) openVoucherFromRow(res.data);
            });
        });

        // per-page + filter
        $perPage.addEventListener('change', function () { reloadHistory(1); });
        $filter.addEventListener('input', function () { runFilterSearch(); $filterClr.style.display = $filter.value ? '' : 'none'; });
        $filter.addEventListener('focus', runFilterSearch);
        $filterDd.addEventListener('mousedown', function (e) {
            var li = e.target.closest('li');
            if (!li || li.classList.contains('empty')) return;
            e.preventDefault();
            histFilterId = parseInt(li.getAttribute('data-id'), 10) || 0;
            $filter.value = li.textContent;
            $filterClr.style.display = '';
            $filterDd.classList.remove('active');
            if ($supPop) $supPop.classList.remove('open');
            reloadHistory(1);
        });
        $filterClr.addEventListener('click', function () {
            histFilterId = 0; $filter.value = ''; $filterClr.style.display = 'none'; reloadHistory(1);
        });

        // Khoảng ngày (Từ ngày … đến ngày …) — lọc server-side qua get_history.
        if ($hfDateFrom) $hfDateFrom.addEventListener('change', function () {
            histDateFrom = $hfDateFrom.value; reloadHistory(1);
        });
        if ($hfDateTo) $hfDateTo.addEventListener('change', function () {
            histDateTo = $hfDateTo.value; reloadHistory(1);
        });

        // Phễu lọc NCC: mở/đóng popover chứa ô tìm nhà cung cấp.
        if ($supFunnel && $supPop) {
            $supFunnel.addEventListener('click', function (e) {
                e.stopPropagation();
                var open = $supPop.classList.toggle('open');
                if (open && $filter) $filter.focus();
            });
            $supPop.addEventListener('click', function (e) { e.stopPropagation(); });
        }

        // purchases modal
        if ($purClose) $purClose.addEventListener('click', closePurchases);
        if ($purOv)    $purOv.addEventListener('click', closePurchases);
        if ($purPerPage) $purPerPage.addEventListener('change', function () { loadPurchases(1); });

        // attachments viewer modal
        if ($attClose) $attClose.addEventListener('click', closeAtt);
        if ($attOv)    $attOv.addEventListener('click', closeAtt);
        if ($attGrid)  $attGrid.addEventListener('click', function (e) {
            var it = e.target.closest('.sd-att-item[data-view]');
            if (!it) return;
            var u = it.getAttribute('data-view');
            if (window.InvoiceViewer) window.InvoiceViewer.open(u, isImg(u)); else window.open(u, '_blank');
        });

        document.addEventListener('keydown', function (e) {
            if (e.key !== 'Escape') return;
            if ($purModal && $purModal.style.display !== 'none') closePurchases();
            if ($attModal && $attModal.style.display !== 'none') closeAtt();
        });
    }

    if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', init);
    else init();
})();
