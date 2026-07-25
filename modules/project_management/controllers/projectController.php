<?php
defined('APPPATH') OR exit('Không được quyền truy cập phần này');

require_once __DIR__ . '/../../../libraries/notifications.php'; // @nhắc tên → đẩy chuông

/* =====================================================================
 *  QUẢN LÝ DỰ ÁN — controller (module project_management)
 *  URL: ?mod=project_management&controllers=project&action=<act>
 *  - 2 action trả HTML: dashboard, detail.
 *  - còn lại trả JSON (AJAX). Mọi thao tác kiểm tra thành viên dự án.
 * =====================================================================*/

function construct()
{
    load_model('project');
    pm_ensure_schema();
}

/** Link tới trang chi tiết dự án (dùng cho thông báo chuông). */
function pm_detail_link($pid)
{
    return '?mod=project_management&controllers=project&action=detail&id=' . (int) $pid;
}

/* ---------------- helpers ---------------- */

function pm_json($payload)
{
    while (ob_get_level() > 0) { ob_end_clean(); }
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($payload, JSON_UNESCAPED_UNICODE);
    exit;
}

function pm_uid_or_fail()
{
    $uid = pm_uid();
    if ($uid <= 0) pm_json(['ok' => false, 'message' => 'Bạn cần đăng nhập.']);
    return $uid;
}

/** Lấy project_id từ request, kiểm tra là thành viên; fail JSON nếu không. */
function pm_guard_project($uid)
{
    $pid = (int) ($_POST['project_id'] ?? $_GET['project_id'] ?? 0);
    if ($pid <= 0 || !pm_is_member($pid, $uid)) {
        pm_json(['ok' => false, 'message' => 'Bạn không thuộc dự án này.']);
    }
    return $pid;
}

/** Lấy session_id + kiểm tra session thuộc 1 dự án mà user là thành viên. */
function pm_guard_session($uid)
{
    $sid = (int) ($_POST['session_id'] ?? $_GET['session_id'] ?? 0);
    $sess = pm_get_session($sid);
    if (!$sess || !pm_is_member((int) $sess['project_id'], $uid)) {
        pm_json(['ok' => false, 'message' => 'Không có quyền truy cập session.']);
    }
    return $sess;
}

/* ============================================================
 *  VIEW: Dashboard (lưới card dự án)
 * ============================================================ */

function dashboardAction()
{
    $uid = pm_uid();
    load_view('dashboard', [
        'projects' => $uid > 0 ? pm_projects_for($uid) : [],
        'me'       => permission_current_user(),
    ]);
}

/* ============================================================
 *  VIEW: Detail (workspace 2 khối: chat | canvas)
 * ============================================================ */

function detailAction()
{
    $uid = pm_uid();
    $pid = (int) ($_GET['id'] ?? 0);
    $project = pm_get_project($pid);
    if (!$project || !pm_is_member($pid, $uid)) {
        $page_title = 'Không có quyền';
        load_view('detail', ['project' => null, 'me' => permission_current_user()]);
        return;
    }
    $sessions = pm_sessions($pid);
    // Cá nhân hóa: mở lại session đang làm việc gần nhất của user; nếu chưa có/đã xóa → session đầu.
    $first = pm_get_last_session($pid, $uid) ?: ($sessions[0]['id'] ?? 0);
    load_view('detail', [
        'project'   => $project,
        'sessions'  => $sessions,
        'first_session' => (int) $first,
        'members'   => pm_members($pid),
        'is_leader' => pm_is_leader($pid, $uid),
        'all_users' => pm_all_users($uid),
        'invited'   => pm_pending_invite_ids($pid),
        'me'        => permission_current_user(),
    ]);
}

/* ============================================================
 *  AJAX — Dự án
 * ============================================================ */

function projectsAction()
{
    $uid = pm_uid_or_fail();
    pm_json(['ok' => true, 'projects' => pm_projects_for($uid)]);
}

function createProjectAction()
{
    $uid = pm_uid_or_fail();
    $p = pm_create_project($_POST['name'] ?? '', $_POST['description'] ?? '', $uid);
    if (!$p) pm_json(['ok' => false, 'message' => 'Không tạo được dự án (thiếu tên?).']);
    pm_json(['ok' => true, 'project' => ['id' => (int) $p['id'], 'name' => $p['name']]]);
}

