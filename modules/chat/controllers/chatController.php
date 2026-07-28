<?php
/* =====================================================================
 *  CHAT CONTROLLER — các action AJAX (trả JSON) cho widget chat góc phải.
 *  URL: ?mod=chat&controllers=chat&action=<...>
 *  Mọi action yêu cầu đăng nhập (permission_current_user).
 * ===================================================================== */

session_start();
require_once __DIR__ . '/../../../libraries/chat.php';
require_once __DIR__ . '/../../../libraries/chat_bot.php';
require_once __DIR__ . '/../../../libraries/notifications.php';

// construct mặc định (router gọi trước action).
function construct() {}

/** Tiện ích: trả JSON rồi dừng dòng xử lý của action. */
function chat_json($data)
{
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($data, JSON_UNESCAPED_UNICODE);
}

/** User hiện tại hoặc null + trả lỗi JSON nếu chưa đăng nhập. */
function chat_require_user()
{
    $user = permission_current_user();
    if (!$user) {
        chat_json(['ok' => false, 'message' => 'Chưa đăng nhập.']);
        return null;
    }
    return $user;
}

/** 1. Danh bạ: tất cả user khác (avatar + fullname).
 *  Tài khoản hệ thống ("Safe King") được ghim lên đầu, chỉ với user có quyền. */
function contactsAction()
{
    $user = chat_require_user();
    if (!$user) return;
    $uid = (int) $user['id'];
    chat_ensure_tables();

    $contacts = chat_contacts($uid);
    $botTopics = chatbot_user_topics($uid);
    if ($botTopics) {
        $bid = chatbot_user_id();
        array_unshift($contacts, [
            'id'       => $bid,
            'fullname' => chatbot_name(),
            'username' => CHATBOT_USERNAME,
            'avatar'   => '',
            'online'   => true,          // luôn sẵn sàng trả lời
            'alias'    => '',
            'is_bot'   => true,
            'bot_desc' => 'Tài khoản hệ thống · ' . count($botTopics) . ' chủ đề',
        ]);
    }
    chat_json([
        'ok'         => true,
        'me'         => chat_user_brief($uid),
        'contacts'   => $contacts,
        'bot'        => chat_bot_state_for_user($uid),
    ]);
}

/** Trạng thái tài khoản hệ thống theo góc nhìn 1 user (dùng chung cho FE). */
function chat_bot_state_for_user($uid)
{
    $topics = chatbot_user_topics($uid);
    $all    = chatbot_topics();
    $list   = [];
    foreach ($topics as $t) $list[] = ['key' => $t, 'label' => $all[$t]['label']];
    return [
        'id'       => chatbot_user_id(),
        'name'     => chatbot_name(),
        'can_chat' => count($topics) > 0,
        'topics'   => $list,
        'is_admin' => permission_is_admin(),
    ];
}

/** 2. Danh sách hội thoại gần đây của tôi. */
function conversationsAction()
{
    $user = chat_require_user();
    if (!$user) return;
    $uid = (int) $user['id'];

    // Quyền chat với hệ thống có thể bị admin thu hồi sau đó → ẩn luôn hội thoại cũ.
    $convs = chat_conversations($uid, 50);
    if (!chatbot_can_chat($uid)) {
        $convs = array_values(array_filter($convs, static fn($c) => empty($c['is_bot'])));
    }
    chat_json([
        'ok'            => true,
        'conversations' => $convs,
        'unread_total'  => chat_unread_total($uid),
        'bot'           => chat_bot_state_for_user($uid),
    ]);
}

/** 3. Mở (hoặc tạo) hội thoại 1-1 với 1 user. Trả meta hội thoại. */
function openAction()
{
    $user = chat_require_user();
    if (!$user) return;
    $other = (int) ($_POST['user_id'] ?? $_GET['user_id'] ?? 0);
    if ($other <= 0) { chat_json(['ok' => false, 'message' => 'Thiếu user_id.']); return; }

    // Hội thoại với tài khoản hệ thống: phải có ít nhất 1 chủ đề được cấp quyền,
    // và lần đầu mở thì bot tự gửi lời chào + danh sách chủ đề.
    if (chatbot_is_bot($other)) {
        if (!chatbot_can_chat((int) $user['id'])) {
            chat_json(['ok' => false, 'message' => 'Bạn chưa được cấp quyền trò chuyện với tài khoản hệ thống.']);
            return;
        }
        $cid = chatbot_open_conversation((int) $user['id']);
    } else {
        $cid = chat_get_or_create_direct((int) $user['id'], $other);
    }
    if ($cid <= 0) { chat_json(['ok' => false, 'message' => 'Không tạo được hội thoại.']); return; }

    chat_json([
        'ok'           => true,
        'conversation' => chat_conversation_meta($cid, (int) $user['id']),
    ]);
}

