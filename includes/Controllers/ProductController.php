<?php

/**
 * Wootour Bulk Editor - Product Controller
 * 
 * Handles ALL product and category operations including:
 * - Product CRUD operations
 * - Category management
 * - Bulk operations
 * - Product filtering and search
 * - Product metadata management
 * - Stock management
 * - Price management
 * - Image management
 * 
 * @package     WootourBulkEditor
 * @subpackage  Controllers
 * @author      Your Name <email@example.com>
 * @license     GPL-2.0+
 * @since       1.0.0
 */

namespace WootourBulkEditor\Controllers;

use WootourBulkEditor\Core\Constants;
use WootourBulkEditor\Repositories\ProductRepository;
use WootourBulkEditor\Repositories\WootourRepository;
use WootourBulkEditor\Services\SecurityService;
use WootourBulkEditor\Services\LoggerService;
use WootourBulkEditor\Traits\Singleton;
use WootourBulkEditor\Exceptions\ProductException;
use WC_Product;
use WP_Error;

// Exit if accessed directly
defined('ABSPATH') || exit;

/**
 * Class ProductController
 * 
 * Central controller for all product and category operations
 */
final class ProductController
{
    use Singleton;

    /**
     * @var ProductRepository
     */
    private ProductRepository $product_repository;

    /**
     * @var WootourRepository
     */
    private WootourRepository $wootour_repository;

    /**
     * @var SecurityService
     */
    private SecurityService $security_service;

    /**
     * @var LoggerService
     */
    private LoggerService $logger;

    /**
     * Private constructor
     */
    private function __construct()
    {
        // Dependencies will be injected via init
    }

    /**
     * Initialize the controller
     */
    public function init(): void
    {
        // Inject dependencies
        $this->product_repository = ProductRepository::getInstance();
        $this->wootour_repository = WootourRepository::getInstance();
        $this->security_service = SecurityService::getInstance();
        $this->logger = LoggerService::getInstance();

        // Register action hooks
        $this->register_hooks();
    }

    /**
     * Register WordPress hooks
     */
    private function register_hooks(): void
    {
        // Product hooks
        add_action('save_post_product', [$this, 'on_product_save'], 10, 3);
        add_action('before_delete_post', [$this, 'on_product_delete'], 10, 2);
        add_action('woocommerce_product_duplicate', [$this, 'on_product_duplicate'], 10, 2);

        // Category hooks
        add_action('created_product_cat', [$this, 'on_category_created'], 10, 2);
        add_action('edited_product_cat', [$this, 'on_category_edited'], 10, 2);
        add_action('delete_product_cat', [$this, 'on_category_deleted'], 10, 4);

        // Custom action hooks
        add_action('wbe_product_updated', [$this, 'on_wbe_product_updated'], 10, 2);
    }

    // ==========================================
    // PRODUCT RETRIEVAL OPERATIONS
    // ==========================================

    /**
     * Get a single product by ID
     * 
     * @param int $product_id Product ID
     * @return array Product data
     * @throws ProductException
     */
    public function get_product(int $product_id): array
    {
        try {
            $product_model = $this->product_repository->getProduct($product_id);

            if (!$product_model) {
                throw ProductException::notFound($product_id);
            }

            return $this->format_product_response($product_model);
        } catch (\Exception $e) {
            $this->logger->error('Failed to get product', [
                'product_id' => $product_id,
                'error' => $e->getMessage()
            ]);
            throw $e;
        }
    }

    /**
     * Get products by category
     * 
     * @param int $category_id Category ID (0 for all)
     * @param int $page Page number
     * @param int $per_page Items per page
     * @return array Products and pagination data
     */
    public function get_products_by_category(
        int $category_id = 0,
        int $page = 1,
        int $per_page = 50,
        bool $only_wootour = false
    ): array {
        try {
            // ✅ Pass the only_wootour parameter
            $products = $this->product_repository->getProductsByCategory(
                $category_id,
                $page,
                $per_page,
                $only_wootour
            );

            $pagination = $this->product_repository->getPaginationInfo($category_id, $per_page);

            return [
                'products' => array_map([$this, 'format_product_response'], $products),
                'pagination' => [
                    'current_page' => $page,
                    'per_page' => $per_page,
                    'total' => $pagination['total'],
                    'total_pages' => $pagination['total_pages'],
                    'has_more' => $page < $pagination['total_pages']
                ]
            ];
        } catch (\Exception $e) {
            $this->logger->error('Failed to get products by category', [
                'category_id' => $category_id,
                'error' => $e->getMessage()
            ]);
            throw $e;
        }
    }