function updateProjectAction()
{
    $uid = pm_uid_or_fail();
    $pid = pm_guard_project($uid);
    if (!pm_is_leader($pid, $uid)) pm_json(['ok' => false, 'message' => 'Chỉ trưởng dự án mới sửa được.']);
    pm_json(['ok' => pm_update_project($pid, $_POST['name'] ?? '', $_POST['description'] ?? '')]);
}

function archiveProjectAction()
{
    $uid = pm_uid_or_fail();
    $pid = pm_guard_project($uid);
    if (!pm_is_leader($pid, $uid)) pm_json(['ok' => false, 'message' => 'Chỉ trưởng dự án mới lưu trữ được.']);
    pm_json(['ok' => pm_archive_project($pid)]);
}

/* ============================================================
 *  AJAX — Thành viên & quyền
 * ============================================================ */

function membersAction()
{
    $uid = pm_uid_or_fail();
    $pid = pm_guard_project($uid);
    pm_json([
        'ok' => true,
        'members' => pm_members($pid),
        'is_leader' => pm_is_leader($pid, $uid),
        'all_users' => pm_all_users($uid),
        'invited' => pm_pending_invite_ids($pid),
    ]);
}

/** Leader MỜI thành viên: tạo lời mời + đẩy chuông (chưa thêm vào dự án ngay). */
function inviteMembersAction()
{
    $uid = pm_uid_or_fail();
    $pid = pm_guard_project($uid);
    if (!pm_is_leader($pid, $uid)) pm_json(['ok' => false, 'message' => 'Chỉ trưởng dự án mới mời được thành viên.']);
    $ids = $_POST['user_ids'] ?? $_POST['user_id'] ?? [];
    if (is_string($ids)) $ids = (strpos($ids, '[') === 0) ? (json_decode($ids, true) ?: []) : [$ids];
    $res = pm_invite_members($pid, $uid, (array) $ids);
    if (empty($res['ok'])) pm_json($res);
    // Đẩy chuông cho từng người được mời (kèm link tới dự án).
    $proj = pm_get_project($pid);
    $who  = pm_user_brief($uid)['fullname'];
    foreach ($res['invited'] as $t) {
        notify_create(
            (int) $t['id'],
            $who . ' mời bạn tham gia dự án "' . ($proj['name'] ?? 'dự án') . '"',
            'Bấm “Tham gia” để vào dự án, hoặc “Từ chối” để bỏ qua.',
            pm_detail_link($pid),
            'project_invite',
            $uid
        );
    }
    pm_json([
        'ok' => true,
        'invited_count' => count($res['invited']),
        'members' => pm_members($pid),
        'invited' => pm_pending_invite_ids($pid),
    ]);
}

/** Đẩy chuông cho người đã gửi lời mời biết kết quả (real-time qua poll chuông). */
function pm_notify_inviter($inviter_id, $pid, $actor_id, $verb)
{
    if ((int) $inviter_id <= 0) return;
    $proj = pm_get_project($pid);
    $who  = pm_user_brief((int) $actor_id)['fullname'];
    notify_create(
        (int) $inviter_id,
        $who . ' ' . $verb . ' dự án "' . ($proj['name'] ?? 'dự án') . '"',
        '',
        pm_detail_link($pid),
        'project_invite_result',
        (int) $actor_id
    );
}

/** Người được mời CHẤP NHẬN (từ chuông) → trở thành thành viên. Không yêu cầu là member sẵn. */
function acceptInviteAction()
{
    $uid = pm_uid_or_fail();
    $pid = (int) ($_POST['project_id'] ?? $_GET['project_id'] ?? 0);
    if ($pid <= 0) pm_json(['ok' => false, 'message' => 'Thiếu dự án.']);
    $res = pm_accept_invite($pid, $uid);
    if (!empty($res['ok'])) {
        $res['link'] = pm_detail_link($pid);
        pm_notify_inviter($res['invited_by'] ?? 0, $pid, $uid, 'đã tham gia');
    }
    pm_json($res);
}

