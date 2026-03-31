<?php

namespace WBE\Wootour;

/**
 * Normalise et valide les données de disponibilité
 * Responsabilité : transformer les données brutes en structure exploitable
 */
class AvailabilityParser
{
    /**
     * Jours de la semaine valides
     */
    private const VALID_DAYS = [
        'monday',
        'tuesday',
        'wednesday',
        'thursday',
        'friday',
        'saturday',
        'sunday'
    ];

    /**
     * Valide une date au format Y-m-d
     * 
     * @param string $date
     * @return bool
     */
    public static function is_valid_date(string $date): bool
    {
        $d = \DateTime::createFromFormat('Y-m-d', $date);
        return $d && $d->format('Y-m-d') === $date;
    }

    /**
     * Valide un jour de la semaine
     * 
     * @param string $day
     * @return bool
     */
    public static function is_valid_day(string $day): bool
    {
        return in_array(strtolower($day), self::VALID_DAYS, true);
    }

    /**
     * Normalise un tableau de jours (lowercase, dédoublonnage, validation)
     * 
     * @param array $days
     * @return array
     */
    public static function normalize_days(array $days): array
    {
        $normalized = [];
        
        foreach ($days as $day) {
            $day = strtolower(trim($day));
            if (self::is_valid_day($day) && !in_array($day, $normalized, true)) {
                $normalized[] = $day;
            }
        }
        
        return $normalized;
    }

    /**
     * Normalise un tableau de dates (validation, dédoublonnage, tri)
     * 
     * @param array $dates
     * @return array
     */
    public static function normalize_dates(array $dates): array
    {
        $normalized = [];
        
        foreach ($dates as $date) {
            $date = trim($date);
            if (self::is_valid_date($date) && !in_array($date, $normalized, true)) {
                $normalized[] = $date;
            }
        }
        
        sort($normalized);
        
        return $normalized;
    }

    /**
     * Vérifie si une date est dans la plage start/end
     * 
     * @param string $date Date à vérifier
     * @param string|null $start_date
     * @param string|null $end_date
     * @return bool
     */
    public static function is_date_in_range(string $date, ?string $start_date, ?string $end_date): bool
    {
        if (!self::is_valid_date($date)) {
            return false;
        }

        $timestamp = strtotime($date);

        if ($start_date && strtotime($start_date) > $timestamp) {
            return false;
        }

        if ($end_date && strtotime($end_date) < $timestamp) {
            return false;
        }

        return true;
    }

    /**
     * Détecte les conflits entre dates spécifiques et dates exclues
     * 
     * @param array $specific_dates
     * @param array $unavailable_dates
     * @return array Dates en conflit
     */
    public static function detect_conflicts(array $specific_dates, array $unavailable_dates): array
    {
        $specific_keys = array_keys($specific_dates);
        return array_intersect($specific_keys, $unavailable_dates);
    }

    /**
     * Normalise les données d'entrée utilisateur avant traitement
     * 
     * @param array $input Données brutes du formulaire
     * @return array Données normalisées et validées
     */
    public static function normalize_input(array $input): array
    {
        $normalized = [];

        // Date de début
        if (!empty($input['start_date']) && self::is_valid_date($input['start_date'])) {
            $normalized['start_date'] = $input['start_date'];
        }

        // Date de fin
        if (!empty($input['end_date']) && self::is_valid_date($input['end_date'])) {
            $normalized['end_date'] = $input['end_date'];
        }

        // Jours disponibles
        if (!empty($input['available_days']) && is_array($input['available_days'])) {
            $normalized['available_days'] = self::normalize_days($input['available_days']);
        }

        // Dates exclues
        if (!empty($input['unavailable_dates']) && is_array($input['unavailable_dates'])) {
            $normalized['unavailable_dates'] = self::normalize_dates($input['unavailable_dates']);
        }

        // Date spécifique unique
        if (!empty($input['specific_date']) && self::is_valid_date($input['specific_date'])) {
            $normalized['specific_dates'] = [$input['specific_date'] => []];
        }

        return $normalized;
    }
}