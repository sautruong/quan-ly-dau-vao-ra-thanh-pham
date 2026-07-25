/* =====================================================================
 *  GREEN DATETIME PICKER — component dùng chung cho form "Ngày giờ ghi".
 *
 *  CÁCH DÙNG (tái sử dụng cho mọi view):
 *    1) <input type="datetime-local" id="..." class="js-green-datetime" step="1">
 *    2) Nạp public/css/shared/datetime_picker.css + file này.
 *  JS tự ẩn input gốc, dựng UI lịch + bánh xe giờ/phút/giây (màu xanh lá),
 *  và đồng bộ ngược value về input gốc (giữ định dạng datetime-local) đồng
 *  thời bắn sự kiện 'input' + 'change' để code cũ không phải sửa.
 *
 *  Khi code khác gán input.value bằng JS (không bắn event), gọi:
 *      input._dtpRefresh && input._dtpRefresh();
 *  để UI cập nhật theo value mới.
 * ===================================================================== */
(function () {
    'use strict';

    var WEEKDAYS = ['T2', 'T3', 'T4', 'T5', 'T6', 'T7', 'CN'];

    // Cấu hình 6 ô nhập tay (theo thứ tự). max: dùng để biết khi nào tự nhảy ô.
    var SEG_DEFS = [
        { key: 'd',  len: 2, max: 31,   label: 'Ngày',  ph: 'dd',   sep: '/' },
        { key: 'mo', len: 2, max: 12,   label: 'Tháng', ph: 'mm',   sep: '/' },
        { key: 'y',  len: 4, max: 9999, label: 'Năm',   ph: 'yyyy', sep: '' },
        { key: 'h',  len: 2, max: 23,   label: 'Giờ',   ph: 'HH',   sep: ':' },
        { key: 'mi', len: 2, max: 59,   label: 'Phút',  ph: 'MM',   sep: ':' },
        { key: 's',  len: 2, max: 59,   label: 'Giây',  ph: 'SS',   sep: '' }
    ];

    function pad2(n) { return String(n).padStart(2, '0'); }

    /* Parse "YYYY-MM-DDTHH:MM[:SS]" -> {y,mo,d,h,mi,s} (mo: 1-12). null nếu sai. */
    function parseValue(v) {
        var m = /^(\d{4})-(\d{2})-(\d{2})T(\d{2}):(\d{2})(?::(\d{2}))?$/.exec(String(v || ''));
        if (!m) return null;
        return {
            y:  +m[1], mo: +m[2], d: +m[3],
            h:  +m[4], mi: +m[5], s: +(m[6] || 0)
        };
    }

    function nowState() {
        var d = new Date();
        return { y: d.getFullYear(), mo: d.getMonth() + 1, d: d.getDate(),
                 h: d.getHours(), mi: d.getMinutes(), s: d.getSeconds() };
    }

    function stateToValue(st) {
        return st.y + '-' + pad2(st.mo) + '-' + pad2(st.d) + 'T' +
               pad2(st.h) + ':' + pad2(st.mi) + ':' + pad2(st.s);
    }

    function formatDisplay(st) {
        return pad2(st.h) + ':' + pad2(st.mi) + ':' + pad2(st.s) +
               '  •  ' + pad2(st.d) + '/' + pad2(st.mo) + '/' + st.y;
    }

    function daysInMonth(y, mo) { return new Date(y, mo, 0).getDate(); }

    function el(tag, cls, html) {
        var e = document.createElement(tag);
        if (cls) e.className = cls;
        if (html != null) e.innerHTML = html;
        return e;
    }

    function buildTimeList(unit, max) {
        var ul = el('ul', 'gdtp-time-list');
        ul.setAttribute('data-unit', unit);
        for (var i = 0; i < max; i++) {
            var li = el('li', '', pad2(i));
            li.setAttribute('data-val', String(i));
            ul.appendChild(li);
        }
        return ul;
    }

    function enhance(input) {
        if (input._dtpRefresh) return; // đã gắn rồi
        input.classList.add('gdtp-bound');

        // State hiện tại + tháng đang xem trên lịch.
        var st = parseValue(input.value) || nowState();
        var view = { y: st.y, mo: st.mo };

        /* ---------- Trigger ---------- */
        var trigger = el('button', 'gdtp-trigger');
        trigger.type = 'button';
        var icon = el('span', 'gdtp-trigger-icon', '<i class="fa-solid fa-calendar-days"></i>');
        var txt = el('span', 'gdtp-trigger-text');
        trigger.appendChild(icon);
        trigger.appendChild(txt);
        input.parentNode.insertBefore(trigger, input.nextSibling);

        /* ---------- Popover ---------- */
        var pop = el('div', 'gdtp-popover');

        // -- Nhập tay thủ công: 6 ô (ngày/tháng/năm giờ:phút:giây) tự nhảy ô --
        var manualRow = el('div', 'gdtp-manual-row');
        manualRow.appendChild(el('span', 'gdtp-manual-label', 'Nhập tay:'));
        var segBox = el('div', 'gdtp-seg-box');
        var segEls = {};
        SEG_DEFS.forEach(function (sg, i) {
            var inp = el('input', 'gdtp-seg gdtp-seg-' + sg.key);
            inp.type = 'text';
            inp.setAttribute('inputmode', 'numeric');
            inp.setAttribute('maxlength', String(sg.len));
            inp.setAttribute('data-key', sg.key);
            inp.setAttribute('aria-label', sg.label);
            inp.placeholder = sg.ph;
            segBox.appendChild(inp);
            segEls[sg.key] = inp;
            if (sg.sep) segBox.appendChild(el('span', 'gdtp-seg-sep', sg.sep));
        });
        manualRow.appendChild(segBox);
        var segOrder = SEG_DEFS.map(function (s) { return s.key; });

        // -- Calendar --
        var cal = el('div', 'gdtp-calendar');
        var header = el('div', 'gdtp-cal-header');
        var btnPrev = el('button', 'gdtp-nav gdtp-prev', '‹'); btnPrev.type = 'button';
        var btnNext = el('button', 'gdtp-nav gdtp-next', '›'); btnNext.type = 'button';
        var selWrap = el('div', 'gdtp-cal-selects');
        var selMonth = el('select', 'gdtp-sel-month');
        for (var mo = 1; mo <= 12; mo++) {
            var om = el('option', '', 'Tháng ' + mo); om.value = String(mo); selMonth.appendChild(om);
        }
        var selYear = el('select', 'gdtp-sel-year');
        selWrap.appendChild(selMonth);
        selWrap.appendChild(selYear);
        header.appendChild(btnPrev);
        header.appendChild(selWrap);
        header.appendChild(btnNext);

        var weekdays = el('div', 'gdtp-weekdays');
        WEEKDAYS.forEach(function (w, i) {
            weekdays.appendChild(el('span', i === 6 ? 'is-sun' : '', w));
        });
        var daysGrid = el('div', 'gdtp-days');
        cal.appendChild(header);
        cal.appendChild(weekdays);
        cal.appendChild(daysGrid);

        // -- Time wheels --
        var timeBox = el('div', 'gdtp-time');
        timeBox.appendChild(el('div', 'gdtp-time-title', 'Giờ ghi'));
        var timeCols = el('div', 'gdtp-time-cols');
        [['hour', 'Giờ', 24], ['minute', 'Phút', 60], ['second', 'Giây', 60]].forEach(function (c) {
            var col = el('div', 'gdtp-time-col');
            col.appendChild(el('div', 'gdtp-time-col-label', c[1]));
            col.appendChild(buildTimeList(c[0], c[2]));
            timeCols.appendChild(col);
        });
        timeBox.appendChild(timeCols);

        // -- Footer --
        var footer = el('div', 'gdtp-footer');
        var btnNow = el('button', 'gdtp-btn gdtp-btn-now', '<i class="fa-solid fa-clock-rotate-left"></i> Bây giờ');
        btnNow.type = 'button';
        var btnDone = el('button', 'gdtp-btn gdtp-btn-done', 'Xong');
        btnDone.type = 'button';
        footer.appendChild(btnNow);
        footer.appendChild(btnDone);

        pop.appendChild(manualRow);
        pop.appendChild(cal);
        pop.appendChild(timeBox);
        pop.appendChild(footer);
        document.body.appendChild(pop);

        /* ---------- Render helpers ---------- */
        function syncToInput(fireEvents) {
            input.value = stateToValue(st);
            txt.textContent = formatDisplay(st);
            txt.classList.remove('is-empty');
            renderManual();
            if (fireEvents) {
                input.dispatchEvent(new Event('input', { bubbles: true }));
                input.dispatchEvent(new Event('change', { bubbles: true }));
            }
        }

        function renderYearOptions() {
            var cur = view.y;
            var from = cur - 5, to = cur + 5;
            selYear.innerHTML = '';
            for (var y = from; y <= to; y++) {
                var o = el('option', '', String(y)); o.value = String(y);
                selYear.appendChild(o);
            }
            selYear.value = String(cur);
            selMonth.value = String(view.mo);
        }

        function renderDays() {
            daysGrid.innerHTML = '';
            var first = new Date(view.y, view.mo - 1, 1);
            // getDay(): 0=CN..6=T7 -> chuyển sang T2=0..CN=6
            var lead = (first.getDay() + 6) % 7;
            var dim = daysInMonth(view.y, view.mo);
            var prevDim = daysInMonth(view.mo === 1 ? view.y - 1 : view.y, view.mo === 1 ? 12 : view.mo - 1);
            var today = nowState();
            var cells = [];
            // ngày tháng trước (mờ)
            for (var i = lead - 1; i >= 0; i--) cells.push({ d: prevDim - i, other: true });
            for (var d = 1; d <= dim; d++) cells.push({ d: d, other: false });
            while (cells.length % 7 !== 0 || cells.length < 42) {
                cells.push({ d: cells.length - (lead + dim) + 1, other: true });
                if (cells.length >= 42) break;
            }
            cells.forEach(function (c) {
                var b = el('button', 'gdtp-day', String(c.d));
                b.type = 'button';
                if (c.other) {
                    b.classList.add('is-other');
                } else {
                    if (c.d === today.d && view.mo === today.mo && view.y === today.y) b.classList.add('is-today');
                    if (c.d === st.d && view.mo === st.mo && view.y === st.y) b.classList.add('is-selected');
                    b.addEventListener('click', function () {
                        st.d = c.d; st.mo = view.mo; st.y = view.y;
                        renderDays();
                        syncToInput(true);
                    });
                }
                daysGrid.appendChild(b);
            });
        }

        function renderTime() {
            var map = { hour: st.h, minute: st.mi, second: st.s };
            timeCols.querySelectorAll('.gdtp-time-list').forEach(function (ul) {
                var unit = ul.getAttribute('data-unit');
                var sel = map[unit];
                ul.querySelectorAll('li').forEach(function (li) {
                    var on = (+li.getAttribute('data-val') === sel);
                    li.classList.toggle('is-selected', on);
                    if (on) li.scrollIntoView({ block: 'nearest' });
                });
            });
        }

        function segByKey(k) {
            for (var i = 0; i < SEG_DEFS.length; i++) if (SEG_DEFS[i].key === k) return SEG_DEFS[i];
            return null;
        }
        function manualHasFocus() { return segBox.contains(document.activeElement); }

        function fillSegsFromState() {
            segEls.d.value  = pad2(st.d);
            segEls.mo.value = pad2(st.mo);
            segEls.y.value  = String(st.y);
            segEls.h.value  = pad2(st.h);
            segEls.mi.value = pad2(st.mi);
            segEls.s.value  = pad2(st.s);
            segBox.classList.remove('is-invalid');
        }

        function renderManual() {
            // Đang gõ tay → không ghi đè để giữ con trỏ/giá trị dở.
            if (manualHasFocus()) return;
            fillSegsFromState();
        }

        // Đọc 6 ô → state hợp lệ & đầy đủ; null nếu thiếu hoặc sai biên.
        function segVals() {
            var d = segEls.d.value, mo = segEls.mo.value, y = segEls.y.value,
                h = segEls.h.value, mi = segEls.mi.value, s = segEls.s.value;
            if (d === '' || mo === '' || y === '' || h === '' || mi === '' || s === '') return null;
            if (y.length !== 4) return null; // chờ nhập đủ 4 chữ số năm rồi mới áp dụng
            var v = { y: +y, mo: +mo, d: +d, h: +h, mi: +mi, s: +s };
            if (v.mo < 1 || v.mo > 12) return null;
            if (v.y < 1) return null;
            if (v.d < 1 || v.d > daysInMonth(v.y, v.mo)) return null;
            if (v.h > 23 || v.mi > 59 || v.s > 59) return null;
            return v;
        }

        // Áp dụng nếu 6 ô đầy đủ & hợp lệ → cập nhật lịch/đồng hồ ngay (live).
        // silent=true: không bật cờ đỏ khi chưa đủ (dùng lúc đang gõ).
        function applyManual(silent) {
            var v = segVals();
            if (!v) { if (!silent) segBox.classList.add('is-invalid'); return false; }
            st = v;
            view.y = st.y; view.mo = st.mo;
            renderYearOptions();
            renderDays();
            renderTime();
            segBox.classList.remove('is-invalid');
            syncToInput(true);
            return true;
        }

        function focusNextSeg(key) {
            var idx = segOrder.indexOf(key);
            if (idx >= 0 && idx < segOrder.length - 1) {
                var nx = segEls[segOrder[idx + 1]];
                nx.focus(); nx.select();
            }
        }
        function focusPrevSeg(key) {
            var idx = segOrder.indexOf(key);
            if (idx > 0) {
                var pv = segEls[segOrder[idx - 1]];
                pv.focus();
                var n = pv.value.length;
                try { pv.setSelectionRange(n, n); } catch (_) {}
                return pv;
            }
            return null;
        }

        function renderAll() {
            renderYearOptions();
            renderDays();
            renderTime();
            renderManual();
        }

        /* ---------- Events ---------- */
        timeCols.addEventListener('click', function (e) {
            var li = e.target.closest('li');
            if (!li) return;
            var ul = li.closest('.gdtp-time-list');
            var unit = ul.getAttribute('data-unit');
            var val = +li.getAttribute('data-val');
            if (unit === 'hour') st.h = val;
            else if (unit === 'minute') st.mi = val;
            else st.s = val;
            renderTime();
            syncToInput(true);
        });

        btnPrev.addEventListener('click', function () {
            view.mo--; if (view.mo < 1) { view.mo = 12; view.y--; }
            renderAll();
        });
        btnNext.addEventListener('click', function () {
            view.mo++; if (view.mo > 12) { view.mo = 1; view.y++; }
            renderAll();
        });
        selMonth.addEventListener('change', function () { view.mo = +selMonth.value; renderAll(); });
        selYear.addEventListener('change', function () { view.y = +selYear.value; renderAll(); });

        btnNow.addEventListener('click', function () {
            st = nowState();
            view.y = st.y; view.mo = st.mo;
            renderAll();
            syncToInput(true);
        });
        btnDone.addEventListener('click', function () {
            applyManual(true); // chốt giá trị đang gõ (nếu đủ & hợp lệ) trước khi đóng
            close();
        });

        // --- Nhập tay theo 6 ô, tự nhảy ô ---
        var SEG_JUMP = { '/': 1, ':': 1, ' ': 1, '-': 1, '.': 1 };

        segBox.addEventListener('input', function (e) {
            var inp = e.target.closest('.gdtp-seg');
            if (!inp) return;
            var key = inp.getAttribute('data-key');
            var def = segByKey(key);
            // chỉ giữ chữ số, giới hạn độ dài
            var v = inp.value.replace(/\D/g, '').slice(0, def.len);
            inp.value = v;
            segBox.classList.remove('is-invalid');
            // Tự nhảy ô: đã đủ ký tự, hoặc không thể gõ thêm chữ số mà vẫn hợp lệ.
            var full = v.length >= def.len;
            var cantExtend = key !== 'y' && v.length > 0 && (parseInt(v, 10) * 10 > def.max);
            if ((full || cantExtend) && v.length < def.len) inp.value = v.padStart(def.len, '0');
            applyManual(true); // cập nhật lịch/đồng hồ ngay nếu đủ
            if (full || cantExtend) focusNextSeg(key);
        });

        segBox.addEventListener('keydown', function (e) {
            var inp = e.target.closest('.gdtp-seg');
            if (!inp) return;
            var key = inp.getAttribute('data-key');
            if (e.key === 'Enter') { e.preventDefault(); applyManual(); return; }
            if (e.key === 'Escape') { e.stopPropagation(); fillSegsFromState(); inp.blur(); return; }
            if (SEG_JUMP[e.key]) { e.preventDefault(); focusNextSeg(key); return; }
            if (e.key === 'ArrowLeft' && inp.selectionStart === 0 && inp.selectionEnd === 0) {
                e.preventDefault(); focusPrevSeg(key); return;
            }
            if (e.key === 'ArrowRight' && inp.selectionStart === inp.value.length) {
                e.preventDefault(); focusNextSeg(key); return;
            }
            if (e.key === 'Backspace' && inp.value === '') {
                var pv = focusPrevSeg(key);
                if (pv) { e.preventDefault(); pv.value = pv.value.slice(0, -1); }
                return;
            }
            // Chặn ký tự không phải số (vẫn cho phép phím điều khiển/tổ hợp Ctrl).
            if (e.key.length === 1 && !e.ctrlKey && !e.metaKey && !/[0-9]/.test(e.key)) {
                e.preventDefault();
            }
        });

        // Rời toàn bộ vùng nhập tay → chốt (hợp lệ) hoặc khôi phục theo state.
        segBox.addEventListener('focusout', function (e) {
            if (segBox.contains(e.relatedTarget)) return; // vẫn còn trong vùng nhập
            if (!applyManual(true)) fillSegsFromState();
        });
        // Bấm vào ô → chọn sẵn nội dung cho dễ gõ đè.
        segBox.addEventListener('focusin', function (e) {
            var inp = e.target.closest('.gdtp-seg');
            if (inp) { try { inp.select(); } catch (_) {} }
        });

        /* ---------- Open / close ---------- */
        function position() {
            var r = trigger.getBoundingClientRect();
            var top = window.scrollY + r.bottom + 6;
            var left = window.scrollX + r.left;
            var pw = pop.offsetWidth || 460;
            var maxLeft = window.scrollX + document.documentElement.clientWidth - pw - 8;
            if (left > maxLeft) left = Math.max(window.scrollX + 8, maxLeft);
            pop.style.top = top + 'px';
            pop.style.left = left + 'px';
        }
        function open() {
            // luôn đọc lại value mới nhất trước khi mở
            var fresh = parseValue(input.value);
            if (fresh) { st = fresh; }
            view.y = st.y; view.mo = st.mo;
            pop.classList.add('is-open');
            trigger.classList.add('is-open');
            renderAll();
            position();
        }
        function close() {
            pop.classList.remove('is-open');
            trigger.classList.remove('is-open');
        }
        function isOpen() { return pop.classList.contains('is-open'); }

        trigger.addEventListener('click', function () { isOpen() ? close() : open(); });
        document.addEventListener('click', function (e) {
            if (!isOpen()) return;
            if (e.target.closest('.gdtp-popover') === pop) return;
            if (e.target === trigger || trigger.contains(e.target)) return;
            close();
        });
        document.addEventListener('keydown', function (e) { if (e.key === 'Escape' && isOpen()) close(); });
        window.addEventListener('resize', function () { if (isOpen()) position(); });
        window.addEventListener('scroll', function () { if (isOpen()) position(); }, true);

        /* ---------- API cho code ngoài ---------- */
        // Đồng bộ UI theo input.value hiện tại (không ghi ngược value).
        var syncingDisplay = false;
        input._dtpRefresh = function () {
            var fresh = parseValue(input.value);
            if (fresh) {
                st = fresh;
                txt.textContent = formatDisplay(st);
                txt.classList.remove('is-empty');
                if (isOpen()) { view.y = st.y; view.mo = st.mo; renderAll(); }
            } else {
                txt.textContent = 'Chọn ngày giờ';
                txt.classList.add('is-empty');
            }
        };

        // Tự bắt mọi lần code khác gán input.value = '...' (không bắn event) để
        // UI cập nhật mà KHÔNG cần sửa JS từng view. Ghi đè setter trên element.
        try {
            var nativeDesc = Object.getOwnPropertyDescriptor(HTMLInputElement.prototype, 'value');
            if (nativeDesc && nativeDesc.set && nativeDesc.get) {
                Object.defineProperty(input, 'value', {
                    configurable: true,
                    enumerable: nativeDesc.enumerable,
                    get: function () { return nativeDesc.get.call(this); },
                    set: function (v) {
                        nativeDesc.set.call(this, v);
                        if (!syncingDisplay) {
                            syncingDisplay = true;
                            try { input._dtpRefresh(); } finally { syncingDisplay = false; }
                        }
                    }
                });
            }
        } catch (_) { /* trình duyệt cũ: vẫn dùng _dtpRefresh thủ công */ }

        // Khởi tạo hiển thị ban đầu.
        if (parseValue(input.value)) {
            txt.textContent = formatDisplay(st);
        } else {
            txt.textContent = 'Chọn ngày giờ';
            txt.classList.add('is-empty');
        }

        // Làm nổi bật khi vừa vào page (phóng to một xíu rồi trả lại kích thước cũ), tự tắt sau 4s.
        // Bắn sự kiện 'gdtp:highlight-end' khi hiệu ứng dừng để các thành phần khác (vd nút
        // xổ/thu bút toán kế toán) có thể nối tiếp hiệu ứng nổi bật của riêng chúng.
        if (input.classList.contains('js-green-datetime-highlight')) {
            trigger.classList.add('gdtp-highlight');
            setTimeout(function () {
                trigger.classList.remove('gdtp-highlight');
                document.dispatchEvent(new CustomEvent('gdtp:highlight-end'));
            }, 4000);
        }
    }

    function init(root) {
        (root || document).querySelectorAll('input.js-green-datetime[type="datetime-local"]').forEach(enhance);
    }

    window.GreenDateTimePicker = { init: init, enhance: enhance };

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', function () { init(); });
    } else {
        init();
    }
})();
