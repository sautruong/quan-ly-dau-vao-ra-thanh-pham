<?php
// Hint cho IDE (PHP Intelephense) resolve load_* và dem_*/de_*.
if (!function_exists('__dem_intelephense_hint_stub')) {
    function __dem_intelephense_hint_stub()
    {
        require_once __DIR__ . '/../../../core/base.php';
        require_once __DIR__ . '/../models/daily_eventsModel.php';
    }
}

function construct()
{
    load_model('daily_events');
}

/** Trả JSON sạch (UTF-8, không escape unicode). */
function de_json($payload)
{
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($payload, JSON_UNESCAPED_UNICODE);
    exit;
}

function de_ctl_user()
{
    $u = dem_current_user();
    return [
        'id'       => (int) ($u['id'] ?? 0),
        'is_admin' => dem_is_admin($u),
    ];
}

/* ============================================================
 *  Page — "Thảo luận" (Tường kiểu Facebook). Xem libraries/daily_events_wall.php.
 * ============================================================ */
function daily_eventsAction()
{
    dem_ensure_tables();
    dem_wall_ensure_tables();
    dem_ensure_view_registered();
    $me = de_ctl_user();
    $me = array_merge($me, dew_user_brief($me['id'])); // + fullname/avatar cho JS (ô soạn bài, bình luận)
    // ?post=ID -> mở từ link trong chuông (gắn thẻ), kể cả bài chưa ghim vào tường.
    $post_param = (int) ($_GET['post'] ?? 0);
    $feed = dew_post_list($me['id'], 1, '', $me['is_admin']);
    $permalink = $post_param > 0 ? dew_post_get_single($post_param, $me['id'], $me['is_admin']) : null;
    load_view('daily_events', [
        'me'        => $me,
        'feed'      => $feed,
        'permalink' => $permalink,
    ]);
}

/* ============================================================
 *  AJAX — Tìm kiếm
 * ============================================================ */
function search_productsAction()
{
    de_json(['data' => de_search_products($_POST['keyword'] ?? '')]);
}

function search_usersAction()
{
    $me = de_ctl_user();
    de_json(['data' => de_search_users($_POST['keyword'] ?? '', $me['id'])]);
}

/* ============================================================
 *  AJAX — Danh sách sự kiện theo loại (+ tìm theo từ khóa + phân trang)
 * ============================================================ */
function item_listAction()
{
    $me = de_ctl_user();
    $type = (string) ($_POST['item_type'] ?? '');
    $kw   = (string) ($_POST['keyword'] ?? '');
    $page = max(1, (int) ($_POST['page'] ?? 1));
    $data = de_item_list($type, $kw, $me['id'], $me['is_admin'], $page, DE_PAGE_SIZE);
    $total = de_item_count($type, $kw, $me['id'], $me['is_admin']);
    de_json(['success' => true, 'data' => $data, 'total' => $total, 'page' => $page, 'per_page' => DE_PAGE_SIZE]);
}

/* ============================================================
 *  AJAX — Thêm / sửa / xóa sự kiện
 * ============================================================ */
function item_addAction()
{
    $me = de_ctl_user();
    $type = (string) ($_POST['item_type'] ?? '');
    $res = de_item_add($type, $_POST, $me['id']);
    de_json($res);
}

function item_updateAction()
{
    $me = de_ctl_user();
    $id = (int) ($_POST['id'] ?? 0);
    $res = de_item_update($id, $_POST, $me['id'], $me['is_admin']);
    de_json($res);
}

function item_deleteAction()
{
    $me = de_ctl_user();
    $id = (int) ($_POST['id'] ?? 0);
    $res = de_item_delete($id, $me['id'], $me['is_admin']);
    de_json($res);
}

/* ============================================================
 *  AJAX — Ảnh dẫn chứng (item hoặc comment)
 * ============================================================ */
