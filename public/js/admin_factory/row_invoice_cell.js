/*
 * row_invoice_cell.js — ô "Hóa đơn" theo từng dòng cho các bảng admin_factory
 * (sales_orders, purchase_order). Mỗi cell có:
 *   - data-wr-id   : id dòng (warehouse_receipts.id hoặc sales_orders.id)
 *   - data-inv-type: 'purchase_invoice' | 'sales_invoice'
 * Chức năng: kéo-thả / chọn tệp -> upload ngay (AJAX); click xem; click xóa.
 *
 * Endpoints (admin_factory):
 *   action=receipt_invoice_upload  (POST: wr_id, type, files[])
 *   action=receipt_invoice_delete  (POST: id)
 *
 * Dán ảnh (Ctrl+V): click 1 ô để chọn (viền xanh) rồi Ctrl+V — ảnh trong clipboard
 * (vd "Copy hình ảnh" từ Zalo/Messenger/web) được upload thẳng, khỏi tải về máy.
 */
(function () {
    'use strict';

    var BASE = '?mod=admin_factory&controllers=admin&action=';
    var IMG_EXT = ['jpg', 'jpeg', 'png', 'gif', 'webp'];

    // Ô đang được chọn để dán ảnh (Ctrl+V).
    var activeCell = null;
    function setActive(cell) {
        if (activeCell === cell) return;
        if (activeCell) activeCell.classList.remove('ic-active');
        activeCell = cell || null;
        if (activeCell) activeCell.classList.add('ic-active');
    }

    function isImage(url) {
        return IMG_EXT.indexOf(String(url || '').split('.').pop().toLowerCase()) !== -1;
    }
    function escapeAttr(s) {
        return String(s == null ? '' : s).replace(/"/g, '&quot;');
    }

    function thumbHtml(inv) {
        var url = inv.file_url;
        var inner = isImage(url)
            ? '<img src="' + escapeAttr(url) + '" data-view="' + escapeAttr(url) + '" alt="">'
            : '<i class="fa-solid fa-file-pdf iu-file-ico" data-view="' + escapeAttr(url) + '"></i>';
        return '<div class="iu-thumb" data-id="' + inv.id + '">' +
               inner +
               '<button type="button" class="iu-remove" title="Xóa hóa đơn">&times;</button>' +
               '</div>';
    }

    function view(url) {
        if (window.InvoiceViewer) window.InvoiceViewer.open(url, isImage(url));
        else window.open(url, '_blank');
    }

    function syncCount(cell) {
        var list = cell.querySelector('.ic-list');
        var cnt  = cell.querySelector('.ic-count');
        if (list && cnt) {
            var n = list.querySelectorAll('.iu-thumb').length;
            cnt.textContent = n;
            cnt.classList.toggle('has', n > 0); // có hóa đơn -> số xanh lá
        }
    }

    function upload(cell, files) {
        if (!files || !files.length) return;
        var wrId = cell.getAttribute('data-wr-id');
        var type = cell.getAttribute('data-inv-type');
        var store = cell.getAttribute('data-store') || '';
        var list = cell.querySelector('.ic-list');
        var drop = cell.querySelector('.ic-drop');

        var fd = new FormData();
        fd.append('wr_id', wrId);
        fd.append('type', type);
        if (store) fd.append('store', store);
        Array.prototype.forEach.call(files, function (f) { fd.append('files[]', f); });

        var busy = document.createElement('div');
        busy.className = 'ic-uploading';
        busy.textContent = 'Đang tải lên...';
        cell.insertBefore(busy, drop);

        fetch(BASE + 'receipt_invoice_upload', { method: 'POST', body: fd, credentials: 'same-origin' })
            .then(function (r) { return r.json(); })
            .then(function (res) {
                busy.remove();
                if (!res || !res.ok) {
                    alert((res && res.message) || 'Tải hóa đơn thất bại.');
                    return;
                }
                (res.saved || []).forEach(function (inv) {
                    list.insertAdjacentHTML('beforeend', thumbHtml(inv));
                });
                syncCount(cell);
                cell.classList.add('ic-open'); // mở danh sách để thấy hóa đơn vừa thêm
                if (res.errors && res.errors.length) alert(res.errors.join('\n'));
            })
            .catch(function () { busy.remove(); alert('Lỗi mạng khi tải hóa đơn.'); });
    }

    function del(thumb) {
        var id = thumb.getAttribute('data-id');
        var cell = thumb.closest('.invoice-cell');
        if (!id) { thumb.remove(); syncCount(cell); return; }
        if (!confirm('Xóa hóa đơn này?')) return;
        var fd = new FormData();
        fd.append('id', id);
        var store = cell ? (cell.getAttribute('data-store') || '') : '';
        if (store) fd.append('store', store);
        fetch(BASE + 'receipt_invoice_delete', { method: 'POST', body: fd, credentials: 'same-origin' })
            .then(function (r) { return r.json(); })
            .then(function (res) {
                if (res && res.ok) { thumb.remove(); syncCount(cell); }
                else alert((res && res.message) || 'Xóa thất bại.');
            })
            .catch(function () { alert('Lỗi mạng khi xóa hóa đơn.'); });
    }

    document.addEventListener('click', function (e) {
        // Chọn ô để dán ảnh: click trong ô -> chọn; click ngoài -> bỏ chọn.
        setActive(e.target.closest ? e.target.closest('.invoice-cell') : null);

        var rm = e.target.closest && e.target.closest('.invoice-cell .iu-remove');
        if (rm) { e.preventDefault(); del(rm.closest('.iu-thumb')); return; }

        var v = e.target.closest && e.target.closest('.invoice-cell [data-view]');
        if (v) { e.preventDefault(); view(v.getAttribute('data-view')); return; }

        var toggle = e.target.closest && e.target.closest('.invoice-cell .ic-toggle');
        if (toggle) {
            e.preventDefault();
            e.stopPropagation();
            toggle.closest('.invoice-cell').classList.toggle('ic-open');
            return;
        }

        // Nút "Tải lên" -> mở hộp chọn tệp. Click vùng ô khác chỉ để chọn ô (đã setActive ở trên).
        var up = e.target.closest && e.target.closest('.invoice-cell .ic-upload');
        if (up) {
            e.preventDefault();
            e.stopPropagation();
            var cell = up.closest('.invoice-cell');
            setActive(cell);
            var input = cell.querySelector('.ic-drop input[type="file"]');
            if (input) input.click();
        }
    });

    document.addEventListener('change', function (e) {
        var input = e.target;
        if (!input.matches || !input.matches('.invoice-cell .ic-drop input[type="file"]')) return;
        var cell = input.closest('.invoice-cell');
        if (input.files && input.files.length) upload(cell, input.files);
        input.value = '';
    });

    // Drag & drop trên từng .ic-drop
    document.addEventListener('dragover', function (e) {
        var drop = e.target.closest && e.target.closest('.invoice-cell .ic-drop');
        if (!drop) return;
        e.preventDefault();
        drop.classList.add('dragover');
    });
    document.addEventListener('dragleave', function (e) {
        var drop = e.target.closest && e.target.closest('.invoice-cell .ic-drop');
        if (drop) drop.classList.remove('dragover');
    });
    document.addEventListener('drop', function (e) {
        var drop = e.target.closest && e.target.closest('.invoice-cell .ic-drop');
        if (!drop) return;
        e.preventDefault();
        drop.classList.remove('dragover');
        var cell = drop.closest('.invoice-cell');
        if (e.dataTransfer && e.dataTransfer.files && e.dataTransfer.files.length) {
            upload(cell, e.dataTransfer.files);
        }
    });

    // Đổi 1 blob ảnh từ clipboard thành File có tên + đuôi hợp lệ (backend kiểm tra
    // định dạng theo đuôi). Ảnh dán thường không có tên file nên phải tự đặt.
    function renameImage(blob) {
        var ext = String((blob.type || '').split('/')[1] || 'png').toLowerCase();
        if (ext === 'jpeg') ext = 'jpg';
        if (IMG_EXT.indexOf(ext) === -1) ext = 'png';
        var name = 'paste_' + Date.now() + '.' + ext;
        try {
            return new File([blob], name, { type: blob.type || 'image/png' });
        } catch (err) {
            // Trình duyệt cũ không có constructor File: gắn name trực tiếp lên blob.
            try { blob.name = name; } catch (e2) {}
            return blob;
        }
    }

    // Gom các ảnh từ clipboard của sự kiện paste.
    function imagesFromClipboard(e) {
        var out = [];
        var cd = e.clipboardData || window.clipboardData;
        if (!cd) return out;

        var items = cd.items || [];
        for (var i = 0; i < items.length; i++) {
            var it = items[i];
            if (it.kind === 'file' && it.type && it.type.indexOf('image/') === 0) {
                var blob = it.getAsFile();
                if (blob) out.push(renameImage(blob));
            }
        }
        // Fallback: một số trình duyệt đặt ảnh ở clipboardData.files.
        if (!out.length && cd.files && cd.files.length) {
            Array.prototype.forEach.call(cd.files, function (f) {
                if (f.type && f.type.indexOf('image/') === 0) out.push(renameImage(f));
            });
        }
        return out;
    }

    // Ctrl+V: dán ảnh vào ô đang chọn.
    document.addEventListener('paste', function (e) {
        if (!activeCell) return;
        var files = imagesFromClipboard(e);
        if (!files.length) return;   // clipboard không có ảnh (vd chỉ có text/link)
        e.preventDefault();
        upload(activeCell, files);
    });
})();
