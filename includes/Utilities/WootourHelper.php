<?php
/**
 * Wootour Bulk Editor - Wootour Helper
 * 
 * Utility functions for Wootour plugin integration.
 * 
 * @package     WootourBulkEditor
 * @subpackage  Utilities
 
 * @license     GPL-2.0+
 * @since       1.0.0
 */

namespace WootourBulkEditor\Utilities;

// Exit if accessed directly
defined('ABSPATH') || exit;

/**
 * Class WootourHelper
 * 
 * Static utility methods for Wootour integration
 */
final class WootourHelper
{
    /**
     * Check if Wootour plugin is active
     */
    public static function isWootourActive(): bool
    {
        return defined('WOOTOUR_VERSION') || class_exists('WooTour');
    }

    /**
     * Get Wootour version
     */
    public static function getWootourVersion(): ?string
    {
        if (defined('WOOTOUR_VERSION')) {
            return WOOTOUR_VERSION;
        }
        
        if (function_exists('get_plugin_data')) {
            $plugin_data = get_plugin_data(WP_PLUGIN_DIR . '/wootour/wootour.php');
            return $plugin_data['Version'] ?? null;
        }
        
        return null;
    }

    /**
     * Detect Wootour meta keys
     */
    public static function detectMetaKeys(): array
    {
        global $wpdb;
        
        $possible_keys = [
            '_wootour_availability',
            '_wootour_availabilities',
            '_tour_availability',
            '_wootour_dates',
            '_availability_data',
            '_wootour_v3_availability',
            '_wootour_calendar_data',
        ];
        
        $detected_keys = [];
        
        foreach ($possible_keys as $key) {
            $exists = $wpdb->get_var($wpdb->prepare(
                "SELECT COUNT(*) FROM {$wpdb->postmeta} 
                WHERE meta_key = %s 
                AND post_id IN (
                    SELECT ID FROM {$wpdb->posts} 
                    WHERE post_type = 'product'
                ) 
                LIMIT 1",
                $key
            ));
            
            if ($exists > 0) {
                $detected_keys[] = $key;
            }
        }
        
        return $detected_keys;
    }

    /**
     * Get primary meta key used by Wootour
     */
    public static function getPrimaryMetaKey(): string
    {
        $detected_keys = self::detectMetaKeys();
        
        if (!empty($detected_keys)) {
            // Prefer the most specific key
            foreach ($detected_keys as $key) {
                if (strpos($key, '_wootour_availability') !== false) {
                    return $key;
                }
            }
            
            return $detected_keys[0];
        }
        
        // Default fallback
        return '_wootour_availability';
    }

    /**
     * Analyze Wootour data structure
     */
    public static function analyzeStructure(string $meta_key): array
    {
        global $wpdb;
        
        $analysis = [
            'meta_key' => $meta_key,
            'format' => 'unknown',
            'sample_count' => 0,
            'sample_data' => [],
            'field_patterns' => [],
        ];
        
        // Get sample data
        $samples = $wpdb->get_results($wpdb->prepare(
            "SELECT meta_value FROM {$wpdb->postmeta} 
            WHERE meta_key = %s 
            AND meta_value IS NOT NULL 
            AND meta_value != '' 
            LIMIT 5",
            $meta_key
        ));
        
        if (empty($samples)) {
            return $analysis;
        }
        
        $analysis['sample_count'] = count($samples);
        
        foreach ($samples as $sample) {
            $value = $sample->meta_value;
            $analysis['sample_data'][] = substr($value, 0, 100) . (strlen($value) > 100 ? '...' : '');
            
            // Detect format
            if (self::isSerialized($value)) {
                $analysis['format'] = 'serialized';
                $decoded = maybe_unserialize($value);
                if (is_array($decoded)) {
                    $analysis['field_patterns'] = array_merge(
                        $analysis['field_patterns'],
                        array_keys($decoded)
                    );
                }
            } elseif (self::isJson($value)) {
                $analysis['format'] = 'json';
                $decoded = json_decode($value, true);
                if (is_array($decoded)) {
                    $analysis['field_patterns'] = array_merge(
                        $analysis['field_patterns'],
                        array_keys($decoded)
                    );
                }
            } elseif (strpos($value, '|') !== false) {
                $analysis['format'] = 'pipe_delimited';
            } elseif (strpos($value, ',') !== false) {
                $analysis['format'] = 'comma_delimited';
            }
        }
        
        $analysis['field_patterns'] = array_unique($analysis['field_patterns']);
        
        return $analysis;
    }

