<?php

/**
 * sample_label — Tạo tem gửi mẫu
 *
 * Tem gửi kèm mẫu sản phẩm cho khách/đối tác. Ephemeral, giống production_label/tea_label:
 * card chỉ tồn tại trên DOM (client), Reset/tải lại trang là mất — không có bảng DB riêng.
 *
 * Nguồn dữ liệu:
 *   - products     : chỉ dùng để search tên sản phẩm cho card.
 *   - app_settings : 3 trường "sửa 1 lần dùng nhiều lần" (Khối lượng/Ghi chú/Cảnh báo),
 *                    giống pattern tl_fixed_text_defaults() ở tea_label.
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
              VALUES ('sample_label','sample_label','sample_label',
                      'Tạo tem gửi mẫu','SẢN XUẤT', 49)");
}

/* ============================================================
 *  Search sản phẩm (chọn nhiều lần liên tiếp, mỗi lần chọn -> 1 card)
 * ============================================================ */

function sl_search_products($keyword)
{
    $kw = trim((string) $keyword);
    if ($kw === '') return [];
    $k = escape_string($kw);
    $sql = "SELECT id, COALESCE(NULLIF(common_product_name, ''), product_name) AS name
            FROM products
            WHERE product_name LIKE '%$k%' OR common_product_name LIKE '%$k%'
            ORDER BY product_name ASC
            LIMIT 15";
    $rows = db_fetch_array($sql) ?: [];
    foreach ($rows as &$r) { $r['id'] = (int) $r['id']; }
    unset($r);
    return $rows;
}

/* ============================================================
 *  3 trường cố định trên tem (Khối lượng / Ghi chú / Cảnh báo)
 *  "sửa 1 lần dùng nhiều lần" — app_settings, key sample_label.<field>
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
        'quantity' => '100 gram',
        'note'     => 'Sản phẩm mẫu - không bán, chỉ dùng thử nghiệm cảm quan hoặc đánh giá',
        'warning'  => 'Không dùng khi sản phẩm hết hạn sử dụng',
    ];
}

function sl_get_fixed_texts()
{
    sl_app_settings_ensure();
    $out  = sl_fixed_text_defaults();
    $rows = db_fetch_array("SELECT setting_key, setting_value FROM app_settings
                            WHERE setting_key LIKE 'sample_label.%'") ?: [];
    foreach ($rows as $r) {
        $k = substr((string) $r['setting_key'], strlen('sample_label.'));
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
    $full = 'sample_label.' . $key;
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
