<?php
defined('APPPATH') OR exit('Không được quyền truy cập phần này');

/* =====================================================================
 *  OFFICE (Docs + Sheets) — controller
 *  URL: ?mod=office&controllers=office&action=<act>
 *  - docs/sheets: danh sách (đăng ký tbl_views).
 *  - editor: workspace soạn thảo (KHÔNG đăng ký tbl_views, guard theo
 *    quyền xem/sửa của từng tài liệu, giống pattern project_management).
 *  - còn lại: AJAX (JSON).
 * =====================================================================*/

function construct()
{
    load_model('office');
    office_ensure_tables();
    office_ensure_view_registered();
}

function office_json($payload)
{
    while (ob_get_level() > 0) { ob_end_clean(); }
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($payload, JSON_UNESCAPED_UNICODE);
    exit;
}

function office_cur_uid()
{
    $uid = office_uid();
    if ($uid <= 0) office_json(['success' => false, 'message' => 'Chưa đăng nhập.']);
    return $uid;
}

/* ============================================================
 *  VIEW: danh sách Docs / Sheets
 * ============================================================ */
function office_list_view($type)
{
    $uid = office_uid();
    load_view($type === 'sheet' ? 'sheets_list' : 'docs_list', [
        'type'   => $type,
        'mine'   => $uid > 0 ? office_list_mine($uid, $type) : [],
        'shared' => $uid > 0 ? office_list_shared_with_me($uid, $type) : [],
        'byme'   => $uid > 0 ? office_list_shared_by_me($uid, $type) : [],
        'users'  => $uid > 0 ? office_users($uid) : [],
    ]);
}

function docsAction()  { office_list_view('doc'); }
function sheetsAction() { office_list_view('sheet'); }

/* ============================================================
 *  VIEW: Editor (dùng chung cho doc & sheet)
 * ============================================================ */
function editorAction()
{
    $uid = office_uid();
    $id = (int) ($_GET['id'] ?? 0);
    $doc = $id > 0 ? office_open($uid, $id) : null;
    load_view('editor', [
        'doc' => $doc,
        'id'  => $id,
        'me'  => permission_current_user(),
    ]);
}

/* ============================================================
 *  AJAX — Danh sách
 * ============================================================ */
function listAction()
{
    $uid = office_cur_uid();
    $type = office_type_ok($_GET['type'] ?? $_POST['type'] ?? 'doc');
    office_json([
        'success' => true,
        'mine'    => office_list_mine($uid, $type),
        'shared'  => office_list_shared_with_me($uid, $type),
        'byme'    => office_list_shared_by_me($uid, $type),
    ]);
}

/* ============================================================
 *  AJAX — CRUD tài liệu
 * ============================================================ */
function createAction()
{
    $uid = office_cur_uid();
    $type = office_type_ok($_POST['type'] ?? 'doc');
    $doc = office_create($uid, $type, $_POST['title'] ?? '');
    office_json($doc ? ['success' => true, 'doc' => $doc] : ['success' => false]);
}

function renameAction()
{
    $uid = office_cur_uid();
    office_json(['success' => office_rename($uid, $_POST['id'] ?? 0, $_POST['title'] ?? '')]);
}

function deleteAction()
{
    $uid = office_cur_uid();
    office_json(['success' => office_delete($uid, $_POST['id'] ?? 0)]);
}

function star_toggleAction()
{
    $uid = office_cur_uid();
    office_json(office_toggle_star($uid, $_POST['id'] ?? 0));
}

/* ============================================================
 *  AJAX — Mở / lưu nội dung
 * ============================================================ */
function getAction()
{
    $uid = office_cur_uid();
    $id = (int) ($_GET['id'] ?? $_POST['id'] ?? 0);
    $doc = office_open($uid, $id);
    if (!$doc) office_json(['success' => false, 'message' => 'Không tìm thấy hoặc không có quyền.']);
    office_ping_presence($uid, $id);
    office_json(['success' => true, 'doc' => $doc, 'active_users' => office_active_users($uid, $id)]);
}

function saveAction()
{
    $uid = office_cur_uid();
    $id = (int) ($_POST['id'] ?? 0);
    $res = office_save($uid, $id, $_POST['content'] ?? '', $_POST['base_version'] ?? 0, $_POST['title'] ?? null);
    office_json($res);
}

function save_version_manualAction()
{
    $uid = office_cur_uid();
    office_json(office_save_version_manual($uid, $_POST['id'] ?? 0, $_POST['note'] ?? ''));
}

/** Poll nhẹ: presence + trạng thái đang nhập + version + tiêu đề hiện tại (FE dùng để
 *  đồng bộ "X đang nhập...", đổi tên real-time, và tự tải nội dung mới khi rảnh tay). */
