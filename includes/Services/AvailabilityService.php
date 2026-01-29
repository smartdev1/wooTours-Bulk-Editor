<?php

/**
 * Wootour Bulk Editor - Availability Service
 * 
 * Core business logic for handling availability rules
 * and merging changes without overwriting existing data.
 * 
 * @package     WootourBulkEditor
 * @subpackage  Services
 * @author      Votre Nom <email@example.com>
 * @license     GPL-2.0+
 * @since       1.0.0
 */

namespace WootourBulkEditor\Services;

use WootourBulkEditor\Interfaces\ServiceInterface;
use WootourBulkEditor\Core\Constants;
use WootourBulkEditor\Models\Availability;
use WootourBulkEditor\Exceptions\ValidationException;
use WootourBulkEditor\Traits\Singleton;

// Exit if accessed directly
defined('ABSPATH') || exit;

/**
 * Class AvailabilityService
 * 
 * Service layer for availability business logic.
 * Implements the core rule: "empty fields don't overwrite"
 */
final class AvailabilityService implements ServiceInterface
{
    use Singleton;

    /**
     * Weekday names for display
     */
    private const WEEKDAY_NAMES = [
        0 => 'sunday',
        1 => 'monday',
        2 => 'tuesday',
        3 => 'wednesday',
        4 => 'thursday',
        5 => 'friday',
        6 => 'saturday',
    ];

    /**
     * Initialize the service
     */
    public function init(): void
    {
        // Service doesn't need WordPress hooks yet
    }

    /**
     * Merge changes into existing availability
     * 
     * Core business logic: Empty fields in $changes don't overwrite existing data
     * 
     * @param Availability $existing Current availability
     * @param array $changes Changes to apply (from UI form)
     * @return Availability New availability with changes merged
     * @throws ValidationException If changes are invalid
     */
    // Dans AvailabilityService.php
    public function mergeChanges(Availability $existing, array $changes): Availability
    {
        error_log('[WBE AvailabilityService] Merging changes with existing availability');
        error_log('[WBE AvailabilityService] Existing: ' . print_r($existing->toArray(), true));
        error_log('[WBE AvailabilityService] Changes: ' . print_r($changes, true));

        // Commencer avec l'objet existant
        $merged = $existing;

        // Appliquer les changements un par un (méthodes immuables)
        if (!empty($changes['start_date'])) {
            $merged = $merged->withStartDate($changes['start_date']);
        }

        if (!empty($changes['end_date'])) {
            $merged = $merged->withEndDate($changes['end_date']);
        }

        if (!empty($changes['weekdays'])) {
            // Créer un nouveau tableau de weekdays
            $current_weekdays = $merged->getWeekdays();
            $new_weekdays = array_unique(array_merge($current_weekdays, $changes['weekdays']));

            // Pour modifier les weekdays, vous aurez besoin d'une méthode withWeekdays()
            // Si elle n'existe pas, vous devrez peut-être créer un nouvel objet
            if (method_exists($merged, 'withWeekdays')) {
                $merged = $merged->withWeekdays($new_weekdays);
            } else {
                // Solution de contournement : recréer l'objet
                $data = $merged->toArray();
                $data['weekdays'] = $new_weekdays;
                $merged = new Availability($data);
            }
        }

        error_log('[WBE AvailabilityService] Merged result: ' . print_r($merged->toArray(), true));

        return $merged;
    }

    /**
     * Validate changes array structure
     * 
     * @param array $changes
     * @throws ValidationException
     */
    public function validateChanges(array $changes): void
    {
        $allowed_fields = ['start_date', 'end_date', 'weekdays', 'exclusions', 'specific'];

        // Check for unknown fields
        $unknown_fields = array_diff(array_keys($changes), $allowed_fields);
        if (!empty($unknown_fields)) {
            throw ValidationException::invalidField(
                sprintf('Unknown field(s): %s', implode(', ', $unknown_fields))
            );
        }

        // Validate each field if present
        foreach ($changes as $field => $value) {
            if (!$this->isEmptyValue($value)) {
                $method = 'validate' . str_replace('_', '', ucwords($field, '_'));
                if (method_exists($this, $method)) {
                    $this->$method($value);
                }
            }
        }
    }

