<?php
/**
 * Wootour Bulk Editor - Date Helper
 * 
 * Utility functions for date manipulation and validation.
 * 
 * @package     WootourBulkEditor
 * @subpackage  Utilities
 * @author      Votre Nom <email@example.com>
 * @license     GPL-2.0+
 * @since       1.0.0
 */

namespace WootourBulkEditor\Utilities;

use WootourBulkEditor\Core\Constants;

// Exit if accessed directly
defined('ABSPATH') || exit;

/**
 * Class DateHelper
 * 
 * Static utility methods for date operations
 */
final class DateHelper
{
    /**
     * Weekday names
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
     * Month names
     */
    private const MONTH_NAMES = [
        1  => 'january',
        2  => 'february',
        3  => 'march',
        4  => 'april',
        5  => 'may',
        6  => 'june',
        7  => 'july',
        8  => 'august',
        9  => 'september',
        10 => 'october',
        11 => 'november',
        12 => 'december',
    ];

    /**
     * Parse and validate a date string
     */
    public static function parseDate(string $date, string $format = 'Y-m-d'): ?\DateTime
    {
        if (empty(trim($date))) {
            return null;
        }

        try {
            $date_time = \DateTime::createFromFormat($format, $date);
            
            if ($date_time && $date_time->format($format) === $date) {
                return $date_time;
            }
            
            // Try alternative parsing
            $timestamp = strtotime($date);
            if ($timestamp !== false) {
                $date_time = new \DateTime();
                $date_time->setTimestamp($timestamp);
                return $date_time;
            }
            
        } catch (\Exception $e) {
            // Invalid date
            return null;
        }

        return null;
    }

    /**
     * Format date to specified format
     */
    public static function formatDate(?\DateTime $date, string $format = 'Y-m-d'): string
    {
        if (!$date) {
            return '';
        }
        
        return $date->format($format);
    }

    /**
     * Convert date to MySQL format
     */
    public static function toMysqlDate(string $date): string
    {
        $date_time = self::parseDate($date);
        return $date_time ? $date_time->format('Y-m-d') : '';
    }

    /**
     * Convert date to display format
     */
    public static function toDisplayDate(string $date): string
    {
        $date_time = self::parseDate($date, 'Y-m-d');
        if (!$date_time) {
            return $date;
        }
        
        return $date_time->format(Constants::DATE_FORMATS['display']);
    }

    /**
     * Validate date range
     */
    public static function validateDateRange(string $start_date, string $end_date): bool
    {
        $start = self::parseDate($start_date);
        $end = self::parseDate($end_date);
        
        if (!$start || !$end) {
            return false;
        }
        
        return $start <= $end;
    }

    /**
     * Get all dates between two dates
     */
    public static function getDatesBetween(string $start_date, string $end_date, string $format = 'Y-m-d'): array
    {
        $start = self::parseDate($start_date);
        $end = self::parseDate($end_date);
        
        if (!$start || !$end || $start > $end) {
            return [];
        }
        
        $dates = [];
        $interval = new \DateInterval('P1D');
        $period = new \DatePeriod($start, $interval, $end->modify('+1 day'));
        
        foreach ($period as $date) {
            $dates[] = $date->format($format);
        }
        
        return $dates;
    }

    /**
     * Check if date is within range
     */
    public static function isDateInRange(string $date, string $start_date, string $end_date): bool
    {
        $check = self::parseDate($date);
        $start = self::parseDate($start_date);
        $end = self::parseDate($end_date);
        
        if (!$check || !$start || !$end) {
            return false;
        }
        
        return $check >= $start && $check <= $end;
    }

    /**
     * Get weekday number from date (0 = Sunday, 6 = Saturday)
     */
    public static function getWeekdayNumber(string $date): ?int
    {
        $date_time = self::parseDate($date);
        if (!$date_time) {
            return null;
        }
        
        return (int) $date_time->format('w');
    }

    /**
     * Get weekday name from date
     */
    public static function getWeekdayName(string $date): string
    {
        $weekday = self::getWeekdayNumber($date);
        
        if ($weekday === null) {
            return '';
        }
        
        return self::WEEKDAY_NAMES[$weekday] ?? '';
    }

    /**
     * Convert weekday numbers to names
     */
    public static function weekdaysToNames(array $weekdays, bool $capitalize = true): array
    {
        $names = [];
        
        foreach ($weekdays as $day) {
            if (isset(self::WEEKDAY_NAMES[$day])) {
                $name = self::WEEKDAY_NAMES[$day];
                $names[] = $capitalize ? ucfirst($name) : $name;
            }
        }
        
        return $names;
    }

