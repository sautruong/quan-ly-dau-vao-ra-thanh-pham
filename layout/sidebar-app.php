<?php
/* =====================================================================
 *  SIDEBAR TRÁI DÙNG CHUNG (app shell)  —  gọi bằng get_sidebar('app')
 *  ---------------------------------------------------------------------
 *  - Tự lấy user đang đăng nhập + menu (permission_menu_for_user).
 *  - Menu cha = group_label (icon + text) -> click xổ menu con.
 *  - Menu con = từng view (icon + text), active theo view hiện tại.
 *  - Nút thu gọn: chỉ còn icon.
 *  Self-contained: không phụ thuộc biến truyền từ controller.
 * ===================================================================== */

// Chỉ render 1 lần / request (an toàn khi include nhiều partial).
if (!empty($GLOBALS['__app_sidebar_rendered'])) { return; }
$GLOBALS['__app_sidebar_rendered'] = true;

$__sb_user   = function_exists('permission_current_user') ? permission_current_user() : null;
$__sb_groups = ($__sb_user && function_exists('permission_menu_for_user'))
    ? permission_menu_for_user($__sb_user) : [];
$__sb_admin  = function_exists('permission_is_admin') ? permission_is_admin($__sb_user) : false;

$__cur_mod = function_exists('get_module')     ? get_module()     : ($_GET['mod'] ?? '');
$__cur_ctl = function_exists('get_controller') ? get_controller() : ($_GET['controllers'] ?? '');
$__cur_act = function_exists('get_action')     ? get_action()     : ($_GET['action'] ?? '');

/* Hàm helper: chỉ khai báo 1 lần (sidebar-app.php có thể được require 2 lần
 * ở họ view hr/product_profile -> tránh "Cannot redeclare"). */
if (!function_exists('app_sidebar_group_icon')):

/** Icon cho từng nhóm menu cha (theo group_label). */
function app_sidebar_group_icon($label)
{
    static $map = [
        'NHẬP KHO'                => 'fa-truck-ramp-box',
        'NHẬP NGUYÊN VẬT LIỆU'    => 'fa-boxes-stacked',
        'XUẤT KHO'                => 'fa-truck-fast',
        'SẢN XUẤT'                => 'fa-industry',
        'THU CHI'                 => 'fa-money-bill-trend-up',
        'CÔNG NỢ'                 => 'fa-file-invoice-dollar',
        'BÁO CÁO'                 => 'fa-chart-column',
        'TỒN KHO'                 => 'fa-boxes-packing',
        'BÁN HÀNG - NHÀ MÁY'      => 'fa-cart-shopping',
        'KHO SẢN PHẨM'            => 'fa-warehouse',
        'NHÂN SỰ'                 => 'fa-users',
        'HỒ SƠ SẢN PHẨM'          => 'fa-folder-open',
        'ADMIN - NHÀ MÁY'         => 'fa-screwdriver-wrench',
        'BIỂU MẪU QL VSATTP'      => 'fa-clipboard-list',
        'KẾ TOÁN - NHÀ MÁY'       => 'fa-calculator',
        'QUẢN TRỊ HỆ THỐNG'       => 'fa-user-shield',
        'ĐƠN ĐẶT HÀNG'            => 'fa-clipboard-check',
        'VĂN PHÒNG'               => 'fa-briefcase',
        'LƯƠNG'                   => 'fa-money-check-dollar',
        'Tiện ích'                => 'fa-toolbox',
    ];
    return $map[$label] ?? 'fa-folder';
}

/** URL điều hướng tới 1 view (admin: link thường; user: link ẩn qua nav_url()). */
function app_sidebar_url($v)
{
    if (function_exists('nav_url')) {
        return nav_url($v['module'], $v['controller'], $v['action']);
    }
    return '?mod=' . urlencode($v['module'])
        . '&controllers=' . urlencode($v['controller'])
        . '&action=' . urlencode($v['action']);
}

