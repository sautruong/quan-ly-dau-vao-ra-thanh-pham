<?php
/**
 * =====================================================================
 *  tea_scent_group — Sổ theo dõi NVL "kiểm soát" dùng ủ hương trà: 1 NVL
 *  kiểm soát = 1 "nhóm", gắn với danh sách sản phẩm dùng NVL đó theo tỉ lệ
 *  (kg NVL / 1 đơn vị thành phẩm), có tồn đầu + lịch sử nhập riêng, và
 *  TỰ ĐỘNG trừ khi NVL được dùng để sản xuất (hook từ investment_products).
 * =====================================================================
 *  Dùng chung (require_once, KHÔNG autoload) bởi:
 *   - module tea_scent_group (hiển thị + thiết lập + nghiệp vụ thủ công)
 *   - module inventory_management (hook tự trừ + cảnh báo tồn ở view
 *     "Nhập giá vốn sản xuất" / investment_products).
 *
 *  Bảng: tsg_groups (1 dòng / NVL kiểm soát)
 *        tsg_setup_products (N-N: 1 nhóm dùng cho nhiều SP, theo tỉ lệ)
 *        tsg_ledger (sổ cái, khóa theo group_id)
 *   entry_type: 'opening' (mốc tồn đầu) | 'receive' (nhập thêm, thủ công)
 *             | 'usage' (xuất dùng SX, tự động từ hook).
 *   qty lưu SIGNED: + nhập (opening/receive), - xuất (usage).
 *
 *  Mọi hàm bọc guard function_exists để không định nghĩa trùng.
 * =====================================================================
 */

