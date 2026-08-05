<?php
/**
 * investment_auto_cost — GHI GIÁ VỐN TỰ ĐỘNG theo lúc ghi sản lượng.
 *
 * User chốt 5/8/2026: "ở /inventory_management/dashboard sau khi nhập sản phẩm số lượng và ghi
 * thì việc click Ghi cũng đồng thời ghi giá vốn cho list này, thay cho button Ghi thủ công của
 * /inventory_management/investment_products. Khi kích hoạt tự động này việc sửa ở view sản lượng
 * cũng đồng bộ với view giá vốn. Ngoài ra có nhận diện sản phẩm chưa có thành phần nào (không
 * tính Chi phí sản xuất) thì vẫn cho ghi giá vốn + đẩy chuông cho admin."
 *
 * Cách tính: user chốt phương án (b) — chạy ĐÚNG giá vốn 2 lớp + luật "lần sản xuất tương đồng
 * ±3%", để số tự động KHỚP với số hiện ra khi mở view giá vốn, vì user còn vào xem lại và sửa.
 *
 * ==============================================================================
 * TÁI TẠO ĐƯỢC ĐẾN ĐÂU — ĐỌC TRƯỚC KHI SỬA
 * ==============================================================================
 * Payload của nút "Ghi" bên investment_products được dựng 100% TỪ DOM tại thời điểm bấm. Bản
 * server ở đây tái tạo đúng TRẠNG THÁI TRANG LÚC MỚI NẠP:
 *
 *   product_qty  = SUM(stock_imports.quantity) type_import='fg_receipt_production' của ngày đó
 *   total_qty    = luật ±3% nếu khớp (im_get_similar_production_issue), ngược lại qr × product_qty
 *   unit_price   = mic_compute_issue_cost(...)['unit_price']   (giá BÌNH QUÂN đã trộn 2 lớp)
 *   total_cost   = mic_compute_issue_cost(...)['total']        (KHOÁ TÊN LÀ 'total', không phải
 *                  'total_cost' — chỉ controller mới đổi tên khi trả JSON)
 *   tổng SP      = round( Σ total_cost NVL  +  chi phí sản xuất )
 *   giá trị      = round( product_qty × product_prices.system_price )
 *
 * KHÔNG tái tạo được (và KHÔNG cần): các con số do người dùng SỬA TAY trên trang — lúc ghi tự
 * động thì chưa ai sửa gì cả. Đó chính là lý do user giữ quyền vào xem lại và chỉnh.
 *
 * "Chi phí sản xuất" KHÔNG phải một NVL: nó là dòng ẢO (material_id = 0) chỉ tồn tại trên giao
 * diện, tiền của nó CÓ cộng vào tổng vốn của sản phẩm nhưng KHÔNG nằm trong materials[]. Bản
 * server phải giữ đúng bất đối xứng đó, nếu không tổng tiền lệch.
 *
 * Prefix iac_*. Bảng tự tạo qua iac_ensure_tables().
 */

if (date_default_timezone_get() !== 'Asia/Ho_Chi_Minh') {
    date_default_timezone_set('Asia/Ho_Chi_Minh');
}

require_once __DIR__ . '/notifications.php';
require_once __DIR__ . '/material_issue_costing.php';

