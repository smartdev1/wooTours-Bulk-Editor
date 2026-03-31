<?php

namespace WBE\Woo;

use WP_Query;

class ProductRepository
{
    /**
     * Récupère tous les produits d'une ou plusieurs catégories
     * 
     * @param array $category_ids Liste des IDs de catégories
     * @param int $limit Limite de produits (0 = tous)
     * @return array Tableau d'IDs de produits
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
     * 
     * @param array $product_ids Liste des IDs de produits
     * @return array Tableau d'objets WP_Post
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
     * 
     * @param int $limit Limite de produits (défaut: 500)
     * @return array Tableau d'IDs de produits
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
     * 
     * @param array $category_ids IDs de catégories (optionnel)
     * @param array $product_ids IDs de produits spécifiques (optionnel)
     * @return int Nombre total de produits
     */
    public static function count_products(array $category_ids = [], array $product_ids = []): int
    {
        $all_ids = [];

        // Produits par catégories
        if (!empty($category_ids)) {
            $cat_products = self::get_products_by_categories($category_ids);
            $all_ids = array_merge($all_ids, $cat_products);
        }

        // Produits spécifiques
        if (!empty($product_ids)) {
            $all_ids = array_merge($all_ids, $product_ids);
        }

        return count(array_unique($all_ids));
    }

    /**
     * Vérifie si un produit existe et est publié
     * 
     * @param int $product_id ID du produit
     * @return bool
     */
    public static function product_exists(int $product_id): bool
    {
        $product = get_post($product_id);
        
        return $product && $product->post_type === 'product' && $product->post_status === 'publish';
    }

    /**
     * Récupère les produits par lots (batch processing)
     * Essentiel pour éviter les timeouts sur gros volumes
     * 
     * @param array $product_ids Liste complète des IDs
     * @param int $batch_size Taille du lot (défaut: 50)
     * @return array Tableau de tableaux (lots)
     */
    public static function split_into_batches(array $product_ids, int $batch_size = 50): array
    {
        return array_chunk($product_ids, $batch_size);
    }

    // ============================================
    // NOUVELLES MÉTHODES POUR LA SÉLECTION PAR CATÉGORIE
    // ============================================

    /**
     * Récupère les détails d'un produit (nom, SKU, etc.)
     * 
     * @param int $product_id ID du produit
     * @return array|null Détails du produit ou null
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
     * 
     * @param array $product_ids Liste des IDs
     * @return array Tableau de détails
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
     * Récupère tous les produits d'une catégorie spécifique
     * 
     * @param int $category_id ID de la catégorie
     * @param int $page Page pour la pagination
     * @param int $per_page Produits par page
     * @return array [products => IDs, total => count, pages => page_count]
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
     * Recherche des produits dans une catégorie spécifique
     * 
     * @param int $category_id ID de la catégorie
     * @param string $search_term Terme de recherche
     * @param int $limit Limite de résultats
     * @return array IDs des produits trouvés
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
     * 
     * @param string $search_term Terme de recherche
     * @param int $limit Limite de résultats
     * @return array Détails des produits trouvés
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
     * Compte le nombre total de produits dans le catalogue
     * 
     * @return int Nombre total de produits
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
     * Récupère les IDs de tous les produits (pour mode "tout le catalogue")
     * 
     * @param int $limit Limite maximale
     * @return array IDs des produits
     */
    // public static function get_all_product_ids(int $limit = 1000): array
    // {
    //     $args = [
    //         'post_type'      => 'product',
    //         'post_status'    => 'publish',
    //         'posts_per_page' => $limit,
    //         'fields'         => 'ids'
    //     ];

    //     $query = new WP_Query($args);
        
    //     return $query->posts;
    // }

    /**
     * Récupère les IDs des produits pour plusieurs scénarios
     * 
     * @param array $params Paramètres de sélection
     * @return array IDs des produits
     */
    public static function get_products_by_selection(array $params): array
    {
        $selection_type = $params['selection_type'] ?? 'manual';
        
        switch ($selection_type) {
            case 'all':
                // Tous les produits du catalogue
                return self::get_all_product_ids();
                
            case 'category_all':
                // Tous les produits d'une catégorie
                $category_id = $params['category_id'] ?? 0;
                if ($category_id) {
                    return self::get_products_by_categories([$category_id]);
                }
                return [];
                
            case 'category_manual':
                // Produits spécifiques dans une catégorie
                $product_ids = $params['product_ids'] ?? [];
                return array_map('intval', $product_ids);
                
            case 'manual':
                // Produits spécifiques (mode libre)
                $product_ids = $params['product_ids'] ?? [];
                return array_map('intval', $product_ids);
                
            default:
                return [];
        }
    }

    /**
     * Valide une liste d'IDs de produits
     * 
     * @param array $product_ids IDs à valider
     * @return array IDs valides
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
     * Formate les produits pour l'affichage JavaScript
     * 
     * @param array $product_ids IDs des produits
     * @return array Produits formatés
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
}