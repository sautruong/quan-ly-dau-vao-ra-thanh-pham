<?php
/**
 * material_check — ĐỐI CHIẾU TỒN NGUYÊN VẬT LIỆU cho 1 sản phẩm theo SL kế hoạch (KH hằng ngày).
 *
 * User chốt 2026-11-09: dựa vào id sản phẩm, TRUY VẤN NGƯỢC lần sản xuất GẦN NHẤT xem dùng NVL gì,
 * chia theo sản lượng lần đó ra ĐỊNH MỨC/1 ĐƠN VỊ, rồi nhân với SL kế hoạch hiện tại, đem so với
 * tồn NVL để kết luận đủ/thiếu.
 *
 *   định mức/đv = Σ(NVL xuất lần SX gần nhất) ÷ sản lượng lần đó
 *   cần         = định mức/đv × SL kế hoạch
 *   đối chiếu   = tồn hiện có − cần   (âm = thiếu)
 *
 * Nguồn: raw_material_production_issue_data (NVL xuất cho SX) + finished_product_production_data
 * (sản lượng lần đó) + material_inventory (tồn) + material_information (tên/đơn vị). Prefix matchk_.
 */

if (!function_exists('matchk_compare')) {

    /**
     * Trả:
     *   ['ok'=>true,'last_date'=>'Y-m-d','output_qty'=>float,'plan_qty'=>float,'short_count'=>int,
     *    'rows'=>[['material_id','name','unit','need','stock','diff','short'],...]]
     *   hoặc ['ok'=>false,'reason'=>string].
     */
    function matchk_compare($product_id, $plan_qty)
    {
        $pid = (int) $product_id;
        $pq  = (float) $plan_qty;
        if ($pid <= 0) return ['ok' => false, 'reason' => 'Thiếu sản phẩm.'];
        if ($pq <= 0)  return ['ok' => false, 'reason' => 'Chưa có số lượng kế hoạch.'];

        // Ngày của LẦN SẢN XUẤT GẦN NHẤT (theo ngày nghiệp vụ created_at).
        $row = db_fetch_row("SELECT DATE(MAX(created_at)) d FROM raw_material_production_issue_data WHERE product_id = $pid");
        $d = $row ? (string) $row['d'] : '';
        if ($d === '' || $d === '0000-00-00') {
            return ['ok' => false, 'reason' => 'Chưa có dữ liệu sản xuất để tính định mức.'];
        }
        $esc = escape_string($d);

        // Sản lượng lần đó (để chia ra định mức/đơn vị).
        $out = db_fetch_row("SELECT quantity FROM finished_product_production_data
                             WHERE product_id = $pid AND DATE(created_at) = '$esc'
                             ORDER BY id DESC LIMIT 1");
        $output = $out ? (float) $out['quantity'] : 0.0;
        if ($output <= 0) {
            return ['ok' => false, 'reason' => 'Lần SX gần nhất (' . date('d/m/Y', strtotime($d)) . ') chưa có sản lượng để chia định mức.', 'last_date' => $d];
        }

        // NVL đã dùng lần đó + tên/đơn vị + tồn hiện có.
        $mats = db_fetch_array(
            "SELECT r.material_id,
                    SUM(r.quantity) AS used_qty,
                    COALESCE(NULLIF(m.common_material_name, ''), m.material_name) AS name,
                    m.unit AS unit,
                    (SELECT quantity FROM material_inventory WHERE material_id = r.material_id LIMIT 1) AS stock
             FROM raw_material_production_issue_data r
             LEFT JOIN material_information m ON m.id = r.material_id
             WHERE r.product_id = $pid AND DATE(r.created_at) = '$esc'
             GROUP BY r.material_id"
        ) ?: [];

        $rows = [];
        $short = 0;
        foreach ($mats as $mt) {
            $used  = (float) $mt['used_qty'];
            $per   = $used / $output;             // định mức / 1 đơn vị
            $need  = $per * $pq;                   // cần cho SL kế hoạch
            $stock = ($mt['stock'] !== null) ? (float) $mt['stock'] : 0.0;
            $diff  = $stock - $need;
            $isShort = $diff < -0.0005;
            if ($isShort) $short++;
            $rows[] = [
                'material_id' => (int) $mt['material_id'],
                'name'        => (string) ($mt['name'] ?: ('#' . $mt['material_id'])),
                'unit'        => (string) ($mt['unit'] ?? ''),
                'need'        => round($need, 3),
                'stock'       => round($stock, 3),
                'diff'        => round($diff, 3),
                'short'       => $isShort,
            ];
        }

        return [
            'ok'          => true,
            'last_date'   => $d,
            'output_qty'  => $output,
            'plan_qty'    => $pq,
            'short_count' => $short,
            'rows'        => $rows,
        ];
    }
}
