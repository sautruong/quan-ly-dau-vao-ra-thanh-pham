<?php
/**
 * print_settings — thông tin cố định in chứng từ (phiếu nhập kho, phiếu xuất kho...).
 * Lưu key/value ở bảng app_settings, prefix 'print_receipt.<key>'.
 * Dùng chung nhiều module → cùng 1 công ty / người lập, sửa 1 nơi áp dụng mọi phiếu.
 * "Sửa một lần dùng dài lâu": user sửa trực tiếp trên modal in → lưu lại.
 *
 * File require_once từ nhiều controller/model nên mọi hàm có guard function_exists.
 */

if (!function_exists('print_settings_ensure_table')) {

    /** Tạo bảng app_settings nếu chưa có (1 lần / request). */
    function print_settings_ensure_table()
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
    function print_settings_defaults()
    {
        return [
            'company_name'       => 'CÔNG TY TNHH VUA AN TOÀN',
            'company_enname'     => 'SAFE KING Co,.LTD',
            'company_address'    => '1/13Z Ấp Tiền Lân, Xã Bà Điểm, Thành Phố Hồ Chí Minh, Việt Nam.',
            'company_phone'      => '',
            'company_email'      => '',
            'signer_preparer'    => 'TRƯƠNG NGỌC SÁU',
            'signer_deliverer'   => '',
            'signer_storekeeper' => '',
            'signer_accountant'  => '',
            'signer_director'    => 'LÊ HỮU TRÍ',
        ];
    }

    /** Lấy toàn bộ print settings (default + override đã lưu). */
    function print_settings_get()
    {
        print_settings_ensure_table();
        $out = print_settings_defaults();
        $rows = db_fetch_array("SELECT setting_key, setting_value FROM app_settings
                                WHERE setting_key LIKE 'print_receipt.%'");
        foreach (($rows ?: []) as $r) {
            $k = substr((string) $r['setting_key'], strlen('print_receipt.'));
            if (array_key_exists($k, $out) && $r['setting_value'] !== null) {
                $out[$k] = (string) $r['setting_value'];
            }
        }
        return $out;
    }

    /** Lưu 1 print setting (chỉ chấp nhận key trong whitelist). */
    function print_settings_save($key, $value)
    {
        $key = (string) $key;
        if (!array_key_exists($key, print_settings_defaults())) return false;
        print_settings_ensure_table();
        $full = 'print_receipt.' . $key;
        $fk   = escape_string($full);
        $exists = db_num_rows("SELECT 1 FROM app_settings WHERE setting_key = '$fk'") > 0;
        if ($exists) {
            db_update('app_settings', ['setting_value' => (string) $value], "setting_key = '$fk'");
        } else {
            db_insert('app_settings', ['setting_key' => $full, 'setting_value' => (string) $value]);
        }
        return true;
    }
}
