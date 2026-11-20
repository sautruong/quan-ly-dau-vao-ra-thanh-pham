<?php
/**
 * formula_image — HÌNH CÔNG THỨC MẺ SẢN XUẤT theo (sản phẩm + sản lượng), dùng ở KH hằng ngày.
 *
 * User chốt 2026-11-09:
 *  - Ảnh công thức là ảnh admin TẢI TỪ MÁY (công thức mẻ, có tính lặp lại), gắn theo (product, qty).
 *  - Nếu (product, qty) chưa có ảnh riêng → lấy ảnh của sản lượng LÂN CẬN theo biên ±3%:
 *      ảnh gốc B phủ dải [floor(B×0.97) … ceil(B×1.03)] (vd B=46 phủ 44..48). Chọn B gần qty nhất.
 *  - User thường chỉ XEM được khi admin DUYỆT cho TỪNG kế hoạch (plan_date), không duyệt 1 lần.
 *
 * Prefix fmi_*. Hai bảng tự tạo: product_formula_images + plan_formula_approvals.
 * File upload vào public/uploads/formula_images (thư mục uploads được .gitignore bảo vệ).
 */

if (date_default_timezone_get() !== 'Asia/Ho_Chi_Minh') {
    date_default_timezone_set('Asia/Ho_Chi_Minh');
}

