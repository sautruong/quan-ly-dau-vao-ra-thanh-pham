<?php
// Hint cho IDE (PHP Intelephense) resolve load_* và py_*.
if (!function_exists('__py_intelephense_hint_stub')) {
    function __py_intelephense_hint_stub()
    {
        require_once __DIR__ . '/../../../core/base.php';
        require_once __DIR__ . '/../models/payrollModel.php';
    }
}

// send_mail() không được autoload toàn cục — mỗi module dùng nó phải tự require (giống admin_factory/auth).
require_once __DIR__ . '/../../../libraries/sendmail.php';

function construct()
{
    load_model('payroll');
}

/** Trả JSON sạch (UTF-8, không escape unicode). */
function py_json($payload)
{
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($payload, JSON_UNESCAPED_UNICODE);
    exit;
}

function py_current_user_id()
{
    if (!function_exists('permission_current_user')) return 0;
    $u = permission_current_user();
    return (int) ($u['id'] ?? 0);
}

/** year/month từ GET/POST, mặc định tháng hiện tại. */
function py_req_year_month()
{
    $y = (int) ($_REQUEST['year'] ?? $_REQUEST['y'] ?? date('Y'));
    $m = (int) ($_REQUEST['month'] ?? $_REQUEST['m'] ?? date('n'));
    if ($m < 1 || $m > 12) $m = (int) date('n');
    if ($y < 2000 || $y > 2100) $y = (int) date('Y');
    return [$y, $m];
}

/* ============================================================
 *  Page: Bảng chấm công
 * ============================================================ */
function timesheetAction()
{
    py_ensure_tables();
    py_ensure_view_registered();
    [$y, $m] = py_req_year_month();
    load_view('timesheet', [
        'year'        => $y,
        'month'       => $m,
        'off_reasons' => py_off_reasons_list(),
        'read_only'   => function_exists('permission_is_view_only') ? permission_is_view_only('payroll', 'payroll', 'timesheet') : false,
    ]);
}

/** Toàn bộ dữ liệu lưới chấm công + thống kê tháng (mark đã resolve sẵn theo mặc định). */
function timesheet_gridAction()
{
    [$y, $m] = py_req_year_month();
    $days = py_month_days($y, $m);
    $employees = py_employees_for_branch();
    $ids = array_column($employees, 'id');
    $marks_map = py_timesheet_marks($ids, $y, $m);
    $today = date('Y-m-d');

    $out_employees = [];
    foreach ($employees as $emp) {
        $eid = (int) $emp['id'];
        $emp_marks = $marks_map[$eid] ?? [];
        $row_marks = [];
        foreach ($days as $day) {
            // Ngày tương lai vẫn cho phép có mark đã lưu trước (vd chấm off trước) -> luôn
            // ưu tiên mark đã lưu; chỉ mặc định rỗng khi chưa có gì lưu VÀ là ngày tương lai.
            $stored = $emp_marks[$day['date']] ?? null;
            $mark = py_effective_mark($day['date'], $today, $stored['mark'] ?? null, $day['is_sunday']);
            $row_marks[$day['date']] = [
                'mark'      => $mark,
                'reason_id' => $stored['reason_id'] ?? null,
                'reason'    => $stored['reason'] ?? '',
            ];
        }
        $out_employees[] = [
            'id'         => $eid,
            'full_name'  => $emp['full_name'],
            'job_title'  => $emp['job_title'],
            'supported'  => py_is_supported_job_title($emp['job_title']),
            'marks'      => $row_marks,
        ];
    }

    $stats = py_calc_monthly_stats($y, $m);

    py_json([
        'success'   => true,
        'year'      => $y,
        'month'     => $m,
        'days'      => $days,
        'employees' => $out_employees,
        'stats'     => $stats,
    ]);
}

