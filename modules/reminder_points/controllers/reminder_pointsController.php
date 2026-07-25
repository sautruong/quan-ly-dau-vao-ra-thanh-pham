<?php
/**
 * "Điểm nhắc" — không có view/trang riêng (widget nằm trong header dùng chung),
 * nên module này chỉ có các *Action() AJAX, không đăng ký tbl_views.
 * Vì vậy mọi action GHI phải tự gọi permission_require_can_edit() theo đúng view đích.
 */

function construct()
{
    load_model('reminder_points');
}

function rp_json($payload)
{
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($payload, JSON_UNESCAPED_UNICODE);
    exit;
}

/* ================= Loại 1: nhắc trước nhập sản xuất ================= */

function rp_search_productAction()
{
    rp_json(['success' => true, 'items' => rp_search_products($_GET['keyword'] ?? '')]);
}

function rp_pre_input_listAction()
{
    rp_json(['success' => true, 'items' => rp_list_pre_input_notes()]);
}

function rp_pre_input_saveAction()
{
    permission_require_can_edit('inventory_management', 'inventory_management', 'investment_products');
    $product_id = (int) ($_POST['product_id'] ?? 0);
    $note       = trim($_POST['note'] ?? '');
    if ($product_id <= 0) {
        rp_json(['success' => false, 'message' => 'Chưa chọn sản phẩm.']);
    }
    rp_save_pre_input_note($product_id, $note);
    rp_json(['success' => true]);
}

function rp_pre_input_deleteAction()
{
    permission_require_can_edit('inventory_management', 'inventory_management', 'investment_products');
    $product_id = (int) ($_POST['product_id'] ?? 0);
    rp_delete_pre_input_note($product_id);
    rp_json(['success' => true]);
}

/* ================= Loại 2: nhắc trước lập KHSX ================= */

function rp_pre_plan_listAction()
{
    rp_json(['success' => true, 'items' => rp_list_pre_plan_reminders()]);
}

/** Thêm 1 lời nhắc MỚI cho sản phẩm (không ghi đè — 1 sản phẩm có thể có nhiều lời nhắc). */
function rp_pre_plan_addAction()
{
    permission_require_can_edit('production_staff', 'production_staff', 'plan_for_staff');
    $product_id = (int) ($_POST['product_id'] ?? 0);
    $note       = trim($_POST['note'] ?? '');
    if ($product_id <= 0 || $note === '') {
        rp_json(['success' => false, 'message' => 'Chưa chọn sản phẩm hoặc chưa nhập nội dung.']);
    }
    rp_add_pre_plan_reminder($product_id, $note);
    rp_json(['success' => true]);
}

function rp_pre_plan_updateAction()
{
    permission_require_can_edit('production_staff', 'production_staff', 'plan_for_staff');
    rp_update_pre_plan_reminder((int) ($_POST['id'] ?? 0), $_POST['note'] ?? '');
    rp_json(['success' => true]);
}

function rp_pre_plan_deleteAction()
{
    permission_require_can_edit('production_staff', 'production_staff', 'plan_for_staff');
    rp_delete_pre_plan_reminder((int) ($_POST['id'] ?? 0));
    rp_json(['success' => true]);
}

/* ================= Loại 3: nhắc trước bốc hàng ================= */

function rp_search_customerAction()
{
    rp_json(['success' => true, 'items' => rp_search_customers($_GET['keyword'] ?? '')]);
}

function rp_pickup_branch_listAction()
{
    rp_json(['success' => true, 'items' => rp_list_branch_reminders()]);
}

function rp_pickup_branch_saveAction()
{
    permission_require_can_edit('order_management', 'order_management', 'branch_orders');
    $note      = trim($_POST['note'] ?? '');
    $applyAll  = !empty($_POST['apply_all']);
    $ids       = $applyAll ? [] : (json_decode($_POST['customer_ids'] ?? '[]', true) ?: []);
    if ($note === '') {
        rp_json(['success' => false, 'message' => 'Chưa nhập nội dung nhắc.']);
    }
    rp_add_branch_reminder($note, $ids);
    rp_json(['success' => true]);
}

function rp_pickup_branch_updateAction()
{
    permission_require_can_edit('order_management', 'order_management', 'branch_orders');
    rp_update_branch_reminder((int) ($_POST['id'] ?? 0), $_POST['note'] ?? '');
    rp_json(['success' => true]);
}

function rp_pickup_branch_deleteAction()
{
    permission_require_can_edit('order_management', 'order_management', 'branch_orders');
    rp_delete_branch_reminder((int) ($_POST['id'] ?? 0));
    rp_json(['success' => true]);
}

function rp_pickup_product_listAction()
{
    rp_json(['success' => true, 'items' => rp_list_product_pickup_reminders()]);
}

function rp_pickup_product_saveAction()
{
    permission_require_can_edit('order_management', 'order_management', 'branch_orders');
    $note     = trim($_POST['note'] ?? '');
    $applyAll = !empty($_POST['apply_all']);
    $ids      = $applyAll ? [] : (json_decode($_POST['product_ids'] ?? '[]', true) ?: []);
    if ($note === '') {
        rp_json(['success' => false, 'message' => 'Chưa nhập nội dung nhắc.']);
    }
    rp_add_product_pickup_reminder($note, $ids);
    rp_json(['success' => true]);
}

function rp_pickup_product_updateAction()
{
    permission_require_can_edit('order_management', 'order_management', 'branch_orders');
    rp_update_product_pickup_reminder((int) ($_POST['id'] ?? 0), $_POST['note'] ?? '');
    rp_json(['success' => true]);
}

function rp_pickup_product_deleteAction()
{
    permission_require_can_edit('order_management', 'order_management', 'branch_orders');
    rp_delete_product_pickup_reminder((int) ($_POST['id'] ?? 0));
    rp_json(['success' => true]);
}
