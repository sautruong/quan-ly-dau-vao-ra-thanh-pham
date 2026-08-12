<?php
/**
 * =====================================================================
 *  Lương (payroll) — Model
 * =====================================================================
 *  2 views: Bảng chấm công (timesheet) + Bảng lương (payslip).
 *
 *  Bảng dùng:
 *   - employees / employee_work_info / employee_organization (module hr) : nguồn nhân sự
 *   - payroll_timesheet_entries   : chấm công — chỉ lưu SAI LỆCH so với mặc định "x"
 *   - payroll_off_reasons         : danh mục lý do off (CRUD)
 *   - payroll_employee_settings   : phụ cấp chức vụ (v) — thiết lập 1 lần/nhân viên
 *   - payroll_monthly_entries     : snapshot lương đã Lưu — 1 dòng/nhân viên/tháng
 *   - payroll_monthly_summary     : số liệu dùng chung đã Lưu — 1 dòng/tháng
 *   - production_receipts, sales_returns (handling_method='Thay bao bì') : sản lượng tháng
 *   - products.is_new_product     : lọc sản lượng sản phẩm mới (thưởng Trưởng phòng)
 *   - app_settings                : các số có thể chỉnh sửa, prefix 'payroll.'
 *
 *  Prefix hàm: py_*.
 *  Chỉ hỗ trợ 2 chức danh: 'Trưởng phòng', 'Nhân viên' — khác thì "chưa cấu hình".
 * =====================================================================
 */

/* ============================================================
 *  Khởi tạo bảng (idempotent)
 * ============================================================ */
