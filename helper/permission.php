<?php

/* =====================================================================
 *  PHÂN QUYỀN THEO VIEW
 *  ---------------------------------------------------------------------
 *  Mô hình: admin tick view nào cho 1 tài khoản → tài khoản đó chỉ
 *  thấy / truy cập được những view đó.
 *
 *  - tbl_users.role = 'admin'  : bỏ qua mọi kiểm tra, thấy hết.
 *  - tbl_views                 : danh mục view gán được (xem migration).
 *  - tbl_user_views            : bản ghi gán user <-> view.
 *
 *  Quy ước: chỉ các action CÓ trong tbl_views mới bị kiểm soát truy cập.
 *  Các action phụ trợ (AJAX update/create/delete, upload...) KHÔNG nằm
 *  trong danh mục nên không bị chặn — chúng là thao tác con của 1 view.
 * ===================================================================== */

/** Bảo đảm session đã khởi động (router/guard có thể chạy trước controller). */
function permission_boot_session()
{
    if (session_status() === PHP_SESSION_NONE) {
        session_start();
    }
    // Đồng bộ cookie -> session (giống check_auth()).
    if (isset($_COOKIE['is_login']) && empty($_SESSION['is_login'])) {
        $_SESSION['is_login']   = $_COOKIE['is_login'];
        $_SESSION['user_login'] = $_COOKIE['user_login'] ?? '';
    }
}

/** Lấy bản ghi user đang đăng nhập (theo username trong session). Cache tĩnh. */
function permission_current_user()
{
    static $cached = false;
    if ($cached !== false) {
        return $cached;
    }
    permission_boot_session();
    $username = $_SESSION['user_login'] ?? '';
    if ($username === '') {
        return $cached = null;
    }
    $esc = escape_string($username);
    $row = db_fetch_row("SELECT * FROM tbl_users WHERE username = '{$esc}' LIMIT 1");
    return $cached = ($row ?: null);
}

/** $user có phải admin không. */
function permission_is_admin($user = null)
{
    if ($user === null) {
        $user = permission_current_user();
    }
    return $user && ($user['role'] ?? 'user') === 'admin';
}

/* =====================================================================
 *  TIỆN ÍCH TRÊN HEADER (lịch / việc cần làm / điểm nhắc) — không phải
 *  trang điều hướng thật, chỉ là 3 dòng "ảo" trong tbl_views để admin
 *  tick quyền qua ĐÚNG màn "Phân quyền người dùng" hiện có. Bị loại khỏi
 *  sidebar/trang chủ ở permission_filter_menu_groups() (group_label
 *  riêng, không map icon). Avatar + chuông + nút sáng/tối trên header
 *  LUÔN cố định, không cần phân quyền.
 * ===================================================================== */

define('PERMISSION_HEADER_WIDGET_GROUP', 'TIỆN ÍCH HEADER');

/** Tạo 3 dòng "view ảo" cho lịch/todo/điểm nhắc nếu chưa có (1 lần / request). */
function permission_ensure_header_widget_views()
{
    static $done = false;
    if ($done) return;
    $done = true;

    $items = [
        ['action' => 'calendar',        'label' => 'Lịch (trên header)',        'sort' => 9001],
        ['action' => 'todo',            'label' => 'Việc cần làm (trên header)', 'sort' => 9002],
        ['action' => 'reminder_points', 'label' => 'Điểm nhắc (trên header)',    'sort' => 9003],
    ];
    foreach ($items as $it) {
        $exists = db_fetch_row(
            "SELECT id FROM tbl_views WHERE module = 'header_widget' AND controller = 'header_widget'
             AND action = '" . escape_string($it['action']) . "' LIMIT 1"
        );
        if ($exists) continue;
        db_insert('tbl_views', [
            'module'      => 'header_widget',
            'controller'  => 'header_widget',
            'action'      => $it['action'],
            'label'       => $it['label'],
            'group_label' => PERMISSION_HEADER_WIDGET_GROUP,
            'sort'        => $it['sort'],
            'is_active'   => 1,
        ]);
    }
}

/** Icon/lịch/todo/điểm nhắc trên header có hiện cho $user không (admin hoặc được tick quyền). */
function permission_header_widget_visible($user, $widget_action)
{
    if (!$user) return false;
    if (permission_is_admin($user)) return true;
    $uid = (int) ($user['id'] ?? 0);
    if ($uid <= 0) return false;
    permission_ensure_header_widget_views();
    return in_array($uid, permission_user_ids_for_view('header_widget', 'header_widget', $widget_action), true);
}

/** Tìm 1 view trong danh mục theo (module, controller, action). null nếu không có. */
function permission_find_view($module, $controller, $action)
{
    $m = escape_string($module);
    $c = escape_string($controller);
    $a = escape_string($action);
    $row = db_fetch_row(
        "SELECT * FROM tbl_views
         WHERE module = '{$m}' AND controller = '{$c}' AND action = '{$a}'
           AND is_active = 1 LIMIT 1"
    );
    return $row ?: null;
}

/** 1 user đã được gán view_id này chưa. */
function permission_user_has_view($user_id, $view_id)
{
    $uid = (int) $user_id;
    $vid = (int) $view_id;
    return db_num_rows(
        "SELECT 1 FROM tbl_user_views WHERE user_id = {$uid} AND view_id = {$vid} LIMIT 1"
    ) > 0;
}

