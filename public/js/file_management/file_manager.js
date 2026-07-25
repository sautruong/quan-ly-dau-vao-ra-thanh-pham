/* =====================================================================
 *  QUẢN LÝ FILE — file_manager.js
 *  Render cây nhóm/thư mục/file, kéo-thả sắp xếp, chia sẻ (user/chat/dự án),
 *  tìm kiếm, gắn sao, đổi tên, tạo bản sao, tải xuống.
 * ===================================================================== */
(function () {
    'use strict';

    var ACT = '?mod=file_management&controllers=file_management&action=';
    var DL  = ACT + 'download&id=';

    var state = {
        library: { groups: [], root_files: [] },
        shared: { pending: [], accepted: [] },
        users: [],
        projects: [],
        frequent: [],             // top file hay dùng (click_count), FAQ cố định
        tab: 'mine',
        starOnly: false,
        openFolders: {},          // id -> true (thư mục đang mở)
        uploadTarget: 0,          // folder_id đích cho lần tải lên kế tiếp
        uploadFolderTarget: 0     // folder_id đích cho lần tải THƯ MỤC kế tiếp
    };

    /* ----------------- tiện ích ----------------- */
    function $(s, r) { return (r || document).querySelector(s); }
    function $all(s, r) { return Array.prototype.slice.call((r || document).querySelectorAll(s)); }
    function esc(s) {
        return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) {
            return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c];
        });
    }
    function el(html) { var t = document.createElement('template'); t.innerHTML = html.trim(); return t.content.firstChild; }

    function toast(msg, ok) {
        var t = $('#fm-toast');
        t.textContent = msg;
        t.className = 'fm-toast show' + (ok === false ? ' err' : '');
        clearTimeout(toast._t);
        toast._t = setTimeout(function () { t.className = 'fm-toast'; }, 2600);
    }

    function api(action, data, cb, isForm) {
        var opt = { method: 'POST' };
        if (isForm) {
            opt.body = data; // FormData
        } else {
            opt.headers = { 'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8' };
            opt.body = Object.keys(data || {}).map(function (k) {
                var v = data[k];
                if (Array.isArray(v) || (v && typeof v === 'object')) v = JSON.stringify(v);
                return encodeURIComponent(k) + '=' + encodeURIComponent(v);
            }).join('&');
        }
        fetch(ACT + action, opt).then(function (r) { return r.json(); })
            .then(function (j) { cb && cb(j); })
            .catch(function () { toast('Lỗi kết nối máy chủ.', false); });
    }

    var ICONS = {
        image: 'fa-file-image', pdf: 'fa-file-pdf', word: 'fa-file-word', excel: 'fa-file-excel',
        ppt: 'fa-file-powerpoint', video: 'fa-file-video', audio: 'fa-file-audio',
        archive: 'fa-file-zipper', text: 'fa-file-lines', file: 'fa-file'
    };
    function fileIcon(f) {
        if (f.is_image && f.url) return '<span class="fm-thumb" style="background-image:url(\'' + esc(f.url) + '\')"></span>';
        return '<span class="fm-ic fm-ic-' + esc(f.kind) + '"><i class="fa-solid ' + (ICONS[f.kind] || ICONS.file) + '"></i></span>';
    }

    /* ----------------- bootstrap ----------------- */
    function boot() {
        try {
            var raw = JSON.parse($('#fm-boot').textContent || '{}');
            state.library = raw.library || state.library;
            state.shared = raw.shared || state.shared;
            state.users = raw.users || [];
            state.projects = raw.projects || [];
        } catch (e) {}
        bindToolbar();
        bindGlobal();
        bindFrequentFaq();
        render();
        loadFrequent();
    }

    function reload(cb) {
        api('data', {}, function (j) {
            if (j && j.success) {
                state.library = j.library;
                state.shared = j.shared;
                render();
            }
            cb && cb();
        });
        loadFrequent();
    }

    // Cho phép widget Chat (nổi trên mọi trang, kể cả chính trang Quản lý file)
    // gọi refresh real-time ngay khi "Thêm vào thư viện của tôi" thành công,
    // không cần reload cả trang. Xem public/js/shared/chat.js (doAddToLibrary).
    window.__fmReload = reload;

    /* ================= RENDER ================= */
    function render() {
        $('#fm-tab-mine').style.display = state.tab === 'mine' ? '' : 'none';
        $('#fm-tab-shared').style.display = state.tab === 'shared' ? '' : 'none';
        $all('.fm-chip').forEach(function (c) { c.classList.toggle('is-active', c.dataset.tab === state.tab); });
        if (state.tab === 'mine') renderMine(); else renderShared();
        var n = (state.shared.pending || []).length;
        var b = $('#fm-pending-badge');
        b.textContent = n; b.style.display = n ? '' : 'none';
    }

    function passStar(item) { return !state.starOnly || item.starred; }

    function renderMine() {
        // File ở gốc (ngoài nhóm)
        var rf = $('#fm-root-files');
        var roots = (state.library.root_files || []).filter(passStar);
        rf.innerHTML = '';
        if (roots.length) {
            var head = el('<div class="fm-section-head"><i class="fa-regular fa-file"></i> File ngoài nhóm</div>');
            rf.appendChild(head);
            var grid = el('<div class="fm-grid fm-sortable" data-type="file" data-parent="0"></div>');
            roots.forEach(function (f) { grid.appendChild(fileTile(f, true)); });
            rf.appendChild(grid);
            makeSortable(grid);
        }

        // Nhóm (card dọc)
        var wrap = $('#fm-groups');
        wrap.dataset.type = 'folder';
        wrap.dataset.parent = '0';
        wrap.innerHTML = '';
        var groups = (state.library.groups || []);
        var shown = 0;
        groups.forEach(function (g) {
            var card = groupCard(g);
            if (card) { wrap.appendChild(card); shown++; }
        });
        makeSortable(wrap, true);
        $('#fm-mine-empty').style.display = (groups.length === 0 && roots.length === 0) ? '' : 'none';

        // Mục chia sẻ đã "Vào thư viện của tôi" (in_my_library=1) → hiện luôn ở đây,
        // không chỉ ở tab "Được chia sẻ" (trước đây bấm xong không thấy đâu).
        var lsHead = $('#fm-library-shared-head');
        var lsGrid = $('#fm-library-shared');
        lsGrid.innerHTML = '';
        var libShared = (state.library.shared_saved || []).filter(function (s) { return passStar(s.item); });
        if (libShared.length) {
            lsHead.style.display = '';
            libShared.forEach(function (s) { lsGrid.appendChild(sharedItem(s)); });
        } else {
            lsHead.style.display = 'none';
        }
    }

    function groupCard(g) {
        if (state.starOnly && !hasStarred(g)) return null;
        var card = el(
            '<div class="fm-card fm-draggable" draggable="true" data-type="folder" data-id="' + g.id + '" data-parent="0">' +
                '<div class="fm-card-head" style="' + (g.color ? 'border-left-color:' + esc(g.color) : '') + '">' +
                    '<span class="fm-grip" title="Kéo để sắp xếp"><i class="fa-solid fa-grip-vertical"></i></span>' +
                    '<i class="fa-solid fa-folder fm-card-folder"></i>' +
                    '<span class="fm-card-name" title="' + esc(g.name) + '">' + esc(g.name) + '</span>' +
                    '<button class="fm-star ' + (g.starred ? 'on' : '') + '" data-act="star" data-type="folder" data-id="' + g.id + '" title="Gắn sao"><i class="fa-' + (g.starred ? 'solid' : 'regular') + ' fa-star"></i></button>' +
                    '<button class="fm-iconbtn" data-act="folder-menu" data-id="' + g.id + '" title="Tùy chọn"><i class="fa-solid fa-ellipsis-vertical"></i></button>' +
                '</div>' +
                '<div class="fm-card-body"></div>' +
            '</div>'
        );
        var body = $('.fm-card-body', card);
        body.appendChild(folderChildren(g));
        wireExternalFileDrop(card, function () { return g.id; });
        return card;
    }

    // Vùng con của 1 thư mục: subfolders + files (đệ quy)
    function folderChildren(node) {
        var box = el('<div class="fm-children"></div>');

        var subWrap = el('<div class="fm-sortable" data-type="folder" data-parent="' + node.id + '"></div>');
        (node.folders || []).forEach(function (sf) {
            if (state.starOnly && !hasStarred(sf)) return;
            subWrap.appendChild(folderNode(sf));
        });
        box.appendChild(subWrap);
        makeSortable(subWrap);

        var fileWrap = el('<div class="fm-filelist fm-sortable" data-type="file" data-parent="' + node.id + '"></div>');
        (node.files || []).filter(passStar).forEach(function (f) { fileWrap.appendChild(fileRow(f)); });
        box.appendChild(fileWrap);
        makeSortable(fileWrap);

        return box;
    }

    function folderNode(sf) {
        var open = !!state.openFolders[sf.id];
        var node = el(
            '<div class="fm-folder fm-draggable" draggable="true" data-type="folder" data-id="' + sf.id + '" data-parent="' + sf.parent_id + '">' +
                '<div class="fm-folder-head" data-act="toggle" data-id="' + sf.id + '">' +
                    '<i class="fa-solid fa-caret-' + (open ? 'down' : 'right') + ' fm-caret"></i>' +
                    '<i class="fa-solid fa-folder' + (open ? '-open' : '') + '"></i>' +
                    '<span class="fm-folder-name" title="' + esc(sf.name) + '">' + esc(sf.name) + '</span>' +
                    '<button class="fm-star ' + (sf.starred ? 'on' : '') + '" data-act="star" data-type="folder" data-id="' + sf.id + '"><i class="fa-' + (sf.starred ? 'solid' : 'regular') + ' fa-star"></i></button>' +
                    '<button class="fm-iconbtn" data-act="folder-menu" data-id="' + sf.id + '"><i class="fa-solid fa-ellipsis-vertical"></i></button>' +
                '</div>' +
            '</div>'
        );
        if (open) node.appendChild(folderChildren(sf));
        wireExternalFileDrop(node, function () { return sf.id; });
        return node;
    }

    function fileRow(f) {
        return el(
            '<div class="fm-file fm-draggable" draggable="true" data-type="file" data-id="' + f.id + '" data-parent="' + f.folder_id + '">' +
                fileIcon(f) +
                '<span class="fm-file-name" data-act="open" data-id="' + f.id + '" title="' + esc(f.name) + '">' + esc(f.name) + '</span>' +
                '<span class="fm-file-meta">' + esc(f.size_human) + ' · ' + fmtDate(f.created_at) + '</span>' +
                '<button class="fm-star ' + (f.starred ? 'on' : '') + '" data-act="star" data-type="file" data-id="' + f.id + '"><i class="fa-' + (f.starred ? 'solid' : 'regular') + ' fa-star"></i></button>' +
                '<a class="fm-iconbtn" href="' + DL + f.id + '" title="Tải xuống"><i class="fa-solid fa-download"></i></a>' +
                '<button class="fm-iconbtn" data-act="share" data-id="' + f.id + '" title="Chia sẻ"><i class="fa-solid fa-share-nodes"></i></button>' +
                '<button class="fm-iconbtn" data-act="file-menu" data-id="' + f.id + '"><i class="fa-solid fa-ellipsis-vertical"></i></button>' +
            '</div>'
        );
    }

    function fileTile(f, isRoot) {
        return el(
            '<div class="fm-tile fm-draggable" draggable="true" data-type="file" data-id="' + f.id + '" data-parent="' + f.folder_id + '">' +
                '<div class="fm-tile-ic" data-act="open" data-id="' + f.id + '">' + fileIcon(f) + '</div>' +
                '<div class="fm-tile-name" title="' + esc(f.name) + '">' + esc(f.name) + '</div>' +
                '<div class="fm-tile-meta">' + esc(f.size_human) + '</div>' +
                '<button class="fm-star ' + (f.starred ? 'on' : '') + '" data-act="star" data-type="file" data-id="' + f.id + '"><i class="fa-' + (f.starred ? 'solid' : 'regular') + ' fa-star"></i></button>' +
                '<button class="fm-iconbtn fm-tile-menu" data-act="file-menu" data-id="' + f.id + '"><i class="fa-solid fa-ellipsis-vertical"></i></button>' +
            '</div>'
        );
    }

    function hasStarred(node) {
        if (node.starred) return true;
        if ((node.files || []).some(function (f) { return f.starred; })) return true;
        return (node.folders || []).some(hasStarred);
    }

    function fmtDate(s) {
        if (!s) return '';
        var d = new Date(String(s).replace(' ', 'T'));
        if (isNaN(d)) return s;
        return ('0' + d.getDate()).slice(-2) + '/' + ('0' + (d.getMonth() + 1)).slice(-2) + '/' + d.getFullYear();
    }

    /* ---------- TAB chia sẻ ---------- */
    function renderShared() {
        var pend = $('#fm-pending');
        pend.innerHTML = '';
        (state.shared.pending || []).forEach(function (s) {
            pend.appendChild(el(
                '<div class="fm-invite" data-share="' + s.share_id + '">' +
                    (s.owner_avatar ? '<img class="fm-ava" src="' + esc(s.owner_avatar) + '">' : '<span class="fm-ava fm-ava-x">' + esc((s.owner_name || '?').charAt(0)) + '</span>') +
                    '<div class="fm-invite-info">' +
                        '<b>' + esc(s.owner_name) + '</b> chia sẻ ' + (s.item_type === 'folder' ? 'nhóm file' : 'tệp') +
                        ' <span class="fm-invite-item">“' + esc(s.item.name) + '”</span>' +
                    '</div>' +
                    '<div class="fm-invite-act">' +
                        '<button class="fm-btn fm-btn-sm fm-btn-primary" data-act="accept" data-share="' + s.share_id + '">Nhận</button>' +
                        '<button class="fm-btn fm-btn-sm" data-act="reject" data-share="' + s.share_id + '">Từ chối</button>' +
                    '</div>' +
                '</div>'
            ));
        });
        if (!(state.shared.pending || []).length) pend.innerHTML = '<div class="fm-muted">Không có lời mời mới.</div>';

        var grid = $('#fm-shared-grid');
        grid.innerHTML = '';
        var acc = state.shared.accepted || [];
        acc.forEach(function (s) { grid.appendChild(sharedItem(s)); });
        $('#fm-shared-empty').style.display = acc.length ? 'none' : '';
    }

    function sharedItem(s) {
        var it = s.item;
        var head;
        if (s.item_type === 'folder') {
            head =
                '<div class="fm-shared-head" data-act="open-shared" data-id="' + it.id + '">' +
                    '<i class="fa-solid fa-folder"></i>' +
                    '<span class="fm-shared-name">' + esc(it.name) + '</span>' +
                '</div>';
        } else {
            head =
                '<div class="fm-shared-head">' + fileIcon(it) +
                    '<span class="fm-shared-name" data-act="open" data-id="' + it.id + '">' + esc(it.name) + '</span>' +
                '</div>';
        }
        var iconActions =
            '<a class="fm-iconbtn" href="' + (s.item_type === 'file' ? DL + it.id : '#') + '" ' + (s.item_type === 'file' ? '' : 'style="display:none"') + ' title="Tải xuống"><i class="fa-solid fa-download"></i></a>' +
            (s.item_type === 'file' ? '<button class="fm-iconbtn" data-act="copy" data-id="' + it.id + '" title="Tạo bản sao vào thư viện của tôi"><i class="fa-solid fa-copy"></i></button>' : '') +
            (s.item_type === 'file' ? '<button class="fm-iconbtn" data-act="reshare" data-id="' + it.id + '" title="Chia sẻ tiếp"><i class="fa-solid fa-share-nodes"></i></button>' : '') +
            '<button class="fm-iconbtn fm-danger" data-act="leaveshare" data-share="' + s.share_id + '" title="Gỡ khỏi Được chia sẻ"><i class="fa-solid fa-trash"></i></button>';

        var libRow = !s.in_my_library
            ? '<button class="fm-btn fm-btn-sm" data-act="addlib" data-share="' + s.share_id + '" title="Giữ lại kể cả khi chủ gỡ chia sẻ"><i class="fa-solid fa-plus"></i> Vào thư viện của tôi</button>'
            : '<span class="fm-tag"><i class="fa-solid fa-check"></i> Trong thư viện</span>';

        var box = el(
            '<div class="fm-shared-card" data-share="' + s.share_id + '">' +
                head +
                '<div class="fm-shared-by"><i class="fa-solid fa-user"></i> ' + esc(s.owner_name) + '</div>' +
                '<div class="fm-shared-actions">' + iconActions + '</div>' +
                '<div class="fm-shared-libr-row">' + libRow + '</div>' +
                '<div class="fm-shared-children" style="display:none;"></div>' +
            '</div>'
        );

        // Cho phép kéo-thả file (đã lưu từ chia sẻ) vào 1 nhóm file/thư mục khác của
        // chính mình — vì đây là mục của người khác nên chỉ TẠO BẢN SAO, không "di chuyển".
        if (s.item_type === 'file') {
            box.setAttribute('draggable', 'true');
            box.classList.add('fm-shared-draggable');
            box.addEventListener('dragstart', function (e) {
                try { e.dataTransfer.setData('application/x-fm-shared-copy', String(it.id)); } catch (x) {}
                e.dataTransfer.effectAllowed = 'copy';
                box.classList.add('dragging');
            });
            box.addEventListener('dragend', function () { box.classList.remove('dragging'); });
        }
        return box;
    }

    function openSharedFolder(card, folderId) {
        var box = $('.fm-shared-children', card);
        if (box.style.display !== 'none') { box.style.display = 'none'; card.classList.remove('is-open'); return; }
        box.style.display = '';
        card.classList.add('is-open');
        box.innerHTML = '<div class="fm-muted">Đang tải…</div>';
        api('folder_contents', { folder_id: folderId }, function (j) {
            if (!j || !j.success) { box.innerHTML = '<div class="fm-muted">Không xem được.</div>'; return; }
            box.innerHTML = '';
            j.data.folders.forEach(function (sf) {
                box.appendChild(el(
                    '<div class="fm-folder">' +
                        '<div class="fm-folder-head" data-act="open-shared" data-id="' + sf.id + '">' +
                            '<i class="fa-solid fa-caret-right fm-caret"></i><i class="fa-solid fa-folder"></i>' +
                            '<span class="fm-folder-name">' + esc(sf.name) + '</span>' +
                        '</div><div class="fm-shared-children" style="display:none;"></div>' +
                    '</div>'
                ));
            });
            j.data.files.forEach(function (f) {
                var row = el(
                    '<div class="fm-file">' + fileIcon(f) +
                        '<span class="fm-file-name" data-act="open" data-id="' + f.id + '">' + esc(f.name) + '</span>' +
                        '<span class="fm-file-meta">' + esc(f.size_human) + '</span>' +
                        '<a class="fm-iconbtn" href="' + DL + f.id + '" title="Tải xuống"><i class="fa-solid fa-download"></i></a>' +
                        '<button class="fm-iconbtn" data-act="copy" data-id="' + f.id + '" title="Tạo bản sao"><i class="fa-solid fa-copy"></i></button>' +
                        '<button class="fm-iconbtn" data-act="reshare" data-id="' + f.id + '" title="Chia sẻ tiếp"><i class="fa-solid fa-share-nodes"></i></button>' +
                    '</div>'
                );
                box.appendChild(row);
            });
            if (!j.data.folders.length && !j.data.files.length) box.innerHTML = '<div class="fm-muted">Trống.</div>';
        });
    }

    /* ================= TOOLBAR ================= */
    function bindToolbar() {
        $('#fm-add-group').addEventListener('click', function () {
            promptModal('Thêm nhóm file', 'Tên nhóm file', '', function (name) {
                api('create_folder', { parent_id: 0, name: name }, function (j) {
                    if (j.success) { closeModal(); reload(); toast('Đã tạo nhóm file.'); }
                    else toast(j.message || 'Lỗi.', false);
                });
            });
        });
        $('#fm-upload-root').addEventListener('click', function () { triggerUpload(0); });
        $('#fm-upload-folder-root').addEventListener('click', function () { triggerFolderUpload(0); });

        $all('.fm-chip').forEach(function (c) {
            c.addEventListener('click', function () { state.tab = c.dataset.tab; render(); });
        });
        $('#fm-filter-star').addEventListener('click', function () {
            state.starOnly = !state.starOnly;
            this.classList.toggle('is-active', state.starOnly);
            this.querySelector('i').className = 'fa-' + (state.starOnly ? 'solid' : 'regular') + ' fa-star';
            render();
        });

        var si = $('#fm-search'), timer;
        si.addEventListener('input', function () {
            clearTimeout(timer);
            var kw = si.value.trim();
            if (kw === '') { hideSearch(); return; }
            timer = setTimeout(function () { doSearch(kw); }, 250);
        });
        $('#fm-search-clear').addEventListener('click', function () { si.value = ''; hideSearch(); });
        $('#fm-search-back').addEventListener('click', function () { si.value = ''; hideSearch(); });

        $('#fm-file-input').addEventListener('change', function () { handleUpload(this.files); this.value = ''; });
        $('#fm-folder-input').addEventListener('change', function () { handleUploadFolder(this.files); this.value = ''; });

        var storageBtn = $('#fm-storage-btn');
        if (storageBtn) storageBtn.addEventListener('click', openStorageModal);
    }

    /* ----- Dung lượng lưu trữ: donut + lọc "chưa đụng" + xóa hàng loạt ----- */
    function openStorageModal() {
        openModal('Dung lượng lưu trữ',
            '<div class="fm-storage-top">' +
                '<div class="fm-donut" id="fm-donut" style="background: conic-gradient(#e5e7eb 0deg 360deg);"><span id="fm-donut-pct">…</span></div>' +
                '<div class="fm-storage-info" id="fm-storage-info">Đang tải…</div>' +
            '</div>' +
            '<div class="fm-field-label">Dọn file lâu chưa dùng</div>' +
            '<div class="fm-storage-chips">' +
                '<button type="button" class="fm-chip" data-months="3">3 tháng chưa đụng</button>' +
                '<button type="button" class="fm-chip" data-months="6">6 tháng chưa đụng</button>' +
                '<button type="button" class="fm-chip" data-months="12">1 năm chưa đụng</button>' +
            '</div>' +
            '<div id="fm-storage-list"></div>' +
            '<div class="fm-modal-foot" id="fm-storage-foot" style="display:none;">' +
                '<label class="fm-user-row" style="margin-right:auto;"><input type="checkbox" id="fm-storage-all"><span class="fm-check-circle"><i class="fa-solid fa-check"></i></span><span>Chọn tất cả</span></label>' +
                '<button class="fm-btn fm-btn-danger" id="fm-storage-delete">Xóa đã chọn</button>' +
            '</div>');

        loadStorageStats();

        $all('.fm-storage-chips .fm-chip', $('#fm-modal-body')).forEach(function (chip) {
            chip.addEventListener('click', function () {
                $all('.fm-storage-chips .fm-chip', $('#fm-modal-body')).forEach(function (c) { c.classList.remove('is-active'); });
                chip.classList.add('is-active');
                loadStaleFiles(+chip.dataset.months);
            });
        });
    }

    function loadStorageStats() {
        api('storage_stats', {}, function (j) {
            if (!j || !j.success) return;
            var d = j.data;
            var pct = d.percent || 0;
            var color = pct >= 90 ? '#dc2626' : (pct >= 70 ? '#f59e0b' : 'var(--fm-green)');
            $('#fm-donut').style.background = 'conic-gradient(' + color + ' 0deg ' + (pct * 3.6) + 'deg, #e5e7eb ' + (pct * 3.6) + 'deg 360deg)';
            $('#fm-donut-pct').textContent = pct + '%';
            $('#fm-storage-info').innerHTML =
                'Đã dùng <b>' + fmtBytes(d.used_bytes) + '</b> / ' + fmtBytes(d.quota_bytes) +
                '<div class="fm-muted">Giới hạn do quản trị viên thiết lập.</div>';
        });
    }

    function fmtBytes(b) {
        b = +b || 0;
        if (b >= 1073741824) return (b / 1073741824).toFixed(2) + ' GB';
        if (b >= 1048576) return (b / 1048576).toFixed(1) + ' MB';
        if (b >= 1024) return (b / 1024).toFixed(0) + ' KB';
        return b + ' B';
    }

    function loadStaleFiles(months) {
        var list = $('#fm-storage-list');
        list.innerHTML = '<div class="fm-muted">Đang tải…</div>';
        api('stale_files', { months: months }, function (j) {
            if (!j || !j.success) { list.innerHTML = '<div class="fm-muted">Lỗi tải danh sách.</div>'; return; }
            var files = j.data || [];
            var foot = $('#fm-storage-foot');
            if (!files.length) {
                list.innerHTML = '<div class="fm-muted">Không có file nào phù hợp.</div>';
                foot.style.display = 'none';
                return;
            }
            list.innerHTML = files.map(function (f) {
                return '<label class="fm-user-row"><input type="checkbox" class="fm-stale-check" value="' + f.id + '">' +
                    '<span class="fm-check-circle"><i class="fa-solid fa-check"></i></span>' +
                    fileIcon(f) + '<span>' + esc(f.name) + '</span>' +
                    '<span class="fm-file-meta" style="margin-left:auto;">' + esc(f.size_human) + '</span></label>';
            }).join('');
            foot.style.display = 'flex';

            var allBox = $('#fm-storage-all');
            allBox.checked = false;
            allBox.onchange = function () {
                $all('.fm-stale-check', list).forEach(function (c) { c.checked = allBox.checked; });
            };
        });
    }

    (function bindStorageDelete() {
        document.addEventListener('click', function (e) {
            var btn = e.target.closest('#fm-storage-delete');
            if (!btn) return;
            var ids = $all('.fm-stale-check:checked', $('#fm-storage-list')).map(function (c) { return +c.value; });
            if (!ids.length) { toast('Chưa chọn file nào.', false); return; }

            // Xác nhận 2 bước ngay trên nút (tránh mở modal xác nhận lồng trong modal dung lượng).
            if (btn.dataset.armed !== '1') {
                btn.dataset.armed = '1';
                var original = btn.textContent;
                btn.textContent = 'Bấm lần nữa để xác nhận xóa (' + ids.length + ')';
                setTimeout(function () { btn.dataset.armed = ''; btn.textContent = original; }, 3000);
                return;
            }
            btn.dataset.armed = '';
            btn.textContent = 'Xóa đã chọn';

            api('delete_files_bulk', { ids: ids }, function (j) {
                if (j && j.success) {
                    toast('Đã xóa ' + j.count + ' file.');
                    var activeChip = $('.fm-storage-chips .fm-chip.is-active', $('#fm-modal-body'));
                    if (activeChip) loadStaleFiles(+activeChip.dataset.months);
                    loadStorageStats();
                    reload();
                } else toast('Lỗi.', false);
            });
        });
    })();

    function triggerUpload(folderId) { state.uploadTarget = folderId; $('#fm-file-input').click(); }

    /** Gửi 1 danh sách file lên `action` theo từng đợt nhỏ (dưới max_file_uploads mặc định của PHP,
     *  thường là 20) — chọn nhiều file/thư mục cùng lúc mà gửi 1 lần duy nhất sẽ khiến PHP âm thầm
     *  cắt bớt $_FILES trong khi các trường text (paths[]) vẫn còn đủ, gây lệch dữ liệu và lỗi. */
    var UPLOAD_BATCH_SIZE = 15;
    function uploadInBatches(action, staticFields, files, withPath, onFinish) {
        var list = Array.prototype.slice.call(files);
        var idx = 0, totalSaved = 0, allErrors = [];
        function next() {
            if (idx >= list.length) { onFinish(totalSaved, allErrors); return; }
            var chunk = list.slice(idx, idx + UPLOAD_BATCH_SIZE);
            idx += UPLOAD_BATCH_SIZE;
            var fd = new FormData();
            Object.keys(staticFields).forEach(function (k) { fd.append(k, staticFields[k]); });
            chunk.forEach(function (f) {
                fd.append('files[]', f);
                if (withPath) fd.append('paths[]', f.webkitRelativePath || f.name);
            });
            api(action, fd, function (j) {
                if (j && j.saved) totalSaved += j.saved;
                if (j && j.errors && j.errors.length) allErrors = allErrors.concat(j.errors);
                else if (!j || j.success === false) allErrors.push((j && j.message) || 'Lỗi không xác định.');
                next();
            }, true);
        }
        next();
    }

    function handleUpload(files) {
        if (!files || !files.length) return;
        toast('Đang tải lên…');
        uploadInBatches('upload', { folder_id: state.uploadTarget }, files, false, function (saved, errors) {
            reload();
            if (saved > 0) toast('Đã tải lên ' + saved + ' tệp.' + (errors.length ? ' (' + errors.length + ' lỗi)' : ''));
            else toast(errors[0] || 'Tải lên thất bại.', false);
        });
    }

    /** Tải cả 1 thư mục từ máy tính lên: thư mục gốc được chọn trở thành 1 "nhóm file" mới
     *  (hoặc thư mục con của folderId nếu tải từ menu ngữ cảnh 1 thư mục), giữ nguyên cây con. */
    function triggerFolderUpload(folderId) { state.uploadFolderTarget = folderId; $('#fm-folder-input').click(); }

    function handleUploadFolder(files) {
        if (!files || !files.length) return;
        toast('Đang tải thư mục lên…');
        uploadInBatches('upload_folder', { target_folder_id: state.uploadFolderTarget }, files, true, function (saved, errors) {
            reload();
            if (saved > 0) toast('Đã tải lên ' + saved + ' tệp.' + (errors.length ? ' (' + errors.length + ' lỗi)' : ''));
            else toast(errors[0] || 'Tải thư mục thất bại.', false);
        });
    }

    /** Danh sách chi tiết các tệp không tải lên được (kéo-thả từ máy tính), để user biết vì sao. */
    function showUploadErrors(errors) {
        if (!errors || !errors.length) return;
        openModal('Một số tệp không tải lên được',
            '<ul class="fm-upload-errors">' + errors.map(function (e) { return '<li>' + esc(e) + '</li>'; }).join('') + '</ul>' +
            '<div class="fm-modal-foot"><button class="fm-btn fm-btn-primary" data-close>Đã hiểu</button></div>');
    }

    /** Kéo-thả file TỪ MÁY TÍNH (ngoài trình duyệt) thả thẳng vào 1 nhóm file/thư mục con
     *  để tải lên đúng nhóm/thư mục đó — khác với kéo-thả nội bộ (sắp xếp/di chuyển) ở
     *  makeSortable(), vốn chỉ xử lý item đã có sẵn trong hệ thống (dataTransfer không có 'Files'). */
    function wireExternalFileDrop(node, getFolderId) {
        var depth = 0;
        function isFileDrag(e) {
            return !!(e.dataTransfer && Array.prototype.indexOf.call(e.dataTransfer.types || [], 'Files') >= 0);
        }
        node.addEventListener('dragenter', function (e) {
            if (!isFileDrag(e)) return;
            e.preventDefault(); e.stopPropagation();
            depth++;
            node.classList.add('fm-drop-hover');
        });
        node.addEventListener('dragover', function (e) {
            if (!isFileDrag(e)) return;
            e.preventDefault(); e.stopPropagation();
            e.dataTransfer.dropEffect = 'copy';
        });
        node.addEventListener('dragleave', function (e) {
            if (!isFileDrag(e)) return;
            e.stopPropagation();
            depth = Math.max(0, depth - 1);
            if (depth === 0) node.classList.remove('fm-drop-hover');
        });
        node.addEventListener('drop', function (e) {
            if (!isFileDrag(e) || !e.dataTransfer.files || !e.dataTransfer.files.length) return;
            e.preventDefault(); e.stopPropagation();
            depth = 0;
            node.classList.remove('fm-drop-hover');
            var files = e.dataTransfer.files;
            toast('Đang tải lên…');
            uploadInBatches('upload', { folder_id: getFolderId() }, files, false, function (saved, errors) {
                reload();
                if (saved > 0) toast('Đã tải lên ' + saved + ' tệp vào nhóm.');
                else if (!errors.length) toast('Tải lên thất bại.', false);
                showUploadErrors(errors);
            });
        });
    }

    function doSearch(kw) {
        api('search', { keyword: kw }, function (j) {
            if (!j || !j.success) return;
            $('#fm-tab-mine').style.display = 'none';
            $('#fm-tab-shared').style.display = 'none';
            $('#fm-search-results').style.display = '';
            var grid = $('#fm-search-grid'); grid.innerHTML = '';
            if (!j.data.length) { grid.innerHTML = '<div class="fm-muted">Không tìm thấy kết quả.</div>'; return; }
            j.data.forEach(function (it) {
                if (it.type === 'file') grid.appendChild(fileTile(it, true));
                else grid.appendChild(el(
                    '<div class="fm-tile fm-tile-folder" data-act="goto-folder" data-id="' + it.id + '">' +
                        '<div class="fm-tile-ic"><span class="fm-ic"><i class="fa-solid fa-folder"></i></span></div>' +
                        '<div class="fm-tile-name">' + esc(it.name) + '</div>' +
                        '<div class="fm-tile-meta">Thư mục</div>' +
                    '</div>'
                ));
            });
        });
    }
    function hideSearch() {
        $('#fm-search-results').style.display = 'none';
        render();
    }

    /** Bấm 1 thư mục trong kết quả tìm kiếm: mở hết các thư mục cha chứa nó rồi cuộn tới,
     *  nháy sáng để user dễ nhận ra vị trí — thay vì không phản ứng gì như trước. */
    function gotoFolder(id) {
        api('folder_ancestry', { id: id }, function (j) {
            if (!j || !j.success) { toast('Không tìm thấy thư mục.', false); return; }
            (j.chain || []).forEach(function (fid) { state.openFolders[fid] = true; });
            state.tab = 'mine';
            if (state.starOnly) {
                state.starOnly = false;
                var starBtn = $('#fm-filter-star');
                starBtn.classList.remove('is-active');
                starBtn.querySelector('i').className = 'fa-regular fa-star';
            }
            $('#fm-search').value = '';
            hideSearch();
            setTimeout(function () {
                var target = document.querySelector('.fm-card[data-id="' + id + '"], .fm-folder[data-id="' + id + '"]');
                if (!target) return;
                target.scrollIntoView({ behavior: 'smooth', block: 'center' });
                target.classList.add('fm-goto-flash');
                setTimeout(function () { target.classList.remove('fm-goto-flash'); }, 1600);
            }, 50);
        });
    }

    /* ---------- FAQ cố định "File hay dùng" (top 10 theo click_count) ---------- */
    function loadFrequent() {
        api('frequent_files', {}, function (j) {
            state.frequent = (j && j.success && j.data) ? j.data : [];
            renderFrequent();
        });
    }
    function renderFrequent() {
        var grid = $('#fm-frequent-grid'), empty = $('#fm-frequent-empty');
        if (!grid) return;
        grid.innerHTML = '';
        var items = state.frequent || [];
        if (empty) empty.style.display = items.length ? 'none' : '';
        items.forEach(function (f) { grid.appendChild(fileTile(f, true)); });
    }
    function bindFrequentFaq() {
        var faq = $('#fm-frequent-faq');
        if (!faq) return;
        $('.fm-faq-header', faq).addEventListener('click', function () {
            var open = faq.classList.toggle('open');
            this.setAttribute('aria-expanded', open ? 'true' : 'false');
        });
    }

    /* ================= ACTIONS (event delegation) ================= */
    function bindGlobal() {
        document.addEventListener('click', function (e) {
            var t = e.target.closest('[data-act]');
            if (!t) { closeCtx(); return; }
            var act = t.dataset.act, id = +t.dataset.id, type = t.dataset.type, share = +t.dataset.share;

            if (act === 'star') { e.preventDefault(); doStar(type, id, t); return; }
            if (act === 'open') { e.preventDefault(); window.open(ACT + 'open&id=' + id, '_blank'); api('bump_click', { id: id }, loadFrequent); return; }
            if (act === 'toggle') { e.preventDefault(); state.openFolders[id] = !state.openFolders[id]; render(); return; }
            if (act === 'goto-folder') { e.preventDefault(); gotoFolder(id); return; }
            if (act === 'folder-menu') { e.preventDefault(); folderMenu(id, t); return; }
            if (act === 'file-menu') { e.preventDefault(); fileMenu(id, t); return; }
            if (act === 'share') { e.preventDefault(); shareFileModal(id); return; }
            if (act === 'reshare') { e.preventDefault(); shareUserModal('file', id, true); return; }
            if (act === 'copy') { e.preventDefault(); doCopy(id); return; }
            if (act === 'accept') { e.preventDefault(); respond(share, true); return; }
            if (act === 'reject') { e.preventDefault(); respond(share, false); return; }
            if (act === 'addlib') { e.preventDefault(); addToLib(share); return; }
            if (act === 'leaveshare') { e.preventDefault(); leaveShare(share); return; }
            if (act === 'open-shared') {
                e.preventDefault();
                var card = t.closest('.fm-shared-card') || t.closest('.fm-folder');
                openSharedFolder(card, id);
                return;
            }
        });
    }

    function doStar(type, id, btn) {
        api('toggle_star', { type: type, id: id }, function (j) {
            if (j.success) {
                btn.classList.toggle('on', j.starred);
                btn.querySelector('i').className = 'fa-' + (j.starred ? 'solid' : 'regular') + ' fa-star';
                // cập nhật state để filter sao đúng
                updateStarState(type, id, j.starred);
            }
        });
    }
    function updateStarState(type, id, val) {
        function walk(arr) {
            (arr || []).forEach(function (n) {
                if (type === 'folder' && n.type === 'folder' && n.id === id) n.starred = val;
                if (type === 'file') (n.files || []).forEach(function (f) { if (f.id === id) f.starred = val; });
                walk(n.folders);
            });
        }
        walk(state.library.groups);
        (state.library.root_files || []).forEach(function (f) { if (type === 'file' && f.id === id) f.starred = val; });
    }

    function doCopy(id) {
        api('make_copy', { id: id, folder_id: 0 }, function (j) {
            if (j.success) { toast('Đã tạo bản sao vào thư viện của bạn.'); if (state.tab === 'mine') reload(); }
            else toast(j.message || 'Lỗi.', false);
        });
    }
    function respond(share, accept) {
        api('share_respond', { share_id: share, accept: accept ? 1 : 0 }, function (j) {
            if (j.success) { toast(accept ? 'Đã nhận chia sẻ.' : 'Đã từ chối.'); reload(); }
            else toast(j.message || 'Lỗi.', false);
        });
    }
    function addToLib(share) {
        api('add_to_library', { share_id: share }, function (j) {
            if (j.success) { toast('Đã đưa vào thư viện của bạn.'); reload(); }
            else toast('Lỗi.', false);
        });
    }
    function leaveShare(share) {
        confirmModal('Gỡ khỏi Được chia sẻ',
            'Gỡ mục này khỏi "Được chia sẻ với tôi"? Bạn sẽ mất quyền truy cập trừ khi được chia sẻ lại.',
            function () {
                api('leave_share', { share_id: share }, function (j) {
                    if (j.success) { closeModal(); toast('Đã gỡ.'); reload(); }
                    else toast(j.message || 'Lỗi.', false);
                });
            });
    }

    /* ----------- menu ngữ cảnh ----------- */
    function closeCtx() { var m = $('#fm-ctxmenu'); if (m) m.style.display = 'none'; }
    function showCtx(anchor, items) {
        var m = $('#fm-ctxmenu');
        m.innerHTML = items.map(function (it) {
            if (it.sep) return '<div class="fm-ctx-sep"></div>';
            return '<button class="fm-ctx-item' + (it.danger ? ' danger' : '') + '"><i class="fa-solid ' + it.icon + '"></i> ' + esc(it.label) + '</button>';
        }).join('');
        var btns = $all('.fm-ctx-item', m), bi = 0;
        items.forEach(function (it) {
            if (it.sep) return;
            var b = btns[bi++];
            b.addEventListener('click', function (e) { e.stopPropagation(); closeCtx(); it.fn(); });
        });
        var r = anchor.getBoundingClientRect();
        m.style.display = 'block';
        var w = m.offsetWidth, h = m.offsetHeight;
        var left = Math.min(r.left, window.innerWidth - w - 8);
        var top = r.bottom + 4;
        if (top + h > window.innerHeight) top = Math.max(8, r.top - h - 4);
        m.style.left = left + 'px';
        m.style.top = top + 'px';
    }

    function findFolder(id, arr) {
        arr = arr || state.library.groups;
        for (var i = 0; i < arr.length; i++) {
            if (arr[i].id === id) return arr[i];
            var f = findFolder(id, arr[i].folders || []);
            if (f) return f;
        }
        return null;
    }

    function folderMenu(id, anchor) {
        var f = findFolder(id);
        showCtx(anchor, [
            { icon: 'fa-cloud-arrow-up', label: 'Tải file vào đây', fn: function () { triggerUpload(id); } },
            { icon: 'fa-folder-plus', label: 'Thêm thư mục con', fn: function () {
                promptModal('Thêm thư mục con', 'Tên thư mục', '', function (name) {
                    api('create_folder', { parent_id: id, name: name }, function (j) {
                        if (j.success) { closeModal(); state.openFolders[id] = true; reload(); }
                        else toast(j.message || 'Lỗi.', false);
                    });
                });
            } },
            { sep: true },
            { icon: 'fa-pen', label: 'Đổi tên', fn: function () {
                promptModal('Đổi tên', 'Tên mới', f ? f.name : '', function (name) {
                    api('rename_folder', { id: id, name: name }, function (j) {
                        if (j.success) { closeModal(); reload(); } else toast('Lỗi.', false);
                    });
                });
            } },
            { icon: 'fa-share-nodes', label: 'Chia sẻ', fn: function () { shareUserModal('folder', id, false); } },
            { icon: 'fa-palette', label: 'Đổi màu', fn: function () { colorModal(id, f ? f.color : ''); } },
            { icon: 'fa-chart-pie', label: 'Xem dung lượng', fn: function () { showFolderStorage(id, f ? f.name : ''); } },
            { sep: true },
            { icon: 'fa-trash', label: 'Xóa', danger: true, fn: function () {
                confirmModal('Xóa thư mục', 'Xóa “' + (f ? f.name : '') + '” cùng toàn bộ thư mục con và file bên trong? Hành động không thể hoàn tác.', function () {
                    api('delete_folder', { id: id }, function (j) {
                        if (j.success) { closeModal(); reload(); toast('Đã xóa.'); } else toast('Lỗi.', false);
                    });
                });
            } }
        ]);
    }

    /** Dung lượng riêng của 1 nhóm/thư mục (tính cả thư mục con) — giúp user biết chỗ nào
     *  đang nặng để dọn dẹp, giải phóng dung lượng. */
    function showFolderStorage(id, name) {
        openModal('Dung lượng đang dùng', '<p class="fm-confirm-msg">Đang tính…</p>');
        api('folder_storage', { id: id }, function (j) {
            if (!j || !j.success) {
                $('#fm-modal-body').innerHTML = '<p class="fm-confirm-msg">Không tính được dung lượng.</p>';
                return;
            }
            $('#fm-modal-body').innerHTML =
                '<p class="fm-confirm-msg"><b>' + esc(name) + '</b> đang dùng <b>' + esc(j.bytes_human) + '</b> (' + (+j.count) + ' tệp), tính cả thư mục con bên trong.</p>' +
                '<div class="fm-modal-foot"><button class="fm-btn fm-btn-primary" data-close>Đóng</button></div>';
        });
    }

    function findFileEverywhere(id) {
        var found = null;
        (state.library.root_files || []).forEach(function (f) { if (f.id === id) found = f; });
        function walk(arr) { (arr || []).forEach(function (n) { (n.files || []).forEach(function (f) { if (f.id === id) found = f; }); walk(n.folders); }); }
        walk(state.library.groups);
        return found;
    }

    function fileMenu(id, anchor) {
        var f = findFileEverywhere(id);
        showCtx(anchor, [
            { icon: 'fa-download', label: 'Tải xuống', fn: function () { window.location = DL + id; } },
            { icon: 'fa-pen', label: 'Đổi tên', fn: function () {
                promptModal('Đổi tên', 'Tên mới', f ? f.name : '', function (name) {
                    api('rename_file', { id: id, name: name }, function (j) {
                        if (j.success) { closeModal(); reload(); } else toast('Lỗi.', false);
                    });
                });
            } },
            { icon: 'fa-copy', label: 'Tạo bản sao', fn: function () { doCopy(id); } },
            { icon: 'fa-arrows-up-down-left-right', label: 'Di chuyển tới…', fn: function () { moveModal(id); } },
            { icon: 'fa-share-nodes', label: 'Chia sẻ', fn: function () { shareFileModal(id); } },
            { sep: true },
            { icon: 'fa-trash', label: 'Xóa', danger: true, fn: function () {
                confirmModal('Xóa tệp', 'Xóa “' + (f ? f.name : '') + '”?', function () {
                    api('delete_file', { id: id }, function (j) {
                        if (j.success) { closeModal(); reload(); toast('Đã xóa.'); } else toast('Lỗi.', false);
                    });
                });
            } }
        ]);
    }

    /* ================= MODALS ================= */
    function openModal(title, bodyHtml) {
        $('#fm-modal-title').textContent = title;
        $('#fm-modal-body').innerHTML = bodyHtml;
        $('#fm-modal').style.display = 'flex';
    }
    function closeModal() { $('#fm-modal').style.display = 'none'; }

    function promptModal(title, label, val, onOk) {
        openModal(title,
            '<label class="fm-field-label">' + esc(label) + '</label>' +
            '<input type="text" class="fm-input" id="fm-prompt-input" value="' + esc(val) + '">' +
            '<div class="fm-modal-foot"><button class="fm-btn" data-close>Hủy</button>' +
            '<button class="fm-btn fm-btn-primary" id="fm-prompt-ok">Lưu</button></div>');
        var inp = $('#fm-prompt-input');
        inp.focus(); inp.select();
        function ok() { var v = inp.value.trim(); if (v === '') { inp.focus(); return; } onOk(v); }
        $('#fm-prompt-ok').addEventListener('click', ok);
        inp.addEventListener('keydown', function (e) { if (e.key === 'Enter') ok(); });
    }

    function confirmModal(title, msg, onOk) {
        openModal(title,
            '<p class="fm-confirm-msg">' + esc(msg) + '</p>' +
            '<div class="fm-modal-foot"><button class="fm-btn" data-close>Hủy</button>' +
            '<button class="fm-btn fm-btn-danger" id="fm-confirm-ok">Xóa</button></div>');
        $('#fm-confirm-ok').addEventListener('click', onOk);
    }

    function colorModal(id, cur) {
        var colors = ['#ef4444', '#f59e0b', '#10b981', '#3b82f6', '#8b5cf6', '#ec4899', '#64748b', '#0ea5e9'];
        openModal('Đổi màu nhóm',
            '<div class="fm-colors">' + colors.map(function (c) {
                return '<button class="fm-color-dot ' + (c === cur ? 'on' : '') + '" data-color="' + c + '" style="background:' + c + '"></button>';
            }).join('') + '<button class="fm-color-dot fm-color-none" data-color="">×</button></div>');
        $all('.fm-color-dot', $('#fm-modal-body')).forEach(function (b) {
            b.addEventListener('click', function () {
                api('set_folder_color', { id: id, color: b.dataset.color }, function (j) {
                    if (j.success) { closeModal(); reload(); }
                });
            });
        });
    }

    function moveModal(fileId) {
        // danh sách thư mục phẳng (có thụt lề)
        var opts = '<option value="0">— Thư viện của tôi (gốc) —</option>';
        function walk(arr, depth) {
            (arr || []).forEach(function (f) {
                opts += '<option value="' + f.id + '">' + '&nbsp;'.repeat(depth * 3) + esc(f.name) + '</option>';
                walk(f.folders, depth + 1);
            });
        }
        walk(state.library.groups, 0);
        openModal('Di chuyển tệp tới',
            '<select class="fm-input" id="fm-move-select">' + opts + '</select>' +
            '<div class="fm-modal-foot"><button class="fm-btn" data-close>Hủy</button>' +
            '<button class="fm-btn fm-btn-primary" id="fm-move-ok">Di chuyển</button></div>');
        $('#fm-move-ok').addEventListener('click', function () {
            api('move_file', { id: fileId, folder_id: $('#fm-move-select').value }, function (j) {
                if (j.success) { closeModal(); reload(); toast('Đã di chuyển.'); } else toast('Lỗi.', false);
            });
        });
    }

    /* ----- Chia sẻ với user trong hệ thống (folder hoặc file) ----- */
    function shareUserModal(type, id, isReshare) {
        var userRows = state.users.map(function (u) {
            return '<label class="fm-user-row"><input type="checkbox" value="' + u.id + '"><span class="fm-check-circle"><i class="fa-solid fa-check"></i></span>' +
                (u.avatar ? '<img class="fm-ava" src="' + esc(u.avatar) + '">' : '<span class="fm-ava fm-ava-x">' + esc(u.name.charAt(0)) + '</span>') +
                '<span>' + esc(u.name) + '</span></label>';
        }).join('');
        openModal((isReshare ? 'Chia sẻ tiếp ' : 'Chia sẻ ') + (type === 'folder' ? 'nhóm file' : 'tệp'),
            '<input type="text" class="fm-input" id="fm-user-filter" placeholder="Lọc người dùng...">' +
            '<div class="fm-user-list" id="fm-user-list">' + userRows + '</div>' +
            '<div id="fm-current-shares"></div>' +
            '<div class="fm-modal-foot"><button class="fm-btn" data-close>Đóng</button>' +
            '<button class="fm-btn fm-btn-primary" id="fm-share-ok">Chia sẻ</button></div>');

        $('#fm-user-filter').addEventListener('input', function () {
            var kw = this.value.toLowerCase();
            $all('.fm-user-row', $('#fm-user-list')).forEach(function (r) {
                r.style.display = r.textContent.toLowerCase().indexOf(kw) >= 0 ? '' : 'none';
            });
        });
        $('#fm-share-ok').addEventListener('click', function () {
            var targets = $all('#fm-user-list input:checked').map(function (c) { return +c.value; });
            if (!targets.length) { toast('Chưa chọn người nhận.', false); return; }
            api('share_user', { item_type: type, item_id: id, targets: targets }, function (j) {
                if (j.success) { toast('Đã chia sẻ với ' + j.shared + ' người.'); loadCurrentShares(type, id); $all('#fm-user-list input:checked').forEach(function (c) { c.checked = false; }); }
                else toast(j.message || 'Lỗi.', false);
            });
        });
        if (!isReshare) loadCurrentShares(type, id);
    }

    function loadCurrentShares(type, id) {
        var box = $('#fm-current-shares'); if (!box) return;
        api('share_list', { item_type: type, item_id: id }, function (j) {
            if (!j.success || !j.data.length) { box.innerHTML = ''; return; }
            box.innerHTML = '<div class="fm-field-label">Đang chia sẻ với</div>' + j.data.map(function (s) {
                var st = s.status === 'pending' ? '<span class="fm-tag warn">Chờ nhận</span>'
                    : s.status === 'accepted' ? '<span class="fm-tag ok">Đã nhận</span>'
                    : s.status === 'revoked' ? '<span class="fm-tag">Đã gỡ</span>' : '<span class="fm-tag">Từ chối</span>';
                var lib = s.in_my_library ? ' <span class="fm-tag">trong thư viện họ</span>' : '';
                var btn = (s.status !== 'revoked' && !s.in_my_library)
                    ? '<button class="fm-link danger" data-revoke="' + s.share_id + '">Gỡ</button>' : '';
                return '<div class="fm-share-row">' +
                    (s.avatar ? '<img class="fm-ava sm" src="' + esc(s.avatar) + '">' : '<span class="fm-ava sm fm-ava-x">' + esc(s.name.charAt(0)) + '</span>') +
                    '<span>' + esc(s.name) + '</span>' + st + lib + btn + '</div>';
            }).join('');
            $all('[data-revoke]', box).forEach(function (b) {
                b.addEventListener('click', function () {
                    api('revoke_share', { share_id: +b.dataset.revoke }, function (r) {
                        if (r.success) { loadCurrentShares(type, id); toast('Đã gỡ chia sẻ.'); }
                        else toast(r.message || 'Không gỡ được.', false);
                    });
                });
            });
        });
    }

    /* ----- Chia sẻ 1 FILE: 3 tab (Người dùng / Chat / Dự án) ----- */
    function shareFileModal(fileId) {
        openModal('Chia sẻ tệp',
            '<div class="fm-tabs">' +
                '<button class="fm-mtab is-active" data-mtab="user">Người dùng</button>' +
                '<button class="fm-mtab" data-mtab="chat">Gửi qua Chat</button>' +
                '<button class="fm-mtab" data-mtab="project">Vào Dự án</button>' +
            '</div>' +
            '<div class="fm-mtab-body" id="fm-mtab-user"></div>' +
            '<div class="fm-mtab-body" id="fm-mtab-chat" style="display:none;"></div>' +
            '<div class="fm-mtab-body" id="fm-mtab-project" style="display:none;"></div>');

        $all('.fm-mtab', $('#fm-modal-body')).forEach(function (b) {
            b.addEventListener('click', function () {
                $all('.fm-mtab').forEach(function (x) { x.classList.remove('is-active'); });
                b.classList.add('is-active');
                ['user', 'chat', 'project'].forEach(function (k) {
                    $('#fm-mtab-' + k).style.display = b.dataset.mtab === k ? '' : 'none';
                });
            });
        });

        // TAB user
        var userRows = state.users.map(function (u) {
            return '<label class="fm-user-row"><input type="checkbox" value="' + u.id + '"><span class="fm-check-circle"><i class="fa-solid fa-check"></i></span>' +
                (u.avatar ? '<img class="fm-ava" src="' + esc(u.avatar) + '">' : '<span class="fm-ava fm-ava-x">' + esc(u.name.charAt(0)) + '</span>') +
                '<span>' + esc(u.name) + '</span></label>';
        }).join('');
        $('#fm-mtab-user').innerHTML =
            '<input type="text" class="fm-input" id="fm-fu-filter" placeholder="Lọc người dùng...">' +
            '<div class="fm-user-list" id="fm-fu-list">' + userRows + '</div>' +
            '<div id="fm-current-shares"></div>' +
            '<div class="fm-modal-foot"><button class="fm-btn fm-btn-primary" id="fm-fu-ok">Chia sẻ</button></div>';
        $('#fm-fu-filter').addEventListener('input', function () {
            var kw = this.value.toLowerCase();
            $all('.fm-user-row', $('#fm-fu-list')).forEach(function (r) { r.style.display = r.textContent.toLowerCase().indexOf(kw) >= 0 ? '' : 'none'; });
        });
        $('#fm-fu-ok').addEventListener('click', function () {
            var targets = $all('#fm-fu-list input:checked').map(function (c) { return +c.value; });
            if (!targets.length) { toast('Chưa chọn người nhận.', false); return; }
            api('share_user', { item_type: 'file', item_id: fileId, targets: targets }, function (j) {
                if (j.success) { toast('Đã chia sẻ.'); loadCurrentShares('file', fileId); }
                else toast(j.message || 'Lỗi.', false);
            });
        });
        loadCurrentShares('file', fileId);

        // TAB chat
        var chatRows = state.users.map(function (u) {
            return '<label class="fm-user-row"><input type="radio" name="fm-chat-u" value="' + u.id + '">' +
                (u.avatar ? '<img class="fm-ava" src="' + esc(u.avatar) + '">' : '<span class="fm-ava fm-ava-x">' + esc(u.name.charAt(0)) + '</span>') +
                '<span>' + esc(u.name) + '</span></label>';
        }).join('');
        $('#fm-mtab-chat').innerHTML =
            '<p class="fm-muted">Gửi tệp này vào hộp thoại chat với một đồng nghiệp.</p>' +
            '<div class="fm-user-list">' + chatRows + '</div>' +
            '<input type="text" class="fm-input" id="fm-chat-note" placeholder="Lời nhắn (tuỳ chọn)">' +
            '<div class="fm-modal-foot"><button class="fm-btn fm-btn-primary" id="fm-chat-ok">Gửi qua Chat</button></div>';
        $('#fm-chat-ok').addEventListener('click', function () {
            var u = $('input[name=fm-chat-u]:checked');
            if (!u) { toast('Chọn người nhận.', false); return; }
            api('share_to_chat', { file_id: fileId, target_id: u.value, note: $('#fm-chat-note').value }, function (j) {
                if (j.success) { toast('Đã gửi qua chat.'); closeModal(); } else toast(j.message || 'Lỗi.', false);
            });
        });

        // TAB project
        var projOpts = '<option value="">— Chọn dự án —</option>' + state.projects.map(function (p) {
            return '<option value="' + p.id + '">' + esc(p.name) + '</option>';
        }).join('');
        $('#fm-mtab-project').innerHTML = state.projects.length
            ? ('<label class="fm-field-label">Dự án của bạn</label><select class="fm-input" id="fm-proj-sel">' + projOpts + '</select>' +
               '<label class="fm-field-label">Phiên làm việc (gần đây nhất ở trên)</label><select class="fm-input" id="fm-sess-sel" disabled><option>— Chọn dự án trước —</option></select>' +
               '<input type="text" class="fm-input" id="fm-proj-note" placeholder="Lời nhắn (tuỳ chọn)">' +
               '<div class="fm-modal-foot"><button class="fm-btn fm-btn-primary" id="fm-proj-ok">Chia sẻ vào dự án</button></div>')
            : '<p class="fm-muted">Bạn chưa tham gia dự án nào.</p>';

        if (state.projects.length) {
            $('#fm-proj-sel').addEventListener('change', function () {
                var pid = this.value, ss = $('#fm-sess-sel');
                if (!pid) { ss.disabled = true; ss.innerHTML = '<option>— Chọn dự án trước —</option>'; return; }
                ss.disabled = true; ss.innerHTML = '<option>Đang tải…</option>';
                api('project_sessions', { project_id: pid }, function (j) {
                    if (j.success && j.data.length) {
                        ss.innerHTML = j.data.map(function (s, i) {
                            return '<option value="' + s.id + '">' + esc(s.name) + (i === 0 ? ' (gần đây nhất)' : '') + '</option>';
                        }).join('');
                        ss.disabled = false;
                    } else { ss.innerHTML = '<option value="">(Dự án chưa có phiên)</option>'; }
                });
            });
            $('#fm-proj-ok').addEventListener('click', function () {
                var pid = $('#fm-proj-sel').value, sid = $('#fm-sess-sel').value;
                if (!pid || !sid) { toast('Chọn dự án & phiên.', false); return; }
                api('share_to_project', { file_id: fileId, project_id: pid, session_id: sid, note: $('#fm-proj-note').value }, function (j) {
                    if (j.success) { toast('Đã chia sẻ vào dự án.'); closeModal(); } else toast(j.message || 'Lỗi.', false);
                });
            });
        }
    }

    /* ================= DRAG & DROP (sắp xếp + di chuyển giữa thư mục) =================
     * File (data-type="file") kéo được sang BẤT KỲ container file nào khác (root files,
     * hoặc .fm-filelist của 1 thư mục khác đang mở) — giống di chuyển card Trello giữa
     * các list. Thư mục (data-type="folder") vẫn chỉ sắp xếp trong cùng container như cũ. */
    var dragEl = null, dragList = null, dragType = null, dragFromParent = null;
    function makeSortable(container) {
        if (!container || container._sortable) return;
        container._sortable = true;
        var myType = container.dataset.type;
        container.addEventListener('dragstart', function (e) {
            var item = e.target.closest('.fm-draggable');
            if (!item || item.parentNode !== container) return;
            dragEl = item; dragList = container; dragType = myType;
            dragFromParent = container.dataset.parent;
            item.classList.add('dragging');
            e.dataTransfer.effectAllowed = 'move';
            try { e.dataTransfer.setData('text/plain', item.dataset.id); } catch (x) {}
        });
        container.addEventListener('dragend', function () {
            if (dragEl) dragEl.classList.remove('dragging');
            dragEl = null; dragList = null; dragType = null; dragFromParent = null;
        });
        container.addEventListener('dragover', function (e) {
            if (myType === 'file' && e.dataTransfer && Array.prototype.indexOf.call(e.dataTransfer.types || [], 'application/x-fm-shared-copy') >= 0) {
                e.preventDefault();
                e.dataTransfer.dropEffect = 'copy';
                return;
            }
            if (!dragEl) return;
            var crossAllowed = dragType === 'file' && myType === 'file';
            if (dragList !== container && !crossAllowed) return;
            e.preventDefault();
            e.stopPropagation();
            var after = afterElement(container, e.clientY);
            if (after == null) { if (container.lastElementChild !== dragEl) container.appendChild(dragEl); }
            else if (after !== dragEl) container.insertBefore(dragEl, after);
            dragList = container; // đã re-parent trong DOM sang container đang hover
        });
        container.addEventListener('drop', function (e) {
            var sharedId = myType === 'file' && e.dataTransfer && e.dataTransfer.getData('application/x-fm-shared-copy');
            if (sharedId) {
                e.preventDefault();
                e.stopPropagation();
                var newFolder = +container.dataset.parent || 0;
                api('make_copy', { id: +sharedId, folder_id: newFolder }, function (j) {
                    if (j && j.success) { toast('Đã tạo bản sao vào thư viện của bạn.'); reload(); }
                    else toast((j && j.message) || 'Không tạo được bản sao.', false);
                });
                return;
            }
            if (!dragEl || dragList !== container) return;
            e.preventDefault();
            e.stopPropagation();
            var moved = dragType === 'file' && String(dragFromParent) !== String(container.dataset.parent);
            if (moved) {
                var fileId = +dragEl.dataset.id;
                var newFolder = +container.dataset.parent || 0;
                api('move_file', { id: fileId, folder_id: newFolder }, function (j) {
                    if (j && j.success) { saveOrder(container); reload(); }
                    else { toast((j && j.message) || 'Không di chuyển được.', false); reload(); }
                });
            } else {
                saveOrder(container);
            }
        });
    }
    function afterElement(container, y) {
        var items = $all('.fm-draggable:not(.dragging)', container).filter(function (c) { return c.parentNode === container; });
        var closest = { off: -Infinity, el: null };
        items.forEach(function (child) {
            var box = child.getBoundingClientRect();
            var off = y - box.top - box.height / 2;
            if (off < 0 && off > closest.off) closest = { off: off, el: child };
        });
        return closest.el;
    }
    function saveOrder(container) {
        var type = container.dataset.type;
        var parent = container.dataset.parent;
        var ids = $all('.fm-draggable', container).filter(function (c) { return c.parentNode === container; })
            .map(function (c) { return +c.dataset.id; });
        if (!ids.length) return;
        api('reorder', { type: type, parent_id: parent, ids: ids }, function (j) {
            if (j.success) { container.classList.add('reorder-ok'); setTimeout(function () { container.classList.remove('reorder-ok'); }, 500); }
        });
    }

    /* ----- đóng modal / menu ----- */
    document.addEventListener('click', function (e) {
        if (e.target.id === 'fm-modal' || e.target.closest('#fm-modal-close') || e.target.closest('[data-close]')) closeModal();
    });
    document.addEventListener('keydown', function (e) { if (e.key === 'Escape') { closeModal(); closeCtx(); } });
    window.addEventListener('scroll', closeCtx, true);

    if (document.readyState !== 'loading') boot();
    else document.addEventListener('DOMContentLoaded', boot);
})();
