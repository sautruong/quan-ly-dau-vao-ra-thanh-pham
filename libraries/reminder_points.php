<?php
/**
 * reminder_points — "Điểm nhắc" trên app-header (cạnh nút lịch #app-cal).
 *
 * 3 loại nhắc độc lập:
 *   1. pre_input  — nhắc trước nhập sản xuất (per-product, 1 dòng/sản phẩm, bảng riêng).
 *   2. pre_plan   — nhắc trước lập KHSX (bảng product_reminders_pre_plan riêng, KHÔNG
 *                   unique theo product_id — 1 sản phẩm có thể có nhiều lời nhắc, hiện
 *                   trên production_plan.php theo thứ tự mới→cũ, sửa/xóa từng dòng).
 *   3. pickup     — nhắc trước bốc hàng, theo chi nhánh (branch_pickup_reminders) hoặc
 *                   theo sản phẩm (product_pickup_reminders); mỗi dòng có thể áp dụng
 *                   1 chi nhánh/sản phẩm cụ thể hoặc TẤT CẢ (cột *_id = NULL).
 *
 * Không autoload (giống notifications.php/todos.php/evcal.php) — require_once nơi dùng.
 *
 * Lưu ý: các hàm rp_*_pre_production_note() thao tác bảng pre_production_notes CŨ —
 * đó là tính năng "Lưu ý" tự do gốc của plan_for_staff.php (khác với loại 2 ở trên),
 * giữ lại nguyên vẹn cho tính năng đó, KHÔNG liên quan tới "Điểm nhắc" pre_plan.
 */

