<?php
defined('APPPATH') OR exit('Không được quyền truy cập phần này');

if (!function_exists('__admin_factory_intelephense_hint_stub')) {
    function __admin_factory_intelephense_hint_stub()
    {
        require_once __DIR__ . '/../../../core/base.php';
        require_once __DIR__ . '/../models/adminModel.php';
    }
}

require_once __DIR__ . '/../../../libraries/warehouse_receipt_invoices.php';
require_once __DIR__ . '/../../../libraries/sendmail.php';       // gửi OTP chuyển quyền admin
require_once __DIR__ . '/../../../libraries/notifications.php';  // đẩy thông báo "chuông" cho admin mới
require_once __DIR__ . '/../../../libraries/user_lifecycle.php'; // "Rời hệ thống" — xóa dữ liệu cá nhân hóa
require_once __DIR__ . '/../../supplier_debt/models/supplier_debtModel.php'; // ảnh thanh toán NCC (sd_*)
require_once __DIR__ . '/../../../libraries/system_settings.php'; // Cài đặt hệ thống (sidebar, chỉ admin trưởng)
require_once __DIR__ . '/../../file_management/models/file_managementModel.php'; // Ưu tiên dung lượng theo user

function construct()
{
    load_model('admin');
}

/* =====================================================================
 *  HÓA ĐƠN (warehouse_receipt_invoices) — upload / xóa theo dòng.
 * =====================================================================*/

/** AJAX: upload nhiều file hóa đơn cho 1 (wr_id, type). FILES: files[] */
function receipt_invoice_uploadAction()
{
    header('Content-Type: application/json; charset=utf-8');
    $wr_id = (int) ($_POST['wr_id'] ?? 0);
    $type  = (string) ($_POST['type'] ?? '');
    $store = (string) ($_POST['store'] ?? '');
    if ($wr_id <= 0 || empty($_FILES['files'])) {
        echo json_encode(['ok' => false, 'message' => 'Thiếu tham số hoặc chưa chọn tệp.']);
        exit;
    }
    // store='supplier_payment' -> ảnh thanh toán NCC (supplier_payment_attachments).
    if ($store === 'supplier_payment') {
        echo json_encode(sd_save_attachments($wr_id, $_FILES['files']), JSON_UNESCAPED_UNICODE);
        exit;
    }
    if (!in_array($type, wri_valid_types(), true)) {
        echo json_encode(['ok' => false, 'message' => 'Loại hóa đơn không hợp lệ.']);
        exit;
    }
    $res = wri_save_uploaded_files($wr_id, $type, $_FILES['files']);
    echo json_encode($res, JSON_UNESCAPED_UNICODE);
    exit;
}

/** AJAX: xóa 1 hóa đơn theo id. */
function receipt_invoice_deleteAction()
{
    header('Content-Type: application/json; charset=utf-8');
    $id    = (int) ($_POST['id'] ?? 0);
    $store = (string) ($_POST['store'] ?? '');
    if ($id <= 0) {
        echo json_encode(['ok' => false, 'message' => 'Thiếu id.']);
        exit;
    }
    if ($store === 'supplier_payment') {
        echo json_encode(['ok' => sd_delete_attachment($id)], JSON_UNESCAPED_UNICODE);
        exit;
    }
    echo json_encode(['ok' => wri_delete($id)], JSON_UNESCAPED_UNICODE);
    exit;
}

/** AJAX: danh sách hóa đơn của 1 (wr_id, type). */
function receipt_invoice_listAction()
{
    header('Content-Type: application/json; charset=utf-8');
    $wr_id = (int) ($_GET['wr_id'] ?? $_POST['wr_id'] ?? 0);
    $type  = (string) ($_GET['type'] ?? $_POST['type'] ?? '');
    echo json_encode(['ok' => true, 'data' => wri_list($wr_id, $type)], JSON_UNESCAPED_UNICODE);
    exit;
}

/* =====================================================================
 *  GIẢI THÍCH GIÁ TRỊ THEO DÒNG ("con mắt") — AJAX JSON.
 * =====================================================================*/

/** AJAX: chi tiết "Giá trị nhập kho" của 1 warehouse_receipts. GET/POST: wr_id */
function purchase_value_detailAction()
{
    header('Content-Type: application/json; charset=utf-8');
    $wr_id = (int) ($_GET['wr_id'] ?? $_POST['wr_id'] ?? 0);
    $data  = admin_get_purchase_value_detail($wr_id);
    if (!$data) {
        echo json_encode(['ok' => false, 'message' => 'Không tìm thấy dữ liệu nguồn cho dòng này.']);
        exit;
    }
    echo json_encode(['ok' => true, 'data' => $data], JSON_UNESCAPED_UNICODE);
    exit;
}

/** AJAX: chi tiết "Giá trị hàng hóa" của 1 đơn bán. GET: customer_id, created_at */
function sales_value_detailAction()
{
    header('Content-Type: application/json; charset=utf-8');
    $customer_id = (int) ($_GET['customer_id'] ?? $_POST['customer_id'] ?? 0);
    $created_at  = (string) ($_GET['created_at'] ?? $_POST['created_at'] ?? '');
    $lines = admin_get_sales_value_detail($customer_id, $created_at);
    echo json_encode(['ok' => true, 'data' => ['lines' => $lines]], JSON_UNESCAPED_UNICODE);
    exit;
}

function manage_product_listAction()
{
    $products   = admin_get_product_list();
    $categories = admin_get_categories();
    $suppliers  = admin_get_suppliers();
    load_view('manage_product_list', [
        'products'   => $products,
        'categories' => $categories,
        'suppliers'  => $suppliers,
    ]);
}

function manage_material_listAction()
{
    $materials = admin_get_material_list();
    $suppliers = admin_get_suppliers();
    load_view('manage_material_list', [
        'materials' => $materials,
        'suppliers' => $suppliers,
    ]);
}

function manage_supplier_listAction()
{
    $suppliers = admin_get_supplier_list();
    load_view('manage_supplier_list', [
        'suppliers' => $suppliers,
    ]);
}

function manage_customer_listAction()
{
    $customers = admin_get_customer_list();
    load_view('manage_customer_list', [
        'customers' => $customers,
    ]);
}

function manage_user_listAction()
{
    $me       = permission_require_admin_or_deputy();
    $is_admin = permission_is_admin($me);
    $users    = admin_get_user_list();

    // Admin phó: chỉ thấy user thuộc nhóm phân loại được giao.
    if (!$is_admin) {
        $allow_class = permission_deputy_class_ids((int) ($me['id'] ?? 0));
        $users = array_values(array_filter($users, static function ($u) use ($allow_class) {
            return in_array((int) ($u['classification_id'] ?? 0), $allow_class, true);
        }));
    }

    // Id admin phó đã nhận (để gắn badge "Quản trị viên" màu vàng nhạt).
    $deputy_ids = array_values(array_map(
        static fn($d) => (int) $d['user_id'],
        array_filter(permission_deputy_list(), static fn($d) => ($d['status'] ?? '') === 'accepted')
    ));
    load_view('manage_user_list', [
        'users'           => $users,
        'status_options'  => admin_user_status_options(),
        'classifications' => $is_admin ? admin_get_user_classifications() : [],
        'is_admin'        => $is_admin,
        'deputy_ids'      => $deputy_ids,
    ]);
}

