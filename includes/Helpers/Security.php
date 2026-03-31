<?php

namespace WBE\Helpers;

class Security
{

    public static function can_manage_woocommerce(): bool
    {
        return current_user_can('manage_woocommerce') || current_user_can('administrator');
    }


    public static function enforce_permissions(): void
    {
        self::check_permissions();
    }

    public static function check_permissions(): void
    {
        if (!self::can_manage_woocommerce()) {
            Response::error('Permissions insuffisantes. Vous devez être administrateur ou gestionnaire WooCommerce.', 403);
        }
    }

    public static function verify_nonce(string $nonce, string $action): bool
    {
        return wp_verify_nonce(sanitize_text_field($nonce), $action) !== false;
    }


    public static function create_nonce(string $action): string
    {
        return wp_create_nonce($action);
    }


    public static function sanitize_array(array $data): array
    {
        $sanitized = [];

        foreach ($data as $key => $value) {
            if (is_array($value)) {
                $sanitized[$key] = self::sanitize_array($value);
            } else {
                $sanitized[$key] = sanitize_text_field($value);
            }
        }

        return $sanitized;
    }


    /**
     * Valide et nettoie un tableau d'IDs de produits
     * 
     * CORRECTION : Gère maintenant les chaînes JSON envoyées depuis JavaScript
     * et ne fait pas la vérification get_post_type() qui peut être lente
     * 
     * @param mixed $products Peut être : tableau, chaîne JSON, ou valeur unique
     * @return array Tableau d'entiers > 0
     */
    public static function validate_product_ids($products): array
    {
        // DEBUG : Log de l'entrée
        error_log("    [validate_product_ids] INPUT brut : " . print_r($products, true));
        error_log("    [validate_product_ids] Type : " . gettype($products));

        // Si c'est une chaîne, essayer de décoder en JSON
        if (is_string($products)) {
            error_log("    [validate_product_ids] C'est une chaîne, tentative de décodage JSON");
            
            // Essayer JSON d'abord (envoyé par JSON.stringify)
            $decoded = json_decode($products, true);
            
            if (json_last_error() === JSON_ERROR_NONE && is_array($decoded)) {
                error_log("    [validate_product_ids] ✓ Décodage JSON réussi : " . print_r($decoded, true));
                $products = $decoded;
            } else {
                error_log("    [validate_product_ids] ✗ Décodage JSON échoué : " . json_last_error_msg());
                
                // Si ce n'est pas du JSON valide, peut-être un tableau sérialisé PHP
                $unserialized = @unserialize($products);
                if (is_array($unserialized)) {
                    error_log("    [validate_product_ids] ✓ Unserialization réussie");
                    $products = $unserialized;
                } else {
                    // Dernière tentative : peut-être une valeur unique
                    error_log("    [validate_product_ids] Traitement comme valeur unique");
                    $products = [$products];
                }
            }
        }

        // Si ce n'est toujours pas un tableau, forcer en tableau
        if (!is_array($products)) {
            error_log("    [validate_product_ids] Conversion en tableau : " . gettype($products));
            $products = [$products];
        }

        error_log("    [validate_product_ids] Avant filtrage : " . print_r($products, true));

        // Nettoyer et filtrer
        // IMPORTANT : On ne vérifie PAS get_post_type() ici car c'est trop lent
        // La vérification d'existence se fera plus tard dans le traitement
        $products = array_map('intval', $products);
        $products = array_filter($products, function ($id) {
            return $id > 0;  // On vérifie juste que c'est un entier positif
        });

        $products = array_values($products);  // Réindexer

        error_log("    [validate_product_ids] Après filtrage : " . print_r($products, true));
        error_log("    [validate_product_ids] Nombre d'IDs valides : " . count($products));

        return $products;
    }


    /**
     * Valide et nettoie un tableau d'IDs de catégories
     * 
     * CORRECTION : Gère maintenant les chaînes JSON
     * 
     * @param mixed $category_ids
     * @return array
     */
    public static function validate_category_ids($category_ids): array
    {
        error_log("    [validate_category_ids] INPUT brut : " . print_r($category_ids, true));
        error_log("    [validate_category_ids] Type : " . gettype($category_ids));

        // Si c'est une chaîne, essayer de décoder en JSON
        if (is_string($category_ids)) {
            error_log("    [validate_category_ids] C'est une chaîne, tentative de décodage JSON");
            
            $decoded = json_decode($category_ids, true);
            
            if (json_last_error() === JSON_ERROR_NONE && is_array($decoded)) {
                error_log("    [validate_category_ids] ✓ Décodage JSON réussi");
                $category_ids = $decoded;
            } else {
                error_log("    [validate_category_ids] ✗ Décodage JSON échoué : " . json_last_error_msg());
                
                $unserialized = @unserialize($category_ids);
                if (is_array($unserialized)) {
                    error_log("    [validate_category_ids] ✓ Unserialization réussie");
                    $category_ids = $unserialized;
                } else {
                    error_log("    [validate_category_ids] Traitement comme valeur unique");
                    $category_ids = [$category_ids];
                }
            }
        }

        // Forcer en tableau si nécessaire
        if (!is_array($category_ids)) {
            error_log("    [validate_category_ids] Conversion en tableau");
            $category_ids = [$category_ids];
        }

        error_log("    [validate_category_ids] Avant filtrage : " . print_r($category_ids, true));

        $valid_ids = [];

        foreach ($category_ids as $id) {
            $id = absint($id);
            if ($id > 0 && term_exists($id, 'product_cat')) {
                $valid_ids[] = $id;
            }
        }

        $valid_ids = array_unique($valid_ids);

        error_log("    [validate_category_ids] Après filtrage : " . print_r($valid_ids, true));
        error_log("    [validate_category_ids] Nombre d'IDs valides : " . count($valid_ids));

        return $valid_ids;
    }


    public static function check_admin_access(): void
    {
        if (!is_admin()) {
            wp_die(__('Accès non autorisé.'));
        }

        if (!self::can_manage_woocommerce()) {
            wp_die(__('Permissions insuffisantes. Vous devez être administrateur ou gestionnaire WooCommerce.'));
        }
    }
}