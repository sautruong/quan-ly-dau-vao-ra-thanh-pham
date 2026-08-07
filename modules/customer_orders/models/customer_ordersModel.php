<?php
defined('APPPATH') OR exit('Không được quyền truy cập phần này');

/**
 * =====================================================================================
 *  QUẢN LÝ ĐƠN HÀNG — model  (prefix co_)
 * -------------------------------------------------------------------------------------
 *  View "Đơn hàng" (/customer_orders/orders) là bản dành cho KHÁCH của view "Đơn bán hàng"
 *  (/admin_factory/sales_orders): cùng nguồn dữ liệu, cùng kho hóa đơn, nhưng bị giới hạn
 *  theo đúng khách hàng gắn với tài khoản đang đăng nhập.
 *
 *  TÊN MODULE: thư mục là customer_orders chứ không phải order_management vì
 *  modules/order_management/ ĐÃ TỒN TẠI (branch_orders, picking_slip, prefix om_*).
 *  Nhóm menu hiển thị vẫn là "QUẢN LÝ ĐƠN HÀNG".
 *
 *  ĐỒNG BỘ HÓA ĐƠN 2 CHIỀU với Đơn bán hàng là MIỄN PHÍ, không có bảng trung gian và
 *  không có code sync: cả 2 view đọc/ghi cùng bảng warehouse_receipt_invoices theo cặp
 *  (type, wr_id) và cùng thư mục public/uploads/receipt_invoices/<type>/.
 *
 *  LUẬT SỐNG CÒN: sales_orders.id và sales_warehouse_export_invoices.id CÓ THỂ TRÙNG NHAU.
 *  Mọi thao tác tra/ghi/xóa phải dùng CẶP (inv_type, id), tuyệt đối không dùng id đơn lẻ.
 * =====================================================================================
 */

require_once APPPATH . DIRECTORY_SEPARATOR . 'libraries' . DIRECTORY_SEPARATOR . 'warehouse_receipt_invoices.php';

/* =====================================================================
 *  KHỞI TẠO
 * =====================================================================*/

/**
 * Bảo đảm schema phụ trợ.
 * Phải tự tạo CẢ 2 cột định danh của tbl_users chứ không ỷ vào trang quản lý user: nếu một
 * khách vào view này TRƯỚC khi admin từng mở /admin_factory/manage_user_list thì cột chưa tồn
 * tại, permission_current_user() không có customer_id, và ai cũng rơi vào mode 'none' — trang
 * trắng trơn mà không có lỗi nào để lần ra.
 */
function co_ensure_tables()
{
    wri_ensure_source_columns();

    static $userDone = false;
    if ($userDone) return;
    $userDone = true;
    if (!db_fetch_row("SHOW COLUMNS FROM tbl_users LIKE 'user_kind'")) {
        db_query("ALTER TABLE tbl_users ADD user_kind VARCHAR(20) NOT NULL DEFAULT ''");
    }
    if (!db_fetch_row("SHOW COLUMNS FROM tbl_users LIKE 'customer_id'")) {
        db_query("ALTER TABLE tbl_users ADD customer_id INT(11) NOT NULL DEFAULT 0");
        db_query("ALTER TABLE tbl_users ADD INDEX idx_users_customer (customer_id)");
    }
}

/** Đăng ký view vào danh mục phân quyền (INSERT IGNORE nên chạy lại vô hại). */
function co_ensure_view_registered()
{
    if (db_num_rows("SHOW TABLES LIKE 'tbl_views'") <= 0) return;
    // Cột `controller` PHẢI bằng đúng tiền tố tên file customer_ordersController.php.
    // Sai một chữ -> permission_find_view() trả null -> guard FAIL-OPEN (ai cũng vào được)
    // và header mất tiêu đề trang.
    db_query(
        "INSERT IGNORE INTO tbl_views (module, controller, action, label, group_label, sort)
         VALUES ('customer_orders', 'customer_orders', 'orders', 'Đơn hàng', 'QUẢN LÝ ĐƠN HÀNG', 150)"
    );
}

/* =====================================================================
 *  PHẠM VI XEM — nguồn sự thật duy nhất
 * =====================================================================*/