/**
 * AJAX (POST: user_id, name, allow_create): gán phân loại cho 1 user.
 *  - name là phân loại mới + allow_create=0 -> trả {ok:false, is_new:true} để FE hỏi lưu.
 *  - allow_create=1 -> tạo phân loại mới (nếu chưa có) rồi gán.
 */
function set_user_classificationAction()
{
    header('Content-Type: application/json; charset=utf-8');
    permission_require_admin(true);
    $user_id      = (int) ($_POST['user_id'] ?? 0);
    $name         = (string) ($_POST['name'] ?? '');
    $allow_create = (int) ($_POST['allow_create'] ?? 0) === 1;
    if ($user_id <= 0) {
        echo json_encode(['ok' => false, 'message' => 'Thiếu người dùng.']);
        exit;
    }
    echo json_encode(admin_set_user_classification($user_id, $name, $allow_create));
    exit;
}

/* =====================================================================
 *  PHÂN QUYỀN THEO VIEW — admin tick view cho từng tài khoản.
 * =====================================================================*/

/** Trang gán quyền: chọn 1 user rồi tick các view được phép truy cập. */
function manage_permissionsAction()
{
    $me       = permission_require_admin_or_deputy();
    $is_admin = permission_is_admin($me);
    $groups   = permission_all_views_grouped();
    $users    = admin_get_user_list();

    // Admin phó: chỉ thấy nhóm chức năng & nhóm user thuộc phạm vi được giao.
    if (!$is_admin) {
        $allow_groups = permission_deputy_groups((int) ($me['id'] ?? 0));
        $allow_class  = permission_deputy_class_ids((int) ($me['id'] ?? 0));
        $groups = array_intersect_key($groups, array_flip($allow_groups));
        $users  = array_values(array_filter($users, static function ($u) use ($allow_class) {
            return in_array((int) ($u['classification_id'] ?? 0), $allow_class, true);
        }));
    }

    load_view('manage_permissions', [
        'page_title'             => 'Phân quyền người dùng',
        'users'                  => $users,
        'groups'                 => $groups,
        'current_admin'          => $me,
        'is_admin'               => $is_admin,
        'transfer_candidates'    => $is_admin ? admin_get_active_users_for_transfer((int) ($me['id'] ?? 0)) : [],
        'all_group_labels'       => array_keys(permission_all_views_grouped()),
        'classifications'        => $is_admin ? admin_get_user_classifications() : [],
        'deputies'               => $is_admin ? permission_deputy_list() : [],
        'view_only_capable_ids'  => permission_view_only_capable_ids(),
    ]);
}

/**
 * AJAX (admin trưởng): bổ nhiệm / cập nhật phạm vi 1 admin phó.
 * POST: user_id, groups[] (group_label), class_ids[] (classification_id).
 */
function deputy_saveAction()
{
    header('Content-Type: application/json; charset=utf-8');
    permission_require_admin(true);
    // Hỗ trợ bổ nhiệm NHIỀU user cùng lúc (user_ids[]) hoặc 1 user (user_id).
    $uids = $_POST['user_ids'] ?? [];
    if (!is_array($uids)) $uids = [];
    if (!$uids && (int) ($_POST['user_id'] ?? 0) > 0) $uids = [(int) $_POST['user_id']];
    $uids = array_values(array_unique(array_filter(array_map('intval', $uids), static fn($v) => $v > 0)));

    $groups    = $_POST['groups'] ?? [];
    $class_ids = $_POST['class_ids'] ?? [];
    if (!is_array($groups))    $groups = [];
    if (!is_array($class_ids)) $class_ids = [];
    if (!$uids)               { echo json_encode(['ok' => false, 'message' => 'Chưa chọn user nào.']); exit; }
    if (!$groups || !$class_ids) {
        echo json_encode(['ok' => false, 'message' => 'Cần chọn ít nhất 1 module và 1 nhóm user.']);
        exit;
    }

    $admin   = permission_current_user();
    $results = [];
    foreach ($uids as $uid) {
        $is_new = permission_set_deputy($uid, $groups, $class_ids, (int) ($admin['id'] ?? 0));
        if ($is_new) {
            // Lần đầu bổ nhiệm: đẩy LỜI MỜI (Nhận/Từ chối) cho user.
            notify_create($uid, 'Lời mời bổ nhiệm admin phó',
                'Bạn được mời làm admin phó (phân quyền cho user trong phạm vi được giao). Hãy phản hồi Nhận / Từ chối.',
                '?mod=admin_factory&controllers=admin&action=manage_permissions',
                'deputy_invite', (int) ($admin['id'] ?? 0));
        } else {
            // Đã là admin phó, chỉ cập nhật/bổ sung quyền: báo thường (không Nhận/Từ chối).
            notify_create($uid, 'Cập nhật quyền admin phó',
                'Admin trưởng vừa cập nhật phạm vi (module / nhóm user) bạn được quản lý.',
                '?mod=admin_factory&controllers=admin&action=manage_permissions',
                'deputy_scope_updated', (int) ($admin['id'] ?? 0));
        }
        $results[] = ['user_id' => $uid, 'status' => permission_deputy_status($uid)];
    }
    echo json_encode(['ok' => true, 'results' => $results]);
    exit;
}

/**
 * AJAX (user được mời): phản hồi lời mời bổ nhiệm admin phó.
 * POST: accept (1/0). Cả hai trường hợp đều đẩy chuông lại cho admin trưởng.
 */
function deputy_respondAction()
{
    header('Content-Type: application/json; charset=utf-8');
    $me = permission_current_user();
    if (!$me) { echo json_encode(['ok' => false, 'message' => 'Phiên không hợp lệ.']); exit; }
    $uid = (int) $me['id'];
    if (permission_deputy_status($uid) !== 'pending') {
        echo json_encode(['ok' => false, 'message' => 'Không có lời mời đang chờ.']); exit;
    }
    $accept = (int) ($_POST['accept'] ?? 0) === 1;
    $name   = trim((string) ($me['fullname'] ?? '')) ?: (string) $me['username'];
    if ($accept) {
        permission_set_deputy_status($uid, 'accepted');
        notify_admins('Admin phó đã nhận bổ nhiệm',
            $name . ' đã NHẬN bổ nhiệm admin phó.',
            '?mod=admin_factory&controllers=admin&action=manage_permissions', 'deputy_accepted');
    } else {
        permission_remove_deputy($uid);
        notify_admins('Admin phó đã từ chối bổ nhiệm',
            $name . ' đã TỪ CHỐI bổ nhiệm admin phó.',
            '?mod=admin_factory&controllers=admin&action=manage_permissions', 'deputy_rejected');
    }
    echo json_encode(['ok' => true, 'accepted' => $accept]);
    exit;
}

