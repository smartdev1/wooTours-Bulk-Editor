<?php

namespace WBE\Woo;

use WP_Query;

class ProductRepository
{
    /**
     * Récupère tous les produits d'une ou plusieurs catégories
     */
    public static function get_products_by_categories(array $category_ids, int $limit = 0): array
    {
        if (empty($category_ids)) {
            return [];
        }

        $args = [
            'post_type'      => 'product',
            'post_status'    => 'publish',
            'posts_per_page' => $limit > 0 ? $limit : -1,
            'fields'         => 'ids',
            'tax_query'      => [
                [
                    'taxonomy' => 'product_cat',
                    'field'    => 'term_id',
                    'terms'    => $category_ids,
                    'operator' => 'IN'
                ]
            ]
        ];

        $query = new WP_Query($args);
        return $query->posts;
    }

    /**
     * Récupère des produits spécifiques par IDs
     */
    public static function get_products_by_ids(array $product_ids): array
    {
        if (empty($product_ids)) {
            return [];
        }

        $args = [
            'post_type'      => 'product',
            'post_status'    => 'publish',
            'post__in'       => $product_ids,
            'posts_per_page' => -1,
            'orderby'        => 'post__in'
        ];

        $query = new WP_Query($args);
        return $query->posts;
    }

    /**
     * Récupère tous les produits (avec limite de sécurité)
     */
    public static function get_all_products(int $limit = 500): array
    {
        $args = [
            'post_type'      => 'product',
            'post_status'    => 'publish',
            'posts_per_page' => $limit,
            'fields'         => 'ids'
        ];

        $query = new WP_Query($args);
        return $query->posts;
    }

    /**
     * Compte le nombre de produits dans une sélection
     */
    public static function count_products(array $category_ids = [], array $product_ids = []): int
    {
        $all_ids = [];

        if (!empty($category_ids)) {
            $cat_products = self::get_products_by_categories($category_ids);
            $all_ids = array_merge($all_ids, $cat_products);
        }

        if (!empty($product_ids)) {
            $all_ids = array_merge($all_ids, $product_ids);
        }

        return count(array_unique($all_ids));
    }

    /**
     * Vérifie si un produit existe et est publié
     */
    public static function product_exists(int $product_id): bool
    {
        $product = get_post($product_id);
        return $product && $product->post_type === 'product' && $product->post_status === 'publish';
    }

    /**
     * Récupère les produits par lots
     */
    public static function split_into_batches(array $product_ids, int $batch_size = 50): array
    {
        return array_chunk($product_ids, $batch_size);
    }

    /**
     * Récupère les détails d'un produit
     */
    public static function get_product_details(int $product_id): ?array
    {
        $product = wc_get_product($product_id);

        if (!$product) {
            return null;
        }

        return [
            'id'    => $product_id,
            'title' => $product->get_name(),
            'sku'   => $product->get_sku(),
            'price' => $product->get_price(),
            'type'  => $product->get_type(),
            'link'  => get_edit_post_link($product_id, 'raw'),
        ];
    }

    /**
     * Récupère les détails de plusieurs produits
     */
    public static function get_multiple_products_details(array $product_ids): array
    {
        $products = [];

        foreach ($product_ids as $product_id) {
            if ($details = self::get_product_details($product_id)) {
                $products[] = $details;
            }
        }

        return $products;
    }

    /**
     * Récupère tous les produits d'une catégorie avec pagination
     */
    public static function get_products_in_category(int $category_id, int $page = 1, int $per_page = 50): array
    {
        $args = [
            'post_type'      => 'product',
            'post_status'    => 'publish',
            'posts_per_page' => $per_page,
            'paged'          => $page,
            'fields'         => 'ids',
            'tax_query'      => [
                [
                    'taxonomy' => 'product_cat',
                    'field'    => 'term_id',
                    'terms'    => $category_id,
                ]
            ]
        ];

        $query = new WP_Query($args);

        return [
            'products' => $query->posts,
            'total'    => $query->found_posts,
            'pages'    => $query->max_num_pages
        ];
    }

    /**
     * Recherche dans une catégorie
     */
    public static function search_products_in_category(int $category_id, string $search_term, int $limit = 50): array
    {
        $args = [
            'post_type'      => 'product',
            'post_status'    => 'publish',
            'posts_per_page' => $limit,
            's'              => $search_term,
            'fields'         => 'ids',
            'tax_query'      => [
                [
                    'taxonomy' => 'product_cat',
                    'field'    => 'term_id',
                    'terms'    => $category_id,
                ]
            ]
        ];

        $query = new WP_Query($args);
        return $query->posts;
    }

    /**
     * Recherche globale de produits
     */
    public static function search_products(string $search_term, int $limit = 50): array
    {
        $args = [
            'post_type'      => 'product',
            'post_status'    => 'publish',
            'posts_per_page' => $limit,
            's'              => $search_term,
            'fields'         => 'ids'
        ];

        $query = new WP_Query($args);
        return self::get_multiple_products_details($query->posts);
    }

