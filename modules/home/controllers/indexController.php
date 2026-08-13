<?php
# KHAI BÁO SỬ DỤNG SESSION
session_start();
ob_start();
require_once __DIR__ . '/../../../libraries/notifications.php';
require_once __DIR__ . '/../../../libraries/todos.php';
require_once __DIR__ . '/../../../libraries/evcal.php';
require_once __DIR__ . '/../../../libraries/home_layout.php';
// Hàm construct thả mặc định ở mọi nơi
function construct() {}
// Kiểm tra thông tin đăng nhập, chưa đẩy qua trang login để bắt login trước
// Quyết định Gọi view home
function indexAction()
{
    check_auth();
    $user = permission_current_user();
    $uid  = (int) ($user['id'] ?? 0);
    home_layout_ensure_tables();
    $menu_groups = permission_menu_for_user($user);
    if (function_exists('permission_filter_menu_groups')) {
        $menu_groups = permission_filter_menu_groups($menu_groups);
    }
    $is_admin_flag  = permission_is_admin($user);
    $is_deputy_flag = permission_is_deputy($user);
    if ($is_admin_flag || $is_deputy_flag) {
        $menu_groups['QUẢN TRỊ HỆ THỐNG'] = [
            ['module' => 'admin_factory', 'controller' => 'admin', 'action' => 'manage_permissions', 'label' => 'Phân quyền người dùng'],
            ['module' => 'admin_factory', 'controller' => 'admin', 'action' => 'manage_user_list',   'label' => 'Quản lý người dùng'],
        ];
    }
    $menu_groups = home_menu_order_apply($uid, $menu_groups);
    load_view('index', [
        'menu_groups'  => $menu_groups,
        'nav_bar_keys' => home_menu_bar_keys($uid, array_keys($menu_groups)),
        'is_admin'     => $is_admin_flag,
        'is_deputy'    => $is_deputy_flag,
        'current_user' => $user,
        'bg_slides'    => home_bg_user_slides($uid),
    ]);
}
function expermoreAction(){
    load_view('expermore');
}

/** Trang Lịch phóng to (full page, không phải modal) — xem/kéo-thả toàn bộ sự kiện cá nhân. */
function calendar_fullAction()
{
    check_auth();
    load_view('calendar_full');
}

/**
 * Upload ảnh đại diện cho user đang đăng nhập (AJAX, trả JSON).
 * Lưu vào public/images/avatar/, cập nhật tbl_users.avatar.
 */
function uploadAvatarAction()
{
    header('Content-Type: application/json; charset=utf-8');
    $user = permission_current_user();
    if (!$user) {
        echo json_encode(['ok' => false, 'message' => 'Chưa đăng nhập.']);
        return;
    }
    if (empty($_FILES['avatar']) || $_FILES['avatar']['error'] !== UPLOAD_ERR_OK) {
        echo json_encode(['ok' => false, 'message' => 'Không nhận được tệp.']);
        return;
    }

    $file = $_FILES['avatar'];
    if ($file['size'] > 4 * 1024 * 1024) {
        echo json_encode(['ok' => false, 'message' => 'Ảnh vượt quá 4MB.']);
        return;
    }
    $info = @getimagesize($file['tmp_name']);
    if ($info === false) {
        echo json_encode(['ok' => false, 'message' => 'Tệp không phải hình ảnh.']);
        return;
    }
    $ext_map = [IMAGETYPE_JPEG => 'jpg', IMAGETYPE_PNG => 'png', IMAGETYPE_GIF => 'gif', IMAGETYPE_WEBP => 'webp'];
    if (!isset($ext_map[$info[2]])) {
        echo json_encode(['ok' => false, 'message' => 'Chỉ chấp nhận JPG/PNG/GIF/WEBP.']);
        return;
    }
    $ext = $ext_map[$info[2]];

    $dir = APPPATH . DIRECTORY_SEPARATOR . 'public' . DIRECTORY_SEPARATOR . 'images' . DIRECTORY_SEPARATOR . 'avatar';
    if (!is_dir($dir)) {
        @mkdir($dir, 0775, true);
    }
    $filename = 'u' . (int) $user['id'] . '_' . time() . '.' . $ext;
    $dest     = $dir . DIRECTORY_SEPARATOR . $filename;

    if (!move_uploaded_file($file['tmp_name'], $dest)) {
        echo json_encode(['ok' => false, 'message' => 'Không lưu được tệp.']);
        return;
    }

    // Xoá ảnh cũ (nếu có) để khỏi rác.
    $old = trim((string) ($user['avatar'] ?? ''));
    if ($old !== '' && $old !== $filename) {
        $old_path = $dir . DIRECTORY_SEPARATOR . basename($old);
        if (is_file($old_path)) { @unlink($old_path); }
    }

    db_update('tbl_users', ['avatar' => $filename], 'id = ' . (int) $user['id']);

    echo json_encode(['ok' => true, 'url' => 'public/images/avatar/' . $filename]);
}

/**
 * Đổi tên hiển thị (fullname) của user đang đăng nhập (AJAX, trả JSON).
 */
function updateProfileNameAction()
{
    header('Content-Type: application/json; charset=utf-8');
    $user = permission_current_user();
    if (!$user) {
        echo json_encode(['ok' => false, 'message' => 'Chưa đăng nhập.']);
        return;
    }
    $fullname = trim((string) ($_POST['fullname'] ?? ''));
    if ($fullname === '') {
        echo json_encode(['ok' => false, 'message' => 'Tên không được để trống.']);
        return;
    }
    if (mb_strlen($fullname, 'UTF-8') > 100) {
        $fullname = mb_substr($fullname, 0, 100, 'UTF-8');
    }
    db_update('tbl_users', ['fullname' => $fullname], 'id = ' . (int) $user['id']);
    echo json_encode(['ok' => true, 'fullname' => $fullname], JSON_UNESCAPED_UNICODE);
}

/**
 * Cài đặt tài khoản: cập nhật hồ sơ user đang đăng nhập (AJAX, trả JSON).
 * Cho sửa: fullname, ngày sinh (day/month/year), gender, phone.
 * KHÔNG cho sửa: username, email (chỉ xem). Avatar dùng riêng uploadAvatar.
 */
