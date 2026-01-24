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
     * Check business rules after merge
     */
    private function checkBusinessRules(Availability $existing, Availability $merged, array $changes): void
    {
        // Rule 1: Cannot have dates in both specific and exclusions
        $conflicts = array_intersect($merged->getSpecificDates(), $merged->getExclusions());
        if (!empty($conflicts)) {
            throw ValidationException::businessRuleViolation(
                sprintf(
                    'Dates cannot be both specific and excluded: %s',
                    implode(', ', $conflicts)
                )
            );
        }

        // Rule 2: If start_date changed, ensure it's before end_date
        if (isset($changes['start_date']) && !$this->isEmptyValue($changes['start_date'])) {
            if (!empty($merged->getEndDate()) && $merged->getStartDate() > $merged->getEndDate()) {
                throw ValidationException::businessRuleViolation(
                    'Start date must be before end date'
                );
            }
        }

        // Rule 3: If adding exclusions, check they're within date range
        if (isset($changes['exclusions']) && !$this->isEmptyValue($changes['exclusions'])) {
            $new_exclusions = $this->normalizeDateArray($changes['exclusions']);
            foreach ($new_exclusions as $exclusion) {
                if (!empty($merged->getStartDate()) && $exclusion < $merged->getStartDate()) {
                    throw ValidationException::businessRuleViolation(
                        sprintf('Exclusion %s is before start date %s', $exclusion, $merged->getStartDate())
                    );
                }
                if (!empty($merged->getEndDate()) && $exclusion > $merged->getEndDate()) {
                    throw ValidationException::businessRuleViolation(
                        sprintf('Exclusion %s is after end date %s', $exclusion, $merged->getEndDate())
                    );
                }
            }
        }

        // Rule 4: Weekday validation when date range is set
        if (!empty($merged->getStartDate()) && !empty($merged->getEndDate())) {
            $start_weekday = date('w', strtotime($merged->getStartDate()));
            $end_weekday = date('w', strtotime($merged->getEndDate()));

            // If weekdays are specified, ensure range makes sense
            if (!empty($merged->getWeekdays())) {
                $range_days = $this->getDaysBetween($merged->getStartDate(), $merged->getEndDate());
                $available_in_range = array_intersect($range_days, $merged->getWeekdays());

                if (empty($available_in_range)) {
                    throw ValidationException::businessRuleViolation(
                        'No selected weekdays fall within the specified date range'
                    );
                }
            }
        }
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

        $timestamp = strtotime($date);
        if ($timestamp === false) {
            return '';
        }

        return date('Y-m-d', $timestamp);
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
