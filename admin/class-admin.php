<?php
/**
 * Wootour Bulk Editor - Admin Class
 * 
 * Main admin class for handling WordPress admin integration.
 * 
 * @package     WootourBulkEditor
 * @license     GPL-2.0+
 * @since       1.0.0
 */

namespace WootourBulkEditor\Admin;

use WootourBulkEditor\Core\Constants;
use WootourBulkEditor\Traits\Singleton;

// Exit if accessed directly
defined('ABSPATH') || exit;

/**
 * Class Admin
 * 
 * Handles WordPress admin integration for the plugin
 */
final class Admin
{
    use Singleton;

    /**
     * Page hook suffix
     */
    private string $page_hook;

    /**
     * Private constructor
     */
    private function __construct()
    {
        // Constructeur en privé pour le singleton
    }

    /**
     * Initialize admin functionality
     */
    public function init(): void
    {

        add_action('admin_menu', [$this, 'register_admin_menu']);
        
        add_action('admin_enqueue_scripts', [$this, 'enqueue_admin_assets']);

        add_filter('plugin_action_links_' . Constants::BASENAME, [$this, 'add_plugin_action_links']);
        
        add_action('admin_notices', [$this, 'display_admin_notices']);
        
        add_action('wp_ajax_wbe_admin_test', [$this, 'handle_admin_test']);
        add_action('wp_ajax_wbe_admin_diagnostic', [$this, 'handle_admin_diagnostic']);
    }

    /**
     * Register admin menu under WooCommerce
     */
    public function register_admin_menu(): void
    {
        if (!$this->user_can_access()) {
            return;
        }

        $this->page_hook = add_submenu_page(
            'woocommerce',
            __('Wootour Bulk Editor', Constants::TEXT_DOMAIN),
            __('Wootour Bulk Edit', Constants::TEXT_DOMAIN),
            'manage_woocommerce',
            'wootour-bulk-edit',
            [$this, 'render_admin_page'],
            56
        );

        add_action('load-' . $this->page_hook, [$this, 'add_help_tabs']);
    }

    /**
     * Check if current user can access the plugin
     */
    private function user_can_access(): bool
    {
        foreach (Constants::REQUIRED_CAPS as $role => $cap) {
            if (current_user_can($cap)) {
                return true;
            }
        }
        
        return false;
    }

    /**
     * Enqueue admin scripts and styles
     */
    public function enqueue_admin_assets(string $hook_suffix): void
    {
        if ($hook_suffix !== $this->page_hook) {
            return;
        }

        wp_enqueue_script('jquery-ui-datepicker');
        wp_enqueue_style('jquery-ui-style', 
            'https://ajax.googleapis.com/ajax/libs/jqueryui/1.12.1/themes/smoothness/jquery-ui.css',
            [], 
            '1.12.1'
        );

        wp_enqueue_style(
            'wbe-admin',
            Constants::plugin_url() . 'admin/assets/css/admin.min.css',
            ['wp-components', 'jquery-ui-style'],
            Constants::VERSION
        );

        wp_enqueue_script(
            'wbe-admin',
            Constants::plugin_url() . 'admin/assets/js/admin.min.js',
            ['jquery', 'jquery-ui-datepicker', 'wp-util', 'wp-i18n'],
            Constants::VERSION,
            true
        );

        $this->localize_admin_script();
    }

    /**
     * Localize admin script with PHP data
     */
    private function localize_admin_script(): void
    {
        $localization_data = [
            'ajax_url' => admin_url('admin-ajax.php'),
            'admin_url' => admin_url(),
            'plugin_url' => Constants::plugin_url(),
            'nonce' => wp_create_nonce('wbe_ajax_nonce'),
            'admin_nonce' => wp_create_nonce('wbe_admin_nonce'),
            
            'version' => Constants::VERSION,
            'batch_size' => Constants::BATCH_SIZE,
            'timeout_seconds' => Constants::TIMEOUT_SECONDS,
            'date_format' => Constants::DATE_FORMATS['display'],
            'date_format_js' => Constants::DATE_FORMATS['js'],
            
            'user_id' => get_current_user_id(),
            'user_can' => [
                'manage_products' => current_user_can('edit_products'),
                'manage_woocommerce' => current_user_can('manage_woocommerce'),
            ],
            
            'system' => [
                'memory_limit' => ini_get('memory_limit'),
                'max_execution_time' => ini_get('max_execution_time'),
                'php_version' => PHP_VERSION,
                'wp_version' => get_bloginfo('version'),
            ],
        ];

        wp_localize_script('wbe-admin', 'wbe_admin', $localization_data);
    }