if (!function_exists('fmi_ensure_tables')) {

    function fmi_ensure_tables()
    {
        static $done = false;
        if ($done) return;
        $done = true;

        db_query("CREATE TABLE IF NOT EXISTS product_formula_images (
            id          INT(11) NOT NULL AUTO_INCREMENT,
            product_id  INT(11) NOT NULL,
            quantity    INT(11) NOT NULL,
            image_file  VARCHAR(255) NOT NULL,
            uploaded_by INT(11) NOT NULL DEFAULT 0,
            created_at  DATETIME NOT NULL,
            updated_at  DATETIME NOT NULL,
            PRIMARY KEY (id),
            UNIQUE KEY uq_prod_qty (product_id, quantity),
            KEY idx_prod (product_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci");

        /* Duyệt theo TỪNG kế hoạch: 1 dòng = (plan_date, product) đã được admin duyệt cho NV xem.
           plan_date đổi mỗi lần công bố KH mới → tự nhiên phải duyệt lại. */
        db_query("CREATE TABLE IF NOT EXISTS plan_formula_approvals (
            plan_date   DATE NOT NULL,
            product_id  INT(11) NOT NULL,
            approved_by INT(11) NOT NULL DEFAULT 0,
            approved_at DATETIME NOT NULL,
            PRIMARY KEY (plan_date, product_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci");

        /* LỜI NHẮC theo sản phẩm (admin nhập ở modal Công thức): hiện cho NV TRƯỚC khi xem hình công thức.
           Khác duyệt (theo từng kế hoạch), lời nhắc LƯU MÃI cho tới khi admin sửa/xoá → khoá = product_id. */
        db_query("CREATE TABLE IF NOT EXISTS product_formula_notes (
            product_id  INT(11) NOT NULL,
            note        TEXT NOT NULL,
            updated_by  INT(11) NOT NULL DEFAULT 0,
            updated_at  DATETIME NOT NULL,
            PRIMARY KEY (product_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci");

        /* MÔ TẢ công thức (chữ thuần, admin nhập cạnh chỗ nạp hình): hiện ở CHÂN hình cho NV một lúc rồi ẩn.
           Gắn với HÌNH → khoá (product_id, quantity) = sản lượng của chính hình đó (xem fmi_desc_key). */
        db_query("CREATE TABLE IF NOT EXISTS product_formula_descs (
            product_id  INT(11) NOT NULL,
            quantity    INT(11) NOT NULL,
            description TEXT NOT NULL,
            updated_by  INT(11) NOT NULL DEFAULT 0,
            updated_at  DATETIME NOT NULL,
            PRIMARY KEY (product_id, quantity)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci");
    }

    function fmi_now() { return date('Y-m-d H:i:s'); }

    /** Thư mục lưu ảnh (tương đối web root). */
    function fmi_dir_rel() { return 'public/uploads/formula_images'; }

    /* ============================================================
     *  TÌM ẢNH CHO (SẢN PHẨM, SẢN LƯỢNG) — ƯU TIÊN ĐÚNG, SAU ĐÓ LÂN CẬN ±3%
     * ============================================================ */

    /**
     * Trả ['image_file'=>path, 'source_quantity'=>B, 'exact'=>bool] hoặc null nếu không có ảnh phủ.
     */
    function fmi_find_image($product_id, $quantity)
    {
        fmi_ensure_tables();
        $pid = (int) $product_id;
        $q   = (int) round((float) $quantity);
        if ($pid <= 0) return null;

        // 1) Ảnh đúng (product, qty).
        $row = db_fetch_row("SELECT quantity, image_file FROM product_formula_images
                             WHERE product_id = $pid AND quantity = $q LIMIT 1");
        if ($row) {
            return ['image_file' => (string) $row['image_file'], 'source_quantity' => (int) $row['quantity'], 'exact' => true];
        }

        // 2) Ảnh lân cận: ảnh gốc B phủ [floor(B×0.97) … ceil(B×1.03)]; chọn B gần q nhất.
        $rows = db_fetch_array("SELECT quantity, image_file FROM product_formula_images WHERE product_id = $pid") ?: [];
        $best = null; $bestDiff = PHP_INT_MAX;
        foreach ($rows as $r) {
            $B  = (int) $r['quantity'];
            if ($B <= 0) continue;
            $lo = (int) floor($B * 0.97);
            $hi = (int) ceil($B * 1.03);
            if ($q >= $lo && $q <= $hi) {
                $d = abs($B - $q);
                if ($d < $bestDiff) {
                    $bestDiff = $d;
                    $best = ['image_file' => (string) $r['image_file'], 'source_quantity' => $B, 'exact' => false];
                }
            }
        }
        return $best;
    }

    /* ============================================================
     *  LƯU ẢNH (admin tải từ máy)
     * ============================================================ */

    /**
     * Lưu/ghi đè ảnh công thức cho (product, qty). $file = 1 phần tử $_FILES.
     * Trả ['ok'=>bool,'image_file'=>path] | ['ok'=>false,'message'=>...].
     */
    function fmi_save_image($product_id, $quantity, $file, $by = 0)
    {
        fmi_ensure_tables();
        $pid = (int) $product_id;
        $q   = (int) round((float) $quantity);
        if ($pid <= 0 || $q <= 0) return ['ok' => false, 'message' => 'Thiếu sản phẩm hoặc sản lượng.'];
        if (empty($file) || ($file['error'] ?? 1) !== UPLOAD_ERR_OK) {
            return ['ok' => false, 'message' => 'Chưa chọn được ảnh hợp lệ.'];
        }

        $ext = strtolower(pathinfo((string) ($file['name'] ?? ''), PATHINFO_EXTENSION));
        $allow = ['jpg', 'jpeg', 'png', 'webp', 'gif'];
        if (!in_array($ext, $allow, true)) return ['ok' => false, 'message' => 'Chỉ nhận ảnh JPG/PNG/WEBP/GIF.'];
        if (!@getimagesize($file['tmp_name'])) return ['ok' => false, 'message' => 'File không phải ảnh.'];

        $abs_dir = APPPATH . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, fmi_dir_rel());
        if (!is_dir($abs_dir)) @mkdir($abs_dir, 0777, true);

        $filename = 'f' . $pid . '_q' . $q . '_' . time() . '.' . $ext;
        $abs_path = $abs_dir . DIRECTORY_SEPARATOR . $filename;
        if (!move_uploaded_file($file['tmp_name'], $abs_path)) {
            return ['ok' => false, 'message' => 'Không lưu được ảnh lên máy chủ.'];
        }
        $rel = fmi_dir_rel() . '/' . $filename;

        $old = db_fetch_row("SELECT id, image_file FROM product_formula_images
                             WHERE product_id = $pid AND quantity = $q LIMIT 1");
        $now = fmi_now();
        if ($old) {
            // Xoá file cũ cho gọn (nếu còn).
            $oldAbs = APPPATH . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, (string) $old['image_file']);
            if (is_file($oldAbs) && strpos($oldAbs, 'formula_images') !== false) @unlink($oldAbs);
            db_update('product_formula_images',
                ['image_file' => $rel, 'uploaded_by' => (int) $by, 'updated_at' => $now],
                'id = ' . (int) $old['id']);
        } else {
            db_insert('product_formula_images', [
                'product_id' => $pid, 'quantity' => $q, 'image_file' => $rel,
                'uploaded_by' => (int) $by, 'created_at' => $now, 'updated_at' => $now,
            ]);
        }
        return ['ok' => true, 'image_file' => $rel];
    }

    /**
     * GỠ hình công thức đang hiển thị cho (sản phẩm, sản lượng thẻ): xoá dòng DB + file ảnh trên máy chủ.
     * "Hình đang hiển thị" = hình đúng sản lượng, hoặc hình lân cận ±3% nếu chưa có hình riêng → gỡ hình GỐC đó
     * (nên các sản lượng lân cận dùng chung hình ấy cũng mất hình). Giữ nguyên duyệt / lời nhắc / mô tả.
     * Trả ['ok'=>true,'quantity'=>sản lượng gốc của hình đã gỡ] hoặc ['ok'=>false,'message'=>...].
     */
    function fmi_delete_image($product_id, $quantity)
    {
        fmi_ensure_tables();
        $pid = (int) $product_id;
        if ($pid <= 0) return ['ok' => false, 'message' => 'Thiếu sản phẩm.'];
        $img = fmi_find_image($pid, $quantity);
        if (!$img) return ['ok' => false, 'message' => 'Chưa có hình công thức để gỡ.'];

        $q   = (int) $img['source_quantity'];
        $row = db_fetch_row("SELECT id, image_file FROM product_formula_images WHERE product_id = $pid AND quantity = $q LIMIT 1");
        if (!$row) return ['ok' => false, 'message' => 'Chưa có hình công thức để gỡ.'];
        db_delete('product_formula_images', 'id = ' . (int) $row['id']);

        // Xoá file: CHỈ trong thư mục formula_images và theo TÊN file (basename) — không bao giờ xoá file ngoài thư mục này,
        // dù cột image_file có trỏ đi đâu; và không xoá nếu dòng khác còn dùng chung đúng file đó.
        $name = basename(str_replace('\\', '/', (string) $row['image_file']));
        if ($name !== '' && $name !== '.' && $name !== '..') {
            $still = db_num_rows("SELECT 1 FROM product_formula_images WHERE image_file LIKE '%" . escape_string($name) . "' LIMIT 1");
            $abs   = APPPATH . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, fmi_dir_rel()) . DIRECTORY_SEPARATOR . $name;
            if (!$still && is_file($abs)) @unlink($abs);
        }
        return ['ok' => true, 'quantity' => $q];
    }

    /* ============================================================
     *  DUYỆT THEO TỪNG KẾ HOẠCH
     * ============================================================ */

    function fmi_is_approved($plan_date, $product_id)
    {
        fmi_ensure_tables();
        $d = date('Y-m-d', strtotime((string) $plan_date));
        $pid = (int) $product_id;
        if ($pid <= 0) return false;
        return db_num_rows("SELECT 1 FROM plan_formula_approvals
                            WHERE plan_date = '" . escape_string($d) . "' AND product_id = $pid LIMIT 1") > 0;
    }

    /** Bật/tắt duyệt. $on=true → duyệt, false → gỡ duyệt. */
    function fmi_set_approved($plan_date, $product_id, $by, $on)
    {
        fmi_ensure_tables();
        $d = date('Y-m-d', strtotime((string) $plan_date));
        $pid = (int) $product_id;
        if ($pid <= 0) return false;
        if ($on) {
            db_query("INSERT INTO plan_formula_approvals (plan_date, product_id, approved_by, approved_at)
                      VALUES ('" . escape_string($d) . "', $pid, " . (int) $by . ", '" . fmi_now() . "')
                      ON DUPLICATE KEY UPDATE approved_by = VALUES(approved_by), approved_at = VALUES(approved_at)");
        } else {
            db_delete('plan_formula_approvals', "plan_date = '" . escape_string($d) . "' AND product_id = $pid");
        }
        return true;
    }

    /** Mảng product_id đã được duyệt cho 1 plan_date (để view render nhanh, 1 truy vấn). */
    function fmi_approved_pids($plan_date)
    {
        fmi_ensure_tables();
        $d = date('Y-m-d', strtotime((string) $plan_date));
        $rows = db_fetch_array("SELECT product_id FROM plan_formula_approvals
                                WHERE plan_date = '" . escape_string($d) . "'") ?: [];
        return array_map(static fn($r) => (int) $r['product_id'], $rows);
    }

    /* ============================================================
     *  LỜI NHẮC CÔNG THỨC (theo sản phẩm, lưu cho tới khi đổi/xoá)
     *
     *  Lời nhắc là HTML ĐỊNH DẠNG ĐƠN GIẢN do admin soạn: in đậm (b), nghiêng (i), gạch chân (u),
     *  gạch đầu dòng (ul/li) và MÀU CHỮ (span style=color:#rrggbb). Mọi thứ khác bị lọc bỏ ở
     *  fmi_note_sanitize_html() trước khi lưu VÀ trước khi trả ra → hiển thị bằng innerHTML là an toàn.
     *  Ghi chú cũ lưu dạng chữ thường (không thẻ) vẫn đọc được: fmi_note_to_html() tự đổi sang HTML.
     * ============================================================ */

    /** Độ dài tối đa của lời nhắc: số ký tự HIỂN THỊ (không tính thẻ định dạng). */
    function fmi_note_max() { return 500; }

    /** Trần độ dài HTML sau khi lọc (chặn lạm dụng định dạng lồng nhau). */
    function fmi_note_html_max() { return 6000; }

    /** Chuẩn hoá màu về #rrggbb; chỉ nhận #rgb, #rrggbb, rgb()/rgba(). Sai → ''. */
    function fmi_note_color($v)
    {
        $v = strtolower(trim((string) $v));
        if (preg_match('/^#([0-9a-f])([0-9a-f])([0-9a-f])$/', $v, $m)) return '#' . $m[1] . $m[1] . $m[2] . $m[2] . $m[3] . $m[3];
        if (preg_match('/^#[0-9a-f]{6}$/', $v)) return $v;
        if (preg_match('/^rgba?\(\s*(\d{1,3})\s*,\s*(\d{1,3})\s*,\s*(\d{1,3})\s*(?:,\s*[\d.]+\s*)?\)$/', $v, $m)) {
            return sprintf('#%02x%02x%02x', min(255, (int) $m[1]), min(255, (int) $m[2]), min(255, (int) $m[3]));
        }
        return '';
    }

    /** Làm sạch đệ quy các con của $parent theo danh sách thẻ cho phép. */
    function fmi_note_clean_children(DOMNode $parent, DOMDocument $doc)
    {
        $drop  = ['script', 'style', 'iframe', 'object', 'embed', 'noscript', 'template', 'svg', 'math', 'link', 'meta',
                  'title', 'head', 'form', 'input', 'button', 'textarea', 'select', 'img', 'video', 'audio', 'canvas'];
        $keep  = ['b', 'i', 'u', 'ul', 'ol', 'li', 'br', 'div', 'p', 'span'];
        $alias = ['strong' => 'b', 'em' => 'i'];

        foreach (iterator_to_array($parent->childNodes) as $n) {
            if ($n->nodeType === XML_TEXT_NODE) continue;
            if ($n->nodeType !== XML_ELEMENT_NODE) { $parent->removeChild($n); continue; }   // comment, PI, ...
            /** @var DOMElement $n */

            $tag = strtolower($n->nodeName);
            if (in_array($tag, $drop, true)) { $parent->removeChild($n); continue; }          // bỏ cả ruột

            fmi_note_clean_children($n, $doc);                                                // sạch con trước

            $color = '';
            if ($tag === 'font') {
                $color = fmi_note_color($n->getAttribute('color'));
                $tag = 'span';
            } elseif ($n->hasAttribute('style')
                      && preg_match('/(?:^|;)\s*color\s*:\s*([^;]+)/i', $n->getAttribute('style'), $m)) {
                // Trình duyệt có thể gắn color thẳng vào <u>/<b>/<i>/<li>... (không tạo <span>) → giữ màu ở BẤT KỲ thẻ cho phép.
                $color = fmi_note_color($m[1]);
            }
            if (isset($alias[$tag])) $tag = $alias[$tag];

            if (!in_array($tag, $keep, true) || ($tag === 'span' && $color === '')) {
                // Thẻ không cho phép (hoặc span không màu): gỡ vỏ, giữ nội dung.
                while ($n->firstChild) $parent->insertBefore($n->firstChild, $n);
                $parent->removeChild($n);
                continue;
            }
            // Dựng thẻ MỚI sạch mọi thuộc tính (chặn onclick/href/style lạ...).
            $new = $doc->createElement($tag);
            if ($color !== '') $new->setAttribute('style', 'color:' . $color);   // chỉ thuộc tính duy nhất được giữ
            while ($n->firstChild) $new->appendChild($n->firstChild);
            $parent->replaceChild($new, $n);
        }
    }

    /**
     * Lọc HTML lời nhắc → ['html' => đã lọc, 'text' => chữ thuần (trim)].
     * Giữ: b i u ul ol li br div p + span màu. Còn lại gỡ vỏ/xoá. Bỏ xuống dòng/thẻ rỗng ở đầu-cuối.
     */
    function fmi_note_sanitize_html($html)
    {
        $html = str_replace(["\r\n", "\r"], "\n", (string) $html);
        if (trim($html) === '') return ['html' => '', 'text' => ''];

        if (!class_exists('DOMDocument')) {   // dự phòng: không có ext dom → hạ về chữ thuần
            $t = trim(strip_tags($html));
            return ['html' => nl2br(htmlspecialchars($t, ENT_QUOTES, 'UTF-8'), false), 'text' => $t];
        }

        $prev = libxml_use_internal_errors(true);
        $doc  = new DOMDocument('1.0', 'UTF-8');
        $ok   = $doc->loadHTML('<?xml encoding="UTF-8"><div id="fmi-root">' . $html . '</div>',
                               LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD);
        libxml_clear_errors();
        libxml_use_internal_errors($prev);
        $root = $ok ? $doc->getElementsByTagName('div')->item(0) : null;
        if (!$root) return ['html' => '', 'text' => ''];

        fmi_note_clean_children($root, $doc);

        $text = preg_replace('/^[\s\x{00A0}]+|[\s\x{00A0}]+$/u', '', (string) $root->textContent);
        $out = '';
        foreach ($root->childNodes as $c) $out .= $doc->saveHTML($c);
        // Dọn xuống dòng thừa ở đầu/cuối (trình soạn hay để lại <br>, <div><br></div>).
        $blank = '(?:\s|&nbsp;|<br\s*/?>|<div>(?:\s|&nbsp;|<br\s*/?>)*</div>|<p>(?:\s|&nbsp;|<br\s*/?>)*</p>)+';
        $out = preg_replace('~^' . $blank . '~i', '', $out);
        $out = preg_replace('~' . $blank . '$~i', '', $out);
        return ['html' => $text === '' ? '' : $out, 'text' => $text];
    }

    /**
     * Nội dung lời nhắc đã LƯU → HTML an toàn để hiển thị. Ghi chú cũ dạng chữ thuần (không có thẻ,
     * không có thực thể &...;) được escape + đổi xuống dòng thành <br>; còn lại coi là HTML và lọc lại.
     */
    function fmi_note_to_html($stored)
    {
        $stored = trim((string) $stored);
        if ($stored === '') return '';
        if (!preg_match('/<|&(?:[a-z]+|#\d+|#x[0-9a-f]+);/i', $stored)) {
            return nl2br(htmlspecialchars($stored, ENT_QUOTES, 'UTF-8'), false);
        }
        $r = fmi_note_sanitize_html($stored);
        return $r['html'];
    }

    /** Lời nhắc đã lưu của sản phẩm — HTML an toàn ('' nếu chưa có). */
    function fmi_get_note($product_id)
    {
        fmi_ensure_tables();
        $pid = (int) $product_id;
        if ($pid <= 0) return '';
        $row = db_fetch_row("SELECT note FROM product_formula_notes WHERE product_id = $pid LIMIT 1");
        return $row ? fmi_note_to_html($row['note']) : '';
    }

    /**
     * Lưu lời nhắc (HTML từ trình soạn). Lọc HTML; rỗng sau khi lọc = XOÁ lời nhắc.
     * Trả ['ok'=>true,'note'=>html đã lưu ('' nếu đã xoá)] hoặc ['ok'=>false,'message'=>...].
     * Quá fmi_note_max() ký tự hiển thị → từ chối (không tự cắt, tránh làm hỏng định dạng).
     */
    function fmi_set_note($product_id, $note, $by = 0)
    {
        fmi_ensure_tables();
        $pid = (int) $product_id;
        if ($pid <= 0) return ['ok' => false, 'message' => 'Thiếu sản phẩm.'];

        $clean = fmi_note_sanitize_html($note);
        if ($clean['html'] === '') {
            db_delete('product_formula_notes', "product_id = $pid");
            return ['ok' => true, 'note' => ''];
        }
        $len = function_exists('mb_strlen') ? mb_strlen($clean['text'], 'UTF-8') : strlen($clean['text']);
        if ($len > fmi_note_max()) {
            return ['ok' => false, 'message' => 'Lời nhắc dài ' . $len . ' ký tự, tối đa ' . fmi_note_max() . '.'];
        }
        if (strlen($clean['html']) > fmi_note_html_max()) {
            return ['ok' => false, 'message' => 'Định dạng lời nhắc quá phức tạp, hãy rút gọn.'];
        }
        db_query("INSERT INTO product_formula_notes (product_id, note, updated_by, updated_at)
                  VALUES ($pid, '" . escape_string($clean['html']) . "', " . (int) $by . ", '" . fmi_now() . "')
                  ON DUPLICATE KEY UPDATE note = VALUES(note), updated_by = VALUES(updated_by), updated_at = VALUES(updated_at)");
        return ['ok' => true, 'note' => $clean['html']];
    }

    /* ============================================================
     *  MÔ TẢ CÔNG THỨC (chữ thuần, gắn với HÌNH công thức)
     * ============================================================ */

    /** Độ dài tối đa của mô tả (ký tự). */
    function fmi_desc_max() { return 300; }

    /**
     * Sản lượng dùng làm khoá mô tả cho (sản phẩm, sản lượng thẻ): nếu đã có hình phủ sản lượng này
     * (đúng hoặc lân cận ±3%) → khoá theo sản lượng GỐC của hình đó, để mô tả đi theo hình;
     * chưa có hình → khoá theo chính sản lượng thẻ (khi nạp hình đúng sản lượng này sẽ khớp).
     */
    function fmi_desc_key($product_id, $quantity)
    {
        $img = fmi_find_image($product_id, $quantity);
        return $img ? (int) $img['source_quantity'] : (int) round((float) $quantity);
    }

    /** Mô tả của hình công thức đang hiển thị cho (sản phẩm, sản lượng thẻ) — chữ thuần, '' nếu chưa có. */
    function fmi_get_desc($product_id, $quantity)
    {
        fmi_ensure_tables();
        $pid = (int) $product_id;
        if ($pid <= 0) return '';
        $q = fmi_desc_key($pid, $quantity);
        $row = db_fetch_row("SELECT description FROM product_formula_descs WHERE product_id = $pid AND quantity = $q LIMIT 1");
        return $row ? trim((string) $row['description']) : '';
    }

    /**
     * Lưu mô tả. Rỗng (sau trim) = XOÁ. Giữ xuống dòng. Quá fmi_desc_max() ký tự → từ chối.
     * Trả ['ok'=>true,'description'=>chuỗi đã lưu ('' nếu xoá),'quantity'=>khoá] hoặc ['ok'=>false,'message'=>...].
     */
    function fmi_set_desc($product_id, $quantity, $text, $by = 0)
    {
        fmi_ensure_tables();
        $pid = (int) $product_id;
        if ($pid <= 0) return ['ok' => false, 'message' => 'Thiếu sản phẩm.'];
        $q = fmi_desc_key($pid, $quantity);
        if ($q <= 0) return ['ok' => false, 'message' => 'Chưa có sản lượng để gắn mô tả.'];

        $text = str_replace(["\r\n", "\r"], "\n", (string) $text);
        // Byte UTF-8 sai (client lỗi/độc) → thay bằng '?' để json_encode phản hồi không bị hỏng.
        if (function_exists('mb_convert_encoding')) $text = mb_convert_encoding($text, 'UTF-8', 'UTF-8');
        $text = trim($text);
        if ($text === '') {
            db_delete('product_formula_descs', "product_id = $pid AND quantity = $q");
            return ['ok' => true, 'description' => '', 'quantity' => $q];
        }
        $len = function_exists('mb_strlen') ? mb_strlen($text, 'UTF-8') : strlen($text);
        if ($len > fmi_desc_max()) {
            return ['ok' => false, 'message' => 'Mô tả dài ' . $len . ' ký tự, tối đa ' . fmi_desc_max() . '.'];
        }
        db_query("INSERT INTO product_formula_descs (product_id, quantity, description, updated_by, updated_at)
                  VALUES ($pid, $q, '" . escape_string($text) . "', " . (int) $by . ", '" . fmi_now() . "')
                  ON DUPLICATE KEY UPDATE description = VALUES(description), updated_by = VALUES(updated_by), updated_at = VALUES(updated_at)");
        return ['ok' => true, 'description' => $text, 'quantity' => $q];
    }

    /* ============================================================
     *  NHẮC ADMIN DUYỆT CÔNG THỨC SAU KHI "XUẤT KH"
     * ============================================================ */

    /**
     * Các sản phẩm trong kế hoạch $plans (mảng dòng production_plans: product_id, quantity, product_name)
     * ĐÃ CÓ hình công thức (đúng sản lượng hoặc lân cận ±3%) nhưng CHƯA được duyệt cho $plan_date
     * → admin cần vào xem để duyệt cho NV. Mỗi sản phẩm xuất hiện 1 lần.
     * Trả [['product_id'=>int,'name'=>string,'quantity'=>int], ...].
     */
    function fmi_pending_review($plan_date, $plans)
    {
        $approved = array_flip(fmi_approved_pids($plan_date));
        $out = []; $seen = [];
        foreach ((array) $plans as $p) {
            $pid = (int) ($p['product_id'] ?? 0);
            if ($pid <= 0 || isset($seen[$pid]) || isset($approved[$pid])) continue;
            $seen[$pid] = true;
            $qty = (int) ($p['quantity'] ?? 0);
            if (!fmi_find_image($pid, $qty)) continue;
            $out[] = ['product_id' => $pid, 'name' => (string) ($p['product_name'] ?? ''), 'quantity' => $qty];
        }
        return $out;
    }
}
