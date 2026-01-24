<?php
/**
 * Wootour Bulk Editor - Availability Model
 * 
 * Value object representing product availability rules
 * 
 * @package     WootourBulkEditor
 * @subpackage  Models
 * @author      Votre Nom <email@example.com>
 * @license     GPL-2.0+
 * @since       1.0.0
 */

namespace WootourBulkEditor\Models;

use WootourBulkEditor\Core\Constants;

// Exit if accessed directly
defined('ABSPATH') || exit;

/**
 * Class Availability
 * 
 * Represents the availability rules for a product
 * Immutable value object for data integrity
 */
final class Availability
{
    /**
     * Product ID
     */
    private int $product_id = 0;

    /**
     * Start date (Y-m-d)
     */
    private string $start_date = '';

    /**
     * End date (Y-m-d)
     */
    private string $end_date = '';

    /**
     * Available weekdays (0-6, where 0 is Sunday)
     */
    private array $weekdays = [];

    /**
     * Excluded dates
     */
    private array $exclusions = [];

    /**
     * Specific available dates
     */
    private array $specific_dates = [];

    /**
     * Raw data from Wootour (if any)
     */
    private array $raw_data = [];

    /**
     * Constructor
     * 
     * @param array $data Availability data
     */
    public function __construct(array $data = [])
    {
        $this->hydrate($data);
    }

    /**
     * Hydrate the object from array data
     */
    private function hydrate(array $data): void
    {
        $defaults = Constants::DEFAULT_AVAILABILITY;
        
        $this->start_date = $this->sanitizeDate($data['start_date'] ?? $defaults['start_date']);
        $this->end_date = $this->sanitizeDate($data['end_date'] ?? $defaults['end_date']);
        $this->weekdays = $this->sanitizeWeekdays($data['weekdays'] ?? $defaults['weekdays']);
        $this->exclusions = $this->sanitizeDates($data['exclusions'] ?? $defaults['exclusions']);
        $this->specific_dates = $this->sanitizeDates($data['specific'] ?? $defaults['specific']);
        
        // Store any additional raw data
        unset(
            $data['start_date'],
            $data['end_date'],
            $data['weekdays'],
            $data['exclusions'],
            $data['specific']
        );
        
        $this->raw_data = $data;
    }

    /**
     * Validate the availability data
     * 
     * @throws \InvalidArgumentException If data is invalid
     */
    public function validate(): void
    {
        // Validate dates format
        if (!empty($this->start_date) && !$this->isValidDate($this->start_date)) {
            throw new \InvalidArgumentException(
                sprintf('Invalid start date format: %s (expected Y-m-d)', $this->start_date)
            );
        }

        if (!empty($this->end_date) && !$this->isValidDate($this->end_date)) {
            throw new \InvalidArgumentException(
                sprintf('Invalid end date format: %s (expected Y-m-d)', $this->end_date)
            );
        }

        // Validate date range
        if (!empty($this->start_date) && !empty($this->end_date)) {
            $start = strtotime($this->start_date);
            $end = strtotime($this->end_date);
            
            if ($start > $end) {
                throw new \InvalidArgumentException(
                    sprintf('Start date (%s) cannot be after end date (%s)', $this->start_date, $this->end_date)
                );
            }
        }

        // Validate weekdays
        foreach ($this->weekdays as $day) {
            if (!is_numeric($day) || $day < 0 || $day > 6) {
                throw new \InvalidArgumentException(
                    sprintf('Invalid weekday: %s (must be 0-6)', $day)
                );
            }
        }

        // Validate exclusion dates
        foreach ($this->exclusions as $date) {
            if (!$this->isValidDate($date)) {
                throw new \InvalidArgumentException(
                    sprintf('Invalid exclusion date format: %s', $date)
                );
            }
        }

        // Validate specific dates
        foreach ($this->specific_dates as $date) {
            if (!$this->isValidDate($date)) {
                throw new \InvalidArgumentException(
                    sprintf('Invalid specific date format: %s', $date)
                );
            }
        }

        // Check for conflicts between specific dates and exclusions
        $conflicts = array_intersect($this->specific_dates, $this->exclusions);
        if (!empty($conflicts)) {
            throw new \InvalidArgumentException(
                sprintf('Dates cannot be both specific and excluded: %s', implode(', ', $conflicts))
            );
        }
    }