/** Người được mời TỪ CHỐI lời mời → quay lại vòng lặp ban đầu (có thể mời lại). */
function declineInviteAction()
{
    $uid = pm_uid_or_fail();
    $pid = (int) ($_POST['project_id'] ?? $_GET['project_id'] ?? 0);
    if ($pid <= 0) pm_json(['ok' => false, 'message' => 'Thiếu dự án.']);
    $res = pm_decline_invite($pid, $uid);
    if (!empty($res['ok'])) pm_notify_inviter($res['invited_by'] ?? 0, $pid, $uid, 'đã từ chối lời mời tham gia');
    pm_json($res);
}

/** Người được mời BỎ QUA (đánh dấu đã đọc tất cả mà không phản hồi) → cũng đóng lời mời. */
function skipInviteAction()
{
    $uid = pm_uid_or_fail();
    $pid = (int) ($_POST['project_id'] ?? $_GET['project_id'] ?? 0);
    if ($pid <= 0) pm_json(['ok' => false, 'message' => 'Thiếu dự án.']);
    $res = pm_decline_invite($pid, $uid);   // bỏ qua = đóng lời mời, cho phép mời lại
    if (!empty($res['ok'])) pm_notify_inviter($res['invited_by'] ?? 0, $pid, $uid, 'đã xem và bỏ qua lời mời tham gia');
    pm_json($res);
}

/** Bỏ qua TẤT CẢ lời mời đang chờ (khi user xóa hết thông báo) + báo từng người mời. */
function skipAllInvitesAction()
{
    $uid = pm_uid_or_fail();
    $rows = pm_skip_all_invites($uid);
    foreach ($rows as $r) {
        pm_notify_inviter((int) $r['invited_by'], (int) $r['project_id'], $uid, 'đã xem và bỏ qua lời mời tham gia');
    }
    pm_json(['ok' => true, 'skipped' => count($rows)]);
}

function addMemberAction()
{
    $uid = pm_uid_or_fail();
    $pid = pm_guard_project($uid);
    $ids = $_POST['user_ids'] ?? $_POST['user_id'] ?? [];
    if (is_string($ids)) $ids = (strpos($ids, '[') === 0) ? (json_decode($ids, true) ?: []) : [$ids];
    $res = pm_add_members($pid, $uid, (array) $ids);
    if (empty($res['ok'])) pm_json($res);
    pm_json(['ok' => true, 'added' => $res['added'], 'members' => pm_members($pid)]);
}

function removeMemberAction()
{
    $uid = pm_uid_or_fail();
    $pid = pm_guard_project($uid);
    $res = pm_remove_member($pid, $uid, (int) ($_POST['user_id'] ?? 0));
    if (empty($res['ok'])) pm_json($res);
    pm_json(['ok' => true, 'members' => pm_members($pid)]);
}

function transferLeaderAction()
{
    $uid = pm_uid_or_fail();
    $pid = pm_guard_project($uid);
    $res = pm_transfer_leader($pid, $uid, (int) ($_POST['user_id'] ?? 0));
    if (empty($res['ok'])) pm_json($res);
    pm_json(['ok' => true, 'members' => pm_members($pid)]);
}

/** Rời dự án (có chế độ im lặng; trưởng phải trao quyền trước). */
function leaveProjectAction()
{
    $uid = pm_uid_or_fail();
    $pid = pm_guard_project($uid);
    $res = pm_leave_project($pid, $uid, !empty($_POST['silent']) ? 1 : 0, (int) ($_POST['new_leader_id'] ?? 0));
    pm_json($res);
}

/* ============================================================
 *  AJAX — Session
 * ============================================================ */

function sessionsAction()
{
    $uid = pm_uid_or_fail();
    $pid = pm_guard_project($uid);
    pm_json(['ok' => true, 'sessions' => pm_sessions($pid)]);
}

