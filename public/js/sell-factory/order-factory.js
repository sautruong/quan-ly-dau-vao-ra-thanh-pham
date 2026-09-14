/*
 * order-factory.js (V2)
 * - Lưới tồn theo nhóm + search tức thì.
 * - Giỏ hàng dạng modal (FAB góc phải + badge).
 * - Cá nhân hóa: ghi nhớ chi nhánh, ghi chú đơn, "Trước đó" / "Đặt lại".
 */
(function ($) {
    'use strict';

    var BASE = '?mod=sell_factory&controllers=sell_factory&action=';

    /* --------------------------- Helpers --------------------------- */
    function fmtMoney(n) {
        n = Number(n) || 0;
        return n.toLocaleString('vi-VN', { maximumFractionDigits: 0 });
    }
    function fmtWeight(n) {
        n = Number(n) || 0;
        return n.toLocaleString('vi-VN', { maximumFractionDigits: 2 });
    }
    function openModal(id) { $('#' + id).fadeIn(120); }
    function closeModal(id) { $('#' + id).fadeOut(120); }

    $(document).on('click', '.modal-close', function () { closeModal($(this).data('close')); });
    $(document).on('click', '.modal-mask', function (e) { if (e.target === this) $(this).fadeOut(120); });

    /* --------------------------- View "Đơn hiện tại" ---------------------------
     * Không còn là modal: #of-order-view phủ vùng nội dung (khung .app-ov-* của app_shell.css),
     * nút × trả về view "Tồn thành phẩm nhà máy". body.app-ov-open khoá cuộn trang nền (view này
     * cho cuộn body — xem cuối order-factory.css). */
    function openOrderView() {
        $('body').addClass('app-ov-open');
        $('#of-order-view').fadeIn(120);
        if (typeof applyColVisibility === 'function') applyColVisibility();
    }
    function closeOrderView() {
        $('#brand-suggest, #goods-suggest').hide();
        $('#of-order-view').fadeOut(120, function () { $('body').removeClass('app-ov-open'); });
    }
    $(document).on('click', '#of-order-close', closeOrderView);

    /* --------------------------- Chế độ SỬA ĐƠN (order_history) ---------------------------
     * Panel là cùng một markup (_order_panel.php); ở order_history nó mang data-mode="edit":
     * mở từ nút "Chỉnh sửa" của 1 dòng lịch sử, chân view là "Lưu thay đổi" thay vì "Gửi nhà máy".
     * window.ofEditingOrderId để khối realtime của order_history biết đừng vẽ đè dòng đang sửa. */
    function panelIsEdit() { return $('#of-order-view').attr('data-mode') === 'edit'; }

    $(document).on('click', '.ofh-edit', function (e) {
        e.preventDefault();
        var id = $(this).data('id');
        $.getJSON(BASE + 'history_detail', { id: id }, function (res) {
            if (!res.ok || !res.data) { alert('Không tải được đơn hàng.'); return; }
            var d = res.data;
            if (Number(d.picked) === 1 || Number(d.locked) === 1) {
                alert('Đơn đã bị khóa hoặc đã bốc, không thể sửa.');
                return;
            }
            window.ofEditingOrderId = d.id;
            loadHistoryIntoForm(d);
            $('#of-order-title').text('Chỉnh sửa đơn hàng');
            openOrderView();
            setTimeout(runInventoryCheck, 400);   // báo ngay SP nào vượt tồn nhà máy
        }).fail(function () { alert('Lỗi mạng/Server.'); });
    });

    $(document).on('click', '#btn-save-edit', function () {
        var payload = collectOrderPayload();
        if (!payload.items.length) { alert('Đơn phải có ít nhất 1 sản phẩm.'); return; }
        if (!window.ofEditingOrderId) { alert('Không xác định được đơn đang sửa.'); return; }
        if (recentPending) { commitSuggestInput($('#order-table .qt-input.is-suggested')); setRecentButtons(false); }
        var $btn = $(this).prop('disabled', true);
        $.ajax({
            url: BASE + 'update_order', method: 'POST', contentType: 'application/json',
            data: JSON.stringify({
                id: window.ofEditingOrderId, items: payload.items,
                weight_total: payload.weight_total, value_total: payload.value_total,
                note: payload.note || ''
            }), dataType: 'json'
        }).done(function (res) {
            if (res && res.ok) { alert('Đã lưu và gửi thông báo cho nhà máy.'); location.reload(); }
            else { alert((res && res.msg) || 'Không lưu được.'); $btn.prop('disabled', false); }
        }).fail(function () { alert('Lỗi mạng/Server.'); $btn.prop('disabled', false); });
    });

    /* --------------------------- Cart badge --------------------------- */
    function updateCartCount() {
        var n = $('#order-table tbody tr').length;
        $('#of-cart-count').text(n);
        $('#of-cart-cur-count').text(n);
    }
    // Hover xổ dropdown (CSS). Click "Đơn hàng hiện tại" -> mở view đơn.
    $(document).on('click', '#of-cart-current', openOrderView);

    /* --------------------------- Search lọc tức thì --------------------------- */
    // Bỏ dấu tiếng Việt để tìm không phụ thuộc dấu ("hong tra" khớp "Hồng Trà").
    function noAccent(s) {
        return String(s || '')
            .normalize('NFD').replace(/[\u0300-\u036f]/g, '')
            .replace(/đ/g, 'd').replace(/Đ/g, 'D')
            .toLowerCase();
    }
    function escHtml(s) {
        return String(s == null ? '' : s)
            .replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;');
    }
    var outStockTimer = null, lastShownInStock = 0;
    $(document).on('input', '#of-search-input', function () {
        // Lưới tồn lọc không phụ thuộc dấu; out-of-stock dùng keyword gốc cho LIKE phía server.
        var raw = String($(this).val() || '').trim();
        var kw = noAccent(raw);
        var shown = 0, total = 0;
        $('#of-inventory .of-block .of-prod').each(function () {
            total++;
            var hay = noAccent($(this).attr('data-search'));
            var ok = kw === '' || hay.indexOf(kw) !== -1;
            $(this).toggle(ok);
            if (ok) shown++;
        });
        // Ẩn nhóm/cột rỗng khi đang lọc.
        $('.of-group-col').each(function () {
            var has = $(this).find('.of-prod:visible').length > 0;
            $(this).toggle(kw === '' || has);
        });
        $('.of-block').each(function () {
            var has = $(this).find('.of-prod:visible').length > 0;
            $(this).toggle(kw === '' || has);
        });
        lastShownInStock = shown;
        $('#of-result-info').text(kw === '' ? '' : (shown + ' sản phẩm'));
        searchOutOfStock(raw, shown);
    });

    /* --- SP đang hết: chỉ hiện khi search, bấm vào vẫn thêm vào giỏ được --- */
    function searchOutOfStock(rawKw, shown) {
        clearTimeout(outStockTimer);
        var $box = $('#of-out-stock');
        if (!rawKw) { $box.empty().hide(); $('#of-empty-search').hide(); return; }
        $('#of-empty-search').toggle(shown === 0); // tạm; callback dưới sẽ chỉnh lại
        outStockTimer = setTimeout(function () {
            $.getJSON(BASE + 'search_out_of_stock', { keyword: rawKw }, function (res) {
                var rows = (res && res.ok && res.data) ? res.data : [];
                if (!rows.length) {
                    $box.empty().hide();
                    $('#of-empty-search').toggle(lastShownInStock === 0);
                    return;
                }
                var html = '<div class="of-out-head"><i class="fa-solid fa-box-open"></i> Sản phẩm đang hết:</div>'
                         + '<div class="of-out-list">';
                $.each(rows, function (i, p) {
                    html += '<div class="of-prod of-prod-out product-item btn-order-product" data-product-id="' + p.product_id + '" title="Thêm vào giỏ hàng">'
                          + '<span class="of-prod-name">' + escHtml(p.product_name) + '</span>'
                          + '<span class="of-prod-lead"></span>'
                          + '<span class="of-prod-inv of-out-tag">Đang hết</span>'
                          + '</div>';
                });
                html += '</div>';
                $box.html(html).show();
                $('#of-empty-search').hide(); // có gợi ý SP đang hết -> không báo "không có SP"
            }).fail(function () { $box.empty().hide(); });
        }, 300);
    }

    /* --------------------------- Autocomplete: Chi nhánh --------------------------- */
    var brandTimer = null;
    $(document).on('input', '#brand', function () {
        clearTimeout(brandTimer);
        var kw = String($(this).val() || '').trim();
        $('#brand_id').val('');
        if (kw.length === 0) { $('#brand-suggest').empty().hide(); return; }
        brandTimer = setTimeout(function () {
            $.getJSON(BASE + 'search_customer', { keyword: kw }, function (res) {
                if (!res.ok) return;
                var html = '';
                $.each(res.data, function (i, c) {
                    html += '<li data-id="' + c.id + '" data-name="' + escHtml(c.name) + '" data-short="' + escHtml(c.short_name || '') + '">'
                          + escHtml(c.name) + (c.short_name ? ' <span class="muted">(' + escHtml(c.short_name) + ')</span>' : '')
                          + '</li>';
                });
                $('#brand-suggest').html(html).toggle(html !== '');
            });
        }, 250);
    });
    $(document).on('click', '#brand-suggest li', function () {
        var name = $(this).data('name'), id = $(this).data('id');
        var short = String($(this).data('short') || '').trim();
        // Ô chi nhánh hiển thị TÊN VIẾT TẮT (PLHCM, PLDN…); tên đầy đủ giữ ở #brand_full để gửi server.
        $('#brand').val(short !== '' ? short : name);
        $('#brand_full').val(name);
        $('#brand_id').val(id);
        $('#brand-suggest').empty().hide();
        rememberBranch();
    });
    // Ghi nhớ chi nhánh (nhập 1 lần dùng nhiều lần).
    function rememberBranch() {
        $.post(BASE + 'set_branch', {
            customer_id: $('#brand_id').val() || '',
            customer_name: branchFullName()
        });
    }
    $(document).on('change', '#brand', rememberBranch);

    /** Tên đầy đủ của chi nhánh (gửi lên server); chưa chọn từ gợi ý thì lấy đúng chữ user gõ. */
    function branchFullName() {
        return String($('#brand_full').val() || $('#brand').val() || '').trim();
    }
    /** Tên hiển thị cho khách xem (hoá đơn Zalo/chat): ưu tiên tên viết tắt đang hiện trong ô. */
    function branchLabel() {
        return String($('#brand').val() || '').trim();
    }

    /* --------------------------- Nút × xóa nhanh từ khóa trong ô tìm --------------------------- */
    $(document).on('click', '.of-input-clear', function () {
        var $inp = $('#' + $(this).data('clear'));
        $inp.val('').trigger('input').focus();
    });

    /* --------------------------- Autocomplete: Sản phẩm --------------------------- */
    var goodsTimer = null, goodsIdx = -1;
    $(document).on('input', '#search-goods', function () {
        clearTimeout(goodsTimer);
        var kw = String($(this).val() || '').trim();
        if (kw.length === 0) { $('#goods-suggest').empty().hide(); goodsIdx = -1; return; }
        goodsTimer = setTimeout(function () {
            $.getJSON(BASE + 'search_product', { keyword: kw }, function (res) {
                if (!res.ok) return;
                var html = '';
                $.each(res.data, function (i, p) {
                    var tag = p.type === 'material' ? ' · NVL' : '';
                    html += '<li data-type="' + p.type + '" data-id="' + p.ref_id + '">'
                          + escHtml(p.name) + ' <span class="muted">(' + escHtml(p.unit || '') + tag + ')</span>'
                          + '</li>';
                });
                goodsIdx = -1;
                $('#goods-suggest').html(html).toggle(html !== '');
            });
        }, 250);
    });

    // Mũi tên lên/xuống chọn, Tab/Enter xác nhận, Esc đóng (cùng mẫu với các ô tìm khác trong app).
    $(document).on('keydown', '#search-goods', function (e) {
        var $items = $('#goods-suggest li');
        if (!$items.length || $('#goods-suggest').is(':hidden')) return;
        if (e.key === 'ArrowDown') {
            e.preventDefault(); goodsIdx = (goodsIdx + 1) % $items.length;
        } else if (e.key === 'ArrowUp') {
            e.preventDefault(); goodsIdx = (goodsIdx - 1 + $items.length) % $items.length;
        } else if (e.key === 'Enter' || e.key === 'Tab') {
            e.preventDefault();
            chooseGoods($items.eq(goodsIdx >= 0 ? goodsIdx : 0));
            return;
        } else if (e.key === 'Escape') {
            $('#goods-suggest').hide(); return;
        } else { return; }
        $items.removeClass('active');
        $items.eq(goodsIdx).addClass('active')[0].scrollIntoView({ block: 'nearest' });
    });

    function chooseGoods($li) {
        if (!$li || !$li.length) return;
        var type = $li.data('type'), id = $li.data('id');
        $('#search-goods').val('').focus();
        $('#goods-suggest').empty().hide();
        goodsIdx = -1;
        if (type === 'material') addMaterialToOrder(id);
        else addProductToOrder(id);
    }
    $(document).on('click', '#goods-suggest li', function () { chooseGoods($(this)); });

    /* --------------------------- Click sản phẩm trên lưới tồn = thêm vào giỏ --------------------------- */
    $(document).on('click', '.btn-order-product', function () {
        var pid = $(this).closest('.product-item').data('product-id');
        addProductToOrder(pid); // chỉ ghi vào giỏ, KHÔNG mở modal -> user thêm nhiều lượt.
        // Phản hồi nhẹ: nháy badge giỏ hàng.
        $('#of-cart-btn').addClass('of-cart-bump');
        setTimeout(function () { $('#of-cart-btn').removeClass('of-cart-bump'); }, 300);
    });

    /* --------------------------- Xem info: click tên SP trong bảng đơn (tbody) --------------------------- */
    function showProductInfo(pid) {
        $.getJSON(BASE + 'product_detail', { product_id: pid }, function (res) {
            if (!res.ok || !res.data) return;
            var d = res.data;
            $('#m-info-image').attr('src', d.image_url || '');
            $('#m-info-name').text(d.product_name || '');
            $('#m-info-unit').text(d.unit || '');
            $('#m-info-qty').text((d.quantity != null ? d.quantity : 0) + ' ' + (d.unit || ''));
            $('#m-info-inner').text(d.inner_packaging_spec || '-');
            $('#m-info-outer').text(d.outer_packaging_spec || '-');
            $('#m-info-price').text(d.system_price != null ? fmtMoney(d.system_price) + ' đ' : '-');
            openModal('modal-info');
        });
    }
    $(document).on('click', '#order-table tbody td.text-left', function () {
        var pid = $(this).closest('tr').data('product-id');
        if (pid) showProductInfo(pid); // chỉ SP (NVL không có info quy cách).
    });

    /* --------------------------- Thêm dòng vào đơn --------------------------- */
    function addProductToOrder(pid) {
        var $exist = $('#order-table tbody tr[data-product-id="' + pid + '"]');
        if ($exist.length) { $exist.find('.qt-input').focus(); return; }
        $.getJSON(BASE + 'product_order_info', { product_id: pid }, function (res) {
            if (!res.ok || !res.data) return;
            appendOrderRow(res.data, false);
        });
    }
    function addMaterialToOrder(mid) {
        var $exist = $('#order-table tbody tr[data-material-id="' + mid + '"]');
        if ($exist.length) { $exist.find('.qt-input').focus(); return; }
        $.getJSON(BASE + 'material_order_info', { material_id: mid }, function (res) {
            if (!res.ok || !res.data) return;
            appendOrderRow(res.data, true);
        });
    }
    /* Ảnh tròn 18px cạnh tên SP. NVL và SP chưa có ảnh dùng ô tròn xám có icon để cột tên
       vẫn thẳng hàng (ảnh thật lấy từ products.image_url, server đã ghép sẵn đường dẫn). */
    function thumbHtml(src) {
        return src
            ? '<img class="of-row-thumb" src="' + escHtml(src) + '" alt="" loading="lazy" onerror="this.outerHTML=\'<span class=&quot;of-row-thumb is-empty&quot;><i class=&quot;fa-solid fa-image&quot;></i></span>\'">'
            : '<span class="of-row-thumb is-empty"><i class="fa-solid fa-image"></i></span>';
    }

    function appendOrderRow(d, isMaterial, qtyVal, lineWeight) {
        var idAttr = isMaterial
            ? 'data-material-id="' + (d.material_id != null ? d.material_id : d.product_id) + '"'
            : 'data-product-id="' + d.product_id + '"';
        var nameSuffix = isMaterial ? ' <span class="muted">(NVL)</span>' : '';
        var thumb = thumbHtml(isMaterial ? '' : (d.image_url || ''));
        var stock = Number(d.stock) || 0;
        // Ô số lượng: type=text + inputmode để KHÔNG có mũi tên tăng/giảm của input[number].
        var tr = '<tr ' + idAttr
               + ' data-weight-kg="' + d.weight_kg + '"'
               + ' data-system-price="' + d.system_price + '"'
               + ' data-stock="' + stock + '">'
               + '<td class="text-left">' + thumb + '<span class="of-row-name">' + escHtml(d.product_name) + nameSuffix + '</span></td>'
               + '<td>' + escHtml(d.unit || '') + '</td>'
               + '<td><input type="text" inputmode="decimal" class="qt-input" value="' + (qtyVal != null ? qtyVal : '') + '"></td>'
               + '<td class="td-weight of-col-weight">' + (lineWeight != null ? fmtWeight(lineWeight) : '0') + '</td>'
               + '<td class="td-stock of-col-stock" title="' + escHtml(d.stock_text || '') + '">' + fmtWeight(stock) + '</td>'
               + '<td class="text-center"><span class="btn-remove-row" title="Xóa">&times;</span></td>'
               + '</tr>';
        var $tr = $(tr);
        $('#order-table tbody').append($tr);
        applyColVisibility($tr);
        markOverStock($tr);
        recalcTotal();
        updateCartCount();
        return $tr;
    }

    /** SL đặt vượt tồn nhà máy -> tô đỏ số để user chú ý (không chặn đặt). */
    function markOverStock($tr) {
        var qty = parseFloat($tr.find('.qt-input').val()) || 0;
        var stock = parseFloat($tr.attr('data-stock'));
        var over = !isNaN(stock) && qty > stock;
        $tr.toggleClass('is-over-stock', over);
        $tr.find('.qt-input').toggleClass('is-over', over);
    }

    /* --------------------------- Tính tổng --------------------------- */
    $(document).on('input', '.qt-input', function () {
        var $tr = $(this).closest('tr');
        var qty = parseFloat($(this).val()) || 0;
        var wkg = parseFloat($tr.data('weight-kg')) || 0;
        $tr.find('.td-weight').text(fmtWeight(qty * wkg));
        markOverStock($tr);
        recalcTotal();
    });
    // Xóa dòng: mờ + thu lại rồi mới gỡ khỏi DOM (đỡ giật so với biến mất đột ngột).
    $(document).on('click', '.btn-remove-row', function () {
        var $tr = $(this).closest('tr');
        if ($tr.hasClass('is-vanishing')) return;
        $tr.addClass('is-vanishing');
        setTimeout(function () {
            $tr.remove();
            recalcTotal();
            updateCartCount();
            scheduleInvCheck();
            // Xóa hết dòng đang mang số tạm -> thu cặp "Đồng ý / Hủy" về lại 1 nút.
            if (recentPending && !$('#order-table .qt-input.is-suggested').length) setRecentButtons(false);
        }, 260);
    });
    function recalcTotal() {
        var wTotal = 0, vTotal = 0;
        $('#order-table tbody tr').each(function () {
            var $tr = $(this);
            var qty = parseFloat($tr.find('.qt-input').val()) || 0;
            var wkg = parseFloat($tr.data('weight-kg')) || 0;
            var price = parseFloat($tr.data('system-price')) || 0;
            wTotal += qty * wkg;
            vTotal += qty * price;
        });
        $('#weight-total-result').text(fmtWeight(wTotal) + ' kg');
        $('#value-total-result').text(fmtMoney(vTotal) + ' đ');
    }

    /* =================================================================================
       KHỐI TỒN KHO NHÀ MÁY (cột trái của view "Đơn hiện tại")
       ---------------------------------------------------------------------------------
       Danh sách dọc theo nhóm, lọc tức thì không dấu. Bấm 1 dòng = thêm thẳng vào đơn bên
       phải, kèm icon bay từ dòng tồn sang dòng vừa thêm + dòng đó chớp sáng, để khách đang
       xem đơn đặt thêm hàng mà không phải đóng view ra lưới tồn.
       ================================================================================= */
    $(document).on('input', '#of-stock-search', function () {
        var kw = noAccent(String($(this).val() || '').trim());
        var shown = 0;
        $('#of-ov-stock-list .of-stock-row').each(function () {
            var ok = kw === '' || noAccent($(this).attr('data-search')).indexOf(kw) !== -1;
            $(this).toggle(ok);
            if (ok) shown++;
        });
        $('#of-ov-stock-list .of-stock-group').each(function () {
            $(this).toggle(kw === '' || $(this).find('.of-stock-row:visible').length > 0);
        });
        $('#of-stock-empty').prop('hidden', shown !== 0);
    });

    $(document).on('click', '.of-stock-row', function () {
        var $src = $(this);
        var pid = $src.data('product-id');
        if (!pid) return;
        var $exist = $('#order-table tbody tr[data-product-id="' + pid + '"]');
        if ($exist.length) {                       // đã có trong đơn -> chỉ nhấn mạnh dòng cũ
            flyToOrderRow($src, $exist);
            $exist.find('.qt-input').focus().select();
            return;
        }
        $.getJSON(BASE + 'product_order_info', { product_id: pid }, function (res) {
            if (!res.ok || !res.data) return;
            var $tr = appendOrderRow(res.data, false);
            flyToOrderRow($src, $tr);
            scheduleInvCheck();
        });
    });

    /** Icon tròn bay từ dòng tồn sang dòng trong đơn, tới nơi thì dòng đó chớp sáng. */
    function flyToOrderRow($from, $to) {
        if (!$from || !$from.length || !$to || !$to.length) { if ($to) $to.addClass('of-row-flash'); return; }
        var a = $from[0].getBoundingClientRect(), b = $to[0].getBoundingClientRect();
        var $dot = $('<div class="app-fly-history"><i class="fa-solid fa-cart-plus"></i></div>')
            .css({ left: (a.left + a.width / 2 - 17) + 'px', top: (a.top + a.height / 2 - 17) + 'px' })
            .appendTo('body');
        // Ép trình duyệt tính layout trước khi gắn class đích, nếu không transition không chạy.
        $dot[0].getBoundingClientRect();
        $dot.css({
            '--fh-dx': (b.left + 24 - (a.left + a.width / 2)) + 'px',
            '--fh-dy': (b.top + b.height / 2 - (a.top + a.height / 2)) + 'px'
        }).addClass('is-arrived');
        setTimeout(function () {
            $dot.remove();
            $to.addClass('of-row-flash');
            setTimeout(function () { $to.removeClass('of-row-flash'); }, 1200);
        }, 560);
    }

    /* =================================================================================
       ẨN / HIỆN TẠM CỘT "Khối lượng (kg)" và "Tồn nhà máy"
       Nút mắt gạch nằm trong ô tiêu đề (hiện khi hover); ẩn rồi thì nút mở lại nằm cùng
       hàng với "Lấy số đặt gần đây".
       ================================================================================= */
    // Trạng thái ẩn cột CÁ NHÂN HÓA theo user — server render sẵn từ data-hidden-cols
    // (xem _order_panel.php + sf_get_hidden_cols) để không bị "chớp" hiện rồi mới ẩn lúc tải trang.
    var hiddenCols = {};
    (function initHiddenCols() {
        var raw = String($('#of-order-view').attr('data-hidden-cols') || '').trim();
        if (!raw) return;
        raw.split(',').forEach(function (c) { c = c.trim(); if (c) hiddenCols[c] = true; });
    })();
    function applyColVisibility($scope) {
        $.each(['weight', 'stock'], function (i, col) {
            var hide = !!hiddenCols[col];
            ($scope || $('#of-order-view')).find('.of-col-' + col).toggle(!hide);
            $('#of-order-view .of-col-show[data-col="' + col + '"]').prop('hidden', !hide);
        });
    }
    /** Ghi lại lên server để lần vào trang sau vẫn giữ đúng cột đang ẩn (mỗi user riêng). */
    function saveHiddenCols() {
        var cols = Object.keys(hiddenCols).filter(function (c) { return hiddenCols[c]; });
        $.post(BASE + 'save_hidden_cols', { cols: cols.join(',') });
    }
    $(document).on('click', '.of-col-hide', function (e) {
        e.stopPropagation();
        hiddenCols[$(this).data('col')] = true;
        applyColVisibility();
        saveHiddenCols();
    });
    $(document).on('click', '.of-col-show', function () {
        hiddenCols[$(this).data('col')] = false;
        applyColVisibility();
        saveHiddenCols();
    });

    /* =================================================================================
       LẤY SỐ ĐẶT GẦN ĐÂY (góc phải bảng hàng)
       ---------------------------------------------------------------------------------
       Nguồn số liệu là DỮ LIỆU XUẤT KHO của nhà máy cho chi nhánh này (`stock_exports`), tức số
       lượng chi nhánh THỰC SỰ lấy hàng lần gần nhất — không phải số đã đặt.

       Bấm nút -> điền số vào cột "Số lượng đặt" bằng màu xám (tạm), hàng chưa từng lấy để trống;
       nút đổi thành cặp "Đồng ý / Hủy":
         - Đồng ý -> số xám thành số thật (đen), sửa tiếp được trước khi gửi. Nút trở lại
                     "Lấy số đặt gần đây" nhưng ở dạng CHÌM (.is-used) để user biết đã lấy 1 lần.
         - Hủy    -> trả các ô về giá trị trước khi bấm (thường là rỗng) để nhập tay.
       Bấm LẠI (kể cả khi nút đang chìm) = nạp lại số mới nhất, GHI ĐÈ mọi số đang có — đúng ý
       "load lại rồi chỉnh sửa tiếp".
       ================================================================================= */
    var recentPending = false, recentUsed = false, recentBackup = null;

    function setRecentButtons(pending) {
        recentPending = pending;
        $('#of-recent-qty-btn').prop('hidden', pending).toggleClass('is-used', recentUsed);
        $('#of-recent-confirm').prop('hidden', !pending);
    }

    /** Chốt các ô xám thành số thật (dùng cho "Đồng ý" và khi user tự gõ đè). */
    function commitSuggestInput($inp) {
        $inp.removeClass('is-suggested');
    }

    $(document).on('click', '#of-recent-qty-btn', function () {
        var $rows = $('#order-table tbody tr');
        if (!$rows.length) { alert('Chưa có sản phẩm nào trong đơn để lấy số.'); return; }
        var cid = $('#brand_id').val() || '';
        if (!cid) { alert('Chọn chi nhánh từ danh sách gợi ý trước để lấy số mua gần đây.'); $('#brand').focus(); return; }

        var $btn = $(this).prop('disabled', true);
        $.getJSON(BASE + 'recent_quantities', { customer_id: cid }, function (res) {
            $btn.prop('disabled', false);
            if (!res || !res.ok) { alert((res && res.msg) || 'Không lấy được số mua gần đây.'); return; }
            var map = res.data || {};
            var filled = 0;
            recentBackup = [];   // để nút "Hủy" trả lại đúng số đang có trước khi nạp
            $rows.each(function () {
                var $tr = $(this);
                var mid = $tr.data('material-id'), pid = $tr.data('product-id');
                var key = (mid != null ? 'material:' + mid : 'product:' + pid);
                var $inp = $tr.find('.qt-input');
                recentBackup.push({ $inp: $inp, val: $inp.val() });
                var qty = map[key];
                if (qty == null) { $inp.val('').removeClass('is-suggested'); }   // chưa từng lấy -> bỏ trống
                else { $inp.val(qty).addClass('is-suggested'); filled++; }
                $tr.find('.td-weight').text(fmtWeight((Number(qty) || 0) * (parseFloat($tr.data('weight-kg')) || 0)));
                markOverStock($tr);
            });
            if (!filled) {
                alert('Chi nhánh này chưa từng lấy các mặt hàng đang có trong đơn (không có dữ liệu xuất kho).');
            }
            recalcTotal();
            scheduleInvCheck();
            setRecentButtons(true);
        }).fail(function () { $btn.prop('disabled', false); alert('Lỗi mạng/Server.'); });
    });

    $(document).on('click', '#of-recent-qty-ok', function () {
        commitSuggestInput($('#order-table .qt-input.is-suggested'));
        recentUsed = true;               // nút trở lại dạng chìm: đã lấy số 1 lần
        recentBackup = null;
        setRecentButtons(false);
    });

    $(document).on('click', '#of-recent-qty-cancel', function () {
        $.each(recentBackup || [], function (i, b) {
            var $tr = b.$inp.closest('tr');
            b.$inp.val(b.val).removeClass('is-suggested');
            $tr.find('.td-weight').text(fmtWeight((parseFloat(b.val) || 0) * (parseFloat($tr.data('weight-kg')) || 0)));
            markOverStock($tr);
        });
        recentBackup = null;
        recalcTotal();
        scheduleInvCheck();
        setRecentButtons(false);
    });

    // User tự sửa 1 ô đang là số tạm -> ô đó thành số thật; hết ô tạm thì thu lại về 1 nút.
    $(document).on('input', '.qt-input.is-suggested', function () {
        commitSuggestInput($(this));
        if (!$('#order-table .qt-input.is-suggested').length) { recentUsed = true; setRecentButtons(false); }
    });

    /* --------------------------- Payload --------------------------- */
    function collectOrderPayload() {
        var items = [], wTotal = 0, vTotal = 0;
        $('#order-table tbody tr').each(function () {
            var $tr = $(this);
            var qty = parseFloat($tr.find('.qt-input').val()) || 0;
            if (qty <= 0) return;
            var wkg = parseFloat($tr.data('weight-kg')) || 0;
            var price = parseFloat($tr.data('system-price')) || 0;
            var mid = $tr.data('material-id');
            var pid = $tr.data('product-id');
            items.push({
                product_id: pid != null ? pid : null,
                material_id: mid != null ? mid : null,
                type: mid != null ? 'material' : 'product',
                product_name: $tr.find('td').eq(0).text(),
                unit: $tr.find('td').eq(1).text(),
                qt_order: qty,
                weight_kg: wkg,
                system_price: price,
                line_weight: qty * wkg,
                line_value: qty * price
            });
            wTotal += qty * wkg;
            vTotal += qty * price;
        });
        return {
            customer_id: $('#brand_id').val() || null,
            customer_name: branchFullName(),
            customer_label: branchLabel(),   // tên viết tắt — chỉ dùng để hiển thị/hoá đơn
            note: $('#order-note').val() || '',
            items: items,
            weight_total: wTotal,
            value_total: vTotal
        };
    }

    /* --------------------------- Gửi nhà máy --------------------------- */
    $(document).on('click', '#btn-share-factory', function () {
        // Gửi đơn = coi như đã đồng ý các số tạm còn sót lại (số vẫn đang hiện trên bảng).
        if (recentPending) { commitSuggestInput($('#order-table .qt-input.is-suggested')); setRecentButtons(false); }
        var payload = collectOrderPayload();
        if (payload.items.length === 0) { alert('Bạn chưa thêm sản phẩm nào để đặt.'); return; }
        if (!payload.customer_name) { alert('Vui lòng chọn/nhập chi nhánh.'); $('#brand').focus(); return; }
        runInventoryCheck();
        $.ajax({
            url: BASE + 'save_order', method: 'POST',
            contentType: 'application/json', data: JSON.stringify(payload), dataType: 'json'
        }).done(function (res) {
            if (!res.ok) { alert(res.msg || 'Có lỗi xảy ra'); return; }
            alert('Đã gửi đơn hàng tới nhà máy.');
            reloadRecent();
            // Reset đơn sau khi gửi.
            $('#order-table tbody').empty();
            $('#order-note').val('');
            recalcTotal();
            updateCartCount();
            $('#order-inv-status').attr('hidden', '').empty();
            closeOrderView(); // đơn đã gửi -> trả về view "Tồn thành phẩm nhà máy"
        }).fail(function () { alert('Lỗi mạng/Server.'); });
    });

    /* --------------------------- Trước đó / Đặt lại --------------------------- */
    function reloadRecent() {
        $.getJSON(BASE + 'history', { page: 1 }, function (res) {
            if (!res.ok) return;
            var rows = res.data.rows || [];
            var html = '';
            $.each(rows, function (i, h) {
                var d = new Date(String(h.created_at).replace(' ', 'T'));
                var ds = String(d.getDate()).padStart(2, '0') + '/' +
                         String(d.getMonth() + 1).padStart(2, '0') + '/' + d.getFullYear();
                html += '<li>'
                      + '<a href="#" class="btn-reorder of-recent-desc" data-id="' + h.id + '">Đặt hàng ' + ds + ' — ' + h.description + '</a>'
                      + '<span class="of-recent-del btn-delete-history" data-id="' + h.id + '" title="Xóa đơn">&times;</span>'
                      + '</li>';
            });
            if (!html) html = '<li class="of-recent-empty">Chưa có đơn nào.</li>';
            $('#of-recent-list').html(html);
        });
    }

    $(document).on('click', '.btn-reorder', function (e) {
        e.preventDefault();
        var id = $(this).data('id');
        loadOrderById(id);
    });
    function loadOrderById(id) {
        $.getJSON(BASE + 'history_detail', { id: id }, function (res) {
            if (!res.ok || !res.data) return;
            loadHistoryIntoForm(res.data);
            openOrderView();
            setTimeout(runInventoryCheck, 500);
        });
    }
    function loadHistoryIntoForm(row) {
        var short = String(row.customer_short || '').trim();
        $('#brand').val(short !== '' ? short : (row.customer_name || ''));
        $('#brand_full').val(row.customer_name || '');
        $('#brand_id').val(row.customer_id || '');
        $('#order-note').val(row.note || '');
        $('#order-table tbody').empty();
        if (Array.isArray(row.order_items)) {
            $.each(row.order_items, function (i, it) {
                appendOrderRow({
                    product_id: it.product_id,
                    material_id: it.material_id,
                    product_name: it.product_name,
                    unit: it.unit,
                    weight_kg: it.weight_kg,
                    system_price: it.system_price,
                    // image_url + stock do server gắn thêm trong sf_get_history_row()
                    image_url: it.image_url,
                    stock: it.stock
                }, !!it.material_id, it.qt_order, it.line_weight);
            });
        }
        recalcTotal();
        updateCartCount();
    }

    /* --------------------------- Xóa đơn khỏi lịch sử --------------------------- */
    $(document).on('click', '.btn-delete-history', function (e) {
        e.preventDefault();
        var id = $(this).data('id');
        if (!confirm('Xóa đơn này khỏi lịch sử? Thao tác không thể hoàn tác.')) return;
        var $row = $(this).closest('tr, li');
        $.post(BASE + 'delete_history', { id: id }, function (res) {
            if (res && res.ok) $row.remove();
            else alert((res && res.msg) || 'Không xóa được.');
        }, 'json').fail(function () { alert('Lỗi mạng/Server.'); });
    });

    /* --------------------------- Share Zalo --------------------------- */
    $(document).on('click', '#btn-share-zalo', function () {
        // Dựng hoá đơn bằng ĐÚNG hàm mà nút "Gửi qua chat" dùng -> hai đường không bao giờ lệch.
        if (!fillInvoicePreview()) { alert('Chưa có sản phẩm để chia sẻ.'); return; }
        openModal('modal-zalo');
    });
    /* ---------------------------------------------------------------------------------
       CHỤP HOÁ ĐƠN — hàm dùng chung cho "Chụp hóa đơn" (Zalo) và "Gửi qua chat", để hai
       đường luôn ra CÙNG MỘT tấm ảnh.

       Hai việc phải làm quanh lúc chụp:
       1. Gỡ viền gạch đứt của .zalo-capture — nó chỉ để nhìn trên màn hình, không được vào ảnh.
       2. Nếu #modal-zalo đang đóng (đường "Gửi qua chat" không mở nó ra) thì phải cho nó
          ĐƯỢC BỐ TRÍ, nếu không html2canvas đo ra 0x0 và ảnh rỗng. Dùng display:flex kèm
          opacity:0 thay vì mở modal thật: opacity nằm trên lớp mask (cha), mà html2canvas chỉ
          đọc computed style của CHÍNH #zalo-capture trở xuống nên ảnh vẫn đục bình thường.
       --------------------------------------------------------------------------------- */
    function captureInvoiceCanvas() {
        var el = document.getElementById('zalo-capture');
        var $mask = $('#modal-zalo');
        var dangHien = $mask.is(':visible');
        var styleCu = $mask.attr('style') || '';

        if (!dangHien) $mask.css({ display: 'flex', opacity: 0, pointerEvents: 'none' });
        $(el).addClass('of-capturing');

        // html2canvas trả Promise GỐC (không phải Deferred của jQuery) nên KHÔNG có .always() —
        // phải dọn dẹp ở cả 2 nhánh bằng tay, nếu không viền gạch đứt sẽ mất luôn trên màn hình
        // và #modal-zalo kẹt ở display:flex khi chụp lỗi.
        var donDep = function () {
            $(el).removeClass('of-capturing');
            if (!dangHien) {
                if (styleCu) $mask.attr('style', styleCu);
                else $mask.removeAttr('style').hide();
            }
        };
        return html2canvas(el, { backgroundColor: '#fff', scale: 2 }).then(
            function (canvas) { donDep(); return canvas; },
            function (err) { donDep(); throw err; }
        );
    }

    /** Bọc canvas -> Blob PNG (toBlob không trả Promise sẵn). */
    function canvasToBlob(canvas) {
        return new Promise(function (resolve, reject) {
            canvas.toBlob(function (b) { b ? resolve(b) : reject(new Error('Không dựng được ảnh.')); }, 'image/png');
        });
    }

    $(document).on('click', '#btn-capture-zalo', function () {
        var $btn = $(this).prop('disabled', true).text('Đang chụp...');
        captureInvoiceCanvas().then(function (canvas) {
            canvas.toBlob(function (blob) {
                if (navigator.clipboard && window.ClipboardItem) {
                    navigator.clipboard.write([new ClipboardItem({ 'image/png': blob })]).then(function () {
                        alert('Đã copy hóa đơn vào clipboard. Mở Zalo và dán bằng Ctrl+V.');
                    }).catch(function () { downloadCanvas(canvas); });
                } else { downloadCanvas(canvas); }
                $btn.prop('disabled', false).text('Chụp hóa đơn');
            }, 'image/png');
        });
    });

    /* =================================================================================
       GỬI ĐƠN QUA CHAT — dựng đúng hoá đơn của "Share Zalo" rồi gửi ảnh đó vào hộp thoại
       chat với người được chọn. Server: sf_share_order_to_chat().
       ================================================================================= */

    /** Đổ nội dung đơn hiện tại vào #zalo-capture. Trả payload, hoặc null nếu đơn rỗng. */
    function fillInvoicePreview() {
        var payload = collectOrderPayload();
        if (payload.items.length === 0) return null;
        // Hoá đơn gửi khách: tên chi nhánh viết tắt, tổng khối lượng làm tròn số nguyên.
        $('#zalo-customer-name').text(payload.customer_label || payload.customer_name || '________');
        var rows = '';
        $.each(payload.items, function (i, it) {
            rows += '<tr><td>' + escHtml(it.product_name) + '</td><td>' + escHtml(it.unit || '') + '</td>'
                  + '<td>' + it.qt_order + '</td><td>' + fmtWeight(it.line_weight) + '</td></tr>';
        });
        $('#zalo-body').html(rows);
        $('#zalo-weight-total-value').text(Math.round(Number(payload.weight_total) || 0).toLocaleString('vi-VN'));
        return payload;
    }

    $(document).on('click', '#btn-share-chat', function () {
        if (!fillInvoicePreview()) { alert('Chưa có sản phẩm để gửi.'); return; }
        $('#of-chat-status').text('');
        $('#of-chat-filter').val('');
        openModal('modal-share-chat');
        loadChatContacts();
    });

    var chatContactsLoaded = false;
    function loadChatContacts() {
        if (chatContactsLoaded) return;
        $.getJSON(BASE + 'chat_contacts', function (res) {
            if (!res || !res.ok) { $('#of-chat-list').html('<div class="of-chat-empty">Không tải được danh bạ.</div>'); return; }
            var rows = (res.data || []).map(function (u) {
                var ten = u.alias || u.fullname || u.username || '';
                var ava = u.avatar
                    ? '<img class="of-chat-ava" src="' + escHtml(u.avatar) + '" alt="">'
                    : '<span class="of-chat-ava of-chat-ava-x">' + escHtml(ten.charAt(0)) + '</span>';
                return '<label class="of-chat-row">'
                     + '<input type="checkbox" class="of-chat-pick" value="' + (+u.id) + '">'
                     + ava + '<span>' + escHtml(ten) + '</span>'
                     + (u.online ? '<span class="of-chat-dot" title="Đang online"></span>' : '')
                     + '</label>';
            }).join('');
            $('#of-chat-list').html(rows || '<div class="of-chat-empty">Chưa có ai trong danh bạ.</div>');
            chatContactsLoaded = true;
        }).fail(function () {
            $('#of-chat-list').html('<div class="of-chat-empty">Lỗi mạng khi tải danh bạ.</div>');
        });
    }

    $(document).on('input', '#of-chat-filter', function () {
        var kw = noAccent(String($(this).val() || ''));
        $('#of-chat-list .of-chat-row').each(function () {
            $(this).toggle(kw === '' || noAccent($(this).text()).indexOf(kw) !== -1);
        });
    });

    $(document).on('click', '#of-chat-send', function () {
        var $btn = $(this);
        var targets = $('#of-chat-list .of-chat-pick:checked').map(function () { return +this.value; }).get();
        if (!targets.length) { $('#of-chat-status').text('Chưa chọn người nhận.'); return; }

        $btn.prop('disabled', true).text('Đang gửi…');
        $('#of-chat-status').text('Đang dựng ảnh hoá đơn…');

        captureInvoiceCanvas()
            .then(canvasToBlob)
            .then(function (blob) {
                var fd = new FormData();
                fd.append('image', blob, 'don-hang.png');
                fd.append('note', $('#of-chat-note').val() || '');
                $.each(targets, function (i, id) { fd.append('targets[]', id); });
                $('#of-chat-status').text('Đang gửi…');
                return $.ajax({
                    url: BASE + 'share_order_to_chat', method: 'POST', data: fd,
                    processData: false, contentType: false, dataType: 'json'
                });
            })
            .then(function (res) {
                if (res && res.ok) {
                    closeModal('modal-share-chat');
                    alert('Đã gửi hoá đơn cho ' + res.sent + ' người qua chat.');
                    $('#of-chat-note').val('');
                    $('#of-chat-list .of-chat-pick:checked').prop('checked', false);
                } else {
                    $('#of-chat-status').text((res && res.msg) || 'Gửi thất bại.');
                }
            })
            .catch(function () { $('#of-chat-status').text('Lỗi mạng khi gửi.'); })
            .then(function () { $btn.prop('disabled', false).text('Gửi'); });
    });
    function downloadCanvas(canvas) {
        var a = document.createElement('a');
        a.href = canvas.toDataURL('image/png');
        a.download = 'don-hang-nha-may.png';
        a.click();
    }

    /* --------------------------- Đóng dropdown khi click ngoài --------------------------- */
    $(document).on('click', function (e) {
        if (!$(e.target).closest('.wp-search-branch').length) $('#brand-suggest').hide();
        if (!$(e.target).closest('.wp-search-goods').length) $('#goods-suggest').hide();
    });

    /* --------------------------- Tình hình đơn hàng (đối chiếu tồn) --------------------------- */
    var invCheckTimer = null;
    function collectOrderProducts() {
        var items = [];
        $('#order-table tbody tr').each(function () {
            var $tr = $(this);
            var pid = $tr.data('product-id');
            var qty = parseFloat($tr.find('.qt-input').val()) || 0;
            if (pid && qty > 0) items.push({ product_id: pid, qty: qty });
        });
        return items;
    }
    /* Đơn dài (>10 SP) thì khối này chiếm hết màn hình -> gập lại kiểu FAQ, bấm tiêu đề mới xổ.
       Đơn ngắn giữ nguyên kiểu cũ (luôn mở) cho khỏi phải bấm thêm 1 nhịp. */
    var FAQ_FROM = 10;
    function renderInvStatus(data) {
        var $box = $('#order-inv-status');
        var rowCount = $('#order-table tbody tr').length;
        var faq = rowCount > FAQ_FROM;
        var wasOpen = $box.hasClass('is-open');
        var shortCount = (data.all_enough || !data.lines) ? 0 : data.lines.length;

        var head = 'Tình hình đơn hàng:';
        if (faq) {
            head = '<button type="button" class="ois-toggle"><i class="fa-solid fa-chevron-right"></i> Tình hình đơn hàng'
                 + (shortCount ? ' <span class="ois-badge">' + shortCount + ' SP thiếu</span>'
                               : ' <span class="ois-badge is-ok">đủ tồn</span>') + '</button>';
        }
        var html = '<div class="ois-title">' + head + '</div><div class="ois-content">';
        if (data.all_enough) {
            html += '<div class="ois-ok"><i class="fa-solid fa-circle-check"></i> Đơn hàng đủ tồn để nhà máy xuất</div>';
        } else {
            html += '<ul class="ois-list">';
            $.each(data.lines, function (i, l) {
                var unit = l.unit ? (' ' + l.unit) : '';
                var line = '<strong>' + escHtml(l.product_name) + '</strong>: Thiếu ' +
                           fmtWeight(l.shortage) + unit + ' (order ' + fmtWeight(l.order_qty) + ')';
                if (l.plan) {
                    line += ' - <span class="ois-plan">Sẽ được sản xuất vào ' + escHtml(l.plan.weekday) + ' (' + escHtml(l.plan.display) + ')</span>';
                } else {
                    line += ' - <span class="ois-noplan">Chưa có lịch sản xuất</span>';
                }
                html += '<li>' + line + '</li>';
            });
            html += '</ul>';
        }
        html += '</div>';
        $box.html(html).removeAttr('hidden')
            .toggleClass('is-faq', faq)
            .toggleClass('is-open', faq ? wasOpen : true);
    }
    $(document).on('click', '.ois-toggle', function () {
        $('#order-inv-status').toggleClass('is-open');
    });
    function runInventoryCheck() {
        var items = collectOrderProducts();
        if (!items.length) { $('#order-inv-status').attr('hidden', '').empty(); return; }
        $.ajax({
            url: BASE + 'order_inventory_check', method: 'POST',
            contentType: 'application/json', data: JSON.stringify({ items: items }), dataType: 'json'
        }).done(function (res) { if (res && res.ok) renderInvStatus(res.data); });
    }
    function scheduleInvCheck() { clearTimeout(invCheckTimer); invCheckTimer = setTimeout(runInventoryCheck, 400); }
    $(document).on('input', '.qt-input', scheduleInvCheck);
    // (Xóa dòng gọi scheduleInvCheck trong handler .btn-remove-row — phải đợi dòng biến mất hẳn.)
    // Kiểm tồn định kỳ: dừng khi tab bị ẩn (AppPoll — public/js/shared/app_shell.js).
    var invTick = function () { if (collectOrderProducts().length) runInventoryCheck(); };
    if (window.AppPoll) window.AppPoll.every('of-inventory', invTick, { interval: 20000, maxInterval: 60000 });
    else setInterval(invTick, 20000);

    /* --------------------------- Cài đặt thứ tự hiển thị --------------------------- */
    var settingLoaded = false;
    $(document).on('click', '#of-setting-btn', function () {
        openModal('modal-setting');
        if (settingLoaded) return;
        $.getJSON(BASE + 'display_order_list', function (res) {
            if (!res.ok) { $('#of-setting-list').html('<div class="of-setting-loading">Không tải được.</div>'); return; }
            var html = '<table class="of-set-table"><thead><tr>'
                     + '<th>Tên sản phẩm</th><th style="width:90px;">Thứ tự</th></tr></thead><tbody>';
            $.each(res.data, function (i, p) {
                var nm = String(p.product_name || '');
                var cat = p.category_name ? '<div class="of-set-cat">' + p.category_name + '</div>' : '';
                html += '<tr data-name="' + nm.toLowerCase() + '">'
                      + '<td>' + nm + cat + '</td>'
                      + '<td><input type="number" class="of-set-order-input" min="1" step="1"'
                      + ' data-product-id="' + p.product_id + '"'
                      + ' value="' + (p.sort_order != null ? p.sort_order : '') + '"></td>'
                      + '</tr>';
            });
            html += '</tbody></table>';
            $('#of-setting-list').html(html);
            settingLoaded = true;
        });
    });
    $(document).on('input', '#of-setting-search', function () {
        var kw = String($(this).val() || '').trim().toLowerCase();
        $('#of-setting-list .of-set-table tbody tr').each(function () {
            $(this).toggle(kw === '' || String($(this).data('name')).indexOf(kw) !== -1);
        });
    });
    $(document).on('click', '#of-setting-save', function () {
        var orders = {};
        $('#of-setting-list .of-set-order-input').each(function () {
            orders[$(this).data('product-id')] = $(this).val();
        });
        var $btn = $(this).prop('disabled', true).text('Đang lưu...');
        $.ajax({
            url: BASE + 'save_display_order', method: 'POST',
            contentType: 'application/json', data: JSON.stringify({ orders: orders }), dataType: 'json'
        }).done(function (res) {
            if (res && res.ok) { location.reload(); }
            else { alert('Không lưu được.'); $btn.prop('disabled', false).text('Lưu thứ tự'); }
        }).fail(function () { alert('Lỗi mạng/Server.'); $btn.prop('disabled', false).text('Lưu thứ tự'); });
    });

    /* --------------------------- Khởi tạo --------------------------- */
    $(function () {
        updateCartCount();
        // "Đặt lại" chuyển từ trang lịch sử qua.
        var rid = null;
        try { rid = sessionStorage.getItem('sf_reorder_id'); sessionStorage.removeItem('sf_reorder_id'); } catch (e) {}
        if (rid) loadOrderById(rid);

        flashRecentlyProduced();
    });

    /* ---------------------------------------------------------------------------------
       CHỚP SÁNG SẢN PHẨM CỦA MẺ SẢN XUẤT GẦN NHẤT
       View gắn sẵn data-recent-produced="1" cho những ô thuộc ngày sản xuất mới nhất trong
       finished_product_production_data (xem sf_get_recent_production_product_ids()).
       Ở đây chỉ bật/tắt class — toàn bộ phồng/giữ màu/nhạt dần nằm ở CSS .of-prod-flash.

       Gỡ class sau khi animation kết thúc để ô trở lại HOÀN TOÀN giống các ô khác: nếu để
       lại, `background` của keyframe cuối vẫn thắng rule :hover nên di chuột vào ô đó sẽ
       không đổi màu như những ô còn lại.
       --------------------------------------------------------------------------------- */
    function flashRecentlyProduced() {
        var $items = $('.of-prod[data-recent-produced="1"]');
        if (!$items.length) return;

        $items.addClass('of-prod-flash');
        $items.one('animationend', function () { $(this).removeClass('of-prod-flash'); });
        /* Chốt chặn theo thời gian (5.8s của keyframes + 0.4s dư): ô bị bộ lọc tìm kiếm ẩn đi
           (display:none) giữa chừng thì animation dừng và animationend KHÔNG BAO GIỜ bắn — gõ
           tìm kiếm ngay khi vừa vào trang là dính. Không có dòng này thì ô đó giữ nền xanh mãi. */
        setTimeout(function () { $items.removeClass('of-prod-flash'); }, 6200);
    }

})(jQuery);
