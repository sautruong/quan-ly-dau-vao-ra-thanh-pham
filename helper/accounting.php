<?php
defined('APPPATH') OR exit('Không được quyền truy cập phần này');

/**
 * Render khối "Bút toán kế toán" dùng chung cho các action có ghi bút toán.
 *
 * @param string $transaction_group  Tên action (ghi vào data-attribute + window.JE_CONFIG).
 * @param string $variant            'je-card' (mặc định) hoặc 'wp-journal-entry'.
 * @param bool   $multi              false (mặc định) → 1 block, id cũ (#je-debit...), tương thích
 *                                   ngược cho các trang chưa nâng cấp. true → nhiều bút toán:
 *                                   nút "THÊM BÚT TOÁN" + danh sách block (flex grid 2), field
 *                                   theo class (.je-debit...) + 1 <template> để JS nhân bản.
 * @param bool   $collapsed_on_load  true → khối bút toán ẩn (thu gọn) sẵn ngay lúc render, chỉ
 *                                   áp dụng ở multi mode; user bấm nút xổ/thu (.btn-toggle-je)
 *                                   để mở ra. Mặc định false (bung ra) như hành vi cũ.
 *
 * Lưu ý: ở single mode các id #je-debit, #je-credit, #je-amount được giữ nguyên để JS riêng của
 * mỗi action không bị vỡ.
 */
