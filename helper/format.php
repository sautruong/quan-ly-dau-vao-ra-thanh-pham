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
        case 'social_insurance_salary':
            // Hiển thị tiền tệ, ví dụ 5000000 → "5,000,000 đ" (thay vì "5.00").
            return ($value === '' || $value === null) ? '' : currency_format((float) $value);
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
/**
 * Quy đổi mốc thời gian sang tiếng Việt tương đối (dùng chung chuông/todo/đơn hàng).
 * Vừa xong / Hôm nay HH:MM / Hôm qua HH:MM / N ngày trước / Tuần trước /
 * N tuần trước / Tháng trước / N tháng trước / N năm trước.
 */
if (!function_exists('format_time_ago_vi')) {
    function format_time_ago_vi($datetime)
    {
        if (empty($datetime)) return '';
        $ts = is_numeric($datetime) ? (int) $datetime : strtotime((string) $datetime);
        if (!$ts) return (string) $datetime;
        $now  = time();
        $hm   = date('H:i', $ts);
        if ($now - $ts < 60) return 'Vừa xong';
        $days = (int) floor((strtotime(date('Y-m-d', $now)) - strtotime(date('Y-m-d', $ts))) / 86400);
        if ($days <= 0)   return 'Hôm nay ' . $hm;
        if ($days === 1)  return 'Hôm qua ' . $hm;
        if ($days <= 6)   return $days . ' ngày trước';
        if ($days <= 13)  return 'Tuần trước';
        if ($days <= 29)  return (int) floor($days / 7) . ' tuần trước';
        if ($days <= 59)  return 'Tháng trước';
        if ($days <= 364) return (int) floor($days / 30) . ' tháng trước';
        return (int) floor($days / 365) . ' năm trước';
    }
}

/**
 * Nhãn tương đối + mốc giờ đầy đủ cho cột "ngày" của bảng lịch sử (dùng chung 10 view
 * nhập liệu: dashboard/product_buy/other_receipt/sales_return_receipt/investment_products
 * (inventory_management), row_material_receiving/other_row_material_receiving
 * (inventory_receiving), sales_delivery_note/other_warehouse_outbound (warehouse_outbound),
 * cash_transactions).
 * Khác format_time_ago_vi (chuông/todo): LUÔN kèm "HH:mm:ss dd/mm/YYYY" đầy đủ, ngưỡng
 * "Vừa xong" là dưới 1h (không phải 1 phút), và trả kèm màu để tô cột ngày:
 *   'fresh'  (xanh lá)  → vừa xong (<1h) hoặc hôm nay
 *   'recent' (nâu nhạt) → hôm qua, 2 ngày trước
 *   'old'    (đen)      → cũ hơn (từ 3 ngày trước trở đi)
 * Trả về ['text' => 'Nhãn: HH:mm:ss dd/mm/YYYY', 'color' => 'fresh'|'recent'|'old'].
 */
if (!function_exists('history_date_display')) {
    function history_date_display($datetime)
    {
        if (empty($datetime)) return ['text' => '', 'color' => 'old'];
        $ts = is_numeric($datetime) ? (int) $datetime : strtotime((string) $datetime);
        if (!$ts) return ['text' => (string) $datetime, 'color' => 'old'];

        $now  = time();
        $full = date('H:i:s d/m/Y', $ts);
        $days = (int) floor((strtotime(date('Y-m-d', $now)) - strtotime(date('Y-m-d', $ts))) / 86400);

        if ($now - $ts < 3600) {
            $label = 'Vừa xong';
            $color = 'fresh';
        } elseif ($days <= 0) {
            $label = 'Hôm nay';
            $color = 'fresh';
        } elseif ($days === 1) {
            $label = 'Hôm qua';
            $color = 'recent';
        } elseif ($days === 2) {
            $label = '2 ngày trước';
            $color = 'recent';
        } elseif ($days <= 6) {
            $label = $days . ' ngày trước';
            $color = 'old';
        } elseif ($days <= 13) {
            $label = 'Tuần trước';
            $color = 'old';
        } elseif ($days <= 29) {
            $label = (int) floor($days / 7) . ' tuần trước';
            $color = 'old';
        } elseif ($days <= 59) {
            $label = 'Tháng trước';
            $color = 'old';
        } elseif ($days <= 364) {
            $label = (int) floor($days / 30) . ' tháng trước';
            $color = 'old';
        } elseif ($days <= 729) {
            $label = 'Năm trước';
            $color = 'old';
        } else {
            $label = (int) floor($days / 365) . ' năm trước';
            $color = 'old';
        }

        return ['text' => $label . ': ' . $full, 'color' => $color];
    }
}
