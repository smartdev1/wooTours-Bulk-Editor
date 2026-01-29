<?php
/**
 * Wootour Bulk Editor - Product Helper
 * 
 * Utility functions for WooCommerce product operations.
 * 
 * @package     WootourBulkEditor
 * @subpackage  Utilities
 
 * @license     GPL-2.0+
 * @since       1.0.0
 */

namespace WootourBulkEditor\Utilities;

// Exit if accessed directly
defined('ABSPATH') || exit;

/**
 * Class ProductHelper
 * 
 * Static utility methods for product operations
 */
final class ProductHelper
{
    /**
     * Check if product exists and is valid
     */
    public static function isValidProduct(int $product_id): bool
    {
        if ($product_id <= 0) {
            return false;
        }
        
        $product = get_post($product_id);
        
        if (!$product || $product->post_type !== 'product') {
            return false;
        }
        
        $wc_product = wc_get_product($product_id);
        
        return $wc_product && $wc_product->is_type('simple');
    }

    /**
     * Get product categories with hierarchy
     */
    public static function getProductCategories(int $product_id, bool $include_parents = true): array
    {
        $categories = wp_get_post_terms($product_id, 'product_cat', [
            'fields' => 'ids',
        ]);
        
        if (is_wp_error($categories) || empty($categories)) {
            return [];
        }
        
        if ($include_parents) {
            $all_categories = [];
            foreach ($categories as $category_id) {
                $all_categories[] = $category_id;
                $all_categories = array_merge($all_categories, self::getCategoryParents($category_id));
            }
            $categories = array_unique($all_categories);
        }
        
        return $categories;
    }

    /**
     * Get parent categories for a category
     */
    public static function getCategoryParents(int $category_id): array
    {
        $parents = [];
        $current = $category_id;
        
        while ($current) {
            $term = get_term($current, 'product_cat');
            if (!$term || is_wp_error($term) || $term->parent == 0) {
                break;
            }
            
            $current = $term->parent;
            $parents[] = $current;
        }
        
        return $parents;
    }

    /**
     * Get product category tree
     */
    public static function getCategoryTree(int $product_id): array
    {
        $categories = self::getProductCategories($product_id, true);
        $tree = [];
        
        foreach ($categories as $category_id) {
            $term = get_term($category_id, 'product_cat');
            if ($term && !is_wp_error($term)) {
                $tree[] = [
                    'id'     => $term->term_id,
                    'name'   => $term->name,
                    'slug'   => $term->slug,
                    'parent' => $term->parent,
                ];
            }
        }
        
        return $tree;
    }

    /**
     * Check if product is in category
     */
    public static function isInCategory(int $product_id, int $category_id): bool
    {
        $categories = self::getProductCategories($product_id, true);
        return in_array($category_id, $categories, true);
    }

    /**
     * Check if product is in any of the given categories
     */
    public static function isInAnyCategory(int $product_id, array $category_ids): bool
    {
        $categories = self::getProductCategories($product_id, true);
        return !empty(array_intersect($categories, $category_ids));
    }

    /**
     * Get product SKU
     */
    public static function getProductSku(int $product_id): string
    {
        $product = wc_get_product($product_id);
        return $product ? $product->get_sku() : '';
    }

    /**
     * Get product name
     */
    public static function getProductName(int $product_id): string
    {
        $product = wc_get_product($product_id);
        return $product ? $product->get_name() : '';
    }

    /**
     * Get product price
     */
    public static function getProductPrice(int $product_id): string
    {
        $product = wc_get_product($product_id);
        return $product ? $product->get_price() : '';
    }

    /**
     * Get product status
     */
    public static function getProductStatus(int $product_id): string
    {
        $product = get_post($product_id);
        return $product ? $product->post_status : '';
    }

    /**
     * Check if product is published
     */
    public static function isProductPublished(int $product_id): bool
    {
        return self::getProductStatus($product_id) === 'publish';
    }

    /**
     * Get product edit URL
     */
    public static function getProductEditUrl(int $product_id): string
    {
        return get_edit_post_link($product_id, '');
    }

    /**
     * Get product view URL
     */
    public static function getProductViewUrl(int $product_id): string
    {
        return get_permalink($product_id);
    }

