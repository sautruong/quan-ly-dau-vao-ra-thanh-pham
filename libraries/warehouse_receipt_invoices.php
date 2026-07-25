<?php

/**
 * warehouse_receipt_invoices — thư viện dùng chung lưu ảnh/file hóa đơn.
 *
 * Bảng: warehouse_receipt_invoices (id, wr_id, type, file_url, created_at).
 *   - type = 'purchase_invoice'     -> wr_id = warehouse_receipts.id              (hóa đơn mua)
 *   - type = 'sales_invoice'        -> wr_id = sales_orders.id                    (hóa đơn bán - lịch sử)
 *   - type = 'sales_export_invoice' -> wr_id = sales_warehouse_export_invoices.id (hóa đơn bán - phiếu xuất kho mới)
 *
 * File vật lý copy vào: public/uploads/receipt_invoices/<type>/
 * file_url lưu dạng web-relative: 'public/uploads/receipt_invoices/<type>/<file>'
 * (dùng trực tiếp làm href/src vì app phục vụ từ thư mục gốc nvsxvat.vn).
 *
 * Yêu cầu: libraries/database.php đã load (db_insert/db_fetch_array/...).
 */

if (!defined('WRI_ALLOWED_EXT')) {
    define('WRI_ALLOWED_EXT', 'jpg,jpeg,png,gif,webp,pdf');
}

/** Trả các type hợp lệ. */
function wri_valid_types()
{
    return ['purchase_invoice', 'sales_invoice', 'sales_export_invoice'];
}

/** Thư mục lưu file vật lý (tuyệt đối) cho 1 type; tự tạo nếu chưa có. */
function wri_storage_dir($type)
{
    $type   = in_array($type, wri_valid_types(), true) ? $type : 'misc';
    $rel    = 'public' . DIRECTORY_SEPARATOR . 'uploads' . DIRECTORY_SEPARATOR
            . 'receipt_invoices' . DIRECTORY_SEPARATOR . $type;
    $abs    = APPPATH . DIRECTORY_SEPARATOR . $rel;
    if (!is_dir($abs)) {
        @mkdir($abs, 0777, true);
    }
    return $abs;
}

/**
 * Chuẩn hóa $_FILES['xxx'] (đơn hoặc nhiều file) về list các entry phẳng:
 *   [ ['name','type','tmp_name','error','size'], ... ]
 */
function wri_normalize_files($files)
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

/**
 * Lưu nhiều file hóa đơn cho 1 (wr_id, type).
 * $files: mảng $_FILES['files'] (đã hoặc chưa chuẩn hóa đều nhận được).
 * Trả: ['ok'=>bool, 'saved'=>[{id,file_url,created_at}], 'errors'=>[...]].
 */
function wri_save_uploaded_files($wr_id, $type, $files)
{
    $wr_id = (int) $wr_id;
    if ($wr_id <= 0 || !in_array($type, wri_valid_types(), true)) {
        return ['ok' => false, 'saved' => [], 'errors' => ['Tham số không hợp lệ.']];
    }

    $allowed = explode(',', WRI_ALLOWED_EXT);
    $abs_dir = wri_storage_dir($type);
    $rel_web = 'public/uploads/receipt_invoices/' . $type;

    $saved  = [];
    $errors = [];
    foreach (wri_normalize_files($files) as $f) {
        if (($f['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) continue;
        if (($f['error'] ?? 1) !== UPLOAD_ERR_OK) {
            $errors[] = 'Tải file thất bại: ' . ($f['name'] ?? '');
            continue;
        }
        $ext = strtolower(pathinfo($f['name'], PATHINFO_EXTENSION));
        if (!in_array($ext, $allowed, true)) {
            $errors[] = 'Định dạng không hợp lệ: ' . $f['name'];
            continue;
        }

        $base     = preg_replace('/[^A-Za-z0-9_\-]/', '_', pathinfo($f['name'], PATHINFO_FILENAME));
        $base     = $base !== '' ? $base : 'invoice';
        $unique   = $wr_id . '_' . time() . '_' . substr(md5($f['name'] . mt_rand()), 0, 6);
        $filename = $unique . '_' . $base . '.' . $ext;
        $abs_path = $abs_dir . DIRECTORY_SEPARATOR . $filename;

        $moved = is_uploaded_file($f['tmp_name'])
            ? move_uploaded_file($f['tmp_name'], $abs_path)
            : @rename($f['tmp_name'], $abs_path);
        if (!$moved) {
            $errors[] = 'Không thể lưu file: ' . $f['name'];
            continue;
        }

        $file_url = $rel_web . '/' . $filename;
        $id = (int) db_insert('warehouse_receipt_invoices', [
            'wr_id'    => $wr_id,
            'type'     => $type,
            'file_url' => $file_url,
        ]);
        $saved[] = ['id' => $id, 'file_url' => $file_url];
    }

    return ['ok' => empty($errors) || !empty($saved), 'saved' => $saved, 'errors' => $errors];
}

/** Danh sách hóa đơn của 1 (wr_id, type), cũ -> mới. */
function wri_list($wr_id, $type)
{
    $wr_id = (int) $wr_id;
    if ($wr_id <= 0 || !in_array($type, wri_valid_types(), true)) return [];
    $t = escape_string($type);
    return db_fetch_array(
        "SELECT id, wr_id, type, file_url, created_at
         FROM warehouse_receipt_invoices
         WHERE wr_id = $wr_id AND type = '$t'
         ORDER BY id ASC"
    ) ?: [];
}

/** Lấy 1 dòng hóa đơn theo id. */
function wri_get($id)
{
    $id = (int) $id;
    if ($id <= 0) return null;
    return db_fetch_row(
        "SELECT id, wr_id, type, file_url, created_at
         FROM warehouse_receipt_invoices WHERE id = $id LIMIT 1"
    ) ?: null;
}

/** Xóa 1 hóa đơn: gỡ file vật lý (nếu trong uploads) + xóa dòng DB. */
function wri_delete($id)
{
    $row = wri_get($id);
    if (!$row) return false;

    $rel = str_replace(['/', '\\'], DIRECTORY_SEPARATOR, (string) $row['file_url']);
    $abs = APPPATH . DIRECTORY_SEPARATOR . $rel;
    if (is_file($abs) && strpos($rel, 'receipt_invoices') !== false) {
        @unlink($abs);
    }
    db_query("DELETE FROM warehouse_receipt_invoices WHERE id = " . (int) $row['id']);
    return true;
}

/** Tìm warehouse_receipts.id theo import_invoice_id (stock_import_invoices.id). */
function wri_wr_id_by_invoice($import_invoice_id)
{
    $iid = (int) $import_invoice_id;
    if ($iid <= 0) return 0;
    $row = db_fetch_row("SELECT id FROM warehouse_receipts WHERE import_invoice_id = $iid ORDER BY id DESC LIMIT 1");
    return $row ? (int) $row['id'] : 0;
}
