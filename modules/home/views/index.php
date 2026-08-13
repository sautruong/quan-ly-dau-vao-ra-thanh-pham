<?php
defined('APPPATH') OR exit('Không được quyền truy cập phần này');
/** @var array $menu_groups [group_label => [ {module,controller,action,label} ]] (đã áp thứ tự đã lưu) */
/** @var bool  $is_admin */
/** @var array|null $current_user */
/** @var array $bg_slides [ {id, filename, from_lib, url} ] */
$menu_groups  = isset($menu_groups) && is_array($menu_groups) ? $menu_groups : [];
$is_admin     = !empty($is_admin);
$current_user = isset($current_user) && is_array($current_user) ? $current_user : null;
$bg_slides    = isset($bg_slides) && is_array($bg_slides) ? $bg_slides : [];
?>
<!DOCTYPE html>
<html lang="vi" class="home-html">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Trang chủ</title>
    <link rel="icon" href="public/images/logo/logo_vat_png.png" type="image/png">
    <link rel="stylesheet" href="<?php echo asset_ver('public/css/reset.css'); ?>">
    <link rel="stylesheet" href="<?php echo asset_ver('public/css/all.css'); ?>">
    <link rel="stylesheet" href="<?php echo asset_ver('public/css/global.css'); ?>">
    <link rel="stylesheet" href="<?php echo asset_ver('public/css/style_home.css'); ?>">
</head>

<body class="home-body">
    <div id="home-bg-layer"></div>
    <script>window.__HOME_BG_SLIDES = <?php echo json_encode($bg_slides, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES); ?>;</script>

    <!-- "Welcome {tên}": mờ->sáng giữa màn hình, giữ 3s rồi tự ẩn (nằm trước lớp nền, xem header_home.js::initWelcomeFlash). -->
    <div id="home-welcome-flash" aria-hidden="true">
        <span class="hwf-line1">Welcome</span>
        <span class="hwf-line2"><?php echo htmlspecialchars((string) ($current_user['fullname'] ?? ''), ENT_QUOTES, 'UTF-8'); ?></span>
    </div>

    <?php require LAYOUTPATH . DIRECTORY_SEPARATOR . 'header-home.php'; ?>

    <?php if (empty($menu_groups)): ?>
        <div id="content-home">
            <div class="home-welcome-empty">Bạn chưa được cấp quyền truy cập chức năng nào. Vui lòng liên hệ quản trị viên.</div>
        </div>
    <?php endif; ?>

    <?php
    // Widget chat (góc phải dưới) ở trang chủ.
    require_once LAYOUTPATH . DIRECTORY_SEPARATOR . 'chat-widget.php';
    ?>

    <!-- Phân quyền real-time cho trang chủ: đổi quyền -> tải lại trang để menu ngang cập nhật. -->
    <script>
    (function () {
        var PSTATE = '?mod=home&controllers=index&action=permissionState';
        function toast(msg) {
            var t = document.createElement('div');
            t.textContent = msg;
            t.style.cssText = 'position:fixed;left:50%;bottom:28px;transform:translate(-50%,16px);'
                + 'background:#0f5132;color:#fff;padding:12px 18px;border-radius:10px;font-size:14px;font-weight:600;'
                + 'box-shadow:0 10px 30px rgba(0,0,0,.25);z-index:99999;opacity:0;transition:opacity .3s,transform .3s;';
            document.body.appendChild(t);
            requestAnimationFrame(function () { t.style.opacity = '1'; t.style.transform = 'translate(-50%,0)'; });
            setTimeout(function () { t.style.opacity = '0'; setTimeout(function () { t.remove(); }, 350); }, 1600);
        }
        var lastSig = null;
        function poll() {
            fetch(PSTATE + '&cur_mod=home', { credentials: 'same-origin' })
            .then(function (r) { return r.json(); })
            .then(function (res) {
                if (!res || !res.ok) return;
                if (lastSig === null) { lastSig = res.sig; return; }
                if (res.sig !== lastSig) {
                    lastSig = res.sig;
                    if (!res.is_admin) {
                        toast('Quyền truy cập của bạn vừa được cập nhật.');
                        setTimeout(function () { window.location.reload(); }, 1700);
                    }
                }
            })
            .catch(function () {});
        }
        // Kiểm tra quyền: 30s, tự giãn khi rỗi và dừng khi tab bị ẩn (AppPoll — app_shell.js).
        if (window.AppPoll) window.AppPoll.every('home-perm', poll, { interval: 30000, maxInterval: 60000 });
        else setInterval(poll, 30000);
        setTimeout(poll, 2000);
    })();
    </script>
</body>

</html>
