<?php

/**
 * production_staff model
 * - KẾ HOẠCH SẢN XUẤT DÀI NGÀY (long_term_production_plan)
 *   Bảng: long_term_production_plans (sản phẩm/ngày) + long_term_production_tasks (việc khác/ngày).
 */

/* =====================================================================
 *  TÊN THƯỜNG GỌI (products.common_product_name / material_information.common_material_name)
 *  Dùng ở production_plan (sửa tên SP) và plan_for_staff (sửa tên NVL bao bì).
 * =====================================================================*/

/** Thêm cột products.common_product_name ("Tên thường gọi") nếu chưa có. */
function ps_ensure_product_common_name_column()
{
    $col = db_fetch_row("SELECT COLUMN_NAME FROM information_schema.COLUMNS
                         WHERE TABLE_SCHEMA = DATABASE()
                           AND TABLE_NAME = 'products'
                           AND COLUMN_NAME = 'common_product_name' LIMIT 1");
    if (!$col) {
        db_query("ALTER TABLE products ADD COLUMN common_product_name VARCHAR(255) DEFAULT NULL");
    }
}

/** Lưu tên thường gọi cho 1 sản phẩm. */
function ps_save_product_common_name($product_id, $value)
{
    ps_ensure_product_common_name_column();
    $pid = (int) $product_id;
    if ($pid <= 0) return false;
    $v = trim((string) $value);
    return db_update('products', ['common_product_name' => ($v !== '' ? $v : null)], "id = {$pid}");
}

/** Lưu tên thường gọi cho 1 nguyên vật liệu (bao bì trong/ngoài). */
function ps_save_material_common_name($material_id, $value)
{
    $mid = (int) $material_id;
    if ($mid <= 0) return false;
    $v = trim((string) $value);
    return db_update('material_information', ['common_material_name' => ($v !== '' ? $v : null)], "id = {$mid}");
}

/* =====================================================================
 *  TIÊU ĐỀ PHIẾU SHARE KHSX (app_settings, DÙNG CHUNG prefix 'pf_share.*'
 *  với production_formula — sửa ở view nào cũng cập nhật chung 1 bảng).
 * =====================================================================*/

/** Tạo bảng app_settings nếu chưa có (1 lần / request). */
function ps_share_app_settings_ensure()
{
    static $done = false;
    if ($done) return;
    $done = true;
    db_query("CREATE TABLE IF NOT EXISTS app_settings (
        setting_key   VARCHAR(100) NOT NULL,
        setting_value TEXT DEFAULT NULL,
        PRIMARY KEY (setting_key)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci");
}

/** Giá trị mặc định + whitelist key — khớp production_formula (pf_share_settings_defaults). */
function ps_share_settings_defaults()
{
    return [
        'company_name'    => 'VUA AN TOÀN',
        'company_address' => '1/13Z Ấp Tiền Lân, Xã Bà Điểm, TP Hồ Chí Minh, Việt Nam',
    ];
}

/** Lấy toàn bộ thông tin tiêu đề phiếu share (default + override đã lưu, dùng chung với production_formula). */
function ps_get_share_settings()
{
    ps_share_app_settings_ensure();
    $out  = ps_share_settings_defaults();
    $rows = db_fetch_array("SELECT setting_key, setting_value FROM app_settings
                            WHERE setting_key LIKE 'pf_share.%'") ?: [];
    foreach ($rows as $r) {
        $k = substr((string) $r['setting_key'], strlen('pf_share.'));
        if (array_key_exists($k, $out) && $r['setting_value'] !== null) {
            $out[$k] = (string) $r['setting_value'];
        }
    }
    return $out;
}

/** Lưu 1 thông tin tiêu đề phiếu share (chỉ chấp nhận key trong whitelist). */
function ps_save_share_setting($key, $value)
{
    $key = (string) $key;
    if (!array_key_exists($key, ps_share_settings_defaults())) return false;
    ps_share_app_settings_ensure();
    $full = 'pf_share.' . $key;
    $fk   = escape_string($full);
    $exists = db_num_rows("SELECT 1 FROM app_settings WHERE setting_key = '$fk'") > 0;
    if ($exists) {
        db_update('app_settings', ['setting_value' => (string) $value], "setting_key = '$fk'");
    } else {
        db_insert('app_settings', ['setting_key' => $full, 'setting_value' => (string) $value]);
    }
    return true;
}

/** Định dạng ngày kiểu "04 Tháng Bảy 2026", không phụ thuộc locale server (giống pl_vn_date). */
function ps_vn_date($date)
{
    $ts = is_string($date) ? strtotime($date) : (int) $date;
    if (!$ts) return '';
    $months = [
        1 => 'Một', 2 => 'Hai', 3 => 'Ba', 4 => 'Tư', 5 => 'Năm', 6 => 'Sáu',
        7 => 'Bảy', 8 => 'Tám', 9 => 'Chín', 10 => 'Mười', 11 => 'Mười Một', 12 => 'Mười Hai',
    ];
    return date('d', $ts) . ' Tháng ' . $months[(int) date('n', $ts)] . ' ' . date('Y', $ts);
}

/* =====================================================================
 *  HELPERS NGÀY
 * =====================================================================*/

/** Thứ trong tuần (tiếng Việt) cho 1 ngày 'Y-m-d'. */
function ltp_weekday_vi($date)
{
    $n = (int) date('N', strtotime($date)); // 1=Mon .. 7=Sun
    $map = [
        1 => 'Thứ hai', 2 => 'Thứ ba', 3 => 'Thứ tư', 4 => 'Thứ năm',
        5 => 'Thứ sáu', 6 => 'Thứ bảy', 7 => 'Chủ nhật',
    ];
    return $map[$n] ?? '';
}

/** Chuẩn hóa 'Y-m-d' hợp lệ, hoặc '' nếu sai định dạng. */
function ltp_norm_date($d)
{
    $d = trim((string) $d);
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $d)) return '';
    $t = strtotime($d);
    return $t ? date('Y-m-d', $t) : '';
}

/**
 * Sinh danh sách N ngày liên tiếp KHÔNG tính hôm nay (bắt đầu từ ngày mai).
 * Mỗi phần tử: { date, weekday, display (j/n/Y), is_sunday }.
 */
function ltp_build_upcoming_days($n = 10)
{
    $days = [];
    for ($i = 1; $i <= $n; $i++) {
        $ts = strtotime("+$i day");
        $d  = date('Y-m-d', $ts);
        $days[] = [
            'date'      => $d,
            'weekday'   => ltp_weekday_vi($d),
            'display'   => date('j/n/Y', $ts),
            'is_sunday' => ((int) date('N', $ts) === 7),
        ];
    }
    return $days;
}

/** Ngày plan/task xa nhất đang có dữ liệu (>= ngày bắt đầu), hoặc null. */
function ltp_max_plan_date($from)
{
    $f = escape_string(ltp_norm_date($from) ?: date('Y-m-d'));
    $r = db_fetch_row(
        "SELECT MAX(d) AS m FROM (
            SELECT MAX(plan_date) d FROM long_term_production_plans WHERE plan_date >= '$f'
            UNION ALL
            SELECT MAX(plan_date) d FROM long_term_production_tasks WHERE plan_date >= '$f'
         ) t"
    );
    return !empty($r['m']) ? $r['m'] : null;
}

