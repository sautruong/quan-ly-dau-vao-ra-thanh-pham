/* =====================================================================
   product_profile_standard_missing.js — modal "Hóa đơn, chứng từ còn thiếu"
   ở standard-dossier.php (Bộ hồ sơ sản phẩm chuẩn). KHÁC bản ở detail.php:
   - Đa sản phẩm (toàn bộ Bộ hồ sơ chuẩn), nạp qua AJAX mỗi lần MỞ modal
     (giống modal "Tần suất") thay vì server-render sẵn 1 sản phẩm.
   - Có thêm bộ lọc theo LOẠI hồ sơ thiếu (Tất cả/Hồ sơ sản phẩm/Hồ sơ
     nguyên liệu/Hồ sơ nhà cung cấp/Hóa đơn) và nút "Tải xuống" ZIP toàn bộ
     file HIỆN CÓ (không phải file thiếu) của các sản phẩm ĐANG HIỂN THỊ
     trên trang (tôn trọng ô tìm kiếm + bộ lọc dinh dưỡng của trang).
   Rename pencil (.btn-edit-filename) TÁI DÙNG handler có sẵn trong
   product_profile_dragdrop.js — không viết lại logic đổi tên.
   ===================================================================== */
(function ($) {
    'use strict';

    var $btn = $('#sd-btn-missing-docs');
    var $modal = $('#sd-missing-modal');
    if (!$btn.length || !$modal.length) return; // trang không có tính năng này

    var ACT = '?mod=product_profile&controllers=product_profile&action=';
    var $filter = $('#sd-missing-filter');
    var $downloadBtn = $('#sd-missing-download');
    var $body = $('#sd-missing-body');
    var $summary = $('#sd-missing-summary');

    function escHtml(s) {
        return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) {
            return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c];
        });
    }
    function openOverlay($m) {
        $m.addClass('is-open');
        $('body').css('overflow', 'hidden');
    }
    // Windows cấm các ký tự < > : " / \ | ? * trong tên file/thư mục.
    function sanitizeName(s) {
        return String(s || '').replace(/[<>:"/\\|?*]/g, '-').trim();
    }

    function entitySpan(kind, name) {
        if (!name) return '';
        return " '" + '<span class="missing-entity missing-entity-' + escHtml(kind) + '">' + escHtml(name) + '</span>' + "'";
    }

    function renderProduct(prod) {
        var pname = prod.product_name;
        var html = '<div class="sd-missing-group" data-product-id="' + prod.product_id + '">';
        html += '<div class="sd-missing-group-title">Sản phẩm: <span class="missing-entity missing-entity-product">' + escHtml(pname) + '</span>'
            + '<button type="button" class="sd-missing-group-del" title="Bỏ tạm sản phẩm này khỏi danh sách (chỉ để chụp/in, mở lại hiện đủ)">&times;</button>'
            + '</div>';

        var rows = '';
        (prod.items || []).forEach(function (row) {
            if (row.bom_id === 0) {
                // Dòng đặc biệt "thiếu hồ sơ sản phẩm" — hiển thị riêng, không nằm trong bảng thành phần.
                row.missing.forEach(function (m) {
                    html += '<div class="sd-missing-standalone" data-kind="' + escHtml(m.kind) + '">'
                        + '<span>Thiếu hồ sơ sản phẩm' + entitySpan('product', pname) + '</span>'
                        + '<button type="button" class="missing-item-del" title="Bỏ dòng (chỉ để chụp/in, không ảnh hưởng dữ liệu)">&times;</button>'
                        + '</div>';
                });
                return;
            }
            var cells = '';
            row.missing.forEach(function (m) {
                cells += '<li data-kind="' + escHtml(m.kind) + '">'
                    + '<span>' + escHtml(m.label) + entitySpan(m.entity_type, m.entity_name) + '</span>'
                    + '<button type="button" class="missing-item-del" title="Bỏ dòng (chỉ để chụp/in, không ảnh hưởng dữ liệu)">&times;</button>'
                    + '</li>';
            });
            var nameCell = '<a href="#" class="sd-material-name-link" data-material-id="' + (row.material_info_id || 0) + '" data-material-name="' + escHtml(row.material_name) + '" title="Xem sản phẩm nào dùng nguyên liệu này">' + escHtml(row.material_name) + '</a>';
            rows += '<tr><td>' + nameCell + '</td><td><ul class="missing-list">' + cells + '</ul></td></tr>';
        });

        if (rows) {
            html += '<table class="missing-docs-table"><thead><tr><th>Tên nguyên liệu</th><th>Hóa đơn, chứng từ thiếu</th></tr></thead><tbody>' + rows + '</tbody></table>';
        }
        html += '</div>';
        return html;
    }

    // "match" tính THẲNG từ data-kind của phần tử, KHÔNG dựa vào trạng thái hiện/ẩn hiện tại trên DOM —
    // nếu dựa vào :visible của con để quyết định hiện cha, đến khi đổi lại bộ lọc lần 2 mọi con sẽ luôn báo
    // :visible=false (vì cha đang display:none từ lần lọc trước), tạo vòng lặp tự khóa ẩn vĩnh viễn.
    function matches(el, mode) {
        return mode === 'all' || $(el).data('kind') === mode;
    }

    function applyFilter() {
        var mode = $filter.val() || 'all';
        var visibleCount = 0;
        $body.find('.sd-missing-standalone, .missing-list li').each(function () {
            var show = matches(this, mode);
            $(this).toggle(show);
            if (show) visibleCount++;
        });
        // <tr> hiện nếu có ít nhất 1 <li> con khớp bộ lọc (tính lại từ data-kind, không dùng :visible)
        $body.find('.missing-docs-table tbody tr').each(function () {
            var $tr = $(this);
            var hasMatch = $tr.find('.missing-list li').toArray().some(function (li) { return matches(li, mode); });
            $tr.toggle(hasMatch);
        });
        // Ẩn cả bảng (kể cả tiêu đề "Tên nguyên liệu"/"Hóa đơn, chứng từ thiếu") nếu không còn dòng nào khớp —
        // ví dụ chọn lọc "Hồ sơ sản phẩm" thì bảng thành phần rỗng, không nên vẫn hiện tiêu đề bảng trống.
        $body.find('.missing-docs-table').each(function () {
            var $table = $(this);
            var hasMatch = $table.find('.missing-list li').toArray().some(function (li) { return matches(li, mode); });
            $table.toggle(hasMatch);
        });
        // Ẩn cả nhóm sản phẩm nếu không còn gì khớp (cả standalone lẫn bảng)
        $body.find('.sd-missing-group').each(function () {
            var $g = $(this);
            var hasVisible = $g.find('.sd-missing-standalone').toArray().some(function (el) { return matches(el, mode); })
                || $g.find('.missing-list li').toArray().some(function (li) { return matches(li, mode); });
            $g.toggle(hasVisible);
        });
        $summary.html('Tổng số dòng thiếu: <b>' + visibleCount + '</b>');
    }

    function loadMissing() {
        $body.html('<p class="freq-loading">Đang tải...</p>');
        $.getJSON(ACT + 'ajax_missing_docs_standard', function (res) {
            var products = (res && res.products) || [];
            if (!products.length) {
                $body.html('<p class="missing-docs-empty">Không thiếu hồ sơ/hóa đơn nào.</p>');
                $summary.html('');
                return;
            }
            var html = '';
            products.forEach(function (p) { html += renderProduct(p); });
            $body.html(html);
            applyFilter();
        }).fail(function () {
            $body.html('<p class="freq-loading">Lỗi tải dữ liệu.</p>');
        });
    }

    $btn.on('click', function () {
        $filter.val('all');
        openOverlay($modal);
        loadMissing();
    });

    $filter.on('change', applyFilter);

    // Bỏ 1 dòng khỏi màn hình — chỉ tạm thời (để chụp/in gọn), mở lại modal luôn nạp lại đầy đủ qua AJAX.
    $(document).on('click', '#sd-missing-modal .missing-item-del', function () {
        var $row = $(this).closest('li, .sd-missing-standalone');
        var $group = $row.closest('.sd-missing-group');
        $row.remove();
        if ($group.find('.missing-list li').length === 0 && $group.find('.sd-missing-standalone').length === 0) {
            $group.remove();
        } else {
            $group.find('.missing-docs-table tbody tr').each(function () {
                var $tr = $(this);
                if (!$tr.find('.missing-list li').length) $tr.remove();
            });
        }
    });

    // Bỏ tạm CẢ 1 sản phẩm (cả nhóm) khỏi màn hình — chỉ để chụp/in gọn, mở lại modal luôn nạp lại đầy đủ qua AJAX.
    $(document).on('click', '#sd-missing-modal .sd-missing-group-del', function () {
        $(this).closest('.sd-missing-group').remove();
    });

    // "In" -> in khổ A4 (window.print, @media print chỉ hiện #sd-missing-capture-area — xem standard.css)
    $(document).on('click', '#sd-missing-print', function () {
        window.print();
    });

    // Click tên nguyên liệu -> modal "Sản phẩm dùng nguyên liệu này" (dùng chung #sd-material-products-modal
    // với modal "Hóa đơn, chứng từ đã có" — nút "In" của modal đó đã bind sẵn ở product_profile_standard_existing.js,
    // KHÔNG bind lại ở đây để tránh gọi window.print() 2 lần).
    var $mpModal2 = $('#sd-material-products-modal');
    var $mpBody2 = $('#sd-mp-body');
    var $mpName2 = $('#sd-mp-material-name');

    $(document).on('click', '#sd-missing-modal .sd-material-name-link', function (e) {
        e.preventDefault();
        var $link = $(this);
        $mpName2.text($link.data('material-name'));
        $mpBody2.html('<p class="freq-loading">Đang tải...</p>');
        openOverlay($mpModal2);
        $.getJSON(ACT + 'ajax_standard_products_using_material', { material_info_id: $link.data('material-id') }, function (res) {
            var products = (res && res.products) || [];
            if (!products.length) {
                $mpBody2.html('<p class="missing-docs-empty">Không có sản phẩm nào (trong Bộ hồ sơ chuẩn) dùng nguyên liệu này.</p>');
                return;
            }
            var html = '<ul class="sd-mp-list">';
            products.forEach(function (p) { html += '<li>' + escHtml(p.product_name) + '</li>'; });
            html += '</ul>';
            $mpBody2.html(html);
        }).fail(function () {
            $mpBody2.html('<p class="freq-loading">Lỗi tải dữ liệu.</p>');
        });
    });

    // "Chụp" -> chụp nội dung modal thành ảnh, copy vào clipboard để Ctrl+V dán sang app khác.
    $(document).on('click', '#sd-missing-capture', function () {
        var $capBtn = $(this);
        var area = document.getElementById('sd-missing-capture-area');
        if (!area) return;
        if (typeof window.html2canvas !== 'function') { alert('Không nạp được html2canvas. Kiểm tra mạng rồi thử lại.'); return; }
        if (!navigator.clipboard || typeof window.ClipboardItem !== 'function') { alert('Trình duyệt không hỗ trợ copy ảnh vào clipboard.'); return; }
        var orig = $capBtn.html();
        $capBtn.html('Đang xử lý...').prop('disabled', true);
        window.html2canvas(area, { scale: 2, backgroundColor: '#ffffff', useCORS: true }).then(function (canvas) {
            canvas.toBlob(function (blob) {
                navigator.clipboard.write([new ClipboardItem({ 'image/png': blob })]).then(function () {
                    alert('Đã copy ảnh vào clipboard.\nMở app khác (Zalo, Messenger...) và bấm Ctrl+V để dán.');
                }).catch(function () {
                    alert('Không copy được vào clipboard.');
                }).then(function () {
                    $capBtn.html(orig).prop('disabled', false);
                });
            });
        }).catch(function () {
            alert('Chụp ảnh thất bại.');
            $capBtn.html(orig).prop('disabled', false);
        });
    });

    /* ---------------- "Tải xuống" ZIP — chỉ các sản phẩm ĐANG HIỂN THỊ trên trang ---------------- */
    $downloadBtn.on('click', function () {
        if (typeof window.JSZip !== 'function') { alert('Không nạp được JSZip. Kiểm tra mạng rồi thử lại.'); return; }

        var productIds = [];
        $('.sd-card').each(function () {
            var $card = $(this);
            if ($card.css('display') !== 'none') productIds.push($card.data('product-id'));
        });
        if (!productIds.length) { alert('Không có sản phẩm nào đang hiển thị để tải.'); return; }

        var orig = $downloadBtn.html();
        $downloadBtn.html('Đang chuẩn bị...').prop('disabled', true);

        $.ajax({
            url: ACT + 'ajax_all_files_standard',
            method: 'POST',
            data: { product_ids: productIds },
            dataType: 'json'
        }).then(function (res) {
            var products = (res && res.products) || [];
            if (!products.length) { alert('Không có file nào để tải.'); return $.Deferred().reject().promise(); }

            $downloadBtn.html('Đang nén...');
            var zip = new JSZip();
            var chain = Promise.resolve();

            function addFile(folder, item) {
                chain = chain.then(function () {
                    return fetch(item.download_url).then(function (r) {
                        if (!r.ok) throw new Error('Không tải được: ' + item.display_name);
                        return r.blob();
                    }).then(function (blob) {
                        var ext = item.ext || '';
                        var safeName = sanitizeName(item.display_name);
                        var fname = ext && safeName.slice(-(ext.length + 1)) !== ('.' + ext) ? safeName + '.' + ext : safeName;
                        folder.file(fname, blob);
                    });
                });
            }

            products.forEach(function (p) {
                var pFolder = zip.folder(sanitizeName(p.product_name));
                var pfFolder = pFolder.folder('Hồ sơ sản phẩm');
                (p.product_files || []).forEach(function (f) { addFile(pfFolder, f); });

                if ((p.composition || []).length) {
                    var compFolder = pFolder.folder('Thành phần cấu tạo');
                    var supFolder = compFolder.folder('Hồ sơ nhà cung cấp');
                    var matFolder = compFolder.folder('Hồ sơ nguyên liệu');
                    var invFolder = compFolder.folder('Hóa đơn');
                    p.composition.forEach(function (item) {
                        (item.supplier || []).forEach(function (f) { addFile(supFolder, f); });
                        (item.material || []).forEach(function (f) { addFile(matFolder, f); });
                        (item.invoice || []).forEach(function (f) { addFile(invFolder, f); });
                    });
                }
            });

            return chain.then(function () { return zip.generateAsync({ type: 'blob' }); }).then(function (blob) {
                var a = document.createElement('a');
                a.href = URL.createObjectURL(blob);
                a.download = 'bo_ho_so_chuan.zip';
                document.body.appendChild(a);
                a.click();
                a.remove();
            });
        }).catch(function (err) {
            if (err) {
                console.error('Tải ZIP lỗi:', err);
                alert('Lỗi khi tải file: ' + (err && err.message ? err.message : err));
            }
        }).then(function () {
            $downloadBtn.html(orig).prop('disabled', false);
        });
    });

})(jQuery);
