/* ============================================================
   product_profile_company.js  — Hồ sơ doanh nghiệp
   Modal: tên hồ sơ + kéo-thả nhiều file (mỗi file có nút 'x') + Thêm
   -> upload vào files (entity_type='company_profile'); xóa file.
   Yêu cầu: jQuery
   ============================================================ */
$(function () {

    var ACT = '?mod=product_profile&controllers=product_profile&action=';
    var selectedFiles = [];

    var $dz = $('#cp-dropzone');
    var $input = $('#cp-file-input');
    var $list = $('#cp-selected');

    // Click vùng thả -> mở hộp chọn file
    $dz.on('click', function () { $input.trigger('click'); });
    $input.on('change', function () { addFiles(this.files); this.value = ''; });

    // Kéo-thả
    $dz.on('dragenter dragover', function (e) {
        e.preventDefault(); e.stopPropagation();
        $dz.addClass('is-dragover');
    });
    $dz.on('dragleave', function (e) {
        e.preventDefault(); e.stopPropagation();
        $dz.removeClass('is-dragover');
    });
    $dz.on('drop', function (e) {
        e.preventDefault(); e.stopPropagation();
        $dz.removeClass('is-dragover');
        var f = e.originalEvent.dataTransfer ? e.originalEvent.dataTransfer.files : null;
        if (f) addFiles(f);
    });

    function addFiles(fileList) {
        for (var i = 0; i < fileList.length; i++) selectedFiles.push(fileList[i]);
        render();
    }

    function render() {
        $list.empty();
        selectedFiles.forEach(function (f, idx) {
            var $li = $('<li class="cp-sel-item"></li>');
            $li.append($('<span class="cp-sel-name"></span>').text(f.name));
            var $x = $('<button type="button" class="cp-sel-remove" title="Bỏ file">&times;</button>');
            $x.on('click', function () {
                selectedFiles.splice(idx, 1);
                render();
            });
            $li.append($x);
            $list.append($li);
        });
    }

    // Submit -> upload
    $('#company-form').on('submit', function (e) {
        e.preventDefault();
        var name = ($('#cp-group-name').val() || '').trim();
        if (name === '') { alert('Vui lòng đặt tên hồ sơ.'); return; }
        if (!selectedFiles.length) { alert('Vui lòng chọn ít nhất một file.'); return; }

        var fd = new FormData();
        fd.append('group_name', name);
        selectedFiles.forEach(function (f) { fd.append('files[]', f); });

        var $btn = $('.cp-submit');
        var orig = $btn.text();
        $btn.prop('disabled', true).text('Đang thêm...');

        $.ajax({
            url: ACT + 'ajax_add_company_profile',
            method: 'POST', data: fd, processData: false, contentType: false, dataType: 'json',
            success: function (res) {
                if (res && res.success) {
                    window.location.reload();
                } else {
                    alert((res && res.message) || 'Thêm thất bại.');
                    $btn.prop('disabled', false).text(orig);
                }
            },
            error: function () {
                alert('Lỗi kết nối khi thêm hồ sơ.');
                $btn.prop('disabled', false).text(orig);
            }
        });
    });

    // Xóa file
    $(document).on('click', '.cp-delete-file', function () {
        var $btn = $(this);
        var id = $btn.data('file-id');
        if (!window.confirm('Bạn có chắc muốn xóa file này?')) return;

        $.ajax({
            url: ACT + 'ajax_delete_file', method: 'POST', data: { file_id: id }, dataType: 'json',
            success: function (res) {
                if (res && res.success) {
                    var $grp = $btn.closest('.cp-group');
                    $btn.closest('.cp-file').remove();
                    if ($grp.find('.cp-file').length === 0) $grp.remove();
                } else {
                    alert('Xóa thất bại.');
                }
            },
            error: function () { alert('Lỗi kết nối khi xóa.'); }
        });
    });

    // Tải file lên cho 1 hồ sơ (group) sẵn có — dùng lại endpoint thêm hồ sơ.
    $(document).on('click', '.cp-upload-btn', function () {
        $(this).siblings('.cp-upload-input').trigger('click');
    });

    $(document).on('change', '.cp-upload-input', function () {
        var input = this;
        if (!input.files || !input.files.length) return;

        var $group    = $(input).closest('.cp-group');
        var groupName = $group.attr('data-group-name');
        var $btn      = $group.find('.cp-upload-btn');
        var orig      = $btn.html();

        var fd = new FormData();
        fd.append('group_name', groupName);
        for (var i = 0; i < input.files.length; i++) fd.append('files[]', input.files[i]);

        $btn.prop('disabled', true).html('<i class="fa-solid fa-spinner fa-spin"></i> Đang tải...');
        $.ajax({
            url: ACT + 'ajax_add_company_profile',
            method: 'POST', data: fd, processData: false, contentType: false, dataType: 'json',
            success: function (res) {
                if (res && res.success) {
                    window.location.reload();
                } else {
                    alert((res && res.message) || 'Tải lên thất bại.');
                    $btn.prop('disabled', false).html(orig);
                }
            },
            error: function () {
                alert('Lỗi kết nối khi tải lên.');
                $btn.prop('disabled', false).html(orig);
            }
        });
        input.value = '';
    });

});
