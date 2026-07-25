/* =====================================================================
 *  QL nhóm trà ủ hương (tea_scent_group)
 *  KHỐI 1: Danh sách nhóm (mỗi nhóm = 1 NVL kiểm soát)
 *  KHỐI 2: Chi tiết nhóm (sản phẩm dùng + mốc tồn đầu + nhập thêm + sổ)
 * ===================================================================== */
(function () {
    'use strict';

    var CFG = window.TSG_CONFIG || { baseUrl: '?mod=tea_scent_group&controllers=tea_scent_group&action=', groups: [] };

    var groups = CFG.groups || [];
    var currentGroupId = null;
    var lastDetail = null;

    /* ---------- DOM ---------- */
    var $ = function (id) { return document.getElementById(id); };

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
    function num(n, dec) {
        if (dec === undefined) dec = 2;
        var v = Math.round((Number(n) + Number.EPSILON) * Math.pow(10, dec)) / Math.pow(10, dec);
        if (!isFinite(v)) v = 0;
        return v.toLocaleString('vi-VN', { maximumFractionDigits: dec });
    }
    function parseNum(s) { var v = parseFloat(String(s).replace(/\./g, '').replace(',', '.')); return isFinite(v) ? v : 0; }
    function formatDate(s) {
        if (!s) return '';
        var m = String(s).match(/(\d{4})-(\d{2})-(\d{2})/);
        if (!m) return s;
        return m[3] + '/' + m[2] + '/' + m[1];
    }
    function todayYMD() { return new Date().toISOString().slice(0, 10); }

    document.addEventListener('click', function (e) {
        if (!e.target.closest('.tsg-search-wrap')) {
            Array.prototype.forEach.call(document.querySelectorAll('.tsg-search-dropdown.open'),
                function (d) { d.classList.remove('open'); });
        }
    });

    /* =====================================================================
     *  Autocomplete dùng chung (↑↓ chọn dòng, Enter chọn, Tab chọn, Escape đóng)
     * ===================================================================== */
    function attachSearch(input, drop, action, onSelect) {
        var list = [], active = -1;
        function render() {
            if (!list.length) {
                drop.innerHTML = '<li class="tsg-sd-empty">Không tìm thấy.</li>';
                drop.classList.add('open'); return;
            }
            var html = '';
            list.forEach(function (s, i) {
                var sub = s.unit ? ('ĐV: ' + s.unit) : '';
                html += '<li data-i="' + i + '"' + (i === active ? ' class="active"' : '') + '>'
                    + '<span>' + esc(s.name || '') + '</span>'
                    + (sub ? '<small>' + esc(sub) + '</small>' : '') + '</li>';
            });
            drop.innerHTML = html; drop.classList.add('open');
        }
        function close() { drop.classList.remove('open'); active = -1; }
        var doSearch = debounce(function () {
            post(action, { keyword: input.value.trim() }).then(function (res) {
                list = (res && res.data) || [];
                active = list.length ? 0 : -1;
                render();
            });
        }, 180);
        input.addEventListener('input', function () { input.removeAttribute('data-selected-id'); doSearch(); });
        input.addEventListener('focus', doSearch);
        input.addEventListener('keydown', function (e) {
            if (!drop.classList.contains('open')) { if (e.key === 'ArrowDown') doSearch(); return; }
            if (e.key === 'ArrowDown') { e.preventDefault(); active = Math.min(active + 1, list.length - 1); render(); }
            else if (e.key === 'ArrowUp') { e.preventDefault(); active = Math.max(active - 1, 0); render(); }
            else if (e.key === 'Enter') { if (active >= 0 && list[active]) { e.preventDefault(); onSelect(list[active]); close(); } }
            else if (e.key === 'Tab') { if (active >= 0 && list[active]) { onSelect(list[active]); close(); } }
            else if (e.key === 'Escape') { close(); }
        });
        drop.addEventListener('mousedown', function (e) {
            var li = e.target.closest('li[data-i]'); if (!li) return;
            e.preventDefault();
            var i = parseInt(li.getAttribute('data-i'), 10);
            if (list[i]) { onSelect(list[i]); setTimeout(close, 0); }
        });
    }

    /* =====================================================================
     *  KHỐI 1: Danh sách nhóm + Thêm nhóm mới
     * ===================================================================== */
    var addMaterialInput = $('tsg-add-material'), addMaterialDrop = $('tsg-add-material-dropdown');
    var chosenMaterial = null;
    var addSetupList = []; // {product_id, product_name, ratio} — thiết lập nháp trước khi tạo nhóm

    function renderAddSetupTable() {
        var tb = $('tsg-addsetup-tbody');
        if (!addSetupList.length) {
            tb.innerHTML = '<tr class="tsg-st-empty"><td colspan="3">Chưa có sản phẩm nào dùng nguyên liệu này.</td></tr>';
            return;
        }
        var html = '';
        addSetupList.forEach(function (r, i) {
            html += '<tr data-idx="' + i + '">'
                + '<td>' + esc(r.product_name) + '</td>'
                + '<td class="tsg-st-ratio"><input type="text" class="tsg-inline-ratio" inputmode="decimal" value="' + num(r.ratio, 4) + '" data-idx="' + i + '"> %</td>'
                + '<td class="tsg-st-act"><button type="button" class="tsg-st-del" data-idx="' + i + '" title="Xóa">&times;</button></td>'
                + '</tr>';
        });
        tb.innerHTML = html;
    }
    renderAddSetupTable();

    function addSetupUpsert(product_id, product_name, ratio) {
        var idx = -1;
        addSetupList.forEach(function (r, i) { if (r.product_id === product_id) idx = i; });
        if (idx >= 0) addSetupList[idx].ratio = ratio;
        else addSetupList.push({ product_id: product_id, product_name: product_name, ratio: ratio });
        renderAddSetupTable();
    }

    $('tsg-addsetup-tbody').addEventListener('click', function (e) {
        var btn = e.target.closest('.tsg-st-del'); if (!btn) return;
        addSetupList.splice(parseInt(btn.getAttribute('data-idx'), 10), 1);
        renderAddSetupTable();
    });
    $('tsg-addsetup-tbody').addEventListener('change', function (e) {
        var inp = e.target.closest('.tsg-inline-ratio'); if (!inp) return;
        var idx = parseInt(inp.getAttribute('data-idx'), 10);
        var pct = parseNum(inp.value);
        if (pct <= 0) { alert('Tỉ lệ phải lớn hơn 0.'); renderAddSetupTable(); return; }
        addSetupList[idx].ratio = pct;
    });

    var addSetupProductInput = $('tsg-addsetup-product'), addSetupProductDrop = $('tsg-addsetup-product-dropdown');
    var chosenAddSetupProduct = null;
    attachSearch(addSetupProductInput, addSetupProductDrop, 'search_products', function (p) {
        chosenAddSetupProduct = p;
        addSetupProductInput.value = p.name;
    });
    addSetupProductInput.addEventListener('input', function () { chosenAddSetupProduct = null; });

    $('tsg-btn-add-addsetup').addEventListener('click', function () {
        if (!chosenAddSetupProduct) { alert('Chọn sản phẩm.'); return; }
        var pct = parseNum($('tsg-addsetup-ratio').value);
        if (pct <= 0) { alert('Nhập tỉ lệ dùng (%).'); return; }
        addSetupUpsert(chosenAddSetupProduct.id, chosenAddSetupProduct.name, pct);
        addSetupProductInput.value = ''; chosenAddSetupProduct = null; $('tsg-addsetup-ratio').value = '';
    });

    attachSearch(addMaterialInput, addMaterialDrop, 'search_materials', function (m) {
        chosenMaterial = m;
        addMaterialInput.value = m.name;
        post('material_products', { material_id: m.id }).then(function (res) {
            addSetupList = ((res && res.data) || []).map(function (r) {
                return { product_id: r.product_id, product_name: r.product_name, ratio: r.usage_ratio_percent };
            });
            renderAddSetupTable();
        });
    });
    addMaterialInput.addEventListener('input', function () {
        chosenMaterial = null;
        addSetupList = [];
        renderAddSetupTable();
    });

    function renderGroupList() {
        var wrap = $('tsg-group-list');
        if (!groups.length) {
            wrap.innerHTML = '<div class="tsg-group-empty">Chưa có nhóm nào.</div>';
            return;
        }
        var html = '';
        groups.forEach(function (g) {
            var low = g.balance <= 0;
            html += '<div class="tsg-group-card' + (g.group_id === currentGroupId ? ' active' : '') + '" data-id="' + g.group_id + '">'
                + '<div class="tsg-gc-name">' + esc(g.material_name) + '</div>'
                + '<div class="tsg-gc-meta">'
                + '<span class="tsg-gc-balance' + (low ? ' tsg-gc-low' : '') + '">Tồn: ' + num(g.balance, 2) + ' ' + esc(g.unit || '') + '</span>'
                + '<span class="tsg-gc-count">' + g.product_count + ' SP</span>'
                + '</div></div>';
        });
        wrap.innerHTML = html;
    }
    renderGroupList();

    $('tsg-group-list').addEventListener('click', function (e) {
        var card = e.target.closest('.tsg-group-card'); if (!card) return;
        selectGroup(parseInt(card.getAttribute('data-id'), 10));
    });

    $('tsg-btn-add-group').addEventListener('click', function () {
        if (!chosenMaterial) { alert('Chọn nguyên liệu kiểm soát.'); return; }
        var threshold = parseNum($('tsg-add-threshold').value) || 4;
        var note = $('tsg-add-note').value.trim();
        var btn = this; btn.disabled = true;
        post('group_add', {
            material_id: chosenMaterial.id,
            material_name: chosenMaterial.name,
            unit: chosenMaterial.unit || '',
            threshold: threshold,
            note: note
        }).then(function (res) {
            if (!res || !res.success) {
                btn.disabled = false;
                alert((res && res.message) || 'Tạo nhóm thất bại.');
                return;
            }
            var gid = res.group_id;
            var setups = addSetupList.slice();
            Promise.all(setups.map(function (r) {
                return post('setup_add', {
                    group_id: gid,
                    product_id: r.product_id,
                    product_name: r.product_name,
                    usage_ratio_percent: r.ratio
                });
            })).then(function () {
                btn.disabled = false;
                groups = res.data || [];
                renderGroupList();
                addMaterialInput.value = ''; chosenMaterial = null;
                $('tsg-add-note').value = ''; $('tsg-add-threshold').value = '4';
                addSetupList = []; renderAddSetupTable();
                selectGroup(gid);
            });
        });
    });

    var addFaq = $('tsg-add-faq');
    $('tsg-add-faq-head').addEventListener('click', function () { addFaq.classList.toggle('is-collapsed'); });

    /* =====================================================================
     *  KHỐI 2: Chi tiết nhóm
     * ===================================================================== */
    function selectGroup(groupId) {
        currentGroupId = groupId;
        renderGroupList();
        post('group_detail', { group_id: groupId }).then(function (res) {
            if (!res || !res.success) { alert((res && res.message) || 'Không tải được chi tiết nhóm.'); return; }
            lastDetail = res.data;
            renderDetail(lastDetail);
        });
    }

    function reloadDetail() {
        if (!currentGroupId) return;
        post('group_detail', { group_id: currentGroupId }).then(function (res) {
            if (res && res.success) { lastDetail = res.data; renderDetail(lastDetail); }
        });
        post('group_list', {}).then(function (res) {
            if (res && res.success) { groups = res.data || []; renderGroupList(); }
        });
    }

    function renderDetail(data) {
        $('tsg-detail-empty').style.display = 'none';
        $('tsg-detail-body').style.display = '';
        var g = data.group;
        $('tsg-detail-title').textContent = g.material_name;
        $('tsg-detail-summary').innerHTML = 'Tồn NL đã ủ hiện tại: <b>' + num(data.balance, 2) + ' ' + esc(g.unit || '') + '</b>';
        $('tsg-threshold-input').value = g.warning_threshold;

        renderSetupTable(data.setup);
        renderOpening(data.opening);
        renderReceipts(data.receipts);
        renderLedger(data.ledger);
    }

    function renderSetupTable(rows) {
        var tb = $('tsg-setup-tbody');
        if (!rows || !rows.length) {
            tb.innerHTML = '<tr class="tsg-st-empty"><td colspan="3">Chưa có sản phẩm nào dùng nguyên liệu này.</td></tr>';
            return;
        }
        var html = '';
        rows.forEach(function (r) {
            html += '<tr data-id="' + r.id + '">'
                + '<td>' + esc(r.product_name || ('#' + r.product_id)) + '</td>'
                + '<td class="tsg-st-ratio"><input type="text" class="tsg-inline-ratio" inputmode="decimal" value="' + num(r.usage_ratio_percent, 4) + '" data-id="' + r.id + '"> %</td>'
                + '<td class="tsg-st-act"><button type="button" class="tsg-st-del" data-id="' + r.id + '" title="Xóa">&times;</button></td>'
                + '</tr>';
        });
        tb.innerHTML = html;
    }

    $('tsg-setup-tbody').addEventListener('click', function (e) {
        var btn = e.target.closest('.tsg-st-del'); if (!btn) return;
        if (!confirm('Xóa thiết lập sản phẩm này?')) return;
        post('setup_delete', { id: btn.getAttribute('data-id') }).then(function (res) {
            if (res && res.success) reloadDetail();
        });
    });
    $('tsg-setup-tbody').addEventListener('change', function (e) {
        var inp = e.target.closest('.tsg-inline-ratio'); if (!inp) return;
        var pct = parseNum(inp.value);
        if (pct <= 0) { alert('Tỉ lệ phải lớn hơn 0.'); reloadDetail(); return; }
        post('setup_update_ratio', { id: inp.getAttribute('data-id'), usage_ratio_percent: pct }).then(function (res) {
            if (!res || !res.success) { alert((res && res.message) || 'Cập nhật thất bại.'); reloadDetail(); }
        });
    });

    var setupProductInput = $('tsg-setup-product'), setupProductDrop = $('tsg-setup-product-dropdown');
    var chosenSetupProduct = null;
    attachSearch(setupProductInput, setupProductDrop, 'search_products', function (p) {
        chosenSetupProduct = p;
        setupProductInput.value = p.name;
    });
    setupProductInput.addEventListener('input', function () { chosenSetupProduct = null; });

    $('tsg-btn-add-setup').addEventListener('click', function () {
        if (!currentGroupId) { alert('Chọn 1 nhóm trước.'); return; }
        if (!chosenSetupProduct) { alert('Chọn sản phẩm.'); return; }
        var pct = parseNum($('tsg-setup-ratio').value);
        if (pct <= 0) { alert('Nhập tỉ lệ dùng (%).'); return; }
        var btn = this; btn.disabled = true;
        post('setup_add', {
            group_id: currentGroupId,
            product_id: chosenSetupProduct.id,
            product_name: chosenSetupProduct.name,
            usage_ratio_percent: pct
        }).then(function (res) {
            btn.disabled = false;
            if (res && res.success) {
                setupProductInput.value = ''; chosenSetupProduct = null; $('tsg-setup-ratio').value = '';
                reloadDetail();
            } else {
                alert((res && res.message) || 'Lưu thất bại.');
            }
        });
    });

    function renderOpening(opening) {
        if (opening) {
            $('tsg-opening-qty').value = opening.qty;
            $('tsg-opening-date').value = opening.entry_date || todayYMD();
            $('tsg-opening-hint').textContent = 'Đã thiết lập mốc tồn đầu: ' + num(opening.qty, 2) + ' (ngày ' + formatDate(opening.entry_date) + '). Lưu lại để cập nhật.';
        } else {
            $('tsg-opening-qty').value = '';
            $('tsg-opening-date').value = todayYMD();
            $('tsg-opening-hint').textContent = 'Chưa thiết lập mốc tồn đầu.';
        }
    }

    $('tsg-btn-save-opening').addEventListener('click', function () {
        if (!currentGroupId) { alert('Chọn 1 nhóm trước.'); return; }
        var qty = parseNum($('tsg-opening-qty').value);
        if (qty < 0) { alert('Số lượng không hợp lệ.'); return; }
        var btn = this; btn.disabled = true;
        post('opening_set', { group_id: currentGroupId, qty: qty, date: $('tsg-opening-date').value }).then(function (res) {
            btn.disabled = false;
            if (res && res.success) reloadDetail();
            else alert((res && res.message) || 'Lưu mốc thất bại.');
        });
    });

    function renderReceipts(rows) {
        var tb = $('tsg-receipt-tbody');
        if (!rows || !rows.length) {
            tb.innerHTML = '<tr class="tsg-st-empty"><td colspan="3">Chưa có lần nhập thêm nào.</td></tr>';
            return;
        }
        var html = '';
        rows.forEach(function (r) {
            html += '<tr>'
                + '<td>' + esc(formatDate(r.entry_date)) + '</td>'
                + '<td>' + esc(r.content || '') + '</td>'
                + '<td class="tsg-st-ratio">+' + num(r.qty, 2) + '</td>'
                + '</tr>';
        });
        tb.innerHTML = html;
    }

    $('tsg-btn-add-receipt').addEventListener('click', function () {
        if (!currentGroupId) { alert('Chọn 1 nhóm trước.'); return; }
        var qty = parseNum($('tsg-receipt-qty').value);
        if (qty <= 0) { alert('Số lượng phải lớn hơn 0.'); return; }
        var btn = this; btn.disabled = true;
        post('receipt_add', {
            group_id: currentGroupId,
            qty: qty,
            note: $('tsg-receipt-note').value.trim(),
            date: $('tsg-receipt-date').value || todayYMD()
        }).then(function (res) {
            btn.disabled = false;
            if (res && res.success) {
                $('tsg-receipt-qty').value = ''; $('tsg-receipt-note').value = '';
                reloadDetail();
            } else {
                alert((res && res.message) || 'Nhập kho thất bại.');
            }
        });
    });

    function renderLedger(rows) {
        var tb = $('tsg-ledger-tbody');
        if (!rows || !rows.length) {
            tb.innerHTML = '<tr class="tsg-lg-empty"><td colspan="4">Chưa có lịch sử.</td></tr>';
            return;
        }
        var running = 0;
        var html = '';
        rows.forEach(function (r) {
            running += Number(r.qty);
            var signCls = r.qty < 0 ? 'tsg-lg-out' : 'tsg-lg-in';
            var q = (r.qty < 0 ? '' : '+') + num(r.qty, 2);
            html += '<tr>'
                + '<td class="tsg-lg-date">' + esc(formatDate(r.entry_date)) + '</td>'
                + '<td>' + esc(r.content || '') + '</td>'
                + '<td class="tsg-lg-qty ' + signCls + '">' + q + '</td>'
                + '<td class="tsg-lg-qty">' + num(running, 2) + '</td>'
                + '</tr>';
        });
        tb.innerHTML = html;
    }

    $('tsg-btn-save-threshold').addEventListener('click', function () {
        if (!currentGroupId) return;
        var th = parseNum($('tsg-threshold-input').value);
        if (th <= 0) { alert('Ngưỡng phải lớn hơn 0.'); return; }
        post('group_update_threshold', { group_id: currentGroupId, threshold: th }).then(function (res) {
            if (res && res.success) reloadDetail();
            else alert((res && res.message) || 'Lưu ngưỡng thất bại.');
        });
    });

    $('tsg-btn-delete-group').addEventListener('click', function () {
        if (!currentGroupId) return;
        if (!confirm('Xóa nhóm này? Toàn bộ thiết lập và lịch sử sẽ bị xóa.')) return;
        post('group_delete', { group_id: currentGroupId }).then(function (res) {
            if (res && res.success) {
                groups = res.data || [];
                currentGroupId = null;
                renderGroupList();
                $('tsg-detail-body').style.display = 'none';
                $('tsg-detail-empty').style.display = '';
            } else {
                alert((res && res.message) || 'Xóa thất bại.');
            }
        });
    });
})();
