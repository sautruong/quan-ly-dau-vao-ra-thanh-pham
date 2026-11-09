<?php
/**
 * formula_image — HÌNH CÔNG THỨC MẺ SẢN XUẤT theo (sản phẩm + sản lượng), dùng ở KH hằng ngày.
 *
 * User chốt 2026-11-09:
 *  - Ảnh công thức là ảnh admin TẢI TỪ MÁY (công thức mẻ, có tính lặp lại), gắn theo (product, qty).
 *  - Nếu (product, qty) chưa có ảnh riêng → lấy ảnh của sản lượng LÂN CẬN theo biên ±3%:
 *      ảnh gốc B phủ dải [floor(B×0.97) … ceil(B×1.03)] (vd B=46 phủ 44..48). Chọn B gần qty nhất.
 *  - User thường chỉ XEM được khi admin DUYỆT cho TỪNG kế hoạch (plan_date), không duyệt 1 lần.
 *
 * Prefix fmi_*. Hai bảng tự tạo: product_formula_images + plan_formula_approvals.
 * File upload vào public/uploads/formula_images (thư mục uploads được .gitignore bảo vệ).
 */

if (date_default_timezone_get() !== 'Asia/Ho_Chi_Minh') {
    date_default_timezone_set('Asia/Ho_Chi_Minh');
}

