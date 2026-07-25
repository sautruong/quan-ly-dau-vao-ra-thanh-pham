/* ============================================================
   product_profile_sortable.js
   Kéo-sắp xếp các .ingredient-item trong .list-ingredient (kiểu Trello).
   Hỗ trợ NHIỀU .list-ingredient trên cùng trang (mỗi card 1 list).
   - Chỉ kéo được khi giữ tay nắm .drag-handle, và chỉ trong cùng list.
   - Thả xong -> lưu thứ tự (position) vào bill_of_materials.
   - Nút 'x' xóa thành phần (xóa row bill_of_materials).
   Yêu cầu: jQuery
   ============================================================ */
$(function () {

    var ACT = '?mod=product_profile&controllers=product_profile&action=';
    if (!$('.list-ingredient').length) return;

    var dragEl = null;
    var dragList = null; // list chứa phần tử đang kéo

    // Chỉ cho phép kéo khi nhấn vào tay nắm
    $(document).on('mousedown', '.drag-handle', function () {
        $(this).closest('.ingredient-item').attr('draggable', 'true');
    });
    $(document).on('mouseup', function () {
        $('.ingredient-item[draggable="true"]').attr('draggable', 'false');
    });

    $(document).on('dragstart', '.ingredient-item', function (e) {
        dragEl = this;
        dragList = $(this).closest('.list-ingredient')[0];
        this.classList.add('dragging');
        var dt = e.originalEvent.dataTransfer;
        if (dt) {
            dt.effectAllowed = 'move';
            dt.setData('text/plain', $(this).data('bom-id') || '');
        }
    });

    $(document).on('dragend', '.ingredient-item', function () {
        this.classList.remove('dragging');
        $(this).attr('draggable', 'false');
        var $list = dragList ? $(dragList) : null;
        dragEl = null;
        dragList = null;
        if ($list) saveOrder($list);
    });

    // Sắp xếp trực tiếp khi kéo (chỉ trong cùng list)
    $(document).on('dragover', '.list-ingredient', function (e) {
        if (!dragEl || this !== dragList) return; // bỏ qua kéo-thả FILE & kéo sang card khác
        e.preventDefault();
        var container = this;
        var after = getDragAfterElement(container, e.originalEvent.clientY);
        if (after == null) {
            if (container.lastElementChild !== dragEl) container.appendChild(dragEl);
        } else if (after !== dragEl) {
            container.insertBefore(dragEl, after);
        }
    });

    function getDragAfterElement(container, y) {
        var els = Array.prototype.slice.call(
            container.querySelectorAll('.ingredient-item:not(.dragging)')
        );
        var closest = { offset: -Infinity, element: null };
        els.forEach(function (child) {
            var box = child.getBoundingClientRect();
            var offset = y - box.top - box.height / 2;
            if (offset < 0 && offset > closest.offset) {
                closest = { offset: offset, element: child };
            }
        });
        return closest.element;
    }

    // Xóa thành phần (nút 'x')
    $(document).on('click', '.btn-delete-composition', function () {
        var $btn = $(this);
        var bomId = $btn.data('bom-id');
        var name = $btn.data('name') || 'này';
        var productId = $btn.closest('.list-ingredient').data('product-id');

        if (!window.confirm("Bạn có chắc chắn muốn xóa thành phần '" + name + "' khỏi sản phẩm?")) {
            return;
        }

        $.ajax({
            url: ACT + 'ajax_delete_composition',
            method: 'POST',
            data: { bom_id: bomId, product_id: productId },
            dataType: 'json',
            success: function (res) {
                if (res && res.success) {
                    $btn.closest('.ingredient-item').fadeOut(150, function () { $(this).remove(); });
                } else {
                    alert((res && res.message) || 'Xóa thành phần thất bại.');
                }
            },
            error: function () { alert('Lỗi kết nối khi xóa thành phần.'); }
        });
    });

    function saveOrder($list) {
        var productId = $list.data('product-id');
        var order = [];
        $list.find('.ingredient-item').each(function () {
            var id = $(this).data('bom-id');
            if (id) order.push(id);
        });
        if (!order.length) return;

        $.ajax({
            url: ACT + 'ajax_reorder_composition',
            method: 'POST',
            data: { product_id: productId, order: order },
            dataType: 'json',
            success: function (res) {
                if (res && res.success) {
                    $list.addClass('reorder-ok');
                    setTimeout(function () { $list.removeClass('reorder-ok'); }, 500);
                } else {
                    alert((res && res.message) || 'Lưu thứ tự thất bại.');
                }
            },
            error: function () { alert('Lỗi kết nối khi lưu thứ tự.'); }
        });
    }

});
