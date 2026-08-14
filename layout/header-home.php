<?php
/* =====================================================================
 *  HEADER TRANG CHỦ  —  require trực tiếp từ modules/home/views/index.php
 *  ---------------------------------------------------------------------
 *  - Trái : logo trong 2 vòng tròn + tên hãng 2 dòng.
 *  - Giữa : menu cha ngang (kéo-thả đổi vị trí, giới hạn 8 + ">>" kiểu
 *           thanh dấu trang Chrome, hover mở dropdown menu con)
 *           — public/js/home/header_home.js.
 *  - Phải : tìm menu, lịch, todo, chuông, điểm nhắc (nếu có quyền),
 *           sáng/tối chữ menu, cài đặt hình nền, avatar ngoài cùng
 *           — tái dùng layout/header-widgets/*. Header nền trong suốt.
 *  Cần biến $menu_groups (đã áp thứ tự đã lưu) truyền từ indexController.
 * ===================================================================== */

if (!empty($GLOBALS['__home_header_rendered'])) { return; }
$GLOBALS['__home_header_rendered'] = true;

require_once __DIR__ . '/header-widgets/_bootstrap.php';

/** Chữ in thường, viết hoa chữ cái đầu câu (mirror sidebar-app.php::app_sentence_case). */
if (!function_exists('home_nav_sentence_case')) {
    function home_nav_sentence_case($s)
    {
        $s = trim((string) $s);
        if ($s === '') return $s;
        static $exact = [
            'BIỂU MẪU QL VSATTP' => 'Biểu mẫu QL VSATTP',
        ];
        if (isset($exact[$s])) return $exact[$s];
        return mb_strtoupper(mb_substr($s, 0, 1, 'UTF-8'), 'UTF-8')
            . mb_strtolower(mb_substr($s, 1, null, 'UTF-8'), 'UTF-8');
    }
}

if (!function_exists('home_nav_view_url')) {
    function home_nav_view_url($v)
    {
        return '?mod=' . urlencode((string) ($v['module'] ?? ''))
            . '&controllers=' . urlencode((string) ($v['controller'] ?? ''))
            . '&action=' . urlencode((string) ($v['action'] ?? ''));
    }
}

$__hn_groups   = isset($menu_groups) && is_array($menu_groups) ? $menu_groups : [];
$__hn_bar_keys = isset($nav_bar_keys) && is_array($nav_bar_keys) ? $nav_bar_keys : [];

/** In ra 1 item menu cha (dùng chung cho hàng chính lẫn danh sách ẩn trong ">>"). */
if (!function_exists('home_nav_render_item')) {
    function home_nav_render_item($gkey, $items)
    {
        $gdisplay  = function_exists('permission_group_display_label') ? permission_group_display_label($gkey) : $gkey;
        $glabel    = htmlspecialchars(home_nav_sentence_case($gdisplay), ENT_QUOTES, 'UTF-8');
        $gkey_esc  = htmlspecialchars((string) $gkey, ENT_QUOTES, 'UTF-8');
        ?>
        <div class="home-nav-item" draggable="true" data-key="<?php echo $gkey_esc; ?>">
            <button type="button" class="home-nav-btn">
                <span><?php echo $glabel; ?></span>
            </button>
            <button type="button" class="home-nav-pin" title="Thêm vào thanh dấu trang" aria-label="Thêm vào thanh dấu trang">
                <i class="fa-solid fa-thumbtack"></i>
            </button>
            <div class="home-nav-drop">
                <?php /* Tiêu đề chỉ hiện khi menu con bung dạng modal trên mobile (CSS ẩn ở desktop) —
                         mở modal che hết màn hình thì phải nhắc lại đang ở nhóm nào. */ ?>
                <div class="home-nav-drop-title">
                    <span><?php echo $glabel; ?></span>
                    <button type="button" class="home-nav-drop-close" aria-label="Đóng">&times;</button>
                </div>
                <?php foreach ($items as $v):
                    $label = htmlspecialchars((string) ($v['label'] ?? ''), ENT_QUOTES, 'UTF-8');
                    $url   = htmlspecialchars(home_nav_view_url($v), ENT_QUOTES, 'UTF-8');
                ?>
                    <a href="<?php echo $url; ?>" data-group="<?php echo $glabel; ?>"><?php echo $label; ?></a>
                <?php endforeach; ?>
            </div>
        </div>
        <?php
    }
}

