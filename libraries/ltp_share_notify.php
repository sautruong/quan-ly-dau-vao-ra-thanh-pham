<?php
/**
 * ltp_share_notify — CHUÔNG CHO "CÔNG BỐ KẾ HOẠCH SẢN XUẤT HẰNG NGÀY".
 *
 * User chốt 2026-11-09 (thảo luận thiết kế):
 *   1. Sau 17:00 mỗi ngày (bỏ chủ nhật) mà CHƯA công bố ("share") kế hoạch NGÀY MAI
 *      → đẩy chuông nhắc admin vào công bố.
 *   2. Công bố xong → đẩy chuông cho các user CÓ QUYỀN xem view "Kế hoạch sản xuất hằng ngày"
 *      (production_staff/plan_for_staff) để họ mở xem (view này user thường chỉ xem, không sửa).
 *   3. Admin bổ sung/đổi kế hoạch rồi công bố lại → đẩy chuông lại "kế hoạch đã thay đổi".
 *
 * ==============================================================================
 * "SHARE" LÀ GÌ — và vì sao gộp với auto-export 16:50
 * ==============================================================================
 * "Công bố/Share" = đưa kế hoạch của một ngày thành "Kế hoạch sản xuất hằng ngày" ĐANG CHẠY
 * (ghi vào production_plans/additional_tasks). Có đúng HAI đường tới trạng thái đó, cả hai đều
 * móc vào ltpn_mark_and_notify():
 *    · Tay : nút "XUẤT KH" trên card long_term_production_plan → save_planAction().
 *    · Tự động : lịch 16:50 (libraries/plan_auto_export.php) → plan_auto_export_runAction().
 * Nhờ gộp ở một điểm móc, auto 16:50 chạy xong CŨNG tự bắn chuông cho user; lời nhắc 17:00 chỉ
 * nổ khi tới giờ mà kế hoạch ngày mai VẪN chưa được công bố bằng bất kỳ đường nào.
 *
 * ==============================================================================
 * VÌ SAO KHÔNG CẦN POLLER RIÊNG (giống production_reminder / plan_auto_export)
 * ==============================================================================
 * Lời nhắc 17:00 thuần server (đọc DB + tạo thông báo), nên ltpn_reminder_sweep() được gọi ĐI NHỜ
 * trong endpoint poll toàn cục report/auto_report_due_check — endpoint đó đã được poller
 * (public/js/shared/auto_report_poller.js) gọi đều cho MỌI user đang đăng nhập. Hệ quả: không ai
 * online thì lời nhắc trượt sang lúc có người mở app — chấp nhận được, vì nhắc là để nhắc người
 * đang làm việc.
 *
 * Prefix ltpn_* (KHÔNG dùng ltp_* vì production_staffModel.php đã giữ prefix đó). Bảng tự tạo.
 */

// Mọi so sánh giờ/ngày theo giờ VN (giống notifications.php / plan_auto_export.php).
if (date_default_timezone_get() !== 'Asia/Ho_Chi_Minh') {
    date_default_timezone_set('Asia/Ho_Chi_Minh');
}

require_once __DIR__ . '/notifications.php';
// permission_user_ids_for_view() nằm ở helper — nạp phòng khi lib được require ngoài luồng guard.
if (!function_exists('permission_user_ids_for_view')) {
    $__perm = __DIR__ . '/../helper/permission.php';
    if (is_file($__perm)) require_once $__perm;
}

