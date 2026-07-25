(function () {
    // Bộ lọc theo cột cho 5 view dữ liệu admin_factory.
    // Mỗi cột muốn lọc đặt 1 nút:
    //   <div class="btn-filter" data-filter-target="product_id"
    //        data-search-type="products" data-filter-title="Lọc tên sản phẩm">…</div>
    //   - data-filter-target : name của hidden input trong form.data-filter
    //   - data-search-type   : products|materials|customers|suppliers|goods
    //   - data-filter-title  : placeholder + tiêu đề modal
    // Khi bấm "LỌC", gán value đã chọn vào hidden input rồi submit form (reload GET),
    // nên bộ lọc tên kết hợp luôn với khoảng ngày (from/to) đang có trên form.

    var AJAX_SEARCH = '?mod=admin_factory&controllers=admin&action=data_filter_search';

    var form = document.querySelector('form.data-filter');

    // ---- Tạo modal 1 lần, dùng chung cho mọi cột ----
    var backdrop = document.createElement('div');
    backdrop.className = 'col-filter-backdrop';
    backdrop.innerHTML =
        '<div class="col-filter-modal" role="dialog" aria-modal="true">' +
            '<div class="cf-header"><span class="cf-title">Lọc</span>' +
                '<button type="button" class="cf-close" aria-label="Đóng">&times;</button></div>' +
            '<div class="cf-body">' +
                '<input type="text" class="cf-search" placeholder="Nhập từ khóa...">' +
                '<div class="cf-list"></div>' +
            '</div>' +
            '<div class="cf-footer">' +
                '<button type="button" class="cf-btn-clear">Bỏ lọc</button>' +
                '<button type="button" class="cf-btn-apply">LỌC</button>' +
            '</div>' +
        '</div>';
    document.body.appendChild(backdrop);

    var elTitle   = backdrop.querySelector('.cf-title');
    var elSearch  = backdrop.querySelector('.cf-search');
    var elList    = backdrop.querySelector('.cf-list');
    var elApply   = backdrop.querySelector('.cf-btn-apply');
    var elClear   = backdrop.querySelector('.cf-btn-clear');
    var elClose   = backdrop.querySelector('.cf-close');

    var current = { target: '', type: '', selectedValue: '' };
    var debounceTimer;

    function openModal(btn) {
        current.target = btn.getAttribute('data-filter-target') || '';
        current.type   = btn.getAttribute('data-search-type') || '';
        current.selectedValue = '';
        var title = btn.getAttribute('data-filter-title') || 'Lọc';
        elTitle.textContent = title;
        elSearch.placeholder = title;
        elSearch.value = '';
        elList.innerHTML = '';
        backdrop.classList.add('show');
        elSearch.focus();
        doSearch('');
    }

    function closeModal() {
        backdrop.classList.remove('show');
    }

    function doSearch(keyword) {
        var fd = new FormData();
        fd.append('type', current.type);
        fd.append('keyword', keyword);
        fetch(AJAX_SEARCH, { method: 'POST', body: fd, credentials: 'same-origin' })
            .then(function (r) { return r.json(); })
            .then(function (res) { renderList((res && res.data) ? res.data : []); })
            .catch(function () { renderList([]); });
    }

    function renderList(items) {
        if (!items.length) {
            elList.innerHTML = '<div class="cf-empty">Không tìm thấy</div>';
            return;
        }
        elList.innerHTML = items.map(function (it) {
            var v = String(it.value);
            return '<div class="cf-item" data-value="' + escapeAttr(v) + '">' +
                   escapeHtml(it.name) + '</div>';
        }).join('');
    }

    function escapeHtml(s) {
        return String(s).replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;');
    }
    function escapeAttr(s) {
        return String(s).replace(/"/g, '&quot;');
    }

    function applyFilter(value) {
        if (!form) return;
        var input = form.querySelector('[name="' + current.target + '"]');
        if (!input) {
            // tạo hidden input nếu view chưa khai báo
            input = document.createElement('input');
            input.type = 'hidden';
            input.name = current.target;
            form.appendChild(input);
        }
        input.value = value;
        form.submit();
    }

    // ---- Sự kiện ----
    document.addEventListener('click', function (e) {
        var btn = e.target.closest && e.target.closest('.wp-title .btn-filter');
        if (btn) { e.preventDefault(); openModal(btn); }
    });

    elSearch.addEventListener('input', function () {
        clearTimeout(debounceTimer);
        debounceTimer = setTimeout(function () { doSearch(elSearch.value); }, 180);
    });

    elList.addEventListener('click', function (e) {
        var item = e.target.closest('.cf-item');
        if (!item) return;
        var prev = elList.querySelector('.cf-item.selected');
        if (prev) prev.classList.remove('selected');
        item.classList.add('selected');
        current.selectedValue = item.getAttribute('data-value');
    });

    elApply.addEventListener('click', function () {
        if (!current.selectedValue) return;
        applyFilter(current.selectedValue);
    });
    elClear.addEventListener('click', function () { applyFilter(''); });
    elClose.addEventListener('click', closeModal);
    backdrop.addEventListener('click', function (e) {
        if (e.target === backdrop) closeModal();
    });
    document.addEventListener('keydown', function (e) {
        if (e.key === 'Escape' && backdrop.classList.contains('show')) closeModal();
    });

    // ---- Đánh dấu cột đang có bộ lọc áp dụng ----
    document.addEventListener('DOMContentLoaded', function () {
        if (!form) return;
        document.querySelectorAll('.wp-title .btn-filter').forEach(function (btn) {
            var target = btn.getAttribute('data-filter-target');
            var input  = form.querySelector('[name="' + target + '"]');
            if (input && input.value && input.value !== '0') {
                btn.classList.add('is-active');
            }
        });
    });
})();