function render_journal_entry_block($transaction_group, $variant = 'je-card', $multi = false, $prefill_mode = 'all', $save_mode = 'auto', $collapsed_on_load = false) {
    $prefill_mode = in_array($prefill_mode, ['latest', 'none'], true) ? $prefill_mode : 'all'; // TASK 4 ('none' = trang tự prefill)
    // $save_mode = 'on_record' → KHÔNG auto-save template lúc gõ/blur; trang tự lưu
    // nghiệp vụ ở thời điểm "Ghi" (insert-if-new, không ghi đè). 'auto' = như cũ.
    $defer_save   = ($save_mode === 'on_record');
    $variant    = ($variant === 'wp-journal-entry') ? 'wp-journal-entry' : 'je-card';
    $title_cls  = ($variant === 'wp-journal-entry') ? 'je-title' : 'je-card-title';
    $wrapper_id = ($variant === 'wp-journal-entry') ? 'wp-journal-entry' : 'je-card';
    $group_attr = htmlspecialchars($transaction_group, ENT_QUOTES, 'UTF-8');

    if ($multi) {
        // 1 "bộ" bút toán. $with_ids=true cho block ĐẦU (giữ id cũ #je-debit... để JS từng
        // trang vẫn đọc/đồng bộ entry đầu như single); block nhân bản chỉ dùng class.
        $build_entry = function ($with_ids) {
            $id = function ($name) use ($with_ids) { return $with_ids ? ' id="' . $name . '"' : ''; };
            return '
            <div class="je-block je-entry">
                <button type="button" class="btn-remove-je" title="Bỏ bút toán này">&times;</button>
                <div class="je-row je-row-full">
                    <label>Diễn giải nghiệp vụ:</label>
                    <input type="text"' . $id('je-transaction-name') . ' class="je-input je-transaction-name" list="je-transaction-name-list" autocomplete="off">
                </div>
                <div class="je-row je-row-full je-row-textarea">
                    <label>Mô tả nghiệp vụ:</label>
                    <textarea' . $id('je-description') . ' class="je-input je-textarea je-description" rows="2"></textarea>
                </div>
                <div class="je-row je-row-account">
                    <label>Nợ:</label>
                    <input type="text"' . $id('je-debit') . ' class="je-input je-debit je-account-code" autocomplete="off" placeholder="Số hiệu">
                    <input type="text"' . $id('je-debit-name') . ' class="je-input je-debit-name" autocomplete="off" placeholder="Tên tài khoản nợ">
                </div>
                <div class="je-row je-row-account">
                    <label>Có:</label>
                    <input type="text"' . $id('je-credit') . ' class="je-input je-credit je-account-code" autocomplete="off" placeholder="Số hiệu">
                    <input type="text"' . $id('je-credit-name') . ' class="je-input je-credit-name" autocomplete="off" placeholder="Tên tài khoản có">
                </div>
                <div class="je-row je-row-amount">
                    <label>Giá trị:</label>
                    <input type="text"' . $id('je-amount') . ' class="je-input je-input-amount je-amount" autocomplete="off" inputmode="numeric">
                </div>
            </div>';
        };
        $entry_first  = $build_entry(true);   // block đầu (có id)
        $entry_tpl    = $build_entry(false);  // block nhân bản (chỉ class)
        $wrap_cls     = 'je-multi ' . $variant . '-multi' . ($collapsed_on_load ? ' je-collapsed' : '');
        $expanded_attr = $collapsed_on_load ? 'false' : 'true';
        ?>
        <div class="<?php echo $wrap_cls; ?>" id="<?php echo $wrapper_id; ?>" data-transaction-group="<?php echo $group_attr; ?>" data-je-multi="1">
            <div class="je-multi-head">
                <div class="<?php echo $title_cls; ?>"></div>
                <div class="je-multi-actions">
                    <button type="button" class="btn-add-je">+ BÚT TOÁN KẾ TOÁN</button>
                    <button type="button" class="btn-toggle-je" aria-expanded="<?php echo $expanded_attr; ?>" aria-label="Thu gọn / mở rộng bút toán" title="Thu gọn / mở rộng">
                        <i class="fa-solid fa-chevron-down"></i>
                    </button>
                </div>
            </div>
            <div class="je-collapse">
                <div class="je-list"><?php echo $entry_first; ?></div>
            </div>
            <datalist id="je-transaction-name-list"></datalist>
            <template class="je-block-template"><?php echo $entry_tpl; ?></template>
        </div>

        <script>
            window.JE_CONFIG = window.JE_CONFIG || {};
            window.JE_CONFIG.transactionGroup = <?php echo json_encode($transaction_group, JSON_UNESCAPED_UNICODE); ?>;
            window.JE_CONFIG.ajaxBase = '?mod=accounting&controllers=accounting&action=';
            window.JE_CONFIG.multi = true;
            window.JE_CONFIG.prefillMode = <?php echo json_encode($prefill_mode); ?>;
            window.JE_CONFIG.deferSave = <?php echo $defer_save ? 'true' : 'false'; ?>;
        </script>
        <?php
        return;
    }
    ?>
    <div class="<?php echo $variant; ?> je-block" id="<?php echo $wrapper_id; ?>" data-transaction-group="<?php echo $group_attr; ?>">
        <div class="<?php echo $title_cls; ?>">GHI BÚT TOÁN KẾ TOÁN</div>

        <div class="je-row je-row-full">
            <label for="je-transaction-name">Diễn giải nghiệp vụ:</label>
            <input type="text" id="je-transaction-name" class="je-input" list="je-transaction-name-list" autocomplete="off">
            <datalist id="je-transaction-name-list"></datalist>
        </div>

        <div class="je-row je-row-pair">
            <div class="je-col">
                <label for="je-debit">Nợ:</label>
                <input type="text" id="je-debit" class="je-input" autocomplete="off">
            </div>
            <div class="je-col">
                <label for="je-credit">Có:</label>
                <input type="text" id="je-credit" class="je-input" autocomplete="off">
            </div>
        </div>

        <div class="je-row je-row-pair">
            <div class="je-col">
                <label for="je-debit-name">Tên TK Nợ:</label>
                <input type="text" id="je-debit-name" class="je-input" autocomplete="off">
            </div>
            <div class="je-col">
                <label for="je-credit-name">Tên TK Có:</label>
                <input type="text" id="je-credit-name" class="je-input" autocomplete="off">
            </div>
        </div>

        <div class="je-row">
            <label for="je-amount">Giá trị:</label>
            <input type="text" id="je-amount" class="je-input je-input-amount" autocomplete="off" inputmode="numeric">
        </div>

        <div class="je-row je-row-full je-row-textarea">
            <label for="je-description">Mô tả nghiệp vụ:</label>
            <textarea id="je-description" class="je-input je-textarea" rows="2"></textarea>
        </div>
    </div>

    <script>
        window.JE_CONFIG = window.JE_CONFIG || {};
        window.JE_CONFIG.transactionGroup = <?php echo json_encode($transaction_group, JSON_UNESCAPED_UNICODE); ?>;
        window.JE_CONFIG.ajaxBase = '?mod=accounting&controllers=accounting&action=';
    </script>
    <?php
}

