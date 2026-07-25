/* ===== Quản lý dự án — composers.js : 4 trình soạn đặc biệt ===== */
window.PMX = window.PMX || {};
window.PMComposers = (function () {
    'use strict';
    var X = window.PMX;

    var $modal = document.getElementById('pm-builder-modal');
    var $title = document.getElementById('pm-builder-title');
    var $body = document.getElementById('pm-builder-body');
    var $send = document.getElementById('pm-builder-send');

    var collector = null;      // function -> payload | null
    var currentType = null;
    var onSubmit = null;

    function close() { $modal.style.display = 'none'; collector = null; }
    document.getElementById('pm-builder-close').addEventListener('click', close);
    document.getElementById('pm-builder-cancel').addEventListener('click', close);
    $modal.addEventListener('click', function (e) { if (e.target === $modal) close(); });

    $send.addEventListener('click', function () {
        if (!collector) return;
        var payload = collector();
        if (payload === null) return; // không hợp lệ
        var t = currentType, cb = onSubmit;
        close();
        cb(t, payload);
    });

    function el(html) { var d = document.createElement('div'); d.innerHTML = html; return d.firstElementChild; }

    /* ---------- TODO LIST (checklist: tích hoàn thành, dùng chung) ---------- */
    function buildChecklist() {
        $title.textContent = 'Tạo Todo List';
        $body.innerHTML = '<div class="pm-bld-list" id="bld-list"></div>' +
            '<button type="button" class="pm-bld-add" id="bld-add"><i class="fa-solid fa-plus"></i> Thêm dòng</button>';
        var list = $body.querySelector('#bld-list');
        function addRow(v) {
            var row = el('<div class="pm-bld-row"><input type="text" placeholder="Nội dung việc..." value="' + X.esc(v || '') + '"><button type="button" class="pm-bld-del"><i class="fa-solid fa-xmark"></i></button></div>');
            row.querySelector('.pm-bld-del').addEventListener('click', function () { row.remove(); });
            row.querySelector('input').addEventListener('keydown', function (e) { if (e.key === 'Enter') { e.preventDefault(); addRow(''); list.lastChild.querySelector('input').focus(); } });
            list.appendChild(row);
        }
        $body.querySelector('#bld-add').addEventListener('click', function () { addRow(''); });
        addRow(''); // chỉ 1 dòng ban đầu, Enter sinh tiếp
        setTimeout(function () { var i = list.querySelector('input'); if (i) i.focus(); }, 50);
        collector = function () {
            var items = [];
            list.querySelectorAll('input').forEach(function (i) { var v = i.value.trim(); if (v) items.push(v); });
            if (!items.length) { X.toast('Todo List trống.'); return null; }
            return items;
        };
    }

    /* ---------- CHECK LIST / VOTE (bình chọn: chọn 1 hoặc nhiều) ---------- */
    function buildVote() {
        $title.textContent = 'Tạo bình chọn';
        $body.innerHTML = '<label class="pm-vote-multi"><input type="checkbox" id="vote-multi" checked> Cho phép chọn nhiều</label>' +
            '<div class="pm-bld-list" id="vote-list"></div>' +
            '<button type="button" class="pm-bld-add" id="vote-add"><i class="fa-solid fa-plus"></i> Thêm lựa chọn</button>';
        var list = $body.querySelector('#vote-list');
        function addRow(v) {
            var row = el('<div class="pm-bld-row"><input type="text" placeholder="Một lựa chọn..." value="' + X.esc(v || '') + '"><button type="button" class="pm-bld-del"><i class="fa-solid fa-xmark"></i></button></div>');
            row.querySelector('.pm-bld-del').addEventListener('click', function () { row.remove(); });
            row.querySelector('input').addEventListener('keydown', function (e) { if (e.key === 'Enter') { e.preventDefault(); addRow(''); list.lastChild.querySelector('input').focus(); } });
            list.appendChild(row);
        }
        $body.querySelector('#vote-add').addEventListener('click', function () { addRow(''); });
        addRow(''); addRow('');
        setTimeout(function () { var i = list.querySelector('input'); if (i) i.focus(); }, 50);
        collector = function () {
            var options = [];
            list.querySelectorAll('input').forEach(function (i) { var v = i.value.trim(); if (v) options.push(v); });
            if (options.length < 2) { X.toast('Cần ít nhất 2 lựa chọn.'); return null; }
            return { multi: $body.querySelector('#vote-multi').checked, options: options };
        };
    }

    /* ---------- TABLE (mô phỏng field DB) ---------- */
    function buildTable() {
        $title.textContent = 'Tạo bảng mô phỏng';
        $body.innerHTML =
            '<label>Tên bảng</label><input type="text" id="tbl-name" placeholder="Đặt tên bảng">' +
            '<div class="pm-bld-hint">Mỗi dòng = 1 field: tên field · kiểu dữ liệu · ghi chú.</div>' +
            '<div class="pm-tbl-grid" id="tbl-grid">' +
            '<div class="cellrow"><b>Tên field</b><b>Kiểu</b><b>Ghi chú</b><span></span></div></div>' +
            '<button type="button" class="pm-bld-add" id="tbl-add"><i class="fa-solid fa-plus"></i> Thêm field</button>';
        var grid = $body.querySelector('#tbl-grid');
        function addRow(a, b, c) {
            var row = el('<div class="cellrow">' +
                '<input type="text" placeholder="id" value="' + X.esc(a || '') + '">' +
                '<input type="text" placeholder="INT / VARCHAR..." value="' + X.esc(b || '') + '">' +
                '<input type="text" placeholder="khóa chính, NULL..." value="' + X.esc(c || '') + '">' +
                '<button type="button" class="pm-bld-del"><i class="fa-solid fa-xmark"></i></button></div>');
            row.querySelector('.pm-bld-del').addEventListener('click', function () { row.remove(); });
            grid.appendChild(row);
        }
        $body.querySelector('#tbl-add').addEventListener('click', function () { addRow('', '', ''); });
        addRow('id', 'INT', 'PK, AUTO_INCREMENT'); addRow('', '', '');
        collector = function () {
            var rows = [];
            grid.querySelectorAll('.cellrow').forEach(function (r, idx) {
                if (idx === 0) return; // header
                var ins = r.querySelectorAll('input');
                var f = ins[0].value.trim(), t = ins[1].value.trim(), n = ins[2].value.trim();
                if (f || t || n) rows.push([f, t, n]);
            });
            if (!rows.length) { X.toast('Chưa có field nào.'); return null; }
            return { name: $body.querySelector('#tbl-name').value.trim(), columns: ['Field', 'Kiểu', 'Ghi chú'], rows: rows };
        };
    }

    /* ---------- TREE (sơ đồ module/view) ---------- */
    function buildTree() {
        $title.textContent = 'Tạo sơ đồ cây (module / view)';
        $body.innerHTML = '<div class="pm-bld-hint">Dùng nút ⇥ để thụt cấp (con), ⇤ để lùi cấp. Mô phỏng module → view.</div>' +
            '<div class="pm-bld-list" id="tree-list"></div>' +
            '<button type="button" class="pm-bld-add" id="tree-add"><i class="fa-solid fa-plus"></i> Thêm node</button>';
        var list = $body.querySelector('#tree-list');
        function addRow(level, label) {
            level = level || 0;
            var row = el('<div class="pm-tree-row" data-level="' + level + '">' +
                '<span class="indent"><button type="button" data-d="out" title="Lùi cấp">⇤</button><button type="button" data-d="in" title="Thụt cấp">⇥</button></span>' +
                '<span class="lvl" style="width:' + (level * 16) + 'px"></span>' +
                '<input type="text" placeholder="Tên module/view..." value="' + X.esc(label || '') + '">' +
                '<button type="button" class="pm-bld-del"><i class="fa-solid fa-xmark"></i></button></div>');
            function setLvl(l) { l = Math.max(0, Math.min(5, l)); row.dataset.level = l; row.querySelector('.lvl').style.width = (l * 16) + 'px'; }
            row.querySelector('[data-d=in]').addEventListener('click', function () { setLvl(parseInt(row.dataset.level, 10) + 1); });
            row.querySelector('[data-d=out]').addEventListener('click', function () { setLvl(parseInt(row.dataset.level, 10) - 1); });
            row.querySelector('.pm-bld-del').addEventListener('click', function () { row.remove(); });
            list.appendChild(row);
        }
        $body.querySelector('#tree-add').addEventListener('click', function () { addRow(0, ''); });
        addRow(0, 'Module'); addRow(1, 'View 1'); addRow(1, 'View 2');
        collector = function () {
            var nodes = [];
            list.querySelectorAll('.pm-tree-row').forEach(function (r) {
                var label = r.querySelector('input').value.trim();
                if (label) nodes.push({ level: parseInt(r.dataset.level, 10) || 0, label: label });
            });
            if (!nodes.length) { X.toast('Cây trống.'); return null; }
            return { nodes: nodes };
        };
    }

    /* ---------- PROCESS (kéo sắp xếp bước) ---------- */
    function buildProcess() {
        $title.textContent = 'Xây dựng quy trình các bước';
        $body.innerHTML = '<div class="pm-bld-list" id="proc-list"></div>' +
            '<button type="button" class="pm-bld-add" id="proc-add"><i class="fa-solid fa-plus"></i> Thêm bước</button>';
        var list = $body.querySelector('#proc-list');
        var dragEl = null;
        function addRow(v) {
            var row = el('<div class="pm-bld-row drag" draggable="true">' +
                '<span class="pm-bld-grip"><i class="fa-solid fa-grip-vertical"></i></span>' +
                '<input type="text" placeholder="Nhập nội dung bước" value="' + X.esc(v || '') + '">' +
                '<button type="button" class="pm-bld-del"><i class="fa-solid fa-xmark"></i></button></div>');
            var inp = row.querySelector('input');
            row.querySelector('.pm-bld-del').addEventListener('click', function () { row.remove(); });
            // Enter -> sinh dòng mới
            inp.addEventListener('keydown', function (e) { if (e.key === 'Enter') { e.preventDefault(); addRow(''); list.lastChild.querySelector('input').focus(); } });
            // Rời ô (click ra ngoài) mà dòng trống thì tự ẩn — giữ lại tối thiểu 1 dòng
            inp.addEventListener('blur', function () {
                setTimeout(function () {
                    if (inp.value.trim() === '' && list.querySelectorAll('.pm-bld-row').length > 1) row.remove();
                }, 120);
            });
            row.addEventListener('dragstart', function () { dragEl = row; row.classList.add('dragging'); });
            row.addEventListener('dragend', function () { row.classList.remove('dragging'); dragEl = null; });
            list.appendChild(row);
        }
        list.addEventListener('dragover', function (e) {
            e.preventDefault();
            if (!dragEl) return;
            var after = getAfter(list, e.clientY);
            if (after == null) list.appendChild(dragEl);
            else list.insertBefore(dragEl, after);
        });
        function getAfter(container, y) {
            var els = Array.prototype.slice.call(container.querySelectorAll('.pm-bld-row:not(.dragging)'));
            var closest = { offset: -Infinity, el: null };
            els.forEach(function (c) {
                var box = c.getBoundingClientRect();
                var offset = y - box.top - box.height / 2;
                if (offset < 0 && offset > closest.offset) closest = { offset: offset, el: c };
            });
            return closest.el;
        }
        $body.querySelector('#proc-add').addEventListener('click', function () { addRow(''); });
        addRow(''); // chỉ 1 dòng ban đầu, Enter sinh tiếp
        setTimeout(function () { var i = list.querySelector('input'); if (i) i.focus(); }, 50);
        collector = function () {
            var steps = [];
            list.querySelectorAll('input').forEach(function (i) { var v = i.value.trim(); if (v) steps.push(v); });
            if (!steps.length) { X.toast('Quy trình trống.'); return null; }
            return { steps: steps };
        };
    }

    var builders = { checklist: buildChecklist, vote: buildVote, table: buildTable, tree: buildTree, process: buildProcess };

    return {
        open: function (type, cb) {
            if (!builders[type]) return;
            currentType = type; onSubmit = cb;
            builders[type]();
            $modal.style.display = '';
        }
    };
})();