/** Mảng id các view đã gán cho 1 user. */
function permission_granted_view_ids($user_id)
{
    $uid  = (int) $user_id;
    $rows = db_fetch_array("SELECT view_id FROM tbl_user_views WHERE user_id = {$uid}");
    return array_map(static fn($r) => (int) $r['view_id'], $rows);
}

/** Toàn bộ danh mục view (active), đã gom nhóm theo group_label, giữ thứ tự sort. */
/**
 * Đăng ký các view mà MODULE KHÔNG TỰ BOOTSTRAP ĐƯỢC.
 *
 * Vòng luẩn quẩn: co_ensure_view_registered() chỉ chạy khi ai đó mở /customer_orders/orders,
 * mà muốn mở thì phải thấy link trong sidebar — link chỉ hiện khi view đã có trong tbl_views.
 * Kết quả: view không bao giờ xuất hiện, kể cả ở màn Phân quyền.
 * Vì thế phải ghi từ ĐÂY — nơi chạy mỗi khi dựng menu / mở màn phân quyền.
 *
 * INSERT IGNORE dựa vào UNIQUE KEY uq_view (module, controller, action) nên chạy lại vô hại.
 * Cột `controller` PHẢI bằng đúng tiền tố tên file <controller>Controller.php, sai một chữ là
 * permission_find_view() trả null -> guard FAIL-OPEN (ai cũng vào được) + header mất tiêu đề.
 */
function permission_ensure_static_views()
{
    static $done = false;
    if ($done) return;
    $done = true;
    if (db_num_rows("SHOW TABLES LIKE 'tbl_views'") <= 0) return;

    db_query(
        "INSERT IGNORE INTO tbl_views (module, controller, action, label, group_label, sort, is_active)
         VALUES ('customer_orders', 'customer_orders', 'orders', 'Đơn hàng', 'QUẢN LÝ ĐƠN HÀNG', 150, 1)"
    );

    /* 3 tab của "Bán hàng - nhà máy" tách thành 3 mục menu cấp 2 (thay cho thanh tab trong
       trang). Trước đây chỉ order_factory có trong danh mục; 2 view kia không đăng ký nên
       vừa không hiện ở sidebar, vừa lọt guard FAIL-OPEN (ai đăng nhập cũng vào được). */
    db_query(
        "INSERT IGNORE INTO tbl_views (module, controller, action, label, group_label, sort, is_active) VALUES
         ('sell_factory', 'sell_factory', 'production_forecast', 'KHSX dự kiến',      'BÁN HÀNG - NHÀ MÁY', 71, 1),
         ('sell_factory', 'sell_factory', 'order_history',       'Lịch sử đặt hàng',  'BÁN HÀNG - NHÀ MÁY', 72, 1)"
    );

    /* Đổi nhãn mục đầu cho khớp bộ 3 (chỉ khi vẫn là nhãn seed gốc — tránh đè tên admin đã
       tự sửa). Dùng UPDATE vì INSERT IGNORE ở trên không sửa được dòng đã có. */
    db_query(
        "UPDATE tbl_views SET label = 'Tồn thành phẩm nhà máy'
         WHERE module = 'sell_factory' AND controller = 'sell_factory' AND action = 'order_factory'
           AND label = 'Đặt hàng nhà máy'"
    );
}

function permission_all_views_grouped()
{
    permission_ensure_static_views();
    $rows = db_fetch_array(
        "SELECT * FROM tbl_views WHERE is_active = 1 ORDER BY sort, id"
    );
    $groups = [];
    foreach ($rows as $r) {
        $groups[$r['group_label']][] = $r;
    }
    return $groups;
}

/**
 * Menu của 1 user: admin -> toàn bộ view; user thường -> chỉ view được gán.
 * Trả về mảng [group_label => [rows]] đã giữ thứ tự sort.
 */
function permission_menu_for_user($user)
{
    permission_ensure_static_views();
    if (permission_is_admin($user)) {
        return permission_all_views_grouped();
    }
    $uid  = (int) ($user['id'] ?? 0);
    if ($uid <= 0) {
        return [];
    }
    $rows = db_fetch_array(
        "SELECT v.* FROM tbl_views v
         JOIN tbl_user_views uv ON uv.view_id = v.id AND uv.user_id = {$uid}
         WHERE v.is_active = 1
         ORDER BY v.sort, v.id"
    );
    $groups = [];
    foreach ($rows as $r) {
        $groups[$r['group_label']][] = $r;
    }
    // Nhóm "Quản trị hệ thống" cho admin phó được thêm trực tiếp ở sidebar + trang chủ
    // (manage_permissions không nằm trong tbl_views nên không gom từ đây).
    return $groups;
}

/**
 * Đổi TÊN HIỂN THỊ của nhóm menu (không đổi group_label trong DB, không đụng icon-map).
 * Dùng ở sidebar + trang chủ để hiển thị nhãn mới mà không phải migrate dữ liệu.
 */
function permission_group_display_label($label)
{
    static $relabel = [
        'NHẬP KHO'             => 'NHẬP SẢN XUẤT',
        'NHẬP NGUYÊN VẬT LIỆU' => 'MUA HÀNG',
        'XUẤT KHO'             => 'BÁN HÀNG',
        'ADMIN - NHÀ MÁY'      => 'DỮ LIỆU NHÀ MÁY',
    ];
    return $relabel[$label] ?? $label;
}

