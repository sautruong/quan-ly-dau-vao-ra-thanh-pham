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
 *  HAI THỨ PHẢI GIỮ NGUYÊN GIỮA CÁC LẦN VẼ LẠI (yêu cầu của anh Sáu):
 *   1. THỨ TỰ DÒNG — chốt một lần lúc mở phiếu (thuTuDong). Đổi số lượng hay
 *      bấm quy đổi kiện/số làm dòng rơi sang nhóm quy cách khác, nếu sắp lại
 *      theo nhóm thì dòng nhảy đi mất chỗ ngay dưới tay đang bốc.
 *   2. CÁC Ô ĐANG MỞ — gạt mở ô chung kiện ở nhiều dòng cùng lúc, chỉ đóng khi
 *      bấm × (hoặc ✓ sau khi nhập số). Kèm giữ luôn con trỏ đang gõ.
 *
 *  Cử chỉ (pointer events nên dùng chung cho cả chạm lẫn chuột):
 *   - Gạt TÊN HÀNG sang phải  -> ô nhập số chung kiện
 *   - Gạt Ô SỐ LƯỢNG sang trái -> ô nhập số bốc thật (dừng gõ 2s tự lưu)
 * ============================================================================
 */
(function ($) {
    'use strict';

    var BASE  = '?mod=warehouse&controllers=warehouse&action=';
    var SLIP  = window.WPK_SLIP || null;
    var SLIPS = window.WPK_SLIPS || [];

    var rawMode   = {};   // {itemId: true} — dòng đang hiện SỐ thay vì quy đổi kiện
    var qtyTimer  = {};   // {itemId: timeoutId} — hẹn 2s tự lưu số bốc
    var kienTimer = {};   // {group: timeoutId} — hẹn 2s mới báo sai định dạng
    var thuTuDong = {};   // {itemId: thứ tự} — CHỐT lúc mở phiếu, không sắp lại nữa

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
    function dongSong() { return ((SLIP && SLIP.items) || []).filter(function (i) { return !i.removed; }); }
    function timDong(id) {
        var r = ((SLIP && SLIP.items) || []).filter(function (x) { return Number(x.id) === Number(id); });
        return r[0] || null;
    }

    /* =====================================================================
     *  THỨ TỰ DÒNG — chốt một lần, sau đó giữ nguyên
     * ---------------------------------------------------------------------
     *  Thuật toán sắp giống hệt phiếu soạn A4 (picking_slip.js) để nhân viên
     *  cầm phiếu giấy và nhìn màn hình thấy cùng một thứ tự:
     *  nhóm 1 chẵn quy cách · 2 quy cách + lẻ · 3 đã quy đổi ra số · 4 lẻ ·
     *  5 nguyên vật liệu. Nhưng CHỈ chạy lúc mở phiếu — xem chú thích đầu tệp.
     * ===================================================================== */
    function rowGroup(it) {
        if (it.item_type === 'material') return 5;
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
    /** Chốt thứ tự cho phiếu vừa mở. Dòng thêm sau nhận số lớn dần -> luôn xuống cuối. */
    function chotThuTu() {
        thuTuDong = {};
        dongSong().slice().sort(function (a, b) {
            var ga = rowGroup(a), gb = rowGroup(b);
            if (ga !== gb) return ga - gb;
            return cmpRow(a, b);
        }).forEach(function (it, i) { thuTuDong[it.id] = i; });
    }
    function theoThuTu(items) {
        var max = Object.keys(thuTuDong).length;
        return items.slice().sort(function (a, b) {
            var xa = (thuTuDong[a.id] != null) ? thuTuDong[a.id] : (max + Number(a.id));
            var xb = (thuTuDong[b.id] != null) ? thuTuDong[b.id] : (max + Number(b.id));
            return xa - xb;
        });
    }

    /* =====================================================================
     *  GIỮ TRẠNG THÁI Ô ĐANG MỞ QUA MỖI LẦN VẼ LẠI
     * ===================================================================== */
    function chupTrangThai() {
        var t = { mo: {}, giaTri: {}, focus: null };
        $('#wpk-list .wpk-slot').each(function () {
            if (this.hasAttribute('hidden')) return;
            var id = Number($(this).closest('.wpk-row').data('id')) || 0;
            var loai = $(this).hasClass('wpk-slot-group') ? 'group' : 'qty';
            t.mo[id + '|' + loai] = true;
            t.giaTri[id + '|' + loai] = $(this).find('input').val();
        });
        var el = document.activeElement;
        if (el && el.closest && el.closest('#wpk-list .wpk-slot')) {
            var $s = $(el).closest('.wpk-slot');
            t.focus = {
                id: Number($s.closest('.wpk-row').data('id')) || 0,
                loai: $s.hasClass('wpk-slot-group') ? 'group' : 'qty',
                caret: (typeof el.selectionStart === 'number') ? el.selectionStart : null
            };
        }
        return t;
    }
    function datLaiTrangThai(t) {
        if (!t) return;
        Object.keys(t.mo).forEach(function (k) {
            var p = k.split('|');
            var $slot = $('#wpk-list .wpk-row[data-id="' + p[0] + '"] .wpk-slot-' + p[1]);
            if (!$slot.length) return;
            $slot.removeAttr('hidden');
            var v = t.giaTri[k];
            if (v !== undefined && v !== null) $slot.find('input').val(v);
            if (p[1] === 'group') capNhatNutSlot($slot);
        });
        if (!t.focus) return;
        var $inp = $('#wpk-list .wpk-row[data-id="' + t.focus.id + '"] .wpk-slot-' + t.focus.loai + ' input');
        if (!$inp.length) return;
        $inp.trigger('focus');
        if (t.focus.caret != null && $inp[0].setSelectionRange) {
            try { $inp[0].setSelectionRange(t.focus.caret, t.focus.caret); } catch (e) {}
        }
    }

    /* =====================================================================
     *  VẼ
     * ===================================================================== */

    function rowHtml(it) {
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
        // Số chung kiện nằm SÁT TRÁI, cùng dòng với tên hàng (không xuống dòng riêng).
        var grp = (it.kien_group !== null && it.kien_group !== undefined)
            ? '<span class="wpk-grp-badge" title="Chung kiện ' + it.kien_group + '">' + it.kien_group + '</span>' : '';
        var remind = it.reminder_note
            ? '<div class="wpk-remind"><i class="fa-solid fa-thumbtack"></i> ' + esc(it.reminder_note) + '</div>' : '';

        return '' +
            '<div class="wpk-row' + (locked ? ' is-picked' : '') + '" data-id="' + it.id + '">' +
              '<div class="wpk-row-main">' +
                '<div class="wpk-name-wrap" data-swipe="right">' +
                  grp +
                  '<div class="wpk-name">' +
                    '<div class="wpk-name-text">' + warn + esc(String(it.product_name || '').toUpperCase()) + '</div>' +
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
                '<button type="button" class="wpk-slot-btn" data-mode="close" title="Đóng"><i class="fa-solid fa-xmark"></i></button>' +
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
        var giu = chupTrangThai();

        var live = dongSong();
        var gone = ((SLIP.items) || []).filter(function (i) { return i.removed; });

        var html = '';
        theoThuTu(live).forEach(function (it) { html += rowHtml(it); });
        $list.html(html || '<p class="wpk-empty">Phiếu này không còn dòng hàng nào.</p>');

        $('#wpk-total-label').text('TỔNG ' + live.length + ' SP');

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
        datLaiTrangThai(giu);
    }

    function renderKien() {
        var $box = $('#wpk-kien');
        if (!$box.length || !SLIP) return;
        var groups = (SLIP.summary && SLIP.summary.groups) || [];
        if (!groups.length) { $box.empty().attr('hidden', true); return; }

        // Giữ đúng ô đang gõ: chỉ dựng lại khi tập nhóm thay đổi.
        var kyHieu = groups.slice().sort(function (a, b) { return a - b; }).join(',');
        if ($box.attr('data-groups') === kyHieu) return;
        $box.attr('data-groups', kyHieu);

        var map = SLIP.kien_map || {};
        var html = '<div class="wpk-kien-head"><i class="fa-solid fa-box"></i> Đóng chung kiện</div>';
        groups.slice().sort(function (a, b) { return a - b; }).forEach(function (g) {
            html += '<div class="wpk-kien-row" data-group="' + g + '">' +
                '<label>Kiện (' + g + ') =</label>' +
                '<input type="text" class="wpk-kien-input" value="' + esc(map[String(g)] || '') + '" placeholder="1T hoặc 1B" autocomplete="off">' +
                '<div class="wpk-kien-err" hidden>Bắt buộc có <b>T</b> (thùng) hoặc <b>B</b> (bao) — ví dụ <b>1T</b>, <b>2B</b>.</div>' +
                '</div>';
        });
        $box.html(html).removeAttr('hidden');
    }

    function renderSummary() {
        if (!SLIP) return;
        $('#wpk-sum').text((SLIP.summary && SLIP.summary.text) || '—');
        var shorts = dongSong().filter(function (i) { return i.is_short; });
        var $w = $('#wpk-warn');
        if (!shorts.length) { $w.attr('hidden', true).empty(); return; }
        $w.removeAttr('hidden').html('<i class="fa-solid fa-triangle-exclamation"></i> Thiếu tồn: ' +
            esc(shorts.map(function (i) { return i.product_name + ' (tồn ' + num(i.stock) + ')'; }).join('; ')));
    }

    /* ---- Chip chọn phiếu: nhiều phiếu cùng lúc, bộ đếm cập nhật ngay khi tích ---- */
    function renderChips() {
        var $box = $('#wpk-chips');
        if (!$box.length) return;
        $box.html(SLIPS.map(function (s) {
            var mo   = SLIP && Number(s.id) === slipId();
            var done = String(s.status) === 'done';
            var tot = Number(s.total_items) || 0, pk = Number(s.picked_items) || 0;
            // Phiếu đang mở -> is-open (nền xanh); mọi phiếu CÒN PHẢI SOẠN -> is-active
            // (viền xanh) để nhân viên thấy ngay còn mấy phiếu và nhảy qua lại.
            var cls = 'wpk-chip' + (mo ? ' is-open' : '') + (done ? ' is-done' : ' is-active');
            return '<button type="button" class="' + cls + '" data-slip-id="' + s.id + '">' +
                '<span class="wpk-chip-name">' + esc(s.label) + '</span>' +
                '<span class="wpk-chip-count">' + pk + '/' + tot + '</span>' +
                (done ? '<i class="fa-solid fa-circle-check"></i>' : '') +
                '</button>';
        }).join(''));
    }
    /** Đồng bộ bộ đếm của phiếu đang mở từ dữ liệu vừa nhận (không đợi tải lại trang). */
    function capNhatChipHienTai() {
        if (!SLIP) return;
        var live = dongSong();
        for (var i = 0; i < SLIPS.length; i++) {
            if (Number(SLIPS[i].id) !== slipId()) continue;
            SLIPS[i].total_items  = live.length;
            SLIPS[i].picked_items = live.filter(function (x) { return x.picked; }).length;
            SLIPS[i].status       = SLIP.status;
            break;
        }
        renderChips();
    }

    function renderAll() { renderList(); renderKien(); renderSummary(); capNhatChipHienTai(); }

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
    var nuotClick = false;

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
        if (sw.dir === 'right' && dx > 0) { sw.fired = true; moSlot(sw.row, 'group'); }
        else if (sw.dir === 'left' && dx < 0) { sw.fired = true; moSlot(sw.row, 'qty'); }
    });

    // Gạt xong thì nhả tay VẪN sinh ra 1 click — nuốt cái click đó, nếu không nó sẽ
    // chạy tiếp handler quy đổi kiện/mở modal và vẽ lại danh sách, xóa mất ô vừa mở.
    $(document).on('pointerup pointercancel', function () {
        if (sw && sw.fired) {
            nuotClick = true;
            setTimeout(function () { nuotClick = false; }, 300);
        }
        sw = null;
    });

    /** Mở ô nhập. CỐ Ý KHÔNG đóng ô của dòng khác — đánh chung kiện là việc làm trên
        nhiều dòng một lúc, đóng cái trước là nhân viên mất dấu mình đang gom những gì. */
    function moSlot($row, which) {
        var $slot = $row.find(which === 'group' ? '.wpk-slot-group' : '.wpk-slot-qty');
        if (!$slot.length) return;
        $slot.removeAttr('hidden');
        if (which === 'group') capNhatNutSlot($slot);
        var $inp = $slot.find('input');
        $inp.trigger('focus');
        if ($inp[0] && $inp[0].select) $inp[0].select();
    }

    /* =====================================================================
     *  Ô "BỐC THỰC TẾ" — gõ xong dừng 2s là tự lưu.
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
        var id = Number($(this).closest('.wpk-row').data('id')) || 0;
        if (!id) return;
        clearTimeout(qtyTimer[id]);
        var it = timDong(id);
        var v = Number($(this).val()) || 0;
        if (it && Number(it.qty_actual) === v) return;   // không đổi thì khỏi gọi server
        post('set_item_qty', { item_id: id, qty: v });
    });

    /* =====================================================================
     *  Ô "CHUNG KIỆN SỐ"
     *  Chưa nhập gì -> nút ×  (đóng; nếu dòng đang có số thì × = bỏ chung kiện).
     *  Nhập số > 0  -> nút ✓  (xác nhận: lưu rồi đóng).
     * ===================================================================== */
    function capNhatNutSlot($slot) {
        var v = Number($slot.find('.wpk-group-input').val());
        var ok = !isNaN(v) && v > 0;
        $slot.find('.wpk-slot-btn')
            .attr('data-mode', ok ? 'ok' : 'close')
            .attr('title', ok ? 'Xác nhận' : 'Đóng')
            .html('<i class="fa-solid ' + (ok ? 'fa-check' : 'fa-xmark') + '"></i>');
    }
    $(document).on('input', '.wpk-group-input', function () {
        capNhatNutSlot($(this).closest('.wpk-slot'));
    });
    $(document).on('keydown', '.wpk-group-input', function (e) {
        if (e.key !== 'Enter') return;
        e.preventDefault();
        $(this).closest('.wpk-slot').find('.wpk-slot-btn').trigger('click');
    });
    $(document).on('click', '.wpk-slot-btn', function () {
        var $slot = $(this).closest('.wpk-slot');
        var $row  = $slot.closest('.wpk-row');
        var id    = Number($row.data('id')) || 0;
        var v     = Number($slot.find('.wpk-group-input').val());
        $slot.attr('hidden', true);
        if (!id) return;
        if ($(this).attr('data-mode') === 'ok') { post('set_item_group', { item_id: id, group: v }); return; }
        // × : chưa nhập gì. Dòng đang mang số chung kiện thì hiểu là BỎ chung kiện.
        var it = timDong(id);
        if (it && it.kien_group !== null && it.kien_group !== undefined) {
            post('set_item_group', { item_id: id, group: '' });
        }
    });

    /* ----- Tích "bốc đủ" = khóa dòng; tích lại = mở ra sửa ----- */
    $(document).on('change', '.wpk-picked', function () {
        var id = Number($(this).closest('.wpk-row').data('id')) || 0;
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

    /* ----- Bấm ô số lượng: quy đổi kiện <-> số (KHÔNG đụng thứ tự dòng) ----- */
    $(document).on('click', '.wpk-qty', function () {
        if (nuotClick) return;
        var id = Number($(this).closest('.wpk-row').data('id')) || 0;
        if (!id) return;
        rawMode[id] = !rawMode[id];
        var it = timDong(id);
        if (it) $(this).text(rawMode[id] ? num(it.qty_actual) : (it.kien_text || ''));
    });

    /* ----- Bấm tên hàng: modal tồn kho + quy cách ----- */
    $(document).on('click', '.wpk-name-text', function () {
        if (nuotClick) return;
        var id = Number($(this).closest('.wpk-row').data('id')) || 0;
        var it = id ? timDong(id) : null;
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

    /* =====================================================================
     *  KHAI CHUNG KIỆN "Kiện (1) = 1T"
     *  Chỉ báo sai định dạng khi nhân viên NGƯNG GÕ 2s — báo ngay từng phím
     *  thì gõ "1" đã đỏ lòm trong khi họ đang định gõ tiếp "T".
     * ===================================================================== */
    function collectKien() {
        var map = {};
        $('#wpk-kien .wpk-kien-row').each(function () {
            map[String($(this).data('group'))] = String($(this).find('.wpk-kien-input').val() || '').trim();
        });
        return map;
    }
    $(document).on('input', '.wpk-kien-input', function () {
        var $row = $(this).closest('.wpk-kien-row');
        var g = String($row.data('group'));
        var v = String($(this).val() || '').trim().toUpperCase();
        var hopLe = (v === '') || /^\d+\s*[TB]$/.test(v);
        if (hopLe) { $row.removeClass('is-bad').find('.wpk-kien-err').attr('hidden', true); }

        clearTimeout(kienTimer[g]);
        var map = collectKien();
        kienTimer[g] = setTimeout(function () {
            if (!hopLe) { $row.addClass('is-bad').find('.wpk-kien-err').removeAttr('hidden'); return; }
            // Vẽ lại khối kiện sẽ cướp con trỏ đang gõ -> chỉ cập nhật dòng tổng.
            $.post(BASE + 'save_kien', { slip_id: slipId(), map: JSON.stringify(map) }, null, 'json')
                .done(function (res) {
                    if (res && res.ok && res.slip) { SLIP = res.slip; renderSummary(); }
                });
        }, 2000);
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
            // Dòng mới chưa có trong thuTuDong -> tự rơi xuống cuối; mở sẵn ô nhập số.
            var $row = $('.wpk-row[data-id="' + res.new_item_id + '"]');
            if ($row.length) moSlot($row, 'qty');
        });
        setTimeout(function () { $('#wpk-search').trigger('focus'); }, 30);
    }

    /* =====================================================================
     *  SOẠN XONG
     * ===================================================================== */
    $(document).on('click', '#wpk-finish', function () {
        if (!SLIP) return;
        var left = dongSong().filter(function (i) { return !i.picked; });
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
                if (res.slips) SLIPS = res.slips;
                renderAll();
                histPage = 1;
                loadHistory();          // phiếu vừa chốt phải có mặt trong Lịch sử ngay
                // Còn phiếu khác đang chờ -> nhảy thẳng sang, khỏi bắt bấm lại.
                var conLai = SLIPS.filter(function (s) { return String(s.status) !== 'done'; });
                if (conLai.length) moPhieu(Number(conLai[0].id));
            })
            .fail(function () { $btn.prop('disabled', false); alert('Lỗi mạng / máy chủ.'); });
    });

    /* ----- Đổi phiếu: nạp bằng AJAX, không tải lại cả trang ----- */
    function moPhieu(id) {
        if (!id) return;
        $.getJSON(BASE + 'slip_data', { slip_id: id }, function (res) {
            if (!res || !res.ok) { alert((res && res.msg) || 'Không mở được phiếu.'); return; }
            SLIP = res.slip;
            if (res.slips) SLIPS = res.slips;
            rawMode = {};
            $('#wpk-kien').removeAttr('data-groups');
            $('#wpk-cust').text((SLIP.customer_short && String(SLIP.customer_short).trim()) || SLIP.customer_name || '');
            $('#wpk-receiver').text([SLIP.receiver, SLIP.phone].filter(Boolean).join(' - '));
            $('#wpk-address').text(SLIP.address || '');
            $('#wpk-note').val(SLIP.note || '');
            $('#wpk-slip').attr('data-slip-id', SLIP.id);
            var mau = SLIP.accent || '#16a34a';
            var page = document.querySelector('.wpk-page');
            if (page) page.style.setProperty('--wpk-accent', mau);
            chotThuTu();
            renderAll();
            window.scrollTo({ top: 0, behavior: 'smooth' });
        });
    }
    $(document).on('click', '.wpk-chip', function () {
        var id = Number($(this).data('slip-id')) || 0;
        if (!id || id === slipId()) return;
        moPhieu(id);
    });

    /* =====================================================================
     *  LỊCH SỬ SOẠN HÀNG — lọc ngày + phân trang (khối dùng chung history_filter)
     *  Bấm 1 dòng để xem lại BẢN CHỤP lúc bấm "Soạn xong", không phải trạng thái
     *  hiện giờ của phiếu.
     * ===================================================================== */
    var histPage = 1;

    function ngayGio(s) {
        if (!s) return '';
        var p = String(s).split(/[- :]/);
        if (p.length < 5) return String(s);
        return p[3] + ':' + p[4] + ' ' + p[2] + '/' + p[1] + '/' + p[0];
    }

    function loadHistory() {
        var $body = $('#wpk-hist-body');
        if (!$body.length) return;
        $.getJSON(BASE + 'history', {
            from: $('#hf-date-from').val() || '',
            to:   $('#hf-date-to').val() || '',
            page: histPage,
            per:  $('#hf-page-size').val() || 10
        }, function (res) {
            if (!res || !res.ok) { $body.html('<tr><td colspan="6">Không tải được lịch sử.</td></tr>'); return; }
            var d = res.data;
            histPage = d.page;
            $('#hf-count').text(d.total ? ('Tổng ' + d.total + ' phiếu') : '');
            if (!d.rows.length) {
                $body.html('<tr><td colspan="6" class="wpk-hist-empty">Chưa có phiếu nào soạn xong.</td></tr>');
            } else {
                $body.html(d.rows.map(function (r) {
                    return '<tr class="wpk-hist-row" data-slip-id="' + r.id + '">' +
                        '<td>' + esc(ngayGio(r.done_at)) + '</td>' +
                        '<td><b>' + esc(r.label) + '</b></td>' +
                        '<td>' + r.lines + '</td>' +
                        '<td>' + esc(r.kien || '—') + '</td>' +
                        '<td>' + esc(r.done_by_name || '') + '</td>' +
                        '<td>' + (Number(r.synced)
                            ? '<span class="wpk-hist-ok">Đã cập nhật đơn</span>'
                            : '<span class="wpk-hist-wait">Chờ admin cập nhật</span>') + '</td>' +
                        '</tr>';
                }).join(''));
            }
            renderHistPager(d.total_pages);
        });
    }

    function renderHistPager(totalPages) {
        var $p = $('#wpk-hist-pager');
        if (!$p.length) return;
        if (totalPages <= 1) { $p.empty(); return; }
        var out = '<button type="button" class="page-btn page-prev"' + (histPage === 1 ? ' disabled' : '') + '>«</button>';
        for (var i = 1; i <= totalPages; i++) {
            out += '<button type="button" class="page-btn page-num' + (i === histPage ? ' active' : '') + '" data-page="' + i + '">' + i + '</button>';
        }
        out += '<button type="button" class="page-btn page-next"' + (histPage === totalPages ? ' disabled' : '') + '>»</button>';
        $p.html(out);
    }

    $(document).on('click', '#wpk-hist-pager .page-num', function () {
        histPage = Number($(this).data('page')) || 1; loadHistory();
    });
    $(document).on('click', '#wpk-hist-pager .page-prev', function () {
        if (histPage > 1) { histPage--; loadHistory(); }
    });
    $(document).on('click', '#wpk-hist-pager .page-next', function () { histPage++; loadHistory(); });
    $(document).on('change', '#hf-date-from, #hf-date-to, #hf-page-size', function () { histPage = 1; loadHistory(); });
    $(document).on('click', '#hf-reset', function () {
        $('#hf-date-from, #hf-date-to').val('');
        $('#hf-page-size').val('10');
        histPage = 1; loadHistory();
    });

    $(document).on('click', '.wpk-hist-row', function () {
        var id = Number($(this).data('slip-id')) || 0;
        if (!id) return;
        $.getJSON(BASE + 'history_detail', { slip_id: id }, function (res) {
            if (!res || !res.ok) { alert((res && res.msg) || 'Không xem được phiếu.'); return; }
            var d = res.data, s = d.snapshot || {};
            $('#wpk-hist-title').text('Phiếu soạn — ' + (d.label || ''));
            $('#wpk-hist-meta').html(
                '<div><span>Soạn xong:</span><b>' + esc(ngayGio(d.done_at)) + (d.done_by ? ' · ' + esc(d.done_by) : '') + '</b></div>' +
                '<div><span>Người nhận:</span><b>' + esc(d.receiver || '—') + '</b></div>' +
                '<div><span>Địa chỉ:</span><b>' + esc(d.address || '—') + '</b></div>' +
                (d.note ? '<div><span>Ghi chú:</span><b>' + esc(d.note) + '</b></div>' : '')
            );
            $('#wpk-hist-items').html((s.items || []).map(function (it) {
                var lech = Number(it.pick) - Number(it.order);
                return '<tr>' +
                    '<td>' + esc(it.name) + (it.group != null ? ' <span class="wpk-grp-badge">' + it.group + '</span>' : '') + '</td>' +
                    '<td>' + num(it.order) + '</td>' +
                    '<td><b>' + num(it.pick) + '</b>' +
                        (Math.abs(lech) > 0.0001 ? ' <span class="wpk-hist-diff">' + (lech > 0 ? '+' : '') + num(lech) + '</span>' : '') + '</td>' +
                    '<td>' + esc(it.kien || '') + '</td>' +
                    '</tr>';
            }).join('') || '<tr><td colspan="4">Không có dòng nào.</td></tr>');
            var kmap = s.kien_map || {};
            var kienKhai = Object.keys(kmap).sort(function (a, b) { return a - b; })
                .map(function (g) { return 'Kiện (' + g + ') = ' + esc(kmap[g]); }).join(' · ');
            $('#wpk-hist-foot').html(
                '<div><b>Số kiện:</b> ' + esc(s.kien || '—') +
                (s.weight ? ' · ' + num(s.weight) + ' kg' : '') + '</div>' +
                (kienKhai ? '<div>' + kienKhai + '</div>' : '') +
                '<div>' + (d.synced
                    ? '<span class="wpk-hist-ok">Đã cập nhật vào đơn hàng lúc ' + esc(ngayGio(d.synced_at)) + '</span>'
                    : '<span class="wpk-hist-wait">Chưa được admin cập nhật vào đơn hàng</span>') + '</div>'
            );
            $('#wpk-hist-modal').removeAttr('hidden');
        });
    });
    $(document).on('click', '#wpk-hist-close', function () { $('#wpk-hist-modal').attr('hidden', true); });
    $(document).on('click', '#wpk-hist-modal', function (e) {
        if (e.target === this) $(this).attr('hidden', true);
    });

    $(function () {
        renderChips();
        if (SLIP) { chotThuTu(); renderAll(); }
        loadHistory();
    });

})(jQuery);
