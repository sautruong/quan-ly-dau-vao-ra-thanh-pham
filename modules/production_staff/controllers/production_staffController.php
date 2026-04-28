<?php
function construct()
{
    load_model('production_staff');
}

function production_planAction()
{
    global $conn;

    $sql = "SELECT pp.id AS plan_id, pp.product_id, pp.quantity, pp.note AS plan_note,
                   pp.sample, pp.test, pp.check_inventory,
                   p.product_name,
                   ppn.note AS pre_note
            FROM production_plans pp
            LEFT JOIN products p ON p.id = pp.product_id
            LEFT JOIN pre_production_notes ppn ON ppn.product_id = pp.product_id
            ORDER BY pp.id ASC";
    $plans = db_fetch_array($sql);

    $sql_tasks = "SELECT id, description FROM additional_tasks ORDER BY id ASC";
    $tasks = db_fetch_array($sql_tasks);

    $data = [
        'plans' => $plans ?: [],
        'tasks' => $tasks ?: []
    ];
    load_view('production_plan', $data);
}

function plan_for_staffAction()
{
    global $conn;

    $sql = "SELECT pp.id AS plan_id, pp.product_id, pp.quantity, pp.note AS plan_note,
                   pp.sample, pp.test, pp.check_inventory,
                   p.product_name,
                   ppn.note AS pre_note,
                   fgi.quantity AS stock_qty,
                   mi_in.material_name AS inner_packaging,
                   mi_out.material_name AS outer_packaging,
                   pib.inner_packaging_spec, pib.outer_packaging_spec
            FROM production_plans pp
            LEFT JOIN products p ON p.id = pp.product_id
            LEFT JOIN pre_production_notes ppn ON ppn.product_id = pp.product_id
            LEFT JOIN finished_goods_inventory fgi ON fgi.product_id = pp.product_id
            LEFT JOIN product_info_basic pib ON pib.product_id = pp.product_id
            LEFT JOIN material_information mi_in ON mi_in.id = pib.inner_packaging_id
            LEFT JOIN material_information mi_out ON mi_out.id = pib.outer_packaging_id
            ORDER BY pp.id ASC";
    $plans = db_fetch_array($sql);

    $sql_tasks = "SELECT id, description FROM additional_tasks ORDER BY id ASC";
    $tasks = db_fetch_array($sql_tasks);

    $data = [
        'plans' => $plans ?: [],
        'tasks' => $tasks ?: []
    ];
    load_view('plan_for_staff', $data);
}

function search_productAction()
{
    global $conn;
    header('Content-Type: application/json');

    $keyword = trim($_POST['keyword'] ?? '');
    if ($keyword === '') {
        echo json_encode(['data' => []]);
        exit;
    }

    $keyword_safe = escape_string($keyword);
    $sql = "SELECT id, product_name
            FROM products
            WHERE product_name LIKE '%$keyword_safe%'
            ORDER BY product_name ASC
            LIMIT 15";
    $result = db_fetch_array($sql);

    echo json_encode(['data' => $result ?: []]);
    exit;
}

function get_product_dataAction()
{
    global $conn;
    header('Content-Type: application/json');

    $product_id = (int) ($_POST['product_id'] ?? 0);
    if ($product_id <= 0) {
        echo json_encode(['success' => false, 'message' => 'Invalid product_id']);
        exit;
    }

    $sql = "SELECT id, product_name FROM products WHERE id = $product_id LIMIT 1";
    $product = db_fetch_row($sql);

    if (empty($product)) {
        echo json_encode(['success' => false, 'message' => 'Product not found']);
        exit;
    }

    $sql_note = "SELECT note FROM pre_production_notes WHERE product_id = $product_id LIMIT 1";
    $note_row = db_fetch_row($sql_note);

    echo json_encode([
        'success' => true,
        'data' => [
            'product_id'   => (int) $product['id'],
            'product_name' => $product['product_name'],
            'note'         => $note_row['note'] ?? ''
        ]
    ]);
    exit;
}

function save_noteAction()
{
    global $conn;
    header('Content-Type: application/json');

    $product_id = (int) ($_POST['product_id'] ?? 0);
    $note       = trim($_POST['note'] ?? '');

    if ($product_id <= 0) {
        echo json_encode(['success' => false, 'message' => 'Invalid product_id']);
        exit;
    }

    $sql_check = "SELECT id FROM pre_production_notes WHERE product_id = $product_id LIMIT 1";
    $existing = db_fetch_row($sql_check);

    if ($note === '') {
        if (!empty($existing)) {
            db_delete('pre_production_notes', "product_id = $product_id");
        }
        echo json_encode(['success' => true, 'deleted' => true]);
        exit;
    }

    if (!empty($existing)) {
        db_update('pre_production_notes', ['note' => $note], "product_id = $product_id");
    } else {
        db_insert('pre_production_notes', [
            'product_id' => $product_id,
            'note'       => $note
        ]);
    }

    echo json_encode(['success' => true]);
    exit;
}

function delete_noteAction()
{
    global $conn;
    header('Content-Type: application/json');

    $product_id = (int) ($_POST['product_id'] ?? 0);
    if ($product_id <= 0) {
        echo json_encode(['success' => false, 'message' => 'Invalid product_id']);
        exit;
    }

    db_delete('pre_production_notes', "product_id = $product_id");
    echo json_encode(['success' => true]);
    exit;
}

function save_planAction()
{
    global $conn;
    header('Content-Type: application/json');

    $items_raw   = $_POST['items'] ?? '[]';
    $tasks_raw   = $_POST['tasks'] ?? '[]';
    $force_clear = !empty($_POST['force_clear']);

    $items = json_decode($items_raw, true) ?: [];
    $tasks = json_decode($tasks_raw, true) ?: [];

    if (empty($items) && !$force_clear) {
        echo json_encode(['success' => false, 'message' => 'Không có sản phẩm nào trong kế hoạch.']);
        exit;
    }

    // 1) Xóa plan cũ + tasks cũ (1 kế hoạch hiện hành)
    db_query("DELETE FROM additional_tasks");
    db_query("DELETE FROM production_plans");

    // 2) Insert plans
    $first_plan_id = 0;
    foreach ($items as $item) {
        $pid = (int) ($item['product_id'] ?? 0);
        $qty = (int) ($item['quantity'] ?? 0);
        $note = trim($item['note'] ?? '');
        $sample = !empty($item['sample']) ? 1 : 0;
        $test = !empty($item['test']) ? 1 : 0;
        $check_inventory = !empty($item['check_inventory']) ? 1 : 0;
        if ($pid <= 0) continue;

        $plan_id = db_insert('production_plans', [
            'product_id'      => $pid,
            'quantity'        => $qty,
            'note'            => $note,
            'sample'          => $sample,
            'test'            => $test,
            'check_inventory' => $check_inventory
        ]);
        if ($first_plan_id === 0) $first_plan_id = (int) $plan_id;
    }

    // 3) Insert tasks (gắn về plan đầu tiên — đại diện kế hoạch)
    if ($first_plan_id > 0 && !empty($tasks)) {
        foreach ($tasks as $desc) {
            $desc = trim((string) $desc);
            if ($desc === '') continue;
            db_insert('additional_tasks', [
                'plan_id'     => $first_plan_id,
                'description' => $desc
            ]);
        }
    }

    echo json_encode(['success' => true]);
    exit;
}
