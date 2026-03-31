<?php

namespace WBE\Services;

use WBE\Wootour\AvailabilityParser;
use WBE\Wootour\MetaRepository;

/**
 * Service de validation métier
 * Vérifie la cohérence des données avant application
 * 
 * VERSION CORRIGÉE - 2026-02-07
 * FIX : Gestion correcte de specific_dates (au pluriel) au lieu de specific_date
 */
class ValidationService
{
    /**
     * Valide les données d'entrée avant traitement
     * 
     * @param array $input Données brutes du formulaire
     * @return array ['valid' => bool, 'errors' => array, 'data' => array]
     */
    public static function validate_input(array $input): array
    {
        $errors = [];
        $data = [];

        // Validation de la date de début
        if (!empty($input['start_date'])) {
            if (!AvailabilityParser::is_valid_date($input['start_date'])) {
                $errors[] = "Date de début invalide : {$input['start_date']}";
            } else {
                $data['start_date'] = $input['start_date'];
            }
        }

        // Validation de la date de fin
        if (!empty($input['end_date'])) {
            if (!AvailabilityParser::is_valid_date($input['end_date'])) {
                $errors[] = "Date de fin invalide : {$input['end_date']}";
            } else {
                $data['end_date'] = $input['end_date'];
            }
        }

        // Vérification de cohérence start < end
        if (isset($data['start_date']) && isset($data['end_date'])) {
            if (strtotime($data['start_date']) > strtotime($data['end_date'])) {
                $errors[] = "La date de début doit être antérieure à la date de fin";
            }
        }

        // Validation des jours disponibles
        if (!empty($input['available_days']) && is_array($input['available_days'])) {
            $normalized_days = AvailabilityParser::normalize_days($input['available_days']);
            
            if (empty($normalized_days)) {
                $errors[] = "Aucun jour valide fourni";
            } else {
                $data['available_days'] = $normalized_days;
            }
        }

        // Validation des dates exclues
        if (!empty($input['unavailable_dates']) && is_array($input['unavailable_dates'])) {
            $normalized_dates = AvailabilityParser::normalize_dates($input['unavailable_dates']);
            
            if (empty($normalized_dates)) {
                $errors[] = "Aucune date d'exclusion valide fournie";
            } else {
                $data['unavailable_dates'] = $normalized_dates;
            }
        }

        // CORRECTION : Validation des dates spécifiques (PLURIEL)
        // Le formulaire JavaScript envoie specific_dates (array de dates)
        if (!empty($input['specific_dates']) && is_array($input['specific_dates'])) {
            $validated_specific_dates = [];
            
            foreach ($input['specific_dates'] as $date) {
                // Vérifier que c'est une date valide
                if (AvailabilityParser::is_valid_date($date)) {
                    // Format attendu par AvailabilityUpdater : ['2026-04-10' => [], '2026-04-15' => []]
                    $validated_specific_dates[$date] = [];
                } else {
                    $errors[] = "Date spécifique invalide : {$date}";
                }
            }
            
            if (!empty($validated_specific_dates)) {
                $data['specific_dates'] = $validated_specific_dates;
            }
        }
        
        // LEGACY : Support de specific_date (singulier) pour rétrocompatibilité
        // Utilisé si on veut ajouter UNE SEULE date spécifique via un autre formulaire
        elseif (!empty($input['specific_date'])) {
            if (!AvailabilityParser::is_valid_date($input['specific_date'])) {
                $errors[] = "Date spécifique invalide : {$input['specific_date']}";
            } else {
                $data['specific_dates'] = [$input['specific_date'] => []];
            }
        }

        return [
            'valid' => empty($errors),
            'errors' => $errors,
            'data' => $data
        ];
    }

    /**
     * Vérifie les conflits potentiels pour un produit
     * 
     * @param int $product_id
     * @param array $changes Modifications à appliquer
     * @return array Liste des conflits détectés
     */
    public static function check_conflicts(int $product_id, array $changes): array
    {
        $conflicts = [];

        // Récupérer les données actuelles
        $current = MetaRepository::get_all_availability($product_id);

        // Conflit : date spécifique dans les exclusions
        if (!empty($changes['specific_dates']) && !empty($current['unavailable_dates'])) {
            $specific_keys = array_keys($changes['specific_dates']);
            $conflicts_found = array_intersect($specific_keys, $current['unavailable_dates']);
            
            if (!empty($conflicts_found)) {
                $conflicts[] = "Dates spécifiques en conflit avec dates exclues : " . implode(', ', $conflicts_found);
            }
        }

        // Conflit : nouvelle exclusion sur date spécifique existante
        if (!empty($changes['unavailable_dates']) && !empty($current['specific_dates'])) {
            $conflicts_found = array_intersect($changes['unavailable_dates'], array_keys($current['specific_dates']));
            
            if (!empty($conflicts_found)) {
                $conflicts[] = "Exclusions en conflit avec dates spécifiques existantes : " . implode(', ', $conflicts_found);
            }
        }

        // Conflit : dates hors plage start/end
        if (!empty($changes['unavailable_dates'])) {
            $start = $changes['start_date'] ?? $current['start_date'];
            $end = $changes['end_date'] ?? $current['end_date'];

            foreach ($changes['unavailable_dates'] as $date) {
                if (!AvailabilityParser::is_date_in_range($date, $start, $end)) {
                    $conflicts[] = "Date d'exclusion hors plage : {$date}";
                }
            }
        }

        // NOUVEAU : Conflit dates spécifiques hors plage start/end
        if (!empty($changes['specific_dates'])) {
            $start = $changes['start_date'] ?? $current['start_date'];
            $end = $changes['end_date'] ?? $current['end_date'];

            foreach (array_keys($changes['specific_dates']) as $date) {
                if (!AvailabilityParser::is_date_in_range($date, $start, $end)) {
                    $conflicts[] = "Date spécifique hors plage : {$date}";
                }
            }
        }

        return $conflicts;
    }

    /**
     * Valide qu'au moins une modification est demandée
     * 
     * @param array $data Données normalisées
     * @return bool
     */
    public static function has_changes(array $data): bool
    {
        return !empty($data);
    }
}