/** 4. Lấy tin nhắn của 1 hội thoại (before_id: xem cũ hơn, after_id: poll tin mới). */
function messagesAction()
{
    $user = chat_require_user();
    if (!$user) return;
    $cid = (int) ($_GET['conversation_id'] ?? $_POST['conversation_id'] ?? 0);
    if ($cid <= 0 || !chat_is_participant($cid, (int) $user['id'])) {
        chat_json(['ok' => false, 'message' => 'Không có quyền xem hội thoại này.']);
        return;
    }
    $before = (int) ($_GET['before_id'] ?? 0);
    $after  = (int) ($_GET['after_id'] ?? 0);
    $limit  = (int) ($_GET['limit'] ?? 20);
    $uid    = (int) $user['id'];

    $messages = chat_messages($cid, $limit, $before, $after, $uid);

    // Mở phòng (lô đầu, không phải cuộn cũ/poll) → coi như đã xem các cảm xúc người
    // khác thả lên tin của tôi → tắt badge "{emoji} {tên}" ở danh sách hội thoại.
    if ($before === 0 && $after === 0) chat_touch_reactions_seen($cid, $uid);

    // KHÔNG tự đánh dấu đã đọc ở đây nữa — chỉ tính "đã xem" khi người nhận
    // focus vào ô nhập (markRead). Mở/poll không còn = đã xem.
    chat_json([
        'ok'       => true,
        'messages' => $messages,
        'readers'  => chat_readers($cid, $uid),                 // trạng thái "đã xem" của người khác
        'updates'  => $after > 0 ? chat_recent_message_updates($cid, $uid) : null, // đồng bộ cảm xúc/thu hồi khi poll
        'has_more' => $before === 0 && $after === 0 ? (count($messages) >= $limit) : true,
    ]);
}

/** Đánh dấu đã đọc — gọi khi người nhận focus ô nhập tin. POST conversation_id. */
function markReadAction()
{
    $user = chat_require_user();
    if (!$user) return;
    $cid = (int) ($_POST['conversation_id'] ?? 0);
    if ($cid <= 0 || !chat_is_participant($cid, (int) $user['id'])) {
        chat_json(['ok' => false]);
        return;
    }
    chat_mark_read($cid, (int) $user['id']);
    chat_json(['ok' => true, 'unread_total' => chat_unread_total((int) $user['id'])]);
}

