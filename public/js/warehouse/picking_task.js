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
 *  THỨ TỰ DÒNG PHẢI GIỮ NGUYÊN GIỮA CÁC LẦN VẼ LẠI (yêu cầu của anh Sáu):
 *  chốt một lần lúc mở phiếu (thuTuDong). Đổi số lượng hay bấm quy đổi kiện/số
 *  làm dòng rơi sang nhóm quy cách khác; nếu sắp lại theo nhóm thì dòng nhảy đi
 *  mất chỗ ngay dưới tay đang bốc.
 *
 *  Thao tác trên 1 dòng (không còn cử chỉ gạt — đứng bốc hàng một tay thì gạt
 *  trúng/trượt thất thường, bấm dứt khoát hơn):
 *   - Bấm TÊN HÀNG        -> modal đánh số chung kiện
 *   - Bấm Ô SỐ LƯỢNG      -> quy đổi kiện <-> số
 *   - Bấm ĐÚP Ô SỐ LƯỢNG  -> modal đổi số bốc thực tế
 * ============================================================================
 */
(function ($) {
    'use strict';

    var BASE  = '?mod=warehouse&controllers=warehouse&action=';
    var SLIP  = window.WPK_SLIP || null;
    var SLIPS = window.WPK_SLIPS || [];
    var ME     = window.WPK_ME || { id: 0 };
    var CHIXEM = false;   // đang xem lại 1 phiếu trong lịch sử -> khoá mọi thao tác

    var rawMode   = {};   // {itemId: true} — dòng đang hiện SỐ thay vì quy đổi kiện
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
        if (CHIXEM) return false;   // xem lại phiếu cũ: chỉ đọc, không sửa/xoá gì được
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

        /* Xem lại phiếu cũ: GIỮ NGUYÊN bố cục đang soạn — vẫn quy cách, tồn kho, cảnh báo
           thiếu và dấu tích xanh. Chỉ khác: dấu tích bị khoá, không có nút xoá, không có ô
           nhập, và KHÔNG làm mờ dòng (phiếu này soạn xong rồi, mờ đi lại khó đọc). */
        if (CHIXEM) {
            return '' +
                '<div class="wpk-row is-readonly" data-id="' + it.id + '">' +
                  '<div class="wpk-row-main">' +
                    '<div class="wpk-name-wrap">' + grp +
                      '<div class="wpk-name">' +
                        '<div class="wpk-name-text">' + warn + esc(String(it.product_name || '').toUpperCase()) + '</div>' +
                        (sub.length ? '<div class="wpk-sub">' + sub.join(' · ') + '</div>' : '') +
                      '</div>' +
                    '</div>' +
                    '<div class="wpk-qty-wrap">' +
                      '<label class="wpk-chk is-lock"><input type="checkbox" checked disabled><span></span></label>' +
                      '<span class="wpk-qty">' + esc(qtyText) + '</span>' +
                    '</div>' +
                  '</div>' +
                '</div>';
        }

        return '' +
            '<div class="wpk-row' + (locked ? ' is-picked' : '') + '" data-id="' + it.id + '">' +
              '<div class="wpk-row-main">' +
                '<div class="wpk-name-wrap">' +
                  grp +
                  '<div class="wpk-name">' +
                    '<div class="wpk-name-text">' + warn + esc(String(it.product_name || '').toUpperCase()) + '</div>' +
                    (sub.length ? '<div class="wpk-sub">' + sub.join(' · ') + '</div>' : '') +
                    remind +
                  '</div>' +
                '</div>' +
                '<div class="wpk-qty-wrap">' +
                  '<label class="wpk-chk"><input type="checkbox" class="wpk-picked"' + (locked ? ' checked' : '') + '><span></span></label>' +
                  '<span class="wpk-qty' + (quyDoiDuoc(it) ? '' : ' is-plain') + '">' + esc(qtyText) + '</span>' +
                '</div>' +
                '<button type="button" class="wpk-del" title="Gỡ khỏi phiếu">&times;</button>' +
              '</div>' +
            '</div>';
    }

    function renderList() {
        var $list = $('#wpk-list');
        if (!$list.length || !SLIP) return;

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

        $('#wpk-slip').toggleClass('is-locked', !editable()).toggleClass('is-readonly', CHIXEM);
        // Xem lại phiếu cũ: giấu ô thêm hàng + khối đã gỡ, nút dưới đổi thành "Chi tiết".
        $('#wpk-toolbar').toggle(!CHIXEM);
        $('#wpk-note').prop('readonly', CHIXEM);
        if (CHIXEM) $rm.attr('hidden', true);
        $('#wpk-finish')
            .prop('disabled', CHIXEM ? false : !editable())
            .html(CHIXEM
                ? '<i class="fa-solid fa-circle-info"></i> Chi tiết'
                : '<i class="fa-solid fa-circle-check"></i> Soạn xong');
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

    /** "8B + 31T + 6 SP lẻ = 800 kg" — số kiện đi kèm khối lượng để biết luôn xe nào chở nổi.
        Khối lượng LÀM TRÒN 0 số lẻ (359,5 -> 360): đứng bốc hàng không ai cần tới lạng. */
    function chuoiKien(s) {
        if (!s) return '—';
        var t = s.text || '';
        var kg = Math.round(Number(s.weight) || 0);
        if (!t && !kg) return '—';
        return (t || '—') + (kg ? ' = ' + kg.toLocaleString('vi-VN') + ' kg' : '');
    }

    function renderSummary() {
        if (!SLIP) return;
        $('#wpk-sum').text(chuoiKien(SLIP.summary));
        var shorts = dongSong().filter(function (i) { return i.is_short; });
        var $w = $('#wpk-warn');
        if (!shorts.length || CHIXEM) { $w.attr('hidden', true).empty(); return; }
        // Mỗi mặt hàng MỘT DÒNG — gộp hết vào một dòng dài thì đứng bốc hàng không đọc nổi.
        $w.removeAttr('hidden').html(
            '<div class="wpk-warn-head"><i class="fa-solid fa-triangle-exclamation"></i> Thiếu tồn</div>' +
            shorts.map(function (i) {
                return '<div class="wpk-warn-line"><b>' + esc(i.product_name) + '</b>' +
                    '<span>tồn ' + num(i.stock) + ' ' + esc(i.unit || '') + '</span></div>';
            }).join('')
        );
    }

    /* ---- Chip chọn phiếu: nhiều phiếu cùng lúc, bộ đếm cập nhật ngay khi tích ---- */
    function renderChips() {
        var $box = $('#wpk-chips');
        if (!$box.length) return;
        $box.html(SLIPS.map(function (s) {
            var mo   = SLIP && Number(s.id) === slipId();
            var done = String(s.status) === 'done';
            var tot = Number(s.total_items) || 0, pk = Number(s.picked_items) || 0;
            // MÀU RIÊNG TỪNG KHÁCH (customers.secondary_color) — chip PLDN vẫn vàng dù đang
            // mở PLHCM màu đỏ. Đổ vào biến --c của riêng chip đó, CSS đọc var(--c) nên không
            // chip nào ăn theo màu của phiếu đang mở.
            var mau = s.accent || '#16a34a';
            // Phiếu đang mở -> is-open (nền màu khách); phiếu CÒN PHẢI SOẠN -> is-active
            // (chữ + viền màu khách) để nhân viên thấy ngay còn mấy phiếu và nhảy qua lại.
            var cls = 'wpk-chip' + (mo ? ' is-open' : '') + (done ? ' is-done' : ' is-active');
            return '<button type="button" class="' + cls + '" data-slip-id="' + s.id + '"' +
                ' style="--c: ' + esc(mau) + '">' +
                '<span class="wpk-chip-name">' + esc(s.label) + '</span>' +
                '<span class="wpk-chip-count">' + pk + '/' + tot + '</span>' +
                (done ? '<i class="fa-solid fa-circle-check"></i>' : '') +
                (ME.is_admin ? '<span class="wpk-chip-del" title="Gỡ phiếu soạn này">&times;</span>' : '') +
                '</button>';
        }).join(''));
    }
    /* ---- Nhắc còn phiếu chưa bấm "Soạn xong" ----
       Bấm "Soạn xong" mới là lúc chuông báo về cho admin. Rời trang khi còn phiếu dở thì
       admin không hề biết kho đã bốc tới đâu. Chỉ chặn ở beforeunload — dải nhắc trong trang
       đã bỏ theo yêu cầu, hàng chip vốn đã cho thấy còn mấy phiếu chưa xong. */
    function soPhieuChuaXong() {
        return SLIPS.filter(function (s) { return String(s.status) !== 'done'; }).length;
    }
    window.addEventListener('beforeunload', function (e) {
        if (!soPhieuChuaXong()) return;      // xong hết thì đi thoải mái
        e.preventDefault();
        e.returnValue = '';                  // trình duyệt tự hiện hộp xác nhận (không sửa được chữ)
        return '';
    });

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

    /** Dòng có gì để quy đổi không? Số lượng chưa đủ 1 kiện (vd 10 gói mà quy cách
        15 gói/thùng) thì ô vốn đã hiện số lẻ rồi — bấm vào chẳng đổi được gì. */
    function quyDoiDuoc(it) {
        return !!it && (Number(it.ops_qty) || 0) > 0 && (Number(it.kien_whole) || 0) > 0;
    }

    /* =====================================================================
     *  BẤM Ô SỐ LƯỢNG
     *   1 lần  -> quy đổi kiện <-> số (không đụng thứ tự dòng)
     *   2 lần  -> modal đổi số bốc thực tế
     *  Bấm đúp cũng sinh ra 2 cú click, nên phải HOÃN việc quy đổi ~230ms rồi
     *  huỷ nếu bấm đúp tới — không thì màn hình nháy 2 lần trước khi modal mở.
     * ===================================================================== */
    var henQuyDoi = null;

    $(document).on('click', '.wpk-qty', function () {
        var $o = $(this);
        var id = Number($o.closest('.wpk-row').data('id')) || 0;
        if (!id) return;
        var it = timDong(id);
        if (!quyDoiDuoc(it)) return;              // nhỏ hơn quy cách -> giữ nguyên
        clearTimeout(henQuyDoi);
        henQuyDoi = setTimeout(function () {
            rawMode[id] = !rawMode[id];
            $o.text(rawMode[id] ? num(it.qty_actual) : (it.kien_text || ''));
        }, 230);
    });

    $(document).on('dblclick', '.wpk-qty', function () {
        clearTimeout(henQuyDoi);
        if (!editable()) return;
        var id = Number($(this).closest('.wpk-row').data('id')) || 0;
        var it = id ? timDong(id) : null;
        if (!it) return;
        if (it.picked) { alert('Dòng đã tích bốc đủ — bỏ tích trước rồi mới sửa được số.'); return; }
        dongDangSua = id;
        $('#wpk-qty-sub').html(esc(it.product_name) +
            (it.pack_label ? ' · ' + esc(it.pack_label) : '') +
            ' · đơn đặt <b>' + esc(it.order_kien || num(it.qty_order)) + '</b>');
        $('#wpk-qty-unit').text(it.unit || '');
        $('#wpk-qty-val').val(Number(it.qty_actual) || 0);
        veTruocQuyDoi();
        $('#wpk-qty-modal').removeAttr('hidden');
        setTimeout(function () { $('#wpk-qty-val').trigger('focus').trigger('select'); }, 30);
    });

    /* =====================================================================
     *  BẤM TÊN HÀNG -> modal đánh số chung kiện
     * ===================================================================== */
    $(document).on('click', '.wpk-name-wrap', function () {
        if (!editable()) return;
        var id = Number($(this).closest('.wpk-row').data('id')) || 0;
        var it = id ? timDong(id) : null;
        if (!it) return;
        if (it.picked) { alert('Dòng đã tích bốc đủ — bỏ tích trước rồi mới đánh chung kiện được.'); return; }
        dongDangSua = id;
        $('#wpk-group-sub').html(esc(it.product_name) +
            (it.inv_kien ? ' · TK ' + esc(it.inv_kien) : ''));
        $('#wpk-group-val').val(it.kien_group != null ? it.kien_group : '');
        $('#wpk-group-modal').removeAttr('hidden');
        setTimeout(function () { $('#wpk-group-val').trigger('focus').trigger('select'); }, 30);
    });

    /* ---- Hai modal nhập: lưu / bỏ / đóng ---- */
    var dongDangSua = 0;

    function dongModal(id) { $('#' + id).attr('hidden', true); }
    $(document).on('click', '[data-wpk-close]', function () { dongModal($(this).data('wpk-close')); });
    $(document).on('click', '.wpk-modal-mask', function (e) {
        if (e.target === this) $(this).attr('hidden', true);
    });

    /** Nháy sáng số chung kiện vừa đặt. */
    function nhaySoKien(id) {
        var $o = $('.wpk-row[data-id="' + id + '"] .wpk-grp-badge');
        if (!$o.length) return;
        $o.removeClass('is-flash');
        void $o[0].offsetWidth;
        $o.addClass('is-flash');
        setTimeout(function () { $o.removeClass('is-flash'); }, 1100);
    }

    /* Giống hệt modal đổi số: CHỈ "Xác nhận" mới ghi, bấm × là không đổi gì.
       Bỏ trống ô rồi Xác nhận = bỏ chung kiện (khỏi cần nút riêng). */
    $(document).on('click', '#wpk-group-save', function () {
        var raw = String($('#wpk-group-val').val() || '').trim();
        var id  = dongDangSua;
        if (!id) return;
        if (raw !== '' && (isNaN(Number(raw)) || Number(raw) <= 0)) {
            alert('Nhập số kiện chung lớn hơn 0, hoặc để trống để bỏ chung kiện.');
            return;
        }
        dongModal('wpk-group-modal');
        post('set_item_group', { item_id: id, group: raw === '' ? '' : Number(raw) },
            function () { nhaySoKien(id); });
    });

    /** Xem trước: gõ 90 mà quy cách 30 gói/bao thì báo luôn "= 3B".
        Dưới 1 kiện VẪN LƯU BÌNH THƯỜNG — chỉ nói cho biết sẽ hiện ra số lẻ. */
    function veTruocQuyDoi() {
        var it = timDong(dongDangSua);
        var v = Number($('#wpk-qty-val').val());
        var ops = it ? (Number(it.ops_qty) || 0) : 0;
        if (!it || isNaN(v) || v < 0 || ops <= 0) { $('#wpk-qty-preview').text(''); return; }
        var chan = Math.floor(v / ops), du = v - chan * ops;
        $('#wpk-qty-preview').text(chan > 0
            ? ('= ' + chan + (it.letter || '') + (du > 0 ? ' + ' + num(du) + ' ' + (it.unit || '') : ''))
            : ('= ' + num(v) + ' ' + (it.unit || '') + ' (chưa đủ 1 ' + (it.short_name || 'kiện') + ')'));
    }
    $(document).on('input', '#wpk-qty-val', veTruocQuyDoi);

    /** Nháy sáng ô số lượng vừa đổi — để mắt bắt được ngay là số nào vừa thay. */
    function nhayOSoLuong(id) {
        var $o = $('.wpk-row[data-id="' + id + '"] .wpk-qty');
        if (!$o.length) return;
        $o.removeClass('is-flash');
        void $o[0].offsetWidth;          // ép tính lại layout, không thì chạy 2 lần liên tiếp mất hiệu ứng
        $o.addClass('is-flash');
        setTimeout(function () { $o.removeClass('is-flash'); }, 1100);
    }

    $(document).on('click', '#wpk-qty-save', function () {
        var v = Number($('#wpk-qty-val').val());
        var id = dongDangSua;
        if (!id) return;
        if (isNaN(v) || v < 0) { alert('Số lượng không hợp lệ.'); return; }
        dongModal('wpk-qty-modal');
        /* Đưa dòng về hiển thị QUY ĐỔI KIỆN. Nếu trước đó người dùng đã bấm để xem số thô,
           cờ rawMode của dòng vẫn còn bật, vẽ lại sẽ ra "95" thay vì "3B 5" — trong khi số
           bốc phải đọc theo kiện mới đúng nghiệp vụ. Muốn xem lại số thô thì bấm 1 cái. */
        delete rawMode[id];
        // Số dưới quy cách vẫn ghi bình thường; sau khi vẽ lại thì nháy sáng ô đó.
        post('set_item_qty', { item_id: id, qty: v }, function () { nhayOSoLuong(id); });
    });
    $(document).on('keydown', '#wpk-group-val, #wpk-qty-val', function (e) {
        if (e.key !== 'Enter') return;
        e.preventDefault();
        $(this.id === 'wpk-group-val' ? '#wpk-group-save' : '#wpk-qty-save').trigger('click');
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
        if (CHIXEM) { moModalChiTiet(); return; }   // đang xem lịch sử -> nút là "Chi tiết"
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

    /** Đổ phần đầu phiếu (khách / người nhận / địa chỉ / ghi chú / màu) từ SLIP.
        Dùng chung cho cả đổi phiếu đang soạn lẫn xem lại phiếu trong lịch sử. */
    function dungThongTinPhieu() {
        if (!SLIP) return;
        $('#wpk-cust').text((SLIP.customer_short && String(SLIP.customer_short).trim()) || SLIP.customer_name || '');
        $('#wpk-receiver').text([SLIP.receiver, SLIP.phone].filter(Boolean).join(' - '));
        $('#wpk-address').text(SLIP.address || '');
        $('#wpk-note').val(SLIP.note || '');
        $('#wpk-slip').attr('data-slip-id', SLIP.id);
        var mau = SLIP.accent || '#16a34a';
        var page = document.querySelector('.wpk-page');
        if (page) page.style.setProperty('--wpk-accent', mau);
        // Cả :root nữa — modal nằm ngoài .wpk-page, không đặt ở đây thì chúng giữ màu
        // mặc định thay vì màu của khách đang mở.
        document.documentElement.style.setProperty('--wpk-accent', mau);
    }

    /* ----- Đổi phiếu: nạp bằng AJAX, không tải lại cả trang ----- */
    function moPhieu(id) {
        if (!id) return;
        $.getJSON(BASE + 'slip_data', { slip_id: id }, function (res) {
            if (!res || !res.ok) { alert((res && res.msg) || 'Không mở được phiếu.'); return; }
            CHIXEM = false;                     // rời chế độ xem lịch sử
            lichSuDangXem = null;
            SLIP = res.slip;
            if (res.slips) SLIPS = res.slips;
            rawMode = {};
            $('#wpk-kien').removeAttr('data-groups');
            $('#wpk-empty').attr('hidden', true);
            $('#wpk-slip').removeAttr('hidden');
            dungThongTinPhieu();
            chotThuTu();
            renderAll();
            window.scrollTo({ top: 0, behavior: 'smooth' });
        });
    }
    $(document).on('click', '.wpk-chip', function () {
        var id = Number($(this).data('slip-id')) || 0;
        // Đang xem lịch sử thì bấm lại chính chip đó cũng phải quay về chế độ soạn.
        if (!id || (id === slipId() && !CHIXEM)) return;
        moPhieu(id);
    });

    /* =====================================================================
     *  LỊCH SỬ SOẠN HÀNG — lọc ngày + phân trang (khối dùng chung history_filter)
     *  Bấm 1 thẻ để ĐỔ NGƯỢC phiếu cũ lên chính khối .wpk-slip ở trên, ở chế độ
     *  CHỈ XEM (CHIXEM) — nhìn y như lúc đang soạn, chỉ khác là không sửa được gì.
     *  Nút dưới cùng lúc đó đổi thành "Chi tiết" để mở modal số liệu.
     * ===================================================================== */
    var histPage = 1;

    function ngayGio(s) {
        if (!s) return '';
        var p = String(s).split(/[- :]/);
        if (p.length < 5) return String(s);
        return p[3] + ':' + p[4] + ' ' + p[2] + '/' + p[1] + '/' + p[0];
    }

    /** Avatar 1 người soạn; chưa có ảnh thì lấy chữ cái đầu. */
    function avatarHtml(u, cls) {
        var t = 'title="' + esc(u.name || u.username || '') + '"';
        if (u.avatar) {
            return '<span class="wpk-ava ' + (cls || '') + ' has-img" ' + t + '>' +
                '<img src="public/images/avatar/' + esc(u.avatar) + '" alt=""></span>';
        }
        return '<span class="wpk-ava ' + (cls || '') + '" ' + t + '>' + esc(u.initial || '?') + '</span>';
    }
    function avatarsHtml(list) {
        if (!list || !list.length) return '<span class="wpk-ava-none">—</span>';
        return '<span class="wpk-ava-group">' + list.map(function (u) { return avatarHtml(u); }).join('') + '</span>';
    }

    function loadHistory() {
        var $body = $('#wpk-hist-body');
        if (!$body.length) return;
        $.getJSON(BASE + 'history', {
            from: $('#hf-date-from').val() || '',
            to:   $('#hf-date-to').val() || '',
            page: histPage,
            per:  $('#hf-page-size').val() || 4
        }, function (res) {
            if (!res || !res.ok) { $body.html('<p class="wpk-hist-empty">Không tải được lịch sử.</p>'); return; }
            var d = res.data;
            histPage = d.page;
            $('#hf-count').text(d.total ? ('Tổng ' + d.total + ' phiếu') : '');
            if (!d.rows.length) {
                $body.html('<p class="wpk-hist-empty">Chưa có phiếu nào soạn xong.</p>');
            } else {
                $body.html(d.rows.map(function (r) {
                    var mau = r.accent || '#16a34a';
                    return '<div class="wpk-hist-card" data-slip-id="' + r.id + '" style="--c: ' + esc(mau) + '">' +
                        '<div class="wpk-hist-top">' +
                            '<b class="wpk-hist-cust">' + esc(r.label) + '</b>' +
                            (Number(r.synced)
                                ? '<span class="wpk-hist-ok">Đã cập nhật đơn</span>'
                                : '<span class="wpk-hist-wait">Chờ cập nhật</span>') +
                        '</div>' +
                        '<div class="wpk-hist-mid">' +
                            '<span>' + esc(ngayGio(r.done_at)) + '</span>' +
                            '<span>' + r.lines + ' SP</span>' +
                            '<span><b>' + esc(chuoiKien({ text: r.kien, weight: r.weight })) + '</b></span>' +
                        '</div>' +
                        '<div class="wpk-hist-bot">' + avatarsHtml(r.pickers) +
                            (ME.is_admin ? '<button type="button" class="wpk-hist-del" title="Xoá phiếu soạn"><i class="fa-solid fa-trash-can"></i></button>' : '') +
                        '</div>' +
                        '</div>';
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
        $('#hf-page-size').val('4');
        histPage = 1; loadHistory();
    });

    /* ---- Bấm 1 thẻ lịch sử: dựng lại phiếu cũ ở chế độ chỉ xem ---- */
    var lichSuDangXem = null;

    /** Nắn dữ liệu lịch sử về đúng hình dạng của 1 phiếu để dùng lại nguyên bộ hàm vẽ. */
    function slipTuLichSu(d) {
        var s = d.snapshot || {};
        return {
            id: d.id,
            customer_short: d.label, customer_name: d.label,
            receiver: d.receiver, phone: d.phone, address: d.address, note: d.note,
            accent: d.accent, status: 'done', synced: d.synced ? 1 : 0,
            kien_map: s.kien_map || {},
            summary: { text: s.kien || '', weight: s.weight || 0, groups: [], invalid: [] },
            // Bản chụp mang theo cả quy cách + thứ tự nên chotThuTu() dựng lại ĐÚNG thứ tự
            // lúc đang soạn (chẵn quy cách -> quy cách + lẻ -> lẻ -> NVL), không phải A→B.
            items: (s.items || []).map(function (it, i) {
                return {
                    id: 'h' + i, product_name: it.name, unit: it.unit,
                    qty_order: it.order, qty_actual: it.pick,
                    kien_text: it.kien, order_kien: '', kien_group: it.group,
                    picked: true, removed: false, added_by_staff: false,
                    item_type: it.type || 'product',
                    ops_qty: Number(it.ops) || 0,
                    kien_whole: Number(it.whole) || 0,
                    kien_rem: Number(it.rem) || 0,
                    stock: Number(it.stock) || 0,
                    is_short: !!it.short,
                    inv_kien: it.tk || '',
                    pack_label: it.pack || '',
                    reminder_note: '',
                    sort_order: (it.sort === undefined ? null : it.sort)
                };
            })
        };
    }

    $(document).on('click', '.wpk-hist-card', function () {
        var id = Number($(this).data('slip-id')) || 0;
        if (!id) return;
        $.getJSON(BASE + 'history_detail', { slip_id: id }, function (res) {
            if (!res || !res.ok) { alert((res && res.msg) || 'Không xem được phiếu.'); return; }
            lichSuDangXem = res.data;
            CHIXEM = true;
            SLIP = slipTuLichSu(res.data);
            rawMode = {};
            $('#wpk-kien').removeAttr('data-groups');
            dungThongTinPhieu();
            $('#wpk-empty').attr('hidden', true);
            $('#wpk-slip').removeAttr('hidden');
            chotThuTu();
            renderList(); renderKien(); renderSummary(); renderChips();
            $('html, body').animate({ scrollTop: 0 }, 200);
        });
    });

    /* ---- Nút "Chi tiết" (chỉ có ở chế độ xem lịch sử) -> modal số liệu ---- */
    function moModalChiTiet() {
        var d = lichSuDangXem;
        if (!d) return;
        var s = d.snapshot || {};
        $('#wpk-hist-title').text('Phiếu soạn — ' + (d.label || ''));
        $('#wpk-hist-meta').html(
            '<div><span>Soạn xong:</span><b>' + esc(ngayGio(d.done_at)) + '</b></div>' +
            '<div><span>Người soạn:</span><b>' + avatarsHtml(d.pickers) + '</b></div>' +
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
            '<div><b>Số kiện:</b> ' + esc(chuoiKien({ text: s.kien, weight: s.weight })) + '</div>' +
            (kienKhai ? '<div>' + kienKhai + '</div>' : '') +
            '<div>' + (d.synced
                ? '<span class="wpk-hist-ok">Đã cập nhật vào đơn hàng lúc ' + esc(ngayGio(d.synced_at)) + '</span>'
                : '<span class="wpk-hist-wait">Chưa được admin cập nhật vào đơn hàng</span>') + '</div>'
        );
        $('#wpk-hist-modal').removeAttr('hidden');
    }
    $(document).on('click', '#wpk-hist-close', function () { $('#wpk-hist-modal').attr('hidden', true); });
    $(document).on('click', '#wpk-hist-modal', function (e) {
        if (e.target === this) $(this).attr('hidden', true);
    });

    /* =====================================================================
     *  REAL-TIME — người này tích, người kia thấy ngay
     * ---------------------------------------------------------------------
     *  Không có websocket nên đi bằng polling (giống chat widget). Chỉ chạy khi
     *  tab đang hiện và phiếu còn sửa được; đang gõ trong ô nhập thì hoãn một
     *  nhịp để không giật mất con trỏ.
     * ===================================================================== */
    var POLL_MS = 4000;
    var pollTimer = null;

    function dangGo() {
        var el = document.activeElement;
        return !!(el && el.tagName === 'INPUT' && el.closest && el.closest('#wpk-slip'));
    }

    /** Bay avatar người vừa tích lên từ đúng ô checkbox rồi tan dần. */
    function bayAvatar(itemId, nguoi) {
        var el = document.querySelector('.wpk-row[data-id="' + itemId + '"] .wpk-chk');
        if (!el || !nguoi) return;
        var r = el.getBoundingClientRect();
        var $fly = $('<span class="wpk-ava-fly">' + avatarHtml(nguoi) + '</span>').appendTo('body');
        $fly.css({ left: (r.left + r.width / 2 - 16) + 'px', top: (r.top + r.height / 2 - 16) + 'px' });
        // Ép trình duyệt tính layout trước khi gắn class chạy, nếu không nó gộp 2 bước và mất hiệu ứng.
        void $fly[0].offsetWidth;
        $fly.addClass('is-fly');
        setTimeout(function () { $fly.remove(); }, 1300);
    }

    function nhipPoll() {
        if (!SLIP || CHIXEM || document.hidden || dangGo()) return;
        if (!editable()) return;
        $.getJSON(BASE + 'slip_data', { slip_id: slipId() }, function (res) {
            if (!res || !res.ok || !res.slip) return;
            if (Number(res.slip.id) !== slipId() || CHIXEM) return;   // đã đổi phiếu giữa chừng

            // Dòng nào vừa chuyển sang ĐÃ TÍCH do NGƯỜI KHÁC -> bay avatar của họ.
            var truoc = {};
            ((SLIP.items) || []).forEach(function (i) { truoc[i.id] = !!i.picked; });
            var danhBa = {};
            ((res.slip.pickers) || []).forEach(function (u) { danhBa[u.id] = u; });
            var moiTich = [];
            ((res.slip.items) || []).forEach(function (i) {
                if (i.picked && truoc[i.id] === false && Number(i.picked_by) !== Number(ME.id)) {
                    moiTich.push({ id: i.id, nguoi: danhBa[i.picked_by] });
                }
            });

            SLIP = res.slip;
            if (res.slips) SLIPS = res.slips;
            renderAll();
            moiTich.forEach(function (x) { bayAvatar(x.id, x.nguoi); });
        });
    }
    function batPoll() {
        clearInterval(pollTimer);
        pollTimer = setInterval(nhipPoll, POLL_MS);
    }

    /* =====================================================================
     *  ADMIN: XOÁ PHIẾU SOẠN
     *  Dùng khi admin cần sửa lại đơn rồi gửi phiếu mới — bỏ hẳn phiếu cũ cho
     *  nhân viên khỏi soạn nhầm bản lỗi thời.
     * ===================================================================== */
    function xoaPhieu(id, nhan, sauKhiXong) {
        if (!ME.is_admin) return;
        if (!confirm('Xoá hẳn phiếu soạn "' + nhan + '"? Không khôi phục lại được.')) return;
        $.post(BASE + 'delete_slip', { slip_id: id }, function (res) {
            if (!res || !res.ok) { alert((res && res.msg) || 'Không xoá được.'); return; }
            SLIPS = SLIPS.filter(function (s) { return Number(s.id) !== Number(id); });
            if (Number(id) === slipId()) {
                // Đang mở đúng phiếu vừa xoá -> nhảy sang phiếu còn lại, hết thì dọn trống.
                if (SLIPS.length) { moPhieu(Number(SLIPS[0].id)); }
                else {
                    SLIP = null; CHIXEM = false;
                    $('#wpk-slip').attr('hidden', true);
                    $('#wpk-empty').removeAttr('hidden');
                    renderChips();
                }
            } else { renderChips(); }
            if (typeof sauKhiXong === 'function') sauKhiXong();
        }, 'json').fail(function () { alert('Lỗi mạng / máy chủ.'); });
    }

    $(document).on('click', '.wpk-chip-del', function (e) {
        e.stopPropagation();                       // đừng để lọt xuống handler mở phiếu
        var $chip = $(this).closest('.wpk-chip');
        xoaPhieu(Number($chip.data('slip-id')) || 0, $chip.find('.wpk-chip-name').text());
    });
    $(document).on('click', '.wpk-hist-del', function (e) {
        e.stopPropagation();                       // đừng để lọt xuống handler xem lại phiếu
        var $card = $(this).closest('.wpk-hist-card');
        var id = Number($card.data('slip-id')) || 0;
        xoaPhieu(id, $card.find('.wpk-hist-cust').text(), function () {
            if (lichSuDangXem && Number(lichSuDangXem.id) === id) {
                lichSuDangXem = null; CHIXEM = false;
                $('#wpk-slip').attr('hidden', true);
                if (!SLIPS.length) $('#wpk-empty').removeAttr('hidden');
            }
            loadHistory();
        });
    });

    $(function () {
        if (SLIP && SLIP.accent) document.documentElement.style.setProperty('--wpk-accent', SLIP.accent);
        renderChips();
        if (SLIP) { chotThuTu(); renderAll(); }
        loadHistory();
        batPoll();
        // Quay lại tab thì hỏi server ngay, khỏi đợi hết nhịp.
        document.addEventListener('visibilitychange', function () { if (!document.hidden) nhipPoll(); });
    });

})(jQuery);
