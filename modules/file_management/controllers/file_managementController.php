<?php
// Hint cho IDE (PHP Intelephense) resolve load_* và fm_*.
if (!function_exists('__fm_intelephense_hint_stub')) {
    function __fm_intelephense_hint_stub()
    {
        require_once __DIR__ . '/../../../core/base.php';
        require_once __DIR__ . '/../models/file_managementModel.php';
    }
}

function construct()
{
    load_model('file_management');
}

/** Trả JSON sạch (UTF-8) rồi dừng. */
function fm_json($payload)
{
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($payload, JSON_UNESCAPED_UNICODE);
    exit;
}

function fm_cur_uid()
{
    $uid = fm_uid();
    if ($uid <= 0) fm_json(['success' => false, 'message' => 'Chưa đăng nhập.']);
    return $uid;
}

/* ============================================================
 *  Trang chính
 * ============================================================ */
function file_managerAction()
{
    fm_ensure_tables();
    fm_ensure_view_registered();
    $uid = fm_uid();
    load_view('file_manager', [
        'library'   => fm_my_library($uid),
        'shared'    => fm_shared_with_me($uid),
        'users'     => fm_users($uid),
        'projects'  => fm_my_projects($uid),
    ]);
}

/* ============================================================
 *  AJAX — Đọc lại dữ liệu
 * ============================================================ */
function dataAction()
{
    $uid = fm_cur_uid();
    fm_json([
        'success'  => true,
        'library'  => fm_my_library($uid),
        'shared'   => fm_shared_with_me($uid),
    ]);
}

function folder_contentsAction()
{
    $uid = fm_cur_uid();
    $fid = (int) ($_POST['folder_id'] ?? 0);
    $data = fm_folder_contents($uid, $fid);
    if ($data === null) fm_json(['success' => false, 'message' => 'Không có quyền.']);
    fm_json(['success' => true, 'data' => $data]);
}

function searchAction()
{
    $uid = fm_cur_uid();
    fm_json(['success' => true, 'data' => fm_search($uid, $_POST['keyword'] ?? '')]);
}

/** AJAX: đường dẫn tổ tiên (id) của 1 thư mục — dùng để "đi tới" khi bấm 1 kết quả
 *  tìm kiếm là thư mục (mở hết các thư mục cha rồi cuộn tới đúng vị trí). */
function folder_ancestryAction()
{
    $uid = fm_cur_uid();
    $id = (int) ($_POST['id'] ?? 0);
    $f = db_fetch_row("SELECT owner_id FROM fm_folders WHERE id = $id LIMIT 1");
    if (!$f || (int) $f['owner_id'] !== $uid) fm_json(['success' => false, 'message' => 'Không có quyền.']);
    fm_json(['success' => true, 'chain' => fm_folder_ancestry($id)]);
}

/** Danh sách "File hay dùng" (top click_count), hiển thị giống kiểu tìm kiếm. */
function frequent_filesAction()
{
    $uid = fm_cur_uid();
    fm_json(['success' => true, 'data' => fm_frequent_files($uid, 4)]);
}

/** Đếm 1 lượt click mở file (dùng cho danh sách "File hay dùng"). */
function bump_clickAction()
{
    $uid = fm_cur_uid();
    fm_json(fm_bump_click($uid, $_POST['id'] ?? 0));
}

/* ============================================================
 *  AJAX — Thư mục
 * ============================================================ */
function create_folderAction()
{
    $uid = fm_cur_uid();
    $res = fm_create_folder($uid, $_POST['parent_id'] ?? 0, $_POST['name'] ?? '');
    fm_json($res);
}

function rename_folderAction()
{
    $uid = fm_cur_uid();
    $ok = fm_rename_folder($uid, $_POST['id'] ?? 0, $_POST['name'] ?? '');
    fm_json(['success' => $ok]);
}

function set_folder_colorAction()
{
    $uid = fm_cur_uid();
    fm_json(['success' => fm_set_folder_color($uid, $_POST['id'] ?? 0, $_POST['color'] ?? '')]);
}

function delete_folderAction()
{
    $uid = fm_cur_uid();
    fm_json(['success' => fm_delete_folder($uid, $_POST['id'] ?? 0)]);
}

/* ============================================================
 *  AJAX — File
 * ============================================================ */
