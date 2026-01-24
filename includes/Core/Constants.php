<?php

namespace WootourBulkEditor\Core;

defined('ABSPATH') || exit;

final class Constants
{

    public const VERSION = '1.0.0';
    public const PREFIX = 'wbe_';
    public const TEXT_DOMAIN = 'wootour-bulk-editor';

    public static function plugin_dir(): string
    {
        return trailingslashit(WP_PLUGIN_DIR . '/wootour-bulk-editor');
    }

    public static function plugin_url(): string
    {
        return trailingslashit(plugins_url('', dirname(__FILE__, 2) . '/wootour-bulk-editor.php'));
    }

    public const BASENAME = 'wootour-bulk-editor/wootour-bulk-editor.php';

    /**
     * Meta keys used by Wootour (TO BE VERIFIED ON STAGING)
     * These will be validated and possibly adjusted during initialization
     */
    public const META_KEYS = [
        'availability'  => '_wootour_availability',
        'start_date'    => '_wootour_start_date',
        'end_date'      => '_wootour_end_date',
        'weekdays'      => '_wootour_weekdays',
        'exclusions'    => '_wootour_exclusions',
        'specific'      => '_wootour_specific_dates',
    ];

    /**
     * Performance et sécurité
     */

    // Produits maximum traités par lot
    public const BATCH_SIZE = 50;

    // Temps maximum d'exécution d'un lot en secondes
    public const TIMEOUT_SECONDS = 30;

    // Limite de mémoire pour le traitement par lot
    public const MEMORY_LIMIT = '256M';


    public const REQUIRED_CAPS = [
        'administrator' => 'manage_options',
        'shop_manager'  => 'manage_woocommerce',
    ];

    public const LOG_OPTION_PREFIX = 'wbe_log_';
    public const LOG_MAX_ENTRIES = 100;
    public const LOG_RETENTION_DAYS = 30;

    /** Actions AJAX */
    public const AJAX_ACTIONS = [
        'process_batch'  => 'wbe_process_batch',
        'get_products'   => 'wbe_get_products',
        'get_categories' => 'wbe_get_categories',
        'validate_dates' => 'wbe_validate_dates',
    ];

    /**
     * Nom des nonces pour la sécurité des requêtes
     */
    public const NONCE_ACTIONS = [
        'bulk_edit'   => 'wbe_bulk_edit_nonce',
        'ajax'        => 'wbe_ajax_nonce',
        'admin_page'  => 'wbe_admin_nonce',
    ];

    /**
     * Clés pour le cache temporaire (transients)
     */
    public const TRANSIENT_KEYS = [
        'categories'    => 'wbe_categories_cache',
        'products'      => 'wbe_products_cache_',
        'batch_status'  => 'wbe_batch_status_',
    ];

    /**
     * Noms des tables personnalisées dans la base de données
     * Réservé pour une utilisation future
     */
    public const DB_TABLES = [
        'logs' => 'wbe_logs',
    ];

    /**
     * Structure par défaut pour la disponibilité
     */
    public const DEFAULT_AVAILABILITY = [
        'start_date'    => '',
        'end_date'      => '',
        'weekdays'      => [],
        'exclusions'    => [],
        'specific'      => [],
    ];

    public const DATE_FORMATS = [
        'mysql'     => 'Y-m-d',
        'display'   => 'd/m/Y',
        'js'        => 'dd/mm/yy',
    ];


    public const ERROR_CODES = [
        'invalid_product'   => 1001,
        'invalid_date'      => 1002,
        'permission_denied' => 1003,
        'batch_failed'      => 1004,
        'wootour_error'     => 1005,
    ];


    public static function get_verified_meta_key(): string
    {
        $candidate_keys = [
            '_wootour_availability',
            '_wootour_availabilities',
            '_tour_availability',
        ];

        foreach ($candidate_keys as $key) {
            global $wpdb;
            $exists = $wpdb->get_var($wpdb->prepare(
                "SELECT COUNT(*) FROM {$wpdb->postmeta} WHERE meta_key = %s LIMIT 1",
                $key
            ));

            if ($exists) {
                set_transient('wbe_detected_meta_key', $key, DAY_IN_SECONDS);
                return $key;
            }
        }

        return self::META_KEYS['availability'];
    }