function upload_imagesAction()
{
    $me = de_ctl_user();
    $owner_type = (string) ($_POST['owner_type'] ?? 'item');
    $owner_id   = (int) ($_POST['owner_id'] ?? 0);
    if (!de_can_manage_owner($owner_type, $owner_id, $me['id'], $me['is_admin'])) {
        de_json(['ok' => false, 'saved' => [], 'errors' => ['Bạn không có quyền đính kèm ảnh vào đây.']]);
    }
    $files = $_FILES['files'] ?? null;
    $res = de_save_images($owner_type, $owner_id, $files);
    de_json(['ok' => !empty($res['ok']), 'saved' => $res['saved'], 'errors' => $res['errors']]);
}

function image_deleteAction()
{
    $me = de_ctl_user();
    $id = (int) ($_POST['id'] ?? 0);
    $ok = de_delete_image($id, $me['id'], $me['is_admin']);
    de_json(['success' => $ok]);
}

/* ============================================================
 *  AJAX — Bình luận
 * ============================================================ */
function comment_addAction()
{
    $me = de_ctl_user();
    $item_id = (int) ($_POST['item_id'] ?? 0);
    $content = (string) ($_POST['content'] ?? '');
    $res = de_comment_add($item_id, $content, $me['id'], $me['is_admin']);
    de_json($res);
}

function comment_deleteAction()
{
    $me = de_ctl_user();
    $id = (int) ($_POST['id'] ?? 0);
    $res = de_comment_delete($id, $me['id'], $me['is_admin']);
    de_json($res);
}

/* ============================================================
 *  AJAX — Chia sẻ cho user khác
 * ============================================================ */
function share_addAction()
{
    $me = de_ctl_user();
    $item_id = (int) ($_POST['item_id'] ?? 0);
    $target  = (int) ($_POST['user_id'] ?? 0);
    de_json(de_share_add($item_id, $target, $me['id'], $me['is_admin']));
}

function share_removeAction()
{
    $me = de_ctl_user();
    $item_id = (int) ($_POST['item_id'] ?? 0);
    $target  = (int) ($_POST['user_id'] ?? 0);
    de_json(de_share_remove($item_id, $target, $me['id'], $me['is_admin']));
}

/* ============================================================
 *  AJAX — "Thảo luận" (Tường). Tiền tố wall_ để không trùng tên
 *  với các action cũ ở trên (item/comment/share — module cũ, xem
 *  libraries/daily_events.php). Logic thật ở libraries/daily_events_wall.php.
 * ============================================================ */
function wall_post_listAction()
{
    $me = de_ctl_user();
    $page = max(1, (int) ($_POST['page'] ?? 1));
    $hashtag = (string) ($_POST['hashtag'] ?? '');
    $keyword = (string) ($_POST['keyword'] ?? '');
    de_json(['success' => true] + dew_post_list($me['id'], $page, $hashtag, $me['is_admin'], $keyword));
}

/** Poll realtime: đồng bộ cảm xúc/bình luận cho các bài đang hiển thị trên máy client. */
function wall_pollAction()
{
    $me = de_ctl_user();
    $ids = $_POST['ids'] ?? [];
    if (!is_array($ids)) $ids = [];
    de_json(['success' => true, 'rows' => dew_posts_snapshot($ids, $me['id'], $me['is_admin'])]);
}

function wall_post_getAction()
{
    $me = de_ctl_user();
    $id = (int) ($_POST['id'] ?? 0);
    $post = dew_post_get_single($id, $me['id'], $me['is_admin']);
    de_json(['success' => $post !== null, 'data' => $post]);
}

/** Gợi ý #hashtag đã dùng trước đây khi đang gõ (bộ nhớ hashtag). */
function wall_hashtag_suggestAction()
{
    de_ctl_user(); // chỉ cần đăng nhập, không cần lọc theo quyền xem bài (hashtag không gắn danh tính)
    $kw = (string) ($_POST['keyword'] ?? '');
    de_json(['success' => true, 'data' => dew_hashtag_suggest($kw)]);
}

function wall_post_addAction()
{
    $me = de_ctl_user();
    $content = (string) ($_POST['content'] ?? '');
    $tagged = $_POST['tagged'] ?? [];
    if (!is_array($tagged)) $tagged = [];
    $has_attachments = !empty($_POST['has_attachments']);
    de_json(dew_post_add($me['id'], $content, $tagged, $has_attachments));
}

