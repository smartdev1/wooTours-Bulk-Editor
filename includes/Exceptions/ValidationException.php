<?php
/**
 * Wootour Bulk Editor - Validation Exception
 * 
 * @package     WootourBulkEditor
 * @subpackage  Exceptions
 
 * @license     GPL-2.0+
 * @since       1.0.0
 */

namespace WootourBulkEditor\Exceptions;

use WootourBulkEditor\Core\Constants;

// Exit if accessed directly
defined('ABSPATH') || exit;

/**
 * Class ValidationException
 * 
 * Custom exception for validation errors
 */
class ValidationException extends \InvalidArgumentException
{
    /**
     * Invalid field value
     */
    public static function invalidField(string $message): self
    {
        return new self(
            $message,
            Constants::ERROR_CODES['invalid_date']
        );
    }

    /**
     * Invalid date format
     */
    public static function invalidDate(string $field, $value): self
    {
        return new self(
            sprintf('Invalid date for %s: %s', $field, $value),
            Constants::ERROR_CODES['invalid_date']
        );
    }

    /**
     * Business rule violation
     */
    public static function businessRuleViolation(string $message): self
    {
        return new self(
            $message,
            Constants::ERROR_CODES['batch_failed']
        );
    }

    /**
     * Date conflict
     */
    public static function dateConflict(string $date1, string $date2): self
    {
        return new self(
            sprintf('Date conflict between %s and %s', $date1, $date2),
            Constants::ERROR_CODES['invalid_date']
        );
    }
}