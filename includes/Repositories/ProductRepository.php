<?php

/**
 * Wootour Bulk Editor - Product Repository
 * 
 * Handles retrieval and filtering of WooCommerce products
 * with Wootour compatibility and performance optimizations.
 * 
 * @package     WootourBulkEditor
 * @subpackage  Repositories
 * @author      Votre Nom <email@example.com>
 * @license     GPL-2.0+
 * @since       1.0.0
 */

namespace WootourBulkEditor\Repositories;

use WootourBulkEditor\Core\Constants;
use WootourBulkEditor\Repositories\RepositoryInterface;
use WootourBulkEditor\Traits\Singleton;
use WootourBulkEditor\Models\Product as ProductModel;

// Exit if accessed directly
defined('ABSPATH') || exit;

/**
 * Class ProductRepository
 * 
 * Repository for WooCommerce product operations.
 * Optimized for performance with large catalogs.
 */
final class ProductRepository implements RepositoryInterface
{
    use Singleton;

    /**
     * @var WootourRepository
     */
    private $wootour_repository;

    /**
     * Cache for product queries
     */
    private array $query_cache = [];

    /**
     * Private constructor
     */
    private function __construct()
    {
        // Will be injected via init
    }

    /**
     * Initialize repository with dependencies
     */
    public function init(): void
    {
        $this->wootour_repository = WootourRepository::getInstance();

        // Add cache clearing hooks
        add_action('save_post_product', [$this, 'clearCache'], 10, 2);
        add_action('created_product_cat', [$this, 'clearCache']);
        add_action('edited_product_cat', [$this, 'clearCache']);
        add_action('delete_product_cat', [$this, 'clearCache']);
    }

    /**
     * Get products by category with pagination
     * 
     * @param int $category_id Category ID (0 for all)
     * @param int $page Page number (1-based)
     * @param int $per_page Items per page
     * @return array Array of ProductModel objects
     */
    public function getProductsByCategory(
        int $category_id = 0,
        int $page = 1,
        int $per_page = 50,
        bool $only_wootour = false
    ): array {
        $cache_key = sprintf(
            'category_%d_page_%d_per_%d_wt_%d',
            $category_id,
            $page,
            $per_page,
            $only_wootour ? 1 : 0
        );

        if (isset($this->query_cache[$cache_key])) {
            return $this->query_cache[$cache_key];
        }

        // ✅ Build args with optional Wootour filter
        $args = $this->buildBaseQueryArgs($only_wootour);

        // Add category filter if specified
        if ($category_id > 0) {
            $args['tax_query'][] = [
                'taxonomy' => 'product_cat',
                'field'    => 'term_id',
                'terms'    => $category_id,
                'operator' => 'IN',
            ];
        }

        // Add pagination
        $args['paged'] = $page;
        $args['posts_per_page'] = $per_page;

        $products = $this->executeQuery($args);

        $this->query_cache[$cache_key] = $products;

        return $products;
    }

    /**
     * Get products by IDs
     * 
     * @param array $product_ids Array of product IDs
     * @return array Array of ProductModel objects
     */
    public function getProductsByIds(array $product_ids): array
    {
        if (empty($product_ids)) {
            return [];
        }

        $cache_key = 'ids_' . md5(implode(',', $product_ids));

        if (isset($this->query_cache[$cache_key])) {
            return $this->query_cache[$cache_key];
        }

        $args = $this->buildBaseQueryArgs();
        $args['post__in'] = $product_ids;
        $args['posts_per_page'] = -1; // Get all specified IDs
        $args['orderby'] = 'post__in'; // Preserve input order

        $products = $this->executeQuery($args);

        $this->query_cache[$cache_key] = $products;

        return $products;
    }

    /**
     * Get all products (with performance limits)
     * 
     * @param int $limit Maximum number of products to return
     * @return array Array of ProductModel objects
     */
    public function getAllProducts(int $limit = 1000): array
    {
        $cache_key = 'all_' . $limit;

        if (isset($this->query_cache[$cache_key])) {
            return $this->query_cache[$cache_key];
        }

        $args = $this->buildBaseQueryArgs();
        $args['posts_per_page'] = $limit;
        $args['paged'] = 1;

        $products = $this->executeQuery($args);

        $this->query_cache[$cache_key] = $products;

        return $products;
    }

