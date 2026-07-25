/* ===== Quản lý dự án — canvas.js : vẽ phác thảo bản thiết kế (SVG) ===== */
window.PMX = window.PMX || {};
window.PMCanvas = (function () {
    'use strict';
    var X = window.PMX;
    var SVGNS = 'http://www.w3.org/2000/svg';

    var $svg = document.getElementById('pm-svg');
    var $wrap = document.getElementById('pm-canvas-wrap');
    var $status = document.getElementById('pm-canvas-status');
    var $fill = document.getElementById('pm-fill');
    var $stroke = document.getElementById('pm-stroke');

    var state = {
        sid: 0, version: 0, dirty: false, tool: 'select',
        shapes: [], selected: null, seq: 1, interacting: false, saveTimer: null
    };

    /* ---------- helpers ---------- */
    function mk(tag, attrs) { var e = document.createElementNS(SVGNS, tag); for (var k in attrs) e.setAttribute(k, attrs[k]); return e; }
    function pt(e) { var r = $svg.getBoundingClientRect(); return { x: e.clientX - r.left, y: e.clientY - r.top }; }
    function nid() { return 's' + (state.seq++) + '_' + Date.now().toString(36); }

    function setTool(t) {
        state.tool = t;
        document.querySelectorAll('.pm-shape[data-shape]').forEach(function (b) { b.classList.toggle('active', b.dataset.shape === t); });
        $svg.style.cursor = (t === 'select') ? 'default' : 'crosshair';
    }
    document.querySelectorAll('.pm-shape[data-shape]').forEach(function (b) {
        b.addEventListener('click', function () {
            if (b.dataset.shape === 'text') { openTextForNew(); return; } // bấm A: mở ngay thanh nhập
            setTool(b.dataset.shape);
        });
    });
    // Mở thanh nhập cho văn bản mới, vị trí mặc định gần góc trên-trái vùng đang xem.
    function openTextForNew() {
        setTool('select');
        var x = $wrap.scrollLeft + 50, y = $wrap.scrollTop + 60;
        openTextBar(null, x, y);
    }
    // Trợ giúp xoay điểm quanh tâm (deg độ).
    function rotPt(x, y, cx, cy, deg) {
        var r = deg * Math.PI / 180, c = Math.cos(r), s = Math.sin(r), dx = x - cx, dy = y - cy;
        return { x: cx + dx * c - dy * s, y: cy + dx * s + dy * c };
    }
    // line / arrow / arrow2: dạng "2 đầu mút" (xoay bằng cách kéo đầu mút, không có núm xoay).
    function isLineLike(s) { var t = (typeof s === 'string') ? s : (s && s.type); return t === 'line' || t === 'arrow' || t === 'arrow2'; }
    // Vẽ đầu mũi tên (2 nét) tại điểm tip theo hướng ang.
    function arrowHead(parent, tipx, tipy, ang, color, sw) {
        var len = Math.max(10, (sw || 2) * 5), spread = 0.45;
        [ang + Math.PI - spread, ang + Math.PI + spread].forEach(function (a) {
            parent.appendChild(mk('line', { x1: tipx, y1: tipy, x2: tipx + len * Math.cos(a), y2: tipy + len * Math.sin(a), stroke: color, 'stroke-width': sw, 'stroke-linecap': 'round' }));
        });
    }
    // Tách văn bản thành các dòng: theo \n và tự xuống dòng nếu có chiều rộng s.w.
    function textLines(s) {
        var fs = s.fs || 18;
        var paras = String(s.text || 'Văn bản').split('\n');
        if (!s.w) return paras;
        var maxChars = Math.max(1, Math.floor(s.w / (fs * 0.56)));
        var out = [];
        paras.forEach(function (p) {
            if (p === '') { out.push(''); return; }
            var words = p.split(' '), line = '';
            words.forEach(function (w) {
                var test = line ? line + ' ' + w : w;
                if (test.length > maxChars && line) { out.push(line); line = w; }
                else line = test;
            });
            if (line) out.push(line);
        });
        return out.length ? out : [''];
    }
    document.getElementById('pm-canvas-delete').addEventListener('click', function () { deleteSelected(); });
    document.addEventListener('keydown', function (e) {
        if ((e.key === 'Delete' || e.key === 'Backspace') && state.selected && document.activeElement === document.body) {
            e.preventDefault(); deleteSelected();
        }
    });

    /* ---------- render ---------- */
    function shapeNode(s) {
        var common = { 'data-shape-id': s.id, fill: s.fill || 'none', stroke: s.stroke || '#16a34a', 'stroke-width': s.sw || 2 };
        var n;
        if (s.type === 'rect' || s.type === 'square') {
            n = mk('rect', Object.assign({ x: s.x, y: s.y, width: Math.max(1, s.w), height: Math.max(1, s.h), rx: 4 }, common));
        } else if (s.type === 'rrect') {
            var rr = Math.max(0, Math.min(s.r == null ? 14 : s.r, Math.min(s.w, s.h) / 2));
            n = mk('rect', Object.assign({ x: s.x, y: s.y, width: Math.max(1, s.w), height: Math.max(1, s.h), rx: rr, ry: rr }, common));
        } else if (s.type === 'circle') {
            n = mk('ellipse', Object.assign({ cx: s.x + s.w / 2, cy: s.y + s.h / 2, rx: Math.max(1, s.w / 2), ry: Math.max(1, s.h / 2) }, common));
        } else if (s.type === 'triangle') {
            var p = (s.x + s.w / 2) + ',' + s.y + ' ' + s.x + ',' + (s.y + s.h) + ' ' + (s.x + s.w) + ',' + (s.y + s.h);
            n = mk('polygon', Object.assign({ points: p }, common));
        } else if (s.type === 'line') {
            n = mk('line', { 'data-shape-id': s.id, x1: s.x, y1: s.y, x2: s.x2, y2: s.y2, stroke: s.stroke || '#16a34a', 'stroke-width': s.sw || 2, 'stroke-linecap': 'round' });
        } else if (s.type === 'arrow' || s.type === 'arrow2') {
            n = mk('g', { 'data-shape-id': s.id });
            var col = s.stroke || '#16a34a', asw = s.sw || 2;
            n.appendChild(mk('line', { x1: s.x, y1: s.y, x2: s.x2, y2: s.y2, stroke: col, 'stroke-width': asw, 'stroke-linecap': 'round' }));
            var aang = Math.atan2(s.y2 - s.y, s.x2 - s.x);
            arrowHead(n, s.x2, s.y2, aang, col, asw);
            if (s.type === 'arrow2') arrowHead(n, s.x, s.y, aang + Math.PI, col, asw);
        } else if (s.type === 'text') {
            var fs = s.fs || 18;
            n = mk('text', { 'data-shape-id': s.id, x: s.x, y: s.y + fs, fill: s.stroke || '#111827', 'font-size': fs, 'font-family': 'Arial, sans-serif' });
            var lines = textLines(s);
            lines.forEach(function (ln, i) {
                var ts = mk('tspan', { x: s.x, dy: (i === 0 ? 0 : Math.round(fs * 1.25)) });
                ts.textContent = ln === '' ? ' ' : ln;
                n.appendChild(ts);
            });
            if (s.rotate) {
                var tb = bbox(s);
                n.setAttribute('transform', 'rotate(' + s.rotate + ' ' + (tb.x + tb.w / 2) + ' ' + (tb.y + tb.h / 2) + ')');
            }
        }
        // Xoay cho các hình khối (line/arrow xoay bằng đầu mút; text tự xử lý ở trên).
        if (n && s.rotate && s.type !== 'text' && !isLineLike(s)) {
            var rb = bbox(s);
            n.setAttribute('transform', 'rotate(' + s.rotate + ' ' + (rb.x + rb.w / 2) + ' ' + (rb.y + rb.h / 2) + ')');
        }
        return n;
    }

    function render() {
        $svg.innerHTML = '';
        state.shapes.forEach(function (s) {
            var n = shapeNode(s);
            if (n) {
                n.addEventListener('mousedown', onShapeDown);
                if (s.link) n.style.cursor = 'pointer';
                // double-click xử lý thủ công trong onShapeDown (render() rebuild node
                // nên listener dblclick gốc không kích hoạt được).
                $svg.appendChild(n);
            }
        });
        if (state.selected) drawSelection(state.selected);
        $status.textContent = state.shapes.length + ' hình' + (state.dirty ? ' · đang lưu...' : '');
    }

    function bbox(s) {
        if (isLineLike(s)) {
            return { x: Math.min(s.x, s.x2), y: Math.min(s.y, s.y2), w: Math.abs(s.x2 - s.x), h: Math.abs(s.y2 - s.y) };
        }
        if (s.type === 'text') {
            var fs = s.fs || 18, lines = textLines(s), maxLen = 0;
            lines.forEach(function (l) { maxLen = Math.max(maxLen, l.length); });
            var w = s.w ? s.w : Math.max(40, maxLen * fs * 0.55);
            return { x: s.x, y: s.y, w: w, h: lines.length * fs * 1.3 };
        }
        return { x: s.x, y: s.y, w: s.w, h: s.h };
    }

    function drawSelection(id) {
        var s = byId(id); if (!s) return;
        var b = bbox(s);
        if (s.type === 'text') { drawTextSelection(s, b); return; }
        if (isLineLike(s)) {
            var pad = 3;
            $svg.appendChild(mk('rect', { x: b.x - pad, y: b.y - pad, width: b.w + pad * 2, height: b.h + pad * 2, class: 'sel-outline' }));
            addHandle(s.x, s.y, 'p1'); addHandle(s.x2, s.y2, 'p2');
            return;
        }
        drawBoxSelection(s, b);
    }
    // Vùng chọn hình khối: xoay (trên), 4 góc resize, + núm bo góc nếu là rrect.
    function drawBoxSelection(s, b) {
        var deg = s.rotate || 0, cx = b.x + b.w / 2, cy = b.y + b.h / 2;
        var g = mk('g', { transform: 'rotate(' + deg + ' ' + cx + ' ' + cy + ')' });
        g.appendChild(mk('rect', { x: b.x - 3, y: b.y - 3, width: b.w + 6, height: b.h + 6, class: 'sel-outline' }));
        g.appendChild(mk('line', { x1: cx, y1: b.y - 3, x2: cx, y2: b.y - 20, class: 'sel-outline' }));
        addHandleTo(g, cx, b.y - 22, 'rot', 'rot');
        addHandleTo(g, b.x, b.y, 'nw', 'sq'); addHandleTo(g, b.x + b.w, b.y, 'ne', 'sq');
        addHandleTo(g, b.x, b.y + b.h, 'sw', 'sq'); addHandleTo(g, b.x + b.w, b.y + b.h, 'se', 'sq');
        if (s.type === 'rrect') {
            var rr = Math.max(0, Math.min(s.r == null ? 14 : s.r, Math.min(b.w, b.h) / 2));
            addHandleTo(g, b.x + Math.max(rr, 10), b.y, 'radius', 'rad'); // bo góc
        }
        $svg.appendChild(g);
    }
    // Vùng chọn cho text: xoay (trên), gom ngang/wrap (phải), cỡ chữ (góc dưới-phải).
    function drawTextSelection(s, b) {
        var deg = s.rotate || 0, cx = b.x + b.w / 2, cy = b.y + b.h / 2;
        var g = mk('g', { transform: 'rotate(' + deg + ' ' + cx + ' ' + cy + ')' });
        g.appendChild(mk('rect', { x: b.x - 3, y: b.y - 3, width: b.w + 6, height: b.h + 6, class: 'sel-outline' }));
        g.appendChild(mk('line', { x1: cx, y1: b.y - 3, x2: cx, y2: b.y - 20, class: 'sel-outline' }));
        addHandleTo(g, cx, b.y - 22, 'trot', 'rot');           // xoay
        addHandleTo(g, b.x + b.w + 3, cy, 'twidth', 'sq');     // gom ngang → wrap
        addHandleTo(g, b.x + b.w + 3, b.y + b.h + 3, 'tfs', 'sq'); // cỡ chữ
        $svg.appendChild(g);
    }
    function addHandle(x, y, which) { addHandleTo($svg, x, y, which, 'sq'); }
    function addHandleTo(parent, x, y, which, kind) {
        var h;
        if (kind === 'rot') h = mk('circle', { cx: x, cy: y, r: 6, class: 'sel-handle rot-handle', 'data-handle': which });
        else if (kind === 'rad') h = mk('circle', { cx: x, cy: y, r: 5, class: 'sel-handle rad-handle', 'data-handle': which });
        else h = mk('rect', { x: x - 5, y: y - 5, width: 10, height: 10, class: 'sel-handle', 'data-handle': which });
        h.addEventListener('mousedown', onHandleDown);
        parent.appendChild(h);
    }

    function byId(id) { for (var i = 0; i < state.shapes.length; i++) if (state.shapes[i].id === id) return state.shapes[i]; return null; }

    /* ---------- create (vẽ mới) ---------- */
    var draft = null, dragMode = null, dragStart = null, dragOrig = null, resizeWhich = null;

    $svg.addEventListener('mousedown', function (e) {
        if (e.target !== $svg) return; // bấm vào nền
        if (state.tool === 'select') { select(null); return; }
        var p = pt(e);
        if (state.tool === 'text') {
            // Mở thanh nhập văn bản ở dưới; bấm "Nhập" sẽ chèn vào vị trí đã click.
            openTextBar(null, p.x, p.y);
            setTool('select');
            return;
        }
        state.interacting = true;
        draft = newShape(state.tool, p);
        state.shapes.push(draft);
        render();
    });

    function newShape(type, p) {
        // ax/ay = điểm neo cố định (nơi nhấn chuột); x/y/w/h tính lại mỗi lần kéo.
        var base = { id: nid(), type: type, ax: p.x, ay: p.y, x: p.x, y: p.y, w: 1, h: 1, fill: ($fill.dataset.none === '1' ? 'none' : $fill.value), stroke: $stroke.value, sw: 2 };
        if (isLineLike(type)) { base.x2 = p.x; base.y2 = p.y; }
        if (type === 'rrect') base.r = 14;
        return base;
    }

    window.addEventListener('mousemove', function (e) {
        if (draft) {
            var p = pt(e);
            if (isLineLike(draft)) { draft.x2 = p.x; draft.y2 = p.y; }
            else {
                var w = p.x - draft.ax, h = p.y - draft.ay;
                if (draft.type === 'square' || draft.type === 'circle') { var m = Math.max(Math.abs(w), Math.abs(h)); w = (w < 0 ? -m : m); h = (h < 0 ? -m : m); }
                draft.w = Math.abs(w); draft.h = Math.abs(h);
                draft.x = w < 0 ? draft.ax + w : draft.ax;
                draft.y = h < 0 ? draft.ay + h : draft.ay;
            }
            render();
        } else if (dragMode === 'move' && state.selected) {
            var p2 = pt(e), s = byId(state.selected);
            var dx = p2.x - dragStart.x, dy = p2.y - dragStart.y;
            if (isLineLike(s)) { s.x = dragOrig.x + dx; s.y = dragOrig.y + dy; s.x2 = dragOrig.x2 + dx; s.y2 = dragOrig.y2 + dy; }
            else { s.x = dragOrig.x + dx; s.y = dragOrig.y + dy; }
            render();
        } else if (dragMode === 'resize' && state.selected) {
            resizeMove(pt(e));
        }
    });

    window.addEventListener('mouseup', function () {
        if (draft) {
            if ((isLineLike(draft) && Math.abs(draft.x2 - draft.x) + Math.abs(draft.y2 - draft.y) < 4) ||
                (!isLineLike(draft) && draft.w < 4 && draft.h < 4)) {
                // quá nhỏ -> hủy
                state.shapes.pop();
            } else {
                select(draft.id); markDirty();
            }
            draft = null; state.interacting = false; setTool('select'); render();
        }
        if (dragMode) { dragMode = null; resizeWhich = null; state.interacting = false; markDirty(); }
    });

    /* ---------- move / resize ---------- */
    var lastClick = { id: null, t: 0 };
    function onShapeDown(e) {
        if (state.tool !== 'select') return;
        e.stopPropagation();
        var id = e.currentTarget.getAttribute('data-shape-id');
        var s = byId(id);
        // Tự phát hiện double-click (vì render() rebuild node làm hỏng sự kiện dblclick gốc).
        var now = Date.now();
        if (lastClick.id === id && (now - lastClick.t) < 350) {
            lastClick.t = 0;
            if (s && s.link) { window.PMChat.scrollTo(s.link); return; }
            if (s && s.type === 'text') { openTextBar(s); return; }
        }
        lastClick = { id: id, t: now };
        select(id);
        dragMode = 'move'; dragStart = pt(e); state.interacting = true;
        dragOrig = isLineLike(s) ? { x: s.x, y: s.y, x2: s.x2, y2: s.y2 } : { x: s.x, y: s.y };
    }
    function onHandleDown(e) {
        e.stopPropagation();
        dragMode = 'resize'; resizeWhich = e.target.getAttribute('data-handle');
        dragStart = pt(e); state.interacting = true;
        var s = byId(state.selected);
        dragOrig = JSON.parse(JSON.stringify(s));
    }
    function resizeMove(p) {
        var s = byId(state.selected); if (!s) return;
        if (isLineLike(s)) {
            if (resizeWhich === 'p1') { s.x = p.x; s.y = p.y; } else { s.x2 = p.x; s.y2 = p.y; }
            render(); return;
        }
        if (s.type === 'text') {
            var o = dragOrig, ob = bbox(o);
            var ocx = ob.x + ob.w / 2, ocy = ob.y + ob.h / 2, deg = o.rotate || 0;
            if (resizeWhich === 'trot') { // xoay quanh tâm
                s.rotate = Math.round(Math.atan2(p.y - ocy, p.x - ocx) * 180 / Math.PI + 90);
                render(); return;
            }
            var locNow = rotPt(p.x, p.y, ocx, ocy, -deg);
            var locStart = rotPt(dragStart.x, dragStart.y, ocx, ocy, -deg);
            if (resizeWhich === 'twidth') {        // gom ngang → tự xuống dòng
                var baseW = o.w || ob.w;
                s.w = Math.max(40, Math.round(baseW + (locNow.x - locStart.x)));
            } else if (resizeWhich === 'tfs') {     // cỡ chữ
                s.fs = Math.max(10, Math.min(200, Math.round((o.fs || 18) + (locNow.y - locStart.y) * 0.5)));
            }
            render(); return;
        }
        // Hình khối (rect/square/rrect/circle/triangle): có xoay + bo góc.
        var o = dragOrig, deg = o.rotate || 0, ob = bbox(o);
        var ocx = ob.x + ob.w / 2, ocy = ob.y + ob.h / 2;
        if (resizeWhich === 'rot') { // xoay quanh tâm
            s.rotate = Math.round(Math.atan2(p.y - ocy, p.x - ocx) * 180 / Math.PI + 90);
            render(); return;
        }
        var loc = rotPt(p.x, p.y, ocx, ocy, -deg); // khử xoay về hệ tọa độ gốc của hình
        if (resizeWhich === 'radius') { // bo góc rrect
            s.r = Math.max(0, Math.min(Math.min(s.w, s.h) / 2, Math.round(loc.x - s.x)));
            render(); return;
        }
        var x1 = o.x, y1 = o.y, x2 = o.x + o.w, y2 = o.y + o.h;
        if (resizeWhich === 'nw') { x1 = loc.x; y1 = loc.y; }
        else if (resizeWhich === 'ne') { x2 = loc.x; y1 = loc.y; }
        else if (resizeWhich === 'sw') { x1 = loc.x; y2 = loc.y; }
        else if (resizeWhich === 'se') { x2 = loc.x; y2 = loc.y; }
        s.x = Math.min(x1, x2); s.y = Math.min(y1, y2);
        s.w = Math.max(2, Math.abs(x2 - x1)); s.h = Math.max(2, Math.abs(y2 - y1));
        render();
    }

    function select(id) { state.selected = id; render(); }
    function deleteSelected() {
        if (!state.selected) return;
        state.shapes = state.shapes.filter(function (s) { return s.id !== state.selected; });
        state.selected = null; markDirty(); render();
    }

    /* ---------- Thanh nhập văn bản (textarea + nút "Nhập" ở dưới khối thiết kế) ---------- */
    var $textBar = document.getElementById('pm-text-bar');
    var $textInput = document.getElementById('pm-text-input');
    var textTarget = null; // {mode:'new', x, y} hoặc {mode:'edit', shape}
    function openTextBar(shape, x, y) {
        if (shape) { textTarget = { mode: 'edit', shape: shape }; $textInput.value = shape.text || ''; }
        else { textTarget = { mode: 'new', x: x, y: y }; $textInput.value = ''; }
        $textBar.style.display = '';
        setTimeout(function () { $textInput.focus(); }, 30);
    }
    function closeTextBar() { $textBar.style.display = 'none'; textTarget = null; }
    function commitTextBar() {
        if (!textTarget) return;
        var v = $textInput.value.replace(/\s+$/, ''); // bỏ khoảng trắng/đầu dòng cuối
        if (textTarget.mode === 'edit') {
            var s = textTarget.shape;
            if (v.trim() === '') { state.shapes = state.shapes.filter(function (x) { return x.id !== s.id; }); if (state.selected === s.id) state.selected = null; }
            else s.text = v;
        } else if (v.trim() !== '') {
            var ns = { id: nid(), type: 'text', x: textTarget.x, y: textTarget.y, fs: 18, text: v, stroke: $stroke.value };
            state.shapes.push(ns); select(ns.id);
        }
        closeTextBar(); markDirty(); render();
    }
    if ($textBar) {
        document.getElementById('pm-text-ok').addEventListener('click', commitTextBar);
        document.getElementById('pm-text-cancel').addEventListener('click', closeTextBar);
        $textInput.addEventListener('keydown', function (e) {
            if (e.key === 'Enter' && (e.ctrlKey || e.metaKey)) { e.preventDefault(); commitTextBar(); }
            if (e.key === 'Escape') { closeTextBar(); }
        });
    }

    // cập nhật màu cho shape đang chọn
    $fill.addEventListener('input', function () {
        $fill.dataset.none = ''; // chọn màu = tắt chế độ trong suốt
        var s = byId(state.selected);
        if (s && s.type === 'text') { s.stroke = $fill.value; markDirty(); render(); return; }
        if (s && s.type !== 'line') { s.fill = $fill.value; markDirty(); render(); }
    });
    $stroke.addEventListener('input', function () { var s = byId(state.selected); if (s) { s.stroke = $stroke.value; markDirty(); render(); } });

    // Nền trong suốt (không màu) cho hình đang chọn + cho các hình tạo sau.
    document.getElementById('pm-fill-none').addEventListener('click', function () {
        $fill.dataset.none = '1';
        var s = byId(state.selected);
        if (s && s.type !== 'line' && s.type !== 'text') { s.fill = 'none'; markDirty(); render(); }
        X.toast('Nền: trong suốt');
    });

    /* ---------- lưu (debounce + version) ---------- */
    function markDirty() {
        state.dirty = true;
        clearTimeout(state.saveTimer);
        state.saveTimer = setTimeout(save, 700);
    }
    function save() {
        if (!state.sid) return;
        var data = JSON.stringify({ shapes: state.shapes });
        X.post('canvasSave', { session_id: state.sid, data: data, base_version: state.version }).then(function (res) {
            if (res.ok) { state.version = res.version; state.dirty = false; $status.textContent = state.shapes.length + ' hình · đã lưu'; }
            else if (res.conflict) {
                X.toast('Bản vẽ đã được người khác cập nhật — đang tải lại.');
                state.dirty = false; load();
            }
        });
    }

    function load() {
        if (!state.sid) return;
        X.get('canvasGet', { session_id: state.sid }).then(function (res) {
            if (!res.ok) return;
            state.version = res.canvas.version;
            state.shapes = (res.canvas.data && res.canvas.data.shapes) || [];
            state.selected = null; state.dirty = false;
            render();
        });
    }

    /* ---------- render ra PNG (thumbnail / xuất file / xem full) ---------- */
    function renderShapesToPNG(shapes, maxW) {
        return new Promise(function (resolve) {
            if (!shapes || !shapes.length) { resolve(''); return; }
            var minX = 1e9, minY = 1e9, maxX = -1e9, maxY = -1e9;
            shapes.forEach(function (s) { var b = bbox(s); minX = Math.min(minX, b.x); minY = Math.min(minY, b.y); maxX = Math.max(maxX, b.x + b.w); maxY = Math.max(maxY, b.y + b.h); });
            var pad = 16; minX -= pad; minY -= pad; maxX += pad; maxY += pad;
            var w = Math.max(10, maxX - minX), h = Math.max(10, maxY - minY);
            // Dựng phần thân chỉ gồm shapes (không lẫn outline/handle vùng chọn).
            var inner = '';
            shapes.forEach(function (s) { var n = shapeNode(s); if (n) inner += n.outerHTML || new XMLSerializer().serializeToString(n); });
            var svgStr = '<svg xmlns="' + SVGNS + '" width="' + w + '" height="' + h + '" viewBox="' + minX + ' ' + minY + ' ' + w + ' ' + h + '">' +
                '<rect x="' + minX + '" y="' + minY + '" width="' + w + '" height="' + h + '" fill="#ffffff"/>' + inner + '</svg>';
            var img = new Image();
            img.onload = function () {
                var scale = maxW ? Math.min(2, maxW / w) : 1;
                var c = document.createElement('canvas'); c.width = Math.round(w * scale); c.height = Math.round(h * scale);
                var ctx = c.getContext('2d'); ctx.drawImage(img, 0, 0, c.width, c.height);
                try { resolve(c.toDataURL('image/png')); } catch (e) { resolve(''); }
            };
            img.onerror = function () { resolve(''); };
            img.src = 'data:image/svg+xml;base64,' + btoa(unescape(encodeURIComponent(svgStr)));
        });
    }
    function renderPNG(maxW) { return renderShapesToPNG(state.shapes, maxW); }

    // Xuất PNG (tải file)
    document.getElementById('pm-canvas-export').addEventListener('click', function () {
        if (!state.shapes.length) { X.toast('Chưa có hình nào để xuất.'); return; }
        renderPNG(2000).then(function (url) {
            if (!url) { X.toast('Không xuất được.'); return; }
            var a = document.createElement('a');
            a.href = url; a.download = 'ban-thiet-ke-' + state.sid + '.png';
            document.body.appendChild(a); a.click(); a.remove();
        });
    });

    // Gắn tin nhắn cho hình đang chọn (liên kết shape ↔ chat)
    document.getElementById('pm-canvas-link').addEventListener('click', function () {
        if (!state.selected) { X.toast('Chọn 1 hình trước.'); return; }
        var note = prompt('Ghi chú / thảo luận cho hình này:');
        if (note === null) return; note = note.trim(); if (!note) return;
        var sid = state.selected;
        window.PMChat.sendShapeNote(sid, note).then(function (msg) {
            if (!msg) { X.toast('Không gửi được.'); return; }
            var s = byId(sid); if (s) { s.link = msg.id; markDirty(); render(); }
            X.toast('Đã gắn tin nhắn vào hình.');
        });
    });

    /* ---------- public ---------- */
    setTool('select');
    return {
        init: function (sid) { state.sid = sid; load(); },
        switchSession: function (sid) { if (state.dirty) save(); state.sid = sid; state.shapes = []; state.selected = null; state.version = 0; render(); load(); },
        version: function () { return state.version; },
        onPollVersion: function (ver) {
            ver = parseInt(ver, 10) || 0;
            if (ver > state.version && !state.dirty && !state.interacting) load();
        },
        focus: function () { $wrap.scrollIntoView({ behavior: 'smooth', block: 'nearest' }); },
        renderShapesPNG: function (shapes, maxW) { return renderShapesToPNG(shapes, maxW || 1600); },
        focusShape: function (id) {
            var s = byId(id); if (!s) { X.toast('Hình không còn trên bản thiết kế.'); return; }
            setTool('select'); select(id);
            $wrap.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
            var node = $svg.querySelector('[data-shape-id="' + id + '"]');
            if (node) { node.classList.add('shape-flash'); setTimeout(function () { node.classList.remove('shape-flash'); }, 1400); }
        }
    };
})();
