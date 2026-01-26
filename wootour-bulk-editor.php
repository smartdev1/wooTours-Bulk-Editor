<?php
/**
 * Plugin Name: Wootour Edition de masses
 * Description: Bulk edit availability for Wootour products without overwriting existing data
 * Version:     1.0.0
 * Author:      Intinct Vertical
 * License:     GPL v2 or later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: wootour-bulk-editor
 * Domain Path: /languages
 * Requires at least: 5.6
 * Requires PHP: 7.4
 * WC requires at least: 5.0
 * 
 * @package WootourBulkEditor
 */

// Exit if accessed directly
defined('ABSPATH') || exit;

/**
 * Main plugin bootstrap function
 * 
 * @return WootourBulkEditor\Core\Plugin|null
 */
function wootour_bulk_editor(): ?WootourBulkEditor\Core\Plugin
{
    static $plugin = null;
    
    if (null === $plugin) {
        try {
            // Manually load core files needed for bootstrap
            $base_dir = __DIR__;
            
            // Load Singleton trait first (if Plugin uses it)
            $singleton_file = $base_dir . '/includes/Core/Traits/Singleton.php';
            if (file_exists($singleton_file)) {
                require_once $singleton_file;
            }
            
            // Load Autoloader class
            $autoloader_file = $base_dir . '/includes/Core/Autoloader.php';
            if (!file_exists($autoloader_file)) {
                throw new \RuntimeException('Autoloader class not found at: ' . $autoloader_file);
            }
            require_once $autoloader_file;
            
            // Load Constants class (needed by Plugin)
            $constants_file = $base_dir . '/includes/Core/Constants.php';
            if (!file_exists($constants_file)) {
                throw new \RuntimeException('Constants class not found at: ' . $constants_file);
            }
            require_once $constants_file;
            
            // Load Plugin class
            $plugin_file = $base_dir . '/includes/Core/Plugin.php';
            if (!file_exists($plugin_file)) {
                throw new \RuntimeException('Plugin class not found at: ' . $plugin_file);
            }
            require_once $plugin_file;
            
            // Now we can safely instantiate and initialize
            $plugin = WootourBulkEditor\Core\Plugin::getInstance();
            $plugin->init();
            
        } catch (\Throwable $e) {
            // Log error but don't crash the site
            error_log(sprintf(
                'Wootour Bulk Editor failed to initialize: %s in %s:%s',
                $e->getMessage(),
                $e->getFile(),
                $e->getLine()
            ));
            
            // Show admin notice if in admin area
            if (is_admin()) {
                add_action('admin_notices', function () use ($e) {
                    ?>
                    <div class="notice notice-error">
                        <p>
                            <strong>Wootour Bulk Editor Error:</strong> 
                            <?php echo esc_html($e->getMessage()); ?>
                        </p>
                    </div>
                    <?php
                });
            }
            
            $plugin = null;
        }
    }
    
    return $plugin;
}

// Initialize plugin on plugins_loaded hook
add_action('plugins_loaded', 'wootour_bulk_editor', 10);

/**
 * Quick access function to the plugin instance
 * 
 * @return WootourBulkEditor\Core\Plugin|null
 */
function wbe(): ?WootourBulkEditor\Core\Plugin
{
    return wootour_bulk_editor();
}