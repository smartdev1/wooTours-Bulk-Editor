<?php
/**
 * Wootour Bulk Editor - Array Helper
 * 
 * Utility functions for array manipulation and validation.
 * 
 * @package     WootourBulkEditor
 * @subpackage  Utilities
 * @author      Votre Nom <email@example.com>
 * @license     GPL-2.0+
 * @since       1.0.0
 */

namespace WootourBulkEditor\Utilities;

// Exit if accessed directly
defined('ABSPATH') || exit;

/**
 * Class ArrayHelper
 * 
 * Static utility methods for array operations
 */
final class ArrayHelper
{
    /**
     * Flatten a multi-dimensional array
     */
    public static function flatten(array $array, bool $preserve_keys = false): array
    {
        $result = [];
        
        array_walk_recursive($array, function($value, $key) use (&$result, $preserve_keys) {
            if ($preserve_keys) {
                $result[$key] = $value;
            } else {
                $result[] = $value;
            }
        });
        
        return $result;
    }

    /**
     * Get a value from array with default
     */
    public static function get(array $array, $key, $default = null)
    {
        if (is_array($key)) {
            return self::getNested($array, $key, $default);
        }
        
        return $array[$key] ?? $default;
    }

    /**
     * Get nested value using dot notation
     */
    public static function getNested(array $array, $keys, $default = null)
    {
        if (is_string($keys)) {
            $keys = explode('.', $keys);
        }
        
        foreach ($keys as $key) {
            if (!is_array($array) || !array_key_exists($key, $array)) {
                return $default;
            }
            $array = $array[$key];
        }
        
        return $array;
    }

    /**
     * Set nested value using dot notation
     */
    public static function setNested(array &$array, $keys, $value): array
    {
        if (is_string($keys)) {
            $keys = explode('.', $keys);
        }
        
        $current = &$array;
        foreach ($keys as $key) {
            if (!isset($current[$key]) || !is_array($current[$key])) {
                $current[$key] = [];
            }
            $current = &$current[$key];
        }
        
        $current = $value;
        return $array;
    }

    /**
     * Check if array has nested key
     */
    public static function hasNested(array $array, $keys): bool
    {
        if (is_string($keys)) {
            $keys = explode('.', $keys);
        }
        
        foreach ($keys as $key) {
            if (!is_array($array) || !array_key_exists($key, $array)) {
                return false;
            }
            $array = $array[$key];
        }
        
        return true;
    }

    /**
     * Remove empty values from array
     */
    public static function removeEmpty(array $array, bool $recursive = true): array
    {
        foreach ($array as $key => $value) {
            if ($value === null || $value === '' || $value === []) {
                unset($array[$key]);
            } elseif ($recursive && is_array($value)) {
                $array[$key] = self::removeEmpty($value, true);
                if (empty($array[$key])) {
                    unset($array[$key]);
                }
            }
        }
        
        return $array;
    }

    /**
     * Remove falsy values (null, false, '', 0, '0')
     */
    public static function removeFalsy(array $array, bool $recursive = true): array
    {
        foreach ($array as $key => $value) {
            if (!$value) {
                unset($array[$key]);
            } elseif ($recursive && is_array($value)) {
                $array[$key] = self::removeFalsy($value, true);
                if (empty($array[$key])) {
                    unset($array[$key]);
                }
            }
        }
        
        return $array;
    }

    /**
     * Map array keys
     */
    public static function mapKeys(array $array, callable $callback): array
    {
        $result = [];
        
        foreach ($array as $key => $value) {
            $new_key = $callback($key, $value);
            $result[$new_key] = $value;
        }
        
        return $result;
    }

    /**
     * Map array values
     */
    public static function mapValues(array $array, callable $callback): array
    {
        $result = [];
        
        foreach ($array as $key => $value) {
            $result[$key] = $callback($value, $key);
        }
        
        return $result;
    }

    /**
     * Filter array by keys
     */
    public static function filterByKeys(array $array, array $keys): array
    {
        return array_intersect_key($array, array_flip($keys));
    }

    /**
     * Filter array by values
     */
    public static function filterByValues(array $array, array $values): array
    {
        return array_intersect($array, $values);
    }

    /**
     * Group array by key
     */
    public static function groupBy(array $array, $key): array
    {
        $result = [];
        
        foreach ($array as $item) {
            $key_value = is_callable($key) ? $key($item) : self::get($item, $key);
            $result[$key_value][] = $item;
        }
        
        return $result;
    }