    /**
     * Get products by IDs
     * 
     * @param array $product_ids Array of product IDs
     * @return array Products data
     */
    public function get_products_by_ids(array $product_ids): array
    {
        try {
            $valid_ids = $this->product_repository->validateProductIds($product_ids);
            $products = $this->product_repository->getProductsByIds($valid_ids);

            return [
                'products' => array_map([$this, 'format_product_response'], $products),
                'requested' => count($product_ids),
                'found' => count($products),
                'invalid_ids' => array_diff($product_ids, $valid_ids)
            ];
        } catch (\Exception $e) {
            $this->logger->error('Failed to get products by IDs', [
                'product_ids' => $product_ids,
                'error' => $e->getMessage()
            ]);
            throw $e;
        }
    }

    /**
     * Search products
     * 
     * @param string $search_term Search term
     * @param int $limit Max results
     * @return array Search results
     */
    public function search_products(
        string $search_term,
        int $limit = 50,
        bool $only_wootour = false
    ): array {
        try {
            // ✅ Pass the only_wootour parameter
            $products = $this->product_repository->searchProducts(
                $search_term,
                $limit,
                $only_wootour
            );

            return [
                'products' => array_map([$this, 'format_product_response'], $products),
                'search_term' => $search_term,
                'found' => count($products)
            ];
        } catch (\Exception $e) {
            $this->logger->error('Failed to search products', [
                'search_term' => $search_term,
                'error' => $e->getMessage()
            ]);
            throw $e;
        }
    }

    /**
     * Get products with Wootour data
     * 
     * @param int $limit Max results
     * @return array Products with Wootour data
     */
    public function get_products_with_wootour(int $limit = 200): array
    {
        try {
            // This method always filters by Wootour
            $products = $this->product_repository->getProductsWithWootour($limit);

            return [
                'products' => array_map([$this, 'format_product_response'], $products),
                'found' => count($products)
            ];
        } catch (\Exception $e) {
            $this->logger->error('Failed to get products with Wootour', [
                'error' => $e->getMessage()
            ]);
            throw $e;
        }
    }

    // ==========================================
    // PRODUCT CREATE/UPDATE/DELETE OPERATIONS
    // ==========================================

    /**
     * Create a new product
     * 
     * @param array $product_data Product data
     * @return array Created product data
     * @throws ProductException
     */
    public function create_product(array $product_data): array
    {
        try {
            // Validate permissions
            if (!$this->security_service->canManageProducts()) {
                throw new \Exception('Insufficient permissions to create products');
            }

            // Validate required fields
            $this->validate_product_data($product_data, true);

            // Create WooCommerce product
            $product = new \WC_Product_Simple();

            // Set basic data
            $this->set_product_properties($product, $product_data);

            // Save product
            $product_id = $product->save();

            if (!$product_id) {
                throw new \Exception('Failed to save product');
            }

            // Set additional metadata
            if (!empty($product_data['meta'])) {
                $this->update_product_meta($product_id, $product_data['meta']);
            }

            // Set categories
            if (!empty($product_data['categories'])) {
                $this->set_product_categories($product_id, $product_data['categories']);
            }

            // Set images
            if (!empty($product_data['images'])) {
                $this->set_product_images($product_id, $product_data['images']);
            }

            // Log action
            $this->logger->info('Product created', [
                'product_id' => $product_id,
                'name' => $product_data['name'] ?? ''
            ]);

            do_action('wbe_product_created', $product_id, $product_data);

            return $this->get_product($product_id);
        } catch (\Exception $e) {
            $this->logger->error('Failed to create product', [
                'data' => $product_data,
                'error' => $e->getMessage()
            ]);
            throw $e;
        }
    }

