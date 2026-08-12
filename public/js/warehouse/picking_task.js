/*
 * ============================================================================
 *  KHO — view "Soạn hàng" (/warehouse/picking_task)
 * ----------------------------------------------------------------------------
 *  Nhân viên kho thao tác trực tiếp trên điện thoại hoặc máy tính.
 *
 *  MỌI THAY ĐỔI ĐỀU GHI THẲNG LÊN SERVER rồi vẽ lại từ dữ liệu server trả về —
 *  cố ý không giữ trạng thái ở client, vì phiếu có thể mở song song trên điện
 *  thoại và máy tính, giữ ở client là hai bên lệch nhau ngay.
 *
 *  Cử chỉ (pointer events nên dùng chung cho cả chạm lẫn chuột):
 *   - Gạt TÊN HÀNG sang phải  -> ô nhập số chung kiện
 *   - Gạt Ô SỐ LƯỢNG sang trái -> ô nhập số bốc thực tế (dừng gõ 2s là tự lưu)
 *  Trên máy tính là bấm giữ chuột rồi kéo, đúng như anh Sáu chốt.
 * ============================================================================
 */
(function ($) {
    'use strict';

    var BASE = '?mod=warehouse&controllers=warehouse&action=';
    var SLIP = window.WPK_SLIP || null;
    var rawMode = {};          // {itemId: true} — dòng đang hiện SỐ thay vì quy đổi kiện
    var qtyTimer = {};         // {itemId: timeoutId} — hẹn 2s tự lưu số bốc
    var kienTimer = null;

    function esc(s) {
        return String(s == null ? '' : s)
            .replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;').replace(/'/g, '&#39;');
    }
    function num(n) {
        n = Number(n) || 0;
        return (Math.round(n * 100) / 100).toLocaleString('vi-VN');
    }
    function slipId() { return SLIP ? Number(SLIP.id) : 0; }
    function editable() {
        return !!SLIP && !Number(SLIP.synced) && (SLIP.status === 'new' || SLIP.status === 'doing');
    }

    /* =====================================================================
     *  SẮP XẾP — dùng ĐÚNG thuật toán của phiếu soạn A4 (picking_slip.js) để
     *  nhân viên cầm phiếu giấy và nhìn màn hình thấy cùng một thứ tự.
     *  Nhóm 1: chẵn quy cách (2T) · 2: quy cách + lẻ (2T 3) · 3: đã quy đổi ra
     *  số · 4: lẻ · 5: nguyên vật liệu.
     * ===================================================================== */
    function rowGroup(it) {
        if (it.item_type === 'material') return 5;
        if (rawMode[it.id]) return 3;
        var ops = Number(it.ops_qty) || 0;
        var whole = Number(it.kien_whole) || 0;
        var rem = Number(it.kien_rem) || 0;
        if (ops > 0 && whole > 0 && rem === 0) return 1;
        if (ops > 0 && whole > 0 && rem > 0) return 2;
        return 4;
    }
    function cmpRow(a, b) {
        var sa = a.sort_order, sb = b.sort_order;
        var na = (sa !== null && sa !== '' && !isNaN(sa)) ? Number(sa) : null;
        var nb = (sb !== null && sb !== '' && !isNaN(sb)) ? Number(sb) : null;
        if (na !== null && nb !== null) { if (na !== nb) return na - nb; }
        else if (na !== null) return -1;
        else if (nb !== null) return 1;
        return String(a.product_name || '').localeCompare(String(b.product_name || ''), 'vi');
    }
    function sortItems(items) {
        return items.slice().sort(function (a, b) {
            var ga = rowGroup(a), gb = rowGroup(b);
            if (ga !== gb) return ga - gb;
            return cmpRow(a, b);
        });
    }

    /* =====================================================================
     *  VẼ
     * ===================================================================== */

    function rowHtml(it, stt) {
        var locked = !!it.picked;
        var qtyText = rawMode[it.id] ? num(it.qty_actual) : (it.kien_text || '');
        var sub = [];
        if (it.pack_label) sub.push(esc(it.pack_label));
        if (it.inv_kien) sub.push('TK: ' + esc(it.inv_kien));
        if (it.item_type === 'material') sub.push('<span class="wpk-tag">NVL</span>');
        if (it.added_by_staff) sub.push('<span class="wpk-tag wpk-tag-add">kho thêm</span>');

        var warn = it.is_short
            ? '<i class="fa-solid fa-triangle-exclamation wpk-short" title="Thiếu tồn: cần ' + num(it.qty_actual) + ', tồn ' + num(it.stock) + '"></i>'
            : '';
        var grp = (it.kien_group !== null && it.kien_group !== undefined)
            ? '<span class="wpk-grp-badge" title="Chung kiện ' + it.kien_group + '">' + it.kien_group + '</span>' : '';
        var remind = it.reminder_note
            ? '<div class="wpk-remind"><i class="fa-solid fa-thumbtack"></i> ' + esc(it.reminder_note) + '</div>' : '';

        return '' +
            '<div class="wpk-row' + (locked ? ' is-picked' : '') + '" data-id="' + it.id + '">' +
              '<div class="wpk-row-main">' +
                '<div class="wpk-name-wrap" data-swipe="right">' +
                  '<span class="wpk-stt">' + stt + '</span>' +
                  '<div class="wpk-name">' +
                    '<div class="wpk-name-text">' + warn + esc(String(it.product_name || '').toUpperCase()) + grp + '</div>' +
                    (sub.length ? '<div class="wpk-sub">' + sub.join(' · ') + '</div>' : '') +
                    remind +
                  '</div>' +
                '</div>' +
                '<div class="wpk-qty-wrap" data-swipe="left">' +
                  '<label class="wpk-chk"><input type="checkbox" class="wpk-picked"' + (locked ? ' checked' : '') + '><span></span></label>' +
                  '<span class="wpk-qty">' + esc(qtyText) + '</span>' +
                '</div>' +
                '<button type="button" class="wpk-del" title="Gỡ khỏi phiếu">&times;</button>' +
              '</div>' +
              '<div class="wpk-slot wpk-slot-group" hidden>' +
                '<label>Chung kiện số</label>' +
                '<input type="number" class="wpk-group-input" min="1" step="1" inputmode="numeric" value="' +
                  (it.kien_group !== null && it.kien_group !== undefined ? it.kien_group : '') + '">' +
                '<button type="button" class="wpk-slot-clear" title="Bỏ chung kiện"><i class="fa-solid fa-xmark"></i></button>' +
              '</div>' +
              '<div class="wpk-slot wpk-slot-qty" hidden>' +
                '<label>Bốc thực tế</label>' +
                '<input type="number" class="wpk-qty-input" min="0" step="1" inputmode="decimal" value="' + (Number(it.qty_actual) || 0) + '">' +
                '<span class="wpk-slot-unit">' + esc(it.unit || '') + '</span>' +
              '</div>' +
            '</div>';
    }

    function renderList() {
        var $list = $('#wpk-list');
        if (!$list.length || !SLIP) return;
        var live = (SLIP.items || []).filter(function (i) { return !i.removed; });
        var gone = (SLIP.items || []).filter(function (i) { return i.removed; });

        var html = '';
        sortItems(live).forEach(function (it, i) { html += rowHtml(it, i + 1); });
        $list.html(html || '<p class="wpk-empty">Phiếu này không còn dòng hàng nào.</p>');

        // Khối "đã gỡ": giữ lại để admin (và cả nhân viên lỡ tay) bấm cho vào lại.
        var $rm = $('#wpk-removed');
        if (!gone.length) { $rm.attr('hidden', true); }
        else {
            $rm.removeAttr('hidden');
            $('#wpk-removed-count').text('(' + gone.length + ')');
            $('#wpk-removed-list').html(gone.map(function (it) {
                return '<div class="wpk-removed-item" data-id="' + it.id + '">' +
                    '<span>' + esc(it.product_name) + '</span>' +
                    '<button type="button" class="wpk-restore" title="Đưa lại vào phiếu"><i class="fa-solid fa-rotate-left"></i></button>' +
                    '</div>';
            }).join(''));
        }

        $('#wpk-slip').toggleClass('is-locked', !editable());
        $('#wpk-finish').prop('disabled', !editable());
    }

    function renderKien() {
        var $box = $('#wpk-kien');
        if (!$box.length || !SLIP) return;
        var groups = (SLIP.summary && SLIP.summary.groups) || [];
        if (!groups.length) { $box.empty().attr('hidden', true); return; }
        groups = groups.slice().sort(function (a, b) { return a - b; });
        var map = SLIP.kien_map || {};
        var invalid = (SLIP.summary && SLIP.summary.invalid) || [];
        var html = '<div class="wpk-kien-head"><i class="fa-solid fa-box"></i> Đóng chung kiện</div>';
        groups.forEach(function (g) {
            var v = map[String(g)] || '';
            var bad = invalid.indexOf(g) !== -1;
            html += '<div class="wpk-kien-row' + (bad ? ' is-bad' : '') + '" data-group="' + g + '">' +
                '<label>Kiện (' + g + ') =</label>' +
                '<input type="text" class="wpk-kien-input" value="' + esc(v) + '" placeholder="1T hoặc 1B" autocomplete="off">' +
                '</div>';
        });
        html += '<div class="wpk-kien-hint">Bắt buộc có <b>T</b> (thùng) hoặc <b>B</b> (bao) — ví dụ <b>1T</b>, <b>2B</b>.</div>';
        $box.html(html).removeAttr('hidden');
    }

    function renderSummary() {
        if (!SLIP) return;
        var s = SLIP.summary || {};
        $('#wpk-sum').text(s.text || '—');
        var msgs = [];
        if (s.invalid && s.invalid.length) {
            msgs.push('Kiện ' + s.invalid.join(', ') + ' chưa khai đúng (phải có T hoặc B).');
        }
        var shorts = (SLIP.items || []).filter(function (i) { return !i.removed && i.is_short; });
        if (shorts.length) {
            msgs.push('Thiếu tồn: ' + shorts.map(function (i) {
                return i.product_name + ' (tồn ' + num(i.stock) + ')';
            }).join('; '));
        }
        var $w = $('#wpk-warn');
        if (!msgs.length) { $w.attr('hidden', true).empty(); return; }
        $w.removeAttr('hidden').html('<i class="fa-solid fa-triangle-exclamation"></i> ' + msgs.map(esc).join('<br>'));
    }

    function renderAll() { renderList(); renderKien(); renderSummary(); }

    /* =====================================================================
     *  GỬI LỆNH
     * ===================================================================== */

    function post(action, data, done) {
        $.post(BASE + action, data, null, 'json')
            .done(function (res) {
                if (!res || !res.ok) { alert((res && res.msg) || 'Không lưu được.'); return; }
                if (res.slip) { SLIP = res.slip; renderAll(); }
                if (typeof done === 'function') done(res);
            })
            .fail(function () { alert('Lỗi mạng / máy chủ.'); });
    }

    /* =====================================================================
     *  CỬ CHỈ GẠT — pointer events, chạy được cả chạm lẫn chuột.
     *  Chỉ kích hoạt khi đi ngang rõ hơn đi dọc, nếu không sẽ cướp mất thao
     *  tác cuộn trang trên điện thoại.
     * ===================================================================== */
    var NGUONG = 40;
    var sw = null;

    $(document).on('pointerdown', '.wpk-name-wrap, .wpk-qty-wrap', function (e) {
        if (!editable()) return;
        var $row = $(this).closest('.wpk-row');
        if ($row.hasClass('is-picked')) return;         // đã tích = khóa
        if ($(e.target).closest('.wpk-chk, input, button').length) return;
        sw = { x: e.clientX, y: e.clientY, dir: $(this).data('swipe'), row: $row, fired: false };
    });

    $(document).on('pointermove', function (e) {
        if (!sw || sw.fired) return;
        var dx = e.clientX - sw.x, dy = e.clientY - sw.y;
        if (Math.abs(dx) < NGUONG || Math.abs(dx) < Math.abs(dy) * 1.5) return;
        if (sw.dir === 'right' && dx > 0) { sw.fired = true; openSlot(sw.row, 'group'); }
        else if (sw.dir === 'left' && dx < 0) { sw.fired = true; openSlot(sw.row, 'qty'); }
    });

    // Gạt xong thì nhả tay VẪN sinh ra 1 click — nuốt cái click đó, nếu không nó sẽ
    // chạy tiếp handler quy đổi kiện/mở modal và vẽ lại danh sách, xóa mất ô vừa mở.
    var nuotClick = false;
    $(document).on('pointerup pointercancel', function () {
        if (sw && sw.fired) {
            nuotClick = true;
            setTimeout(function () { nuotClick = false; }, 300);
        }
        sw = null;
    });

    function openSlot($row, which) {
        $('.wpk-slot').attr('hidden', true);
        var $slot = $row.find(which === 'group' ? '.wpk-slot-group' : '.wpk-slot-qty');
        $slot.removeAttr('hidden');
        var $inp = $slot.find('input');
        $inp.trigger('focus');
        if ($inp[0] && $inp[0].select) $inp[0].select();
    }

    /* =====================================================================
     *  Ô "BỐC THỰC TẾ" — gõ xong dừng 2s là tự lưu (yêu cầu của anh Sáu).
     * ===================================================================== */
    $(document).on('input', '.wpk-qty-input', function () {
        var $inp = $(this);
        var id = Number($inp.closest('.wpk-row').data('id')) || 0;
        if (!id) return;
        clearTimeout(qtyTimer[id]);
        var val = $inp.val();
        qtyTimer[id] = setTimeout(function () {
            post('set_item_qty', { item_id: id, qty: Number(val) || 0 });
        }, 2000);
    });
    // Enter / rời ô -> lưu ngay, khỏi phải chờ hết 2s.
    $(document).on('keydown', '.wpk-qty-input', function (e) {
        if (e.key !== 'Enter') return;
        e.preventDefault();
        $(this).trigger('blur');
    });
    // focusout chứ không phải blur: blur không nổi bọt nên ủy quyền qua document không ăn.
    $(document).on('focusout', '.wpk-qty-input', function () {
        var $row = $(this).closest('.wpk-row');
        var id = Number($row.data('id')) || 0;
        if (!id) return;
        clearTimeout(qtyTimer[id]);
        var it = ((SLIP && SLIP.items) || []).filter(function (x) { return Number(x.id) === id; })[0];
        var v = Number($(this).val()) || 0;
        if (it && Number(it.qty_actual) === v) return;   // không đổi thì khỏi gọi server
        post('set_item_qty', { item_id: id, qty: v });
    });

    /* ----- Ô "chung kiện số" ----- */
    $(document).on('change', '.wpk-group-input', function () {
        var id = Number($(this).closest('.wpk-row').data('id')) || 0;
        if (!id) return;
        post('set_item_group', { item_id: id, group: $(this).val() });
    });
    $(document).on('click', '.wpk-slot-clear', function () {
        var id = Number($(this).closest('.wpk-row').data('id')) || 0;
        if (!id) return;
        post('set_item_group', { item_id: id, group: '' });
    });

    /* ----- Tích "bốc đủ" = khóa dòng; tích lại = mở ra sửa ----- */
    $(document).on('change', '.wpk-picked', function () {
        var $row = $(this).closest('.wpk-row');
        var id = Number($row.data('id')) || 0;
        if (!id) return;
        post('set_item_picked', { item_id: id, picked: this.checked ? 1 : 0 });
    });

    /* ----- Gỡ / phục hồi dòng ----- */
    $(document).on('click', '.wpk-del', function () {
        var $row = $(this).closest('.wpk-row');
        var id = Number($row.data('id')) || 0;
        if (!id) return;
        if (!confirm('Gỡ "' + $row.find('.wpk-name-text').text().trim() + '" khỏi phiếu soạn?')) return;
        post('set_item_removed', { item_id: id, removed: 1 });
    });
    $(document).on('click', '.wpk-restore', function () {
        var id = Number($(this).closest('.wpk-removed-item').data('id')) || 0;
        if (!id) return;
        post('set_item_removed', { item_id: id, removed: 0 });
    });

    /* ----- Bấm ô số lượng: quy đổi kiện <-> số ----- */
    $(document).on('click', '.wpk-qty', function () {
        if (nuotClick) return;
        var id = Number($(this).closest('.wpk-row').data('id')) || 0;
        if (!id) return;
        rawMode[id] = !rawMode[id];
        renderList();
    });

    /* ----- Bấm tên hàng: modal tồn kho + quy cách ----- */
    $(document).on('click', '.wpk-name-text', function () {
        if (nuotClick) return;
        var id = Number($(this).closest('.wpk-row').data('id')) || 0;
        if (!id || !SLIP) return;
        var it = (SLIP.items || []).filter(function (x) { return Number(x.id) === id; })[0];
        if (!it) return;
        $('#wpk-info-name').text(it.product_name || '');
        var lines = '';
        lines += '<div class="wpk-info-line"><span>Tồn kho:</span><b>' + num(it.stock) + ' ' + esc(it.unit || '') +
                 (it.inv_kien ? ' <i>(' + esc(it.inv_kien) + ')</i>' : '') + '</b></div>';
        lines += '<div class="wpk-info-line"><span>QC:</span><b>' +
                 (it.pack_label ? esc(it.pack_label) : 'Không có quy cách ngoài') + '</b></div>';
        lines += '<div class="wpk-info-line"><span>Đơn đặt:</span><b>' + esc(it.order_kien || num(it.qty_order)) + '</b></div>';
        lines += '<div class="wpk-info-line"><span>Đang bốc:</span><b>' + esc(it.kien_text || num(it.qty_actual)) + '</b></div>';
        if (it.kien_group !== null && it.kien_group !== undefined) {
            lines += '<div class="wpk-info-line"><span>Chung kiện:</span><b>' + it.kien_group + '</b></div>';
        }
        $('#wpk-info-lines').html(lines);
        $('#wpk-info-modal').removeAttr('hidden');
    });
    $(document).on('click', '#wpk-info-close', function () { $('#wpk-info-modal').attr('hidden', true); });
    $(document).on('click', '#wpk-info-modal', function (e) {
        if (e.target === this) $(this).attr('hidden', true);
    });

    /* ----- Khai chung kiện "Kiện (1) = 1T" ----- */
    function collectKien() {
        var map = {};
        $('#wpk-kien .wpk-kien-row').each(function () {
            map[String($(this).data('group'))] = String($(this).find('.wpk-kien-input').val() || '').trim();
        });
        return map;
    }
    $(document).on('input', '.wpk-kien-input', function () {
        var $row = $(this).closest('.wpk-kien-row');
        var v = String($(this).val() || '').trim().toUpperCase();
        $row.toggleClass('is-bad', v !== '' && !/^\d+\s*[TB]$/.test(v));
        clearTimeout(kienTimer);
        var map = collectKien();
        kienTimer = setTimeout(function () {
            // Vẽ lại kien sẽ cướp con trỏ đang gõ -> chỉ cập nhật dòng tổng.
            $.post(BASE + 'save_kien', { slip_id: slipId(), map: JSON.stringify(map) }, null, 'json')
                .done(function (res) {
                    if (res && res.ok && res.slip) { SLIP = res.slip; renderSummary(); }
                });
        }, 800);
    });

    /* ----- Ghi chú ----- */
    var noteTimer = null;
    $(document).on('input', '#wpk-note', function () {
        var v = $(this).val();
        clearTimeout(noteTimer);
        noteTimer = setTimeout(function () {
            $.post(BASE + 'save_note', { slip_id: slipId(), note: v }, null, 'json');
        }, 900);
    });

    /* =====================================================================
     *  THÊM HÀNG VÀO PHIẾU — gõ ra gợi ý, mũi tên chọn, Enter/Tab xác nhận.
     *  CỐ Ý cho trùng tên: nhân viên có thể tách 1 mặt hàng thành 2 dòng để
     *  đóng vào 2 kiện khác nhau.
     * ===================================================================== */
    var sgTimer = null, sgIdx = -1;
    $(document).on('input', '#wpk-search', function () {
        clearTimeout(sgTimer);
        var kw = String($(this).val() || '').trim();
        if (!kw) { $('#wpk-suggest').empty().hide(); sgIdx = -1; return; }
        sgTimer = setTimeout(function () {
            $.getJSON(BASE + 'search_item', { keyword: kw }, function (res) {
                if (!res || !res.ok) return;
                var html = '';
                (res.data || []).forEach(function (p) {
                    html += '<li data-id="' + p.product_id + '" data-type="' + esc(p.type || 'product') + '">' +
                        esc(p.name) + (p.type === 'material' ? ' <span class="wpk-tag">NVL</span>' : '') +
                        ' <span class="wpk-sg-unit">(' + esc(p.unit || '') + ')</span></li>';
                });
                sgIdx = html ? 0 : -1;
                $('#wpk-suggest').html(html).toggle(html !== '').children().eq(0).addClass('is-active');
            });
        }, 250);
    });
    $(document).on('keydown', '#wpk-search', function (e) {
        var $items = $('#wpk-suggest li');
        if (!$items.length || $('#wpk-suggest').is(':hidden')) return;
        if (e.key === 'ArrowDown') { e.preventDefault(); sgIdx = (sgIdx + 1) % $items.length; }
        else if (e.key === 'ArrowUp') { e.preventDefault(); sgIdx = (sgIdx - 1 + $items.length) % $items.length; }
        else if (e.key === 'Enter' || e.key === 'Tab') { e.preventDefault(); chooseSuggest($items.eq(sgIdx >= 0 ? sgIdx : 0)); return; }
        else if (e.key === 'Escape') { $('#wpk-suggest').hide(); return; }
        else { return; }
        $items.removeClass('is-active');
        $items.eq(sgIdx).addClass('is-active')[0].scrollIntoView({ block: 'nearest' });
    });
    $(document).on('click', '#wpk-suggest li', function () { chooseSuggest($(this)); });
    $(document).on('click', function (e) {
        if (!$(e.target).closest('.wpk-search-box').length) $('#wpk-suggest').hide();
    });
    function chooseSuggest($li) {
        if (!$li || !$li.length) return;
        var id = Number($li.data('id')) || 0;
        var type = String($li.data('type') || 'product');
        $('#wpk-search').val('');
        $('#wpk-suggest').empty().hide();
        sgIdx = -1;
        post('add_item', { slip_id: slipId(), type: type, item_id: id }, function (res) {
            // Dòng mới luôn bắt đầu từ 0 -> mở sẵn ô nhập số cho nhân viên gõ.
            var $row = $('.wpk-row[data-id="' + res.new_item_id + '"]');
            if ($row.length) openSlot($row, 'qty');
        });
        setTimeout(function () { $('#wpk-search').trigger('focus'); }, 30);
    }

    /* =====================================================================
     *  SOẠN XONG
     * ===================================================================== */
    $(document).on('click', '#wpk-finish', function () {
        if (!SLIP) return;
        var left = (SLIP.items || []).filter(function (i) { return !i.removed && !i.picked; });
        if (left.length) {
            alert('Còn ' + left.length + ' mặt hàng chưa tích bốc đủ. Tích hết mới gọi là soạn xong.');
            return;
        }
        var $btn = $(this).prop('disabled', true);
        $.post(BASE + 'finish_slip', { slip_id: slipId() }, null, 'json')
            .done(function (res) {
                $btn.prop('disabled', false);
                if (!res || !res.ok) { alert((res && res.msg) || 'Chưa chốt được phiếu.'); return; }
                SLIP = res.slip;
                renderAll();
                alert('Đã báo soạn xong. Admin sẽ vào xem và cập nhật đơn hàng.');
                location.reload();
            })
            .fail(function () { $btn.prop('disabled', false); alert('Lỗi mạng / máy chủ.'); });
    });

    /* ----- Đổi phiếu ----- */
    $(document).on('click', '.wpk-chip', function () {
        var id = Number($(this).data('slip-id')) || 0;
        if (!id || id === slipId()) return;
        window.location.href = BASE + 'picking_task&slip_id=' + id;
    });

    $(function () { if (SLIP) renderAll(); });

})(jQuery);
