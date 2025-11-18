<?php
if (!defined('RPIDB_TZ_INITIALIZED')) {
    define('RPIDB_TZ_INITIALIZED', true);

    $timezone = '';

    if (is_readable('/etc/timezone')) {
        $timezone = trim(@file_get_contents('/etc/timezone'));
    }

    if (!$timezone) {
        $timezone = trim(@shell_exec("timedatectl show -p Timezone --value 2>/dev/null"));
    }

    if (!$timezone) {
        $timezone = trim(@shell_exec("date +'%Z'"));
    }

    if ($timezone) {
        if (!@date_default_timezone_set($timezone)) {
            date_default_timezone_set('UTC');
        }
    } else {
        date_default_timezone_set('UTC');
    }
}

