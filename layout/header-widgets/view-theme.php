<?php
/* Sáng / Tối cho NỘI DUNG của trang chi tiết đang xem (khác nút "Giao diện"
 * của sidebar — nút đó chỉ đổi màu sidebar). Bật tối: nền tối, chữ/icon giữ
 * nguyên sắc màu nhưng sáng nổi bật lên (kỹ thuật filter invert + hue-rotate).
 * JS: public/js/shared/app_shell.js (mục "Sáng / Tối cho nội dung trang"). */
?>
<button type="button" class="app-view-theme-btn" id="app-view-theme-btn" title="Chuyển sang giao diện tối" aria-label="Sáng / Tối">
    <i class="fa-solid fa-moon"></i>
</button>
