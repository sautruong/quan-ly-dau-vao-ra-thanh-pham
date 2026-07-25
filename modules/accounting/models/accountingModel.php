<?php
defined('APPPATH') OR exit('Không được quyền truy cập phần này');

/**
 * Lấy toàn bộ mapping + entries của 1 transaction_group.
 *
 * Trả về:
 * [
 *   {
 *     id, transaction_name, description,
 *     entries: { debit: {account_code, account_name, amount_formula},
 *                credit: {...} }
 *   }, ...
 * ]
 */
function acc_list_by_group($transaction_group) {
    $g = escape_string(trim($transaction_group));
    if ($g === '') return array();

    $sql_map = "SELECT id, transaction_name, description, updated_at
                FROM accounting_transaction_mapping
                WHERE transaction_group = '{$g}'
                ORDER BY transaction_name ASC";
    $mappings = db_fetch_array($sql_map);

    if (empty($mappings)) return array();

    $ids = array();
    foreach ($mappings as $m) $ids[] = (int) $m['id'];
    $ids_in = implode(',', $ids);

    $entries_by_map = array();
    $sql_ent = "SELECT mapping_id, entry_type, account_code, account_name, amount_formula
                FROM accounting_transaction_entry
                WHERE mapping_id IN ({$ids_in})";
    $entries = db_fetch_array($sql_ent);
    foreach ($entries as $e) {
        $mid  = (int) $e['mapping_id'];
        $type = $e['entry_type'];
        if (!isset($entries_by_map[$mid])) {
            $entries_by_map[$mid] = array('debit' => null, 'credit' => null);
        }
        $entries_by_map[$mid][$type] = array(
            'account_code'   => $e['account_code'],
            'account_name'   => $e['account_name'],
            'amount_formula' => $e['amount_formula'],
        );
    }

    $result = array();
    foreach ($mappings as $m) {
        $mid = (int) $m['id'];
        $result[] = array(
            'id'               => $mid,
            'transaction_name' => $m['transaction_name'],
            'description'      => $m['description'],
            'updated_at'       => isset($m['updated_at']) ? $m['updated_at'] : null,
            'entries'          => isset($entries_by_map[$mid])
                ? $entries_by_map[$mid]
                : array('debit' => null, 'credit' => null),
        );
    }
    return $result;
}

/**
 * Upsert 1 mapping + 2 entries (debit + credit).
 *
 * $entries = [
 *   'debit'  => ['account_code'=>..., 'account_name'=>..., 'amount_formula'=>...],
 *   'credit' => [...]
 * ]
 *
 * Trả về mapping_id (int).
 */
function acc_save_mapping_with_entries($transaction_group, $transaction_name, $description, $entries) {
    global $conn;

    $g = escape_string(trim($transaction_group));
    $n = escape_string(trim($transaction_name));
    $d = escape_string($description !== null ? $description : '');

    if ($g === '' || $n === '') return 0;

    // 1. Tìm mapping cũ
    $row = db_fetch_row("SELECT id FROM accounting_transaction_mapping
                         WHERE transaction_group = '{$g}' AND transaction_name = '{$n}'
                         LIMIT 1");

    if (!empty($row['id'])) {
        $mapping_id = (int) $row['id'];
        db_query("UPDATE accounting_transaction_mapping
                  SET description = '{$d}'
                  WHERE id = {$mapping_id}");
    } else {
        db_query("INSERT INTO accounting_transaction_mapping
                  (transaction_group, transaction_name, description)
                  VALUES ('{$g}', '{$n}', '{$d}')");
        $mapping_id = (int) mysqli_insert_id($conn);
    }

    if ($mapping_id <= 0) return 0;

    // 2. Xóa 2 entries cũ rồi insert lại
    db_query("DELETE FROM accounting_transaction_entry WHERE mapping_id = {$mapping_id}");

    foreach (array('debit', 'credit') as $type) {
        if (!isset($entries[$type])) continue;
        $e    = $entries[$type];
        $code = isset($e['account_code'])   ? trim($e['account_code'])   : '';
        if ($code === '') continue; // bỏ qua nếu mã tài khoản rỗng

        $code_e = escape_string($code);
        $name_e = escape_string(isset($e['account_name'])   ? $e['account_name']   : '');
        $amt_e  = escape_string(isset($e['amount_formula']) ? $e['amount_formula'] : '');

        db_query("INSERT INTO accounting_transaction_entry
                  (mapping_id, entry_type, account_code, account_name, amount_formula)
                  VALUES ({$mapping_id}, '{$type}', '{$code_e}', '{$name_e}', '{$amt_e}')");
    }

    return $mapping_id;
}
