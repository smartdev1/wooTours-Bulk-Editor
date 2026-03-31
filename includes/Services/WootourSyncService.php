<?php

namespace WBE\Services;

/**
 * Service de synchronisation WBE → Wootour
 * 
 * CORRECTION : Gère correctement les dates spécifiques pour l'affichage
 * 
 * VERSION CORRIGÉE - 2026-02-07
 * Changements principaux :
 * - sync_specific_dates() utilise maintenant add_post_meta() correctement
 * - Suppression propre des anciennes dates avant ajout
 * - Pas besoin de format exc_mb-field-X, WordPress gère ça automatiquement
 */
class WootourSyncService
{
    /**
     * Mapping des jours WBE → Wootour
     */
    private const DAY_MAPPING = [
        'sunday'    => 1,
        'monday'    => 2,
        'tuesday'   => 3,
        'wednesday' => 4,
        'thursday'  => 5,
        'friday'    => 6,
        'saturday'  => 7
    ];

    /**
     * Synchronise TOUTES les données vers Wootour
     */
    public static function sync_all(int $product_id, array $changes): bool
    {
        $success = true;

        if (isset($changes['start_date'])) {
            $result = self::sync_start_date($product_id, $changes['start_date']);
            $success = $result && $success;
        }

        if (isset($changes['end_date'])) {
            $result = self::sync_end_date($product_id, $changes['end_date']);
            $success = $result && $success;
        }

        if (isset($changes['available_days'])) {
            $result = self::sync_available_days($product_id, $changes['available_days']);
            $success = $result && $success;
        }

        if (isset($changes['unavailable_dates'])) {
            $result = self::sync_unavailable_dates($product_id, $changes['unavailable_dates']);
            $success = $result && $success;
        }

        if (isset($changes['specific_dates'])) {
            $result = self::sync_specific_dates($product_id, $changes['specific_dates']);
            $success = $result && $success;
        }

        // Reconstruire le cache Wootour après toutes les modifications
        if ($success) {
            self::sync_wootour_availability_cache($product_id);
        }

        return $success;
    }

    /**
     * Synchronise la date de début
     */
    public static function sync_start_date(int $product_id, string $date): bool
    {
        $timestamp = strtotime($date);

        if ($timestamp === false) {
            return false;
        }

        $success = true;

        // Wootour utilise plusieurs clés pour la même donnée (compatibilité)
        $success = update_post_meta($product_id, 'wt_start', $timestamp) !== false && $success;
        $success = update_post_meta($product_id, 'start_date', $timestamp) !== false && $success;
        $success = update_post_meta($product_id, 'eventstartdate', $date) !== false && $success;
        $success = update_post_meta($product_id, 'wp_event_start_date', $date) !== false && $success;
        $success = update_post_meta($product_id, '_start_date', $date) !== false && $success;

        if ($success && defined('WP_DEBUG') && WP_DEBUG) {
            error_log(sprintf(
                '[WBE Sync] Date de début synchronisée pour produit #%d : %s (timestamp: %d)',
                $product_id,
                $date,
                $timestamp
            ));
        }

        return $success;
    }

    /**
     * Synchronise la date de fin
     */
    public static function sync_end_date(int $product_id, string $date): bool
    {
        $timestamp = strtotime($date);

        if ($timestamp === false) {
            return false;
        }

        $success = true;

        $success = update_post_meta($product_id, 'wt_expired', $timestamp) !== false && $success;
        $success = update_post_meta($product_id, 'expired_date', $timestamp) !== false && $success;

        if ($success && defined('WP_DEBUG') && WP_DEBUG) {
            error_log(sprintf(
                '[WBE Sync] Date de fin synchronisée pour produit #%d : %s (timestamp: %d)',
                $product_id,
                $date,
                $timestamp
            ));
        }

        return $success;
    }

    /**
     * Synchronise les jours disponibles
     */
    public static function sync_available_days(int $product_id, array $days): bool
    {
        $wootour_days = self::convert_days_to_wootour($days);

        $success = update_post_meta($product_id, '_weekdays', $wootour_days) !== false;

        if ($success && defined('WP_DEBUG') && WP_DEBUG) {
            error_log(sprintf(
                '[WBE Sync] Jours disponibles synchronisés pour produit #%d : %s → %s',
                $product_id,
                implode(', ', $days),
                implode(', ', $wootour_days)
            ));
        }

        return $success;
    }

