<?php

namespace WBE\Wootour;

/**
 * Couche d'accès aux métadonnées Wootour
 * 
 * VERSION OPTIMISÉE FINALE :
 * - Utilise le cache _wootour_availability quand disponible
 * - Fallback intelligent vers les métadonnées individuelles
 * - Compatible avec Wootour natif ET format WBE
 */
class MetaRepository
{
    /**
     * Clés de métadonnées WBE
     */
    private const META_START_DATE = '_tour_start_date';
    private const META_END_DATE = '_tour_end_date';
    private const META_AVAILABLE_DAYS = '_tour_available_days';
    private const META_UNAVAILABLE_DATES = '_tour_unavailable_dates';
    private const META_SPECIFIC_DATES = '_tour_specific_dates';
    
    /**
     * Clés Wootour natives
     */
    private const WT_AVAILABILITY_CACHE = '_wootour_availability';
    private const WT_START = 'wt_start';
    private const WT_EXPIRED = 'wt_expired';
    private const WT_WEEKDAYS = '_weekdays';

    /**
     * Récupère la date de début du tour
     * 
     * Ordre de priorité :
     * 1. _tour_start_date (WBE)
     * 2. Cache _wootour_availability
     * 3. wt_start (timestamp Wootour)
     */
    public static function get_start_date(int $product_id): ?string
    {
        // 1. Format WBE (prioritaire)
        $wbe_date = get_post_meta($product_id, self::META_START_DATE, true);
        if (!empty($wbe_date)) {
            return $wbe_date;
        }
        
        // 2. Cache Wootour
        $cache = self::get_wootour_cache($product_id);
        if (!empty($cache['start_date'])) {
            return date('Y-m-d', $cache['start_date']);
        }
        
        // 3. Timestamp Wootour
        $timestamp = get_post_meta($product_id, self::WT_START, true);
        if (!empty($timestamp)) {
            return date('Y-m-d', $timestamp);
        }
        
        return null;
    }

    /**
     * Récupère la date de fin du tour
     */
    public static function get_end_date(int $product_id): ?string
    {
        // 1. Format WBE
        $wbe_date = get_post_meta($product_id, self::META_END_DATE, true);
        if (!empty($wbe_date)) {
            return $wbe_date;
        }
        
        // 2. Cache Wootour
        $cache = self::get_wootour_cache($product_id);
        if (!empty($cache['end_date'])) {
            return date('Y-m-d', $cache['end_date']);
        }
        
        // 3. Timestamp Wootour
        $timestamp = get_post_meta($product_id, self::WT_EXPIRED, true);
        if (!empty($timestamp)) {
            return date('Y-m-d', $timestamp);
        }
        
        return null;
    }

    /**
     * Récupère les jours disponibles
     * 
     * Format WBE : ['monday', 'tuesday', 'wednesday']
     * Format Wootour : [2, 3, 4] où 1=Dimanche, 2=Lundi, ..., 7=Samedi
     */
    public static function get_available_days(int $product_id): array
    {
        // 1. Format WBE
        $wbe_days = get_post_meta($product_id, self::META_AVAILABLE_DAYS, true);
        if (is_string($wbe_days)) {
            $wbe_days = maybe_unserialize($wbe_days);
        }
        if (is_array($wbe_days) && !empty($wbe_days)) {
            return $wbe_days;
        }
        
        // 2. Cache Wootour
        $cache = self::get_wootour_cache($product_id);
        if (!empty($cache['weekdays']) && is_array($cache['weekdays'])) {
            return self::convert_wootour_days_to_wbe($cache['weekdays']);
        }
        
        // 3. Métadonnée Wootour directe
        $wt_days = get_post_meta($product_id, self::WT_WEEKDAYS, true);
        if (is_string($wt_days)) {
            $wt_days = maybe_unserialize($wt_days);
        }
        if (is_array($wt_days) && !empty($wt_days)) {
            return self::convert_wootour_days_to_wbe($wt_days);
        }
        
        return [];
    }

