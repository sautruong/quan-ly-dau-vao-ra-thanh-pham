<?php
/**
 * =====================================================================
 *  customer_packaging — Sổ bao bì DO KHÁCH HÀNG GỬI (chiều ngược lại
 *  với coffee_packaging.php: ở đó MÌNH gửi bì cho NCC; ở đây KHÁCH gửi
 *  bì cho MÌNH để đóng gói hộ sản phẩm của họ).
 * =====================================================================
 *  Dùng chung (require_once, KHÔNG autoload) bởi:
 *   - module customer_packaging (hiển thị sổ + thiết lập + thêm nghiệp vụ)
 *   - module inventory_management (banner nhắc ở view "Nhập giá vốn sản xuất")
 *
 *  customer_name / packaging_name là TEXT TỰ ĐẶT (không FK) — được gom lại
 *  thành 1 "loại bao bì" (cp_packaging_types.id = type_id) để làm khóa sổ,
 *  tương tự supplier_id+packaging_id bên coffee_packaging nhưng gộp thành
 *  1 khóa duy nhất vì tên là text.
 *
 *  Bảng: cp_packaging_types (1 dòng / combo khách hàng+bao bì)
 *        cp_setup_products  (N-N: 1 loại bao bì có thể dùng cho nhiều SP,
 *                             1 SP có thể dùng nhiều loại bao bì của nhiều khách)
 *        cp_packaging_ledger (sổ cái, khóa theo type_id)
 *   entry_type: 'opening' (tồn đầu ghi nhận) | 'receive' (khách chuyển bì qua)
 *             | 'usage' (xuất dùng cho 1 SP, thủ công) | 'loss' (hao hụt)
 *             | 'confirm' (chốt mốc tồn, đối chiếu thực tế).
 *   qty lưu SIGNED: + nhập (opening/receive/confirm), - xuất (usage/loss).
 *
 *  Mọi hàm bọc guard function_exists để không định nghĩa trùng.
 * =====================================================================
 */