function uploadAction()
{
    $uid = fm_cur_uid();
    $folder_id = (int) ($_POST['folder_id'] ?? 0);
    if (empty($_FILES['files'])) fm_json(['success' => false, 'message' => 'Không có tệp nào.']);

    $files = $_FILES['files'];
    $names = (array) $files['name'];
    $saved = [];
    $errors = [];
    $unlimited = permission_is_admin(); // chỉ bỏ giới hạn 50MB/tệp — dung lượng tổng (quota) áp dụng cho MỌI user kể cả admin
    $remaining = fm_quota_remaining($uid); // null chỉ khi hệ thống không đặt quota nào
    for ($i = 0; $i < count($names); $i++) {
        $one = [
            'name'     => $files['name'][$i],
            'type'     => $files['type'][$i],
            'tmp_name' => $files['tmp_name'][$i],
            'error'    => $files['error'][$i],
            'size'     => $files['size'][$i],
        ];
        if ($remaining !== null && (int) $one['size'] > $remaining) {
            $errors[] = $one['name'] . ': vượt định mức lưu trữ còn lại';
            continue;
        }
        $r = fm_store_upload($one, $unlimited);
        if (!$r['ok']) { $errors[] = $r['name'] . ': ' . $r['reason']; continue; }
        $id = fm_add_file($uid, $folder_id, $r);
        if ($id > 0) {
            $saved[] = $id;
            if ($remaining !== null) $remaining -= $r['size'];
        }
    }
    fm_json(['success' => count($saved) > 0, 'saved' => count($saved), 'errors' => $errors]);
}

/** AJAX: tải cả 1 thư mục từ máy tính lên, giữ nguyên cây thư mục con.
 *  Thư mục gốc được chọn trở thành 1 thư mục mới dưới target_folder_id
 *  (target_folder_id=0 → hiện ra như 1 "nhóm file" mới ở cấp gốc). */
function upload_folderAction()
{
    $uid = fm_cur_uid();
    $target = (int) ($_POST['target_folder_id'] ?? 0);
    if ($target > 0) {
        $p = db_fetch_row("SELECT owner_id FROM fm_folders WHERE id = $target LIMIT 1");
        if (!$p || (int) $p['owner_id'] !== $uid) fm_json(['success' => false, 'message' => 'Thư mục đích không hợp lệ.']);
    }
    if (empty($_FILES['files']) || empty($_POST['paths'])) fm_json(['success' => false, 'message' => 'Không có tệp nào.']);

    $files = $_FILES['files'];
    $names = (array) $files['name'];
    $paths = (array) $_POST['paths'];
    if (count($paths) !== count($names)) fm_json(['success' => false, 'message' => 'Dữ liệu không hợp lệ.']);

    $unlimited = permission_is_admin(); // chỉ bỏ giới hạn 50MB/tệp — dung lượng tổng (quota) áp dụng cho MỌI user kể cả admin
    $remaining = fm_quota_remaining($uid); // null chỉ khi hệ thống không đặt quota nào
    $folder_cache = []; // đường dẫn thư mục tương đối -> folder_id đã tạo trong lần tải này
    $saved = [];
    $errors = [];

    for ($i = 0; $i < count($names); $i++) {
        $rel = trim(str_replace('\\', '/', (string) $paths[$i]), '/');
        $segments = array_values(array_filter(explode('/', $rel), function ($s) { return $s !== ''; }));
        array_pop($segments); // bỏ tên file, chỉ giữ các cấp thư mục

        $parent_id = $target;
        $key = '';
        foreach ($segments as $seg) {
            $key .= '/' . $seg;
            if (!isset($folder_cache[$key])) {
                $res = fm_get_or_create_folder($uid, $parent_id, $seg);
                if (empty($res['success'])) break;
                $folder_cache[$key] = (int) $res['id'];
            }
            $parent_id = $folder_cache[$key];
        }

        $one = [
            'name'     => $files['name'][$i],
            'type'     => $files['type'][$i],
            'tmp_name' => $files['tmp_name'][$i],
            'error'    => $files['error'][$i],
            'size'     => $files['size'][$i],
        ];
        if ($remaining !== null && (int) $one['size'] > $remaining) {
            $errors[] = $one['name'] . ': vượt định mức lưu trữ còn lại';
            continue;
        }
        $r = fm_store_upload($one, $unlimited);
        if (!$r['ok']) { $errors[] = $r['name'] . ': ' . $r['reason']; continue; }
        $id = fm_add_file($uid, $parent_id, $r);
        if ($id > 0) {
            $saved[] = $id;
            if ($remaining !== null) $remaining -= $r['size'];
        }
    }
    fm_json(['success' => count($saved) > 0, 'saved' => count($saved), 'errors' => $errors]);
}

function rename_fileAction()
{
    $uid = fm_cur_uid();
    fm_json(['success' => fm_rename_file($uid, $_POST['id'] ?? 0, $_POST['name'] ?? '')]);
}