function sessionCreateAction()
{
    $uid = pm_uid_or_fail();
    $pid = pm_guard_project($uid);
    $s = pm_create_session($pid, $_POST['name'] ?? '', $uid);
    if (!$s) pm_json(['ok' => false, 'message' => 'Không tạo được session.']);
    // Nhật ký hoạt động: ai tạo session.
    $who = pm_user_brief($uid)['fullname'];
    pm_insert_message((int) $s['id'], $pid, $uid, $who . ' đã tạo session "' . $s['name'] . '".', 'system');
    // Mẫu (template) nếu có.
    $tpl = $_POST['template'] ?? '';
    if (in_array($tpl, ['db', 'module', 'process'], true)) pm_seed_template((int) $s['id'], $pid, $uid, $tpl);
    pm_json(['ok' => true, 'session' => $s, 'sessions' => pm_sessions($pid)]);
}

/** Ghi nhớ session đang làm việc (cá nhân hóa theo user). */
function setActiveSessionAction()
{
    $uid = pm_uid_or_fail();
    $sess = pm_guard_session($uid);
    pm_set_last_session((int) $sess['project_id'], (int) $sess['id'], $uid);
    pm_json(['ok' => true]);
}

function sessionRenameAction()
{
    $uid = pm_uid_or_fail();
    $sess = pm_guard_session($uid);
    $ok = pm_rename_session((int) $sess['id'], $_POST['name'] ?? '');
    pm_json(['ok' => $ok, 'sessions' => pm_sessions((int) $sess['project_id'])]);
}

function sessionDeleteAction()
{
    $uid = pm_uid_or_fail();
    $sess = pm_guard_session($uid);
    $pid = (int) $sess['project_id'];
    // Không cho xóa session cuối cùng.
    $all = pm_sessions($pid);
    if (count($all) <= 1) pm_json(['ok' => false, 'message' => 'Dự án phải còn ít nhất 1 session.']);
    pm_delete_session((int) $sess['id']);
    pm_json(['ok' => true, 'sessions' => pm_sessions($pid)]);
}

/* ============================================================
 *  AJAX — Chat
 * ============================================================ */

/** Poll: tin mới sau after_id + cập nhật động + nhắc hẹn đến hạn + version canvas. */
function pollAction()
{
    $uid = pm_uid_or_fail();
    $sess = pm_guard_session($uid);
    $sid = (int) $sess['id'];
    $after = (int) ($_GET['after_id'] ?? $_POST['after_id'] ?? 0);
    $canvasVer = (int) ($_GET['canvas_version'] ?? $_POST['canvas_version'] ?? 0);

    $new = pm_messages($sid, 50, 0, $after, $uid);
    $canvas = pm_canvas_get($sid);
    // KHÔNG tự đánh dấu đã đọc ở poll — chỉ tính "đã xem" khi user focus ô nhập (markRead).
    pm_json([
        'ok'        => true,
        'messages'  => $new,
        'updates'   => pm_recent_updates($sid, $uid),
        'reminders' => pm_due_reminders($uid),
        'pinned'    => pm_pinned_messages($sid, $uid),
        'readers'   => pm_readers($sid, $uid),
        'canvas_version' => $canvas['version'],
    ]);
}

/** Đánh dấu "đã xem" — chỉ gọi khi user focus ô nhập tin. Trả readers để FE cập nhật. */
function markReadAction()
{
    $uid = pm_uid_or_fail();
    $sess = pm_guard_session($uid);
    pm_mark_read((int) $sess['id'], $uid);
    pm_json(['ok' => true, 'readers' => pm_readers((int) $sess['id'], $uid)]);
}

/** Danh sách tin đã ghim của session (load ban đầu). */
function pinnedAction()
{
    $uid = pm_uid_or_fail();
    $sess = pm_guard_session($uid);
    pm_json(['ok' => true, 'pinned' => pm_pinned_messages((int) $sess['id'], $uid)]);
}

/** Bật/tắt ghim 1 tin. */
function pinToggleAction()
{
    $uid = pm_uid_or_fail();
    $pinned = pm_pin_toggle((int) ($_POST['message_id'] ?? 0), $uid);
    pm_json(['ok' => true, 'pinned' => $pinned]);
}