    /**
     * Get product image URL
     */
    public static function getProductImageUrl(int $product_id, string $size = 'thumbnail'): string
    {
        $product = wc_get_product($product_id);
        
        if (!$product) {
            return wc_placeholder_img_src($size);
        }
        
        $image_id = $product->get_image_id();
        
        if ($image_id) {
            $image = wp_get_attachment_image_url($image_id, $size);
            if ($image) {
                return $image;
            }
        }
        
        return wc_placeholder_img_src($size);
    }

    /**
     * Get products by category
     */
    public static function getProductsByCategory(
        int $category_id, 
        int $page = 1, 
        int $per_page = 50
    ): array {
        $args = [
            'post_type'      => 'product',
            'post_status'    => 'publish',
            'posts_per_page' => $per_page,
            'paged'          => $page,
            'orderby'        => 'title',
            'order'          => 'ASC',
        ];
        
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
        $products = [];
        
        foreach ($query->posts as $post) {
            $product = wc_get_product($post);
            if ($product && $product->is_type('simple')) {
                $products[] = [
                    'id'        => $product->get_id(),
                    'name'      => $product->get_name(),
                    'sku'       => $product->get_sku(),
                    'price'     => $product->get_price(),
                    'status'    => $post->post_status,
                ];
            }
        }
        
        wp_reset_postdata();
        
        return $products;
    }

    /**
     * Get product count by category
     */
    public static function getProductCountByCategory(int $category_id = 0): int
    {
        $args = [
            'post_type'   => 'product',
            'post_status' => 'publish',
            'fields'      => 'ids',
            'posts_per_page' => -1,
            'nopaging'    => true,
        ];
        
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
        return $query->found_posts;
    }

    /**
     * Search products by name or SKU
     */
    public static function searchProducts(string $search_term, int $limit = 50): array
    {
        if (empty($search_term)) {
            return [];
        }
        
        $args = [
            'post_type'      => 'product',
            'post_status'    => 'publish',
            'posts_per_page' => $limit,
            's'              => $search_term,
            'orderby'        => 'title',
            'order'          => 'ASC',
        ];
        
        $query = new \WP_Query($args);
        $products = [];
        
        foreach ($query->posts as $post) {
            $product = wc_get_product($post);
            if ($product && $product->is_type('simple')) {
                $products[] = [
                    'id'        => $product->get_id(),
                    'name'      => $product->get_name(),
                    'sku'       => $product->get_sku(),
                    'price'     => $product->get_price(),
                ];
            }
        }
        
        wp_reset_postdata();
        
        return $products;
    }