    /**
     * Update a product
     * 
     * @param int $product_id Product ID
     * @param array $product_data Updated product data
     * @return array Updated product data
     * @throws ProductException
     */
    public function update_product(int $product_id, array $product_data): array
    {
        try {
            // Validate permissions
            if (!$this->security_service->canManageProducts()) {
                throw new \Exception('Insufficient permissions to update products');
            }

            // Get existing product
            $product = wc_get_product($product_id);
            if (!$product) {
                throw ProductException::notFound($product_id);
            }

            // Validate update data
            $this->validate_product_data($product_data, false);

            // Update properties
            $this->set_product_properties($product, $product_data);

            // Save product
            $product->save();

            // Update metadata if provided
            if (isset($product_data['meta'])) {
                $this->update_product_meta($product_id, $product_data['meta']);
            }

            // Update categories if provided
            if (isset($product_data['categories'])) {
                $this->set_product_categories($product_id, $product_data['categories']);
            }

            // Update images if provided
            if (isset($product_data['images'])) {
                $this->set_product_images($product_id, $product_data['images']);
            }

            // Log action
            $this->logger->info('Product updated', [
                'product_id' => $product_id,
                'changes' => array_keys($product_data)
            ]);

            do_action('wbe_product_updated', $product_id, $product_data);

            return $this->get_product($product_id);
        } catch (\Exception $e) {
            $this->logger->error('Failed to update product', [
                'product_id' => $product_id,
                'error' => $e->getMessage()
            ]);
            throw $e;
        }
    }

    /**
     * Delete a product
     * 
     * @param int $product_id Product ID
     * @param bool $force Force delete (vs trash)
     * @return bool Success status
     * @throws ProductException
     */
    public function delete_product(int $product_id, bool $force = false): bool
    {
        try {
            // Validate permissions
            if (!$this->security_service->canManageProducts()) {
                throw new \Exception('Insufficient permissions to delete products');
            }

            $product = wc_get_product($product_id);
            if (!$product) {
                throw ProductException::notFound($product_id);
            }

            // Delete product
            $result = $product->delete($force);

            if (!$result) {
                throw new \Exception('Failed to delete product');
            }

            // Log action
            $this->logger->info('Product deleted', [
                'product_id' => $product_id,
                'force' => $force
            ]);

            do_action('wbe_product_deleted', $product_id, $force);

            return true;
        } catch (\Exception $e) {
            $this->logger->error('Failed to delete product', [
                'product_id' => $product_id,
                'error' => $e->getMessage()
            ]);
            throw $e;
        }
    }

    /**
     * Duplicate a product
     * 
     * @param int $product_id Product ID to duplicate
     * @return array Duplicated product data
     * @throws ProductException
     */
    public function duplicate_product(int $product_id): array
    {
        try {
            // Validate permissions
            if (!$this->security_service->canManageProducts()) {
                throw new \Exception('Insufficient permissions to duplicate products');
            }

            $product = wc_get_product($product_id);
            if (!$product) {
                throw ProductException::notFound($product_id);
            }

            // Use WooCommerce's duplicate functionality
            $duplicate = wc_get_product_object('simple');
            $duplicate->set_name($product->get_name() . ' (Copy)');
            $duplicate->set_slug('');

            // Copy all properties
            $duplicate->set_status('draft');
            $duplicate->set_description($product->get_description());
            $duplicate->set_short_description($product->get_short_description());
            $duplicate->set_sku($product->get_sku() . '-copy');
            $duplicate->set_price($product->get_price());
            $duplicate->set_regular_price($product->get_regular_price());
            $duplicate->set_sale_price($product->get_sale_price());

            // Save
            $new_product_id = $duplicate->save();

            // Copy categories
            $categories = wp_get_post_terms($product_id, 'product_cat', ['fields' => 'ids']);
            if (!empty($categories)) {
                wp_set_post_terms($new_product_id, $categories, 'product_cat');
            }

            // Copy images
            $image_id = $product->get_image_id();
            if ($image_id) {
                $duplicate->set_image_id($image_id);
                $duplicate->save();
            }

            // Log action
            $this->logger->info('Product duplicated', [
                'original_id' => $product_id,
                'new_id' => $new_product_id
            ]);

            do_action('wbe_product_duplicated', $product_id, $new_product_id);

            return $this->get_product($new_product_id);
        } catch (\Exception $e) {
            $this->logger->error('Failed to duplicate product', [
                'product_id' => $product_id,
                'error' => $e->getMessage()
            ]);
            throw $e;
        }
    }

    // ==========================================
    // BULK OPERATIONS
    // ==========================================

