(function () {
    // "Check Database" — modal kiểm tra dữ liệu các bảng bị tác động bởi 1 view.
    // Cách dùng: 1 nút có
    //   <button class="btn-check-db" data-tables="t1,t2,...">Check Database</button>
    // Phân trang THẬT ở server (10 dòng/trang, đi hết toàn bộ bảng — không giới hạn
    // 50 dòng như trước). Một số bảng có Ô LỌC (icon cạnh tiêu đề) theo đúng 1 cột đã
    // cấu hình ở server (cdb_filter_target_map() trong libraries/check_database.php).
    // Cột FK (*_id) đã được server chuyển hóa sang tên ở bảng chính.

    var styleInjected = false;
    var backdrop      = null;

    function injectStyle() {
        if (styleInjected) return;
        styleInjected = true;
        var css =
            '.cdb-backdrop{position:fixed;inset:0;background:rgba(0,0,0,.45);display:none;' +
            'align-items:flex-start;justify-content:center;z-index:2000;padding:40px 0;overflow-y:auto;}' +
            '.cdb-backdrop.show{display:flex;}' +
            '.cdb-modal{background:#fff;border-radius:8px;width:1000px;max-width:95vw;' +
            'box-shadow:0 14px 40px rgba(0,0,0,.25);}' +
            '.cdb-header{padding:12px 16px;border-bottom:1px solid #e1e4e8;font-weight:600;font-size:15px;' +
            'display:flex;justify-content:space-between;align-items:center;position:sticky;top:0;background:#fff;border-radius:8px 8px 0 0;}' +
            '.cdb-close{cursor:pointer;border:0;background:none;font-size:22px;line-height:1;color:#888;}' +
            '.cdb-body{padding:14px 16px;max-height:75vh;overflow-y:auto;}' +
            '.cdb-section{margin-bottom:22px;}' +
            '.cdb-section-head{display:flex;align-items:center;gap:6px;margin:0 0 8px;}' +
            '.cdb-section h3{margin:0;font-size:14px;color:#096926;border-left:4px solid #096926;padding-left:8px;}' +
            '.cdb-filter-btn{border:1px solid #c2c8d0;background:#fff;color:#666;width:22px;height:22px;' +
            'border-radius:5px;cursor:pointer;font-size:11px;display:inline-flex;align-items:center;justify-content:center;flex:0 0 auto;}' +
            '.cdb-filter-btn:hover{color:#096926;border-color:#096926;background:#eef6f0;}' +
            '.cdb-filter-btn.is-active{color:#fff;background:#096926;border-color:#096926;}' +
            '.cdb-filter-wrap{position:relative;flex:0 0 auto;display:flex;align-items:center;gap:6px;}' +
            '.cdb-filter-input{display:none;width:180px;padding:4px 8px;border:1px solid #096926;border-radius:5px;font-size:12px;}' +
            '.cdb-filter-wrap.open .cdb-filter-input{display:inline-block;}' +
            '.cdb-table{width:100%;border-collapse:collapse;font-size:12px;}' +
            '.cdb-table th,.cdb-table td{border:1px solid #d8dee6;padding:5px 7px;text-align:left;white-space:nowrap;}' +
            '.cdb-table thead th{background:#f0f3f7;font-weight:600;position:sticky;top:0;}' +
            '.cdb-scroll{overflow-x:auto;border:1px solid #eef0f3;border-radius:6px;position:relative;}' +
            '.cdb-scroll.is-loading{opacity:.5;pointer-events:none;}' +
            '.cdb-pager{margin-top:8px;display:flex;gap:4px;flex-wrap:wrap;align-items:center;}' +
            '.cdb-pager .pg{min-width:28px;padding:4px 8px;border:1px solid #c2c8d0;background:#fff;border-radius:4px;cursor:pointer;font-size:12px;}' +
            '.cdb-pager .pg.active{background:#096926;color:#fff;border-color:#096926;}' +
            '.cdb-pager .pg[disabled]{opacity:.4;cursor:not-allowed;}' +
            '.cdb-pager .pg-ellipsis{padding:4px 4px;color:#999;font-size:12px;}' +
            '.cdb-empty{padding:10px;color:#999;font-style:italic;font-size:12px;}' +
            '.cdb-err{padding:10px;color:#b22;font-size:12px;}' +
            '.cdb-count{font-size:12px;color:#666;margin-left:auto;}' +
            '.btn-check-db{display:inline-flex;align-items:center;gap:6px;background:#0d6efd;color:#fff;' +
            'border:0;padding:7px 14px;border-radius:6px;cursor:pointer;font-size:13px;font-weight:600;}' +
            '.btn-check-db:hover{background:#0b5ed7;}' +
            '.cdb-actions{padding:8px 20px;}';
        var s = document.createElement('style');
        s.textContent = css;
        document.head.appendChild(s);
    }

    function buildBackdrop() {
        if (backdrop) return backdrop;
        backdrop = document.createElement('div');
        backdrop.className = 'cdb-backdrop';
        backdrop.innerHTML =
            '<div class="cdb-modal" role="dialog" aria-modal="true">' +
                '<div class="cdb-header"><span>Check Database</span>' +
                    '<button type="button" class="cdb-close" aria-label="Đóng">&times;</button></div>' +
                '<div class="cdb-body"></div>' +
            '</div>';
        document.body.appendChild(backdrop);
        backdrop.querySelector('.cdb-close').addEventListener('click', close);
        backdrop.addEventListener('click', function (e) { if (e.target === backdrop) close(); });
        document.addEventListener('keydown', function (e) {
            if (e.key === 'Escape' && backdrop.classList.contains('show')) close();
        });
        return backdrop;
    }

    function close() { if (backdrop) backdrop.classList.remove('show'); }

    function esc(s) {
        return String(s == null ? '' : s)
            .replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;');
    }

    function ajaxUrl() {
        // 1) Trang mở bằng URL cũ (?mod=..&controllers=..) -> dùng luôn.
        var p = new URLSearchParams(window.location.search);
        var mod = p.get('mod') || '';
        if (mod !== '') {
            var ctrl = p.get('controllers') || '';
            return '?mod=' + encodeURIComponent(mod) +
                   '&controllers=' + encodeURIComponent(ctrl) +
                   '&action=check_database';
        }
        // 2) Trang URL gọn /{mod}/{action}[/{id}] -> lấy mod từ path, gọi
        //    check_database dạng URL gọn: {gốc app}/{mod}/check_database.
        //    Dùng <base href> (document.baseURI) để đúng cả khi app nằm thư mục con.
        var root;
        try { root = new URL(document.baseURI).pathname; } catch (e) { root = '/'; }
        if (root.charAt(root.length - 1) !== '/') root += '/';
        var path = window.location.pathname;
        if (path.indexOf(root) === 0) path = path.slice(root.length);
        var seg = path.replace(/^\/+/, '').split('/').filter(Boolean);
        var m = seg.length ? decodeURIComponent(seg[0]) : '';
        if (m === '') {
            return '?mod=&controllers=&action=check_database'; // fallback (hiếm)
        }
        return root + encodeURIComponent(m) + '/check_database';
    }

    /** Cửa sổ số trang hiển thị: CĂN GIỮA trang hiện tại (±3, tối đa 7 số liên tiếp) để luôn
     *  bấm lùi lại được vài trang trước đó, không chỉ tiến tới — trước đây cửa sổ bắt đầu đúng
     *  từ current nên đứng ở trang giữa danh sách là hết đường quay lại. Sau cửa sổ, thêm 1 mốc
     *  "giữa" toàn bộ danh sách (total/2, chỉ hiện khi còn nằm phía trước cửa sổ) để nhảy nhanh
     *  qua đoạn xa, rồi tới trang cuối. Ví dụ total=119, current=22 -> [19,20,21,22,23,24,25,
     *  '...',60,'...',119]. */
    function buildPageWindow(current, total) {
        var WIN = 3;
        var start = Math.max(1, current - WIN);
        var end = Math.min(total, current + WIN);
        var pages = [];
        for (var p = start; p <= end; p++) pages.push(p);
        if (end < total) {
            var mid = Math.round(total / 2);
            if (mid > end && mid < total) {
                pages.push('...');
                pages.push(mid);
            }
            if (end < total - 1) pages.push('...');
            pages.push(total);
        }
        return pages;
    }

    function renderPager(pagerEl, item, onGoto) {
        var total = item.total_pages || 1;
        var current = item.page || 1;
        if (total <= 1) {
            pagerEl.innerHTML = '<span class="cdb-count">' + (item.total || 0) + ' dòng</span>';
            return;
        }
        var pages = buildPageWindow(current, total);
        var html = pages.map(function (p) {
            if (p === '...') return '<span class="pg-ellipsis">...</span>';
            return '<button type="button" class="pg' + (p === current ? ' active' : '') + '" data-pg="' + p + '">' + p + '</button>';
        }).join('');
        html += '<span class="cdb-count">' + (item.total || 0) + ' dòng — trang ' + current + '/' + total + '</span>';
        pagerEl.innerHTML = html;
        Array.prototype.forEach.call(pagerEl.querySelectorAll('.pg'), function (b) {
            b.addEventListener('click', function () { onGoto(parseInt(b.getAttribute('data-pg'), 10) || 1); });
        });
    }

    function renderTableBody(scrollEl, item) {
        var table = document.createElement('table');
        table.className = 'cdb-table';
        var thead = '<thead><tr>' +
            (item.columns || []).map(function (c) { return '<th>' + esc(c) + '</th>'; }).join('') +
            '</tr></thead>';
        var rows = item.rows || [];
        var tbody = rows.length
            ? rows.map(function (r) {
                return '<tr>' + r.map(function (c) { return '<td>' + esc(c) + '</td>'; }).join('') + '</tr>';
            }).join('')
            : ('<tr><td colspan="' + (item.columns || []).length + '"><div class="cdb-empty">Chưa có dữ liệu.</div></td></tr>');
        table.innerHTML = thead + '<tbody>' + tbody + '</tbody>';
        scrollEl.innerHTML = '';
        scrollEl.appendChild(table);
    }

    /** 1 khối bảng: tiêu đề (+ icon lọc nếu item.filterable), bảng dữ liệu, phân trang.
     *  Tự quản lý state (trang/từ khóa) riêng và tự gọi AJAX lại CHỈ cho bảng này khi
     *  đổi trang hoặc gõ lọc — không phải tải lại cả modal. */
    function renderSection(item) {
        var state = { page: item.page || 1, keyword: item.keyword || '' };
        var sec = document.createElement('div');
        sec.className = 'cdb-section';

        var head = document.createElement('div');
        head.className = 'cdb-section-head';
        var h = document.createElement('h3');
        h.textContent = item.table;
        head.appendChild(h);

        var filterWrap = null, filterInput = null, filterBtn = null;
        if (item.filterable) {
            filterWrap = document.createElement('div');
            filterWrap.className = 'cdb-filter-wrap';
            filterBtn = document.createElement('button');
            filterBtn.type = 'button';
            filterBtn.className = 'cdb-filter-btn';
            filterBtn.title = 'Lọc';
            filterBtn.innerHTML = '<i class="fa-solid fa-filter"></i>';
            filterInput = document.createElement('input');
            filterInput.type = 'text';
            filterInput.className = 'cdb-filter-input';
            filterInput.placeholder = 'Lọc...';
            filterWrap.appendChild(filterBtn);
            filterWrap.appendChild(filterInput);
            head.appendChild(filterWrap);

            filterBtn.addEventListener('click', function () {
                filterWrap.classList.toggle('open');
                if (filterWrap.classList.contains('open')) filterInput.focus();
            });
            var filterTimer = null;
            filterInput.addEventListener('input', function () {
                clearTimeout(filterTimer);
                filterTimer = setTimeout(function () {
                    state.keyword = filterInput.value.trim();
                    state.page = 1;
                    filterBtn.classList.toggle('is-active', state.keyword !== '');
                    reload(true);
                }, 300);
            });
        }
        sec.appendChild(head);

        if (item.error) {
            var e = document.createElement('div');
            e.className = 'cdb-err';
            e.textContent = item.error;
            sec.appendChild(e);
            return sec;
        }

        var scroll = document.createElement('div');
        scroll.className = 'cdb-scroll';
        sec.appendChild(scroll);

        var pager = document.createElement('div');
        pager.className = 'cdb-pager';
        sec.appendChild(pager);

        function draw(it) {
            renderTableBody(scroll, it);
            renderPager(pager, it, function (p) { state.page = p; reload(false); });
        }
        draw(item);

        function reload(keepFilterFocus) {
            scroll.classList.add('is-loading');
            var fd = new FormData();
            fd.append('table', item.table);
            fd.append('page', state.page);
            fd.append('keyword', state.keyword);
            fetch(ajaxUrl(), { method: 'POST', body: fd, credentials: 'same-origin' })
                .then(function (r) { return r.json(); })
                .then(function (res) {
                    scroll.classList.remove('is-loading');
                    var d = (res && res.data && res.data[0]) ? res.data[0] : null;
                    if (!d) return;
                    draw(d);
                    if (keepFilterFocus && filterInput) filterInput.focus();
                })
                .catch(function () { scroll.classList.remove('is-loading'); });
        }

        return sec;
    }

    function open(tables) {
        injectStyle();
        var bd = buildBackdrop();
        var body = bd.querySelector('.cdb-body');
        body.innerHTML = '<div class="cdb-empty">Đang tải dữ liệu...</div>';
        bd.classList.add('show');

        var fd = new FormData();
        fd.append('tables', tables);
        fetch(ajaxUrl(), { method: 'POST', body: fd, credentials: 'same-origin' })
            .then(function (r) { return r.json(); })
            .then(function (res) {
                body.innerHTML = '';
                var data = (res && res.data) ? res.data : [];
                if (!data.length) {
                    body.innerHTML = '<div class="cdb-empty">Không có bảng nào để hiển thị.</div>';
                    return;
                }
                data.forEach(function (item) { body.appendChild(renderSection(item)); });
            })
            .catch(function () {
                body.innerHTML = '<div class="cdb-err">Lỗi kết nối khi tải dữ liệu.</div>';
            });
    }

    document.addEventListener('click', function (e) {
        var btn = e.target.closest && e.target.closest('.btn-check-db, [data-check-db]');
        if (!btn) return;
        e.preventDefault();
        open(btn.getAttribute('data-tables') || '');
    });

    // Inject style sớm để nút có giao diện ngay (không chờ tới lần mở modal).
    injectStyle();
})();