/* --- Lưu độ dài board (số thẻ) vào app_settings, không giới hạn số thẻ --- */
function ltp_setting_get($key, $default = null)
{
    $k = escape_string($key);
    $r = db_fetch_row("SELECT setting_value FROM app_settings WHERE setting_key = '$k' LIMIT 1");
    return $r ? $r['setting_value'] : $default;
}
function ltp_setting_set($key, $val)
{
    $k = escape_string($key);
    $v = escape_string((string) $val);
    db_query("INSERT INTO app_settings (setting_key, setting_value) VALUES ('$k', '$v')
              ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)");
}

/**
 * Ngày bắt đầu board: MỘT NGÀY CỐ ĐỊNH lưu trong app_settings (ltp_board_start_date).
 * Khác bản cũ (today-2 động) — điểm bắt đầu KHÔNG tự trôi theo ngày, nên các ngày
 * đã qua không tự ẩn vào "Đã hoàn thành"; chúng chỉ rời board khi user bấm xóa
 * card đầu (ltp_remove_day) — lúc đó điểm bắt đầu mới dời tới hôm sau.
 * Lần đầu chưa có cấu hình -> khởi tạo = hôm nay-2 (giữ hiển thị hôm qua + hôm nay).
 */
function ltp_board_start()
{
    $stored = ltp_norm_date((string) ltp_setting_get('ltp_board_start_date', ''));
    if ($stored !== '') return $stored;
    $init = date('Y-m-d', strtotime('-2 day'));
    ltp_setting_set('ltp_board_start_date', $init);
    return $init;
}