    /**
     * Index array by key
     */
    public static function indexBy(array $array, $key): array
    {
        $result = [];
        
        foreach ($array as $item) {
            $key_value = is_callable($key) ? $key($item) : self::get($item, $key);
            $result[$key_value] = $item;
        }
        
        return $result;
    }

    /**
     * Sort array by multiple fields
     */
    public static function sortByMultiple(array $array, array $sort_spec): array
    {
        usort($array, function($a, $b) use ($sort_spec) {
            foreach ($sort_spec as $field => $direction) {
                $a_value = self::getNested($a, $field);
                $b_value = self::getNested($b, $field);
                
                if ($a_value == $b_value) {
                    continue;
                }
                
                $result = $a_value <=> $b_value;
                return $direction === SORT_DESC ? -$result : $result;
            }
            
            return 0;
        });
        
        return $array;
    }

    /**
     * Merge arrays recursively with special handling
     */
    public static function mergeRecursive(array ...$arrays): array
    {
        $result = array_shift($arrays);
        
        foreach ($arrays as $array) {
            foreach ($array as $key => $value) {
                if (is_array($value) && isset($result[$key]) && is_array($result[$key])) {
                    $result[$key] = self::mergeRecursive($result[$key], $value);
                } else {
                    $result[$key] = $value;
                }
            }
        }
        
        return $result;
    }

    /**
     * Diff arrays recursively
     */
    public static function diffRecursive(array $array1, array $array2): array
    {
        $diff = [];
        
        foreach ($array1 as $key => $value) {
            if (!array_key_exists($key, $array2)) {
                $diff[$key] = $value;
                continue;
            }
            
            if (is_array($value) && is_array($array2[$key])) {
                $sub_diff = self::diffRecursive($value, $array2[$key]);
                if (!empty($sub_diff)) {
                    $diff[$key] = $sub_diff;
                }
            } elseif ($value !== $array2[$key]) {
                $diff[$key] = $value;
            }
        }
        
        foreach ($array2 as $key => $value) {
            if (!array_key_exists($key, $array1)) {
                $diff[$key] = $value;
            }
        }
        
        return $diff;
    }

    /**
     * Check if array is associative
     */
    public static function isAssociative(array $array): bool
    {
        if ([] === $array) {
            return false;
        }
        
        return array_keys($array) !== range(0, count($array) - 1);
    }

    /**
     * Convert array to query string
     */
    public static function toQueryString(array $array, string $prefix = ''): string
    {
        $parts = [];
        
        foreach ($array as $key => $value) {
            $full_key = $prefix ? "{$prefix}[{$key}]" : $key;
            
            if (is_array($value)) {
                $parts[] = self::toQueryString($value, $full_key);
            } else {
                $parts[] = $full_key . '=' . urlencode($value);
            }
        }
        
        return implode('&', $parts);
    }

    /**
     * Pluck values from array of arrays
     */
    public static function pluck(array $array, $key): array
    {
        return array_map(function($item) use ($key) {
            return is_array($item) ? self::get($item, $key) : null;
        }, $array);
    }

    /**
     * Unique by key
     */
    public static function uniqueBy(array $array, $key): array
    {
        $seen = [];
        $result = [];
        
        foreach ($array as $item) {
            $key_value = is_callable($key) ? $key($item) : self::get($item, $key);
            
            if (!in_array($key_value, $seen, true)) {
                $seen[] = $key_value;
                $result[] = $item;
            }
        }
        
        return $result;
    }

    /**
     * Split array into chunks by condition
     */
    public static function splitBy(array $array, callable $condition): array
    {
        $true = [];
        $false = [];
        
        foreach ($array as $key => $value) {
            if ($condition($value, $key)) {
                $true[$key] = $value;
            } else {
                $false[$key] = $value;
            }
        }
        
        return [$true, $false];
    }

    /**
     * Partition array into groups
     */
    public static function partition(array $array, int $size): array
    {
        return array_chunk($array, $size, true);
    }

    /**
     * Shuffle array preserving keys
     */
    public static function shuffleAssoc(array $array): array
    {
        $keys = array_keys($array);
        shuffle($keys);
        
        $shuffled = [];
        foreach ($keys as $key) {
            $shuffled[$key] = $array[$key];
        }
        
        return $shuffled;
    }

    /**
     * Get random element from array
     */
    public static function random(array $array, int $num = 1)
    {
        if (empty($array)) {
            return $num === 1 ? null : [];
        }
        
        $keys = array_rand($array, min($num, count($array)));
        
        if ($num === 1) {
            return $array[$keys];
        }
        
        return array_intersect_key($array, array_flip((array) $keys));
    }