/** AJAX (admin trưởng): gỡ bổ nhiệm admin phó. POST: user_id. */
function deputy_removeAction()
{
    header('Content-Type: application/json; charset=utf-8');
    permission_require_admin(true);
    $uid = (int) ($_POST['user_id'] ?? 0);
    if ($uid <= 0) { echo json_encode(['ok' => false, 'message' => 'Thiếu người dùng.']); exit; }
    permission_remove_deputy($uid);
    echo json_encode(['ok' => true]);
    exit;
}

/* =====================================================================
 *  BUỘC RỜI HỆ THỐNG — admin trưởng (hoặc admin phó trong phạm vi) buộc
 *  1 user rời. Gửi OTP tới email người thao tác để xác nhận.
 * =====================================================================*/

/** Người thao tác có được buộc rời $target_id không (admin trưởng: mọi user; admin phó: trong nhóm). */
function admin_can_force_leave($actor, $target)
{
    if (!$target) return false;
    if ((int) ($target['id'] ?? 0) === (int) ($actor['id'] ?? 0)) return false; // không tự buộc mình
    if (($target['role'] ?? '') === 'admin') return false;                       // không buộc admin trưởng
    if (permission_is_admin($actor)) return true;
    return permission_deputy_can_manage_user((int) $actor['id'], (int) $target['id']);
}

/** AJAX (POST: user_id): gửi OTP 6 ký tự (60s) tới email người thao tác để xác nhận buộc rời. */
function force_leave_send_otpAction()
{
    header('Content-Type: application/json; charset=utf-8');
    $actor = permission_require_admin_or_deputy(true);
    permission_boot_session();

    $target = admin_get_user_by_id((int) ($_POST['user_id'] ?? 0));
    if (!$target) { echo json_encode(['ok' => false, 'message' => 'Không tìm thấy người dùng.']); exit; }
    if (!admin_can_force_leave($actor, $target)) {
        echo json_encode(['ok' => false, 'message' => 'Bạn không có quyền buộc tài khoản này rời hệ thống.']); exit;
    }
    $actor_email = trim((string) ($actor['email'] ?? ''));
    if ($actor_email === '') {
        echo json_encode(['ok' => false, 'message' => 'Tài khoản của bạn chưa có email để nhận mã.']); exit;
    }

    $otp = admin_generate_otp();
    $_SESSION['force_leave'] = [
        'otp'       => $otp,
        'expires'   => time() + 60,
        'target_id' => (int) $target['id'],
        'actor_id'  => (int) $actor['id'],
    ];
    $tname = htmlspecialchars((string) ($target['fullname'] ?: $target['username']), ENT_QUOTES, 'UTF-8');
    $content =
        "Bạn vừa yêu cầu <b>BUỘC RỜI HỆ THỐNG</b> đối với tài khoản: <b>{$tname}</b>.<br><br>"
        . "Mã xác nhận của bạn là: <h2 style='letter-spacing:4px;'>{$otp}</h2>"
        . "Mã có hiệu lực trong <b>60 giây</b>. Sau khi xác nhận, dữ liệu cá nhân hóa của tài khoản này sẽ bị xóa "
        . "và tài khoản tự xóa trong 24 giờ.";
    $sent = send_mail($actor_email, (string) ($actor['fullname'] ?? ''), 'Mã xác nhận buộc rời hệ thống — SAFEKING', $content);
    if ($sent !== true) {
        unset($_SESSION['force_leave']);
        echo json_encode(['ok' => false, 'message' => 'Không gửi được email. Kiểm tra cấu hình SMTP rồi thử lại.']); exit;
    }
    echo json_encode([
        'ok'         => true,
        'masked'     => admin_mask_email($actor_email),
        'expires_in' => 60,
    ]);
    exit;
}

/** AJAX (POST: user_id, otp): xác nhận buộc rời hệ thống nếu OTP khớp & còn hạn. */
function force_leave_confirmAction()
{
    header('Content-Type: application/json; charset=utf-8');
    $actor = permission_require_admin_or_deputy(true);
    permission_boot_session();

    $otp    = strtoupper(trim((string) ($_POST['otp'] ?? '')));
    $target = admin_get_user_by_id((int) ($_POST['user_id'] ?? 0));
    $st     = $_SESSION['force_leave'] ?? null;
    if (!is_array($st)) {
        echo json_encode(['ok' => false, 'message' => 'Chưa yêu cầu mã hoặc phiên đã kết thúc.']); exit;
    }
    if (time() > (int) $st['expires']) {
        unset($_SESSION['force_leave']);
        echo json_encode(['ok' => false, 'message' => 'Mã đã hết hạn. Hãy gửi lại mã.']); exit;
    }
    if (!$target || (int) $target['id'] !== (int) $st['target_id'] || (int) $actor['id'] !== (int) $st['actor_id']) {
        echo json_encode(['ok' => false, 'message' => 'Dữ liệu không khớp. Hãy thử lại.']); exit;
    }
    if ($otp === '' || $otp !== (string) $st['otp']) {
        echo json_encode(['ok' => false, 'message' => 'Mã không đúng.']); exit;
    }
    if (!admin_can_force_leave($actor, $target)) {
        echo json_encode(['ok' => false, 'message' => 'Bạn không có quyền buộc tài khoản này rời hệ thống.']); exit;
    }

    $uid   = (int) $target['id'];
    $tname = trim((string) ($target['fullname'] ?? '')) ?: (string) $target['username'];
    $aname = trim((string) ($actor['fullname'] ?? '')) ?: (string) $actor['username'];
    lifecycle_mark_user_left($uid);
    unset($_SESSION['force_leave']);
    notify_admins('Tài khoản bị buộc rời hệ thống',
        $aname . ' đã buộc ' . $tname . ' rời hệ thống. Tài khoản sẽ tự xóa trong 24 giờ.',
        '?mod=admin_factory&controllers=admin&action=manage_user_list', 'force_left');
    echo json_encode(['ok' => true]);
    exit;
}

/**
 * AJAX (POST: new_user_id): gửi mã OTP 6 ký tự (hiệu lực 60s) tới email ADMIN HIỆN TẠI
 * để xác nhận chuyển quyền admin. Mã lưu trong session (không lộ ra client).
 */