if (!function_exists('rp_ensure_tables')) {

    function rp_ensure_tables()
    {
        static $done = false;
        if ($done) return;
        $done = true;

        db_query("CREATE TABLE IF NOT EXISTS product_reminders_pre_input (
            id          INT(11) NOT NULL AUTO_INCREMENT,
            product_id  INT(11) NOT NULL,
            note        TEXT NOT NULL,
            created_at  DATETIME NOT NULL,
            updated_at  DATETIME NOT NULL,
            PRIMARY KEY (id),
            UNIQUE KEY uq_product (product_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci");

        db_query("CREATE TABLE IF NOT EXISTS product_reminders_pre_plan (
            id          INT(11) NOT NULL AUTO_INCREMENT,
            product_id  INT(11) NOT NULL,
            note        TEXT NOT NULL,
            created_at  DATETIME NOT NULL,
            updated_at  DATETIME NOT NULL,
            PRIMARY KEY (id),
            KEY idx_product (product_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci");

        db_query("CREATE TABLE IF NOT EXISTS branch_pickup_reminders (
            id          INT(11) NOT NULL AUTO_INCREMENT,
            customer_id INT(11) DEFAULT NULL,
            note        TEXT NOT NULL,
            created_at  DATETIME NOT NULL,
            updated_at  DATETIME NOT NULL,
            PRIMARY KEY (id),
            KEY idx_customer (customer_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci");

        db_query("CREATE TABLE IF NOT EXISTS product_pickup_reminders (
            id          INT(11) NOT NULL AUTO_INCREMENT,
            product_id  INT(11) DEFAULT NULL,
            note        TEXT NOT NULL,
            created_at  DATETIME NOT NULL,
            updated_at  DATETIME NOT NULL,
            PRIMARY KEY (id),
            KEY idx_product (product_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci");
    }

    /**
     * true nếu icon "Điểm nhắc" nên hiện cho $user — admin hoặc được admin tick quyền trực
     * tiếp qua màn "Phân quyền người dùng" (nhóm "TIỆN ÍCH HEADER", xem
     * helper/permission.php::permission_header_widget_visible()). Trước đây suy luận gián
     * tiếp từ 3 view khác — nay tách hẳn thành quyền độc lập, gán trực tiếp.
     */
    function reminder_points_visible_for($user)
    {
        if (!function_exists('permission_header_widget_visible')) return false;
        return permission_header_widget_visible($user, 'reminder_points');
    }

    /* ================= Loại 1: nhắc trước nhập sản xuất ================= */

    function rp_get_pre_input_note($product_id)
    {
        $pid = (int) $product_id;
        if ($pid <= 0) return '';
        $row = db_fetch_row("SELECT note FROM product_reminders_pre_input WHERE product_id = $pid LIMIT 1");
        return $row ? (string) $row['note'] : '';
    }

    /** Upsert; xóa dòng nếu note rỗng (giữ hành vi giống save_noteAction cũ). */
    function rp_save_pre_input_note($product_id, $note)
    {
        $pid  = (int) $product_id;
        $note = trim((string) $note);
        if ($pid <= 0) return false;

        $existing = db_fetch_row("SELECT id FROM product_reminders_pre_input WHERE product_id = $pid LIMIT 1");
        if ($note === '') {
            if ($existing) db_delete('product_reminders_pre_input', "product_id = $pid");
            return true;
        }
        $now = date('Y-m-d H:i:s');
        if ($existing) {
            db_update('product_reminders_pre_input', ['note' => $note, 'updated_at' => $now], "product_id = $pid");
        } else {
            db_insert('product_reminders_pre_input', [
                'product_id' => $pid,
                'note'       => $note,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }
        return true;
    }

    function rp_delete_pre_input_note($product_id)
    {
        $pid = (int) $product_id;
        if ($pid <= 0) return false;
        db_delete('product_reminders_pre_input', "product_id = $pid");
        return true;
    }

    /** Danh sách nhắc đã thiết lập (cho panel widget). */
    function rp_list_pre_input_notes()
    {
        return db_fetch_array(
            "SELECT r.id, r.product_id, r.note, p.product_name
             FROM product_reminders_pre_input r
             LEFT JOIN products p ON p.id = r.product_id
             ORDER BY r.updated_at DESC"
        ) ?: [];
    }

    /* ================= Loại 2: nhắc trước lập KHSX (nhiều dòng/sản phẩm) ================= */

    /** Thêm 1 lời nhắc MỚI cho sản phẩm (không ghi đè — 1 sản phẩm có thể có nhiều lời nhắc). */
    function rp_add_pre_plan_reminder($product_id, $note)
    {
        $pid  = (int) $product_id;
        $note = trim((string) $note);
        if ($pid <= 0 || $note === '') return 0;
        $now = date('Y-m-d H:i:s');
        return (int) db_insert('product_reminders_pre_plan', [
            'product_id' => $pid, 'note' => $note, 'created_at' => $now, 'updated_at' => $now,
        ]);
    }

    /** Sửa nội dung 1 lời nhắc theo id; note rỗng => xóa dòng. */
    function rp_update_pre_plan_reminder($id, $note)
    {
        $id   = (int) $id;
        $note = trim((string) $note);
        if ($id <= 0) return false;
        if ($note === '') {
            db_delete('product_reminders_pre_plan', "id = $id");
            return true;
        }
        db_update('product_reminders_pre_plan', ['note' => $note, 'updated_at' => date('Y-m-d H:i:s')], "id = $id");
        return true;
    }

    function rp_delete_pre_plan_reminder($id)
    {
        $id = (int) $id;
        if ($id <= 0) return false;
        db_delete('product_reminders_pre_plan', "id = $id");
        return true;
    }

    /** Danh sách toàn bộ lời nhắc KHSX (cho panel widget quản lý), mới nhất trước. */
    function rp_list_pre_plan_reminders()
    {
        return db_fetch_array(
            "SELECT r.id, r.product_id, r.note, p.product_name
             FROM product_reminders_pre_plan r
             LEFT JOIN products p ON p.id = r.product_id
             ORDER BY r.id DESC"
        ) ?: [];
    }

    /** Lời nhắc của 1 sản phẩm (cho production_plan.php), mới nhất trước. */
    function rp_get_pre_plan_reminders_for_product($product_id)
    {
        $pid = (int) $product_id;
        if ($pid <= 0) return [];
        return db_fetch_array(
            "SELECT id, note FROM product_reminders_pre_plan
             WHERE product_id = $pid ORDER BY id DESC"
        ) ?: [];
    }

    /* ================= Legacy: "Lưu ý" tự do gốc của plan_for_staff.php (KHÔNG phải Điểm nhắc) ================= */

    function rp_get_pre_production_note($product_id)
    {
        $pid = (int) $product_id;
        if ($pid <= 0) return '';
        $row = db_fetch_row("SELECT note FROM pre_production_notes WHERE product_id = $pid LIMIT 1");
        return $row ? (string) $row['note'] : '';
    }

    /** Upsert; xóa dòng nếu note rỗng — logic giữ nguyên như save_noteAction gốc. */
    function rp_save_pre_production_note($product_id, $note)
    {
        $pid  = (int) $product_id;
        $note = trim((string) $note);
        if ($pid <= 0) return false;

        $existing = db_fetch_row("SELECT id FROM pre_production_notes WHERE product_id = $pid LIMIT 1");
        if ($note === '') {
            if ($existing) db_delete('pre_production_notes', "product_id = $pid");
            return true;
        }
        if ($existing) {
            db_update('pre_production_notes', ['note' => $note], "product_id = $pid");
        } else {
            db_insert('pre_production_notes', ['product_id' => $pid, 'note' => $note]);
        }
        return true;
    }

    function rp_delete_pre_production_note($product_id)
    {
        $pid = (int) $product_id;
        if ($pid <= 0) return false;
        db_delete('pre_production_notes', "product_id = $pid");
        return true;
    }

    function rp_list_pre_production_notes()
    {
        return db_fetch_array(
            "SELECT n.id, n.product_id, n.note, p.product_name
             FROM pre_production_notes n
             LEFT JOIN products p ON p.id = n.product_id
             WHERE TRIM(n.note) <> ''
             ORDER BY n.id DESC"
        ) ?: [];
    }

    /* ================= Loại 3: nhắc trước bốc hàng ================= */

    /** $customer_ids rỗng => 1 dòng áp dụng TẤT CẢ chi nhánh (customer_id NULL). */
    function rp_add_branch_reminder($note, array $customer_ids = [])
    {
        $note = trim((string) $note);
        if ($note === '') return [];
        $now = date('Y-m-d H:i:s');
        $ids = [];
        if (empty($customer_ids)) {
            $ids[] = db_insert('branch_pickup_reminders', [
                'customer_id' => null, 'note' => $note, 'created_at' => $now, 'updated_at' => $now,
            ]);
        } else {
            foreach ($customer_ids as $cid) {
                $cid = (int) $cid;
                if ($cid <= 0) continue;
                $ids[] = db_insert('branch_pickup_reminders', [
                    'customer_id' => $cid, 'note' => $note, 'created_at' => $now, 'updated_at' => $now,
                ]);
            }
        }
        return $ids;
    }

    function rp_update_branch_reminder($id, $note)
    {
        $id   = (int) $id;
        $note = trim((string) $note);
        if ($id <= 0) return false;
        if ($note === '') {
            db_delete('branch_pickup_reminders', "id = $id");
            return true;
        }
        db_update('branch_pickup_reminders', ['note' => $note, 'updated_at' => date('Y-m-d H:i:s')], "id = $id");
        return true;
    }

    function rp_delete_branch_reminder($id)
    {
        $id = (int) $id;
        if ($id <= 0) return false;
        db_delete('branch_pickup_reminders', "id = $id");
        return true;
    }

    function rp_list_branch_reminders()
    {
        return db_fetch_array(
            "SELECT r.id, r.customer_id, r.note, c.name AS customer_name, c.short_name AS customer_short_name,
                    c.secondary_color AS customer_secondary_color
             FROM branch_pickup_reminders r
             LEFT JOIN customers c ON c.id = r.customer_id
             ORDER BY r.updated_at DESC"
        ) ?: [];
    }

    /** Các nhắc áp dụng cho 1 chi nhánh cụ thể: riêng của chi nhánh đó + các dòng "ALL". */
    function rp_get_branch_reminders_for_customer($customer_id)
    {
        $cid = (int) $customer_id;
        if ($cid <= 0) return [];
        return db_fetch_array(
            "SELECT id, note, customer_id FROM branch_pickup_reminders
             WHERE customer_id = $cid OR customer_id IS NULL
             ORDER BY (customer_id IS NULL) ASC, updated_at DESC"
        ) ?: [];
    }

    /** $product_ids rỗng => 1 dòng áp dụng TẤT CẢ sản phẩm (product_id NULL). */
    function rp_add_product_pickup_reminder($note, array $product_ids = [])
    {
        $note = trim((string) $note);
        if ($note === '') return [];
        $now = date('Y-m-d H:i:s');
        $ids = [];
        if (empty($product_ids)) {
            $ids[] = db_insert('product_pickup_reminders', [
                'product_id' => null, 'note' => $note, 'created_at' => $now, 'updated_at' => $now,
            ]);
        } else {
            foreach ($product_ids as $pid) {
                $pid = (int) $pid;
                if ($pid <= 0) continue;
                $ids[] = db_insert('product_pickup_reminders', [
                    'product_id' => $pid, 'note' => $note, 'created_at' => $now, 'updated_at' => $now,
                ]);
            }
        }
        return $ids;
    }

    function rp_update_product_pickup_reminder($id, $note)
    {
        $id   = (int) $id;
        $note = trim((string) $note);
        if ($id <= 0) return false;
        if ($note === '') {
            db_delete('product_pickup_reminders', "id = $id");
            return true;
        }
        db_update('product_pickup_reminders', ['note' => $note, 'updated_at' => date('Y-m-d H:i:s')], "id = $id");
        return true;
    }

    function rp_delete_product_pickup_reminder($id)
    {
        $id = (int) $id;
        if ($id <= 0) return false;
        db_delete('product_pickup_reminders', "id = $id");
        return true;
    }

    function rp_list_product_pickup_reminders()
    {
        return db_fetch_array(
            "SELECT r.id, r.product_id, r.note, p.product_name
             FROM product_pickup_reminders r
             LEFT JOIN products p ON p.id = r.product_id
             ORDER BY r.updated_at DESC"
        ) ?: [];
    }

    /** Nhắc áp dụng cho 1 sản phẩm khi soạn hàng: ưu tiên nhắc riêng, rồi tới nhắc "ALL". */
    function rp_get_product_pickup_note($product_id)
    {
        $pid = (int) $product_id;
        if ($pid <= 0) return '';
        $row = db_fetch_row(
            "SELECT note FROM product_pickup_reminders
             WHERE product_id = $pid OR product_id IS NULL
             ORDER BY (product_id IS NULL) ASC, updated_at DESC LIMIT 1"
        );
        return $row ? (string) $row['note'] : '';
    }

    /* ================= Tìm kiếm dùng chung cho panel widget ================= */

    function rp_search_products($keyword)
    {
        $keyword = trim((string) $keyword);
        if ($keyword === '') return [];
        $kw = escape_string($keyword);
        return db_fetch_array(
            "SELECT id, product_name FROM products
             WHERE product_name LIKE '%$kw%'
             ORDER BY product_name ASC LIMIT 15"
        ) ?: [];
    }

    function rp_search_customers($keyword)
    {
        $keyword = trim((string) $keyword);
        if ($keyword === '') return [];
        $kw = escape_string($keyword);
        return db_fetch_array(
            "SELECT id, name, short_name, secondary_color FROM customers
             WHERE name LIKE '%$kw%' OR short_name LIKE '%$kw%'
             ORDER BY name ASC LIMIT 15"
        ) ?: [];
    }
}