/**
 * Ngày kết thúc board (hiệu lực): mặc định hôm nay+10, cộng delta đã lưu
 * (ltp_extra_days — do Thêm/Xóa ngày), tự nới tới ngày xa nhất còn dữ liệu,
 * và không bao giờ nhỏ hơn hôm nay.
 */
function ltp_board_end()
{
    $today = date('Y-m-d');
    $extra = (int) ltp_setting_get('ltp_extra_days', 0);
    $n     = 10 + $extra;                          // có thể âm
    $end   = date('Y-m-d', strtotime("$n day"));
    $maxc  = ltp_max_plan_date(ltp_board_start());
    if ($maxc && $maxc > $end) $end = $maxc;
    if ($end < $today)         $end = $today;
    $start = ltp_board_start();
    if ($end < $start)         $end = $start; // không bao giờ ngắn hơn ngày bắt đầu
    return $end;
}

/** Đặt ngày kết thúc board (lưu dưới dạng delta so với hôm nay+10). */
function ltp_set_board_end($date)
{
    $d = ltp_norm_date($date);
    if ($d === '') return;
    $base  = strtotime(date('Y-m-d', strtotime('+10 day')));
    $extra = (int) round((strtotime($d) - $base) / 86400);
    ltp_setting_set('ltp_extra_days', $extra);
}

/** Thêm 1 ngày vào cuối board (ngày kế tiếp). Trả ngày mới. */
function ltp_add_day()
{
    $new_end = date('Y-m-d', strtotime(ltp_board_end() . ' +1 day'));
    ltp_set_board_end($new_end);
    return $new_end;
}

/** Thêm 1 ngày vào ĐẦU board (ngày trước ngày bắt đầu hiện tại). Trả ngày mới. */
function ltp_add_day_start()
{
    $start = ltp_board_start();
    $new   = date('Y-m-d', strtotime($start . ' -1 day'));
    ltp_setting_set('ltp_board_start_date', $new);
    return $new;
}

/**
 * Cửa sổ cho TRANG LẬP kế hoạch: 2 ngày đã qua + hôm nay (dẫn đầu) + các ngày tới.
 * Bắt đầu hôm nay-2 (Thứ 5); số ngày cuối do ltp_board_end() quyết định (lưu được,
 * Thêm/Xóa ngày thay đổi thật, không giới hạn). Cờ: is_today, is_past, is_sunday.
 * (Khác ltp_build_upcoming_days — bản chỉ-tương-lai dùng cho KHSX dự kiến sell_factory.)
 */
function ltp_build_plan_window()
{
    $today = date('Y-m-d');
    $start = ltp_board_start();
    $end   = ltp_board_end();

    $days = [];
    for ($ts = strtotime($start), $endts = strtotime($end); $ts <= $endts; $ts = strtotime('+1 day', $ts)) {
        $d = date('Y-m-d', $ts);
        $days[] = [
            'date'      => $d,
            'weekday'   => ltp_weekday_vi($d),
            'display'   => date('j/n/Y', $ts),
            'is_sunday' => ((int) date('N', $ts) === 7),
            'is_today'  => ($d === $today),
            'is_past'   => ($d < $today),
        ];
    }
    return $days;
}

/* =====================================================================
 *  ĐỌC DỮ LIỆU
 * =====================================================================*/

