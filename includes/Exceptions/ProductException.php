<?php
/**
 * Wootour Bulk Editor - Product Exception
 * 
 * Custom exception for product-related errors
 * 
 * @package     WootourBulkEditor
 * @subpackage  Exceptions
 * @since       1.0.0
 */

namespace WootourBulkEditor\Exceptions;

use Exception;

defined('ABSPATH') || exit;

/**
 * Class ProductException
 * 
 * Custom exception for product operations
 */
class ProductException extends Exception
{
    /**
     * Exception code constants
     */
    const PRODUCT_NOT_FOUND = 1001;
    const INVALID_PRODUCT_DATA = 1002;
    const PRODUCT_UPDATE_FAILED = 1003;
    const PRODUCT_DELETE_FAILED = 1004;
    const INSUFFICIENT_PERMISSIONS = 1005;
    const INVALID_CATEGORY = 1006;
    const INVALID_SKU = 1007;
    const INVALID_PRICE = 1008;

    /**
     * Create a "product not found" exception
     * 
     * @param int $product_id Product ID
     * @return static
     */
    public static function notFound(int $product_id): self
    {
        return new self(
            sprintf(__('Product with ID %d not found', 'wootour-bulk-editor'), $product_id),
            self::PRODUCT_NOT_FOUND
        );
    }

    /**
     * Create an "invalid product data" exception
     * 
     * @param string $field Field name
     * @param string $reason Reason
     * @return static
     */
    public static function invalidData(string $field, string $reason = ''): self
    {
        $message = sprintf(__('Invalid product data for field: %s', 'wootour-bulk-editor'), $field);
        
        if ($reason) {
            $message .= ' - ' . $reason;
        }
        
        return new self($message, self::INVALID_PRODUCT_DATA);
    }

    /**
     * Create an "update failed" exception
     * 
     * @param int $product_id Product ID
     * @param string $reason Reason
     * @return static
     */
    public static function updateFailed(int $product_id, string $reason = ''): self
    {
        $message = sprintf(__('Failed to update product %d', 'wootour-bulk-editor'), $product_id);
        
        if ($reason) {
            $message .= ': ' . $reason;
        }
        
        return new self($message, self::PRODUCT_UPDATE_FAILED);
    }

    /**
     * Create a "delete failed" exception
     * 
     * @param int $product_id Product ID
     * @param string $reason Reason
     * @return static
     */
    public static function deleteFailed(int $product_id, string $reason = ''): self
    {
        $message = sprintf(__('Failed to delete product %d', 'wootour-bulk-editor'), $product_id);
        
        if ($reason) {
            $message .= ': ' . $reason;
        }
        
        return new self($message, self::PRODUCT_DELETE_FAILED);
    }

    /**
     * Create an "insufficient permissions" exception
     * 
     * @param string $action Action attempted
     * @return static
     */
    public static function insufficientPermissions(string $action = ''): self
    {
        $message = __('Insufficient permissions to perform this action', 'wootour-bulk-editor');
        
        if ($action) {
            $message .= ': ' . $action;
        }
        
        return new self($message, self::INSUFFICIENT_PERMISSIONS);
    }

    /**
     * Create an "invalid category" exception
     * 
     * @param int $category_id Category ID
     * @return static
     */
    public static function invalidCategory(int $category_id): self
    {
        return new self(
            sprintf(__('Invalid category ID: %d', 'wootour-bulk-editor'), $category_id),
            self::INVALID_CATEGORY
        );
    }

    /**
     * Create an "invalid SKU" exception
     * 
     * @param string $sku SKU
     * @param string $reason Reason
     * @return static
     */
    public static function invalidSku(string $sku, string $reason = ''): self
    {
        $message = sprintf(__('Invalid SKU: %s', 'wootour-bulk-editor'), $sku);
        
        if ($reason) {
            $message .= ' - ' . $reason;
        }
        
        return new self($message, self::INVALID_SKU);
    }

    /**
     * Create an "invalid price" exception
     * 
     * @param mixed $price Price value
     * @param string $field Price field name
     * @return static
     */
    public static function invalidPrice($price, string $field = 'price'): self
    {
        return new self(
            sprintf(__('Invalid %s: %s', 'wootour-bulk-editor'), $field, $price),
            self::INVALID_PRICE
        );
    }
}