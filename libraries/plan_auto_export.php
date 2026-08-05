<?php
/**
 * plan_auto_export — XUẤT KẾ HOẠCH SẢN XUẤT ĐỊNH KỲ (view production_staff/long_term_production_plan).
 *
 * User chốt 2026-08-05: "Thiết lập 16:50 hằng ngày (không tính chủ nhật) sẽ tự chuyển kế hoạch
 * sản xuất của NGÀY MAI qua tab 'Kế hoạch sản xuất hằng ngày' thay cho button 'Xuất KH'. Đồng
 * thời chụp kế hoạch ngày mai này gửi vào tin nhắn Safe King."
 *
 * ==============================================================================
 * VÌ SAO KIẾN TRÚC GIỐNG auto_report (hẹn giờ PHÍA CLIENT, không cron)
 * ==============================================================================
 * Việc này gồm 2 phần rất khác nhau:
 *   1. CHUYỂN kế hoạch  → thuần server (đọc long_term_production_plans của ngày mai rồi ghi
 *                          production_plans/additional_tasks) — không cần trình duyệt.
 *   2. CHỤP ảnh gửi chat → BẮT BUỘC có trình duyệt (html2canvas), mà hệ thống KHÔNG có cron.
 * Vì phần 2 quyết định, toàn bộ lịch chạy trong trình duyệt của MỘT tài khoản được chỉ định
 * (`delegate_user_id`) đang online — đúng mô hình đã chạy thật ở libraries/auto_report.php.
 * Prefix pae_*. Bảng tự tạo qua pae_ensure_tables().
 *
 * ==============================================================================
 * KHÁC auto_report ở 3 điểm (đừng copy nhầm)
 * ==============================================================================
 *  · NGƯỜI GỬI là tài khoản hệ thống **Safe King** (`chatbot_user_id()`), không phải người được
 *    uỷ quyền. User chốt vậy để tin nhắn hiện ra dưới danh nghĩa hệ thống, không phải cá nhân ai.
 *  · CHỈ MỘT LỊCH (bảng vẫn để nhiều dòng cho dễ mở rộng, nhưng `pae_config()` luôn lấy dòng đầu
 *    còn sống). User mô tả đúng một mốc giờ, dựng màn quản trị nhiều lịch là thừa.
 *  · KHÔNG có luồng uỷ quyền Nhận/Từ chối. Người thực hiện do admin chọn thẳng trong Cài đặt;
 *    thêm bước xác nhận chỉ làm chậm mà không giải quyết vấn đề gì ở đây.
 *
 * Require từ: production_staffController.php (construct). Mọi hàm bọc function_exists vì file
 * này còn được require từ poller/guard.
 */

// Mọi so sánh giờ/ngày theo giờ VN (giống libraries/notifications.php + auto_report.php).
if (date_default_timezone_get() !== 'Asia/Ho_Chi_Minh') {
    date_default_timezone_set('Asia/Ho_Chi_Minh');
}

require_once __DIR__ . '/notifications.php';
/**
 * Mượn `ar_active_users()` + `ar_groups_for_user()` của auto_report thay vì chép lại: câu hỏi
 * "ai được nhận tin" và "user này ở nhóm chat nào" phải có ĐÚNG MỘT lời đáp trong hệ thống —
 * chép ra bản thứ hai là hẹn ngày hai màn Cài đặt hiện hai danh sách khác nhau.
 * File đó chỉ ĐỊNH NGHĨA hàm (bảng chỉ tạo khi gọi ar_ensure_tables()) nên require là vô hại.
 */
require_once __DIR__ . '/auto_report.php';

