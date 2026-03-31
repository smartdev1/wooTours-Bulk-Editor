<?php

namespace WBE\Admin;

class Assets
{
    public function __construct()
    {
        add_action('admin_enqueue_scripts', [$this, 'enqueue']);
    }

    public function enqueue(string $hook): void
    {
        if ($hook !== 'woocommerce_page_wootour-bulk-editor') {
            return;
        }

        // jQuery UI Datepicker (inclus dans WordPress)
        wp_enqueue_script('jquery-ui-datepicker');
        wp_enqueue_style('wp-jquery-ui-dialog');

        // Select2 via WooCommerce (handle correct)
        if (class_exists('WooCommerce')) {
            // WooCommerce utilise 'wc-enhanced-select' qui inclut Select2
            wp_enqueue_script('wc-enhanced-select');
            wp_enqueue_style('woocommerce_admin_styles');
        } else {
            // Fallback : charger Select2 depuis CDN si WooCommerce n'est pas dispo
            $this->enqueue_select2_fallback();
        }

        // CSS du plugin
        wp_enqueue_style(
            'wbe-admin',
            WBE_URL . 'admin/css/admin.css',
            [], // Pas de dépendance CSS pour éviter l'erreur
            WBE_VERSION
        );

        // JS du plugin
        wp_enqueue_script(
            'wbe-admin',
            WBE_URL . 'admin/js/admin.js',
            ['jquery', 'jquery-ui-datepicker', 'wc-enhanced-select'], // Utiliser wc-enhanced-select
            WBE_VERSION,
            true
        );

        // Localisation
        wp_localize_script('wbe-admin', 'WBE_DATA', [
            'ajax_url' => admin_url('admin-ajax.php'),
            'nonce'    => wp_create_nonce('wbe_nonce'),
        ]);
    }

    /**
     * Charge Select2 depuis un CDN si WooCommerce n'est pas disponible
     * (normalement ne devrait jamais arriver car on vérifie la dépendance WooCommerce)
     */
    private function enqueue_select2_fallback(): void
    {
        wp_enqueue_script(
            'select2',
            'https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/js/select2.min.js',
            ['jquery'],
            '4.1.0',
            true
        );

        wp_enqueue_style(
            'select2',
            'https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/css/select2.min.css',
            [],
            '4.1.0'
        );
    }
}