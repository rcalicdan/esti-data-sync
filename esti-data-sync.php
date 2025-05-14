<?php

/**
 * Plugin Name: Esti Data Sync
 * Plugin URI: https://esti.toniemoje.pl/
 * Description: Syncs property data from a JSON file to the 'property' custom post type.
 * Version: 1.0.0
 * Author: Reymart A. Calicdan
 * Author URI: https://esti.toniemoje.pl/
 * License: GPL-2.0+
 * License URI: http://www.gnu.org/licenses/gpl-2.0.txt
 * Text Domain: esti-data-sync
 */

if (! defined('ABSPATH')) {
    exit; // Exit if accessed directly.
}

define('ESTI_SYNC_PLUGIN_PATH', plugin_dir_path(__FILE__));
define('ESTI_SYNC_PLUGIN_URL', plugin_dir_url(__FILE__));
define('ESTI_SYNC_DATA_FILE', ESTI_SYNC_PLUGIN_PATH . 'data/sample-data.json');

spl_autoload_register(function ($class_name) {
    if (strpos($class_name, 'Esti_') === 0) {
        $file = ESTI_SYNC_PLUGIN_PATH . 'includes/class-' . strtolower(str_replace('_', '-', $class_name)) . '.php';
        if (file_exists($file)) {
            require_once $file;
        }
    }

    $enum_file = ESTI_SYNC_PLUGIN_PATH . 'enums/' . strtolower(str_replace('_', '-', $class_name)) . '.php';
    if (file_exists($enum_file)) {
        require_once $enum_file;
    }
});

if (class_exists('Esti_Main')) {
    $esti_main = new Esti_Main();
    $esti_main->init();
}
