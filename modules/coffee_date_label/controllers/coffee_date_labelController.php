<?php
// Hint cho IDE (PHP Intelephense) resolve load_* và cdl_*.
if (!function_exists('__cdl_intelephense_hint_stub')) {
    function __cdl_intelephense_hint_stub()
    {
        require_once __DIR__ . '/../../../core/base.php';
        require_once __DIR__ . '/../models/coffee_date_labelModel.php';
    }
}

function construct()
{
    load_model('coffee_date_label');
}

/* ============================================================
 *  Page
 * ============================================================ */

function coffee_date_labelAction()
{
    cdl_ensure_view_registered();
    load_view('coffee_date_label', [
        'today' => date('Y-m-d'),
    ]);
}

/* ============================================================
 *  AJAX — Check Database (dùng chung)
 * ============================================================ */

function check_databaseAction()
{
    require_once __DIR__ . '/../../../libraries/check_database.php';
    cdb_handle_ajax();
}