    /**
     * Récupère les dates exclues
     */
    public static function get_unavailable_dates(int $product_id): array
    {
        // 1. Format WBE
        $wbe_dates = get_post_meta($product_id, self::META_UNAVAILABLE_DATES, true);
        if (is_string($wbe_dates)) {
            $wbe_dates = maybe_unserialize($wbe_dates);
        }
        if (is_array($wbe_dates) && !empty($wbe_dates)) {
            return $wbe_dates;
        }
        
        // 2. Cache Wootour
        $cache = self::get_wootour_cache($product_id);
        if (!empty($cache['exclusions']) && is_array($cache['exclusions'])) {
            return self::convert_timestamps_to_dates($cache['exclusions']);
        }
        
        return [];
    }

    /**
     * Récupère les dates spécifiques
     * 
     * VERSION OPTIMISÉE : Exploite le cache _wootour_availability
     */
    public static function get_specific_dates(int $product_id): array
    {
        // 1. Format WBE (prioritaire)
        $wbe_dates = get_post_meta($product_id, self::META_SPECIFIC_DATES, true);
        if (is_string($wbe_dates)) {
            $wbe_dates = maybe_unserialize($wbe_dates);
        }
        if (is_array($wbe_dates) && !empty($wbe_dates)) {
            return $wbe_dates;
        }
        
        // 2. Cache Wootour (_wootour_availability['custom_dates'])
        $cache = self::get_wootour_cache($product_id);
        if (!empty($cache['custom_dates']) && is_array($cache['custom_dates'])) {
            return self::convert_wootour_custom_dates($cache['custom_dates']);
        }
        
        // 3. Fallback : lire wt_customdate directement (si cache invalide)
        global $wpdb;
        $results = $wpdb->get_results($wpdb->prepare(
            "SELECT meta_value 
             FROM $wpdb->postmeta 
             WHERE post_id = %d 
             AND meta_key = 'wt_customdate'
             ORDER BY meta_id",
            $product_id
        ));
        
        if (empty($results)) {
            return [];
        }
        
        $specific_dates = [];
        foreach ($results as $row) {
            $timestamp = intval($row->meta_value);
            if ($timestamp > 0) {
                $date = date('Y-m-d', $timestamp);
                $specific_dates[$date] = [
                    'available' => true,
                    'price' => null,
                    'slots' => null,
                ];
            }
        }
        
        return $specific_dates;
    }

    /**
     * Récupère TOUTES les métadonnées Wootour d'un produit
     */
    public static function get_all_availability(int $product_id): array
    {
        return [
            'start_date'        => self::get_start_date($product_id),
            'end_date'          => self::get_end_date($product_id),
            'available_days'    => self::get_available_days($product_id),
            'unavailable_dates' => self::get_unavailable_dates($product_id),
            'specific_dates'    => self::get_specific_dates($product_id),
        ];
    }

    /**
     * Enregistre la date de début
     */
    public static function save_start_date(int $product_id, ?string $date): bool
    {
        if ($date === null) {
            return delete_post_meta($product_id, self::META_START_DATE);
        }
        
        $current = get_post_meta($product_id, self::META_START_DATE, true);
        
        if ($current === $date) {
            return true;
        }
        
        $result = update_post_meta($product_id, self::META_START_DATE, $date);
        
        if ($result === false) {
            $verification = get_post_meta($product_id, self::META_START_DATE, true);
            return $verification === $date;
        }
        
        return true;
    }

    /**
     * Enregistre la date de fin
     */
    public static function save_end_date(int $product_id, ?string $date): bool
    {
        if ($date === null) {
            return delete_post_meta($product_id, self::META_END_DATE);
        }
        
        $current = get_post_meta($product_id, self::META_END_DATE, true);
        
        if ($current === $date) {
            return true;
        }
        
        $result = update_post_meta($product_id, self::META_END_DATE, $date);
        
        if ($result === false) {
            $verification = get_post_meta($product_id, self::META_END_DATE, true);
            return $verification === $date;
        }
        
        return true;
    }

