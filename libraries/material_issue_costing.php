<?php
/**
 * material_issue_costing — Giá vốn xuất NVL dùng sản xuất theo MÔ HÌNH 2 LỚP GIÁ.
 *
 * Bối cảnh: hệ thống KHÔNG lưu kho theo lô FIFO. material_inventory chỉ là 1 con số
 * tồn / NVL; giá nhập gần nhất ghi đè vào material_purchase_prices. Khi xuất dùng NVL
 * (page investment_products / "Nhập giá vốn sản xuất"), ta cần tách giá vốn thành 2 lớp.
 *
 * ĐỊNH NGHĨA LỚP (theo TỒN HIỆN TẠI tại $as_of, KHÔNG theo tồn-trước-nhập):
 *   - tồn_trước_xuất = tồn NVL ngay TRƯỚC phiếu xuất đang tính (truy ngược chứng từ tới $as_of).
 *   - Lần nhập gần đây = quantity của phiếu mua gần nhất (recent_qty, price_new).
 *   - Lớp CŨ (mi_ago) = max(0, tồn_trước_xuất − recent_qty): phần tồn còn lại CỦA LẦN NHẬP
 *                       TRƯỚC ĐÓ, định giá theo lô mua trước đó (price_old).
 *   - Lớp MỚI = phần thuộc lần nhập gần đây, định giá price_new.
 *   Nhờ trừ recent_qty khỏi TỒN HIỆN TẠI, các lần xuất đã xảy ra giữa lần nhập gần đây và
 *   $as_of được phản ánh đúng (không còn đếm thừa lớp cũ như cách tồn-trước-nhập cũ).
 *
 *   qt <= mi_ago : cost = price_old * qt
 *   qt >  mi_ago : cost = price_old * mi_ago + price_new * (qt - mi_ago)   (kèm cảnh báo)
 *
 * Tất cả số liệu được TRUY XUẤT NGƯỢC từ lịch sử chứng từ (stock_imports + stock_exports +
 * stock_import_purchase_costs), nên cùng một hàm chạy được cho:
 *   (1) lúc nhập giá vốn  → tính .input-cost (Phần 1).
 *   (2) lúc sửa giá nhập   → recompute & ghi đè giá vốn các phiếu xuất sau đó (Phần 2).
 * Vì deterministic theo lịch sử, recost = chạy lại (1) với as_of = ngày xuất → idempotent.
 *
 * GIỚI HẠN (đã thống nhất): mô hình 2 lớp chỉ chính xác tuyệt đối khi trước phiếu nhập
 * gần nhất tồn nằm ở 1 mặt bằng giá (price_old). Nếu tồn còn ≥2 lô chưa dùng hết ở các giá
 * khác nhau thì đây là xấp xỉ. Muốn chính xác mọi trường hợp cần FIFO nhiều lô.
 *
 * Phụ thuộc: db_query/db_fetch_row/db_fetch_array/db_insert/db_update/escape_string (core)
 *            + je_insert_pairs (helper/accounting, autoload).
 */