/**
 * Chuẩn hóa payload bút toán (đa bút toán) thành mảng entries [{debit, credit, amount}, ...].
 * - $je['entries'] (mảng từ JSON je_entries trên giao diện) → lọc & dùng.
 * - Nếu không có entries hợp lệ → fallback 1 cặp mặc định $fallback = ['debit','credit','amount']
 *   (thường là kết quả im_je_resolve của trang) để giữ nguyên hành vi single cũ.
 * "Việc ghi/sửa chỉ xét các cụm hiện diện trên giao diện" ⇒ nguồn chính là $je['entries'].
 */
function je_entries_from_payload($je, $fallback = null)
{
    $je  = is_array($je) ? $je : [];
    $out = [];

    // Nguồn entries: $je['entries'] (nếu controller có truyền) hoặc trực tiếp từ POST je_entries.
    $entries_src = [];
    if (!empty($je['entries']) && is_array($je['entries'])) {
        $entries_src = $je['entries'];
    } elseif (isset($_POST['je_entries'])) {
        $decoded = json_decode((string) $_POST['je_entries'], true);
        if (is_array($decoded)) $entries_src = $decoded;
    }

    if (!empty($entries_src)) {
        foreach ($entries_src as $e) {
            if (!is_array($e)) continue;
            $debit  = trim((string) ($e['debit']  ?? ''));
            $credit = trim((string) ($e['credit'] ?? ''));
            $amount = (float) ($e['amount'] ?? 0);
            if ($debit === '' && $credit === '' && $amount <= 0) continue; // bỏ cụm trống
            $out[] = ['debit' => $debit, 'credit' => $credit, 'amount' => $amount];
        }
    }

    if (empty($out) && is_array($fallback)) {
        $out[] = [
            'debit'  => (string) ($fallback['debit']  ?? ''),
            'credit' => (string) ($fallback['credit'] ?? ''),
            'amount' => (float)  ($fallback['amount'] ?? 0),
        ];
    }

    // 1 cụm duy nhất chưa nhập giá trị → dùng amount mặc định (tổng phiếu) như hành vi cũ.
    if (count($out) === 1 && (float) $out[0]['amount'] <= 0 && is_array($fallback)) {
        $out[0]['amount'] = (float) ($fallback['amount'] ?? 0);
    }

    return $out;
}

/**
 * Insert các cặp bút toán (Nợ/Có) vào bảng `transactions` cho 1 reference.
 * KHÔNG tự xóa dòng cũ — caller chịu trách nhiệm delete trước (và ghi các cặp tự động riêng nếu có).
 * Mỗi entry → tối đa 2 dòng; bỏ qua entry amount <= 0; mỗi vế chỉ ghi khi có mã tài khoản.
 */
function je_insert_pairs($reference_type, $reference_id, $entries, $created_at = null)
{
    $rid = (int) $reference_id;
    if ($rid <= 0) return;

    foreach ((array) $entries as $e) {
        $debit  = trim((string) ($e['debit']  ?? ''));
        $credit = trim((string) ($e['credit'] ?? ''));
        $amount = (float) ($e['amount'] ?? 0);
        if ($amount <= 0) continue;

        $base = [
            'amount'         => $amount,
            'reference_type' => $reference_type,
            'reference_id'   => $rid,
        ];
        if ($created_at !== null && $created_at !== '') $base['created_at'] = $created_at;

        if ($debit !== '')  db_insert('transactions', array_merge($base, ['account_code' => $debit,  'type' => 'debit']));
        if ($credit !== '') db_insert('transactions', array_merge($base, ['account_code' => $credit, 'type' => 'credit']));
    }
}