function updateAccountSettingsAction()
{
    header('Content-Type: application/json; charset=utf-8');
    $user = permission_current_user();
    if (!$user) {
        echo json_encode(['ok' => false, 'message' => 'Chưa đăng nhập.'], JSON_UNESCAPED_UNICODE);
        return;
    }

    $fullname = trim((string) ($_POST['fullname'] ?? ''));
    $day      = (string) ($_POST['day'] ?? '');
    $month    = (string) ($_POST['month'] ?? '');
    $year     = (string) ($_POST['year'] ?? '');
    $gender   = (string) ($_POST['gender'] ?? '');
    $phone    = trim((string) ($_POST['phone'] ?? ''));

    // --- Validate (đồng bộ với form đăng ký) ---
    if ($fullname === '') {
        echo json_encode(['ok' => false, 'message' => 'Họ và tên không được để trống.'], JSON_UNESCAPED_UNICODE);
        return;
    }
    if (mb_strlen($fullname, 'UTF-8') > 100) {
        $fullname = mb_substr($fullname, 0, 100, 'UTF-8');
    }
    if (!ctype_digit($day) || (int) $day < 1 || (int) $day > 31) {
        echo json_encode(['ok' => false, 'message' => 'Ngày sinh không hợp lệ.'], JSON_UNESCAPED_UNICODE);
        return;
    }
    if (!ctype_digit($month) || (int) $month < 1 || (int) $month > 12) {
        echo json_encode(['ok' => false, 'message' => 'Tháng sinh không hợp lệ.'], JSON_UNESCAPED_UNICODE);
        return;
    }
    if (!ctype_digit($year) || strlen($year) !== 4) {
        echo json_encode(['ok' => false, 'message' => 'Năm sinh không hợp lệ.'], JSON_UNESCAPED_UNICODE);
        return;
    }
    if (!in_array($gender, ['M', 'F'], true)) {
        echo json_encode(['ok' => false, 'message' => 'Vui lòng chọn giới tính.'], JSON_UNESCAPED_UNICODE);
        return;
    }
    if ($phone !== '' && !preg_match('/^(03|05|07|08|09)[0-9]{8}$/', $phone)) {
        echo json_encode(['ok' => false, 'message' => 'Số điện thoại không hợp lệ.'], JSON_UNESCAPED_UNICODE);
        return;
    }

    $dateofbirth = sprintf('%04d-%02d-%02d', (int) $year, (int) $month, (int) $day);

    db_update('tbl_users', [
        'fullname'    => $fullname,
        'dateofbirth' => $dateofbirth,
        'gender'      => $gender,
        'phone'       => $phone,
    ], 'id = ' . (int) $user['id']);

    echo json_encode([
        'ok'          => true,
        'fullname'    => $fullname,
        'dateofbirth' => $dateofbirth,
        'gender'      => $gender,
        'phone'       => $phone,
        'message'     => 'Đã lưu thông tin tài khoản.',
    ], JSON_UNESCAPED_UNICODE);
}

/* ============================================================
 *  PHÂN QUYỀN REAL-TIME — trạng thái menu của user đang đăng nhập
 *  (app_shell.js poll: đổi quyền -> render lại menu; mất quyền view
 *   đang xem -> đẩy về trang chủ). Trả JSON.
 * ============================================================ */
function permissionStateAction()
{
    header('Content-Type: application/json; charset=utf-8');
    $user = permission_current_user();
    if (!$user) {
        echo json_encode(['ok' => false, 'message' => 'Chưa đăng nhập.']);
        return;
    }
    echo json_encode(home_permission_state_payload(
        $user,
        (string) ($_GET['cur_mod'] ?? $_POST['cur_mod'] ?? ''),
        (string) ($_GET['cur_ctl'] ?? $_POST['cur_ctl'] ?? ''),
        (string) ($_GET['cur_act'] ?? $_POST['cur_act'] ?? '')
    ), JSON_UNESCAPED_UNICODE);
}

/** Nội dung trả về của permissionState — tách riêng để endpoint gộp app_poll dùng lại
 *  (xem app_pollAction). */
function home_permission_state_payload($user, $cur_mod = '', $cur_ctl = '', $cur_act = '')
{
    $is_admin  = permission_is_admin($user);
    $is_deputy = function_exists('permission_is_deputy') && permission_is_deputy($user);

    // Icon nhóm (đồng bộ với layout/sidebar-app.php::app_sidebar_group_icon).
    $icon_map = [
        'NHẬP KHO' => 'fa-truck-ramp-box', 'NHẬP NGUYÊN VẬT LIỆU' => 'fa-boxes-stacked',
        'XUẤT KHO' => 'fa-truck-fast', 'SẢN XUẤT' => 'fa-industry',
        'THU CHI' => 'fa-money-bill-trend-up', 'CÔNG NỢ' => 'fa-file-invoice-dollar',
        'BÁO CÁO' => 'fa-chart-column', 'TỒN KHO' => 'fa-boxes-packing',
        'BÁN HÀNG - NHÀ MÁY' => 'fa-cart-shopping',
        'KHO SẢN PHẨM' => 'fa-warehouse', 'NHÂN SỰ' => 'fa-users',
        'HỒ SƠ SẢN PHẨM' => 'fa-folder-open', 'ADMIN - NHÀ MÁY' => 'fa-screwdriver-wrench',
        'BIỂU MẪU QL VSATTP' => 'fa-clipboard-list', 'KẾ TOÁN - NHÀ MÁY' => 'fa-calculator',
        'QUẢN TRỊ HỆ THỐNG' => 'fa-user-shield', 'VIỆC CẦN LÀM' => 'fa-list-check',
        'DỰ ÁN' => 'fa-diagram-project', 'ĐƠN ĐẶT HÀNG' => 'fa-clipboard-check',
        'QUẢN LÝ FILE' => 'fa-folder-open', 'VĂN PHÒNG' => 'fa-briefcase',
        'LƯƠNG' => 'fa-money-check-dollar', 'Sự kiện' => 'fa-comment-dots',
        'QUẢN LÝ ĐƠN HÀNG' => 'fa-file-invoice', 'KHO' => 'fa-dolly',
    ];

    $groups = permission_menu_for_user($user);
    if (function_exists('permission_filter_menu_groups')) {
        $groups = permission_filter_menu_groups($groups);
    }
    // Nhóm "QUẢN TRỊ HỆ THỐNG" cho admin / admin phó (như sidebar).
    if ($is_admin || $is_deputy) {
        $groups['QUẢN TRỊ HỆ THỐNG'] = [
            ['module' => 'admin_factory', 'controller' => 'admin', 'action' => 'manage_permissions', 'label' => 'Phân quyền người dùng'],
            ['module' => 'admin_factory', 'controller' => 'admin', 'action' => 'manage_user_list',   'label' => 'Quản lý người dùng'],
        ];
    }
    $groups = home_menu_order_apply((int) $user['id'], $groups);

    $out_groups = [];
    $allowed_keys = [];
    foreach ($groups as $gname => $items) {
        if (empty($items)) continue;
        $out_items = [];
        foreach ($items as $v) {
            $m = (string) ($v['module'] ?? '');
            $c = (string) ($v['controller'] ?? '');
            $a = (string) ($v['action'] ?? '');
            $allowed_keys[strtolower($m . '|' . $c . '|' . $a)] = true;
            $out_items[] = [
                'module' => $m, 'controller' => $c, 'action' => $a,
                'label'  => (string) ($v['label'] ?? ''),
                'url'    => '?mod=' . urlencode($m) . '&controllers=' . urlencode($c) . '&action=' . urlencode($a),
            ];
        }
        $display = function_exists('permission_group_display_label')
            ? permission_group_display_label($gname) : $gname;
        $out_groups[] = [
            'label'   => (string) $gname,
            'display' => (string) $display,
            'icon'    => $icon_map[$gname] ?? 'fa-folder',
            'items'   => $out_items,
        ];
    }

    // Chữ ký: đổi -> client render lại menu.
    $sig = md5(json_encode([$is_admin, $is_deputy, array_keys($allowed_keys)]));

    // View hiện tại còn được phép không? (admin / view không-gate / view được gán).
    $cur_mod = (string) $cur_mod;
    $cur_ctl = (string) $cur_ctl;
    $cur_act = (string) $cur_act;
    $current_allowed = true;
    if (!$is_admin && $cur_mod !== '' && $cur_mod !== 'home' && $cur_mod !== 'auth') {
        // Admin phó được vào màn phân quyền/quản lý user dù không nằm trong tbl_views.
        $deputy_pass = $is_deputy && $cur_mod === 'admin_factory'
            && in_array($cur_act, ['manage_permissions', 'manage_user_list'], true);
        if (!$deputy_pass) {
            $view = permission_find_view($cur_mod, $cur_ctl, $cur_act);
            if ($view) { // chỉ những view có trong danh mục mới bị gate
                $current_allowed = isset($allowed_keys[strtolower($cur_mod . '|' . $cur_ctl . '|' . $cur_act)]);
            }
        }
    }

    return [
        'ok'              => true,
        'is_admin'        => $is_admin,
        'sig'             => $sig,
        'groups'          => $out_groups,
        'current_allowed' => $current_allowed,
    ];
}