/**
 * Tài khoản đang đăng nhập được xem gì.
 * CHỈ đọc từ session/DB, KHÔNG nhận tham số từ client.
 *   mode = 'admin'    -> xem tất cả, có bộ lọc khách hàng
 *   mode = 'customer' -> chỉ đơn của customer_id đó
 *   mode = 'none'     -> không thấy gì (fail-closed)
 */
function co_scope()
{
    static $cache = null;
    if ($cache !== null) return $cache;

    $u = permission_current_user();
    if (!$u) return $cache = ['mode' => 'none', 'customer_id' => 0, 'user_id' => 0];

    $uid = (int) ($u['id'] ?? 0);
    if (permission_is_admin($u)) return $cache = ['mode' => 'admin', 'customer_id' => 0, 'user_id' => $uid];

    /* permission_current_user() cache dòng user NGAY TRONG permission_guard(), tức TRƯỚC khi
       co_ensure_tables() kịp thêm cột. Lần chạy đầu sau khi cập nhật mã, dòng cache đó chưa có
       2 khoá này -> mọi khách rơi vào 'none' và trang trống mà không có lỗi nào để lần ra.
       Thiếu khoá thì đọc lại thẳng từ DB (1 truy vấn, chỉ xảy ra đúng lần đầu). */
    if (!array_key_exists('customer_id', $u) || !array_key_exists('user_kind', $u)) {
        co_ensure_tables();
        $fresh = db_fetch_row("SELECT user_kind, customer_id FROM tbl_users WHERE id = {$uid} LIMIT 1");
        if ($fresh) $u = array_merge($u, $fresh);
    }

    $cid = (int) ($u['customer_id'] ?? 0);
    if ((string) ($u['user_kind'] ?? '') === 'customer' && $cid > 0) {
        return $cache = ['mode' => 'customer', 'customer_id' => $cid, 'user_id' => $uid];
    }
    // "Nội bộ sản xuất" hoặc chưa được định danh -> 0 dòng. Fail-closed.
    return $cache = ['mode' => 'none', 'customer_id' => 0, 'user_id' => $uid];
}

/**
 * customer_id thực sự dùng để truy vấn.
 * CHỖ CHẶN QUAN TRỌNG NHẤT: khách gõ tay ?customer_id=<khách khác> cũng bị ép về khách của mình.
 * Trả -1 nghĩa là "không được xem gì".
 */
function co_effective_customer_id($requested = 0)
{
    $s = co_scope();
    if ($s['mode'] === 'admin')    return (int) $requested;   // 0 = tất cả
    if ($s['mode'] === 'customer') return (int) $s['customer_id'];
    return -1;
}

/** customer_id của 1 dòng đơn — tra theo CẶP (inv_type, id). */
function co_order_customer_id($inv_type, $id)
{
    $id = (int) $id;
    if ($id <= 0) return 0;
    $tbl = $inv_type === 'sales_export_invoice' ? 'sales_warehouse_export_invoices'
         : ($inv_type === 'sales_invoice' ? 'sales_orders' : '');
    if ($tbl === '') return 0;
    $r = db_fetch_row("SELECT customer_id FROM {$tbl} WHERE id = {$id} LIMIT 1");
    return $r ? (int) $r['customer_id'] : 0;
}

/** Tài khoản hiện tại có được đụng vào đơn này không. */
function co_can_touch_order($inv_type, $id)
{
    $s = co_scope();
    if ($s['mode'] === 'none')  return false;
    if ($s['mode'] === 'admin') return true;
    return co_order_customer_id($inv_type, $id) === (int) $s['customer_id'];
}

/* =====================================================================
 *  DỮ LIỆU ĐƠN HÀNG
 * =====================================================================*/

/** Điều kiện lọc khoảng ngày trên 1 cột datetime. */
function co_date_cond($col, $from, $to)
{
    $c = [];
    if ($from !== '') $c[] = "DATE({$col}) >= '" . escape_string($from) . "'";
    if ($to   !== '') $c[] = "DATE({$col}) <= '" . escape_string($to) . "'";
    return $c ? implode(' AND ', $c) : '';
}

