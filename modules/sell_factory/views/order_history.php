<?php

/** @var array $history */
$history = isset($history) ? $history : ['rows' => [], 'total_pages' => 0, 'page' => 1];
?>
<!DOCTYPE html>
<html lang="vi">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Lịch sử đặt hàng</title>
    <link rel="icon" href="public/images/logo/logo_vat_png.png" type="image/png">
    <link rel="stylesheet" href="<?php echo asset_ver('public/css/reset.css'); ?>">
    <link rel="stylesheet" href="<?php echo asset_ver('public/css/all.css'); ?>">
    <link rel="stylesheet" href="<?php echo asset_ver('public/css/global.css'); ?>">
    <link rel="stylesheet" href="<?php echo asset_ver('public/css/sell-factory/order-factory.css'); ?>">
    <link rel="stylesheet" href="<?php echo asset_ver('public/css/shared/app_shell.css'); ?>">
    <script src="<?php echo asset_ver('public/js/jquery-4.0.0.js'); ?>"></script>
</head>

<body>
    <div id="wrapper">
        <?php get_sidebar('app'); ?>
        <?php get_header('app'); ?>

        <div class="of-page">
            <?php /* Đã gỡ thanh tab — 3 view đã thành 3 mục menu cấp 2 ở sidebar. */ ?>
            <div class="of-history-wrap">
                <h2 class="of-block-title">Lịch sử đặt hàng của tôi</h2>
                <table class="of-history-table">
                    <thead>
                        <tr>
                            <th>Ngày</th>
                            <th>Chi nhánh</th>
                            <th>Diễn giải</th>
                            <th>Ghi chú</th>
                            <th>Trạng thái</th>
                            <th class="text-center">Thao tác</th>
                        </tr>
                    </thead>
                    <tbody id="ofh-body">
                        <?php foreach ($history['rows'] as $h):
                            $picked = !empty($h['picked']);
                            $locked = !empty($h['locked']);
                            $skipped = !empty($h['factory_hidden']) && !$picked; // nhà máy đã bỏ qua
                            $editable = !$picked && !$locked && empty($h['factory_hidden']);
                            $can_redo = $picked || !empty($h['factory_hidden']);   // đã bốc / nhà máy đã xử lý
                        ?>
                            <tr data-id="<?= (int) $h['id'] ?>">
                                <td><?= date('d/m/Y H:i', strtotime($h['created_at'])) ?></td>
                                <td><?= htmlspecialchars((string) ($h['customer_name'] ?? ''), ENT_QUOTES, 'UTF-8') ?></td>
                                <td><?= htmlspecialchars((string) $h['description'], ENT_QUOTES, 'UTF-8') ?></td>
                                <td><?= htmlspecialchars((string) ($h['note'] ?? ''), ENT_QUOTES, 'UTF-8') ?></td>
                                <td>
                                    <?php if ($picked): ?><span class="ofh-badge picked">Đã bốc</span>
                                    <?php elseif ($skipped): ?><span class="ofh-badge skipped">Nhà máy đã bỏ qua</span>
                                    <?php elseif (!empty($h['confirmed'])): ?><span class="ofh-badge confirmed">Đã xác nhận</span>
                                    <?php elseif ($locked): ?><span class="ofh-badge locked">Đã khóa</span>
                                    <?php else: ?><span class="ofh-badge open">Có thể sửa</span><?php endif; ?>
                                    <?php if (!empty($h['pickup_time'])): ?>
                                        <div class="ofh-pickup">Hẹn bốc: <?= date('H:i d/m/Y', strtotime($h['pickup_time'])) ?></div>
                                    <?php endif; ?>
                                </td>
                                <td class="text-center">
                                    <?php if ($editable): ?>
                                        <a href="#" class="ofh-edit" data-id="<?= (int) $h['id'] ?>">Chỉnh sửa</a>
                                    <?php endif; ?>
                                    <?php if ($can_redo): ?>
                                        <a href="?mod=sell_factory&controllers=sell_factory&action=order_factory" class="ofh-redo" data-id="<?= (int) $h['id'] ?>">Đặt lại</a>
                                    <?php endif; ?>
                                    <a href="#" class="btn-delete-history ofh-del" data-id="<?= (int) $h['id'] ?>">Xóa</a>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                        <?php if (empty($history['rows'])): ?>
                            <tr><td colspan="6" style="text-align:center;color:gray;padding:24px;">Bạn chưa có đơn đặt hàng nào.</td></tr>
                        <?php endif; ?>
                    </tbody>
                </table>

                <div class="of-history-pagination">
                    <?php if ($history['total_pages'] > 1): ?>
                        <?php for ($i = 1; $i <= $history['total_pages']; $i++): ?>
                            <a href="?mod=sell_factory&controllers=sell_factory&action=order_history&page=<?= $i ?>"
                               class="page-link <?= $i === $history['page'] ? 'active' : '' ?>"><?= $i ?></a>
                        <?php endfor; ?>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>

    <!-- ====== MODAL: CHỈNH SỬA ĐƠN HÀNG ====== -->
    <div class="modal-mask of-cart-modal" id="modal-edit" style="display:none;">
        <div class="modal-box of-cart-box">
            <span class="modal-close" data-close="modal-edit">&times;</span>
            <div class="of-cart-head"><h3><i class="fa-solid fa-pen-to-square"></i> Chỉnh sửa đơn hàng</h3></div>
            <div class="of-cart-body">
                <div class="of-note-field">
                    <label>Chi nhánh:</label>
                    <div id="edit-cust" style="font-weight:600;"></div>
                </div>
                <div class="wp-search-goods">
                    <i class="fa-brands fa-sistrix"></i>
                    <input type="text" id="edit-search-goods" autocomplete="off" placeholder="Thêm sản phẩm...">
                    <ul class="dropdown-suggest" id="edit-goods-suggest"></ul>
                </div>
                <div class="wp-list-order">
                    <table id="edit-order-table">
                        <thead>
                            <tr><td>Tên sản phẩm</td><td>Đơn vị</td><td>Số lượng</td><td>Khối lượng (kg)</td><td></td></tr>
                        </thead>
                        <tbody></tbody>
                    </table>
                    <div class="wp-total">
                        <div class="weight-total"><div class="label"><p>Tổng khối lượng:</p></div><div class="result"><span id="edit-weight-total">0 kg</span></div></div>
                        <div class="value-total"><div class="label"><p>GTHH:</p></div><div class="result"><span id="edit-value-total">0 đ</span></div></div>
                    </div>
                </div>
                <div class="of-note-field">
                    <label for="edit-note">Ghi chú đơn hàng:</label>
                    <textarea id="edit-note" rows="2"></textarea>
                </div>
                <div class="wp-button">
                    <button class="share-factory" id="btn-save-edit">Lưu thay đổi</button>
                </div>
            </div>
        </div>
    </div>

    <script>
    (function ($) {
        var BASE = '?mod=sell_factory&controllers=sell_factory&action=';
        var editId = 0;
        function money(n){ return (Number(n)||0).toLocaleString('vi-VN',{maximumFractionDigits:0}); }
        function wt(n){ return (Number(n)||0).toLocaleString('vi-VN',{maximumFractionDigits:2}); }
        function openM(id){ $('#'+id).fadeIn(120); }
        function closeM(id){ $('#'+id).fadeOut(120); }
        $(document).on('click', '.modal-close', function(){ closeM($(this).data('close')); });
        $(document).on('click', '.modal-mask', function(e){ if(e.target===this) $(this).fadeOut(120); });

        // Xóa đơn.
        $(document).on('click', '.ofh-del', function (e) {
            e.preventDefault();
            var id = $(this).data('id');
            if (!confirm('Xóa đơn này khỏi lịch sử? Thao tác không thể hoàn tác.')) return;
            var $row = $(this).closest('tr');
            $.post(BASE + 'delete_history', { id: id }, function (res) {
                if (res && res.ok) $row.remove();
                else alert((res && res.msg) || 'Không xóa được.');
            }, 'json').fail(function () { alert('Lỗi mạng/Server.'); });
        });
        // Đặt lại.
        $(document).on('click', '.ofh-redo', function (e) {
            e.preventDefault();
            var id = $(this).data('id');
            try { sessionStorage.setItem('sf_reorder_id', id); } catch (err) {}
            window.location.href = $(this).attr('href');
        });

        /* ---------- Chỉnh sửa đơn ---------- */
        $(document).on('click', '.ofh-edit', function (e) {
            e.preventDefault();
            var id = $(this).data('id');
            $.getJSON(BASE + 'history_detail', { id: id }, function (res) {
                if (!res.ok || !res.data) return;
                var d = res.data;
                if (Number(d.picked) === 1 || Number(d.locked) === 1) { alert('Đơn đã bị khóa hoặc đã bốc, không thể sửa.'); return; }
                editId = d.id;
                $('#edit-cust').text(d.customer_name || '');
                $('#edit-note').val(d.note || '');
                $('#edit-order-table tbody').empty();
                (d.order_items || []).forEach(function (it) {
                    appendRow(it, !!it.material_id, it.qt_order, it.line_weight);
                });
                recalc();
                openM('modal-edit');
            });
        });

        function appendRow(d, isMat, qtyVal, lineW) {
            var idAttr = isMat ? 'data-material-id="' + (d.material_id||d.product_id) + '"' : 'data-product-id="' + d.product_id + '"';
            var suffix = isMat ? ' <span class="muted">(NVL)</span>' : '';
            var tr = '<tr ' + idAttr + ' data-weight-kg="' + d.weight_kg + '" data-system-price="' + d.system_price + '">'
                   + '<td class="text-left">' + d.product_name + suffix + '</td>'
                   + '<td>' + (d.unit || '') + '</td>'
                   + '<td><input type="number" class="qt-input" min="0" step="1" value="' + (qtyVal!=null?qtyVal:'') + '"></td>'
                   + '<td class="td-weight">' + (lineW!=null?wt(lineW):'0') + '</td>'
                   + '<td class="text-center"><span class="btn-remove-row" title="Xóa">&times;</span></td>'
                   + '</tr>';
            $('#edit-order-table tbody').append(tr);
        }
        $(document).on('input', '#edit-order-table .qt-input', function () {
            var $tr = $(this).closest('tr');
            var qty = parseFloat($(this).val())||0, wkg = parseFloat($tr.data('weight-kg'))||0;
            $tr.find('.td-weight').text(wt(qty*wkg)); recalc();
        });
        $(document).on('click', '#edit-order-table .btn-remove-row', function(){ $(this).closest('tr').remove(); recalc(); });
        function recalc() {
            var w=0,v=0;
            $('#edit-order-table tbody tr').each(function(){
                var $t=$(this), q=parseFloat($t.find('.qt-input').val())||0;
                w += q*(parseFloat($t.data('weight-kg'))||0);
                v += q*(parseFloat($t.data('system-price'))||0);
            });
            $('#edit-weight-total').text(wt(w)+' kg'); $('#edit-value-total').text(money(v)+' đ');
        }

        // Thêm SP vào đơn đang sửa.
        var t=null;
        $(document).on('input', '#edit-search-goods', function () {
            clearTimeout(t);
            var kw = String($(this).val()||'').trim();
            if(!kw){ $('#edit-goods-suggest').empty().hide(); return; }
            t = setTimeout(function(){
                $.getJSON(BASE+'search_product',{keyword:kw},function(res){
                    if(!res.ok) return;
                    var html='';
                    $.each(res.data,function(i,p){
                        var tag = p.type==='material'?' · NVL':'';
                        html += '<li data-type="'+p.type+'" data-id="'+p.ref_id+'">'+p.name+' <span class="muted">('+(p.unit||'')+tag+')</span></li>';
                    });
                    $('#edit-goods-suggest').html(html).toggle(html!=='');
                });
            },250);
        });
        $(document).on('click', '#edit-goods-suggest li', function () {
            var type=$(this).data('type'), id=$(this).data('id');
            $('#edit-search-goods').val(''); $('#edit-goods-suggest').empty().hide();
            var action = type==='material'?'material_order_info':'product_order_info';
            var key = type==='material'?'material_id':'product_id';
            var q={}; q[key]=id;
            if ($('#edit-order-table tbody tr['+(type==='material'?'data-material-id':'data-product-id')+'="'+id+'"]').length) return;
            $.getJSON(BASE+action, q, function(res){
                if(!res.ok||!res.data) return;
                appendRow(res.data, type==='material'); recalc();
            });
        });
        $(document).on('click', function(e){ if(!$(e.target).closest('.wp-search-goods').length) $('#edit-goods-suggest').hide(); });

        // Lưu thay đổi.
        $(document).on('click', '#btn-save-edit', function () {
            var items=[], w=0, v=0;
            $('#edit-order-table tbody tr').each(function(){
                var $t=$(this), q=parseFloat($t.find('.qt-input').val())||0;
                if(q<=0) return;
                var wkg=parseFloat($t.data('weight-kg'))||0, price=parseFloat($t.data('system-price'))||0;
                var mid=$t.data('material-id'), pid=$t.data('product-id');
                items.push({ product_id: pid!=null?pid:null, material_id: mid!=null?mid:null,
                    type: mid!=null?'material':'product', product_name: $t.find('td').eq(0).text(),
                    unit: $t.find('td').eq(1).text(), qt_order:q, weight_kg:wkg, system_price:price,
                    line_weight:q*wkg, line_value:q*price });
                w+=q*wkg; v+=q*price;
            });
            if(!items.length){ alert('Đơn phải có ít nhất 1 sản phẩm.'); return; }
            var $btn=$(this).prop('disabled',true);
            $.ajax({ url:BASE+'update_order', method:'POST', contentType:'application/json',
                data: JSON.stringify({ id:editId, items:items, weight_total:w, value_total:v, note:$('#edit-note').val()||'' }), dataType:'json'
            }).done(function(res){
                if(res&&res.ok){ alert('Đã lưu và gửi thông báo cho nhà máy.'); location.reload(); }
                else { alert((res&&res.msg)||'Không lưu được.'); $btn.prop('disabled',false); }
            }).fail(function(){ alert('Lỗi mạng/Server.'); $btn.prop('disabled',false); });
        });

        /* ---------- Realtime: nhà máy khóa/bốc/xác nhận/bỏ qua -> cập nhật ngay ---------- */
        function statusBadge(picked, locked, confirmed, skipped) {
            if (picked)    return '<span class="ofh-badge picked">Đã bốc</span>';
            if (skipped)   return '<span class="ofh-badge skipped">Nhà máy đã bỏ qua</span>';
            if (confirmed) return '<span class="ofh-badge confirmed">Đã xác nhận</span>';
            if (locked)    return '<span class="ofh-badge locked">Đã khóa</span>';
            return '<span class="ofh-badge open">Có thể sửa</span>';
        }
        function refreshStatuses() {
            $.getJSON(BASE + 'order_statuses', function (res) {
                if (!res || !res.ok) return;
                $.each(res.data || [], function (i, s) {
                    var id = Number(s.id);
                    var $row = $('#ofh-body tr[data-id="' + id + '"]');
                    if (!$row.length) return;
                    // Đang mở modal sửa CHÍNH đơn này -> đừng đụng để không phá thao tác.
                    if (editId && Number(editId) === id && $('#modal-edit').is(':visible')) return;

                    var picked = Number(s.picked) === 1, locked = Number(s.locked) === 1,
                        confirmed = Number(s.confirmed) === 1, fhidden = Number(s.factory_hidden) === 1;
                    var skipped = fhidden && !picked;
                    var editable = !picked && !locked && !fhidden;
                    var canRedo = picked || fhidden;

                    // Cột Trạng thái (giữ lại dòng "Hẹn bốc" nếu có).
                    var $st = $row.find('td').eq(4);
                    var $pickup = $st.find('.ofh-pickup');
                    $st.html(statusBadge(picked, locked, confirmed, skipped));
                    if ($pickup.length) $st.append($pickup);

                    // Cột Thao tác.
                    var act = '';
                    if (editable) act += '<a href="#" class="ofh-edit" data-id="' + id + '">Chỉnh sửa</a> ';
                    if (canRedo)  act += '<a href="?mod=sell_factory&controllers=sell_factory&action=order_factory" class="ofh-redo" data-id="' + id + '">Đặt lại</a> ';
                    act += '<a href="#" class="btn-delete-history ofh-del" data-id="' + id + '">Xóa</a>';
                    $row.find('td').eq(5).html(act);
                });
            });
        }
        setInterval(refreshStatuses, 5000);
    })(jQuery);
    </script>
    <script src="<?php echo asset_ver('public/js/shared/app_shell.js'); ?>"></script>
</body>

</html>
