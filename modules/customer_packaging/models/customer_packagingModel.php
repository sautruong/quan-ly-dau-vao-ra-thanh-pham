<?php
/**
 * =====================================================================
 *  QL bao bì khách hàng (customer_packaging) — Model
 * =====================================================================
 *  Bối cảnh: một số khách hàng gửi bao bì riêng để nhà máy đóng gói hộ
 *  sản phẩm của họ (chiều ngược lại với module order_coffee: ở đó MÌNH
 *  gửi bao bì cho NCC gia công).
 *
 *  Bảng riêng: cp_packaging_types, cp_setup_products, cp_packaging_ledger
 *  (định nghĩa ở libraries/customer_packaging.php, hàm cp_*).
 *
 *  Prefix hàm module: cpm_* (customer packaging module) để không đụng
 *  hàm cp_* dùng chung trong thư viện.
 * =====================================================================
 */

require_once __DIR__ . '/../../../libraries/customer_packaging.php';

function cpm_ensure_tables()
{
    cp_ensure_tables();
}

/** Đăng ký view vào menu "SẢN XUẤT" (idempotent). */
function cpm_ensure_view_registered()
{
    if (db_num_rows("SHOW TABLES LIKE 'tbl_views'") <= 0) return;
    db_query("INSERT IGNORE INTO tbl_views (module, controller, action, label, group_label, sort)
              VALUES ('customer_packaging','customer_packaging','customer_packaging','QL bao bì khách hàng','SẢN XUẤT', 51)");
}

function cpm_current_user_id()
{
    if (!function_exists('permission_current_user')) return 0;
    $u = permission_current_user();
    return (int) ($u['id'] ?? 0);
}

/* ============================================================
 *  Tìm kiếm sản phẩm (cho picker "Dùng cho sản phẩm")
 * ============================================================ */
function cpm_search_products($keyword)
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

/* ============================================================
 *  Thiết lập: khách hàng + bao bì + sản phẩm
 * ============================================================ */
function cpm_customer_suggest($keyword)
{
    return cp_customer_names($keyword);
}

function cpm_packaging_suggest($customer_name, $keyword)
{
    return cp_packaging_names_for_customer($customer_name, $keyword);
}

function cpm_setup_list()
{
    return cp_setup_list();
}

function cpm_setup_add($customer_name, $packaging_name, $product_id, $product_name)
{
    $cn = trim((string) $customer_name);
    $pn = trim((string) $packaging_name);
    $pid = (int) $product_id;
    if ($cn === '' || $pn === '' || $pid <= 0) {
        return ['success' => false, 'message' => 'Thiếu tên khách hàng / tên bao bì / sản phẩm.'];
    }
    $id = cp_setup_add($cn, $pn, $pid, $product_name);
    if ($id <= 0) return ['success' => false, 'message' => 'Không thể lưu thiết lập.'];
    return ['success' => true, 'id' => $id];
}

function cpm_setup_delete($setup_id)
{
    return cp_setup_delete((int) $setup_id);
}

/* ============================================================
 *  Sổ bao bì theo khách hàng (khối .cp-card)
 * ============================================================ */
function cpm_customer_book($customer_name)
{
    return cp_customer_book($customer_name);
}

/**
 * Thêm nghiệp vụ bao bì thủ công.
 * $entry_type: 'opening' (tồn đầu ghi nhận) | 'receive' (khách chuyển bì qua)
 *            | 'usage' (xuất dùng cho SP) | 'loss' (hao hụt).
 */
function cpm_entry_add($customer_name, $packaging_name, $entry_type, $qty, $date, $product_id = 0, $product_name = '', $reason = '')
{
    $cn = trim((string) $customer_name);
    $pn = trim((string) $packaging_name);
    $q  = (float) $qty;
    if ($cn === '' || $pn === '' || $q <= 0) {
        return ['success' => false, 'message' => 'Thiếu tên khách hàng / tên bao bì / số lượng.'];
    }

    $map = [
        'opening' => ['Tồn đầu ghi nhận', 1],
        'receive' => ['Khách chuyển bì qua', 1],
        'usage'   => ['Xuất dùng', -1],
        'loss'    => ['Hao hụt', -1],
    ];
    if (!isset($map[$entry_type])) return ['success' => false, 'message' => 'Loại nghiệp vụ không hợp lệ.'];
    list($label, $sign) = $map[$entry_type];

    $pid = (int) $product_id;
    $pname = trim((string) $product_name);
    if ($entry_type === 'usage' && ($pid <= 0 || $pname === '')) {
        return ['success' => false, 'message' => 'Xuất dùng cần chọn sản phẩm.'];
    }

    $content = $label;
    if ($entry_type === 'usage') $content = 'Xuất dùng: ' . $pname;
    if ($entry_type === 'loss' && trim((string) $reason) !== '') $content = 'Hao hụt: ' . trim((string) $reason);

    $tid = cp_type_find_or_create($cn, $pn);
    if ($tid <= 0) return ['success' => false, 'message' => 'Không thể tạo loại bao bì.'];

    cp_add_entry(
        $tid,
        $entry_type,
        $content,
        $sign * $q,
        $date,
        $entry_type === 'usage' ? $pid : null,
        $entry_type === 'usage' ? $pname : null,
        cpm_current_user_id()
    );
    return ['success' => true];
}

/**
 * "Chốt tồn": ghi nhận số dư hiện tại khớp với tồn thực tế -> tạo 1 mốc 'confirm'
 * mới ở NGÀY HÔM NAY, làm điểm bắt đầu lại cho lần tính tiếp theo.
 */
function cpm_confirm($customer_name, $packaging_name)
{
    $cn = trim((string) $customer_name);
    $pn = trim((string) $packaging_name);
    if ($cn === '' || $pn === '') return ['success' => false, 'message' => 'Thiếu tên khách hàng / tên bao bì.'];

    $tid = cp_type_find_or_create($cn, $pn);
    if ($tid <= 0) return ['success' => false, 'message' => 'Không tìm thấy loại bao bì.'];

    $qty = (float) cp_balance($tid);
    cp_add_entry($tid, 'confirm', 'Xác nhận tồn', $qty, date('Y-m-d'), null, null, cpm_current_user_id());
    return ['success' => true, 'qty' => $qty];
}
