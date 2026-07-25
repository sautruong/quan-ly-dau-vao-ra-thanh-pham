/* =====================================================================
   product_profile_frequency.js — modal "Tần suất" (tần suất lặp lại file
   hồ sơ NCC/NVL/hóa đơn theo tên file), DÙNG CHUNG cho 2 trang:
   - detail.php (Chi tiết sản phẩm): #frequency-body (div) — mode "grouped",
     giới hạn theo NCC/NVL của RIÊNG sản phẩm đang xem (window.PP_FREQ_PRODUCT_ID).
   - list.php (Danh sách sản phẩm): #freq-table-body (table) — mode "flat",
     toàn hệ thống, có phân trang (#freq-pagination) + lọc server-side.
   Rename pencil (.btn-edit-filename) TÁI DÙNG handler có sẵn trong
   product_profile_dragdrop.js — chỉ cần render đúng markup .file-name-edit,
   không viết lại logic đổi tên.
   ===================================================================== */
(function ($) {
    'use strict';

    var $btn = $('#btn-file-frequency');
    var $modal = $('#frequency-modal');
    if (!$btn.length || !$modal.length) return; // trang không có tính năng này

    var ACT = '?mod=product_profile&controllers=product_profile&action=';
    var $filter = $('#freq-filter-select');
    var $groupFilter = $('#freq-group-filter'); // chỉ tồn tại ở list.php (mode flat, toàn hệ thống)
    var $downloadBtn = $('#freq-download-zip');
    var $groupedBody = $('#frequency-body');
    var $tableBody = $('#freq-table-body');
    var $pagination = $('#freq-pagination');
    var $summary = $('#freq-summary');
    var isFlatMode = $tableBody.length > 0;
    var productId = window.PP_FREQ_PRODUCT_ID || 0;
    var currentPage = 1;
    var removedKeys = {}; // "kind:file_id" đã bấm x -> loại khỏi ZIP dù đang ở trang khác (mode flat)

    function escHtml(s) {
        return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) {
            return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c];
        });
    }
    function openOverlay($m) {
        $m.addClass('is-open');
        $('body').css('overflow', 'hidden');
    }
    // 'product' (nhóm "Hồ sơ liên quan sản phẩm", bảng product_files) PHẢI đổi thành 'pfile' (không phải
    // 'product' hay 'composition') để: (a) rơi đúng vào nhánh mặc định ajax_rename_product_file trong
    // dragdrop.js (kind !== composition/invoice), (b) không trùng giá trị 'product' mà list.php đang dùng
    // với kiểu hiển thị "bọc trong <p class='title-file'>" (khác kiểu chữ thường trong <a> mà modal này dùng).
    function renameKind(kind) {
        if (kind === 'invoice') return 'invoice';
        if (kind === 'product') return 'pfile';
        return 'composition';
    }
    // Windows cấm các ký tự < > : " / \ | ? * trong tên file — tên hóa đơn dạng "HĐ NVL: uuid" có dấu ":"
    // nên phải thay trước khi đặt tên file trong .zip, không thì giải nén trên Windows sẽ lỗi/đổi tên lạ.
    function sanitizeFileName(s) {
        return String(s || '').replace(/[<>:"/\\|?*]/g, '-').trim();
    }
    function itemKey(it) { return it.kind + ':' + it.file_id; }

    // Ô tên file: link xem/tải + bút sửa tên (khớp đúng markup mà product_profile_dragdrop.js đang lắng nghe).
    function nameCellHtml(it) {
        return '<span class="file-name-edit">'
            + '<a href="' + escHtml(it.download_url) + '" target="_blank">' + escHtml(it.display_name) + '</a>'
            + '<a href="#" class="btn-edit-filename" data-kind="' + renameKind(it.kind) + '" data-file-id="' + it.file_id + '" title="Sửa tên file">'
            + '<i class="fa-solid fa-pen"></i></a>'
            + '</span>';
    }

    /* ---------------- Mode "grouped" (detail.php) ---------------- */
    function renderGrouped(groups) {
        var html = '';
        (groups || []).forEach(function (g) {
            if (!g.items || !g.items.length) return;
            html += '<div class="freq-group">';
            html += '<div class="freq-group-title">' + escHtml(g.label) + '</div>';
            html += '<ul class="freq-list">';
            g.items.forEach(function (it) {
                html += '<li class="freq-item" data-count="' + it.freq + '" data-group="' + escHtml(g.key) + '" data-key="' + escHtml(itemKey(it)) + '">'
                    + nameCellHtml(it)
                    + '<span class="freq-count">(' + it.freq + ')</span>'
                    + '<button type="button" class="freq-item-del" title="Bỏ khỏi danh sách (chỉ tạm thời, mở lại hiện đủ)">&times;</button>'
                    + '</li>';
            });
            html += '</ul></div>';
        });
        $groupedBody.html(html || '<p class="freq-loading">Chưa có file nào.</p>');
        applyGroupedFilter();
    }
    function applyGroupedFilter() {
        var min = parseInt($filter.val(), 10) || 0;
        $groupedBody.find('.freq-item').each(function () {
            var count = parseInt($(this).data('count'), 10) || 0;
            $(this).toggle(count > min);
        });
        // Tính "còn dòng nào khớp" lại từ data-count (KHÔNG dùng :visible của .freq-item) — nếu nhóm đang bị
        // ẩn từ lần lọc trước, con bên trong sẽ luôn báo :visible=false do cha display:none, tạo vòng lặp tự
        // khóa ẩn vĩnh viễn khi đổi lại bộ lọc lần 2 (vd hiện đủ trở lại là bị chọn "Tất cả" nhưng vẫn ẩn hết).
        $groupedBody.find('.freq-group').each(function () {
            var $g = $(this);
            var hasMatch = $g.find('.freq-item').toArray().some(function (it) {
                return (parseInt($(it).data('count'), 10) || 0) > min;
            });
            $g.toggle(hasMatch);
        });
        $summary.html('Tổng số file: <b>' + $groupedBody.find('.freq-item:visible').length + '</b>');
    }
    function loadGrouped() {
        $groupedBody.html('<p class="freq-loading">Đang tải...</p>');
        $.getJSON(ACT + 'ajax_file_frequency', { product_id: productId }, function (res) {
            renderGrouped((res && res.groups) || []);
        }).fail(function () {
            $groupedBody.html('<p class="freq-loading">Lỗi tải dữ liệu.</p>');
        });
    }

    /* ---------------- Mode "flat" phân trang (list.php) ---------------- */
    function renderFlatRows(items) {
        if (!items || !items.length) {
            $tableBody.html('<tr><td colspan="3" class="freq-loading">Không có file nào khớp bộ lọc.</td></tr>');
            return;
        }
        var html = '';
        items.forEach(function (it) {
            var key = itemKey(it);
            var removedCls = removedKeys[key] ? ' style="display:none"' : '';
            html += '<tr class="freq-item" data-count="' + it.freq + '" data-key="' + escHtml(key) + '"' + removedCls + '>'
                + '<td>' + escHtml(it.group_label) + '</td>'
                + '<td>' + nameCellHtml(it) + '</td>'
                + '<td class="freq-count-col">' + it.freq + '<button type="button" class="freq-item-del" title="Bỏ khỏi danh sách (chỉ tạm thời)">&times;</button></td>'
                + '</tr>';
        });
        $tableBody.html(html);
    }
    function renderFlatPagination(res) {
        var total = res.total_pages || 1;
        var current = res.page || 1;
        $pagination.empty();
        if (total <= 1) return;

        function btn(label, page, opts) {
            opts = opts || {};
            var $b = $('<button type="button"></button>').html(label);
            if (opts.active) $b.addClass('active');
            if (opts.disabled) $b.prop('disabled', true);
            else $b.on('click', function () { loadFlat(page); });
            $pagination.append($b);
        }
        btn('&laquo;', current - 1, { disabled: current <= 1 });
        var pages = [];
        for (var p = 1; p <= total; p++) {
            if (p === 1 || p === total || (p >= current - 1 && p <= current + 1)) pages.push(p);
            else if (pages[pages.length - 1] !== '...') pages.push('...');
        }
        pages.forEach(function (p) {
            if (p === '...') $pagination.append($('<button type="button" disabled>…</button>'));
            else btn(String(p), p, { active: p === current });
        });
        btn('&raquo;', current + 1, { disabled: current >= total });
    }
    function loadFlat(page) {
        currentPage = page || 1;
        $tableBody.html('<tr><td colspan="3" class="freq-loading">Đang tải...</td></tr>');
        $.getJSON(ACT + 'ajax_file_frequency', { min_freq: $filter.val() || 0, group: $groupFilter.val() || 'all', page: currentPage }, function (res) {
            renderFlatRows((res && res.items) || []);
            renderFlatPagination(res || {});
            $summary.html('Tổng số file: <b>' + ((res && res.total) || 0) + '</b>');
        }).fail(function () {
            $tableBody.html('<tr><td colspan="3" class="freq-loading">Lỗi tải dữ liệu.</td></tr>');
        });
    }

    /* ---------------- Mở modal ---------------- */
    $btn.on('click', function () {
        $filter.val('0');
        $groupFilter.val('all');
        openOverlay($modal);
        if (isFlatMode) { removedKeys = {}; loadFlat(1); } else { loadGrouped(); }
    });

    $filter.on('change', function () {
        if (isFlatMode) loadFlat(1); else applyGroupedFilter();
    });

    $groupFilter.on('change', function () {
        if (isFlatMode) loadFlat(1);
    });

    // Bỏ 1 dòng khỏi màn hình — chỉ tạm thời. Mode grouped: mở lại modal gọi AJAX mới nên tự đầy đủ.
    // Mode flat: nhớ lại (removedKeys) để "Tải xuống" vẫn loại đúng, kể cả dòng ở TRANG KHÁC.
    $(document).on('click', '.freq-item-del', function () {
        var $row = $(this).closest('.freq-item');
        removedKeys[$row.data('key')] = true;
        $row.remove();
        if (!isFlatMode) applyGroupedFilter();
    });

    /* ---------------- Tải xuống ZIP ---------------- */
    $downloadBtn.on('click', function () {
        if (typeof window.JSZip !== 'function') { alert('Không nạp được JSZip. Kiểm tra mạng rồi thử lại.'); return; }

        var orig = $downloadBtn.html();
        $downloadBtn.html('Đang chuẩn bị...').prop('disabled', true);

        function collectItems() {
            if (!isFlatMode) {
                // Grouped: dữ liệu đã có sẵn trên DOM (không phân trang) -> lấy trực tiếp từ .freq-item:visible.
                var items = [];
                $groupedBody.find('.freq-item:visible').each(function () {
                    var $li = $(this);
                    var $link = $li.find('.file-name-edit a[target="_blank"]');
                    items.push({
                        url: $link.attr('href'),
                        name: ($link.text() || '').trim(),
                        group: $li.closest('.freq-group').find('.freq-group-title').text()
                    });
                });
                return $.Deferred().resolve(items).promise();
            }
            // Flat: phải gọi lại AJAX lấy TOÀN BỘ (all=1) theo đúng bộ lọc hiện tại, rồi trừ removedKeys —
            // vì phân trang chỉ có 20 dòng/trang trên DOM, không đủ để tải hết theo bộ lọc.
            return $.getJSON(ACT + 'ajax_file_frequency', { min_freq: $filter.val() || 0, group: $groupFilter.val() || 'all', all: 1 }).then(function (res) {
                var items = (res && res.items) || [];
                return items.filter(function (it) { return !removedKeys[it.kind + ':' + it.file_id]; })
                    .map(function (it) {
                        return { url: it.download_url, name: it.display_name, group: it.group_label, ext: it.ext };
                    });
            });
        }

        collectItems().then(function (items) {
            if (!items.length) { alert('Không có file nào để tải.'); return $.Deferred().reject().promise(); }
            $downloadBtn.html('Đang nén...');
            var zip = new JSZip();
            var chain = Promise.resolve();
            items.forEach(function (it) {
                chain = chain.then(function () {
                    return fetch(it.url).then(function (r) {
                        if (!r.ok) throw new Error('Không tải được: ' + it.name);
                        return r.blob();
                    }).then(function (blob) {
                        var ext = it.ext || '';
                        var safeName = sanitizeFileName(it.name);
                        var fname = ext && safeName.slice(-(ext.length + 1)) !== ('.' + ext) ? safeName + '.' + ext : safeName;
                        zip.folder(it.group || 'Khác').file(fname, blob);
                    });
                });
            });
            return chain.then(function () { return zip.generateAsync({ type: 'blob' }); }).then(function (blob) {
                var a = document.createElement('a');
                a.href = URL.createObjectURL(blob);
                a.download = 'tan_suat_ho_so.zip';
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