/**
 * Bỏ khỏi menu chính các view đã có ở nhóm "QUẢN TRỊ HỆ THỐNG" (tránh trùng).
 * Hiện tại: manage_user_list (Quản lý người dùng) — chỉ giữ ở Quản trị hệ thống.
 * Không xóa dữ liệu tbl_views nên guard truy cập vẫn nguyên.
 */
function permission_filter_menu_groups($groups)
{
    // Nhóm "view ảo" của tiện ích header (lịch/todo/điểm nhắc) — chỉ để tick quyền ở màn
    // Phân quyền người dùng, KHÔNG phải trang điều hướng thật nên không hiện ở sidebar/trang chủ.
    unset($groups[PERMISSION_HEADER_WIDGET_GROUP]);

    $dups = [
        ['module' => 'admin_factory', 'action' => 'manage_user_list'],
    ];
    foreach ($groups as $g => $items) {
        $items = array_values(array_filter($items, static function ($v) use ($dups) {
            foreach ($dups as $d) {
                if (($v['module'] ?? '') === $d['module'] && ($v['action'] ?? '') === $d['action']) {
                    return false;
                }
            }
            return true;
        }));
        if (empty($items)) {
            unset($groups[$g]);
        } else {
            $groups[$g] = $items;
        }
    }
    return $groups;
}

/**
 * Ghi đè tập view của 1 user = danh sách view_id được chọn.
 * (Xóa hết rồi insert lại — đơn giản & nhất quán.)
 * $view_only_ids: tập con của $view_ids được gán can_edit = 0 (chỉ xem).
 */
function permission_set_user_views($user_id, array $view_ids, array $view_only_ids = [], array $edit_today_ids = [], array $check_db_ids = [])
{
    $uid = (int) $user_id;
    if ($uid <= 0) {
        return false;
    }
    permission_ensure_edit_today_column();
    // PHẢI gọi TRƯỚC db_insert bên dưới: db_insert dựng tên cột từ array_keys, thiếu cột thật
    // trong bảng là câu INSERT lỗi 1054 và giết cả request.
    permission_ensure_check_db_column();
    db_delete('tbl_user_views', "user_id = {$uid}");

    // Lọc về int hợp lệ & loại trùng.
    $ids = array_values(array_unique(array_filter(
        array_map('intval', $view_ids),
        static fn($v) => $v > 0
    )));
    $view_only_set  = array_flip(array_map('intval', $view_only_ids));
    $edit_today_set = array_flip(array_map('intval', $edit_today_ids));
    $check_db_set   = array_flip(array_map('intval', $check_db_ids));
    foreach ($ids as $vid) {
        $only = isset($view_only_set[$vid]);
        db_insert('tbl_user_views', [
            'user_id'  => $uid,
            'view_id'  => $vid,
            'can_edit' => $only ? 0 : 1,
            // "Sửa trong ngày" chỉ có nghĩa KHI ĐANG chỉ-xem: nó là cửa hẹp mở ra từ trạng thái
            // khoá, không phải một mức quyền song song. Tick nó mà không tick "Chỉ xem" thì user
            // vốn đã sửa được mọi ngày — lưu cờ này vào chỉ tạo hiểu nhầm lúc đọc lại dữ liệu.
            'can_edit_today' => ($only && isset($edit_today_set[$vid])) ? 1 : 0,
            // "Database" ĐỘC LẬP với chỉ-xem: user chỉ-xem vẫn có thể được cho phép soi bảng.
            'can_check_db'   => isset($check_db_set[$vid]) ? 1 : 0,
        ]);
    }
    return true;
}

/**
 * Cột `can_edit_today` (bổ sung 5/8/2026) — tạo nếu chưa có, chạy 1 lần/request.
 *
 * Dự án không có hệ migration tự động cho phần helper này; các bảng phân quyền vốn được tạo/vá
 * tại chỗ. Giữ đúng lối đó để cài đặt trên máy mới không phải nhớ chạy SQL tay.
 */
function permission_ensure_edit_today_column()
{
    static $done = false;
    if ($done) {
        return;
    }
    $done = true;
    if (db_num_rows("SHOW TABLES LIKE 'tbl_user_views'") <= 0) {
        return;
    }
    if (db_num_rows("SHOW COLUMNS FROM tbl_user_views LIKE 'can_edit_today'") <= 0) {
        db_query("ALTER TABLE tbl_user_views ADD COLUMN can_edit_today TINYINT(1) NOT NULL DEFAULT 0");
    }
}

/* =====================================================================
 *  QUYỀN "DATABASE" — nút Check database của từng view (7/8/2026)
 *  ---------------------------------------------------------------------
 *  Trước đây nút hiện cho mọi user không phải "chỉ xem". Nay admin tick
 *  từng view mới hiện, và ĐƯỜNG SERVER cũng chặn theo cờ này chứ không
 *  chỉ ẩn nút — ẩn nút mà không chặn thì gõ thẳng URL vẫn đọc được bảng.
 * ===================================================================== */

