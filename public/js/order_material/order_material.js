/* =====================================================================
 *  Đặt hàng NVL (order_material)
 *  KHỐI 1: Phân tích NVL  |  KHỐI 2: Đơn đặt hàng
 * ===================================================================== */
(function () {
    'use strict';

    var CFG = window.OM_CONFIG || { baseUrl: '?mod=order_material&controllers=order_material&action=', orders: [] };

    /* ---------- State ---------- */
    var supplier = null;        // { id, supplier_name, ... }
    var materials = [];         // NVL của NCC đang chọn
    var matPage = 1;            // trang hiện tại bảng NVL
    var matPageSize = 10;       // số dòng / trang (mặc định 10)
    var docItems = [];          // [{ material_id, name, unit, qty, origUnit }] trong phiếu
    var convertItem = null;     // dòng đang quy đổi đơn vị
    var analysis = null;        // dữ liệu modal phân tích đang mở
    var supList = [];           // dropdown NCC hiện tại
    var supActive = -1;         // chỉ số đang highlight trong dropdown
    var editingOrderId = 0;     // id đơn đang "Sửa đơn" (0 = phiếu hiện tại là đơn MỚI)

    /* ---------- DOM ---------- */
    var $ = function (id) { return document.getElementById(id); };
    var supInput = $('om-supplier-input'), supDrop = $('om-supplier-dropdown'), supClear = $('om-search-clear');
    var btnMin = $('om-btn-min-setting'), btnSupInfo = $('om-btn-supplier-info');
    var matTbody = $('om-mat-tbody'), matRowTpl = $('om-mat-row-tpl');
    var faqBody = $('om-faq-body');
    var docTbody = $('om-doc-tbody'), docRowTpl = $('om-doc-row-tpl'), docSupplier = $('om-doc-supplier');
    var matPager = $('om-mat-pager'), matPageSizeSel = $('om-mat-pagesize');
    var matPrev = $('om-mat-prev'), matNext = $('om-mat-next'), matPageInfo = $('om-mat-pageinfo');
    var saveOrderBtn = $('om-btn-save-order'), saveOrderBtnDefaultHtml = saveOrderBtn.innerHTML;

    /** Đổi nhãn nút "Lưu đơn" <-> "Cập nhật đơn" theo trạng thái đang sửa 1 đơn đã lưu hay không. */
    function updateSaveBtnUI() {
        if (editingOrderId) {
            saveOrderBtn.innerHTML = '<i class="fa-solid fa-pen-to-square"></i> Cập nhật đơn';
            saveOrderBtn.classList.add('is-editing');
        } else {
            saveOrderBtn.innerHTML = saveOrderBtnDefaultHtml;
            saveOrderBtn.classList.remove('is-editing');
        }
    }

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
    function postJSON(action, obj) {
        return fetch(CFG.baseUrl + action, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json; charset=UTF-8' },
            body: JSON.stringify(obj)
        }).then(function (r) { return r.json(); });
    }
    function debounce(fn, ms) { var t; return function () { var a = arguments, c = this; clearTimeout(t); t = setTimeout(function () { fn.apply(c, a); }, ms); }; }
    function esc(s) {
        return String(s == null ? '' : s).replace(/[&<>"]/g, function (c) {
            return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;' }[c];
        });
    }
    function num(n, dec) {
        if (dec === undefined) dec = 2;
        var v = Math.round((Number(n) + Number.EPSILON) * Math.pow(10, dec)) / Math.pow(10, dec);
        if (!isFinite(v)) v = 0;
        return v.toLocaleString('vi-VN', { maximumFractionDigits: dec });
    }
    function money(n) { return Math.round(Number(n) || 0).toLocaleString('en-US') + ' đ'; }
    function parseNum(s) { var v = parseFloat(String(s).replace(/\./g, '').replace(',', '.')); return isFinite(v) ? v : 0; }

    /* ---------- Ghi nhớ NCC đang chọn + phiếu đang soạn qua reload (localStorage) ---------- */
    var LS_SUPPLIER_KEY = 'om_selected_supplier';
    var LS_DOC_ITEMS_KEY = 'om_doc_items';
    function saveSupplier(s) {
        try {
            if (s && s.supplier_name) localStorage.setItem(LS_SUPPLIER_KEY, JSON.stringify({ id: s.id, supplier_name: s.supplier_name }));
            else localStorage.removeItem(LS_SUPPLIER_KEY);
        } catch (e) { /* localStorage không khả dụng -> bỏ qua */ }
    }
    function loadSupplier() {
        try {
            var s = JSON.parse(localStorage.getItem(LS_SUPPLIER_KEY) || 'null');
            return (s && s.supplier_name) ? s : null;
        } catch (e) { return null; }
    }
    function clearSupplierStorage() { try { localStorage.removeItem(LS_SUPPLIER_KEY); } catch (e) { } }
    function saveDocItems() { try { localStorage.setItem(LS_DOC_ITEMS_KEY, JSON.stringify(docItems)); } catch (e) { } }
    function loadDocItems() {
        try {
            var arr = JSON.parse(localStorage.getItem(LS_DOC_ITEMS_KEY) || '[]');
            return Array.isArray(arr) ? arr : [];
        } catch (e) { return []; }
    }

    /* =====================================================================
     *  Modal helpers
     * ===================================================================== */
    function openModal(id) { var m = $(id); if (m) m.style.display = 'flex'; }
    function closeModal(id) { var m = $(id); if (m) m.style.display = 'none'; }
    document.addEventListener('click', function (e) {
        var c = e.target.getAttribute && e.target.getAttribute('data-close');
        if (c) closeModal(c);
        if (e.target.classList && e.target.classList.contains('om-modal-mask')) e.target.style.display = 'none';
    });

    /* =====================================================================
     *  KHỐI 1 — Tìm nhà cung cấp (dropdown + phím mũi tên / Tab / Enter)
     * ===================================================================== */
    function renderSupDrop() {
        if (!supList.length) {
            supDrop.innerHTML = '<li class="om-sd-empty">Không tìm thấy nhà cung cấp.</li>';
            supDrop.classList.add('open');
            return;
        }
        var html = '';
        supList.forEach(function (s, i) {
            html += '<li data-i="' + i + '"' + (i === supActive ? ' class="active"' : '') + '>'
                + '<span>' + esc(s.supplier_name) + '</span>'
                + (s.phone_number ? '<small>' + esc(s.phone_number) + '</small>' : '')
                + '</li>';
        });
        supDrop.innerHTML = html;
        supDrop.classList.add('open');
    }
    function closeSupDrop() { supDrop.classList.remove('open'); supActive = -1; }

    var doSupSearch = debounce(function () {
        var kw = supInput.value.trim();
        post('search_suppliers', { keyword: kw }).then(function (res) {
            supList = (res && res.data) || [];
            supActive = supList.length ? 0 : -1;
            renderSupDrop();
        });
    }, 180);

    supInput.addEventListener('input', doSupSearch);
    supInput.addEventListener('focus', function () { if (supInput.value.trim() !== '') doSupSearch(); });

    // Đang tìm sang NCC khác (gõ khác tên NCC đang chọn) -> làm trống danh sách NVL/FAQ của NCC cũ.
    supInput.addEventListener('input', function () {
        if (supplier && supInput.value.trim() !== supplier.supplier_name) resetSupplierSelection();
    });

    // Nút "x" xóa nhanh nội dung tìm kiếm (hiện khi hover ô tìm kiếm và có nội dung).
    supClear.addEventListener('click', function () {
        supInput.value = '';
        closeSupDrop();
        if (supplier) resetSupplierSelection();
        supInput.focus();
    });
    var OM_DOC_SUPPLIER_PLACEHOLDER = '.................................';
    function resetSupplierSelection() {
        supplier = null;
        materials = [];
        matPage = 1;
        btnMin.disabled = true;
        btnSupInfo.disabled = true;
        matTbody.innerHTML = '<tr class="om-mat-empty"><td colspan="4">Chọn một nhà cung cấp để xem danh sách nguyên vật liệu.</td></tr>';
        matPager.style.display = 'none';
        faqBody.innerHTML = '<div class="om-faq-empty">Chọn nhà cung cấp để xem các nguyên vật liệu nên đặt.</div>';
        hiddenBody.innerHTML = '<div class="om-faq-empty">Chọn nhà cung cấp để xem các thành phần đang tạm ẩn.</div>';
        clearSupplierStorage();
        // Đổi NCC -> phiếu đang soạn (KHỐI 2) cũng thuộc về NCC cũ -> reset luôn.
        docItems = [];
        docSupplier.textContent = OM_DOC_SUPPLIER_PLACEHOLDER;
        renderDoc();
    }

    supInput.addEventListener('keydown', function (e) {
        if (!supDrop.classList.contains('open')) {
            if (e.key === 'ArrowDown') { doSupSearch(); }
            return;
        }
        if (e.key === 'ArrowDown') {
            e.preventDefault();
            supActive = Math.min(supActive + 1, supList.length - 1);
            renderSupDrop();
        } else if (e.key === 'ArrowUp') {
            e.preventDefault();
            supActive = Math.max(supActive - 1, 0);
            renderSupDrop();
        } else if (e.key === 'Enter' || e.key === 'Tab') {
            if (supActive >= 0 && supList[supActive]) {
                e.preventDefault();
                selectSupplier(supList[supActive]);
            }
        } else if (e.key === 'Escape') {
            closeSupDrop();
        }
    });

    supDrop.addEventListener('mousedown', function (e) {
        var li = e.target.closest('li[data-i]');
        if (!li) return;
        e.preventDefault();
        var i = parseInt(li.getAttribute('data-i'), 10);
        if (supList[i]) selectSupplier(supList[i]);
    });

    document.addEventListener('click', function (e) {
        if (!e.target.closest('.om-search-wrap')) closeSupDrop();
    });

    function selectSupplier(s) {
        supplier = s;
        supInput.value = s.supplier_name;
        closeSupDrop();
        docSupplier.textContent = s.supplier_name;
        btnMin.disabled = false;
        btnSupInfo.disabled = false;
        saveSupplier(s);
        loadMaterials();
    }

    /* =====================================================================
     *  KHỐI 1 — Danh sách NVL của NCC
     * ===================================================================== */
    function loadMaterials() {
        if (!supplier) return;
        matPage = 1;
        matTbody.innerHTML = '<tr class="om-mat-empty"><td colspan="4">Đang tải...</td></tr>';
        post('supplier_materials', { supplier_id: supplier.id }).then(function (res) {
            materials = (res && res.data) || [];
            renderMaterials();
            renderFaq();
        });
        loadHidden();
    }

    function renderMaterials() {
        matTbody.innerHTML = '';
        if (!materials.length) {
            matTbody.innerHTML = '<tr class="om-mat-empty"><td colspan="4">Nhà cung cấp này chưa có nguyên vật liệu.</td></tr>';
            matPager.style.display = 'none';
            return;
        }

        var total = materials.length;
        var totalPages = Math.max(1, Math.ceil(total / matPageSize));
        if (matPage > totalPages) matPage = totalPages;
        var start = (matPage - 1) * matPageSize;
        var pageRows = materials.slice(start, start + matPageSize);

        pageRows.forEach(function (m) {
            var tr = matRowTpl.content.firstElementChild.cloneNode(true);
            if (m.warn) tr.classList.add('is-warn');
            var link = tr.querySelector('.om-mat-link');
            link.textContent = m.display_name;
            // Click tên NVL: CHỈ mở Phân tích NVL — không đổ qua "Đơn đặt hàng".
            link.addEventListener('click', function () { openAnalysis(m.id); });
            tr.querySelector('.om-mat-unit').textContent = m.unit || '';
            tr.querySelector('.om-mat-stock').textContent = num(m.stock, 2);
            var add = tr.querySelector('.om-add-btn');
            if (isInDoc(m.id)) add.classList.add('is-added');
            // "+": duy nhất cách đổ qua đơn đặt hàng — nhận SL đặt gần nhất (m.last_qty, xem addToDoc()).
            add.addEventListener('click', function () { addToDoc(m); });
            var hide = tr.querySelector('.om-hide-btn');
            hide.addEventListener('click', function () { hideMaterial(m); });
            tr.setAttribute('data-mid', m.id);
            matTbody.appendChild(tr);
        });

        // Cập nhật thanh phân trang.
        matPager.style.display = 'flex';
        matPageInfo.textContent = matPage + ' / ' + totalPages
            + ' (' + total + ' NVL)';
        matPrev.disabled = matPage <= 1;
        matNext.disabled = matPage >= totalPages;
    }

    matPageSizeSel.addEventListener('change', function () {
        matPageSize = parseInt(this.value, 10) || 10;
        matPage = 1;
        renderMaterials();
    });
    matPrev.addEventListener('click', function () {
        if (matPage > 1) { matPage--; renderMaterials(); }
    });
    matNext.addEventListener('click', function () {
        var totalPages = Math.max(1, Math.ceil(materials.length / matPageSize));
        if (matPage < totalPages) { matPage++; renderMaterials(); }
    });

    function renderFaq() {
        var warn = materials.filter(function (m) { return m.warn; });
        if (!warn.length) {
            faqBody.innerHTML = '<div class="om-faq-empty">Không có nguyên vật liệu nào dưới định mức tồn tối thiểu.</div>';
            return;
        }
        var html = '';
        warn.forEach(function (m) {
            html += '<div class="om-faq-item">'
                + '<div class="om-faq-q"><b>' + esc(m.display_name) + '</b> đang ở mức tồn '
                + '<span class="om-faq-stock">' + num(m.stock, 2) + ' ' + esc(m.unit || '') + '</span>'
                + ' (dưới định mức tối thiểu ' + num(m.min_quantity, 2) + ') và đang trong thời gian sử dụng.'
                + (m.lead_days ? ' Dự kiến về sau ' + m.lead_days + ' ngày.' : '')
                + '</div>'
                + '<div class="om-faq-actions">'
                + '<button type="button" class="om-faq-add" data-mid="' + m.id + '">+ Đơn</button>'
                + '<button type="button" class="om-faq-cal" data-mid="' + m.id + '"><i class="fa-solid fa-calendar-plus"></i> Calendar</button>'
                + '</div>'
                + '</div>';
        });
        faqBody.innerHTML = html;
        Array.prototype.forEach.call(faqBody.querySelectorAll('.om-faq-add'), function (b) {
            b.addEventListener('click', function () {
                var mid = parseInt(b.getAttribute('data-mid'), 10);
                var m = materials.filter(function (x) { return x.id === mid; })[0];
                if (m) addToDoc(m);
            });
        });
        Array.prototype.forEach.call(faqBody.querySelectorAll('.om-faq-cal'), function (b) {
            b.addEventListener('click', function (e) {
                e.stopPropagation();
                var mid = parseInt(b.getAttribute('data-mid'), 10);
                var m = materials.filter(function (x) { return x.id === mid; })[0];
                if (m) openCalMenu(b, m);
            });
        });
    }

    /* =====================================================================
     *  KHỐI 1 — "Tạm ẩn" NVL khỏi danh sách của NCC đang chọn (+ "Thành phần
     *  tạm ẩn" để mở lại) — luôn theo CẶP (material_id, supplier_id).
     * ===================================================================== */
    var hiddenBody = $('om-hidden-body');

    function hideMaterial(m) {
        if (!supplier) return;
        post('hide_material', { material_id: m.id, supplier_id: supplier.id }).then(function (res) {
            if (!res || !res.success) return;
            materials = materials.filter(function (x) { return x.id !== m.id; });
            renderMaterials();
            renderFaq();
            loadHidden();
        });
    }

    function loadHidden() {
        if (!supplier) {
            hiddenBody.innerHTML = '<div class="om-faq-empty">Chọn nhà cung cấp để xem các thành phần đang tạm ẩn.</div>';
            return;
        }
        post('hidden_materials', { supplier_id: supplier.id }).then(function (res) {
            renderHidden((res && res.data) || []);
        });
    }

    function renderHidden(list) {
        if (!list.length) {
            hiddenBody.innerHTML = '<div class="om-faq-empty">Chưa ẩn thành phần nào của nhà cung cấp này.</div>';
            return;
        }
        var html = '';
        list.forEach(function (m) {
            html += '<div class="om-hidden-item">'
                + '<span class="om-hidden-name">' + esc(m.display_name) + '</span>'
                + '<button type="button" class="om-hidden-restore" data-mid="' + m.id + '">'
                + '<i class="fa-solid fa-eye"></i> Mở lại</button>'
                + '</div>';
        });
        hiddenBody.innerHTML = html;
        Array.prototype.forEach.call(hiddenBody.querySelectorAll('.om-hidden-restore'), function (b) {
            b.addEventListener('click', function () {
                if (!supplier) return;
                var mid = parseInt(b.getAttribute('data-mid'), 10);
                post('unhide_material', { material_id: mid, supplier_id: supplier.id }).then(function (res) {
                    if (!res || !res.success) return;
                    loadMaterials(); // tải lại danh sách + FAQ + "Thành phần tạm ẩn" (loadHidden() bên trong)
                });
            });
        });
    }

    /* =====================================================================
     *  KHỐI 1 — "+ Calendar": nhắc lịch cá nhân "Order {tên thường gọi}"
     * ===================================================================== */
    var calMenu = $('om-cal-menu');
    var calTargetMaterial = null;
    var calTargetBtn = null;
    var CAL_OPTIONS = [
        ['Ngày mai', 1, 'day'],
        ['3 Ngày sau', 3, 'day'],
        ['Tuần sau', 7, 'day'],
        ['2 tuần sau', 14, 'day'],
        ['Tháng sau', 1, 'month']
    ];
    if (calMenu) {
        calMenu.innerHTML = CAL_OPTIONS.map(function (o, i) {
            return '<li data-i="' + i + '">' + esc(o[0]) + '</li>';
        }).join('');
    }
    function openCalMenu(btn, m) {
        if (!calMenu) return;
        calTargetMaterial = m;
        calTargetBtn = btn;
        // Đo trước (ẩn bằng visibility, không phải display) để biết chiều cao menu
        // -> quyết định mở xuống dưới hay lật lên trên nếu sát mép dưới màn hình.
        calMenu.style.visibility = 'hidden';
        calMenu.style.display = 'block';
        var rect = btn.getBoundingClientRect();
        var menuH = calMenu.offsetHeight;
        var top = (window.innerHeight - rect.bottom < menuH + 10)
            ? (rect.top + window.scrollY - menuH - 4)   // không đủ chỗ dưới -> lật lên trên nút
            : (rect.bottom + window.scrollY + 4);
        calMenu.style.top = top + 'px';
        calMenu.style.left = (rect.left + window.scrollX) + 'px';
        calMenu.style.visibility = 'visible';
    }
    function closeCalMenu() { if (calMenu) calMenu.style.display = 'none'; calTargetMaterial = null; }
    if (calMenu) {
        calMenu.addEventListener('click', function (e) {
            var li = e.target.closest('li[data-i]');
            if (!li || !calTargetMaterial) return;
            var opt = CAL_OPTIONS[parseInt(li.getAttribute('data-i'), 10)];
            scheduleOrderReminder(calTargetMaterial, opt[1], opt[2], calTargetBtn);
            closeCalMenu();
        });
    }
    document.addEventListener('click', function (e) {
        if (calMenu && calMenu.style.display === 'block'
            && !e.target.closest('#om-cal-menu') && !e.target.closest('.om-faq-cal')) closeCalMenu();
    });

    function scheduleOrderReminder(m, amount, unit, fromBtn) {
        var target = new Date();
        if (unit === 'month') target.setMonth(target.getMonth() + amount);
        else target.setDate(target.getDate() + amount);
        var pad2 = function (n) { return ('0' + n).slice(-2); };
        var dateStr = target.getFullYear() + '-' + pad2(target.getMonth() + 1) + '-' + pad2(target.getDate());
        var now = new Date();
        var timeStr = pad2(now.getHours()) + ':' + pad2(now.getMinutes());
        var content = 'Order ' + (m.common_name || m.display_name);

        // Nhận diện trùng: nếu ngày đó đã có lời nhắc cùng nội dung -> không thêm nữa.
        postHome('evcalDay', { date: dateStr }).then(function (res) {
            var existed = (res && res.data) || [];
            var dup = existed.some(function (ev) {
                return String(ev.content || '').trim().toLowerCase() === content.trim().toLowerCase();
            });
            if (dup) { alert('Lời nhắc "' + content + '" đã có trong ngày ' + dateStr + ' — không thêm trùng.'); return; }

            postHome('evcalCreate', { date: dateStr, time: timeStr, content: content }).then(function (res2) {
                if (!res2 || !res2.ok) { alert('Không thêm được lời nhắc lịch.'); return; }
                flyToHeaderCalendar(fromBtn);
            });
        });
    }
    function postHome(action, data) {
        var body = new URLSearchParams();
        for (var k in data) body.append(k, data[k]);
        return fetch('?mod=home&controllers=index&action=' + action, {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8' },
            body: body.toString()
        }).then(function (r) { return r.json(); });
    }

    /* Hiệu ứng icon bay từ nút "+ Calendar" vào nút Lịch trên header + chớp sáng báo hiệu. */
    function flyToHeaderCalendar(fromBtn) {
        var toEl = document.getElementById('app-cal-btn');
        if (!fromBtn || !toEl) return;
        var a = fromBtn.getBoundingClientRect(), b = toEl.getBoundingClientRect();
        var fly = document.createElement('div');
        fly.className = 'om-fly-icon om-fly-icon-cal';
        fly.innerHTML = '<i class="fa-solid fa-calendar-days"></i>';
        fly.style.left = (a.left + a.width / 2) + 'px';
        fly.style.top = (a.top + a.height / 2) + 'px';
        document.body.appendChild(fly);
        fly.getBoundingClientRect(); // reflow
        var dx = (b.left + b.width / 2) - (a.left + a.width / 2);
        var dy = (b.top + b.height / 2) - (a.top + a.height / 2);
        fly.style.transform = 'translate(' + dx + 'px,' + dy + 'px) scale(.3)';
        fly.style.opacity = '0.15';
        setTimeout(function () {
            fly.remove();
            toEl.classList.add('om-cal-flash');
            setTimeout(function () { toEl.classList.remove('om-cal-flash'); }, 900);
        }, 720);
    }

    /* =====================================================================
     *  KHỐI 1 — Modal phân tích NVL
     * ===================================================================== */
    function openAnalysis(mid) {
        post('material_analysis', { material_id: mid }).then(function (res) {
            if (!res || !res.success) { alert((res && res.message) || 'Không tải được phân tích.'); return; }
            analysis = res.data;
            renderAnalysis(analysis);
            openModal('om-modal-analysis');
        });
    }

    function renderAnalysis(d) {
        $('om-an-name').textContent = d.display_name;
        var u = d.unit || '';
        var imp = d.last_import
            ? (formatDate(d.last_import.date) + ' — ' + num(d.last_import.qty, 2) + ' ' + u)
            : 'Chưa có';
        $('om-an-summary').innerHTML =
            card('Tồn hiện tại', num(d.stock, 2) + ' ' + u, 'stock')
            + card('ĐM dùng 1 tháng', num(d.use_1m, 2) + ' ' + u, 'use')
            + card('ĐM dùng 3 tháng', num(d.use_3m, 2) + ' ' + u, 'use')
            + card('ĐM dùng 6 tháng', num(d.use_6m, 2) + ' ' + u, 'use')
            + card('Lần nhập kho gần đây', imp, 'import');

        // Sắp xếp theo xuất 6 tháng: nhiều nhất xếp đầu tiên.
        var prods = (d.products || []).slice().sort(function (a, b) {
            return (b.sale_6m || 0) - (a.sale_6m || 0);
        });

        var ph = '';
        if (!prods.length) {
            ph = '<div class="om-faq-empty">Chưa có sản phẩm nào dùng nguyên vật liệu này.</div>';
        } else {
            prods.forEach(function (p) {
                // SL sản xuất tối đa = tồn NVL hiện tại / định mức 1 SP (làm tròn xuống — không thể
                // sản xuất phần lẻ). Bỏ qua khi định mức = 0 (tránh chia cho 0 / vô nghĩa).
                var norm = Number(p.norm) || 0;
                var maxProduce = norm > 0 ? Math.floor((Number(d.stock) || 0) / norm) : null;
                var maxHtml = maxProduce !== null
                    ? ' - Sản xuất tối đa: <span class="om-an-prod-max">' + num(maxProduce, 0) + ' ' + esc(p.unit || '') + '</span>'
                    : '';
                ph += '<div class="om-an-prod">'
                    + '<div class="om-an-prod-name">' + esc(p.name) + maxHtml + '</div>'
                    + '<div class="om-an-prod-grid">'
                    + cell('ĐM', num(p.norm, 3) + ' ' + u)
                    + cell('Thành phẩm', num(p.produced, 0) + ' ' + esc(p.unit || ''))
                    + cell('Tồn hiện tại', num(p.stock, 0) + ' ' + esc(p.unit || ''))
                    + cell('Xuất 1 tháng', num(p.sale_1m, 0), 'out')
                    + cell('Xuất 3 tháng', num(p.sale_3m, 0), 'out')
                    + cell('Xuất 6 tháng', num(p.sale_6m, 0), 'out')
                    + '</div></div>';
            });
        }
        $('om-an-products').innerHTML = ph;
    }
    function card(label, val, mod) {
        return '<div class="om-an-card' + (mod ? ' om-an-card--' + mod : '') + '">'
            + '<div class="om-an-label">' + esc(label) + '</div>'
            + '<div class="om-an-value">' + val + '</div></div>';
    }
    function cell(label, val, mod) {
        return '<div' + (mod ? ' class="cell--' + mod + '"' : '') + '>'
            + '<span>' + esc(label) + '</span><b>' + val + '</b></div>';
    }

    /* Kế hoạch: nhập SL thành phẩm -> tổng định mức cần */
    $('om-btn-open-plan').addEventListener('click', function () {
        if (!analysis) return;
        $('om-plan-mat-name').textContent = analysis.display_name;
        $('om-plan-unit').textContent = analysis.unit || '';
        var html = '';
        analysis.products.forEach(function (p, i) {
            html += '<tr data-norm="' + p.bom_norm + '">'
                + '<td>' + esc(p.name) + '</td>'
                + '<td class="om-pl-norm">' + num(p.bom_norm, 3) + '</td>'
                + '<td class="om-pl-qty"><input type="text" class="om-plan-q" inputmode="decimal" value="0"></td>'
                + '<td class="om-pl-need" data-need="' + i + '">0</td>'
                + '</tr>';
        });
        $('om-plan-tbody').innerHTML = html;
        recalcPlan();
        openModal('om-modal-plan');
    });

    $('om-plan-tbody').addEventListener('input', function (e) {
        if (e.target.classList.contains('om-plan-q')) recalcPlan();
    });
    function recalcPlan() {
        var total = 0;
        Array.prototype.forEach.call($('om-plan-tbody').querySelectorAll('tr'), function (tr) {
            var norm = parseFloat(tr.getAttribute('data-norm')) || 0;
            var q = parseNum(tr.querySelector('.om-plan-q').value);
            var need = norm * q;
            total += need;
            tr.querySelector('.om-pl-need').textContent = num(need, 3);
        });
        $('om-plan-total').textContent = num(total, 3);
    }

    /* =====================================================================
     *  KHỐI 1 — Thiết lập tồn tối thiểu
     * ===================================================================== */
    var WIN_OPTS = [
        ['1m', 'Dưới 1 tháng'], ['3m', 'Dưới 3 tháng'], ['6m', 'Dưới 6 tháng'],
        ['1y', 'Dưới 1 năm'], ['none', 'Không xét thời gian']
    ];
    btnMin.addEventListener('click', function () {
        if (!materials.length) { alert('Nhà cung cấp này chưa có nguyên vật liệu.'); return; }
        var html = '';
        materials.forEach(function (m) {
            var opts = WIN_OPTS.map(function (o) {
                return '<option value="' + o[0] + '"' + (m.usage_window === o[0] ? ' selected' : '') + '>' + o[1] + '</option>';
            }).join('');
            html += '<tr data-mid="' + m.id + '">'
                + '<td>' + esc(m.display_name) + '</td>'
                + '<td class="om-mn-min"><input type="text" class="om-mn-min-in" inputmode="decimal" value="' + (m.has_setting ? num(m.min_quantity, 3) : '') + '"></td>'
                + '<td class="om-mn-lead"><input type="text" class="om-mn-lead-in" inputmode="numeric" value="' + (m.has_setting ? m.lead_days : '') + '"></td>'
                + '<td class="om-mn-win"><select class="om-mn-win-in">' + opts + '</select></td>'
                + '</tr>';
        });
        $('om-min-tbody').innerHTML = html;
        openModal('om-modal-min');
    });

    $('om-btn-save-min').addEventListener('click', function () {
        var items = [];
        Array.prototype.forEach.call($('om-min-tbody').querySelectorAll('tr'), function (tr) {
            var minRaw = tr.querySelector('.om-mn-min-in').value.trim();
            if (minRaw === '') return; // bỏ trống = không thiết lập
            items.push({
                material_id: parseInt(tr.getAttribute('data-mid'), 10),
                min_quantity: parseNum(minRaw),
                lead_days: parseInt(tr.querySelector('.om-mn-lead-in').value, 10) || 0,
                usage_window: tr.querySelector('.om-mn-win-in').value
            });
        });
        var btn = this; btn.disabled = true;
        post('save_min_settings_bulk', { items: JSON.stringify(items) }).then(function (res) {
            btn.disabled = false;
            if (res && res.success) { closeModal('om-modal-min'); loadMaterials(); }
            else alert('Lưu thiết lập thất bại.');
        });
    });

    /* =====================================================================
     *  KHỐI 1 — Thông tin nhà cung cấp
     * ===================================================================== */
    var SUP_FIELDS = [
        ['supplier_name', 'Tên NCC'], ['phone_number', 'Điện thoại'],
        ['email', 'Email'], ['website', 'Website'], ['address', 'Địa chỉ']
    ];
    btnSupInfo.addEventListener('click', function () {
        if (!supplier) return;
        post('supplier_info', { supplier_id: supplier.id }).then(function (res) {
            var d = (res && res.data) || {};
            var html = SUP_FIELDS.map(function (f) {
                return '<div class="om-sup-field">'
                    + '<label>' + esc(f[1]) + '</label>'
                    + '<input type="text" data-field="' + f[0] + '" value="' + esc(d[f[0]] || '') + '">'
                    + '</div>';
            }).join('');
            html += '<div class="om-sup-hint">Sửa trực tiếp — rời khỏi ô là tự lưu vào hệ thống.</div>';
            $('om-sup-info').innerHTML = html;
            openModal('om-modal-supplier');
        });
    });

    // Sửa thông tin NCC -> lưu DB ngay khi rời ô (change/blur).
    $('om-sup-info').addEventListener('change', function (e) {
        var inp = e.target.closest('input[data-field]');
        if (!inp || !supplier) return;
        var data = { supplier_id: supplier.id };
        data[inp.getAttribute('data-field')] = inp.value;
        post('update_supplier', data).then(function (res) {
            if (res && res.success) {
                inp.classList.add('is-saved');
                setTimeout(function () { inp.classList.remove('is-saved'); }, 1200);
                // Đồng bộ tên NCC hiển thị nếu sửa supplier_name.
                if (inp.getAttribute('data-field') === 'supplier_name') {
                    supplier.supplier_name = inp.value;
                    docSupplier.textContent = inp.value;
                    supInput.value = inp.value;
                    saveSupplier(supplier);
                }
            }
        });
    });

    /* =====================================================================
     *  KHỐI 2 — Phiếu đặt hàng (thêm/xóa/SL/STT)
     * ===================================================================== */
    function isInDoc(mid) { return docItems.some(function (it) { return it.material_id === mid; }); }

    function addToDoc(m) {
        var existing = docItems.filter(function (it) { return it.material_id === m.id; })[0];
        if (existing) {
            // Đã có sẵn trong đơn (vd thêm từ trước rồi chưa nhập SL) — SL đang bỏ trống/0
            // thì tự gán SL đặt gần nhất luôn, thay vì im lặng không làm gì.
            if (!existing.qty && m.last_qty > 0) {
                existing.qty = m.last_qty;
                renderDoc();
            }
            flashDocRow(m.id);
            return;
        }
        docItems.push({
            material_id: m.id,
            name: m.display_name,
            unit: m.unit || '',
            origUnit: m.unit || '',                // đơn vị gốc (không đổi)
            qty: m.last_qty > 0 ? m.last_qty : 0   // SL theo đơn gần nhất
        });
        renderDoc();
        markAdded();
        flashDocRow(m.id);
    }
    // Cuộn tới + chớp sáng dòng vừa thêm/đã có trong phiếu, để luôn có phản hồi khi bấm "+".
    function flashDocRow(mid) {
        setTimeout(function () {
            var tr = docTbody.querySelector('tr[data-mid="' + mid + '"]');
            if (!tr) return;
            tr.scrollIntoView({ block: 'nearest', behavior: 'smooth' });
            tr.classList.remove('om-btn-flash'); void tr.offsetWidth;
            tr.classList.add('om-btn-flash');
            setTimeout(function () { tr.classList.remove('om-btn-flash'); }, 650);
        }, 30);
    }
    function removeFromDoc(mid) {
        docItems = docItems.filter(function (it) { return it.material_id !== mid; });
        renderDoc();
        markAdded();
    }
    function markAdded() {
        Array.prototype.forEach.call(matTbody.querySelectorAll('.om-mat-row'), function (tr) {
            var mid = parseInt(tr.getAttribute('data-mid'), 10);
            tr.querySelector('.om-add-btn').classList.toggle('is-added', isInDoc(mid));
        });
    }

    var OM_MIN_ROWS = 4;   // tối thiểu 4 dòng trên phiếu
    function renderDoc() {
        docTbody.innerHTML = '';
        docItems.forEach(function (it, i) {
            var tr = docRowTpl.content.firstElementChild.cloneNode(true);
            tr.setAttribute('data-mid', it.material_id);
            tr.querySelector('.om-dc-stt').innerHTML = '<span class="om-stt-badge">' + (i + 1) + '</span>';
            tr.querySelector('.om-dc-name').textContent = it.name;
            tr.querySelector('.om-dc-unit').textContent = it.unit;
            var inp = tr.querySelector('.om-qty-input');
            inp.value = it.qty ? num(it.qty, 3) : '';
            inp.addEventListener('input', function () { it.qty = parseNum(inp.value); saveDocItems(); });
            tr.querySelector('.om-qty-convert').addEventListener('click', function () { openConvert(it); });
            tr.querySelector('.om-doc-del').addEventListener('click', function () { removeFromDoc(it.material_id); });
            docTbody.appendChild(tr);
        });
        // Bù dòng trống để luôn có tối thiểu 6 dòng; STT của dòng trống được làm mờ.
        for (var k = docItems.length; k < OM_MIN_ROWS; k++) {
            var er = document.createElement('tr');
            er.className = 'om-doc-row is-empty-row';
            er.innerHTML = '<td class="om-dc-stt"><span class="om-stt-badge is-empty">' + (k + 1) + '</span></td>'
                + '<td class="om-dc-name"></td><td class="om-dc-unit"></td>'
                + '<td class="om-dc-qty"></td><td class="om-dc-act"></td>';
            docTbody.appendChild(er);
        }
        saveDocItems();
    }

    /* Quy đổi đơn vị (chỉ cho đơn hàng, không đổi gốc trong DB) */
    function openConvert(it) {
        convertItem = it;
        $('om-convert-name').innerHTML = esc(it.name)
            + ' — <small>Đơn vị gốc: ' + esc(it.origUnit || it.unit || '—') + '</small>';
        $('om-convert-unit').value = it.unit || '';
        $('om-convert-qty').value = it.qty ? num(it.qty, 3) : '';
        openModal('om-modal-convert');
        setTimeout(function () { $('om-convert-unit').focus(); $('om-convert-unit').select(); }, 30);
    }
    $('om-convert-apply').addEventListener('click', function () {
        if (!convertItem) return;
        convertItem.unit = $('om-convert-unit').value.trim();
        convertItem.qty = parseNum($('om-convert-qty').value);
        convertItem = null;
        closeModal('om-modal-convert');
        renderDoc();
    });
    ['om-convert-unit', 'om-convert-qty'].forEach(function (id) {
        $(id).addEventListener('keydown', function (e) {
            if (e.key === 'Enter') { e.preventDefault(); $('om-convert-apply').click(); }
        });
    });

    function collectOrder() {
        var items = docItems
            .map(function (it) { return { material_id: it.material_id, name: it.name, unit: it.unit, qty: it.qty, orig_unit: it.origUnit }; })
            .filter(function (it) { return it.qty > 0; });
        return {
            supplier_id: supplier ? supplier.id : 0,
            supplier_name: supplier ? supplier.supplier_name : (docSupplier.textContent || ''),
            note: '',
            items: items
        };
    }

    $('om-btn-save-order').addEventListener('click', function () {
        var btn = this;
        if (!supplier) { shakeBtn(btn); return; }
        var payload = collectOrder();
        if (!payload.items.length) { shakeBtn(btn); return; }
        btn.disabled = true;

        // Đang "Sửa đơn" -> CẬP NHẬT đúng đơn đã lưu (không tạo mới, không kiểm tra trùng).
        if (editingOrderId) {
            payload.id = editingOrderId;
            postJSON('update_order', payload).then(function (res) {
                btn.disabled = false;
                if (res && res.success) {
                    CFG.orders = res.orders || [];
                    editingOrderId = 0;
                    updateSaveBtnUI();
                    flashBtn(btn);
                    flyToSaved(btn);
                } else {
                    shakeBtn(btn);
                }
            });
            return;
        }

        postJSON('save_order', payload).then(function (res) {
            btn.disabled = false;
            if (res && res.success) {
                CFG.orders = res.orders || [];
                flashBtn(btn);            // nhấp nháy nút Lưu đơn
                flyToSaved(btn);          // icon bay vào "Đơn đã lưu"
            } else if (res && res.duplicate) {
                shakeBtn(btn);            // đơn trùng -> không lưu
                markDuplicate(btn);
            } else {
                shakeBtn(btn);
            }
        });
    });

    /* ---------- Hiệu ứng phản hồi (không dùng modal) ---------- */
    function shakeBtn(el) {
        el.classList.remove('om-btn-shake'); void el.offsetWidth;
        el.classList.add('om-btn-shake');
        setTimeout(function () { el.classList.remove('om-btn-shake'); }, 450);
    }
    function flashBtn(el) {
        el.classList.remove('om-btn-flash'); void el.offsetWidth;
        el.classList.add('om-btn-flash');
        setTimeout(function () { el.classList.remove('om-btn-flash'); }, 650);
    }
    function markDuplicate(btn) {
        if (btn.getAttribute('data-dup')) return;
        btn.setAttribute('data-dup', '1');
        var orig = btn.innerHTML;
        btn.classList.add('om-btn-dupe');
        btn.innerHTML = '<i class="fa-solid fa-circle-exclamation"></i> Đơn đã tồn tại hôm nay';
        setTimeout(function () {
            btn.innerHTML = orig;
            btn.classList.remove('om-btn-dupe');
            btn.removeAttribute('data-dup');
        }, 1700);
    }
    function flyToSaved(fromEl) {
        var toEl = $('om-btn-saved-orders');
        if (!fromEl || !toEl) return;
        var a = fromEl.getBoundingClientRect(), b = toEl.getBoundingClientRect();
        var fly = document.createElement('div');
        fly.className = 'om-fly-icon';
        fly.innerHTML = '<i class="fa-solid fa-file-invoice"></i>';
        fly.style.left = (a.left + a.width / 2) + 'px';
        fly.style.top = (a.top + a.height / 2) + 'px';
        document.body.appendChild(fly);
        fly.getBoundingClientRect(); // reflow
        var dx = (b.left + b.width / 2) - (a.left + a.width / 2);
        var dy = (b.top + b.height / 2) - (a.top + a.height / 2);
        fly.style.transform = 'translate(' + dx + 'px,' + dy + 'px) scale(.3)';
        fly.style.opacity = '0.15';
        setTimeout(function () {
            fly.remove();
            toEl.classList.add('om-pulse');
            setTimeout(function () { toEl.classList.remove('om-pulse'); }, 600);
        }, 720);
    }

    $('om-btn-clear-order').addEventListener('click', function () {
        if (!docItems.length) return;
        if (!confirm('Xóa toàn bộ nội dung đơn hiện tại?')) return;
        docItems = [];
        editingOrderId = 0;
        updateSaveBtnUI();
        renderDoc();
        markAdded();
    });

    /* =====================================================================
     *  KHỐI 2 — Chụp & chia sẻ (html2canvas -> clipboard)
     * ===================================================================== */
    $('om-btn-share-order').addEventListener('click', function () {
        if (!docItems.length) { alert('Chưa có mặt hàng để chia sẻ.'); return; }
        var btn = this;
        var doShare = function () { captureAndShare($('om-doc-sheet'), btn, 'don-dat-hang-nvl.png'); };
        var payload = collectOrder();
        // Đang "Sửa đơn" -> phiếu này vốn đã là 1 đơn đã lưu (đang chỉnh sửa), khỏi hỏi lưu lại.
        if (editingOrderId || !supplier || !payload.items.length) { doShare(); return; }
        // Xét đơn hiện tại đã có trong "Đơn đã lưu" chưa (cùng NCC + cùng tập mặt hàng, trong ngày).
        postJSON('check_order_saved', payload).then(function (res) {
            if (res && res.exists) { doShare(); return; }
            if (confirm('Đơn hàng này chưa được lưu. Bạn có muốn lưu đơn hàng này không?')) {
                postJSON('save_order', payload).then(function (res2) {
                    if (res2 && res2.success) CFG.orders = res2.orders || CFG.orders;
                    doShare();
                }).catch(doShare);
            } else {
                doShare();
            }
        }).catch(doShare); // lỗi mạng -> vẫn cho chụp, không chặn thao tác chính
    });

    /** Chụp 1 vùng "phiếu" bằng html2canvas rồi copy ảnh vào clipboard (dùng chung cho phiếu đặt hàng + báo cáo). */
    function captureAndShare(sheet, btn, filename) {
        if (typeof window.html2canvas !== 'function') { alert('Không nạp được html2canvas.'); return; }
        var orig = btn.innerHTML;
        btn.innerHTML = 'Đang xử lý...'; btn.disabled = true;
        sheet.classList.add('is-capturing');   // ẩn nút xóa khỏi ảnh

        var SCALE = 2;
        // backgroundColor:null -> nền trong suốt quanh góc bo, để tự vẽ bóng đổ theo hình đơn.
        window.html2canvas(sheet, { scale: SCALE, backgroundColor: null, useCORS: true }).then(function (docCanvas) {
            sheet.classList.remove('is-capturing');

            // html2canvas KHÔNG render box-shadow -> tự vẽ lại bóng đổ bằng Canvas API,
            // chừa lề xung quanh để bóng không bị lẹm (giống box-shadow -1px -1px 12px #696f79).
            var M = Math.round(20 * SCALE);                 // lề mỗi cạnh (device px)
            var out = document.createElement('canvas');
            out.width  = docCanvas.width  + M * 2;
            out.height = docCanvas.height + M * 2;
            var ctx = out.getContext('2d');
            ctx.fillStyle = '#ffffff';                       // nền trắng để thấy bóng
            ctx.fillRect(0, 0, out.width, out.height);
            ctx.shadowColor   = 'rgba(105, 111, 121, 0.6)';  // #696f79
            ctx.shadowBlur    = 12 * SCALE;
            ctx.shadowOffsetX = -1 * SCALE;
            ctx.shadowOffsetY = -1 * SCALE;
            ctx.drawImage(docCanvas, M, M);                  // vẽ đơn + bóng đổ của nó
            var canvas = out;

            canvas.toBlob(function (blob) {
                function restore() { btn.innerHTML = orig; btn.disabled = false; }
                if (navigator.clipboard && window.ClipboardItem) {
                    navigator.clipboard.write([new ClipboardItem({ 'image/png': blob })]).then(function () {
                        alert('Đã copy ảnh vào clipboard.\nMở app khác (Zalo, Messenger...) và bấm Ctrl+V để dán.');
                        restore();
                    }).catch(function () { downloadCanvas(canvas, filename); restore(); });
                } else { downloadCanvas(canvas, filename); restore(); }
            }, 'image/png');
        }).catch(function (err) { sheet.classList.remove('is-capturing'); alert('Lỗi tạo ảnh: ' + err.message); btn.innerHTML = orig; btn.disabled = false; });
    }
    function downloadCanvas(canvas, filename) {
        var a = document.createElement('a');
        a.href = canvas.toDataURL('image/png');
        a.download = filename || 'anh-chup.png';
        a.click();
    }

    /* =====================================================================
     *  KHỐI 1 — Xuất báo cáo định mức dùng NVL (từ modal phân tích)
     * ===================================================================== */
    $('om-btn-open-report').addEventListener('click', function () {
        if (!analysis) return;
        // Mượn nguyên cụm logo + cụm liên hệ đang hiển thị trên phiếu đặt hàng (đã phản ánh mọi chỉnh sửa hover-to-edit).
        var headerEl = document.querySelector('#om-doc-sheet .om-doc-header');
        var reportHeader = $('om-report-header');
        reportHeader.innerHTML = '';
        if (headerEl) {
            var clone = headerEl.cloneNode(true);
            Array.prototype.forEach.call(clone.querySelectorAll('.om-edit-btn'), function (b) { b.remove(); });
            reportHeader.appendChild(clone);
        }
        $('om-report-mat-name').textContent = analysis.display_name;
        $('om-report-supplier').textContent = supplier ? supplier.supplier_name : '—';
        $('om-report-summary').innerHTML = $('om-an-summary').innerHTML;
        $('om-report-products').innerHTML = $('om-an-products').innerHTML;
        $('om-report-date').textContent = vnToday();
        openModal('om-modal-report');
    });
    $('om-btn-share-report').addEventListener('click', function () {
        captureAndShare($('om-report-sheet'), this, 'bc-dinh-muc-nvl.png');
    });
    function vnToday() {
        var d = new Date();
        return 'Ngày ' + d.getDate() + ' tháng ' + ('0' + (d.getMonth() + 1)).slice(-2) + ' năm ' + d.getFullYear();
    }

    /* =====================================================================
     *  KHỐI 2 — Đơn đã lưu (đã nhận / xóa / đặt lại)
     * ===================================================================== */
    $('om-btn-saved-orders').addEventListener('click', function () {
        renderOrders(CFG.orders || []);
        openModal('om-modal-orders');
        post('list_orders', {}).then(function (res) {
            if (res && res.success) { CFG.orders = res.data; renderOrders(res.data); }
        });
    });

    function renderOrders(list) {
        var box = $('om-orders-list');
        if (!list.length) { box.innerHTML = '<div class="om-faq-empty">Chưa có đơn nào được lưu.</div>'; return; }
        var html = '';
        list.forEach(function (o) {
            var names = (o.order_items || []).map(function (it) {
                return esc(it.name) + ' (' + num(it.qty, 2) + ' ' + esc(it.unit || '') + ')';
            }).join(', ');
            html += '<div class="om-order-card' + (o.received ? ' is-received' : '') + '" data-id="' + o.id + '">'
                + '<div class="om-order-card-head">'
                + '<span class="om-oc-sup">' + esc(o.supplier_name || '—') + '</span>'
                + '<span class="om-oc-badge">' + (o.received ? 'Đã nhận' : 'Chờ nhận') + '</span>'
                + '</div>'
                + '<div class="om-oc-date">' + formatDate(o.created_at) + ' · ' + o.item_count + ' mặt hàng - ' + money(o.total_value) + '</div>'
                + '<div class="om-oc-items">' + names + '</div>'
                + '<div class="om-oc-actions">'
                + '<button class="om-oc-reorder" data-id="' + o.id + '"><i class="fa-solid fa-rotate-left"></i> Đặt lại</button>'
                + '<button class="om-oc-recv" data-id="' + o.id + '" data-rc="' + (o.received ? 0 : 1) + '"><i class="fa-solid fa-check"></i> ' + (o.received ? 'Bỏ đã nhận' : 'Đã nhận đơn') + '</button>'
                + '<button class="om-oc-edit" data-id="' + o.id + '"><i class="fa-solid fa-pen"></i> Sửa đơn</button>'
                + '<button class="om-oc-del" data-id="' + o.id + '"><i class="fa-solid fa-trash-can"></i> Xóa</button>'
                + '</div></div>';
        });
        box.innerHTML = html;
    }

    $('om-orders-list').addEventListener('click', function (e) {
        var btn = e.target.closest('button'); if (!btn) return;
        var id = parseInt(btn.getAttribute('data-id'), 10);
        if (btn.classList.contains('om-oc-recv')) {
            post('set_received', { id: id, received: btn.getAttribute('data-rc') }).then(function (res) {
                if (res && res.success) { CFG.orders = res.orders; renderOrders(res.orders); }
            });
        } else if (btn.classList.contains('om-oc-del')) {
            if (!confirm('Xóa đơn này?')) return;
            post('delete_order', { id: id }).then(function (res) {
                if (res && res.success) { CFG.orders = res.orders; renderOrders(res.orders); }
            });
        } else if (btn.classList.contains('om-oc-reorder')) {
            post('order_detail', { id: id }).then(function (res) {
                if (!res || !res.success) { alert('Không tải được đơn.'); return; }
                editingOrderId = 0; // "Đặt lại" luôn tạo đơn MỚI khi lưu, khác với "Sửa đơn"
                updateSaveBtnUI();
                loadOrderIntoDoc(res.data);
                closeModal('om-modal-orders');
            });
        } else if (btn.classList.contains('om-oc-edit')) {
            post('order_detail', { id: id }).then(function (res) {
                if (!res || !res.success) { alert('Không tải được đơn.'); return; }
                editingOrderId = id; // Lưu đơn tiếp theo sẽ CẬP NHẬT đúng đơn này, không tạo mới
                updateSaveBtnUI();
                loadOrderIntoDoc(res.data);
                closeModal('om-modal-orders');
            });
        }
    });

    function loadOrderIntoDoc(o) {
        // Đặt lại: nạp NCC + danh sách mặt hàng vào phiếu hiện tại (lưu thành đơn MỚI).
        supplier = { id: o.supplier_id, supplier_name: o.supplier_name };
        supInput.value = o.supplier_name || '';
        docSupplier.textContent = o.supplier_name || '';
        btnMin.disabled = false; btnSupInfo.disabled = false;
        saveSupplier(supplier);
        docItems = (o.order_items || []).map(function (it) {
            return {
                material_id: it.material_id, name: it.name, unit: it.unit,
                origUnit: it.orig_unit || it.unit, qty: parseFloat(it.qty) || 0
            };
        });
        renderDoc();
        if (o.supplier_id) loadMaterials();
    }

    /* =====================================================================
     *  Hover-to-edit (tiêu đề công ty + tên người ký) -> app_settings
     * ===================================================================== */
    function startEdit(el) {
        if (el.classList.contains('is-editing')) return;
        var key = el.getAttribute('data-key');
        var multiline = el.getAttribute('data-multiline') === '1';
        var textEl = el.querySelector('.om-etext');
        var btn = el.querySelector('.om-edit-btn');
        var cur = textEl.textContent;
        var inp = document.createElement(multiline ? 'textarea' : 'input');
        if (!multiline) inp.type = 'text';
        inp.className = 'om-edit-input' + (multiline ? ' om-edit-area' : '');
        inp.value = cur;
        if (multiline) { inp.rows = Math.max(2, cur.split('\n').length); }
        el.classList.add('is-editing');
        textEl.style.display = 'none';
        if (btn) btn.style.display = 'none';
        el.insertBefore(inp, btn || null);
        inp.focus();
        if (inp.select) inp.select();
        var done = false;
        function finish(commit) {
            if (done) return; done = true;
            var val = multiline ? inp.value.replace(/\s+$/, '') : inp.value.trim();
            if (commit && val !== cur) {
                textEl.textContent = val;
                post('save_setting', { key: key, value: val });
            }
            inp.remove();
            textEl.style.display = '';
            if (btn) btn.style.display = '';
            el.classList.remove('is-editing');
        }
        inp.addEventListener('keydown', function (ev) {
            if (ev.key === 'Enter' && multiline && ev.altKey) {
                // Alt+Enter -> chèn xuống dòng (giữ trong textarea, hiển thị xuống dòng trên view).
                ev.preventDefault();
                var s = inp.selectionStart, e = inp.selectionEnd;
                inp.value = inp.value.slice(0, s) + '\n' + inp.value.slice(e);
                inp.selectionStart = inp.selectionEnd = s + 1;
            } else if (ev.key === 'Enter' && !ev.shiftKey) {
                ev.preventDefault(); inp.blur();
            } else if (ev.key === 'Escape') {
                done = true; inp.remove(); textEl.style.display = ''; if (btn) btn.style.display = ''; el.classList.remove('is-editing');
            }
        });
        inp.addEventListener('blur', function () { finish(true); });
    }
    Array.prototype.forEach.call(document.querySelectorAll('.om-editable'), function (el) {
        var btn = el.querySelector('.om-edit-btn');
        if (btn) btn.addEventListener('click', function (e) { e.preventDefault(); e.stopPropagation(); startEdit(el); });
    });

    /* ---------- Date format ---------- */
    function formatDate(s) {
        if (!s) return '';
        var m = String(s).match(/(\d{4})-(\d{2})-(\d{2})[ T]?(\d{2})?:?(\d{2})?/);
        if (!m) return s;
        var d = m[3] + '/' + m[2] + '/' + m[1];
        if (m[4]) d += ' ' + m[4] + ':' + (m[5] || '00');
        return d;
    }

    /* =====================================================================
     *  Chữ ký động: render theo cấu hình + cài đặt chức danh + kéo-thả
     * ===================================================================== */
    var signRoles = Array.isArray(CFG.signRoles) ? CFG.signRoles.slice() : ['Giám đốc', 'Kế toán trưởng', 'Thủ kho', 'Người lên đơn'];
    var signs = Array.isArray(CFG.signs)
        ? CFG.signs.map(function (s) { return { role: String(s.role || ''), name: String(s.name || '') }; })
        : [];
    var signsBox = $('om-doc-signs');

    function saveSigns() { post('save_setting', { key: 'order_material.signs', value: JSON.stringify(signs) }); }
    function saveRoles() { post('save_setting', { key: 'order_material.sign_roles', value: JSON.stringify(signRoles) }); }

    var dragFrom = null;
    function bindSignDrag(div) {
        div.addEventListener('dragstart', function () { dragFrom = parseInt(div.getAttribute('data-idx'), 10); div.classList.add('dragging'); });
        div.addEventListener('dragend', function () {
            div.classList.remove('dragging');
            Array.prototype.forEach.call(signsBox.querySelectorAll('.om-sign'), function (s) { s.classList.remove('drag-over'); });
        });
        div.addEventListener('dragover', function (e) { e.preventDefault(); div.classList.add('drag-over'); });
        div.addEventListener('dragleave', function () { div.classList.remove('drag-over'); });
        div.addEventListener('drop', function (e) {
            e.preventDefault();
            var to = parseInt(div.getAttribute('data-idx'), 10);
            if (dragFrom === null || dragFrom === to) return;
            var tmp = signs[dragFrom]; signs[dragFrom] = signs[to]; signs[to] = tmp;   // đổi vị trí (swap)
            dragFrom = null; renderSigns(); saveSigns();
        });
    }

    function startSignNameEdit(ed, idx) {
        if (ed.classList.contains('is-editing')) return;
        var textEl = ed.querySelector('.om-etext');
        var btn = ed.querySelector('.om-edit-btn');
        var cur = textEl.textContent;
        var inp = document.createElement('input');
        inp.type = 'text'; inp.className = 'om-edit-input'; inp.value = cur;
        ed.classList.add('is-editing'); textEl.style.display = 'none'; if (btn) btn.style.display = 'none';
        ed.insertBefore(inp, btn || null); inp.focus(); inp.select();
        var done = false;
        function finish(commit) {
            if (done) return; done = true;
            var val = inp.value.trim();
            if (commit) { signs[idx].name = val; textEl.textContent = val; saveSigns(); }
            inp.remove(); textEl.style.display = ''; if (btn) btn.style.display = ''; ed.classList.remove('is-editing');
        }
        inp.addEventListener('keydown', function (ev) {
            if (ev.key === 'Enter') { ev.preventDefault(); inp.blur(); }
            else if (ev.key === 'Escape') { done = true; inp.remove(); textEl.style.display = ''; if (btn) btn.style.display = ''; ed.classList.remove('is-editing'); }
        });
        inp.addEventListener('blur', function () { finish(true); });
    }

    function renderSigns() {
        if (!signsBox) return;
        signsBox.innerHTML = '';
        signs.forEach(function (sg, idx) {
            var div = document.createElement('div');
            div.className = 'om-sign';
            div.setAttribute('draggable', 'true');
            div.setAttribute('data-idx', idx);
            div.innerHTML = '<div class="om-sign-role">' + esc(sg.role) + '</div>'
                + '<div class="om-sign-note">(Ký, họ tên)</div>'
                + '<div class="om-editable om-sign-name"><span class="om-etext" style="padding-left:24px;">' + esc(sg.name) + '</span>'
                + '<button type="button" class="om-edit-btn" title="Sửa"><i class="fa-solid fa-pen"></i></button></div>';
            var ed = div.querySelector('.om-sign-name');
            var btn = ed.querySelector('.om-edit-btn');
            btn.addEventListener('click', function (e) { e.preventDefault(); e.stopPropagation(); startSignNameEdit(ed, idx); });
            bindSignDrag(div);
            signsBox.appendChild(div);
        });
    }

    function renderRoleList() {
        var ul = $('om-sign-rolelist'); if (!ul) return;
        ul.innerHTML = '';
        signRoles.forEach(function (role, i) {
            var checked = signs.some(function (s) { return s.role === role; });
            var li = document.createElement('li');
            li.className = 'om-sign-roleitem';
            li.innerHTML = '<input type="checkbox" ' + (checked ? 'checked' : '') + '>'
                + '<span class="om-sign-rolename">' + esc(role) + '</span>'
                + '<div class="om-sign-roleact">'
                + '<button type="button" class="om-sign-rolebtn" data-act="edit" title="Đổi tên"><i class="fa-solid fa-pen"></i></button>'
                + '<button type="button" class="om-sign-rolebtn danger" data-act="del" title="Xóa"><i class="fa-solid fa-trash"></i></button>'
                + '</div>';
            var cb = li.querySelector('input');
            cb.addEventListener('change', function () {
                if (cb.checked) { if (!signs.some(function (s) { return s.role === role; })) signs.push({ role: role, name: '' }); }
                else { signs = signs.filter(function (s) { return s.role !== role; }); }
                renderSigns(); saveSigns();
            });
            var nameEl = li.querySelector('.om-sign-rolename');
            li.querySelector('[data-act="edit"]').addEventListener('click', function () {
                nameEl.setAttribute('contenteditable', 'true'); nameEl.focus();
                var r = document.createRange(); r.selectNodeContents(nameEl);
                var sel = window.getSelection(); sel.removeAllRanges(); sel.addRange(r);
            });
            nameEl.addEventListener('blur', function () {
                if (nameEl.getAttribute('contenteditable') !== 'true') return;
                nameEl.removeAttribute('contenteditable');
                var nv = nameEl.textContent.trim();
                if (nv && nv !== role) {
                    signRoles[i] = nv;
                    signs.forEach(function (s) { if (s.role === role) s.role = nv; });
                    saveRoles(); saveSigns(); renderRoleList(); renderSigns();
                } else { nameEl.textContent = role; }
            });
            nameEl.addEventListener('keydown', function (e) { if (e.key === 'Enter') { e.preventDefault(); nameEl.blur(); } });
            li.querySelector('[data-act="del"]').addEventListener('click', function () {
                if (!confirm('Xóa chức danh "' + role + '"?')) return;
                signRoles = signRoles.filter(function (r) { return r !== role; });
                signs = signs.filter(function (s) { return s.role !== role; });
                saveRoles(); saveSigns(); renderRoleList(); renderSigns();
            });
            ul.appendChild(li);
        });
    }

    function addRole() {
        var inp = $('om-sign-newrole'); var v = inp.value.trim();
        if (!v) return;
        if (signRoles.indexOf(v) === -1) { signRoles.push(v); saveRoles(); }
        inp.value = ''; renderRoleList();
    }

    var signSettingBtn = $('om-btn-sign-setting');
    if (signSettingBtn) signSettingBtn.addEventListener('click', function () { renderRoleList(); openModal('om-modal-sign'); });
    var signAddBtn = $('om-sign-addbtn');
    if (signAddBtn) signAddBtn.addEventListener('click', addRole);
    var signNewInp = $('om-sign-newrole');
    if (signNewInp) signNewInp.addEventListener('keydown', function (e) { if (e.key === 'Enter') { e.preventDefault(); addRole(); } });

    /* ---------- Init: khôi phục NCC đang chọn + phiếu đang soạn qua reload ---------- */
    docItems = loadDocItems();
    renderDoc();

    var savedSupplier = loadSupplier();
    if (savedSupplier) {
        supplier = savedSupplier;
        supInput.value = savedSupplier.supplier_name;
        docSupplier.textContent = savedSupplier.supplier_name;
        btnMin.disabled = false;
        btnSupInfo.disabled = false;
        loadMaterials();
    }

    renderSigns();
})();
