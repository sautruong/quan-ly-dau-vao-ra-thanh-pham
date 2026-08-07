<?php
defined('APPPATH') OR exit('Không được quyền truy cập phần này');
/** @var array $users  Danh sách tài khoản */
/** @var array $groups [group_label => [ {id,label,...} ]] */
$users     = isset($users) && is_array($users) ? $users : [];
$groups    = isset($groups) && is_array($groups) ? $groups : [];
$ajax_base = '?mod=admin_factory&controllers=admin&action=';
$current_admin        = isset($current_admin) && is_array($current_admin) ? $current_admin : [];
$transfer_candidates  = isset($transfer_candidates) && is_array($transfer_candidates) ? $transfer_candidates : [];
$is_admin             = isset($is_admin) ? (bool) $is_admin : true;
$all_group_labels     = isset($all_group_labels) && is_array($all_group_labels) ? $all_group_labels : [];
$classifications      = isset($classifications) && is_array($classifications) ? $classifications : [];
$deputies             = isset($deputies) && is_array($deputies) ? $deputies : [];
$view_only_capable_ids = isset($view_only_capable_ids) && is_array($view_only_capable_ids) ? array_map('intval', $view_only_capable_ids) : [];
$edit_today_capable_ids = isset($edit_today_capable_ids) && is_array($edit_today_capable_ids) ? array_map('intval', $edit_today_capable_ids) : [];
$cur_admin_name  = trim((string) ($current_admin['fullname'] ?? '')) ?: (string) ($current_admin['username'] ?? '');
$cur_admin_uname = (string) ($current_admin['username'] ?? '');
?>
<!DOCTYPE html>
<html lang="vi">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>PHÂN QUYỀN NGƯỜI DÙNG</title>
    <link rel="icon" href="public/images/logo/logo_vat_png.png" type="image/png">
    <link rel="stylesheet" href="<?php echo asset_ver('public/css/reset.css'); ?>">
    <link rel="stylesheet" href="<?php echo asset_ver('public/css/all.css'); ?>">
    <link rel="stylesheet" href="<?php echo asset_ver('public/css/global.css'); ?>">
    <style>
        body { font-family: Arial, sans-serif; background: #eef1f5; color: #1f2733; margin: 0; }
        /* App shell: header nằm trên, nội dung cuộn dọc toàn trang (không kẹt overflow:hidden của #wrapper). */
        #wrapper.has-sider > .content { overflow-y: auto; overflow-x: hidden; }
        .perm-wrap { width: 100%; max-width: none; margin: 16px 0; padding: 20px 24px; background: #fff;
            border-radius: 8px; box-shadow: 0 1px 4px rgba(0,0,0,.08); box-sizing: border-box; }
        /* Bỏ giới hạn chiều cao cuộn riêng của vùng danh sách view -> cuộn theo cả trang. */
        #permArea { max-height: none !important; }
        .perm-bar {
            position: sticky; top: 0; z-index: 5; background: #fff;
            display: flex; align-items: center; gap: 14px; flex-wrap: wrap;
            padding: 12px 0; border-bottom: 1px solid #e1e5ea; margin-bottom: 14px;
        }
        .home-logo { display: inline-flex; align-items: center; }
        .home-logo img { width: 40px; height: auto; display: block; transition: transform .15s; }
        .home-logo:hover img { transform: scale(1.08); }
        .perm-bar h1 { font-size: 18px; margin: 0 12px 0 0; }
        .perm-bar select {
            padding: 7px 10px; border: 1px solid #c2c8d0; border-radius: 4px;
            font-size: 14px; min-width: 280px;
        }
        .perm-bar .role-tag {
            font-size: 12px; padding: 2px 8px; border-radius: 10px;
            background: #eef; color: #224; font-weight: 600;
        }
        .perm-bar .role-tag.admin { background: #fde9c8; color: #8a5a00; }
        .btn {
            padding: 8px 16px; border: 0; border-radius: 4px; cursor: pointer;
            font-size: 14px; font-weight: 600;
        }
        .btn-save  { background: #096926; color: #fff; }
        .btn-save:disabled { background: #9bbfa6; cursor: not-allowed; }
        .btn-light { background: #eef0f3; color: #333; }
        .hint { color: #666; font-size: 13px; margin: 0 0 12px; }
        /* Vùng danh sách view: cuộn dọc riêng, thanh bar trên giữ cố định. */
        #permArea {
            max-height: calc(100vh - 220px);
            overflow-y: auto;
            padding: 4px 8px 4px 2px;
            border: 1px solid #e1e5ea;
            border-radius: 6px;
            background: #fafbfc;
        }
        /* 4 group-card trên một dòng (thu gọn dần ở màn nhỏ). */
        .group-grid { display: grid; grid-template-columns: repeat(4, 1fr); gap: 14px; }
        @media (max-width: 1300px) { .group-grid { grid-template-columns: repeat(3, 1fr); } }
        @media (max-width: 1000px) { .group-grid { grid-template-columns: repeat(2, 1fr); } }
        @media (max-width: 680px)  { .group-grid { grid-template-columns: 1fr; } }
        .group-card { border: 1px solid #d8dde3; border-radius: 6px; overflow: hidden; }
        .group-head {
            display: flex; align-items: center; justify-content: space-between;
            background: #f0f3f7; padding: 8px 12px; border-bottom: 1px solid #d8dde3;
        }
        .group-head .gname { font-weight: 700; font-size: 13px; }
        /* Checkbox + "Chọn cả nhóm" canh giữa theo chiều dọc (ngang bằng nhau). */
        .group-head label {
            display: inline-flex; align-items: center; gap: 6px; line-height: 1;
            font-size: 12px; color: #335; cursor: pointer; user-select: none;
        }
        .group-head label .group-check { margin: 0; }
        .group-body { padding: 8px 12px; }
        .view-item { display: flex; align-items: center; gap: 8px; padding: 4px 0; font-size: 13px; margin-bottom: 0; }
        .view-item input { width: 16px; height: 16px; cursor: pointer; }
        .view-item label { cursor: pointer; margin-bottom: 0; }
        .view-item .view-only-toggle { margin-left: auto; display: flex; align-items: center; gap: 4px; font-size: 12px; color: #b8860b; white-space: nowrap; }
        .view-item .view-only-toggle input {
            appearance: none; -webkit-appearance: none;
            width: 14px; height: 14px; margin: 0;
            border: 2px solid #b8860b; border-radius: 50%;
            background: #fff; position: relative; cursor: pointer;
        }
        .view-item .view-only-toggle input:checked { background: #b8860b; }
        .view-item .view-only-toggle input:checked::after {
            content: ""; position: absolute; left: 50%; top: 50%;
            width: 5px; height: 5px; border-radius: 50%; background: #fff;
            transform: translate(-50%, -50%);
        }
        .view-item .view-only-toggle input:disabled { cursor: not-allowed; opacity: .45; }
        /* "Sửa trong ngày" — màu XANH LÁ để phân biệt ngay với "Chỉ xem" (vàng): hai ô nằm sát
           nhau trên cùng một dòng, cùng màu thì rất dễ tick nhầm ô. Bỏ margin-left:auto vì ô
           "Chỉ xem" đã đẩy cụm sang phải rồi; để cả hai auto sẽ tách rời hai đầu dòng. */
        .view-item .edit-today-toggle { margin-left: 10px; color: #096926; }
        .view-item .edit-today-toggle input { border-color: #096926; }
        .view-item .edit-today-toggle input:checked { background: #096926; }
        /* "Database" — màu xanh dương để phân biệt hẳn với 2 ô kia (vàng: chỉ xem, xanh lá: sửa trong ngày) */
        .view-item .check-db-toggle { margin-left: 10px; color: #2563eb; }
        .view-item .check-db-toggle input { border-color: #2563eb; }
        .view-item .check-db-toggle input:checked { background: #2563eb; }
        /* Nút chuông mở modal nhắc nhở — cấu hình toàn hệ thống, không theo user. */
        .prm-gear {
            margin-left: 8px; border: 0; background: none; cursor: pointer;
            color: #b8860b; font-size: 14px; padding: 2px 4px; line-height: 1;
        }
        .prm-gear:hover { color: #8a5a00; }

        /* ----- Modal nhắc nhở nhập sản lượng ----- */
        .prm-mask {
            position: fixed; inset: 0; background: rgba(0,0,0,.45); z-index: 9998;
            display: flex; align-items: center; justify-content: center; padding: 20px;
        }
        .prm-box {
            background: #fff; border-radius: 10px; width: min(540px, 100%);
            max-height: 88vh; display: flex; flex-direction: column; overflow: hidden;
            box-shadow: 0 12px 40px rgba(0,0,0,.25);
        }
        .prm-head {
            display: flex; align-items: center; gap: 10px; padding: 14px 18px;
            border-bottom: 1px solid #e1e5ea; font-weight: 700; font-size: 15px;
        }
        .prm-head .prm-x { margin-left: auto; cursor: pointer; font-size: 22px; color: #888; line-height: 1; }
        .prm-body { padding: 16px 18px; overflow-y: auto; display: flex; flex-direction: column; gap: 12px; }
        .prm-note {
            margin: 0; padding: 10px 12px; border-radius: 8px; font-size: 12.5px; line-height: 1.5;
            background: #f2f7f3; border: 1px solid #cfe3d4; color: #2c4a33;
        }
        .prm-row { display: flex; align-items: center; gap: 8px; font-size: 13.5px; cursor: pointer; }
        .prm-field { display: flex; flex-direction: column; gap: 5px; font-size: 13px; }
        .prm-field > span { font-weight: 600; }
        .prm-field input[type="time"], .prm-field input[type="text"], .prm-field select {
            padding: 8px 10px; border: 1px solid #c2c8d0; border-radius: 6px; font-size: 13px; font-family: inherit;
        }
        .prm-field select[multiple] { min-height: 116px; }
        .prm-rtype { display: flex; gap: 16px; font-size: 12.5px; }
        .prm-foot {
            display: flex; align-items: center; gap: 10px; padding: 12px 18px;
            border-top: 1px solid #e1e5ea;
        }
        .prm-msg { flex: 1; font-size: 12.5px; color: #555; }
        .prm-sub { display: none; flex-direction: column; gap: 10px; padding-left: 24px; }
        .prm-sub.on { display: flex; }
        .empty-state { color: #888; padding: 40px; text-align: center; }
        .toast {
            position: fixed; right: 16px; bottom: 16px; background: #333; color: #fff;
            padding: 10px 14px; border-radius: 4px; font-size: 13px; opacity: 0;
            transform: translateY(8px); transition: opacity .2s, transform .2s; z-index: 9999;
        }
        .toast.show { opacity: 1; transform: translateY(0); }
        .toast.err { background: #b22; }
        .disabled-area { opacity: .45; pointer-events: none; }

        /* ----- Chuyển quyền admin ----- */
        .transfer-card {
            margin-top: 18px; border: 1px solid #f0c98b; border-radius: 8px;
            background: #fffaf2; overflow: hidden;
        }
        .transfer-card .tc-head {
            background: #fdf0d8; padding: 10px 16px; border-bottom: 1px solid #f0c98b;
            font-weight: 700; font-size: 14px; color: #8a5a00;
            display: flex; align-items: center; gap: 8px;
        }
        .transfer-card .tc-body { padding: 14px 16px; }
        .transfer-row { display: flex; align-items: center; gap: 10px; flex-wrap: wrap; margin-bottom: 10px; }
        .transfer-row .lab { min-width: 120px; font-size: 13px; font-weight: 600; color: #444; }
        .transfer-row .cur-admin {
            font-size: 14px; font-weight: 700; color: #8a5a00;
            background: #fde9c8; padding: 4px 10px; border-radius: 6px;
        }
        .transfer-row select {
            padding: 7px 10px; border: 1px solid #c2c8d0; border-radius: 4px;
            font-size: 14px; min-width: 280px;
        }
        .btn-warn   { background: #b5651d; color: #fff; }
        .btn-warn:disabled { background: #d3b08a; cursor: not-allowed; }
        .btn-danger { background: #b22; color: #fff; }
        .btn-danger:disabled { background: #d39a9a; cursor: not-allowed; }
        .tc-note { font-size: 12.5px; color: #777; margin: 4px 0 0; }

        /* ----- Modal OTP xác nhận chuyển quyền ----- */
        .tf-modal-backdrop {
            position: fixed; inset: 0; background: rgba(0,0,0,.45);
            display: none; align-items: center; justify-content: center; z-index: 1000; padding: 20px;
        }
        .tf-modal-backdrop.show { display: flex; }
        .tf-modal {
            background: #fff; border-radius: 10px; width: 430px; max-width: 92vw;
            box-shadow: 0 12px 34px rgba(0,0,0,.25); overflow: hidden;
        }
        .tf-modal-head { padding: 14px 18px; font-size: 16px; font-weight: 700; color: #fff; background: #b5651d; }
        .tf-modal-body { padding: 18px; }
        .tf-modal-sub { font-size: 13px; color: #444; margin: 0 0 14px; }
        .tf-modal-sub b { color: #8a5a00; }
        .tf-otp-boxes { display: flex; gap: 8px; justify-content: space-between; }
        .tf-otp-box {
            width: 100%; aspect-ratio: 1 / 1; text-align: center; font-size: 22px; font-weight: 700;
            text-transform: uppercase; border: 1px solid #c2c8d0; border-radius: 8px; outline: none;
            box-sizing: border-box; transition: border-color .2s, box-shadow .2s;
        }
        .tf-otp-box:focus { border-color: #b5651d; box-shadow: 0 0 0 2px rgba(181,101,29,.2); }
        .tf-otp-box:disabled { background: #f1f1f1; color: #9a9a9a; }
        .tf-otp-msg { min-height: 18px; font-size: 13px; font-style: italic; margin: 10px 0 0; text-align: center; }
        .tf-otp-msg.err { color: #b22; }
        .tf-otp-msg.ok  { color: #096926; }
        .tf-otp-actions { display: flex; align-items: center; justify-content: space-between; margin-top: 12px; }
        #tfCountdown { font-size: 13px; color: #b22; font-weight: 600; }
        .tf-modal-foot { padding: 14px 18px; display: flex; justify-content: flex-end; gap: 10px; border-top: 1px solid #eee; }

        /* ----- Tabs trong tc-head (Chuyển quyền admin trưởng / Bổ nhiệm admin phó) ----- */
        .tc-tabs { display: flex; gap: 0; }
        .tc-tab {
            background: transparent; border: 0; cursor: pointer; padding: 10px 16px;
            font-weight: 700; font-size: 14px; color: #a07a3a;
            border-bottom: 3px solid transparent;
        }
        .tc-tab.active { color: #8a5a00; border-bottom-color: #b5651d; }
        .tc-pane { display: none; }
        .tc-pane.active { display: block; }
        /* Bổ nhiệm admin phó */
        .dep-row { display: flex; align-items: center; gap: 10px; flex-wrap: wrap; margin-bottom: 12px; }
        .dep-row .lab { min-width: 120px; font-size: 13px; font-weight: 600; color: #444; }
        .dep-scope { display: grid; grid-template-columns: 1fr 1fr; gap: 14px; margin: 6px 0 12px; }
        .dep-box { border: 1px solid #f0c98b; border-radius: 6px; background: #fff; padding: 10px 12px; }
        .dep-box h4 { margin: 0 0 8px; font-size: 13px; color: #8a5a00; }
        .dep-check { display: flex; align-items: center; gap: 7px; padding: 3px 0; font-size: 13px; }
        .dep-list { margin-top: 10px; display: flex; flex-wrap: wrap; gap: 8px; }
        .dep-chip {
            display: inline-flex; align-items: center; gap: 8px; background: #fde9c8; color: #8a5a00;
            padding: 5px 10px; border-radius: 14px; font-size: 12.5px; font-weight: 600;
        }
        .dep-chip button { border: 0; background: transparent; color: #b22; cursor: pointer; font-weight: 700; }
        .dep-chip.is-pending { background: #e5e7eb; color: #555; }
        .dep-chip .st { font-size: 11px; font-weight: 700; padding: 1px 6px; border-radius: 8px; }
        .dep-chip.is-accepted .st { background: #d1fae5; color: #066b3a; }
        .dep-chip.is-pending  .st { background: #fde9c8; color: #8a5a00; }

        /* Header app phải nổi trên cùng để dùng được dropdown (chuông/việc/user). */
        .app-header { position: relative; z-index: 1500; }
        .perm-bar { z-index: 1 !important; }

        /* Combobox tìm-kiếm (thay select) */
        .combo { position: relative; min-width: 280px; }
        .combo-input {
            width: 100%; box-sizing: border-box; padding: 7px 10px; border: 1px solid #c2c8d0;
            border-radius: 4px; font-size: 14px; outline: none;
        }
        .combo-input:focus { border-color: #16a34a; box-shadow: 0 0 0 2px rgba(22,163,74,.15); }
        .combo-list {
            display: none; position: absolute; top: calc(100% + 4px); left: 0; right: 0; z-index: 50;
            max-height: 260px; overflow-y: auto; background: #fff; border: 1px solid #d8dde3;
            border-radius: 6px; box-shadow: 0 8px 22px rgba(0,0,0,.14);
        }
        .combo.open .combo-list { display: block; }
        .combo-opt { padding: 8px 12px; font-size: 13px; cursor: pointer; }
        .combo-opt:hover, .combo-opt.active { background: #eaf7ef; }
        .combo-opt .un { color: #777; font-size: 12px; }
        .combo-empty { padding: 10px 12px; font-size: 13px; color: #999; }
        .combo-tags { display: flex; flex-wrap: wrap; gap: 6px; margin-bottom: 6px; }
        .combo-tag {
            display: inline-flex; align-items: center; gap: 6px; background: #eaf7ef; color: #096926;
            border: 1px solid #bfe6cd; padding: 3px 8px; border-radius: 12px; font-size: 12.5px; font-weight: 600;
        }
        .combo-tag button { border: 0; background: transparent; color: #b22; cursor: pointer; font-weight: 700; }

        /* Checkbox tròn, tích nền xanh lá */
        .view-check, .group-check, .dep-group, .dep-class, .admin-info-check {
            appearance: none; -webkit-appearance: none; width: 18px; height: 18px; min-width: 18px;
            border: 2px solid #c2c8d0; border-radius: 50%; cursor: pointer; position: relative;
            outline: none; transition: background .15s, border-color .15s; margin: 0; box-sizing: border-box;
        }
        .view-check:checked, .group-check:checked, .dep-group:checked,
        .dep-class:checked, .admin-info-check:checked {
            background: #16a34a; border-color: #16a34a;
        }
        .view-check:checked::after, .group-check:checked::after,
        .dep-group:checked::after, .dep-class:checked::after, .admin-info-check:checked::after {
            content: ''; position: absolute; left: 5px; top: 2px; width: 4px; height: 8px;
            border: solid #fff; border-width: 0 2px 2px 0; transform: rotate(45deg);
        }
        /* Card "Quản trị hệ thống" chỉ hiện khi user là admin phó. */
        .admin-only-group { border-color: #f0c98b; }
        .admin-only-group .group-head { background: #fdf0d8; }
        .admin-only-group .gname { color: #8a5a00; }
        .admin-only-note { font-size: 12px; color: #8a5a00; margin: 8px 0 0; }
    </style>
    <link rel="stylesheet" href="<?php echo asset_ver('public/css/shared/app_shell.css'); ?>">
</head>

<body>
    <div id="wrapper" class="has-sider">
        <?php get_sidebar('app'); ?>
        <?php get_header('app'); ?>
        <div class="content">
    <div class="perm-wrap">
        <div class="perm-bar">
            <div class="combo" id="userCombo">
                <input type="text" class="combo-input" id="userComboInput" placeholder="Tìm & chọn tài khoản…" autocomplete="off">
                <input type="hidden" id="userSelect" value="">
                <div class="combo-list" id="userComboList"></div>
            </div>
            <span id="roleTag" class="role-tag" style="display:none;"></span>
            <button id="btnSave" class="btn btn-save" disabled>Lưu phân quyền</button>
        </div>

        <p class="hint">Tick các chức năng muốn cho tài khoản truy cập. Tài khoản <b>admin</b> luôn thấy toàn bộ (không cần gán).</p>

        <div id="permArea" class="disabled-area">
            <?php if (empty($groups)): ?>
                <div class="empty-state">Chưa có view nào trong danh mục. Hãy chạy migration_permissions.sql.</div>
            <?php else: ?>
                <div class="group-grid">
                    <?php if ($is_admin): ?>
                    <!-- Tùy chọn module "Quản trị hệ thống": chỉ áp dụng khi user được chọn là admin phó. -->
                    <div class="group-card admin-only-group" data-group="__ADMIN__" style="display:none;">
                        <div class="group-head">
                            <span class="gname"><i class="fa-solid fa-user-shield"></i> QUẢN TRỊ HỆ THỐNG</span>
                        </div>
                        <div class="group-body">
                            <div class="view-item">
                                <input type="checkbox" class="admin-info-check" checked disabled>
                                <label>Phân quyền người dùng</label>
                            </div>
                            <div class="view-item">
                                <input type="checkbox" class="admin-info-check" checked disabled>
                                <label>Quản lý người dùng</label>
                            </div>
                            <p class="admin-only-note">
                                <i class="fa-solid fa-circle-info"></i>
                                Admin phó tự động được quản lý module &amp; nhóm user do admin trưởng giao
                                (cấu hình ở tab “Bổ nhiệm admin phó”).
                            </p>
                        </div>
                    </div>
                    <?php endif; ?>
                    <?php foreach ($groups as $gname => $items):
                        $gname_esc = htmlspecialchars((string) $gname, ENT_QUOTES, 'UTF-8');
                    ?>
                        <div class="group-card">
                            <div class="group-head">
                                <span class="gname"><?php echo $gname_esc; ?></span>
                                <label><input type="checkbox" class="group-check"> Chọn cả nhóm</label>
                            </div>
                            <div class="group-body">
                                <?php foreach ($items as $v):
                                    $vid   = (int) $v['id'];
                                    $label = htmlspecialchars((string) $v['label'], ENT_QUOTES, 'UTF-8');
                                    $can_view_only = in_array($vid, $view_only_capable_ids, true);
                                    $can_edit_today = in_array($vid, $edit_today_capable_ids, true);
                                ?>
                                    <div class="view-item">
                                        <input type="checkbox" class="view-check" id="v<?php echo $vid; ?>" value="<?php echo $vid; ?>">
                                        <label for="v<?php echo $vid; ?>"><?php echo $label; ?></label>
                                        <?php if ($can_view_only): ?>
                                        <label class="view-only-toggle">
                                            <input type="checkbox" class="view-only-check" data-view="<?php echo $vid; ?>"> Chỉ xem
                                        </label>
                                        <?php endif; ?>
                                        <?php /*
                                          "Database" (7/8/2026) — ĐỘC LẬP với "Chỉ xem": user chỉ-xem vẫn có
                                          thể được cho phép soi bảng. Chỉ khoá khi chưa tick ô cấp view.
                                          Tick vào thì nút Database mới hiện trên header của view đó, VÀ endpoint
                                          check_database phía server mới cho qua.
                                        */ ?>
                                        <label class="view-only-toggle check-db-toggle"
                                               title="Cho phép xem nội dung bảng dữ liệu của view này (nút Database trên header)">
                                            <input type="checkbox" class="check-db-check" data-view="<?php echo $vid; ?>" disabled>
                                            Database
                                        </label>
                                        <?php if ($can_edit_today): ?>
                                        <?php /*
                                          "Cho phép chỉnh sửa trong ngày" (5/8/2026) — CỬA HẸP mở ra từ "Chỉ xem":
                                          tick CẢ HAI = nhập/sửa được dữ liệu của hôm nay, các ngày trước chỉ xem.
                                          Ô này chỉ bật được khi "Chỉ xem" đang bật (JS ràng buộc, backend cũng
                                          ép lại trong permission_set_user_views) — tick một mình nó vô nghĩa vì
                                          không "Chỉ xem" thì user vốn đã sửa được mọi ngày.
                                        */ ?>
                                        <label class="view-only-toggle edit-today-toggle"
                                               title="Chỉ dùng kèm &quot;Chỉ xem&quot;: cho nhập và sửa dữ liệu của NGÀY HÔM NAY, các ngày trước chỉ xem">
                                            <input type="checkbox" class="edit-today-check" data-view="<?php echo $vid; ?>" disabled>
                                            Sửa trong ngày
                                        </label>
                                        <?php /*
                                          Nhắc nhở nhập sản lượng — cấu hình TOÀN HỆ THỐNG (giờ nhắc,
                                          chuông/chat), không phải quyền của riêng user đang chọn. Vì vậy
                                          nút này nằm ngoài mọi checkbox và KHÔNG bị disable theo user.
                                        */ ?>
                                        <button type="button" class="prm-gear" id="btn-prm-setting"
                                                title="Cài đặt nhắc nhở: đến giờ mà chưa ai nhập sản lượng thì đẩy chuông / nhắn chat">
                                            <i class="fa-solid fa-bell"></i>
                                        </button>
                                        <?php endif; ?>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </div>

        <?php if ($is_admin): ?>
        <!-- ============ ADMIN TRƯỞNG: CHUYỂN QUYỀN / BỔ NHIỆM ADMIN PHÓ ============ -->
        <div class="transfer-card">
            <div class="tc-head">
                <div class="tc-tabs">
                    <button type="button" class="tc-tab active" data-tc-tab="transfer">
                        <i class="fa-solid fa-user-shield"></i> Chuyển quyền admin trưởng
                    </button>
                    <button type="button" class="tc-tab" data-tc-tab="deputy">
                        <i class="fa-solid fa-user-gear"></i> Bổ nhiệm admin phó
                    </button>
                </div>
            </div>
            <div class="tc-body">
                <!-- Pane 1: chuyển quyền admin trưởng -->
                <div class="tc-pane active" data-tc-pane="transfer">
                    <div class="transfer-row">
                        <span class="lab">Admin hiện tại:</span>
                        <span class="cur-admin">
                            <?php echo htmlspecialchars($cur_admin_name, ENT_QUOTES, 'UTF-8'); ?>
                            <?php if ($cur_admin_uname !== ''): ?>(<?php echo htmlspecialchars($cur_admin_uname, ENT_QUOTES, 'UTF-8'); ?>)<?php endif; ?>
                        </span>
                    </div>
                    <div class="transfer-row">
                        <span class="lab">Admin mới:</span>
                        <select id="newAdminSelect">
                            <option value="">-- Chọn tài khoản (đang hoạt động) --</option>
                            <?php foreach ($transfer_candidates as $c):
                                $cid   = (int) $c['id'];
                                $cname = htmlspecialchars((string) ($c['fullname'] ?? ''), ENT_QUOTES, 'UTF-8');
                                $cun   = htmlspecialchars((string) ($c['username'] ?? ''), ENT_QUOTES, 'UTF-8');
                            ?>
                                <option value="<?php echo $cid; ?>"><?php echo $cname; ?> (<?php echo $cun; ?>)</option>
                            <?php endforeach; ?>
                        </select>
                        <button id="btnSendOtp" class="btn btn-warn" type="button" disabled>Gửi mã xác nhận</button>
                    </div>
                    <p class="tc-note">
                        Hệ thống gửi mã OTP 6 ký tự (hiệu lực 60 giây) tới email của <b>admin hiện tại</b>.
                        Nhập đúng mã mới chuyển quyền. Sau khi chuyển, bạn sẽ trở thành người dùng thường.
                    </p>
                </div>

                <!-- Pane 2: bổ nhiệm admin phó -->
                <div class="tc-pane" data-tc-pane="deputy">
                    <div class="dep-row" style="align-items:flex-start;">
                        <span class="lab">Chọn user:</span>
                        <div class="combo" id="deputyCombo" style="min-width:320px;">
                            <div class="combo-tags" id="deputyTags"></div>
                            <input type="text" class="combo-input" id="deputyComboInput"
                                   placeholder="Tìm & chọn 1 hoặc nhiều user…" autocomplete="off">
                            <div class="combo-list" id="deputyComboList"></div>
                        </div>
                    </div>

                    <div class="dep-scope">
                        <div class="dep-box">
                            <h4><i class="fa-solid fa-layer-group"></i> Module được phân quyền</h4>
                            <?php foreach ($all_group_labels as $gl):
                                $gl_esc = htmlspecialchars((string) $gl, ENT_QUOTES, 'UTF-8');
                            ?>
                                <label class="dep-check">
                                    <input type="checkbox" class="dep-group" value="<?php echo $gl_esc; ?>">
                                    <span><?php echo $gl_esc; ?></span>
                                </label>
                            <?php endforeach; ?>
                        </div>
                        <div class="dep-box">
                            <h4><i class="fa-solid fa-users"></i> Nhóm user được quản lý</h4>
                            <?php if (empty($classifications)): ?>
                                <p class="combo-empty">Chưa có phân loại user nào. Tạo ở "Quản lý người dùng".</p>
                            <?php else: foreach ($classifications as $c):
                                $ccid = (int) $c['id'];
                                $cnm  = htmlspecialchars((string) $c['name'], ENT_QUOTES, 'UTF-8');
                            ?>
                                <label class="dep-check">
                                    <input type="checkbox" class="dep-class" value="<?php echo $ccid; ?>">
                                    <span><?php echo $cnm; ?></span>
                                </label>
                            <?php endforeach; endif; ?>
                        </div>
                    </div>

                    <button id="btnSaveDeputy" class="btn btn-warn" type="button">Lưu bổ nhiệm admin phó</button>

                    <div class="dep-list" id="deputyList">
                        <?php foreach ($deputies as $d):
                            $dn = trim((string) ($d['fullname'] ?? '')) ?: (string) ($d['username'] ?? '');
                            $dst = (string) ($d['status'] ?? 'pending');
                        ?>
                            <span class="dep-chip is-<?php echo $dst === 'accepted' ? 'accepted' : 'pending'; ?>"
                                  data-deputy-id="<?php echo (int) $d['user_id']; ?>"
                                  data-groups="<?php echo htmlspecialchars(json_encode($d['groups']), ENT_QUOTES, 'UTF-8'); ?>"
                                  data-classes="<?php echo htmlspecialchars(json_encode($d['class_ids']), ENT_QUOTES, 'UTF-8'); ?>">
                                <i class="fa-solid fa-user-gear"></i>
                                <?php echo htmlspecialchars($dn, ENT_QUOTES, 'UTF-8'); ?>
                                <span class="st"><?php echo $dst === 'accepted' ? 'Đã nhận' : 'Chờ nhận'; ?></span>
                                <button type="button" class="dep-remove" title="Gỡ admin phó">&times;</button>
                            </span>
                        <?php endforeach; ?>
                    </div>
                </div>
            </div>
        </div>
        <?php endif; ?>
    </div>
        </div><!-- /.content -->
    </div><!-- /#wrapper -->

    <!-- Modal OTP xác nhận chuyển quyền admin -->
    <div class="tf-modal-backdrop" id="transferModal">
        <div class="tf-modal">
            <div class="tf-modal-head">Xác nhận chuyển quyền admin</div>
            <div class="tf-modal-body">
                <p class="tf-modal-sub" id="tfModalSub"></p>
                <div class="tf-otp-boxes" id="tfOtpBoxes">
                    <input class="tf-otp-box" maxlength="1" autocomplete="off" inputmode="latin">
                    <input class="tf-otp-box" maxlength="1" autocomplete="off" inputmode="latin">
                    <input class="tf-otp-box" maxlength="1" autocomplete="off" inputmode="latin">
                    <input class="tf-otp-box" maxlength="1" autocomplete="off" inputmode="latin">
                    <input class="tf-otp-box" maxlength="1" autocomplete="off" inputmode="latin">
                    <input class="tf-otp-box" maxlength="1" autocomplete="off" inputmode="latin">
                </div>
                <p class="tf-otp-msg" id="tfOtpMsg"></p>
                <div class="tf-otp-actions">
                    <span id="tfCountdown"></span>
                    <button type="button" class="btn btn-light" id="tfResend" disabled>Gửi lại mã</button>
                </div>
            </div>
            <div class="tf-modal-foot">
                <button type="button" class="btn btn-light" id="tfCancel">Hủy</button>
                <button type="button" class="btn btn-danger" id="tfConfirm">Xác nhận chuyển quyền</button>
            </div>
        </div>
    </div>

    <div id="toast" class="toast"></div>

    <?php /*
      MODAL NHẮC NHỞ NHẬP SẢN LƯỢNG (YC2b, 5/8/2026).
      Cấu hình TOÀN HỆ THỐNG — độc lập hoàn toàn với user đang được phân quyền ở trên.
      Hai đường nhắc là 2 ô tick ĐỘC LẬP (user chốt): bật cả hai cùng lúc được.
    */ ?>
    <div class="prm-mask" id="prm-mask" style="display:none;">
        <div class="prm-box">
            <div class="prm-head">
                <i class="fa-solid fa-bell" style="color:#b8860b"></i>
                <span>Nhắc nhở nhập sản lượng sản xuất</span>
                <span class="prm-x" id="prm-close">&times;</span>
            </div>
            <div class="prm-body">
                <p class="prm-note">
                    Đến giờ đã hẹn, nếu <strong>chưa có ai nhập sản lượng</strong> trong ngày
                    (view “Nhập sản lượng sản xuất”), hệ thống sẽ nhắc. Mỗi ngày nhắc đúng một lần.
                    <span id="prm-today-state"></span>
                </p>

                <label class="prm-row"><input type="checkbox" id="prm-active"> <strong>Bật nhắc nhở</strong></label>

                <div style="display:grid;grid-template-columns:1fr 1fr;gap:12px;">
                    <label class="prm-field"><span>Giờ nhắc</span>
                        <input type="time" id="prm-time" step="60" value="17:00">
                    </label>
                    <label class="prm-row" style="align-self:end;padding-bottom:8px;">
                        <input type="checkbox" id="prm-skip-sunday" checked> <span>Không nhắc chủ nhật</span>
                    </label>
                </div>

                <label class="prm-row"><input type="checkbox" id="prm-bell" checked> <span>Đẩy chuông cho admin</span></label>

                <label class="prm-row"><input type="checkbox" id="prm-chat"> <span>Nhắn vào chat (Safe King gửi)</span></label>
                <div class="prm-sub" id="prm-chat-sub">
                    <div class="prm-rtype">
                        <label><input type="radio" name="prm-rtype" value="users" checked> Người dùng</label>
                        <label><input type="radio" name="prm-rtype" value="group"> Nhóm chat</label>
                    </div>
                    <select id="prm-users" multiple></select>
                    <select id="prm-group" style="display:none;"></select>
                </div>

                <label class="prm-field"><span>Câu nhắc (để trống dùng mặc định)</span>
                    <input type="text" id="prm-message" maxlength="480" placeholder="Hôm nay chưa thấy ai nhập sản lượng…">
                </label>
            </div>
            <div class="prm-foot">
                <span class="prm-msg" id="prm-msg"></span>
                <button type="button" class="btn btn-save" id="prm-save">Lưu</button>
            </div>
        </div>
    </div>

    <script>
    /* Nhắc nhở nhập sản lượng — độc lập với phần phân quyền phía trên, nên đóng gói riêng. */
    (function () {
        var AJAX = '<?php echo $ajax_base; ?>';
        var mask = document.getElementById('prm-mask');
        var open = document.getElementById('btn-prm-setting');
        if (!mask || !open) return;   /* view "Nhập sản lượng sản xuất" không có trong danh mục */

        var elActive = document.getElementById('prm-active');
        var elTime   = document.getElementById('prm-time');
        var elSunday = document.getElementById('prm-skip-sunday');
        var elBell   = document.getElementById('prm-bell');
        var elChat   = document.getElementById('prm-chat');
        var elSub    = document.getElementById('prm-chat-sub');
        var elUsers  = document.getElementById('prm-users');
        var elGroup  = document.getElementById('prm-group');
        var elMsgIn  = document.getElementById('prm-message');
        var elState  = document.getElementById('prm-today-state');
        var elMsg    = document.getElementById('prm-msg');
        var elSave   = document.getElementById('prm-save');

        function rtype() {
            var r = mask.querySelector('input[name="prm-rtype"]:checked');
            return r ? r.value : 'users';
        }
        function applyRtype() {
            var g = rtype() === 'group';
            elUsers.style.display = g ? 'none' : '';
            elGroup.style.display = g ? '' : 'none';
        }
        function applyChat() { elSub.classList.toggle('on', elChat.checked); }

        mask.querySelectorAll('input[name="prm-rtype"]').forEach(function (r) {
            r.addEventListener('change', applyRtype);
        });
        elChat.addEventListener('change', applyChat);

        function fill(sel, list, picked) {
            sel.innerHTML = '';
            (list || []).forEach(function (o) {
                var op = document.createElement('option');
                op.value = String(o.id);
                op.textContent = o.name + (o.member_count ? ' (' + o.member_count + ' người)' : '');
                if (picked && picked.indexOf(String(o.id)) !== -1) op.selected = true;
                sel.appendChild(op);
            });
        }

        var loaded = false;
        function load() {
            fetch(AJAX + 'production_reminder_config', { credentials: 'same-origin' })
                .then(function (r) { return r.json(); })
                .then(function (res) {
                    if (!res || !res.ok) return;
                    var c = res.config;
                    elState.textContent = res.has_today
                        ? ' Hôm nay ĐÃ có dữ liệu sản lượng.'
                        : ' Hôm nay CHƯA có dữ liệu sản lượng.';
                    var uids = [];
                    if (c) {
                        try { uids = (JSON.parse(c.chat_recipient_user_ids || '[]') || []).map(String); } catch (e) { uids = []; }
                    }
                    fill(elUsers, res.users, uids);
                    fill(elGroup, res.groups, c ? [String(c.chat_recipient_conversation_id)] : []);
                    if (!loaded && c) {
                        elActive.checked = !!Number(c.is_active);
                        elTime.value     = String(c.remind_time || '17:00:00').slice(0, 5);
                        elSunday.checked = !!Number(c.skip_sunday);
                        elBell.checked   = !!Number(c.notify_bell);
                        elChat.checked   = !!Number(c.notify_chat);
                        elMsgIn.value    = c.message || '';
                        var rt = c.chat_recipient_type === 'group' ? 'group' : 'users';
                        var rr = mask.querySelector('input[name="prm-rtype"][value="' + rt + '"]');
                        if (rr) rr.checked = true;
                    }
                    loaded = true;
                    applyRtype(); applyChat();
                });
        }

        open.addEventListener('click', function () { mask.style.display = 'flex'; load(); });
        document.getElementById('prm-close').addEventListener('click', function () { mask.style.display = 'none'; });
        mask.addEventListener('click', function (e) { if (e.target === mask) mask.style.display = 'none'; });

        elSave.addEventListener('click', function () {
            var uids = Array.prototype.slice.call(elUsers.selectedOptions || []).map(function (o) { return Number(o.value); });
            var fd = new FormData();
            fd.append('is_active',   elActive.checked ? 1 : '');
            fd.append('remind_time', elTime.value);
            fd.append('skip_sunday', elSunday.checked ? 1 : '');
            fd.append('notify_bell', elBell.checked ? 1 : '');
            fd.append('notify_chat', elChat.checked ? 1 : '');
            fd.append('chat_recipient_type', rtype());
            fd.append('chat_recipient_user_ids', JSON.stringify(uids));
            fd.append('chat_recipient_conversation_id', elGroup.value || 0);
            fd.append('message', elMsgIn.value || '');

            elSave.disabled = true; elMsg.textContent = 'Đang lưu...';
            fetch(AJAX + 'production_reminder_save', { method: 'POST', body: fd, credentials: 'same-origin' })
                .then(function (r) { return r.json(); })
                .then(function (res) {
                    elSave.disabled = false;
                    elMsg.textContent = (res && res.ok) ? 'Đã lưu.' : ((res && res.message) || 'Lưu thất bại.');
                    if (res && res.ok) setTimeout(function () { elMsg.textContent = ''; }, 2500);
                })
                .catch(function () { elSave.disabled = false; elMsg.textContent = 'Lỗi mạng.'; });
        });
    })();
    </script>

    <!-- ===== Combobox tìm-kiếm dùng chung (thay select) ===== -->
    <script>
    (function () {
        function esc(s) {
            return String(s == null ? '' : s).replace(/&/g, '&amp;').replace(/</g, '&lt;')
                .replace(/>/g, '&gt;').replace(/"/g, '&quot;').replace(/'/g, '&#39;');
        }
        window.__permEsc = esc;
        <?php
        $perm_users_js = array_map(static function ($u) {
            return [
                'id'       => (int) $u['id'],
                'name'     => (string) ($u['fullname'] ?? ''),
                'username' => (string) ($u['username'] ?? ''),
                'role'     => (string) ($u['role'] ?? 'user'),
            ];
        }, $users);
        ?>
        window.PERM_USERS = <?php echo json_encode($perm_users_js, JSON_UNESCAPED_UNICODE); ?>;
        window.PERM_DEPUTY_USERS = window.PERM_USERS.filter(function (u) { return u.role !== 'admin'; });
        // Id các admin phó ĐÃ NHẬN (để hiện tùy chọn "Quản trị hệ thống" trong lưới phân quyền).
        window.PERM_DEPUTY_IDS = <?php
            echo json_encode(array_values(array_map(
                static fn($d) => (int) $d['user_id'],
                array_filter($deputies, static fn($d) => ($d['status'] ?? '') === 'accepted')
            )), JSON_UNESCAPED_UNICODE);
        ?>;
        // Người đang đăng nhập (để admin phó không tự phân quyền cho chính mình).
        window.PERM_ME_ID = <?php echo (int) ($current_admin['id'] ?? 0); ?>;
        window.PERM_ME_IS_DEPUTY = <?php echo $is_admin ? 'false' : 'true'; ?>;

        // Combobox: gõ keyword -> xổ danh sách; ↑/↓ chọn; Enter chốt; click chọn.
        window.makeCombo = function (cfg) {
            var root  = document.getElementById(cfg.rootId);
            var input = document.getElementById(cfg.inputId);
            var listEl = document.getElementById(cfg.listId);
            var tagsEl = cfg.tagsId ? document.getElementById(cfg.tagsId) : null;
            var items = cfg.items || [];
            var selected = {};               // multi: id -> item
            var filtered = [], activeIdx = -1;
            function label(it) { return it.name + ' (' + it.username + ')'; }
            function render(kw) {
                kw = (kw || '').toLowerCase().trim();
                filtered = items.filter(function (it) {
                    if (cfg.multi && selected[it.id]) return false;
                    return label(it).toLowerCase().indexOf(kw) !== -1;
                });
                activeIdx = filtered.length ? 0 : -1;
                listEl.innerHTML = filtered.length
                    ? filtered.map(function (it, i) {
                        return '<div class="combo-opt' + (i === activeIdx ? ' active' : '') + '" data-idx="' + i + '">'
                            + esc(it.name) + ' <span class="un">(' + esc(it.username) + ')</span></div>';
                      }).join('')
                    : '<div class="combo-empty">Không có kết quả</div>';
            }
            function open() { root.classList.add('open'); render(input.value); }
            function close() { root.classList.remove('open'); }
            function selectedIds() { return Object.keys(selected).map(Number); }
            function renderTags() {
                if (!tagsEl) return;
                tagsEl.innerHTML = selectedIds().map(function (id) {
                    return '<span class="combo-tag">' + esc(selected[id].name)
                        + '<button type="button" data-rm="' + id + '">&times;</button></span>';
                }).join('');
            }
            function choose(it) {
                if (cfg.multi) {
                    selected[it.id] = it; input.value = ''; renderTags(); render(''); input.focus();
                    if (cfg.onChange) cfg.onChange(selectedIds());
                } else {
                    if (cfg.hidden) cfg.hidden.value = it.id;
                    input.value = label(it); close();
                    if (cfg.onSelect) cfg.onSelect(it);
                }
            }
            function highlight() {
                var opts = listEl.querySelectorAll('.combo-opt');
                opts.forEach(function (o, i) { o.classList.toggle('active', i === activeIdx); if (i === activeIdx) o.scrollIntoView({ block: 'nearest' }); });
            }
            input.addEventListener('focus', open);
            input.addEventListener('input', function () { open(); });
            input.addEventListener('keydown', function (e) {
                if (!root.classList.contains('open')) { if (e.key === 'ArrowDown') open(); return; }
                if (e.key === 'ArrowDown') { e.preventDefault(); activeIdx = Math.min(activeIdx + 1, filtered.length - 1); highlight(); }
                else if (e.key === 'ArrowUp') { e.preventDefault(); activeIdx = Math.max(activeIdx - 1, 0); highlight(); }
                else if (e.key === 'Enter') { e.preventDefault(); if (activeIdx >= 0 && filtered[activeIdx]) choose(filtered[activeIdx]); }
                else if (e.key === 'Escape') { close(); }
            });
            listEl.addEventListener('mousedown', function (e) {
                var o = e.target.closest('.combo-opt'); if (!o) return; e.preventDefault();
                var it = filtered[parseInt(o.getAttribute('data-idx'), 10)]; if (it) choose(it);
            });
            if (tagsEl) tagsEl.addEventListener('click', function (e) {
                var b = e.target.closest('[data-rm]'); if (!b) return;
                delete selected[parseInt(b.getAttribute('data-rm'), 10)]; renderTags(); render(input.value);
                if (cfg.onChange) cfg.onChange(selectedIds());
            });
            document.addEventListener('click', function (e) { if (!root.contains(e.target)) close(); });
            return {
                selectedIds: selectedIds,
                clearTags: function () { selected = {}; renderTags(); render(''); },
                reset: function () { selected = {}; if (cfg.hidden) cfg.hidden.value = ''; input.value = ''; renderTags(); }
            };
        };
    })();
    </script>

    <script>
    (function () {
        var AJAX_BASE   = <?php echo json_encode($ajax_base); ?>;
        var userSelect  = document.getElementById('userSelect');
        var btnSave     = document.getElementById('btnSave');
        var permArea    = document.getElementById('permArea');
        var roleTag     = document.getElementById('roleTag');
        var viewChecks     = Array.prototype.slice.call(document.querySelectorAll('.view-check'));
        var groupChecks    = Array.prototype.slice.call(document.querySelectorAll('.group-check'));
        var viewOnlyChecks = Array.prototype.slice.call(document.querySelectorAll('.view-only-check'));
        /* "Sửa trong ngày" (5/8/2026) — chỉ bật được KHI "Chỉ xem" đang bật. */
        var editTodayChecks = Array.prototype.slice.call(document.querySelectorAll('.edit-today-check'));
        /* "Database" (7/8/2026) — DOC LAP voi "Chi xem", chi khoa khi chua cap view. */
        var checkDbChecks = Array.prototype.slice.call(document.querySelectorAll('.check-db-check'));

        function showToast(msg, isErr) {
            var t = document.getElementById('toast');
            t.textContent = msg;
            t.classList.toggle('err', !!isErr);
            t.classList.add('show');
            clearTimeout(t._timer);
            t._timer = setTimeout(function () { t.classList.remove('show'); }, 1900);
        }

        function setAllChecks(on) {
            viewChecks.forEach(function (c) { c.checked = on; });
            viewOnlyChecks.forEach(function (c) { c.checked = false; });
            editTodayChecks.forEach(function (c) { c.checked = false; });
            checkDbChecks.forEach(function (c) { c.checked = false; });
            syncGroupChecks();
        }

        /* Checkbox "Chỉ xem" chỉ có ý nghĩa khi view tương ứng đang được cấp. */
        function viewOnlyCheckFor(viewId) {
            return viewOnlyChecks.filter(function (c) { return c.dataset.view === String(viewId); })[0];
        }
        function editTodayCheckFor(viewId) {
            return editTodayChecks.filter(function (c) { return c.dataset.view === String(viewId); })[0];
        }
        function checkDbCheckFor(viewId) {
            return checkDbChecks.filter(function (c) { return c.dataset.view === String(viewId); })[0];
        }
        function syncViewOnlyAvailability() {
            viewChecks.forEach(function (c) {
                var voc = viewOnlyCheckFor(c.value);
                var etc = editTodayCheckFor(c.value);
                if (voc) {
                    if (!c.checked) { voc.checked = false; }
                    voc.disabled = !c.checked;
                }
                /* Bỏ tick "Chỉ xem" (hoặc bỏ cấp view) là cờ "sửa trong ngày" mất nghĩa ngay —
                   tắt luôn để admin không lưu ra trạng thái mà backend sẽ âm thầm bỏ qua. */
                if (etc) {
                    var allow = c.checked && voc && voc.checked;
                    if (!allow) { etc.checked = false; }
                    etc.disabled = !allow;
                }
                /* "Database" KHÔNG phụ thuộc "Chỉ xem" — chỉ cần view được cấp là bật được. */
                var cdc = checkDbCheckFor(c.value);
                if (cdc) {
                    if (!c.checked) { cdc.checked = false; }
                    cdc.disabled = !c.checked;
                }
            });
        }

        /* Bật/tắt "Chỉ xem" phải kéo theo trạng thái của ô "Sửa trong ngày" cùng dòng. */
        viewOnlyChecks.forEach(function (c) {
            c.addEventListener('change', syncViewOnlyAvailability);
        });

        /* Đồng bộ checkbox "chọn cả nhóm" theo các view con. */
        function syncGroupChecks() {
            groupChecks.forEach(function (gc) {
                var body = gc.closest('.group-card').querySelectorAll('.view-check');
                var all = body.length > 0;
                body.forEach(function (c) { if (!c.checked) all = false; });
                gc.checked = all;
            });
        }

        var currentUid = 0, baseline = {}, dirty = false, syncable = false;
        var visibleIds = {};
        viewChecks.forEach(function (c) { visibleIds[c.value] = true; });

        groupChecks.forEach(function (gc) {
            gc.addEventListener('change', function () {
                var body = gc.closest('.group-card').querySelectorAll('.view-check');
                body.forEach(function (c) { c.checked = gc.checked; });
                dirty = true;
            });
        });
        viewChecks.forEach(function (c) {
            c.addEventListener('change', function () { syncGroupChecks(); syncViewOnlyAvailability(); dirty = true; });
        });
        viewOnlyChecks.forEach(function (c) {
            c.addEventListener('change', function () { dirty = true; });
        });
        syncViewOnlyAvailability();

        var adminOnlyCard = document.querySelector('.admin-only-group');
        function toggleAdminCard(uid) {
            if (!adminOnlyCard) return;
            var isDep = (window.PERM_DEPUTY_IDS || []).indexOf(parseInt(uid, 10)) !== -1;
            adminOnlyCard.style.display = isDep ? '' : 'none';
        }

        function applyViewIds(ids, viewOnlyIds, editTodayIds, checkDbIds) {
            var set = {};
            (ids || []).forEach(function (id) { set[String(id)] = true; });
            viewChecks.forEach(function (c) { c.checked = !!set[c.value]; });
            var voSet = {};
            (viewOnlyIds || []).forEach(function (id) { voSet[String(id)] = true; });
            viewOnlyChecks.forEach(function (c) { c.checked = !!voSet[c.dataset.view]; });
            var etSet = {};
            (editTodayIds || []).forEach(function (id) { etSet[String(id)] = true; });
            editTodayChecks.forEach(function (c) { c.checked = !!etSet[c.dataset.view]; });
            var cdSet = {};
            (checkDbIds || []).forEach(function (id) { cdSet[String(id)] = true; });
            checkDbChecks.forEach(function (c) { c.checked = !!cdSet[c.dataset.view]; });
            syncGroupChecks();
            syncViewOnlyAvailability();
            baseline = checkedSet(); dirty = false;   // chỉ so theo các view hiển thị (phạm vi)
        }
        function checkedSet() {
            var s = {}; viewChecks.forEach(function (c) { if (c.checked) s[c.value] = true; }); return s;
        }
        function setsDiffer(a, b) {
            var ka = Object.keys(a), kb = Object.keys(b);
            if (ka.length !== kb.length) return true;
            for (var i = 0; i < ka.length; i++) { if (!b[ka[i]]) return true; }
            return false;
        }
        function lockAll(tagText) {
            setAllChecks(true);
            permArea.classList.add('disabled-area');
            btnSave.disabled = true;
            roleTag.style.display = '';
            roleTag.textContent = tagText;
            roleTag.classList.add('admin');
            syncable = false;
        }

        function loadUser(uid, role) {
            uid = parseInt(uid, 10) || 0;
            currentUid = uid; dirty = false; syncable = false;
            toggleAdminCard(uid);
            if (!uid) {
                permArea.classList.add('disabled-area');
                btnSave.disabled = true;
                roleTag.style.display = 'none';
                setAllChecks(false);
                return;
            }
            // Admin phó KHÔNG tự phân quyền cho chính mình: xem toàn quyền (khóa) như admin chọn chính mình.
            if (uid === window.PERM_ME_ID && window.PERM_ME_IS_DEPUTY) {
                lockAll('ADMIN PHÓ — toàn quyền trong phạm vi');
                return;
            }
            if (role === 'admin') {
                lockAll('ADMIN — toàn quyền');
                return;
            }
            roleTag.style.display = '';
            roleTag.textContent = 'Người dùng';
            roleTag.classList.remove('admin');

            fetch(AJAX_BASE + 'user_views_get&user_id=' + encodeURIComponent(uid), { credentials: 'same-origin' })
                .then(function (r) { return r.json(); })
                .then(function (res) {
                    if (!res || !res.ok) { showToast((res && res.message) || 'Không tải được quyền', true); return; }
                    applyViewIds(res.view_ids || [], res.view_only_ids || [], res.edit_today_ids || [], res.check_db_ids || []);
                    permArea.classList.remove('disabled-area');
                    btnSave.disabled = false;
                    syncable = true;
                })
                .catch(function () { showToast('Lỗi kết nối', true); });
        }

        // Combobox tìm-kiếm tài khoản (thay select).
        window.makeCombo({
            rootId: 'userCombo', inputId: 'userComboInput', listId: 'userComboList',
            items: window.PERM_USERS, hidden: userSelect,
            onSelect: function (it) { loadUser(it.id, it.role); }
        });

        btnSave.addEventListener('click', function () {
            var uid = userSelect.value;
            if (!uid) { showToast('Chưa chọn tài khoản', true); return; }
            var fd = new FormData();
            fd.append('user_id', uid);
            viewChecks.forEach(function (c) { if (c.checked) fd.append('view_ids[]', c.value); });
            viewOnlyChecks.forEach(function (c) { if (c.checked && !c.disabled) fd.append('view_only_ids[]', c.dataset.view); });
            editTodayChecks.forEach(function (c) { if (c.checked && !c.disabled) fd.append('edit_today_ids[]', c.dataset.view); });
            checkDbChecks.forEach(function (c) { if (c.checked && !c.disabled) fd.append('check_db_ids[]', c.dataset.view); });

            btnSave.disabled = true;
            fetch(AJAX_BASE + 'user_views_save', { method: 'POST', body: fd, credentials: 'same-origin' })
                .then(function (r) { return r.json(); })
                .then(function (res) {
                    btnSave.disabled = false;
                    if (res && res.ok) {
                        baseline = checkedSet(); dirty = false;
                        showToast('Đã lưu (' + (res.count || 0) + ' chức năng)');
                    } else {
                        showToast((res && res.message) || 'Lưu thất bại', true);
                    }
                })
                .catch(function () { btnSave.disabled = false; showToast('Lỗi kết nối', true); });
        });

        // Đồng bộ real-time: nếu người khác (admin trưởng <-> admin phó) đổi quyền user đang xem
        // mà bạn CHƯA chỉnh dở -> tự cập nhật checkbox (không đè khi đang chỉnh).
        setInterval(function () {
            if (!syncable || !currentUid || dirty) return;
            fetch(AJAX_BASE + 'user_views_get&user_id=' + currentUid, { credentials: 'same-origin' })
                .then(function (r) { return r.json(); })
                .then(function (res) {
                    if (!res || !res.ok || dirty) return;
                    var remote = {};
                    (res.view_ids || []).forEach(function (id) { if (visibleIds[String(id)]) remote[String(id)] = true; });
                    if (setsDiffer(remote, baseline)) {
                        applyViewIds(res.view_ids || [], res.view_only_ids || [], res.edit_today_ids || [], res.check_db_ids || []);
                        showToast('Đã đồng bộ thay đổi phân quyền từ người khác');
                    }
                })
                .catch(function () {});
        }, 5000);
    })();
    </script>

    <!-- ===== Chuyển quyền admin: gửi OTP -> modal 6 ô -> xác nhận ===== -->
    <script>
    (function () {
        var AJAX_BASE      = <?php echo json_encode($ajax_base); ?>;
        var newAdminSelect = document.getElementById('newAdminSelect');
        var btnSendOtp     = document.getElementById('btnSendOtp');
        var modal          = document.getElementById('transferModal');
        if (!newAdminSelect || !btnSendOtp || !modal) return;

        var sub        = document.getElementById('tfModalSub');
        var boxes      = Array.prototype.slice.call(document.querySelectorAll('#tfOtpBoxes .tf-otp-box'));
        var msg        = document.getElementById('tfOtpMsg');
        var countdown  = document.getElementById('tfCountdown');
        var btnResend  = document.getElementById('tfResend');
        var btnCancel  = document.getElementById('tfCancel');
        var btnConfirm = document.getElementById('tfConfirm');

        var timer = null, verifying = false;

        function showToast(m, isErr) {
            var t = document.getElementById('toast');
            t.textContent = m;
            t.classList.toggle('err', !!isErr);
            t.classList.add('show');
            clearTimeout(t._timer);
            t._timer = setTimeout(function () { t.classList.remove('show'); }, 1900);
        }
        function setMsg(text, isErr) {
            msg.textContent = text || '';
            msg.classList.remove('ok', 'err');
            if (text) msg.classList.add(isErr ? 'err' : 'ok');
        }
        function lockBoxes(l) { boxes.forEach(function (b) { b.disabled = l; }); }
        function collect()   { return boxes.map(function (b) { return b.value; }).join('').toUpperCase(); }

        /* Bật nút "Gửi mã" khi đã chọn admin mới. */
        newAdminSelect.addEventListener('change', function () {
            btnSendOtp.disabled = !newAdminSelect.value;
        });

        function openModal(masked) {
            sub.innerHTML = 'Mã OTP 6 ký tự đã gửi tới email admin hiện tại'
                + (masked ? ' (<b>' + masked + '</b>)' : '') + '. Nhập mã để hoàn tất.';
            boxes.forEach(function (b) { b.value = ''; });
            setMsg('');
            modal.classList.add('show');
            lockBoxes(false);
            boxes[0].focus();
        }
        function closeModal() {
            modal.classList.remove('show');
            if (timer) { clearInterval(timer); timer = null; }
        }

        function startCountdown(sec) {
            if (timer) { clearInterval(timer); timer = null; }
            var r = sec;
            countdown.textContent = 'Hết hạn sau ' + r + 's';
            btnResend.disabled = true;
            lockBoxes(false);
            timer = setInterval(function () {
                r--;
                if (r <= 0) {
                    clearInterval(timer); timer = null;
                    countdown.textContent = 'Mã đã hết hạn';
                    btnResend.disabled = false;
                    lockBoxes(true);
                    setMsg('Mã đã hết hạn (quá 60 giây). Hãy gửi lại mã.', true);
                } else {
                    countdown.textContent = 'Hết hạn sau ' + r + 's';
                }
            }, 1000);
        }

        function sendOtp(isResend) {
            var id = newAdminSelect.value;
            if (!id) { showToast('Chưa chọn admin mới', true); return; }
            (isResend ? btnResend : btnSendOtp).disabled = true;
            var fd = new FormData();
            fd.append('new_user_id', id);
            fetch(AJAX_BASE + 'admin_transfer_send_otp', { method: 'POST', body: fd, credentials: 'same-origin' })
                .then(function (r) { return r.json(); })
                .then(function (res) {
                    if (res && res.ok) {
                        if (!isResend) {
                            openModal(res.masked);
                        } else {
                            boxes.forEach(function (b) { b.value = ''; });
                            lockBoxes(false); boxes[0].focus();
                            setMsg('Đã gửi lại mã.', false);
                        }
                        startCountdown(res.expires_in || 60);
                    } else {
                        (isResend ? btnResend : btnSendOtp).disabled = false;
                        showToast((res && res.message) || 'Không gửi được mã', true);
                    }
                })
                .catch(function () {
                    (isResend ? btnResend : btnSendOtp).disabled = false;
                    showToast('Lỗi kết nối', true);
                });
        }

        function verify() {
            if (verifying) return;
            var code = collect();
            if (code.length !== 6) { setMsg('Vui lòng nhập đủ 6 ký tự', true); return; }
            verifying = true;
            setMsg('Đang xác nhận...', false);
            var fd = new FormData();
            fd.append('otp', code);
            fetch(AJAX_BASE + 'admin_transfer_confirm', { method: 'POST', body: fd, credentials: 'same-origin' })
                .then(function (r) { return r.json(); })
                .then(function (res) {
                    verifying = false;
                    if (res && res.ok) {
                        if (timer) { clearInterval(timer); timer = null; }
                        var extra = (res.email_sent === false)
                            ? ' (email báo admin mới gửi chưa thành công — đã đẩy thông báo trong app)'
                            : ' Đã email + đẩy thông báo cho admin mới.';
                        setMsg('Chuyển quyền thành công!' + extra + ' Đang chuyển hướng...', false);
                        lockBoxes(true);
                        setTimeout(function () {
                            window.location.href = res.redirect || '?mod=home&controllers=index&action=index';
                        }, 1200);
                    } else {
                        setMsg((res && res.message) || 'Mã không đúng', true);
                        boxes.forEach(function (b) { b.value = ''; });
                        boxes[0].focus();
                    }
                })
                .catch(function () { verifying = false; setMsg('Lỗi kết nối, vui lòng thử lại', true); });
        }

        /* 6 ô: tự nhảy ô, dán mã, tự xác nhận khi đủ 6 */
        boxes.forEach(function (box, idx) {
            box.addEventListener('input', function () {
                box.value = box.value.toUpperCase();
                if (box.value && idx < boxes.length - 1) boxes[idx + 1].focus();
                if (collect().length === 6) verify();
            });
            box.addEventListener('keydown', function (e) {
                if (e.key === 'Backspace' && box.value === '' && idx > 0) boxes[idx - 1].focus();
            });
            box.addEventListener('paste', function (e) {
                e.preventDefault();
                var t = (e.clipboardData || window.clipboardData).getData('text').trim().toUpperCase();
                for (var i = 0; i < boxes.length; i++) boxes[i].value = t.charAt(i) || '';
                (boxes[Math.min(t.length, boxes.length) - 1] || boxes[0]).focus();
                if (collect().length === 6) verify();
            });
        });

        btnSendOtp.addEventListener('click', function () { sendOtp(false); });
        btnResend.addEventListener('click', function () { sendOtp(true); });
        btnConfirm.addEventListener('click', verify);
        btnCancel.addEventListener('click', closeModal);
        modal.addEventListener('click', function (e) { if (e.target === modal) closeModal(); });
    })();
    </script>

    <!-- ===== Tabs + Bổ nhiệm admin phó (chỉ admin trưởng) ===== -->
    <script>
    (function () {
        var AJAX_BASE = <?php echo json_encode($ajax_base); ?>;

        /* Chuyển tab trong tc-head */
        var tabs  = Array.prototype.slice.call(document.querySelectorAll('.tc-tab'));
        var panes = Array.prototype.slice.call(document.querySelectorAll('.tc-pane'));
        tabs.forEach(function (tab) {
            tab.addEventListener('click', function () {
                var key = tab.getAttribute('data-tc-tab');
                tabs.forEach(function (t) { t.classList.toggle('active', t === tab); });
                panes.forEach(function (p) { p.classList.toggle('active', p.getAttribute('data-tc-pane') === key); });
            });
        });

        var btnSaveDep = document.getElementById('btnSaveDeputy');
        var depList    = document.getElementById('deputyList');
        var esc        = window.__permEsc;
        if (!btnSaveDep || !depList || !window.makeCombo) return;

        var groupChecks = Array.prototype.slice.call(document.querySelectorAll('.dep-group'));
        var classChecks = Array.prototype.slice.call(document.querySelectorAll('.dep-class'));
        var nameById = {};
        (window.PERM_DEPUTY_USERS || []).forEach(function (u) { nameById[u.id] = u.name + ' (' + u.username + ')'; });

        function showToast(msg, isErr) {
            var t = document.getElementById('toast');
            t.textContent = msg;
            t.classList.toggle('err', !!isErr);
            t.classList.add('show');
            clearTimeout(t._timer);
            t._timer = setTimeout(function () { t.classList.remove('show'); }, 1900);
        }
        function setScope(groups, classes) {
            var g = {}, c = {};
            (groups || []).forEach(function (x) { g[String(x)] = true; });
            (classes || []).forEach(function (x) { c[String(x)] = true; });
            groupChecks.forEach(function (cb) { cb.checked = !!g[cb.value]; });
            classChecks.forEach(function (cb) { cb.checked = !!c[String(parseInt(cb.value, 10))]; });
        }
        function chipFor(uid) {
            return depList.querySelector('.dep-chip[data-deputy-id="' + uid + '"]');
        }

        /* Combobox chọn NHIỀU user (bổ nhiệm chung 1 phạm vi). */
        var depCombo = window.makeCombo({
            rootId: 'deputyCombo', inputId: 'deputyComboInput', listId: 'deputyComboList',
            tagsId: 'deputyTags', multi: true, items: window.PERM_DEPUTY_USERS,
            onChange: function (ids) {
                // Chọn đúng 1 user đã là admin phó -> nạp lại phạm vi đã lưu cho tiện chỉnh.
                if (ids.length === 1) {
                    var chip = chipFor(ids[0]);
                    if (chip) {
                        setScope(JSON.parse(chip.getAttribute('data-groups') || '[]'),
                                 JSON.parse(chip.getAttribute('data-classes') || '[]'));
                    }
                }
            }
        });

        btnSaveDep.addEventListener('click', function () {
            var ids = depCombo.selectedIds();
            if (!ids.length) { showToast('Chưa chọn user nào', true); return; }
            var groups = groupChecks.filter(function (c) { return c.checked; }).map(function (c) { return c.value; });
            var classes = classChecks.filter(function (c) { return c.checked; }).map(function (c) { return c.value; });
            if (!groups.length) { showToast('Chọn ít nhất 1 module', true); return; }
            if (!classes.length) { showToast('Chọn ít nhất 1 nhóm user', true); return; }

            var fd = new FormData();
            ids.forEach(function (id) { fd.append('user_ids[]', id); });
            groups.forEach(function (g) { fd.append('groups[]', g); });
            classes.forEach(function (c) { fd.append('class_ids[]', c); });
            btnSaveDep.disabled = true;
            fetch(AJAX_BASE + 'deputy_save', { method: 'POST', body: fd, credentials: 'same-origin' })
                .then(function (r) { return r.json(); })
                .then(function (res) {
                    btnSaveDep.disabled = false;
                    if (!res || !res.ok) { showToast((res && res.message) || 'Lưu thất bại', true); return; }
                    (res.results || []).forEach(function (r) {
                        var uid = r.user_id, st = r.status || 'pending';
                        var label = nameById[uid] || ('#' + uid);
                        var chip = chipFor(uid);
                        if (!chip) {
                            chip = document.createElement('span');
                            chip.setAttribute('data-deputy-id', uid);
                            chip.innerHTML = '<i class="fa-solid fa-user-gear"></i> ' + esc(label)
                                + ' <span class="st"></span>'
                                + ' <button type="button" class="dep-remove" title="Gỡ admin phó">&times;</button>';
                            depList.appendChild(chip);
                        }
                        chip.className = 'dep-chip is-' + (st === 'accepted' ? 'accepted' : 'pending');
                        var stEl = chip.querySelector('.st'); if (stEl) stEl.textContent = (st === 'accepted' ? 'Đã nhận' : 'Chờ nhận');
                        chip.setAttribute('data-groups', JSON.stringify(groups));
                        chip.setAttribute('data-classes', JSON.stringify(classes.map(function (x) { return parseInt(x, 10); })));
                    });
                    depCombo.reset();
                    showToast('Đã gửi lời mời bổ nhiệm admin phó');
                })
                .catch(function () { btnSaveDep.disabled = false; showToast('Lỗi kết nối', true); });
        });

        depList.addEventListener('click', function (e) {
            var btn = e.target.closest('.dep-remove');
            if (!btn) return;
            var chip = btn.closest('.dep-chip');
            var uid = chip.getAttribute('data-deputy-id');
            if (!window.confirm('Gỡ bổ nhiệm admin phó của tài khoản này?')) return;
            var fd = new FormData();
            fd.append('user_id', uid);
            fetch(AJAX_BASE + 'deputy_remove', { method: 'POST', body: fd, credentials: 'same-origin' })
                .then(function (r) { return r.json(); })
                .then(function (res) {
                    if (res && res.ok) { chip.remove(); showToast('Đã gỡ admin phó'); }
                    else showToast((res && res.message) || 'Gỡ thất bại', true);
                })
                .catch(function () { showToast('Lỗi kết nối', true); });
        });
    })();
    </script>

    <!-- App shell JS: bật chuông / việc cần làm / menu user trên header + poll trạng thái. -->
    <script src="<?php echo asset_ver('public/js/shared/app_shell.js'); ?>"></script>
</body>

</html>
