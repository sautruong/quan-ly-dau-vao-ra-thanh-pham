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
    return $info_hr_for_contract;
    //show_array( $info_hr_db); 
}