/** Cột `can_check_db` — tạo nếu chưa có, chạy 1 lần/request. */
function permission_ensure_check_db_column()
{
    static $done = false;
    if ($done) {
        return;
    }
    $done = true;
    if (db_num_rows("SHOW TABLES LIKE 'tbl_user_views'") <= 0) {
        return;
    }
    if (db_num_rows("SHOW COLUMNS FROM tbl_user_views LIKE 'can_check_db'") <= 0) {
        db_query("ALTER TABLE tbl_user_views ADD COLUMN can_check_db TINYINT(1) NOT NULL DEFAULT 0");
        /* BACKFILL 1 LẦN DUY NHẤT — nằm trong nhánh "cột vừa được tạo" nên không bao giờ chạy lại.
           Giữ nguyên hiện trạng cho user đang dùng: ai đang thấy nút (tức không phải chỉ-xem) thì
           vẫn thấy. Không có bước này thì sau khi cập nhật mã, TẤT CẢ user mất nút cùng lúc và
           admin phải đi tick lại từ đầu cho từng người. */
        db_query("UPDATE tbl_user_views SET can_check_db = 1 WHERE can_edit = 1");
    }
}

/** Các view_id user này đang được bật cờ "Database" (để màn Phân quyền tick lại đúng). */
function permission_user_check_db_ids($user_id)
{
    $uid = (int) $user_id;
    if ($uid <= 0) {
        return [];
    }
    permission_ensure_check_db_column();
    $rows = db_fetch_array(
        "SELECT view_id FROM tbl_user_views WHERE user_id = {$uid} AND can_check_db = 1"
    ) ?: [];
    return array_map(static fn($r) => (int) $r['view_id'], $rows);
}

/**
 * User này có được xem Database ở view đó không.
 *
 * FAIL-CLOSED, ngược với permission_user_can_edit() (hàm đó trả true ở các nhánh thoát sớm).
 * An toàn vì mọi view mang nút đều có trong tbl_views, nên user thường vào được trang thì chắc
 * chắn đã có bản ghi tbl_user_views (permission_guard() chặn từ trước).
 */
function permission_can_check_db($module, $controller, $action, $user = null)
{
    if ($user === null) {
        $user = permission_current_user();
    }
    if (permission_is_admin($user)) {
        return true; // admin luôn thấy
    }
    $uid = (int) ($user['id'] ?? 0);
    if ($uid <= 0) {
        return false;
    }
    $view = permission_find_view($module, $controller, $action);
    if (!$view) {
        return false;
    }
    permission_ensure_check_db_column();
    $vid = (int) $view['id'];
    $row = db_fetch_row(
        "SELECT can_check_db FROM tbl_user_views WHERE user_id = {$uid} AND view_id = {$vid} LIMIT 1"
    );
    return $row ? ((int) $row['can_check_db'] === 1) : false;
}

/**
 * Chốt chặn phía SERVER cho endpoint check_database của 1 module.
 * Endpoint được gọi theo dạng /{mod}/check_database nên chỉ biết MODULE, không biết đang đứng ở
 * view nào — vì thế cho qua khi user được bật cờ ở BẤT KỲ view nào thuộc module đó.
 */
function permission_can_check_db_in_module($module, $user = null)
{
    if ($user === null) {
        $user = permission_current_user();
    }
    if (permission_is_admin($user)) {
        return true;
    }
    $uid = (int) ($user['id'] ?? 0);
    if ($uid <= 0) {
        return false;
    }
    permission_ensure_check_db_column();
    $m = escape_string((string) $module);
    return db_num_rows(
        "SELECT 1 FROM tbl_user_views uv
           JOIN tbl_views v ON v.id = uv.view_id
          WHERE uv.user_id = {$uid} AND uv.can_check_db = 1 AND v.module = '{$m}' LIMIT 1"
    ) > 0;
}

/* =====================================================================
 *  "CHỈ XEM" (view-only) — chặn bước ghi/sửa/xoá cuối cùng cho 1 số view
 *  nhập liệu, độc lập với permission_guard() cấp trang ở trên.
 * ===================================================================== */

/** Danh mục 14 view có hỗ trợ "chỉ xem" (khớp module/controller/action trong tbl_views). */
function permission_view_only_capable_keys()
{
    return [
        ['module' => 'inventory_management', 'controller' => 'inventory_management', 'action' => 'dashboard'],
        ['module' => 'inventory_management', 'controller' => 'inventory_management', 'action' => 'investment_products'],
        ['module' => 'inventory_management', 'controller' => 'inventory_management', 'action' => 'other_receipt'],
        ['module' => 'inventory_management', 'controller' => 'inventory_management', 'action' => 'sales_return_receipt'],
        ['module' => 'inventory_receiving',  'controller' => 'inventory_receiving',  'action' => 'row_material_receiving'],
        ['module' => 'inventory_receiving',  'controller' => 'inventory_receiving',  'action' => 'other_row_material_receiving'],
        ['module' => 'warehouse_outbound',   'controller' => 'warehouse_outbound',   'action' => 'sales_delivery_note'],
        ['module' => 'warehouse_outbound',   'controller' => 'warehouse_outbound',   'action' => 'other_warehouse_outbound'],
        ['module' => 'production_formula',   'controller' => 'production_formula',   'action' => 'production_formula'],
        ['module' => 'cash_transactions',    'controller' => 'cash_transactions',    'action' => 'cash_transactions'],
        ['module' => 'admin_factory',        'controller' => 'admin',                'action' => 'manage_product_list'],
        ['module' => 'admin_factory',        'controller' => 'admin',                'action' => 'manage_material_list'],
        ['module' => 'admin_factory',        'controller' => 'admin',                'action' => 'manage_supplier_list'],
        ['module' => 'admin_factory',        'controller' => 'admin',                'action' => 'manage_customer_list'],
        ['module' => 'payroll',              'controller' => 'payroll',              'action' => 'timesheet'],
        ['module' => 'payroll',              'controller' => 'payroll',              'action' => 'payslip'],
    ];
}

