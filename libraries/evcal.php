<?php
/**
 * evcal — Lịch (calendar) cá nhân hóa cạnh "Việc cần làm" trên app-header.
 *
 * Mỗi user có sự kiện/lời nhắc RIÊNG (bảng calendar_events, KHÔNG chia sẻ, khác
 * hẳn todo_lists nhiều-người). 1 ngày có thể có NHIỀU sự kiện. Mỗi sự kiện có
 * thể kèm giờ nhắc (event_time) — tới giờ đó sẽ đẩy 1 thông báo lên chuông
 * (xem [[bell-notifications]]) đúng 1 lần (cột notified chặn lặp).
 *
 * Nội dung (content) là dữ liệu cá nhân hóa → mã hóa như [[personal-data-encryption]].
 *
 * Không autoload (giống todos.php/notifications.php) → require_once nơi dùng.
 */

date_default_timezone_set('Asia/Ho_Chi_Minh');

require_once __DIR__ . '/crypto.php';

if (!function_exists('evcal_ensure_tables')) {

    function evcal_ensure_tables()
    {
        static $done = false;
        if ($done) return;
        $done = true;

        db_query("CREATE TABLE IF NOT EXISTS calendar_events (
            id          INT(11) NOT NULL AUTO_INCREMENT,
            user_id     INT(11) NOT NULL,
            event_date  DATE NOT NULL,
            event_time  TIME DEFAULT NULL,
            content     TEXT NOT NULL,
            notified    TINYINT(1) NOT NULL DEFAULT 0,
            created_at  DATETIME NOT NULL,
            updated_at  DATETIME NOT NULL,
            PRIMARY KEY (id),
            KEY idx_user_date (user_id, event_date)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci");

        // Lặp lại (view phóng to): recur_rule = cấu hình JSON lưu ở sự kiện GỐC;
        // recur_parent_id = trỏ về sự kiện gốc trên các dòng được TỰ SINH sẵn.
        if (!db_fetch_row("SHOW COLUMNS FROM calendar_events LIKE 'recur_rule'")) {
            db_query("ALTER TABLE calendar_events ADD recur_rule TEXT NULL AFTER content");
        }
        if (!db_fetch_row("SHOW COLUMNS FROM calendar_events LIKE 'recur_parent_id'")) {
            db_query("ALTER TABLE calendar_events ADD recur_parent_id INT NULL AFTER recur_rule");
        }
        // Đánh dấu đã thực hiện (checkbox tròn ở app-cal-dropdown) — không tính vào badge nữa.
        if (!db_fetch_row("SHOW COLUMNS FROM calendar_events LIKE 'is_done'")) {
            db_query("ALTER TABLE calendar_events ADD is_done TINYINT(1) NOT NULL DEFAULT 0 AFTER notified");
        }
    }

    /** true nếu $s khớp định dạng ngày Y-m-d hợp lệ. */
    function evcal_valid_date($s)
    {
        $s = (string) $s;
        $d = DateTime::createFromFormat('Y-m-d', $s);
        return $d && $d->format('Y-m-d') === $s;
    }

    /** true nếu $s rỗng (không giờ) hoặc khớp H:i / H:i:s hợp lệ. */
    function evcal_valid_time($s)
    {
        $s = trim((string) $s);
        if ($s === '') return true;
        if (preg_match('/^([01]\d|2[0-3]):([0-5]\d)(:([0-5]\d))?$/', $s)) return true;
        return false;
    }

    function evcal_norm_time($s)
    {
        $s = trim((string) $s);
        if ($s === '') return null;
        if (preg_match('/^([01]\d|2[0-3]):([0-5]\d)$/', $s)) return $s . ':00';
        return $s;
    }

    /** Giải mã + chuẩn hoá 1 dòng sự kiện để trả JS. */
    function evcal_format_row($r)
    {
        $r['content']    = crypto_decrypt($r['content']);
        $r['event_time'] = $r['event_time'] !== null ? substr((string) $r['event_time'], 0, 5) : null;
        $r['id']         = (int) $r['id'];
        $r['has_recur']  = !empty($r['recur_rule']);
        $r['done']       = !empty($r['is_done']);
        unset($r['user_id'], $r['notified'], $r['recur_rule'], $r['recur_parent_id'], $r['is_done']);
        return $r;
    }

    /**
     * Sự kiện trong khoảng [$from, $to] (Y-m-d, bao gồm 2 đầu) của 1 user — dùng
     * cho lưới tháng (chấm báo có sự kiện) + view phóng to (hiện đầy đủ nội dung).
     */
    function evcal_range_events($user_id, $from, $to)
    {
        $uid = (int) $user_id;
        if ($uid <= 0 || !evcal_valid_date($from) || !evcal_valid_date($to)) return [];
        evcal_ensure_tables();
        $f = escape_string($from);
        $t = escape_string($to);
        $rows = db_fetch_array("SELECT * FROM calendar_events
                                WHERE user_id = $uid AND event_date BETWEEN '$f' AND '$t'
                                ORDER BY event_date ASC, (event_time IS NULL) ASC, event_time ASC, id ASC") ?: [];
        return array_map('evcal_format_row', $rows);
    }

    /** Sự kiện của đúng 1 ngày. */
    function evcal_day_events($user_id, $date)
    {
        $uid = (int) $user_id;
        if ($uid <= 0 || !evcal_valid_date($date)) return [];
        evcal_ensure_tables();
        $d = escape_string($date);
        $rows = db_fetch_array("SELECT * FROM calendar_events
                                WHERE user_id = $uid AND event_date = '$d'
                                ORDER BY (event_time IS NULL) ASC, event_time ASC, id ASC") ?: [];
        return array_map('evcal_format_row', $rows);
    }

    /** Số sự kiện CHƯA đánh dấu đã thực hiện của user trong hôm nay (badge trên nút lịch). */
    function evcal_today_count($user_id)
    {
        $uid = (int) $user_id;
        if ($uid <= 0) return 0;
        evcal_ensure_tables();
        $today = date('Y-m-d');
        $row = db_fetch_row("SELECT COUNT(*) AS c FROM calendar_events WHERE user_id = $uid AND event_date = '$today' AND is_done = 0");
        return $row ? (int) $row['c'] : 0;
    }

    /** Đánh dấu đã thực hiện / bỏ đánh dấu 1 sự kiện (checkbox tròn ở app-cal-dropdown). */
    function evcal_set_done($user_id, $id, $done)
    {
        $uid = (int) $user_id;
        $eid = (int) $id;
        if ($uid <= 0 || $eid <= 0) return false;
        evcal_ensure_tables();
        $own = db_fetch_row("SELECT id FROM calendar_events WHERE id = $eid AND user_id = $uid");
        if (!$own) return false;
        db_update('calendar_events', [
            'is_done'    => $done ? 1 : 0,
            'updated_at' => date('Y-m-d H:i:s'),
        ], "id = $eid AND user_id = $uid");
        return true;
    }

    /** Tạo 1 sự kiện/lời nhắc. Trả bản ghi vừa tạo (đã giải mã) hoặc null. */
    function evcal_create($user_id, $date, $time, $content)
    {
        $uid = (int) $user_id;
        $content = trim((string) $content);
        if ($uid <= 0 || $content === '' || !evcal_valid_date($date) || !evcal_valid_time($time)) return null;
        if (mb_strlen($content, 'UTF-8') > 500) $content = mb_substr($content, 0, 500, 'UTF-8');
        evcal_ensure_tables();
        $now = date('Y-m-d H:i:s');
        $id = (int) db_insert('calendar_events', [
            'user_id'    => $uid,
            'event_date' => $date,
            'event_time' => evcal_norm_time($time),
            'content'    => crypto_encrypt($content),
            'notified'   => 0,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        if ($id <= 0) return null;
        $row = db_fetch_row("SELECT * FROM calendar_events WHERE id = $id");
        return $row ? evcal_format_row($row) : null;
    }

    /**
     * Sửa nội dung và/hoặc giờ nhắc của 1 sự kiện (chỉ chủ sự kiện).
     * $time: null = không đổi giờ hiện tại; '' = xoá giờ (chuyển thành sự kiện cả ngày);
     * 'HH:MM' = đặt giờ mới. Đổi giờ (hoặc ngày qua evcal_move) reset notified = 0.
     */
    function evcal_update($user_id, $id, $content = null, $time = null)
    {
        $uid = (int) $user_id;
        $eid = (int) $id;
        if ($uid <= 0 || $eid <= 0) return false;
        evcal_ensure_tables();
        $own = db_fetch_row("SELECT id, event_time FROM calendar_events WHERE id = $eid AND user_id = $uid");
        if (!$own) return false;
        $data = ['updated_at' => date('Y-m-d H:i:s')];
        if ($content !== null) {
            $content = trim((string) $content);
            if ($content === '') return false;
            if (mb_strlen($content, 'UTF-8') > 500) $content = mb_substr($content, 0, 500, 'UTF-8');
            $data['content'] = crypto_encrypt($content);
        }
        if ($time !== null) {
            if (!evcal_valid_time($time)) return false;
            $newTime = evcal_norm_time($time);
            if ($newTime !== $own['event_time']) {
                $data['event_time'] = $newTime;
                $data['notified']   = 0;
            }
        }
        db_update('calendar_events', $data, "id = $eid AND user_id = $uid");
        return true;
    }

    /** Chuyển 1 sự kiện sang ngày khác (kéo-thả ở view phóng to). Reset notified. */
    function evcal_move($user_id, $id, $new_date)
    {
        $uid = (int) $user_id;
        $eid = (int) $id;
        if ($uid <= 0 || $eid <= 0 || !evcal_valid_date($new_date)) return false;
        evcal_ensure_tables();
        $own = db_fetch_row("SELECT id FROM calendar_events WHERE id = $eid AND user_id = $uid");
        if (!$own) return false;
        db_update('calendar_events', [
            'event_date' => $new_date,
            'notified'   => 0,
            'updated_at' => date('Y-m-d H:i:s'),
        ], "id = $eid AND user_id = $uid");
        return true;
    }

    /** Xóa 1 sự kiện (chỉ chủ). */
    function evcal_delete($user_id, $id)
    {
        $uid = (int) $user_id;
        $eid = (int) $id;
        if ($uid <= 0 || $eid <= 0) return false;
        evcal_ensure_tables();
        db_delete('calendar_events', "id = $eid AND user_id = $uid");
        return true;
    }

    /**
     * Quét các lời nhắc tới giờ mà chưa báo (notified=0, có event_time, thời điểm
     * ngày+giờ <= hiện tại) của 1 user → đẩy chuông (notify_create) rồi đánh dấu đã báo.
     * Gọi định kỳ từ JS (poll), độc lập với việc dropdown lịch có đang mở hay không.
     */
    function evcal_check_due_reminders($user_id)
    {
        $uid = (int) $user_id;
        if ($uid <= 0 || !function_exists('notify_create')) return 0;
        evcal_ensure_tables();
        $due = db_fetch_array("SELECT * FROM calendar_events
                               WHERE user_id = $uid AND notified = 0 AND is_done = 0 AND event_time IS NOT NULL
                                 AND TIMESTAMP(event_date, event_time) <= NOW()") ?: [];
        foreach ($due as $r) {
            $content = crypto_decrypt($r['content']);
            // link = 'evcal:<id>' (không phải URL thật) để chuông biết sự kiện gốc, phục vụ nút "Nhắc lại".
            notify_create($uid, 'Nhắc lịch: ' . mb_substr($content, 0, 60, 'UTF-8'), $content, 'evcal:' . (int) $r['id'], 'calendar_reminder');
            db_update('calendar_events', ['notified' => 1], 'id = ' . (int) $r['id']);
        }
        return count($due);
    }

    /**
     * "Nhắc lại" từ chuông: dịch lời nhắc sang ngày hiện tại + $days ngày, giữ nguyên giờ.
     * Tái dùng evcal_move() (đã reset notified = 0 để có thể báo lại đúng giờ ở ngày mới).
     */
    function evcal_snooze($user_id, $id, $days)
    {
        $days = (int) $days;
        if ($days <= 0) return false;
        $newDate = date('Y-m-d', strtotime('+' . $days . ' days'));
        return evcal_move($user_id, $id, $newDate);
    }

    /** "Nhắc lại" theo mốc ngắn (15 phút/1h/4h): dịch cả ngày LẪN giờ sang NOW()+phút,
     * khác evcal_move (chỉ đổi ngày, giữ nguyên giờ gốc) vì mốc ngắn cần đúng giờ hiện tại + N. */
    function evcal_snooze_minutes($user_id, $id, $minutes)
    {
        $uid = (int) $user_id;
        $eid = (int) $id;
        $minutes = (int) $minutes;
        if ($uid <= 0 || $eid <= 0 || $minutes <= 0) return false;
        evcal_ensure_tables();
        $own = db_fetch_row("SELECT id FROM calendar_events WHERE id = $eid AND user_id = $uid");
        if (!$own) return false;
        $ts = strtotime('+' . $minutes . ' minutes');
        db_update('calendar_events', [
            'event_date' => date('Y-m-d', $ts),
            'event_time' => date('H:i:s', $ts),
            'notified'   => 0,
            'updated_at' => date('Y-m-d H:i:s'),
        ], "id = $eid AND user_id = $uid");
        return true;
    }

    /* =====================================================================
     *  LẶP LẠI (view phóng to) — TỰ SINH SẴN các dòng sự kiện tương lai
     *  (không tính ảo). Mốc xa nhất = 1 năm kể từ ngày sự kiện gốc.
     * ===================================================================== */

    /** Ngày xa nhất được phép sinh sự kiện lặp (Y-m-d, gốc + 1 năm). */
    function evcal_recur_horizon($originDate)
    {
        $d = DateTime::createFromFormat('Y-m-d', $originDate);
        $d->modify('+1 year');
        return $d->format('Y-m-d');
    }

    /** Kiểm tra cấu hình lặp hợp lệ (freq + tham số đi kèm theo từng loại). */
    function evcal_valid_recur_rule($rule)
    {
        if (!is_array($rule)) return false;
        $freq = $rule['freq'] ?? '';
        if (!in_array($freq, ['daily', 'weekly', 'monthly', 'yearly'], true)) return false;
        if ((int) ($rule['interval'] ?? 1) < 1) return false;
        if ($freq === 'weekly' && empty($rule['weekdays'])) return false;
        if ($freq === 'monthly') {
            $d = (int) ($rule['month_day'] ?? 0);
            if ($d < 1 || $d > 31) return false;
        }
        if ($freq === 'yearly') {
            $m = (int) ($rule['year_month'] ?? 0);
            $d = (int) ($rule['year_day'] ?? 0);
            if ($m < 1 || $m > 12 || $d < 1 || $d > 31) return false;
        }
        return true;
    }

    /**
     * Tính danh sách ngày (Y-m-d) sẽ được sinh, KHÔNG gồm ngày gốc, chỉ các
     * ngày SAU ngày gốc và <= $horizonDate.
     */
    function evcal_compute_recur_dates($originDate, array $rule, $horizonDate)
    {
        $freq     = $rule['freq'];
        $interval = max(1, (int) ($rule['interval'] ?? 1));
        $origin   = DateTime::createFromFormat('Y-m-d', $originDate);
        $horizon  = DateTime::createFromFormat('Y-m-d', $horizonDate);
        $dates    = [];

        if ($freq === 'daily') {
            for ($k = 1; $k <= 1000; $k++) {
                $d = (clone $origin)->modify('+' . ($k * $interval) . ' days');
                if ($d > $horizon) break;
                $dates[] = $d->format('Y-m-d');
            }
        } elseif ($freq === 'weekly') {
            $weekdays = array_values(array_unique(array_map('intval', $rule['weekdays'] ?? [])));
            sort($weekdays);
            if (!$weekdays) return [];
            $originDow = (int) $origin->format('N'); // 1=T2 .. 7=CN
            $weekStart = (clone $origin)->modify('-' . ($originDow - 1) . ' days');
            for ($w = 0; $w <= 600; $w++) {
                $cycleStart = (clone $weekStart)->modify('+' . ($w * 7) . ' days');
                if ($cycleStart > $horizon) break;
                if ($w % $interval !== 0) continue;
                foreach ($weekdays as $dow) {
                    $d = (clone $cycleStart)->modify('+' . ($dow - 1) . ' days');
                    if ($d > $origin && $d <= $horizon) $dates[] = $d->format('Y-m-d');
                }
            }
        } elseif ($freq === 'monthly') {
            $day = max(1, min(31, (int) ($rule['month_day'] ?? $origin->format('j'))));
            for ($k = 0; $k <= 200; $k++) {
                $m   = (clone $origin)->modify('first day of this month')->modify('+' . ($k * $interval) . ' months');
                $dim = (int) $m->format('t');
                $d   = DateTime::createFromFormat('Y-m-d', $m->format('Y-m-') . sprintf('%02d', min($day, $dim)));
                if ($d > $horizon) break;
                if ($d > $origin) $dates[] = $d->format('Y-m-d');
            }
        } elseif ($freq === 'yearly') {
            $ym = max(1, min(12, (int) ($rule['year_month'] ?? $origin->format('n'))));
            $yd = max(1, min(31, (int) ($rule['year_day'] ?? $origin->format('j'))));
            for ($k = 0; $k <= 50; $k++) {
                $y   = (int) $origin->format('Y') + $k * $interval;
                $dim = (int) (DateTime::createFromFormat('Y-m-d', sprintf('%04d-%02d-01', $y, $ym)))->format('t');
                $d   = DateTime::createFromFormat('Y-m-d', sprintf('%04d-%02d-%02d', $y, $ym, min($yd, $dim)));
                if ($d > $horizon) break;
                if ($d > $origin) $dates[] = $d->format('Y-m-d');
            }
        }
        sort($dates);
        return array_values(array_unique($dates));
    }

    /** Cấu hình lặp hiện có của 1 sự kiện (null nếu chưa thiết lập / không phải chủ). */
    function evcal_get_recurrence($user_id, $id)
    {
        $uid = (int) $user_id;
        $eid = (int) $id;
        if ($uid <= 0 || $eid <= 0) return null;
        evcal_ensure_tables();
        $row = db_fetch_row("SELECT recur_rule FROM calendar_events WHERE id = $eid AND user_id = $uid");
        if (!$row || empty($row['recur_rule'])) return null;
        $rule = json_decode($row['recur_rule'], true);
        return is_array($rule) ? $rule : null;
    }

    /**
     * Thiết lập (hoặc xóa, $rule = null) lặp lại cho 1 sự kiện.
     * - Xóa hết các dòng đã tự sinh trước đó từ sự kiện này (cho phép sửa lại
     *   thiết lập mà không bị chồng trùng), rồi sinh lại theo $rule mới.
     * - Trả false nếu không hợp lệ / không phải chủ sự kiện; ngược lại trả
     *   ['created' => n] (n = số dòng vừa sinh).
     */
    function evcal_set_recurrence($user_id, $id, $rule)
    {
        $uid = (int) $user_id;
        $eid = (int) $id;
        if ($uid <= 0 || $eid <= 0) return false;
        evcal_ensure_tables();
        $own = db_fetch_row("SELECT * FROM calendar_events WHERE id = $eid AND user_id = $uid");
        if (!$own) return false;

        // Luôn dọn các dòng tự sinh cũ của sự kiện này trước (idempotent khi sửa lại).
        db_delete('calendar_events', "recur_parent_id = $eid AND user_id = $uid");

        if ($rule === null) {
            db_update('calendar_events', ['recur_rule' => null], "id = $eid AND user_id = $uid");
            return ['created' => 0];
        }
        if (!evcal_valid_recur_rule($rule)) return false;

        db_update('calendar_events', [
            'recur_rule' => json_encode($rule, JSON_UNESCAPED_UNICODE),
        ], "id = $eid AND user_id = $uid");

        $horizon = evcal_recur_horizon($own['event_date']);
        $dates   = evcal_compute_recur_dates($own['event_date'], $rule, $horizon);
        $now     = date('Y-m-d H:i:s');
        foreach ($dates as $d) {
            db_insert('calendar_events', [
                'user_id'         => $uid,
                'event_date'      => $d,
                'event_time'      => $own['event_time'],
                'content'         => $own['content'], // đã mã hóa sẵn — copy thẳng, không giải/mã lại
                'notified'        => 0,
                'recur_parent_id' => $eid,
                'created_at'      => $now,
                'updated_at'      => $now,
            ]);
        }
        return ['created' => count($dates)];
    }
}
