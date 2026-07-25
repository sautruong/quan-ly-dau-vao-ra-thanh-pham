<?php
/**
 * =====================================================================
 *  coffee_packaging — Sổ bao bì tại NCC + hook trừ tồn khi nhập kho TP
 * =====================================================================
 *  Dùng chung (require_once, KHÔNG autoload) bởi:
 *   - module order_coffee (hiển thị sổ + thêm nghiệp vụ thủ công)
 *   - module inventory_management (gọi hook coffee_order_on_finished_received
 *     ngay sau khi ghi nhận nhập kho thành phẩm mua/gia công của NCC).
 *
 *  Bảng: oc_packaging_ledger (mỗi NCC × mỗi loại bao bì là 1 sổ cái cộng/trừ).
 *   entry_type: 'opening' (tồn đầu/NCC còn) | 'send' (VAT gửi qua)
 *             | 'process' (NCC gia công, tự động) | 'loss' (hao hụt).
 *   qty lưu SIGNED: + nhập (opening/send), - xuất (process/loss).
 *
 *  Mọi hàm bọc guard function_exists để không định nghĩa trùng giữa 2 module.
 * =====================================================================
 */

if (!function_exists('coffee_pkg_ensure_tables')) {

    /** Tạo bảng sổ bao bì (idempotent). Nguồn chân lý chung. */
    function coffee_pkg_ensure_tables()
    {
        static $done = false;
        if ($done) return;
        $done = true;

        db_query("CREATE TABLE IF NOT EXISTS oc_packaging_ledger (
            id INT AUTO_INCREMENT PRIMARY KEY,
            supplier_id    INT NOT NULL,
            packaging_id   INT NOT NULL,
            packaging_name VARCHAR(255) DEFAULT NULL,
            entry_type     VARCHAR(20)  NOT NULL,
            content        VARCHAR(500) DEFAULT NULL,
            qty            DECIMAL(15,3) NOT NULL DEFAULT 0,
            entry_date     DATE DEFAULT NULL,
            source_invoice_id INT DEFAULT NULL,
            source_product_id INT DEFAULT NULL,
            user_id        INT DEFAULT NULL,
            created_at     TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            KEY idx_sup_pkg (supplier_id, packaging_id),
            KEY idx_src (source_invoice_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci");
    }

    /** Kiểm tra 1 bảng có tồn tại không (an toàn cho hook chạy sớm). */
    function coffee_pkg_table_exists($table)
    {
        $t = escape_string((string) $table);
        return db_num_rows("SHOW TABLES LIKE '$t'") > 0;
    }

    /** Thêm 1 dòng sổ (qty đã signed). $src = ['invoice_id','product_id','user_id']. */
    function coffee_pkg_add_entry($supplier_id, $packaging_id, $packaging_name, $entry_type, $content, $signed_qty, $entry_date = null, $src = [])
    {
        coffee_pkg_ensure_tables();
        $sid = (int) $supplier_id;
        $pid = (int) $packaging_id;
        if ($sid <= 0 || $pid <= 0) return 0;

        $valid = ['opening', 'send', 'process', 'loss', 'confirm'];
        $type  = in_array($entry_type, $valid, true) ? $entry_type : 'opening';

        $data = [
            'supplier_id'       => $sid,
            'packaging_id'      => $pid,
            'packaging_name'    => (string) $packaging_name,
            'entry_type'        => $type,
            'content'           => (string) $content,
            'qty'               => (float) $signed_qty,
            'entry_date'        => $entry_date ? date('Y-m-d', strtotime($entry_date)) : date('Y-m-d'),
            'source_invoice_id' => isset($src['invoice_id']) && (int) $src['invoice_id'] > 0 ? (int) $src['invoice_id'] : null,
            'source_product_id' => isset($src['product_id']) && (int) $src['product_id'] > 0 ? (int) $src['product_id'] : null,
            'user_id'           => isset($src['user_id']) && (int) $src['user_id'] > 0 ? (int) $src['user_id'] : null,
        ];
        return (int) db_insert('oc_packaging_ledger', $data);
    }

    /**
     * Toàn bộ dòng sổ CỦA 1 LOẠI BAO BÌ (1 NCC × 1 packaging_id), thứ tự thời gian tăng dần.
     * Nội bộ dùng bởi coffee_pkg_windowed_entries — không lọc theo mốc.
     */
    function coffee_pkg_raw_entries($supplier_id, $packaging_id)
    {
        coffee_pkg_ensure_tables();
        $sid = (int) $supplier_id;
        $pid = (int) $packaging_id;
        if ($sid <= 0 || $pid <= 0) return [];
        $rows = db_fetch_array(
            "SELECT id, packaging_id, packaging_name, entry_type, content, qty,
                    entry_date, source_invoice_id, source_product_id, created_at
             FROM oc_packaging_ledger
             WHERE supplier_id = $sid AND packaging_id = $pid
             ORDER BY entry_date ASC, id ASC"
        ) ?: [];
        foreach ($rows as &$r) {
            $r['id']           = (int) $r['id'];
            $r['packaging_id'] = (int) $r['packaging_id'];
            $r['qty']          = (float) $r['qty'];
        }
        unset($r);
        return $rows;
    }

    /**
     * Các "mốc" (checkpoint) của 1 loại bao bì tại 1 NCC — dòng 'opening' (NCC còn) hoặc
     * 'confirm' (Xác nhận tồn). Mới nhất trước.
     */
    function coffee_pkg_milestones($supplier_id, $packaging_id)
    {
        $all = coffee_pkg_raw_entries($supplier_id, $packaging_id);
        $out = [];
        foreach ($all as $r) {
            if (in_array($r['entry_type'], ['opening', 'confirm'], true)) $out[] = $r;
        }
        // Mới nhất (ngày lớn hơn, rồi id lớn hơn) lên đầu.
        usort($out, function ($a, $b) {
            if ($a['entry_date'] !== $b['entry_date']) return strcmp($b['entry_date'], $a['entry_date']);
            return $b['id'] - $a['id'];
        });
        return $out;
    }

    /**
     * Dòng sổ + số dư của 1 loại bao bì, TÍNH TỪ 1 MỐC trở đi (tránh cộng dồn vô hạn từ đầu).
     * $milestone_id = 0 -> tự chọn mốc MỚI NHẤT (mặc định); không có mốc nào -> lấy toàn bộ lịch sử.
     * Dòng mốc đầu tiên nếu là 'confirm' sẽ được hiển thị lại thành 'opening' / 'NCC còn'
     * (dùng làm tồn đầu kỳ mới); các mốc khác xuất hiện sau đó trong cửa sổ chỉ để tham khảo,
     * KHÔNG cộng dồn vào số dư (tránh tính trùng số đã xác nhận).
     */
    function coffee_pkg_windowed_entries($supplier_id, $packaging_id, $milestone_id = 0)
    {
        $all = coffee_pkg_raw_entries($supplier_id, $packaging_id);
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
            if (in_array($r['entry_type'], ['opening', 'confirm'], true)) continue; // mốc khác -> chỉ hiển thị
            $total += $r['qty'];
        }
        if ($anchorIdx >= 0 && $window[0]['entry_type'] === 'confirm') {
            $window[0]['content']    = 'NCC còn';
            $window[0]['entry_type'] = 'opening';
        }
        return ['entries' => $window, 'total' => $total];
    }

    /** Toàn bộ dòng sổ của 1 NCC (mọi loại bao bì), mỗi loại tính từ mốc mới nhất của riêng nó. */
    function coffee_pkg_entries($supplier_id)
    {
        coffee_pkg_ensure_tables();
        $sid = (int) $supplier_id;
        if ($sid <= 0) return [];
        $pkgIds = db_fetch_array(
            "SELECT DISTINCT packaging_id FROM oc_packaging_ledger WHERE supplier_id = $sid"
        ) ?: [];
        $rows = [];
        foreach ($pkgIds as $p) {
            $pid = (int) $p['packaging_id'];
            $w = coffee_pkg_windowed_entries($sid, $pid, 0);
            foreach ($w['entries'] as $e) $rows[] = $e;
        }
        return $rows;
    }

    /** Số dư hiện tại theo từng loại bao bì của 1 NCC (mỗi loại tính từ mốc mới nhất). */
    function coffee_pkg_balances($supplier_id)
    {
        coffee_pkg_ensure_tables();
        $sid = (int) $supplier_id;
        if ($sid <= 0) return [];
        $ids = db_fetch_array(
            "SELECT packaging_id, MAX(packaging_name) AS packaging_name
             FROM oc_packaging_ledger WHERE supplier_id = $sid
             GROUP BY packaging_id ORDER BY packaging_name ASC"
        ) ?: [];
        $out = [];
        foreach ($ids as $row) {
            $pid = (int) $row['packaging_id'];
            $w = coffee_pkg_windowed_entries($sid, $pid, 0);
            $out[] = [
                'packaging_id'   => $pid,
                'packaging_name' => $row['packaging_name'],
                'balance'        => $w['total'],
            ];
        }
        return $out;
    }

    /** Số dư 1 loại bao bì cụ thể (tính từ mốc mới nhất). */
    function coffee_pkg_balance($supplier_id, $packaging_id)
    {
        coffee_pkg_ensure_tables();
        $w = coffee_pkg_windowed_entries($supplier_id, $packaging_id, 0);
        return $w['total'];
    }

    /** Tên hiển thị NVL: ưu tiên material_name. */
    function coffee_pkg_mat_name($row)
    {
        $m = trim((string) ($row['material_name'] ?? ''));
        return $m !== '' ? $m : (string) ($row['common_material_name'] ?? '');
    }

    /**
     * HOOK — gọi sau khi NHẬP KHO thành phẩm (mua/gia công) của 1 NCC.
     * Nếu SP có thiết lập công thức (oc_recipes) kèm bao bì -> ghi 1 dòng 'process'
     * trừ tồn bao bì phía NCC theo số thành phẩm nhập (1 thành phẩm = 1 bì).
     * Nội dung neo theo TÊN NGUYÊN LIỆU (NCC chỉ biết tên NL, không biết tên SP).
     *
     * Idempotent: cùng (invoice, product) -> xóa dòng 'process' cũ rồi ghi lại,
     * nên khi SỬA phiếu nhập không bị trừ trùng.
     */
    function coffee_order_on_finished_received($product_id, $qty, $supplier_id, $created_at, $invoice_id = 0)
    {
        // Không bao giờ được phép làm hỏng luồng nhập kho của module host.
        try {
            $pid = (int) $product_id;
            $sid = (int) $supplier_id;
            $q   = (float) $qty;
            $inv = (int) $invoice_id;
            if ($pid <= 0 || $sid <= 0 || $q <= 0) return;
            if (!coffee_pkg_table_exists('oc_recipes')) return; // module order_coffee chưa dùng

            $rec = db_fetch_row(
                "SELECT packaging_id, packaging_name, materials
                 FROM oc_recipes WHERE product_id = $pid LIMIT 1"
            );
            if (!$rec) return;
            $pkgId = (int) ($rec['packaging_id'] ?? 0);
            if ($pkgId <= 0) return; // SP không gửi bao bì -> không trừ

            // Tên NL trong công thức để ghi nội dung.
            $mats = json_decode((string) ($rec['materials'] ?? ''), true);
            $names = [];
            if (is_array($mats)) {
                foreach ($mats as $m) {
                    $nm = trim((string) ($m['name'] ?? ''));
                    if ($nm !== '') $names[] = $nm;
                }
            }
            $qtyTxt = rtrim(rtrim(number_format($q, 3, '.', ''), '0'), '.');
            $content = 'NCC Gia công: ' . $qtyTxt
                . (count($names) ? ' (' . implode(' + ', $names) . ')' : '');

            coffee_pkg_ensure_tables();

            // Idempotent: gỡ dòng process cũ của đúng (invoice, product) trước khi ghi mới.
            if ($inv > 0) {
                db_query("DELETE FROM oc_packaging_ledger
                          WHERE entry_type = 'process'
                            AND source_invoice_id = $inv
                            AND source_product_id = $pid");
            }

            coffee_pkg_add_entry(
                $sid,
                $pkgId,
                (string) ($rec['packaging_name'] ?? ''),
                'process',
                $content,
                -$q,                    // trừ tồn
                $created_at,
                ['invoice_id' => $inv, 'product_id' => $pid]
            );
        } catch (\Throwable $e) {
            // Nuốt lỗi: không ảnh hưởng nghiệp vụ nhập kho.
        }
    }

    /** Gỡ toàn bộ dòng 'process' sinh từ 1 invoice (khi xóa phiếu nhập). */
    function coffee_pkg_remove_by_invoice($invoice_id)
    {
        try {
            if (!coffee_pkg_table_exists('oc_packaging_ledger')) return;
            $inv = (int) $invoice_id;
            if ($inv <= 0) return;
            db_query("DELETE FROM oc_packaging_ledger
                      WHERE entry_type = 'process' AND source_invoice_id = $inv");
        } catch (\Throwable $e) {
        }
    }
}