if (!function_exists('iac_ensure_tables')) {

    function iac_ensure_tables()
    {
        static $done = false;
        if ($done) return;
        $done = true;

        db_query("CREATE TABLE IF NOT EXISTS investment_auto_cost_configs (
            id           INT(11) NOT NULL AUTO_INCREMENT,
            /* 0 = tắt: nút Ghi bên investment_products dùng như cũ (thủ công). */
            is_active    TINYINT(1) NOT NULL DEFAULT 0,
            /* 1 = SP chưa có thành phần nào vẫn ghi (chỉ có chi phí sản xuất) + đẩy chuông. */
            warn_no_bom  TINYINT(1) NOT NULL DEFAULT 1,
            created_by   INT(11) NOT NULL DEFAULT 0,
            created_at   DATETIME NOT NULL,
            updated_at   DATETIME NOT NULL,
            deleted_at   DATETIME DEFAULT NULL,
            PRIMARY KEY (id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci");
    }

    function iac_now() { return date('Y-m-d H:i:s'); }

    /* ============================================================
     *  CẤU HÌNH
     * ============================================================ */

    function iac_config()
    {
        iac_ensure_tables();
        $row = db_fetch_row("SELECT * FROM investment_auto_cost_configs WHERE deleted_at IS NULL ORDER BY id ASC LIMIT 1");
        return $row ?: null;
    }

    /** Bật/tắt — hàm rẻ, gọi được ở mọi đường ghi mà không sợ tốn. */
    function iac_is_on()
    {
        $c = iac_config();
        return $c && (int) $c['is_active'] === 1;
    }

    function iac_save($in, $actor_id = 0)
    {
        iac_ensure_tables();
        $data = [
            'is_active'   => !empty($in['is_active']) ? 1 : 0,
            'warn_no_bom' => !empty($in['warn_no_bom']) ? 1 : 0,
            'updated_at'  => iac_now(),
        ];
        $cur = iac_config();
        if ($cur) {
            db_update('investment_auto_cost_configs', $data, 'id = ' . (int) $cur['id']);
            return ['ok' => true, 'id' => (int) $cur['id']];
        }
        $data['created_by'] = (int) $actor_id;
        $data['created_at'] = iac_now();
        $id = (int) db_insert('investment_auto_cost_configs', $data);
        return $id > 0 ? ['ok' => true, 'id' => $id] : ['ok' => false, 'error' => 'Không lưu được cấu hình.'];
    }

    /* ============================================================
     *  DỰNG PAYLOAD GIÁ VỐN CHO 1 NGÀY
     * ============================================================ */

    /**
     * Mốc thời gian dùng để định giá ("as of"). Lấy lần ghi sản lượng MUỘN NHẤT trong ngày —
     * đó là thời điểm nghiệp vụ thực sự xuất NVL. Dùng "bây giờ" sẽ sai khi ghi bù cho ngày cũ:
     * mic_compute_issue_cost tính tồn theo mốc này, lệch mốc là lệch cả lớp giá.
     */
    function iac_as_of_for_date($date)
    {
        $d = escape_string(date('Y-m-d', strtotime($date)));
        $r = db_fetch_row(
            "SELECT MAX(created_at) AS m FROM stock_imports
              WHERE type_import = 'fg_receipt_production' AND DATE(created_at) = '{$d}'"
        );
        return ($r && !empty($r['m'])) ? (string) $r['m'] : ($d . ' 23:59:59');
    }

    /**
     * Dựng items[] đúng shape mà im_record_investment() ăn:
     *   [product_id, product_qty, total_cost, expected_value, materials[{material_id,total_qty,unit_price,total_cost}]]
     *
     * Trả ['items'=>[], 'cost_price'=>float, 'goods_value'=>float, 'no_bom'=>[ '<tên SP>', ... ]].
     */
    function iac_build_items_for_date($date)
    {
        require_once __DIR__ . '/../helper/accounting.php';

        $d      = date('Y-m-d', strtotime($date));
        $d_safe = escape_string($d);
        $as_of  = iac_as_of_for_date($d);

        // Cột cờ được tạo LAZY ở model — gọi trước khi SELECT, nếu không dính Unknown column
        // trên DB chưa từng mở view investment_products.
        if (function_exists('im_ensure_exclude_production_cost_column')) {
            im_ensure_exclude_production_cost_column();
        }

        $rows = db_fetch_array(
            "SELECT product_id, SUM(quantity) AS quantity
               FROM stock_imports
              WHERE type_import = 'fg_receipt_production' AND DATE(created_at) = '{$d_safe}'
              GROUP BY product_id ORDER BY product_id ASC"
        ) ?: [];

        $rate        = production_cost_rate();
        $items       = [];
        $cost_price  = 0.0;
        $goods_value = 0.0;
        $no_bom      = [];

        foreach ($rows as $r) {
            $pid = (int) $r['product_id'];
            $qty = (float) $r['quantity'];
            if ($pid <= 0 || $qty <= 0) continue;

            $p = db_fetch_row("SELECT product_name, exclude_production_cost FROM products WHERE id = {$pid} LIMIT 1");
            if (!$p) continue;

            $pr = db_fetch_row("SELECT system_price FROM product_prices WHERE product_id = {$pid} LIMIT 1");
            $system_price = $pr ? (float) $pr['system_price'] : 0.0;

            // Định mức. LEFT JOIN + material_id > 0 để khớp đúng những gì view giá vốn hiển thị
            // (view dùng LEFT JOIN nên NVL đã xoá vẫn hiện thành '#id').
            $bom = db_fetch_array(
                "SELECT pm.material_id, pm.quantity_required
                   FROM product_materials pm
                  WHERE pm.product_id = {$pid} AND pm.material_id > 0
                  ORDER BY pm.id ASC"
            ) ?: [];

            // LUẬT ±3%: nếu tìm được lần sản xuất trước có sản lượng lệch ≤3%, dùng lại lượng
            // NVL đã xuất của lần đó thay cho định mức — đúng thứ view giá vốn tự làm khi nạp.
            $similar = function_exists('im_get_similar_production_issue')
                ? im_get_similar_production_issue($pid, $qty, $d)
                : ['matched' => false, 'materials' => []];
            $sim_map = [];
            if (!empty($similar['matched'])) {
                foreach ((array) $similar['materials'] as $sm) {
                    $mid = (int) ($sm['material_id'] ?? 0);
                    if ($mid > 0) $sim_map[$mid] = (float) ($sm['total_qty'] ?? 0);
                }
            }

            if (empty($bom)) {
                $no_bom[] = (string) $p['product_name'];
            }

            $materials   = [];
            $mat_cost_sum = 0.0;
            foreach ($bom as $b) {
                $mid = (int) $b['material_id'];
                $total_qty = array_key_exists($mid, $sim_map)
                    ? $sim_map[$mid]
                    : ((float) $b['quantity_required']) * $qty;
                if ($total_qty <= 0) continue;   // im_record_investment cũng bỏ qua dòng này

                // Giá vốn 2 lớp. CHÚ Ý khoá trả về là 'total', KHÔNG phải 'total_cost'.
                $c = mic_compute_issue_cost($mid, $total_qty, $as_of);
                $line_cost  = (float) $c['total'];
                $unit_price = (float) $c['unit_price'];

                $materials[] = [
                    'material_id' => $mid,
                    'total_qty'   => $total_qty,
                    'unit_price'  => $unit_price,
                    'total_cost'  => $line_cost,
                ];
                $mat_cost_sum += $line_cost;
            }

            // Chi phí sản xuất — dòng ẢO: CÓ trong tổng vốn, KHÔNG có trong materials[].
            $prod_cost = ((int) $p['exclude_production_cost'] === 1) ? 0.0 : ($qty * $rate);

            // Làm tròn ở CẤP SẢN PHẨM, đúng như giao diện (ô tổng đi qua formatMoney → số nguyên);
            // tiền từng dòng NVL thì để thô. Tròn ở chỗ khác sẽ lệch vài đồng so với thao tác tay.
            $item_total    = round($mat_cost_sum + $prod_cost);
            $item_expected = round($qty * $system_price);

            $items[] = [
                'product_id'     => $pid,
                'product_qty'    => $qty,
                'total_cost'     => $item_total,
                'expected_value' => $item_expected,
                'materials'      => $materials,
            ];
            $cost_price  += $item_total;
            $goods_value += $item_expected;
        }

        return [
            'items'       => $items,
            'cost_price'  => $cost_price,
            'goods_value' => $goods_value,
            'no_bom'      => $no_bom,
            'as_of'       => $as_of,
        ];
    }

    /* ============================================================
     *  ĐỒNG BỘ 1 NGÀY
     * ============================================================ */

    /**
     * Ghi lại giá vốn của $date từ đầu: xoá batch investment cũ của ngày rồi ghi mới.
     *
     * VÌ SAO XOÁ-RỒI-GHI thay vì cập nhật: sửa ở view sản lượng có thể THÊM/BỚT cả sản phẩm, mà
     * im_update_investment_batch() chỉ vá theo danh sách cũ. Dựng lại từ sản lượng hiện tại của
     * ngày là cách duy nhất luôn ra đúng, và cũng chính là thứ view giá vốn hiển thị.
     *
     * $date KHÔNG còn sản lượng nào (vừa xoá phiếu) → chỉ xoá batch cũ, không ghi gì. Đúng: ngày
     * đó không sản xuất thì không có giá vốn.
     */
    function iac_sync_date($date, $actor_id = 0)
    {
        if (!$date) return ['ok' => false, 'error' => 'Thiếu ngày.'];
        $d = date('Y-m-d', strtotime($date));

        $built = iac_build_items_for_date($d);

        // Xoá batch giá vốn cũ của ngày (có thể nhiều batch nếu trong ngày ghi nhiều lần).
        $olds = db_fetch_array(
            "SELECT DISTINCT DATE_FORMAT(created_at, '%Y-%m-%d %H:%i:%s') AS gk
               FROM stock_imports
              WHERE type_import = 'investment_production'
                AND DATE(created_at) = '" . escape_string($d) . "'"
        ) ?: [];
        foreach ($olds as $o) {
            $gk = trim((string) $o['gk']);
            if ($gk !== '' && function_exists('im_delete_investment_batch')) {
                im_delete_investment_batch($gk);
            }
        }

        if (empty($built['items'])) {
            return ['ok' => true, 'items' => 0, 'cleared' => count($olds)];
        }

        // created_at của batch giá vốn = mốc định giá, để lịch sử 2 view khớp nhau về thời điểm.
        $ok = im_record_investment(
            $built['items'],
            $built['cost_price'],
            $built['goods_value'],
            $built['as_of'],
            null
        );

        if (!empty($built['no_bom'])) {
            iac_warn_no_bom($built['no_bom'], $d);
        }

        return ['ok' => (bool) $ok, 'items' => count($built['items']), 'cleared' => count($olds)];
    }

    /**
     * SP chưa khai định mức nào → VẪN ghi giá vốn (chỉ có chi phí sản xuất) rồi báo chuông admin.
     * User chốt: không chặn, vì chặn thì cả ngày không có giá vốn chỉ vì một sản phẩm thiếu công thức.
     */
    function iac_warn_no_bom($names, $date)
    {
        $c = iac_config();
        if ($c && (int) $c['warn_no_bom'] !== 1) return;

        $list = implode(', ', array_slice($names, 0, 8));
        if (count($names) > 8) $list .= ' … (+' . (count($names) - 8) . ')';

        notify_admins(
            'Có sản phẩm chưa khai công thức nhưng vẫn ghi giá vốn',
            'Ngày ' . date('d/m/Y', strtotime($date)) . ': ' . $list
            . ' — chưa có nguyên vật liệu nào trong công thức, giá vốn chỉ gồm chi phí sản xuất. '
            . 'Vào Công thức sản xuất khai định mức rồi mở lại view Nhập giá vốn để kiểm tra.',
            'inventory_management/investment_products',
            'general'
        );
    }

    /**
     * Đồng bộ NHIỀU ngày một lượt (sửa phiếu có thể dời sang ngày khác → phải tính lại CẢ HAI).
     * Bỏ qua ngày rỗng/trùng.
     */
    function iac_sync_dates(array $dates, $actor_id = 0)
    {
        $seen = [];
        foreach ($dates as $x) {
            $x = trim((string) $x);
            if ($x === '') continue;
            $d = date('Y-m-d', strtotime($x));
            if (isset($seen[$d])) continue;
            $seen[$d] = true;
            iac_sync_date($d, $actor_id);
        }
    }
}
