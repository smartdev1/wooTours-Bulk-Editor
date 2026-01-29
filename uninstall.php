<?php
/**
 * Wootour Bulk Editor - Uninstall Script
 * 
 * Cleans up plugin data when the plugin is deleted.
 * Runs only when the plugin is deleted via WordPress admin.
 * 
 * @package     WootourBulkEditor
 * @license     GPL-2.0+
 * @since       1.0.0
 */


if (!defined('WP_UNINSTALL_PLUGIN')) {
    exit;
}

$plugin_file = plugin_basename(__FILE__);
$plugin_dir = dirname($plugin_file);
$requested_plugin = isset($_REQUEST['plugin']) ? sanitize_text_field($_REQUEST['plugin']) : '';
$requested_slug = isset($_REQUEST['slug']) ? sanitize_text_field($_REQUEST['slug']) : '';

if ($plugin_dir !== 'wootour-bulk-editor' && 
    $requested_plugin !== 'wootour-bulk-editor/wootour-bulk-editor.php' &&
    $requested_slug !== 'wootour-bulk-editor') {
    exit;
}


if (!function_exists('delete_option')) {
    require_once ABSPATH . 'wp-admin/includes/plugin.php';
    require_once ABSPATH . 'wp-includes/pluggable.php';
}

/**
 * Uninstall handler class
 */
class WBE_Uninstaller
{
    /**
     * Plugin prefix for all options/transients
     */
    const PREFIX = 'wbe_';
    
    /**
     * Options to delete
     */
    const OPTIONS = [
        'wbe_version',
        'wbe_activated_at',
        'wbe_last_cleanup',
        'wbe_logs_*',
        'wbe_settings',
    ];
    
    /**
     * Transient patterns to delete
     */
    const TRANSIENT_PATTERNS = [
        'wbe_*',
        '_transient_wbe_*',
        '_transient_timeout_wbe_*',
    ];
    
    /**
     * Cron hooks to unschedule
     */
    const CRON_HOOKS = [
        'wbe_daily_log_cleanup',
    ];
    
    /**
     * User meta keys to delete
     */
    const USER_META = [
        'wbe_user_settings',
        'wbe_last_viewed',
    ];
    
    /**
     * Run uninstallation
     */
    public static function run()
    {
        
        if (!current_user_can('delete_plugins')) {
            wp_die(
                esc_html__('You do not have permission to delete plugins.', 'wootour-bulk-editor'),
                esc_html__('Permission Denied', 'wootour-bulk-editor'),
                ['response' => 403]
            );
        }
        
        self::log_uninstall_start();
        
        self::unschedule_crons();
        self::delete_transients();
        self::delete_options();
        self::delete_user_meta();
        self::clear_object_cache();
        
        self::log_uninstall_complete();
        
        self::maybe_keep_logs();
        
        do_action('wbe_uninstall_complete');
    }
    
    /**
     * Unschedule cron jobs
     */
    private static function unschedule_crons()
    {
        foreach (self::CRON_HOOKS as $hook) {
            $timestamp = wp_next_scheduled($hook);
            if ($timestamp) {
                wp_unschedule_event($timestamp, $hook);
            }
            wp_clear_scheduled_hook($hook);
        }
    }
    
    /**
     * Delete all plugin transients
     */
    private static function delete_transients()
    {
        global $wpdb;
        
        foreach (self::TRANSIENT_PATTERNS as $pattern) {

            if (strpos($pattern, '*') !== false) {
                $like_pattern = str_replace('*', '%', $pattern);
                
                $transients = $wpdb->get_col($wpdb->prepare(
                    "SELECT option_name 
                    FROM {$wpdb->options} 
                    WHERE option_name LIKE %s 
                    OR option_name LIKE %s",
                    '_transient_' . $like_pattern,
                    '_transient_timeout_' . $like_pattern
                ));
                
                foreach ($transients as $transient) {
                    $name = str_replace(['_transient_', '_transient_timeout_'], '', $transient);
                    delete_transient($name);
                }
                
                if (is_multisite()) {
                    $transients = $wpdb->get_col($wpdb->prepare(
                        "SELECT meta_key 
                        FROM {$wpdb->sitemeta} 
                        WHERE meta_key LIKE %s 
                        OR meta_key LIKE %s",
                        '_site_transient_' . $like_pattern,
                        '_site_transient_timeout_' . $like_pattern
                    ));
                    
                    foreach ($transients as $transient) {
                        $name = str_replace(['_site_transient_', '_site_transient_timeout_'], '', $transient);
                        delete_site_transient($name);
                    }
                }
            } else {

                delete_transient($pattern);
                
                if (is_multisite()) {
                    delete_site_transient($pattern);
                }
            }
        }
    }
    
