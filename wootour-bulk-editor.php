<?php
/**
 * Plugin Name: Wootour Bulk Editor
 * Description: Édition massive des disponibilités Wootour sans modifier le core.
 * Version: 1.0.0
 * Author: Instinct Vertical
 * Requires at least: 6.0
 * Requires PHP: 7.4
 */

if (!defined('ABSPATH')) {
    exit;
}

// Définition des constantes
define('WBE_PATH', plugin_dir_path(__FILE__));
define('WBE_URL', plugin_dir_url(__FILE__));
define('WBE_VERSION', '1.0.0');

// Autoload simple
spl_autoload_register(function ($class) {
    if (strpos($class, 'WBE\\') !== 0) {
        return;
    }

    $class = str_replace('WBE\\', '', $class);
    $class = str_replace('\\', DIRECTORY_SEPARATOR, $class);
    $path  = WBE_PATH . 'includes/' . $class . '.php';

    if (file_exists($path)) {
        require_once $path;
    }
});

add_action('wp_ajax_wbe_reset_product_completely', array('WBE\\Ajax\\ResetHandler', 'reset_product_completely'));
add_action('wp_ajax_wbe_get_all_category_products', array('WBE\\Ajax\\ResetHandler', 'get_all_category_products'));
add_action('wp_ajax_wbe_get_all_products', array('WBE\\Ajax\\ResetHandler', 'get_all_products'));

// Lancement du plugin
add_action('plugins_loaded', function () {
    WBE\Core\Plugin::run();
    new WBE\Ajax\CategoryHandler();
});