    /**
     * Enregistre les jours disponibles
     */
    public static function save_available_days(int $product_id, array $days): bool
    {
        if (empty($days)) {
            return delete_post_meta($product_id, self::META_AVAILABLE_DAYS);
        }
        
        $current = self::get_available_days($product_id);
        
        if ($current === $days) {
            return true;
        }
        
        $result = update_post_meta($product_id, self::META_AVAILABLE_DAYS, $days);
        
        if ($result === false) {
            $verification = self::get_available_days($product_id);
            return $verification === $days;
        }
        
        return true;
    }

    /**
     * Enregistre les dates exclues
     */
    public static function save_unavailable_dates(int $product_id, array $dates): bool
    {
        if (empty($dates)) {
            return delete_post_meta($product_id, self::META_UNAVAILABLE_DATES);
        }
        
        $current = self::get_unavailable_dates($product_id);
        
        if ($current === $dates) {
            return true;
        }
        
        $result = update_post_meta($product_id, self::META_UNAVAILABLE_DATES, $dates);
        
        if ($result === false) {
            $verification = self::get_unavailable_dates($product_id);
            return $verification === $dates;
        }
        
        return true;
    }

    /**
     * Enregistre les dates spécifiques
     */
    public static function save_specific_dates(int $product_id, array $dates): bool
    {
        if (empty($dates)) {
            return delete_post_meta($product_id, self::META_SPECIFIC_DATES);
        }
        
        $current = self::get_specific_dates($product_id);
        
        if ($current === $dates) {
            return true;
        }
        
        $result = update_post_meta($product_id, self::META_SPECIFIC_DATES, $dates);
        
        if ($result === false) {
            $verification = self::get_specific_dates($product_id);
            return $verification === $dates;
        }
        
        return true;
    }

    /**
     * Supprime TOUTES les métadonnées Wootour
     */
    public static function reset_all_availability(int $product_id): bool
    {
        $success = true;
        
        $success = delete_post_meta($product_id, self::META_START_DATE) && $success;
        $success = delete_post_meta($product_id, self::META_END_DATE) && $success;
        $success = delete_post_meta($product_id, self::META_AVAILABLE_DAYS) && $success;
        $success = delete_post_meta($product_id, self::META_UNAVAILABLE_DATES) && $success;
        $success = delete_post_meta($product_id, self::META_SPECIFIC_DATES) && $success;
        
        return $success;
    }

    // ========================================================================
    // MÉTHODES UTILITAIRES PRIVÉES
    // ========================================================================

    /**
     * Récupère le cache _wootour_availability
     * 
     * @param int $product_id
     * @return array
     */
    private static function get_wootour_cache(int $product_id): array
    {
        $cache = get_post_meta($product_id, self::WT_AVAILABILITY_CACHE, true);
        
        if (is_string($cache)) {
            $cache = maybe_unserialize($cache);
        }
        
        return is_array($cache) ? $cache : [];
    }

    /**
     * Convertit les jours Wootour (1-7) vers le format WBE (noms)
     * 
     * Wootour : 1=Dimanche, 2=Lundi, 3=Mardi, 4=Mercredi, 5=Jeudi, 6=Vendredi, 7=Samedi
     * WBE : monday, tuesday, wednesday, thursday, friday, saturday, sunday
     * 
     * @param array $wootour_days [2, 3, 4]
     * @return array ['monday', 'tuesday', 'wednesday']
     */
    private static function convert_wootour_days_to_wbe(array $wootour_days): array
    {
        $mapping = [
            1 => 'sunday',
            2 => 'monday',
            3 => 'tuesday',
            4 => 'wednesday',
            5 => 'thursday',
            6 => 'friday',
            7 => 'saturday',
        ];
        
        $wbe_days = [];
        foreach ($wootour_days as $day) {
            if (isset($mapping[$day])) {
                $wbe_days[] = $mapping[$day];
            }
        }
        
        return $wbe_days;
    }

