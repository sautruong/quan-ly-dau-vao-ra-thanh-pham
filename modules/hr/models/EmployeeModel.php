<?php
function  get_column($table)
{
    // show_array($table_hr_config); //>OK
    $sql = "SELECT COLUMN_NAME 
            FROM INFORMATION_SCHEMA.COLUMNS 
            WHERE TABLE_NAME = '$table'";

    $list_column = db_fetch_array($sql); //Lấy một mảng trong sql


    return array_column($list_column, 'COLUMN_NAME');
}

function get_list_content_table($table, $branch)
{
    global $conn;

    $columns = get_column($table);
    $conditions = [];

    // Điều kiện mặc định
    if ($table == 'employees') {
        $conditions[] = "w.employment_status IN ('active', 'on_leave')";
    } else {
        $conditions[] = "employee_work_info.employment_status IN ('active', 'on_leave')";
    }

    // ======================
    // CASE 1: employee_work_info
    // ======================
    if ($table == 'employee_work_info') {

        $select = "*";

        $sql = "FROM `employee_work_info`
                LEFT JOIN `employees`
                ON `employee_work_info`.`employee_id` = `employees`.`id`";
    }

    // ======================
    // CASE 2: employees
    // ======================
    elseif ($table == 'employees') {

        $select = "e.id, e.employee_code, e.full_name, e.gender,
                   e.date_of_birth, e.phone_number, e.email, w.branch";

        $sql = "FROM employees AS e
                LEFT JOIN employee_work_info AS w
                ON e.id = w.employee_id";
    }

    // ======================
    // CASE 3: bảng khác
    // ======================
    else {

        $new_columns = [];
        $new_columns[] = "`$table`.`id`";
        $new_columns[] = "`employees`.`employee_code` AS employee_code";
        $new_columns[] = "`employees`.`full_name` AS full_name";

        foreach ($columns as $col) {
            if ($col == 'employee_id' || $col == 'id') continue;
            $new_columns[] = "`$table`.`$col`";
        }

        $select = implode(", ", $new_columns);

        $sql = "FROM `$table`
                LEFT JOIN `employees`
                ON `$table`.`employee_id` = `employees`.`id`
                LEFT JOIN `employee_work_info`
                ON `employees`.`id` = `employee_work_info`.`employee_id`";
    }

    // ======================
    // FILTER BRANCH
    // ======================
    if (!empty($branch) && $branch != 'all') {

        $branch = $conn->real_escape_string($branch);

        if ($table == 'employees') {
            $conditions[] = "w.branch = '$branch'";
        } else {
            $conditions[] = "employee_work_info.branch = '$branch'";
        }
    }

    // ======================
    // BUILD FINAL SQL
    // ======================
    $final_sql = "SELECT $select " . $sql;

    if (!empty($conditions)) {
        $final_sql .= " WHERE " . implode(" AND ", $conditions);
    }

    return db_fetch_array($final_sql);
}
/* ============================================================
 *  Cấu hình hợp đồng (BÊN A + Giám đốc) — sửa 1 lần dùng dài lâu.
 *  Lưu app_settings, prefix 'contract.'.
 * ============================================================ */
function contract_settings_defaults()
{
    return [
        'company_name'    => 'CÔNG TY TNHH VUA AN TOÀN',
        'company_address' => '1/13Z Ấp Tiền Lân, Xã Bà Điểm, Thành Phố Hồ Chí Minh, Việt Nam.',
        'director_name'   => 'LÊ HỮU TRÍ',
    ];
}

function contract_settings_ensure_table()
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

function contract_settings_get()
{
    contract_settings_ensure_table();
    $out  = contract_settings_defaults();
    $rows = db_fetch_array("SELECT setting_key, setting_value FROM app_settings
                            WHERE setting_key LIKE 'contract.%'");
    foreach (($rows ?: []) as $r) {
        $k = substr((string) $r['setting_key'], strlen('contract.'));
        if (array_key_exists($k, $out) && $r['setting_value'] !== null && $r['setting_value'] !== '') {
            $out[$k] = (string) $r['setting_value'];
        }
    }
    return $out;
}

function contract_settings_save($key, $value)
{
    $key = (string) $key;
    if (!array_key_exists($key, contract_settings_defaults())) return false;
    contract_settings_ensure_table();
    $full = 'contract.' . $key;
    $fk   = escape_string($full);
    $exists = db_num_rows("SELECT 1 FROM app_settings WHERE setting_key = '$fk'") > 0;
    if ($exists) {
        db_update('app_settings', ['setting_value' => (string) $value], "setting_key = '$fk'");
    } else {
        db_insert('app_settings', ['setting_key' => $full, 'setting_value' => (string) $value]);
    }
    return true;
}

function get_data_contract_hr_by_id($id)
{
    $id = (int)$id;
    $info_hr_for_contract = [];
    $info_hr_for_contract['contract_number'] = "HĐLĐ/" . date('Y') . "/01";
    $info_hr_for_contract['sign_date'] = date('d/m/Y');
    $info_hr_for_contract['contract_type'] = "12 tháng";
    $info_hr_for_contract['contract_end_date'] = date('d/m/Y', strtotime('+1 year'));;
    $sql = "SELECT 
            e.full_name, 
            e.date_of_birth,
            b.nationality,
            w.work_address,
            iden.citizen_id,
            iden.id_issue_place,
            iden.permanent_address,
            iden.temporary_address,
            o.position,
            insurance.social_insurance_salary
        FROM employees as e 
        LEFT JOIN employee_basic_info as b ON e.id = b.id
        LEFT JOIN employee_work_info as w ON e.id = w.id
        LEFT JOIN employee_identity as iden ON e.id = iden.id
        LEFT JOIN employee_organization as o ON e.id = o.id
        LEFT JOIN employee_insurance as insurance ON e.id = insurance.id
        WHERE e.id = '$id'";
    $info_hr_db = db_fetch_array($sql);

    $info_hr_for_contract['employee_name'] = $info_hr_db[0]['full_name'];
    $info_hr_for_contract['date_of_birth'] = $info_hr_db[0]['date_of_birth'];
    $info_hr_for_contract['nationality'] = $info_hr_db[0]['nationality'];
    $info_hr_for_contract['permanent_address'] = $info_hr_db[0]['permanent_address'];
    $info_hr_for_contract['temporary_address'] = $info_hr_db[0]['temporary_address'];
    $info_hr_for_contract['citizen_id'] = $info_hr_db[0]['citizen_id'];
    $info_hr_for_contract['place_of_issue'] = $info_hr_db[0]['id_issue_place'];
    $info_hr_for_contract['work_address'] = $info_hr_db[0]['work_address'];
    $info_hr_for_contract['position'] = $info_hr_db[0]['position'];
    $info_hr_for_contract['insurance_salary'] = $info_hr_db[0]['social_insurance_salary'];

    // Cấu hình BÊN A + Giám đốc (sửa 1 lần dùng dài lâu).
    $info_hr_for_contract['contract_settings'] = contract_settings_get();
    return $info_hr_for_contract;
    //show_array( $info_hr_db); 
}