if (!function_exists('cp_ensure_tables')) {

    /** Tạo các bảng (idempotent). */
    function cp_ensure_tables()
    {
        static $done = false;
        if ($done) return;
        $done = true;

        db_query("CREATE TABLE IF NOT EXISTS cp_packaging_types (
            id INT AUTO_INCREMENT PRIMARY KEY,
            customer_name  VARCHAR(255) NOT NULL,
            packaging_name VARCHAR(255) NOT NULL,
            note TEXT DEFAULT NULL,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            UNIQUE KEY uniq_type (customer_name, packaging_name)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci");

        db_query("CREATE TABLE IF NOT EXISTS cp_setup_products (
            id INT AUTO_INCREMENT PRIMARY KEY,
            type_id INT NOT NULL,
            product_id INT NOT NULL,
            product_name VARCHAR(255) DEFAULT NULL,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            UNIQUE KEY uniq_setup (type_id, product_id),
            KEY idx_product (product_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci");

        db_query("CREATE TABLE IF NOT EXISTS cp_packaging_ledger (
            id INT AUTO_INCREMENT PRIMARY KEY,
            type_id INT NOT NULL,
            entry_type  VARCHAR(20) NOT NULL,
            content     VARCHAR(500) DEFAULT NULL,
            product_id  INT DEFAULT NULL,
            product_name VARCHAR(255) DEFAULT NULL,
            qty DECIMAL(15,3) NOT NULL DEFAULT 0,
            entry_date DATE DEFAULT NULL,
            user_id INT DEFAULT NULL,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            KEY idx_type (type_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci");
    }

    /** Kiểm tra 1 bảng có tồn tại không (an toàn cho hook chạy sớm/module chưa dùng). */
    function cp_table_exists($table)
    {
        $t = escape_string((string) $table);
        return db_num_rows("SHOW TABLES LIKE '$t'") > 0;
    }

    /** Tìm hoặc tạo 1 "loại bao bì" (combo khách hàng + tên bao bì). Trả về type_id. */
    function cp_type_find_or_create($customer_name, $packaging_name)
    {
        cp_ensure_tables();
        $cn = trim((string) $customer_name);
        $pn = trim((string) $packaging_name);
        if ($cn === '' || $pn === '') return 0;
        $cnE = escape_string($cn);
        $pnE = escape_string($pn);

        db_query("INSERT IGNORE INTO cp_packaging_types (customer_name, packaging_name) VALUES ('$cnE','$pnE')");
        $row = db_fetch_row("SELECT id FROM cp_packaging_types WHERE customer_name = '$cnE' AND packaging_name = '$pnE' LIMIT 1");
        return $row ? (int) $row['id'] : 0;
    }

    function cp_type_get($type_id)
    {
        $tid = (int) $type_id;
        if ($tid <= 0) return null;
        return db_fetch_row("SELECT id, customer_name, packaging_name FROM cp_packaging_types WHERE id = $tid LIMIT 1");
    }

    /** Thêm 1 dòng sổ (qty đã signed). */
    function cp_add_entry($type_id, $entry_type, $content, $signed_qty, $entry_date = null, $product_id = null, $product_name = null, $user_id = null)
    {
        cp_ensure_tables();
        $tid = (int) $type_id;
        if ($tid <= 0) return 0;

        $valid = ['opening', 'receive', 'usage', 'loss', 'confirm'];
        $type  = in_array($entry_type, $valid, true) ? $entry_type : 'opening';

        $data = [
            'type_id'      => $tid,
            'entry_type'   => $type,
            'content'      => (string) $content,
            'product_id'   => (int) $product_id > 0 ? (int) $product_id : null,
            'product_name' => $product_name !== null && $product_name !== '' ? (string) $product_name : null,
            'qty'          => (float) $signed_qty,
            'entry_date'   => $entry_date ? date('Y-m-d', strtotime($entry_date)) : date('Y-m-d'),
            'user_id'      => (int) $user_id > 0 ? (int) $user_id : null,
        ];
        return (int) db_insert('cp_packaging_ledger', $data);
    }

    /** Toàn bộ dòng sổ của 1 type_id, thứ tự thời gian tăng dần. */
    function cp_raw_entries($type_id)
    {
        cp_ensure_tables();
        $tid = (int) $type_id;
        if ($tid <= 0) return [];
        $rows = db_fetch_array(
            "SELECT id, type_id, entry_type, content, product_id, product_name, qty, entry_date, created_at
             FROM cp_packaging_ledger
             WHERE type_id = $tid
             ORDER BY entry_date ASC, id ASC"
        ) ?: [];
        foreach ($rows as &$r) {
            $r['id']      = (int) $r['id'];
            $r['type_id'] = (int) $r['type_id'];
            $r['qty']     = (float) $r['qty'];
        }
        unset($r);
        return $rows;
    }

    /** Các "mốc" (opening/confirm) của 1 loại bao bì. Mới nhất trước. */
    function cp_milestones($type_id)
    {
        $all = cp_raw_entries($type_id);
        $out = [];
        foreach ($all as $r) {
            if (in_array($r['entry_type'], ['opening', 'confirm'], true)) $out[] = $r;
        }
        usort($out, function ($a, $b) {
            if ($a['entry_date'] !== $b['entry_date']) return strcmp($b['entry_date'], $a['entry_date']);
            return $b['id'] - $a['id'];
        });
        return $out;
    }

    /**
     * Dòng sổ + số dư của 1 loại bao bì, tính từ 1 mốc trở đi.
     * $milestone_id = 0 -> tự chọn mốc mới nhất; không có mốc -> lấy toàn bộ lịch sử.
     */
    function cp_windowed_entries($type_id, $milestone_id = 0)
    {
        $all = cp_raw_entries($type_id);
        if (!$all) return ['entries' => [], 'total' => 0.0];

        $mid = (int) $milestone_id;
        $anchorIdx = -1;
        if ($mid > 0) {
            foreach ($all as $i => $r) { if ($r['id'] === $mid) { $anchorIdx = $i; break; } }
        }
        if ($anchorIdx < 0) {
            for ($i = count($all) - 1; $i >= 0; $i--) {
                if (in_array($all[$i]['entry_type'], ['opening', 'confirm'], true)) { $anchorIdx = $i; break; }
            }
        }
        $window = $anchorIdx >= 0 ? array_slice($all, $anchorIdx) : $all;

        $total = 0.0;
        foreach ($window as $i => $r) {
            if ($i === 0 && $anchorIdx >= 0) { $total += $r['qty']; continue; }
            if (in_array($r['entry_type'], ['opening', 'confirm'], true)) continue;
            $total += $r['qty'];
        }
        if ($anchorIdx >= 0 && $window[0]['entry_type'] === 'confirm') {
            $window[0]['content']    = 'Chốt tồn';
            $window[0]['entry_type'] = 'opening';
        }
        return ['entries' => $window, 'total' => $total];
    }

    function cp_balance($type_id)
    {
        $w = cp_windowed_entries($type_id, 0);
        return $w['total'];
    }

    /** Danh sách tên khách hàng duy nhất (gợi ý autocomplete). */
    function cp_customer_names($keyword = '')
    {
        cp_ensure_tables();
        $kw = trim((string) $keyword);
        $where = $kw === '' ? '' : "WHERE customer_name LIKE '%" . escape_string($kw) . "%'";
        $rows = db_fetch_array(
            "SELECT DISTINCT customer_name FROM cp_packaging_types $where ORDER BY customer_name ASC LIMIT 20"
        ) ?: [];
        return array_map(function ($r) { return $r['customer_name']; }, $rows);
    }

    /** Danh sách tên bao bì đã dùng cho 1 khách hàng (gợi ý autocomplete). */
    function cp_packaging_names_for_customer($customer_name, $keyword = '')
    {
        cp_ensure_tables();
        $cn = escape_string(trim((string) $customer_name));
        if ($cn === '') return [];
        $kw = trim((string) $keyword);
        $extra = $kw === '' ? '' : " AND packaging_name LIKE '%" . escape_string($kw) . "%'";
        $rows = db_fetch_array(
            "SELECT DISTINCT packaging_name FROM cp_packaging_types WHERE customer_name = '$cn'$extra ORDER BY packaging_name ASC LIMIT 20"
        ) ?: [];
        return array_map(function ($r) { return $r['packaging_name']; }, $rows);
    }

    /** Các loại bao bì (type) + số dư hiện tại của 1 khách hàng. */
    function cp_types_for_customer($customer_name)
    {
        cp_ensure_tables();
        $cn = escape_string(trim((string) $customer_name));
        if ($cn === '') return [];
        $rows = db_fetch_array(
            "SELECT id, packaging_name FROM cp_packaging_types WHERE customer_name = '$cn' ORDER BY packaging_name ASC"
        ) ?: [];
        $out = [];
        foreach ($rows as $row) {
            $tid = (int) $row['id'];
            $out[] = [
                'type_id'        => $tid,
                'packaging_name' => $row['packaging_name'],
                'balance'        => cp_balance($tid),
            ];
        }
        return $out;
    }

    /** Toàn bộ dòng sổ của 1 khách hàng (mọi loại bao bì), mỗi loại tính từ mốc mới nhất. */
    function cp_entries_for_customer($customer_name)
    {
        cp_ensure_tables();
        $cn = escape_string(trim((string) $customer_name));
        if ($cn === '') return [];
        $ids = db_fetch_array("SELECT id FROM cp_packaging_types WHERE customer_name = '$cn'") ?: [];
        $rows = [];
        foreach ($ids as $row) {
            $tid = (int) $row['id'];
            $w = cp_windowed_entries($tid, 0);
            foreach ($w['entries'] as $e) $rows[] = $e;
        }
        return $rows;
    }

    /** Sổ đầy đủ (types + entries + tổng) của 1 khách hàng — dùng cho khối .cp-card. */
    function cp_customer_book($customer_name)
    {
        $types    = cp_types_for_customer($customer_name);
        $entries  = cp_entries_for_customer($customer_name);
        $total = 0.0;
        foreach ($types as $t) $total += (float) $t['balance'];
        return ['types' => $types, 'entries' => $entries, 'total' => $total];
    }

    /** Danh sách toàn bộ thiết lập (khách hàng + bao bì + sản phẩm) — cho bảng "Thiết lập". */
    function cp_setup_list()
    {
        cp_ensure_tables();
        $rows = db_fetch_array(
            "SELECT sp.id AS setup_id, sp.product_id, sp.product_name,
                    t.id AS type_id, t.customer_name, t.packaging_name
             FROM cp_setup_products sp
             JOIN cp_packaging_types t ON t.id = sp.type_id
             ORDER BY t.customer_name ASC, t.packaging_name ASC, sp.product_name ASC"
        ) ?: [];
        foreach ($rows as &$r) {
            $r['setup_id']   = (int) $r['setup_id'];
            $r['product_id'] = (int) $r['product_id'];
            $r['type_id']    = (int) $r['type_id'];
        }
        unset($r);
        return $rows;
    }

    /** Thêm 1 thiết lập (tạo type nếu chưa có, rồi gắn sản phẩm — bỏ qua nếu đã tồn tại). */
    function cp_setup_add($customer_name, $packaging_name, $product_id, $product_name, $note = '')
    {
        cp_ensure_tables();
        $tid = cp_type_find_or_create($customer_name, $packaging_name);
        $pid = (int) $product_id;
        if ($tid <= 0 || $pid <= 0) return 0;

        if ($note !== '') {
            $noteE = escape_string(trim((string) $note));
            db_query("UPDATE cp_packaging_types SET note = '$noteE' WHERE id = $tid");
        }

        // db_insert() không dùng INSERT IGNORE -> nếu gọi thẳng khi đã tồn tại (UNIQUE KEY
        // uniq_setup) sẽ làm db_query() gọi db_sql_error()/exit() và sập cả request.
        // Nên kiểm tra tồn tại trước, chỉ insert khi thực sự chưa có.
        $existing = db_fetch_row("SELECT id FROM cp_setup_products WHERE type_id = $tid AND product_id = $pid LIMIT 1");
        if ($existing) return (int) $existing['id'];

        return (int) db_insert('cp_setup_products', [
            'type_id'      => $tid,
            'product_id'   => $pid,
            'product_name' => (string) $product_name,
        ]);
    }

    function cp_setup_delete($setup_id)
    {
        cp_ensure_tables();
        $id = (int) $setup_id;
        if ($id <= 0) return false;
        db_query("DELETE FROM cp_setup_products WHERE id = $id");
        return true;
    }

    /**
     * Bản đồ product_id -> danh sách {customer_name, packaging_name} đã thiết lập.
     * Dùng cho banner nhắc ở view "Nhập giá vốn sản xuất" (investment_products).
     * Không bao giờ được phép làm hỏng luồng gọi (defensive: bảng chưa có -> trả rỗng).
     */
    function cp_products_setup_map(array $product_ids)
    {
        try {
            if (!cp_table_exists('cp_setup_products') || !cp_table_exists('cp_packaging_types')) return [];
            $ids = array_values(array_unique(array_map('intval', $product_ids)));
            $ids = array_filter($ids, function ($v) { return $v > 0; });
            if (!$ids) return [];
            $in = implode(',', $ids);
            $rows = db_fetch_array(
                "SELECT sp.product_id, t.customer_name, t.packaging_name
                 FROM cp_setup_products sp
                 JOIN cp_packaging_types t ON t.id = sp.type_id
                 WHERE sp.product_id IN ($in)
                 ORDER BY t.customer_name ASC, t.packaging_name ASC"
            ) ?: [];
            $out = [];
            foreach ($rows as $r) {
                $pid = (int) $r['product_id'];
                $out[$pid][] = ['customer_name' => $r['customer_name'], 'packaging_name' => $r['packaging_name']];
            }
            return $out;
        } catch (\Throwable $e) {
            return [];
        }
    }
}
