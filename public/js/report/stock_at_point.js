(function () {
    'use strict';

    var CFG = window.REPORT_CFG || { baseUrl: '?mod=report&controllers=report&action=' };

    // ---------- State ----------
    var mode = 'end';                 // 'start' | 'end'
    var data = { products: [], materials: [] };
    var lastLabel = '';

    // ---------- DOM ----------
    var $date    = document.getElementById('sap-date');
    var $btnStart= document.getElementById('sap-btn-start');
    var $btnEnd  = document.getElementById('sap-btn-end');
    var $status  = document.getElementById('sap-status');
    var $pBody   = document.querySelector('#sap-table-product tbody');
    var $mBody   = document.querySelector('#sap-table-material tbody');
    var $pSearch = document.getElementById('sap-search-product');
    var $mSearch = document.getElementById('sap-search-material');

    // ---------- Helpers ----------
    function postForm(action, payload) {
        var body = new URLSearchParams();
        Object.keys(payload || {}).forEach(function (k) { body.append(k, payload[k]); });
        return fetch(CFG.baseUrl + action, {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body: body.toString()
        }).then(function (r) { return r.json(); });
    }

    function escapeHtml(s) {
        return String(s == null ? '' : s)
            .replace(/&/g, '&amp;').replace(/</g, '&lt;')
            .replace(/>/g, '&gt;').replace(/"/g, '&quot;').replace(/'/g, '&#39;');
    }

    function fmtQty(v) {
        var n = Number(v) || 0;
        if (Math.abs(n - Math.round(n)) < 1e-9) return String(Math.round(n));
        return String(parseFloat(n.toFixed(3)));
    }

    // ---------- Render ----------
    function rowsHtml(list, kind) {
        if (!list.length) {
            return '<tr class="empty-row"><td colspan="5">Không có dữ liệu.</td></tr>';
        }
        return list.map(function (it, i) {
            var mid = kind === 'product' ? it.category : it.supplier;
            var neg = Number(it.quantity) < 0 ? ' class="num neg"' : ' class="num"';
            return '<tr>' +
                '<td>' + (i + 1) + '</td>' +
                '<td>' + escapeHtml(it.name) + '</td>' +
                '<td>' + escapeHtml(mid) + '</td>' +
                '<td>' + escapeHtml(it.unit) + '</td>' +
                '<td' + neg + '>' + fmtQty(it.quantity) + '</td>' +
            '</tr>';
        }).join('');
    }

    function render() {
        $pBody.innerHTML = rowsHtml(data.products, 'product');
        $mBody.innerHTML = rowsHtml(data.materials, 'material');
        applyFilter($pSearch, $pBody);
        applyFilter($mSearch, $mBody);
    }

    function setActiveBtn() {
        $btnStart.classList.toggle('active', mode === 'start');
        $btnEnd.classList.toggle('active', mode === 'end');
    }

    // ---------- Load ----------
    function load() {
        var date = ($date && $date.value) || '';
        setActiveBtn();
        $status.textContent = 'Đang tải…';
        postForm('stock_at_point_data', { date: date, mode: mode }).then(function (res) {
            if (!res || !res.success) {
                $status.textContent = 'Lỗi tải dữ liệu.';
                return;
            }
            data.products  = res.products  || [];
            data.materials = res.materials || [];
            lastLabel = res.label || '';
            $status.textContent = 'Tồn lúc: ' + lastLabel +
                '  ·  ' + data.products.length + ' thành phẩm, ' + data.materials.length + ' NVL';
            render();
        }).catch(function () {
            $status.textContent = 'Lỗi kết nối.';
        });
    }

    // ---------- Client-side filter (lọc theo tên) ----------
    function applyFilter($input, $body) {
        if (!$input) return;
        var kw = $input.value.trim().toLowerCase();
        $body.querySelectorAll('tr').forEach(function (tr) {
            if (tr.classList.contains('empty-row')) return;
            var name = (tr.children[1] ? tr.children[1].textContent : '').toLowerCase();
            tr.style.display = (kw === '' || name.indexOf(kw) !== -1) ? '' : 'none';
        });
    }

    // ---------- Export Excel (.xls) ----------
    function exportXls(target, scope) {
        var list = (target === 'product' ? data.products : data.materials).slice();
        if (scope === 'instock') list = list.filter(function (x) { return Number(x.quantity) > 0; });

        var isProduct = target === 'product';
        var groupHead = isProduct ? 'Danh mục' : 'Nhà cung cấp';
        var nameHead  = isProduct ? 'Thành phẩm' : 'Nguyên vật liệu';

        var head = '<tr>' +
            '<th>STT</th><th>' + nameHead + '</th><th>' + groupHead + '</th>' +
            '<th>ĐVT</th><th>Tồn</th></tr>';
        var body = list.map(function (it, i) {
            var g = isProduct ? it.category : it.supplier;
            return '<tr>' +
                '<td>' + (i + 1) + '</td>' +
                '<td>' + escapeHtml(it.name) + '</td>' +
                '<td>' + escapeHtml(g) + '</td>' +
                '<td>' + escapeHtml(it.unit) + '</td>' +
                '<td>' + fmtQty(it.quantity) + '</td>' +
            '</tr>';
        }).join('');

        var title = (isProduct ? 'Tồn thành phẩm' : 'Tồn NVL') + ' - ' + lastLabel +
            (scope === 'instock' ? ' (chỉ có tồn)' : ' (toàn bộ)');
        var caption = '<tr><td colspan="5"><b>' + escapeHtml(title) + '</b></td></tr>';

        var inner = '<table border="1">' + caption + head + body + '</table>';
        var html =
            '<html xmlns:o="urn:schemas-microsoft-com:office:office" ' +
            'xmlns:x="urn:schemas-microsoft-com:office:excel" ' +
            'xmlns="http://www.w3.org/TR/REC-html40">' +
            '<head><meta charset="UTF-8"></head><body>' + inner + '</body></html>';

        var blob = new Blob(['﻿', html], { type: 'application/vnd.ms-excel;charset=utf-8' });
        var url  = URL.createObjectURL(blob);
        var a    = document.createElement('a');
        a.href = url;
        a.download = title.replace(/[\\/:*?"<>|]+/g, '_').replace(/\s+/g, '_') + '.xls';
        document.body.appendChild(a);
        a.click();
        document.body.removeChild(a);
        setTimeout(function () { URL.revokeObjectURL(url); }, 0);
    }

    // ---------- Wire-up ----------
    function init() {
        $btnStart.addEventListener('click', function () { mode = 'start'; load(); });
        $btnEnd.addEventListener('click',   function () { mode = 'end';   load(); });
        if ($date) $date.addEventListener('change', load);

        if ($pSearch) $pSearch.addEventListener('input', function () { applyFilter($pSearch, $pBody); });
        if ($mSearch) $mSearch.addEventListener('input', function () { applyFilter($mSearch, $mBody); });

        document.addEventListener('click', function (e) {
            var btn = e.target.closest && e.target.closest('.sap-export');
            if (!btn) return;
            e.preventDefault();
            if (!data.products.length && !data.materials.length) { alert('Chưa có dữ liệu để xuất.'); return; }
            exportXls(btn.getAttribute('data-target'), btn.getAttribute('data-scope'));
        });

        load(); // auto: cuối ngày hôm nay
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }
})();