function pollAction()
{
    $uid = office_cur_uid();
    $id = (int) ($_GET['id'] ?? $_POST['id'] ?? 0);
    $doc = office_row($id);
    if (!$doc || !office_can_view($uid, $doc)) office_json(['success' => false]);
    $typing = isset($_POST['typing']) ? (bool) $_POST['typing'] : null;
    office_ping_presence($uid, $id, $typing);
    office_json([
        'success'      => true,
        'version'      => (int) $doc['current_version'],
        'title'        => office_dec($doc['title'] ?? ''),
        'active_users' => office_active_users($uid, $id),
    ]);
}

/* ============================================================
 *  AJAX — Lịch sử phiên bản
 * ============================================================ */
function versionsAction()
{
    $uid = office_cur_uid();
    office_json(['success' => true, 'data' => office_versions($uid, $_GET['id'] ?? $_POST['id'] ?? 0)]);
}

function version_restoreAction()
{
    $uid = office_cur_uid();
    office_json(office_version_restore($uid, $_POST['id'] ?? 0, $_POST['version_id'] ?? 0));
}

/* ============================================================
 *  AJAX — Chia sẻ
 * ============================================================ */
function shareAction()
{
    $uid = office_cur_uid();
    $id = (int) ($_POST['id'] ?? 0);
    $targets = $_POST['targets'] ?? [];
    if (is_string($targets)) $targets = json_decode($targets, true) ?: [];
    if (!is_array($targets) || !$targets) office_json(['success' => false, 'message' => 'Chưa chọn người nhận.']);
    $permission = $_POST['permission'] ?? 'view';
    $ok = 0; $msg = '';
    foreach ($targets as $t) {
        $res = office_share($uid, $id, (int) $t, $permission);
        if (!empty($res['success'])) $ok++;
        else $msg = $res['message'] ?? '';
    }
    office_json(['success' => $ok > 0, 'shared' => $ok, 'message' => $ok > 0 ? '' : $msg]);
}

function share_listAction()
{
    $uid = office_cur_uid();
    office_json(['success' => true, 'data' => office_share_list($uid, $_GET['id'] ?? $_POST['id'] ?? 0)]);
}

function revoke_shareAction()
{
    $uid = office_cur_uid();
    office_json(office_revoke_share($uid, $_POST['share_id'] ?? 0));
}

/** Chủ sở hữu đổi quyền của 1 người đã được chia sẻ. */
function change_permissionAction()
{
    $uid = office_cur_uid();
    office_json(office_change_permission($uid, $_POST['share_id'] ?? 0, $_POST['permission'] ?? 'view'));
}

/** Người NHẬN tự gỡ 1 mục đã được chia sẻ (dọn dẹp danh sách của mình). */
function leave_shareAction()
{
    $uid = office_cur_uid();
    office_json(office_leave_share($uid, $_POST['share_id'] ?? 0));
}

/** Người NHẬN ghim/bỏ ghim 1 mục vào "Thư viện của tôi". */
function toggle_libraryAction()
{
    $uid = office_cur_uid();
    office_json(office_toggle_library($uid, $_POST['share_id'] ?? 0));
}

/** Chia sẻ 1 bản xuất trực tiếp vào Chat (danh bạ). */
function share_to_chatAction()
{
    $uid = office_cur_uid();
    office_json(office_share_to_chat($uid, $_POST['id'] ?? 0, $_POST['target_id'] ?? 0, $_POST['note'] ?? ''));
}

/* ============================================================
 *  AJAX — Danh bạ + Xuất vào Quản lý file
 * ============================================================ */
function usersAction()
{
    $uid = office_cur_uid();
    office_json(['success' => true, 'data' => office_users($uid)]);
}

function export_to_fmAction()
{
    $uid = office_cur_uid();
    office_json(office_export_to_fm($uid, $_POST['id'] ?? 0));
}

/* ============================================================
 *  AJAX — "Trộn file" (mail-merge Docs ⇄ Sheets)
 * ============================================================ */
function mail_mergeAction()
{
    $uid = office_cur_uid();
    office_json(office_mail_merge(
        $uid,
        $_POST['template_id'] ?? 0,
        $_POST['sheet_id'] ?? 0,
        $_POST['title_prefix'] ?? '',
        $_POST['name_col'] ?? '',
        $_POST['ext_sheet_id'] ?? 0,
        $_POST['main_join_col'] ?? '',
        $_POST['ext_join_col'] ?? ''
    ));
}

/** Tải toàn bộ kết quả 1 lượt trộn file thành 1 tệp .zip duy nhất — GET binary streaming, giống
 *  downloadAction() bên dưới, cho phép gọi bằng 1 thẻ <a href="...&ids=1,2,3" download>. */
