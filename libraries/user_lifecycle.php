<?php
/**
 * user_lifecycle — "Rời hệ thống" (nhân viên thôi việc).
 *
 * Khi 1 user rời hệ thống:
 *   - status = 'left' (Đã rời hệ thống), status_changed_at = giờ rời.
 *   - Xóa toàn bộ dữ liệu cá nhân hóa (todo / task / project / chat...).
 *   - Tài khoản tự xóa hẳn sau 24h (xem admin_purge_expired_pending_users()).
 *   - Trong khi chờ xóa, app chat hiển thị "Tài khoản không tồn tại".
 *
 * File require_once nhiều nơi → mọi hàm bọc guard function_exists, không autoload.
 */

if (!function_exists('lifecycle_table_exists')) {

    /** Bảng có tồn tại không (cache trong request). */
    function lifecycle_table_exists($table)
    {
        static $cache = [];
        $t = (string) $table;
        if (isset($cache[$t])) return $cache[$t];
        $esc = escape_string($t);
        $row = db_fetch_row("SHOW TABLES LIKE '{$esc}'");
        return $cache[$t] = (bool) $row;
    }

    /** Xóa an toàn theo điều kiện nếu bảng tồn tại. */
    function lifecycle_safe_delete($table, $where)
    {
        if (!lifecycle_table_exists($table)) return;
        db_delete($table, $where);
    }

    /**
     * Xóa toàn bộ dữ liệu cá nhân hóa của 1 user (todo / task / project / chat...).
     * Bọc guard từng bảng để chạy được dù module chưa cài.
     */
    function lifecycle_purge_personal_data($user_id)
    {
        $uid = (int) $user_id;
        if ($uid <= 0) return false;

        /* ----- Todo (danh sách + task + thành viên + cấu hình) ----- */
        if (lifecycle_table_exists('todo_lists')) {
            $owned = db_fetch_array("SELECT id FROM todo_lists WHERE owner_id = {$uid}") ?: [];
            $ids   = array_map(static fn($r) => (int) $r['id'], $owned);
            if ($ids) {
                $in = implode(',', $ids);
                lifecycle_safe_delete('todo_items', "list_id IN ({$in})");
                lifecycle_safe_delete('todo_list_members', "list_id IN ({$in})");
            }
            lifecycle_safe_delete('todo_lists', "owner_id = {$uid}");
        }
        lifecycle_safe_delete('todo_items', "user_id = {$uid}");
        lifecycle_safe_delete('todo_list_members', "user_id = {$uid}");
        lifecycle_safe_delete('todo_settings', "user_id = {$uid}");

        /* ----- Tasks (Google Tasks) ----- */
        lifecycle_safe_delete('task_items', "user_id = {$uid}");
        lifecycle_safe_delete('task_groups', "user_id = {$uid}");

        /* ----- Project management ----- */
        lifecycle_safe_delete('project_reminders', "user_id = {$uid}");
        lifecycle_safe_delete('project_reactions', "user_id = {$uid}");
        lifecycle_safe_delete('project_members', "user_id = {$uid}");
        lifecycle_safe_delete('project_messages', "sender_id = {$uid}");
        if (lifecycle_table_exists('projects')) {
            $owned = db_fetch_array("SELECT id FROM projects WHERE created_by = {$uid}") ?: [];
            $ids   = array_map(static fn($r) => (int) $r['id'], $owned);
            if ($ids) {
                $in = implode(',', $ids);
                lifecycle_safe_delete('project_reminders', "project_id IN ({$in})");
                lifecycle_safe_delete('project_reactions', "message_id IN (SELECT id FROM project_messages WHERE project_id IN ({$in}))");
                lifecycle_safe_delete('project_messages', "project_id IN ({$in})");
                lifecycle_safe_delete('project_sessions', "project_id IN ({$in})");
                lifecycle_safe_delete('project_members', "project_id IN ({$in})");
            }
            lifecycle_safe_delete('projects', "created_by = {$uid}");
        }

        /* ----- Chat ----- */
        lifecycle_safe_delete('chat_reminders', "user_id = {$uid}");
        lifecycle_safe_delete('chat_reactions', "user_id = {$uid}");
        lifecycle_safe_delete('chat_contact_aliases', "owner_id = {$uid} OR contact_id = {$uid}");
        lifecycle_safe_delete('chat_participants', "user_id = {$uid}");
        lifecycle_safe_delete('chat_messages', "sender_id = {$uid}");

        /* ----- Thông báo (chuông) ----- */
        lifecycle_safe_delete('notifications', "user_id = {$uid}");

        /* ----- Bổ nhiệm admin phó (nếu có) ----- */
        lifecycle_safe_delete('admin_deputies', "user_id = {$uid}");
        lifecycle_safe_delete('admin_deputy_groups', "user_id = {$uid}");
        lifecycle_safe_delete('admin_deputy_classes', "user_id = {$uid}");

        return true;
    }

    /**
     * Đánh dấu 1 user "Đã rời hệ thống": set status='left' + xóa dữ liệu cá nhân hóa.
     * Tài khoản sẽ tự xóa hẳn sau 24h.
     */
    function lifecycle_mark_user_left($user_id)
    {
        $uid = (int) $user_id;
        if ($uid <= 0) return false;
        db_update('tbl_users', [
            'status'            => 'left',
            'status_changed_at' => date('Y-m-d H:i:s'),
            'activation_code'   => null,
            'code_expired_at'   => null,
        ], "id = {$uid}");
        lifecycle_purge_personal_data($uid);
        return true;
    }

    /** Tài khoản còn hiệu lực không (tồn tại & chưa rời). Dùng cho realtime + chat. */
    function lifecycle_account_active($user_id)
    {
        $uid = (int) $user_id;
        if ($uid <= 0) return false;
        $row = db_fetch_row("SELECT status FROM tbl_users WHERE id = {$uid} LIMIT 1");
        if (!$row) return false;
        return ($row['status'] ?? '') !== 'left';
    }
}
