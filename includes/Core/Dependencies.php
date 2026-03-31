<?php

namespace WBE\Core;

class Dependencies
{
    public static function check(): bool
    {
        if (!self::is_woocommerce_active()) {
            self::admin_notice('WooCommerce doit être installé et activé.');
            return false;
        }

        if (!self::is_wootour_active()) {
            self::admin_notice(
                'Wootour doit être installé et activé. ' .
                'Assurez-vous que le plugin Wootour est correctement installé.'
            );
            return false;
        }
        return true;
    }

    private static function is_woocommerce_active(): bool
    {
        return class_exists('WooCommerce');
    }

    private static function is_wootour_active(): bool
    {
        if (function_exists('is_plugin_active')) {
            if (is_plugin_active('wootour/wootour.php') || 
                is_plugin_active('wootour-pro/wootour.php')) {
                return true;
            }
        }
        
        $active_plugins = get_option('active_plugins', []);
        foreach ($active_plugins as $plugin) {
            if (strpos($plugin, 'wootour') !== false) {
                return true;
            }
        }
        
        if (is_multisite()) {
            $network_plugins = get_site_option('active_sitewide_plugins', []);
            foreach (array_keys($network_plugins) as $plugin) {
                if (strpos($plugin, 'wootour') !== false) {
                    return true;
                }
            }
        }
        
        $wootour_indicators = [
            'wootour_get_version',
            'wootour_plugin_name',
            'wootour_init',
            'wootour',
            'WooTour',
            'Wootour_Plugin',
            'WooTour_Booking',
            'WooTour_Main',
            'Wootour_Loader',
            'WOOTOUR_VERSION',
            'WOOTOUR_PLUGIN_FILE',
            'WOOTOUR_PATH'
        ];
        
        foreach ($wootour_indicators as $indicator) {
            if (function_exists($indicator) || 
                class_exists($indicator) || 
                defined($indicator)) {
                return true;
            }
        }
        
        return false;
    }
}