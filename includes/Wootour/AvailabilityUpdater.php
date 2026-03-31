<?php

namespace WBE\Wootour;

use WBE\Services\WootourSyncService;

/**
 * Logique de mise à jour NON DESTRUCTIVE des disponibilités
 * 
 * RÈGLE D'OR : On ne touche QUE ce qui est explicitement demandé
 * Les champs vides dans l'input ne modifient PAS les données existantes
 * 
 * SYNCHRONISATION : Écrit automatiquement dans Wootour pour garantir la compatibilité
 * 
 * VERSION AMÉLIORÉE - 2026-02-07
 * Ajout : Fonction de réinitialisation complète et exhaustive
 */
class AvailabilityUpdater
{
    /**
     * Met à jour les disponibilités d'un produit de manière non destructive
     * AVEC synchronisation automatique vers Wootour
     * 
     * @param int $product_id
     * @param array $changes Modifications à appliquer (normalisées)
     * @return bool Succès ou échec
     */
    public static function update_availability(int $product_id, array $changes): bool
    {
        // Vérifier que le produit existe
        if (!get_post($product_id)) {
            self::log_error($product_id, 'Produit introuvable');
            return false;
        }

        $success = true;

        // ÉTAPE 1 : Mise à jour des métadonnées WBE
        // ==========================================

        self::log_info($product_id, 'Début de mise à jour : ' . json_encode(array_keys($changes)));

        // Mise à jour de la date de début
        if (isset($changes['start_date'])) {
            $result = MetaRepository::save_start_date($product_id, $changes['start_date']);
            $success = $result && $success;
            self::log_info($product_id, "Date début : {$changes['start_date']} - " . ($result ? 'OK' : 'ÉCHEC'));
        }

        // Mise à jour de la date de fin
        if (isset($changes['end_date'])) {
            $result = MetaRepository::save_end_date($product_id, $changes['end_date']);
            $success = $result && $success;
            self::log_info($product_id, "Date fin : {$changes['end_date']} - " . ($result ? 'OK' : 'ÉCHEC'));
        }

        // Mise à jour des jours disponibles
        if (isset($changes['available_days'])) {
            $result = self::update_available_days($product_id, $changes['available_days']);
            $success = $result && $success;
            $days_str = implode(', ', $changes['available_days']);
            self::log_info($product_id, "Jours disponibles : $days_str - " . ($result ? 'OK' : 'ÉCHEC'));
        }

        // Mise à jour des dates exclues
        if (isset($changes['unavailable_dates'])) {
            $result = self::update_unavailable_dates($product_id, $changes['unavailable_dates']);
            $success = $result && $success;
            $count = count($changes['unavailable_dates']);
            self::log_info($product_id, "Dates exclues : $count date(s) - " . ($result ? 'OK' : 'ÉCHEC'));
        }

        // Mise à jour des dates spécifiques
        if (isset($changes['specific_dates'])) {
            $result = self::update_specific_dates($product_id, $changes['specific_dates']);
            $success = $result && $success;
            $count = count($changes['specific_dates']);
            self::log_info($product_id, "Dates spécifiques : $count date(s) - " . ($result ? 'OK' : 'ÉCHEC'));
        }

        // ÉTAPE 2 : Synchronisation vers Wootour
        // ========================================

        if ($success) {
            try {
                self::log_info($product_id, 'Début synchronisation Wootour');
                
                // Synchroniser toutes les modifications vers Wootour
                $sync_success = WootourSyncService::sync_all($product_id, $changes);

                if ($sync_success) {
                    self::log_info($product_id, 'Synchronisation Wootour : SUCCÈS');
                } else {
                    self::log_error($product_id, 'Synchronisation Wootour : PARTIELLE');
                }

                // Détecter d'éventuels problèmes de synchronisation
                $issues = WootourSyncService::detect_sync_issues($product_id);

                if (!empty($issues)) {
                    self::log_error($product_id, 'Problèmes de sync détectés :');
                    foreach ($issues as $issue) {
                        self::log_error($product_id, "  - $issue");
                    }
                }
                
            } catch (\Exception $e) {
                // Logger l'erreur mais ne pas faire échouer la transaction WBE
                self::log_error($product_id, 'Exception sync Wootour : ' . $e->getMessage());
            }
        } else {
            self::log_error($product_id, 'Mise à jour WBE échouée, synchronisation Wootour annulée');
        }

        self::log_info($product_id, 'Fin de mise à jour - Statut : ' . ($success ? 'SUCCÈS' : 'ÉCHEC'));

        return $success;
    }

