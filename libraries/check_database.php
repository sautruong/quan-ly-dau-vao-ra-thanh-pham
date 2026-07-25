<?php
defined('APPPATH') OR exit('Không được quyền truy cập phần này');

/* =====================================================================
 *  CHECK DATABASE — component dùng chung cho các view Nhập/Xuất.
 *  Trả về preview nội dung 1 số bảng, PHÂN TRANG THẬT ở server (10 dòng/trang,
 *  đi hết toàn bộ bảng chứ không giới hạn 50 dòng), với cột FK (*_id) đã được
 *  chuyển hóa sang tên ở bảng chính. Một số bảng còn có Ô LỌC (icon cạnh tiêu đề)
 *  theo đúng 1 cột đã khai báo ở cdb_filter_target_map().
 *  Mỗi view khai báo danh sách bảng cần xem qua data-tables của nút.
 * =====================================================================*/

/**
 * Bảng -> NHÃN cột dùng để lọc (icon lọc cạnh tiêu đề bảng trong modal Check Database
 * — dùng chung cho MỌI view có nút .btn-check-db, không riêng gì 1 trang nhập liệu).
 * Nhãn phải khớp ĐÚNG 1 trong 2 dạng:
 *  - Nhãn dịch từ cột FK (*_id) theo cdb_fk_map(), vd 'Tên sản phẩm' / 'Tên NVL' /
 *    'Nhà cung cấp' / 'Khách hàng' / 'Nghiệp vụ'.
 *  - Tên cột GỐC (không phải FK) của chính bảng đó, vd 'material_name', 'description'
 *    — các bảng "gốc" (material_information, products...) hiển thị đúng tên cột thô
 *    này làm tiêu đề (không dịch, vì không phải cột *_id).
 * Bảng nào không có "trường chính" phù hợp để lọc (chỉ có số liệu, không có cột định
 * danh) thì cố tình KHÔNG khai báo ở đây (vd production_costs_daily).
 */
function cdb_filter_target_map()
{
    return [
        // inventory_receiving
        'stock_imports'                      => 'Tên sản phẩm',
        'stock_import_invoices'              => 'Nhà cung cấp',
        'warehouse_receipts'                 => 'Nhà cung cấp',
        'stock_import_purchase_costs'        => 'description',
        'raw_material_purchase_data'         => 'Tên NVL',
        'material_inventory'                 => 'Tên NVL',
        'material_purchase_prices'           => 'Tên NVL',
        'purchase_price_changes'             => 'Tên NVL',
        'material_information'               => 'material_name',
        // inventory_management / danh mục chung
        'products'                            => 'product_name',
        'finished_goods_inventory'            => 'Tên sản phẩm',
        'product_purchase_prices'             => 'Tên sản phẩm',
        'purchased_finished_product_data'     => 'Tên sản phẩm',
        'sales_returns'                       => 'Tên sản phẩm',
        'finished_product_production_data'    => 'Tên sản phẩm',
        'production_materials'                => 'Tên NVL',
        'production_receipts'                 => 'Tên sản phẩm',
        'product_materials'                   => 'Tên sản phẩm',
        'sales_inventory_issue_data'          => 'Tên sản phẩm',
        'sales_warehouse_export_invoices'     => 'Khách hàng',
        'raw_material_production_issue_data'  => 'Tên NVL',
        // warehouse_outbound
        'stock_exports'                       => 'Tên sản phẩm',
        // accounting
        'transactions'                        => 'transaction_name',
        'accounting_transaction_mapping'      => 'transaction_name',
        'accounting_transaction_entry'        => 'Nghiệp vụ',
        // cash_transactions
        'cash_transactions'                   => 'description',
        // production_staff
        'production_plans'                    => 'Tên sản phẩm',
        'pre_production_notes'                => 'Tên sản phẩm',
        'additional_tasks'                    => 'description',
        'product_info_basic'                  => 'Tên sản phẩm',
        // production_formula
        'product_recipe_notes'                => 'Tên sản phẩm',
        'product_batch_recipes'                => 'Tên sản phẩm',
        'product_batch_recipe_items'          => 'Tên NVL',
        'material_images'                     => 'Tên NVL',
    ];
}

/** Bản đồ FK → bảng chính + cột tên. Khóa = tên cột FK. */
function cdb_fk_map()
{
    return [
        'product_id'      => ['table' => 'products',                       'name' => 'product_name',     'label' => 'Tên sản phẩm'],
        'material_id'     => ['table' => 'material_information',           'name' => 'material_name',    'label' => 'Tên NVL'],
        'supplier_id'     => ['table' => 'suppliers',                      'name' => 'supplier_name',    'label' => 'Nhà cung cấp'],
        'customer_id'     => ['table' => 'customers',                      'name' => 'name',             'label' => 'Khách hàng'],
        'category_id'     => ['table' => 'product_categories',            'name' => 'category_name',    'label' => 'Nhóm SP'],
        'mapping_id'      => ['table' => 'accounting_transaction_mapping', 'name' => 'transaction_name', 'label' => 'Nghiệp vụ'],
    ];
}