    /**
     * Bulk update products
     * 
     * @param array $product_ids Array of product IDs
     * @param array $updates Data to update
     * @return array Results of bulk update
     */
    public function bulk_update_products(array $product_ids, array $updates): array
    {
        try {
            // Validate permissions
            if (!$this->security_service->canManageProducts()) {
                throw new \Exception('Insufficient permissions for bulk operations');
            }

            $valid_ids = $this->product_repository->validateProductIds($product_ids);

            $results = [
                'success' => [],
                'failed' => [],
                'total' => count($valid_ids)
            ];

            foreach ($valid_ids as $product_id) {
                try {
                    $this->update_product($product_id, $updates);
                    $results['success'][] = $product_id;
                } catch (\Exception $e) {
                    $results['failed'][] = [
                        'product_id' => $product_id,
                        'error' => $e->getMessage()
                    ];
                }
            }

            // Log bulk action
            $this->logger->info('Bulk update completed', [
                'total' => $results['total'],
                'success' => count($results['success']),
                'failed' => count($results['failed'])
            ]);

            do_action('wbe_bulk_update_completed', $results, $updates);

            return $results;
        } catch (\Exception $e) {
            $this->logger->error('Bulk update failed', [
                'error' => $e->getMessage()
            ]);
            throw $e;
        }
    }

    /**
     * Bulk delete products
     * 
     * @param array $product_ids Array of product IDs
     * @param bool $force Force delete
     * @return array Results
     */
    public function bulk_delete_products(array $product_ids, bool $force = false): array
    {
        try {
            // Validate permissions
            if (!$this->security_service->canManageProducts()) {
                throw new \Exception('Insufficient permissions for bulk delete');
            }

            $valid_ids = $this->product_repository->validateProductIds($product_ids);

            $results = [
                'success' => [],
                'failed' => [],
                'total' => count($valid_ids)
            ];

            foreach ($valid_ids as $product_id) {
                try {
                    $this->delete_product($product_id, $force);
                    $results['success'][] = $product_id;
                } catch (\Exception $e) {
                    $results['failed'][] = [
                        'product_id' => $product_id,
                        'error' => $e->getMessage()
                    ];
                }
            }

            // Log bulk action
            $this->logger->info('Bulk delete completed', [
                'total' => $results['total'],
                'success' => count($results['success']),
                'failed' => count($results['failed']),
                'force' => $force
            ]);

            return $results;
        } catch (\Exception $e) {
            $this->logger->error('Bulk delete failed', [
                'error' => $e->getMessage()
            ]);
            throw $e;
        }
    }

    /**
     * Bulk assign categories
     * 
     * @param array $product_ids Product IDs
     * @param array $category_ids Category IDs to assign
     * @param bool $append Append or replace categories
     * @return array Results
     */
    public function bulk_assign_categories(array $product_ids, array $category_ids, bool $append = true): array
    {
        try {
            // Validate permissions
            if (!$this->security_service->canManageProducts()) {
                throw new \Exception('Insufficient permissions');
            }

            $valid_ids = $this->product_repository->validateProductIds($product_ids);

            $results = [
                'success' => [],
                'failed' => [],
                'total' => count($valid_ids)
            ];

            foreach ($valid_ids as $product_id) {
                try {
                    $this->set_product_categories($product_id, $category_ids, $append);
                    $results['success'][] = $product_id;
                } catch (\Exception $e) {
                    $results['failed'][] = [
                        'product_id' => $product_id,
                        'error' => $e->getMessage()
                    ];
                }
            }

            $this->logger->info('Bulk category assignment completed', $results);

            return $results;
        } catch (\Exception $e) {
            $this->logger->error('Bulk category assignment failed', [
                'error' => $e->getMessage()
            ]);
            throw $e;
        }
    }

    // ==========================================
    // CATEGORY OPERATIONS
    // ==========================================

    /**
     * Get all categories with counts
     * 
     * @return array Categories data
     */
    public function get_categories(): array
    {
        try {
            return $this->product_repository->getCategoriesWithCounts();
        } catch (\Exception $e) {
            $this->logger->error('Failed to get categories', [
                'error' => $e->getMessage()
            ]);
            throw $e;
        }
    }

    /**
     * Get category tree (hierarchical)
     * 
     * @return array Category tree
     */
    public function get_category_tree(): array
    {
        try {
            return $this->product_repository->getCategoryTree();
        } catch (\Exception $e) {
            $this->logger->error('Failed to get category tree', [
                'error' => $e->getMessage()
            ]);
            throw $e;
        }
    }