    /**
     * Validate changes with detailed results (for AJAX validation)
     * 
     * @param array $changes
     * @return array Detailed validation results
     */
    public function validateChangesDetailed(array $changes): array
    {
        $results = [
            'errors' => [],
            'warnings' => [],
            'valid' => true,
            'summary' => [],
        ];

        // Validation de base
        try {
            $this->validateChanges($changes);
        } catch (ValidationException $e) {
            $results['errors'][] = $e->getMessage();
            $results['valid'] = false;
        }

        //  Validation avancée des dates

        // 1. Validation de la plage de dates (start_date vs end_date)
        if (isset($changes['start_date']) && isset($changes['end_date'])) {
            $start_timestamp = strtotime($changes['start_date']);
            $end_timestamp = strtotime($changes['end_date']);

            if ($start_timestamp && $end_timestamp) {
                // Date de fin antérieure à date de début
                if ($end_timestamp < $start_timestamp) {
                    $results['errors'][] = sprintf(
                        'La date de fin (%s) ne peut pas être antérieure à la date de début (%s).',
                        date('d/m/Y', $end_timestamp),
                        date('d/m/Y', $start_timestamp)
                    );
                    $results['valid'] = false;
                }

                // Date de début dans le passé
                $today_timestamp = strtotime(date('Y-m-d'));
                if ($start_timestamp < $today_timestamp) {
                    $results['warnings'][] = sprintf(
                        'La date de début (%s) est dans le passé.',
                        date('d/m/Y', $start_timestamp)
                    );
                }

                // Plage trop longue (optionnel - 2 ans max)
                $max_days = 730; // 2 ans
                $days_diff = ($end_timestamp - $start_timestamp) / (60 * 60 * 24);
                if ($days_diff > $max_days) {
                    $results['warnings'][] = sprintf(
                        'La plage de dates est longue (%d jours). Limite recommandée : %d jours.',
                        $days_diff,
                        $max_days
                    );
                }
            }
        }

        // 2. Vérifier les conflits entre dates spécifiques et exclusions
        if (!empty($changes['specific']) && !empty($changes['exclusions'])) {
            $specific_dates = $this->normalizeDateArray($changes['specific']);
            $exclusion_dates = $this->normalizeDateArray($changes['exclusions']);

            $conflicts = array_intersect($specific_dates, $exclusion_dates);
            if (!empty($conflicts)) {
                foreach ($conflicts as $conflict) {
                    $results['errors'][] = sprintf(
                        'La date %s est à la fois une date spécifique et une exclusion.',
                        date('d/m/Y', strtotime($conflict))
                    );
                    $results['valid'] = false;
                }
            }
        }

        // 3. Vérifier que les dates spécifiques sont dans la plage
        if (!empty($changes['specific']) && isset($changes['start_date']) && isset($changes['end_date'])) {
            $specific_dates = $this->normalizeDateArray($changes['specific']);
            $start_timestamp = strtotime($changes['start_date']);
            $end_timestamp = strtotime($changes['end_date']);

            foreach ($specific_dates as $date) {
                $date_timestamp = strtotime($date);

                if ($date_timestamp < $start_timestamp) {
                    $results['warnings'][] = sprintf(
                        'La date spécifique %s est avant la date de début.',
                        date('d/m/Y', $date_timestamp)
                    );
                }

                if ($date_timestamp > $end_timestamp) {
                    $results['warnings'][] = sprintf(
                        'La date spécifique %s est après la date de fin.',
                        date('d/m/Y', $date_timestamp)
                    );
                }
            }
        }

        // 4. Vérifier que les exclusions sont dans la plage
        if (!empty($changes['exclusions']) && isset($changes['start_date']) && isset($changes['end_date'])) {
            $exclusion_dates = $this->normalizeDateArray($changes['exclusions']);
            $start_timestamp = strtotime($changes['start_date']);
            $end_timestamp = strtotime($changes['end_date']);

            foreach ($exclusion_dates as $date) {
                $date_timestamp = strtotime($date);

                if ($date_timestamp < $start_timestamp) {
                    $results['warnings'][] = sprintf(
                        'L\'exclusion %s est avant la date de début.',
                        date('d/m/Y', $date_timestamp)
                    );
                }

                if ($date_timestamp > $end_timestamp) {
                    $results['warnings'][] = sprintf(
                        'L\'exclusion %s est après la date de fin.',
                        date('d/m/Y', $date_timestamp)
                    );
                }
            }
        }

        // 5. Vérifier que les weekdays sélectionnés sont cohérents avec la plage
        if (!empty($changes['weekdays']) && isset($changes['start_date']) && isset($changes['end_date'])) {
            $weekdays = $this->normalizeWeekdays($changes['weekdays']);
            $start_timestamp = strtotime($changes['start_date']);
            $end_timestamp = strtotime($changes['end_date']);

            $days_in_range = [];
            $current = $start_timestamp;
            while ($current <= $end_timestamp) {
                $days_in_range[] = (int) date('w', $current);
                $current = strtotime('+1 day', $current);
            }

            $available_days_in_range = array_intersect($weekdays, array_unique($days_in_range));

            if (empty($available_days_in_range)) {
                $results['warnings'][] = 'Aucun des jours sélectionnés ne se trouve dans la plage de dates.';
            }
        }

        // Générer un résumé
        $results['summary'] = $this->generateValidationSummary($changes, $results);

        return $results;
    }

