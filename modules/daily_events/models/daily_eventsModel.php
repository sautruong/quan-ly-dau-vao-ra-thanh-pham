<?php
/**
 * Sự kiện hằng ngày (daily_events) — Model.
 * Logic CRUD cũ (3 loại, không còn hiển thị) nằm ở libraries/daily_events.php
 * (hàm de_*). Logic "Thảo luận" (Tường kiểu Facebook) nằm ở
 * libraries/daily_events_wall.php (hàm dew_*). Model chỉ là lớp mỏng gọi
 * lib + xử lý quyền (chủ sở hữu / admin).
 */

require_once __DIR__ . '/../../../libraries/daily_events.php';
require_once __DIR__ . '/../../../libraries/daily_events_wall.php';

function dem_ensure_tables()
{
    de_ensure_tables();
}

function dem_wall_ensure_tables()
{
    dew_ensure_tables();
}

function dem_ensure_view_registered()
{
    de_ensure_view_registered();
}

function dem_current_user()
{
    return de_current_user();
}

function dem_is_admin($user)
{
    return function_exists('permission_is_admin') ? permission_is_admin($user) : false;
}