if (!function_exists('mic_compute_issue_cost')) {

    /** Marker interpretation của phiếu xuất "thiếu tồn" (KHÔNG trừ tồn) → loại khi back-calc. */
    function mic_shortage_marker()
    {
        return 'Chưa ghi dữ liệu do có hàng hóa đang thiếu tồn';
    }

    /** Chuẩn hoá datetime → 'Y-m-d H:i:s' (null nếu rỗng/không parse được). */
    function mic_dt($dt)
    {
        if ($dt === null) return null;
        $dt = trim((string) $dt);
        if ($dt === '') return null;
        $t = strtotime($dt);
        return $t ? date('Y-m-d H:i:s', $t) : null;
    }

    /** Chuẩn hoá về 'Y-m-d 00:00:00' (khớp key ngày của rmpi / fpp). */
    function mic_date_only($dt)
    {
        $t = strtotime((string) $dt);
        return $t ? date('Y-m-d 00:00:00', $t) : null;
    }

    /**
     * Tồn NVL ngay TRƯỚC mốc $before_dt (truy xuất ngược từ lịch sử chứng từ):
     *   = Σ stock_imports.quantity (material, created_at < before)
     *   − Σ stock_exports.quantity (material, created_at < before, BỎ marker thiếu tồn).
     * Gom MỌI loại chứng từ chạm tồn NVL (mua / nhập khác / xuất SX / xuất khác / bán NVL).
     */
    function mic_inventory_before($material_id, $before_dt)
    {
        $mid = (int) $material_id;
        $b   = mic_dt($before_dt);
        if ($mid <= 0 || $b === null) return 0.0;
        $bs = escape_string($b);

        $in = db_fetch_row("SELECT COALESCE(SUM(quantity),0) AS q FROM stock_imports
                            WHERE material_id = $mid AND created_at < '$bs'");
        $marker = escape_string(mic_shortage_marker());
        $out = db_fetch_row("SELECT COALESCE(SUM(quantity),0) AS q FROM stock_exports
                             WHERE material_id = $mid AND created_at < '$bs'
                               AND (interpretation IS NULL OR interpretation <> '$marker')");
        return (float) ($in['q'] ?? 0) - (float) ($out['q'] ?? 0);
    }

    /** Giá đã gồm CPMH (pit) của 1 dòng stock_imports nhập mua = (qty*price + ΣCPMH)/qty. */
    function mic_pit_of_import_row($row)
    {
        $qty   = (float) ($row['quantity']   ?? 0);
        $price = (float) ($row['unit_price'] ?? 0);
        if ($qty <= 0) return $price > 0 ? $price : 0.0;
        $sum = 0.0;
        $sid = (int) ($row['id'] ?? 0);
        if ($sid > 0) {
            foreach (db_fetch_array("SELECT price FROM stock_import_purchase_costs
                                     WHERE stock_import_id = $sid") ?: [] as $c) {
                $sum += (float) $c['price'];
            }
        }
        return ($qty * $price + $sum) / $qty;
    }

    /**
     * Phiếu nhập MUA (row_material_receiving) gần nhất với created_at <= $as_of_dt.
     * Trả row {id, quantity, unit_price, created_at} hoặc null.
     */
    function mic_latest_priced_receipt($material_id, $as_of_dt)
    {
        $mid = (int) $material_id;
        $a   = mic_dt($as_of_dt);
        if ($mid <= 0) return null;
        $cond = $a !== null ? "AND created_at <= '" . escape_string($a) . "'" : '';
        return db_fetch_row("SELECT id, quantity, unit_price, created_at FROM stock_imports
                             WHERE material_id = $mid AND type_import = 'row_material_receiving'
                               $cond
                             ORDER BY created_at DESC, id DESC LIMIT 1") ?: null;
    }

    /**
     * pit của phiếu nhập mua gần nhất STRICTLY TRƯỚC $before_dt (= giá lô cũ price_old).
     * Trả float hoặc null nếu không có lô trước.
     */
    function mic_receipt_pit_before($material_id, $before_dt)
    {
        $mid = (int) $material_id;
        $b   = mic_dt($before_dt);
        if ($mid <= 0 || $b === null) return null;
        $bs  = escape_string($b);
        $row = db_fetch_row("SELECT id, quantity, unit_price, created_at FROM stock_imports
                             WHERE material_id = $mid AND type_import = 'row_material_receiving'
                               AND created_at < '$bs'
                             ORDER BY created_at DESC, id DESC LIMIT 1");
        if (!$row) return null;
        $pit = mic_pit_of_import_row($row);
        return $pit > 0 ? $pit : null;
    }

    /** Giá hiệu lực fallback (khi chưa từng có phiếu mua): incl>0 ? incl : purchase_price. */
    function mic_effective_price($material_id)
    {
        $mid = (int) $material_id;
        if ($mid <= 0) return 0.0;
        $p = db_fetch_row("SELECT purchase_price, purchase_price_includes_purchase_cost AS incl
                           FROM material_purchase_prices
                           WHERE material_id = $mid
                           ORDER BY last_updated_at DESC, id DESC LIMIT 1");
        if (!$p) return 0.0;
        $incl = $p['incl'];
        return ($incl !== null && (float) $incl > 0) ? (float) $incl : (float) $p['purchase_price'];
    }

    /**
     * Tính giá vốn xuất $qty đơn vị NVL tại thời điểm $as_of_dt theo mô hình 2 lớp.
     * Trả mảng:
     *   total      : tổng giá vốn (đã tách lớp)
     *   unit_price : giá bình quân = total/qty (khi tách 2 lớp đây là blended)
     *   mi_ago     : tồn trước phiếu nhập gần nhất (>=0)
     *   price_old, price_new
     *   warn       : true nếu qty > mi_ago (cần báo user phần vượt tính giá mới)
     *   q_old, q_new : số lượng từng lớp
     *   has_receipt: có phiếu mua làm mốc hay không (false → fallback 1 giá)
     */
    function mic_compute_issue_cost($material_id, $qty, $as_of_dt)
    {
        $mid = (int) $material_id;
        $q   = (float) $qty;
        if ($q < 0) $q = 0.0;

        $R = $mid > 0 ? mic_latest_priced_receipt($mid, $as_of_dt) : null;

        // Không có phiếu mua làm mốc → định giá toàn bộ theo giá hiệu lực hiện hành.
        if (!$R) {
            $price = mic_effective_price($mid);
            return [
                'total'       => $q * $price,
                'unit_price'  => $price,
                'mi_ago'      => null,
                'price_old'   => $price,
                'price_new'   => $price,
                'q_old'       => $q,
                'q_new'       => 0.0,
                'warn'        => false,
                'has_receipt' => false,
            ];
        }

        $price_new  = mic_pit_of_import_row($R);
        $recent_qty = max(0.0, (float) ($R['quantity'] ?? 0)); // 'Lần nhập gần đây'

        // 'Tồn trước xuất' = tồn NVL ngay TRƯỚC phiếu xuất đang tính (truy ngược tới $as_of;
        // phiếu xuất đang tính chưa ghi / đã ghi đều bị loại vì điều kiện created_at < as_of).
        // Lớp CŨ (còn lại của lần nhập TRƯỚC ĐÓ) = tồn trước xuất − lần nhập gần đây.
        $as_of_norm = mic_dt($as_of_dt);
        if ($as_of_norm === null) $as_of_norm = date('Y-m-d H:i:s');
        $inv_before_issue = max(0.0, mic_inventory_before($mid, $as_of_norm));
        $mi_ago = max(0.0, $inv_before_issue - $recent_qty);   // q lớp cũ còn trong kho

        $price_old = mic_receipt_pit_before($mid, $R['created_at']);
        if ($price_old === null) $price_old = $price_new; // không có lô cũ → coi như giá mới

        $eps       = 1e-6;
        $samePrice = abs($price_old - $price_new) <= 1e-4;     // giá 1 = giá 2 → không cần tách

        // q nằm trọn trong lớp cũ → 1 giá (price_old).
        if ($q <= $mi_ago + $eps) {
            $total = $price_old * $q;
            return [
                'total'       => $total,
                'unit_price'  => $q > 0 ? $total / $q : $price_old,
                'mi_ago'      => $mi_ago,
                'price_old'   => $price_old,
                'price_new'   => $price_new,
                'q_old'       => $q,
                'q_new'       => 0.0,
                'warn'        => false,
                'has_receipt' => true,
            ];
        }

        // q vượt lớp cũ → tách: q_old @ price_old (lần nhập trước) + q_new @ price_new (gần đây).
        $q_old = $mi_ago;
        $q_new = $q - $mi_ago;
        $total = $q_old * $price_old + $q_new * $price_new;
        return [
            'total'       => $total,
            'unit_price'  => $q > 0 ? $total / $q : $price_new,
            'mi_ago'      => $mi_ago,
            'price_old'   => $price_old,
            'price_new'   => $price_new,
            'q_old'       => $q_old,
            'q_new'       => $q_new,
            // chỉ cảnh báo khi THỰC tách: có đủ cả 2 lớp và 2 lô khác giá.
            'warn'        => ($q_old > $eps && $q_new > $eps && !$samePrice),
            'has_receipt' => true,
        ];
    }

    /* ============================================================
     *  RECOST (Phần 2) — sau khi GIÁ/SỐ LƯỢNG 1 phiếu nhập đổi, tính lại giá vốn
     *  của MỌI phiếu xuất dùng sản xuất (export_production) của NVL đó từ $from_dt
     *  trở đi và ghi đè đồng bộ các bảng phái sinh.
     * ============================================================ */

    /**
     * @param int    $material_id
     * @param string $from_dt  Mốc bắt đầu recost (= min(ngày nhập cũ, ngày nhập mới)).
     * @return int   Số dòng stock_exports đã cập nhật.
     */
    function mic_recost_material_issues($material_id, $from_dt)
    {
        $mid = (int) $material_id;
        $f   = mic_dt($from_dt);
        if ($mid <= 0 || $f === null) return 0;
        $fs  = escape_string($f);

        // Các phiếu xuất SX của NVL này từ mốc recost trở đi.
        $rows = db_fetch_array(
            "SELECT id, product_id, quantity, created_at,
                    DATE_FORMAT(created_at, '%Y-%m-%d %H:%i:%s') AS gk
             FROM stock_exports
             WHERE material_id = $mid AND type_export = 'export_production'
               AND created_at >= '$fs'
             ORDER BY created_at ASC, id ASC"
        ) ?: [];
        if (empty($rows)) return 0;

        $pr_ids_affected = [];  // production_receipt id -> true
        $batch_keys      = [];  // group_key -> true

        foreach ($rows as $r) {
            $eid       = (int) $r['id'];
            $pid       = (int) $r['product_id'];
            $qty       = (float) $r['quantity'];
            $gk        = (string) $r['gk'];
            $batch_keys[$gk] = true;

            $res       = mic_compute_issue_cost($mid, $qty, $r['created_at']);
            $new_total = (float) $res['total'];
            $new_unit  = $qty > 0 ? $new_total / $qty : 0.0;

            // 1) stock_exports (ledger giá vốn)
            db_update('stock_exports',
                ['unit_price' => $new_unit, 'total_amount' => $new_total],
                "id = $eid");

            // 2) production_materials (qua production_receipts của batch + product)
            $gks = escape_string($gk);
            $pr  = db_fetch_row("SELECT id FROM production_receipts
                                 WHERE product_id = $pid
                                   AND DATE_FORMAT(created_at, '%Y-%m-%d %H:%i:%s') = '$gks'
                                 ORDER BY id ASC LIMIT 1");
            if ($pr) {
                $pr_id = (int) $pr['id'];
                $pr_ids_affected[$pr_id] = true;
                db_update('production_materials',
                    ['total_cost' => $new_total],
                    "production_receipt_id = $pr_id AND material_id = $mid");
            }

            // 3) raw_material_production_issue_data (per material/product/ngày)
            $d_only = mic_date_only($r['created_at']);
            if ($d_only !== null) {
                $ds = escape_string($d_only);
                db_update('raw_material_production_issue_data',
                    ['unit_price' => $new_unit, 'amount' => $new_total],
                    "material_id = $mid AND product_id = $pid AND created_at = '$ds'");
            }
        }

        // 4) production_receipts.total_cost = Σ production_materials.total_cost (re-sum).
        foreach (array_keys($pr_ids_affected) as $pr_id) {
            $sum = db_fetch_row("SELECT COALESCE(SUM(total_cost),0) AS s
                                 FROM production_materials WHERE production_receipt_id = $pr_id");
            db_update('production_receipts',
                ['total_cost' => (float) ($sum['s'] ?? 0)],
                "id = " . (int) $pr_id);
        }

        // 5) Tổng hợp lại từng batch: production_costs_daily + transactions + fpp.
        foreach (array_keys($batch_keys) as $gk) {
            mic_resync_investment_batch($gk);
        }

        return count($rows);
    }

    /**
     * Đồng bộ lại các tổng hợp của 1 batch investment_production sau khi total_cost
     * của các production_receipts trong batch đã đổi:
     *   - production_costs_daily.cost_price = Σ production_receipts.total_cost
     *   - transactions ('production'): xoá + ghi lại cặp default Dr155/Cr152 amount = grand_cost
     *   - finished_product_production_data.production_cost (per product/ngày)
     */
    function mic_resync_investment_batch($group_key)
    {
        $gk = trim((string) $group_key);
        if ($gk === '') return;
        $gks = escape_string($gk);

        $pr_rows = db_fetch_array("SELECT id, product_id, total_cost, created_at
                                   FROM production_receipts
                                   WHERE DATE_FORMAT(created_at, '%Y-%m-%d %H:%i:%s') = '$gks'
                                   ORDER BY id ASC") ?: [];
        if (empty($pr_rows)) return;

        $grand_cost = 0.0;
        foreach ($pr_rows as $pr) $grand_cost += (float) $pr['total_cost'];
        $first_pr_id = (int) $pr_rows[0]['id'];
        $created_at  = (string) $pr_rows[0]['created_at'];

        // production_costs_daily theo stock_imports_id đầu tiên của batch.
        $first_si = db_fetch_row("SELECT id FROM stock_imports
                                  WHERE DATE_FORMAT(created_at, '%Y-%m-%d %H:%i:%s') = '$gks'
                                    AND type_import = 'investment_production'
                                  ORDER BY id ASC LIMIT 1");
        if ($first_si) {
            $first_si_id = (int) $first_si['id'];
            db_update('production_costs_daily',
                ['cost_price' => $grand_cost],
                "stock_imports_id = $first_si_id");
        }

        // transactions: xoá cặp cũ của batch (mọi reference_id = pr id của batch) rồi ghi lại
        // cặp default Dr155 / Cr152 amount = grand_cost (theo đúng flow record/edit investment).
        $pr_ids = array_map(function ($p) { return (int) $p['id']; }, $pr_rows);
        $pr_csv = implode(',', $pr_ids);
        db_query("DELETE FROM transactions
                  WHERE reference_type = 'production' AND reference_id IN ($pr_csv)");
        if ($grand_cost > 0) {
            je_insert_pairs('production', $first_pr_id,
                [['debit' => '155', 'credit' => '152', 'amount' => $grand_cost]],
                $created_at);
        }

        // finished_product_production_data.production_cost per (product, ngày).
        $d_only = mic_date_only($created_at);
        if ($d_only !== null) {
            $ds = escape_string($d_only);
            foreach ($pr_rows as $pr) {
                $pid = (int) $pr['product_id'];
                db_update('finished_product_production_data',
                    ['production_cost' => (float) $pr['total_cost']],
                    "product_id = $pid AND created_at = '$ds'");
            }
        }
    }
}
