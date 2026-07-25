(function () {
    'use strict';

    const CFG = window.PCC_CONFIG || { baseUrl: '?mod=inventory_receiving&controllers=inventory_receiving&action=' };

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

    function parseNum(s) {
        return parseFloat(String(s == null ? '' : s).replace(/,/g, '')) || 0;
    }

    function fmtMoney(v) {
        if (v === null || typeof v === 'undefined') return '—';
        const n = Math.round(Number(v) || 0);
        return n.toLocaleString('en-US') + ' đ';
    }

    function fmtPrice(v) {
        if (v === null || typeof v === 'undefined') return '—';
        const n = Math.round((Number(v) || 0) * 1e8) / 1e8;
        return n.toLocaleString('en-US', { maximumFractionDigits: 8 }) + ' đ';
    }

    function fmtQty(v) {
        const n = Number(v) || 0;
        if (Math.abs(n - Math.round(n)) < 1e-9) return String(Math.round(n));
        return String(parseFloat(n.toFixed(4)));
    }

    function fmtRate(r) {
        if (r === null || typeof r === 'undefined') return '—';
        const n = Number(r) || 0;
        const sign = n > 0 ? '+' : '';
        return sign + (Math.round(n * 100) / 100).toLocaleString('en-US') + '%';
    }

    function fmtDate(s) {
        if (!s) return '—';
        const m = String(s).match(/^(\d{4})-(\d{2})-(\d{2})[ T](\d{2}):(\d{2})/);
        if (!m) return String(s);
        return m[3] + '/' + m[2] + '/' + m[1] + ' ' + m[4] + ':' + m[5];
    }

    /* ============================================
       Ô tìm kiếm gộp NVL + thành phẩm (search_items có sẵn ở
       inventory_receivingController — tái dùng nguyên).
       ============================================ */
    const $search   = document.getElementById('pcc-search-input');
    const $dropdown = document.getElementById('pcc-search-dropdown');
    const $chip     = document.getElementById('pcc-selected-chip');
    const $chipType = document.getElementById('pcc-selected-type');
    const $chipName = document.getElementById('pcc-selected-name');
    const $chipClear= document.getElementById('pcc-selected-clear');
    const $priceInp = document.getElementById('pcc-new-price');
    const $btnCheck = document.getElementById('pcc-btn-check');
    const $result   = document.getElementById('pcc-result');

    let selected = null; // { type: 'material'|'product', id, name }
    let activeIdx = -1;
    let searchTimer = null;

    function hideDropdown() {
        $dropdown.classList.remove('active');
        $dropdown.innerHTML = '';
        activeIdx = -1;
    }

    function renderDropdown(items) {
        activeIdx = -1;
        if (!items.length) {
            $dropdown.innerHTML = '<li class="empty">Không tìm thấy</li>';
        } else {
            $dropdown.innerHTML = items.map((it, i) => {
                const badge = it.type === 'product' ? 'Thành phẩm' : 'NVL';
                return '<li data-idx="' + i + '" data-id="' + it.id + '" data-type="' + it.type + '" data-name="' + escapeHtml(it.name) + '">' +
                    '<span class="pcc-dd-badge pcc-dd-badge-' + it.type + '">' + badge + '</span>' +
                    '<span class="pcc-dd-name">' + escapeHtml(it.name) + '</span>' +
                '</li>';
            }).join('');
        }
        $dropdown.classList.add('active');
    }

    function updateCheckBtnState() {
        $btnCheck.disabled = !(selected && parseNum($priceInp.value) > 0);
    }

    function selectItem(type, id, name) {
        selected = { type: type, id: id, name: name };
        $chipType.textContent = type === 'product' ? 'Thành phẩm' : 'NVL';
        $chipType.className = 'pcc-selected-type pcc-selected-type-' + type;
        $chipName.textContent = name;
        $chip.style.display = '';
        $search.value = '';
        hideDropdown();
        updateCheckBtnState();
        $priceInp.focus();
    }

    $chipClear.addEventListener('click', () => {
        selected = null;
        $chip.style.display = 'none';
        $result.innerHTML = '';
        updateCheckBtnState();
        $search.focus();
    });

    $search.addEventListener('input', () => {
        const kw = $search.value.trim();
        clearTimeout(searchTimer);
        if (kw === '') { hideDropdown(); return; }
        searchTimer = setTimeout(() => {
            postForm('search_items', { keyword: kw }).then(res => {
                renderDropdown(res.data || []);
            });
        }, 220);
    });

    $search.addEventListener('keydown', (e) => {
        if (!$dropdown.classList.contains('active')) return;
        const lis = $dropdown.querySelectorAll('li:not(.empty)');
        if (!lis.length) return;
        if (e.key === 'ArrowDown') {
            e.preventDefault();
            activeIdx = (activeIdx + 1) % lis.length;
        } else if (e.key === 'ArrowUp') {
            e.preventDefault();
            activeIdx = (activeIdx - 1 + lis.length) % lis.length;
        } else if (e.key === 'Enter' || e.key === 'Tab') {
            if (activeIdx >= 0) {
                e.preventDefault();
                const li = lis[activeIdx];
                selectItem(li.getAttribute('data-type'), li.getAttribute('data-id'), li.getAttribute('data-name'));
            }
            return;
        } else if (e.key === 'Escape') {
            hideDropdown();
            return;
        } else {
            return;
        }
        lis.forEach(li => li.classList.remove('active'));
        lis[activeIdx].classList.add('active');
        lis[activeIdx].scrollIntoView({ block: 'nearest' });
    });

    $dropdown.addEventListener('click', (e) => {
        const li = e.target.closest('li');
        if (!li || li.classList.contains('empty')) return;
        selectItem(li.getAttribute('data-type'), li.getAttribute('data-id'), li.getAttribute('data-name'));
    });

    document.addEventListener('click', (e) => {
        if (!e.target.closest('#pcc-search-wrap')) hideDropdown();
    });

    $priceInp.addEventListener('input', updateCheckBtnState);

    /* ============================================
       Kiểm tra
       ============================================ */
    function renderProductResult(res) {
        const up = Number(res.change_rate) > 0;
        const rateCls = res.change_rate === null ? '' : (up ? 'up' : 'down');
        const dateTip = res.old_date ? ' title="Ngày nhập: ' + escapeHtml(fmtDate(res.old_date)) + '"' : '';
        $result.innerHTML =
            '<table class="ppc-table pcc-result-table">' +
                '<thead><tr><th>Tên sản phẩm</th><th class="center">Giá trước đó</th><th class="center">Giá mới</th><th class="center">Tỉ lệ biến động</th><th class="center">Thao tác</th></tr></thead>' +
                '<tbody><tr>' +
                    '<td>' + escapeHtml(res.product_name) + '</td>' +
                    '<td class="num center"' + dateTip + '>' + fmtMoney(res.old_price) + '</td>' +
                    '<td class="num center">' + fmtMoney(res.new_price) + '</td>' +
                    '<td class="num center ' + rateCls + '">' + fmtRate(res.change_rate) + '</td>' +
                    '<td class="center">' +
                        '<button type="button" class="ppc-impact-btn pcc-history-btn" title="Xem lịch sử biến động" ' +
                            'data-hist-type="product" data-hist-id="' + res.product_id + '" data-hist-name="' + escapeHtml(res.product_name) + '">' +
                            '<i class="fa-solid fa-clock-rotate-left"></i></button>' +
                    '</td>' +
                '</tr></tbody>' +
            '</table>';
        if (!res.old_price) {
            $result.insertAdjacentHTML('afterbegin',
                '<div class="pcc-warn"><i class="fa-solid fa-triangle-exclamation"></i> Chưa có lịch sử nhập mua sản phẩm này — không tính được tỉ lệ biến động.</div>');
        }
    }

    function renderMaterialResult(res) {
        const items = res.items || [];
        const warn = !res.old_price
            ? '<div class="pcc-warn"><i class="fa-solid fa-triangle-exclamation"></i> Nguyên liệu này chưa có giá nhập trước đó — giá vốn cũ tạm tính với đơn giá 0.</div>'
            : '';
        if (!items.length) {
            $result.innerHTML = warn + '<div class="pcc-empty">Chưa có sản phẩm nào dùng nguyên liệu này.</div>';
            return;
        }
        const rows = items.map(it => {
            const up = Number(it.change_rate) > 0;
            return '<tr>' +
                '<td><button type="button" class="pci-product-name pcc-breakdown-btn" ' +
                    'data-product-id="' + it.product_id + '" data-product-name="' + escapeHtml(it.product_name) + '">' +
                    escapeHtml(it.product_name) + '</button></td>' +
                '<td class="num center">' + fmtMoney(it.old_cost) + '</td>' +
                '<td class="num center">' + fmtMoney(it.new_cost) + '</td>' +
                '<td class="num center ' + (up ? 'up' : 'down') + '">' + fmtRate(it.change_rate) + '</td>' +
                '<td class="center">' +
                    '<button type="button" class="ppc-impact-btn pcc-history-btn" title="Xem lịch sử biến động nguyên liệu" ' +
                        'data-hist-type="material" data-hist-id="' + res.material_id + '" data-hist-name="' + escapeHtml(res.material_name) + '">' +
                        '<i class="fa-solid fa-clock-rotate-left"></i></button>' +
                '</td>' +
            '</tr>';
        }).join('');
        $result.innerHTML = warn +
            '<table class="ppc-table pcc-result-table">' +
                '<thead><tr><th>Tên sản phẩm</th><th class="center">Giá vốn trước đó</th><th class="center">Giá vốn mới</th><th class="center">Tỉ lệ biến động</th><th class="center">Thao tác</th></tr></thead>' +
                '<tbody>' + rows + '</tbody>' +
            '</table>';
    }

    $btnCheck.addEventListener('click', () => {
        if (!selected) return;
        const newPrice = parseNum($priceInp.value);
        if (newPrice <= 0) { alert('Vui lòng nhập giá NCC báo.'); return; }

        $btnCheck.disabled = true;
        $result.innerHTML = '<div class="pcc-loading">Đang kiểm tra...</div>';

        const action = selected.type === 'product' ? 'pcc_check_product' : 'pcc_check_material';
        const payload = selected.type === 'product'
            ? { product_id: selected.id, new_price: newPrice }
            : { material_id: selected.id, new_price: newPrice };

        postForm(action, payload).then(res => {
            updateCheckBtnState();
            if (!res || !res.success) {
                $result.innerHTML = '<div class="pcc-warn"><i class="fa-solid fa-circle-exclamation"></i> ' +
                    escapeHtml((res && res.message) || 'Kiểm tra thất bại.') + '</div>';
                return;
            }
            // Lưu lại để mở modal giải thích giá vốn (trường hợp NVL) dùng đúng cặp giá vừa kiểm tra.
            $result.dataset.materialId = selected.type === 'material' ? res.material_id : '';
            $result.dataset.oldPrice = selected.type === 'material' ? (res.old_price || 0) : '';
            $result.dataset.newPrice = selected.type === 'material' ? res.new_price : '';
            if (selected.type === 'product') renderProductResult(res);
            else renderMaterialResult(res);
        }).catch(() => {
            updateCheckBtnState();
            $result.innerHTML = '<div class="pcc-warn"><i class="fa-solid fa-circle-exclamation"></i> Lỗi kết nối khi kiểm tra.</div>';
        });
    });

    /* ============================================
       Modal lịch sử biến động giá
       ============================================ */
    const $histOv    = document.getElementById('pcc-history-modal-overlay');
    const $histTitle = $histOv.querySelector('.pcc-history-title');
    const $histTbody = document.getElementById('pcc-history-tbody');

    function openHistoryModal(type, id, name) {
        $histTitle.textContent = 'Lịch sử biến động giá' + (name ? ' — ' + name : '');
        $histTbody.innerHTML = '<tr><td colspan="4" style="text-align:center;color:#888;">Đang tải...</td></tr>';
        $histOv.classList.add('active');
        postForm('pcc_price_history', { type: type, id: id }).then(res => {
            const items = (res && res.items) || [];
            if (!items.length) {
                $histTbody.innerHTML = '<tr><td colspan="4" style="text-align:center;color:#888;">Chưa có biến động nào được ghi nhận.</td></tr>';
                return;
            }
            $histTbody.innerHTML = items.map(it => {
                const up = Number(it.change_rate) > 0;
                return '<tr>' +
                    '<td>' + escapeHtml(fmtDate(it.created_at)) + '</td>' +
                    '<td class="num center">' + fmtMoney(it.old_price) + '</td>' +
                    '<td class="num center">' + fmtMoney(it.new_price) + '</td>' +
                    '<td class="num center ' + (up ? 'up' : 'down') + '">' + fmtRate(it.change_rate) + '</td>' +
                '</tr>';
            }).join('');
        });
    }

    function closeHistoryModal() { $histOv.classList.remove('active'); }
    $histOv.addEventListener('click', (e) => {
        if (e.target === $histOv || e.target.closest('.ppc-modal-close') || e.target.closest('.ppc-modal-ok')) closeHistoryModal();
    });

    /* ============================================
       Modal giải thích giá vốn (tái dùng AJAX ajax_product_cost_breakdown
       đã có từ modal "Giá vốn ảnh hưởng" ở row_material_receiving).
       ============================================ */
    const $bdOv    = document.getElementById('pcc-breakdown-modal-overlay');
    const $bdTitle = $bdOv.querySelector('.pcc-breakdown-title');
    const $bdTbody = document.getElementById('pcc-breakdown-tbody');
    const $bdTfoot = document.getElementById('pcc-breakdown-tfoot');

    function openBreakdownModal(productId, productName) {
        const materialId = $result.dataset.materialId;
        const oldPrice = $result.dataset.oldPrice;
        const newPrice = $result.dataset.newPrice;
        if (!materialId) return;

        $bdTitle.textContent = 'Giải thích giá vốn' + (productName ? ' — ' + productName : '');
        $bdTbody.innerHTML = '<tr><td colspan="6" style="text-align:center;color:#888;">Đang tải...</td></tr>';
        $bdTfoot.innerHTML = '';
        $bdOv.classList.add('active');

        postForm('ajax_product_cost_breakdown', { product_id: productId, material_id: materialId, old_price: oldPrice, new_price: newPrice }).then(res => {
            if (!res || !res.success) {
                $bdTbody.innerHTML = '<tr><td colspan="6" style="text-align:center;color:#c00;">' +
                    escapeHtml((res && res.message) || 'Lỗi tải dữ liệu.') + '</td></tr>';
                return;
            }
            const rows = res.rows || [];
            $bdTbody.innerHTML = rows.map(r => {
                return '<tr class="' + (r.is_changed ? 'pcb-row-changed' : '') + '">' +
                    '<td>' + escapeHtml(r.material_name) + (r.is_changed ? ' <span class="pcb-changed-tag">biến động</span>' : '') + '</td>' +
                    '<td class="num center">' + fmtQty(r.quantity_required) + '</td>' +
                    '<td class="num center">' + fmtPrice(r.price_old) + '</td>' +
                    '<td class="num center">' + fmtPrice(r.price_new) + '</td>' +
                    '<td class="num center">' + fmtMoney(r.line_old) + '</td>' +
                    '<td class="num center">' + fmtMoney(r.line_new) + '</td>' +
                '</tr>';
            }).join('');
            const up = Number(res.change_rate) > 0;
            $bdTfoot.innerHTML =
                '<tr class="pcb-total-row">' +
                    '<td colspan="4">Tổng giá vốn (<span class="' + (up ? 'up' : 'down') + '">' + fmtRate(res.change_rate) + '</span>)</td>' +
                    '<td class="num center">' + fmtMoney(res.total_old) + '</td>' +
                    '<td class="num center">' + fmtMoney(res.total_new) + '</td>' +
                '</tr>';
        });
    }

    function closeBreakdownModal() { $bdOv.classList.remove('active'); }
    $bdOv.addEventListener('click', (e) => {
        if (e.target === $bdOv || e.target.closest('.ppc-modal-close') || e.target.closest('.ppc-modal-ok')) closeBreakdownModal();
    });

    document.addEventListener('keydown', (e) => {
        if (e.key !== 'Escape') return;
        closeHistoryModal();
        closeBreakdownModal();
    });

    /* Delegated click trong khu vực kết quả: nút lịch sử + nút tên sản phẩm (giải thích giá vốn). */
    $result.addEventListener('click', (e) => {
        const histBtn = e.target.closest('.pcc-history-btn');
        if (histBtn) {
            openHistoryModal(histBtn.getAttribute('data-hist-type'), histBtn.getAttribute('data-hist-id'), histBtn.getAttribute('data-hist-name'));
            return;
        }
        const bdBtn = e.target.closest('.pcc-breakdown-btn');
        if (bdBtn) {
            openBreakdownModal(bdBtn.getAttribute('data-product-id'), bdBtn.getAttribute('data-product-name'));
        }
    });

    updateCheckBtnState();
})();
