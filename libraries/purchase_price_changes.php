<?php
/**
 * purchase_price_changes — helper dùng chung cho 2 luồng:
 *   - inventory_management / product_buy        (thành phẩm mua hàng, material_id = NULL)
 *   - inventory_receiving  / row_material_receiving (NVL mua, product_id = NULL)
 *
 * Bảng purchase_price_changes (xem migration_purchase_price_changes.sql):
 *   id, product_id, material_id, old_price, new_price, change_rate, created_at
 *
 * Mỗi lần Ghi/Sửa: với từng dòng tính new_price = total / quantity, đối chiếu với
 * giá "đã gồm chi phí mua hàng" của lần nhập gần nhất trước đó (old_price).
 * Nếu khác nhau (và có old_price > 0) → ghi 1 dòng biến động + đẩy vào buffer để
 * controller trả về cho client hiển thị modal.
 *
 * File này được require_once từ cả 2 model nên mọi hàm đều có guard function_exists.
 */

if (!function_exists('ppc_ensure_table')) {

    /** Tạo bảng purchase_price_changes nếu chưa có (chạy 1 lần / request). */
    function ppc_ensure_table()
    {
        static $done = false;
        if ($done) return;
        $done = true;
        db_query("CREATE TABLE IF NOT EXISTS purchase_price_changes (
            id INT(11) NOT NULL AUTO_INCREMENT,
            product_id INT(11) DEFAULT NULL,
            material_id INT(11) DEFAULT NULL,
            old_price DECIMAL(15,2) NOT NULL DEFAULT 0,
            new_price DECIMAL(15,2) NOT NULL DEFAULT 0,
            change_rate DECIMAL(12,4) NOT NULL DEFAULT 0,
            created_at TIMESTAMP NOT NULL DEFAULT current_timestamp(),
            PRIMARY KEY (id),
            KEY idx_ppc_product (product_id),
            KEY idx_ppc_material (material_id),
            KEY idx_ppc_created (created_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci");
    }

    /** Buffer (by-ref) chứa các biến động của lần Ghi/Sửa hiện tại — để trả ra modal. */
    function &ppc_buffer()
    {
        static $buf = [];
        return $buf;
    }

    /** Reset buffer trước khi bắt đầu 1 thao tác Ghi/Sửa. */
    function ppc_reset()
    {
        $buf = &ppc_buffer();
        $buf = [];
    }

    /** Lấy + xoá buffer (gọi sau khi record/edit xong để trả JSON). */
    function ppc_take()
    {
        $buf = &ppc_buffer();
        $out = $buf;
        $buf = [];
        return $out;
    }

    /**
     * So sánh old/new; nếu khác nhau → INSERT 1 dòng purchase_price_changes + push buffer.
     * - $product_id / $material_id: 1 trong 2 là id, cái còn lại null.
     * - $old: giá gồm CPMH của lần gần nhất trước đó (null/0 = không có baseline → bỏ qua).
     * - $new: giá mới = total / quantity.
     * - $created_at: theo #record-datetime (null → DB default NOW()).
     * - $label: tên thành phẩm / NVL để hiển thị modal.
     * Trả change_rate (%) nếu có ghi, ngược lại null.
     */
    function ppc_record($product_id, $material_id, $old, $new, $created_at, $label)
    {
        if ($old === null || $old === '') return null;       // không có baseline
        $old = (float) $old;
        $new = (float) $new;
        if ($old <= 0) return null;                          // không tính được tỷ lệ
        if (abs($new - $old) <= 0.005) return null;          // không đổi

        $rate = (($new - $old) / $old) * 100;

        ppc_ensure_table();
        $data = [
            'product_id'  => $product_id !== null ? (int) $product_id : null,
            'material_id' => $material_id !== null ? (int) $material_id : null,
            'old_price'   => $old,
            'new_price'   => $new,
            'change_rate' => $rate,
        ];
        if ($created_at !== null && $created_at !== '') $data['created_at'] = $created_at;
        db_insert('purchase_price_changes', $data);

        $buf = &ppc_buffer();
        $buf[] = [
            'name'        => (string) $label,
            'product_id'  => $product_id !== null ? (int) $product_id : null,
            'material_id' => $material_id !== null ? (int) $material_id : null,
            'old_price'   => $old,
            'new_price'   => $new,
            'change_rate' => round($rate, 2),
        ];
        return $rate;
    }

    /**
     * Xoá các dòng biến động của 1 batch (theo created_at = batch datetime) cho danh sách
     * product hoặc material — dùng khi Sửa (xoá rồi ghi lại) hoặc Xóa batch.
     * $field = 'product_id' | 'material_id'.
     */
    function ppc_delete_for_batch($field, $ids, $created_at)
    {
        $field = ($field === 'material_id') ? 'material_id' : 'product_id';
        $ids = array_values(array_unique(array_filter(array_map('intval', (array) $ids))));
        if (empty($ids) || $created_at === null || $created_at === '') return;
        ppc_ensure_table();
        $in  = implode(',', $ids);
        $key = escape_string((string) $created_at);
        db_query("DELETE FROM purchase_price_changes
                  WHERE $field IN ($in)
                    AND DATE_FORMAT(created_at, '%Y-%m-%d %H:%i:%s') = '$key'");
    }
}