/**
 * Danh sách đơn của 1 khách (hoặc tất cả nếu admin không lọc).
 * SQL copy từ admin_get_sales_orders_data() để 2 view LUÔN ra cùng con số.
 *
 * $customer_id: -1 = không thấy gì | 0 = tất cả (chỉ admin) | >0 = đúng 1 khách.
 *
 * "Giá trị hàng hóa" của nhánh phiếu xuất phải lấy SUM(stock_exports.total_amount) và LOẠI
 * dòng marker thiếu tồn; KHÔNG dùng cột goods_value làm tiền vì nó lưu theo TRIỆU và đã làm
 * tròn 2 số lẻ — chỉ dùng làm phương án dự phòng khi subquery trả NULL.
 */
function co_get_orders($from = '', $to = '', $customer_id = 0)
{
    $customer_id = (int) $customer_id;
    if ($customer_id < 0) return [];   // fail-closed

    // --- Nhánh 1: sales_orders (đơn lịch sử) ---
    // Đơn cũ không gắn được khách hàng (customer_id NULL/0) tự bị loại khi lọc theo khách —
    // anh Sáu đã chốt: ẩn hẳn khỏi view khách, không thể xác định chủ nên không được hiện.
    $cond1 = [];
    if ($c = co_date_cond('o.created_at', $from, $to)) $cond1[] = $c;
    if ($customer_id > 0) $cond1[] = "o.customer_id = {$customer_id}";
    $where1 = $cond1 ? 'WHERE ' . implode(' AND ', $cond1) : '';
    $sql1 = "SELECT o.id, o.customer_id, o.description, o.value, o.created_at,
                    c.name AS customer_name, c.short_name AS customer_short_name,
                    c.secondary_color AS customer_color,
                    (SELECT SUM(e.quantity * COALESCE(pw.weight_kg, 0))
                     FROM stock_exports e
                     LEFT JOIN product_weights pw ON pw.product_id = e.product_id
                     WHERE e.customer_id = o.customer_id
                       AND e.created_at  = o.created_at
                       AND e.type_export = 'sales_issue') AS weight_kg,
                    'sales_invoice' AS inv_type
             FROM sales_orders o
             LEFT JOIN customers c ON c.id = o.customer_id
             {$where1}";

    // --- Nhánh 2: sales_warehouse_export_invoices (phiếu xuất bán mới) ---
    $cond2 = [];
    if ($c = co_date_cond('s.created_at', $from, $to)) $cond2[] = $c;
    if ($customer_id > 0) $cond2[] = "s.customer_id = {$customer_id}";
    $where2 = $cond2 ? 'WHERE ' . implode(' AND ', $cond2) : '';
    $shortage = escape_string('Chưa ghi dữ liệu do có hàng hóa đang thiếu tồn');
    $sql2 = "SELECT s.id, s.customer_id, NULL AS description,
                    COALESCE(
                        (SELECT SUM(e.total_amount)
                         FROM stock_exports e
                         WHERE e.customer_id = s.customer_id
                           AND e.created_at  = s.created_at
                           AND e.type_export = 'sales_issue'
                           AND e.interpretation <> '{$shortage}'),
                        s.goods_value * 1000000
                    ) AS value, s.created_at,
                    c.name AS customer_name, c.short_name AS customer_short_name,
                    c.secondary_color AS customer_color,
                    (SELECT SUM(e.quantity * COALESCE(pw.weight_kg, 0))
                     FROM stock_exports e
                     LEFT JOIN product_weights pw ON pw.product_id = e.product_id
                     WHERE e.customer_id = s.customer_id
                       AND e.created_at  = s.created_at
                       AND e.type_export = 'sales_issue') AS weight_kg,
                    'sales_export_invoice' AS inv_type
             FROM sales_warehouse_export_invoices s
             LEFT JOIN customers c ON c.id = s.customer_id
             {$where2}";

    return db_fetch_array(
        "SELECT * FROM ( {$sql1} UNION ALL {$sql2} ) t ORDER BY t.created_at DESC, t.id DESC"
    ) ?: [];
}

/**
 * Map hóa đơn theo wr_id cho 1 type (1 query cho cả bảng).
 * Trả: [ wr_id => [ {id, file_url, created_at, uploaded_by, upload_source}, ... ] ].
 */
