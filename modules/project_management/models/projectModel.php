<?php
defined('APPPATH') OR exit('Không được quyền truy cập phần này');

/* =====================================================================
 *  MODULE: QUẢN LÝ DỰ ÁN — prefix pm_
 *  Không gian để nhiều user cùng thảo luận & xây dựng các dự án web.
 *
 *  Mô hình:
 *   - projects                : 1 dự án.
 *   - project_members         : thành viên + vai trò (leader|member).
 *   - project_sessions        : 1 dự án có nhiều "session" (nhóm công việc / tab).
 *   - project_messages        : tin nhắn của 1 session (chat nâng cao).
 *         type: text|image|file|system|checklist|table|tree|process|canvas
 *         payload (JSON) giữ cấu trúc cho các loại tin đặc biệt.
 *   - project_attachments     : ảnh/file đính kèm tin nhắn.
 *   - project_reactions       : cảm xúc (1 emoji / user / tin).
 *   - project_stars           : gắn sao DÙNG CHUNG cả nhóm (1 dòng / tin).
 *   - project_reminders       : nhắc hẹn theo tin (báo qua poll).
 *   - project_checklist_items : dòng checklist (trạng thái tick dùng chung).
 *   - project_canvas          : 1 canvas / session (vẽ phác thảo, cộng tác).
 *
 *  Gotcha múi giờ (xem chat.php): XAMPP mặc định Europe/Berlin → set lại VN.
 * =====================================================================*/

date_default_timezone_set('Asia/Ho_Chi_Minh');

if (!defined('PM_RECALL_WINDOW')) define('PM_RECALL_WINDOW', 3600); // 1 giờ

/* ============================================================
 *  Schema — tự tạo (idempotent, gọi ở construct)
 * ============================================================ */

