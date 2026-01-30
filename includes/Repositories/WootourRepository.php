<?php

/**
 * Wootour Bulk Editor - Wootour Repository
 * 
 * MODIFICATION MAJEURE : Utilisation de timestamps UNIQUES au lieu de tableaux
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
     * Mettre à jour les métadonnées WooTours (timestamp UNIX unique)
     * 
     * ⚠️ MODIFICATION MAJEURE : Un seul timestamp par meta key, pas de tableaux
     */
    private function updateWootourTimestampMeta(int $product_id, array $availability_data): void
    {
        error_log('');
        error_log('████████████████████████████████████████');
        error_log('🔵 updateWootourTimestampMeta() DÉBUT');
        error_log('████████████████████████████████████████');
        error_log('Product ID: ' . $product_id);
        error_log('Availability Data reçue:');
        error_log(print_r($availability_data, true));
        error_log('');

        // Convertir les dates en timestamps UNIX
        $start_timestamp = !empty($availability_data['start_date'])
            ? strtotime($availability_data['start_date'])
            : '';

        $end_timestamp = !empty($availability_data['end_date'])
            ? strtotime($availability_data['end_date'])
            : '';

        error_log('🕐 Timestamps calculés:');
        error_log('  start_timestamp: ' . ($start_timestamp ?: 'VIDE'));
        error_log('  end_timestamp: ' . ($end_timestamp ?: 'VIDE'));
        error_log('');

        // === MÉTADONNÉES PRINCIPALES (timestamp unique) ===

        // wt_start - timestamp UNIX (début)
        if (!empty($start_timestamp)) {
            error_log('📝 Tentative update wt_start...');
            $result = update_post_meta($product_id, 'wt_start', $start_timestamp);
            error_log('  Résultat: ' . ($result ? 'SUCCESS' : 'FAILED/UNCHANGED'));
            error_log('  Valeur: ' . $start_timestamp . ' (' . date('Y-m-d', $start_timestamp) . ')');
        } else {
            error_log('⏭️  wt_start: IGNORÉ (pas de start_date)');
        }

        // wt_expired - timestamp UNIX (expiration)
        if (!empty($end_timestamp)) {
            error_log('📝 Tentative update wt_expired...');
            $result = update_post_meta($product_id, 'wt_expired', $end_timestamp);
            error_log('  Résultat: ' . ($result ? 'SUCCESS' : 'FAILED/UNCHANGED'));
            error_log('  Valeur: ' . $end_timestamp . ' (' . date('Y-m-d', $end_timestamp) . ')');
        } else {
            error_log('⏭️  wt_expired: IGNORÉ (pas de end_date)');
        }

        // wt_weekday - jours de la semaine (array autorisé pour ce champ)
        if (!empty($availability_data['weekdays'])) {
            error_log('📝 Tentative update wt_weekday...');

            // Convertir nos jours (0=dimanche, 1=lundi) en format WooTours (2=lundi, 3=mardi)
            $wootour_weekdays = [];
            foreach ($availability_data['weekdays'] as $day) {
                $wootour_day = $day + 1;
                if ($wootour_day == 7) $wootour_day = 1;
                $wootour_weekdays[] = $wootour_day;
            }

            $result = update_post_meta($product_id, 'wt_weekday', $wootour_weekdays);
            error_log('  Résultat: ' . ($result ? 'SUCCESS' : 'FAILED/UNCHANGED'));
            error_log('  Valeur: ' . print_r($wootour_weekdays, true));
        } else {
            error_log('⏭️  wt_weekday: IGNORÉ (pas de weekdays)');
        }

        error_log('');
        error_log('═══════════════════════════════════════');
        error_log('🔴 SECTION DATES D\'EXCLUSION');
        error_log('═══════════════════════════════════════');

        // === DATES D'EXCLUSION : UN SEUL TIMESTAMP ===

        // Vérifier ce qu'on a reçu
        if (isset($availability_data['exclusions'])) {
            error_log('📥 Exclusions reçues:');
            error_log('  Type: ' . gettype($availability_data['exclusions']));
            error_log('  Valeur: ' . print_r($availability_data['exclusions'], true));
            error_log('  Count: ' . (is_array($availability_data['exclusions']) ? count($availability_data['exclusions']) : 'N/A'));
        } else {
            error_log('❌ Exclusions: PAS DANS availability_data');
        }
        error_log('');

        // Nettoyer d'abord les anciennes valeurs multiples
        error_log('🧹 Nettoyage des anciennes métadonnées...');
        delete_post_meta($product_id, 'wt_disabledate');
        delete_post_meta($product_id, 'wt_disable_book');
        error_log('  ✅ wt_disabledate et wt_disable_book supprimés');
        error_log('');

        if (!empty($availability_data['exclusions'])) {
            error_log('📝 TRAITEMENT DES EXCLUSIONS...');

            // Prendre UNIQUEMENT la première date d'exclusion
            $first_exclusion = $availability_data['exclusions'][0];
            error_log('  Première exclusion: ' . $first_exclusion);

            $timestamp = strtotime($first_exclusion);
            error_log('  Timestamp: ' . $timestamp);

            if ($timestamp) {
                // wt_disabledate - UN SEUL timestamp
                error_log('');
                error_log('  ▶️  Mise à jour wt_disabledate...');
                $result = update_post_meta($product_id, 'wt_disabledate', $timestamp);
                error_log('    Résultat: ' . ($result ? '✅ SUCCESS' : '⚠️  FAILED/UNCHANGED'));
                error_log('    Valeur: ' . $timestamp . ' (' . $first_exclusion . ')');

                // Vérification immédiate
                $verify = get_post_meta($product_id, 'wt_disabledate', true);
                error_log('    Vérification: ' . ($verify ? $verify : 'VIDE'));
                error_log('');

                // wt_disable_book - Utiliser add_post_meta pour chaque date
                error_log('  ▶️  Ajout de toutes les exclusions dans wt_disable_book...');
                $count = 0;
                foreach ($availability_data['exclusions'] as $date) {
                    $ts = strtotime($date);
                    if ($ts) {
                        $result = add_post_meta($product_id, 'wt_disable_book', $ts);
                        error_log('    add_post_meta wt_disable_book: ' . $ts . ' (' . $date . ') → ' . ($result ? '✅' : '❌'));
                        $count++;
                    }
                }
                error_log('  ✅ ' . $count . ' date(s) ajoutée(s) à wt_disable_book');

                // Vérification immédiate
                $verify_all = get_post_meta($product_id, 'wt_disable_book', false);
                error_log('  Vérification wt_disable_book: ' . (is_array($verify_all) ? count($verify_all) . ' valeur(s)' : 'VIDE'));
            } else {
                error_log('  ❌ ÉCHEC: Impossible de convertir la date en timestamp');
            }
        } else {
            error_log('⏭️  Exclusions: VIDE ou ABSENT - Aucune mise à jour');
        }

        error_log('');
        error_log('═══════════════════════════════════════');
        error_log('🟢 SECTION DATES SPÉCIFIQUES');
        error_log('═══════════════════════════════════════');

        // === DATES SPÉCIFIQUES : UN SEUL TIMESTAMP ===

        // Vérifier ce qu'on a reçu
        if (isset($availability_data['specific'])) {
            error_log('📥 Dates spécifiques reçues:');
            error_log('  Type: ' . gettype($availability_data['specific']));
            error_log('  Valeur: ' . print_r($availability_data['specific'], true));
            error_log('  Count: ' . (is_array($availability_data['specific']) ? count($availability_data['specific']) : 'N/A'));
        } else {
            error_log('❌ Dates spécifiques: PAS DANS availability_data');
        }
        error_log('');

        // Nettoyer d'abord les anciennes valeurs
        error_log('🧹 Nettoyage wt_customdate...');
        delete_post_meta($product_id, 'wt_customdate');
        error_log('  ✅ wt_customdate supprimé');
        error_log('');

        if (!empty($availability_data['specific'])) {
            error_log('📝 TRAITEMENT DES DATES SPÉCIFIQUES...');

            // Prendre UNIQUEMENT la première date spécifique
            $first_specific = $availability_data['specific'][0];
            error_log('  Première date spécifique: ' . $first_specific);

            $timestamp = strtotime($first_specific);
            error_log('  Timestamp: ' . $timestamp);

            if ($timestamp) {
                // wt_customdate - UN SEUL timestamp
                error_log('');
                error_log('  ▶️  Mise à jour wt_customdate...');
                $result = update_post_meta($product_id, 'wt_customdate', $timestamp);
                error_log('    Résultat: ' . ($result ? '✅ SUCCESS' : '⚠️  FAILED/UNCHANGED'));
                error_log('    Valeur: ' . $timestamp . ' (' . $first_specific . ')');

                // Vérification immédiate
                $verify = get_post_meta($product_id, 'wt_customdate', true);
                error_log('    Vérification: ' . ($verify ? $verify . ' (' . date('Y-m-d', $verify) . ')' : 'VIDE'));
            } else {
                error_log('  ❌ ÉCHEC: Impossible de convertir la date en timestamp');
            }
        } else {
            error_log('⏭️  Dates spécifiques: VIDE ou ABSENT - Aucune mise à jour');
        }

        error_log('');
        error_log('═══════════════════════════════════════');
        error_log('🔵 MÉTADONNÉES COMPLÉMENTAIRES');
        error_log('═══════════════════════════════════════');

        // === MÉTADONNÉES COMPLÉMENTAIRES (format string pour compatibilité) ===

        if (!empty($availability_data['start_date'])) {
            error_log('📝 _tour_start_date: ' . $availability_data['start_date']);
            update_post_meta($product_id, '_tour_start_date', $availability_data['start_date']);
        }

        if (!empty($availability_data['end_date'])) {
            error_log('📝 _tour_end_date: ' . $availability_data['end_date']);
            update_post_meta($product_id, '_tour_end_date', $availability_data['end_date']);

            error_log('📝 expired_date: ' . $availability_data['end_date']);
            update_post_meta($product_id, 'expired_date', $availability_data['end_date']);
        }

        error_log('');
        error_log('████████████████████████████████████████');
        error_log('🔵 updateWootourTimestampMeta() FIN');
        error_log('████████████████████████████████████████');
        error_log('');
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
