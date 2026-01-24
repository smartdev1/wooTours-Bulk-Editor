<?php
/**
 * Wootour Bulk Editor - Product Model
 * 
 * Value object representing a WooCommerce product
 * with Wootour-specific metadata.
 * 
 * @package     WootourBulkEditor
 * @subpackage  Models
 * @author      Votre Nom <email@example.com>
 * @license     GPL-2.0+
 * @since       1.0.0
 */

namespace WootourBulkEditor\Models;

// Exit if accessed directly
defined('ABSPATH') || exit;

/**
 * Class Product
 * 
 * Represents a WooCommerce product with Wootour context
 */
final class Product
{
    /**
     * Product ID
     */
    private int $id;

    /**
     * Product name
     */
    private string $name;

    /**
     * Product SKU
     */
    private string $sku;

    /**
     * Product price
     */
    private string $price;

    /**
     * Product status
     */
    private string $status;

    /**
     * Category IDs
     */
    private array $categories;

    /**
     * Whether product has Wootour data
     */
    private bool $has_wootour;

    /**
     * Edit URL
     */
    private string $edit_url;

    /**
     * View URL
     */
    private string $view_url;

    /**
     * Image URL
     */
    private string $image_url;

    /**
     * Constructor
     */
    public function __construct(array $data)
    {
        $this->id = (int) ($data['id'] ?? 0);
        $this->name = (string) ($data['name'] ?? '');
        $this->sku = (string) ($data['sku'] ?? '');
        $this->price = (string) ($data['price'] ?? '');
        $this->status = (string) ($data['status'] ?? 'publish');
        $this->categories = (array) ($data['categories'] ?? []);
        $this->has_wootour = (bool) ($data['has_wootour'] ?? false);
        $this->edit_url = (string) ($data['edit_url'] ?? '');
        $this->view_url = (string) ($data['view_url'] ?? '');
        $this->image_url = (string) ($data['image_url'] ?? '');
    }

    /**
     * Convert to array
     */
    public function toArray(): array
    {
        return [
            'id'          => $this->id,
            'name'        => $this->name,
            'sku'         => $this->sku,
            'price'       => $this->price,
            'status'      => $this->status,
            'categories'  => $this->categories,
            'has_wootour' => $this->has_wootour,
            'edit_url'    => $this->edit_url,
            'view_url'    => $this->view_url,
            'image_url'   => $this->image_url,
        ];
    }

    /**
     * Convert to array for JSON response
     */
    public function toApiArray(): array
    {
        return [
            'id'          => $this->id,
            'name'        => html_entity_decode($this->name),
            'sku'         => $this->sku,
            'price'       => wc_price($this->price),
            'status'      => $this->status,
            'has_wootour' => $this->has_wootour,
            'edit_url'    => $this->edit_url,
            'view_url'    => $this->view_url,
            'image_url'   => $this->image_url,
        ];
    }

    /**
     * Check if product is published
     */
    public function isPublished(): bool
    {
        return $this->status === 'publish';
    }

    /**
     * Check if product is in category
     */
    public function isInCategory(int $category_id): bool
    {
        return in_array($category_id, $this->categories, true);
    }

    /**
     * Check if product has any of the given categories
     */
    public function hasAnyCategory(array $category_ids): bool
    {
        return !empty(array_intersect($this->categories, $category_ids));
    }

    /**
     * Get category names
     */
    public function getCategoryNames(): array
    {
        if (empty($this->categories)) {
            return [];
        }

        $names = [];
        foreach ($this->categories as $category_id) {
            $term = get_term($category_id, 'product_cat');
            if ($term && !is_wp_error($term)) {
                $names[] = $term->name;
            }
        }

        return $names;
    }

    /**
     * Get first category name
     */
    public function getPrimaryCategory(): string
    {
        $names = $this->getCategoryNames();
        return $names[0] ?? '';
    }

    /**
     * Getters
     */
    public function getId(): int
    {
        return $this->id;
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function getSku(): string
    {
        return $this->sku;
    }

    public function getPrice(): string
    {
        return $this->price;
    }

    public function getStatus(): string
    {
        return $this->status;
    }

    public function getCategories(): array
    {
        return $this->categories;
    }

    public function hasWootour(): bool
    {
        return $this->has_wootour;
    }

    public function getEditUrl(): string
    {
        return $this->edit_url;
    }

    public function getViewUrl(): string
    {
        return $this->view_url;
    }

    public function getImageUrl(): string
    {
        return $this->image_url;
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
        throw new \LogicException(sprintf('%s is immutable', __CLASS__));
    }

    /**
     * String representation
     */
    public function __toString(): string
    {
        return sprintf('#%d %s', $this->id, $this->name);
    }
}