/** Whitelist bảng được phép xem (chống dump bảng tùy ý). */
function cdb_allowed_tables()
{
    return [
        // inventory_receiving
        'stock_imports', 'stock_import_invoices', 'warehouse_receipts', 'stock_import_purchase_costs',
        'raw_material_purchase_data', 'material_inventory', 'material_purchase_prices', 'material_information',
        'purchase_price_changes',
        // inventory_management
        'purchased_finished_product_data', 'product_purchase_prices', 'finished_goods_inventory', 'products',
        'sales_returns', 'finished_product_production_data', 'production_materials', 'production_costs_daily',
        'production_receipts', 'product_materials', 'sales_inventory_issue_data', 'sales_warehouse_export_invoices',
        'raw_material_production_issue_data',
        // warehouse_outbound
        'stock_exports', 'transactions',
        // accounting — bút toán đã ghi (transactions) + định khoản mẫu (mapping/entry)
        'accounting_transaction_mapping', 'accounting_transaction_entry',
        // cash_transactions
        'cash_transactions',
        // production_staff (Lập KHSX / Bản KHSX cho nhân viên)
        'production_plans', 'pre_production_notes', 'additional_tasks', 'product_info_basic',
        // production_formula (Công thức sản xuất)
        'product_recipe_notes', 'product_batch_recipes', 'product_batch_recipe_items', 'material_images',
    ];
}

/** Chạy query an toàn (không exit khi lỗi) — trả mảng kết quả hoặc null. */
function cdb_safe_fetch($sql)
{
    global $conn;
    $r = @mysqli_query($conn, $sql);
    if (!$r) {
        return null;
    }
    $out = [];
    while ($row = mysqli_fetch_assoc($r)) {
        $out[] = $row;
    }
    mysqli_free_result($r);
    return $out;
}

/** Danh sách cột của bảng (có cache trong 1 request). */
function cdb_table_columns($table)
{
    static $cache = [];
    if (isset($cache[$table])) {
        return $cache[$table];
    }
    $rows = cdb_safe_fetch("SHOW COLUMNS FROM `{$table}`");
    $cols = [];
    if (is_array($rows)) {
        foreach ($rows as $r) {
            $cols[] = $r['Field'];
        }
    }
    return $cache[$table] = $cols;
}

/** Cột thời gian để sắp xếp mới → cũ; ưu tiên thời điểm CẬP NHẬT để phản ánh
 *  "vừa thay đổi"; fallback id. */
function cdb_time_column($cols)
{
    foreach (['last_updated_at', 'updated_at', 'created_at', 'date', 'transaction_date'] as $c) {
        if (in_array($c, $cols, true)) {
            return $c;
        }
    }
    return in_array('id', $cols, true) ? 'id' : ($cols[0] ?? 'id');
}

/**
 * Cấu hình "độ mới theo chuyển động" cho các bảng tồn kho KHÔNG có cột thời gian
 * (material_inventory, finished_goods_inventory). "Vừa thay đổi" được suy từ created_at
 * mới nhất ở các bảng nhập/xuất tham chiếu material_id/product_id tương ứng.
 * Trả [id_col, [[src_table, src_id_col], ...]] hoặc null.
 */
function cdb_recency_sources($table)
{
    // Nguồn chuyển động (nhập/xuất kho) của 1 NGUYÊN VẬT LIỆU: [src_table, src_id_col, src_time_col].
    // Chỉ gồm chuyển động kho/giao dịch — KHÔNG gồm cập nhật giá (master-data).
    $MAT = [
        ['stock_imports',                      'material_id', 'created_at'],
        ['stock_exports',                      'material_id', 'created_at'],
        ['raw_material_purchase_data',         'material_id', 'created_at'],
        ['raw_material_production_issue_data', 'material_id', 'created_at'],
        ['sales_inventory_issue_data',         'material_id', 'created_at'],
    ];
    // Nguồn chuyển động của 1 SẢN PHẨM.
    $PROD = [
        ['stock_imports',                      'product_id', 'created_at'],
        ['stock_exports',                      'product_id', 'created_at'],
        ['finished_product_production_data',   'product_id', 'created_at'],
        ['purchased_finished_product_data',    'product_id', 'created_at'],
        ['sales_inventory_issue_data',         'product_id', 'created_at'],
        ['production_receipts',                'product_id', 'created_at'],
        ['sales_returns',                      'product_id', 'created_at'],
        ['raw_material_production_issue_data', 'product_id', 'created_at'],
    ];

    // Chỉ cấu hình cho các bảng KHÔNG có cột thời gian riêng (suy độ mới từ chuyển động).
    // [match_col_trên_bảng_t, sources]
    $map = [
        'material_inventory'       => ['material_id', $MAT],
        'material_information'      => ['id',          $MAT],   // id của material_information chính là material_id
        'production_materials'      => ['material_id', $MAT],
        'finished_goods_inventory' => ['product_id',  $PROD],
        'products'                 => ['id',           $PROD],  // id của products chính là product_id
        'product_materials'        => ['product_id',  $PROD],
    ];
    return $map[$table] ?? null;
}

