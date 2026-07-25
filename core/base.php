<?php

defined('APPPATH') OR exit('Không được quyền truy cập phần này');


/**
 * URL asset kèm ?v=filemtime để tránh trình duyệt giữ bản CSS/JS cũ sau khi sửa —
 * dùng cho các file dùng chung (app_shell.css/js...) được link tĩnh ở rất nhiều view,
 * nơi việc tự tay đổi version thủ công mỗi lần sửa là không khả thi.
 * @param string $rel  đường dẫn tương đối từ gốc app (vd 'public/css/shared/app_shell.css')
 */
function asset_ver($rel) {
    $abs = APPPATH . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $rel);
    $v = is_file($abs) ? filemtime($abs) : time();
    return $rel . '?v=' . $v;
}

// get Module name

function get_module() {
    global $config;
    $module = isset($_GET['mod']) ? $_GET['mod'] : $config['default_module'];
    return $module;
}

// get Controller name
function get_controller() {
    global $config;
    $controller = isset($_GET['controllers']) ? $_GET['controllers'] : $config['default_controller'];
    return $controller;
}

//get Action name
function get_action() {
    global $config;
    $action = isset($_GET['action']) ? $_GET['action'] : $config['default_action'];
    return $action;
}

/*
 * -------------------------------
 * Load
 * ------------------------------------------------------------------------------------
 * Load các file từ các phân vùng vào hệ thống tham gia xử lý
 * Ví dụ: load('lib','database');
 * ------------------------------------------------------------------------------------
 * GIẢI THÍCH
 * ------------------------------------------------------------------------------------
 * Đầu vào
 * - $type: Loại phân vùng hệ thống: lib, helper...
 * - $name: Tên chức năng được load: database, string...
 * ------------------------------------------------------------------------------------
 */

//function run_module($url, $data_echo = true) {
////    global $config;
//    include  base_url().$url;
////    if (empty($url))
////        return FALSE;
////
////    if ($data_echo) {
////        echo get_data($url);
////    } else {
////        return get_data($url);
////    }
//}

function load($type, $name) {
    if ($type == 'libraries')
        $path = LIBPATH . DIRECTORY_SEPARATOR . "{$name}.php";
    if ($type == 'helper')
        $path = HELPERPATH . DIRECTORY_SEPARATOR . "{$name}.php";
    if (file_exists($path)) {
        require "$path";
    } else {
        echo "{$type}:{$name} không tồn tại";
    }
}

/*
 * -----------------------------
 * callFunction
 * -----------------------------
 * Gọi đến hàm theo tham số biến
 */

function call_function($list_function = array()) {
    if (is_array($list_function)) {
        foreach ($list_function as $f) {
            if (function_exists($f())) {
                $f();
            }
        }
    }
}

function load_view($name, $data_send = array()) {
    global $data;
    $data = $data_send;
    $path = MODULESPATH . DIRECTORY_SEPARATOR . get_module() . DIRECTORY_SEPARATOR . 'views' . DIRECTORY_SEPARATOR . $name . '.php';
    if (file_exists($path)) {
        if (is_array($data)) {
            foreach ($data as $key_data => $v_data) {
                $$key_data = $v_data;
            }
        }
        require $path;
    } else {
        echo "Không tìm thấy {$path}";
    }
}

function load_model($name) {
    $path = MODULESPATH . DIRECTORY_SEPARATOR . get_module() . DIRECTORY_SEPARATOR . 'models' . DIRECTORY_SEPARATOR . $name . 'Model.php';
    if (file_exists($path)) {
        require $path;
    } else {
        echo "Không tìm thấy {$path}";
    }
}

function get_header($name = '') {
    global $data;
    if (empty($name)) {
        $name = 'header';
    } else {
        $name = "header-{$name}";
    }
    $path = LAYOUTPATH . DIRECTORY_SEPARATOR . $name . '.php';
    if (file_exists($path)) {
        if (is_array($data)) {
            foreach ($data as $key => $a) {
                $$key = $a;
            }
        }
        require $path;
    } else {
        echo "Không tìm thấy {$path}";
    }
}

function get_footer($name = '') {
    global $data;
    if (empty($name)) {
        $name = 'footer';
    } else {
        $name = "footer-{$name}";
    }
    $path = LAYOUTPATH . DIRECTORY_SEPARATOR . $name . '.php';
    if (file_exists($path)) {
        if (is_array($data)) {
            foreach ($data as $key => $a) {
                $$key = $a;
            }
        }
        require $path;
    } else {
        echo "Không tìm thấy {$path}";
    }
}

function get_sidebar($name = '') {
    global $data;
    if (empty($name)) {
        $name = 'sidebar';
    } else {
        $name = "sidebar-{$name}";
    }
    $path = LAYOUTPATH . DIRECTORY_SEPARATOR . $name . '.php';
    if (file_exists($path)) {
        if (is_array($data)) {
            foreach ($data as $key => $a) {
                $$key = $a;
            }
        }
        require $path;
    } else {
        echo "Không tìm thấy {$path}";
    }
}

function get_template_part($name) {
    global $data;
    if (empty($name))
        return FALSE;
    $path = LAYOUTPATH . DIRECTORY_SEPARATOR . "template-{$name}.php";
    if (file_exists($path)) {
        foreach ($data as $key => $a) {
            $$key = $a;
        }
        require $path;
    } else {
        echo "Không tìm thấy {$path}";
    }
}

?>