    /**
     * Met à jour les jours disponibles (fusion avec l'existant)
     * 
     * @param int $product_id
     * @param array $new_days
     * @return bool
     */
    private static function update_available_days(int $product_id, array $new_days): bool
    {
        $existing = MetaRepository::get_available_days($product_id);
        $merged = array_unique(array_merge($existing, $new_days));
        
        // Tri alphabétique pour cohérence
        sort($merged);

        return MetaRepository::save_available_days($product_id, $merged);
    }

    /**
     * Met à jour les dates exclues (fusion avec l'existant)
     * 
     * @param int $product_id
     * @param array $new_dates
     * @return bool
     */
    private static function update_unavailable_dates(int $product_id, array $new_dates): bool
    {
        $existing = MetaRepository::get_unavailable_dates($product_id);
        $merged = array_unique(array_merge($existing, $new_dates));
        
        // Tri chronologique
        sort($merged);

        return MetaRepository::save_unavailable_dates($product_id, $merged);
    }

    /**
     * Met à jour les dates spécifiques (fusion avec l'existant)
     * 
     * @param int $product_id
     * @param array $new_dates
     * @return bool
     */
    private static function update_specific_dates(int $product_id, array $new_dates): bool
    {
        $existing = MetaRepository::get_specific_dates($product_id);

        // Fusion : les nouvelles dates écrasent les anciennes si même clé
        $merged = array_merge($existing, $new_dates);

        return MetaRepository::save_specific_dates($product_id, $merged);
    }

    /**
     * Réinitialise TOUTES les disponibilités d'un produit
     * ⚠️ Action destructive - à utiliser uniquement si demandé explicitement
     * 
     * NOUVELLE VERSION EXHAUSTIVE - Supprime TOUTES les métadonnées :
     * - Métadonnées WBE (_tour_*)
     * - Métadonnées Wootour (wt_*, _weekdays, etc.)
     * - Champs répétables (wt_disabledate, wt_customdate)
     * - Cache (_wootour_availability)
     * 
     * @param int $product_id
     * @return bool
     */
    public static function reset_availability(int $product_id): bool
    {
        self::log_info($product_id, '╔════════════════════════════════════════╗');
        self::log_info($product_id, '║   RÉINITIALISATION COMPLÈTE DÉMARRÉE   ║');
        self::log_info($product_id, '╚════════════════════════════════════════╝');

        $success = true;
        $deleted_count = 0;

        // ========================================
        // ÉTAPE 1 : Métadonnées WBE
        // ========================================
        self::log_info($product_id, '[1/4] Suppression métadonnées WBE...');
        
        $wbe_metas = [
            '_tour_start_date',
            '_tour_end_date',
            '_tour_available_days',
            '_tour_unavailable_dates',
            '_tour_specific_dates',
        ];

        foreach ($wbe_metas as $meta_key) {
            $deleted = delete_post_meta($product_id, $meta_key);
            if ($deleted) {
                $deleted_count++;
                self::log_info($product_id, "  ✓ Supprimé : $meta_key");
            }
        }

        // ========================================
        // ÉTAPE 2 : Métadonnées Wootour simples
        // ========================================
        self::log_info($product_id, '[2/4] Suppression métadonnées Wootour simples...');
        
        $wootour_metas = [
            // Dates de début (toutes variantes)
            'wt_start',
            'start_date',
            'eventstartdate',
            'wp_event_start_date',
            '_start_date',
            
            // Dates de fin (toutes variantes)
            'wt_expired',
            'expired_date',
            
            // Jours de la semaine
            '_weekdays',
            'wt_weekday',
            
            // Cache
            '_wootour_availability',
        ];

        foreach ($wootour_metas as $meta_key) {
            $deleted = delete_post_meta($product_id, $meta_key);
            if ($deleted) {
                $deleted_count++;
                self::log_info($product_id, "  ✓ Supprimé : $meta_key");
            }
        }

        // ========================================
        // ÉTAPE 3 : Champs répétables (wt_disabledate)
        // ========================================
        self::log_info($product_id, '[3/4] Suppression dates d\'exclusion (champs répétables)...');
        
        global $wpdb;
        
        // Supprimer toutes les entrées wt_disabledate
        $result = $wpdb->query($wpdb->prepare(
            "DELETE FROM $wpdb->postmeta 
             WHERE post_id = %d 
             AND meta_key = 'wt_disabledate'",
            $product_id
        ));

        if ($result !== false && $result > 0) {
            $deleted_count += $result;
            self::log_info($product_id, "  ✓ Supprimé : $result entrées wt_disabledate");
        }

        // Supprimer aussi les variantes legacy (si elles existent)
        $result = $wpdb->query($wpdb->prepare(
            "DELETE FROM $wpdb->postmeta 
             WHERE post_id = %d 
             AND meta_key LIKE 'wt_disabledate%%'",
            $product_id
        ));

        if ($result !== false && $result > 0) {
            $deleted_count += $result;
            self::log_info($product_id, "  ✓ Supprimé : $result entrées wt_disabledate (variantes)");
        }

        // ========================================
        // ÉTAPE 4 : Champs répétables (wt_customdate)
        // ========================================
        self::log_info($product_id, '[4/4] Suppression dates spécifiques (champs répétables)...');
        
        // Supprimer toutes les entrées wt_customdate
        $result = $wpdb->query($wpdb->prepare(
            "DELETE FROM $wpdb->postmeta 
             WHERE post_id = %d 
             AND meta_key = 'wt_customdate'",
            $product_id
        ));

        if ($result !== false && $result > 0) {
            $deleted_count += $result;
            self::log_info($product_id, "  ✓ Supprimé : $result entrées wt_customdate");
        }

        // Supprimer aussi les variantes (si elles existent)
        $result = $wpdb->query($wpdb->prepare(
            "DELETE FROM $wpdb->postmeta 
             WHERE post_id = %d 
             AND meta_key LIKE 'wt_customdate%%'",
            $product_id
        ));

        if ($result !== false && $result > 0) {
            $deleted_count += $result;
            self::log_info($product_id, "  ✓ Supprimé : $result entrées wt_customdate (variantes)");
        }

        // ========================================
        // RÉSUMÉ
        // ========================================
        self::log_info($product_id, '');
        self::log_info($product_id, '╔════════════════════════════════════════╗');
        self::log_info($product_id, "║  TOTAL : $deleted_count métadonnées supprimées  ║");
        self::log_info($product_id, '╚════════════════════════════════════════╝');
        self::log_info($product_id, '');

        if ($deleted_count > 0) {
            self::log_info($product_id, '✓ Réinitialisation COMPLÈTE - Produit vierge');
        } else {
            self::log_info($product_id, 'ℹ Produit déjà vierge (aucune métadonnée trouvée)');
        }

        return $success;
    }