    /**
     * Convert weekday names to numbers
     */
    public static function weekdaysToNumbers(array $weekday_names): array
    {
        $numbers = [];
        $name_to_number = array_flip(self::WEEKDAY_NAMES);
        
        foreach ($weekday_names as $name) {
            $normalized = strtolower(trim($name));
            if (isset($name_to_number[$normalized])) {
                $numbers[] = $name_to_number[$normalized];
            }
        }
        
        return array_unique($numbers);
    }

    /**
     * Check if date is a weekday (Monday-Friday)
     */
    public static function isWeekday(string $date): bool
    {
        $weekday = self::getWeekdayNumber($date);
        return $weekday !== null && $weekday >= 1 && $weekday <= 5;
    }

    /**
     * Check if date is a weekend
     */
    public static function isWeekend(string $date): bool
    {
        $weekday = self::getWeekdayNumber($date);
        return $weekday !== null && ($weekday === 0 || $weekday === 6);
    }

    /**
     * Get month name from date
     */
    public static function getMonthName(string $date): string
    {
        $date_time = self::parseDate($date);
        if (!$date_time) {
            return '';
        }
        
        $month = (int) $date_time->format('n');
        return self::MONTH_NAMES[$month] ?? '';
    }

    /**
     * Calculate date difference in days
     */
    public static function diffInDays(string $date1, string $date2): ?int
    {
        $d1 = self::parseDate($date1);
        $d2 = self::parseDate($date2);
        
        if (!$d1 || !$d2) {
            return null;
        }
        
        $diff = $d1->diff($d2);
        return (int) $diff->format('%r%a');
    }

    /**
     * Add days to a date
     */
    public static function addDays(string $date, int $days, string $format = 'Y-m-d'): string
    {
        $date_time = self::parseDate($date);
        if (!$date_time) {
            return '';
        }
        
        $date_time->modify("+{$days} days");
        return $date_time->format($format);
    }

    /**
     * Subtract days from a date
     */
    public static function subDays(string $date, int $days, string $format = 'Y-m-d'): string
    {
        $date_time = self::parseDate($date);
        if (!$date_time) {
            return '';
        }
        
        $date_time->modify("-{$days} days");
        return $date_time->format($format);
    }

    /**
     * Get first day of month
     */
    public static function getFirstDayOfMonth(string $date, string $format = 'Y-m-d'): string
    {
        $date_time = self::parseDate($date);
        if (!$date_time) {
            return '';
        }
        
        $date_time->modify('first day of this month');
        return $date_time->format($format);
    }

    /**
     * Get last day of month
     */
    public static function getLastDayOfMonth(string $date, string $format = 'Y-m-d'): string
    {
        $date_time = self::parseDate($date);
        if (!$date_time) {
            return '';
        }
        
        $date_time->modify('last day of this month');
        return $date_time->format($format);
    }

    /**
     * Check if date is today
     */
    public static function isToday(string $date): bool
    {
        $date_time = self::parseDate($date);
        if (!$date_time) {
            return false;
        }
        
        $today = new \DateTime();
        return $date_time->format('Y-m-d') === $today->format('Y-m-d');
    }

    /**
     * Check if date is in the past
     */
    public static function isPast(string $date): bool
    {
        $date_time = self::parseDate($date);
        if (!$date_time) {
            return false;
        }
        
        $today = new \DateTime();
        return $date_time < $today;
    }

    /**
     * Check if date is in the future
     */
    public static function isFuture(string $date): bool
    {
        $date_time = self::parseDate($date);
        if (!$date_time) {
            return false;
        }
        
        $today = new \DateTime();
        return $date_time > $today;
    }

    /**
     * Get current date in specified format
     */
    public static function now(string $format = 'Y-m-d'): string
    {
        return (new \DateTime())->format($format);
    }

    /**
     * Get tomorrow's date
     */
    public static function tomorrow(string $format = 'Y-m-d'): string
    {
        return (new \DateTime('tomorrow'))->format($format);
    }

    /**
     * Get yesterday's date
     */
    public static function yesterday(string $format = 'Y-m-d'): string
    {
        return (new \DateTime('yesterday'))->format($format);
    }

    /**
     * Get next weekday date
     */
    public static function getNextWeekday(int $weekday, string $from_date = ''): string
    {
        $from = $from_date ? self::parseDate($from_date) : new \DateTime();
        if (!$from) {
            $from = new \DateTime();
        }
        
        $current_weekday = (int) $from->format('w');
        $days_to_add = $weekday - $current_weekday;
        
        if ($days_to_add <= 0) {
            $days_to_add += 7;
        }
        
        $from->modify("+{$days_to_add} days");
        return $from->format('Y-m-d');
    }