    /**
     * Convertit un tableau de timestamps en dates Y-m-d
     * 
     * @param array $timestamps [1772064000, 1772323200, ...]
     * @return array ['2026-02-26', '2026-03-01', ...]
     */
    private static function convert_timestamps_to_dates(array $timestamps): array
    {
        $dates = [];
        
        foreach ($timestamps as $timestamp) {
            $ts = is_numeric($timestamp) ? intval($timestamp) : strtotime($timestamp);
            if ($ts > 0) {
                $dates[] = date('Y-m-d', $ts);
            }
        }
        
        return $dates;
    }

    /**
     * Convertit les custom_dates Wootour vers le format WBE
     * 
     * Format Wootour custom_dates (peut varier) :
     * - Soit un tableau de timestamps
     * - Soit un tableau associatif avec des métadonnées
     * 
     * @param array $custom_dates
     * @return array Format WBE : ['2026-04-10' => [...], ...]
     */
    private static function convert_wootour_custom_dates(array $custom_dates): array
    {
        $wbe_dates = [];
        
        foreach ($custom_dates as $key => $value) {
            // Si c'est un timestamp direct
            if (is_numeric($key)) {
                $timestamp = is_numeric($value) ? intval($value) : strtotime($value);
                if ($timestamp > 0) {
                    $date = date('Y-m-d', $timestamp);
                    $wbe_dates[$date] = [
                        'available' => true,
                        'price' => null,
                        'slots' => null,
                    ];
                }
            }
            // Si c'est déjà un format date => données
            elseif (is_string($key) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $key)) {
                $wbe_dates[$key] = is_array($value) ? $value : [
                    'available' => true,
                    'price' => null,
                    'slots' => null,
                ];
            }
        }
        
        return $wbe_dates;
    }

    /**
     * BONUS : Méthode pour forcer la reconstruction du cache Wootour
     * Utile après des modifications manuelles
     * 
     * @param int $product_id
     * @return bool
     */
    public static function rebuild_wootour_cache(int $product_id): bool
    {
        $cache = [
            'start_date' => null,
            'end_date' => null,
            'weekdays' => [],
            'exclusions' => [],
            'custom_dates' => [],
        ];
        
        // Récupérer les données WBE
        $start = self::get_start_date($product_id);
        $end = self::get_end_date($product_id);
        $days = self::get_available_days($product_id);
        $excluded = self::get_unavailable_dates($product_id);
        $specific = self::get_specific_dates($product_id);
        
        // Convertir au format Wootour
        if ($start) {
            $cache['start_date'] = strtotime($start);
        }
        if ($end) {
            $cache['end_date'] = strtotime($end);
        }
        if (!empty($days)) {
            $cache['weekdays'] = self::convert_wbe_days_to_wootour($days);
        }
        if (!empty($excluded)) {
            foreach ($excluded as $date) {
                $cache['exclusions'][] = strtotime($date);
            }
        }
        if (!empty($specific)) {
            foreach ($specific as $date => $data) {
                $cache['custom_dates'][] = strtotime($date);
            }
        }
        
        return update_post_meta($product_id, self::WT_AVAILABILITY_CACHE, $cache);
    }

    /**
     * Convertit les jours WBE vers le format Wootour
     * 
     * @param array $wbe_days ['monday', 'tuesday']
     * @return array [2, 3]
     */
    private static function convert_wbe_days_to_wootour(array $wbe_days): array
    {
        $mapping = [
            'sunday' => 1,
            'monday' => 2,
            'tuesday' => 3,
            'wednesday' => 4,
            'thursday' => 5,
            'friday' => 6,
            'saturday' => 7,
        ];
        
        $wootour_days = [];
        foreach ($wbe_days as $day) {
            $day_lower = strtolower($day);
            if (isset($mapping[$day_lower])) {
                $wootour_days[] = $mapping[$day_lower];
            }
        }
        
        return $wootour_days;
    }
}