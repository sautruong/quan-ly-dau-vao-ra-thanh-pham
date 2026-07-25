<?php
/* =====================================================================
 *  LINK ĐIỀU HƯỚNG TRANG — URL GỌN, DÙNG CHUNG CHO MỌI TÀI KHOẢN.
 *  ---------------------------------------------------------------------
 *  Dạng link:  /{mod}/{action}[/{id}][?tham_số_phụ]
 *      vd  /project_management/detail/15
 *          (thay cho ?mod=project_management&controllers=project&action=detail&id=15)
 *
 *  - KHÔNG còn phân biệt admin / user thường (trước đây admin thấy link
 *    query-string đầy đủ, user thường thấy link token 20 số kiểu facebook).
 *    Nay cả 2 dùng chung đúng 1 dạng link gọn ở trên.
 *  - `controllers` KHÔNG xuất hiện trên URL: mỗi module chỉ có duy nhất 1
 *    controller nên suy ra được từ tên module (nav_controller_for_module()).
 *  - Tham số `id` (hay dùng nhất) nằm ở segment cuối cho gọn; các tham số
 *    phụ khác vẫn nối dạng query string bình thường.
 *  - TƯƠNG THÍCH NGƯỢC (không phá code cũ):
 *      + Link/AJAX dạng ?mod=..&controllers=..&action=.. vẫn chạy y như cũ
 *        (nav_parse_path() thoát ngay khi đã có $_GET['mod']).
 *      + Link token cũ ?/{mod}/{token} (đã lỡ gửi/bookmark) vẫn giải mã được
 *        qua bảng nav_tokens — giữ lại nav_token_lookup() cho mục đích này.
 *  - CHỈ áp dụng cho link điều hướng trang (href/form action/redirect).
 *    Các lời gọi AJAX (fetch) giữ nguyên query string thường như cũ.
 * ===================================================================== */

