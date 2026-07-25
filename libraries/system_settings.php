<?php
/**
 * system_settings — cấu hình chung toàn hệ thống (Cài đặt hệ thống trong sidebar).
 * Lưu key/value ở bảng app_settings (dùng chung với print_settings), prefix 'system.<key>'.
 * Chỉ admin trưởng được sửa (permission_is_admin) — kiểm tra ở controller, không ở đây.
 */

if (!function_exists('system_settings_ensure_table')) {

    /** Tạo bảng app_settings nếu chưa có (1 lần / request). */
    function system_settings_ensure_table()
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

    /** Giá trị mặc định + whitelist key cho phép sửa. */
    function system_settings_defaults()
    {
        return [
            'chat_retention_mode' => 'none',
            'storage_quota_mb'    => '2048',
        ];
    }

    /** Các giá trị hợp lệ cho từng key (whitelist chặt, tránh lưu rác). */
    function system_settings_allowed_values($key)
    {
        if ($key === 'chat_retention_mode') {
            return ['none', 'count_500', 'days_7', 'days_30', 'days_90', 'days_180', 'days_365'];
        }
        return null; // null = không giới hạn danh sách (vd storage_quota_mb là số)
    }

    /** Lấy toàn bộ cấu hình hệ thống (default + override đã lưu). */
    function system_settings_get()
    {
        system_settings_ensure_table();
        $out = system_settings_defaults();
        $rows = db_fetch_array("SELECT setting_key, setting_value FROM app_settings
                                WHERE setting_key LIKE 'system.%'");
        foreach (($rows ?: []) as $r) {
            $k = substr((string) $r['setting_key'], strlen('system.'));
            if (array_key_exists($k, $out) && $r['setting_value'] !== null) {
                $out[$k] = (string) $r['setting_value'];
            }
        }
        return $out;
    }

    /** Lưu 1 cấu hình hệ thống (chỉ chấp nhận key + giá trị hợp lệ). */
    function system_settings_save($key, $value)
    {
        $key = (string) $key;
        if (!array_key_exists($key, system_settings_defaults())) return false;

        $value = (string) $value;
        if ($key === 'storage_quota_mb') {
            if (!ctype_digit($value) || (int) $value <= 0) return false;
        } else {
            $allowed = system_settings_allowed_values($key);
            if ($allowed !== null && !in_array($value, $allowed, true)) return false;
        }

        system_settings_ensure_table();
        $full = 'system.' . $key;
        $fk   = escape_string($full);
        $exists = db_num_rows("SELECT 1 FROM app_settings WHERE setting_key = '$fk'") > 0;
        if ($exists) {
            db_update('app_settings', ['setting_value' => $value], "setting_key = '$fk'");
        } else {
            db_insert('app_settings', ['setting_key' => $full, 'setting_value' => $value]);
        }
        return true;
    }
}
