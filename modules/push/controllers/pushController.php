<?php
defined('APPPATH') OR exit('Không được quyền truy cập phần này');

/**
 * Module "push" — thông báo đẩy tới điện thoại (Web Push).
 *
 * Giống reminder_points: module này KHÔNG có view/trang riêng, không đăng ký
 * trong tbl_views — chỉ gồm các *Action() AJAX phục vụ nút "Bật thông báo trên
 * điện thoại" và service worker. Toàn bộ nghiệp vụ nằm ở libraries/push.php.
 *
 * Vì không có trong tbl_views nên permission_guard cho qua tự do: mọi tài khoản
 * đã đăng nhập đều gọi được. Điều đó ĐÚNG với các action ở đây vì chúng chỉ
 * thao tác trên thiết bị của CHÍNH người đang đăng nhập — trừ genkeys, action
 * đó tự chặn riêng chỉ cho admin.
 */

require_once __DIR__ . '/../../../libraries/push.php';

function construct()
{
    // Không có model riêng — mọi thứ nằm ở libraries/push.php.
}

/** Trả JSON và dừng. Đặt header TRƯỚC mọi echo, nếu không bộ lọc HTML sẽ chạy vào JSON. */
function push_json($payload)
{
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($payload, JSON_UNESCAPED_UNICODE);
    exit;
}

/** Người đang đăng nhập, hoặc 0. */
function push_me()
{
    $u = permission_current_user();
    return $u ? (int) $u['id'] : 0;
}

/* =====================================================================
 *  Đăng ký / huỷ đăng ký thiết bị
 * ===================================================================== */

/**
 * Trạng thái push cho trang hiện tại: đã cấu hình khóa chưa, khóa công khai
 * để trình duyệt đăng ký, và máy này đã đăng ký chưa.
 */
function statusAction()
{
    $uid = push_me();
    if ($uid <= 0) push_json(['ok' => false, 'msg' => 'Chưa đăng nhập']);

    push_json([
        'ok'         => true,
        'configured' => push_is_configured(),
        'publicKey'  => push_public_key(),
        'devices'    => push_device_count($uid),
    ]);
}

/** Lưu đăng ký nhận push của thiết bị đang dùng. */
function subscribeAction()
{
    $uid = push_me();
    if ($uid <= 0) push_json(['ok' => false, 'msg' => 'Chưa đăng nhập']);

    $endpoint = (string) ($_POST['endpoint'] ?? '');
    $p256dh   = (string) ($_POST['p256dh']   ?? '');
    $auth     = (string) ($_POST['auth']     ?? '');

    $id = push_save_subscription($uid, $endpoint, $p256dh, $auth, $_SERVER['HTTP_USER_AGENT'] ?? '');
    if ($id <= 0) push_json(['ok' => false, 'msg' => 'Dữ liệu đăng ký không hợp lệ']);

    push_json(['ok' => true, 'devices' => push_device_count($uid)]);
}

/** Bỏ đăng ký thiết bị đang dùng (người dùng tắt thông báo trên máy này). */
function unsubscribeAction()
{
    $uid = push_me();
    if ($uid <= 0) push_json(['ok' => false, 'msg' => 'Chưa đăng nhập']);

    push_forget_subscription((string) ($_POST['endpoint'] ?? ''));
    push_json(['ok' => true, 'devices' => push_device_count($uid)]);
}

/**
 * Gửi thử một push cho chính mình — để người dùng biết đã bật thành công,
 * và để chẩn đoán khi "bật rồi mà không thấy gì".
 */
function testAction()
{
    $uid = push_me();
    if ($uid <= 0) push_json(['ok' => false, 'msg' => 'Chưa đăng nhập']);

    if (!push_is_configured()) {
        push_json(['ok' => false, 'msg' => 'Máy chủ chưa cấu hình khóa VAPID (config/push.php)']);
    }
    if (push_device_count($uid) <= 0) {
        push_json(['ok' => false, 'msg' => 'Máy này chưa bật thông báo']);
    }

    $sent = push_send_to_user($uid, [
        'title' => 'Vua An Toàn',
        'body'  => 'Thông báo thử — nếu thấy dòng này là đã bật thành công.',
        'tag'   => 'push-test',
        'type'  => 'test',
    ]);

    if ($sent <= 0) {
        push_json([
            'ok'  => false,
            'msg' => 'Không gửi được. Có thể đang bật "tắt thông báo" trong Cài đặt trò chuyện, '
                   . 'hoặc đăng ký của máy đã hết hạn — hãy tắt rồi bật lại.',
        ]);
    }
    push_json(['ok' => true, 'sent' => $sent]);
}

/* =====================================================================
 *  Công cụ cho quản trị
 * ===================================================================== */

/**
 * Sinh cặp khóa VAPID mới để dán vào config/push.php.
 *
 * Trả text/plain cho dễ chép, và để tránh bộ lọc HTML của nav chạy vào nội dung.
 * CHỈ admin. Sinh khóa mới KHÔNG tự ghi đè cấu hình — người quản trị tự dán vào,
 * vì đổi khóa là làm chết toàn bộ đăng ký cũ.
 */
function genkeysAction()
{
    $u = permission_current_user();
    if (!$u || ($u['role'] ?? '') !== 'admin') {
        header('Content-Type: text/plain; charset=utf-8');
        echo "Chỉ tài khoản admin mới được sinh khóa.\n";
        exit;
    }

    $k = push_generate_vapid_keys();
    header('Content-Type: text/plain; charset=utf-8');
    if (!$k) {
        echo "Không sinh được khóa — PHP thiếu phần mở rộng openssl.\n";
        exit;
    }

    echo "Chép 3 dòng dưới đây vào file config/push.php trên máy chủ:\n\n";
    echo "\$config['push_vapid_subject'] = 'mailto:doi-thanh-email-cua-ban@example.com';\n";
    echo "\$config['push_vapid_public']  = '" . $k['publicKey'] . "';\n";
    echo "\$config['push_vapid_private'] = '" . $k['privateKey'] . "';\n\n";
    echo "LƯU Ý: đổi khóa sẽ làm mọi thiết bị đã bật thông báo trước đó ngừng nhận.\n";
    echo "Sinh một lần rồi giữ nguyên.\n";
    exit;
}