if (!function_exists('pae_ensure_tables')) {

    /* ============================================================
     *  SCHEMA
     * ============================================================ */

    /** Tạo bảng cấu hình + nhật ký nếu chưa có (1 lần / request). */
    function pae_ensure_tables()
    {
        static $done = false;
        if ($done) return;
        $done = true;

        db_query("CREATE TABLE IF NOT EXISTS ltp_auto_export_configs (
            id                        INT(11) NOT NULL AUTO_INCREMENT,
            is_active                 TINYINT(1) NOT NULL DEFAULT 1,
            /* Giờ chạy trong ngày. Kế hoạch được xuất là của NGÀY MAI so với ngày chạy. */
            send_time                 TIME NOT NULL DEFAULT '16:50:00',
            /* 1 = bỏ qua chủ nhật (mặc định, đúng yêu cầu user). Xét theo NGÀY CHẠY. */
            skip_sunday               TINYINT(1) NOT NULL DEFAULT 1,
            /* Trình duyệt của tài khoản này là nơi chụp ảnh — phải đang online lúc tới giờ. */
            delegate_user_id          INT(11) NOT NULL DEFAULT 0,
            /* Cửa sổ chờ (phút): quá mốc này mà chưa chạy thì tính là LỠ và báo chuông admin. */
            window_minutes            INT(11) NOT NULL DEFAULT 30,
            /* Chờ trang vẽ xong trước khi chụp (ms). */
            settle_ms                 INT(11) NOT NULL DEFAULT 3500,
            recipient_type            ENUM('users','group') NOT NULL DEFAULT 'users',
            recipient_user_ids        TEXT DEFAULT NULL,
            recipient_conversation_id INT(11) NOT NULL DEFAULT 0,
            caption                   VARCHAR(500) DEFAULT NULL,
            last_run_date             DATE DEFAULT NULL,
            last_run_at               DATETIME DEFAULT NULL,
            missed_notified_date      DATE DEFAULT NULL,
            created_by                INT(11) NOT NULL DEFAULT 0,
            created_at                DATETIME NOT NULL,
            updated_at                DATETIME NOT NULL,
            deleted_at                DATETIME DEFAULT NULL,
            PRIMARY KEY (id),
            KEY idx_delegate (delegate_user_id),
            KEY idx_active (is_active)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci");

        db_query("CREATE TABLE IF NOT EXISTS ltp_auto_export_log (
            id                INT(11) NOT NULL AUTO_INCREMENT,
            config_id         INT(11) NOT NULL,
            run_date          DATE NOT NULL,
            /* plan_date = ngày của kế hoạch được xuất (thường = run_date + 1). */
            plan_date         DATE DEFAULT NULL,
            status            ENUM('sent','exported','missed','failed') NOT NULL,
            actor_user_id     INT(11) NOT NULL DEFAULT 0,
            item_count        INT(11) NOT NULL DEFAULT 0,
            recipient_summary VARCHAR(500) DEFAULT NULL,
            message_ids       TEXT DEFAULT NULL,
            attachment_file   VARCHAR(255) DEFAULT NULL,
            note              VARCHAR(500) DEFAULT NULL,
            created_at        DATETIME NOT NULL,
            PRIMARY KEY (id),
            /* 1 lịch chỉ ghi 1 dòng/ngày — chạy lại trong ngày thì CẬP NHẬT, không đẻ dòng mới. */
            UNIQUE KEY uq_config_date (config_id, run_date)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci");
    }

    /* ============================================================
     *  TIỆN ÍCH
     * ============================================================ */

    function pae_today() { return date('Y-m-d'); }
    function pae_now()   { return date('Y-m-d H:i:s'); }

    /** Ngày của kế hoạch sẽ xuất = NGÀY MAI so với ngày chạy. */
    function pae_target_date($runDate = null)
    {
        $base = $runDate ? strtotime($runDate) : time();
        return date('Y-m-d', strtotime('+1 day', $base));
    }

    /** Chuẩn hoá 'HH:MM' | 'HH:MM:SS' → 'HH:MM:SS'; rỗng nếu sai định dạng. */
    function pae_normalize_time($t)
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

    /**
     * Ngày chạy có bị bỏ qua không. Xét theo NGÀY CHẠY (hôm nay), KHÔNG phải ngày của kế hoạch:
     * user nói "16:50 hằng ngày, không tính chủ nhật" — tức chủ nhật thì không ai ngồi bấm.
     * Chủ nhật vẫn xuất được BẰNG TAY qua nút "XUẤT KH" trên card (nút đó giữ nguyên).
     */
    function pae_is_skipped_day($cfg, $date = null)
    {
        if (empty($cfg['skip_sunday'])) return false;
        $d = $date ?: pae_today();
        return (int) date('w', strtotime($d)) === 0; // 0 = chủ nhật
    }

    /** Danh sách user id nhận tin (kiểu 'users'). */
    function pae_recipient_user_ids($cfg)
    {
        $raw = (string) ($cfg['recipient_user_ids'] ?? '');
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

    /** Lịch đang dùng (dòng còn sống đầu tiên). null = chưa cấu hình lần nào. */
    function pae_config()
    {
        pae_ensure_tables();
        $row = db_fetch_row("SELECT * FROM ltp_auto_export_configs WHERE deleted_at IS NULL ORDER BY id ASC LIMIT 1");
        return $row ?: null;
    }

    /**
     * Lưu cấu hình (tạo mới nếu chưa có). Trả ['ok'=>bool,'error'=>string,'id'=>int].
     *
     * ĐỔI GIỜ = MỞ KHOÁ CHẠY LẠI TRONG NGÀY — copy đúng bài học của auto_report: mỗi lịch chỉ
     * chạy 1 lần/ngày (chốt `last_run_date`), nên nếu không xoá mốc đó thì sửa giờ xong vẫn phải
     * đợi sang hôm sau, không ai hiểu vì sao. Sửa người nhận / chú thích mà GIỮ NGUYÊN giờ thì
     * vẫn khoá (tránh gửi trùng ngoài ý muốn).
     */
    function pae_save($in, $actor_id = 0)
    {
        pae_ensure_tables();

        $time = pae_normalize_time($in['send_time'] ?? '');
        if ($time === '') return ['ok' => false, 'error' => 'Giờ chạy không hợp lệ (định dạng HH:MM).'];

        $delegate = (int) ($in['delegate_user_id'] ?? 0);
        if ($delegate <= 0) {
            return ['ok' => false, 'error' => 'Chưa chọn người thực hiện — cần một tài khoản đang online để chụp ảnh.'];
        }

        $type = (($in['recipient_type'] ?? 'users') === 'group') ? 'group' : 'users';
        $uids = [];
        $cid  = 0;
        if ($type === 'group') {
            $cid = (int) ($in['recipient_conversation_id'] ?? 0);
            if ($cid <= 0) return ['ok' => false, 'error' => 'Chưa chọn nhóm chat nhận tin.'];
        } else {
            $raw = $in['recipient_user_ids'] ?? [];
            if (is_string($raw)) $raw = json_decode($raw, true) ?: [];
            foreach ((array) $raw as $v) { $v = (int) $v; if ($v > 0) $uids[] = $v; }
            $uids = array_values(array_unique($uids));
            if (!$uids) return ['ok' => false, 'error' => 'Chưa chọn người nhận tin.'];
        }

        $window = (int) ($in['window_minutes'] ?? 30);
        if ($window < 5)   $window = 5;
        if ($window > 240) $window = 240;

        $data = [
            'is_active'                 => !empty($in['is_active']) ? 1 : 0,
            'send_time'                 => $time,
            'skip_sunday'               => !empty($in['skip_sunday']) ? 1 : 0,
            'delegate_user_id'          => $delegate,
            'window_minutes'            => $window,
            'recipient_type'            => $type,
            'recipient_user_ids'        => $uids ? json_encode($uids) : null,
            'recipient_conversation_id' => $cid,
            'caption'                   => trim((string) ($in['caption'] ?? '')) ?: null,
            'updated_at'                => pae_now(),
        ];

        $cur = pae_config();
        if ($cur) {
            // Đổi giờ → xoá mốc đã chạy để lịch chạy lại được ngay hôm nay.
            if ((string) $cur['send_time'] !== $time) {
                $data['last_run_date']        = null;
                $data['last_run_at']          = null;
                $data['missed_notified_date'] = null;
            }
            db_update('ltp_auto_export_configs', $data, 'id = ' . (int) $cur['id']);
            return ['ok' => true, 'id' => (int) $cur['id']];
        }

        $data['created_by'] = (int) $actor_id;
        $data['created_at'] = pae_now();
        $id = (int) db_insert('ltp_auto_export_configs', $data);
        return $id > 0 ? ['ok' => true, 'id' => $id] : ['ok' => false, 'error' => 'Không lưu được cấu hình.'];
    }

    /* ============================================================
     *  ĐẾN GIỜ CHƯA?
     * ============================================================ */

    /** Mốc đầu/cuối cửa sổ chạy của hôm nay. */
    function pae_window_bounds($cfg)
    {
        $start = strtotime(pae_today() . ' ' . $cfg['send_time']);
        return [$start, $start + ((int) $cfg['window_minutes']) * 60];
    }

    /**
     * Lịch có đang tới lượt CHÍNH user này chạy không (poller gọi mỗi nhịp).
     * Trả cấu hình nếu tới lượt, null nếu không.
     */
    function pae_due_for_user($uid)
    {
        $uid = (int) $uid;
        if ($uid <= 0) return null;

        $cfg = pae_config();
        if (!$cfg) return null;
        if (empty($cfg['is_active'])) return null;
        if ((int) $cfg['delegate_user_id'] !== $uid) return null;
        if (pae_is_skipped_day($cfg)) return null;
        if ((string) $cfg['last_run_date'] === pae_today()) return null;

        [$start, $end] = pae_window_bounds($cfg);
        $now = time();
        if ($now < $start || $now > $end) return null;

        return $cfg;
    }

    /**
     * Quét lịch bị LỠ — chạy cơ hội trong endpoint poll (bất kỳ ai online gọi cũng được).
     * Quá cửa sổ mà chưa chạy → báo chuông admin ĐÚNG 1 LẦN/NGÀY (chốt missed_notified_date).
     */
    function pae_run_missed_sweep()
    {
        $cfg = pae_config();
        if (!$cfg || empty($cfg['is_active'])) return;
        if (pae_is_skipped_day($cfg)) return;
        if ((string) $cfg['last_run_date'] === pae_today()) return;
        if ((string) $cfg['missed_notified_date'] === pae_today()) return;

        [, $end] = pae_window_bounds($cfg);
        if (time() <= $end) return;

        $who = pae_user_name((int) $cfg['delegate_user_id']);
        notify_admins(
            'Chưa xuất được kế hoạch sản xuất tự động',
            'Lịch ' . date('H:i', strtotime($cfg['send_time'])) . ' hôm nay không chạy — '
            . 'tài khoản thực hiện (' . $who . ') không mở ứng dụng trong cửa sổ chờ '
            . (int) $cfg['window_minutes'] . ' phút. Kế hoạch ngày mai CHƯA được chuyển sang '
            . '"Kế hoạch sản xuất hằng ngày"; vào board bấm "XUẤT KH" để làm tay.',
            'production_staff/long_term_production_plan',
            'general'
        );
        db_update('ltp_auto_export_configs', ['missed_notified_date' => pae_today()], 'id = ' . (int) $cfg['id']);
        pae_log((int) $cfg['id'], 'missed', ['note' => 'Quá cửa sổ chờ, người thực hiện không online.']);
    }

    function pae_user_name($uid)
    {
        $uid = (int) $uid;
        if ($uid <= 0) return 'không rõ';
        $r = db_fetch_row("SELECT fullname, username FROM tbl_users WHERE id = {$uid} LIMIT 1");
        if (!$r) return '#' . $uid;
        $n = trim((string) ($r['fullname'] ?? ''));
        return $n !== '' ? $n : (string) ($r['username'] ?? ('#' . $uid));
    }

    /** Chốt "đã chạy hôm nay". */
    function pae_mark_run($cfgId)
    {
        db_update('ltp_auto_export_configs', [
            'last_run_date' => pae_today(),
            'last_run_at'   => pae_now(),
        ], 'id = ' . (int) $cfgId);
    }

    /** Ghi nhật ký 1 lượt chạy. 1 dòng/ngày — chạy lại thì cập nhật đè. */
    function pae_log($cfgId, $status, $extra = [])
    {
        pae_ensure_tables();
        $row = [
            'config_id'         => (int) $cfgId,
            'run_date'          => pae_today(),
            'plan_date'         => $extra['plan_date']         ?? pae_target_date(),
            'status'            => $status,
            'actor_user_id'     => (int) ($extra['actor_user_id'] ?? 0),
            'item_count'        => (int) ($extra['item_count'] ?? 0),
            'recipient_summary' => $extra['recipient_summary'] ?? null,
            'message_ids'       => isset($extra['message_ids']) ? json_encode($extra['message_ids']) : null,
            'attachment_file'   => $extra['attachment_file']   ?? null,
            'note'              => $extra['note']              ?? null,
            'created_at'        => pae_now(),
        ];
        // KHÔNG dùng db_insert (nó die khi trùng UNIQUE uq_config_date) — dựng câu lệnh thủ công
        // để có ON DUPLICATE KEY UPDATE, giống ar_log().
        $keys = []; $vals = []; $upd = [];
        foreach ($row as $k => $v) {
            $keys[] = "`$k`";
            $sql    = ($v === null) ? 'NULL' : "'" . escape_string((string) $v) . "'";
            $vals[] = $sql;
            if ($k !== 'config_id' && $k !== 'run_date') $upd[] = "`$k` = $sql";
        }
        db_query("INSERT INTO ltp_auto_export_log (" . implode(',', $keys) . ") VALUES (" . implode(',', $vals) . ")"
               . " ON DUPLICATE KEY UPDATE " . implode(', ', $upd));
    }

    /* ============================================================
     *  CHUYỂN KẾ HOẠCH NGÀY MAI (phần thuần server)
     * ============================================================ */

    /**
     * Đọc kế hoạch dài ngày của $planDate rồi ghi sang production_plans/additional_tasks —
     * tức làm ĐÚNG việc mà nút "XUẤT KH" trên card đang làm, nhưng ở phía server.
     *
     * VÌ SAO KHÔNG GỌI LẠI save_planAction: action đó đọc $_POST và `exit` sau khi in JSON, gọi
     * lại từ luồng khác là chết request. Ở đây lặp lại đúng 3 bước của nó (xoá sạch → chèn
     * plans → chèn tasks) trên dữ liệu đọc từ bảng, không qua DOM.
     *
     * Trả ['ok'=>bool,'error'=>string,'item_count'=>int,'plan_date'=>string].
     */
    function pae_export_plan_date($planDate)
    {
        $planDate = date('Y-m-d', strtotime($planDate));

        $items = db_fetch_array(
            "SELECT product_id, quantity
               FROM long_term_production_plans
              WHERE plan_date = '" . escape_string($planDate) . "'
              ORDER BY id ASC"
        ) ?: [];
        $tasks = db_fetch_array(
            "SELECT content
               FROM long_term_production_tasks
              WHERE plan_date = '" . escape_string($planDate) . "'
              ORDER BY id ASC"
        ) ?: [];

        if (!$items) {
            return ['ok' => false, 'error' => 'Kế hoạch ngày ' . date('d/m/Y', strtotime($planDate)) . ' chưa có sản phẩm nào.',
                    'item_count' => 0, 'plan_date' => $planDate];
        }

        // 1) Xoá kế hoạch hiện hành (hệ chỉ giữ MỘT kế hoạch đang chạy — giống save_planAction).
        db_query("DELETE FROM additional_tasks");
        db_query("DELETE FROM production_plans");

        // 2) Chèn sản phẩm. Giữ lại plan_id ĐẦU TIÊN để gắn "việc khác" vào (xem bước 3).
        $n = 0;
        $first_plan_id = 0;
        foreach ($items as $it) {
            $pid = (int) ($it['product_id'] ?? 0);
            if ($pid <= 0) continue;
            $plan_id = (int) db_insert('production_plans', [
                'product_id' => $pid,
                'quantity'   => (int) ($it['quantity'] ?? 0),
                'note'       => '',
            ]);
            if ($first_plan_id === 0) $first_plan_id = $plan_id;
            $n++;
        }

        // 3) Chèn "việc khác".
        //    ⚠️ HAI BẢNG DÙNG TÊN CỘT KHÁC NHAU — nguồn `long_term_production_tasks.content`,
        //    đích `additional_tasks.description`; và đích còn đòi `plan_id` trỏ về kế hoạch đại
        //    diện (đúng cách save_planAction đang làm). Ghi thẳng 'content' là mất sạch việc khác.
        if ($first_plan_id > 0) {
            foreach ($tasks as $t) {
                $c = trim((string) ($t['content'] ?? ''));
                if ($c === '') continue;
                db_insert('additional_tasks', [
                    'plan_id'     => $first_plan_id,
                    'description' => $c,
                ]);
            }
        }

        return ['ok' => true, 'item_count' => $n, 'plan_date' => $planDate];
    }

    /* ============================================================
     *  GỬI CHAT — NGƯỜI GỬI LÀ SAFE KING
     * ============================================================ */

    /**
     * Gửi ảnh kế hoạch vào chat với tư cách tài khoản hệ thống Safe King.
     * $fileMeta = kết quả chat_store_upload().
     */
    function pae_send_to_recipients($cfg, $fileMeta, $caption = '')
    {
        require_once __DIR__ . '/chat.php';
        require_once __DIR__ . '/chat_bot.php';
        chat_ensure_tables();
        chatbot_ensure_schema();

        $bot = (int) chatbot_user_id();
        if ($bot <= 0) return ['ok' => false, 'error' => 'Chưa có tài khoản hệ thống Safe King.'];
        if (empty($fileMeta) || empty($fileMeta['file_name'])) return ['ok' => false, 'error' => 'Thiếu ảnh kế hoạch.'];

        $planDate = pae_target_date();
        $body = trim((string) $caption);
        if ($body === '') $body = trim((string) ($cfg['caption'] ?? ''));
        if ($body === '') {
            $body = '🗓️ Kế hoạch sản xuất ngày ' . date('d/m/Y', strtotime($planDate))
                  . ' — đã chuyển sang "Kế hoạch sản xuất hằng ngày".';
        }

        $att = [
            'file_name'     => (string) $fileMeta['file_name'],
            'original_name' => (string) ($fileMeta['original_name'] ?? 'ke-hoach-san-xuat.png'),
            'mime'          => (string) ($fileMeta['mime'] ?? 'image/png'),
            'size'          => (int) ($fileMeta['size'] ?? 0),
            'is_image'      => (int) ($fileMeta['is_image'] ?? 1) ? 1 : 0,
        ];

        $mids = [];
        if ((string) ($cfg['recipient_type'] ?? 'users') === 'group') {
            $cid = (int) $cfg['recipient_conversation_id'];
            if ($cid <= 0) return ['ok' => false, 'error' => 'Chưa chọn nhóm chat nhận tin.'];
            // Safe King là tài khoản HỆ THỐNG nên tự thêm vào nhóm nếu chưa có — nếu không,
            // chat_insert_message ghi được nhưng nhóm không thấy bot đâu và mọi kiểm tra
            // "có phải thành viên" ở nơi khác sẽ trượt.
            pae_ensure_bot_in_group($cid, $bot);
            $mid = pae_send_one($cid, $bot, $body, $att);
            if ($mid > 0) $mids[] = $mid;
            $g = db_fetch_row("SELECT name FROM chat_conversations WHERE id = {$cid} LIMIT 1");
            $summary = 'Nhóm: ' . ($g ? (string) $g['name'] : ('#' . $cid));
        } else {
            $ids  = pae_recipient_user_ids($cfg);
            $sent = 0;
            foreach ($ids as $uid) {
                $uid = (int) $uid;
                if ($uid <= 0 || $uid === $bot) continue;
                // Mở đúng hội thoại riêng giữa người nhận và Safe King (tạo kèm lời chào nếu mới).
                $cid = (int) chatbot_open_conversation($uid);
                if ($cid <= 0) continue;
                $mid = pae_send_one($cid, $bot, $body, $att);
                if ($mid > 0) { $mids[] = $mid; $sent++; }
            }
            if ($sent === 0) return ['ok' => false, 'error' => 'Không gửi được cho người nhận nào.'];
            $summary = $sent . ' người nhận';
        }

        return ['ok' => true, 'message_ids' => $mids, 'recipient_summary' => $summary];
    }

    /** Bảo đảm Safe King là thành viên nhóm (idempotent). */
    function pae_ensure_bot_in_group($cid, $bot)
    {
        $cid = (int) $cid; $bot = (int) $bot;
        if ($cid <= 0 || $bot <= 0) return;
        $has = db_num_rows("SELECT 1 FROM chat_participants WHERE conversation_id = {$cid} AND user_id = {$bot} LIMIT 1");
        if ($has > 0) return;
        db_insert('chat_participants', [
            'conversation_id' => $cid,
            'user_id'         => $bot,
            'role'            => 'member',
            'joined_at'       => pae_now(),
        ]);
    }

    /** Chèn 1 tin ảnh + attachment (dùng chung 1 file trên đĩa). Trả message_id. */
    function pae_send_one($cid, $sender, $body, $att)
    {
        $mid = (int) chat_insert_message($cid, $sender, $body, 'image');
        if ($mid <= 0) return 0;
        $row = $att; $row['message_id'] = $mid;
        db_insert('chat_attachments', $row);
        chat_mark_read($cid, $sender, $mid); // bản của người gửi coi như đã đọc
        return $mid;
    }
}