function admin_transfer_send_otpAction()
{
    header('Content-Type: application/json; charset=utf-8');
    $admin = permission_require_admin(true);
    permission_boot_session();

    $new_id = (int) ($_POST['new_user_id'] ?? 0);
    $target = admin_get_user_by_id($new_id);
    if (!$target) {
        echo json_encode(['ok' => false, 'message' => 'Chưa chọn admin mới hợp lệ.']); exit;
    }
    if ((int) $target['id'] === (int) $admin['id']) {
        echo json_encode(['ok' => false, 'message' => 'Không thể chuyển quyền cho chính mình.']); exit;
    }
    if ($target['status'] !== 'active') {
        echo json_encode(['ok' => false, 'message' => 'Admin mới phải là tài khoản đang hoạt động.']); exit;
    }
    if ($target['role'] === 'admin') {
        echo json_encode(['ok' => false, 'message' => 'Tài khoản này đã là admin.']); exit;
    }
    $admin_email = trim((string) ($admin['email'] ?? ''));
    if ($admin_email === '') {
        echo json_encode(['ok' => false, 'message' => 'Admin hiện tại chưa có email để nhận mã.']); exit;
    }

    $otp = admin_generate_otp();
    $_SESSION['admin_transfer'] = [
        'otp'         => $otp,
        'expires'     => time() + 60,           // 60 giây
        'new_user_id' => (int) $target['id'],
        'admin_id'    => (int) $admin['id'],
    ];

    $new_name = htmlspecialchars((string) ($target['fullname'] ?: $target['username']), ENT_QUOTES, 'UTF-8');
    $content =
        "Bạn (hoặc ai đó) vừa yêu cầu <b>chuyển quyền ADMIN</b> sang tài khoản: <b>{$new_name}</b>.<br><br>"
        . "Mã xác nhận của bạn là: <h2 style='letter-spacing:4px;'>{$otp}</h2>"
        . "Mã có hiệu lực trong <b>60 giây</b>. Nhập mã này để hoàn tất chuyển quyền.<br>"
        . "Nếu không phải bạn, hãy bỏ qua email này và đổi mật khẩu.";
    $sent = send_mail($admin_email, (string) ($admin['fullname'] ?? ''), 'Mã xác nhận chuyển quyền ADMIN — SAFEKING', $content);

    if ($sent !== true) {
        unset($_SESSION['admin_transfer']); // gửi lỗi -> hủy mã, buộc gửi lại
        echo json_encode(['ok' => false, 'message' => 'Không gửi được email. Kiểm tra cấu hình SMTP rồi thử lại.']); exit;
    }
    echo json_encode([
        'ok'         => true,
        'message'    => 'Đã gửi mã xác nhận tới email admin hiện tại.',
        'masked'     => admin_mask_email($admin_email),
        'expires_in' => 60,
    ]);
    exit;
}

/**
 * AJAX (POST: otp): xác nhận OTP và thực hiện chuyển quyền admin nếu khớp & còn hạn.
 * Sau khi chuyển, ADMIN HIỆN TẠI bị hạ xuống 'user' -> client tự điều hướng về trang chủ.
 */
function admin_transfer_confirmAction()
{
    header('Content-Type: application/json; charset=utf-8');
    $admin = permission_require_admin(true);
    permission_boot_session();

    $otp = strtoupper(trim((string) ($_POST['otp'] ?? '')));
    $st  = $_SESSION['admin_transfer'] ?? null;
    if (!is_array($st)) {
        echo json_encode(['ok' => false, 'message' => 'Chưa yêu cầu mã hoặc phiên đã kết thúc. Hãy gửi lại mã.']); exit;
    }
    if ((int) ($st['admin_id'] ?? 0) !== (int) $admin['id']) {
        unset($_SESSION['admin_transfer']);
        echo json_encode(['ok' => false, 'message' => 'Phiên chuyển quyền không hợp lệ.']); exit;
    }
    if (time() > (int) ($st['expires'] ?? 0)) {
        unset($_SESSION['admin_transfer']);
        echo json_encode(['ok' => false, 'message' => 'Mã đã hết hạn (quá 60 giây). Hãy gửi lại mã.']); exit;
    }
    if ($otp === '' || $otp !== (string) $st['otp']) {
        echo json_encode(['ok' => false, 'message' => 'Mã xác nhận không đúng.']); exit;
    }

    // Lấy thông tin admin mới TRƯỚC khi chuyển (để gửi email/đẩy thông báo).
    $target = admin_get_user_by_id((int) $st['new_user_id']);

    $res = admin_transfer_role((int) $admin['id'], (int) $st['new_user_id']);
    unset($_SESSION['admin_transfer']);
    if (!$res['ok']) {
        echo json_encode(['ok' => false, 'message' => $res['message']]); exit;
    }

    // ---- Báo cho ADMIN MỚI: chuông trong app (LUÔN) + email (nếu có địa chỉ) ----
    $new_id    = (int) $st['new_user_id'];
    $new_name  = $target ? (string) ($target['fullname'] ?: $target['username']) : '';
    $from_name = (string) ($admin['fullname'] ?? '') ?: (string) ($admin['username'] ?? '');

    // 1) Đẩy thông báo lên "chuông" (bell) dùng chung của admin mới.
    notify_create(
        $new_id,
        'Bạn đã được chuyển quyền ADMIN',
        'Bạn vừa được ' . $from_name . ' chuyển quyền quản trị viên (admin) trên hệ thống SAFEKING.',
        '?mod=home&controllers=index&action=index',
        'admin_transfer'
    );

    // 2) Email cho admin mới (nếu tài khoản có địa chỉ email).
    $new_email  = $target ? trim((string) ($target['email'] ?? '')) : '';
    $email_sent = false;
    if ($new_email !== '') {
        $content =
            "Xin chào <b>" . htmlspecialchars($new_name, ENT_QUOTES, 'UTF-8') . "</b>,<br><br>"
            . "Bạn vừa được <b>" . htmlspecialchars($from_name, ENT_QUOTES, 'UTF-8') . "</b> chuyển quyền "
            . "<b>QUẢN TRỊ VIÊN (ADMIN)</b> trên hệ thống SAFEKING.<br>"
            . "Kể từ bây giờ tài khoản của bạn có toàn quyền quản trị. Vui lòng đăng nhập để kiểm tra.<br><br>"
            . "Nếu bạn cho rằng đây là nhầm lẫn, hãy liên hệ quản trị hệ thống ngay.";
        $email_sent = (send_mail($new_email, $new_name, 'Bạn đã được chuyển quyền ADMIN — SAFEKING', $content) === true);
    }

    echo json_encode([
        'ok'         => true,
        'message'    => 'Chuyển quyền admin thành công. Bạn không còn là admin.',
        'email_sent' => $email_sent,
        'redirect'   => '?mod=home&controllers=index&action=index',
    ]);
    exit;
}

/** AJAX: lấy danh sách view_id đã gán cho 1 user. GET: user_id */
function user_views_getAction()
{
    $me = permission_require_admin_or_deputy(true);
    header('Content-Type: application/json; charset=utf-8');
    $uid = (int) ($_GET['user_id'] ?? $_POST['user_id'] ?? 0);
    if ($uid <= 0) {
        echo json_encode(['ok' => false, 'message' => 'Thiếu user_id.']);
        exit;
    }
    // Admin phó chỉ được xem user thuộc nhóm phân loại mình quản lý.
    if (!permission_is_admin($me)
        && !permission_deputy_can_manage_user((int) $me['id'], $uid)) {
        echo json_encode(['ok' => false, 'message' => 'Ngoài phạm vi quản lý của bạn.']);
        exit;
    }
    $view_only_ids = array_map(static fn($r) => (int) $r['view_id'], db_fetch_array(
        "SELECT view_id FROM tbl_user_views WHERE user_id = {$uid} AND can_edit = 0"
    ) ?: []);
    echo json_encode([
        'ok'            => true,
        'view_ids'      => permission_granted_view_ids($uid),
        'view_only_ids' => $view_only_ids,
    ]);
    exit;
}