    /**
     * Generate a summary of validation results
     */
    private function generateValidationSummary(array $changes, array $validation_results): array
    {
        $summary = [
            'dates_configured' => false,
            'has_errors' => !empty($validation_results['errors']),
            'has_warnings' => !empty($validation_results['warnings']),
            'error_count' => count($validation_results['errors']),
            'warning_count' => count($validation_results['warnings']),
            'details' => [],
        ];

        // Informations sur la plage de dates
        if (isset($changes['start_date']) && isset($changes['end_date'])) {
            $start_timestamp = strtotime($changes['start_date']);
            $end_timestamp = strtotime($changes['end_date']);

            if ($start_timestamp && $end_timestamp) {
                $summary['dates_configured'] = true;
                $summary['details']['date_range'] = sprintf(
                    '%s - %s (%d jours)',
                    date('d/m/Y', $start_timestamp),
                    date('d/m/Y', $end_timestamp),
                    ($end_timestamp - $start_timestamp) / (60 * 60 * 24)
                );
            }
        }

        // Informations sur les jours de la semaine
        if (!empty($changes['weekdays'])) {
            $weekday_names_fr = ['Dimanche', 'Lundi', 'Mardi', 'Mercredi', 'Jeudi', 'Vendredi', 'Samedi'];
            $weekdays = $this->normalizeWeekdays($changes['weekdays']);
            $selected_days = array_map(fn($idx) => $weekday_names_fr[$idx], $weekdays);
            $summary['details']['weekdays'] = implode(', ', $selected_days);
        }

        // Informations sur les dates spécifiques
        if (!empty($changes['specific'])) {
            $specific_dates = $this->normalizeDateArray($changes['specific']);
            $summary['details']['specific_dates_count'] = count($specific_dates);
            if (count($specific_dates) <= 5) {
                $formatted_dates = array_map(fn($date) => date('d/m/Y', strtotime($date)), $specific_dates);
                $summary['details']['specific_dates_list'] = implode(', ', $formatted_dates);
            }
        }

        // Informations sur les exclusions
        if (!empty($changes['exclusions'])) {
            $exclusion_dates = $this->normalizeDateArray($changes['exclusions']);
            $summary['details']['exclusions_count'] = count($exclusion_dates);
            if (count($exclusion_dates) <= 5) {
                $formatted_dates = array_map(fn($date) => date('d/m/Y', strtotime($date)), $exclusion_dates);
                $summary['details']['exclusions_list'] = implode(', ', $formatted_dates);
            }
        }

        return $summary;
    }

