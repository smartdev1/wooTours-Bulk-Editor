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
            error_log('[WBE WootourRepository] Raw data from DB: ' . substr($raw_data, 0, 500) . '...');

            try {
                // Première désérialisation
                $first_pass = @unserialize($raw_data);

                if (is_string($first_pass)) {
                    // Deuxième désérialisation
                    $second_pass = @unserialize($first_pass);

                    if (is_array($second_pass)) {
                        $availability_data = $second_pass;
                        error_log('[WBE WootourRepository] Double unserialized successfully');
                    }
                } elseif (is_array($first_pass)) {
                    $availability_data = $first_pass;
                    error_log('[WBE WootourRepository] Single unserialized successfully');
                }
            } catch (\Exception $e) {
                error_log('[WBE WootourRepository] Unserialize error: ' . $e->getMessage());
            }
        }

        // ⚠️ DEBUG : Vérifier le contenu avant nettoyage
        error_log('[WBE WootourRepository] Before cleanup - specific: ' . print_r($availability_data['specific'] ?? 'NOT SET', true));
        error_log('[WBE WootourRepository] Before cleanup - exclusions: ' . print_r($availability_data['exclusions'] ?? 'NOT SET', true));

        // Nettoyer les données
        unset($availability_data['raw_data'], $availability_data['product_id']);

        // ⚠️ Si 'specific' ou 'exclusions' sont des timestamps, les convertir
        if (!empty($availability_data['specific'])) {
            $availability_data['specific'] = array_map(function ($timestamp) {
                if (is_numeric($timestamp)) {
                    return date('Y-m-d', (int) $timestamp);
                }
                return $timestamp;
            }, $availability_data['specific']);
            error_log('[WBE WootourRepository] Specific dates converted: ' . print_r($availability_data['specific'], true));
        }

        if (!empty($availability_data['exclusions'])) {
            $availability_data['exclusions'] = array_map(function ($timestamp) {
                if (is_numeric($timestamp)) {
                    return date('Y-m-d', (int) $timestamp);
                }
                return $timestamp;
            }, $availability_data['exclusions']);
            error_log('[WBE WootourRepository] Exclusions converted: ' . print_r($availability_data['exclusions'], true));
        }

        if (!empty($availability_data['specific'])) {
            $availability_data['specific'] = array_map(function ($timestamp) {
                if (is_numeric($timestamp)) {
                    return date('Y-m-d', (int) $timestamp);
                }
                return $timestamp;
            }, $availability_data['specific']);
            error_log('[WBE WootourRepository] Specific dates converted: ' . print_r($availability_data['specific'], true));
        }

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
        error_log('[WBE WootourRepository] Product ID: ' . $product_id);
        error_log('[WBE WootourRepository] Availability data received: ' . print_r($availability_data, true));

        if (isset($availability_data['specific'])) {
            error_log('[WBE WootourRepository] ✅ SPECIFIC DATES PRESENT: ' . count($availability_data['specific']) . ' date(s)');
            error_log('[WBE WootourRepository] Specific dates: ' . print_r($availability_data['specific'], true));
        } else {
            error_log('[WBE WootourRepository] ❌ SPECIFIC DATES NOT IN DATA');
        }

        try {
            // 1. Mettre à jour les métadonnées WooTours (timestamps)
            $meta_updated = $this->updateWootourTimestampMeta($product_id, $availability_data);

            if (!$meta_updated) {
                error_log('[WBE WootourRepository] ERROR: Failed to update timestamp meta');
                return false;
            }

            // 2. Mettre à jour _wootour_availability (double sérialisé)
            $meta_key = '_wootour_availability';
            $wootour_data = $this->formatForWootour($availability_data, $product_id);

            // CORRECTION : update_post_meta() retourne false si la valeur n'a pas changé
            // Ce n'est pas une erreur, donc on vérifie d'abord si la sauvegarde est nécessaire
            $existing_data = get_post_meta($product_id, $meta_key, true);

            // Si les données existent déjà et sont identiques, considérer comme un succès
            if ($existing_data === $wootour_data) {
                error_log('[WBE WootourRepository] Data unchanged, considering as success');
                $result = true;
            } else {
                $result = update_post_meta($product_id, $meta_key, $wootour_data);

                // update_post_meta() peut retourner false même en cas de succès si la valeur était vide
                if ($result === false) {
                    // Vérifier si la valeur a été sauvegardée
                    $new_data = get_post_meta($product_id, $meta_key, true);
                    $result = ($new_data === $wootour_data);

                    if ($result) {
                        error_log('[WBE WootourRepository] Data saved despite false return');
                    } else {
                        error_log('[WBE WootourRepository] ERROR: Failed to save _wootour_availability');
                        return false;
                    }
                }
            }

            // 3. Vider les caches
            $this->clearAllCaches($product_id);

            error_log('[WBE WootourRepository] === END updateAvailability (SUCCESS) ===');

            return true; // Toujours retourner true si on arrive ici
        } catch (\Exception $e) {
            error_log('[WBE WootourRepository] EXCEPTION: ' . $e->getMessage());
            return false;
        }
    }

    private function formatForWootour(array $availability_data, int $product_id): string
    {
        error_log('[WBE WootourRepository] Formatting for Wootour with UNIX timestamps');

        // ⚠️ IMPORTANT : Convertir AUSSI les exclusions et specific en timestamps
        $exclusions_timestamps = [];
        if (!empty($availability_data['exclusions'])) {
            foreach ($availability_data['exclusions'] as $date) {
                $timestamp = is_numeric($date) ? (int) $date : strtotime($date);
                if ($timestamp !== false) {
                    $exclusions_timestamps[] = $timestamp;
                }
            }
        }

        $specific_timestamps = [];
        if (!empty($availability_data['specific'])) {
            foreach ($availability_data['specific'] as $date) {
                $timestamp = is_numeric($date) ? (int) $date : strtotime($date);
                if ($timestamp !== false) {
                    $specific_timestamps[] = $timestamp;
                }
            }
        }

        // Convertir les dates en timestamps UNIX
        $start_timestamp = !empty($availability_data['start_date'])
            ? strtotime($availability_data['start_date'])
            : '';

        $end_timestamp = !empty($availability_data['end_date'])
            ? strtotime($availability_data['end_date'])
            : '';

        // Format attendu par WooTours
        $clean_data = [
            'start_date' => $start_timestamp,
            'end_date' => $end_timestamp,
            'weekdays' => $availability_data['weekdays'] ?? [],
            'exclusions' => $exclusions_timestamps,
            'specific' => $specific_timestamps,
            'raw_data' => $availability_data['raw_data'] ?? [],
            'product_id' => $product_id,
        ];

        error_log('[WBE WootourRepository] Clean data (timestamps): ' . print_r($clean_data, true));

        // DOUBLE SÉRIALISATION
        $serialized_array = serialize($clean_data);
        $double_serialized = serialize($serialized_array);

        return $double_serialized;
    }

    /**
     * Mettre à jour les métadonnées WooTours avec structure de champs indexés
     * 
     * ⚠️ MODIFICATION COMPLÈTE : Structure de champs indexés pour compatibilité WooTours
     */
    private function updateWootourTimestampMeta(int $product_id, array $availability_data): bool
    {
        error_log('');
        error_log('████████████████████████████████████████');
        error_log('🔄 updateWootourTimestampMeta() DÉBUT');
        error_log('████████████████████████████████████████');
        error_log('Product ID: ' . $product_id);
        error_log('Data received: ' . print_r($availability_data, true));
        error_log('');

        // === 1. RÉCUPÉRATION DES DONNÉES EXISTANTES ===
        error_log('📥 Récupération des données existantes...');

        // Dates d'exclusion existantes
        $existing_exclusions = $this->getExistingDates($product_id, 'wt_disable_book');
        error_log('  wt_disable_book existant: ' . count($existing_exclusions) . ' date(s)');
        if (!empty($existing_exclusions)) {
            error_log('  Existing exclusions: ' . print_r($existing_exclusions, true));
        }

        // Dates spécifiques existantes
        $existing_specific = $this->getExistingDates($product_id, 'wt_customdate');
        error_log('  wt_customdate existant: ' . count($existing_specific) . ' date(s)');
        if (!empty($existing_specific)) {
            error_log('  Existing specific: ' . print_r($existing_specific, true));
        }

        error_log('');

        // === 2. TRAITEMENT DES DATES D'EXCLUSION ===
        if (isset($availability_data['exclusions']) && is_array($availability_data['exclusions'])) {
            error_log('═══════════════════════════════════════');
            error_log('🔴 TRAITEMENT DES EXCLUSIONS');
            error_log('═══════════════════════════════════════');
            error_log('  Nouvelles exclusions: ' . print_r($availability_data['exclusions'], true));

            // Fusionner les dates (éviter les doublons)
            $all_exclusions = array_unique(array_merge($existing_exclusions, $availability_data['exclusions']));
            error_log('  Fusion: ' . count($existing_exclusions) . ' existant + ' .
                count($availability_data['exclusions']) . ' nouvelles = ' .
                count($all_exclusions) . ' total');

            // Sauvegarder chaque date dans un champ séparé (structure indexée)
            $this->saveIndexedDates($product_id, 'wt_disable_book', $all_exclusions);

            // Sauvegarder aussi dans wt_disabledate avec structure indexée
            $this->saveIndexedDates($product_id, 'wt_disabledate', $all_exclusions, true);
        } else {
            error_log('⏭️  Exclusions: PAS DANS availability_data ou pas un tableau');
            error_log('  Type: ' . gettype($availability_data['exclusions'] ?? 'not set'));
        }

        // === 3. TRAITEMENT DES DATES SPÉCIFIQUES ===
        error_log('');
        error_log('═══════════════════════════════════════');
        error_log('🟢 TRAITEMENT DES DATES SPÉCIFIQUES');
        error_log('═══════════════════════════════════════');

        if (isset($availability_data['specific'])) {
            error_log('  ✅ specific IS SET');
            error_log('  Type: ' . gettype($availability_data['specific']));
            error_log('  Content: ' . print_r($availability_data['specific'], true));

            if (is_array($availability_data['specific'])) {
                error_log('  ✅ specific IS ARRAY with ' . count($availability_data['specific']) . ' element(s)');

                if (!empty($availability_data['specific'])) {
                    error_log('  ✅ specific IS NOT EMPTY');

                    // Fusionner les dates (éviter les doublons)
                    $all_specific = array_unique(array_merge($existing_specific, $availability_data['specific']));
                    error_log('  Fusion: ' . count($existing_specific) . ' existant + ' .
                        count($availability_data['specific']) . ' nouvelles = ' .
                        count($all_specific) . ' total');
                    error_log('  All specific dates to save: ' . print_r($all_specific, true));

                    // ⚠️ IMPORTANT : Supprimer d'abord les anciennes entrées
                    delete_post_meta($product_id, 'wt_customdate');
                    error_log('  🧹 Deleted all existing wt_customdate entries');

                    // Sauvegarder chaque date dans un champ séparé (structure indexée)
                    $this->saveIndexedDates($product_id, 'wt_customdate', $all_specific, false);

                    // Vérifier immédiatement ce qui a été sauvegardé
                    $saved = $this->getExistingDates($product_id, 'wt_customdate');
                    error_log('  ✅ Verification: ' . count($saved) . ' date(s) saved');
                    error_log('  Saved dates: ' . print_r($saved, true));
                } else {
                    error_log('  ⚠️  specific IS EMPTY ARRAY');
                }
            } else {
                error_log('  ❌ specific IS NOT AN ARRAY');
            }
        } else {
            error_log('  ❌ specific NOT SET in availability_data');
            error_log('  Available keys: ' . print_r(array_keys($availability_data), true));
        }

        // === 4. MÉTADONNÉES PRINCIPALES (inchangées) ===
        error_log('');
        error_log('═══════════════════════════════════════');
        error_log('🔵 MÉTADONNÉES PRINCIPALES');
        error_log('═══════════════════════════════════════');

        // wt_start - timestamp UNIX (début)
        if (!empty($availability_data['start_date'])) {
            $start_timestamp = strtotime($availability_data['start_date']);
            update_post_meta($product_id, 'wt_start', $start_timestamp);
            update_post_meta($product_id, 'start_date', $start_timestamp);
            error_log('📝 wt_start: ' . $start_timestamp . ' (' . $availability_data['start_date'] . ')');
        }

        // wt_expired - timestamp UNIX (expiration)
        if (!empty($availability_data['end_date'])) {
            $end_timestamp = strtotime($availability_data['end_date']);
            update_post_meta($product_id, 'wt_expired', $end_timestamp);
            update_post_meta($product_id, 'expired_date', $end_timestamp);
            error_log('📝 wt_expired: ' . $end_timestamp . ' (' . $availability_data['end_date'] . ')');
        }

        // wt_weekday - jours de la semaine
        if (!empty($availability_data['weekdays']) && is_array($availability_data['weekdays'])) {
            $wootour_weekdays = [];
            foreach ($availability_data['weekdays'] as $day) {
                $wootour_day = $day + 1;
                if ($wootour_day == 7) $wootour_day = 1;
                $wootour_weekdays[] = $wootour_day;
            }
            update_post_meta($product_id, 'wt_weekday', $wootour_weekdays);
            error_log('📝 wt_weekday: ' . print_r($wootour_weekdays, true));
        }

        // === 5. MÉTADONNÉES COMPLÉMENTAIRES ===
        if (!empty($availability_data['start_date'])) {
            $start_timestamp = strtotime($availability_data['start_date']);

            if ($start_timestamp !== false) {
                update_post_meta($product_id, 'wt_start', $start_timestamp);
                update_post_meta($product_id, 'start_date', $start_timestamp);
            }
        }

        if (!empty($availability_data['end_date'])) {
            $end_timestamp = strtotime($availability_data['end_date']);

            if ($end_timestamp !== false) {
                update_post_meta($product_id, 'wt_expired', $end_timestamp);
                update_post_meta($product_id, 'expired_date', $end_timestamp);
            }
        }


        error_log('');
        error_log('████████████████████████████████████████');
        error_log('✅ updateWootourTimestampMeta() TERMINÉ');
        error_log('████████████████████████████████████████');

        return true;
    }

    /**
     * Sauvegarder des dates dans une structure indexée (exc_mb-field-0, exc_mb-field-1, etc.)
     * 
     * ⚠️ CORRECTION CRITIQUE : WooTour attend des TIMESTAMPS UNIX (integers), pas des strings
     */
    private function saveIndexedDates(int $product_id, string $meta_key, array $dates, bool $delete_first = false): void
    {
        if ($delete_first) {
            // Supprimer toutes les entrées existantes pour ce meta_key
            delete_post_meta($product_id, $meta_key);
            error_log('  🧹 ' . $meta_key . ': toutes les entrées supprimées');
        }

        if (empty($dates)) {
            error_log('  ⏭️  ' . $meta_key . ': aucune date à sauvegarder');
            return;
        }

        error_log('  💾 Sauvegarde de ' . count($dates) . ' date(s) dans ' . $meta_key . ':');

        foreach ($dates as $index => $date) {
            // ⚠️ CORRECTION CRITIQUE : Convertir en timestamp UNIX
            if (is_string($date)) {
                // Si c'est une string de date (YYYY-MM-DD ou MM/DD/YYYY)
                $timestamp = strtotime($date);

                if ($timestamp === false) {
                    error_log('    ❌ ERREUR: Date invalide "' . $date . '" - ignorée');
                    continue;
                }
            } elseif (is_numeric($date)) {
                // Si c'est déjà un timestamp, le garder
                $timestamp = (int) $date;
            } else {
                error_log('    ❌ ERREUR: Format de date inconnu - ignorée');
                continue;
            }

            // Créer la clé indexée
            $indexed_key = $meta_key . '[exc_mb-field-' . $index . ']';

            // ✅ Sauvegarder le TIMESTAMP (integer)
            $result = add_post_meta($product_id, $meta_key, $timestamp);

            // Log détaillé
            $log_line = '    ' . $indexed_key . ' = ' . $timestamp;
            $log_line .= ' (' . date('Y-m-d', $timestamp) . ')';
            $log_line .= $result ? ' ✅' : ' ❌ (échec ou doublon)';
            error_log($log_line);
        }

        error_log('  ✅ ' . count($dates) . ' date(s) sauvegardée(s) en timestamps');
    }

    /**
     * Récupérer les dates existantes pour un meta_key
     * ⚠️ CORRECTION : Les dates sont stockées en timestamps, on les retourne en format lisible
     */
    private function getExistingDates(int $product_id, string $meta_key): array
    {
        $timestamps = get_post_meta($product_id, $meta_key, false);

        if (empty($timestamps)) {
            return [];
        }

        $dates = [];

        foreach ($timestamps as $timestamp) {
            if (empty($timestamp)) {
                continue;
            }

            // Si c'est un timestamp, le convertir en date
            if (is_numeric($timestamp)) {
                $dates[] = date('Y-m-d', (int) $timestamp);
            } else {
                // Si c'est déjà une string, la garder mais la normaliser
                $ts = strtotime($timestamp);
                if ($ts !== false) {
                    $dates[] = date('Y-m-d', $ts);
                }
            }
        }

        return array_unique(array_filter($dates));
    }

    /**
     * DEBUG : Voir ce qui existe vraiment dans la base
     */
    private function debugExistingData(int $product_id): void
    {
        error_log('');
        error_log('🔍 DEBUG STRUCTURE POUR PRODUIT #' . $product_id);
        error_log('===========================================');

        $meta_keys = ['wt_disable_book', 'wt_disabledate', 'wt_customdate'];

        foreach ($meta_keys as $key) {
            $values = get_post_meta($product_id, $key, false);
            error_log($key . ':');
            foreach ($values as $index => $value) {
                error_log('  [' . $index . '] = ' . $value .
                    ' (type: ' . gettype($value) .
                    ', is_numeric: ' . (is_numeric($value) ? 'yes' : 'no') . ')');
            }
        }
    }

    /**
     * Récupérer les dates indexées pour affichage/debug
     */
    public function getIndexedDates(int $product_id, string $meta_key): array
    {
        global $wpdb;

        $results = $wpdb->get_results($wpdb->prepare(
            "SELECT meta_value 
        FROM {$wpdb->postmeta} 
        WHERE post_id = %d 
        AND meta_key = %s 
        ORDER BY meta_id",
            $product_id,
            $meta_key
        ), ARRAY_A);

        $dates = [];
        $index = 0;

        foreach ($results as $row) {
            $dates['exc_mb-field-' . $index] = $row['meta_value'];
            $index++;
        }

        return $dates;
    }

    /**
     * Vérifier la structure des données sauvegardées
     */
    public function verifySavedStructure(int $product_id): array
    {
        error_log('');
        error_log('🔍 VÉRIFICATION STRUCTURE POUR PRODUIT #' . $product_id);
        error_log('');

        $structure = [
            'wt_disable_book' => $this->getIndexedDates($product_id, 'wt_disable_book'),
            'wt_disabledate' => $this->getIndexedDates($product_id, 'wt_disabledate'),
            'wt_customdate' => $this->getIndexedDates($product_id, 'wt_customdate'),
            'meta_keys' => [],
        ];

        // Récupérer toutes les meta_keys pour ce produit
        global $wpdb;
        $all_meta = $wpdb->get_results($wpdb->prepare(
            "SELECT meta_key, meta_value 
        FROM {$wpdb->postmeta} 
        WHERE post_id = %d 
        AND (meta_key LIKE '%wt_%' OR meta_key LIKE '%tour%' OR meta_key LIKE '%date%')
        ORDER BY meta_key, meta_id",
            $product_id
        ), ARRAY_A);

        foreach ($all_meta as $meta) {
            $structure['meta_keys'][$meta['meta_key']][] = $meta['meta_value'];
        }

        error_log('Structure vérifiée:');
        error_log(print_r($structure, true));

        return $structure;
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