function co_invoices_map($type, $ids)
{
    if (!in_array($type, wri_valid_types(), true)) return [];
    $ids = array_values(array_unique(array_filter(array_map('intval', (array) $ids))));
    if (empty($ids)) return [];
    co_ensure_tables();

    $t    = escape_string($type);
    $list = implode(',', $ids);
    $rows = db_fetch_array(
        "SELECT id, wr_id, file_url, created_at, uploaded_by, upload_source
         FROM warehouse_receipt_invoices
         WHERE type = '{$t}' AND wr_id IN ({$list})
         ORDER BY id ASC"
    ) ?: [];

    $map = [];
    foreach ($rows as $r) {
        $map[(int) $r['wr_id']][] = [
            'id'            => (int) $r['id'],
            'file_url'      => (string) $r['file_url'],
            'created_at'    => (string) $r['created_at'],
            'uploaded_by'   => (int) $r['uploaded_by'],
            'upload_source' => (string) ($r['upload_source'] ?: 'factory'),
        ];
    }
    return $map;
}

/**
 * Chi tiết mặt hàng của 1 đơn (modal khi bấm vào dòng).
 * SQL copy từ rp_dd_export_batch_items() — load_model() chỉ tìm trong module hiện tại nên
 * không gọi chéo sang module report được.
 */
function co_order_lines($customer_id, $created_at)
{
    $cid = (int) $customer_id;
    if ($cid <= 0) return [];
    $ca = escape_string(date('Y-m-d H:i:s', strtotime((string) $created_at)));
    $rows = db_fetch_array(
        "SELECT s.quantity, s.unit_price, s.total_amount AS amount,
                COALESCE(NULLIF(p.common_product_name, ''), p.product_name,
                         NULLIF(mi.common_material_name, ''), mi.material_name) AS name,
                COALESCE(p.unit, mi.unit) AS unit,
                COALESCE(pw.weight_kg, 0) AS weight_kg
         FROM stock_exports s
         LEFT JOIN products p ON p.id = s.product_id
         LEFT JOIN material_information mi ON mi.id = s.material_id
         LEFT JOIN product_weights pw ON pw.product_id = s.product_id
         WHERE s.type_export = 'sales_issue' AND s.customer_id = {$cid} AND s.created_at = '{$ca}'
         ORDER BY s.id ASC"
    ) ?: [];

    $out = [];
    foreach ($rows as $r) {
        $qty = (float) $r['quantity'];
        $out[] = [
            'name'   => (string) ($r['name'] ?? ''),
            'unit'   => (string) ($r['unit'] ?? ''),
            'qty'    => $qty,
            'price'  => (float) $r['unit_price'],
            'value'  => (float) $r['amount'],
            'weight' => $qty * (float) $r['weight_kg'],
        ];
    }
    return $out;
}

/* =====================================================================
 *  HÓA ĐƠN — ghi / xóa / chia sẻ
 * =====================================================================*/

/** Khách tải hóa đơn lên: ghi kèm dấu vết nguồn để quyết định quyền xóa về sau. */
function co_save_invoices($inv_type, $wr_id, $files)
{
    $s      = co_scope();
    $source = $s['mode'] === 'admin' ? 'factory' : 'customer';
    return wri_save_uploaded_files($wr_id, $inv_type, $files, (int) $s['user_id'], $source);
}

/**
 * Xóa 1 hóa đơn. Khách CHỈ xóa được hóa đơn do CHÍNH tài khoản mình tải lên;
 * hóa đơn nhà máy tải từ Đơn bán hàng thì không.
 */
function co_delete_invoice($invoice_id)
{
    $inv = wri_get((int) $invoice_id);
    if (!$inv) return ['ok' => false, 'message' => 'Không tìm thấy hóa đơn.'];

    if (!co_can_touch_order((string) $inv['type'], (int) $inv['wr_id'])) {
        return ['ok' => false, 'message' => 'Không có quyền với đơn hàng này.'];
    }

    $s = co_scope();
    if ($s['mode'] !== 'admin') {
        $laCuaKhach = (string) ($inv['upload_source'] ?? 'factory') === 'customer';
        $laCuaToi   = (int) ($inv['uploaded_by'] ?? 0) === (int) $s['user_id'];
        if (!$laCuaKhach || !$laCuaToi) {
            return ['ok' => false, 'message' => 'Hóa đơn do nhà máy tải lên — bạn không xóa được.'];
        }
    }
    return ['ok' => (bool) wri_delete((int) $inv['id'])];
}

