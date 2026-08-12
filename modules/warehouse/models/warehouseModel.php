<?php

/**
 * ============================================================================
 *  KHO — model (prefix wh_)
 * ----------------------------------------------------------------------------
 *  PHIẾU SOẠN cho nhân viên kho. Admin bấm "Gửi phiếu soạn" ở
 *  /order_management/branch_orders -> đổ danh sách hàng của đơn qua đây thành
 *  một BẢN SAO ĐỘC LẬP.
 *
 *  LUẬT QUAN TRỌNG NHẤT: phiếu soạn KHÔNG phải là đơn hàng.
 *  Nhân viên kho thêm / sửa / xóa dòng thoải mái mà đơn gốc trong
 *  factory_order_sales_history KHÔNG suy suyển. Chỉ khi admin xem phiếu đã soạn
 *  xong rồi bấm "Cập nhật đơn hàng" (wh_sync_to_order) thì số của nhân viên mới
 *  ghi đè lên đơn gốc. Nhờ vậy nhân viên bốc thiếu/bốc dư không tự ý làm sai
 *  lệch đơn của bán hàng.
 *
 *  Bảng (tự tạo, không chạy SQL tay khi deploy):
 *    wh_picking_slips  — mỗi lần gửi 1 phiếu
 *    wh_picking_items  — các dòng hàng của phiếu (bản chụp)
 *
 *  Tái dùng của order_management (om_*): quy đổi kiện, bao bì ngoài, tồn kho,
 *  thứ tự hiển thị phiếu soạn — để 2 nơi luôn ra cùng một con số.
 * ============================================================================
 */

/* BẪY ĐÃ DÍNH: load_model() dùng `require` TRẦN (core/base.php), không phải require_once.
   Nên khi đang chạy trong module order_management mà file này kéo sẵn model kia vào, tới
   lượt construct() gọi load_model('order_management') là "Cannot redeclare" ngay. Chốt chặn
   bằng function_exists: chỉ nạp khi thật sự chưa có. */
if (!function_exists('om_get_product_packaging')) {
    require_once __DIR__ . '/../../order_management/models/order_managementModel.php';
}

/* =====================================================================
 *  SCHEMA
 * ===================================================================== */

