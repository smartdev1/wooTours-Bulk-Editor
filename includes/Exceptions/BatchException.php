<?php
/**
 * Wootour Bulk Editor - Batch Exception
 * 
 * @package     WootourBulkEditor
 * @subpackage  Exceptions
 * @author      Votre Nom <email@example.com>
 * @license     GPL-2.0+
 * @since       1.0.0
 */

namespace WootourBulkEditor\Exceptions;

use WootourBulkEditor\Core\Constants;

// Exit if accessed directly
defined('ABSPATH') || exit;

/**
 * Class BatchException
 * 
 * Custom exception for batch processing errors
 */
class BatchException extends \RuntimeException
{
    /**
     * Empty product list
     */
    public static function emptyProductList(): self
    {
        return new self(
            'No products selected for batch processing',
            Constants::ERROR_CODES['batch_failed']
        );
    }

    /**
     * Empty changes
     */
    public static function emptyChanges(): self
    {
        return new self(
            'No changes specified for batch processing',
            Constants::ERROR_CODES['batch_failed']
        );
    }

    /**
     * Invalid changes
     */
    public static function invalidChanges(string $details): self
    {
        return new self(
            sprintf('Invalid changes: %s', $details),
            Constants::ERROR_CODES['batch_failed']
        );
    }

    /**
     * Too many products
     */
    public static function tooManyProducts(int $count, int $max): self
    {
        return new self(
            sprintf('Too many products selected: %d (maximum: %d)', $count, $max),
            Constants::ERROR_CODES['batch_failed']
        );
    }

    /**
     * Processing failed
     */
    public static function processingFailed(string $operation_id, string $details, int $processed): self
    {
        return new self(
            sprintf('Batch processing failed for operation %s after %d products: %s', 
                $operation_id, $processed, $details),
            Constants::ERROR_CODES['batch_failed']
        );
    }

    /**
     * Timeout exceeded
     */
    public static function timeoutExceeded(int $timeout): self
    {
        return new self(
            sprintf('Batch processing timeout exceeded (%d seconds). Operation can be resumed.', $timeout),
            Constants::ERROR_CODES['batch_failed']
        );
    }

    /**
     * Cannot resume operation
     */
    public static function cannotResume(string $operation_id): self
    {
        return new self(
            sprintf('Cannot resume operation %s: resume data not found or expired', $operation_id),
            Constants::ERROR_CODES['batch_failed']
        );
    }

    /**
     * Operation already completed
     */
    public static function alreadyCompleted(string $operation_id): self
    {
        return new self(
            sprintf('Operation %s is already completed', $operation_id),
            Constants::ERROR_CODES['batch_failed']
        );
    }

    /**
     * Permission denied
     */
    public static function permissionDenied(): self
    {
        return new self(
            'You do not have permission to perform batch operations',
            Constants::ERROR_CODES['permission_denied']
        );
    }

    /**
     * Resource limit reached
     */
    public static function resourceLimit(string $resource): self
    {
        return new self(
            sprintf('Server resource limit reached: %s', $resource),
            Constants::ERROR_CODES['batch_failed']
        );
    }
}