    /**
     * Check if string is serialized data
     */
    private static function isSerialized(string $data): bool
    {
        return is_serialized($data);
    }

    /**
     * Check if string is valid JSON
     */
    private static function isJson(string $data): bool
    {
        json_decode($data);
        return json_last_error() === JSON_ERROR_NONE;
    }

    /**
     * Get Wootour availability for a product
     */
    public static function getProductAvailability(int $product_id, string $meta_key = ''): array
    {
        if (empty($meta_key)) {
            $meta_key = self::getPrimaryMetaKey();
        }
        
        $raw_data = get_post_meta($product_id, $meta_key, true);
        
        if (empty($raw_data)) {
            return [];
        }
        
        // Try to decode based on detected format
        $analysis = self::analyzeStructure($meta_key);
        $format = $analysis['format'] ?? 'auto';
        
        switch ($format) {
            case 'serialized':
                $data = maybe_unserialize($raw_data);
                break;
                
            case 'json':
                $data = json_decode($raw_data, true);
                break;
                
            case 'pipe_delimited':
                $data = self::parsePipeDelimited($raw_data);
                break;
                
            case 'comma_delimited':
                $data = self::parseCommaDelimited($raw_data);
                break;
                
            default:
                $data = self::autoDetectFormat($raw_data);
        }
        
        return is_array($data) ? $data : ['raw' => $raw_data];
    }

    /**
     * Parse pipe-delimited format
     */
    private static function parsePipeDelimited(string $data): array
    {
        $parts = explode('|', $data);
        $result = [];
        
        // Basic mapping (adjust based on actual Wootour structure)
        if (isset($parts[0])) $result['start_date'] = trim($parts[0]);
        if (isset($parts[1])) $result['end_date'] = trim($parts[1]);
        if (isset($parts[2])) $result['weekdays'] = array_map('trim', explode(',', $parts[2]));
        
        return $result;
    }

    /**
     * Parse comma-delimited format
     */
    private static function parseCommaDelimited(string $data): array
    {
        $parts = array_map('trim', explode(',', $data));
        $result = [];
        
        // Try to identify what each part represents
        foreach ($parts as $part) {
            if (strtotime($part) !== false) {
                if (empty($result['dates'])) {
                    $result['dates'] = [];
                }
                $result['dates'][] = date('Y-m-d', strtotime($part));
            } elseif (is_numeric($part) && $part >= 0 && $part <= 6) {
                if (empty($result['weekdays'])) {
                    $result['weekdays'] = [];
                }
                $result['weekdays'][] = (int) $part;
            }
        }
        
        return $result;
    }

    /**
     * Auto-detect data format
     */
    private static function autoDetectFormat(string $data): array
    {
        // Try serialized
        if (self::isSerialized($data)) {
            $decoded = maybe_unserialize($data);
            if (is_array($decoded)) {
                return $decoded;
            }
        }
        
        // Try JSON
        if (self::isJson($data)) {
            $decoded = json_decode($data, true);
            if (is_array($decoded)) {
                return $decoded;
            }
        }
        
        // Return as raw data
        return ['raw' => $data];
    }

    /**
     * Save availability to Wootour format
     */
    public static function saveAvailability(
        int $product_id, 
        array $data, 
        string $meta_key = '',
        string $format = ''
    ): bool {
        if (empty($meta_key)) {
            $meta_key = self::getPrimaryMetaKey();
        }
        
        if (empty($format)) {
            $analysis = self::analyzeStructure($meta_key);
            $format = $analysis['format'] ?? 'serialized';
        }
        
        // Convert to Wootour format
        $formatted_data = self::formatForWootour($data, $format);
        
        // Save to database
        $result = update_post_meta($product_id, $meta_key, $formatted_data);
        
        return $result !== false;
    }

