<?php
/**
 * chat_bot — TÀI KHOẢN HỆ THỐNG trong hộp chat (mặc định tên "Safe King").
 * ---------------------------------------------------------------------------
 * Là 1 user thật trong tbl_users (status='system', KHÔNG đăng nhập được vì
 * đăng nhập yêu cầu status='active') nên tái dùng được toàn bộ cơ chế sẵn có
 * của [[chat-widget]]: hội thoại direct, tin nhắn, badge chưa đọc, toast…
 *
 * Nhiệm vụ: nhận câu hỏi của người dùng → tự truy vấn cơ sở dữ liệu → trả lời
 * ngay trong khung chat. Các chủ đề đang hỗ trợ:
 *   - formula   : công thức sản xuất (product_batch_recipes / product_materials)
 *   - stock     : tồn kho thành phẩm (finished_goods_inventory)
 *   - mat_price : giá nhập nguyên vật liệu (material_purchase_prices + stock_imports)
 *   - output    : sản lượng sản xuất (finished_product_production_data)
 *   - cost      : giá vốn sản phẩm & biến động (product_materials + purchase_price_changes)
 *   - supplier  : mua hàng theo nhà cung cấp (stock_import_invoices + stock_imports)
 *   - customer  : khách hàng & sản phẩm khách mua (sales_inventory_issue_data)
 *
 * 3 khái niệm xuyên suốt (khác hẳn 2 chủ đề đầu vốn chỉ cần 1 tên sản phẩm):
 *   1. THỰC THỂ có 4 loại — sản phẩm / nguyên vật liệu / nhà cung cấp / khách hàng.
 *      chatbot_entities() nạp chung, thuật toán khớp tên dùng lại y nguyên.
 *   2. KỲ THỜI GIAN — chatbot_parse_period() hiểu "tháng này / tháng 6 / tuần
 *      trước / 30 ngày qua / từ 1/6 đến 30/6"; không nói gì = tháng hiện tại.
 *   3. NÚT "ĐI TIẾP" — mỗi câu trả lời kèm nút mở sang chủ đề liên quan, tạo
 *      thành mạch: mua hàng → giá nhập → giá vốn → sản lượng → khách hàng.
 *      Toàn bộ ngữ cảnh nằm trong payload của nút ({k,e,i,f,u}) nên bot vẫn
 *      KHÔNG cần bảng lưu phiên hội thoại.
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

    /**
     * Danh mục chủ đề bot hiểu được. Thêm chủ đề mới thì khai báo ở đây —
     * màn hình phân quyền (modal "Cài đặt trò chuyện") tự sinh thêm cột tick.
     * 'entities' = các loại thực thể chủ đề đó chấp nhận, THEO THỨ TỰ ƯU TIÊN
     * khi dò tên trong câu hỏi (vd chủ đề supplier ưu tiên hiểu tên NCC trước,
     * không thấy mới thử hiểu là tên NVL).
     */
    function chatbot_topics()
    {
        return [
            'formula' => [
                'label'    => 'Công thức sản xuất',
                'desc'     => 'Hỏi công thức mẻ sản xuất của một sản phẩm.',
                'example'  => 'Gửi tôi công thức bột phô mai',
                'entities' => ['product'],
            ],
            'stock' => [
                'label'    => 'Tồn kho sản phẩm',
                'desc'     => 'Hỏi số lượng thành phẩm đang còn trong kho.',
                'example'  => 'Tồn kho bột phô mai còn bao nhiêu',
                'entities' => ['product'],
            ],
            'mat_price' => [
                'label'    => 'Giá nhập nguyên vật liệu',
                'desc'     => 'Giá mua hiện hành của một NVL, các lần nhập gần nhất và biến động giá.',
                'example'  => 'Giá nhập bột sữa',
                'entities' => ['material'],
            ],
            'output' => [
                'label'    => 'Sản lượng sản xuất',
                'desc'     => 'Sản lượng đã sản xuất theo kỳ, của một sản phẩm hoặc toàn nhà máy.',
                'example'  => 'Sản lượng bột phô mai tháng này',
                'entities' => ['product'],
            ],
            'cost' => [
                'label'    => 'Giá vốn & biến động',
                'desc'     => 'Giá vốn hiện hành của sản phẩm, cơ cấu thành phần và ảnh hưởng khi NVL đổi giá.',
                'example'  => 'Giá vốn bột phô mai',
                'entities' => ['product', 'material'],
            ],
            'supplier' => [
                'label'    => 'Mua hàng theo nhà cung cấp',
                'desc'     => 'Đã mua gì của một NCC trong kỳ, hoặc một NVL đang mua của những NCC nào.',
                'example'  => 'Tháng này mua gì của Trà Phú Sỹ',
                'entities' => ['supplier', 'material'],
            ],
            'customer' => [
                'label'    => 'Khách hàng & sản phẩm đã mua',
                'desc'     => 'Khách hàng mua gì trong kỳ, hoặc một sản phẩm đang được khách nào mua.',
                'example'  => 'Khách Cần Thơ tháng này mua gì',
                'entities' => ['customer', 'product'],
            ],
        ];
    }

    /** Các loại thực thể 1 chủ đề chấp nhận, theo thứ tự ưu tiên khi dò tên. */
    function chatbot_topic_entity_kinds($topic)
    {
        $t = chatbot_topics();
        return isset($t[$topic]['entities']) ? $t[$topic]['entities'] : ['product'];
    }

    /** Chủ đề này trả lời được khi KHÔNG nêu thực thể nào không (báo cáo tổng hợp). */
    function chatbot_topic_allows_overview($topic)
    {
        return in_array($topic, ['output', 'customer', 'supplier'], true);
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

    /**
     * CỤM TỪ nhận diện chủ đề (ưu tiên số 1, xét trước từ khóa lẻ).
     * Lý do phải có lớp này: nhiều chủ đề dùng chung chữ đầu — "giá vốn" và
     * "giá nhập" đều bắt đầu bằng "giá", chấm điểm theo từ lẻ sẽ hòa và chọn
     * bừa. Khớp bằng "chứa nguyên cụm", cụm DÀI NHẤT thắng.
     */
    /**
     * MẪU CÂU nhận diện chủ đề (ưu tiên số 0 — xét trước cả cụm từ).
     * Cần lớp này vì tiếng Việt hay chèn từ vào GIỮA cặp từ khóa: "mua [gì của]
     * Phú Sỹ", "ai [đang] mua bột sữa" — khớp chuỗi cứng sẽ trượt hết.
     */
    function chatbot_topic_patterns()
    {
        return [
            'supplier' => [
                '/\bmua\b.{0,14}\bcua\b/',      // "mua gì của …", "mua hàng của …"
                '/\bnhap\b.{0,14}\bcua\b/',     // "nhập gì của …"
                '/\bmua\b.{0,10}\btu\b/',       // "mua từ …"
            ],
            'customer' => [
                '/\bai\b.{0,10}\bmua\b/',       // "ai (đang) mua …"
                '/\bkhach\b.{0,12}\bmua\b/',    // "khách nào mua …"
                '/\bban\b.{0,12}\bcho\b/',      // "bán (hàng) cho …"
            ],
        ];
    }

    function chatbot_topic_phrases()
    {
        return [
            'formula'   => ['cong thuc'],
            'stock'     => ['ton kho', 'con bao nhieu'],
            'cost'      => ['gia von', 'gia thanh', 'gia von san pham'],
            'mat_price' => ['gia nhap', 'gia mua', 'gia nguyen lieu', 'gia nvl', 'don gia nhap', 'nhap gia'],
            'output'    => ['san luong', 'san xuat duoc', 'da san xuat', 'lam duoc bao nhieu'],
            'supplier'  => ['nha cung cap', 'mua hang cua', 'mua cua', 'nhap hang cua', 'don mua hang'],
            'customer'  => ['khach hang', 'ban cho', 'khach mua', 'doanh so'],
        ];
    }

    /**
     * Từ khóa LẺ nhận diện chủ đề (đã bỏ dấu), kèm TRỌNG SỐ — dùng khi câu hỏi
     * viết tắt/cụt không khớp cụm nào. Trọng số cao = từ khóa đặc trưng, gần
     * như chỉ chủ đề đó mới dùng ('ncc', 'gv'); trọng số 1 = từ chung chung.
     */
    function chatbot_topic_anchors()
    {
        return [
            // "công thức" — chỉ cần khớp 1 trong 2 từ là đủ (user hay gõ thiếu).
            'formula'   => ['cong' => 1, 'thuc' => 1, 'ct' => 2],
            // "tồn" / "tồn kho" — theo yêu cầu, từ khóa chính là "tồn".
            'stock'     => ['ton' => 2, 'tonkho' => 2, 'kho' => 1],
            'cost'      => ['von' => 3, 'giavon' => 3, 'gv' => 3],
            // 'mua' để bắt "bột kerry đang mua bao nhiêu"; các câu "mua … của <NCC>"
            // đã bị lớp mẫu câu bắt về chủ đề supplier từ trước nên không tranh chấp.
            'mat_price' => ['gianhap' => 3, 'giamua' => 3, 'gia' => 1, 'nhap' => 1, 'mua' => 1],
            'output'    => ['sanluong' => 3, 'sx' => 3, 'luong' => 2, 'sanxuat' => 2],
            'supplier'  => ['ncc' => 3, 'nhacungcap' => 3, 'cungcap' => 2],
            'customer'  => ['khachhang' => 3, 'khach' => 3, 'kh' => 2, 'doanhso' => 3],
        ];
    }

    /**
     * Đoán chủ đề của câu hỏi. Trả '' nếu không đoán được.
     * Bước 1: khớp CỤM TỪ (cụm dài nhất thắng, hòa thì cụm xuất hiện trước thắng).
     * Bước 2: chấm điểm từ khóa lẻ có trọng số; mỗi từ chỉ tính điểm CAO NHẤT
     *         nó khớp được trong chủ đề đó (không cộng dồn nhiều anchor).
     */
    function chatbot_detect_topic($norm)
    {
        $norm = trim((string) $norm);
        if ($norm === '') return '';
        $hay = ' ' . $norm . ' ';

        // --- Bước 0: mẫu câu (cặp từ khóa có từ chèn ở giữa) ---
        foreach (chatbot_topic_patterns() as $topic => $pats) {
            foreach ($pats as $re) {
                if (preg_match($re, $hay)) return $topic;
            }
        }

        // --- Bước 1: cụm từ ---
        $best = ''; $bestLen = 0; $bestAt = PHP_INT_MAX;
        foreach (chatbot_topic_phrases() as $topic => $phrases) {
            foreach ($phrases as $ph) {
                $at = strpos($hay, ' ' . $ph . ' ');
                if ($at === false) continue;
                $len = strlen($ph);
                if ($len > $bestLen || ($len === $bestLen && $at < $bestAt)) {
                    $best = $topic; $bestLen = $len; $bestAt = $at;
                }
            }
        }
        if ($best !== '') return $best;

        // --- Bước 2: từ khóa lẻ có trọng số ---
        $tokens = chatbot_tokens($norm);
        if (!$tokens) return '';

        $best = ''; $bestScore = 0; $bestPos = PHP_INT_MAX;
        foreach (chatbot_topic_anchors() as $topic => $anchors) {
            $score = 0; $pos = PHP_INT_MAX;
            foreach ($tokens as $i => $tk) {
                $hit = 0;
                foreach ($anchors as $a => $w) {
                    if (chatbot_token_like($tk, $a) && $w > $hit) $hit = $w;
                }
                if ($hit > 0) {
                    $score += $hit;
                    if ($i < $pos) $pos = $i;
                }
            }
            if ($score > $bestScore || ($score === $bestScore && $score > 0 && $pos < $bestPos)) {
                $best = $topic; $bestScore = $score; $bestPos = $pos;
            }
        }
        return $bestScore > 0 ? $best : '';
    }

    /** Từ đệm cần bỏ khi rút phần "tên thực thể" (SP/NVL/NCC/khách) ra khỏi câu hỏi. */
    function chatbot_stop_words()
    {
        return [
            'gui','cho','toi','minh','em','anh','chi','ban','xem','show','sen','send','giup','giùm','gium',
            'la','gi','the','nao','bao','nhieu','con','hien','tai','nay','voi','va','cua','ve','hoi','bit','biet',
            'san','pham','sp','ma','so','luong','sl','kiem','tra','check','coi','lai','o','a','ah','oi','nhe','nhé',
            'cong','thuc','ct','ton','kho','tonkho','hang','muon','can','duoc','khong','ko','vui','long','lam','on',
            // --- từ khóa của 5 chủ đề mới: là chỉ dấu chủ đề, không phải tên thực thể ---
            'gia','von','giavon','gv','nhap','mua','gianhap','giamua','don',
            // ('da' KHÔNG được liệt kê: trùng "đá/dạ" hay có trong tên hàng — cụm
            //  "đã sản xuất" đã do lớp cụm từ lo, không cần chặn ở đây)
            'sanluong','sanxuat','sx','xuat',
            'ncc','nhacungcap','cungcap','cung','cap','nha',
            'khach','khachhang','kh','doanh','doanhso','ban',
            // --- từ chỉ mốc thời gian: đã được chatbot_parse_period() xử lý riêng ---
            'thang','tuan','ngay','nam','quy','hom','qua','truoc','tu','den','gan','day','trong','ky',
        ];
    }

    /* =====================================================================
     *  6. Tìm sản phẩm theo tên (đủ / thiếu / sai chính tả)
     * ===================================================================== */

    /**
     * Danh sách thực thể để so khớp tên (cache 1 lần / request / loại).
     * $kind: 'product' | 'material' | 'supplier' | 'customer'.
     * Mỗi thực thể có nhiều "biến thể tên" (tên thường gọi + tên hệ thống) —
     * thuật toán khớp bên dưới dùng chung cho cả 4 loại.
     */
    function chatbot_entities($kind)
    {
        static $cache = [];
        $kind = (string) $kind;
        if (isset($cache[$kind])) return $cache[$kind];

        // [câu truy vấn, cột tên thường gọi, cột tên chính thức, cột mã, cột đơn vị]
        $map = [
            'product'  => ["SELECT id, product_code AS code, product_name AS formal,
                                   common_product_name AS common, unit FROM products ORDER BY id ASC"],
            'material' => ["SELECT id, material_code AS code, material_name AS formal,
                                   common_material_name AS common, unit FROM material_information ORDER BY id ASC"],
            'supplier' => ["SELECT id, supplier_code AS code, supplier_name AS formal,
                                   short_name AS common, '' AS unit FROM suppliers ORDER BY id ASC"],
            'customer' => ["SELECT id, '' AS code, name AS formal,
                                   short_name AS common, '' AS unit FROM customers ORDER BY id ASC"],
        ];
        if (!isset($map[$kind])) { $cache[$kind] = []; return $cache[$kind]; }

        $rows = db_fetch_array($map[$kind][0]) ?: [];
        $out  = [];
        foreach ($rows as $r) {
            $common  = trim((string) ($r['common'] ?? ''));
            $formal  = trim((string) ($r['formal'] ?? ''));
            $display = $common !== '' ? $common : $formal;
            if ($display === '') continue;

            $variants = [];
            foreach ([$common, $formal] as $n) {
                $n = chatbot_normalize($n);
                if ($n !== '' && !in_array($n, $variants, true)) $variants[] = $n;
            }
            $out[] = [
                'kind'     => $kind,
                'id'       => (int) $r['id'],
                'code'     => (string) ($r['code'] ?? ''),
                'name'     => $display,          // tên thường gọi (ưu tiên) để hiển thị
                'formal'   => $formal,
                'unit'     => (string) ($r['unit'] ?? ''),
                'variants' => $variants,
                'words'    => chatbot_tokens($variants ? $variants[0] : ''),
            ];
        }
        $cache[$kind] = $out;
        return $out;
    }

    /** Giữ tên cũ cho 2 chủ đề formula/stock (và mọi chỗ đang gọi). */
    function chatbot_products()
    {
        return chatbot_entities('product');
    }

    /** Tìm 1 thực thể theo loại + id (dùng khi user bấm nút gợi ý). */
    function chatbot_entity_by_id($kind, $id)
    {
        $id = (int) $id;
        if ($id <= 0) return null;
        foreach (chatbot_entities($kind) as $e) {
            if ($e['id'] === $id) return $e;
        }
        return null;
    }

    /** Nhãn loại thực thể để ghép câu ("sản phẩm nào", "nhà cung cấp nào"…). */
    function chatbot_kind_label($kind)
    {
        $m = [
            'product'  => 'sản phẩm',
            'material' => 'nguyên vật liệu',
            'supplier' => 'nhà cung cấp',
            'customer' => 'khách hàng',
        ];
        return isset($m[$kind]) ? $m[$kind] : 'mục';
    }

    /** Ký tự 1 chữ của loại thực thể, dùng trong payload nút bấm cho gọn. */
    function chatbot_kind_code($kind)
    {
        $m = ['product' => 'p', 'material' => 'm', 'supplier' => 's', 'customer' => 'c'];
        return isset($m[$kind]) ? $m[$kind] : 'p';
    }

    /** Ngược lại của chatbot_kind_code(). */
    function chatbot_kind_from_code($code)
    {
        $m = ['p' => 'product', 'm' => 'material', 's' => 'supplier', 'c' => 'customer'];
        $code = (string) $code;
        return isset($m[$code]) ? $m[$code] : 'product';
    }

    /**
     * Khớp "đầy đủ": tên sản phẩm nằm trọn trong câu hỏi (so theo ranh giới từ).
     * Trả về danh sách sản phẩm, tên dài nhất xếp trước (khớp cụ thể nhất).
     */
    function chatbot_match_full($norm, $kind = 'product')
    {
        $hay = ' ' . $norm . ' ';
        $hits = [];
        foreach (chatbot_entities($kind) as $p) {
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
    function chatbot_match_partial($norm, $limit = 5, $kind = 'product')
    {
        $stop = chatbot_stop_words();
        // Gom mọi từ khóa chủ đề để loại luôn phần gõ TẮT của chúng ("công th" -> 'th').
        $anchors = [];
        foreach (chatbot_topic_anchors() as $list) {
            foreach (array_keys($list) as $a) $anchors[] = $a;
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
        foreach (chatbot_entities($kind) as $p) {
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
     *  6b. KỲ THỜI GIAN
     *  Các chủ đề sản lượng / mua hàng / khách hàng đều là số liệu THEO KỲ.
     *  Không nêu kỳ thì mặc định THÁNG HIỆN TẠI, và câu trả lời luôn ghi rõ
     *  kỳ đang tính để không ai hiểu nhầm con số.
     * ===================================================================== */

    /**
     * Đọc mốc thời gian trong câu hỏi (đã chuẩn hóa, không dấu).
     * Trả ['from' => 'Y-m-d', 'to' => 'Y-m-d', 'label' => '…', 'explicit' => bool].
     */
    function chatbot_parse_period($norm)
    {
        $norm = ' ' . trim((string) $norm) . ' ';
        $mk = function ($from, $to, $label) {
            return ['from' => $from, 'to' => $to, 'label' => $label, 'explicit' => true];
        };
        $d = function ($ts) { return date('Y-m-d', $ts); };

        // "từ 1/6 đến 30/6" / "từ 1/6/2026 den 30/6/2026" — xét trước vì cụ thể nhất.
        if (preg_match('/\btu (\d{1,2}) (\d{1,2})(?: (\d{4}))? den (\d{1,2}) (\d{1,2})(?: (\d{4}))?\b/', $norm, $m)) {
            $y1 = $m[3] !== '' ? (int) $m[3] : (int) date('Y');
            $y2 = isset($m[6]) && $m[6] !== '' ? (int) $m[6] : $y1;
            $f  = sprintf('%04d-%02d-%02d', $y1, (int) $m[2], (int) $m[1]);
            $t  = sprintf('%04d-%02d-%02d', $y2, (int) $m[5], (int) $m[4]);
            if ($f > $t) { $tmp = $f; $f = $t; $t = $tmp; }
            return $mk($f, $t, chatbot_date_vi($f) . ' → ' . chatbot_date_vi($t));
        }
        // "30 ngày qua" / "7 ngay gan day"
        if (preg_match('/\b(\d{1,3}) ngay (?:qua|gan day|tro lai day)\b/', $norm, $m)) {
            $n = max(1, min(400, (int) $m[1]));
            return $mk($d(strtotime('-' . ($n - 1) . ' days')), $d(time()), $n . ' ngày qua');
        }
        if (strpos($norm, ' hom nay ') !== false || strpos($norm, ' bua nay ') !== false) {
            return $mk($d(time()), $d(time()), 'hôm nay ' . chatbot_date_vi($d(time())));
        }
        if (strpos($norm, ' hom qua ') !== false) {
            $y = strtotime('-1 day');
            return $mk($d($y), $d($y), 'hôm qua ' . chatbot_date_vi($d($y)));
        }
        if (strpos($norm, ' tuan nay ') !== false) {
            return $mk($d(strtotime('monday this week')), $d(strtotime('sunday this week')), 'tuần này');
        }
        if (strpos($norm, ' tuan truoc ') !== false) {
            return $mk($d(strtotime('monday last week')), $d(strtotime('sunday last week')), 'tuần trước');
        }
        if (strpos($norm, ' thang truoc ') !== false) {
            $s = strtotime('first day of last month');
            return $mk(date('Y-m-01', $s), date('Y-m-t', $s), 'tháng ' . date('n/Y', $s));
        }
        if (strpos($norm, ' thang nay ') !== false) {
            return $mk(date('Y-m-01'), date('Y-m-t'), 'tháng ' . date('n/Y'));
        }
        // "tháng 6" / "tháng 6 2025" / "tháng 6/2025"
        if (preg_match('/\bthang (\d{1,2})(?: (\d{4}))?\b/', $norm, $m)) {
            $mo = max(1, min(12, (int) $m[1]));
            $yr = isset($m[2]) && $m[2] !== '' ? (int) $m[2] : (int) date('Y');
            $s  = mktime(0, 0, 0, $mo, 1, $yr);
            return $mk(date('Y-m-01', $s), date('Y-m-t', $s), 'tháng ' . $mo . '/' . $yr);
        }
        // "quý 2" / "quy 2 2025"
        if (preg_match('/\bquy ([1-4])(?: (\d{4}))?\b/', $norm, $m)) {
            $q  = (int) $m[1];
            $yr = isset($m[2]) && $m[2] !== '' ? (int) $m[2] : (int) date('Y');
            $s  = mktime(0, 0, 0, ($q - 1) * 3 + 1, 1, $yr);
            $e  = mktime(0, 0, 0, $q * 3, 1, $yr);
            return $mk(date('Y-m-01', $s), date('Y-m-t', $e), 'quý ' . $q . '/' . $yr);
        }
        if (strpos($norm, ' nam nay ') !== false) {
            return $mk(date('Y-01-01'), date('Y-12-31'), 'năm ' . date('Y'));
        }
        if (strpos($norm, ' nam ngoai ') !== false) {
            $y = (int) date('Y') - 1;
            return $mk($y . '-01-01', $y . '-12-31', 'năm ' . $y);
        }
        if (preg_match('/\bnam (\d{4})\b/', $norm, $m)) {
            $y = (int) $m[1];
            return $mk($y . '-01-01', $y . '-12-31', 'năm ' . $y);
        }

        // Mặc định: tháng hiện tại (explicit = false để câu trả lời nói rõ "mặc định").
        return ['from' => date('Y-m-01'), 'to' => date('Y-m-t'),
                'label' => 'tháng ' . date('n/Y'), 'explicit' => false];
    }

    /** Kỳ liền trước, cùng độ dài — dùng để so sánh tăng/giảm. */
    function chatbot_period_prev(array $p)
    {
        $f = strtotime($p['from']);
        $t = strtotime($p['to']);
        $days = max(1, (int) round(($t - $f) / 86400) + 1);
        // Kỳ là trọn 1 tháng → kỳ trước cũng lấy trọn tháng trước (không phải "n ngày trước").
        if ($p['from'] === date('Y-m-01', $f) && $p['to'] === date('Y-m-t', $f)) {
            $s = strtotime('-1 month', $f);
            return ['from' => date('Y-m-01', $s), 'to' => date('Y-m-t', $s),
                    'label' => 'tháng ' . date('n/Y', $s)];
        }
        $pt = strtotime('-1 day', $f);
        $pf = strtotime('-' . ($days - 1) . ' days', $pt);
        return ['from' => date('Y-m-d', $pf), 'to' => date('Y-m-d', $pt), 'label' => 'kỳ trước'];
    }

    /** Điều kiện SQL lọc theo kỳ cho 1 cột datetime. */
    function chatbot_period_where($col, array $p)
    {
        return "$col >= '" . escape_string($p['from']) . " 00:00:00'"
             . " AND $col <= '" . escape_string($p['to']) . " 23:59:59'";
    }

    /** 'Y-m-d [H:i:s]' -> 'd/m/Y' cho người đọc. */
    function chatbot_date_vi($dt)
    {
        $dt = trim((string) $dt);
        if ($dt === '' || strpos($dt, '0000') === 0) return '—';
        $ts = strtotime($dt);
        return $ts ? date('d/m/Y', $ts) : '—';
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

    /** Tiền kiểu Việt Nam, làm tròn đồng: 51000 -> "51.000 đ". */
    function chatbot_money($n)
    {
        return number_format((float) $n, 0, ',', '.') . ' đ';
    }

    /** Tỉ lệ biến động có dấu: 3.03 -> "+3,03%"; -1.2 -> "-1,2%". */
    function chatbot_pct($rate)
    {
        $r = (float) $rate;
        $s = number_format(abs($r), 2, ',', '.');
        $s = rtrim(rtrim($s, '0'), ',');
        if ($s === '') $s = '0';
        return ($r > 0 ? '+' : ($r < 0 ? '-' : '')) . $s . '%';
    }

    /** % thay đổi từ $old sang $new (null nếu không có mốc cũ để so). */
    function chatbot_change_rate($old, $new)
    {
        $old = (float) $old;
        if ($old == 0.0) return null;
        return (((float) $new - $old) / $old) * 100;
    }

    /**
     * Tên hiển thị của 1 dòng nhập/xuất — dòng có thể là THÀNH PHẨM (product_id)
     * hoặc NGUYÊN VẬT LIỆU (material_id), dùng chung ở chủ đề supplier/customer.
     */
    function chatbot_item_name($product_id, $material_id)
    {
        $pid = (int) $product_id;
        $mid = (int) $material_id;
        if ($pid > 0) {
            $r = db_fetch_row("SELECT COALESCE(NULLIF(common_product_name, ''), product_name) AS nm, unit
                               FROM products WHERE id = $pid LIMIT 1");
            if ($r) return ['name' => (string) $r['nm'], 'unit' => (string) $r['unit']];
        }
        if ($mid > 0) {
            $r = db_fetch_row("SELECT COALESCE(NULLIF(common_material_name, ''), material_name) AS nm, unit
                               FROM material_information WHERE id = $mid LIMIT 1");
            if ($r) return ['name' => (string) $r['nm'], 'unit' => (string) $r['unit']];
        }
        return ['name' => 'Mặt hàng khác', 'unit' => ''];
    }

    /**
     * Nạp muộn model inventory_receiving để dùng lại CÔNG THỨC GIÁ VỐN đã có
     * (ir_get_price_incl / ir_product_cost_breakdown / ir_material_cost_impact).
     * Cố ý KHÔNG require ở đầu file: chỉ chủ đề 'cost' mới cần, mà file đó kéo
     * theo 5 model khác — 6 chủ đề còn lại không việc gì phải trả phí nạp đó.
     * Dùng lại thay vì chép công thức để giá vốn ở chat và ở màn hình nhập kho
     * không bao giờ lệch nhau.
     */
    function chatbot_cost_model_ready()
    {
        if (function_exists('ir_product_cost_breakdown')) return true;
        $f = __DIR__ . '/../modules/inventory_receiving/models/inventory_receivingModel.php';
        if (is_file($f)) { require_once $f; }
        return function_exists('ir_product_cost_breakdown');
    }

    /* ---------------------------------------------------------------------
     *  7.1  GIÁ NHẬP NGUYÊN VẬT LIỆU  (chủ đề mat_price)
     * ------------------------------------------------------------------- */
    function chatbot_answer_mat_price(array $mat)
    {
        $mid  = (int) $mat['id'];
        $unit = trim((string) $mat['unit']);
        $per  = $unit !== '' ? ' đ/' . $unit : ' đ';

        $lines = ['[b]Giá nhập ' . $mat['name'] . '[/b]'];

        $cur = db_fetch_row(
            "SELECT purchase_price AS pp, purchase_price_includes_purchase_cost AS pit, last_updated_at
             FROM material_purchase_prices WHERE material_id = $mid
             ORDER BY last_updated_at DESC, id DESC LIMIT 1"
        );
        if (!$cur) {
            $lines[] = 'Nguyên vật liệu này chưa có giá nhập nào trong hệ thống.';
            return implode("\n", $lines);
        }
        $lines[] = 'Giá mua: [b]' . number_format((float) $cur['pp'], 0, ',', '.') . $per . '[/b]';
        if ($cur['pit'] !== null && $cur['pit'] !== '' && (float) $cur['pit'] > 0) {
            $lines[] = 'Đã gồm chi phí mua hàng: [b]'
                . number_format((float) $cur['pit'], 0, ',', '.') . $per . '[/b]';
        }
        $lines[] = 'Cập nhật: ' . chatbot_date_vi($cur['last_updated_at']);

        // 3 lần nhập gần nhất (kèm nhà cung cấp của phiếu).
        $imports = db_fetch_array(
            "SELECT si.quantity, si.unit_price, si.created_at,
                    COALESCE(NULLIF(s.short_name, ''), s.supplier_name) AS sup
             FROM stock_imports si
             LEFT JOIN stock_import_invoices inv ON inv.id = si.import_invoice_id
             LEFT JOIN suppliers s ON s.id = inv.supplier_id
             WHERE si.material_id = $mid
               AND si.type_import IN ('row_material_receiving', 'other_row_material_receiving')
             ORDER BY si.created_at DESC, si.id DESC LIMIT 3"
        ) ?: [];
        if ($imports) {
            $lines[] = '';
            $lines[] = '[b]' . count($imports) . ' lần nhập gần nhất[/b]';
            foreach ($imports as $im) {
                $sup = trim((string) ($im['sup'] ?? ''));
                $lines[] = '• ' . chatbot_date_vi($im['created_at'])
                    . ($sup !== '' ? ' · ' . $sup : '')
                    . ' · ' . chatbot_num($im['quantity']) . ($unit !== '' ? ' ' . $unit : '')
                    . ' · ' . number_format((float) $im['unit_price'], 0, ',', '.') . $per;
            }
        }

        // Lần đổi giá gần nhất. Lọc old_price > 0: dữ liệu CŨ còn sót các dòng
        // "lần đầu có giá" (old = 0, change_rate = -1) — đọc lên sẽ thành
        // "giảm 1% từ 0 đ", sai hoàn toàn. Bản ppc_record() hiện tại đã chặn.
        $chg = db_fetch_row(
            "SELECT old_price, new_price, change_rate, created_at
             FROM purchase_price_changes WHERE material_id = $mid AND old_price > 0
             ORDER BY created_at DESC, id DESC LIMIT 1"
        );
        if ($chg) {
            $lines[] = '';
            $lines[] = ((float) $chg['change_rate'] > 0 ? '⚠ Tăng ' : 'Biến động ')
                . chatbot_pct($chg['change_rate']) . ' ngày ' . chatbot_date_vi($chg['created_at'])
                . ' (' . number_format((float) $chg['old_price'], 0, ',', '.')
                . ' → ' . number_format((float) $chg['new_price'], 0, ',', '.') . $per . ')';
        }
        return implode("\n", $lines);
    }

    /* ---------------------------------------------------------------------
     *  7.2  SẢN LƯỢNG  (chủ đề output)
     * ------------------------------------------------------------------- */
    function chatbot_answer_output($product, array $p)
    {
        // --- Không nêu sản phẩm: bảng xếp hạng toàn nhà máy trong kỳ ---
        if (!$product) {
            $rows = db_fetch_array(
                "SELECT d.product_id, SUM(d.quantity) AS q, COUNT(*) AS n,
                        COALESCE(NULLIF(p.common_product_name, ''), p.product_name) AS nm, p.unit
                 FROM finished_product_production_data d
                 JOIN products p ON p.id = d.product_id
                 WHERE " . chatbot_period_where('d.created_at', $p) . "
                 GROUP BY d.product_id, nm, p.unit
                 ORDER BY q DESC LIMIT 10"
            ) ?: [];
            $tot = db_fetch_row(
                "SELECT COUNT(DISTINCT product_id) AS sp, COUNT(*) AS n, SUM(quantity) AS q
                 FROM finished_product_production_data
                 WHERE " . chatbot_period_where('created_at', $p)
            );
            $lines = ['[b]Sản lượng ' . $p['label'] . '[/b]'];
            if (!$rows) {
                $lines[] = 'Chưa ghi nhận sản lượng nào trong kỳ này.';
                return implode("\n", $lines);
            }
            $lines[] = 'Đã chạy ' . (int) $tot['sp'] . ' sản phẩm · ' . (int) $tot['n'] . ' lượt sản xuất';
            $lines[] = '';
            $lines[] = '[b]Top ' . count($rows) . ' sản lượng cao nhất[/b]';
            $i = 0;
            foreach ($rows as $r) {
                $i++;
                $lines[] = $i . '. ' . $r['nm'] . ': [b]' . chatbot_num($r['q'])
                    . (trim((string) $r['unit']) !== '' ? ' ' . $r['unit'] : '') . '[/b]'
                    . ' (' . (int) $r['n'] . ' lượt)';
            }
            return implode("\n", $lines);
        }

        // --- Có sản phẩm: số liệu kỳ này + so kỳ trước ---
        $pid  = (int) $product['id'];
        $unit = trim((string) $product['unit']);
        $cur  = db_fetch_row(
            "SELECT COUNT(*) AS n, SUM(quantity) AS q, MAX(created_at) AS last_at
             FROM finished_product_production_data
             WHERE product_id = $pid AND " . chatbot_period_where('created_at', $p)
        );
        $prevP = chatbot_period_prev($p);
        $prev  = db_fetch_row(
            "SELECT SUM(quantity) AS q FROM finished_product_production_data
             WHERE product_id = $pid AND " . chatbot_period_where('created_at', $prevP)
        );

        $q = $cur ? (float) $cur['q'] : 0;
        $n = $cur ? (int) $cur['n'] : 0;

        $lines   = ['[b]Sản lượng ' . $product['name'] . '[/b]'];
        $lines[] = 'Kỳ: ' . $p['label'] . ($p['explicit'] ? '' : ' (mặc định)');
        $lines[] = 'Đã sản xuất: [b]' . chatbot_num($q) . ($unit !== '' ? ' ' . $unit : '') . '[/b]'
            . ($n > 0 ? ' · ' . $n . ' lượt' : '');
        if ($n > 0) $lines[] = 'Lần gần nhất: ' . chatbot_date_vi($cur['last_at']);

        $pq   = $prev ? (float) $prev['q'] : 0;
        $rate = chatbot_change_rate($pq, $q);
        if ($rate !== null) {
            $lines[] = 'So với ' . $prevP['label'] . ' (' . chatbot_num($pq) . '): [b]'
                . chatbot_pct($rate) . '[/b]';
        } elseif ($pq <= 0 && $q > 0) {
            $lines[] = $prevP['label'] . ' không sản xuất sản phẩm này.';
        }
        if ($q <= 0) {
            $lines[] = '';
            $lines[] = '(Không có lượt sản xuất nào trong kỳ — thử hỏi kỳ rộng hơn, ví dụ "năm nay".)';
        }
        return implode("\n", $lines);
    }

    /* ---------------------------------------------------------------------
     *  7.3  GIÁ VỐN & BIẾN ĐỘNG  (chủ đề cost)
     * ------------------------------------------------------------------- */

    /** Giá vốn hiện hành + cơ cấu thành phần của 1 sản phẩm. */
    function chatbot_answer_cost_product(array $product)
    {
        $pid = (int) $product['id'];
        if (!chatbot_cost_model_ready()) {
            return 'Tôi chưa tra được giá vốn lúc này (không nạp được công thức tính). Vui lòng báo quản trị viên.';
        }
        // material_id = 0 → không dòng nào "đang biến động" → mọi NVL lấy giá hiện hành.
        $calc = ir_product_cost_breakdown($pid, 0, 0, 0);
        if (!$calc || empty($calc['rows'])) {
            return 'Sản phẩm [b]' . $product['name'] . '[/b] chưa có công thức nên chưa tính được giá vốn.';
        }
        $total = (float) $calc['total_new'];
        $unit  = trim((string) $product['unit']) !== '' ? (string) $product['unit'] : 'đơn vị';

        $lines   = ['[b]Giá vốn ' . $product['name'] . '[/b]'];
        $lines[] = 'Giá vốn hiện hành: [b]' . chatbot_money($total) . '[/b] / 1 ' . $unit;

        $rows = $calc['rows'];
        usort($rows, static fn($a, $b) => (float) $b['line_new'] <=> (float) $a['line_new']);
        $lines[] = '';
        $lines[] = '[b]Thành phần chiếm tỉ trọng lớn nhất[/b]';
        foreach (array_slice($rows, 0, 5) as $r) {
            $share = $total > 0 ? ((float) $r['line_new'] / $total) * 100 : 0;
            $lines[] = '• ' . $r['material_name'] . ': ' . chatbot_money($r['line_new'])
                . ' (' . number_format($share, 1, ',', '.') . '%)';
        }
        if (count($rows) > 5) $lines[] = '… và ' . (count($rows) - 5) . ' thành phần khác.';

        // NVL trong công thức vừa đổi giá 90 ngày qua.
        $chg = db_fetch_array(
            "SELECT ppc.old_price, ppc.new_price, ppc.change_rate, ppc.created_at,
                    COALESCE(NULLIF(mi.common_material_name, ''), mi.material_name) AS nm
             FROM purchase_price_changes ppc
             JOIN material_information mi ON mi.id = ppc.material_id
             WHERE ppc.material_id IN (SELECT material_id FROM product_materials WHERE product_id = $pid)
               AND ppc.created_at >= DATE_SUB(NOW(), INTERVAL 90 DAY)
             ORDER BY ppc.created_at DESC, ppc.id DESC LIMIT 5"
        ) ?: [];
        $lines[] = '';
        if ($chg) {
            $lines[] = '[b]NVL vừa đổi giá (90 ngày qua)[/b]';
            foreach ($chg as $c) {
                $lines[] = '• ' . $c['nm'] . ': ' . chatbot_pct($c['change_rate'])
                    . ' ngày ' . chatbot_date_vi($c['created_at']);
            }
        } else {
            $lines[] = '90 ngày qua không NVL nào trong công thức này đổi giá.';
        }
        $lines[] = '';
        $lines[] = '(Giá vốn tính theo định mức công thức × giá NVL hiện hành, đã gồm chi phí mua hàng.)';
        return implode("\n", $lines);
    }

    /** NVL đổi giá thì những sản phẩm nào bị ảnh hưởng giá vốn. */
    function chatbot_answer_cost_material(array $mat)
    {
        $mid = (int) $mat['id'];
        if (!chatbot_cost_model_ready()) {
            return 'Tôi chưa tra được giá vốn lúc này (không nạp được công thức tính). Vui lòng báo quản trị viên.';
        }
        // old_price > 0: xem chú thích cùng vấn đề ở chatbot_answer_mat_price().
        $chg = db_fetch_row(
            "SELECT old_price, new_price, change_rate, created_at
             FROM purchase_price_changes WHERE material_id = $mid AND old_price > 0
             ORDER BY created_at DESC, id DESC LIMIT 1"
        );
        $lines = ['[b]Giá vốn ảnh hưởng bởi ' . $mat['name'] . '[/b]'];
        if (!$chg) {
            $lines[] = 'Nguyên vật liệu này chưa từng ghi nhận biến động giá nào, nên giá vốn các sản phẩm chưa đổi vì nó.';
            $used = db_fetch_row("SELECT COUNT(*) AS n FROM product_materials WHERE material_id = $mid");
            $n = $used ? (int) $used['n'] : 0;
            if ($n > 0) $lines[] = 'Hiện có ' . $n . ' sản phẩm dùng NVL này trong công thức.';
            return implode("\n", $lines);
        }
        $old = (float) $chg['old_price'];
        $new = (float) $chg['new_price'];
        $lines[] = 'Lần đổi giá gần nhất ' . chatbot_date_vi($chg['created_at']) . ': '
            . number_format($old, 0, ',', '.') . ' → ' . number_format($new, 0, ',', '.')
            . ' đ (' . chatbot_pct($chg['change_rate']) . ')';

        $impact = ir_material_cost_impact($mid, $old, $new);
        if (!$impact) {
            $lines[] = '';
            $lines[] = 'Chưa sản phẩm nào dùng NVL này trong công thức.';
            return implode("\n", $lines);
        }
        $lines[] = '';
        $lines[] = '[b]' . count($impact) . ' sản phẩm dùng NVL này — ảnh hưởng nhiều nhất[/b]';
        foreach (array_slice($impact, 0, 5) as $it) {
            $lines[] = '• ' . $it['product_name'] . ': ' . chatbot_money($it['old_cost'])
                . ' → ' . chatbot_money($it['new_cost']) . ' (' . chatbot_pct($it['change_rate']) . ')';
        }
        return implode("\n", $lines);
    }

    /* ---------------------------------------------------------------------
     *  7.4  MUA HÀNG THEO NHÀ CUNG CẤP  (chủ đề supplier)
     * ------------------------------------------------------------------- */

    /** Đã mua gì của 1 NCC trong kỳ. */
    function chatbot_answer_supplier(array $sup, array $p)
    {
        $sid   = (int) $sup['id'];
        $where = chatbot_period_where('inv.created_at', $p);

        $sum = db_fetch_row(
            "SELECT COUNT(*) AS n, SUM(inventory_value) AS amount
             FROM stock_import_invoices inv
             WHERE inv.supplier_id = $sid AND " . chatbot_period_where('inv.created_at', $p)
        );
        $lines   = ['[b]Mua hàng của ' . $sup['name'] . '[/b]'];
        $lines[] = 'Kỳ: ' . $p['label'] . ($p['explicit'] ? '' : ' (mặc định)');

        $n = $sum ? (int) $sum['n'] : 0;
        if ($n === 0) {
            $lines[] = 'Không có phiếu nhập nào trong kỳ này.';
            $last = db_fetch_row(
                "SELECT created_at FROM stock_import_invoices WHERE supplier_id = $sid
                 ORDER BY created_at DESC, id DESC LIMIT 1"
            );
            if ($last) $lines[] = 'Lần mua gần nhất: ' . chatbot_date_vi($last['created_at']);
            return implode("\n", $lines);
        }
        $lines[] = 'Tổng: [b]' . $n . ' phiếu · ' . chatbot_money($sum['amount']) . '[/b]';

        $items = db_fetch_array(
            "SELECT si.material_id, si.product_id, SUM(si.quantity) AS q,
                    MAX(si.created_at) AS last_at,
                    SUBSTRING_INDEX(GROUP_CONCAT(si.unit_price ORDER BY si.created_at DESC, si.id DESC), ',', 1) AS last_price
             FROM stock_imports si
             JOIN stock_import_invoices inv ON inv.id = si.import_invoice_id
             WHERE inv.supplier_id = $sid AND $where
             GROUP BY si.material_id, si.product_id
             ORDER BY q DESC LIMIT 10"
        ) ?: [];
        if ($items) {
            $lines[] = '';
            $lines[] = '[b]Đã mua[/b]';
            foreach ($items as $it) {
                $info = chatbot_item_name($it['product_id'], $it['material_id']);
                $lines[] = '• ' . $info['name'] . ': ' . chatbot_num($it['q'])
                    . ($info['unit'] !== '' ? ' ' . $info['unit'] : '')
                    . ' · ' . number_format((float) $it['last_price'], 0, ',', '.') . ' đ';
            }
        }
        $lastAt = db_fetch_row(
            "SELECT MAX(created_at) AS d FROM stock_import_invoices
             WHERE supplier_id = $sid AND " . chatbot_period_where('created_at', $p)
        );
        if ($lastAt && $lastAt['d']) {
            $lines[] = '';
            $lines[] = 'Lần mua gần nhất trong kỳ: ' . chatbot_date_vi($lastAt['d']);
        }
        return implode("\n", $lines);
    }

    /** 1 NVL đang mua của những NCC nào — so giá giữa các NCC. */
    function chatbot_answer_supplier_of_material(array $mat)
    {
        $mid  = (int) $mat['id'];
        $unit = trim((string) $mat['unit']);

        $rows = db_fetch_array(
            "SELECT inv.supplier_id,
                    COALESCE(NULLIF(s.short_name, ''), s.supplier_name) AS sup,
                    COUNT(*) AS n, MAX(si.created_at) AS last_at,
                    SUBSTRING_INDEX(GROUP_CONCAT(si.unit_price ORDER BY si.created_at DESC, si.id DESC), ',', 1) AS last_price
             FROM stock_imports si
             JOIN stock_import_invoices inv ON inv.id = si.import_invoice_id
             JOIN suppliers s ON s.id = inv.supplier_id
             WHERE si.material_id = $mid
               AND si.type_import IN ('row_material_receiving', 'other_row_material_receiving')
             GROUP BY inv.supplier_id, sup
             ORDER BY last_at DESC"
        ) ?: [];

        $lines = ['[b]' . $mat['name'] . ' mua của nhà cung cấp nào[/b]'];
        if (!$rows) {
            $lines[] = 'Chưa có phiếu nhập nào cho NVL này nên chưa biết đang mua của ai.';
        } else {
            foreach ($rows as $r) {
                $lines[] = '• ' . $r['sup'] . ': '
                    . number_format((float) $r['last_price'], 0, ',', '.')
                    . ($unit !== '' ? ' đ/' . $unit : ' đ')
                    . ' · ' . (int) $r['n'] . ' lần · gần nhất ' . chatbot_date_vi($r['last_at']);
            }
            if (count($rows) > 1) {
                $prices = array_map(static fn($r) => (float) $r['last_price'], $rows);
                $min = min($prices); $max = max($prices);
                if ($min > 0 && $max > $min) {
                    $lines[] = '';
                    $lines[] = 'Chênh lệch giá gần nhất giữa các NCC: [b]'
                        . chatbot_pct((($max - $min) / $min) * 100) . '[/b].';
                }
            }
        }

        // NCC đã đăng ký cho NVL này nhưng chưa từng phát sinh phiếu.
        $reg = db_fetch_array(
            "SELECT COALESCE(NULLIF(s.short_name, ''), s.supplier_name) AS sup
             FROM material_supplier_map msm
             JOIN suppliers s ON s.id = msm.supplier_id
             WHERE msm.material_id = $mid"
        ) ?: [];
        $bought = array_map(static fn($r) => (string) $r['sup'], $rows);
        $never  = [];
        foreach ($reg as $r) {
            if (!in_array((string) $r['sup'], $bought, true)) $never[] = (string) $r['sup'];
        }
        if ($never) {
            $lines[] = '';
            $lines[] = 'Đã đăng ký nhưng chưa phát sinh mua: ' . implode(', ', $never) . '.';
        }
        return implode("\n", $lines);
    }

    /* ---------------------------------------------------------------------
     *  7.5  KHÁCH HÀNG & SẢN PHẨM KHÁCH MUA  (chủ đề customer)
     * ------------------------------------------------------------------- */

    /** 1 khách hàng đã mua gì trong kỳ. */
    function chatbot_answer_customer(array $cus, array $p)
    {
        $cid   = (int) $cus['id'];
        $where = chatbot_period_where('created_at', $p);

        $sum = db_fetch_row(
            "SELECT COUNT(*) AS n, COUNT(DISTINCT DATE(created_at)) AS days, SUM(amount) AS amount
             FROM sales_inventory_issue_data WHERE customer_id = $cid AND $where"
        );
        $lines   = ['[b]Khách hàng ' . $cus['name'] . '[/b]'];
        $lines[] = 'Kỳ: ' . $p['label'] . ($p['explicit'] ? '' : ' (mặc định)');

        $n = $sum ? (int) $sum['n'] : 0;
        if ($n === 0) {
            $lines[] = 'Không có đơn xuất bán nào trong kỳ này.';
            $last = db_fetch_row(
                "SELECT MAX(created_at) AS d FROM sales_inventory_issue_data WHERE customer_id = $cid"
            );
            if ($last && $last['d']) $lines[] = 'Lần mua gần nhất: ' . chatbot_date_vi($last['d']);
            return implode("\n", $lines);
        }
        $lines[] = 'Doanh số: [b]' . chatbot_money($sum['amount']) . '[/b] · '
            . (int) $sum['days'] . ' ngày xuất · ' . $n . ' dòng hàng';

        $items = db_fetch_array(
            "SELECT product_id, material_id, SUM(quantity) AS q, SUM(amount) AS amt, MAX(created_at) AS last_at
             FROM sales_inventory_issue_data
             WHERE customer_id = $cid AND $where
             GROUP BY product_id, material_id
             ORDER BY amt DESC LIMIT 8"
        ) ?: [];
        if ($items) {
            $lines[] = '';
            $lines[] = '[b]Sản phẩm mua nhiều nhất[/b]';
            foreach ($items as $it) {
                $info = chatbot_item_name($it['product_id'], $it['material_id']);
                $lines[] = '• ' . $info['name'] . ': ' . chatbot_num($it['q'])
                    . ($info['unit'] !== '' ? ' ' . $info['unit'] : '')
                    . ' · ' . chatbot_money($it['amt']);
            }
        }

        // Từng mua nhưng đã lâu không mua lại (mốc 60 ngày, không phụ thuộc kỳ đang xem).
        $cold = db_fetch_array(
            "SELECT product_id, material_id, MAX(created_at) AS last_at
             FROM sales_inventory_issue_data
             WHERE customer_id = $cid
             GROUP BY product_id, material_id
             HAVING last_at < DATE_SUB(NOW(), INTERVAL 60 DAY)
             ORDER BY last_at DESC LIMIT 5"
        ) ?: [];
        if ($cold) {
            $lines[] = '';
            $lines[] = '⚠ [b]Hơn 60 ngày chưa mua lại[/b]';
            foreach ($cold as $c) {
                $info = chatbot_item_name($c['product_id'], $c['material_id']);
                $lines[] = '• ' . $info['name'] . ' — lần cuối ' . chatbot_date_vi($c['last_at']);
            }
        }
        return implode("\n", $lines);
    }

    /** 1 sản phẩm đang được những khách nào mua. */
    function chatbot_answer_customer_of_product(array $product, array $p)
    {
        $pid  = (int) $product['id'];
        $unit = trim((string) $product['unit']);

        $rows = db_fetch_array(
            "SELECT s.customer_id, COALESCE(NULLIF(c.short_name, ''), c.name) AS kh,
                    SUM(s.quantity) AS q, SUM(s.amount) AS amt, MAX(s.created_at) AS last_at
             FROM sales_inventory_issue_data s
             LEFT JOIN customers c ON c.id = s.customer_id
             WHERE s.product_id = $pid AND " . chatbot_period_where('s.created_at', $p) . "
             GROUP BY s.customer_id, kh
             ORDER BY amt DESC"
        ) ?: [];

        $lines   = ['[b]Khách mua ' . $product['name'] . '[/b]'];
        $lines[] = 'Kỳ: ' . $p['label'] . ($p['explicit'] ? '' : ' (mặc định)');
        if (!$rows) {
            $lines[] = 'Không có khách nào mua sản phẩm này trong kỳ.';
            $last = db_fetch_row(
                "SELECT MAX(created_at) AS d FROM sales_inventory_issue_data WHERE product_id = $pid"
            );
            if ($last && $last['d']) $lines[] = 'Lần bán gần nhất: ' . chatbot_date_vi($last['d']);
            return implode("\n", $lines);
        }
        $lines[] = '';
        foreach ($rows as $r) {
            $kh = trim((string) ($r['kh'] ?? '')) !== '' ? $r['kh'] : 'Khách lẻ';
            $lines[] = '• ' . $kh . ': ' . chatbot_num($r['q']) . ($unit !== '' ? ' ' . $unit : '')
                . ' · ' . chatbot_money($r['amt']) . ' · gần nhất ' . chatbot_date_vi($r['last_at']);
        }
        return implode("\n", $lines);
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

    /**
     * Gọi đúng hàm trả lời theo chủ đề.
     * $entity = null khi user hỏi tổng hợp không nêu tên (chỉ vài chủ đề cho phép,
     * xem chatbot_topic_allows_overview). $period null = tự lấy tháng hiện tại.
     */
    function chatbot_answer($topic, $entity = null, $period = null)
    {
        $p    = is_array($period) ? $period : chatbot_parse_period('');
        $kind = is_array($entity) ? (string) ($entity['kind'] ?? 'product') : '';

        // Chủ đề cần tên cụ thể mà lại không có → nhắc user nêu tên (không phải lỗi quyền).
        $needName = static function ($topic) {
            $kinds = chatbot_topic_entity_kinds($topic);
            return 'Bạn muốn xem ' . mb_strtolower(chatbot_topic_label($topic), 'UTF-8')
                . ' của ' . chatbot_kind_label($kinds[0]) . ' nào? Nhắn kèm tên giúp tôi nhé.';
        };

        switch ($topic) {
            case 'formula':
                return $entity ? chatbot_answer_formula($entity) : $needName($topic);
            case 'stock':
                return $entity ? chatbot_answer_stock($entity) : $needName($topic);
            case 'mat_price':
                return $entity ? chatbot_answer_mat_price($entity) : $needName($topic);
            case 'output':
                return chatbot_answer_output($entity ?: null, $p);
            case 'cost':
                if ($kind === 'material') return chatbot_answer_cost_material($entity);
                return $entity ? chatbot_answer_cost_product($entity) : $needName($topic);
            case 'supplier':
                if ($kind === 'material') return chatbot_answer_supplier_of_material($entity);
                return $entity ? chatbot_answer_supplier($entity, $p) : $needName($topic);
            case 'customer':
                if ($kind === 'product') return chatbot_answer_customer_of_product($entity, $p);
                return $entity ? chatbot_answer_customer($entity, $p) : $needName($topic);
        }
        return 'Tôi chưa hỗ trợ chủ đề này.';
    }

    /* =====================================================================
     *  7b. NÚT "ĐI TIẾP" — nối các chủ đề thành một mạch hội thoại
     *  Mua hàng → giá nhập → giá vốn → sản lượng → khách hàng. Người dùng chỉ
     *  gõ 1 câu rồi bấm nút đi tiếp, không phải nhớ cú pháp của chủ đề khác.
     * ===================================================================== */

    /** 1 nút mở chủ đề $topic cho thực thể $entity (kèm kỳ đang xem, nếu có). */
    function chatbot_opt($label, $topic, $entity, $period = null)
    {
        $o = ['t' => $label, 'k' => $topic];
        if (is_array($entity)) {
            $o['e'] = chatbot_kind_code($entity['kind'] ?? 'product');
            $o['i'] = (int) $entity['id'];
            $o['p'] = (int) $entity['id'];   // tương thích ngược với nút đời cũ
        }
        if (is_array($period) && !empty($period['explicit'])) {
            $o['f'] = $period['from'];
            $o['u'] = $period['to'];
        }
        return $o;
    }

    /**
     * Các nút gợi ý đi tiếp sau khi đã trả lời xong 1 câu.
     * Chỉ giữ nút mà user CÓ QUYỀN hỏi chủ đề đó — tránh bấm vào rồi bị từ chối.
     */
    function chatbot_next_options($topic, $entity, array $period, $user_id)
    {
        if (!is_array($entity)) return [];
        $kind = (string) ($entity['kind'] ?? 'product');
        $name = (string) $entity['name'];
        $out  = [];

        if ($topic === 'mat_price' && $kind === 'material') {
            $out[] = chatbot_opt('Ảnh hưởng giá vốn sản phẩm nào?', 'cost', $entity);
            $out[] = chatbot_opt('Mua của nhà cung cấp nào?', 'supplier', $entity);
        } elseif ($topic === 'supplier' && $kind === 'material') {
            $out[] = chatbot_opt('Xem giá nhập ' . $name, 'mat_price', $entity);
            $out[] = chatbot_opt('Ảnh hưởng giá vốn sản phẩm nào?', 'cost', $entity);
        } elseif ($topic === 'cost' && $kind === 'material') {
            $out[] = chatbot_opt('Xem giá nhập ' . $name, 'mat_price', $entity);
            $out[] = chatbot_opt('Mua của nhà cung cấp nào?', 'supplier', $entity);
        } elseif ($topic === 'cost' && $kind === 'product') {
            $out[] = chatbot_opt('Xem công thức ' . $name, 'formula', $entity);
            $out[] = chatbot_opt('Sản lượng ' . $name, 'output', $entity, $period);
        } elseif ($topic === 'output' && $kind === 'product') {
            $out[] = chatbot_opt('Tồn kho hiện tại', 'stock', $entity);
            $out[] = chatbot_opt('Giá vốn ' . $name, 'cost', $entity);
            $out[] = chatbot_opt('Khách nào đang mua?', 'customer', $entity, $period);
        } elseif ($topic === 'customer' && $kind === 'product') {
            $out[] = chatbot_opt('Tồn kho hiện tại', 'stock', $entity);
            $out[] = chatbot_opt('Sản lượng ' . $name, 'output', $entity, $period);
        } elseif ($topic === 'formula' && $kind === 'product') {
            $out[] = chatbot_opt('Giá vốn ' . $name, 'cost', $entity);
            $out[] = chatbot_opt('Tồn kho hiện tại', 'stock', $entity);
        } elseif ($topic === 'stock' && $kind === 'product') {
            $out[] = chatbot_opt('Sản lượng ' . $name, 'output', $entity, $period);
            $out[] = chatbot_opt('Khách nào đang mua?', 'customer', $entity, $period);
        }

        $mine = chatbot_user_topics($user_id);
        return array_values(array_filter($out, static fn($o) => in_array($o['k'], $mine, true)));
    }

    /** Nhãn nút gợi ý khi hỏi ngược "có phải bạn hỏi về…". */
    function chatbot_option_label($topic, array $entity)
    {
        $n = $entity['name'];
        switch ($topic) {
            case 'stock':     return 'Show tôi tồn kho ' . $n;
            case 'mat_price': return 'Giá nhập ' . $n;
            case 'output':    return 'Sản lượng ' . $n;
            case 'cost':      return 'Giá vốn ' . $n;
            case 'supplier':  return 'Mua hàng ' . $n;
            case 'customer':  return 'Khách hàng ' . $n;
        }
        return 'Show tôi công thức ' . $n;
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

        // Kỳ thời gian nêu trong câu hỏi (không nêu = tháng hiện tại).
        $period = chatbot_parse_period($norm);

        /* Dò tên thực thể theo ĐÚNG THỨ TỰ ƯU TIÊN của chủ đề: chủ đề supplier
           thử hiểu là tên NCC trước, không ra mới thử hiểu là tên NVL… Khớp ĐỦ
           tên (nằm trọn trong câu) luôn thắng khớp mờ ở mọi loại, nên phải quét
           hết một lượt "khớp đủ" rồi mới tới lượt "khớp mờ". */
        $kinds = chatbot_topic_entity_kinds($topic);

        foreach ($kinds as $kind) {
            $full = chatbot_match_full($norm, $kind);
            if (count($full) === 1) return chatbot_reply($cid, $uid, $topic, $full[0], $period);
            if (count($full) > 1)   return [chatbot_ask_choice($cid, $topic, $full, $period)];
        }

        $cands = [];
        $anyToken = false;
        foreach ($kinds as $kind) {
            $partial = chatbot_match_partial($norm, 5, $kind);
            if ($partial['tokens']) $anyToken = true;
            foreach ($partial['items'] as $it) $cands[] = $it;
        }

        // Không còn từ nào để làm tên → chủ đề nào cho phép thì trả báo cáo tổng hợp.
        if (!$anyToken || !$cands) {
            if (chatbot_topic_allows_overview($topic) && $topic === 'output') {
                return chatbot_reply($cid, $uid, $topic, null, $period);
            }
            if (!$anyToken) {
                return [chatbot_say($cid, 'Bạn muốn xem ' . chatbot_topic_label($topic)
                    . ' của ' . chatbot_kind_label($kinds[0]) . ' nào? Nhắn kèm tên giúp tôi nhé.')];
            }
            return [chatbot_say($cid,
                'Tôi không tìm thấy ' . chatbot_kind_label($kinds[0]) . ' nào khớp với "' . trim($text) . '".' . "\n"
                . 'Bạn thử nhắn lại tên (có thể chỉ cần vài chữ) giúp tôi nhé.')];
        }

        // Đúng 1 ứng viên và mọi từ khóa đều khớp chính xác → trả lời luôn cho nhanh.
        if (count($cands) === 1 && $cands[0]['exact_all']) {
            return chatbot_reply($cid, $uid, $topic, $cands[0]['p'], $period);
        }
        usort($cands, static fn($a, $b) => $b['score'] <=> $a['score']);
        $cands = array_slice($cands, 0, 5);
        return [chatbot_ask_choice($cid, $topic, array_map(static fn($i) => $i['p'], $cands), $period)];
    }

    /**
     * Trả lời + gắn luôn cụm nút "đi tiếp" sang chủ đề liên quan.
     * Mọi nhánh trả lời cuối cùng đều đi qua đây để hành vi đồng nhất.
     */
    function chatbot_reply($conversation_id, $user_id, $topic, $entity, array $period)
    {
        $body = chatbot_answer($topic, $entity, $period);
        $opts = chatbot_next_options($topic, $entity, $period, $user_id);
        return [chatbot_say($conversation_id, $body, $opts)];
    }

    /** Gửi câu hỏi ngược "có phải bạn hỏi về…" kèm các nút chọn. */
    function chatbot_ask_choice($conversation_id, $topic, array $entities, $period = null)
    {
        $body = 'Có phải bạn hỏi về ' . mb_strtolower(chatbot_topic_label($topic), 'UTF-8')
            . ' của ' . chatbot_kind_label($entities[0]['kind'] ?? 'product')
            . ' sau, nếu đúng xin chọn 1.';

        $options = [];
        foreach ($entities as $e) {
            $options[] = chatbot_opt(chatbot_option_label($topic, $e), $topic, $e, $period);
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
     * User bấm 1 nút gợi ý.
     * $pick = ['k' => chủ đề|cancel, 'e' => loại thực thể (p/m/s/c), 'i' => id,
     *          'f'/'u' => kỳ từ/đến (tùy chọn), 'src' => message_id].
     * 'p' là field ĐỜI CŨ (chỉ product_id) — vẫn đọc để các tin nhắn cũ trong DB
     * bấm lại vẫn chạy đúng.
     */
    function chatbot_handle_pick($conversation_id, $user_id, array $pick)
    {
        $cid   = (int) $conversation_id;
        $uid   = (int) $user_id;
        $topic = (string) ($pick['k'] ?? '');
        $src   = (int) ($pick['src'] ?? 0);
        if ($src > 0) chatbot_mark_options_used($src);

        if ($topic === 'cancel') {
            return [chatbot_say($cid, 'Rõ - có gì cứ nhắn tôi nhé.')];
        }
        $topics = chatbot_topics();
        if (!isset($topics[$topic])) {
            return [chatbot_say($cid, chatbot_help_text($uid))];
        }
        if (!chatbot_can($uid, $topic)) {
            return [chatbot_say($cid, 'Bạn chưa được cấp quyền hỏi về chủ đề [b]' . chatbot_topic_label($topic) . '[/b].')];
        }

        $eid  = (int) ($pick['i'] ?? ($pick['p'] ?? 0));
        $kind = isset($pick['e']) && $pick['e'] !== ''
            ? chatbot_kind_from_code($pick['e'])
            : 'product';

        // Kỳ đã chốt sẵn trong nút (nếu có) → giữ nguyên kỳ user đang xem.
        $from = (string) ($pick['f'] ?? '');
        $to   = (string) ($pick['u'] ?? '');
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $from) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $to)) {
            $period = ['from' => $from, 'to' => $to, 'explicit' => true,
                       'label' => chatbot_date_vi($from) . ' → ' . chatbot_date_vi($to)];
            // Trọn 1 tháng thì gọi tên tháng cho dễ đọc.
            if ($from === date('Y-m-01', strtotime($from)) && $to === date('Y-m-t', strtotime($from))) {
                $period['label'] = 'tháng ' . date('n/Y', strtotime($from));
            }
        } else {
            $period = chatbot_parse_period('');
        }

        if ($eid <= 0) return chatbot_reply($cid, $uid, $topic, null, $period);

        $entity = chatbot_entity_by_id($kind, $eid);
        if (!$entity) {
            return [chatbot_say($cid, 'Tôi không tìm thấy ' . chatbot_kind_label($kind)
                . ' này nữa — có thể đã bị xóa khỏi hệ thống.')];
        }
        return chatbot_reply($cid, $uid, $topic, $entity, $period);
    }
}