/** view_id (trong tbl_views) tương ứng 14 view "chỉ xem" ở trên, đã lọc theo view thực có trong DB. */
function permission_view_only_capable_ids()
{
    $ids = [];
    foreach (permission_view_only_capable_keys() as $k) {
        $v = permission_find_view($k['module'], $k['controller'], $k['action']);
        if ($v) {
            $ids[] = (int) $v['id'];
        }
    }
    return $ids;
}

/**
 * User này còn được ghi/sửa dữ liệu ở view (module,controller,action) không.
 * true nếu: admin, hoặc view không tồn tại/user chưa được gán (giữ hành vi cũ),
 * false chỉ khi user đã được gán view đó VÀ can_edit = 0 (chỉ xem).
 */
function permission_user_can_edit($module, $controller, $action, $user = null)
{
    if ($user === null) {
        $user = permission_current_user();
    }
    if (permission_is_admin($user)) {
        return true;
    }
    $uid = (int) ($user['id'] ?? 0);
    if ($uid <= 0) {
        return true;
    }
    $view = permission_find_view($module, $controller, $action);
    if (!$view) {
        return true;
    }
    $vid = (int) $view['id'];
    $row = db_fetch_row("SELECT can_edit FROM tbl_user_views WHERE user_id = {$uid} AND view_id = {$vid} LIMIT 1");
    if (!$row) {
        return true;
    }
    return (int) $row['can_edit'] !== 0;
}

/** Ngược lại của permission_user_can_edit() — dùng ở view PHP để ẩn nút "Check Database". */
function permission_is_view_only($module, $controller, $action, $user = null)
{
    return !permission_user_can_edit($module, $controller, $action, $user);
}

/**
 * Chặn action ghi/sửa/xoá nếu user đang ở chế độ "chỉ xem" cho view (module,controller,action).
 * Gọi ngay dòng đầu action; nếu bị chặn sẽ echo JSON [$response_key => false, message] rồi exit.
 */
function permission_require_can_edit($module, $controller, $action, $response_key = 'success')
{
    if (permission_user_can_edit($module, $controller, $action)) {
        return true;
    }
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode([$response_key => false, 'message' => 'Bạn chỉ có quyền xem, không thể ghi/sửa dữ liệu.']);
    exit;
}

/* =====================================================================
 *  "CHỈ SỬA TRONG NGÀY" (bổ sung 5/8/2026)
 *
 *  User chốt: "tích đồng thời Chỉ xem VÀ Cho phép chỉnh sửa trong ngày là chỉ được nhập và sửa
 *  trong ngày hôm đó — nghĩa là ngày hiện tại; còn các ngày lịch sử trước đó chỉ xem, không sửa."
 *
 *  Vì vậy đây KHÔNG phải mức quyền thứ ba, mà là một CỬA HẸP mở ra từ trạng thái "chỉ xem":
 *      can_edit = 0 và can_edit_today = 1  →  ghi/sửa được, nhưng chỉ trên dữ liệu của HÔM NAY.
 * ===================================================================== */

/**
 * Danh mục view hỗ trợ "chỉ sửa trong ngày".
 *
 * HẸP CÓ CHỦ Ý — hiện chỉ "Nhập sản lượng sản xuất" (inventory_management/dashboard), là view mà
 * user yêu cầu uỷ quyền cho nhân viên nhập. Chỉ thêm view vào đây SAU KHI đường ghi của nó đã
 * thật sự gọi permission_require_can_edit_on_date() với đúng ngày của bản ghi — bày ô tick ra mà
 * backend không chặn theo ngày là hứa suông, admin tưởng đã khoá lịch sử trong khi chưa khoá gì.
 */
function permission_edit_today_capable_keys()
{
    return [
        ['module' => 'inventory_management', 'controller' => 'inventory_management', 'action' => 'dashboard'],
    ];
}

/** view_id tương ứng danh mục trên, lọc theo view thực có trong DB. */
function permission_edit_today_capable_ids()
{
    $ids = [];
    foreach (permission_edit_today_capable_keys() as $k) {
        $v = permission_find_view($k['module'], $k['controller'], $k['action']);
        if ($v) {
            $ids[] = (int) $v['id'];
        }
    }
    return $ids;
}

/** Các view_id user này đang được bật cờ "sửa trong ngày" (để màn Phân quyền tick lại đúng). */
function permission_user_edit_today_ids($user_id)
{
    $uid = (int) $user_id;
    if ($uid <= 0) {
        return [];
    }
    permission_ensure_edit_today_column();
    $rows = db_fetch_array(
        "SELECT view_id FROM tbl_user_views WHERE user_id = {$uid} AND can_edit_today = 1"
    ) ?: [];
    return array_map(static fn($r) => (int) $r['view_id'], $rows);
}