/** AJAX: lưu (ghi đè) tập view của 1 user. POST: user_id, view_ids[] */
function user_views_saveAction()
{
    $me = permission_require_admin_or_deputy(true);
    header('Content-Type: application/json; charset=utf-8');
    $uid            = (int) ($_POST['user_id'] ?? 0);
    $view_ids       = $_POST['view_ids'] ?? [];
    $view_only_ids  = $_POST['view_only_ids'] ?? [];
    if ($uid <= 0) {
        echo json_encode(['ok' => false, 'message' => 'Thiếu user_id.']);
        exit;
    }
    if (!is_array($view_ids)) {
        $view_ids = [];
    }
    if (!is_array($view_only_ids)) {
        $view_only_ids = [];
    }
    $view_ids      = array_map('intval', $view_ids);
    $view_only_ids = array_map('intval', $view_only_ids);

    if (permission_is_admin($me)) {
        permission_set_user_views($uid, $view_ids, $view_only_ids);
        echo json_encode(['ok' => true, 'count' => count($view_ids)]);
        exit;
    }

    // ----- Admin phó: kiểm tra phạm vi + giữ nguyên view ngoài phạm vi + báo admin trưởng -----
    $deputy_id = (int) $me['id'];
    if (!permission_deputy_can_manage_user($deputy_id, $uid)) {
        echo json_encode(['ok' => false, 'message' => 'User này ngoài nhóm phân loại bạn được giao.']);
        exit;
    }
    $allowed_groups = permission_deputy_groups($deputy_id);
    $scope_ids      = permission_view_ids_in_groups($allowed_groups);          // view_id trong phạm vi
    $scope_set      = array_flip($scope_ids);
    // Chỉ nhận các view thuộc phạm vi; phần ngoài phạm vi của user được giữ lại.
    $submitted_in_scope = array_values(array_filter($view_ids, static fn($v) => isset($scope_set[$v])));
    $existing           = permission_granted_view_ids($uid);
    $keep_out_of_scope  = array_values(array_filter($existing, static fn($v) => !isset($scope_set[$v])));
    $final = array_values(array_unique(array_merge($keep_out_of_scope, $submitted_in_scope)));

    // Chỉ xem: áp submitted trong phạm vi, giữ nguyên trạng thái cũ cho view ngoài phạm vi.
    $submitted_view_only_in_scope = array_values(array_filter(
        $view_only_ids,
        static fn($v) => isset($scope_set[$v]) && in_array($v, $submitted_in_scope, true)
    ));
    $existing_view_only = array_map(static fn($r) => (int) $r['view_id'], db_fetch_array(
        "SELECT view_id FROM tbl_user_views WHERE user_id = {$uid} AND can_edit = 0"
    ) ?: []);
    $keep_view_only_out_of_scope = array_values(array_filter($existing_view_only, static fn($v) => !isset($scope_set[$v])));
    $final_view_only = array_values(array_unique(array_merge($keep_view_only_out_of_scope, $submitted_view_only_in_scope)));
    permission_set_user_views($uid, $final, $final_view_only);

    // Đẩy chuông cho admin trưởng tại thời điểm lưu.
    $target = admin_get_user_by_id($uid);
    $tname  = trim((string) ($target['fullname'] ?? '')) ?: (string) ($target['username'] ?? ('#' . $uid));
    $dname  = trim((string) ($me['fullname'] ?? '')) ?: (string) ($me['username'] ?? ('#' . $deputy_id));
    notify_admins(
        'Admin phó vừa phân quyền',
        $dname . ' đã cập nhật phân quyền cho ' . $tname . ' (' . count($submitted_in_scope) . ' chức năng trong phạm vi).',
        '?mod=admin_factory&controllers=admin&action=manage_permissions',
        'deputy_grant'
    );
    echo json_encode(['ok' => true, 'count' => count($submitted_in_scope)]);
    exit;
}

/**
 * AJAX: cập nhật trạng thái 1 tài khoản người dùng.
 * Tham số POST: user_id, status (active|pending|inactive)
 */
function update_user_statusAction()
{
    header('Content-Type: application/json; charset=utf-8');
    $me      = permission_require_admin_or_deputy(true);
    $user_id = (int) ($_POST['user_id'] ?? 0);
    $status  = (string) ($_POST['status'] ?? '');

    if ($user_id <= 0) {
        echo json_encode(['ok' => false, 'message' => 'Thiếu tham số.']);
        exit;
    }
    // Admin phó chỉ được đổi trạng thái user thuộc nhóm phân loại được giao.
    if (!permission_is_admin($me) && !permission_deputy_can_manage_user((int) $me['id'], $user_id)) {
        echo json_encode(['ok' => false, 'message' => 'User này ngoài phạm vi quản lý của bạn.']);
        exit;
    }

    // Trạng thái TRƯỚC khi đổi — để chỉ gửi mail khi chuyển SANG 'active' (lần đầu).
    $before = admin_get_user_by_id($user_id);

    if (admin_update_user_status($user_id, $status) === false) {
        echo json_encode(['ok' => false, 'message' => 'Trạng thái không hợp lệ.']);
        exit;
    }

    // Vừa kích hoạt (active) và trước đó CHƯA active → báo email cho người dùng.
    $mailed = false;
    if ($status === 'active' && $before && ($before['status'] ?? '') !== 'active') {
        $mailed = admin_send_account_activated_mail($before);
    }

    echo json_encode(['ok' => true, 'mailed' => $mailed]);
    exit;
}

/** Gửi email báo "tài khoản đã được kích hoạt" cho 1 user (mảng có email, fullname, username). */
function admin_send_account_activated_mail($user)
{
    $email    = trim((string) ($user['email'] ?? ''));
    $fullname = (string) ($user['fullname'] ?? '');
    $username = (string) ($user['username'] ?? '');
    if ($email === '') return false;

    $login_url = 'http://localhost/learn_practic/nvsxvat.vn/public/?mod=auth&controllers=index&action=login';
    $content = "Xin chào <b>" . htmlspecialchars($fullname) . "</b>,<br><br>"
        . "Tài khoản của bạn (<b>" . htmlspecialchars($username) . "</b>) trên hệ thống SAFEKING ERP "
        . "đã được <b>kích hoạt</b> và đang ở trạng thái <b>Đang hoạt động</b>.<br>"
        . "Bạn có thể đăng nhập và sử dụng hệ thống ngay bây giờ."
        . "<br><br><a href=\"{$login_url}\" "
        . "style=\"display:inline-block;padding:10px 18px;background:#096926;color:#fff;"
        . "text-decoration:none;border-radius:6px;font-weight:600;\">Đăng nhập ngay</a>"
        . "<br><br>Nếu bạn không thực hiện đăng ký này, vui lòng bỏ qua email.";

    return send_mail($email, $fullname ?: 'Người dùng', 'Tài khoản của bạn đã được kích hoạt — SAFEKING', $content);
}