    /**
     * Create a new category
     * 
     * @param array $category_data Category data
     * @return array Created category
     */
    public function create_category(array $category_data): array
    {
        try {
            // Validate permissions
            if (!$this->security_service->canManageProducts()) {
                throw new \Exception('Insufficient permissions to create categories');
            }

            $args = [
                'description' => $category_data['description'] ?? '',
                'parent' => $category_data['parent'] ?? 0,
                'slug' => $category_data['slug'] ?? '',
            ];

            $result = wp_insert_term(
                $category_data['name'],
                'product_cat',
                $args
            );

            if (is_wp_error($result)) {
                throw new \Exception($result->get_error_message());
            }

            $category_id = $result['term_id'];

            // Add image if provided
            if (!empty($category_data['image_id'])) {
                update_term_meta($category_id, 'thumbnail_id', $category_data['image_id']);
            }

            $this->logger->info('Category created', [
                'category_id' => $category_id,
                'name' => $category_data['name']
            ]);

            do_action('wbe_category_created', $category_id, $category_data);

            return $this->get_category($category_id);
        } catch (\Exception $e) {
            $this->logger->error('Failed to create category', [
                'error' => $e->getMessage()
            ]);
            throw $e;
        }
    }

    /**
     * Update a category
     * 
     * @param int $category_id Category ID
     * @param array $category_data Updated data
     * @return array Updated category
     */
    public function update_category(int $category_id, array $category_data): array
    {
        try {
            // Validate permissions
            if (!$this->security_service->canManageProducts()) {
                throw new \Exception('Insufficient permissions to update categories');
            }

            $args = [];

            if (isset($category_data['name'])) {
                $args['name'] = $category_data['name'];
            }
            if (isset($category_data['slug'])) {
                $args['slug'] = $category_data['slug'];
            }
            if (isset($category_data['description'])) {
                $args['description'] = $category_data['description'];
            }
            if (isset($category_data['parent'])) {
                $args['parent'] = $category_data['parent'];
            }

            $result = wp_update_term($category_id, 'product_cat', $args);

            if (is_wp_error($result)) {
                throw new \Exception($result->get_error_message());
            }

            // Update image if provided
            if (isset($category_data['image_id'])) {
                update_term_meta($category_id, 'thumbnail_id', $category_data['image_id']);
            }

            $this->logger->info('Category updated', [
                'category_id' => $category_id
            ]);

            do_action('wbe_category_updated', $category_id, $category_data);

            return $this->get_category($category_id);
        } catch (\Exception $e) {
            $this->logger->error('Failed to update category', [
                'category_id' => $category_id,
                'error' => $e->getMessage()
            ]);
            throw $e;
        }
    }

    /**
     * Delete a category
     * 
     * @param int $category_id Category ID
     * @return bool Success
     */
    public function delete_category(int $category_id): bool
    {
        try {
            // Validate permissions
            if (!$this->security_service->canManageProducts()) {
                throw new \Exception('Insufficient permissions to delete categories');
            }

            $result = wp_delete_term($category_id, 'product_cat');

            if (is_wp_error($result)) {
                throw new \Exception($result->get_error_message());
            }

            $this->logger->info('Category deleted', [
                'category_id' => $category_id
            ]);

            do_action('wbe_category_deleted', $category_id);

            return true;
        } catch (\Exception $e) {
            $this->logger->error('Failed to delete category', [
                'category_id' => $category_id,
                'error' => $e->getMessage()
            ]);
            throw $e;
        }
    }

    /**
     * Get a single category
     * 
     * @param int $category_id Category ID
     * @return array Category data
     */
    public function get_category(int $category_id): array
    {
        $term = get_term($category_id, 'product_cat');

        if (is_wp_error($term) || !$term) {
            throw new \Exception('Category not found');
        }

        $thumbnail_id = get_term_meta($category_id, 'thumbnail_id', true);

        return [
            'id' => $term->term_id,
            'name' => $term->name,
            'slug' => $term->slug,
            'description' => $term->description,
            'parent' => $term->parent,
            'count' => $term->count,
            'image_id' => $thumbnail_id,
            'image_url' => $thumbnail_id ? wp_get_attachment_url($thumbnail_id) : '',
        ];
    }

    // ==========================================
    // STOCK MANAGEMENT
    // ==========================================