    /**
     * Compte le nombre total de produits
     */
    public static function count_all_products(): int
    {
        $args = [
            'post_type'      => 'product',
            'post_status'    => 'publish',
            'posts_per_page' => 1,
            'fields'         => 'ids'
        ];

        $query = new WP_Query($args);
        return $query->found_posts;
    }

    /**
     * Récupère les IDs de tous les produits publiés
     */
    public static function get_all_product_ids(): array
    {
        global $wpdb;

        $product_ids = $wpdb->get_col(
            "SELECT ID FROM {$wpdb->posts}
            WHERE post_type = 'product'
            AND post_status = 'publish'"
        );

        return array_map('intval', $product_ids);
    }

    /**
     * Récupère les produits selon un type de sélection
     */
    public static function get_products_by_selection(array $params): array
    {
        $selection_type = $params['selection_type'] ?? 'manual';

        switch ($selection_type) {
            case 'all':
                return self::get_all_product_ids();
            case 'category_all':
                $category_id = $params['category_id'] ?? 0;
                return $category_id ? self::get_products_by_categories([$category_id]) : [];
            case 'category_manual':
            case 'manual':
                return array_map('intval', $params['product_ids'] ?? []);
            default:
                return [];
        }
    }

    /**
     * Valide une liste d'IDs de produits
     */
    public static function validate_product_ids(array $product_ids): array
    {
        $valid_ids = [];

        foreach ($product_ids as $id) {
            $id = intval($id);
            if ($id > 0 && self::product_exists($id)) {
                $valid_ids[] = $id;
            }
        }

        return array_unique($valid_ids);
    }

    /**
     * Formate les produits pour JavaScript
     */
    public static function format_products_for_js(array $product_ids): array
    {
        $products = [];

        foreach ($product_ids as $product_id) {
            if ($details = self::get_product_details($product_id)) {
                $products[] = [
                    'id'    => $details['id'],
                    'title' => $details['title'],
                    'sku'   => $details['sku'],
                    'price' => $details['price']
                ];
            }
        }

        return $products;
    }

    // ================================================================
    //  NOUVELLES MÉTHODES — MODE "TOUT SAUF"
    // ================================================================

    /**
     * Retourne tous les IDs d'un périmètre en excluant des produits spécifiques.
     *
     * Utilisé pour les deux sous-modes :
     *   - Tout le catalogue sauf [produits]
     *   - Toute une catégorie sauf [produits]
     *
     * @param array $scope_ids     IDs du périmètre de départ (catalogue ou catégorie)
     * @param array $excluded_ids  IDs à retirer du périmètre
     * @return array               IDs à traiter
     */
    public static function get_all_except_products(array $scope_ids, array $excluded_ids): array
    {
        if (empty($excluded_ids)) {
            // Aucune exclusion → retourner tout le périmètre
            return array_values(array_map('intval', $scope_ids));
        }

        $excluded_ids = array_map('intval', $excluded_ids);
        $scope_ids    = array_map('intval', $scope_ids);

        // Retirer les exclusions du périmètre
        $result = array_values(array_diff($scope_ids, $excluded_ids));

        if (defined('WP_DEBUG') && WP_DEBUG) {
            error_log(sprintf(
                '[WBE] get_all_except_products : périmètre=%d | exclus=%d | résultat=%d',
                count($scope_ids),
                count($excluded_ids),
                count($result)
            ));
        }

        return $result;
    }

    /**
     * Retourne tous les produits du catalogue en excluant des catégories entières.
     *
     * Utilisé pour le sous-mode : Tout le catalogue sauf [catégories]
     *
     * @param array $excluded_category_ids  IDs des catégories à exclure
     * @return array                        IDs des produits à traiter
     */
    public static function get_all_except_categories(array $excluded_category_ids): array
    {
        if (empty($excluded_category_ids)) {
            return self::get_all_product_ids();
        }

        // Récupérer les IDs des produits appartenant aux catégories exclues
        $excluded_product_ids = self::get_products_by_categories($excluded_category_ids);

        if (empty($excluded_product_ids)) {
            // Les catégories exclues sont vides → retourner tout le catalogue
            return self::get_all_product_ids();
        }

        // Récupérer tous les produits et soustraire les exclusions
        // On utilise la requête SQL directe pour la performance sur gros volumes
        global $wpdb;

        $excluded_ids_sql = implode(',', array_map('intval', $excluded_product_ids));

        $product_ids = $wpdb->get_col(
            "SELECT ID FROM {$wpdb->posts}
             WHERE post_type = 'product'
             AND post_status = 'publish'
             AND ID NOT IN ({$excluded_ids_sql})"
        );

        $result = array_map('intval', $product_ids);

        if (defined('WP_DEBUG') && WP_DEBUG) {
            error_log(sprintf(
                '[WBE] get_all_except_categories : catégories exclues=%d | produits exclus=%d | résultat=%d',
                count($excluded_category_ids),
                count($excluded_product_ids),
                count($result)
            ));
        }

        return $result;
    }
}
