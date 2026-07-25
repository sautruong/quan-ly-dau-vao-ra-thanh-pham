/* ============================================================
   product_profile_info.js
   Modal "Xem thông tin sản phẩm" (#info-basic-modal):
   - Sửa Đơn vị / Quy cách bao bì -> auto-save vào product_info_basic
   - Bao bì trong/ngoài: tìm & chọn nguyên liệu -> lưu *_id
   - Cập nhật / thêm hình ảnh sản phẩm
   Yêu cầu: jQuery
   ============================================================ */
$(function () {

    var ACT = '?mod=product_profile&controllers=product_profile&action=';
    var $modal = $('#info-basic-modal');
    if (!$modal.length) return;

    var productId = $modal.find('.info-edit').data('product-id');

    function flashOk($el) {
        $el.addClass('saved-ok');
        setTimeout(function () { $el.removeClass('saved-ok'); }, 700);
    }

    function saveBasic(field, value, $el) {
        $.ajax({
            url: ACT + 'ajax_update_product_basic',
            method: 'POST',
            data: { product_id: productId, field: field, value: value },
            dataType: 'json',
            success: function (res) {
                if (res && res.success) {
                    if ($el) flashOk($el);
                } else {
                    alert((res && res.message) || 'Lưu thất bại.');
                }
            },
            error: function () { alert('Lỗi kết nối khi lưu.'); }
        });
    }

    /* 1. Trường text: Đơn vị, Quy cách bao bì trong/ngoài */
    $modal.on('change', '.info-field', function () {
        var $f = $(this);
        saveBasic($f.data('field'), $f.val(), $f);
    });

    /* 2. Cập nhật / thêm hình ảnh */
    $modal.on('click', '.btn-update-image', function () {
        $(this).closest('.info-image-cell').find('.info-image-input').trigger('click');
    });

    $modal.on('change', '.info-image-input', function () {
        var file = this.files[0];
        if (!file) return;
        var $cell = $(this).closest('.info-image-cell');
        var fd = new FormData();
        fd.append('product_id', productId);
        fd.append('file', file);

        $.ajax({
            url: ACT + 'ajax_update_product_image',
            method: 'POST',
            data: fd,
            processData: false,
            contentType: false,
            dataType: 'json',
            success: function (res) {
                if (res && res.success) {
                    var $img = $cell.find('.info-thumb');
                    $img.attr('src', res.web_path + '?t=' + (new Date()).getTime()).show();
                } else {
                    alert((res && res.message) || 'Cập nhật ảnh thất bại.');
                }
            },
            error: function () { alert('Lỗi kết nối khi cập nhật ảnh.'); }
        });
        this.value = '';
    });

    /* 3. Bao bì trong/ngoài: tìm nguyên liệu -> lưu *_id */
    $modal.find('.pkg-picker').each(function () {
        var $wrap = $(this);
        var field = $wrap.data('field');
        var $input = $wrap.find('.pkg-search');
        var $results = $wrap.find('.search-results');
        var timer = null;

        $input.on('input', function () {
            var kw = (this.value || '').trim();
            clearTimeout(timer);
            if (kw.length < 1) { $results.hide().empty(); return; }
            timer = setTimeout(function () {
                $.ajax({
                    url: ACT + 'search_material',
                    method: 'POST',
                    data: { keyword: kw },
                    dataType: 'json',
                    success: function (res) {
                        $results.empty();
                        if (res.data && res.data.length) {
                            res.data.forEach(function (item) {
                                var $d = $('<div class="result-item"></div>')
                                    .text(item.material_name + ' — ' + (item.supplier_name || 'Không có NCC'));
                                $d.on('click', function () {
                                    $input.val(item.material_name);
                                    $wrap.data('id', item.id);
                                    $results.hide().empty();
                                    saveBasic(field, item.id, $input);
                                });
                                $results.append($d);
                            });
                            $results.show();
                        } else {
                            $results.html('<div class="no-result">Không tìm thấy nguyên liệu</div>').show();
                        }
                    }
                });
            }, 300);
        });

        // Xóa trắng ô -> gỡ liên kết (set NULL)
        $input.on('change', function () {
            if ((this.value || '').trim() === '') {
                $wrap.data('id', 0);
                saveBasic(field, 0, $input);
            }
        });
    });

    // Ẩn dropdown khi click ra ngoài picker
    $(document).on('mousedown', function (e) {
        $modal.find('.pkg-picker').each(function () {
            if (!$(this).is(e.target) && $(this).has(e.target).length === 0) {
                $(this).find('.search-results').hide();
            }
        });
    });

});
