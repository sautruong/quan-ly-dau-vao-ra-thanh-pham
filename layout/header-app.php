<?php
/* =====================================================================
 *  HEADER TRÊN DÙNG CHUNG (app shell)  —  gọi bằng get_header('app')
 *  ---------------------------------------------------------------------
 *  - Trái: tiêu đề view (h1 nhỏ), lấy label từ tbl_views.
 *  - Phải: cụm icon dùng chung, xem layout/header-widgets/*.php
 *          (cal, remind, todo, bell, user + các modal liên quan).
 *  - #app-header-search: slot để JS đẩy ô tìm kiếm của view lên header.
 *  Có thể đặt $page_title trước khi gọi để ép tiêu đề.
 * ===================================================================== */

// Chỉ render 1 lần / request.
if (!empty($GLOBALS['__app_header_rendered'])) { return; }
$GLOBALS['__app_header_rendered'] = true;

require_once __DIR__ . '/header-widgets/_bootstrap.php';

$__hd_mod = function_exists('get_module')     ? get_module()     : ($_GET['mod'] ?? '');
$__hd_ctl = function_exists('get_controller') ? get_controller() : ($_GET['controllers'] ?? '');
$__hd_act = function_exists('get_action')     ? get_action()     : ($_GET['action'] ?? '');

