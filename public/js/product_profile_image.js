/* ============================================================
   product_profile_image.js — hover trên .img_product (trang list):
   - Nút "mắt": xem ảnh phóng to (dùng lightbox dùng chung InvoiceViewer).
   - Nút "máy ảnh": đổi ảnh — chọn tệp / dán Ctrl+V / kéo-thả trực tiếp
     vào ảnh. Upload xong cập nhật luôn products.image_url qua AJAX.
   Yêu cầu: jQuery + public/js/shared/invoice_dropzone.js (InvoiceViewer)
   ============================================================ */
$(function () {
    'use strict';

    var ACT = '?mod=product_profile&controllers=product_profile&action=';
    var IMG_EXT = ['jpg', 'jpeg', 'png', 'gif', 'webp', 'bmp'];
    var activeZone = null;

    function dragHasFiles(e) {
        var dt = e.originalEvent && e.originalEvent.dataTransfer;
        if (!dt || !dt.types) return false;
        return Array.prototype.indexOf.call(dt.types, 'Files') !== -1;
    }

    // Ảnh dán từ clipboard không có tên -> tự đặt tên hợp lệ theo mime type
    function fileFromBlob(blob) {
        var ext = String((blob.type || '').split('/')[1] || 'png').toLowerCase();
        if (ext === 'jpeg') ext = 'jpg';
        if (IMG_EXT.indexOf(ext) === -1) ext = 'png';
        var name = 'paste_' + Date.now() + '.' + ext;
        try {
            return new File([blob], name, { type: blob.type || 'image/png' });
        } catch (err) {
            try { blob.name = name; } catch (e2) {}
            return blob;
        }
    }

    function uploadImage($zone, file) {
        if (!file || file.type.indexOf('image/') !== 0) {
            alert('Chỉ chấp nhận file hình ảnh.');
            return;
        }
        var productId = $zone.closest('.container-product').data('product-id');
        if (!productId) return;

        var fd = new FormData();
        fd.append('product_id', productId);
        fd.append('file', file);

        $zone.addClass('is-uploading');
        $.ajax({
            url: ACT + 'ajax_update_product_image',
            method: 'POST',
            data: fd,
            processData: false,
            contentType: false,
            dataType: 'json',
            success: function (res) {
                if (res && res.success) {
                    $zone.find('.js-product-photo').attr('src', res.web_path + '?t=' + Date.now());
                    // Sản phẩm trước đó chưa có ảnh (chỉ có nút máy ảnh) -> vừa upload xong thì thêm nút "mắt"
                    if (!$zone.find('.img-action-view').length) {
                        $('<button type="button" class="img-action-btn img-action-view" title="Xem ảnh phóng to"><i class="fa-solid fa-eye"></i></button>')
                            .prependTo($zone.find('.img-product-overlay'));
                    }
                } else {
                    alert((res && res.message) || 'Cập nhật ảnh thất bại.');
                }
            },
            error: function () { alert('Lỗi kết nối khi cập nhật ảnh.'); },
            complete: function () { $zone.removeClass('is-uploading'); }
        });
    }

    /* ---------- Nút "mắt": xem ảnh phóng to ---------- */
    $(document).on('click', '.img-action-view', function (e) {
        e.preventDefault();
        e.stopPropagation();
        var src = $(this).closest('.img_product').find('.js-product-photo').attr('src');
        if (src && window.InvoiceViewer) window.InvoiceViewer.open(src, true);
    });

    /* ---------- Chọn tệp từ máy tính (nút "máy ảnh") ---------- */
    $(document).on('change', '.js-product-photo-input', function () {
        var file = this.files && this.files[0];
        var $zone = $(this).closest('.img_product');
        this.value = '';
        if (file) uploadImage($zone, file);
    });

    /* ---------- Click vùng ảnh (không phải 2 nút) = chọn vùng để dán Ctrl+V ---------- */
    $(document).on('click', '.img_product', function (e) {
        if ($(e.target).closest('.img-action-btn').length) return;
        if (activeZone && activeZone[0] !== this) activeZone.removeClass('img-product-active');
        activeZone = $(this).addClass('img-product-active');
    });
    $(document).on('click', function (e) {
        if (activeZone && !$(e.target).closest('.img_product').length) {
            activeZone.removeClass('img-product-active');
            activeZone = null;
        }
    });
    $(document).on('paste', function (e) {
        if (!activeZone) return;
        var cd = (e.originalEvent || e).clipboardData;
        if (!cd || !cd.items) return;
        for (var i = 0; i < cd.items.length; i++) {
            var it = cd.items[i];
            if (it.kind === 'file' && it.type && it.type.indexOf('image/') === 0) {
                var blob = it.getAsFile();
                if (blob) {
                    e.preventDefault();
                    uploadImage(activeZone, fileFromBlob(blob));
                }
                break;
            }
        }
    });

    /* ---------- Kéo-thả ảnh trực tiếp vào .img_product ----------
       Gắn trực tiếp lên từng vùng (không delegate qua document) để chặn
       nổi bọt TRƯỚC KHI tới .container-product[data-dropzone="product"]
       (vùng thả file hồ sơ) trong product_profile_dragdrop.js. */
    $('.img_product').each(function () {
        var $zone = $(this);
        var depth = 0;

        $zone.on('dragenter', function (e) {
            if (!dragHasFiles(e)) return;
            e.preventDefault(); e.stopPropagation();
            depth++;
            $zone.addClass('is-dragover');
        });
        $zone.on('dragover', function (e) {
            if (!dragHasFiles(e)) return;
            e.preventDefault(); e.stopPropagation();
            if (e.originalEvent.dataTransfer) e.originalEvent.dataTransfer.dropEffect = 'copy';
        });
        $zone.on('dragleave', function (e) {
            if (!dragHasFiles(e)) return;
            e.preventDefault(); e.stopPropagation();
            depth--;
            if (depth <= 0) { depth = 0; $zone.removeClass('is-dragover'); }
        });
        $zone.on('drop', function (e) {
            if (!dragHasFiles(e)) return;
            e.preventDefault(); e.stopPropagation();
            depth = 0;
            $zone.removeClass('is-dragover');
            var files = e.originalEvent.dataTransfer ? e.originalEvent.dataTransfer.files : null;
            if (files && files.length) uploadImage($zone, files[0]);
        });
    });
});
