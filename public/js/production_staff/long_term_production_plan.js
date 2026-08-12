/*
 * long_term_production_plan.js
 * Kế hoạch sản xuất dài ngày — 10 ngày (không tính hôm nay).
 *  - Thêm sản phẩm qua modal; "Việc khác" thêm/sửa tại chỗ.
 *  - Sản phẩm: tên & số lượng là input sửa tại chỗ (bấm tên -> đổi sản phẩm
 *    bằng gợi ý tìm kiếm); nút × để xóa.
 *  - "Việc khác": nội dung là input sửa tại chỗ; Enter -> dòng mới; bỏ trống +
 *    rời ô -> tự xóa; nút × để xóa.
 *  - Kéo card: tráo NỘI DUNG giữa 2 ngày. Kéo grip 1 dòng: chuyển dòng đó
 *    sang ngày khác (đúng nhóm sản phẩm / việc khác).
 *  - Khối "Đã hoàn thành" (ngày đã qua) thu/mở.
 *  - Kế hoạch SX DỰ PHÒNG: thêm/sửa/xóa dòng (tên = gợi ý SP, có ngày tạo),
 *    kéo sắp xếp trong list hoặc kéo lên 1 ngày để đưa vào kế hoạch; cài đặt
 *    thời gian tự xóa (1 tuần / 2 tuần / 1 tháng) tính từ ngày tạo.
 */
