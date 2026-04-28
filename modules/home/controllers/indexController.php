<?php
# KHAI BÁO SỬ DỤNG SESSION
session_start();
ob_start();
// Hàm construct thả mặc định ở mọi nơi
function construct() {}
// Kiểm tra thông tin đăng nhập, chưa đẩy qua trang login để bắt login trước
// Quyết định Gọi view home
function indexAction()
{
    check_auth();
    load_view('index');
}
function expermoreAction(){
    load_view('expermore');
}