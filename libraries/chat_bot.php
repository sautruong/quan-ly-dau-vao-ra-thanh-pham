<?php
/**
 * chat_bot — TÀI KHOẢN HỆ THỐNG trong hộp chat (mặc định tên "Safe King").
 * ---------------------------------------------------------------------------
 * Là 1 user thật trong tbl_users (status='system', KHÔNG đăng nhập được vì
 * đăng nhập yêu cầu status='active') nên tái dùng được toàn bộ cơ chế sẵn có
 * của [[chat-widget]]: hội thoại direct, tin nhắn, badge chưa đọc, toast…
 *
 * Nhiệm vụ: nhận câu hỏi của người dùng → tự truy vấn cơ sở dữ liệu → trả lời
 * ngay trong khung chat. Hiện hỗ trợ 2 chủ đề:
 *   - formula : công thức sản xuất (product_batch_recipes / product_materials)
 *   - stock   : tồn kho thành phẩm (finished_goods_inventory)
 *
 * Phân quyền: mặc định CHỈ admin được trò chuyện; admin cấp quyền cho từng user
 * theo TỪNG CHỦ ĐỀ (bảng chat_bot_access). Không có chủ đề nào → không thấy tài
 * khoản hệ thống trong danh bạ.
 *
 * Tên hiển thị lưu ở app_settings key 'chatbot.name' (admin sửa được trong
 * Cài đặt trò chuyện) và luôn đồng bộ ngược vào tbl_users.fullname.
 *
 * Prefix hàm: chatbot_*. Mọi hàm bọc trong guard function_exists (require_once
 * từ nhiều nơi, giống chat.php / notifications.php).
 */

require_once __DIR__ . '/chat.php';

