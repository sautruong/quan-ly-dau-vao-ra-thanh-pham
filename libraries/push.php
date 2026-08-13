<?php

/* =====================================================================
 *  push.php — THÔNG BÁO ĐẨY (Web Push) TỚI ĐIỆN THOẠI
 *  ---------------------------------------------------------------------
 *  Việc của file này: khi có tin nhắn hoặc thông báo mới, đẩy một thông báo
 *  xuống điện thoại người nhận KỂ CẢ KHI HỌ ĐÃ THOÁT APP, và gắn con số
 *  chưa đọc lên icon app ngoài màn hình chính (giống số đỏ trên icon Mail).
 *
 *  Điều kiện để chạy được (thiếu một cái là im lặng, không báo lỗi):
 *    1. Trang chạy HTTPS. (localhost được miễn khi thử máy)
 *    2. config/push.php có cặp khóa VAPID — xem config/push.sample.php.
 *    3. Người dùng đã bấm Cho phép thông báo.
 *    4. RIÊNG iPhone: bắt buộc phải "Thêm vào Màn hình chính" trước đã.
 *       Mở bằng Safari thường thì iOS không cho đăng ký push, không có cách vá.
 *
 *  Điều Apple KHÔNG cho phép: đẩy push im lặng chỉ để cập nhật con số.
 *  Mỗi lượt push bắt buộc hiện một thông báo cho người dùng thấy. Nên không
 *  có chế độ "chỉ chạy số, không hiện banner" — muốn im thì người dùng tự tắt
 *  tiếng cho app trong Cài đặt máy.
 *
 *  Vì sao gửi thẳng chứ không xếp hàng đợi: dự án không có cron, các việc hẹn
 *  giờ khác đi nhờ vòng poll của trình duyệt. Nhưng push tồn tại chính cho lúc
 *  KHÔNG AI mở app — lúc đó chẳng có vòng poll nào chạy để đẩy hàng đợi đi cả.
 *  Nên phải gửi ngay tại chỗ, và bù lại bằng cách đặt timeout ngắn (xem
 *  push_http_client) để một thiết bị chết không kéo chậm người đang gửi tin.
 * ===================================================================== */

