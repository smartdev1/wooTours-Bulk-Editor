<?php

namespace WBE\Woo;

class CategoryRepository
{
    /**
     * Récupère toutes les catégories de produits
     * 
     * @return array Liste des catégories
     */
    public static function get_all_categories(): array
    {
        $categories = get_terms([
            'taxonomy'   => 'product_cat',
            'hide_empty' => false,
            'orderby'    => 'name',
            'order'      => 'ASC'
        ]);

        return is_array($categories) ? $categories : [];
    }

    /**
     * Récupère une catégorie spécifique
     * 
     * @param int $category_id ID de la catégorie
     * @return object|null Catégorie ou null
     */
    public static function get_category(int $category_id): ?object
    {
        $category = get_term($category_id, 'product_cat');
        
        return $category && !is_wp_error($category) ? $category : null;
    }

    /**
     * Récupère le nom d'une catégorie
     * 
     * @param int $category_id ID de la catégorie
     * @return string Nom de la catégorie
     */
    public static function get_category_name(int $category_id): string
    {
        $category = self::get_category($category_id);
        
        return $category ? $category->name : 'Catégorie inconnue';
    }

    /**
     * Récupère le nombre de produits dans une catégorie
     * 
     * @param int $category_id ID de la catégorie
     * @return int Nombre de produits
     */
    public static function get_product_count(int $category_id): int
    {
        $category = self::get_category($category_id);
        
        return $category ? $category->count : 0;
    }

    /**
     * Vérifie si une catégorie existe
     * 
     * @param int $category_id ID de la catégorie
     * @return bool
     */
    public static function category_exists(int $category_id): bool
    {
        return term_exists($category_id, 'product_cat') !== null;
    }

    /**
     * Formate les catégories pour Select2
     * 
     * @return array Catégories formatées
     */
    public static function get_categories_for_select(): array
    {
        $categories = self::get_all_categories();
        $formatted = [];
        
        foreach ($categories as $category) {
            $formatted[] = [
                'id'   => $category->term_id,
                'text' => sprintf('%s (%d produits)', $category->name, $category->count)
            ];
        }
        
        return $formatted;
    }

    /**
     * Récupère les catégories par IDs
     * 
     * @param array $category_ids IDs des catégories
     * @return array Catégories
     */
    public static function get_categories_by_ids(array $category_ids): array
    {
        if (empty($category_ids)) {
            return [];
        }

        $categories = get_terms([
            'taxonomy'   => 'product_cat',
            'include'    => $category_ids,
            'hide_empty' => false
        ]);

        return is_array($categories) ? $categories : [];
    }
}