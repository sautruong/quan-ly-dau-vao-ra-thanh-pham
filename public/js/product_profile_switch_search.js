/* ============================================================
   product_profile_switch_search.js — ô "Tìm sản phẩm khác để xem" ở
   trang Chi tiết sản phẩm (detail.php). Điều khiển bàn phím đầy đủ
   (mũi tên lên/xuống, Tab/Enter chọn, Escape đóng) theo mẫu wirePicker()
   trong app_shell.js — xem memory dropdown-keyboard-nav-default.
   Chọn xong -> điều hướng sang trang Chi tiết của sản phẩm vừa chọn.
   ============================================================ */
$(function () {
    var ACT = '?mod=product_profile&controllers=product_profile&action=';
    var $input = $('#pp-switch-input');
    var $dropdown = $('#pp-switch-dropdown');
    if (!$input.length) return;

    var timer = null, activeIdx = -1;

    function items() { return $dropdown.find('li[data-id]'); }
    function highlight(idx) {
        var $items = items();
        $items.removeClass('is-active');
        if (idx >= 0 && $items.eq(idx).length) {
            $items.eq(idx).addClass('is-active');
            $items.eq(idx)[0].scrollIntoView({ block: 'nearest' });
        }
    }
    function closeDropdown() {
        $dropdown.empty().removeClass('is-open');
        activeIdx = -1;
    }
    function pickItem($li) {
        if (!$li || !$li.length) return;
        var id = $li.attr('data-id');
        if (!id) return;
        window.location.href = ACT + 'product_detail&id=' + id;
    }

    $input.on('input', function () {
        activeIdx = -1;
        var kw = $input.val().trim();
        clearTimeout(timer);
        if (kw.length < 1) { closeDropdown(); return; }
        timer = setTimeout(function () {
            $.post(ACT + 'search_products_for_detail', { keyword: kw }, function (res) {
                var list = (res && res.data) || [];
                $dropdown.empty();
                if (list.length) {
                    list.forEach(function (it) {
                        $('<li></li>').attr('data-id', it.id).text(it.display_name).appendTo($dropdown);
                    });
                } else {
                    $('<li class="no-result"></li>').text('Không tìm thấy sản phẩm').appendTo($dropdown);
                }
                $dropdown.addClass('is-open');
                activeIdx = -1;
            }, 'json');
        }, 250);
    });

    $input.on('keydown', function (e) {
        if (!$dropdown.hasClass('is-open')) return;
        var $items = items();
        if (!$items.length) return;
        if (e.key === 'ArrowDown') {
            e.preventDefault();
            activeIdx = (activeIdx + 1) % $items.length;
            highlight(activeIdx);
        } else if (e.key === 'ArrowUp') {
            e.preventDefault();
            activeIdx = (activeIdx - 1 + $items.length) % $items.length;
            highlight(activeIdx);
        } else if (e.key === 'Enter') {
            if (activeIdx >= 0) { e.preventDefault(); pickItem($items.eq(activeIdx)); }
        } else if (e.key === 'Tab') {
            // Không preventDefault: chọn dòng đang tô sáng rồi vẫn cho Tab chuyển focus tiếp.
            if (activeIdx >= 0) pickItem($items.eq(activeIdx));
        } else if (e.key === 'Escape') {
            closeDropdown();
        }
    });

    $dropdown.on('click', 'li[data-id]', function (e) {
        e.stopPropagation();
        pickItem($(this));
    });

    $(document).on('click', function (e) {
        if (!$(e.target).closest('.pp-switch-search').length) closeDropdown();
    });
});
