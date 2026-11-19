<?php
defined('APPPATH') OR exit('Không được quyền truy cập phần này');

function construct()
{
    load_model('cash_transactions');
}

/** AJAX: Check Database — preview nội dung các bảng bị tác động (dùng chung). */
function check_databaseAction()
{
    require_once __DIR__ . '/../../../libraries/check_database.php';
    cdb_handle_ajax();
}

/** Helper: trả JSON sạch rồi dừng. */
function ct_json($payload)
{
    while (ob_get_level() > 0) { ob_end_clean(); }
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($payload, JSON_UNESCAPED_UNICODE);
    exit;
}

/** Đọc je-card payload từ POST: je_entries (JSON, đa bút toán) + je_debit/credit/amount (entry đầu, tương thích). */
function ct_je_from_post()
{
    $entries = [];
    if (isset($_POST['je_entries'])) {
        $decoded = json_decode((string) $_POST['je_entries'], true);
        if (is_array($decoded)) $entries = $decoded;
    }
    return [
        'entries' => $entries,
        'debit'   => $_POST['je_debit']  ?? '',
        'credit'  => $_POST['je_credit'] ?? '',
        'amount'  => $_POST['je_amount'] ?? 0,
    ];
}

function cash_transactionsAction()
{
    load_view('cash_transactions', [
        'history' => ct_get_history(100),
    ]);
}

/** GET/POST: bút toán gợi ý theo Phân loại (Thu/Chi) + Tài khoản (TM/TG) gần nhất. */
function recent_journalAction()
{
    $type    = $_POST['transaction_type'] ?? $_GET['transaction_type'] ?? '';
    $account = $_POST['account_name']     ?? $_GET['account_name']     ?? '';
    ct_json(['success' => true, 'data' => ct_recent_journal_entries($type, $account)]);
}

/** GET/POST: bút toán đã ghi của 1 phiếu (giữ Giá trị) — nạp khi bấm "Sửa". */
function journal_entriesAction()
{
    $id = (int) ($_POST['id'] ?? $_GET['id'] ?? 0);
    ct_json(['success' => true, 'data' => ct_journal_entries($id)]);
}

/** GET/POST: danh sách nghiệp vụ đã nhập (distinct) theo Phân loại + Tài khoản — cho dropdown right-click chọn. */
function journal_name_optionsAction()
{
    $type    = $_POST['transaction_type'] ?? $_GET['transaction_type'] ?? '';
    $account = $_POST['account_name']     ?? $_GET['account_name']     ?? '';
    ct_json(['success' => true, 'data' => ct_journal_name_options($type, $account)]);
}

/** GET: trả lịch sử thu chi (JSON) để JS refresh. */
function get_historyAction()
{
    ct_json(['success' => true, 'data' => ct_get_history(100)]);
}

/** GET: trả 1 phiếu thu chi để render chế độ Sửa. */
function get_transactionAction()
{
    $id  = (int) ($_POST['id'] ?? $_GET['id'] ?? 0);
    $row = ct_get($id);
    if (!$row) ct_json(['success' => false, 'message' => 'Không tìm thấy phiếu.']);
    ct_json(['success' => true, 'data' => ct_format_history_row($row)]);
}

/** POST: ghi 1 phiếu thu chi mới. */
function recordAction()
{
    permission_require_can_edit('cash_transactions', 'cash_transactions', 'cash_transactions');
    $data = [
        'description'      => $_POST['description']      ?? '',
        'transaction_type' => $_POST['transaction_type'] ?? '',
        'account_name'     => $_POST['account_name']     ?? '',
        'account_number'   => $_POST['account_number']   ?? '',
        'amount'           => $_POST['amount']           ?? 0,
        'created_at'       => $_POST['created_at']       ?? '',
    ];
    $id = ct_record($data, ct_je_from_post());
    if ($id <= 0) {
        ct_json(['success' => false, 'message' => 'Dữ liệu không hợp lệ — kiểm tra Diễn giải, Phân loại, Tài khoản, Số tiền (và Số tài khoản nếu chọn TG).']);
    }
    ct_json(['success' => true, 'id' => $id, 'history' => ct_get_history(100)]);
}