function set_timesheet_markAction()
{
    permission_require_can_edit('payroll', 'payroll', 'timesheet');
    $eid = (int) ($_POST['employee_id'] ?? 0);
    $date = (string) ($_POST['date'] ?? '');
    $mark = (string) ($_POST['mark'] ?? '');
    $reason_id = isset($_POST['reason_id']) ? (int) $_POST['reason_id'] : null;
    if ($eid <= 0 || $date === '') py_json(['success' => false, 'message' => 'Thiếu dữ liệu.']);
    $ok = py_set_timesheet_mark($eid, $date, $mark, $reason_id, null, py_current_user_id());
    py_json(['success' => $ok]);
}

function set_holiday_columnAction()
{
    permission_require_can_edit('payroll', 'payroll', 'timesheet');
    [$y, $m] = py_req_year_month();
    $date = (string) ($_POST['date'] ?? '');
    if ($date === '') py_json(['success' => false, 'message' => 'Thiếu ngày.']);
    $n = py_set_holiday_for_all($y, $m, $date, py_current_user_id());
    py_json(['success' => true, 'affected' => $n]);
}

function off_reasons_listAction()
{
    py_json(['success' => true, 'data' => py_off_reasons_list()]);
}

function off_reason_saveAction()
{
    permission_require_can_edit('payroll', 'payroll', 'timesheet');
    $id = (int) ($_POST['id'] ?? 0);
    $reason = (string) ($_POST['reason'] ?? '');
    $sort = (int) ($_POST['sort_order'] ?? 0);
    $saved_id = py_off_reason_save($id, $reason, $sort);
    if ($saved_id <= 0) py_json(['success' => false, 'message' => 'Lý do không hợp lệ.']);
    py_json(['success' => true, 'id' => $saved_id, 'data' => py_off_reasons_list()]);
}

function off_reason_deleteAction()
{
    permission_require_can_edit('payroll', 'payroll', 'timesheet');
    $id = (int) ($_POST['id'] ?? 0);
    py_off_reason_delete($id);
    py_json(['success' => true, 'data' => py_off_reasons_list()]);
}

/* ============================================================
 *  Page: Bảng lương
 * ============================================================ */
function payslipAction()
{
    py_ensure_tables();
    py_ensure_view_registered();
    [$y, $m] = py_req_year_month();
    load_view('payslip', [
        'year'               => $y,
        'month'              => $m,
        'settings'           => py_all_settings(),
        'settings_meta'      => py_setting_meta(),
        'employees'          => py_employees_for_branch(),
        'position_allowances'=> py_all_position_allowances(),
        'read_only'          => function_exists('permission_is_view_only') ? permission_is_view_only('payroll', 'payroll', 'payslip') : false,
    ]);
}

/** 5 chỉ số dùng chung của tháng: Tổng công SX, Hệ số công, Sản lượng, SP/NVSX, Thành tích. */
function monthly_infoAction()
{
    [$y, $m] = py_req_year_month();
    py_json(['success' => true, 'year' => $y, 'month' => $m, 'stats' => py_calc_monthly_stats($y, $m)]);
}

/** Sản lượng tháng, chi tiết theo từng sản phẩm — dùng ở modal con khi click "Sản lượng tháng này". */
function output_by_productAction()
{
    [$y, $m] = py_req_year_month();
    $only_new = !empty($_POST['only_new']);
    py_json(['success' => true, 'data' => py_calc_output_by_product($y, $m, $only_new)]);
}

function output_adjustments_listAction()
{
    [$y, $m] = py_req_year_month();
    py_json(['success' => true, 'data' => py_output_adjustments_list($y, $m)]);
}

function output_adjustment_saveAction()
{
    permission_require_can_edit('payroll', 'payroll', 'payslip');
    [$y, $m] = py_req_year_month();
    $id     = (int) ($_POST['id'] ?? 0);
    $amount = (float) ($_POST['amount'] ?? 0);
    $note   = (string) ($_POST['note'] ?? '');
    if ($amount == 0) py_json(['success' => false, 'message' => 'Số lượng điều chỉnh phải khác 0.']);
    $saved_id = py_output_adjustment_save($id, $y, $m, $amount, $note, py_current_user_id());
    py_json(['success' => $saved_id > 0, 'id' => $saved_id, 'data' => py_output_adjustments_list($y, $m)]);
}