/** Map [plan_date => [item,...]] sản phẩm trong khoảng ngày. */
function ltp_get_items_grouped($from, $to)
{
    $from = ltp_norm_date($from);
    $to   = ltp_norm_date($to);
    if ($from === '' || $to === '') return [];
    $f = escape_string($from); $t = escape_string($to);
    $rows = db_fetch_array(
        /* PHẢI dùng CÙNG công thức tên với ô gợi ý tìm kiếm (search_productAction cũng
           COALESCE common_product_name trước). Trước đây ô gợi ý hiện TÊN THƯỜNG GỌI còn card
           hiện TÊN GỐC, nên cùng một sản phẩm mang 2 tên khác nhau tuỳ lúc nhìn:
           id=42 gợi ý ra "Đường Nâu Mật Mía" nhưng sau F5 card lại ghi "Đường đen VAT can 2.5kg".
           Người dùng thấy tên đổi sau khi tải lại trang và tưởng hệ thống tự đổi sản phẩm. */
        "SELECT l.id, l.plan_date, l.product_id, l.quantity, l.sort_order, l.status,
                COALESCE(NULLIF(p.common_product_name, ''), p.product_name) AS product_name,
                p.unit
         FROM long_term_production_plans l
         LEFT JOIN products p ON p.id = l.product_id
         WHERE l.plan_date BETWEEN '$f' AND '$t'
         ORDER BY l.plan_date ASC, l.sort_order ASC, l.id ASC"
    ) ?: [];
    $map = [];
    foreach ($rows as $r) $map[$r['plan_date']][] = $r;
    return $map;
}

/** Map [plan_date => [task,...]] việc khác trong khoảng ngày. */
function ltp_get_tasks_grouped($from, $to)
{
    $from = ltp_norm_date($from);
    $to   = ltp_norm_date($to);
    if ($from === '' || $to === '') return [];
    $f = escape_string($from); $t = escape_string($to);
    $rows = db_fetch_array(
        "SELECT id, plan_date, content, sort_order, status
         FROM long_term_production_tasks
         WHERE plan_date BETWEEN '$f' AND '$t'
         ORDER BY plan_date ASC, sort_order ASC, id ASC"
    ) ?: [];
    $map = [];
    foreach ($rows as $r) $map[$r['plan_date']][] = $r;
    return $map;
}

/**
 * Khối "Đã hoàn thành": các ngày ĐÃ QUA (< hôm nay) còn dữ liệu.
 * Trả: { days: [ {date, weekday, display, items[], tasks[]} ... mới→cũ ], count }.
 * count = tổng số sản phẩm + việc khác đã qua.
 */
function ltp_get_completed($before = null)
{
    // Mặc định < hôm nay; truyền $before (ngày bắt đầu board) để không trùng các
    // ngày đã-qua đang hiển thị trên board (vd hôm qua).
    $cut = ltp_norm_date((string) $before) ?: date('Y-m-d');
    $t = escape_string($cut);

    $items = db_fetch_array(
        "SELECT l.id, l.plan_date, l.product_id, l.quantity, p.product_name, p.unit
         FROM long_term_production_plans l
         LEFT JOIN products p ON p.id = l.product_id
         WHERE l.plan_date < '$t'
         ORDER BY l.plan_date DESC, l.sort_order ASC, l.id ASC"
    ) ?: [];
    $tasks = db_fetch_array(
        "SELECT id, plan_date, content
         FROM long_term_production_tasks
         WHERE plan_date < '$t'
         ORDER BY plan_date DESC, sort_order ASC, id ASC"
    ) ?: [];

    $by = [];
    foreach ($items as $r) {
        $d = $r['plan_date'];
        if (!isset($by[$d])) $by[$d] = ['items' => [], 'tasks' => []];
        $by[$d]['items'][] = $r;
    }
    foreach ($tasks as $r) {
        $d = $r['plan_date'];
        if (!isset($by[$d])) $by[$d] = ['items' => [], 'tasks' => []];
        $by[$d]['tasks'][] = $r;
    }

    $days  = [];
    $count = 0;
    foreach ($by as $d => $grp) {
        $count += count($grp['items']) + count($grp['tasks']);
        $days[] = [
            'date'    => $d,
            'weekday' => ltp_weekday_vi($d),
            'display' => date('j/n/Y', strtotime($d)),
            'items'   => $grp['items'],
            'tasks'   => $grp['tasks'],
        ];
    }
    return ['days' => $days, 'count' => $count];
}