/**
 * AJAX: cập nhật 1 field của 1 dòng product.
 * Tham số POST: product_id, field, value
 * field hợp lệ: product_name, unit, category_id, inner_packaging_spec,
 * outer_packaging_spec, weight_kg, has_nutrition_fact, is_new_product, selling_price, type, supplier_id
 */
function update_product_fieldAction()
{
    header('Content-Type: application/json; charset=utf-8');
    permission_require_can_edit('admin_factory', 'admin', 'manage_product_list', 'ok');
    $product_id = (int) ($_POST['product_id'] ?? 0);
    $field      = (string) ($_POST['field'] ?? '');
    $value      = $_POST['value'] ?? '';

    if ($product_id <= 0 || $field === '') {
        echo json_encode(['ok' => false, 'message' => 'Thiếu tham số.']);
        exit;
    }

    $product = admin_get_product_by_id($product_id);
    if (!$product) {
        echo json_encode(['ok' => false, 'message' => 'Sản phẩm không tồn tại.']);
        exit;
    }

    switch ($field) {
        case 'product_name':
        case 'common_product_name':
        case 'unit':
            admin_update_products_field($product_id, $field, trim((string) $value));
            break;

        case 'category_id':
            $cid = (int) $value;
            admin_update_products_field($product_id, 'category_id', $cid > 0 ? $cid : null);
            break;

        case 'has_nutrition_fact':
            $v = ((string) $value === '1' || $value === 1 || $value === true) ? 1 : 0;
            admin_update_products_field($product_id, 'has_nutrition_fact', $v);
            break;

        case 'is_new_product':
            $v = ((string) $value === '1' || $value === 1 || $value === true) ? 1 : 0;
            admin_update_products_field($product_id, 'is_new_product', $v);
            break;

        case 'type':
            $v = (string) $value;
            if (!in_array($v, ['Sản xuất', 'Thương mại'], true)) {
                echo json_encode(['ok' => false, 'message' => 'Loại sản phẩm không hợp lệ.']);
                exit;
            }
            admin_update_products_field($product_id, 'type', $v);
            // Sản xuất => không có supplier (VAT là nhà tự sản xuất).
            if ($v === 'Sản xuất') {
                admin_update_products_field($product_id, 'supplier_id', null);
            }
            break;

        case 'supplier_id':
            $sid = (int) $value;
            admin_update_products_field($product_id, 'supplier_id', $sid > 0 ? $sid : null);
            break;

        case 'inner_packaging_spec':
        case 'outer_packaging_spec':
            admin_upsert_product_info_basic($product_id, $field, trim((string) $value));
            break;

        case 'weight_kg':
            admin_upsert_product_weight($product_id, (float) $value);
            break;

        case 'selling_price':
            admin_upsert_selling_price($product_id, (float) $value);
            admin_upsert_system_price($product_id, (float) $value);
            break;

        default:
            echo json_encode(['ok' => false, 'message' => 'Field không được phép.']);
            exit;
    }

    echo json_encode(['ok' => true]);
    exit;
}

/**
 * AJAX: upload ảnh đại diện cho 1 sản phẩm.
 * Tham số POST: product_id; FILES: image
 */
function upload_product_imageAction()
{
    header('Content-Type: application/json; charset=utf-8');
    $product_id = (int) ($_POST['product_id'] ?? 0);
    if ($product_id <= 0 || empty($_FILES['image'])) {
        echo json_encode(['ok' => false, 'message' => 'Thiếu tham số.']);
        exit;
    }
    $result = admin_save_product_image_upload($product_id, $_FILES['image']);
    echo json_encode($result);
    exit;
}

/**
 * AJAX: cập nhật 1 field cho 1 material.
 * Tham số POST: material_id, field, value
 * field hợp lệ: material_name, unit, supplier_id, purchase_price,
 *               purchase_price_includes_purchase_cost, selling_price
 */
function update_material_fieldAction()
{
    header('Content-Type: application/json; charset=utf-8');
    permission_require_can_edit('admin_factory', 'admin', 'manage_material_list', 'ok');
    $material_id = (int) ($_POST['material_id'] ?? 0);
    $field       = (string) ($_POST['field'] ?? '');
    $value       = $_POST['value'] ?? '';

    if ($material_id <= 0 || $field === '') {
        echo json_encode(['ok' => false, 'message' => 'Thiếu tham số.']);
        exit;
    }

    switch ($field) {
        case 'material_name':
        case 'common_material_name':
        case 'unit':
            admin_update_material_field($material_id, $field, trim((string) $value));
            break;

        case 'classification':
            admin_update_material_field($material_id, 'classification', trim((string) $value));
            break;

        case 'supplier_id':
            admin_update_material_field($material_id, 'supplier_id', $value);
            break;

        case 'purchase_price':
        case 'purchase_price_includes_purchase_cost':
            admin_upsert_material_purchase_price($material_id, $field, $value);
            break;

        case 'selling_price':
            admin_upsert_material_selling_price($material_id, (float) $value);
            break;

        default:
            echo json_encode(['ok' => false, 'message' => 'Field không được phép.']);
            exit;
    }

    echo json_encode(['ok' => true]);
    exit;
}

/**
 * AJAX: tìm nhà cung cấp theo keyword (autocomplete cho cột Nhà cung cấp).
 * Tham số POST: keyword
 */
function search_suppliersAction()
{
    header('Content-Type: application/json; charset=utf-8');
    $kw    = (string) ($_POST['keyword'] ?? '');
    $items = admin_search_suppliers_by_name($kw, 20);
    echo json_encode(['ok' => true, 'data' => $items]);
    exit;
}

/**
 * AJAX: cập nhật 1 field cho 1 customer.
 */
function update_customer_fieldAction()
{
    header('Content-Type: application/json; charset=utf-8');
    permission_require_can_edit('admin_factory', 'admin', 'manage_customer_list', 'ok');
    $customer_id = (int) ($_POST['customer_id'] ?? 0);
    $field       = (string) ($_POST['field'] ?? '');
    $value       = (string) ($_POST['value'] ?? '');

    if ($customer_id <= 0 || $field === '') {
        echo json_encode(['ok' => false, 'message' => 'Thiếu tham số.']);
        exit;
    }
    $ok = admin_update_customer_field($customer_id, $field, $value);
    if ($ok === false) {
        echo json_encode(['ok' => false, 'message' => 'Field không hợp lệ hoặc giá trị không được trống.']);
        exit;
    }
    echo json_encode(['ok' => true]);
    exit;
}

/**
 * AJAX: cập nhật 1 field cho 1 supplier.
 */
