<?php
function currency_format($number, $suffix = 'đ'){
    return number_format($number).$suffix;
}
function format_value($key, $value) {
    switch ($key) {
        case 'gender':
            return $value == 'M' ? 'Nam' : 'Nữ';

        case 'date_of_birth':
            return date('d/m/Y', strtotime($value));
        case 'hire_date':
            return date('d/m/Y', strtotime($value));
        case 'id_issue_date':
            return date('d/m/Y', strtotime($value));
        case 'contract_sign_date':
            return date('d/m/Y', strtotime($value));
        case 'contract_start_date':
            return date('d/m/Y', strtotime($value));
        case 'contract_end_date':
            return date('d/m/Y', strtotime($value));
        case 'region':
             $regions = [
                'north'   => 'Miền Bắc',
                'central' => 'Miền Trung',
                'south'   => 'Miền Nam',
            ];
            return $regions[$value] ?? $value;
        case 'employee_type':
             $employee_type = [
                'full_time'   => 'Toàn thời gian',
                'part_time' => 'Bán thời gian',
                'intern'   => 'Thực tập sinh',
                'contractor'   => 'Cộng tác viên',
            ];
            return $employee_type[$value] ?? $value;
        case 'social_insurance_status':
             $status_social = [
                'active'   => 'Đang đóng',
                'inactive' => 'Nghỉ đóng',
                'pending'   => 'Chưa đóng',
            ];
            return  $status_social[$value] ?? $value;
        case 'branch':
            $branches = [
                'headquarters' => 'Trụ sở chính',
                'HCM'          => 'Hồ Chí Minh',
                'HN'           => 'Hà Nội',
                'DN'           => 'Đà Nẵng',
                'CT'           => 'Cần Thơ',
            ];
            return $branches[$value] ?? $value;
        default:
            return $value;
    }
}