    /**
     * Format data for Wootour storage
     */
    public static function formatForWootour(array $data, string $format): string
    {
        switch ($format) {
            case 'json':
                return json_encode($data, JSON_UNESCAPED_UNICODE);
                
            case 'pipe_delimited':
                return self::formatPipeDelimited($data);
                
            case 'comma_delimited':
                return self::formatCommaDelimited($data);
                
            case 'serialized':
            default:
                return serialize($data);
        }
    }

    /**
     * Format as pipe-delimited string
     */
    private static function formatPipeDelimited(array $data): string
    {
        $parts = [
            $data['start_date'] ?? '',
            $data['end_date'] ?? '',
            isset($data['weekdays']) && is_array($data['weekdays']) ? 
                implode(',', $data['weekdays']) : '',
        ];
        
        return implode('|', $parts);
    }

    /**
     * Format as comma-delimited string
     */
    private static function formatCommaDelimited(array $data): string
    {
        $parts = [];
        
        if (!empty($data['start_date'])) {
            $parts[] = $data['start_date'];
        }
        
        if (!empty($data['end_date'])) {
            $parts[] = $data['end_date'];
        }
        
        if (!empty($data['weekdays']) && is_array($data['weekdays'])) {
            $parts = array_merge($parts, $data['weekdays']);
        }
        
        if (!empty($data['exclusions']) && is_array($data['exclusions'])) {
            $parts = array_merge($parts, $data['exclusions']);
        }
        
        return implode(',', $parts);
    }

    /**
     * Get Wootour hooks
     */
    public static function getWootourHooks(): array
    {
        global $wp_filter;
        
        $wootour_hooks = [];
        
        foreach ($wp_filter as $hook => $filters) {
            if (strpos($hook, 'wootour') !== false || 
                strpos($hook, 'tour') !== false ||
                strpos($hook, 'availability') !== false) {
                $wootour_hooks[] = $hook;
            }
        }
        
        sort($wootour_hooks);
        
        return $wootour_hooks;
    }

    /**
     * Check if Wootour hook exists
     */
    public static function hasHook(string $hook): bool
    {
        return has_action($hook) || has_filter($hook);
    }

    /**
     * Get Wootour settings
     */
    public static function getWootourSettings(): array
    {
        $settings = [];
        
        // Try to get Wootour settings from options
        $possible_options = [
            'wootour_settings',
            'tour_settings',
            'woocommerce_tour_settings',
        ];
        
        foreach ($possible_options as $option) {
            $value = get_option($option);
            if ($value) {
                if (is_array($value)) {
                    $settings = array_merge($settings, $value);
                } else {
                    $settings[$option] = $value;
                }
            }
        }
        
        return $settings;
    }

    /**
     * Check if product type is supported by Wootour
     */
    public static function isProductTypeSupported(string $product_type): bool
    {
        $supported_types = apply_filters('wootour_supported_product_types', [
            'simple',
            'variable',
            'tour',
            'activity',
        ]);
        
        return in_array($product_type, $supported_types, true);
    }

    /**
     * Get Wootour product types
     */
    public static function getWootourProductTypes(): array
    {
        $types = [];
        
        // Try to get from Wootour if function exists
        if (function_exists('wootour_get_product_types')) {
            $types = wootour_get_product_types();
        } elseif (class_exists('WooTour')) {
            // Try reflection or other methods
            $types = ['simple', 'tour'];
        }
        
        return apply_filters('wbe_wootour_product_types', $types);
    }