function update_supplier_fieldAction()
{
    header('Content-Type: application/json; charset=utf-8');
    permission_require_can_edit('admin_factory', 'admin', 'manage_supplier_list', 'ok');
    $supplier_id = (int) ($_POST['supplier_id'] ?? 0);
    $field       = (string) ($_POST['field'] ?? '');
    $value       = (string) ($_POST['value'] ?? '');

    if ($supplier_id <= 0 || $field === '') {
        echo json_encode(['ok' => false, 'message' => 'Thiếu tham số.']);
        exit;
    }
    $ok = admin_update_supplier_field($supplier_id, $field, $value);
    if ($ok === false) {
        echo json_encode(['ok' => false, 'message' => 'Field không hợp lệ.']);
        exit;
    }
    echo json_encode(['ok' => true]);
    exit;
}

/* =====================================================================
 *  CREATE actions
 * =====================================================================*/

function create_productAction()
{
    header('Content-Type: application/json; charset=utf-8');
    permission_require_can_edit('admin_factory', 'admin', 'manage_product_list', 'ok');
    $image = $_FILES['image'] ?? null;
    $res   = admin_create_product($_POST, $image);
    echo json_encode($res);
    exit;
}

function create_materialAction()
{
    header('Content-Type: application/json; charset=utf-8');
    permission_require_can_edit('admin_factory', 'admin', 'manage_material_list', 'ok');
    $res = admin_create_material($_POST);
    echo json_encode($res);
    exit;
}

function create_customerAction()
{
    header('Content-Type: application/json; charset=utf-8');
    permission_require_can_edit('admin_factory', 'admin', 'manage_customer_list', 'ok');
    $res = admin_create_customer($_POST);
    echo json_encode($res);
    exit;
}

function create_supplierAction()
{
    header('Content-Type: application/json; charset=utf-8');
    permission_require_can_edit('admin_factory', 'admin', 'manage_supplier_list', 'ok');
    $res = admin_create_supplier($_POST);
    echo json_encode($res);
    exit;
}

/* =====================================================================
 *  DELETE actions
 * =====================================================================*/

function delete_productAction()
{
    header('Content-Type: application/json; charset=utf-8');
    permission_require_can_edit('admin_factory', 'admin', 'manage_product_list', 'ok');
    $id = (int) ($_POST['product_id'] ?? 0);
    if ($id <= 0) {
        echo json_encode(['ok' => false, 'message' => 'Thiếu tham số.']);
        exit;
    }
    echo json_encode(admin_delete_product($id));
    exit;
}

function delete_materialAction()
{
    header('Content-Type: application/json; charset=utf-8');
    permission_require_can_edit('admin_factory', 'admin', 'manage_material_list', 'ok');
    $id = (int) ($_POST['material_id'] ?? 0);
    if ($id <= 0) {
        echo json_encode(['ok' => false, 'message' => 'Thiếu tham số.']);
        exit;
    }
    echo json_encode(admin_delete_material($id));
    exit;
}

function delete_customerAction()
{
    header('Content-Type: application/json; charset=utf-8');
    permission_require_can_edit('admin_factory', 'admin', 'manage_customer_list', 'ok');
    $id = (int) ($_POST['customer_id'] ?? 0);
    if ($id <= 0) {
        echo json_encode(['ok' => false, 'message' => 'Thiếu tham số.']);
        exit;
    }
    echo json_encode(admin_delete_customer($id));
    exit;
}

function delete_supplierAction()
{
    header('Content-Type: application/json; charset=utf-8');
    permission_require_can_edit('admin_factory', 'admin', 'manage_supplier_list', 'ok');
    $id = (int) ($_POST['supplier_id'] ?? 0);
    if ($id <= 0) {
        echo json_encode(['ok' => false, 'message' => 'Thiếu tham số.']);
        exit;
    }
    echo json_encode(admin_delete_supplier($id));
    exit;
}
/* =====================================================================
 *  Data-aggregation views (read-only): hiển thị nội dung 5 bảng tổng hợp,
 *  sắp xếp mới → cũ, JS lo phân trang client-side.
 * =====================================================================*/

/**
 * Đọc & chuẩn hóa khoảng ngày lọc từ GET (?from=YYYY-MM-DD&to=YYYY-MM-DD).
 * Trả về [from, to] — giá trị không hợp lệ bị bỏ qua (rỗng).
 */
function admin_read_date_range()
{
    $norm = function ($v) {
        $v = trim((string) $v);
        return preg_match('/^\d{4}-\d{2}-\d{2}$/', $v) ? $v : '';
    };
    return [$norm($_GET['from'] ?? ''), $norm($_GET['to'] ?? '')];
}

/**
 * AJAX: tìm option cho bộ lọc cột (.btn-filter) ở 5 view dữ liệu.
 * Tham số POST/GET: type (products|materials|customers|suppliers|goods), keyword
 * Trả về { ok, data:[{value, name}] }.
 */
function data_filter_searchAction()
{
    header('Content-Type: application/json; charset=utf-8');
    $type = (string) ($_POST['type'] ?? $_GET['type'] ?? '');
    $kw   = (string) ($_POST['keyword'] ?? $_GET['keyword'] ?? '');
    echo json_encode(['ok' => true, 'data' => admin_search_filter_options($type, $kw, 20)]);
    exit;
}

function finished_product_production_dataAction()
{
    list($from, $to) = admin_read_date_range();
    $product_id = (int) ($_GET['product_id'] ?? 0);
    load_view('finished_product_production_data', [
        'rows'       => admin_get_finished_product_production_data($from, $to, $product_id),
        'from'       => $from,
        'to'         => $to,
        'product_id' => $product_id,
    ]);
}

function purchased_finished_product_dataAction()
{
    list($from, $to) = admin_read_date_range();
    $product_id  = (int) ($_GET['product_id'] ?? 0);
    $supplier_id = (int) ($_GET['supplier_id'] ?? 0);
    load_view('purchased_finished_product_data', [
        'rows'        => admin_get_purchased_finished_product_data($from, $to, $product_id, $supplier_id),
        'from'        => $from,
        'to'          => $to,
        'product_id'  => $product_id,
        'supplier_id' => $supplier_id,
    ]);
}

function raw_material_purchase_dataAction()
{
    list($from, $to) = admin_read_date_range();
    $material_id = (int) ($_GET['material_id'] ?? 0);
    $supplier_id = (int) ($_GET['supplier_id'] ?? 0);
    load_view('raw_material_purchase_data', [
        'rows'        => admin_get_raw_material_purchase_data($from, $to, $material_id, $supplier_id),
        'from'        => $from,
        'to'          => $to,
        'material_id' => $material_id,
        'supplier_id' => $supplier_id,
    ]);
}

function raw_material_production_issue_dataAction()
{
    list($from, $to) = admin_read_date_range();
    $material_id = (int) ($_GET['material_id'] ?? 0);
    $product_id  = (int) ($_GET['product_id'] ?? 0);
    load_view('raw_material_production_issue_data', [
        'rows'        => admin_get_raw_material_production_issue_data($from, $to, $material_id, $product_id),
        'from'        => $from,
        'to'          => $to,
        'material_id' => $material_id,
        'product_id'  => $product_id,
    ]);
}