/** POST: sửa 1 phiếu thu chi. */
function editAction()
{
    permission_require_can_edit('cash_transactions', 'cash_transactions', 'cash_transactions');
    $id   = (int) ($_POST['id'] ?? 0);
    $data = [
        'description'      => $_POST['description']      ?? '',
        'transaction_type' => $_POST['transaction_type'] ?? '',
        'account_name'     => $_POST['account_name']     ?? '',
        'account_number'   => $_POST['account_number']   ?? '',
        'amount'           => $_POST['amount']           ?? 0,
        'created_at'       => $_POST['created_at']       ?? '',
    ];
    $ok = ct_update($id, $data, ct_je_from_post());
    if (!$ok) {
        ct_json(['success' => false, 'message' => 'Không cập nhật được — kiểm tra dữ liệu nhập.']);
    }
    ct_json(['success' => true, 'history' => ct_get_history(100)]);
}

/** POST: xóa 1 phiếu thu chi. */
function deleteAction()
{
    permission_require_can_edit('cash_transactions', 'cash_transactions', 'cash_transactions');
    $id = (int) ($_POST['id'] ?? 0);
    if ($id <= 0) ct_json(['success' => false, 'message' => 'Thiếu id.']);
    ct_delete($id);
    ct_json(['success' => true, 'history' => ct_get_history(100)]);
}

/* =====================================================================================
 *  ĐỀ NGHỊ MUA ĐỒ (view cash_transactions/purchase_request) — nghiệp vụ: libraries/purchase_request.php
 *
 *  2 NƠI dùng chung thư viện:
 *   · "Nhập thu chi"  → xây DANH SÁCH ĐỀ NGHỊ (nút Thêm/Gỡ DS đề nghị + "QL danh sách đề nghị")
 *                        → quyền ghi = quyền sửa của view cash_transactions.
 *   · "Đề nghị mua đồ" → lập đề nghị (mọi người được gán view) + duyệt/gom/đặt mua (admin)
 *                        → quyền ghi = quyền sửa của view purchase_request.
 *  Các action AJAX KHÔNG nằm trong tbl_views nên guard cấp trang bỏ qua chúng → tự kiểm quyền ở đây.
 * ===================================================================================== */

function pr_boot()
{
    require_once __DIR__ . '/../../../libraries/purchase_request.php';
    pr_ensure_tables();
}

/** Người dùng có vào được ít nhất 1 trong các view (cash_transactions | purchase_request) — admin luôn được. */
function pr_guard_access(array $views)
{
    $user = permission_current_user();
    if (!$user) ct_json(['ok' => false, 'message' => 'Chưa đăng nhập.']);
    if (permission_is_admin($user)) return $user;
    foreach ($views as $v) {
        $view = permission_find_view('cash_transactions', 'cash_transactions', $v);
        if ($view && permission_user_has_view((int) $user['id'], (int) $view['id'])) return $user;
    }
    ct_json(['ok' => false, 'message' => 'Bạn chưa được cấp quyền dùng chức năng này.']);
}

/** Chặn ghi nếu user đang ở chế độ "chỉ xem" của view đó. Trả JSON {ok:false} (thư viện permission_* dùng khoá 'success'). */
function pr_guard_edit($view_action)
{
    if (!permission_user_can_edit('cash_transactions', 'cash_transactions', $view_action)) {
        ct_json(['ok' => false, 'message' => 'Bạn chỉ có quyền xem, không thể ghi/sửa dữ liệu.']);
    }
}

/**
 * Thao tác trên DANH SÁCH hạng mục (thêm / đổi hình / gỡ) dùng chung cho 2 màn: "Nhập thu chi" và "Đề nghị mua đồ"
 * (người lập đề nghị tự thêm món mới ngay trong màn đề nghị) → cho phép khi user CÓ ÍT NHẤT MỘT trong 2 view
 * và view đó KHÔNG ở chế độ "chỉ xem". Admin luôn được.
 */
function pr_guard_edit_any($user)
{
    if (permission_is_admin($user)) return;
    foreach (['cash_transactions', 'purchase_request'] as $v) {
        $view = permission_find_view('cash_transactions', 'cash_transactions', $v);
        if ($view && permission_user_has_view((int) $user['id'], (int) $view['id'])
            && permission_user_can_edit('cash_transactions', 'cash_transactions', $v)) return;
    }
    ct_json(['ok' => false, 'message' => 'Bạn chỉ có quyền xem, không thể ghi/sửa dữ liệu.']);
}

