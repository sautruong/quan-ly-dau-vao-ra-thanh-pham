<?php
/* Toggle sáng/tối cho CHỮ + ICON của header trang chủ (không phải theme
 * toàn hệ thống) — vì header trong suốt, nền có thể là ảnh tối/sáng bất
 * kỳ nên cần chỉnh riêng để chữ menu luôn đọc được. Lưu localStorage
 * key 'home_header_text_mode' ('light'|'dark'); áp sớm bằng script
 * bootstrap trong header-home.php để tránh nháy màu lúc tải trang. */
?>
<button type="button" class="home-icon-btn" id="home-text-theme-btn" aria-label="Sáng / Tối chữ menu" title="Sáng / Tối chữ menu">
    <i class="fa-solid fa-circle-half-stroke"></i>
</button>
<script>
(function () {
    var btn = document.getElementById('home-text-theme-btn');
    var header = document.getElementById('home-header');
    if (!btn || !header) return;
    btn.addEventListener('click', function () {
        var light = header.classList.toggle('is-light-text');
        try { localStorage.setItem('home_header_text_mode', light ? 'light' : 'dark'); } catch (e) {}
    });
})();
</script>
