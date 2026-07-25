(function () {
    'use strict';

    var ACT = '?mod=production_staff&controllers=production_staff&action=';

    function postForm(action, payload) {
        var body = new URLSearchParams();
        Object.keys(payload || {}).forEach(function (k) { body.append(k, payload[k]); });
        return fetch(ACT + action, {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body: body.toString()
        }).then(function (r) { return r.json(); });
    }

    function escapeHtml(s) {
        return String(s == null ? '' : s)
            .replace(/&/g, '&amp;').replace(/</g, '&lt;')
            .replace(/>/g, '&gt;').replace(/"/g, '&quot;');
    }

    /* ============================================================
       Share KHSX — modal tiêu đề mượn nguyên .pf-share-* của
       production_formula (rope + logo + tên/địa chỉ công ty sửa tại chỗ,
       dùng chung app_settings 'pf_share.*'), thân phiếu là danh sách card
       dọc (1 card / sản phẩm). Nút "Share" chụp ảnh modal và copy vào
       clipboard để Ctrl+V gửi qua Zalo / Messenger.
       ============================================================ */

    var backdrop  = document.getElementById('khsx-backdrop');
    var sheet     = document.getElementById('khsx-sheet');
    var shareList = document.getElementById('khsx-share-list');
    var tasksWrap = document.getElementById('khsx-share-tasks');
    var shareBtn  = document.getElementById('khsx-share-btn');
    var closeBtn  = document.getElementById('khsx-close-btn');
    var trigger   = document.getElementById('btn-share-khsx');

    function toast(msg) {
        var t = document.createElement('div');
        t.className = 'khsx-toast';
        t.textContent = msg;
        document.body.appendChild(t);
        requestAnimationFrame(function () { t.classList.add('show'); });
        setTimeout(function () {
            t.classList.remove('show');
            setTimeout(function () { t.remove(); }, 300);
        }, 2200);
    }

    /** Đọc dữ liệu trực tiếp từ các .product-item thật trên trang (đã phản ánh
     * mọi chỉnh sửa bao bì/QC vừa lưu, không cần load lại trang). */
    function collectPlanCards() {
        var cards = [];
        document.querySelectorAll('.content > .list-product > .product-item').forEach(function (li) {
            var nameEl = li.querySelector('.name-product strong');
            var qtyEl  = li.querySelector('.num input');
            var qcCbs  = li.querySelectorAll('.list-QC .wp-ck input[type="checkbox"]');
            var qc = {
                sample:          !!(qcCbs[0] && qcCbs[0].checked),
                test:            !!(qcCbs[1] && qcCbs[1].checked),
                check_inventory: !!(qcCbs[2] && qcCbs[2].checked)
            };
            var stockEl    = li.querySelector('.list-info .info-item span');
            var innerName  = li.querySelector('.pkg-picker[data-field="inner_packaging_id"] .pkg-search');
            var outerName  = li.querySelector('.pkg-picker[data-field="outer_packaging_id"] .pkg-search');
            var innerSpec  = li.querySelector('.info-field[data-field="inner_packaging_spec"]');
            var outerSpec  = li.querySelector('.info-field[data-field="outer_packaging_spec"]');
            var noteInput  = li.querySelector('.note input');
            cards.push({
                productId: li.getAttribute('data-product-id') || '',
                name:      (nameEl ? nameEl.textContent : '').trim(),
                qty:       (qtyEl ? qtyEl.value : '').trim(),
                qc:        qc,
                stock:     stockEl ? stockEl.textContent.trim() : '—',
                innerName: innerName ? innerName.value.trim() : '',
                outerName: outerName ? outerName.value.trim() : '',
                innerSpec: innerSpec ? innerSpec.value.trim() : '',
                outerSpec: outerSpec ? outerSpec.value.trim() : '',
                note:      noteInput ? noteInput.value.trim() : ''
            });
        });
        return cards;
    }

    /** Tách quy cách bao bì ngoài kiểu "30 gói/bao" -> {packPerOuter:30, unitName:'gói', containerName:'bao'}.
     * Cùng quy ước với pl_parse_outer_spec() bên production_label (PHP). */
    function parseOuterSpec(spec) {
        spec = String(spec || '').trim();
        if (!spec || spec.indexOf('/') === -1) return null;
        var parts = spec.split('/');
        var left  = parts[0].trim();
        var right = parts.slice(1).join('/').trim();
        var m = left.match(/(\d+(?:[.,]\d+)?)\s*(.*)/);
        if (!m) return null;
        var packPerOuter = parseFloat(m[1].replace(',', '.'));
        if (!packPerOuter) return null;
        return { packPerOuter: packPerOuter, unitName: (m[2] || '').trim(), containerName: right };
    }

    /** Quy đổi tồn kho thô (đơn vị lẻ, vd "gói") sang bao/thùng theo QCBBN, vd
     * 1738 gói + "30 gói/bao" -> "57 Bao 28 gói". Không quy đổi được (thiếu QCBBN,
     * không phải số...) thì trả về nguyên giá trị thô. */
    function formatStockByPackaging(stockRaw, outerSpec) {
        var n = parseFloat(String(stockRaw).replace(',', '.'));
        var parsed = parseOuterSpec(outerSpec);
        if (!parsed || isNaN(n)) return stockRaw;
        var containers = Math.floor(n / parsed.packPerOuter);
        var remainder  = Math.round((n - containers * parsed.packPerOuter) * 100) / 100;
        var containerLabel = parsed.containerName
            ? parsed.containerName.charAt(0).toUpperCase() + parsed.containerName.slice(1)
            : '';
        var parts = [];
        if (containers > 0 && containerLabel) parts.push(containers + ' ' + containerLabel);
        if (remainder > 0 || !parts.length) parts.push(remainder + (parsed.unitName ? ' ' + parsed.unitName : ''));
        return parts.join(' ');
    }

    function renderShareCards(cards) {
        if (!cards.length) {
            return '<p class="khsx-share-empty">Chưa có kế hoạch sản xuất để chia sẻ.</p>';
        }
        return cards.map(function (c) {
            var head = escapeHtml(c.name) + ': ' + escapeHtml(c.qty);
            var stockDisplay = formatStockByPackaging(c.stock, c.outerSpec);
            // Checkbox tròn, giống hệt .list-QC ở view chính (checked = nổi bật xanh, unchecked = chìm).
            // Dùng <span class="khsx-fake-check"> thay cho <input type="checkbox"> thật vì
            // html2canvas không chụp đúng appearance:none của checkbox lúc share ảnh.
            var qcHtml = '<div class="list-QC khsx-share-card-qc">' +
                '<div class="wp-ck"><span class="khsx-fake-check' + (c.qc.sample ? ' checked' : '') + '"></span><label>Lấy mẫu</label></div>' +
                '<div class="wp-ck"><span class="khsx-fake-check' + (c.qc.test ? ' checked' : '') + '"></span><label>Test</label></div>' +
                '<div class="wp-ck"><span class="khsx-fake-check' + (c.qc.check_inventory ? ' checked' : '') + '"></span><label>Kiểm tồn</label></div>' +
                '</div>';
            var lines = [
                'Tồn kho đầu ngày: <b class="khsx-stock-val">' + escapeHtml(stockDisplay) + '</b>',
                'BBT: ' + escapeHtml(c.innerName || '—'),
                'BBN: ' + escapeHtml(c.outerName || '—'),
                'QCBBT: ' + escapeHtml(c.innerSpec || '—'),
                'QCBBN: ' + escapeHtml(c.outerSpec || '—')
            ];
            var noteLine = '<li class="khsx-note-line" data-product-id="' + escapeHtml(c.productId) + '">' +
                '<span class="khsx-note-label">Lưu ý:</span> ' +
                '<span class="khsx-note-text" contenteditable="true">' + escapeHtml(c.note) + '</span> ' +
                '<button type="button" class="khsx-note-del" title="Xóa lưu ý">×</button>' +
                '</li>';
            return '<div class="khsx-share-card">' +
                '<div class="khsx-share-card-head">' + head + '</div>' +
                qcHtml +
                '<ul class="khsx-share-card-list">' + lines.map(function (l) { return '<li>' + l + '</li>'; }).join('') + noteLine + '</ul>' +
                '</div>';
        }).join('');
    }

    function open() {
        if (!backdrop) return;
        shareList.innerHTML = renderShareCards(collectPlanCards());

        tasksWrap.innerHTML = '';
        var srcTasks = document.querySelector('.content > .wp-other-work');
        if (srcTasks) {
            var tasksClone = srcTasks.cloneNode(true);
            // Bỏ nút/khung "Thêm việc khác" ra khỏi ảnh share, chỉ giữ danh sách.
            var addBtnClone = tasksClone.querySelector('#btn-add-other-work');
            if (addBtnClone) addBtnClone.remove();
            var addFormClone = tasksClone.querySelector('#other-work-form');
            if (addFormClone) addFormClone.remove();
            // Gỡ mọi id trong bản clone (nhất là #list-other-work) để không tạo id
            // trùng trên trang -> tránh querySelectorAll toàn trang khớp nhầm clone.
            if (tasksClone.id) tasksClone.removeAttribute('id');
            tasksClone.querySelectorAll('[id]').forEach(function (n) { n.removeAttribute('id'); });
            tasksWrap.appendChild(tasksClone);
        }

        backdrop.classList.add('show');
    }
    function close() { if (backdrop) backdrop.classList.remove('show'); }

    if (trigger) trigger.addEventListener('click', open);
    if (closeBtn) closeBtn.addEventListener('click', close);
    if (backdrop) {
        backdrop.addEventListener('click', function (e) { if (e.target === backdrop) close(); });
    }
    document.addEventListener('keydown', function (e) {
        if (e.key === 'Escape' && backdrop && backdrop.classList.contains('show')) close();
    });

    /** Sửa/xóa "Lưu ý" ngay trong modal share — ghi thẳng vào pre_production_notes
     * (cùng bảng với .input-note ở production_plan và tab "KHSX" của Điểm nhắc),
     * nên sửa ở đây tự động đồng bộ mọi nơi khác. */
    function wireShareNoteEdit() {
        if (!shareList) return;
        shareList.addEventListener('focus', function (e) {
            if (e.target.classList && e.target.classList.contains('khsx-note-text')) {
                e.target.dataset.orig = e.target.textContent;
            }
        }, true);
        shareList.addEventListener('keydown', function (e) {
            if (!e.target.classList || !e.target.classList.contains('khsx-note-text')) return;
            if (e.key === 'Enter') { e.preventDefault(); e.target.blur(); }
            else if (e.key === 'Escape') { e.target.textContent = e.target.dataset.orig || ''; e.target.blur(); }
        });
        function syncPageNote(pid, note) {
            var pageInput = document.querySelector('.pf-note-input[data-product-id="' + pid + '"]');
            if (!pageInput) return;
            pageInput.value = note;
            var noteWrap = pageInput.closest('.note');
            if (noteWrap) noteWrap.style.display = note ? '' : 'none';
        }
        shareList.addEventListener('blur', function (e) {
            if (!e.target.classList || !e.target.classList.contains('khsx-note-text')) return;
            var li = e.target.closest('.khsx-note-line');
            var pid = li && li.getAttribute('data-product-id');
            if (!pid) return;
            var note = e.target.textContent.trim();
            postForm('save_note', { product_id: pid, note: note }).then(function (res) {
                if (res && res.success) syncPageNote(pid, note);
            });
        }, true);
        shareList.addEventListener('click', function (e) {
            var btn = e.target.closest('.khsx-note-del');
            if (!btn) return;
            var li = btn.closest('.khsx-note-line');
            var pid = li && li.getAttribute('data-product-id');
            if (!pid) return;
            if (!confirm('Xóa lời nhắc này?')) return;
            postForm('delete_note', { product_id: pid }).then(function (res) {
                if (!res || !res.success) return;
                li.querySelector('.khsx-note-text').textContent = '';
                syncPageNote(pid, '');
            });
        });
    }
    wireShareNoteEdit();

    /* ============================================================
       In BÁO CÁO SẢN XUẤT (BC) — biểu mẫu A4, nhân viên ghi tay sau khi in.
       ============================================================ */
    (function () {
        var btnPrintBc  = document.getElementById('btn-print-bc');
        var bcModal     = document.getElementById('bc-print-modal');
        var bcOverlay   = document.getElementById('bc-print-modal-overlay');
        var bcClose     = document.getElementById('bc-print-close');
        var bcDo        = document.getElementById('bc-print-do');
        var bcList      = bcModal && bcModal.querySelector('.bc-product-list');
        var bcOwList    = bcModal && bcModal.querySelector('.bc-ow-list');

        function bcRowHtml(name) {
            return '<li class="bc-product-row">' +
                '<div class="bc-product-top">' +
                    '<span class="bc-product-name">' + escapeHtml(name) + ':</span>' +
                    '<span class="bc-qty-box"></span>' +
                '</div>' +
                '<div class="bc-date-block">' +
                    '<span class="bc-date-block-label">Date:</span>' +
                    '<span class="bc-radio-opt"><span class="bc-bullet"></span>Hôm nay</span>' +
                    '<span class="bc-radio-opt"><span class="bc-bullet"></span>Hôm qua</span>' +
                    '<span class="bc-radio-opt"><span class="bc-bullet"></span>Khác:<span class="bc-fill-line"></span></span>' +
                '</div>' +
                '</li>';
        }

        // Nạp lại danh sách SP + việc khác từ DOM sống ngay trước khi mở, vì bản
        // in được PHP render tĩnh lúc tải trang — SP/việc thêm sau đó qua AJAX
        // (addPlan/ajax_add_task) không tự có mặt nếu không rebuild ở đây.
        function refreshBcSheet() {
            if (bcList) {
                var names = Array.prototype.map.call(
                    document.querySelectorAll('.content > .list-product > .product-item'),
                    function (li) {
                        var nameEl = li.querySelector('.name-product strong');
                        return nameEl ? nameEl.textContent.trim() : '';
                    }
                ).filter(function (n) { return n !== ''; });
                bcList.innerHTML = names.length
                    ? names.map(bcRowHtml).join('')
                    : '<li class="bc-product-row bc-empty">Chưa có kế hoạch sản xuất được gửi xuống.</li>';
            }
            if (bcOwList) {
                // Chỉ đọc danh sách việc khác SỐNG trong .content — KHÔNG lấy nhầm bản
                // clone nằm trong modal Share (clone giữ nguyên id="list-other-work" nên
                // querySelectorAll toàn trang khớp cả 2 -> in bị nhân đôi việc khác).
                var owLive = document.querySelector('.content > .wp-other-work #list-other-work');
                var taskTexts = owLive ? Array.prototype.map.call(
                    owLive.querySelectorAll('.other-work-item .content-other-work input'),
                    function (inp) { return inp.value.trim(); }
                ).filter(function (t) { return t !== ''; }) : [];
                bcOwList.innerHTML = taskTexts.length
                    ? taskTexts.map(function (t) { return '<li>' + escapeHtml(t) + '</li>'; }).join('')
                    : '<li></li><li></li><li></li>';
            }
        }

        function openBc() { refreshBcSheet(); if (bcModal) bcModal.style.display = 'flex'; }
        function closeBc() { if (bcModal) bcModal.style.display = 'none'; }

        if (btnPrintBc) btnPrintBc.addEventListener('click', openBc);
        if (bcOverlay)  bcOverlay.addEventListener('click', closeBc);
        if (bcClose)    bcClose.addEventListener('click', closeBc);
        if (bcDo)       bcDo.addEventListener('click', function () { window.print(); });
        document.addEventListener('keydown', function (e) {
            if (e.key === 'Escape' && bcModal && bcModal.style.display === 'flex') closeBc();
        });
    })();

    function fallbackDownload(canvas) {
        var a = document.createElement('a');
        a.href = canvas.toDataURL('image/png');
        a.download = 'KHSX_' + new Date().toISOString().slice(0, 10) + '.png';
        a.click();
        toast('Trình duyệt không hỗ trợ copy ảnh — đã tải file PNG về máy.');
    }

    function shareImage() {
        if (typeof html2canvas === 'undefined') {
            toast('Không tải được thư viện chụp ảnh.');
            return;
        }
        shareBtn.disabled = true;
        var oldHtml = shareBtn.innerHTML;
        shareBtn.innerHTML = '<i class="fa-solid fa-spinner fa-spin"></i> Đang xử lý...';
        sheet.classList.add('is-capturing'); // html2canvas không vẽ được repeating-linear-gradient của .pf-share-rope

        html2canvas(sheet, { scale: 2, backgroundColor: '#ffffff', useCORS: true })
            .then(function (canvas) {
                sheet.classList.remove('is-capturing');
                canvas.toBlob(function (blob) {
                    if (!blob) { fallbackDownload(canvas); finish(); return; }
                    if (navigator.clipboard && window.ClipboardItem) {
                        navigator.clipboard.write([new ClipboardItem({ 'image/png': blob })])
                            .then(function () {
                                toast('Đã copy ảnh KHSX. Mở Zalo/Messenger và nhấn Ctrl+V để gửi.');
                                finish();
                            })
                            .catch(function () { fallbackDownload(canvas); finish(); });
                    } else {
                        fallbackDownload(canvas);
                        finish();
                    }
                }, 'image/png');
            })
            .catch(function () {
                sheet.classList.remove('is-capturing');
                toast('Lỗi khi chụp ảnh.');
                finish();
            });

        function finish() {
            shareBtn.disabled = false;
            shareBtn.innerHTML = oldHtml;
        }
    }
    if (shareBtn) shareBtn.addEventListener('click', shareImage);

    /* ---------- Sửa tên/địa chỉ công ty tại chỗ (bút chì), giống production_formula ---------- */
    function startShareEdit(el) {
        var key = el.getAttribute('data-share-key');
        var multiline = el.getAttribute('data-multiline') === '1';
        var textEl = el.querySelector('.pf-share-etext');
        var btn = el.querySelector('.pf-share-edit');
        if (!textEl || el.classList.contains('is-editing')) return;
        var cur = textEl.textContent;
        var inp = document.createElement(multiline ? 'textarea' : 'input');
        if (!multiline) inp.type = 'text';
        inp.className = 'pf-share-edit-input' + (multiline ? ' pf-share-edit-area' : '');
        inp.value = cur;
        if (multiline) inp.rows = Math.max(2, cur.split('\n').length);
        el.classList.add('is-editing');
        textEl.style.display = 'none';
        if (btn) btn.style.display = 'none';
        el.insertBefore(inp, btn || null);
        inp.focus(); if (inp.select) inp.select();
        var done = false;
        function finish(commit) {
            if (done) return; done = true;
            var val = multiline ? inp.value.replace(/\s+$/, '') : inp.value.trim();
            if (commit && val !== '' && val !== cur) {
                textEl.textContent = val;
                postForm('save_share_setting', { key: key, value: val });
            }
            inp.remove();
            textEl.style.display = '';
            if (btn) btn.style.display = '';
            el.classList.remove('is-editing');
        }
        inp.addEventListener('keydown', function (ev) {
            if (ev.key === 'Enter' && multiline && ev.altKey) {
                ev.preventDefault();
                var s = inp.selectionStart, e = inp.selectionEnd;
                inp.value = inp.value.slice(0, s) + '\n' + inp.value.slice(e);
                inp.selectionStart = inp.selectionEnd = s + 1;
            } else if (ev.key === 'Enter' && !ev.altKey) {
                ev.preventDefault(); inp.blur();
            } else if (ev.key === 'Escape') {
                done = true; inp.remove(); textEl.style.display = ''; if (btn) btn.style.display = ''; el.classList.remove('is-editing');
            }
        });
        inp.addEventListener('blur', function () { finish(true); });
    }
    Array.prototype.forEach.call(document.querySelectorAll('.pf-share-editable'), function (el) {
        var btn = el.querySelector('.pf-share-edit');
        if (btn) btn.addEventListener('click', function (e) { e.preventDefault(); e.stopPropagation(); startShareEdit(el); });
    });

    /* ============================================================
       Inline edit: Bao bì trong/ngoài + QC BBT/BBN -> product_info_basic
       (chỉ áp dụng cho .product-item thật trong .content, không phải bản
       clone trong modal Share)
       ============================================================ */

    function flashOk(el) {
        if (!el) return;
        el.classList.add('saved-ok');
        setTimeout(function () { el.classList.remove('saved-ok'); }, 700);
    }

    function saveBasic(productId, field, value, el) {
        postForm('ajax_update_product_basic', { product_id: productId, field: field, value: value })
            .then(function (res) {
                if (res && res.success) { flashOk(el); }
                else { alert((res && res.message) || 'Lưu thất bại.'); }
            })
            .catch(function () { alert('Lỗi kết nối khi lưu.'); });
    }

    // Gõ tay trực tiếp vào ô bao bì trong/ngoài (không chọn từ dropdown) -> đổi
    // "tên thường gọi" của NVL đang liên kết (không đổi liên kết NVL).
    function saveMaterialCommonName(materialId, value, el) {
        postForm('save_material_common_name', { material_id: materialId, value: value })
            .then(function (res) {
                if (res && res.success) { flashOk(el); }
                else { alert((res && res.message) || 'Lưu thất bại.'); }
            })
            .catch(function () { alert('Lỗi kết nối khi lưu.'); });
    }

    function hideResults(el) { if (el) { el.style.display = 'none'; el.innerHTML = ''; } }

    function renderResults(el, items, onPick) {
        el.innerHTML = '';
        if (!items.length) {
            el.innerHTML = '<div class="no-result">Không tìm thấy nguyên liệu</div>';
            el.style.display = 'block';
            return;
        }
        items.forEach(function (item) {
            var d = document.createElement('div');
            d.className = 'result-item';
            d.textContent = item.material_name + ' — ' + (item.supplier_name || 'Không có NCC');
            d.addEventListener('click', function () { onPick(item); });
            el.appendChild(d);
        });
        el.style.display = 'block';
    }

    var content = document.querySelector('.content');
    if (content) {
        // 1. Quy cách (info-field): lưu khi thay đổi
        content.addEventListener('change', function (e) {
            var f = e.target.closest('.info-field');
            if (!f) return;
            saveBasic(f.getAttribute('data-product-id'), f.getAttribute('data-field'), f.value, f);
        });

        // 2. Bao bì trong/ngoài (pkg-picker): autocomplete nguyên liệu
        content.querySelectorAll('.pkg-picker').forEach(function (wrap) {
            var field   = wrap.getAttribute('data-field');
            var pid     = wrap.getAttribute('data-product-id');
            var input   = wrap.querySelector('.pkg-search');
            var results = wrap.querySelector('.search-results');
            var timer   = null;
            var activeIdx = -1;

            input.dataset.orig = (input.value || '').trim();

            function highlight(items) {
                items.forEach(function (it) { it.classList.remove('active'); });
                if (activeIdx >= 0 && activeIdx < items.length) {
                    items[activeIdx].classList.add('active');
                    items[activeIdx].scrollIntoView({ block: 'nearest' });
                }
            }

            input.addEventListener('input', function () {
                var kw = (input.value || '').trim();
                clearTimeout(timer);
                wrap._justPicked = false;
                activeIdx = -1;
                if (kw.length < 1) { hideResults(results); return; }
                timer = setTimeout(function () {
                    postForm('search_material', { keyword: kw }).then(function (res) {
                        activeIdx = -1;
                        renderResults(results, res.data || [], function (item) {
                            wrap._justPicked = true;
                            input.value = item.material_name;
                            input.dataset.orig = item.material_name;
                            wrap.setAttribute('data-id', item.id);
                            hideResults(results);
                            saveBasic(pid, field, item.id, input);
                        });
                    });
                }, 300);
            });

            // Điều khiển bằng bàn phím: ↑/↓ di chuyển, Enter/Tab chọn, Escape đóng.
            input.addEventListener('keydown', function (e) {
                if (results.style.display !== 'block') return;
                var items = results.querySelectorAll('.result-item');
                if (!items.length) return;
                if (e.key === 'ArrowDown') {
                    e.preventDefault();
                    activeIdx = (activeIdx + 1) % items.length;
                    highlight(items);
                } else if (e.key === 'ArrowUp') {
                    e.preventDefault();
                    activeIdx = (activeIdx - 1 + items.length) % items.length;
                    highlight(items);
                } else if (e.key === 'Enter' || e.key === 'Tab') {
                    // Chỉ "cướp" phím khi đang trỏ vào 1 gợi ý; nếu chưa chọn dòng nào
                    // thì để Tab/Enter chạy mặc định (blur -> lưu tên thường gọi).
                    if (activeIdx >= 0 && activeIdx < items.length) {
                        e.preventDefault();
                        items[activeIdx].click(); // -> onPick: gán value + lưu + ẩn dropdown
                    }
                } else if (e.key === 'Escape') {
                    hideResults(results);
                }
            });

            // Rời khỏi ô: nếu vừa chọn từ dropdown thì bỏ qua (đã lưu ở onPick).
            // Xóa trắng -> gỡ liên kết NVL. Gõ tay đổi chữ (không chọn dropdown) ->
            // đổi "tên thường gọi" của NVL đang liên kết, không đổi liên kết.
            input.addEventListener('blur', function () {
                setTimeout(function () {
                    if (wrap._justPicked) { wrap._justPicked = false; return; }
                    var val = (input.value || '').trim();
                    if (val === '') {
                        wrap.setAttribute('data-id', 0);
                        input.dataset.orig = '';
                        saveBasic(pid, field, 0, input);
                        return;
                    }
                    var curId = parseInt(wrap.getAttribute('data-id'), 10) || 0;
                    if (curId > 0 && val !== input.dataset.orig) {
                        input.dataset.orig = val;
                        saveMaterialCommonName(curId, val, input);
                    }
                }, 200);
            });
        });

        // Ẩn dropdown khi click ra ngoài picker
        document.addEventListener('mousedown', function (e) {
            content.querySelectorAll('.pkg-picker').forEach(function (wrap) {
                if (!wrap.contains(e.target)) hideResults(wrap.querySelector('.search-results'));
            });
        });

        // 3. List-QC: nhân viên tự tích "Lấy mẫu / Test / Kiểm tồn" -> lưu vào production_plans
        content.addEventListener('change', function (e) {
            var cb = e.target.closest('.list-QC input[type="checkbox"]');
            if (!cb) return;
            var planId = cb.getAttribute('data-plan-id');
            var field  = cb.getAttribute('data-field');
            if (!planId || !field) return;
            var wasChecked = cb.checked;
            postForm('ajax_update_plan_qc', { plan_id: planId, field: field, value: wasChecked ? 1 : 0 })
                .then(function (res) {
                    if (!res || !res.success) {
                        cb.checked = !wasChecked;
                        alert((res && res.message) || 'Lưu thất bại.');
                    }
                })
                .catch(function () {
                    cb.checked = !wasChecked;
                    alert('Lỗi kết nối khi lưu.');
                });
        });

        // 4. Tên sản phẩm (.pf-name-text): hover hiện bút chì -> click sửa tại chỗ,
        // click ra ngoài (blur) -> lưu common_product_name.
        content.addEventListener('click', function (e) {
            var btn = e.target.closest('.pf-name-edit-btn');
            if (!btn) return;
            var wrap = btn.closest('.name-product');
            var textEl = wrap && wrap.querySelector('.pf-name-text');
            if (!textEl || textEl.getAttribute('contenteditable') === 'true') return;
            textEl.dataset.orig = textEl.textContent.trim();
            textEl.setAttribute('contenteditable', 'true');
            textEl.focus();
            var range = document.createRange();
            range.selectNodeContents(textEl);
            var sel = window.getSelection();
            sel.removeAllRanges();
            sel.addRange(range);
        });
        content.addEventListener('keydown', function (e) {
            if (!e.target.classList || !e.target.classList.contains('pf-name-text')) return;
            if (e.key === 'Enter') { e.preventDefault(); e.target.blur(); }
            else if (e.key === 'Escape') {
                e.target.textContent = e.target.dataset.orig || '';
                e.target.setAttribute('contenteditable', 'false');
                e.target.blur();
            }
        });
        content.addEventListener('blur', function (e) {
            if (!e.target.classList || !e.target.classList.contains('pf-name-text')) return;
            var textEl = e.target;
            textEl.setAttribute('contenteditable', 'false');
            var val = (textEl.textContent || '').replace(/\s+/g, ' ').trim();
            if (val === '') { textEl.textContent = textEl.dataset.orig || ''; return; }
            textEl.textContent = val;
            if (val === textEl.dataset.orig) return;
            var pid = textEl.getAttribute('data-product-id');
            postForm('save_product_common_name', { product_id: pid, value: val }).then(function (res) {
                if (res && res.success) flashOk(textEl);
                else alert((res && res.message) || 'Lưu thất bại.');
            });
        }, true);

        // 5. Số lượng sản xuất (.pf-qty-input): cho sửa trực tiếp -> lưu vào production_plans
        content.addEventListener('change', function (e) {
            var inp = e.target.closest('.pf-qty-input');
            if (!inp) return;
            var planId = inp.getAttribute('data-plan-id');
            if (!planId) return;
            var qty = parseInt(String(inp.value).replace(/[^\d-]/g, ''), 10) || 0;
            inp.value = qty;
            postForm('ajax_update_plan_quantity', { plan_id: planId, quantity: qty })
                .then(function (res) {
                    if (res && res.success) flashOk(inp);
                    else alert((res && res.message) || 'Lưu thất bại.');
                });
        });
    }

    /* ============================================================
       "VIỆC KHÁC": nhân viên tự thêm / gỡ bớt việc, lưu thẳng vào additional_tasks
       (gỡ bớt: hover vào dòng .other-work-item hiện nút "x").
       ============================================================ */
    (function () {
        var wrap  = document.querySelector('.content > .wp-other-work');
        if (!wrap) return;
        var btnAdd = document.getElementById('btn-add-other-work');
        var form   = document.getElementById('other-work-form');
        var input  = document.getElementById('other-work-input');
        var list   = document.getElementById('list-other-work');
        if (!btnAdd || !form || !input || !list) return;

        function renumber() {
            list.querySelectorAll('.other-work-item .serial p').forEach(function (p, i) {
                p.textContent = i + 1;
            });
        }

        function openForm() {
            form.style.display = 'flex';
            input.value = '';
            input.focus();
        }
        function closeForm() {
            form.style.display = 'none';
        }

        btnAdd.addEventListener('click', function () {
            if (form.style.display === 'flex') closeForm();
            else openForm();
        });

        input.addEventListener('keydown', function (e) {
            if (e.key === 'Enter') {
                e.preventDefault();
                var desc = input.value.trim();
                if (desc === '') return;
                input.disabled = true;
                postForm('ajax_add_task', { description: desc })
                    .then(function (res) {
                        input.disabled = false;
                        if (!res || !res.success) {
                            alert((res && res.message) || 'Thêm thất bại.');
                            return;
                        }
                        var li = document.createElement('li');
                        li.className = 'other-work-item';
                        li.setAttribute('data-task-id', res.id);
                        li.innerHTML =
                            '<div class="serial"><p></p></div>' +
                            '<div class="content-other-work"><input type="text" value="' + escapeHtml(desc) + '" readonly></div>' +
                            '<button type="button" class="task-delete" title="Gỡ việc này">&times;</button>';
                        list.appendChild(li);
                        renumber();
                        input.value = '';
                        input.focus();
                    })
                    .catch(function () {
                        input.disabled = false;
                        alert('Lỗi kết nối khi lưu.');
                    });
            } else if (e.key === 'Escape') {
                closeForm();
            }
        });

        list.addEventListener('click', function (e) {
            var btn = e.target.closest('.task-delete');
            if (!btn) return;
            var li = btn.closest('.other-work-item');
            var taskId = li && li.getAttribute('data-task-id');
            if (!taskId) return;
            if (!confirm('Gỡ việc này khỏi danh sách?')) return;
            postForm('ajax_delete_task', { task_id: taskId })
                .then(function (res) {
                    if (!res || !res.success) {
                        alert((res && res.message) || 'Gỡ thất bại.');
                        return;
                    }
                    li.remove();
                    renumber();
                });
        });
    })();

    /* ============================================================
       Reset danh sách: xóa toàn bộ KHSX hiện hành (sản phẩm + việc khác),
       tái dùng save_plan(force_clear=1) — cùng logic reset của production_plan cũ.
       ============================================================ */
    (function () {
        var btnReset = document.getElementById('btn-reset');
        var listProduct = document.getElementById('list-product');
        var listTasks = document.getElementById('list-other-work');
        if (!btnReset) return;

        btnReset.addEventListener('click', function () {
            if (!confirm('Xóa toàn bộ kế hoạch sản xuất hiện tại?')) return;
            btnReset.disabled = true;
            postForm('save_plan', { items: '[]', tasks: '[]', force_clear: 1 })
                .then(function (res) {
                    btnReset.disabled = false;
                    if (!res || !res.success) {
                        alert((res && res.message) || 'Reset thất bại.');
                        return;
                    }
                    if (listProduct) {
                        listProduct.innerHTML = '<li class="list-product-empty" style="grid-column:1/-1;text-align:center;padding:40px;color:#6b7280;font-style:italic;list-style:none;">Chưa có kế hoạch sản xuất được gửi xuống.</li>';
                    }
                    if (listTasks) listTasks.innerHTML = '';
                })
                .catch(function () {
                    btnReset.disabled = false;
                    alert('Lỗi kết nối khi reset.');
                });
        });
    })();

    /* ============================================================
       Ô tìm kiếm thêm sản phẩm mới vào KHSX hiện hành (plan_for_staff giờ là
       bảng sống — thêm SP ghi thẳng vào production_plans qua ajax_add_plan_product,
       không cần bước "Gửi KHSX" trung gian như production_plan cũ).
       ============================================================ */
    (function () {
        var $search   = document.getElementById('pf-search-product');
        var $dropdown = document.getElementById('pf-search-dropdown');
        var $list     = document.getElementById('list-product');
        if (!$search || !$dropdown || !$list) return;

        var activeIdx = -1;
        var timer = null;

        function hideDropdown() {
            $dropdown.classList.remove('active');
            $dropdown.innerHTML = '';
            activeIdx = -1;
        }

        function renderDropdown(data) {
            activeIdx = -1;
            if (!data.length) {
                $dropdown.innerHTML = '<li class="empty">Không tìm thấy sản phẩm</li>';
            } else {
                $dropdown.innerHTML = data.map(function (it) {
                    return '<li data-id="' + it.id + '">' + escapeHtml(it.product_name) + '</li>';
                }).join('');
            }
            $dropdown.classList.add('active');
        }

        function buildCardHtml(plan) {
            var pid = plan.product_id;
            var qty = (plan.quantity === null || typeof plan.quantity === 'undefined') ? 0 : plan.quantity;
            var isChk = function (v) { return Number(v) === 1 ? 'checked' : ''; };
            var stockDisplay = (plan.stock_qty === null || typeof plan.stock_qty === 'undefined') ? '—' : parseInt(plan.stock_qty, 10);
            return '' +
                '<li class="product-item" data-product-id="' + pid + '">' +
                    '<div class="wp-product-num">' +
                        '<div class="name-product">' +
                            '<p><strong class="pf-name-text" contenteditable="false" spellcheck="false" data-product-id="' + pid + '">' + escapeHtml(plan.product_name) + '</strong>' +
                            '<button type="button" class="pf-name-edit-btn" title="Sửa tên"><i class="fa-solid fa-pen"></i></button></p>' +
                        '</div>' +
                        '<div class="num"><input type="text" class="pf-qty-input" data-plan-id="' + plan.plan_id + '" value="' + qty + '"></div>' +
                    '</div>' +
                    '<div class="list-QC">' +
                        '<div class="wp-ck"><input type="checkbox" id="ck_sample_' + plan.plan_id + '" data-plan-id="' + plan.plan_id + '" data-field="sample" ' + isChk(plan.sample) + '><label for="ck_sample_' + plan.plan_id + '">Lấy mẫu</label></div>' +
                        '<div class="wp-ck"><input type="checkbox" id="ck_test_' + plan.plan_id + '" data-plan-id="' + plan.plan_id + '" data-field="test" ' + isChk(plan.test) + '><label for="ck_test_' + plan.plan_id + '">Test</label></div>' +
                        '<div class="wp-ck"><input type="checkbox" id="ck_stock_' + plan.plan_id + '" data-plan-id="' + plan.plan_id + '" data-field="check_inventory" ' + isChk(plan.check_inventory) + '><label for="ck_stock_' + plan.plan_id + '">Kiểm tồn</label></div>' +
                    '</div>' +
                    '<div class="wp-info">' +
                        '<div class="title"><p>Thông tin:</p></div>' +
                        '<ul class="list-info">' +
                            '<li class="info-item">Tồn kho đầu ngày: <span>' + stockDisplay + '</span></li>' +
                            '<li class="info-item">Bao bì trong:' +
                                '<div class="pkg-picker" data-field="inner_packaging_id" data-product-id="' + pid + '" data-id="' + (plan.inner_packaging_id || 0) + '">' +
                                    '<input type="text" class="pkg-search" value="' + escapeHtml(plan.inner_packaging || '') + '" placeholder="Tìm nguyên liệu..." autocomplete="off">' +
                                    '<div class="search-results"></div>' +
                                '</div>' +
                            '</li>' +
                            '<li class="info-item">QC BBT:' +
                                '<input type="text" class="info-field" data-field="inner_packaging_spec" data-product-id="' + pid + '" value="' + escapeHtml(plan.inner_packaging_spec || '') + '" placeholder="Nhập quy cách...">' +
                            '</li>' +
                            '<li class="info-item">Bao bì ngoài:' +
                                '<div class="pkg-picker" data-field="outer_packaging_id" data-product-id="' + pid + '" data-id="' + (plan.outer_packaging_id || 0) + '">' +
                                    '<input type="text" class="pkg-search" value="' + escapeHtml(plan.outer_packaging || '') + '" placeholder="Tìm nguyên liệu..." autocomplete="off">' +
                                    '<div class="search-results"></div>' +
                                '</div>' +
                            '</li>' +
                            '<li class="info-item">QC BBN:' +
                                '<input type="text" class="info-field" data-field="outer_packaging_spec" data-product-id="' + pid + '" value="' + escapeHtml(plan.outer_packaging_spec || '') + '" placeholder="Nhập quy cách...">' +
                            '</li>' +
                        '</ul>' +
                    '</div>' +
                '</li>';
        }

        function addPlan(productId) {
            if ($list.querySelector('.product-item[data-product-id="' + productId + '"]')) {
                alert('Sản phẩm đã có trong kế hoạch.');
                hideDropdown();
                return;
            }
            postForm('ajax_add_plan_product', { product_id: productId }).then(function (res) {
                if (!res || !res.success || !res.plan) {
                    alert((res && res.message) || 'Thêm sản phẩm thất bại.');
                    return;
                }
                var emptyEl = $list.querySelector('.list-product-empty');
                if (emptyEl) emptyEl.remove();
                $list.insertAdjacentHTML('beforeend', buildCardHtml(res.plan));
                $search.value = '';
                hideDropdown();
            });
        }

        $search.addEventListener('input', function () {
            var kw = $search.value.trim();
            clearTimeout(timer);
            if (kw === '') { hideDropdown(); return; }
            timer = setTimeout(function () {
                postForm('search_product', { keyword: kw }).then(function (res) {
                    renderDropdown(res.data || []);
                });
            }, 220);
        });

        $search.addEventListener('keydown', function (e) {
            if (!$dropdown.classList.contains('active')) return;
            var lis = $dropdown.querySelectorAll('li:not(.empty)');
            if (!lis.length) return;
            if (e.key === 'ArrowDown') {
                e.preventDefault();
                activeIdx = (activeIdx + 1) % lis.length;
            } else if (e.key === 'ArrowUp') {
                e.preventDefault();
                activeIdx = (activeIdx - 1 + lis.length) % lis.length;
            } else if (e.key === 'Enter' || e.key === 'Tab') {
                if (activeIdx >= 0) {
                    e.preventDefault();
                    addPlan(lis[activeIdx].getAttribute('data-id'));
                }
                return;
            } else if (e.key === 'Escape') {
                hideDropdown();
                return;
            } else {
                return;
            }
            lis.forEach(function (li) { li.classList.remove('active'); });
            lis[activeIdx].classList.add('active');
            lis[activeIdx].scrollIntoView({ block: 'nearest' });
        });

        $dropdown.addEventListener('click', function (e) {
            var li = e.target.closest('li');
            if (!li || li.classList.contains('empty')) return;
            addPlan(li.getAttribute('data-id'));
        });

        document.addEventListener('click', function (e) {
            if (!e.target.closest('.wp-search')) hideDropdown();
        });
    })();
})();