// Tiêu đề: ưu tiên $page_title; nếu không, lấy label view từ tbl_views.
if (!isset($page_title) || $page_title === '') {
    $page_title = '';
    if (function_exists('permission_find_view')) {
        $__v = permission_find_view($__hd_mod, $__hd_ctl, $__hd_act);
        if ($__v && !empty($__v['label'])) {
            $page_title = $__v['label'];
        }
    }
}
?>
<?php if ($__hd_mod === 'report' && $__hd_ctl === 'report' && $__hd_act === 'daily_dashboard'): ?>
<!-- Header riêng cho daily_dashboard: logo/brand trái, tìm kiếm+tiện ích giữa, chuông+chat phải, avatar ngoài cùng.
     Không đổi behavior của header-widgets/*.php, chỉ đổi cách gom nhóm — xem [[daily-dashboard-report]]. -->
<header class="app-header dd2-app-header">
    <div class="dd2-hdr-brand">
        <!-- Chỉ bọc LOGO bằng link về trang chủ (không bọc cả cụm): chữ bên cạnh là contenteditable
             sửa-tại-chỗ, bọc chung sẽ biến mỗi lần bấm sửa tên thành một cú điều hướng. -->
        <a href="<?php echo function_exists('nav_url') ? nav_url('home', 'index', 'index') : '?mod=home&controllers=index&action=index'; ?>" class="dd2-hdr-logo-link" title="Về trang chủ">
            <img src="public/images/logo/logo_vat_png.png" class="dd2-hdr-logo" alt="logo">
        </a>
        <span class="dd2-hdr-brand-text" id="dd2-hdr-brand-text" data-setting="daily_dashboard.logo_caption"><?php echo htmlspecialchars(function_exists('rp_dd_get_logo_caption') ? rp_dd_get_logo_caption() : 'VUA AN TOÀN', ENT_QUOTES, 'UTF-8'); ?></span>
        <i class="fa-solid fa-pen dd2-hdr-brand-edit" id="dd2-hdr-brand-edit" title="Sửa tên"></i>
    </div>
    <div class="dd2-hdr-middle">
        <div class="app-header-search" id="app-header-search"></div>
        <?php require __DIR__ . '/header-widgets/view-theme.php'; ?>
        <?php if ($__hd_cal_visible): ?>
            <?php require __DIR__ . '/header-widgets/cal.php'; ?>
        <?php endif; ?>
        <?php if ($__hd_remind_visible): ?>
            <?php require __DIR__ . '/header-widgets/remind.php'; ?>
        <?php endif; ?>
        <?php if ($__hd_todo_visible): ?>
            <?php require __DIR__ . '/header-widgets/todo.php'; ?>
        <?php endif; ?>
    </div>
    <div class="dd2-hdr-actions app-header-right">
        <?php require __DIR__ . '/header-widgets/bell.php'; ?>
    </div>
    <div class="dd2-hdr-avatar">
        <?php require __DIR__ . '/header-widgets/user.php'; ?>
    </div>
</header>
<?php else: ?>
<header class="app-header">
    <div class="app-header-left">
        <button type="button" class="app-header-burger" id="app-header-burger" aria-label="Mở menu">
            <i class="fa-solid fa-bars"></i>
        </button>
        <h1 class="app-header-title"><?php echo htmlspecialchars((string) $page_title, ENT_QUOTES, 'UTF-8'); ?></h1>
    </div>

    <div class="app-header-right">
        <div class="app-header-search" id="app-header-search"></div>
        <?php require __DIR__ . '/header-widgets/view-theme.php'; ?>
        <?php if ($__hd_cal_visible): ?>
            <?php require __DIR__ . '/header-widgets/cal.php'; ?>
        <?php endif; ?>
        <?php if ($__hd_remind_visible): ?>
            <?php require __DIR__ . '/header-widgets/remind.php'; ?>
        <?php endif; ?>
        <?php if ($__hd_todo_visible): ?>
            <?php require __DIR__ . '/header-widgets/todo.php'; ?>
        <?php endif; ?>

        <!-- MOBILE: gom Lịch / Điểm nhắc / Việc cần làm / Sáng-Tối vào 1 nút (cạnh chuông).
             Ẩn trên desktop (CSS). Mỗi mục bấm sẽ mở đúng công cụ tương ứng dạng modal
             canh giữa màn hình — xem app_shell.js (wireHeaderTools) + app_shell.css. -->
        <div class="app-header-tools" id="app-header-tools">
            <button type="button" class="app-tools-btn" id="app-tools-btn" aria-label="Tiện ích">
                <i class="fa-solid fa-grip"></i>
            </button>
            <div class="app-tools-menu" id="app-tools-menu">
                <?php if ($__hd_cal_visible): ?>
                    <button type="button" class="app-tools-item" data-tools-target="app-cal-btn">
                        <i class="fa-solid fa-calendar-days"></i> Lịch
                    </button>
                <?php endif; ?>
                <?php if ($__hd_remind_visible): ?>
                    <button type="button" class="app-tools-item" data-tools-target="app-remind-btn">
                        <i class="fa-solid fa-thumbtack"></i> Điểm nhắc
                    </button>
                <?php endif; ?>
                <?php if ($__hd_todo_visible): ?>
                    <button type="button" class="app-tools-item" data-tools-target="app-todo-btn">
                        <i class="fa-solid fa-list-check"></i> Việc cần làm
                    </button>
                <?php endif; ?>
                <button type="button" class="app-tools-item" data-tools-target="app-view-theme-btn">
                    <i class="fa-solid fa-circle-half-stroke"></i> Sáng / Tối
                </button>
            </div>
        </div>

        <?php require __DIR__ . '/header-widgets/bell.php'; ?>
        <?php require __DIR__ . '/header-widgets/user.php'; ?>
    </div>
</header>
<?php endif; ?>

<?php
/* Thanh tab phụ cho cụm "Kế hoạch sản xuất" (2 view dùng chung) — đặt ngay dưới
 * header để chuyển qua lại. Chỉ hiện khi đang ở 1 trong 2 view này.
 * View 'production_plan' đã gỡ (2026-07) — mọi logic dồn qua 'plan_for_staff'. */
$__plan_tabs = [
    ['action' => 'plan_for_staff',            'label' => 'Kế hoạch sản xuất hằng ngày'],
    ['action' => 'long_term_production_plan', 'label' => 'Kế hoạch sản xuất dài ngày'],
];
if ($__hd_mod === 'production_staff' && $__hd_ctl === 'production_staff'
    && in_array($__hd_act, array_column($__plan_tabs, 'action'), true)):
?>
<nav class="app-subtabs" aria-label="Chuyển trang kế hoạch sản xuất">
    <?php foreach ($__plan_tabs as $__t):
        $__u = '?mod=production_staff&controllers=production_staff&action=' . $__t['action'];
        $__is = ($__hd_act === $__t['action']);
    ?>
        <a class="app-subtab<?php echo $__is ? ' is-active' : ''; ?>"
           href="<?php echo htmlspecialchars($__u, ENT_QUOTES, 'UTF-8'); ?>">
            <?php echo htmlspecialchars($__t['label'], ENT_QUOTES, 'UTF-8'); ?>
        </a>
    <?php endforeach; ?>
</nav>
<?php endif; ?>