    /**
     * Sort dates chronologically
     */
    public static function sortDates(array $dates, bool $ascending = true): array
    {
        usort($dates, function($a, $b) use ($ascending) {
            $time_a = strtotime($a);
            $time_b = strtotime($b);
            
            if ($time_a == $time_b) {
                return 0;
            }
            
            if ($ascending) {
                return ($time_a < $time_b) ? -1 : 1;
            } else {
                return ($time_a > $time_b) ? -1 : 1;
            }
        });
        
        return $dates;
    }

    /**
     * Remove duplicate dates
     */
    public static function uniqueDates(array $dates): array
    {
        $unique = [];
        
        foreach ($dates as $date) {
            $normalized = self::toMysqlDate($date);
            if ($normalized && !in_array($normalized, $unique, true)) {
                $unique[] = $normalized;
            }
        }
        
        return self::sortDates($unique);
    }

    /**
     * Check for date conflicts between arrays
     */
    public static function hasDateConflicts(array $dates1, array $dates2): bool
    {
        $normalized1 = array_map([self::class, 'toMysqlDate'], array_filter($dates1));
        $normalized2 = array_map([self::class, 'toMysqlDate'], array_filter($dates2));
        
        return !empty(array_intersect($normalized1, $normalized2));
    }

    /**
     * Get conflicting dates
     */
    public static function getDateConflicts(array $dates1, array $dates2): array
    {
        $normalized1 = array_map([self::class, 'toMysqlDate'], array_filter($dates1));
        $normalized2 = array_map([self::class, 'toMysqlDate'], array_filter($dates2));
        
        return array_intersect($normalized1, $normalized2);
    }

    /**
     * Validate date format
     */
    public static function isValidFormat(string $date, string $format = 'Y-m-d'): bool
    {
        return self::parseDate($date, $format) !== null;
    }

    /**
     * Get human readable date difference
     */
    public static function humanDiff(string $date1, string $date2): string
    {
        $d1 = self::parseDate($date1);
        $d2 = self::parseDate($date2);
        
        if (!$d1 || !$d2) {
            return '';
        }
        
        $diff = $d1->diff($d2);
        
        if ($diff->y > 0) {
            return sprintf('%d year%s', $diff->y, $diff->y > 1 ? 's' : '');
        }
        
        if ($diff->m > 0) {
            return sprintf('%d month%s', $diff->m, $diff->m > 1 ? 's' : '');
        }
        
        if ($diff->d > 0) {
            return sprintf('%d day%s', $diff->d, $diff->d > 1 ? 's' : '');
        }
        
        return 'today';
    }

    /**
     * Get date for calendar display
     */
    public static function forCalendar(string $date): array
    {
        $date_time = self::parseDate($date);
        if (!$date_time) {
            return [];
        }
        
        return [
            'year'    => (int) $date_time->format('Y'),
            'month'   => (int) $date_time->format('n'),
            'day'     => (int) $date_time->format('j'),
            'weekday' => (int) $date_time->format('w'),
            'iso'     => $date_time->format('Y-m-d'),
            'display' => self::toDisplayDate($date),
        ];
    }

    /**
     * Generate month calendar data
     */
    public static function generateMonthCalendar(int $year, int $month): array
    {
        $first_day = new \DateTime("{$year}-{$month}-01");
        $last_day = clone $first_day;
        $last_day->modify('last day of this month');
        
        $calendar = [];
        $current = clone $first_day;
        
        // Add days from previous month
        $first_weekday = (int) $first_day->format('w');
        if ($first_weekday > 0) {
            $current->modify('-' . $first_weekday . ' days');
            for ($i = 0; $i < $first_weekday; $i++) {
                $calendar[] = [
                    'date' => $current->format('Y-m-d'),
                    'day'  => (int) $current->format('j'),
                    'is_current_month' => false,
                    'is_today' => false,
                ];
                $current->modify('+1 day');
            }
        }
        
        // Add days of current month
        $current = clone $first_day;
        $today = new \DateTime();
        
        while ($current->format('Y-m') === "{$year}-" . str_pad($month, 2, '0', STR_PAD_LEFT)) {
            $calendar[] = [
                'date' => $current->format('Y-m-d'),
                'day'  => (int) $current->format('j'),
                'is_current_month' => true,
                'is_today' => $current->format('Y-m-d') === $today->format('Y-m-d'),
                'weekday' => (int) $current->format('w'),
            ];
            $current->modify('+1 day');
        }
        
        // Add days from next month
        $last_weekday = (int) $last_day->format('w');
        if ($last_weekday < 6) {
            $days_to_add = 6 - $last_weekday;
            for ($i = 1; $i <= $days_to_add; $i++) {
                $calendar[] = [
                    'date' => $current->format('Y-m-d'),
                    'day'  => (int) $current->format('j'),
                    'is_current_month' => false,
                    'is_today' => false,
                ];
                $current->modify('+1 day');
            }
        }
        
        return $calendar;
    }
}