    /**
     * Get products that have Wootour availability data
     * 
     * @param int $limit Maximum number of products
     * @return array Array of ProductModel objects
     */
    public function getProductsWithWootour(int $limit = 200): array
    {
        $cache_key = 'products_with_wootour_' . $limit;

        if (isset($this->query_cache[$cache_key])) {
            return $this->query_cache[$cache_key];
        }

        // ✅ Force Wootour filter to true
        $args = $this->buildBaseQueryArgs(true);
        $args['posts_per_page'] = $limit;

        $products = $this->executeQuery($args);

        $this->query_cache[$cache_key] = $products;

        return $products;
    }

    /**
     * Search products by name/sku
     * 
     * @param string $search_term Search term
     * @param int $limit Maximum results
     * @return array Array of ProductModel objects
     */
    public function searchProducts(
        string $search_term,
        int $limit = 50,
        bool $only_wootour = false
    ): array {
        $cache_key = sprintf(
            'search_%s_limit_%d_wt_%d',
            md5($search_term),
            $limit,
            $only_wootour ? 1 : 0
        );

        if (isset($this->query_cache[$cache_key])) {
            return $this->query_cache[$cache_key];
        }

        // ✅ Build args with optional Wootour filter
        $args = $this->buildBaseQueryArgs($only_wootour);

        $args['s'] = sanitize_text_field($search_term);
        $args['posts_per_page'] = $limit;

        $products = $this->executeQuery($args);

        $this->query_cache[$cache_key] = $products;

        return $products;
    }

    /**
     * Extend WordPress search to include SKU
     */
    public function extendProductSearch(string $search, \WP_Query $query): string
    {
        if (empty($search) || !$query->is_search()) {
            return $search;
        }

        global $wpdb;

        $search_term = $query->get('s');
        $like = '%' . $wpdb->esc_like($search_term) . '%';

        // Search in postmeta for SKU
        $sku_search = $wpdb->prepare(
            "OR EXISTS (
                SELECT 1 FROM {$wpdb->postmeta}
                WHERE post_id = {$wpdb->posts}.ID
                AND meta_key = '_sku'
                AND meta_value LIKE %s
            )",
            $like
        );

        // Add SKU search to the main search query
        $search = preg_replace(
            '/\) AND/',
            $sku_search . ') AND',
            $search
        );

