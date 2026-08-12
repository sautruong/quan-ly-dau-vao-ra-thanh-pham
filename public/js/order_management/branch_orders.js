/*
 * branch_orders.js — thao tác đơn hàng phía nhà máy:
 *  xóa, đánh dấu đã bốc, khóa, hẹn bốc + gửi TB, xem đơn (sửa SL) + gửi TB.
 */
(function ($) {
    'use strict';

    var BASE = '?mod=order_management&controllers=order_management&action=';
    function money(n) { return (Number(n) || 0).toLocaleString('vi-VN', { maximumFractionDigits: 0 }); }
    function wt(n) { return (Number(n) || 0).toLocaleString('vi-VN', { maximumFractionDigits: 2 }); }

    /* ---------- Xóa đơn ---------- */
    $(document).on('click', '.om-card-del', function () {
        var $card = $(this).closest('.om-card');
        if (!confirm('Xóa đơn này? Thao tác không thể hoàn tác.')) return;
        $.post(BASE + 'delete_order', { id: $card.data('id') }, function (res) {
            if (res && res.ok) $card.fadeOut(150, function () { $(this).remove(); });
            else alert('Không xóa được.');
        }, 'json');
    });

    /* ---------- Xác nhận đơn hàng (check -> báo bán hàng; bỏ check -> không báo) ---------- */
    $(document).on('change', '.om-confirm-check', function () {
        var $card = $(this).closest('.om-card');
        var confirmed = $(this).is(':checked');
        $.post(BASE + 'set_confirmed', { id: $card.data('id'), confirmed: confirmed ? 1 : '' }, function (res) {
            if (!res || !res.ok) return;
            $card.toggleClass('is-confirmed', confirmed);
        }, 'json');
    });

    /* ---------- Đánh dấu đã bốc ---------- */
    $(document).on('change', '.om-picked-check', function () {
        var $card = $(this).closest('.om-card');
        var picked = $(this).is(':checked');
        $.post(BASE + 'set_picked', { id: $card.data('id'), picked: picked ? 1 : '' }, function (res) {
            if (res && res.ok) {
                $card.toggleClass('is-picked', picked);
                if (picked && !$card.find('.om-picked-flag').length) {
                    $card.find('.om-card-del').after('<span class="om-picked-flag"><i class="fa-solid fa-circle-check"></i> Đã bốc</span>');
                } else if (!picked) {
                    $card.find('.om-picked-flag').remove();
                }
            }
        }, 'json');
    });

    /* ---------- Khóa / mở khóa ---------- */
    $(document).on('click', '.om-lock-btn', function () {
        var $btn = $(this), $card = $btn.closest('.om-card');
        var lock = !$btn.hasClass('is-on');
        $.post(BASE + 'set_lock', { id: $card.data('id'), locked: lock ? 1 : '' }, function (res) {
            if (!res || !res.ok) return;
            $btn.toggleClass('is-on', lock);
            $card.toggleClass('is-locked', lock);
            $btn.find('i').attr('class', 'fa-solid ' + (lock ? 'fa-lock' : 'fa-lock-open'));
            $btn.attr('title', lock ? 'Đã khóa' : 'Khóa đơn');
            // đồng bộ payload
            var d = $card.data('order'); if (d) { d.locked = lock ? 1 : 0; $card.data('order', d); }
        }, 'json');
    });

    /* ---------- Hẹn bốc + gửi thông báo ---------- */
    $(document).on('click', '.om-send-pickup', function () {
        var $card = $(this).closest('.om-card');
        var val = $card.find('.om-pickup-input').val();
        if (!val) { alert('Chọn ngày giờ bốc trước.'); return; }
        $.post(BASE + 'set_pickup', { id: $card.data('id'), pickup_time: val }, function (res) {
            if (res && res.ok) alert('Đã gửi thông báo lịch bốc cho bán hàng.');
            else alert('Không gửi được.');
        }, 'json');
    });

    /* ---------- Xác nhận xuất kho: tích đã bốc + báo bán hàng + đẩy sang phiếu xuất kho ----------
     * Nếu đơn chưa tích "Đã bốc" -> hỏi qua modal trước; chọn OK thì tự tích "Đã bốc" (tái dùng handler
     * .om-picked-check ở trên) rồi mới thực hiện. Đã tích sẵn -> chạy thẳng, không hỏi lại. */
    var pendingOutbound = null; // { $card, $btn }
    function runConfirmOutbound($card, $btn) {
        $btn.prop('disabled', true);
        $.post(BASE + 'confirm_outbound', { id: $card.data('id') }, function (res) {
            if (res && res.ok) {
                try { sessionStorage.setItem('wo_prefill_order', JSON.stringify(res.prefill || {})); } catch (e) {}
                window.location.href = '?mod=warehouse_outbound&controllers=warehouse_outbound&action=sales_delivery_note';
            } else {
                alert((res && res.msg) || 'Không xử lý được.');
                $btn.prop('disabled', false);
            }
        }, 'json').fail(function () { alert('Lỗi mạng/Server.'); $btn.prop('disabled', false); });
    }
    $(document).on('click', '.om-confirm-outbound', function () {
        var $card = $(this).closest('.om-card'), $btn = $(this);
        if ($card.find('.om-picked-check').is(':checked')) { runConfirmOutbound($card, $btn); return; }
        pendingOutbound = { $card: $card, $btn: $btn };
        $('#om-outbound-confirm-modal').fadeIn(120);
    });
    function closeOutboundConfirm() { $('#om-outbound-confirm-modal').fadeOut(120); pendingOutbound = null; }
    $(document).on('click', '#om-outbound-confirm-cancel, #om-outbound-confirm-close', closeOutboundConfirm);
    $(document).on('click', '#om-outbound-confirm-modal', function (e) { if (e.target === this) closeOutboundConfirm(); });
    $(document).on('click', '#om-outbound-confirm-ok', function () {
        if (!pendingOutbound) { $('#om-outbound-confirm-modal').fadeOut(120); return; }
        var p = pendingOutbound; pendingOutbound = null;
        $('#om-outbound-confirm-modal').fadeOut(120);
        p.$card.find('.om-picked-check').prop('checked', true).trigger('change'); // tự tích "Đã bốc" (đồng bộ AJAX qua handler sẵn có)
        runConfirmOutbound(p.$card, p.$btn);
    });

    /* ---------- Xem đơn + sửa số lượng ---------- */
    var curId = 0, curItems = [], curRemoved = [];
    $(document).on('click', '.om-view-order', function () {
        var data = $(this).closest('.om-card').data('order');
        if (!data) return;
        curId = data.id;
        curItems = data.items || [];
        curRemoved = [];
        $('#om-modal-cust').text(data.customer || '');
        renderModal();
        $('#om-add-search').val('');
        $('#om-add-suggest').empty().hide();
        $('#om-order-modal').fadeIn(120);
    });

    /* ---------- Thêm sản phẩm vào đơn (autocomplete + điều khiển bằng phím) ---------- */
    var addTimer = null, omAddIdx = -1;
    $(document).on('input', '#om-add-search', function () {
        var kw = $(this).val().trim(), $sug = $('#om-add-suggest');
        clearTimeout(addTimer);
        if (kw.length < 1) { $sug.empty().hide(); omAddIdx = -1; return; }
        addTimer = setTimeout(function () {
            $.getJSON(BASE + 'search_product_slip', { keyword: kw }, function (res) {
                if (!res || !res.ok || !res.data || !res.data.length) { $sug.empty().hide(); omAddIdx = -1; return; }
                var html = '';
                $.each(res.data, function (i, r) {
                    html += '<li data-type="' + esc(r.type) + '" data-id="' + r.product_id + '">'
                          + esc(r.name) + ' <span class="muted">(' + esc(r.unit || '') + ')</span></li>';
                });
                omAddIdx = -1;
                $sug.html(html).show();
            });
        }, 250);
    });

    // Mũi tên lên/xuống chọn, Tab/Enter xác nhận, Esc đóng.
    $(document).on('keydown', '#om-add-search', function (e) {
        var $items = $('#om-add-suggest li');
        if (!$items.length || $('#om-add-suggest').is(':hidden')) return;
        if (e.key === 'ArrowDown') {
            e.preventDefault(); omAddIdx = (omAddIdx + 1) % $items.length;
        } else if (e.key === 'ArrowUp') {
            e.preventDefault(); omAddIdx = (omAddIdx - 1 + $items.length) % $items.length;
        } else if (e.key === 'Enter' || e.key === 'Tab') {
            e.preventDefault();
            omChooseSuggest($items.eq(omAddIdx >= 0 ? omAddIdx : 0));
            return;
        } else if (e.key === 'Escape') {
            $('#om-add-suggest').hide(); return;
        } else { return; }
        $items.removeClass('active');
        $items.eq(omAddIdx).addClass('active')[0].scrollIntoView({ block: 'nearest' });
    });

    $(document).on('click', '#om-add-suggest li', function () { omChooseSuggest($(this)); });

    // Chọn 1 gợi ý -> lấy thông tin (giá/đơn vị/khối lượng/tồn), chèn thẳng dòng mới, focus lại input.
    function omChooseSuggest($li) {
        if (!$li || !$li.length) return;
        var type = String($li.data('type') || 'product'), id = $li.data('id');
        $('#om-add-search').val(''); $('#om-add-suggest').empty().hide(); omAddIdx = -1;
        var refocus = function () { setTimeout(function () { $('#om-add-search').focus(); }, 30); };
        // Đã có trong đơn -> không thêm trùng.
        if ($('#om-modal-body tr[data-pid="' + id + '"][data-type="' + type + '"]').length) {
            alert('Sản phẩm này đã có trong đơn.'); refocus(); return;
        }
        $.getJSON(BASE + 'product_slip_info', { product_id: id, type: type }, function (res) {
            if (!res || !res.ok || !res.data) { alert('Không lấy được thông tin hàng hóa.'); refocus(); return; }
            var d = res.data;
            var name = d.product_name || '', unit = d.unit || '';
            var price = Number(d.system_price) || 0, weight = Number(d.weight_kg) || 0;
            var hasStock = (type !== 'material');
            var $tr = $('<tr data-new="1" data-idx="" data-pid="' + id + '" data-type="' + esc(type) + '"'
                + ' data-price="' + price + '" data-weight="' + weight + '"'
                + ' data-stock="' + (hasStock ? (Number(d.stock) || 0) : '') + '"'
                + ' data-name="' + esc(name) + '" data-unit="' + esc(unit) + '">'
                + '<td class="text-left"><button type="button" class="om-row-del" title="Bỏ dòng">&times;</button> ' + nameSwapHtml(name) + '</td>'
                + '<td><input type="text" inputmode="decimal" class="om-qty-input" value="1"> <span class="om-unit">' + esc(unit) + '</span></td>'
                + '<td class="om-line-weight">' + wt(1 * weight) + '</td>'
                + '<td class="om-line-price">' + money(price) + '</td>'
                + '<td class="om-line-value">' + money(price) + '</td>'
                + '<td class="om-status-cell"></td>'
                + '</tr>');
            $('#om-modal-body').append($tr);
            updateStatus($tr);
            updateWarn();
            recalc();
            refocus(); // chọn xong -> sẵn sàng nhập thêm
        });
    }

    // Xóa 1 dòng: dòng cũ -> ghi nhận idx để báo backend bỏ khỏi đơn; dòng mới -> chỉ gỡ khỏi DOM.
    $(document).on('click', '.om-row-del', function () {
        var $tr = $(this).closest('tr');
        var idx = $tr.attr('data-idx');
        if (!$tr.attr('data-new') && idx !== '' && idx != null) curRemoved.push(Number(idx));
        $tr.remove();
        updateWarn();
        recalc();
    });

    // Ẩn gợi ý khi click ra ngoài.
    $(document).on('click', function (e) {
        if (!$(e.target).closest('.om-search-box').length) {
            $('#om-add-suggest').hide();
            $('.om-new-row-suggest').hide();
        }
    });

    function esc(s) {
        return String(s == null ? '' : s).replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;');
    }

    // Tên sản phẩm trong dòng: hover hiện viền, click đổi sang sản phẩm/NVL khác (xem khối "Đổi sản
    // phẩm trong dòng" phía dưới, gần cuối file).
    function nameSwapHtml(name) {
        return '<span class="om-name-swap-wrap"><span class="om-name-swap" title="Đổi sản phẩm">' + esc(name) + '</span></span>';
    }

    function renderModal() {
        var rows = '';
        $.each(curItems, function (i, it) {
            var hasStock = (it.stock != null && it.stock !== '' && !it.is_material);
            var weight = Number(it.weight_kg) || 0;
            rows += '<tr data-idx="' + it.idx + '" data-price="' + it.price + '" data-weight="' + weight + '"'
                  + ' data-pid="' + (it.pid || '') + '" data-type="' + (it.type || 'product') + '"'
                  + ' data-stock="' + (hasStock ? it.stock : '') + '"'
                  + ' data-name="' + esc(it.name) + '" data-unit="' + esc(it.unit || '') + '">'
                  + '<td class="text-left"><button type="button" class="om-row-del no-capture" title="Xóa sản phẩm khỏi đơn">&times;</button> ' + nameSwapHtml(it.name) + '</td>'
                  + '<td><input type="text" inputmode="decimal" class="om-qty-input" value="' + it.qty + '"> '
                  + '<span class="om-unit">' + esc(it.unit || '') + '</span></td>'
                  + '<td class="om-line-weight">' + wt(it.qty * weight) + '</td>'
                  + '<td class="om-line-price">' + money(it.price) + '</td>'
                  + '<td class="om-line-value">' + money(it.value) + '</td>'
                  + '<td class="om-status-cell no-capture"></td>'
                  + '</tr>';
        });
        $('#om-modal-body').html(rows);
        $('#om-modal-body tr').each(function () { updateStatus($(this)); });
        updateWarn();
        recalc();
    }

    // Trạng thái 1 dòng: so SL hiện tại với tồn (data-stock). NVL/không có tồn -> "Đủ".
    function updateStatus($tr) {
        var stockAttr = $tr.attr('data-stock');
        var qty = Number($tr.find('.om-qty-input').val()) || 0;
        var html, lack = 0;
        if (stockAttr !== '' && stockAttr != null) {
            var stock = Number(stockAttr);
            if (qty > stock) {
                lack = Math.round((qty - stock) * 100) / 100;
                html = '<span class="om-tag-short">Thiếu ' + wt(lack) + '</span>';
            } else {
                html = '<span class="om-tag-ok">Đủ</span>';
            }
        } else {
            html = '<span class="om-tag-ok">Đủ</span>';
        }
        $tr.toggleClass('is-short', lack > 0);
        $tr.find('.om-status-cell').html(html);
    }

    // Banner cảnh báo tổng hợp các dòng vượt tồn.
    function updateWarn() {
        var lines = [];
        $('#om-modal-body tr').each(function () {
            var $tr = $(this);
            var stockAttr = $tr.attr('data-stock');
            if (stockAttr === '' || stockAttr == null) return;
            var stock = Number(stockAttr);
            var qty = Number($tr.find('.om-qty-input').val()) || 0;
            if (qty > stock) {
                lines.push({ name: $tr.attr('data-name') || '', unit: $tr.attr('data-unit') || '',
                             stock: stock, lack: Math.round((qty - stock) * 100) / 100 });
            }
        });
        var $w = $('#om-modal-warn');
        if (!lines.length) { $w.hide().empty(); return; }
        var html = '<div class="om-warn-head"><i class="fa-solid fa-triangle-exclamation"></i> Vượt tồn — điều chỉnh quá tồn hiện có:</div><ul>';
        lines.forEach(function (l) {
            html += '<li><b>' + esc(l.name) + '</b>: tồn ' + wt(l.stock) + ', thiếu <b>' + wt(l.lack) + '</b> ' + esc(l.unit) + '</li>';
        });
        html += '</ul>';
        $w.html(html).show();
    }

    $(document).on('input', '.om-qty-input', function () {
        var $tr = $(this).closest('tr');
        var qty = Number($(this).val()) || 0;
        var price = Number($tr.data('price')) || 0;
        var weight = Number($tr.data('weight')) || 0;
        $tr.find('.om-line-value').text(money(qty * price));
        $tr.find('.om-line-weight').text(wt(qty * weight));
        updateStatus($tr);
        updateWarn();
        recalc();
    });

    function recalc() {
        var val = 0, wgt = 0;
        $('#om-modal-body tr').each(function () {
            var qty = Number($(this).find('.om-qty-input').val()) || 0;
            val += qty * (Number($(this).data('price')) || 0);
            wgt += qty * (Number($(this).data('weight')) || 0);
        });
        $('#om-modal-value').text(money(val) + ' đ');
        $('#om-modal-weight').text(money(wgt) + ' kg');
    }

    $(document).on('click', '#om-save-qty', function () {
        var q = {}, additions = [];
        $('#om-modal-body tr').each(function () {
            var $tr = $(this);
            var qty = Number($tr.find('.om-qty-input').val()) || 0;
            if ($tr.attr('data-new')) {
                if (qty <= 0) return; // dòng mới SL 0 -> bỏ qua
                additions.push({
                    type: $tr.attr('data-type') || 'product',
                    product_id: Number($tr.attr('data-pid')) || 0,
                    product_name: $tr.attr('data-name') || '',
                    unit: $tr.attr('data-unit') || '',
                    system_price: Number($tr.attr('data-price')) || 0,
                    weight_kg: Number($tr.attr('data-weight')) || 0,
                    qty: qty
                });
            } else {
                q[$tr.data('idx')] = qty;
            }
        });
        if (!$('#om-modal-body tr').length) { alert('Đơn phải còn ít nhất 1 sản phẩm.'); return; }
        var $btn = $(this).prop('disabled', true);
        $.ajax({
            url: BASE + 'update_quantities', method: 'POST',
            contentType: 'application/json',
            data: JSON.stringify({ id: curId, quantities: q, additions: additions, removed: curRemoved }), dataType: 'json'
        }).done(function (res) {
            if (res && res.ok) {
                // Không hỏi xác nhận -> ẩn modal, ghi nhớ id đơn để chớp sáng card sau khi
                // trang tải lại (dùng chung flashNewOrderCard() ở cuối file).
                try { sessionStorage.setItem('om_new_order_id', String(curId)); } catch (e) {}
                close();
                location.reload();
            } else { alert('Không lưu được.'); $btn.prop('disabled', false); }
        }).fail(function () { alert('Lỗi mạng/Server.'); $btn.prop('disabled', false); });
    });

    /* ---------- Đóng modal ---------- */
    function close() { $('#om-order-modal').fadeOut(120); }
    $(document).on('click', '#om-order-close', close);
    $(document).on('click', '#om-order-modal', function (e) { if (e.target === this) close(); });

    /* ---------- "Chụp" đơn hàng -> copy ảnh vào clipboard để gửi khách (giống Chụp hàng thiếu) ---------- */
    $(document).on('click', '#om-order-capture-btn', function () {
        var $btn = $(this).prop('disabled', true);
        var el = document.getElementById('om-order-capture');
        html2canvas(el, {
            backgroundColor: '#fff', scale: 2,
            ignoreElements: function (node) { return node.classList && node.classList.contains('no-capture'); }
        }).then(function (canvas) {
            canvas.toBlob(function (blob) {
                if (navigator.clipboard && window.ClipboardItem) {
                    navigator.clipboard.write([new ClipboardItem({ 'image/png': blob })]).then(function () {
                        alert('Đã copy ảnh đơn hàng vào clipboard. Dán (Ctrl+V) để gửi cho khách hàng.');
                    }).catch(function () { downloadOrderCanvas(canvas); });
                } else { downloadOrderCanvas(canvas); }
                $btn.prop('disabled', false);
            }, 'image/png');
        });
    });
    function downloadOrderCanvas(canvas) {
        var a = document.createElement('a');
        a.href = canvas.toDataURL('image/png');
        a.download = 'don-hang.png';
        a.click();
    }

    /* =====================================================================
     *  Đổi sản phẩm trong dòng (modal Xem đơn hàng) — hover tên SP hiện viền,
     *  click biến tên thành ô tìm sản phẩm/NVL khác (tái dùng search_product_slip/
     *  product_slip_info như khối "+ Thêm sản phẩm" ở trên). Dòng CŨ (có data-idx
     *  thật, đọc từ đơn đã lưu) không thể đổi product_id tại chỗ ở backend
     *  (om_update_quantities chỉ nhận {idx: qty}) -> khi đổi, đánh dấu idx cũ vào
     *  curRemoved rồi biến dòng thành "dòng mới" (data-new=1) mang product_id mới,
     *  y hệt cách 1 dòng "Thêm sản phẩm" hoạt động — Lưu sẽ gửi đúng sang additions[].
     * ===================================================================== */
    var omSwapIdx = -1, swapTimer = null;

    $(document).on('click', '.om-name-swap', function (e) {
        e.stopPropagation();
        var $span = $(this);
        if ($span.closest('.om-name-swap-wrap').hasClass('is-editing')) return;
        var $wrap = $span.closest('.om-name-swap-wrap');
        var orig = $span.text();
        $wrap.addClass('is-editing').html(
            '<input type="text" class="om-swap-input" autocomplete="off" placeholder="Gõ tên SP/NVL khác...">' +
            '<ul class="om-suggest om-swap-suggest"></ul>'
        );
        $wrap.data('orig', orig);
        var $inp = $wrap.find('.om-swap-input');
        $inp.val('').focus();
    });

    function omRestoreSwapName($wrap, name) {
        omSwapIdx = -1;
        $wrap.removeClass('is-editing').html(
            '<span class="om-name-swap" title="Đổi sản phẩm">' + esc(name) + '</span>'
        );
    }

    $(document).on('input', '.om-swap-input', function () {
        var $inp = $(this), $sug = $inp.siblings('.om-swap-suggest');
        var kw = $inp.val().trim();
        clearTimeout(swapTimer);
        if (kw.length < 1) { $sug.empty().hide(); omSwapIdx = -1; return; }
        swapTimer = setTimeout(function () {
            $.getJSON(BASE + 'search_product_slip', { keyword: kw }, function (res) {
                if (!res || !res.ok || !res.data || !res.data.length) { $sug.empty().hide(); omSwapIdx = -1; return; }
                var html = '';
                $.each(res.data, function (i, r) {
                    html += '<li data-type="' + esc(r.type) + '" data-id="' + r.product_id + '">'
                          + esc(r.name) + ' <span class="muted">(' + esc(r.unit || '') + ')</span></li>';
                });
                omSwapIdx = -1;
                $sug.html(html).show();
            });
        }, 250);
    });

    $(document).on('keydown', '.om-swap-input', function (e) {
        var $inp = $(this), $wrap = $inp.closest('.om-name-swap-wrap');
        if (e.key === 'Escape') { e.preventDefault(); omRestoreSwapName($wrap, $wrap.data('orig')); return; }
        var $items = $inp.siblings('.om-swap-suggest').find('li');
        if (!$items.length || $inp.siblings('.om-swap-suggest').is(':hidden')) return;
        if (e.key === 'ArrowDown') {
            e.preventDefault(); omSwapIdx = (omSwapIdx + 1) % $items.length;
        } else if (e.key === 'ArrowUp') {
            e.preventDefault(); omSwapIdx = (omSwapIdx - 1 + $items.length) % $items.length;
        } else if (e.key === 'Enter' || e.key === 'Tab') {
            e.preventDefault();
            omDoSwap($inp, $items.eq(omSwapIdx >= 0 ? omSwapIdx : 0));
            return;
        } else { return; }
        $items.removeClass('active');
        $items.eq(omSwapIdx).addClass('active')[0].scrollIntoView({ block: 'nearest' });
    });

    $(document).on('click', '.om-swap-suggest li', function () {
        omDoSwap($(this).closest('.om-swap-suggest').siblings('.om-swap-input'), $(this));
    });

    // Click ra ngoài / rời ô mà chưa chọn -> khôi phục tên cũ (delay để li kịp nhận click trước blur).
    $(document).on('focusout', '.om-swap-input', function () {
        var $inp = $(this), $wrap = $inp.closest('.om-name-swap-wrap');
        setTimeout(function () {
            if ($wrap.find('.om-swap-input:focus').length) return;
            if ($wrap.hasClass('is-editing')) omRestoreSwapName($wrap, $wrap.data('orig'));
        }, 150);
    });

    function omDoSwap($inp, $li) {
        if (!$li || !$li.length) return;
        var type = String($li.data('type') || 'product'), id = $li.data('id');
        var $wrap = $inp.closest('.om-name-swap-wrap');
        var $tr = $inp.closest('tr');
        var origName = $wrap.data('orig');

        if (String(id) === String($tr.attr('data-pid')) && type === $tr.attr('data-type')) {
            omRestoreSwapName($wrap, origName); return; // chọn lại đúng SP đang có -> không đổi gì
        }
        var dupe = $('#om-modal-body tr').not($tr).filter('[data-pid="' + id + '"][data-type="' + type + '"]');
        if (dupe.length) { alert('Sản phẩm này đã có trong đơn.'); omRestoreSwapName($wrap, origName); return; }

        $inp.prop('disabled', true);
        $.getJSON(BASE + 'product_slip_info', { product_id: id, type: type }, function (res) {
            if (!res || !res.ok || !res.data) { alert('Không lấy được thông tin hàng hóa.'); omRestoreSwapName($wrap, origName); return; }
            var d = res.data;
            var name = d.product_name || '', unit = d.unit || '';
            var price = Number(d.system_price) || 0, weight = Number(d.weight_kg) || 0;
            var hasStock = (type !== 'material');
            var qty = Number($tr.find('.om-qty-input').val()) || 0;

            // Dòng CŨ (idx thật) -> báo backend bỏ dòng cũ, biến dòng này thành dòng "mới" mang SP mới.
            var oldIdx = $tr.attr('data-idx');
            if (!$tr.attr('data-new') && oldIdx !== '' && oldIdx != null) curRemoved.push(Number(oldIdx));

            $tr.attr('data-new', '1').attr('data-idx', '')
               .attr('data-pid', id).attr('data-type', type)
               .attr('data-stock', hasStock ? (Number(d.stock) || 0) : '')
               .attr('data-name', name).attr('data-unit', unit)
               .data('price', price).data('weight', weight);
            $tr.find('.om-unit').text(unit);
            $tr.find('.om-line-price').text(money(price));
            $tr.find('.om-line-value').text(money(qty * price));
            $tr.find('.om-line-weight').text(wt(qty * weight));
            omRestoreSwapName($wrap, name);
            updateStatus($tr);
            updateWarn();
            recalc();
        }).fail(function () {
            alert('Lỗi mạng/Server.'); omRestoreSwapName($wrap, origName);
        });
    }

    /* =====================================================================
     *  XEM HÀNG THIẾU (trong modal Xem đơn hàng) — chỉ các dòng đang thiếu
     *  tồn (curItems[].short). "Gỡ tạm" + "dự kiến ngày có lại" chỉ tồn tại
     *  trong phiên xem hiện tại (không lưu DB) — đóng modal là mất.
     * ===================================================================== */
    var shortageItems = [];
    var SHORTAGE_MONTH = ['jan', 'feb', 'mar', 'apr', 'may', 'jun', 'jul', 'aug', 'sep', 'oct', 'nov', 'dec'];

    function fmtShortageDate(dateYMD) {
        if (!dateYMD) return { label: 'Chưa xác định', unset: true };
        var p = dateYMD.split('-');
        var d = new Date(Number(p[0]), Number(p[1]) - 1, Number(p[2]));
        var today = new Date(); today.setHours(0, 0, 0, 0);
        var diff = Math.round((d - today) / 86400000);
        var dm = d.getDate() + ' ' + SHORTAGE_MONTH[d.getMonth()];
        var label;
        if (diff > 0) label = diff + ' ngày tới (' + dm + ')';
        else if (diff === 0) label = 'Hôm nay (' + dm + ')';
        else label = 'Đã trễ ' + Math.abs(diff) + ' ngày (' + dm + ')';
        return { label: label, unset: false };
    }

    function renderShortageTable() {
        var rows = '';
        $.each(shortageItems, function (i, it) {
            var dinfo = fmtShortageDate(it.planDate);
            var unit = it.unit || '';
            rows += '<tr data-i="' + i + '">'
                  + '<td class="text-left">' + esc(it.name) + '</td>'
                  + '<td>' + wt(it.qty) + ' ' + esc(unit) + '</td>'
                  + '<td>' + wt(it.stock) + ' ' + esc(unit) + '</td>'
                  + '<td class="om-shortage-lack">' + wt(it.lack) + ' ' + esc(unit) + '</td>'
                  + '<td class="om-shortage-date' + (dinfo.unset ? ' is-unset' : '') + '">' + esc(dinfo.label) + '</td>'
                  + '<td class="no-capture">'
                  + (it.is_material ? '' : '<button type="button" class="om-shortage-row-addplan" title="Thêm sản phẩm này vào lịch sản xuất"><i class="fa-solid fa-calendar-plus"></i></button>')
                  + '<button type="button" class="om-shortage-row-del" title="Gỡ tạm khỏi danh sách">&times;</button></td>'
                  + '</tr>';
        });
        $('#om-shortage-body').html(rows);
        $('#om-shortage-table').toggle(shortageItems.length > 0);
        $('#om-shortage-empty').toggle(shortageItems.length === 0);
    }

    $(document).on('click', '#om-view-shortage-btn', function () {
        shortageItems = (curItems || []).filter(function (it) { return it.short; }).map(function (it) {
            return {
                pid: it.pid, name: it.name, unit: it.unit, qty: it.qty, stock: it.stock, lack: it.lack,
                is_material: !!it.is_material, planDate: ''
            };
        });
        $('#om-shortage-cust').text($('#om-modal-cust').text());
        renderShortageTable();
        $('#om-shortage-modal').fadeIn(120);
    });

    $(document).on('click', '.om-shortage-row-del', function () {
        var i = Number($(this).closest('tr').data('i'));
        shortageItems.splice(i, 1);
        renderShortageTable();
    });

    // Click ô "Dự kiến ngày có lại" -> mở input date; rời đi (blur) là chốt giá trị
    // (để trống -> quay lại "Chưa xác định").
    $(document).on('click', '#om-shortage-body td.om-shortage-date', function () {
        if ($(this).find('input').length) return;
        var $td = $(this), i = Number($td.closest('tr').data('i'));
        $td.html('<input type="date" value="' + (shortageItems[i].planDate || '') + '">');
        $td.find('input').focus().on('blur', function () {
            shortageItems[i].planDate = $(this).val() || '';
            renderShortageTable();
        });
    });

    function closeShortage() { $('#om-shortage-modal').fadeOut(120); }
    $(document).on('click', '#om-shortage-close, #om-shortage-cancel', closeShortage);
    $(document).on('click', '#om-shortage-modal', function (e) { if (e.target === this) closeShortage(); });

    /* Toast góc trái dưới màn hình, tự ẩn — dùng cho các thông báo không cần bấm OK xác nhận. */
    function omToast(msg) {
        var $t = $('<div class="om-toast">' + esc(msg) + '</div>').appendTo('body');
        requestAnimationFrame(function () { $t.addClass('show'); });
        setTimeout(function () {
            $t.removeClass('show');
            setTimeout(function () { $t.remove(); }, 250);
        }, 2200);
    }

    /* ---------- "Thêm vào lịch sản xuất" — TỪNG DÒNG riêng biệt (cạnh nút gỡ tạm), không phải
     * thêm hết 1 lượt: bấm icon -> ô đó xổ ra select 4 mốc, chọn xong gán riêng SẢN PHẨM CỦA DÒNG
     * ĐÓ vào Kế hoạch sản xuất dài ngày (giống hệt cách ô "Dự kiến ngày có lại" xổ input[date]). ---------- */
    $(document).on('click', '.om-shortage-row-addplan', function () {
        var $td = $(this).closest('td');
        if ($td.find('select').length) return; // đang mở rồi
        var i = Number($td.closest('tr').data('i'));
        var it = shortageItems[i];
        if (!it) return;
        var $sel = $(
            '<select class="om-shortage-row-select">' +
                '<option value="">— Chọn thời điểm —</option>' +
                '<option value="tomorrow">Ngày mai</option>' +
                '<option value="2d">2 ngày tới</option>' +
                '<option value="3d">3 ngày tới</option>' +
                '<option value="nextweek">Tuần sau</option>' +
            '</select>'
        );
        $td.empty().append($sel);
        $sel.trigger('focus');
        var done = false;
        function restore() { if (done) return; done = true; renderShortageTable(); }
        $sel.on('blur', restore);
        $sel.on('change', function () {
            var option = $sel.val();
            if (!option) { restore(); return; }
            done = true; // đã xử lý -> chặn blur restore lại lần nữa
            $.post(BASE + 'ajax_add_shortage_to_plan', { product_ids: JSON.stringify([it.pid]), option: option }, function (res) {
                if (res && res.ok) {
                    var p = String(res.plan_date || '').split('-'); // Y-m-d -> d/m/Y
                    var dLabel = p.length === 3 ? (p[2] + '/' + p[1] + '/' + p[0]) : res.plan_date;
                    omToast('Đã thêm "' + it.name + '" vào Kế hoạch sản xuất dài ngày, ngày ' + dLabel + '.');
                } else {
                    alert((res && res.msg) || 'Không thêm được.');
                }
            }, 'json').fail(function () { alert('Lỗi mạng/Server.'); }).always(renderShortageTable);
        });
    });

    /* ---------- "Chụp" hàng thiếu -> copy ảnh vào clipboard để báo khách hàng ---------- */
    $(document).on('click', '#om-shortage-capture-btn', function () {
        var $btn = $(this).prop('disabled', true);
        var el = document.getElementById('om-shortage-capture');
        html2canvas(el, {
            backgroundColor: '#fff', scale: 2,
            ignoreElements: function (node) { return node.classList && node.classList.contains('no-capture'); }
        }).then(function (canvas) {
            canvas.toBlob(function (blob) {
                if (navigator.clipboard && window.ClipboardItem) {
                    navigator.clipboard.write([new ClipboardItem({ 'image/png': blob })]).then(function () {
                        alert('Đã copy ảnh hàng thiếu vào clipboard. Dán (Ctrl+V) để gửi cho khách hàng.');
                    }).catch(function () { downloadShortageCanvas(canvas); });
                } else { downloadShortageCanvas(canvas); }
                $btn.prop('disabled', false);
            }, 'image/png');
        });
    });
    function downloadShortageCanvas(canvas) {
        var a = document.createElement('a');
        a.href = canvas.toDataURL('image/png');
        a.download = 'hang-thieu.png';
        a.click();
    }

    /* =====================================================================
     *  ĐỐI CHIẾU CÔNG THỨC (click ô "Thiếu" trong bảng hàng thiếu)
     *  Định mức product_materials × số lượng thiếu, đối chiếu tồn NVL.
     * ===================================================================== */
    $(document).on('click', '#om-shortage-body td.om-shortage-lack', function () {
        var i = Number($(this).closest('tr').data('i'));
        var it = shortageItems[i];
        if (!it) return;
        if (it.is_material) { alert('NVL / bao bì không có công thức sản xuất để đối chiếu.'); return; }
        openRecipeModal(it);
    });

    function openRecipeModal(it) {
        $('#om-recipe-pname').text(it.name);
        $('#om-recipe-hint').text('Thiếu ' + wt(it.lack) + ' ' + (it.unit || '') + ' — đối chiếu định mức công thức × số lượng thiếu với tồn NVL hiện có.');
        $('#om-recipe-verdict').removeClass('is-ok is-bad').text('Đang kiểm tra…');
        $('#om-recipe-table').hide();
        $('#om-recipe-body').empty();
        $('#om-recipe-modal').fadeIn(120);
        $.getJSON(BASE + 'check_shortage_recipe', { product_id: it.pid, shortage: it.lack }, function (res) {
            if (!res || !res.ok) {
                $('#om-recipe-verdict').removeClass('is-ok').addClass('is-bad').text((res && res.msg) || 'Không kiểm tra được.');
                return;
            }
            if (!res.has_recipe) {
                $('#om-recipe-verdict').removeClass('is-ok is-bad').text('Sản phẩm này chưa có công thức (product_materials) để đối chiếu.');
                return;
            }
            if (res.enough) {
                $('#om-recipe-verdict').removeClass('is-bad').addClass('is-ok').text('✓ Có khả năng sản xuất đủ.');
            } else {
                $('#om-recipe-verdict').removeClass('is-ok').addClass('is-bad').text('✗ ' + res.reason);
            }
            var rows = '';
            $.each(res.components, function (i2, c) {
                var unit = c.unit || '';
                rows += '<tr>'
                      + '<td>' + c.idx + '</td>'
                      + '<td class="text-left">' + esc(c.name) + '</td>'
                      + '<td>' + wt(c.required) + ' ' + esc(unit) + '</td>'
                      + '<td>' + wt(c.stock) + ' ' + esc(unit) + '</td>'
                      + '<td class="' + (c.enough ? 'is-ok' : 'is-short') + '">' + (c.enough ? 'Đủ' : ('Thiếu ' + wt(c.short) + ' ' + esc(unit))) + '</td>'
                      + '</tr>';
            });
            $('#om-recipe-body').html(rows);
            $('#om-recipe-table').show();
        });
    }

    function closeRecipe() { $('#om-recipe-modal').fadeOut(120); }
    $(document).on('click', '#om-recipe-close', closeRecipe);
    $(document).on('click', '#om-recipe-modal', function (e) { if (e.target === this) closeRecipe(); });

    /* =====================================================================
     *  THÊM ĐƠN HÀNG THỦ CÔNG (nhà máy tự nhập cho 1 chi nhánh)
     *  Modal + bảng riêng (#om-new-*) để không đụng state của modal Xem đơn.
     *
     *  Nhập nhanh kiểu bảng tính: 1 dòng nháp (.om-new-row-draft) luôn nằm cuối
     *  bảng khi đang soạn — gõ tên -> chọn gợi ý (mũi tên/Tab/Enter) -> nhảy
     *  sang ô Số lượng -> Enter chốt dòng NGAY và mở dòng nháp kế tiếp để gõ
     *  liên tục. Dòng nháp bỏ trống mà rời đi (click ra ngoài/blur) sẽ tự ẩn,
     *  gọi lại qua nút "+ Thêm sản phẩm / NVL" (#om-new-add-row-btn).
     * ===================================================================== */
    $(document).on('click', '#om-new-order-btn', function () {
        $('#om-new-customer').val('');
        $('#om-new-order-note').val('');
        $('#om-new-modal-body').empty();
        recalcNew();
        addDraftRow(true);
        $('#om-new-order-modal').fadeIn(120);
    });

    function closeNewOrder() { $('#om-new-order-modal').fadeOut(120); }
    $(document).on('click', '#om-new-order-close', closeNewOrder);
    $(document).on('click', '#om-new-order-modal', function (e) { if (e.target === this) closeNewOrder(); });

    $(document).on('click', '#om-new-add-row-btn', function () { addDraftRow(true); });

    // Tạo dòng nháp (nếu chưa có) ở cuối bảng, ẩn nút "+ Thêm sản phẩm".
    function addDraftRow(focus) {
        var $exist = $('#om-new-modal-body tr.om-new-row-draft');
        if ($exist.length) { if (focus) $exist.find('.om-new-row-search').focus(); return; }
        var $tr = $(
            '<tr class="om-new-row-draft">' +
                '<td class="text-left"><div class="om-search-box">' +
                    '<input type="text" class="om-new-row-search" autocomplete="off" placeholder="Gõ tên sản phẩm / NVL...">' +
                    '<ul class="om-suggest om-new-row-suggest"></ul>' +
                '</div></td>' +
                '<td></td><td></td><td></td><td></td>' +
            '</tr>'
        );
        $('#om-new-modal-body').append($tr);
        $('#om-new-add-row-btn').hide();
        if (focus) setTimeout(function () { $tr.find('.om-new-row-search').focus(); }, 30);
    }
    // Bỏ dòng nháp còn trống, hiện lại nút "+ Thêm sản phẩm".
    function removeDraftRow() {
        $('#om-new-modal-body tr.om-new-row-draft').remove();
        $('#om-new-add-row-btn').show();
    }

    var newAddTimer = null, newAddIdx = -1;
    $(document).on('input', '.om-new-row-search', function () {
        var $tr = $(this).closest('tr'), $sug = $tr.find('.om-new-row-suggest');
        var kw = $(this).val().trim();
        clearTimeout(newAddTimer);
        if (kw.length < 1) { $sug.empty().hide(); newAddIdx = -1; return; }
        newAddTimer = setTimeout(function () {
            $.getJSON(BASE + 'search_product_slip', { keyword: kw }, function (res) {
                if (!res || !res.ok || !res.data || !res.data.length) { $sug.empty().hide(); newAddIdx = -1; return; }
                var html = '';
                $.each(res.data, function (i, r) {
                    html += '<li data-type="' + esc(r.type) + '" data-id="' + r.product_id + '">'
                          + esc(r.name) + ' <span class="muted">(' + esc(r.unit || '') + ')</span></li>';
                });
                newAddIdx = -1;
                $sug.html(html).show();
            });
        }, 250);
    });

    // Mũi tên lên/xuống chọn, Tab/Enter xác nhận, Esc đóng (giống ô thêm SP ở modal Xem đơn).
    $(document).on('keydown', '.om-new-row-search', function (e) {
        var $tr = $(this).closest('tr'), $items = $tr.find('.om-new-row-suggest li');
        if (!$items.length || $tr.find('.om-new-row-suggest').is(':hidden')) return;
        if (e.key === 'ArrowDown') {
            e.preventDefault(); newAddIdx = (newAddIdx + 1) % $items.length;
        } else if (e.key === 'ArrowUp') {
            e.preventDefault(); newAddIdx = (newAddIdx - 1 + $items.length) % $items.length;
        } else if (e.key === 'Enter' || e.key === 'Tab') {
            e.preventDefault();
            omChooseNewSuggest($tr, $items.eq(newAddIdx >= 0 ? newAddIdx : 0));
            return;
        } else if (e.key === 'Escape') {
            $tr.find('.om-new-row-suggest').hide(); return;
        } else { return; }
        $items.removeClass('active');
        $items.eq(newAddIdx).addClass('active')[0].scrollIntoView({ block: 'nearest' });
    });

    $(document).on('click', '.om-new-row-suggest li', function () { omChooseNewSuggest($(this).closest('tr'), $(this)); });

    // Dòng nháp bị bỏ trống mà rời focus (click ra ngoài) -> tự ẩn (gọi lại qua nút "+").
    // Dòng đang SỬA (đổi sang SP khác) mà rời focus -> khôi phục lại nội dung cũ.
    $(document).on('focusout', '.om-new-row-search', function () {
        var $tr = $(this).closest('tr');
        setTimeout(function () {
            if ($tr.find(':focus').length) return;               // focus vẫn còn trong dòng (vd đang bấm gợi ý)
            if ($tr.hasClass('om-new-row-draft')) { removeDraftRow(); return; }
            if ($tr.hasClass('om-new-row-editing')) {
                var bak = $tr.data('om-backup');
                if (bak) $tr.html(bak);
                $tr.removeClass('om-new-row-editing').removeData('om-backup');
            }
        }, 150);
    });

    // Hover ô tên SP (đã chọn) hiện viền -> click để đổi sang sản phẩm khác.
    $(document).on('click', '#om-new-modal-body td.text-left', function (e) {
        if ($(e.target).closest('.om-new-row-del').length) return; // bấm nút xóa -> không mở lại tìm kiếm
        var $tr = $(this).closest('tr');
        if ($tr.hasClass('om-new-row-draft') || $tr.hasClass('om-new-row-editing')) return; // đang gõ rồi
        $tr.data('om-backup', $tr.html());
        $tr.addClass('om-new-row-editing');
        $tr.find('td').eq(0).html(
            '<div class="om-search-box">' +
                '<input type="text" class="om-new-row-search" autocomplete="off" placeholder="Gõ tên sản phẩm / NVL...">' +
                '<ul class="om-suggest om-new-row-suggest"></ul>' +
            '</div>'
        );
        setTimeout(function () { $tr.find('.om-new-row-search').focus(); }, 30);
    });

    // Chọn 1 gợi ý -> lấy thông tin hàng hóa, chốt NGAY vào dòng nháp/dòng đang sửa rồi nhảy sang ô Số lượng.
    function omChooseNewSuggest($tr, $li) {
        if (!$li || !$li.length) return;
        var type = String($li.data('type') || 'product'), id = $li.data('id');
        $tr.find('.om-new-row-suggest').empty().hide();
        newAddIdx = -1;
        if ($('#om-new-modal-body tr').not($tr).filter('[data-pid="' + id + '"][data-type="' + type + '"]').length) {
            alert('Sản phẩm này đã có trong đơn.');
            $tr.find('.om-new-row-search').val('').focus();
            return;
        }
        var wasEditing = $tr.hasClass('om-new-row-editing');
        $tr.removeClass('om-new-row-draft om-new-row-editing'); // chặn focusout xoá/khôi phục dòng trong lúc chờ AJAX (giữ backup phòng khi lỗi)
        $.getJSON(BASE + 'product_slip_info', { product_id: id, type: type }, function (res) {
            if (!res || !res.ok || !res.data) {
                alert('Không lấy được thông tin hàng hóa.');
                $tr.addClass(wasEditing ? 'om-new-row-editing' : 'om-new-row-draft');
                $tr.find('.om-new-row-search').focus();
                return;
            }
            var d = res.data;
            var name = d.product_name || '', unit = d.unit || '';
            var price = Number(d.system_price) || 0, weight = Number(d.weight_kg) || 0;
            var hasStock = (type !== 'material');
            $tr.removeData('om-backup');
            $tr.attr({
                'data-pid': id, 'data-type': type, 'data-price': price, 'data-weight': weight,
                'data-stock': hasStock ? (Number(d.stock) || 0) : '',
                'data-name': name, 'data-unit': unit
            });
            var $tds = $tr.find('td');
            $tds.eq(0).html('<button type="button" class="om-new-row-del" title="Bỏ dòng">&times;</button> ' + esc(name));
            $tds.eq(1).html('<input type="number" class="om-new-qty-input" min="0" value="1"> <span class="om-unit">' + esc(unit) + '</span>');
            $tds.eq(2).text(money(price));
            $tds.eq(3).addClass('om-new-line-value').text(money(price));
            $tds.eq(4).addClass('om-new-status-cell');
            updateStatusNew($tr);
            recalcNew();
            $tr.find('.om-new-qty-input').focus().select(); // (#2) chọn xong -> nhảy qua Số lượng
        });
    }

    $(document).on('click', '.om-new-row-del', function () {
        $(this).closest('tr').remove();
        recalcNew();
    });

    $(document).on('input', '.om-new-qty-input', function () {
        var $tr = $(this).closest('tr');
        var qty = Number($(this).val()) || 0;
        var price = Number($tr.data('price')) || 0;
        $tr.find('.om-new-line-value').text(money(qty * price));
        updateStatusNew($tr);
        recalcNew();
    });

    // Enter ở ô Số lượng -> chốt dòng (đã tự cập nhật qua 'input' ở trên) rồi mở dòng nháp kế tiếp để gõ liên tục.
    $(document).on('keydown', '.om-new-qty-input', function (e) {
        if (e.key !== 'Enter') return;
        e.preventDefault();
        addDraftRow(true);
    });

    // Trạng thái 1 dòng: so SL hiện tại với tồn (data-stock). NVL/không có tồn -> "Đủ".
    function updateStatusNew($tr) {
        var stockAttr = $tr.attr('data-stock');
        var qty = Number($tr.find('.om-new-qty-input').val()) || 0;
        var html, lack = 0;
        if (stockAttr !== '' && stockAttr != null) {
            var stock = Number(stockAttr);
            if (qty > stock) {
                lack = Math.round((qty - stock) * 100) / 100;
                html = '<span class="om-tag-short">Thiếu ' + wt(lack) + '</span>';
            } else {
                html = '<span class="om-tag-ok">Đủ</span>';
            }
        } else {
            html = '<span class="om-tag-ok">Đủ</span>';
        }
        $tr.toggleClass('is-short', lack > 0);
        $tr.find('.om-new-status-cell').html(html);
    }

    function recalcNew() {
        var val = 0;
        $('#om-new-modal-body tr').each(function () {
            var qty = Number($(this).find('.om-new-qty-input').val()) || 0;
            val += qty * (Number($(this).data('price')) || 0);
        });
        $('#om-new-modal-value').text(money(val) + ' đ');
    }

    $(document).on('click', '#om-save-new-order', function () {
        var customerId = Number($('#om-new-customer').val()) || 0;
        if (!customerId) { alert('Chọn chi nhánh trước.'); return; }
        var items = [];
        $('#om-new-modal-body tr').each(function () {
            var $tr = $(this);
            var qty = Number($tr.find('.om-new-qty-input').val()) || 0;
            if (qty <= 0) return;
            items.push({
                type: $tr.attr('data-type') || 'product',
                product_id: Number($tr.attr('data-pid')) || 0,
                product_name: $tr.attr('data-name') || '',
                unit: $tr.attr('data-unit') || '',
                system_price: Number($tr.attr('data-price')) || 0,
                weight_kg: Number($tr.attr('data-weight')) || 0,
                qty: qty
            });
        });
        if (!items.length) { alert('Thêm ít nhất 1 sản phẩm.'); return; }
        var orderDate = $('#om-new-order-date').val() || '';
        var note = $('#om-new-order-note').val().trim();
        var $btn = $(this).prop('disabled', true);
        $.post(BASE + 'create_manual_order', { customer_id: customerId, items: JSON.stringify(items), order_date: orderDate, note: note }, function (res) {
            if (res && res.ok) {
                // Không hỏi xác nhận -> ghi nhớ id đơn vừa tạo để phóng to nhẹ card sau khi
                // trang tải lại (xem flashNewOrderCard() cuối file).
                try { sessionStorage.setItem('om_new_order_id', String(res.id)); } catch (e) {}
                location.reload();
            } else {
                alert((res && res.msg) || 'Không tạo được đơn.'); $btn.prop('disabled', false);
            }
        }, 'json').fail(function () { alert('Lỗi mạng/Server.'); $btn.prop('disabled', false); });
    });

    /* =====================================================================
     *  CÀI ĐẶT: tự xóa (ẩn) đơn đã bốc sau X ngày
     * ===================================================================== */
    $(document).on('click', '#om-auto-delete-btn', function () {
        $('#om-auto-delete-modal').fadeIn(120);
    });
    function closeAutoDelete() { $('#om-auto-delete-modal').fadeOut(120); }
    $(document).on('click', '#om-auto-delete-close', closeAutoDelete);
    $(document).on('click', '#om-auto-delete-modal', function (e) { if (e.target === this) closeAutoDelete(); });

    $(document).on('click', '#om-auto-delete-save', function () {
        var days = Number($('input[name="om-auto-delete"]:checked').val()) || 0;
        var $btn = $(this).prop('disabled', true);
        $.post(BASE + 'set_auto_delete_setting', { days: days }, function (res) {
            $btn.prop('disabled', false);
            if (res && res.ok) { alert('Đã lưu cài đặt.'); closeAutoDelete(); }
            else alert('Không lưu được.');
        }, 'json').fail(function () { $btn.prop('disabled', false); alert('Lỗi mạng/Server.'); });
    });

    /* ---------- Card đơn vừa tạo thủ công: phóng to nhẹ rồi trở lại kích cỡ ban đầu
       (thay cho hộp thoại "OK" xác nhận) — id ghi lại trước khi reload ở #om-save-new-order. */
    (function flashNewOrderCard() {
        var id;
        try { id = sessionStorage.getItem('om_new_order_id'); } catch (e) { id = null; }
        if (!id) return;
        try { sessionStorage.removeItem('om_new_order_id'); } catch (e) {}
        var $card = $('.om-card[data-id="' + id + '"]');
        if (!$card.length) return;
        $card.addClass('om-card-pulse');
        setTimeout(function () { $card.removeClass('om-card-pulse'); }, 650);
    })();

    /* =====================================================================
     *  PHIẾU SOẠN GỬI KHO
     * ---------------------------------------------------------------------
     *  Nút trên card có 4 trạng thái:
     *   - chưa gửi   -> bấm là gửi phiếu xuống /warehouse/picking_task
     *   - đang soạn  -> bấm là mở modal xem tiến độ (chưa cho cập nhật đơn)
     *   - soạn xong  -> bấm là mở modal + cho "Cập nhật đơn hàng"
     *   - đã đồng bộ -> chỉ xem lại
     *
     *  LUẬT: phiếu soạn KHÔNG tự ghi vào đơn. Nhân viên kho sửa/xóa thoải mái;
     *  chỉ khi admin bấm "Cập nhật đơn hàng" số của kho mới ghi đè đơn gốc.
     * ===================================================================== */
    var pickingSlip = null;

    /** Báo xong việc rồi tự tắt — không bắt bấm OK cho một việc đã chắc chắn thành công. */
    function omToast(msg) {
        var $t = $('#om-toast');
        if (!$t.length) $t = $('<div id="om-toast" class="om-toast"></div>').appendTo('body');
        $t.html('<i class="fa-solid fa-circle-check"></i> ' + esc(msg)).addClass('is-on');
        clearTimeout(omToast._t);
        omToast._t = setTimeout(function () { $t.removeClass('is-on'); }, 2600);
    }

    $(document).on('click', '.om-send-picking', function () {
        var $btn = $(this);
        var $card = $btn.closest('.om-card');
        var slipId = Number($btn.data('slip-id')) || 0;
        var daDongBo = $btn.hasClass('is-synced');

        // Phiếu đang soạn dở / đã soạn xong nhưng CHƯA cập nhật đơn -> bấm là xem lại.
        // Phiếu ĐÃ cập nhật đơn thì vòng đó khép rồi, bấm là gửi phiếu MỚI — kho hay cần
        // soạn bổ sung cho chính đơn đó.
        if (slipId > 0 && !daDongBo) { openPickingModal(slipId); return; }

        var cust = $card.find('.om-card-cust').text().trim();
        var hoi = daDongBo
            ? 'Đơn "' + cust + '" đã cập nhật theo phiếu soạn trước. Gửi thêm một phiếu soạn MỚI xuống kho?'
            : 'Gửi phiếu soạn đơn "' + cust + '" xuống cho nhân viên kho?';
        if (!confirm(hoi)) return;
        $btn.prop('disabled', true);
        $.post(BASE + 'send_picking_slip', { order_id: $card.data('id') }, function (res) {
            $btn.prop('disabled', false);
            if (!res || !res.ok) { alert((res && res.msg) || 'Không gửi được phiếu soạn.'); return; }
            $btn.attr('data-slip-id', res.id).data('slip-id', res.id)
                .removeClass('is-synced is-done').addClass('is-doing')
                .attr('title', 'Kho đang soạn — bấm để xem tiến độ')
                .find('i').attr('class', 'fa-solid fa-clipboard-list');
            omToast('Đã gửi phiếu soạn xuống kho.');
        }, 'json').fail(function () { $btn.prop('disabled', false); alert('Lỗi mạng/Server.'); });
    });

    function openPickingModal(slipId) {
        $.getJSON(BASE + 'picking_slip_review', { slip_id: slipId }, function (res) {
            if (!res || !res.ok) { alert((res && res.msg) || 'Không tải được phiếu soạn.'); return; }
            pickingSlip = res.slip;
            renderPicking();
            $('#om-picking-modal').fadeIn(120);
        }).fail(function () { alert('Lỗi mạng/Server.'); });
    }

    function renderPicking() {
        var s = pickingSlip;
        if (!s) return;
        var label = (s.customer_short && String(s.customer_short).trim()) || s.customer_name || '';
        $('#om-picking-cust').text(label);

        var done = (s.status === 'done');
        var synced = !!Number(s.synced);
        $('#om-picking-state').text(
            synced ? 'Đã cập nhật đơn hàng theo phiếu này.'
                   : (done ? 'Kho báo soạn xong. Xem lại rồi bấm "Cập nhật đơn hàng" để lấy số thực soạn ghi đè lên đơn.'
                           : 'Kho đang soạn dở — chờ nhân viên bấm "Soạn xong" rồi hãy cập nhật đơn.')
        );
        $('#om-picking-sync').prop('disabled', !done || synced);

        var live = (s.items || []).filter(function (i) { return !i.removed; });
        var gone = (s.items || []).filter(function (i) { return i.removed; });

        $('#om-picking-body').html(live.map(function (it) {
            var d = Number(it.qty_actual) - Number(it.qty_order);
            var dCell = it.added_by_staff
                ? '<span class="om-pk-add">kho thêm</span>'
                : (Math.abs(d) < 0.0001 ? '<span class="om-pk-same">—</span>'
                    : '<span class="om-pk-diff">' + (d > 0 ? '+' : '') + wt(d) + '</span>');
            return '<tr>' +
                '<td class="text-left">' + esc(it.product_name) +
                    (it.picked ? ' <i class="fa-solid fa-circle-check om-pk-ok" title="Kho đã tích bốc đủ"></i>' : '') + '</td>' +
                '<td>' + (it.added_by_staff ? '—' : esc(it.order_kien || wt(it.qty_order))) + '</td>' +
                '<td><b>' + esc(it.kien_text || wt(it.qty_actual)) + '</b></td>' +
                '<td>' + (it.kien_group != null ? it.kien_group : '') + '</td>' +
                '<td>' + dCell + '</td>' +
                '</tr>';
        }).join('') || '<tr><td colspan="5" class="text-center">Phiếu không còn dòng nào.</td></tr>');

        var groups = (s.summary && s.summary.groups) || [];
        var kmap = s.kien_map || {};
        $('#om-picking-kien').html(groups.length
            ? '<b>Đóng chung kiện:</b> ' + groups.slice().sort(function (a, b) { return a - b; })
                .map(function (g) { return 'Kiện (' + g + ') = ' + esc(kmap[String(g)] || '?'); }).join(' · ')
            : '');

        if (!gone.length) $('#om-picking-removed').hide();
        else {
            $('#om-picking-removed').show();
            $('#om-picking-removed-list').html(gone.map(function (it) {
                return '<button type="button" class="om-pk-restore" data-item-id="' + it.id + '">' +
                    '<i class="fa-solid fa-rotate-left"></i> ' + esc(it.product_name) + '</button>';
            }).join(''));
        }

        var sum = s.summary || {};
        $('#om-picking-sum').text('Số kiện dự kiến: ' + (sum.text || '—') +
            (sum.weight ? ' · ' + wt(sum.weight) + ' kg' : ''));
    }

    // Cho lại 1 dòng nhân viên đã gỡ vào phiếu (dùng chung endpoint của module Kho).
    $(document).on('click', '.om-pk-restore', function () {
        var id = Number($(this).data('item-id')) || 0;
        if (!id) return;
        $.post('?mod=warehouse&controllers=warehouse&action=set_item_removed',
            { item_id: id, removed: '' }, function (res) {
                if (!res || !res.ok) { alert((res && res.msg) || 'Không phục hồi được.'); return; }
                pickingSlip = res.slip;
                renderPicking();
            }, 'json').fail(function () { alert('Lỗi mạng/Server.'); });
    });

    $(document).on('click', '#om-picking-sync', function () {
        if (!pickingSlip) return;
        if (!confirm('Lấy số nhân viên kho đã soạn ghi đè lên đơn hàng gốc? Số cũ của đơn sẽ mất.')) return;
        var $btn = $(this).prop('disabled', true);
        $.post(BASE + 'sync_picking_slip', { slip_id: pickingSlip.id }, function (res) {
            $btn.prop('disabled', false);
            if (!res || !res.ok) { alert((res && res.msg) || 'Không cập nhật được.'); return; }
            alert('Đã cập nhật đơn hàng: ' + res.lines + ' dòng, ' + wt(res.weight) + ' kg.');
            location.reload();
        }, 'json').fail(function () { $btn.prop('disabled', false); alert('Lỗi mạng/Server.'); });
    });

    $(document).on('click', '#om-picking-close', function () { $('#om-picking-modal').fadeOut(120); });
    $(document).on('click', '#om-picking-modal', function (e) {
        if (e.target === this) $(this).fadeOut(120);
    });

})(jQuery);