    /**
     * Update product stock
     * 
     * @param int $product_id Product ID
     * @param int|null $quantity Stock quantity (null for unlimited)
     * @param string $status Stock status (instock, outofstock, onbackorder)
     * @return array Updated product data
     */
    public function update_stock(int $product_id, ?int $quantity, string $status = 'instock'): array
    {
        try {
            $product = wc_get_product($product_id);
            if (!$product) {
                throw ProductException::notFound($product_id);
            }

            if ($quantity !== null) {
                $product->set_manage_stock(true);
                $product->set_stock_quantity($quantity);
            } else {
                $product->set_manage_stock(false);
            }

            $product->set_stock_status($status);
            $product->save();

            $this->logger->info('Stock updated', [
                'product_id' => $product_id,
                'quantity' => $quantity,
                'status' => $status
            ]);

            do_action('wbe_stock_updated', $product_id, $quantity, $status);

            return $this->get_product($product_id);
        } catch (\Exception $e) {
            $this->logger->error('Failed to update stock', [
                'product_id' => $product_id,
                'error' => $e->getMessage()
            ]);
            throw $e;
        }
    }

    /**
     * Bulk update stock
     * 
     * @param array $stock_updates Array of [product_id => [quantity, status]]
     * @return array Results
     */
    public function bulk_update_stock(array $stock_updates): array
    {
        $results = [
            'success' => [],
            'failed' => [],
            'total' => count($stock_updates)
        ];

        foreach ($stock_updates as $product_id => $stock_data) {
            try {
                $this->update_stock(
                    $product_id,
                    $stock_data['quantity'] ?? null,
                    $stock_data['status'] ?? 'instock'
                );
                $results['success'][] = $product_id;
            } catch (\Exception $e) {
                $results['failed'][] = [
                    'product_id' => $product_id,
                    'error' => $e->getMessage()
                ];
            }
        }

        return $results;
    }

    // ==========================================
    // PRICE MANAGEMENT
    // ==========================================

    /**
     * Update product pricing
     * 
     * @param int $product_id Product ID
     * @param float|null $regular_price Regular price
     * @param float|null $sale_price Sale price
     * @return array Updated product data
     */
    public function update_pricing(int $product_id, ?float $regular_price, ?float $sale_price = null): array
    {
        try {
            $product = wc_get_product($product_id);
            if (!$product) {
                throw ProductException::notFound($product_id);
            }

            if ($regular_price !== null) {
                $product->set_regular_price($regular_price);
            }

            if ($sale_price !== null) {
                $product->set_sale_price($sale_price);
            }

            $product->save();

            $this->logger->info('Pricing updated', [
                'product_id' => $product_id,
                'regular_price' => $regular_price,
                'sale_price' => $sale_price
            ]);

            do_action('wbe_pricing_updated', $product_id, $regular_price, $sale_price);

            return $this->get_product($product_id);
        } catch (\Exception $e) {
            $this->logger->error('Failed to update pricing', [
                'product_id' => $product_id,
                'error' => $e->getMessage()
            ]);
            throw $e;
        }
    }

    /**
     * Bulk update pricing
     * 
     * @param array $product_ids Product IDs
     * @param array $pricing_data Pricing updates
     * @return array Results
     */
    public function bulk_update_pricing(array $product_ids, array $pricing_data): array
    {
        $results = [
            'success' => [],
            'failed' => [],
            'total' => count($product_ids)
        ];

        foreach ($product_ids as $product_id) {
            try {
                $this->update_pricing(
                    $product_id,
                    $pricing_data['regular_price'] ?? null,
                    $pricing_data['sale_price'] ?? null
                );
                $results['success'][] = $product_id;
            } catch (\Exception $e) {
                $results['failed'][] = [
                    'product_id' => $product_id,
                    'error' => $e->getMessage()
                ];
            }
        }

        return $results;
    }

    // ==========================================
    // HELPER METHODS
    // ==========================================

    /**
     * Validate product data
     * 
     * @param array $data Product data
     * @param bool $is_create Is this for product creation
     * @throws \Exception
     */
    private function validate_product_data(array $data, bool $is_create = false): void
    {
        if ($is_create) {
            if (empty($data['name'])) {
                throw new \Exception('Product name is required');
            }
        }

        // Validate price if provided
        if (isset($data['regular_price']) && !is_numeric($data['regular_price'])) {
            throw new \Exception('Invalid regular price');
        }

        if (isset($data['sale_price']) && !is_numeric($data['sale_price'])) {
            throw new \Exception('Invalid sale price');
        }

        // Validate stock quantity if provided
        if (isset($data['stock_quantity']) && !is_numeric($data['stock_quantity'])) {
            throw new \Exception('Invalid stock quantity');
        }
    }

