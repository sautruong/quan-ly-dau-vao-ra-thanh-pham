/* =====================================================================
 *  OFFICE — docs_editor.js
 *  Trình soạn thảo văn bản NHIỀU TRANG (mỗi trang là 1 vùng contenteditable
 *  khổ A4 thật — không phải 1 khối cuộn dài giả lập). Toolbar: phông chữ,
 *  cỡ chữ, màu chữ, đậm/nghiêng/gạch chân (nhận diện đang bật), căn lề,
 *  danh sách, thụt lề, liên kết, bảng (chọn số cột/dòng lúc chèn, chèn/xóa
 *  dòng-cột, kéo giãn từng cột kiểu Word — chỉ đổi 2 cột liền kề, không
 *  tràn trang, màu nền/viền theo đúng vùng đang bôi đen, hover chọn cả
 *  bảng rồi xóa bằng phím Delete), ảnh (click chọn, kéo góc đổi cỡ, xóa),
 *  ngắt trang thật (Ctrl+Enter → nhảy sang trang mới), undo/redo.
 *  Lưu qua nút "Lưu" (OfficeEditor.save) — xem public/js/office/office_common.js.
 * ===================================================================== */
(function () {
    'use strict';

    var OE = window.OfficeEditor;
    if (!OE) return;

    function $(s, r) { return (r || document).querySelector(s); }

    var STATE_CMDS = ['bold', 'italic', 'underline', 'strikeThrough',
        'insertUnorderedList', 'insertOrderedList', 'justifyLeft', 'justifyCenter', 'justifyRight'];

    var PAGE_DELIM = '<!--OFPAGE-->';

    var TOOLBAR_HTML =
        '<button type="button" class="of-tb-btn" data-cmd="undo" title="Hoàn tác"><i class="fa-solid fa-rotate-left"></i></button>' +
        '<button type="button" class="of-tb-btn" data-cmd="redo" title="Làm lại"><i class="fa-solid fa-rotate-right"></i></button>' +
        '<span class="of-tb-sep"></span>' +
        '<select class="of-tb-select" id="of-tb-block" title="Kiểu chữ">' +
        '<option value="p">Văn bản thường</option><option value="h1">Tiêu đề 1</option>' +
        '<option value="h2">Tiêu đề 2</option><option value="h3">Tiêu đề 3</option></select>' +
        '<select class="of-tb-select" id="of-tb-font" title="Phông chữ">' +
        '<option value="Arial">Arial</option><option value="Times New Roman">Times New Roman</option>' +
        '<option value="Verdana">Verdana</option><option value="Tahoma">Tahoma</option>' +
        '<option value="Courier New">Courier New</option><option value="Georgia">Georgia</option></select>' +
        '<select class="of-tb-select" id="of-tb-fontsize" title="Cỡ chữ">' +
        '<option value="10">10</option><option value="12">12</option><option value="13">13</option>' +
        '<option value="14">14</option><option value="15" selected>15</option><option value="16">16</option>' +
        '<option value="18">18</option><option value="20">20</option><option value="24">24</option>' +
        '<option value="28">28</option><option value="32">32</option><option value="36">36</option>' +
        '<option value="48">48</option></select>' +
        '<select class="of-tb-select" id="of-tb-lineheight" title="Khoảng cách dòng">' +
        '<option value="" selected disabled>Khoảng cách dòng</option>' +
        '<option value="1">1.0</option><option value="1.15">1.15</option><option value="1.5">1.5</option>' +
        '<option value="2">2.0</option><option value="2.5">2.5</option><option value="3">3.0</option>' +
        '<option value="__custom">Thông số tùy chọn…</option></select>' +
        '<span class="of-tb-sep"></span>' +
        '<button type="button" class="of-tb-btn" data-cmd="bold" title="Đậm"><i class="fa-solid fa-bold"></i></button>' +
        '<button type="button" class="of-tb-btn" data-cmd="italic" title="Nghiêng"><i class="fa-solid fa-italic"></i></button>' +
        '<button type="button" class="of-tb-btn" data-cmd="underline" title="Gạch chân"><i class="fa-solid fa-underline"></i></button>' +
        '<button type="button" class="of-tb-btn" data-cmd="strikeThrough" title="Gạch ngang"><i class="fa-solid fa-strikethrough"></i></button>' +
        '<label class="of-tb-color" title="Màu chữ"><i class="fa-solid fa-font"></i><input type="color" id="of-tb-color" value="#000000"></label>' +
        '<span class="of-tb-sep"></span>' +
        '<button type="button" class="of-tb-btn" data-cmd="justifyLeft" title="Căn trái"><i class="fa-solid fa-align-left"></i></button>' +
        '<button type="button" class="of-tb-btn" data-cmd="justifyCenter" title="Căn giữa"><i class="fa-solid fa-align-center"></i></button>' +
        '<button type="button" class="of-tb-btn" data-cmd="justifyRight" title="Căn phải"><i class="fa-solid fa-align-right"></i></button>' +
        '<span class="of-tb-sep"></span>' +
        '<button type="button" class="of-tb-btn" data-cmd="insertUnorderedList" title="Danh sách chấm"><i class="fa-solid fa-list-ul"></i></button>' +
        '<button type="button" class="of-tb-btn" data-cmd="insertOrderedList" title="Danh sách số"><i class="fa-solid fa-list-ol"></i></button>' +
        '<button type="button" class="of-tb-btn" data-cmd="outdent" title="Giảm thụt lề"><i class="fa-solid fa-outdent"></i></button>' +
        '<button type="button" class="of-tb-btn" data-cmd="indent" title="Tăng thụt lề"><i class="fa-solid fa-indent"></i></button>' +
        '<span class="of-tb-sep"></span>' +
        '<button type="button" class="of-tb-btn" data-act="link" title="Chèn liên kết"><i class="fa-solid fa-link"></i></button>' +
        '<button type="button" class="of-tb-btn" data-act="table" title="Chèn bảng"><i class="fa-solid fa-table"></i></button>' +
        '<button type="button" class="of-tb-btn" data-act="image" title="Chèn ảnh"><i class="fa-solid fa-image"></i></button>' +
        '<button type="button" class="of-tb-btn" data-act="pagebreak" title="Ngắt trang (Ctrl+Enter)"><i class="fa-solid fa-file-circle-plus"></i></button>' +
        '<button type="button" class="of-tb-btn" data-act="margin" title="Điều chỉnh canh lề"><i class="fa-solid fa-ruler-combined"></i></button>' +
        '<span class="of-tb-sep"></span>' +
        '<button type="button" class="of-tb-btn" data-act="savever" title="Lưu phiên bản"><i class="fa-solid fa-floppy-disk"></i></button>';

    var container = null;      // #of-doc-editor (chứa nhiều .of-doc-page)
    var pages = [];             // danh sách các trang (mảng phần tử DOM)
    var activePage = null;      // trang đang có focus / thao tác gần nhất
    var selectedTableWrap = null;
    var selectedImgWrap = null;
    var savedRange = null;      // vùng chọn được lưu lại trước khi mở modal (tránh mất vị trí con trỏ)
    var pendingColorCells = null; // các ô đã bôi đen, chụp lại TRƯỚC khi bấm vào ô chọn màu (input color cướp focus)

    /* ================================================================
     *  Canh lề (margin) — áp dụng cho CẢ tài liệu (mọi trang), không phải riêng từng trang.
     *  Không có bảng/JSON riêng cho Docs (khác Sheets) — nội dung lưu là 1 chuỗi HTML thuần, nên
     *  lưu 4 số canh lề bằng 1 MỐC HTML comment gắn ở ĐẦU chuỗi nội dung, giống hệt cách
     *  "<!--OFPAGE-->" đã dùng để đánh dấu ngắt trang — không cần đổi schema/officeModel.php.
     * ================================================================ */
    var MARGIN_RE = /^<!--OFMARGIN:([\d.]+),([\d.]+),([\d.]+),([\d.]+)-->/;
    var docMargin = { top: 2.54, right: 2.54, bottom: 2.54, left: 2.54 }; // "Normal" mặc định

    function marginPaddingCss() {
        return docMargin.top + 'cm ' + docMargin.right + 'cm ' + docMargin.bottom + 'cm ' + docMargin.left + 'cm';
    }

    /* ================================================================
     *  NHIỀU TRANG (mỗi trang là 1 vùng contenteditable khổ A4)
     * ================================================================ */
    function createPageEl(html) {
        var page = document.createElement('div');
        page.className = 'of-doc-page';
        page.contentEditable = OE.canEdit ? 'true' : 'false';
        page.innerHTML = html || '<p></p>';
        page.style.padding = marginPaddingCss();
        return page;
    }

    function loadPages(content) {
        content = String(content || '<p></p>');
        var m = MARGIN_RE.exec(content);
        if (m) {
            docMargin = { top: parseFloat(m[1]), right: parseFloat(m[2]), bottom: parseFloat(m[3]), left: parseFloat(m[4]) };
            content = content.slice(m[0].length);
        } else {
            docMargin = { top: 2.54, right: 2.54, bottom: 2.54, left: 2.54 };
        }
        syncRulerMargin();
        container.innerHTML = '';
        pages = [];
        var htmls = content.split(PAGE_DELIM);
        htmls.forEach(function (h) {
            var page = createPageEl(h);
            container.appendChild(page);
            pages.push(page);
        });
        activePage = pages[0];
    }

    function serializePages() {
        return '<!--OFMARGIN:' + docMargin.top + ',' + docMargin.right + ',' + docMargin.bottom + ',' + docMargin.left + '-->' +
            pages.map(function (p) { return p.innerHTML; }).join(PAGE_DELIM);
    }

    /** Đổi canh lề CẢ tài liệu ngay lập tức (mọi trang hiện có) — trang mới tạo sau đó tự lấy
     *  đúng giá trị này qua createPageEl(). */
    function applyMargin(t, r, b, l) {
        docMargin = { top: t, right: r, bottom: b, left: l };
        var css = marginPaddingCss();
        pages.forEach(function (p) { p.style.padding = css; });
        syncRulerMargin();
        OE.scheduleAutosave();
    }

    var MARGIN_PRESETS = [
        { label: 'Normal', hint: '2.54cm mọi phía', t: 2.54, r: 2.54, b: 2.54, l: 2.54 },
        { label: 'Narrow', hint: '1.27cm mọi phía', t: 1.27, r: 1.27, b: 1.27, l: 1.27 },
        { label: 'Moderate', hint: 'Trên/dưới 2.54cm — trái/phải 1.91cm', t: 2.54, r: 1.91, b: 2.54, l: 1.91 }
    ];

    function openMarginModal() {
        var presetsHtml = MARGIN_PRESETS.map(function (p, i) {
            return '<button type="button" class="of-btn of-margin-preset" data-i="' + i + '">' +
                '<b>' + p.label + '</b><span class="of-muted">' + p.hint + '</span></button>';
        }).join('');
        OE.openModal('Điều chỉnh canh lề', '' +
            '<div class="of-margin-presets">' + presetsHtml + '</div>' +
            '<div class="of-margin-custom-title">Tùy chỉnh (cm)</div>' +
            '<div class="of-margin-inputs">' +
            '<label>Trên <input type="number" step="0.01" min="0" id="of-margin-top" value="' + docMargin.top + '"></label>' +
            '<label>Dưới <input type="number" step="0.01" min="0" id="of-margin-bottom" value="' + docMargin.bottom + '"></label>' +
            '<label>Trái <input type="number" step="0.01" min="0" id="of-margin-left" value="' + docMargin.left + '"></label>' +
            '<label>Phải <input type="number" step="0.01" min="0" id="of-margin-right" value="' + docMargin.right + '"></label>' +
            '</div>' +
            '<button type="button" class="of-btn of-btn-primary" id="of-margin-apply">Áp dụng</button>');

        document.querySelectorAll('.of-margin-preset').forEach(function (btn) {
            btn.addEventListener('click', function () {
                var p = MARGIN_PRESETS[parseInt(btn.dataset.i, 10)];
                applyMargin(p.t, p.r, p.b, p.l);
                OE.closeModal();
            });
        });
        $('#of-margin-apply').addEventListener('click', function () {
            var t = parseFloat($('#of-margin-top').value);
            var r = parseFloat($('#of-margin-right').value);
            var b = parseFloat($('#of-margin-bottom').value);
            var l = parseFloat($('#of-margin-left').value);
            if ([t, r, b, l].some(function (v) { return isNaN(v) || v < 0; })) {
                OE.toast('Giá trị canh lề không hợp lệ.', false);
                return;
            }
            applyMargin(t, r, b, l);
            OE.closeModal();
        });
    }

    /** Ctrl+Enter: trang hiện tại giữ nguyên khổ A4, con trỏ nhảy hẳn sang 1 trang DOM mới. */
    function insertNewPageAfter(afterPage) {
        var newPage = createPageEl('<p></p>');
        if (afterPage.nextSibling) container.insertBefore(newPage, afterPage.nextSibling);
        else container.appendChild(newPage);
        var idx = pages.indexOf(afterPage);
        pages.splice(idx + 1, 0, newPage);
        activePage = newPage;
        newPage.focus();
        var range = document.createRange();
        range.selectNodeContents(newPage);
        range.collapse(true);
        var sel = window.getSelection();
        sel.removeAllRanges(); sel.addRange(range);
    }

    function isInPages(node) {
        while (node) {
            if (pages.indexOf(node) !== -1) return true;
            node = node.parentNode;
        }
        return false;
    }

    /* ================================================================
     *  Giữ vùng chọn khi thao tác qua modal/ô màu (những nơi cướp focus)
     * ================================================================ */
    function saveCurrentRange() {
        var sel = window.getSelection();
        if (sel && sel.rangeCount > 0) {
            var r = sel.getRangeAt(0);
            if (isInPages(r.commonAncestorContainer)) { savedRange = r.cloneRange(); return; }
        }
        savedRange = null;
    }
    function restoreSavedRange() {
        if (!savedRange || !activePage) return;
        // {preventScroll:true}: chỉ cần LẤY LẠI focus/con trỏ để execCommand áp dụng đúng chỗ,
        // KHÔNG cần cuộn màn hình tới activePage — nếu không, mỗi lần bấm nút toolbar (bôi đen ở
        // giữa trang dài rồi bấm B/I/U...) trình duyệt tự cuộn màn hình về đầu trang đang chứa
        // activePage do focus() mặc định cuộn phần tử vào khung nhìn, dù nó vốn đã ở trong khung
        // nhìn (chỉ là không phải focus tuyệt đối theo con trỏ trình duyệt).
        activePage.focus({ preventScroll: true });
        var sel = window.getSelection();
        sel.removeAllRanges();
        sel.addRange(savedRange);
    }

    /* ================================================================
     *  BẢNG
     * ================================================================ */
    function tableWrapHtml(cols, rows) {
        var html = '<div class="of-table-wrap" contenteditable="false">' +
            '<div class="of-table-bar">' +
            '<button type="button" class="of-table-grip" title="Chọn bảng (nhấn Delete để xóa)"><i class="fa-solid fa-up-down-left-right"></i></button>' +
            '<label class="of-table-color-btn" title="Màu nền (theo vùng đang bôi đen)"><i class="fa-solid fa-fill-drip"></i><input type="color" class="of-table-bg-input"></label>' +
            '<button type="button" class="of-table-tool-btn of-table-border-btn" title="Thiết lập viền (theo vùng đang bôi đen)"><i class="fa-solid fa-border-all"></i></button>' +
            '<button type="button" class="of-table-tool-btn of-table-merge-btn" title="Gộp ô"><i class="fa-solid fa-table-cells-large"></i></button>' +
            '<button type="button" class="of-table-tool-btn of-table-split-btn" title="Chia ô"><i class="fa-solid fa-table-cells"></i></button>' +
            '</div>' +
            '<table class="of-doc-table" contenteditable="true"><tbody>';
        for (var r = 0; r < rows; r++) {
            html += '<tr>';
            for (var c = 0; c < cols; c++) html += '<td style="border:1px solid #cbd5e1;padding:6px 8px;">&nbsp;</td>';
            html += '</tr>';
        }
        // .of-table-exit: dải mỏng LUÔN CÓ ngay dưới bảng (contenteditable=false, không phải
        // nội dung) — bấm vào đây đặt con trỏ thẳng vào đoạn văn SAU bảng, không lọt vào ô cuối
        // của bảng nữa. <br> bên trong <p> để đoạn văn có chiều cao dòng thật, dễ bấm trúng.
        html += '</tbody></table></div>' +
            '<div class="of-table-exit" contenteditable="false" title="Bấm để xuống dòng mới sau bảng">' +
            '<span>↵ Xuống dòng sau bảng</span></div><p><br></p>';
        return html;
    }

    function openInsertTableModal() {
        saveCurrentRange();
        OE.openModal('Chèn bảng',
            '<div class="of-table-dim-row">' +
            '<label>Số cột <input type="text" inputmode="numeric" id="of-ins-cols" value="3"></label>' +
            '<label>Số dòng <input type="text" inputmode="numeric" id="of-ins-rows" value="3"></label>' +
            '</div>' +
            '<div class="of-table-dim-hint">Cột: 1–20, dòng: 1–50.</div>' +
            '<button type="button" class="of-btn of-btn-primary" id="of-ins-table-go">Chèn bảng</button>');
        var go = $('#of-ins-table-go');
        if (go) go.addEventListener('click', function () {
            var colsRaw = parseInt(($('#of-ins-cols').value || '').replace(/[^0-9]/g, ''), 10);
            var rowsRaw = parseInt(($('#of-ins-rows').value || '').replace(/[^0-9]/g, ''), 10);
            var cols = Math.max(1, Math.min(20, isNaN(colsRaw) ? 3 : colsRaw));
            var rows = Math.max(1, Math.min(50, isNaN(rowsRaw) ? 3 : rowsRaw));
            OE.closeModal();
            restoreSavedRange();
            document.execCommand('insertHTML', false, tableWrapHtml(cols, rows));
            OE.scheduleAutosave();
        });
    }

    function cellIndex(cell) { return Array.prototype.indexOf.call(cell.parentElement.children, cell); }
    function tableRowIndex(tr) { return Array.prototype.indexOf.call(tr.parentElement.children, tr); }

    /* ================================================================
     *  Chọn NHIỀU Ô trong bảng (kéo chuột, kể cả kéo DỌC theo cột) — trình duyệt xử lý chọn
     *  nhiều ô kiểu bảng qua Selection API khá thất thường (đặc biệt khi kéo dọc chỉ trong 1
     *  cột), nên tự theo dõi vùng chọn bằng chỉ số hàng/cột thay vì dựa hẳn vào
     *  window.getSelection() — cùng cách tiếp cận với vùng chọn ô trong Sheets (sel {r1,c1,r2,c2}).
     *  Tô nền CẢ Ô (.is-cellsel) để dễ nhận biết, thay vì chỉ có viền bôi đen văn bản mặc định.
     * ================================================================ */
    var cellSel = null; // {table, r1, c1, r2, c2} — hàng/cột 0-based TRONG BẢNG đang chọn

    function clearCellSelUi(table) {
        if (!table) return;
        table.querySelectorAll('.is-cellsel').forEach(function (c) { c.classList.remove('is-cellsel'); });
    }
    function applyCellSelUi() {
        if (!cellSel) return;
        var rows = cellSel.table.querySelectorAll('tr');
        var r1 = Math.min(cellSel.r1, cellSel.r2), r2 = Math.max(cellSel.r1, cellSel.r2);
        var c1 = Math.min(cellSel.c1, cellSel.c2), c2 = Math.max(cellSel.c1, cellSel.c2);
        for (var r = 0; r < rows.length; r++) {
            var cells = rows[r].children;
            for (var c = 0; c < cells.length; c++) {
                cells[c].classList.toggle('is-cellsel', r >= r1 && r <= r2 && c >= c1 && c <= c2);
            }
        }
    }
    function getCellSelCells() {
        if (!cellSel) return null;
        var rows = cellSel.table.querySelectorAll('tr');
        var r1 = Math.min(cellSel.r1, cellSel.r2), r2 = Math.max(cellSel.r1, cellSel.r2);
        var c1 = Math.min(cellSel.c1, cellSel.c2), c2 = Math.max(cellSel.c1, cellSel.c2);
        var out = [];
        for (var r = r1; r <= r2; r++) {
            var cells = rows[r] ? rows[r].children : [];
            for (var c = c1; c <= c2; c++) if (cells[c]) out.push(cells[c]);
        }
        return out;
    }

    /** Áp dụng 1 hành động định dạng (thường là execCommand) cho ĐÚNG vùng đang chọn — nếu đang
     *  chọn NHIỀU Ô bảng (cellSel), lần lượt bôi đen TOÀN BỘ nội dung từng ô rồi mới chạy hành
     *  động cho ô đó (làm y hệt cho từng ô một). Cần thiết vì lúc bắt đầu kéo chọn nhiều ô,
     *  Selection gốc của trình duyệt đã bị xoá chủ động (xem bindTableInteractions, tránh chồng
     *  2 lớp tô: viền bôi đen chữ mặc định + nền .is-cellsel tự vẽ) — nếu không làm vậy,
     *  execCommand/Ctrl+B/I/U... sẽ không còn vùng chọn nào để áp dụng, coi như "không ăn". Dùng
     *  chung cho toolbar (B/I/U/gạch ngang/căn lề/danh sách/thụt lề/cỡ chữ/phông chữ/màu chữ) và
     *  phím tắt Ctrl+B/I/U. */
    function applyFormatAction(fn) {
        if (cellSel) {
            var cells = getCellSelCells();
            if (cells && cells.length > 1) {
                cells.forEach(function (cell) {
                    var range = document.createRange();
                    range.selectNodeContents(cell);
                    var sel = window.getSelection();
                    sel.removeAllRanges();
                    sel.addRange(range);
                    fn();
                });
                applyCellSelUi(); // giữ lại tô nền vùng đã chọn (fn() ở trên có thể đã đổi DOM)
                return;
            }
        }
        fn();
    }

    /** Vùng chọn dạng {table,r1,c1,r2,c2} (đã chuẩn hoá min/max) cho 1 danh sách ô — dùng cho
     *  viền bảng/gộp ô, cần biết đúng HÌNH CHỮ NHẬT chứ không chỉ danh sách ô rời rạc. */
    function cellsToRange(table, cells) {
        if (!cells || !cells.length) return null;
        var r1 = Infinity, r2 = -Infinity, c1 = Infinity, c2 = -Infinity;
        cells.forEach(function (cell) {
            var r = tableRowIndex(cell.parentElement), c = cellIndex(cell);
            r1 = Math.min(r1, r); r2 = Math.max(r2, r);
            c1 = Math.min(c1, c); c2 = Math.max(c2, c);
        });
        return { table: table, r1: r1, r2: r2, c1: c1, c2: c2 };
    }

    var CELL_BORDER_STYLE = 'border:1px solid #cbd5e1;padding:6px 8px;';

    function newCellLike(refCell) {
        var td = document.createElement('td');
        td.innerHTML = '&nbsp;';
        td.style.cssText = CELL_BORDER_STYLE;
        // Dòng mới lấy độ rộng theo đúng cột của dòng tham chiếu (dòng phía trên) để bảng
        // không bị lệch cột khi vừa thêm dòng.
        if (refCell && refCell.style.width) td.style.width = refCell.style.width;
        return td;
    }

    function insertTableRow(cell, pos) {
        var tr = cell.parentElement;
        var newTr = document.createElement('tr');
        for (var i = 0; i < tr.children.length; i++) {
            newTr.appendChild(newCellLike(tr.children[i]));
        }
        tr.parentElement.insertBefore(newTr, pos === 'above' ? tr : tr.nextSibling);
    }
    function deleteTableRow(cell) {
        var tr = cell.parentElement;
        var tbody = tr.parentElement;
        if (tbody.children.length > 1) tr.remove();
    }
    function insertTableCol(cell, pos, table) {
        // Đóng băng độ rộng các cột hiện có trước — nếu không, chèn 1 cột mới vào bảng đang
        // table-layout:auto có thể làm trình duyệt tự phân bổ lại khiến các cột cũ bị xô lệch.
        freezeColumnWidths(table);
        var idx = cellIndex(cell);
        table.querySelectorAll('tr').forEach(function (tr) {
            var td = newCellLike(null);
            td.style.width = '80px'; // độ rộng nhỏ mặc định để thấy rõ có cột mới vừa chèn
            var ref = tr.children[idx];
            tr.insertBefore(td, pos === 'left' ? ref : ref.nextSibling);
        });
    }
    function deleteTableCol(cell, table) {
        var idx = cellIndex(cell);
        var rows = table.querySelectorAll('tr');
        if (rows[0] && rows[0].children.length <= 1) return;
        rows.forEach(function (tr) { if (tr.children[idx]) tr.children[idx].remove(); });
    }

    function showTableCtx(x, y, cell, table) {
        var items = [
            { label: 'Chèn dòng trên', act: function () { insertTableRow(cell, 'above'); } },
            { label: 'Chèn dòng dưới', act: function () { insertTableRow(cell, 'below'); } },
            { label: 'Xóa dòng', act: function () { deleteTableRow(cell); } },
            { label: 'Chèn cột trái', act: function () { insertTableCol(cell, 'left', table); } },
            { label: 'Chèn cột phải', act: function () { insertTableCol(cell, 'right', table); } },
            { label: 'Xóa cột', act: function () { deleteTableCol(cell, table); } }
        ];
        var menu = document.createElement('div');
        menu.className = 'of-ctxmenu';
        menu.style.left = x + 'px'; menu.style.top = y + 'px'; menu.style.display = 'block';
        menu.innerHTML = items.map(function (it, i) { return '<div class="of-ctx-item" data-i="' + i + '">' + it.label + '</div>'; }).join('');
        document.body.appendChild(menu);
        menu.querySelectorAll('.of-ctx-item').forEach(function (el, i) {
            el.addEventListener('click', function () {
                items[i].act();
                if (menu.parentNode) document.body.removeChild(menu);
                OE.scheduleAutosave();
            });
        });
        setTimeout(function () {
            document.addEventListener('click', function once() {
                if (menu.parentNode) document.body.removeChild(menu);
                document.removeEventListener('click', once);
            });
        }, 0);
    }

    function selectTableWrap(wrap) {
        if (selectedTableWrap && selectedTableWrap !== wrap) selectedTableWrap.classList.remove('is-selected');
        selectedTableWrap = wrap;
        if (wrap) wrap.classList.add('is-selected');
    }

    /** Đặt caret vào đoạn văn NGAY SAU 1 mốc (dải "thoát bảng" hoặc chính .of-table-wrap) —
     *  tạo đoạn văn mới nếu chưa có. Dùng khi bấm dải thoát bảng, hoặc khi Enter ở ô cuối bảng. */
    function placeCaretAfter(markerEl) {
        var p = markerEl.nextElementSibling;
        if (!p || p.tagName !== 'P') {
            p = document.createElement('p');
            p.innerHTML = '<br>';
            markerEl.parentNode.insertBefore(p, markerEl.nextSibling);
        }
        var page = markerEl.closest('.of-doc-page');
        if (page) page.focus();
        var range = document.createRange();
        range.selectNodeContents(p);
        range.collapse(true);
        var selc = window.getSelection();
        selc.removeAllRanges();
        selc.addRange(range);
        activePage = page || activePage;
    }

    /** Lấy các ô (td/th) đang thuộc vùng chọn hiện tại trong bảng — ưu tiên vùng chọn ô TỰ THEO
     *  DÕI (cellSel, xem phần "Chọn NHIỀU Ô" ở trên, đáng tin cậy hơn cho kéo dọc theo cột); nếu
     *  không có, mới lùi về đọc Selection API gốc của trình duyệt; nếu vẫn không bôi đen ô nào
     *  (chỉ đặt con trỏ, hoặc chọn ngoài bảng) → coi như áp dụng cho CẢ bảng. */
    function getSelectedCells(table) {
        if (cellSel && cellSel.table === table) {
            var fromCellSel = getCellSelCells();
            if (fromCellSel && fromCellSel.length > 1) return fromCellSel;
        }
        var all = Array.prototype.slice.call(table.querySelectorAll('td,th'));
        var sel = window.getSelection();
        if (!sel || sel.rangeCount === 0 || sel.isCollapsed) return all;
        var picked = all.filter(function (c) {
            try { return sel.containsNode(c, true); } catch (e) { return false; }
        });
        return picked.length ? picked : all;
    }

    /* ================================================================
     *  "Thiết lập viền" cho bảng — y hệt modal "Thiết lập gridlines" của Sheets (tái dùng nguyên
     *  CSS .of-border-preview/.obp-line đã có sẵn trong office.css): chọn màu/độ dày/kiểu nét +
     *  vị trí áp dụng (trên/dưới/trái/phải/giữa dọc/giữa ngang) mô phỏng trên khối vuông.
     * ================================================================ */
    function applyTableBorder(table, cells, sides, color, width, style) {
        var rg = cellsToRange(table, cells);
        if (!rg) return;
        var val = width + 'px ' + style + ' ' + color;
        var rows = table.querySelectorAll('tr');
        for (var r = rg.r1; r <= rg.r2; r++) {
            var rowCells = rows[r] ? rows[r].children : [];
            for (var c = rg.c1; c <= rg.c2; c++) {
                var cell = rowCells[c];
                if (!cell) continue;
                if (sides.t && r === rg.r1) cell.style.borderTop = val;
                if (sides.b && r === rg.r2) cell.style.borderBottom = val;
                if (sides.l && c === rg.c1) cell.style.borderLeft = val;
                if (sides.r && c === rg.c2) cell.style.borderRight = val;
                if (sides.vmid && c < rg.c2) cell.style.borderRight = val;
                if (sides.hmid && r < rg.r2) cell.style.borderBottom = val;
            }
        }
        OE.scheduleAutosave();
    }

    function openTableBorderModal(table, cells) {
        var sides = { t: false, b: false, l: false, r: false, vmid: false, hmid: false };
        OE.openModal('Thiết lập viền bảng', '' +
            '<div class="of-border-color-row">Màu viền: <input type="color" id="of-tbb-color" value="#1f2937"></div>' +
            '<div class="of-border-color-row">' +
            '<span>Độ dày:</span><select class="of-tb-select" id="of-tbb-width">' +
            '<option value="2">Mỏng</option><option value="3" selected>Vừa</option><option value="4">Dày</option>' +
            '</select>' +
            '<span>Kiểu nét:</span><select class="of-tb-select" id="of-tbb-style">' +
            '<option value="solid">Liền</option><option value="dashed">Đứt nét</option><option value="dotted">Chấm chấm</option>' +
            '</select>' +
            '</div>' +
            '<div class="of-border-preview" id="of-tbb-preview">' +
            '<div class="obp-cell"></div><div class="obp-cell"></div><div class="obp-cell"></div><div class="obp-cell"></div>' +
            '<div class="obp-line obp-top" data-side="t" title="Viền trên"></div>' +
            '<div class="obp-line obp-bottom" data-side="b" title="Viền dưới"></div>' +
            '<div class="obp-line obp-left" data-side="l" title="Viền trái"></div>' +
            '<div class="obp-line obp-right" data-side="r" title="Viền phải"></div>' +
            '<div class="obp-line obp-vmid" data-side="vmid" title="Đường giữa dọc"></div>' +
            '<div class="obp-line obp-hmid" data-side="hmid" title="Đường giữa ngang"></div>' +
            '</div>' +
            '<div class="of-muted" style="text-align:center;margin-top:6px;">Bấm vào từng cạnh/đường giữa của khối vuông để bật/tắt, rồi bấm Áp dụng.</div>' +
            '<div class="of-backguard-actions">' +
            '<button type="button" class="of-btn" id="of-tbb-cancel">Hủy</button>' +
            '<button type="button" class="of-btn of-btn-primary" id="of-tbb-apply">Áp dụng</button>' +
            '</div>');
        document.querySelectorAll('#of-tbb-preview .obp-line').forEach(function (line) {
            line.addEventListener('click', function () {
                var side = line.dataset.side;
                sides[side] = !sides[side];
                line.classList.toggle('is-on', sides[side]);
            });
        });
        $('#of-tbb-cancel').addEventListener('click', OE.closeModal);
        $('#of-tbb-apply').addEventListener('click', function () {
            var color = $('#of-tbb-color').value;
            var width = parseInt($('#of-tbb-width').value, 10);
            var style = $('#of-tbb-style').value;
            applyTableBorder(table, cells, sides, color, width, style);
            OE.closeModal();
        });
    }

    /* ================================================================
     *  Gộp ô (merge) / Chia ô (split) — dùng colspan/rowspan HTML chuẩn (Word/trình duyệt hiểu
     *  sẵn, xuất file vẫn đúng), KHÔNG tự dựng mô hình lưới riêng. Áp dụng tốt cho bảng đơn giản;
     *  nếu vùng chọn chồng lên 1 ô ĐÃ gộp từ trước, chỉ số hàng/cột dùng chỉ số DOM con thô (giống
     *  cách insertTableRow/Col hiện có đang làm) — không cố tính lại lưới "hình" đầy đủ.
     * ================================================================ */
    function mergeCells(table, cells) {
        var rg = cellsToRange(table, cells);
        if (!rg || (rg.r1 === rg.r2 && rg.c1 === rg.c2)) {
            OE.toast('Chọn ít nhất 2 ô liền nhau để gộp.', false);
            return;
        }
        var rows = table.querySelectorAll('tr');
        var keepCell = rows[rg.r1].children[rg.c1];
        var extraHtml = [];
        for (var r = rg.r1; r <= rg.r2; r++) {
            var rowCells = rows[r] ? Array.prototype.slice.call(rows[r].children) : [];
            for (var c = rg.c1; c <= rg.c2; c++) {
                var cell = rowCells[c];
                if (!cell || cell === keepCell) continue;
                var html = cell.innerHTML.replace(/&nbsp;/g, '').trim();
                if (html) extraHtml.push(html);
                cell.remove();
            }
        }
        keepCell.colSpan = rg.c2 - rg.c1 + 1;
        keepCell.rowSpan = rg.r2 - rg.r1 + 1;
        if (extraHtml.length) {
            var keepHtml = keepCell.innerHTML.replace(/&nbsp;/g, '').trim();
            keepCell.innerHTML = (keepHtml ? keepHtml + ' ' : '') + extraHtml.join(' ');
        }
        clearCellSelUi(table);
        cellSel = null;
        OE.scheduleAutosave();
    }

    function splitCell(cell) {
        var colspan = cell.colSpan || 1, rowspan = cell.rowSpan || 1;
        if (colspan <= 1 && rowspan <= 1) { OE.toast('Ô này chưa được gộp.', false); return; }
        var table = cell.closest('table');
        var tr = cell.parentElement;
        var rowIdx = tableRowIndex(tr);
        var colIdx = cellIndex(cell);
        var rows = table.querySelectorAll('tr');
        var content = cell.innerHTML;
        cell.remove();
        for (var r = 0; r < rowspan; r++) {
            var targetRow = rows[rowIdx + r];
            if (!targetRow) continue;
            for (var c = 0; c < colspan; c++) {
                var newCell = newCellLike(null);
                if (r === 0 && c === 0) newCell.innerHTML = content || '&nbsp;';
                var refCell = targetRow.children[colIdx] || null;
                targetRow.insertBefore(newCell, refCell);
            }
        }
        OE.scheduleAutosave();
    }

    /* ---------------- Kéo giãn cột kiểu Word: chỉ trao đổi độ rộng với cột liền kề ---------------- */
    function freezeColumnWidths(table) {
        var firstRow = table.querySelector('tr');
        if (!firstRow) return [];
        var widths = [];
        Array.prototype.forEach.call(firstRow.children, function (cell, i) {
            var w = cell.getBoundingClientRect().width;
            widths.push(w);
            table.querySelectorAll('tr').forEach(function (tr) { if (tr.children[i]) tr.children[i].style.width = w + 'px'; });
        });
        table.style.tableLayout = 'fixed';
        return widths;
    }
    function freezeRowHeights(table) {
        table.querySelectorAll('tr').forEach(function (tr) { tr.style.height = tr.getBoundingClientRect().height + 'px'; });
    }
    function setColWidth(table, idx, w) {
        table.querySelectorAll('tr').forEach(function (tr) { if (tr.children[idx]) tr.children[idx].style.width = w + 'px'; });
    }

    function bindTableInteractions() {
        var resizing = false;

        container.addEventListener('mousemove', function (e) {
            if (resizing) return;
            var cell = e.target.closest('.of-doc-table td, .of-doc-table th');
            if (!cell) return;
            var rect = cell.getBoundingClientRect();
            var nearRight = (rect.right - e.clientX) <= 6;
            var nearBottom = (rect.bottom - e.clientY) <= 6;
            cell.style.cursor = nearRight ? 'col-resize' : (nearBottom ? 'row-resize' : '');
        });

        container.addEventListener('mousedown', function (e) {
            var cell = e.target.closest('.of-doc-table td, .of-doc-table th');
            if (!cell) return;
            var rect = cell.getBoundingClientRect();
            var nearRight = (rect.right - e.clientX) <= 6;
            var nearBottom = (rect.bottom - e.clientY) <= 6;
            if (!nearRight && !nearBottom) return;
            e.preventDefault();
            var table = cell.closest('table');
            var idx = cellIndex(cell);
            var tr = cell.parentElement;
            var rowIdx = tableRowIndex(tr);
            var startX = e.clientX, startY = e.clientY;

            // Nếu ô đang kéo nằm trong 1 vùng chọn NHIỀU CỘT/DÒNG (cellSel, xem khối bên dưới) ->
            // áp dụng co/giãn ĐỒNG THỜI cho toàn bộ cột/dòng trong vùng đó (giống Sheets — mỗi
            // cột/dòng cộng CÙNG 1 độ dịch chuột vào độ rộng/cao ban đầu của riêng nó), không chỉ
            // riêng cột/dòng đang bấm vào.
            var selCols = null, selRows = null;
            if (cellSel && cellSel.table === table) {
                var r1 = Math.min(cellSel.r1, cellSel.r2), r2 = Math.max(cellSel.r1, cellSel.r2);
                var c1 = Math.min(cellSel.c1, cellSel.c2), c2 = Math.max(cellSel.c1, cellSel.c2);
                if (nearRight && c2 > c1 && idx >= c1 && idx <= c2) {
                    selCols = []; for (var cc = c1; cc <= c2; cc++) selCols.push(cc);
                }
                if (nearBottom && r2 > r1 && rowIdx >= r1 && rowIdx <= r2) {
                    selRows = []; for (var rr = r1; rr <= r2; rr++) selRows.push(rr);
                }
            }

            var widths = nearRight ? freezeColumnWidths(table) : null;
            if (nearBottom) freezeRowHeights(table);
            var startW = widths ? widths[idx] : rect.width;
            var startH = rect.height;
            var totalCols = widths ? widths.length : 0;
            // Kiểu trao đổi mép với cột liền kề chỉ áp dụng khi đang co/giãn 1 cột đơn lẻ — co/giãn
            // nhiều cột đã chọn cùng lúc thì đổi hẳn tổng độ rộng bảng, giống cách Sheets xử lý.
            var hasNext = nearRight && !selCols && (idx + 1) < totalCols;
            var nextStartW = hasNext ? widths[idx + 1] : 0;

            var startWidthsMulti = null, startHeightsMulti = null, rowElsMulti = null;
            if (selCols) {
                startWidthsMulti = {};
                selCols.forEach(function (cc) { startWidthsMulti[cc] = widths[cc]; });
            }
            if (selRows) {
                var allTrs = table.querySelectorAll('tr');
                rowElsMulti = {};
                startHeightsMulti = {};
                selRows.forEach(function (rr) {
                    rowElsMulti[rr] = allTrs[rr];
                    startHeightsMulti[rr] = rowElsMulti[rr] ? rowElsMulti[rr].getBoundingClientRect().height : startH;
                });
            }

            resizing = true;
            function onMove(ev) {
                if (nearRight) {
                    var delta = ev.clientX - startX;
                    if (selCols) {
                        selCols.forEach(function (cc) {
                            setColWidth(table, cc, Math.max(30, startWidthsMulti[cc] + delta));
                        });
                    } else if (hasNext) {
                        // Chỉ trao đổi độ rộng với cột liền kề bên phải — mép ngoài cùng của
                        // bảng (tổng độ rộng) không đổi, không thể tràn khỏi khổ giấy.
                        var maxDelta = nextStartW - 30;   // tối đa lấy từ cột kế bên phải
                        var minDelta = -(startW - 30);    // tối đa thu hẹp cột hiện tại
                        var applied = Math.max(minDelta, Math.min(maxDelta, delta));
                        setColWidth(table, idx, startW + applied);
                        setColWidth(table, idx + 1, nextStartW - applied);
                    } else if (delta < 0) {
                        // Cột cuối cùng (mép ngoài): chỉ cho thu hẹp, không cho mở rộng ra ngoài trang.
                        setColWidth(table, idx, Math.max(30, startW + delta));
                    }
                }
                if (nearBottom) {
                    var deltaY = ev.clientY - startY;
                    if (selRows) {
                        selRows.forEach(function (rr) {
                            if (rowElsMulti[rr]) rowElsMulti[rr].style.height = Math.max(20, startHeightsMulti[rr] + deltaY) + 'px';
                        });
                    } else {
                        var newH = Math.max(20, startH + deltaY);
                        tr.style.height = newH + 'px';
                    }
                }
            }
            function onUp() {
                document.removeEventListener('mousemove', onMove);
                document.removeEventListener('mouseup', onUp);
                resizing = false;
                OE.scheduleAutosave();
            }
            document.addEventListener('mousemove', onMove);
            document.addEventListener('mouseup', onUp);
        });

        // Kéo chuột chọn NHIỀU Ô (kể cả kéo DỌC theo 1 cột) — không tranh chấp với khối resize ở
        // trên vì cùng kiểm tra "sát mép" y hệt và bỏ qua nếu đúng (resize lo phần đó). Click đơn
        // thuần (không kéo sang ô khác) KHÔNG giữ vùng chọn — để con trỏ đặt bình thường như cũ.
        container.addEventListener('mousedown', function (e) {
            if (e.target.closest('.of-table-bar')) return; // nút trên thanh công cụ nổi của bảng
            var cell = e.target.closest('.of-doc-table td, .of-doc-table th');
            if (!cell) {
                if (cellSel) { clearCellSelUi(cellSel.table); cellSel = null; }
                return;
            }
            var rect = cell.getBoundingClientRect();
            var nearRight = (rect.right - e.clientX) <= 6;
            var nearBottom = (rect.bottom - e.clientY) <= 6;
            if (nearRight || nearBottom) return; // sát mép -> để khối resize ở trên xử lý
            var table = cell.closest('table');
            if (cellSel && cellSel.table !== table) clearCellSelUi(cellSel.table);
            var startRow = tableRowIndex(cell.parentElement), startCol = cellIndex(cell);
            var dragging = false;
            function onMove(ev) {
                var overEl = document.elementFromPoint(ev.clientX, ev.clientY);
                var overCell = overEl && overEl.closest && overEl.closest('.of-doc-table td, .of-doc-table th');
                if (!overCell || overCell.closest('table') !== table) return;
                var r2 = tableRowIndex(overCell.parentElement), c2 = cellIndex(overCell);
                if (!dragging && (r2 !== startRow || c2 !== startCol)) {
                    dragging = true;
                    // Đã thật sự sang ô khác -> đây là chọn vùng ô, không phải bôi đen chữ bình
                    // thường trong 1 ô: xoá Selection gốc của trình duyệt để khỏi hiện chồng 2 lớp
                    // tô (viền bôi đen chữ mặc định + nền .is-cellsel tự vẽ).
                    var browserSel = window.getSelection();
                    if (browserSel) browserSel.removeAllRanges();
                }
                if (!dragging) return;
                cellSel = { table: table, r1: startRow, c1: startCol, r2: r2, c2: c2 };
                applyCellSelUi();
            }
            function onUp() {
                document.removeEventListener('mousemove', onMove);
                document.removeEventListener('mouseup', onUp);
                if (!dragging) { clearCellSelUi(table); cellSel = null; }
            }
            document.addEventListener('mousemove', onMove);
            document.addEventListener('mouseup', onUp);
        });

        container.addEventListener('contextmenu', function (e) {
            var cell = e.target.closest('.of-doc-table td, .of-doc-table th');
            var table = e.target.closest('.of-doc-table');
            if (!cell || !table) return;
            e.preventDefault();
            showTableCtx(e.pageX, e.pageY, cell, table);
        });

        container.addEventListener('click', function (e) {
            var exit = e.target.closest('.of-table-exit');
            if (exit) { e.preventDefault(); placeCaretAfter(exit); return; }
            var grip = e.target.closest('.of-table-grip');
            if (grip) {
                e.preventDefault();
                var wrap = grip.closest('.of-table-wrap');
                selectTableWrap(wrap === selectedTableWrap ? null : wrap);
                return;
            }
            var borderBtn = e.target.closest('.of-table-border-btn');
            if (borderBtn) {
                e.preventDefault();
                var tbl1 = borderBtn.closest('.of-table-wrap').querySelector('table');
                var cells1 = (pendingColorCells && pendingColorCells.length) ? pendingColorCells : getSelectedCells(tbl1);
                openTableBorderModal(tbl1, cells1);
                return;
            }
            var mergeBtn = e.target.closest('.of-table-merge-btn');
            if (mergeBtn) {
                e.preventDefault();
                var tbl2 = mergeBtn.closest('.of-table-wrap').querySelector('table');
                var cells2 = (pendingColorCells && pendingColorCells.length) ? pendingColorCells : getSelectedCells(tbl2);
                mergeCells(tbl2, cells2);
                return;
            }
            var splitBtn = e.target.closest('.of-table-split-btn');
            if (splitBtn) {
                e.preventDefault();
                var tbl3 = splitBtn.closest('.of-table-wrap').querySelector('table');
                var cells3 = getSelectedCells(tbl3);
                if (cells3 && cells3.length) splitCell(cells3[0]);
                return;
            }
        });

        // Ô chọn màu / nút viền / nút gộp ô cướp focus ngay khi mousedown (trước khi bảng màu
        // native mở ra, hoặc trước khi modal cướp focus) — phải chụp lại danh sách ô đang bôi đen
        // NGAY LÚC NÀY, trước khi vùng chọn bị mất.
        container.addEventListener('mousedown', function (e) {
            var btn = e.target.closest('.of-table-color-btn, .of-table-border-btn, .of-table-merge-btn');
            if (!btn) return;
            var table = btn.closest('.of-table-wrap').querySelector('table');
            pendingColorCells = getSelectedCells(table);
        });

        container.addEventListener('input', function (e) {
            var bgInput = e.target.closest('.of-table-bg-input');
            if (bgInput) {
                var table1 = bgInput.closest('.of-table-wrap').querySelector('table');
                var cells1 = (pendingColorCells && pendingColorCells.length) ? pendingColorCells : getSelectedCells(table1);
                cells1.forEach(function (c) { c.style.background = bgInput.value; });
                OE.scheduleAutosave();
                return;
            }
        });
    }

    /* ================================================================
     *  ẢNH
     * ================================================================ */
    function imgWrapHtml(url) {
        return '<span class="of-img-wrap" contenteditable="false">' +
            '<img src="' + url + '" style="width:360px;max-width:100%">' +
            '<span class="of-img-del" title="Xóa ảnh"><i class="fa-solid fa-xmark"></i></span>' +
            '<span class="of-img-resize" title="Kéo để đổi cỡ"></span>' +
            '</span>';
    }

    function insertImageFile(file) {
        var fd = new FormData();
        fd.append('file', file);
        fetch('?mod=office&controllers=office&action=upload_image', { method: 'POST', body: fd })
            .then(function (r) { return r.json(); })
            .then(function (j) {
                if (j && j.ok) {
                    restoreSavedRange();
                    if (!savedRange) activePage.focus();
                    document.execCommand('insertHTML', false, imgWrapHtml(j.url));
                    OE.scheduleAutosave();
                } else {
                    OE.toast((j && j.reason) || 'Không chèn được ảnh.', false);
                }
            })
            .catch(function () { OE.toast('Lỗi tải ảnh lên.', false); });
    }

    function selectImgWrap(wrap) {
        if (selectedImgWrap && selectedImgWrap !== wrap) selectedImgWrap.classList.remove('is-selected');
        selectedImgWrap = wrap;
        if (wrap) wrap.classList.add('is-selected');
    }

    function bindImageInteractions() {
        container.addEventListener('mousedown', function (e) {
            var handle = e.target.closest('.of-img-resize');
            if (!handle) return;
            e.preventDefault();
            var wrap = handle.closest('.of-img-wrap');
            var img = wrap.querySelector('img');
            var startX = e.clientX;
            var startW = img.getBoundingClientRect().width;
            function onMove(ev) {
                var newW = Math.max(30, startW + (ev.clientX - startX));
                img.style.width = newW + 'px';
            }
            function onUp() {
                document.removeEventListener('mousemove', onMove);
                document.removeEventListener('mouseup', onUp);
                OE.scheduleAutosave();
            }
            document.addEventListener('mousemove', onMove);
            document.addEventListener('mouseup', onUp);
        });

        container.addEventListener('click', function (e) {
            var delBtn = e.target.closest('.of-img-del');
            if (delBtn) {
                e.preventDefault();
                var w = delBtn.closest('.of-img-wrap');
                if (selectedImgWrap === w) selectedImgWrap = null;
                if (w) w.remove();
                OE.scheduleAutosave();
                return;
            }
            var wrap = e.target.closest('.of-img-wrap');
            if (wrap) { selectImgWrap(wrap); return; }
        });
    }

    /* ================================================================
     *  Bỏ chọn bảng/ảnh khi click ra ngoài, xóa bằng phím Delete/Backspace
     * ================================================================ */
    function bindSelectionClearAndDelete() {
        document.addEventListener('click', function (e) {
            if (selectedTableWrap && !e.target.closest('.of-table-wrap')) selectTableWrap(null);
            if (selectedImgWrap && !e.target.closest('.of-img-wrap')) selectImgWrap(null);
        });
        document.addEventListener('keydown', function (e) {
            if (selectedImgWrap && (e.key === 'Delete' || e.key === 'Backspace')) {
                e.preventDefault();
                selectedImgWrap.remove();
                selectedImgWrap = null;
                OE.scheduleAutosave();
                return;
            }
            if (selectedTableWrap && (e.key === 'Delete' || e.key === 'Backspace')) {
                e.preventDefault();
                selectedTableWrap.remove();
                selectedTableWrap = null;
                OE.scheduleAutosave();
                return;
            }
            if (e.key === 'Escape') { selectTableWrap(null); selectImgWrap(null); }
        });
    }

    /* ================================================================
     *  Cỡ chữ — TỰ bọc vùng đang chọn vào 1 <span style="font-size:..."> mới bằng Range API,
     *  KHÔNG dùng execCommand('fontSize') nữa (đã deprecated, hành vi lồng thẻ không ổn định
     *  giữa các trình duyệt — từng gặp đúng lỗi "đổi cỡ không ăn" khi vùng chọn trùng 1 đoạn đã
     *  có cỡ chữ riêng từ trước, vì execCommand có thể bọc thẻ MỚI ra NGOÀI thẻ CŨ thay vì thay
     *  thế nó, khiến cỡ cũ (nằm sâu hơn, cụ thể hơn) thắng cỡ mới theo luật CSS). Tự làm bằng tay
     *  cho chắc chắn 100% kiểm soát được cấu trúc DOM sinh ra.
     * ================================================================ */
    function applyFontSize(px) {
        applyFormatAction(function () {
            var sel = window.getSelection();
            if (!sel || sel.rangeCount === 0 || sel.isCollapsed) return;
            var range = sel.getRangeAt(0);
            var span = document.createElement('span');
            span.style.fontSize = px + 'px';
            span.appendChild(range.extractContents());
            range.insertNode(span);
            // Xoá cỡ chữ lồng BÊN TRONG span vừa bọc (nếu vùng chọn trùng 1 đoạn đã có cỡ chữ
            // riêng từ trước) để cỡ mới THỰC SỰ hiển thị, không bị con cháu ghi đè theo luật CSS.
            span.querySelectorAll('[style*="font-size"]').forEach(function (inner) { inner.style.fontSize = ''; });
            span.querySelectorAll('font[size]').forEach(function (inner) { inner.removeAttribute('size'); });
            sel.removeAllRanges();
            var newRange = document.createRange();
            newRange.selectNodeContents(span);
            sel.addRange(newRange);
        });
    }

    /* ================================================================
     *  Tìm các phần tử KHỐI (p/h1-h6/li/td/th/blockquote) đang giao với vùng bôi đen — dùng
     *  chung cho "Khoảng cách dòng" và thước kẻ thụt lề (ruler) bên dưới, cả 2 đều cần áp style
     *  (lineHeight/marginLeft/marginRight) trực tiếp lên từng khối vì không có execCommand nào
     *  làm việc này.
     * ================================================================ */
    var BLOCK_SEL = 'p,h1,h2,h3,h4,h5,h6,li,td,th,blockquote';
    function getSelectedBlocks(range) {
        if (!activePage || !range) return [];
        var blocks = Array.prototype.slice.call(activePage.querySelectorAll(BLOCK_SEL))
            .filter(function (el) { return range.intersectsNode(el); });
        if (!blocks.length) {
            // Không bôi đen (con trỏ chỉ đứng 1 chỗ): áp dụng cho khối chứa con trỏ.
            var node = range.startContainer;
            var el = node.nodeType === 1 ? node : node.parentElement;
            var block = el && el.closest(BLOCK_SEL);
            if (block) blocks = [block];
        }
        return blocks;
    }

    /* ================================================================
     *  Thước kẻ thụt lề (ruler) — hiện khi bôi đen văn bản, có 2 tay nắm kéo thụt lề TRÁI/PHẢI
     *  của đoạn đang chọn (chỉ đổi đúng 1 mép, mép kia đứng yên — giống Word/Google Docs). Vị trí
     *  ban đầu 2 tay nắm lấy từ margin-left/margin-right hiện có của khối ĐẦU TIÊN trong vùng
     *  chọn ("mép của dòng đầu tiên" — đúng yêu cầu), kéo xong áp CÙNG 1 giá trị cho MỌI khối
     *  đang được chọn ("nguyên khối bôi đen").
     *  Để thước luôn khớp chính xác với lề trang (canh lề ở "Điều chỉnh canh lề") mà KHÔNG cần đo
     *  toạ độ qua getBoundingClientRect(): .of-ruler-track định vị bằng chính `left/right: Xcm`
     *  (docMargin) bên trong .of-ruler — 2 phần tử có cùng width/margin:auto như .of-doc-page nên
     *  browser tự tính ra đúng cùng 1 độ rộng nội dung bằng con số cm giống hệt padding của trang.
     * ================================================================ */
    var rulerEl = null, rulerTrackEl = null, rulerLeftHandle = null, rulerRightHandle = null;
    var rulerBlocks = null; // các khối đang áp dụng khi kéo — chụp lại lúc bắt đầu kéo (mousedown)
    var RULER_MIN_GAP = 24; // px — khoảng cách tối thiểu còn lại giữa 2 tay nắm

    function syncRulerMargin() {
        if (!rulerTrackEl) return;
        rulerTrackEl.style.left = docMargin.left + 'cm';
        rulerTrackEl.style.right = docMargin.right + 'cm';
    }

    /** Thước LUÔN hiện (giống Word thật — không chỉ hiện khi bôi đen) — hàm này giờ chỉ còn lo
     *  cập nhật ĐÚNG vị trí 2 tay nắm theo khối đang chứa con trỏ/vùng đang chọn, không còn tự ẩn
     *  hiện gì nữa. Chỉ ẩn hẳn 1 lần ở init() khi tài liệu không có quyền sửa (kéo thụt lề vô
     *  nghĩa lúc chỉ xem). Selection collapsed (chỉ đặt con trỏ, không bôi đen) vẫn cập nhật theo
     *  khối chứa con trỏ — getSelectedBlocks() đã tự lo phần rơi về "khối chứa con trỏ" này. */
    function updateRuler() {
        if (!rulerEl || !OE.canEdit) return;
        var s = window.getSelection();
        if (!s || s.rangeCount === 0) return;
        var range = s.getRangeAt(0);
        if (!activePage || !activePage.contains(range.commonAncestorContainer)) return;
        var blocks = getSelectedBlocks(range);
        if (!blocks.length) return;
        var first = blocks[0];
        rulerLeftHandle.style.left = (parseFloat(first.style.marginLeft) || 0) + 'px';
        rulerRightHandle.style.right = (parseFloat(first.style.marginRight) || 0) + 'px';
    }

    function bindRulerDrag(handle, side) {
        handle.addEventListener('mousedown', function (e) {
            e.preventDefault();
            e.stopPropagation(); // không để mousedown lọt xuống trang (mất selection đang bôi đen)
            var s = window.getSelection();
            if (!s || s.rangeCount === 0) return;
            rulerBlocks = getSelectedBlocks(s.getRangeAt(0));
            if (!rulerBlocks.length) return;
            var trackWidth = rulerTrackEl.clientWidth; // .of-ruler-track không có padding riêng -> đúng bằng độ rộng nội dung trang
            var startX = e.clientX;
            var otherPx = parseFloat((side === 'left' ? rulerRightHandle.style.right : rulerLeftHandle.style.left)) || 0;
            var startPx = parseFloat((side === 'left' ? rulerLeftHandle.style.left : rulerRightHandle.style.right)) || 0;
            var max = Math.max(0, trackWidth - otherPx - RULER_MIN_GAP);
            function onMove(ev) {
                var delta = ev.clientX - startX;
                var val = side === 'left' ? startPx + delta : startPx - delta;
                val = Math.max(0, Math.min(max, val));
                if (side === 'left') {
                    rulerLeftHandle.style.left = val + 'px';
                    rulerBlocks.forEach(function (b) { b.style.marginLeft = val + 'px'; });
                } else {
                    rulerRightHandle.style.right = val + 'px';
                    rulerBlocks.forEach(function (b) { b.style.marginRight = val + 'px'; });
                }
            }
            function onUp() {
                document.removeEventListener('mousemove', onMove);
                document.removeEventListener('mouseup', onUp);
                rulerBlocks = null;
                OE.scheduleAutosave();
            }
            document.addEventListener('mousemove', onMove);
            document.addEventListener('mouseup', onUp);
        });
    }

    /* ================================================================
     *  Khoảng cách dòng (không có execCommand cho line-height — tự tìm các
     *  phần tử khối giao với vùng đang bôi đen rồi đặt style.lineHeight
     *  trực tiếp, giống thủ thuật cỡ chữ trên).
     * ================================================================ */
    function applyLineHeight(value) {
        var s = window.getSelection();
        if (!s || !s.rangeCount) return;
        getSelectedBlocks(s.getRangeAt(0)).forEach(function (b) { b.style.lineHeight = value; });
    }

    /* ================================================================
     *  Nhận diện B/I/U/... đang bật tại vị trí con trỏ
     * ================================================================ */
    /** Đồng bộ lại 2 ô chọn "Cỡ chữ"/"Phông chữ" trên toolbar theo ĐÚNG định dạng thật tại vị
     *  trí con trỏ — trước đây 2 ô này chỉ đổi khi TỰ TAY chọn, không bao giờ tự cập nhật lại
     *  khi tải trang/di chuyển con trỏ, nên dù văn bản đã lưu đúng cỡ 13 (style.fontSize thật
     *  trong nội dung HTML lưu xuống), ô chọn trên toolbar vẫn hiện "15" mặc định như chưa từng
     *  đổi gì — KHÔNG PHẢI cỡ chữ áp dụng bị lưu sai, chỉ là UI hiển thị không đọc lại. Cỡ chữ
     *  dùng thủ thuật <font size=7> -> style.fontSize (xem applyFontSize()); phông chữ dùng
     *  execCommand('fontName') thẳng, browser giữ nguyên dạng thẻ cũ <font face="...">. */
    function updateFontControls() {
        var s = window.getSelection();
        if (!s || s.rangeCount === 0 || !activePage) return;
        var node = s.getRangeAt(0).startContainer;
        var el = node.nodeType === 1 ? node : node.parentElement;
        if (!el || !activePage.contains(el)) return;

        // Đọc cỡ chữ THẬT SỰ đang hiển thị qua getComputedStyle (không chỉ dò style gắn tay) —
        // kể cả đoạn văn chưa từng đổi cỡ (kế thừa 15px mặc định từ .of-doc-page) vẫn phải khớp
        // đúng "15" ở đây. Nếu chỉ dò style gắn tay, đoạn văn mặc định sẽ bị BỎ QUA hoàn toàn,
        // để ô chọn kẹt nguyên giá trị cũ còn sót từ lần bôi đen trước — lỡ đúng bằng giá trị
        // user sắp chọn thì trình duyệt không bắn sự kiện 'change' (chọn lại y hệt giá trị đang
        // hiện = không đổi gì), khiến người dùng bôi đen rồi chọn cỡ chữ nhưng "không ăn".
        var fsSelect = $('#of-tb-fontsize');
        if (fsSelect) {
            var px = Math.round(parseFloat(window.getComputedStyle(el).fontSize));
            if (!isNaN(px) && fsSelect.querySelector('option[value="' + px + '"]')) fsSelect.value = String(px);
        }

        // Tương tự cỡ chữ: dùng getComputedStyle (lấy đúng font ĐANG HIỂN THỊ, kể cả đoạn văn
        // chưa từng đổi font — kế thừa font mặc định của trang) thay vì chỉ dò thẻ <font face>
        // gắn tay, để tránh y hệt bug "kẹt giá trị cũ, chọn lại không bắn 'change'" ở trên.
        var fontSelect = $('#of-tb-font');
        if (fontSelect) {
            var famRaw = window.getComputedStyle(el).fontFamily || '';
            var fam = famRaw.split(',')[0].trim().replace(/^['"]|['"]$/g, '');
            if (fam && fontSelect.querySelector('option[value="' + fam + '"]')) fontSelect.value = fam;
        }
    }

    function bindToolbarState(toolbar) {
        function update() {
            STATE_CMDS.forEach(function (cmd) {
                var btn = toolbar.querySelector('[data-cmd="' + cmd + '"]');
                if (!btn) return;
                var active = false;
                try { active = document.queryCommandState(cmd); } catch (e) {}
                btn.classList.toggle('is-active', active);
            });
            updateRuler();
            updateFontControls();
            // Luôn giữ savedRange MỚI NHẤT trong lúc user còn đang bôi đen trong trang (không chỉ
            // chụp ĐÚNG lúc mousedown vào toolbar) — với riêng <select>/<input type=color>
            // (khác hẳn <button>), 1 số trình duyệt có thể tự dời focus/xoá Selection NGAY khi
            // mở dropdown gốc của hệ điều hành, đôi khi trước cả khi listener mousedown ở toolbar
            // kịp chạy, khiến saveCurrentRange() lúc đó chụp phải Selection ĐÃ RỖNG (savedRange
            // = null) → restoreSavedRange() sau đó thành no-op → cỡ chữ/phông chữ "đổi không ăn"
            // dù đã bôi đen. Cập nhật liên tục ở đây (mỗi lần bôi đen thay đổi) đảm bảo LUÔN có
            // sẵn 1 bản chụp hợp lệ, gần nhất có thể, không phụ thuộc timing đúng lúc bấm chuột.
            saveCurrentRange();
        }
        document.addEventListener('selectionchange', function () {
            if (pages.indexOf(document.activeElement) !== -1) update();
        });
        container.addEventListener('keyup', update);
        container.addEventListener('mouseup', update);
        container.addEventListener('focusin', update);
    }

    function init() {
        container = $('#of-doc-editor');
        var toolbar = $('#of-doc-toolbar');
        if (!container || !toolbar) return;

        toolbar.innerHTML = TOOLBAR_HTML;
        if (!OE.canEdit) {
            Array.prototype.slice.call(toolbar.querySelectorAll('button,select')).forEach(function (b) { b.disabled = true; });
        }

        rulerEl = $('#of-doc-ruler');
        rulerTrackEl = $('#of-doc-ruler-track');
        rulerLeftHandle = $('#of-doc-ruler-left');
        rulerRightHandle = $('#of-doc-ruler-right');
        if (!OE.canEdit && rulerEl) rulerEl.style.display = 'none'; // chỉ xem: ẩn hẳn, kéo thụt lề vô nghĩa
        if (rulerLeftHandle) bindRulerDrag(rulerLeftHandle, 'left');
        if (rulerRightHandle) bindRulerDrag(rulerRightHandle, 'right');

        loadPages(OE.doc && OE.doc.content);

        OE.registerContentGetter(serializePages);
        OE.registerContentSetter(loadPages);
        OE.registerIsEditingCheck(function () { return pages.indexOf(document.activeElement) !== -1; });

        container.addEventListener('focusin', function (e) {
            var p = e.target.closest('.of-doc-page');
            if (p) activePage = p;
        });

        container.addEventListener('input', function () { OE.scheduleAutosave(); });
        container.addEventListener('paste', function () {
            setTimeout(function () { OE.scheduleAutosave(); }, 0);
        });
        container.addEventListener('keydown', function (e) {
            // Ctrl+B/I/U — hầu hết trình duyệt đã tự làm việc này trong contentEditable, nhưng tự
            // bắt rõ ràng ở đây để chắc chắn nhất quán với nút toolbar (cùng gọi execCommand +
            // scheduleAutosave ngay, không phụ thuộc hành vi mặc định có thể khác nhau giữa các
            // trình duyệt/ngữ cảnh — vd khi đang ở trong bảng lồng contenteditable).
            if (OE.canEdit && (e.ctrlKey || e.metaKey) && !e.altKey && !e.shiftKey) {
                var k = e.key.toLowerCase();
                if (k === 'b' || k === 'i' || k === 'u') {
                    e.preventDefault();
                    var cmdBIU = k === 'b' ? 'bold' : k === 'i' ? 'italic' : 'underline';
                    applyFormatAction(function () { document.execCommand(cmdBIU, false, null); });
                    OE.scheduleAutosave();
                    return;
                }
            }
            // Tab — mặc định trình duyệt sẽ chuyển focus RA KHỎI trang (mất hẳn vùng soạn thảo),
            // rất khó chịu. Trong Ô BẢNG: nhảy sang ô kế tiếp (Shift+Tab lùi lại), giống Word/
            // Excel — querySelectorAll('td,th') liệt kê theo ĐÚNG thứ tự tài liệu nên tự "tràn"
            // sang đầu dòng kế tiếp khi hết dòng, không cần tính lại chỉ số hàng/cột thủ công.
            // Ngoài bảng: chèn 1 khoảng thụt (2× "em space", đủ rõ để thấy "nhảy 1 khúc" mà vẫn
            // gọn — không dùng ký tự tab thật vì trình duyệt/</> HTML không hiển thị thống nhất).
            if (OE.canEdit && e.key === 'Tab') {
                var sTab = window.getSelection();
                var nodeTab = sTab && sTab.rangeCount ? sTab.getRangeAt(0).startContainer : null;
                var tdTab = nodeTab && (nodeTab.nodeType === 1 ? nodeTab.closest('td,th') : (nodeTab.parentElement && nodeTab.parentElement.closest('td,th')));
                e.preventDefault();
                if (tdTab) {
                    var tableTab = tdTab.closest('table');
                    var allCells = Array.prototype.slice.call(tableTab.querySelectorAll('td,th'));
                    var nextIdx = allCells.indexOf(tdTab) + (e.shiftKey ? -1 : 1);
                    if (nextIdx >= 0 && nextIdx < allCells.length) {
                        var rTab = document.createRange();
                        rTab.selectNodeContents(allCells[nextIdx]);
                        rTab.collapse(true);
                        sTab.removeAllRanges();
                        sTab.addRange(rTab);
                    }
                    return;
                }
                if (!e.shiftKey) document.execCommand('insertHTML', false, '&emsp;&emsp;');
                OE.scheduleAutosave();
                return;
            }
            if (OE.canEdit && e.ctrlKey && e.key === 'Enter') {
                e.preventDefault();
                insertNewPageAfter(activePage);
                OE.scheduleAutosave();
                return;
            }
            // Alt+Enter TRONG 1 Ô BẢNG: xuống dòng NGAY TẠI ô đang gõ (chèn <br>), không phải
            // thoát bảng/xuống trang mới — kiểm tra và return SỚM, TRƯỚC nhánh Enter thường bên
            // dưới, vì nhánh đó vốn không loại trừ altKey nên nếu để lọt xuống, Alt+Enter ở đúng
            // Ô CUỐI CÙNG của bảng sẽ bị hiểu nhầm thành "thoát bảng" thay vì xuống dòng trong ô.
            if (OE.canEdit && e.key === 'Enter' && e.altKey) {
                var sAlt = window.getSelection();
                if (sAlt && sAlt.rangeCount) {
                    var nodeAlt = sAlt.getRangeAt(0).startContainer;
                    var tdAlt = nodeAlt.nodeType === 1 ? nodeAlt.closest('td,th') : (nodeAlt.parentElement && nodeAlt.parentElement.closest('td,th'));
                    if (tdAlt) {
                        e.preventDefault();
                        document.execCommand('insertHTML', false, '<br>');
                        OE.scheduleAutosave();
                        return;
                    }
                }
            }
            // Enter khi con trỏ đang ở Ô CUỐI CÙNG (dòng cuối, cột cuối) của 1 bảng: thoát ra
            // đoạn văn NGAY SAU bảng thay vì mặc định thêm dòng mới BÊN TRONG ô đó — đây chính
            // là nguyên nhân cảm giác "xuống dòng kéo theo bảng" (bảng phình ra) mà user báo.
            // Các ô KHÁC (không phải ô cuối) vẫn giữ hành vi mặc định: Enter xuống dòng trong ô.
            if (OE.canEdit && e.key === 'Enter' && !e.shiftKey && !e.ctrlKey && !e.altKey) {
                var s = window.getSelection();
                if (s && s.rangeCount) {
                    var node = s.getRangeAt(0).startContainer;
                    var td = node.nodeType === 1 ? node.closest('td,th') : (node.parentElement && node.parentElement.closest('td,th'));
                    if (td) {
                        var tr = td.parentElement;
                        var isLastRow = !tr.nextElementSibling;
                        var isLastCell = !td.nextElementSibling;
                        if (isLastRow && isLastCell) {
                            e.preventDefault();
                            var wrap = td.closest('.of-table-wrap');
                            var marker = wrap.nextElementSibling; // .of-table-exit (bảng mới) hoặc <p> thẳng (bảng cũ)
                            if (marker) placeCaretAfter(marker.classList && marker.classList.contains('of-table-exit') ? marker : wrap);
                            OE.scheduleAutosave();
                        }
                    }
                }
            }
        });

        // Bất kỳ điều khiển nào trên toolbar (nút/select/ô màu) đều cướp focus khỏi trang/bảng
        // NGAY khi mousedown (trước khi 'click'/'change'/'input' kịp xử lý) — chụp lại vùng
        // chọn tại đây (còn nguyên vẹn) để khôi phục đúng chỗ trước khi áp dụng lệnh, nếu không
        // thao tác trên vùng đang bôi đen trong Ô BẢNG (hoặc bất kỳ đâu) sẽ bị mất/không áp dụng.
        toolbar.addEventListener('mousedown', function (e) {
            if (e.target.closest('.of-tb-btn, .of-tb-select, .of-tb-color')) saveCurrentRange();
        });

        toolbar.addEventListener('click', function (e) {
            var btn = e.target.closest('.of-tb-btn');
            if (!btn) return;
            if (btn.dataset.act !== 'table' && btn.dataset.act !== 'image') {
                restoreSavedRange();
                if (!savedRange) activePage.focus();
            }
            if (btn.dataset.cmd) {
                var cmd = btn.dataset.cmd;
                applyFormatAction(function () { document.execCommand(cmd, false, null); });
            } else if (btn.dataset.act === 'link') {
                var url = prompt('Địa chỉ liên kết (URL):', 'https://');
                if (url) applyFormatAction(function () { document.execCommand('createLink', false, url); });
            } else if (btn.dataset.act === 'table') {
                openInsertTableModal();
                return;
            } else if (btn.dataset.act === 'image') {
                saveCurrentRange();
                var input = document.createElement('input');
                input.type = 'file'; input.accept = 'image/*';
                input.addEventListener('change', function () {
                    if (input.files[0]) insertImageFile(input.files[0]);
                });
                input.click();
                return;
            } else if (btn.dataset.act === 'pagebreak') {
                insertNewPageAfter(activePage);
            } else if (btn.dataset.act === 'margin') {
                openMarginModal();
                return;
            } else if (btn.dataset.act === 'savever') {
                var note = prompt('Ghi chú cho phiên bản này (không bắt buộc):', '') || '';
                OE.save(true, note);
                return;
            }
            OE.scheduleAutosave();
        });

        var blockSelect = $('#of-tb-block');
        if (blockSelect) blockSelect.addEventListener('change', function () {
            restoreSavedRange();
            if (!savedRange) activePage.focus();
            applyFormatAction(function () { document.execCommand('formatBlock', false, blockSelect.value); });
            OE.scheduleAutosave();
        });

        var fontSelect = $('#of-tb-font');
        if (fontSelect) fontSelect.addEventListener('change', function () {
            restoreSavedRange();
            if (!savedRange) activePage.focus();
            applyFormatAction(function () { document.execCommand('fontName', false, fontSelect.value); });
            OE.scheduleAutosave();
        });

        var fontSizeSelect = $('#of-tb-fontsize');
        if (fontSizeSelect) fontSizeSelect.addEventListener('change', function () {
            restoreSavedRange();
            if (!savedRange) activePage.focus();
            applyFontSize(fontSizeSelect.value);
            OE.scheduleAutosave();
        });

        var lineHeightSelect = $('#of-tb-lineheight');
        if (lineHeightSelect) lineHeightSelect.addEventListener('change', function () {
            restoreSavedRange();
            if (!savedRange) activePage.focus();
            var val = lineHeightSelect.value;
            lineHeightSelect.value = '';
            if (val === '__custom') {
                var custom = prompt('Nhập khoảng cách dòng (ví dụ: 1.75):', '');
                if (custom === null || custom.trim() === '') return;
                var num = parseFloat(custom.replace(',', '.'));
                if (isNaN(num) || num <= 0) { OE.toast('Giá trị không hợp lệ.', false); return; }
                applyLineHeight(num);
            } else if (val) {
                applyLineHeight(val);
            } else {
                return;
            }
            OE.scheduleAutosave();
        });

        var colorInput = $('#of-tb-color');
        if (colorInput) colorInput.addEventListener('input', function () {
            restoreSavedRange();
            if (!savedRange) activePage.focus();
            applyFormatAction(function () { document.execCommand('foreColor', false, colorInput.value); });
            OE.scheduleAutosave();
        });

        bindTableInteractions();
        bindImageInteractions();
        bindSelectionClearAndDelete();
        bindToolbarState(toolbar);
        OE.initChrome();
    }

    document.addEventListener('DOMContentLoaded', init);
})();
