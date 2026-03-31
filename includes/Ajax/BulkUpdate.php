<?php

namespace WBE\Ajax;

use WBE\Helpers\Security;
use WBE\Helpers\Response;
use WBE\Services\BulkProcessor;
use WBE\Services\ValidationService;
use WBE\Woo\ProductRepository;
use WBE\Wootour\AvailabilityParser;
use WBE\Wootour\AvailabilityUpdater;

class BulkUpdate
{
    public function __construct()
    {
        add_action('wp_ajax_wbe_bulk_update', [$this, 'handle']);
        add_action('wp_ajax_wbe_search_products', [$this, 'search_products']);
    }

    public function handle(): void
    {
        // Sécurité
        Security::enforce_permissions();

        if (
            empty($_POST['nonce']) ||
            !Security::verify_nonce($_POST['nonce'], 'wbe_nonce')
        ) {
            Response::error('Nonce invalide', 403);
        }

        error_log("========== WBE BULK UPDATE - DÉBUT ==========");
        error_log("POST complet : " . print_r($_POST, true));

        $selection_mode = sanitize_text_field($_POST['selection_mode'] ?? 'categories');
        $action_type    = sanitize_text_field($_POST['action_type'] ?? 'update');

        error_log("Mode de sélection : " . $selection_mode);
        error_log("Type d'action : " . $action_type);

        // ================================================================
        //  RÉSOLUTION DES PRODUITS SELON LE MODE
        // ================================================================
        $all_product_ids = [];

        switch ($selection_mode) {

            // ── Modes existants ──────────────────────────────────────────
            case 'categories':
                $category_id        = isset($_POST['category_id']) ? intval($_POST['category_id']) : 0;
                $category_selection = sanitize_text_field($_POST['category_selection'] ?? 'all');

                if ($category_id > 0) {
                    if ($category_selection === 'manual' && !empty($_POST['products'])) {
                        $all_product_ids = Security::validate_product_ids($_POST['products']);
                    } else {
                        $all_product_ids = ProductRepository::get_products_by_categories([$category_id]);
                    }
                }
                break;

            case 'manual':
                $all_product_ids = Security::validate_product_ids($_POST['products'] ?? []);
                break;

            case 'all':
                $all_product_ids = ProductRepository::get_all_product_ids();
                break;

            // ── Nouveau mode : Tout sauf ──────────────────────────────────
            case 'exclusion':
                $all_product_ids = $this->resolve_exclusion_products();
                break;

            default:
                Response::error('Mode de sélection invalide');
                return;
        }

        error_log("Produits résolus : " . count($all_product_ids));

        if (empty($all_product_ids)) {
            error_log("ERREUR : Aucun produit sélectionné après résolution");
            error_log("========== WBE BULK UPDATE - FIN (ERREUR) ==========");
            Response::error('Aucun produit sélectionné');
            return;
        }

        // ================================================================
        //  TRAITEMENT
        // ================================================================
        if ($action_type === 'reset') {
            error_log("Action : RESET sur " . count($all_product_ids) . " produits");

            $report = [
                'total'   => count($all_product_ids),
                'success' => 0,
                'errors'  => 0,
            ];

            foreach ($all_product_ids as $product_id) {
                $result = AvailabilityUpdater::reset_availability($product_id);
                if ($result) { $report['success']++; }
                else         { $report['errors']++; }
            }

            error_log("Résultat RESET : " . print_r($report, true));
            error_log("========== WBE BULK UPDATE - FIN (SUCCÈS) ==========");

            wp_send_json_success([
                'message' => sprintf('%d produits réinitialisés : %d succès, %d erreurs', $report['total'], $report['success'], $report['errors']),
                'report'  => $report
            ]);
            return;
        }

        // UPDATE
        error_log("Action : UPDATE");
        $input      = $_POST['changes'] ?? [];
        $validation = ValidationService::validate_input($input);

        error_log("Validation : " . print_r($validation, true));

        if (!$validation['valid']) {
            Response::error('Données invalides : ' . implode(', ', $validation['errors']));
            return;
        }

        if (!ValidationService::has_changes($validation['data'])) {
            Response::error('Aucune modification spécifiée');
            return;
        }

        $report = BulkProcessor::process_bulk_update($all_product_ids, $validation['data']);

        error_log("Résultat UPDATE : " . print_r($report, true));
        error_log("========== WBE BULK UPDATE - FIN (SUCCÈS) ==========");

        Response::success([
            'message' => $this->format_message($report),
            'report'  => $report
        ]);
    }

    // ================================================================
    //  RÉSOLUTION DES PRODUITS — MODE EXCLUSION
    // ================================================================

