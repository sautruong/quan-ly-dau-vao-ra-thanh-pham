<?php

/**
 * siro_label — Nhãn siro mẫu
 *
 * Nhãn 50x35mm dán lên chai siro mẫu. Không lưu trữ dữ liệu (tạo ra để in rồi
 * thôi) — chỉ có các dòng thông tin công ty cố định được "sửa một lần dùng
 * nhiều lần" (app_settings), giống pattern hotline ở production_label /
 * 3 dòng cố định ở tea_label. Tên siro (SIRO MÃNG CẦU) và NSX/HSD do người
 * dùng nhập mỗi lần tạo tem nên không lưu qua app_settings.
 *
 * Prefix hàm: sl_*.
 */

/* ============================================================
 *  Đăng ký view (tự chạy, không cần migration tay)
 * ============================================================ */

function sl_ensure_view_registered()
{
    if (db_num_rows("SHOW TABLES LIKE 'tbl_views'") <= 0) return;
    db_query("INSERT IGNORE INTO tbl_views (module, controller, action, label, group_label, sort)
              VALUES ('siro_label','siro_label','siro_label',
                      'Nhãn siro mẫu','SẢN XUẤT', 50)");
}

/* ============================================================
 *  Các dòng cố định trên nhãn — "sửa 1 lần dùng nhiều lần" (app_settings)
 * ============================================================ */

function sl_app_settings_ensure()
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

/** Whitelist key => giá trị mặc định. */
function sl_fixed_text_defaults()
{
    return [
        'company_line1' => 'CÔNG TY TNHH',
        // Nhãn "Địa chỉ:" nay là span riêng trong view (giống .sl-label của các dòng khác)
        // nên giá trị lưu chỉ còn phần địa chỉ.
        'address'       => '1/13Z Ấp Tiền Lân, Xã Bà Điểm, TP Hồ Chí Minh, Việt Nam',
        'storage'       => 'Nơi khô ráo, thoáng mát',
        'hotline'       => '0777 044 777',
        'volume'        => '100 ML',
        'origin'        => 'Việt Nam',
    ];
}

function sl_get_fixed_texts()
{
    sl_app_settings_ensure();
    $out  = sl_fixed_text_defaults();
    $rows = db_fetch_array("SELECT setting_key, setting_value FROM app_settings
                            WHERE setting_key LIKE 'siro_label.%'") ?: [];
    foreach ($rows as $r) {
        $k = substr((string) $r['setting_key'], strlen('siro_label.'));
        if (array_key_exists($k, $out) && $r['setting_value'] !== null && trim((string) $r['setting_value']) !== '') {
            $out[$k] = (string) $r['setting_value'];
        }
    }
    return $out;
}

function sl_save_fixed_text($key, $value)
{
    $key = (string) $key;
    if (!array_key_exists($key, sl_fixed_text_defaults())) return false;
    sl_app_settings_ensure();
    $full = 'siro_label.' . $key;
    $fk   = escape_string($full);
    $v    = trim((string) $value);
    $exists = db_num_rows("SELECT 1 FROM app_settings WHERE setting_key = '$fk'") > 0;
    if ($exists) {
        db_update('app_settings', ['setting_value' => $v], "setting_key = '$fk'");
    } else {
        db_insert('app_settings', ['setting_key' => $full, 'setting_value' => $v]);
    }
    return true;
}