    /**
     * Check if date is valid Y-m-d format
     */
    private function isValidDate(string $date): bool
    {
        if (empty($date)) {
            return true;
        }

        $date_time = \DateTime::createFromFormat('Y-m-d', $date);
        return $date_time && $date_time->format('Y-m-d') === $date;
    }

    /**
     * Sanitize a date string
     */
    private function sanitizeDate(string $date): string
    {
        $date = trim($date);
        
        if (empty($date)) {
            return '';
        }

        // Try to convert to Y-m-d
        $timestamp = strtotime($date);
        if ($timestamp === false) {
            return '';
        }

        return date('Y-m-d', $timestamp);
    }

    /**
     * Sanitize weekdays array
     */
    private function sanitizeWeekdays($weekdays): array
    {
        if (!is_array($weekdays)) {
            if (is_string($weekdays)) {
                $weekdays = array_map('trim', explode(',', $weekdays));
            } else {
                $weekdays = [];
            }
        }

        $sanitized = [];
        foreach ($weekdays as $day) {
            $day = (int) $day;
            if ($day >= 0 && $day <= 6) {
                $sanitized[] = $day;
            }
        }

        return array_unique($sanitized);
    }

    /**
     * Sanitize dates array
     */
    private function sanitizeDates($dates): array
    {
        if (!is_array($dates)) {
            if (is_string($dates)) {
                $dates = array_map('trim', explode(',', $dates));
            } else {
                $dates = [];
            }
        }

        $sanitized = [];
        foreach ($dates as $date) {
            $sanitized_date = $this->sanitizeDate($date);
            if (!empty($sanitized_date)) {
                $sanitized[] = $sanitized_date;
            }
        }

        return array_unique($sanitized);
    }

    /**
     * Convert to array
     */
    public function toArray(): array
    {
        return [
            'start_date'    => $this->start_date,
            'end_date'      => $this->end_date,
            'weekdays'      => $this->weekdays,
            'exclusions'    => $this->exclusions,
            'specific'      => $this->specific_dates,
            'raw_data'      => $this->raw_data,
            'product_id'    => $this->product_id,
        ];
    }

    /**
     * Check if availability is empty
     */
    public function isEmpty(): bool
    {
        return empty($this->start_date) 
            && empty($this->end_date) 
            && empty($this->weekdays) 
            && empty($this->exclusions) 
            && empty($this->specific_dates);
    }

    /**
     * Get available dates within a range
     */
    public function getAvailableDates(string $start_date, string $end_date): array
    {
        $this->validate();
        
        $available = [];
        $current = strtotime($start_date);
        $end = strtotime($end_date);
        
        while ($current <= $end) {
            $date = date('Y-m-d', $current);
            
            if ($this->isDateAvailable($date)) {
                $available[] = $date;
            }
            
            $current = strtotime('+1 day', $current);
        }
        
        return $available;
    }

    /**
     * Check if a specific date is available
     */
    public function isDateAvailable(string $date): bool
    {
        $date = $this->sanitizeDate($date);
        if (empty($date)) {
            return false;
        }

        // Check exclusions first
        if (in_array($date, $this->exclusions)) {
            return false;
        }

        // Check specific dates
        if (!empty($this->specific_dates)) {
            return in_array($date, $this->specific_dates);
        }

        // Check date range
        if (!empty($this->start_date) && $date < $this->start_date) {
            return false;
        }
        
        if (!empty($this->end_date) && $date > $this->end_date) {
            return false;
        }

        // Check weekdays
        if (!empty($this->weekdays)) {
            $weekday = date('w', strtotime($date)); // 0-6, where 0 is Sunday
            return in_array((int) $weekday, $this->weekdays);
        }

        // If no rules, date is available
        return true;
    }