/** 5. Gửi tin nhắn (text + ảnh/file). POST conversation_id, body, files[]. */
function sendAction()
{
    $user = chat_require_user();
    if (!$user) return;
    $uid = (int) $user['id'];
    $cid = (int) ($_POST['conversation_id'] ?? 0);
    if ($cid <= 0 || !chat_is_participant($cid, $uid)) {
        chat_json(['ok' => false, 'message' => 'Không có quyền gửi vào hội thoại này.']);
        return;
    }
    $body = trim((string) ($_POST['body'] ?? ''));

    // Thu thập file (input name="files[]").
    $stored  = [];
    $skipped = [];
    if (!empty($_FILES['files']) && is_array($_FILES['files']['name'])) {
        $n = count($_FILES['files']['name']);
        for ($i = 0; $i < $n; $i++) {
            $f = [
                'name'     => $_FILES['files']['name'][$i],
                'type'     => $_FILES['files']['type'][$i],
                'tmp_name' => $_FILES['files']['tmp_name'][$i],
                'error'    => $_FILES['files']['error'][$i],
                'size'     => $_FILES['files']['size'][$i],
            ];
            $a = chat_store_upload($f);
            if (!empty($a['ok'])) {
                unset($a['ok']);
                $stored[] = $a;
            } else {
                $skipped[] = ($a['name'] ?? 'tệp') . ' (' . ($a['reason'] ?? 'lỗi') . ')';
            }
        }
    }

    if ($body === '' && !$stored) {
        chat_json([
            'ok'      => false,
            'message' => $skipped ? ('Không gửi được tệp: ' . implode('; ', $skipped)) : 'Tin nhắn trống.',
        ]);
        return;
    }

    // Loại tin: có ảnh → image, có file khác → file, còn lại text.
    $type = 'text';
    if ($stored) {
        $all_image = true;
        foreach ($stored as $a) { if (!$a['is_image']) { $all_image = false; break; } }
        $type = $all_image ? 'image' : 'file';
    }

    // Trả lời 1 tin (kiểu Zalo): chỉ chấp nhận nếu tin gốc cùng hội thoại.
    $reply = (int) ($_POST['reply_to_id'] ?? 0);
    if ($reply > 0) {
        $rm = db_fetch_row("SELECT conversation_id FROM chat_messages WHERE id = {$reply} LIMIT 1");
        if (!$rm || (int) $rm['conversation_id'] !== $cid) $reply = 0;
    }

    $mid = chat_insert_message($cid, $uid, $body, $type, $reply);
    foreach ($stored as $a) {
        $a['message_id'] = $mid;
        db_insert('chat_attachments', $a);
    }
    chat_mark_read($cid, $uid, $mid);

    // @nhắc tên: đẩy chuông cho thành viên được nhắc (phải cùng hội thoại, trừ người gửi).
    $mentions = $_POST['mentions'] ?? '';
    $mlist = is_string($mentions) ? json_decode($mentions, true) : $mentions;
    if (is_array($mlist) && $mlist) {
        $who     = chat_user_brief($uid)['fullname'];
        $meta    = chat_conversation_meta($cid, $uid);
        $where   = ($meta && ($meta['type'] ?? '') === 'group') ? ('nhóm "' . ($meta['name'] ?? '') . '"') : 'cuộc trò chuyện';
        $preview = $body !== '' ? mb_substr(chat_strip_format_tags($body), 0, 120, 'UTF-8') : '[Tệp đính kèm]';
        $seen = [];
        foreach ($mlist as $tid) {
            $tid = (int) $tid;
            if ($tid <= 0 || $tid === $uid || isset($seen[$tid]) || !chat_is_participant($cid, $tid)) continue;
            $seen[$tid] = true;
            notify_create($tid, $who . ' đã nhắc bạn trong ' . $where, $preview, '', 'chat_mention', $uid);
        }
    }

    // Hội thoại với tài khoản hệ thống → trả lời NGAY trong cùng request (không chờ
    // poll) để người dùng thấy câu trả lời liền sau tin của mình. bot_pick = user bấm
    // 1 nút gợi ý thay vì gõ tay (xem libraries/chat_bot.php).
    $botReplies = [];
    if (chatbot_is_bot_conversation($cid) && !chatbot_is_bot($uid)) {
        $pickRaw = (string) ($_POST['bot_pick'] ?? '');
        $pick    = $pickRaw !== '' ? json_decode($pickRaw, true) : null;
        $ids = is_array($pick)
            ? chatbot_handle_pick($cid, $uid, $pick)
            : chatbot_handle_message($cid, $uid, $body);
        $botReplies = chatbot_fetch_messages($ids, $uid);
    }

    $rows = db_fetch_array("SELECT * FROM chat_messages WHERE id = {$mid} LIMIT 1");
    $fmt  = chat_format_messages($rows, $uid);
    chat_json([
        'ok'          => true,
        'message'     => $fmt ? $fmt[0] : null,
        'skipped'     => $skipped, // tệp bị bỏ qua (nếu có) để cảnh báo người gửi
        'bot_replies' => $botReplies,
    ]);
}

/** 6. Tìm kiếm tin nhắn theo từ khóa trong 1 hội thoại. */
function searchAction()
{
    $user = chat_require_user();
    if (!$user) return;
    $cid = (int) ($_GET['conversation_id'] ?? 0);
    $kw  = (string) ($_GET['q'] ?? '');
    if ($cid <= 0 || !chat_is_participant($cid, (int) $user['id'])) {
        chat_json(['ok' => false, 'message' => 'Không có quyền.']);
        return;
    }
    chat_json([
        'ok'      => true,
        'results' => chat_search_messages($cid, $kw, 50, (int) $user['id']),
    ]);
}

/** 7. Tạo nhóm chat (kiểu Zalo). POST name, members[] (user ids). */
function createGroupAction()
{
    $user = chat_require_user();
    if (!$user) return;
    $name    = (string) ($_POST['name'] ?? '');
    $members = $_POST['members'] ?? [];
    if (!is_array($members)) $members = [$members];

    $cid = chat_create_group((int) $user['id'], $name, $members);
    if ($cid <= 0) {
        chat_json(['ok' => false, 'message' => 'Nhóm cần tên và ít nhất 1 thành viên khác.']);
        return;
    }
    chat_json([
        'ok'           => true,
        'conversation' => chat_conversation_meta($cid, (int) $user['id']),
    ]);
}