/** Bình chọn 1 option của tin type='vote'. */
function voteToggleAction()
{
    $uid = pm_uid_or_fail();
    $mid = (int) ($_POST['message_id'] ?? 0);
    $m = pm_get_message($mid);
    if (!$m) pm_json(['ok' => false, 'message' => 'Tin không tồn tại.']);
    $payload = $m['payload'] ? json_decode($m['payload'], true) : [];
    $multi = !empty($payload['multi']);
    $tally = pm_vote_toggle($mid, (int) ($_POST['opt_index'] ?? -1), $uid, $multi);
    pm_json(['ok' => true, 'votes' => (object) $tally]);
}

/** Tìm tin nhắn theo từ khóa trong session hiện tại. */
function searchAction()
{
    $uid = pm_uid_or_fail();
    $sess = pm_guard_session($uid);
    $q = trim((string) ($_GET['q'] ?? $_POST['q'] ?? ''));
    $results = pm_search_messages((int) $sess['id'], $q, 50, $uid);
    pm_json(['ok' => true, 'q' => $q, 'results' => $results]);
}

/** Tải tin nhắn (phân trang / lọc sao). */
function messagesAction()
{
    $uid = pm_uid_or_fail();
    $sess = pm_guard_session($uid);
    $before = (int) ($_GET['before_id'] ?? 0);
    $starred = !empty($_GET['starred']);
    $msgs = pm_messages((int) $sess['id'], 25, $before, 0, $uid, $starred);
    pm_json([
        'ok' => true, 'messages' => $msgs, 'has_more' => count($msgs) >= 25,
        'readers' => pm_readers((int) $sess['id'], $uid),
    ]);
}