    /**
     * Delete all plugin options
     */
    private static function delete_options()
    {
        global $wpdb;
        
        foreach (self::OPTIONS as $option) {
            if (strpos($option, '*') !== false) {

                $like_pattern = str_replace('*', '%', $option);
                $options = $wpdb->get_col($wpdb->prepare(
                    "SELECT option_name FROM {$wpdb->options} WHERE option_name LIKE %s",
                    $like_pattern
                ));
                
                foreach ($options as $option_name) {
                    delete_option($option_name);
                    
                    if (is_multisite()) {
                        delete_network_option(null, $option_name);
                    }
                }
            } else {
                delete_option($option);
                
                if (is_multisite()) {
                    delete_network_option(null, $option);
                }
            }
        }
    }
    
    /**
     * Delete user meta data
     */
    private static function delete_user_meta()
    {
        global $wpdb;
        
        foreach (self::USER_META as $meta_key) {

            $wpdb->delete(
                $wpdb->usermeta,
                ['meta_key' => $meta_key],
                ['%s']
            );
            
            if (is_multisite()) {
                $user_ids = $wpdb->get_col("SELECT user_id FROM {$wpdb->usermeta} WHERE meta_key = '{$meta_key}'");
                foreach ($user_ids as $user_id) {
                    delete_user_meta($user_id, $meta_key);
                }
            }
        }
    }
    
    /**
     * Clear object cache
     */
    private static function clear_object_cache()
    {
        wp_cache_flush();
        
        $cache_groups = ['wbe_products', 'wbe_categories', 'wbe_availability'];
        
        foreach ($cache_groups as $group) {
            wp_cache_delete('wbe_cache_flush', $group);
        }
    }
    
    /**
     * Optionally keep logs for a period after uninstall
     */
    private static function maybe_keep_logs()
    {
        $keep_logs = apply_filters('wbe_keep_logs_after_uninstall', false);
        $keep_days = apply_filters('wbe_keep_logs_days', 7);
        
        if (!$keep_logs) {
            return;
        }
        
        global $wpdb;
        

        $log_options = $wpdb->get_col($wpdb->prepare(
            "SELECT option_name FROM {$wpdb->options} WHERE option_name LIKE %s",
            'wbe_logs_%'
        ));
        
        foreach ($log_options as $option_name) {
            
            $expiration = time() + ($keep_days * DAY_IN_SECONDS);
            $wpdb->update(
                $wpdb->options,
                ['autoload' => 'no'],
                ['option_name' => $option_name],
                ['%s'],
                ['%s']
            );
            
            wp_schedule_single_event($expiration, 'wbe_delete_expired_logs', [$option_name]);
        }
        
        if (!has_action('wbe_delete_expired_logs')) {
            add_action('wbe_delete_expired_logs', function($option_name) {
                delete_option($option_name);
            });
        }
    }
    
    /**
     * Log uninstallation start
     */
    private static function log_uninstall_start()
    {
        if (!defined('WP_DEBUG') || !WP_DEBUG) {
            return;
        }
        
        $log_entry = [
            'timestamp' => current_time('mysql'),
            'action'    => 'uninstall_start',
            'data'      => [
                'plugin_version' => get_option('wbe_version', 'unknown'),
                'wordpress_version' => get_bloginfo('version'),
                'php_version'    => PHP_VERSION,
                'user_id'        => get_current_user_id(),
                'user_ip'        => $_SERVER['REMOTE_ADDR'] ?? 'unknown',
                'site_url'       => site_url(),
            ],
        ];
        
        update_option('wbe_uninstall_log', $log_entry, false);

        error_log('[WootourBulkEditor] Uninstallation started by user ' . get_current_user_id());
    }
    