/* ============================================================
 *  ENDPOINT GỘP CHO MỌI POLL NỀN (anh Sáu chốt 13/8/2026)
 *  ---------------------------------------------------------
 *  Trước đây mỗi widget của app-shell tự poll 1 endpoint riêng
 *  (chuông 8s, việc cần làm 5s, phân quyền 5s, trạng thái tài
 *  khoản 6s, lịch 25s, chat 6s) => ~7 request nền / 6 giây / tab.
 *  Nay JS gom hết vào MỘT request duy nhất mỗi chu kỳ:
 *      POST ?mod=home&controllers=index&action=app_poll
 *           parts=notif,todo,perm,account,evcal,chat
 *  Tham số riêng của từng phần đi kèm tiền tố p_<part>_<key>
 *  (vd p_todo_list_id, p_perm_cur_mod) — xem AppPoll.hub trong
 *  public/js/shared/app_shell.js.
 *  Phần nào không đăng ký thì KHÔNG tính -> không tốn truy vấn.
 * ============================================================ */
function app_pollAction()
{
    header('Content-Type: application/json; charset=utf-8');
    $user = permission_current_user();
    if (!$user) { echo json_encode(['ok' => false, 'message' => 'Chưa đăng nhập.']); return; }
    $uid = (int) $user['id'];

    $raw   = (string) ($_POST['parts'] ?? $_GET['parts'] ?? '');
    $parts = array_values(array_filter(array_map('trim', explode(',', $raw))));
    $arg = function ($part, $key, $default = '') {
        $k = 'p_' . $part . '_' . $key;
        return isset($_POST[$k]) ? $_POST[$k] : (isset($_GET[$k]) ? $_GET[$k] : $default);
    };

    $out = [];
    foreach ($parts as $part) {
        switch ($part) {
            case 'notif':
                $limit  = (int) $arg('notif', 'limit', 10);
                $offset = (int) $arg('notif', 'offset', 0);
                if ($limit < 1)  $limit = 10;
                if ($limit > 50) $limit = 50;
                $out['notif'] = [
                    'ok'     => true,
                    'unread' => notify_unread_count($uid),
                    'total'  => notify_total_count($uid),
                    'offset' => $offset,
                    'data'   => notify_list($uid, $limit, $offset),
                ];
                break;

            case 'todo':
                $lid = (int) $arg('todo', 'list_id', 0);
                if ($lid <= 0) $lid = todo_default_list_id($uid);
                $role = todo_user_role_in_list($uid, $lid);
                if ($role === null) {
                    $out['todo'] = ['ok' => false, 'message' => 'Không có quyền xem danh sách.'];
                    break;
                }
                $list = todo_list_get($lid);
                $owner_real = $list ? (string) (($list['owner_fullname'] ?? '') ?: ($list['owner_username'] ?? '')) : '';
                $alias_map  = todo_viewer_alias_map($uid);
                $out['todo'] = [
                    'ok'         => true,
                    'list_id'    => $lid,
                    'role'       => $role,
                    'title'      => $list ? (string) $list['title'] : '',
                    'owner_name' => $list ? ($alias_map[(int) $list['owner_id']] ?? $owner_real) : '',
                    'can_edit'   => todo_can_edit_content($uid, $lid),
                    'data'       => todo_list_items($uid, $lid),
                    'pending'    => todo_pending_count($uid),
                    'clear_mode' => todo_get_clear_mode($uid),
                ];
                break;

            case 'perm':
                $out['perm'] = home_permission_state_payload(
                    $user,
                    (string) $arg('perm', 'cur_mod'),
                    (string) $arg('perm', 'cur_ctl'),
                    (string) $arg('perm', 'cur_act')
                );
                break;

            case 'account':
                // Bị buộc rời / bị đóng băng -> client hiện modal + tự logout.
                $status = (string) ($user['status'] ?? '');
                $active = !in_array($status, ['left', 'inactive'], true);
                $out['account'] = [
                    'ok'     => true,
                    'active' => $active,
                    'reason' => $active ? '' : ($status === 'inactive' ? 'inactive' : 'left'),
                ];
                break;

            case 'evcal':
                // Quét lời nhắc tới giờ -> đẩy chuông. Đây là việc HẸN GIỜ chạy nhờ poll
                // (hệ thống không có cron) nên vẫn chạy cả khi tab bị ẩn, xem keepHidden
                // trong app_shell.js.
                $out['evcal'] = ['ok' => true, 'fired' => evcal_check_due_reminders($uid)];
                break;

            case 'chat':
                require_once LIBPATH . DIRECTORY_SEPARATOR . 'chat.php';
                chat_ensure_tables();
                chat_touch_presence($uid);   // heartbeat: đánh dấu đang online
                $out['chat'] = [
                    'ok'            => true,
                    'unread_total'  => chat_unread_total($uid),
                    'latest'        => chat_latest_incoming($uid),
                    'due_reminders' => chat_due_reminders($uid),
                    'notif'         => chat_notif_state($uid),
                ];
                break;
        }
    }

    echo json_encode(['ok' => true, 'parts' => $out], JSON_UNESCAPED_UNICODE);
}