    /**
     * Check business rules after merge
     */
    private function checkBusinessRules(Availability $existing, Availability $merged, array $changes): void
    {
        // Règle existante : dates à la fois spécifiques et exclues
        $conflicts = array_intersect($merged->getSpecificDates(), $merged->getExclusions());
        if (!empty($conflicts)) {
            throw ValidationException::businessRuleViolation(
                sprintf(
                    'Dates ne peuvent pas être à la fois spécifiques et exclues : %s',
                    implode(', ', array_map(function ($date) {
                        return date('d/m/Y', strtotime($date));
                    }, $conflicts))
                )
            );
        }

        //  NOUVELLE RÈGLE : Date de fin antérieure à date de début
        if (!empty($merged->getStartDate()) && !empty($merged->getEndDate())) {
            $start_timestamp = strtotime($merged->getStartDate());
            $end_timestamp = strtotime($merged->getEndDate());

            if ($end_timestamp < $start_timestamp) {
                throw ValidationException::businessRuleViolation(
                    sprintf(
                        'La date de fin (%s) ne peut pas être antérieure à la date de début (%s).',
                        date('d/m/Y', $end_timestamp),
                        date('d/m/Y', $start_timestamp)
                    )
                );
            }

            //  Règle optionnelle : date de début dans le passé
            $today_timestamp = strtotime(date('Y-m-d'));
            if ($start_timestamp < $today_timestamp) {
                // C'est un warning, pas une erreur bloquante
                // Mais on peut le logger
                error_log(sprintf(
                    '[WBE] Warning: Start date %s is in the past',
                    date('d/m/Y', $start_timestamp)
                ));
            }
        }

        //  NOUVELLE RÈGLE : Dates spécifiques/exclusions hors plage
        if (!empty($merged->getStartDate()) && !empty($merged->getEndDate())) {
            $start_timestamp = strtotime($merged->getStartDate());
            $end_timestamp = strtotime($merged->getEndDate());

            // Vérifier les dates spécifiques
            foreach ($merged->getSpecificDates() as $date) {
                $date_timestamp = strtotime($date);
                if ($date_timestamp < $start_timestamp || $date_timestamp > $end_timestamp) {
                    throw ValidationException::businessRuleViolation(
                        sprintf(
                            'La date spécifique %s est en dehors de la plage définie.',
                            date('d/m/Y', $date_timestamp)
                        )
                    );
                }
            }

            // Vérifier les exclusions
            foreach ($merged->getExclusions() as $date) {
                $date_timestamp = strtotime($date);
                if ($date_timestamp < $start_timestamp || $date_timestamp > $end_timestamp) {
                    throw ValidationException::businessRuleViolation(
                        sprintf(
                            'L\'exclusion %s est en dehors de la plage définie.',
                            date('d/m/Y', $date_timestamp)
                        )
                    );
                }
            }
        }

        // Règle existante : vérifier que les weekdays existent dans la plage
        if (!empty($merged->getStartDate()) && !empty($merged->getEndDate()) && !empty($merged->getWeekdays())) {
            $range_days = $this->getDaysBetween($merged->getStartDate(), $merged->getEndDate());
            $available_in_range = array_intersect($range_days, $merged->getWeekdays());

            if (empty($available_in_range)) {
                throw ValidationException::businessRuleViolation(
                    'Aucun des jours de la semaine sélectionnés ne se trouve dans la plage de dates spécifiée'
                );
            }
        }
    }

    /**
     * Validate if a string is a valid date in expected format
     */
    public function isValidDate(string $date): bool
    {
        if (empty($date)) {
            return false;
        }

        // Accepter les formats DD/MM/YYYY et YYYY-MM-DD
        $timestamp = strtotime($date);
        if ($timestamp === false) {
            return false;
        }

        // Vérifier que la date est valide
        return checkdate(
            (int) date('m', $timestamp),
            (int) date('d', $timestamp),
            (int) date('Y', $timestamp)
        );
    }