    /**
     * Log uninstallation completion
     */
    private static function log_uninstall_complete()
    {
        if (!defined('WP_DEBUG') || !WP_DEBUG) {
            return;
        }
        
        $start_log = get_option('wbe_uninstall_log', []);
        
        $log_entry = [
            'timestamp' => current_time('mysql'),
            'action'    => 'uninstall_complete',
            'data'      => [
                'duration'      => time() - strtotime($start_log['timestamp'] ?? 'now'),
                'start_data'    => $start_log['data'] ?? [],
                'cleaned_items' => [
                    'options'       => count(self::OPTIONS),
                    'transients'    => count(self::TRANSIENT_PATTERNS),
                    'cron_hooks'    => count(self::CRON_HOOKS),
                    'user_meta'     => count(self::USER_META),
                ],
            ],
        ];

        $log_dir = WP_CONTENT_DIR . '/uploads/wootour-bulk-editor-logs/';
        if (!file_exists($log_dir)) {
            wp_mkdir_p($log_dir);
        }
        
        $log_file = $log_dir . 'uninstall-' . date('Y-m-d') . '.log';
        $log_message = sprintf(
            "[%s] UNINSTALL COMPLETE - User: %d, Site: %s, Cleaned: %d options, %d transients\n",
            current_time('mysql'),
            get_current_user_id(),
            site_url(),
            count(self::OPTIONS),
            count(self::TRANSIENT_PATTERNS)
        );
        
        @file_put_contents($log_file, $log_message, FILE_APPEND | LOCK_EX);

        delete_option('wbe_uninstall_log');
        
        error_log('[WootourBulkEditor] Uninstallation completed successfully');
    }
    
    /**
     * Check if safe to uninstall
     */
    private static function is_safe_to_uninstall()
    {

        $active_plugins = get_option('active_plugins', []);
        $dependent_plugins = [];

        foreach ($active_plugins as $plugin) {
            if (strpos($plugin, 'wootour-bulk-editor') === false) {
                $plugin_data = get_plugin_data(WP_PLUGIN_DIR . '/' . $plugin);
                if (stripos($plugin_data['Description'] ?? '', 'wootour bulk editor') !== false ||
                    stripos($plugin_data['Name'] ?? '', 'wootour bulk editor') !== false) {
                    $dependent_plugins[] = $plugin_data['Name'];
                }
            }
        }
        
        if (!empty($dependent_plugins)) {
            return [
                'safe' => false,
                'message' => sprintf(
                    __('The following plugins may depend on Wootour Bulk Editor: %s', 'wootour-bulk-editor'),
                    implode(', ', $dependent_plugins)
                ),
                'dependent_plugins' => $dependent_plugins
            ];
        }

        global $wpdb;
        $pending_operations = $wpdb->get_var(
            "SELECT COUNT(*) FROM {$wpdb->options} WHERE option_name LIKE '_transient_wbe_resume_%'"
        );
        
        if ($pending_operations > 0) {
            return [
                'safe' => false,
                'message' => sprintf(
                    __('There are %d pending operations. Complete or cancel them before uninstalling.', 'wootour-bulk-editor'),
                    $pending_operations
                ),
                'pending_operations' => $pending_operations
            ];
        }
        
        return ['safe' => true, 'message' => ''];
    }
    
    /**
     * Display uninstall confirmation
     */
    public static function display_confirmation()
    {
        
        $safety_check = self::is_safe_to_uninstall();
        
        if (!$safety_check['safe']) {
            wp_die(
                '<h1>' . esc_html__('Cannot Uninstall', 'wootour-bulk-editor') . '</h1>' .
                '<p>' . esc_html($safety_check['message']) . '</p>' .
                '<p><a href="' . admin_url('plugins.php') . '">' . 
                esc_html__('Return to plugins page', 'wootour-bulk-editor') . '</a></p>',
                esc_html__('Uninstall Blocked', 'wootour-bulk-editor'),
                ['response' => 403]
            );
        }
        
        return true;
    }
    