function mail_merge_zipAction()
{
    $uid = office_uid();
    if ($uid <= 0) { http_response_code(403); echo 'Chưa đăng nhập.'; exit; }
    $ids = array_filter(array_map('intval', explode(',', (string) ($_GET['ids'] ?? ''))));
    if (!$ids) { http_response_code(400); echo 'Thiếu danh sách tệp.'; exit; }

    $body = office_mail_merge_zip_body($uid, $ids);
    if ($body === null) { http_response_code(500); echo 'Không tạo được tệp zip.'; exit; }

    while (ob_get_level() > 0) { ob_end_clean(); }
    header('Content-Type: application/zip');
    header('Content-Length: ' . strlen($body));
    header('X-Content-Type-Options: nosniff');
    header('Content-Disposition: attachment; filename="tron_file_' . date('Ymd_His') . '.zip"');
    echo $body;
    exit;
}

/** "Lưu vào Docs" — kết quả trộn file lúc đầu chỉ là bản xem trước (office_mail_merge() không
 *  ghi DB), bấm nút này mới thật sự tạo ofc_documents. */
function mail_merge_saveAction()
{
    $uid = office_cur_uid();
    $items = json_decode((string) ($_POST['items'] ?? '[]'), true);
    if (!is_array($items)) $items = [];
    office_json(office_mail_merge_save($uid, $items));
}

/** "Tải xuống hết" khi CHƯA bấm "Lưu vào Docs" (chưa có id thật) — POST vì payload (nội dung đầy
 *  đủ từng file) có thể lớn, GET query string không đủ chỗ. Gọi từ JS bằng cách submit 1 <form
 *  method="post" target="_blank"> ẩn (không phải fetch+Blob) để trình duyệt tự tải file qua
 *  Content-Disposition như mọi action tải khác trong module, không cần thêm code tạo Blob. */
function mail_merge_zip_previewAction()
{
    $uid = office_uid();
    if ($uid <= 0) { http_response_code(403); echo 'Chưa đăng nhập.'; exit; }
    $items = json_decode((string) ($_POST['items'] ?? '[]'), true);
    if (!is_array($items) || !$items) { http_response_code(400); echo 'Thiếu dữ liệu.'; exit; }

    $body = office_mail_merge_zip_preview($items);
    if ($body === null) { http_response_code(500); echo 'Không tạo được tệp zip.'; exit; }

    while (ob_get_level() > 0) { ob_end_clean(); }
    header('Content-Type: application/zip');
    header('Content-Length: ' . strlen($body));
    header('X-Content-Type-Options: nosniff');
    header('Content-Disposition: attachment; filename="tron_file_' . date('Ymd_His') . '.zip"');
    echo $body;
    exit;
}

/* ============================================================
 *  Tải xuống trực tiếp: Docs -> .doc (HTML mở bằng Word), Sheets -> .xlsx thật
 * ============================================================ */
function downloadAction()
{
    $uid = office_uid();
    if ($uid <= 0) { http_response_code(403); echo 'Chưa đăng nhập.'; exit; }
    $id = (int) ($_GET['id'] ?? 0);
    $doc = office_row($id);
    if (!$doc) { http_response_code(404); echo 'Không tìm thấy.'; exit; }

    $result = $doc['type'] === 'sheet' ? office_download_sheet_xlsx($uid, $id) : office_download_doc($uid, $id);
    if (!$result) { http_response_code(403); echo 'Không có quyền hoặc không tải được.'; exit; }

    // Dọn sạch MỌI lớp output buffer đang mở (không chỉ 1 lớp) — nếu còn sót lại, nội dung
    // buffer cũ có thể bị in ra TRƯỚC/SAU phần nhị phân, làm hỏng cấu trúc tệp .xlsx/.doc.
    while (ob_get_level() > 0) { ob_end_clean(); }
    // filename= (fallback) chỉ nên là ASCII theo RFC 6266 — tên tiếng Việt có dấu để hết ở
    // filename*=UTF-8''..., tránh trình duyệt/hệ điều hành đọc sai phần mở rộng khi gặp byte lạ.
    $asciiTitle = preg_replace('/[^\x20-\x7E]/', '_', $result['title']);
    $asciiTitle = str_replace(['"', '/', '\\'], '_', $asciiTitle);
    $fname = rawurlencode($result['title'] . '.' . $result['ext']);
    header('Content-Type: ' . $result['mime']);
    header('Content-Length: ' . strlen($result['body']));
    header('X-Content-Type-Options: nosniff');
    header("Content-Disposition: attachment; filename=\"{$asciiTitle}.{$result['ext']}\"; filename*=UTF-8''{$fname}");
    echo $result['body'];
    exit;
}

/* ============================================================
 *  AJAX — Chèn ảnh vào Docs
 * ============================================================ */
function upload_imageAction()
{
    office_cur_uid();
    if (empty($_FILES['file'])) office_json(['ok' => false, 'reason' => 'Không có tệp.']);
    office_json(office_store_image($_FILES['file']));
}
