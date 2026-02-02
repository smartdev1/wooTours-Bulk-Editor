<?php
/**
 * WooTour Field Formatter Service
 * Formate les données pour créer les champs WooTour individuels
 * 
 * @package     WootourBulkEditor
 * @subpackage  Services
 * @license     GPL-2.0+
 * @since       1.0.0
 */

namespace WootourBulkEditor\Services;

defined('ABSPATH') || exit;

class WootourFieldFormatter
{
    /**
     * Formater les dates spécifiques au format WooTour
     * Crée un champ distinct pour chaque date (exc_mb-field-0, exc_mb-field-1, ...)
     * 
     * @param array $dates Tableau de dates YYYY-MM-DD
     * @return array Tableau formaté pour WooTour ['exc_mb-field-0' => 'MM/DD/YYYY', ...]
     */
    public static function formatSpecificDates(array $dates): array
    {
        $formatted = [];
        
        foreach ($dates as $index => $date) {
            $formatted["exc_mb-field-{$index}"] = self::convertToWootourDate($date);
        }
        
        error_log('[WBE WootourFieldFormatter] Formatted ' . count($formatted) . ' specific dates');
        return $formatted;
    }
    
    /**
     * Formater les dates d'exclusion au format WooTour
     * Crée un champ distinct pour chaque date (exc_mb-field-0, exc_mb-field-1, ...)
     * 
     * @param array $dates Tableau de dates YYYY-MM-DD
     * @return array Tableau formaté pour WooTour ['exc_mb-field-0' => 'MM/DD/YYYY', ...]
     */
    public static function formatExclusionDates(array $dates): array
    {
        $formatted = [];
        
        foreach ($dates as $index => $date) {
            $formatted["exc_mb-field-{$index}"] = self::convertToWootourDate($date);
        }
        
        error_log('[WBE WootourFieldFormatter] Formatted ' . count($formatted) . ' exclusion dates');
        return $formatted;
    }
    
    /**
     * Convertir une date YYYY-MM-DD en format WooTour (MM/DD/YYYY)
     * 
     * @param string $date Date au format YYYY-MM-DD
     * @return string Date au format MM/DD/YYYY
     */
    private static function convertToWootourDate(string $date): string
    {
        $timestamp = strtotime($date);
        if ($timestamp === false) {
            error_log('[WBE WootourFieldFormatter] Invalid date: ' . $date);
            return '';
        }
        return date('m/d/Y', $timestamp);
    }
    
    /**
     * Fusionner les nouvelles dates avec les dates existantes
     * 
     * @param array $existingDates Dates existantes du produit (format WooTour)
     * @param array $newDates Nouvelles dates à ajouter (format YYYY-MM-DD)
     * @param bool $replace Si true, remplace toutes les dates, sinon fusionne
     * @return array Tableau fusionné au format WooTour
     */
    public static function mergeDates(array $existingDates, array $newDates, bool $replace = false): array
    {
        if ($replace || empty($existingDates)) {
            error_log('[WBE WootourFieldFormatter] Replacing all dates with ' . count($newDates) . ' new dates');
            return $newDates;
        }
        
        error_log('[WBE WootourFieldFormatter] Merging ' . count($existingDates) . ' existing with ' . count($newDates) . ' new dates');
        
        // Convertir les dates existantes en format normalisé pour comparaison
        $existingNormalized = [];
        foreach ($existingDates as $key => $dateValue) {
            $normalized = self::normalizeDate($dateValue);
            if ($normalized) {
                $existingNormalized[$normalized] = $dateValue;
            }
        }
        
        // Convertir les nouvelles dates
        $newNormalized = [];
        foreach ($newDates as $key => $dateValue) {
            $normalized = self::normalizeDate($dateValue);
            if ($normalized) {
                $newNormalized[$normalized] = $dateValue;
            }
        }
        
        // Fusionner en évitant les doublons
        $merged = array_merge($existingNormalized, $newNormalized);
        
        // Trier par date
        uksort($merged, function($a, $b) {
            return strtotime($a) <=> strtotime($b);
        });
        
        // Réindexer les champs
        $reindexed = self::reindexFields(array_values($merged));
        
        error_log('[WBE WootourFieldFormatter] Merged result: ' . count($reindexed) . ' unique dates');
        return $reindexed;
    }
    
    /**
     * Réindexer les champs WooTour (exc_mb-field-0, exc_mb-field-1, ...)
     * 
     * @param array $values Valeurs à réindexer
     * @return array Champs réindexés
     */
    private static function reindexFields(array $values): array
    {
        $reindexed = [];
        $index = 0;
        
        foreach ($values as $value) {
            if (!empty($value)) {
                $reindexed["exc_mb-field-{$index}"] = $value;
                $index++;
            }
        }
        
        return $reindexed;
    }
    
    /**
     * Normaliser une date pour comparaison (toujours retourner YYYY-MM-DD)
     * 
     * @param string $date Date à normaliser
     * @return string Date normalisée YYYY-MM-DD ou chaîne vide si invalide
     */
    private static function normalizeDate(string $date): string
    {
        if (empty($date)) {
            return '';
        }
        
        // Si format MM/DD/YYYY (format WooTour)
        if (preg_match('/^(\d{2})\/(\d{2})\/(\d{4})$/', $date, $matches)) {
            return sprintf('%04d-%02d-%02d', $matches[3], $matches[1], $matches[2]);
        }
        
        // Si format DD/MM/YYYY
        if (preg_match('/^(\d{2})\/(\d{2})\/(\d{4})$/', $date, $matches)) {
            // Ambiguïté - on suppose MM/DD/YYYY par défaut (format WooTour)
            return sprintf('%04d-%02d-%02d', $matches[3], $matches[1], $matches[2]);
        }
        
        // Si déjà YYYY-MM-DD
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
            return $date;
        }
        
        // Essayer strtotime comme dernier recours
        $timestamp = strtotime($date);
        if ($timestamp !== false) {
            return date('Y-m-d', $timestamp);
        }
        
        error_log('[WBE WootourFieldFormatter] Cannot normalize date: ' . $date);
        return '';
    }
    
    /**
     * Extraire les dates d'un tableau formaté WooTour
     * 
     * @param array $wootourDates Tableau au format ['exc_mb-field-X' => 'MM/DD/YYYY']
     * @return array Tableau de dates YYYY-MM-DD
     */
    public static function extractDatesFromWootour(array $wootourDates): array
    {
        $dates = [];
        
        foreach ($wootourDates as $key => $dateValue) {
            if (strpos($key, 'exc_mb-field-') === 0) {
                $normalized = self::normalizeDate($dateValue);
                if ($normalized) {
                    $dates[] = $normalized;
                }
            }
        }
        
        return array_unique($dates);
    }
}