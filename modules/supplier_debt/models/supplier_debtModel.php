<?php
defined('APPPATH') OR exit('Không được quyền truy cập phần này');

/* =====================================================================
 *  MODULE: Công nợ nhà cung cấp — view "Lịch sử thanh toán" (prefix sd_)
 *  ---------------------------------------------------------------------
 *  - supplier_payments            : 1 dòng = 1 phiếu chi cho NCC.
 *  - supplier_payment_attachments : ảnh/file đính kèm của 1 phiếu chi.
 *  - suppliers.address            : địa chỉ NCC (tự thêm cột nếu thiếu).
 *
 *  Lịch sử "các lần mua" của 1 NCC = tổng hợp stock_import_invoices
 *  (dùng chung cho cả mua NVL 'row_material_receiving' và mua thành phẩm
 *  'fg_receipt_purchase' — phân biệt bằng stock_imports.type_import).
 * =====================================================================*/

require_once __DIR__ . '/../../../libraries/print_settings.php';

/* ============================================================
 *  Bảng + cột — tự tạo / tự thêm (idempotent, gọi ở construct)
 * ============================================================ */

function sd_ensure_schema()
{
    static $done = false;
    if ($done) return;
    $done = true;

    db_query("CREATE TABLE IF NOT EXISTS supplier_payments (
        id          INT(11)        NOT NULL AUTO_INCREMENT,
        supplier_id INT(11)        DEFAULT NULL,
        amount      DECIMAL(15, 2) NOT NULL DEFAULT 0,
        note        VARCHAR(255)   DEFAULT NULL,
        created_at  TIMESTAMP      NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (id),
        KEY idx_supplier_payments_supplier (supplier_id),
        KEY idx_supplier_payments_created_at (created_at)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    db_query("CREATE TABLE IF NOT EXISTS supplier_payment_attachments (
        id         INT(11)      NOT NULL AUTO_INCREMENT,
        payment_id INT(11)      NOT NULL,
        file_url   VARCHAR(255) NOT NULL,
        created_at TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (id),
        KEY idx_spa_payment (payment_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    // suppliers.address — thêm nếu thiếu.
    $col = db_fetch_row("SELECT COLUMN_NAME FROM information_schema.COLUMNS
                         WHERE TABLE_SCHEMA = DATABASE()
                           AND TABLE_NAME = 'suppliers'
                           AND COLUMN_NAME = 'address' LIMIT 1");
    if (!$col) {
        db_query("ALTER TABLE suppliers ADD COLUMN address VARCHAR(255) DEFAULT NULL");
    }
}

/* ============================================================
 *  Helpers chung
 * ============================================================ */

function sd_normalize_datetime($s)
{
    $s = trim((string) $s);
    if ($s === '') return null;
    $ts = strtotime($s);
    return $ts ? date('Y-m-d H:i:s', $ts) : null;
}

/** Danh sách per_page hợp lệ. */
function sd_per_page($n)
{
    $n = (int) $n;
    return in_array($n, [5, 10, 20, 50, 100], true) ? $n : 5;
}

/* ============================================================
 *  Print settings — thông tin cố định trên phiếu chi (dùng chung)
 * ============================================================ */

function sd_get_print_settings()             { return print_settings_get(); }
function sd_save_print_setting($key, $value) { return print_settings_save($key, $value); }

/* ============================================================
 *  Vùng ký PHIẾU CHI — chức danh phía công ty (Giám đốc, Kế toán...):
 *  ĐỘNG (user tự thêm/sửa/xóa/kéo sắp xếp) nên lưu riêng ở app_settings
 *  prefix 'supplier_debt.' (không đi qua whitelist cố định của
 *  print_settings.php). "Người nhận tiền" KHÔNG nằm trong danh sách này —
 *  luôn là 1 dòng ký cố định, để trống tên (NCC tự ký tay).
 * ============================================================ */

function sd_get_setting($key, $default = '')
{
    print_settings_ensure_table();
    $k = escape_string('supplier_debt.' . (string) $key);
    $row = db_fetch_row("SELECT setting_value FROM app_settings WHERE setting_key = '$k' LIMIT 1");
    if (!$row || $row['setting_value'] === null) return $default;
    return $row['setting_value'];
}

function sd_set_setting($key, $value)
{
    print_settings_ensure_table();
    $full = 'supplier_debt.' . (string) $key;
    $fk = escape_string($full);
    $exists = db_num_rows("SELECT 1 FROM app_settings WHERE setting_key = '$fk'") > 0;
    if ($exists) {
        db_update('app_settings', ['setting_value' => (string) $value], "setting_key = '$fk'");
    } else {
        db_insert('app_settings', ['setting_key' => $full, 'setting_value' => (string) $value]);
    }
    return true;
}

/** Danh sách chức danh khả dụng + cụm ký đang hiển thị (phía công ty) trên PHIẾU CHI. */
function sd_get_sign_config()
{
    $roles_raw = sd_get_setting('sign_roles', '');
    $roles = $roles_raw !== '' ? json_decode($roles_raw, true) : null;
    if (!is_array($roles) || empty($roles)) {
        $roles = ['Giám đốc', 'Kế toán trưởng', 'Người lập phiếu'];
    }

    $signs_raw = sd_get_setting('signs', '');
    $signs = $signs_raw !== '' ? json_decode($signs_raw, true) : null;
    if (!is_array($signs)) {
        // Seed lần đầu từ 3 người ký cũ (tương thích ngược với print_settings).
        $ps = print_settings_get();
        $signs = [
            ['role' => 'Giám đốc',        'name' => $ps['signer_director']   ?? ''],
            ['role' => 'Kế toán trưởng',  'name' => $ps['signer_accountant'] ?? ''],
            ['role' => 'Người lập phiếu', 'name' => $ps['signer_preparer']   ?? ''],
        ];
    }

    return [
        'sign_roles' => array_values(array_filter(array_map('strval', $roles), function ($r) { return $r !== ''; })),
        'signs'      => $signs,
    ];
}

/** Lưu 'sign_roles' hoặc 'signs' (JSON) — whitelist 2 key này. */
function sd_save_sign_setting($key, $value)
{
    if (!in_array($key, ['sign_roles', 'signs'], true)) return false;
    return sd_set_setting($key, $value);
}

/* ============================================================
 *  Nhà cung cấp
 * ============================================================ */

function sd_search_suppliers($keyword)
{
    $kw = trim((string) $keyword);
    if ($kw === '') return [];
    $k = escape_string($kw);
    return db_fetch_array("SELECT id, supplier_name, address, phone_number
                           FROM suppliers
                           WHERE supplier_name LIKE '%$k%'
                           ORDER BY supplier_name ASC
                           LIMIT 15") ?: [];
}

function sd_get_supplier($supplier_id)
{
    $sid = (int) $supplier_id;
    if ($sid <= 0) return null;
    return db_fetch_row("SELECT id, supplier_name, address, phone_number
                         FROM suppliers WHERE id = $sid LIMIT 1") ?: null;
}

/** Lưu địa chỉ NCC (sửa tại chỗ trên phiếu chi → dùng dài lâu). */
function sd_save_supplier_address($supplier_id, $address)
{
    $sid = (int) $supplier_id;
    if ($sid <= 0) return false;
    db_update('suppliers', ['address' => trim((string) $address)], "id = $sid");
    return true;
}

/* ============================================================
 *  Phiếu chi (supplier_payments) — CRUD
 * ============================================================ */

function sd_format_payment_row($r)
{
    $ts = strtotime($r['created_at'] ?? '');
    return [
        'id'            => (int) $r['id'],
        'supplier_id'   => $r['supplier_id'] !== null ? (int) $r['supplier_id'] : 0,
        'supplier_name' => $r['supplier_name'] ?? '',
        'amount'        => (float) $r['amount'],
        'note'          => $r['note'] ?? '',
        'created_at'    => $r['created_at'],
        'date_display'  => $ts ? date('d/m/Y H:i', $ts) : '',
        'attachments'   => isset($r['att_count']) ? (int) $r['att_count'] : 0,
    ];
}

/**
 * Dựng mệnh đề WHERE cho lịch sử thanh toán: lọc theo NCC + khoảng ngày.
 * $date_from/$date_to dạng 'YYYY-MM-DD' (đã kiểm định để tránh SQL injection).
 */
function sd_history_where($supplier_id = 0, $date_from = '', $date_to = '')
{
    $sid   = (int) $supplier_id;
    $conds = [];
    if ($sid > 0) $conds[] = "sp.supplier_id = $sid";
    if (preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) $date_from)) {
        $conds[] = "sp.created_at >= '" . $date_from . " 00:00:00'";
    }
    if (preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) $date_to)) {
        $conds[] = "sp.created_at <= '" . $date_to . " 23:59:59'";
    }
    return $conds ? ('WHERE ' . implode(' AND ', $conds)) : '';
}

/** Đếm tổng số phiếu chi (lọc theo NCC + khoảng ngày nếu có). */
function sd_count_history($supplier_id = 0, $date_from = '', $date_to = '')
{
    $cond = sd_history_where($supplier_id, $date_from, $date_to);
    $row  = db_fetch_row("SELECT COUNT(*) AS n FROM supplier_payments sp $cond");
    return $row ? (int) $row['n'] : 0;
}

/**
 * Lấy 1 trang lịch sử thanh toán (mới → cũ).
 * Trả ['rows'=>[...], 'total'=>int, 'page'=>int, 'per_page'=>int].
 */
function sd_get_history($page = 1, $per_page = 5, $supplier_id = 0, $date_from = '', $date_to = '')
{
    $page = max(1, (int) $page);
    $per  = sd_per_page($per_page);
    $sid  = (int) $supplier_id;
    $off  = ($page - 1) * $per;
    $cond = sd_history_where($sid, $date_from, $date_to);

    $rows = db_fetch_array("SELECT sp.id, sp.supplier_id, sp.amount, sp.note, sp.created_at,
                                   s.supplier_name,
                                   (SELECT COUNT(*) FROM supplier_payment_attachments a
                                    WHERE a.payment_id = sp.id) AS att_count
                            FROM supplier_payments sp
                            LEFT JOIN suppliers s ON s.id = sp.supplier_id
                            $cond
                            ORDER BY sp.created_at DESC, sp.id DESC
                            LIMIT $per OFFSET $off") ?: [];

    return [
        'rows'     => array_map('sd_format_payment_row', $rows),
        'total'    => sd_count_history($sid, $date_from, $date_to),
        'page'     => $page,
        'per_page' => $per,
    ];
}

function sd_get_payment($id)
{
    $id = (int) $id;
    if ($id <= 0) return null;
    $r = db_fetch_row("SELECT sp.id, sp.supplier_id, sp.amount, sp.note, sp.created_at,
                              s.supplier_name
                       FROM supplier_payments sp
                       LEFT JOIN suppliers s ON s.id = sp.supplier_id
                       WHERE sp.id = $id LIMIT 1");
    return $r ? sd_format_payment_row($r) : null;
}

/** Validate payload form. Trả null nếu thiếu/không hợp lệ. */
function sd_sanitize_payload($data)
{
    $sid    = (int) ($data['supplier_id'] ?? 0);
    $amount = (float) ($data['amount'] ?? 0);
    if ($sid <= 0 || $amount <= 0) return null;

    $sup  = sd_get_supplier($sid);
    if (!$sup) return null;
    // Diễn giải do người dùng nhập (có thể để trống → không hiển thị trong lịch sử).
    $note = trim((string) ($data['note'] ?? ''));

    return [
        'supplier_id' => $sid,
        'amount'      => $amount,
        'note'        => $note,
    ];
}

/** Ghi 1 phiếu chi mới. Trả id (0 nếu lỗi). */
function sd_record($data)
{
    $row = sd_sanitize_payload($data);
    if ($row === null) return 0;
    $ca = sd_normalize_datetime($data['created_at'] ?? '');
    if ($ca !== null) $row['created_at'] = $ca;
    return (int) db_insert('supplier_payments', $row);
}

/** Cập nhật 1 phiếu chi. */
function sd_update($id, $data)
{
    $id = (int) $id;
    if ($id <= 0 || !sd_get_payment($id)) return false;
    $row = sd_sanitize_payload($data);
    if ($row === null) return false;
    $ca = sd_normalize_datetime($data['created_at'] ?? '');
    if ($ca !== null) $row['created_at'] = $ca;
    db_update('supplier_payments', $row, "id = $id");
    return true;
}

/** Xóa 1 phiếu chi + toàn bộ file đính kèm. */
function sd_delete($id)
{
    $id = (int) $id;
    if ($id <= 0) return false;
    sd_delete_attachments_for_payment($id);
    db_delete('supplier_payments', "id = $id");
    return true;
}

/* ============================================================
 *  Đính kèm (ảnh/file) — staging upload sau khi Tạo phiếu chi
 * ============================================================ */

if (!defined('SD_ALLOWED_EXT')) define('SD_ALLOWED_EXT', 'jpg,jpeg,png,gif,webp,pdf');

function sd_storage_dir()
{
    $rel = 'public' . DIRECTORY_SEPARATOR . 'uploads' . DIRECTORY_SEPARATOR . 'supplier_payments';
    $abs = APPPATH . DIRECTORY_SEPARATOR . $rel;
    if (!is_dir($abs)) @mkdir($abs, 0777, true);
    return $abs;
}

/** Chuẩn hóa $_FILES['files'] (đơn/nhiều) về list phẳng. */
function sd_normalize_files($files)
{
    $out = [];
    if (!is_array($files) || !isset($files['name'])) return $out;
    if (is_array($files['name'])) {
        $n = count($files['name']);
        for ($i = 0; $i < $n; $i++) {
            $out[] = [
                'name'     => $files['name'][$i] ?? '',
                'type'     => $files['type'][$i] ?? '',
                'tmp_name' => $files['tmp_name'][$i] ?? '',
                'error'    => $files['error'][$i] ?? UPLOAD_ERR_NO_FILE,
                'size'     => $files['size'][$i] ?? 0,
            ];
        }
    } else {
        $out[] = $files;
    }
    return $out;
}

/** Lưu nhiều file đính kèm cho 1 phiếu chi. */
function sd_save_attachments($payment_id, $files)
{
    $pid = (int) $payment_id;
    if ($pid <= 0 || !sd_get_payment($pid)) {
        return ['ok' => false, 'saved' => [], 'errors' => ['Phiếu chi không hợp lệ.']];
    }
    $allowed = explode(',', SD_ALLOWED_EXT);
    $abs_dir = sd_storage_dir();
    $rel_web = 'public/uploads/supplier_payments';

    $saved = [];
    $errors = [];
    foreach (sd_normalize_files($files) as $f) {
        if (($f['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) continue;
        if (($f['error'] ?? 1) !== UPLOAD_ERR_OK) { $errors[] = 'Tải file thất bại: ' . ($f['name'] ?? ''); continue; }
        $ext = strtolower(pathinfo($f['name'], PATHINFO_EXTENSION));
        if (!in_array($ext, $allowed, true)) { $errors[] = 'Định dạng không hợp lệ: ' . $f['name']; continue; }

        $base     = preg_replace('/[^A-Za-z0-9_\-]/', '_', pathinfo($f['name'], PATHINFO_FILENAME));
        $base     = $base !== '' ? $base : 'chi';
        $unique   = $pid . '_' . time() . '_' . substr(md5($f['name'] . mt_rand()), 0, 6);
        $filename = $unique . '_' . $base . '.' . $ext;
        $abs_path = $abs_dir . DIRECTORY_SEPARATOR . $filename;

        $moved = is_uploaded_file($f['tmp_name'])
            ? move_uploaded_file($f['tmp_name'], $abs_path)
            : @rename($f['tmp_name'], $abs_path);
        if (!$moved) { $errors[] = 'Không thể lưu file: ' . $f['name']; continue; }

        $file_url = $rel_web . '/' . $filename;
        $id = (int) db_insert('supplier_payment_attachments', ['payment_id' => $pid, 'file_url' => $file_url]);
        $saved[] = ['id' => $id, 'file_url' => $file_url];
    }
    return ['ok' => empty($errors) || !empty($saved), 'saved' => $saved, 'errors' => $errors];
}

function sd_list_attachments($payment_id)
{
    $pid = (int) $payment_id;
    if ($pid <= 0) return [];
    return db_fetch_array("SELECT id, payment_id, file_url, created_at
                           FROM supplier_payment_attachments
                           WHERE payment_id = $pid ORDER BY id ASC") ?: [];
}

/** Xóa 1 file đính kèm theo id (gỡ file vật lý + dòng DB). */
function sd_delete_attachment($id)
{
    $id = (int) $id;
    if ($id <= 0) return false;
    $row = db_fetch_row("SELECT id, file_url FROM supplier_payment_attachments WHERE id = $id LIMIT 1");
    if (!$row) return false;
    $rel = str_replace(['/', '\\'], DIRECTORY_SEPARATOR, (string) $row['file_url']);
    $abs = APPPATH . DIRECTORY_SEPARATOR . $rel;
    if (is_file($abs) && strpos($rel, 'supplier_payments') !== false) @unlink($abs);
    db_delete('supplier_payment_attachments', "id = $id");
    return true;
}

function sd_delete_attachments_for_payment($payment_id)
{
    $pid = (int) $payment_id;
    if ($pid <= 0) return;
    foreach (sd_list_attachments($pid) as $a) {
        $rel = str_replace(['/', '\\'], DIRECTORY_SEPARATOR, (string) $a['file_url']);
        $abs = APPPATH . DIRECTORY_SEPARATOR . $rel;
        if (is_file($abs) && strpos($rel, 'supplier_payments') !== false) @unlink($abs);
    }
    db_delete('supplier_payment_attachments', "payment_id = $pid");
}

/* ============================================================
 *  Lịch sử "các lần mua" của 1 NCC (modal khi click tên NCC)
 *  Tổng hợp từ stock_import_invoices (mua NVL + mua thành phẩm).
 * ============================================================ */

function sd_purchase_type_label($types)
{
    $t = (string) $types;
    $has_mat = strpos($t, 'row_material_receiving') !== false;
    $has_fg  = strpos($t, 'fg_receipt_purchase') !== false;
    if ($has_mat && $has_fg) return 'Mua NVL & thành phẩm';
    if ($has_mat) return 'Mua nguyên vật liệu';
    if ($has_fg)  return 'Mua thành phẩm';
    return 'Mua hàng';
}

function sd_count_supplier_purchases($supplier_id)
{
    $sid = (int) $supplier_id;
    if ($sid <= 0) return 0;
    $row = db_fetch_row("SELECT COUNT(*) AS n FROM stock_import_invoices WHERE supplier_id = $sid");
    return $row ? (int) $row['n'] : 0;
}

/**
 * 1 trang các lần mua của 1 NCC (gần nhất trước).
 * Trả ['rows'=>[{id,total,purchase_cost,type_label,created_at,date_display}], 'total'=>int, ...].
 */
function sd_get_supplier_purchases($supplier_id, $page = 1, $per_page = 5)
{
    $sid  = (int) $supplier_id;
    $page = max(1, (int) $page);
    $per  = sd_per_page($per_page);
    $off  = ($page - 1) * $per;
    if ($sid <= 0) return ['rows' => [], 'total' => 0, 'page' => $page, 'per_page' => $per];

    $rows = db_fetch_array("SELECT inv.id, inv.inventory_value, inv.purchase_cost, inv.created_at,
                                   (SELECT GROUP_CONCAT(DISTINCT si.type_import)
                                    FROM stock_imports si
                                    WHERE si.import_invoice_id = inv.id) AS types
                            FROM stock_import_invoices inv
                            WHERE inv.supplier_id = $sid
                            ORDER BY inv.created_at DESC, inv.id DESC
                            LIMIT $per OFFSET $off") ?: [];

    $out = [];
    foreach ($rows as $r) {
        $ts = strtotime($r['created_at'] ?? '');
        $out[] = [
            'id'            => (int) $r['id'],
            'total'         => (float) $r['inventory_value'],
            'purchase_cost' => (float) $r['purchase_cost'],
            'type_label'    => sd_purchase_type_label($r['types'] ?? ''),
            'created_at'    => $r['created_at'],
            'date_display'  => $ts ? date('d/m/Y H:i', $ts) : '',
        ];
    }

    return [
        'rows'     => $out,
        'total'    => sd_count_supplier_purchases($sid),
        'page'     => $page,
        'per_page' => $per,
    ];
}