function output_adjustment_deleteAction()
{
    permission_require_can_edit('payroll', 'payroll', 'payslip');
    $id = (int) ($_POST['id'] ?? 0);
    py_output_adjustment_delete($id);
    [$y, $m] = py_req_year_month();
    py_json(['success' => true, 'data' => py_output_adjustments_list($y, $m)]);
}

/** Tính live (chưa lưu) toàn bộ nhân viên cho tháng chọn. */
function payslip_dataAction()
{
    [$y, $m] = py_req_year_month();
    $raw = $_POST['overrides'] ?? '{}';
    $overrides = json_decode((string) $raw, true);
    if (!is_array($overrides)) $overrides = [];

    $monthly_stats = py_calc_monthly_stats($y, $m);
    $employees = py_employees_for_branch();
    $out = [];
    foreach ($employees as $emp) {
        $eid = (int) $emp['id'];
        $ov = $overrides[(string) $eid] ?? ($overrides[$eid] ?? []);
        $out[] = py_calc_employee_month($emp, $y, $m, is_array($ov) ? $ov : [], $monthly_stats);
    }

    py_json([
        'success'  => true,
        'year'     => $y,
        'month'    => $m,
        'summary'  => $monthly_stats,
        'employees'=> $out,
    ]);
}

function save_position_allowanceAction()
{
    permission_require_can_edit('payroll', 'payroll', 'payslip');
    $eid = (int) ($_POST['employee_id'] ?? 0);
    $value = (float) ($_POST['value'] ?? 0);
    if ($eid <= 0) py_json(['success' => false, 'message' => 'Thiếu employee_id.']);
    py_json(['success' => py_save_position_allowance($eid, $value), 'data' => py_all_position_allowances()]);
}

function list_position_allowancesAction()
{
    py_json(['success' => true, 'data' => py_all_position_allowances()]);
}

function check_already_savedAction()
{
    [$y, $m] = py_req_year_month();
    py_json(['success' => true, 'already_saved' => py_check_already_saved($y, $m)]);
}

function save_monthAction()
{
    permission_require_can_edit('payroll', 'payroll', 'payslip');
    [$y, $m] = py_req_year_month();
    $raw = $_POST['overrides'] ?? '{}';
    $overrides_raw = json_decode((string) $raw, true);
    if (!is_array($overrides_raw)) $overrides_raw = [];
    $overrides = [];
    foreach ($overrides_raw as $eid => $ov) {
        $overrides[(int) $eid] = is_array($ov) ? $ov : [];
    }
    $result = py_save_month($y, $m, $overrides, py_current_user_id());
    py_json(['success' => true, 'saved' => $result['saved'], 'skipped' => $result['skipped']]);
}

function load_history_monthAction()
{
    [$y, $m] = py_req_year_month();
    $data = py_load_saved_month($y, $m);
    py_json(['success' => true, 'year' => $y, 'month' => $m] + $data);
}

function send_payslip_emailAction()
{
    permission_require_can_edit('payroll', 'payroll', 'payslip');
    $eid = (int) ($_POST['employee_id'] ?? 0);
    [$y, $m] = py_req_year_month();
    if ($eid <= 0) py_json(['success' => false, 'message' => 'Thiếu employee_id.']);
    $result = py_send_payslip_email($eid, $y, $m);
    py_json($result);
}

function save_settingAction()
{
    permission_require_can_edit('payroll', 'payroll', 'payslip');
    $key = (string) ($_POST['key'] ?? '');
    $value = (string) ($_POST['value'] ?? '');
    if (!py_save_setting($key, $value)) py_json(['success' => false, 'message' => 'Thiết lập không hợp lệ.']);
    py_json(['success' => true]);
}
