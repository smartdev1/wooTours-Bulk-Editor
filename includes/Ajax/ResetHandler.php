<?php

namespace WBE\Ajax;

/**
 * Handler pour la réinitialisation complète
 */
class ResetHandler
{
    /**
     * Réinitialise complètement un produit
     */
    public static function reset_product_completely()
    {
        // Vérifier le nonce
        if (!wp_verify_nonce($_POST['nonce'] ?? '', 'wbe_nonce')) {
            wp_send_json_error(['message' => 'Nonce invalide']);
            return;
        }

        $product_id = intval($_POST['product_id'] ?? 0);
        
        if ($product_id <= 0) {
            wp_send_json_error(['message' => 'ID produit invalide']);
            return;
        }

        try {
            // Supprimer TOUTES les métadonnées WBE
            $meta_keys = [
                // Métadonnées WBE
                '_tour_start_date',
                '_tour_end_date',
                '_tour_available_days',
                '_tour_unavailable_dates',
                '_tour_specific_dates',
                
                // Métadonnées Wootour natives
                'wt_start',
                'wt_expired',
                '_weekdays',
                '_wootour_availability',
                'eventstartdate',
                'wp_event_start_date',
                '_start_date',
                
                // Anciens formats de compatibilité
                'start_date',
                'expired_date',
            ];

            $success = true;
            foreach ($meta_keys as $meta_key) {
                $success = delete_post_meta($product_id, $meta_key) && $success;
            }

            // Supprimer les champs répétables wt_disabledate et wt_customdate
            global $wpdb;
            $wpdb->query($wpdb->prepare(
                "DELETE FROM $wpdb->postmeta 
                 WHERE post_id = %d 
                 AND meta_key IN ('wt_disabledate', 'wt_customdate')",
                $product_id
            ));

            if (defined('WP_DEBUG') && WP_DEBUG) {
                error_log(sprintf(
                    '[WBE Reset] Produit #%d complètement réinitialisé',
                    $product_id
                ));
            }

            wp_send_json_success([
                'message' => 'Produit réinitialisé avec succès',
                'product_id' => $product_id
            ]);
            
        } catch (\Exception $e) {
            wp_send_json_error([
                'message' => 'Erreur lors de la réinitialisation',
                'error' => $e->getMessage()
            ]);
        }
    }

    /**
     * Récupère tous les produits d'une catégorie
     */
    public static function get_all_category_products()
    {
        if (!wp_verify_nonce($_POST['nonce'] ?? '', 'wbe_nonce')) {
            wp_send_json_error(['message' => 'Nonce invalide']);
            return;
        }

        $category_id = intval($_POST['category_id'] ?? 0);
        
        if ($category_id <= 0) {
            wp_send_json_error(['message' => 'Catégorie invalide']);
            return;
        }

        $args = [
            'post_type' => 'product',
            'posts_per_page' => -1,
            'fields' => 'ids',
            'tax_query' => [
                [
                    'taxonomy' => 'product_cat',
                    'field' => 'term_id',
                    'terms' => $category_id,
                ]
            ],
            'post_status' => 'publish',
        ];

        $query = new \WP_Query($args);
        
        wp_send_json_success([
            'product_ids' => $query->posts,
            'count' => count($query->posts)
        ]);
    }

    /**
     * Récupère tous les produits du catalogue
     */
    public static function get_all_products()
    {
        if (!wp_verify_nonce($_POST['nonce'] ?? '', 'wbe_nonce')) {
            wp_send_json_error(['message' => 'Nonce invalide']);
            return;
        }

        $args = [
            'post_type' => 'product',
            'posts_per_page' => -1,
            'fields' => 'ids',
            'post_status' => 'publish',
        ];

        $query = new \WP_Query($args);
        
        wp_send_json_success([
            'product_ids' => $query->posts,
            'count' => count($query->posts)
        ]);
    }
}