<?php
/* Chuông thông báo: badge số chưa đọc (góc phải-trên), click xổ danh sách.
 * Cần $__hd_unread (từ _bootstrap.php). id="app-bell" được chat.js dùng làm
 * mốc để gắn "bóng chat" khi bật Ẩn bóng chat — giữ nguyên id này. */
?>
<div class="app-bell" id="app-bell">
    <button type="button" class="app-bell-btn" id="app-bell-btn" aria-label="Thông báo">
        <i class="fa-solid fa-bell"></i>
        <span class="app-bell-badge" id="app-bell-badge" <?php echo $__hd_unread > 0 ? '' : 'style="display:none;"'; ?>>
            <?php echo $__hd_unread > 99 ? '99+' : (int) $__hd_unread; ?>
        </span>
    </button>
    <div class="app-bell-dropdown" id="app-bell-dropdown">
        <div class="app-bell-head">
            <span>Thông báo</span>
            <button type="button" class="app-bell-readall" id="app-bell-readall">Đánh dấu tất cả đã đọc</button>
        </div>
        <div class="app-bell-list" id="app-bell-list">
            <div class="app-bell-empty">Đang tải…</div>
        </div>
        <div class="app-bell-foot" id="app-bell-foot">
            <button type="button" class="app-bell-older" id="app-bell-older" style="display:none">Trước đó</button>
            <button type="button" class="app-bell-clear" id="app-bell-clear">Xóa hết thông báo</button>
        </div>
    </div>
</div>
