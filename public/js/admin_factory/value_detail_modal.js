/*
 * value_detail_modal.js
 * "Con mắt" giải thích giá trị 1 dòng trong purchase_order & sales_orders.
 *  - Hover ô .td-value-detail -> hiện icon mắt (đã render sẵn .vd-eye, CSS lo ẩn/hiện).
 *  - Click .vd-eye -> AJAX lấy dữ liệu nguồn -> show modal bảng giải thích giá trị.
 *  Phân loại theo data-value-detail = 'purchase' | 'sales'.
 */
(function () {
    'use strict';

    var BASE = '?mod=admin_factory&controllers=admin&action=';
    var mask = null;

    function fmt(n) {
        n = Number(n) || 0;
        return n.toLocaleString('vi-VN', { maximumFractionDigits: 0 });
    }
    function esc(s) {
        return String(s == null ? '' : s)
            .replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;');
    }

    function ensureMask() {
        if (mask) return mask;
        mask = document.createElement('div');
        mask.className = 'vd-mask';
        mask.innerHTML =
            '<div class="vd-box">' +
              '<div class="vd-head"><h3></h3><span class="vd-close" title="Đóng">&times;</span></div>' +
              '<div class="vd-meta"></div>' +
              '<div class="vd-body"></div>' +
            '</div>';
        document.body.appendChild(mask);
        mask.addEventListener('click', function (e) {
            if (e.target === mask || e.target.classList.contains('vd-close')) close();
        });
        document.addEventListener('keydown', function (e) {
            if (e.key === 'Escape') close();
        });
        return mask;
    }
    function open() { ensureMask().classList.add('open'); }
    function close() { if (mask) mask.classList.remove('open'); }

    function setContent(title, metaHtml, bodyHtml) {
        ensureMask();
        mask.querySelector('.vd-head h3').textContent = title;
        mask.querySelector('.vd-meta').innerHTML = metaHtml;
        mask.querySelector('.vd-body').innerHTML = bodyHtml;
    }

    /* ---------- render PURCHASE ---------- */
    function renderPurchase(d) {
        var meta =
            '<span><b>Nhà cung cấp:</b> ' + esc(d.supplier_name || '—') + '</span>' +
            '<span><b>Giá trị nhập kho:</b> ' + fmt(d.value) + '</span>' +
            '<span><b>Chi phí mua hàng:</b> ' + fmt(d.cost) + '</span>';

        var rows = '', totQty = 0, totAmount = 0, totCost = 0, totTotal = 0;
        if (!d.lines || !d.lines.length) {
            rows = '<tr><td colspan="5" class="vd-empty">Dòng này chưa có dữ liệu nhập chi tiết trên hệ thống.</td></tr>';
        } else {
            d.lines.forEach(function (l) {
                totQty += Number(l.quantity) || 0;
                totAmount += Number(l.amount) || 0;
                totCost += Number(l.cost_sum) || 0;
                totTotal += Number(l.total) || 0;
                var costHtml = '';
                (l.costs || []).forEach(function (c) {
                    costHtml += '<span class="vd-cost">' + esc(c.description) + ': ' + fmt(c.price) + '</span>';
                });
                rows +=
                    '<tr>' +
                      '<td>' + esc(l.name) + ' <span class="vd-cost">(' + esc(l.kind) + ')</span></td>' +
                      '<td class="num">' + fmt(l.quantity) + '</td>' +
                      '<td class="num">' + fmt(l.unit_price) + '</td>' +
                      '<td class="num">' + fmt(l.amount) + (costHtml ? '<br>' + costHtml : '') + '</td>' +
                      '<td class="num">' + fmt(l.total) + '</td>' +
                    '</tr>';
            });
        }
        var body =
            '<table class="vd-table"><thead><tr>' +
              '<th>Hàng hóa</th><th>Số lượng</th><th>Đơn giá</th>' +
              '<th>Thành tiền (+CPMH)</th><th>Tổng giá trị</th>' +
            '</tr></thead><tbody>' + rows + '</tbody>' +
            '<tfoot><tr>' +
              '<td>Tổng cộng</td>' +
              '<td class="num">' + fmt(totQty) + '</td>' +
              '<td class="num">—</td>' +
              '<td class="num">' + fmt(totAmount) + ' (+' + fmt(totCost) + ')</td>' +
              '<td class="num">' + fmt(totTotal) + '</td>' +
            '</tr></tfoot></table>';

        setContent('Dữ liệu cấu thành "Giá trị nhập kho"', meta, body);
        open();
    }

    /* ---------- render SALES ---------- */
    function renderSales(d, displayValue) {
        var lines = (d && d.lines) || [];
        var meta = '<span><b>Giá trị hàng hóa:</b> ' + fmt(displayValue) + '</span>';
        var rows = '', totQty = 0, totTotal = 0;
        if (!lines.length) {
            rows = '<tr><td colspan="4" class="vd-empty">Không tìm thấy chi tiết xuất kho cho dòng này (có thể là dữ liệu nhập tay/lịch sử).</td></tr>';
        } else {
            lines.forEach(function (l) {
                totQty += Number(l.quantity) || 0;
                totTotal += Number(l.total) || 0;
                rows +=
                    '<tr>' +
                      '<td>' + esc(l.name) + ' <span class="vd-cost">(' + esc(l.kind) + ')</span></td>' +
                      '<td class="num">' + fmt(l.quantity) + '</td>' +
                      '<td class="num">' + fmt(l.unit_price) + '</td>' +
                      '<td class="num">' + fmt(l.total) + '</td>' +
                    '</tr>';
            });
        }
        var body =
            '<table class="vd-table"><thead><tr>' +
              '<th>Hàng hóa</th><th>Số lượng</th><th>Đơn giá</th><th>Thành tiền</th>' +
            '</tr></thead><tbody>' + rows + '</tbody>' +
            (lines.length
                ? '<tfoot><tr><td>Tổng cộng</td><td class="num">' + fmt(totQty) +
                  '</td><td class="num">—</td><td class="num">' + fmt(totTotal) + '</td></tr></tfoot>'
                : '') +
            '</table>';
        setContent('Dữ liệu cấu thành "Giá trị hàng hóa"', meta, body);
        open();
    }

    function showLoading(title) {
        setContent(title, '', '<div class="vd-loading">Đang tải dữ liệu…</div>');
        open();
    }

    /* ---------- click handler (delegated) ---------- */
    document.addEventListener('click', function (e) {
        var eye = e.target.closest ? e.target.closest('.vd-eye') : null;
        if (!eye) return;
        var td = eye.closest('.td-value-detail');
        if (!td) return;
        var type = td.getAttribute('data-value-detail');

        if (type === 'purchase') {
            var wrId = td.getAttribute('data-wr-id');
            showLoading('Dữ liệu cấu thành "Giá trị nhập kho"');
            fetch(BASE + 'purchase_value_detail&wr_id=' + encodeURIComponent(wrId))
                .then(function (r) { return r.json(); })
                .then(function (res) {
                    if (!res.ok) { setContent('Thông báo', '', '<div class="vd-empty">' + esc(res.message || 'Lỗi tải dữ liệu.') + '</div>'); return; }
                    renderPurchase(res.data);
                })
                .catch(function () { setContent('Lỗi', '', '<div class="vd-empty">Lỗi mạng/Server.</div>'); });
        } else if (type === 'sales') {
            var cid = td.getAttribute('data-customer-id') || 0;
            var ca  = td.getAttribute('data-created-at') || '';
            var dv  = td.getAttribute('data-value') || 0;
            showLoading('Dữ liệu cấu thành "Giá trị hàng hóa"');
            fetch(BASE + 'sales_value_detail&customer_id=' + encodeURIComponent(cid) +
                  '&created_at=' + encodeURIComponent(ca))
                .then(function (r) { return r.json(); })
                .then(function (res) {
                    if (!res.ok) { setContent('Thông báo', '', '<div class="vd-empty">' + esc(res.message || 'Lỗi tải dữ liệu.') + '</div>'); return; }
                    renderSales(res.data, dv);
                })
                .catch(function () { setContent('Lỗi', '', '<div class="vd-empty">Lỗi mạng/Server.</div>'); });
        }
    });
})();