/** Gửi tin: text/ảnh/file + reply + các loại đặc biệt (checklist/table/tree/process). */
function sendAction()
{
    $uid = pm_uid_or_fail();
    $sess = pm_guard_session($uid);
    $sid = (int) $sess['id'];
    $pid = (int) $sess['project_id'];

    $type = $_POST['type'] ?? 'text';
    $body = trim((string) ($_POST['body'] ?? ''));
    $reply = (int) ($_POST['reply_to_id'] ?? 0);
    $allowed = ['text','image','file','checklist','table','tree','process','vote'];
    if (!in_array($type, $allowed, true)) $type = 'text';

    // payload cho loại đặc biệt
    $payload = null;
    $checklistRows = [];
    if (in_array($type, ['table','tree','process','vote'], true)) {
        $raw = $_POST['payload'] ?? '';
        $decoded = is_string($raw) ? json_decode($raw, true) : $raw;
        if (!is_array($decoded)) pm_json(['ok' => false, 'message' => 'Dữ liệu không hợp lệ.']);
        if ($type === 'vote') {
            $opts = array_values(array_filter(array_map(fn($x) => trim((string) $x), $decoded['options'] ?? []), fn($v) => $v !== ''));
            if (count($opts) < 2) pm_json(['ok' => false, 'message' => 'Cần ít nhất 2 lựa chọn.']);
            $decoded = ['multi' => !empty($decoded['multi']), 'options' => $opts];
        }
        $payload = json_encode($decoded, JSON_UNESCAPED_UNICODE);
    } elseif ($type === 'checklist') {
        $raw = $_POST['payload'] ?? '';
        $decoded = is_string($raw) ? json_decode($raw, true) : $raw;
        $checklistRows = is_array($decoded) ? $decoded : [];
        $checklistRows = array_values(array_filter(array_map(function ($x) {
            return trim((string) (is_array($x) ? ($x['content'] ?? '') : $x));
        }, $checklistRows), fn($v) => $v !== ''));
        if (!$checklistRows) pm_json(['ok' => false, 'message' => 'Checklist trống.']);
    }

    // Tải file đính kèm (nếu có)
    $uploaded = [];
    $skipped = [];
    if (!empty($_FILES['files']) && is_array($_FILES['files']['name'])) {
        $n = count($_FILES['files']['name']);
        for ($i = 0; $i < $n; $i++) {
            $f = [
                'name' => $_FILES['files']['name'][$i], 'type' => $_FILES['files']['type'][$i],
                'tmp_name' => $_FILES['files']['tmp_name'][$i], 'error' => $_FILES['files']['error'][$i],
                'size' => $_FILES['files']['size'][$i],
            ];
            if (($f['error'] ?? 4) === UPLOAD_ERR_NO_FILE) continue;
            $r = pm_store_upload($f);
            if (!empty($r['ok'])) $uploaded[] = $r;
            else $skipped[] = ($r['name'] ?? 'tệp') . ' (' . ($r['reason'] ?? 'lỗi') . ')';
        }
    }

    if ($body === '' && !$uploaded && !in_array($type, ['table','tree','process','checklist','vote'], true)) {
        pm_json(['ok' => false, 'message' => 'Tin nhắn trống.', 'skipped' => $skipped]);
    }

    // Quyết định type cuối: có ảnh → image, có file → file (nếu là text gốc).
    if ($type === 'text' && $uploaded) {
        $allImg = true;
        foreach ($uploaded as $u) if (empty($u['is_image'])) { $allImg = false; break; }
        $type = $allImg ? 'image' : 'file';
    }

    // Liên kết shape ↔ tin: ghi chú cho 1 hình trên canvas (text-only).
    $shapeRef = trim((string) ($_POST['shape_ref'] ?? ''));
    if ($shapeRef !== '' && $type === 'text') {
        $payload = json_encode(['shape_link' => mb_substr($shapeRef, 0, 60, 'UTF-8')], JSON_UNESCAPED_UNICODE);
    }

    $mid = pm_insert_message($sid, $pid, $uid, $body, $type, $payload, $reply, 0);

    foreach ($uploaded as $u) {
        db_insert('project_attachments', [
            'message_id' => $mid, 'file_name' => $u['file_name'], 'original_name' => $u['original_name'],
            'mime' => $u['mime'], 'size' => $u['size'], 'is_image' => $u['is_image'],
        ]);
    }
    if ($type === 'checklist') {
        foreach ($checklistRows as $i => $c) {
            db_insert('project_checklist_items', ['message_id' => $mid, 'content' => mb_substr($c, 0, 490, 'UTF-8'), 'is_done' => 0, 'sort_order' => $i]);
        }
    }

    // @nhắc tên: đẩy chuông cho các thành viên được nhắc (trừ người gửi).
    $mentions = $_POST['mentions'] ?? '';
    $mlist = is_string($mentions) ? json_decode($mentions, true) : $mentions;
    if (is_array($mlist) && $mlist) {
        $proj = pm_get_project($pid);
        $who = pm_user_brief($uid)['fullname'];
        $preview = $body !== '' ? mb_substr($body, 0, 120, 'UTF-8') : pm_type_label($type);
        $seen = [];
        foreach ($mlist as $tid) {
            $tid = (int) $tid;
            if ($tid <= 0 || $tid === $uid || isset($seen[$tid]) || !pm_is_member($pid, $tid)) continue;
            $seen[$tid] = true;
            notify_create($tid, $who . ' đã nhắc bạn trong "' . ($proj['name'] ?? 'dự án') . '"', $preview, pm_detail_link($pid), 'project_mention', $uid);
        }
    }

    $fmt = pm_messages($sid, 1, 0, $mid - 1, $uid);
    pm_json(['ok' => true, 'message' => $fmt ? $fmt[0] : null, 'skipped' => $skipped]);
}

function recallAction()
{
    $uid = pm_uid_or_fail();
    pm_json(pm_recall_message((int) ($_POST['message_id'] ?? 0), $uid));
}

function reactAction()
{
    $uid = pm_uid_or_fail();
    $reax = pm_react((int) ($_POST['message_id'] ?? 0), $uid, $_POST['emoji'] ?? '');
    pm_json(['ok' => true, 'reactions' => $reax]);
}

function starToggleAction()
{
    $uid = pm_uid_or_fail();
    $note = isset($_POST['note']) ? (string) $_POST['note'] : null;
    $res = pm_star_toggle((int) ($_POST['message_id'] ?? 0), $uid, $note);
    pm_json(['ok' => true, 'starred' => $res['starred'], 'note' => $res['note']]);
}

function forwardAction()
{
    $uid = pm_uid_or_fail();
    $res = pm_forward_message((int) ($_POST['message_id'] ?? 0), (int) ($_POST['to_session_id'] ?? 0), $uid);
    pm_json($res);
}

