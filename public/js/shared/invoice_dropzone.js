/*
 * invoice_dropzone.js — vùng kéo-thả hóa đơn dạng "staging" (record-time).
 * Dùng cho các trang nhập kho: file được giữ ở client tới khi bấm "Ghi/Sửa",
 * sau đó trang tự upload qua FormData kèm invoice_id.
 *
 * API:
 *   var dz = InvoiceDropzone.create(rootEl);
 *   dz.getFiles() -> [File, ...]
 *   dz.count()    -> number
 *   dz.clear()    -> xóa toàn bộ staged files + preview
 *
 * Markup gốc cần có (rootEl):
 *   <div class="iu-dropzone"> ... <button class="iu-btn-choose"> <input class="iu-input" type=file multiple hidden> </div>
 *   <div class="iu-preview"></div>
 *
 * Cũng export InvoiceViewer.open(url) để xem ảnh phóng to (lightbox dùng chung).
 */
(function () {
    'use strict';

    var IMG_EXT = ['jpg', 'jpeg', 'png', 'gif', 'webp'];

    function ext(name) {
        return String(name || '').split('.').pop().toLowerCase();
    }
    function isImage(name) {
        return IMG_EXT.indexOf(ext(name)) !== -1;
    }
    function escapeHtml(s) {
        return String(s == null ? '' : s)
            .replace(/&/g, '&amp;').replace(/</g, '&lt;')
            .replace(/>/g, '&gt;').replace(/"/g, '&quot;');
    }

    /* ---------------- Dán ảnh từ clipboard (Ctrl+V) ---------------- */
    // Đổi 1 blob ảnh từ clipboard thành File có tên + đuôi hợp lệ (ảnh dán
    // thường không có tên nên phải tự đặt — backend kiểm tra theo đuôi).
    function renameImage(blob) {
        var e = String((blob.type || '').split('/')[1] || 'png').toLowerCase();
        if (e === 'jpeg') e = 'jpg';
        if (IMG_EXT.indexOf(e) === -1) e = 'png';
        var name = 'paste_' + (++pasteSeq) + '.' + e;
        try {
            return new File([blob], name, { type: blob.type || 'image/png' });
        } catch (err) {
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
        // Fallback: vài trình duyệt đặt ảnh ở clipboardData.files.
        if (!out.length && cd.files && cd.files.length) {
            Array.prototype.forEach.call(cd.files, function (f) {
                if (f.type && f.type.indexOf('image/') === 0) out.push(renameImage(f));
            });
        }
        return out;
    }

    // Vùng dropzone đang được chọn để dán ảnh (Ctrl+V) + cài listener 1 lần.
    var pasteSeq = 0;
    var activeRoot = null;
    var pasteInstalled = false;
    function installPaste() {
        if (pasteInstalled) return;
        pasteInstalled = true;
        document.addEventListener('click', function (e) {
            var r = e.target.closest ? e.target.closest('.wp-invoice-upload') : null;
            if (activeRoot && activeRoot !== r) activeRoot.classList.remove('iu-active');
            activeRoot = (r && r.__iuAddFiles) ? r : null;
            if (activeRoot) activeRoot.classList.add('iu-active');
        });
        document.addEventListener('paste', function (e) {
            if (!activeRoot || !activeRoot.__iuAddFiles) return;
            var files = imagesFromClipboard(e);
            if (!files.length) return;   // clipboard chỉ có text/link -> bỏ qua
            e.preventDefault();
            activeRoot.__iuAddFiles(files);
        });
    }

    /* ---------------- Lightbox dùng chung (zoom lăn chuột, kéo di chuyển, xoay) ---------------- */
    var InvoiceViewer = (function () {
        var box = null, img = null;
        var scale = 1, rotate = 0, tx = 0, ty = 0;
        var dragging = false, dragStartX = 0, dragStartY = 0, txStart = 0, tyStart = 0;
        var MIN_SCALE = 1, MAX_SCALE = 6;

        function applyTransform() {
            img.style.transform = 'translate(' + tx + 'px,' + ty + 'px) scale(' + scale + ') rotate(' + rotate + 'deg)';
        }
        function resetTransform() {
            scale = 1; rotate = 0; tx = 0; ty = 0;
            applyTransform();
        }
        function ensure() {
            if (box) return box;
            box = document.createElement('div');
            box.className = 'iu-lightbox';
            box.innerHTML = '<span class="iu-lb-close" title="Đóng (Esc)">&times;</span>'
                + '<button type="button" class="iu-lb-rotate" title="Xoay ảnh"><i class="fa-solid fa-rotate-right"></i></button>'
                + '<img src="" alt="hóa đơn" draggable="false">';
            document.body.appendChild(box);
            img = box.querySelector('img');

            box.addEventListener('click', function (e) {
                if (e.target === box || e.target.classList.contains('iu-lb-close')) hide();
            });
            document.addEventListener('keydown', function (e) {
                if (e.key === 'Escape') hide();
            });

            box.querySelector('.iu-lb-rotate').addEventListener('click', function (e) {
                e.stopPropagation();
                rotate = (rotate + 90) % 360;
                applyTransform();
            });

            /* Lăn chuột để phóng to/thu nhỏ (quanh vị trí con trỏ trên ảnh). */
            img.addEventListener('wheel', function (e) {
                e.preventDefault();
                var rect = img.getBoundingClientRect();
                var offX = e.clientX - (rect.left + rect.width / 2);
                var offY = e.clientY - (rect.top + rect.height / 2);
                var prevScale = scale;
                var factor = e.deltaY < 0 ? 1.15 : 1 / 1.15;
                scale = Math.min(MAX_SCALE, Math.max(MIN_SCALE, scale * factor));
                if (scale === MIN_SCALE) { tx = 0; ty = 0; }
                else {
                    var ratio = scale / prevScale;
                    tx = (tx - offX) * ratio + offX;
                    ty = (ty - offY) * ratio + offY;
                }
                applyTransform();
            }, { passive: false });

            /* Nhấn giữ chuột để kéo ảnh (chỉ có ý nghĩa khi đã phóng to). */
            img.addEventListener('mousedown', function (e) {
                e.preventDefault();
                dragging = true;
                dragStartX = e.clientX; dragStartY = e.clientY;
                txStart = tx; tyStart = ty;
                img.classList.add('is-dragging');
            });
            document.addEventListener('mousemove', function (e) {
                if (!dragging) return;
                tx = txStart + (e.clientX - dragStartX);
                ty = tyStart + (e.clientY - dragStartY);
                applyTransform();
            });
            document.addEventListener('mouseup', function () {
                if (!dragging) return;
                dragging = false;
                img.classList.remove('is-dragging');
            });

            return box;
        }
        function open(url, isImg) {
            if (isImg === undefined) isImg = isImage(url);
            if (!isImg) { window.open(url, '_blank'); return; }
            ensure();
            resetTransform();
            img.src = url;
            box.classList.add('show');
        }
        function hide() {
            if (!box) return;
            box.classList.remove('show');
            dragging = false;
            img.classList.remove('is-dragging');
        }
        return { open: open };
    })();
    window.InvoiceViewer = InvoiceViewer;

    /* ---------------- Staging dropzone ---------------- */
    function create(root) {
        if (!root) return null;
        var zone    = root.querySelector('.iu-dropzone');
        var input   = root.querySelector('.iu-input');
        var btn     = root.querySelector('.iu-btn-choose');
        var preview = root.querySelector('.iu-preview');
        if (!zone || !input || !preview) return null;

        var staged = []; // [{ id, file }]
        var seq = 0;

        function addFiles(fileList) {
            Array.prototype.forEach.call(fileList, function (f) {
                staged.push({ id: ++seq, file: f });
            });
            render();
        }

        function removeById(id) {
            staged = staged.filter(function (s) { return s.id !== id; });
            render();
        }

        function render() {
            preview.innerHTML = staged.map(function (s) {
                var name = s.file.name;
                var inner;
                if (isImage(name)) {
                    var url = URL.createObjectURL(s.file);
                    inner = '<img src="' + url + '" data-view="' + url + '" alt="">';
                } else {
                    inner = '<i class="fa-solid fa-file-pdf iu-file-ico"></i>';
                }
                return '<div class="iu-thumb" data-id="' + s.id + '">' +
                       inner +
                       '<span class="iu-file-name">' + escapeHtml(name) + '</span>' +
                       '<button type="button" class="iu-remove" title="Bỏ tệp">&times;</button>' +
                       '</div>';
            }).join('');
        }

        // Events
        // CHỈ nút "Chọn tệp" mới mở hộp chọn thư mục. Click vào vùng dropzone
        // KHÔNG mở hộp chọn — đó chỉ là thao tác chọn vùng để dán ảnh (Ctrl+V).
        if (btn) btn.addEventListener('click', function (e) { e.preventDefault(); input.click(); });
        input.addEventListener('change', function () {
            if (input.files && input.files.length) addFiles(input.files);
            input.value = '';
        });

        ['dragenter', 'dragover'].forEach(function (ev) {
            zone.addEventListener(ev, function (e) {
                e.preventDefault(); e.stopPropagation();
                zone.classList.add('dragover');
            });
        });
        ['dragleave', 'drop'].forEach(function (ev) {
            zone.addEventListener(ev, function (e) {
                e.preventDefault(); e.stopPropagation();
                zone.classList.remove('dragover');
            });
        });
        zone.addEventListener('drop', function (e) {
            var dt = e.dataTransfer;
            if (dt && dt.files && dt.files.length) addFiles(dt.files);
        });

        // Cho phép dán ảnh (Ctrl+V) khi vùng này đang được chọn (click vào).
        root.__iuAddFiles = addFiles;
        installPaste();

        preview.addEventListener('click', function (e) {
            var rm = e.target.closest('.iu-remove');
            if (rm) {
                var id = parseInt(rm.closest('.iu-thumb').getAttribute('data-id'), 10);
                removeById(id);
                return;
            }
            var img = e.target.closest('img[data-view]');
            if (img) InvoiceViewer.open(img.getAttribute('data-view'), true);
        });

        return {
            getFiles: function () { return staged.map(function (s) { return s.file; }); },
            count: function () { return staged.length; },
            clear: function () { staged = []; render(); }
        };
    }

    window.InvoiceDropzone = { create: create };
})();