/** Gửi 1 ảnh hóa đơn vào hộp thoại chat với những người được chọn. */
function co_share_invoice_to_chat($invoice_id, array $target_ids, $note = '')
{
    $inv = wri_get((int) $invoice_id);
    if (!$inv) return ['ok' => false, 'message' => 'Không tìm thấy hóa đơn.'];
    if (!co_can_touch_order((string) $inv['type'], (int) $inv['wr_id'])) {
        return ['ok' => false, 'message' => 'Không có quyền với đơn hàng này.'];
    }

    $s = co_scope();
    require_once APPPATH . DIRECTORY_SEPARATOR . 'libraries' . DIRECTORY_SEPARATOR . 'chat.php';
    chat_ensure_tables();

    $ids = [];
    foreach ($target_ids as $t) {
        $t = (int) $t;
        if ($t > 0 && $t !== (int) $s['user_id'] && !in_array($t, $ids, true)) $ids[] = $t;
    }
    if (!$ids) return ['ok' => false, 'message' => 'Chưa chọn người nhận.'];

    // Nhân bản blob sang thư mục của chat để chat tự quản vòng đời (giống fm_share_to_chat):
    // xóa hóa đơn bên này KHÔNG được làm hỏng ảnh đã gửi trong chat.
    $rel = str_replace(['/', '\\'], DIRECTORY_SEPARATOR, (string) $inv['file_url']);
    $src = APPPATH . DIRECTORY_SEPARATOR . $rel;
    if (!is_file($src)) return ['ok' => false, 'message' => 'Tệp hóa đơn không còn trên máy chủ.'];

    $chatDir = APPPATH . DIRECTORY_SEPARATOR . 'public' . DIRECTORY_SEPARATOR . 'uploads'
             . DIRECTORY_SEPARATOR . 'chat';
    if (!is_dir($chatDir)) @mkdir($chatDir, 0775, true);

    $orig = basename((string) $inv['file_url']);
    $ext  = preg_replace('/[^a-z0-9]/', '', strtolower(pathinfo($orig, PATHINFO_EXTENSION)));
    $new  = 'c' . time() . '_' . substr(md5($orig . uniqid('', true)), 0, 10) . ($ext !== '' ? '.' . $ext : '');
    if (!@copy($src, $chatDir . DIRECTORY_SEPARATOR . $new)) {
        return ['ok' => false, 'message' => 'Không gửi được tệp.'];
    }

    $body = trim((string) $note);
    if ($body === '') $body = '🧾 Hóa đơn đơn hàng — ' . date('d/m/Y');

    $att = [
        'file_name'     => $new,
        'original_name' => mb_substr($orig, 0, 250, 'UTF-8'),
        'mime'          => in_array($ext, ['jpg', 'jpeg', 'png', 'gif', 'webp'], true) ? 'image/' . $ext : 'application/octet-stream',
        'size'          => (int) @filesize($src),
        'is_image'      => $ext === 'pdf' ? 0 : 1,
    ];

    $sent = 0;
    foreach ($ids as $t) {
        $cid = chat_get_or_create_direct((int) $s['user_id'], $t);
        if ($cid <= 0) continue;
        $mid = (int) chat_insert_message($cid, (int) $s['user_id'], $body, $att['is_image'] ? 'image' : 'file');
        if ($mid <= 0) continue;
        $row = $att;
        $row['message_id'] = $mid;
        db_insert('chat_attachments', $row);
        chat_mark_read($cid, (int) $s['user_id'], $mid);
        $sent++;
    }
    if ($sent === 0) return ['ok' => false, 'message' => 'Không gửi được cho người nhận nào.'];
    return ['ok' => true, 'sent' => $sent];
}

/**
 * DIỄN GIẢI ngắn cho từng đơn: "Bột sữa Royal's 100, Hồng Trà Bá Tước 100, ...".
 * Tên hàng lấy theo TÊN THƯỜNG GỌI (common_product_name) khi có.
 *
 * Gom TẤT CẢ đơn trong 1 truy vấn duy nhất rồi tự chia rổ — nếu gọi co_order_lines() cho
 * từng dòng thì 1 trang 100 đơn là 100 truy vấn.
 *
 * @return array [ "customerId|created_at" => ['text' => string, 'more' => int] ]
 */
