<?php
defined('APPPATH') OR exit('Không được quyền truy cập phần này');

/* =====================================================================
 *  CASH TRANSACTIONS — page "Nhập thu chi"
 *  Ghi 1 phiếu thu/chi vào bảng cash_transactions, đồng bộ bút toán sang
 *  bảng transactions (reference_type = 'cash_transactions'). Mapping/entry
 *  (accounting_transaction_mapping + accounting_transaction_entry) do khối
 *  je-card tự lưu qua module accounting (journal_entry.js) — giống các tab khác.
 * =====================================================================*/

/** Chuẩn hóa datetime về 'Y-m-d H:i:s'; trả null nếu rỗng/không hợp lệ. */
function ct_normalize_datetime($s)
{
    $s = trim((string) $s);
    if ($s === '') return null;
    $ts = strtotime($s);
    return $ts ? date('Y-m-d H:i:s', $ts) : null;
}

/** Lấy 1 phiếu thu chi theo id. */
function ct_get($id)
{
    $id = (int) $id;
    if ($id <= 0) return null;
    return db_fetch_row("SELECT id, description, transaction_type, account_name,
                                account_number, amount, created_at
                         FROM cash_transactions WHERE id = $id LIMIT 1");
}

/** Format 1 dòng cho lịch sử (thêm date_display + date_color). */
function ct_format_history_row($r)
{
    $hist_date = history_date_display($r['created_at'] ?? '');
    return [
        'id'               => (int) $r['id'],
        'description'      => $r['description'],
        'transaction_type' => $r['transaction_type'],
        'account_name'     => $r['account_name'],
        'account_number'   => $r['account_number'] !== null ? $r['account_number'] : '',
        'amount'           => (float) $r['amount'],
        'created_at'       => $r['created_at'],
        'date_display'     => $hist_date['text'],
        'date_color'       => $hist_date['color'],
    ];
}

/** Lấy lịch sử thu chi (mới → cũ), tối đa $limit dòng (JS phân trang 5/trang). */
function ct_get_history($limit = 100)
{
    $limit = (int) $limit;
    if ($limit <= 0) $limit = 100;
    $rows = db_fetch_array("SELECT id, description, transaction_type, account_name,
                                   account_number, amount, created_at
                            FROM cash_transactions
                            ORDER BY created_at DESC, id DESC
                            LIMIT $limit") ?: [];
    return array_map('ct_format_history_row', $rows);
}

/**
 * Đồng bộ bút toán trong `transactions` cho 1 phiếu thu chi (đa bút toán):
 * xóa hết dòng cũ (reference_type='cash_transactions', reference_id=$id) rồi insert lại
 * 2 dòng (Nợ + Có) cho MỖI entry. Mỗi entry: {debit, credit, amount}.
 * Bỏ qua entry có amount <= 0; mỗi vế chỉ ghi khi có mã tài khoản.
 */
function ct_sync_transactions($cash_id, $entries, $created_at = null)
{
    $cash_id = (int) $cash_id;
    if ($cash_id <= 0) return;

    db_delete('transactions', "reference_type = 'cash_transactions' AND reference_id = $cash_id");

    foreach ((array) $entries as $e) {
        $debit  = trim((string) ($e['debit']  ?? ''));
        $credit = trim((string) ($e['credit'] ?? ''));
        $amount = (float) ($e['amount'] ?? 0);
        $tname  = trim((string) ($e['transaction_name'] ?? ''));
        if ($amount <= 0) continue;

        $base = [
            'amount'           => $amount,
            'reference_type'   => 'cash_transactions',
            'reference_id'     => $cash_id,
            'transaction_name' => $tname !== '' ? $tname : null,
        ];
        if ($created_at !== null) $base['created_at'] = $created_at;

        if ($debit !== '')  db_insert('transactions', array_merge($base, ['account_code' => $debit,  'type' => 'debit']));
        if ($credit !== '') db_insert('transactions', array_merge($base, ['account_code' => $credit, 'type' => 'credit']));
    }
}

/**
 * Chuẩn hóa danh sách bút toán từ payload $je.
 * - $je['entries'] (mảng) → dùng trực tiếp.
 * - fallback: 1 entry từ $je['debit']/$je['credit']/$je['amount'] (tương thích single).
 * $fallback_amount: dùng khi entry không có amount (>0) — giữ hành vi cũ (lấy số tiền phiếu).
 */
function ct_je_entries($je, $fallback_amount = 0)
{
    $je = is_array($je) ? $je : [];
    $entries = [];

    if (!empty($je['entries']) && is_array($je['entries'])) {
        $entries = $je['entries'];
    } else {
        $debit  = trim((string) ($je['debit']  ?? ''));
        $credit = trim((string) ($je['credit'] ?? ''));
        $amount = (float) ($je['amount'] ?? 0);
        if ($debit !== '' || $credit !== '' || $amount > 0) {
            $entries = [['debit' => $debit, 'credit' => $credit, 'amount' => $amount]];
        }
    }

    // Một entry duy nhất mà chưa nhập giá trị → fallback số tiền phiếu (hành vi cũ).
    if (count($entries) === 1 && (float) ($entries[0]['amount'] ?? 0) <= 0 && $fallback_amount > 0) {
        $entries[0]['amount'] = (float) $fallback_amount;
    }
    return $entries;
}

/** Validate + chuẩn hóa payload form. Trả null nếu thiếu/không hợp lệ. */
function ct_sanitize_payload($data)
{
    $description = trim((string) ($data['description'] ?? ''));
    $type        = trim((string) ($data['transaction_type'] ?? ''));
    $account     = trim((string) ($data['account_name'] ?? ''));
    $acc_number  = trim((string) ($data['account_number'] ?? ''));
    $amount      = (float) ($data['amount'] ?? 0);

    if ($description === '' || $amount <= 0) return null;
    if (!in_array($type, ['Thu', 'Chi'], true)) return null;
    if (!in_array($account, ['TM', 'TG'], true)) return null;
    if ($account === 'TG' && $acc_number === '') return null; // TG bắt buộc số tài khoản
    if ($account !== 'TG') $acc_number = '';                  // TM không cần số tài khoản

    return [
        'description'      => $description,
        'transaction_type' => $type,
        'account_name'     => $account,
        'account_number'   => $acc_number !== '' ? $acc_number : null,
        'amount'           => $amount,
    ];
}

/**
 * Ghi 1 phiếu thu chi mới + đồng bộ bút toán transactions.
 * Trả id mới (0 nếu thiếu/không hợp lệ).
 */
function ct_record($data, $je = null)
{
    $row = ct_sanitize_payload($data);
    if ($row === null) return 0;
    $ca = ct_normalize_datetime($data['created_at'] ?? '');
    if ($ca !== null) $row['created_at'] = $ca;

    $id = (int) db_insert('cash_transactions', $row);
    if ($id <= 0) return 0;

    ct_sync_transactions($id, ct_je_entries($je, (float) $row['amount']), $ca);

    return $id;
}

/** Cập nhật 1 phiếu thu chi + đồng bộ lại bút toán. */
function ct_update($id, $data, $je = null)
{
    $id = (int) $id;
    if ($id <= 0 || !ct_get($id)) return false;

    $row = ct_sanitize_payload($data);
    if ($row === null) return false;
    $ca = ct_normalize_datetime($data['created_at'] ?? '');
    if ($ca !== null) $row['created_at'] = $ca;

    db_update('cash_transactions', $row, "id = $id");

    ct_sync_transactions($id, ct_je_entries($je, (float) $row['amount']), $ca);

    return true;
}

/**
 * Tra mẫu định khoản của group 'cash_transactions' theo Diễn giải nghiệp vụ.
 * Trả [ 'by_name' => [transaction_name => {description, debit_name, credit_name}],
 *       'by_code' => [account_code => account_name] ] để bù tên TK cho dữ liệu cũ.
 */
function ct_je_template_lookup()
{
    $rows = db_fetch_array("
        SELECT m.transaction_name, m.description,
               de.account_code AS debit_code,  de.account_name AS debit_name,
               ce.account_code AS credit_code, ce.account_name AS credit_name
        FROM accounting_transaction_mapping m
        LEFT JOIN accounting_transaction_entry de ON de.mapping_id = m.id AND de.entry_type = 'debit'
        LEFT JOIN accounting_transaction_entry ce ON ce.mapping_id = m.id AND ce.entry_type = 'credit'
        WHERE m.transaction_group = 'cash_transactions'
        ORDER BY m.updated_at DESC, m.id DESC
    ") ?: [];

    $by_name = [];
    $by_code = [];
    foreach ($rows as $tp) {
        $name = (string) ($tp['transaction_name'] ?? '');
        if ($name !== '' && !isset($by_name[$name])) $by_name[$name] = $tp;
        $dc = trim((string) ($tp['debit_code']  ?? ''));
        $cc = trim((string) ($tp['credit_code'] ?? ''));
        if ($dc !== '' && !isset($by_code[$dc]) && !empty($tp['debit_name']))  $by_code[$dc] = $tp['debit_name'];
        if ($cc !== '' && !isset($by_code[$cc]) && !empty($tp['credit_name'])) $by_code[$cc] = $tp['credit_name'];
    }
    return ['by_name' => $by_name, 'by_code' => $by_code];
}

/**
 * Dựng lại các cụm bút toán (Nợ/Có) đã ghi của 1 phiếu thu chi từ bảng transactions.
 * Mỗi cụm mang theo transaction_name (Diễn giải nghiệp vụ) đã lưu → tra "Mô tả nghiệp vụ"
 * + tên TK riêng theo từng diễn giải.
 * $keep_amount = true  → giữ nguyên Giá trị đã ghi (dùng khi "Sửa").
 * $keep_amount = false → để trống Giá trị (gợi ý cho phiếu MỚI).
 */
function ct_build_entries($cash_id, $keep_amount = true)
{
    $cash_id = (int) $cash_id;
    if ($cash_id <= 0) return [];

    // Thứ tự insert: Nợ rồi Có cho mỗi cụm; transaction_name lưu kèm từng dòng.
    $tx = db_fetch_array("SELECT account_code, type, amount, transaction_name
                          FROM transactions
                          WHERE reference_type = 'cash_transactions' AND reference_id = {$cash_id}
                          ORDER BY id ASC") ?: [];
    if (empty($tx)) return [];

    $entries = [];
    $cur = null;
    foreach ($tx as $t) {
        $code = trim((string) $t['account_code']);
        $name = (string) ($t['transaction_name'] ?? '');
        $amt  = (float) $t['amount'];
        if ($t['type'] === 'debit') {
            if ($cur !== null) $entries[] = $cur;
            $cur = ['debit' => $code, 'credit' => '', 'amount' => $amt, 'transaction_name' => $name];
        } else {
            if ($cur === null) {
                $cur = ['debit' => '', 'credit' => $code, 'amount' => $amt, 'transaction_name' => $name];
            } elseif ($cur['credit'] === '') {
                $cur['credit'] = $code;
                if ($cur['transaction_name'] === '') $cur['transaction_name'] = $name;
            } else {
                $entries[] = $cur;
                $cur = ['debit' => '', 'credit' => $code, 'amount' => $amt, 'transaction_name' => $name];
            }
        }
    }
    if ($cur !== null) $entries[] = $cur;
    if (empty($entries)) return [];

    $lk      = ct_je_template_lookup();
    $by_name = $lk['by_name'];
    $by_code = $lk['by_code'];

    $out = [];
    foreach ($entries as $e) {
        $tp = $by_name[$e['transaction_name']] ?? null; // mẫu theo đúng diễn giải nghiệp vụ
        $out[] = [
            'transaction_name' => $e['transaction_name'],
            'description'      => $tp['description'] ?? '',
            'debit'       => $e['debit'],
            'debit_name'  => $e['debit']  !== '' ? ($tp['debit_name']  ?? ($by_code[$e['debit']]  ?? '')) : '',
            'credit'      => $e['credit'],
            'credit_name' => $e['credit'] !== '' ? ($tp['credit_name'] ?? ($by_code[$e['credit']] ?? '')) : '',
            'amount'      => $keep_amount ? $e['amount'] : '',
        ];
    }
    return $out;
}

/**
 * Bút toán "gợi ý" cho phiếu MỚI theo Phân loại (Thu/Chi) + Tài khoản (TM/TG):
 * tìm phiếu gần nhất CÙNG loại + tài khoản, dựng lại các cụm Nợ/Có (để trống Giá trị).
 */
function ct_recent_journal_entries($type, $account)
{
    $type    = trim((string) $type);
    $account = trim((string) $account);
    if (!in_array($type, ['Thu', 'Chi'], true)) return [];
    if (!in_array($account, ['TM', 'TG'], true)) return [];

    $type_e = escape_string($type);
    $acc_e  = escape_string($account);

    $row = db_fetch_row("SELECT id FROM cash_transactions
                         WHERE transaction_type = '{$type_e}' AND account_name = '{$acc_e}'
                         ORDER BY created_at DESC, id DESC LIMIT 1");
    if (empty($row['id'])) return [];

    return ct_build_entries((int) $row['id'], false); // false = để trống Giá trị
}

/** Bút toán đã ghi của 1 phiếu (giữ nguyên Giá trị) — dùng khi bấm "Sửa". */
function ct_journal_entries($id)
{
    return ct_build_entries((int) $id, true);
}

/**
 * Danh sách các NGHIỆP VỤ (transaction_name) ĐÃ NHẬP trước đây theo Phân loại (Thu/Chi)
 * + Tài khoản (TM/TG), để user xổ chọn (right-click trên ô "Diễn giải nghiệp vụ").
 * Mỗi nghiệp vụ là DISTINCT theo tên, lấy cụm Nợ/Có gần nhất (để trống Giá trị).
 * Khác ct_recent_journal_entries (chỉ lấy phiếu gần nhất): hàm này gom HẾT lịch sử.
 */
function ct_journal_name_options($type, $account)
{
    $type    = trim((string) $type);
    $account = trim((string) $account);
    if (!in_array($type, ['Thu', 'Chi'], true)) return [];
    if (!in_array($account, ['TM', 'TG'], true)) return [];

    $type_e = escape_string($type);
    $acc_e  = escape_string($account);

    // Mỗi nghiệp vụ → phiếu (reference_id) gần nhất có nó, lọc theo loại + tài khoản.
    $rows = db_fetch_array("
        SELECT t.transaction_name, MAX(t.reference_id) AS ref_id
        FROM transactions t
        JOIN cash_transactions c ON c.id = t.reference_id
        WHERE t.reference_type = 'cash_transactions'
          AND c.transaction_type = '{$type_e}' AND c.account_name = '{$acc_e}'
          AND t.transaction_name IS NOT NULL AND t.transaction_name <> ''
        GROUP BY t.transaction_name
        ORDER BY ref_id DESC
    ") ?: [];
    if (empty($rows)) return [];

    // Dựng cụm Nợ/Có cho từng phiếu một lần rồi tra theo tên nghiệp vụ.
    $by_ref = [];
    $out    = [];
    foreach ($rows as $r) {
        $name = (string) $r['transaction_name'];
        $ref  = (int) $r['ref_id'];
        if (!isset($by_ref[$ref])) {
            $idx = [];
            foreach (ct_build_entries($ref, false) as $e) { // false = để trống Giá trị
                $n = (string) ($e['transaction_name'] ?? '');
                if ($n !== '' && !isset($idx[$n])) $idx[$n] = $e;
            }
            $by_ref[$ref] = $idx;
        }
        if (isset($by_ref[$ref][$name])) $out[] = $by_ref[$ref][$name];
    }
    return $out;
}

/** Xóa 1 phiếu thu chi + bút toán transactions liên quan. */
function ct_delete($id)
{
    $id = (int) $id;
    if ($id <= 0) return false;
    db_delete('transactions', "reference_type = 'cash_transactions' AND reference_id = $id");
    db_delete('cash_transactions', "id = $id");
    return true;
}