if (!function_exists('chatbot_ensure_schema')) {

    /** Username cố định của tài khoản hệ thống (KHÔNG đổi khi admin đổi tên hiển thị). */
    if (!defined('CHATBOT_USERNAME'))     define('CHATBOT_USERNAME', 'safe_king');
    /** Tên hiển thị mặc định lúc mới tạo. */
    if (!defined('CHATBOT_DEFAULT_NAME')) define('CHATBOT_DEFAULT_NAME', 'Safe King');
    /** status riêng để mọi danh sách người dùng loại tài khoản này ra. */
    if (!defined('CHATBOT_STATUS'))       define('CHATBOT_STATUS', 'system');

    /* =====================================================================
     *  1. Schema + tài khoản hệ thống
     * ===================================================================== */

    /** Tạo bảng phân quyền chủ đề (1 lần / request). */
    function chatbot_ensure_schema()
    {
        static $done = false;
        if ($done) return;
        $done = true;

        chat_ensure_tables();

        // Quyền trò chuyện với tài khoản hệ thống, chi tiết tới từng CHỦ ĐỀ.
        // Không có dòng nào = user đó không được chat với hệ thống.
        db_query("CREATE TABLE IF NOT EXISTS chat_bot_access (
            user_id     INT(11) NOT NULL,
            topic       VARCHAR(32) NOT NULL,
            granted_by  INT(11) NOT NULL DEFAULT 0,
            granted_at  DATETIME NOT NULL,
            PRIMARY KEY (user_id, topic),
            KEY idx_user (user_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci");
    }

    /**
     * id của tài khoản hệ thống — tự tạo nếu chưa có.
     * Trả 0 nếu vì lý do nào đó không tạo được (FE sẽ coi như không có bot).
     */
    function chatbot_user_id()
    {
        static $id = null;
        if ($id !== null) return $id;
        chatbot_ensure_schema();

        $u = escape_string(CHATBOT_USERNAME);
        $row = db_fetch_row("SELECT id FROM tbl_users WHERE username = '{$u}' LIMIT 1");
        if ($row) { $id = (int) $row['id']; return $id; }

        // Mật khẩu ngẫu nhiên không ai biết + status='system' → không thể đăng nhập.
        $id = (int) db_insert('tbl_users', [
            'fullname'    => chatbot_stored_name(),
            'dateofbirth' => '1970-01-01',
            'gender'      => 'M',
            'phone'       => '',
            'email'       => CHATBOT_USERNAME . '@system.local',
            'username'    => CHATBOT_USERNAME,
            'password'    => md5(uniqid('chatbot', true)),
            'status'      => CHATBOT_STATUS,
            'role'        => 'system',
            'created_at'  => date('Y-m-d H:i:s'),
        ]);
        return $id;
    }

    /** Có phải id của tài khoản hệ thống không. */
    function chatbot_is_bot($user_id)
    {
        $bid = chatbot_user_id();
        return $bid > 0 && (int) $user_id === $bid;
    }

    /** Tên hiển thị đang lưu ở app_settings (chưa chắc đã có tài khoản). */
    function chatbot_stored_name()
    {
        system_settings_ensure_table();
        $row = db_fetch_row("SELECT setting_value FROM app_settings WHERE setting_key = 'chatbot.name' LIMIT 1");
        $name = $row ? trim((string) $row['setting_value']) : '';
        return $name !== '' ? $name : CHATBOT_DEFAULT_NAME;
    }

    /** Tên hiển thị của tài khoản hệ thống. */
    function chatbot_name()
    {
        return chatbot_stored_name();
    }

    /** Admin đổi tên tài khoản hệ thống (đồng bộ luôn tbl_users.fullname). */
    function chatbot_set_name($name)
    {
        $name = trim((string) $name);
        if ($name === '') return ['ok' => false, 'message' => 'Tên không được để trống.'];
        if (mb_strlen($name, 'UTF-8') > 100) $name = mb_substr($name, 0, 100, 'UTF-8');

        system_settings_ensure_table();
        $val = $name;
        $exists = db_num_rows("SELECT 1 FROM app_settings WHERE setting_key = 'chatbot.name'") > 0;
        if ($exists) {
            db_update('app_settings', ['setting_value' => $val], "setting_key = 'chatbot.name'");
        } else {
            db_insert('app_settings', ['setting_key' => 'chatbot.name', 'setting_value' => $val]);
        }
        $bid = chatbot_user_id();
        if ($bid > 0) db_update('tbl_users', ['fullname' => $name], "id = {$bid}");
        return ['ok' => true, 'name' => $name];
    }

    /* =====================================================================
     *  2. Chủ đề + phân quyền
     * ===================================================================== */

    /** Danh mục chủ đề bot hiểu được. Thêm chủ đề mới thì khai báo ở đây. */
    function chatbot_topics()
    {
        return [
            'formula' => [
                'label'   => 'Công thức sản xuất',
                'desc'    => 'Hỏi công thức mẻ sản xuất của một sản phẩm.',
                'example' => 'Gửi tôi công thức bột phô mai',
            ],
            'stock' => [
                'label'   => 'Tồn kho sản phẩm',
                'desc'    => 'Hỏi số lượng thành phẩm đang còn trong kho.',
                'example' => 'Tồn kho bột phô mai còn bao nhiêu',
            ],
        ];
    }

    /** Nhãn chủ đề (rỗng nếu key lạ). */
    function chatbot_topic_label($topic)
    {
        $t = chatbot_topics();
        return isset($t[$topic]) ? $t[$topic]['label'] : '';
    }

    /** Các chủ đề 1 user được phép hỏi. Admin = tất cả. */
    function chatbot_user_topics($user_id)
    {
        chatbot_ensure_schema();
        $uid = (int) $user_id;
        if ($uid <= 0) return [];

        $user = db_fetch_row("SELECT id, role FROM tbl_users WHERE id = {$uid} LIMIT 1");
        if (!$user) return [];
        if (($user['role'] ?? '') === 'admin') return array_keys(chatbot_topics());

        $rows = db_fetch_array("SELECT topic FROM chat_bot_access WHERE user_id = {$uid}") ?: [];
        $valid = chatbot_topics();
        $out = [];
        foreach ($rows as $r) {
            $t = (string) $r['topic'];
            if (isset($valid[$t])) $out[] = $t;
        }
        return $out;
    }

    /** User này có được hỏi chủ đề $topic không. */
    function chatbot_can($user_id, $topic)
    {
        return in_array((string) $topic, chatbot_user_topics($user_id), true);
    }

    /** Có được trò chuyện với tài khoản hệ thống không (ít nhất 1 chủ đề). */
    function chatbot_can_chat($user_id)
    {
        return count(chatbot_user_topics($user_id)) > 0;
    }

    /** Admin gán lại danh sách chủ đề cho 1 user (ghi đè toàn bộ). */
    function chatbot_set_user_topics($user_id, array $topics, $actor_id = 0)
    {
        chatbot_ensure_schema();
        $uid = (int) $user_id;
        if ($uid <= 0 || chatbot_is_bot($uid)) return false;

        $valid = chatbot_topics();
        $now   = date('Y-m-d H:i:s');
        db_delete('chat_bot_access', "user_id = {$uid}");
        foreach ($topics as $t) {
            $t = (string) $t;
            if (!isset($valid[$t])) continue;
            db_insert('chat_bot_access', [
                'user_id'    => $uid,
                'topic'      => $t,
                'granted_by' => (int) $actor_id,
                'granted_at' => $now,
            ]);
        }
        return true;
    }

    /** Bảng phân quyền cho màn hình admin: mọi user (trừ bot) kèm chủ đề đang có. */
    function chatbot_access_matrix()
    {
        chatbot_ensure_schema();
        $bid  = chatbot_user_id();
        $rows = db_fetch_array(
            "SELECT id, fullname, username, role
             FROM tbl_users
             WHERE id <> {$bid} AND (status IS NULL OR status NOT IN ('blocked', 'left', '" . CHATBOT_STATUS . "'))
             ORDER BY (role = 'admin') DESC, fullname, username"
        ) ?: [];
        $granted = [];
        foreach ((db_fetch_array("SELECT user_id, topic FROM chat_bot_access") ?: []) as $g) {
            $granted[(int) $g['user_id']][] = (string) $g['topic'];
        }
        $valid = chatbot_topics();
        $out = [];
        foreach ($rows as $r) {
            $uid     = (int) $r['id'];
            $isAdmin = ($r['role'] ?? '') === 'admin';
            $topics  = $isAdmin ? array_keys($valid)
                                : array_values(array_intersect($granted[$uid] ?? [], array_keys($valid)));
            $out[] = [
                'id'       => $uid,
                'fullname' => $r['fullname'] !== '' ? $r['fullname'] : $r['username'],
                'username' => $r['username'],
                'is_admin' => $isAdmin,      // admin luôn đủ quyền, không tick/bỏ tick được
                'topics'   => $topics,
            ];
        }
        return $out;
    }

    /* =====================================================================
     *  3. Hội thoại với bot
     * ===================================================================== */

    /** Hội thoại này có phải đang chat với tài khoản hệ thống không. */
    function chatbot_is_bot_conversation($conversation_id)
    {
        $bid = chatbot_user_id();
        if ($bid <= 0) return false;
        $cid = (int) $conversation_id;
        $c = db_fetch_row("SELECT type FROM chat_conversations WHERE id = {$cid} LIMIT 1");
        if (!$c || $c['type'] !== 'direct') return false;
        return db_num_rows("SELECT 1 FROM chat_participants
                            WHERE conversation_id = {$cid} AND user_id = {$bid}") > 0;
    }

    /** Mở (hoặc tạo) hội thoại giữa user và bot. Lần đầu tự gửi lời chào. */
    function chatbot_open_conversation($user_id)
    {
        $uid = (int) $user_id;
        $bid = chatbot_user_id();
        if ($uid <= 0 || $bid <= 0) return 0;

        $cid = chat_get_or_create_direct($uid, $bid);
        if ($cid <= 0) return 0;
        // Hội thoại trống → chào + hướng dẫn (chỉ 1 lần, vì lần sau đã có tin).
        if (db_num_rows("SELECT 1 FROM chat_messages WHERE conversation_id = {$cid} LIMIT 1") === 0) {
            chatbot_say($cid, chatbot_greeting_text($uid));
        }
        return $cid;
    }

    /** Lời chào + danh sách chủ đề user được phép hỏi. */
    function chatbot_greeting_text($user_id)
    {
        $me = chat_user_brief((int) $user_id);
        return 'Xin chào ' . $me['fullname'] . "!\n"
            . 'Tôi là [b]' . chatbot_name() . '[/b] — tài khoản hệ thống. Bạn có thể hỏi tôi và tôi sẽ tra cứu '
            . "trực tiếp trong dữ liệu của nhà máy.\n\n"
            . chatbot_topic_list_text($user_id, 'Chủ đề bạn được hỏi:');
    }

    /* =====================================================================
     *  4. Gửi tin thay mặt bot
     * ===================================================================== */

    /**
     * Bot gửi 1 tin vào hội thoại. $options = danh sách nút bấm gợi ý:
     *   [ ['t' => 'nhãn nút', 'k' => 'formula|stock|cancel', 'p' => product_id], ... ]
     * Lưu ở chat_messages.meta (JSON) — chat_format_messages trả về cho FE vẽ nút.
     */
    function chatbot_say($conversation_id, $body, array $options = [])
    {
        $cid = (int) $conversation_id;
        $bid = chatbot_user_id();
        if ($cid <= 0 || $bid <= 0) return 0;

        $mid = chat_insert_message($cid, $bid, (string) $body, 'text');
        if ($mid > 0 && $options) {
            db_update('chat_messages', [
                'meta' => json_encode(['options' => $options], JSON_UNESCAPED_UNICODE),
            ], "id = {$mid}");
        }
        return $mid;
    }

    /** Đánh dấu 1 tin gợi ý đã được chọn → FE vô hiệu hóa cụm nút (không bấm lại được). */
    function chatbot_mark_options_used($message_id)
    {
        $mid = (int) $message_id;
        if ($mid <= 0) return;
        $row = db_fetch_row("SELECT meta FROM chat_messages WHERE id = {$mid} LIMIT 1");
        if (!$row || empty($row['meta'])) return;
        $meta = json_decode((string) $row['meta'], true);
        if (!is_array($meta) || empty($meta['options'])) return;
        $meta['used'] = 1;
        db_update('chat_messages', ['meta' => json_encode($meta, JSON_UNESCAPED_UNICODE)], "id = {$mid}");
    }

    /** Đọc lại các tin bot vừa gửi (đã format) để trả thẳng về cho FE, không phải chờ poll. */
    function chatbot_fetch_messages(array $ids, $me_id)
    {
        $ids = array_values(array_filter(array_map('intval', $ids), static fn($v) => $v > 0));
        if (!$ids) return [];
        $in   = implode(',', $ids);
        $rows = db_fetch_array("SELECT * FROM chat_messages WHERE id IN ({$in}) ORDER BY id ASC") ?: [];
        return chat_format_messages($rows, (int) $me_id);
    }

    /* =====================================================================
     *  5. Chuẩn hóa văn bản + nhận diện chủ đề
     * ===================================================================== */

    /** Bỏ dấu tiếng Việt + hạ chữ thường + gom khoảng trắng (để so khớp "mờ"). */
    function chatbot_normalize($text)
    {
        $s = mb_strtolower(trim((string) $text), 'UTF-8');
        $map = [
            'à'=>'a','á'=>'a','ạ'=>'a','ả'=>'a','ã'=>'a','â'=>'a','ầ'=>'a','ấ'=>'a','ậ'=>'a','ẩ'=>'a','ẫ'=>'a',
            'ă'=>'a','ằ'=>'a','ắ'=>'a','ặ'=>'a','ẳ'=>'a','ẵ'=>'a',
            'è'=>'e','é'=>'e','ẹ'=>'e','ẻ'=>'e','ẽ'=>'e','ê'=>'e','ề'=>'e','ế'=>'e','ệ'=>'e','ể'=>'e','ễ'=>'e',
            'ì'=>'i','í'=>'i','ị'=>'i','ỉ'=>'i','ĩ'=>'i',
            'ò'=>'o','ó'=>'o','ọ'=>'o','ỏ'=>'o','õ'=>'o','ô'=>'o','ồ'=>'o','ố'=>'o','ộ'=>'o','ổ'=>'o','ỗ'=>'o',
            'ơ'=>'o','ờ'=>'o','ớ'=>'o','ợ'=>'o','ở'=>'o','ỡ'=>'o',
            'ù'=>'u','ú'=>'u','ụ'=>'u','ủ'=>'u','ũ'=>'u','ư'=>'u','ừ'=>'u','ứ'=>'u','ự'=>'u','ử'=>'u','ữ'=>'u',
            'ỳ'=>'y','ý'=>'y','ỵ'=>'y','ỷ'=>'y','ỹ'=>'y',
            'đ'=>'d',
        ];
        $s = strtr($s, $map);
        $s = preg_replace('/[^a-z0-9]+/u', ' ', $s);
        return trim(preg_replace('/\s+/', ' ', (string) $s));
    }

    /** Tách chuỗi đã chuẩn hóa thành mảng từ. */
    function chatbot_tokens($norm)
    {
        $norm = trim((string) $norm);
        return $norm === '' ? [] : explode(' ', $norm);
    }

    /** 2 từ "gần giống" nhau không (cho phép gõ thiếu / sai 1 ký tự). */
    function chatbot_token_like($token, $target)
    {
        $token  = (string) $token;
        $target = (string) $target;
        if ($token === '' || $target === '') return false;
        if ($token === $target) return true;
        // Gõ thiếu đuôi: "cong th" -> "thuc" (tối thiểu 3 ký tự để tránh khớp bừa).
        if (strlen($token) >= 3 && strpos($target, $token) === 0) return true;
        // Sai 1 ký tự: "cogn" -> "cong".
        if (strlen($token) >= 4 && levenshtein($token, $target) <= 1) return true;
        return false;
    }

    /** Từ khóa nhận diện từng chủ đề (đã bỏ dấu). */
    function chatbot_topic_anchors()
    {
        return [
            // "công thức" — chỉ cần khớp 1 trong 2 từ là đủ (user hay gõ thiếu).
            'formula' => ['cong', 'thuc', 'ct'],
            // "tồn" / "tồn kho" — theo yêu cầu, từ khóa chính là "tồn".
            'stock'   => ['ton', 'tonkho', 'kho'],
        ];
    }

    /**
     * Đoán chủ đề của câu hỏi. Trả '' nếu không đoán được.
     * Chấm điểm theo số từ khóa khớp; hòa điểm thì ưu tiên từ khóa xuất hiện trước.
     */
    function chatbot_detect_topic($norm)
    {
        $tokens = chatbot_tokens($norm);
        if (!$tokens) return '';

        $best = ''; $bestScore = 0; $bestPos = PHP_INT_MAX;
        foreach (chatbot_topic_anchors() as $topic => $anchors) {
            $score = 0; $pos = PHP_INT_MAX;
            foreach ($tokens as $i => $tk) {
                foreach ($anchors as $a) {
                    if (chatbot_token_like($tk, $a)) {
                        $score++;
                        if ($i < $pos) $pos = $i;
                        break;
                    }
                }
            }
            // 'kho' một mình chưa đủ chắc cho chủ đề tồn kho (vd "kho hàng", "nhập kho"),
            // nhưng theo yêu cầu chỉ cần có "tồn" là chốt → đã tính ở vòng trên.
            if ($score > $bestScore || ($score === $bestScore && $score > 0 && $pos < $bestPos)) {
                $best = $topic; $bestScore = $score; $bestPos = $pos;
            }
        }
        return $bestScore > 0 ? $best : '';
    }

    /** Từ đệm cần bỏ khi rút phần "tên sản phẩm" ra khỏi câu hỏi. */
    function chatbot_stop_words()
    {
        return [
            'gui','cho','toi','minh','em','anh','chi','ban','xem','show','sen','send','giup','giùm','gium',
            'la','gi','the','nao','bao','nhieu','con','hien','tai','nay','voi','va','cua','ve','hoi','bit','biet',
            'san','pham','sp','ma','so','luong','sl','kiem','tra','check','coi','lai','o','a','ah','oi','nhe','nhé',
            'cong','thuc','ct','ton','kho','tonkho','hang','muon','can','duoc','khong','ko','vui','long','lam','on',
        ];
    }

    /* =====================================================================
     *  6. Tìm sản phẩm theo tên (đủ / thiếu / sai chính tả)
     * ===================================================================== */

    /**
     * Danh sách sản phẩm để so khớp (cache 1 lần / request).
     * Mỗi sản phẩm có nhiều "biến thể tên": tên thường gọi + tên hệ thống + mã.
     */
    function chatbot_products()
    {
        static $cache = null;
        if ($cache !== null) return $cache;

        $rows = db_fetch_array(
            "SELECT id, product_code, product_name, common_product_name, unit
             FROM products ORDER BY id ASC"
        ) ?: [];
        $cache = [];
        foreach ($rows as $r) {
            $common  = trim((string) ($r['common_product_name'] ?? ''));
            $formal  = trim((string) ($r['product_name'] ?? ''));
            $display = $common !== '' ? $common : $formal;
            if ($display === '') continue;

            $variants = [];
            foreach ([$common, $formal] as $n) {
                $n = chatbot_normalize($n);
                if ($n !== '' && !in_array($n, $variants, true)) $variants[] = $n;
            }
            $cache[] = [
                'id'       => (int) $r['id'],
                'code'     => (string) $r['product_code'],
                'name'     => $display,          // tên thường gọi (ưu tiên) để hiển thị
                'formal'   => $formal,
                'unit'     => (string) $r['unit'],
                'variants' => $variants,
                'words'    => chatbot_tokens($variants ? $variants[0] : ''),
            ];
        }
        return $cache;
    }

    /**
     * Khớp "đầy đủ": tên sản phẩm nằm trọn trong câu hỏi (so theo ranh giới từ).
     * Trả về danh sách sản phẩm, tên dài nhất xếp trước (khớp cụ thể nhất).
     */
    function chatbot_match_full($norm)
    {
        $hay = ' ' . $norm . ' ';
        $hits = [];
        foreach (chatbot_products() as $p) {
            $best = 0;
            foreach ($p['variants'] as $v) {
                if ($v === '') continue;
                if (strpos($hay, ' ' . $v . ' ') !== false) $best = max($best, strlen($v));
            }
            if ($best > 0) $hits[] = ['p' => $p, 'len' => $best];
        }
        if (!$hits) return [];
        usort($hits, static fn($a, $b) => $b['len'] <=> $a['len']);

        // Chỉ giữ các kết quả dài nhất — "bột kem phô mai" thắng "phô mai".
        $top = $hits[0]['len'];
        $out = [];
        foreach ($hits as $h) {
            if ($h['len'] === $top) $out[] = $h['p'];
        }
        return $out;
    }

    /**
     * Khớp "thiếu / sai chính tả": chấm điểm theo số từ khóa của câu hỏi tìm thấy
     * trong tên sản phẩm. Trả tối đa $limit ứng viên, kèm cờ 'exact_all' cho biết
     * mọi từ khóa đều khớp chính xác (không phải đoán mò).
     */
    function chatbot_match_partial($norm, $limit = 5)
    {
        $stop = chatbot_stop_words();
        // Gom mọi từ khóa chủ đề để loại luôn phần gõ TẮT của chúng ("công th" -> 'th').
        $anchors = [];
        foreach (chatbot_topic_anchors() as $list) {
            foreach ($list as $a) $anchors[] = $a;
        }
        $tokens = [];
        foreach (chatbot_tokens($norm) as $tk) {
            if (strlen($tk) < 2) continue;              // bỏ ký tự lẻ
            if (in_array($tk, $stop, true)) continue;   // bỏ từ đệm + từ khóa chủ đề
            // Từ ngắn (<=3) mà là phần đầu của 1 từ khóa chủ đề → coi như user gõ tắt
            // từ khóa, không phải tên sản phẩm ("công th" chứ không phải nhãn "TH").
            $isAnchorPrefix = false;
            if (strlen($tk) <= 3) {
                foreach ($anchors as $a) {
                    if ($tk !== $a && strpos($a, $tk) === 0) { $isAnchorPrefix = true; break; }
                }
            }
            if ($isAnchorPrefix) continue;
            $tokens[] = $tk;
        }
        if (!$tokens) return ['tokens' => [], 'items' => []];

        $items = [];
        foreach (chatbot_products() as $p) {
            $words = [];
            foreach ($p['variants'] as $v) {
                foreach (chatbot_tokens($v) as $w) $words[] = $w;
            }
            if (!$words) continue;

            $hitExact = 0; $hitFuzzy = 0;
            foreach ($tokens as $tk) {
                $exact = false; $fuzzy = false;
                foreach ($words as $w) {
                    if ($w === $tk) { $exact = true; break; }
                    if (!$fuzzy && chatbot_token_like($tk, $w)) $fuzzy = true;
                }
                if ($exact) $hitExact++;
                elseif ($fuzzy) $hitFuzzy++;
            }
            if ($hitExact + $hitFuzzy === 0) continue;

            $items[] = [
                'p'         => $p,
                'hits'      => $hitExact + $hitFuzzy,
                'score'     => $hitExact * 10 + $hitFuzzy * 6 - max(0, count($words) - count($tokens)),
                'exact_all' => ($hitExact === count($tokens)),
            ];
        }
        if (!$items) return ['tokens' => $tokens, 'items' => []];

        // CHỈ giữ nhóm khớp nhiều từ khóa nhất — tránh lôi cả rổ sản phẩm chỉ vì
        // trùng đúng 1 chữ chung chung ("bột", "trà"…).
        $maxHits = 0;
        foreach ($items as $i) $maxHits = max($maxHits, $i['hits']);
        // Câu hỏi có từ 2 từ khóa trở lên mà ứng viên tốt nhất chỉ khớp 1 → coi như không khớp.
        if ($maxHits < min(2, count($tokens))) return ['tokens' => $tokens, 'items' => []];
        $items = array_values(array_filter($items, static fn($i) => $i['hits'] === $maxHits));

        usort($items, static function ($a, $b) {
            if ($a['score'] !== $b['score']) return $b['score'] <=> $a['score'];
            return strlen($a['p']['name']) <=> strlen($b['p']['name']);
        });
        return ['tokens' => $tokens, 'items' => array_slice($items, 0, max(1, (int) $limit))];
    }

    /* =====================================================================
     *  7. Trả lời từng chủ đề (truy vấn CSDL)
     * ===================================================================== */

    /** Số kiểu Việt Nam: 7.6 -> "7,6"; 1000 -> "1.000"; bỏ số 0 thừa sau dấu phẩy. */
    function chatbot_num($n)
    {
        $n = (float) $n;
        $s = number_format($n, 3, ',', '.');
        $s = rtrim(rtrim($s, '0'), ',');
        return $s === '' || $s === '-' ? '0' : $s;
    }

    /** Số lượng + đơn vị của 1 dòng công thức (quy đổi đơn vị / kg→g giống module Công thức). */
    function chatbot_qty_text($qty, $unit, $conv_unit = '', $conv_ratio = 0)
    {
        $qty  = (float) $qty;
        $unit = trim((string) $unit);

        // Quy đổi riêng của dòng (vd kg -> thùng, 1 thùng = 25 kg).
        $conv_unit  = trim((string) $conv_unit);
        $conv_ratio = (float) $conv_ratio;
        if ($conv_unit !== '' && $conv_ratio > 0) {
            return chatbot_num($qty / $conv_ratio) . ' ' . $conv_unit;
        }
        // Dưới 1kg thì đọc theo gam cho dễ hình dung (giống production_formula).
        if (strtolower($unit) === 'kg' && $qty > 0 && $qty < 1) {
            return chatbot_num($qty * 1000) . ' g';
        }
        return chatbot_num($qty) . ($unit !== '' ? ' ' . $unit : '');
    }

    /**
     * Nội dung trả lời chủ đề "công thức sản xuất".
     * Ưu tiên CÔNG THỨC MẺ ĐƯỢC THIẾT LẬP ĐẦU TIÊN (product_batch_recipes cũ nhất);
     * chưa có mẻ nào thì lùi về công thức 1 đơn vị (product_materials).
     */
    function chatbot_answer_formula(array $product)
    {
        $pid = (int) $product['id'];

        $batch = db_fetch_row(
            "SELECT id, label, multiplier, output_qty, note
             FROM product_batch_recipes WHERE product_id = {$pid}
             ORDER BY created_at ASC, id ASC LIMIT 1"
        );

        if ($batch) {
            $bid   = (int) $batch['id'];
            $items = db_fetch_array(
                "SELECT i.quantity, i.unit, i.custom_name, i.conv_unit, i.conv_ratio,
                        m.material_name, m.common_material_name, m.unit AS m_unit
                 FROM product_batch_recipe_items i
                 LEFT JOIN material_information m ON m.id = i.material_id
                 WHERE i.batch_id = {$bid}
                 ORDER BY i.sort_order ASC, i.id ASC"
            ) ?: [];

            $label = trim((string) ($batch['label'] ?? ''));
            if ($label === '') {
                $label = $product['name'] . ' (mẻ x' . chatbot_num($batch['multiplier']) . ')';
            }
            $lines = ['[b]Công thức ' . $label . '[/b]'];
            if (!$items) {
                $lines[] = 'Công thức mẻ này chưa có thành phần nào.';
                return implode("\n", $lines);
            }
            foreach ($items as $it) {
                $name = trim((string) ($it['common_material_name'] ?? ''));
                if ($name === '') $name = trim((string) ($it['material_name'] ?? ''));
                if ($name === '') $name = trim((string) ($it['custom_name'] ?? ''));
                if ($name === '') $name = 'Thành phần';
                $unit = trim((string) ($it['unit'] ?? '')) !== ''
                    ? (string) $it['unit'] : (string) ($it['m_unit'] ?? '');
                $lines[] = '• ' . $name . ': '
                    . chatbot_qty_text($it['quantity'], $unit, $it['conv_unit'] ?? '', $it['conv_ratio'] ?? 0);
            }
            $note = trim((string) ($batch['note'] ?? ''));
            if ($note !== '') {
                $lines[] = '';
                $lines[] = 'Ghi chú: ' . $note;
            }
            return implode("\n", $lines);
        }

        // Chưa lưu công thức mẻ nào → công thức gốc 1 đơn vị.
        $rows = db_fetch_array(
            "SELECT pm.quantity_required AS quantity,
                    mi.material_name, mi.common_material_name, mi.unit
             FROM product_materials pm
             JOIN material_information mi ON mi.id = pm.material_id
             WHERE pm.product_id = {$pid}
             ORDER BY pm.sort_order ASC, pm.id ASC"
        ) ?: [];
        if (!$rows) {
            return 'Sản phẩm [b]' . $product['name'] . '[/b] chưa có công thức nào trong hệ thống.';
        }
        $unitLabel = trim((string) $product['unit']) !== '' ? (string) $product['unit'] : 'đơn vị';
        $lines = ['[b]Công thức ' . $product['name'] . ' (1 ' . $unitLabel . ')[/b]'];
        foreach ($rows as $r) {
            $name = trim((string) ($r['common_material_name'] ?? ''));
            if ($name === '') $name = (string) $r['material_name'];
            $lines[] = '• ' . $name . ': ' . chatbot_qty_text($r['quantity'], $r['unit']);
        }
        $lines[] = '';
        $lines[] = '(Sản phẩm này chưa lưu công thức mẻ nào — đang hiển thị công thức 1 đơn vị.)';
        return implode("\n", $lines);
    }

    /** Nội dung trả lời chủ đề "tồn kho sản phẩm" (kho thành phẩm). */
    function chatbot_answer_stock(array $product)
    {
        $pid = (int) $product['id'];
        $row = db_fetch_row("SELECT quantity FROM finished_goods_inventory WHERE product_id = {$pid} LIMIT 1");
        $qty = $row ? (float) $row['quantity'] : 0;
        $unit = trim((string) $product['unit']);

        $lines   = ['[b]Tồn kho ' . $product['name'] . '[/b]'];
        $lines[] = 'Mã sản phẩm: ' . ($product['code'] !== '' ? $product['code'] : '—');
        $lines[] = 'Tồn hiện tại: [b]' . chatbot_num($qty) . ($unit !== '' ? ' ' . $unit : '') . '[/b]';
        if (!$row) {
            $lines[] = '';
            $lines[] = '(Sản phẩm chưa có dòng nào trong kho thành phẩm.)';
        } elseif ($qty <= 0) {
            $lines[] = '';
            $lines[] = '(Sản phẩm này đang hết hàng trong kho thành phẩm.)';
        }
        return implode("\n", $lines);
    }

    /** Gọi đúng hàm trả lời theo chủ đề. */
    function chatbot_answer($topic, array $product)
    {
        if ($topic === 'formula') return chatbot_answer_formula($product);
        if ($topic === 'stock')   return chatbot_answer_stock($product);
        return 'Tôi chưa hỗ trợ chủ đề này.';
    }

    /** Nhãn nút gợi ý cho 1 sản phẩm theo chủ đề. */
    function chatbot_option_label($topic, array $product)
    {
        if ($topic === 'stock') return 'Show tôi tồn kho ' . $product['name'];
        return 'Show tôi công thức ' . $product['name'];
    }

    /* =====================================================================
     *  8. Bộ não: nhận câu hỏi → trả lời
     * ===================================================================== */

    /**
     * Xử lý 1 câu hỏi của user trong hội thoại với bot.
     * Trả mảng id các tin bot vừa gửi (để FE hiển thị ngay, không chờ poll).
     */
    function chatbot_handle_message($conversation_id, $user_id, $text)
    {
        $cid = (int) $conversation_id;
        $uid = (int) $user_id;
        $text = chat_strip_format_tags((string) $text);
        $norm = chatbot_normalize($text);

        $mine = chatbot_user_topics($uid);
        if (!$mine) {
            return [chatbot_say($cid, 'Bạn chưa được cấp quyền trò chuyện với tài khoản hệ thống. Vui lòng liên hệ quản trị viên.')];
        }
        if ($norm === '') {
            return [chatbot_say($cid, 'Bạn nhắn gì tôi chưa đọc được — thử gõ lại bằng chữ giúp tôi nhé.')];
        }

        $topic = chatbot_detect_topic($norm);
        if ($topic === '') {
            return [chatbot_say($cid, chatbot_help_text($uid))];
        }
        if (!in_array($topic, $mine, true)) {
            return [chatbot_say($cid,
                'Bạn chưa được cấp quyền hỏi về chủ đề [b]' . chatbot_topic_label($topic) . '[/b]. '
                . 'Vui lòng liên hệ quản trị viên.' . "\n\n"
                . chatbot_topic_list_text($uid, 'Các chủ đề bạn đang được hỏi:'))];
        }

        // (1) Tên sản phẩm ghi ĐỦ trong câu hỏi → chốt luôn.
        $full = chatbot_match_full($norm);
        if (count($full) === 1) {
            return [chatbot_say($cid, chatbot_answer($topic, $full[0]))];
        }
        if (count($full) > 1) {
            return [chatbot_ask_choice($cid, $topic, $full)];
        }

        // (2) Ghi thiếu / sai chính tả → hỏi ngược lại cho user chọn.
        $partial = chatbot_match_partial($norm);
        if (!$partial['tokens']) {
            $what = $topic === 'stock' ? 'xem tồn kho' : 'xem công thức';
            return [chatbot_say($cid, 'Bạn muốn ' . $what . ' của sản phẩm nào? Nhắn kèm tên sản phẩm giúp tôi nhé.')];
        }
        $items = $partial['items'];
        if (!$items) {
            return [chatbot_say($cid,
                'Tôi không tìm thấy sản phẩm nào khớp với "' . trim($text) . '".' . "\n"
                . 'Bạn thử nhắn lại tên sản phẩm (có thể chỉ cần vài chữ) giúp tôi nhé.')];
        }
        // Đúng 1 ứng viên và mọi từ khóa đều khớp chính xác → trả lời luôn cho nhanh.
        if (count($items) === 1 && $items[0]['exact_all']) {
            return [chatbot_say($cid, chatbot_answer($topic, $items[0]['p']))];
        }
        return [chatbot_ask_choice($cid, $topic, array_map(static fn($i) => $i['p'], $items))];
    }

    /** Gửi câu hỏi ngược "có phải bạn hỏi về…" kèm các nút chọn. */
    function chatbot_ask_choice($conversation_id, $topic, array $products)
    {
        $what = $topic === 'stock' ? 'tồn kho' : 'công thức';
        $body = 'Có phải bạn hỏi về ' . $what . ' các sản phẩm sau, nếu đúng xin chọn 1.';

        $options = [];
        foreach ($products as $p) {
            $options[] = ['t' => chatbot_option_label($topic, $p), 'k' => $topic, 'p' => (int) $p['id']];
        }
        $options[] = ['t' => 'Do tôi nhắn nhầm', 'k' => 'cancel'];
        return chatbot_say($conversation_id, $body, $options);
    }

    /** Liệt kê chủ đề user được phép hỏi (kèm ví dụ), sau 1 câu mở đầu tùy chỗ dùng. */
    function chatbot_topic_list_text($user_id, $intro)
    {
        $mine = chatbot_user_topics($user_id);
        $all  = chatbot_topics();
        if (!$mine) return 'Bạn chưa được cấp quyền hỏi chủ đề nào. Vui lòng liên hệ quản trị viên.';

        $lines = [$intro];
        foreach ($mine as $t) {
            $lines[] = '• [b]' . $all[$t]['label'] . '[/b] — ví dụ: "' . $all[$t]['example'] . '"';
        }
        return implode("\n", $lines);
    }

    /** Hướng dẫn khi không đoán được ý. */
    function chatbot_help_text($user_id)
    {
        return chatbot_topic_list_text($user_id, 'Tôi chưa hiểu ý bạn. Hiện tôi tra cứu được các chủ đề sau:');
    }

    /**
     * User bấm 1 nút gợi ý. $pick = ['k' => chủ đề|cancel, 'p' => product_id, 'src' => message_id].
     * Trả mảng id tin bot vừa gửi.
     */
    function chatbot_handle_pick($conversation_id, $user_id, array $pick)
    {
        $cid  = (int) $conversation_id;
        $uid  = (int) $user_id;
        $kind = (string) ($pick['k'] ?? '');
        $pid  = (int) ($pick['p'] ?? 0);
        $src  = (int) ($pick['src'] ?? 0);
        if ($src > 0) chatbot_mark_options_used($src);

        if ($kind === 'cancel') {
            return [chatbot_say($cid, 'Rõ - có gì cứ nhắn tôi nhé.')];
        }
        $topics = chatbot_topics();
        if (!isset($topics[$kind])) {
            return [chatbot_say($cid, chatbot_help_text($uid))];
        }
        if (!chatbot_can($uid, $kind)) {
            return [chatbot_say($cid, 'Bạn chưa được cấp quyền hỏi về chủ đề [b]' . chatbot_topic_label($kind) . '[/b].')];
        }
        foreach (chatbot_products() as $p) {
            if ($p['id'] === $pid) return [chatbot_say($cid, chatbot_answer($kind, $p))];
        }
        return [chatbot_say($cid, 'Tôi không tìm thấy sản phẩm này nữa — có thể đã bị xóa khỏi hệ thống.')];
    }
}
