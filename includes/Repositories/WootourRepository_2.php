<?php

/**
 * Wootour Bulk Editor - Wootour Repository
 * 
 * Handles all interactions with Wootour plugin data
 * without modifying Wootour's code directly.
 * 
 * @package     WootourBulkEditor
 * @subpackage  Repositories
 
 * @license     GPL-2.0+
 * @since       1.0.0
 */

namespace WootourBulkEditor\Repositories;

use WootourBulkEditor\Core\Constants;
use WootourBulkEditor\Models\Availability;
use WootourBulkEditor\Exceptions\WootourException;
use WootourBulkEditor\Traits\Singleton;

// Exit if accessed directly
defined('ABSPATH') || exit;

/**
 * Class WootourRepository
 * 
 * Repository pattern for accessing and manipulating Wootour data.
 * Acts as an abstraction layer between our plugin and Wootour.
 */
final class WootourRepository implements RepositoryInterface
{
    use Singleton;

    /**
     * The actual meta key used by Wootour (detected at runtime)
     */
    private string $meta_key;

    /**
     * Whether we've detected the meta key structure
     */
    private bool $structure_detected = false;

    /**
     * Cache for product availability data
     */
    private array $availability_cache = [];

    /**
     * Private constructor for Singleton
     */
    private function __construct()
    {
        $this->detect_wootour_structure();
    }

    /**
     * Initialize the repository
     */
    public function init(): void
    {
        // Add cleanup hook for cache
        add_action('save_post_product', [$this, 'clear_product_cache'], 10, 2);
        add_action('wootour_availability_updated', [$this, 'clear_product_cache'], 10, 1);
    }

