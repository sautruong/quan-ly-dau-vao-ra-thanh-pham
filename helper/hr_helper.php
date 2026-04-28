<?php
function get_table_title($table)
{
    static $map = [
        'employees' => 'Thông tin chính',
        'employee_basic_info' => 'Thông tin căn bản',
        'employee_work_info' => 'Thông tin làm việc',
        'employee_identity' => 'Thông tin định danh',
        'employee_bank' => 'Ngân hàng',
        'employee_education' => 'Học vấn',
        'employee_insurance' => 'Bảo hiểm xã hội',
        'employee_contract' => 'Hợp đồng',
        'employee_organization' => 'Tổ chức',
        'employee_management' => 'Quản lý',
        'employee_documents' => 'Lưu hồ sơ',
    ];

    return $map[$table] ?? $table;
}
function render_input($table, $column, $value)
{
    // name để submit dạng: employees[full_name]
    $name = $table . '[' . $column . ']';

    // xử lý type
    if ($column === 'date_of_birth' || str_contains($column, 'date')) {
        return "<input type='date' name='$name' value='$value'>";
    }
    // gender select
    if ($column === 'gender') {
        return "
            <select name='$name'>
                <option value='' disabled hidden>Chọn giới tính</option>
                <option value='M' " . ($value == 'M' ? 'selected' : '') . ">Nam</option>
                <option value='F' " . ($value == 'F' ? 'selected' : '') . ">Nữ</option>
            </select>
        ";
    }
    // employment_status select
    if ($column === 'employment_status') {
        return "
            <select name='$name'>
                <option value='' disabled hidden>Chọn trạng thái làm việc</option>
                <option value='active' " . ($value == 'active' ? 'selected' : '') . ">Đang làm việc</option>
                <option value='regigned' " . ($value == 'regigned' ? 'selected' : '') . ">Thôi việc</option>
                <option value='on_leave' " . ($value == 'on_leave' ? 'selected' : '') . ">Nghỉ gián đoạn</option>
            </select>
        ";
    }
    // region select
    if ($column === 'region') {
        return "
            <select name='$name'>
                <option value='' disabled hidden>Chọn miền</option>
                <option value='north' " . ($value == 'north' ? 'selected' : '') . ">Miền Bắc</option>
                <option value='central' " . ($value == 'central' ? 'selected' : '') . ">Miền Trung</option>
                <option value='south' " . ($value == 'south' ? 'selected' : '') . ">Miền Nam</option>
            </select>
        ";
    }
    // branch select
    if ($column === 'branch') {
        return "
            <select name='$name'>
                <option value='' disabled hidden>Chọn chi nhánh</option>
                <option value='headquarters' " . ($value == 'headquarters' ? 'selected' : '') . ">Trụ sở chính</option>
                <option value='HCM' " . ($value == 'HCM' ? 'selected' : '') . ">Hồ Chí Minh</option>
                <option value='HN' " . ($value == 'HN' ? 'selected' : '') . ">Hà Nội</option>
                <option value='DN' " . ($value == 'DN' ? 'selected' : '') . ">Đà Nẵng</option>
                <option value='CT' " . ($value == 'CT' ? 'selected' : '') . ">Cần Thơ</option>
            </select>
        ";
    }
    // default text

    return "<input type='text' name='$name' value='" . htmlspecialchars($value) . "'>";
}