/** Chữ in thường, viết hoa chữ cái đầu câu (vd "NHẬP KHO" -> "Nhập kho"). */
function app_sentence_case($s)
{
    $s = trim((string) $s);
    if ($s === '') return $s;
    // Nhãn có viết tắt (QL, VSATTP...) cần giữ nguyên cách viết hoa, không sentence-case chung.
    static $exact = [
        'BIỂU MẪU QL VSATTP' => 'Biểu mẫu QL VSATTP',
    ];
    if (isset($exact[$s])) return $exact[$s];
    return mb_strtoupper(mb_substr($s, 0, 1, 'UTF-8'), 'UTF-8')
        . mb_strtolower(mb_substr($s, 1, null, 'UTF-8'), 'UTF-8');
}

endif;

// Bỏ view trùng với nhóm Quản trị hệ thống (vd "Quản lý người dùng").
if (function_exists('permission_filter_menu_groups')) {
    $__sb_groups = permission_filter_menu_groups($__sb_groups);
}

// Gom thêm nhóm "QUẢN TRỊ HỆ THỐNG":
//  - admin trưởng: như trang chủ.
//  - admin phó (đã nhận): cũng được vào Phân quyền + Quản lý người dùng (phạm vi được giao).
$__sb_render = $__sb_groups;
$__sb_is_deputy = function_exists('permission_is_deputy') && permission_is_deputy($__sb_user);
if ($__sb_admin || $__sb_is_deputy) {
    $__sb_render['QUẢN TRỊ HỆ THỐNG'] = [
        ['module' => 'admin_factory', 'controller' => 'admin', 'action' => 'manage_permissions', 'label' => 'Phân quyền người dùng'],
        ['module' => 'admin_factory', 'controller' => 'admin', 'action' => 'manage_user_list',   'label' => 'Quản lý người dùng'],
    ];
}
?>
<aside id="app-sidebar" class="app-sidebar">
    <div class="app-sb-brand">
        <a href="<?php echo function_exists('nav_url') ? nav_url('home', 'index', 'index') : '?mod=home&controllers=index&action=index'; ?>" class="app-sb-logo" title="Về trang chủ">
            <img src="public/images/logo/logo_vat_png.png" alt="VUA AN TOÀN">
        </a>
    </div>

    <div class="app-sb-search" id="app-sb-search">
        <i class="fa-solid fa-magnifying-glass"></i>
        <input type="text" id="app-sb-search-input" placeholder="Tìm menu..." autocomplete="off">
    </div>

    <nav class="app-sb-nav">
        <?php foreach ($__sb_render as $gname => $items):
            if (empty($items)) continue;
            $gicon = app_sidebar_group_icon($gname);
            // Nhóm có chứa view hiện tại -> mở sẵn + active.
            $group_active = false;
            foreach ($items as $it) {
                if (($it['module'] ?? '') === $__cur_mod
                    && ($it['action'] ?? '') === $__cur_act) { $group_active = true; break; }
            }
            $gdisplay = function_exists('permission_group_display_label') ? permission_group_display_label($gname) : $gname;
            $gname_esc = htmlspecialchars(app_sentence_case($gdisplay), ENT_QUOTES, 'UTF-8');
        ?>
            <div class="app-sb-group<?php echo $group_active ? ' is-open is-active' : ''; ?>">
                <button type="button" class="app-sb-parent" aria-expanded="<?php echo $group_active ? 'true' : 'false'; ?>">
                    <span class="app-sb-ic"><i class="fa-solid <?php echo $gicon; ?>"></i></span>
                    <span class="app-sb-txt"><?php echo $gname_esc; ?></span>
                    <span class="app-sb-caret"><i class="fa-solid fa-chevron-down"></i></span>
                </button>
                <ul class="app-sb-children">
                    <?php foreach ($items as $v):
                        $is_active = (($v['module'] ?? '') === $__cur_mod && ($v['action'] ?? '') === $__cur_act);
                        $label = htmlspecialchars((string) ($v['label'] ?? ''), ENT_QUOTES, 'UTF-8');
                        $url   = htmlspecialchars(app_sidebar_url($v), ENT_QUOTES, 'UTF-8');
                        $title = $label;
                    ?>
                        <li class="app-sb-child<?php echo $is_active ? ' is-active' : ''; ?>">
                            <a href="<?php echo $url; ?>" title="<?php echo $title; ?>">
                                <span class="app-sb-dot"><i class="fa-solid fa-circle"></i></span>
                                <span class="app-sb-txt"><?php echo $label; ?></span>
                            </a>
                        </li>
                    <?php endforeach; ?>
                </ul>
            </div>
        <?php endforeach; ?>
    </nav>

    <div class="app-sb-theme" id="app-sb-theme">
        <button type="button" class="app-sb-theme-btn" id="app-sb-theme-btn" title="Giao diện">
            <span class="app-sb-ic"><i class="fa-solid fa-palette"></i></span>
            <span class="app-sb-txt">Giao diện</span>
        </button>
        <div class="app-sb-theme-dropdown" id="app-sb-theme-dropdown">
            <div class="app-sb-theme-row">
                <span class="app-sb-theme-lab">Màu nền sidebar</span>
                <input type="color" id="app-sb-bg-picker" class="app-sb-color-input" value="#ffffff" title="Chọn màu nền">
            </div>
            <div class="app-sb-theme-row">
                <span class="app-sb-theme-lab">Màu chữ</span>
                <div class="app-sb-text-toggle">
                    <button type="button" class="app-sb-text-opt" data-text-mode="light">Sáng</button>
                    <button type="button" class="app-sb-text-opt" data-text-mode="dark">Tối</button>
                </div>
            </div>
            <button type="button" class="app-sb-theme-reset" id="app-sb-theme-reset">Đặt lại mặc định</button>
        </div>
    </div>

    <?php if ($__sb_admin): ?>
    <button type="button" class="app-sb-settings-btn" id="app-sb-settings-btn" title="Cài đặt hệ thống">
        <span class="app-sb-ic"><i class="fa-solid fa-gear"></i></span>
        <span class="app-sb-txt">Cài đặt hệ thống</span>
    </button>
    <?php endif; ?>

    <button type="button" class="app-sb-collapse" id="app-sb-collapse" title="Thu gọn / mở rộng">
        <span class="app-sb-ic"><i class="fa-solid fa-angles-left"></i></span>
    </button>