function sale_inventory_issue_dataAction()
{
    list($from, $to) = admin_read_date_range();
    $goods       = (string) ($_GET['goods'] ?? '');
    $customer_id = (int) ($_GET['customer_id'] ?? 0);
    load_view('sale_inventory_issue_data', [
        'rows'        => admin_get_sale_inventory_issue_data($from, $to, $goods, $customer_id),
        'from'        => $from,
        'to'          => $to,
        'goods'       => $goods,
        'customer_id' => $customer_id,
    ]);
}

function accounting_dataAction()
{
    list($from, $to) = admin_read_date_range();
    load_view('accounting_data', [
        'rows' => admin_get_accounting_data($from, $to),
        'from' => $from,
        'to'   => $to,
    ]);
}

function cash_transactions_dataAction()
{
    list($from, $to) = admin_read_date_range();
    $type    = (string) ($_GET['type']    ?? '');
    $account = (string) ($_GET['account'] ?? '');
    if (!in_array($type, ['Thu', 'Chi'], true))  $type = '';
    if (!in_array($account, ['TM', 'TG'], true)) $account = '';
    load_view('cash_transactions_data', [
        'rows'    => admin_get_cash_transactions_data($from, $to, $type, $account),
        'from'    => $from,
        'to'      => $to,
        'type'    => $type,
        'account' => $account,
    ]);
}

function purchase_orderAction()
{
    list($from, $to) = admin_read_date_range();
    $supplier_id = (int) ($_GET['supplier_id'] ?? 0);
    $rows = admin_get_purchase_order_data($from, $to, $supplier_id);
    load_view('purchase_order', [
        'rows'         => $rows,
        'from'         => $from,
        'to'           => $to,
        'supplier_id'  => $supplier_id,
        'invoices_map' => admin_get_invoices_map('purchase_invoice', array_column($rows, 'id')),
    ]);
}

/** Lịch sử thanh toán NCC (module supplier_debt) — bảng dữ liệu. */
function supplier_payment_historyAction()
{
    sd_ensure_schema();
    list($from, $to) = admin_read_date_range();
    $supplier_id = (int) ($_GET['supplier_id'] ?? 0);
    $rows = admin_get_supplier_payments($from, $to, $supplier_id);
    load_view('supplier_payment_history', [
        'rows'            => $rows,
        'from'            => $from,
        'to'              => $to,
        'supplier_id'     => $supplier_id,
        'attachments_map' => admin_get_payment_attachments_map(array_column($rows, 'id')),
    ]);
}

function sales_ordersAction()
{
    list($from, $to) = admin_read_date_range();
    $customer_id = (int) ($_GET['customer_id'] ?? 0);
    $rows = admin_get_sales_orders_data($from, $to, $customer_id);

    // id giữa sales_orders và sales_warehouse_export_invoices có thể trùng nhau,
    // nên tách id theo nguồn và build 2 map hóa đơn riêng (mỗi map 1 namespace type).
    $order_ids = $export_ids = [];
    foreach ($rows as $r) {
        if (($r['inv_type'] ?? '') === 'sales_export_invoice') {
            $export_ids[] = (int) $r['id'];
        } else {
            $order_ids[] = (int) $r['id'];
        }
    }

    load_view('sales_orders', [
        'rows'                => $rows,
        'from'                => $from,
        'to'                  => $to,
        'customer_id'         => $customer_id,
        'invoices_map'        => admin_get_invoices_map('sales_invoice', $order_ids),
        'export_invoices_map' => admin_get_invoices_map('sales_export_invoice', $export_ids),
    ]);
}

/** AJAX: lưu khối lượng chỉnh tay cho 1 dòng đơn bán. */
function update_sales_order_weightAction()
{
    header('Content-Type: application/json; charset=utf-8');
    $inv_type = (string) ($_POST['inv_type'] ?? '');
    $order_id = (int) ($_POST['order_id'] ?? 0);
    $weight   = $_POST['weight'] ?? '';
    $weight   = ($weight === '' ? 0 : (float) $weight);
    $ok = admin_save_sales_order_weight($inv_type, $order_id, $weight);
    echo json_encode(['ok' => $ok], JSON_UNESCAPED_UNICODE);
    exit;
}

/* =====================================================================
 *  CÀI ĐẶT HỆ THỐNG (sidebar) — chỉ admin trưởng.
 * =====================================================================*/

/** AJAX: lấy cấu hình hệ thống hiện tại (dọn chat + giới hạn dung lượng). */
function system_settings_getAction()
{
    header('Content-Type: application/json; charset=utf-8');
    if (!permission_is_admin(permission_current_user())) {
        echo json_encode(['success' => false, 'message' => 'Không có quyền.']);
        exit;
    }
    echo json_encode(['success' => true, 'data' => system_settings_get()], JSON_UNESCAPED_UNICODE);
    exit;
}

/** AJAX: lưu 1 cấu hình hệ thống. POST: key, value. */
function system_settings_saveAction()
{
    header('Content-Type: application/json; charset=utf-8');
    if (!permission_is_admin(permission_current_user())) {
        echo json_encode(['success' => false, 'message' => 'Không có quyền.']);
        exit;
    }
    $ok = system_settings_save($_POST['key'] ?? '', $_POST['value'] ?? '');
    echo json_encode(['success' => $ok]);
    exit;
}

/** AJAX: danh sách ưu tiên dung lượng hiện có + toàn bộ user (cho ô chọn). */
function storage_priority_listAction()
{
    header('Content-Type: application/json; charset=utf-8');
    if (!permission_is_admin(permission_current_user())) {
        echo json_encode(['success' => false, 'message' => 'Không có quyền.']);
        exit;
    }
    $users = db_fetch_array(
        "SELECT id, fullname, username FROM tbl_users
         WHERE status IS NULL OR status NOT IN ('blocked', 'left')
         ORDER BY fullname, username"
    ) ?: [];
    $userList = array_map(static function ($u) {
        return [
            'id'   => (int) $u['id'],
            'name' => (string) (($u['fullname'] ?? '') ?: ($u['username'] ?? '')),
        ];
    }, $users);
    echo json_encode([
        'success'   => true,
        'overrides' => fm_quota_overrides_list(),
        'users'     => $userList,
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

/** AJAX: lưu mức ưu tiên dung lượng cho 1 user. POST: user_id, quota_mb. */
function storage_priority_saveAction()
{
    header('Content-Type: application/json; charset=utf-8');
    if (!permission_is_admin(permission_current_user())) {
        echo json_encode(['success' => false, 'message' => 'Không có quyền.']);
        exit;
    }
    $ok = fm_quota_override_save($_POST['user_id'] ?? 0, $_POST['quota_mb'] ?? 0);
    echo json_encode(['success' => $ok]);
    exit;
}

/** AJAX: gỡ mức ưu tiên dung lượng của 1 user. POST: user_id. */
function storage_priority_deleteAction()
{
    header('Content-Type: application/json; charset=utf-8');
    if (!permission_is_admin(permission_current_user())) {
        echo json_encode(['success' => false, 'message' => 'Không có quyền.']);
        exit;
    }
    fm_quota_override_delete($_POST['user_id'] ?? 0);
    echo json_encode(['success' => true]);
    exit;
}