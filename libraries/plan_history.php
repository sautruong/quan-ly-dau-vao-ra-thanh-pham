<?php
/**
 * plan_history — LỊCH SỬ "KẾ HOẠCH SẢN XUẤT HẰNG NGÀY" THEO TỪNG NGÀY (prefix phs_*).
 *
 * User chốt 2026-11-19: trên mobile, bấm vào lịch ở trang Kế hoạch sản xuất hằng ngày
 * (production_staff/plan_for_staff) → popover chọn ngày để xem lại kế hoạch NGÀY ĐÓ. Vì vậy mỗi lần
 * đổ kế hoạch từ dài ngày sang hằng ngày, hoặc điều chỉnh kế hoạch, phải CẬP NHẬT bản lưu của ngày
 * đó — gồm cả danh sách sản phẩm lẫn "việc khác".
 *
 * ==============================================================================
 * VÌ SAO LÀ "ẢNH CHỤP" (snapshot) CHỨ KHÔNG PHẢI TRỎ VỀ BẢNG GỐC
 * ==============================================================================
 * Hệ chỉ giữ MỘT kế hoạch đang chạy (production_plans + additional_tasks) — mỗi lần công bố là xoá
 * sạch rồi chèn lại. Muốn xem lại ngày cũ thì phải cất bản sao ở chỗ khác. Bản sao còn phải ĐÓNG BĂNG
 * các thông tin lấy từ bảng khác (tên SP, bao bì, quy cách, tồn, ghi chú): sau này đổi tên SP hay đổi
 * bao bì thì kế hoạch của ngày cũ vẫn phải hiện đúng như hôm đó nhân viên đã thấy.
 *
 * Mỗi ngày đúng MỘT dòng (khoá chính plan_date); lưu lại thì GHI ĐÈ bằng trạng thái mới nhất của ngày đó
 * (đúng yêu cầu "điều chỉnh → cập nhật"). first_saved_at giữ nguyên, save_count đếm số lần cập nhật.
 *
 * ==============================================================================
 * 3 QUY TẮC AN TOÀN (đừng phá)
 * ==============================================================================
 *  1. KHÔNG BAO GIỜ làm hỏng thao tác chính: mọi câu lệnh ở đây dùng mysqli_query trần + try/catch,
 *     không dùng db_query() vì db_query() in bảng lỗi HTML rồi dừng — sẽ làm hỏng JSON của các action
 *     AJAX đang ghi dữ liệu. Lưu lịch sử hỏng chỉ ghi error_log, thao tác chính vẫn thành công.
 *  2. KHÔNG ghi đè lịch sử bằng bản RỖNG: kế hoạch hiện hành không có sản phẩm nào (vừa Reset, hoặc
 *     xoá hết) thì bỏ qua, giữ bản đầy đủ gần nhất — lịch sử là để tra cứu, mất nó vì lỡ tay là mất luôn.
 *  3. JSON lưu ở dạng ASCII (\uXXXX, KHÔNG dùng JSON_UNESCAPED_UNICODE) để không phụ thuộc charset kết nối.
 *
 * Bảng tự tạo qua phs_ensure_table() (giống plan_auto_export / ltp_share_notify). File .sql đi kèm chỉ để
 * cài tay hoặc tra cứu.
 */

if (date_default_timezone_get() !== 'Asia/Ho_Chi_Minh') {
    date_default_timezone_set('Asia/Ho_Chi_Minh');
}

// Dùng lại ltpn_current_plan_date() để có ĐÚNG MỘT định nghĩa "kế hoạch hiện hành thuộc ngày nào".
require_once __DIR__ . '/ltp_share_notify.php';

