/* ============================================================
   product_profile_dragdrop.js
   Kéo-thả file từ máy tính vào vùng thả:
   - data-dropzone="product"     -> bảng product_files (trang list)
   - data-dropzone="composition" -> bảng files        (trang chi tiết)
   Tên file ban đầu = tên file (bỏ đuôi), hiển thị dạng input để sửa.
   Sửa input -> cập nhật file_name trong DB.
   Yêu cầu: jQuery
   ============================================================ */
$(function () {

    var ACT = '?mod=product_profile&controllers=product_profile&action=';

    /* ---------- Gắn sự kiện kéo-thả cho từng vùng ---------- */
    // Chỉ xử lý khi kéo FILE từ máy tính (bỏ qua kéo-sắp-xếp thành phần)
    function dragHasFiles(e) {
        var dt = e.originalEvent && e.originalEvent.dataTransfer;
        if (!dt || !dt.types) return false;
        return Array.prototype.indexOf.call(dt.types, 'Files') !== -1;
    }

    $('[data-dropzone]').each(function () {
        var el = this;
        var $el = $(el);
        var depth = 0;

        $el.on('dragenter', function (e) {
            if (!dragHasFiles(e)) return; // để event bubble cho việc sắp xếp
            e.preventDefault();
            e.stopPropagation();
            depth++;
            $el.addClass('is-dragover');
        });
        $el.on('dragover', function (e) {
            if (!dragHasFiles(e)) return;
            e.preventDefault();
            e.stopPropagation();
            if (e.originalEvent.dataTransfer) {
                e.originalEvent.dataTransfer.dropEffect = 'copy';
            }
        });
        $el.on('dragleave', function (e) {
            if (!dragHasFiles(e)) return;
            e.preventDefault();
            e.stopPropagation();
            depth--;
            if (depth <= 0) {
                depth = 0;
                $el.removeClass('is-dragover');
            }
        });
        $el.on('drop', function (e) {
            if (!dragHasFiles(e)) return;
            e.preventDefault();
            e.stopPropagation();
            depth = 0;
            $el.removeClass('is-dragover');
            var files = e.originalEvent.dataTransfer ? e.originalEvent.dataTransfer.files : null;
            if (!files || !files.length) return;
            handleDrop($el, files);
        });
    });

    function handleDrop($zone, files) {
        var kind = $zone.data('dropzone');
        for (var i = 0; i < files.length; i++) {
            if (kind === 'product') {
                uploadProductFile($zone, files[i]);
            } else if (kind === 'composition') {
                uploadCompositionFile($zone, files[i]);
            }
        }
    }

    /* ---------- (2) Upload file sản phẩm ---------- */
    function uploadProductFile($zone, file) {
        var productId = $zone.data('product-id');
        var fd = new FormData();
        fd.append('product_id', productId);
        fd.append('file', file);

        var $ul = $zone.find('.main-file').first();
        var $placeholder = addPlaceholder($ul, file.name);

        $.ajax({
            url: ACT + 'ajax_upload_product_file',
            method: 'POST',
            data: fd,
            processData: false,
            contentType: false,
            dataType: 'json',
            success: function (res) {
                $placeholder.remove();
                if (!res || !res.success) {
                    alert((res && res.message) || 'Upload thất bại.');
                    return;
                }
                $ul.append(buildProductFileItem(res, productId));
            },
            error: function () {
                $placeholder.remove();
                alert('Lỗi kết nối khi upload.');
            }
        });
    }

    function buildProductFileItem(res, productId) {
        var $li = $('<li class="file-item dropped-file"></li>');

        // Tên file: link mở file + nút bút để sửa tên
        var $nameWrap = $('<div class="file-name-edit"></div>');
        var $a = $('<a target="_blank"></a>').attr('href', res.web_path || '#')
            .append($('<p class="title-file"></p>').text(res.file_name));
        var $edit = $('<a href="#" class="btn-edit-filename" title="Sửa tên file"><i class="fa-solid fa-pen"></i></a>')
            .attr('data-kind', 'product')
            .attr('data-file-id', res.id);
        $nameWrap.append($a).append($edit);

        var $icons = $('<div class="wp-icon-processing-file"></div>');
        $icons.append(iconLink(ACT + 'download_file&id_file=' + res.id, 'fa-solid fa-download', false));
        $icons.append(iconLink(ACT + 'update_file&id_product=' + productId + '&id_file=' + res.id, 'fa-solid fa-file-arrow-up', false));
        $icons.append(iconLink(ACT + 'delete_file&id_file=' + res.id, 'fa-solid fa-trash-can', 'Bạn có thật sự muốn xóa file này?'));

        $li.append($nameWrap).append($icons);
        return $li;
    }

    /* ---------- (3) Upload file thành phần ---------- */
    function uploadCompositionFile($zone, file) {
        var fd = new FormData();
        fd.append('entity_type', $zone.data('entity-type'));
        fd.append('entity_id', $zone.data('entity-id'));
        fd.append('folder_name', $zone.data('folder-name') || '');
        fd.append('product_id', $zone.data('product-id') || 0);
        fd.append('file', file);

        var $ul = $zone.find('.list-file').first();
        // Nếu vùng chưa có <ul.list-file> thì tạo
        if (!$ul.length) {
            $ul = $('<ul class="list-file"></ul>');
            $zone.append($ul);
        }
        var $placeholder = addPlaceholder($ul, file.name, true);

        $.ajax({
            url: ACT + 'ajax_upload_composition_file',
            method: 'POST',
            data: fd,
            processData: false,
            contentType: false,
            dataType: 'json',
            success: function (res) {
                $placeholder.remove();
                if (!res || !res.success) {
                    alert((res && res.message) || 'Upload thất bại.');
                    return;
                }
                $ul.append(buildCompositionFileItem(res, $zone));
            },
            error: function () {
                $placeholder.remove();
                alert('Lỗi kết nối khi upload.');
            }
        });
    }

    function buildCompositionFileItem(res, $zone) {
        var productId = $zone.data('product-id') || res.product_id || 0;
        var entityName = $zone.data('folder-name') || '';

        var $li = $('<li class="file-item dropped-file"></li>');
        $li.append('<div class="level-2">--</div>');

        // Tên file: link mở file + nút bút (data-kind=composition) để sửa tên
        var $nameWrap = $('<div class="name-file file-name-edit"></div>');
        var $a = $('<a target="_blank"></a>').attr('href', res.web_path || '#').text(res.file_name);
        var $edit = $('<a href="#" class="btn-edit-filename" title="Sửa tên file"><i class="fa-solid fa-pen"></i></a>')
            .attr('data-kind', 'composition')
            .attr('data-file-id', res.id);
        $nameWrap.append($a).append($edit);
        $li.append($nameWrap);

        var $op = $('<div class="operation"></div>');
        // Tải file
        $op.append(iconLink(ACT + 'download_composition_file&file_id=' + res.id + '&product_id=' + productId, 'fa-solid fa-download', false, 'Tải file'));
        // Thay thế file (mở modal js-open-cfile của trang chi tiết)
        var $replace = $('<a href="#" class="replace-file js-open-cfile" title="Thay thế file"><i class="fa-solid fa-rotate"></i></a>')
            .attr('data-action', ACT + 'ready_replace_composition_file&file_id=' + res.id + '&product_id=' + productId)
            .attr('data-subtitle', 'Thay thế file của: ' + entityName)
            .attr('data-old', res.file_name)
            .attr('data-label', res.file_name)
            .attr('data-submit', 'THAY THẾ');
        $op.append($replace);
        // Xóa file
        $op.append(iconLink(ACT + 'delete_composition_file&file_id=' + res.id + '&product_id=' + productId, 'fa-regular fa-trash-can', 'Bạn có chắc chắn muốn xóa file này?', 'Xóa file'));

        $li.append($op);
        return $li;
    }

    /* ---------- Helpers ---------- */
    function iconLink(href, iconClass, confirmMsg, title) {
        var $a = $('<a></a>').attr('href', href).append($('<i></i>').addClass(iconClass));
        if (title) $a.attr('title', title);
        if (confirmMsg) {
            $a.on('click', function (e) {
                if (!window.confirm(confirmMsg)) e.preventDefault();
            });
        }
        return $a;
    }

    // Hiển thị dòng tạm "đang tải" trong lúc upload
    function addPlaceholder($ul, fileName, isComposition) {
        var $li = $('<li class="file-item file-uploading"></li>');
        if (isComposition) $li.append('<div class="level-2">--</div>');
        $li.append($('<span class="uploading-label"></span>')
            .html('<i class="fa-solid fa-spinner fa-spin"></i> Đang tải ')
            .append($('<span></span>').text(fileName)));
        $ul.append($li);
        return $li;
    }

    /* ---------- (list) Sửa tên file qua nút bút: click -> input -> blur -> cập nhật ---------- */
    // Dựng lại phần hiển thị tên (link + nút bút) theo loại file (product / composition)
    function restoreNameDisplay($wrap, kind, href, name, fileId) {
        var $a = $('<a target="_blank"></a>').attr('href', href);
        // 'pfile' = file sản phẩm (product_files) hiển thị ở modal "Tần suất" — dùng kiểu chữ thường trong
        // <a> giống composition/invoice, KHÔNG bọc <p class="title-file"> (kiểu đó chỉ dành cho list.php).
        if (kind === 'composition' || kind === 'invoice' || kind === 'pfile') {
            $a.text(name); // detail: tên nằm trực tiếp trong <a>
        } else {
            $a.append($('<p class="title-file"></p>').text(name)); // list: <a><p class="title-file">
        }
        var $edit = $('<a href="#" class="btn-edit-filename" title="Sửa tên file"><i class="fa-solid fa-pen"></i></a>')
            .attr('data-kind', kind)
            .attr('data-file-id', fileId);
        $wrap.empty().append($a).append($edit);
    }

    $(document).on('click', '.btn-edit-filename', function (e) {
        e.preventDefault();
        var $btn = $(this);
        var $wrap = $btn.closest('.file-name-edit');
        if ($wrap.find('input.inline-rename').length) {
            $wrap.find('input.inline-rename').focus();
            return;
        }
        var kind = $btn.data('kind') || 'product';
        var fileId = $btn.data('file-id');
        var $link = $wrap.find('a[target="_blank"]');
        var href = $link.attr('href') || '#';
        var name = ($link.text() || '').trim(); // .text() lấy được cả khi tên nằm trong <p>

        var $input = $('<input type="text" class="inline-rename">')
            .attr('data-kind', kind)
            .attr('data-file-id', fileId)
            .attr('data-href', href)
            .attr('data-orig', name)
            .val(name);
        if (kind !== 'composition') $input.addClass('title-file'); // đồng bộ style ở list
        $wrap.empty().append($input);
        $input.trigger('focus');
        if ($input[0].setSelectionRange) {
            $input[0].setSelectionRange(0, $input.val().length);
        }
    });

    // Enter = lưu, Esc = huỷ
    $(document).on('keydown', '.inline-rename', function (e) {
        if (e.key === 'Enter') {
            e.preventDefault();
            $(this).trigger('blur');
        } else if (e.key === 'Escape') {
            $(this).data('cancel', true).trigger('blur');
        }
    });

    // Click ra ngoài (blur) -> cập nhật DB rồi trả lại dạng link
    $(document).on('blur', '.inline-rename', function () {
        var $input = $(this);
        var $wrap = $input.closest('.file-name-edit');
        var kind = $input.data('kind') || 'product';
        var fileId = $input.data('file-id');
        var href = $input.data('href') || '#';
        var orig = $input.data('orig') || '';
        var cancelled = $input.data('cancel');
        var newName = ($input.val() || '').trim();
        var action = kind === 'composition' ? 'ajax_rename_composition_file'
            : kind === 'invoice' ? 'ajax_rename_material_invoice'
            : 'ajax_rename_product_file';

        // Huỷ / rỗng / không đổi -> giữ tên cũ, không gọi DB
        if (cancelled || newName === '' || newName === orig) {
            restoreNameDisplay($wrap, kind, href, orig, fileId);
            return;
        }

        $.ajax({
            url: ACT + action,
            method: 'POST',
            data: { file_id: fileId, file_name: newName },
            dataType: 'json',
            success: function (res) {
                restoreNameDisplay($wrap, kind, href, (res && res.success) ? newName : orig, fileId);
                if (!res || !res.success) alert((res && res.message) || 'Đổi tên thất bại.');
            },
            error: function () {
                restoreNameDisplay($wrap, kind, href, orig, fileId);
                alert('Lỗi kết nối khi đổi tên file.');
            }
        });
    });

});
