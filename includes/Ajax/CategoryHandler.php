<?php

namespace WBE\Ajax;

use WBE\Woo\CategoryRepository;
use WBE\Woo\ProductRepository;

class CategoryHandler
{
    public function __construct()
    {
        add_action('wp_ajax_wbe_get_category_products', [$this, 'get_category_products']);
        add_action('wp_ajax_wbe_get_total_products', [$this, 'get_total_products']);
        add_action('wp_ajax_wbe_search_products', [$this, 'search_products']);
        add_action('wp_ajax_wbe_search_in_category', [$this, 'search_in_category']);
    }

    /**
     * Récupère les produits d'une catégorie
     */
    public function get_category_products(): void
    {
        $this->check_security();

        $category_id = isset($_POST['category_id']) ? intval($_POST['category_id']) : 0;
        
        if (!$category_id || !CategoryRepository::category_exists($category_id)) {
            wp_send_json_error(['message' => 'Catégorie invalide']);
        }

        // Récupérer les produits avec pagination
        $page = isset($_POST['page']) ? intval($_POST['page']) : 1;
        $per_page = isset($_POST['per_page']) ? intval($_POST['per_page']) : 50;
        
        $products_data = ProductRepository::get_products_in_category($category_id, $page, $per_page);
        $product_details = ProductRepository::get_multiple_products_details($products_data['products']);

        wp_send_json_success([
            'products' => $product_details,
            'total'    => $products_data['total'],
            'pages'    => $products_data['pages']
        ]);
    }

    /**
     * Récupère le nombre total de produits
     */
    public function get_total_products(): void
    {
        $this->check_security();

        $total = ProductRepository::count_all_products();

        wp_send_json_success([
            'total' => $total
        ]);
    }

    /**
     * Recherche globale de produits
     */
    public function search_products(): void
    {
        $this->check_security();

        $search_term = isset($_POST['query']) ? sanitize_text_field($_POST['query']) : '';
        
        if (strlen($search_term) < 2) {
            wp_send_json_success(['products' => []]);
        }

        $products = ProductRepository::search_products($search_term, 50);

        wp_send_json_success([
            'products' => $products
        ]);
    }

    /**
     * Recherche dans une catégorie spécifique
     */
    public function search_in_category(): void
    {
        $this->check_security();

        $category_id = isset($_POST['category_id']) ? intval($_POST['category_id']) : 0;
        $search_term = isset($_POST['query']) ? sanitize_text_field($_POST['query']) : '';
        
        if (!$category_id || strlen($search_term) < 2) {
            wp_send_json_success(['products' => []]);
        }

        $product_ids = ProductRepository::search_products_in_category($category_id, $search_term, 50);
        $products = ProductRepository::get_multiple_products_details($product_ids);

        wp_send_json_success([
            'products' => $products
        ]);
    }

    /**
     * Vérifie la sécurité (nonce et permissions)
     */
    private function check_security(): void
    {
        if (!check_ajax_referer('wbe_nonce', 'nonce', false)) {
            wp_send_json_error(['message' => 'Nonce invalide']);
        }

        if (!current_user_can('manage_woocommerce')) {
            wp_send_json_error(['message' => 'Permissions insuffisantes']);
        }
    }
}