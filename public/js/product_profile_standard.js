/* ============================================================
   product_profile_standard.js — Bộ hồ sơ sản phẩm chuẩn
   - Tìm kiếm sản phẩm (lọc card theo tên)
   - Xóa file sản phẩm / file thành phần (AJAX, ở lại trang)
   (Đổi tên: dùng product_profile_dragdrop.js; sắp xếp/xóa thành phần:
    dùng product_profile_sortable.js)
   Yêu cầu: jQuery
   ============================================================ */
$(function () {

    var ACT = '?mod=product_profile&controllers=product_profile&action=';

    // Tìm kiếm + lọc theo trạng thái công bố dinh dưỡng
    var $search = $('#sd-search');
    var $nutritionFilter = $('#sd-nutrition-filter');
    function applySdFilters() {
        var kw = ($search.val() || '').toLowerCase().trim();
        var mode = $nutritionFilter.val() || 'all';
        $('.sd-card').each(function () {
            var $card = $(this);
            var name = String($card.data('product-name') || '');
            var matchKeyword = name.indexOf(kw) !== -1;
            var matchNutrition = true;
            if (mode === 'has') {
                matchNutrition = String($card.data('has-nutrition')) === '1';
            } else if (mode === 'confirmed') {
                matchNutrition = String($card.data('nutrition-confirmed')) === '1';
            }
            $card.css('display', (matchKeyword && matchNutrition) ? '' : 'none');
        });
    }
    if ($search.length) $search.on('input', applySdFilters);
    if ($nutritionFilter.length) $nutritionFilter.on('change', applySdFilters);

    function delFile(action, $btn) {
        if (!window.confirm('Bạn có chắc muốn xóa file này?')) return;
        $.ajax({
            url: ACT + action,
            method: 'POST',
            data: { file_id: $btn.data('file-id') },
            dataType: 'json',
            success: function (res) {
                if (res && res.success) {
                    $btn.closest('.file-item').fadeOut(150, function () { $(this).remove(); });
                } else {
                    alert((res && res.message) || 'Xóa thất bại.');
                }
            },
            error: function () { alert('Lỗi kết nối khi xóa.'); }
        });
    }

    // Xóa file sản phẩm (product_files)
    $(document).on('click', '.sd-del-product-file', function () {
        delFile('ajax_delete_product_file', $(this));
    });

    // Xóa file thành phần (files)
    $(document).on('click', '.sd-del-comp-file', function () {
        delFile('ajax_delete_file', $(this));
    });

    // Xóa hóa đơn (material_invoices) — dùng lại delFile() nhưng data key là invoice-id, không phải file-id
    $(document).on('click', '.sd-del-invoice', function () {
        if (!window.confirm('Bạn có chắc muốn xóa hóa đơn này?')) return;
        var $btn = $(this);
        $.ajax({
            url: ACT + 'ajax_delete_material_invoice',
            method: 'POST',
            data: { invoice_id: $btn.data('invoice-id') },
            dataType: 'json',
            success: function (res) {
                if (res && res.success) {
                    $btn.closest('.file-item').fadeOut(150, function () { $(this).remove(); });
                } else {
                    alert((res && res.message) || 'Xóa thất bại.');
                }
            },
            error: function () { alert('Lỗi kết nối khi xóa.'); }
        });
    });

    /* ===== Thêm thành phần (modal tìm nguyên liệu, chọn nhiều) ===== */
    var sdAddProductId = 0;
    var sdPicked = [];
    var sdTimer = null;
    var $sdInput = $('.sd-msearch-input');
    var $sdResults = $('.sd-msearch-results');
    var $sdSelected = $('.sd-msearch-selected');

    function renderSdChips() {
        $sdSelected.empty();
        sdPicked.forEach(function (m, idx) {
            var $item = $('<div class="selected-material-item"></div>');
            var $info = $('<div class="material-info"></div>');
            $info.append($('<span class="material-name"></span>').text(m.name));
            $info.append($('<span class="supplier-name"></span>').text('NCC: ' + (m.supplier || 'Không có')));
            var $rm = $('<button type="button" class="btn-remove"><i class="fa-solid fa-xmark"></i></button>');
            $rm.on('click', function () { sdPicked.splice(idx, 1); renderSdChips(); });
            $item.append($info).append($rm);
            $sdSelected.append($item);
        });
    }

    // Mở modal cho đúng card -> reset trạng thái
    $(document).on('click', '.sd-add-material', function () {
        sdAddProductId = $(this).data('product-id');
        sdPicked = [];
        renderSdChips();
        $sdInput.val('');
        $sdResults.hide().empty();
    });

    $sdInput.on('input', function () {
        var kw = (this.value || '').trim();
        clearTimeout(sdTimer);
        if (kw.length < 1) { $sdResults.hide().empty(); return; }
        sdTimer = setTimeout(function () {
            $.ajax({
                url: ACT + 'search_material', method: 'POST', data: { keyword: kw }, dataType: 'json',
                success: function (res) {
                    $sdResults.empty();
                    if (res.data && res.data.length) {
                        res.data.forEach(function (item) {
                            if (sdPicked.some(function (m) { return m.id == item.id; })) return;
                            var $d = $('<div class="result-item"></div>')
                                .text(item.material_name + ' — ' + (item.supplier_name || 'Không có NCC'));
                            $d.on('click', function () {
                                sdPicked.push({ id: item.id, name: item.material_name, supplier: item.supplier_name || '' });
                                renderSdChips();
                                $sdInput.val('');
                                $sdResults.hide().empty();
                            });
                            $sdResults.append($d);
                        });
                        if (!$sdResults.children().length) $sdResults.html('<div class="no-result">Đã chọn hết kết quả</div>');
                        $sdResults.show();
                    } else {
                        $sdResults.html('<div class="no-result">Không tìm thấy nguyên liệu</div>').show();
                    }
                }
            });
        }, 300);
    });

    $(document).on('mousedown', function (e) {
        var $w = $('.sd-msearch');
        if ($w.length && !$w.is(e.target) && $w.has(e.target).length === 0) $sdResults.hide();
    });

    $(document).on('click', '.sd-add-comp-submit', function () {
        if (!sdAddProductId) return;
        if (!sdPicked.length) { alert('Vui lòng chọn ít nhất một nguyên liệu.'); return; }
        var ids = sdPicked.map(function (m) { return m.id; });
        var $btn = $(this);
        var orig = $btn.text();
        $btn.prop('disabled', true).text('Đang thêm...');
        $.ajax({
            url: ACT + 'ajax_add_composition', method: 'POST',
            data: { product_id: sdAddProductId, material_ids: ids }, dataType: 'json',
            success: function (res) {
                if (res && res.success) { window.location.reload(); }
                else { alert((res && res.message) || 'Thêm thất bại.'); $btn.prop('disabled', false).text(orig); }
            },
            error: function () { alert('Lỗi kết nối.'); $btn.prop('disabled', false).text(orig); }
        });
    });

});
