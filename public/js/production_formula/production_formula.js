/* =====================================================================
 *  Công thức sản xuất (production_formula)
 * ===================================================================== */
(function () {
    'use strict';

    var CFG = window.PF_CONFIG || { baseUrl: '?mod=production_formula&controllers=production_formula&action=' };

    /* ---------- State ---------- */
    var product = null;
    var recipe = [];          // [{ pm_id, material_id, display_name, material_name, common_material_name, unit, classification, base, stock, image_count, unitLabel, unitTouched, dispUnit }]
    var batches = [];
    var multiplier = 1;
    var onlyMaterial = false;
    var dirty = false;
    // Khóa sửa số lượng ở công thức GỐC (x1) — mặc định khóa, bật qua nút
    // "Chỉnh sửa công thức 1đv". Ở hệ số x2+ (công thức mẻ) luôn sửa được bình thường.
    var unitEditUnlocked = false;
    // Số thập phân hiển thị cột "Số lượng" (tab 1) — chỉnh qua 2 nút </-> khi hover tiêu đề cột,
    // để xem chính xác định mức của các thành phần tỷ lệ dùng rất nhỏ (không đổi số liệu gốc).
    var pfQtyDecimals = 2;
    var PF_QTY_DEC_MIN = 0, PF_QTY_DEC_MAX = 6;
    var currentBatch = null;
    var batchScale = 1; // hệ số xem trước khi share ở tab mẻ — chỉ hiển thị, không lưu DB
    // Chế độ hiển thị tên NVL: 'common' = ưu tiên tên thường gọi (mặc định),
    // 'standard' = ưu tiên tên nguyên liệu phổ thông. Có fallback 2 chiều.
    var nameMode = 'common';

    /* ---------- DOM ---------- */
    var $ = function (id) { return document.getElementById(id); };
    var $search = $('pf-search'), $sd = $('pf-search-dropdown');
    var $empty = $('pf-empty'), $main = $('pf-main'), $tabs = $('pf-tabs');
    var $prodName = $('pf-product-name'), $mulBadge = $('pf-multiplier-badge');
    var $unitEditToggle = $('pf-unit-edit-toggle');
    var $qtyDecCtrl = $('pf-qty-dec-ctrl'), $qtyDecMinus = $('pf-qty-dec-minus'), $qtyDecPlus = $('pf-qty-dec-plus');
    var $tbody = $('pf-recipe-tbody'), $rowTpl = $('pf-row-template');
    var $note = $('pf-note'), $noteStat = $('pf-note-status');
    var $totalQty = $('pf-total-qty'), $totalUnit = $('pf-total-unit');
    var $muls = $('pf-multipliers'), $mulInput = $('pf-mul-input');
    var $saveBatch = $('pf-save-batch'), $saveBatchWrap = $('pf-save-batch-wrap'), $batchName = $('pf-batch-name');
    var $onlyMat = $('pf-only-material'), $shareBtn = $('pf-share-btn');

    var $batchList = $('pf-batch-list'), $batchDetail = $('pf-batch-detail');
    var $bProdName = $('pf-batch-product-name'), $bMulBadge = $('pf-batch-mul-badge');
    var $bTbody = $('pf-batch-tbody'), $bNoteRow = $('pf-batch-note-row'), $bNote = $('pf-batch-note');
    var $bOutputInput = $('pf-batch-output-input'), $bOutputUnit = $('pf-batch-output-unit'), $bOutputStatus = $('pf-batch-output-status');
    var $bTotalQty = $('pf-batch-total-qty'), $bTotalUnit = $('pf-batch-total-unit');
    var $bShareBtn = $('pf-batch-share-btn'), $bDelete = $('pf-batch-delete'), $bDup = $('pf-batch-dup');
    var $bMuls = $('pf-batch-multipliers'), $bMulInput = $('pf-batch-mul-input');
    var $bNoteActions = $('pf-batch-note-actions'), $bNoteEdit = $('pf-batch-note-edit');
    var $bNoteDel = $('pf-batch-note-del'), $bNoteAdd = $('pf-batch-note-add');

    var $ucModal = $('pf-unit-conv-modal'), $ucOverlay = $('pf-unit-conv-overlay'), $ucClose = $('pf-unit-conv-close');
    var $ucBaseUnit = $('pf-uc-base-unit'), $ucConvUnit = $('pf-uc-conv-unit'), $ucRatio = $('pf-uc-ratio'), $ucOk = $('pf-uc-ok');
    var ucItemIdx = -1;

    var $modal = $('pf-modal'), $modalOv = $('pf-modal-overlay'), $modalClose = $('pf-modal-close');
    var $modalShare = $('pf-modal-share'), $shareSheet = $('pf-share-sheet');
    var $shareProd = $('pf-share-product'), $shareTbody = $('pf-share-tbody');
    var $shareTotal = $('pf-share-total'), $shareNote = $('pf-share-note');

    var $recentWrap = $('pf-recent-wrap'), $recentList = $('pf-recent-list');
    // R2-6 (mobile): bấm nhãn "Đã xem gần đây" để mở/đóng danh sách (dạng FAQ).
    if ($recentWrap) {
        var $recentLbl = $recentWrap.querySelector('.pf-recent-label');
        if ($recentLbl) $recentLbl.addEventListener('click', function () { $recentWrap.classList.toggle('is-open'); });
    }

    var $miModal = $('pf-matinfo-modal'), $miOverlay = $('pf-matinfo-overlay'), $miClose = $('pf-matinfo-close');
    var $miName = $('pf-matinfo-name'), $miSysName = $('pf-matinfo-sysname'), $miUnit = $('pf-matinfo-unit');
    var $miStock = $('pf-matinfo-stock'), $miUse1m = $('pf-matinfo-use1m'), $miUse3m = $('pf-matinfo-use3m');
    var $miUse6m = $('pf-matinfo-use6m'), $miProducts = $('pf-matinfo-products');

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
    function fmt(n, dec) {
        if (dec === undefined) dec = 2;
        var v = Math.round((Number(n) + Number.EPSILON) * Math.pow(10, dec)) / Math.pow(10, dec);
        return String(v);
    }
    function esc(s) {
        return String(s == null ? '' : s).replace(/[&<>"]/g, function (c) {
            return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;' }[c];
        });
    }
    function isKg(unit) { return String(unit || '').trim().toLowerCase() === 'kg'; }
    function isRawMaterial(cls) { return String(cls || '').trim() === 'Nguyên liệu'; }

    /**
     * Tên hiển thị NVL theo chế độ đang chọn (toggle ở tiêu đề cột).
     * - 'common'  : ưu tiên common_material_name, trống thì lấy material_name.
     * - 'standard': ưu tiên material_name, trống thì lấy common_material_name.
     * - Cả 2 trống -> 'Không xác định'.
     */
    function resolveName(row) {
        if (!row) return 'Không xác định';
        var m = String(row.material_name || '').trim();
        var c = String(row.common_material_name || '').trim();
        if (nameMode === 'standard') return m || c || 'Không xác định';
        return c || m || 'Không xác định';
    }

    // decimals: số thập phân override (tab "Công thức 1 đơn vị" cho chỉnh qua nút </->);
    // bỏ trống -> dùng mặc định như cũ (không ảnh hưởng các chỗ gọi khác).
    function displayQty(valueInBase, unit, decimals) {
        if (isKg(unit)) {
            if (valueInBase < 1) return { num: fmt(valueInBase * 1000, decimals != null ? decimals : 2), unit: 'g' };
            return { num: fmt(valueInBase, decimals != null ? decimals : 2), unit: 'kg' };
        }
        return { num: fmt(valueInBase, decimals != null ? decimals : 3), unit: String(unit || '') };
    }
    function parseQtyToBase(typed, dispUnit, materialUnit) {
        var v = parseFloat(String(typed).replace(',', '.'));
        if (isNaN(v) || v < 0) v = 0;
        if (isKg(materialUnit) && dispUnit === 'g') return v / 1000;
        return v;
    }

    /* ---- "Quy đổi đơn vị" (tab mẻ, lưu riêng từng dòng — it.conv_unit/it.conv_ratio):
     * 1 đvqđ = conv_ratio đvht. Chỉ đổi HIỂN THỊ/lưu của dòng công thức mẻ này, không
     * đụng material_information.unit (khác hẳn kg<->g tự động ở displayQty). ---- */
    function hasConv(it) { return !!(it && it.conv_unit && Number(it.conv_ratio) > 0); }
    function displayQtyForItem(it, valueInBase) {
        if (hasConv(it)) return { num: fmt(valueInBase / Number(it.conv_ratio), 2), unit: it.conv_unit };
        return displayQty(valueInBase, it.unit);
    }
    function parseQtyToBaseForItem(it, typed, dispUnit) {
        if (hasConv(it) && dispUnit === it.conv_unit) {
            var v = parseFloat(String(typed).replace(',', '.'));
            if (isNaN(v) || v < 0) v = 0;
            return v * Number(it.conv_ratio);
        }
        return parseQtyToBase(typed, dispUnit, it.unit);
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
        if (!sdItems.length) { $sd.innerHTML = '<li class="empty">Không tìm thấy sản phẩm có công thức.</li>'; $sd.classList.add('open'); return; }
        $sd.innerHTML = sdItems.map(function (it, i) {
            var badge = it.has_recipe ? '' : ' <span class="pf-sd-badge">chưa có công thức</span>';
            return '<li data-idx="' + i + '"><span class="pf-sd-name">' + esc(it.product_name) + '</span>' + badge + '</li>';
        }).join('');
        $sd.classList.add('open');
    }
    function closeDropdown() { $sd.classList.remove('open'); $sd.innerHTML = ''; sdItems = []; sdActive = -1; }
    function highlight() { Array.prototype.forEach.call($sd.children, function (li, i) { li.classList.toggle('active', i === sdActive); }); }
    function chooseProduct(it) { closeDropdown(); $search.value = it.product_name; loadRecipe(it.id); }
    $search.addEventListener('input', doSearch);
    $search.addEventListener('keydown', function (e) {
        if (!$sd.classList.contains('open') || !sdItems.length) return;
        if (e.key === 'ArrowDown') { e.preventDefault(); sdActive = Math.min(sdActive + 1, sdItems.length - 1); highlight(); }
        else if (e.key === 'ArrowUp') { e.preventDefault(); sdActive = Math.max(sdActive - 1, 0); highlight(); }
        else if (e.key === 'Enter') { e.preventDefault(); if (sdActive >= 0) chooseProduct(sdItems[sdActive]); }
        else if (e.key === 'Escape') { closeDropdown(); }
    });
    $sd.addEventListener('click', function (e) { var li = e.target.closest('li[data-idx]'); if (li) chooseProduct(sdItems[+li.dataset.idx]); });
    document.addEventListener('click', function (e) { if (!e.target.closest('.pf-search-wrap')) closeDropdown(); });

    /* ====================================================================
     *  ĐÃ XEM GẦN ĐÂY (localStorage — riêng theo trình duyệt, không qua DB)
     * ==================================================================== */
    var RECENT_KEY = 'pf_recent_views_v1';
    var RECENT_CAP = 8;
    function loadRecentViews() {
        try { var a = JSON.parse(localStorage.getItem(RECENT_KEY) || '[]'); return Array.isArray(a) ? a : []; }
        catch (e) { return []; }
    }
    function saveRecentViews(a) {
        try { localStorage.setItem(RECENT_KEY, JSON.stringify(a.slice(0, RECENT_CAP))); } catch (e) { }
    }
    function pushRecentView(p) {
        if (!p || !p.id) return;
        var a = loadRecentViews().filter(function (x) { return x.id !== p.id; });
        a.unshift({ id: p.id, product_name: p.product_name, product_code: p.product_code || '', unit: p.unit || '' });
        saveRecentViews(a);
        renderRecentStrip();
    }
    function renderRecentStrip() {
        if (!$recentWrap || !$recentList) return;
        var a = loadRecentViews().slice(0, RECENT_CAP);
        if (!a.length) { $recentWrap.style.display = 'none'; return; }
        $recentWrap.style.display = 'flex';
        $recentList.innerHTML = a.map(function (p) {
            return '<button type="button" class="pf-recent-chip" data-id="' + p.id + '">' + esc(p.product_name) + '</button>';
        }).join('');
    }
    if ($recentList) {
        $recentList.addEventListener('click', function (e) {
            var b = e.target.closest('.pf-recent-chip');
            if (!b) return;
            $search.value = b.textContent || '';
            loadRecipe(+b.dataset.id);
        });
    }
    renderRecentStrip();

    /* ====================================================================
     *  LOAD + RENDER công thức 1 đơn vị
     * ==================================================================== */
    function loadRecipe(pid) {
        post('get_recipe', { product_id: pid }).then(function (res) {
            if (!res || !res.success) { alert((res && res.message) || 'Lỗi tải công thức.'); return; }
            product = res.product;
            pushRecentView(product);
            if ($galleryBtn) $galleryBtn.disabled = false;
            recipe = (res.recipe || []).map(function (r) {
                return {
                    pm_id: r.pm_id, material_id: r.material_id, display_name: r.display_name,
                    material_name: r.material_name || '', common_material_name: r.common_material_name || '',
                    unit: r.unit, classification: r.classification, base: Number(r.quantity),
                    stock: Number(r.stock), image_count: Number(r.image_count || 0),
                    unitLabel: r.unit, unitTouched: false, dispUnit: r.unit
                };
            });
            batches = res.batches || [];
            multiplier = 1; dirty = false; setActiveMul(1); hideSaveBatch();
            // Sản phẩm CHƯA có công thức (recipe rỗng) -> không có gì để bảo vệ, mở khóa sẵn
            // để nhập công thức mới liền mạch (gõ tên -> Tab chọn -> nhảy sang ô số lượng gõ luôn).
            unitEditUnlocked = recipe.length === 0; updateUnitEditToggleUI();
            pfQtyDecimals = 2; updateQtyDecUI();
            $empty.style.display = 'none'; $main.style.display = 'block';
            $prodName.textContent = product.product_name;
            $note.value = res.note || ''; $noteStat.textContent = '';
            autoGrowNote();
            $mulInput.value = '';
            renderRecipe(); renderBatchList();
        });
    }

    function renderRecipe() {
        $tbody.innerHTML = '';
        recipe.forEach(function (row, idx) {
            var tr = $rowTpl.content.firstElementChild.cloneNode(true);
            tr.dataset.pmId = row.pm_id; tr.dataset.idx = idx;
            tr.querySelector('.pf-name-input').value = resolveName(row);
            paintRow(tr, row);
            paintImgBtn(tr, row);
            if (onlyMaterial && !isRawMaterial(row.classification)) tr.classList.add('pf-hidden');
            $tbody.appendChild(tr);
        });
        renumber(); updateTotal();
    }

    // Khóa sửa số lượng chỉ áp dụng ở công thức GỐC (hệ số x1) và chưa bấm mở khóa.
    // Ở hệ số x2+ luôn cho sửa bình thường (để lưu công thức mẻ).
    function isUnitLocked() { return multiplier === 1 && !unitEditUnlocked; }
    function updateUnitEditToggleUI() {
        if (!$unitEditToggle) return;
        // Chỉ có tác dụng ở công thức GỐC (x1) -> ẩn nút khi đang xem hệ số x2+.
        $unitEditToggle.style.display = multiplier === 1 ? '' : 'none';
        $unitEditToggle.classList.toggle('active', unitEditUnlocked);
        $unitEditToggle.innerHTML = unitEditUnlocked
            ? '<i class="fa-solid fa-lock-open"></i>'
            : '<i class="fa-solid fa-lock"></i>';
    }
    if ($unitEditToggle) {
        $unitEditToggle.addEventListener('click', function () {
            unitEditUnlocked = !unitEditUnlocked;
            updateUnitEditToggleUI();
            repaintAllRows();
        });
    }

    // Số thập phân cột "Số lượng" (tab 1) — chỉ đổi CÁCH HIỂN THỊ, không đụng số liệu gốc (row.base).
    function updateQtyDecUI() {
        if ($qtyDecCtrl) $qtyDecCtrl.title = 'Đang hiển thị ' + pfQtyDecimals + ' số thập phân';
        if ($qtyDecMinus) $qtyDecMinus.disabled = pfQtyDecimals <= PF_QTY_DEC_MIN;
        if ($qtyDecPlus) $qtyDecPlus.disabled = pfQtyDecimals >= PF_QTY_DEC_MAX;
    }
    function changeQtyDecimals(delta) {
        var next = Math.max(PF_QTY_DEC_MIN, Math.min(PF_QTY_DEC_MAX, pfQtyDecimals + delta));
        if (next === pfQtyDecimals) return;
        pfQtyDecimals = next;
        updateQtyDecUI();
        repaintAllRows();
    }
    if ($qtyDecMinus) $qtyDecMinus.addEventListener('click', function (e) { e.stopPropagation(); changeQtyDecimals(-1); });
    if ($qtyDecPlus) $qtyDecPlus.addEventListener('click', function (e) { e.stopPropagation(); changeQtyDecimals(1); });

    /** Vẽ số lượng + đơn vị (quy đổi kg/g) + cảnh báo tồn. */
    function paintRow(tr, row) {
        var mv = row.base * multiplier;
        var d = displayQty(mv, row.unit, pfQtyDecimals);
        row.dispUnit = d.unit;
        var $inp = tr.querySelector('.pf-qty-input');
        var $u = tr.querySelector('.pf-unit-input');
        // Số lượng 0 (thành phần vừa thêm, chưa nhập) -> để trống cho user gõ, tránh hiện "0"/"NaN".
        if (document.activeElement !== $inp) $inp.value = (!mv || isNaN(mv)) ? '' : d.num;
        // Quy đổi -> cập nhật cột đơn vị cho đúng (chỉ NVL đơn vị kg mới đổi g/kg).
        if (isKg(row.unit)) { row.unitLabel = d.unit; if (document.activeElement !== $u) $u.value = d.unit; }
        else if (document.activeElement !== $u) { $u.value = row.unitLabel || row.unit; }
        tr.classList.toggle('over-stock', mv > row.stock + 1e-9);
        var locked = isUnitLocked();
        var $qtyCell = tr.querySelector('.pf-cell-qty');
        if ($qtyCell) $qtyCell.classList.toggle('readonly', locked);
        $inp.readOnly = locked;
        var $normBtn = tr.querySelector('.pf-norm-btn');
        if ($normBtn) $normBtn.disabled = locked;
    }

    function paintImgBtn(tr, row) {
        var btn = tr.querySelector('.pf-img-btn');
        var has = row.image_count > 0;
        btn.classList.toggle('has-img', has);
        btn.querySelector('.pf-img-count').textContent = has ? row.image_count : '';
    }

    function renumber() {
        var n = 0;
        Array.prototype.forEach.call($tbody.children, function (tr) {
            if (tr.classList.contains('pf-hidden')) { tr.querySelector('.pf-stt-num').textContent = ''; return; }
            n++; tr.querySelector('.pf-stt-num').textContent = n;
        });
    }
    function updateTotal() {
        $totalQty.textContent = fmt(multiplier, 2);
        $totalUnit.textContent = product ? (product.unit || '') : '';
        if (multiplier !== 1) { $mulBadge.style.display = 'inline-block'; $mulBadge.textContent = 'x' + fmt(multiplier, 2) + ' mẻ'; }
        else $mulBadge.style.display = 'none';
    }

    /* ---------- Sửa số lượng / đơn vị ---------- */
    // normEdit: chế độ "Thay đổi định mức" — sửa 1 số -> tam suất các thành phần còn lại.
    var normEdit = { active: false, idx: -1, a1: 0 };
    function repaintAllRows() { Array.prototype.forEach.call($tbody.children, function (tr) { paintRow(tr, recipe[+tr.dataset.idx]); }); updateTotal(); }

    $tbody.addEventListener('keydown', function (e) {
        if ((e.target.classList.contains('pf-qty-input') || e.target.classList.contains('pf-unit-input')) && e.key === 'Enter') { e.preventDefault(); e.target.blur(); }
    });
    $tbody.addEventListener('blur', function (e) {
        var tr = e.target.closest('tr'); if (!tr) return;
        var row = recipe[+tr.dataset.idx]; if (!row) return;
        if (e.target.classList.contains('pf-qty-input')) {
            if (isUnitLocked()) { paintRow(tr, row); return; } // đang khóa công thức gốc -> bỏ qua mọi thay đổi
            // --- Chế độ Thay đổi định mức (tam suất) ---
            if (normEdit.active && +tr.dataset.idx === normEdit.idx) {
                e.target.classList.remove('pf-norm-active');
                normEdit.active = false;
                var b1 = parseQtyToBase(e.target.value, row.dispUnit, row.unit); // giá trị mới (đơn vị gốc, đã nhân hệ số)
                if (b1 <= 0 || normEdit.a1 <= 0) { paintRow(tr, row); return; }
                var ratio = b1 / normEdit.a1;
                if (Math.abs(ratio - 1) > 1e-9) {
                    recipe.forEach(function (r) { r.base = r.base * ratio; });
                    if (multiplier === 1) recipe.forEach(function (r) { post('update_quantity', { pm_id: r.pm_id, quantity: r.base }); });
                    else markDirty();
                }
                repaintAllRows();
                return;
            }
            // --- Sửa 1 dòng bình thường ---
            var mvNew = parseQtyToBase(e.target.value, row.dispUnit, row.unit);
            var newBase = multiplier > 0 ? mvNew / multiplier : mvNew;
            if (Math.abs(newBase - row.base) > 1e-9) {
                row.base = newBase;
                if (multiplier === 1) post('update_quantity', { pm_id: row.pm_id, quantity: row.base });
                else markDirty();
            }
            if (multiplier !== 1) markDirty();
            paintRow(tr, row); updateTotal();
        } else if (e.target.classList.contains('pf-unit-input')) {
            // Sửa đơn vị thủ công: chỉ lưu trong UI, KHÔNG đụng database.
            row.unitLabel = e.target.value.trim(); row.unitTouched = true;
        }
    }, true);

    /* ---------- Tên thành phần: input search + dropdown ---------- */
    var nameSearch = debounce(function (tr) {
        var inp = tr.querySelector('.pf-name-input');
        var dd = tr.querySelector('.pf-name-dropdown');
        var kw = inp.value.trim();
        if (kw === '') { closeNameDd(dd); return; }
        post('search_materials', { keyword: kw }).then(function (res) {
            var items = (res && res.data) || [];
            dd._items = items; dd._active = -1;
            if (!items.length) { dd.innerHTML = '<li class="empty">Không tìm thấy NVL.</li>'; dd.classList.add('open'); return; }
            dd.innerHTML = items.map(function (it, i) {
                return '<li data-idx="' + i + '"><span class="nd-name">' + esc(it.display_name) + '</span><span class="nd-meta">' + esc(it.unit || '') + ' · ' + esc(it.classification || '') + '</span></li>';
            }).join('');
            dd.classList.add('open');
        });
    }, 180);
    function closeNameDd(dd) { dd.classList.remove('open'); dd.innerHTML = ''; dd._items = []; dd._active = -1; }
    function nameHighlight(dd) { Array.prototype.forEach.call(dd.children, function (li, i) { li.classList.toggle('active', i === dd._active); }); }
    var nameJustChose = 0; // mốc thời gian vừa chọn NVL từ dropdown (tránh blur tưởng nhầm là sửa tên)
    function chooseMaterial(tr, it) {
        var row = recipe[+tr.dataset.idx];
        var dd = tr.querySelector('.pf-name-dropdown');
        nameJustChose = Date.now();
        closeNameDd(dd);
        post('update_material', { pm_id: row.pm_id, material_id: it.id }).then(function (res) {
            if (!res || !res.success) { alert((res && res.message) || 'Không đổi được nguyên liệu.'); return; }
            var m = res.material;
            row.material_id = m.material_id; row.display_name = m.display_name;
            row.material_name = m.material_name || ''; row.common_material_name = m.common_material_name || '';
            row.unit = m.unit; row.classification = m.classification; row.stock = Number(m.stock);
            row.image_count = Number(m.image_count || 0); row.unitLabel = m.unit; row.unitTouched = false;
            tr.querySelector('.pf-name-input').value = resolveName(row);
            paintRow(tr, row); paintImgBtn(tr, row);
            // áp lại bộ lọc "chỉ nguyên liệu"
            tr.classList.toggle('pf-hidden', onlyMaterial && !isRawMaterial(row.classification));
            renumber();
            // Chọn xong NVL (gõ tên -> Tab) -> nhảy sang ô số lượng để gõ tiếp luôn.
            var $qty = tr.querySelector('.pf-qty-input');
            if ($qty) { $qty.focus(); $qty.select(); }
        });
    }

    /** Chọn NVL cho dòng "Thêm thành phần" (tạm, chưa có pm_id) -> tạo mới trong DB. */
    function chooseNewMaterial(tr, it) {
        var dd = tr.querySelector('.pf-name-dropdown');
        nameJustChose = Date.now();
        closeNameDd(dd);
        if (!product) return;
        post('add_recipe_item', { product_id: product.id, material_id: it.id, quantity: 0 }).then(function (res) {
            if (!res || !res.success) { alert((res && res.message) || 'Không thêm được thành phần.'); return; }
            var r = res.item;
            recipe.push({
                pm_id: r.pm_id, material_id: r.material_id, display_name: r.display_name,
                material_name: r.material_name || '', common_material_name: r.common_material_name || '',
                unit: r.unit, classification: r.classification, base: Number(r.quantity),
                stock: Number(r.stock), image_count: Number(r.image_count || 0),
                unitLabel: r.unit, unitTouched: false, dispUnit: r.unit
            });
            renderRecipe();
            // Chọn xong NVL cho dòng vừa thêm (gõ tên -> Tab) -> nhảy sang ô số lượng để gõ tiếp luôn.
            var newTr = $tbody.querySelector('tr[data-pm-id="' + r.pm_id + '"]');
            var $qty = newTr && newTr.querySelector('.pf-qty-input');
            if ($qty) { $qty.focus(); $qty.select(); }
        });
    }

    /* ---- Sửa tên trực tiếp trên ô .pf-name-input (không chọn từ dropdown) ----
     * Mode "thường gọi"  -> cập nhật common_material_name vào DB.
     * Mode "phổ thông"   -> chỉ giữ tạm trong phiên, KHÔNG đụng DB. */
    function syncMaterialName(materialId, field, value) {
        recipe.forEach(function (r, i) {
            if (r.material_id === materialId) {
                r[field] = value;
                var tr = $tbody.querySelector('tr[data-idx="' + i + '"]');
                if (tr) { var ni = tr.querySelector('.pf-name-input'); if (ni && document.activeElement !== ni) ni.value = resolveName(r); }
            }
        });
    }
    function commitNameEdit(tr, row, typed, inp) {
        typed = (typed || '').trim();
        var cur = resolveName(row);
        if (typed === '' || typed === cur) { inp.value = cur; return; }
        if (nameMode === 'common') {
            row.common_material_name = typed;
            syncMaterialName(row.material_id, 'common_material_name', typed);
            post('rename_material_common', { material_id: row.material_id, common_material_name: typed });
        } else {
            // Tên phổ thông: chỉ đổi hiển thị tạm thời, không lưu DB.
            row.material_name = typed;
            syncMaterialName(row.material_id, 'material_name', typed);
        }
        inp.value = resolveName(row);
    }

    $tbody.addEventListener('input', function (e) {
        if (e.target.classList.contains('pf-name-input')) nameSearch(e.target.closest('tr'));
    });
    // Enter trên ô tên: nếu không đang chọn từ dropdown -> blur để chốt sửa tên.
    $tbody.addEventListener('keydown', function (e) {
        if (e.key !== 'Enter' || !e.target.classList.contains('pf-name-input')) return;
        var tr = e.target.closest('tr');
        var dd = tr.querySelector('.pf-name-dropdown');
        if (dd && dd.classList.contains('open') && dd._items && dd._items.length && dd._active >= 0) return; // để chọn material
        e.preventDefault();
        if (dd) closeNameDd(dd);
        e.target.blur();
    });
    // Blur ô tên: chốt sửa tên (trừ khi vừa chọn material từ dropdown).
    $tbody.addEventListener('blur', function (e) {
        if (!e.target.classList.contains('pf-name-input')) return;
        var tr = e.target.closest('tr'); if (!tr) return;
        // Dòng thêm mới (chưa chọn NVL): bấm ra ngoài thì bỏ dòng tạm, không gọi server.
        if (tr.classList.contains('pf-row-new')) {
            var ddNew = tr.querySelector('.pf-name-dropdown');
            setTimeout(function () {
                if (Date.now() - nameJustChose < 350) return; // vừa chọn NVL -> renderRecipe() sẽ thay thế dòng này
                if (ddNew && ddNew.classList.contains('open')) return;
                if (tr.parentNode) tr.remove();
            }, 200);
            return;
        }
        var row = recipe[+tr.dataset.idx]; if (!row) return;
        var inp = e.target, typed = inp.value;
        setTimeout(function () {
            if (Date.now() - nameJustChose < 350) return;          // vừa chọn NVL từ dropdown
            var dd = tr.querySelector('.pf-name-dropdown');
            if (dd && dd.classList.contains('open')) return;        // đang thao tác dropdown
            commitNameEdit(tr, row, typed, inp);
        }, 200);
    }, true);
    $tbody.addEventListener('keydown', function (e) {
        if (!e.target.classList.contains('pf-name-input')) return;
        var tr = e.target.closest('tr');
        var dd = tr.querySelector('.pf-name-dropdown');
        if (!dd.classList.contains('open') || !dd._items || !dd._items.length) return;
        if (e.key === 'ArrowDown') { e.preventDefault(); dd._active = Math.min((dd._active | 0) + 1, dd._items.length - 1); nameHighlight(dd); }
        else if (e.key === 'ArrowUp') { e.preventDefault(); dd._active = Math.max((dd._active | 0) - 1, 0); nameHighlight(dd); }
        else if (e.key === 'Enter' || e.key === 'Tab') {
            if (dd._active >= 0) {
                e.preventDefault();
                var chosenK = dd._items[dd._active];
                if (tr.classList.contains('pf-row-new')) chooseNewMaterial(tr, chosenK); else chooseMaterial(tr, chosenK);
            }
        }
        else if (e.key === 'Escape') { closeNameDd(dd); }
    });
    $tbody.addEventListener('click', function (e) {
        var li = e.target.closest('.pf-name-dropdown li[data-idx]');
        if (li) {
            var tr = e.target.closest('tr'); var dd = tr.querySelector('.pf-name-dropdown');
            var chosen = dd._items[+li.dataset.idx];
            if (tr.classList.contains('pf-row-new')) chooseNewMaterial(tr, chosen); else chooseMaterial(tr, chosen);
        }
    });
    document.addEventListener('click', function (e) {
        if (!e.target.closest('.pf-name-wrap')) {
            Array.prototype.forEach.call($tbody.querySelectorAll('.pf-name-dropdown.open'), function (dd) { closeNameDd(dd); });
        }
    });

    /* ---------- Hình ảnh nguyên liệu + Thay đổi định mức ---------- */
    $tbody.addEventListener('click', function (e) {
        var cancelNewBtn = e.target.closest('.pf-cancel-new-row');
        if (cancelNewBtn) { cancelNewBtn.closest('tr').remove(); return; }
        var imgBtn = e.target.closest('.pf-img-btn');
        if (imgBtn) { openImageModal(recipe[+imgBtn.closest('tr').dataset.idx]); return; }
        var delBtn = e.target.closest('.pf-del-row');
        if (delBtn) {
            var trDel = delBtn.closest('tr'); var rowDel = recipe[+trDel.dataset.idx];
            if (!rowDel) return;
            // Không hỏi xác nhận -> tan biến nhẹ nhàng rồi mới thực sự xóa khỏi bảng.
            trDel.classList.add('pf-row-vanishing');
            post('delete_item', { pm_id: rowDel.pm_id }).then(function (res) {
                if (res && res.success) {
                    setTimeout(function () { recipe.splice(+trDel.dataset.idx, 1); renderRecipe(); }, 260);
                } else {
                    trDel.classList.remove('pf-row-vanishing');
                    alert((res && res.message) || 'Không xóa được thành phần.');
                }
            });
            return;
        }
        var normBtn = e.target.closest('.pf-norm-btn');
        if (normBtn) {
            if (isUnitLocked()) return; // đang khóa công thức gốc -> không cho thay đổi định mức
            var tr = normBtn.closest('tr'); var row = recipe[+tr.dataset.idx];
            var mv = row.base * multiplier;
            if (mv <= 0) { alert('Không thể đổi định mức từ giá trị 0. Hãy nhập số lượng > 0 cho thành phần này trước.'); return; }
            normEdit = { active: true, idx: +tr.dataset.idx, a1: mv };
            var inp = tr.querySelector('.pf-qty-input');
            inp.classList.add('pf-norm-active');
            inp.focus(); inp.select();
        }
    });

    /* ---------- Drag & drop (Trello) ---------- */
    var dragEl = null;
    $tbody.addEventListener('mousedown', function (e) { var h = e.target.closest('.pf-drag-handle'); if (h) h.closest('tr').setAttribute('draggable', 'true'); });
    $tbody.addEventListener('mouseup', function () { Array.prototype.forEach.call($tbody.querySelectorAll('tr[draggable="true"]'), function (tr) { tr.setAttribute('draggable', 'false'); }); });
    $tbody.addEventListener('dragstart', function (e) { var tr = e.target.closest('tr'); if (!tr) return; dragEl = tr; tr.classList.add('dragging'); e.dataTransfer.effectAllowed = 'move'; });
    $tbody.addEventListener('dragend', function () { if (!dragEl) return; dragEl.classList.remove('dragging'); dragEl.setAttribute('draggable', 'false'); dragEl = null; commitOrder(); });
    $tbody.addEventListener('dragover', function (e) {
        if (!dragEl) return; e.preventDefault();
        var after = getDragAfter(e.clientY);
        if (after == null) $tbody.appendChild(dragEl); else $tbody.insertBefore(dragEl, after);
    });
    function getDragAfter(y) {
        var els = Array.prototype.slice.call($tbody.querySelectorAll('tr:not(.dragging)'));
        var closest = { offset: -Infinity, el: null };
        els.forEach(function (el) { var box = el.getBoundingClientRect(); var offset = y - box.top - box.height / 2; if (offset < 0 && offset > closest.offset) closest = { offset: offset, el: el }; });
        return closest.el;
    }
    function commitOrder() {
        var newOrder = [], ids = [];
        Array.prototype.forEach.call($tbody.children, function (tr) {
            var row = recipe.find(function (r) { return r.pm_id === +tr.dataset.pmId; });
            if (row) { newOrder.push(row); ids.push(row.pm_id); }
        });
        recipe = newOrder;
        Array.prototype.forEach.call($tbody.children, function (tr, i) { tr.dataset.idx = i; });
        renumber();
        if (product) post('reorder', { product_id: product.id, order: JSON.stringify(ids) });
    }

    /* ---------- Ghi chú ---------- */
    var saveNote = debounce(function () {
        if (!product) return;
        $noteStat.textContent = 'Đang lưu...';
        post('save_note', { product_id: product.id, note: $note.value }).then(function (res) {
            $noteStat.textContent = (res && res.success) ? 'Đã lưu' : 'Lỗi';
            setTimeout(function () { $noteStat.textContent = ''; }, 1500);
        });
    }, 600);
    // Textarea tự giãn cao theo nội dung (không hiện thanh cuộn riêng).
    function autoGrowNote() {
        $note.style.height = 'auto';
        $note.style.height = $note.scrollHeight + 'px';
    }
    $note.addEventListener('input', function () { autoGrowNote(); saveNote(); });
    // Enter thường = kết thúc nhập (blur), Alt+Enter = chèn xuống dòng — giống quy ước
    // pf-share-editable ở plan_for_staff.js.
    $note.addEventListener('keydown', function (ev) {
        if (ev.key === 'Enter' && ev.altKey) {
            ev.preventDefault();
            var s = $note.selectionStart, e = $note.selectionEnd;
            $note.value = $note.value.slice(0, s) + '\n' + $note.value.slice(e);
            $note.selectionStart = $note.selectionEnd = s + 1;
            autoGrowNote();
            saveNote();
        } else if (ev.key === 'Enter' && !ev.altKey) {
            ev.preventDefault();
            $note.blur();
        }
    });

    /* ---------- Multiplier ---------- */
    function setActiveMul(m) { Array.prototype.forEach.call($muls.children, function (b) { b.classList.toggle('active', Number(b.dataset.mul) === m); }); }
    function applyMultiplier(m) {
        multiplier = m > 0 ? m : 1;
        Array.prototype.forEach.call($tbody.children, function (tr) { paintRow(tr, recipe[+tr.dataset.idx]); });
        updateTotal();
        updateUnitEditToggleUI();
        if (multiplier !== 1) markDirty(); else if (!dirty) hideSaveBatch();
    }
    $muls.addEventListener('click', function (e) { var b = e.target.closest('.pf-mul-btn'); if (!b) return; $mulInput.value = ''; setActiveMul(Number(b.dataset.mul)); applyMultiplier(Number(b.dataset.mul)); });
    $mulInput.addEventListener('input', function () { var v = parseFloat($mulInput.value); if (isNaN(v) || v <= 0) return; setActiveMul(-1); applyMultiplier(v); });
    function markDirty() { dirty = true; $saveBatchWrap.style.display = 'block'; }
    function hideSaveBatch() { $saveBatchWrap.style.display = 'none'; }

    /* ---------- Hiệu ứng thay cho hộp thoại xác nhận "Đã lưu công thức mẻ": icon bay
     * từ nút Lưu sang tab "Công thức mẻ sản xuất" + tab đó chớp sáng lên. ---------- */
    function flyIconToBatchTab() {
        var $tabBatch = $tabs.querySelector('.pf-tab[data-tab="batch"]');
        if (!$saveBatch || !$tabBatch) return;
        var startRect = $saveBatch.getBoundingClientRect();
        var endRect = $tabBatch.getBoundingClientRect();
        var icon = document.createElement('i');
        icon.className = 'fa-solid fa-flask-vial pf-fly-icon';
        icon.style.left = (startRect.left + startRect.width / 2) + 'px';
        icon.style.top = (startRect.top + startRect.height / 2) + 'px';
        document.body.appendChild(icon);
        icon.getBoundingClientRect(); // ép reflow để transition ăn từ vị trí đầu
        requestAnimationFrame(function () {
            icon.style.left = (endRect.left + endRect.width / 2) + 'px';
            icon.style.top = (endRect.top + endRect.height / 2) + 'px';
            icon.style.opacity = '0';
            icon.style.fontSize = '10px';
        });
        setTimeout(function () {
            icon.remove();
            $tabBatch.classList.add('pf-tab-flash');
            setTimeout(function () { $tabBatch.classList.remove('pf-tab-flash'); }, 900);
        }, 550);
    }

    /* ---------- Lưu công thức mẻ (tôn trọng bộ lọc "chỉ nguyên liệu") ---------- */
    $saveBatch.addEventListener('click', function () {
        if (!product) return;
        // Nếu đang tích "Chỉ hiển thị nguyên liệu" -> chỉ lưu các thành phần đang hiển thị.
        var rows = recipe.filter(function (r) { return !(onlyMaterial && !isRawMaterial(r.classification)); });
        var items = rows.map(function (r, i) { return { material_id: r.material_id, quantity: r.base * multiplier, unit: r.unit, sort_order: i + 1 }; });
        if (!items.length) { alert('Không có thành phần nào để lưu.'); return; }
        // Ưu tiên tên do user đặt; bỏ trống -> để mặc định (chip hiển thị "x{hệ số}").
        var label = $batchName ? $batchName.value.trim() : '';
        $saveBatch.disabled = true;
        post('save_batch', { product_id: product.id, multiplier: multiplier, label: label, note: $note.value, items: JSON.stringify(items) }).then(function (res) {
            $saveBatch.disabled = false;
            if (res && res.success) {
                batches = res.batches || []; renderBatchList(); dirty = false;
                if ($batchName) $batchName.value = '';
                flyIconToBatchTab();
            } else alert((res && res.message) || 'Lưu công thức mẻ thất bại.');
        });
    });

    /* ---------- Thêm thành phần mới (chọn từ danh mục NVL) ---------- */
    var $addItemBtn = $('pf-add-item-btn');
    if ($addItemBtn) {
        $addItemBtn.addEventListener('click', function () {
            if (!product) return;
            var existing = $tbody.querySelector('.pf-row-new');
            if (existing) { var existingInp = existing.querySelector('.pf-name-input'); if (existingInp) existingInp.focus(); return; }
            var tr = document.createElement('tr');
            tr.className = 'pf-row pf-row-new';
            tr.innerHTML =
                '<td class="pf-cell-stt"><span class="pf-stt-num">—</span></td>' +
                '<td class="pf-cell-name"><div class="pf-name-wrap">' +
                '<input type="text" class="pf-name-input" autocomplete="off" placeholder="Gõ tên NVL để chọn...">' +
                '<ul class="pf-name-dropdown"></ul>' +
                '</div></td>' +
                '<td class="pf-cell-unit"></td>' +
                '<td class="pf-cell-qty"></td>' +
                '<td class="pf-cell-act"><button type="button" class="pf-cancel-new-row" title="Hủy">&times;</button></td>';
            $tbody.appendChild(tr);
            tr.querySelector('.pf-name-input').focus();
        });
    }

    /* ---------- Lọc chỉ nguyên liệu ---------- */
    $onlyMat.addEventListener('change', function () {
        onlyMaterial = $onlyMat.checked;
        Array.prototype.forEach.call($tbody.children, function (tr) {
            var row = recipe[+tr.dataset.idx];
            tr.classList.toggle('pf-hidden', onlyMaterial && !isRawMaterial(row.classification));
        });
        renumber();
    });

    /* ====================================================================
     *  TABS
     * ==================================================================== */
    $tabs.addEventListener('click', function (e) {
        var t = e.target.closest('.pf-tab'); if (!t) return;
        var tab = t.dataset.tab;
        Array.prototype.forEach.call($tabs.children, function (b) { b.classList.toggle('active', b === t); });
        document.querySelectorAll('.pf-panel').forEach(function (p) { p.classList.toggle('active', p.dataset.panel === tab); });
    });

    /* ====================================================================
     *  TAB 2 — công thức mẻ
     * ==================================================================== */
    function renderBatchList() {
        if (!batches.length) { $batchList.innerHTML = '<p class="pf-batch-empty">Chưa có công thức mẻ nào được lưu cho sản phẩm này.</p>'; $batchDetail.style.display = 'none'; return; }
        $batchList.innerHTML = batches.map(function (b) {
            var dt = (b.created_at || '').replace('T', ' ').slice(0, 16);
            var hasName = b.label && b.label.trim() !== '';
            var title = hasName ? esc(b.label) : 'x' + fmt(b.multiplier, 2);
            var meta = (hasName ? 'x' + fmt(b.multiplier, 2) + ' · ' : '') + esc(dt);
            return '<div class="pf-batch-chip" data-id="' + b.id + '">' +
                '<div class="pf-bc-mul"><span class="pf-bc-name">' + title + '</span>' +
                '<button type="button" class="pf-bc-edit" title="Sửa tên công thức mẻ"><i class="fa-solid fa-pen"></i></button></div>' +
                '<div class="pf-bc-meta">' + meta + '</div></div>';
        }).join('');
    }
    // Sửa tên công thức mẻ tại chỗ (sửa 1 lần dùng nhiều lần).
    function editBatchChipName(chip) {
        var mulEl = chip.querySelector('.pf-bc-mul');
        if (!mulEl || mulEl.querySelector('.pf-bc-edit-input')) return;
        var bid = +chip.dataset.id;
        var b = batches.find(function (x) { return x.id === bid; });
        var cur = (b && b.label && b.label.trim() !== '') ? b.label : '';
        var inp = document.createElement('input');
        inp.type = 'text'; inp.className = 'pf-bc-edit-input'; inp.value = cur;
        inp.placeholder = 'Tên công thức mẻ...';
        mulEl.innerHTML = ''; mulEl.appendChild(inp);
        inp.focus(); inp.select();
        var done = false;
        function finish(commit) {
            if (done) return; done = true;
            if (commit) {
                post('update_batch_label', { batch_id: bid, product_id: product ? product.id : 0, label: inp.value })
                    .then(function (res) { batches = (res && res.batches) || batches; renderBatchList(); });
            } else { renderBatchList(); }
        }
        inp.addEventListener('click', function (ev) { ev.stopPropagation(); });
        inp.addEventListener('keydown', function (ev) {
            if (ev.key === 'Enter') { ev.preventDefault(); inp.blur(); }
            else if (ev.key === 'Escape') { done = true; renderBatchList(); }
        });
        inp.addEventListener('blur', function () { finish(true); });
    }
    $batchList.addEventListener('click', function (e) {
        var editBtn = e.target.closest('.pf-bc-edit');
        if (editBtn) { e.stopPropagation(); editBatchChipName(editBtn.closest('.pf-batch-chip')); return; }
        var chip = e.target.closest('.pf-batch-chip'); if (!chip) return;
        Array.prototype.forEach.call($batchList.children, function (c) { c.classList.toggle('active', c === chip); });
        loadBatch(+chip.dataset.id);
    });
    function loadBatch(bid) {
        post('get_batch', { batch_id: bid }).then(function (res) {
            if (!res || !res.success) { alert('Không tải được công thức mẻ.'); return; }
            currentBatch = res.data; renderBatchDetail(true);
        });
    }
    /** Vẽ lại chi tiết mẻ đang chọn. resetScale=true khi đây là 1 mẻ MỚI được tải
     *  (loadBatch/nhân bản) — đưa hệ số xem trước về x1; false khi chỉ vẽ lại do
     *  đổi chế độ tên hiển thị (giữ nguyên hệ số đang xem). */
    function renderBatchDetail(resetScale) {
        var b = currentBatch;
        $batchDetail.style.display = 'block';
        $bProdName.textContent = b.product_name;
        if (resetScale) { batchScale = 1; setActiveBatchMul(1); if ($bMulInput) $bMulInput.value = ''; }
        $bTbody.innerHTML = b.items.map(function (it, i) { return batchRowHtml(it, i); }).join('');
        updateBatchTotal();
        renderBatchNote();
        renderBatchOutput();
    }

    /* ---- Tổng sản phẩm: ghi đè tay tổng số thành phẩm thực tế tạo thành, khác
     * với tổng tự tính = multiplier (để trống = dùng lại tự tính như trước giờ). ---- */
    function batchOutputBase() {
        var b = currentBatch;
        return (b.output_qty !== null && b.output_qty !== undefined) ? b.output_qty : b.multiplier;
    }
    function renderBatchOutput() {
        var b = currentBatch; if (!b || !$bOutputInput) return;
        $bOutputInput.value = (b.output_qty !== null && b.output_qty !== undefined) ? fmt(b.output_qty, 2) : '';
        $bOutputInput.placeholder = 'Tự động (' + fmt(b.multiplier, 2) + ')';
        if ($bOutputUnit) $bOutputUnit.textContent = b.product_unit || '';
    }
    var saveBatchOutput = debounce(function () {
        if (!currentBatch || !$bOutputInput) return;
        var bid = currentBatch.id;
        if ($bOutputStatus) $bOutputStatus.textContent = 'Đang lưu...';
        post('update_batch_output_qty', { batch_id: bid, qty: $bOutputInput.value }).then(function (res) {
            if (!currentBatch || currentBatch.id !== bid) return; // đã chuyển mẻ khác trong lúc chờ
            if (res && res.success) {
                currentBatch.output_qty = (res.output_qty === null || typeof res.output_qty === 'undefined') ? null : Number(res.output_qty);
                updateBatchTotal();
                if ($bOutputStatus) $bOutputStatus.textContent = 'Đã lưu';
            } else if ($bOutputStatus) $bOutputStatus.textContent = 'Lỗi';
            setTimeout(function () { if ($bOutputStatus) $bOutputStatus.textContent = ''; }, 1500);
        });
    }, 600);
    if ($bOutputInput) $bOutputInput.addEventListener('input', saveBatchOutput);

    /* ---- Ghi chú công thức mẻ: sửa/xóa tại chỗ (tùy biến, khác ghi chú tab 1 chỉ
     * sửa được lúc lưu mẻ). Rỗng -> hiện nút "Thêm ghi chú" thay vì ẩn cả cụm. ---- */
    function renderBatchNote() {
        var b = currentBatch; if (!b || !$bNoteRow) return;
        var has = b.note && b.note.trim() !== '';
        $bNoteRow.style.display = 'flex';
        $bNote.style.display = has ? '' : 'none';
        $bNote.textContent = has ? b.note : '';
        if ($bNoteActions) $bNoteActions.style.display = has ? '' : 'none';
        if ($bNoteAdd) $bNoteAdd.style.display = has ? 'none' : '';
    }
    function editBatchNote() {
        if (!currentBatch || !$bNoteRow || $bNoteRow.querySelector('.pf-batch-note-edit-input')) return;
        var cur = currentBatch.note || '';
        var inp = document.createElement('textarea');
        inp.rows = 1; inp.className = 'pf-batch-note-edit-input'; inp.value = cur;
        inp.placeholder = 'Nhập ghi chú... (Alt+Enter để xuống dòng)';
        $bNote.style.display = 'none';
        if ($bNoteActions) $bNoteActions.style.display = 'none';
        if ($bNoteAdd) $bNoteAdd.style.display = 'none';
        $bNoteRow.insertBefore(inp, $bNote.nextSibling);
        function grow() { inp.style.height = 'auto'; inp.style.height = inp.scrollHeight + 'px'; }
        grow();
        inp.focus(); if (cur) inp.select();
        inp.addEventListener('input', grow);
        // Enter thường = kết thúc nhập (blur), Alt+Enter = chèn xuống dòng — giống ô Ghi chú tab 1.
        inp.addEventListener('keydown', function (ev) {
            if (ev.key === 'Enter' && ev.altKey) {
                ev.preventDefault();
                var s = inp.selectionStart, e = inp.selectionEnd;
                inp.value = inp.value.slice(0, s) + '\n' + inp.value.slice(e);
                inp.selectionStart = inp.selectionEnd = s + 1;
                grow();
            } else if (ev.key === 'Enter' && !ev.altKey) {
                ev.preventDefault(); inp.blur();
            } else if (ev.key === 'Escape') {
                done = true; inp.remove(); renderBatchNote();
            }
        });
        var done = false;
        function finish(commit) {
            if (done) return; done = true;
            inp.remove();
            if (commit) {
                var val = inp.value.trim();
                if (val !== cur) {
                    currentBatch.note = val;
                    post('update_batch_note', { batch_id: currentBatch.id, note: val });
                }
            }
            renderBatchNote();
        }
        inp.addEventListener('blur', function () { finish(true); });
    }
    function deleteBatchNote() {
        if (!currentBatch) return;
        if (!confirm('Xóa ghi chú này?')) return;
        currentBatch.note = '';
        post('update_batch_note', { batch_id: currentBatch.id, note: '' });
        renderBatchNote();
    }
    if ($bNoteEdit) $bNoteEdit.addEventListener('click', editBatchNote);
    if ($bNoteAdd) $bNoteAdd.addEventListener('click', editBatchNote);
    if ($bNoteDel) $bNoteDel.addEventListener('click', deleteBatchNote);
    function batchRowHtml(it, i) {
        var d = displayQtyForItem(it, Number(it.quantity) * batchScale);
        var over = Number(it.quantity) * batchScale > Number(it.stock) + 1e-9;
        var hasImg = !it.is_custom && Number(it.image_count || 0) > 0;
        var name = it.is_custom ? esc(it.display_name) : esc(resolveName(it));
        var nameInner = '<span class="pf-cell-name-inner"><span class="pf-cell-name-text" title="Xem thông tin nguyên liệu">' + name +
            (it.is_custom ? ' <span class="pf-batch-custom-badge" title="Nguyên liệu tự do, chỉ có trong công thức mẻ này">tự do</span>' : '') +
            '</span>' +
            (!it.is_custom ? '<button type="button" class="pf-batch-name-edit" title="Sửa tên thường gọi"><i class="fa-solid fa-pen"></i></button>' : '') +
            '</span>';
        return '<tr class="pf-row' + (over ? ' over-stock' : '') + (it.is_custom ? ' pf-batch-custom-row' : '') + '" draggable="false"' +
            ' data-idx="' + i + '" data-item-id="' + it.item_id + '" data-material-id="' + (it.material_id == null ? '' : it.material_id) + '" data-disp="' + esc(d.unit) + '">' +
            '<td class="pf-cell-stt"><span class="pf-drag-handle" title="Kéo để sắp xếp"><i class="fa-solid fa-grip-vertical"></i></span><span class="pf-stt-num">' + (i + 1) + '</span></td>' +
            '<td class="pf-cell-name' + (hasImg ? ' pf-has-img' : '') + '"' + (hasImg ? ' title="Xem hình ảnh"' : '') + '>' + nameInner + '</td>' +
            '<td class="pf-cell-unit"><span class="pf-cell-unit-inner"><span class="pf-cell-unit-text">' + esc(d.unit) + '</span>' +
            '<button type="button" class="pf-unit-conv-btn" title="Quy đổi đơn vị"><i class="fa-solid fa-arrows-rotate"></i></button></span></td>' +
            '<td class="pf-cell-qty"><input type="text" class="pf-qty-input" inputmode="decimal" value="' + d.num + '"></td>' +
            '<td class="pf-cell-act"><button type="button" class="pf-batch-del-row" title="Xóa dòng">&times;</button></td></tr>';
    }
    function batchRenumber() {
        var n = 0;
        Array.prototype.forEach.call($bTbody.children, function (tr) { n++; tr.querySelector('.pf-stt-num').textContent = n; });
    }
    /* ---- Nhân hệ số xem trước khi share (chỉ hiển thị, không lưu DB) ---- */
    function setActiveBatchMul(m) {
        if (!$bMuls) return;
        Array.prototype.forEach.call($bMuls.children, function (b) { b.classList.toggle('active', Number(b.dataset.mul) === m); });
    }
    function updateBatchTotal() {
        var b = currentBatch; if (!b) return;
        $bTotalQty.textContent = fmt(batchOutputBase() * batchScale, 2);
        $bTotalUnit.textContent = b.product_unit || '';
        $bMulBadge.textContent = 'x' + fmt(b.multiplier, 2) + ' mẻ' + (batchScale !== 1 ? ' (xem trước x' + fmt(batchScale, 2) + ')' : '');
    }
    function applyBatchScale(m) {
        batchScale = m > 0 ? m : 1;
        Array.prototype.forEach.call($bTbody.children, function (tr) {
            if (tr.classList.contains('pf-batch-row-new')) return;
            var it = currentBatch.items[+tr.dataset.idx]; if (!it) return;
            var d = displayQtyForItem(it, Number(it.quantity) * batchScale);
            tr.dataset.disp = d.unit;
            tr.querySelector('.pf-cell-unit-text').textContent = d.unit;
            var inp = tr.querySelector('.pf-qty-input');
            if (document.activeElement !== inp) inp.value = d.num;
            tr.classList.toggle('over-stock', Number(it.quantity) * batchScale > Number(it.stock) + 1e-9);
        });
        updateBatchTotal();
    }
    if ($bMuls) {
        $bMuls.addEventListener('click', function (e) {
            var b = e.target.closest('.pf-mul-btn'); if (!b) return;
            if ($bMulInput) $bMulInput.value = '';
            setActiveBatchMul(Number(b.dataset.mul)); applyBatchScale(Number(b.dataset.mul));
        });
    }
    if ($bMulInput) {
        $bMulInput.addEventListener('input', function () {
            var v = parseFloat($bMulInput.value);
            if (isNaN(v) || v <= 0) return;
            setActiveBatchMul(-1); applyBatchScale(v);
        });
    }

    /* ---- Sửa số lượng 1 dòng mẻ -> cập nhật DB ---- */
    $bTbody.addEventListener('keydown', function (e) {
        if (e.target.classList.contains('pf-qty-input') && e.key === 'Enter') { e.preventDefault(); e.target.blur(); }
    });
    $bTbody.addEventListener('blur', function (e) {
        if (!e.target.classList.contains('pf-qty-input')) return;
        var tr = e.target.closest('tr'); if (tr.classList.contains('pf-batch-row-new')) return;
        var it = currentBatch.items[+tr.dataset.idx]; if (!it) return;
        // Giá trị đang hiển thị đã nhân theo batchScale (xem trước) -> chia lại
        // trước khi so sánh/lưu, để không ghi đè sai số lượng gốc đã lưu.
        var newQty = parseQtyToBaseForItem(it, e.target.value, tr.dataset.disp) / batchScale;
        if (Math.abs(newQty - Number(it.quantity)) > 1e-9) {
            it.quantity = newQty;
            post('update_batch_item', { item_id: it.item_id, quantity: newQty });
        }
        // repaint dòng (đổi g/kg hoặc quy đổi đơn vị + cảnh báo tồn) mà vẫn giữ item_id/idx
        var d = displayQtyForItem(it, Number(it.quantity) * batchScale);
        tr.dataset.disp = d.unit;
        tr.querySelector('.pf-cell-unit-text').textContent = d.unit;
        if (document.activeElement !== e.target) e.target.value = d.num;
        tr.classList.toggle('over-stock', Number(it.quantity) * batchScale > Number(it.stock) + 1e-9);
    }, true);

    /* ---- Click tên có ảnh -> xem ảnh / cây bút -> sửa tên thường gọi / icon quy đổi đơn vị ---- */
    $bTbody.addEventListener('click', function (e) {
        var del = e.target.closest('.pf-batch-del-row');
        if (del) {
            var tr = del.closest('tr'); var it = currentBatch.items[+tr.dataset.idx];
            if (!confirm('Xóa thành phần "' + it.display_name + '" khỏi công thức mẻ này?')) return;
            post('delete_batch_item', { item_id: it.item_id }).then(function (res) {
                if (res && res.success) { currentBatch.items.splice(+tr.dataset.idx, 1); renderBatchDetail(); }
            });
            return;
        }
        var ucBtn = e.target.closest('.pf-unit-conv-btn');
        if (ucBtn) { e.stopPropagation(); var trU = ucBtn.closest('tr'); openUnitConvModal(+trU.dataset.idx); return; }
        var nameEditBtn = e.target.closest('.pf-batch-name-edit');
        if (nameEditBtn) { e.stopPropagation(); startBatchNameEdit(nameEditBtn.closest('tr')); return; }
        var nameText = e.target.closest('.pf-cell-name-text');
        if (nameText) {
            var trT = nameText.closest('tr'); var itT = currentBatch.items[+trT.dataset.idx];
            if (!itT || itT.is_custom) return;
            openMaterialInfoModal(itT);
            return;
        }
        var nameCell = e.target.closest('.pf-cell-name.pf-has-img');
        if (nameCell) {
            var tr2 = nameCell.closest('tr'); var it2 = currentBatch.items[+tr2.dataset.idx];
            if (!it2 || it2.is_custom) return;
            openImageModalFor(it2.material_id, it2.display_name);
        }
    });

    /* ---- Sửa tên thường gọi trực tiếp trên tab mẻ (cây bút, chỉ dòng có ảnh) —
     * ghi vào material_information.common_material_name, đồng bộ tab 1 + mọi dòng
     * khác cùng material_id đang hiển thị trong chính mẻ này. ---- */
    function syncCommonNameEverywhere(materialId, val) {
        syncMaterialName(materialId, 'common_material_name', val);
        if (currentBatch && currentBatch.items) {
            currentBatch.items.forEach(function (r) { if (r.material_id === materialId) r.common_material_name = val; });
        }
    }
    function startBatchNameEdit(tr) {
        if (!tr || !currentBatch) return;
        var it = currentBatch.items[+tr.dataset.idx]; if (!it || it.is_custom) return;
        var cell = tr.querySelector('.pf-cell-name');
        if (cell.querySelector('.pf-batch-name-edit-input')) return;
        var textEl = cell.querySelector('.pf-cell-name-text');
        var editBtn = cell.querySelector('.pf-batch-name-edit');
        var cur = resolveName(it);
        var inp = document.createElement('input');
        inp.type = 'text'; inp.className = 'pf-batch-name-edit-input'; inp.value = cur;
        textEl.style.display = 'none';
        editBtn.parentNode.insertBefore(inp, editBtn);
        inp.focus(); inp.select();
        var done = false;
        function finish(commit) {
            if (done) return; done = true;
            if (commit) {
                var val = inp.value.trim();
                if (val !== '' && val !== cur) {
                    it.common_material_name = val;
                    syncCommonNameEverywhere(it.material_id, val);
                    post('rename_material_common', { material_id: it.material_id, common_material_name: val });
                }
            }
            renderBatchDetail();
        }
        inp.addEventListener('click', function (ev) { ev.stopPropagation(); });
        inp.addEventListener('keydown', function (ev) {
            if (ev.key === 'Enter') { ev.preventDefault(); inp.blur(); }
            else if (ev.key === 'Escape') { done = true; renderBatchDetail(); }
        });
        inp.addEventListener('blur', function () { finish(true); });
    }

    /* ---- Modal "Quy đổi đơn vị" (icon hover trên .pf-cell-unit, tab mẻ) ---- */
    function openUnitConvModal(idx) {
        if (!currentBatch) return;
        var it = currentBatch.items[idx]; if (!it) return;
        ucItemIdx = idx;
        $ucBaseUnit.textContent = it.unit || '';
        $ucConvUnit.value = it.conv_unit || '';
        $ucRatio.value = it.conv_ratio || '';
        $ucModal.style.display = 'flex';
        $ucConvUnit.focus();
    }
    function closeUnitConvModal() { $ucModal.style.display = 'none'; ucItemIdx = -1; }
    if ($ucClose) $ucClose.addEventListener('click', closeUnitConvModal);
    if ($ucOverlay) $ucOverlay.addEventListener('click', closeUnitConvModal);
    if ($ucOk) {
        $ucOk.addEventListener('click', function () {
            if (ucItemIdx < 0 || !currentBatch) return;
            var it = currentBatch.items[ucItemIdx]; if (!it) return;
            var cu = $ucConvUnit.value.trim();
            var ratio = parseFloat($ucRatio.value);
            if (cu !== '' && (isNaN(ratio) || ratio <= 0)) { alert('Tỉ lệ phải lớn hơn 0.'); return; }
            it.conv_unit = cu !== '' ? cu : null;
            it.conv_ratio = cu !== '' ? ratio : null;
            post('update_batch_item_conversion', { item_id: it.item_id, conv_unit: cu, conv_ratio: cu !== '' ? ratio : '' });
            closeUnitConvModal();
            applyBatchScale(batchScale);
        });
    }

    /* ---- Modal "Thông tin nguyên liệu" (click .pf-cell-name-text, tab mẻ) ---- */
    function openMaterialInfoModal(it) {
        if (!it || it.is_custom || !it.material_id) return;
        $miName.textContent = resolveName(it);
        $miSysName.textContent = '—'; $miUnit.textContent = '—'; $miStock.textContent = '—';
        $miUse1m.textContent = '—'; $miUse3m.textContent = '—'; $miUse6m.textContent = '—';
        $miProducts.innerHTML = '<li class="pf-matinfo-products-empty">Đang tải...</li>';
        $miModal.style.display = 'flex';
        post('get_material_info', { material_id: it.material_id }).then(function (res) {
            if ($miModal.style.display === 'none') return; // đã đóng trước khi có phản hồi
            if (!res || !res.success) {
                $miProducts.innerHTML = '<li class="pf-matinfo-products-empty">' + esc((res && res.message) || 'Không tải được thông tin.') + '</li>';
                return;
            }
            var d = res.data;
            var unitSuffix = d.unit ? (' ' + d.unit) : '';
            $miSysName.textContent = d.system_name || '—';
            $miUnit.textContent = d.unit || '—';
            $miStock.textContent = fmt(d.stock, 2) + unitSuffix;
            $miUse1m.textContent = fmt(d.use_1m, 2) + unitSuffix;
            $miUse3m.textContent = fmt(d.use_3m, 2) + unitSuffix;
            $miUse6m.textContent = fmt(d.use_6m, 2) + unitSuffix;
            $miProducts.innerHTML = (d.products && d.products.length)
                ? d.products.map(function (p) { return '<li>' + esc(p.name) + '</li>'; }).join('')
                : '<li class="pf-matinfo-products-empty">Chưa có sản phẩm nào dùng nguyên liệu này.</li>';
        });
    }
    function closeMaterialInfoModal() { $miModal.style.display = 'none'; }
    if ($miClose) $miClose.addEventListener('click', closeMaterialInfoModal);
    if ($miOverlay) $miOverlay.addEventListener('click', closeMaterialInfoModal);

    /* ---- Kéo-thả sắp xếp dòng mẻ -> cập nhật DB ---- */
    var bDragEl = null;
    $bTbody.addEventListener('mousedown', function (e) { var h = e.target.closest('.pf-drag-handle'); if (h) h.closest('tr').setAttribute('draggable', 'true'); });
    $bTbody.addEventListener('mouseup', function () { Array.prototype.forEach.call($bTbody.querySelectorAll('tr[draggable="true"]'), function (tr) { tr.setAttribute('draggable', 'false'); }); });
    $bTbody.addEventListener('dragstart', function (e) { var tr = e.target.closest('tr'); if (!tr) return; bDragEl = tr; tr.classList.add('dragging'); e.dataTransfer.effectAllowed = 'move'; });
    $bTbody.addEventListener('dragend', function () { if (!bDragEl) return; bDragEl.classList.remove('dragging'); bDragEl.setAttribute('draggable', 'false'); bDragEl = null; commitBatchOrder(); });
    $bTbody.addEventListener('dragover', function (e) {
        if (!bDragEl) return; e.preventDefault();
        var els = Array.prototype.slice.call($bTbody.querySelectorAll('tr:not(.dragging)'));
        var closest = { offset: -Infinity, el: null };
        els.forEach(function (el) { var box = el.getBoundingClientRect(); var off = e.clientY - box.top - box.height / 2; if (off < 0 && off > closest.offset) closest = { offset: off, el: el }; });
        if (closest.el == null) $bTbody.appendChild(bDragEl); else $bTbody.insertBefore(bDragEl, closest.el);
    });
    function commitBatchOrder() {
        var ids = [], newItems = [];
        Array.prototype.forEach.call($bTbody.children, function (tr) {
            var it = currentBatch.items[+tr.dataset.idx];
            if (it) { ids.push(it.item_id); newItems.push(it); }
        });
        currentBatch.items = newItems;
        Array.prototype.forEach.call($bTbody.children, function (tr, i) { tr.dataset.idx = i; });
        batchRenumber();
        post('reorder_batch_items', { batch_id: currentBatch.id, order: JSON.stringify(ids) });
    }
    if ($bDup) {
        $bDup.addEventListener('click', function () {
            if (!currentBatch) return;
            $bDup.disabled = true;
            post('duplicate_batch', { batch_id: currentBatch.id }).then(function (res) {
                $bDup.disabled = false;
                if (!res || !res.success) { alert((res && res.message) || 'Không nhân bản được công thức mẻ.'); return; }
                batches = res.batches || []; renderBatchList();
                currentBatch = res.data; renderBatchDetail(true);
                var chip = $batchList.querySelector('.pf-batch-chip[data-id="' + res.batch_id + '"]');
                Array.prototype.forEach.call($batchList.children, function (c) { c.classList.toggle('active', c === chip); });
            });
        });
    }
    $bDelete.addEventListener('click', function () {
        if (!currentBatch) return;
        if (!confirm('Xóa công thức mẻ x' + fmt(currentBatch.multiplier, 2) + ' này?')) return;
        post('delete_batch', { batch_id: currentBatch.id, product_id: currentBatch.product_id }).then(function (res) {
            if (res && res.success) { batches = res.batches || []; currentBatch = null; $batchDetail.style.display = 'none'; renderBatchList(); }
        });
    });

    /* ---- Thêm nguyên liệu (chọn NVL có sẵn hoặc gõ tên tự do) vào 1 mẻ đã lưu ---- */
    var $bAddItemBtn = $('pf-batch-add-item-btn');
    var batchAddSearch = debounce(function (tr) {
        var inp = tr.querySelector('.pf-name-input');
        var dd = tr.querySelector('.pf-name-dropdown');
        tr.dataset.materialId = ''; // gõ lại tên -> bỏ lựa chọn NVL trước đó
        var kw = inp.value.trim();
        if (kw === '') { closeNameDd(dd); return; }
        post('search_materials', { keyword: kw }).then(function (res) {
            var items = (res && res.data) || [];
            dd._items = items; dd._active = -1;
            if (!items.length) { dd.innerHTML = '<li class="empty">Không có trong danh mục — bấm "Thêm" để dùng làm tên tự do.</li>'; dd.classList.add('open'); return; }
            dd.innerHTML = items.map(function (it, i) {
                return '<li data-idx="' + i + '"><span class="nd-name">' + esc(it.display_name) + '</span><span class="nd-meta">' + esc(it.unit || '') + ' · ' + esc(it.classification || '') + '</span></li>';
            }).join('');
            dd.classList.add('open');
        });
    }, 180);
    function chooseBatchAddMaterial(tr, it) {
        closeNameDd(tr.querySelector('.pf-name-dropdown'));
        tr.dataset.materialId = it.id;
        tr.querySelector('.pf-name-input').value = it.display_name;
        var unitInp = tr.querySelector('.pf-unit-input');
        if (unitInp && !unitInp.value.trim()) unitInp.value = it.unit || '';
    }
    function confirmBatchAddRow(tr) {
        if (!currentBatch) return;
        var nameInp = tr.querySelector('.pf-name-input');
        var unitInp = tr.querySelector('.pf-unit-input');
        var qtyInp = tr.querySelector('.pf-qty-input');
        var mid = +(tr.dataset.materialId || 0);
        var name = nameInp.value.trim();
        if (!mid && name === '') { alert('Nhập tên nguyên liệu.'); return; }
        var qty = parseFloat(String(qtyInp.value).replace(',', '.')) || 0;
        post('add_batch_item', {
            batch_id: currentBatch.id, material_id: mid || 0,
            custom_name: mid ? '' : name, quantity: qty, unit: unitInp.value.trim()
        }).then(function (res) {
            if (!res || !res.success) { alert((res && res.message) || 'Không thêm được nguyên liệu.'); return; }
            currentBatch.items.push(res.item);
            renderBatchDetail();
        });
    }
    if ($bAddItemBtn) {
        $bAddItemBtn.addEventListener('click', function () {
            if (!currentBatch) return;
            var existing = $bTbody.querySelector('.pf-batch-row-new');
            if (existing) { var exInp = existing.querySelector('.pf-name-input'); if (exInp) exInp.focus(); return; }
            var tr = document.createElement('tr');
            tr.className = 'pf-row pf-batch-row-new';
            tr.innerHTML =
                '<td class="pf-cell-stt"><span class="pf-stt-num">—</span></td>' +
                '<td class="pf-cell-name"><div class="pf-name-wrap">' +
                '<input type="text" class="pf-name-input" autocomplete="off" placeholder="Gõ tên NVL có sẵn hoặc tên tự do...">' +
                '<ul class="pf-name-dropdown"></ul>' +
                '</div></td>' +
                '<td class="pf-cell-unit"><input type="text" class="pf-unit-input" autocomplete="off" placeholder="đv"></td>' +
                '<td class="pf-cell-qty"><input type="text" class="pf-qty-input" inputmode="decimal" value="0"></td>' +
                '<td class="pf-cell-act">' +
                '<button type="button" class="pf-batch-add-confirm" title="Thêm"><i class="fa-solid fa-check"></i></button>' +
                '<button type="button" class="pf-batch-add-cancel" title="Hủy">&times;</button>' +
                '</td>';
            $bTbody.appendChild(tr);
            tr.querySelector('.pf-name-input').focus();
        });
    }
    $bTbody.addEventListener('input', function (e) {
        if (!e.target.classList.contains('pf-name-input')) return;
        var tr = e.target.closest('tr');
        if (tr.classList.contains('pf-batch-row-new')) batchAddSearch(tr);
    });
    $bTbody.addEventListener('keydown', function (e) {
        if (!e.target.classList.contains('pf-name-input')) return;
        var tr = e.target.closest('tr');
        if (!tr.classList.contains('pf-batch-row-new')) return;
        var dd = tr.querySelector('.pf-name-dropdown');
        if (e.key === 'ArrowDown') { e.preventDefault(); dd._active = Math.min(((dd._active | 0) + 1), ((dd._items || []).length - 1)); nameHighlight(dd); }
        else if (e.key === 'ArrowUp') { e.preventDefault(); dd._active = Math.max(((dd._active | 0) - 1), 0); nameHighlight(dd); }
        else if (e.key === 'Enter') {
            e.preventDefault();
            if (dd && dd.classList.contains('open') && dd._items && dd._items.length && dd._active >= 0) chooseBatchAddMaterial(tr, dd._items[dd._active]);
            else confirmBatchAddRow(tr);
        }
        else if (e.key === 'Escape') { closeNameDd(dd); }
    });
    $bTbody.addEventListener('click', function (e) {
        var liNew = e.target.closest('.pf-batch-row-new .pf-name-dropdown li[data-idx]');
        if (liNew) { var trNew = e.target.closest('tr'); var ddNew = trNew.querySelector('.pf-name-dropdown'); chooseBatchAddMaterial(trNew, ddNew._items[+liNew.dataset.idx]); return; }
        var confirmBtn = e.target.closest('.pf-batch-add-confirm');
        if (confirmBtn) { confirmBatchAddRow(confirmBtn.closest('tr')); return; }
        var cancelBtn = e.target.closest('.pf-batch-add-cancel');
        if (cancelBtn) { cancelBtn.closest('tr').remove(); return; }
    });

    /* ====================================================================
     *  XEM NHANH TỒN (NVL / Thành phẩm)
     * ==================================================================== */
    (function () {
        var wrap = $('pf-stock-lookup'), input = $('pf-stock-input'), suggest = $('pf-stock-suggest');
        var btnMat = $('pf-stock-material'), btnProd = $('pf-stock-product');
        if (!wrap || !input || !suggest) return;
        var mode = null, timer = null;
        function hide() { suggest.innerHTML = ''; suggest.style.display = 'none'; }
        function open(m, ph) { mode = m; wrap.classList.add('is-open'); input.placeholder = ph; input.value = ''; hide(); input.focus(); }
        btnMat.addEventListener('click', function () { open('material', 'Nhập tên nguyên vật liệu..'); });
        btnProd.addEventListener('click', function () { open('product', 'Nhập tên thành phẩm..'); });
        input.addEventListener('input', function () {
            clearTimeout(timer);
            var kw = input.value.trim();
            if (kw === '' || !mode) { hide(); return; }
            var action = mode === 'material' ? 'material_stock_search' : 'product_stock_search';
            timer = setTimeout(function () {
                post(action, { keyword: kw }).then(function (res) {
                    var data = (res && res.data) || [];
                    if (!data.length) { suggest.innerHTML = '<li class="pf-stock-empty">Không tìm thấy.</li>'; suggest.style.display = 'block'; return; }
                    suggest.innerHTML = data.map(function (r) {
                        var label = esc(r.name) + ' — ' + esc(fmt(Number(r.quantity), 2)) + (r.unit ? ' ' + esc(r.unit) : '');
                        return '<li data-label="' + label + '">' + label + '</li>';
                    }).join('');
                    suggest.style.display = 'block';
                });
            }, 220);
        });
        suggest.addEventListener('click', function (e) { var li = e.target.closest('li'); if (!li || li.classList.contains('pf-stock-empty')) return; input.value = li.getAttribute('data-label') || li.textContent; hide(); });
        document.addEventListener('click', function (e) {
            if (!e.target.closest('#pf-stock-lookup') && e.target !== btnMat && e.target !== btnProd && !e.target.closest('#pf-stock-material') && !e.target.closest('#pf-stock-product')) {
                hide(); if (input.value.trim() === '') { wrap.classList.remove('is-open'); mode = null; }
            }
        });
    })();

    /* ====================================================================
     *  MODAL HÌNH ẢNH NGUYÊN LIỆU
     * ==================================================================== */
    var $imgModal = $('pf-img-modal'), $imgOverlay = $('pf-img-overlay'), $imgClose = $('pf-img-close');
    var $imgTitle = $('pf-img-title'), $imgGallery = $('pf-img-gallery'), $imgInput = $('pf-img-input'), $imgStatus = $('pf-img-status');
    var $lightbox = $('pf-lightbox'), $lightboxImg = $('pf-lightbox-img'), $lightboxClose = $('pf-lightbox-close');
    var $lightboxStage = $('pf-lightbox-stage');
    var $lbRotateLeft = $('pf-lightbox-rotate-left'), $lbRotateRight = $('pf-lightbox-rotate-right');
    var $lbZoomIn = $('pf-lightbox-zoom-in'), $lbZoomOut = $('pf-lightbox-zoom-out'), $lbReset = $('pf-lightbox-reset');
    var $lbShare = $('pf-lightbox-share');
    var imgMaterialId = 0;

    function openImageModal(row) { openImageModalFor(row.material_id, row.display_name); }
    function openImageModalFor(materialId, name) {
        imgMaterialId = materialId;
        $imgTitle.textContent = 'Hình ảnh: ' + name;
        $imgStatus.textContent = '';
        $imgModal.style.display = 'flex';
        loadImages();
    }
    function closeImageModal() { $imgModal.style.display = 'none'; imgMaterialId = 0; }
    function loadImages() {
        $imgGallery.innerHTML = '<div class="pf-img-empty">Đang tải...</div>';
        post('list_material_images', { material_id: imgMaterialId }).then(function (res) { renderGallery((res && res.data) || []); });
    }
    function renderGallery(list) {
        syncImageCount(list.length);
        if (!list.length) { $imgGallery.innerHTML = '<div class="pf-img-empty">Chưa có hình ảnh. Bấm "Thêm ảnh" để tải lên.</div>'; return; }
        $imgGallery.innerHTML = list.map(function (im) {
            return '<div class="pf-img-thumb"><img src="' + esc(im.file_path) + '" data-full="' + esc(im.file_path) + '" alt="">' +
                '<button type="button" class="pf-img-del" data-id="' + im.id + '" title="Xóa">&times;</button></div>';
        }).join('');
    }
    // Cập nhật image_count cho mọi dòng đang dùng material này (tab1 + tab2) + nút ảnh.
    function syncImageCount(count) {
        recipe.forEach(function (r, i) {
            if (r.material_id === imgMaterialId) {
                r.image_count = count;
                var tr = $tbody.querySelector('tr[data-idx="' + i + '"]');
                if (tr) paintImgBtn(tr, r);
            }
        });
        if (currentBatch && currentBatch.items) {
            var changed = false;
            currentBatch.items.forEach(function (it) { if (it.material_id === imgMaterialId) { it.image_count = count; changed = true; } });
            if (changed && $batchDetail.style.display !== 'none') renderBatchDetail();
        }
    }
    $imgGallery.addEventListener('click', function (e) {
        var del = e.target.closest('.pf-img-del');
        if (del) {
            if (!confirm('Xóa ảnh này?')) return;
            post('delete_material_image', { image_id: +del.dataset.id, material_id: imgMaterialId }).then(function (res) { renderGallery((res && res.data) || []); });
            return;
        }
        var img = e.target.closest('img[data-full]');
        if (img) openLightbox(img.getAttribute('data-full'));
    });
    function uploadImages(files) {
        if (!files || !files.length || !imgMaterialId) return;
        var fd = new FormData();
        fd.append('material_id', imgMaterialId);
        Array.prototype.forEach.call(files, function (f) { if (f && f.type && f.type.indexOf('image/') === 0) fd.append('files[]', f); });
        $imgStatus.textContent = 'Đang tải lên...';
        fetch(CFG.baseUrl + 'upload_material_image', { method: 'POST', body: fd, credentials: 'same-origin' })
            .then(function (r) { return r.json(); })
            .then(function (res) {
                if (res && res.success) { $imgStatus.textContent = 'Đã tải lên.'; renderGallery(res.data || []); }
                else { $imgStatus.textContent = (res && res.errors && res.errors.join(', ')) || 'Tải lên thất bại.'; }
                setTimeout(function () { $imgStatus.textContent = ''; }, 2500);
            });
    }
    $imgInput.addEventListener('change', function () { uploadImages($imgInput.files); $imgInput.value = ''; });

    // Ctrl+V: dán ảnh copy từ app khác vào khi modal đang mở.
    document.addEventListener('paste', function (e) {
        if ($imgModal.style.display === 'none' || !imgMaterialId) return;
        var items = (e.clipboardData && e.clipboardData.items) || [];
        var files = [];
        Array.prototype.forEach.call(items, function (it) { if (it.kind === 'file' && it.type.indexOf('image/') === 0) { var f = it.getAsFile(); if (f) files.push(f); } });
        if (files.length) { e.preventDefault(); uploadImages(files); }
    });

    $imgClose.addEventListener('click', closeImageModal);
    $imgOverlay.addEventListener('click', closeImageModal);

    /* ---- Lightbox: xoay trái/phải, lăn chuột phóng to/thu nhỏ, share qua chat ---- */
    var lbRotation = 0, lbScale = 1;
    var LB_SCALE_MIN = 0.3, LB_SCALE_MAX = 5;

    function applyLightboxTransform() {
        $lightboxImg.style.transform = 'rotate(' + lbRotation + 'deg) scale(' + lbScale + ')';
    }
    function openLightbox(src) {
        lbRotation = 0; lbScale = 1;
        $lightboxImg.src = src;
        applyLightboxTransform();
        $lightbox.style.display = 'flex';
    }
    function closeLightbox() { $lightbox.style.display = 'none'; }

    $lightboxClose.addEventListener('click', closeLightbox);
    $lightbox.addEventListener('click', function (e) { if (e.target === $lightbox) closeLightbox(); });
    document.addEventListener('keydown', function (e) {
        if (e.key === 'Escape' && $lightbox.style.display === 'flex') closeLightbox();
    });

    if ($lbRotateLeft) $lbRotateLeft.addEventListener('click', function () { lbRotation -= 90; applyLightboxTransform(); });
    if ($lbRotateRight) $lbRotateRight.addEventListener('click', function () { lbRotation += 90; applyLightboxTransform(); });
    if ($lbZoomIn) $lbZoomIn.addEventListener('click', function () { lbScale = Math.min(LB_SCALE_MAX, lbScale * 1.25); applyLightboxTransform(); });
    if ($lbZoomOut) $lbZoomOut.addEventListener('click', function () { lbScale = Math.max(LB_SCALE_MIN, lbScale / 1.25); applyLightboxTransform(); });
    if ($lbReset) $lbReset.addEventListener('click', function () { lbRotation = 0; lbScale = 1; applyLightboxTransform(); });
    if ($lightboxStage) {
        $lightboxStage.addEventListener('wheel', function (e) {
            e.preventDefault();
            var factor = e.deltaY < 0 ? 1.12 : (1 / 1.12);
            lbScale = Math.min(LB_SCALE_MAX, Math.max(LB_SCALE_MIN, lbScale * factor));
            applyLightboxTransform();
        }, { passive: false });
    }
    // Share: vẽ lại ảnh đang xem (đã xoay) ra canvas rồi copy vào clipboard — cùng quy ước
    // "chụp ảnh -> clipboard -> Ctrl+V dán vào chat/Zalo/Messenger" dùng ở $galleryShare/$shareBtn.
    if ($lbShare) {
        $lbShare.addEventListener('click', function () {
            if (!navigator.clipboard || typeof window.ClipboardItem !== 'function') { alert('Trình duyệt không hỗ trợ copy ảnh vào clipboard.'); return; }
            var srcImg = $lightboxImg;
            if (!srcImg.naturalWidth) { alert('Ảnh chưa tải xong, thử lại sau.'); return; }
            var orig = $lbShare.innerHTML;
            $lbShare.innerHTML = 'Đang xử lý...'; $lbShare.disabled = true;
            var w = srcImg.naturalWidth, h = srcImg.naturalHeight;
            var swapped = ((lbRotation % 180) + 180) % 180 === 90;
            var canvas = document.createElement('canvas');
            canvas.width = swapped ? h : w;
            canvas.height = swapped ? w : h;
            var ctx = canvas.getContext('2d');
            ctx.fillStyle = '#ffffff'; ctx.fillRect(0, 0, canvas.width, canvas.height);
            ctx.translate(canvas.width / 2, canvas.height / 2);
            ctx.rotate(lbRotation * Math.PI / 180);
            ctx.drawImage(srcImg, -w / 2, -h / 2, w, h);
            function finish() { $lbShare.innerHTML = orig; $lbShare.disabled = false; }
            canvas.toBlob(function (blob) {
                if (!blob) { alert('Lỗi tạo ảnh.'); finish(); return; }
                navigator.clipboard.write([new ClipboardItem({ 'image/png': blob })]).then(function () {
                    alert('Đã copy ảnh vào clipboard.\nMở khung chat (hoặc Zalo, Messenger...) và bấm Ctrl+V để gửi.');
                }).catch(function () { alert('Không copy được vào clipboard.'); }).then(finish);
            }, 'image/png');
        });
    }

    /* ====================================================================
     *  GALLERY ẢNH TOÀN BỘ NGUYÊN LIỆU CỦA CÔNG THỨC + SHARE
     * ==================================================================== */
    var $galleryBtn = $('pf-gallery-btn'), $galleryModal = $('pf-gallery-modal'), $galleryOverlay = $('pf-gallery-overlay');
    var $galleryClose = $('pf-gallery-close'), $galleryGrid = $('pf-gallery-grid'), $gallerySheet = $('pf-gallery-sheet');
    var $galleryProdName = $('pf-gallery-product-name'), $galleryShare = $('pf-gallery-share');

    function renderGalleryGrid(list) {
        if (!list.length) { $galleryGrid.innerHTML = '<div class="pf-gallery-empty">Không có hình ảnh nào cho công thức này.</div>'; return; }
        $galleryGrid.innerHTML = list.map(function (im) {
            return '<figure class="pf-gallery-item"><img src="' + esc(im.file_path) + '" alt="" data-full="' + esc(im.file_path) + '">' +
                '<figcaption>' + esc(im.display_name) + '</figcaption></figure>';
        }).join('');
    }
    // Click 1 ảnh trong lưới -> phóng to bằng lightbox chung (xoay/zoom/share).
    $galleryGrid.addEventListener('click', function (e) {
        var img = e.target.closest('img[data-full]');
        if (img) openLightbox(img.getAttribute('data-full'));
    });
    if ($galleryBtn) {
        $galleryBtn.addEventListener('click', function () {
            if (!product) return;
            $galleryProdName.textContent = product.product_name;
            $galleryGrid.innerHTML = '<div class="pf-gallery-empty">Đang tải...</div>';
            $galleryModal.style.display = 'flex';
            post('list_recipe_images_gallery', { product_id: product.id }).then(function (res) {
                renderGalleryGrid((res && res.data) || []);
            });
        });
    }
    function closeGalleryModal() { $galleryModal.style.display = 'none'; }
    if ($galleryClose) $galleryClose.addEventListener('click', closeGalleryModal);
    if ($galleryOverlay) $galleryOverlay.addEventListener('click', closeGalleryModal);
    if ($galleryShare) {
        $galleryShare.addEventListener('click', function () {
            if (typeof window.html2canvas !== 'function') { alert('Không nạp được html2canvas. Kiểm tra mạng rồi thử lại.'); return; }
            if (!navigator.clipboard || typeof window.ClipboardItem !== 'function') { alert('Trình duyệt không hỗ trợ copy ảnh vào clipboard.'); return; }
            var orig = $galleryShare.innerHTML;
            $galleryShare.innerHTML = 'Đang xử lý...'; $galleryShare.disabled = true;
            window.html2canvas($gallerySheet, { scale: 2, backgroundColor: '#ffffff', useCORS: true }).then(function (canvas) {
                canvas.toBlob(function (blob) {
                    navigator.clipboard.write([new ClipboardItem({ 'image/png': blob })]).then(function () {
                        alert('Đã copy ảnh danh sách nguyên liệu vào clipboard.\nMở app khác (Zalo, Messenger...) và bấm Ctrl+V để dán.');
                    }).catch(function () { alert('Không copy được vào clipboard.'); }).then(function () { $galleryShare.innerHTML = orig; $galleryShare.disabled = false; });
                }, 'image/png');
            }).catch(function (err) { console.error('Gallery share error:', err); alert('Lỗi tạo ảnh: ' + err.message); $galleryShare.innerHTML = orig; $galleryShare.disabled = false; });
        });
    }

    /* ====================================================================
     *  SHARE (modal -> html2canvas -> clipboard)
     * ==================================================================== */
    function buildShareSheet(rows, productName, totalNum, totalUnit, note) {
        $shareProd.textContent = productName;
        // Cột số lượng KHÔNG kèm đơn vị (đã có cột đơn vị); số lượng sửa tạm được.
        $shareTbody.innerHTML = rows.map(function (r, i) {
            return '<tr><td class="ss-stt"><span class="ss-stt-badge">' + (i + 1) + '</span></td><td class="ss-name">' + esc(r.name) + '</td>' +
                '<td class="ss-unit">' + esc(r.unit) + '</td>' +
                '<td class="ss-qty"><input type="text" class="pf-share-qty-input" value="' + esc(r.qty) + '"></td></tr>';
        }).join('');
        $shareTotal.innerHTML = '<span class="pf-share-total-label">TỔNG SẢN PHẨM:</span> ' +
            '<span class="pf-share-total-val">' + esc(totalNum + ' ' + totalUnit) + '</span>';
        if (note && note.trim() !== '') {
            $shareNote.style.display = 'block';
            $shareNote.innerHTML = '<span class="pf-share-note-label">Ghi chú:</span> ' + esc(note);
        } else $shareNote.style.display = 'none';
    }
    function openShareUnit() {
        if (!product) return;
        var rows = recipe.filter(function (r) { return !(onlyMaterial && !isRawMaterial(r.classification)); }).map(function (r) {
            var d = displayQty(r.base * multiplier, r.unit);
            return { name: resolveName(r), unit: (isKg(r.unit) ? d.unit : (r.unitLabel || r.unit)), qty: d.num };
        });
        buildShareSheet(rows, product.product_name, fmt(multiplier, 2), product.unit || '', $note.value);
        $modal.style.display = 'flex';
    }
    function openShareBatch() {
        if (!currentBatch) return;
        var b = currentBatch;
        var rows = b.items.map(function (it) {
            var d = displayQtyForItem(it, Number(it.quantity) * batchScale);
            return { name: it.is_custom ? it.display_name : resolveName(it), unit: d.unit, qty: d.num };
        });
        buildShareSheet(rows, b.product_name, fmt(batchOutputBase() * batchScale, 2), b.product_unit || '', b.note);
        $modal.style.display = 'flex';
    }
    function closeModal() { $modal.style.display = 'none'; }
    $shareBtn.addEventListener('click', openShareUnit);
    $bShareBtn.addEventListener('click', openShareBatch);
    $modalClose.addEventListener('click', closeModal);
    $modalOv.addEventListener('click', closeModal);

    /* ====================================================================
     *  TOGGLE tên NVL (tên phổ thông ↔ tên thường gọi) — icon ở tiêu đề cột
     * ==================================================================== */
    // Nhãn cột cho user biết đang hiển thị tên nào.
    function nameModeLabel() {
        return nameMode === 'standard' ? 'NGUYÊN LIỆU (Tên phổ thông)' : 'NGUYÊN LIỆU (Tên thường gọi)';
    }
    function updateNameModeLabels() {
        Array.prototype.forEach.call(document.querySelectorAll('.pf-th-label'), function (el) {
            el.textContent = nameModeLabel();
        });
    }
    function applyNameMode() {
        updateNameModeLabels();
        // Tab 1 — cập nhật giá trị input tên (không đè ô đang gõ).
        Array.prototype.forEach.call($tbody.children, function (tr) {
            var row = recipe[+tr.dataset.idx];
            var inp = tr.querySelector('.pf-name-input');
            if (row && inp && document.activeElement !== inp) inp.value = resolveName(row);
        });
        // Tab 2 — vẽ lại chi tiết mẻ (batchRowHtml dùng resolveName).
        if (currentBatch && $batchDetail.style.display !== 'none') renderBatchDetail();
    }
    Array.prototype.forEach.call(document.querySelectorAll('.pf-name-toggle'), function (btn) {
        btn.addEventListener('click', function (e) {
            e.preventDefault();
            nameMode = (nameMode === 'common') ? 'standard' : 'common';
            applyNameMode();
        });
    });
    updateNameModeLabels();

    /* ====================================================================
     *  SỬA tiêu đề phiếu Share tại chỗ (sửa 1 lần dùng nhiều lần)
     * ==================================================================== */
    function startShareEdit(el) {
        var key = el.getAttribute('data-share-key');
        var multiline = el.getAttribute('data-multiline') === '1';
        var textEl = el.querySelector('.pf-share-etext');
        var btn = el.querySelector('.pf-share-edit');
        if (!textEl || el.classList.contains('is-editing')) return;
        var cur = textEl.textContent;
        var inp = document.createElement(multiline ? 'textarea' : 'input');
        if (!multiline) inp.type = 'text';
        inp.className = 'pf-share-edit-input' + (multiline ? ' pf-share-edit-area' : '');
        inp.value = cur;
        if (multiline) inp.rows = Math.max(2, cur.split('\n').length);
        el.classList.add('is-editing');
        textEl.style.display = 'none';
        if (btn) btn.style.display = 'none';
        el.insertBefore(inp, btn || null);
        inp.focus(); if (inp.select) inp.select();
        var done = false;
        function finish(commit) {
            if (done) return; done = true;
            var val = multiline ? inp.value.replace(/\s+$/, '') : inp.value.trim();
            if (commit && val !== '' && val !== cur) {
                textEl.textContent = val;
                post('save_share_setting', { key: key, value: val });
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
            } else if (ev.key === 'Enter' && !ev.altKey) {
                ev.preventDefault(); inp.blur();
            } else if (ev.key === 'Escape') {
                done = true; inp.remove(); textEl.style.display = ''; if (btn) btn.style.display = ''; el.classList.remove('is-editing');
            }
        });
        inp.addEventListener('blur', function () { finish(true); });
    }
    Array.prototype.forEach.call(document.querySelectorAll('.pf-share-editable'), function (el) {
        var btn = el.querySelector('.pf-share-edit');
        if (btn) btn.addEventListener('click', function (e) { e.preventDefault(); e.stopPropagation(); startShareEdit(el); });
    });

    $modalShare.addEventListener('click', function () {
        if (typeof window.html2canvas !== 'function') { alert('Không nạp được html2canvas. Kiểm tra mạng rồi thử lại.'); return; }
        if (!navigator.clipboard || typeof window.ClipboardItem !== 'function') { alert('Trình duyệt không hỗ trợ copy ảnh vào clipboard.'); return; }
        var orig = $modalShare.innerHTML;
        $modalShare.innerHTML = 'Đang xử lý...'; $modalShare.disabled = true;
        $shareSheet.classList.add('is-capturing'); // html2canvas không vẽ được repeating-linear-gradient của .pf-share-rope
        window.html2canvas($shareSheet, { scale: 2, backgroundColor: '#ffffff', useCORS: true }).then(function (canvas) {
            $shareSheet.classList.remove('is-capturing');
            canvas.toBlob(function (blob) {
                navigator.clipboard.write([new ClipboardItem({ 'image/png': blob })]).then(function () {
                    alert('Đã copy ảnh công thức vào clipboard.\nMở app khác (Zalo, Messenger...) và bấm Ctrl+V để dán.');
                }).catch(function () { alert('Không copy được vào clipboard.'); }).then(function () { $modalShare.innerHTML = orig; $modalShare.disabled = false; });
            }, 'image/png');
        }).catch(function (err) { $shareSheet.classList.remove('is-capturing'); console.error('Share error:', err); alert('Lỗi tạo ảnh: ' + err.message); $modalShare.innerHTML = orig; $modalShare.disabled = false; });
    });

})();