/** Chia sẻ 1 tin của dự án qua CHAT HỆ THỐNG tới 1 người. POST message_id, target_uid, note. */
function shareToChatAction()
{
    $uid = pm_uid_or_fail();
    $res = pm_share_to_chat(
        (int) ($_POST['message_id'] ?? 0),
        (int) ($_POST['target_uid'] ?? 0),
        $uid,
        (string) ($_POST['note'] ?? '')
    );
    pm_json($res);
}

/** Thẻ thông tin 1 user (cho UserCard). GET/POST user_id. */
function userCardAction()
{
    $uid = pm_uid_or_fail();
    $tid = (int) ($_GET['user_id'] ?? $_POST['user_id'] ?? 0);
    if ($tid <= 0) pm_json(['ok' => false, 'message' => 'Thiếu user_id.']);
    $r = db_fetch_row("SELECT id, fullname, username, email, avatar, role
                       FROM tbl_users WHERE id = {$tid} LIMIT 1");
    if (!$r) pm_json(['ok' => false, 'message' => 'Không tìm thấy người dùng.']);
    pm_json(['ok' => true, 'user' => [
        'id'       => (int) $r['id'],
        'fullname' => $r['fullname'] !== '' ? $r['fullname'] : $r['username'],
        'username' => (string) $r['username'],
        'email'    => (string) ($r['email'] ?? ''),
        'avatar'   => pm_avatar_url($r['avatar']),
        'is_admin' => ($r['role'] ?? '') === 'admin',
    ]]);
}

function setReminderAction()
{
    $uid = pm_uid_or_fail();
    pm_json(pm_set_reminder($uid, (int) ($_POST['message_id'] ?? 0), $_POST['remind_at'] ?? '', $_POST['note'] ?? ''));
}

function checklistToggleAction()
{
    $uid = pm_uid_or_fail();
    $ok = pm_checklist_toggle((int) ($_POST['item_id'] ?? 0), $uid, !empty($_POST['done']));
    pm_json(['ok' => $ok]);
}

/* ============================================================
 *  AJAX — Canvas
 * ============================================================ */

function canvasGetAction()
{
    $uid = pm_uid_or_fail();
    $sess = pm_guard_session($uid);
    pm_json(['ok' => true, 'canvas' => pm_canvas_get((int) $sess['id'])]);
}

function canvasSaveAction()
{
    $uid = pm_uid_or_fail();
    $sess = pm_guard_session($uid);
    $res = pm_canvas_save((int) $sess['id'], (int) $sess['project_id'], $_POST['data'] ?? '', (int) ($_POST['base_version'] ?? 0), $uid);
    pm_json($res);
}

/** Chia sẻ snapshot canvas vào chat (tin type='canvas'). */
function canvasShareAction()
{
    $uid = pm_uid_or_fail();
    $sess = pm_guard_session($uid);
    $sid = (int) $sess['id'];
    $pid = (int) $sess['project_id'];
    $canvas = pm_canvas_get($sid);
    $thumb = (string) ($_POST['thumb'] ?? ''); // dataURL ảnh thu nhỏ (cho card preview)
    if (mb_strlen($thumb) > 600000) $thumb = ''; // chặn payload quá lớn
    // Snapshot shapes: ưu tiên client gửi (đúng thứ đang xem), fallback DB.
    $shapesRaw = $_POST['shapes'] ?? '';
    $shapes = ($shapesRaw !== '') ? json_decode($shapesRaw, true) : null;
    if (!is_array($shapes)) $shapes = $canvas['data']['shapes'] ?? [];
    if (count($shapes) > 5000) $shapes = array_slice($shapes, 0, 5000);
    $payload = json_encode([
        'session_id'  => $sid,
        'version'     => $canvas['version'],
        'thumb'       => $thumb,
        'shapes'      => $shapes,
        'shape_count' => count($shapes),
    ], JSON_UNESCAPED_UNICODE);
    $mid = pm_insert_message($sid, $pid, $uid, 'Đã chia sẻ bản thiết kế', 'canvas', $payload, 0, 0);
    $fmt = pm_messages($sid, 1, 0, $mid - 1, $uid);
    pm_json(['ok' => true, 'message' => $fmt ? $fmt[0] : null]);
}
