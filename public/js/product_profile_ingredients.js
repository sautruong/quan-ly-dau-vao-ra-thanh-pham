/* =====================================================================
   product_profile_ingredients.js — modal "Xem thành phần" (list.php)
   - Ô tìm sản phẩm: gõ keyword xổ dropdown, điều khiển bằng mũi tên
     lên/xuống, chọn bằng Enter hoặc Tab, Escape đóng (quy ước dropdown
     gợi ý dùng chung của dự án). Chọn được NHIỀU sản phẩm 1 lượt.
   - Bảng: Tên sản phẩm | Thành phần. Ô Thành phần sửa trực tiếp (hover
     hiện viền), rời ô là lưu (products.label_ingredients).
   - Nút xoay trong ô: bỏ bản sửa tay, dựng lại theo công thức sản xuất.
   - Xuất Excel: nút [data-export-excel] dùng chung (data_export_excel.js)
     đọc value của <input>/<textarea> trong ô nên xuất đúng bảng đang xem.
   Yêu cầu: jQuery (đã nạp ở list.php).
   ===================================================================== */
(function ($) {
    'use strict';

    var ACT = '?mod=product_profile&controllers=product_profile&action=';
    var $btn = $('#btn-label-ingredients');
    var $modal = $('#ingredients-modal');
    if (!$btn.length || !$modal.length) return;

    var $search = $('#ing-search');
    var $suggest = $('#ing-suggest');
    var $tbody = $('#ing-tbody');
    var chosen = {};      // product_id -> true (tránh thêm trùng)
    var activeIdx = -1;
    var searchTimer = null;

    function escHtml(s) {
        return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) {
            return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c];
        });
    }

    /* ---------------- Dropdown gợi ý sản phẩm ---------------- */
    function items() { return $suggest.find('.ing-suggest-item'); }

    function highlight(idx) {
        var els = items();
        els.removeClass('is-active');
        if (idx >= 0 && els.eq(idx).length) {
            els.eq(idx).addClass('is-active');
            els.get(idx).scrollIntoView({ block: 'nearest' });
        }
        activeIdx = idx;
    }

    function closeSuggest() {
        $suggest.empty().removeClass('is-open');
        activeIdx = -1;
    }

    function renderSuggest(list) {
        if (!list.length) {
            $suggest.html('<div class="ing-suggest-empty">Không tìm thấy sản phẩm</div>').addClass('is-open');
            activeIdx = -1;
            return;
        }
        $suggest.html(list.map(function (p) {
            var name = p.product_name || p.display_name || '';
            return '<div class="ing-suggest-item" data-id="' + p.id + '" data-name="' + escHtml(name) + '">'
                + escHtml(p.display_name || name)
                + (p.display_name && p.display_name !== name ? ' <span class="ing-suggest-sub">' + escHtml(name) + '</span>' : '')
                + '</div>';
        }).join('')).addClass('is-open');
        highlight(0); // sẵn sàng cho Enter/Tab ngay
    }

    function doSearch() {
        var kw = $search.val().trim();
        if (!kw) { closeSuggest(); return; }
        $.post(ACT + 'search_products_for_detail', { keyword: kw }, function (res) {
            renderSuggest((res && res.data) || []);
        }, 'json').fail(closeSuggest);
    }

    $search.on('input', function () {
        clearTimeout(searchTimer);
        searchTimer = setTimeout(doSearch, 200);
    });

    $search.on('keydown', function (e) {
        var els = items();
        if (e.key === 'ArrowDown') {
            e.preventDefault();
            if (!els.length) { doSearch(); return; }
            highlight(activeIdx + 1 >= els.length ? 0 : activeIdx + 1);
        } else if (e.key === 'ArrowUp') {
            e.preventDefault();
            if (!els.length) return;
            highlight(activeIdx - 1 < 0 ? els.length - 1 : activeIdx - 1);
        } else if (e.key === 'Enter' || e.key === 'Tab') {
            if (activeIdx < 0 || !els.eq(activeIdx).length) return; // Tab: cho nhảy focus như thường
            e.preventDefault();
            pick(els.eq(activeIdx));
        } else if (e.key === 'Escape') {
            closeSuggest();
        }
    });

    $suggest.on('mousedown', '.ing-suggest-item', function (e) {
        e.preventDefault();
        pick($(this));
    });

    // Chọn 1 sản phẩm -> thêm dòng vào bảng, ô tìm kiếm trống lại để chọn tiếp.
    function pick($item) {
        var id = parseInt($item.data('id'), 10);
        closeSuggest();
        $search.val('').focus();
        if (!id || chosen[id]) return;
        chosen[id] = true;
        addRow({ id: id, product_name: $item.data('name') || '', text: '', loading: true });
        loadRows([id], false);
    }

    /* ---------------- Bảng thành phần ---------------- */
    function rowHtml(it) {
        var badge = it.loading
            ? ''
            : (it.is_saved
                ? '<span class="ing-flag is-manual" title="Đang dùng bản đã sửa tay (đã lưu)">đã sửa tay</span>'
                : '<span class="ing-flag" title="Đang dựng theo công thức sản xuất">theo công thức</span>');
        return '<tr class="ing-row' + (it.is_saved ? ' is-manual' : '') + '" data-product-id="' + it.id + '">'
            + '<td class="ing-cell-name">'
            + '<input type="text" class="ing-pname" value="' + escHtml(it.product_name) + '" readonly>'
            + '<button type="button" class="ing-row-del" title="Bỏ sản phẩm này khỏi bảng">&times;</button>'
            + '</td>'
            + '<td class="ing-cell-text">'
            + '<textarea class="ing-text" rows="2"' + (it.loading ? ' disabled' : '')
            + ' placeholder="' + (it.loading ? 'Đang tải...' : 'Sản phẩm chưa có công thức sản xuất — có thể nhập tay thành phần...') + '">'
            + escHtml(it.text) + '</textarea>'
            + '<div class="ing-cell-tools">'
            + badge
            + '<button type="button" class="ing-regen" title="Lấy lại theo công thức sản xuất (bỏ bản sửa tay)">'
            + '<i class="fa-solid fa-rotate"></i></button>'
            + '</div>'
            + '</td>'
            + '</tr>';
    }

    // Sau khi dựng DOM: nhớ giá trị đang có làm mốc "đã lưu" (nếu không, chỉ cần click
    // vào ô rồi rời ra là ghi đè bản theo công thức thành bản sửa tay) + tự giãn cao ô.
    function mountRow(id) {
        var $ta = $tbody.find('.ing-row[data-product-id="' + id + '"] .ing-text');
        $ta.data('lastSaved', $ta.val());
        autoGrow($ta.get(0));
    }

    function addRow(it) {
        $tbody.find('.ing-empty-row').remove();
        $tbody.append(rowHtml(it));
        mountRow(it.id);
    }

    function fillRow(it) {
        var $row = $tbody.find('.ing-row[data-product-id="' + it.id + '"]');
        if (!$row.length) return;
        $row.replaceWith(rowHtml(it));
        mountRow(it.id);
    }

    // Nạp/nạp lại dữ liệu thành phần. regenerate = true -> bỏ bản sửa tay.
    function loadRows(ids, regenerate) {
        if (!ids.length) return;
        var data = { product_ids: ids };
        if (regenerate) data.regenerate = 1;
        $.post(ACT + 'ajax_label_ingredients', data, function (res) {
            ((res && res.items) || []).forEach(fillRow);
        }, 'json');
    }

    function autoGrow(el) {
        if (!el) return;
        el.style.height = 'auto';
        el.style.height = Math.max(el.scrollHeight, 42) + 'px';
    }

    function flash($cell, ok) {
        $cell.removeClass('is-saved-ok is-saved-err');
        void $cell.get(0).offsetWidth;
        $cell.addClass(ok ? 'is-saved-ok' : 'is-saved-err');
        setTimeout(function () { $cell.removeClass('is-saved-ok is-saved-err'); }, 700);
    }

    // Sửa trực tiếp -> lưu khi rời ô (chuỗi trống = quay về dựng theo công thức).
    $tbody.on('input', '.ing-text', function () { autoGrow(this); });
    $tbody.on('change blur', '.ing-text', function () {
        var $ta = $(this);
        if ($ta.data('lastSaved') === $ta.val()) return;
        $ta.data('lastSaved', $ta.val());
        var $row = $ta.closest('.ing-row');
        var pid = $row.data('product-id');
        var $cell = $ta.closest('.ing-cell-text');
        $.post(ACT + 'ajax_save_label_ingredients', { product_id: pid, text: $ta.val() }, function (res) {
            var ok = !!(res && res.success);
            flash($cell, ok);
            // Server trả về dòng đã chuẩn hóa (hoa/thường, hoặc quay về bản theo công thức
            // nếu chuỗi trùng/để trống) -> vẽ lại đúng những gì thực sự được lưu.
            if (ok && res.item) fillRow(res.item);
        }, 'json').fail(function () { flash($cell, false); });
    });

    // Lấy lại theo công thức sản xuất: xóa bản sửa tay rồi nạp lại.
    $tbody.on('click', '.ing-regen', function () {
        var $row = $(this).closest('.ing-row');
        var pid = $row.data('product-id');
        $.post(ACT + 'ajax_save_label_ingredients', { product_id: pid, text: '' }, function () {
            loadRows([pid], true);
        }, 'json');
    });

    $tbody.on('click', '.ing-row-del', function () {
        var $row = $(this).closest('.ing-row');
        delete chosen[$row.data('product-id')];
        $row.remove();
        if (!$tbody.find('.ing-row').length) {
            $tbody.html('<tr class="ing-empty-row"><td colspan="2" class="ing-empty">Chưa chọn sản phẩm nào.</td></tr>');
        }
    });

    /* ---------------- Mở modal ---------------- */
    $btn.on('click', function () {
        chosen = {};
        $tbody.html('<tr class="ing-empty-row"><td colspan="2" class="ing-empty">Chưa chọn sản phẩm nào.</td></tr>');
        $search.val('');
        closeSuggest();
        $modal.addClass('is-open');
        $('body').css('overflow', 'hidden');
        setTimeout(function () { $search.focus(); }, 60);
    });

    // Click ra ngoài ô tìm kiếm thì đóng dropdown (đóng/mở modal do product_profile_modal.js lo).
    $(document).on('mousedown', function (e) {
        if (!$(e.target).closest('#ing-picker').length) closeSuggest();
    });
})(jQuery);