    /**
     * Get uninstall statistics
     */
    public static function get_stats()
    {
        global $wpdb;
        
        $stats = [
            'options_count' => 0,
            'transients_count' => 0,
            'user_meta_count' => 0,
            'log_entries_count' => 0,
            'total_size' => 0,
        ];
        
        foreach (self::OPTIONS as $option) {
            if (strpos($option, '*') !== false) {
                $like_pattern = str_replace('*', '%', $option);
                $count = $wpdb->get_var($wpdb->prepare(
                    "SELECT COUNT(*) FROM {$wpdb->options} WHERE option_name LIKE %s",
                    $like_pattern
                ));
                $stats['options_count'] += (int) $count;
            } else {
                if (get_option($option) !== false) {
                    $stats['options_count']++;
                }
            }
        }
        
        foreach (self::TRANSIENT_PATTERNS as $pattern) {
            if (strpos($pattern, '*') !== false) {
                $like_pattern = str_replace('*', '%', $pattern);
                $count = $wpdb->get_var($wpdb->prepare(
                    "SELECT COUNT(*) FROM {$wpdb->options} 
                    WHERE option_name LIKE %s OR option_name LIKE %s",
                    '_transient_' . $like_pattern,
                    '_transient_timeout_' . $like_pattern
                ));
                $stats['transients_count'] += (int) $count;
            }
        }
        
        foreach (self::USER_META as $meta_key) {
            $count = $wpdb->get_var($wpdb->prepare(
                "SELECT COUNT(*) FROM {$wpdb->usermeta} WHERE meta_key = %s",
                $meta_key
            ));
            $stats['user_meta_count'] += (int) $count;
        }
        
        $log_options = $wpdb->get_col($wpdb->prepare(
            "SELECT option_name FROM {$wpdb->options} WHERE option_name LIKE %s",
            'wbe_logs_%'
        ));
        
        foreach ($log_options as $option_name) {
            $logs = get_option($option_name, []);
            $stats['log_entries_count'] += count($logs);
        }
        
        $stats['total_size'] = self::calculate_total_size();
        
        return $stats;
    }
    
    /**
     * Calculate total size of plugin data
     */
    private static function calculate_total_size()
    {
        global $wpdb;
        
        $total_size = 0;
        
        foreach (self::OPTIONS as $option) {
            if (strpos($option, '*') !== false) {
                $like_pattern = str_replace('*', '%', $option);
                $sizes = $wpdb->get_col($wpdb->prepare(
                    "SELECT LENGTH(option_value) FROM {$wpdb->options} WHERE option_name LIKE %s",
                    $like_pattern
                ));
                
                foreach ($sizes as $size) {
                    $total_size += (int) $size;
                }
            } else {
                $value = get_option($option);
                if ($value !== false) {
                    $total_size += strlen(serialize($value));
                }
            }
        }
        
        foreach (self::TRANSIENT_PATTERNS as $pattern) {
            if (strpos($pattern, '*') !== false) {
                $like_pattern = str_replace('*', '%', $pattern);
                $sizes = $wpdb->get_col($wpdb->prepare(
                    "SELECT LENGTH(option_value) FROM {$wpdb->options} 
                    WHERE option_name LIKE %s OR option_name LIKE %s",
                    '_transient_' . $like_pattern,
                    '_transient_timeout_' . $like_pattern
                ));
                
                foreach ($sizes as $size) {
                    $total_size += (int) $size;
                }
            }
        }
        
        return $total_size;
    }
}

try {

    if (defined('WP_DEBUG') && WP_DEBUG && is_admin()) {
        WBE_Uninstaller::display_confirmation();
    }
    

    WBE_Uninstaller::run();
    
} catch (Exception $e) {
    
    if (defined('WP_DEBUG') && WP_DEBUG) {
        error_log('[WootourBulkEditor] Uninstall error: ' . $e->getMessage());
        
        $log_dir = WP_CONTENT_DIR . '/uploads/wootour-bulk-editor-logs/';
        if (!file_exists($log_dir)) {
            wp_mkdir_p($log_dir);
        }
        
        $error_log = $log_dir . 'uninstall-error-' . date('Y-m-d-His') . '.log';
        $error_message = sprintf(
            "[%s] UNINSTALL ERROR\nMessage: %s\nFile: %s\nLine: %s\nTrace:\n%s\n",
            current_time('mysql'),
            $e->getMessage(),
            $e->getFile(),
            $e->getLine(),
            $e->getTraceAsString()
        );
        
        @file_put_contents($error_log, $error_message, LOCK_EX);
    }
}

register_shutdown_function(function() {

    global $wpdb;
    
    $wpdb->query($wpdb->prepare(
        "DELETE FROM {$wpdb->options} 
        WHERE option_name LIKE %s OR option_name LIKE %s",
        '_transient_wbe_%',
        '_transient_timeout_wbe_%'
    ));
    
    if (function_exists('wp_cache_flush')) {
        wp_cache_flush();
    }
});