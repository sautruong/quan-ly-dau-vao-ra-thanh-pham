/*
 * picking_slip.js — Phiếu soạn hàng.
 * - In A4 (window.print).
 * - Nhập tay: chọn chi nhánh, thêm SP/NVL bao bì, nhập SL đặt hàng -> quy đổi kiện.
 * - Xác nhận xuất kho: đẩy dữ liệu sang sales_delivery_note (warehouse_outbound).
 */
(function ($) {
    'use strict';

    var BASE = '?mod=order_management&controllers=order_management&action=';

    function escapeHtml(s) {
        return String(s == null ? '' : s)
            .replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;');
    }

    /* ----- In phiếu ----- */
    $(document).on('click', '#ps-print', function () { window.print(); });

    /* ----- Quy đổi tồn / SL: kiện <-> số (cả 2 chế độ) -----
       vd "84B 4" (84 kiện x 6 + 4) <-> "508". Giá trị số lấy sẵn từ data-raw. */
    $(document).on('click', '.ps-conv-tg', function () {
        var $v = $(this).prev('.ps-conv');
        if (!$v.length) return;
        var raw = $v.attr('data-mode') === 'raw';
        $v.text(raw ? ($v.attr('data-kien') || '') : ($v.attr('data-raw') || ''));
        $v.attr('data-mode', raw ? 'kien' : 'raw');
        $(this).toggleClass('is-on', !raw);
        // Quy đổi ô SL đặt hàng -> SP chuyển sang/khỏi nhóm 3 -> sắp lại.
        if ($(this).closest('td').index() === 2) sortSlipRows();
    });

    /* ===== Thứ tự hiển thị: sắp 5 nhóm theo quy cách + cài đặt (cả 2 chế độ) ===== */
    var PS_MIN_ROWS = 20; // mặc định hiển thị tối thiểu 20 dòng, dòng dư không có SP sẽ chìm màu
    function renumber() {
        $('#ps-body tr:not(.ps-empty-row)').each(function (i) { $(this).find('.ps-stt-badge').text(i + 1); });
    }
    // Bù thêm dòng trống cho đủ PS_MIN_ROWS (chỉ đánh số + STT chìm màu, không có dữ liệu SP).
    function ensureMinRows(tb) {
        tb = tb || document.getElementById('ps-body');
        if (!tb) return;
        $(tb).children('tr.ps-empty-row').remove();
        var count = $(tb).children('tr').length;
        var html = '';
        for (var i = count + 1; i <= PS_MIN_ROWS; i++) {
            html += '<tr class="ps-empty-row">'
                  + '<td class="text-center ps-stt"><span class="ps-stt-badge">' + i + '</span></td>'
                  + '<td></td><td class="ps-qty"></td><td></td>'
                  + '</tr>';
        }
        if (html) $(tb).append(html);
    }
    // Nhóm 1: chẵn quy cách (2T) · 2: quy cách + lẻ (2T 3) · 3: đã quy đổi ra số (230)
    //         · 4: lẻ (3 gói) · 5: nguyên vật liệu.
    function rowGroup($tr) {
        if (Number($tr.attr('data-material')) === 1) return 5;
        var $q = $tr.find('td').eq(2).find('.ps-conv').first(); // ô SL đặt hàng
        if ($q.length && $q.attr('data-mode') === 'raw') return 3; // user đã quy đổi ra số
        var whole = Number($tr.attr('data-whole')) || 0;
        var rem   = Number($tr.attr('data-rem')) || 0;
        var ops   = Number($tr.attr('data-ops')) || 0;
        if (ops > 0 && whole > 0 && rem === 0) return 1;
        if (ops > 0 && whole > 0 && rem > 0)  return 2;
        return 4; // lẻ / không quy cách
    }
    // Trong 1 nhóm: có số thứ tự xếp trước (số nhỏ trước; trùng -> A→B); trống xếp cuối A→B.
    function cmpRow($a, $b) {
        var sa = $a.attr('data-sort'), sb = $b.attr('data-sort');
        var na = (sa !== '' && sa != null && !isNaN(sa)) ? Number(sa) : null;
        var nb = (sb !== '' && sb != null && !isNaN(sb)) ? Number(sb) : null;
        if (na !== null && nb !== null) { if (na !== nb) return na - nb; }
        else if (na !== null) return -1;
        else if (nb !== null) return 1;
        return String($a.attr('data-name') || '').localeCompare(String($b.attr('data-name') || ''), 'vi');
    }
    function sortSlipRows() {
        var tb = document.getElementById('ps-body');
        if (!tb) return;
        $(tb).children('tr.ps-empty-row').remove();
        var rows = $(tb).children('tr').get();
        rows.sort(function (a, b) {
            var ga = rowGroup($(a)), gb = rowGroup($(b));
            if (ga !== gb) return ga - gb;
            return cmpRow($(a), $(b));
        });
        rows.forEach(function (r) { tb.appendChild(r); });
        renumber();
        ensureMinRows(tb);
    }

    /* ===== Modal Setting: thứ tự hiển thị phiếu soạn (giống order_factory) ===== */
    var psSettingLoaded = false;
    $(document).on('click', '#ps-setting-btn', function () {
        $('#ps-setting-modal').fadeIn(120);
        if (psSettingLoaded) return;
        $.getJSON(BASE + 'slip_order_list', function (res) {
            if (!res || !res.ok) { $('#ps-setting-list').html('<div class="ps-setting-loading">Không tải được.</div>'); return; }
            var html = '<table class="ps-set-table"><thead><tr><th class="text-left">Tên sản phẩm</th><th style="width:110px;">Thứ tự hiển thị</th></tr></thead><tbody>';
            $.each(res.data, function (i, p) {
                var nm = String(p.product_name || '');
                var cat = p.category_name ? '<div class="ps-set-cat">' + escapeHtml(p.category_name) + '</div>' : '';
                html += '<tr data-name="' + escapeHtml(nm.toLowerCase()) + '">'
                      + '<td class="text-left">' + escapeHtml(nm) + cat + '</td>'
                      + '<td><input type="number" class="ps-set-input" min="1" step="1" data-product-id="' + p.product_id + '" value="' + (p.sort_order != null ? p.sort_order : '') + '"></td>'
                      + '</tr>';
            });
            html += '</tbody></table>';
            $('#ps-setting-list').html(html);
            psSettingLoaded = true;
        });
    });
    $(document).on('click', '#ps-setting-close', function () { $('#ps-setting-modal').fadeOut(120); });
    $(document).on('click', '#ps-setting-modal', function (e) { if (e.target === this) $(this).fadeOut(120); });
    $(document).on('input', '#ps-setting-search', function () {
        var kw = String($(this).val() || '').trim().toLowerCase();
        $('#ps-setting-list .ps-set-table tbody tr').each(function () {
            $(this).toggle(kw === '' || String($(this).data('name')).indexOf(kw) !== -1);
        });
    });
    $(document).on('click', '#ps-setting-save', function () {
        var orders = {};
        $('#ps-setting-list .ps-set-input').each(function () { orders[$(this).data('product-id')] = $(this).val(); });
        var $btn = $(this).prop('disabled', true);
        $.ajax({
            url: BASE + 'slip_order_save', method: 'POST', contentType: 'application/json',
            data: JSON.stringify({ orders: orders }), dataType: 'json'
        }).done(function (res) {
            $btn.prop('disabled', false);
            if (res && res.ok) { $('#ps-setting-modal').fadeOut(120); location.reload(); }
            else alert('Không lưu được.');
        }).fail(function () { $btn.prop('disabled', false); alert('Lỗi mạng/Server.'); });
    });

    // Sắp xếp ngay khi tải (chế độ từ đơn rows đã có sẵn).
    $(function () { sortSlipRows(); });

    /* ----- Xác nhận xuất kho -> đẩy prefill sang sales_delivery_note (cả 2 chế độ) ----- */
    $(document).on('click', '#ps-confirm-outbound', function () {
        if (window.OM_MANUAL) {
            var prefill = buildManualPrefill();
            if (!prefill.items.length) { alert('Chưa có hàng hóa nào trên phiếu.'); return; }
            if (!prefill.customer_id) { alert('Vui lòng chọn chi nhánh.'); return; }
            goOutbound(prefill);
        } else {
            var id = Number(window.OM_ORDER_ID) || 0;
            if (!id) { alert('Không xác định được đơn.'); return; }
            var $btn = $(this).prop('disabled', true);
            $.getJSON(BASE + 'order_prefill', { id: id }, function (res) {
                if (res && res.ok && res.data) { goOutbound(res.data); }
                else { alert((res && res.msg) || 'Không lấy được dữ liệu đơn.'); $btn.prop('disabled', false); }
            }).fail(function () { alert('Lỗi mạng/Server.'); $btn.prop('disabled', false); });
        }
    });
    function goOutbound(prefill) {
        try { sessionStorage.setItem('wo_prefill_order', JSON.stringify(prefill || {})); } catch (e) {}
        window.location.href = '?mod=warehouse_outbound&controllers=warehouse_outbound&action=sales_delivery_note';
    }

    if (!window.OM_MANUAL) return; // chế độ từ đơn: chỉ cần in + quy đổi + xác nhận xuất kho.

    /* ----- Quy đổi kiện (giống om_qty_to_kien phía server) ----- */
    function kienText(qty, opsQty, letter, unit) {
        qty = Number(qty) || 0;
        opsQty = Number(opsQty) || 0;
        if (opsQty <= 0) return (qty || 0) + (unit ? ' ' + unit : '');
        var whole = Math.floor(qty / opsQty);
        var rem = qty - whole * opsQty;
        var t = whole + (letter || '');
        if (rem > 0) t += ' ' + (Math.round(rem * 100) / 100);
        return t;
    }

    /* ----- Prefill từ phiếu nhập tay (cho Xác nhận xuất kho) ----- */
    function buildManualPrefill() {
        var $o = $('#ps-branch option:selected');
        var items = [];
        $('#ps-body tr').each(function () {
            var $tr = $(this);
            var qty = Number($tr.find('.ps-qty-input').val()) || 0;
            if (qty <= 0) return;
            items.push({
                product_id: Number($tr.attr('data-product-id')) || 0,
                item_type: String($tr.attr('data-item-type') || 'product'),
                product_name: String($tr.attr('data-name') || ''),
                quantity: qty,
                unit: String($tr.attr('data-unit') || ''),
                weight_kg: Number($tr.attr('data-weight')) || 0,
                unit_price: Number($tr.attr('data-price')) || 0
            });
        });
        return {
            customer_id: Number($('#ps-branch').val()) || 0,
            customer_name: String($o.data('name') || ''),
            items: items
        };
    }

    /* ----- Chi nhánh ----- */
    $(document).on('change', '#ps-branch', function () {
        var $o = $(this).find('option:selected');
        $('#ps-cust').text($o.data('short') || $o.data('name') || '');
        $('#ps-receiver').text($o.data('receiver') || '');
        var color = $o.data('color') || '#16a34a';
        document.getElementById('om-slip').style.setProperty('--om-accent', color);
    });

    /* ----- Ghi chú: input -> đồng bộ vào span in ----- */
    $(document).on('input', '#ps-note-input', function () {
        $('#ps-note').text($(this).val() || '');
    });

    /* ----- Autocomplete sản phẩm + NVL bao bì (điều khiển bằng phím) ----- */
    var t = null, psActiveIdx = -1;
    $(document).on('input', '#ps-search', function () {
        clearTimeout(t);
        var kw = String($(this).val() || '').trim();
        if (!kw) { $('#ps-suggest').empty().hide(); psActiveIdx = -1; return; }
        t = setTimeout(function () {
            $.getJSON(BASE + 'search_product_slip', { keyword: kw }, function (res) {
                if (!res.ok) return;
                var html = '';
                $.each(res.data, function (i, p) {
                    var tag = p.type === 'material' ? ' <span class="ps-sg-badge">NVL</span>' : '';
                    html += '<li data-id="' + p.product_id + '" data-type="' + (p.type || 'product') + '">'
                          + escapeHtml(p.name) + tag
                          + ' <span class="muted">(' + escapeHtml(p.unit || '') + ')</span></li>';
                });
                psActiveIdx = -1;
                $('#ps-suggest').html(html).toggle(html !== '');
            });
        }, 250);
    });
    // Mũi tên lên/xuống chọn, Tab/Enter xác nhận, Esc đóng.
    $(document).on('keydown', '#ps-search', function (e) {
        var $items = $('#ps-suggest li');
        if (!$items.length || $('#ps-suggest').is(':hidden')) return;
        if (e.key === 'ArrowDown') {
            e.preventDefault(); psActiveIdx = (psActiveIdx + 1) % $items.length;
        } else if (e.key === 'ArrowUp') {
            e.preventDefault(); psActiveIdx = (psActiveIdx - 1 + $items.length) % $items.length;
        } else if (e.key === 'Enter' || e.key === 'Tab') {
            e.preventDefault();
            chooseSuggest($items.eq(psActiveIdx >= 0 ? psActiveIdx : 0));
            return;
        } else if (e.key === 'Escape') {
            $('#ps-suggest').hide(); return;
        } else { return; }
        $items.removeClass('active');
        $items.eq(psActiveIdx).addClass('active')[0].scrollIntoView({ block: 'nearest' });
    });
    $(document).on('click', '#ps-suggest li', function () { chooseSuggest($(this)); });
    function chooseSuggest($li) {
        if (!$li || !$li.length) return;
        var pid = $li.data('id'), type = String($li.data('type') || 'product');
        $('#ps-search').val('');
        $('#ps-suggest').empty().hide();
        psActiveIdx = -1;
        addRow(pid, type);
        setTimeout(function () { $('#ps-search').focus(); }, 30); // focus lại để nhập tiếp
    }
    $(document).on('click', function (e) {
        if (!$(e.target).closest('.om-search-box').length) $('#ps-suggest').hide();
    });

    /* ----- Thêm dòng SP / NVL ----- */
    function addRow(pid, type) {
        type = type || 'product';
        if ($('#ps-body tr[data-product-id="' + pid + '"][data-item-type="' + type + '"]').length) return;
        $.getJSON(BASE + 'product_slip_info', { product_id: pid, type: type }, function (res) {
            if (!res.ok || !res.data) return;
            var d = res.data;
            var invK = d.inv_kien || '';
            var invRaw = (d.stock != null ? d.stock : '');
            var sub = '';
            if (d.pack_label) sub += '<span>' + escapeHtml(d.pack_label) + '</span>';
            if (type === 'material') sub += '<span class="ps-sg-badge">NVL bao bì</span>';
            sub += '<span class="ps-tk">TK: '
                 +   '<span class="ps-conv" data-kien="' + escapeHtml(invK) + '" data-raw="' + escapeHtml(String(invRaw)) + '">' + escapeHtml(invK) + '</span>'
                 +   '<span class="ps-conv-tg no-print" title="Quy đổi tồn ra số"><i class="fa-solid fa-right-left"></i></span>'
                 + '</span>';
            if (type === 'product' && d.reminder_note) {
                sub += '<span class="ps-reminder"><i class="fa-solid fa-thumbtack"></i> ' + escapeHtml(d.reminder_note) + '</span>';
            }
            var tr = '<tr data-product-id="' + d.product_id + '"'
                   + ' data-item-type="' + type + '"'
                   + ' data-material="' + (type === 'material' ? 1 : 0) + '"'
                   + ' data-ops="' + (d.ops_qty || 0) + '"'
                   + ' data-letter="' + (d.letter || '') + '"'
                   + ' data-unit="' + escapeHtml(d.unit || '') + '"'
                   + ' data-name="' + escapeHtml(d.product_name || '') + '"'
                   + ' data-weight="' + (d.weight_kg || 0) + '"'
                   + ' data-price="' + (d.system_price || 0) + '"'
                   + ' data-stock="' + (d.stock != null ? d.stock : '') + '"'
                   + ' data-sort="' + (d.sort_order != null ? d.sort_order : '') + '"'
                   + ' data-whole="0" data-rem="0">'
                   + '<td class="text-center ps-stt"><span class="ps-stt-badge"></span></td>'
                   + '<td class="text-left">'
                   +   '<div class="ps-pname"><i class="fa-solid fa-triangle-exclamation ps-short-flag" style="display:none;"></i>' + escapeHtml(String(d.product_name || '').toUpperCase()) + '</div>'
                   +   '<div class="ps-sub">' + sub + '</div>'
                   + '</td>'
                   + '<td class="ps-qty-cell">'
                   +   '<input type="number" class="ps-qty-input no-print" min="0" step="1" value="0">'
                   +   '<span class="ps-qty-line"><span class="ps-check"></span>'
                   +     '<span class="ps-qty-text ps-conv" data-kien="" data-raw="0"></span>'
                   +     '<span class="ps-conv-tg no-print" title="Quy đổi ra số"><i class="fa-solid fa-right-left"></i></span>'
                   +   '</span>'
                   + '</td>'
                   + '<td class="ps-shapes">'
                   +   '<span class="ps-shapes-set" style="display:none;">'
                   +     '<span class="ps-shape ps-sq"></span><span class="ps-shape ps-ci"></span><span class="ps-shape ps-hex"></span>'
                   +   '</span>'
                   +   '<span class="ps-row-del no-print" title="Xóa">&times;</span>'
                   + '</td>'
                   + '</tr>';
            $('#ps-body').append(tr);
            sortSlipRows();
            recomputeKien();
            recomputeWarn();
        });
    }

    $(document).on('input', '.ps-qty-input', function () {
        var $tr = $(this).closest('tr');
        var qty = Number($(this).val()) || 0;
        var ops = Number($tr.data('ops')) || 0;
        var kt = kienText(qty, ops, $tr.data('letter'), $tr.data('unit'));
        var $v = $tr.find('.ps-qty-text');
        $v.attr('data-kien', kt).attr('data-raw', String(qty));
        $v.text($v.attr('data-mode') === 'raw' ? String(qty) : kt);
        // Cập nhật nhóm quy cách của dòng (whole/rem).
        var whole = ops > 0 ? Math.floor(qty / ops) : 0;
        var rem   = ops > 0 ? (qty - whole * ops) : qty;
        $tr.attr('data-whole', whole).attr('data-rem', rem);
        // Chung kiện chỉ hiện khi SL đặt < quy cách (SP lẻ).
        var isLoose = (ops <= 0) || (qty > 0 && qty < ops);
        $tr.find('.ps-shapes-set').toggle(isLoose);
        recomputeKien();
        recomputeWarn();
    });
    // Sắp lại khi rời ô SL (tránh nhảy dòng liên tục lúc đang gõ).
    $(document).on('change', '.ps-qty-input', function () { sortSlipRows(); });

    $(document).on('click', '.ps-row-del', function () {
        $(this).closest('tr').remove();
        renumber();
        ensureMinRows();
        recomputeKien();
        recomputeWarn();
    });

    /* ----- Cảnh báo thiếu tồn (SL đặt > tồn hiện có) ----- */
    function recomputeWarn() {
        var lines = [];
        $('#ps-body tr').each(function () {
            var $tr = $(this);
            var stockAttr = $tr.attr('data-stock');
            if (stockAttr == null || stockAttr === '') return;
            var stock = Number(stockAttr);
            var qty = Number($tr.find('.ps-qty-input').val()) || 0;
            var isShort = qty > 0 && qty > stock;
            var lack = isShort ? Math.round((qty - stock) * 100) / 100 : 0;
            $tr.find('.ps-short-flag')
                .toggle(isShort)
                .attr('title', isShort ? 'Thiếu tồn: cần ' + qty + ', tồn ' + stock : '');
            if (isShort) {
                lines.push({
                    name: String($tr.attr('data-name') || ''),
                    unit: String($tr.attr('data-unit') || ''),
                    stock: stock,
                    lack: lack
                });
            }
        });
        var $w = $('#ps-stock-warn');
        if (!lines.length) { $w.hide().empty(); return; }
        var html = '<div class="ps-warn-head"><i class="fa-solid fa-triangle-exclamation"></i> Cảnh báo thiếu tồn:</div><ul>';
        lines.forEach(function (l) {
            html += '<li><b>' + escapeHtml(l.name) + '</b>: tồn ' + l.stock
                  + ', thiếu <b>' + l.lack + '</b> ' + escapeHtml(l.unit) + '</li>';
        });
        html += '</ul>';
        $w.html(html).show();
    }

    /* ----- Số kiện dự kiến ----- */
    function recomputeKien() {
        var byLetter = {}, loose = 0;
        $('#ps-body tr').each(function () {
            var $tr = $(this);
            var qty = Number($tr.find('.ps-qty-input').val()) || 0;
            var ops = Number($tr.data('ops')) || 0;
            var letter = String($tr.data('letter') || '');
            if (ops > 0 && letter) {
                var whole = Math.floor(qty / ops);
                var rem = qty - whole * ops;
                if (whole > 0) byLetter[letter] = (byLetter[letter] || 0) + whole;
                if (rem > 0 || whole === 0) loose++;
            } else if (qty > 0) {
                loose++;
            }
        });
        var segs = [];
        Object.keys(byLetter).forEach(function (l) { segs.push(byLetter[l] + l); });
        if (loose > 0) segs.push(loose + ' SP lẻ');
        $('#ps-kien').text(segs.join(' + '));
    }

})(jQuery);