/* ============================================================
 *  Chuông thông báo (bell) — AJAX cho app-header
 * ============================================================ */

/** Danh sách thông báo + số chưa đọc của user đang đăng nhập. */
function notificationsAction()
{
    header('Content-Type: application/json; charset=utf-8');
    $user = permission_current_user();
    if (!$user) { echo json_encode(['ok' => false, 'message' => 'Chưa đăng nhập.']); return; }
    $uid = (int) $user['id'];
    $limit  = (int) ($_GET['limit'] ?? $_POST['limit'] ?? 10);
    $offset = (int) ($_GET['offset'] ?? $_POST['offset'] ?? 0);
    if ($limit < 1)  $limit = 10;
    if ($limit > 50) $limit = 50;
    echo json_encode([
        'ok'     => true,
        'unread' => notify_unread_count($uid),
        'total'  => notify_total_count($uid),
        'offset' => $offset,
        'data'   => notify_list($uid, $limit, $offset),
    ], JSON_UNESCAPED_UNICODE);
}

/** Đánh dấu 1 thông báo đã đọc (POST id). Trả số chưa đọc còn lại. */
function markNotificationReadAction()
{
    header('Content-Type: application/json; charset=utf-8');
    $user = permission_current_user();
    if (!$user) { echo json_encode(['ok' => false, 'message' => 'Chưa đăng nhập.']); return; }
    $uid = (int) $user['id'];
    notify_mark_read((int) ($_POST['id'] ?? 0), $uid);
    echo json_encode(['ok' => true, 'unread' => notify_unread_count($uid)], JSON_UNESCAPED_UNICODE);
}

/** Đánh dấu tất cả thông báo đã đọc. */
function markAllNotificationsReadAction()
{
    header('Content-Type: application/json; charset=utf-8');
    $user = permission_current_user();
    if (!$user) { echo json_encode(['ok' => false, 'message' => 'Chưa đăng nhập.']); return; }
    $uid = (int) $user['id'];
    notify_mark_all_read($uid);
    echo json_encode(['ok' => true, 'unread' => 0], JSON_UNESCAPED_UNICODE);
}

/** Xóa hết thông báo (đã xem rồi xóa). */
function deleteAllNotificationsAction()
{
    header('Content-Type: application/json; charset=utf-8');
    $user = permission_current_user();
    if (!$user) { echo json_encode(['ok' => false, 'message' => 'Chưa đăng nhập.']); return; }
    $uid = (int) $user['id'];
    notify_delete_all($uid);
    echo json_encode(['ok' => true, 'unread' => 0, 'total' => 0], JSON_UNESCAPED_UNICODE);
}

/* ============================================================
 *  Todo list — AJAX cho app-header (cạnh chuông)
 *  Nâng cấp: nhiều danh sách + chia sẻ cộng tác (xem libraries/todos.php).
 *  Quyền thao tác task = owner hoặc member đã "Nhận" (todo_can_access).
 * ============================================================ */

/** Trả về user đang đăng nhập hoặc xuất JSON lỗi + 0. */
function todo_current_uid_or_fail()
{
    header('Content-Type: application/json; charset=utf-8');
    $user = permission_current_user();
    if (!$user) { echo json_encode(['ok' => false, 'message' => 'Chưa đăng nhập.']); return 0; }
    return (int) $user['id'];
}

/** Tên hiển thị của user đang đăng nhập (cho nội dung thông báo). */
function todo_me_name()
{
    $u = permission_current_user();
    if (!$u) return 'Ai đó';
    return (string) ((trim((string) ($u['fullname'] ?? '')) ?: ($u['username'] ?? '')) ?: 'Ai đó');
}

/** Link gắn vào thông báo lời mời (bell trích list_id từ đây). */
function todo_invite_link($list_id)
{
    return '?mod=home&controllers=index&action=index&todo_list=' . (int) $list_id;
}

/* ---- Danh sách (lists) ---- */

/** Tất cả danh sách hiển thị trên tab của user (sở hữu + đã nhận). */
function todoListsAction()
{
    $uid = todo_current_uid_or_fail();
    if (!$uid) return;
    echo json_encode([
        'ok'         => true,
        'lists'      => todo_user_lists($uid),
        'pending'    => todo_pending_count($uid),
        'clear_mode' => todo_get_clear_mode($uid),
        'me'         => $uid,
    ], JSON_UNESCAPED_UNICODE);
}

/** Tạo danh sách mới (POST title). */
function todoListCreateAction()
{
    $uid = todo_current_uid_or_fail();
    if (!$uid) return;
    $lid = todo_list_create($uid, $_POST['title'] ?? '');
    if ($lid <= 0) { echo json_encode(['ok' => false, 'message' => 'Không tạo được danh sách.']); return; }
    echo json_encode(['ok' => true, 'list_id' => $lid, 'lists' => todo_user_lists($uid)], JSON_UNESCAPED_UNICODE);
}

/** Đổi tên danh sách (POST list_id, title) — chỉ chủ. */
function todoListRenameAction()
{
    $uid = todo_current_uid_or_fail();
    if (!$uid) return;
    $ok = todo_list_rename($uid, (int) ($_POST['list_id'] ?? 0), $_POST['title'] ?? '');
    echo json_encode(['ok' => $ok], JSON_UNESCAPED_UNICODE);
}