    /**
     * Validate product IDs
     */
    public static function validateProductIds(array $product_ids): array
    {
        if (empty($product_ids)) {
            return [];
        }
        
        // Remove duplicates and non-numeric values
        $product_ids = array_unique(array_filter($product_ids, 'is_numeric'));
        
        if (empty($product_ids)) {
            return [];
        }
        
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
     * Get products with Wootour data
     */
    public static function getProductsWithWootour(int $limit = 100): array
    {
        global $wpdb;
        
        // Try to detect Wootour meta key
        $meta_keys = [
            '_wootour_availability',
            '_wootour_availabilities',
            '_tour_availability',
        ];
        
        $product_ids = [];
        
        foreach ($meta_keys as $meta_key) {
            $ids = $wpdb->get_col($wpdb->prepare(
                "SELECT DISTINCT post_id 
                FROM {$wpdb->postmeta} 
                WHERE meta_key = %s 
                AND meta_value IS NOT NULL 
                AND meta_value != '' 
                LIMIT %d",
                $meta_key,
                $limit
            ));
            
            if (!empty($ids)) {
                $product_ids = array_merge($product_ids, $ids);
                break;
            }
        }
        
        if (empty($product_ids)) {
            return [];
        }
        
        $product_ids = array_slice(array_unique($product_ids), 0, $limit);
        $products = [];
        
        foreach ($product_ids as $product_id) {
            $product = wc_get_product($product_id);
            if ($product && $product->is_type('simple')) {
                $products[] = [
                    'id'        => $product->get_id(),
                    'name'      => $product->get_name(),
                    'sku'       => $product->get_sku(),
                ];
            }
        }
        
        return $products;
    }

    /**
     * Check if product has Wootour data
     */
    public static function hasWootourData(int $product_id): bool
    {
        $meta_keys = [
            '_wootour_availability',
            '_wootour_availabilities',
            '_tour_availability',
        ];
        
        foreach ($meta_keys as $meta_key) {
            if (metadata_exists('post', $product_id, $meta_key)) {
                $value = get_post_meta($product_id, $meta_key, true);
                if (!empty($value)) {
                    return true;
                }
            }
        }
        
        return false;
    }

    /**
     * Get product type
     */
    public static function getProductType(int $product_id): string
    {
        $product = wc_get_product($product_id);
        return $product ? $product->get_type() : '';
    }

    /**
     * Check if product is virtual
     */
    public static function isVirtual(int $product_id): bool
    {
        $product = wc_get_product($product_id);
        return $product ? $product->is_virtual() : false;
    }

    /**
     * Check if product is downloadable
     */
    public static function isDownloadable(int $product_id): bool
    {
        $product = wc_get_product($product_id);
        return $product ? $product->is_downloadable() : false;
    }

    /**
     * Get product stock status
     */
    public static function getStockStatus(int $product_id): string
    {
        $product = wc_get_product($product_id);
        return $product ? $product->get_stock_status() : '';
    }

    /**
     * Get product stock quantity
     */
    public static function getStockQuantity(int $product_id): int
    {
        $product = wc_get_product($product_id);
        return $product ? $product->get_stock_quantity() : 0;
    }

    /**
     * Check if product is in stock
     */
    public static function isInStock(int $product_id): bool
    {
        $product = wc_get_product($product_id);
        return $product ? $product->is_in_stock() : false;
    }

    /**
     * Get product weight
     */
    public static function getProductWeight(int $product_id): string
    {
        $product = wc_get_product($product_id);
        return $product ? $product->get_weight() : '';
    }

    /**
     * Get product dimensions
     */
    public static function getProductDimensions(int $product_id): array
    {
        $product = wc_get_product($product_id);
        
        if (!$product) {
            return [];
        }
        
        return [
            'length' => $product->get_length(),
            'width'  => $product->get_width(),
            'height' => $product->get_height(),
        ];
    }

    /**
     * Get product attributes
     */
    public static function getProductAttributes(int $product_id): array
    {
        $product = wc_get_product($product_id);
        
        if (!$product) {
            return [];
        }
        
        $attributes = $product->get_attributes();
        $result = [];
        
        foreach ($attributes as $attribute) {
            if ($attribute->is_taxonomy()) {
                $terms = wp_get_post_terms($product_id, $attribute->get_name());
                $term_names = wp_list_pluck($terms, 'name');
                $result[$attribute->get_name()] = $term_names;
            } else {
                $result[$attribute->get_name()] = $attribute->get_options();
            }
        }
        
        return $result;
    }

    /**
     * Get product tags
     */
    public static function getProductTags(int $product_id): array
    {
        $tags = wp_get_post_terms($product_id, 'product_tag', [
            'fields' => 'names',
        ]);
        
        return is_wp_error($tags) ? [] : $tags;
    }

    /**
     * Get product shipping class
     */
    public static function getShippingClass(int $product_id): string
    {
        $product = wc_get_product($product_id);
        
        if (!$product) {
            return '';
        }
        
        $shipping_class_id = $product->get_shipping_class_id();
        
        if (!$shipping_class_id) {
            return '';
        }
        
        $term = get_term($shipping_class_id, 'product_shipping_class');
        return $term && !is_wp_error($term) ? $term->name : '';
    }

    /**
     * Get product variations (if variable product)
     */
    public static function getProductVariations(int $product_id): array
    {
        $product = wc_get_product($product_id);
        
        if (!$product || !$product->is_type('variable')) {
            return [];
        }
        
        $variations = $product->get_available_variations();
        $result = [];
        
        foreach ($variations as $variation) {
            $result[] = [
                'id'        => $variation['variation_id'],
                'attributes' => $variation['attributes'],
                'price'     => $variation['display_price'],
                'sku'       => $variation['sku'],
                'stock'     => $variation['is_in_stock'],
            ];
        }
        
        return $result;
    }

    /**
     * Get product related products
     */
    public static function getRelatedProducts(int $product_id, int $limit = 5): array
    {
        $product = wc_get_product($product_id);
        
        if (!$product) {
            return [];
        }
        
        $related_ids = wc_get_related_products($product_id, $limit);
        $related = [];
        
        foreach ($related_ids as $related_id) {
            $related_product = wc_get_product($related_id);
            if ($related_product) {
                $related[] = [
                    'id'    => $related_id,
                    'name'  => $related_product->get_name(),
                    'price' => $related_product->get_price(),
                    'url'   => get_permalink($related_id),
                ];
            }
        }
        
        return $related;
    }

    /**
     * Get product upsells
     */
    public static function getUpsellProducts(int $product_id): array
    {
        $product = wc_get_product($product_id);
        
        if (!$product) {
            return [];
        }
        
        $upsell_ids = $product->get_upsell_ids();
        $upsells = [];
        
        foreach ($upsell_ids as $upsell_id) {
            $upsell_product = wc_get_product($upsell_id);
            if ($upsell_product) {
                $upsells[] = [
                    'id'    => $upsell_id,
                    'name'  => $upsell_product->get_name(),
                    'price' => $upsell_product->get_price(),
                ];
            }
        }
        
        return $upsells;
    }

    /**
     * Get product cross-sells
     */
    public static function getCrossSellProducts(int $product_id): array
    {
        $product = wc_get_product($product_id);
        
        if (!$product) {
            return [];
        }
        
        $cross_sell_ids = $product->get_cross_sell_ids();
        $cross_sells = [];
        
        foreach ($cross_sell_ids as $cross_sell_id) {
            $cross_sell_product = wc_get_product($cross_sell_id);
            if ($cross_sell_product) {
                $cross_sells[] = [
                    'id'    => $cross_sell_id,
                    'name'  => $cross_sell_product->get_name(),
                    'price' => $cross_sell_product->get_price(),
                ];
            }
        }
        
        return $cross_sells;
    }

    /**
     * Get product reviews
     */
    public static function getProductReviews(int $product_id, int $limit = 10): array
    {
        $args = [
            'post_id' => $product_id,
            'status'  => 'approve',
            'number'  => $limit,
        ];
        
        $comments = get_comments($args);
        $reviews = [];
        
        foreach ($comments as $comment) {
            $rating = get_comment_meta($comment->comment_ID, 'rating', true);
            $reviews[] = [
                'id'      => $comment->comment_ID,
                'author'  => $comment->comment_author,
                'date'    => $comment->comment_date,
                'content' => $comment->comment_content,
                'rating'  => $rating ? (int) $rating : 0,
            ];
        }
        
        return $reviews;
    }

    /**
     * Get product average rating
     */
    public static function getProductAverageRating(int $product_id): float
    {
        $product = wc_get_product($product_id);
        return $product ? (float) $product->get_average_rating() : 0.0;
    }

    /**
     * Get product review count
     */
    public static function getProductReviewCount(int $product_id): int
    {
        $product = wc_get_product($product_id);
        return $product ? $product->get_review_count() : 0;
    }

    /**
     * Get product total sales
     */
    public static function getProductTotalSales(int $product_id): int
    {
        $product = wc_get_product($product_id);
        return $product ? $product->get_total_sales() : 0;
    }

    /**
     * Check if product is on sale
     */
    public static function isOnSale(int $product_id): bool
    {
        $product = wc_get_product($product_id);
        return $product ? $product->is_on_sale() : false;
    }

    /**
     * Get product sale price
     */
    public static function getSalePrice(int $product_id): string
    {
        $product = wc_get_product($product_id);
        return $product ? $product->get_sale_price() : '';
    }

    /**
     * Get product regular price
     */
    public static function getRegularPrice(int $product_id): string
    {
        $product = wc_get_product($product_id);
        return $product ? $product->get_regular_price() : '';
    }

    /**
     * Get product sale percentage
     */
    public static function getSalePercentage(int $product_id): float
    {
        $regular = (float) self::getRegularPrice($product_id);
        $sale = (float) self::getSalePrice($product_id);
        
        if ($regular <= 0 || $sale >= $regular) {
            return 0.0;
        }
        
        return (($regular - $sale) / $regular) * 100;
    }
}