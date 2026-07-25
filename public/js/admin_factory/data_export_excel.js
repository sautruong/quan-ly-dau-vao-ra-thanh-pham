(function () {
    // Xuất bảng dữ liệu đang hiển thị ra file Excel (.xls).
    // Lấy đúng dữ liệu trên giao diện: header từ <thead>, dữ liệu từ tất cả <tr>
    // trong <tbody> (bỏ qua phân trang client-side: export TOÀN BỘ dòng đang lọc,
    // không chỉ trang hiện tại). Không phụ thuộc thư viện ngoài.

    function cellText(cell) {
        // Ô có input/select/checkbox (bảng quản lý sửa trực tiếp) → lấy giá trị field.
        var field = cell.querySelector('input, select, textarea');
        if (field) {
            if (field.tagName === 'SELECT') {
                var opt = field.options[field.selectedIndex];
                return opt ? opt.text.replace(/\s+/g, ' ').trim() : '';
            }
            if (field.type === 'checkbox') return field.checked ? 'x' : '';
            if (field.type === 'file' || field.type === 'button' || field.type === 'hidden') {
                // bỏ qua field thao tác → dùng text hiển thị còn lại
                return (cell.textContent || '').replace(/\s+/g, ' ').trim();
            }
            return (field.value || '').replace(/\s+/g, ' ').trim();
        }
        return (cell.textContent || '').replace(/\s+/g, ' ').trim();
    }

    function tableToHtml(table) {
        var rowsHtml = [];

        // Header
        var headCells = table.querySelectorAll('thead th');
        if (headCells.length) {
            var ths = [];
            for (var h = 0; h < headCells.length; h++) {
                ths.push('<th>' + escapeHtml(cellText(headCells[h])) + '</th>');
            }
            rowsHtml.push('<tr>' + ths.join('') + '</tr>');
        }

        // Body — toàn bộ dòng, bỏ dòng "trống" (empty-row)
        var bodyRows = table.querySelectorAll('tbody tr');
        for (var i = 0; i < bodyRows.length; i++) {
            var tr = bodyRows[i];
            if (tr.classList.contains('empty-row')) continue;
            var cells = tr.querySelectorAll('td');
            if (!cells.length) continue;
            var tds = [];
            for (var c = 0; c < cells.length; c++) {
                tds.push('<td>' + escapeHtml(cellText(cells[c])) + '</td>');
            }
            rowsHtml.push('<tr>' + tds.join('') + '</tr>');
        }

        return '<table border="1">' + rowsHtml.join('') + '</table>';
    }

    function escapeHtml(s) {
        return String(s)
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;');
    }

    function buildFileName() {
        // Ưu tiên tên tab đang active để file dễ nhận biết.
        var active = document.querySelector('.main-tab .active a, .main-tab .tab-active a');
        var base = active ? active.textContent.trim() : (document.title || 'du_lieu');
        // Gắn khoảng ngày nếu có trên form lọc.
        var from = document.querySelector('.data-filter [name="from"]');
        var to   = document.querySelector('.data-filter [name="to"]');
        var range = '';
        if (from && from.value) range += '_tu_' + from.value;
        if (to && to.value)     range += '_den_' + to.value;
        var name = (base + range).replace(/[\\/:*?"<>|]+/g, '_').replace(/\s+/g, '_');
        return name + '.xls';
    }

    function exportTable(table) {
        if (!table) return;
        var inner = tableToHtml(table);
        var html =
            '<html xmlns:o="urn:schemas-microsoft-com:office:office" ' +
            'xmlns:x="urn:schemas-microsoft-com:office:excel" ' +
            'xmlns="http://www.w3.org/TR/REC-html40">' +
            '<head><meta charset="UTF-8"></head><body>' + inner + '</body></html>';

        // BOM để Excel đọc đúng UTF-8 (tiếng Việt).
        var blob = new Blob(['﻿', html], { type: 'application/vnd.ms-excel;charset=utf-8' });
        var url  = URL.createObjectURL(blob);
        var a    = document.createElement('a');
        a.href = url;
        a.download = buildFileName();
        document.body.appendChild(a);
        a.click();
        document.body.removeChild(a);
        setTimeout(function () { URL.revokeObjectURL(url); }, 0);
    }

    document.addEventListener('click', function (e) {
        var btn = e.target.closest && e.target.closest('[data-export-excel]');
        if (!btn) return;
        e.preventDefault();
        // Bảng cùng wrapper với nút, hoặc bảng dữ liệu duy nhất trên trang.
        var wrap  = btn.closest('.content') || document;
        // Ưu tiên bảng nút chỉ định (data-export-target), rồi bảng phân trang,
        // cuối cùng fallback bảng đầu tiên trong vùng .content.
        var sel   = btn.getAttribute('data-export-target');
        var table = (sel && (wrap.querySelector(sel) || document.querySelector(sel))) ||
                    wrap.querySelector('table[data-paginate]') ||
                    document.querySelector('table[data-paginate]') ||
                    wrap.querySelector('table');
        exportTable(table);
    });
})();