/** Quản lý MỤC CHI (thêm/sửa/xóa) vẫn thuộc "Nhập thu chi": admin hoặc người có view đó (không ở chế độ chỉ xem). */
function pr_can_manage_categories($user)
{
    if (permission_is_admin($user)) return true;
    $view = permission_find_view('cash_transactions', 'cash_transactions', 'cash_transactions');
    return $view && permission_user_has_view((int) $user['id'], (int) $view['id'])
        && permission_user_can_edit('cash_transactions', 'cash_transactions', 'cash_transactions');
}

/** Trang "Đề nghị mua đồ" — thiết kế cho điện thoại, dựng sẵn danh sách + số đếm, phần còn lại nạp bằng AJAX. */
function purchase_requestAction()
{
    pr_boot();
    $user = permission_current_user();
    $is_approver = pr_is_approver($user);
    $tab = (string) ($_GET['tab'] ?? '');
    if (!in_array($tab, ['new', 'mine', 'review'], true)) $tab = '';
    if ($tab === 'review' && !$is_approver) $tab = 'mine';

    // Thông tin đơn vị trên tờ phiếu: DÙNG CHUNG với phiếu xuất/nhập kho (print_settings, app_settings 'print_receipt.*'),
    // nên sửa tên/địa chỉ công ty ở một nơi là mọi phiếu cùng đổi.
    require_once __DIR__ . '/../../../libraries/print_settings.php';
    $ps = print_settings_get();

    load_view('purchase_request', [
        'print'       => ['company_name' => (string) ($ps['company_name'] ?? ''), 'company_address' => (string) ($ps['company_address'] ?? '')],
        'catalog'     => pr_catalog(),
        'counts'      => pr_counts((int) $user['id'], $is_approver),
        'is_approver' => $is_approver,
        'can_edit'    => permission_user_can_edit('cash_transactions', 'cash_transactions', 'purchase_request'),
        'can_manage_cats' => pr_can_manage_categories($user),
        'start_tab'   => $tab,
        'me'          => ['id' => (int) $user['id'], 'name' => pr_user_name($user['id'])],
    ]);
}

/* ---------- Danh sách đề nghị (catalog) ---------- */

/** GET: toàn bộ mục chi + hạng mục (bộ chọn + màn QL). */
function pr_catalogAction()
{
    pr_boot();
    pr_guard_access(['cash_transactions', 'purchase_request']);
    ct_json(['ok' => true, 'catalog' => pr_catalog()]);
}

/** POST: thêm/sửa tên mục chi. POST: id (0=mới), name */
function pr_category_saveAction()
{
    pr_boot();
    pr_guard_access(['cash_transactions']);
    pr_guard_edit('cash_transactions');
    $res = pr_category_save((int) ($_POST['id'] ?? 0), (string) ($_POST['name'] ?? ''));
    if (!empty($res['ok'])) $res['catalog'] = pr_catalog();
    ct_json($res);
}

/** POST: xóa mục chi (chỉ khi rỗng). POST: id */
function pr_category_deleteAction()
{
    pr_boot();
    pr_guard_access(['cash_transactions']);
    pr_guard_edit('cash_transactions');
    $res = pr_category_delete((int) ($_POST['id'] ?? 0));
    if (!empty($res['ok'])) $res['catalog'] = pr_catalog();
    ct_json($res);
}

/**
 * POST multipart: thêm/sửa hạng mục. id (0=mới), category_id, name, source_cash_id, image (file), remove_image.
 * source_cash_id > 0 = thêm TỪ MỘT DÒNG "Nhập thu chi": phiếu phải tồn tại và chưa có hạng mục nào.
 */
