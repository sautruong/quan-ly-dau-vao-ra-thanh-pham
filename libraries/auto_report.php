<?php
/**
 * auto_report — Gửi báo cáo sản xuất TỰ ĐỘNG (view report/report/daily_dashboard).
 *
 * Vì nút "Chụp" chạy bằng html2canvas trong trình duyệt và hệ thống KHÔNG có cron,
 * "gửi tự động" = hẹn giờ phía client trong trình duyệt của một tài khoản được uỷ quyền
 * (đang online). Thư viện này giữ toàn bộ nghiệp vụ (cấu hình lịch, uỷ quyền, gửi vào chat,
 * quét lịch bị lỡ). Prefix ar_*. Bảng tự tạo qua ar_ensure_tables().
 *
 * Require từ: reportController.php (construct) và helper/permission.php (guard cần
 * ar_is_active_delegate trước khi controller chạy) → mọi hàm bọc trong function_exists.
 */

// Mọi so sánh giờ/ngày phải theo giờ VN (giống libraries/notifications.php).
if (date_default_timezone_get() !== 'Asia/Ho_Chi_Minh') {
    date_default_timezone_set('Asia/Ho_Chi_Minh');
}

require_once __DIR__ . '/notifications.php';

if (!function_exists('ar_ensure_tables')) {

    /* ============================================================
     *  SCHEMA
     * ============================================================ */

    /** Tạo 2 bảng cấu hình + nhật ký nếu chưa có (1 lần / request). */
    function ar_ensure_tables()
    {
        static $done = false;
        if ($done) return;
        $done = true;
        db_query("CREATE TABLE IF NOT EXISTS report_auto_send_configs (
            id                        INT(11) NOT NULL AUTO_INCREMENT,
            view_key                  VARCHAR(64)  NOT NULL DEFAULT 'daily_dashboard',
            title                     VARCHAR(255) NOT NULL DEFAULT '',
            delegate_user_id          INT(11) NOT NULL DEFAULT 0,
            delegation_status         ENUM('pending','accepted','declined') NOT NULL DEFAULT 'pending',
            delegated_at              DATETIME DEFAULT NULL,
            responded_at              DATETIME DEFAULT NULL,
            is_paused                 TINYINT(1) NOT NULL DEFAULT 0,
            paused_by                 INT(11) NOT NULL DEFAULT 0,
            paused_at                 DATETIME DEFAULT NULL,
            recipient_type            ENUM('users','group') NOT NULL DEFAULT 'users',
            recipient_user_ids        TEXT DEFAULT NULL,
            recipient_conversation_id INT(11) NOT NULL DEFAULT 0,
            send_time                 TIME NOT NULL DEFAULT '17:00:00',
            window_minutes            INT(11) NOT NULL DEFAULT 30,
            settle_ms                 INT(11) NOT NULL DEFAULT 4000,
            caption                   VARCHAR(500) DEFAULT NULL,
            last_sent_date            DATE DEFAULT NULL,
            missed_notified_date      DATE DEFAULT NULL,
            last_sent_at              DATETIME DEFAULT NULL,
            created_by                INT(11) NOT NULL DEFAULT 0,
            is_active                 TINYINT(1) NOT NULL DEFAULT 1,
            created_at                DATETIME NOT NULL,
            updated_at                DATETIME NOT NULL,
            PRIMARY KEY (id),
            KEY idx_delegate (delegate_user_id),
            KEY idx_active (is_active)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci");

        db_query("CREATE TABLE IF NOT EXISTS report_auto_send_log (
            id                 INT(11) NOT NULL AUTO_INCREMENT,
            config_id          INT(11) NOT NULL,
            send_date          DATE NOT NULL,
            status             ENUM('sent','missed','failed') NOT NULL,
            delegate_user_id   INT(11) NOT NULL DEFAULT 0,
            recipient_summary  VARCHAR(500) DEFAULT NULL,
            message_ids        TEXT DEFAULT NULL,
            attachment_file    VARCHAR(255) DEFAULT NULL,
            note               VARCHAR(500) DEFAULT NULL,
            created_at         DATETIME NOT NULL,
            PRIMARY KEY (id),
            UNIQUE KEY uq_config_date (config_id, send_date)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci");
    }

    /** Đăng ký menu trang quản trị (nhóm BÁO CÁO). Idempotent. */
    function ar_ensure_view_registered()
    {
        if (db_num_rows("SHOW TABLES LIKE 'tbl_views'") <= 0) return;
        db_query(
            "INSERT INTO tbl_views (module, controller, action, label, group_label, sort)
             VALUES ('report','report','auto_report_admin','Gửi báo cáo tự động','BÁO CÁO', 64)
             ON DUPLICATE KEY UPDATE label = VALUES(label), group_label = VALUES(group_label), sort = VALUES(sort)"
        );
    }

    /* ============================================================
     *  TIỆN ÍCH
     * ============================================================ */

    function ar_today() { return date('Y-m-d'); }
    function ar_now()   { return date('Y-m-d H:i:s'); }

    /** Chuẩn hoá 'HH:MM' | 'HH:MM:SS' -> 'HH:MM:SS', rỗng nếu sai. */
    function ar_normalize_time($t)
    {
        $t = trim((string) $t);
        if (preg_match('/^(\d{1,2}):(\d{2})(?::(\d{2}))?$/', $t, $m)) {
            $h = (int) $m[1]; $i = (int) $m[2]; $s = isset($m[3]) ? (int) $m[3] : 0;
            if ($h <= 23 && $i <= 59 && $s <= 59) {
                return sprintf('%02d:%02d:%02d', $h, $i, $s);
            }
        }
        return '';
    }

    /** Mảng int id người nhận từ JSON đã lưu. */
    function ar_recipient_user_ids($cfg)
    {
        $raw = $cfg['recipient_user_ids'] ?? '';
        if ($raw === '' || $raw === null) return [];
        $arr = json_decode((string) $raw, true);
        if (!is_array($arr)) return [];
        $out = [];
        foreach ($arr as $v) { $v = (int) $v; if ($v > 0) $out[$v] = $v; }
        return array_values($out);
    }

    /** Tên hiển thị 1 user (fullname, fallback username). */
    function ar_user_name($uid)
    {
        $uid = (int) $uid;
        if ($uid <= 0) return '';
        $row = db_fetch_row("SELECT fullname, username FROM tbl_users WHERE id = {$uid} LIMIT 1");
        if (!$row) return 'Tài khoản #' . $uid;
        $f = trim((string) ($row['fullname'] ?? ''));
        return $f !== '' ? $f : (string) ($row['username'] ?? ('#' . $uid));
    }

    /** Danh sách user còn hoạt động (cho ô chọn uỷ quyền / người nhận). */
    function ar_active_users()
    {
        $rows = db_fetch_array(
            "SELECT id, fullname, username FROM tbl_users
             WHERE status IS NULL OR status NOT IN ('blocked','left','system')
             ORDER BY fullname, username"
        ) ?: [];
        $out = [];
        foreach ($rows as $r) {
            $out[] = [
                'id'   => (int) $r['id'],
                'name' => trim((string) ($r['fullname'] ?? '')) !== '' ? $r['fullname'] : $r['username'],
            ];
        }
        return $out;
    }

    /** Các nhóm chat mà 1 user là thành viên (để chọn "nhóm nhận"). */
    function ar_groups_for_user($uid)
    {
        $uid = (int) $uid;
        if ($uid <= 0) return [];
        if (db_num_rows("SHOW TABLES LIKE 'chat_conversations'") <= 0) return [];
        $rows = db_fetch_array(
            "SELECT c.id, c.name,
                    (SELECT COUNT(*) FROM chat_participants p2 WHERE p2.conversation_id = c.id) AS member_count
             FROM chat_conversations c
             JOIN chat_participants p ON p.conversation_id = c.id AND p.user_id = {$uid}
             WHERE c.type = 'group'
             ORDER BY c.updated_at DESC, c.id DESC"
        ) ?: [];
        $out = [];
        foreach ($rows as $r) {
            $out[] = [
                'id'           => (int) $r['id'],
                'name'         => (string) ($r['name'] ?? ('Nhóm #' . (int) $r['id'])),
                'member_count' => (int) $r['member_count'],
            ];
        }
        return $out;
    }

    /** True nếu 1 conversation_id là nhóm và $uid là thành viên. */
    function ar_group_ok_for_user($conversation_id, $uid)
    {
        $cid = (int) $conversation_id; $uid = (int) $uid;
        if ($cid <= 0 || $uid <= 0) return false;
        if (db_num_rows("SHOW TABLES LIKE 'chat_conversations'") <= 0) return false;
        return db_num_rows(
            "SELECT 1 FROM chat_conversations c
             JOIN chat_participants p ON p.conversation_id = c.id AND p.user_id = {$uid}
             WHERE c.id = {$cid} AND c.type = 'group' LIMIT 1"
        ) > 0;
    }

    /* ============================================================
     *  ĐỌC CẤU HÌNH
     * ============================================================ */

    function ar_config($id)
    {
        ar_ensure_tables();
        $id = (int) $id;
        if ($id <= 0) return null;
        return db_fetch_row("SELECT * FROM report_auto_send_configs WHERE id = {$id} AND is_active = 1 LIMIT 1");
    }

    function ar_configs_all()
    {
        ar_ensure_tables();
        return db_fetch_array(
            "SELECT * FROM report_auto_send_configs WHERE is_active = 1 ORDER BY send_time ASC, id ASC"
        ) ?: [];
    }

    function ar_configs_for_delegate($uid)
    {
        ar_ensure_tables();
        $uid = (int) $uid;
        if ($uid <= 0) return [];
        return db_fetch_array(
            "SELECT * FROM report_auto_send_configs
             WHERE is_active = 1 AND delegate_user_id = {$uid}
             ORDER BY send_time ASC, id ASC"
        ) ?: [];
    }

    /**
     * Bổ sung thông tin hiển thị cho 1 cấu hình: tên uỷ quyền, mô tả người nhận,
     * nhãn trạng thái, giờ gửi HH:MM.
     */
    function ar_decorate($cfg)
    {
        $cfg['delegate_name'] = ar_user_name($cfg['delegate_user_id'] ?? 0);
        $cfg['send_time_hm']  = substr((string) ($cfg['send_time'] ?? ''), 0, 5);
        if (($cfg['recipient_type'] ?? 'users') === 'group') {
            $gid = (int) ($cfg['recipient_conversation_id'] ?? 0);
            $g = $gid > 0 ? db_fetch_row("SELECT name FROM chat_conversations WHERE id = {$gid} LIMIT 1") : null;
            $cfg['recipient_text'] = 'Nhóm: ' . ($g ? (string) $g['name'] : ('#' . $gid));
        } else {
            $ids = ar_recipient_user_ids($cfg);
            $names = array_map('ar_user_name', array_slice($ids, 0, 4));
            $more = count($ids) - count($names);
            $cfg['recipient_text'] = $names ? (implode(', ', $names) . ($more > 0 ? ' +' . $more : '')) : '(chưa chọn)';
        }
        $cfg['recipient_user_ids_arr'] = ar_recipient_user_ids($cfg);
        $st = $cfg['delegation_status'] ?? 'pending';
        $cfg['status_text'] = $st === 'accepted' ? 'Đã nhận uỷ quyền'
                            : ($st === 'declined' ? 'Đã từ chối' : 'Chờ xác nhận');

        // Tình trạng CHẠY HÔM NAY — trước đây hoàn toàn không hiện ra đâu cả nên khi "tới giờ mà
        // không gửi" thì không có cách nào biết vì sao. Mỗi lịch chỉ gửi 1 lần/ngày (chốt
        // last_sent_date), đây là lý do hay gặp nhất.
        $today  = ar_today();
        $nowSec = time();
        list($start, $end) = ar_window_bounds($cfg);
        if ((string) ($cfg['last_sent_date'] ?? '') === $today) {
            $cfg['run_state'] = 'sent';
            $at = (string) ($cfg['last_sent_at'] ?? '');
            $cfg['run_text']  = 'Đã gửi hôm nay' . ($at ? ' lúc ' . substr($at, 11, 5) : '') . ' — mai mới gửi tiếp';
        } elseif (!empty($cfg['is_paused'])) {
            $cfg['run_state'] = 'paused';
            $cfg['run_text']  = 'Đang tạm ngưng nên bỏ qua hôm nay';
        } elseif ($st !== 'accepted') {
            $cfg['run_state'] = 'not_accepted';
            $cfg['run_text']  = 'Người uỷ quyền chưa bấm "Nhận" nên lịch chưa chạy';
        } elseif ($nowSec < $start) {
            $cfg['run_state'] = 'waiting';
            $cfg['run_text']  = 'Chưa tới giờ (còn ' . max(1, (int) ceil(($start - $nowSec) / 60)) . ' phút)';
        } elseif ($nowSec <= $end) {
            $cfg['run_state'] = 'window';
            $cfg['run_text']  = 'Đang trong cửa sổ chờ — cần người uỷ quyền MỞ APP để gửi (còn '
                              . max(1, (int) ceil(($end - $nowSec) / 60)) . ' phút)';
        } else {
            $cfg['run_state'] = 'missed';
            $cfg['run_text']  = 'Đã lỡ hôm nay (quá cửa sổ chờ ' . (int) $cfg['window_minutes'] . ' phút)';
        }
        return $cfg;
    }

    /* ============================================================
     *  GUARD — dùng trong permission_guard()
     * ============================================================ */

    /** True nếu $uid là người được uỷ quyền ĐANG hiệu lực của cấu hình $cfgId. */
    function ar_is_active_delegate($uid, $cfgId)
    {
        ar_ensure_tables();
        $uid = (int) $uid; $cfgId = (int) $cfgId;
        if ($uid <= 0 || $cfgId <= 0) return false;
        return db_num_rows(
            "SELECT 1 FROM report_auto_send_configs
             WHERE id = {$cfgId} AND delegate_user_id = {$uid}
               AND delegation_status = 'accepted' AND is_paused = 0 AND is_active = 1
             LIMIT 1"
        ) > 0;
    }

    /* ============================================================
     *  GHI CẤU HÌNH
     * ============================================================ */

    /**
     * Tạo mới / cập nhật 1 cấu hình. Trả:
     *   ['ok'=>bool, 'error'=>string, 'id'=>int, 'is_new'=>bool,
     *    'delegate_changed'=>bool, 'old_delegate_id'=>int, 'delegate_id'=>int]
     */
    function ar_save($in, $created_by = 0)
    {
        ar_ensure_tables();
        $id       = (int) ($in['id'] ?? 0);
        $title    = trim((string) ($in['title'] ?? ''));
        $delegate = (int) ($in['delegate_user_id'] ?? 0);
        $rtype    = ($in['recipient_type'] ?? 'users') === 'group' ? 'group' : 'users';
        $conv     = (int) ($in['recipient_conversation_id'] ?? 0);
        $time     = ar_normalize_time($in['send_time'] ?? '');
        $window   = (int) ($in['window_minutes'] ?? 30);
        $settle   = (int) ($in['settle_ms'] ?? 4000);
        $caption  = trim((string) ($in['caption'] ?? ''));
        $viewKey  = trim((string) ($in['view_key'] ?? 'daily_dashboard')) ?: 'daily_dashboard';

        // recipient_user_ids: chấp nhận mảng hoặc JSON string.
        $rawIds = $in['recipient_user_ids'] ?? [];
        if (is_string($rawIds)) { $dec = json_decode($rawIds, true); $rawIds = is_array($dec) ? $dec : []; }
        $userIds = [];
        foreach ((array) $rawIds as $v) { $v = (int) $v; if ($v > 0) $userIds[$v] = $v; }
        $userIds = array_values($userIds);

        // ---- Validate ----
        if ($delegate <= 0)              return ['ok' => false, 'error' => 'Chưa chọn tài khoản được uỷ quyền.'];
        if ($time === '')                return ['ok' => false, 'error' => 'Giờ gửi không hợp lệ.'];
        if ($window < 10)  $window = 10; if ($window > 720) $window = 720;
        if ($settle < 1500) $settle = 1500; if ($settle > 20000) $settle = 20000;
        if ($rtype === 'group') {
            if ($conv <= 0)                          return ['ok' => false, 'error' => 'Chưa chọn nhóm nhận.'];
            if (!ar_group_ok_for_user($conv, $delegate))
                return ['ok' => false, 'error' => 'Người được uỷ quyền phải là thành viên của nhóm nhận.'];
            $userIds = [];
        } else {
            if (!$userIds)                           return ['ok' => false, 'error' => 'Chưa chọn người nhận.'];
            $conv = 0;
        }
        if ($title === '') $title = 'Báo cáo sản xuất ' . $time;

        $now = ar_now();
        $data = [
            'view_key'                  => $viewKey,
            'title'                     => $title,
            'delegate_user_id'          => $delegate,
            'recipient_type'            => $rtype,
            'recipient_user_ids'        => $rtype === 'users' ? json_encode(array_values($userIds)) : null,
            'recipient_conversation_id' => $conv,
            'send_time'                 => $time,
            'window_minutes'            => $window,
            'settle_ms'                 => $settle,
            'caption'                   => $caption !== '' ? $caption : null,
            'updated_at'                => $now,
        ];

        if ($id <= 0) {
            // Tạo mới → chờ xác nhận.
            $data['delegation_status'] = 'pending';
            $data['delegated_at']      = $now;
            $data['responded_at']      = null;
            $data['is_paused']         = 0;
            $data['created_by']        = (int) $created_by;
            $data['is_active']         = 1;
            $data['created_at']        = $now;
            $newId = (int) db_insert('report_auto_send_configs', $data);
            return ['ok' => true, 'id' => $newId, 'is_new' => true,
                    'delegate_changed' => true, 'old_delegate_id' => 0, 'delegate_id' => $delegate];
        }

        $old = ar_config($id);
        if (!$old) return ['ok' => false, 'error' => 'Không tìm thấy cấu hình.'];
        $oldDelegate = (int) $old['delegate_user_id'];
        $delegateChanged = ($oldDelegate !== $delegate);
        if ($delegateChanged) {
            // Đổi người uỷ quyền → phải xác nhận lại.
            $data['delegation_status'] = 'pending';
            $data['delegated_at']      = $now;
            $data['responded_at']      = null;
        }
        db_update('report_auto_send_configs', $data, 'id = ' . $id);
        return ['ok' => true, 'id' => $id, 'is_new' => false,
                'delegate_changed' => $delegateChanged, 'old_delegate_id' => $oldDelegate, 'delegate_id' => $delegate];
    }

    /** Xoá mềm 1 cấu hình. */
    function ar_soft_delete($id)
    {
        ar_ensure_tables();
        $id = (int) $id;
        if ($id <= 0) return false;
        db_update('report_auto_send_configs', ['is_active' => 0, 'updated_at' => ar_now()], 'id = ' . $id);
        return true;
    }

    /** Tạm ngưng / bật lại. $actor_id = người thao tác. */
    function ar_set_pause($id, $paused, $actor_id = 0)
    {
        ar_ensure_tables();
        $id = (int) $id;
        if ($id <= 0) return false;
        db_update('report_auto_send_configs', [
            'is_paused'  => $paused ? 1 : 0,
            'paused_by'  => $paused ? (int) $actor_id : 0,
            'paused_at'  => $paused ? ar_now() : null,
            'updated_at' => ar_now(),
        ], 'id = ' . $id);
        return true;
    }

    /** Người được uỷ quyền phản hồi lời mời: accept=true -> accepted, false -> declined. */
    function ar_respond($cfgId, $uid, $accept)
    {
        ar_ensure_tables();
        $cfgId = (int) $cfgId; $uid = (int) $uid;
        $cfg = ar_config($cfgId);
        if (!$cfg || (int) $cfg['delegate_user_id'] !== $uid) return false;
        db_update('report_auto_send_configs', [
            'delegation_status' => $accept ? 'accepted' : 'declined',
            'responded_at'      => ar_now(),
            'updated_at'        => ar_now(),
        ], 'id = ' . $cfgId);
        return true;
    }

    /* ============================================================
     *  ĐẾN GIỜ (client poll) + QUÉT LỠ
     * ============================================================ */

    /** Cửa sổ [start,end] theo giây (epoch, hôm nay) của 1 cấu hình. */
    function ar_window_bounds($cfg)
    {
        $today = ar_today();
        $start = strtotime($today . ' ' . (string) $cfg['send_time']);
        $end   = $start + max(1, (int) $cfg['window_minutes']) * 60;
        return [$start, $end];
    }

    /** Các lịch "đến giờ" mà $uid cần thực hiện ngay (accepted, chưa tạm ngưng, trong cửa sổ, chưa gửi hôm nay). */
    function ar_due_for_user($uid)
    {
        ar_ensure_tables();
        $uid = (int) $uid;
        if ($uid <= 0) return [];
        $today = ar_today();
        $nowSec = time();
        $rows = db_fetch_array(
            "SELECT * FROM report_auto_send_configs
             WHERE is_active = 1 AND is_paused = 0 AND delegation_status = 'accepted'
               AND delegate_user_id = {$uid}"
        ) ?: [];
        $out = [];
        foreach ($rows as $cfg) {
            if ((string) ($cfg['last_sent_date'] ?? '') === $today) continue;
            list($start, $end) = ar_window_bounds($cfg);
            if ($nowSec >= $start && $nowSec <= $end) {
                $out[] = [
                    'config_id' => (int) $cfg['id'],
                    'title'     => (string) $cfg['title'],
                    'settle_ms' => (int) $cfg['settle_ms'],
                    'date'      => $today,
                ];
            }
        }
        return $out;
    }

    /**
     * Quét các lịch đã QUÁ cửa sổ mà chưa gửi & chưa báo lỡ hôm nay -> đẩy chuông admin (1 lần/ngày).
     * Chạy cơ hội trong endpoint poll (bất kỳ ai online). Không có cron -> nếu tuyệt đối không ai
     * mở app thì không chạy tới khi có người mở (nhất quán chat_apply_retention_if_due).
     */
    function ar_run_missed_sweep()
    {
        ar_ensure_tables();
        $today  = ar_today();
        $nowSec = time();
        $rows = db_fetch_array(
            "SELECT * FROM report_auto_send_configs
             WHERE is_active = 1 AND is_paused = 0 AND delegation_status = 'accepted'"
        ) ?: [];
        foreach ($rows as $cfg) {
            if ((string) ($cfg['last_sent_date'] ?? '') === $today) continue;
            if ((string) ($cfg['missed_notified_date'] ?? '') === $today) continue;
            list(, $end) = ar_window_bounds($cfg);
            if ($nowSec <= $end) continue; // chưa hết cửa sổ → chưa kết luận lỡ

            $id = (int) $cfg['id'];
            db_update('report_auto_send_configs', ['missed_notified_date' => $today], 'id = ' . $id);
            ar_log($id, 'missed', [
                'delegate_user_id' => (int) $cfg['delegate_user_id'],
                'note'             => 'Người được uỷ quyền không online trong khung giờ.',
            ]);
            $who = ar_user_name($cfg['delegate_user_id']);
            notify_admins(
                'Báo cáo tự động chưa được gửi',
                'Lịch "' . $cfg['title'] . '" (' . substr((string) $cfg['send_time'], 0, 5) . ') hôm nay chưa gửi được — ' . $who . ' không online.',
                'arcfg:' . $id,
                'autoreport'
            );
        }
    }

    /* ============================================================
     *  GỬI VÀO CHAT
     * ============================================================ */

    /** Đánh dấu đã gửi hôm nay. */
    function ar_mark_sent($cfgId)
    {
        ar_ensure_tables();
        $cfgId = (int) $cfgId;
        db_update('report_auto_send_configs', [
            'last_sent_date' => ar_today(),
            'last_sent_at'   => ar_now(),
        ], 'id = ' . $cfgId);
    }

    /** Chặn quét-lỡ báo trùng khi đã xử lý (thất bại) hôm nay. */
    function ar_mark_missed_notified($cfgId)
    {
        ar_ensure_tables();
        db_update('report_auto_send_configs', ['missed_notified_date' => ar_today()], 'id = ' . (int) $cfgId);
    }

    /** Ghi nhật ký 1 kết quả/lịch/ngày (INSERT IGNORE theo uq_config_date). */
    function ar_log($cfgId, $status, $extra = [])
    {
        ar_ensure_tables();
        $cfgId = (int) $cfgId;
        $status = in_array($status, ['sent', 'missed', 'failed'], true) ? $status : 'failed';
        $cols = [
            'config_id'         => $cfgId,
            'send_date'         => ar_today(),
            'status'            => $status,
            'delegate_user_id'  => (int) ($extra['delegate_user_id'] ?? 0),
            'recipient_summary' => isset($extra['recipient_summary']) ? mb_substr((string) $extra['recipient_summary'], 0, 490, 'UTF-8') : null,
            'message_ids'       => isset($extra['message_ids']) ? json_encode($extra['message_ids']) : null,
            'attachment_file'   => $extra['attachment_file'] ?? null,
            'note'              => isset($extra['note']) ? mb_substr((string) $extra['note'], 0, 490, 'UTF-8') : null,
            'created_at'        => ar_now(),
        ];
        // Không dùng db_insert (sẽ die khi trùng UNIQUE) — build INSERT IGNORE thủ công.
        $keys = []; $vals = [];
        foreach ($cols as $k => $v) {
            $keys[] = "`$k`";
            $vals[] = ($v === null) ? 'NULL' : "'" . escape_string((string) $v) . "'";
        }
        db_query("INSERT IGNORE INTO report_auto_send_log (" . implode(',', $keys) . ") VALUES (" . implode(',', $vals) . ")");
    }

    /**
     * Gửi ảnh báo cáo vào chat với TƯ CÁCH người được uỷ quyền (sender = delegate).
     * $fileMeta = kết quả chat_store_upload() (đã lưu 1 lần trên đĩa, dùng lại cho mọi người nhận).
     * Trả ['ok'=>bool, 'error'=>string, 'message_ids'=>[], 'recipient_summary'=>string].
     */
    function ar_send_to_recipients($cfg, $fileMeta, $caption = '')
    {
        require_once __DIR__ . '/chat.php';
        chat_ensure_tables();

        $sender = (int) $cfg['delegate_user_id'];
        if ($sender <= 0) return ['ok' => false, 'error' => 'Thiếu tài khoản gửi.'];
        if (empty($fileMeta) || empty($fileMeta['file_name'])) return ['ok' => false, 'error' => 'Thiếu ảnh báo cáo.'];

        $body = trim((string) $caption);
        if ($body === '') {
            $body = trim((string) ($cfg['caption'] ?? ''));
        }
        if ($body === '') {
            $body = '📊 ' . (string) $cfg['title'] . ' — ngày ' . date('d/m/Y');
        }

        $att = [
            'file_name'     => (string) $fileMeta['file_name'],
            'original_name' => (string) ($fileMeta['original_name'] ?? 'bao-cao.png'),
            'mime'          => (string) ($fileMeta['mime'] ?? 'image/png'),
            'size'          => (int) ($fileMeta['size'] ?? 0),
            'is_image'      => (int) ($fileMeta['is_image'] ?? 1) ? 1 : 0,
        ];

        $mids = [];
        if (($cfg['recipient_type'] ?? 'users') === 'group') {
            $cid = (int) $cfg['recipient_conversation_id'];
            if ($cid <= 0 || !chat_is_participant($cid, $sender)) {
                return ['ok' => false, 'error' => 'Người gửi không còn trong nhóm nhận.'];
            }
            $mid = ar_send_one($cid, $sender, $body, $att);
            if ($mid > 0) $mids[] = $mid;
            $g = db_fetch_row("SELECT name FROM chat_conversations WHERE id = {$cid} LIMIT 1");
            $summary = 'Nhóm: ' . ($g ? (string) $g['name'] : ('#' . $cid));
        } else {
            $ids = ar_recipient_user_ids($cfg);
            $sent = 0;
            foreach ($ids as $uid) {
                $uid = (int) $uid;
                if ($uid <= 0 || $uid === $sender) continue;
                $cid = chat_get_or_create_direct($sender, $uid);
                if ($cid <= 0) continue;
                $mid = ar_send_one($cid, $sender, $body, $att);
                if ($mid > 0) { $mids[] = $mid; $sent++; }
            }
            if ($sent === 0) return ['ok' => false, 'error' => 'Không gửi được cho người nhận nào.'];
            $summary = $sent . ' người nhận';
        }

        return ['ok' => true, 'message_ids' => $mids, 'recipient_summary' => $summary];
    }

    /** Chèn 1 tin ảnh + attachment (dùng chung 1 file trên đĩa). Trả message_id. */
    function ar_send_one($cid, $sender, $body, $att)
    {
        $mid = (int) chat_insert_message($cid, $sender, $body, 'image');
        if ($mid <= 0) return 0;
        $row = $att; $row['message_id'] = $mid;
        db_insert('chat_attachments', $row);
        chat_mark_read($cid, $sender, $mid); // bản của người gửi coi như đã đọc
        return $mid;
    }
}