function wall_post_updateAction()
{
    $me = de_ctl_user();
    $id = (int) ($_POST['id'] ?? 0);
    $content = (string) ($_POST['content'] ?? '');
    $tagged = $_POST['tagged'] ?? null;
    if ($tagged !== null && !is_array($tagged)) $tagged = [];
    de_json(dew_post_update($id, $content, $me['id'], $tagged));
}

function wall_post_deleteAction()
{
    $me = de_ctl_user();
    $id = (int) ($_POST['id'] ?? 0);
    de_json(dew_post_delete($id, $me['id'], $me['is_admin']));
}

/** Chia sẻ lại một bài ĐÃ ĐĂNG cho user khác (không giới hạn 60 phút như sửa bài). */
function wall_share_addAction()
{
    $me = de_ctl_user();
    $id = (int) ($_POST['post_id'] ?? 0);
    $users = $_POST['user_ids'] ?? [];
    if (!is_array($users)) $users = [];
    de_json(dew_post_share_add($id, $users, $me['id'], $me['is_admin']));
}

/** Chia sẻ 1 ảnh/tệp đính kèm của Tường qua CHAT (gửi thành tin nhắn thật). */
function wall_share_to_chatAction()
{
    $me = de_ctl_user();
    $att = (int) ($_POST['attachment_id'] ?? 0);
    $users = $_POST['user_ids'] ?? [];
    $convs = $_POST['conversation_ids'] ?? [];
    if (!is_array($users)) $users = [];
    if (!is_array($convs)) $convs = [];
    de_json(dew_attachment_share_to_chat($att, $users, $convs, $me['id'], $me['is_admin']));
}

function wall_pin_toggleAction()
{
    $me = de_ctl_user();
    $id = (int) ($_POST['post_id'] ?? 0);
    de_json(dew_pin_toggle($id, $me['id']));
}

function wall_comment_addAction()
{
    $me = de_ctl_user();
    $post_id = (int) ($_POST['post_id'] ?? 0);
    $content = (string) ($_POST['content'] ?? '');
    $parent_id = (int) ($_POST['parent_id'] ?? 0);
    // Chỉ có ảnh (chụp từ camera), không gõ chữ -> vẫn gửi được.
    $has_attachments = !empty($_POST['has_attachments']);
    de_json(dew_comment_add($post_id, $content, $me['id'], $parent_id, $has_attachments));
}

function wall_comment_deleteAction()
{
    $me = de_ctl_user();
    $id = (int) ($_POST['id'] ?? 0);
    de_json(dew_comment_delete($id, $me['id']));
}

function wall_reactAction()
{
    $me = de_ctl_user();
    $type = (string) ($_POST['target_type'] ?? 'post');
    $id = (int) ($_POST['target_id'] ?? 0);
    $emoji = (string) ($_POST['emoji'] ?? '');
    // Chỉ cho thả cảm xúc vào bài (hoặc bình luận của bài) mà mình có quyền xem.
    $post_id = $type === 'comment' ? (int) (dew_comment_row($id)['post_id'] ?? 0) : $id;
    if (!$me['is_admin'] && !dew_can_view_post($post_id, $me['id'])) {
        de_json(['success' => false, 'message' => 'Bạn không có quyền thả cảm xúc ở đây.']);
    }
    de_json(['success' => true, 'reactions' => dew_react($type, $id, $me['id'], $emoji)]);
}

function wall_upload_addAction()
{
    $me = de_ctl_user();
    $owner_type = (string) ($_POST['owner_type'] ?? 'post');
    $owner_id = (int) ($_POST['owner_id'] ?? 0);
    if (!$me['is_admin'] && !dew_can_manage_attachment_owner($owner_type, $owner_id, $me['id'])) {
        de_json(['ok' => false, 'saved' => [], 'errors' => ['Bạn không có quyền đính kèm vào đây.']]);
    }
    $files = $_FILES['files'] ?? null;
    de_json(dew_save_attachments($owner_type, $owner_id, $files));
}

function wall_attachment_deleteAction()
{
    $me = de_ctl_user();
    $id = (int) ($_POST['id'] ?? 0);
    $ok = dew_attachment_delete($id, $me['id'], $me['is_admin']);
    de_json(['success' => $ok]);
}
