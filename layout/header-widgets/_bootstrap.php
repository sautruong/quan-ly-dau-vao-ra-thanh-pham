<?php
/* =====================================================================
 *  BOOTSTRAP CHO CỤM ICON HEADER DÙNG CHUNG
 *  ---------------------------------------------------------------------
 *  Tính sẵn các biến $__hd_* + hàm app_header_avatar_html() dùng bởi
 *  cal.php / remind.php / todo.php / bell.php / user.php. Được require
 *  bởi CẢ header-app.php (trang chi tiết) LẪN header-home.php (trang chủ)
 *  trước khi render cụm icon, để 2 nơi có thể sắp xếp thứ tự widget khác
 *  nhau mà không lặp code.
 * ===================================================================== */

if (!empty($GLOBALS['__app_header_widgets_bootstrap_rendered'])) { return; }
$GLOBALS['__app_header_widgets_bootstrap_rendered'] = true;

require_once __DIR__ . '/../../libraries/notifications.php';
require_once __DIR__ . '/../../libraries/todos.php';
require_once __DIR__ . '/../../libraries/evcal.php';
require_once __DIR__ . '/../../libraries/reminder_points.php';

$__hd_user = function_exists('permission_current_user') ? permission_current_user() : null;

// Lịch / Việc cần làm / Điểm nhắc: chỉ hiện cho admin hoặc user được tick quyền trực tiếp
// (nhóm "TIỆN ÍCH HEADER" ở màn Phân quyền người dùng) — avatar/chuông/sáng-tối luôn cố định.
$__hd_cal_visible    = permission_header_widget_visible($__hd_user, 'calendar');
$__hd_todo_visible   = permission_header_widget_visible($__hd_user, 'todo');
$__hd_remind_visible = reminder_points_visible_for($__hd_user);

// Số thông báo chưa đọc của người dùng hiện tại (cho badge chuông).
$__hd_unread = ($__hd_user && function_exists('notify_unread_count'))
    ? notify_unread_count((int) ($__hd_user['id'] ?? 0)) : 0;

// Số task chưa hoàn thành (cho badge todo-list cạnh chuông).
$__hd_todo_pending = ($__hd_user && function_exists('todo_pending_count'))
    ? todo_pending_count((int) ($__hd_user['id'] ?? 0)) : 0;

// Số sự kiện/lời nhắc hôm nay (cho badge nút lịch, cạnh trái todo).
$__hd_cal_today = ($__hd_user && function_exists('evcal_today_count'))
    ? evcal_today_count((int) ($__hd_user['id'] ?? 0)) : 0;

$__fullname = trim((string) ($__hd_user['fullname'] ?? '')) ?: 'Người dùng';
$__username = (string) ($__hd_user['username'] ?? '');
$__email    = (string) ($__hd_user['email'] ?? '');
$__role     = (string) ($__hd_user['role'] ?? 'user');
$__avatar   = trim((string) ($__hd_user['avatar'] ?? ''));
$__initial  = mb_strtoupper(mb_substr($__fullname, 0, 1, 'UTF-8'), 'UTF-8');

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
