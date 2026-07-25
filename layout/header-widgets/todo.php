<?php
/* Todo list cá nhân: badge số task chưa xong, click xổ modal giống chuông.
 * Cần $__hd_todo_pending (từ _bootstrap.php). */
?>
<div class="app-todo" id="app-todo">
    <button type="button" class="app-todo-btn" id="app-todo-btn" aria-label="Việc cần làm">
        <i class="fa-solid fa-list-check"></i>
        <span class="app-todo-badge" id="app-todo-badge" <?php echo $__hd_todo_pending > 0 ? '' : 'style="display:none;"'; ?>>
            <?php echo $__hd_todo_pending > 99 ? '99+' : (int) $__hd_todo_pending; ?>
        </span>
    </button>
    <div class="app-todo-dropdown" id="app-todo-dropdown">
        <!-- Hàng tab các danh sách (JS render): hiện tối đa 3, dư thì gom vào "..." -->
        <div class="app-todo-tabs" id="app-todo-tabs">
            <div class="app-todo-tabset" id="app-todo-tabset"></div>
            <div class="app-todo-more-wrap" id="app-todo-more-wrap" style="display:none;">
                <button type="button" class="app-todo-more-btn" id="app-todo-more-btn" title="Danh sách khác">…</button>
                <div class="app-todo-more-menu" id="app-todo-more-menu"></div>
            </div>
            <button type="button" class="app-todo-add-list" id="app-todo-add-list" title="Thêm danh sách">
                <i class="fa-solid fa-plus"></i>
            </button>
            <div class="app-todo-setting-wrap" id="app-todo-setting-wrap">
                <button type="button" class="app-todo-tabbtn" id="app-todo-setting-btn" title="Cài đặt tự xóa">
                    <i class="fa-solid fa-gear"></i>
                </button>
                <div class="app-todo-setting-menu" id="app-todo-setting-menu">
                    <div class="app-todo-setting-label">Tự động xóa task hoàn thành</div>
                    <label class="app-todo-setting-opt"><input type="radio" name="todo-clear-mode" value="manual"> Xóa thủ công</label>
                    <label class="app-todo-setting-opt"><input type="radio" name="todo-clear-mode" value="1day"> Tự xóa sau 1 ngày</label>
                    <label class="app-todo-setting-opt"><input type="radio" name="todo-clear-mode" value="1week"> Tự xóa sau 1 tuần</label>
                    <label class="app-todo-setting-opt"><input type="radio" name="todo-clear-mode" value="30days"> Tự xóa sau 30 ngày</label>
                </div>
            </div>
        </div>

        <div class="app-todo-head">
            <div class="app-todo-titlebar">
                <span class="app-todo-title" id="app-todo-active-title" title="Bấm để đổi tên (chủ danh sách)">Việc cấp bách</span>
                <span class="app-todo-owner" id="app-todo-owner"></span>
            </div>
            <div class="app-todo-tools" id="app-todo-tools">
                <button type="button" class="app-todo-tool owner-only" id="app-todo-share-btn" title="Chia sẻ danh sách">
                    <i class="fa-solid fa-user-plus"></i><span>Chia sẻ</span>
                </button>
                <button type="button" class="app-todo-tool owner-only" id="app-todo-clear-done" title="Xóa task hoàn thành">
                    <i class="fa-solid fa-check-double"></i><span>Xóa xong</span>
                </button>
                <button type="button" class="app-todo-tool owner-only" id="app-todo-clear-all" title="Xóa tất cả task">
                    <i class="fa-solid fa-trash"></i><span>Xóa hết</span>
                </button>
                <button type="button" class="app-todo-tool danger owner-only" id="app-todo-list-del" title="Xóa danh sách này">
                    <i class="fa-solid fa-trash-can"></i><span>Xóa list</span>
                </button>
                <button type="button" class="app-todo-tool danger member-only" id="app-todo-leave-btn" title="Rời bỏ danh sách này" style="display:none;">
                    <i class="fa-solid fa-right-from-bracket"></i><span>Rời bỏ</span>
                </button>
            </div>
        </div>

        <!-- Panel chia sẻ (chủ danh sách): chọn user → Gửi; danh sách người đang nhận + Gỡ. -->
        <div class="app-todo-share" id="app-todo-share" style="display:none;">
            <div class="app-todo-share-head">
                <span><i class="fa-solid fa-share-nodes"></i> Giao danh sách cho người khác</span>
                <button type="button" class="app-todo-share-close" id="app-todo-share-close" aria-label="Đóng">&times;</button>
            </div>
            <input type="text" class="app-todo-share-search" id="app-todo-share-search"
                   placeholder="Tìm theo tên / tài khoản…" autocomplete="off">
            <div class="app-todo-share-users" id="app-todo-share-users"></div>
            <label class="app-todo-share-canedit">
                <input type="checkbox" id="app-todo-share-canedit">
                Cho phép thêm, sửa nội dung
            </label>
            <button type="button" class="app-todo-share-send" id="app-todo-share-send" disabled>
                <i class="fa-solid fa-paper-plane"></i> Gửi
            </button>
            <div class="app-todo-share-msg" id="app-todo-share-msg"></div>
            <div class="app-todo-share-members-label">Đang chia sẻ với</div>
            <div class="app-todo-share-members" id="app-todo-share-members"></div>
        </div>

        <div class="app-todo-list" id="app-todo-list">
            <div class="app-todo-empty">Đang tải…</div>
        </div>
        <div class="app-todo-add" id="app-todo-add">
            <input type="text" class="app-todo-input" id="app-todo-input"
                   placeholder="Thêm việc cần làm, nhấn Enter…" maxlength="500" autocomplete="off">
        </div>
    </div>
</div>