    public static function to_array(): array
    {
        return [
            'version'           => self::VERSION,
            'batch_size'        => self::BATCH_SIZE,
            'timeout'           => self::TIMEOUT_SECONDS,
            'memory_limit'      => self::MEMORY_LIMIT,
            'meta_keys'         => self::META_KEYS,
            'plugin_dir'        => self::plugin_dir(),
            'plugin_url'        => self::plugin_url(),
        ];
    }

    /**
     * Check if WooTours is active
     * Based on the actual WooTours plugin structure
     * 
     * @return bool
     */
    public static function is_wootour_active(): bool
    {
        // Method 1: Check for the constant defined by WooTours
        if (defined('WOO_TOUR_PATH')) {
            return true;
        }
        
        // Method 2: Check if the main class exists
        if (class_exists('EX_WooTour')) {
            return true;
        }
        
        // Method 3: Check if the plugin file exists and is active
        if (function_exists('is_plugin_active')) {
            // Try common WooTours plugin paths
            $possible_paths = [
                'wootour/wootour.php',
                'woo-tour/wootour.php',
                'wootours/wootour.php',
            ];
            
            foreach ($possible_paths as $path) {
                if (is_plugin_active($path)) {
                    return true;
                }
            }
        }
        
        // Method 4: Check if WooTours function exists
        if (function_exists('wt_get_plugin_url')) {
            return true;
        }
        
        return false;
    }
    
    /**
     * Get WooTours version if available
     * 
     * @return string|null
     */
    public static function get_wootour_version(): ?string
    {
        if (!self::is_wootour_active()) {
            return null;
        }
        
        // WooTours doesn't define a VERSION constant, but we can get it from plugin data
        if (!function_exists('get_plugin_data')) {
            require_once ABSPATH . 'wp-admin/includes/plugin.php';
        }
        
        $possible_paths = [
            WP_PLUGIN_DIR . '/wootour/wootour.php',
            WP_PLUGIN_DIR . '/woo-tour/wootour.php',
            WP_PLUGIN_DIR . '/wootours/wootour.php',
        ];
        
        foreach ($possible_paths as $path) {
            if (file_exists($path)) {
                $plugin_data = get_plugin_data($path, false, false);
                return $plugin_data['Version'] ?? null;
            }
        }
        
        return null;
    }

    /**
     * Validate environment requirements
     * 
     * @throws \RuntimeException If critical requirements are not met
     * @return bool
     */
    public static function validate_environment(): bool
    {
        $errors = [];

        // Critical checks only (will throw error and prevent plugin from loading)
        if (version_compare(PHP_VERSION, '7.4', '<')) {
            $errors[] = sprintf(
                'Nécessite PHP 7.4 ou supérieur. Version actuelle: %s',
                PHP_VERSION
            );
        }

        if (version_compare(get_bloginfo('version'), '5.6', '<')) {
            $errors[] = 'Nécessite WordPress 5.6 ou supérieur';
        }

        if (!class_exists('WooCommerce')) {
            $errors[] = 'WooCommerce n\'est pas actif';
        }

        // Throw exception if critical requirements not met
        if (!empty($errors)) {
            throw new \RuntimeException(
                'Environment validation failed: ' . implode(', ', $errors)
            );
        }

        // Non-critical warning: WooTours not active
        if (!self::is_wootour_active()) {
            $version = self::get_wootour_version();
            $message = 'WooTours plugin not detected - limited functionality';
            
            error_log('[WootourBulkEditor] Warning: ' . $message);
            
            // Add admin notice
            add_action('admin_notices', function() {
                ?>
                <div class="notice notice-warning is-dismissible">
                    <p>
                        <strong>Wootour Bulk Editor:</strong> 
                        Le plugin WooTours n'est pas détecté. Le plugin fonctionnera en mode limité.
                    </p>
                    <p>
                        Pour une utilisation complète, veuillez installer et activer le plugin 
                        <strong>WooTours</strong> (par ExThemes).
                    </p>
                </div>
                <?php
            });
        } else {
            // Log successful detection
            $version = self::get_wootour_version();
            error_log(sprintf(
                '[WootourBulkEditor] WooTours detected successfully%s',
                $version ? ' (version ' . $version . ')' : ''
            ));
        }

        return true;
    }


    private function __construct() {}

    private function __clone() {}

    public function __wakeup()
    {
        throw new \Exception("Constantes ne peut pas être désérialisée");
    }
}