    /**
     * Set product properties from data array
     * 
     * @param WC_Product $product Product object
     * @param array $data Product data
     */
    private function set_product_properties(WC_Product $product, array $data): void
    {
        if (isset($data['name'])) {
            $product->set_name($data['name']);
        }

        if (isset($data['description'])) {
            $product->set_description($data['description']);
        }

        if (isset($data['short_description'])) {
            $product->set_short_description($data['short_description']);
        }

        if (isset($data['sku'])) {
            $product->set_sku($data['sku']);
        }

        if (isset($data['regular_price'])) {
            $product->set_regular_price($data['regular_price']);
        }

        if (isset($data['sale_price'])) {
            $product->set_sale_price($data['sale_price']);
        }

        if (isset($data['status'])) {
            $product->set_status($data['status']);
        }

        if (isset($data['stock_quantity'])) {
            $product->set_manage_stock(true);
            $product->set_stock_quantity($data['stock_quantity']);
        }

        if (isset($data['stock_status'])) {
            $product->set_stock_status($data['stock_status']);
        }

        if (isset($data['weight'])) {
            $product->set_weight($data['weight']);
        }

        if (isset($data['length'])) {
            $product->set_length($data['length']);
        }

        if (isset($data['width'])) {
            $product->set_width($data['width']);
        }

        if (isset($data['height'])) {
            $product->set_height($data['height']);
        }
    }

    /**
     * Update product metadata
     * 
     * @param int $product_id Product ID
     * @param array $meta Metadata to update
     */
    private function update_product_meta(int $product_id, array $meta): void
    {
        foreach ($meta as $key => $value) {
            update_post_meta($product_id, $key, $value);
        }
    }

    /**
     * Set product categories
     * 
     * @param int $product_id Product ID
     * @param array $category_ids Category IDs
     * @param bool $append Append or replace
     */
    private function set_product_categories(int $product_id, array $category_ids, bool $append = false): void
    {
        wp_set_post_terms($product_id, $category_ids, 'product_cat', $append);
    }

    /**
     * Set product images
     * 
     * @param int $product_id Product ID
     * @param array $images Image data
     */
    private function set_product_images(int $product_id, array $images): void
    {
        $product = wc_get_product($product_id);

        if (!empty($images['main'])) {
            $product->set_image_id($images['main']);
        }

        if (!empty($images['gallery'])) {
            $product->set_gallery_image_ids($images['gallery']);
        }

        $product->save();
    }

    /**
     * Format product response for API
     * 
     * @param \WootourBulkEditor\Models\Product $product_model
     * @return array
     */
    private function format_product_response($product_model): array
    {
        return $product_model->toArray();
    }

    // ==========================================
    // WEBHOOK/HOOK CALLBACKS
    // ==========================================

    /**
     * Callback when product is saved
     */
    public function on_product_save(int $post_id, \WP_Post $post, bool $update): void
    {
        if ($update) {
            $this->product_repository->clearCache($post_id);
        }
    }

    /**
     * Callback when product is deleted
     */
    public function on_product_delete(int $post_id, \WP_Post $post): void
    {
        if ($post->post_type === 'product') {
            $this->product_repository->clearCache($post_id);
        }
    }

    /**
     * Callback when product is duplicated
     */
    public function on_product_duplicate(\WC_Product $duplicate, \WC_Product $product): void
    {
        $this->logger->info('Product duplicated via WooCommerce', [
            'original_id' => $product->get_id(),
            'duplicate_id' => $duplicate->get_id()
        ]);
    }

    /**
     * Callback when category is created
     */
    public function on_category_created(int $term_id, int $tt_id): void
    {
        $this->product_repository->clearCache();
    }

    /**
     * Callback when category is edited
     */
    public function on_category_edited(int $term_id, int $tt_id): void
    {
        $this->product_repository->clearCache();
    }

    /**
     * Callback when category is deleted
     */
    public function on_category_deleted(int $term_id, int $tt_id, \WP_Term $deleted_term, array $object_ids): void
    {
        $this->product_repository->clearCache();
    }

    /**
     * Callback for custom product update action
     */
    public function on_wbe_product_updated(int $product_id, array $data): void
    {
        // Additional processing after product update
        // Can be extended by other plugins/modules
    }
}