/** 8. Tổng số chưa đọc (cho badge nút chat) — gọi định kỳ. */
function unreadAction()
{
    $user = chat_require_user();
    if (!$user) return;
    chat_json(['ok' => true, 'unread_total' => chat_unread_total((int) $user['id'])]);
}

/** 9. Poll toàn cục: badge + auto-mở khung chat + nhắc hẹn tới hạn + presence. */
function pollAction()
{
    $user = chat_require_user();
    if (!$user) return;
    $uid = (int) $user['id'];
    chat_touch_presence($uid);                              // heartbeat: đánh dấu đang online
    chat_json([
        'ok'            => true,
        'unread_total'  => chat_unread_total($uid),
        'latest'        => chat_latest_incoming($uid),     // {message_id, conversation_id, ...} | null
        'due_reminders' => chat_due_reminders($uid),
        'notif'         => chat_notif_state($uid),          // {mute_all, in_system, toast} cho JS gate
    ]);
}

/** 10. Thu hồi tin nhắn (chỉ người gửi). POST message_id. */
function recallAction()
{
    $user = chat_require_user();
    if (!$user) return;
    $mid = (int) ($_POST['message_id'] ?? 0);
    chat_json(chat_recall_message($mid, (int) $user['id']));
}

/** 26. Người nhận tự xóa 1 tin phía mình (người gửi vẫn giữ nguyên). POST message_id. */
function deleteMessageAction()
{
    $user = chat_require_user();
    if (!$user) return;
    $mid = (int) ($_POST['message_id'] ?? 0);
    chat_json(chat_delete_message_for_me($mid, (int) $user['id']));
}

/** 11. Thả / đổi / gỡ cảm xúc. POST message_id, emoji. */
function reactAction()
{
    $user = chat_require_user();
    if (!$user) return;
    $mid = (int) ($_POST['message_id'] ?? 0);
    $em  = (string) ($_POST['emoji'] ?? '');
    $m = db_fetch_row("SELECT conversation_id FROM chat_messages WHERE id = {$mid} LIMIT 1");
    if (!$m || !chat_is_participant((int) $m['conversation_id'], (int) $user['id'])) {
        chat_json(['ok' => false, 'message' => 'Không có quyền.']); return;
    }
    chat_json(['ok' => true, 'reactions' => chat_react($mid, (int) $user['id'], $em)]);
}

/** 12. Thêm thành viên (direct sẽ thành group). POST conversation_id, members[]. */
function addMembersAction()
{
    $user = chat_require_user();
    if (!$user) return;
    $cid = (int) ($_POST['conversation_id'] ?? 0);
    $members = $_POST['members'] ?? [];
    if (!is_array($members)) $members = [$members];
    if ($cid <= 0 || !chat_is_participant($cid, (int) $user['id'])) {
        chat_json(['ok' => false, 'message' => 'Không có quyền.']); return;
    }
    $ok = chat_add_members($cid, (int) $user['id'], $members);
    chat_json($ok
        ? ['ok' => true, 'conversation' => chat_conversation_meta($cid, (int) $user['id'])]
        : ['ok' => false, 'message' => 'Không thêm được thành viên.']);
}

/** 13. Trưởng nhóm đuổi thành viên. POST conversation_id, user_id. */
function removeMemberAction()
{
    $user = chat_require_user();
    if (!$user) return;
    $cid    = (int) ($_POST['conversation_id'] ?? 0);
    $target = (int) ($_POST['user_id'] ?? 0);
    $ok = chat_remove_member($cid, (int) $user['id'], $target);
    chat_json($ok
        ? ['ok' => true, 'conversation' => chat_conversation_meta($cid, (int) $user['id'])]
        : ['ok' => false, 'message' => 'Chỉ trưởng nhóm mới được đuổi thành viên.']);
}

/** 14. Rời nhóm / hội thoại. POST conversation_id, silent (0/1), new_admin_id (tùy chọn). */
function leaveAction()
{
    $user = chat_require_user();
    if (!$user) return;
    $cid    = (int) ($_POST['conversation_id'] ?? 0);
    $silent = (int) ($_POST['silent'] ?? 0);
    $newAdm = (int) ($_POST['new_admin_id'] ?? 0);
    chat_json(chat_leave_conversation($cid, (int) $user['id'], $silent, $newAdm));
}