    /**
     * Render admin page
     */
    public function render_admin_page(): void
    {
        if (!$this->user_can_access()) {
            wp_die(__('You do not have sufficient permissions to access this page.', Constants::TEXT_DOMAIN));
        }

        $template_path = Constants::plugin_dir() . 'admin/views/admin-page.php';
        
        if (file_exists($template_path)) {
            include $template_path;
        } else {
            echo '<div class="error"><p>';
            echo sprintf(
                __('Template file not found: %s', Constants::TEXT_DOMAIN),
                esc_html($template_path)
            );
            echo '</p></div>';
        }
    }

    /**
     * Add help tabs to admin page
     */
    public function add_help_tabs(): void
    {
        $screen = get_current_screen();
        
        if ($screen->id !== $this->page_hook) {
            return;
        }

        $screen->add_help_tab([
            'id'      => 'wbe-overview',
            'title'   => __('Overview', Constants::TEXT_DOMAIN),
            'content' => '
                <h2>' . __('Wootour Bulk Editor', Constants::TEXT_DOMAIN) . '</h2>
                <p>' . __('This plugin allows you to bulk edit availability dates for Wootour products.', Constants::TEXT_DOMAIN) . '</p>
                <p><strong>' . __('Key Principle:', Constants::TEXT_DOMAIN) . '</strong> ' . 
                __('Empty fields in the form do NOT overwrite existing data in the products.', Constants::TEXT_DOMAIN) . '</p>
            ',
        ]);

        $screen->add_help_tab([
            'id'      => 'wbe-usage',
            'title'   => __('How to Use', Constants::TEXT_DOMAIN),
            'content' => '
                <h2>' . __('How to Use', Constants::TEXT_DOMAIN) . '</h2>
                <ol>
                    <li><strong>' . __('Select Products:', Constants::TEXT_DOMAIN) . '</strong> ' . 
                    __('Filter by category or search for specific products.', Constants::TEXT_DOMAIN) . '</li>
                    <li><strong>' . __('Set Availability:', Constants::TEXT_DOMAIN) . '</strong> ' . 
                    __('Use date pickers and calendars to define availability rules.', Constants::TEXT_DOMAIN) . '</li>
                    <li><strong>' . __('Preview:', Constants::TEXT_DOMAIN) . '</strong> ' . 
                    __('Review changes before applying them.', Constants::TEXT_DOMAIN) . '</li>
                    <li><strong>' . __('Apply:', Constants::TEXT_DOMAIN) . '</strong> ' . 
                    __('Apply changes to all selected products.', Constants::TEXT_DOMAIN) . '</li>
                </ol>
            ',
        ]);

        $screen->set_help_sidebar(
            '<p><strong>' . __('For more information:', Constants::TEXT_DOMAIN) . '</strong></p>' .
            '<p><a href="https://wordpress.org/support/" target="_blank">' . __('WordPress Support', Constants::TEXT_DOMAIN) . '</a></p>' .
            '<p><a href="https://woocommerce.com/documentation/" target="_blank">' . __('WooCommerce Docs', Constants::TEXT_DOMAIN) . '</a></p>'
        );
    }

    /**
     * Add plugin action links
     */
    public function add_plugin_action_links(array $links): array
    {
        if (!$this->user_can_access()) {
            return $links;
        }

        $action_links = [
            'settings' => sprintf(
                '<a href="%s">%s</a>',
                admin_url('admin.php?page=wootour-bulk-edit'),
                __('Bulk Edit', Constants::TEXT_DOMAIN)
            ),
        ];

        return array_merge($action_links, $links);
    }

    /**
     * Display admin notices
     */
    public function display_admin_notices(): void
    {
        $screen = get_current_screen();
        
        $show_on_pages = ['plugins', $this->page_hook];
        
        if (!$screen || !in_array($screen->id, $show_on_pages, true)) {
            return;
        }

        if (!defined('WOOTOUR_VERSION')) {
            $this->render_notice(
                'warning',
                __('Wootour plugin is not active. This plugin requires Wootour to function properly.', Constants::TEXT_DOMAIN)
            );
        }

        if (!class_exists('WooCommerce')) {
            $this->render_notice(
                'error',
                __('WooCommerce is not active. This plugin requires WooCommerce.', Constants::TEXT_DOMAIN)
            );
        }

        if (version_compare(PHP_VERSION, '7.4', '<')) {
            $this->render_notice(
                'warning',
                sprintf(
                    __('Your PHP version (%s) is below the recommended version 7.4.', Constants::TEXT_DOMAIN),
                    PHP_VERSION
                )
            );
        }
    }