/** Xóa danh sách (POST list_id) — chỉ chủ; báo chuông cho thành viên. */
function todoListDeleteAction()
{
    $uid = todo_current_uid_or_fail();
    if (!$uid) return;
    $lid  = (int) ($_POST['list_id'] ?? 0);
    $list = todo_list_get($lid);
    $members = todo_list_delete($uid, $lid);
    if ($members === null) { echo json_encode(['ok' => false, 'message' => 'Bạn không phải chủ danh sách.']); return; }
    $title = $list ? (string) $list['title'] : 'danh sách';
    foreach ($members as $m) {
        notify_create($m, 'Danh sách công việc đã bị xóa',
            todo_me_name() . ' đã xóa danh sách “' . $title . '”.', '', 'todo_deleted', $uid);
    }
    echo json_encode(['ok' => true, 'lists' => todo_user_lists($uid), 'pending' => todo_pending_count($uid)], JSON_UNESCAPED_UNICODE);
}

/** Người nhận rời bỏ danh sách (POST list_id) — báo chuông cho chủ. */
function todoListLeaveAction()
{
    $uid = todo_current_uid_or_fail();
    if (!$uid) return;
    $lid  = (int) ($_POST['list_id'] ?? 0);
    $list = todo_list_get($lid);
    if (!todo_list_leave($uid, $lid)) { echo json_encode(['ok' => false, 'message' => 'Không rời được danh sách.']); return; }
    if ($list) {
        notify_create((int) $list['owner_id'], 'Rời danh sách công việc',
            todo_me_name() . ' đã rời danh sách “' . (string) $list['title'] . '”.',
            '', 'todo_leave', $uid);
    }
    echo json_encode(['ok' => true, 'lists' => todo_user_lists($uid), 'pending' => todo_pending_count($uid)], JSON_UNESCAPED_UNICODE);
}

/* ---- Chia sẻ / thành viên (share) ---- */

/** Bảng thành viên hiện tại + danh sách user có thể gán (POST/GET list_id, keyword). */
function todoMembersAction()
{
    $uid = todo_current_uid_or_fail();
    if (!$uid) return;
    $lid = (int) ($_GET['list_id'] ?? $_POST['list_id'] ?? 0);
    if (todo_user_role_in_list($uid, $lid) !== 'owner') {
        echo json_encode(['ok' => false, 'message' => 'Chỉ chủ danh sách mới quản lý chia sẻ.']); return;
    }
    $kw = (string) ($_GET['keyword'] ?? $_POST['keyword'] ?? '');
    echo json_encode([
        'ok'         => true,
        'members'    => todo_list_members($lid, $uid),
        'assignable' => todo_assignable_users($uid, $lid, $kw),
    ], JSON_UNESCAPED_UNICODE);
}

/** Gửi (gán) danh sách cho 1+ user (POST list_id, user_ids[]). Đẩy chuông cho từng người. */
function todoShareAction()
{
    $uid = todo_current_uid_or_fail();
    if (!$uid) return;
    $lid  = (int) ($_POST['list_id'] ?? 0);
    $list = todo_list_get($lid);
    if (!$list || (int) $list['owner_id'] !== $uid) {
        echo json_encode(['ok' => false, 'message' => 'Bạn không phải chủ danh sách.']); return;
    }
    $ids = $_POST['user_ids'] ?? ($_POST['user_id'] ?? []);
    if (is_string($ids)) $ids = explode(',', $ids);
    if (!is_array($ids)) $ids = [$ids];
    $canEdit = !empty($_POST['can_edit']) && $_POST['can_edit'] !== '0';
    $sent = 0; $errors = [];
    foreach ($ids as $tid) {
        $tid = (int) $tid;
        if ($tid <= 0) continue;
        $res = todo_list_share($uid, $lid, $tid, $canEdit);
        if (!empty($res['ok'])) {
            notify_create($tid, 'Lời mời cộng tác công việc',
                todo_me_name() . ' mời bạn vào danh sách “' . (string) $list['title'] . '”.',
                todo_invite_link($lid), 'todo_invite', $uid);
            $sent++;
        } elseif (!empty($res['message'])) {
            $errors[] = $res['message'];
        }
    }
    echo json_encode([
        'ok'      => true,
        'sent'    => $sent,
        'errors'  => $errors,
        'members' => todo_list_members($lid, $uid),
        'assignable' => todo_assignable_users($uid, $lid, ''),
    ], JSON_UNESCAPED_UNICODE);
}

/** Bật/tắt quyền thêm-sửa cho 1 người nhận đã có trong danh sách (POST list_id, user_id, can_edit). */
function todoSetMemberEditAction()
{
    $uid = todo_current_uid_or_fail();
    if (!$uid) return;
    $lid = (int) ($_POST['list_id'] ?? 0);
    $tid = (int) ($_POST['user_id'] ?? 0);
    $canEdit = !empty($_POST['can_edit']) && $_POST['can_edit'] !== '0';
    $ok = todo_set_member_can_edit($uid, $lid, $tid, $canEdit);
    echo json_encode([
        'ok'      => $ok,
        'members' => todo_list_members($lid, $uid),
    ], JSON_UNESCAPED_UNICODE);
}

/** Gỡ 1 người nhận khỏi danh sách (POST list_id, user_id) — báo chuông cho họ. */
function todoUnshareAction()
{
    $uid = todo_current_uid_or_fail();
    if (!$uid) return;
    $lid  = (int) ($_POST['list_id'] ?? 0);
    $tid  = (int) ($_POST['user_id'] ?? 0);
    $list = todo_list_get($lid);
    if (!$list || (int) $list['owner_id'] !== $uid) {
        echo json_encode(['ok' => false, 'message' => 'Bạn không phải chủ danh sách.']); return;
    }
    if (!todo_list_unshare($uid, $lid, $tid)) { echo json_encode(['ok' => false]); return; }
    notify_create($tid, 'Bị gỡ khỏi danh sách công việc',
        todo_me_name() . ' đã gỡ bạn khỏi danh sách “' . (string) $list['title'] . '”.',
        '', 'todo_removed', $uid);
    echo json_encode([
        'ok'      => true,
        'members' => todo_list_members($lid, $uid),
        'assignable' => todo_assignable_users($uid, $lid, ''),
    ], JSON_UNESCAPED_UNICODE);
}