    /**
     * Synchronise les dates exclues
     * 
     * IMPORTANT : Utilise add_post_meta() pour créer plusieurs entrées
     */
    public static function sync_unavailable_dates(int $product_id, array $dates): bool
    {
        global $wpdb;

        // ÉTAPE 1 : Supprimer TOUTES les anciennes entrées wt_disabledate
        $wpdb->query($wpdb->prepare(
            "DELETE FROM $wpdb->postmeta 
             WHERE post_id = %d 
             AND meta_key = 'wt_disabledate'",
            $product_id
        ));

        if (empty($dates)) {
            return true;
        }

        $success = true;

        // ÉTAPE 2 : Ajouter chaque date comme nouvelle entrée
        foreach ($dates as $date) {
            $timestamp = strtotime($date);

            if ($timestamp === false) {
                continue;
            }

            // add_post_meta() avec $unique = false permet de créer plusieurs entrées
            $result = add_post_meta($product_id, 'wt_disabledate', $timestamp, false);

            if ($result === false) {
                $success = false;
            }
        }

        if ($success && defined('WP_DEBUG') && WP_DEBUG) {
            error_log(sprintf(
                '[WBE Sync] %d dates exclues synchronisées pour produit #%d',
                count($dates),
                $product_id
            ));
        }

        return $success;
    }

    /**
     * Synchronise les dates spécifiques
     * 
     * CORRECTION PRINCIPALE : 
     * - Wootour utilise wt_customdate comme champ répétable
     * - Chaque date est une entrée séparée avec add_post_meta()
     * - Le format exc_mb-field-X est géré automatiquement par le plugin Wootour
     * 
     * @param int $product_id
     * @param array $dates Format: ['2026-04-10' => [...], '2026-04-15' => [...]]
     *                      OU simplement: ['2026-04-10', '2026-04-15']
     * @return bool
     */
    public static function sync_specific_dates(int $product_id, $dates): bool
    {
        global $wpdb;

        // ÉTAPE 1 : Supprimer TOUTES les anciennes entrées wt_customdate
        $wpdb->query($wpdb->prepare(
            "DELETE FROM $wpdb->postmeta 
             WHERE post_id = %d 
             AND meta_key = 'wt_customdate'",
            $product_id
        ));

        // Si pas de dates, on a fini
        if (empty($dates)) {
            if (defined('WP_DEBUG') && WP_DEBUG) {
                error_log(sprintf(
                    '[WBE Sync] Dates spécifiques supprimées pour produit #%d',
                    $product_id
                ));
            }
            return true;
        }

        // Normaliser les dates en tableau simple
        $date_list = [];
        if (is_array($dates)) {
            foreach ($dates as $key => $value) {
                // Si c'est un tableau associatif ['2026-04-10' => [...]]
                if (is_numeric($key)) {
                    $date_list[] = $value;
                } else {
                    $date_list[] = $key;
                }
            }
        }

        $success = true;
        $count = 0;

        // ÉTAPE 2 : Ajouter chaque date comme entrée séparée
        foreach ($date_list as $date) {
            $timestamp = strtotime($date);

            if ($timestamp === false) {
                error_log("[WBE Sync] Date invalide ignorée: $date");
                continue;
            }

            // Utiliser add_post_meta() avec $unique = false
            // Cela crée une nouvelle ligne dans wp_postmeta à chaque fois
            $result = add_post_meta($product_id, 'wt_customdate', $timestamp, false);

            if ($result !== false) {
                $count++;
            } else {
                $success = false;
                error_log("[WBE Sync] Échec ajout date spécifique: $date pour produit #$product_id");
            }
        }

        if ($success && defined('WP_DEBUG') && WP_DEBUG) {
            error_log(sprintf(
                '[WBE Sync] %d dates spécifiques synchronisées pour produit #%d',
                $count,
                $product_id
            ));
        }

        return $success;
    }

    /**
     * Reconstruit le cache _wootour_availability
     * 
     * Ce cache est utilisé par Wootour pour améliorer les performances
     */
    public static function sync_wootour_availability_cache(int $product_id): bool
    {
        // Récupérer toutes les dates exclues (champ répétable)
        $exclusions = get_post_meta($product_id, 'wt_disabledate', false);
        $custom_dates = get_post_meta($product_id, 'wt_customdate', false);

        // Convertir en integers
        $exclusions = array_map('intval', is_array($exclusions) ? $exclusions : []);
        $custom_dates = array_map('intval', is_array($custom_dates) ? $custom_dates : []);

        $cache = [
            'start_date' => get_post_meta($product_id, 'wt_start', true),
            'end_date' => get_post_meta($product_id, 'wt_expired', true),
            'weekdays' => maybe_unserialize(get_post_meta($product_id, '_weekdays', true)),
            'exclusions' => $exclusions,
            'custom_dates' => $custom_dates
        ];

        $success = update_post_meta($product_id, '_wootour_availability', $cache) !== false;

        if ($success && defined('WP_DEBUG') && WP_DEBUG) {
            error_log(sprintf(
                '[WBE Sync] Cache _wootour_availability mis à jour pour produit #%d (%d exclusions, %d dates spécifiques)',
                $product_id,
                count($exclusions),
                count($custom_dates)
            ));
        }

        return $success;
    }

