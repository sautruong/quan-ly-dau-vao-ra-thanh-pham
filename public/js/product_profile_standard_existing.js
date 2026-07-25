/* =====================================================================
   product_profile_standard_existing.js — modal "Hóa đơn, chứng từ đã có"
   ở standard-dossier.php (Bộ hồ sơ sản phẩm chuẩn). NGƯỢC với modal "Hóa
   đơn, chứng từ còn thiếu" (product_profile_standard_missing.js): liệt kê
   file HIỆN CÓ (không phải file thiếu), dùng lại đúng dữ liệu/action
   `ajax_all_files_standard` (không truyền product_ids -> mặc định TOÀN BỘ
   sản phẩm trong Bộ hồ sơ chuẩn), vốn đã được xây cho tính năng "Tải
   xuống" ZIP của modal kia.
   Rename pencil (.btn-edit-filename) TÁI DÙNG handler có sẵn trong
   product_profile_dragdrop.js — không viết lại logic đổi tên.
   ===================================================================== */
(function ($) {
    'use strict';

    var $btn = $('#sd-btn-existing-docs');
    var $modal = $('#sd-existing-modal');
    if (!$btn.length || !$modal.length) return; // trang không có tính năng này

    var ACT = '?mod=product_profile&controllers=product_profile&action=';
    var $filter = $('#sd-existing-filter');
    var $downloadBtn = $('#sd-existing-download');
    var $body = $('#sd-existing-body');
    var $summary = $('#sd-existing-summary');

    function escHtml(s) {
        return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) {
            return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c];
        });
    }
    function openOverlay($m) {
        $m.addClass('is-open');
        $('body').css('overflow', 'hidden');
    }
    function sanitizeName(s) {
        return String(s || '').replace(/[<>:"/\\|?*]/g, '-').trim();
    }
    // kind nội bộ (product/supplier/material/invoice) -> data-kind cho bút sửa tên. 'product' KHÔNG được giữ
    // nguyên (xem gotcha ở product_profile_frequency.js: trùng giá trị 'product' mà list.php dùng với kiểu
    // hiển thị khác) -> dịch sang 'pfile'.
    function renameKind(kind) {
        if (kind === 'invoice') return 'invoice';
        if (kind === 'product') return 'pfile';
        return 'composition';
    }
    function fileLinkHtml(f) {
        return '<span class="file-name-edit sd-existing-file">'
            + '<a href="' + escHtml(f.download_url) + '" target="_blank">' + escHtml(f.display_name) + '</a>'
            + '<a href="#" class="btn-edit-filename" data-kind="' + renameKind(f.kind) + '" data-file-id="' + f.id + '" title="Sửa tên file">'
            + '<i class="fa-solid fa-pen"></i></a>'
            + '</span>';
    }

    function renderProduct(prod) {
        var html = '<div class="sd-missing-group" data-product-id="' + prod.product_id + '">';
        html += '<div class="sd-missing-group-title">Sản phẩm: <span class="missing-entity missing-entity-product">' + escHtml(prod.product_name) + '</span>'
            + '<button type="button" class="sd-missing-group-del" title="Bỏ tạm sản phẩm này khỏi danh sách (chỉ để chụp/in, mở lại hiện đủ)">&times;</button>'
            + '</div>';

        if ((prod.product_files || []).length) {
            html += '<div class="sd-missing-standalone sd-existing-line" data-kind="product">'
                + '<span class="sd-existing-label">Hồ sơ sản phẩm:</span> '
                + prod.product_files.map(fileLinkHtml).join(' ')
                + '</div>';
        }

        var rows = '';
        (prod.composition || []).forEach(function (item) {
            var cells = '';
            if ((item.supplier || []).length) {
                cells += '<li data-kind="supplier"><span class="sd-existing-label">Hồ sơ nhà cung cấp:</span> ' + item.supplier.map(fileLinkHtml).join(' ') + '</li>';
            }
            if ((item.material || []).length) {
                cells += '<li data-kind="material"><span class="sd-existing-label">Hồ sơ nguyên liệu:</span> ' + item.material.map(fileLinkHtml).join(' ') + '</li>';
            }
            if ((item.invoice || []).length) {
                cells += '<li data-kind="invoice"><span class="sd-existing-label">Hóa đơn:</span> ' + item.invoice.map(fileLinkHtml).join(' ') + '</li>';
            }
            if (cells) {
                var nameCell = '<a href="#" class="sd-material-name-link" data-material-id="' + (item.material_info_id || 0) + '" data-material-name="' + escHtml(item.material_name) + '" title="Xem sản phẩm nào dùng nguyên liệu này">' + escHtml(item.material_name) + '</a>';
                rows += '<tr><td>' + nameCell + '</td><td><ul class="missing-list">' + cells + '</ul></td></tr>';
            }
        });

        if (rows) {
            html += '<table class="missing-docs-table"><thead><tr><th>Tên nguyên liệu</th><th>Hóa đơn, chứng từ đã có</th></tr></thead><tbody>' + rows + '</tbody></table>';
        }
        html += '</div>';
        return html;
    }

    // "match" tính THẲNG từ data-kind, KHÔNG dựa vào :visible hiện tại của DOM — tránh vòng lặp tự khóa ẩn
    // khi đổi lại bộ lọc lần 2 (xem gotcha đã vá ở product_profile_standard_missing.js).
    function matches(el, mode) {
        return mode === 'all' || $(el).data('kind') === mode;
    }

    function applyFilter() {
        var mode = $filter.val() || 'all';
        var visibleCount = 0;
        $body.find('.sd-existing-line, .missing-list li').each(function () {
            var show = matches(this, mode);
            $(this).toggle(show);
            if (show) visibleCount++;
        });
        $body.find('.missing-docs-table tbody tr').each(function () {
            var $tr = $(this);
            var hasMatch = $tr.find('.missing-list li').toArray().some(function (li) { return matches(li, mode); });
            $tr.toggle(hasMatch);
        });
        $body.find('.missing-docs-table').each(function () {
            var $table = $(this);
            var hasMatch = $table.find('.missing-list li').toArray().some(function (li) { return matches(li, mode); });
            $table.toggle(hasMatch);
        });
        $body.find('.sd-missing-group').each(function () {
            var $g = $(this);
            var hasVisible = $g.find('.sd-existing-line').toArray().some(function (el) { return matches(el, mode); })
                || $g.find('.missing-list li').toArray().some(function (li) { return matches(li, mode); });
            $g.toggle(hasVisible);
        });
        $summary.html('Tổng số dòng: <b>' + visibleCount + '</b>');
    }

    function loadExisting() {
        $body.html('<p class="freq-loading">Đang tải...</p>');
        $.ajax({ url: ACT + 'ajax_all_files_standard', method: 'POST', dataType: 'json' }).then(function (res) {
            var products = (res && res.products) || [];
            if (!products.length) {
                $body.html('<p class="missing-docs-empty">Chưa có file nào.</p>');
                $summary.html('');
                return;
            }
            var html = '';
            products.forEach(function (p) { html += renderProduct(p); });
            $body.html(html);
            applyFilter();
        }).catch(function () {
            $body.html('<p class="freq-loading">Lỗi tải dữ liệu.</p>');
        });
    }

    $btn.on('click', function () {
        $filter.val('all');
        openOverlay($modal);
        loadExisting();
    });

    $filter.on('change', applyFilter);

    $(document).on('click', '#sd-existing-modal .sd-missing-group-del', function () {
        $(this).closest('.sd-missing-group').remove();
    });

    $(document).on('click', '#sd-existing-print', function () {
        window.print();
    });

    /* ---------------- Click tên nguyên liệu -> modal "Sản phẩm dùng nguyên liệu này" ---------------- */
    var $mpModal = $('#sd-material-products-modal');
    var $mpBody = $('#sd-mp-body');
    var $mpName = $('#sd-mp-material-name');

    $(document).on('click', '#sd-existing-modal .sd-material-name-link', function (e) {
        e.preventDefault();
        var $link = $(this);
        var materialId = $link.data('material-id');
        var materialName = $link.data('material-name');
        $mpName.text(materialName);
        $mpBody.html('<p class="freq-loading">Đang tải...</p>');
        openOverlay($mpModal);
        $.getJSON(ACT + 'ajax_standard_products_using_material', { material_info_id: materialId }, function (res) {
            var products = (res && res.products) || [];
            if (!products.length) {
                $mpBody.html('<p class="missing-docs-empty">Không có sản phẩm nào (trong Bộ hồ sơ chuẩn) dùng nguyên liệu này.</p>');
                return;
            }
            var html = '<ul class="sd-mp-list">';
            products.forEach(function (p) {
                html += '<li>' + escHtml(p.product_name) + '</li>';
            });
            html += '</ul>';
            $mpBody.html(html);
        }).fail(function () {
            $mpBody.html('<p class="freq-loading">Lỗi tải dữ liệu.</p>');
        });
    });

    $(document).on('click', '#sd-mp-print', function () {
        window.print();
    });

    $(document).on('click', '#sd-existing-capture', function () {
        var $capBtn = $(this);
        var area = document.getElementById('sd-existing-capture-area');
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