/** Người được mời Nhận / Từ chối (POST list_id, accept) — báo chuông cho chủ. */
function todoRespondInviteAction()
{
    $uid = todo_current_uid_or_fail();
    if (!$uid) return;
    $lid    = (int) ($_POST['list_id'] ?? 0);
    $accept = !empty($_POST['accept']) && $_POST['accept'] !== '0';
    $list   = todo_list_get($lid);
    if (!todo_list_respond($uid, $lid, $accept)) {
        echo json_encode(['ok' => false, 'message' => 'Lời mời không còn hiệu lực.']); return;
    }
    if ($list) {
        notify_create((int) $list['owner_id'],
            $accept ? 'Đã nhận danh sách công việc' : 'Đã từ chối danh sách công việc',
            todo_me_name() . ($accept ? ' đã nhận' : ' đã từ chối') . ' danh sách “' . (string) $list['title'] . '”.',
            '', $accept ? 'todo_accept' : 'todo_decline', $uid);
    }
    echo json_encode(['ok' => true, 'accepted' => $accept, 'pending' => todo_pending_count($uid)], JSON_UNESCAPED_UNICODE);
}

/* ---- Task trong 1 danh sách ---- */

/** Task của 1 danh sách + meta (POST/GET list_id). Trả role để JS bật/tắt nút chủ. */
function todosAction()
{
    $uid = todo_current_uid_or_fail();
    if (!$uid) return;
    $lid = (int) ($_GET['list_id'] ?? $_POST['list_id'] ?? 0);
    if ($lid <= 0) $lid = todo_default_list_id($uid);
    $role = todo_user_role_in_list($uid, $lid);
    if ($role === null) { echo json_encode(['ok' => false, 'message' => 'Không có quyền xem danh sách.']); return; }
    $list = todo_list_get($lid);
    $owner_real = $list ? (string) (($list['owner_fullname'] ?? '') ?: ($list['owner_username'] ?? '')) : '';
    $alias_map  = todo_viewer_alias_map($uid);
    echo json_encode([
        'ok'         => true,
        'list_id'    => $lid,
        'role'       => $role,
        'title'      => $list ? (string) $list['title'] : '',
        'owner_name' => $list ? ($alias_map[(int) $list['owner_id']] ?? $owner_real) : '',
        'can_edit'   => todo_can_edit_content($uid, $lid),
        'data'       => todo_list_items($uid, $lid),
        'pending'    => todo_pending_count($uid),
        'clear_mode' => todo_get_clear_mode($uid),
    ], JSON_UNESCAPED_UNICODE);
}

/** Thêm task (POST list_id, content). */
function todoCreateAction()
{
    $uid = todo_current_uid_or_fail();
    if (!$uid) return;
    $item = todo_create($uid, (int) ($_POST['list_id'] ?? 0), $_POST['content'] ?? '');
    if (!$item) { echo json_encode(['ok' => false, 'message' => 'Nội dung trống hoặc không có quyền.']); return; }
    echo json_encode(['ok' => true, 'item' => $item, 'pending' => todo_pending_count($uid)], JSON_UNESCAPED_UNICODE);
}

/** Sửa nội dung task (POST list_id, id, content). */
function todoUpdateAction()
{
    $uid = todo_current_uid_or_fail();
    if (!$uid) return;
    $ok = todo_update_content($uid, (int) ($_POST['list_id'] ?? 0), (int) ($_POST['id'] ?? 0), $_POST['content'] ?? '');
    echo json_encode(['ok' => $ok], JSON_UNESCAPED_UNICODE);
}

/** Đánh dấu xong / chưa xong (POST list_id, id, done). */
function todoToggleAction()
{
    $uid = todo_current_uid_or_fail();
    if (!$uid) return;
    todo_set_done($uid, (int) ($_POST['list_id'] ?? 0), (int) ($_POST['id'] ?? 0),
        !empty($_POST['done']) && $_POST['done'] !== '0');
    echo json_encode(['ok' => true, 'pending' => todo_pending_count($uid)], JSON_UNESCAPED_UNICODE);
}

/** Xóa 1 task (POST list_id, id). */
function todoDeleteAction()
{
    $uid = todo_current_uid_or_fail();
    if (!$uid) return;
    todo_delete($uid, (int) ($_POST['list_id'] ?? 0), (int) ($_POST['id'] ?? 0));
    echo json_encode(['ok' => true, 'pending' => todo_pending_count($uid)], JSON_UNESCAPED_UNICODE);
}

/** Xóa task đã hoàn thành trong 1 danh sách (POST list_id). */
function todoClearDoneAction()
{
    $uid = todo_current_uid_or_fail();
    if (!$uid) return;
    todo_clear_done($uid, (int) ($_POST['list_id'] ?? 0));
    echo json_encode(['ok' => true, 'pending' => todo_pending_count($uid)], JSON_UNESCAPED_UNICODE);
}

/** Xóa tất cả task trong 1 danh sách (POST list_id). */
function todoClearAllAction()
{
    $uid = todo_current_uid_or_fail();
    if (!$uid) return;
    todo_clear_all($uid, (int) ($_POST['list_id'] ?? 0));
    echo json_encode(['ok' => true, 'pending' => todo_pending_count($uid)], JSON_UNESCAPED_UNICODE);
}

/** Sắp xếp lại task trong 1 danh sách (POST list_id, ids[]). */
function todoReorderAction()
{
    $uid = todo_current_uid_or_fail();
    if (!$uid) return;
    $ids = $_POST['ids'] ?? [];
    if (is_string($ids)) $ids = explode(',', $ids);
    todo_reorder($uid, (int) ($_POST['list_id'] ?? 0), $ids);
    echo json_encode(['ok' => true], JSON_UNESCAPED_UNICODE);
}

/** Lưu chế độ tự xóa (POST mode) — per-user, dùng chung mọi danh sách. */
function todoSettingsAction()
{
    $uid = todo_current_uid_or_fail();
    if (!$uid) return;
    todo_set_clear_mode($uid, (string) ($_POST['mode'] ?? 'manual'));
    echo json_encode(['ok' => true, 'clear_mode' => todo_get_clear_mode($uid)], JSON_UNESCAPED_UNICODE);
}

/* ============================================================
 *  Lịch (calendar) — AJAX cho app-header (cạnh trái todo)
 *  Cá nhân hóa hoàn toàn (không chia sẻ) — xem libraries/evcal.php.
 * ============================================================ */

/** Trả về user đang đăng nhập hoặc xuất JSON lỗi + 0. */
function evcal_current_uid_or_fail()
{
    header('Content-Type: application/json; charset=utf-8');
    $user = permission_current_user();
    if (!$user) { echo json_encode(['ok' => false, 'message' => 'Chưa đăng nhập.']); return 0; }
    return (int) $user['id'];
}

