<?php
/**
 * =====================================================================
 *  Đặt hàng NVL (order_material) — Model
 * =====================================================================
 *  Bảng dùng:
 *   - suppliers                          : nhà cung cấp (search + info)
 *   - material_information               : NVL (supplier_id, common_material_name)
 *   - material_supplier_map              : NVL <-> NCC (nhiều NCC / 1 NVL)
 *   - material_inventory                 : tồn NVL hiện tại
 *   - material_min_stock_settings        : định mức tồn tối thiểu + cảnh báo
 *   - raw_material_production_issue_data : NVL xuất cho sản xuất (định mức dùng)
 *   - finished_product_production_data   : thành phẩm tạo ra
 *   - finished_goods_inventory           : tồn thành phẩm
 *   - sales_inventory_issue_data         : xuất kho bán hàng
 *   - product_materials                  : công thức (định mức BOM)
 *   - stock_imports                      : lần nhập kho NVL gần đây
 *   - material_purchase_orders           : đơn đặt hàng NVL (lưu/nhận/xóa)
 *   - om_material_hidden_supplier        : NVL tạm ẩn khỏi danh sách 1 NCC
 *   - app_settings                       : tiêu đề phiếu + tên người ký
 *
 *  Prefix hàm: om_*.
 */

/* ============================================================
 *  Khởi tạo bảng (idempotent)
 * ============================================================ */