/**
 * TASK 5: xóa (reset) toàn bộ danh sách "Đã hoàn thành" — tức mọi sản phẩm/việc khác
 * có plan_date < ngày bắt đầu board ($before). Dùng đúng cutoff như ltp_get_completed.
 */
function ltp_clear_completed($before = null)
{
    $cut = ltp_norm_date((string) $before) ?: date('Y-m-d');
    $t = escape_string($cut);
    foreach (['long_term_production_plans', 'long_term_production_tasks'] as $tbl) {
        db_query("DELETE FROM $tbl WHERE plan_date < '$t'");
    }
    return true;
}

/* =====================================================================
 *  GHI DỮ LIỆU
 * =====================================================================*/

/** sort_order kế tiếp cho 1 bảng + ngày. */
function ltp_next_sort($table, $plan_date)
{
    $table = ($table === 'long_term_production_tasks') ? $table : 'long_term_production_plans';
    $d = escape_string($plan_date);
    $row = db_fetch_row("SELECT COALESCE(MAX(sort_order),0) AS m FROM $table WHERE plan_date='$d'");
    return (int) ($row['m'] ?? 0) + 1;
}

/** Thêm 1 sản phẩm vào kế hoạch của 1 ngày. Trả id (>0) hoặc 0. */
function ltp_add_product($plan_date, $product_id, $quantity)
{
    $d   = ltp_norm_date($plan_date);
    $pid = (int) $product_id;
    if ($d === '' || $pid <= 0) return 0;
    $qty = (float) $quantity;
    return (int) db_insert('long_term_production_plans', [
        'plan_date'  => $d,
        'product_id' => $pid,
        'quantity'   => $qty,
        'sort_order' => ltp_next_sort('long_term_production_plans', $d),
    ]);
}

/** Thêm 1 việc khác vào 1 ngày. Trả id (>0) hoặc 0. */
function ltp_add_task($plan_date, $content)
{
    $d = ltp_norm_date($plan_date);
    $c = trim((string) $content);
    if ($d === '' || $c === '') return 0;
    return (int) db_insert('long_term_production_tasks', [
        'plan_date'  => $d,
        'content'    => $c,
        'sort_order' => ltp_next_sort('long_term_production_tasks', $d),
    ]);
}

/** Sửa số lượng (và tùy chọn product_id) 1 sản phẩm. */
function ltp_update_product($id, $quantity, $product_id = 0)
{
    $id = (int) $id;
    if ($id <= 0) return false;
    $data = ['quantity' => (float) $quantity];
    if ((int) $product_id > 0) $data['product_id'] = (int) $product_id;
    db_update('long_term_production_plans', $data, "id = $id");
    return true;
}

/** Sửa nội dung 1 việc khác. */
function ltp_update_task($id, $content)
{
    $id = (int) $id;
    $c  = trim((string) $content);
    if ($id <= 0 || $c === '') return false;
    db_update('long_term_production_tasks', ['content' => $c], "id = $id");
    return true;
}

/**
 * Xóa ngày (mới — KHÔNG dồn ngày sau lên nữa):
 *  - Card ĐẦU board (và còn >1 card): xóa hẳn card -> dời điểm bắt đầu tới hôm sau.
 *  - Card CUỐI board (và còn >1 card): xóa hẳn card -> thu ngắn board 1 ngày.
 *  - Card GIỮA (hoặc board chỉ còn 1 card): chỉ xóa nội dung (giữ lại card).
 * Trả: 'removed' (mất card), 'cleared' (chỉ xóa nội dung), hoặc false nếu lỗi.
 */
function ltp_remove_day($plan_date)
{
    $d = ltp_norm_date($plan_date);
    if ($d === '') return false;

    $start = ltp_board_start();
    $end   = ltp_board_end();
    $ds = escape_string($d);

    // Luôn xóa nội dung của ngày đó.
    foreach (['long_term_production_plans', 'long_term_production_tasks'] as $tbl) {
        db_query("DELETE FROM $tbl WHERE plan_date = '$ds'");
    }

    // Card đầu (còn nhiều hơn 1 card): dời điểm bắt đầu (cố định) tới hôm sau.
    if ($d === $start && $start !== $end) {
        $new_start = date('Y-m-d', strtotime($start . ' +1 day'));
        ltp_setting_set('ltp_board_start_date', $new_start);
        return 'removed';
    }
    // Card cuối (còn nhiều hơn 1 card): thu ngắn board 1 ngày.
    if ($d === $end && $start !== $end) {
        $new_end = date('Y-m-d', strtotime($end . ' -1 day'));
        if ($new_end < $start) $new_end = $start;
        ltp_set_board_end($new_end);
        return 'removed';
    }
    // Card giữa (hoặc chỉ còn 1 card): chỉ xóa nội dung.
    return 'cleared';
}