/** Sự kiện trong 1 khoảng ngày (GET/POST from, to) — cho lưới tháng + view phóng to. */
function evcalRangeAction()
{
    $uid = evcal_current_uid_or_fail();
    if (!$uid) return;
    $from = (string) ($_GET['from'] ?? $_POST['from'] ?? '');
    $to   = (string) ($_GET['to']   ?? $_POST['to']   ?? '');
    echo json_encode(['ok' => true, 'data' => evcal_range_events($uid, $from, $to)], JSON_UNESCAPED_UNICODE);
}

/** Sự kiện của đúng 1 ngày (GET/POST date). */
function evcalDayAction()
{
    $uid = evcal_current_uid_or_fail();
    if (!$uid) return;
    $date = (string) ($_GET['date'] ?? $_POST['date'] ?? '');
    echo json_encode(['ok' => true, 'data' => evcal_day_events($uid, $date)], JSON_UNESCAPED_UNICODE);
}

/** Thêm sự kiện/lời nhắc (POST date, time, content). */
function evcalCreateAction()
{
    $uid = evcal_current_uid_or_fail();
    if (!$uid) return;
    $item = evcal_create($uid, $_POST['date'] ?? '', $_POST['time'] ?? '', $_POST['content'] ?? '');
    if (!$item) { echo json_encode(['ok' => false, 'message' => 'Không thêm được sự kiện.']); return; }
    echo json_encode(['ok' => true, 'item' => $item, 'today' => evcal_today_count($uid)], JSON_UNESCAPED_UNICODE);
}

/** Sửa nội dung và/hoặc giờ nhắc (POST id, content?, time?). */
function evcalUpdateAction()
{
    $uid = evcal_current_uid_or_fail();
    if (!$uid) return;
    $content = array_key_exists('content', $_POST) ? $_POST['content'] : null;
    $time    = array_key_exists('time', $_POST) ? $_POST['time'] : null;
    $ok = evcal_update($uid, (int) ($_POST['id'] ?? 0), $content, $time);
    echo json_encode(['ok' => $ok, 'today' => evcal_today_count($uid)], JSON_UNESCAPED_UNICODE);
}

/** Kéo-thả đổi ngày (view phóng to) (POST id, date). */
function evcalMoveAction()
{
    $uid = evcal_current_uid_or_fail();
    if (!$uid) return;
    $ok = evcal_move($uid, (int) ($_POST['id'] ?? 0), (string) ($_POST['date'] ?? ''));
    echo json_encode(['ok' => $ok, 'today' => evcal_today_count($uid)], JSON_UNESCAPED_UNICODE);
}

/** Xóa 1 sự kiện (POST id). */
function evcalDeleteAction()
{
    $uid = evcal_current_uid_or_fail();
    if (!$uid) return;
    evcal_delete($uid, (int) ($_POST['id'] ?? 0));
    echo json_encode(['ok' => true, 'today' => evcal_today_count($uid)], JSON_UNESCAPED_UNICODE);
}

/**
 * "Nhắc lại" từ chuông (POST evcal_id, notif_id, days HOẶC minutes): dịch lời nhắc lịch
 * sang ngày hiện tại + days (giữ giờ gốc) hoặc NOW() + minutes (đổi cả giờ, dùng cho mốc
 * ngắn 15 phút/1h/4h), rồi đánh dấu đã đọc thông báo gốc trên chuông.
 */
function evcalSnoozeAction()
{
    $uid = evcal_current_uid_or_fail();
    if (!$uid) return;
    $evcalId = (int) ($_POST['evcal_id'] ?? 0);
    $notifId = (int) ($_POST['notif_id'] ?? 0);
    $minutes = (int) ($_POST['minutes'] ?? 0);
    $days    = (int) ($_POST['days'] ?? 0);
    $ok = $minutes > 0 ? evcal_snooze_minutes($uid, $evcalId, $minutes) : evcal_snooze($uid, $evcalId, $days);
    if ($ok && $notifId > 0) {
        notify_mark_read($notifId, $uid);
    }
    echo json_encode(['ok' => $ok, 'unread' => notify_unread_count($uid), 'today' => evcal_today_count($uid)], JSON_UNESCAPED_UNICODE);
}

/** Quét lời nhắc tới giờ → đẩy chuông (POST, gọi định kỳ độc lập với dropdown). */
function evcalCheckAction()
{
    $uid = evcal_current_uid_or_fail();
    if (!$uid) return;
    $fired = evcal_check_due_reminders($uid);
    echo json_encode(['ok' => true, 'fired' => $fired], JSON_UNESCAPED_UNICODE);
}

/** Đánh dấu đã thực hiện / bỏ đánh dấu (POST id, done='1'|'0'). */
function evcalSetDoneAction()
{
    $uid = evcal_current_uid_or_fail();
    if (!$uid) return;
    $done = (string) ($_POST['done'] ?? '') === '1';
    $ok = evcal_set_done($uid, (int) ($_POST['id'] ?? 0), $done);
    echo json_encode(['ok' => $ok, 'today' => evcal_today_count($uid)], JSON_UNESCAPED_UNICODE);
}

/** Lấy cấu hình lặp hiện có của 1 sự kiện (GET/POST id) — tiền điền modal khi sửa lại. */
function evcalRecurGetAction()
{
    $uid = evcal_current_uid_or_fail();
    if (!$uid) return;
    $rule = evcal_get_recurrence($uid, (int) ($_GET['id'] ?? $_POST['id'] ?? 0));
    echo json_encode(['ok' => true, 'rule' => $rule], JSON_UNESCAPED_UNICODE);
}

/**
 * Thiết lập / xóa lặp lại cho 1 sự kiện (view phóng to).
 * POST: id, clear=1 (xóa lặp) HOẶC freq + interval + weekdays (csv, "hằng tuần")
 * + month_day ("hằng tháng") + year_month/year_day ("hằng năm").
 */