    /**
     * Convert date from DD/MM/YYYY to YYYY-MM-DD
     */
    public function convertDateToDatabaseFormat(string $date): string
    {
        if (preg_match('/^(\d{2})\/(\d{2})\/(\d{4})$/', $date, $matches)) {
            return sprintf('%04d-%02d-%02d', $matches[3], $matches[2], $matches[1]);
        }

        // Si c'est déjà en YYYY-MM-DD, le retourner tel quel
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
            return $date;
        }

        // Tenter une conversion avec strtotime
        $timestamp = strtotime($date);
        if ($timestamp !== false) {
            return date('Y-m-d', $timestamp);
        }

        return '';
    }

    /**
     * Normalize a date string to Y-m-d format
     */
    private function normalizeDate($date): string
    {
        if (empty($date)) {
            return '';
        }

        if (is_string($date)) {
            $date = trim($date);
        }

        //  AMÉLIORATION : Accepter DD/MM/YYYY
        if (preg_match('/^(\d{2})\/(\d{2})\/(\d{4})$/', $date, $matches)) {
            $date = sprintf('%04d-%02d-%02d', $matches[3], $matches[2], $matches[1]);
        }

        $timestamp = strtotime($date);
        if ($timestamp === false) {
            return '';
        }

        return date('Y-m-d', $timestamp);
    }
    /**
     * Normalize changes to standard format
     */
    private function normalizeChanges(array $changes): array
    {
        $normalized = [];

        foreach ($changes as $field => $value) {
            if ($this->isEmptyValue($value)) {
                continue; // Skip empty values entirely
            }

            switch ($field) {
                case 'start_date':
                case 'end_date':
                    $normalized[$field] = $this->normalizeDate($value);
                    break;

                case 'weekdays':
                    $normalized[$field] = $this->normalizeWeekdays($value);
                    break;

                case 'exclusions':
                case 'specific':
                    $normalized[$field] = $this->normalizeDateArray($value);
                    break;

                default:
                    $normalized[$field] = $value;
            }
        }

        return $normalized;
    }

    /**
     * Apply merge rules to each field
     */
    private function applyMergeRules(array $existing, array $changes): array
    {
        $merged = $existing;

        foreach ($changes as $field => $new_value) {
            // Special handling for array fields (additive, not replacement)
            if (in_array($field, ['exclusions', 'specific', 'weekdays'])) {
                $merged[$field] = $this->mergeArrayField(
                    $existing[$field] ?? [],
                    $new_value,
                    $field
                );
            }
            // Date fields (direct replacement if not empty)
            elseif (in_array($field, ['start_date', 'end_date'])) {
                $merged[$field] = $new_value;
            }
        }

        return $merged;
    }

    /**
     * Merge array fields with special rules
     */
    private function mergeArrayField(array $existing, $new, string $field): array
    {
        if (!is_array($new)) {
            $new = [$new];
        }

        // For exclusions and specific dates: union (add new to existing)
        if (in_array($field, ['exclusions', 'specific'])) {
            $merged = array_unique(array_merge($existing, $new));

            // Sort dates chronologically
            usort($merged, function ($a, $b) {
                return strtotime($a) <=> strtotime($b);
            });

            return $merged;
        }

        // For weekdays: replacement (but only if new value is not empty)
        if ($field === 'weekdays') {
            return $new;
        }

        return $existing;
    }

    /**
     * Get all weekdays between two dates
     */
    private function getDaysBetween(string $start, string $end): array
    {
        $days = [];
        $current = strtotime($start);
        $end_time = strtotime($end);

        while ($current <= $end_time) {
            $days[] = (int) date('w', $current);
            $current = strtotime('+1 day', $current);
        }

        return array_unique($days);
    }

    /**
     * Validate start_date field
     */
    private function validateStartDate($value): void
    {
        $date = $this->normalizeDate($value);
        if (empty($date)) {
            throw ValidationException::invalidDate('start_date', $value);
        }
    }

    /**
     * Validate end_date field
     */
    private function validateEndDate($value): void
    {
        $date = $this->normalizeDate($value);
        if (empty($date)) {
            throw ValidationException::invalidDate('end_date', $value);
        }
    }

    /**
     * Validate weekdays field
     */
    private function validateWeekdays($value): void
    {
        $weekdays = $this->normalizeWeekdays($value);

        if (empty($weekdays)) {
            throw ValidationException::invalidField('weekdays must contain valid days (0-6)');
        }

        foreach ($weekdays as $day) {
            if ($day < 0 || $day > 6) {
                throw ValidationException::invalidField(
                    sprintf('Invalid weekday: %d (must be 0-6)', $day)
                );
            }
        }
    }

    /**
     * Validate exclusions field
     */
    private function validateExclusions($value): void
    {
        $dates = $this->normalizeDateArray($value);

        foreach ($dates as $date) {
            if (empty($date)) {
                throw ValidationException::invalidDate('exclusions', $value);
            }
        }
    }

    /**
     * Validate specific dates field
     */
    private function validateSpecific($value): void
    {
        $dates = $this->normalizeDateArray($value);

        foreach ($dates as $date) {
            if (empty($date)) {
                throw ValidationException::invalidDate('specific', $value);
            }
        }
    }

    /**
     * Normalize weekdays input
     */
    private function normalizeWeekdays($weekdays): array
    {
        if (is_array($weekdays)) {
            $values = $weekdays;
        } elseif (is_string($weekdays)) {
            $values = array_map('trim', explode(',', $weekdays));
        } elseif (is_numeric($weekdays)) {
            $values = [$weekdays];
        } else {
            $values = [];
        }

        $normalized = [];
        foreach ($values as $value) {
            if (is_numeric($value)) {
                $day = (int) $value;
                if ($day >= 0 && $day <= 6) {
                    $normalized[] = $day;
                }
            } elseif (is_string($value)) {
                // Try to parse weekday name
                $lower = strtolower(trim($value));
                $day_index = array_search($lower, self::WEEKDAY_NAMES, true);
                if ($day_index !== false) {
                    $normalized[] = $day_index;
                }
            }
        }

        sort($normalized);
        return array_unique($normalized);
    }

    /**
     * Normalize array of dates
     */
    private function normalizeDateArray($dates): array
    {
        if (is_array($dates)) {
            $values = $dates;
        } elseif (is_string($dates)) {
            $values = array_map('trim', explode(',', $dates));
        } else {
            $values = [$dates];
        }

        $normalized = [];
        foreach ($values as $date) {
            $normalized_date = $this->normalizeDate($date);
            if (!empty($normalized_date)) {
                $normalized[] = $normalized_date;
            }
        }

        return array_unique($normalized);
    }

    /**
     * Check if value is empty for business logic purposes
     */
    private function isEmptyValue($value): bool
    {
        if (is_null($value)) {
            return true;
        }

        if (is_string($value) && trim($value) === '') {
            return true;
        }

        if (is_array($value) && empty(array_filter($value, fn($v) => !$this->isEmptyValue($v)))) {
            return true;
        }

        return false;
    }

    /**
     * Calculate availability conflicts between changes and existing
     * 
     * @param Availability $existing
     * @param array $changes
     * @return array List of conflicts/warnings
     */
    public function calculateConflicts(Availability $existing, array $changes): array
    {
        $conflicts = [];

        try {
            $merged = $this->mergeChanges($existing, $changes);
        } catch (ValidationException $e) {
            return [['type' => 'error', 'message' => $e->getMessage()]];
        }

        // Check for overlapping exclusions with specific dates
        $overlap = array_intersect($merged->getExclusions(), $merged->getSpecificDates());
        if (!empty($overlap)) {
            $conflicts[] = [
                'type' => 'warning',
                'message' => sprintf(
                    '%d date(s) are both specific and excluded (will be treated as excluded)',
                    count($overlap)
                ),
                'dates' => $overlap,
            ];
        }

        // Check if new exclusions fall outside date range
        if (!empty($changes['exclusions']) && !$this->isEmptyValue($changes['exclusions'])) {
            $new_exclusions = $this->normalizeDateArray($changes['exclusions']);
            $existing_exclusions = $existing->getExclusions();
            $added_exclusions = array_diff($new_exclusions, $existing_exclusions);

            foreach ($added_exclusions as $exclusion) {
                if (!empty($merged->getStartDate()) && $exclusion < $merged->getStartDate()) {
                    $conflicts[] = [
                        'type' => 'warning',
                        'message' => sprintf(
                            'Exclusion %s is before start date %s',
                            $exclusion,
                            $merged->getStartDate()
                        ),
                    ];
                }
                if (!empty($merged->getEndDate()) && $exclusion > $merged->getEndDate()) {
                    $conflicts[] = [
                        'type' => 'warning',
                        'message' => sprintf(
                            'Exclusion %s is after end date %s',
                            $exclusion,
                            $merged->getEndDate()
                        ),
                    ];
                }
            }
        }

        return $conflicts;
    }

    /**
     * Format availability for display in UI
     */
    public function formatForDisplay(Availability $availability): array
    {
        $weekday_names = [];
        foreach ($availability->getWeekdays() as $day) {
            $weekday_names[] = ucfirst(self::WEEKDAY_NAMES[$day] ?? $day);
        }

        return [
            'start_date' => $availability->getStartDate(),
            'end_date' => $availability->getEndDate(),
            'weekdays' => $weekday_names,
            'exclusions' => $availability->getExclusions(),
            'specific_dates' => $availability->getSpecificDates(),
            'is_empty' => $availability->isEmpty(),
            'summary' => (string) $availability,
        ];
    }

    /**
     * Calculate preview of changes
     */
    public function calculatePreview(Availability $existing, array $changes, string $start_date, string $end_date): array
    {
        try {
            $merged = $this->mergeChanges($existing, $changes);
        } catch (ValidationException $e) {
            return [
                'success' => false,
                'error' => $e->getMessage(),
            ];
        }

        $existing_dates = $existing->getAvailableDates($start_date, $end_date);
        $new_dates = $merged->getAvailableDates($start_date, $end_date);

        $added = array_diff($new_dates, $existing_dates);
        $removed = array_diff($existing_dates, $new_dates);
        $unchanged = array_intersect($existing_dates, $new_dates);

        return [
            'success' => true,
            'existing_count' => count($existing_dates),
            'new_count' => count($new_dates),
            'added' => array_values($added),
            'removed' => array_values($removed),
            'unchanged' => array_values($unchanged),
            'summary' => sprintf(
                '%d dates total: %d added, %d removed, %d unchanged',
                count($new_dates),
                count($added),
                count($removed),
                count($unchanged)
            ),
        ];
    }

    /**
     * Get default empty changes array
     */
    public function getEmptyChanges(): array
    {
        return [
            'start_date' => '',
            'end_date' => '',
            'weekdays' => [],
            'exclusions' => [],
            'specific' => [],
        ];
    }

    /**
     * Parse form data from UI
     */
    public function parseFormData(array $form_data): array
    {
        $changes = $this->getEmptyChanges();

        foreach (array_keys($changes) as $field) {
            if (isset($form_data[$field])) {
                $changes[$field] = $form_data[$field];
            }
        }

        // Handle weekdays checkbox array
        if (isset($form_data['weekdays']) && is_array($form_data['weekdays'])) {
            $changes['weekdays'] = array_keys(array_filter($form_data['weekdays']));
        }

        return $changes;
    }

    /**
     * Check if changes will actually modify anything
     */
    public function hasEffectiveChanges(Availability $existing, array $changes): bool
    {
        if (empty(array_filter($changes, fn($v) => !$this->isEmptyValue($v)))) {
            return false;
        }

        try {
            $merged = $this->mergeChanges($existing, $changes);
            return $merged->toArray() !== $existing->toArray();
        } catch (ValidationException $e) {
            return true; // If validation fails, there are changes to process
        }
    }
}