if (!function_exists('nav_url')) {

    /**
     * Tên controller của 1 module. Mỗi module trong hệ thống chỉ có đúng 1 file
     * *Controller.php nên suy ra được, nhờ vậy URL không cần chứa 'controllers'.
     * Ưu tiên file trùng tên module (đa số), nếu không có thì lấy file duy nhất
     * trong thư mục controllers (vd module project_management -> projectController).
     */
    function nav_controller_for_module($mod)
    {
        static $cache = array();
        $mod = (string) $mod;
        if ($mod === '') return '';
        if (isset($cache[$mod])) return $cache[$mod];

        $dir = MODULESPATH . DIRECTORY_SEPARATOR . $mod . DIRECTORY_SEPARATOR . 'controllers';
        $found = $mod; // fallback: trùng tên module

        if (is_file($dir . DIRECTORY_SEPARATOR . $mod . 'Controller.php')) {
            $found = $mod;
        } elseif (is_dir($dir)) {
            foreach ((array) scandir($dir) as $f) {
                if (substr($f, -14) === 'Controller.php') {
                    $found = substr($f, 0, -14);
                    break;
                }
            }
        }
        $cache[$mod] = $found;
        return $found;
    }

    /**
     * Thư mục gốc app tính từ document root ('' nếu app nằm ngay root như
     * safeking.cloud). Dùng để dựng link tuyệt đối đúng cả khi app đặt trong
     * thư mục con (vd localhost/nvsxvat.vn/).
     */
    function nav_base_path()
    {
        static $base = null;
        if ($base !== null) return $base;

        $script = isset($_SERVER['SCRIPT_NAME']) ? $_SERVER['SCRIPT_NAME'] : '';
        $dir = rtrim(str_replace('\\', '/', dirname($script)), '/');
        $base = ($dir === '' || $dir === '.') ? '' : $dir;
        return $base;
    }

    /**
     * Sinh link điều hướng trang dạng /{mod}/{action}[/{id}][?...].
     * $params: tham số phụ (vd ['id' => 15]); 'id' được đưa lên segment cuối,
     * các key còn lại nối thành query string.
     * $controllers giữ trong chữ ký để không phải sửa 31 lời gọi sẵn có —
     * chỉ dùng khi module có controller KHÁC với suy luận mặc định.
     */
    function nav_url($mod, $controllers, $action, $params = array())
    {
        $params = is_array($params) ? $params : array();

        $url = nav_base_path() . '/' . rawurlencode($mod) . '/' . rawurlencode($action);

        if (isset($params['id']) && $params['id'] !== '' && $params['id'] !== null) {
            $url .= '/' . rawurlencode($params['id']);
            unset($params['id']);
        }

        // Controller không suy ra được từ module -> giữ lại trong query string
        // để router vẫn gọi đúng file (hiện tại không module nào rơi vào nhánh này).
        if ($controllers !== '' && $controllers !== null && $controllers !== nav_controller_for_module($mod)) {
            $params['controllers'] = $controllers;
        }

        if (!empty($params)) $url .= '?' . http_build_query($params);
        return $url;
    }

    /**
     * Đọc URL gọn /{mod}/{action}[/{id}] từ REQUEST_URI, đổ vào $_GET để
     * get_module()/get_controller()/get_action() hoạt động y như cũ.
     * No-op nếu request đã có ?mod=... (link/AJAX kiểu cũ) hoặc không có path.
     * Gọi trong core/appload.php SAU db_connect().
     */
    function nav_parse_path()
    {
        // Link kiểu cũ ?mod=..&controllers=..&action=.. -> giữ nguyên, không đụng vào.
        if (isset($_GET['mod']) && $_GET['mod'] !== '') return;

        // Link token cũ ?/{mod}/{token} -> vẫn giải mã được (bookmark/link đã gửi đi).
        $qs = isset($_SERVER['QUERY_STRING']) ? $_SERVER['QUERY_STRING'] : '';
        if ($qs !== '' && $qs[0] === '/') { nav_parse_legacy_token(); return; }

        $uri = isset($_SERVER['REQUEST_URI']) ? $_SERVER['REQUEST_URI'] : '';
        if ($uri === '') return;

        $path = parse_url($uri, PHP_URL_PATH);
        if (!is_string($path) || $path === '') return;

        // Bỏ thư mục gốc app (khi app nằm trong thư mục con) rồi tách segment.
        $base = nav_base_path();
        if ($base !== '' && strpos($path, $base) === 0) {
            $path = substr($path, strlen($base));
        }
        $path = trim($path, '/');
        if ($path === '') return; // trang chủ -> dùng default_module trong config

        $seg = array_values(array_filter(explode('/', $path), 'strlen'));
        if (empty($seg)) return;
        if (substr($seg[0], -4) === '.php') return; // gọi thẳng index.php -> không phải URL gọn

        $mod = rawurldecode($seg[0]);
        // Module không tồn tại -> để router báo lỗi như cũ, không đoán bừa.
        if (!is_dir(MODULESPATH . DIRECTORY_SEPARATOR . $mod)) return;

        $_GET['mod'] = $mod;
        if (isset($seg[1])) $_GET['action'] = rawurldecode($seg[1]);
        if (isset($seg[2]) && !isset($_GET['id'])) $_GET['id'] = rawurldecode($seg[2]);

        // controllers có thể đã nằm trong query string (trường hợp không suy ra được).
        if (!isset($_GET['controllers']) || $_GET['controllers'] === '') {
            $_GET['controllers'] = nav_controller_for_module($mod);
        }
    }

    /**
     * Đổi 1 query string kiểu cũ (?mod=..&controllers=..&action=..&id=..) sang
     * URL gọn. Trả về null nếu không đổi được (thiếu mod/action) -> giữ nguyên.
     */
    function nav_pretty_from_query($qs)
    {
        $qs = ltrim((string) $qs, '?');
        if ($qs === '') return null;

        // Chuỗi trong HTML thường đã escape & -> &amp;
        $qs = str_replace('&amp;', '&', $qs);
        parse_str($qs, $p);
        if (empty($p['mod'])) return null;

        // Thiếu action (vd '?mod=home&controllers=index') -> dùng default_action như router.
        if (empty($p['action'])) {
            global $config;
            $p['action'] = isset($config['default_action']) ? $config['default_action'] : 'index';
        }

        $mod = (string) $p['mod'];
        $action = (string) $p['action'];
        $controllers = isset($p['controllers']) ? (string) $p['controllers'] : '';
        unset($p['mod'], $p['action'], $p['controllers']);

        return nav_url($mod, $controllers, $action, $p);
    }

    /**
     * Lọc HTML đầu ra: đổi link điều hướng trong href="..."/action="..." sang
     * URL gọn. CỐ Ý chỉ đụng 2 thuộc tính này — chuỗi endpoint AJAX trong JS
     * (vd CFG.baseUrl = '?mod=..&action=') KHÔNG khớp mẫu nên giữ nguyên query
     * string như cũ, đúng thiết kế (fetch vẫn chạy y hệt trước).
     */
    function nav_prettify_html($html)
    {
        $html = (string) $html;

        $html = preg_replace_callback(
            '/\b(href|action)=(["\'])\?((?:mod|amp;mod)=[^"\']*)\2/i',
            function ($m) {
                $pretty = nav_pretty_from_query($m[3]);
                if ($pretty === null) return $m[0];
                return $m[1] . '=' . $m[2] . htmlspecialchars($pretty, ENT_QUOTES, 'UTF-8') . $m[2];
            },
            $html
        );

        return nav_inject_base_tag($html);
    }

    /**
     * Chèn <base href="{gốc app}/"> vào <head>.
     * BẮT BUỘC với URL gọn: toàn bộ view đang tham chiếu tài nguyên bằng đường dẫn
     * TƯƠNG ĐỐI ("public/css/..", "public/js/..") — trước đây URL luôn là gốc "/" (kèm
     * query string) nên chúng phân giải thành /public/... Khi URL thành /home/index thì
     * chúng lại phân giải thành /home/public/... -> 302/HTML -> vỡ CSS/JS.
     * Thẻ <base> khôi phục đúng hành vi cũ cho MỌI đường dẫn tương đối, kể cả đường dẫn
     * do JS tự dựng lúc chạy (không thể xử lý bằng cách thay chuỗi trong HTML).
     */
    function nav_inject_base_tag($html)
    {
        if (stripos($html, '<head') === false) return $html;   // không phải trang HTML đầy đủ
        if (preg_match('/<base\b/i', $html)) return $html;      // đã có <base> sẵn

        $base = nav_base_path() . '/';
        $tag = '<base href="' . htmlspecialchars($base, ENT_QUOTES, 'UTF-8') . '">';

        return preg_replace('/(<head\b[^>]*>)/i', '$1' . $tag, $html, 1);
    }

    /**
     * Bật lọc output cho response HTML. Gọi ở đầu request (core/appload.php).
     * Bỏ qua response không phải HTML (JSON/ảnh/PDF...) bằng cách kiểm tra
     * Content-Type lúc flush -> AJAX trả JSON không bị đụng tới.
     */
    function nav_start_output_filter()
    {
        if (PHP_SAPI === 'cli') return;
        ob_start(function ($buffer) {
            $isHtml = true;
            foreach (headers_list() as $h) {
                // Redirect: đổi luôn sang URL gọn. Nhờ output buffering, header chưa
                // được gửi đi ở thời điểm callback này chạy -> vẫn thay thế được,
                // khỏi phải sửa 47 chỗ header('Location: ?mod=..') rải rác.
                if (stripos($h, 'location:') === 0) {
                    $loc = trim(substr($h, strlen('location:')));
                    if ($loc !== '' && $loc[0] === '?') {
                        $pretty = nav_pretty_from_query($loc);
                        if ($pretty !== null && !headers_sent()) {
                            header('Location: ' . $pretty, true);
                        }
                    }
                }
                if (stripos($h, 'content-type:') === 0 && stripos($h, 'text/html') === false) {
                    $isHtml = false; // JSON, ảnh, file tải về... -> không lọc nội dung
                }
            }
            return $isHtml ? nav_prettify_html($buffer) : $buffer;
        });
    }

    /**
     * Redirect tới URL gọn. Thay cho header('Location: ?mod=..&action=..').
     * Giữ nguyên hành vi nếu chuỗi truyền vào không phải link nội bộ kiểu cũ.
     */
    function nav_redirect($url)
    {
        $u = (string) $url;
        if ($u !== '' && $u[0] === '?') {
            $pretty = nav_pretty_from_query($u);
            if ($pretty !== null) $u = $pretty;
        }
        header('Location: ' . $u);
        exit;
    }

    /* -----------------------------------------------------------------
     *  TƯƠNG THÍCH NGƯỢC — link token 20 số kiểu cũ (?/{mod}/{token}).
     *  Không sinh token mới nữa; chỉ đọc lại bảng nav_tokens cho các link
     *  đã lỡ phát hành trước đây.
     * ----------------------------------------------------------------- */

    /** Tra token -> mảng payload (controllers/action/params). Không có -> mảng rỗng. */
    function nav_token_lookup($token)
    {
        if (db_num_rows("SHOW TABLES LIKE 'nav_tokens'") <= 0) return array();
        $token = preg_replace('/[^0-9]/', '', (string) $token);
        if ($token === '') return array();

        $tokenEsc = escape_string($token);
        $row = db_fetch_row("SELECT payload FROM nav_tokens WHERE token = '{$tokenEsc}' LIMIT 1");
        if (!$row) return array();

        $arr = json_decode($row['payload'], true);
        return is_array($arr) ? $arr : array();
    }

    function nav_parse_legacy_token()
    {
        $qs = isset($_SERVER['QUERY_STRING']) ? $_SERVER['QUERY_STRING'] : '';
        if ($qs === '' || $qs[0] !== '/') return;

        $parts = explode('/', substr($qs, 1), 2);
        $mod = isset($parts[0]) ? rawurldecode($parts[0]) : '';
        $token = isset($parts[1]) ? $parts[1] : '';

        $payload = nav_token_lookup($token);
        $_GET['mod'] = $mod;
        $_GET['controllers'] = isset($payload['controllers']) ? $payload['controllers'] : '';
        $_GET['action'] = isset($payload['action']) ? $payload['action'] : '';
        unset($payload['controllers'], $payload['action']);
        foreach ($payload as $key => $value) {
            $_GET[$key] = $value;
        }
    }
}