function pr_item_saveAction()
{
    pr_boot();
    $user = pr_guard_access(['cash_transactions', 'purchase_request']);
    pr_guard_edit_any($user);

    $src = (int) ($_POST['source_cash_id'] ?? 0);
    $id  = (int) ($_POST['id'] ?? 0);
    if ($src > 0 && $id === 0) {
        if (!ct_get($src)) ct_json(['ok' => false, 'message' => 'Không tìm thấy phiếu thu chi.']);
        if (pr_item_id_by_cash($src) > 0) ct_json(['ok' => false, 'message' => 'Phiếu này đã được thêm vào danh sách đề nghị rồi.']);
    }
    $res = pr_item_save(
        $id, (int) ($_POST['category_id'] ?? 0), (string) ($_POST['name'] ?? ''), $src,
        $_FILES['image'] ?? null, !empty($_POST['remove_image']), (int) $user['id']
    );
    if (!empty($res['ok'])) $res['catalog'] = pr_catalog();
    ct_json($res);
}

/**
 * POST multipart: đổi/gỡ HÌNH của 1 hạng mục có sẵn (bấm hình trên card ở màn "Đề nghị mua đồ"). id, image (file) | remove_image.
 * Dùng được cho người có view "Đề nghị mua đồ" lẫn "Nhập thu chi" (không cần quyền sửa tên/mục chi) —
 * nhưng người đang ở chế độ "chỉ xem" của view mình được cấp thì không ghi được.
 */
function pr_item_imageAction()
{
    pr_boot();
    $user = pr_guard_access(['cash_transactions', 'purchase_request']);
    pr_guard_edit_any($user);

    $id  = (int) ($_POST['id'] ?? 0);
    $cur = pr_item_get($id);
    if (!$cur) ct_json(['ok' => false, 'message' => 'Không tìm thấy hạng mục (có thể đã bị gỡ khỏi danh sách).']);

    $res = pr_item_save($id, (int) $cur['category_id'], (string) $cur['name'], 0,
        $_FILES['image'] ?? null, !empty($_POST['remove_image']), (int) $user['id']);
    if (!empty($res['ok'])) $res['catalog'] = pr_catalog();
    ct_json($res);
}

/** POST: gỡ hạng mục khỏi danh sách. POST: id */
function pr_item_deleteAction()
{
    pr_boot();
    $user = pr_guard_access(['cash_transactions', 'purchase_request']);
    pr_guard_edit_any($user);
    $res = pr_item_delete((int) ($_POST['id'] ?? 0));
    if (!empty($res['ok'])) $res['catalog'] = pr_catalog();
    ct_json($res);
}

/** POST: nút "Gỡ DS đề nghị" trên 1 dòng thu chi. POST: cash_id */
function pr_item_remove_by_cashAction()
{
    pr_boot();
    pr_guard_access(['cash_transactions']);
    pr_guard_edit('cash_transactions');
    $iid = pr_item_id_by_cash((int) ($_POST['cash_id'] ?? 0));
    $res = $iid > 0 ? pr_item_delete($iid) : ['ok' => true];
    if (!empty($res['ok'])) $res['catalog'] = pr_catalog();
    ct_json($res);
}

/* ---------- Đề nghị ---------- */

/** GET: gợi ý cho 1 hạng mục — 2 lần đặt mua gần nhất + đề nghị khác đang chờ. GET: id, exclude */
function pr_item_infoAction()
{
    pr_boot();
    pr_guard_access(['purchase_request']);
    $info = pr_item_info((int) ($_GET['id'] ?? 0), (int) ($_GET['exclude'] ?? 0));
    ct_json(['ok' => true] + $info);
}

/** POST: lưu nháp / gửi đề nghị. POST: payload (JSON {id,is_urgent,note,lines[]}), submit (0|1) */
function pr_request_saveAction()
{
    pr_boot();
    $user = pr_guard_access(['purchase_request']);
    pr_guard_edit('purchase_request');
    $payload = json_decode((string) ($_POST['payload'] ?? ''), true);
    if (!is_array($payload)) ct_json(['ok' => false, 'message' => 'Dữ liệu đề nghị không hợp lệ.']);
    $res = pr_request_save($user, $payload, !empty($_POST['submit']));
    if (!empty($res['ok'])) $res['counts'] = pr_counts((int) $user['id'], pr_is_approver($user));
    ct_json($res);
}