(function () {
    'use strict';

    var BASE = (window.LTP_CONFIG && window.LTP_CONFIG.baseUrl) ||
               '?mod=production_staff&controllers=production_staff&action=';

    var activeCard = null;   // .ltp-card đang thao tác (mở modal)
    var staged = [];         // sản phẩm tạm trong modal: {product_id, product_name, unit, qty}

    /* ---------------- helpers ---------------- */
    function esc(s) {
        return String(s == null ? '' : s)
            .replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;');
    }
    function post(action, data) {
        var body = new URLSearchParams();
        Object.keys(data).forEach(function (k) { body.append(k, data[k]); });
        return fetch(BASE + action, {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8' },
            body: body.toString()
        }).then(function (r) { return r.json(); });
    }
    function fmtQty(n) {
        n = Number(n) || 0;
        return n.toLocaleString('en-US', { maximumFractionDigits: 2 }).replace(/,/g, '');
    }
    // Xóa nhẹ nhàng: thêm class hiệu ứng rồi gỡ DOM sau khi chạy transition.
    function fadeRemove(el) {
        if (!el) return;
        el.classList.add('ltp-removing');
        setTimeout(function () { el.remove(); }, 220);
    }

    /* ---------------- modal ---------------- */
    function openModal(id) {
        var card = activeCard;
        var dateLabel = card ? card.querySelector('.ltp-day').textContent.trim() : '';
        var m = document.getElementById(id);
        m.querySelectorAll('.ltp-modal-date').forEach(function (el) { el.textContent = dateLabel; });
        m.classList.add('open');
    }
    function closeModal(id) { document.getElementById(id).classList.remove('open'); }

    document.addEventListener('click', function (e) {
        var c = e.target.closest && e.target.closest('.ltp-modal-close');
        if (c) { closeModal(c.getAttribute('data-close')); return; }
        if (e.target.classList && e.target.classList.contains('ltp-modal-mask')) {
            e.target.classList.remove('open');
        }
    });

    /* ---------------- open add-product / add-task ---------------- */
    document.addEventListener('click', function (e) {
        var btnP = e.target.closest && e.target.closest('.ltp-btn-add-product');
        if (btnP) {
            activeCard = btnP.closest('.ltp-card');
            staged = [];
            renderStaged();
            document.getElementById('ltp-product-search').value = '';
            document.getElementById('ltp-product-suggest').innerHTML = '';
            openModal('ltp-modal-product');
            return;
        }
        var btnT = e.target.closest && e.target.closest('.ltp-btn-add-task');
        if (btnT) {
            activeCard = btnT.closest('.ltp-card');
            document.getElementById('ltp-task-content').value = '';
            openModal('ltp-modal-task');
            return;
        }
    });

    /* ---------------- product search ---------------- */
    var searchTimer = null;
    var searchInput = document.getElementById('ltp-product-search');
    if (searchInput) {
        searchInput.addEventListener('input', function () {
            clearTimeout(searchTimer);
            var kw = this.value.trim();
            var box = document.getElementById('ltp-product-suggest');
            if (kw === '') { box.innerHTML = ''; return; }
            searchTimer = setTimeout(function () {
                post('search_product', { keyword: kw }).then(function (res) {
                    var data = (res && res.data) || [];
                    box.innerHTML = data.map(function (p) {
                        return '<li data-id="' + p.id + '" data-name="' + esc(p.product_name) + '">' +
                               esc(p.product_name) + '</li>';
                    }).join('');
                });
            }, 220);
        });
    }
    // Chọn 1 sản phẩm trong modal: thêm vào danh sách tạm, lấy SL sản xuất gần
    // nhất điền sẵn (rỗng nếu chưa từng sản xuất), rồi focus lại ô tìm để nhập tiếp.
    function selectModalProduct(pid, name) {
        var box = document.getElementById('ltp-product-suggest');
        var input = document.getElementById('ltp-product-search');
        if (!staged.some(function (s) { return s.product_id === pid; })) {
            var entry = { product_id: pid, product_name: name, qty: '' };
            staged.push(entry);
            renderStaged();
            // SL sản xuất gần nhất (finished_product_production_data) -> điền sẵn.
            post('get_product_data', { product_id: pid }).then(function (res) {
                var lq = (res && res.success && res.data) ? res.data.last_quantity : '';
                if (lq === '' || lq == null) return;
                if (entry.qty !== '') return; // user đã tự nhập -> không ghi đè
                entry.qty = String(lq);
                var rows = document.querySelectorAll('#ltp-staged .ltp-staged-row');
                Array.prototype.forEach.call(rows, function (row) {
                    if (staged[parseInt(row.getAttribute('data-i'), 10)] === entry) {
                        var q = row.querySelector('.ltp-staged-qty');
                        if (q && q.value === '') q.value = entry.qty;
                    }
                });
            });
        }
        if (input) input.value = '';
        if (box) box.innerHTML = '';
        if (input) input.focus();
    }
    function moveModalActive(dir) {
        var box = document.getElementById('ltp-product-suggest');
        var lis = box.querySelectorAll('li');
        if (!lis.length) return;
        var cur = box.querySelector('li.ltp-active');
        var idx = cur ? Array.prototype.indexOf.call(lis, cur) : -1;
        idx += dir;
        if (idx < 0) idx = lis.length - 1;
        if (idx >= lis.length) idx = 0;
        if (cur) cur.classList.remove('ltp-active');
        lis[idx].classList.add('ltp-active');
        lis[idx].scrollIntoView({ block: 'nearest' });
    }
    document.addEventListener('click', function (e) {
        var li = e.target.closest && e.target.closest('#ltp-product-suggest li');
        if (!li) return;
        selectModalProduct(parseInt(li.getAttribute('data-id'), 10), li.getAttribute('data-name'));
    });
    // rê chuột -> đồng bộ mục đang sáng với phím mũi tên
    var modalSuggestBox = document.getElementById('ltp-product-suggest');
    if (modalSuggestBox) {
        modalSuggestBox.addEventListener('mouseover', function (e) {
            var li = e.target.closest('li');
            if (!li) return;
            modalSuggestBox.querySelectorAll('li.ltp-active').forEach(function (x) { x.classList.remove('ltp-active'); });
            li.classList.add('ltp-active');
        });
    }
    // điều khiển bằng phím trong ô tìm của modal: ↑/↓ chọn, Enter/Tab xác nhận
    if (searchInput) {
        searchInput.addEventListener('keydown', function (e) {
            var box = document.getElementById('ltp-product-suggest');
            var has = box && box.querySelector('li');
            if (e.key === 'ArrowDown') { if (has) { e.preventDefault(); moveModalActive(1); } }
            else if (e.key === 'ArrowUp') { if (has) { e.preventDefault(); moveModalActive(-1); } }
            else if (e.key === 'Enter' || e.key === 'Tab') {
                if (!has) return;
                e.preventDefault();
                var li = box.querySelector('li.ltp-active') || box.querySelector('li');
                if (li) selectModalProduct(parseInt(li.getAttribute('data-id'), 10), li.getAttribute('data-name'));
            } else if (e.key === 'Escape') {
                if (box) box.innerHTML = '';
            }
        });
    }

    function renderStaged() {
        var box = document.getElementById('ltp-staged');
        if (!staged.length) {
            box.innerHTML = '<div class="ltp-staged-empty">Chưa chọn sản phẩm nào.</div>';
            return;
        }
        box.innerHTML = staged.map(function (s, i) {
            return '<div class="ltp-staged-row" data-i="' + i + '">' +
                   '<span class="ltp-staged-name">' + esc(s.product_name) + '</span>' +
                   '<input type="number" class="ltp-staged-qty" min="0" step="any" placeholder="SL" value="' + esc(s.qty) + '">' +
                   '<span class="ltp-staged-remove" title="Bỏ">&times;</span>' +
                   '</div>';
        }).join('');
    }
    document.addEventListener('input', function (e) {
        if (e.target.classList && e.target.classList.contains('ltp-staged-qty')) {
            var row = e.target.closest('.ltp-staged-row');
            staged[parseInt(row.getAttribute('data-i'), 10)].qty = e.target.value;
        }
    });
    document.addEventListener('click', function (e) {
        if (e.target.classList && e.target.classList.contains('ltp-staged-remove')) {
            var row = e.target.closest('.ltp-staged-row');
            staged.splice(parseInt(row.getAttribute('data-i'), 10), 1);
            renderStaged();
        }
    });

    /* ---------------- confirm add products ---------------- */
    var addProdBtn = document.getElementById('ltp-add-products-confirm');
    if (addProdBtn) {
        addProdBtn.addEventListener('click', function () {
            if (!activeCard) return;
            var date = activeCard.getAttribute('data-date');
            var list = activeCard.querySelector('.ltp-product-list');
            var toAdd = staged.filter(function (s) { return (parseFloat(s.qty) || 0) > 0; });
            if (!toAdd.length) { alert('Hãy nhập số lượng cho sản phẩm.'); return; }

            var chain = Promise.resolve();
            toAdd.forEach(function (s) {
                chain = chain.then(function () {
                    return post('ltp_add_product', { plan_date: date, product_id: s.product_id, quantity: s.qty })
                        .then(function (res) {
                            if (res && res.ok) list.insertAdjacentHTML('beforeend', itemHtml(res.item));
                        });
                });
            });
            chain.then(function () {
                staged = [];
                renderStaged();
                closeModal('ltp-modal-product');
            });
        });
    }

    function itemHtml(it) {
        var qty = esc(fmtQty(it.quantity));
        var name = esc(it.product_name);
        return '<li class="ltp-item" data-id="' + it.id + '" data-product-id="' + it.product_id +
               '" data-unit="' + esc(it.unit || '') + '">' +
               '<span class="ltp-item-grip" draggable="true" title="Kéo để chuyển ngày"><i class="fa-solid fa-grip-vertical"></i></span>' +
               '<input type="text" class="ltp-item-name" value="' + name + '" data-name="' + name + '" autocomplete="off" spellcheck="false" title="Bấm để đổi sản phẩm">' +
               '<input type="text" class="ltp-item-qty" value="' + qty + '" inputmode="decimal" title="Bấm để đổi số lượng">' +
               '<span class="ltp-item-del" title="Xóa">&times;</span>' +
               '</li>';
    }

    // Dòng sản phẩm NHÁP (chưa chọn sản phẩm): nhập tên -> chọn gợi ý sẽ tạo thật.
    function itemDraftHtml() {
        return '<li class="ltp-item ltp-item-draft" data-product-id="0">' +
               '<span class="ltp-item-grip" draggable="true" title="Kéo để chuyển ngày"><i class="fa-solid fa-grip-vertical"></i></span>' +
               '<input type="text" class="ltp-item-name ltp-prod-search" value="" data-name="" autocomplete="off" spellcheck="false" placeholder="Tên sản phẩm..." title="Nhập tên để chọn sản phẩm">' +
               '<input type="text" class="ltp-item-qty" value="" inputmode="decimal" title="Bấm để đổi số lượng">' +
               '<span class="ltp-item-del" title="Xóa">&times;</span>' +
               '</li>';
    }

    /* ---------------- confirm add task ---------------- */
    var addTaskBtn = document.getElementById('ltp-add-task-confirm');
    if (addTaskBtn) {
        addTaskBtn.addEventListener('click', function () {
            if (!activeCard) return;
            var date = activeCard.getAttribute('data-date');
            var content = document.getElementById('ltp-task-content').value.trim();
            if (content === '') { alert('Hãy nhập nội dung.'); return; }
            var list = activeCard.querySelector('.ltp-task-list');
            post('ltp_add_task', { plan_date: date, content: content }).then(function (res) {
                if (res && res.ok) {
                    list.insertAdjacentHTML('beforeend', taskHtml(res.task));
                    closeModal('ltp-modal-task');
                }
            });
        });
    }
    function taskHtml(tk) {
        var c = esc(tk.content);
        return '<li class="ltp-task" data-id="' + tk.id + '">' +
               '<span class="ltp-task-grip" draggable="true" title="Kéo để chuyển ngày"><i class="fa-solid fa-grip-vertical"></i></span>' +
               '<input type="text" class="ltp-task-content" value="' + c + '" data-content="' + c + '" placeholder="Nội dung việc..." title="Bấm để sửa">' +
               '<span class="ltp-task-del" title="Xóa">&times;</span>' +
               '</li>';
    }
    function taskDraftHtml() {
        return '<li class="ltp-task">' +
               '<span class="ltp-task-grip" draggable="true" title="Kéo để chuyển ngày"><i class="fa-solid fa-grip-vertical"></i></span>' +
               '<input type="text" class="ltp-task-content" value="" data-content="" placeholder="Nội dung việc..." title="Bấm để sửa">' +
               '<span class="ltp-task-del" title="Xóa">&times;</span>' +
               '</li>';
    }

    /* ---------------- "Việc khác": sửa tại chỗ + Enter ra dòng mới ----------------
     * - .ltp-task-content là input: bấm để sửa.
     * - Lưu khi rời ô; bỏ trống + rời ô -> tự xóa (draft chỉ gỡ DOM).
     * - Enter -> lưu dòng hiện tại + tạo dòng mới phía dưới để nhập tiếp. */

    function commitTask(li) {
        if (!li || li._ltpBusy) return Promise.resolve();
        var input = li.querySelector('.ltp-task-content');
        if (!input) return Promise.resolve();
        var v    = input.value.trim();
        var id   = li.getAttribute('data-id');
        var prev = input.getAttribute('data-content') || '';

        // Draft (chưa có id)
        if (!id) {
            if (v === '') { li.remove(); return Promise.resolve(); }
            var card = li.closest('.ltp-card');
            if (!card) { li.remove(); return Promise.resolve(); }
            li._ltpBusy = true;
            return post('ltp_add_task', { plan_date: card.getAttribute('data-date'), content: v }).then(function (res) {
                li._ltpBusy = false;
                if (res && res.ok) { li.setAttribute('data-id', res.task.id); input.setAttribute('data-content', v); }
            });
        }
        // Đã có id: trống -> xóa; có nội dung & đổi -> cập nhật
        if (v === '') {
            li._ltpBusy = true;
            return post('ltp_delete', { type: 'task', id: id }).then(function (res) {
                li._ltpBusy = false;
                if (res && res.ok) li.remove();
            });
        }
        if (v === prev) return Promise.resolve();
        li._ltpBusy = true;
        return post('ltp_update_task', { id: id, content: v }).then(function (res) {
            li._ltpBusy = false;
            if (res && res.ok) input.setAttribute('data-content', res.content);
        });
    }

    // .ltp-card cha có draggable="true" (tráo ngày) -> mặc định "nuốt" mất gesture kéo-chọn-text
    // bên trong input con (đã verify: -webkit-user-drag:none KHÔNG đủ để sửa). Tắt hẳn draggable
    // của card trong lúc đang sửa 1 "Việc khác" (focus vào .ltp-task-content), bật lại lúc rời ô.
    document.addEventListener('focusin', function (e) {
        if (!e.target.classList || !e.target.classList.contains('ltp-task-content')) return;
        var card = e.target.closest('.ltp-card');
        if (card) card.draggable = false;
    });
    document.addEventListener('focusout', function (e) {
        if (!e.target.classList || !e.target.classList.contains('ltp-task-content')) return;
        var card = e.target.closest('.ltp-card');
        if (card) card.draggable = true;
    });

    // xóa nhanh 1 "Việc khác" (nút ×)
    document.addEventListener('click', function (e) {
        var del = e.target.closest && e.target.closest('.ltp-task-del');
        if (!del) return;
        var li = del.closest('.ltp-task');
        if (!li) return;
        var id = li.getAttribute('data-id');
        if (!id) { fadeRemove(li); return; }
        post('ltp_delete', { type: 'task', id: id }).then(function (res) { if (res && res.ok) fadeRemove(li); });
    });

    /* ============================================================
     *  Sửa nhanh TÊN sản phẩm (đổi sản phẩm khác) & SỐ LƯỢNG (inline)
     * ============================================================ */

    // dropdown gợi ý dùng chung cho ô tên sản phẩm
    var inlineSuggest = document.createElement('ul');
    inlineSuggest.className = 'ltp-inline-suggest';
    inlineSuggest.style.display = 'none';
    document.body.appendChild(inlineSuggest);
    var nameTimer = null;
    var activeNameInput = null;

    function hideInlineSuggest() {
        inlineSuggest.style.display = 'none';
        inlineSuggest.innerHTML = '';
    }
    // Di chuyển mục đang chọn bằng phím mũi tên (cuộn theo nếu cần).
    function moveActiveSuggest(dir) {
        var lis = inlineSuggest.querySelectorAll('li');
        if (!lis.length) return;
        var cur = inlineSuggest.querySelector('li.ltp-active');
        var idx = cur ? Array.prototype.indexOf.call(lis, cur) : -1;
        idx += dir;
        if (idx < 0) idx = lis.length - 1;
        if (idx >= lis.length) idx = 0;
        if (cur) cur.classList.remove('ltp-active');
        lis[idx].classList.add('ltp-active');
        lis[idx].scrollIntoView({ block: 'nearest' });
    }
    function positionSuggest(input) {
        var r = input.getBoundingClientRect();
        inlineSuggest.style.left = (r.left + window.scrollX) + 'px';
        inlineSuggest.style.top  = (r.bottom + window.scrollY + 2) + 'px';
        inlineSuggest.style.minWidth = Math.max(r.width, 180) + 'px';
    }
    function fmtDate(s) {
        var p = String(s || '').slice(0, 10).split('-'); // [Y,M,D]
        return p.length === 3 ? (p[2] + '/' + p[1] + '/' + p[0]) : String(s || '');
    }

    /* Ô tên đang trong quá trình LƯU sản phẩm vừa chọn. Cần cờ này vì mousedown trên gợi ý
       chạy TRƯỚC focusout, nhưng việc lưu là bất đồng bộ — không có cờ thì handler focusout
       bên dưới sẽ trả ô về tên cũ ngay giữa lúc đang lưu. */
    var dangCommit = null;

    // chọn 1 gợi ý sản phẩm: phân nhánh theo ngữ cảnh (item ngày / dòng dự phòng)
    function selectSuggestion(input, pid, name) {
        dangCommit = input;
        if (input.closest('.ltp-backup-item')) commitBackupName(input, pid, name);
        else commitProduct(input, pid, name);
    }

    /* ---------------------------------------------------------------------------------
       GÕ TÊN MÀ KHÔNG CHỌN GỢI Ý = KHÔNG CÓ GÌ ĐƯỢC LƯU (vá 11/8/2026).
       Sản phẩm chỉ được ghi khi bấm/Enter vào một mục trong danh sách gợi ý — commitProduct()
       là nơi DUY NHẤT gửi product_id lên server. Nhưng ô tên là <input> nên chữ vừa gõ vẫn nằm
       đó, nhìn y như đã lưu xong; phải tới lúc F5 mới hiện lại tên THẬT trong CSDL.
       Đúng tình huống anh Sáu gặp: gõ "Đường đen bí đao" vào dòng vốn đang là sản phẩm khác,
       nhập số lượng (số lượng thì LƯU ĐƯỢC vì đi đường riêng), F5 xong tên quay về sản phẩm cũ.
       Nay rời ô mà chưa chọn gợi ý thì trả ngay về tên thật + nháy đỏ, để thấy liền là chưa ăn.
       --------------------------------------------------------------------------------- */
    document.addEventListener('focusout', function (e) {
        var t = e.target;
        if (!t || !t.classList || !t.classList.contains('ltp-item-name')) return;
        if (t.classList.contains('ltp-prod-search') && t.closest('.ltp-item-draft')) {
            // Dòng nháp: chưa từng có sản phẩm nào, để trống là đúng.
            t.value = '';
            return;
        }
        setTimeout(function () {
            if (dangCommit === t) return;               // đang lưu lựa chọn hợp lệ
            var thuc = t.getAttribute('data-name') || '';
            if (t.value === thuc) return;               // không đổi gì
            t.value = thuc;
            t.classList.add('ltp-name-revert');
            setTimeout(function () { t.classList.remove('ltp-name-revert'); }, 1000);
        }, 60);
    });

    // Lấy SL sản xuất gần nhất của sản phẩm điền vào ô .ltp-item-qty của dòng
    // (giống modal). Có id -> lưu luôn; rỗng (chưa từng SX) -> giữ nguyên ô.
    function applyLastQty(item, pid) {
        if (!item) return;
        var qtyInput = item.querySelector('.ltp-item-qty');
        if (!qtyInput) return;
        post('get_product_data', { product_id: pid }).then(function (res) {
            var lq = (res && res.success && res.data) ? res.data.last_quantity : '';
            if (lq === '' || lq == null) return;
            qtyInput.value = fmtQty(lq);
            var id = item.getAttribute('data-id');
            if (id) post('ltp_update_product', { id: id, quantity: lq });
        });
    }

    // Lấy SL sản xuất gần nhất của sản phẩm điền vào ô .ltp-bk-qty của dòng
    // dự phòng (giống logic ô số lượng item ngày). Có id -> lưu luôn.
    function applyBackupLastQty(bli, pid) {
        if (!bli) return;
        var qtyInput = bli.querySelector('.ltp-bk-qty');
        if (!qtyInput) return;
        post('get_product_data', { product_id: pid }).then(function (res) {
            var lq = (res && res.success && res.data) ? res.data.last_quantity : '';
            if (lq === '' || lq == null) return;
            qtyInput.value = fmtQty(lq);
            var id = bli.getAttribute('data-id');
            if (id) post('ltp_backup_update', { id: id, quantity: lq });
        });
    }

    function commitProduct(input, pid, name) {
        var item = input.closest('.ltp-item');
        if (!item) return;
        var qtyInput = item.querySelector('.ltp-item-qty');
        var qty = qtyInput ? qtyInput.value : '';
        var id  = item.getAttribute('data-id');

        // Dòng nháp (chưa có id): tạo mới trong ngày của card -> giữ lại dòng.
        if (!id) {
            var card = item.closest('.ltp-card');
            if (!card) { hideInlineSuggest(); return; }
            post('ltp_add_product', { plan_date: card.getAttribute('data-date'), product_id: pid, quantity: qty || 0 })
                .then(function (res) {
                    hideInlineSuggest();
                    if (dangCommit === input) dangCommit = null;
                    if (res && res.ok) {
                        item.setAttribute('data-id', res.item.id);
                        item.setAttribute('data-product-id', pid);
                        if (res.item.unit != null) item.setAttribute('data-unit', res.item.unit);
                        item.classList.remove('ltp-item-draft');
                        input.value = name;
                        input.setAttribute('data-name', name);
                        applyLastQty(item, pid); // điền SL sản xuất gần nhất
                        if (qtyInput) { qtyInput.focus(); qtyInput.select(); }
                    } else {
                        fadeRemove(item);
                    }
                });
            return;
        }
        // Dòng đã có: đổi sản phẩm + lấy SL sản xuất gần nhất cho ô số lượng.
        post('ltp_update_product', { id: id, quantity: qty, product_id: pid })
            .then(function (res) {
                if (dangCommit === input) dangCommit = null;
                if (res && res.ok) {
                    input.value = name;
                    input.setAttribute('data-name', name);
                    item.setAttribute('data-product-id', pid);
                    applyLastQty(item, pid);
                }
                hideInlineSuggest();
                input.blur();
            });
    }

    // Chọn sản phẩm cho 1 dòng DỰ PHÒNG: nếu là draft thì tạo mới (lấy ngày tạo),
    // nếu đã có thì cập nhật. Xong chuyển focus sang ô số lượng.
    function commitBackupName(input, pid, name) {
        var bli = input.closest('.ltp-backup-item');
        if (!bli) return;
        var qtyInput = bli.querySelector('.ltp-bk-qty');
        var id = bli.getAttribute('data-id');
        if (id) {
            post('ltp_backup_update', { id: id, quantity: qtyInput.value || 0, product_id: pid }).then(function (res) {
                if (res && res.ok) {
                    input.value = name; input.setAttribute('data-name', name); bli.setAttribute('data-product-id', pid);
                    applyBackupLastQty(bli, pid); // điền SL sản xuất gần nhất
                }
                hideInlineSuggest();
                qtyInput.focus(); qtyInput.select();
            });
        } else {
            post('ltp_backup_add', { product_id: pid, quantity: qtyInput.value || 0 }).then(function (res) {
                hideInlineSuggest();
                if (res && res.ok) {
                    bli.setAttribute('data-id', res.item.id);
                    bli.setAttribute('data-product-id', res.item.product_id);
                    input.value = res.item.product_name || name;
                    input.setAttribute('data-name', input.value);
                    var dateEl = bli.querySelector('.ltp-bk-date');
                    if (dateEl) dateEl.textContent = res.item.created_at ? 'Tạo: ' + fmtDate(res.item.created_at) : '';
                    if (res.item.quantity) qtyInput.value = fmtQty(res.item.quantity);
                    applyBackupLastQty(bli, pid); // điền SL sản xuất gần nhất
                    qtyInput.focus(); qtyInput.select();
                } else { bli.remove(); refreshBackupEmpty(); }
            });
        }
    }

    document.addEventListener('focusin', function (e) {
        if (e.target.classList && e.target.classList.contains('ltp-prod-search')) {
            activeNameInput = e.target;
            e.target.select();
        }
    });
    document.addEventListener('input', function (e) {
        if (!(e.target.classList && e.target.classList.contains('ltp-prod-search'))) return;
        var input = e.target;
        activeNameInput = input;
        clearTimeout(nameTimer);
        var kw = input.value.trim();
        if (kw === '') { hideInlineSuggest(); return; }
        nameTimer = setTimeout(function () {
            post('search_product', { keyword: kw }).then(function (res) {
                if (activeNameInput !== input) return;
                var data = (res && res.data) || [];
                if (!data.length) { hideInlineSuggest(); return; }
                inlineSuggest.innerHTML = data.map(function (p, i) {
                    /* Mục ĐẦU sáng sẵn: Enter/Tab vốn đã lấy "mục đang sáng HOẶC mục đầu", nhưng
                       trước đây không sáng gì cả nên người dùng không hề biết mình sắp chọn dòng
                       nào — gõ xong bấm Enter là dính sản phẩm khác mà không nhận ra. Sáng sẵn
                       thì hành vi vẫn y hệt, chỉ khác là NHÌN THẤY được trước khi bấm. */
                    return '<li data-id="' + p.id + '" data-name="' + esc(p.product_name) + '"' +
                           (i === 0 ? ' class="ltp-active"' : '') + '>' +
                           esc(p.product_name) + '</li>';
                }).join('');
                positionSuggest(input);
                inlineSuggest.style.display = 'block';
            });
        }, 220);
    });
    // chọn gợi ý: dùng mousedown để chạy trước sự kiện blur
    inlineSuggest.addEventListener('mousedown', function (e) {
        var li = e.target.closest('li');
        if (!li || !activeNameInput) return;
        e.preventDefault();
        selectSuggestion(activeNameInput, parseInt(li.getAttribute('data-id'), 10), li.getAttribute('data-name'));
    });
    // rê chuột -> đồng bộ mục đang chọn với phím mũi tên (chỉ 1 mục sáng)
    inlineSuggest.addEventListener('mouseover', function (e) {
        var li = e.target.closest('li');
        if (!li) return;
        inlineSuggest.querySelectorAll('li.ltp-active').forEach(function (x) { x.classList.remove('ltp-active'); });
        li.classList.add('ltp-active');
    });

    // Rời ô: ô tìm sản phẩm khôi phục tên đã lưu; "việc khác" & dòng dự phòng
    // tự lưu / tự xóa nếu trống.
    document.addEventListener('focusout', function (e) {
        var t = e.target;
        if (!t.classList) return;

        if (t.classList.contains('ltp-prod-search')) {
            setTimeout(function () {
                t.value = t.getAttribute('data-name') || '';
                if (activeNameInput === t) { hideInlineSuggest(); activeNameInput = null; }
                var bli = t.closest('.ltp-backup-item');
                if (bli && !bli.contains(document.activeElement)) handleBackupBlur(bli);
                // Dòng sản phẩm NHÁP chưa chọn SP + rời hẳn cụm -> biến mất nhẹ nhàng.
                var item = t.closest('.ltp-item');
                if (item && !item.getAttribute('data-id') && !item.contains(document.activeElement)) {
                    fadeRemove(item);
                }
            }, 150);
            return;
        }
        if (t.classList.contains('ltp-task-content')) {
            var li = t.closest('.ltp-task');
            setTimeout(function () {
                if (!li || li.contains(document.activeElement)) return;
                commitTask(li);
            }, 150);
            return;
        }
        if (t.classList.contains('ltp-bk-note-input')) {
            saveBackupNote(t);
            return;
        }
        if (t.classList.contains('ltp-bk-qty')) {
            var bli2 = t.closest('.ltp-backup-item');
            setTimeout(function () {
                if (!bli2 || bli2.contains(document.activeElement)) return;
                handleBackupBlur(bli2);
            }, 150);
        }
    });

    // phím tắt trong các ô nhập
    document.addEventListener('keydown', function (e) {
        var t = e.target;
        if (!t.classList) return;

        // ô tìm sản phẩm (item ngày & dòng dự phòng): ↑/↓ chọn, Enter xác nhận
        if (t.classList.contains('ltp-prod-search')) {
            var visible = inlineSuggest.style.display !== 'none';
            if (e.key === 'ArrowDown' && visible) {
                e.preventDefault(); moveActiveSuggest(1);
            } else if (e.key === 'ArrowUp' && visible) {
                e.preventDefault(); moveActiveSuggest(-1);
            } else if (e.key === 'Enter') {
                e.preventDefault();
                var li = visible ? (inlineSuggest.querySelector('li.ltp-active') || inlineSuggest.querySelector('li')) : null;
                if (li) selectSuggestion(t, parseInt(li.getAttribute('data-id'), 10), li.getAttribute('data-name'));
                else t.blur();
            } else if (e.key === 'Tab' && visible) {
                // Tab: chọn gợi ý đang sáng (hoặc gợi ý đầu) như Enter.
                var liTab = inlineSuggest.querySelector('li.ltp-active') || inlineSuggest.querySelector('li');
                if (liTab) {
                    e.preventDefault();
                    selectSuggestion(t, parseInt(liTab.getAttribute('data-id'), 10), liTab.getAttribute('data-name'));
                }
            } else if (e.key === 'Escape') {
                t.value = t.getAttribute('data-name') || '';
                hideInlineSuggest();
                t.blur();
            }
            return;
        }
        // số lượng item ngày: Enter -> lưu (blur) rồi chèn 1 dòng nháp phía dưới
        // để nhập sản phẩm tiếp; dòng nháp tự biến mất nếu rời ô mà chưa chọn SP.
        if (t.classList.contains('ltp-item-qty') && e.key === 'Enter') {
            e.preventDefault();
            var curItem = t.closest('.ltp-item');
            t.blur(); // kích hoạt 'change' -> lưu số lượng
            if (curItem && curItem.parentNode && curItem.parentNode.classList.contains('ltp-product-list')) {
                curItem.insertAdjacentHTML('afterend', itemDraftHtml());
                var nextItem = curItem.nextElementSibling;
                if (nextItem) {
                    var nameInput = nextItem.querySelector('.ltp-item-name');
                    if (nameInput) nameInput.focus();
                }
            }
            return;
        }
        // "Việc khác": Enter -> lưu rồi tạo dòng mới phía dưới để nhập tiếp
        if (t.classList.contains('ltp-task-content') && e.key === 'Enter') {
            e.preventDefault();
            var tli = t.closest('.ltp-task');
            if (t.value.trim() === '') { t.blur(); return; }
            commitTask(tli).then(function () {
                var list = tli.closest('.ltp-task-list');
                if (!list) return;
                tli.insertAdjacentHTML('afterend', taskDraftHtml());
                var nx = tli.nextElementSibling;
                if (nx) nx.querySelector('.ltp-task-content').focus();
            });
            return;
        }
        // ghi chú dòng dự phòng: Enter lưu (blur), Esc hủy (khôi phục)
        if (t.classList.contains('ltp-bk-note-input')) {
            if (e.key === 'Enter') { e.preventDefault(); t.blur(); }
            else if (e.key === 'Escape') { e.preventDefault(); t.value = t.getAttribute('data-note') || ''; t.blur(); }
            return;
        }
        // số lượng dòng dự phòng: Enter -> lưu rồi chèn 1 dòng dự phòng mới
        // ngay phía dưới (kế tiếp) để nhập sản phẩm tiếp.
        if (t.classList.contains('ltp-bk-qty') && e.key === 'Enter') {
            e.preventDefault();
            var curBli = t.closest('.ltp-backup-item');
            t.blur();
            addBackupDraftAfter(curBli);
            return;
        }
    });

    // lưu số lượng khi đổi (blur/Enter) — item ngày & dòng dự phòng; cài đặt TTL
    document.addEventListener('change', function (e) {
        var t = e.target;
        if (t.classList && t.classList.contains('ltp-item-qty')) {
            var item = t.closest('.ltp-item');
            if (!item) return;
            post('ltp_update_product', { id: item.getAttribute('data-id'), quantity: t.value })
                .then(function (res) { if (res && res.ok) t.value = fmtQty(t.value); });
            return;
        }
        if (t.classList && t.classList.contains('ltp-bk-qty')) {
            var bli = t.closest('.ltp-backup-item');
            if (!bli) return;
            var id = bli.getAttribute('data-id');
            if (!id) return; // chưa chọn sản phẩm -> chưa lưu
            post('ltp_backup_update', { id: id, quantity: t.value })
                .then(function (res) { if (res && res.ok) t.value = fmtQty(t.value); });
            return;
        }
        if (t.name === 'ltp-bk-ttl') {
            post('ltp_backup_set_ttl', { ttl: t.value });
        }
    });

    // xóa nhanh 1 sản phẩm (nút ×)
    document.addEventListener('click', function (e) {
        var del = e.target.closest && e.target.closest('.ltp-item-del');
        if (!del) return;
        var item = del.closest('.ltp-item');
        if (!item) return;
        if (!item.getAttribute('data-id')) { fadeRemove(item); return; } // dòng nháp
        post('ltp_delete', { type: 'product', id: item.getAttribute('data-id') })
            .then(function (res) { if (res && res.ok) fadeRemove(item); });
    });

    /* ============================================================
     *  KẾ HOẠCH SẢN XUẤT DỰ PHÒNG
     * ============================================================ */
    function refreshBackupEmpty() {
        var list = document.getElementById('ltp-backup-list');
        var empty = document.querySelector('#ltp-backup .ltp-backup-empty');
        if (!list || !empty) return;
        if (list.querySelector('.ltp-backup-item')) empty.setAttribute('hidden', '');
        else empty.removeAttribute('hidden');
    }
    function backupDraftHtml() {
        return '<li class="ltp-backup-item" data-product-id="0">' +
               '<span class="ltp-bk-grip" draggable="true" title="Kéo để sắp xếp / đưa lên ngày"><i class="fa-solid fa-grip-vertical"></i></span>' +
               '<div class="ltp-bk-main">' +
                 '<input type="text" class="ltp-bk-name ltp-prod-search" value="" data-name="" autocomplete="off" spellcheck="false" placeholder="Tên sản phẩm..." title="Bấm để đổi sản phẩm">' +
                 '<div class="ltp-bk-meta">' +
                   '<span class="ltp-bk-date"></span>' +
                   '<input type="text" class="ltp-bk-note-input" placeholder="Nhập ghi chú.." value="" data-note="">' +
                 '</div>' +
               '</div>' +
               '<input type="text" class="ltp-bk-qty" value="" inputmode="decimal" placeholder="SL" title="Bấm để đổi số lượng">' +
               '<span class="ltp-bk-del" title="Xóa">&times;</span>' +
               '</li>';
    }
    function addBackupDraft() {
        var list = document.getElementById('ltp-backup-list');
        if (!list) return;
        refreshBackupEmpty();
        var empty = document.querySelector('#ltp-backup .ltp-backup-empty');
        if (empty) empty.setAttribute('hidden', '');
        list.insertAdjacentHTML('beforeend', backupDraftHtml());
        var li = list.lastElementChild;
        if (li) li.querySelector('.ltp-bk-name').focus();
    }
    // Chèn 1 dòng dự phòng nháp ngay sau 1 dòng cho trước (Enter ở ô SL).
    function addBackupDraftAfter(bli) {
        var list = document.getElementById('ltp-backup-list');
        if (!list) return;
        if (!bli || bli.parentNode !== list) { addBackupDraft(); return; }
        var empty = document.querySelector('#ltp-backup .ltp-backup-empty');
        if (empty) empty.setAttribute('hidden', '');
        bli.insertAdjacentHTML('afterend', backupDraftHtml());
        var li = bli.nextElementSibling;
        if (li) li.querySelector('.ltp-bk-name').focus();
    }
    // Rời 1 dòng dự phòng: nếu chưa lưu (chưa chọn sản phẩm) -> bỏ.
    function handleBackupBlur(bli) {
        if (!bli) return;
        if (!bli.getAttribute('data-id')) { bli.remove(); refreshBackupEmpty(); }
    }

    /* ----- Ghi chú dòng dự phòng: ô input không viền, hover hiện viền để nhập ----- */
    function saveBackupNote(input) {
        if (!input) return;
        var bli = input.closest('.ltp-backup-item');
        var id  = bli ? bli.getAttribute('data-id') : '';
        var prev = input.getAttribute('data-note') || '';
        var val  = input.value.trim();
        if (val === prev) return;                 // không đổi
        if (!id) { input.value = prev; return; }  // chưa lưu (chưa chọn sản phẩm)
        post('ltp_backup_update_note', { id: id, note: val }).then(function (res) {
            if (res && res.ok) input.setAttribute('data-note', val);
            else input.value = prev;
        });
    }

    // nút "+ Sản phẩm" dự phòng
    var bkAddBtn = document.getElementById('ltp-backup-add');
    if (bkAddBtn) bkAddBtn.addEventListener('click', function () { addBackupDraft(); });

    // xóa 1 dòng dự phòng (nút ×)
    document.addEventListener('click', function (e) {
        var del = e.target.closest && e.target.closest('.ltp-bk-del');
        if (!del) return;
        var bli = del.closest('.ltp-backup-item');
        if (!bli) return;
        var id = bli.getAttribute('data-id');
        // Xóa không hỏi, cho biến mất nhẹ nhàng.
        if (!id) { fadeRemove(bli); setTimeout(refreshBackupEmpty, 240); return; }
        post('ltp_backup_delete', { id: id }).then(function (res) {
            if (res && res.ok) { fadeRemove(bli); setTimeout(refreshBackupEmpty, 240); }
        });
    });

    // menu cài đặt thời gian tự xóa
    var bkSetBtn  = document.getElementById('ltp-backup-setting-btn');
    var bkSetWrap = bkSetBtn ? bkSetBtn.closest('.ltp-backup-setting-wrap') : null;
    if (bkSetBtn) {
        bkSetBtn.addEventListener('click', function (e) {
            e.stopPropagation();
            bkSetWrap.classList.toggle('is-open');
        });
        document.addEventListener('click', function (e) {
            if (bkSetWrap && !e.target.closest('.ltp-backup-setting-wrap')) bkSetWrap.classList.remove('is-open');
        });
    }

    // giữ dropdown đúng vị trí khi cuộn / đổi cỡ
    window.addEventListener('scroll', function () {
        if (activeNameInput && inlineSuggest.style.display !== 'none') positionSuggest(activeNameInput);
    }, true);
    window.addEventListener('resize', function () {
        if (activeNameInput && inlineSuggest.style.display !== 'none') positionSuggest(activeNameInput);
    });

    /* ============================================================
     *  KÉO-THẢ
     *   - Kéo HEAD card  : tráo NỘI DUNG giữa 2 ngày (ngày giữ nguyên).
     *   - Kéo grip 1 dòng: chuyển riêng .ltp-item / .ltp-task sang ngày khác
     *     (sản phẩm về nhóm sản phẩm, "việc khác" về nhóm Việc khác).
     * ============================================================ */
    var dragSrc = null;       // .ltp-card đang kéo (tráo nội dung)
    var itemSrc = null;       // .ltp-item / .ltp-task đang kéo
    var itemSrcType = null;   // 'product' | 'task'

    document.addEventListener('dragstart', function (e) {
        // 1) kéo 1 dòng qua grip (item ngày / việc khác / dòng dự phòng)
        var grip = e.target.closest && e.target.closest('.ltp-item-grip, .ltp-task-grip, .ltp-bk-grip');
        if (grip) {
            var prod = grip.closest('.ltp-item');
            var bk   = grip.closest('.ltp-backup-item');
            itemSrc = prod || bk || grip.closest('.ltp-task');
            if (!itemSrc) return;
            itemSrcType = prod ? 'product' : (bk ? 'backup' : 'task');
            itemSrc.classList.add('ltp-item-dragging');
            e.dataTransfer.effectAllowed = 'move';
            try { e.dataTransfer.setData('text/plain', itemSrc.getAttribute('data-id') || ''); } catch (x) {}
            return;
        }
        // 2) kéo card (tráo nội dung 2 ngày)
        var card = e.target.closest && e.target.closest('.ltp-card');
        if (!card) return;
        if (e.target.closest('input, textarea, .ltp-edit-box')) { e.preventDefault(); return; }
        dragSrc = card;
        card.classList.add('ltp-dragging');
        e.dataTransfer.effectAllowed = 'move';
        try { e.dataTransfer.setData('text/plain', card.getAttribute('data-date')); } catch (x) {}
    });
    function clearBkMarks() {
        document.querySelectorAll('.ltp-bk-drop-before, .ltp-bk-drop-after').forEach(function (l) {
            l.classList.remove('ltp-bk-drop-before', 'ltp-bk-drop-after');
        });
        document.querySelectorAll('.ltp-cluster.ltp-products.ltp-bk-target').forEach(function (l) {
            l.classList.remove('ltp-bk-target');
        });
    }
    function clearTaskDropMarks() {
        document.querySelectorAll('.ltp-task.ltp-drop-before, .ltp-task.ltp-drop-after').forEach(function (l) {
            l.classList.remove('ltp-drop-before', 'ltp-drop-after');
        });
    }
    document.addEventListener('dragend', function () {
        if (dragSrc) dragSrc.classList.remove('ltp-dragging');
        if (itemSrc) itemSrc.classList.remove('ltp-item-dragging');
        document.querySelectorAll('.ltp-card.ltp-over').forEach(function (c) { c.classList.remove('ltp-over'); });
        document.querySelectorAll('.ltp-list.ltp-list-over').forEach(function (l) { l.classList.remove('ltp-list-over'); });
        clearBkMarks();
        clearTaskDropMarks();
        dragSrc = null; itemSrc = null; itemSrcType = null;
    });
    document.addEventListener('dragover', function (e) {
        // kéo 1 dòng dự phòng: sắp xếp trong list HOẶC đưa lên 1 ngày
        if (itemSrc && itemSrcType === 'backup') {
            var card = e.target.closest && e.target.closest('.ltp-card');
            if (card) {
                e.preventDefault(); e.dataTransfer.dropEffect = 'move';
                clearBkMarks();
                var cluster = card.querySelector('.ltp-cluster.ltp-products');
                if (cluster) cluster.classList.add('ltp-bk-target');
                return;
            }
            var overItem = e.target.closest && e.target.closest('.ltp-backup-item');
            var inList   = e.target.closest && e.target.closest('#ltp-backup-list');
            if (overItem || inList) {
                e.preventDefault(); e.dataTransfer.dropEffect = 'move';
                clearBkMarks();
                if (overItem && overItem !== itemSrc) {
                    var rect = overItem.getBoundingClientRect();
                    var after = (e.clientY - rect.top) > rect.height / 2;
                    overItem.classList.add(after ? 'ltp-bk-drop-after' : 'ltp-bk-drop-before');
                }
            }
            return;
        }
        // kéo 1 dòng (item ngày / việc khác): tô sáng list đích phù hợp theo loại
        if (itemSrc) {
            var c = e.target.closest && e.target.closest('.ltp-card');
            if (!c) return;
            e.preventDefault();
            e.dataTransfer.dropEffect = 'move';
            var list = c.querySelector(itemSrcType === 'task' ? '.ltp-task-list' : '.ltp-product-list');
            document.querySelectorAll('.ltp-list.ltp-list-over').forEach(function (l) {
                if (l !== list) l.classList.remove('ltp-list-over');
            });
            if (list) list.classList.add('ltp-list-over');
            // Việc khác: đang hover ngay 1 dòng khác TRONG CÙNG ngày -> hiện vạch chèn
            // trước/sau để sắp xếp lại thứ tự (khác với kéo sang thẻ ngày khác).
            if (itemSrcType === 'task') {
                clearTaskDropMarks();
                var overTask = e.target.closest && e.target.closest('.ltp-task');
                if (overTask && overTask !== itemSrc && overTask.parentNode === itemSrc.parentNode) {
                    var tRect = overTask.getBoundingClientRect();
                    var tAfter = (e.clientY - tRect.top) > tRect.height / 2;
                    overTask.classList.add(tAfter ? 'ltp-drop-after' : 'ltp-drop-before');
                }
            }
            return;
        }
        // kéo card
        var card = e.target.closest && e.target.closest('.ltp-card');
        if (!card || !dragSrc) return;
        e.preventDefault();
        e.dataTransfer.dropEffect = 'move';
        if (card !== dragSrc) card.classList.add('ltp-over');
    });
    document.addEventListener('dragleave', function (e) {
        if (itemSrc) return;
        var card = e.target.closest && e.target.closest('.ltp-card');
        if (card) card.classList.remove('ltp-over');
    });
    document.addEventListener('drop', function (e) {
        // 0) thả 1 dòng DỰ PHÒNG: đưa lên 1 ngày HOẶC sắp xếp lại trong list
        if (itemSrc && itemSrcType === 'backup') {
            var card = e.target.closest && e.target.closest('.ltp-card');
            if (card) {
                e.preventDefault(); clearBkMarks();
                var bkId = itemSrc.getAttribute('data-id');
                if (!bkId) return; // draft chưa lưu
                var srcLi = itemSrc;
                post('ltp_backup_to_day', { id: bkId, plan_date: card.getAttribute('data-date') }).then(function (res) {
                    if (res && res.ok) {
                        var plist = card.querySelector('.ltp-product-list');
                        if (plist) plist.insertAdjacentHTML('beforeend', itemHtml(res.item));
                        srcLi.remove();
                        refreshBackupEmpty();
                    }
                });
                return;
            }
            // sắp xếp trong danh sách dự phòng
            e.preventDefault();
            var bkList  = document.getElementById('ltp-backup-list');
            var overBk  = e.target.closest && e.target.closest('.ltp-backup-item');
            if (overBk && overBk !== itemSrc) {
                var rect2 = overBk.getBoundingClientRect();
                var after2 = (e.clientY - rect2.top) > rect2.height / 2;
                overBk.parentNode.insertBefore(itemSrc, after2 ? overBk.nextSibling : overBk);
            } else if (bkList && !overBk) {
                bkList.appendChild(itemSrc);
            }
            clearBkMarks();
            if (bkList) {
                var ids = Array.prototype.map.call(bkList.querySelectorAll('.ltp-backup-item'), function (li) {
                    return li.getAttribute('data-id');
                }).filter(Boolean);
                post('ltp_backup_reorder', { ids: JSON.stringify(ids) });
            }
            return;
        }
        // 1) thả 1 dòng (item ngày / việc khác) sang ngày khác — HOẶC (riêng "việc khác")
        //    sắp xếp lại vị trí trong CÙNG 1 ngày nếu thả ngay lên 1 dòng khác trong cùng list.
        if (itemSrc) {
            var c = e.target.closest && e.target.closest('.ltp-card');
            if (!c) return;
            e.preventDefault();
            var list = c.querySelector(itemSrcType === 'task' ? '.ltp-task-list' : '.ltp-product-list');
            if (!list) return;
            list.classList.remove('ltp-list-over');
            if (itemSrcType === 'task' && itemSrc.parentNode === list) {
                var overTask2 = e.target.closest && e.target.closest('.ltp-task');
                clearTaskDropMarks();
                if (overTask2 && overTask2 !== itemSrc) {
                    var tRect2 = overTask2.getBoundingClientRect();
                    var tAfter2 = (e.clientY - tRect2.top) > tRect2.height / 2;
                    overTask2.parentNode.insertBefore(itemSrc, tAfter2 ? overTask2.nextSibling : overTask2);
                    var ids = Array.prototype.map.call(list.querySelectorAll('.ltp-task'), function (li) {
                        return li.getAttribute('data-id');
                    }).filter(Boolean);
                    post('ltp_task_reorder', { ids: JSON.stringify(ids) });
                }
                return;
            }
            if (itemSrc.parentNode !== list) {
                list.appendChild(itemSrc);
                post('ltp_move_item', {
                    type: itemSrcType,
                    id: itemSrc.getAttribute('data-id'),
                    plan_date: c.getAttribute('data-date')
                });
            }
            return;
        }
        // 2) tráo nội dung 2 ngày (kéo card)
        var target = e.target.closest && e.target.closest('.ltp-card');
        if (!target || !dragSrc || target === dragSrc) return;
        e.preventDefault();
        target.classList.remove('ltp-over');

        var dateA = dragSrc.getAttribute('data-date');
        var dateB = target.getAttribute('data-date');

        // tráo NỘI DUNG (card-body), ngày (head) giữ nguyên
        var bodyA = dragSrc.querySelector('.ltp-card-body');
        var bodyB = target.querySelector('.ltp-card-body');
        var tmp = bodyA.innerHTML;
        bodyA.innerHTML = bodyB.innerHTML;
        bodyB.innerHTML = tmp;

        post('ltp_swap_days', { date_a: dateA, date_b: dateB });
    });

    /* ---------------- xóa ngày (nút 'x') ----------------
     * - Card đầu/cuối: xóa hẳn card (mode 'removed') -> fade rồi reload để lịch
     *   canh đúng cột.
     * - Card giữa: chỉ xóa nội dung (mode 'cleared') -> giữ card, dọn body tại chỗ. */
    document.addEventListener('click', function (e) {
        var x = e.target.closest && e.target.closest('.ltp-card-del');
        if (!x) return;
        var card = x.closest('.ltp-card');
        if (!card) return;
        var date = card.getAttribute('data-date');
        post('ltp_remove_day', { plan_date: date }).then(function (res) {
            if (!res || !res.ok) return;
            if (res.mode === 'removed') {
                card.classList.add('ltp-removing');
                setTimeout(function () { location.reload(); }, 260);
            } else {
                var pl = card.querySelector('.ltp-product-list');
                var tl = card.querySelector('.ltp-task-list');
                if (pl) pl.innerHTML = '';
                if (tl) tl.innerHTML = '';
            }
        });
    });

    /* ---------------- XUẤT KH: đẩy kế hoạch 1 ngày (đã qua) sang trang Lập KHSX ----------------
     * Gom sản phẩm + việc khác của card rồi gọi save_plan (ghi vào production_plans +
     * additional_tasks) — đúng nguồn dữ liệu mà 'plan_for_staff' đọc. */
    document.addEventListener('click', function (e) {
        var btn = e.target.closest && e.target.closest('.ltp-export-plan');
        if (!btn) return;
        var card = btn.closest('.ltp-card');
        if (!card) return;

        var items = [];
        card.querySelectorAll('.ltp-product-list .ltp-item').forEach(function (li) {
            var pid = parseInt(li.getAttribute('data-product-id'), 10);
            if (!pid) return; // bỏ dòng nháp chưa chọn sản phẩm
            var qEl = li.querySelector('.ltp-item-qty');
            var qty = qEl ? (parseFloat(String(qEl.value).replace(/,/g, '')) || 0) : 0;
            items.push({ product_id: pid, quantity: qty });
        });
        var tasks = [];
        card.querySelectorAll('.ltp-task-list .ltp-task').forEach(function (li) {
            var c = li.querySelector('.ltp-task-content');
            var v = c ? c.value.trim() : '';
            if (v) tasks.push(v);
        });

        if (!items.length) { alert('Ngày này chưa có sản phẩm để xuất.'); return; }
        // Không hỏi xác nhận — đẩy thẳng sang plan_for_staff.

        btn.disabled = true;
        post('save_plan', { items: JSON.stringify(items), tasks: JSON.stringify(tasks), force_clear: 1 })
            .then(function (res) {
                if (res && res.success) {
                    window.location.href = BASE + 'plan_for_staff';
                } else {
                    btn.disabled = false;
                    alert((res && res.message) || 'Xuất kế hoạch thất bại.');
                }
            })
            .catch(function () { btn.disabled = false; alert('Lỗi mạng khi xuất kế hoạch.'); });
    });

    /* ---------------- "Thêm ngày" (chèn ngày kế tiếp ở cuối) ---------------- */
    var WEEKDAYS = ['Chủ nhật', 'Thứ hai', 'Thứ ba', 'Thứ tư', 'Thứ năm', 'Thứ sáu', 'Thứ bảy'];
    function ymd(d) {
        return d.getFullYear() + '-' +
               String(d.getMonth() + 1).padStart(2, '0') + '-' +
               String(d.getDate()).padStart(2, '0');
    }
    function buildCardHtml(dateStr, weekday, display, isSun) {
        var p = dateStr.split('-');
        var dt = new Date(+p[0], +p[1] - 1, +p[2]);
        var wd = weekday || WEEKDAYS[dt.getDay()];
        var disp = display || (dt.getDate() + '/' + (dt.getMonth() + 1) + '/' + dt.getFullYear());
        if (typeof isSun === 'undefined') isSun = (dt.getDay() === 0);
        return '<div class="ltp-card' + (isSun ? ' is-sunday' : '') + '" draggable="true" data-date="' + dateStr + '">' +
               '<div class="ltp-card-head">' +
                 '<span class="ltp-card-del" title="Xóa ngày (đầu/cuối: xóa card; giữa: xóa nội dung)">&times;</span>' +
                 '<div class="ltp-day"><span class="wd">' + wd + '</span> <span class="dt">(' + disp + ')</span></div>' +
                 '<div class="ltp-card-actions">' +
                   '<button type="button" class="ltp-btn ltp-btn-add-product">+ Sản phẩm</button>' +
                   '<button type="button" class="ltp-btn ltp-btn-add-task">Việc khác</button>' +
                 '</div>' +
               '</div>' +
               '<div class="ltp-card-body">' +
                 '<div class="ltp-cluster ltp-products"><ul class="ltp-list ltp-product-list"></ul></div>' +
                 '<div class="ltp-cluster ltp-tasks"><div class="ltp-cluster-title">Việc khác:</div>' +
                 '<ul class="ltp-list ltp-task-list"></ul></div>' +
               '</div>' +
               '<div class="ltp-card-foot">' +
                 '<button type="button" class="ltp-btn ltp-export-plan" title="Xuất kế hoạch ngày này sang trang BC KHSX cho nhân viên">' +
                   '<i class="fa-solid fa-paper-plane"></i> XUẤT KH</button>' +
               '</div>' +
               '</div>';
    }
    var addDayBtn = document.getElementById('ltp-add-day');
    if (addDayBtn) {
        addDayBtn.addEventListener('click', function () {
            addDayBtn.disabled = true;
            // Reload để gom nhóm tuần (FAQ) lại cho đúng sau khi thêm ngày.
            post('ltp_add_day', {}).then(function (res) {
                if (res && res.ok) location.reload();
                else addDayBtn.disabled = false;
            });
        });
    }

    /* ---------------- FAQ tuần: bấm tiêu đề để thu gọn / mở các card của tuần ---------------- */
    document.addEventListener('click', function (e) {
        var head = e.target.closest && e.target.closest('.ltp-week-head');
        if (!head) return;
        var wk = head.getAttribute('data-week');
        var collapsed = head.classList.toggle('is-collapsed');
        document.querySelectorAll('.ltp-card[data-week="' + wk + '"]').forEach(function (c) {
            c.classList.toggle('ltp-week-hidden', collapsed);
        });
    });

    // Thêm ngày vào ĐẦU board: card mới thành card đầu -> reload để lịch canh đúng cột.
    var addDayFirstBtn = document.getElementById('ltp-add-day-first');
    if (addDayFirstBtn) {
        addDayFirstBtn.addEventListener('click', function () {
            addDayFirstBtn.disabled = true;
            post('ltp_add_day_first', {}).then(function (res) {
                if (res && res.ok) location.reload();
                else addDayFirstBtn.disabled = false;
            });
        });
    }

    /* ---------------- completed toggle ---------------- */
    document.addEventListener('click', function (e) {
        var t = e.target.closest && e.target.closest('.ltp-completed-toggle');
        if (!t) return;
        var body = document.querySelector('#ltp-completed .ltp-completed-body');
        var icon = t.querySelector('i');
        if (body.hasAttribute('hidden')) { body.removeAttribute('hidden'); if (icon) icon.style.transform = 'rotate(90deg)'; }
        else { body.setAttribute('hidden', ''); if (icon) icon.style.transform = ''; }
    });

    /* ---------------- TASK 5: Xóa (reset) danh sách đã hoàn thành ---------------- */
    document.addEventListener('click', function (e) {
        var btn = e.target.closest && e.target.closest('#ltp-completed-clear');
        if (!btn) return;
        e.preventDefault();
        if (!confirm('Xóa toàn bộ danh sách "Đã hoàn thành"? Thao tác này không thể hoàn tác.')) return;
        btn.disabled = true;
        post('ltp_clear_completed', {}).then(function (res) {
            btn.disabled = false;
            if (!res || !res.ok) { alert((res && res.message) || 'Xóa không thành công.'); return; }
            var body = document.querySelector('#ltp-completed .ltp-completed-body');
            if (body) body.innerHTML = '<p class="ltp-empty">Chưa có ngày nào hoàn thành.</p>';
            var cnt = document.querySelector('#ltp-completed .ltp-completed-count');
            if (cnt) cnt.textContent = '0';
        }).catch(function () {
            btn.disabled = false;
            alert('Lỗi mạng khi xóa danh sách đã hoàn thành.');
        });
    });

    /* ============================================================
     *  XEM NHANH TỒN KHO (NVL / Thành phẩm) — thanh công cụ
     *   - Bấm "Xem tồn NVL" / "Xem tồn thành phẩm" -> hiện ô nhập tên,
     *     gõ keyword -> xổ dropdown 'Tên - tồn kho' (vd: 'Bột cacao - 50 kg').
     *   - Tồn NVL: material_inventory.quantity; tồn TP: finished_goods_inventory.quantity.
     * ============================================================ */
    (function () {
        var wrap    = document.getElementById('ltp-stock-lookup');
        var input   = document.getElementById('ltp-stock-input');
        var suggest = document.getElementById('ltp-stock-suggest');
        var btnMat  = document.getElementById('ltp-stock-material');
        var btnProd = document.getElementById('ltp-stock-product');
        if (!wrap || !input || !suggest) return;

        var mode  = null;   // 'material' | 'product'
        var timer = null;

        function hideSuggest() { suggest.innerHTML = ''; suggest.style.display = 'none'; }
        function openLookup(m, placeholder) {
            mode = m;
            wrap.classList.add('is-open');
            input.placeholder = placeholder;
            input.value = '';
            hideSuggest();
            input.focus();
        }

        if (btnMat)  btnMat.addEventListener('click',  function () { openLookup('material', 'Nhập tên nguyên vật liệu..'); });
        if (btnProd) btnProd.addEventListener('click', function () { openLookup('product',  'Nhập tên thành phẩm..'); });

        input.addEventListener('input', function () {
            clearTimeout(timer);
            var kw = input.value.trim();
            if (kw === '' || !mode) { hideSuggest(); return; }
            var action = mode === 'material' ? 'ltp_material_stock_search' : 'ltp_product_stock_search';
            timer = setTimeout(function () {
                post(action, { keyword: kw }).then(function (res) {
                    var data = (res && res.data) || [];
                    if (!data.length) {
                        suggest.innerHTML = '<li class="ltp-stock-empty">Không tìm thấy.</li>';
                        suggest.style.display = 'block';
                        return;
                    }
                    suggest.innerHTML = data.map(function (r) {
                        var label = esc(r.name) + ' - ' + esc(fmtQty(r.quantity)) +
                                    (r.unit ? ' ' + esc(r.unit) : '');
                        return '<li data-label="' + label + '">' + label + '</li>';
                    }).join('');
                    suggest.style.display = 'block';
                });
            }, 220);
        });

        suggest.addEventListener('click', function (e) {
            var li = e.target.closest('li');
            if (!li || li.classList.contains('ltp-stock-empty')) return;
            input.value = li.getAttribute('data-label') || li.textContent;
            hideSuggest();
        });

        // Bấm ra ngoài vùng tra cứu -> đóng dropdown; nếu ô đang trống thì ẩn luôn ô nhập
        document.addEventListener('click', function (e) {
            if (!e.target.closest('#ltp-stock-lookup') &&
                e.target !== btnMat && e.target !== btnProd) {
                hideSuggest();
                if (input.value.trim() === '') { wrap.classList.remove('is-open'); mode = null; }
            }
        });
    })();

})();

/* Modal "xem kế hoạch 1 ngày" trên mobile đã tách sang public/js/shared/day_plan_modal.js
   (cặp với layout/day-plan-modal.php) để view KHSX dự kiến của Đặt hàng nhà máy dùng chung. */
