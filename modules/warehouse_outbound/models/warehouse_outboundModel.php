<?php

/**
 * Lấy purchase_price mới nhất của 1 nguyên vật liệu (material_purchase_prices).
 * 0 nếu chưa có row nào.
 */
function wo_get_material_purchase_price($material_id)
{
    $mid = (int) $material_id;
    if ($mid <= 0) return 0.0;
    $row = db_fetch_row("SELECT purchase_price FROM material_purchase_prices
                         WHERE material_id = $mid
                         ORDER BY last_updated_at DESC, id DESC
                         LIMIT 1");
    return $row ? (float) $row['purchase_price'] : 0.0;
}

/**
 * Tính giá vốn 1 đơn vị của 1 hàng hóa.
 * - product (TP): SUM(quantity_required * purchase_price) qua product_materials.
 * - material (NVL): purchase_price mới nhất.
 */
function wo_compute_item_unit_cost($id, $type)
{
    $i = (int) $id;
    if ($i <= 0) return 0.0;
    if ($type === 'material') {
        return wo_get_material_purchase_price($i);
    }
    return (float) im_compute_product_cost_per_unit($i);
}

/**
 * Lấy chi tiết defaults để render 1 product-item khi user pick từ dropdown.
 * Trả: {id, type, name, warehouse, unit_cost}. unit_cost = giá vốn 1 đơn vị.
 */
function wo_get_item_defaults($id, $type)
{
    $i = (int) $id;
    if ($i <= 0) return null;
    if ($type === 'material') {
        $m = im_get_material($i);
        if (!$m) return null;
        return [
            'id'        => (int) $m['id'],
            'type'      => 'material',
            'name'      => $m['material_name'],
            'warehouse' => 'Kho NVL',
            'unit_cost' => wo_get_material_purchase_price($i),
        ];
    }
    $p = im_get_product($i);
    if (!$p) return null;
    return [
        'id'        => (int) $p['id'],
        'type'      => 'product',
        'name'      => $p['product_name'],
        'warehouse' => 'Kho TP',
        'unit_cost' => (float) im_compute_product_cost_per_unit($i),
    ];
}

/**
 * Ghi 1 dòng stock_exports cho 1 hàng hóa (TP hoặc NVL) + trừ tồn tương ứng.
 *   - product (TP): trừ finished_goods_inventory.product_id
 *   - material (NVL): trừ material_inventory.material_id
 * Trả export_id (>0), hoặc 0 nếu lỗi/thiếu tồn.
 */
function wo_record_other_outbound_item($id, $type, $qty, $unit_price, $total_amount, $interpretation, $created_at = null)
{
    $i = (int) $id;
    $q = (float) $qty;
    if ($i <= 0 || $q <= 0) return 0;

    $is_material = ($type === 'material');
    if ($is_material) {
        im_adjust_material_inventory($i, -$q);
    } else {
        $row = db_fetch_row("SELECT id, quantity FROM finished_goods_inventory WHERE product_id = $i LIMIT 1");
        if ($row) {
            $new_stock = (float) $row['quantity'] - $q;
            db_update('finished_goods_inventory', ['quantity' => $new_stock], 'id = ' . (int) $row['id']);
        } else {
            db_insert('finished_goods_inventory', ['product_id' => $i, 'quantity' => -$q]);
        }
    }

    $data = [
        'product_id'     => $is_material ? null : $i,
        'material_id'    => $is_material ? $i : null,
        'customer_id'    => null,
        'quantity'       => $q,
        'unit_price'     => (float) $unit_price,
        'total_amount'   => (float) $total_amount,
        'interpretation' => (string) $interpretation,
        'type_export'    => 'other_export_outbound',
    ];
    $ca = im_sanitize_datetime($created_at);
    if ($ca !== null) $data['created_at'] = $ca;

    return (int) db_insert('stock_exports', $data);
}

/* ============================================================
 *   LƯU NGHIỆP VỤ ĐÃ NHẬP THEO TỪNG PHIẾU (outbound_journal_entries)
 *   Để khi "Sửa" ở lịch sử chỉ đổ lại đúng nghiệp vụ của phiếu đó.
 * ============================================================ */

/** Tạo bảng nếu chưa có (idempotent) — tránh phụ thuộc chạy migration tay. */
function wo_ensure_outbound_je_table()
{
    static $done = false;
    if ($done) return;
    db_query("CREATE TABLE IF NOT EXISTS `outbound_journal_entries` (
        `id`             INT AUTO_INCREMENT PRIMARY KEY,
        `reference_type` VARCHAR(64) NOT NULL,
        `reference_id`   INT         NOT NULL,
        `entries_json`   MEDIUMTEXT  NULL,
        `created_at`     DATETIME DEFAULT CURRENT_TIMESTAMP,
        `updated_at`     DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        UNIQUE KEY `uniq_ref` (`reference_type`, `reference_id`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    $done = true;
}

/**
 * Đọc các bút toán user nhập từ POST je_entries (GIỮ NGUYÊN đủ field để dựng lại
 * cụm khi "Sửa"): transaction_name, debit, credit, debit_name, credit_name, amount,
 * description. Bỏ cụm trống hoàn toàn.
 */
function wo_read_posted_je_entries()
{
    if (!isset($_POST['je_entries'])) return [];
    $arr = json_decode((string) $_POST['je_entries'], true);
    if (!is_array($arr)) return [];
    $out = [];
    foreach ($arr as $e) {
        if (!is_array($e)) continue;
        $name   = trim((string) ($e['transaction_name'] ?? ''));
        $debit  = trim((string) ($e['debit']  ?? ''));
        $credit = trim((string) ($e['credit'] ?? ''));
        $amount = (float) ($e['amount'] ?? 0);
        if ($name === '' && $debit === '' && $credit === '' && $amount <= 0) continue;
        $out[] = [
            'transaction_name' => $name,
            'debit'            => $debit,
            'debit_name'       => trim((string) ($e['debit_name']  ?? '')),
            'credit'           => $credit,
            'credit_name'      => trim((string) ($e['credit_name'] ?? '')),
            'amount'           => $amount,
            'description'      => trim((string) ($e['description'] ?? '')),
        ];
    }
    return $out;
}

/** Upsert JSON nghiệp vụ đã nhập cho 1 phiếu (reference_type + reference_id). */
function wo_save_outbound_journal_entries($reference_type, $reference_id, array $entries)
{
    $rid = (int) $reference_id;
    if ($rid <= 0) return;
    wo_ensure_outbound_je_table();
    wo_delete_outbound_journal_entries($reference_type, $rid);
    if (empty($entries)) return; // không có nghiệp vụ → khỏi lưu dòng rỗng
    db_insert('outbound_journal_entries', [
        'reference_type' => (string) $reference_type,
        'reference_id'   => $rid,
        'entries_json'   => json_encode(array_values($entries), JSON_UNESCAPED_UNICODE),
    ]);
}

/** Lấy lại mảng nghiệp vụ đã nhập của 1 phiếu (rỗng nếu chưa có). */
function wo_get_outbound_journal_entries($reference_type, $reference_id)
{
    $rid = (int) $reference_id;
    if ($rid <= 0) return [];
    wo_ensure_outbound_je_table();
    $rt = escape_string((string) $reference_type);
    $row = db_fetch_row("SELECT entries_json FROM outbound_journal_entries
                         WHERE reference_type = '$rt' AND reference_id = $rid
                         LIMIT 1");
    if (empty($row['entries_json'])) return [];
    $arr = json_decode($row['entries_json'], true);
    return is_array($arr) ? $arr : [];
}

/** Xóa nghiệp vụ đã nhập của 1 phiếu. */
function wo_delete_outbound_journal_entries($reference_type, $reference_id)
{
    $rid = (int) $reference_id;
    if ($rid <= 0) return;
    wo_ensure_outbound_je_table();
    $rt = escape_string((string) $reference_type);
    db_query("DELETE FROM outbound_journal_entries
              WHERE reference_type = '$rt' AND reference_id = $rid");
}

/** Gom các nghiệp vụ có tên vào thư viện template (insert-if-new, KHÔNG ghi đè). */
function wo_save_je_templates_if_new(array $entries)
{
    foreach ($entries as $e) {
        $name = trim((string) ($e['transaction_name'] ?? ''));
        if ($name === '') continue;
        $amt = (string) ($e['amount'] ?? '');
        je_save_template_if_new('other_warehouse_outbound', $name, $e['description'] ?? '', [
            'debit'  => ['account_code' => $e['debit']  ?? '', 'account_name' => $e['debit_name']  ?? '', 'amount_formula' => $amt],
            'credit' => ['account_code' => $e['credit'] ?? '', 'account_name' => $e['credit_name'] ?? '', 'amount_formula' => $amt],
        ]);
    }
}

/**
 * Ghi 2 hoặc 4 dòng transactions cho 1 batch other_export_outbound.
 *   - $tp_total > 0: cặp Dr 632 / Cr 155
 *   - $nvl_total > 0: cặp Dr 621 / Cr 152
 * reference_type = 'other_export_outbound', reference_id = $reference_id.
 */
function wo_record_other_outbound_transactions($reference_id, $tp_total, $nvl_total, $created_at = null)
{
    $rid = (int) $reference_id;
    if ($rid <= 0) return false;

    $ca = im_sanitize_datetime($created_at);
    $base = [
        'reference_type' => 'other_export_outbound',
        'reference_id'   => $rid,
    ];
    if ($ca !== null) $base['created_at'] = $ca;

    $tp  = (float) $tp_total;
    $nvl = (float) $nvl_total;

    if ($tp > 0) {
        $r = array_merge($base, ['amount' => $tp]);
        db_insert('transactions', array_merge($r, ['account_code' => '632', 'type' => 'debit']));
        db_insert('transactions', array_merge($r, ['account_code' => '155', 'type' => 'credit']));
    }
    if ($nvl > 0) {
        $r = array_merge($base, ['amount' => $nvl]);
        db_insert('transactions', array_merge($r, ['account_code' => '621', 'type' => 'debit']));
        db_insert('transactions', array_merge($r, ['account_code' => '152', 'type' => 'credit']));
    }
    return true;
}

/**
 * Lấy danh sách stock_exports.id của 1 batch other_export_outbound theo group_key.
 * group_key = DATE_FORMAT(created_at, '%Y-%m-%d %H:%i:%s').
 */
function wo_get_other_outbound_export_ids($group_key)
{
    $key = escape_string((string) $group_key);
    if ($key === '') return [];
    $rows = db_fetch_array("SELECT id FROM stock_exports
                            WHERE type_export = 'other_export_outbound'
                              AND DATE_FORMAT(created_at, '%Y-%m-%d %H:%i:%s') = '$key'") ?: [];
    return array_map(function ($r) { return (int) $r['id']; }, $rows);
}

/**
 * Lấy id nhỏ nhất của stock_exports trong 1 batch — dùng làm reference_id cho transactions.
 */
function wo_get_other_outbound_first_export_id($group_key)
{
    $key = escape_string((string) $group_key);
    if ($key === '') return 0;
    $row = db_fetch_row("SELECT MIN(id) AS first_id FROM stock_exports
                         WHERE type_export = 'other_export_outbound'
                           AND DATE_FORMAT(created_at, '%Y-%m-%d %H:%i:%s') = '$key'");
    return $row ? (int) $row['first_id'] : 0;
}

/**
 * Xóa transactions của 1 batch other_export_outbound theo danh sách export_ids
 * (reference_id của tx luôn là export_id nhỏ nhất nên chỉ cần xoá theo nhóm id).
 */
function wo_delete_other_outbound_transactions(array $export_ids)
{
    $ids = array_values(array_filter(array_map('intval', $export_ids), function ($v) { return $v > 0; }));
    if (empty($ids)) return;
    $ids_sql = implode(',', $ids);
    db_query("DELETE FROM transactions
              WHERE reference_type = 'other_export_outbound'
                AND reference_id IN ($ids_sql)");
}

/**
 * Lấy chi tiết 1 batch theo group_key — gồm items + thông tin tổng hợp để render Sửa.
 * Trả null nếu không có dòng nào.
 */
function wo_get_other_outbound_batch($group_key)
{
    $gk  = trim((string) $group_key);
    if ($gk === '') return null;
    $gk_safe = escape_string($gk);

    $sql = "SELECT se.id AS export_id,
                   se.product_id,
                   se.material_id,
                   se.quantity,
                   se.unit_price,
                   se.total_amount,
                   se.interpretation,
                   se.created_at,
                   p.product_name,
                   m.material_name
            FROM stock_exports se
            LEFT JOIN products p             ON p.id = se.product_id
            LEFT JOIN material_information m ON m.id = se.material_id
            WHERE DATE_FORMAT(se.created_at, '%Y-%m-%d %H:%i:%s') = '$gk_safe'
              AND se.type_export = 'other_export_outbound'
            ORDER BY se.id ASC";
    $rows = db_fetch_array($sql) ?: [];
    if (empty($rows)) return null;

    $items = [];
    foreach ($rows as $r) {
        $mid = (int) ($r['material_id'] ?? 0);
        $pid = (int) ($r['product_id'] ?? 0);
        $is_material = $mid > 0;
        $id   = $is_material ? $mid : $pid;
        $type = $is_material ? 'material' : 'product';
        $items[] = [
            'export_id'      => (int) $r['export_id'],
            'id'             => $id,
            'type'           => $type,
            'name'           => $is_material
                ? ($r['material_name'] ?: ('#' . $mid))
                : ($r['product_name']  ?: ('#' . $pid)),
            'warehouse'      => $is_material ? 'Kho NVL' : 'Kho TP',
            'quantity'       => (float) $r['quantity'],
            'unit_price'     => (float) $r['unit_price'],
            'total_amount'   => (float) $r['total_amount'],
            'interpretation' => $r['interpretation'] ?: '',
        ];
    }

    return [
        'group_key'  => $gk,
        'created_at' => $rows[0]['created_at'],
        'items'      => $items,
    ];
}

/**
 * Tính tổng TP / NVL của 1 mảng items đã chuẩn hoá để dựng amount cho transactions.
 * Mỗi item: {type, total_amount}.
 */
function wo_split_totals_by_type(array $items)
{
    $tp = 0.0; $nvl = 0.0;
    foreach ($items as $it) {
        $t = trim((string) ($it['type'] ?? 'product'));
        $amt = (float) ($it['total_amount'] ?? 0);
        if ($t === 'material') $nvl += $amt;
        else                    $tp  += $amt;
    }
    return ['tp' => $tp, 'nvl' => $nvl];
}

/**
 * Lấy các batch other_export_outbound gần nhất từ stock_exports, group theo
 * created_at (giây). Mỗi batch có: {group_key, created_at, date_display, summary, items[]}.
 */
function wo_get_recent_other_outbound_batches($limit = 100)
{
    $limit = (int) $limit;
    if ($limit <= 0) $limit = 100;

    $sql_groups = "SELECT DATE_FORMAT(created_at, '%Y-%m-%d %H:%i:%s') AS group_key,
                          MIN(created_at) AS created_at,
                          SUM(total_amount) AS total
                   FROM stock_exports
                   WHERE type_export = 'other_export_outbound'
                   GROUP BY group_key
                   ORDER BY created_at DESC
                   LIMIT $limit";
    $groups = db_fetch_array($sql_groups) ?: [];

    $batches = [];
    foreach ($groups as $g) {
        $key_safe = escape_string($g['group_key']);
        $sql_items = "SELECT se.id AS export_id,
                             se.product_id,
                             se.material_id,
                             se.quantity,
                             se.unit_price,
                             se.total_amount,
                             se.interpretation,
                             p.product_name,
                             m.material_name
                      FROM stock_exports se
                      LEFT JOIN products p             ON p.id = se.product_id
                      LEFT JOIN material_information m ON m.id = se.material_id
                      WHERE DATE_FORMAT(se.created_at, '%Y-%m-%d %H:%i:%s') = '$key_safe'
                        AND se.type_export = 'other_export_outbound'
                      ORDER BY se.id ASC";
        $rows = db_fetch_array($sql_items) ?: [];

        $items = [];
        $names = [];
        foreach ($rows as $r) {
            $mid = (int) ($r['material_id'] ?? 0);
            $pid = (int) ($r['product_id'] ?? 0);
            $is_material = $mid > 0;
            $id   = $is_material ? $mid : $pid;
            $type = $is_material ? 'material' : 'product';
            $name = $is_material
                ? ($r['material_name'] ?: ('#' . $mid))
                : ($r['product_name']  ?: ('#' . $pid));
            $items[] = [
                'export_id'      => (int) $r['export_id'],
                'id'             => $id,
                'type'           => $type,
                'name'           => $name,
                'warehouse'      => $is_material ? 'Kho NVL' : 'Kho TP',
                'quantity'       => (float) $r['quantity'],
                'unit_price'     => (float) $r['unit_price'],
                'total_amount'   => (float) $r['total_amount'],
                'interpretation' => $r['interpretation'] ?: '',
            ];
            $names[] = $name;
        }

        $summary = 'Xuất kho khác: ' . implode(', ', $names);
        $max_len = 160;
        if (mb_strlen($summary, 'UTF-8') > $max_len) {
            $summary = mb_substr($summary, 0, $max_len, 'UTF-8') . '...';
        }

        $hist_date    = history_date_display($g['created_at']);
        $date_display = $hist_date['text'];
        $date_color   = $hist_date['color'];

        // Nghiệp vụ kế toán đã nhập của riêng phiếu này (reference_id = export_id nhỏ nhất)
        // → "Sửa" chỉ đổ lại đúng các nghiệp vụ này, không phải toàn bộ thư viện.
        $export_ids = array_map(function ($it) { return (int) $it['export_id']; }, $items);
        $first_eid  = !empty($export_ids) ? min($export_ids) : 0;
        $je_entries = $first_eid ? wo_get_outbound_journal_entries('other_export_outbound', $first_eid) : [];

        $batches[] = [
            'group_key'    => $g['group_key'],
            'created_at'   => $g['created_at'],
            'date_display' => $date_display,
            'date_color'   => $date_color,
            'summary'      => $summary,
            'total'        => (float) $g['total'],
            'items'        => $items,
            'je_entries'   => $je_entries,
        ];
    }
    return $batches;
}

/**
 * Ghi 1 batch Xuất kho khác.
 * $items: [{id, type:'product'|'material', quantity, unit_price, total_amount, interpretation}, ...]
 * Trả ['ok'=>bool, 'count'=>int, 'first_export_id'=>int].
 */
function wo_record_other_outbound_batch(array $items, $created_at = null)
{
    if (empty($items)) return ['ok' => false, 'count' => 0, 'first_export_id' => 0];

    $count = 0;
    $first_eid = 0;
    foreach ($items as $it) {
        $id    = (int) ($it['id'] ?? 0);
        $type  = trim((string) ($it['type'] ?? 'product'));
        $qty   = (float) ($it['quantity'] ?? 0);
        $price = (float) ($it['unit_price'] ?? 0);
        $total = (float) ($it['total_amount'] ?? 0);
        $ip    = trim((string) ($it['interpretation'] ?? ''));
        if ($id <= 0 || $qty <= 0) continue;

        $eid = wo_record_other_outbound_item($id, $type, $qty, $price, $total, $ip, $created_at);
        if ($eid > 0) {
            $count++;
            if ($first_eid === 0) $first_eid = $eid;
        }
    }

    if ($first_eid > 0) {
        $totals = wo_split_totals_by_type($items);
        wo_record_other_outbound_transactions($first_eid, $totals['tp'], $totals['nvl'], $created_at);
        // Bút toán user nhập trên form GHI BÚT TOÁN KẾ TOÁN (đa bút toán) — ghi thêm
        // ngoài các cặp tự động (632/155, 621/152). Chỉ ghi cụm hiện diện trên giao diện.
        je_insert_pairs('other_export_outbound', $first_eid, je_entries_from_payload([], null), im_sanitize_datetime($created_at));

        // Lưu nghiệp vụ đã nhập theo phiếu (để "Sửa" đổ lại đúng nghiệp vụ của phiếu)
        // + gom vào thư viện template (insert-if-new) cho lần sau chọn ở #je-transaction-name.
        $posted_je = wo_read_posted_je_entries();
        wo_save_outbound_journal_entries('other_export_outbound', $first_eid, $posted_je);
        wo_save_je_templates_if_new($posted_je);
    }

    return ['ok' => $count > 0, 'count' => $count, 'first_export_id' => $first_eid];
}

/**
 * Hoàn lại tồn từ 1 row stock_exports (đảo dấu so với khi ghi).
 */
function wo_rollback_other_outbound_row(array $row)
{
    $pid = (int) ($row['product_id'] ?? 0);
    $mid = (int) ($row['material_id'] ?? 0);
    $qty = (float) ($row['quantity'] ?? 0);
    if ($qty <= 0) return;
    if ($mid > 0) {
        im_adjust_material_inventory($mid, $qty);
        return;
    }
    if ($pid > 0) {
        $cur = db_fetch_row("SELECT id, quantity FROM finished_goods_inventory WHERE product_id = $pid LIMIT 1");
        if ($cur) {
            db_update('finished_goods_inventory', ['quantity' => (float) $cur['quantity'] + $qty], 'id = ' . (int) $cur['id']);
        } else {
            db_insert('finished_goods_inventory', ['product_id' => $pid, 'quantity' => $qty]);
        }
    }
}

/**
 * Xóa 1 batch other_export_outbound theo group_key:
 *  - Rollback tồn từng row trong stock_exports.
 *  - Xoá transactions theo reference_id IN (export_ids của batch).
 *  - Xoá stock_exports của batch.
 * Trả số dòng stock_exports đã xoá.
 */
function wo_delete_other_outbound_batch($group_key)
{
    $gk = trim((string) $group_key);
    if ($gk === '') return 0;
    $gk_safe = escape_string($gk);

    $rows = db_fetch_array("SELECT id, product_id, material_id, quantity
                            FROM stock_exports
                            WHERE type_export = 'other_export_outbound'
                              AND DATE_FORMAT(created_at, '%Y-%m-%d %H:%i:%s') = '$gk_safe'") ?: [];
    if (empty($rows)) return 0;

    $export_ids = [];
    foreach ($rows as $r) {
        wo_rollback_other_outbound_row($r);
        $export_ids[] = (int) $r['id'];
    }

    wo_delete_other_outbound_transactions($export_ids);

    // Nghiệp vụ đã nhập theo phiếu — khóa theo export_id nhỏ nhất (= reference_id).
    if (!empty($export_ids)) {
        wo_delete_outbound_journal_entries('other_export_outbound', min($export_ids));
    }

    db_query("DELETE FROM stock_exports
              WHERE type_export = 'other_export_outbound'
                AND DATE_FORMAT(created_at, '%Y-%m-%d %H:%i:%s') = '$gk_safe'");

    return count($rows);
}

/**
 * Sửa 1 batch other_export_outbound — wipe-and-rewrite:
 *  - Rollback + xoá batch cũ (gồm stock_exports + transactions).
 *  - Ghi lại batch mới với created_at có thể đổi (datetime picker).
 * Trả ['ok'=>bool, 'count'=>int, 'first_export_id'=>int].
 */
function wo_edit_other_outbound_batch($group_key, array $items, $created_at_new)
{
    wo_delete_other_outbound_batch($group_key);
    return wo_record_other_outbound_batch($items, $created_at_new);
}
