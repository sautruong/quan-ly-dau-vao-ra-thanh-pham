(function () {
    'use strict';

    const CFG = window.INVENTORY_CONFIG || { baseUrl: '?mod=inventory_management&controllers=inventory_management&action=' };
    const INITIAL = window.INVENTORY_DATA || { items: [], planDate: '', history: [], typeImport: 'investment_production' };
    const TYPE_IMPORT = INITIAL.typeImport || 'investment_production';
    let pageSize = 10; // số dòng/trang — đổi qua select #hf-page-size
    // Bộ lọc lịch sử (client-side trên INITIAL.history đã nạp sẵn).
    const histFilter = { keyword: '', dateFrom: '', dateTo: '' };

    // TASK 3a: đơn giá "Chi phí sản xuất" (overhead/đơn vị). Mặc định 6000, user đổi được.
    let prodCostRate = Number(INITIAL.productionCostRate);
    if (!isFinite(prodCostRate) || prodCostRate < 0) prodCostRate = 6000;

    let editingBatchKey = null;
    let historyPage = 1;
    let currentItems = (INITIAL.items || []).slice(); // last items loaded by date
    let matActiveIdx = -1; // vị trí đang chọn (phím mũi tên) trong .material-dropdown đang mở

    const $list      = document.getElementById('list-product');
    const $btnRec    = document.getElementById('btn-record');
    const $btnEdit   = document.getElementById('btn-edit');
    const $histBody  = document.getElementById('history-tbody');
    const $histPager = document.getElementById('history-pagination');
    const $hfDateFrom = document.getElementById('hf-date-from');
    const $hfDateTo   = document.getElementById('hf-date-to');
    const $hfPageSize = document.getElementById('hf-page-size');
    const $hfReset    = document.getElementById('hf-reset');
    const $hfCount    = document.getElementById('hf-count');
    const $hfKeyword    = document.getElementById('hf-keyword');
    const $hfKeywordBtn = document.getElementById('hf-keyword-btn');
    const $hfKeywordPop = document.getElementById('hf-keyword-pop');
    const $banner    = document.getElementById('edit-batch-banner');
    const $bannerLb  = document.getElementById('edit-batch-label');
    const $btnCancel = document.getElementById('cancel-edit-batch');
    const $dateTime  = document.getElementById('record-datetime');
    const $totalCost = document.querySelector('#total-list .total-list-value-cost');
    const $totalVal  = document.querySelector('#total-list-value .total-list-value-amount');

    // ---------- Journal Entry (form GHI BÚT TOÁN KẾ TOÁN) ----------
    const JE_DEFAULTS = { debit: '155', credit: '152' };
    const $jeDebit  = document.getElementById('je-debit');
    const $jeCredit = document.getElementById('je-credit');
    const $jeAmount = document.getElementById('je-amount');
    let jeAmountTouched = false;

    function jeFormatAmount(n) {
        const v = Math.round(Number(n) || 0);
        return v > 0 ? v.toLocaleString('en-US') : '';
    }
    function jeParseAmount(s) {
        return Number(String(s == null ? '' : s).replace(/[^\d.-]/g, '')) || 0;
    }
    function jeSyncAmountFromTotals() {
        if (jeAmountTouched || !$jeAmount) return;
        const cost = $totalCost ? Number(String($totalCost.textContent || '').replace(/[^\d.-]/g, '')) || 0 : 0;
        $jeAmount.value = jeFormatAmount(cost);
    }
    function jePayload() {
        const p = {
            je_debit:  ($jeDebit  && $jeDebit.value.trim())  || '',
            je_credit: ($jeCredit && $jeCredit.value.trim()) || '',
            je_amount: jeParseAmount($jeAmount && $jeAmount.value)
        };
        if (window.JE && window.JE.collectEntries) p.je_entries = JSON.stringify(window.JE.collectEntries());
        return p;
    }
    function setupJe() {
        if (!$jeDebit || !$jeCredit || !$jeAmount) return;
        if (!$jeDebit.value)  $jeDebit.value  = JE_DEFAULTS.debit;
        if (!$jeCredit.value) $jeCredit.value = JE_DEFAULTS.credit;
        $jeAmount.addEventListener('input', () => { jeAmountTouched = true; });
        $jeAmount.addEventListener('blur', () => {
            $jeAmount.value = jeFormatAmount(jeParseAmount($jeAmount.value));
        });
        $jeAmount.addEventListener('focus', () => {
            const n = jeParseAmount($jeAmount.value);
            $jeAmount.value = n > 0 ? String(n) : '';
        });
        jeSyncAmountFromTotals();
    }

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

    // Gọi sang module customer_packaging (nghiệp vụ "Xuất dùng" xử lý tại chỗ, xem openCpkgModal).
    const CPKG_BASE = '?mod=customer_packaging&controllers=customer_packaging&action=';
    function postCpkg(action, payload) {
        const body = new URLSearchParams();
        Object.keys(payload || {}).forEach(k => body.append(k, payload[k]));
        return fetch(CPKG_BASE + action, {
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

    // Thông báo ngắn tự ẩn (không cần bấm OK) — dùng cho phản hồi "+ Lịch" ở modal cảnh báo tồn NVL.
    function toast(msg) {
        const t = document.createElement('div');
        t.className = 'ip-toast';
        t.textContent = msg;
        document.body.appendChild(t);
        requestAnimationFrame(() => t.classList.add('show'));
        setTimeout(() => {
            t.classList.remove('show');
            setTimeout(() => t.remove(), 300);
        }, 2200);
    }

    function formatMoney(v) {
        const n = Math.round(Number(v) || 0);
        return n.toLocaleString('en-US') + ' đ';
    }

    function parseMoney(s) {
        return Number(String(s || '').replace(/[^\d.-]/g, '')) || 0;
    }

    function round2(n) {
        const x = Number(n) || 0;
        return Math.round(x * 100) / 100;
    }

    function formatQR2(v) { return round2(v).toFixed(2); }

    function formatTotalQty(v) {
        const n = Number(v) || 0;
        const r = Math.round(n * 100) / 100;
        return Number.isInteger(r) ? String(r) : r.toFixed(2).replace(/\.?0+$/, '');
    }

    // ---------- Datetime picker ----------
    function pad2(n) { return String(n).padStart(2, '0'); }
    function nowLocalValue() {
        const d = new Date();
        return d.getFullYear() + '-' + pad2(d.getMonth() + 1) + '-' + pad2(d.getDate())
             + 'T' + pad2(d.getHours()) + ':' + pad2(d.getMinutes()) + ':' + pad2(d.getSeconds());
    }
    function parseLocalValue(v) {
        const m = /^(\d{4})-(\d{2})-(\d{2})T(\d{2}):(\d{2})(?::(\d{2}))?$/.exec(String(v || ''));
        if (!m) return null;
        return { y: m[1], M: m[2], d: m[3], h: m[4], min: m[5], s: m[6] || '00' };
    }
    function pickerToMysql() {
        const p = parseLocalValue($dateTime.value);
        if (!p) return '';
        return p.y + '-' + p.M + '-' + p.d + ' ' + p.h + ':' + p.min + ':' + p.s;
    }
    function pickerToDateYMD() {
        const p = parseLocalValue($dateTime.value);
        if (!p) return '';
        return p.y + '-' + p.M + '-' + p.d;
    }
    function mysqlToLocalValue(s) {
        const m = /^(\d{4})-(\d{2})-(\d{2})[\sT](\d{2}):(\d{2}):(\d{2})$/.exec(String(s || ''));
        if (!m) return null;
        return `${m[1]}-${m[2]}-${m[3]}T${m[4]}:${m[5]}:${m[6]}`;
    }

    // ---------- Render product-item (FAQ-style materials) ----------
    // persisted=true → dòng đã có trong product_materials (live-save .input-total-qty được phép).
    // persisted=false → dòng mới user vừa "Thêm thành phần", chỉ lưu khi bấm "Cập nhật định mức".
    function buildMaterialHTML(mat, productQty, persisted) {
        const qr        = Number(mat.quantity_required) || 0;
        const price     = Number(mat.purchase_price)    || 0;
        const mid       = mat.material_id ? mat.material_id : 0;
        const totalQty  = qr * productQty;
        const totalCost = totalQty * price;
        const hasQty    = productQty > 0;
        return `
            <li class="material-item" draggable="false"
                data-material-id="${mid}"
                data-classification="${escapeHtml(mat.classification || '')}"
                data-quantity-required="${qr}"
                data-purchase-price="${price}"
                data-persisted="${persisted ? 1 : 0}">
                <span class="material-drag-handle" title="Kéo để sắp xếp">⋮⋮</span>
                <div class="name-material">
                    <div class="material-name-field">
                        <input type="text" class="input-material-name"
                               value="${escapeHtml(mat.material_name || '')}"
                               placeholder="Tên thành phần" autocomplete="off">
                        <ul class="material-dropdown"></ul>
                    </div>
                    <input type="text" class="input-formula" value="${hasQty ? formatQR2(qr) + 'x' + productQty : ''}" readonly>
                    <input type="text" class="input-total-qty" value="${totalQty ? formatTotalQty(totalQty) : ''}">
                    <input type="text" class="input-cost" value="${formatMoney(totalCost)}">
                </div>
                <button type="button" class="btn-remove-material" title="Loại bỏ thành phần">×</button>
            </li>
        `;
    }

    // TASK 3a: dòng cố định "Chi phí sản xuất" đặt cuối .list-material.
    // Vẫn là .material-item (để cộng vào Tổng vốn) nhưng data-material-id=0 +
    // class .production-cost-item → KHÔNG ghi product_materials, KHÔNG gọi giá vốn 2 lớp.
    // .input-cost = qty × prodCostRate; sửa .input-cost = đổi đơn giá (lưu config).
    function buildProductionCostRow(productQty) {
        const qty  = Number(productQty) || 0;
        const cost = qty * prodCostRate;
        return `
            <li class="material-item production-cost-item" draggable="false"
                data-material-id="0"
                data-line-cost="${cost}">
                <span class="material-drag-handle" style="visibility:hidden;">⋮⋮</span>
                <div class="name-material">
                    <div class="material-name-field">
                        <input type="text" class="input-material-name" value="Chi phí sản xuất"
                               readonly title="Chi phí sản xuất trung bình (mặt bằng, nhân công, điện nước...)">
                    </div>
                    <input type="text" class="input-formula" value="${qty ? '1x' + qty : ''}" readonly>
                    <input type="text" class="input-total-qty" value="${qty ? formatTotalQty(qty) : ''}" readonly>
                    <input type="text" class="input-cost" value="${formatMoney(cost)}"
                           title="Đơn giá ${formatMoney(prodCostRate)}/đơn vị — sửa để đổi đơn giá">
                </div>
                <button type="button" class="btn-remove-production-cost" title="Gỡ khỏi sản phẩm này (vd hàng mẫu không tính chi phí sản xuất)">×</button>
            </li>
        `;
    }

    function addProductItem(p) {
        const pid        = p.product_id;
        const productQty = Number(p.quantity) || 0;
        const sysPrice   = Number(p.system_price) || 0;
        const materials  = Array.isArray(p.materials) ? p.materials : [];

        // "Chi phí sản xuất" bị gỡ khỏi sản phẩm này (vd hàng mẫu) -> không dựng dòng.
        const matsHtml = materials.map(m => buildMaterialHTML(m, productQty, true)).join('')
            + (p.exclude_production_cost ? '' : buildProductionCostRow(productQty));

        const li = document.createElement('li');
        li.className = 'product-item';
        li.setAttribute('data-product-id', pid);
        li.setAttribute('data-system-price', sysPrice);
        li.setAttribute('data-quantity', productQty);
        li.innerHTML = `
            <button type="button" class="btn-remove-product" title="Xóa">×</button>
            <div class="wp-top-product">
                <div class="name-product">
                    <p>${escapeHtml(p.product_name || '')}</p>
                </div>
                <input type="text" class="input-quantity" value="${productQty}" placeholder="SL" readonly>
            </div>
            <div class="interpretation">
                <p class="interp-text">Nhập giá vốn sản xuất ${escapeHtml(p.product_name || '')}</p>
            </div>
            <div class="material-faq">
                <button type="button" class="material-faq-header" aria-expanded="false">
                    <span class="faq-label">Chi tiết nguyên liệu (${materials.length})</span>
                    <span class="faq-icon">▾</span>
                </button>
                <div class="material-faq-body">
                    <ul class="list-material">${matsHtml}</ul>
                    <div class="material-actions">
                        <button type="button" class="btn-add-material">+ Thêm thành phần</button>
                        <button type="button" class="btn-update-norm" style="display:none;">Cập nhật định mức</button>
                    </div>
                </div>
            </div>
            <div class="sub-total">
                <div class="sub-total-group sub-total-group-cost">
                    <div class="title-total"><p>Tổng vốn:</p></div>
                    <div class="total"><p>0 đ</p></div>
                </div>
                <div class="sub-total-group sub-total-group-value">
                    <div class="value-total"><p>Giá trị:</p></div>
                    <div class="total"><p>0 đ</p><span class="value-warning-dot" title=""></span></div>
                </div>
            </div>
            ${p.reminder_note ? `
            <div class="product-reminder">
                <span class="product-reminder-label">Ghi chú:</span>
                <span class="product-reminder-text" contenteditable="true">${escapeHtml(p.reminder_note)}</span>
                <button type="button" class="product-reminder-del" title="Xóa ghi chú">×</button>
            </div>` : ''}
            ${Array.isArray(p.customer_pkg) && p.customer_pkg.length ? `
            <div class="product-cpkg-warning">
                <i class="fa-solid fa-triangle-exclamation"></i>
                Trừ bao bì khách hàng nếu có
                <span class="product-cpkg-list">(${p.customer_pkg.map(c => `${escapeHtml(c.customer_name)} — ${escapeHtml(c.packaging_name)}`).join(', ')})</span>
                <a class="product-cpkg-link" href="?mod=customer_packaging&controllers=customer_packaging&action=customer_packaging" target="_blank">Mở QL bao bì khách hàng ↗</a>
            </div>` : ''}
        `;

        li.querySelector('.btn-remove-product').addEventListener('click', () => {
            // Đang SỬA nhóm lịch sử: chỉ bỏ dòng khỏi form (lưu bằng nút "Sửa"),
            // không đụng phiếu nhập thành phẩm của ngày.
            if (editingBatchKey) { li.remove(); recomputeAllTotals(); return; }
            if (!confirm('Gỡ "' + (p.product_name || '') + '" khỏi ngày này?\n\n'
                + 'Sẽ XÓA phiếu nhập thành phẩm của sản phẩm trong ngày và TRỪ LẠI tồn kho. '
                + 'Sản phẩm cũng biến mất ở trang "Nhập thành phẩm sản xuất". Không hồi phục được.')) return;
            postForm('remove_day_product', {
                product_id: pid,
                date: pickerToDateYMD(),
                source: 'investment_products'
            }).then(res => {
                if (!res || !res.success) { alert((res && res.message) || 'Gỡ thất bại.'); return; }
                li.remove();
                recomputeAllTotals();
            }).catch(() => alert('Lỗi kết nối khi gỡ.'));
        });

        // Cảnh báo trừ bao bì khách hàng: mở modal xử lý tại chỗ (nghiệp vụ "Xuất dùng")
        // thay vì redirect sang trang QL bao bì khách hàng.
        const $cpkgLink = li.querySelector('.product-cpkg-link');
        if ($cpkgLink) {
            $cpkgLink.addEventListener('click', (e) => {
                e.preventDefault();
                openCpkgModal(pid, p.product_name || '', productQty, Array.isArray(p.customer_pkg) ? p.customer_pkg : []);
            });
        }

        // Ghi chú "Điểm nhắc trước nhập sản xuất": sửa tại chỗ (blur lưu), × xóa —
        // không có ghi chú thì không hiển thị khối này (chỉ thiết lập mới từ app head).
        const $remind = li.querySelector('.product-reminder');
        if ($remind) {
            const $remindText = $remind.querySelector('.product-reminder-text');
            const $remindDel  = $remind.querySelector('.product-reminder-del');
            const RP_ACT = '?mod=reminder_points&controllers=reminder_points&action=';
            const rpPostForm = (action, payload) => {
                const body = new URLSearchParams();
                Object.keys(payload || {}).forEach(k => body.append(k, payload[k]));
                return fetch(RP_ACT + action, { method: 'POST', body }).then(r => r.json());
            };
            $remindText.addEventListener('blur', () => {
                const note = $remindText.textContent.trim();
                rpPostForm('rp_pre_input_save', { product_id: pid, note }).then(() => {
                    if (note === '') $remind.remove();
                });
            });
            $remindText.addEventListener('keydown', (e) => {
                if (e.key === 'Enter') { e.preventDefault(); $remindText.blur(); }
            });
            $remindDel.addEventListener('click', () => {
                rpPostForm('rp_pre_input_delete', { product_id: pid }).then(() => {
                    $remind.remove();
                });
            });
        }

        $list.appendChild(li);
        recomputeProductSubtotals(li);
        // Tính giá vốn 2 lớp cho từng NVL đã có sẵn (không cảnh báo khi nạp danh sách).
        // Bỏ qua dòng "Chi phí sản xuất" (không phải material).
        li.querySelectorAll('.material-item:not(.production-cost-item)').forEach($li => applyIssueCost($li, false));

        // TASK 2: chỉ khi Ghi mới (không phải đang Sửa batch lịch sử) mới ưu tiên nạp lại
        // total_qty NVL từ lần sản xuất trước có sản lượng tương đồng (±3%).
        if (!editingBatchKey && productQty > 0) {
            applySimilarProductionIssue(li, productQty);
        }
    }

    function clearList() { $list.innerHTML = ''; }

    function renderListFrom(items) {
        clearList();
        (items || []).forEach(it => addProductItem(it));
        recomputeAllTotals();
    }

    // ---------- Recompute helpers ----------
    function recomputeMaterialRow($li, productQty) {
        const qr    = Number($li.getAttribute('data-quantity-required')) || 0;
        const price = Number($li.getAttribute('data-purchase-price')) || 0;
        const totalQty  = qr * productQty;
        const totalCost = totalQty * price;
        $li.querySelector('.input-formula').value   = formatQR2(qr) + 'x' + productQty;
        $li.querySelector('.input-total-qty').value = formatTotalQty(totalQty);
        $li.querySelector('.input-cost').value      = formatMoney(totalCost);
    }

    // Đọc total_qty từ chính .input-total-qty để tôn trọng đúng giá trị user đã nhập
    // (không reformat / không tính lại từ qr × productQty — tránh nhảy số).
    function readMaterialTotalQty($li) {
        const $inp = $li.querySelector('.input-total-qty');
        return parseFloat($inp ? $inp.value : '0') || 0;
    }

    // ---------- Giá vốn xuất NVL theo MÔ HÌNH 2 LỚP GIÁ (server-side) ----------
    // Mỗi .material-item lưu data-line-cost (giá vốn đã tách lớp) + data-blended-price
    // (giá bình quân = cost/qty). getLineCost() ưu tiên giá trị server; fallback qty×giá.
    function getLineCost($li, totalQty) {
        const stored = $li.getAttribute('data-line-cost');
        if (stored !== null && stored !== '' && isFinite(Number(stored))) return Number(stored);
        const price = Number($li.getAttribute('data-purchase-price')) || 0;
        return totalQty * price;
    }

    function clearLineCost($li) {
        $li.removeAttribute('data-line-cost');
        $li.removeAttribute('data-blended-price');
    }

    function fmtSplitQty(v) {
        const n = Number(v) || 0;
        const r = Math.round(n * 100) / 100;
        return Number.isInteger(r) ? String(r) : r.toFixed(2).replace(/\.?0+$/, '');
    }

    // Gọi endpoint tính giá 2 lớp cho 1 material-item, set .input-cost + data-line-cost,
    // rồi recompute tổng. notify=true → hiện cảnh báo tách giá khi qt > tồn-trước-nhập.
    function applyIssueCost($li, notify) {
        const mid      = parseInt($li.getAttribute('data-material-id'), 10) || 0;
        const totalQty = readMaterialTotalQty($li);
        const $product = $li.closest('.product-item');
        if (mid <= 0 || !(totalQty > 0)) {
            clearLineCost($li);
            recomputeMaterialCostFromTotal($li);
            if ($product) recomputeProductSubtotals($product);
            recomputeGrandTotals();
            return;
        }
        const seq = (Number($li.getAttribute('data-cost-seq')) || 0) + 1;
        $li.setAttribute('data-cost-seq', String(seq));

        postForm('compute_material_issue_cost', {
            material_id: mid,
            qty:         totalQty,
            as_of:       pickerToMysql()
        }).then(res => {
            // Bỏ qua response cũ nếu user đã đổi tiếp (race).
            if ((Number($li.getAttribute('data-cost-seq')) || 0) !== seq) return;
            if (!res || !res.success || !res.data) return;
            const d = res.data;
            $li.setAttribute('data-line-cost', String(d.total_cost));
            $li.setAttribute('data-blended-price', String(d.unit_price));
            const $c = $li.querySelector('.input-cost');
            if ($c) $c.value = formatMoney(d.total_cost);
            if ($product) recomputeProductSubtotals($product);
            recomputeGrandTotals();

            if (notify && d.warn) {
                const unit = d.unit ? (' ' + d.unit) : '';
                alert(
                    'Tách giá vốn "' + (d.material_name || '') + '":\n' +
                    '• Lớp cũ (lần nhập trước): ' + fmtSplitQty(d.q_old) + unit + ' × ' + formatMoney(d.price_old) + '\n' +
                    '• Lớp mới (lần nhập gần đây): ' + fmtSplitQty(d.q_new) + unit + ' × ' + formatMoney(d.price_new) + '\n' +
                    '= ' + formatMoney(d.total_cost)
                );
            }
        });
    }

    function applyIssueCostAll(notify) {
        $list.querySelectorAll('.material-item:not(.production-cost-item)').forEach($li => applyIssueCost($li, notify));
    }

    // ---------- TASK 2: nạp total_qty NVL từ lần sản xuất tương đồng (±3% sản lượng) ----------
    // Ưu tiên truy vấn ngược finished_product_production_data theo iqt (= productQty).
    // Tìm thấy → ghi đè .input-total-qty của các material-item theo material_id rồi tính
    // lại định mức + giá vốn 2 lớp. Không tìm thấy → giữ nguyên cách load hiện tại.
    function applySimilarProductionIssue($product, productQty) {
        const pid = parseInt($product.getAttribute('data-product-id'), 10) || 0;
        if (pid <= 0 || !(productQty > 0)) return;
        postForm('get_similar_production_materials', {
            product_id:   pid,
            quantity:     productQty,
            exclude_date: pickerToDateYMD()
        }).then(res => {
            if (!res || !res.success || !res.matched) return;
            const map = {};
            (res.materials || []).forEach(m => { map[parseInt(m.material_id, 10)] = Number(m.total_qty); });
            let applied = 0;
            $product.querySelectorAll('.material-item:not(.production-cost-item)').forEach($li => {
                const mid = parseInt($li.getAttribute('data-material-id'), 10) || 0;
                if (!mid) return;
                // Phân loại NVL: 'Bao bì trong' và 'Nhãn' dùng đúng SL sản xuất của SP
                // (1 SP = 1 đơn vị), KHÔNG lấy theo lần sản xuất tương đồng. Các loại còn
                // lại ('Nguyên liệu', 'Bao bì ngoài', …) giữ logic cũ: lấy total_qty từ lần
                // sản xuất gần đúng ±3%.
                const cls = ($li.getAttribute('data-classification') || '').trim();
                const usesProductQty = (cls === 'Bao bì trong' || cls === 'Nhãn');
                let newTotal;
                if (usesProductQty) {
                    newTotal = productQty;
                } else {
                    if (!(mid in map)) return;
                    newTotal = map[mid];
                }
                if (!isFinite(newTotal) || newTotal < 0) return;
                const newQR = round2(newTotal / productQty);
                $li.setAttribute('data-quantity-required', newQR);
                const price = Number($li.getAttribute('data-purchase-price')) || 0;
                const $inp = $li.querySelector('.input-total-qty');
                if ($inp) $inp.value = formatTotalQty(newTotal);
                const $f = $li.querySelector('.input-formula');
                if ($f) $f.value = formatQR2(newQR) + 'x' + productQty;
                const $c = $li.querySelector('.input-cost');
                if ($c) $c.value = formatMoney(newTotal * price);
                clearLineCost($li);
                applyIssueCost($li, false); // seq guard đảm bảo response mới nhất thắng
                applied++;
            });
            if (applied > 0) {
                recomputeAllTotals();
                const $lbl = $product.querySelector('.faq-label');
                if ($lbl) {
                    $lbl.title = 'Định mức NVL lấy theo lần sản xuất ngày ' + (res.source_date || '?')
                        + ' (SL ' + formatTotalQty(res.source_quantity || 0)
                        + ', lệch ' + (res.deviation != null ? res.deviation : 0) + '%).';
                }
            }
        });
    }

    // TASK 3a: cập nhật mọi dòng "Chi phí sản xuất" theo prodCostRate hiện tại.
    function refreshProductionCostRows() {
        $list.querySelectorAll('.product-item').forEach($p => {
            const qty = Number($p.getAttribute('data-quantity')) || 0;
            $p.querySelectorAll('.production-cost-item').forEach($li => {
                const cost = qty * prodCostRate;
                $li.setAttribute('data-line-cost', String(cost));
                const $c = $li.querySelector('.input-cost');
                if ($c) {
                    $c.value = formatMoney(cost);
                    $c.title = 'Đơn giá ' + formatMoney(prodCostRate) + '/đơn vị — sửa để đổi đơn giá';
                }
            });
        });
        recomputeAllTotals();
    }

    // TASK 1.2: cảnh báo tỉ lệ (Giá trị - Tổng vốn) / Tổng vốn = k bằng chấm tròn bên
    // phải "Giá trị". k<0.15 đỏ · 0.15<=k<0.25 vàng · 0.25<=k<=1 không cảnh báo · k>1 xanh.
    function updateValueWarningDot($product, a, b) {
        const $dot = $product.querySelector('.value-warning-dot');
        if (!$dot) return;
        $dot.className = 'value-warning-dot';
        $dot.removeAttribute('title');
        if (!(a > 0)) return; // chưa xác định được tỉ lệ khi tổng vốn = 0
        const k = (b - a) / a;
        const pct = (k * 100).toFixed(1) + '%';
        if (k < 0.15) {
            $dot.classList.add('dot-red');
            $dot.title = 'Giá trị chỉ cao hơn vốn ' + pct + ' — biên lợi nhuận thấp.';
        } else if (k < 0.25) {
            $dot.classList.add('dot-yellow');
            $dot.title = 'Giá trị cao hơn vốn ' + pct + ' — biên lợi nhuận trung bình.';
        } else if (k <= 1) {
            // trong khoảng bình thường, không cảnh báo
        } else {
            $dot.classList.add('dot-green');
            $dot.title = 'Giá trị cao hơn vốn ' + pct + ' — biên lợi nhuận cao.';
        }
    }

    function recomputeProductSubtotals($product) {
        const productQty = Number($product.getAttribute('data-quantity')) || 0;
        const sysPrice   = Number($product.getAttribute('data-system-price')) || 0;
        let subtotalCost = 0;
        $product.querySelectorAll('.material-item').forEach($li => {
            const totalQty = readMaterialTotalQty($li);
            subtotalCost += getLineCost($li, totalQty);
        });
        const subtotalValue = productQty * sysPrice;
        const totals = $product.querySelectorAll('.sub-total .total p');
        if (totals[0]) totals[0].textContent = formatMoney(subtotalCost);
        if (totals[1]) totals[1].textContent = formatMoney(subtotalValue);
        updateValueWarningDot($product, subtotalCost, subtotalValue);
    }

    function recomputeGrandTotals() {
        let totalCost = 0, totalValue = 0;
        $list.querySelectorAll('.product-item').forEach($p => {
            const totals = $p.querySelectorAll('.sub-total .total p');
            if (totals[0]) totalCost  += parseMoney(totals[0].textContent);
            if (totals[1]) totalValue += parseMoney(totals[1].textContent);
        });
        if ($totalCost) $totalCost.textContent = formatMoney(totalCost);
        if ($totalVal)  $totalVal.textContent  = formatMoney(totalValue);
        jeSyncAmountFromTotals();
    }

    // Hàm tổng hợp: gọi khi bất kỳ thay đổi nào ảnh hưởng đến số liệu hiển thị.
    function recomputeAllTotals() {
        $list.querySelectorAll('.product-item').forEach($p => recomputeProductSubtotals($p));
        recomputeGrandTotals();
    }

    function readGrandTotals() {
        return {
            cost_price:  $totalCost ? parseMoney($totalCost.textContent) : 0,
            goods_value: $totalVal  ? parseMoney($totalVal.textContent)  : 0,
        };
    }

    // Build payload items[] có materials từ DOM hiện tại — dùng cho cả Ghi và Sửa.
    // Mỗi item bao gồm product_qty + total_cost + expected_value đọc thẳng từ
    // .sub-total .total p (vị trí 1 = Tổng vốn, vị trí 2 = Giá trị) để khớp DOM 1:1.
    function collectItemsForPayload() {
        const items = [];
        $list.querySelectorAll('.product-item').forEach($p => {
            const pid = parseInt($p.getAttribute('data-product-id'), 10);
            if (!pid) return;
            const productQty = Number($p.getAttribute('data-quantity')) || 0;
            const materials = [];
            $p.querySelectorAll('.material-item').forEach($li => {
                const mid   = parseInt($li.getAttribute('data-material-id'), 10);
                if (!mid) return;
                const price     = Number($li.getAttribute('data-purchase-price')) || 0;
                const total_qty = readMaterialTotalQty($li);
                const total_cost = getLineCost($li, total_qty);  // giá vốn 2 lớp (nếu có)
                const blended    = Number($li.getAttribute('data-blended-price'));
                const unit_price = (isFinite(blended) && blended > 0) ? blended : price;
                materials.push({
                    material_id: mid,
                    total_qty:   total_qty,
                    unit_price:  unit_price,
                    total_cost:  total_cost,
                });
            });

            const totals = $p.querySelectorAll('.sub-total .total p');
            const total_cost     = totals[0] ? parseMoney(totals[0].textContent) : 0;
            const expected_value = totals[1] ? parseMoney(totals[1].textContent) : 0;

            items.push({
                product_id:     pid,
                product_qty:    productQty,
                total_cost:     total_cost,
                expected_value: expected_value,
                materials:      materials,
            });
        });
        return items;
    }

    // ---------- Edit material total-qty (the "144" input) ----------
    $list.addEventListener('change', (e) => {
        const $inp = e.target.closest('.input-total-qty');
        if (!$inp) return;
        const $li      = $inp.closest('.material-item');
        const $product = $inp.closest('.product-item');
        if (!$li || !$product) return;

        const productQty = Number($product.getAttribute('data-quantity')) || 0;
        if (productQty <= 0) {
            alert('Số lượng sản phẩm không hợp lệ — chưa có dữ liệu nhập kho cho ngày này.');
            recomputeMaterialRow($li, productQty);
            return;
        }
        const newTotal = Number($inp.value);
        if (!isFinite(newTotal) || newTotal < 0) {
            recomputeMaterialRow($li, productQty);
            return;
        }
        // qty_required mới = total / quantity (tròn 2 chữ số) — chỉ dùng cho .input-formula
        // và lưu DB. KHÔNG ghi đè .input-total-qty (giữ đúng giá trị user vừa nhập).
        const newQR = round2(newTotal / productQty);
        $li.setAttribute('data-quantity-required', newQR);
        const price = Number($li.getAttribute('data-purchase-price')) || 0;
        $li.querySelector('.input-formula').value = formatQR2(newQR) + 'x' + productQty;
        // Hiển thị tạm qty×giá; clear override cũ rồi gọi server tính giá vốn 2 lớp.
        $li.querySelector('.input-cost').value    = formatMoney(newTotal * price);
        clearLineCost($li);
        recomputeAllTotals();
        applyIssueCost($li, true);

        // Dòng mới (chưa có trong product_materials) chỉ lưu khi bấm "Cập nhật định mức".
        const persisted = $li.getAttribute('data-persisted') === '1';
        const pid = parseInt($product.getAttribute('data-product-id'), 10);
        const mid = parseInt($li.getAttribute('data-material-id'), 10);
        if (!persisted || !mid) return;
        postForm('update_material_qty', {
            product_id:        pid,
            material_id:       mid,
            quantity_required: newQR
        }).then(res => {
            if (!res || !res.success) {
                alert((res && res.message) ? res.message : 'Không lưu được giá trị.');
            }
        });
    });

    // ---------- Edit material total-cost → cập nhật purchase_price ----------
    // newPrice = input-cost / input-total-qty. Confirm trước khi gọi API.
    $list.addEventListener('change', (e) => {
        const $inp = e.target.closest('.input-cost');
        if (!$inp) return;
        const $li = $inp.closest('.material-item');
        if (!$li) return;

        // TASK 3a: dòng "Chi phí sản xuất" → sửa .input-cost = đổi đơn giá (lưu config).
        if ($li.classList.contains('production-cost-item')) {
            const $product = $li.closest('.product-item');
            const qty = Number($product ? $product.getAttribute('data-quantity') : 0) || 0;
            const newCost = parseMoney($inp.value);
            if (!isFinite(newCost) || newCost < 0 || qty <= 0) {
                $inp.value = formatMoney(qty * prodCostRate);
                if (qty <= 0) alert('Số lượng sản phẩm không hợp lệ.');
                return;
            }
            const newRate = Math.round(newCost / qty);
            if (newRate === prodCostRate) { $inp.value = formatMoney(qty * prodCostRate); return; }
            if (!confirm('Đổi đơn giá chi phí sản xuất thành ' + formatMoney(newRate) + '/đơn vị?\n(áp dụng cho tất cả sản phẩm)')) {
                $inp.value = formatMoney(qty * prodCostRate);
                return;
            }
            const oldRate = prodCostRate;
            prodCostRate = newRate;
            refreshProductionCostRows();
            postForm('save_production_cost_rate', { rate: newRate }).then(res => {
                if (!res || !res.success) {
                    alert((res && res.message) ? res.message : 'Không lưu được đơn giá.');
                    prodCostRate = oldRate;
                    refreshProductionCostRows();
                }
            });
            return;
        }

        const totalQty = readMaterialTotalQty($li);
        const oldPrice = Number($li.getAttribute('data-purchase-price')) || 0;
        const oldCost  = totalQty * oldPrice;

        const newCost = parseMoney($inp.value);
        if (!isFinite(newCost) || newCost < 0 || totalQty <= 0) {
            $inp.value = formatMoney(oldCost);
            if (totalQty <= 0) alert('Tổng số lượng nguyên liệu không hợp lệ.');
            return;
        }

        const newPrice = Math.round(newCost / totalQty);
        if (newPrice === oldPrice) {
            // Giá/đơn vị không đổi sau khi làm tròn (không cần xác nhận/gọi API đổi giá
            // mua), nhưng vẫn tôn trọng đúng tổng giá vốn user vừa nhập thay vì âm thầm
            // revert về oldCost tính lại từ qty × giá cũ.
            $li.setAttribute('data-line-cost', String(newCost));
            $inp.value = formatMoney(newCost);
            recomputeAllTotals();
            return;
        }

        const matNameEl = $li.querySelector('.input-material-name');
        const matName   = matNameEl ? matNameEl.value.trim() : '';
        const msg = 'Cập nhật thay đổi giá mua ' + matName + ' thành ' + formatMoney(newPrice) + '?';
        if (!confirm(msg)) {
            $inp.value = formatMoney(oldCost);
            return;
        }

        $li.setAttribute('data-purchase-price', newPrice);
        // User chỉnh tay tổng giá vốn → tôn trọng đúng giá trị này (ghi đè 2 lớp tự động).
        $li.setAttribute('data-line-cost', String(newCost));
        $li.setAttribute('data-blended-price', String(newPrice));
        $inp.value = formatMoney(newCost);
        recomputeAllTotals();

        const mid = parseInt($li.getAttribute('data-material-id'), 10);
        postForm('update_material_purchase_price', {
            material_id: mid,
            price:       newPrice
        }).then(res => {
            if (!res || !res.success) {
                alert((res && res.message) ? res.message : 'Không lưu được giá mua.');
                $li.setAttribute('data-purchase-price', oldPrice);
                $inp.value = formatMoney(oldCost);
                recomputeAllTotals();
            }
        });
    });

    // ---------- FAQ toggle ----------
    $list.addEventListener('click', (e) => {
        const $hdr = e.target.closest('.material-faq-header');
        if (!$hdr) return;
        const $faq = $hdr.parentElement;
        const open = $faq.classList.toggle('open');
        $hdr.setAttribute('aria-expanded', open ? 'true' : 'false');
    });

    /* ============================================================
     *   MATERIAL EDITING (x / Thêm thành phần / kéo sắp xếp / đổi NVL)
     *   Bất kỳ thay đổi cấu trúc nào (xóa / thêm / kéo / đổi tên) đều hiện
     *   button "Cập nhật định mức" để user đồng bộ product_materials.
     * ============================================================ */
    function markProductDirty($product) {
        if (!$product) return;
        const btn = $product.querySelector('.btn-update-norm');
        if (btn) btn.style.display = '';
    }

    function updateFaqCount($product) {
        if (!$product) return;
        const n   = $product.querySelectorAll('.material-item:not(.production-cost-item)').length;
        const lbl = $product.querySelector('.faq-label');
        if (lbl) lbl.textContent = 'Chi tiết nguyên liệu (' + n + ')';
    }

    // Tính lại formula + cost của 1 material-item từ .input-total-qty và data-purchase-price hiện tại.
    function recomputeMaterialCostFromTotal($li) {
        const $product   = $li.closest('.product-item');
        const productQty = Number($product.getAttribute('data-quantity')) || 0;
        const totalQty   = readMaterialTotalQty($li);
        const price      = Number($li.getAttribute('data-purchase-price')) || 0;
        const qr         = productQty > 0 ? round2(totalQty / productQty) : 0;
        $li.setAttribute('data-quantity-required', qr);
        const $f = $li.querySelector('.input-formula');
        if ($f) $f.value = productQty > 0 ? formatQR2(qr) + 'x' + productQty : '';
        const $c = $li.querySelector('.input-cost');
        if ($c) $c.value = formatMoney(totalQty * price);
    }

    // ---------- Material name suggestion dropdown ----------
    function hideMatDropdown($dd) {
        if (!$dd) return;
        $dd.classList.remove('active');
        $dd.innerHTML = '';
        matActiveIdx = -1;
    }

    function hideAllMatDropdowns() {
        $list.querySelectorAll('.material-dropdown.active').forEach(hideMatDropdown);
    }

    function renderMatDropdown($dd, items) {
        matActiveIdx = -1;
        if (!items.length) {
            $dd.innerHTML = '<li class="empty">Không tìm thấy NVL</li>';
        } else {
            $dd.innerHTML = items.map(it =>
                `<li data-id="${it.id}" data-price="${Number(it.purchase_price) || 0}" data-classification="${escapeHtml(it.classification || '')}" data-name="${escapeHtml(it.material_name)}">${escapeHtml(it.material_name)}</li>`
            ).join('');
        }
        $dd.classList.add('active');
    }

    const runMaterialNameSearch = debounce(function ($input) {
        const $field = $input.closest('.material-name-field');
        const $dd    = $field ? $field.querySelector('.material-dropdown') : null;
        if (!$dd) return;
        const kw = $input.value.trim();
        if (kw === '') { hideMatDropdown($dd); return; }
        postForm('search_materials', { keyword: kw }).then(res => {
            renderMatDropdown($dd, (res && res.data) || []);
        });
    }, 220);

    function pickMaterial($opt) {
        const $li = $opt.closest('.material-item');
        if (!$li) return;
        const id    = parseInt($opt.getAttribute('data-id'), 10) || 0;
        const name  = $opt.getAttribute('data-name') || '';
        const price = Number($opt.getAttribute('data-price')) || 0;
        $li.setAttribute('data-material-id', id);
        $li.setAttribute('data-classification', $opt.getAttribute('data-classification') || '');
        $li.setAttribute('data-purchase-price', price);
        $li.querySelector('.input-material-name').value = name;
        hideMatDropdown($opt.closest('.material-dropdown'));
        const $product = $li.closest('.product-item');
        clearLineCost($li); // đổi NVL → bỏ giá vốn cũ, tính lại theo 2 lớp
        recomputeMaterialCostFromTotal($li);
        recomputeProductSubtotals($product);
        recomputeGrandTotals();
        markProductDirty($product);
        applyIssueCost($li, true);
        // Chọn xong → focus qua SL để user nhập luôn số lượng.
        const $qty = $li.querySelector('.input-total-qty');
        if ($qty) { $qty.focus(); $qty.select(); }
    }

    // Gõ vào ô tên → tìm gợi ý; reset material-id về 0 (buộc chọn lại từ dropdown).
    $list.addEventListener('input', (e) => {
        const $name = e.target.closest('.input-material-name');
        if (!$name) return;
        const $li = $name.closest('.material-item');
        $li.setAttribute('data-material-id', '0');
        clearLineCost($li);
        runMaterialNameSearch($name);
        markProductDirty($li.closest('.product-item'));
    });

    // Điều khiển dropdown gợi ý NVL bằng bàn phím: ArrowUp/Down di chuyển,
    // Tab/Enter chọn dòng đang active (hoặc dòng đầu nếu chưa di chuyển).
    $list.addEventListener('keydown', (e) => {
        // Enter trên SL/giá trị chỉ để chốt giá trị (kích hoạt 'change') — không được
        // làm gì khác (không thêm dòng, không submit ẩn).
        const $qtyOrCost = e.target.closest('.input-total-qty, .input-cost');
        if ($qtyOrCost) {
            if (e.key === 'Enter') { e.preventDefault(); $qtyOrCost.blur(); }
            return;
        }
        const $name = e.target.closest('.input-material-name');
        if (!$name) return;
        const $field = $name.closest('.material-name-field');
        const $dd = $field ? $field.querySelector('.material-dropdown') : null;
        if (!$dd || !$dd.classList.contains('active')) return;
        const items = $dd.querySelectorAll('li:not(.empty)');
        if (!items.length) return;
        if (e.key === 'ArrowDown') {
            e.preventDefault();
            matActiveIdx = (matActiveIdx + 1) % items.length;
        } else if (e.key === 'ArrowUp') {
            e.preventDefault();
            matActiveIdx = (matActiveIdx - 1 + items.length) % items.length;
        } else if (e.key === 'Enter' || e.key === 'Tab') {
            const idx = matActiveIdx >= 0 ? matActiveIdx : 0;
            if (items[idx]) {
                e.preventDefault();
                pickMaterial(items[idx]);
            }
            return;
        } else if (e.key === 'Escape') {
            hideMatDropdown($dd);
            return;
        } else {
            return;
        }
        items.forEach(li => li.classList.remove('active'));
        items[matActiveIdx].classList.add('active');
        items[matActiveIdx].scrollIntoView({ block: 'nearest' });
    });

    // ---------- Click: xóa thành phần / thêm thành phần / cập nhật định mức ----------
    $list.addEventListener('click', (e) => {
        // Xóa 1 thành phần
        const $rm = e.target.closest('.btn-remove-material');
        if ($rm) {
            const $li      = $rm.closest('.material-item');
            const $product = $li.closest('.product-item');
            $li.remove();
            updateFaqCount($product);
            markProductDirty($product);
            recomputeProductSubtotals($product);
            recomputeGrandTotals();
            return;
        }

        // Gỡ "Chi phí sản xuất" khỏi sản phẩm này (vd hàng mẫu) — lưu ngay (flag riêng trên
        // products, không thuộc product_materials nên không cần "Cập nhật định mức").
        const $rmCost = e.target.closest('.btn-remove-production-cost');
        if ($rmCost) {
            const $li      = $rmCost.closest('.material-item');
            const $product = $li.closest('.product-item');
            const pid      = parseInt($product.getAttribute('data-product-id'), 10) || 0;
            postForm('remove_production_cost_from_product', { product_id: pid }).then(res => {
                if (!res || !res.success) { alert((res && res.message) || 'Không gỡ được.'); return; }
                $li.remove();
                recomputeProductSubtotals($product);
                recomputeGrandTotals();
            });
            return;
        }

        // Thêm 1 thành phần (dòng trống để user nhập)
        const $add = e.target.closest('.btn-add-material');
        if ($add) {
            const $product   = $add.closest('.product-item');
            const productQty = Number($product.getAttribute('data-quantity')) || 0;
            const $listMat   = $product.querySelector('.list-material');
            $listMat.insertAdjacentHTML('beforeend', buildMaterialHTML(
                { material_id: 0, material_name: '', quantity_required: 0, purchase_price: 0 },
                productQty, false
            ));
            updateFaqCount($product);
            markProductDirty($product);
            const $newName = $listMat.lastElementChild.querySelector('.input-material-name');
            if ($newName) $newName.focus();
            return;
        }

        // Cập nhật định mức → đồng bộ product_materials
        const $upd = e.target.closest('.btn-update-norm');
        if ($upd) {
            handleUpdateNorm($upd.closest('.product-item'));
            return;
        }
    });

    function handleUpdateNorm($product) {
        if (!$product) return;
        const pid        = parseInt($product.getAttribute('data-product-id'), 10);
        const productQty = Number($product.getAttribute('data-quantity')) || 0;
        if (!pid) { alert('Thiếu mã sản phẩm.'); return; }
        if (productQty <= 0) {
            alert('Số lượng sản phẩm không hợp lệ — không thể tính định mức.');
            return;
        }

        const materials = [];
        let invalid = false;
        $product.querySelectorAll('.material-item:not(.production-cost-item)').forEach($li => {
            const mid  = parseInt($li.getAttribute('data-material-id'), 10) || 0;
            const name = ($li.querySelector('.input-material-name').value || '').trim();
            if (!mid) { if (name !== '') invalid = true; return; }
            const totalQty = readMaterialTotalQty($li);
            materials.push({ material_id: mid, quantity_required: totalQty / productQty });
        });

        if (invalid) {
            alert('Có thành phần chưa được chọn từ danh sách gợi ý. Vui lòng chọn lại.');
            return;
        }
        if (!materials.length) { alert('Cần ít nhất 1 thành phần.'); return; }

        postForm('update_product_norm', {
            product_id: pid,
            materials:  JSON.stringify(materials)
        }).then(res => {
            if (res && res.success) {
                $product.querySelectorAll('.material-item').forEach($li => $li.setAttribute('data-persisted', '1'));
                const btn = $product.querySelector('.btn-update-norm');
                if (btn) btn.style.display = 'none';
                toast('Đã cập nhật định mức.');
            } else {
                alert(res && res.message ? res.message : 'Không cập nhật được định mức.');
            }
        });
    }

    // ---------- Mousedown: chọn NVL trong dropdown / bật kéo qua tay cầm ----------
    $list.addEventListener('mousedown', (e) => {
        const $opt = e.target.closest('.material-dropdown li');
        if ($opt && !$opt.classList.contains('empty')) {
            e.preventDefault(); // giữ focus, tránh blur trước khi chọn
            pickMaterial($opt);
            return;
        }
        const $handle = e.target.closest('.material-drag-handle');
        if ($handle) {
            const $li = $handle.closest('.material-item');
            if ($li) $li.draggable = true;
        }
    });

    // Ẩn dropdown khi click ra ngoài ô tên.
    document.addEventListener('click', (e) => {
        if (!e.target.closest('.material-name-field')) hideAllMatDropdowns();
    });

    // ---------- Drag & drop sắp xếp .material-item (trong cùng 1 list-material) ----------
    let $dragEl = null;

    function getDragAfterElement($listMat, y) {
        const els = [...$listMat.querySelectorAll('.material-item:not(.dragging)')];
        let closest = { offset: -Infinity, el: null };
        els.forEach($el => {
            const box    = $el.getBoundingClientRect();
            const offset = y - box.top - box.height / 2;
            if (offset < 0 && offset > closest.offset) closest = { offset, el: $el };
        });
        return closest.el;
    }

    $list.addEventListener('dragstart', (e) => {
        const $li = e.target.closest('.material-item');
        if (!$li || !$li.draggable) return;
        $dragEl = $li;
        $li.classList.add('dragging');
        if (e.dataTransfer) e.dataTransfer.effectAllowed = 'move';
    });

    $list.addEventListener('dragover', (e) => {
        if (!$dragEl) return;
        const $listMat = e.target.closest('.list-material');
        if (!$listMat || $listMat !== $dragEl.parentElement) return; // chỉ trong cùng list
        e.preventDefault();
        const $after = getDragAfterElement($listMat, e.clientY);
        if ($after == null) $listMat.appendChild($dragEl);
        else $listMat.insertBefore($dragEl, $after);
    });

    $list.addEventListener('dragend', () => {
        if (!$dragEl) return;
        $dragEl.classList.remove('dragging');
        $dragEl.draggable = false;
        const $product = $dragEl.closest('.product-item');
        markProductDirty($product);
        recomputeProductSubtotals($product);
        recomputeGrandTotals();
        $dragEl = null;
    });

    // Nhấn tay cầm rồi thả mà không kéo → tắt lại draggable để không cản thao tác input.
    document.addEventListener('mouseup', () => {
        if ($dragEl) return;
        $list.querySelectorAll('.material-item[draggable="true"]').forEach($li => { $li.draggable = false; });
    });

    // ---------- Date-driven reload ----------
    function reloadItemsForCurrentDate() {
        const date = pickerToDateYMD();
        if (!date) return Promise.resolve([]);
        return postForm('get_investment_items_for_date', { date }).then(res => {
            const items = (res && res.success && Array.isArray(res.data)) ? res.data : [];
            currentItems = items;
            if (!editingBatchKey) renderListFrom(items);
            return items;
        });
    }

    // ---------- Edit batch mode ----------
    function enterEditMode(batch) {
        editingBatchKey = batch.group_key;
        const localVal = mysqlToLocalValue(batch.created_at);
        if (localVal) $dateTime.value = localVal;
        if (window.JE && window.JE.loadTemplatesAsBlocks) window.JE.loadTemplatesAsBlocks();

        // Load list-material từ stock_exports lịch sử của batch
        postForm('get_investment_batch_detail', { group_key: batch.group_key }).then(res => {
            const items = (res && res.success && Array.isArray(res.data)) ? res.data : [];
            renderListFrom(items);
            $banner.style.display = 'flex';
            $bannerLb.textContent = batch.date_display;
            $btnRec.style.display = 'none';
            if ($btnEdit) $btnEdit.style.display = ''; // chỉ hiện "Sửa" khi sửa từ lịch sử
            document.querySelector('.content').scrollIntoView({ behavior: 'smooth' });
        });
    }

    // keepEdits=true → giữ nguyên list-product user vừa sửa (không nạp lại
    // items theo ngày từ server). Dùng sau khi user nhấn Sửa thành công.
    function exitEditMode(keepEdits) {
        editingBatchKey = null;
        $banner.style.display = 'none';
        $btnRec.style.display = '';
        if ($btnEdit) $btnEdit.style.display = 'none'; // thoát sửa → ẩn lại "Sửa"
        if (window.JE && window.JE.reset) window.JE.reset();
        if (keepEdits) return;
        renderListFrom(currentItems);
    }

    $btnCancel.addEventListener('click', (e) => {
        e.preventDefault();
        exitEditMode();
    });

    // ---------- Picker change ----------
    $dateTime.addEventListener('change', () => {
        if (editingBatchKey) return;
        reloadItemsForCurrentDate();
    });

    // ---------- Record / Edit buttons ----------
    function flashActive(btn) {
        [$btnRec, $btnEdit].forEach(b => b && b.classList.remove('active'));
        if (btn) btn.classList.add('active');
    }

    // TASK 1.2: lời nhắc lịch nhanh cho NVL "sắp hết" (ip-warn-caution) — thêm
    // vào calendar_events qua evcalCreate (module home/index), nội dung
    // "Order {tên thường gọi}". Ngày tính từ hôm nay theo lựa chọn.
    const CAL_REMIND_OPTIONS = [
        { label: 'Ngày mai', days: 1 },
        { label: '3 ngày sau', days: 3 },
        { label: 'Tuần sau', days: 7 },
        { label: '2 tuần sau', days: 14 },
        { label: 'Tháng sau', months: 1 },
    ];

    function calRemindDateYMD(opt) {
        const d = new Date();
        if (opt.months) d.setMonth(d.getMonth() + opt.months);
        if (opt.days) d.setDate(d.getDate() + opt.days);
        const y = d.getFullYear();
        const m = String(d.getMonth() + 1).padStart(2, '0');
        const day = String(d.getDate()).padStart(2, '0');
        return `${y}-${m}-${day}`;
    }

    // Giờ gợi ý = giờ sự kiện cuối cùng (có giờ) trong ngày + 15 phút; chưa có sự kiện
    // nào thì mặc định 09:00 — giống hệt logic suggestTime() ở trang Lịch phóng to
    // (public/js/calendar_full.js).
    function suggestCalTime(events) {
        const evs = (events || []).filter(it => it && it.event_time);
        if (!evs.length) return '09:00';
        const last = evs[evs.length - 1];
        const parts = last.event_time.split(':');
        const mins = (parseInt(parts[0], 10) * 60 + parseInt(parts[1], 10) + 15) % 1440;
        const pad2 = n => String(n).padStart(2, '0');
        return pad2(Math.floor(mins / 60)) + ':' + pad2(mins % 60);
    }

    function fetchCalDayEvents(dateYMD) {
        const body = new URLSearchParams({ date: dateYMD });
        return fetch('?mod=home&controllers=index&action=evcalDay', {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body: body.toString()
        }).then(r => r.json());
    }

    function addMaterialCalReminder(materialName, dateYMD) {
        return fetchCalDayEvents(dateYMD).then(res => {
            const timeStr = suggestCalTime((res && res.data) || []);
            const body = new URLSearchParams({ date: dateYMD, time: timeStr, content: 'Order ' + materialName });
            return fetch('?mod=home&controllers=index&action=evcalCreate', {
                method: 'POST',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                body: body.toString()
            }).then(r => r.json());
        });
    }

    // TASK 1.1: gom cảnh báo tồn NVL (không đủ / sắp hết) trả về từ record_investment
    // thành 1 modal duy nhất. n1 = tồn trước xuất, q1 = SL xuất, k = n1 - q1.
    function buildStockWarningRow(w) {
        const unit = w.unit ? (' ' + w.unit) : '';
        const insufficient = w.type === 'insufficient';
        const cls = insufficient ? 'ip-warn-item ip-warn-danger' : 'ip-warn-item ip-warn-caution';
        const msg = insufficient
            ? 'Tồn không đủ để xuất sản xuất — thiếu ' + fmtSplitQty(Math.abs(w.k)) + unit + '.'
            : 'Tồn còn lại ' + fmtSplitQty(w.k) + unit + ' (' + fmtSplitQty(w.n1 > 0 ? (w.k * 100 / w.n1) : 0) + '% so với trước xuất) — sắp hết, nên xem xét đặt thêm.';
        const calBtn = insufficient ? '' : `
                    <div class="ip-warn-cal">
                        <button type="button" class="ip-warn-cal-btn" data-material="${escapeHtml(w.common_name || w.material_name)}">+ Lịch</button>
                        <ul class="ip-warn-cal-menu" hidden>${CAL_REMIND_OPTIONS.map((o, i) => `<li data-idx="${i}">${o.label}</li>`).join('')}</ul>
                    </div>`;
        return `
            <li class="${cls}">
                <span class="ip-warn-icon">${insufficient ? '⛔' : '⚠'}</span>
                <div class="ip-warn-body">
                    <p class="ip-warn-name">${escapeHtml(w.material_name)}</p>
                    <p class="ip-warn-msg">${msg}</p>
                    <p class="ip-warn-detail">Tồn trước xuất: ${fmtSplitQty(w.n1)}${unit} · SL xuất: ${fmtSplitQty(w.q1)}${unit}</p>
                    ${calBtn}
                </div>
            </li>`;
    }

    // TASK: cảnh báo NVL "kiểm soát" (module tea_scent_group) sắp cần ủ thêm.
    // a = tồn trước xuất, b = SL xuất lần này, times_left = round(a/b, 2).
    function buildTeaScentWarningRow(w) {
        const unit = w.unit ? (' ' + w.unit) : '';
        const products = (w.products || []).join(', ');
        return `
            <li class="ip-warn-item ip-warn-caution">
                <span class="ip-warn-icon">⚠</span>
                <div class="ip-warn-body">
                    <p class="ip-warn-name">${escapeHtml(w.material_name)}</p>
                    <p class="ip-warn-msg">Tồn chỉ còn đủ dùng khoảng ${fmtSplitQty(w.times_left)} lần nữa (ngưỡng cảnh báo: ${fmtSplitQty(w.threshold)} lần) — nên ủ thêm.</p>
                    <p class="ip-warn-detail">Tồn trước xuất: ${fmtSplitQty(w.a)}${unit} · SL xuất lần này: ${fmtSplitQty(w.b)}${unit}${products ? (' · SP: ' + escapeHtml(products)) : ''}</p>
                </div>
            </li>`;
    }

    function showTeaScentWarnings(warnings, onClose) {
        if (!Array.isArray(warnings) || !warnings.length) {
            if (onClose) onClose();
            return;
        }
        const rows = warnings.map(buildTeaScentWarningRow).join('');
        const wrap = document.createElement('div');
        wrap.innerHTML = `
            <div class="ip-warn-mask">
                <div class="ip-warn-box">
                    <div class="ip-warn-title">Cảnh báo NVL ủ hương</div>
                    <ul class="ip-warn-list">${rows}</ul>
                    <div class="ip-warn-foot">
                        <button type="button" class="ip-warn-close-btn">Đã hiểu</button>
                    </div>
                </div>
            </div>`;
        const $mask = wrap.firstElementChild;
        document.body.appendChild($mask);
        const close = () => { $mask.remove(); if (onClose) onClose(); };
        $mask.querySelector('.ip-warn-close-btn').addEventListener('click', close);
        $mask.addEventListener('click', (e) => { if (e.target === $mask) close(); });
    }

    // Menu dùng position:fixed (thoát khỏi overflow-y:auto của .ip-warn-list) nên
    // toạ độ phải tính bằng tay theo vị trí nút trên viewport; lật lên trên nếu
    // không đủ chỗ bên dưới.
    function positionCalMenu(btn, menu) {
        const r = btn.getBoundingClientRect();
        menu.style.left = r.left + 'px';
        const menuH = menu.offsetHeight || (CAL_REMIND_OPTIONS.length * 30 + 8);
        const spaceBelow = window.innerHeight - r.bottom;
        if (spaceBelow < menuH + 8 && r.top > menuH + 8) {
            menu.style.top = (r.top - menuH - 4) + 'px';
        } else {
            menu.style.top = (r.bottom + 4) + 'px';
        }
    }

    function showStockWarnings(warnings, onClose) {
        if (!Array.isArray(warnings) || !warnings.length) {
            if (onClose) onClose();
            return;
        }
        const rows = warnings.map(buildStockWarningRow).join('');
        const wrap = document.createElement('div');
        wrap.innerHTML = `
            <div class="ip-warn-mask">
                <div class="ip-warn-box">
                    <div class="ip-warn-title">Cảnh báo tồn nguyên liệu</div>
                    <ul class="ip-warn-list">${rows}</ul>
                    <div class="ip-warn-foot">
                        <button type="button" class="ip-warn-close-btn">Đã hiểu</button>
                    </div>
                </div>
            </div>`;
        const $mask = wrap.firstElementChild;
        document.body.appendChild($mask);
        const $list = $mask.querySelector('.ip-warn-list');
        $list.addEventListener('scroll', () => {
            $mask.querySelectorAll('.ip-warn-cal-menu').forEach(m => { m.hidden = true; });
        });
        const close = () => { $mask.remove(); if (onClose) onClose(); };
        $mask.querySelector('.ip-warn-close-btn').addEventListener('click', close);
        $mask.addEventListener('click', (e) => {
            if (e.target === $mask) { close(); return; }
            const calBtn = e.target.closest('.ip-warn-cal-btn');
            if (calBtn) {
                e.stopPropagation();
                const menu = calBtn.nextElementSibling;
                $mask.querySelectorAll('.ip-warn-cal-menu').forEach(m => { if (m !== menu) m.hidden = true; });
                const willOpen = menu.hidden;
                menu.hidden = !willOpen;
                if (willOpen) positionCalMenu(calBtn, menu);
                return;
            }
            const opt = e.target.closest('.ip-warn-cal-menu li');
            if (opt) {
                e.stopPropagation();
                const menu = opt.closest('.ip-warn-cal-menu');
                const btn = menu.previousElementSibling;
                const cfg = CAL_REMIND_OPTIONS[Number(opt.dataset.idx)];
                const dateYMD = calRemindDateYMD(cfg);
                menu.hidden = true;
                addMaterialCalReminder(btn.dataset.material, dateYMD).then(res => {
                    if (res && res.ok) toast('Đã thêm lời nhắc vào lịch ngày ' + dateYMD + '.');
                    else toast(res && res.message ? res.message : 'Không thêm được lời nhắc.');
                });
            }
        });
    }

    // Modal cảnh báo trùng phiếu giá vốn: liệt kê SP đã có phiếu cùng ngày, cho chọn
    // "Ghi đè" (xóa phiếu cũ trước khi ghi phiếu mới) hoặc "Hủy" (không ghi).
    function buildDuplicateRow(d) {
        return `
            <li class="ip-warn-item ip-warn-caution">
                <span class="ip-warn-icon">⚠</span>
                <div class="ip-warn-body">
                    <p class="ip-warn-name">${escapeHtml(d.product_name)}</p>
                    <p class="ip-warn-msg">Đã có phiếu giá vốn sản xuất ngày ${escapeHtml(d.date_vn)}.</p>
                </div>
            </li>`;
    }

    function showDuplicateModal(dups, onOverwrite, onCancel) {
        const rows = dups.map(buildDuplicateRow).join('');
        const wrap = document.createElement('div');
        wrap.innerHTML = `
            <div class="ip-warn-mask">
                <div class="ip-warn-box">
                    <div class="ip-warn-title">Trùng phiếu giá vốn sản xuất</div>
                    <ul class="ip-warn-list">${rows}</ul>
                    <div class="ip-warn-foot">
                        <button type="button" class="ip-dup-cancel-btn">Hủy</button>
                        <button type="button" class="ip-dup-overwrite-btn">Ghi đè</button>
                    </div>
                </div>
            </div>`;
        const $mask = wrap.firstElementChild;
        document.body.appendChild($mask);
        const close = () => $mask.remove();
        $mask.querySelector('.ip-dup-cancel-btn').addEventListener('click', () => { close(); if (onCancel) onCancel(); });
        $mask.querySelector('.ip-dup-overwrite-btn').addEventListener('click', () => { close(); onOverwrite(); });
        $mask.addEventListener('click', (e) => { if (e.target === $mask) { close(); if (onCancel) onCancel(); } });
    }

    // Modal "Trừ bao bì khách hàng": xử lý tại chỗ nghiệp vụ "Xuất dùng cho sản phẩm"
    // (customer_packagingModel::cpm_entry_add, entry_type='usage') thay vì redirect
    // sang trang QL bao bì khách hàng. pairs = p.customer_pkg [{customer_name, packaging_name}].
    function openCpkgModal(pid, productName, productQty, pairs) {
        if (!pairs || !pairs.length) return;
        const options = pairs.map((c, i) =>
            `<option value="${i}">${escapeHtml(c.customer_name)} — ${escapeHtml(c.packaging_name)}</option>`
        ).join('');
        const todayYMD = new Date().toISOString().slice(0, 10);
        const fullPageHref = '?mod=customer_packaging&controllers=customer_packaging&action=customer_packaging';

        const wrap = document.createElement('div');
        wrap.innerHTML = `
            <div class="ip-warn-mask">
                <div class="ip-warn-box ip-cpkg-box">
                    <div class="ip-warn-title ip-cpkg-title">Trừ bao bì khách hàng — ${escapeHtml(productName)}</div>
                    <div class="ip-cpkg-field">
                        <label>Khách hàng — Bao bì</label>
                        <select class="ip-cpkg-pair">${options}</select>
                    </div>
                    <div class="ip-cpkg-field-row">
                        <div class="ip-cpkg-field">
                            <label>Số lượng trừ</label>
                            <input type="text" class="ip-cpkg-qty" inputmode="decimal" value="${productQty || ''}">
                        </div>
                        <div class="ip-cpkg-field">
                            <label>Ngày</label>
                            <input type="date" class="ip-cpkg-date" value="${todayYMD}">
                        </div>
                    </div>
                    <a class="ip-cpkg-fullpage-link" href="${fullPageHref}" target="_blank">Mở trang quản lý đầy đủ ↗</a>
                    <div class="ip-warn-foot">
                        <button type="button" class="ip-dup-cancel-btn ip-cpkg-cancel-btn">Hủy</button>
                        <button type="button" class="ip-warn-close-btn ip-cpkg-submit-btn">Ghi sổ (trừ bao bì)</button>
                    </div>
                </div>
            </div>`;
        const $mask = wrap.firstElementChild;
        document.body.appendChild($mask);
        const close = () => $mask.remove();
        $mask.querySelector('.ip-cpkg-cancel-btn').addEventListener('click', close);
        $mask.addEventListener('click', (e) => { if (e.target === $mask) close(); });

        $mask.querySelector('.ip-cpkg-submit-btn').addEventListener('click', function () {
            const btn  = this;
            const idx  = parseInt($mask.querySelector('.ip-cpkg-pair').value, 10) || 0;
            const pair = pairs[idx];
            const qty  = parseFloat(String($mask.querySelector('.ip-cpkg-qty').value || '').replace(',', '.')) || 0;
            const date = $mask.querySelector('.ip-cpkg-date').value;
            if (!pair || qty <= 0) { alert('Nhập số lượng trừ hợp lệ.'); return; }
            btn.disabled = true;
            postCpkg('entry_add', {
                customer_name: pair.customer_name,
                packaging_name: pair.packaging_name,
                entry_type: 'usage',
                qty: qty,
                entry_date: date,
                product_id: pid,
                product_name: productName
            }).then(res => {
                btn.disabled = false;
                if (res && res.success) {
                    close();
                    toast('Đã trừ bao bì "' + pair.packaging_name + '" của ' + pair.customer_name + '.');
                } else {
                    alert((res && res.message) || 'Trừ bao bì thất bại.');
                }
            });
        });
    }

    // onOk(overwriteKeys) — overwriteKeys rỗng nếu không có trùng.
    function checkDuplicatesThen(productIds, onOk) {
        if (!productIds.length) { onOk([]); return; }
        postForm('check_investment_duplicates', {
            product_ids: JSON.stringify(productIds),
            plan_date:   pickerToDateYMD()
        }).then(res => {
            const dups = (res && res.data) || [];
            if (!dups.length) { onOk([]); return; }
            const keys = Array.from(new Set(dups.map(d => d.group_key).filter(Boolean)));
            showDuplicateModal(dups, () => onOk(keys));
        });
    }

    $btnRec.addEventListener('click', () => {
        if (editingBatchKey) return;
        const items = collectItemsForPayload();
        if (!items.length) {
            alert('Chưa có sản phẩm nào để ghi.');
            return;
        }
        const pids = items.map(it => it.product_id);
        checkDuplicatesThen(pids, (overwriteKeys) => {
            flashActive($btnRec);
            const totals = readGrandTotals();
            postForm('record_investment', Object.assign({
                items:          JSON.stringify(items),
                cost_price:     totals.cost_price,
                goods_value:    totals.goods_value,
                created_at:     pickerToMysql(),
                overwrite_keys: JSON.stringify(overwriteKeys || [])
            }, jePayload())).then(res => {
                if (res && res.success) {
                    if (window.appFlyToHistory) window.appFlyToHistory($btnRec);
                    // Auto reload trang để refresh tồn nguyên liệu, lịch sử, list-product...
                    // (sau khi user đóng modal cảnh báo tồn, nếu có + đợi hiệu ứng bay chạy xong).
                    showStockWarnings(res.stock_warnings, () => {
                        showTeaScentWarnings(res.tea_scent_warnings, () => setTimeout(() => window.location.reload(), 950));
                    });
                } else {
                    alert(res && res.message ? res.message : 'Có lỗi xảy ra.');
                }
            });
        });
    });

    $btnEdit.addEventListener('click', () => {
        if (!editingBatchKey) {
            alert('Hãy chọn "Sửa" ở bảng Lịch sử để chỉnh nhóm cần sửa.');
            return;
        }
        flashActive($btnEdit);
        const totals = readGrandTotals();
        const items  = collectItemsForPayload();
        postForm('edit_investment_batch', Object.assign({
            group_key:   editingBatchKey,
            items:       JSON.stringify(items),
            cost_price:  totals.cost_price,
            goods_value: totals.goods_value
        }, jePayload())).then(res => {
            if (res && res.success) {
                alert('Đã lưu các thay đổi giá vốn của nhóm.');
                renderHistory(res.history || []);
                // Giữ nguyên list user vừa sửa — không revert về items theo ngày.
                exitEditMode(true);
                showTeaScentWarnings(res.tea_scent_warnings);
            } else {
                alert(res && res.message ? res.message : 'Có lỗi xảy ra.');
            }
        });
    });

    // ---------- History (with pagination) ----------
    function renderHistory(batches) {
        INITIAL.history = batches || [];
        historyPage = 1;
        renderHistoryPage();
    }

    // Ngày của batch dạng 'YYYY-MM-DD'. Ưu tiên created_at ('YYYY-MM-DD HH:MM:SS');
    // fallback parse từ date_display ('HH:ii:ss dd/mm/yyyy' hoặc 'dd/mm/yyyy').
    // So sánh chuỗi 'YYYY-MM-DD' đúng thứ tự nên dùng trực tiếp cho khoảng ngày.
    function batchDateYMD(b) {
        const iso = /^(\d{4})-(\d{2})-(\d{2})/.exec(String(b && b.created_at ? b.created_at : ''));
        if (iso) return iso[1] + '-' + iso[2] + '-' + iso[3];
        const vn = /(\d{1,2})\/(\d{1,2})\/(\d{4})/.exec(String(b && b.date_display ? b.date_display : ''));
        if (vn) return vn[3] + '-' + pad2(vn[2]) + '-' + pad2(vn[1]);
        return '';
    }

    // Áp dụng bộ lọc (từ khóa + khoảng ngày) lên INITIAL.history.
    function getFilteredHistory() {
        const all = INITIAL.history || [];
        const kw = histFilter.keyword.trim().toLowerCase();
        const from = histFilter.dateFrom;
        const to   = histFilter.dateTo;
        if (!kw && !from && !to) return all;
        return all.filter(b => {
            if (kw) {
                const inSummary = String(b.summary || '').toLowerCase().indexOf(kw) !== -1;
                const inItems = Array.isArray(b.items) && b.items.some(it =>
                    String(it.product_name || '').toLowerCase().indexOf(kw) !== -1);
                if (!inSummary && !inItems) return false;
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
        const total = (INITIAL.history || []).length;
        const data = getFilteredHistory();
        const totalPages = Math.max(1, Math.ceil(data.length / pageSize));
        if (historyPage > totalPages) historyPage = totalPages;
        if (historyPage < 1) historyPage = 1;

        if ($hfCount) {
            $hfCount.textContent = data.length === total
                ? (total + ' nhóm')
                : (data.length + '/' + total + ' nhóm');
        }
        updateFilterUI();

        if (!data.length) {
            const msg = total ? 'Không có nhóm nào khớp bộ lọc.' : 'Chưa có thao tác nào.';
            $histBody.innerHTML = '<tr class="history-empty"><td colspan="3">' + msg + '</td></tr>';
            $histPager.innerHTML = '';
            return;
        }

        const start = (historyPage - 1) * pageSize;
        const slice = data.slice(start, start + pageSize);
        // data-group-key là khóa duy nhất → handler Sửa/Xóa tra cứu theo nó,
        // không phụ thuộc index (an toàn khi đang lọc/phân trang).
        $histBody.innerHTML = slice.map(b => {
            const actions = b.needs_investment
                ? `<a href="#" class="record-investment-supplement">${b.is_supplement ? 'Nhập giá vốn bổ sung' : 'Nhập giá vốn'}</a>`
                : `<a href="#" class="edit-list-import">Sửa</a>
                   <span class="sep">|</span>
                   <a href="#" class="delete-list-import">Xóa</a>`;
            return `
                <tr data-group-key="${escapeHtml(b.group_key)}">
                    <td class="${b.date_color ? 'hist-date-' + b.date_color : ''}">${escapeHtml(b.date_display)}</td>
                    <td title="${escapeHtml(b.summary)}">${escapeHtml(b.summary)}</td>
                    <td class="history-actions">${actions}</td>
                </tr>
            `;
        }).join('');

        renderPager(totalPages);
    }

    function renderPager(totalPages) {
        if (totalPages <= 1) { $histPager.innerHTML = ''; return; }
        const parts = [];
        parts.push(`<button type="button" class="page-btn page-prev" ${historyPage === 1 ? 'disabled' : ''}>«</button>`);
        const range = pageRange(historyPage, totalPages);
        range.forEach(p => {
            if (p === '...') parts.push('<span class="page-ellipsis">…</span>');
            else parts.push(`<button type="button" class="page-btn page-num ${p === historyPage ? 'active' : ''}" data-page="${p}">${p}</button>`);
        });
        parts.push(`<button type="button" class="page-btn page-next" ${historyPage === totalPages ? 'disabled' : ''}>»</button>`);
        $histPager.innerHTML = parts.join('');
    }

    function pageRange(current, total) {
        const out = [];
        if (total <= 7) { for (let i = 1; i <= total; i++) out.push(i); return out; }
        out.push(1);
        if (current > 3) out.push('...');
        const from = Math.max(2, current - 1);
        const to   = Math.min(total - 1, current + 1);
        for (let i = from; i <= to; i++) out.push(i);
        if (current < total - 2) out.push('...');
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
        const supLink  = e.target.closest('.record-investment-supplement');
        if (!editLink && !delLink && !supLink) return;
        e.preventDefault();
        const tr = e.target.closest('tr');
        if (!tr) return;
        const gk = tr.getAttribute('data-group-key');
        const batch = (INITIAL.history || []).find(b => b.group_key === gk);
        if (!batch) return;

        if (supLink) {
            if (editingBatchKey) exitEditMode(true);
            const localVal = mysqlToLocalValue(batch.created_at);
            if (localVal) $dateTime.value = localVal;
            reloadItemsForCurrentDate().then(() => {
                document.querySelector('.content').scrollIntoView({ behavior: 'smooth' });
            });
            return;
        }
        if (editLink) { enterEditMode(batch); return; }
        if (delLink) {
            if (!confirm('Xóa nhóm "' + batch.date_display + '"? Tồn nguyên liệu sẽ được hoàn lại.')) return;
            postForm('delete_investment_batch', { group_key: batch.group_key }).then(res => {
                if (res && res.success) {
                    alert('Đã xóa.');
                    renderHistory(res.history || []);
                    if (editingBatchKey === batch.group_key) exitEditMode();
                } else {
                    alert(res && res.message ? res.message : 'Có lỗi xảy ra.');
                }
            });
        }
    });

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

    // ---------- Init ----------
    function init() {
        $dateTime.value = nowLocalValue();
        renderListFrom(currentItems);
        setupJe();
        setupHistoryFilter();
        renderHistory(INITIAL.history || []);
    }

    init();
})();