function om_ensure_tables()
{
    static $done = false;
    if ($done) return;
    $done = true;

    db_query("CREATE TABLE IF NOT EXISTS material_min_stock_settings (
        material_id  INT           NOT NULL PRIMARY KEY,
        min_quantity DECIMAL(15,3) NOT NULL DEFAULT 0,
        lead_days    INT           NOT NULL DEFAULT 0,
        usage_window VARCHAR(10)   NOT NULL DEFAULT 'none',
        updated_at   TIMESTAMP     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci");

    db_query("CREATE TABLE IF NOT EXISTS material_purchase_orders (
        id            INT AUTO_INCREMENT PRIMARY KEY,
        user_id       INT          DEFAULT NULL,
        supplier_id   INT          DEFAULT NULL,
        supplier_name VARCHAR(255) DEFAULT NULL,
        order_items   LONGTEXT     DEFAULT NULL,
        note          TEXT         DEFAULT NULL,
        status        VARCHAR(20)  NOT NULL DEFAULT 'new',
        received      TINYINT(1)   NOT NULL DEFAULT 0,
        received_at   DATETIME     DEFAULT NULL,
        hidden        TINYINT(1)   NOT NULL DEFAULT 0,
        created_at    TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
        KEY idx_supplier (supplier_id),
        KEY idx_created  (created_at)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci");

    // Snapshot giá trị lúc tự động xác nhận "Đã nhận" (khớp phiếu ở inventory_receiving) — dùng để
    // daily_dashboard hiển thị so sánh dự kiến/thực nhận mà không bị trôi theo giá NVL cập nhật sau đó.
    db_query("ALTER TABLE material_purchase_orders ADD COLUMN IF NOT EXISTS expected_value_snapshot DECIMAL(15,2) DEFAULT NULL AFTER received_at");
    db_query("ALTER TABLE material_purchase_orders ADD COLUMN IF NOT EXISTS actual_value_snapshot   DECIMAL(15,2) DEFAULT NULL AFTER expected_value_snapshot");

    db_query("CREATE TABLE IF NOT EXISTS app_settings (
        setting_key   VARCHAR(100) NOT NULL PRIMARY KEY,
        setting_value TEXT DEFAULT NULL
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci");

    // "Tạm ẩn" NVL khỏi danh sách của 1 NCC (không xóa dữ liệu — chỉ ẩn/hiện lại khi search).
    // Khóa theo CẶP (material_id, supplier_id) vì 1 NVL có thể gắn nhiều NCC (material_supplier_map).
    db_query("CREATE TABLE IF NOT EXISTS om_material_hidden_supplier (
        material_id INT      NOT NULL,
        supplier_id INT      NOT NULL,
        hidden_at   DATETIME NOT NULL,
        PRIMARY KEY (material_id, supplier_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci");

    // Cột địa chỉ NCC (có thể đã được module công nợ NCC thêm trước đó).
    db_query("ALTER TABLE suppliers ADD COLUMN IF NOT EXISTS address VARCHAR(255) DEFAULT NULL");
}

/** Đăng ký view vào menu "SẢN XUẤT" (idempotent — chạy ở trang chính). */
function om_ensure_view_registered()
{
    if (db_num_rows("SHOW TABLES LIKE 'tbl_views'") <= 0) return;
    db_query("INSERT IGNORE INTO tbl_views (module, controller, action, label, group_label, sort)
              VALUES ('order_material','order_material','order_material','Đặt hàng NVL','SẢN XUẤT', 45)");
}

/* ============================================================
 *  Helpers
 * ============================================================ */

/** Tên hiển thị NVL: lấy theo TÊN PHỔ THÔNG (material_name), fallback tên thường gọi. */
function om_display_name($row)
{
    $m = trim((string) ($row['material_name'] ?? ''));
    return $m !== '' ? $m : (string) ($row['common_material_name'] ?? '');
}

/** Số ngày tương ứng "Thời gian dùng". 0 = không xét thời gian. */
function om_window_days($code)
{
    switch ((string) $code) {
        case '1m': return 30;
        case '3m': return 90;
        case '6m': return 180;
        case '1y': return 365;
        default:   return 0; // none
    }
}

/* ============================================================
 *  app_settings (tiêu đề phiếu + tên người ký) — sửa 1 lần dùng nhiều lần
 * ============================================================ */
function om_get_setting($key, $default = '')
{
    om_ensure_tables();
    $k = escape_string((string) $key);
    $row = db_fetch_row("SELECT setting_value FROM app_settings WHERE setting_key = '$k' LIMIT 1");
    if (!$row || $row['setting_value'] === null) return $default;
    return $row['setting_value'];
}

function om_set_setting($key, $value)
{
    om_ensure_tables();
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

/** Toàn bộ thông tin cố định của phiếu đặt hàng (có default). */
function om_doc_settings()
{
    // Danh mục chức danh ký (có thể thêm/sửa/xóa).
    $roles_raw = om_get_setting('order_material.sign_roles', '');
    $roles = $roles_raw !== '' ? json_decode($roles_raw, true) : null;
    if (!is_array($roles) || empty($roles)) {
        $roles = ['Giám đốc', 'Kế toán trưởng', 'Thủ kho', 'Người lên đơn'];
    }

    // Các cụm chữ ký đang hiển thị (đã chọn) — [{role, name}].
    $signs_raw = om_get_setting('order_material.signs', '');
    $signs = $signs_raw !== '' ? json_decode($signs_raw, true) : null;
    if (!is_array($signs)) {
        // Seed lần đầu từ 3 người ký cũ (tương thích ngược).
        $signs = [
            ['role' => 'Người lên đơn',  'name' => om_get_setting('order_material.signer_orderer', '')],
            ['role' => 'Thủ kho',        'name' => om_get_setting('order_material.signer_warehouse', '')],
            ['role' => 'Kế toán trưởng', 'name' => om_get_setting('order_material.signer_accountant', '')],
        ];
    }

    return [
        'company_name'      => om_get_setting('order_material.company_name', 'VUA AN TOÀN'),
        'company_enname'    => om_get_setting('order_material.company_enname', 'SAFE KING Co,.LTD'),
        'company_address'   => om_get_setting('order_material.company_address', '1/13Z Ấp Tiền Lân, Xã Bà Điểm, TP Hồ Chí Minh, Việt Nam'),
        'company_phone'     => om_get_setting('order_material.company_phone', ''),
        'company_email'     => om_get_setting('order_material.company_email', ''),
        'signer_orderer'    => om_get_setting('order_material.signer_orderer', ''),
        'signer_warehouse'  => om_get_setting('order_material.signer_warehouse', ''),
        'signer_accountant' => om_get_setting('order_material.signer_accountant', ''),
        'sign_roles'        => array_values(array_filter(array_map('strval', $roles))),
        'signs'             => $signs,
    ];
}

/* ============================================================
 *  Nhà cung cấp (search dropdown + info)
 * ============================================================ */
function om_search_suppliers($keyword)
{
    $kw = trim((string) $keyword);
    $k  = escape_string($kw);
    $where = $kw === '' ? '' : "WHERE supplier_name LIKE '%$k%'";
    $sql = "SELECT id, supplier_name, phone_number, email, website, address
            FROM suppliers
            $where
            ORDER BY supplier_name ASC
            LIMIT 15";
    $rows = db_fetch_array($sql) ?: [];
    foreach ($rows as &$r) $r['id'] = (int) $r['id'];
    unset($r);
    return $rows;
}

function om_supplier_info($supplier_id)
{
    om_ensure_tables();
    $sid = (int) $supplier_id;
    if ($sid <= 0) return null;
    $row = db_fetch_row("SELECT * FROM suppliers WHERE id = $sid LIMIT 1");
    if ($row) $row['id'] = (int) $row['id'];
    return $row;
}

/** Cập nhật thông tin NCC (sửa ngay trong modal -> ghi DB). */
function om_update_supplier($supplier_id, $data)
{
    om_ensure_tables();
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

/* ============================================================
 *  Danh sách NVL của 1 nhà cung cấp (+ tồn + cảnh báo nên đặt + SL đặt gần nhất)
 * ============================================================ */
function om_supplier_materials($supplier_id)
{
    om_ensure_tables();
    $sid = (int) $supplier_id;
    if ($sid <= 0) return [];

    // NVL gắn trực tiếp (supplier_id) HOẶC qua bảng map nhiều NCC — trừ NVL đang "tạm ẩn" với ĐÚNG NCC này.
    $sql = "SELECT mi.id, mi.material_name, mi.common_material_name,
                   COALESCE(NULLIF(mi.unit,''),'') AS unit,
                   COALESCE(inv.quantity,0) AS stock,
                   mss.min_quantity, mss.lead_days, mss.usage_window
            FROM material_information mi
            LEFT JOIN material_inventory inv ON inv.material_id = mi.id
            LEFT JOIN material_min_stock_settings mss ON mss.material_id = mi.id
            WHERE (mi.supplier_id = $sid
               OR EXISTS (SELECT 1 FROM material_supplier_map msm
                          WHERE msm.material_id = mi.id AND msm.supplier_id = $sid))
              AND NOT EXISTS (SELECT 1 FROM om_material_hidden_supplier h
                               WHERE h.material_id = mi.id AND h.supplier_id = $sid)
            ORDER BY mi.material_name ASC";
    $rows = db_fetch_array($sql) ?: [];

    $lastQty = om_last_order_qty_map($sid);

    $out = [];
    foreach ($rows as $r) {
        $mid   = (int) $r['id'];
        $stock = (float) $r['stock'];
        $hasSetting = $r['min_quantity'] !== null;
        $min   = $hasSetting ? (float) $r['min_quantity'] : 0.0;
        $win   = $hasSetting ? (string) $r['usage_window'] : 'none';

        // Cảnh báo nên đặt: tồn < định mức tối thiểu (đã thiết lập) VÀ
        // trong "Thời gian dùng" có dùng NVL này (none = bỏ qua điều kiện thời gian).
        $warn = false;
        if ($hasSetting && $stock < $min) {
            $days = om_window_days($win);
            $warn = ($days === 0) ? true : om_material_used_within($mid, $days);
        }

        $commonName = trim((string) ($r['common_material_name'] ?? ''));
        $out[] = [
            'id'           => $mid,
            'display_name' => om_display_name($r),
            'common_name'  => $commonName !== '' ? $commonName : (string) $r['material_name'],
            'unit'         => $r['unit'],
            'stock'        => $stock,
            'has_setting'  => $hasSetting,
            'min_quantity' => $min,
            'usage_window' => $win,
            'lead_days'    => $hasSetting ? (int) $r['lead_days'] : 0,
            'warn'         => $warn,
            'last_qty'     => isset($lastQty[$mid]) ? (float) $lastQty[$mid] : 0.0,
        ];
    }
    return $out;
}

/* ============================================================
 *  "Tạm ẩn" NVL khỏi danh sách của 1 NCC (theo cặp material_id + supplier_id)
 * ============================================================ */

function om_hide_material($material_id, $supplier_id)
{
    om_ensure_tables();
    $mid = (int) $material_id;
    $sid = (int) $supplier_id;
    if ($mid <= 0 || $sid <= 0) return false;
    $exists = db_num_rows(
        "SELECT 1 FROM om_material_hidden_supplier WHERE material_id = $mid AND supplier_id = $sid"
    ) > 0;
    if (!$exists) {
        db_insert('om_material_hidden_supplier', [
            'material_id' => $mid,
            'supplier_id' => $sid,
            'hidden_at'   => date('Y-m-d H:i:s'),
        ]);
    }
    return true;
}

function om_unhide_material($material_id, $supplier_id)
{
    om_ensure_tables();
    $mid = (int) $material_id;
    $sid = (int) $supplier_id;
    if ($mid <= 0 || $sid <= 0) return false;
    db_delete('om_material_hidden_supplier', "material_id = $mid AND supplier_id = $sid");
    return true;
}

/** Danh sách NVL đang "tạm ẩn" của 1 NCC — cho khối "Thành phần tạm ẩn". */
function om_hidden_materials($supplier_id)
{
    om_ensure_tables();
    $sid = (int) $supplier_id;
    if ($sid <= 0) return [];
    $rows = db_fetch_array(
        "SELECT mi.id, mi.material_name, mi.common_material_name, h.hidden_at
         FROM om_material_hidden_supplier h
         JOIN material_information mi ON mi.id = h.material_id
         WHERE h.supplier_id = $sid
         ORDER BY h.hidden_at DESC"
    ) ?: [];
    $out = [];
    foreach ($rows as $r) {
        $out[] = [
            'id'           => (int) $r['id'],
            'display_name' => om_display_name($r),
        ];
    }
    return $out;
}

/** NVL có được xuất cho sản xuất trong $days ngày qua không. */
function om_material_used_within($material_id, $days)
{
    $mid = (int) $material_id;
    $d   = (int) $days;
    if ($mid <= 0 || $d <= 0) return false;
    $row = db_fetch_row(
        "SELECT COALESCE(SUM(quantity),0) AS q
         FROM raw_material_production_issue_data
         WHERE material_id = $mid AND created_at >= (NOW() - INTERVAL $d DAY)"
    );
    return $row && (float) $row['q'] > 0;
}

/** Map material_id => số lượng đặt gần nhất (từ các đơn của NCC này). */
function om_last_order_qty_map($supplier_id)
{
    $sid = (int) $supplier_id;
    if ($sid <= 0) return [];
    $rows = db_fetch_array(
        "SELECT order_items FROM material_purchase_orders
         WHERE supplier_id = $sid AND hidden = 0
         ORDER BY created_at DESC, id DESC
         LIMIT 30"
    ) ?: [];
    $map = [];
    foreach ($rows as $r) {
        $items = json_decode((string) $r['order_items'], true);
        if (!is_array($items)) continue;
        foreach ($items as $it) {
            $mid = (int) ($it['material_id'] ?? 0);
            if ($mid <= 0 || isset($map[$mid])) continue; // đơn mới nhất xét trước -> giữ
            $map[$mid] = (float) ($it['qty'] ?? 0);
        }
    }
    return $map;
}

/* ============================================================
 *  Thiết lập tồn tối thiểu (modal list NVL của NCC)
 * ============================================================ */
function om_save_min_setting($material_id, $min_quantity, $lead_days, $usage_window)
{
    om_ensure_tables();
    $mid = (int) $material_id;
    if ($mid <= 0) return false;
    $valid = ['1m', '3m', '6m', '1y', 'none'];
    $win = in_array($usage_window, $valid, true) ? $usage_window : 'none';
    $data = [
        'min_quantity' => (float) $min_quantity,
        'lead_days'    => (int) $lead_days,
        'usage_window' => $win,
    ];
    $exists = db_num_rows("SELECT 1 FROM material_min_stock_settings WHERE material_id = $mid") > 0;
    if ($exists) {
        db_update('material_min_stock_settings', $data, "material_id = $mid");
    } else {
        $data['material_id'] = $mid;
        db_insert('material_min_stock_settings', $data);
    }
    return true;
}

/* ============================================================
 *  Phân tích NVL (modal khi click tên NVL)
 * ============================================================ */
function om_material_usage_sum($material_id, $days)
{
    $mid = (int) $material_id;
    $d   = (int) $days;
    $row = db_fetch_row(
        "SELECT COALESCE(SUM(quantity),0) AS q
         FROM raw_material_production_issue_data
         WHERE material_id = $mid AND created_at >= (NOW() - INTERVAL $d DAY)"
    );
    return $row ? (float) $row['q'] : 0.0;
}

function om_product_sales_sum($product_id, $days)
{
    $pid = (int) $product_id;
    $d   = (int) $days;
    $row = db_fetch_row(
        "SELECT COALESCE(SUM(quantity),0) AS q
         FROM sales_inventory_issue_data
         WHERE product_id = $pid AND created_at >= (NOW() - INTERVAL $d DAY)"
    );
    return $row ? (float) $row['q'] : 0.0;
}

function om_material_analysis($material_id)
{
    $mid = (int) $material_id;
    if ($mid <= 0) return null;

    $mat = db_fetch_row(
        "SELECT mi.id, mi.material_name, mi.common_material_name,
                COALESCE(NULLIF(mi.unit,''),'') AS unit,
                COALESCE(inv.quantity,0) AS stock
         FROM material_information mi
         LEFT JOIN material_inventory inv ON inv.material_id = mi.id
         WHERE mi.id = $mid LIMIT 1"
    );
    if (!$mat) return null;

    // Lần nhập kho NVL gần đây nhất.
    $last_import = db_fetch_row(
        "SELECT created_at, quantity FROM stock_imports
         WHERE material_id = $mid AND type_import = 'row_material_receiving'
         ORDER BY created_at DESC, id DESC LIMIT 1"
    );

    // Danh sách sản phẩm dùng NVL này.
    $prodRows = db_fetch_array(
        "SELECT pm.product_id, p.product_name, COALESCE(NULLIF(p.unit,''),'') AS unit,
                pm.quantity_required AS bom_norm,
                COALESCE(fgi.quantity,0) AS stock
         FROM product_materials pm
         JOIN products p ON p.id = pm.product_id
         LEFT JOIN finished_goods_inventory fgi ON fgi.product_id = p.id
         WHERE pm.material_id = $mid
         ORDER BY p.product_name ASC"
    ) ?: [];

    $products = [];
    foreach ($prodRows as $pr) {
        $pid = (int) $pr['product_id'];

        // Định mức dùng gần nhất cho SP này (lần xuất NVL gần nhất).
        $normRow = db_fetch_row(
            "SELECT quantity FROM raw_material_production_issue_data
             WHERE material_id = $mid AND product_id = $pid
             ORDER BY created_at DESC, id DESC LIMIT 1"
        );
        // Thành phẩm: số SP tạo ra của lần sản xuất gần nhất.
        $prodOut = db_fetch_row(
            "SELECT quantity FROM finished_product_production_data
             WHERE product_id = $pid
             ORDER BY created_at DESC, id DESC LIMIT 1"
        );

        $products[] = [
            'product_id'  => $pid,
            'name'        => (string) $pr['product_name'],
            'unit'        => (string) $pr['unit'],
            'bom_norm'    => (float) $pr['bom_norm'],
            'norm'        => $normRow ? (float) $normRow['quantity'] : (float) $pr['bom_norm'],
            'produced'    => $prodOut ? (float) $prodOut['quantity'] : 0.0,
            'stock'       => (float) $pr['stock'],
            'sale_1m'     => om_product_sales_sum($pid, 30),
            'sale_3m'     => om_product_sales_sum($pid, 90),
            'sale_6m'     => om_product_sales_sum($pid, 180),
        ];
    }

    return [
        'material_id'  => $mid,
        'display_name' => om_display_name($mat),
        'unit'         => (string) $mat['unit'],
        'stock'        => (float) $mat['stock'],
        'use_1m'       => om_material_usage_sum($mid, 30),
        'use_3m'       => om_material_usage_sum($mid, 90),
        'use_6m'       => om_material_usage_sum($mid, 180),
        'last_import'  => $last_import ? [
            'date' => (string) $last_import['created_at'],
            'qty'  => (float) $last_import['quantity'],
        ] : null,
        'products'     => $products,
    ];
}

/* ============================================================
 *  Đơn đặt hàng — lưu / danh sách / chi tiết / nhận / xóa
 * ============================================================ */
/**
 * Đơn trùng = CÙNG NGÀY (hôm nay) + CÙNG NCC + CÙNG TẬP MẶT HÀNG (material_id).
 * (Bỏ qua số lượng — chỉ xét danh mục mặt hàng.)
 */
function om_find_duplicate_order($supplier_id, $items)
{
    om_ensure_tables();
    $sid = (int) $supplier_id;
    if ($sid <= 0) return false;

    $newSet = [];
    foreach ((array) $items as $it) {
        $mid = (int) ($it['material_id'] ?? 0);
        if ($mid > 0) $newSet[$mid] = true;
    }
    if (empty($newSet)) return false;
    $newKeys = array_keys($newSet);
    sort($newKeys);

    $rows = db_fetch_array(
        "SELECT order_items FROM material_purchase_orders
         WHERE supplier_id = $sid AND hidden = 0 AND DATE(created_at) = CURDATE()"
    ) ?: [];
    foreach ($rows as $r) {
        $items2 = json_decode((string) $r['order_items'], true);
        if (!is_array($items2)) continue;
        $set2 = [];
        foreach ($items2 as $it) {
            $mid = (int) ($it['material_id'] ?? 0);
            if ($mid > 0) $set2[$mid] = true;
        }
        $keys2 = array_keys($set2);
        sort($keys2);
        if ($keys2 === $newKeys) return true;
    }
    return false;
}

function om_save_order($supplier_id, $supplier_name, $items, $note, $user_id = 0)
{
    om_ensure_tables();
    $clean = [];
    foreach ((array) $items as $it) {
        $mid = (int) ($it['material_id'] ?? 0);
        $qty = (float) ($it['qty'] ?? 0);
        if ($qty <= 0) continue;
        $clean[] = [
            'material_id' => $mid,
            'name'        => (string) ($it['name'] ?? ''),
            'unit'        => (string) ($it['unit'] ?? ''),
            'qty'         => $qty,
        ];
    }
    if (empty($clean)) return 0;

    $data = [
        'user_id'       => (int) $user_id > 0 ? (int) $user_id : null,
        'supplier_id'   => (int) $supplier_id > 0 ? (int) $supplier_id : null,
        'supplier_name' => (string) $supplier_name,
        'order_items'   => json_encode($clean, JSON_UNESCAPED_UNICODE),
        'note'          => (string) $note,
        'status'        => 'new',
    ];
    return (int) db_insert('material_purchase_orders', $data);
}

/** Sửa đơn đã lưu tại chỗ (UPDATE cùng id) — dùng cho nút "Sửa đơn" ở modal Đơn đã lưu,
 *  khác với "Đặt lại" (nạp vào phiếu rồi Lưu đơn tạo ĐƠN MỚI). */
function om_update_order($id, $supplier_id, $supplier_name, $items, $note)
{
    om_ensure_tables();
    $oid = (int) $id;
    if ($oid <= 0) return false;
    $clean = [];
    foreach ((array) $items as $it) {
        $mid = (int) ($it['material_id'] ?? 0);
        $qty = (float) ($it['qty'] ?? 0);
        if ($qty <= 0) continue;
        $clean[] = [
            'material_id' => $mid,
            'name'        => (string) ($it['name'] ?? ''),
            'unit'        => (string) ($it['unit'] ?? ''),
            'qty'         => $qty,
        ];
    }
    if (empty($clean)) return false;

    db_update('material_purchase_orders', [
        'supplier_id'   => (int) $supplier_id > 0 ? (int) $supplier_id : null,
        'supplier_name' => (string) $supplier_name,
        'order_items'   => json_encode($clean, JSON_UNESCAPED_UNICODE),
        'note'          => (string) $note,
    ], "id = $oid AND hidden = 0");
    return true;
}

function om_list_orders($limit = 50)
{
    om_ensure_tables();
    $lim = (int) $limit;
    if ($lim <= 0) $lim = 50;
    $rows = db_fetch_array(
        "SELECT id, supplier_id, supplier_name, order_items, note,
                status, received, received_at, created_at
         FROM material_purchase_orders
         WHERE hidden = 0
         ORDER BY created_at DESC, id DESC
         LIMIT $lim"
    ) ?: [];
    foreach ($rows as &$r) {
        $items = json_decode((string) $r['order_items'], true);
        $r['order_items'] = is_array($items) ? $items : [];
        $r['id']          = (int) $r['id'];
        $r['received']    = (int) $r['received'];
        $r['item_count']  = count($r['order_items']);
    }
    unset($r);

    // Giá trị đơn hàng dự kiến = SUM(qty * giá mua gồm chi phí mua hàng) từng mặt hàng.
    $allMids = [];
    foreach ($rows as $r) {
        foreach ($r['order_items'] as $it) $allMids[] = (int) ($it['material_id'] ?? 0);
    }
    $priceMap = om_material_price_map($allMids);
    foreach ($rows as &$r) {
        $total = 0.0;
        foreach ($r['order_items'] as $it) {
            $mid = (int) ($it['material_id'] ?? 0);
            $qty = (float) ($it['qty'] ?? 0);
            $total += $qty * ($priceMap[$mid] ?? 0.0);
        }
        $r['total_value'] = $total;
    }
    unset($r);

    return $rows;
}

/**
 * Giá mua gần nhất (đã gồm chi phí mua hàng) của từng NVL.
 * Ưu tiên dòng GẦN NHẤT có "giá mua gồm CPMH" (bất kể dòng đó có phải dòng mới nhất
 * tuyệt đối hay không — tránh trường hợp dòng mới nhất chỉ cập nhật giá mua thường mà
 * bỏ trống CPMH, khiến bị rơi xuống lấy nhầm giá mua thường dù NVL đã từng có giá CPMH).
 * CHỈ lấy giá mua thường (purchase_price) khi NVL đó CHƯA TỪNG có dòng nào ghi giá gồm
 * CPMH. Chưa có giá nào cả -> 0.
 */
function om_material_price_map(array $material_ids)
{
    $ids = array_unique(array_filter(array_map('intval', $material_ids)));
    $map = [];
    foreach ($ids as $mid) {
        $p = db_fetch_row(
            "SELECT COALESCE(purchase_price_includes_purchase_cost, purchase_price) AS price
             FROM material_purchase_prices
             WHERE material_id = $mid
             ORDER BY (purchase_price_includes_purchase_cost IS NOT NULL) DESC, last_updated_at DESC, id DESC
             LIMIT 1"
        );
        $map[$mid] = $p && $p['price'] !== null ? (float) $p['price'] : 0.0;
    }
    return $map;
}

function om_get_order($id)
{
    om_ensure_tables();
    $oid = (int) $id;
    if ($oid <= 0) return null;
    $row = db_fetch_row(
        "SELECT id, supplier_id, supplier_name, order_items, note,
                status, received, received_at, created_at
         FROM material_purchase_orders WHERE id = $oid AND hidden = 0 LIMIT 1"
    );
    if (!$row) return null;
    $items = json_decode((string) $row['order_items'], true);
    $row['order_items'] = is_array($items) ? $items : [];
    $row['id']          = (int) $row['id'];
    $row['received']    = (int) $row['received'];
    return $row;
}

/**
 * $expected_value/$actual_value: snapshot giá trị dự kiến (lúc đặt) và giá trị nhập kho thực
 * (lúc ghi phiếu ở inventory_receiving) — chỉ có khi tự động xác nhận qua khớp phiếu
 * (xem om_match_order_for_receipt()); bỏ trống (null) khi user tự tay bật/tắt "Đã nhận" ở
 * module order_material — khi đó không có gì để snapshot, và tắt "Đã nhận" luôn xóa snapshot cũ.
 */
function om_set_received($id, $received, $expected_value = null, $actual_value = null)
{
    om_ensure_tables();
    $oid = (int) $id;
    if ($oid <= 0) return false;
    $rc = $received ? 1 : 0;
    db_update('material_purchase_orders', [
        'received'                => $rc,
        'status'                  => $rc ? 'received' : 'new',
        'received_at'             => $rc ? date('Y-m-d H:i:s') : null,
        'expected_value_snapshot' => ($rc && $expected_value !== null) ? (float) $expected_value : null,
        'actual_value_snapshot'   => ($rc && $actual_value !== null) ? (float) $actual_value : null,
    ], "id = $oid");
    return true;
}

function om_delete_order($id)
{
    om_ensure_tables();
    $oid = (int) $id;
    if ($oid <= 0) return false;
    db_update('material_purchase_orders', ['hidden' => 1], "id = $oid");
    return true;
}

/* ============================================================
 *  Khớp đơn đã lưu <-> phiếu nhập kho thực tế (gọi từ module inventory_receiving)
 * ============================================================ */

/**
 * Tìm đơn đã lưu (chưa nhận, cùng NCC) có ĐÚNG BỘ NVL trùng với $material_ids
 * (số lượng không cần khớp). Nhiều đơn cùng khớp -> chọn đơn tạo sớm nhất (FIFO).
 */
function om_find_matching_order($supplier_id, array $material_ids)
{
    om_ensure_tables();
    $sid = (int) $supplier_id;
    if ($sid <= 0) return null;

    $set = array_values(array_unique(array_filter(array_map('intval', $material_ids))));
    if (empty($set)) return null;
    sort($set);

    $rows = db_fetch_array(
        "SELECT id, supplier_id, supplier_name, order_items, note,
                status, received, received_at, created_at
         FROM material_purchase_orders
         WHERE supplier_id = $sid AND hidden = 0 AND received = 0
         ORDER BY created_at ASC, id ASC"
    ) ?: [];

    foreach ($rows as $r) {
        $items = json_decode((string) $r['order_items'], true);
        if (!is_array($items) || empty($items)) continue;
        $oSet = [];
        foreach ($items as $it) {
            $mid = (int) ($it['material_id'] ?? 0);
            if ($mid > 0) $oSet[$mid] = true;
        }
        $oKeys = array_keys($oSet);
        sort($oKeys);
        if ($oKeys === $set) {
            $r['order_items'] = $items;
            $r['id']          = (int) $r['id'];
            return $r;
        }
    }
    return null;
}

/**
 * Gọi khi CHUẨN BỊ ghi 1 phiếu nhập NVL, TRƯỚC khi phiếu đó ghi đè
 * material_purchase_prices (để "giá trị dự kiến" còn lấy được giá cũ). Chỉ ĐỌC —
 * không ghi gì. Nếu đúng bộ NVL của phiếu khớp với 1 đơn đã lưu (cùng NCC, chưa
 * nhận) -> trả về so sánh giá trị dự kiến (giá trước phiếu này) với giá trị thực
 * nhập (phiếu vừa ghi). Việc đánh dấu "Đã nhận" chỉ nên thực hiện SAU KHI phiếu ghi
 * thành công — gọi om_set_received($result['order_id'], true) lúc đó.
 * $receipt_lines: [ ['material_id'=>, 'qty'=>, 'price_incl'=>], ... ] các dòng NVL của phiếu.
 * Trả về mảng kết quả hoặc null nếu không khớp đơn nào.
 */
function om_match_order_for_receipt($supplier_id, array $receipt_lines)
{
    $mids = [];
    foreach ($receipt_lines as $l) {
        $mid = (int) ($l['material_id'] ?? 0);
        if ($mid > 0) $mids[] = $mid;
    }
    $order = om_find_matching_order($supplier_id, $mids);
    if (!$order) return null;

    $orderMids = [];
    foreach ($order['order_items'] as $it) $orderMids[] = (int) ($it['material_id'] ?? 0);
    $priceMap = om_material_price_map($orderMids);
    $expected = 0.0;
    foreach ($order['order_items'] as $it) {
        $mid = (int) ($it['material_id'] ?? 0);
        $qty = (float) ($it['qty'] ?? 0);
        $expected += $qty * ($priceMap[$mid] ?? 0.0);
    }

    $orderSet = array_flip($orderMids);
    $actual = 0.0;
    foreach ($receipt_lines as $l) {
        $mid = (int) ($l['material_id'] ?? 0);
        if (!isset($orderSet[$mid])) continue;
        $actual += (float) ($l['qty'] ?? 0) * (float) ($l['price_incl'] ?? 0);
    }

    return [
        'order_id'       => $order['id'],
        'supplier_name'  => $order['supplier_name'],
        'created_at'     => $order['created_at'],
        'item_count'     => count($order['order_items']),
        'expected_value' => round($expected, 2),
        'actual_value'   => round($actual, 2),
        'diff'           => round($actual - $expected, 2),
        'diff_rate'      => $expected > 0 ? round((($actual - $expected) / $expected) * 100, 2) : 0.0,
    ];
}