/** 21. Lấy cấu hình chat của tôi (cho modal Cài đặt). */
function settingsAction()
{
    $user = chat_require_user();
    if (!$user) return;
    chat_json(['ok' => true, 'settings' => chat_get_user_settings((int) $user['id'])]);
}

/** 22. Lưu cấu hình chat. POST show_online, notify_in_system, notify_toast, mute_mode. */
function saveSettingsAction()
{
    $user = chat_require_user();
    if (!$user) return;
    $uid  = (int) $user['id'];
    $data = [];
    // Chỉ nhận các khóa có gửi lên (cho phép lưu từng phần).
    if (isset($_POST['show_online']))      $data['show_online']      = (int) $_POST['show_online'] === 1;
    if (isset($_POST['notify_in_system'])) $data['notify_in_system'] = (int) $_POST['notify_in_system'] === 1;
    if (isset($_POST['notify_toast']))     $data['notify_toast']     = (int) $_POST['notify_toast'] === 1;
    if (isset($_POST['mute_mode']))        $data['mute_mode']        = (string) $_POST['mute_mode'];
    chat_save_user_settings($uid, $data);
    chat_json(['ok' => true, 'settings' => chat_get_user_settings($uid)]);
}

/** 23. Đặt/xóa biệt danh cho 1 liên hệ trong danh bạ (riêng tư). POST contact_id, alias. */
function renameContactAction()
{
    $user = chat_require_user();
    if (!$user) return;
    $cid   = (int) ($_POST['contact_id'] ?? 0);
    $alias = (string) ($_POST['alias'] ?? '');
    $ok = chat_set_contact_alias((int) $user['id'], $cid, $alias);
    chat_json($ok ? ['ok' => true, 'alias' => trim($alias)] : ['ok' => false, 'message' => 'Không đổi được tên.']);
}

/** 15. Xóa hội thoại (phía mình). POST conversation_id. */
function deleteConversationAction()
{
    $user = chat_require_user();
    if (!$user) return;
    $cid = (int) ($_POST['conversation_id'] ?? 0);
    $ok = chat_clear_conversation($cid, (int) $user['id']);
    chat_json($ok ? ['ok' => true] : ['ok' => false, 'message' => 'Không thể xóa.']);
}

/** 27. Cài đặt tự xóa tin nhắn cá nhân hóa cho 1 hội thoại (chỉ ẩn phía tôi).
 *  POST conversation_id, mode (1h|4h|1d|1w|1m|off). */
function setAutoDeleteAction()
{
    $user = chat_require_user();
    if (!$user) return;
    $cid  = (int) ($_POST['conversation_id'] ?? 0);
    $mode = (string) ($_POST['mode'] ?? '');
    $ok = chat_set_auto_delete($cid, (int) $user['id'], $mode);
    chat_json($ok
        ? ['ok' => true, 'conversation' => chat_conversation_meta($cid, (int) $user['id'])]
        : ['ok' => false, 'message' => 'Không đặt được.']);
}

/** 16. Tắt thông báo. POST conversation_id, mode (1h|4h|forever|off). */
function muteAction()
{
    $user = chat_require_user();
    if (!$user) return;
    $cid  = (int) ($_POST['conversation_id'] ?? 0);
    $mode = (string) ($_POST['mode'] ?? '');
    $ok = chat_mute($cid, (int) $user['id'], $mode);
    chat_json($ok
        ? ['ok' => true, 'muted_until' => chat_my_mute($cid, (int) $user['id'])]
        : ['ok' => false, 'message' => 'Không đặt được.']);
}

/** 17. Đổi tên nhóm (ai cũng được). POST conversation_id, name. */
function renameGroupAction()
{
    $user = chat_require_user();
    if (!$user) return;
    $cid  = (int) ($_POST['conversation_id'] ?? 0);
    $name = (string) ($_POST['name'] ?? '');
    $ok = chat_rename_group($cid, (int) $user['id'], $name);
    chat_json($ok ? ['ok' => true, 'name' => trim($name)] : ['ok' => false, 'message' => 'Không đổi được tên.']);
}

/** 18. Đổi ảnh đại diện nhóm. POST conversation_id + file 'avatar'. */
function updateGroupAvatarAction()
{
    $user = chat_require_user();
    if (!$user) return;
    $cid = (int) ($_POST['conversation_id'] ?? 0);
    $res = chat_set_group_avatar($cid, (int) $user['id'], $_FILES['avatar'] ?? null);
    chat_json($res);
}

