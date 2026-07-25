(function () {
    // Pagination client-side dùng chung cho 5 view dữ liệu read-only.
    // Mỗi <table data-paginate> được bọc trong 1 wrapper có:
    //   <select data-rows-per-page>
    //   <div data-pagination>
    //   <span data-rows-info>   (optional — hiển thị "1–25 / 234 dòng")
    // Tất cả <tr> dữ liệu được render sẵn từ server; JS chỉ ẩn/hiện theo trang.

    function initTable(table) {
        var tbody = table.querySelector('tbody');
        if (!tbody) return;
        var allRows = Array.prototype.slice.call(tbody.querySelectorAll('tr'));
        if (allRows.length === 0) return;

        var wrap   = table.closest('[data-paginate-wrap]') || table.parentElement;
        var select = wrap.querySelector('[data-rows-per-page]');
        var pager  = wrap.querySelector('[data-pagination]');
        var info   = wrap.querySelector('[data-rows-info]');
        if (!select || !pager) return;

        var perPage = parseInt(select.value, 10) || 25;
        var page    = 1;

        function render() {
            var total      = allRows.length;
            var totalPages = Math.max(1, Math.ceil(total / perPage));
            if (page > totalPages) page = totalPages;
            if (page < 1) page = 1;
            var start = (page - 1) * perPage;
            var end   = start + perPage;
            for (var i = 0; i < allRows.length; i++) {
                allRows[i].style.display = (i >= start && i < end) ? '' : 'none';
            }
            renderPager(totalPages);
            if (info) {
                if (total === 0) info.textContent = '0 dòng';
                else info.textContent = (start + 1) + '–' + Math.min(end, total) + ' / ' + total + ' dòng';
            }
        }

        function renderPager(totalPages) {
            var buttons = [];
            buttons.push(btn(page - 1, '‹', page <= 1, false, 'nav-btn'));
            for (var p = 1; p <= totalPages; p++) {
                if (p === 1 || p === totalPages || Math.abs(p - page) <= 2) {
                    buttons.push(btn(p, String(p), false, p === page));
                } else if (Math.abs(p - page) === 3) {
                    buttons.push('<span class="page-ellipsis">…</span>');
                }
            }
            buttons.push(btn(page + 1, '›', page >= totalPages, false, 'nav-btn'));
            pager.innerHTML = buttons.join('');
        }

        function btn(p, label, disabled, active, extraCls) {
            var cls = 'page-btn' + (active ? ' active' : '') + (extraCls ? ' ' + extraCls : '');
            var attrs = disabled ? ' disabled' : '';
            return '<button class="' + cls + '" data-page="' + p + '"' + attrs + '>' + label + '</button>';
        }

        select.addEventListener('change', function () {
            perPage = parseInt(select.value, 10) || 25;
            page = 1;
            render();
        });
        pager.addEventListener('click', function (e) {
            var b = e.target.closest && e.target.closest('.page-btn');
            if (!b || b.hasAttribute('disabled')) return;
            var p = parseInt(b.getAttribute('data-page'), 10);
            if (!isNaN(p)) { page = p; render(); }
        });

        render();
    }

    document.addEventListener('DOMContentLoaded', function () {
        var tables = document.querySelectorAll('table[data-paginate]');
        for (var i = 0; i < tables.length; i++) initTable(tables[i]);
    });
})();