/** GET: 1 đơn (chủ đơn hoặc người duyệt). GET: id */
function pr_request_getAction()
{
    pr_boot();
    $user = pr_guard_access(['purchase_request']);
    $r = pr_request_get((int) ($_GET['id'] ?? 0));
    if (!$r || ((int) $r['user_id'] !== (int) $user['id'] && !pr_is_approver($user))) {
        ct_json(['ok' => false, 'message' => 'Không tìm thấy đề nghị.']);
    }
    ct_json(['ok' => true, 'request' => $r]);
}

/** GET: đơn của tôi (phân trang 10 đơn/trang). GET: page (mặc định 1; vượt quá thì lùi về trang cuối). */
function pr_my_requestsAction()
{
    pr_boot();
    $user = pr_guard_access(['purchase_request']);
    $mine = pr_my_requests((int) $user['id'], (int) ($_GET['page'] ?? 1), 10);
    ct_json(['ok' => true, 'requests' => $mine['requests'],
             'total' => $mine['total'], 'page' => $mine['page'], 'pages' => $mine['pages'], 'per_page' => $mine['per_page'],
             'counts' => pr_counts((int) $user['id'], pr_is_approver($user))]);
}

/** POST: hủy đơn đã gửi. POST: id */
function pr_request_cancelAction()
{
    pr_boot();
    $user = pr_guard_access(['purchase_request']);
    pr_guard_edit('purchase_request');
    $res = pr_request_cancel($user, (int) ($_POST['id'] ?? 0));
    $res['counts'] = pr_counts((int) $user['id'], pr_is_approver($user));
    ct_json($res);
}

/** POST: xóa đơn nháp / bị từ chối / đã hủy. POST: id */
function pr_request_deleteAction()
{
    pr_boot();
    $user = pr_guard_access(['purchase_request']);
    pr_guard_edit('purchase_request');
    $res = pr_request_delete($user, (int) ($_POST['id'] ?? 0));
    $res['counts'] = pr_counts((int) $user['id'], pr_is_approver($user));
    ct_json($res);
}

/* ---------- Duyệt (admin) ---------- */

/**
 * GET: hàng đợi duyệt. GET: mode = submitted (mặc định, không phân trang) | done (phân trang 10 mục/trang), page.
 * mode=done trả thêm 'entries': mỗi phần tử là 1 PHIẾU GOM ({kind:'order', order:{...}}) hoặc 1 đề nghị bị từ chối ({kind:'request', request:{...}}).
 */
function pr_review_listAction()
{
    pr_boot();
    $user = pr_guard_access(['purchase_request']);
    if (!pr_is_approver($user)) ct_json(['ok' => false, 'message' => 'Bạn không có quyền duyệt đề nghị.']);
    $mode = (($_GET['mode'] ?? '') === 'done') ? 'done' : 'submitted';
    $res  = pr_review_list($mode, (int) ($_GET['page'] ?? 1), 10);
    $out  = ['ok' => true, 'requests' => $res['requests'],
             'total' => $res['total'], 'page' => $res['page'], 'pages' => $res['pages'], 'per_page' => $res['per_page'],
             'counts' => pr_counts((int) $user['id'], true)];
    if (isset($res['entries'])) $out['entries'] = $res['entries'];
    ct_json($out);
}

/** POST: từ chối đề nghị. POST: id, reason */
function pr_request_rejectAction()
{
    pr_boot();
    $user = pr_guard_access(['purchase_request']);
    pr_guard_edit('purchase_request');
    $res = pr_request_reject($user, (int) ($_POST['id'] ?? 0), (string) ($_POST['reason'] ?? ''));
    $res['counts'] = pr_counts((int) $user['id'], pr_is_approver($user));
    ct_json($res);
}

/** POST: xác nhận gửi đặt mua các đơn đã gom. POST: request_ids (JSON mảng), adjust (JSON [{line_id,qty,dropped}]) */
function pr_order_confirmAction()
{
    pr_boot();
    $user = pr_guard_access(['purchase_request']);
    pr_guard_edit('purchase_request');
    $ids    = json_decode((string) ($_POST['request_ids'] ?? '[]'), true);
    $adjust = json_decode((string) ($_POST['adjust'] ?? '[]'), true);
    $res = pr_order_confirm($user, is_array($ids) ? $ids : [], is_array($adjust) ? $adjust : []);
    $res['counts'] = pr_counts((int) $user['id'], pr_is_approver($user));
    ct_json($res);
}
