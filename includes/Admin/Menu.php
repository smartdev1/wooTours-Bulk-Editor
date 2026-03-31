<?php

namespace WBE\Admin;

use WBE\Helpers\Security;

class Menu
{
    private $plugin_url;
    private $plugin_path;
    private $version = '1.0.0';

    public function __construct()
    {
        // Définir les chemins si les constantes n'existent pas
        if (!defined('WBE_PLUGIN_URL')) {
            $this->plugin_url = plugin_dir_url(dirname(dirname(__FILE__)));
            $this->plugin_path = plugin_dir_path(dirname(dirname(__FILE__)));
        } else {
            $this->plugin_url = WBE_PLUGIN_URL;
            $this->plugin_path = WBE_PLUGIN_DIR;
        }
        
        if (!defined('WBE_VERSION')) {
            define('WBE_VERSION', $this->version);
        }

        add_action('admin_menu', [$this, 'register_menu']);
        add_action('admin_enqueue_scripts', [$this, 'enqueue_scripts']);
    }

    public function register_menu(): void
    {
        if (!Security::can_manage_woocommerce()) {
            return;
        }

        add_submenu_page(
            'woocommerce',
            'Édition Massive Wootour',
            'Wootour Bulk Editor',
            'manage_woocommerce',
            'wootour-bulk-editor',
            [$this, 'render_page']
        );
    }

    public function render_page(): void
    {
        if (!Security::can_manage_woocommerce()) {
            wp_die(__('Vous n\'avez pas les permissions nécessaires pour accéder à cette page.'));
        }

        // Utiliser le chemin calculé
        require $this->plugin_path . 'admin/views/bulk-editor-page.php';
    }

    public function enqueue_scripts(string $hook): void
    {
        if ($hook !== 'woocommerce_page_wootour-bulk-editor') {
            return;
        }

        // 1. Charger jQuery UI Datepicker
        wp_enqueue_script('jquery-ui-datepicker');
        
        // 2. Charger le style jQuery UI
        wp_enqueue_style(
            'jquery-ui-style',
            'https://code.jquery.com/ui/1.12.1/themes/smoothness/jquery-ui.css',
            [],
            '1.12.1'
        );
        
        // 3. Charger Select2 (WooCommerce)
        if (function_exists('WC')) {
            wp_enqueue_script('select2');
            wp_enqueue_style('select2', WC()->plugin_url() . '/assets/css/select2.css');
        }

        // 4. Notre style CSS personnalisé (utiliser l'URL calculée)
        wp_enqueue_style(
            'wbe-admin-style',
            $this->plugin_url . 'assets/css/admin.css',
            [],
            WBE_VERSION
        );

        // 5. Notre script JavaScript principal
        wp_enqueue_script(
            'wbe-admin-script',
            $this->plugin_url . 'assets/js/admin.js',
            ['jquery', 'jquery-ui-datepicker', 'select2'],
            WBE_VERSION,
            true
        );

        // 6. Passer des données au script JavaScript
        wp_localize_script('wbe-admin-script', 'WBE_DATA', [
            'ajax_url' => admin_url('admin-ajax.php'),
            'nonce' => wp_create_nonce('wbe_nonce'),
            'date_format' => $this->get_js_date_format(),
            'i18n' => [
                'select_date' => __('Sélectionner une date', 'wootour-bulk-editor'),
                'start_date' => __('Date de début', 'wootour-bulk-editor'),
                'end_date' => __('Date de fin', 'wootour-bulk-editor'),
                'specific_date' => __('Date spécifique', 'wootour-bulk-editor'),
                'apply_changes' => __('Appliquer les modifications', 'wootour-bulk-editor'),
                'reset_all' => __('Tout réinitialiser', 'wootour-bulk-editor'),
            ],
            'plugin_url' => $this->plugin_url,
        ]);
    }

    private function get_js_date_format(): string
    {
        $php_format = get_option('date_format', 'd/m/Y');
        
        $conversions = [
            'd/m/Y' => 'dd/mm/yy',
            'm/d/Y' => 'mm/dd/yy',
            'Y-m-d' => 'yy-mm-dd',
            'F j, Y' => 'MM d, yy',
            'j F Y' => 'd MM yy',
        ];
        
        return $conversions[$php_format] ?? 'dd/mm/yy';
    }
}