function delete_fileAction()
{
    $uid = fm_cur_uid();
    fm_json(['success' => fm_delete_file($uid, $_POST['id'] ?? 0)]);
}

/** AJAX: thêm 1 file đính kèm trong Chat vào "Đã lưu từ chia sẻ" (gọi từ #chat-widget). */
function add_from_chatAction()
{
    $uid = fm_cur_uid();
    fm_json(fm_add_from_chat_attachment($uid, $_POST['attachment_id'] ?? 0));
}

/** AJAX: dung lượng đã dùng / giới hạn của user hiện tại. */
function storage_statsAction()
{
    $uid = fm_cur_uid();
    fm_json(['success' => true, 'data' => fm_storage_stats($uid)]);
}

/** AJAX: dung lượng riêng của 1 nhóm file/thư mục (tính cả thư mục con) — để user biết
 *  nhóm nào đang nặng mà dọn dẹp, giải phóng dung lượng. */
function folder_storageAction()
{
    $uid = fm_cur_uid();
    $id = (int) ($_POST['id'] ?? 0);
    $f = db_fetch_row("SELECT owner_id FROM fm_folders WHERE id = $id LIMIT 1");
    if (!$f || (int) $f['owner_id'] !== $uid) fm_json(['success' => false, 'message' => 'Không có quyền.']);
    $s = fm_folder_storage($uid, $id);
    fm_json(['success' => true, 'bytes' => $s['bytes'], 'bytes_human' => fm_human_size($s['bytes']), 'count' => $s['count']]);
}

/** AJAX: danh sách file "chưa đụng" >= N tháng. GET/POST: months */
function stale_filesAction()
{
    $uid = fm_cur_uid();
    $months = (int) ($_GET['months'] ?? $_POST['months'] ?? 3);
    fm_json(['success' => true, 'data' => fm_stale_files($uid, $months)]);
}

/** AJAX: xóa hàng loạt file (dọn dẹp dung lượng). POST: ids[] */
function delete_files_bulkAction()
{
    $uid = fm_cur_uid();
    $ids = $_POST['ids'] ?? [];
    if (is_string($ids)) $ids = json_decode($ids, true) ?: [];
    if (!is_array($ids)) $ids = [];
    $count = fm_delete_files_bulk($uid, array_map('intval', $ids));
    fm_json(['success' => true, 'count' => $count]);
}

function move_fileAction()
{
    $uid = fm_cur_uid();
    fm_json(['success' => fm_move_file($uid, $_POST['id'] ?? 0, $_POST['folder_id'] ?? 0)]);
}

function toggle_starAction()
{
    $uid = fm_cur_uid();
    fm_json(fm_toggle_star($uid, $_POST['type'] ?? 'file', $_POST['id'] ?? 0));
}

function make_copyAction()
{
    $uid = fm_cur_uid();
    fm_json(fm_make_copy($uid, $_POST['id'] ?? 0, $_POST['folder_id'] ?? 0));
}

function reorderAction()
{
    $uid = fm_cur_uid();
    $ids = $_POST['ids'] ?? [];
    if (is_string($ids)) $ids = json_decode($ids, true) ?: [];
    $ok = fm_reorder($uid, $_POST['type'] ?? 'file', $_POST['parent_id'] ?? 0, $ids);
    fm_json(['success' => $ok]);
}

/* ============================================================
 *  AJAX — Chia sẻ với user trong hệ thống
 * ============================================================ */
function share_userAction()
{
    $uid = fm_cur_uid();
    $type = $_POST['item_type'] ?? 'file';
    $id   = (int) ($_POST['item_id'] ?? 0);
    $targets = $_POST['targets'] ?? [];
    if (is_string($targets)) $targets = json_decode($targets, true) ?: [];
    if (!is_array($targets) || !$targets) fm_json(['success' => false, 'message' => 'Chưa chọn người nhận.']);

    $ok = 0; $msg = '';
    foreach ($targets as $t) {
        $res = fm_share_to_user($uid, $type, $id, (int) $t, $uid);
        if (!empty($res['success'])) $ok++;
        else $msg = $res['message'] ?? '';
    }
    fm_json(['success' => $ok > 0, 'shared' => $ok, 'message' => $ok > 0 ? '' : $msg]);
}

function share_listAction()
{
    $uid = fm_cur_uid();
    fm_json(['success' => true, 'data' => fm_share_list($uid, $_POST['item_type'] ?? 'file', $_POST['item_id'] ?? 0)]);
}

function share_respondAction()
{
    $uid = fm_cur_uid();
    fm_json(fm_share_respond($uid, $_POST['share_id'] ?? 0, !empty($_POST['accept'])));
}

