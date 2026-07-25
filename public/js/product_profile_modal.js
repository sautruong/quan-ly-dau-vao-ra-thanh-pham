/* ============================================================
   product_profile_modal.js
   - Modal dùng chung (mở/đóng)
   - Chọn file (hiển thị tên)
   - Tìm kiếm nguyên liệu (multi / single)
   - Check database (bảng + phân trang)
   Yêu cầu: jQuery
   ============================================================ */
$(function () {

    /* ---------------------------------------------------------
       1. MỞ / ĐÓNG MODAL
       data-pp-open="#id-modal" để mở
       .pp-modal-close hoặc click nền để đóng, ESC để đóng
       --------------------------------------------------------- */
    function openModal($overlay) {
        $overlay.addClass('is-open');
        $('body').css('overflow', 'hidden');
    }

    function closeModal($overlay) {
        $overlay.removeClass('is-open');
        if ($('.pp-modal-overlay.is-open').length === 0) {
            $('body').css('overflow', '');
        }
    }

    $(document).on('click', '[data-pp-open]', function (e) {
        e.preventDefault();
        var target = $(this).data('pp-open');
        var $overlay = $(target);
        if (!$overlay.length) return;

        // Truyền thông tin "dòng bị tác động" cho Check DB (nếu có)
        if ($overlay.is('#checkdb-modal')) {
            $overlay.data('default-table', $(this).data('default-table') || 'products');
            $overlay.data('affected-id', $(this).data('affected-id') || 0);
            $overlay.data('affected-table', $(this).data('affected-table') || '');
            initCheckDb($overlay);
        }
        openModal($overlay);
    });

    $(document).on('click', '.pp-modal-close', function () {
        closeModal($(this).closest('.pp-modal-overlay'));
    });

    // Click ra nền (ngoài .pp-modal) thì đóng
    $(document).on('mousedown', '.pp-modal-overlay', function (e) {
        if (e.target === this) {
            closeModal($(this));
        }
    });

    $(document).on('keydown', function (e) {
        if (e.key === 'Escape') {
            $('.pp-modal-overlay.is-open').each(function () {
                closeModal($(this));
            });
        }
    });

    // Mở sẵn modal nếu URL yêu cầu (vd: submit lỗi quay lại) — data-pp-autoopen trên overlay
    $('.pp-modal-overlay[data-pp-autoopen="1"]').each(function () {
        openModal($(this));
    });

    /* ---------------------------------------------------------
       2. CHỌN FILE (class-based, scope theo form)
       Nút: .btn-choose  | input file: input[type=file]
       hiển thị tên: .file-name-display (trong cùng form)
       --------------------------------------------------------- */
    $(document).on('click', '.btn-choose', function () {
        $(this).closest('form').find('input[type="file"]').trigger('click');
    });

    $(document).on('change', 'form input[type="file"]', function () {
        var file = this.files[0];
        var $display = $(this).closest('form').find('.file-name-display');
        if (file) {
            $display.val(file.name);
        } else {
            $display.val('');
        }
    });

    /* ---------------------------------------------------------
       3. TÌM KIẾM NGUYÊN LIỆU
       Wrapper: .material-search  (data-mode="multi"|"single")
         .material-search-input   : ô nhập
         .search-results          : khung kết quả
         .selected-materials      : nơi render đã chọn
       --------------------------------------------------------- */
    $('.material-search').each(function () {
        var $wrap = $(this);
        var mode = $wrap.data('mode') || 'multi';
        var $input = $wrap.find('.material-search-input');
        var $results = $wrap.find('.search-results');
        // .selected-materials nằm NGOÀI .material-search (cùng form) -> tìm theo form
        var $selected = $wrap.closest('form').find('.selected-materials');
        if (!$selected.length) $selected = $wrap.siblings('.selected-materials');
        var picked = []; // [{id, material_name, supplier_name}]
        var timer = null;
        var activeIdx = -1;

        function resultItems() { return $results.find('.result-item'); }
        function highlight(idx) {
            var $items = resultItems();
            $items.removeClass('is-active');
            if (idx >= 0 && $items.eq(idx).length) {
                $items.eq(idx).addClass('is-active');
                $items.eq(idx)[0].scrollIntoView({ block: 'nearest' });
            }
        }
        // Chọn 1 kết quả (click hoặc bàn phím) -> thêm vào picked, dọn ô nhập, focus lại để chọn tiếp.
        function pickResult(item) {
            add({
                id: item.id,
                material_name: item.material_name,
                supplier_name: item.supplier_name || ''
            });
            $input.val('');
            $results.hide().empty();
            activeIdx = -1;
            $input.focus();
        }

        function render() {
            $selected.empty();
            picked.forEach(function (m) {
                var fieldName = (mode === 'single') ? 'material_id' : 'material_ids[]';
                var $item = $(
                    '<div class="selected-material-item">' +
                        '<input type="hidden" name="' + fieldName + '" value="' + m.id + '">' +
                        '<div class="material-info">' +
                            '<span class="material-name"></span>' +
                            '<span class="supplier-name"></span>' +
                        '</div>' +
                        '<button type="button" class="btn-remove" data-id="' + m.id + '">' +
                            '<i class="fa-solid fa-xmark"></i>' +
                        '</button>' +
                    '</div>'
                );
                $item.find('.material-name').text(m.material_name);
                $item.find('.supplier-name').text('NCC: ' + (m.supplier_name || 'Không có'));
                $selected.append($item);
            });
        }

        function add(m) {
            if (mode === 'single') {
                picked = [m];
            } else {
                if (picked.some(function (x) { return x.id == m.id; })) return;
                picked.push(m);
            }
            render();
        }

        $input.on('input', function () {
            activeIdx = -1;
            var keyword = (this.value || '').trim();
            clearTimeout(timer);
            if (keyword.length < 1) {
                $results.hide();
                return;
            }
            timer = setTimeout(function () {
                $.ajax({
                    url: '?mod=product_profile&controllers=product_profile&action=search_material',
                    method: 'POST',
                    data: { keyword: keyword },
                    dataType: 'json',
                    success: function (res) {
                        $results.empty();
                        activeIdx = -1;
                        if (res.data && res.data.length > 0) {
                            res.data.forEach(function (item) {
                                if (mode === 'multi' && picked.some(function (m) { return m.id == item.id; })) return;
                                var $div = $('<div class="result-item"></div>');
                                $div.text(item.material_name + ' — ' + (item.supplier_name || 'Không có NCC'));
                                $div.data('item', item);
                                $div.on('click', function () { pickResult(item); });
                                $results.append($div);
                            });
                            if ($results.children('.result-item').length === 0) {
                                $results.html('<div class="no-result">Tất cả kết quả đã được chọn</div>');
                            }
                            $results.show();
                        } else {
                            $results.html('<div class="no-result">Không tìm thấy nguyên liệu</div>').show();
                        }
                    }
                });
            }, 300);
        });

        // Mũi tên lên/xuống tô sáng, Tab/Enter chọn dòng đang tô sáng rồi focus lại ô nhập để chọn tiếp.
        $input.on('keydown', function (e) {
            var $items = resultItems();
            if (!$items.length || $results.is(':hidden')) return;
            if (e.key === 'ArrowDown') {
                e.preventDefault();
                activeIdx = (activeIdx + 1) % $items.length;
                highlight(activeIdx);
            } else if (e.key === 'ArrowUp') {
                e.preventDefault();
                activeIdx = (activeIdx - 1 + $items.length) % $items.length;
                highlight(activeIdx);
            } else if (e.key === 'Enter' || e.key === 'Tab') {
                if (activeIdx >= 0) {
                    e.preventDefault();
                    pickResult($items.eq(activeIdx).data('item'));
                }
            } else if (e.key === 'Escape') {
                $results.hide();
            }
        });

        $selected.on('click', '.btn-remove', function () {
            var id = $(this).data('id');
            picked = picked.filter(function (m) { return m.id != id; });
            render();
        });

        $(document).on('mousedown', function (e) {
            if (!$wrap.is(e.target) && $wrap.has(e.target).length === 0) {
                $results.hide();
            }
        });
    });

    /* ---------------------------------------------------------
       4. CHECK DATABASE
       --------------------------------------------------------- */
    function initCheckDb($overlay) {
        var $select = $overlay.find('.checkdb-table-select');
        var defaultTable = $overlay.data('default-table') || 'products';

        // Nếu có dòng bị tác động và bảng của nó nằm trong select -> ưu tiên
        var affectedTable = $overlay.data('affected-table') || '';
        var startTable = defaultTable;
        if (affectedTable && $select.find('option[value="' + affectedTable + '"]').length) {
            startTable = affectedTable;
        }
        $select.val(startTable);

        loadCheckDb($overlay, 1);
    }

    function affectedIdFor($overlay) {
        var $select = $overlay.find('.checkdb-table-select');
        var affectedTable = $overlay.data('affected-table') || '';
        var affectedId = parseInt($overlay.data('affected-id'), 10) || 0;
        // Chỉ bơm affected_id khi đang xem đúng bảng bị tác động
        return (affectedTable && $select.val() === affectedTable) ? affectedId : 0;
    }

    function loadCheckDb($overlay, page) {
        var $select = $overlay.find('.checkdb-table-select');
        var $wrap = $overlay.find('.checkdb-table-wrap');
        var $meta = $overlay.find('.checkdb-meta');
        var $pag = $overlay.find('.checkdb-pagination');
        var table = $select.val();
        var affectedId = affectedIdFor($overlay);

        $wrap.html('<div class="checkdb-loading"><i class="fa-solid fa-spinner fa-spin"></i> Đang tải dữ liệu...</div>');
        $pag.empty();

        $.ajax({
            url: '?mod=product_profile&controllers=product_profile&action=check_database',
            method: 'POST',
            data: { table: table, page: page, affected_id: affectedId },
            dataType: 'json',
            success: function (res) {
                if (!res || !res.success) {
                    $wrap.html('<div class="checkdb-empty">' + ((res && res.message) || 'Không tải được dữ liệu.') + '</div>');
                    $meta.text('');
                    return;
                }
                renderTable($wrap, res, affectedId);
                $meta.text('Bảng "' + res.table + '" — Trang ' + res.page + '/' + (res.total_pages || 1) + ' — Tổng ' + res.total + ' dòng');
                renderPagination($overlay, $pag, res);
            },
            error: function () {
                $wrap.html('<div class="checkdb-empty">Lỗi kết nối khi tải dữ liệu.</div>');
            }
        });
    }

    function renderTable($wrap, res, affectedId) {
        if (!res.rows || res.rows.length === 0) {
            $wrap.html('<div class="checkdb-empty">Bảng rỗng — không có dữ liệu.</div>');
            return;
        }
        var $table = $('<table class="checkdb-table"></table>');
        var $thead = $('<thead></thead>');
        var $htr = $('<tr></tr>');
        res.columns.forEach(function (c) {
            $('<th></th>').text(c).appendTo($htr);
        });
        $thead.append($htr);
        $table.append($thead);

        var $tbody = $('<tbody></tbody>');
        res.rows.forEach(function (row) {
            var $tr = $('<tr></tr>');
            var isAffected = affectedId && String(row.id) === String(affectedId);
            if (isAffected) $tr.addClass('is-affected');
            res.columns.forEach(function (c) {
                var val = (row[c] === null || row[c] === undefined) ? '' : String(row[c]);
                var $td = $('<td></td>').attr('title', val).text(val);
                if (c === 'id' && isAffected) {
                    $td.append('<span class="checkdb-badge">vừa tác động</span>');
                }
                $tr.append($td);
            });
            $tbody.append($tr);
        });
        $table.append($tbody);
        $wrap.empty().append($table);
    }

    function renderPagination($overlay, $pag, res) {
        var total = res.total_pages || 1;
        var current = res.page || 1;
        if (total <= 1) return;

        function btn(label, page, opts) {
            opts = opts || {};
            var $b = $('<button type="button"></button>').html(label);
            if (opts.active) $b.addClass('active');
            if (opts.disabled) {
                $b.prop('disabled', true);
            } else {
                $b.on('click', function () { loadCheckDb($overlay, page); });
            }
            $pag.append($b);
        }

        btn('&laquo;', current - 1, { disabled: current <= 1 });

        // Cửa sổ trang gọn: 1 ... (c-1) c (c+1) ... last
        var pages = [];
        for (var p = 1; p <= total; p++) {
            if (p === 1 || p === total || (p >= current - 1 && p <= current + 1)) {
                pages.push(p);
            } else if (pages[pages.length - 1] !== '...') {
                pages.push('...');
            }
        }
        pages.forEach(function (p) {
            if (p === '...') {
                $pag.append($('<button type="button" disabled>…</button>'));
            } else {
                btn(String(p), p, { active: p === current });
            }
        });

        btn('&raquo;', current + 1, { disabled: current >= total });
    }

    // Đổi bảng trong Check DB
    $(document).on('change', '.checkdb-table-select', function () {
        loadCheckDb($(this).closest('.pp-modal-overlay'), 1);
    });

});
