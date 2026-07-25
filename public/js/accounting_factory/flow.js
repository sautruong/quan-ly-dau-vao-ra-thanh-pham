/* =====================================================================
   KẾ TOÁN - NHÀ MÁY — Luồng NVL / sản phẩm (TASK 4)
   AJAX render tồn đầu/cuối + biến động; xuất PDF qua window.print().
   ===================================================================== */
(function () {
    'use strict';

    var CFG  = window.AF_CONFIG || { kind: 'material', baseUrl: '' };
    var KIND = CFG.kind === 'product' ? 'product' : 'material';

    var $kw      = document.getElementById('af-keyword');
    var $dd      = document.getElementById('af-dropdown');
    var $from    = document.getElementById('af-from');
    var $to      = document.getElementById('af-to');
    var $pdf     = document.getElementById('af-pdf');
    var $empty   = document.getElementById('af-empty');
    var $report  = document.getElementById('af-report');

    var selected = null; // {id, name, unit}
    var ddTimer  = null;

    function fmtNum(n) {
        n = Number(n) || 0;
        return (Math.round(n * 100) / 100).toLocaleString('en-US');
    }
    function fmtMoney(n) { return fmtNum(n) + ' đ'; }
    function fmtDate(s) {
        if (!s) return '';
        var d = String(s).replace('T', ' ').split('.')[0];
        var m = d.match(/^(\d{4})-(\d{2})-(\d{2})[ ]?(\d{2}:\d{2})?/);
        if (!m) return d;
        return m[3] + '/' + m[2] + '/' + m[1] + (m[4] ? ' ' + m[4] : '');
    }
    function post(action, data) {
        var fd = new FormData();
        Object.keys(data).forEach(function (k) { fd.append(k, data[k]); });
        return fetch(CFG.baseUrl + action, { method: 'POST', body: fd, credentials: 'same-origin' })
            .then(function (r) { return r.json(); });
    }
    function esc(s) {
        return String(s == null ? '' : s).replace(/[&<>"]/g, function (c) {
            return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;' }[c];
        });
    }

    /* ---- Tìm kiếm item ---- */
    function hideDd() { $dd.classList.remove('active'); $dd.innerHTML = ''; }
    function renderDd(items) {
        if (!items.length) { $dd.innerHTML = '<li class="empty">Không tìm thấy</li>'; $dd.classList.add('active'); return; }
        $dd.innerHTML = items.map(function (it) {
            return '<li data-id="' + it.id + '" data-name="' + esc(it.name) + '" data-unit="' + esc(it.unit || '') + '">'
                + esc(it.name) + (it.unit ? ' (' + esc(it.unit) + ')' : '') + '</li>';
        }).join('');
        $dd.classList.add('active');
    }
    $kw.addEventListener('input', function () {
        var kw = $kw.value.trim();
        selected = null;
        if (ddTimer) clearTimeout(ddTimer);
        if (kw === '') { hideDd(); return; }
        ddTimer = setTimeout(function () {
            post('search_item', { keyword: kw, kind: KIND }).then(function (res) {
                if (res && res.success) renderDd(res.data || []);
            });
        }, 220);
    });
    $dd.addEventListener('click', function (e) {
        var li = e.target.closest('li[data-id]');
        if (!li) return;
        selected = { id: parseInt(li.getAttribute('data-id'), 10), name: li.getAttribute('data-name'), unit: li.getAttribute('data-unit') };
        $kw.value = selected.name + (selected.unit ? ' (' + selected.unit + ')' : '');
        hideDd();
        load();
    });
    document.addEventListener('click', function (e) {
        if (!$dd.contains(e.target) && e.target !== $kw) hideDd();
    });
    $from.addEventListener('change', load);
    $to.addEventListener('change', load);

    /* ---- Render báo cáo ---- */
    function rowsHtml(list, cols) {
        if (!list.length) {
            return '<tr class="empty"><td colspan="' + cols + '">Không có phát sinh</td></tr>';
        }
        return null; // caller builds
    }

    // Render 1 bảng 3 cột (ngày, SL, giá trị) + footer tổng.
    function fillTable(tbodyId, qtyId, valId, sec) {
        var rows = (sec && sec.rows) || [];
        document.getElementById(tbodyId).innerHTML = rows.length ? rows.map(function (r) {
            return '<tr><td class="l">' + fmtDate(r.date) + '</td><td>' + fmtNum(r.qty) + '</td><td>' + fmtMoney(r.value) + '</td></tr>';
        }).join('') : '<tr class="empty"><td colspan="3">Không có phát sinh</td></tr>';
        document.getElementById(qtyId).textContent = fmtNum(sec ? sec.sum_qty : 0);
        document.getElementById(valId).textContent = fmtMoney(sec ? sec.sum_val : 0);
    }

    function renderMaterial(d) {
        fillTable('af-nhap-kho',  'af-nk-qty',  'af-nk-val',  d.nhap_kho);
        fillTable('af-nhap-khac', 'af-nkk-qty', 'af-nkk-val', d.nhap_khac);
        fillTable('af-xuat-sx',   'af-xsx-qty', 'af-xsx-val', d.xuat_sx);
    }

    function renderProduct(d) {
        fillTable('af-nhap-sx',  'af-sx-qty',  'af-sx-val',  d.nhap_sx);
        fillTable('af-nhap-khac','af-nkk-qty', 'af-nkk-val', d.nhap_khac);
        fillTable('af-ban-tra',  'af-bt-qty',  'af-bt-val',  d.ban_tra);
        fillTable('af-xuat-ban', 'af-xb-qty',  'af-xb-val',  d.xuat_ban);
    }

    function load() {
        if (!selected || !selected.id) { return; }
        var from = $from.value, to = $to.value;
        if (!from || !to) return;
        var action = KIND === 'product' ? 'product_flow_data' : 'material_flow_data';
        var payload = { from: from, to: to };
        payload[KIND === 'product' ? 'product_id' : 'material_id'] = selected.id;

        post(action, payload).then(function (res) {
            if (!res || !res.success) { alert((res && res.message) || 'Không tải được dữ liệu.'); return; }
            var d = res.data;
            document.getElementById('af-meta').textContent =
                (KIND === 'product' ? 'Sản phẩm: ' : 'Nguyên vật liệu: ') + selected.name
                + (selected.unit ? ' (' + selected.unit + ')' : '')
                + '  —  từ ' + fmtDate(from) + ' đến ' + fmtDate(to);
            document.getElementById('af-from-lbl').textContent = fmtDate(from);
            document.getElementById('af-to-lbl').textContent = fmtDate(to);
            document.getElementById('af-opening').textContent = fmtNum(d.opening) + (selected.unit ? ' ' + selected.unit : '');
            document.getElementById('af-closing').textContent = fmtNum(d.closing) + (selected.unit ? ' ' + selected.unit : '');

            if (KIND === 'product') renderProduct(d); else renderMaterial(d);

            $empty.style.display = 'none';
            $report.classList.add('show');
            $pdf.disabled = false;
        });
    }

    $pdf.addEventListener('click', function () {
        if ($pdf.disabled) return;
        window.print();
    });
})();