    /**
     * Convert array to CSV string
     */
    public static function toCsv(array $array, string $delimiter = ',', string $enclosure = '"'): string
    {
        if (empty($array)) {
            return '';
        }
        
        $output = fopen('php://temp', 'r+');
        
        // Write header if associative
        if (self::isAssociative($array)) {
            fputcsv($output, array_keys($array), $delimiter, $enclosure);
        }
        
        // Write data
        fputcsv($output, array_values($array), $delimiter, $enclosure);
        
        rewind($output);
        $csv = stream_get_contents($output);
        fclose($output);
        
        return $csv;
    }

    /**
     * Convert multi-dimensional array to CSV
     */
    public static function toCsvMulti(array $arrays, string $delimiter = ',', string $enclosure = '"'): string
    {
        if (empty($arrays)) {
            return '';
        }
        
        $output = fopen('php://temp', 'r+');
        
        // Write header from first array
        $first = reset($arrays);
        if (is_array($first) && self::isAssociative($first)) {
            fputcsv($output, array_keys($first), $delimiter, $enclosure);
        }
        
        // Write data
        foreach ($arrays as $array) {
            fputcsv($output, array_values($array), $delimiter, $enclosure);
        }
        
        rewind($output);
        $csv = stream_get_contents($output);
        fclose($output);
        
        return $csv;
    }

    /**
     * Validate array structure
     */
    public static function validateStructure(array $array, array $structure): bool
    {
        foreach ($structure as $key => $type) {
            if (!array_key_exists($key, $array)) {
                return false;
            }
            
            if (is_array($type)) {
                if (!is_array($array[$key]) || !self::validateStructure($array[$key], $type)) {
                    return false;
                }
            } elseif ($type === 'array' && !is_array($array[$key])) {
                return false;
            } elseif ($type === 'string' && !is_string($array[$key])) {
                return false;
            } elseif ($type === 'int' && !is_int($array[$key])) {
                return false;
            } elseif ($type === 'float' && !is_float($array[$key])) {
                return false;
            } elseif ($type === 'bool' && !is_bool($array[$key])) {
                return false;
            } elseif ($type === 'numeric' && !is_numeric($array[$key])) {
                return false;
            }
        }
        
        return true;
    }

    /**
     * Sanitize array values
     */
    public static function sanitize(array $array, callable $sanitizer = null): array
    {
        if ($sanitizer === null) {
            $sanitizer = function($value) {
                if (is_string($value)) {
                    return sanitize_text_field($value);
                }
                return $value;
            };
        }
        
        return self::mapValues($array, function($value) use ($sanitizer) {
            if (is_array($value)) {
                return self::sanitize($value, $sanitizer);
            }
            return $sanitizer($value);
        });
    }

    /**
     * Deep clone array
     */
    public static function clone(array $array): array
    {
        return unserialize(serialize($array));
    }

    /**
     * Get first element
     */
    public static function first(array $array)
    {
        return reset($array);
    }

    /**
     * Get last element
     */
    public static function last(array $array)
    {
        return end($array);
    }

    /**
     * Get array depth
     */
    public static function depth(array $array): int
    {
        $max_depth = 1;
        
        foreach ($array as $value) {
            if (is_array($value)) {
                $depth = self::depth($value) + 1;
                if ($depth > $max_depth) {
                    $max_depth = $depth;
                }
            }
        }
        
        return $max_depth;
    }

    /**
     * Transform array keys to camelCase
     */
    public static function camelCaseKeys(array $array): array
    {
        $result = [];
        
        foreach ($array as $key => $value) {
            $camel_key = lcfirst(str_replace(' ', '', ucwords(str_replace(['_', '-'], ' ', $key))));
            
            if (is_array($value)) {
                $result[$camel_key] = self::camelCaseKeys($value);
            } else {
                $result[$camel_key] = $value;
            }
        }
        
        return $result;
    }

    /**
     * Transform array keys to snake_case
     */
    public static function snakeCaseKeys(array $array): array
    {
        $result = [];
        
        foreach ($array as $key => $value) {
            $snake_key = strtolower(preg_replace(['/([a-z\d])([A-Z])/', '/([^_])([A-Z][a-z])/'], '$1_$2', $key));
            
            if (is_array($value)) {
                $result[$snake_key] = self::snakeCaseKeys($value);
            } else {
                $result[$snake_key] = $value;
            }
        }
        
        return $result;
    }
}