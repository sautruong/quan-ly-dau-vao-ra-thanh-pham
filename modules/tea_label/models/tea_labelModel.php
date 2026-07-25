<?php

/**
 * tea_label — Tạo tem nguyên liệu trà
 *
 * Dán lên bao NVL trà nhận từ nhà cung cấp. Số lượng tem = ceil(số lượng nhập / quy
 * cách chia) — tính hoàn toàn phía client (không cần round-trip DB) vì mọi dữ liệu
 * cần thiết (tên NVL gõ tự do, NCC đã chọn, loại trà, ngày SX) đã có sẵn trên form.
 *
 * Nguồn dữ liệu:
 *   - material_information : chỉ dùng để GỢI Ý tên NVL (không ràng buộc, tên NVL trên
 *                             tem là text tự do vì có thể chưa từng được tạo trong hệ
 *                             thống — hàng mới nhận từ NCC).
 *   - suppliers             : tên + địa chỉ NCC hiển thị trên tem (supplier_name, address).
 *   - app_settings          : 3 dòng cố định (Công dụng/Hạn sử dụng/Bảo quản) — sửa 1 lần
 *                             dùng nhiều lần, giống pattern hotline ở production_label.
 *
 * Prefix hàm: tl_*.
 */

/* ============================================================
 *  Đăng ký view (tự chạy, không cần migration tay)
 * ============================================================ */

function tl_ensure_view_registered()
{
    if (db_num_rows("SHOW TABLES LIKE 'tbl_views'") <= 0) return;
    db_query("INSERT IGNORE INTO tbl_views (module, controller, action, label, group_label, sort)
              VALUES ('tea_label','tea_label','tea_label',
                      'Tạo tem nguyên liệu trà','SẢN XUẤT', 48)");
}

/* ============================================================
 *  Gợi ý tên nguyên liệu (free text, không bắt buộc chọn đúng 1 dòng có sẵn)
 * ============================================================ */

function tl_search_materials($keyword)
{
    $kw = trim((string) $keyword);
    if ($kw === '') return [];
    $k = escape_string($kw);
    $sql = "SELECT id, material_name, common_material_name
            FROM material_information
            WHERE material_name LIKE '%$k%' OR common_material_name LIKE '%$k%'
            ORDER BY material_name ASC
            LIMIT 15";
    $rows = db_fetch_array($sql) ?: [];
    foreach ($rows as &$r) {
        $c = trim((string) $r['common_material_name']);
        $r['display_name'] = $c !== '' ? $c : (string) $r['material_name'];
    }
    unset($r);
    return $rows;
}

/* ============================================================
 *  Tìm nhà cung cấp (tham chiếu bảng suppliers, độc lập với tên NVL)
 * ============================================================ */

function tl_search_suppliers($keyword)
{
    $kw = trim((string) $keyword);
    if ($kw === '') return [];
    $k = escape_string($kw);
    $sql = "SELECT id, supplier_name, address
            FROM suppliers
            WHERE supplier_name LIKE '%$k%'
            ORDER BY supplier_name ASC
            LIMIT 15";
    $rows = db_fetch_array($sql) ?: [];
    foreach ($rows as &$r) { $r['id'] = (int) $r['id']; }
    unset($r);
    return $rows;
}

/* ============================================================
 *  3 dòng cố định trên tem (Công dụng / Hạn sử dụng / Bảo quản)
 *  "sửa 1 lần dùng nhiều lần" — app_settings, key tea_label.<field>
 * ============================================================ */

function tl_app_settings_ensure()
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
function tl_fixed_text_defaults()
{
    return [
        'usage'      => 'Dùng pha chế trà sữa hoặc trà để uống',
        'shelf_life' => '2 năm kể từ ngày sản xuất',
        'storage'    => 'Nơi khô ráo, thoáng mát',
    ];
}

function tl_get_fixed_texts()
{
    tl_app_settings_ensure();
    $out  = tl_fixed_text_defaults();
    $rows = db_fetch_array("SELECT setting_key, setting_value FROM app_settings
                            WHERE setting_key LIKE 'tea_label.%'") ?: [];
    foreach ($rows as $r) {
        $k = substr((string) $r['setting_key'], strlen('tea_label.'));
        if (array_key_exists($k, $out) && $r['setting_value'] !== null && trim((string) $r['setting_value']) !== '') {
            $out[$k] = (string) $r['setting_value'];
        }
    }
    return $out;
}

function tl_save_fixed_text($key, $value)
{
    $key = (string) $key;
    if (!array_key_exists($key, tl_fixed_text_defaults())) return false;
    tl_app_settings_ensure();
    $full = 'tea_label.' . $key;
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