    /**
     * BONUS : Affiche un rapport détaillé AVANT réinitialisation
     * Utile pour confirmer ce qui va être supprimé
     * 
     * @param int $product_id
     * @return array
     */
    public static function get_reset_preview(int $product_id): array
    {
        global $wpdb;

        $preview = [
            'product_id' => $product_id,
            'product_name' => get_the_title($product_id),
            'will_delete' => [],
            'total_items' => 0,
        ];

        // WBE
        $wbe_data = MetaRepository::get_all_availability($product_id);
        if (!empty($wbe_data['start_date'])) {
            $preview['will_delete'][] = "Date début WBE : {$wbe_data['start_date']}";
            $preview['total_items']++;
        }
        if (!empty($wbe_data['end_date'])) {
            $preview['will_delete'][] = "Date fin WBE : {$wbe_data['end_date']}";
            $preview['total_items']++;
        }
        if (!empty($wbe_data['available_days'])) {
            $preview['will_delete'][] = "Jours disponibles : " . implode(', ', $wbe_data['available_days']);
            $preview['total_items']++;
        }
        if (!empty($wbe_data['unavailable_dates'])) {
            $count = count($wbe_data['unavailable_dates']);
            $preview['will_delete'][] = "Dates exclues : $count date(s)";
            $preview['total_items'] += $count;
        }
        if (!empty($wbe_data['specific_dates'])) {
            $count = count($wbe_data['specific_dates']);
            $preview['will_delete'][] = "Dates spécifiques : $count date(s)";
            $preview['total_items'] += $count;
        }

        // Wootour
        $wt_start = get_post_meta($product_id, 'wt_start', true);
        if ($wt_start) {
            $preview['will_delete'][] = "Date début Wootour : " . date('Y-m-d', $wt_start);
            $preview['total_items']++;
        }

        $wt_end = get_post_meta($product_id, 'wt_expired', true);
        if ($wt_end) {
            $preview['will_delete'][] = "Date fin Wootour : " . date('Y-m-d', $wt_end);
            $preview['total_items']++;
        }

        // Compter les champs répétables
        $disable_count = $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM $wpdb->postmeta 
             WHERE post_id = %d AND meta_key = 'wt_disabledate'",
            $product_id
        ));
        if ($disable_count > 0) {
            $preview['will_delete'][] = "Exclusions Wootour : $disable_count date(s)";
            $preview['total_items'] += $disable_count;
        }

        $custom_count = $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM $wpdb->postmeta 
             WHERE post_id = %d AND meta_key = 'wt_customdate'",
            $product_id
        ));
        if ($custom_count > 0) {
            $preview['will_delete'][] = "Dates spécifiques Wootour : $custom_count date(s)";
            $preview['total_items'] += $custom_count;
        }

        return $preview;
    }

    /**
     * Vérifie l'état de synchronisation d'un produit
     * Utile pour le debugging et la validation
     * 
     * @param int $product_id
     * @return array Rapport de synchronisation
     */
    public static function check_sync_status(int $product_id): array
    {
        $report = [
            'product_id' => $product_id,
            'product_name' => get_the_title($product_id),
            'wbe_data' => MetaRepository::get_all_availability($product_id),
            'wootour_data' => self::get_wootour_data($product_id),
            'sync_issues' => WootourSyncService::detect_sync_issues($product_id),
            'is_synchronized' => false
        ];

        $report['is_synchronized'] = empty($report['sync_issues']);

        return $report;
    }

    /**
     * Récupère les données Wootour d'un produit
     * 
     * @param int $product_id
     * @return array
     */
    private static function get_wootour_data(int $product_id): array
    {
        global $wpdb;

        // Récupérer les exclusions Wootour
        $exclusions_results = $wpdb->get_results($wpdb->prepare(
            "SELECT meta_key, meta_value 
             FROM $wpdb->postmeta 
             WHERE post_id = %d 
             AND meta_key = 'wt_disabledate'
             ORDER BY meta_value",
            $product_id
        ));

        $exclusions = [];
        foreach ($exclusions_results as $row) {
            $exclusions[] = $row->meta_value;
        }

        // Récupérer les dates spécifiques Wootour
        $custom_results = $wpdb->get_results($wpdb->prepare(
            "SELECT meta_key, meta_value 
             FROM $wpdb->postmeta 
             WHERE post_id = %d 
             AND meta_key = 'wt_customdate'
             ORDER BY meta_value",
            $product_id
        ));

        $custom_dates = [];
        foreach ($custom_results as $row) {
            $custom_dates[] = $row->meta_value;
        }

        return [
            'start_timestamp' => get_post_meta($product_id, 'wt_start', true),
            'end_timestamp' => get_post_meta($product_id, 'wt_expired', true),
            'start_date_ymd' => get_post_meta($product_id, 'eventstartdate', true),
            'weekdays' => maybe_unserialize(get_post_meta($product_id, '_weekdays', true)),
            'exclusions' => $exclusions,
            'exclusions_count' => count($exclusions),
            'custom_dates' => $custom_dates,
            'custom_dates_count' => count($custom_dates),
            'cache' => get_post_meta($product_id, '_wootour_availability', true)
        ];
    }

    /**
     * Log d'information (uniquement si WP_DEBUG activé)
     * 
     * @param int $product_id
     * @param string $message
     * @return void
     */
    private static function log_info(int $product_id, string $message): void
    {
        if (defined('WP_DEBUG') && WP_DEBUG) {
            error_log("[WBE AvailabilityUpdater] Produit #$product_id - $message");
        }
    }

    /**
     * Log d'erreur (uniquement si WP_DEBUG activé)
     * 
     * @param int $product_id
     * @param string $message
     * @return void
     */
    private static function log_error(int $product_id, string $message): void
    {
        if (defined('WP_DEBUG') && WP_DEBUG) {
            error_log("[WBE ERROR] Produit #$product_id - $message");
        }
    }

    /**
     * Récupère un rapport détaillé de toutes les métadonnées (WBE + Wootour)
     * Utile pour le debugging approfondi
     * 
     * @param int $product_id
     * @return array
     */
    public static function get_full_diagnostic(int $product_id): array
    {
        $sync_status = self::check_sync_status($product_id);
        
        return [
            'product' => [
                'id' => $product_id,
                'name' => get_the_title($product_id),
                'type' => get_post_type($product_id),
                'status' => get_post_status($product_id)
            ],
            'wbe' => $sync_status['wbe_data'],
            'wootour' => $sync_status['wootour_data'],
            'synchronization' => [
                'is_synchronized' => $sync_status['is_synchronized'],
                'issues' => $sync_status['sync_issues'],
                'issue_count' => count($sync_status['sync_issues'])
            ],
            'timestamp' => current_time('mysql')
        ];
    }
}