function pm_ensure_schema()
{
    static $done = false;
    if ($done) return;
    $done = true;

    db_query("CREATE TABLE IF NOT EXISTS projects (
        id          INT(11) NOT NULL AUTO_INCREMENT,
        name        VARCHAR(255) NOT NULL,
        description TEXT DEFAULT NULL,
        status      ENUM('active','archived') NOT NULL DEFAULT 'active',
        created_by  INT(11) NOT NULL,
        created_at  DATETIME NOT NULL,
        updated_at  DATETIME NOT NULL,
        PRIMARY KEY (id),
        KEY idx_pj_status (status),
        KEY idx_pj_updated (updated_at)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    db_query("CREATE TABLE IF NOT EXISTS project_members (
        id         INT(11) NOT NULL AUTO_INCREMENT,
        project_id INT(11) NOT NULL,
        user_id    INT(11) NOT NULL,
        role       ENUM('leader','member') NOT NULL DEFAULT 'member',
        joined_at  DATETIME NOT NULL,
        PRIMARY KEY (id),
        UNIQUE KEY uq_pm (project_id, user_id),
        KEY idx_pm_user (user_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    db_query("CREATE TABLE IF NOT EXISTS project_sessions (
        id          INT(11) NOT NULL AUTO_INCREMENT,
        project_id  INT(11) NOT NULL,
        name        VARCHAR(255) NOT NULL,
        sort_order  INT(11) NOT NULL DEFAULT 0,
        created_by  INT(11) NOT NULL,
        created_at  DATETIME NOT NULL,
        updated_at  DATETIME NOT NULL,
        PRIMARY KEY (id),
        KEY idx_ps_project (project_id, sort_order)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    db_query("CREATE TABLE IF NOT EXISTS project_messages (
        id             INT(11) NOT NULL AUTO_INCREMENT,
        session_id     INT(11) NOT NULL,
        project_id     INT(11) NOT NULL,
        sender_id      INT(11) NOT NULL,
        body           TEXT DEFAULT NULL,
        type           ENUM('text','image','file','system','checklist','table','tree','process','canvas','vote') NOT NULL DEFAULT 'text',
        payload        LONGTEXT DEFAULT NULL,
        reply_to_id    INT(11) NOT NULL DEFAULT 0,
        forward_from_id INT(11) NOT NULL DEFAULT 0,
        recalled       TINYINT(1) NOT NULL DEFAULT 0,
        created_at     DATETIME NOT NULL,
        PRIMARY KEY (id),
        KEY idx_msg_session (session_id, id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    db_query("CREATE TABLE IF NOT EXISTS project_attachments (
        id            INT(11) NOT NULL AUTO_INCREMENT,
        message_id    INT(11) NOT NULL,
        file_name     VARCHAR(255) NOT NULL,
        original_name VARCHAR(255) NOT NULL,
        mime          VARCHAR(120) DEFAULT NULL,
        size          INT(11) NOT NULL DEFAULT 0,
        is_image      TINYINT(1) NOT NULL DEFAULT 0,
        PRIMARY KEY (id),
        KEY idx_att_msg (message_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    db_query("CREATE TABLE IF NOT EXISTS project_reactions (
        id         INT(11) NOT NULL AUTO_INCREMENT,
        message_id INT(11) NOT NULL,
        user_id    INT(11) NOT NULL,
        emoji      VARCHAR(24) NOT NULL,
        created_at DATETIME NOT NULL,
        PRIMARY KEY (id),
        UNIQUE KEY uq_react (message_id, user_id),
        KEY idx_react_msg (message_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    // Gắn sao CÁ NHÂN HÓA: mỗi user 1 dòng / tin (bộ lọc sao riêng, không đụng nhau).
    db_query("CREATE TABLE IF NOT EXISTS project_stars (
        id         INT(11) NOT NULL AUTO_INCREMENT,
        message_id INT(11) NOT NULL,
        starred_by INT(11) NOT NULL,
        note       TEXT NULL,
        created_at DATETIME NOT NULL,
        PRIMARY KEY (id),
        UNIQUE KEY uq_star (message_id, starred_by)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    db_query("CREATE TABLE IF NOT EXISTS project_reminders (
        id         INT(11) NOT NULL AUTO_INCREMENT,
        user_id    INT(11) NOT NULL,
        message_id INT(11) NOT NULL,
        session_id INT(11) NOT NULL,
        project_id INT(11) NOT NULL,
        remind_at  DATETIME NOT NULL,
        note       TEXT DEFAULT NULL,
        notified   TINYINT(1) NOT NULL DEFAULT 0,
        created_at DATETIME NOT NULL,
        PRIMARY KEY (id),
        KEY idx_rem_due (user_id, notified, remind_at)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    // Checklist: trạng thái tick DÙNG CHUNG (người nhận tick → cả nhóm thấy).
    db_query("CREATE TABLE IF NOT EXISTS project_checklist_items (
        id         INT(11) NOT NULL AUTO_INCREMENT,
        message_id INT(11) NOT NULL,
        content    VARCHAR(500) NOT NULL,
        is_done    TINYINT(1) NOT NULL DEFAULT 0,
        done_by    INT(11) NOT NULL DEFAULT 0,
        done_at    DATETIME DEFAULT NULL,
        sort_order INT(11) NOT NULL DEFAULT 0,
        PRIMARY KEY (id),
        KEY idx_cl_msg (message_id, sort_order)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    db_query("CREATE TABLE IF NOT EXISTS project_canvas (
        id         INT(11) NOT NULL AUTO_INCREMENT,
        session_id INT(11) NOT NULL,
        project_id INT(11) NOT NULL,
        data       LONGTEXT DEFAULT NULL,
        version    INT(11) NOT NULL DEFAULT 0,
        updated_by INT(11) NOT NULL DEFAULT 0,
        updated_at DATETIME NOT NULL,
        PRIMARY KEY (id),
        UNIQUE KEY uq_canvas_session (session_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    // Ghim tin nhắn (dùng chung cả nhóm): 1 dòng / tin.
    db_query("CREATE TABLE IF NOT EXISTS project_pins (
        id         INT(11) NOT NULL AUTO_INCREMENT,
        message_id INT(11) NOT NULL,
        session_id INT(11) NOT NULL,
        pinned_by  INT(11) NOT NULL,
        created_at DATETIME NOT NULL,
        PRIMARY KEY (id),
        UNIQUE KEY uq_pin (message_id),
        KEY idx_pin_session (session_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    // Bình chọn (vote list): mỗi user chọn 1+ option của 1 tin type='vote'.
    db_query("CREATE TABLE IF NOT EXISTS project_votes (
        id         INT(11) NOT NULL AUTO_INCREMENT,
        message_id INT(11) NOT NULL,
        opt_index  INT(11) NOT NULL,
        user_id    INT(11) NOT NULL,
        created_at DATETIME NOT NULL,
        PRIMARY KEY (id),
        UNIQUE KEY uq_vote (message_id, opt_index, user_id),
        KEY idx_vote_msg (message_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    // Trạng thái cá nhân: session đang làm việc gần nhất của mỗi user trong 1 dự án.
    db_query("CREATE TABLE IF NOT EXISTS project_user_state (
        user_id         INT(11) NOT NULL,
        project_id      INT(11) NOT NULL,
        last_session_id INT(11) NOT NULL DEFAULT 0,
        updated_at      DATETIME NOT NULL,
        PRIMARY KEY (user_id, project_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    // "Đã xem": mỗi user giữ tin nhắn mới nhất đã đọc trong 1 session.
    db_query("CREATE TABLE IF NOT EXISTS project_reads (
        session_id           INT(11) NOT NULL,
        user_id              INT(11) NOT NULL,
        last_read_message_id INT(11) NOT NULL DEFAULT 0,
        last_read_at         DATETIME NOT NULL,
        PRIMARY KEY (session_id, user_id),
        KEY idx_pr_session (session_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    // Lời mời tham gia dự án: leader mời → đẩy chuông → người được mời chấp nhận mới vào.
    db_query("CREATE TABLE IF NOT EXISTS project_invites (
        id         INT(11) NOT NULL AUTO_INCREMENT,
        project_id INT(11) NOT NULL,
        user_id    INT(11) NOT NULL,
        invited_by INT(11) NOT NULL,
        status     ENUM('pending','accepted','declined') NOT NULL DEFAULT 'pending',
        created_at DATETIME NOT NULL,
        responded_at DATETIME DEFAULT NULL,
        PRIMARY KEY (id),
        UNIQUE KEY uq_invite (project_id, user_id),
        KEY idx_inv_user (user_id, status)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    // Bảng project_stars cũ dùng UNIQUE(message_id) (sao dùng chung) → đổi sang per-user.
    $starIdx = db_fetch_array("SHOW INDEX FROM project_stars WHERE Key_name = 'uq_star'") ?: [];
    if (count($starIdx) === 1) { // chỉ 1 cột → bản cũ, nâng cấp lên (message_id, starred_by)
        db_query("ALTER TABLE project_stars DROP INDEX uq_star, ADD UNIQUE KEY uq_star (message_id, starred_by)");
    }
    // Mô tả khi gắn sao (tùy chọn) — hiển thị dưới phần meta khi lọc tin gắn sao.
    if (!db_fetch_row("SHOW COLUMNS FROM project_stars LIKE 'note'")) {
        db_query("ALTER TABLE project_stars ADD COLUMN note TEXT NULL AFTER starred_by");
    }

    // Bảng project_messages đã tồn tại từ trước có thể thiếu 'vote' trong ENUM → bổ sung 1 lần.
    $col = db_fetch_row("SHOW COLUMNS FROM project_messages LIKE 'type'");
    if ($col && strpos((string) $col['Type'], "'vote'") === false) {
        db_query("ALTER TABLE project_messages MODIFY type
            ENUM('text','image','file','system','checklist','table','tree','process','canvas','vote')
            NOT NULL DEFAULT 'text'");
    }
}

/* ============================================================
 *  Helpers chung
 * ============================================================ */

/** id user đang đăng nhập (0 nếu không có). */
function pm_uid()
{
    $u = permission_current_user();
    return $u ? (int) $u['id'] : 0;
}

function pm_now() { return date('Y-m-d H:i:s'); }

/** URL avatar (rỗng nếu chưa có ảnh → FE hiển thị chữ cái đầu). */
function pm_avatar_url($avatar)
{
    $avatar = trim((string) $avatar);
    return $avatar === '' ? '' : 'public/images/avatar/' . $avatar;
}

/** Thông tin gọn 1 user. */
function pm_user_brief($user_id)
{
    static $cache = [];
    $uid = (int) $user_id;
    if (isset($cache[$uid])) return $cache[$uid];
    $r = db_fetch_row("SELECT id, fullname, username, avatar FROM tbl_users WHERE id = {$uid} LIMIT 1");
    if (!$r) {
        return $cache[$uid] = ['id' => $uid, 'fullname' => 'Người dùng', 'username' => '', 'avatar' => ''];
    }
    return $cache[$uid] = [
        'id'       => (int) $r['id'],
        'fullname' => $r['fullname'] !== '' ? $r['fullname'] : $r['username'],
        'username' => $r['username'],
        'avatar'   => pm_avatar_url($r['avatar']),
    ];
}

/**
 * Bản đồ biệt danh (danh xưng riêng) của 1 người xem — dùng chung bảng
 * chat_contact_aliases với hộp chat mini. [contact_id => alias].
 */
function pm_alias_map($viewer_id)
{
    static $cache = [];
    static $tableExists = null;
    $vid = (int) $viewer_id;
    if ($vid <= 0) return [];
    if (isset($cache[$vid])) return $cache[$vid];
    if ($tableExists === null) {
        $tableExists = (bool) db_fetch_row("SHOW TABLES LIKE 'chat_contact_aliases'");
    }
    $map = [];
    if ($tableExists) {
        $rows = db_fetch_array("SELECT contact_id, alias FROM chat_contact_aliases WHERE owner_id = {$vid}") ?: [];
        foreach ($rows as $r) { $a = trim((string) $r['alias']); if ($a !== '') $map[(int) $r['contact_id']] = $a; }
    }
    return $cache[$vid] = $map;
}

/** Tên hiển thị theo góc nhìn người xem: ưu tiên biệt danh trong danh bạ, else fullname. */
function pm_display_name($user_id, $viewer_id = null)
{
    $viewer = ($viewer_id === null) ? pm_uid() : (int) $viewer_id;
    $uid = (int) $user_id;
    $aliases = pm_alias_map($viewer);
    if (isset($aliases[$uid])) return $aliases[$uid];
    return pm_user_brief($uid)['fullname'];
}

/** Tất cả user khác (để chọn thêm thành viên). */
function pm_all_users($me_id = 0)
{
    $me = (int) $me_id;
    $rows = db_fetch_array(
        "SELECT id, fullname, username, avatar FROM tbl_users
         WHERE id <> {$me} AND (status IS NULL OR status <> 'blocked')
         ORDER BY fullname, username"
    ) ?: [];
    $out = [];
    foreach ($rows as $r) {
        $id = (int) $r['id'];
        $out[] = [
            'id'       => $id,
            'fullname' => pm_display_name($id, $me),
            'username' => $r['username'],
            'avatar'   => pm_avatar_url($r['avatar']),
        ];
    }
    return $out;
}

/* ============================================================
 *  DỰ ÁN (projects)
 * ============================================================ */

/** Danh sách dự án mà user là thành viên (kèm role + số thành viên + tiến độ checklist). */
function pm_projects_for($uid)
{
    pm_ensure_schema();
    $uid = (int) $uid;
    if ($uid <= 0) return [];
    $rows = db_fetch_array(
        "SELECT p.*, m.role AS my_role
         FROM projects p
         JOIN project_members m ON m.project_id = p.id AND m.user_id = {$uid}
         WHERE p.status = 'active'
         ORDER BY p.updated_at DESC, p.id DESC"
    ) ?: [];
    $out = [];
    foreach ($rows as $p) {
        $pid = (int) $p['id'];
        $cnt = db_fetch_row("SELECT COUNT(*) AS c FROM project_members WHERE project_id = {$pid}");
        $scnt = db_fetch_row("SELECT COUNT(*) AS c FROM project_sessions WHERE project_id = {$pid}");
        $out[] = [
            'id'            => $pid,
            'name'          => $p['name'],
            'description'   => (string) ($p['description'] ?? ''),
            'status'        => $p['status'],
            'my_role'       => $p['my_role'],
            'member_count'  => (int) ($cnt['c'] ?? 0),
            'session_count' => (int) ($scnt['c'] ?? 0),
            'created_at'    => $p['created_at'],
            'updated_at'    => $p['updated_at'],
        ];
    }
    return $out;
}

/** % checklist hoàn thành toàn dự án (gợi ý: hiển thị trên card). */
function pm_project_progress($project_id)
{
    $pid = (int) $project_id;
    $r = db_fetch_row(
        "SELECT COUNT(*) AS total, SUM(ci.is_done) AS done
         FROM project_checklist_items ci
         JOIN project_messages msg ON msg.id = ci.message_id
         WHERE msg.project_id = {$pid}"
    );
    $total = (int) ($r['total'] ?? 0);
    $done  = (int) ($r['done'] ?? 0);
    return ['total' => $total, 'done' => $done, 'percent' => $total > 0 ? round($done * 100 / $total) : 0];
}

function pm_get_project($id)
{
    $id = (int) $id;
    if ($id <= 0) return null;
    pm_ensure_schema();
    return db_fetch_row("SELECT * FROM projects WHERE id = {$id} LIMIT 1") ?: null;
}

/** Tạo dự án mới: người tạo = leader, tự tạo Session 1. */
function pm_create_project($name, $description, $creator)
{
    pm_ensure_schema();
    $name = trim((string) $name);
    $creator = (int) $creator;
    if ($name === '' || $creator <= 0) return null;
    if (mb_strlen($name, 'UTF-8') > 250) $name = mb_substr($name, 0, 250, 'UTF-8');

    $now = pm_now();
    $pid = (int) db_insert('projects', [
        'name'        => $name,
        'description' => trim((string) $description),
        'status'      => 'active',
        'created_by'  => $creator,
        'created_at'  => $now,
        'updated_at'  => $now,
    ]);
    if ($pid <= 0) return null;

    db_insert('project_members', [
        'project_id' => $pid, 'user_id' => $creator,
        'role' => 'leader', 'joined_at' => $now,
    ]);
    pm_create_session($pid, 'Session 1', $creator);
    return pm_get_project($pid);
}

function pm_update_project($pid, $name, $description)
{
    $pid = (int) $pid;
    if ($pid <= 0) return false;
    $data = ['updated_at' => pm_now()];
    $name = trim((string) $name);
    if ($name !== '') $data['name'] = mb_substr($name, 0, 250, 'UTF-8');
    $data['description'] = trim((string) $description);
    db_update('projects', $data, "id = {$pid}");
    return true;
}

function pm_archive_project($pid)
{
    $pid = (int) $pid;
    if ($pid <= 0) return false;
    db_update('projects', ['status' => 'archived', 'updated_at' => pm_now()], "id = {$pid}");
    return true;
}

function pm_touch_project($pid)
{
    $pid = (int) $pid;
    if ($pid > 0) db_update('projects', ['updated_at' => pm_now()], "id = {$pid}");
}

/* ============================================================
 *  THÀNH VIÊN & QUYỀN
 * ============================================================ */

function pm_is_member($project_id, $uid)
{
    $pid = (int) $project_id; $uid = (int) $uid;
    if ($pid <= 0 || $uid <= 0) return false;
    $r = db_fetch_row("SELECT id FROM project_members WHERE project_id = {$pid} AND user_id = {$uid} LIMIT 1");
    return (bool) $r;
}

function pm_is_leader($project_id, $uid)
{
    $pid = (int) $project_id; $uid = (int) $uid;
    if ($pid <= 0 || $uid <= 0) return false;
    $r = db_fetch_row("SELECT id FROM project_members WHERE project_id = {$pid} AND user_id = {$uid} AND role = 'leader' LIMIT 1");
    return (bool) $r;
}

function pm_members($project_id)
{
    $pid = (int) $project_id;
    $rows = db_fetch_array(
        "SELECT m.user_id, m.role, m.joined_at, u.fullname, u.username, u.avatar
         FROM project_members m
         JOIN tbl_users u ON u.id = m.user_id
         WHERE m.project_id = {$pid}
         ORDER BY (m.role = 'leader') DESC, u.fullname"
    ) ?: [];
    $out = [];
    foreach ($rows as $r) {
        $uid = (int) $r['user_id'];
        $out[] = [
            'user_id'   => $uid,
            'fullname'  => pm_display_name($uid),
            'username'  => $r['username'],
            'avatar'    => pm_avatar_url($r['avatar']),
            'role'      => $r['role'],
            'is_leader' => $r['role'] === 'leader',
        ];
    }
    return $out;
}

/** Leader thêm thành viên. Trả số người thực thêm. */
function pm_add_members($project_id, $actor_id, array $user_ids)
{
    $pid = (int) $project_id;
    if (!pm_is_leader($pid, $actor_id)) return ['ok' => false, 'message' => 'Chỉ trưởng dự án mới thêm được thành viên.'];
    $added = 0; $names = [];
    foreach ($user_ids as $uid) {
        $uid = (int) $uid;
        if ($uid <= 0 || pm_is_member($pid, $uid)) continue;
        db_insert('project_members', [
            'project_id' => $pid, 'user_id' => $uid,
            'role' => 'member', 'joined_at' => pm_now(),
        ]);
        $added++;
        $names[] = pm_user_brief($uid)['fullname'];
    }
    if ($added > 0) {
        $who = pm_user_brief((int) $actor_id)['fullname'];
        pm_system_message_all_sessions($pid, $who . ' đã thêm ' . implode(', ', $names) . ' vào dự án.', $actor_id);
        pm_touch_project($pid);
    }
    return ['ok' => true, 'added' => $added];
}

/* ============================================================
 *  LỜI MỜI THAM GIA (project_invites) — mời → chuông → chấp nhận
 * ============================================================ */

/** Leader mời thành viên. Tạo/đặt lại lời mời 'pending'. Trả danh sách người vừa mời. */
function pm_invite_members($project_id, $actor_id, array $user_ids)
{
    $pid = (int) $project_id;
    if (!pm_is_leader($pid, $actor_id)) return ['ok' => false, 'message' => 'Chỉ trưởng dự án mới mời được thành viên.'];
    $invited = [];
    foreach ($user_ids as $uid) {
        $uid = (int) $uid;
        if ($uid <= 0 || pm_is_member($pid, $uid)) continue;
        $cur = db_fetch_row("SELECT id, status FROM project_invites WHERE project_id = {$pid} AND user_id = {$uid} LIMIT 1");
        if ($cur && $cur['status'] === 'pending') continue;   // đã mời, đang chờ
        if ($cur) {
            db_update('project_invites',
                ['status' => 'pending', 'invited_by' => (int) $actor_id, 'created_at' => pm_now(), 'responded_at' => null],
                "id = " . (int) $cur['id']);
        } else {
            db_insert('project_invites', [
                'project_id' => $pid, 'user_id' => $uid, 'invited_by' => (int) $actor_id,
                'status' => 'pending', 'created_at' => pm_now(),
            ]);
        }
        $invited[] = ['id' => $uid, 'fullname' => pm_user_brief($uid)['fullname']];
    }
    return ['ok' => true, 'invited' => $invited];
}

/** Danh sách user_id đang được mời (pending) của 1 dự án. */
function pm_pending_invite_ids($project_id)
{
    $pid = (int) $project_id;
    $rows = db_fetch_array("SELECT user_id FROM project_invites WHERE project_id = {$pid} AND status = 'pending'") ?: [];
    return array_map(static fn($r) => (int) $r['user_id'], $rows);
}

/** Người được mời chấp nhận → trở thành thành viên. Trả kèm invited_by để báo người mời. */
function pm_accept_invite($project_id, $user_id)
{
    $pid = (int) $project_id; $uid = (int) $user_id;
    if ($pid <= 0 || $uid <= 0) return ['ok' => false, 'message' => 'Thiếu thông tin.'];
    $inv = db_fetch_row("SELECT id, invited_by, status FROM project_invites WHERE project_id = {$pid} AND user_id = {$uid} LIMIT 1");
    if (!$inv || $inv['status'] !== 'pending') {
        if (pm_is_member($pid, $uid)) return ['ok' => true, 'already' => true, 'invited_by' => 0];
        return ['ok' => false, 'message' => 'Lời mời không còn hiệu lực.'];
    }
    if (!pm_is_member($pid, $uid)) {
        db_insert('project_members', [
            'project_id' => $pid, 'user_id' => $uid, 'role' => 'member', 'joined_at' => pm_now(),
        ]);
    }
    db_update('project_invites', ['status' => 'accepted', 'responded_at' => pm_now()], "id = " . (int) $inv['id']);
    $name = pm_user_brief($uid)['fullname'];
    pm_system_message_all_sessions($pid, $name . ' đã tham gia dự án.', $uid);
    pm_touch_project($pid);
    return ['ok' => true, 'invited_by' => (int) $inv['invited_by']];
}

/** Tất cả lời mời đang chờ của 1 user → [{project_id, invited_by}]. */
function pm_pending_invites_of_user($user_id)
{
    $uid = (int) $user_id;
    if ($uid <= 0) return [];
    return db_fetch_array("SELECT project_id, invited_by FROM project_invites WHERE user_id = {$uid} AND status = 'pending'") ?: [];
}

/** Bỏ qua TẤT CẢ lời mời đang chờ (khi user xóa hết thông báo). Trả danh sách để báo người mời. */
function pm_skip_all_invites($user_id)
{
    $uid = (int) $user_id;
    if ($uid <= 0) return [];
    $rows = pm_pending_invites_of_user($uid);
    if ($rows) db_update('project_invites', ['status' => 'declined', 'responded_at' => pm_now()],
        "user_id = {$uid} AND status = 'pending'");
    return $rows;
}

/**
 * Đóng 1 lời mời đang chờ (từ chối HOẶC bỏ qua) → status='declined' để có thể mời lại.
 * Trả invited_by của lời mời để controller đẩy chuông cho người mời (0 nếu không còn pending).
 */
function pm_decline_invite($project_id, $user_id)
{
    $pid = (int) $project_id; $uid = (int) $user_id;
    if ($pid <= 0 || $uid <= 0) return ['ok' => false, 'message' => 'Thiếu thông tin.'];
    $inv = db_fetch_row("SELECT id, invited_by, status FROM project_invites WHERE project_id = {$pid} AND user_id = {$uid} LIMIT 1");
    if (!$inv || $inv['status'] !== 'pending') return ['ok' => true, 'invited_by' => 0]; // đã xử lý trước đó
    db_update('project_invites', ['status' => 'declined', 'responded_at' => pm_now()], "id = " . (int) $inv['id']);
    return ['ok' => true, 'invited_by' => (int) $inv['invited_by']];
}

function pm_remove_member($project_id, $actor_id, $target_id)
{
    $pid = (int) $project_id; $target = (int) $target_id;
    if (!pm_is_leader($pid, $actor_id)) return ['ok' => false, 'message' => 'Chỉ trưởng dự án mới xóa được thành viên.'];
    if (pm_is_leader($pid, $target)) return ['ok' => false, 'message' => 'Không thể xóa trưởng dự án.'];
    db_delete('project_members', "project_id = {$pid} AND user_id = {$target}");
    $who = pm_user_brief((int) $actor_id)['fullname'];
    $name = pm_user_brief($target)['fullname'];
    pm_system_message_all_sessions($pid, $who . ' đã xóa ' . $name . ' khỏi dự án.', $actor_id);
    return ['ok' => true];
}

/** Trao quyền trưởng dự án cho thành viên khác (người trao thành member). */
function pm_transfer_leader($project_id, $actor_id, $target_id)
{
    $pid = (int) $project_id; $actor = (int) $actor_id; $target = (int) $target_id;
    if (!pm_is_leader($pid, $actor)) return ['ok' => false, 'message' => 'Chỉ trưởng dự án mới trao quyền.'];
    if (!pm_is_member($pid, $target)) return ['ok' => false, 'message' => 'Người nhận chưa phải thành viên.'];
    if ($actor === $target) return ['ok' => false, 'message' => 'Đã là trưởng dự án.'];
    db_update('project_members', ['role' => 'member'], "project_id = {$pid} AND user_id = {$actor}");
    db_update('project_members', ['role' => 'leader'], "project_id = {$pid} AND user_id = {$target}");
    $name = pm_user_brief($target)['fullname'];
    pm_system_message_all_sessions($pid, pm_user_brief($actor)['fullname'] . ' đã trao quyền trưởng dự án cho ' . $name . '.', $actor);
    return ['ok' => true];
}

/**
 * Rời dự án (giống rời nhóm chat).
 *  - $silent = 1: rời trong im lặng (không phát tin hệ thống cho thành viên).
 *  - Nếu là TRƯỞNG: bắt buộc trao quyền cho 1 thành viên khác trước khi rời
 *    (truyền $new_leader_id). Nếu thiếu → trả need_leader + danh sách thành viên.
 *  - Nếu là trưởng & là thành viên duy nhất → không thể rời (gợi ý lưu trữ).
 */
function pm_leave_project($project_id, $uid, $silent = 0, $new_leader_id = 0)
{
    $pid = (int) $project_id; $u = (int) $uid; $silent = $silent ? 1 : 0;
    if (!pm_is_member($pid, $u)) return ['ok' => false, 'message' => 'Bạn không thuộc dự án này.'];
    $wasLeader = pm_is_leader($pid, $u);
    $name = pm_user_brief($u)['fullname'];
    $newLeaderName = '';

    if ($wasLeader) {
        $others = array_values(array_filter(pm_members($pid), static fn($m) => (int) $m['user_id'] !== $u));
        if (!$others) {
            return ['ok' => false, 'message' => 'Bạn là trưởng và là thành viên duy nhất — không thể trao quyền. Hãy lưu trữ dự án thay vì rời.'];
        }
        $newId = (int) $new_leader_id;
        $valid = $newId > 0 && $newId !== $u && pm_is_member($pid, $newId);
        if (!$valid) {
            return ['ok' => false, 'need_leader' => true, 'members' => $others]; // FE chọn trưởng mới
        }
        db_update('project_members', ['role' => 'member'], "project_id = {$pid} AND user_id = {$u}");
        db_update('project_members', ['role' => 'leader'], "project_id = {$pid} AND user_id = {$newId}");
        $newLeaderName = pm_user_brief($newId)['fullname'];
    }

    db_delete('project_members', "project_id = {$pid} AND user_id = {$u}");
    db_delete('project_user_state', "project_id = {$pid} AND user_id = {$u}"); // dọn ghi nhớ cá nhân

    if (!$silent) {
        $msg = $wasLeader
            ? ($name . ' đã trao quyền trưởng dự án cho ' . $newLeaderName . ' và rời dự án.')
            : ($name . ' đã rời dự án.');
        pm_system_message_all_sessions($pid, $msg, $u);
    }
    return ['ok' => true];
}

/* ============================================================
 *  SESSION (project_sessions)
 * ============================================================ */

function pm_sessions($project_id)
{
    $pid = (int) $project_id;
    return db_fetch_array(
        "SELECT id, name, sort_order, created_by, created_at, updated_at
         FROM project_sessions WHERE project_id = {$pid}
         ORDER BY sort_order ASC, id ASC"
    ) ?: [];
}

function pm_get_session($session_id)
{
    $sid = (int) $session_id;
    return db_fetch_row("SELECT * FROM project_sessions WHERE id = {$sid} LIMIT 1") ?: null;
}

/** Tạo session; tên rỗng → tự đặt "Session N". */
function pm_create_session($project_id, $name, $creator)
{
    $pid = (int) $project_id;
    $name = trim((string) $name);
    $row = db_fetch_row("SELECT COUNT(*) AS c, MAX(sort_order) AS m FROM project_sessions WHERE project_id = {$pid}");
    $count = (int) ($row['c'] ?? 0);
    $order = ($row && $row['m'] !== null) ? ((int) $row['m'] + 1) : 0;
    if ($name === '') $name = 'Session ' . ($count + 1);
    if (mb_strlen($name, 'UTF-8') > 250) $name = mb_substr($name, 0, 250, 'UTF-8');
    $now = pm_now();
    $sid = (int) db_insert('project_sessions', [
        'project_id' => $pid, 'name' => $name, 'sort_order' => $order,
        'created_by' => (int) $creator, 'created_at' => $now, 'updated_at' => $now,
    ]);
    if ($sid <= 0) return null;
    return ['id' => $sid, 'name' => $name, 'sort_order' => $order];
}

function pm_rename_session($session_id, $name)
{
    $sid = (int) $session_id;
    $name = trim((string) $name);
    if ($sid <= 0 || $name === '') return false;
    db_update('project_sessions', ['name' => mb_substr($name, 0, 250, 'UTF-8'), 'updated_at' => pm_now()], "id = {$sid}");
    return true;
}

/** Xóa session + toàn bộ dữ liệu liên quan (tin nhắn, canvas...). */
function pm_delete_session($session_id)
{
    $sid = (int) $session_id;
    if ($sid <= 0) return false;
    $msgs = db_fetch_array("SELECT id FROM project_messages WHERE session_id = {$sid}") ?: [];
    foreach ($msgs as $m) {
        $mid = (int) $m['id'];
        db_delete('project_attachments', "message_id = {$mid}");
        db_delete('project_reactions', "message_id = {$mid}");
        db_delete('project_stars', "message_id = {$mid}");
        db_delete('project_checklist_items', "message_id = {$mid}");
        db_delete('project_pins', "message_id = {$mid}");
        db_delete('project_votes', "message_id = {$mid}");
    }
    db_delete('project_messages', "session_id = {$sid}");
    db_delete('project_reminders', "session_id = {$sid}");
    db_delete('project_canvas', "session_id = {$sid}");
    db_delete('project_sessions', "id = {$sid}");
    db_delete('project_user_state', "last_session_id = {$sid}"); // xóa ghi nhớ trỏ tới session đã xóa
    return true;
}

/** Ghi nhớ session đang làm việc gần nhất của 1 user trong 1 dự án (cá nhân hóa). */
function pm_set_last_session($project_id, $session_id, $user_id)
{
    $pid = (int) $project_id; $sid = (int) $session_id; $u = (int) $user_id;
    if ($pid <= 0 || $u <= 0) return false;
    $s = db_fetch_row("SELECT id FROM project_sessions WHERE id = {$sid} AND project_id = {$pid} LIMIT 1");
    if (!$s) return false;
    $now = pm_now();
    $exist = db_fetch_row("SELECT user_id FROM project_user_state WHERE user_id = {$u} AND project_id = {$pid} LIMIT 1");
    if ($exist) {
        db_update('project_user_state', ['last_session_id' => $sid, 'updated_at' => $now], "user_id = {$u} AND project_id = {$pid}");
    } else {
        db_insert('project_user_state', ['user_id' => $u, 'project_id' => $pid, 'last_session_id' => $sid, 'updated_at' => $now]);
    }
    return true;
}

/** Session làm việc gần nhất của user (0 nếu chưa có / đã bị xóa). */
function pm_get_last_session($project_id, $user_id)
{
    $pid = (int) $project_id; $u = (int) $user_id;
    if ($pid <= 0 || $u <= 0) return 0;
    $r = db_fetch_row("SELECT last_session_id FROM project_user_state WHERE user_id = {$u} AND project_id = {$pid} LIMIT 1");
    if (!$r) return 0;
    $sid = (int) $r['last_session_id'];
    if ($sid <= 0) return 0;
    $s = db_fetch_row("SELECT id FROM project_sessions WHERE id = {$sid} AND project_id = {$pid} LIMIT 1");
    return $s ? $sid : 0; // session có thể đã bị xóa → fallback
}

/* ============================================================
 *  TIN NHẮN (project_messages)
 * ============================================================ */

/** Chèn 1 tin nhắn thô. Trả message_id. */
function pm_insert_message($session_id, $project_id, $sender_id, $body, $type = 'text', $payload = null, $reply_to_id = 0, $forward_from_id = 0)
{
    $mid = (int) db_insert('project_messages', [
        'session_id'      => (int) $session_id,
        'project_id'      => (int) $project_id,
        'sender_id'       => (int) $sender_id,
        'body'            => ($body === '' ? null : $body),
        'type'            => $type,
        'payload'         => $payload,
        'reply_to_id'     => (int) $reply_to_id,
        'forward_from_id' => (int) $forward_from_id,
        'recalled'        => 0,
        'created_at'      => pm_now(),
    ]);
    pm_touch_project((int) $project_id);
    return $mid;
}

/** Tin hệ thống cho mọi session của 1 dự án (vd: thêm/xóa thành viên). */
function pm_system_message_all_sessions($project_id, $text, $actor_id)
{
    $pid = (int) $project_id;
    $sessions = pm_sessions($pid);
    foreach ($sessions as $s) {
        pm_insert_message((int) $s['id'], $pid, (int) $actor_id, $text, 'system');
    }
}

/**
 * Seed nội dung mẫu cho session mới theo template:
 *  - db      : 1 tin "bảng" mô phỏng field DB.
 *  - module  : 1 tin "sơ đồ cây" module/view.
 *  - process : 1 tin "quy trình" các bước.
 * Trả về true nếu có seed.
 */
function pm_seed_template($session_id, $project_id, $user_id, $template)
{
    $sid = (int) $session_id; $pid = (int) $project_id; $uid = (int) $user_id;
    switch ($template) {
        case 'db':
            $payload = json_encode([
                'name' => 'ten_bang',
                'columns' => ['Field', 'Kiểu', 'Ghi chú'],
                'rows' => [
                    ['id', 'INT', 'PK, AUTO_INCREMENT'],
                    ['name', 'VARCHAR(255)', ''],
                    ['created_at', 'DATETIME', ''],
                ],
            ], JSON_UNESCAPED_UNICODE);
            pm_insert_message($sid, $pid, $uid, 'Khung thiết kế CSDL — chỉnh sửa field cho phù hợp.', 'table', $payload);
            return true;
        case 'module':
            $payload = json_encode([
                'nodes' => [
                    ['level' => 0, 'label' => 'Module'],
                    ['level' => 1, 'label' => 'View danh sách'],
                    ['level' => 1, 'label' => 'View chi tiết'],
                    ['level' => 2, 'label' => 'AJAX / API'],
                ],
            ], JSON_UNESCAPED_UNICODE);
            pm_insert_message($sid, $pid, $uid, 'Khung sơ đồ module/view — bổ sung các node.', 'tree', $payload);
            return true;
        case 'process':
            $payload = json_encode([
                'steps' => ['Phân tích yêu cầu', 'Thiết kế CSDL', 'Lập trình', 'Kiểm thử', 'Triển khai'],
            ], JSON_UNESCAPED_UNICODE);
            pm_insert_message($sid, $pid, $uid, 'Khung quy trình — điều chỉnh các bước.', 'process', $payload);
            return true;
    }
    return false;
}

/** Attachments cho 1 tập tin nhắn. Trả [mid => [att,...]]. */
function pm_attachments_for(array $message_ids)
{
    $ids = array_values(array_filter(array_map('intval', $message_ids), static fn($v) => $v > 0));
    if (!$ids) return [];
    $in = implode(',', $ids);
    $rows = db_fetch_array("SELECT * FROM project_attachments WHERE message_id IN ({$in})") ?: [];
    $out = [];
    foreach ($rows as $r) {
        $mid = (int) $r['message_id'];
        $out[$mid][] = [
            'id'            => (int) $r['id'],
            'url'           => 'public/uploads/project/' . $r['file_name'],
            'original_name' => $r['original_name'],
            'mime'          => (string) ($r['mime'] ?? ''),
            'size'          => (int) $r['size'],
            'is_image'      => (int) $r['is_image'] === 1,
        ];
    }
    return $out;
}

/** Cảm xúc của 1 tập tin → [mid => [{emoji,count,mine,users}]]. */
function pm_reactions_for(array $message_ids, $me_id = 0)
{
    $ids = array_values(array_filter(array_map('intval', $message_ids), static fn($v) => $v > 0));
    if (!$ids) return [];
    $in = implode(',', $ids);
    $me = (int) $me_id;
    $rows = db_fetch_array("SELECT message_id, emoji, user_id FROM project_reactions WHERE message_id IN ({$in})") ?: [];
    $tmp = [];
    foreach ($rows as $r) {
        $mid = (int) $r['message_id']; $em = $r['emoji']; $uid = (int) $r['user_id'];
        if (!isset($tmp[$mid][$em])) $tmp[$mid][$em] = ['emoji' => $em, 'count' => 0, 'mine' => false, 'users' => []];
        $tmp[$mid][$em]['count']++;
        $isMine = $uid === $me;
        if ($isMine) $tmp[$mid][$em]['mine'] = true;
        // tên hiển thị tooltip: bản thân = "Bạn", còn lại theo danh xưng người xem đã đặt
        $tmp[$mid][$em]['users'][] = $isMine ? 'Bạn' : pm_display_name($uid, $me);
    }
    $out = [];
    foreach ($tmp as $mid => $byEmoji) $out[$mid] = array_values($byEmoji);
    return $out;
}

/** Tập message_id đã gắn sao (trong 1 tập). */
function pm_starred_set(array $message_ids, $me_id = 0)
{
    $me = (int) $me_id;
    if ($me <= 0) return [];
    $ids = array_values(array_filter(array_map('intval', $message_ids), static fn($v) => $v > 0));
    if (!$ids) return [];
    $in = implode(',', $ids);
    $rows = db_fetch_array("SELECT message_id, note FROM project_stars WHERE message_id IN ({$in}) AND starred_by = {$me}") ?: [];
    $set = [];
    foreach ($rows as $r) $set[(int) $r['message_id']] = (string) ($r['note'] ?? ''); // value = mô tả ('' nếu không có)
    return $set;
}

/** Tập message_id đã ghim (trong 1 tập). */
function pm_pinned_set(array $message_ids)
{
    $ids = array_values(array_filter(array_map('intval', $message_ids), static fn($v) => $v > 0));
    if (!$ids) return [];
    $in = implode(',', $ids);
    $rows = db_fetch_array("SELECT message_id FROM project_pins WHERE message_id IN ({$in})") ?: [];
    $set = [];
    foreach ($rows as $r) $set[(int) $r['message_id']] = true;
    return $set;
}

/** Bình chọn của 1 tập tin → [mid => { opt_index => {count, mine, voters[]} }]. */
function pm_votes_for(array $message_ids, $me_id = 0)
{
    $ids = array_values(array_filter(array_map('intval', $message_ids), static fn($v) => $v > 0));
    if (!$ids) return [];
    $in = implode(',', $ids);
    $me = (int) $me_id;
    $rows = db_fetch_array("SELECT message_id, opt_index, user_id FROM project_votes WHERE message_id IN ({$in})") ?: [];
    $out = [];
    foreach ($rows as $r) {
        $mid = (int) $r['message_id']; $oi = (int) $r['opt_index']; $u = (int) $r['user_id'];
        if (!isset($out[$mid][$oi])) $out[$mid][$oi] = ['count' => 0, 'mine' => false, 'voters' => []];
        $out[$mid][$oi]['count']++;
        $out[$mid][$oi]['voters'][] = pm_display_name($u, $me);
        if ($u === $me) $out[$mid][$oi]['mine'] = true;
    }
    return $out;
}

/** Checklist items theo tập tin → [mid => [items]]. */
function pm_checklists_for(array $message_ids)
{
    $ids = array_values(array_filter(array_map('intval', $message_ids), static fn($v) => $v > 0));
    if (!$ids) return [];
    $in = implode(',', $ids);
    $rows = db_fetch_array("SELECT * FROM project_checklist_items WHERE message_id IN ({$in}) ORDER BY sort_order ASC, id ASC") ?: [];
    $out = [];
    foreach ($rows as $r) {
        $out[(int) $r['message_id']][] = [
            'id'      => (int) $r['id'],
            'content' => $r['content'],
            'is_done' => (int) $r['is_done'] === 1,
            'done_by' => (int) $r['done_by'],
            'done_by_name' => (int) $r['done_by'] > 0 ? pm_display_name((int) $r['done_by']) : '',
        ];
    }
    return $out;
}

/** Trích dẫn gọn cho tin được trả lời (tên theo biệt danh của người xem). */
function pm_reply_previews(array $message_ids, $viewer_id = null)
{
    $ids = array_values(array_filter(array_map('intval', $message_ids), static fn($v) => $v > 0));
    if (!$ids) return [];
    $in = implode(',', array_unique($ids));
    $rows = db_fetch_array("SELECT id, sender_id, body, type, recalled FROM project_messages WHERE id IN ({$in})") ?: [];
    $out = [];
    foreach ($rows as $r) {
        $recalled = (int) ($r['recalled'] ?? 0) === 1;
        if ($recalled)                                   $preview = 'Tin nhắn đã thu hồi';
        elseif (trim((string) ($r['body'] ?? '')) !== '') $preview = (string) $r['body'];
        else                                              $preview = pm_type_label($r['type']);
        if (mb_strlen($preview, 'UTF-8') > 120) $preview = mb_substr($preview, 0, 120, 'UTF-8') . '…';
        $out[(int) $r['id']] = [
            'id' => (int) $r['id'], 'sender_name' => pm_display_name((int) $r['sender_id'], $viewer_id),
            'preview' => $preview, 'recalled' => $recalled,
        ];
    }
    return $out;
}

function pm_type_label($type)
{
    switch ($type) {
        case 'image':     return '[Hình ảnh]';
        case 'file':      return '[Tệp đính kèm]';
        case 'checklist': return '[Danh sách kiểm tra]';
        case 'table':     return '[Bảng dữ liệu]';
        case 'tree':      return '[Sơ đồ cây]';
        case 'process':   return '[Quy trình]';
        case 'canvas':    return '[Bản thiết kế]';
        default:          return '[Tệp đính kèm]';
    }
}

/** Định dạng đầy đủ 1 tập dòng message (kèm người gửi, đính kèm, cảm xúc, sao, checklist, reply, payload). */
function pm_format_messages(array $rows, $me_id = 0)
{
    if (!$rows) return [];
    $me   = (int) $me_id;
    $ids  = array_map(static fn($r) => (int) $r['id'], $rows);
    $atts = pm_attachments_for($ids);
    $reax = pm_reactions_for($ids, $me);
    $star = pm_starred_set($ids, $me);
    $pin  = pm_pinned_set($ids);
    $clk  = pm_checklists_for($ids);
    $votes = pm_votes_for($ids, $me);

    $replyIds = [];
    foreach ($rows as $r) { $rid = (int) ($r['reply_to_id'] ?? 0); if ($rid > 0) $replyIds[] = $rid; }
    $replies = pm_reply_previews($replyIds, $me);

    $out = [];
    foreach ($rows as $r) {
        $mid      = (int) $r['id'];
        $recalled = (int) ($r['recalled'] ?? 0) === 1;
        $brief    = pm_user_brief((int) $r['sender_id']);
        $rid      = (int) ($r['reply_to_id'] ?? 0);
        $canRecall = !$recalled
            && $me > 0 && (int) $r['sender_id'] === $me
            && $r['type'] !== 'system'
            && strtotime($r['created_at']) >= time() - PM_RECALL_WINDOW;
        $payload = null;
        if (!$recalled && $r['payload'] !== null && $r['payload'] !== '') {
            $payload = json_decode($r['payload'], true);
        }
        $out[] = [
            'id'          => $mid,
            'session_id'  => (int) $r['session_id'],
            'sender_id'   => (int) $r['sender_id'],
            'sender_name' => pm_display_name((int) $r['sender_id'], $me),
            'sender_avatar' => $brief['avatar'],
            'body'        => $recalled ? '' : (string) ($r['body'] ?? ''),
            'type'        => $r['type'],
            'payload'     => $recalled ? null : $payload,
            'checklist'   => $recalled ? [] : ($clk[$mid] ?? []),
            'created_at'  => $r['created_at'],
            'recalled'    => $recalled,
            'can_recall'  => $canRecall,
            'forwarded'   => (int) ($r['forward_from_id'] ?? 0) > 0,
            'attachments' => $recalled ? [] : ($atts[$mid] ?? []),
            'reactions'   => $reax[$mid] ?? [],
            'starred'     => isset($star[$mid]),
            'star_note'   => isset($star[$mid]) ? $star[$mid] : '',
            'pinned'      => isset($pin[$mid]),
            'votes'       => ($r['type'] === 'vote' && !$recalled) ? (object) ($votes[$mid] ?? []) : null,
            'reply_to'    => ($rid > 0 ? ($replies[$rid] ?? null) : null),
        ];
    }
    return $out;
}

/**
 * Lấy tin nhắn của 1 session.
 *  - before_id > 0 : tin CŨ hơn (cuộn lên).
 *  - after_id  > 0 : tin MỚI hơn (poll).
 *  - starred_only  : chỉ tin đã gắn sao.
 * Luôn trả theo thứ tự cũ → mới.
 */
/** Tìm tin nhắn theo từ khóa trong 1 session (giống tìm kiếm trong hộp chat). */
function pm_search_messages($session_id, $keyword, $limit = 50, $me_id = 0)
{
    pm_ensure_schema();
    $sid = (int) $session_id;
    $kw = trim((string) $keyword);
    if ($kw === '') return [];
    $esc = escape_string($kw);
    $lim = max(1, min(100, (int) $limit));
    $rows = db_fetch_array(
        "SELECT * FROM project_messages
         WHERE session_id = {$sid} AND type <> 'system' AND recalled = 0
           AND body LIKE '%{$esc}%'
         ORDER BY id DESC LIMIT {$lim}"
    ) ?: [];
    return pm_format_messages($rows, (int) $me_id);
}

function pm_messages($session_id, $limit = 25, $before_id = 0, $after_id = 0, $me_id = 0, $starred_only = false)
{
    pm_ensure_schema();
    $sid = (int) $session_id;
    $lim = max(1, min(100, (int) $limit));
    $before = (int) $before_id;
    $after  = (int) $after_id;
    $me = (int) $me_id;

    $starJoin = $starred_only ? "JOIN project_stars st ON st.message_id = m.id AND st.starred_by = {$me}" : '';

    if ($after > 0) {
        $rows = db_fetch_array(
            "SELECT m.* FROM project_messages m {$starJoin}
             WHERE m.session_id = {$sid} AND m.id > {$after}
             ORDER BY m.id ASC LIMIT {$lim}"
        ) ?: [];
        return pm_format_messages($rows, $me);
    }
    $cond = "m.session_id = {$sid}";
    if ($before > 0) $cond .= " AND m.id < {$before}";
    $rows = db_fetch_array(
        "SELECT m.* FROM project_messages m {$starJoin}
         WHERE {$cond}
         ORDER BY m.id DESC LIMIT {$lim}"
    ) ?: [];
    $rows = array_reverse($rows);
    return pm_format_messages($rows, $me);
}

/** Cập nhật động khi poll: cảm xúc + đã thu hồi + sao + checklist của các tin gần đây. */
function pm_recent_updates($session_id, $me_id, $limit = 100)
{
    $sid = (int) $session_id;
    $me  = (int) $me_id;
    $lim = max(1, min(200, (int) $limit));
    $rows = db_fetch_array("SELECT id, recalled FROM project_messages WHERE session_id = {$sid} ORDER BY id DESC LIMIT {$lim}") ?: [];
    $ids = array_map(static fn($r) => (int) $r['id'], $rows);
    $recalled = [];
    foreach ($rows as $r) if ((int) $r['recalled'] === 1) $recalled[] = (int) $r['id'];
    return [
        'reactions' => pm_reactions_for($ids, $me),
        'recalled'  => $recalled,
        'starred'   => array_keys(pm_starred_set($ids, $me)),
        'pinned'    => array_keys(pm_pinned_set($ids)),
        'checklist' => pm_checklists_for($ids),
        'votes'     => (object) pm_votes_for($ids, $me),
    ];
}

/* ============================================================
 *  "Đã xem" (seen) — mỗi user lưu tin mới nhất đã đọc / session
 * ============================================================ */

/** Ghi nhận user đã đọc tới tin mới nhất của session (chỉ tăng, không lùi). */
function pm_mark_read($session_id, $user_id, $up_to_id = 0)
{
    $sid = (int) $session_id; $uid = (int) $user_id; $upto = (int) $up_to_id;
    if ($sid <= 0 || $uid <= 0) return;
    if ($upto <= 0) {
        $row = db_fetch_row("SELECT MAX(id) AS mx FROM project_messages WHERE session_id = {$sid}");
        $upto = $row ? (int) $row['mx'] : 0;
    }
    if ($upto <= 0) return;
    $now = pm_now();
    $exists = db_fetch_row("SELECT last_read_message_id FROM project_reads WHERE session_id = {$sid} AND user_id = {$uid} LIMIT 1");
    if ($exists) {
        // chỉ cập nhật khi đọc tới tin mới hơn
        db_update('project_reads',
            ['last_read_message_id' => $upto, 'last_read_at' => $now],
            "session_id = {$sid} AND user_id = {$uid} AND last_read_message_id < {$upto}");
    } else {
        db_insert('project_reads', [
            'session_id' => $sid, 'user_id' => $uid,
            'last_read_message_id' => $upto, 'last_read_at' => $now,
        ]);
    }
}

/**
 * Người ĐÃ XEM trong 1 session (loại trừ tôi): [{user_id, fullname, avatar, last_read_message_id}].
 * Chỉ lấy người là thành viên dự án và đã đọc ít nhất 1 tin.
 */
function pm_readers($session_id, $me_id)
{
    $sid = (int) $session_id; $me = (int) $me_id;
    if ($sid <= 0) return [];
    $rows = db_fetch_array(
        "SELECT r.user_id, r.last_read_message_id, u.avatar
         FROM project_reads r
         JOIN project_sessions s ON s.id = r.session_id
         JOIN project_members m ON m.project_id = s.project_id AND m.user_id = r.user_id
         JOIN tbl_users u ON u.id = r.user_id
         WHERE r.session_id = {$sid} AND r.user_id <> {$me} AND r.last_read_message_id > 0
         ORDER BY r.last_read_at DESC"
    ) ?: [];
    $out = [];
    foreach ($rows as $r) {
        $uid = (int) $r['user_id'];
        $out[] = [
            'user_id'              => $uid,
            'fullname'             => pm_display_name($uid, $me),
            'avatar'               => pm_avatar_url($r['avatar']),
            'last_read_message_id' => (int) $r['last_read_message_id'],
        ];
    }
    return $out;
}

function pm_get_message($message_id)
{
    $mid = (int) $message_id;
    return db_fetch_row("SELECT * FROM project_messages WHERE id = {$mid} LIMIT 1") ?: null;
}

/** Thu hồi 1 tin (chỉ người gửi, trong 1 giờ). */
function pm_recall_message($message_id, $user_id)
{
    $mid = (int) $message_id; $uid = (int) $user_id;
    $m = pm_get_message($mid);
    if (!$m || (int) $m['sender_id'] !== $uid) return ['ok' => false, 'message' => 'Không thể thu hồi tin này.'];
    if ((int) ($m['recalled'] ?? 0) === 1) return ['ok' => true];
    if (strtotime($m['created_at']) < time() - PM_RECALL_WINDOW) {
        return ['ok' => false, 'message' => 'Chỉ có thể thu hồi trong vòng 1 giờ sau khi gửi.'];
    }
    db_update('project_messages', ['recalled' => 1, 'body' => null, 'payload' => null], "id = {$mid}");
    db_delete('project_attachments', "message_id = {$mid}");
    db_delete('project_reactions', "message_id = {$mid}");
    db_delete('project_checklist_items', "message_id = {$mid}");
    db_delete('project_pins', "message_id = {$mid}");
    db_delete('project_votes', "message_id = {$mid}");
    return ['ok' => true];
}

/** Thả / đổi / gỡ cảm xúc. */
function pm_react($message_id, $user_id, $emoji)
{
    $mid = (int) $message_id; $uid = (int) $user_id; $em = trim((string) $emoji);
    if ($mid <= 0 || $uid <= 0 || $em === '') return [];
    if (mb_strlen($em, 'UTF-8') > 8) $em = mb_substr($em, 0, 8, 'UTF-8');
    $cur = db_fetch_row("SELECT id, emoji FROM project_reactions WHERE message_id = {$mid} AND user_id = {$uid} LIMIT 1");
    if ($cur) {
        if ($cur['emoji'] === $em) db_delete('project_reactions', "id = " . (int) $cur['id']);
        else db_update('project_reactions', ['emoji' => $em, 'created_at' => pm_now()], "id = " . (int) $cur['id']);
    } else {
        db_insert('project_reactions', ['message_id' => $mid, 'user_id' => $uid, 'emoji' => $em, 'created_at' => pm_now()]);
    }
    $map = pm_reactions_for([$mid], $uid);
    return $map[$mid] ?? [];
}

/** Bật/tắt gắn sao (cá nhân hóa). Kèm mô tả tùy chọn khi gắn. Trả ['starred'=>bool, 'note'=>string]. */
function pm_star_toggle($message_id, $user_id, $note = null)
{
    $mid = (int) $message_id; $uid = (int) $user_id;
    if ($mid <= 0 || $uid <= 0) return ['starred' => false, 'note' => ''];
    $cur = db_fetch_row("SELECT id FROM project_stars WHERE message_id = {$mid} AND starred_by = {$uid} LIMIT 1");
    if ($cur) { db_delete('project_stars', "message_id = {$mid} AND starred_by = {$uid}"); return ['starred' => false, 'note' => '']; } // bỏ sao
    $note = ($note === null) ? '' : trim((string) $note);
    db_insert('project_stars', ['message_id' => $mid, 'starred_by' => $uid, 'note' => $note, 'created_at' => pm_now()]);
    return ['starred' => true, 'note' => $note];
}

/** Bật/tắt ghim tin (dùng chung cả nhóm). Trả trạng thái mới. */
function pm_pin_toggle($message_id, $user_id)
{
    $mid = (int) $message_id; $uid = (int) $user_id;
    if ($mid <= 0) return false;
    $cur = db_fetch_row("SELECT id FROM project_pins WHERE message_id = {$mid} LIMIT 1");
    if ($cur) { db_delete('project_pins', "message_id = {$mid}"); return false; }
    $m = pm_get_message($mid);
    if (!$m) return false;
    db_insert('project_pins', [
        'message_id' => $mid, 'session_id' => (int) $m['session_id'],
        'pinned_by' => $uid, 'created_at' => pm_now(),
    ]);
    return true;
}

/** Danh sách tin đã ghim trong 1 session (mới ghim trước), đã format. */
function pm_pinned_messages($session_id, $me_id = 0)
{
    $sid = (int) $session_id;
    $rows = db_fetch_array(
        "SELECT m.* FROM project_pins p
         JOIN project_messages m ON m.id = p.message_id
         WHERE p.session_id = {$sid} AND m.recalled = 0
         ORDER BY p.created_at DESC"
    ) ?: [];
    return pm_format_messages($rows, (int) $me_id);
}

/** Bình chọn 1 option. $multi=false → chỉ giữ 1 lựa chọn; bấm lại = bỏ. Trả tally mới của tin. */
function pm_vote_toggle($message_id, $opt_index, $user_id, $multi = true)
{
    $mid = (int) $message_id; $oi = (int) $opt_index; $uid = (int) $user_id;
    if ($mid <= 0 || $uid <= 0) return [];
    $cur = db_fetch_row("SELECT id FROM project_votes WHERE message_id = {$mid} AND opt_index = {$oi} AND user_id = {$uid} LIMIT 1");
    if ($cur) {
        db_delete('project_votes', "id = " . (int) $cur['id']); // bỏ chọn
    } else {
        if (!$multi) db_delete('project_votes', "message_id = {$mid} AND user_id = {$uid}"); // chọn 1: xóa lựa chọn cũ
        db_insert('project_votes', ['message_id' => $mid, 'opt_index' => $oi, 'user_id' => $uid, 'created_at' => pm_now()]);
    }
    $map = pm_votes_for([$mid], $uid);
    return $map[$mid] ?? [];
}

/** Chia sẻ lại (forward) 1 tin sang session đích. */
function pm_forward_message($message_id, $to_session_id, $actor_id)
{
    $src = pm_get_message($message_id);
    if (!$src) return ['ok' => false, 'message' => 'Tin không tồn tại.'];
    $sess = pm_get_session($to_session_id);
    if (!$sess) return ['ok' => false, 'message' => 'Session đích không tồn tại.'];
    $pid = (int) $sess['project_id'];
    if (!pm_is_member($pid, $actor_id)) return ['ok' => false, 'message' => 'Không có quyền.'];

    $newId = pm_insert_message(
        (int) $sess['id'], $pid, (int) $actor_id,
        $src['body'], $src['type'], $src['payload'], 0, (int) $src['id']
    );
    // Sao chép attachments + checklist (giữ nguyên nội dung gốc).
    $atts = db_fetch_array("SELECT * FROM project_attachments WHERE message_id = " . (int) $src['id']) ?: [];
    foreach ($atts as $a) {
        db_insert('project_attachments', [
            'message_id' => $newId, 'file_name' => $a['file_name'], 'original_name' => $a['original_name'],
            'mime' => $a['mime'], 'size' => (int) $a['size'], 'is_image' => (int) $a['is_image'],
        ]);
    }
    $clk = db_fetch_array("SELECT * FROM project_checklist_items WHERE message_id = " . (int) $src['id'] . " ORDER BY sort_order") ?: [];
    foreach ($clk as $i => $c) {
        db_insert('project_checklist_items', [
            'message_id' => $newId, 'content' => $c['content'], 'is_done' => 0, 'sort_order' => $i,
        ]);
    }
    return ['ok' => true, 'message_id' => $newId];
}

/**
 * Chia sẻ 1 tin của dự án SANG CHAT HỆ THỐNG (module chat) tới 1 người nhận (1-1).
 * Mirror fm_share_to_chat() — recipe "đưa tệp/tin từ module X vào chat hệ thống":
 *  - require_once thư viện chat (KHÔNG autoload) qua function_exists.
 *  - COPY blob đính kèm sang public/uploads/chat/ (chat tự quản vòng đời copy của nó,
 *    xoá tin gốc không làm hỏng tin chat) — không tham chiếu chéo thư mục.
 *  - chat_insert_message() TỰ mã hóa body, nên truyền body THÔ (project body vốn plaintext).
 *  - type='file' nếu có đính kèm (kể cả ảnh — is_image nằm trên dòng attachment), ngược lại 'text'.
 */
function pm_share_to_chat($message_id, $target_uid, $actor_id, $note = '')
{
    $src = pm_get_message($message_id);
    if (!$src) return ['ok' => false, 'message' => 'Tin không tồn tại.'];

    // Chỉ chia sẻ tin thuộc dự án mình là thành viên.
    $sess = pm_get_session((int) $src['session_id']);
    if (!$sess || !pm_is_member((int) $sess['project_id'], (int) $actor_id)) {
        return ['ok' => false, 'message' => 'Không có quyền chia sẻ tin này.'];
    }
    $target = (int) $target_uid;
    if ($target <= 0 || $target === (int) $actor_id) {
        return ['ok' => false, 'message' => 'Người nhận không hợp lệ.'];
    }

    if (!function_exists('chat_get_or_create_direct') || !function_exists('chat_insert_message')) {
        require_once __DIR__ . '/../../../libraries/chat.php';
    }

    $cid = chat_get_or_create_direct((int) $actor_id, $target);
    if ($cid <= 0) return ['ok' => false, 'message' => 'Không mở được hội thoại.'];

    // Đính kèm của tin gốc.
    $atts = db_fetch_array("SELECT * FROM project_attachments WHERE message_id = " . (int) $src['id']) ?: [];

    // Body: ưu tiên lời nhắn, sau đó nội dung tin gốc, cuối cùng là tên tệp đầu tiên (để tin không rỗng).
    $body = trim((string) $note);
    if ($body === '') $body = trim((string) ($src['body'] ?? ''));
    if ($body === '' && !empty($atts)) $body = (string) $atts[0]['original_name'];

    $type = !empty($atts) ? 'file' : 'text';
    $newMid = chat_insert_message($cid, (int) $actor_id, $body, $type);
    if ($newMid <= 0) return ['ok' => false, 'message' => 'Không gửi được tin.'];

    // Nhân bản từng blob project -> chat (đặt tên mới prefix 'c'); COPY TRƯỚC khi ghi dòng attachment.
    $src_dir  = APPPATH . DIRECTORY_SEPARATOR . 'public' . DIRECTORY_SEPARATOR . 'uploads' . DIRECTORY_SEPARATOR . 'project';
    $chat_dir = APPPATH . DIRECTORY_SEPARATOR . 'public' . DIRECTORY_SEPARATOR . 'uploads' . DIRECTORY_SEPARATOR . 'chat';
    if (!is_dir($chat_dir)) @mkdir($chat_dir, 0775, true);

    foreach ($atts as $a) {
        $srcPath = $src_dir . DIRECTORY_SEPARATOR . $a['file_name'];
        if (!is_file($srcPath)) continue;
        $ext = strtolower(pathinfo((string) $a['file_name'], PATHINFO_EXTENSION));
        $safe_ext = preg_replace('/[^a-z0-9]/', '', $ext);
        $newName = 'c' . time() . '_' . substr(md5($a['file_name'] . uniqid('', true)), 0, 10)
            . ($safe_ext !== '' ? '.' . $safe_ext : '');
        if (!@copy($srcPath, $chat_dir . DIRECTORY_SEPARATOR . $newName)) continue;
        db_insert('chat_attachments', [
            'message_id'    => $newMid,
            'file_name'     => $newName,
            'original_name' => mb_substr((string) $a['original_name'], 0, 250, 'UTF-8'),
            'mime'          => (string) ($a['mime'] ?? ''),
            'size'          => (int) $a['size'],
            'is_image'      => (int) $a['is_image'],
        ]);
    }

    if (function_exists('chat_mark_read')) chat_mark_read($cid, (int) $actor_id, $newMid);
    return ['ok' => true, 'message_id' => $newMid, 'conversation_id' => $cid];
}

/** Tick / bỏ tick 1 dòng checklist (dùng chung). */
function pm_checklist_toggle($item_id, $user_id, $done)
{
    $iid = (int) $item_id; $uid = (int) $user_id;
    if ($iid <= 0) return false;
    if ($done) {
        db_update('project_checklist_items', ['is_done' => 1, 'done_by' => $uid, 'done_at' => pm_now()], "id = {$iid}");
    } else {
        db_update('project_checklist_items', ['is_done' => 0, 'done_by' => 0, 'done_at' => null], "id = {$iid}");
    }
    return true;
}

/* ============================================================
 *  NHẮC HẸN (project_reminders)
 * ============================================================ */

function pm_set_reminder($user_id, $message_id, $remind_at, $note = '')
{
    $uid = (int) $user_id; $mid = (int) $message_id;
    $ts = strtotime(str_replace('T', ' ', trim((string) $remind_at)));
    if ($uid <= 0 || $mid <= 0 || !$ts) return ['ok' => false, 'message' => 'Thời gian không hợp lệ.'];
    if ($ts <= time()) return ['ok' => false, 'message' => 'Hãy chọn thời điểm trong tương lai.'];
    $m = pm_get_message($mid);
    if (!$m) return ['ok' => false, 'message' => 'Tin nhắn không tồn tại.'];
    $note = trim((string) $note);
    if (mb_strlen($note, 'UTF-8') > 1000) $note = mb_substr($note, 0, 1000, 'UTF-8');
    db_insert('project_reminders', [
        'user_id' => $uid, 'message_id' => $mid,
        'session_id' => (int) $m['session_id'], 'project_id' => (int) $m['project_id'],
        'remind_at' => date('Y-m-d H:i:s', $ts), 'note' => ($note === '' ? null : $note),
        'notified' => 0, 'created_at' => pm_now(),
    ]);
    return ['ok' => true, 'remind_at' => date('Y-m-d H:i:s', $ts)];
}

function pm_due_reminders($user_id)
{
    $uid = (int) $user_id;
    if ($uid <= 0) return [];
    $now = pm_now();
    $rows = db_fetch_array(
        "SELECT * FROM project_reminders
         WHERE user_id = {$uid} AND notified = 0 AND remind_at <= '" . escape_string($now) . "'
         ORDER BY remind_at ASC LIMIT 20"
    ) ?: [];
    $out = [];
    foreach ($rows as $r) {
        db_update('project_reminders', ['notified' => 1], "id = " . (int) $r['id']);
        $mrow = db_fetch_array("SELECT * FROM project_messages WHERE id = " . (int) $r['message_id'] . " LIMIT 1") ?: [];
        $fmt = $mrow ? pm_format_messages($mrow, $uid) : [];
        $sess = pm_get_session((int) $r['session_id']);
        $proj = pm_get_project((int) $r['project_id']);
        $out[] = [
            'id'           => (int) $r['id'],
            'project_id'   => (int) $r['project_id'],
            'project_name' => $proj ? $proj['name'] : 'Dự án',
            'session_id'   => (int) $r['session_id'],
            'session_name' => $sess ? $sess['name'] : 'Session',
            'message_id'   => (int) $r['message_id'],
            'note'         => (string) ($r['note'] ?? ''),
            'remind_at'    => $r['remind_at'],
            'message'      => $fmt ? $fmt[0] : null,
        ];
    }
    return $out;
}

/* ============================================================
 *  CANVAS (project_canvas)
 * ============================================================ */

function pm_canvas_get($session_id)
{
    $sid = (int) $session_id;
    $r = db_fetch_row("SELECT * FROM project_canvas WHERE session_id = {$sid} LIMIT 1");
    if (!$r) return ['version' => 0, 'data' => ['shapes' => []], 'updated_by' => 0, 'updated_at' => null];
    $data = $r['data'] ? json_decode($r['data'], true) : ['shapes' => []];
    if (!is_array($data)) $data = ['shapes' => []];
    return [
        'version'    => (int) $r['version'],
        'data'       => $data,
        'updated_by' => (int) $r['updated_by'],
        'updated_at' => $r['updated_at'],
    ];
}

/**
 * Lưu canvas. base_version để chống ghi đè: nếu DB đã mới hơn, trả conflict.
 */
function pm_canvas_save($session_id, $project_id, $data_json, $base_version, $user_id)
{
    $sid = (int) $session_id; $pid = (int) $project_id;
    $base = (int) $base_version;
    $now = pm_now();
    $cur = db_fetch_row("SELECT id, version FROM project_canvas WHERE session_id = {$sid} LIMIT 1");
    // Chuẩn hóa JSON (giữ gọn, tránh lưu rác).
    $decoded = json_decode((string) $data_json, true);
    if (!is_array($decoded)) $decoded = ['shapes' => []];
    $clean = json_encode($decoded, JSON_UNESCAPED_UNICODE);

    if (!$cur) {
        $ver = 1;
        db_insert('project_canvas', [
            'session_id' => $sid, 'project_id' => $pid, 'data' => $clean,
            'version' => $ver, 'updated_by' => (int) $user_id, 'updated_at' => $now,
        ]);
        return ['ok' => true, 'version' => $ver];
    }
    $dbVer = (int) $cur['version'];
    if ($base > 0 && $base < $dbVer) {
        return ['ok' => false, 'conflict' => true, 'version' => $dbVer];
    }
    $ver = $dbVer + 1;
    db_update('project_canvas',
        ['data' => $clean, 'version' => $ver, 'updated_by' => (int) $user_id, 'updated_at' => $now],
        "session_id = {$sid}"
    );
    return ['ok' => true, 'version' => $ver];
}

/* ============================================================
 *  UPLOAD (port từ chat_store_upload, lưu public/uploads/project)
 * ============================================================ */

function pm_store_upload($file)
{
    $orig = (string) ($file['name'] ?? 'tệp');
    if (empty($file) || !isset($file['error'])) return ['ok' => false, 'name' => $orig, 'reason' => 'không nhận được tệp'];
    if ($file['error'] !== UPLOAD_ERR_OK) {
        $msg = ($file['error'] == UPLOAD_ERR_INI_SIZE || $file['error'] == UPLOAD_ERR_FORM_SIZE)
            ? 'vượt giới hạn dung lượng của máy chủ' : 'lỗi tải lên (mã ' . (int) $file['error'] . ')';
        return ['ok' => false, 'name' => $orig, 'reason' => $msg];
    }
    if ($file['size'] > 25 * 1024 * 1024) return ['ok' => false, 'name' => $orig, 'reason' => 'vượt 25MB'];

    $dir = APPPATH . DIRECTORY_SEPARATOR . 'public' . DIRECTORY_SEPARATOR . 'uploads' . DIRECTORY_SEPARATOR . 'project';
    if (!is_dir($dir)) @mkdir($dir, 0775, true);

    $ext = strtolower(pathinfo($orig, PATHINFO_EXTENSION));
    $blocked = ['php', 'php3', 'php4', 'php5', 'phtml', 'phar', 'exe', 'sh', 'bat', 'cmd', 'htaccess'];
    if (in_array($ext, $blocked, true)) return ['ok' => false, 'name' => $orig, 'reason' => 'định dạng .' . $ext . ' không được phép'];

    $info = @getimagesize($file['tmp_name']);
    $is_image = $info !== false ? 1 : 0;
    $safe_ext = preg_replace('/[^a-z0-9]/', '', $ext);
    $filename = 'p' . time() . '_' . substr(md5($orig . uniqid('', true)), 0, 10) . ($safe_ext !== '' ? '.' . $safe_ext : '');
    $dest = $dir . DIRECTORY_SEPARATOR . $filename;
    if (!move_uploaded_file($file['tmp_name'], $dest)) return ['ok' => false, 'name' => $orig, 'reason' => 'không lưu được tệp'];

    return [
        'ok' => true, 'file_name' => $filename,
        'original_name' => mb_substr($orig, 0, 250, 'UTF-8'),
        'mime' => (string) ($file['type'] ?? ''), 'size' => (int) $file['size'], 'is_image' => $is_image,
    ];
}
