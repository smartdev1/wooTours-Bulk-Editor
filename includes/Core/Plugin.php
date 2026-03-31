<?php

namespace WBE\Core;

use WBE\Admin\Menu;
use WBE\Admin\Assets;
// use WBE\Admin\Debug;
use WBE\Ajax\BulkUpdate;

class Plugin
{
    public static function run(): void
    {
        if (!Dependencies::check()) {
            return;
        }

        self::init();
    }

    private static function init(): void
    {
        if (is_admin()) {
            new Menu();
            new Assets();
            new BulkUpdate();
            // new Debug();
        }
    }
}