/**
 * Sinh ORDER BY (không kèm 'ORDER BY') để đẩy material/product vừa thay đổi lên đầu.
 * Trả null nếu bảng không thuộc nhóm tồn kho cấu hình ở trên.
 */
function cdb_recency_order($table)
{
    $cfg = cdb_recency_sources($table);
    if (!$cfg) {
        return null;
    }
    list($matchCol, $sources) = $cfg;
    // Bảng t phải có cột để đối chiếu (vd material_id / product_id / id).
    if (!in_array($matchCol, cdb_table_columns($table), true)) {
        return null;
    }
    $unions = [];
    foreach ($sources as $s) {
        list($st, $sc, $tc) = $s;
        $cols = cdb_table_columns($st);
        // Chỉ gộp nguồn tồn tại & có đủ cột id + cột thời gian (tránh lỗi SQL).
        if (in_array($sc, $cols, true) && in_array($tc, $cols, true)) {
            $unions[] = "SELECT `{$sc}` AS ref, `{$tc}` AS ts FROM `{$st}`";
        }
    }
    if (empty($unions)) {
        return null;
    }
    $u = implode(" UNION ALL ", $unions);
    // MAX(thời gian) qua mọi chuyển động của entity; NULL (chưa từng chuyển động) xuống cuối.
    return "(SELECT MAX(mv.ts) FROM ({$u}) mv WHERE mv.ref = t.`{$matchCol}`) DESC, t.`id` DESC";
}

/**
 * Preview 1 bảng, PHÂN TRANG THẬT (đi hết toàn bộ bảng) + LỌC theo cột đã cấu hình
 * (nếu bảng có trong cdb_filter_target_map() và $keyword khác rỗng).
 * Trả: [table, columns[], rows[][], total, page, page_size, total_pages, filterable, keyword]
 * hoặc [table, error].
 */