/**
 * Chuyển 1 sản phẩm / việc khác sang ngày khác (kéo-thả từng dòng).
 * Đặt vào cuối danh sách của ngày đích (sort_order kế tiếp).
 */
function ltp_move_item($type, $id, $to_date)
{
    $id = (int) $id;
    $d  = ltp_norm_date($to_date);
    if ($id <= 0 || $d === '') return false;
    $table = $type === 'task' ? 'long_term_production_tasks' : 'long_term_production_plans';
    db_update($table, [
        'plan_date'  => $d,
        'sort_order' => ltp_next_sort($table, $d),
    ], "id = $id");
    return true;
}

/** Xóa 1 sản phẩm / việc khác. */
function ltp_delete($type, $id)
{
    $id = (int) $id;
    if ($id <= 0) return false;
    $table = $type === 'task' ? 'long_term_production_tasks' : 'long_term_production_plans';
    db_delete($table, "id = $id");
    return true;
}

/* =====================================================================
 *  KẾ HOẠCH SẢN XUẤT DỰ PHÒNG (long_term_production_backup)
 * =====================================================================*/

/** Mã thời gian tự xóa -> mệnh đề INTERVAL của MySQL. */
function ltp_backup_ttl_interval($code)
{
    switch ((string) $code) {
        case '1w': return '1 WEEK';
        case '2w': return '2 WEEK';
        case '1m':
        default:   return '1 MONTH';
    }
}

/** Lấy cấu hình thời gian tự xóa dự phòng ('1w'|'2w'|'1m'). */
function ltp_backup_ttl_get()
{
    $v = (string) ltp_setting_get('ltp_backup_ttl', '1m');
    return in_array($v, ['1w', '2w', '1m'], true) ? $v : '1m';
}

/** Đặt cấu hình thời gian tự xóa dự phòng. */
function ltp_backup_ttl_set($code)
{
    if (!in_array((string) $code, ['1w', '2w', '1m'], true)) return false;
    ltp_setting_set('ltp_backup_ttl', $code);
    return true;
}

/** Xóa các dòng dự phòng đã quá hạn (created_at < now - TTL). Chạy mỗi lần mở trang. */
function ltp_backup_purge_expired()
{
    $iv = ltp_backup_ttl_interval(ltp_backup_ttl_get());
    db_query("DELETE FROM long_term_production_backup WHERE created_at < DATE_SUB(NOW(), INTERVAL $iv)");
}

/** Danh sách sản phẩm dự phòng (kèm tên sản phẩm, ngày tạo). */
function ltp_get_backup_items()
{
    return db_fetch_array(
        "SELECT b.id, b.product_id, b.quantity, b.note, b.sort_order, b.created_at,
                p.product_name, p.unit
         FROM long_term_production_backup b
         LEFT JOIN products p ON p.id = b.product_id
         ORDER BY b.sort_order ASC, b.id ASC"
    ) ?: [];
}

/** Đọc 1 dòng dự phòng (kèm tên sản phẩm). */
function ltp_backup_row($id)
{
    $id = (int) $id;
    if ($id <= 0) return null;
    return db_fetch_row(
        "SELECT b.id, b.product_id, b.quantity, b.note, b.created_at, p.product_name, p.unit
         FROM long_term_production_backup b
         LEFT JOIN products p ON p.id = b.product_id
         WHERE b.id = $id LIMIT 1"
    ) ?: null;
}

/** sort_order kế tiếp cho danh sách dự phòng. */
function ltp_backup_next_sort()
{
    $row = db_fetch_row("SELECT COALESCE(MAX(sort_order),0) AS m FROM long_term_production_backup");
    return (int) ($row['m'] ?? 0) + 1;
}