function py_ensure_tables()
{
    static $done = false;
    if ($done) return;
    $done = true;

    db_query("CREATE TABLE IF NOT EXISTS app_settings (
        setting_key   VARCHAR(100) NOT NULL PRIMARY KEY,
        setting_value TEXT DEFAULT NULL
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci");

    db_query("CREATE TABLE IF NOT EXISTS payroll_timesheet_entries (
        id          INT AUTO_INCREMENT PRIMARY KEY,
        employee_id INT NOT NULL,
        work_date   DATE NOT NULL,
        mark        VARCHAR(10) NOT NULL,
        reason_id   INT DEFAULT NULL,
        note        VARCHAR(255) DEFAULT NULL,
        updated_by  INT DEFAULT NULL,
        updated_at  TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        UNIQUE KEY uniq_emp_date (employee_id, work_date),
        KEY idx_date (work_date)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci");

    db_query("CREATE TABLE IF NOT EXISTS payroll_off_reasons (
        id         INT AUTO_INCREMENT PRIMARY KEY,
        reason     VARCHAR(255) NOT NULL,
        sort_order INT NOT NULL DEFAULT 0,
        is_active  TINYINT(1) NOT NULL DEFAULT 1,
        created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci");

    db_query("CREATE TABLE IF NOT EXISTS payroll_employee_settings (
        employee_id        INT NOT NULL PRIMARY KEY,
        position_allowance DECIMAL(15,2) NOT NULL DEFAULT 0,
        updated_at          TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci");

    db_query("CREATE TABLE IF NOT EXISTS payroll_monthly_entries (
        id                    INT AUTO_INCREMENT PRIMARY KEY,
        employee_id           INT NOT NULL,
        year                  SMALLINT NOT NULL,
        month                 TINYINT NOT NULL,
        job_title             VARCHAR(50)  NOT NULL,
        months_worked_p       INT NOT NULL DEFAULT 0,
        seniority_coefficient DECIMAL(8,4) NOT NULL DEFAULT 0,
        base_salary_a         DECIMAL(15,2) NOT NULL DEFAULT 0,
        workdays_in_month_b   DECIMAL(6,2) NOT NULL DEFAULT 0,
        days_worked_c         DECIMAL(6,2) NOT NULL DEFAULT 0,
        holiday_days_d        DECIMAL(6,2) NOT NULL DEFAULT 0,
        annual_leave_f        DECIMAL(6,2) NOT NULL DEFAULT 0,
        off_days_g            DECIMAL(6,2) NOT NULL DEFAULT 0,
        total_days_h          DECIMAL(6,2) NOT NULL DEFAULT 0,
        base_total_r          DECIMAL(15,2) NOT NULL DEFAULT 0,
        discipline_allow_j    DECIMAL(15,2) NOT NULL DEFAULT 0,
        position_allow_base_v DECIMAL(15,2) NOT NULL DEFAULT 0,
        position_allow_k      DECIMAL(15,2) NOT NULL DEFAULT 0,
        total_allow_l         DECIMAL(15,2) NOT NULL DEFAULT 0,
        flat_allow_y1         DECIMAL(15,2) NOT NULL DEFAULT 0,
        allow_boc_hang        DECIMAL(15,2) NOT NULL DEFAULT 0,
        allow_don_dep         DECIMAL(15,2) NOT NULL DEFAULT 0,
        allow_hu_hong         DECIMAL(15,2) NOT NULL DEFAULT 0,
        ot_hours              DECIMAL(8,2) NOT NULL DEFAULT 0,
        ot_total              DECIMAL(15,2) NOT NULL DEFAULT 0,
        violation_count_t     INT NOT NULL DEFAULT 0,
        violation_deduct_t    DECIMAL(15,2) NOT NULL DEFAULT 0,
        kpi_o                 DECIMAL(15,2) NOT NULL DEFAULT 0,
        kpi_i                 DECIMAL(15,4) NOT NULL DEFAULT 0,
        kpi_u                 DECIMAL(15,2) NOT NULL DEFAULT 0,
        kpi_o_base            DECIMAL(15,2) NOT NULL DEFAULT 0,
        new_product_bonus     DECIMAL(15,2) NOT NULL DEFAULT 0,
        kpi_total             DECIMAL(15,2) NOT NULL DEFAULT 0,
        bhxh_base             DECIMAL(15,2) NOT NULL DEFAULT 0,
        bhxh_company          DECIMAL(15,2) NOT NULL DEFAULT 0,
        bhxh_company_auto     DECIMAL(15,2) NOT NULL DEFAULT 0,
        bhxh_employee         DECIMAL(15,2) NOT NULL DEFAULT 0,
        bhxh_employee_auto    DECIMAL(15,2) NOT NULL DEFAULT 0,
        total_with_bhxh       DECIMAL(15,2) NOT NULL DEFAULT 0,
        total_after_bhxh      DECIMAL(15,2) NOT NULL DEFAULT 0,
        advance_amount        DECIMAL(15,2) NOT NULL DEFAULT 0,
        other_bonus           DECIMAL(15,2) NOT NULL DEFAULT 0,
        other_bonus_note      VARCHAR(255) DEFAULT NULL,
        net_pay               DECIMAL(15,2) NOT NULL DEFAULT 0,
        created_at            TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        updated_at            TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        UNIQUE KEY uniq_emp_ym (employee_id, year, month)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci");

    // Cột bổ sung sau này (bảng payroll_monthly_entries có thể đã tồn tại trước khi các cột này được thêm vào).
    db_query("ALTER TABLE payroll_monthly_entries ADD COLUMN IF NOT EXISTS allow_boc_hang DECIMAL(15,2) NOT NULL DEFAULT 0 AFTER flat_allow_y1");
    db_query("ALTER TABLE payroll_monthly_entries ADD COLUMN IF NOT EXISTS allow_don_dep  DECIMAL(15,2) NOT NULL DEFAULT 0 AFTER allow_boc_hang");
    db_query("ALTER TABLE payroll_monthly_entries ADD COLUMN IF NOT EXISTS allow_hu_hong  DECIMAL(15,2) NOT NULL DEFAULT 0 AFTER allow_don_dep");
    db_query("ALTER TABLE payroll_monthly_entries ADD COLUMN IF NOT EXISTS kpi_i          DECIMAL(15,4) NOT NULL DEFAULT 0 AFTER kpi_o");
    db_query("ALTER TABLE payroll_monthly_entries ADD COLUMN IF NOT EXISTS kpi_u          DECIMAL(15,2) NOT NULL DEFAULT 0 AFTER kpi_i");
    db_query("ALTER TABLE payroll_monthly_entries ADD COLUMN IF NOT EXISTS kpi_o_base     DECIMAL(15,2) NOT NULL DEFAULT 0 AFTER kpi_u");

    db_query("CREATE TABLE IF NOT EXISTS payroll_monthly_summary (
        id                      INT AUTO_INCREMENT PRIMARY KEY,
        year                    SMALLINT NOT NULL,
        month                   TINYINT NOT NULL,
        workdays_in_month       DECIMAL(6,2) NOT NULL DEFAULT 0,
        total_production_congs DECIMAL(10,2) NOT NULL DEFAULT 0,
        cong_coefficient        DECIMAL(8,4) NOT NULL DEFAULT 0,
        output_qty              DECIMAL(15,3) NOT NULL DEFAULT 0,
        output_per_cong_i       DECIMAL(15,4) NOT NULL DEFAULT 0,
        achievement_value_u     DECIMAL(15,2) NOT NULL DEFAULT 0,
        new_product_qty         DECIMAL(15,3) NOT NULL DEFAULT 0,
        created_at              TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        updated_at              TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        UNIQUE KEY uniq_ym (year, month)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci");

    // Cột "Sản phẩm mới" (có thể đã được manage_product_list thêm trước đó).
    db_query("ALTER TABLE products ADD COLUMN IF NOT EXISTS is_new_product TINYINT(1) NOT NULL DEFAULT 0");

    db_query("CREATE TABLE IF NOT EXISTS payroll_output_adjustments (
        id         INT AUTO_INCREMENT PRIMARY KEY,
        year       SMALLINT NOT NULL,
        month      TINYINT NOT NULL,
        amount     DECIMAL(15,3) NOT NULL DEFAULT 0,
        note       VARCHAR(255) DEFAULT NULL,
        created_by INT DEFAULT NULL,
        created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        KEY idx_ym (year, month)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci");

    py_seed_off_reasons();
}

/** Đăng ký 2 view vào menu "LƯƠNG" (idempotent — chạy ở trang chính). */
function py_ensure_view_registered()
{
    if (db_num_rows("SHOW TABLES LIKE 'tbl_views'") <= 0) return;
    db_query("INSERT IGNORE INTO tbl_views (module, controller, action, label, group_label, sort)
              VALUES ('payroll','payroll','timesheet','Bảng chấm công','LƯƠNG', 130)");
    db_query("INSERT IGNORE INTO tbl_views (module, controller, action, label, group_label, sort)
              VALUES ('payroll','payroll','payslip','Bảng lương','LƯƠNG', 131)");
}

function py_seed_off_reasons()
{
    $row = db_fetch_row("SELECT COUNT(*) AS c FROM payroll_off_reasons");
    if ($row && (int) $row['c'] > 0) return;
    $defaults = ['Con ốm', 'Ốm đau', 'Nghỉ nữa ngày có phép', 'Nghỉ có phép'];
    foreach ($defaults as $i => $reason) {
        db_insert('payroll_off_reasons', ['reason' => $reason, 'sort_order' => $i, 'is_active' => 1]);
    }
}

/* ============================================================
 *  app_settings (các số "sửa 1 lần dùng nhiều lần") — prefix 'payroll.'
 * ============================================================ */
function py_get_setting($key, $default = '')
{
    py_ensure_tables();
    $k = escape_string((string) $key);
    $row = db_fetch_row("SELECT setting_value FROM app_settings WHERE setting_key = '$k' LIMIT 1");
    if (!$row || $row['setting_value'] === null || $row['setting_value'] === '') return $default;
    return $row['setting_value'];
}

function py_set_setting($key, $value)
{
    py_ensure_tables();
    $k = (string) $key;
    if ($k === '') return false;
    $ke = escape_string($k);
    $exists = db_num_rows("SELECT 1 FROM app_settings WHERE setting_key = '$ke'") > 0;
    if ($exists) {
        db_update('app_settings', ['setting_value' => (string) $value], "setting_key = '$ke'");
    } else {
        db_insert('app_settings', ['setting_key' => $k, 'setting_value' => (string) $value]);
    }
    return true;
}

/** Danh sách key setting được phép sửa qua save_settingAction, kèm mặc định. */
function py_setting_defaults()
{
    return [
        'payroll.branch_filter'            => 'headquarters',
        'payroll.base_salary.truong_phong' => 6500000,
        'payroll.base_salary.nhan_vien'    => 4420000,
        'payroll.annual_leave_f'           => 1,
        'payroll.discipline_allowance_base'=> 300000,
        'payroll.allow_boc_hang'           => 200000,
        'payroll.allow_don_dep'            => 100000,
        'payroll.allow_hu_hong'            => 100000,
        'payroll.violation_rate'           => 50000,
        'payroll.kpi_tier1_max'            => 2600,
        'payroll.kpi_tier2_max'            => 3200,
        'payroll.kpi_tier1_value'          => 500,
        'payroll.kpi_tier2_value'          => 700,
        'payroll.kpi_tier3_value'          => 900,
        'payroll.new_product_bonus_rate'   => 500,
        'payroll.bhxh_base.truong_phong'   => 6420000,
        'payroll.bhxh_base.nhan_vien'      => 5310000,
        'payroll.bhxh_rate_company'        => 21.5,
        'payroll.bhxh_rate_employee'       => 10.5,
        'payroll.company_name'             => 'CÔNG TY TNHH VUA AN TOÀN',
        'payroll.signer_title'             => 'TRƯỞNG PHÒNG SẢN XUẤT',
    ];
}

/** Toàn bộ setting hiện tại (default đã fallback) — dùng cho khối "Cài đặt" ở Bảng lương. */
function py_all_settings()
{
    $out = [];
    foreach (py_setting_defaults() as $key => $default) {
        $out[$key] = py_get_setting($key, $default);
    }
    return $out;
}

/** Nhãn tiếng Việt + kiểu hiển thị (currency/percent/number/text) cho từng key, dùng ở khối "Cài đặt". */
function py_setting_meta()
{
    return [
        'payroll.branch_filter'             => ['label' => 'Chi nhánh áp dụng (mã "branch" trong Nhân sự)', 'type' => 'text',     'unit' => ''],
        'payroll.base_salary.truong_phong'  => ['label' => 'Lương cơ bản — Trưởng phòng',                     'type' => 'currency', 'unit' => 'đ'],
        'payroll.base_salary.nhan_vien'     => ['label' => 'Lương cơ bản — Nhân viên',                        'type' => 'currency', 'unit' => 'đ'],
        'payroll.annual_leave_f'            => ['label' => 'Phép năm mặc định',                               'type' => 'number',   'unit' => 'ngày'],
        'payroll.discipline_allowance_base' => ['label' => 'Phụ cấp kỷ luật (mức cơ sở)',                     'type' => 'currency', 'unit' => 'đ'],
        'payroll.allow_boc_hang'            => ['label' => 'Phụ cấp bốc hàng',                                'type' => 'currency', 'unit' => 'đ'],
        'payroll.allow_don_dep'             => ['label' => 'Phụ cấp dọn dẹp',                                 'type' => 'currency', 'unit' => 'đ'],
        'payroll.allow_hu_hong'             => ['label' => 'Phụ cấp hư hỏng/SP lỗi',                          'type' => 'currency', 'unit' => 'đ'],
        'payroll.violation_rate'            => ['label' => 'Mức trừ VPNQ (mỗi lần)',                          'type' => 'currency', 'unit' => 'đ'],
        'payroll.kpi_tier1_max'             => ['label' => 'Ngưỡng SP/NVSX — dưới bậc 1',                    'type' => 'number',   'unit' => 'SP/NVSX'],
        'payroll.kpi_tier2_max'             => ['label' => 'Ngưỡng SP/NVSX — dưới bậc 2',                    'type' => 'number',   'unit' => 'SP/NVSX'],
        'payroll.kpi_tier1_value'           => ['label' => 'Giá trị thành tích — Bậc 1',                      'type' => 'currency', 'unit' => 'đ'],
        'payroll.kpi_tier2_value'           => ['label' => 'Giá trị thành tích — Bậc 2',                      'type' => 'currency', 'unit' => 'đ'],
        'payroll.kpi_tier3_value'           => ['label' => 'Giá trị thành tích — Bậc 3',                      'type' => 'currency', 'unit' => 'đ'],
        'payroll.new_product_bonus_rate'    => ['label' => 'Thưởng SP mới (Trưởng phòng, mỗi SP)',           'type' => 'currency', 'unit' => 'đ'],
        'payroll.bhxh_base.truong_phong'    => ['label' => 'Lương đóng BHXH — Trưởng phòng',                  'type' => 'currency', 'unit' => 'đ'],
        'payroll.bhxh_base.nhan_vien'       => ['label' => 'Lương đóng BHXH — Nhân viên',                     'type' => 'currency', 'unit' => 'đ'],
        'payroll.bhxh_rate_company'         => ['label' => 'Tỷ lệ DN đóng BHXH',                              'type' => 'percent',  'unit' => '%'],
        'payroll.bhxh_rate_employee'        => ['label' => 'Tỷ lệ NLĐ đóng BHXH',                             'type' => 'percent',  'unit' => '%'],
        'payroll.company_name'              => ['label' => 'Tên công ty (dùng trong email)',                  'type' => 'text',     'unit' => ''],
        'payroll.signer_title'              => ['label' => 'Chức danh người ký (dùng trong email)',           'type' => 'text',     'unit' => ''],
    ];
}

/** Danh sách phụ cấp chức vụ (v) đã thiết lập cho từng nhân viên, kèm tên hiển thị. */
function py_all_position_allowances()
{
    py_ensure_tables();
    return db_fetch_array(
        "SELECT s.employee_id, s.position_allowance, e.full_name
         FROM payroll_employee_settings s
         LEFT JOIN employees e ON e.id = s.employee_id
         WHERE s.position_allowance <> 0
         ORDER BY e.full_name ASC"
    ) ?: [];
}

function py_save_setting($key, $value)
{
    $defaults = py_setting_defaults();
    if (!array_key_exists((string) $key, $defaults)) return false;
    return py_set_setting($key, $value);
}

function py_branch()
{
    return (string) py_get_setting('payroll.branch_filter', 'headquarters');
}

/* ============================================================
 *  Lịch ngày trong tháng
 * ============================================================ */
function py_weekday_vi($date)
{
    $n = (int) date('N', strtotime($date));
    $map = [1 => 'T2', 2 => 'T3', 3 => 'T4', 4 => 'T5', 5 => 'T6', 6 => 'T7', 7 => 'CN'];
    return $map[$n] ?? '';
}

function py_days_in_month($year, $month)
{
    return (int) date('t', mktime(0, 0, 0, (int) $month, 1, (int) $year));
}

function py_month_days($year, $month)
{
    $year  = (int) $year;
    $month = (int) $month;
    $days_in_month = py_days_in_month($year, $month);
    $today = date('Y-m-d');
    $out = [];
    for ($d = 1; $d <= $days_in_month; $d++) {
        $date = sprintf('%04d-%02d-%02d', $year, $month, $d);
        $out[] = [
            'date'      => $date,
            'day'       => $d,
            'weekday'   => py_weekday_vi($date),
            'is_sunday' => ((int) date('N', strtotime($date)) === 7),
            'is_today'  => ($date === $today),
            'is_past'   => ($date < $today),
            'is_future' => ($date > $today),
        ];
    }
    return $out;
}

/** b = số ngày trong tháng - số chủ nhật. */
function py_workdays_in_month($year, $month)
{
    $days = py_month_days($year, $month);
    $sundays = 0;
    foreach ($days as $d) {
        if ($d['is_sunday']) $sundays++;
    }
    return count($days) - $sundays;
}

/* ============================================================
 *  Nhân sự — Trụ sở chính (branch = setting 'payroll.branch_filter')
 * ============================================================ */
/** Trưởng phòng luôn đứng đầu, sau đó Nhân viên sắp theo thâm niên lâu nhất (hire_date sớm nhất) trước. */
/**
 * MỘT nhân viên với ĐÚNG hình dạng mà py_calc_employee_month() cần.
 *
 * BẪY ĐÃ DÍNH (12/8/2026): `SELECT * FROM employees` KHÔNG đủ — job_title nằm ở
 * employee_organization còn hire_date nằm ở employee_work_info. Thiếu job_title thì
 * py_calc_employee_month() thoát sớm với warning "Chưa cấu hình lương cho chức danh này"
 * và KHÔNG trả về kpi_o, nên đoạn tính lại cho mail lặng lẽ không làm gì cả.
 * Giữ ĐÚNG bộ cột của py_employees_for_branch() để 2 đường tính không bao giờ lệch.
 */
function py_employee_for_calc($employee_id)
{
    $eid = (int) $employee_id;
    if ($eid <= 0) return null;
    return db_fetch_row(
        "SELECT e.id, e.employee_code, e.full_name, e.email,
                w.hire_date, w.branch, w.employment_status,
                o.job_title
         FROM employees e
         LEFT JOIN employee_work_info w ON e.id = w.employee_id
         LEFT JOIN employee_organization o ON e.id = o.employee_id
         WHERE e.id = $eid LIMIT 1"
    );
}

function py_employees_for_branch($branch = null)
{
    $branch = $branch !== null ? $branch : py_branch();
    $b = escape_string($branch);
    $rows = db_fetch_array(
        "SELECT e.id, e.employee_code, e.full_name, e.email,
                w.hire_date, w.branch, w.employment_status,
                o.job_title
         FROM employees e
         LEFT JOIN employee_work_info w ON e.id = w.employee_id
         LEFT JOIN employee_organization o ON e.id = o.employee_id
         WHERE w.branch = '$b'
           AND (w.employment_status IS NULL OR w.employment_status IN ('active', 'on_leave'))"
    ) ?: [];

    usort($rows, function ($emp1, $emp2) {
        $is_manager1 = py_normalize_job_title($emp1['job_title'] ?? '') === 'truong_phong';
        $is_manager2 = py_normalize_job_title($emp2['job_title'] ?? '') === 'truong_phong';
        if ($is_manager1 !== $is_manager2) return $is_manager1 ? -1 : 1;

        $hire1 = strtotime((string) ($emp1['hire_date'] ?? ''));
        $hire2 = strtotime((string) ($emp2['hire_date'] ?? ''));
        $hire1 = $hire1 !== false ? $hire1 : PHP_INT_MAX;
        $hire2 = $hire2 !== false ? $hire2 : PHP_INT_MAX;
        if ($hire1 !== $hire2) return $hire1 <=> $hire2;

        return strcmp((string) $emp1['full_name'], (string) $emp2['full_name']);
    });

    return $rows;
}

function py_employee_row($employee_id)
{
    $eid = (int) $employee_id;
    return db_fetch_row(
        "SELECT e.id, e.employee_code, e.full_name, e.email,
                w.hire_date, w.branch, w.employment_status,
                o.job_title
         FROM employees e
         LEFT JOIN employee_work_info w ON e.id = w.employee_id
         LEFT JOIN employee_organization o ON e.id = o.employee_id
         WHERE e.id = $eid LIMIT 1"
    );
}

/**
 * Chuẩn hóa chức danh (job_title là text tự do nhập tay ở HR, casing có thể khác nhau,
 * vd "Trưởng Phòng" / "Trưởng phòng") -> 'truong_phong' | 'nhan_vien' | null (không hỗ trợ).
 */
function py_normalize_job_title($job_title)
{
    $s = mb_strtolower(trim((string) $job_title), 'UTF-8');
    if ($s === mb_strtolower('Trưởng phòng', 'UTF-8')) return 'truong_phong';
    if ($s === mb_strtolower('Nhân viên', 'UTF-8')) return 'nhan_vien';
    return null;
}

function py_is_supported_job_title($job_title)
{
    return py_normalize_job_title($job_title) !== null;
}

/* ============================================================
 *  Chấm công — đọc/ghi
 * ============================================================ */

/** [employee_id][date] => ['mark','reason_id','reason']. */
function py_timesheet_marks(array $employee_ids, $year, $month)
{
    $ids = array_values(array_unique(array_filter(array_map('intval', $employee_ids), static function ($v) {
        return $v > 0;
    })));
    if (empty($ids)) return [];
    $y = (int) $year;
    $m = (int) $month;
    $csv = implode(',', $ids);
    $rows = db_fetch_array(
        "SELECT t.employee_id, t.work_date, t.mark, t.reason_id, r.reason
         FROM payroll_timesheet_entries t
         LEFT JOIN payroll_off_reasons r ON r.id = t.reason_id
         WHERE t.employee_id IN ($csv) AND YEAR(t.work_date) = $y AND MONTH(t.work_date) = $m"
    ) ?: [];
    $out = [];
    foreach ($rows as $row) {
        $eid  = (int) $row['employee_id'];
        $date = (string) $row['work_date'];
        $out[$eid][$date] = [
            'mark'      => (string) $row['mark'],
            'reason_id' => $row['reason_id'] !== null ? (int) $row['reason_id'] : null,
            'reason'    => (string) ($row['reason'] ?? ''),
        ];
    }
    return $out;
}

/**
 * Mark hiệu lực của 1 ô (hiển thị trên lưới): không có dòng DB ->
 * 'x' nếu <= hôm nay VÀ không phải chủ nhật; chủ nhật mặc định luôn rỗng
 * (không tự đánh "x") — chỉ có mark khi user chủ động chấm (xem là tăng ca CN).
 */
function py_effective_mark($date, $today, $stored_mark, $is_sunday = false)
{
    if ($stored_mark !== null && $stored_mark !== '') return $stored_mark;
    if ($is_sunday) return '';
    return ($date <= $today) ? 'x' : '';
}

function py_set_timesheet_mark($employee_id, $date, $mark, $reason_id = null, $note = null, $user_id = 0)
{
    py_ensure_tables();
    $eid = (int) $employee_id;
    $d   = escape_string((string) $date);
    $mark = (string) $mark;
    if ($eid <= 0 || $d === '') return false;

    $is_sunday = ((int) date('N', strtotime((string) $date)) === 7);

    // "x" là mặc định CHỈ với ngày không phải chủ nhật -> xóa dòng để về mặc định.
    // Chủ nhật không có mặc định "x" (mặc định là không tính) nên "x" ở CN phải LƯU lại
    // (được xem là tăng ca chủ nhật — xem py_calc_effective_mark).
    if (($mark === 'x' && !$is_sunday) || $mark === '') {
        db_query("DELETE FROM payroll_timesheet_entries WHERE employee_id = $eid AND work_date = '$d'");
        return true;
    }
    if (!in_array($mark, ['x', 'half', 'off', 'holiday', 'dash'], true)) return false;

    $data = [
        'mark'       => $mark,
        'reason_id'  => ($reason_id !== null && (int) $reason_id > 0) ? (int) $reason_id : null,
        'note'       => ($note !== null && $note !== '') ? (string) $note : null,
        'updated_by' => (int) $user_id,
    ];
    $exists = db_num_rows("SELECT 1 FROM payroll_timesheet_entries WHERE employee_id = $eid AND work_date = '$d'") > 0;
    if ($exists) {
        db_update('payroll_timesheet_entries', $data, "employee_id = $eid AND work_date = '$d'");
    } else {
        $data['employee_id'] = $eid;
        $data['work_date']   = (string) $date;
        db_insert('payroll_timesheet_entries', $data);
    }
    return true;
}

/** Chấm "L" (Lễ/Tết) cho TOÀN BỘ nhân viên trong 1 ngày. */
function py_set_holiday_for_all($year, $month, $date, $user_id = 0)
{
    $employees = py_employees_for_branch();
    $n = 0;
    foreach ($employees as $emp) {
        if (py_set_timesheet_mark((int) $emp['id'], $date, 'holiday', null, null, $user_id)) $n++;
    }
    return $n;
}

/* ---------- Lý do off (CRUD nhỏ) ---------- */
function py_off_reasons_list()
{
    py_ensure_tables();
    return db_fetch_array(
        "SELECT id, reason, sort_order FROM payroll_off_reasons WHERE is_active = 1 ORDER BY sort_order ASC, id ASC"
    ) ?: [];
}

function py_off_reason_save($id, $reason, $sort_order = 0)
{
    py_ensure_tables();
    $id     = (int) $id;
    $reason = trim((string) $reason);
    if ($reason === '') return 0;
    $data = ['reason' => $reason, 'sort_order' => (int) $sort_order];
    if ($id > 0) {
        db_update('payroll_off_reasons', $data, "id = $id");
        return $id;
    }
    $data['is_active'] = 1;
    return (int) db_insert('payroll_off_reasons', $data);
}

/** Soft-delete (giữ lại lý do đã dùng trong lịch sử chấm công). */
function py_off_reason_delete($id)
{
    py_ensure_tables();
    $id = (int) $id;
    if ($id <= 0) return false;
    db_update('payroll_off_reasons', ['is_active' => 0], "id = $id");
    return true;
}

/* ============================================================
 *  Thống kê chấm công (1 nhân viên + toàn nhóm)
 * ============================================================ */

/**
 * Mark hiệu lực dùng cho TÍNH LƯƠNG (khác với mark hiển thị trên lưới chấm công):
 * mặc định 'x' cho MỌI ngày trong tháng (kể cả ngày tương lai — vì bảng lương giả định
 * đủ công cả tháng, chỉ trừ theo các ngoại lệ đã chấm), riêng chủ nhật mặc định KHÔNG
 * tính (như '-') vì đã bị trừ khỏi mẫu số "ngày làm việc trong tháng" (b) rồi.
 */
function py_calc_effective_mark($is_sunday, $stored_mark)
{
    if ($stored_mark !== null && $stored_mark !== '') {
        // Chủ nhật đánh "x" = user chủ động đi làm ngày CN -> xem là tăng ca,
        // KHÔNG tính vào công thường (c/d/g) — giống "dash".
        if ($is_sunday && $stored_mark === 'x') return 'dash';
        return $stored_mark;
    }
    return $is_sunday ? 'dash' : 'x';
}

/** c = số ngày x(1)+nửa công(0.5); d = số ngày lễ tết; g = off(1)+nửa công(0.5). */
function py_calc_attendance($employee_id, $year, $month, $marks_map = null)
{
    if ($marks_map === null) {
        $marks_map = py_timesheet_marks([(int) $employee_id], $year, $month);
    }
    $days = py_month_days($year, $month);
    $emp_marks = $marks_map[(int) $employee_id] ?? [];
    $c = 0.0; $d = 0.0; $g = 0.0;
    foreach ($days as $day) {
        $stored = $emp_marks[$day['date']]['mark'] ?? null;
        $mark = py_calc_effective_mark($day['is_sunday'], $stored);
        switch ($mark) {
            case 'x':       $c += 1; break;
            case 'half':    $c += 0.5; $g += 0.5; break;
            case 'off':     $g += 1; break;
            case 'holiday': $d += 1; break;
            // 'dash' -> không tính vào đâu cả
        }
    }
    return ['c' => $c, 'd' => $d, 'g' => $g];
}

/** Sản lượng tháng = SUM(finished_product_production_data.quantity) + SUM(sales_returns "Thay bao bì"),
 *  lọc thêm is_new_product nếu cần. 2026-07-17: đổi nguồn từ production_receipts (ghi lúc "Nhập giá vốn
 *  sản xuất") sang finished_product_production_data (ghi lúc "Nhập thành phẩm sản xuất" — nhập kho vật lý
 *  thật) để khớp đúng số ở modal/card "Sản lượng" trên daily_dashboard — 2 nguồn từng lệch nhau vì
 *  production_receipts ghi SL lúc costing, có thể trùng/thiếu so với SL nhập kho thật. */
/** Từ khoá nhận biết HÀNG MẪU trong tên sản phẩm. Hàng mẫu (gửi khách dùng thử) KHÔNG tính vào
 *  sản lượng: không vào danh sách, không cộng vào tổng. VD "Siro Ổi Safe King Mẫu 100ML",
 *  "Bột Sữa Royal VAT mẫu 100gr", "Lục Trà Hoa Nhài Mẫu 100Gr". */
function py_sample_keyword() { return 'mẫu'; }

/** Điều kiện SQL loại hàng mẫu. Xét CẢ product_name lẫn common_product_name (tên thường gọi) vì
 *  chỗ hiển thị dùng COALESCE(common, name) — hễ một trong hai có chữ "mẫu" là loại.
 *  COALESCE(...,'') bắt buộc: LEFT JOIN không khớp sẽ cho NULL, mà `NULL NOT LIKE` ra NULL nên
 *  dòng đó bị loại oan. LOWER() để không phân biệt hoa/thường ("Mẫu", "mẫu", "MẪU"). */
function py_exclude_sample_sql($alias = 'p')
{
    $kw = escape_string(py_sample_keyword());
    return " AND LOWER(COALESCE($alias.product_name, '')) NOT LIKE '%$kw%'"
         . " AND LOWER(COALESCE($alias.common_product_name, '')) NOT LIKE '%$kw%'";
}

function py_calc_output_qty($year, $month, $only_new = false)
{
    $y = (int) $year;
    $m = (int) $month;
    // Luôn JOIN products để lọc hàng mẫu. LEFT JOIN (không phải INNER) để dòng sản xuất nào không
    // tra được sản phẩm vẫn được tính như trước, tránh làm tụt sản lượng ngoài ý muốn.
    $join_new = $only_new
        ? "INNER JOIN products p ON p.id = t.product_id AND p.is_new_product = 1"
        : "LEFT JOIN products p ON p.id = t.product_id";
    $no_sample = py_exclude_sample_sql('p');

    $pr = db_fetch_row(
        "SELECT COALESCE(SUM(t.quantity), 0) AS q FROM finished_product_production_data t $join_new
         WHERE YEAR(t.created_at) = $y AND MONTH(t.created_at) = $m $no_sample"
    );
    $sr = db_fetch_row(
        "SELECT COALESCE(SUM(t.quantity), 0) AS q FROM sales_returns t $join_new
         WHERE t.handling_method = 'Thay bao bì' AND YEAR(t.created_at) = $y AND MONTH(t.created_at) = $m $no_sample"
    );
    return (float) ($pr['q'] ?? 0) + (float) ($sr['q'] ?? 0);
}

/** u = giá trị thành tích, tiered theo i. */
function py_calc_u($i)
{
    $t1 = (float) py_get_setting('payroll.kpi_tier1_max', 2600);
    $t2 = (float) py_get_setting('payroll.kpi_tier2_max', 3200);
    $v1 = (float) py_get_setting('payroll.kpi_tier1_value', 500);
    $v2 = (float) py_get_setting('payroll.kpi_tier2_value', 700);
    $v3 = (float) py_get_setting('payroll.kpi_tier3_value', 900);
    if ($i < $t1) return $v1;
    if ($i < $t2) return $v2;
    return $v3;
}

/** Sản lượng tháng, chi tiết theo từng sản phẩm (finished_product_production_data + sales_returns
 *  "Thay bao bì"). $only_new lọc riêng sản phẩm mới. Mirror nguồn VÀ luật loại hàng mẫu với
 *  py_calc_output_qty() — 2 hàm này phải luôn cùng luật, nếu không tổng ở thẻ sẽ lệch danh sách
 *  trong modal. */
function py_calc_output_by_product($year, $month, $only_new = false)
{
    $y = (int) $year;
    $m = (int) $month;
    $new_cond  = $only_new ? "AND p.is_new_product = 1" : "";
    $no_sample = py_exclude_sample_sql('p');

    $rows = db_fetch_array(
        "SELECT p.id AS product_id, p.product_name, p.common_product_name,
                COALESCE(SUM(t.quantity), 0) AS qty
         FROM finished_product_production_data t
         INNER JOIN products p ON p.id = t.product_id
         WHERE YEAR(t.created_at) = $y AND MONTH(t.created_at) = $m $new_cond $no_sample
         GROUP BY p.id, p.product_name, p.common_product_name"
    ) ?: [];
    $return_rows = db_fetch_array(
        "SELECT p.id AS product_id, p.product_name, p.common_product_name,
                COALESCE(SUM(t.quantity), 0) AS qty
         FROM sales_returns t
         INNER JOIN products p ON p.id = t.product_id
         WHERE t.handling_method = 'Thay bao bì' AND YEAR(t.created_at) = $y AND MONTH(t.created_at) = $m $new_cond $no_sample
         GROUP BY p.id, p.product_name, p.common_product_name"
    ) ?: [];

    $map = [];
    foreach ($rows as $r) {
        $pid  = (int) $r['product_id'];
        $name = trim((string) $r['common_product_name']) !== '' ? $r['common_product_name'] : $r['product_name'];
        $map[$pid] = ['product_id' => $pid, 'product_name' => (string) $name, 'qty' => (float) $r['qty']];
    }
    foreach ($return_rows as $r) {
        $pid  = (int) $r['product_id'];
        $name = trim((string) $r['common_product_name']) !== '' ? $r['common_product_name'] : $r['product_name'];
        if (!isset($map[$pid])) $map[$pid] = ['product_id' => $pid, 'product_name' => (string) $name, 'qty' => 0.0];
        $map[$pid]['qty'] += (float) $r['qty'];
    }

    $out = array_values($map);
    usort($out, function ($a, $b) { return $b['qty'] <=> $a['qty']; });
    return $out;
}

/** Số liệu dùng chung cho cả tháng: b, tổng công SX (Nhân viên), hệ số công, sản lượng, i, u, SL sản phẩm mới. */
function py_calc_monthly_stats($year, $month)
{
    $b = py_workdays_in_month($year, $month);
    $employees = py_employees_for_branch();
    $ids = array_column($employees, 'id');
    $marks_map = py_timesheet_marks($ids, $year, $month);

    $total_congs = 0.0;
    foreach ($employees as $emp) {
        if (py_normalize_job_title($emp['job_title']) !== 'nhan_vien') continue;
        $att = py_calc_attendance((int) $emp['id'], $year, $month, $marks_map);
        $total_congs += $att['c'];
    }
    $he_so_cong = $b > 0 ? $total_congs / $b : 0.0;

    $output_qty_raw  = py_calc_output_qty($year, $month, false);
    $output_adjustment = py_output_adjustments_sum($year, $month);
    $output_qty      = $output_qty_raw + $output_adjustment;
    $new_product_qty = py_calc_output_qty($year, $month, true);
    // SP/NVSX (i) làm tròn 0 chữ số thập phân, dùng giá trị đã làm tròn cho cả xét bậc thành tích lẫn tính thưởng KPI.
    $i = $he_so_cong > 0 ? round($output_qty / $he_so_cong) : 0.0;
    $u = py_calc_u($i);

    return [
        'b'                  => $b,
        'total_congs'        => $total_congs,
        'he_so_cong'         => $he_so_cong,
        'output_qty_raw'     => $output_qty_raw,
        'output_adjustment'  => $output_adjustment,
        'output_qty'         => $output_qty,
        'new_product_qty'    => $new_product_qty,
        'i'                  => $i,
        'u'                  => $u,
    ];
}

/* ============================================================
 *  Điều chỉnh sản lượng tháng (cộng/trừ tạm do lệch nhập liệu sản xuất)
 * ============================================================ */
function py_output_adjustments_list($year, $month)
{
    py_ensure_tables();
    $y = (int) $year; $m = (int) $month;
    return db_fetch_array(
        "SELECT id, amount, note, created_at FROM payroll_output_adjustments
         WHERE year = $y AND month = $m ORDER BY created_at ASC"
    ) ?: [];
}

function py_output_adjustments_sum($year, $month)
{
    py_ensure_tables();
    $y = (int) $year; $m = (int) $month;
    $row = db_fetch_row("SELECT COALESCE(SUM(amount), 0) AS s FROM payroll_output_adjustments WHERE year = $y AND month = $m");
    return $row ? (float) $row['s'] : 0.0;
}

/** $id > 0 -> sửa điều chỉnh đã có; $id <= 0 -> tạo mới. */
function py_output_adjustment_save($id, $year, $month, $amount, $note, $user_id = 0)
{
    py_ensure_tables();
    $id = (int) $id;
    $data = [
        'amount' => (float) $amount,
        'note'   => trim((string) $note) !== '' ? trim((string) $note) : null,
    ];
    if ($id > 0) {
        db_update('payroll_output_adjustments', $data, "id = $id");
        return $id;
    }
    $data['year']       = (int) $year;
    $data['month']      = (int) $month;
    $data['created_by'] = (int) $user_id;
    return (int) db_insert('payroll_output_adjustments', $data);
}

function py_output_adjustment_delete($id)
{
    py_ensure_tables();
    $id = (int) $id;
    if ($id <= 0) return false;
    db_query("DELETE FROM payroll_output_adjustments WHERE id = $id");
    return true;
}

/* ============================================================
 *  Phụ cấp chức vụ (v) — thiết lập 1 lần / nhân viên
 * ============================================================ */
function py_employee_position_allowance($employee_id)
{
    py_ensure_tables();
    $eid = (int) $employee_id;
    $row = db_fetch_row("SELECT position_allowance FROM payroll_employee_settings WHERE employee_id = $eid LIMIT 1");
    return $row ? (float) $row['position_allowance'] : 0.0;
}

function py_save_position_allowance($employee_id, $value)
{
    py_ensure_tables();
    $eid = (int) $employee_id;
    if ($eid <= 0) return false;
    $v = (float) $value;
    $exists = db_num_rows("SELECT 1 FROM payroll_employee_settings WHERE employee_id = $eid") > 0;
    if ($exists) {
        db_update('payroll_employee_settings', ['position_allowance' => $v], "employee_id = $eid");
    } else {
        db_insert('payroll_employee_settings', ['employee_id' => $eid, 'position_allowance' => $v]);
    }
    return true;
}

/* ============================================================
 *  Thâm niên
 * ============================================================ */

/**
 * p = số tháng làm việc, tính theo mốc THÁNG (không xét ngày trong tháng):
 * tháng vào làm luôn được tính trọn 1 tháng, tháng đang xử lý (tháng/năm truyền vào)
 * KHÔNG được tính (chưa hoàn tất) — vd vào làm 10/2019, tính tới 7/2026
 * => (2026-2019)*12 + (7-10) = 81 tháng.
 */
function py_calc_seniority_months($hire_date, $year, $month)
{
    $hire_date = trim((string) $hire_date);
    if ($hire_date === '' || $hire_date === '0000-00-00') return 0;
    $hire_ts = strtotime($hire_date);
    if (!$hire_ts) return 0;

    $hire_y = (int) date('Y', $hire_ts);
    $hire_m = (int) date('n', $hire_ts);
    $hire_d = (int) date('j', $hire_ts);

    // Vào làm ngày <=15 -> tính luôn tháng đó; ngày >15 -> tính từ tháng kế tiếp.
    if ($hire_d > 15) {
        $hire_m += 1;
        if ($hire_m > 12) { $hire_m = 1; $hire_y += 1; }
    }

    $year  = (int) $year;
    $month = (int) $month;

    $months = ($year - $hire_y) * 12 + ($month - $hire_m);
    $months += 1; // +1 theo yêu cầu, cộng thêm vào kết quả của logic hiện tại
    return max(0, $months);
}

/* ============================================================
 *  Engine tính lương 1 nhân viên / 1 tháng
 * ============================================================ */

/**
 * $overrides (ephemeral, chưa lưu cho tới khi bấm "Lưu"):
 *   ot_hours, violation_count, advance_amount, other_bonus, other_bonus_note,
 *   bhxh_company (override), bhxh_employee (override).
 */
function py_calc_employee_month(array $employee, $year, $month, array $overrides = [], array $monthly_stats = null)
{
    if ($monthly_stats === null) $monthly_stats = py_calc_monthly_stats($year, $month);

    $job_title = trim((string) ($employee['job_title'] ?? ''));
    $jt_norm = py_normalize_job_title($job_title);
    $result = [
        'employee_id' => (int) $employee['id'],
        'full_name'   => (string) ($employee['full_name'] ?? ''),
        'email'       => (string) ($employee['email'] ?? ''),
        'hire_date'   => (string) ($employee['hire_date'] ?? ''),
        'job_title'   => $job_title,
        'supported'   => $jt_norm !== null,
    ];
    if (!$result['supported']) {
        $result['warning'] = 'Chưa cấu hình lương cho chức danh này';
        return $result;
    }

    // Các trường user tự nhập (VPNQ, giờ tăng ca, tiền ứng, thưởng khác) không có "mặc định" nào khác
    // ngoài lần lưu gần nhất — nếu request này không truyền override cho 1 trường, lấy lại giá trị đã
    // lưu trước đó (thay vì luôn về 0), để chuyển qua lại giữa các tháng / tải lại trang không mất dữ liệu.
    $saved = py_saved_employee_entry((int) $employee['id'], $year, $month);

    $b = (float) $monthly_stats['b'];
    $att = py_calc_attendance((int) $employee['id'], $year, $month);
    $c = $att['c']; $d = $att['d']; $g = $att['g'];
    $f = (float) py_get_setting('payroll.annual_leave_f', 1);
    $h = $c + $d + $f;

    $a = $jt_norm === 'truong_phong'
        ? (float) py_get_setting('payroll.base_salary.truong_phong', 6500000)
        : (float) py_get_setting('payroll.base_salary.nhan_vien', 4420000);

    $r = $b > 0 ? round(($a / $b) * $h) : 0.0;

    // g > 1 (off nhiều) -> trả theo tỉ lệ công thực tế (setting/b)*c (bị giảm so với mức đủ).
    // g <= 1 (không off / gần như không off) -> hưởng ĐỦ mức cài đặt, không quy đổi tỉ lệ.
    $gate = ($g > 1 && $b > 0);
    $discipline_base = (float) py_get_setting('payroll.discipline_allowance_base', 300000);
    $j = $b > 0 ? ($gate ? round(($discipline_base / $b) * $c) : $discipline_base) : 0.0;

    $v = py_employee_position_allowance((int) $employee['id']);
    $k = $b > 0 ? ($gate ? round(($v / $b) * $c) : $v) : 0.0;
    $l = $j + $k;

    // 3 khoản phụ cấp cố định (bốc hàng/dọn dẹp/hư hỏng) — tách riêng để hiển thị từng dòng, tổng = y1.
    $allow_boc_hang = 0.0; $allow_don_dep = 0.0; $allow_hu_hong = 0.0;
    if ($b > 0) {
        $base_boc_hang = (float) py_get_setting('payroll.allow_boc_hang', 200000);
        $base_don_dep  = (float) py_get_setting('payroll.allow_don_dep', 100000);
        $base_hu_hong  = (float) py_get_setting('payroll.allow_hu_hong', 100000);
        $allow_boc_hang = $gate ? round($base_boc_hang / $b * $c) : $base_boc_hang;
        $allow_don_dep  = $gate ? round($base_don_dep / $b * $c) : $base_don_dep;
        $allow_hu_hong  = $gate ? round($base_hu_hong / $b * $c) : $base_hu_hong;
    }
    $y1 = $allow_boc_hang + $allow_don_dep + $allow_hu_hong;

    $ot_hours = (float) ($overrides['ot_hours'] ?? ($saved['ot_hours'] ?? 0));
    $ot_total = $b > 0 ? ($a / $b / 8) * $ot_hours : 0.0;

    $violation_count = (int) ($overrides['violation_count'] ?? ($saved['violation_count_t'] ?? 0));
    $t = $violation_count * (float) py_get_setting('payroll.violation_rate', 50000);

    $p = py_calc_seniority_months((string) ($employee['hire_date'] ?? ''), $year, $month);
    $y2 = (int) floor($p / 6);
    // "hệ số thâm niên" = 1 + y2*0.05 (đúng nghĩa đen của spec, y2 làm tròn xuống).
    $seniority_coeff = 1 + $y2 * 0.05;

    $i = (float) $monthly_stats['i'];
    $u = (float) $monthly_stats['u'];
    // o_base = i*u*hệ_số_thâm_niên (hệ số thâm niên = 1+y2*0.05 đã có sẵn "+1" trong định nghĩa —
    // không cộng thêm 1 lần nữa), làm tròn 0 chữ số thập phân.
    $o_base = round($i * $u * $seniority_coeff);
    // o cuối cùng = o_base * (số ngày đi làm / số ngày làm việc trong tháng) — quy đổi theo tỉ lệ chuyên cần.
    $o = $b > 0 ? round($o_base * $c / $b) : 0.0;

    $new_product_bonus = 0.0;
    if ($jt_norm === 'truong_phong') {
        $new_product_bonus = round(((float) $monthly_stats['new_product_qty']) * (float) py_get_setting('payroll.new_product_bonus_rate', 500));
        $o += $new_product_bonus;
    }

    $kpi_total = $y1 + $o - $t;

    $bhxh_base = $jt_norm === 'truong_phong'
        ? (float) py_get_setting('payroll.bhxh_base.truong_phong', 6420000)
        : (float) py_get_setting('payroll.bhxh_base.nhan_vien', 5310000);
    $rate_company  = (float) py_get_setting('payroll.bhxh_rate_company', 21.5);
    $rate_employee = (float) py_get_setting('payroll.bhxh_rate_employee', 10.5);
    $bhxh_company_auto  = round($bhxh_base * ($rate_company / 100));
    $bhxh_employee_auto = round($bhxh_base * ($rate_employee / 100));
    $bhxh_company  = (isset($overrides['bhxh_company'])  && $overrides['bhxh_company']  !== '') ? (float) $overrides['bhxh_company']  : $bhxh_company_auto;
    $bhxh_employee = (isset($overrides['bhxh_employee']) && $overrides['bhxh_employee'] !== '') ? (float) $overrides['bhxh_employee'] : $bhxh_employee_auto;

    $total_with_bhxh  = $r + $l + $kpi_total + $ot_total + $bhxh_company - $bhxh_employee;
    $total_after_bhxh = $total_with_bhxh - $bhxh_company;

    $advance          = (float) ($overrides['advance_amount'] ?? ($saved['advance_amount'] ?? 0));
    $other_bonus       = (float) ($overrides['other_bonus'] ?? ($saved['other_bonus'] ?? 0));
    $other_bonus_note  = (string) ($overrides['other_bonus_note'] ?? ($saved['other_bonus_note'] ?? ''));
    $net_pay = $total_after_bhxh - $advance + $other_bonus;

    return $result + [
        'months_worked_p'       => $p,
        'seniority_coefficient' => $seniority_coeff,
        'base_salary_a'         => $a,
        'workdays_in_month_b'   => $b,
        'days_worked_c'         => $c,
        'holiday_days_d'        => $d,
        'annual_leave_f'        => $f,
        'off_days_g'            => $g,
        'total_days_h'          => $h,
        'base_total_r'          => $r,
        'discipline_allow_j'    => $j,
        'position_allow_base_v' => $v,
        'position_allow_k'      => $k,
        'total_allow_l'         => $l,
        'flat_allow_y1'         => $y1,
        'allow_boc_hang'        => $allow_boc_hang,
        'allow_don_dep'         => $allow_don_dep,
        'allow_hu_hong'         => $allow_hu_hong,
        'ot_hours'              => $ot_hours,
        'ot_total'              => $ot_total,
        'violation_count_t'     => $violation_count,
        'violation_deduct_t'    => $t,
        'kpi_o'                 => $o,
        'kpi_i'                 => $i,
        'kpi_u'                 => $u,
        'kpi_o_base'            => $o_base,
        'new_product_bonus'     => $new_product_bonus,
        'kpi_total'             => $kpi_total,
        'bhxh_base'             => $bhxh_base,
        'bhxh_company'          => $bhxh_company,
        'bhxh_company_auto'     => $bhxh_company_auto,
        'bhxh_employee'         => $bhxh_employee,
        'bhxh_employee_auto'    => $bhxh_employee_auto,
        'total_with_bhxh'       => $total_with_bhxh,
        'total_after_bhxh'      => $total_after_bhxh,
        'advance_amount'        => $advance,
        'other_bonus'           => $other_bonus,
        'other_bonus_note'      => $other_bonus_note,
        'net_pay'               => $net_pay,
    ];
}

/** Danh sách cột lưu vào payroll_monthly_entries (khớp key trả về của py_calc_employee_month). */
function py_entry_columns()
{
    return [
        'job_title', 'months_worked_p', 'seniority_coefficient', 'base_salary_a', 'workdays_in_month_b',
        'days_worked_c', 'holiday_days_d', 'annual_leave_f', 'off_days_g', 'total_days_h', 'base_total_r',
        'discipline_allow_j', 'position_allow_base_v', 'position_allow_k', 'total_allow_l', 'flat_allow_y1',
        'allow_boc_hang', 'allow_don_dep', 'allow_hu_hong',
        'ot_hours', 'ot_total', 'violation_count_t', 'violation_deduct_t',
        'kpi_o', 'kpi_i', 'kpi_u', 'kpi_o_base', 'new_product_bonus', 'kpi_total',
        'bhxh_base', 'bhxh_company', 'bhxh_company_auto', 'bhxh_employee', 'bhxh_employee_auto',
        'total_with_bhxh', 'total_after_bhxh', 'advance_amount', 'other_bonus', 'other_bonus_note', 'net_pay',
    ];
}

/* ============================================================
 *  Lưu / lịch sử
 * ============================================================ */
function py_check_already_saved($year, $month)
{
    py_ensure_tables();
    $y = (int) $year; $m = (int) $month;
    return db_num_rows("SELECT 1 FROM payroll_monthly_summary WHERE year = $y AND month = $m") > 0;
}

/** Giá trị các trường user tự nhập (VPNQ, giờ tăng ca, tiền ứng, thưởng khác) đã lưu gần nhất cho 1 nhân viên/tháng, nếu có. */
function py_saved_employee_entry($employee_id, $year, $month)
{
    $eid = (int) $employee_id; $y = (int) $year; $m = (int) $month;
    return db_fetch_row(
        "SELECT ot_hours, violation_count_t, advance_amount, other_bonus, other_bonus_note
         FROM payroll_monthly_entries WHERE employee_id = $eid AND year = $y AND month = $m LIMIT 1"
    );
}

/**
 * $employee_overrides: [employee_id => [ot_hours, violation_count, advance_amount, other_bonus, other_bonus_note, bhxh_company, bhxh_employee]]
 */
function py_save_month($year, $month, array $employee_overrides, $user_id = 0)
{
    py_ensure_tables();
    $y = (int) $year; $m = (int) $month;
    $monthly_stats = py_calc_monthly_stats($y, $m);

    $summary_data = [
        'year'                    => $y,
        'month'                   => $m,
        'workdays_in_month'       => $monthly_stats['b'],
        'total_production_congs'  => $monthly_stats['total_congs'],
        'cong_coefficient'        => $monthly_stats['he_so_cong'],
        'output_qty'              => $monthly_stats['output_qty'],
        'output_per_cong_i'       => $monthly_stats['i'],
        'achievement_value_u'     => $monthly_stats['u'],
        'new_product_qty'         => $monthly_stats['new_product_qty'],
    ];
    $exists_summary = db_num_rows("SELECT 1 FROM payroll_monthly_summary WHERE year = $y AND month = $m") > 0;
    if ($exists_summary) {
        db_update('payroll_monthly_summary', $summary_data, "year = $y AND month = $m");
    } else {
        db_insert('payroll_monthly_summary', $summary_data);
    }

    $columns = py_entry_columns();
    $employees = py_employees_for_branch();
    $saved = 0;
    $skipped = [];
    foreach ($employees as $emp) {
        $eid = (int) $emp['id'];
        $overrides = $employee_overrides[$eid] ?? [];
        $calc = py_calc_employee_month($emp, $y, $m, $overrides, $monthly_stats);
        if (empty($calc['supported'])) {
            $skipped[] = ['employee_id' => $eid, 'full_name' => $emp['full_name'], 'job_title' => $emp['job_title']];
            continue;
        }
        $row = [];
        foreach ($columns as $col) {
            $row[$col] = $calc[$col];
        }
        $row['employee_id'] = $eid;
        $row['year']  = $y;
        $row['month'] = $m;

        $exists_entry = db_num_rows("SELECT 1 FROM payroll_monthly_entries WHERE employee_id = $eid AND year = $y AND month = $m") > 0;
        if ($exists_entry) {
            db_update('payroll_monthly_entries', $row, "employee_id = $eid AND year = $y AND month = $m");
        } else {
            db_insert('payroll_monthly_entries', $row);
        }
        $saved++;
    }
    return ['saved' => $saved, 'skipped' => $skipped];
}

function py_load_saved_month($year, $month)
{
    py_ensure_tables();
    $y = (int) $year; $m = (int) $month;
    $summary = db_fetch_row("SELECT * FROM payroll_monthly_summary WHERE year = $y AND month = $m LIMIT 1");
    $entries = db_fetch_array(
        "SELECT en.*, e.full_name, e.email, w.hire_date
         FROM payroll_monthly_entries en
         LEFT JOIN employees e ON e.id = en.employee_id
         LEFT JOIN employee_work_info w ON w.employee_id = en.employee_id
         WHERE en.year = $y AND en.month = $m"
    ) ?: [];

    // Cùng thứ tự với bảng đang tính live: Trưởng phòng trước, sau đó Nhân viên theo thâm niên lâu nhất.
    usort($entries, function ($emp1, $emp2) {
        $is_manager1 = py_normalize_job_title($emp1['job_title'] ?? '') === 'truong_phong';
        $is_manager2 = py_normalize_job_title($emp2['job_title'] ?? '') === 'truong_phong';
        if ($is_manager1 !== $is_manager2) return $is_manager1 ? -1 : 1;

        $hire1 = strtotime((string) ($emp1['hire_date'] ?? ''));
        $hire2 = strtotime((string) ($emp2['hire_date'] ?? ''));
        $hire1 = $hire1 !== false ? $hire1 : PHP_INT_MAX;
        $hire2 = $hire2 !== false ? $hire2 : PHP_INT_MAX;
        if ($hire1 !== $hire2) return $hire1 <=> $hire2;

        return strcmp((string) $emp1['full_name'], (string) $emp2['full_name']);
    });

    return ['summary' => $summary ?: null, 'employees' => $entries];
}

/* ============================================================
 *  Email bảng lương (từ snapshot đã Lưu)
 * ============================================================ */
function py_off_days_with_reasons($employee_id, $year, $month)
{
    $eid = (int) $employee_id; $y = (int) $year; $m = (int) $month;
    return db_fetch_array(
        "SELECT t.work_date, t.mark, r.reason
         FROM payroll_timesheet_entries t
         LEFT JOIN payroll_off_reasons r ON r.id = t.reason_id
         WHERE t.employee_id = $eid AND YEAR(t.work_date) = $y AND MONTH(t.work_date) = $m
           AND t.mark IN ('off', 'half')
         ORDER BY t.work_date ASC"
    ) ?: [];
}

function py_money($n)
{
    return number_format((float) $n, 0, ',', '.') . ' đ';
}

/* ---------- Helpers dựng HTML email (style inline để tương thích trình đọc email) ---------- */
function py_email_section_title($text)
{
    return '<tr><td colspan="2" style="padding:18px 0 8px;">'
        . '<div style="background:#f0f6f2;border-left:4px solid #096926;padding:8px 14px;font-size:13.5px;font-weight:700;color:#096926;border-radius:0 4px 4px 0;">'
        . htmlspecialchars($text) . '</div></td></tr>';
}

function py_email_row($label, $value, $opts = [])
{
    $bold      = !empty($opts['bold']);
    $highlight = !empty($opts['highlight']);
    $bg        = $highlight ? '#e8f3ec' : '#fff';
    $fw        = ($bold || $highlight) ? '700' : '400';
    $fs        = $highlight ? '15px' : '13px';
    $color     = $highlight ? '#096926' : '#222';
    return '<tr>'
        . '<td style="padding:7px 12px;border-bottom:1px solid #eef0f2;background:' . $bg . ';color:#555;font-size:13px;">' . htmlspecialchars($label) . '</td>'
        . '<td style="padding:7px 12px;border-bottom:1px solid #eef0f2;background:' . $bg . ';text-align:right;font-weight:' . $fw . ';font-size:' . $fs . ';color:' . $color . ';">' . $value . '</td>'
        . '</tr>';
}

function py_email_table_open()
{
    return '<table style="width:100%;border-collapse:collapse;background:#fff;border:1px solid #eef0f2;border-radius:6px;overflow:hidden;">';
}

function py_build_payslip_email_html(array $employee, array $entry, array $summary, array $off_days, $year, $month)
{
    $company = (string) py_get_setting('payroll.company_name', 'CÔNG TY TNHH VUA AN TOÀN');
    $signer  = (string) py_get_setting('payroll.signer_title', 'TRƯỞNG PHÒNG SẢN XUẤT');
    $today_vi = 'ngày ' . date('j') . ' tháng ' . date('n') . ' năm ' . date('Y');
    $full_name = (string) $employee['full_name'];

    $off_rows = '';
    if (!empty($off_days)) {
        foreach ($off_days as $od) {
            $label = $od['mark'] === 'half' ? 'Nửa công' : 'Off';
            $reason = trim((string) ($od['reason'] ?? ''));
            $off_rows .= '<tr>'
                . '<td style="padding:6px 12px;border-bottom:1px solid #eef0f2;font-size:13px;">' . htmlspecialchars((string) $od['work_date']) . '</td>'
                . '<td style="padding:6px 12px;border-bottom:1px solid #eef0f2;font-size:13px;">' . htmlspecialchars($label) . '</td>'
                . '<td style="padding:6px 12px;border-bottom:1px solid #eef0f2;font-size:13px;color:#666;">' . ($reason !== '' ? htmlspecialchars($reason) : '—') . '</td>'
                . '</tr>';
        }
    } else {
        $off_rows = '<tr><td colspan="3" style="padding:10px 12px;font-size:13px;color:#888;font-style:italic;">Không có ngày off trong tháng — chuyên cần đầy đủ.</td></tr>';
    }

    $violation_line = (int) $entry['violation_count_t'] > 0
        ? py_money($entry['violation_deduct_t']) . ' (' . (int) $entry['violation_count_t'] . ' lần)'
        : py_money(0);
    $other_bonus_line = py_money($entry['other_bonus']);
    if (trim((string) $entry['other_bonus_note']) !== '') {
        $other_bonus_line .= '<div style="font-size:11px;color:#888;font-weight:400;margin-top:2px;">' . htmlspecialchars((string) $entry['other_bonus_note']) . '</div>';
    }
    $new_product_note = (float) $entry['new_product_bonus'] > 0
        ? '<div style="font-size:11px;color:#888;font-weight:400;margin-top:2px;">(đã gồm thưởng SP mới ' . py_money($entry['new_product_bonus']) . ')</div>'
        : '';

    ob_start();
    ?>
    <div style="max-width:640px;margin:0 auto;font-family:Arial,Helvetica,sans-serif;color:#222;">
        <div style="background:linear-gradient(135deg,#096926,#128140);padding:22px 26px;border-radius:10px 10px 0 0;">
            <div style="color:rgba(255,255,255,.8);font-size:11px;letter-spacing:1.5px;text-transform:uppercase;"><?php echo htmlspecialchars($company); ?></div>
            <div style="color:#fff;font-size:21px;font-weight:700;margin-top:6px;">BẢNG LƯƠNG THÁNG <?php echo (int) $month; ?>/<?php echo (int) $year; ?></div>
            <div style="color:rgba(255,255,255,.9);font-size:13px;margin-top:4px;"><?php echo htmlspecialchars($full_name); ?> — <?php echo htmlspecialchars((string) $entry['job_title']); ?></div>
        </div>

        <div style="border:1px solid #eef0f2;border-top:none;border-radius:0 0 10px 10px;padding:24px 26px;background:#fbfcfb;">
            <p style="font-size:13.5px;line-height:1.6;">Chào <b><?php echo htmlspecialchars($full_name); ?></b>,</p>
            <p style="font-size:13.5px;line-height:1.6;">
                Hôm nay <?php echo $today_vi; ?>, <?php echo htmlspecialchars($company); ?> thống kê tình hình sản xuất, chấm công
                và tính lương tháng <?php echo (int) $month; ?>/<?php echo (int) $year; ?> của bạn với các nội dung sau:
            </p>

            <table style="width:100%;border-collapse:collapse;margin-top:6px;">
                <?php echo py_email_section_title('1. Thống kê công của toàn nhóm'); ?>
            </table>
            <?php echo py_email_table_open(); ?>
                <?php
                echo py_email_row('Ngày làm trong tháng', (string) round((float) $summary['workdays_in_month'], 1));
                echo py_email_row('Tổng công sản xuất', (string) round((float) $summary['total_production_congs'], 1));
                echo py_email_row('Hệ số công', (string) round((float) $summary['cong_coefficient'], 3));
                ?>
            </table>

            <table style="width:100%;border-collapse:collapse;">
                <?php echo py_email_section_title('2. Thành tích của nhóm'); ?>
            </table>
            <?php echo py_email_table_open(); ?>
                <?php
                echo py_email_row('Sản lượng tháng này', (string) round((float) $summary['output_qty'], 1));
                echo py_email_row('SP/NVSX', (string) round((float) $summary['output_per_cong_i'], 0));
                echo py_email_row('Thành tích', py_money($summary['achievement_value_u']));
                ?>
            </table>

            <table style="width:100%;border-collapse:collapse;">
                <?php echo py_email_section_title('3. Bảng lương chi tiết'); ?>
            </table>
            <?php echo py_email_table_open(); ?>
                <?php
                echo py_email_row('Chức vụ', htmlspecialchars((string) $entry['job_title']));
                echo py_email_row('Số tháng làm việc', (int) $entry['months_worked_p'] . ' tháng');
                echo py_email_row('Thâm niên (hệ số)', number_format((float) $entry['seniority_coefficient'], 2));
                echo py_email_row('Hình thức', 'NV chính thức');
                echo py_email_row('BHXH', 'Đã tham gia');
                echo py_email_row('Lương cơ bản', py_money($entry['base_salary_a']));
                echo py_email_row('Số ngày làm việc trong tháng', (string) round((float) $entry['workdays_in_month_b'], 1));
                echo py_email_row('Số ngày đi làm', (string) round((float) $entry['days_worked_c'], 1));
                echo py_email_row('Số ngày lễ/tết', (string) round((float) $entry['holiday_days_d'], 1));
                echo py_email_row('Phép năm', (string) round((float) $entry['annual_leave_f'], 1));
                echo py_email_row('Số ngày off', (string) round((float) $entry['off_days_g'], 1));
                echo py_email_row('Tổng số ngày tính lương', (string) round((float) $entry['total_days_h'], 1), ['bold' => true]);
                echo py_email_row('Tổng lương cơ bản', py_money($entry['base_total_r']), ['bold' => true]);

                echo py_email_row('Phụ cấp kỷ luật', py_money($entry['discipline_allow_j']));
                echo py_email_row('Phụ cấp chức vụ', py_money($entry['position_allow_k']));
                echo py_email_row('Tổng phụ cấp', py_money($entry['total_allow_l']), ['bold' => true]);

                echo py_email_row('Phụ cấp bốc hàng/dọn dẹp/hư hỏng', py_money($entry['flat_allow_y1']));
                echo py_email_row('Thưởng KPI sản lượng', py_money($entry['kpi_o']) . $new_product_note);
                echo py_email_row('VPNQ', '-' . $violation_line);
                echo py_email_row('Tổng thưởng KPI', py_money($entry['kpi_total']), ['bold' => true]);

                echo py_email_row('Số giờ tăng ca', round((float) $entry['ot_hours'], 1) . ' giờ');
                echo py_email_row('Tổng tiền tăng ca', py_money($entry['ot_total']));

                echo py_email_row('Tiền DN nộp BHXH cho NLĐ', py_money($entry['bhxh_company']));
                echo py_email_row('Tiền NLĐ nộp BHXH', py_money($entry['bhxh_employee']));
                echo py_email_row('Tổng lương gồm BHXH', py_money($entry['total_with_bhxh']), ['bold' => true]);
                echo py_email_row('Tổng lương sau BHXH', py_money($entry['total_after_bhxh']), ['bold' => true]);
                echo py_email_row('Tiền ứng tháng này', py_money($entry['advance_amount']));
                echo py_email_row('Thưởng khác', $other_bonus_line);
                echo py_email_row('THỰC LÃNH', py_money($entry['net_pay']), ['highlight' => true]);
                ?>
            </table>

            <table style="width:100%;border-collapse:collapse;">
                <?php echo py_email_section_title('Ngày off trong tháng'); ?>
            </table>
            <table style="width:100%;border-collapse:collapse;background:#fff;border:1px solid #eef0f2;border-radius:6px;overflow:hidden;">
                <tr>
                    <th style="padding:7px 12px;background:#f5f7fa;text-align:left;font-size:12px;color:#555;">Ngày</th>
                    <th style="padding:7px 12px;background:#f5f7fa;text-align:left;font-size:12px;color:#555;">Loại</th>
                    <th style="padding:7px 12px;background:#f5f7fa;text-align:left;font-size:12px;color:#555;">Lý do</th>
                </tr>
                <?php echo $off_rows; ?>
            </table>

            <p style="font-size:12.5px;color:#555;line-height:1.6;margin-top:22px;">
                Mọi thắc mắc, khiếu nại về các thông tin trên xin liên hệ với <b><?php echo htmlspecialchars($signer); ?></b> của
                <?php echo htmlspecialchars($company); ?> để được xử lý.
            </p>
            <p style="font-size:12.5px;color:#555;line-height:1.6;">
                Nếu không có khiếu nại gì thêm, lương sẽ được chuyển khoản trong giai đoạn từ ngày 10 đến ngày 12 của tháng này.
            </p>

            <div style="margin-top:26px;text-align:right;">
                <div style="font-size:12.5px;color:#555;"><?php echo $today_vi; ?></div>
                <div style="font-size:13px;font-weight:700;margin-top:36px;color:#096926;"><?php echo htmlspecialchars(mb_strtoupper($signer, 'UTF-8')); ?></div>
                <div style="font-size:11px;color:#999;">(Ký, ghi rõ họ tên)</div>
            </div>
        </div>

        <div style="text-align:center;font-size:11px;color:#aaa;padding:14px 10px;">
            Email tự động từ hệ thống quản lý lương — vui lòng không trả lời email này.
        </div>
    </div>
    <?php
    return ob_get_clean();
}

function py_send_payslip_email($employee_id, $year, $month)
{
    $eid = (int) $employee_id; $y = (int) $year; $m = (int) $month;
    $emp = db_fetch_row("SELECT id, full_name, email FROM employees WHERE id = $eid LIMIT 1");
    if (!$emp || trim((string) $emp['email']) === '') {
        return ['success' => false, 'message' => 'Nhân viên chưa có email.'];
    }

    $summary = db_fetch_row("SELECT * FROM payroll_monthly_summary WHERE year = $y AND month = $m LIMIT 1");
    $entry   = db_fetch_row("SELECT * FROM payroll_monthly_entries WHERE employee_id = $eid AND year = $y AND month = $m LIMIT 1");
    if (!$summary || !$entry) {
        return ['success' => false, 'message' => 'Tháng này chưa được lưu bảng lương.'];
    }

    /* =====================================================================================
       TÍNH LẠI PHẦN SẢN LƯỢNG / KPI ĐÚNG NHƯ TRÊN VIEW (vá 11/8/2026).
       -------------------------------------------------------------------------------------
       Trang /payroll/payslip tính LIVE mỗi lần mở, còn mail thì đọc số ĐÃ ĐÓNG BĂNG trong
       payroll_monthly_summary / payroll_monthly_entries. Nên sau khi luật tính sản lượng đổi
       (05/8/2026 thêm py_exclude_sample_sql() loại hàng "Mẫu"), snapshot cũ giữ số cũ và mail
       gửi đi lệch với màn hình — kéo theo lệch cả Tổng thưởng KPI và THỰC LÃNH.

       CHỈ thay đúng chuỗi số phụ thuộc sản lượng, KHÔNG thay cả bảng: mọi số do người dùng nhập
       tay (tăng ca, tiền ứng, thưởng khác, BHXH tự nhập) vẫn giữ nguyên bản đã lưu.
       py_calc_employee_month() với $overrides rỗng tự lùi về chính các giá trị đã lưu trong
       payroll_monthly_entries, nên tính lại KHÔNG làm mất số nhập tay.
       ===================================================================================== */
    $emp_full = py_employee_for_calc($eid);
    if ($emp_full) {
        $stats = py_calc_monthly_stats($y, $m);
        $live  = py_calc_employee_month($emp_full, $y, $m, [], $stats);

        // Khối "Thành tích của nhóm" trong mail đọc theo tên cột của bảng snapshot.
        $summary['output_qty']          = $stats['output_qty'];
        $summary['output_per_cong_i']   = $stats['i'];
        $summary['achievement_value_u'] = $stats['u'];
        $summary['new_product_qty']     = $stats['new_product_qty'];

        // Thưởng KPI sản lượng và các tổng phụ thuộc nó.
        // Chỉ ghi đè khi $live tính được thật (thiếu cấu hình chức danh thì nó chỉ trả về
        // {employee_id, full_name, warning} — lúc đó giữ nguyên số đã lưu còn hơn ghi số rỗng).
        if (!isset($live['warning']) && array_key_exists('kpi_o', $live)) {
            foreach ([
                'kpi_o', 'kpi_i', 'kpi_u', 'kpi_o_base', 'new_product_bonus', 'kpi_total',
                'total_with_bhxh', 'total_after_bhxh', 'net_pay',
            ] as $k) {
                if (array_key_exists($k, $live)) $entry[$k] = $live[$k];
            }
        }
    }

    $off_days = py_off_days_with_reasons($eid, $y, $m);
    $html = py_build_payslip_email_html($emp, $entry, $summary, $off_days, $y, $m);
    $subject = 'Bảng lương tháng ' . $m . '/' . $y . ' - ' . $emp['full_name'];
    $ok = function_exists('send_mail') ? send_mail($emp['email'], $emp['full_name'], $subject, $html) : false;

    return ['success' => (bool) $ok, 'message' => $ok ? 'Đã gửi email.' : 'Gửi email thất bại.'];
}