if (!function_exists('ltpn_ensure_tables')) {

    /** View "Kế hoạch sản xuất hằng ngày" — nguồn danh sách người nhận chuông (admin + được gán). */
    if (!defined('LTPN_VIEW_MODULE'))     define('LTPN_VIEW_MODULE', 'production_staff');
    if (!defined('LTPN_VIEW_CONTROLLER')) define('LTPN_VIEW_CONTROLLER', 'production_staff');
    if (!defined('LTPN_VIEW_ACTION'))     define('LTPN_VIEW_ACTION', 'plan_for_staff');

    /* ============================================================
     *  SCHEMA
     * ============================================================ */

    function ltpn_ensure_tables()
    {
        static $done = false;
        if ($done) return;
        $done = true;

        /* Sự thật về "đã công bố kế hoạch cho ngày nào" — 1 dòng / plan_date.
           Độc lập với plan_auto_export (lịch auto chỉ tồn tại khi admin bật), vì công bố tay phải
           ghi nhận được kể cả khi chưa cấu hình lịch nào. */
        db_query("CREATE TABLE IF NOT EXISTS ltp_share_state (
            plan_date          DATE NOT NULL,
            shared_at          DATETIME DEFAULT NULL,
            shared_by          INT(11) NOT NULL DEFAULT 0,
            last_change_at     DATETIME DEFAULT NULL,
            notified_users_at  DATETIME DEFAULT NULL,
            share_count        INT(11) NOT NULL DEFAULT 0,
            PRIMARY KEY (plan_date)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci");

        /* Cấu hình lời nhắc 17:00 — CHỈ MỘT dòng (lấy dòng đầu còn sống). Chưa dựng màn UI riêng:
           user mô tả đúng "sau 17:00, bỏ chủ nhật" nên mặc định là đủ; để bảng cho dễ mở rộng sau. */
        db_query("CREATE TABLE IF NOT EXISTS ltp_share_reminder_configs (
            id                 INT(11) NOT NULL AUTO_INCREMENT,
            is_active          TINYINT(1) NOT NULL DEFAULT 1,
            remind_time        TIME NOT NULL DEFAULT '17:00:00',
            skip_sunday        TINYINT(1) NOT NULL DEFAULT 1,
            last_reminded_date DATE DEFAULT NULL,
            created_at         DATETIME NOT NULL,
            updated_at         DATETIME NOT NULL,
            PRIMARY KEY (id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci");
    }

    /* ============================================================
     *  TIỆN ÍCH
     * ============================================================ */

    function ltpn_today()    { return date('Y-m-d'); }
    function ltpn_now()      { return date('Y-m-d H:i:s'); }
    function ltpn_tomorrow() { return date('Y-m-d', strtotime('+1 day')); }

    /** Chuẩn hoá chuỗi ngày về 'Y-m-d'; rỗng/sai → chuỗi rỗng. */
    function ltpn_norm_date($d)
    {
        $d = trim((string) $d);
        if ($d === '') return '';
        $t = strtotime($d);
        return $t ? date('Y-m-d', $t) : '';
    }

    /** Cấu hình lời nhắc (tạo dòng mặc định nếu chưa có). */
    function ltpn_config()
    {
        ltpn_ensure_tables();
        $row = db_fetch_row("SELECT * FROM ltp_share_reminder_configs ORDER BY id ASC LIMIT 1");
        if ($row) return $row;
        $now = ltpn_now();
        db_insert('ltp_share_reminder_configs', [
            'is_active'   => 1,
            'remind_time' => '17:00:00',
            'skip_sunday' => 1,
            'created_at'  => $now,
            'updated_at'  => $now,
        ]);
        return db_fetch_row("SELECT * FROM ltp_share_reminder_configs ORDER BY id ASC LIMIT 1");
    }

    /** Kế hoạch cho ngày $planDate đã từng được công bố chưa (shared_at khác NULL). */
    function ltpn_is_shared($planDate)
    {
        $d = ltpn_norm_date($planDate);
        if ($d === '') return false;
        ltpn_ensure_tables();
        $row = db_fetch_row(
            "SELECT 1 FROM ltp_share_state
              WHERE plan_date = '" . escape_string($d) . "' AND shared_at IS NOT NULL LIMIT 1"
        );
        return (bool) $row;
    }

    /* ============================================================
     *  CÔNG BỐ + ĐẨY CHUÔNG CHO USER (YC2 + YC3)
     * ============================================================ */

    /**
     * Điểm móc DUY NHẤT cho "kế hoạch vừa được công bố / cập nhật".
     * Ghi trạng thái cho $planDate rồi đẩy chuông cho user có quyền xem view KH hằng ngày.
     *
     * $sharedBy : user gây ra (loại khỏi danh sách nhận — không tự báo cho mình).
     * Trả số thông báo đã tạo.
     *
     * Chống rung liên hồi: nếu vừa báo trong vòng LTPN_THROTTLE giây thì cập nhật trạng thái
     * nhưng KHÔNG bắn chuông lần nữa (admin sửa 5 dòng liên tục = 1 chuông, không phải 5).
     */
    function ltpn_mark_and_notify($planDate, $sharedBy = 0)
    {
        $d = ltpn_norm_date($planDate);
        if ($d === '') $d = ltpn_today();
        ltpn_ensure_tables();

        $now  = ltpn_now();
        $prev = db_fetch_row("SELECT * FROM ltp_share_state WHERE plan_date = '" . escape_string($d) . "' LIMIT 1");

        $isUpdate = $prev && !empty($prev['shared_at']);

        // Có nên bắn chuông lần này không (throttle theo notified_users_at).
        $throttle = defined('LTPN_THROTTLE') ? (int) LTPN_THROTTLE : 60;
        $notify   = true;
        if ($prev && !empty($prev['notified_users_at'])
            && (time() - strtotime($prev['notified_users_at'])) < $throttle) {
            $notify = false;
        }

        // Ghi/﻿cập nhật trạng thái.
        if (!$prev) {
            db_insert('ltp_share_state', [
                'plan_date'         => $d,
                'shared_at'         => $now,
                'shared_by'         => (int) $sharedBy,
                'last_change_at'    => $now,
                'notified_users_at' => $notify ? $now : null,
                'share_count'       => 1,
            ]);
        } else {
            $upd = [
                'last_change_at' => $now,
                'share_count'    => (int) $prev['share_count'] + 1,
            ];
            if (empty($prev['shared_at'])) { $upd['shared_at'] = $now; $upd['shared_by'] = (int) $sharedBy; }
            if ($notify) $upd['notified_users_at'] = $now;
            db_update('ltp_share_state', $upd, "plan_date = '" . escape_string($d) . "'");
        }

        if (!$notify) return 0;

        // Người nhận = admin + user được gán view KH hằng ngày, trừ người vừa công bố.
        if (!function_exists('permission_user_ids_for_view')) return 0;
        $recipients = permission_user_ids_for_view(LTPN_VIEW_MODULE, LTPN_VIEW_CONTROLLER, LTPN_VIEW_ACTION);
        $sharedBy   = (int) $sharedBy;

        $dmy = date('d/m/Y', strtotime($d));
        if ($isUpdate) {
            $title = 'Kế hoạch sản xuất vừa được cập nhật';
            $msg   = 'Kế hoạch ngày ' . $dmy . ' vừa được thay đổi. Mở để xem lại.';
            $type  = 'plan_updated';
        } else {
            $title = 'Kế hoạch sản xuất đã được công bố';
            $msg   = 'Kế hoạch ngày ' . $dmy . ' đã sẵn sàng. Mở để xem.';
            $type  = 'plan_shared';
        }
        $link = 'production_staff/plan_for_staff';

        $n = 0;
        foreach ($recipients as $uid) {
            $uid = (int) $uid;
            if ($uid <= 0 || $uid === $sharedBy) continue;
            if (notify_create($uid, $title, $msg, $link, $type, $sharedBy) > 0) $n++;
        }
        return $n;
    }

    /* ============================================================
     *  LỜI NHẮC 17:00 "CHƯA CÔNG BỐ KH NGÀY MAI" (YC1)
     * ============================================================ */

    /**
     * Gọi đi nhờ trong endpoint poll toàn cục. Thoát sớm ở các điều kiện rẻ; chỉ chạm bảng công bố
     * khi thật sự đã tới giờ và chưa nhắc hôm nay.
     */
    function ltpn_reminder_sweep()
    {
        $cfg = ltpn_config();
        if (!$cfg || empty($cfg['is_active'])) return;
        if (!empty($cfg['skip_sunday']) && (int) date('w') === 0) return;          // 0 = chủ nhật
        if ((string) $cfg['last_reminded_date'] === ltpn_today()) return;          // mỗi ngày đúng 1 lần
        if (time() < strtotime(ltpn_today() . ' ' . $cfg['remind_time'])) return;  // chưa tới giờ

        // Đã công bố KH ngày mai (bằng tay hoặc auto 16:50) → không nhắc, và chốt luôn để các nhịp
        // poll sau trong buổi tối không phải kiểm lại.
        if (ltpn_is_shared(ltpn_tomorrow())) {
            db_update('ltp_share_reminder_configs',
                ['last_reminded_date' => ltpn_today(), 'updated_at' => ltpn_now()],
                'id = ' . (int) $cfg['id']);
            return;
        }

        notify_admins(
            'Chưa công bố kế hoạch sản xuất ngày mai',
            'Đã qua ' . date('H:i', strtotime($cfg['remind_time'])) . ' ngày ' . date('d/m/Y')
            . ' nhưng kế hoạch sản xuất ngày mai (' . date('d/m/Y', strtotime(ltpn_tomorrow()))
            . ') chưa được công bố. Vào board bấm "XUẤT KH" để công bố cho nhân viên.',
            'production_staff/long_term_production_plan',
            'general'
        );
        db_update('ltp_share_reminder_configs',
            ['last_reminded_date' => ltpn_today(), 'updated_at' => ltpn_now()],
            'id = ' . (int) $cfg['id']);
    }
}