if (!function_exists('fmi_ensure_tables')) {

    function fmi_ensure_tables()
    {
        static $done = false;
        if ($done) return;
        $done = true;

        db_query("CREATE TABLE IF NOT EXISTS product_formula_images (
            id          INT(11) NOT NULL AUTO_INCREMENT,
            product_id  INT(11) NOT NULL,
            quantity    INT(11) NOT NULL,
            image_file  VARCHAR(255) NOT NULL,
            uploaded_by INT(11) NOT NULL DEFAULT 0,
            created_at  DATETIME NOT NULL,
            updated_at  DATETIME NOT NULL,
            PRIMARY KEY (id),
            UNIQUE KEY uq_prod_qty (product_id, quantity),
            KEY idx_prod (product_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci");

        /* Duyệt theo TỪNG kế hoạch: 1 dòng = (plan_date, product) đã được admin duyệt cho NV xem.
           plan_date đổi mỗi lần công bố KH mới → tự nhiên phải duyệt lại. */
        db_query("CREATE TABLE IF NOT EXISTS plan_formula_approvals (
            plan_date   DATE NOT NULL,
            product_id  INT(11) NOT NULL,
            approved_by INT(11) NOT NULL DEFAULT 0,
            approved_at DATETIME NOT NULL,
            PRIMARY KEY (plan_date, product_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci");
    }

    function fmi_now() { return date('Y-m-d H:i:s'); }

    /** Thư mục lưu ảnh (tương đối web root). */
    function fmi_dir_rel() { return 'public/uploads/formula_images'; }

    /* ============================================================
     *  TÌM ẢNH CHO (SẢN PHẨM, SẢN LƯỢNG) — ƯU TIÊN ĐÚNG, SAU ĐÓ LÂN CẬN ±3%
     * ============================================================ */

    /**
     * Trả ['image_file'=>path, 'source_quantity'=>B, 'exact'=>bool] hoặc null nếu không có ảnh phủ.
     */
    function fmi_find_image($product_id, $quantity)
    {
        fmi_ensure_tables();
        $pid = (int) $product_id;
        $q   = (int) round((float) $quantity);
        if ($pid <= 0) return null;

        // 1) Ảnh đúng (product, qty).
        $row = db_fetch_row("SELECT quantity, image_file FROM product_formula_images
                             WHERE product_id = $pid AND quantity = $q LIMIT 1");
        if ($row) {
            return ['image_file' => (string) $row['image_file'], 'source_quantity' => (int) $row['quantity'], 'exact' => true];
        }

        // 2) Ảnh lân cận: ảnh gốc B phủ [floor(B×0.97) … ceil(B×1.03)]; chọn B gần q nhất.
        $rows = db_fetch_array("SELECT quantity, image_file FROM product_formula_images WHERE product_id = $pid") ?: [];
        $best = null; $bestDiff = PHP_INT_MAX;
        foreach ($rows as $r) {
            $B  = (int) $r['quantity'];
            if ($B <= 0) continue;
            $lo = (int) floor($B * 0.97);
            $hi = (int) ceil($B * 1.03);
            if ($q >= $lo && $q <= $hi) {
                $d = abs($B - $q);
                if ($d < $bestDiff) {
                    $bestDiff = $d;
                    $best = ['image_file' => (string) $r['image_file'], 'source_quantity' => $B, 'exact' => false];
                }
            }
        }
        return $best;
    }

    /* ============================================================
     *  LƯU ẢNH (admin tải từ máy)
     * ============================================================ */

    /**
     * Lưu/ghi đè ảnh công thức cho (product, qty). $file = 1 phần tử $_FILES.
     * Trả ['ok'=>bool,'image_file'=>path] | ['ok'=>false,'message'=>...].
     */
    function fmi_save_image($product_id, $quantity, $file, $by = 0)
    {
        fmi_ensure_tables();
        $pid = (int) $product_id;
        $q   = (int) round((float) $quantity);
        if ($pid <= 0 || $q <= 0) return ['ok' => false, 'message' => 'Thiếu sản phẩm hoặc sản lượng.'];
        if (empty($file) || ($file['error'] ?? 1) !== UPLOAD_ERR_OK) {
            return ['ok' => false, 'message' => 'Chưa chọn được ảnh hợp lệ.'];
        }

        $ext = strtolower(pathinfo((string) ($file['name'] ?? ''), PATHINFO_EXTENSION));
        $allow = ['jpg', 'jpeg', 'png', 'webp', 'gif'];
        if (!in_array($ext, $allow, true)) return ['ok' => false, 'message' => 'Chỉ nhận ảnh JPG/PNG/WEBP/GIF.'];
        if (!@getimagesize($file['tmp_name'])) return ['ok' => false, 'message' => 'File không phải ảnh.'];

        $abs_dir = APPPATH . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, fmi_dir_rel());
        if (!is_dir($abs_dir)) @mkdir($abs_dir, 0777, true);

        $filename = 'f' . $pid . '_q' . $q . '_' . time() . '.' . $ext;
        $abs_path = $abs_dir . DIRECTORY_SEPARATOR . $filename;
        if (!move_uploaded_file($file['tmp_name'], $abs_path)) {
            return ['ok' => false, 'message' => 'Không lưu được ảnh lên máy chủ.'];
        }
        $rel = fmi_dir_rel() . '/' . $filename;

        $old = db_fetch_row("SELECT id, image_file FROM product_formula_images
                             WHERE product_id = $pid AND quantity = $q LIMIT 1");
        $now = fmi_now();
        if ($old) {
            // Xoá file cũ cho gọn (nếu còn).
            $oldAbs = APPPATH . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, (string) $old['image_file']);
            if (is_file($oldAbs) && strpos($oldAbs, 'formula_images') !== false) @unlink($oldAbs);
            db_update('product_formula_images',
                ['image_file' => $rel, 'uploaded_by' => (int) $by, 'updated_at' => $now],
                'id = ' . (int) $old['id']);
        } else {
            db_insert('product_formula_images', [
                'product_id' => $pid, 'quantity' => $q, 'image_file' => $rel,
                'uploaded_by' => (int) $by, 'created_at' => $now, 'updated_at' => $now,
            ]);
        }
        return ['ok' => true, 'image_file' => $rel];
    }

    /* ============================================================
     *  DUYỆT THEO TỪNG KẾ HOẠCH
     * ============================================================ */

    function fmi_is_approved($plan_date, $product_id)
    {
        fmi_ensure_tables();
        $d = date('Y-m-d', strtotime((string) $plan_date));
        $pid = (int) $product_id;
        if ($pid <= 0) return false;
        return db_num_rows("SELECT 1 FROM plan_formula_approvals
                            WHERE plan_date = '" . escape_string($d) . "' AND product_id = $pid LIMIT 1") > 0;
    }

    /** Bật/tắt duyệt. $on=true → duyệt, false → gỡ duyệt. */
    function fmi_set_approved($plan_date, $product_id, $by, $on)
    {
        fmi_ensure_tables();
        $d = date('Y-m-d', strtotime((string) $plan_date));
        $pid = (int) $product_id;
        if ($pid <= 0) return false;
        if ($on) {
            db_query("INSERT INTO plan_formula_approvals (plan_date, product_id, approved_by, approved_at)
                      VALUES ('" . escape_string($d) . "', $pid, " . (int) $by . ", '" . fmi_now() . "')
                      ON DUPLICATE KEY UPDATE approved_by = VALUES(approved_by), approved_at = VALUES(approved_at)");
        } else {
            db_delete('plan_formula_approvals', "plan_date = '" . escape_string($d) . "' AND product_id = $pid");
        }
        return true;
    }

    /** Mảng product_id đã được duyệt cho 1 plan_date (để view render nhanh, 1 truy vấn). */
    function fmi_approved_pids($plan_date)
    {
        fmi_ensure_tables();
        $d = date('Y-m-d', strtotime((string) $plan_date));
        $rows = db_fetch_array("SELECT product_id FROM plan_formula_approvals
                                WHERE plan_date = '" . escape_string($d) . "'") ?: [];
        return array_map(static fn($r) => (int) $r['product_id'], $rows);
    }
}
