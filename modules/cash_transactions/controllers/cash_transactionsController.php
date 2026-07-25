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