</aside>
<script>document.body.classList.add('app-has-sidebar');
(function(){try{
    if(localStorage.getItem('app_sb_collapsed')==='1')document.body.classList.add('app-sidebar-collapsed');
    var __bg=localStorage.getItem('app_sb_bg_color'), __tm=localStorage.getItem('app_sb_text_mode');
    if(__bg===null&&__tm===null&&localStorage.getItem('app_sb_theme')==='dark'){__bg='#1a2029';__tm='light';localStorage.setItem('app_sb_bg_color',__bg);localStorage.setItem('app_sb_text_mode',__tm);localStorage.removeItem('app_sb_theme');}
    if(__bg)document.body.style.setProperty('--app-sb-bg',__bg);
    if(__tm==='light'){document.body.style.setProperty('--app-sb-text','#fff');document.body.style.setProperty('--app-sb-text-icon','#fff');document.body.style.setProperty('--app-sb-text-faint','rgba(255,255,255,.55)');document.body.style.setProperty('--app-sb-hover-bg','rgba(255,255,255,.14)');document.body.style.setProperty('--app-sb-hover-text','#fff');document.body.style.setProperty('--app-sb-border','rgba(255,255,255,.18)');}
    else if(__tm==='dark'){document.body.style.setProperty('--app-sb-text','#000');document.body.style.setProperty('--app-sb-text-icon','#000');document.body.style.setProperty('--app-sb-text-faint','rgba(0,0,0,.55)');document.body.style.setProperty('--app-sb-hover-bg','rgba(0,0,0,.06)');document.body.style.setProperty('--app-sb-hover-text','#000');document.body.style.setProperty('--app-sb-border','rgba(0,0,0,.12)');}
    if(localStorage.getItem('app_view_theme')==='dark')document.body.classList.add('app-view-dark');
}catch(e){}})();</script>
<?php if ($__sb_admin): ?>
<!-- ===== Modal "Cài đặt hệ thống" (chỉ admin trưởng) ===== -->
<div class="app-modal" id="app-settings-modal" aria-hidden="true">
    <div class="app-modal-overlay" data-appset-close></div>
    <div class="app-modal-box app-set-box">
        <div class="app-modal-head">
            <h3><i class="fa-solid fa-gear"></i> Cài đặt hệ thống</h3>
            <button type="button" class="app-modal-close" data-appset-close aria-label="Đóng">&times;</button>
        </div>
        <div class="app-modal-body">
            <div class="app-set-tabs">
                <button type="button" class="app-set-tab is-active" data-set-tab="chat">Cài đặt chat</button>
                <button type="button" class="app-set-tab" data-set-tab="storage">Cài đặt dung lượng</button>
            </div>

            <div class="app-set-pane" id="app-set-pane-chat">
                <p class="app-set-hint">Chọn cách tự động dọn dẹp tin nhắn cho <b>mọi hộp chat</b> trong hệ thống. Việc dọn dẹp chạy ngầm khi có người dùng truy cập, không cần máy chủ riêng.</p>
                <label class="app-set-radio"><input type="radio" name="app-set-chat-mode" value="none"><span>Không tự động xóa</span></label>
                <label class="app-set-radio"><input type="radio" name="app-set-chat-mode" value="count_500"><span>Mỗi hộp chat chỉ giữ lại 500 tin nhắn gần nhất</span></label>
                <label class="app-set-radio"><input type="radio" name="app-set-chat-mode" value="days_7"><span>Xóa tin nhắn sau 7 ngày</span></label>
                <label class="app-set-radio"><input type="radio" name="app-set-chat-mode" value="days_30"><span>Xóa tin nhắn sau 1 tháng</span></label>
                <label class="app-set-radio"><input type="radio" name="app-set-chat-mode" value="days_90"><span>Xóa tin nhắn sau 3 tháng</span></label>
                <label class="app-set-radio"><input type="radio" name="app-set-chat-mode" value="days_180"><span>Xóa tin nhắn sau 6 tháng</span></label>
                <label class="app-set-radio"><input type="radio" name="app-set-chat-mode" value="days_365"><span>Xóa tin nhắn sau 1 năm</span></label>
                <div class="app-set-msg" id="app-set-chat-msg"></div>
                <button type="button" class="app-btn app-btn-primary" id="app-set-chat-save">Lưu cài đặt chat</button>
            </div>

            <div class="app-set-pane" id="app-set-pane-storage" style="display:none;">
                <p class="app-set-hint">Giới hạn dung lượng lưu trữ tối đa cho <b>mỗi người dùng</b> ở mục Quản lý file, để tránh tải lên quá nhiều file nặng hệ thống.</p>
                <label class="app-pw-label">Giới hạn dung lượng mỗi người dùng (MB)</label>
                <input type="number" class="app-pw-input" id="app-set-storage-quota" min="1" step="1">
                <div class="app-set-msg" id="app-set-storage-msg"></div>
                <button type="button" class="app-btn app-btn-primary" id="app-set-storage-save">Lưu giới hạn dung lượng</button>

                <div class="app-set-divider"></div>
                <label class="app-pw-label">Ưu tiên riêng cho từng người dùng</label>
                <p class="app-set-hint">Người dùng được ưu tiên sẽ dùng mức dung lượng riêng này thay vì mức chung ở trên.</p>
                <div class="app-set-priority-row">
                    <div class="app-combo" id="app-set-priority-combo">
                        <input type="text" class="app-pw-input" id="app-set-priority-search" placeholder="Tìm người dùng..." autocomplete="off">
                        <input type="hidden" id="app-set-priority-user">
                        <div class="app-combo-list" id="app-set-priority-combo-list" style="display:none;"></div>
                    </div>
                    <input type="number" class="app-pw-input" id="app-set-priority-quota" min="1" step="1" placeholder="MB">
                    <button type="button" class="app-btn app-btn-primary" id="app-set-priority-add">Thêm</button>
                </div>
                <div class="app-set-msg" id="app-set-priority-msg"></div>
                <div class="app-set-priority-list" id="app-set-priority-list"></div>
            </div>
        </div>
    </div>
</div>
<?php endif; ?>
<?php
// Widget chat (góc phải dưới) — dùng chung cho mọi trang có app shell.
require_once LAYOUTPATH . DIRECTORY_SEPARATOR . 'chat-widget.php';
?>