/** User này có đang ở chế độ "chỉ xem, nhưng được sửa dữ liệu trong ngày" không. */
function permission_user_can_edit_today($module, $controller, $action, $user = null)
{
    if ($user === null) {
        $user = permission_current_user();
    }
    if (permission_is_admin($user)) {
        return false; // admin sửa được mọi ngày — không rơi vào chế độ hẹp này
    }
    $uid = (int) ($user['id'] ?? 0);
    if ($uid <= 0) {
        return false;
    }
    $view = permission_find_view($module, $controller, $action);
    if (!$view) {
        return false;
    }
    permission_ensure_edit_today_column();
    $vid = (int) $view['id'];
    $row = db_fetch_row(
        "SELECT can_edit, can_edit_today FROM tbl_user_views
          WHERE user_id = {$uid} AND view_id = {$vid} LIMIT 1"
    );
    if (!$row) {
        return false;
    }
    return (int) $row['can_edit'] === 0 && (int) $row['can_edit_today'] === 1;
}

/**
 * Chốt chặn GHI có xét NGÀY của bản ghi. Dùng thay permission_require_can_edit() ở những action
 * thao tác trên dữ liệu gắn với một ngày cụ thể.
 *
 * $record_date: 'Y-m-d' (hoặc chuỗi datetime bất kỳ strtotime đọc được). Null/rỗng = coi như
 * hôm nay (tạo mới không kèm ngày).
 *
 * Thứ tự xét — đọc kỹ trước khi sửa:
 *   1. Sửa được mọi ngày (admin / chưa bị giới hạn)  → cho qua.
 *   2. Không sửa được VÀ không có cửa "trong ngày"    → chặn như cũ.
 *   3. Có cửa "trong ngày": ngày bản ghi = hôm nay    → cho qua; khác hôm nay → chặn kèm câu
 *      giải thích ĐÚNG lý do (không dùng chung câu "chỉ có quyền xem", vì họ vẫn ghi được hôm nay
 *      — báo sai lý do là người dùng đi hỏi admin cấp thêm quyền một cách vô ích).
 */