    /**
     * Merge with another availability object
     * Returns new object, doesn't modify current
     */
    public function merge(Availability $other): self
    {
        $current_data = $this->toArray();
        $other_data = $other->toArray();
        
        // Remove meta fields
        unset($current_data['raw_data'], $current_data['product_id']);
        unset($other_data['raw_data'], $other_data['product_id']);
        
        // Merge arrays, other takes precedence for non-empty values
        $merged_data = [];
        foreach ($current_data as $key => $value) {
            if (isset($other_data[$key]) && !$this->isEmptyValue($other_data[$key])) {
                $merged_data[$key] = $other_data[$key];
            } else {
                $merged_data[$key] = $value;
            }
        }
        
        return new self($merged_data);
    }

    /**
     * Check if value is empty
     */
    private function isEmptyValue($value): bool
    {
        if (is_null($value)) {
            return true;
        }
        
        if (is_string($value) && trim($value) === '') {
            return true;
        }
        
        if (is_array($value) && empty($value)) {
            return true;
        }
        
        return false;
    }

    /**
     * Getters
     */
    public function getProductId(): int
    {
        return $this->product_id;
    }

    public function getStartDate(): string
    {
        return $this->start_date;
    }

    public function getEndDate(): string
    {
        return $this->end_date;
    }

    public function getWeekdays(): array
    {
        return $this->weekdays;
    }

    public function getExclusions(): array
    {
        return $this->exclusions;
    }

    public function getSpecificDates(): array
    {
        return $this->specific_dates;
    }

    public function getRawData(): array
    {
        return $this->raw_data;
    }

    /**
     * Setters (immutable - return new instance)
     */
    public function withProductId(int $product_id): self
    {
        $clone = clone $this;
        $clone->product_id = $product_id;
        return $clone;
    }

    public function withStartDate(string $start_date): self
    {
        $clone = clone $this;
        $clone->start_date = $this->sanitizeDate($start_date);
        return $clone;
    }

    public function withEndDate(string $end_date): self
    {
        $clone = clone $this;
        $clone->end_date = $this->sanitizeDate($end_date);
        return $clone;
    }

    /**
     * Magic getter for backward compatibility
     */
    public function __get(string $name)
    {
        $method = 'get' . str_replace('_', '', ucwords($name, '_'));
        
        if (method_exists($this, $method)) {
            return $this->$method();
        }
        
        trigger_error(
            sprintf('Undefined property: %s::$%s', __CLASS__, $name),
            E_USER_NOTICE
        );
        
        return null;
    }

    /**
     * Prevent setting properties directly
     */
    public function __set(string $name, $value)
    {
        throw new \LogicException(
            sprintf('%s is immutable. Use with*() methods instead.', __CLASS__)
        );
    }

    /**
     * String representation
     */
    public function __toString(): string
    {
        $parts = [];
        
        if (!empty($this->start_date) && !empty($this->end_date)) {
            $parts[] = sprintf('%s to %s', $this->start_date, $this->end_date);
        }
        
        if (!empty($this->weekdays)) {
            $day_names = ['Sunday', 'Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday'];
            $selected_days = array_map(fn($day) => $day_names[$day] ?? $day, $this->weekdays);
            $parts[] = 'Days: ' . implode(', ', $selected_days);
        }
        
        if (!empty($this->exclusions)) {
            $parts[] = 'Excludes: ' . implode(', ', $this->exclusions);
        }
        
        if (!empty($this->specific_dates)) {
            $parts[] = 'Specific: ' . implode(', ', $this->specific_dates);
        }
        
        return implode('; ', $parts);
    }
}