/** Thêm 1 sản phẩm dự phòng. Trả id (>0) hoặc 0. */
function ltp_backup_add($product_id, $quantity)
{
    $pid = (int) $product_id;
    if ($pid <= 0) return 0;
    return (int) db_insert('long_term_production_backup', [
        'product_id' => $pid,
        'quantity'   => (float) $quantity,
        'sort_order' => ltp_backup_next_sort(),
    ]);
}

/** Sửa số lượng (và tùy chọn product_id) 1 dòng dự phòng. */
function ltp_backup_update($id, $quantity, $product_id = 0)
{
    $id = (int) $id;
    if ($id <= 0) return false;
    $data = ['quantity' => (float) $quantity];
    if ((int) $product_id > 0) $data['product_id'] = (int) $product_id;
    db_update('long_term_production_backup', $data, "id = $id");
    return true;
}

/** Sửa ghi chú 1 dòng dự phòng. */
function ltp_backup_update_note($id, $note)
{
    $id = (int) $id;
    if ($id <= 0) return false;
    db_update('long_term_production_backup', ['note' => (string) $note], "id = $id");
    return true;
}

/** Xóa 1 dòng dự phòng. */
function ltp_backup_delete($id)
{
    $id = (int) $id;
    if ($id <= 0) return false;
    db_delete('long_term_production_backup', "id = $id");
    return true;
}

/** Sắp xếp lại danh sách dự phòng theo thứ tự id truyền vào. */
function ltp_backup_reorder($ids)
{
    if (!is_array($ids)) return false;
    $i = 1;
    foreach ($ids as $id) {
        $id = (int) $id;
        if ($id <= 0) continue;
        db_update('long_term_production_backup', ['sort_order' => $i++], "id = $id");
    }
    return true;
}

/** Sắp xếp lại "Việc khác" TRONG CÙNG 1 ngày theo thứ tự id truyền vào (kéo-thả lên/xuống). */
function ltp_task_reorder($ids)
{
    if (!is_array($ids)) return false;
    $i = 1;
    foreach ($ids as $id) {
        $id = (int) $id;
        if ($id <= 0) continue;
        db_update('long_term_production_tasks', ['sort_order' => $i++], "id = $id");
    }
    return true;
}

/**
 * Đưa 1 dòng dự phòng lên kế hoạch của 1 ngày: tạo bản ghi trong
 * long_term_production_plans rồi xóa khỏi dự phòng. Trả mảng item mới (để render),
 * hoặc null nếu thất bại.
 */
function ltp_backup_to_day($id, $plan_date)
{
    $row = ltp_backup_row($id);
    $d   = ltp_norm_date($plan_date);
    if (!$row || $d === '') return null;

    $new_id = ltp_add_product($d, (int) $row['product_id'], (float) $row['quantity']);
    if ($new_id <= 0) return null;

    ltp_backup_delete((int) $row['id']);
    return [
        'id'           => $new_id,
        'product_id'   => (int) $row['product_id'],
        'product_name' => $row['product_name'] ?? '',
        'unit'         => $row['unit'] ?? '',
        'quantity'     => (float) $row['quantity'],
    ];
}

/**
 * Tráo nội dung giữa 2 ngày (kéo card kiểu trello: NGÀY giữ nguyên, nội dung hoán đổi).
 * Dùng ngày sentinel '1000-01-01' để tránh đụng độ khi swap.
 */
function ltp_swap_days($date_a, $date_b)
{
    $a = ltp_norm_date($date_a);
    $b = ltp_norm_date($date_b);
    if ($a === '' || $b === '' || $a === $b) return false;
    $a = escape_string($a); $b = escape_string($b);
    $tmp = '1000-01-01';

    foreach (['long_term_production_plans', 'long_term_production_tasks'] as $tbl) {
        db_query("UPDATE $tbl SET plan_date='$tmp' WHERE plan_date='$a'");
        db_query("UPDATE $tbl SET plan_date='$a'   WHERE plan_date='$b'");
        db_query("UPDATE $tbl SET plan_date='$b'   WHERE plan_date='$tmp'");
    }
    return true;
}