/**
 * Lưu 1 "nghiệp vụ" (template định khoản) vào thư viện CHỈ KHI tên chưa tồn tại.
 * Khác acc_save_mapping_with_entries (upsert/ghi đè): hàm này KHÔNG ghi đè bản đã
 * lưu — trùng (transaction_group, transaction_name) thì giữ nguyên bản cũ.
 * Dùng lúc "Ghi" để gom các nghiệp vụ mới vào datalist #je-transaction-name cho
 * lần sau chọn lại, mà không phá template người dùng đã lưu trước đó.
 *
 * $entries = ['debit'=>['account_code','account_name','amount_formula'], 'credit'=>[...]]
 * Trả mapping_id (int) — của bản cũ nếu đã tồn tại, hoặc bản mới vừa insert.
 */
function je_save_template_if_new($transaction_group, $transaction_name, $description, $entries)
{
    $g = trim((string) $transaction_group);
    $n = trim((string) $transaction_name);
    if ($g === '' || $n === '') return 0;

    $ge = escape_string($g);
    $ne = escape_string($n);
    $row = db_fetch_row("SELECT id FROM accounting_transaction_mapping
                         WHERE transaction_group = '{$ge}' AND transaction_name = '{$ne}'
                         LIMIT 1");
    if (!empty($row['id'])) return (int) $row['id']; // đã có → KHÔNG ghi đè

    $mapping_id = (int) db_insert('accounting_transaction_mapping', [
        'transaction_group' => $g,
        'transaction_name'  => $n,
        'description'       => (string) ($description ?? ''),
    ]);
    if ($mapping_id <= 0) return 0;

    foreach (['debit', 'credit'] as $type) {
        $e    = isset($entries[$type]) && is_array($entries[$type]) ? $entries[$type] : null;
        if (!$e) continue;
        $code = trim((string) ($e['account_code'] ?? ''));
        if ($code === '') continue; // bỏ vế thiếu mã tài khoản
        db_insert('accounting_transaction_entry', [
            'mapping_id'     => $mapping_id,
            'entry_type'     => $type,
            'account_code'   => $code,
            'account_name'   => (string) ($e['account_name']   ?? ''),
            'amount_formula' => (string) ($e['amount_formula'] ?? ''),
        ]);
    }
    return $mapping_id;
}

/* =====================================================================
 *  ĐƠN GIÁ CHI PHÍ SẢN XUẤT (overhead/đơn vị) — lưu 1 dòng config.
 *  Dùng chung: investment_products (TASK 3a) + sales_delivery_note COGS (TASK 3b).
 *  Mặc định 6000; user đổi được; áp dụng hồi tố (không lưu lịch sử).
 * ===================================================================== */
if (!function_exists('production_cost_rate')) {
    function production_cost_rate()
    {
        $row = db_fetch_row("SELECT cost_per_unit FROM production_cost_settings WHERE id = 1 LIMIT 1");
        if (!$row || $row['cost_per_unit'] === null) {
            return 6000.0;
        }
        return (float) $row['cost_per_unit'];
    }
}

if (!function_exists('set_production_cost_rate')) {
    function set_production_cost_rate($value)
    {
        $v = (float) $value;
        if ($v < 0) $v = 0;
        $exists = db_num_rows("SELECT 1 FROM production_cost_settings WHERE id = 1") > 0;
        if ($exists) {
            db_update('production_cost_settings', ['cost_per_unit' => $v], 'id = 1');
        } else {
            db_insert('production_cost_settings', ['id' => 1, 'cost_per_unit' => $v]);
        }
        return $v;
    }
}