$__hn_overflow_count = 0;
foreach ($__hn_groups as $gkey => $items) {
    if (!empty($items) && empty($__hn_bar_keys[$gkey])) { $__hn_overflow_count++; }
}
?>
<header class="home-header" id="home-header">
    <div class="home-header-left">
        <a href="?mod=home&controllers=index&action=index" class="home-logo-ring" title="Về trang chủ">
            <span class="home-logo-ring-inner">
                <img src="public/images/logo/logo_vat_png.png" alt="VUA AN TOÀN">
            </span>
        </a>
        <div class="home-brand-text">
            <span class="home-brand-line1">VUA AN TOÀN</span>
            <span class="home-brand-line2">SAFEKING CO,.LTD</span>
        </div>
    </div>

    <nav class="home-nav-wrap" id="home-nav-wrap" aria-label="Menu chính">
        <div class="home-nav" id="home-nav">
            <?php foreach ($__hn_groups as $gkey => $items):
                if (empty($items) || empty($__hn_bar_keys[$gkey])) continue;
                home_nav_render_item($gkey, $items);
            endforeach; ?>
        </div>
        <div class="home-nav-item home-nav-more" id="home-nav-more" <?php echo $__hn_overflow_count > 0 ? '' : 'style="display:none;"'; ?>>
            <button type="button" class="home-nav-btn home-nav-more-btn" id="home-nav-more-btn">
                <i class="fa-solid fa-angles-right"></i>
            </button>
            <div class="home-nav-drop home-nav-more-list" id="home-nav-more-list">
                <?php foreach ($__hn_groups as $gkey => $items):
                    if (empty($items) || !empty($__hn_bar_keys[$gkey])) continue;
                    home_nav_render_item($gkey, $items);
                endforeach; ?>
            </div>
        </div>
    </nav>

    <div class="app-header-right home-header-right" id="home-header-right">
        <?php require __DIR__ . '/header-widgets/search-menu.php'; ?>
        <?php if ($__hd_cal_visible): ?>
            <?php require __DIR__ . '/header-widgets/cal.php'; ?>
        <?php endif; ?>
        <?php if ($__hd_todo_visible): ?>
            <?php require __DIR__ . '/header-widgets/todo.php'; ?>
        <?php endif; ?>

        <?php /* MOBILE: gom mọi icon tiện ích của header vào 1 nút, đặt ngay cạnh TRÁI chuông.
                 Cố ý dùng lại đúng id/class .app-header-tools/.app-tools-* của header-app.php
                 để app_shell.js (mục 3b) wire sẵn + app_shell.css lo phần ẩn/hiện theo breakpoint
                 — không phải viết JS riêng cho trang chủ. Mỗi mục chỉ .click() vào nút thật đang ẩn. */ ?>
        <div class="app-header-tools" id="app-header-tools">
            <button type="button" class="app-tools-btn" id="app-tools-btn" aria-label="Tiện ích">
                <i class="fa-solid fa-grip"></i>
            </button>
            <div class="app-tools-menu" id="app-tools-menu">
                <button type="button" class="app-tools-item" data-tools-target="home-search-btn">
                    <i class="fa-solid fa-magnifying-glass"></i> Tìm menu
                </button>
                <?php if ($__hd_cal_visible): ?>
                    <button type="button" class="app-tools-item" data-tools-target="app-cal-btn">
                        <i class="fa-solid fa-calendar-days"></i> Lịch
                    </button>
                <?php endif; ?>
                <?php if ($__hd_todo_visible): ?>
                    <button type="button" class="app-tools-item" data-tools-target="app-todo-btn">
                        <i class="fa-solid fa-list-check"></i> Việc cần làm
                    </button>
                <?php endif; ?>
                <?php if ($__hd_remind_visible): ?>
                    <button type="button" class="app-tools-item" data-tools-target="app-remind-btn">
                        <i class="fa-solid fa-thumbtack"></i> Điểm nhắc
                    </button>
                <?php endif; ?>
                <button type="button" class="app-tools-item" data-tools-target="home-text-theme-btn">
                    <i class="fa-solid fa-circle-half-stroke"></i> Sáng / Tối chữ
                </button>
                <button type="button" class="app-tools-item" data-tools-target="home-bgset-btn">
                    <i class="fa-solid fa-image"></i> Cài đặt hình nền
                </button>
            </div>
        </div>

        <?php require __DIR__ . '/header-widgets/bell.php'; ?>
        <?php if ($__hd_remind_visible): ?>
            <?php require __DIR__ . '/header-widgets/remind.php'; ?>
        <?php endif; ?>
        <?php require __DIR__ . '/header-widgets/text-theme.php'; ?>
        <?php require __DIR__ . '/header-widgets/bg-settings.php'; ?>
        <?php require __DIR__ . '/header-widgets/user.php'; ?>
    </div>
</header>

<?php
// Cache-busting theo mtime file: header trang chủ đang chỉnh sửa liên tục trong ngày, tránh
// trình duyệt của user khác giữ CSS/JS cũ (style "chưa đồng bộ" dù server đã cập nhật).
$__hn_css = __DIR__ . '/../public/css/home/header_home.css';
$__hn_js  = __DIR__ . '/../public/js/home/header_home.js';
$__hn_css_v = is_file($__hn_css) ? filemtime($__hn_css) : time();
$__hn_js_v  = is_file($__hn_js)  ? filemtime($__hn_js)  : time();
?>
<link rel="stylesheet" href="<?php echo asset_ver('public/css/shared/app_shell.css'); ?>">
<link rel="stylesheet" href="public/css/home/header_home.css?v=<?php echo $__hn_css_v; ?>">
<script>
(function () {
    try {
        /* Tính năng "Giao diện sidebar" đã gỡ (2026-08) — dọn nốt key cũ để trang có
           sidebar không bị inline style của phiên trước đè lên bảng màu mới. */
        ['app_sb_bg_color', 'app_sb_text_mode', 'app_sb_theme'].forEach(function (k) { localStorage.removeItem(k); });
        var header = document.getElementById('home-header');
        if (header && localStorage.getItem('home_header_text_mode') === 'light') {
            header.classList.add('is-light-text');
        }
    } catch (e) {}
})();
</script>
<!-- app_shell.js wire các dropdown tái dùng (lịch/todo/chuông/điểm nhắc/avatar) — mọi view chi
     tiết đều tự link file này (xem modules/*/views/*.php); trang chủ dùng lại đúng markup nên
     cũng cần link ở đây, nếu không các nút này sẽ không có JS xử lý click/hover. -->
<script src="<?php echo asset_ver('public/js/shared/app_shell.js'); ?>"></script>
<script src="public/js/home/header_home.js?v=<?php echo $__hn_js_v; ?>"></script>