function cdb_preview_table($table, $page = 1, $pageSize = 10, $keyword = '')
{
    $table = trim((string) $table);
    if (!in_array($table, cdb_allowed_tables(), true)) {
        return ['table' => $table, 'error' => 'Bảng không hợp lệ.'];
    }
    $cols = cdb_table_columns($table);
    if (empty($cols)) {
        return ['table' => $table, 'error' => 'Bảng không tồn tại hoặc rỗng cấu trúc.'];
    }

    $fkmap   = cdb_fk_map();
    $timeCol = cdb_time_column($cols);

    $selects = [];
    $exprs   = [];   // biểu thức SQL THẬT song song $headers — dùng để lọc (alias không dùng được ở WHERE)
    $joins   = [];
    $headers = [];   // mỗi phần tử: ['key','label'] hoặc ['key','label','fallback']
    $i = 0;
    foreach ($cols as $col) {
        if (isset($fkmap[$col])) {
            $m     = $fkmap[$col];
            $alias = 'fk' . (++$i);
            $joins[]   = "LEFT JOIN `{$m['table']}` {$alias} ON {$alias}.id = t.`{$col}`";
            $selects[] = "{$alias}.`{$m['name']}` AS `{$col}__name`";
            $selects[] = "t.`{$col}`";
            $headers[] = ['key' => $col . '__name', 'label' => $m['label'], 'fallback' => $col];
            $exprs[]   = "{$alias}.`{$m['name']}`";
        } else {
            $selects[] = "t.`{$col}`";
            $headers[] = ['key' => $col, 'label' => $col];
            $exprs[]   = "t.`{$col}`";
        }
    }

    // Bảng tồn kho không có cột thời gian → sắp theo độ mới của chuyển động
    // (material/product vừa thay đổi lên đầu); còn lại dùng cột thời gian/id.
    $order = cdb_recency_order($table);
    if ($order === null) {
        // Tie-break bằng id DESC: nhiều dòng cùng được ghi/cập nhật trong 1 lượt (vd nhập
        // nhiều NVL trong cùng 1 phiếu → cùng giây trên last_updated_at/created_at) sẽ
        // không có thứ tự xác định nếu chỉ sort theo cột thời gian; id DESC đảm bảo dòng
        // vừa ghi (id lớn hơn) luôn nổi lên trên trong nhóm trùng giờ đó. CHỈ áp dụng khi
        // bảng thật sự có cột `id` — vài bảng (vd product_recipe_notes) lấy PK khác làm
        // định danh (product_id) nên không có cột `id` riêng.
        $hasId = in_array('id', $cols, true);
        if ($timeCol === 'id') {
            $order = "t.`id` DESC";
        } elseif ($hasId) {
            $order = "t.`{$timeCol}` DESC, t.`id` DESC";
        } else {
            $order = "t.`{$timeCol}` DESC";
        }
    }

    // Ô lọc (icon cạnh tiêu đề): chỉ bảng có trong cdb_filter_target_map(), lọc theo
    // ĐÚNG cột đã khai báo (khớp theo nhãn cột đang hiển thị).
    $filterLabel = cdb_filter_target_map()[$table] ?? null;
    $filterExpr  = null;
    if ($filterLabel !== null) {
        foreach ($headers as $idx => $h) {
            if ($h['label'] === $filterLabel) { $filterExpr = $exprs[$idx]; break; }
        }
    }
    $kw    = trim((string) $keyword);
    $where = '';
    if ($filterExpr !== null && $kw !== '') {
        $esc   = function_exists('escape_string') ? escape_string($kw) : addslashes($kw);
        $where = " WHERE {$filterExpr} LIKE '%{$esc}%'";
    }

    $fromJoin = "FROM `{$table}` t " . implode(" ", $joins);

    $countRow = cdb_safe_fetch("SELECT COUNT(*) AS c {$fromJoin}{$where}");
    $total    = ($countRow && isset($countRow[0]['c'])) ? (int) $countRow[0]['c'] : 0;

    $pageSize   = max(1, (int) $pageSize);
    $totalPages = max(1, (int) ceil($total / $pageSize));
    $page       = max(1, min((int) $page, $totalPages));
    $offset     = ($page - 1) * $pageSize;

    $sql = "SELECT " . implode(", ", $selects) . " {$fromJoin}{$where}"
         . " ORDER BY {$order} LIMIT {$pageSize} OFFSET {$offset}";

    $rows = cdb_safe_fetch($sql);
    if ($rows === null) {
        return ['table' => $table, 'error' => 'Không đọc được dữ liệu.'];
    }

    $disp = [];
    foreach ($rows as $r) {
        $line = [];
        foreach ($headers as $h) {
            if (isset($h['fallback'])) {
                $name = $r[$h['key']] ?? null;
                $raw  = $r[$h['fallback']] ?? null;
                $line[] = ($name !== null && $name !== '')
                    ? $name
                    : ($raw !== null && $raw !== '' ? ('#' . $raw) : '');
            } else {
                $v = $r[$h['key']] ?? '';
                $line[] = $v === null ? '' : $v;
            }
        }
        $disp[] = $line;
    }

    $labels = array_map(function ($h) { return $h['label']; }, $headers);
    return [
        'table'       => $table,
        'columns'     => $labels,
        'rows'        => $disp,
        'total'       => $total,
        'page'        => $page,
        'page_size'   => $pageSize,
        'total_pages' => $totalPages,
        'filterable'  => $filterExpr !== null,
        'keyword'     => $kw,
    ];
}

/** Preview nhiều bảng (CSV) — luôn trang 1, không lọc (dùng lúc mở modal lần đầu). */
function cdb_preview_tables($tables_csv, $pageSize = 10)
{
    $names = array_filter(array_map('trim', explode(',', (string) $tables_csv)));
    $out   = [];
    foreach ($names as $t) {
        $out[] = cdb_preview_table($t, 1, $pageSize, '');
    }
    return $out;
}

/**
 * Xử lý AJAX: in JSON rồi exit. Dùng chung cho mọi module controller.
 * 2 chế độ:
 *  - table=<tên>[&page=&keyword=] : đổi trang / lọc riêng 1 bảng (client gọi khi
 *    bấm số trang hoặc gõ ô lọc) -> trả về preview đúng 1 bảng đó.
 *  - tables=CSV : mở modal lần đầu -> trả trang 1 của TỪNG bảng trong danh sách.
 */
function cdb_handle_ajax()
{
    header('Content-Type: application/json; charset=utf-8');
    $table = trim((string) ($_POST['table'] ?? $_GET['table'] ?? ''));
    if ($table !== '') {
        $page = (int) ($_POST['page'] ?? $_GET['page'] ?? 1);
        $kw   = (string) ($_POST['keyword'] ?? $_GET['keyword'] ?? '');
        echo json_encode(['ok' => true, 'data' => [cdb_preview_table($table, $page, 10, $kw)]], JSON_UNESCAPED_UNICODE);
        exit;
    }
    $tables = (string) ($_POST['tables'] ?? $_GET['tables'] ?? '');
    echo json_encode(['ok' => true, 'data' => cdb_preview_tables($tables, 10)], JSON_UNESCAPED_UNICODE);
    exit;
}