/** 19. Danh sách thành viên nhóm (avatar + fullname + nhận diện trưởng nhóm). */
function membersAction()
{
    $user = chat_require_user();
    if (!$user) return;
    $cid = (int) ($_GET['conversation_id'] ?? 0);
    if ($cid <= 0 || !chat_is_participant($cid, (int) $user['id'])) {
        chat_json(['ok' => false, 'message' => 'Không có quyền.']); return;
    }
    chat_json([
        'ok'       => true,
        'members'  => chat_group_members($cid),
        'is_admin' => (chat_conversation_meta($cid, (int) $user['id'])['is_admin'] ?? false),
    ]);
}

/** 24. Thẻ thông tin 1 user (cho UserCard). GET/POST user_id. */
function userCardAction()
{
    $user = chat_require_user();
    if (!$user) return;
    $uid = (int) ($_GET['user_id'] ?? $_POST['user_id'] ?? 0);
    $card = $uid > 0 ? chat_user_card($uid) : null;
    chat_json($card ? ['ok' => true, 'user' => $card] : ['ok' => false, 'message' => 'Không tìm thấy người dùng.']);
}

/** 25. Chia sẻ lại 1 tin nhắn. POST message_id, conversation_ids[], user_ids[]. */
function forwardAction()
{
    $user = chat_require_user();
    if (!$user) return;
    $mid = (int) ($_POST['message_id'] ?? 0);
    $convIds = $_POST['conversation_ids'] ?? [];
    $userIds = $_POST['user_ids'] ?? [];
    if (!is_array($convIds)) $convIds = $convIds !== '' ? [$convIds] : [];
    if (!is_array($userIds)) $userIds = $userIds !== '' ? [$userIds] : [];
    chat_json(chat_forward_message($mid, $convIds, $userIds, (int) $user['id']));
}

/** 20. Tạo nhắc hẹn cho 1 tin. POST message_id, remind_at (Y-m-d H:i:s). */
function setReminderAction()
{
    $user = chat_require_user();
    if (!$user) return;
    $mid  = (int) ($_POST['message_id'] ?? 0);
    $at   = (string) ($_POST['remind_at'] ?? '');
    $note = (string) ($_POST['note'] ?? '');
    chat_json(chat_set_reminder((int) $user['id'], $mid, $at, $note));
}

/* =====================================================================
 *  TÀI KHOẢN HỆ THỐNG ("Safe King") — chỉ quản trị viên.
 *  Khối cài đặt nằm trong modal "Cài đặt trò chuyện" của widget.
 * ===================================================================== */

/** Chỉ cho qua nếu là admin; ngược lại trả JSON lỗi và dừng. */
function chat_bot_require_admin()
{
    $user = chat_require_user();
    if (!$user) return null;
    if (!permission_is_admin($user)) {
        chat_json(['ok' => false, 'message' => 'Chỉ quản trị viên mới cấu hình được tài khoản hệ thống.']);
        return null;
    }
    return $user;
}

/** 27. Dữ liệu màn hình cấu hình: tên hệ thống + ma trận quyền theo chủ đề. */
function botAdminAction()
{
    $user = chat_bot_require_admin();
    if (!$user) return;

    $topics = [];
    foreach (chatbot_topics() as $key => $t) {
        $topics[] = ['key' => $key, 'label' => $t['label'], 'desc' => $t['desc']];
    }
    chat_json([
        'ok'     => true,
        'name'   => chatbot_name(),
        'topics' => $topics,
        'users'  => chatbot_access_matrix(),
    ]);
}

/** 28. Đổi tên hiển thị của tài khoản hệ thống. POST name. */
function botSaveNameAction()
{
    $user = chat_bot_require_admin();
    if (!$user) return;
    chat_json(chatbot_set_name((string) ($_POST['name'] ?? '')));
}

/** 29. Gán lại chủ đề được phép hỏi cho 1 user. POST user_id, topics[]. */
function botSaveAccessAction()
{
    $user = chat_bot_require_admin();
    if (!$user) return;
    $target = (int) ($_POST['user_id'] ?? 0);
    $topics = $_POST['topics'] ?? [];
    if (!is_array($topics)) $topics = $topics !== '' ? [$topics] : [];
    if ($target <= 0) { chat_json(['ok' => false, 'message' => 'Thiếu user_id.']); return; }

    $ok = chatbot_set_user_topics($target, $topics, (int) $user['id']);
    chat_json($ok
        ? ['ok' => true, 'topics' => chatbot_user_topics($target)]
        : ['ok' => false, 'message' => 'Không lưu được quyền.']);
}