function wh_ensure_tables()
{
    static $done = false;
    if ($done) return;
    $done = true;

    db_query("CREATE TABLE IF NOT EXISTS wh_picking_slips (
        id             INT(11) NOT NULL AUTO_INCREMENT,
        order_id       INT(11) NOT NULL DEFAULT 0,
        customer_id    INT(11) NOT NULL DEFAULT 0,
        customer_name  VARCHAR(255) DEFAULT NULL,
        customer_short VARCHAR(100) DEFAULT NULL,
        receiver       VARCHAR(255) DEFAULT NULL,
        phone          VARCHAR(100) DEFAULT NULL,
        address        TEXT DEFAULT NULL,
        accent         VARCHAR(9)  DEFAULT NULL,
        note           TEXT DEFAULT NULL,
        kien_map       TEXT DEFAULT NULL,
        status         VARCHAR(20) NOT NULL DEFAULT 'new',
        synced         TINYINT(1)  NOT NULL DEFAULT 0,
        synced_at      DATETIME DEFAULT NULL,
        sent_by        INT(11) NOT NULL DEFAULT 0,
        sent_at        DATETIME DEFAULT NULL,
        done_by        INT(11) NOT NULL DEFAULT 0,
        done_at        DATETIME DEFAULT NULL,
        PRIMARY KEY (id),
        KEY idx_order (order_id),
        KEY idx_status (status)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci");

    db_query("CREATE TABLE IF NOT EXISTS wh_picking_items (
        id             INT(11) NOT NULL AUTO_INCREMENT,
        slip_id        INT(11) NOT NULL,
        source_index   INT(11) DEFAULT NULL,
        item_type      VARCHAR(20) NOT NULL DEFAULT 'product',
        item_id        INT(11) NOT NULL DEFAULT 0,
        product_name   VARCHAR(255) DEFAULT NULL,
        unit           VARCHAR(50)  DEFAULT NULL,
        weight_kg      DECIMAL(14,4) NOT NULL DEFAULT 0,
        system_price   DECIMAL(16,2) NOT NULL DEFAULT 0,
        qty_order      DECIMAL(14,3) NOT NULL DEFAULT 0,
        qty_actual     DECIMAL(14,3) NOT NULL DEFAULT 0,
        kien_group     INT(11) DEFAULT NULL,
        picked         TINYINT(1) NOT NULL DEFAULT 0,
        removed        TINYINT(1) NOT NULL DEFAULT 0,
        added_by_staff TINYINT(1) NOT NULL DEFAULT 0,
        seq            INT(11) NOT NULL DEFAULT 0,
        PRIMARY KEY (id),
        KEY idx_slip (slip_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci");

    // Bản chụp lúc bấm "Soạn xong" — xem wh_finish_slip(). Thêm sau nên phải vá lười.
    if (!db_fetch_row("SHOW COLUMNS FROM wh_picking_slips LIKE 'finish_snapshot'")) {
        db_query("ALTER TABLE wh_picking_slips ADD finish_snapshot TEXT DEFAULT NULL");
    }
    // Ai tích dòng này — một phiếu có thể do 2-3 người cùng bốc, nên phải ghi theo TỪNG DÒNG
    // chứ không chỉ done_by (người bấm nút cuối).
    if (!db_fetch_row("SHOW COLUMNS FROM wh_picking_items LIKE 'picked_by'")) {
        db_query("ALTER TABLE wh_picking_items ADD picked_by INT(11) NOT NULL DEFAULT 0");
    }
}

/** Đăng ký view "Soạn hàng" vào danh mục phân quyền (idempotent). */
function wh_ensure_view_registered()
{
    static $done = false;
    if ($done) return;
    $done = true;
    if (db_num_rows("SHOW TABLES LIKE 'tbl_views'") <= 0) return;
    db_query(
        "INSERT IGNORE INTO tbl_views (module, controller, action, label, group_label, sort, is_active)
         VALUES ('warehouse', 'warehouse', 'picking_task', 'Soạn hàng', 'KHO', 152, 1)"
    );
}

/* =====================================================================
 *  TẠO PHIẾU TỪ ĐƠN
 * ===================================================================== */

/**
 * Chụp toàn bộ dòng hàng của 1 đơn thành 1 phiếu soạn mới.
 * Gửi lại đơn đã có phiếu -> tạo phiếu MỚI và bỏ các phiếu cũ chưa soạn xong
 * (status 'new'/'doing') của chính đơn đó, để nhân viên không soạn nhầm bản cũ.
 * Phiếu cũ ĐÃ soạn xong thì giữ nguyên làm lịch sử.
 *
 * @return array ['ok'=>bool, 'id'=>int, 'msg'=>string]
 */
function wh_create_slip_from_order($order_id, $sent_by = 0)
{
    wh_ensure_tables();
    $order_id = (int) $order_id;
    if ($order_id <= 0) return ['ok' => false, 'msg' => 'Thiếu id đơn hàng.'];

    $o = om_get_order($order_id);
    if (!$o) return ['ok' => false, 'msg' => 'Không tìm thấy đơn hàng.'];
    if (empty($o['items'])) return ['ok' => false, 'msg' => 'Đơn này chưa có hàng hóa nào.'];

    // Phiếu đang soạn dở của cùng đơn -> hủy, tránh 2 bản song song.
    db_query("UPDATE wh_picking_slips SET status = 'cancelled'
              WHERE order_id = $order_id AND status IN ('new','doing')");

    $cust = db_fetch_row("SELECT name, short_name, receiver, phone, address, secondary_color
                          FROM customers WHERE id = " . (int) $o['customer_id'] . " LIMIT 1");

    $slip_id = db_insert('wh_picking_slips', [
        'order_id'       => $order_id,
        'customer_id'    => (int) $o['customer_id'],
        'customer_name'  => (string) ($o['customer_name'] ?? ''),
        'customer_short' => (string) ($cust['short_name'] ?? ''),
        'receiver'       => (string) ($cust['receiver'] ?? ''),
        'phone'          => (string) ($cust['phone'] ?? ''),
        'address'        => (string) ($cust['address'] ?? ''),
        'accent'         => (string) ($cust['secondary_color'] ?? '#16a34a'),
        'note'           => (string) ($o['note'] ?? ''),
        'kien_map'       => '{}',
        'status'         => 'new',
        'sent_by'        => (int) $sent_by,
        'sent_at'        => date('Y-m-d H:i:s'),
    ]);
    if (!$slip_id) return ['ok' => false, 'msg' => 'Không tạo được phiếu soạn.'];

    $seq = 0;
    foreach ($o['items'] as $idx => $it) {
        $isMat = !empty($it['material_id']);
        $seq += 10;
        db_insert('wh_picking_items', [
            'slip_id'      => (int) $slip_id,
            'source_index' => (int) $idx,
            'item_type'    => $isMat ? 'material' : 'product',
            'item_id'      => $isMat ? (int) $it['material_id'] : (int) $it['product_id'],
            'product_name' => (string) $it['product_name'],
            'unit'         => (string) $it['unit'],
            'weight_kg'    => (float) $it['weight_kg'],
            'system_price' => (float) $it['system_price'],
            'qty_order'    => (float) $it['qt_order'],
            'qty_actual'   => (float) $it['qt_order'],
            'seq'          => $seq,
        ]);
    }

    return ['ok' => true, 'id' => (int) $slip_id];
}

/* =====================================================================
 *  ĐỌC PHIẾU
 * ===================================================================== */

/**
 * Làm giàu 1 dòng phiếu: bao bì ngoài, tồn hiện tại, quy đổi kiện, cờ thiếu tồn,
 * thứ tự hiển thị. Dùng CHUNG hàm om_* nên số liệu khớp với phiếu soạn A4.
 */
function wh_enrich_item($r)
{
    $isMat = ((string) ($r['item_type'] ?? 'product')) === 'material';
    $id    = (int) ($r['item_id'] ?? 0);
    $name  = (string) ($r['product_name'] ?? '');
    $unit  = (string) ($r['unit'] ?? '');

    $ops = 0; $short = ''; $stock = 0.0;
    if ($isMat) {
        $m = om_get_material_for_slip($id);
        if ($m) {
            $stock = (float) $m['stock'];
            if ($unit === '') $unit = (string) $m['unit'];
            if ($name === '') $name = (string) $m['product_name'];
        }
    } else {
        $pk = om_get_product_packaging($id);
        if ($pk) {
            $ops   = (int) $pk['ops_qty'];
            $short = (string) $pk['short_name'];
            $stock = (float) $pk['stock'];
            if ($unit === '') $unit = (string) $pk['unit'];
            // Tên thường gọi HIỆN TẠI thắng tên đã chụp lúc gửi phiếu (SP có thể được đổi tên).
            if (!empty($pk['product_name'])) $name = (string) $pk['product_name'];
        }
    }

    $qty_actual = (float) ($r['qty_actual'] ?? 0);
    $qty_order  = (float) ($r['qty_order'] ?? 0);
    $k          = om_qty_to_kien($qty_actual, $ops, $short, $unit);
    $ko         = om_qty_to_kien($qty_order, $ops, $short, $unit);

    return [
        'id'           => (int) $r['id'],
        'source_index' => ($r['source_index'] === null || $r['source_index'] === '') ? null : (int) $r['source_index'],
        'item_type'    => $isMat ? 'material' : 'product',
        'item_id'      => $id,
        'product_name' => $name,
        'unit'         => $unit,
        'weight_kg'    => (float) ($r['weight_kg'] ?? 0),
        'system_price' => (float) ($r['system_price'] ?? 0),
        'qty_order'    => $qty_order,
        'qty_actual'   => $qty_actual,
        'kien_group'   => ($r['kien_group'] === null || $r['kien_group'] === '') ? null : (int) $r['kien_group'],
        'picked'       => !empty($r['picked']),
        'picked_by'    => (int) ($r['picked_by'] ?? 0),
        'removed'      => !empty($r['removed']),
        'added_by_staff' => !empty($r['added_by_staff']),
        'seq'          => (int) ($r['seq'] ?? 0),

        'ops_qty'      => $ops,
        'short_name'   => $short,
        'letter'       => om_kien_letter($short),
        'stock'        => $stock,
        'kien_text'    => $k['text'],
        'kien_whole'   => (int) $k['whole'],
        'kien_rem'     => (float) $k['rem'],
        'order_kien'   => $ko['text'],
        'inv_kien'     => om_inventory_kien($stock, $ops, $short, $unit),
        'pack_label'   => ($ops > 0 && $short !== '') ? ($ops . ' ' . $unit . '/' . mb_strtolower($short, 'UTF-8')) : '',
        'is_short'     => (!$isMat && $qty_actual > $stock),
        'reminder_note' => (!$isMat && $id > 0) ? om_get_product_pickup_note($id) : '',
        'sort_order'   => null,   // gắn ở wh_get_slip()
    ];
}

/** 1 phiếu soạn + toàn bộ dòng (kể cả dòng nhân viên đã xóa, để admin xem lại). */
function wh_get_slip($slip_id)
{
    wh_ensure_tables();
    $slip_id = (int) $slip_id;
    if ($slip_id <= 0) return null;
    $s = db_fetch_row("SELECT * FROM wh_picking_slips WHERE id = $slip_id LIMIT 1");
    if (!$s) return null;

    $rows = db_fetch_array("SELECT * FROM wh_picking_items WHERE slip_id = $slip_id ORDER BY seq ASC, id ASC") ?: [];
    $items = [];
    foreach ($rows as $r) $items[] = wh_enrich_item($r);

    // Thứ tự hiển thị phiếu soạn (om_slip_display_order) — dùng CHUNG cấu hình với phiếu A4.
    $pids = [];
    foreach ($items as $it) if ($it['item_type'] === 'product' && $it['item_id'] > 0) $pids[] = $it['item_id'];
    $smap = om_slip_order_map($pids);
    foreach ($items as &$it) {
        $it['sort_order'] = ($it['item_type'] === 'product' && array_key_exists($it['item_id'], $smap))
            ? $smap[$it['item_id']] : null;
    }
    unset($it);

    $kien_map = json_decode((string) ($s['kien_map'] ?? '{}'), true);
    if (!is_array($kien_map)) $kien_map = [];

    $s['items']    = $items;
    $s['kien_map'] = $kien_map;
    $s['summary']  = wh_kien_summary($items, $kien_map);
    // Danh bạ người soạn (id -> avatar/tên) để phía màn hình kia dựng được avatar bay lên
    // ngay khi đồng nghiệp tích một dòng.
    $s['pickers']  = wh_slip_pickers($slip_id, (int) ($s['done_by'] ?? 0));
    return $s;
}

/**
 * Danh sách phiếu cho NHÂN VIÊN KHO: mọi phiếu chưa soạn xong, cộng thêm phiếu
 * vừa soạn xong trong 24h (để xem lại / sửa nếu lỡ tay).
 */
function wh_list_slips_for_staff()
{
    wh_ensure_tables();
    $rows = db_fetch_array(
        "SELECT s.id, s.order_id, s.customer_name, s.customer_short, s.status, s.sent_at, s.done_at, s.accent,
                (SELECT COUNT(*) FROM wh_picking_items i WHERE i.slip_id = s.id AND i.removed = 0) AS total_items,
                (SELECT COUNT(*) FROM wh_picking_items i WHERE i.slip_id = s.id AND i.removed = 0 AND i.picked = 1) AS picked_items
         FROM wh_picking_slips s
         LEFT JOIN factory_order_sales_history h ON h.id = s.order_id
         WHERE COALESCE(h.picked, 0) = 0
           AND (s.status IN ('new','doing')
                OR (s.status = 'done' AND s.done_at >= DATE_SUB(NOW(), INTERVAL 1 DAY)))
         ORDER BY (s.status = 'done') ASC, s.sent_at DESC, s.id DESC"
    ) ?: [];
    foreach ($rows as &$r) {
        $r['label'] = trim((string) $r['customer_short']) !== ''
            ? (string) $r['customer_short'] : (string) $r['customer_name'];
    }
    unset($r);
    return $rows;
}

/** Phiếu mới nhất của 1 đơn (cho trạng thái nút trên card đơn hàng). */
function wh_latest_slip_for_order($order_id)
{
    wh_ensure_tables();
    $order_id = (int) $order_id;
    if ($order_id <= 0) return null;
    return db_fetch_row(
        "SELECT id, status, synced, sent_at, done_at FROM wh_picking_slips
         WHERE order_id = $order_id AND status <> 'cancelled'
         ORDER BY id DESC LIMIT 1"
    );
}

/** Map [order_id => {id,status,synced}] cho cả trang danh sách đơn (1 truy vấn). */
function wh_slip_map_for_orders(array $order_ids)
{
    wh_ensure_tables();
    $ids = array_values(array_unique(array_filter(array_map('intval', $order_ids), static fn($v) => $v > 0)));
    if (!$ids) return [];
    $in   = implode(',', $ids);
    $rows = db_fetch_array(
        "SELECT s.order_id, s.id, s.status, s.synced
         FROM wh_picking_slips s
         JOIN (SELECT order_id, MAX(id) AS mx FROM wh_picking_slips
               WHERE order_id IN ($in) AND status <> 'cancelled' GROUP BY order_id) t
           ON t.mx = s.id"
    ) ?: [];
    $map = [];
    foreach ($rows as $r) {
        $map[(int) $r['order_id']] = [
            'id'     => (int) $r['id'],
            'status' => (string) $r['status'],
            'synced' => !empty($r['synced']),
        ];
    }
    return $map;
}

/* =====================================================================
 *  SỐ KIỆN DỰ KIẾN
 * ---------------------------------------------------------------------
 *  Khác om_kien_summary() ở đúng một điểm — điểm anh Sáu yêu cầu:
 *  các dòng lẻ được nhân viên đánh CHUNG KIỆN (số 1, 2, 3...) không còn
 *  đếm là "SP lẻ" nữa, mà cộng thẳng vào tổng T/B theo con số nhân viên
 *  khai ở "Kiện (1) = 1T". Nhờ vậy 10B + 3T + 2 SP lẻ trở thành 10B + 4T.
 * ===================================================================== */

/** Tách "1T" / "2B" -> ['n'=>int,'letter'=>'T'|'B'] ; sai định dạng -> null. */
function wh_parse_pack($text)
{
    $t = strtoupper(trim((string) $text));
    if ($t === '') return null;
    if (!preg_match('/^(\d+)\s*([TB])$/u', $t, $m)) return null;
    $n = (int) $m[1];
    if ($n <= 0) return null;
    return ['n' => $n, 'letter' => $m[2]];
}

/**
 * Tổng hợp kiện.
 * @param array $items    dòng đã làm giàu (wh_enrich_item)
 * @param array $kien_map ['1' => '1T', '2' => '2B']
 * @return array ['text','parts','loose','groups','invalid','weight']
 */
function wh_kien_summary($items, $kien_map)
{
    $by_letter = [];
    $loose     = 0;
    $groups    = [];    // số nhóm đang được dùng
    $invalid   = [];    // nhóm khai sai định dạng
    $weight    = 0.0;

    foreach ($items as $it) {
        if (!empty($it['removed'])) continue;
        $qty = (float) $it['qty_actual'];
        if ($qty <= 0) continue;
        $weight += $qty * (float) $it['weight_kg'];

        $g = $it['kien_group'];
        if ($g !== null && (int) $g > 0) $groups[(int) $g] = true;

        $whole  = (int) $it['kien_whole'];
        $letter = (string) $it['letter'];
        if ($whole > 0 && $letter !== '') {
            $by_letter[$letter] = ($by_letter[$letter] ?? 0) + $whole;
        }

        // Phần lẻ: nếu dòng đã được gán chung kiện thì KHÔNG tính là SP lẻ nữa —
        // nó sẽ được cộng qua con số khai ở "Kiện (N) = ...".
        $has_loose = ((float) $it['kien_rem'] > 0) || ($whole === 0);
        if ($has_loose && ($g === null || (int) $g <= 0)) $loose++;
    }

    foreach (array_keys($groups) as $g) {
        $p = wh_parse_pack($kien_map[(string) $g] ?? ($kien_map[$g] ?? ''));
        if ($p === null) { $invalid[] = (int) $g; continue; }
        $by_letter[$p['letter']] = ($by_letter[$p['letter']] ?? 0) + $p['n'];
    }

    // Thứ tự hiển thị cố định B trước T rồi tới ký hiệu khác, cho phiếu nào cũng đọc giống nhau.
    $order = ['B' => 0, 'T' => 1, 'K' => 2];
    $keys  = array_keys($by_letter);
    usort($keys, static fn($a, $b) => ($order[$a] ?? 9) <=> ($order[$b] ?? 9));

    $parts = [];
    foreach ($keys as $l) $parts[] = $by_letter[$l] . $l;
    $segs = $parts;
    if ($loose > 0) $segs[] = $loose . ' SP lẻ';

    return [
        'text'    => implode(' + ', $segs),
        'parts'   => $parts,
        'loose'   => $loose,
        'groups'  => array_values(array_map('intval', array_keys($groups))),
        'invalid' => $invalid,
        'weight'  => $weight,
    ];
}

/* =====================================================================
 *  NHÂN VIÊN THAO TÁC TRÊN PHIẾU
 * ===================================================================== */

/**
 * Phiếu còn cho sửa không?
 * MỐC KHÓA DUY NHẤT là synced — tức admin đã bấm "Cập nhật đơn hàng". Trạng thái
 * 'done' CỐ Ý vẫn cho sửa: nhân viên bấm "Soạn xong" rồi mới phát hiện sai vẫn phải
 * sửa lại được, và admin phải bấm được nút cho dòng đã gỡ quay lại phiếu.
 */
function wh_slip_editable($slip_row)
{
    if (!$slip_row) return false;
    if (!empty($slip_row['synced'])) return false;
    return in_array((string) $slip_row['status'], ['new', 'doing', 'done'], true);
}

/**
 * Mọi thao tác ghi đều gọi hàm này: 'new' -> 'doing', và kéo NGƯỢC 'done' -> 'doing'.
 * Kéo ngược là cố ý — phiếu đã báo xong mà bị sửa thì không còn xong nữa, để nguyên
 * 'done' sẽ khiến admin thấy "đã soạn xong" trong khi có dòng chưa tích.
 */
function wh_touch_slip($slip_id)
{
    $slip_id = (int) $slip_id;
    db_query("UPDATE wh_picking_slips SET status = 'doing'
              WHERE id = $slip_id AND synced = 0 AND status IN ('new','done')");
}

/** Dòng + phiếu của nó (kèm kiểm tra dòng thuộc đúng phiếu). */
function wh_get_item($item_id)
{
    wh_ensure_tables();
    $item_id = (int) $item_id;
    if ($item_id <= 0) return null;
    return db_fetch_row("SELECT * FROM wh_picking_items WHERE id = $item_id LIMIT 1");
}

/**
 * Đổi số lượng thực bốc của 1 dòng.
 * Dòng đã tích "bốc đủ" thì khóa — muốn sửa phải bỏ tích trước (luật anh Sáu chốt).
 */
function wh_set_item_qty($item_id, $qty)
{
    $it = wh_get_item($item_id);
    if (!$it) return ['ok' => false, 'msg' => 'Không tìm thấy dòng hàng.'];
    if (!empty($it['picked'])) return ['ok' => false, 'msg' => 'Dòng đã tích bốc đủ — bỏ tích để sửa.'];
    $qty = (float) $qty;
    if ($qty < 0) $qty = 0;
    db_update('wh_picking_items', ['qty_actual' => $qty], 'id = ' . (int) $item_id);
    wh_touch_slip((int) $it['slip_id']);
    return ['ok' => true, 'slip_id' => (int) $it['slip_id']];
}

/** Gán / bỏ số chung kiện cho 1 dòng. $group rỗng hoặc <=0 = bỏ gán. */
function wh_set_item_group($item_id, $group)
{
    $it = wh_get_item($item_id);
    if (!$it) return ['ok' => false, 'msg' => 'Không tìm thấy dòng hàng.'];
    if (!empty($it['picked'])) return ['ok' => false, 'msg' => 'Dòng đã tích bốc đủ — bỏ tích để sửa.'];
    $g = ($group === '' || $group === null) ? null : (int) $group;
    if ($g !== null && $g <= 0) $g = null;
    db_update('wh_picking_items', ['kien_group' => $g], 'id = ' . (int) $item_id);
    wh_touch_slip((int) $it['slip_id']);
    return ['ok' => true, 'slip_id' => (int) $it['slip_id']];
}

/** Tích / bỏ tích "đã bốc đủ" (tích = khóa dòng, bỏ tích = mở lại). */
function wh_set_item_picked($item_id, $picked, $user_id = 0)
{
    $it = wh_get_item($item_id);
    if (!$it) return ['ok' => false, 'msg' => 'Không tìm thấy dòng hàng.'];
    db_update('wh_picking_items', [
        'picked'    => $picked ? 1 : 0,
        'picked_by' => $picked ? (int) $user_id : 0,
    ], 'id = ' . (int) $item_id);
    wh_touch_slip((int) $it['slip_id']);
    return ['ok' => true, 'slip_id' => (int) $it['slip_id']];
}

/**
 * Nhân viên gỡ 1 dòng khỏi phiếu (hàng không có để xuất).
 * XÓA MỀM: giữ dòng lại để admin xem "nhân viên đã gỡ những gì" và bấm cho vào lại.
 */
function wh_remove_item($item_id, $removed = true)
{
    $it = wh_get_item($item_id);
    if (!$it) return ['ok' => false, 'msg' => 'Không tìm thấy dòng hàng.'];
    db_update('wh_picking_items', [
        'removed' => $removed ? 1 : 0,
        'picked'  => $removed ? 0 : (int) $it['picked'],
    ], 'id = ' . (int) $item_id);
    wh_touch_slip((int) $it['slip_id']);
    return ['ok' => true, 'slip_id' => (int) $it['slip_id']];
}

/**
 * Nhân viên thêm 1 SP / NVL vào phiếu.
 * CỐ Ý cho phép trùng tên: một mặt hàng có thể tách làm 2 dòng để đóng 2 kiện khác nhau.
 */
function wh_add_item($slip_id, $type, $item_id, $qty = 0)
{
    wh_ensure_tables();
    $slip_id = (int) $slip_id;
    $item_id = (int) $item_id;
    $type    = ($type === 'material') ? 'material' : 'product';
    if ($slip_id <= 0 || $item_id <= 0) return ['ok' => false, 'msg' => 'Thiếu dữ liệu.'];

    $slip = db_fetch_row("SELECT * FROM wh_picking_slips WHERE id = $slip_id LIMIT 1");
    if (!wh_slip_editable($slip)) return ['ok' => false, 'msg' => 'Phiếu này không còn sửa được.'];

    $info = ($type === 'material') ? om_get_material_for_slip($item_id) : om_get_product_for_slip($item_id);
    if (!$info) return ['ok' => false, 'msg' => 'Không tìm thấy hàng hóa.'];

    $max = db_fetch_row("SELECT COALESCE(MAX(seq), 0) AS mx FROM wh_picking_items WHERE slip_id = $slip_id");
    $new_id = db_insert('wh_picking_items', [
        'slip_id'        => $slip_id,
        'source_index'   => null,
        'item_type'      => $type,
        'item_id'        => $item_id,
        'product_name'   => (string) $info['product_name'],
        'unit'           => (string) $info['unit'],
        'weight_kg'      => (float) ($info['weight_kg'] ?? 0),
        'system_price'   => (float) ($info['system_price'] ?? 0),
        'qty_order'      => 0,
        'qty_actual'     => (float) $qty,
        'added_by_staff' => 1,
        'seq'            => (int) ($max['mx'] ?? 0) + 10,
    ]);
    if (!$new_id) return ['ok' => false, 'msg' => 'Không thêm được dòng.'];
    wh_touch_slip($slip_id);
    return ['ok' => true, 'id' => (int) $new_id];
}

/** Lưu bảng khai chung kiện ['1'=>'1T', ...]. Trả kèm danh sách nhóm khai sai. */
function wh_save_kien_map($slip_id, $map)
{
    wh_ensure_tables();
    $slip_id = (int) $slip_id;
    if ($slip_id <= 0 || !is_array($map)) return ['ok' => false, 'msg' => 'Thiếu dữ liệu.'];
    $clean = []; $invalid = [];
    foreach ($map as $g => $v) {
        $g = (int) $g;
        if ($g <= 0) continue;
        $v = strtoupper(trim((string) $v));
        if ($v === '') continue;
        if (wh_parse_pack($v) === null) $invalid[] = $g;
        $clean[(string) $g] = $v;
    }
    db_update('wh_picking_slips', ['kien_map' => json_encode($clean, JSON_UNESCAPED_UNICODE)], "id = $slip_id");
    wh_touch_slip($slip_id);
    return ['ok' => true, 'invalid' => $invalid];
}

/** Ghi chú phiếu. */
function wh_save_note($slip_id, $note)
{
    wh_ensure_tables();
    $slip_id = (int) $slip_id;
    if ($slip_id <= 0) return false;
    db_update('wh_picking_slips', ['note' => (string) $note], "id = $slip_id");
    return true;
}

/**
 * "Soạn xong": bắt buộc mọi dòng còn lại đã tích, và mọi nhóm chung kiện đã
 * khai đúng định dạng (có T hoặc B).
 */
function wh_finish_slip($slip_id, $user_id = 0)
{
    wh_ensure_tables();
    $slip = wh_get_slip($slip_id);
    if (!$slip) return ['ok' => false, 'msg' => 'Không tìm thấy phiếu soạn.'];

    $left = [];
    foreach ($slip['items'] as $it) {
        if (!empty($it['removed'])) continue;
        if (empty($it['picked'])) $left[] = (string) $it['product_name'];
    }
    if ($left) {
        return [
            'ok'  => false,
            'msg' => 'Còn ' . count($left) . ' mặt hàng chưa tích bốc đủ: ' . implode(', ', array_slice($left, 0, 5))
                     . (count($left) > 5 ? '…' : ''),
        ];
    }
    if (!empty($slip['summary']['invalid'])) {
        return [
            'ok'  => false,
            'msg' => 'Kiện ' . implode(', ', $slip['summary']['invalid'])
                     . ' chưa khai đúng — phải là số kèm T (thùng) hoặc B (bao), ví dụ 1T hoặc 2B.',
        ];
    }

    /* BẢN CHỤP LÚC BÁO XONG — đây mới là "lịch sử soạn hàng" đúng nghĩa.
       Các dòng trong wh_picking_items vẫn còn sửa được cho tới khi admin đồng bộ, nên nếu
       chỉ đọc lại dòng thì con số trong lịch sử sẽ trôi theo lần sửa sau. Chốt cứng ở đây
       thì về sau vẫn tra được đúng thứ nhân viên đã báo vào thời điểm bấm nút. */
    $live = array_values(array_filter($slip['items'], static fn($i) => empty($i['removed'])));
    $snapshot = [
        'at'       => date('Y-m-d H:i:s'),
        'by'       => (int) $user_id,
        'kien'     => (string) ($slip['summary']['text'] ?? ''),
        'weight'   => (float) ($slip['summary']['weight'] ?? 0),
        'lines'    => count($live),
        'removed'  => count($slip['items']) - count($live),
        'kien_map' => $slip['kien_map'],
        'pickers'  => wh_slip_pickers((int) $slip['id'], (int) $user_id),
        'items'    => array_map(static fn($i) => [
            'name'  => (string) $i['product_name'],
            'unit'  => (string) $i['unit'],
            'order' => (float) $i['qty_order'],
            'pick'  => (float) $i['qty_actual'],
            'kien'  => (string) $i['kien_text'],
            'group' => $i['kien_group'],
        ], $live),
    ];

    db_update('wh_picking_slips', [
        'status'          => 'done',
        'done_by'         => (int) $user_id,
        'done_at'         => date('Y-m-d H:i:s'),
        'finish_snapshot' => json_encode($snapshot, JSON_UNESCAPED_UNICODE),
    ], 'id = ' . (int) $slip_id);
    return ['ok' => true, 'slip' => wh_get_slip($slip_id)];
}

/* =====================================================================
 *  LỊCH SỬ SOẠN HÀNG
 * ===================================================================== */

/**
 * Những ai đã soạn phiếu này (để hiện avatar).
 * Gộp người tích từng dòng (wh_picking_items.picked_by) với người bấm "Soạn xong"
 * (done_by) — một phiếu 2-3 người cùng bốc là chuyện thường, chỉ lấy done_by thì
 * mất công người còn lại.
 * Trả [{id, name, username, avatar, initial}, ...]
 */
function wh_slip_pickers($slip_id, $done_by = 0)
{
    $slip_id = (int) $slip_id;
    $ids = [];
    if ($slip_id > 0) {
        foreach ((db_fetch_array(
            "SELECT DISTINCT picked_by FROM wh_picking_items
             WHERE slip_id = $slip_id AND picked = 1 AND picked_by > 0"
        ) ?: []) as $r) $ids[] = (int) $r['picked_by'];
    }
    if ((int) $done_by > 0) $ids[] = (int) $done_by;
    $ids = array_values(array_unique(array_filter($ids)));
    if (!$ids) return [];

    $rows = db_fetch_array(
        'SELECT id, fullname, username, avatar FROM tbl_users WHERE id IN (' . implode(',', $ids) . ')'
    ) ?: [];
    $out = [];
    foreach ($rows as $r) {
        $ten = trim((string) $r['fullname']) !== '' ? (string) $r['fullname'] : (string) $r['username'];
        $out[] = [
            'id'       => (int) $r['id'],
            'name'     => $ten,
            'username' => (string) $r['username'],
            'avatar'   => (string) ($r['avatar'] ?? ''),
            'initial'  => mb_strtoupper(mb_substr($ten, 0, 1, 'UTF-8'), 'UTF-8'),
        ];
    }
    return $out;
}

/**
 * Danh sách phiếu ĐÃ soạn xong, lọc theo khoảng ngày + phân trang.
 * Lọc theo done_at (ngày báo xong) chứ không phải sent_at — người tra cứu nghĩ theo
 * "hôm đó soạn cái gì", không phải "hôm đó admin gửi cái gì".
 */
function wh_history_slips($from = '', $to = '', $page = 1, $per_page = 10)
{
    wh_ensure_tables();
    $page = max(1, (int) $page);
    $per  = max(1, min(100, (int) $per_page));
    $off  = ($page - 1) * $per;

    $where = ["s.status = 'done'", 's.done_at IS NOT NULL'];
    if (preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) $from)) $where[] = "s.done_at >= '" . escape_string($from) . " 00:00:00'";
    if (preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) $to))   $where[] = "s.done_at <= '" . escape_string($to) . " 23:59:59'";
    $w = implode(' AND ', $where);

    $rows = db_fetch_array(
        "SELECT s.id, s.order_id, s.customer_name, s.customer_short, s.accent, s.done_at, s.done_by,
                s.synced, s.synced_at, s.finish_snapshot
         FROM wh_picking_slips s
         WHERE $w
         ORDER BY s.done_at DESC, s.id DESC
         LIMIT $per OFFSET $off"
    ) ?: [];

    foreach ($rows as &$r) {
        $snap = json_decode((string) $r['finish_snapshot'], true);
        if (!is_array($snap)) $snap = [];
        $r['label'] = trim((string) $r['customer_short']) !== ''
            ? (string) $r['customer_short'] : (string) $r['customer_name'];

        // Phiếu chốt TRƯỚC khi có cột finish_snapshot thì không có bản chụp — dựng tạm từ
        // dòng hiện tại để lịch sử không hiện "0 dòng". Chỉ chạy cho phiếu cũ, tối đa
        // bằng số dòng 1 trang nên không nặng.
        if (!$snap) {
            $s = wh_get_slip((int) $r['id']);
            if ($s) {
                $live = array_filter($s['items'], static fn($i) => empty($i['removed']));
                $snap = [
                    'kien'   => (string) ($s['summary']['text'] ?? ''),
                    'lines'  => count($live),
                    'weight' => (float) ($s['summary']['weight'] ?? 0),
                ];
            }
        }
        $r['kien']    = (string) ($snap['kien'] ?? '');
        $r['lines']   = (int) ($snap['lines'] ?? 0);
        $r['weight']  = (float) ($snap['weight'] ?? 0);
        // Nhiều người cùng soạn 1 phiếu -> trả cả danh sách để hiện nhiều avatar.
        $r['pickers'] = wh_slip_pickers((int) $r['id'], (int) $r['done_by']);
        unset($r['finish_snapshot']);
    }
    unset($r);

    $total = (int) db_num_rows("SELECT s.id FROM wh_picking_slips s WHERE $w");
    return [
        'rows'        => $rows,
        'total'       => $total,
        'page'        => $page,
        'per_page'    => $per,
        'total_pages' => (int) ceil($total / $per),
    ];
}

/** Chi tiết 1 phiếu trong lịch sử: ưu tiên bản chụp lúc báo xong, chưa có thì đọc dòng hiện tại. */
function wh_history_detail($slip_id)
{
    $slip = wh_get_slip($slip_id);
    if (!$slip) return null;
    $snap = json_decode((string) ($slip['finish_snapshot'] ?? ''), true);
    if (!is_array($snap) || empty($snap['items'])) {
        $live = array_values(array_filter($slip['items'], static fn($i) => empty($i['removed'])));
        $snap = [
            'at'     => (string) ($slip['done_at'] ?? ''),
            'kien'   => (string) ($slip['summary']['text'] ?? ''),
            'weight' => (float) ($slip['summary']['weight'] ?? 0),
            'lines'  => count($live),
            'items'  => array_map(static fn($i) => [
                'name'  => (string) $i['product_name'],
                'unit'  => (string) $i['unit'],
                'order' => (float) $i['qty_order'],
                'pick'  => (float) $i['qty_actual'],
                'kien'  => (string) $i['kien_text'],
                'group' => $i['kien_group'],
            ], $live),
        ];
    }

    return [
        'id'        => (int) $slip['id'],
        'order_id'  => (int) $slip['order_id'],
        'label'     => trim((string) $slip['customer_short']) !== '' ? (string) $slip['customer_short'] : (string) $slip['customer_name'],
        'receiver'  => (string) $slip['receiver'],
        'address'   => (string) $slip['address'],
        'note'      => (string) $slip['note'],
        'done_at'   => (string) ($slip['done_at'] ?? ''),
        'pickers'   => wh_slip_pickers((int) $slip['id'], (int) ($slip['done_by'] ?? 0)),
        'accent'    => (string) ($slip['accent'] ?? '#16a34a'),
        'phone'     => (string) ($slip['phone'] ?? ''),
        'synced'    => !empty($slip['synced']),
        'synced_at' => (string) ($slip['synced_at'] ?? ''),
        'snapshot'  => $snap,
    ];
}

/* =====================================================================
 *  ADMIN: ĐỒNG BỘ NGƯỢC VỀ ĐƠN HÀNG
 * ===================================================================== */

/**
 * Ghi đè order_items của đơn gốc bằng đúng những gì nhân viên đã chốt soạn.
 * CHỈ đụng vào đơn trong factory_order_sales_history; KHÔNG tự đẩy sang xuất
 * kho bán hàng — anh Sáu chốt bước đó vẫn làm tay ở /warehouse_outbound.
 */
function wh_sync_to_order($slip_id)
{
    wh_ensure_tables();
    $slip = wh_get_slip($slip_id);
    if (!$slip) return ['ok' => false, 'msg' => 'Không tìm thấy phiếu soạn.'];
    $order_id = (int) $slip['order_id'];
    if ($order_id <= 0) return ['ok' => false, 'msg' => 'Phiếu này không gắn với đơn hàng nào.'];
    if (!db_fetch_row("SELECT id FROM factory_order_sales_history WHERE id = $order_id LIMIT 1")) {
        return ['ok' => false, 'msg' => 'Đơn hàng gốc không còn tồn tại.'];
    }

    $items = [];
    $wt = 0.0; $val = 0.0;
    foreach ($slip['items'] as $it) {
        if (!empty($it['removed'])) continue;
        $q = (float) $it['qty_actual'];
        if ($q <= 0) continue;
        $isMat = ($it['item_type'] === 'material');
        $wkg   = (float) $it['weight_kg'];
        $price = (float) $it['system_price'];
        $lw = $q * $wkg;
        $lv = $q * $price;
        $items[] = [
            'product_id'   => $isMat ? null : (int) $it['item_id'],
            'material_id'  => $isMat ? (int) $it['item_id'] : null,
            'type'         => $isMat ? 'material' : 'product',
            'product_name' => (string) $it['product_name'],
            'unit'         => (string) $it['unit'],
            'qt_order'     => $q,
            'weight_kg'    => $wkg,
            'system_price' => $price,
            'line_weight'  => $lw,
            'line_value'   => $lv,
        ];
        $wt  += $lw;
        $val += $lv;
    }
    if (!$items) return ['ok' => false, 'msg' => 'Phiếu soạn không còn dòng hàng nào có số lượng.'];

    db_update('factory_order_sales_history', [
        'order_items'  => json_encode($items, JSON_UNESCAPED_UNICODE),
        'weight_total' => $wt,
        'value_total'  => $val,
        'description'  => 'order nhà máy ' . number_format($wt, 0, ',', '.') . 'kg - ' . number_format($val, 0, ',', '.') . ' đ',
    ], "id = $order_id");

    db_update('wh_picking_slips', [
        'synced'    => 1,
        'synced_at' => date('Y-m-d H:i:s'),
    ], 'id = ' . (int) $slip['id']);

    return ['ok' => true, 'order_id' => $order_id, 'lines' => count($items), 'weight' => $wt, 'value' => $val];
}

/**
 * Xoá hẳn 1 phiếu soạn + toàn bộ dòng của nó.
 * XOÁ CỨNG là cố ý: phiếu soạn chỉ là bản nháp công việc của kho, không phải chứng
 * từ. Đơn hàng gốc và các bút toán xuất kho nằm chỗ khác nên không bị ảnh hưởng —
 * kể cả phiếu đã đồng bộ, số đã ghi vào đơn vẫn còn nguyên trong đơn.
 */
function wh_delete_slip($slip_id)
{
    wh_ensure_tables();
    $slip_id = (int) $slip_id;
    if ($slip_id <= 0) return false;
    if (!db_fetch_row("SELECT id FROM wh_picking_slips WHERE id = $slip_id LIMIT 1")) return false;
    db_query("DELETE FROM wh_picking_items WHERE slip_id = $slip_id");
    db_query("DELETE FROM wh_picking_slips WHERE id = $slip_id");
    return true;
}

/** Admin bấm cho 1 dòng nhân viên đã gỡ quay lại phiếu. */
function wh_restore_item($item_id)
{
    return wh_remove_item($item_id, false);
}
