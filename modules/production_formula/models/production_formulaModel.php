<?php

/**
 * production_formula — Công thức sản xuất
 *
 * Nguồn dữ liệu:
 *   - product_materials        : công thức gốc (1 đơn vị). quantity_required,
 *                                sort_order (kéo-thả Trello).
 *   - material_information      : tên NVL (ưu tiên common_material_name), unit,
 *                                classification (Nguyên liệu / Bao bì trong /
 *                                Bao bì ngoài / Nhãn).
 *   - material_inventory        : tồn kho hiện tại (cảnh báo vượt tồn).
 *   - product_recipe_notes      : ghi chú theo sản phẩm (nhập 1 lần dùng nhiều lần).
 *   - product_batch_recipes(+_items) : "Công thức mẻ sản xuất" lưu snapshot khi
 *                                nhân hệ số x2..x200 (KHÔNG ghi đè product_materials).
 *
 * Prefix hàm: pf_*.
 */

/** Đăng ký view vào menu "SẢN XUẤT" (idempotent — chạy ở trang chính). */
function pf_ensure_view_registered()
{
    if (db_num_rows("SHOW TABLES LIKE 'tbl_views'") <= 0) return;
    db_query("INSERT IGNORE INTO tbl_views (module, controller, action, label, group_label, sort)
              VALUES ('production_formula','production_formula','production_formula','Công thức sản xuất mẻ','SẢN XUẤT', 42)");
}

/* ============================================================
 *  Helpers
 * ============================================================ */

/** Tên hiển thị NVL: ưu tiên common_material_name, fallback material_name. */
function pf_display_name($row)
{
    $c = trim((string) ($row['common_material_name'] ?? ''));
    return $c !== '' ? $c : (string) ($row['material_name'] ?? '');
}

/* ============================================================
 *  Search sản phẩm (dropdown keyword)
 * ============================================================ */

function pf_search_products($keyword)
{
    $kw = trim((string) $keyword);
    if ($kw === '') return [];
    $k = escape_string($kw);
    // Hiện cả sản phẩm CHƯA có công thức (has_recipe=0, xếp sau) để có thể
    // bắt đầu xây công thức mới ngay từ ô tìm kiếm này.
    $sql = "SELECT p.id, p.product_code, p.product_name, p.unit,
                   EXISTS (SELECT 1 FROM product_materials pm WHERE pm.product_id = p.id) AS has_recipe
            FROM products p
            WHERE (p.product_name LIKE '%$k%' OR p.product_code LIKE '%$k%')
            ORDER BY has_recipe DESC, p.product_name ASC
            LIMIT 15";
    $rows = db_fetch_array($sql) ?: [];
    foreach ($rows as &$r) { $r['has_recipe'] = (int) $r['has_recipe']; }
    unset($r);
    return $rows;
}

function pf_get_product($product_id)
{
    $pid = (int) $product_id;
    if ($pid <= 0) return null;
    return db_fetch_row(
        "SELECT id, product_code, product_name, unit FROM products WHERE id = $pid LIMIT 1"
    ) ?: null;
}

/* ============================================================
 *  Công thức 1 đơn vị (product_materials)
 * ============================================================ */

/**
 * Trả về toàn bộ thành phần của 1 sản phẩm kèm tồn kho.
 * Mỗi dòng: pm_id, material_id, name, unit, classification, quantity, stock, sort_order.
 */
function pf_get_recipe($product_id)
{
    $pid = (int) $product_id;
    if ($pid <= 0) return [];
    $sql = "SELECT pm.id            AS pm_id,
                   pm.material_id   AS material_id,
                   pm.quantity_required AS quantity,
                   pm.sort_order    AS sort_order,
                   mi.material_name AS material_name,
                   mi.common_material_name AS common_material_name,
                   mi.unit          AS unit,
                   mi.classification AS classification,
                   COALESCE(inv.quantity, 0) AS stock,
                   (SELECT COUNT(*) FROM material_images mim WHERE mim.material_id = pm.material_id) AS image_count
            FROM product_materials pm
            JOIN material_information mi ON mi.id = pm.material_id
            LEFT JOIN material_inventory inv ON inv.material_id = pm.material_id
            WHERE pm.product_id = $pid
            ORDER BY pm.sort_order ASC, pm.id ASC";
    $rows = db_fetch_array($sql) ?: [];
    foreach ($rows as &$r) {
        $r['pm_id']         = (int) $r['pm_id'];
        $r['material_id']   = (int) $r['material_id'];
        $r['quantity']      = (float) $r['quantity'];
        $r['stock']         = (float) $r['stock'];
        $r['sort_order']    = (int) $r['sort_order'];
        $r['image_count']   = (int) $r['image_count'];
        $r['display_name']  = pf_display_name($r);
    }
    unset($r);
    return $rows;
}

/** Cập nhật số lượng 1 thành phần (input số lượng sửa trực tiếp). */
function pf_update_quantity($pm_id, $quantity)
{
    $id = (int) $pm_id;
    if ($id <= 0) return false;
    $q = (float) $quantity;
    if ($q < 0) $q = 0;
    db_update('product_materials', ['quantity_required' => $q], "id = $id");
    return true;
}

/**
 * Thêm 1 thành phần mới vào công thức (chọn từ danh mục material_information).
 * Trả về dòng đầy đủ (cùng shape các dòng của pf_get_recipe) để client push
 * thẳng vào mảng recipe, hoặc null nếu thất bại.
 */
function pf_add_recipe_item($product_id, $material_id, $quantity = 0)
{
    $pid = (int) $product_id;
    $mid = (int) $material_id;
    if ($pid <= 0 || $mid <= 0) return null;
    $q = (float) $quantity;
    if ($q < 0) $q = 0;

    $maxRow = db_fetch_row("SELECT COALESCE(MAX(sort_order),0) AS m FROM product_materials WHERE product_id = $pid");
    $order  = ((int) ($maxRow['m'] ?? 0)) + 1;

    $pm_id = (int) db_insert('product_materials', [
        'product_id'        => $pid,
        'material_id'        => $mid,
        'quantity_required'  => $q,
        'sort_order'         => $order,
    ]);
    if ($pm_id <= 0) return null;

    $row = db_fetch_row(
        "SELECT pm.id            AS pm_id,
                pm.material_id   AS material_id,
                pm.quantity_required AS quantity,
                pm.sort_order    AS sort_order,
                mi.material_name AS material_name,
                mi.common_material_name AS common_material_name,
                mi.unit          AS unit,
                mi.classification AS classification,
                COALESCE(inv.quantity, 0) AS stock,
                (SELECT COUNT(*) FROM material_images mim WHERE mim.material_id = pm.material_id) AS image_count
         FROM product_materials pm
         JOIN material_information mi ON mi.id = pm.material_id
         LEFT JOIN material_inventory inv ON inv.material_id = pm.material_id
         WHERE pm.id = $pm_id LIMIT 1"
    );
    if (!$row) return null;
    $row['pm_id']        = (int) $row['pm_id'];
    $row['material_id']  = (int) $row['material_id'];
    $row['quantity']     = (float) $row['quantity'];
    $row['sort_order']   = (int) $row['sort_order'];
    $row['stock']        = (float) $row['stock'];
    $row['image_count']  = (int) $row['image_count'];
    $row['display_name'] = pf_display_name($row);
    return $row;
}

/** Xóa 1 thành phần khỏi công thức (product_materials). */
function pf_delete_recipe_item($pm_id)
{
    $id = (int) $pm_id;
    if ($id <= 0) return false;
    db_delete('product_materials', "id = $id");
    return true;
}

/**
 * Lưu thứ tự thành phần sau khi kéo-thả.
 * $ordered_pm_ids : mảng pm_id theo thứ tự mới (index 0 = trên cùng).
 */
function pf_reorder($product_id, $ordered_pm_ids)
{
    $pid = (int) $product_id;
    if ($pid <= 0 || !is_array($ordered_pm_ids)) return false;
    $order = 1;
    foreach ($ordered_pm_ids as $pm_id) {
        $id = (int) $pm_id;
        if ($id <= 0) continue;
        db_update('product_materials', ['sort_order' => $order], "id = $id AND product_id = $pid");
        $order++;
    }
    return true;
}

/* ============================================================
 *  Ghi chú công thức (nhập 1 lần, dùng nhiều lần)
 * ============================================================ */

function pf_get_recipe_note($product_id)
{
    $pid = (int) $product_id;
    if ($pid <= 0) return '';
    $row = db_fetch_row("SELECT note FROM product_recipe_notes WHERE product_id = $pid LIMIT 1");
    return $row ? (string) $row['note'] : '';
}

function pf_save_recipe_note($product_id, $note)
{
    $pid = (int) $product_id;
    if ($pid <= 0) return false;
    $n      = (string) $note;
    $exists = db_fetch_row("SELECT product_id FROM product_recipe_notes WHERE product_id = $pid LIMIT 1");
    if ($exists) {
        db_update('product_recipe_notes', ['note' => $n], "product_id = $pid");
    } else {
        db_insert('product_recipe_notes', ['product_id' => $pid, 'note' => $n]);
    }
    return true;
}

/** Sửa/xóa ghi chú của 1 công thức mẻ đã lưu (tab "Công thức mẻ sản xuất"). */
function pf_update_batch_note($batch_id, $note)
{
    $bid = (int) $batch_id;
    if ($bid <= 0) return false;
    db_update('product_batch_recipes', ['note' => (string) $note], "id = $bid");
    return true;
}

/** Ghi đè/xóa "Tổng sản phẩm" của 1 công thức mẻ. $qty rỗng/không hợp lệ -> NULL
 *  (quay lại dùng giá trị tự tính = multiplier, như trước giờ). */
function pf_update_batch_output_qty($batch_id, $qty)
{
    $bid = (int) $batch_id;
    if ($bid <= 0) return false;
    $q = trim((string) $qty);
    $value = ($q === '' || !is_numeric($q) || (float) $q <= 0) ? null : (float) $q;
    db_update('product_batch_recipes', ['output_qty' => $value], "id = $bid");
    return $value;
}

/* ============================================================
 *  Công thức mẻ sản xuất (snapshot)
 * ============================================================ */

/**
 * Lưu 1 công thức mẻ.
 * $items: [ {material_id, quantity, unit, sort_order}, ... ]
 * Trả batch_id mới.
 */
function pf_save_batch_recipe($product_id, $multiplier, $label, $note, $items)
{
    $pid = (int) $product_id;
    if ($pid <= 0 || !is_array($items) || empty($items)) return 0;
    $mul = (float) $multiplier;
    if ($mul <= 0) $mul = 1;

    $batch_id = (int) db_insert('product_batch_recipes', [
        'product_id' => $pid,
        'label'      => trim((string) $label),
        'multiplier' => $mul,
        'note'       => (string) $note,
    ]);
    if ($batch_id <= 0) return 0;

    $order = 1;
    foreach ($items as $it) {
        $mid    = (int) ($it['material_id'] ?? 0);
        $custom = trim((string) ($it['custom_name'] ?? ''));
        if ($mid <= 0 && $custom === '') continue; // cần material_id hợp lệ HOẶC tên tự do
        db_insert('product_batch_recipe_items', [
            'batch_id'    => $batch_id,
            'material_id' => $mid > 0 ? $mid : null,
            'custom_name' => $mid > 0 ? null : $custom,
            'quantity'    => (float) ($it['quantity'] ?? 0),
            'unit'        => trim((string) ($it['unit'] ?? '')),
            'sort_order'  => isset($it['sort_order']) ? (int) $it['sort_order'] : $order,
        ]);
        $order++;
    }
    return $batch_id;
}

/** Danh sách công thức mẻ đã lưu của 1 sản phẩm (mới nhất trước). */
function pf_list_batch_recipes($product_id)
{
    $pid = (int) $product_id;
    if ($pid <= 0) return [];
    $rows = db_fetch_array(
        "SELECT id, label, multiplier, note, created_at
         FROM product_batch_recipes
         WHERE product_id = $pid
         ORDER BY created_at DESC, id DESC"
    ) ?: [];
    foreach ($rows as &$r) {
        $r['id']         = (int) $r['id'];
        $r['multiplier'] = (float) $r['multiplier'];
    }
    unset($r);
    return $rows;
}

/** Chi tiết 1 công thức mẻ: header + items (kèm tên/tồn NVL). */
function pf_get_batch_recipe($batch_id)
{
    $bid = (int) $batch_id;
    if ($bid <= 0) return null;
    $header = db_fetch_row(
        "SELECT br.id, br.product_id, br.label, br.multiplier, br.output_qty, br.note, br.created_at,
                COALESCE(NULLIF(p.common_product_name, ''), p.product_name) AS product_name,
                p.unit AS product_unit
         FROM product_batch_recipes br
         JOIN products p ON p.id = br.product_id
         WHERE br.id = $bid LIMIT 1"
    );
    if (!$header) return null;
    $header['id']         = (int) $header['id'];
    $header['product_id'] = (int) $header['product_id'];
    $header['multiplier'] = (float) $header['multiplier'];
    $header['output_qty'] = $header['output_qty'] !== null ? (float) $header['output_qty'] : null;

    $items = db_fetch_array(
        "SELECT bri.id AS item_id, bri.material_id, bri.custom_name, bri.quantity, bri.unit,
                bri.conv_unit, bri.conv_ratio, bri.sort_order,
                mi.material_name, mi.common_material_name, mi.classification,
                COALESCE(inv.quantity, 0) AS stock,
                CASE WHEN bri.material_id IS NULL THEN 0
                     ELSE (SELECT COUNT(*) FROM material_images mim WHERE mim.material_id = bri.material_id) END AS image_count
         FROM product_batch_recipe_items bri
         LEFT JOIN material_information mi ON mi.id = bri.material_id
         LEFT JOIN material_inventory inv ON inv.material_id = bri.material_id
         WHERE bri.batch_id = $bid
         ORDER BY bri.sort_order ASC, bri.id ASC"
    ) ?: [];
    foreach ($items as &$r) {
        $r['item_id']      = (int) $r['item_id'];
        $r['material_id']  = $r['material_id'] !== null ? (int) $r['material_id'] : null;
        $r['quantity']     = (float) $r['quantity'];
        $r['stock']        = (float) $r['stock'];
        $r['sort_order']   = (int) $r['sort_order'];
        $r['image_count']  = (int) $r['image_count'];
        $r['conv_ratio']   = $r['conv_ratio'] !== null ? (float) $r['conv_ratio'] : null;
        $r['is_custom']    = $r['material_id'] === null;
        $r['display_name'] = $r['is_custom'] ? (string) ($r['custom_name'] ?? '') : pf_display_name($r);
    }
    unset($r);
    $header['items'] = $items;
    return $header;
}

/**
 * Nhân bản 1 công thức mẻ đã lưu thành 1 mẻ mới độc lập (header + toàn bộ
 * items), để sửa tiếp mà không ảnh hưởng bản gốc. Trả về id mẻ mới, hoặc 0.
 */
function pf_duplicate_batch_recipe($batch_id)
{
    $bid = (int) $batch_id;
    if ($bid <= 0) return 0;
    $src = pf_get_batch_recipe($bid);
    if (!$src) return 0;

    $label  = trim((string) $src['label']);
    $new_id = (int) db_insert('product_batch_recipes', [
        'product_id' => $src['product_id'],
        'label'      => $label !== '' ? $label . ' (bản sao)' : '',
        'multiplier' => $src['multiplier'],
        'output_qty' => $src['output_qty'],
        'note'       => (string) $src['note'],
    ]);
    if ($new_id <= 0) return 0;

    foreach ($src['items'] as $it) {
        $mid = isset($it['material_id']) ? (int) $it['material_id'] : 0;
        db_insert('product_batch_recipe_items', [
            'batch_id'    => $new_id,
            'material_id' => $mid > 0 ? $mid : null,
            'custom_name' => $mid > 0 ? null : ($it['custom_name'] ?? null),
            'quantity'    => $it['quantity'],
            'unit'        => $it['unit'],
            'conv_unit'   => $it['conv_unit'] ?? null,
            'conv_ratio'  => $it['conv_ratio'] ?? null,
            'sort_order'  => $it['sort_order'],
        ]);
    }
    return $new_id;
}

function pf_delete_batch_recipe($batch_id)
{
    $bid = (int) $batch_id;
    if ($bid <= 0) return false;
    db_delete('product_batch_recipes', "id = $bid"); // items cascade
    return true;
}

/* ---- Sửa trực tiếp từng dòng công thức mẻ (product_batch_recipe_items) ---- */

/** Cập nhật số lượng 1 dòng công thức mẻ. */
function pf_update_batch_item_quantity($item_id, $quantity)
{
    $id = (int) $item_id;
    if ($id <= 0) return false;
    $q = (float) $quantity;
    if ($q < 0) $q = 0;
    db_update('product_batch_recipe_items', ['quantity' => $q], "id = $id");
    return true;
}

/**
 * "Quy đổi đơn vị" cho 1 dòng công thức mẻ — chỉ đổi cách hiển thị/lưu số
 * lượng của DÒNG NÀY trong công thức mẻ (vd kg -> thùng, ratio 25 nghĩa là
 * 1 thùng = 25 kg), KHÔNG đụng material_information.unit. $conv_unit rỗng
 * = bỏ quy đổi, quay lại hiển thị theo unit gốc.
 */
function pf_update_batch_item_conversion($item_id, $conv_unit, $conv_ratio)
{
    $id = (int) $item_id;
    if ($id <= 0) return false;
    $cu = trim((string) $conv_unit);
    $ratio = (float) $conv_ratio;
    if ($cu === '' || $ratio <= 0) {
        db_update('product_batch_recipe_items', ['conv_unit' => null, 'conv_ratio' => null], "id = $id");
    } else {
        db_update('product_batch_recipe_items', ['conv_unit' => $cu, 'conv_ratio' => $ratio], "id = $id");
    }
    return true;
}

/** Lưu thứ tự các dòng công thức mẻ sau khi kéo-thả. */
function pf_reorder_batch_items($batch_id, $ordered_item_ids)
{
    $bid = (int) $batch_id;
    if ($bid <= 0 || !is_array($ordered_item_ids)) return false;
    $order = 1;
    foreach ($ordered_item_ids as $iid) {
        $id = (int) $iid;
        if ($id <= 0) continue;
        db_update('product_batch_recipe_items', ['sort_order' => $order], "id = $id AND batch_id = $bid");
        $order++;
    }
    return true;
}

/** Xóa 1 dòng công thức mẻ. */
function pf_delete_batch_item($item_id)
{
    $id = (int) $item_id;
    if ($id <= 0) return false;
    db_delete('product_batch_recipe_items', "id = $id");
    return true;
}

/**
 * Thêm 1 dòng vào 1 công thức mẻ ĐÃ LƯU sẵn — hoặc chọn NVL có trong danh
 * mục ($material_id > 0), hoặc gõ tên tự do ($custom_name, không cần có
 * trong material_information — vd "màu thực phẩm pha sẵn", "nước tinh khiết").
 * Trả về dòng đầy đủ (cùng shape items của pf_get_batch_recipe) hoặc null.
 */
function pf_add_batch_item($batch_id, $material_id, $custom_name, $quantity, $unit)
{
    $bid    = (int) $batch_id;
    if ($bid <= 0) return null;
    $mid    = (int) $material_id;
    $custom = trim((string) $custom_name);
    if ($mid <= 0 && $custom === '') return null;

    $maxRow = db_fetch_row("SELECT COALESCE(MAX(sort_order),0) AS m FROM product_batch_recipe_items WHERE batch_id = $bid");
    $order  = ((int) ($maxRow['m'] ?? 0)) + 1;

    $item_id = (int) db_insert('product_batch_recipe_items', [
        'batch_id'    => $bid,
        'material_id' => $mid > 0 ? $mid : null,
        'custom_name' => $mid > 0 ? null : $custom,
        'quantity'    => (float) $quantity,
        'unit'        => trim((string) $unit),
        'sort_order'  => $order,
    ]);
    if ($item_id <= 0) return null;

    $row = db_fetch_row(
        "SELECT bri.id AS item_id, bri.material_id, bri.custom_name, bri.quantity, bri.unit,
                bri.conv_unit, bri.conv_ratio, bri.sort_order,
                mi.material_name, mi.common_material_name, mi.classification,
                COALESCE(inv.quantity,0) AS stock,
                CASE WHEN bri.material_id IS NULL THEN 0
                     ELSE (SELECT COUNT(*) FROM material_images mim WHERE mim.material_id = bri.material_id) END AS image_count
         FROM product_batch_recipe_items bri
         LEFT JOIN material_information mi ON mi.id = bri.material_id
         LEFT JOIN material_inventory inv ON inv.material_id = bri.material_id
         WHERE bri.id = $item_id LIMIT 1"
    );
    if (!$row) return null;
    $row['item_id']     = (int) $row['item_id'];
    $row['material_id'] = $row['material_id'] !== null ? (int) $row['material_id'] : null;
    $row['quantity']    = (float) $row['quantity'];
    $row['stock']       = (float) $row['stock'];
    $row['sort_order']  = (int) $row['sort_order'];
    $row['image_count'] = (int) $row['image_count'];
    $row['conv_ratio']  = $row['conv_ratio'] !== null ? (float) $row['conv_ratio'] : null;
    $row['is_custom']   = $row['material_id'] === null;
    $row['display_name'] = $row['is_custom'] ? (string) ($row['custom_name'] ?? '') : pf_display_name($row);
    return $row;
}

/* ============================================================
 *  Tên thành phần — đổi NVL của 1 dòng công thức
 * ============================================================ */

/** Search NVL cho dropdown sửa tên thành phần. */
function pf_search_materials($keyword)
{
    $kw = trim((string) $keyword);
    if ($kw === '') return [];
    $k = escape_string($kw);
    $sql = "SELECT id, material_name, common_material_name, unit, classification
            FROM material_information
            WHERE material_name LIKE '%$k%' OR common_material_name LIKE '%$k%'
            ORDER BY material_name ASC
            LIMIT 15";
    $rows = db_fetch_array($sql) ?: [];
    foreach ($rows as &$r) {
        $r['id']           = (int) $r['id'];
        $r['display_name'] = pf_display_name($r);
    }
    unset($r);
    return $rows;
}

/**
 * Sửa "tên thường gọi" (common_material_name) của 1 NVL — ảnh hưởng toàn bộ
 * dòng công thức dùng NVL này. (material_name KHÔNG sửa qua đây.)
 */
function pf_rename_material_common($material_id, $name)
{
    $mid = (int) $material_id;
    if ($mid <= 0) return false;
    db_update('material_information', ['common_material_name' => trim((string) $name)], "id = $mid");
    return true;
}

/** Đổi NVL (material_id) của 1 dòng product_materials. */
function pf_update_recipe_material($pm_id, $material_id)
{
    $id  = (int) $pm_id;
    $mid = (int) $material_id;
    if ($id <= 0 || $mid <= 0) return null;
    db_update('product_materials', ['material_id' => $mid], "id = $id");
    // Trả lại thông tin NVL mới (kèm tồn + số ảnh) để client repaint dòng.
    $row = db_fetch_row(
        "SELECT mi.id AS material_id, mi.material_name, mi.common_material_name, mi.unit,
                mi.classification, COALESCE(inv.quantity,0) AS stock,
                (SELECT COUNT(*) FROM material_images mim WHERE mim.material_id = mi.id) AS image_count
         FROM material_information mi
         LEFT JOIN material_inventory inv ON inv.material_id = mi.id
         WHERE mi.id = $mid LIMIT 1"
    );
    if (!$row) return null;
    $row['material_id']  = (int) $row['material_id'];
    $row['stock']        = (float) $row['stock'];
    $row['image_count']  = (int) $row['image_count'];
    $row['display_name'] = pf_display_name($row);
    return $row;
}

/* ============================================================
 *  Xem nhanh tồn kho (NVL / Thành phẩm) — giống long_term_production_plan
 * ============================================================ */

function pf_material_stock_search($keyword)
{
    $kw = trim((string) $keyword);
    if ($kw === '') return [];
    $k = escape_string($kw);
    $sql = "SELECT mi.id, mi.material_name AS name,
                   COALESCE(NULLIF(mi.unit,''),'') AS unit,
                   COALESCE(inv.quantity,0) AS quantity
            FROM material_information mi
            LEFT JOIN material_inventory inv ON inv.material_id = mi.id
            WHERE mi.material_name LIKE '%$k%'
            ORDER BY mi.material_name ASC
            LIMIT 15";
    return db_fetch_array($sql) ?: [];
}

function pf_product_stock_search($keyword)
{
    $kw = trim((string) $keyword);
    if ($kw === '') return [];
    $k = escape_string($kw);
    $sql = "SELECT p.id, p.product_name AS name,
                   COALESCE(NULLIF(p.unit,''),'') AS unit,
                   COALESCE(fgi.quantity,0) AS quantity
            FROM products p
            LEFT JOIN finished_goods_inventory fgi ON fgi.product_id = p.id
            WHERE p.product_name LIKE '%$k%'
            ORDER BY p.product_name ASC
            LIMIT 15";
    return db_fetch_array($sql) ?: [];
}

/**
 * Thông tin nhanh 1 nguyên liệu — hiện khi click tên NVL ở tab "Công thức mẻ sản xuất":
 * tên hệ thống, đơn vị, tồn kho hiện tại, các sản phẩm đang dùng NVL này (theo BOM
 * product_materials), định mức dùng 1/3/6 tháng gần nhất (tổng số lượng đã xuất cho
 * sản xuất trong N ngày, cùng nguồn raw_material_production_issue_data với order_material).
 */
function pf_get_material_info($material_id)
{
    $mid = (int) $material_id;
    if ($mid <= 0) return null;

    $mat = db_fetch_row(
        "SELECT mi.material_name, mi.common_material_name,
                COALESCE(NULLIF(mi.unit,''),'') AS unit,
                COALESCE(inv.quantity,0) AS stock
         FROM material_information mi
         LEFT JOIN material_inventory inv ON inv.material_id = mi.id
         WHERE mi.id = $mid LIMIT 1"
    );
    if (!$mat) return null;

    $sys_name = trim((string) $mat['material_name']);
    if ($sys_name === '') $sys_name = trim((string) $mat['common_material_name']);

    $prodRows = db_fetch_array(
        "SELECT DISTINCT p.id, p.product_name
         FROM product_materials pm
         JOIN products p ON p.id = pm.product_id
         WHERE pm.material_id = $mid
         ORDER BY p.product_name ASC"
    ) ?: [];
    $products = array_map(function ($p) {
        return ['id' => (int) $p['id'], 'name' => (string) $p['product_name']];
    }, $prodRows);

    $usage_sum = function ($days) use ($mid) {
        $d = (int) $days;
        $row = db_fetch_row(
            "SELECT COALESCE(SUM(quantity),0) AS q
             FROM raw_material_production_issue_data
             WHERE material_id = $mid AND created_at >= (NOW() - INTERVAL $d DAY)"
        );
        return $row ? (float) $row['q'] : 0.0;
    };

    return [
        'material_id' => $mid,
        'system_name' => $sys_name,
        'unit'        => (string) $mat['unit'],
        'stock'       => (float) $mat['stock'],
        'products'    => $products,
        'use_1m'      => $usage_sum(30),
        'use_3m'      => $usage_sum(90),
        'use_6m'      => $usage_sum(180),
    ];
}

/* ============================================================
 *  Hình ảnh nguyên liệu (cột "Thao tác")
 * ============================================================ */

function pf_material_images_dir()
{
    return APPPATH . DIRECTORY_SEPARATOR . 'public' . DIRECTORY_SEPARATOR
        . 'uploads' . DIRECTORY_SEPARATOR . 'material_images';
}

function pf_list_material_images($material_id)
{
    $mid = (int) $material_id;
    if ($mid <= 0) return [];
    $rows = db_fetch_array(
        "SELECT id, file_path, created_at FROM material_images
         WHERE material_id = $mid ORDER BY id ASC"
    ) ?: [];
    foreach ($rows as &$r) { $r['id'] = (int) $r['id']; }
    unset($r);
    return $rows;
}

/** Lưu các file ảnh upload cho 1 NVL. $files = $_FILES['files']. */
function pf_save_material_images($material_id, $files)
{
    $mid = (int) $material_id;
    if ($mid <= 0 || empty($files) || !isset($files['name'])) {
        return ['ok' => false, 'saved' => [], 'errors' => ['Tham số không hợp lệ.']];
    }
    $allowed = ['jpg', 'jpeg', 'png', 'gif', 'webp'];
    $abs_dir = pf_material_images_dir();
    if (!is_dir($abs_dir)) @mkdir($abs_dir, 0777, true);
    $rel_web = 'public/uploads/material_images';

    // Chuẩn hoá mảng files (hỗ trợ nhiều file).
    $names = (array) $files['name'];
    $tmps  = (array) $files['tmp_name'];
    $errs  = (array) $files['error'];

    $saved = [];
    $errors = [];
    foreach ($names as $i => $name) {
        if (($errs[$i] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) continue;
        if (($errs[$i] ?? 1) !== UPLOAD_ERR_OK) { $errors[] = 'Tải thất bại: ' . $name; continue; }
        $ext = strtolower(pathinfo($name, PATHINFO_EXTENSION));
        if (!in_array($ext, $allowed, true)) { $errors[] = 'Định dạng không hợp lệ: ' . $name; continue; }

        $base     = preg_replace('/[^A-Za-z0-9_\-]/', '_', pathinfo($name, PATHINFO_FILENAME));
        $base     = $base !== '' ? $base : 'img';
        $unique   = $mid . '_' . time() . '_' . substr(md5($name . mt_rand()), 0, 6);
        $filename = $unique . '_' . $base . '.' . $ext;
        $abs_path = $abs_dir . DIRECTORY_SEPARATOR . $filename;

        $moved = is_uploaded_file($tmps[$i])
            ? move_uploaded_file($tmps[$i], $abs_path)
            : @rename($tmps[$i], $abs_path);
        if (!$moved) { $errors[] = 'Không thể lưu: ' . $name; continue; }

        $file_path = $rel_web . '/' . $filename;
        $id = (int) db_insert('material_images', ['material_id' => $mid, 'file_path' => $file_path]);
        $saved[] = ['id' => $id, 'file_path' => $file_path];
    }
    return ['ok' => empty($errors) || !empty($saved), 'saved' => $saved, 'errors' => $errors];
}

function pf_delete_material_image($image_id)
{
    $id = (int) $image_id;
    if ($id <= 0) return false;
    $row = db_fetch_row("SELECT file_path FROM material_images WHERE id = $id LIMIT 1");
    if ($row && !empty($row['file_path'])) {
        $abs = APPPATH . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $row['file_path']);
        if (is_file($abs)) @unlink($abs);
    }
    db_delete('material_images', "id = $id");
    return true;
}

/**
 * Danh sách ảnh của nhiều NVL cùng lúc (tránh N+1 query khi xem gallery
 * cả công thức). $material_ids: mảng id (int).
 */
function pf_list_images_for_materials($material_ids)
{
    $ids = array_values(array_unique(array_filter(array_map('intval', (array) $material_ids), function ($v) {
        return $v > 0;
    })));
    if (empty($ids)) return [];
    $in = implode(',', $ids); // toàn số nguyên đã ép kiểu -> an toàn nối chuỗi
    $rows = db_fetch_array(
        "SELECT mim.id, mim.material_id, mim.file_path, mim.created_at,
                mi.material_name, mi.common_material_name
         FROM material_images mim
         JOIN material_information mi ON mi.id = mim.material_id
         WHERE mim.material_id IN ($in)
         ORDER BY mim.material_id ASC, mim.id ASC"
    ) ?: [];
    foreach ($rows as &$r) {
        $r['id']           = (int) $r['id'];
        $r['material_id']  = (int) $r['material_id'];
        $r['display_name'] = pf_display_name($r);
    }
    unset($r);
    return $rows;
}

/** Gallery ảnh của toàn bộ NVL đang có trong công thức 1 đơn vị của 1 sản phẩm. */
function pf_list_recipe_images_gallery($product_id)
{
    $pid = (int) $product_id;
    if ($pid <= 0) return [];
    $rows = db_fetch_array("SELECT DISTINCT material_id FROM product_materials WHERE product_id = $pid") ?: [];
    $mids = array_map(function ($r) { return (int) $r['material_id']; }, $rows);
    return pf_list_images_for_materials($mids);
}

/* ============================================================
 *  Sửa tên (label) công thức mẻ — sửa 1 lần dùng nhiều lần
 * ============================================================ */

/** Đổi tên hiển thị của 1 công thức mẻ (product_batch_recipes.label). */
function pf_update_batch_label($batch_id, $label)
{
    $bid = (int) $batch_id;
    if ($bid <= 0) return false;
    db_update('product_batch_recipes', ['label' => trim((string) $label)], "id = $bid");
    return true;
}

/* ============================================================
 *  Thông tin tiêu đề phiếu Share (app_settings, prefix pf_share.*)
 *  "Sửa một lần dùng nhiều lần" — tên công ty + địa chỉ in trên phiếu share.
 * ============================================================ */

/** Tạo bảng app_settings nếu chưa có (1 lần / request). */
function pf_app_settings_ensure()
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

/** Giá trị mặc định + whitelist key cho phép sửa của phiếu share. */
function pf_share_settings_defaults()
{
    return [
        'company_name'    => 'VUA AN TOÀN',
        'company_address' => '1/13Z Ấp Tiền Lân, Xã Bà Điểm, TP Hồ Chí Minh, Việt Nam',
    ];
}

/** Lấy toàn bộ thông tin tiêu đề phiếu share (default + override đã lưu). */
function pf_get_share_settings()
{
    pf_app_settings_ensure();
    $out  = pf_share_settings_defaults();
    $rows = db_fetch_array("SELECT setting_key, setting_value FROM app_settings
                            WHERE setting_key LIKE 'pf_share.%'") ?: [];
    foreach ($rows as $r) {
        $k = substr((string) $r['setting_key'], strlen('pf_share.'));
        if (array_key_exists($k, $out) && $r['setting_value'] !== null) {
            $out[$k] = (string) $r['setting_value'];
        }
    }
    return $out;
}

/** Lưu 1 thông tin tiêu đề phiếu share (chỉ chấp nhận key trong whitelist). */
function pf_save_share_setting($key, $value)
{
    $key = (string) $key;
    if (!array_key_exists($key, pf_share_settings_defaults())) return false;
    pf_app_settings_ensure();
    $full = 'pf_share.' . $key;
    $fk   = escape_string($full);
    $exists = db_num_rows("SELECT 1 FROM app_settings WHERE setting_key = '$fk'") > 0;
    if ($exists) {
        db_update('app_settings', ['setting_value' => (string) $value], "setting_key = '$fk'");
    } else {
        db_insert('app_settings', ['setting_key' => $full, 'setting_value' => (string) $value]);
    }
    return true;
}
