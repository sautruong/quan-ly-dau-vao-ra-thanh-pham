<?php
/**
 * production_reminder — NHẮC NHỞ NHẬP SẢN LƯỢNG SẢN XUẤT.
 *
 * User chốt 5/8/2026: "cài đặt nhắc nhở gồm chọn thời gian (ví dụ 17:00 - không tính chủ nhật);
 * nếu sang view /inventory_management/dashboard chưa thấy phát sinh dữ liệu trong ngày thì đẩy
 * chuông nhắc admin, hoặc chọn nhắc vào chat chỉ định user hoặc nhóm chat cụ thể: tài khoản
 * Safe King sẽ phát tin nhắn này." — và chốt thêm: HAI Ô TICK ĐỘC LẬP, bật cả hai cùng lúc được.
 *
 * ==============================================================================
 * VÌ SAO KHÔNG CẦN POLLER RIÊNG
 * ==============================================================================
 * Khác YC1 (xuất kế hoạch — phải CHỤP ẢNH nên cần trình duyệt của đúng một người), việc này
 * thuần server: chỉ đọc DB rồi tạo thông báo. Vì vậy `prm_run_sweep()` được gọi ĐI NHỜ trong
 * endpoint `report/auto_report_due_check` — endpoint đó đã được poller toàn cục
 * (public/js/shared/auto_report_poller.js, nhúng ở layout/chat-widget.php) gọi đều đặn cho MỌI
 * user đang đăng nhập. Thêm một poller thứ hai chỉ để chạy vài câu SELECT là phí.
 *
 * Hệ quả cần biết: KHÔNG ai online thì không có nhịp nào chạy → lời nhắc trượt sang lúc có người
 * mở app. Chấp nhận được, vì bản thân lời nhắc là để nhắc NGƯỜI ĐANG LÀM VIỆC.
 *
 * Prefix prm_*. Bảng tự tạo qua prm_ensure_tables().
 */

if (date_default_timezone_get() !== 'Asia/Ho_Chi_Minh') {
    date_default_timezone_set('Asia/Ho_Chi_Minh');
}

require_once __DIR__ . '/notifications.php';

