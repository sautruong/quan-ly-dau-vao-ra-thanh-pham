(function () {
    'use strict';

    var ACT = '?mod=production_staff&controllers=production_staff&action=';

    /* Dirty-state cho nút "Lưu & Gửi kế hoạch" (admin): mọi thao tác GHI đi qua postForm với action
       dưới đây sẽ bật cờ "có thay đổi" → nút sáng lên + chặn rời trang. */
    var EDIT_ACTIONS = {
        ajax_update_plan_qc: 1, ajax_update_plan_quantity: 1, ajax_add_plan_product: 1,
        ajax_add_task: 1, ajax_delete_task: 1, ajax_delete_plan_product: 1,
        save_note: 1, delete_note: 1, save_product_common_name: 1,
        save_material_common_name: 1, ajax_update_product_basic: 1
    };
    var pfDirty = false;
    function pfMarkDirty() {
        if (pfDirty) return;
        pfDirty = true;
        var b = document.getElementById('pf-save-send');
        if (b) { b.disabled = false; b.classList.add('is-dirty'); }
    }
    function pfClearDirty() {
        pfDirty = false;
        var b = document.getElementById('pf-save-send');
        if (b) { b.disabled = true; b.classList.remove('is-dirty'); }
    }

    function postForm(action, payload) {
        if (EDIT_ACTIONS[action]) pfMarkDirty();
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
                    owLive.querySelectorAll('.other-work-item .content-other-work .other-work-text'),
                    function (el) { return el.textContent.trim(); }
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
                            '<div class="content-other-work"><span class="other-work-text">' + escapeHtml(desc) + '</span></div>' +
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
                '<li class="product-item pf-acc" data-product-id="' + pid + '">' +
                    '<div class="wp-product-num pf-acc-head">' +
                        '<div class="pf-prod-ava">' + (plan.image_url
                            ? '<img src="public/images/' + escapeHtml(plan.image_url) + '" alt="" loading="lazy">'
                            : '<i class="fa-solid fa-box pf-ava-ph"></i>') + '</div>' +
                        '<div class="name-product">' +
                            '<p><strong class="pf-name-text" contenteditable="false" spellcheck="false" data-product-id="' + pid + '">' + escapeHtml(plan.product_name) + '</strong>' +
                            '<button type="button" class="pf-name-edit-btn" title="Sửa tên"><i class="fa-solid fa-pen"></i></button></p>' +
                        '</div>' +
                        '<div class="num"><input type="text" class="pf-qty-input" data-plan-id="' + plan.plan_id + '" value="' + qty + '"></div>' +
                        '<span class="pf-acc-caret" aria-hidden="true"><i class="fa-solid fa-chevron-down"></i></span>' +
                    '</div>' +
                    '<div class="pf-acc-body">' +
                    '<div class="list-QC">' +
                        '<div class="wp-ck"><input type="checkbox" id="ck_sample_' + plan.plan_id + '" data-plan-id="' + plan.plan_id + '" data-field="sample" ' + isChk(plan.sample) + '><label for="ck_sample_' + plan.plan_id + '">Lấy mẫu</label></div>' +
                        '<div class="wp-ck"><input type="checkbox" id="ck_test_' + plan.plan_id + '" data-plan-id="' + plan.plan_id + '" data-field="test" ' + isChk(plan.test) + '><label for="ck_test_' + plan.plan_id + '">Test</label></div>' +
                        '<div class="wp-ck"><input type="checkbox" id="ck_stock_' + plan.plan_id + '" data-plan-id="' + plan.plan_id + '" data-field="check_inventory" ' + isChk(plan.check_inventory) + '><label for="ck_stock_' + plan.plan_id + '">Kiểm tồn</label></div>' +
                    '</div>' +
                    '<div class="wp-info">' +
                        '<div class="title"><p>Thông tin:</p></div>' +
                        (plan.system_product_name ? '<div class="pf-sys-name">' + escapeHtml(plan.system_product_name) + '</div>' : '') +
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
                    '<div class="pf-card-actions">' +
                        '<button type="button" class="pf-mc-btn" data-product-id="' + pid + '">Đối chiếu</button>' +
                        '<button type="button" class="pf-formula-btn pf-formula-admin" data-product-id="' + pid + '">Công thức</button>' +
                        '<button type="button" class="pf-del-product" data-plan-id="' + plan.plan_id + '">Xoá</button>' +
                    '</div>' +
                    '</div>' + // .pf-acc-body
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

    /* ============================================================
       FAQ / Accordion: mỗi sản phẩm gập/mở. Bấm hàng tên để mở chi tiết (QC, bao bì, lưu ý).
       Admin: bỏ qua khi bấm đúng ô số lượng / nút sửa tên để không chặn thao tác sửa. User chỉ
       xem: bấm đâu trên hàng cũng gập/mở (ô đã khoá nên không sợ chặn nhầm).
       ============================================================ */
    (function () {
        var list = document.getElementById('list-product');
        if (!list) return;

        var mcBackdrop = document.getElementById('pf-mc-backdrop');
        var mcContent  = document.getElementById('pf-mc-content');
        var mcTitle    = document.getElementById('pf-mc-mtitle');
        var mcClose    = document.getElementById('pf-mc-close');

        function fmtNum(n) { n = Number(n) || 0; return (Math.round(n * 1000) / 1000).toLocaleString('vi-VN'); }
        function cardQty(pid) {
            var li = document.querySelector('.product-item[data-product-id="' + pid + '"]');
            var inp = li && li.querySelector('.pf-qty-input');
            return inp ? (parseInt(String(inp.value).replace(/[^\d]/g, ''), 10) || 0) : 0;
        }
        function nameOf(pid) {
            var li = document.querySelector('.product-item[data-product-id="' + pid + '"]');
            var el = li && li.querySelector('.pf-name-text');
            return el ? el.textContent.trim() : '';
        }
        function renderMc(res) {
            if (!res || !res.ok) {
                mcContent.innerHTML = '<div class="pf-mc-empty">' + escapeHtml((res && res.reason) || 'Chưa có dữ liệu đối chiếu.') + '</div>';
                return;
            }
            var short = res.short_count > 0;
            var head = '<div class="pf-mc-head' + (short ? ' is-short' : '') + '">'
                + (short ? '<i class="fa-solid fa-triangle-exclamation"></i> Thiếu ' + res.short_count + ' nguyên vật liệu'
                         : '<i class="fa-solid fa-circle-check"></i> Đủ nguyên vật liệu')
                + '</div><div class="pf-mc-sub">Định mức theo lần SX gần nhất' + (res.last_date ? (' (' + res.last_date + ')') : '') + '</div>';
            var items = (res.rows || []).map(function (r) {
                return '<div class="pf-mc-item' + (r.short ? ' is-short' : '') + '">'
                    + '<div class="pf-mc-name">' + escapeHtml(r.name) + '</div>'
                    + '<div class="pf-mc-stats">'
                        + '<div class="pf-mc-cell"><span class="pf-mc-lbl">Cần</span><span class="pf-mc-val">' + fmtNum(r.need) + (r.unit ? (' ' + escapeHtml(r.unit)) : '') + '</span></div>'
                        + '<div class="pf-mc-cell"><span class="pf-mc-lbl">Tồn</span><span class="pf-mc-val">' + fmtNum(r.stock) + '</span></div>'
                        + '<div class="pf-mc-cell"><span class="pf-mc-lbl">Đối chiếu</span><span class="pf-mc-val ' + (r.short ? 'is-short' : 'is-ok') + '">' + (r.short ? ('Thiếu ' + fmtNum(-r.diff)) : 'Đủ') + '</span></div>'
                    + '</div></div>';
            }).join('');
            mcContent.innerHTML = head + items;
        }
        function openMatcheck(pid) {
            if (!mcBackdrop || !mcContent) return;
            if (mcTitle) mcTitle.textContent = 'Đối chiếu — ' + nameOf(pid);
            mcContent.innerHTML = '<div class="pf-mc-loading">Đang đối chiếu tồn…</div>';
            mcBackdrop.classList.add('is-open');
            // Luôn tính theo SL HIỆN TẠI ở input (đổi số → đối chiếu cập nhật theo).
            fetch(ACT + 'material_check_get&product_id=' + pid + '&quantity=' + cardQty(pid), { credentials: 'same-origin' })
                .then(function (r) { return r.json(); })
                .then(function (res) { renderMc(res); })
                .catch(function () { mcContent.innerHTML = '<div class="pf-mc-empty">Không tải được đối chiếu tồn.</div>'; });
        }
        function closeMc() { if (mcBackdrop) mcBackdrop.classList.remove('is-open'); }
        if (mcClose) mcClose.addEventListener('click', closeMc);
        if (mcBackdrop) mcBackdrop.addEventListener('click', function (e) { if (e.target === mcBackdrop) closeMc(); });

        // Nút "Đối chiếu" → mở modal bảng đối chiếu.
        document.addEventListener('click', function (e) {
            var b = e.target.closest && e.target.closest('.pf-mc-btn');
            if (!b) return;
            e.stopPropagation();
            openMatcheck(parseInt(b.getAttribute('data-product-id'), 10) || 0);
        });

        // Cảnh báo khi SP thiếu NVL (chưa cần mở modal): chấm đỏ ở nút "Đối chiếu" + viền cam đỏ nhạt ở ô Số lượng
        // (ô SL nằm ở ĐẦU card nên thấy cả khi card đang gập — user chốt 14/11/2026). Trả về Promise để quét tuần tự.
        function checkShort(item) {
            var btn = item.querySelector('.pf-mc-btn');
            var qtyInp = item.querySelector('.pf-qty-input');
            if (!btn && !qtyInp) return Promise.resolve();
            var pid = item.getAttribute('data-product-id');
            // Gõ đổi SL liên tiếp: kết quả của lần gọi cũ về chậm không được đè lên kết quả mới.
            var seq = (item._pfMcSeq = (item._pfMcSeq || 0) + 1);
            return fetch(ACT + 'material_check_get&product_id=' + pid + '&quantity=' + cardQty(pid), { credentials: 'same-origin' })
                .then(function (r) { return r.json(); })
                .then(function (res) {
                    if (seq !== item._pfMcSeq) return;
                    var short = !!(res && res.ok && res.short_count > 0);
                    if (btn) btn.innerHTML = (short ? '<span class="pf-mc-warn" aria-label="Thiếu NVL"></span>' : '') + 'Đối chiếu';
                    if (qtyInp) qtyInp.classList.toggle('is-short', short);
                }).catch(function () {});
        }

        // Quét cả danh sách khi tải trang (mỗi card một request nên chạy TUẦN TỰ, khỏi dồn lên máy chủ).
        var scanChain = Promise.resolve();
        function scanShort(items) {
            items.forEach(function (it) { scanChain = scanChain.then(function () { return checkShort(it); }); });
        }
        scanShort(Array.prototype.slice.call(list.querySelectorAll('.product-item[data-product-id]')));
        // Card thêm sau đó (tìm SP → thêm vào kế hoạch) cũng được kiểm.
        if (window.MutationObserver) {
            new MutationObserver(function (muts) {
                muts.forEach(function (m) {
                    Array.prototype.forEach.call(m.addedNodes, function (n) {
                        if (n.nodeType === 1 && n.matches && n.matches('.product-item[data-product-id]')) scanShort([n]);
                    });
                });
            }).observe(list, { childList: true });
        }

        // Gập/mở card; khi mở → kiểm tra thiếu để tô cảnh báo trên nút Đối chiếu.
        list.addEventListener('click', function (e) {
            var head = e.target.closest('.pf-acc-head');
            if (!head) return;
            if (!window.PF_READONLY && (e.target.closest('.num')
                || e.target.closest('.pf-name-edit-btn') || e.target.closest('.pf-name-text'))) return;
            var item = head.closest('.product-item');
            if (!item) return;
            item.classList.toggle('open');
            if (item.classList.contains('open')) checkShort(item);
        });

        // Đổi SL (đã lưu qua ajax) → cập nhật lại cảnh báo thiếu (nút Đối chiếu + viền ô SL), kể cả khi card đang gập.
        list.addEventListener('change', function (e) {
            var q = e.target.closest && e.target.closest('.pf-qty-input');
            if (!q) return;
            var item = q.closest('.product-item');
            if (item) checkShort(item);
        });
    })();

    /* ============================================================
       CÔNG THỨC (formula image): admin xem/nạp/duyệt; user xem ảnh (zoom) khi đã duyệt.
       ============================================================ */
    (function () {
        var ACTF = '?mod=production_staff&controllers=production_staff&action=';
        var fxBackdrop = document.getElementById('pf-fx-backdrop');
        var fxImg = document.getElementById('pf-fx-img');
        var fxEmpty = document.getElementById('pf-fx-empty');
        var fxNote = document.getElementById('pf-fx-note');
        var fxAdmin = document.getElementById('pf-fx-admin');
        var fxFile = document.getElementById('pf-fx-file');
        var fxUpload = document.getElementById('pf-fx-upload');
        var fxApprove = document.getElementById('pf-fx-approve');
        var fxClose = document.getElementById('pf-fx-close');
        var fxTitle = document.getElementById('pf-fx-title');
        // Lời nhắc (admin): khối hiển thị + nút + ô nhập.
        var fxRemind = document.getElementById('pf-fx-remind');
        var fxRemindT = document.getElementById('pf-fx-remind-t');
        var fxNoteBtn = document.getElementById('pf-fx-notebtn');
        var fxNoteBtnT = document.getElementById('pf-fx-notebtn-t');
        var fxNoteEdit = document.getElementById('pf-fx-noteedit');
        var fxNoteText = document.getElementById('pf-fx-notetext');
        var fxNoteCount = document.getElementById('pf-fx-notecount');
        var fxNoteSave = document.getElementById('pf-fx-notesave');
        var fxNoteDel = document.getElementById('pf-fx-notedel');
        var fxNoteCancel = document.getElementById('pf-fx-notecancel');
        // Mô tả công thức (admin): khối hiển thị + nút + ô nhập (chữ thuần).
        var fxDesc = document.getElementById('pf-fx-desc');
        var fxDescT = document.getElementById('pf-fx-desc-t');
        var fxDescBtn = document.getElementById('pf-fx-descbtn');
        var fxDescBtnT = document.getElementById('pf-fx-descbtn-t');
        var fxDescEdit = document.getElementById('pf-fx-descedit');
        var fxDescText = document.getElementById('pf-fx-desctext');
        var fxDescCount = document.getElementById('pf-fx-desccount');
        var fxDescSave = document.getElementById('pf-fx-descsave');
        var fxDescDel = document.getElementById('pf-fx-descdel');
        var fxDescCancel = document.getElementById('pf-fx-desccancel');
        var curPid = 0, curQty = 0, curApproved = false, curNote = '', curDesc = '';
        // Nhắc duyệt sau "XUẤT KH": danh sách SP chưa duyệt (rmItems) + tập đã duyệt trong phiên này (rmDone).
        var rmItems = [], rmDone = {}, rmActive = false;

        function cardQty(pid) {
            var li = document.querySelector('.product-item[data-product-id="' + pid + '"]');
            var inp = li && li.querySelector('.pf-qty-input');
            return inp ? (parseInt(String(inp.value).replace(/[^\d]/g, ''), 10) || 0) : 0;
        }
        function nameOf(pid) {
            var li = document.querySelector('.product-item[data-product-id="' + pid + '"]');
            var el = li && li.querySelector('.pf-name-text');
            return el ? el.textContent.trim() : '';
        }
        function approveLabel(on) {
            return on ? '<i class="fa-solid fa-user-xmark"></i> Bỏ duyệt'
                      : '<i class="fa-solid fa-user-check"></i> Duyệt cho NV xem';
        }
        function renderFx(res) {
            if (res.has_image) { fxImg.src = res.image_url; fxImg.style.display = ''; fxEmpty.style.display = 'none'; }
            else { fxImg.style.display = 'none'; fxEmpty.style.display = ''; }
            if (fxNote) fxNote.textContent = (res.has_image && !res.exact)
                ? ('Đang dùng ảnh của sản lượng ' + res.source_quantity + ' (lân cận ±3%).') : '';
            if (res.is_admin && fxAdmin) {
                fxAdmin.style.display = '';
                if (fxApprove) { fxApprove.classList.toggle('is-on', !!res.approved); fxApprove.innerHTML = approveLabel(!!res.approved); }
            } else if (fxAdmin) { fxAdmin.style.display = 'none'; }
            curNote = String(res.note || '');
            renderNote();
            curDesc = String(res.description || '');
            renderDesc();
        }

        /* ---- Lời nhắc (admin nhập; NV thấy trước hình) ---- */
        function renderNote() {
            var has = curNote !== '';
            if (fxRemind) fxRemind.style.display = has ? '' : 'none';
            if (fxRemindT) fxRemindT.innerHTML = curNote;   // HTML đã được server lọc (b/i/u/ul/li/màu)
            if (fxNoteBtnT) fxNoteBtnT.textContent = has ? 'Sửa lời nhắc' : 'Nhập lời nhắc';
            if (fxNoteBtn) fxNoteBtn.classList.toggle('is-on', has);
            closeNoteEdit();
        }

        /* ---- Trình soạn lời nhắc: B / I / U / gạch đầu dòng / màu chữ cơ bản (contenteditable + execCommand) ---- */
        var fxRtBar = document.getElementById('pf-fx-rt-bar');
        var NOTE_MAX = 500;   // ký tự hiển thị (khớp fmi_note_max() phía server)
        function rtText() { return (fxNoteText ? fxNoteText.textContent : '').replace(/^[\s ]+|[\s ]+$/g, ''); }
        function rtHtml() { return (fxNoteText && rtText()) ? fxNoteText.innerHTML : ''; }   // rỗng chữ = rỗng (→ xoá lời nhắc)
        function updNoteCount() {
            var n = rtText().length;
            if (fxNoteCount) { fxNoteCount.textContent = n + '/' + NOTE_MAX; fxNoteCount.classList.toggle('is-over', n > NOTE_MAX); }
            if (fxNoteSave) fxNoteSave.disabled = n > NOTE_MAX;
        }
        function rgbToHex(s) {
            var m = /rgba?\(\s*(\d+)\s*,\s*(\d+)\s*,\s*(\d+)/i.exec(String(s || ''));
            if (!m) return /^#[0-9a-f]{6}$/i.test(s) ? String(s).toLowerCase() : '';
            return '#' + [m[1], m[2], m[3]].map(function (v) { return ('0' + Math.min(255, parseInt(v, 10)).toString(16)).slice(-2); }).join('');
        }
        function rtSync() {   // sáng nút B/I/U/danh sách + chấm màu theo vị trí con trỏ
            if (!fxRtBar || !fxNoteText) return;
            var sel = window.getSelection();
            if (!sel || !sel.anchorNode || !fxNoteText.contains(sel.anchorNode)) return;
            ['bold', 'italic', 'underline', 'insertUnorderedList'].forEach(function (c) {
                var b = fxRtBar.querySelector('[data-cmd="' + c + '"]'), on = false;
                try { on = document.queryCommandState(c); } catch (x) {}
                if (b) b.classList.toggle('is-on', !!on);
            });
            var hex = '';
            try { hex = rgbToHex(document.queryCommandValue('foreColor')); } catch (x) {}
            fxRtBar.querySelectorAll('.pf-rt-color').forEach(function (s) { s.classList.toggle('is-on', !!hex && s.getAttribute('data-color') === hex); });
        }
        function rtExec(cmd, val) {
            if (!fxNoteText) return;
            fxNoteText.focus();
            // Chỉ màu dùng CSS (<span style=color>); B/I/U để mặc định ra <b>/<i>/<u> cho server lọc giữ lại.
            try { document.execCommand('styleWithCSS', false, cmd === 'foreColor'); } catch (x) {}
            try { document.execCommand(cmd, false, val || null); } catch (x) {}
            try { document.execCommand('styleWithCSS', false, false); } catch (x) {}
            updNoteCount(); rtSync();
        }
        if (fxRtBar) {
            // mousedown KHÔNG được cướp focus/vùng chọn của ô soạn thì định dạng mới áp đúng đoạn đang bôi.
            fxRtBar.addEventListener('mousedown', function (e) { if (e.target.closest('.pf-rt-btn, .pf-rt-color')) e.preventDefault(); });
            fxRtBar.addEventListener('click', function (e) {
                var b = e.target.closest('.pf-rt-btn');
                if (b) { rtExec(b.getAttribute('data-cmd')); return; }
                var c = e.target.closest('.pf-rt-color');
                if (c) rtExec('foreColor', c.getAttribute('data-color'));
            });
        }
        if (fxNoteText) {
            fxNoteText.addEventListener('input', function () {
                // Xoá sạch chữ thì dọn luôn thẻ còn sót (<br>…) để placeholder hiện lại; giữ nếu đang có gạch đầu dòng.
                if (!fxNoteText.textContent && !fxNoteText.querySelector('li')) fxNoteText.innerHTML = '';
                updNoteCount();
            });
            // Dán = chỉ lấy CHỮ THUẦN (tránh kéo theo định dạng/rác từ Word, web…); định dạng do thanh công cụ quyết định.
            fxNoteText.addEventListener('paste', function (e) {
                e.preventDefault();
                var t = (e.clipboardData || window.clipboardData).getData('text/plain') || '';
                try { document.execCommand('insertText', false, t); } catch (x) {}
            });
            fxNoteText.addEventListener('drop', function (e) { e.preventDefault(); });
            fxNoteText.addEventListener('keyup', rtSync);
            fxNoteText.addEventListener('mouseup', rtSync);
        }
        document.addEventListener('selectionchange', rtSync);

        function openNoteEdit() {
            if (!fxNoteEdit || !fxNoteText) return;
            fxNoteText.innerHTML = curNote; updNoteCount();
            if (fxNoteDel) fxNoteDel.style.display = curNote ? '' : 'none';
            fxNoteEdit.style.display = '';
            fxNoteText.focus();
            try {   // đặt con trỏ ở cuối nội dung
                var r = document.createRange(); r.selectNodeContents(fxNoteText); r.collapse(false);
                var s = window.getSelection(); s.removeAllRanges(); s.addRange(r);
            } catch (x) {}
            rtSync();
        }
        function closeNoteEdit() { if (fxNoteEdit) fxNoteEdit.style.display = 'none'; }
        function saveNote(text) {
            if (!curPid) return;
            var pid = curPid;
            [fxNoteSave, fxNoteDel, fxNoteCancel].forEach(function (b) { if (b) b.disabled = true; });
            postForm('formula_note_save', { product_id: pid, note: text }).then(function (res) {
                if (res && res.ok) {
                    if (pid === curPid) { curNote = String(res.note || ''); renderNote(); }
                    toast(res.note ? 'Đã lưu lời nhắc.' : 'Đã xóa lời nhắc.');
                } else toast((res && res.message) || 'Lưu lời nhắc thất bại.');
            }).catch(function () { toast('Lỗi mạng khi lưu lời nhắc.'); })
              .then(function () { [fxNoteSave, fxNoteDel, fxNoteCancel].forEach(function (b) { if (b) b.disabled = false; }); updNoteCount(); });
        }
        if (fxNoteBtn) fxNoteBtn.addEventListener('click', function () {
            if (fxNoteEdit && fxNoteEdit.style.display !== 'none') closeNoteEdit(); else openNoteEdit();
        });
        if (fxNoteCancel) fxNoteCancel.addEventListener('click', closeNoteEdit);
        if (fxNoteSave) fxNoteSave.addEventListener('click', function () { saveNote(rtHtml()); });
        if (fxNoteDel) fxNoteDel.addEventListener('click', function () {
            if (confirm('Xóa lời nhắc của công thức này?')) saveNote('');
        });

        /* ---- Mô tả công thức (admin nhập; NV thấy ở chân hình một lúc rồi ẩn) ---- */
        var DESC_MAX = 300;   // khớp fmi_desc_max() phía server
        function renderDesc() {
            var has = curDesc !== '';
            if (fxDesc) fxDesc.style.display = has ? '' : 'none';
            if (fxDescT) fxDescT.textContent = curDesc;
            if (fxDescBtnT) fxDescBtnT.textContent = has ? 'Sửa mô tả' : 'Nhập mô tả';
            if (fxDescBtn) fxDescBtn.classList.toggle('is-on', has);
            closeDescEdit();
        }
        function updDescCount() {
            var n = fxDescText ? fxDescText.value.trim().length : 0;
            if (fxDescCount) { fxDescCount.textContent = n + '/' + DESC_MAX; fxDescCount.classList.toggle('is-over', n > DESC_MAX); }
            if (fxDescSave) fxDescSave.disabled = n > DESC_MAX;
        }
        function openDescEdit() {
            if (!fxDescEdit || !fxDescText) return;
            fxDescText.value = curDesc; updDescCount();
            if (fxDescDel) fxDescDel.style.display = curDesc ? '' : 'none';
            fxDescEdit.style.display = '';
            fxDescText.focus();
        }
        function closeDescEdit() { if (fxDescEdit) fxDescEdit.style.display = 'none'; }
        function saveDesc(text) {
            if (!curPid) return;
            var pid = curPid;
            [fxDescSave, fxDescDel, fxDescCancel].forEach(function (b) { if (b) b.disabled = true; });
            postForm('formula_desc_save', { product_id: pid, quantity: curQty, description: text }).then(function (res) {
                if (res && res.ok) {
                    if (pid === curPid) { curDesc = String(res.description || ''); renderDesc(); }
                    toast(res.description ? 'Đã lưu mô tả.' : 'Đã xóa mô tả.');
                } else toast((res && res.message) || 'Lưu mô tả thất bại.');
            }).catch(function () { toast('Lỗi mạng khi lưu mô tả.'); })
              .then(function () { [fxDescSave, fxDescDel, fxDescCancel].forEach(function (b) { if (b) b.disabled = false; }); updDescCount(); });
        }
        if (fxDescBtn) fxDescBtn.addEventListener('click', function () {
            if (fxDescEdit && fxDescEdit.style.display !== 'none') closeDescEdit(); else openDescEdit();
        });
        if (fxDescText) fxDescText.addEventListener('input', updDescCount);
        if (fxDescCancel) fxDescCancel.addEventListener('click', closeDescEdit);
        if (fxDescSave) fxDescSave.addEventListener('click', function () { saveDesc(fxDescText ? fxDescText.value : ''); });
        if (fxDescDel) fxDescDel.addEventListener('click', function () {
            if (confirm('Xóa mô tả của công thức này?')) saveDesc('');
        });
        function openFx(pid) {
            curPid = pid; curQty = cardQty(pid);
            if (fxTitle) fxTitle.textContent = 'Công thức — ' + nameOf(pid) + (curQty ? (' (' + curQty + ')') : '');
            fetch(ACTF + 'formula_image_get&product_id=' + pid + '&quantity=' + curQty, { credentials: 'same-origin' })
                .then(function (r) { return r.json(); })
                .then(function (res) {
                    if (!res || !res.ok) { if (rmActive) showReminder(); return; }
                    curApproved = !!res.approved; renderFx(res);
                    if (fxBackdrop) fxBackdrop.classList.add('is-open');
                }).catch(function () { if (rmActive) showReminder(); });
        }
        function closeFx() {
            if (fxBackdrop) fxBackdrop.classList.remove('is-open');
            closeNoteEdit(); closeDescEdit();
            // Đang trong lượt "nhắc duyệt công thức" → quay lại danh sách (SP đã duyệt được gạch khỏi danh sách).
            if (rmActive) showReminder();
        }

        /* ---- NV bấm "Xem công thức": lời nhắc (nếu có) hiện TRƯỚC → ẩn dần → rồi mới tới hình ---- */
        var noteOv = document.getElementById('pf-note-ov');
        var noteCard = document.getElementById('pf-note-card');
        var noteProd = document.getElementById('pf-note-prod');
        var noteText = document.getElementById('pf-note-text');
        var noteBar = document.getElementById('pf-note-barfill');
        var noteOk = document.getElementById('pf-note-ok');   // nút "Đã hiểu": bỏ qua nhanh sang hình
        var noteTimer = 0, noteBusy = false;
        var NOTE_FADE_OUT = 900;       // ms: thời gian ẩn dần (hết giờ tự ẩn)
        var NOTE_FADE_FAST = 180;      // ms: mờ nhanh khi NV chủ động bỏ qua (Đã hiểu / chạm)
        var NOTE_SKIP_AFTER = 1000;    // ms: chạm sớm hơn mốc này bị bỏ qua (tránh bấm lỡ tay là mất lời nhắc)

        function noteHoldMs(len) {     // thời gian đọc: ~2,5s + 60ms/ký tự (chữ hiển thị), kẹp 3,5s…12s
            return Math.min(12000, Math.max(3500, 2500 + (parseInt(len, 10) || 0) * 60));
        }
        function noteTextLen(html) {   // số chữ hiển thị của lời nhắc (HTML đã được server lọc)
            var d = document.createElement('div'); d.innerHTML = html;
            return (d.textContent || '').length;
        }
        function showNoteThen(note, prodName, next) {
            if (!noteOv || !noteCard || !note || noteBusy) { if (!noteBusy) next(); return; }
            noteBusy = true;
            var hold = noteHoldMs(noteTextLen(note)), openedAt = Date.now(), finished = false;
            if (noteProd) noteProd.textContent = prodName || '';
            if (noteText) noteText.innerHTML = note;   // HTML đã được server lọc
            noteCard.className = 'pf-note-card';
            noteCard.style.transition = '';
            if (noteBar) { noteBar.style.transition = 'none'; noteBar.style.width = '100%'; }
            noteOv.classList.add('is-open');
            noteOv.setAttribute('aria-hidden', 'false');
            // Hiện lên (fade-in) + chạy thanh đếm ngược.
            requestAnimationFrame(function () {
                requestAnimationFrame(function () {
                    noteCard.classList.add('is-in');
                    if (noteBar) { noteBar.style.transition = 'width ' + hold + 'ms linear'; noteBar.style.width = '0%'; }
                });
            });
            function finish(fast) {
                if (finished) return; finished = true;
                clearTimeout(noteTimer);
                noteOv.removeEventListener('click', onTap);
                if (noteOk) noteOk.removeEventListener('click', onOk);
                var fade = fast === true ? NOTE_FADE_FAST : NOTE_FADE_OUT;
                if (fast === true) noteCard.style.transition = 'opacity ' + NOTE_FADE_FAST + 'ms ease';
                noteCard.classList.remove('is-in'); noteCard.classList.add('is-out');   // ẩn dần
                noteTimer = setTimeout(function () {
                    noteOv.classList.remove('is-open');
                    noteOv.setAttribute('aria-hidden', 'true');
                    noteBusy = false;
                    next();                                                              // rồi mới tới hình công thức
                }, fade);
            }
            // Chạm nền: bỏ qua nhưng chỉ sau NOTE_SKIP_AFTER (chống lỡ tay). Nút "Đã hiểu": chủ động → bỏ qua ngay.
            function onTap() { if (Date.now() - openedAt >= NOTE_SKIP_AFTER) finish(true); }
            function onOk(e) { e.stopPropagation(); finish(true); }
            noteOv.addEventListener('click', onTap);
            if (noteOk) noteOk.addEventListener('click', onOk);
            noteTimer = setTimeout(finish, hold);
        }

        document.addEventListener('click', function (e) {
            var a = e.target.closest && e.target.closest('.pf-formula-admin');
            if (a) { e.stopPropagation(); openFx(parseInt(a.getAttribute('data-product-id'), 10) || 0); return; }
            var v = e.target.closest && e.target.closest('.pf-formula-view');
            if (v) {
                e.stopPropagation();
                if (noteBusy) return;
                var pid = parseInt(v.getAttribute('data-product-id'), 10) || 0;
                fetch(ACTF + 'formula_image_get&product_id=' + pid + '&quantity=' + cardQty(pid), { credentials: 'same-origin' })
                    .then(function (r) { return r.json(); }).then(function (res) {
                        if (!res || !res.ok || !res.allowed) { toast('Chưa có hình công thức cho sản lượng này.'); return; }
                        var show = function () {
                            // Truyền kèm lời nhắc (nút "Xem lại lời nhắc") + mô tả (chữ ở chân hình rồi ẩn).
                            if (res.has_image) openZoom(res.image_url, String(res.note || ''), nameOf(pid), String(res.description || ''));
                            else toast('Chưa có hình công thức cho sản lượng này.');
                        };
                        // Có lời nhắc → hiện lời nhắc trước (mỗi lần xem đều hiện), hết thì mới tới hình.
                        if (res.note) showNoteThen(String(res.note), nameOf(pid), show); else show();
                    }).catch(function () {});
            }
        });
        if (fxClose) fxClose.addEventListener('click', closeFx);
        // Modal thiết lập công thức (admin): CHỈ đóng khi bấm đúng nút × (hoặc Hủy ở ô lời nhắc) — bấm ra nền ngoài KHÔNG đóng,
        // để không mất lời nhắc đang soạn dở / thao tác dở do lỡ chạm.
        if (fxImg) fxImg.addEventListener('click', function () { if (fxImg.src) openZoom(fxImg.src, curNote, nameOf(curPid), curDesc); });

        if (fxUpload && fxFile) {
            fxUpload.addEventListener('click', function () { fxFile.click(); });
            fxFile.addEventListener('change', function () {
                if (!fxFile.files || !fxFile.files[0] || !curPid) return;
                var qty = cardQty(curPid) || curQty;
                if (!qty) { toast('Chưa có sản lượng để gắn công thức.'); return; }
                var fd = new FormData();
                fd.append('product_id', curPid); fd.append('quantity', qty); fd.append('image', fxFile.files[0]);
                fetch(ACTF + 'formula_image_upload', { method: 'POST', body: fd, credentials: 'same-origin' })
                    .then(function (r) { return r.json(); }).then(function (res) {
                        fxFile.value = '';
                        if (res && res.ok) {
                            fxImg.src = res.image_url + '?t=' + Date.now(); fxImg.style.display = ''; fxEmpty.style.display = 'none';
                            if (fxNote) fxNote.textContent = ''; toast('Đã lưu ảnh công thức.');
                            // Mô tả đi theo HÌNH: hình mới đúng sản lượng có thể khác hình lân cận trước đó → nạp lại mô tả đúng.
                            var upPid = curPid;
                            fetch(ACTF + 'formula_image_get&product_id=' + upPid + '&quantity=' + curQty, { credentials: 'same-origin' })
                                .then(function (r) { return r.json(); }).then(function (g) {
                                    if (g && g.ok && upPid === curPid) { curDesc = String(g.description || ''); renderDesc(); }
                                }).catch(function () {});
                        } else toast((res && res.message) || 'Lưu ảnh thất bại.');
                    }).catch(function () { toast('Lỗi mạng khi tải ảnh.'); });
            });
        }
        if (fxApprove) {
            fxApprove.addEventListener('click', function () {
                if (!curPid) return;
                var next = !curApproved;
                var body = new URLSearchParams(); body.append('product_id', curPid); body.append('on', next ? 1 : 0);
                fetch(ACTF + 'formula_approve', { method: 'POST', headers: { 'Content-Type': 'application/x-www-form-urlencoded' }, body: body.toString(), credentials: 'same-origin' })
                    .then(function (r) { return r.json(); }).then(function (res) {
                        if (res && res.ok) {
                            curApproved = !!res.approved;
                            fxApprove.classList.toggle('is-on', curApproved);
                            fxApprove.innerHTML = approveLabel(curApproved);
                            if (curApproved) rmDone[curPid] = true; else delete rmDone[curPid];   // cập nhật danh sách nhắc duyệt
                            toast(curApproved ? 'Đã duyệt cho nhân viên xem.' : 'Đã bỏ duyệt.');
                        } else toast((res && res.message) || 'Thao tác thất bại.');
                    }).catch(function () {});
            });
        }

        /* ---- Nhắc admin duyệt công thức NỐI TIẾP sau "XUẤT KH" (dữ liệu do server nhúng ở data-items) ---- */
        var rmBackdrop = document.getElementById('pf-rm-backdrop');
        var rmList = document.getElementById('pf-rm-list');
        var rmSub = document.getElementById('pf-rm-sub');
        var rmLater = document.getElementById('pf-rm-later');

        function rmPending() { return rmItems.filter(function (it) { return !rmDone[it.product_id]; }); }
        function hideReminder() { if (rmBackdrop) { rmBackdrop.classList.remove('is-open'); rmBackdrop.setAttribute('aria-hidden', 'true'); } }
        function showReminder() {
            if (!rmBackdrop || !rmList) return;
            var pend = rmPending();
            if (!pend.length) {                         // duyệt hết rồi → kết thúc lượt nhắc
                rmActive = false; hideReminder();
                if (rmItems.length) toast('Đã duyệt hết công thức cho nhân viên xem.');
                return;
            }
            if (rmSub) rmSub.textContent = 'Kế hoạch vừa xuất có ' + pend.length + ' sản phẩm có công thức chưa duyệt cho nhân viên xem. Bấm vào để xem công thức rồi duyệt:';
            rmList.innerHTML = '';
            pend.forEach(function (it) {
                var li = document.createElement('li'); li.className = 'pf-rm-item';
                var nm = document.createElement('span'); nm.className = 'pf-rm-name'; nm.textContent = it.name || ('SP #' + it.product_id);
                if (it.quantity) { var q = document.createElement('span'); q.className = 'pf-rm-qty'; q.textContent = ' (' + it.quantity + ')'; nm.appendChild(q); }
                var go = document.createElement('button'); go.type = 'button'; go.className = 'pf-rm-go'; go.textContent = 'Xem & duyệt';
                go.addEventListener('click', function () { hideReminder(); openFx(it.product_id); });
                li.appendChild(nm); li.appendChild(go); rmList.appendChild(li);
            });
            rmBackdrop.classList.add('is-open'); rmBackdrop.setAttribute('aria-hidden', 'false');
        }
        if (rmBackdrop) {
            try { rmItems = JSON.parse(rmBackdrop.getAttribute('data-items') || '[]') || []; } catch (x) { rmItems = []; }
            if (rmItems.length) { rmActive = true; showReminder(); }
            if (rmLater) rmLater.addEventListener('click', function () { rmActive = false; hideReminder(); });
        }

        /* ---- Viewer full viewport: pinch-zoom 2 ngón + kéo pan ---- */
        var zoom = document.getElementById('pf-zoom');
        var zoomImg = document.getElementById('pf-zoom-img');
        var zoomClose = document.getElementById('pf-zoom-close');
        var zs = { scale: 1, tx: 0, ty: 0 }, ptrs = {}, startDist = 0, startScale = 1, panStart = null;

        function applyZoom() { if (zoomImg) zoomImg.style.transform = 'translate(' + zs.tx + 'px,' + zs.ty + 'px) scale(' + zs.scale + ')'; }
        /* Nút "Xem lại lời nhắc" ở chân hình → modal có nút × (đóng thì trở lại hình công thức). */
        var zoomNoteBtn = document.getElementById('pf-zoom-note-btn');
        var nmBackdrop = document.getElementById('pf-nm-backdrop');
        var nmClose = document.getElementById('pf-nm-close');
        var nmProd = document.getElementById('pf-nm-prod');
        var nmText = document.getElementById('pf-nm-text');
        var zoomNote = '', zoomNoteProd = '';

        function openNoteModal() {
            if (!nmBackdrop || !zoomNote) return;
            if (nmProd) nmProd.textContent = zoomNoteProd;
            if (nmText) nmText.innerHTML = zoomNote;   // HTML đã được server lọc
            nmBackdrop.classList.add('is-open'); nmBackdrop.setAttribute('aria-hidden', 'false');
        }
        function closeNoteModal() {
            if (nmBackdrop) { nmBackdrop.classList.remove('is-open'); nmBackdrop.setAttribute('aria-hidden', 'true'); }
        }
        if (zoomNoteBtn) zoomNoteBtn.addEventListener('click', function (e) { e.stopPropagation(); openNoteModal(); });
        if (nmClose) nmClose.addEventListener('click', closeNoteModal);
        if (nmBackdrop) nmBackdrop.addEventListener('click', function (e) { if (e.target === nmBackdrop) closeNoteModal(); });

        /* Mô tả công thức ở CHÂN hình: hiện một lúc rồi ẩn dần; chạm (click) vào hình → hiện lại rồi lại ẩn sau một lúc. */
        var zoomDescEl = document.getElementById('pf-zoom-desc');
        var zoomDesc = '', descTimer = 0, zMoved = false, zStart = null;
        var DESC_SHOW_MS = 5000;     // mô tả hiện đúng 5 giây rồi tự ẩn (cố định, không phụ thuộc độ dài)
        // fast=true: NV chủ động chạm để ẩn → mờ nhanh (is-fast); hết giờ tự ẩn thì mờ êm như cũ.
        function hideZoomDesc(fast) {
            clearTimeout(descTimer);
            if (!zoomDescEl) return;
            zoomDescEl.classList.toggle('is-fast', fast === true);
            zoomDescEl.classList.remove('is-show');
        }
        function showZoomDesc() {
            if (!zoomDescEl || !zoomDesc) return;
            clearTimeout(descTimer);
            zoomDescEl.classList.remove('is-fast');
            zoomDescEl.classList.add('is-show');
            descTimer = setTimeout(hideZoomDesc, DESC_SHOW_MS);   // hết 5s → tự ẩn (đối số = undefined → mờ êm)
        }

        function openZoom(url, note, prodName, desc) {
            if (!zoom || !zoomImg) return;
            zoomNote = String(note || ''); zoomNoteProd = prodName || '';
            if (zoomNoteBtn) zoomNoteBtn.style.display = zoomNote ? '' : 'none';   // không có lời nhắc → không có nút
            zoom.classList.toggle('has-nbtn', !!zoomNote);                          // mô tả nằm trên nút này khi có
            closeNoteModal();
            hideZoomDesc();
            zoomDesc = String(desc || '');
            if (zoomDescEl) zoomDescEl.textContent = zoomDesc;                      // chữ thuần → textContent
            zoomImg.src = url; zs = { scale: 1, tx: 0, ty: 0 }; applyZoom(); zoom.classList.add('is-open');
            if (zoomDesc) requestAnimationFrame(function () { requestAnimationFrame(showZoomDesc); });   // chờ 1 nhịp để hiệu ứng hiện mượt
        }
        function closeZoom() { closeNoteModal(); hideZoomDesc(); if (zoom) zoom.classList.remove('is-open'); }
        if (zoomClose) zoomClose.addEventListener('click', closeZoom);
        if (zoom) zoom.addEventListener('click', function (e) {
            if (e.target === zoom) { closeZoom(); return; }
            // Chạm vào hình (không phải sau khi kéo/zoom): mô tả đang hiện → ẨN NGAY (không đợi hết giờ); đang ẩn → hiện lại.
            if (e.target === zoomImg && !zMoved && zoomDesc) {
                if (zoomDescEl && zoomDescEl.classList.contains('is-show')) hideZoomDesc(true); else showZoomDesc();
            }
        });

        if (zoomImg) {
            zoomImg.addEventListener('pointerdown', function (e) {
                try { zoomImg.setPointerCapture(e.pointerId); } catch (x) {}
                ptrs[e.pointerId] = { x: e.clientX, y: e.clientY };
                var ids = Object.keys(ptrs);
                if (ids.length === 1) { zMoved = false; zStart = { x: e.clientX, y: e.clientY }; }
                else zMoved = true;   // 2 ngón = đang zoom, không tính là "chạm"
                if (ids.length === 2) {
                    var a = ptrs[ids[0]], b = ptrs[ids[1]];
                    startDist = Math.hypot(a.x - b.x, a.y - b.y) || 1; startScale = zs.scale;
                } else if (ids.length === 1) { panStart = { x: e.clientX - zs.tx, y: e.clientY - zs.ty }; }
            });
            zoomImg.addEventListener('pointermove', function (e) {
                if (!ptrs[e.pointerId]) return;
                ptrs[e.pointerId] = { x: e.clientX, y: e.clientY };
                if (zStart && Math.hypot(e.clientX - zStart.x, e.clientY - zStart.y) > 8) zMoved = true;   // kéo > 8px = đang kéo hình
                var ids = Object.keys(ptrs);
                if (ids.length === 2) {
                    var a = ptrs[ids[0]], b = ptrs[ids[1]];
                    var dist = Math.hypot(a.x - b.x, a.y - b.y) || 1;
                    zs.scale = Math.min(6, Math.max(1, startScale * (dist / startDist))); applyZoom();
                } else if (ids.length === 1 && panStart && zs.scale > 1) {
                    zs.tx = e.clientX - panStart.x; zs.ty = e.clientY - panStart.y; applyZoom();
                }
            });
            function endPtr(e) {
                delete ptrs[e.pointerId];
                var ids = Object.keys(ptrs);
                if (ids.length === 1) panStart = { x: ptrs[ids[0]].x - zs.tx, y: ptrs[ids[0]].y - zs.ty };
                if (ids.length === 0 && zs.scale <= 1.01) { zs = { scale: 1, tx: 0, ty: 0 }; applyZoom(); }
            }
            zoomImg.addEventListener('pointerup', endPtr);
            zoomImg.addEventListener('pointercancel', endPtr);
            zoomImg.addEventListener('dblclick', function () { zs.scale > 1 ? (zs = { scale: 1, tx: 0, ty: 0 }) : (zs.scale = 2.5); applyZoom(); });
        }
    })();

    /* ============================================================
       ADMIN: xoá sản phẩm (chân card) + "Lưu & Gửi kế hoạch" (dirty) + cảnh báo rời trang.
       ============================================================ */
    (function () {
        // Xoá sản phẩm khỏi kế hoạch.
        document.addEventListener('click', function (e) {
            var btn = e.target.closest && e.target.closest('.pf-del-product');
            if (!btn) return;
            e.stopPropagation();
            var li = btn.closest('.product-item');
            var planId = btn.getAttribute('data-plan-id');
            if (!li || !planId) return;
            if (!confirm('Xoá sản phẩm này khỏi kế hoạch?')) return;
            btn.disabled = true;
            postForm('ajax_delete_plan_product', { plan_id: planId }).then(function (res) {
                if (res && res.success) { if (li.parentNode) li.parentNode.removeChild(li); }
                else { btn.disabled = false; alert((res && res.message) || 'Xoá thất bại.'); }
            }).catch(function () { btn.disabled = false; alert('Lỗi mạng khi xoá.'); });
        });

        // "Lưu & Gửi kế hoạch" → đẩy chuông NV "KH đã thay đổi/bổ sung", xoá cờ dirty.
        var sendBtn = document.getElementById('pf-save-send');
        if (sendBtn) {
            sendBtn.addEventListener('click', function () {
                sendBtn.disabled = true;
                fetch(ACT + 'plan_notify_changed', {
                    method: 'POST', headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                    body: '', credentials: 'same-origin'
                }).then(function (r) { return r.json(); }).then(function (res) {
                    if (res && res.success) { pfClearDirty(); toast('Đã gửi cập nhật kế hoạch cho nhân viên.'); }
                    else { sendBtn.disabled = false; alert((res && res.message) || 'Gửi thất bại.'); }
                }).catch(function () { sendBtn.disabled = false; alert('Lỗi mạng khi gửi.'); });
            });
        }

        // Rời trang khi còn thay đổi chưa gửi → cảnh báo (popup native của trình duyệt).
        window.addEventListener('beforeunload', function (e) {
            if (pfDirty) { e.preventDefault(); e.returnValue = ''; return ''; }
        });
    })();

    /* ============================================================
       CHỈ XEM (view-only): khoá mọi control sửa cho user thường. Backend đã chặn các action ghi
       (permission_require_can_edit) — đây chỉ để giao diện gọn, không bày nút vô tác dụng. Danh
       sách sản phẩm do server render sẵn nên khoá 1 lần lúc tải là đủ (view-only không thêm được).
       ============================================================ */
    (function () {
        if (!window.PF_READONLY) return;
        function lock() {
            document.querySelectorAll(
                '.pf-qty-input, .info-field, .pkg-search, #pf-search-product, #other-work-input'
            ).forEach(function (el) { el.readOnly = true; el.tabIndex = -1; });
            document.querySelectorAll('.list-QC input[type="checkbox"]').forEach(function (el) {
                el.disabled = true;
            });
            document.querySelectorAll(
                '.pf-name-edit-btn, #btn-add-other-work, .task-delete, #btn-reset, .btn-reset, .wp-search'
            ).forEach(function (el) { el.style.display = 'none'; });
        }
        if (document.readyState === 'loading') {
            document.addEventListener('DOMContentLoaded', lock);
        } else {
            lock();
        }
    })();
})();
