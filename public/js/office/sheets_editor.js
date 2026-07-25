/* =====================================================================
 *  OFFICE — sheets_editor.js
 *  Bảng tính tự viết: cuộn ngang/dọc, thêm/xóa/kéo-giãn dòng-cột (kiểu
 *  Word: chỉ đổi đúng cột/dòng đang kéo, không co cột khác), click số
 *  dòng/chữ cột để chọn cả dòng/cột, copy/paste, định dạng cơ bản (đậm/
 *  nghiêng/gạch chân/màu/căn lề/cỡ chữ/định dạng số), công thức (SUM/
 *  AVERAGE/COUNT/MIN/MAX/IF/SUMIF/COUNTIF/VLOOKUP/HLOOKUP + số học),
 *  biểu đồ cột/tròn/đường (SVG, đọc trực tiếp dữ liệu trên lưới). Lưu
 *  qua nút "Lưu" (OfficeEditor.save) — xem public/js/office/office_common.js.
 * ===================================================================== */
(function () {
    'use strict';

    var OE = window.OfficeEditor;
    if (!OE) return;

    function $(s, r) { return (r || document).querySelector(s); }

    /** Ép về OBJECT (map khóa chuỗi) một cách an toàn — KHÔNG được viết `x || {}` ở đây: một
     *  mảng JS rỗng `[]` vẫn là truthy nên `[] || {}` cho ra `[]`, và JSON.stringify() trên
     *  mảng sẽ ÂM THẦM BỎ MẤT mọi khóa không phải số (vd "A1") khi lưu — đã xảy ra thật với dữ
     *  liệu cũ (server từng trả "[]" thay vì "{}" cho colWidths/rowHeights/cells mặc định).
     *  Hàm này vừa phòng ngừa vừa "chữa" dữ liệu cũ bị lưu nhầm dạng mảng: nếu gặp mảng, giữ
     *  lại các phần tử KHÁC null/undefined theo đúng chỉ số của chúng. */
    function toObjMap(v) {
        if (Array.isArray(v)) {
            var o = {};
            v.forEach(function (val, i) { if (val !== null && val !== undefined) o[i] = val; });
            return o;
        }
        return (v && typeof v === 'object') ? v : {};
    }

    var sheet = { cols: 12, rows: 30, colWidths: {}, rowHeights: {}, cells: {}, charts: [], hideGridlines: false };
    var sel = null;      // {r1,c1,r2,c2} vùng chọn (r,c: 1-based row, 0-based col)
    var editingRef = null;
    var clipboard = null;
    var clipRange = null;  // {r1,c1,r2,c2} vùng vừa Ctrl+C/Ctrl+X — hiển thị viền nét đứt cho tới khi dán/Esc
    var clipIsCut = false; // true nếu vùng trên là do Ctrl+X (dán xong sẽ XOÁ vùng nguồn, tức "di chuyển")
    var chartsVisible = false;
    var formulaRangeAnchor = null; // {row,col} ô bắt đầu kéo khi đang chèn vùng vào công thức
    var formulaInsertRange = null; // {start,end} vị trí ký tự vừa chèn trong #of-formula-input (để kéo mở rộng)

    /* ---------------- Địa chỉ ô ---------------- */
    function colLetterSimple(c) {
        var s = '', n = c;
        do { s = String.fromCharCode(65 + (n % 26)) + s; n = Math.floor(n / 26) - 1; } while (n >= 0);
        return s;
    }
    function ref(c, r) { return colLetterSimple(c) + r; }
    function parseRef(a1) {
        var m = /^([A-Z]+)([0-9]+)$/i.exec(a1 || '');
        if (!m) return null;
        var letters = m[1].toUpperCase();
        var col = 0;
        for (var i = 0; i < letters.length; i++) col = col * 26 + (letters.charCodeAt(i) - 64);
        return { col: col - 1, row: parseInt(m[2], 10) };
    }
    function parseRangeArg(rangeStr) {
        var m = /^([A-Z]+[0-9]+):([A-Z]+[0-9]+)$/i.exec((rangeStr || '').trim());
        if (!m) return null;
        var pa = parseRef(m[1].toUpperCase()), pb = parseRef(m[2].toUpperCase());
        return { c1: Math.min(pa.col, pb.col), c2: Math.max(pa.col, pb.col), r1: Math.min(pa.row, pb.row), r2: Math.max(pa.row, pb.row) };
    }

    /* ---------------- Công thức ---------------- */
    var computedCache = {}, computingSet = {};

    function rawOf(a1) { return sheet.cells[a1] ? sheet.cells[a1].v : ''; }

    function splitTopLevel(str, sep) {
        var out = [], depth = 0, cur = '';
        for (var i = 0; i < str.length; i++) {
            var ch = str[i];
            if (ch === '(') depth++;
            if (ch === ')') depth--;
            if (ch === sep && depth === 0) { out.push(cur); cur = ''; } else cur += ch;
        }
        out.push(cur);
        return out;
    }

    function numOf(a1) {
        var v = computeCell(a1);
        var n = parseFloat(v);
        return isNaN(n) ? 0 : n;
    }

    function expandRangeRefs(a, b) {
        var pa = parseRef(a), pb = parseRef(b);
        if (!pa || !pb) return [];
        var out = [];
        for (var r = Math.min(pa.row, pb.row); r <= Math.max(pa.row, pb.row); r++) {
            for (var c = Math.min(pa.col, pb.col); c <= Math.max(pa.col, pb.col); c++) out.push(ref(c, r));
        }
        return out;
    }

    function expandArgsToNumbers(argsStr) {
        var parts = splitTopLevel(argsStr, ',').map(function (s) { return s.trim(); }).filter(function (s) { return s !== ''; });
        var vals = [];
        parts.forEach(function (p) {
            var rangeM = /^([A-Z]+[0-9]+):([A-Z]+[0-9]+)$/i.exec(p);
            if (rangeM) {
                expandRangeRefs(rangeM[1].toUpperCase(), rangeM[2].toUpperCase()).forEach(function (r) { vals.push(numOf(r)); });
            } else if (/^[A-Z]+[0-9]+$/i.test(p)) {
                vals.push(numOf(p.toUpperCase()));
            } else {
                var n = parseFloat(p);
                if (!isNaN(n)) vals.push(n);
            }
        });
        return vals;
    }

    var SAFE_EXPR = /^[0-9+\-*/(). ]*$/;

    function substituteRefs(expr) {
        return expr.replace(/\$?([A-Z]+)\$?([0-9]+)/gi, function (m, col, row) {
            var a1 = col.toUpperCase() + row;
            return String(numOf(a1));
        });
    }

    function evalArithmetic(expr) {
        var sub = substituteRefs(expr);
        if (!SAFE_EXPR.test(sub)) return '#ERR';
        try {
            // eslint-disable-next-line no-new-func
            var v = Function('"use strict"; return (' + (sub.trim() === '' ? '0' : sub) + ')')();
            return typeof v === 'number' && isFinite(v) ? v : '#ERR';
        } catch (e) { return '#ERR'; }
    }

    function evalIf(inner) {
        var parts = splitTopLevel(inner, ',');
        if (parts.length < 2) return '#ERR';
        var condRaw = substituteRefs(parts[0]);
        var condExpr = condRaw.replace(/([<>]=?|==|!=)/, ' $1 ');
        if (!/^[0-9+\-*/(). <>=!]*$/.test(condExpr)) return '#ERR';
        var cond;
        try { cond = Function('"use strict"; return (' + condExpr + ')')(); } catch (e) { cond = false; }
        var branch = cond ? parts[1] : (parts[2] !== undefined ? parts[2] : '""');
        branch = branch.trim();
        if (/^[A-Z]+[0-9]+$/i.test(branch)) return computeCell(branch.toUpperCase());
        if (/^".*"$/.test(branch)) return branch.slice(1, -1);
        return evalArithmetic(branch);
    }

    /** So khớp kiểu Excel: hỗ trợ toán tử so sánh trong chuỗi (">10", "<=5", "<>0") hoặc so khớp trực tiếp. */
    function matchCriteria(value, criteria) {
        criteria = String(criteria == null ? '' : criteria).trim();
        var m = /^(<=|>=|<>|=|<|>)(.*)$/.exec(criteria);
        if (m) {
            var op = m[1], rhsRaw = m[2].trim();
            var rhsNum = parseFloat(rhsRaw), lhsNum = parseFloat(value);
            if (!isNaN(rhsNum) && !isNaN(lhsNum)) {
                switch (op) {
                    case '=': return lhsNum === rhsNum;
                    case '<>': return lhsNum !== rhsNum;
                    case '<': return lhsNum < rhsNum;
                    case '>': return lhsNum > rhsNum;
                    case '<=': return lhsNum <= rhsNum;
                    case '>=': return lhsNum >= rhsNum;
                }
            }
            if (op === '=') return String(value).toLowerCase() === rhsRaw.toLowerCase();
            if (op === '<>') return String(value).toLowerCase() !== rhsRaw.toLowerCase();
            return false;
        }
        var cNum = parseFloat(criteria), vNum = parseFloat(value);
        if (!isNaN(cNum) && !isNaN(vNum)) return vNum === cNum;
        return String(value).toLowerCase() === criteria.toLowerCase();
    }

    function stripQuotes(s) { return s.replace(/^"(.*)"$/, '$1'); }

    function evalSumif(argsStr) {
        var parts = splitTopLevel(argsStr, ',').map(function (s) { return s.trim(); });
        if (parts.length < 2) return '#ERR';
        var range = parseRangeArg(parts[0]);
        if (!range) return '#ERR';
        var criteria = stripQuotes(parts[1]);
        var sumRange = parts[2] ? parseRangeArg(parts[2]) : null;
        if (!sumRange) sumRange = range;
        var total = 0;
        for (var r = range.r1; r <= range.r2; r++) {
            for (var c = range.c1; c <= range.c2; c++) {
                var val = computeCell(ref(c, r));
                if (matchCriteria(val, criteria)) {
                    var sr = sumRange.r1 + (r - range.r1);
                    var sc = sumRange.c1 + (c - range.c1);
                    total += numOf(ref(sc, sr));
                }
            }
        }
        return total;
    }

    function evalCountif(argsStr) {
        var parts = splitTopLevel(argsStr, ',').map(function (s) { return s.trim(); });
        if (parts.length < 2) return '#ERR';
        var range = parseRangeArg(parts[0]);
        if (!range) return '#ERR';
        var criteria = stripQuotes(parts[1]);
        var count = 0;
        for (var r = range.r1; r <= range.r2; r++) {
            for (var c = range.c1; c <= range.c2; c++) {
                if (matchCriteria(computeCell(ref(c, r)), criteria)) count++;
            }
        }
        return count;
    }

    function resolveLookupValue(raw) {
        raw = stripQuotes(raw.trim());
        if (/^[A-Z]+[0-9]+$/i.test(raw)) return computeCell(raw.toUpperCase());
        var n = parseFloat(raw);
        return isNaN(n) ? raw : n;
    }
    function valuesMatch(a, b) {
        var an = parseFloat(a), bn = parseFloat(b);
        if (!isNaN(an) && !isNaN(bn)) return an === bn;
        return String(a).toLowerCase() === String(b).toLowerCase();
    }

    function evalVlookup(argsStr) {
        var parts = splitTopLevel(argsStr, ',').map(function (s) { return s.trim(); });
        if (parts.length < 3) return '#ERR';
        var lookupVal = resolveLookupValue(parts[0]);
        var range = parseRangeArg(parts[1]);
        if (!range) return '#ERR';
        var colIdx = Math.round(parseFloat(substituteRefs(parts[2]))) - 1;
        if (isNaN(colIdx) || colIdx < 0) return '#ERR';
        for (var r = range.r1; r <= range.r2; r++) {
            if (valuesMatch(computeCell(ref(range.c1, r)), lookupVal)) {
                return computeCell(ref(range.c1 + colIdx, r));
            }
        }
        return '#N/A';
    }

    function evalHlookup(argsStr) {
        var parts = splitTopLevel(argsStr, ',').map(function (s) { return s.trim(); });
        if (parts.length < 3) return '#ERR';
        var lookupVal = resolveLookupValue(parts[0]);
        var range = parseRangeArg(parts[1]);
        if (!range) return '#ERR';
        var rowIdx = Math.round(parseFloat(substituteRefs(parts[2]))) - 1;
        if (isNaN(rowIdx) || rowIdx < 0) return '#ERR';
        for (var c = range.c1; c <= range.c2; c++) {
            if (valuesMatch(computeCell(ref(c, range.r1)), lookupVal)) {
                return computeCell(ref(c, range.r1 + rowIdx));
            }
        }
        return '#N/A';
    }

    /** Tính giá trị hiển thị của 1 ô (đệ quy theo công thức, có bảo vệ vòng lặp). */
    function computeCell(a1) {
        if (Object.prototype.hasOwnProperty.call(computedCache, a1)) return computedCache[a1];
        if (computingSet[a1]) { return '#CYCLE!'; }
        var raw = rawOf(a1);
        // Ô bị ép kiểu CHUỖI (gõ dấu ' ở đầu, kiểu Excel — xem commitEdit): giữ nguyên chuỗi,
        // KHÔNG parseFloat (nếu không "01" sẽ hiện thành số 1, mất số 0 đứng đầu) và cũng không
        // coi là công thức dù bắt đầu bằng "=".
        var forcedText = !!(sheet.cells[a1] && sheet.cells[a1].t === 'text');
        if (forcedText || typeof raw !== 'string' || raw.trim().charAt(0) !== '=') {
            var val;
            if (forcedText) val = raw;
            else { var n = parseFloat(raw); val = raw === '' || raw === undefined ? '' : (isNaN(n) ? raw : n); }
            computedCache[a1] = val;
            return val;
        }
        computingSet[a1] = true;
        var formula = raw.trim().slice(1).trim();
        var result;
        // IF/VLOOKUP/HLOOKUP có thể trả về CHUỖI (không chỉ số) → phải xử lý riêng khi cả công
        // thức CHỈ LÀ 1 lệnh gọi hàm này (không nhúng trong biểu thức số học khác).
        var bareM = /^(IF|VLOOKUP|HLOOKUP)\((.*)\)$/i.exec(formula);
        if (bareM) {
            var fnName = bareM[1].toUpperCase();
            if (fnName === 'IF') result = evalIf(bareM[2]);
            else if (fnName === 'VLOOKUP') result = evalVlookup(bareM[2]);
            else result = evalHlookup(bareM[2]);
        } else {
            var fnExpr = formula.replace(/(SUM|AVERAGE|COUNT|MIN|MAX|SUMIF|COUNTIF)\(([^()]*)\)/gi, function (m, fn, args) {
                fn = fn.toUpperCase();
                if (fn === 'SUMIF') return String(evalSumif(args));
                if (fn === 'COUNTIF') return String(evalCountif(args));
                var vals = expandArgsToNumbers(args);
                var r;
                if (fn === 'SUM') r = vals.reduce(function (a, b) { return a + b; }, 0);
                else if (fn === 'AVERAGE') r = vals.length ? vals.reduce(function (a, b) { return a + b; }, 0) / vals.length : 0;
                else if (fn === 'COUNT') r = vals.length;
                else if (fn === 'MIN') r = vals.length ? Math.min.apply(null, vals) : 0;
                else if (fn === 'MAX') r = vals.length ? Math.max.apply(null, vals) : 0;
                return String(r);
            });
            result = evalArithmetic(fnExpr);
        }
        computingSet[a1] = false;
        computedCache[a1] = result;
        return result;
    }

    function recalcAll() { computedCache = {}; computingSet = {}; }

    /* ---------------- Định dạng số hiển thị (không đổi giá trị lưu, chỉ đổi cách hiện) ---------------- */
    function formatDisplayValue(val, fmt) {
        if (typeof val !== 'number' || !fmt || fmt === 'none') return val;
        if (fmt === 'currency') return val.toLocaleString('vi-VN', { maximumFractionDigits: 2 }) + ' ₫';
        if (fmt === 'percent') return val.toLocaleString('vi-VN', { maximumFractionDigits: 2 }) + '%';
        if (fmt === 'number') return val.toLocaleString('vi-VN', { maximumFractionDigits: 2 });
        return val;
    }

    /* ---------------- Render ---------------- */
    function styleOf(a1) { return (sheet.cells[a1] && sheet.cells[a1].s) || {}; }
    function cellStyleAttr(s, wrapText) {
        var out = [];
        if (s.b) out.push('font-weight:bold');
        if (s.i) out.push('font-style:italic');
        if (s.u) out.push('text-decoration:underline');
        if (s.color) out.push('color:' + s.color);
        if (s.bg) out.push('background:' + s.bg);
        if (s.align) out.push('text-align:' + s.align);
        if (s.fs) out.push('font-size:' + s.fs + 'px');
        // Ô có xuống dòng (Alt+Enter trong hộp công thức): giữ nguyên các dấu xuống dòng khi
        // hiển thị thay vì gộp thành 1 dòng như mặc định (white-space:nowrap của .of-sheet-cell).
        if (wrapText) out.push('white-space:pre-wrap');
        // Viền do người dùng TỰ vẽ (nút "Thiết lập gridlines") — dùng border THẬT (không phải
        // box-shadow) để có đủ độ dày/kiểu nét (liền/đứt/chấm) mà box-shadow không mô phỏng
        // được. Border-collapse của bảng GỘP viền 2 ô liền kề thành 1 đường duy nhất theo luật
        // "cạnh dày hơn thắng" — nên applyBorderToSelection() LUÔN chọn độ dày > 1px (viền
        // gridline mặc định), đảm bảo viền người dùng vẽ luôn thắng viền gridline mờ bên cạnh,
        // không bị "giành" mất bởi ô kế bên.
        if (s.bt) out.push('border-top:' + s.bt);
        if (s.bb) out.push('border-bottom:' + s.bb);
        if (s.bl) out.push('border-left:' + s.bl);
        if (s.br) out.push('border-right:' + s.br);
        return out.join(';');
    }

    function inSel(r, c) {
        if (!sel) return false;
        var r1 = Math.min(sel.r1, sel.r2), r2 = Math.max(sel.r1, sel.r2);
        var c1 = Math.min(sel.c1, sel.c2), c2 = Math.max(sel.c1, sel.c2);
        return r >= r1 && r <= r2 && c >= c1 && c <= c2;
    }

    /** Ô có nằm trong vùng vừa Ctrl+C/Ctrl+X (viền nét đứt kiểu Excel) hay không. */
    function inClip(r, c) {
        return !!(clipRange && r >= clipRange.r1 && r <= clipRange.r2 && c >= clipRange.c1 && c <= clipRange.c2);
    }

    /** Tổng chiều rộng thật của bảng (px) = cột số dòng (44px) + tổng width từng cột. Đặt CỨNG
     *  làm width của <table> thay vì để trình duyệt tự "auto" tính — với table-layout:fixed,
     *  width:auto có thể khiến 1 số trình duyệt co bảng lại vừa khung nhìn thay vì tràn ra rồi
     *  cuộn ngang (nhất là khi tổng độ rộng cột lớn hơn khung .of-sheet-wrap). Đặt rõ width thì
     *  bảng LUÔN đúng kích thước dự kiến, phần dư sẽ do overflow:auto của .of-sheet-wrap cuộn. */
    function totalTableWidth() {
        var w = 44;
        for (var c = 0; c < sheet.cols; c++) w += (sheet.colWidths[c] || 100);
        return w;
    }

    function render() {
        recalcAll();
        var wrap = $('#of-sheet-wrap');
        var html = '<table class="of-sheet-table" style="width:' + totalTableWidth() + 'px"><colgroup><col class="of-sheet-rowhead-col">';
        for (var c = 0; c < sheet.cols; c++) {
            html += '<col style="width:' + (sheet.colWidths[c] || 100) + 'px">';
        }
        html += '</colgroup><thead><tr><th class="of-sheet-corner"></th>';
        for (c = 0; c < sheet.cols; c++) {
            var colActive = sel && c >= Math.min(sel.c1, sel.c2) && c <= Math.max(sel.c1, sel.c2);
            html += '<th class="of-sheet-colhead' + (colActive ? ' is-active-header' : '') + '" data-col="' + c + '">' + colLetterSimple(c) +
                '<span class="of-col-resizer" data-col="' + c + '"></span></th>';
        }
        html += '</tr></thead><tbody>';
        for (var r = 1; r <= sheet.rows; r++) {
            var rowActive = sel && r >= Math.min(sel.r1, sel.r2) && r <= Math.max(sel.r1, sel.r2);
            html += '<tr style="height:' + (sheet.rowHeights[r] || 26) + 'px">' +
                '<th class="of-sheet-rowhead' + (rowActive ? ' is-active-header' : '') + '" data-row="' + r + '">' + r +
                '<span class="of-row-resizer" data-row="' + r + '"></span></th>';
            for (c = 0; c < sheet.cols; c++) {
                var a1 = ref(c, r);
                var s = styleOf(a1);
                var val = computeCell(a1);
                if (typeof val === 'number') val = (Math.round(val * 1e6) / 1e6);
                val = formatDisplayValue(val, s.fmt);
                var cls = 'of-sheet-cell' + (inSel(r, c) ? ' is-sel' : '') +
                    (sel && sel.r1 === r && sel.c1 === c ? ' is-active' : '') +
                    (inClip(r, c) ? ' is-clip' : '');
                var raw = rawOf(a1);
                // `draggable` KHÔNG đặt cố định ở đây nữa — bindGrid() tự bật/tắt động ngay lúc
                // mousedown tuỳ theo bấm sát mép hay giữa ô (xem isNearCellEdge()).
                var wrapText = typeof raw === 'string' && raw.indexOf('\n') !== -1;
                html += '<td class="' + cls + '" data-ref="' + a1 + '" data-row="' + r + '" data-col="' + c + '"' +
                    ' style="' + cellStyleAttr(s, wrapText) + '">' + OE.esc(val == null ? '' : String(val)) + '</td>';
            }
            html += '</tr>';
        }
        html += '</tbody></table>';
        wrap.innerHTML = html;
        bindGrid();
        updateToolbarState();
        renderCharts();
        syncFormulaBar();
    }

    /* ---------------- Chọn vùng / điều hướng ---------------- */
    var mouseDown = false;
    // Tự nhận diện double-click bằng data-ref + thời gian, KHÔNG dựa vào sự kiện "dblclick" gốc
    // của trình duyệt: mousedown của click đầu tiên gọi render() thay thế toàn bộ DOM lưới, nên
    // click thứ 2 luôn rơi vào 1 phần tử <td> KHÁC (dù cùng vị trí) — trình duyệt reset bộ đếm
    // click khi phần tử đổi, "dblclick" sẽ không bao giờ bắn ra được (bug: click đúp không vào
    // chế độ sửa được).
    var lastCellClick = { ref: null, time: 0 };
    var dragSourceRef = null; // ref ô đang được kéo (HTML5 drag) để dịch chuyển nội dung sang ô khác

    function setActive(r, c) { sel = { r1: r, c1: c, r2: r, c2: c }; updateSelectionClasses(); }

    /** Cập nhật CHỈ các class chọn/active (không dựng lại toàn bộ bảng) — dùng cho mọi thao tác
     *  chỉ đổi VÙNG CHỌN (không đổi nội dung/cấu trúc ô), vừa nhanh hơn render() đầy đủ (không
     *  gọi lại recalcAll()) vừa giữ nguyên các node <td> hiện có — cần thiết để thao tác kéo-thả
     *  (drag) ô bắt đầu đúng cách (nếu thay node bằng render(), trình duyệt sẽ không khởi động
     *  được thao tác kéo gốc, giống nguyên nhân bug double-click ở trên). */
    function updateSelectionClasses() {
        var wrap = $('#of-sheet-wrap');
        if (!wrap) return;
        wrap.querySelectorAll('.of-sheet-cell').forEach(function (td) {
            var r = parseInt(td.dataset.row, 10), c = parseInt(td.dataset.col, 10);
            td.classList.toggle('is-sel', inSel(r, c));
            td.classList.toggle('is-active', !!(sel && sel.r1 === r && sel.c1 === c));
        });
        wrap.querySelectorAll('.of-sheet-colhead').forEach(function (th) {
            var c = parseInt(th.dataset.col, 10);
            th.classList.toggle('is-active-header', !!(sel && c >= Math.min(sel.c1, sel.c2) && c <= Math.max(sel.c1, sel.c2)));
        });
        wrap.querySelectorAll('.of-sheet-rowhead').forEach(function (th) {
            var r = parseInt(th.dataset.row, 10);
            th.classList.toggle('is-active-header', !!(sel && r >= Math.min(sel.r1, sel.r2) && r <= Math.max(sel.r1, sel.r2)));
        });
        syncFormulaBar();
        updateToolbarState();
    }

    /** Bấm/rê có đang ở SÁT MÉP của ô hay không (trong khoảng EDGE_PX tính từ 1 trong 4 cạnh) —
     *  dùng để phân biệt "kéo để dịch chuyển nội dung" (mép) với "bôi đen vùng chọn" (giữa ô). */
    var EDGE_PX = 6;
    function isNearCellEdge(e, td) {
        var rect = td.getBoundingClientRect();
        var x = e.clientX - rect.left, y = e.clientY - rect.top;
        return x <= EDGE_PX || y <= EDGE_PX || (rect.width - x) <= EDGE_PX || (rect.height - y) <= EDGE_PX;
    }

    function bindGrid() {
        var wrap = $('#of-sheet-wrap');
        wrap.querySelectorAll('.of-sheet-cell').forEach(function (td) {
            td.addEventListener('mousedown', function (e) {
                // Đang gõ công thức trong hộp công thức ("=SUM(" ...): click/kéo trong lưới
                // giờ có nghĩa là CHÈN tham chiếu ô/vùng vào công thức, không phải chọn ô.
                if (isFormulaModeActive()) {
                    e.preventDefault();
                    var r0 = parseInt(td.dataset.row, 10), c0 = parseInt(td.dataset.col, 10);
                    formulaRangeAnchor = { row: r0, col: c0 };
                    insertRefIntoFormula(td.dataset.ref);
                    return;
                }
                // Đang sửa ĐÚNG ô này: để trình duyệt tự đặt caret / bôi đen text bên trong,
                // KHÔNG render lại (nếu không sẽ mất caret + nội dung đang gõ dở — bug double-click).
                if (editingRef === td.dataset.ref) return;
                if (editingRef && editingRef !== td.dataset.ref) commitEdit();
                var now = Date.now();
                var isDouble = lastCellClick.ref === td.dataset.ref && (now - lastCellClick.time) < 400;
                lastCellClick.time = now;
                lastCellClick.ref = td.dataset.ref;
                if (isDouble) {
                    lastCellClick.ref = null; // tránh click thứ 3 liền kề bị hiểu nhầm là 1 double-click nữa
                    setActive(parseInt(td.dataset.row, 10), parseInt(td.dataset.col, 10));
                    enterEdit(td.dataset.ref, false);
                    return;
                }
                var r = parseInt(td.dataset.row, 10), c = parseInt(td.dataset.col, 10);
                var isSoleActive = sel && sel.r1 === r && sel.c1 === c && sel.r1 === sel.r2 && sel.c1 === sel.c2;
                var raw = rawOf(td.dataset.ref);
                var hasContent = raw !== undefined && raw !== '';
                // Chỉ cho phép KÉO khi bấm sát MÉP ô (giống Excel: viền = con trỏ dịch chuyển,
                // giữa ô = để bôi đen/mở rộng vùng chọn) — bật/tắt `draggable` ngay tại đây theo
                // đúng vị trí bấm của LẦN NÀY (không giữ trạng thái cũ từ lần bấm trước).
                var nearEdge = OE.canEdit && hasContent && isNearCellEdge(e, td);
                td.draggable = nearEdge;
                if (isSoleActive && nearEdge) {
                    // Ô này đang là ô chọn DUY NHẤT, có nội dung, và bấm sát mép: user có thể sắp
                    // KÉO nó sang ô khác để dịch chuyển (xem dragstart/drop bên dưới) — không đổi
                    // vùng chọn ở đây, nếu chỉ là click thường (không kéo) thì cũng không cần thay
                    // đổi gì (ô đã được chọn sẵn từ trước).
                    // KHÔNG set mouseDown=true: một thao tác kéo-thả HTML5 (dragstart/drop) không
                    // bao giờ bắn "mouseup" trên trang cho trình duyệt tự dọn cờ này — nếu bật lên
                    // đây, "thả chuột" sau khi kéo sẽ KHÔNG đồng nghĩa với "dừng" nữa: cờ mouseDown
                    // bị kẹt ở true khiến lần rê chuột (dù không giữ nút) kế tiếp bị hiểu nhầm
                    // thành đang kéo chọn vùng.
                    return;
                }
                mouseDown = true;
                sel = { r1: r, c1: c, r2: r, c2: c };
                updateSelectionClasses();
            });
            td.addEventListener('mousemove', function (e) {
                if (formulaRangeAnchor) {
                    var r2 = parseInt(td.dataset.row, 10), c2 = parseInt(td.dataset.col, 10);
                    var rangeStr = (formulaRangeAnchor.row === r2 && formulaRangeAnchor.col === c2)
                        ? ref(c2, r2)
                        : ref(Math.min(formulaRangeAnchor.col, c2), Math.min(formulaRangeAnchor.row, r2)) + ':' +
                          ref(Math.max(formulaRangeAnchor.col, c2), Math.max(formulaRangeAnchor.row, r2));
                    replaceFormulaInsertRange(rangeStr);
                    return;
                }
                if (!mouseDown || !sel || editingRef) return;
                sel.r2 = parseInt(td.dataset.row, 10);
                sel.c2 = parseInt(td.dataset.col, 10);
                updateSelectionClasses();
            });
            // Chỉ đổi con trỏ chuột thành "grab" khi RÊ (không bấm) sát mép ô đã chọn có nội dung
            // — báo trước cho user biết bấm ở đây sẽ kéo-dịch-chuyển thay vì bôi đen vùng chọn.
            td.addEventListener('mousemove', function (e) {
                if (mouseDown || editingRef) return;
                var r = parseInt(td.dataset.row, 10), c = parseInt(td.dataset.col, 10);
                var isSoleActive = sel && sel.r1 === r && sel.c1 === c && sel.r1 === sel.r2 && sel.c1 === sel.c2;
                var raw = rawOf(td.dataset.ref);
                var hasContent = raw !== undefined && raw !== '';
                var showGrab = OE.canEdit && isSoleActive && hasContent && isNearCellEdge(e, td);
                td.style.cursor = showGrab ? 'grab' : '';
            });
            td.addEventListener('mouseleave', function () { td.style.cursor = ''; });
            td.addEventListener('dblclick', function () {
                if (isFormulaModeActive()) return;
                enterEdit(td.dataset.ref, false);
            });
            /* --- Kéo-thả (drag & drop) để DỊCH CHUYỂN nội dung ô này sang ô khác, giống Excel ---
             * `draggable` chỉ bật (xem render()) khi ô có nội dung và đang ở chế độ sửa được;
             * chỉ khởi động được khi ô đã là ô chọn DUY NHẤT (xem nhánh isSoleActive ở trên) để
             * không xung đột với thao tác chọn vùng (rubber-band) hay double-click thông thường. */
            td.addEventListener('dragstart', function (e) {
                if (!OE.canEdit || editingRef) { e.preventDefault(); return; }
                dragSourceRef = td.dataset.ref;
                e.dataTransfer.effectAllowed = 'move';
                e.dataTransfer.setData('text/plain', td.dataset.ref);
            });
            td.addEventListener('dragover', function (e) {
                if (!dragSourceRef) return;
                e.preventDefault();
                e.dataTransfer.dropEffect = 'move';
            });
            td.addEventListener('dragenter', function (e) {
                if (!dragSourceRef) return;
                e.preventDefault();
                td.classList.add('is-drop-target');
            });
            td.addEventListener('dragleave', function () {
                td.classList.remove('is-drop-target');
            });
            td.addEventListener('drop', function (e) {
                e.preventDefault();
                mouseDown = false;
                td.classList.remove('is-drop-target');
                var srcRef = dragSourceRef;
                dragSourceRef = null;
                if (!srcRef || srcRef === td.dataset.ref) return;
                if (editingRef) commitEdit();
                // Kéo-thả là DI CHUYỂN (move) chứ không phải sao chép: xoá ô nguồn, ghi đè ô đích.
                var data = sheet.cells[srcRef];
                delete sheet.cells[srcRef];
                if (data) sheet.cells[td.dataset.ref] = data; else delete sheet.cells[td.dataset.ref];
                var dr = parseInt(td.dataset.row, 10), dc = parseInt(td.dataset.col, 10);
                sel = { r1: dr, c1: dc, r2: dr, c2: dc };
                OE.scheduleAutosave();
                render();
            });
            td.addEventListener('dragend', function () {
                // "Thả chuột" (kể cả thả ra ngoài lưới, huỷ kéo bằng Esc...) LUÔN đồng nghĩa với
                // DỪNG hẳn thao tác chuột hiện tại — dọn sạch mọi cờ trạng thái liên quan.
                mouseDown = false;
                dragSourceRef = null;
                wrap.querySelectorAll('.is-drop-target').forEach(function (el) { el.classList.remove('is-drop-target'); });
            });
        });
        wrap.querySelectorAll('.of-col-resizer').forEach(function (rz) {
            rz.addEventListener('mousedown', function (e) {
                e.stopPropagation();
                var col = parseInt(rz.dataset.col, 10);
                // Cột đang kéo nằm trong 1 vùng chọn NHIỀU CỘT (bôi đen qua header, xem
                // bindGrid() phần "of-sheet-colhead") → co/giãn áp dụng ĐỒNG THỜI cho mọi cột
                // trong vùng đó (giống Excel), không chỉ riêng cột đang bấm vào. Độ rộng dòng
                // KHÔNG liên quan tới việc này (kể cả khi sel chỉ là vùng ô, không phải cả cột) —
                // Excel cũng co nhiều cột cùng lúc dù vùng chọn chỉ là 1 khối ô, không phải cả cột.
                var cols = [col];
                if (sel) {
                    var rg = normalizedSel();
                    if (rg.c2 > rg.c1 && col >= rg.c1 && col <= rg.c2) {
                        cols = [];
                        for (var cc = rg.c1; cc <= rg.c2; cc++) cols.push(cc);
                    }
                }
                var startX = e.clientX;
                var startWidths = {};
                cols.forEach(function (cc) { startWidths[cc] = sheet.colWidths[cc] || 100; });
                // +1: cột đầu trong <colgroup> là .of-sheet-rowhead-col (cột số dòng).
                var colEls = wrap.querySelectorAll('col');
                var tableEl = wrap.querySelector('table.of-sheet-table');
                function onMove(ev) {
                    // Cùng 1 độ dịch chuột (delta) áp cho mọi cột trong `cols` — mỗi cột cộng
                    // delta vào ĐÚNG độ rộng ban đầu của riêng nó (không phải gán chung 1 độ rộng),
                    // để các cột đang khác kích thước nhau vẫn giữ đúng chênh lệch tương đối.
                    var delta = ev.clientX - startX;
                    cols.forEach(function (cc) {
                        var w = Math.max(40, startWidths[cc] + delta);
                        sheet.colWidths[cc] = w;
                        // Cập nhật thẳng DOM thay vì render() lại toàn bảng mỗi pixel di chuột —
                        // render() gọi cả recalcAll() (tính lại MỌI công thức) nên bị trễ/giật so
                        // với con trỏ khi bảng có nhiều dòng/cột. Chỉ render() lại lúc thả chuột.
                        var colEl = colEls[cc + 1];
                        if (colEl) colEl.style.width = w + 'px';
                    });
                    // Bảng có width CỨNG (xem totalTableWidth()) nên phải cộng dồn phần chênh lệch
                    // ngay lúc kéo — nếu không bảng sẽ giữ nguyên width cũ, cột vừa kéo rộng ra sẽ
                    // bị cắt/không cuộn ngang thêm được cho tới khi thả chuột.
                    if (tableEl) tableEl.style.width = totalTableWidth() + 'px';
                }
                function onUp() {
                    document.removeEventListener('mousemove', onMove);
                    document.removeEventListener('mouseup', onUp);
                    OE.scheduleAutosave();
                    render();
                }
                document.addEventListener('mousemove', onMove);
                document.addEventListener('mouseup', onUp);
            });
        });
        wrap.querySelectorAll('.of-row-resizer').forEach(function (rz) {
            rz.addEventListener('mousedown', function (e) {
                e.stopPropagation();
                var row = parseInt(rz.dataset.row, 10);
                // Tương tự cột ở trên: dòng đang kéo nằm trong vùng chọn NHIỀU DÒNG → co/giãn
                // đồng thời mọi dòng trong vùng đó.
                var rows = [row];
                if (sel) {
                    var rg = normalizedSel();
                    if (rg.r2 > rg.r1 && row >= rg.r1 && row <= rg.r2) {
                        rows = [];
                        for (var rr = rg.r1; rr <= rg.r2; rr++) rows.push(rr);
                    }
                }
                var startY = e.clientY;
                var startHeights = {};
                var trEls = {};
                rows.forEach(function (rr) {
                    startHeights[rr] = sheet.rowHeights[rr] || 26;
                    var rh = wrap.querySelector('.of-sheet-rowhead[data-row="' + rr + '"]');
                    trEls[rr] = rh ? rh.closest('tr') : null;
                });
                function onMove(ev) {
                    var delta = ev.clientY - startY;
                    rows.forEach(function (rr) {
                        var h = Math.max(18, startHeights[rr] + delta);
                        sheet.rowHeights[rr] = h;
                        if (trEls[rr]) trEls[rr].style.height = h + 'px';
                    });
                }
                function onUp() {
                    document.removeEventListener('mousemove', onMove);
                    document.removeEventListener('mouseup', onUp);
                    OE.scheduleAutosave();
                    render();
                }
                document.addEventListener('mousemove', onMove);
                document.addEventListener('mouseup', onUp);
            });
        });
        // Click số dòng -> chọn cả dòng; kéo qua nhiều số dòng -> chọn nhiều dòng liền kề (giống
        // click chữ cột bên dưới, và giống kéo-bôi-đen nhiều ô: neo r1 tại dòng bấm xuống đầu
        // tiên, r2 đuổi theo dòng đang rê tới — c1/c2 giữ nguyên trọn 0..cols-1 suốt lúc kéo).
        wrap.querySelectorAll('.of-sheet-rowhead').forEach(function (th) {
            th.addEventListener('mousedown', function (e) {
                if (e.target.closest('.of-row-resizer')) return;
                if (editingRef) commitEdit();
                var row = parseInt(th.dataset.row, 10);
                mouseDown = true;
                sel = { r1: row, c1: 0, r2: row, c2: sheet.cols - 1 };
                updateSelectionClasses();
            });
            th.addEventListener('mousemove', function () {
                if (!mouseDown || editingRef) return;
                sel.r2 = parseInt(th.dataset.row, 10);
                updateSelectionClasses();
            });
        });
        wrap.querySelectorAll('.of-sheet-colhead').forEach(function (th) {
            th.addEventListener('mousedown', function (e) {
                if (e.target.closest('.of-col-resizer')) return;
                if (editingRef) commitEdit();
                var col = parseInt(th.dataset.col, 10);
                mouseDown = true;
                sel = { r1: 1, c1: col, r2: sheet.rows, c2: col };
                updateSelectionClasses();
            });
            th.addEventListener('mousemove', function () {
                if (!mouseDown || editingRef) return;
                sel.c2 = parseInt(th.dataset.col, 10);
                updateSelectionClasses();
            });
            th.addEventListener('contextmenu', function (e) {
                e.preventDefault();
                ctxMenuCol(parseInt(th.dataset.col, 10), e.pageX, e.pageY);
            });
        });
        wrap.querySelectorAll('.of-sheet-rowhead').forEach(function (th) {
            th.addEventListener('contextmenu', function (e) {
                e.preventDefault();
                ctxMenuRow(parseInt(th.dataset.row, 10), e.pageX, e.pageY);
            });
        });
    }

    document.addEventListener('mouseup', function (e) {
        mouseDown = false;
        // Chỉ "dán" định dạng khi buông chuột NGAY TRONG lưới (không phải lúc bấm nút toolbar
        // để BẬT chế độ sơn — nếu không painter sẽ tự tiêu ngay lúc vừa bật).
        if (formatPainter && e.target && e.target.closest && e.target.closest('.of-sheet-cell')) {
            applyPainterIfActive();
        }
        // Vừa kéo chọn vùng để chèn vào công thức xong (thả chuột) — CHỈ trả focus lại ô công
        // thức để có thể click tiếp sang ô/vùng khác nối thêm (giống Excel: có thể chọn nhiều
        // vùng rời nhau trước khi Enter). KHÔNG tự đóng ngoặc ở đây — nếu đóng ngay sau click/kéo
        // đầu tiên thì các ký tự "=SUM(A1:A3" sẽ hoá "=SUM(A1:A3)" và chèn tiếp ô sau sẽ sai vị
        // trí (chèn sau dấu ")"). Việc tự đóng ngoặc còn thiếu chỉ nên làm lúc CHỐT công thức
        // (Enter/blur) — xem commitFormulaBar().
        if (formulaRangeAnchor) {
            var input = $('#of-formula-input');
            if (input) input.focus();
            formulaRangeAnchor = null;
        }
    });

    function ctxMenuCol(col, x, y) {
        if (!OE.canEdit) return;
        showCtx(x, y, [
            { label: 'Chèn cột trước', act: function () { insertCol(col); } },
            { label: 'Chèn cột sau', act: function () { insertCol(col + 1); } },
            { label: 'Xóa cột', act: function () { deleteCol(col); } }
        ]);
    }
    function ctxMenuRow(row, x, y) {
        if (!OE.canEdit) return;
        showCtx(x, y, [
            { label: 'Chèn dòng trên', act: function () { insertRow(row); } },
            { label: 'Chèn dòng dưới', act: function () { insertRow(row + 1); } },
            { label: 'Xóa dòng', act: function () { deleteRow(row); } }
        ]);
    }
    function showCtx(x, y, items) {
        var m = document.createElement('div');
        m.className = 'of-ctxmenu';
        m.style.left = x + 'px'; m.style.top = y + 'px'; m.style.display = 'block';
        m.innerHTML = items.map(function (it, i) { return '<div class="of-ctx-item" data-i="' + i + '">' + it.label + '</div>'; }).join('');
        document.body.appendChild(m);
        m.querySelectorAll('.of-ctx-item').forEach(function (el, i) {
            el.addEventListener('click', function () { items[i].act(); document.body.removeChild(m); OE.scheduleAutosave(); render(); });
        });
        setTimeout(function () {
            document.addEventListener('click', function once() {
                if (m.parentNode) document.body.removeChild(m);
                document.removeEventListener('click', once);
            });
        }, 0);
    }

    function shiftCellsForInsertRow(at) {
        var newCells = {};
        Object.keys(sheet.cells).forEach(function (a1) {
            var p = parseRef(a1);
            var nr = p.row >= at ? p.row + 1 : p.row;
            newCells[ref(p.col, nr)] = sheet.cells[a1];
        });
        sheet.cells = newCells;
    }
    function insertRow(at) { shiftCellsForInsertRow(at); sheet.rows++; }
    function deleteRow(at) {
        var newCells = {};
        Object.keys(sheet.cells).forEach(function (a1) {
            var p = parseRef(a1);
            if (p.row === at) return;
            var nr = p.row > at ? p.row - 1 : p.row;
            newCells[ref(p.col, nr)] = sheet.cells[a1];
        });
        sheet.cells = newCells;
        sheet.rows = Math.max(1, sheet.rows - 1);
    }
    function insertCol(at) {
        var newCells = {};
        Object.keys(sheet.cells).forEach(function (a1) {
            var p = parseRef(a1);
            var nc = p.col >= at ? p.col + 1 : p.col;
            newCells[ref(nc, p.row)] = sheet.cells[a1];
        });
        sheet.cells = newCells;
        sheet.cols++;
    }
    function deleteCol(at) {
        var newCells = {};
        Object.keys(sheet.cells).forEach(function (a1) {
            var p = parseRef(a1);
            if (p.col === at) return;
            var nc = p.col > at ? p.col - 1 : p.col;
            newCells[ref(nc, p.row)] = sheet.cells[a1];
        });
        sheet.cells = newCells;
        sheet.cols = Math.max(1, sheet.cols - 1);
    }

    /* ---------------- Sửa nội dung ô ---------------- */
    function enterEdit(a1, clearFirst) {
        if (!OE.canEdit) return;
        editingRef = a1;
        var td = document.querySelector('.of-sheet-cell[data-ref="' + a1 + '"]');
        if (!td) return;
        td.contentEditable = 'true';
        td.textContent = clearFirst ? '' : (rawOf(a1) !== undefined && rawOf(a1) !== '' ? String(rawOf(a1)) : '');
        td.classList.add('is-editing');
        td.focus();
        // Đặt caret cuối nội dung.
        var range = document.createRange();
        range.selectNodeContents(td);
        range.collapse(false);
        var s = window.getSelection();
        s.removeAllRanges(); s.addRange(range);
    }

    function commitEdit() {
        if (!editingRef) return;
        var td = document.querySelector('.of-sheet-cell[data-ref="' + editingRef + '"]');
        var val = td ? td.textContent : '';
        if (val === '') delete sheet.cells[editingRef];
        else {
            sheet.cells[editingRef] = sheet.cells[editingRef] || {};
            // Gõ dấu ' ở đầu (quy ước Excel) ép ô thành CHUỖI: bỏ dấu ' khỏi giá trị lưu (ẩn
            // ngay khi rời ô) nhưng nhớ lại bằng cờ .t để computeCell() không tự hiểu thành số.
            // Cờ này "dính" cho các lần sửa SAU của cùng ô dù không gõ lại dấu ' (giống Excel:
            // ô đã ở định dạng Văn bản thì gõ tiếp vẫn giữ Văn bản) — tránh việc chỉ bấm Enter
            // xác nhận lại y nguyên "01" cũng vô tình biến ô trở lại thành số 1, mất số 0 đầu.
            // Muốn đưa ô về lại số: xoá trắng ô rồi gõ lại không kèm dấu '.
            if (val.charAt(0) === "'") { val = val.slice(1); sheet.cells[editingRef].t = 'text'; }
            sheet.cells[editingRef].v = val;
        }
        editingRef = null;
        OE.scheduleAutosave();
        render();
    }

    function cancelEdit() { editingRef = null; render(); }

    /** Chèn 1 ký tự xuống dòng thật ("\n") ngay tại vị trí con trỏ đang gõ trong ô (Alt+Enter) —
     *  KHÔNG dùng execCommand('insertLineBreak')/<br> vì td.textContent (đọc lúc commitEdit) bỏ
     *  qua hoàn toàn <br>, không sinh ra "\n" nào — phải tự chèn text node xuống dòng thủ công. */
    function insertLineBreakAtCaret() {
        var s = window.getSelection();
        if (!s || !s.rangeCount) return;
        var range = s.getRangeAt(0);
        range.deleteContents();
        var node = document.createTextNode('\n');
        range.insertNode(node);
        range.setStartAfter(node);
        range.setEndAfter(node);
        s.removeAllRanges();
        s.addRange(range);
    }

    /* ---------------- Bàn phím ---------------- */
    function moveActive(dr, dc) {
        if (!sel) { sel = { r1: 1, c1: 0, r2: 1, c2: 0 }; }
        var r = Math.max(1, Math.min(sheet.rows, sel.r1 + dr));
        var c = Math.max(0, Math.min(sheet.cols - 1, sel.c1 + dc));
        setActive(r, c);
    }

    document.addEventListener('keydown', function (e) {
        var active = document.activeElement;
        var inSheet = active && active.closest && active.closest('#of-sheet-wrap');
        var isBody = !active || active === document.body;
        if (!inSheet && !isBody) return; // đang gõ ở nơi khác (tiêu đề, modal...) thì bỏ qua
        if (!sel) return;

        if (editingRef) {
            // Alt+Enter: chèn 1 dấu xuống dòng NGAY TẠI VỊ TRÍ CON TRỎ đang gõ trực tiếp trong ô
            // (khác Enter thường — không chốt/di chuyển ô), giống hệt Alt+Enter ở hộp công thức.
            if (e.key === 'Enter' && e.altKey) {
                e.preventDefault();
                insertLineBreakAtCaret();
                return;
            }
            if (e.key === 'Enter') { e.preventDefault(); commitEdit(); moveActive(1, 0); }
            else if (e.key === 'Tab') { e.preventDefault(); commitEdit(); moveActive(0, 1); }
            else if (e.key === 'Escape') { e.preventDefault(); cancelEdit(); }
            return;
        }

        if (e.key === 'Escape' && clipRange) {
            e.preventDefault();
            clipRange = null; clipIsCut = false;
            render();
            return;
        }

        if (!OE.canEdit) {
            if (e.key === 'ArrowUp') { e.preventDefault(); moveActive(-1, 0); }
            else if (e.key === 'ArrowDown') { e.preventDefault(); moveActive(1, 0); }
            else if (e.key === 'ArrowLeft') { e.preventDefault(); moveActive(0, -1); }
            else if (e.key === 'ArrowRight') { e.preventDefault(); moveActive(0, 1); }
            return;
        }

        if (e.key === 'ArrowUp') { e.preventDefault(); moveActive(-1, 0); }
        else if (e.key === 'ArrowDown') { e.preventDefault(); moveActive(1, 0); }
        else if (e.key === 'ArrowLeft') { e.preventDefault(); moveActive(0, -1); }
        else if (e.key === 'ArrowRight') { e.preventDefault(); moveActive(0, 1); }
        else if (e.key === 'Enter' || e.key === 'F2') { e.preventDefault(); enterEdit(ref(sel.c1, sel.r1), false); }
        else if (e.key === 'Delete' || e.key === 'Backspace') {
            e.preventDefault();
            forEachSelected(function (a1) { delete sheet.cells[a1]; });
            OE.scheduleAutosave(); render();
        } else if ((e.ctrlKey || e.metaKey) && e.key.toLowerCase() === 'c') {
            copySelection();
        } else if ((e.ctrlKey || e.metaKey) && e.key.toLowerCase() === 'x') {
            e.preventDefault();
            cutSelection();
        } else if ((e.ctrlKey || e.metaKey) && e.key.toLowerCase() === 'v') {
            pasteAt();
        } else if ((e.ctrlKey || e.metaKey) && e.key.toLowerCase() === 'b') {
            e.preventDefault();
            applyStyleToSelection(function (s) { s.b = !s.b; });
        } else if ((e.ctrlKey || e.metaKey) && e.key.toLowerCase() === 'i') {
            e.preventDefault();
            applyStyleToSelection(function (s) { s.i = !s.i; });
        } else if ((e.ctrlKey || e.metaKey) && e.key.toLowerCase() === 'u') {
            e.preventDefault();
            applyStyleToSelection(function (s) { s.u = !s.u; });
        } else if (e.key.length === 1 && !e.ctrlKey && !e.metaKey && !e.altKey) {
            enterEdit(ref(sel.c1, sel.r1), true);
            // Ký tự đầu tiên sẽ được chèn bởi trình duyệt do contentEditable vừa focus.
        }
    });

    function forEachSelected(fn) {
        var r1 = Math.min(sel.r1, sel.r2), r2 = Math.max(sel.r1, sel.r2);
        var c1 = Math.min(sel.c1, sel.c2), c2 = Math.max(sel.c1, sel.c2);
        for (var r = r1; r <= r2; r++) for (var c = c1; c <= c2; c++) fn(ref(c, r));
    }

    /** Vùng vừa Ctrl+C/Ctrl+X (chuẩn hoá r1<=r2, c1<=c2) — dùng để vẽ viền nét đứt trong render()
     *  và để Ctrl+X biết cần xoá ô nào sau khi dán xong. */
    function normalizedSel() {
        return {
            r1: Math.min(sel.r1, sel.r2), r2: Math.max(sel.r1, sel.r2),
            c1: Math.min(sel.c1, sel.c2), c2: Math.max(sel.c1, sel.c2)
        };
    }

    /** Đóng khung 1 ô bằng dấu ngoặc kép kiểu CSV/Excel nếu giá trị có chứa Tab, xuống dòng hay
     *  dấu ngoặc kép — nếu không, 1 ô có Alt+Enter xuống dòng khi copy sẽ bị hiểu nhầm thành 2
     *  DÒNG riêng lúc dán (ký tự "\n" bên trong ô trùng với ký tự dùng để NGĂN CÁCH DÒNG). */
    function tsvEscapeField(v) {
        v = String(v == null ? '' : v);
        if (/[\t\n"]/.test(v)) return '"' + v.replace(/"/g, '""') + '"';
        return v;
    }

    /** Ngược lại với tsvEscapeField: đọc text TSV thành mảng dòng x cột, hiểu đúng ô nào đang
     *  nằm trong dấu ngoặc kép (ký tự "\n"/"\t" bên trong ngoặc kép KHÔNG được coi là ranh giới
     *  dòng/cột — đây là quy tắc CSV chuẩn, giống cách Excel tự đóng gói ô nhiều dòng lúc copy). */
    function parseTsv(text) {
        var rows = [], row = [], field = '', inQuotes = false;
        for (var i = 0; i < text.length; i++) {
            var ch = text[i];
            if (inQuotes) {
                if (ch === '"') {
                    if (text[i + 1] === '"') { field += '"'; i++; } else inQuotes = false;
                } else field += ch;
                continue;
            }
            if (ch === '"' && field === '') { inQuotes = true; continue; }
            if (ch === '\t') { row.push(field); field = ''; continue; }
            if (ch === '\r') continue;
            if (ch === '\n') { row.push(field); rows.push(row); row = []; field = ''; continue; }
            field += ch;
        }
        row.push(field);
        rows.push(row);
        return rows;
    }

    function copySelection() {
        var rg = normalizedSel();
        var rows = [];
        for (var r = rg.r1; r <= rg.r2; r++) {
            var row = [];
            for (var c = rg.c1; c <= rg.c2; c++) row.push(tsvEscapeField(rawOf(ref(c, r)) || ''));
            rows.push(row.join('\t'));
        }
        var text = rows.join('\n');
        clipboard = text;
        // Hiệu ứng chọn kiểu Excel: viền nét đứt quanh vùng vừa copy, giữ tới khi dán/Esc/copy khác.
        clipRange = rg; clipIsCut = false;
        render();
        if (navigator.clipboard && navigator.clipboard.writeText) {
            navigator.clipboard.writeText(text).catch(function () {});
        }
    }

    /** Ctrl+X: giống copy (để có thể dán) nhưng đánh dấu clipIsCut — dán xong sẽ xoá vùng nguồn,
     *  tức "kéo" nội dung từ vùng này sang vùng khác thay vì nhân bản. */
    function cutSelection() {
        copySelection();
        clipIsCut = true;
    }

    function applyPasteText(text) {
        var lines = parseTsv(text);
        var startR = sel.r1, startC = sel.c1;
        var writtenRefs = {};
        lines.forEach(function (cols, ri) {
            if (cols.length === 1 && cols[0] === '' && ri === lines.length - 1 && lines.length > 1) return;
            cols.forEach(function (val, ci) {
                var r = startR + ri, c = startC + ci;
                if (r > sheet.rows) sheet.rows = r;
                if (c >= sheet.cols) sheet.cols = c + 1;
                var a1 = ref(c, r);
                writtenRefs[a1] = true;
                if (val === '') delete sheet.cells[a1];
                else { sheet.cells[a1] = sheet.cells[a1] || {}; sheet.cells[a1].v = val; }
            });
        });
        // Ctrl+X rồi dán: xoá nốt nội dung ở vùng nguồn ("di chuyển" thay vì sao chép), và tắt
        // hiệu ứng viền nét đứt — Excel cũng chỉ cho dán 1 lần sau khi cắt. Bỏ qua các ô nguồn
        // TRÙNG với ô vừa mới ghi giá trị mới vào (vùng nguồn/đích chồng nhau) để không xoá nhầm
        // nội dung vừa dán.
        if (clipIsCut && clipRange) {
            for (var r2 = clipRange.r1; r2 <= clipRange.r2; r2++) {
                for (var c2 = clipRange.c1; c2 <= clipRange.c2; c2++) {
                    var srcRef = ref(c2, r2);
                    if (!writtenRefs[srcRef]) delete sheet.cells[srcRef];
                }
            }
        }
        clipRange = null; clipIsCut = false;
        OE.scheduleAutosave();
        render();
    }

    function pasteAt() {
        // Dùng biến nội bộ `clipboard` trực tiếp — KHÔNG gọi navigator.clipboard.readText()
        // vì API đó có thể khiến trình duyệt hiện hộp thoại xin quyền truy cập clipboard,
        // không phù hợp cho một thao tác dán nhanh trong bảng tính (không cần "thông báo" gì).
        if (clipboard) applyPasteText(clipboard);
    }

    /** Dán từ NGOÀI app (Excel, sheet khác, file text...) — trình duyệt bắn sự kiện 'paste' gốc
     *  mang theo dữ liệu thật của hệ điều hành qua e.clipboardData, khác với biến `clipboard`
     *  nội bộ ở trên (chỉ có giá trị khi copy TỪ CHÍNH bảng tính này). Không dùng cho lúc đang
     *  sửa 1 ô (editingRef) — để trình duyệt tự dán văn bản thường vào đúng ô đang gõ như bình
     *  thường, không "nổ" ra nhiều ô xung quanh ngoài ý muốn.
     *  Ctrl+V cũng đi qua nhánh 'v' trong keydown ở trên (dùng biến `clipboard` nội bộ, đáng tin
     *  cậy hơn vì không phụ thuộc quyền OS clipboard) — nếu nội dung 2 nguồn TRÙNG nhau (tức vừa
     *  copy từ chính bảng tính này) thì bỏ qua ở đây để khỏi dán 2 lần.
     */
    document.addEventListener('paste', function (e) {
        if (!OE.canEdit || editingRef || !sel) return;
        var cd = e.clipboardData || window.clipboardData;
        var text = cd && cd.getData ? cd.getData('text/plain') : '';
        if (!text || text === clipboard) return;
        e.preventDefault();
        clipboard = text;
        clipRange = null; clipIsCut = false;
        applyPasteText(text);
    });

    /* ================================================================
     *  Biểu đồ (cột / tròn / đường) — đọc trực tiếp dữ liệu trên lưới
     * ================================================================ */
    var CHART_COLORS = ['#16a34a', '#2563eb', '#f59e0b', '#dc2626', '#7c3aed', '#0891b2', '#db2777'];

    /** Cột đầu = nhãn; dòng đầu (trừ ô góc) = tên từng chuỗi số liệu; phần còn lại là dữ liệu. */
    function extractChartData(rangeStr) {
        var range = parseRangeArg(rangeStr);
        if (!range || range.r2 <= range.r1 || range.c2 <= range.c1) return null;
        var seriesNames = [];
        for (var c = range.c1 + 1; c <= range.c2; c++) {
            var name = computeCell(ref(c, range.r1));
            seriesNames.push(name !== '' && name != null ? String(name) : ('Chuỗi ' + (c - range.c1)));
        }
        var labels = [];
        var series = seriesNames.map(function () { return []; });
        for (var r = range.r1 + 1; r <= range.r2; r++) {
            labels.push(String(computeCell(ref(range.c1, r))));
            for (var ci = 0; ci < series.length; ci++) series[ci].push(numOf(ref(range.c1 + 1 + ci, r)));
        }
        return { labels: labels, seriesNames: seriesNames, series: series };
    }

    function renderBarChart(data, w, h) {
        var allVals = data.series.reduce(function (a, s) { return a.concat(s); }, [0]);
        var maxV = Math.max.apply(null, allVals) || 1;
        var pad = 26, botPad = 34;
        var chartW = w - pad * 2, chartH = h - pad - botPad;
        var n = data.labels.length || 1;
        var groupW = chartW / n;
        var barW = groupW / (data.series.length + 1);
        var svg = '<svg viewBox="0 0 ' + w + ' ' + h + '" class="of-chart-svg">';
        svg += '<line x1="' + pad + '" y1="' + (h - botPad) + '" x2="' + (w - pad) + '" y2="' + (h - botPad) + '" stroke="#94a3b8"/>';
        data.series.forEach(function (s, si) {
            s.forEach(function (v, i) {
                var barH = (v / maxV) * chartH;
                var x = pad + i * groupW + si * barW + barW * 0.1;
                var y = h - botPad - barH;
                svg += '<rect x="' + x + '" y="' + y + '" width="' + (barW * 0.8) + '" height="' + Math.max(0, barH) + '" fill="' + CHART_COLORS[si % CHART_COLORS.length] + '"></rect>';
            });
        });
        data.labels.forEach(function (l, i) {
            var x = pad + i * groupW + groupW / 2;
            svg += '<text x="' + x + '" y="' + (h - botPad + 14) + '" font-size="9" text-anchor="middle" fill="#475569">' + OE.esc(l).slice(0, 8) + '</text>';
        });
        svg += '</svg>';
        return svg + chartLegend(data.seriesNames);
    }

    function renderLineChart(data, w, h) {
        var allVals = data.series.reduce(function (a, s) { return a.concat(s); }, [0]);
        var maxV = Math.max.apply(null, allVals), minV = Math.min.apply(null, allVals);
        var pad = 26, botPad = 34;
        var chartW = w - pad * 2, chartH = h - pad - botPad;
        var n = data.labels.length;
        var stepX = n > 1 ? chartW / (n - 1) : 0;
        var range = (maxV - minV) || 1;
        var svg = '<svg viewBox="0 0 ' + w + ' ' + h + '" class="of-chart-svg">';
        svg += '<line x1="' + pad + '" y1="' + (h - botPad) + '" x2="' + (w - pad) + '" y2="' + (h - botPad) + '" stroke="#94a3b8"/>';
        data.series.forEach(function (s, si) {
            var pts = s.map(function (v, i) {
                var x = pad + i * stepX;
                var y = h - botPad - ((v - minV) / range) * chartH;
                return x + ',' + y;
            }).join(' ');
            svg += '<polyline points="' + pts + '" fill="none" stroke="' + CHART_COLORS[si % CHART_COLORS.length] + '" stroke-width="2"></polyline>';
        });
        data.labels.forEach(function (l, i) {
            var x = pad + i * stepX;
            svg += '<text x="' + x + '" y="' + (h - botPad + 14) + '" font-size="9" text-anchor="middle" fill="#475569">' + OE.esc(l).slice(0, 8) + '</text>';
        });
        svg += '</svg>';
        return svg + chartLegend(data.seriesNames);
    }

    function renderPieChart(data, w, h) {
        var values = data.series[0] || [];
        var total = values.reduce(function (a, b) { return a + b; }, 0);
        var cx = w / 2, cy = (h - 26) / 2, radius = Math.min(w, h - 26) / 2 - 10;
        var svg = '<svg viewBox="0 0 ' + w + ' ' + (h) + '" class="of-chart-svg">';
        var angleStart = -Math.PI / 2;
        if (total <= 0) {
            svg += '<circle cx="' + cx + '" cy="' + cy + '" r="' + radius + '" fill="#e5e7eb"></circle>';
        } else {
            values.forEach(function (v, i) {
                var frac = v / total;
                var angleEnd = angleStart + frac * Math.PI * 2;
                var x1 = cx + radius * Math.cos(angleStart), y1 = cy + radius * Math.sin(angleStart);
                var x2 = cx + radius * Math.cos(angleEnd), y2 = cy + radius * Math.sin(angleEnd);
                var largeArc = (angleEnd - angleStart) > Math.PI ? 1 : 0;
                svg += '<path d="M' + cx + ',' + cy + ' L' + x1 + ',' + y1 + ' A' + radius + ',' + radius + ' 0 ' + largeArc + ' 1 ' + x2 + ',' + y2 + ' Z" fill="' + CHART_COLORS[i % CHART_COLORS.length] + '"></path>';
                angleStart = angleEnd;
            });
        }
        svg += '</svg>';
        return svg + chartLegend(data.labels);
    }

    function chartLegend(names) {
        return '<div class="of-chart-legend">' + names.map(function (n, i) {
            return '<span><i style="background:' + CHART_COLORS[i % CHART_COLORS.length] + '"></i>' + OE.esc(n) + '</span>';
        }).join('') + '</div>';
    }

    function renderCharts() {
        var panel = $('#of-sheet-charts');
        if (!panel) return;
        var charts = sheet.charts || [];
        if (!charts.length) {
            panel.innerHTML = '<div class="of-muted">Chưa có biểu đồ nào. Bấm "Biểu đồ" trên thanh công cụ để tạo.</div>';
            return;
        }
        panel.innerHTML = charts.map(function (ch) {
            var data = extractChartData(ch.range);
            var body;
            if (!data) body = '<div class="of-muted">Vùng "' + OE.esc(ch.range) + '" không hợp lệ (cần ít nhất 2 dòng, 2 cột).</div>';
            else if (ch.type === 'pie') body = renderPieChart(data, 280, 200);
            else if (ch.type === 'line') body = renderLineChart(data, 280, 180);
            else body = renderBarChart(data, 280, 180);
            return '<div class="of-chart-card" data-id="' + ch.id + '">' +
                '<button type="button" class="of-chart-card-del" data-id="' + ch.id + '" title="Xóa biểu đồ"><i class="fa-solid fa-trash"></i></button>' +
                '<div class="of-chart-card-title">' + OE.esc(ch.title || ch.range) + '</div>' + body + '</div>';
        }).join('');
        panel.querySelectorAll('.of-chart-card-del').forEach(function (b) {
            b.addEventListener('click', function () {
                sheet.charts = (sheet.charts || []).filter(function (c) { return String(c.id) !== b.dataset.id; });
                OE.scheduleAutosave();
                renderCharts();
            });
        });
    }

    /* ---------------- Toolbar ---------------- */
    var TOOLBAR_HTML =
        '<button type="button" class="of-tb-btn" data-s="b" title="Đậm"><i class="fa-solid fa-bold"></i></button>' +
        '<button type="button" class="of-tb-btn" data-s="i" title="Nghiêng"><i class="fa-solid fa-italic"></i></button>' +
        '<button type="button" class="of-tb-btn" data-s="u" title="Gạch chân"><i class="fa-solid fa-underline"></i></button>' +
        '<label class="of-tb-color" title="Màu chữ"><i class="fa-solid fa-font"></i><input type="color" id="of-tb-color"></label>' +
        '<label class="of-tb-color" title="Màu nền"><i class="fa-solid fa-fill-drip"></i><input type="color" id="of-tb-bg"></label>' +
        '<span class="of-tb-sep"></span>' +
        '<select class="of-tb-select" id="of-tb-fontsize" title="Cỡ chữ">' +
        '<option value="10">10</option><option value="12">12</option><option value="13" selected>13</option>' +
        '<option value="14">14</option><option value="16">16</option><option value="18">18</option>' +
        '<option value="20">20</option><option value="24">24</option></select>' +
        '<select class="of-tb-select" id="of-tb-fmt" title="Định dạng số">' +
        '<option value="none">Số thường</option><option value="number">Số (1.000)</option>' +
        '<option value="currency">Tiền tệ (₫)</option><option value="percent">Phần trăm (%)</option></select>' +
        '<span class="of-tb-sep"></span>' +
        '<button type="button" class="of-tb-btn" data-align="left" title="Căn trái"><i class="fa-solid fa-align-left"></i></button>' +
        '<button type="button" class="of-tb-btn" data-align="center" title="Căn giữa"><i class="fa-solid fa-align-center"></i></button>' +
        '<button type="button" class="of-tb-btn" data-align="right" title="Căn phải"><i class="fa-solid fa-align-right"></i></button>' +
        '<button type="button" class="of-tb-btn" id="of-tb-border" title="Thiết lập gridlines"><i class="fa-solid fa-border-all"></i></button>' +
        '<span class="of-tb-sep"></span>' +
        '<button type="button" class="of-tb-btn" data-act="addrow" title="Thêm dòng"><i class="fa-solid fa-table-cells-row-lock"></i> Dòng</button>' +
        '<button type="button" class="of-tb-btn" data-act="addcol" title="Thêm cột"><i class="fa-solid fa-table-columns"></i> Cột</button>' +
        '<span class="of-tb-sep"></span>' +
        '<button type="button" class="of-tb-btn" id="of-tb-paintformat" title="Copy định dạng"><i class="fa-solid fa-paintbrush"></i></button>';

    function applyStyleToSelection(mut) {
        if (!sel) return;
        forEachSelected(function (a1) {
            sheet.cells[a1] = sheet.cells[a1] || {};
            sheet.cells[a1].s = sheet.cells[a1].s || {};
            mut(sheet.cells[a1].s);
        });
        OE.scheduleAutosave();
        render();
    }

    /* ---------------- "Ẩn gridlines" (checkbox trên .of-ed-head) ---------------- */
    /** Đồng bộ lại DOM (class ẩn viền + trạng thái checkbox) theo đúng `sheet.hideGridlines` hiện
     *  tại — gọi lại sau mỗi lần nạp nội dung mới (registerContentSetter) vì checkbox không tự
     *  vẽ lại theo render() bình thường (nó nằm ngoài #of-sheet-wrap, trên .of-ed-head). */
    function applyGridlinesUi() {
        var wrap = $('#of-sheet-wrap');
        if (wrap) wrap.classList.toggle('of-hide-gridlines', !!sheet.hideGridlines);
        var cb = $('#of-sheet-hide-gridlines');
        if (cb) cb.checked = !!sheet.hideGridlines;
    }
    function initGridlinesToggle() {
        var cb = $('#of-sheet-hide-gridlines');
        if (!cb) return;
        cb.addEventListener('change', function () {
            sheet.hideGridlines = cb.checked;
            applyGridlinesUi();
            OE.scheduleAutosave();
        });
    }

    /* ---------------- "Thiết lập gridlines" (vẽ viền theo vùng chọn, kiểu Excel) ---------------- */
    /** Áp border cho vùng đang chọn theo các cạnh đã bật trong `sides` — lưu vào `.s.bt/bb/bl/br`
     *  của TỪNG Ô liên quan (xem cellStyleAttr): viền trên/dưới/trái/phải áp cho hàng/cột NGOÀI
     *  CÙNG của vùng; "giữa dọc"/"giữa ngang" mô phỏng bằng cách vẽ viền PHẢI của mọi cột trừ cột
     *  cuối / viền DƯỚI của mọi dòng trừ dòng cuối — tạo thành các đường phân cách nội bộ.
     *  `width` LUÔN > 1px (viền gridline mặc định của bảng) để border-collapse luôn ưu tiên viền
     *  vừa vẽ tại MỌI đường ngang/dọc nó đi qua (luật "cạnh dày hơn thắng") — nhờ vậy dù chỉ chọn
     *  đường ngang (không chọn dọc), đường kẻ vẫn liền mạch xuyên suốt các cột thay vì bị đường
     *  gridline dọc mảnh hơn (mặc định) cắt ngang tạo cảm giác đứt đoạn tại từng ô. */
    function applyBorderToSelection(sides, color, width, style) {
        if (!sel) return;
        var val = width + 'px ' + style + ' ' + color;
        var rg = normalizedSel();
        for (var r = rg.r1; r <= rg.r2; r++) {
            for (var c = rg.c1; c <= rg.c2; c++) {
                var top = sides.t && r === rg.r1;
                var bottom = sides.b && r === rg.r2;
                var left = sides.l && c === rg.c1;
                var right = sides.r && c === rg.c2;
                var vmid = sides.vmid && c < rg.c2;
                var hmid = sides.hmid && r < rg.r2;
                if (!top && !bottom && !left && !right && !vmid && !hmid) continue;
                var a1 = ref(c, r);
                sheet.cells[a1] = sheet.cells[a1] || {};
                sheet.cells[a1].s = sheet.cells[a1].s || {};
                var s = sheet.cells[a1].s;
                if (top) s.bt = val;
                if (bottom) s.bb = val;
                if (left) s.bl = val;
                if (right) s.br = val;
                if (vmid) s.br = val;
                if (hmid) s.bb = val;
            }
        }
        OE.scheduleAutosave();
        render();
    }

    function openBorderModal() {
        if (!sel) return;
        var sides = { t: false, b: false, l: false, r: false, vmid: false, hmid: false };
        OE.openModal('Thiết lập gridlines', '' +
            '<div class="of-border-color-row">Màu viền: <input type="color" id="of-border-color" value="#1f2937"></div>' +
            '<div class="of-border-color-row">' +
            '<span>Độ dày:</span><select class="of-tb-select" id="of-border-width">' +
            '<option value="2">Mỏng</option><option value="3" selected>Vừa</option><option value="4">Dày</option>' +
            '</select>' +
            '<span>Kiểu nét:</span><select class="of-tb-select" id="of-border-style">' +
            '<option value="solid">Liền</option><option value="dashed">Đứt nét</option><option value="dotted">Chấm chấm</option>' +
            '</select>' +
            '</div>' +
            '<div class="of-border-preview" id="of-border-preview">' +
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
            '<button type="button" class="of-btn" id="of-border-cancel">Hủy</button>' +
            '<button type="button" class="of-btn of-btn-primary" id="of-border-apply">Áp dụng</button>' +
            '</div>');
        var preview = $('#of-border-preview');
        preview.querySelectorAll('.obp-line').forEach(function (line) {
            line.addEventListener('click', function () {
                var side = line.dataset.side;
                sides[side] = !sides[side];
                line.classList.toggle('is-on', sides[side]);
            });
        });
        $('#of-border-cancel').addEventListener('click', OE.closeModal);
        $('#of-border-apply').addEventListener('click', function () {
            var color = $('#of-border-color').value;
            var width = parseInt($('#of-border-width').value, 10);
            var style = $('#of-border-style').value;
            applyBorderToSelection(sides, color, width, style);
            OE.closeModal();
        });
    }
    function initBorderTool() {
        var btn = $('#of-tb-border');
        if (!btn) return;
        btn.addEventListener('click', openBorderModal);
    }

    /* ---------------- Copy định dạng ("format painter") ---------------- */
    var formatPainter = null;   // style đang "cầm" để dán, null = không ở chế độ sơn
    var formatPainterMulti = false;

    function activatePainter(multi) {
        if (!sel) return;
        formatPainter = JSON.parse(JSON.stringify(styleOf(ref(sel.c1, sel.r1))));
        formatPainterMulti = multi;
        updatePainterUi();
    }
    function deactivatePainter() {
        formatPainter = null;
        formatPainterMulti = false;
        updatePainterUi();
    }
    function updatePainterUi() {
        var btn = $('#of-tb-paintformat');
        if (btn) btn.classList.toggle('is-active', !!formatPainter);
        var wrap = $('#of-sheet-wrap');
        if (wrap) wrap.classList.toggle('is-painting', !!formatPainter);
    }
    /** Dán style đã "sơn" vào vùng vừa chọn xong (gọi lúc mouseup) — 1 lượt thì tắt painter
     *  ngay, click ĐÚP (multi) thì giữ nguyên để dán tiếp cho tới khi bấm lại nút để tắt. */
    function applyPainterIfActive() {
        if (!formatPainter || !sel) return;
        forEachSelected(function (a1) {
            sheet.cells[a1] = sheet.cells[a1] || {};
            sheet.cells[a1].s = JSON.parse(JSON.stringify(formatPainter));
        });
        OE.scheduleAutosave();
        render();
        if (!formatPainterMulti) deactivatePainter();
    }

    /* ================================================================
     *  Hộp công thức (giống Excel): ô địa chỉ + nội dung/công thức của
     *  ô đang chọn, tự đề xuất tên hàm, kéo chọn vùng trong lưới để chèn
     *  tham chiếu ngay tại vị trí con trỏ trong công thức đang gõ.
     * ================================================================ */
    var FORMULA_FN_NAMES = ['SUM', 'SUMIF', 'AVERAGE', 'COUNT', 'COUNTIF', 'MIN', 'MAX', 'IF', 'VLOOKUP', 'HLOOKUP'];
    var suggestIndex = -1;
    // Chiều cao (px) user tự kéo .of-formula-resizer để đặt — giữ nguyên khi đổi ô chọn (giống
    // Excel: mở rộng thanh công thức là 1 tuỳ chọn hiển thị chung, không phải riêng từng ô).
    // autoGrowFormulaInput() coi đây là chiều cao TỐI THIỂU, vẫn tự phình thêm nếu nội dung dài hơn.
    var formulaManualHeight = null;

    /** true khi hộp công thức đang có focus VÀ nội dung bắt đầu bằng "=" — lúc này click/kéo
     *  trong lưới có nghĩa là chèn tham chiếu ô/vùng, không phải chọn ô như bình thường. */
    function isFormulaModeActive() {
        var input = $('#of-formula-input');
        return !!(input && document.activeElement === input && /^=/.test(input.value));
    }

    function insertRefIntoFormula(text) {
        var input = $('#of-formula-input');
        if (!input) return;
        var start = input.selectionStart, end = input.selectionEnd;
        // Nếu ký tự ngay trước điểm chèn là phần cuối của 1 tham chiếu/số TRƯỚC ĐÓ (chữ, số,
        // dấu ':' hoặc ')') — nghĩa là đang chèn THÊM 1 vùng MỚI (click sang ô/vùng khác), không
        // phải mở/tiếp tục ngay sau dấu "(" hoặc ",": tự nối bằng dấu phẩy, giống Excel
        // (=SUM(A1:A3,B1:B3)) thay vì dính liền thành chuỗi sai ("A1:A3B1:B3").
        var before = input.value.slice(0, start);
        var needsComma = /[A-Za-z0-9:)]$/.test(before) && !/[(,]\s*$/.test(before);
        var insertText = (needsComma ? ',' : '') + text;
        if (typeof input.setRangeText === 'function') input.setRangeText(insertText, start, end, 'end');
        else input.value = input.value.slice(0, start) + insertText + input.value.slice(end);
        var refStart = start + (needsComma ? 1 : 0);
        formulaInsertRange = { start: refStart, end: refStart + text.length };
    }
    function replaceFormulaInsertRange(text) {
        var input = $('#of-formula-input');
        if (!input || !formulaInsertRange) return;
        if (typeof input.setRangeText === 'function') {
            input.setRangeText(text, formulaInsertRange.start, formulaInsertRange.end, 'end');
        } else {
            input.value = input.value.slice(0, formulaInsertRange.start) + text + input.value.slice(formulaInsertRange.end);
        }
        formulaInsertRange.end = formulaInsertRange.start + text.length;
    }

    // Ô mà hộp công thức ĐANG SỬA — chụp lại lúc bắt đầu hiện/gõ, KHÔNG đọc `sel` trực tiếp lúc
    // commit: vì các <td> không tự nhận focus, click sang ô khác trong lúc hộp công thức vẫn
    // đang có focus sẽ đổi `sel` (để phục vụ chèn tham chiếu khi gõ công thức) nhưng KHÔNG làm
    // input mất focus/blur — nếu đọc `sel` lúc đó sẽ ghi nhầm nội dung đang gõ vào Ô SAI.
    var formulaBarTargetRef = null;

    /** #of-formula-input là <textarea> (để hỗ trợ Alt+Enter xuống dòng như Excel) nhưng phải
     *  TRÔNG như 1 ô nhập 1 dòng khi nội dung chỉ có 1 dòng — tự phình/co chiều cao theo nội dung. */
    function autoGrowFormulaInput(input) {
        input.style.height = 'auto';
        var h = input.scrollHeight;
        if (formulaManualHeight && formulaManualHeight > h) h = formulaManualHeight;
        input.style.height = h + 'px';
    }

    /** Kéo .of-formula-resizer lên/xuống để mở rộng/thu nhỏ ô công thức thủ công. */
    function initFormulaResizer() {
        var handle = $('#of-formula-resizer'), input = $('#of-formula-input');
        if (!handle || !input) return;
        handle.addEventListener('mousedown', function (e) {
            e.preventDefault();
            var startY = e.clientY;
            var startH = input.offsetHeight;
            function onMove(ev) {
                var h = Math.max(28, startH + (ev.clientY - startY));
                formulaManualHeight = h;
                input.style.height = h + 'px';
            }
            function onUp() {
                document.removeEventListener('mousemove', onMove);
                document.removeEventListener('mouseup', onUp);
            }
            document.addEventListener('mousemove', onMove);
            document.addEventListener('mouseup', onUp);
        });
    }

    function syncFormulaBar() {
        var refBox = $('#of-formula-ref'), input = $('#of-formula-input');
        if (!refBox || !input) return;
        if (!sel) { refBox.textContent = ''; input.value = ''; formulaBarTargetRef = null; return; }
        var a1 = ref(sel.c1, sel.r1);
        refBox.textContent = a1;
        if (document.activeElement !== input) {
            input.value = rawOf(a1) != null ? String(rawOf(a1)) : '';
            formulaBarTargetRef = a1;
            autoGrowFormulaInput(input);
        }
    }

    function commitFormulaBar() {
        if (!formulaBarTargetRef || !OE.canEdit) return;
        var input = $('#of-formula-input');
        if (!input) return;
        var a1 = formulaBarTargetRef;
        var val = input.value;
        // Chốt công thức (Enter/blur, không còn thao tác chọn thêm vùng nữa) — lúc này mới tự
        // đóng nốt các dấu ")" còn thiếu, để user không phải tự gõ khi vừa click chọn xong ô/vùng.
        if (/^=/.test(val)) {
            var opens = (val.match(/\(/g) || []).length;
            var closes = (val.match(/\)/g) || []).length;
            if (opens > closes) val += ')'.repeat(opens - closes);
        }
        if (val === '') delete sheet.cells[a1];
        else { sheet.cells[a1] = sheet.cells[a1] || {}; sheet.cells[a1].v = val; }
        OE.scheduleAutosave();
        render();
    }

    function hideFormulaSuggestions() {
        var box = $('#of-formula-suggest');
        if (box) box.style.display = 'none';
        suggestIndex = -1;
    }

    function chooseSuggestion(fn) {
        var input = $('#of-formula-input');
        if (!input) return;
        input.value = '=' + fn + '(';
        hideFormulaSuggestions();
        input.focus();
        input.setSelectionRange(input.value.length, input.value.length);
        autoGrowFormulaInput(input);
    }

    /** Chỉ đề xuất khi vừa gõ "=" + vài chữ cái, CHƯA có dấu ngoặc — gõ tiếp thì thôi gợi ý,
     *  để người dùng tự do gõ tham chiếu ("=C3+C4") mà không bị dropdown che mất. */
    function showFormulaSuggestions(text) {
        var box = $('#of-formula-suggest');
        if (!box) return;
        var m = /^=([A-Za-z]*)$/.exec(text);
        if (!m) { hideFormulaSuggestions(); return; }
        var partial = m[1].toUpperCase();
        var matches = FORMULA_FN_NAMES.filter(function (f) { return f.indexOf(partial) === 0; });
        if (!matches.length) { hideFormulaSuggestions(); return; }
        suggestIndex = 0;
        box.innerHTML = matches.map(function (f, i) {
            return '<div class="of-formula-suggest-item' + (i === 0 ? ' is-active' : '') + '" data-fn="' + f + '">' + f + '(...)</div>';
        }).join('');
        box.style.display = 'block';
        box.querySelectorAll('.of-formula-suggest-item').forEach(function (it) {
            it.addEventListener('mousedown', function (e) {
                e.preventDefault(); // tránh input bị blur trước khi xử lý
                chooseSuggestion(it.dataset.fn);
            });
        });
    }

    function moveSuggestionHighlight(delta) {
        var box = $('#of-formula-suggest');
        if (!box || box.style.display === 'none') return;
        var items = box.querySelectorAll('.of-formula-suggest-item');
        if (!items.length) return;
        suggestIndex = (suggestIndex + delta + items.length) % items.length;
        items.forEach(function (it, i) { it.classList.toggle('is-active', i === suggestIndex); });
    }

    function applySuggestionIfAny() {
        var box = $('#of-formula-suggest');
        if (!box || box.style.display === 'none' || suggestIndex < 0) return false;
        var items = box.querySelectorAll('.of-formula-suggest-item');
        if (!items.length) return false;
        chooseSuggestion(items[suggestIndex].dataset.fn);
        return true;
    }

    function initFormulaBar() {
        var input = $('#of-formula-input');
        if (!input) return;
        if (!OE.canEdit) { input.disabled = true; return; }
        input.addEventListener('input', function () { showFormulaSuggestions(input.value); autoGrowFormulaInput(input); });
        input.addEventListener('keydown', function (e) {
            if (e.key === 'Tab' && applySuggestionIfAny()) { e.preventDefault(); return; }
            if (e.key === 'ArrowDown' || e.key === 'ArrowUp') {
                if ($('#of-formula-suggest').style.display !== 'none') {
                    e.preventDefault();
                    moveSuggestionHighlight(e.key === 'ArrowDown' ? 1 : -1);
                }
                return;
            }
            // Alt+Enter: chèn 1 dấu xuống dòng NGAY TRONG ô công thức (giống Excel) — cố định
            // chuỗi đã gõ, KHÔNG chốt/di chuyển ô như Enter thường. Chuỗi xuống dòng này được lưu
            // nguyên vào giá trị ô và hiển thị xuống dòng lại trong ô trên bảng tính (xem
            // cellStyleAttr()'s `wrapText`).
            if (e.key === 'Enter' && e.altKey) {
                e.preventDefault();
                hideFormulaSuggestions();
                var start = input.selectionStart, end = input.selectionEnd;
                if (typeof input.setRangeText === 'function') input.setRangeText('\n', start, end, 'end');
                else input.value = input.value.slice(0, start) + '\n' + input.value.slice(end);
                autoGrowFormulaInput(input);
                return;
            }
            if (e.key === 'Enter') {
                e.preventDefault();
                if (applySuggestionIfAny()) return;
                hideFormulaSuggestions();
                commitFormulaBar();
                moveActive(1, 0);
            } else if (e.key === 'Escape') {
                hideFormulaSuggestions();
                syncFormulaBar();
                input.blur();
            }
        });
        input.addEventListener('blur', function () {
            setTimeout(function () { hideFormulaSuggestions(); commitFormulaBar(); }, 150);
        });
    }

    /** Nhận diện B/I/U/căn lề/cỡ chữ/định dạng số đang bật theo ô đang chọn (ô đầu tiên của vùng). */
    function updateToolbarState() {
        var toolbar = $('#of-sheet-toolbar');
        if (!toolbar || !sel) return;
        var s = styleOf(ref(sel.c1, sel.r1));
        ['b', 'i', 'u'].forEach(function (k) {
            var btn = toolbar.querySelector('[data-s="' + k + '"]');
            if (btn) btn.classList.toggle('is-active', !!s[k]);
        });
        ['left', 'center', 'right'].forEach(function (a) {
            var btn = toolbar.querySelector('[data-align="' + a + '"]');
            if (btn) btn.classList.toggle('is-active', s.align === a);
        });
        var fsSel = $('#of-tb-fontsize');
        if (fsSel && s.fs) fsSel.value = String(s.fs);
        var fmtSel = $('#of-tb-fmt');
        if (fmtSel) fmtSel.value = s.fmt || 'none';
    }

    function initToolbar() {
        var toolbar = $('#of-sheet-toolbar');
        toolbar.innerHTML = TOOLBAR_HTML;
        if (!OE.canEdit) {
            toolbar.querySelectorAll('button,input,select').forEach(function (b) { b.disabled = true; });
            return;
        }
        toolbar.addEventListener('click', function (e) {
            var btn = e.target.closest('.of-tb-btn');
            if (!btn) return;
            if (btn.id === 'of-tb-paintformat') {
                if (formatPainter) deactivatePainter(); else activatePainter(false);
                return;
            }
            if (btn.dataset.s) applyStyleToSelection(function (s) { s[btn.dataset.s] = !s[btn.dataset.s]; });
            else if (btn.dataset.align) applyStyleToSelection(function (s) { s.align = btn.dataset.align; });
            else if (btn.dataset.act === 'addrow') {
                // Chèn NGAY DƯỚI dòng của ô đang chọn (không phải thêm vào cuối bảng).
                insertRow(sel ? sel.r1 + 1 : sheet.rows + 1);
                OE.scheduleAutosave(); render();
            }
            else if (btn.dataset.act === 'addcol') {
                // Chèn NGAY BÊN PHẢI cột của ô đang chọn (không phải thêm vào cuối bảng).
                insertCol(sel ? sel.c1 + 1 : sheet.cols);
                OE.scheduleAutosave(); render();
            }
        });
        var paintBtn = $('#of-tb-paintformat');
        if (paintBtn) paintBtn.addEventListener('dblclick', function (e) {
            e.preventDefault(); e.stopPropagation();
            activatePainter(true);
        });
        $('#of-tb-color').addEventListener('input', function (e) {
            applyStyleToSelection(function (s) { s.color = e.target.value; });
        });
        $('#of-tb-bg').addEventListener('input', function (e) {
            applyStyleToSelection(function (s) { s.bg = e.target.value; });
        });
        $('#of-tb-fontsize').addEventListener('change', function (e) {
            applyStyleToSelection(function (s) { s.fs = parseInt(e.target.value, 10); });
        });
        $('#of-tb-fmt').addEventListener('change', function (e) {
            applyStyleToSelection(function (s) { s.fmt = e.target.value; });
        });
    }

    /* ---------------- Khởi tạo ---------------- */
    function init() {
        var wrap = $('#of-sheet-wrap');
        if (!wrap) return;
        try {
            var loaded = JSON.parse((OE.doc && OE.doc.content) || '{}');
            sheet.cols = loaded.cols || 12;
            sheet.rows = loaded.rows || 30;
            sheet.colWidths = toObjMap(loaded.colWidths);
            sheet.rowHeights = toObjMap(loaded.rowHeights);
            sheet.cells = toObjMap(loaded.cells);
            sheet.charts = loaded.charts || [];
            sheet.hideGridlines = !!loaded.hideGridlines;
        } catch (e) {}

        OE.registerContentGetter(function () { return JSON.stringify(sheet); });
        OE.registerContentSetter(function (json) {
            try {
                var loaded = JSON.parse(json || '{}');
                sheet.cols = loaded.cols || 12; sheet.rows = loaded.rows || 30;
                sheet.colWidths = toObjMap(loaded.colWidths); sheet.rowHeights = toObjMap(loaded.rowHeights);
                sheet.cells = toObjMap(loaded.cells);
                sheet.charts = loaded.charts || [];
                sheet.hideGridlines = !!loaded.hideGridlines;
            } catch (e) {}
            applyGridlinesUi();
            render();
        });
        OE.registerIsEditingCheck(function () { return editingRef !== null; });

        var chartsPanel = $('#of-sheet-charts');
        if (chartsPanel && sheet.charts && sheet.charts.length) { chartsPanel.style.display = ''; chartsVisible = true; }

        initToolbar();
        initFormulaBar();
        initFormulaResizer();
        initGridlinesToggle();
        initBorderTool();
        sel = { r1: 1, c1: 0, r2: 1, c2: 0 };
        applyGridlinesUi();
        render();
        OE.initChrome();
    }

    document.addEventListener('DOMContentLoaded', init);
})();
