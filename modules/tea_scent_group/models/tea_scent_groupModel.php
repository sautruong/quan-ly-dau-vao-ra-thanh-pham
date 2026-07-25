<?php
/**
 * =====================================================================
 *  QL nhóm trà ủ hương (tea_scent_group) — Model
 * =====================================================================
 *  Bối cảnh: 1 số NVL "kiểm soát" (hương liệu ủ trà) cần theo dõi riêng:
 *  SP nào dùng theo tỉ lệ bao nhiêu, tồn đầu + lịch sử nhập, và tự động
 *  trừ khi NVL được dùng để sản xuất (hook ở investment_products).
 *
 *  Bảng riêng: tsg_groups, tsg_setup_products, tsg_ledger
 *  (định nghĩa ở libraries/tea_scent_group.php, hàm tsg_*).
 *
 *  Prefix hàm module: tsgm_* (tea scent group module) để không đụng
 *  hàm tsg_* dùng chung trong thư viện.
 * =====================================================================
 */

require_once __DIR__ . '/../../../libraries/tea_scent_group.php';

/** Đăng ký view vào menu "SẢN XUẤT" (idempotent). */
function tsgm_ensure_view_registered()
{
    if (db_num_rows("SHOW TABLES LIKE 'tbl_views'") <= 0) return;
    db_query("INSERT IGNORE INTO tbl_views (module, controller, action, label, group_label, sort)
              VALUES ('tea_scent_group','tea_scent_group','tea_scent_group','QL nhóm trà ủ hương','SẢN XUẤT', 52)");
}

function tsgm_current_user_id()
{
    if (!function_exists('permission_current_user')) return 0;
    $u = permission_current_user();
    return (int) ($u['id'] ?? 0);
}

/* ============================================================
 *  Tìm kiếm NVL kiểm soát + sản phẩm (cho picker)
 * ============================================================ */
function tsgm_search_materials($keyword)
{
    $kw = trim((string) $keyword);
    if ($kw === '') return [];
    $k = escape_string($kw);
    $rows = db_fetch_array(
        "SELECT id, material_name, COALESCE(NULLIF(unit,''),'') AS unit
         FROM material_information
         WHERE material_name LIKE '%$k%'
         ORDER BY material_name ASC LIMIT 15"
    ) ?: [];
    $out = [];
    foreach ($rows as $r) {
        $out[] = ['id' => (int) $r['id'], 'name' => (string) $r['material_name'], 'unit' => (string) $r['unit']];
    }
    return $out;
}

function tsgm_search_products($keyword)
{
    $kw = trim((string) $keyword);
    if ($kw === '') return [];
    $k = escape_string($kw);
    $rows = db_fetch_array(
        "SELECT id, product_name, COALESCE(NULLIF(unit,''),'') AS unit
         FROM products
         WHERE product_name LIKE '%$k%'
         ORDER BY product_name ASC LIMIT 15"
    ) ?: [];
    $out = [];
    foreach ($rows as $r) {
        $out[] = ['id' => (int) $r['id'], 'name' => (string) $r['product_name'], 'unit' => (string) $r['unit']];
    }
    return $out;
}

/**
 * Sản phẩm đã có trong công thức (product_materials) của 1 NVL — dùng để
 * gợi ý sẵn ở bước tạo nhóm. quantity_required là định mức/1 đơn vị thành
 * phẩm nên cùng đơn vị với usage_ratio của tsg (0.5 = 50%).
 */
function tsgm_products_for_material($material_id)
{
    $mid = (int) $material_id;
    if ($mid <= 0) return [];
    $rows = db_fetch_array(
        "SELECT pm.product_id, p.product_name, pm.quantity_required
         FROM product_materials pm
         JOIN products p ON p.id = pm.product_id
         WHERE pm.material_id = $mid
         ORDER BY p.product_name ASC"
    ) ?: [];
    $out = [];
    foreach ($rows as $r) {
        $out[] = [
            'product_id'          => (int) $r['product_id'],
            'product_name'        => (string) $r['product_name'],
            'usage_ratio_percent' => round((float) $r['quantity_required'] * 100, 4),
        ];
    }
    return $out;
}
