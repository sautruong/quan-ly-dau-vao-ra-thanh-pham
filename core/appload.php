<?php
defined('APPPATH') OR exit('Không được quyền truy cập phần này');

// Include file config/database
require_once CONFIGPATH . DIRECTORY_SEPARATOR . 'database.php';

// Include file config/config
require_once CONFIGPATH . DIRECTORY_SEPARATOR . 'config.php';
require_once CONFIGPATH . DIRECTORY_SEPARATOR . 'table_hr_config.php';

// Include file config/email
require_once CONFIGPATH . DIRECTORY_SEPARATOR . 'email.php';

// Include file config/crypto (khóa mã hóa dữ liệu cá nhân hóa)
require_once CONFIGPATH . DIRECTORY_SEPARATOR . 'crypto.php';

// Include file config/autoload
require_once CONFIGPATH . DIRECTORY_SEPARATOR . 'autoload.php';

// Include core database
require_once LIBPATH . DIRECTORY_SEPARATOR . 'database.php';

// Include core base

require_once COREPATH . DIRECTORY_SEPARATOR . 'base.php';




if (is_array($autoload)) {
    foreach ($autoload as $type => $list_auto) {
        if (!empty($list_auto)) {
            foreach ($list_auto as $name) {
                load($type, $name);
            }
        }
    }
}



//
//connect db
db_connect($db);

// Giải mã URL gọn /{mod}/{action}[/{id}] -> $_GET['mod'/'controllers'/'action'/'id'].
// Cần chạy SAU db_connect() vì nhánh tương thích ngược (link token cũ) tra bảng nav_tokens.
if (function_exists('nav_parse_path')) {
    nav_parse_path();
}

// Lọc HTML đầu ra: đổi href/action dạng ?mod=..&controllers=..&action=.. sang URL gọn
// (chuỗi endpoint AJAX trong JS không khớp mẫu nên giữ nguyên).
if (function_exists('nav_start_output_filter')) {
    nav_start_output_filter();
}

require COREPATH . DIRECTORY_SEPARATOR . 'router.php';
