function permission_require_can_edit_on_date(
    $module, $controller, $action, $record_date = null, $response_key = 'success'
) {
    if (permission_user_can_edit($module, $controller, $action)) {
        return true;
    }

    if (permission_user_can_edit_today($module, $controller, $action)) {
        $d = trim((string) $record_date);
        $day = ($d === '') ? date('Y-m-d') : date('Y-m-d', strtotime($d));
        if ($day === date('Y-m-d')) {
            return true;
        }
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode([
            $response_key => false,
            'message'     => 'Bạn chỉ được nhập/sửa dữ liệu của ngày hôm nay ('
                             . date('d/m/Y') . '). Dữ liệu ngày '
                             . date('d/m/Y', strtotime($day)) . ' chỉ xem được.',
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }

    header('Content-Type: application/json; charset=utf-8');
    echo json_encode([$response_key => false, 'message' => 'Bạn chỉ có quyền xem, không thể ghi/sửa dữ liệu.']);
    exit;
}

/**
 * Id các user CÓ quyền vào 1 view (admin + user được gán view đó).
 * Dùng để đẩy chuông tới đúng nhóm phụ trách (vd phía nhà máy = branch_orders).
 */
function permission_user_ids_for_view($module, $controller, $action)
{
    $ids = [];
    foreach ((db_fetch_array("SELECT id FROM tbl_users WHERE role = 'admin'") ?: []) as $r) {
        $ids[] = (int) $r['id'];
    }
    $view = permission_find_view($module, $controller, $action);
    if ($view) {
        $vid = (int) $view['id'];
        foreach ((db_fetch_array("SELECT user_id FROM tbl_user_views WHERE view_id = $vid") ?: []) as $r) {
            $ids[] = (int) $r['user_id'];
        }
    }
    return array_values(array_unique(array_filter($ids, static fn($v) => $v > 0)));
}

/* =====================================================================
 *  ADMIN PHÓ (deputy) — admin trưởng bổ nhiệm; được phân quyền cho user
 *  khác trong phạm vi: 1 số nhóm chức năng (group_label) + 1 số nhóm phân
 *  loại user (classification). Mọi lần admin phó lưu phân quyền -> đẩy
 *  chuông cho admin trưởng.
 * ===================================================================== */

/** Tạo các bảng admin phó (1 lần / request). */
function permission_ensure_deputy_schema()
{
    static $done = false;
    if ($done) return;
    $done = true;
    db_query("CREATE TABLE IF NOT EXISTS admin_deputies (
        user_id    INT(11) NOT NULL,
        granted_by INT(11) NOT NULL DEFAULT 0,
        status     VARCHAR(12) NOT NULL DEFAULT 'pending',
        created_at DATETIME NOT NULL,
        PRIMARY KEY (user_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci");
    // Bảng cũ có thể thiếu cột status (pending/accepted) → bổ sung 1 lần.
    $col = db_fetch_row("SHOW COLUMNS FROM admin_deputies LIKE 'status'");
    if (!$col) db_query("ALTER TABLE admin_deputies ADD status VARCHAR(12) NOT NULL DEFAULT 'accepted' AFTER granted_by");
    db_query("CREATE TABLE IF NOT EXISTS admin_deputy_groups (
        user_id     INT(11) NOT NULL,
        group_label VARCHAR(100) NOT NULL,
        PRIMARY KEY (user_id, group_label)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci");
    db_query("CREATE TABLE IF NOT EXISTS admin_deputy_classes (
        user_id           INT(11) NOT NULL,
        classification_id INT(11) NOT NULL,
        PRIMARY KEY (user_id, classification_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci");
}

/** $user có phải admin phó ĐÃ NHẬN bổ nhiệm không (status='accepted'). */
function permission_is_deputy($user = null)
{
    if ($user === null) $user = permission_current_user();
    $uid = (int) ($user['id'] ?? 0);
    if ($uid <= 0) return false;
    permission_ensure_deputy_schema();
    return db_num_rows("SELECT 1 FROM admin_deputies WHERE user_id = {$uid} AND status = 'accepted' LIMIT 1") > 0;
}

/** Trạng thái bổ nhiệm admin phó của 1 user: 'accepted' | 'pending' | '' (chưa bổ nhiệm). */
function permission_deputy_status($user_id)
{
    permission_ensure_deputy_schema();
    $uid = (int) $user_id;
    if ($uid <= 0) return '';
    $row = db_fetch_row("SELECT status FROM admin_deputies WHERE user_id = {$uid} LIMIT 1");
    return $row ? (string) $row['status'] : '';
}

/** Có lời mời bổ nhiệm đang chờ phản hồi không (cho nút Nhận/Từ chối trên chuông). */
function permission_deputy_is_pending($user_id)
{
    return permission_deputy_status($user_id) === 'pending';
}

/** Đặt trạng thái bổ nhiệm (pending/accepted). */
function permission_set_deputy_status($user_id, $status)
{
    permission_ensure_deputy_schema();
    $uid = (int) $user_id;
    if ($uid <= 0) return false;
    db_update('admin_deputies', ['status' => (string) $status], "user_id = {$uid}");
    return true;
}

/** Các group_label mà admin phó được phép phân quyền. */
function permission_deputy_groups($user_id)
{
    permission_ensure_deputy_schema();
    $uid  = (int) $user_id;
    $rows = db_fetch_array("SELECT group_label FROM admin_deputy_groups WHERE user_id = {$uid}") ?: [];
    return array_map(static fn($r) => (string) $r['group_label'], $rows);
}

/** Các classification_id (nhóm phân loại user) mà admin phó được quản lý. */
function permission_deputy_class_ids($user_id)
{
    permission_ensure_deputy_schema();
    $uid  = (int) $user_id;
    $rows = db_fetch_array("SELECT classification_id FROM admin_deputy_classes WHERE user_id = {$uid}") ?: [];
    return array_map(static fn($r) => (int) $r['classification_id'], $rows);
}

/** Danh sách admin phó kèm phạm vi (cho UI admin trưởng). */
function permission_deputy_list()
{
    permission_ensure_deputy_schema();
    $rows = db_fetch_array(
        "SELECT d.user_id, d.status, u.fullname, u.username
         FROM admin_deputies d JOIN tbl_users u ON u.id = d.user_id
         ORDER BY u.fullname, u.username"
    ) ?: [];
    foreach ($rows as &$r) {
        $r['user_id']   = (int) $r['user_id'];
        $r['status']    = (string) ($r['status'] ?? 'pending');
        $r['groups']    = permission_deputy_groups($r['user_id']);
        $r['class_ids'] = permission_deputy_class_ids($r['user_id']);
    }
    unset($r);
    return $rows;
}

/**
 * Bổ nhiệm / cập nhật phạm vi 1 admin phó.
 * Mới bổ nhiệm -> status='pending' (chờ user Nhận). Đã tồn tại -> giữ nguyên status.
 * Trả về true nếu là LẦN ĐẦU bổ nhiệm (để biết có cần đẩy lời mời).
 */
function permission_set_deputy($user_id, array $group_labels, array $class_ids, $granted_by = 0)
{
    permission_ensure_deputy_schema();
    $uid = (int) $user_id;
    if ($uid <= 0) return false;
    $is_new = db_num_rows("SELECT 1 FROM admin_deputies WHERE user_id = {$uid} LIMIT 1") === 0;
    if ($is_new) {
        db_insert('admin_deputies', [
            'user_id' => $uid, 'granted_by' => (int) $granted_by,
            'status' => 'pending', 'created_at' => date('Y-m-d H:i:s'),
        ]);
    }
    db_delete('admin_deputy_groups', "user_id = {$uid}");
    foreach (array_unique($group_labels) as $g) {
        $g = trim((string) $g);
        if ($g === '') continue;
        db_insert('admin_deputy_groups', ['user_id' => $uid, 'group_label' => $g]);
    }
    db_delete('admin_deputy_classes', "user_id = {$uid}");
    foreach (array_unique(array_map('intval', $class_ids)) as $cid) {
        if ($cid <= 0) continue;
        db_insert('admin_deputy_classes', ['user_id' => $uid, 'classification_id' => $cid]);
    }
    return $is_new;
}

/** Gỡ bổ nhiệm admin phó. */
function permission_remove_deputy($user_id)
{
    permission_ensure_deputy_schema();
    $uid = (int) $user_id;
    if ($uid <= 0) return false;
    db_delete('admin_deputies', "user_id = {$uid}");
    db_delete('admin_deputy_groups', "user_id = {$uid}");
    db_delete('admin_deputy_classes', "user_id = {$uid}");
    return true;
}

/** Tất cả view_id thuộc các group_label cho trước. */
function permission_view_ids_in_groups(array $group_labels)
{
    $labels = array_values(array_filter(array_map(static function ($g) {
        return escape_string((string) $g);
    }, $group_labels), static fn($s) => $s !== ''));
    if (!$labels) return [];
    $in   = "'" . implode("','", $labels) . "'";
    $rows = db_fetch_array("SELECT id FROM tbl_views WHERE group_label IN ({$in}) AND is_active = 1") ?: [];
    return array_map(static fn($r) => (int) $r['id'], $rows);
}

/** classification_id của 1 user (0 nếu chưa gán / chưa có cột). */
function permission_user_classification_id($user_id)
{
    $uid = (int) $user_id;
    if ($uid <= 0) return 0;
    $col = db_fetch_row("SHOW COLUMNS FROM tbl_users LIKE 'classification_id'");
    if (!$col) return 0;
    $row = db_fetch_row("SELECT classification_id FROM tbl_users WHERE id = {$uid} LIMIT 1");
    return $row ? (int) $row['classification_id'] : 0;
}

/** Admin phó này có được quản lý user mục tiêu không (theo nhóm phân loại). */
function permission_deputy_can_manage_user($deputy_id, $target_id)
{
    $allowed = permission_deputy_class_ids($deputy_id);
    if (!$allowed) return false;
    return in_array(permission_user_classification_id($target_id), $allowed, true);
}

/** Cho phép admin trưởng HOẶC admin phó (dùng đầu các action phân quyền). */
function permission_require_admin_or_deputy($as_json = false)
{
    $user = permission_current_user();
    if (permission_is_admin($user) || permission_is_deputy($user)) {
        return $user;
    }
    if ($as_json) {
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['ok' => false, 'message' => 'Bạn không có quyền phân quyền.']);
        exit;
    }
    permission_forbidden('Khu vực phân quyền');
}

/* =====================================================================
 *  GUARD — chốt chặn truy cập, gọi từ core/router.php cho mọi request.
 * ===================================================================== */

function permission_guard()
{
    permission_boot_session();

    $module     = get_module();
    $controller = get_controller();
    $action     = get_action();

    // 1) Module auth (đăng nhập/đăng ký/quên mật khẩu/đăng xuất): luôn cho qua.
    if ($module === 'auth') {
        return;
    }

    // 2) Bắt buộc đăng nhập cho mọi thứ còn lại.
    if (empty($_SESSION['is_login'])) {
        permission_redirect_login();
    }
    $user = permission_current_user();
    if (!$user) {
        permission_redirect_login();
    }

    // 3) Admin: toàn quyền.
    if (permission_is_admin($user)) {
        return;
    }

    // 3b) Admin phó: được vào màn phân quyền + quản lý người dùng (giới hạn phạm vi ở controller).
    if ($module === 'admin_factory'
        && in_array($action, ['manage_permissions', 'user_views_get', 'user_views_save', 'manage_user_list'], true)
        && permission_is_deputy($user)) {
        return;
    }

    // 4) Trang chủ (home) luôn cho user đã đăng nhập (menu điều hướng).
    if ($module === 'home') {
        return;
    }

    // 4b) Người được uỷ quyền GỬI BÁO CÁO TỰ ĐỘNG: cho vào trang chụp daily_dashboard dù
    //     chưa được gán quyền view này — CHỈ khi có ?auto_send=<id> và đúng là delegate đang
    //     hiệu lực (accepted, chưa tạm ngưng). Nới đúng lúc, tự hết hiệu lực theo trạng thái uỷ quyền.
    if ($module === 'report' && $controller === 'report' && $action === 'daily_dashboard' && isset($_GET['auto_send'])) {
        if (!function_exists('ar_is_active_delegate')) {
            require_once __DIR__ . '/../libraries/auto_report.php';
        }
        if (function_exists('ar_is_active_delegate')
            && ar_is_active_delegate((int) $user['id'], (int) $_GET['auto_send'])) {
            return;
        }
    }

    // 5) Action không nằm trong danh mục view (AJAX/phụ trợ): cho qua.
    $view = permission_find_view($module, $controller, $action);
    if (!$view) {
        return;
    }

    // 6) Là view có trong danh mục -> phải được gán mới cho vào.
    if (permission_user_has_view((int) $user['id'], (int) $view['id'])) {
        return;
    }

    permission_forbidden($view['label'] ?? '');
}

/** Chỉ cho admin; dùng đầu các action quản trị phân quyền. */
function permission_require_admin($as_json = false)
{
    $user = permission_current_user();
    if (permission_is_admin($user)) {
        return $user;
    }
    if ($as_json) {
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['ok' => false, 'message' => 'Chỉ admin mới được thao tác.']);
        exit;
    }
    permission_forbidden('Khu vực quản trị');
}

/** Đẩy về trang đăng nhập. */
function permission_redirect_login()
{
    header('Location:?mod=auth&controllers=index&action=login');
    exit;
}

/** Trang "không có quyền" (HTML đơn giản). */
function permission_forbidden($view_label = '')
{
    http_response_code(403);
    $label = htmlspecialchars((string) $view_label, ENT_QUOTES, 'UTF-8');
    echo '<!DOCTYPE html><html lang="vi"><head><meta charset="UTF-8">'
        . '<title>Không có quyền truy cập</title></head><body style="font-family:Arial,sans-serif;text-align:center;padding:60px;">'
        . '<h2 style="color:#b22;">Bạn không có quyền truy cập' . ($label !== '' ? ' chức năng "' . $label . '"' : '') . '</h2>'
        . '<p>Vui lòng liên hệ quản trị viên để được cấp quyền.</p>'
        . '<p><a href="?mod=home&controllers=index&action=index">&larr; Về trang chủ</a></p>'
        . '</body></html>';
    exit;
}