        return $search;
    }

    /**
     * Get product count by category
     * 
     * @param int $category_id Category ID (0 for all)
     * @return int Number of products
     */
    public function getProductCount(int $category_id = 0, bool $only_wootour = false): int
    {
        $cache_key = 'count_category_' . $category_id . '_' . ($only_wootour ? 'wootour' : 'all');
        $cache = wp_cache_get($cache_key, 'wbe_products');

        if ($cache !== false) {
            return (int) $cache;
        }

        $args = [
            'post_type'      => 'product',
            'post_status'    => 'publish',
            'fields'         => 'ids',
            'posts_per_page' => -1,
            'nopaging'       => true,
        ];

        // Filter by Wootour compatibility only if requested
        if ($only_wootour) {
            $args['meta_query'] = $this->getWootourMetaQuery();
        }

        // Filter by category if specified
        if ($category_id > 0) {
            $args['tax_query'] = [
                [
                    'taxonomy' => 'product_cat',
                    'field'    => 'term_id',
                    'terms'    => $category_id,
                    'operator' => 'IN',
                ],
            ];
        }

        $query = new \WP_Query($args);
        $count = $query->found_posts;

        wp_cache_set($cache_key, $count, 'wbe_products', HOUR_IN_SECONDS);

        return $count;
    }

    /**
     * Get product categories with counts
     * 
     * @return array Array of categories with ID, name, count
     */
    public function getCategoriesWithCounts(bool $only_wootour = false): array
    {
        $cache_key = 'categories_with_counts_' . ($only_wootour ? 'wootour' : 'all');
        $cache = wp_cache_get($cache_key, 'wbe_products');

        if ($cache !== false) {
            return $cache;
        }

        // Get all product categories
        $categories = get_terms([
            'taxonomy'   => 'product_cat',
            'hide_empty' => false,
            'orderby'    => 'name',
            'order'      => 'ASC',
        ]);

        if (is_wp_error($categories) || empty($categories)) {
            return [];
        }

        $result = [];

        foreach ($categories as $category) {
            // Count products in this category
            $count = $this->getProductCount($category->term_id, $only_wootour);

            // Include ALL categories, even with 0 products
            $result[] = [
                'id'     => $category->term_id,
                'name'   => $category->name,
                'slug'   => $category->slug,
                'count'  => $count,
                'parent' => $category->parent,
            ];
        }

        wp_cache_set($cache_key, $result, 'wbe_products', HOUR_IN_SECONDS);

        return $result;
    }

    public function clearAllCaches(): void
    {
        // Clear category caches
        wp_cache_delete('categories_with_counts_all', 'wbe_products');
        wp_cache_delete('categories_with_counts_wootour', 'wbe_products');
        wp_cache_delete('category_tree', 'wbe_products');

        // Clear product count caches
        // Note: This is a basic implementation. For better performance,
        // you might want to track which category IDs to clear
        $categories = get_terms([
            'taxonomy'   => 'product_cat',
            'hide_empty' => false,
            'fields'     => 'ids',
        ]);

        if (!is_wp_error($categories) && !empty($categories)) {
            foreach ($categories as $cat_id) {
                wp_cache_delete('count_category_' . $cat_id . '_all', 'wbe_products');
                wp_cache_delete('count_category_' . $cat_id . '_wootour', 'wbe_products');
            }
        }

        // Clear total count
        wp_cache_delete('count_category_0_all', 'wbe_products');
        wp_cache_delete('count_category_0_wootour', 'wbe_products');
    }

    /**
     * Get product category tree (hierarchical)
     * 
     * @return array Hierarchical category tree
     */
    public function getCategoryTree(bool $only_wootour = false): array
    {
        $cache_key = 'category_tree_' . ($only_wootour ? 'wootour' : 'all');
        $cache = wp_cache_get($cache_key, 'wbe_products');

        if ($cache !== false) {
            return $cache;
        }

        // Get all categories with counts
        $categories = $this->getCategoriesWithCounts($only_wootour);

        if (empty($categories)) {
            return [];
        }

        // Build tree structure
        $tree = $this->buildCategoryTree($categories);

        wp_cache_set($cache_key, $tree, 'wbe_products', HOUR_IN_SECONDS);

        return $tree;
    }

    /**
     * Build hierarchical category tree
     */
    private function buildCategoryTree(array $categories, int $parent_id = 0): array
    {
        $tree = [];

        foreach ($categories as $category) {
            if ($category['parent'] == $parent_id) {
                // Get children recursively
                $children = $this->buildCategoryTree($categories, $category['id']);

                // Add children to category if they exist
                if (!empty($children)) {
                    $category['children'] = $children;
                } else {
                    $category['children'] = [];
                }

                $tree[] = $category;
            }
        }

        return $tree;
    }

    /**
     * Get a single product by ID
     * 
     * @param int $product_id Product ID
     * @return ProductModel|null Product model or null if not found
     */
    public function getProduct(int $product_id): ?ProductModel
    {
        $cache_key = 'product_' . $product_id;

        if (isset($this->query_cache[$cache_key])) {
            return $this->query_cache[$cache_key];
        }

        $product = wc_get_product($product_id);

        if (!$product || !$product->is_type('simple')) {
            return null;
        }

        $model = $this->createProductModel($product);

        $this->query_cache[$cache_key] = $model;

        return $model;
    }

    /**
     * Check if a product exists and is valid
     * 
     * @param int $product_id Product ID
     * @return bool True if valid product
     */
    public function isValidProduct(int $product_id): bool
    {
        $product = get_post($product_id);

        if (!$product || $product->post_type !== 'product') {
            return false;
        }

        $wc_product = wc_get_product($product_id);

        return $wc_product && $wc_product->is_type('simple');
    }

    /**
     * Build base query arguments
     */
    private function buildBaseQueryArgs(bool $only_wootour = false): array
    {
        $args = [
            'post_type'   => 'product',
            'post_status' => 'publish',
            'orderby'     => 'title',
            'order'       => 'ASC',
            'no_found_rows' => false,
            'update_post_term_cache' => true,
            'update_post_meta_cache' => true,
            'cache_results' => true,
        ];

        // ✅ Add Wootour filter only if requested
        if ($only_wootour) {
            $args['meta_query'] = $this->getWootourMetaQuery();
        }

        return $args;
    }

    /**
     * Get meta query for Wootour-compatible products
     */
    private function getWootourMetaQuery(): array
    {
        // We want products that either have Wootour data
        // OR could have Wootour data (all simple products)
        return [
            'relation' => 'OR',
            // Products with Wootour metadata
            [
                'key'     => $this->wootour_repository->getMetaKey(),
                'compare' => 'EXISTS',
            ],
            // OR simple products that could use Wootour
            [
                'key'     => '_virtual',
                'value'   => 'no',
                'compare' => '=',
            ],
        ];
    }

    /**
     * Execute query and convert to ProductModel objects
     */
    private function executeQuery(array $args): array
    {
        $query = new \WP_Query($args);

        if (!$query->have_posts()) {
            return [];
        }

        $products = [];

        foreach ($query->posts as $post) {
            $wc_product = wc_get_product($post);

            if (!$wc_product || !$wc_product->is_type('simple')) {
                continue;
            }

            $model = $this->createProductModel($wc_product);
            $products[] = $model;
        }

        wp_reset_postdata();

        return $products;
    }

    /**
     * Create ProductModel from WC_Product
     */
    private function createProductModel(\WC_Product $product): ProductModel
    {
        $product_id = $product->get_id();

        // Get product categories
        $categories = wp_get_post_terms($product_id, 'product_cat', [
            'fields' => 'ids',
        ]);

        if (is_wp_error($categories)) {
            $categories = [];
        }

        // Check if product has Wootour data
        $has_wootour = metadata_exists('post', $product_id, $this->wootour_repository->getMetaKey());

        return new ProductModel([
            'id'           => $product_id,
            'name'         => $product->get_name(),
            'sku'          => $product->get_sku(),
            'price'        => $product->get_price(),
            'status'       => $product->get_status(),
            'categories'   => $categories,
            'has_wootour'  => $has_wootour,
            'edit_url'     => get_edit_post_link($product_id, ''),
            'view_url'     => get_permalink($product_id),
            'image_url'    => $this->getProductImageUrl($product),
        ]);
    }

    /**
     * Get product image URL
     */
    private function getProductImageUrl(\WC_Product $product): string
    {
        $image_id = $product->get_image_id();

        if ($image_id) {
            $image = wp_get_attachment_image_url($image_id, 'thumbnail');
            if ($image) {
                return $image;
            }
        }

        return wc_placeholder_img_src('thumbnail');
    }

    /**
     * Clear repository cache
     * 
     * @param int $product_id Optional specific product ID
     */
    public function clearCache(int $product_id = 0): void
    {
        if ($product_id > 0) {
            // Clear specific product cache
            unset($this->query_cache['product_' . $product_id]);

            // Clear any cache entries containing this product ID
            foreach ($this->query_cache as $key => $value) {
                if (strpos($key, 'ids_') === 0) {
                    // Check if this cached result contains the product
                    foreach ($value as $product) {
                        if ($product->getId() === $product_id) {
                            unset($this->query_cache[$key]);
                            break;
                        }
                    }
                }
            }
        } else {
            // Clear all cache
            $this->query_cache = [];
        }

        // Clear WordPress object cache
        wp_cache_flush();
    }

    /**
     * Get pagination info for a query
     * 
     * @param int $category_id Category ID
     * @param int $per_page Items per page
     * @return array Pagination data
     */
    public function getPaginationInfo(int $category_id = 0, int $per_page = 50): array
    {
        $total = $this->getProductCount($category_id);
        $pages = $per_page > 0 ? ceil($total / $per_page) : 0;

        return [
            'total'       => $total,
            'per_page'    => $per_page,
            'total_pages' => $pages,
            'has_more'    => $total > $per_page,
        ];
    }

    /**
     * Validate and sanitize product IDs
     * 
     * @param array $product_ids Array of product IDs
     * @return array Valid product IDs
     */
    public function validateProductIds(array $product_ids): array
    {
        if (empty($product_ids)) {
            return [];
        }

        // Remove duplicates and non-numeric values
        $product_ids = array_unique(array_filter($product_ids, 'is_numeric'));

        if (empty($product_ids)) {
            return [];
        }

        // Check which IDs actually exist
        global $wpdb;
        $placeholders = implode(',', array_fill(0, count($product_ids), '%d'));

        $valid_ids = $wpdb->get_col($wpdb->prepare(
            "SELECT ID FROM {$wpdb->posts} 
            WHERE ID IN ($placeholders) 
            AND post_type = 'product' 
            AND post_status = 'publish'",
            ...$product_ids
        ));

        return array_map('intval', $valid_ids);
    }

    /**
     * Get products in batches (for BatchProcessor)
     * 
     * @param array $product_ids Array of product IDs
     * @param int $batch_size Size of each batch
     * @return \Generator Yields batches of ProductModel objects
     */
    public function getProductsInBatches(array $product_ids, int $batch_size = Constants::BATCH_SIZE): \Generator
    {
        $valid_ids = $this->validateProductIds($product_ids);

        if (empty($valid_ids)) {
            return;
        }

        $batches = array_chunk($valid_ids, $batch_size);

        foreach ($batches as $batch_ids) {
            $products = $this->getProductsByIds($batch_ids);
            yield $products;
        }
    }
}