    /**
     * Detect Wootour's data structure and meta key
     * 
     * @throws WootourException If Wootour structure cannot be detected
     */
    private function detect_wootour_structure(): void
    {
        // Cache first
        $cached = get_transient('wbe_wootour_structure');
        if (is_array($cached) && !empty($cached['meta_key'])) {
            $this->meta_key = $cached['meta_key'];
            $this->structure_detected = true;
            return;
        }

        global $wpdb;

        // 🔍 Détection dynamique réelle
        $meta_key = $wpdb->get_var("
        SELECT meta_key
        FROM {$wpdb->postmeta}
        WHERE meta_key LIKE '%tour%'
           OR meta_key LIKE '%wootour%'
           OR meta_key LIKE '%availability%'
        GROUP BY meta_key
        ORDER BY COUNT(*) DESC
        LIMIT 1
    ");

        if (!empty($meta_key)) {
            $this->meta_key = $meta_key;
            $this->structure_detected = true;

            set_transient('wbe_wootour_structure', [
                'meta_key' => $meta_key,
                'detected_at' => current_time('mysql'),
            ], DAY_IN_SECONDS);

            error_log('[WootourBulkEditor] WooTour meta detected dynamically: ' . $meta_key);
            return;
        }

        // 🔁 Fallback contrôlé (lecture seule)
        $this->meta_key = Constants::META_KEYS['availability'];
        $this->structure_detected = false;

        error_log('[WootourBulkEditor] WooTour structure is variable, using runtime detection');
    }


    /**
     * Analyze sample data to understand Wootour's structure
     */
    private function analyze_structure_samples(string $meta_key): void
    {
        global $wpdb;

        $samples = $wpdb->get_results($wpdb->prepare(
            "SELECT meta_value FROM {$wpdb->postmeta} 
            WHERE meta_key = %s 
            AND meta_value IS NOT NULL 
            AND meta_value != '' 
            LIMIT 5",
            $meta_key
        ));

        $structures = [];
        foreach ($samples as $sample) {
            $value = $sample->meta_value;

            // Try to determine the format
            if ($this->is_serialized($value)) {
                $structures[] = 'serialized';
            } elseif ($this->is_json($value)) {
                $structures[] = 'json';
            } elseif (str_contains($value, '|')) {
                $structures[] = 'pipe_delimited';
            } else {
                $structures[] = 'unknown';
            }
        }

        // Store the most common structure
        $structure_counts = array_count_values($structures);
        arsort($structure_counts);
        $detected_structure = key($structure_counts);

        set_transient('wbe_wootour_format', $detected_structure, DAY_IN_SECONDS);
    }

    /**
     * Get availability data for a product
     * 
     * @param int $product_id Product ID
     * @return Availability Availability model
     * @throws WootourException If product not found or data invalid
     */
    public function getAvailability(int $product_id): Availability
    {
        error_log('[WBE WootourRepository] Getting availability for product #' . $product_id);

        $meta_key = '_wootour_availability';
        $raw_data = get_post_meta($product_id, $meta_key, true);

        $availability_data = [];

        if (!empty($raw_data)) {
            error_log('[WBE WootourRepository] Raw data from DB: ' . $raw_data);

            try {
                // Première désérialisation
                $first_pass = @unserialize($raw_data);

                if (is_string($first_pass)) {
                    // Deuxième désérialisation
                    $second_pass = @unserialize($first_pass);

                    if (is_array($second_pass)) {
                        $availability_data = $second_pass;
                        error_log('[WBE WootourRepository] Double unserialized: ' . print_r($availability_data, true));
                    } else {
                        error_log('[WBE WootourRepository] Second unserialize failed or not array');
                    }
                } elseif (is_array($first_pass)) {
                    // Si déjà un tableau (simple sérialisation)
                    $availability_data = $first_pass;
                    error_log('[WBE WootourRepository] Single unserialized: ' . print_r($availability_data, true));
                }
            } catch (\Exception $e) {
                error_log('[WBE WootourRepository] Unserialize error: ' . $e->getMessage());
            }
        } else {
            error_log('[WBE WootourRepository] No availability data found for product #' . $product_id);
        }

        // Nettoyer les données
        unset($availability_data['raw_data'], $availability_data['product_id']);

        // Créer l'objet Availability
        $availability = new Availability($availability_data);
        $availability = $availability->withProductId($product_id);

        error_log('[WBE WootourRepository] Final Availability object: ' . print_r($availability->toArray(), true));

        return $availability;
    }

    /**
     * Update availability for a product
     * 
     * @param int $product_id Product ID
     * @param array $changes Array of changes (only filled fields will be updated)
     * @return bool True on success
     * @throws WootourException If update fails
     */
    public function updateAvailability(int $product_id, array $availability_data): bool
    {
        error_log('[WBE WootourRepository] === START updateAvailability ===');

        try {
            // 1. Mettre à jour les métadonnées WooTours (timestamps)
            $this->updateWootourTimestampMeta($product_id, $availability_data);

            // 2. Mettre à jour _wootour_availability (double sérialisé)
            $meta_key = '_wootour_availability';
            $wootour_data = $this->formatForWootour($availability_data, $product_id);

            $result = update_post_meta($product_id, $meta_key, $wootour_data);

            // 3. Vider les caches
            $this->clearAllCaches($product_id);

            error_log('[WBE WootourRepository] === END updateAvailability ===');

            return $result !== false;
        } catch (\Exception $e) {
            error_log('[WBE WootourRepository] ERROR: ' . $e->getMessage());
            return false;
        }
    }

    /**
     * Formater les dates pour WooTours (timestamp UNIX)
     */
    private function formatForWootour(array $availability_data, int $product_id): string
    {
        error_log('[WBE WootourRepository] Formatting for Wootour with UNIX timestamps');

        // Convertir les dates en timestamps UNIX
        $start_timestamp = !empty($availability_data['start_date'])
            ? strtotime($availability_data['start_date'])
            : '';

        $end_timestamp = !empty($availability_data['end_date'])
            ? strtotime($availability_data['end_date'])
            : '';

        // Format attendu par WooTours
        $clean_data = [
            'start_date' => $availability_data['start_date'] ?? '', // Garder aussi le format string
            'end_date' => $availability_data['end_date'] ?? '',
            'weekdays' => $availability_data['weekdays'] ?? [],
            'exclusions' => $availability_data['exclusions'] ?? [],
            'specific' => $availability_data['specific'] ?? [],
            'raw_data' => $availability_data['raw_data'] ?? [],
            'product_id' => $product_id,
        ];

        error_log('[WBE WootourRepository] Clean data: ' . print_r($clean_data, true));

        //  DOUBLE SÉRIALISATION
        $serialized_array = serialize($clean_data);
        $double_serialized = serialize($serialized_array);

        return $double_serialized;
    }

    /**
     * Mettre à jour les métadonnées WooTours (timestamp UNIX)
     */
    private function updateWootourTimestampMeta(int $product_id, array $availability_data): void
    {
        error_log('[WBE WootourRepository] Updating WooTours timestamp meta for product #' . $product_id);

        // Convertir les dates en timestamps UNIX
        $start_timestamp = !empty($availability_data['start_date'])
            ? strtotime($availability_data['start_date'])
            : '';

        $end_timestamp = !empty($availability_data['end_date'])
            ? strtotime($availability_data['end_date'])
            : '';

        //  METTRE À JOUR LES MÉTADONNÉES WOO_TOURS RÉELLES
        // wt_start - timestamp UNIX (début)
        if (!empty($start_timestamp)) {
            update_post_meta($product_id, 'wt_start', $start_timestamp);
            error_log('[WBE WootourRepository] Updated wt_start: ' . $start_timestamp . ' (' . date('Y-m-d', $start_timestamp) . ')');
        }

        // wt_expired - timestamp UNIX (expiration)
        if (!empty($end_timestamp)) {
            update_post_meta($product_id, 'wt_expired', $end_timestamp);
            error_log('[WBE WootourRepository] Updated wt_expired: ' . $end_timestamp . ' (' . date('Y-m-d', $end_timestamp) . ')');
        }

        // wt_weekday - jours de la semaine (2=monday, 3=tuesday, etc.)
        if (!empty($availability_data['weekdays'])) {
            // Convertir nos jours (0=dimanche, 1=lundi) en format WooTours (2=lundi, 3=mardi)
            $wootour_weekdays = [];
            foreach ($availability_data['weekdays'] as $day) {
                // 0=dimanche → 1 (WooTours), 1=lundi → 2 (WooTours), etc.
                $wootour_day = $day + 1;
                if ($wootour_day == 7) $wootour_day = 1; // Samedi(6) → 7, mais WooTours dimanche=1
                $wootour_weekdays[] = $wootour_day;
            }

            update_post_meta($product_id, 'wt_weekday', $wootour_weekdays);
            error_log('[WBE WootourRepository] Updated wt_weekday: ' . print_r($wootour_weekdays, true));
        }

        // wt_disabledate - timestamps UNIX pour dates désactivées
        if (!empty($availability_data['exclusions'])) {
            $disabled_timestamps = [];
            foreach ($availability_data['exclusions'] as $date) {
                $timestamp = strtotime($date);
                if ($timestamp) {
                    $disabled_timestamps[] = $timestamp;
                }
            }
            update_post_meta($product_id, 'wt_disabledate', $disabled_timestamps);
            error_log('[WBE WootourRepository] Updated wt_disabledate: ' . print_r($disabled_timestamps, true));
        }

        // wt_customdate - timestamps UNIX pour dates spécifiques
        if (!empty($availability_data['specific'])) {
            $custom_timestamps = [];
            foreach ($availability_data['specific'] as $date) {
                $timestamp = strtotime($date);
                if ($timestamp) {
                    $custom_timestamps[] = $timestamp;
                }
            }
            update_post_meta($product_id, 'wt_customdate', $custom_timestamps);
            error_log('[WBE WootourRepository] Updated wt_customdate: ' . print_r($custom_timestamps, true));
        }

        // Mettre à jour aussi les métadonnées au format string pour compatibilité
        if (!empty($availability_data['start_date'])) {
            update_post_meta($product_id, '_tour_start_date', $availability_data['start_date']);
        }

        if (!empty($availability_data['end_date'])) {
            update_post_meta($product_id, '_tour_end_date', $availability_data['end_date']);
            //  TRÈS IMPORTANT : C'est probablement ça que WooTours affiche !
            update_post_meta($product_id, 'expired_date', $availability_data['end_date']);
        }
    }

    /**
     * Mettre à jour les métadonnées individuelles
     */
    private function updateIndividualMeta(int $product_id, array $availability_data): void
    {
        // Ces clés semblent être utilisées par WooTours aussi
        if (!empty($availability_data['start_date'])) {
            update_post_meta($product_id, '_tour_start_date', $availability_data['start_date']);
        }

        if (!empty($availability_data['end_date'])) {
            update_post_meta($product_id, '_tour_end_date', $availability_data['end_date']);
        }

        // wt_customdate et wt_disabledate semblent être vides mais pourraient être utilisées
        if (!empty($availability_data['specific'])) {
            update_post_meta($product_id, 'wt_customdate', maybe_serialize($availability_data['specific']));
        }

        if (!empty($availability_data['exclusions'])) {
            update_post_meta($product_id, 'wt_disabledate', maybe_serialize($availability_data['exclusions']));
        }
    }

    /**
     * Get raw availability data from database
     */
    private function get_raw_availability(int $product_id): mixed
    {
        $raw = get_post_meta($product_id, $this->meta_key, true);

        // If empty and we have fallback keys, try them
        if (empty($raw) && !$this->structure_detected) {
            foreach (Constants::META_KEYS as $key) {
                if ($key !== $this->meta_key) {
                    $raw = get_post_meta($product_id, $key, true);
                    if (!empty($raw)) {
                        $this->meta_key = $key;
                        break;
                    }
                }
            }
        }

        return $raw;
    }

    /**
     * Parse availability data based on detected format
     */
    private function parse_availability_data(mixed $raw_data): array
    {
        if (empty($raw_data)) {
            return Constants::DEFAULT_AVAILABILITY;
        }

        $format = get_transient('wbe_wootour_format') ?: 'auto';

        switch ($format) {
            case 'serialized':
                $parsed = maybe_unserialize($raw_data);
                break;

            case 'json':
                $parsed = json_decode($raw_data, true);
                if (json_last_error() !== JSON_ERROR_NONE) {
                    throw new \Exception('JSON decode error: ' . json_last_error_msg());
                }
                break;

            case 'pipe_delimited':
                $parsed = $this->parse_pipe_delimited($raw_data);
                break;

            default:
                // Try to auto-detect
                $parsed = $this->auto_detect_format($raw_data);
        }

        // Ensure we have an array
        if (!is_array($parsed)) {
            $parsed = ['raw' => $raw_data];
        }

        // Normalize to our standard structure
        return $this->normalize_availability_array($parsed);
    }

    /**
     * Parse pipe-delimited format (common in older plugins)
     */
    private function parse_pipe_delimited(string $data): array
    {
        $parts = explode('|', $data);
        $result = Constants::DEFAULT_AVAILABILITY;

        // Very basic parsing - will need adjustment based on actual samples
        if (isset($parts[0])) $result['start_date'] = $parts[0];
        if (isset($parts[1])) $result['end_date'] = $parts[1];
        if (isset($parts[2])) $result['weekdays'] = explode(',', $parts[2]);

        return $result;
    }

    /**
     * Clear all caches for a product
     */
    public function clearAllCaches(int $product_id): void
    {
        error_log('[WBE WootourRepository] Clearing all caches for product #' . $product_id);

        // 1. Cache des métadonnées WordPress
        wp_cache_delete($product_id, 'post_meta');

        // 2. Cache de l'objet produit
        clean_post_cache($product_id);

        // 3. Cache WooCommerce
        if (function_exists('wc_delete_product_transients')) {
            wc_delete_product_transients($product_id);
        }

        // 4. Cache spécifique au produit
        $cache_keys = [
            'product_' . $product_id,
            'woocommerce_product_' . $product_id,
            'wc_product_' . $product_id,
            'tour_' . $product_id,
            'wootour_' . $product_id,
        ];

        foreach ($cache_keys as $key) {
            wp_cache_delete($key, 'products');
            wp_cache_delete($key, 'woocommerce');
        }

        // 5. Transients WooCommerce
        delete_transient('wc_product_' . $product_id);
        delete_transient('woocommerce_product_' . $product_id);

        // 6. Cache object product
        $product = wc_get_product($product_id);
        if ($product) {
            $product->set_date_modified(current_time('timestamp', true));
            $product->save();
        }

        // 7. Cache de session utilisateur
        if (class_exists('WC_Session_Handler')) {
            $session = new \WC_Session_Handler();
            $session->forget_session();
        }

        error_log('[WBE WootourRepository] All caches cleared for product #' . $product_id);
    }

    /**
     * Auto-detect data format
     */
    private function auto_detect_format(mixed $data): array
    {
        // Try serialized
        if (is_string($data) && $this->is_serialized($data)) {
            $decoded = maybe_unserialize($data);
            if (is_array($decoded)) {
                set_transient('wbe_wootour_format', 'serialized', HOUR_IN_SECONDS);
                return $decoded;
            }
        }

        // Try JSON
        if (is_string($data) && $this->is_json($data)) {
            $decoded = json_decode($data, true);
            if (is_array($decoded)) {
                set_transient('wbe_wootour_format', 'json', HOUR_IN_SECONDS);
                return $decoded;
            }
        }

        // Default: return as-is in 'raw' key
        return ['raw' => $data];
    }

    /**
     * Normalize availability array to standard structure
     */
    private function normalize_availability_array(array $data): array
    {
        $normalized = Constants::DEFAULT_AVAILABILITY;

        // Map possible field names to our standard names
        $field_mapping = [
            'start_date'    => ['start_date', 'start', 'date_start', 'from_date'],
            'end_date'      => ['end_date', 'end', 'date_end', 'to_date'],
            'weekdays'      => ['weekdays', 'days', 'week_days', 'available_days'],
            'exclusions'    => ['exclusions', 'excluded_dates', 'blackout_dates'],
            'specific'      => ['specific', 'specific_dates', 'dates'],
        ];

        foreach ($field_mapping as $standard_field => $possible_fields) {
            foreach ($possible_fields as $possible_field) {
                if (isset($data[$possible_field])) {
                    $normalized[$standard_field] = $data[$possible_field];
                    break;
                }
            }
        }

        // Ensure arrays are actually arrays
        foreach (['weekdays', 'exclusions', 'specific'] as $array_field) {
            if (!is_array($normalized[$array_field])) {
                if (is_string($normalized[$array_field])) {
                    $normalized[$array_field] = array_map('trim', explode(',', $normalized[$array_field]));
                } else {
                    $normalized[$array_field] = [];
                }
            }
        }

        return $normalized;
    }

    /**
     * Merge existing data with changes (empty fields don't overwrite)
     */
    private function mergeAvailabilityData(array $existing, array $changes): array
    {
        $merged = $existing;

        foreach ($changes as $field => $value) {
            // Skip if field is empty (null, empty string, or empty array)
            if ($this->isEmptyValue($value)) {
                continue;
            }

            // Special handling for date fields
            if (in_array($field, ['start_date', 'end_date'])) {
                $merged[$field] = $this->sanitize_date($value);
            }
            // Special handling for array fields
            elseif (in_array($field, ['weekdays', 'exclusions', 'specific'])) {
                $merged[$field] = $this->merge_array_field($existing[$field] ?? [], $value);
            } else {
                $merged[$field] = $value;
            }
        }

        return $merged;
    }

    /**
     * Merge array fields (add to existing, don't replace)
     */
    private function merge_array_field(array $existing, $new): array
    {
        if (!is_array($new)) {
            $new = [$new];
        }

        // Add new items to existing array, remove duplicates
        return array_unique(array_merge($existing, $new));
    }

    /**
     * Format data for Wootour storage
     */
    private function format_for_wootour(array $data): mixed
    {
        $format = get_transient('wbe_wootour_format') ?: 'serialized';

        switch ($format) {
            case 'json':
                return json_encode($data, JSON_UNESCAPED_UNICODE);

            case 'pipe_delimited':
                return $this->format_pipe_delimited($data);

            case 'serialized':
            default:
                return serialize($data);
        }
    }

    /**
     * Format as pipe-delimited string
     */
    private function format_pipe_delimited(array $data): string
    {
        $parts = [
            $data['start_date'] ?? '',
            $data['end_date'] ?? '',
            is_array($data['weekdays'] ?? []) ? implode(',', $data['weekdays']) : '',
        ];

        return implode('|', $parts);
    }

    /**
     * Check if value is considered empty for merging purposes
     */
    private function isEmptyValue($value): bool
    {
        if (is_null($value)) {
            return true;
        }

        if (is_string($value) && trim($value) === '') {
            return true;
        }

        if (is_array($value) && empty(array_filter($value))) {
            return true;
        }

        return false;
    }

    /**
     * Sanitize date value
     */
    private function sanitize_date($date): string
    {
        if (empty($date)) {
            return '';
        }

        // Try to parse and format as Y-m-d
        $timestamp = strtotime($date);
        if ($timestamp === false) {
            return '';
        }

        return date('Y-m-d', $timestamp);
    }

    /**
     * Check if string is serialized data
     */
    private function is_serialized(string $data): bool
    {
        return is_serialized($data);
    }

    /**
     * Check if string is valid JSON
     */
    private function is_json(string $data): bool
    {
        json_decode($data);
        return json_last_error() === JSON_ERROR_NONE;
    }

    /**
     * Clear cache for a product
     */
    public function clear_product_cache(int $product_id = 0): void
    {
        if ($product_id > 0) {
            $cache_key = 'availability_' . $product_id;
            unset($this->availability_cache[$cache_key]);
        } else {
            $this->availability_cache = [];
        }

        // Clear WordPress object cache
        wp_cache_delete($product_id, 'post_meta');
    }

    /**
     * Get sample data for analysis
     */
    private function get_sample_data(string $meta_key): array
    {
        global $wpdb;

        return $wpdb->get_results($wpdb->prepare(
            "SELECT post_id, LEFT(meta_value, 100) as sample 
            FROM {$wpdb->postmeta} 
            WHERE meta_key = %s 
            AND meta_value IS NOT NULL 
            AND meta_value != '' 
            LIMIT 3",
            $meta_key
        ));
    }

    /**
     * Get detected meta key
     */
    public function getMetaKey(): string
    {
        return $this->meta_key;
    }

    /**
     * Check if structure was detected
     */
    public function isStructureDetected(): bool
    {
        return $this->structure_detected;
    }

    /**
     * Get Wootour version if available
     */
    public function getWootourVersion(): ?string
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
     * Test connection to Wootour
     */
    public function testConnection(): array
    {
        $result = [
            'success' => false,
            'meta_key' => $this->meta_key,
            'structure_detected' => $this->structure_detected,
            'wootour_version' => $this->getWootourVersion(),
            'sample_products' => 0,
            'issues' => [],
        ];

        try {
            // Find a product with Wootour data
            global $wpdb;

            $sample_product_id = $wpdb->get_var($wpdb->prepare(
                "SELECT post_id FROM {$wpdb->postmeta} 
                WHERE meta_key = %s 
                LIMIT 1",
                $this->meta_key
            ));

            if ($sample_product_id) {
                $result['sample_products'] = 1;

                // Try to read data
                $availability = $this->getAvailability((int) $sample_product_id);

                $result['success'] = true;
                $result['sample_data_structure'] = array_keys($availability->toArray());
            } else {
                $result['issues'][] = 'No products found with Wootour data';
            }
        } catch (\Exception $e) {
            $result['issues'][] = $e->getMessage();
        }

        return $result;
    }
}
