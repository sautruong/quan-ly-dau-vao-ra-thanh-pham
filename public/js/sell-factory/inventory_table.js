/*
 * inventory_table.js — bảng "Tồn thành phẩm nhà máy" (order_factory).
 * Chức năng client-side:
 *   - Lọc theo nhóm sản phẩm (#inv-group-select)
 *   - Lọc theo tên sản phẩm (icon fa-filter, popover giống cột admin_factory)
 *   - Số dòng hiển thị (#inv-rpp) + phân trang (#inv-pagination)
 * Dữ liệu đã render sẵn trong <tbody>; JS chỉ ẩn/hiện + phân trang tập kết quả.
 */
(function ($) {
    'use strict';

    var $table   = $('#inv-table');
    if (!$table.length) return;

    var $rows    = $('#inv-tbody .product-item');
    var $empty   = $('.inv-empty-row');
    var $rpp     = $('#inv-rpp');
    var $info    = $('#inv-rows-info');
    var $pager   = $('#inv-pagination');
    var $group   = $('#inv-group-select');
    var $pop     = $('#inv-filter-pop');
    var $popIn   = $('#inv-filter-search');
    var $popList = $('#inv-filter-list');

    var state = {
        page: 1,
        perPage: parseInt($rpp.val(), 10) || 10,
        categoryId: '',
        nameFilter: ''   // từ khóa: lọc sản phẩm có TÊN CHỨA từ khóa
    };

    function matches($tr) {
        if (state.categoryId && String($tr.data('category-id')) !== String(state.categoryId)) return false;
        if (state.nameFilter) {
            var nm = String($tr.data('product-name')).toLowerCase();
            if (nm.indexOf(state.nameFilter.toLowerCase()) === -1) return false;
        }
        return true;
    }

    function filtered() {
        return $rows.filter(function () { return matches($(this)); });
    }

    function render() {
        var $set = filtered();
        var total = $set.length;
        var totalPages = Math.max(1, Math.ceil(total / state.perPage));
        if (state.page > totalPages) state.page = totalPages;
        if (state.page < 1) state.page = 1;

        var start = (state.page - 1) * state.perPage;
        var end   = start + state.perPage;

        $rows.hide();
        $set.each(function (i) {
            if (i >= start && i < end) $(this).show();
        });

        $empty.toggle(total === 0);

        if (total === 0) $info.text('0 dòng');
        else $info.text((start + 1) + '–' + Math.min(end, total) + ' / ' + total + ' dòng');

        renderPager(totalPages);
    }

    function renderPager(totalPages) {
        var html = '';
        function btn(p, label, disabled, active) {
            return '<button class="inv-page-btn' + (active ? ' active' : '') + '"' +
                   (disabled ? ' disabled' : '') + ' data-page="' + p + '">' + label + '</button>';
        }
        html += btn(state.page - 1, '‹', state.page <= 1, false);
        for (var p = 1; p <= totalPages; p++) {
            if (p === 1 || p === totalPages || Math.abs(p - state.page) <= 2) {
                html += btn(p, String(p), false, p === state.page);
            } else if (Math.abs(p - state.page) === 3) {
                html += '<span class="inv-page-ellipsis">…</span>';
            }
        }
        html += btn(state.page + 1, '›', state.page >= totalPages, false);
        $pager.html(totalPages > 1 ? html : '');
    }

    /* ---------- Events ---------- */
    $rpp.on('change', function () {
        state.perPage = parseInt($(this).val(), 10) || 10;
        state.page = 1;
        render();
    });

    $group.on('change', function () {
        state.categoryId = $(this).val();
        state.page = 1;
        render();
    });

    $pager.on('click', '.inv-page-btn', function () {
        if ($(this).is('[disabled]')) return;
        var p = parseInt($(this).data('page'), 10);
        if (!isNaN(p)) { state.page = p; render(); }
    });

    /* ---------- Popover lọc theo tên sản phẩm ---------- */
    function buildNameList(keyword) {
        keyword = String(keyword || '').toLowerCase().trim();
        var seen = {};
        var names = [];
        filtered_by_group().each(function () {
            var n = String($(this).data('product-name'));
            if (seen[n]) return;
            if (keyword && n.toLowerCase().indexOf(keyword) === -1) return;
            seen[n] = true;
            names.push(n);
        });
        names.sort(function (a, b) { return a.localeCompare(b, 'vi'); });

        if (!names.length) {
            $popList.html('<div class="inv-filter-empty">Không tìm thấy</div>');
            return;
        }
        $popList.html(names.map(function (n) {
            var cls = (n === state.nameFilter) ? 'inv-filter-item selected' : 'inv-filter-item';
            return '<div class="' + cls + '" data-name="' + n.replace(/"/g, '&quot;') + '">' +
                   $('<div>').text(n).html() + '</div>';
        }).join(''));
    }

    // Danh sách tên chỉ trong nhóm đang chọn (để lọc tên kết hợp lọc nhóm).
    function filtered_by_group() {
        return $rows.filter(function () {
            if (state.categoryId && String($(this).data('category-id')) !== String(state.categoryId)) return false;
            return true;
        });
    }

    function openPop() {
        // Bỏ danh sách tên — chỉ còn ô từ khóa, gõ tới đâu lọc tới đó.
        if ($popList && $popList.length) $popList.hide();
        $popIn.val(state.nameFilter);
        $pop.show();
        $popIn.trigger('focus');
    }
    function closePop() { $pop.hide(); }

    $('#inv-name-filter').on('click', function (e) {
        e.stopPropagation();
        if ($pop.is(':visible')) closePop(); else openPop();
    });

    // Gõ từ khóa → lọc sản phẩm có tên chứa từ khóa và render ngay (không hiện list).
    $popIn.on('input', function () {
        state.nameFilter = String($(this).val()).trim();
        state.page = 1;
        $('#inv-name-filter').toggleClass('is-active', state.nameFilter !== '');
        render();
    });

    $('#inv-filter-clear').on('click', function () {
        state.nameFilter = '';
        state.page = 1;
        $('#inv-name-filter').removeClass('is-active');
        closePop();
        render();
    });

    // Đóng popover khi click ra ngoài
    $(document).on('click', function (e) {
        if (!$(e.target).closest('#inv-filter-pop, #inv-name-filter').length) closePop();
    });

    render();
})(jQuery);
