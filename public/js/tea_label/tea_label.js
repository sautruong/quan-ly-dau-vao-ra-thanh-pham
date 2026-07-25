/* =====================================================================
 *  Tạo tem nguyên liệu trà (tea_label)
 * ===================================================================== */
(function () {
    'use strict';

    var CFG = window.TL_CONFIG || { baseUrl: '?mod=tea_label&controllers=tea_label&action=', texts: {} };

    /* ---------- DOM ---------- */
    var $ = function (id) { return document.getElementById(id); };
    var $material = $('tl-material'), $materialDrop = $('tl-material-dropdown');
    var $supplier = $('tl-supplier'), $supplierDrop = $('tl-supplier-dropdown'), $supplierAddr = $('tl-supplier-address');
    var $teaTypes = $('tl-tea-types');
    var $qty = $('tl-qty'), $spec = $('tl-spec');
    var $btnReset = $('tl-btn-reset'), $btnMake = $('tl-btn-make'), $btnPrint = $('tl-btn-print');
    var $grid = $('tl-grid'), $gridEmpty = $('tl-grid-empty');
    var $tpl = $('tl-tpl-card');
    var $printModal = $('tl-print-modal'), $printPreview = $('tl-print-preview');

    /* ---------- State ---------- */
    var selectedSupplier = null; // {id, supplier_name, address}
    var selectedTea = 'Trà đen';
    var materialSeq = 0; // tăng mỗi lần bấm "Tạo tem" — dùng nhận diện ranh giới nguyên liệu khi in
    var fixedTexts = {
        usage: (CFG.texts && CFG.texts.usage) || 'Dùng pha chế trà sữa hoặc trà để uống',
        shelf_life: (CFG.texts && CFG.texts.shelf_life) || '2 năm kể từ ngày sản xuất',
        storage: (CFG.texts && CFG.texts.storage) || 'Nơi khô ráo, thoáng mát'
    };

    /* ---------- Utils ---------- */
    function post(action, data) {
        var body = new URLSearchParams();
        for (var k in data) body.append(k, data[k]);
        return fetch(CFG.baseUrl + action, {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8' },
            body: body.toString()
        }).then(function (r) { return r.json(); });
    }
    function debounce(fn, ms) { var t; return function () { var a = arguments, c = this; clearTimeout(t); t = setTimeout(function () { fn.apply(c, a); }, ms); }; }
    function esc(s) {
        return String(s == null ? '' : s).replace(/[&<>"]/g, function (c) {
            return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;' }[c];
        });
    }
    function updateGridEmpty() {
        $gridEmpty.style.display = $grid.querySelectorAll('.tl-card') .length ? 'none' : 'block';
    }

    /* ====================================================================
     *  GỢI Ý TÊN NGUYÊN LIỆU (free text, không bắt buộc chọn)
     * ==================================================================== */
    var mItems = [], mActive = -1;
    var doMaterialSearch = debounce(function () {
        var kw = $material.value.trim();
        if (kw === '') { closeMaterialDrop(); return; }
        post('search_materials', { keyword: kw }).then(function (res) { mItems = (res && res.data) || []; renderMaterialDrop(); });
    }, 180);
    function renderMaterialDrop() {
        mActive = -1;
        if (!mItems.length) { $materialDrop.classList.remove('open'); $materialDrop.innerHTML = ''; return; }
        $materialDrop.innerHTML = mItems.map(function (it, i) {
            return '<li data-idx="' + i + '">' + esc(it.display_name) + '</li>';
        }).join('');
        $materialDrop.classList.add('open');
    }
    function closeMaterialDrop() { $materialDrop.classList.remove('open'); $materialDrop.innerHTML = ''; mItems = []; mActive = -1; }
    function chooseMaterial(it) { $material.value = it.display_name; closeMaterialDrop(); }
    $material.addEventListener('input', doMaterialSearch);
    $material.addEventListener('keydown', function (e) {
        if (!$materialDrop.classList.contains('open') || !mItems.length) return;
        if (e.key === 'ArrowDown') { e.preventDefault(); mActive = Math.min(mActive + 1, mItems.length - 1); highlightDrop($materialDrop, mActive); }
        else if (e.key === 'ArrowUp') { e.preventDefault(); mActive = Math.max(mActive - 1, 0); highlightDrop($materialDrop, mActive); }
        else if (e.key === 'Enter' || e.key === 'Tab') { if (mActive >= 0 && mItems[mActive]) { e.preventDefault(); chooseMaterial(mItems[mActive]); } }
        else if (e.key === 'Escape') { closeMaterialDrop(); }
    });
    $materialDrop.addEventListener('click', function (e) { var li = e.target.closest('li[data-idx]'); if (li) chooseMaterial(mItems[+li.dataset.idx]); });
    function highlightDrop($drop, active) { Array.prototype.forEach.call($drop.children, function (li, i) { li.classList.toggle('active', i === active); }); }

    /* ====================================================================
     *  TÌM NHÀ CUNG CẤP (tham chiếu bảng suppliers)
     * ==================================================================== */
    var sItems = [], sActive = -1;
    var doSupplierSearch = debounce(function () {
        var kw = $supplier.value.trim();
        if (kw === '') { closeSupplierDrop(); return; }
        post('search_suppliers', { keyword: kw }).then(function (res) { sItems = (res && res.data) || []; renderSupplierDrop(); });
    }, 180);
    function renderSupplierDrop() {
        sActive = -1;
        if (!sItems.length) { $supplierDrop.innerHTML = '<li class="empty">Không tìm thấy nhà cung cấp.</li>'; $supplierDrop.classList.add('open'); return; }
        $supplierDrop.innerHTML = sItems.map(function (it, i) {
            return '<li data-idx="' + i + '"><strong>' + esc(it.supplier_name) + '</strong>' +
                (it.address ? '<small>' + esc(it.address) + '</small>' : '') + '</li>';
        }).join('');
        $supplierDrop.classList.add('open');
    }
    function closeSupplierDrop() { $supplierDrop.classList.remove('open'); $supplierDrop.innerHTML = ''; sItems = []; sActive = -1; }
    function chooseSupplier(it) {
        closeSupplierDrop();
        $supplier.value = it.supplier_name;
        selectedSupplier = { id: it.id, supplier_name: it.supplier_name, address: it.address || '' };
        $supplierAddr.textContent = selectedSupplier.address;
        $qty.focus();
    }
    $supplier.addEventListener('input', function () { selectedSupplier = null; $supplierAddr.textContent = ''; doSupplierSearch(); });
    $supplier.addEventListener('keydown', function (e) {
        if (!$supplierDrop.classList.contains('open') || !sItems.length) return;
        if (e.key === 'ArrowDown') { e.preventDefault(); sActive = Math.min(sActive + 1, sItems.length - 1); highlightDrop($supplierDrop, sActive); }
        else if (e.key === 'ArrowUp') { e.preventDefault(); sActive = Math.max(sActive - 1, 0); highlightDrop($supplierDrop, sActive); }
        else if (e.key === 'Enter' || e.key === 'Tab') { if (sActive >= 0 && sItems[sActive]) { e.preventDefault(); chooseSupplier(sItems[sActive]); } }
        else if (e.key === 'Escape') { closeSupplierDrop(); }
    });
    $supplierDrop.addEventListener('click', function (e) { var li = e.target.closest('li[data-idx]'); if (li) chooseSupplier(sItems[+li.dataset.idx]); });
    document.addEventListener('click', function (e) {
        if (!e.target.closest('.tl-search-wrap')) { closeMaterialDrop(); closeSupplierDrop(); }
    });

    /* ====================================================================
     *  LOẠI TRÀ
     * ==================================================================== */
    $teaTypes.addEventListener('click', function (e) {
        var btn = e.target.closest('.tl-tea-btn');
        if (!btn) return;
        Array.prototype.forEach.call($teaTypes.querySelectorAll('.tl-tea-btn'), function (b) { b.classList.remove('active'); });
        btn.classList.add('active');
        selectedTea = btn.dataset.tea;
    });

    /* ====================================================================
     *  LỊCH CHỌN NGÀY (tự chế, xanh lá — giống production_label)
     * ==================================================================== */
    var $dateHidden = $('tl-date'), $dateDisplay = $('tl-date-display'), $dateCal = $('tl-date-cal');
    var MONTH_NAMES = ['Tháng 1', 'Tháng 2', 'Tháng 3', 'Tháng 4', 'Tháng 5', 'Tháng 6', 'Tháng 7', 'Tháng 8', 'Tháng 9', 'Tháng 10', 'Tháng 11', 'Tháng 12'];
    var WEEKDAYS = ['T2', 'T3', 'T4', 'T5', 'T6', 'T7', 'CN'];
    var calViewY, calViewM;

    function pad2(n) { return String(n).padStart(2, '0'); }
    function fmtDateDisplay(y, m, d) { return pad2(d) + '/' + pad2(m) + '/' + y; }
    function parseISODate(v) {
        var m = /^(\d{4})-(\d{2})-(\d{2})$/.exec(String(v || ''));
        return m ? { y: +m[1], m: +m[2], d: +m[3] } : null;
    }
    function setSelectedDate(y, m, d) {
        $dateHidden.value = y + '-' + pad2(m) + '-' + pad2(d);
        $dateDisplay.textContent = fmtDateDisplay(y, m, d);
    }
    function daysInMonth(y, m) { return new Date(y, m, 0).getDate(); }
    function renderCalendar() {
        var sel = parseISODate($dateHidden.value);
        var t = new Date();
        var startWeekday = (new Date(calViewY, calViewM - 1, 1).getDay() + 6) % 7;
        var total = daysInMonth(calViewY, calViewM);
        var html = '<div class="tl-cal-head">'
            + '<button type="button" class="tl-cal-nav" data-nav="-1"><i class="fa-solid fa-chevron-left"></i></button>'
            + '<span class="tl-cal-title">' + MONTH_NAMES[calViewM - 1] + ' ' + calViewY + '</span>'
            + '<button type="button" class="tl-cal-nav" data-nav="1"><i class="fa-solid fa-chevron-right"></i></button>'
            + '</div><div class="tl-cal-week">' + WEEKDAYS.map(function (w) { return '<span>' + w + '</span>'; }).join('') + '</div>'
            + '<div class="tl-cal-grid">';
        for (var i = 0; i < startWeekday; i++) html += '<span class="tl-cal-day empty"></span>';
        for (var d = 1; d <= total; d++) {
            var isToday = t.getFullYear() === calViewY && (t.getMonth() + 1) === calViewM && t.getDate() === d;
            var isSel = sel && sel.y === calViewY && sel.m === calViewM && sel.d === d;
            html += '<span class="tl-cal-day' + (isToday ? ' today' : '') + (isSel ? ' selected' : '') + '" data-d="' + d + '">' + d + '</span>';
        }
        html += '</div>';
        $dateCal.innerHTML = html;
    }
    function openCal() {
        var sel = parseISODate($dateHidden.value) || (function () { var n = new Date(); return { y: n.getFullYear(), m: n.getMonth() + 1, d: n.getDate() }; })();
        calViewY = sel.y; calViewM = sel.m;
        renderCalendar();
        $dateCal.style.display = 'block';
    }
    function closeCal() { $dateCal.style.display = 'none'; }
    $dateDisplay.addEventListener('click', function (e) {
        e.stopPropagation();
        if ($dateCal.style.display === 'block') closeCal(); else openCal();
    });
    $dateCal.addEventListener('click', function (e) {
        var nav = e.target.closest('[data-nav]');
        if (nav) {
            var dir = +nav.dataset.nav;
            calViewM += dir;
            if (calViewM < 1) { calViewM = 12; calViewY--; }
            else if (calViewM > 12) { calViewM = 1; calViewY++; }
            renderCalendar();
            return;
        }
        var day = e.target.closest('.tl-cal-day:not(.empty)');
        if (day) { setSelectedDate(calViewY, calViewM, +day.dataset.d); closeCal(); }
    });
    document.addEventListener('click', function (e) { if (!e.target.closest('.tl-date-picker')) closeCal(); });
    (function initDateDisplay() {
        var init = parseISODate($dateHidden.value);
        if (init) $dateDisplay.textContent = fmtDateDisplay(init.y, init.m, init.d);
    })();

    /* ====================================================================
     *  TẠO TEM
     * ==================================================================== */
    function addCard(batchKey) {
        var wrap = $tpl.content.firstElementChild.cloneNode(true);
        wrap.dataset.material = batchKey;
        var node = wrap.querySelector('.tl-card');
        node.querySelector('.tl-card-name').textContent = $material.value.trim();
        node.querySelector('.tl-card-composition').textContent = selectedTea + ' 100%';
        node.querySelector('.tl-card-date').textContent = $dateDisplay.textContent;
        node.querySelector('.tl-card-company').textContent = selectedSupplier ? selectedSupplier.supplier_name : '';
        node.querySelector('.tl-card-supplier-address').textContent = selectedSupplier ? selectedSupplier.address : '';
        Array.prototype.forEach.call(node.querySelectorAll('.tl-fixed-editable'), function (el) {
            var field = el.dataset.field;
            el.querySelector('.tl-fixed-text').textContent = fixedTexts[field] || '';
        });
        node.querySelector('.tl-card-del').addEventListener('click', function () { wrap.remove(); updateGridEmpty(); });
        $grid.appendChild(wrap);
    }

    $btnMake.addEventListener('click', function () {
        var name = $material.value.trim();
        var qty = parseFloat($qty.value);
        var spec = parseFloat($spec.value);
        if (name === '') { alert('Vui lòng nhập tên nguyên liệu.'); return; }
        if (!selectedSupplier) { alert('Vui lòng chọn nhà cung cấp.'); return; }
        if (isNaN(qty) || qty <= 0) { alert('Số lượng nhập phải lớn hơn 0.'); return; }
        if (isNaN(spec) || spec <= 0) { alert('Quy cách chia phải lớn hơn 0.'); return; }
        var count = Math.ceil(qty / spec);
        materialSeq++;
        for (var i = 0; i < count; i++) addCard(materialSeq);
        updateGridEmpty();
    });

    /* ---------- Sửa tại chỗ 3 dòng cố định (Công dụng/Hạn sử dụng/Bảo quản):
     * hover hiện bút, sửa 1 lần lưu app_settings, đồng bộ ngay mọi tem hiện có ---------- */
    function enterFixedEdit(wrap) {
        if (!wrap || wrap.classList.contains('editing')) return;
        var txt = wrap.querySelector('.tl-fixed-text');
        wrap.classList.add('editing');
        txt.dataset.orig = txt.textContent;
        txt.setAttribute('contenteditable', 'true');
        txt.focus();
        var range = document.createRange();
        range.selectNodeContents(txt);
        var sel = window.getSelection();
        sel.removeAllRanges();
        sel.addRange(range);
    }
    function commitFixedEdit(wrap) {
        if (!wrap || !wrap.classList.contains('editing')) return;
        var field = wrap.dataset.field;
        var txt = wrap.querySelector('.tl-fixed-text');
        wrap.classList.remove('editing');
        txt.removeAttribute('contenteditable');
        var val = (txt.textContent || '').replace(/\s+/g, ' ').trim();
        txt.textContent = val;
        if (!field || val === fixedTexts[field]) return;
        fixedTexts[field] = val;
        Array.prototype.forEach.call($grid.querySelectorAll('.tl-fixed-editable[data-field="' + field + '"] .tl-fixed-text'), function (t) { t.textContent = val; });
        post('save_fixed_text', { key: field, value: val }).then(function (res) {
            if (!res || !res.success) console.warn('Lưu thất bại:', res && res.message);
        });
    }
    $grid.addEventListener('click', function (e) {
        var wrap = e.target.closest('.tl-fixed-editable');
        if (wrap && !wrap.classList.contains('editing')) { e.preventDefault(); enterFixedEdit(wrap); }
    });
    $grid.addEventListener('blur', function (e) {
        if (e.target.classList && e.target.classList.contains('tl-fixed-text')) {
            commitFixedEdit(e.target.closest('.tl-fixed-editable'));
        }
    }, true);
    $grid.addEventListener('keydown', function (e) {
        if (!e.target.classList || !e.target.classList.contains('tl-fixed-text')) return;
        if (e.key === 'Enter') { e.preventDefault(); e.target.blur(); }
        else if (e.key === 'Escape') {
            e.target.textContent = e.target.dataset.orig || '';
            var wrap = e.target.closest('.tl-fixed-editable');
            wrap.classList.remove('editing');
            e.target.removeAttribute('contenteditable');
        }
    });

    /* ====================================================================
     *  Reset + In (modal xem trước, đường nét đứt đỏ đánh dấu chuyển nguyên liệu)
     * ==================================================================== */
    $btnReset.addEventListener('click', function () {
        Array.prototype.forEach.call($grid.querySelectorAll('.tl-card-wrap'), function (c) { c.remove(); });
        updateGridEmpty();
    });

    /* Layout in luôn cố định 2 cột/hàng (card 90mm + 2mm*2 lề ≈ 94mm, A4 trừ lề 8mm mỗi bên
     * còn 194mm → vừa đúng 2 cột), giống PRINT_COLS của production_label. Tính ranh giới dựa
     * vào chỉ số thứ tự (không dùng offsetTop/Left) vì bản xem trước dùng CSS grid cố định cột. */
    var PRINT_COLS = 2;
    function buildPrintPreview() {
        $printPreview.innerHTML = '';
        var wraps = Array.prototype.slice.call($grid.querySelectorAll('.tl-card-wrap'));
        wraps.forEach(function (w, i) {
            var clone = w.cloneNode(true);
            var del = clone.querySelector('.tl-card-del');
            if (del) del.remove();
            Array.prototype.forEach.call(clone.querySelectorAll('[contenteditable]'), function (el) { el.removeAttribute('contenteditable'); });
            clone.classList.remove('tl-boundary-col', 'tl-boundary-row');
            if (i > 0) {
                var curKey = w.dataset.material, prevKey = wraps[i - 1].dataset.material;
                if (curKey && curKey !== prevKey) {
                    clone.classList.add(i % PRINT_COLS === 0 ? 'tl-boundary-row' : 'tl-boundary-col');
                }
            }
            $printPreview.appendChild(clone);
        });
    }
    function openPrintModal() {
        if (!$grid.querySelector('.tl-card-wrap')) { alert('Chưa có tem nào để in.'); return; }
        buildPrintPreview();
        $printModal.classList.add('open');
        $printModal.setAttribute('aria-hidden', 'false');
    }
    function closePrintModal() {
        $printModal.classList.remove('open');
        $printModal.setAttribute('aria-hidden', 'true');
    }
    $btnPrint.addEventListener('click', openPrintModal);
    $('tl-print-modal-close').addEventListener('click', closePrintModal);
    $('tl-print-modal-cancel').addEventListener('click', closePrintModal);
    $('tl-print-modal-overlay').addEventListener('click', closePrintModal);
    $('tl-print-modal-go').addEventListener('click', function () { window.print(); });
    document.addEventListener('keydown', function (e) {
        if (e.key === 'Escape' && $printModal.classList.contains('open')) closePrintModal();
    });

    updateGridEmpty();
})();