function add_to_libraryAction()
{
    $uid = fm_cur_uid();
    fm_json(fm_add_to_library($uid, $_POST['share_id'] ?? 0));
}

function revoke_shareAction()
{
    $uid = fm_cur_uid();
    fm_json(fm_revoke_share($uid, $_POST['share_id'] ?? 0));
}

/** Người nhận tự gỡ 1 mục khỏi "Được chia sẻ với tôi" (khác với revoke_share, do chủ sở hữu gỡ). */
function leave_shareAction()
{
    $uid = fm_cur_uid();
    fm_json(fm_leave_share($uid, $_POST['share_id'] ?? 0));
}

/* ============================================================
 *  AJAX — Danh bạ / dự án
 * ============================================================ */
function project_sessionsAction()
{
    $uid = fm_cur_uid();
    fm_json(['success' => true, 'data' => fm_project_sessions($uid, $_POST['project_id'] ?? 0)]);
}

function share_to_chatAction()
{
    $uid = fm_cur_uid();
    fm_json(fm_share_to_chat($uid, $_POST['file_id'] ?? 0, $_POST['target_id'] ?? 0, $_POST['note'] ?? ''));
}

function share_to_projectAction()
{
    $uid = fm_cur_uid();
    fm_json(fm_share_to_project($uid, $_POST['file_id'] ?? 0, $_POST['project_id'] ?? 0, $_POST['session_id'] ?? 0, $_POST['note'] ?? ''));
}

/* ============================================================
 *  Mở file (tab trình duyệt) — bọc 1 trang HTML có <title> đúng TÊN FILE rồi
 *  nhúng iframe trỏ tới downloadAction(inline=1) bên trong.
 *  Lý do: nếu mở thẳng URL file PDF, Chrome lấy tiêu đề tab từ metadata
 *  Title NHÚNG SẴN TRONG file PDF (ví dụ lúc "Print to PDF" từ 1 trang có
 *  <title> khác) chứ KHÔNG dùng tên file/Content-Disposition — nên tab hiện
 *  sai tên dù file đã đổi tên. Bọc qua trang trung gian này để tab luôn khớp
 *  đúng tên file hiện tại.
 * ============================================================ */
function openAction()
{
    $uid = fm_uid();
    if ($uid <= 0) { http_response_code(403); echo 'Chưa đăng nhập.'; exit; }
    $id = (int) ($_GET['id'] ?? 0);
    $t = fm_download_target($uid, $id);
    if (!$t) { http_response_code(404); echo 'Không tìm thấy hoặc không có quyền.'; exit; }

    $mime = (string) $t['mime'];
    $previewable = strpos($mime, 'image/') === 0 || strpos($mime, 'text/') === 0 || $mime === 'application/pdf';
    $dlUrl = '?mod=file_management&controllers=file_management&action=download&id=' . $id . '&inline=1';
    if (!$previewable) {
        header('Location: ' . $dlUrl);
        exit;
    }

    if (ob_get_level()) ob_end_clean();
    header('Content-Type: text/html; charset=utf-8');
    echo '<!DOCTYPE html><html><head><meta charset="UTF-8">'
        . '<title>' . htmlspecialchars($t['name'], ENT_QUOTES, 'UTF-8') . '</title>'
        . '<style>html,body{margin:0;height:100%;}iframe{border:0;width:100%;height:100%;display:block;}</style>'
        . '</head><body><iframe src="' . htmlspecialchars($dlUrl, ENT_QUOTES, 'UTF-8') . '"></iframe></body></html>';
    exit;
}

/* ============================================================
 *  Tải xuống / xem (stream, kiểm tra quyền)
 * ============================================================ */
function downloadAction()
{
    $uid = fm_uid();
    if ($uid <= 0) { http_response_code(403); echo 'Chưa đăng nhập.'; exit; }
    $t = fm_download_target($uid, (int) ($_GET['id'] ?? 0));
    if (!$t) { http_response_code(404); echo 'Không tìm thấy hoặc không có quyền.'; exit; }

    $inline = !empty($_GET['inline']);
    if (ob_get_level()) ob_end_clean();
    header('Content-Type: ' . ($t['mime'] !== '' ? $t['mime'] : 'application/octet-stream'));
    header('Content-Length: ' . $t['size']);
    $disp = $inline ? 'inline' : 'attachment';
    $fname = rawurlencode($t['name']);
    header("Content-Disposition: $disp; filename=\"" . str_replace('"', '', $t['name']) . "\"; filename*=UTF-8''$fname");
    readfile($t['path']);
    exit;
}
