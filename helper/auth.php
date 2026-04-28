<?php
function check_auth()
{
    /* ===============================
 * 2. CHECK COKIE
 * # Nếu có cookie thì gửi qua session
 * =============================== */
    if (isset($_COOKIE['is_login'])) {
        $_SESSION['is_login'] = $_COOKIE['is_login'];
        $_SESSION['user_login'] = $_COOKIE['user_login'];
    }
    /* ===============================
 * 2. CHECK LOGIN
 * # Check_login: nếu chưa cho session thì đẩy qua login
 * =============================== */
    if (empty($_SESSION['is_login'])) {
        header('Location:?mod=auth&controller=index&action=login');
        exit();
    }
}

function check_login($username, $password, $list_users)
{
    // show_data($list_users);
    foreach ($list_users as $user) {
        if ($username == $user['username'] && $password == $user['password']) {
            return $user['fullname'];
        }
        return FALSE;
    }
}