if (!function_exists('tsg_ensure_tables')) {

    /** Tạo các bảng (idempotent). */
    function tsg_ensure_tables()
    {
        static $done = false;
        if ($done) return;
        $done = true;

        db_query("CREATE TABLE IF NOT EXISTS tsg_groups (
            id INT AUTO_INCREMENT PRIMARY KEY,
            material_id INT NOT NULL,
            material_name VARCHAR(255) DEFAULT NULL,
            unit VARCHAR(50) DEFAULT NULL,
            warning_threshold DECIMAL(10,2) NOT NULL DEFAULT 4,
            note TEXT DEFAULT NULL,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            UNIQUE KEY uniq_material (material_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci");

        db_query("CREATE TABLE IF NOT EXISTS tsg_setup_products (
            id INT AUTO_INCREMENT PRIMARY KEY,
            group_id INT NOT NULL,
            product_id INT NOT NULL,
            product_name VARCHAR(255) DEFAULT NULL,
            usage_ratio DECIMAL(10,4) NOT NULL DEFAULT 0,
            note VARCHAR(500) DEFAULT NULL,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            UNIQUE KEY uniq_setup (group_id, product_id),
            KEY idx_product (product_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci");

        db_query("CREATE TABLE IF NOT EXISTS tsg_ledger (
            id INT AUTO_INCREMENT PRIMARY KEY,
            group_id INT NOT NULL,
            entry_type VARCHAR(20) NOT NULL,
            content VARCHAR(500) DEFAULT NULL,
            product_id INT DEFAULT NULL,
            product_name VARCHAR(255) DEFAULT NULL,
            qty DECIMAL(15,3) NOT NULL DEFAULT 0,
            entry_date DATE DEFAULT NULL,
            source_batch_key VARCHAR(32) DEFAULT NULL,
            user_id INT DEFAULT NULL,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            KEY idx_group (group_id),
            KEY idx_batch (source_batch_key)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci");
    }

    /** Kiểm tra 1 bảng có tồn tại không (an toàn cho hook chạy sớm/module chưa dùng). */
    function tsg_table_exists($table)
    {
        $t = escape_string((string) $table);
        return db_num_rows("SHOW TABLES LIKE '$t'") > 0;
    }

    /* ============================================================
     *  Nhóm (tsg_groups)
     * ============================================================ */

    /** Tạo 1 nhóm mới cho 1 NVL kiểm soát (1 material_id = 1 nhóm duy nhất). */
    function tsg_group_create($material_id, $material_name, $unit, $threshold = 4, $note = '')
    {
        tsg_ensure_tables();
        $mid = (int) $material_id;
        if ($mid <= 0) return 0;
        $existing = db_fetch_row("SELECT id FROM tsg_groups WHERE material_id = $mid LIMIT 1");
        if ($existing) return (int) $existing['id'];
        $th = (float) $threshold;
        if ($th <= 0) $th = 4;
        return (int) db_insert('tsg_groups', [
            'material_id'       => $mid,
            'material_name'     => (string) $material_name,
            'unit'              => (string) $unit,
            'warning_threshold' => $th,
            'note'              => trim((string) $note),
        ]);
    }

    function tsg_group_get($group_id)
    {
        tsg_ensure_tables();
        $gid = (int) $group_id;
        if ($gid <= 0) return null;
        return db_fetch_row("SELECT * FROM tsg_groups WHERE id = $gid LIMIT 1");
    }

    /** Danh sách nhóm kèm tồn hiện tại + số SP đã thiết lập. */
    function tsg_group_list()
    {
        tsg_ensure_tables();
        $rows = db_fetch_array("SELECT * FROM tsg_groups ORDER BY created_at DESC") ?: [];
        $out = [];
        foreach ($rows as $r) {
            $gid   = (int) $r['id'];
            $count = db_fetch_row("SELECT COUNT(*) AS c FROM tsg_setup_products WHERE group_id = $gid");
            $out[] = [
                'group_id'          => $gid,
                'material_id'       => (int) $r['material_id'],
                'material_name'     => $r['material_name'],
                'unit'              => $r['unit'],
                'warning_threshold' => (float) $r['warning_threshold'],
                'note'              => $r['note'],
                'balance'           => tsg_balance($gid),
                'product_count'     => $count ? (int) $count['c'] : 0,
            ];
        }
        return $out;
    }

    function tsg_group_update_threshold($group_id, $threshold)
    {
        tsg_ensure_tables();
        $gid = (int) $group_id;
        $th  = (float) $threshold;
        if ($gid <= 0 || $th <= 0) return false;
        db_update('tsg_groups', ['warning_threshold' => $th], 'id = ' . $gid);
        return true;
    }

    /** Xóa 1 nhóm + toàn bộ thiết lập/sổ cái liên quan. */
    function tsg_group_delete($group_id)
    {
        tsg_ensure_tables();
        $gid = (int) $group_id;
        if ($gid <= 0) return false;
        db_query("DELETE FROM tsg_setup_products WHERE group_id = $gid");
        db_query("DELETE FROM tsg_ledger WHERE group_id = $gid");
        db_query("DELETE FROM tsg_groups WHERE id = $gid");
        return true;
    }

    /* ============================================================
     *  Thiết lập sản phẩm dùng NVL kiểm soát (tsg_setup_products)
     * ============================================================ */

    function tsg_setup_list($group_id)
    {
        tsg_ensure_tables();
        $gid = (int) $group_id;
        if ($gid <= 0) return [];
        $rows = db_fetch_array(
            "SELECT * FROM tsg_setup_products WHERE group_id = $gid ORDER BY created_at ASC"
        ) ?: [];
        foreach ($rows as &$r) {
            $r['id']                  = (int) $r['id'];
            $r['group_id']            = (int) $r['group_id'];
            $r['product_id']          = (int) $r['product_id'];
            $r['usage_ratio']         = (float) $r['usage_ratio'];
            $r['usage_ratio_percent'] = round($r['usage_ratio'] * 100, 4);
        }
        unset($r);
        return $rows;
    }

    /** Thêm/cập nhật thiết lập 1 sản phẩm cho 1 nhóm. $usage_ratio = phân số (0.5 = 50%). */
    function tsg_setup_add($group_id, $product_id, $product_name, $usage_ratio, $note = '')
    {
        tsg_ensure_tables();
        $gid   = (int) $group_id;
        $pid   = (int) $product_id;
        $ratio = (float) $usage_ratio;
        if ($gid <= 0 || $pid <= 0 || $ratio <= 0) return 0;

        // db_insert() không dùng INSERT IGNORE -> nếu gọi thẳng khi đã tồn tại (UNIQUE KEY
        // uniq_setup) sẽ làm db_query() gọi db_sql_error()/exit() và sập cả request.
        $existing = db_fetch_row("SELECT id FROM tsg_setup_products WHERE group_id = $gid AND product_id = $pid LIMIT 1");
        if ($existing) {
            db_update('tsg_setup_products', [
                'usage_ratio'  => $ratio,
                'product_name' => (string) $product_name,
                'note'         => trim((string) $note),
            ], 'id = ' . (int) $existing['id']);
            return (int) $existing['id'];
        }
        return (int) db_insert('tsg_setup_products', [
            'group_id'     => $gid,
            'product_id'   => $pid,
            'product_name' => (string) $product_name,
            'usage_ratio'  => $ratio,
            'note'         => trim((string) $note),
        ]);
    }

    function tsg_setup_update_ratio($setup_id, $usage_ratio)
    {
        tsg_ensure_tables();
        $id    = (int) $setup_id;
        $ratio = (float) $usage_ratio;
        if ($id <= 0 || $ratio <= 0) return false;
        db_update('tsg_setup_products', ['usage_ratio' => $ratio], 'id = ' . $id);
        return true;
    }

    function tsg_setup_delete($setup_id)
    {
        tsg_ensure_tables();
        $id = (int) $setup_id;
        if ($id <= 0) return false;
        db_query("DELETE FROM tsg_setup_products WHERE id = $id");
        return true;
    }

    /**
     * Bản đồ product_id -> danh sách nhóm {group_id, material_id, material_name, unit,
     * usage_ratio, warning_threshold} mà sản phẩm đó có thiết lập. Dùng cho hook + cảnh báo.
     * Không bao giờ được phép làm hỏng luồng gọi (defensive: bảng chưa có -> trả rỗng).
     */
    function tsg_setup_map_for_products(array $product_ids)
    {
        try {
            if (!tsg_table_exists('tsg_setup_products') || !tsg_table_exists('tsg_groups')) return [];
            $ids = array_values(array_unique(array_map('intval', $product_ids)));
            $ids = array_filter($ids, function ($v) { return $v > 0; });
            if (!$ids) return [];
            $in = implode(',', $ids);
            $rows = db_fetch_array(
                "SELECT sp.product_id, sp.usage_ratio, g.id AS group_id, g.material_id,
                        g.material_name, g.unit, g.warning_threshold
                 FROM tsg_setup_products sp
                 JOIN tsg_groups g ON g.id = sp.group_id
                 WHERE sp.product_id IN ($in)"
            ) ?: [];
            $out = [];
            foreach ($rows as $r) {
                $pid = (int) $r['product_id'];
                $out[$pid][] = [
                    'group_id'          => (int) $r['group_id'],
                    'material_id'       => (int) $r['material_id'],
                    'material_name'     => $r['material_name'],
                    'unit'              => $r['unit'],
                    'usage_ratio'       => (float) $r['usage_ratio'],
                    'warning_threshold' => (float) $r['warning_threshold'],
                ];
            }
            return $out;
        } catch (\Throwable $e) {
            return [];
        }
    }

    /* ============================================================
     *  Sổ cái (tsg_ledger)
     * ============================================================ */

    function tsg_add_entry($group_id, $entry_type, $content, $signed_qty, $entry_date = null, $product_id = null, $product_name = null, $source_batch_key = null, $user_id = null)
    {
        tsg_ensure_tables();
        $gid = (int) $group_id;
        if ($gid <= 0) return 0;
        $valid = ['opening', 'receive', 'usage'];
        $type  = in_array($entry_type, $valid, true) ? $entry_type : 'receive';
        return (int) db_insert('tsg_ledger', [
            'group_id'         => $gid,
            'entry_type'       => $type,
            'content'          => (string) $content,
            'product_id'       => (int) $product_id > 0 ? (int) $product_id : null,
            'product_name'     => $product_name !== null && $product_name !== '' ? (string) $product_name : null,
            'qty'              => (float) $signed_qty,
            'entry_date'       => $entry_date ? date('Y-m-d', strtotime($entry_date)) : date('Y-m-d'),
            'source_batch_key' => $source_batch_key !== null && $source_batch_key !== '' ? (string) $source_batch_key : null,
            'user_id'          => (int) $user_id > 0 ? (int) $user_id : null,
        ]);
    }

    function tsg_raw_entries($group_id)
    {
        tsg_ensure_tables();
        $gid = (int) $group_id;
        if ($gid <= 0) return [];
        $rows = db_fetch_array(
            "SELECT id, group_id, entry_type, content, product_id, product_name, qty,
                    entry_date, source_batch_key, created_at
             FROM tsg_ledger
             WHERE group_id = $gid
             ORDER BY entry_date ASC, id ASC"
        ) ?: [];
        foreach ($rows as &$r) {
            $r['id']       = (int) $r['id'];
            $r['group_id'] = (int) $r['group_id'];
            $r['qty']      = (float) $r['qty'];
        }
        unset($r);
        return $rows;
    }

    /** Mốc tồn đầu hiện tại (dòng 'opening' duy nhất/nhóm), hoặc null nếu chưa thiết lập. */
    function tsg_opening_get($group_id)
    {
        tsg_ensure_tables();
        $gid = (int) $group_id;
        if ($gid <= 0) return null;
        $row = db_fetch_row("SELECT id, qty, entry_date FROM tsg_ledger WHERE group_id = $gid AND entry_type = 'opening' ORDER BY id DESC LIMIT 1");
        if (!$row) return null;
        return ['id' => (int) $row['id'], 'qty' => (float) $row['qty'], 'entry_date' => $row['entry_date']];
    }

    /** Thiết lập/ghi đè mốc tồn đầu (chỉ giữ 1 dòng 'opening' duy nhất/nhóm). */
    function tsg_set_opening($group_id, $qty, $date = null, $user_id = null)
    {
        tsg_ensure_tables();
        $gid = (int) $group_id;
        $q   = (float) $qty;
        if ($gid <= 0 || $q < 0) return false;
        db_query("DELETE FROM tsg_ledger WHERE group_id = $gid AND entry_type = 'opening'");
        tsg_add_entry($gid, 'opening', 'Tồn đầu ban đầu', $q, $date, null, null, null, $user_id);
        return true;
    }

    function tsg_add_receipt($group_id, $qty, $note = '', $date = null, $user_id = null)
    {
        tsg_ensure_tables();
        $gid = (int) $group_id;
        $q   = (float) $qty;
        if ($gid <= 0 || $q <= 0) return false;
        $content = 'Nhập thêm' . (trim((string) $note) !== '' ? ': ' . trim((string) $note) : '');
        tsg_add_entry($gid, 'receive', $content, $q, $date, null, null, null, $user_id);
        return true;
    }

    function tsg_receipts_history($group_id)
    {
        $all = tsg_raw_entries($group_id);
        $out = array_values(array_filter($all, function ($r) { return $r['entry_type'] === 'receive'; }));
        usort($out, function ($a, $b) {
            if ($a['entry_date'] !== $b['entry_date']) return strcmp($b['entry_date'], $a['entry_date']);
            return $b['id'] - $a['id'];
        });
        return $out;
    }

    /**
     * Tồn hiện tại = SUM(qty) toàn bộ sổ. Nếu $exclude_batch_key được truyền, loại trừ các
     * dòng 'usage' của batch đó (dùng để tính "tồn TRƯỚC" khi Sửa 1 phiếu xuất đã ghi).
     */
    function tsg_balance($group_id, $exclude_batch_key = null)
    {
        $all   = tsg_raw_entries($group_id);
        $total = 0.0;
        foreach ($all as $r) {
            if ($exclude_batch_key !== null && $r['entry_type'] === 'usage'
                && $r['source_batch_key'] === (string) $exclude_batch_key) continue;
            $total += $r['qty'];
        }
        return $total;
    }

    /**
     * Map material_id => total_qty THẬT đã xuất cho SP này, đọc từ items[].materials
     * (nguồn giá vốn investment_products — .input-total-qty user đã nhập/xác nhận).
     * Đây là số liệu ưu tiên; usage_ratio cấu hình ở tsg_setup_products chỉ dùng làm
     * fallback khi material kiểm soát không nằm trong công thức của SP (dữ liệu thiếu).
     */
    function tsg_materials_qty_map($item)
    {
        $out  = [];
        $mats = isset($item['materials']) && is_array($item['materials']) ? $item['materials'] : [];
        foreach ($mats as $m) {
            $mid = (int) ($m['material_id'] ?? 0);
            if ($mid <= 0) continue;
            $out[$mid] = (float) ($m['total_qty'] ?? 0);
        }
        return $out;
    }

    /* ============================================================
     *  Cảnh báo "sắp cần ủ thêm" — gọi TRƯỚC khi ghi/sửa phiếu investment.
     * ============================================================ */

    /**
     * $items: shape giống investment_products (product_id, product_qty, product_name,
     * materials[] gồm material_id + total_qty thật đã xuất).
     * Gộp usage theo group_id, so a = tồn TRƯỚC (loại trừ batch đang sửa nếu có) với
     * b = tổng SL sẽ xuất lần này. Cảnh báo khi b>0 và a/b <= threshold (mặc định 4).
     */
    function tsg_check_usage_warnings($items, $exclude_batch_key = null)
    {
        try {
            if (!is_array($items) || empty($items)) return [];
            if (!tsg_table_exists('tsg_setup_products')) return [];

            $pids = [];
            foreach ($items as $it) {
                $pid = (int) ($it['product_id'] ?? 0);
                if ($pid > 0) $pids[] = $pid;
            }
            $map = tsg_setup_map_for_products($pids);
            if (empty($map)) return [];

            // Gộp usage_qty theo group_id (1 nhóm có thể bị đụng bởi nhiều SP trong 1 batch).
            $by_group = [];
            foreach ($items as $it) {
                $pid  = (int) ($it['product_id'] ?? 0);
                $pqty = (float) ($it['product_qty'] ?? 0);
                if ($pid <= 0 || $pqty <= 0 || empty($map[$pid])) continue;
                $matQty = tsg_materials_qty_map($it);
                foreach ($map[$pid] as $g) {
                    $gid = $g['group_id'];
                    // Bắt dính theo SL thật đã xuất ở giá vốn; chỉ fallback về usage_ratio
                    // cấu hình khi material kiểm soát không có trong công thức SP (thiếu dữ liệu).
                    $use = array_key_exists($g['material_id'], $matQty)
                        ? $matQty[$g['material_id']]
                        : $pqty * $g['usage_ratio'];
                    if ($use <= 0) continue;
                    if (!isset($by_group[$gid])) $by_group[$gid] = ['qty' => 0.0, 'info' => $g, 'products' => []];
                    $by_group[$gid]['qty'] += $use;
                    $by_group[$gid]['products'][] = (string) ($it['product_name'] ?? ('#' . $pid));
                }
            }

            $warnings = [];
            foreach ($by_group as $gid => $agg) {
                $b = $agg['qty'];
                if ($b <= 0) continue;
                $a = tsg_balance($gid, $exclude_batch_key);
                $threshold = (float) $agg['info']['warning_threshold'];
                if ($threshold <= 0) $threshold = 4;
                if (($a / $b) <= $threshold) {
                    $warnings[] = [
                        'group_id'      => $gid,
                        'material_name' => $agg['info']['material_name'],
                        'unit'          => $agg['info']['unit'],
                        'threshold'     => $threshold,
                        'a'             => $a,
                        'b'             => $b,
                        'times_left'    => $b > 0 ? round($a / $b, 2) : 0,
                        'products'      => array_values(array_unique($agg['products'])),
                    ];
                }
            }
            return $warnings;
        } catch (\Throwable $e) {
            return [];
        }
    }

    /* ============================================================
     *  HOOK — gọi từ inventory_managementModel.php sau khi ghi/sửa/xoá
     *  1 phiếu "Nhập giá vốn sản xuất" (investment_products).
     * ============================================================ */

    /**
     * Idempotent theo $group_key: xoá toàn bộ dòng 'usage' cũ của batch này rồi ghi lại
     * theo $items hiện tại — dùng chung cho cả Ghi mới lẫn Sửa.
     * Không bao giờ được phép làm hỏng luồng ghi giá vốn sản xuất của module host.
     */
    function tsg_on_investment_saved($group_key, $items)
    {
        try {
            $gk = trim((string) $group_key);
            if ($gk === '' || !is_array($items) || empty($items)) return;
            if (!tsg_table_exists('tsg_setup_products')) return; // module chưa dùng

            tsg_ensure_tables();
            // Idempotent: gỡ toàn bộ usage cũ của batch trước khi ghi lại.
            db_query("DELETE FROM tsg_ledger WHERE entry_type = 'usage' AND source_batch_key = '" . escape_string($gk) . "'");

            $pids = [];
            foreach ($items as $it) {
                $pid = (int) ($it['product_id'] ?? 0);
                if ($pid > 0) $pids[] = $pid;
            }
            $map = tsg_setup_map_for_products($pids);
            if (empty($map)) return;

            $ts         = strtotime($gk);
            $entry_date = $ts ? date('Y-m-d', $ts) : date('Y-m-d');

            foreach ($items as $it) {
                $pid  = (int) ($it['product_id'] ?? 0);
                $pqty = (float) ($it['product_qty'] ?? 0);
                if ($pid <= 0 || $pqty <= 0 || empty($map[$pid])) continue;
                $pname  = (string) ($it['product_name'] ?? ('#' . $pid));
                $matQty = tsg_materials_qty_map($it);
                foreach ($map[$pid] as $g) {
                    // Bắt dính theo SL thật đã xuất ở giá vốn; chỉ fallback về usage_ratio
                    // cấu hình khi material kiểm soát không có trong công thức SP (thiếu dữ liệu).
                    $use = array_key_exists($g['material_id'], $matQty)
                        ? $matQty[$g['material_id']]
                        : $pqty * $g['usage_ratio'];
                    if ($use <= 0) continue;
                    $pqtyTxt = rtrim(rtrim(number_format($pqty, 3, '.', ''), '0'), '.');
                    $content = 'Xuất SX: ' . $pname . ' (' . $pqtyTxt . ')';
                    tsg_add_entry($g['group_id'], 'usage', $content, -$use, $entry_date, $pid, $pname, $gk);
                }
            }
        } catch (\Throwable $e) {
            // Nuốt lỗi: không bao giờ được phép làm hỏng luồng ghi giá vốn sản xuất.
        }
    }

    /** Gỡ toàn bộ dòng 'usage' sinh từ 1 batch (khi xoá phiếu investment). */
    function tsg_on_investment_deleted($group_key)
    {
        try {
            $gk = trim((string) $group_key);
            if ($gk === '' || !tsg_table_exists('tsg_ledger')) return;
            db_query("DELETE FROM tsg_ledger WHERE entry_type = 'usage' AND source_batch_key = '" . escape_string($gk) . "'");
        } catch (\Throwable $e) {
        }
    }
}