    /**
     * Render a notice
     */
    private function render_notice(string $type, string $message): void
    {
        printf(
            '<div class="notice notice-%s"><p>%s</p></div>',
            esc_attr($type),
            esc_html($message)
        );
    }

    /**
     * Handle admin test AJAX request
     */
    public function handle_admin_test(): void
    {
        if (!check_ajax_referer('wbe_admin_nonce', 'nonce', false)) {
            wp_send_json_error(['message' => 'Security check failed']);
        }

        if (!$this->user_can_access()) {
            wp_send_json_error(['message' => 'Permission denied']);
        }

        $test_results = [
            'php_version' => PHP_VERSION,
            'wp_version' => get_bloginfo('version'),
            'woocommerce_active' => class_exists('WooCommerce'),
            'wootour_active' => defined('WOOTOUR_VERSION'),
            'memory_limit' => ini_get('memory_limit'),
            'max_execution_time' => ini_get('max_execution_time'),
            'plugin_version' => Constants::VERSION,
            'timestamp' => current_time('mysql'),
        ];

        wp_send_json_success($test_results);
    }

    /**
     * Handle admin diagnostic AJAX request
     */
    public function handle_admin_diagnostic(): void
    {
        if (!check_ajax_referer('wbe_admin_nonce', 'nonce', false)) {
            wp_send_json_error(['message' => 'Security check failed']);
        }

        if (!$this->user_can_access()) {
            wp_send_json_error(['message' => 'Permission denied']);
        }

        $diagnostic = [
            'server' => [
                'php_version' => PHP_VERSION,
                'server_software' => $_SERVER['SERVER_SOFTWARE'] ?? 'Unknown',
                'server_name' => $_SERVER['SERVER_NAME'] ?? 'Unknown',
            ],
            'wordpress' => [
                'version' => get_bloginfo('version'),
                'multisite' => is_multisite(),
                'debug_mode' => defined('WP_DEBUG') && WP_DEBUG,
                'language' => get_locale(),
            ],
            'plugins' => [
                'woocommerce' => [
                    'active' => class_exists('WooCommerce'),
                    'version' => defined('WC_VERSION') ? WC_VERSION : 'Not active',
                ],
                'wootour' => [
                    'active' => defined('WOOTOUR_VERSION'),
                    'version' => defined('WOOTOUR_VERSION') ? WOOTOUR_VERSION : 'Not active',
                ],
                'our_plugin' => [
                    'version' => Constants::VERSION,
                    'path' => Constants::plugin_dir(),
                ],
            ],
            'php' => [
                'memory_limit' => ini_get('memory_limit'),
                'max_execution_time' => ini_get('max_execution_time'),
                'upload_max_filesize' => ini_get('upload_max_filesize'),
                'post_max_size' => ini_get('post_max_size'),
                'max_input_vars' => ini_get('max_input_vars'),
                'timezone' => ini_get('date.timezone'),
            ],
            'database' => [
                'charset' => DB_CHARSET,
                'collate' => DB_COLLATE,
                'version' => $GLOBALS['wpdb']->db_version(),
            ],
        ];

        wp_send_json_success($diagnostic);
    }

    /**
     * Get admin page URL
     */
    public function get_admin_url(): string
    {
        return admin_url('admin.php?page=wootour-bulk-edit');
    }

    /**
     * Get page hook suffix
     */
    public function get_page_hook(): string
    {
        return $this->page_hook;
    }

    /**
     * Check if current page is plugin admin page
     */
    public function is_plugin_page(): bool
    {
        $screen = get_current_screen();
        return $screen && $screen->id === $this->page_hook;
    }

    /**
     * Cleanup admin data (for uninstall/deactivation)
     */
    public function cleanup(): void
    {
        remove_submenu_page('woocommerce', 'wootour-bulk-edit');
        
        $transients = [
            'wbe_admin_notice',
            'wbe_admin_cache',
        ];
        
        foreach ($transients as $transient) {
            delete_transient($transient);
        }
    }
}