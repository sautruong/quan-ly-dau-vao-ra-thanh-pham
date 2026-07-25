<?php
/**
 * =====================================================================
 *  Đặt hàng cà phê (order_coffee) — Model
 * =====================================================================
 *  Bối cảnh: NCC gia công cho mình — có khi giao THÀNH PHẨM (mình gửi bao bì
 *  cho họ làm), có khi chỉ giao NGUYÊN LIỆU (mình tự sản xuất).
 *
 *  Bảng riêng của module:
 *   - oc_recipes          : thiết lập "công thức đặt hàng" của 1 SP
 *                           (NL + đơn vị + SL cho yield_qty thành phẩm, bao bì, ghi chú)
 *   - oc_orders           : đơn đặt hàng (gia công | nguyên liệu), lưu theo nhóm NL
 *   - oc_packaging_ledger : sổ bao bì tại NCC (định nghĩa ở libraries/coffee_packaging.php)
 *  Bảng dùng chung:
 *   - products, material_information, suppliers, app_settings
 *
 *  Prefix hàm: oc_*.
 * =====================================================================
 */

require_once __DIR__ . '/../../../libraries/coffee_packaging.php';

/* ============================================================
 *  Khởi tạo bảng (idempotent)
 * ============================================================ */
function oc_ensure_tables()
{
    static $done = false;
    if ($done) return;
    $done = true;

    db_query("CREATE TABLE IF NOT EXISTS oc_recipes (
        id INT AUTO_INCREMENT PRIMARY KEY,
        product_id     INT NOT NULL,
        product_name   VARCHAR(255) DEFAULT NULL,
        yield_qty      DECIMAL(15,3) NOT NULL DEFAULT 1,
        packaging_id   INT DEFAULT NULL,
        packaging_name VARCHAR(255) DEFAULT NULL,
        note           TEXT DEFAULT NULL,
        materials      LONGTEXT DEFAULT NULL,
        updated_at     TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        UNIQUE KEY uniq_product (product_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci");

    db_query("CREATE TABLE IF NOT EXISTS oc_orders (
        id INT AUTO_INCREMENT PRIMARY KEY,
        user_id       INT DEFAULT NULL,
        supplier_id   INT DEFAULT NULL,
        supplier_name VARCHAR(255) DEFAULT NULL,
        order_type    VARCHAR(20)  NOT NULL DEFAULT 'process',
        groups        LONGTEXT DEFAULT NULL,
        note          TEXT DEFAULT NULL,
        status        VARCHAR(20)  NOT NULL DEFAULT 'new',
        received      TINYINT(1)   NOT NULL DEFAULT 0,
        received_at   DATETIME DEFAULT NULL,
        hidden        TINYINT(1)   NOT NULL DEFAULT 0,
        created_at    TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        KEY idx_sup (supplier_id),
        KEY idx_created (created_at)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci");

    db_query("CREATE TABLE IF NOT EXISTS app_settings (
        setting_key   VARCHAR(100) NOT NULL PRIMARY KEY,
        setting_value TEXT DEFAULT NULL
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci");

    db_query("ALTER TABLE suppliers ADD COLUMN IF NOT EXISTS address VARCHAR(255) DEFAULT NULL");

    // Snapshot giá trị: expected_value chốt LÚC LƯU ĐƠN (đối soát cố định, không trôi theo
    // giá nhập mới); actual_value chốt lúc phiếu nhập kho khớp đơn (tự đánh dấu "Đã nhận").
    db_query("ALTER TABLE oc_orders ADD COLUMN IF NOT EXISTS expected_value DECIMAL(15,2) DEFAULT NULL AFTER received_at");
    db_query("ALTER TABLE oc_orders ADD COLUMN IF NOT EXISTS actual_value   DECIMAL(15,2) DEFAULT NULL AFTER expected_value");

    // Ảnh dẫn chứng gắn vào TỪNG DÒNG sổ bao bì (oc_packaging_ledger) — lưu ảnh chụp tin nhắn
    // đã xác nhận tồn với NCC làm điểm mốc chốt tồn (dán Ctrl+V vào ô Ngày của dòng).
    db_query("CREATE TABLE IF NOT EXISTS oc_ledger_evidence (
        id INT AUTO_INCREMENT PRIMARY KEY,
        entry_id   INT NOT NULL,
        file_path  VARCHAR(500) NOT NULL,
        created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        KEY idx_entry (entry_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci");

    coffee_pkg_ensure_tables();
}

/** Đăng ký view vào menu "SẢN XUẤT" (idempotent). */
function oc_ensure_view_registered()
{
    if (db_num_rows("SHOW TABLES LIKE 'tbl_views'") <= 0) return;
    db_query("INSERT IGNORE INTO tbl_views (module, controller, action, label, group_label, sort)
              VALUES ('order_coffee','order_coffee','order_coffee','Đặt hàng cà phê','SẢN XUẤT', 46)");
}

/* ============================================================
 *  Helpers
 * ============================================================ */
function oc_mat_display_name($row)
{
    $m = trim((string) ($row['material_name'] ?? ''));
    return $m !== '' ? $m : (string) ($row['common_material_name'] ?? '');
}

/* ============================================================
 *  app_settings (tiêu đề phiếu + người ký) — sửa 1 lần dùng nhiều lần
 * ============================================================ */
function oc_get_setting($key, $default = '')
{
    oc_ensure_tables();
    $k = escape_string((string) $key);
    $row = db_fetch_row("SELECT setting_value FROM app_settings WHERE setting_key = '$k' LIMIT 1");
    if (!$row || $row['setting_value'] === null) return $default;
    return $row['setting_value'];
}

function oc_set_setting($key, $value)
{
    oc_ensure_tables();
    $k = (string) $key;
    if ($k === '') return false;
    $ke = escape_string($k);
    $exists = db_num_rows("SELECT 1 FROM app_settings WHERE setting_key = '$ke'") > 0;
    if ($exists) {
        db_update('app_settings', ['setting_value' => (string) $value], "setting_key = '$ke'");
    } else {
        db_insert('app_settings', ['setting_key' => $k, 'setting_value' => (string) $value]);
    }
    return true;
}

/** Thông tin cố định của phiếu (dùng chung app_settings với các phiếu khác về công ty). */
function oc_doc_settings()
{
    $roles_raw = oc_get_setting('order_coffee.sign_roles', '');
    $roles = $roles_raw !== '' ? json_decode($roles_raw, true) : null;
    if (!is_array($roles) || empty($roles)) {
        $roles = ['Giám đốc', 'Kế toán trưởng', 'Thủ kho', 'Người lên đơn'];
    }

    $signs_raw = oc_get_setting('order_coffee.signs', '');
    $signs = $signs_raw !== '' ? json_decode($signs_raw, true) : null;
    if (!is_array($signs)) {
        $signs = [
            ['role' => 'Người lên đơn',  'name' => ''],
            ['role' => 'Thủ kho',        'name' => ''],
            ['role' => 'Giám đốc',       'name' => ''],
        ];
    }

    return [
        'company_name'    => oc_get_setting('order_coffee.company_name', 'VUA AN TOÀN'),
        'company_enname'  => oc_get_setting('order_coffee.company_enname', 'SAFE KING Co,.LTD'),
        'company_address' => oc_get_setting('order_coffee.company_address', '1/13Z Ấp Tiền Lân, Xã Bà Điểm, TP Hồ Chí Minh, Việt Nam'),
        'company_phone'   => oc_get_setting('order_coffee.company_phone', ''),
        'company_email'   => oc_get_setting('order_coffee.company_email', ''),
        'sign_roles'      => array_values(array_filter(array_map('strval', $roles))),
        'signs'           => $signs,
    ];
}

/* ============================================================
 *  Tìm kiếm: nhà cung cấp / sản phẩm / nguyên liệu / bao bì
 * ============================================================ */
function oc_search_suppliers($keyword)
{
    $kw = trim((string) $keyword);
    $k  = escape_string($kw);
    $where = $kw === '' ? '' : "WHERE supplier_name LIKE '%$k%'";
    $rows = db_fetch_array(
        "SELECT id, supplier_name, phone_number, email, website, address
         FROM suppliers $where ORDER BY supplier_name ASC LIMIT 15"
    ) ?: [];
    foreach ($rows as &$r) $r['id'] = (int) $r['id'];
    unset($r);
    return $rows;
}

function oc_supplier_info($supplier_id)
{
    oc_ensure_tables();
    $sid = (int) $supplier_id;
    if ($sid <= 0) return null;
    $row = db_fetch_row("SELECT * FROM suppliers WHERE id = $sid LIMIT 1");
    if ($row) $row['id'] = (int) $row['id'];
    return $row;
}

function oc_update_supplier($supplier_id, $data)
{
    oc_ensure_tables();
    $sid = (int) $supplier_id;
    if ($sid <= 0) return false;
    $allow = ['supplier_name', 'phone_number', 'email', 'website', 'address'];
    $set = [];
    foreach ($allow as $f) {
        if (array_key_exists($f, $data)) $set[$f] = (string) $data[$f];
    }
    if (empty($set)) return false;
    db_update('suppliers', $set, "id = $sid");
    return true;
}

function oc_search_products($keyword)
{
    $kw = trim((string) $keyword);
    $k  = escape_string($kw);
    $where = $kw === '' ? '' : "WHERE product_name LIKE '%$k%'";
    $rows = db_fetch_array(
        "SELECT id, product_name, COALESCE(NULLIF(unit,''),'') AS unit
         FROM products $where ORDER BY product_name ASC LIMIT 15"
    ) ?: [];
    $out = [];
    foreach ($rows as $r) {
        $out[] = [
            'id'   => (int) $r['id'],
            'name' => (string) $r['product_name'],
            'unit' => (string) $r['unit'],
        ];
    }
    return $out;
}

/** Tìm NVL (cho dòng thành phần). */
function oc_search_materials($keyword)
{
    $kw = trim((string) $keyword);
    $k  = escape_string($kw);
    $where = $kw === '' ? '' : "WHERE (material_name LIKE '%$k%' OR common_material_name LIKE '%$k%')";
    $rows = db_fetch_array(
        "SELECT id, material_name, common_material_name, COALESCE(NULLIF(unit,''),'') AS unit
         FROM material_information $where
         ORDER BY material_name ASC LIMIT 15"
    ) ?: [];
    $out = [];
    foreach ($rows as $r) {
        $out[] = [
            'id'   => (int) $r['id'],
            'name' => oc_mat_display_name($r),
            'unit' => (string) $r['unit'],
        ];
    }
    return $out;
}

/** Tìm bao bì: ưu tiên material_information có classification chứa "Bao bì". */
function oc_search_packaging($keyword)
{
    $kw = trim((string) $keyword);
    $k  = escape_string($kw);
    $hasClass = db_num_rows("SHOW COLUMNS FROM material_information LIKE 'classification'") > 0;
    $cls = $hasClass ? "COALESCE(classification,'')" : "''";
    $where = $kw === '' ? '' : "WHERE (material_name LIKE '%$k%' OR common_material_name LIKE '%$k%')";
    $rows = db_fetch_array(
        "SELECT id, material_name, common_material_name, $cls AS classification,
                COALESCE(NULLIF(unit,''),'') AS unit
         FROM material_information $where
         ORDER BY ($cls LIKE '%Bao bì%') DESC, material_name ASC LIMIT 15"
    ) ?: [];
    $out = [];
    foreach ($rows as $r) {
        $out[] = [
            'id'             => (int) $r['id'],
            'name'           => oc_mat_display_name($r),
            'unit'           => (string) $r['unit'],
            'classification' => (string) ($r['classification'] ?? ''),
        ];
    }
    return $out;
}

/* ============================================================
 *  Công thức đặt hàng (thiết lập sản phẩm)
 * ============================================================ */
function oc_clean_materials($materials)
{
    $clean = [];
    foreach ((array) $materials as $m) {
        $mid = (int) ($m['material_id'] ?? 0);
        $nm  = trim((string) ($m['name'] ?? ''));
        if ($mid <= 0 && $nm === '') continue;
        $clean[] = [
            'material_id' => $mid,
            'name'        => $nm,
            'unit'        => (string) ($m['unit'] ?? ''),
            'qty'         => (float) ($m['qty'] ?? 0),
        ];
    }
    return $clean;
}

function oc_save_recipe($payload)
{
    oc_ensure_tables();
    $pid = (int) ($payload['product_id'] ?? 0);
    if ($pid <= 0) return ['success' => false, 'message' => 'Chưa chọn sản phẩm.'];

    $mats = oc_clean_materials($payload['materials'] ?? []);
    if (empty($mats)) return ['success' => false, 'message' => 'Chưa có nguyên liệu nào.'];

    $yield = (float) ($payload['yield_qty'] ?? 1);
    if ($yield <= 0) $yield = 1;

    $data = [
        'product_id'     => $pid,
        'product_name'   => (string) ($payload['product_name'] ?? ''),
        'yield_qty'      => $yield,
        'packaging_id'   => (int) ($payload['packaging_id'] ?? 0) > 0 ? (int) $payload['packaging_id'] : null,
        'packaging_name' => (string) ($payload['packaging_name'] ?? ''),
        'note'           => (string) ($payload['note'] ?? ''),
        'materials'      => json_encode($mats, JSON_UNESCAPED_UNICODE),
    ];

    $exists = db_num_rows("SELECT 1 FROM oc_recipes WHERE product_id = $pid") > 0;
    if ($exists) {
        db_update('oc_recipes', $data, "product_id = $pid");
    } else {
        db_insert('oc_recipes', $data);
    }
    return ['success' => true];
}

function oc_decode_recipe_row($r)
{
    $mats = json_decode((string) $r['materials'], true);
    return [
        'id'             => (int) $r['id'],
        'product_id'     => (int) $r['product_id'],
        'product_name'   => (string) $r['product_name'],
        'yield_qty'      => (float) $r['yield_qty'],
        'packaging_id'   => $r['packaging_id'] !== null ? (int) $r['packaging_id'] : 0,
        'packaging_name' => (string) $r['packaging_name'],
        'note'           => (string) $r['note'],
        'materials'      => is_array($mats) ? $mats : [],
    ];
}

function oc_list_recipes()
{
    oc_ensure_tables();
    $rows = db_fetch_array(
        "SELECT id, product_id, product_name, yield_qty, packaging_id, packaging_name, note, materials
         FROM oc_recipes ORDER BY product_name ASC, id DESC"
    ) ?: [];
    return array_map('oc_decode_recipe_row', $rows);
}

function oc_get_recipe($product_id)
{
    oc_ensure_tables();
    $pid = (int) $product_id;
    if ($pid <= 0) return null;
    $row = db_fetch_row(
        "SELECT id, product_id, product_name, yield_qty, packaging_id, packaging_name, note, materials
         FROM oc_recipes WHERE product_id = $pid LIMIT 1"
    );
    return $row ? oc_decode_recipe_row($row) : null;
}

function oc_delete_recipe($id)
{
    oc_ensure_tables();
    $rid = (int) $id;
    if ($rid <= 0) return false;
    db_delete('oc_recipes', "id = $rid");
    return true;
}

/** Cập nhật riêng ghi chú của 1 SP (ghi chú nhập 1 lần, dùng nhiều lần). */
function oc_update_recipe_note($product_id, $note)
{
    oc_ensure_tables();
    $pid = (int) $product_id;
    if ($pid <= 0) return false;
    if (db_num_rows("SELECT 1 FROM oc_recipes WHERE product_id = $pid") <= 0) return false;
    db_update('oc_recipes', ['note' => (string) $note], "product_id = $pid");
    return true;
}

/* ============================================================
 *  Đơn đặt hàng — lưu / danh sách / chi tiết / nhận / xóa
 * ============================================================ */
function oc_clean_groups($groups)
{
    $clean = [];
    foreach ((array) $groups as $g) {
        $items = [];
        foreach ((array) ($g['items'] ?? []) as $it) {
            $qty = (float) ($it['qty'] ?? 0);
            if ($qty <= 0) continue;
            $items[] = [
                'material_id' => (int) ($it['material_id'] ?? 0),
                'name'        => (string) ($it['name'] ?? ''),
                'unit'        => (string) ($it['unit'] ?? ''),
                'qty'         => $qty,
            ];
        }
        if (empty($items)) continue;
        $clean[] = [
            'product_id'   => (int) ($g['product_id'] ?? 0),
            'product_name' => (string) ($g['product_name'] ?? ''),
            'order_qty'    => (float) ($g['order_qty'] ?? 0),
            'note'         => (string) ($g['note'] ?? ''),
            'items'        => $items,
        ];
    }
    return $clean;
}

function oc_save_order($payload, $user_id = 0)
{
    oc_ensure_tables();
    $groups = oc_clean_groups($payload['groups'] ?? []);
    if (empty($groups)) return 0;

    $type = (string) ($payload['order_type'] ?? 'process');
    if (!in_array($type, ['process', 'material'], true)) $type = 'process';

    $data = [
        'user_id'       => (int) $user_id > 0 ? (int) $user_id : null,
        'supplier_id'   => (int) ($payload['supplier_id'] ?? 0) > 0 ? (int) $payload['supplier_id'] : null,
        'supplier_name' => (string) ($payload['supplier_name'] ?? ''),
        'order_type'    => $type,
        'groups'        => json_encode($groups, JSON_UNESCAPED_UNICODE),
        'note'          => (string) ($payload['note'] ?? ''),
        'status'        => 'new',
        // Chốt giá trị dự kiến NGAY LÚC LƯU để đối soát cố định về sau —
        // không tính lại theo giá nhập mới (giá có thể đổi sau khi nhập phiếu).
        'expected_value' => round(oc_expected_import_value($groups), 2),
    ];
    return (int) db_insert('oc_orders', $data);
}

/** Giá nhập kho (đã gồm CPMH) gần nhất của 1 sản phẩm, từ product_purchase_prices. 0 nếu chưa có lần nhập nào. */
function oc_latest_price_including_tax($product_id)
{
    $pid = (int) $product_id;
    if ($pid <= 0) return 0.0;
    $row = db_fetch_row(
        "SELECT price_including_tax FROM product_purchase_prices
         WHERE product_id = $pid ORDER BY created_at DESC, id DESC LIMIT 1"
    );
    return ($row && $row['price_including_tax'] !== null) ? (float) $row['price_including_tax'] : 0.0;
}

/** Giá nhập kho (đã gồm CPMH) của 1 SP TẠI THỜI ĐIỂM $at — dùng tái dựng giá trị dự kiến cho
 *  đơn CŨ chưa có snapshot, tránh trôi theo giá nhập sau này.
 *  LƯU Ý: product_purchase_prices là bảng upsert CHỈ GIỮ GIÁ MỚI NHẤT (không có lịch sử) —
 *  lịch sử thật nằm ở purchase_price_changes (ghi mỗi lần giá đổi, new_price = giá sau lần đổi). */
function oc_price_including_tax_at($product_id, $at)
{
    $pid = (int) $product_id;
    if ($pid <= 0) return 0.0;
    $atEsc = escape_string((string) $at);
    if (db_num_rows("SHOW TABLES LIKE 'purchase_price_changes'") > 0) {
        $row = db_fetch_row(
            "SELECT new_price FROM purchase_price_changes
             WHERE product_id = $pid AND created_at <= '$atEsc'
             ORDER BY created_at DESC, id DESC LIMIT 1"
        );
        if ($row && $row['new_price'] !== null) return (float) $row['new_price'];
    }
    return oc_latest_price_including_tax($pid);
}

/** Giá trị nhập kho dự kiến của cả đơn = Σ(order_qty của từng nhóm SP × giá nhập của SP đó).
 *  $at = null: giá gần nhất (dùng lúc LƯU đơn mới); $at = datetime: giá tại thời điểm đó
 *  (tái dựng cho đơn cũ chưa có snapshot). */
function oc_expected_import_value($groups, $at = null)
{
    $total = 0.0;
    foreach ($groups as $g) {
        $pid = (int) ($g['product_id'] ?? 0);
        $qty = (float) ($g['order_qty'] ?? 0);
        if ($pid <= 0 || $qty <= 0) continue;
        $total += $qty * ($at !== null ? oc_price_including_tax_at($pid, $at) : oc_latest_price_including_tax($pid));
    }
    return $total;
}

function oc_decode_order_row($r)
{
    $groups = json_decode((string) $r['groups'], true);
    $groups = is_array($groups) ? $groups : [];
    $itemCount = 0;
    foreach ($groups as $g) $itemCount += count($g['items'] ?? []);
    // Giá trị dự kiến: ưu tiên snapshot lưu lúc tạo đơn (cố định để đối soát).
    // Đơn cũ chưa có snapshot -> tái dựng theo giá TẠI thời điểm tạo đơn, không dùng giá mới nhất.
    $expected = ($r['expected_value'] ?? null) !== null
        ? (float) $r['expected_value']
        : oc_expected_import_value($groups, $r['created_at']);
    return [
        'id'            => (int) $r['id'],
        'supplier_id'   => $r['supplier_id'] !== null ? (int) $r['supplier_id'] : 0,
        'supplier_name' => (string) $r['supplier_name'],
        'order_type'    => (string) $r['order_type'],
        'groups'        => $groups,
        'note'          => (string) $r['note'],
        'status'        => (string) $r['status'],
        'received'      => (int) $r['received'],
        'received_at'   => $r['received_at'],
        'created_at'    => $r['created_at'],
        'group_count'   => count($groups),
        'item_count'    => $itemCount,
        'expected_import_value' => $expected,
        'actual_value'  => ($r['actual_value'] ?? null) !== null ? (float) $r['actual_value'] : null,
    ];
}

function oc_list_orders($limit = 50)
{
    oc_ensure_tables();
    $lim = (int) $limit;
    if ($lim <= 0) $lim = 50;
    $rows = db_fetch_array(
        "SELECT id, supplier_id, supplier_name, order_type, groups, note,
                status, received, received_at, expected_value, actual_value, created_at
         FROM oc_orders WHERE hidden = 0
         ORDER BY created_at DESC, id DESC LIMIT $lim"
    ) ?: [];
    return array_map('oc_decode_order_row', $rows);
}

function oc_get_order($id)
{
    oc_ensure_tables();
    $oid = (int) $id;
    if ($oid <= 0) return null;
    $row = db_fetch_row(
        "SELECT id, supplier_id, supplier_name, order_type, groups, note,
                status, received, received_at, expected_value, actual_value, created_at
         FROM oc_orders WHERE id = $oid AND hidden = 0 LIMIT 1"
    );
    return $row ? oc_decode_order_row($row) : null;
}

function oc_set_received($id, $received, $expected_value = null, $actual_value = null)
{
    oc_ensure_tables();
    $oid = (int) $id;
    if ($oid <= 0) return false;
    $rc = $received ? 1 : 0;
    $data = [
        'received'    => $rc,
        'status'      => $rc ? 'received' : 'new',
        'received_at' => $rc ? date('Y-m-d H:i:s') : null,
    ];
    if ($rc && $expected_value !== null) $data['expected_value'] = (float) $expected_value;
    $data['actual_value'] = ($rc && $actual_value !== null) ? (float) $actual_value : null;
    db_update('oc_orders', $data, "id = $oid");
    return true;
}

/**
 * Gọi khi CHUẨN BỊ ghi 1 phiếu nhập có dòng THÀNH PHẨM (row_material_receiving gộp),
 * TRƯỚC khi phiếu ghi đè product_purchase_prices. Chỉ ĐỌC — không ghi gì.
 * Nếu bộ SP của phiếu khớp đúng bộ SP của 1 đơn cà phê đã lưu (cùng NCC, chưa nhận)
 * -> trả so sánh giá trị dự kiến (snapshot lúc đặt) với giá trị thực nhập.
 * Đánh dấu "Đã nhận" chỉ thực hiện SAU khi phiếu ghi thành công —
 * gọi oc_set_received($result['order_id'], true, expected, actual) lúc đó.
 * $product_lines: [ ['product_id'=>, 'qty'=>, 'price_incl'=>], ... ]
 * Mirror om_match_order_for_receipt() của order_material.
 */
function oc_match_order_for_receipt($supplier_id, array $product_lines)
{
    oc_ensure_tables();
    $sid = (int) $supplier_id;
    if ($sid <= 0) return null;

    $pids = [];
    foreach ($product_lines as $l) {
        $pid = (int) ($l['product_id'] ?? 0);
        if ($pid > 0) $pids[$pid] = true;
    }
    $set = array_keys($pids);
    if (empty($set)) return null;
    sort($set);

    $rows = db_fetch_array(
        "SELECT id, supplier_id, supplier_name, groups, expected_value, created_at
         FROM oc_orders
         WHERE supplier_id = $sid AND hidden = 0 AND received = 0
         ORDER BY created_at ASC, id ASC"
    ) ?: [];

    foreach ($rows as $r) {
        $groups = json_decode((string) $r['groups'], true);
        if (!is_array($groups) || empty($groups)) continue;
        $oSet = [];
        foreach ($groups as $g) {
            $pid = (int) ($g['product_id'] ?? 0);
            if ($pid > 0) $oSet[$pid] = true;
        }
        $oKeys = array_keys($oSet);
        sort($oKeys);
        if ($oKeys !== $set) continue;

        $expected = ($r['expected_value'] ?? null) !== null
            ? (float) $r['expected_value']
            : oc_expected_import_value($groups, $r['created_at']);
        $actual = 0.0;
        foreach ($product_lines as $l) {
            $pid = (int) ($l['product_id'] ?? 0);
            if (!isset($oSet[$pid])) continue;
            $actual += (float) ($l['qty'] ?? 0) * (float) ($l['price_incl'] ?? 0);
        }
        return [
            'order_id'       => (int) $r['id'],
            'supplier_name'  => (string) $r['supplier_name'],
            'created_at'     => $r['created_at'],
            'expected_value' => round($expected, 2),
            'actual_value'   => round($actual, 2),
            'diff'           => round($actual - $expected, 2),
            'diff_rate'      => $expected > 0 ? round((($actual - $expected) / $expected) * 100, 2) : 0.0,
        ];
    }
    return null;
}

function oc_delete_order($id)
{
    oc_ensure_tables();
    $oid = (int) $id;
    if ($oid <= 0) return false;
    db_update('oc_orders', ['hidden' => 1], "id = $oid");
    return true;
}

/* ============================================================
 *  Sổ bao bì tại NCC (gọi qua libraries/coffee_packaging.php)
 * ============================================================ */
/** Trả về {entries, balances, total} của 1 NCC. */
function oc_packaging_book($supplier_id)
{
    oc_ensure_tables();
    $sid = (int) $supplier_id;
    if ($sid <= 0) return ['entries' => [], 'balances' => [], 'total' => 0.0];
    $entries  = coffee_pkg_entries($sid);
    $balances = coffee_pkg_balances($sid);
    $total = 0.0;
    foreach ($balances as $b) $total += (float) $b['balance'];

    // Gắn ảnh dẫn chứng (nếu có) vào từng dòng — 1 query cho cả sổ.
    $ids = [];
    foreach ($entries as $e) { $id = (int) ($e['id'] ?? 0); if ($id > 0) $ids[] = $id; }
    $evMap = [];
    if (!empty($ids)) {
        $rows = db_fetch_array(
            "SELECT id, entry_id, file_path FROM oc_ledger_evidence
             WHERE entry_id IN (" . implode(',', $ids) . ") ORDER BY id ASC"
        ) ?: [];
        foreach ($rows as $r) {
            $evMap[(int) $r['entry_id']][] = ['id' => (int) $r['id'], 'file_path' => (string) $r['file_path']];
        }
    }
    foreach ($entries as &$e) {
        $e['evidence'] = $evMap[(int) ($e['id'] ?? 0)] ?? [];
    }
    unset($e);

    return ['entries' => $entries, 'balances' => $balances, 'total' => $total];
}

/* ============================================================
 *  Ảnh dẫn chứng dòng sổ bao bì (dán Ctrl+V vào ô Ngày)
 * ============================================================ */

/** Lưu ảnh dẫn chứng cho 1 dòng sổ bao bì. $files = $_FILES['files'] (hỗ trợ nhiều ảnh/lần dán). */
function oc_ledger_evidence_add($entry_id, $files)
{
    oc_ensure_tables();
    $eid = (int) $entry_id;
    if ($eid <= 0 || empty($files) || !isset($files['name'])) {
        return ['success' => false, 'message' => 'Thiếu entry_id hoặc tệp ảnh.'];
    }
    $exists = db_fetch_row("SELECT id FROM oc_packaging_ledger WHERE id = $eid LIMIT 1");
    if (!$exists) return ['success' => false, 'message' => 'Không tìm thấy dòng sổ bao bì.'];

    $allowed = ['jpg', 'jpeg', 'png', 'gif', 'webp'];
    $abs_dir = dirname(__DIR__, 3) . DIRECTORY_SEPARATOR . 'public' . DIRECTORY_SEPARATOR . 'uploads' . DIRECTORY_SEPARATOR . 'order_coffee';
    if (!is_dir($abs_dir)) @mkdir($abs_dir, 0777, true);
    $rel_web = 'public/uploads/order_coffee';

    $names = (array) $files['name'];
    $tmps  = (array) $files['tmp_name'];
    $errs  = (array) $files['error'];
    $saved = [];
    foreach ($names as $i => $name) {
        if (($errs[$i] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) continue;
        if (($errs[$i] ?? 1) !== UPLOAD_ERR_OK) continue;
        $ext = strtolower(pathinfo($name, PATHINFO_EXTENSION));
        if ($ext === '') $ext = 'png'; // ảnh dán từ clipboard thường là blob image.png
        if (!in_array($ext, $allowed, true)) continue;
        $filename = 'evid_' . $eid . '_' . time() . '_' . substr(md5($name . mt_rand()), 0, 6) . '.' . $ext;
        $abs_path = $abs_dir . DIRECTORY_SEPARATOR . $filename;
        $moved = is_uploaded_file($tmps[$i]) ? move_uploaded_file($tmps[$i], $abs_path) : @rename($tmps[$i], $abs_path);
        if (!$moved) continue;
        $file_path = $rel_web . '/' . $filename;
        $id = (int) db_insert('oc_ledger_evidence', ['entry_id' => $eid, 'file_path' => $file_path]);
        $saved[] = ['id' => $id, 'file_path' => $file_path];
    }
    if (empty($saved)) return ['success' => false, 'message' => 'Không lưu được ảnh nào.'];
    return ['success' => true, 'saved' => $saved];
}

/** Xóa 1 ảnh dẫn chứng (gỡ cả file vật lý). */
function oc_ledger_evidence_delete($evidence_id)
{
    oc_ensure_tables();
    $id = (int) $evidence_id;
    if ($id <= 0) return false;
    $row = db_fetch_row("SELECT file_path FROM oc_ledger_evidence WHERE id = $id LIMIT 1");
    if ($row) {
        $abs = dirname(__DIR__, 3) . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, (string) $row['file_path']);
        if (is_file($abs)) @unlink($abs);
    }
    db_delete('oc_ledger_evidence', "id = $id");
    return true;
}

/**
 * Thêm nghiệp vụ bao bì thủ công.
 * $type: 'opening' (NCC còn) | 'send' (VAT gửi qua) | 'loss' (hao hụt).
 */
function oc_packaging_add($supplier_id, $packaging_id, $packaging_name, $type, $qty, $date = null)
{
    oc_ensure_tables();
    $sid = (int) $supplier_id;
    $pid = (int) $packaging_id;
    $q   = (float) $qty;
    if ($sid <= 0 || $pid <= 0 || $q <= 0) {
        return ['success' => false, 'message' => 'Thiếu thông tin nghiệp vụ bao bì.'];
    }
    $map = [
        'opening' => ['NCC còn', 1],
        'send'    => ['VAT gửi qua', 1],
        'loss'    => ['Hao hụt', -1],
    ];
    if (!isset($map[$type])) return ['success' => false, 'message' => 'Loại nghiệp vụ không hợp lệ.'];
    list($content, $sign) = $map[$type];

    coffee_pkg_add_entry(
        $sid,
        $pid,
        (string) $packaging_name,
        $type,
        $content,
        $sign * $q,
        $date,
        ['user_id' => function_exists('oc_current_user_id') ? oc_current_user_id() : 0]
    );
    return ['success' => true];
}

/** Các mốc (NCC còn / Xác nhận tồn) của 1 loại bao bì tại 1 NCC — mới nhất trước. */
function oc_packaging_milestones($supplier_id, $packaging_id)
{
    oc_ensure_tables();
    return coffee_pkg_milestones((int) $supplier_id, (int) $packaging_id);
}

/** Sổ bao bì của 1 loại bao bì tại 1 NCC, tính từ 1 mốc trở đi ($milestone_id = 0 -> mốc mới nhất). */
function oc_packaging_report($supplier_id, $packaging_id, $milestone_id = 0)
{
    oc_ensure_tables();
    return coffee_pkg_windowed_entries((int) $supplier_id, (int) $packaging_id, (int) $milestone_id);
}

/**
 * "Chốt tồn": ghi nhận số dư hiện tại (đã tính từ mốc mới nhất) khớp với tồn thực tế
 * phía NCC -> tạo 1 mốc 'confirm' mới (Xác nhận tồn) ở NGÀY HÔM NAY, làm điểm bắt đầu
 * lại cho lần tính tiếp theo (tránh sổ phát sinh dài vô hạn nếu không có mốc).
 */
function oc_packaging_confirm($supplier_id, $packaging_id, $packaging_name)
{
    oc_ensure_tables();
    $sid = (int) $supplier_id;
    $pid = (int) $packaging_id;
    if ($sid <= 0 || $pid <= 0) return ['success' => false, 'message' => 'Thiếu thông tin bao bì / NCC.'];

    $current = coffee_pkg_windowed_entries($sid, $pid, 0);
    $qty = (float) $current['total'];

    coffee_pkg_add_entry(
        $sid,
        $pid,
        (string) $packaging_name,
        'confirm',
        'Xác nhận tồn',
        $qty,
        date('Y-m-d'),
        ['user_id' => function_exists('oc_current_user_id') ? oc_current_user_id() : 0]
    );
    return ['success' => true, 'qty' => $qty];
}
