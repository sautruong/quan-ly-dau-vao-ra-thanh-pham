<?php
/**
 * =====================================================================
 *  QUY CÁCH BAO BÌ NGOÀI — đồng bộ "chữ" <-> "bảng cấu trúc"
 * =====================================================================
 *  HAI nơi cùng giữ quy cách bao bì ngoài của 1 sản phẩm:
 *   - product_info_basic.outer_packaging_spec        : CHỮ TỰ DO người dùng gõ, vd "20 gói/thùng"
 *                                                      (view /admin_factory/manage_product_list,
 *                                                      hồ sơ sản phẩm, kế hoạch SX... đều ghi vào đây)
 *   - outer_packaging_specifications (name, quantity): bảng CẤU TRÚC — là nơi DUY NHẤT mà logic quy
 *                                                      đổi kiện (Soạn hàng, phiếu soạn A4, lưới tồn
 *                                                      nhà máy, báo cáo...) đọc: 40 gói / 20 = "2T".
 *
 *  BẪY ĐÃ DÍNH (9/11/2026): không có dòng code nào đẩy chữ sang bảng cấu trúc, nên SP nào được gõ
 *  quy cách sau khi bảng cấu trúc được nạp lần đầu thì Soạn hàng KHÔNG quy đổi (vd "Trà Đen Ceylon
 *  Espresso 500g" 20 gói/thùng, đặt 20 gói vẫn hiện "20 gói" thay vì "1T").
 *
 *  Cách chữa:
 *   1. pack_sync_outer_spec($product_id) — gọi NGAY SAU mỗi lần ghi chữ quy cách: chữ hiểu được thì
 *      bảng cấu trúc đổi theo (chữ là thứ người dùng sửa nên chữ thắng).
 *   2. pack_heal_outer_specs()           — lưới an toàn, chạy tối đa 1 lần/request ở các hàm ĐỌC bảng
 *      cấu trúc: chỉ ĐIỀN chỗ còn trống (chưa có dòng / quantity = 0), KHÔNG ghi đè số đã có. Nhờ đó
 *      dữ liệu cũ tự lành sau khi git pull, không cần chạy SQL tay, và nơi ghi nào quên gọi (1) vẫn
 *      được vá ở lần đọc kế tiếp.
 *
 *  Chữ KHÔNG hiểu được (rỗng, "12 chai/lít", "thùng 20"...) -> KHÔNG đụng vào bảng cấu trúc, để không
 *  xoá nhầm những SP chỉ có số ở bảng cấu trúc mà ô chữ để trống.
 * =====================================================================
 */

/**
 * "20 gói/thùng" -> ['qty' => 20, 'name' => 'Thùng'].  Không hiểu được -> null.
 * Quy tắc: số đứng đầu = số đơn vị nhỏ / 1 bao bì; chữ sau dấu "/" quyết định loại:
 * chứa "thùng"/"thung" -> 'Thùng', chứa "bao" -> 'Bao' (khớp om_kien_letter() -> T / B).
 * Loại khác (vd "lít") bị bỏ qua vì không có ký hiệu kiện tương ứng.
 */
function pack_parse_outer_spec($text)
{
    $t = trim((string) $text);
    if ($t === '') return null;
    if (!preg_match('~^\s*(\d+)\s*[^\d/]*/\s*(.+?)\s*$~u', $t, $m)) return null;
    $qty = (int) $m[1];
    if ($qty <= 0) return null;

    $pack = mb_strtolower($m[2], 'UTF-8');
    if (mb_strpos($pack, 'thùng') !== false || mb_strpos($pack, 'thung') !== false) {
        $name = 'Thùng';
    } elseif (mb_strpos($pack, 'bao') !== false) {
        $name = 'Bao';
    } else {
        return null;
    }
    return ['qty' => $qty, 'name' => $name];
}

/** Ghi $parsed vào bảng cấu trúc cho 1 SP (cập nhật dòng có sẵn, chưa có thì thêm). Trả true nếu đã khớp. */
function pack_upsert_outer_row($product_id, array $parsed)
{
    $pid = (int) $product_id;
    if ($pid <= 0) return false;
    $ex = db_fetch_row(
        "SELECT id, outer_packaging_short_name AS n, quantity AS q
         FROM outer_packaging_specifications WHERE product_id = $pid ORDER BY id ASC LIMIT 1"
    );
    if ($ex) {
        if ((int) $ex['q'] === (int) $parsed['qty'] && (string) $ex['n'] === (string) $parsed['name']) {
            return true;   // đã đúng
        }
        db_update('outer_packaging_specifications',
            ['outer_packaging_short_name' => $parsed['name'], 'quantity' => (int) $parsed['qty']],
            'id = ' . (int) $ex['id']);
        return true;
    }
    return (bool) db_insert('outer_packaging_specifications', [
        'product_id'                 => $pid,
        'outer_packaging_short_name' => $parsed['name'],
        'quantity'                   => (int) $parsed['qty'],
    ]);
}

/**
 * GỌI NGAY SAU KHI GHI product_info_basic.outer_packaging_spec của 1 SP.
 * Chữ hiểu được -> bảng cấu trúc đổi theo. Chữ rỗng/không hiểu -> giữ nguyên bảng cấu trúc.
 * Không bao giờ ném lỗi ra ngoài (lưu quy cách không được hỏng chỉ vì bước đồng bộ).
 */
function pack_sync_outer_spec($product_id)
{
    $pid = (int) $product_id;
    if ($pid <= 0) return false;
    try {
        $row = db_fetch_row("SELECT outer_packaging_spec FROM product_info_basic WHERE product_id = $pid LIMIT 1");
        $parsed = pack_parse_outer_spec($row['outer_packaging_spec'] ?? '');
        if ($parsed === null) return false;
        return pack_upsert_outer_row($pid, $parsed);
    } catch (Throwable $e) {
        return false;
    }
}

/**
 * Lưới an toàn: ĐIỀN quy cách vào bảng cấu trúc cho mọi SP đã có chữ nhưng bảng cấu trúc còn trống
 * (chưa có dòng, hoặc quantity = 0). KHÔNG ghi đè dòng đã có số. Tối đa 1 lần/request.
 * Gọi ở đầu các hàm đọc bảng outer_packaging_specifications.
 */
function pack_heal_outer_specs()
{
    static $done = false;
    if ($done) return;
    $done = true;
    try {
        $rows = db_fetch_array(
            "SELECT pib.product_id, pib.outer_packaging_spec AS spec
             FROM product_info_basic pib
             INNER JOIN products p ON p.id = pib.product_id
             LEFT JOIN outer_packaging_specifications ops ON ops.product_id = pib.product_id
             WHERE TRIM(COALESCE(pib.outer_packaging_spec, '')) <> ''
               AND COALESCE(ops.quantity, 0) = 0"
        ) ?: [];
        foreach ($rows as $r) {
            $parsed = pack_parse_outer_spec($r['spec']);
            if ($parsed !== null) pack_upsert_outer_row((int) $r['product_id'], $parsed);
        }
    } catch (Throwable $e) {
        // Lưới an toàn thôi — không để làm hỏng trang đang đọc.
    }
}
