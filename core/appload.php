<?php
defined('APPPATH') OR exit('Không được quyền truy cập phần này');

// Include file config/database
require_once CONFIGPATH . DIRECTORY_SEPARATOR . 'database.php';

// Include file config/config
require_once CONFIGPATH . DIRECTORY_SEPARATOR . 'config.php';
require_once CONFIGPATH . DIRECTORY_SEPARATOR . 'table_hr_config.php';

// Include file config/email
require_once CONFIGPATH . DIRECTORY_SEPARATOR . 'email.php';

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

require COREPATH . DIRECTORY_SEPARATOR . 'router.php';
















