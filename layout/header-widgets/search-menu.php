<?php
/* Ô tìm kiếm menu con trên header trang chủ: lọc client-side (không AJAX)
 * trên toàn bộ link đã render sẵn trong .home-nav-drop (kể cả nhóm đang
 * nằm trong "..."). Xem public/js/home/header_home.js. */
?>
<div class="home-search" id="home-search">
    <button type="button" class="home-icon-btn" id="home-search-btn" aria-label="Tìm menu" title="Tìm menu">
        <i class="fa-solid fa-magnifying-glass"></i>
    </button>
    <div class="home-search-pop" id="home-search-pop">
        <input type="text" id="home-search-input" placeholder="Tìm menu…" autocomplete="off">
        <div class="home-search-results" id="home-search-results"></div>
    </div>
</div>