    /**
     * Convertit les jours WBE vers Wootour
     */
    private static function convert_days_to_wootour(array $wbe_days): array
    {
        $wootour_days = [];

        foreach ($wbe_days as $day) {
            $day_lower = strtolower($day);
            if (isset(self::DAY_MAPPING[$day_lower])) {
                $wootour_days[] = self::DAY_MAPPING[$day_lower];
            }
        }

        return $wootour_days;
    }

    /**
     * Détecte les problèmes de synchronisation
     */
    public static function detect_sync_issues(int $product_id): array
    {
        $issues = [];

        // Vérifier dates de début
        $wbe_start = get_post_meta($product_id, '_tour_start_date', true);
        $wt_start_ts = get_post_meta($product_id, 'wt_start', true);

        if ($wbe_start && $wt_start_ts) {
            $wbe_ts = strtotime($wbe_start);
            if ($wbe_ts != $wt_start_ts) {
                $issues[] = sprintf(
                    'Désynchronisation date de début : WBE=%s vs Wootour (ts:%d)',
                    $wbe_start,
                    $wt_start_ts
                );
            }
        }

        // Vérifier jours disponibles
        $wbe_days = maybe_unserialize(get_post_meta($product_id, '_tour_available_days', true));
        $wt_days = maybe_unserialize(get_post_meta($product_id, '_weekdays', true));

        if (!empty($wbe_days) && !empty($wt_days)) {
            $expected_wt_days = self::convert_days_to_wootour($wbe_days);
            sort($expected_wt_days);
            sort($wt_days);

            if ($expected_wt_days != $wt_days) {
                $issues[] = sprintf(
                    'Désynchronisation jours : WBE=%s vs Wootour=%s',
                    implode(',', $wbe_days),
                    implode(',', $wt_days)
                );
            }
        }

        // Vérifier dates spécifiques
        $wbe_specific = maybe_unserialize(get_post_meta($product_id, '_tour_specific_dates', true));
        $wt_custom = get_post_meta($product_id, 'wt_customdate', false);

        if (!empty($wbe_specific) && empty($wt_custom)) {
            $issues[] = 'Dates spécifiques WBE présentes mais absentes dans Wootour';
        }

        if (!empty($wbe_specific) && !empty($wt_custom)) {
            $wbe_count = is_array($wbe_specific) ? count($wbe_specific) : 0;
            $wt_count = is_array($wt_custom) ? count($wt_custom) : 0;

            if ($wbe_count != $wt_count) {
                $issues[] = sprintf(
                    'Nombre de dates spécifiques différent : WBE=%d vs Wootour=%d',
                    $wbe_count,
                    $wt_count
                );
            }
        }

        return $issues;
    }

    /**
     * Récupère les dates spécifiques depuis Wootour pour affichage
     * 
     * @param int $product_id
     * @return array Format: ['2026-04-10', '2026-04-15', ...]
     */
    public static function get_specific_dates_for_display(int $product_id): array
    {
        // Récupérer toutes les valeurs wt_customdate (champ répétable)
        $timestamps = get_post_meta($product_id, 'wt_customdate', false);

        if (empty($timestamps) || !is_array($timestamps)) {
            return [];
        }

        $dates = [];
        foreach ($timestamps as $timestamp) {
            if (is_numeric($timestamp) && $timestamp > 0) {
                $dates[] = date('Y-m-d', $timestamp);
            }
        }

        // Trier par ordre chronologique
        sort($dates);

        return $dates;
    }

    /**
     * BONUS : Fonction de debug pour afficher toutes les métadonnées Wootour
     * 
     * @param int $product_id
     * @return array
     */
    public static function get_all_wootour_meta(int $product_id): array
    {
        global $wpdb;

        // Récupérer toutes les dates d'exclusion
        $exclusions = get_post_meta($product_id, 'wt_disabledate', false);
        
        // Récupérer toutes les dates spécifiques
        $custom_dates = get_post_meta($product_id, 'wt_customdate', false);

        return [
            'start_timestamp' => get_post_meta($product_id, 'wt_start', true),
            'end_timestamp' => get_post_meta($product_id, 'wt_expired', true),
            'start_date_ymd' => get_post_meta($product_id, 'eventstartdate', true),
            'weekdays' => maybe_unserialize(get_post_meta($product_id, '_weekdays', true)),
            'exclusions_count' => count($exclusions),
            'exclusions' => $exclusions,
            'custom_dates_count' => count($custom_dates),
            'custom_dates' => $custom_dates,
            'cache' => get_post_meta($product_id, '_wootour_availability', true)
        ];
    }
}