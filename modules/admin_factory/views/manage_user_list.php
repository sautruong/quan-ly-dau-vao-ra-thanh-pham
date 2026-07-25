<?php
defined('APPPATH') OR exit('Không được quyền truy cập phần này');
/** @var array $users */
/** @var array $status_options */
/** @var array $classifications */
$users          = isset($users) && is_array($users) ? $users : [];
$status_options = isset($status_options) && is_array($status_options) ? $status_options : [];
$classifications = isset($classifications) && is_array($classifications) ? $classifications : [];
$is_admin       = isset($is_admin) ? (bool) $is_admin : true;
$deputy_ids     = isset($deputy_ids) && is_array($deputy_ids) ? array_map('intval', $deputy_ids) : [];
$ajax_base      = '?mod=admin_factory&controllers=admin&action=';
?>
<!DOCTYPE html>
<html lang="vi">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>QUẢN LÝ NGƯỜI DÙNG</title>
    <link rel="icon" href="public/images/logo/logo_vat_png.png" type="image/png">
    <link rel="stylesheet" href="public/css/reset.css">
    <link rel="stylesheet" href="public/css/all.css">
    <link rel="stylesheet" href="public/css/global.css">
    <link rel="stylesheet" href="public/css/inventory_management/dashboard.css">
    <link rel="stylesheet" href="public/css/inventory_management/investment_products.css">
    <link rel="stylesheet" href="public/css/accounting/journal_entry.css">
    <style>
        /* ----- Lưới thẻ người dùng: 4 thẻ / hàng, style như "modal avatar" ----- */
        .user-grid {
            display: grid;
            grid-template-columns: repeat(4, 1fr);
            gap: 18px;
            width: 100%;
        }
        .user-empty {
            grid-column: 1 / -1; text-align: center; padding: 30px;
            color: #6b7280; font-style: italic;
            border: 2px dashed #e5e7eb; border-radius: 12px; background: #fff;
        }
        .user-card {
            display: flex; flex-direction: column;
            background: #fff; border: 1px solid #e5e7eb; border-radius: 14px;
            box-shadow: 0 1px 3px rgba(0,0,0,0.08);
            padding: 16px; transition: box-shadow .25s ease, transform .25s ease, border-color .25s ease;
        }
        .user-card:hover { border-color: #16a34a; box-shadow: 0 8px 20px rgba(0,0,0,0.10); transform: translateY(-2px); }

        /* Trên: avatar + thông tin chính */
        .user-card-head {
            display: flex; gap: 12px; align-items: center;
            padding-bottom: 14px; border-bottom: 1px solid #f3f4f6;
        }
        .user-card-main { min-width: 0; flex: 1; }
        .user-card-name {
            font-size: 15px; font-weight: 700; color: #111827; margin-bottom: 4px;
            white-space: nowrap; overflow: hidden; text-overflow: ellipsis;
        }
        .user-card-username {
            font-size: 12.5px; color: #6b7280; display: flex; align-items: center; gap: 6px;
            white-space: nowrap; overflow: hidden; text-overflow: ellipsis;
        }
        .user-card-username i { width: 13px; color: #d1d5db; }
        .user-role-badge {
            display: inline-block; margin-top: 8px; padding: 2px 10px; border-radius: 999px;
            font-size: 11px; font-weight: 700;
        }
        .user-role-badge.role-admin  { background: #fef3c7; color: #92400e; }
        .user-role-badge.role-deputy { background: #fdf6e3; color: #111827; }
        .user-role-badge.role-user   { background: #e5e7eb; color: #374151; }

        /* Dưới: thông tin phụ (thay cho đổi mật khẩu / đăng xuất) */
        .user-card-body { padding: 12px 0 4px; display: flex; flex-direction: column; gap: 8px; }
        .user-card-row {
            font-size: 12.5px; color: #4b5563; display: flex; align-items: center; gap: 8px;
            min-width: 0;
        }
        .user-card-row i { width: 15px; color: #9ca3af; text-align: center; flex-shrink: 0; }
        .user-card-row span { white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }

        /* Chân thẻ: trạng thái (chỉnh sửa được) */
        .user-card-foot {
            margin-top: auto; padding-top: 12px; border-top: 1px solid #f3f4f6;
            display: flex; align-items: center; gap: 8px;
        }
        .user-card-foot-label { font-size: 12px; font-weight: 600; color: #6b7280; flex-shrink: 0; }
        select.status-select {
            flex: 1; box-sizing: border-box; padding: 6px 8px;
            border: 1px solid #d0d4da; border-radius: 8px; font-size: 13px; cursor: pointer;
            outline: none; transition: border-color .15s ease, box-shadow .15s ease;
        }
        select.status-select:focus { border-color: #16a34a; box-shadow: 0 0 0 2px rgba(22,163,74,0.15); }
        /* Phân loại user (combobox nhập tay + gợi ý) */
        .user-card-foot-class { border-top: 1px solid #f3f4f6; margin-top: auto; }
        .user-card-foot-class + .user-card-foot { margin-top: 0; }
        input.classification-input {
            flex: 1; box-sizing: border-box; padding: 6px 8px; min-width: 0;
            border: 1px solid #d0d4da; border-radius: 8px; font-size: 13px;
            outline: none; transition: border-color .15s ease, box-shadow .15s ease;
        }
        input.classification-input:focus { border-color: #2563eb; box-shadow: 0 0 0 2px rgba(37,99,235,0.15); }

        @media (max-width: 1200px) { .user-grid { grid-template-columns: repeat(3, 1fr); } }
        @media (max-width: 860px)  { .user-grid { grid-template-columns: repeat(2, 1fr); } }
        @media (max-width: 560px)  { .user-grid { grid-template-columns: 1fr; } }
        .status-active   { color: #096926; font-weight: 600; }
        .status-pending  { color: #b8860b; font-weight: 600; }
        .status-inactive { color: #b22; font-weight: 600; }
        .save-flash { transition: background-color 0.4s ease; }
        .save-flash.ok  { background-color: #e6f7e6; }
        .save-flash.err { background-color: #fde2e2; }
        .toast {
            position: fixed; right: 16px; bottom: 16px;
            background: #333; color: #fff; padding: 10px 14px; border-radius: 4px;
            font-size: 13px; opacity: 0; transform: translateY(8px); transition: opacity .2s, transform .2s;
            z-index: 9999;
        }
        .toast.show { opacity: 1; transform: translateY(0); }
        .toast.err  { background: #b22; }
        .toolbar { display: flex; align-items: center; justify-content: space-between; margin: 0 0 10px; }
        .toolbar .search-input {
            padding: 6px 10px; min-width: 300px; border: 1px solid #c2c8d0;
            border-radius: 4px; font-size: 13px;
        }
        /* Nút "Quản lý" + menu trong thẻ user */
        .user-card-head { position: relative; }
        .user-card-manage { position: absolute; top: -4px; right: -4px; }
        .user-manage-btn {
            border: 0; background: transparent; color: #9ca3af; cursor: pointer;
            width: 28px; height: 28px; border-radius: 6px; font-size: 15px;
        }
        .user-manage-btn:hover { background: #f3f4f6; color: #374151; }
        .user-manage-menu {
            display: none; position: absolute; top: 28px; right: 0; z-index: 20;
            background: #fff; border: 1px solid #e5e7eb; border-radius: 8px;
            box-shadow: 0 8px 20px rgba(0,0,0,0.12); min-width: 190px; padding: 6px;
        }
        .user-card-manage.open .user-manage-menu { display: block; }
        .user-manage-menu button {
            display: flex; align-items: center; gap: 8px; width: 100%; border: 0;
            background: transparent; padding: 8px 10px; border-radius: 6px; cursor: pointer;
            font-size: 13px; color: #b22; text-align: left;
        }
        .user-manage-menu button:hover { background: #fde2e2; }
        .user-left-badge {
            display: inline-flex; align-items: center; gap: 7px; font-size: 13px; font-weight: 700;
            color: #b22; background: #fde2e2; padding: 6px 12px; border-radius: 999px;
        }
        .user-card.is-left { opacity: .8; }
        /* Modal OTP buộc rời hệ thống */
        .fl-backdrop {
            position: fixed; inset: 0; background: rgba(0,0,0,.45); display: none;
            align-items: center; justify-content: center; z-index: 1000; padding: 20px;
        }
        .fl-backdrop.show { display: flex; }
        .fl-modal { background: #fff; border-radius: 10px; width: 430px; max-width: 92vw; overflow: hidden;
            box-shadow: 0 12px 34px rgba(0,0,0,.25); }
        .fl-head { padding: 14px 18px; font-size: 16px; font-weight: 700; color: #fff; background: #b22; }
        .fl-body { padding: 18px; }
        .fl-sub { font-size: 13px; color: #444; margin: 0 0 14px; }
        .fl-otp { display: flex; gap: 8px; justify-content: space-between; }
        .fl-otp input {
            width: 100%; aspect-ratio: 1/1; text-align: center; font-size: 22px; font-weight: 700;
            text-transform: uppercase; border: 1px solid #c2c8d0; border-radius: 8px; outline: none; box-sizing: border-box;
        }
        .fl-otp input:focus { border-color: #b22; box-shadow: 0 0 0 2px rgba(187,34,34,.2); }
        .fl-msg { min-height: 18px; font-size: 13px; font-style: italic; margin: 10px 0 0; text-align: center; }
        .fl-msg.err { color: #b22; } .fl-msg.ok { color: #096926; }
        .fl-foot { padding: 14px 18px; display: flex; justify-content: space-between; align-items: center; border-top: 1px solid #eee; }
        #flCountdown { font-size: 13px; color: #b22; font-weight: 600; }
    </style>
    <link rel="stylesheet" href="<?php echo asset_ver('public/css/shared/app_shell.css'); ?>">
</head>

<body>
    <div id="wrapper">
        <?php get_sidebar('app'); ?>
        <?php get_header('app'); ?>
        <div class="header" style="display:none" aria-hidden="true">
            <input type="checkbox" id="menu-toggle" class="menu-toggle">
            <div class="wp-logo-title">
                <div class="logo">
                    <a href="?mod=home">
                        <img src="public/images/logo/logo_vat_png.png" alt="" style="width:40px">
                    </a>
                </div>
                <div class="title">
                    <h1>QUẢN LÝ NGƯỜI DÙNG</h1>
                </div>
                <label for="menu-toggle" class="menu-toggle-btn" aria-label="Mở menu">
                    <i class="fa-solid fa-bars"></i>
                </label>
            </div>
            <nav>
                <ul class="main-tab">
                    <li class="tab-item active">
                        <a href="?mod=admin_factory&controllers=admin&action=manage_user_list">Quản lý người dùng</a>
                    </li>
                </ul>
            </nav>
        </div>

        <?php
        // Dự phòng: nếu header chưa nạp helper avatar (hiếm), tự định nghĩa bản tối giản.
        if (!function_exists('app_header_avatar_html')) {
            function app_header_avatar_html($avatar, $initial, $extra_class = '')
            {
                if ($avatar !== '') {
                    $src = htmlspecialchars('public/images/avatar/' . $avatar, ENT_QUOTES, 'UTF-8');
                    return '<span class="app-avatar ' . $extra_class . ' has-img"><img src="' . $src . '" alt="avatar"></span>';
                }
                return '<span class="app-avatar ' . $extra_class . '">' . htmlspecialchars($initial, ENT_QUOTES, 'UTF-8') . '</span>';
            }
        }
        ?>
        <div class="content">
            <div class="toolbar">
                <input type="text" class="search-input" id="searchInput" placeholder="Tìm theo họ và tên...">
            </div>
            <div class="user-grid" id="userGrid">
                <?php if (empty($users)): ?>
                    <div class="user-empty">Chưa có người dùng</div>
                <?php else: foreach ($users as $u):
                    $uid          = (int) $u['id'];
                    $fullname_raw = (string) ($u['fullname'] ?? '');
                    $fullname     = htmlspecialchars($fullname_raw, ENT_QUOTES, 'UTF-8');
                    $dob_raw      = (string) ($u['dateofbirth'] ?? '');
                    // Hiển thị ngày sinh dạng dd/mm/yyyy
                    $dob = '';
                    if ($dob_raw !== '' && $dob_raw !== '0000-00-00') {
                        $ts  = strtotime($dob_raw);
                        $dob = $ts ? date('d/m/Y', $ts) : htmlspecialchars($dob_raw, ENT_QUOTES, 'UTF-8');
                    }
                    $gender   = (string) ($u['gender'] ?? '');
                    $gender_label = $gender === 'M' ? 'Nam' : ($gender === 'F' ? 'Nữ' : '');
                    $phone    = htmlspecialchars((string) ($u['phone'] ?? ''), ENT_QUOTES, 'UTF-8');
                    $email    = htmlspecialchars((string) ($u['email'] ?? ''), ENT_QUOTES, 'UTF-8');
                    $username = htmlspecialchars((string) ($u['username'] ?? ''), ENT_QUOTES, 'UTF-8');
                    $status   = (string) ($u['status'] ?? '');
                    $role     = (string) ($u['role'] ?? 'user');
                    $avatar   = trim((string) ($u['avatar'] ?? ''));
                    $initial  = $fullname_raw !== '' ? mb_strtoupper(mb_substr($fullname_raw, 0, 1, 'UTF-8'), 'UTF-8') : '?';
                    $is_deputy  = in_array($uid, $deputy_ids, true);
                    // Admin phó: badge "Quản trị viên" (vàng nhạt, chữ đen) để phân biệt admin trưởng.
                    $badge_class = $role === 'admin' ? 'role-admin' : ($is_deputy ? 'role-deputy' : 'role-user');
                    $role_label  = $role === 'admin' ? 'Quản trị viên' : ($is_deputy ? 'Quản trị viên' : 'Người dùng');
                    $is_left    = ($status === 'left');
                ?>
                    <div class="user-card<?php echo $is_left ? ' is-left' : ''; ?>" data-user-id="<?php echo $uid; ?>"
                         data-fullname="<?php echo $fullname; ?>" data-status="<?php echo htmlspecialchars($status, ENT_QUOTES, 'UTF-8'); ?>">
                        <!-- Trên: avatar + thông tin chính -->
                        <div class="user-card-head">
                            <?php echo app_header_avatar_html($avatar, $initial, 'app-avatar-lg'); ?>
                            <div class="user-card-main">
                                <div class="user-card-name"><?php echo $fullname; ?></div>
                                <div class="user-card-username"><i class="fa-solid fa-user"></i> <?php echo $username; ?></div>
                                <span class="user-role-badge <?php echo $badge_class; ?>"><?php echo $role_label; ?></span>
                            </div>
                            <?php if (!$is_left && $role !== 'admin'): ?>
                            <div class="user-card-manage">
                                <button type="button" class="user-manage-btn" title="Quản lý"><i class="fa-solid fa-ellipsis-vertical"></i></button>
                                <div class="user-manage-menu">
                                    <button type="button" class="user-force-leave"><i class="fa-solid fa-person-walking-arrow-right"></i> Buộc rời hệ thống</button>
                                </div>
                            </div>
                            <?php endif; ?>
                        </div>

                        <!-- Dưới: thông tin phụ khác trong tbl_users -->
                        <div class="user-card-body">
                            <div class="user-card-row"><i class="fa-solid fa-envelope"></i> <span title="<?php echo $email; ?>"><?php echo $email !== '' ? $email : '—'; ?></span></div>
                            <div class="user-card-row"><i class="fa-solid fa-phone"></i> <span><?php echo $phone !== '' ? $phone : '—'; ?></span></div>
                            <div class="user-card-row"><i class="fa-solid fa-cake-candles"></i> <span><?php echo $dob !== '' ? $dob : '—'; ?></span></div>
                            <div class="user-card-row"><i class="fa-solid fa-venus-mars"></i> <span><?php echo $gender_label !== '' ? $gender_label : '—'; ?></span></div>
                        </div>

                        <?php if ($is_left): ?>
                        <!-- Tài khoản đã rời hệ thống -->
                        <div class="user-card-foot">
                            <span class="user-left-badge"><i class="fa-solid fa-person-walking-arrow-right"></i> Đã rời hệ thống</span>
                        </div>
                        <?php else: ?>
                        <!-- Phân loại user (đặt trên trạng thái) -->
                        <?php
                            $cls_name = htmlspecialchars((string) ($u['classification_name'] ?? ''), ENT_QUOTES, 'UTF-8');
                        ?>
                        <div class="user-card-foot user-card-foot-class">
                            <span class="user-card-foot-label">Phân loại user</span>
                            <input type="text" class="classification-input" list="classification-options"
                                   value="<?php echo $cls_name; ?>"
                                   placeholder="Chọn / nhập phân loại…" autocomplete="off"
                                   <?php echo $is_admin ? '' : 'disabled'; ?>>
                        </div>

                        <!-- Chân: trạng thái tài khoản (admin trưởng & admin phó đều chỉnh được) -->
                        <div class="user-card-foot">
                            <span class="user-card-foot-label">Trạng thái</span>
                            <select class="status-select status-<?php echo htmlspecialchars($status, ENT_QUOTES, 'UTF-8'); ?>">
                                <?php foreach ($status_options as $val => $label):
                                    $val_esc   = htmlspecialchars((string) $val, ENT_QUOTES, 'UTF-8');
                                    $label_esc = htmlspecialchars((string) $label, ENT_QUOTES, 'UTF-8');
                                ?>
                                    <option value="<?php echo $val_esc; ?>" <?php echo $status === $val ? 'selected' : ''; ?>>
                                        <?php echo $label_esc; ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <?php endif; ?>
                    </div>
                <?php endforeach; endif; ?>
            </div>
        </div>
    </div>

    <datalist id="classification-options">
        <?php foreach ($classifications as $c): ?>
            <option value="<?php echo htmlspecialchars((string) ($c['name'] ?? ''), ENT_QUOTES, 'UTF-8'); ?>"></option>
        <?php endforeach; ?>
    </datalist>

    <div id="toast" class="toast"></div>

    <!-- Modal OTP buộc rời hệ thống -->
    <div class="fl-backdrop" id="flModal">
        <div class="fl-modal">
            <div class="fl-head">Xác nhận buộc rời hệ thống</div>
            <div class="fl-body">
                <p class="fl-sub" id="flSub"></p>
                <div class="fl-otp" id="flOtp">
                    <?php for ($i = 0; $i < 6; $i++): ?>
                        <input maxlength="1" autocomplete="off" inputmode="latin">
                    <?php endfor; ?>
                </div>
                <p class="fl-msg" id="flMsg"></p>
            </div>
            <div class="fl-foot">
                <span id="flCountdown"></span>
                <div>
                    <button type="button" class="btn-light" id="flCancel" style="padding:8px 14px;border:0;border-radius:4px;cursor:pointer;">Hủy</button>
                    <button type="button" id="flConfirm" style="padding:8px 14px;border:0;border-radius:4px;cursor:pointer;background:#b22;color:#fff;font-weight:600;">Xác nhận</button>
                </div>
            </div>
        </div>
    </div>

    <script>
    (function () {
        var AJAX_BASE = <?php echo json_encode($ajax_base); ?>;

        /* Lọc thẻ theo họ và tên. */
        var searchInput = document.getElementById('searchInput');
        if (searchInput) {
            searchInput.addEventListener('input', function () {
                var kw = searchInput.value.trim().toLowerCase();
                document.querySelectorAll('#userGrid .user-card[data-user-id]').forEach(function (card) {
                    var name = (card.getAttribute('data-fullname') || '').toLowerCase();
                    card.style.display = (kw === '' || name.indexOf(kw) !== -1) ? '' : 'none';
                });
            });
        }

        function showToast(msg, isErr) {
            var t = document.getElementById('toast');
            t.textContent = msg;
            t.classList.toggle('err', !!isErr);
            t.classList.add('show');
            clearTimeout(t._timer);
            t._timer = setTimeout(function () { t.classList.remove('show'); }, 1800);
        }
        function flash(cell, ok) {
            if (!cell) return;
            cell.classList.remove('save-flash', 'ok', 'err');
            void cell.offsetWidth;
            cell.classList.add('save-flash', ok ? 'ok' : 'err');
            setTimeout(function () { cell.classList.remove('save-flash', 'ok', 'err'); }, 600);
        }
        function rowUserId(el) {
            var card = el.closest('[data-user-id]');
            return card ? parseInt(card.getAttribute('data-user-id'), 10) : 0;
        }

        /* ----------- Đổi trạng thái tài khoản ----------- */
        document.querySelectorAll('.status-select').forEach(function (sel) {
            sel._prev = sel.value;
            sel.addEventListener('change', function () {
                var uid    = rowUserId(sel);
                var status = sel.value;
                var cell   = sel.closest('.user-card');
                var fd = new FormData();
                fd.append('user_id', uid);
                fd.append('status', status);
                fetch(AJAX_BASE + 'update_user_status', { method: 'POST', body: fd, credentials: 'same-origin' })
                    .then(function (r) { return r.json(); })
                    .then(function (res) {
                        if (res && res.ok) {
                            sel.className = 'status-select status-' + status;
                            sel._prev = status;
                            flash(cell, true);
                            if (res.mailed) showToast('Đã kích hoạt và gửi email thông báo cho người dùng.', false);
                        } else {
                            sel.value = sel._prev;
                            flash(cell, false);
                            showToast((res && res.message) || 'Cập nhật thất bại', true);
                        }
                    })
                    .catch(function () {
                        sel.value = sel._prev;
                        flash(cell, false);
                        showToast('Lỗi kết nối', true);
                    });
            });
        });

        /* ----------- Phân loại user (combobox tạo mới) ----------- */
        var classDatalist = document.getElementById('classification-options');
        function datalistHas(name) {
            if (!classDatalist) return false;
            var lc = name.toLowerCase();
            return Array.prototype.some.call(classDatalist.options, function (o) {
                return (o.value || '').toLowerCase() === lc;
            });
        }
        function datalistAdd(name) {
            if (!classDatalist || datalistHas(name)) return;
            var opt = document.createElement('option');
            opt.value = name;
            classDatalist.appendChild(opt);
        }
        function sendClassification(uid, name, allowCreate) {
            var fd = new FormData();
            fd.append('user_id', uid);
            fd.append('name', name);
            fd.append('allow_create', allowCreate ? 1 : 0);
            return fetch(AJAX_BASE + 'set_user_classification', { method: 'POST', body: fd, credentials: 'same-origin' })
                .then(function (r) { return r.json(); });
        }
        document.querySelectorAll('.classification-input').forEach(function (inp) {
            inp._prev = inp.value.trim();
            inp.addEventListener('change', function () {
                var uid  = rowUserId(inp);
                var name = inp.value.trim();
                var cell = inp.closest('.user-card');
                if (name === inp._prev) return;
                sendClassification(uid, name, false).then(function (res) {
                    if (res && res.ok) {
                        inp.value = res.name || '';
                        inp._prev = inp.value;
                        if (res.name) datalistAdd(res.name);
                        flash(cell, true);
                    } else if (res && res.is_new) {
                        if (window.confirm('“' + name + '” là phân loại mới. Lưu phân loại này vào danh sách?')) {
                            sendClassification(uid, name, true).then(function (r2) {
                                if (r2 && r2.ok) {
                                    inp.value = r2.name || '';
                                    inp._prev = inp.value;
                                    datalistAdd(r2.name);
                                    flash(cell, true);
                                    showToast('Đã tạo phân loại mới: ' + r2.name, false);
                                } else {
                                    inp.value = inp._prev;
                                    flash(cell, false);
                                    showToast((r2 && r2.message) || 'Lưu thất bại', true);
                                }
                            }).catch(function () { inp.value = inp._prev; showToast('Lỗi kết nối', true); });
                        } else {
                            inp.value = inp._prev;
                        }
                    } else {
                        inp.value = inp._prev;
                        flash(cell, false);
                        showToast((res && res.message) || 'Cập nhật thất bại', true);
                    }
                }).catch(function () {
                    inp.value = inp._prev;
                    flash(cell, false);
                    showToast('Lỗi kết nối', true);
                });
            });
        });

        /* ----------- Menu "Quản lý" trong thẻ ----------- */
        document.addEventListener('click', function (e) {
            var btn = e.target.closest('.user-manage-btn');
            // Đóng mọi menu đang mở (trừ menu vừa bấm).
            document.querySelectorAll('.user-card-manage.open').forEach(function (m) {
                if (!btn || m !== btn.closest('.user-card-manage')) m.classList.remove('open');
            });
            if (btn) { e.stopPropagation(); btn.closest('.user-card-manage').classList.toggle('open'); }
        });

        /* ----------- Buộc rời hệ thống (OTP) ----------- */
        var flModal = document.getElementById('flModal');
        var flSub   = document.getElementById('flSub');
        var flMsg   = document.getElementById('flMsg');
        var flBoxes = Array.prototype.slice.call(document.querySelectorAll('#flOtp input'));
        var flCountdown = document.getElementById('flCountdown');
        var flCancel = document.getElementById('flCancel');
        var flConfirm = document.getElementById('flConfirm');
        var flUserId = 0, flTimer = null, flVerifying = false;

        function flSetMsg(t, err) { flMsg.textContent = t || ''; flMsg.classList.remove('ok', 'err'); if (t) flMsg.classList.add(err ? 'err' : 'ok'); }
        function flCollect() { return flBoxes.map(function (b) { return b.value; }).join('').toUpperCase(); }
        function flClose() { flModal.classList.remove('show'); if (flTimer) { clearInterval(flTimer); flTimer = null; } }
        function flCountdownStart(sec) {
            if (flTimer) clearInterval(flTimer);
            var r = sec; flCountdown.textContent = 'Hết hạn sau ' + r + 's';
            flTimer = setInterval(function () {
                r--;
                if (r <= 0) { clearInterval(flTimer); flTimer = null; flCountdown.textContent = 'Mã đã hết hạn'; flSetMsg('Mã đã hết hạn. Hãy thử lại.', true); }
                else flCountdown.textContent = 'Hết hạn sau ' + r + 's';
            }, 1000);
        }
        function flVerify() {
            if (flVerifying) return;
            var code = flCollect();
            if (code.length !== 6) { flSetMsg('Nhập đủ 6 ký tự', true); return; }
            flVerifying = true; flSetMsg('Đang xác nhận...', false);
            var fd = new FormData(); fd.append('user_id', flUserId); fd.append('otp', code);
            fetch(AJAX_BASE + 'force_leave_confirm', { method: 'POST', body: fd, credentials: 'same-origin' })
                .then(function (r) { return r.json(); })
                .then(function (res) {
                    flVerifying = false;
                    if (res && res.ok) {
                        if (flTimer) { clearInterval(flTimer); flTimer = null; }
                        flSetMsg('Đã buộc rời hệ thống.', false);
                        var card = document.querySelector('.user-card[data-user-id="' + flUserId + '"]');
                        setTimeout(function () { flClose(); if (card) location.reload(); }, 900);
                    } else {
                        flSetMsg((res && res.message) || 'Mã không đúng', true);
                        flBoxes.forEach(function (b) { b.value = ''; }); flBoxes[0].focus();
                    }
                })
                .catch(function () { flVerifying = false; flSetMsg('Lỗi kết nối', true); });
        }
        flBoxes.forEach(function (box, idx) {
            box.addEventListener('input', function () {
                box.value = box.value.toUpperCase();
                if (box.value && idx < flBoxes.length - 1) flBoxes[idx + 1].focus();
                if (flCollect().length === 6) flVerify();
            });
            box.addEventListener('keydown', function (ev) { if (ev.key === 'Backspace' && box.value === '' && idx > 0) flBoxes[idx - 1].focus(); });
            box.addEventListener('paste', function (ev) {
                ev.preventDefault();
                var t = (ev.clipboardData || window.clipboardData).getData('text').trim().toUpperCase();
                for (var i = 0; i < flBoxes.length; i++) flBoxes[i].value = t.charAt(i) || '';
                if (flCollect().length === 6) flVerify();
            });
        });
        flConfirm.addEventListener('click', flVerify);
        flCancel.addEventListener('click', flClose);
        flModal.addEventListener('click', function (e) { if (e.target === flModal) flClose(); });

        document.querySelectorAll('.user-force-leave').forEach(function (b) {
            b.addEventListener('click', function () {
                var card = b.closest('.user-card');
                card.querySelector('.user-card-manage').classList.remove('open');
                var uid = parseInt(card.getAttribute('data-user-id'), 10);
                var name = card.getAttribute('data-fullname') || '';
                if (!window.confirm('Buộc “' + name + '” rời hệ thống? Mã xác nhận sẽ gửi tới email của bạn.')) return;
                var fd = new FormData(); fd.append('user_id', uid);
                fetch(AJAX_BASE + 'force_leave_send_otp', { method: 'POST', body: fd, credentials: 'same-origin' })
                    .then(function (r) { return r.json(); })
                    .then(function (res) {
                        if (!res || !res.ok) { showToast((res && res.message) || 'Không gửi được mã', true); return; }
                        flUserId = uid;
                        flSub.innerHTML = 'Mã OTP 6 ký tự đã gửi tới email của bạn'
                            + (res.masked ? ' (<b>' + res.masked + '</b>)' : '') + '. Nhập mã để buộc <b>' + name + '</b> rời hệ thống.';
                        flBoxes.forEach(function (x) { x.value = ''; }); flSetMsg('');
                        flModal.classList.add('show'); flBoxes[0].focus();
                        flCountdownStart(res.expires_in || 60);
                    })
                    .catch(function () { showToast('Lỗi kết nối', true); });
            });
        });
    })();
    </script>
    <script src="<?php echo asset_ver('public/js/shared/app_shell.js'); ?>"></script>
</body>

</html>