if (!function_exists('push_ensure_table')) {

    /** Tạo bảng lưu đăng ký nhận push nếu chưa có (1 lần / request). */
    function push_ensure_table()
    {
        static $done = false;
        if ($done) return;
        $done = true;

        /* endpoint là URL do trình duyệt cấp, có thể dài vài trăm ký tự nên
           không đánh UNIQUE thẳng lên nó được (utf8mb4 giới hạn độ dài index).
           Giải pháp: lưu thêm endpoint_hash = SHA-256 của endpoint và đánh
           UNIQUE lên cột hash đó. */
        db_query("CREATE TABLE IF NOT EXISTS push_subscriptions (
            id            INT(11) NOT NULL AUTO_INCREMENT,
            user_id       INT(11) NOT NULL,
            endpoint      VARCHAR(500) NOT NULL,
            endpoint_hash CHAR(64) NOT NULL,
            p256dh        VARCHAR(255) NOT NULL,
            auth_key      VARCHAR(255) NOT NULL,
            user_agent    VARCHAR(255) DEFAULT NULL,
            created_at    DATETIME NOT NULL,
            last_ok_at    DATETIME DEFAULT NULL,
            fail_count    INT(11) NOT NULL DEFAULT 0,
            PRIMARY KEY (id),
            UNIQUE KEY uniq_endpoint (endpoint_hash),
            KEY idx_user (user_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci");
    }

    /* -----------------------------------------------------------------
     *  CẤU HÌNH
     * ----------------------------------------------------------------- */

    /**
     * Đọc cặp khóa VAPID từ config/push.php.
     * Trả về mảng [subject, publicKey, privateKey] hoặc null nếu chưa cấu hình.
     * (Cùng khuôn với crypto_key() trong libraries/crypto.php.)
     */
    function push_config()
    {
        static $cfg = false;
        if ($cfg !== false) return $cfg;

        global $config;
        $get = static function ($k) {
            global $config;
            return isset($config[$k]) ? trim((string) $config[$k]) : '';
        };

        $pub = $get('push_vapid_public');
        // Phòng khi lib được require ở ngữ cảnh chưa bootstrap đủ config.
        if ($pub === '' && defined('CONFIGPATH') && is_file(CONFIGPATH . DIRECTORY_SEPARATOR . 'push.php')) {
            require CONFIGPATH . DIRECTORY_SEPARATOR . 'push.php';
            $pub = $get('push_vapid_public');
        }
        $prv = $get('push_vapid_private');
        $sub = $get('push_vapid_subject');

        if ($pub === '' || $prv === '') { $cfg = null; return null; }
        if ($sub === '') $sub = 'mailto:admin@localhost';

        $cfg = ['subject' => $sub, 'publicKey' => $pub, 'privateKey' => $prv];
        return $cfg;
    }

    /** Đã cấu hình khóa VAPID chưa. */
    function push_is_configured()
    {
        return push_config() !== null;
    }

    /** Khóa công khai để nhúng vào JS (chuỗi rỗng nếu chưa cấu hình). */
    function push_public_key()
    {
        $c = push_config();
        return $c ? $c['publicKey'] : '';
    }

    /**
     * Sinh cặp khóa VAPID mới. Trả ['publicKey' => ..., 'privateKey' => ...].
     *
     * KHÔNG dùng VAPID::createVapidKeys() của thư viện: hàm đó gọi
     * openssl_pkey_new() với tham số dạng phẳng (web-token/jwt-library,
     * Core/Util/ECKey.php) — dạng đó thất bại trên PHP/Windows khi máy không
     * có openssl.cnf ở đường dẫn mặc định. Dạng lồng 'ec' => [...] dưới đây
     * chạy được ở mọi máy, không cần cấu hình gì.
     */
    function push_generate_vapid_keys()
    {
        $key = openssl_pkey_new(['ec' => [
            'curve_name'       => 'prime256v1',
            'private_key_type' => OPENSSL_KEYTYPE_EC,
        ]]);
        if (!$key) return null;

        $d = openssl_pkey_get_details($key);
        if (!$d || ($d['type'] ?? null) !== OPENSSL_KEYTYPE_EC) return null;

        $pad = static function ($s) { return str_pad($s, 32, "\0", STR_PAD_LEFT); };
        $b64 = static function ($s) { return rtrim(strtr(base64_encode($s), '+/', '-_'), '='); };

        return [
            'publicKey'  => $b64("\x04" . $pad($d['ec']['x']) . $pad($d['ec']['y'])),
            'privateKey' => $b64($pad($d['ec']['d'])),
        ];
    }

    /* -----------------------------------------------------------------
     *  ĐĂNG KÝ THIẾT BỊ
     * ----------------------------------------------------------------- */

    /**
     * Lưu (hoặc cập nhật) một đăng ký nhận push của 1 thiết bị.
     * Một người có thể đăng ký nhiều máy — mỗi endpoint là một dòng.
     * Nếu endpoint đã tồn tại (đăng ký lại, hoặc đổi tài khoản trên cùng máy)
     * thì cập nhật lại chủ sở hữu và khóa, không tạo dòng mới.
     */
    function push_save_subscription($user_id, $endpoint, $p256dh, $auth, $user_agent = '')
    {
        $uid = (int) $user_id;
        $endpoint = trim((string) $endpoint);
        $p256dh   = trim((string) $p256dh);
        $auth     = trim((string) $auth);
        if ($uid <= 0 || $endpoint === '' || $p256dh === '' || $auth === '') return 0;
        if (!preg_match('#^https://#i', $endpoint)) return 0;   // chỉ nhận endpoint https hợp lệ

        push_ensure_table();
        $hash = hash('sha256', $endpoint);
        $now  = date('Y-m-d H:i:s');

        $old = db_fetch_row("SELECT id FROM push_subscriptions
                             WHERE endpoint_hash = '" . escape_string($hash) . "' LIMIT 1");
        if ($old) {
            db_update('push_subscriptions', [
                'user_id'    => $uid,
                'p256dh'     => $p256dh,
                'auth_key'   => $auth,
                'user_agent' => mb_substr((string) $user_agent, 0, 255),
                'fail_count' => 0,
            ], 'id = ' . (int) $old['id']);
            return (int) $old['id'];
        }

        return (int) db_insert('push_subscriptions', [
            'user_id'       => $uid,
            'endpoint'      => $endpoint,
            'endpoint_hash' => $hash,
            'p256dh'        => $p256dh,
            'auth_key'      => $auth,
            'user_agent'    => mb_substr((string) $user_agent, 0, 255),
            'created_at'    => $now,
            'fail_count'    => 0,
        ]);
    }

    /** Xóa 1 đăng ký theo endpoint (người dùng tắt thông báo trên máy đó). */
    function push_forget_subscription($endpoint)
    {
        $endpoint = trim((string) $endpoint);
        if ($endpoint === '') return false;
        push_ensure_table();
        return db_delete('push_subscriptions',
            "endpoint_hash = '" . escape_string(hash('sha256', $endpoint)) . "'");
    }

    /** Danh sách thiết bị đã đăng ký của 1 người. */
    function push_user_subscriptions($user_id)
    {
        $uid = (int) $user_id;
        if ($uid <= 0) return [];
        push_ensure_table();
        return db_fetch_array("SELECT * FROM push_subscriptions
                               WHERE user_id = $uid ORDER BY id ASC") ?: [];
    }

    /** Số thiết bị đang nhận push của 1 người. */
    function push_device_count($user_id)
    {
        return count(push_user_subscriptions($user_id));
    }

    /* -----------------------------------------------------------------
     *  GỬI
     * ----------------------------------------------------------------- */

    /**
     * Con số sẽ gắn lên icon app = tin nhắn chưa đọc + thông báo chưa đọc.
     * Đúng nghĩa "số tin", không phải "số cuộc hội thoại": chat_unread_total()
     * đếm từng dòng tin nhắn, notify_unread_count() đếm từng thông báo.
     */
    function push_badge_count($user_id)
    {
        $uid = (int) $user_id;
        if ($uid <= 0) return 0;
        $n = 0;
        if (function_exists('chat_unread_total'))   $n += (int) chat_unread_total($uid);
        if (function_exists('notify_unread_count')) $n += (int) notify_unread_count($uid);
        return $n;
    }

    /**
     * Người này có đang tắt thông báo không (tôn trọng cài đặt sẵn có của chat:
     * chat_user_settings.mute_all_until).
     */
    function push_is_muted($user_id)
    {
        if (!function_exists('chat_get_user_settings')) return false;
        $s = chat_get_user_settings((int) $user_id);
        $until = isset($s['mute_all_until']) ? (string) $s['mute_all_until'] : '';
        return $until !== '' && strtotime($until) > time();
    }

    /**
     * HTTP client dùng để bắn push. Timeout ngắn có chủ đích: người gửi tin
     * đang đứng chờ request này xong, không được để một thiết bị chết treo họ.
     */
    function push_http_client()
    {
        return new GuzzleHttp\Client([
            'timeout'         => 6,
            'connect_timeout' => 3,
            'http_errors'     => false,
        ]);
    }

    /**
     * Đẩy 1 thông báo tới TẤT CẢ thiết bị của 1 người.
     *
     * $data nhận: title, body, url, tag, type.
     * Con số badge tự tính, không cần truyền.
     *
     * Trả về số thiết bị nhận thành công. Luôn nuốt lỗi — push hỏng thì thôi,
     * tuyệt đối không được làm vỡ việc gửi tin nhắn đang diễn ra.
     */
    function push_send_to_user($user_id, array $data)
    {
        $uid = (int) $user_id;
        if ($uid <= 0) return 0;
        if (!push_is_configured()) return 0;
        if (push_is_muted($uid))   return 0;

        $subs = push_user_subscriptions($uid);
        if (!$subs) return 0;

        if (!class_exists('Minishlink\WebPush\WebPush')) {
            $autoload = dirname(__DIR__) . '/vendor/autoload.php';
            if (!is_file($autoload)) return 0;
            require_once $autoload;
        }

        $cfg = push_config();
        $payload = json_encode([
            'title' => (string) ($data['title'] ?? 'Thông báo mới'),
            'body'  => (string) ($data['body']  ?? ''),
            'url'   => (string) ($data['url']   ?? ''),
            'tag'   => (string) ($data['tag']   ?? 'nvsxvat'),
            'type'  => (string) ($data['type']  ?? 'general'),
            // 'count' chứ không phải 'badge': trong service worker, 'badge' đã là
            // tên tuỳ chọn ảnh biểu tượng nhỏ của showNotification() — đặt trùng
            // tên rất dễ dẫn tới sửa nhầm chỗ về sau.
            'count' => push_badge_count($uid),
        ], JSON_UNESCAPED_UNICODE);

        $ok = 0;
        try {
            $webPush = new Minishlink\WebPush\WebPush(
                ['VAPID' => $cfg],
                ['TTL' => 86400, 'urgency' => 'high'],
                push_http_client()
            );

            foreach ($subs as $s) {
                try {
                    $webPush->queueNotification(
                        Minishlink\WebPush\Subscription::create([
                            'endpoint'        => $s['endpoint'],
                            'publicKey'       => $s['p256dh'],
                            'authToken'       => $s['auth_key'],
                            'contentEncoding' => 'aes128gcm',
                        ]),
                        $payload
                    );
                } catch (Throwable $e) {
                    // 1 đăng ký hỏng định dạng thì bỏ qua, vẫn gửi cho máy còn lại.
                }
            }

            foreach ($webPush->flush() as $report) {
                $hash = escape_string(hash('sha256', $report->getEndpoint()));
                if ($report->isSuccess()) {
                    $ok++;
                    db_query("UPDATE push_subscriptions
                              SET last_ok_at = '" . date('Y-m-d H:i:s') . "', fail_count = 0
                              WHERE endpoint_hash = '$hash'");
                } elseif ($report->isSubscriptionExpired()) {
                    // Máy đã gỡ app / thu hồi quyền → dọn luôn, đừng gửi nữa.
                    db_query("DELETE FROM push_subscriptions WHERE endpoint_hash = '$hash'");
                } else {
                    // Hỏng tạm thời (mạng, 5xx). Hỏng liên tiếp nhiều lần mới xóa.
                    db_query("UPDATE push_subscriptions
                              SET fail_count = fail_count + 1 WHERE endpoint_hash = '$hash'");
                    db_query("DELETE FROM push_subscriptions
                              WHERE endpoint_hash = '$hash' AND fail_count >= 10");
                }
            }
        } catch (Throwable $e) {
            return $ok;
        }

        return $ok;
    }

    /** Đẩy cùng một thông báo tới nhiều người. Trả tổng số thiết bị đã nhận. */
    function push_send_to_users(array $user_ids, array $data)
    {
        $n = 0;
        foreach (array_unique(array_map('intval', $user_ids)) as $uid) {
            if ($uid > 0) $n += push_send_to_user($uid, $data);
        }
        return $n;
    }

    /* =================================================================
     *  HAI ĐIỂM MÓC
     *  -----------------------------------------------------------------
     *  Cả hệ thống chỉ có đúng 2 chỗ phát sinh "cái mới cần báo":
     *      chat_insert_message()  (libraries/chat.php)  — mọi tin nhắn
     *      notify_create()        (libraries/notifications.php) — mọi thông báo
     *  Nên chỉ cần móc vào 2 chỗ đó là phủ hết, không sót đường nào.
     * ================================================================= */

    /**
     * Có tin nhắn mới → đẩy cho các thành viên khác trong hội thoại.
     *
     * $body là chuỗi GỐC chưa mã hóa (chat_insert_message nhận vào rồi mới
     * crypto_encrypt lúc ghi), nên ở đây dùng thẳng, không phải giải mã.
     */
    function push_on_chat_message($conversation_id, $sender_id, $body, $type = 'text', $admin_only = 0)
    {
        $cid = (int) $conversation_id;
        $me  = (int) $sender_id;
        if ($cid <= 0 || $me <= 0) return 0;

        // Tin hệ thống ("A đã thêm B vào nhóm"...) không đáng làm rung điện thoại,
        // và chat_unread_total cũng không đếm chúng.
        if ($type === 'system') return 0;
        // Tin chỉ trưởng nhóm thấy — bỏ qua cho gọn, tránh lộ nội dung sai người.
        if ((int) $admin_only === 1) return 0;
        if (!push_is_configured()) return 0;
        if (!function_exists('chat_participant_ids')) return 0;

        $preview = trim((string) $body);
        if ($preview !== '' && function_exists('chat_strip_format_tags')) {
            $preview = chat_strip_format_tags($preview);
        }
        if ($preview === '') $preview = '[Tệp đính kèm]';
        $preview = mb_substr($preview, 0, 120, 'UTF-8');

        // Tên người gửi + tên nhóm: lấy MỘT lần theo góc nhìn người gửi.
        // Với nhóm thì tên nhóm ai nhìn cũng như nhau; với chat 1-1 thì chỉ có
        // đúng một người nhận nên lấy tên người gửi làm tiêu đề là chuẩn.
        $who = '';
        if (function_exists('chat_user_brief')) {
            $b = chat_user_brief($me);
            $who = trim((string) ($b['fullname'] ?? ''));
        }
        if ($who === '') $who = 'Tin nhắn mới';

        $title = $who;
        $line  = $preview;
        if (function_exists('chat_conversation_meta')) {
            $meta = chat_conversation_meta($cid, $me);
            if ($meta && ($meta['type'] ?? '') === 'group') {
                $title = trim((string) ($meta['name'] ?? '')) ?: 'Nhóm chat';
                $line  = $who . ': ' . $preview;
            }
        }

        $sent = 0;
        foreach (chat_participant_ids($cid) as $uid) {
            $uid = (int) $uid;
            if ($uid <= 0 || $uid === $me) continue;   // không tự gửi cho mình

            // Tắt thông báo RIÊNG hội thoại này (lớp mute thứ hai, độc lập với
            // mute toàn cục đã kiểm trong push_send_to_user).
            if (function_exists('chat_my_mute') && chat_my_mute($cid, $uid) !== '') continue;

            $sent += push_send_to_user($uid, [
                'title' => $title,
                'body'  => $line,
                'tag'   => 'chat-' . $cid,   // tin sau thay tin trước, không xếp chồng
                'type'  => 'chat',
            ]);
        }
        return $sent;
    }

    /**
     * Có thông báo mới (chuông) → đẩy cho người nhận.
     *
     * Bỏ qua 'chat_mention': người được nhắc tên đã nhận push của chính tin nhắn
     * đó rồi (push_on_chat_message), đẩy thêm nữa là rung điện thoại hai lần cho
     * cùng một việc.
     */
    function push_on_notification($user_id, $title, $message = '', $link = '', $type = 'general')
    {
        if ($type === 'chat_mention') return 0;
        if (!push_is_configured()) return 0;

        return push_send_to_user((int) $user_id, [
            'title' => (string) $title,
            'body'  => mb_substr(trim((string) $message), 0, 160, 'UTF-8'),
            'url'   => (string) $link,
            'tag'   => 'noti',
            'type'  => (string) $type,
        ]);
    }
}