if (!function_exists('prm_ensure_tables')) {

    /** Loại chứng từ mà view "Nhập sản lượng sản xuất" ghi ra (khoá cứng ở dashboardAction). */
    if (!defined('PRM_TYPE_IMPORT')) {
        define('PRM_TYPE_IMPORT', 'fg_receipt_production');
    }

    /* ============================================================
     *  SCHEMA
     * ============================================================ */

    function prm_ensure_tables()
    {
        static $done = false;
        if ($done) return;
        $done = true;

        db_query("CREATE TABLE IF NOT EXISTS production_reminder_configs (
            id                    INT(11) NOT NULL AUTO_INCREMENT,
            is_active             TINYINT(1) NOT NULL DEFAULT 1,
            /* Giờ bắt đầu nhắc trong ngày. */
            remind_time           TIME NOT NULL DEFAULT '17:00:00',
            /* 1 = bỏ qua chủ nhật (mặc định, đúng yêu cầu user). */
            skip_sunday           TINYINT(1) NOT NULL DEFAULT 1,
            /* HAI ĐƯỜNG NHẮC ĐỘC LẬP — bật cả hai cùng lúc được (user chốt). */
            notify_bell           TINYINT(1) NOT NULL DEFAULT 1,
            notify_chat           TINYINT(1) NOT NULL DEFAULT 0,
            chat_recipient_type   ENUM('users','group') NOT NULL DEFAULT 'users',
            chat_recipient_user_ids TEXT DEFAULT NULL,
            chat_recipient_conversation_id INT(11) NOT NULL DEFAULT 0,
            /* Câu nhắc tuỳ biến; rỗng = dùng câu mặc định. */
            message               VARCHAR(500) DEFAULT NULL,
            /* Chốt đã nhắc trong ngày — mỗi ngày nhắc ĐÚNG 1 LẦN, không rả rích mỗi nhịp poll. */
            last_notified_date    DATE DEFAULT NULL,
            last_notified_at      DATETIME DEFAULT NULL,
            created_by            INT(11) NOT NULL DEFAULT 0,
            created_at            DATETIME NOT NULL,
            updated_at            DATETIME NOT NULL,
            deleted_at            DATETIME DEFAULT NULL,
            PRIMARY KEY (id),
            KEY idx_active (is_active)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci");
    }

    /* ============================================================
     *  TIỆN ÍCH
     * ============================================================ */

    function prm_today() { return date('Y-m-d'); }
    function prm_now()   { return date('Y-m-d H:i:s'); }

    function prm_normalize_time($t)
    {
        $t = trim((string) $t);
        if ($t === '') return '';
        if (preg_match('/^(\d{1,2}):(\d{2})(?::(\d{2}))?$/', $t, $m)) {
            $h = (int) $m[1]; $i = (int) $m[2]; $s = isset($m[3]) ? (int) $m[3] : 0;
            if ($h > 23 || $i > 59 || $s > 59) return '';
            return sprintf('%02d:%02d:%02d', $h, $i, $s);
        }
        return '';
    }

    function prm_recipient_user_ids($cfg)
    {
        $raw = (string) ($cfg['chat_recipient_user_ids'] ?? '');
        if ($raw === '') return [];
        $arr = json_decode($raw, true);
        if (!is_array($arr)) return [];
        $out = [];
        foreach ($arr as $v) { $v = (int) $v; if ($v > 0) $out[] = $v; }
        return array_values(array_unique($out));
    }

    /* ============================================================
     *  ĐỌC / GHI CẤU HÌNH
     * ============================================================ */

    function prm_config()
    {
        prm_ensure_tables();
        $row = db_fetch_row("SELECT * FROM production_reminder_configs WHERE deleted_at IS NULL ORDER BY id ASC LIMIT 1");
        return $row ?: null;
    }

    /**
     * Lưu cấu hình. ĐỔI GIỜ = mở khoá nhắc lại trong ngày (cùng bài học với auto_report /
     * plan_auto_export: không xoá mốc `last_notified_date` thì sửa giờ xong phải đợi hôm sau).
     */
    function prm_save($in, $actor_id = 0)
    {
        prm_ensure_tables();

        $time = prm_normalize_time($in['remind_time'] ?? '');
        if ($time === '') return ['ok' => false, 'error' => 'Giờ nhắc không hợp lệ (định dạng HH:MM).'];

        $bell = !empty($in['notify_bell']);
        $chat = !empty($in['notify_chat']);
        if (!$bell && !$chat) {
            return ['ok' => false, 'error' => 'Chọn ít nhất một cách nhắc: đẩy chuông hoặc nhắn chat.'];
        }

        $type = (($in['chat_recipient_type'] ?? 'users') === 'group') ? 'group' : 'users';
        $uids = [];
        $cid  = 0;
        if ($chat) {
            if ($type === 'group') {
                $cid = (int) ($in['chat_recipient_conversation_id'] ?? 0);
                if ($cid <= 0) return ['ok' => false, 'error' => 'Chưa chọn nhóm chat nhận lời nhắc.'];
            } else {
                $raw = $in['chat_recipient_user_ids'] ?? [];
                if (is_string($raw)) $raw = json_decode($raw, true) ?: [];
                foreach ((array) $raw as $v) { $v = (int) $v; if ($v > 0) $uids[] = $v; }
                $uids = array_values(array_unique($uids));
                if (!$uids) return ['ok' => false, 'error' => 'Chưa chọn người nhận lời nhắc.'];
            }
        }

        $data = [
            'is_active'                      => !empty($in['is_active']) ? 1 : 0,
            'remind_time'                    => $time,
            'skip_sunday'                    => !empty($in['skip_sunday']) ? 1 : 0,
            'notify_bell'                    => $bell ? 1 : 0,
            'notify_chat'                    => $chat ? 1 : 0,
            'chat_recipient_type'            => $type,
            'chat_recipient_user_ids'        => $uids ? json_encode($uids) : null,
            'chat_recipient_conversation_id' => $cid,
            'message'                        => trim((string) ($in['message'] ?? '')) ?: null,
            'updated_at'                     => prm_now(),
        ];

        $cur = prm_config();
        if ($cur) {
            if ((string) $cur['remind_time'] !== $time) {
                $data['last_notified_date'] = null;
                $data['last_notified_at']   = null;
            }
            db_update('production_reminder_configs', $data, 'id = ' . (int) $cur['id']);
            return ['ok' => true, 'id' => (int) $cur['id']];
        }

        $data['created_by'] = (int) $actor_id;
        $data['created_at'] = prm_now();
        $id = (int) db_insert('production_reminder_configs', $data);
        return $id > 0 ? ['ok' => true, 'id' => $id] : ['ok' => false, 'error' => 'Không lưu được cấu hình.'];
    }

    /* ============================================================
     *  HÔM NAY ĐÃ NHẬP SẢN LƯỢNG CHƯA
     * ============================================================ */

    /**
     * Hỏi thẳng SỔ GỐC `stock_imports`.
     *
     * VÌ SAO KHÔNG HỎI `finished_product_production_data`: bảng đó là bảng TỔNG HỢP, khoá theo
     * (product_id, ngày) và có thể còn dòng cũ với quantity = 0 do thao tác gỡ sản phẩm — đếm ở
     * đó phải kèm `quantity > 0` mới đúng, dễ sai. `stock_imports` có 1 dòng cho mỗi lần ghi
     * thật, đúng nghĩa câu hỏi "hôm nay có ai nhập gì chưa".
     *
     * `created_at` ở stock_imports là NGÀY NGHIỆP VỤ người dùng chọn ở ô "Ngày giờ ghi" (không
     * phải giờ INSERT) — đúng thứ cần so, nhưng vì có phần giờ nên phải bọc DATE().
     */
    function prm_has_production_on($date = null)
    {
        $d = $date ?: prm_today();
        $safe = escape_string(date('Y-m-d', strtotime($d)));
        $row = db_fetch_row(
            "SELECT COUNT(*) AS c FROM stock_imports
              WHERE type_import = '" . PRM_TYPE_IMPORT . "'
                AND DATE(created_at) = '{$safe}'"
        );
        return $row && (int) $row['c'] > 0;
    }

    /* ============================================================
     *  QUÉT + NHẮC
     * ============================================================ */

    /**
     * Gọi đi nhờ trong endpoint poll toàn cục. Rẻ: thoát sớm ở 4 điều kiện đầu, chỉ chạm DB
     * đếm sản lượng khi thật sự đã tới giờ và chưa nhắc hôm nay.
     */
    function prm_run_sweep()
    {
        $cfg = prm_config();
        if (!$cfg || empty($cfg['is_active'])) return;
        if (!empty($cfg['skip_sunday']) && (int) date('w') === 0) return;
        if ((string) $cfg['last_notified_date'] === prm_today()) return;
        if (time() < strtotime(prm_today() . ' ' . $cfg['remind_time'])) return;

        // Đã có dữ liệu → không nhắc, và CHỐT LUÔN là "xong việc hôm nay" để các nhịp poll sau
        // không phải đếm lại suốt buổi tối.
        if (prm_has_production_on()) {
            db_update('production_reminder_configs', [
                'last_notified_date' => prm_today(),
                'last_notified_at'   => prm_now(),
            ], 'id = ' . (int) $cfg['id']);
            return;
        }

        $text = trim((string) ($cfg['message'] ?? ''));
        if ($text === '') {
            $text = 'Đến ' . date('H:i', strtotime($cfg['remind_time'])) . ' ngày '
                  . date('d/m/Y') . ' vẫn chưa có ai nhập sản lượng sản xuất hôm nay.';
        }

        if (!empty($cfg['notify_bell'])) {
            notify_admins(
                'Chưa nhập sản lượng sản xuất hôm nay',
                $text,
                'inventory_management/dashboard',
                'general'
            );
        }

        if (!empty($cfg['notify_chat'])) {
            prm_send_chat($cfg, $text);
        }

        db_update('production_reminder_configs', [
            'last_notified_date' => prm_today(),
            'last_notified_at'   => prm_now(),
        ], 'id = ' . (int) $cfg['id']);
    }

    /** Safe King phát lời nhắc vào chat (DM từng người, hoặc 1 nhóm). */
    function prm_send_chat($cfg, $text)
    {
        require_once __DIR__ . '/chat.php';
        require_once __DIR__ . '/chat_bot.php';
        chat_ensure_tables();
        chatbot_ensure_schema();

        $bot = (int) chatbot_user_id();
        if ($bot <= 0) return;

        $body = '⏰ ' . $text;

        if ((string) ($cfg['chat_recipient_type'] ?? 'users') === 'group') {
            $cid = (int) $cfg['chat_recipient_conversation_id'];
            if ($cid <= 0) return;
            // Safe King là tài khoản HỆ THỐNG — tự vào nhóm nếu chưa là thành viên, nếu không
            // tin nhắn ghi được nhưng mọi kiểm tra "có phải thành viên" ở nơi khác đều trượt.
            $has = db_num_rows("SELECT 1 FROM chat_participants WHERE conversation_id = {$cid} AND user_id = {$bot} LIMIT 1");
            if ($has <= 0) {
                db_insert('chat_participants', [
                    'conversation_id' => $cid,
                    'user_id'         => $bot,
                    'role'            => 'member',
                    'joined_at'       => prm_now(),
                ]);
            }
            chatbot_say($cid, $body);
            return;
        }

        foreach (prm_recipient_user_ids($cfg) as $uid) {
            if ((int) $uid === $bot) continue;
            $cid = (int) chatbot_open_conversation((int) $uid);
            if ($cid > 0) chatbot_say($cid, $body);
        }
    }
}