function evcalRecurAction()
{
    $uid = evcal_current_uid_or_fail();
    if (!$uid) return;
    $id = (int) ($_POST['id'] ?? 0);

    if (!empty($_POST['clear'])) {
        $res = evcal_set_recurrence($uid, $id, null);
        echo json_encode(['ok' => $res !== false, 'created' => 0], JSON_UNESCAPED_UNICODE);
        return;
    }

    $weekdays = [];
    if (!empty($_POST['weekdays'])) {
        $weekdays = array_map('intval', explode(',', (string) $_POST['weekdays']));
    }
    $rule = [
        'freq'       => (string) ($_POST['freq'] ?? ''),
        'interval'   => (int) ($_POST['interval'] ?? 1),
        'weekdays'   => $weekdays,
        'month_day'  => (int) ($_POST['month_day'] ?? 0),
        'year_month' => (int) ($_POST['year_month'] ?? 0),
        'year_day'   => (int) ($_POST['year_day'] ?? 0),
    ];
    $res = evcal_set_recurrence($uid, $id, $rule);
    if ($res === false) {
        echo json_encode(['ok' => false, 'message' => 'Thiết lập lặp lại không hợp lệ.']);
        return;
    }
    echo json_encode(['ok' => true, 'created' => $res['created'], 'today' => evcal_today_count($uid)], JSON_UNESCAPED_UNICODE);
}

/* ============================================================
 *  Header trang chủ — thứ tự menu cha + cài đặt hình nền
 *  (xem libraries/home_layout.php)
 * ============================================================ */

/** Trả về user đang đăng nhập hoặc xuất JSON lỗi + null. */
function home_layout_current_user_or_fail()
{
    header('Content-Type: application/json; charset=utf-8');
    $user = permission_current_user();
    if (!$user) { echo json_encode(['ok' => false, 'message' => 'Chưa đăng nhập.']); return null; }
    return $user;
}

/** Lưu thứ tự menu cha đã kéo-thả (POST order[] = danh sách group_key). */
function menuOrderSaveAction()
{
    $user = home_layout_current_user_or_fail();
    if (!$user) return;
    $uid = (int) $user['id'];

    $menu_groups = permission_menu_for_user($user);
    if (function_exists('permission_filter_menu_groups')) {
        $menu_groups = permission_filter_menu_groups($menu_groups);
    }
    $allowed_keys = array_keys($menu_groups);
    if (permission_is_admin($user) || permission_is_deputy($user)) {
        $allowed_keys[] = 'QUẢN TRỊ HỆ THỐNG';
    }

    $order = $_POST['order'] ?? [];
    if (is_string($order)) $order = explode(',', $order);
    $bar_count = (int) ($_POST['bar_count'] ?? HOME_NAV_MAX_BAR);
    home_menu_order_save($uid, is_array($order) ? $order : [], $allowed_keys, $bar_count);
    echo json_encode(['ok' => true], JSON_UNESCAPED_UNICODE);
}

/** Dữ liệu cho modal "Cài đặt hình nền" (GET): thư viện dùng chung + slide của tôi. */
function bgSettingsAction()
{
    $user = home_layout_current_user_or_fail();
    if (!$user) return;
    $uid = (int) $user['id'];

    $library = array_map(function ($r) {
        return [
            'id'  => (int) $r['id'],
            'url' => 'public/images/home_bg_library/' . rawurlencode((string) $r['filename']),
        ];
    }, home_bg_library_list());

    echo json_encode([
        'ok'       => true,
        'is_admin' => permission_is_admin($user),
        'library'  => $library,
        'slides'   => home_bg_user_slides($uid),
        'max'      => HOME_BG_MAX_SLIDES,
    ], JSON_UNESCAPED_UNICODE);
}

/** Thêm 1 ảnh vào thư viện dùng chung (admin only, POST multipart file). */
function bgLibraryAddAction()
{
    $user = home_layout_current_user_or_fail();
    if (!$user) return;
    if (!permission_is_admin($user)) {
        echo json_encode(['ok' => false, 'message' => 'Chỉ quản trị viên mới thêm được vào thư viện.']); return;
    }
    $res = home_bg_library_add($_FILES['file'] ?? null, (int) $user['id']);
    if (empty($res['ok'])) { echo json_encode($res, JSON_UNESCAPED_UNICODE); return; }
    $library = array_map(function ($r) {
        return ['id' => (int) $r['id'], 'url' => 'public/images/home_bg_library/' . rawurlencode((string) $r['filename'])];
    }, home_bg_library_list());
    echo json_encode(['ok' => true, 'library' => $library], JSON_UNESCAPED_UNICODE);
}

/** Xóa 1 ảnh khỏi thư viện dùng chung (admin only, POST id). Cascade xóa slide đang dùng nó. */
function bgLibraryRemoveAction()
{
    $user = home_layout_current_user_or_fail();
    if (!$user) return;
    if (!permission_is_admin($user)) {
        echo json_encode(['ok' => false, 'message' => 'Chỉ quản trị viên mới xóa được khỏi thư viện.']); return;
    }
    home_bg_library_remove((int) ($_POST['id'] ?? 0));
    $library = array_map(function ($r) {
        return ['id' => (int) $r['id'], 'url' => 'public/images/home_bg_library/' . rawurlencode((string) $r['filename'])];
    }, home_bg_library_list());
    echo json_encode(['ok' => true, 'library' => $library, 'slides' => home_bg_user_slides((int) $user['id'])], JSON_UNESCAPED_UNICODE);
}

/** Thêm 1 slide cá nhân từ ảnh thư viện dùng chung (POST library_id). */
function bgSlideAddAction()
{
    $user = home_layout_current_user_or_fail();
    if (!$user) return;
    $res = home_bg_slide_add_from_library((int) $user['id'], (int) ($_POST['library_id'] ?? 0));
    echo json_encode($res, JSON_UNESCAPED_UNICODE);
}

/** Tải ảnh cá nhân từ máy tính, thêm làm slide (POST multipart file). */
function bgSlideUploadAction()
{
    $user = home_layout_current_user_or_fail();
    if (!$user) return;
    $res = home_bg_slide_upload((int) $user['id'], $_FILES['file'] ?? null);
    echo json_encode($res, JSON_UNESCAPED_UNICODE);
}

/** Xóa 1 slide cá nhân (POST slide_id). */
function bgSlideRemoveAction()
{
    $user = home_layout_current_user_or_fail();
    if (!$user) return;
    $res = home_bg_slide_remove((int) $user['id'], (int) ($_POST['slide_id'] ?? 0));
    echo json_encode($res, JSON_UNESCAPED_UNICODE);
}

/** Kéo-thả đổi thứ tự slideshow (POST ids[] theo thứ tự mới). */
function bgSlideReorderAction()
{
    $user = home_layout_current_user_or_fail();
    if (!$user) return;
    $ids = $_POST['ids'] ?? [];
    if (is_string($ids)) $ids = explode(',', $ids);
    $res = home_bg_slide_reorder((int) $user['id'], is_array($ids) ? $ids : []);
    echo json_encode($res, JSON_UNESCAPED_UNICODE);
}