function co_order_summaries(array $rows, $max_items = 3)
{
    if (empty($rows)) return [];

    $cids = $dates = [];
    foreach ($rows as $r) {
        $cid = (int) ($r['customer_id'] ?? 0);
        $ca  = (string) ($r['created_at'] ?? '');
        if ($cid > 0) $cids[$cid] = true;
        if ($ca !== '') $dates["'" . escape_string($ca) . "'"] = true;
    }
    if (empty($cids) || empty($dates)) return [];

    // Lọc thô theo 2 tập rồi chia rổ chính xác theo CẶP (customer_id, created_at) ở PHP —
    // rẻ hơn nhiều so với dựng mệnh đề OR cho từng cặp.
    $rowsItems = db_fetch_array(
        "SELECT s.customer_id, s.created_at, s.quantity,
                COALESCE(NULLIF(p.common_product_name, ''), p.product_name,
                         NULLIF(mi.common_material_name, ''), mi.material_name) AS name
         FROM stock_exports s
         LEFT JOIN products p ON p.id = s.product_id
         LEFT JOIN material_information mi ON mi.id = s.material_id
         WHERE s.type_export = 'sales_issue'
           AND s.customer_id IN (" . implode(',', array_keys($cids)) . ")
           AND s.created_at IN (" . implode(',', array_keys($dates)) . ")
         ORDER BY s.id ASC"
    ) ?: [];

    $gom = [];
    foreach ($rowsItems as $it) {
        $key = (int) $it['customer_id'] . '|' . (string) $it['created_at'];
        $ten = trim((string) ($it['name'] ?? ''));
        if ($ten === '') continue;
        $sl = (float) $it['quantity'];
        $gom[$key][] = $ten . ' ' . rtrim(rtrim(number_format($sl, 2, '.', ''), '0'), '.');
    }

    $out = [];
    foreach ($gom as $key => $ds) {
        $out[$key] = [
            'text' => implode(', ', array_slice($ds, 0, $max_items)),
            'more' => max(0, count($ds) - $max_items),
            'full' => implode(', ', $ds),
        ];
    }
    return $out;
}

/* =====================================================================
 *  TIỆN ÍCH HIỂN THỊ
 * =====================================================================*/

/**
 * Nhãn ngày kiểu tương đối: "Vừa xong, 07/08/2026" / "Hôm nay, ..." / "Hôm qua, ..." /
 * "2 ngày trước, ...". Trả ['label', 'date', 'cls'].
 *   cls: co-d-now / co-d-today  -> xanh lá
 *        co-d-yesterday         -> nâu
 *        ''                     -> đen bình thường
 */
function co_date_label($created_at)
{
    $ts = strtotime((string) $created_at);
    if (!$ts) return ['label' => '', 'date' => '', 'cls' => ''];

    $date = date('d/m/Y', $ts);
    // So sánh theo NGÀY (không theo 24 giờ trôi qua): 23h50 hôm qua so với 00h10 hôm nay vẫn
    // phải là "Hôm qua", chứ không phải "Vừa xong".
    $ngayDon = strtotime(date('Y-m-d', $ts));
    $homNay  = strtotime(date('Y-m-d'));
    $lech    = (int) round(($homNay - $ngayDon) / 86400);

    if ($lech <= 0) {
        // Trong vòng 1 giờ thì coi là vừa ghi xong.
        if (time() - $ts <= 3600) return ['label' => 'Vừa xong', 'date' => $date, 'cls' => 'co-d-now'];
        return ['label' => 'Hôm nay', 'date' => $date, 'cls' => 'co-d-today'];
    }
    if ($lech === 1) return ['label' => 'Hôm qua', 'date' => $date, 'cls' => 'co-d-yesterday'];
    return ['label' => $lech . ' ngày trước', 'date' => $date, 'cls' => ''];
}

/** Danh sách khách hàng cho bộ lọc của admin. */
function co_customer_list()
{
    return db_fetch_array("SELECT id, name FROM customers ORDER BY name ASC") ?: [];
}

function co_esc($s)
{
    return htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8');
}

function co_money($n)
{
    return number_format((float) $n, 0, ',', '.') . ' đ';
}

function co_weight($n)
{
    $n = (float) $n;
    return rtrim(rtrim(number_format($n, 1, ',', '.'), '0'), ',') . ' kg';
}