    /**
     * Résout la liste finale des produits à traiter pour le mode "Tout sauf"
     * Trois sous-modes :
     *   - exclude_products        : tout le catalogue sauf des produits spécifiques
     *   - exclude_from_category   : toute une catégorie sauf des produits spécifiques
     *   - exclude_categories      : tout le catalogue sauf certaines catégories
     *
     * @return array IDs des produits à traiter
     */
    private function resolve_exclusion_products(): array
    {
        $exclusion_mode = sanitize_text_field($_POST['exclusion_mode'] ?? 'exclude_products');

        error_log("  [resolve_exclusion] Sous-mode : " . $exclusion_mode);

        switch ($exclusion_mode) {

            // ── A : Tout le catalogue sauf des produits spécifiques ──────
            case 'exclude_products':
                $excluded_ids = $this->get_clean_ids($_POST['excluded_product_ids'] ?? []);
                error_log("  [resolve_exclusion] Produits exclus : " . implode(',', $excluded_ids));

                $all_ids = ProductRepository::get_all_product_ids();
                return ProductRepository::get_all_except_products($all_ids, $excluded_ids);

            // ── B : Toute une catégorie sauf des produits spécifiques ────
            case 'exclude_from_category':
                $category_id  = intval($_POST['excl_category_id'] ?? 0);
                $excluded_ids = $this->get_clean_ids($_POST['excluded_product_ids'] ?? []);

                error_log("  [resolve_exclusion] Catégorie : " . $category_id . " | Exclus : " . implode(',', $excluded_ids));

                if ($category_id <= 0) {
                    error_log("  [resolve_exclusion] ERREUR : catégorie invalide");
                    return [];
                }

                $category_ids = ProductRepository::get_products_by_categories([$category_id]);
                return ProductRepository::get_all_except_products($category_ids, $excluded_ids);

            // ── C : Tout le catalogue sauf certaines catégories ──────────
            case 'exclude_categories':
                $excluded_category_ids = $this->get_clean_ids($_POST['excluded_category_ids'] ?? []);
                error_log("  [resolve_exclusion] Catégories exclues : " . implode(',', $excluded_category_ids));

                if (empty($excluded_category_ids)) {
                    // Aucune catégorie exclue = tous les produits
                    return ProductRepository::get_all_product_ids();
                }

                return ProductRepository::get_all_except_categories($excluded_category_ids);

            default:
                error_log("  [resolve_exclusion] Sous-mode inconnu : " . $exclusion_mode);
                return [];
        }
    }

    /**
     * Nettoie et valide un tableau d'IDs (supporte tableau, JSON ou entier unique)
     *
     * @param mixed $raw
     * @return array
     */
    private function get_clean_ids($raw): array
    {
        // Si c'est une chaîne JSON
        if (is_string($raw)) {
            $decoded = json_decode($raw, true);
            if (json_last_error() === JSON_ERROR_NONE && is_array($decoded)) {
                $raw = $decoded;
            } else {
                $raw = [$raw];
            }
        }

        if (!is_array($raw)) {
            $raw = [$raw];
        }

        $ids = array_map('intval', $raw);
        $ids = array_filter($ids, fn($id) => $id > 0);
        return array_values(array_unique($ids));
    }

    // ================================================================
    //  RECHERCHE DE PRODUITS
    // ================================================================
    public function search_products(): void
    {
        Security::enforce_permissions();

        if (
            empty($_POST['nonce']) ||
            !Security::verify_nonce($_POST['nonce'], 'wbe_nonce')
        ) {
            Response::error('Nonce invalide', 403);
        }

        $query = sanitize_text_field($_POST['query'] ?? '');

        if (strlen($query) < 2) {
            Response::error('Requête trop courte');
        }

        $args = [
            'post_type'      => 'product',
            'post_status'    => 'publish',
            's'              => $query,
            'posts_per_page' => 20,
            'orderby'        => 'relevance'
        ];

        $products_query = new \WP_Query($args);
        $products = [];

        if ($products_query->have_posts()) {
            while ($products_query->have_posts()) {
                $products_query->the_post();
                $products[] = [
                    'id'    => get_the_ID(),
                    'title' => get_the_title()
                ];
            }
            wp_reset_postdata();
        }

        Response::success(['products' => $products]);
    }

    // ================================================================
    //  UTILITAIRES
    // ================================================================
    private function format_message(array $report): string
    {
        return sprintf(
            '%d produits traités : %d succès, %d erreurs',
            $report['total'],
            $report['success'],
            $report['errors']
        );
    }
}
