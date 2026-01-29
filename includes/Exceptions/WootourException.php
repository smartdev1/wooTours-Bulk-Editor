<?php
/**
 * Wootour Bulk Editor - Wootour Exception
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
 * Class WootourException
 * 
 * Custom exception for Wootour-related errors
 */
class WootourException extends \RuntimeException
{
    /**
     * Product not found
     */
    public static function productNotFound(int $product_id): self
    {
        return new self(
            sprintf('Product #%d not found or is not a valid WooCommerce product', $product_id),
            Constants::ERROR_CODES['invalid_product']
        );
    }

    /**
     * Data parsing failed
     */
    public static function dataParsingFailed(int $product_id, string $details): self
    {
        return new self(
            sprintf('Failed to parse availability data for product #%d: %s', $product_id, $details),
            Constants::ERROR_CODES['wootour_error']
        );
    }

    /**
     * Empty changes array
     */
    public static function emptyChanges(int $product_id): self
    {
        return new self(
            sprintf('No changes provided for product #%d', $product_id),
            Constants::ERROR_CODES['batch_failed']
        );
    }

    /**
     * Update failed
     */
    public static function updateFailed(int $product_id, string $details): self
    {
        return new self(
            sprintf('Failed to update availability for product #%d: %s', $product_id, $details),
            Constants::ERROR_CODES['batch_failed']
        );
    }

    /**
     * Wootour not active
     */
    public static function wootourNotActive(): self
    {
        return new self(
            'Wootour plugin is not active',
            Constants::ERROR_CODES['wootour_error']
        );
    }

    /**
     * Structure detection failed
     */
    public static function structureDetectionFailed(): self
    {
        return new self(
            'Could not detect Wootour data structure. Please ensure Wootour is properly installed.',
            Constants::ERROR_CODES['wootour_error']
        );
    }
}