if (!function_exists('phs_ensure_table')) {

    /* ============================================================
     *  SCHEMA + TIỆN ÍCH
     * ============================================================ */

    function phs_ensure_table()
    {
        static $done = false;
        if ($done) return;
        $done = true;
        phs_exec("CREATE TABLE IF NOT EXISTS production_plan_history (
            plan_date      DATE NOT NULL,
            items_json     LONGTEXT NOT NULL,
            tasks_json     LONGTEXT NOT NULL,
            item_count     INT(11) NOT NULL DEFAULT 0,
            task_count     INT(11) NOT NULL DEFAULT 0,
            first_saved_at DATETIME NOT NULL,
            last_saved_at  DATETIME NOT NULL,
            last_saved_by  INT(11) NOT NULL DEFAULT 0,
            last_source    VARCHAR(20) NOT NULL DEFAULT '',
            save_count     INT(11) NOT NULL DEFAULT 1,
            PRIMARY KEY (plan_date)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci");
    }

    /** Chạy 1 câu lệnh KHÔNG dừng chương trình khi lỗi. Trả mysqli_result|true|false. */
    function phs_exec($sql)
    {
        global $conn;
        try {
            $r = @mysqli_query($conn, $sql);
            if ($r === false) {
                error_log('[plan_history] SQL lỗi: ' . mysqli_error($conn));
            }
            return $r;
        } catch (\Throwable $e) {
            error_log('[plan_history] ' . $e->getMessage());
            return false;
        }
    }

    /** Lấy mọi dòng của 1 câu SELECT (mảng rỗng nếu lỗi). */
    function phs_rows($sql)
    {
        $res = phs_exec($sql);
        $out = [];
        if ($res instanceof \mysqli_result) {
            while ($row = mysqli_fetch_assoc($res)) $out[] = $row;
            mysqli_free_result($res);
        }
        return $out;
    }

    /** Chuẩn hoá 'Y-m-d' hợp lệ (đúng lịch), hoặc '' nếu sai. */
    function phs_norm_date($d)
    {
        $d = trim((string) $d);
        if (!preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $d, $m)) return '';
        return checkdate((int) $m[2], (int) $m[3], (int) $m[1]) ? $d : '';
    }

    /** Ngày của kế hoạch hiện hành (card dài ngày đã công bố gần nhất; chưa có thì hôm nay). */
    function phs_live_date()
    {
        return ltpn_current_plan_date();
    }

    /* ============================================================
     *  ĐỌC KẾ HOẠCH HIỆN HÀNH (nguồn DUY NHẤT — trang plan_for_staff cũng dùng hàm này)
     * ============================================================ */

    /**
     * Kế hoạch đang chạy, đúng như trang plan_for_staff hiển thị.
     * Để trang và bản lưu CÙNG MỘT câu truy vấn: nếu mỗi nơi tự viết thì sớm muộn bản lưu thiếu
     * một trường mà trang có (hoặc ngược lại), và ngày cũ hiện khác ngày đã thấy.
     * $safe = true (khi cất bản lưu): lỗi SQL chỉ ghi log + trả rỗng, KHÔNG in bảng lỗi / dừng chương trình
     * (quy tắc 1). Trang hiển thị dùng $safe = false để lỗi vẫn hiện ra như trước.
     * Trả ['plans' => [...], 'tasks' => [...]].
     */
    function phs_fetch_live($safe = false)
    {
        $fetch = $safe ? 'phs_rows' : 'db_fetch_array';
        $plans = $fetch(
            "SELECT pp.id AS plan_id, pp.product_id, pp.quantity, pp.note AS plan_note,
                    pp.sample, pp.test, pp.check_inventory,
                    COALESCE(NULLIF(p.common_product_name, ''), p.product_name) AS product_name,
                    p.product_name AS system_product_name,
                    p.image_url,
                    ppn.note AS pre_note,
                    fgi.quantity AS stock_qty,
                    COALESCE(NULLIF(mi_in.common_material_name, ''), mi_in.material_name) AS inner_packaging,
                    COALESCE(NULLIF(mi_out.common_material_name, ''), mi_out.material_name) AS outer_packaging,
                    pib.inner_packaging_id, pib.outer_packaging_id,
                    pib.inner_packaging_spec, pib.outer_packaging_spec
             FROM production_plans pp
             LEFT JOIN products p ON p.id = pp.product_id
             LEFT JOIN pre_production_notes ppn ON ppn.product_id = pp.product_id
             LEFT JOIN finished_goods_inventory fgi ON fgi.product_id = pp.product_id
             LEFT JOIN product_info_basic pib ON pib.product_id = pp.product_id
             LEFT JOIN material_information mi_in ON mi_in.id = pib.inner_packaging_id
             LEFT JOIN material_information mi_out ON mi_out.id = pib.outer_packaging_id
             ORDER BY pp.id ASC"
        ) ?: [];

        $tasks = $fetch("SELECT id, description FROM additional_tasks ORDER BY id ASC") ?: [];

        return ['plans' => $plans, 'tasks' => $tasks];
    }

    /* ============================================================
     *  GHI
     * ============================================================ */

    /**
     * Cất (hoặc cập nhật) bản lưu của $planDate từ kế hoạch hiện hành.
     *
     * $source: 'publish' (đổ từ dài ngày — nút XUẤT KH / công bố) | 'auto' (lịch 16:50) |
     *          'edit' (điều chỉnh trên trang hằng ngày) | 'init' (lần đầu thấy kế hoạch đang chạy).
     * Trả true nếu đã ghi, false nếu bỏ qua/lỗi (không bao giờ ném lỗi ra ngoài).
     */
    function phs_capture($planDate = '', $source = 'edit')
    {
        try {
            $d = phs_norm_date($planDate);
            if ($d === '') $d = phs_norm_date(phs_live_date());
            if ($d === '') return false;

            phs_ensure_table();
            $live = phs_fetch_live(true);

            // Quy tắc 2: kế hoạch rỗng thì giữ nguyên bản lưu cũ.
            if (empty($live['plans'])) return false;

            $items = json_encode(array_values($live['plans']));
            $tasks = json_encode(array_values($live['tasks']));
            if ($items === false || $tasks === false) {
                error_log('[plan_history] json_encode lỗi: ' . json_last_error_msg());
                return false;
            }

            $user = function_exists('permission_current_user') ? permission_current_user() : null;
            $uid  = $user ? (int) $user['id'] : 0;
            $now  = date('Y-m-d H:i:s');
            $src  = substr(preg_replace('/[^a-z_]/', '', strtolower((string) $source)), 0, 20);

            $q = "INSERT INTO production_plan_history
                    (plan_date, items_json, tasks_json, item_count, task_count,
                     first_saved_at, last_saved_at, last_saved_by, last_source, save_count)
                  VALUES ('" . escape_string($d) . "', '" . escape_string($items) . "', '" . escape_string($tasks) . "',
                          " . count($live['plans']) . ", " . count($live['tasks']) . ",
                          '$now', '$now', $uid, '" . escape_string($src) . "', 1)
                  ON DUPLICATE KEY UPDATE
                          items_json = VALUES(items_json), tasks_json = VALUES(tasks_json),
                          item_count = VALUES(item_count), task_count = VALUES(task_count),
                          last_saved_at = VALUES(last_saved_at), last_saved_by = VALUES(last_saved_by),
                          last_source = VALUES(last_source), save_count = save_count + 1";
            return phs_exec($q) !== false;
        } catch (\Throwable $e) {
            error_log('[plan_history] capture: ' . $e->getMessage());
            return false;
        }
    }

    /**
     * Gọi sau MỌI thao tác điều chỉnh kế hoạch hiện hành: cập nhật bản lưu của ngày mà kế hoạch đang chạy
     * thuộc về. Rẻ (1 SELECT + 1 INSERT) và không bao giờ ném lỗi.
     */
    function phs_touch($source = 'edit')
    {
        return phs_capture(phs_live_date(), $source);
    }

    /** Lần đầu thấy kế hoạch đang chạy mà chưa có bản lưu → cất luôn (gom dữ liệu có từ trước khi có tính năng). */
    function phs_capture_if_missing($planDate = '')
    {
        try {
            $d = phs_norm_date($planDate);
            if ($d === '') $d = phs_norm_date(phs_live_date());
            if ($d === '') return false;
            phs_ensure_table();
            $has = phs_rows("SELECT 1 AS x FROM production_plan_history WHERE plan_date = '" . escape_string($d) . "' LIMIT 1");
            if ($has) return false;
            return phs_capture($d, 'init');
        } catch (\Throwable $e) {
            return false;
        }
    }

    /* ============================================================
     *  ĐỌC LỊCH SỬ
     * ============================================================ */

    /**
     * Bản lưu của 1 ngày, đã giải mã sẵn để view dùng ngay. null nếu ngày đó không có bản lưu.
     * Trả ['plan_date','plans','tasks','item_count','task_count','first_saved_at','last_saved_at',
     *       'saved_by_name','source','save_count'].
     */
    function phs_get($planDate)
    {
        $d = phs_norm_date($planDate);
        if ($d === '') return null;
        phs_ensure_table();
        $rows = phs_rows("SELECT * FROM production_plan_history WHERE plan_date = '" . escape_string($d) . "' LIMIT 1");
        if (!$rows) return null;
        $r = $rows[0];

        $plans = json_decode((string) $r['items_json'], true);
        $tasks = json_decode((string) $r['tasks_json'], true);

        return [
            'plan_date'      => $r['plan_date'],
            'plans'          => is_array($plans) ? $plans : [],
            'tasks'          => is_array($tasks) ? $tasks : [],
            'item_count'     => (int) $r['item_count'],
            'task_count'     => (int) $r['task_count'],
            'first_saved_at' => $r['first_saved_at'],
            'last_saved_at'  => $r['last_saved_at'],
            'saved_by_name'  => phs_user_name((int) $r['last_saved_by']),
            'source'         => (string) $r['last_source'],
            'save_count'     => (int) $r['save_count'],
        ];
    }

    /** Các ngày CÓ bản lưu trong khoảng [from, to] → map 'Y-m-d' => ['items'=>n, 'tasks'=>n]. */
    function phs_dates_between($from, $to)
    {
        $f = phs_norm_date($from);
        $t = phs_norm_date($to);
        if ($f === '' || $t === '') return [];
        phs_ensure_table();
        $out = [];
        foreach (phs_rows("SELECT plan_date, item_count, task_count FROM production_plan_history
                           WHERE plan_date BETWEEN '" . escape_string($f) . "' AND '" . escape_string($t) . "'
                           ORDER BY plan_date ASC") as $r) {
            $out[$r['plan_date']] = ['items' => (int) $r['item_count'], 'tasks' => (int) $r['task_count']];
        }
        return $out;
    }

    function phs_user_name($uid)
    {
        $uid = (int) $uid;
        if ($uid <= 0) return '';
        $rows = phs_rows("SELECT fullname, username FROM tbl_users WHERE id = $uid LIMIT 1");
        if (!$rows) return '';
        $n = trim((string) ($rows[0]['fullname'] ?? ''));
        return $n !== '' ? $n : (string) ($rows[0]['username'] ?? '');
    }

    /** Nhãn tiếng Việt cho nguồn cập nhật gần nhất. */
    function phs_source_label($source)
    {
        switch ((string) $source) {
            case 'publish': return 'Đổ từ kế hoạch dài ngày';
            case 'auto':    return 'Tự động chuyển từ kế hoạch dài ngày';
            case 'edit':    return 'Điều chỉnh kế hoạch';
            default:        return 'Lưu kế hoạch';
        }
    }
}
