/* =====================================================================================
   QUẢN LÝ ĐƠN HÀNG — view "Đơn hàng".
   Gồm 4 mảng: phân trang client-side, ô hóa đơn (chọn tệp / kéo thả / Ctrl+V),
   lightbox xem hóa đơn (xoay - zoom - tải - gửi chat - thêm - xóa), modal chi tiết đơn.

   VÌ SAO KHÔNG tái dùng row_invoice_cell.js / InvoiceViewer dùng chung: các endpoint hóa
   đơn của admin_factory KHÔNG kiểm quyền dòng nào (guard của router fail-open với action
   ngoài tbl_views), trỏ vào đó là khách xóa được hóa đơn nhà máy và đọc được đơn của khách
   khác. Viewer dùng chung cũng thiếu xoay trái / tải xuống / share / thêm / xóa / prev-next.
   ===================================================================================== */
(function () {
    'use strict';

    var CFG  = window.CO || { base: '?mod=customer_orders&controllers=customer_orders&action=', perPage: 25, isAdmin: false, me: 0 };
    var BASE = CFG.base;

    function $(sel, root) { return (root || document).querySelector(sel); }
    function $all(sel, root) { return Array.prototype.slice.call((root || document).querySelectorAll(sel)); }
    function esc(s) {
        return String(s == null ? '' : s)
            .replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;').replace(/'/g, '&#39;');
    }
    function money(n) { return (Number(n) || 0).toLocaleString('vi-VN', { maximumFractionDigits: 0 }) + ' đ'; }
    function num(n, d) { return (Number(n) || 0).toLocaleString('vi-VN', { maximumFractionDigits: d == null ? 2 : d }); }
    /** Bỏ dấu để lọc danh bạ không phụ thuộc dấu tiếng Việt. */
    function noAccent(s) {
        // Dùng lớp Unicode \p{Diacritic} (regex thuần ASCII) thay vì gõ dải ký tự dấu tổ hợp
        // trực tiếp vào regex: ký tự thô rất dễ hỏng khi file đi qua công cụ đổi encoding, mà
        // hỏng thì bộ lọc sai im lặng chứ không báo lỗi.
        return String(s || '').toLowerCase().normalize('NFD')
            .replace(/\p{Diacritic}/gu, '').replace(/đ/g, 'd');
    }
    function post(action, data) {
        return fetch(BASE + action, { method: 'POST', body: data, credentials: 'same-origin' })
            .then(function (r) { return r.json(); });
    }
    function form(obj) {
        var fd = new FormData();
        Object.keys(obj).forEach(function (k) { fd.append(k, obj[k]); });
        return fd;
    }

    /* =================================================================================
       1) PHÂN TRANG CLIENT-SIDE
       Dữ liệu đã lọc xong ở server; ở đây chỉ ẩn/hiện dòng nên đổi trang tức thì.
       ================================================================================= */
    var rows = $all('#co-table tbody tr.co-row');
    var per  = parseInt(CFG.perPage, 10) || 25;
    var page = 1;

    function totalPages() { return Math.max(1, Math.ceil(rows.length / per)); }

    function renderPage() {
        var tp = totalPages();
        if (page > tp) page = tp;
        rows.forEach(function (tr, i) {
            var inPage = i >= (page - 1) * per && i < page * per;
            tr.classList.toggle('is-hidden', !inPage);
        });

        var box = $('#co-pagination');
        if (!box) return;
        if (tp <= 1) { box.innerHTML = ''; return; }

        var html = '<button type="button" data-go="prev"' + (page === 1 ? ' disabled' : '') + '>‹</button>';
        for (var p = 1; p <= tp; p++) {
            // Rút gọn khi nhiều trang: luôn giữ trang đầu/cuối và vùng quanh trang hiện tại.
            if (tp > 7 && p > 2 && p < tp - 1 && Math.abs(p - page) > 1) {
                if (p === 3 || p === tp - 2) html += '<button type="button" disabled>…</button>';
                continue;
            }
            html += '<button type="button" data-go="' + p + '"' + (p === page ? ' class="active"' : '') + '>' + p + '</button>';
        }
        html += '<button type="button" data-go="next"' + (page === tp ? ' disabled' : '') + '>›</button>';
        box.innerHTML = html;
    }

    document.addEventListener('click', function (e) {
        var b = e.target.closest('#co-pagination button[data-go]');
        if (!b || b.disabled) return;
        var go = b.getAttribute('data-go');
        if (go === 'prev') page = Math.max(1, page - 1);
        else if (go === 'next') page = Math.min(totalPages(), page + 1);
        else page = parseInt(go, 10) || 1;
        renderPage();
        var tbl = $('#co-table');
        if (tbl) tbl.scrollIntoView({ behavior: 'smooth', block: 'start' });
    });

    renderPage();

    /* =================================================================================
       2) Ô HÓA ĐƠN — chọn tệp / kéo thả / Ctrl+V
       ================================================================================= */

    /** Vẽ lại ruột 1 ô hóa đơn từ danh sách file server trả về. */
    function renderCell(cell, files) {
        var canDel, html = '';
        (files || []).forEach(function (f) {
            var laCuaToi = String(f.upload_source) === 'customer' && parseInt(f.uploaded_by, 10) === parseInt(CFG.me, 10);
            canDel = CFG.isAdmin || laCuaToi;
            html += '<span class="co-thumb" data-inv-id="' + parseInt(f.id, 10) + '"'
                  + ' data-src="' + esc(f.file_url) + '"'
                  + ' data-can-delete="' + (canDel ? '1' : '0') + '"'
                  + ' title="' + (String(f.upload_source) === 'customer' ? 'Bạn tải lên' : 'Nhà máy tải lên') + '">'
                  + '<img src="' + esc(f.file_url) + '" alt="Hóa đơn" loading="lazy"></span>';
        });
        var thumbs = cell.querySelector('.co-inv-thumbs');
        if (thumbs) thumbs.innerHTML = html;
        // Tích xanh phải cập nhật ở ĐÂY nữa, không chỉ lúc PHP render lần đầu.
        var chk = cell.querySelector('.co-inv-check');
        if (chk) chk.classList.toggle('is-on', !!(files && files.length));
    }

    function uploadTo(cell, fileList) {
        if (!fileList || !fileList.length) return;
        var fd = new FormData();
        fd.append('inv_type', cell.getAttribute('data-inv-type'));
        fd.append('id', cell.getAttribute('data-id'));
        Array.prototype.forEach.call(fileList, function (f) { fd.append('files[]', f); });

        cell.classList.add('is-busy');
        post('invoice_upload', fd)
            .then(function (res) {
                if (res && res.ok) {
                    renderCell(cell, res.files);
                    if (res.errors && res.errors.length) alert(res.errors.join('\n'));
                } else {
                    alert((res && res.message) || 'Tải hóa đơn thất bại.');
                }
            })
            .catch(function () { alert('Lỗi mạng khi tải hóa đơn.'); })
            .then(function () { cell.classList.remove('is-busy'); });
    }

    // Nút "+" -> mở hộp chọn tệp.
    document.addEventListener('click', function (e) {
        var btn = e.target.closest('.co-inv-add');
        if (!btn) return;
        e.stopPropagation();                       // không mở modal chi tiết của dòng
        var cell = btn.closest('.co-inv-cell');
        var inp  = cell && cell.querySelector('.co-inv-file');
        if (inp) inp.click();
    });
    document.addEventListener('change', function (e) {
        var inp = e.target.closest('.co-inv-file');
        if (!inp) return;
        uploadTo(inp.closest('.co-inv-cell'), inp.files);
        inp.value = '';                            // chọn lại đúng tệp đó vẫn kích hoạt change
    });

    // Kéo tệp từ folder máy tính thả vào ô.
    ['dragenter', 'dragover'].forEach(function (ev) {
        document.addEventListener(ev, function (e) {
            var cell = e.target.closest && e.target.closest('.co-inv-cell');
            if (!cell) return;
            e.preventDefault();
            cell.classList.add('is-drag');
        });
    });
    ['dragleave', 'drop'].forEach(function (ev) {
        document.addEventListener(ev, function (e) {
            var cell = e.target.closest && e.target.closest('.co-inv-cell');
            if (!cell) return;
            if (ev === 'drop') { e.preventDefault(); uploadTo(cell, e.dataTransfer && e.dataTransfer.files); }
            cell.classList.remove('is-drag');
        });
    });

    /* Ctrl+V dán ảnh: dán vào ô đang được trỏ chuột/vừa bấm. Trình duyệt không gắn sự kiện
       paste vào ô cụ thể nên phải tự nhớ ô "đang nhắm". */
    var cellDangNham = null;
    document.addEventListener('mouseover', function (e) {
        var cell = e.target.closest && e.target.closest('.co-inv-cell');
        if (cell) cellDangNham = cell;
    });
    document.addEventListener('paste', function (e) {
        if (!cellDangNham || !e.clipboardData) return;
        var files = [];
        Array.prototype.forEach.call(e.clipboardData.items || [], function (it) {
            if (it.kind === 'file') { var f = it.getAsFile(); if (f) files.push(f); }
        });
        if (!files.length) return;
        e.preventDefault();
        uploadTo(cellDangNham, files);
    });

    /* =================================================================================
       3) LIGHTBOX XEM HÓA ĐƠN
       ================================================================================= */
    var viewer  = $('#co-viewer');
    var vImg    = $('#co-viewer-img');
    var vStage  = $('#co-viewer-stage');
    var vPos    = $('#co-viewer-pos');
    var vCell   = null;    // ô đang xem
    var vList   = [];      // [{id, src, canDelete}]
    var vIdx    = 0;
    var vRot    = 0, vZoom = 1, vX = 0, vY = 0;

    function applyTransform() {
        vImg.style.transform = 'translate(' + vX + 'px,' + vY + 'px) scale(' + vZoom + ') rotate(' + vRot + 'deg)';
    }

    function showAt(i) {
        if (!vList.length) { closeViewer(); return; }
        vIdx = (i + vList.length) % vList.length;
        var it = vList[vIdx];
        vImg.src = it.src;
        vRot = 0; vZoom = 1; vX = 0; vY = 0;
        applyTransform();
        vPos.textContent = (vIdx + 1) + ' / ' + vList.length;
        var delBtn = viewer.querySelector('[data-cv="del"]');
        if (delBtn) delBtn.hidden = !it.canDelete;
    }

    function readCell(cell) {
        return $all('.co-thumb', cell).map(function (t) {
            return {
                id: parseInt(t.getAttribute('data-inv-id'), 10),
                src: t.getAttribute('data-src'),
                canDelete: t.getAttribute('data-can-delete') === '1'
            };
        });
    }

    function openViewer(cell, startId) {
        vCell = cell;
        vList = readCell(cell);
        var i = 0;
        vList.forEach(function (it, k) { if (it.id === startId) i = k; });
        viewer.classList.add('is-open');
        viewer.setAttribute('aria-hidden', 'false');
        showAt(i);
    }
    function closeViewer() {
        viewer.classList.remove('is-open');
        viewer.setAttribute('aria-hidden', 'true');
        vImg.src = '';
        vCell = null;
    }

    document.addEventListener('click', function (e) {
        var th = e.target.closest('.co-thumb');
        if (!th) return;
        e.stopPropagation();
        openViewer(th.closest('.co-inv-cell'), parseInt(th.getAttribute('data-inv-id'), 10));
    });

    // Lăn chuột phóng to / thu nhỏ. passive:false vì có preventDefault (chặn cuộn trang).
    if (vStage) {
        vStage.addEventListener('wheel', function (e) {
            e.preventDefault();
            vZoom = Math.min(8, Math.max(0.2, vZoom * (e.deltaY < 0 ? 1.12 : 1 / 1.12)));
            applyTransform();
        }, { passive: false });

        // Kéo ảnh khi đã phóng to.
        var keo = false, x0 = 0, y0 = 0;
        vStage.addEventListener('pointerdown', function (e) {
            keo = true; x0 = e.clientX - vX; y0 = e.clientY - vY;
            vStage.classList.add('is-panning');
            vStage.setPointerCapture(e.pointerId);
        });
        vStage.addEventListener('pointermove', function (e) {
            if (!keo) return;
            vX = e.clientX - x0; vY = e.clientY - y0;
            applyTransform();
        });
        ['pointerup', 'pointercancel'].forEach(function (ev) {
            vStage.addEventListener(ev, function () { keo = false; vStage.classList.remove('is-panning'); });
        });
    }

    document.addEventListener('click', function (e) {
        var b = e.target.closest('#co-viewer [data-cv]');
        if (!b) return;
        var act = b.getAttribute('data-cv');
        if (act === 'close')   { closeViewer(); return; }
        if (act === 'prev')    { showAt(vIdx - 1); return; }
        if (act === 'next')    { showAt(vIdx + 1); return; }
        if (act === 'rotl')    { vRot -= 90; applyTransform(); return; }
        if (act === 'rotr')    { vRot += 90; applyTransform(); return; }
        if (act === 'zoomin')  { vZoom = Math.min(8, vZoom * 1.2); applyTransform(); return; }
        if (act === 'zoomout') { vZoom = Math.max(0.2, vZoom / 1.2); applyTransform(); return; }

        if (act === 'download') {
            var a = document.createElement('a');
            a.href = vList[vIdx].src;
            a.download = (vList[vIdx].src.split('/').pop() || 'hoa-don.jpg');
            document.body.appendChild(a); a.click(); document.body.removeChild(a);
            return;
        }
        if (act === 'add')  { var inp = vCell && vCell.querySelector('.co-inv-file'); if (inp) inp.click(); return; }
        if (act === 'share') { openShare(vList[vIdx].id); return; }
        if (act === 'del') {
            if (!confirm('Xóa hóa đơn này?')) return;
            var cell = vCell;
            post('invoice_delete', form({ id: vList[vIdx].id }))
                .then(function (res) {
                    if (!res || !res.ok) { alert((res && res.message) || 'Xóa thất bại.'); return; }
                    return post('invoice_list', form({
                        inv_type: cell.getAttribute('data-inv-type'), id: cell.getAttribute('data-id')
                    })).then(function (r2) {
                        if (r2 && r2.ok) {
                            renderCell(cell, r2.files);
                            vList = readCell(cell);
                            if (!vList.length) closeViewer(); else showAt(Math.min(vIdx, vList.length - 1));
                        }
                    });
                })
                .catch(function () { alert('Lỗi mạng khi xóa.'); });
        }
    });

    document.addEventListener('keydown', function (e) {
        if (!viewer.classList.contains('is-open')) return;
        if (e.key === 'Escape')     closeViewer();
        if (e.key === 'ArrowLeft')  showAt(vIdx - 1);
        if (e.key === 'ArrowRight') showAt(vIdx + 1);
    });

    /* =================================================================================
       4) GỬI HÓA ĐƠN QUA CHAT
       ================================================================================= */
    var shareModal = $('#co-share-modal');
    var shareId    = 0;
    var danhBaDaTai = false;

    function openShare(invoiceId) {
        shareId = invoiceId;
        $('#co-share-status').textContent = '';
        $('#co-share-filter').value = '';
        shareModal.classList.add('is-open');
        shareModal.setAttribute('aria-hidden', 'false');
        if (danhBaDaTai) return;
        fetch(BASE + 'chat_contacts', { credentials: 'same-origin' })
            .then(function (r) { return r.json(); })
            .then(function (res) {
                if (!res || !res.ok) { $('#co-share-list').innerHTML = '<div class="co-share-empty">Không tải được danh bạ.</div>'; return; }
                var html = (res.data || []).map(function (u) {
                    var ten = u.alias || u.fullname || u.username || '';
                    var ava = u.avatar
                        ? '<img class="co-share-ava" src="' + esc(u.avatar) + '" alt="">'
                        : '<span class="co-share-ava co-share-ava-x">' + esc(ten.charAt(0)) + '</span>';
                    return '<label class="co-share-row"><input type="checkbox" class="co-share-pick" value="' + (+u.id) + '">'
                         + ava + '<span>' + esc(ten) + '</span></label>';
                }).join('');
                $('#co-share-list').innerHTML = html || '<div class="co-share-empty">Chưa có ai trong danh bạ.</div>';
                danhBaDaTai = true;
            })
            .catch(function () { $('#co-share-list').innerHTML = '<div class="co-share-empty">Lỗi mạng.</div>'; });
    }

    document.addEventListener('input', function (e) {
        if (!e.target.closest('#co-share-filter')) return;
        var kw = noAccent(e.target.value);
        $all('#co-share-list .co-share-row').forEach(function (r) {
            r.style.display = (kw === '' || noAccent(r.textContent).indexOf(kw) !== -1) ? '' : 'none';
        });
    });

    document.addEventListener('click', function (e) {
        if (!e.target.closest('#co-share-send')) return;
        var btn = e.target.closest('#co-share-send');
        var targets = $all('#co-share-list .co-share-pick:checked').map(function (c) { return +c.value; });
        if (!targets.length) { $('#co-share-status').textContent = 'Chưa chọn người nhận.'; return; }

        var fd = new FormData();
        fd.append('id', shareId);
        fd.append('note', $('#co-share-note').value || '');
        targets.forEach(function (id) { fd.append('targets[]', id); });

        btn.disabled = true; btn.textContent = 'Đang gửi…';
        post('invoice_share_chat', fd)
            .then(function (res) {
                if (res && res.ok) {
                    closeModal(shareModal);
                    alert('Đã gửi hóa đơn cho ' + res.sent + ' người qua chat.');
                    $('#co-share-note').value = '';
                    $all('#co-share-list .co-share-pick:checked').forEach(function (c) { c.checked = false; });
                } else {
                    $('#co-share-status').textContent = (res && res.message) || 'Gửi thất bại.';
                }
            })
            .catch(function () { $('#co-share-status').textContent = 'Lỗi mạng khi gửi.'; })
            .then(function () { btn.disabled = false; btn.textContent = 'Gửi'; });
    });

    /* =================================================================================
       5) MODAL CHI TIẾT ĐƠN — bấm vào dòng
       ================================================================================= */
    var detailModal = $('#co-detail-modal');

    function closeModal(m) {
        if (!m) return;
        m.classList.remove('is-open');
        m.setAttribute('aria-hidden', 'true');
    }
    document.addEventListener('click', function (e) {
        if (e.target.closest('[data-co-close]')) closeModal(e.target.closest('.co-modal'));
    });

    document.addEventListener('click', function (e) {
        var tr = e.target.closest('tr.co-row');
        // Bấm trong ô Hóa đơn thì KHÔNG mở chi tiết (nút +, thumbnail... có việc riêng).
        if (!tr || e.target.closest('.co-inv-cell')) return;

        $('#co-detail-title').textContent = 'Chi tiết đơn — ' + tr.getAttribute('data-date')
            + (tr.getAttribute('data-customer') ? ' · ' + tr.getAttribute('data-customer') : '');
        $('#co-detail-body').innerHTML = '<tr><td colspan="5">Đang tải…</td></tr>';
        $('#co-detail-foot').innerHTML = '';
        detailModal.classList.add('is-open');
        detailModal.setAttribute('aria-hidden', 'false');

        post('order_detail', form({
            inv_type: tr.getAttribute('data-inv-type'),
            id: tr.getAttribute('data-id'),
            created_at: tr.getAttribute('data-created-at')
        })).then(function (res) {
            if (!res || !res.ok) {
                $('#co-detail-body').innerHTML = '<tr><td colspan="5">' + esc((res && res.message) || 'Không tải được chi tiết.') + '</td></tr>';
                return;
            }
            var lines = res.lines || [];
            if (!lines.length) {
                $('#co-detail-body').innerHTML = '<tr><td colspan="5">Đơn này chưa có dòng hàng hóa nào.</td></tr>';
                return;
            }
            var tongTien = 0, tongKl = 0;
            $('#co-detail-body').innerHTML = lines.map(function (l) {
                tongTien += Number(l.value) || 0;
                tongKl   += Number(l.weight) || 0;
                return '<tr><td>' + esc(l.name) + '</td><td>' + esc(l.unit) + '</td>'
                     + '<td>' + num(l.qty) + '</td><td>' + money(l.price) + '</td>'
                     + '<td>' + money(l.value) + '</td></tr>';
            }).join('');
            $('#co-detail-foot').innerHTML =
                '<tr><td colspan="4">Tổng khối lượng</td><td>' + num(tongKl, 1) + ' kg</td></tr>' +
                '<tr><td colspan="4">Tổng thành tiền</td><td>' + money(tongTien) + '</td></tr>';
        }).catch(function () {
            $('#co-detail-body').innerHTML = '<tr><td colspan="5">Lỗi mạng.</td></tr>';
        });
    });
})();
