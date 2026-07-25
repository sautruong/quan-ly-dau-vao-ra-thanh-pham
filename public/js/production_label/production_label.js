/* =====================================================================
 *  Tạo tem bao bì ngoài (production_label)
 * ===================================================================== */
(function () {
    'use strict';

    var CFG = window.PL_CONFIG || { baseUrl: '?mod=production_label&controllers=production_label&action=' };

    /* ---------- DOM ---------- */
    var $ = function (id) { return document.getElementById(id); };
    var $search = $('pl-search'), $sd = $('pl-search-dropdown');
    var $date = $('pl-date'), $batch = $('pl-batch');
    var $btnReset = $('pl-btn-reset'), $btnSample = $('pl-btn-sample'), $btnOuter = $('pl-btn-outer');
    var $btnPrint = $('pl-btn-print');
    var $grid = $('pl-grid'), $gridEmpty = $('pl-grid-empty'), $sampleWarning = $('pl-sample-warning');
    var $qtyExpected = $('pl-qty-expected'), $btnAddList = $('pl-btn-add-list');
    var $list = $('pl-list'), $listEmpty = $('pl-list-empty');
    var $tplSample = $('pl-tpl-sample'), $tplOuter = $('pl-tpl-outer');

    /* ---------- State ---------- */
    var selectedProduct = null; // {id, product_name}
    var hotlineValue = (CFG.hotline || '0777 044 777');

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
    function fmtNum(n) {
        if (n === null || n === undefined || n === '') return '';
        var v = Number(n);
        if (isNaN(v)) return '';
        return (Math.round(v * 100) / 100).toString();
    }
    function updateGridEmpty() {
        $gridEmpty.style.display = $grid.querySelectorAll('.pl-card').length ? 'none' : 'block';
        $sampleWarning.style.display = $grid.querySelector('.pl-card-sample') ? '' : 'none';
        updateProductBoundaries();
    }

    /* Đường ngăn nổi bật khi in tại vị trí chuyển sản phẩm trong lưới tem. Layout in luôn
     * cố định 2 cột/hàng (A4 trừ lề 8mm — xem ghi chú @media print), nên xác định hàng/cột
     * bằng chỉ số thứ tự (i % PRINT_COLS), KHÔNG dùng offsetTop/Left (sai lệch lúc xem trên
     * màn hình vì số cột co giãn theo bề rộng trình duyệt). Chỉ ảnh hưởng khi in (CSS gated
     * trong @media print), class vẫn gắn sẵn trên DOM để khỏi tính lại lúc bấm "In A4". */
    var PRINT_COLS = 2;
    function updateProductBoundaries() {
        var wraps = Array.prototype.slice.call($grid.querySelectorAll('.pl-card-wrap'));
        wraps.forEach(function (w, i) {
            w.classList.remove('pl-boundary-col', 'pl-boundary-row');
            if (i === 0) return;
            var curPid = w.dataset.productId;
            var prevPid = wraps[i - 1].dataset.productId;
            if (!curPid || curPid === prevPid) return;
            if (i % PRINT_COLS === 0) w.classList.add('pl-boundary-row');
            else w.classList.add('pl-boundary-col');
        });
    }

    /* ====================================================================
     *  SEARCH SẢN PHẨM
     * ==================================================================== */
    var sdItems = [], sdActive = -1;
    var doSearch = debounce(function () {
        var kw = $search.value.trim();
        if (kw === '') { closeDropdown(); return; }
        post('search_products', { keyword: kw }).then(function (res) { sdItems = (res && res.data) || []; renderDropdown(); });
    }, 180);
    function renderDropdown() {
        sdActive = -1;
        if (!sdItems.length) { $sd.innerHTML = '<li class="empty">Không tìm thấy sản phẩm.</li>'; $sd.classList.add('open'); return; }
        $sd.innerHTML = sdItems.map(function (it, i) {
            return '<li data-idx="' + i + '">' + esc(it.product_name) + '</li>';
        }).join('');
        $sd.classList.add('open');
    }
    function closeDropdown() { $sd.classList.remove('open'); $sd.innerHTML = ''; sdItems = []; sdActive = -1; }
    function highlight() { Array.prototype.forEach.call($sd.children, function (li, i) { li.classList.toggle('active', i === sdActive); }); }
    function chooseProduct(it) {
        closeDropdown();
        $search.value = it.product_name;
        selectedProduct = { id: it.id, product_name: it.product_name };
        $batch.focus();
        $batch.select();
    }
    $search.addEventListener('input', function () { selectedProduct = null; doSearch(); });
    $search.addEventListener('keydown', function (e) {
        if (!$sd.classList.contains('open') || !sdItems.length) return;
        if (e.key === 'ArrowDown') { e.preventDefault(); sdActive = Math.min(sdActive + 1, sdItems.length - 1); highlight(); }
        else if (e.key === 'ArrowUp') { e.preventDefault(); sdActive = Math.max(sdActive - 1, 0); highlight(); }
        else if (e.key === 'Enter' || e.key === 'Tab') {
            if (sdActive >= 0 && sdItems[sdActive]) { e.preventDefault(); chooseProduct(sdItems[sdActive]); }
        }
        else if (e.key === 'Escape') { closeDropdown(); }
    });
    $sd.addEventListener('click', function (e) { var li = e.target.closest('li[data-idx]'); if (li) chooseProduct(sdItems[+li.dataset.idx]); });
    document.addEventListener('click', function (e) { if (!e.target.closest('.pl-search-wrap')) closeDropdown(); });

    /* ---------- Số mẻ: chỉ nhận số nguyên >= 1 ---------- */
    function clampBatchInput(el) {
        var n = parseInt(el.value, 10);
        if (isNaN(n) || n <= 0) n = 1;
        el.value = String(n);
    }
    $batch.addEventListener('blur', function () { clampBatchInput($batch); });

    /* ====================================================================
     *  LỊCH CHỌN NGÀY (tự chế, xanh lá — input[type=date] gốc không style được popup)
     * ==================================================================== */
    var $dateHidden = $('pl-date'), $dateDisplay = $('pl-date-display'), $dateCal = $('pl-date-cal');
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
        var startWeekday = (new Date(calViewY, calViewM - 1, 1).getDay() + 6) % 7; // Thứ 2 = 0
        var total = daysInMonth(calViewY, calViewM);
        var html = '<div class="pl-cal-head">'
            + '<button type="button" class="pl-cal-nav" data-nav="-1"><i class="fa-solid fa-chevron-left"></i></button>'
            + '<span class="pl-cal-title">' + MONTH_NAMES[calViewM - 1] + ' ' + calViewY + '</span>'
            + '<button type="button" class="pl-cal-nav" data-nav="1"><i class="fa-solid fa-chevron-right"></i></button>'
            + '</div><div class="pl-cal-week">' + WEEKDAYS.map(function (w) { return '<span>' + w + '</span>'; }).join('') + '</div>'
            + '<div class="pl-cal-grid">';
        for (var i = 0; i < startWeekday; i++) html += '<span class="pl-cal-day empty"></span>';
        for (var d = 1; d <= total; d++) {
            var isToday = t.getFullYear() === calViewY && (t.getMonth() + 1) === calViewM && t.getDate() === d;
            var isSel = sel && sel.y === calViewY && sel.m === calViewM && sel.d === d;
            html += '<span class="pl-cal-day' + (isToday ? ' today' : '') + (isSel ? ' selected' : '') + '" data-d="' + d + '">' + d + '</span>';
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
        var day = e.target.closest('.pl-cal-day:not(.empty)');
        if (day) { setSelectedDate(calViewY, calViewM, +day.dataset.d); closeCal(); }
    });
    document.addEventListener('click', function (e) { if (!e.target.closest('.pl-date-picker')) closeCal(); });
    (function initDateDisplay() {
        var init = parseISODate($dateHidden.value);
        if (init) $dateDisplay.textContent = fmtDateDisplay(init.y, init.m, init.d);
    })();

    /* ====================================================================
     *  DANH SÁCH SẢN XUẤT — mặc định nạp sản phẩm từ KHSX (production_plans, PHP
     *  render sẵn khi load trang), thêm được sản phẩm khác ngoài KHSX (chỉ cần chọn
     *  SP + nhập Mẻ + SLSX Dự kiến ở khối Thiết lập, không hỏi lại qua popup), sửa số
     *  lượng/mẻ từng dòng, xóa dòng (= không tạo tem cho SP đó). Nút "Tạo tem bao bì
     *  ngoài" xử lý TOÀN BỘ danh sách 1 lượt, đẩy thẳng ra khối "Tem được tạo".
     * ==================================================================== */
    function updateListEmpty() {
        $listEmpty.style.display = $list.querySelectorAll('.pl-list-item').length ? 'none' : '';
    }
    function addListRow(productId, productName, batch, qty) {
        var li = document.createElement('li');
        li.className = 'pl-list-item';
        li.dataset.productId = productId;
        li.innerHTML = '<span class="pl-list-name"></span>' +
            '<label class="pl-list-field">Mẻ<input type="text" class="pl-list-batch" maxlength="2" inputmode="numeric"></label>' +
            '<label class="pl-list-field">SLSX Dự kiến<input type="number" class="pl-list-qty" min="1" step="1"></label>' +
            '<label class="pl-list-field pl-list-sample-field">' +
            '<span class="app-round-check"><input type="checkbox" class="pl-list-sample-check">' +
            '<span class="app-round-check-mark"><i class="fa-solid fa-check"></i></span></span>' +
            'Tạo tem lưu mẫu</label>' +
            '<button type="button" class="pl-list-del" title="Xóa khỏi danh sách"><i class="fa-solid fa-xmark"></i></button>';
        li.querySelector('.pl-list-name').textContent = productName || '';
        li.querySelector('.pl-list-batch').value = batch || '1';
        li.querySelector('.pl-list-qty').value = qty || '';
        $list.insertBefore(li, $listEmpty);
        updateListEmpty();
    }
    $list.addEventListener('click', function (e) {
        var btn = e.target.closest('.pl-list-del');
        if (!btn) return;
        btn.closest('.pl-list-item').remove();
        updateListEmpty();
    });
    $list.addEventListener('blur', function (e) {
        if (e.target.classList && e.target.classList.contains('pl-list-batch')) clampBatchInput(e.target);
    }, true);
    /* Tích "Tạo tem lưu mẫu" ở 1 dòng danh sách → tạo ngay 1 tem lưu mẫu cho SP đó
     * (mượn get_sample_label_data + addSampleCard, giống nút pl-btn-sample). Bỏ tích
     * không xóa tem đã tạo; tích lại thì tạo thêm 1 tem nữa. */
    $list.addEventListener('change', function (e) {
        if (!e.target.classList || !e.target.classList.contains('pl-list-sample-check')) return;
        if (!e.target.checked) return;
        var row = e.target.closest('.pl-list-item');
        var pid = row && row.dataset.productId;
        if (!pid) { e.target.checked = false; return; }
        post('get_sample_label_data', { product_id: pid, date: $date.value }).then(function (res) {
            if (!res || !res.success) { alert((res && res.message) || 'Lỗi tạo tem lưu mẫu.'); e.target.checked = false; return; }
            addSampleCard(res.data);
        });
    });
    updateListEmpty();

    $btnAddList.addEventListener('click', function () {
        if (!selectedProduct) { alert('Vui lòng chọn sản phẩm.'); return; }
        var qty = parseFloat($qtyExpected.value);
        if (isNaN(qty) || qty <= 0) { alert('SLSX Dự kiến phải lớn hơn 0.'); $qtyExpected.focus(); return; }
        clampBatchInput($batch);
        addListRow(selectedProduct.id, selectedProduct.product_name, $batch.value, qty);
        $search.value = '';
        selectedProduct = null;
        $batch.value = '1';
        $qtyExpected.value = '';
        $search.focus();
    });

    /* ====================================================================
     *  TẠO TEM LƯU MẪU
     * ==================================================================== */
    function addSampleCard(data) {
        var wrap = $tplSample.content.firstElementChild.cloneNode(true);
        wrap.dataset.productId = data.product_id || '';
        var node = wrap.querySelector('.pl-card');
        node.classList.add('pl-cat-' + (data.color_key || 'gray'));
        var nameEl = node.querySelector('.pl-card-name');
        nameEl.textContent = data.product_name || '';
        nameEl.dataset.productId = data.product_id || '';
        nameEl.dataset.orig = data.product_name || '';
        var tbody = node.querySelector('.pl-card-ing tbody');
        (data.ingredients || []).forEach(function (ing) {
            var tr = document.createElement('tr');
            var lastQty = ing.last_qty === null || ing.last_qty === undefined ? '—' : fmtNum(ing.last_qty);
            tr.innerHTML = '<td>' + esc(ing.name) + '</td><td>' + esc(fmtNum(ing.stock)) + '</td><td>' +
                esc(lastQty) + '</td><td>' + esc(ing.last_date_display || '—') + '</td>';
            tbody.appendChild(tr);
        });
        node.querySelector('.pl-card-date').textContent = data.production_date_display || '';
        node.querySelector('.pl-card-del').addEventListener('click', function () { wrap.remove(); updateGridEmpty(); });
        $grid.appendChild(wrap);
        updateGridEmpty();
    }

    $btnSample.addEventListener('click', function () {
        if (!selectedProduct) { alert('Vui lòng chọn sản phẩm.'); return; }
        post('get_sample_label_data', { product_id: selectedProduct.id, date: $date.value }).then(function (res) {
            if (!res || !res.success) { alert((res && res.message) || 'Lỗi tạo tem lưu mẫu.'); return; }
            addSampleCard(res.data);
        });
    });

    /* ====================================================================
     *  TẠO TEM BAO BÌ NGOÀI
     * ==================================================================== */
    function addOuterCard(data) {
        var wrap = $tplOuter.content.firstElementChild.cloneNode(true);
        wrap.dataset.productId = data.product_id || '';
        var node = wrap.querySelector('.pl-card');
        var nameEl = node.querySelector('.pl-card-name');
        nameEl.textContent = data.product_name || '';
        nameEl.dataset.productId = data.product_id || '';
        nameEl.dataset.orig = data.product_name || '';
        node.querySelector('.pl-card-lot').textContent = 'LOT: ' + (data.lot || '');
        node.querySelector('.pl-card-spec').textContent = data.spec_display || '';
        node.querySelector('.pl-card-date').textContent = data.production_date_display || '';
        node.querySelector('.pl-hotline-text').textContent = hotlineValue;
        node.querySelector('.pl-card-del').addEventListener('click', function () { wrap.remove(); updateGridEmpty(); });
        $grid.appendChild(wrap);
    }

    /* ---------- Tên thường gọi trên card (.pl-card-name): sửa trực tiếp (contenteditable
     * sẵn có), lưu vào products.common_product_name và đồng bộ ngay sang mọi card khác
     * đang hiển thị CÙNG sản phẩm (khớp theo data-product-id). ---------- */
    function commitCardNameEdit(nameEl) {
        var val = (nameEl.textContent || '').replace(/\s+/g, ' ').trim();
        nameEl.textContent = val;
        var pid = nameEl.dataset.productId;
        if (!pid || val === '' || val === nameEl.dataset.orig) return;
        nameEl.dataset.orig = val;
        Array.prototype.forEach.call($grid.querySelectorAll('.pl-card-name[data-product-id="' + pid + '"]'), function (el) {
            el.textContent = val;
            el.dataset.orig = val;
        });
        post('save_product_common_name', { product_id: pid, value: val });
    }
    $grid.addEventListener('blur', function (e) {
        if (e.target.classList && e.target.classList.contains('pl-card-name')) {
            commitCardNameEdit(e.target);
        }
    }, true);
    $grid.addEventListener('keydown', function (e) {
        if (!e.target.classList || !e.target.classList.contains('pl-card-name')) return;
        if (e.key === 'Enter') { e.preventDefault(); e.target.blur(); }
    });

    /* ---------- Hotline: sửa tại chỗ (hover hiện bút), lưu 1 lần dùng nhiều lần,
     * đổi 1 card đồng bộ ngay sang mọi card bao bì ngoài đang có trên lưới ---------- */
    function enterHotlineEdit(wrap) {
        if (!wrap || wrap.classList.contains('editing')) return;
        var txt = wrap.querySelector('.pl-hotline-text');
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
    function commitHotlineEdit(wrap) {
        if (!wrap || !wrap.classList.contains('editing')) return;
        var txt = wrap.querySelector('.pl-hotline-text');
        wrap.classList.remove('editing');
        txt.removeAttribute('contenteditable');
        var val = (txt.textContent || '').replace(/\s+/g, ' ').trim();
        txt.textContent = val;
        if (val === hotlineValue) return;
        hotlineValue = val;
        Array.prototype.forEach.call($grid.querySelectorAll('.pl-hotline-text'), function (t) { t.textContent = val; });
        post('save_hotline', { value: val }).then(function (res) {
            if (!res || !res.success) console.warn('Lưu hotline thất bại.');
        });
    }
    $grid.addEventListener('click', function (e) {
        var wrap = e.target.closest('.pl-hotline-editable');
        if (wrap && !wrap.classList.contains('editing')) { e.preventDefault(); enterHotlineEdit(wrap); }
    });
    $grid.addEventListener('blur', function (e) {
        if (e.target.classList && e.target.classList.contains('pl-hotline-text')) {
            commitHotlineEdit(e.target.closest('.pl-hotline-editable'));
        }
    }, true);
    $grid.addEventListener('keydown', function (e) {
        if (!e.target.classList || !e.target.classList.contains('pl-hotline-text')) return;
        if (e.key === 'Enter') { e.preventDefault(); e.target.blur(); }
        else if (e.key === 'Escape') {
            e.target.textContent = e.target.dataset.orig || '';
            var wrap = e.target.closest('.pl-hotline-editable');
            wrap.classList.remove('editing');
            e.target.removeAttribute('contenteditable');
        }
    });

    /** Tạo tem bao bì ngoài hàng loạt: xử lý toàn bộ dòng còn lại trong "Danh sách sản
     * xuất" (dòng đã xóa = bỏ qua, không tạo tem), mỗi dòng dùng Mẻ + SLSX Dự kiến của
     * chính dòng đó, không hỏi lại số lượng qua popup nữa. */
    $btnOuter.addEventListener('click', function () {
        var rows = Array.prototype.slice.call($list.querySelectorAll('.pl-list-item'));
        if (!rows.length) { alert('Danh sách sản xuất đang trống.'); return; }
        var errors = [];
        var tasks = rows.map(function (row) {
            var pid  = row.dataset.productId;
            var name = (row.querySelector('.pl-list-name').textContent || '').trim();
            var batch = row.querySelector('.pl-list-batch').value;
            var qty  = parseFloat(row.querySelector('.pl-list-qty').value);
            if (!pid || isNaN(qty) || qty <= 0) {
                errors.push(name + ': SLSX Dự kiến không hợp lệ.');
                return Promise.resolve();
            }
            return post('get_outer_label_data', {
                product_id: pid, date: $date.value, batch_count: batch, qty_needed: qty
            }).then(function (res) {
                if (!res || !res.success) { errors.push(name + ': ' + ((res && res.message) || 'Lỗi tạo tem.')); return; }
                (res.data.groups || []).forEach(function (g) {
                    var cardData = {
                        product_id: pid,
                        product_name: res.data.product_name,
                        spec_display: res.data.spec_display,
                        production_date_display: res.data.production_date_display,
                        lot: g.lot
                    };
                    var n = Number(g.label_count) || 0;
                    for (var i = 0; i < n; i++) addOuterCard(cardData);
                });
            });
        });
        Promise.all(tasks).then(function () {
            updateGridEmpty();
            if (errors.length) alert(errors.join('\n'));
        });
    });

    /* ====================================================================
     *  Reset + In
     * ==================================================================== */
    $btnReset.addEventListener('click', function () {
        Array.prototype.forEach.call($grid.querySelectorAll('.pl-card-wrap'), function (c) { c.remove(); });
        updateGridEmpty();
    });
    $btnPrint.addEventListener('click', function () { window.print(); });

    updateGridEmpty();
})();