    /**
     * Validate Wootour data compatibility
     */
    public static function validateCompatibility(): array
    {
        $compatibility = [
            'wootour_active' => self::isWootourActive(),
            'wootour_version' => self::getWootourVersion(),
            'meta_keys_detected' => self::detectMetaKeys(),
            'primary_meta_key' => self::getPrimaryMetaKey(),
            'hooks_available' => count(self::getWootourHooks()) > 0,
            'settings_available' => !empty(self::getWootourSettings()),
        ];
        
        if ($compatibility['wootour_active'] && !empty($compatibility['primary_meta_key'])) {
            $analysis = self::analyzeStructure($compatibility['primary_meta_key']);
            $compatibility['data_format'] = $analysis['format'];
            $compatibility['field_patterns'] = $analysis['field_patterns'];
        }
        
        return $compatibility;
    }

    /**
     * Get sample products with Wootour data
     */
    public static function getSampleProducts(int $limit = 3): array
    {
        global $wpdb;
        
        $meta_key = self::getPrimaryMetaKey();
        
        $product_ids = $wpdb->get_col($wpdb->prepare(
            "SELECT post_id FROM {$wpdb->postmeta} 
            WHERE meta_key = %s 
            AND meta_value IS NOT NULL 
            AND meta_value != '' 
            LIMIT %d",
            $meta_key,
            $limit
        ));
        
        $samples = [];
        
        foreach ($product_ids as $product_id) {
            $product = wc_get_product($product_id);
            if ($product) {
                $availability = self::getProductAvailability($product_id, $meta_key);
                
                $samples[] = [
                    'id' => $product_id,
                    'name' => $product->get_name(),
                    'sku' => $product->get_sku(),
                    'availability' => $availability,
                    'meta_key' => $meta_key,
                ];
            }
        }
        
        return $samples;
    }

    /**
     * Clear Wootour cache for a product
     */
    public static function clearProductCache(int $product_id): void
    {
        // Clear WordPress object cache
        wp_cache_delete($product_id, 'post_meta');
        
        // Clear any Wootour-specific cache
        $cache_keys = [
            "wootour_availability_{$product_id}",
            "tour_dates_{$product_id}",
            "availability_data_{$product_id}",
        ];
        
        foreach ($cache_keys as $key) {
            wp_cache_delete($key, 'wootour');
            delete_transient($key);
        }
        
        // Trigger Wootour cache clear if hook exists
        if (self::hasHook('wootour_clear_cache')) {
            do_action('wootour_clear_cache', $product_id);
        }
    }

    /**
     * Get Wootour plugin info
     */
    public static function getPluginInfo(): array
    {
        if (!function_exists('get_plugin_data')) {
            return [];
        }
        
        $plugin_paths = [
            'wootour/wootour.php',
            'woo-tour/woo-tour.php',
            'woocommerce-tour/woocommerce-tour.php',
        ];
        
        foreach ($plugin_paths as $path) {
            $full_path = WP_PLUGIN_DIR . '/' . $path;
            if (file_exists($full_path)) {
                $plugin_data = get_plugin_data($full_path);
                return [
                    'name' => $plugin_data['Name'] ?? '',
                    'version' => $plugin_data['Version'] ?? '',
                    'author' => $plugin_data['Author'] ?? '',
                    'description' => $plugin_data['Description'] ?? '',
                    'path' => $path,
                ];
            }
        }
        
        return [];
    }

    /**
     * Check if Wootour function exists
     */
    public static function functionExists(string $function_name): bool
    {
        return function_exists($function_name);
    }

    /**
     * Get Wootour function list
     */
    public static function getFunctionList(): array
    {
        $functions = [
            'wootour_get_availability',
            'wootour_save_availability',
            'wootour_get_settings',
            'wootour_get_product_types',
            'tour_get_dates',
            'tour_save_dates',
        ];
        
        $available = [];
        
        foreach ($functions as $function) {
            if (self::functionExists($function)) {
                $available[] = $function;
            }
        }
        
        return $available;
    }

    /**
     * Test Wootour integration
     */
    public static function testIntegration(): array
    {
        $results = [
            'compatibility' => self::validateCompatibility(),
            'sample_products' => self::getSampleProducts(2),
            'plugin_info' => self::getPluginInfo(),
            'available_functions' => self::getFunctionList(),
            'hooks' => self::getWootourHooks(),
        ];
        
        return $results;
    }
}