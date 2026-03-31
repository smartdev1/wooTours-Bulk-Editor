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

        // ============================================================
        // DEBUG : Log de toutes les données reçues
        // ============================================================
        error_log("========== WBE BULK UPDATE - DÉBUT ==========");
        error_log("POST complet : " . print_r($_POST, true));
        error_log("Category ID brut : " . print_r($_POST['category_id'] ?? 'VIDE', true));
        error_log("Products brutes : " . print_r($_POST['products'] ?? 'VIDE', true));
        error_log("Category selection : " . ($_POST['category_selection'] ?? 'NON DÉFINI'));
        error_log("Selection mode : " . ($_POST['selection_mode'] ?? 'NON DÉFINI'));
        error_log("Action type : " . ($_POST['action_type'] ?? 'NON DÉFINI'));

        // Si products est une chaîne, essayer de la décoder
        if (isset($_POST['products']) && is_string($_POST['products'])) {
            error_log("Products est une chaîne, tentative de décodage JSON...");
            $decoded = json_decode($_POST['products'], true);
            error_log("Résultat du décodage : " . print_r($decoded, true));
            error_log("Erreur JSON : " . json_last_error_msg());
        }

        // Récupération des données selon le mode de sélection
        $category_ids = [];
        $product_ids = [];
        $selection_mode = sanitize_text_field($_POST['selection_mode'] ?? 'categories');
        $action_type = sanitize_text_field($_POST['action_type'] ?? 'update');

        error_log("Mode de sélection détecté : " . $selection_mode);

        switch ($selection_mode) {
            case 'categories':
                $category_id = isset($_POST['category_id']) ? intval($_POST['category_id']) : 0;
                $category_selection = sanitize_text_field($_POST['category_selection'] ?? 'all');

                error_log("Catégorie ID : " . $category_id);
                error_log("Sélection catégorie : " . $category_selection);

                if ($category_id > 0) {
                    $category_ids = [$category_id];
                }

                // Si mode "manuel" dans la catégorie, récupérer les produits sélectionnés
                if ($category_selection === 'manual' && !empty($_POST['products'])) {
                    $product_ids = Security::validate_product_ids($_POST['products']);
                }
                break;

            case 'manual':
                $product_ids = Security::validate_product_ids($_POST['products'] ?? []);
                break;

            case 'all':
                // Pas besoin de IDs spécifiques, on prendra tous les produits
                break;

            default:
                Response::error('Mode de sélection invalide');
                return;
        }

        error_log("Categories après traitement : " . print_r($category_ids, true));
        error_log("Products après traitement : " . print_r($product_ids, true));
        error_log("Nombre de categories : " . count($category_ids));
        error_log("Nombre de products : " . count($product_ids));

        // Récupérer tous les produits concernés
        $all_product_ids = $this->get_all_product_ids($category_ids, $product_ids, $selection_mode);

        error_log("All product IDs combinés : " . print_r($all_product_ids, true));
        error_log("Nombre total de produits : " . count($all_product_ids));

        if (empty($all_product_ids)) {
            error_log("ERREUR : Aucun produit sélectionné");
            error_log("========== WBE BULK UPDATE - FIN (ERREUR) ==========");
            Response::error('Aucun produit sélectionné');
        }

        // Traiter selon le type d'action
        if ($action_type === 'reset') {
            error_log("Action : RESET");
            // $report = BulkProcessor::process_reset($all_product_ids);
            $product_ids = $this->get_all_product_ids($category_ids, $product_ids, $selection_mode);
            $report = [
                'total' => count($product_ids),
                'success' => 0,
                'errors' => 0,
            ];
            foreach ($product_ids as $product_id) {
                $result = AvailabilityUpdater::reset_availability($product_id);

                if ($result) {
                    $report['success']++;
                } else {
                    $report['errors']++;
                }
            }

            wp_send_json_success([
                'message' => 'Réinitialisation terminée',
                'report' => $report
            ]);
        } else {
            error_log("Action : UPDATE");
            // Validation des modifications
            $input = $_POST['changes'] ?? [];
            error_log("Changes reçues : " . print_r($input, true));

            $validation = ValidationService::validate_input($input);
            error_log("Validation result : " . print_r($validation, true));

            if (!$validation['valid']) {
                error_log("ERREUR : Données invalides - " . implode(', ', $validation['errors']));
                error_log("========== WBE BULK UPDATE - FIN (ERREUR) ==========");
                Response::error('Données invalides : ' . implode(', ', $validation['errors']));
            }

            if (!ValidationService::has_changes($validation['data'])) {
                error_log("ERREUR : Aucune modification spécifiée");
                error_log("========== WBE BULK UPDATE - FIN (ERREUR) ==========");
                Response::error('Aucune modification spécifiée');
            }

            // Traitement
            $report = BulkProcessor::process_bulk_update($all_product_ids, $validation['data']);
        }

        error_log("Traitement terminé - Rapport : " . print_r($report, true));
        error_log("========== WBE BULK UPDATE - FIN (SUCCÈS) ==========");

        // Réponse
        Response::success([
            'message' => $this->format_message($report),
            'report' => $report
        ]);
    }

    /**
     * Combine produits de catégories + produits individuels
     */
    private function get_all_product_ids(
        array $category_ids,
        array $product_ids,
        string $selection_mode
    ): array {

        error_log("  [get_all_product_ids] Entrée - Categories: " . print_r($category_ids, true));
        error_log("  [get_all_product_ids] Entrée - Products: " . print_r($product_ids, true));
        error_log("  [get_all_product_ids] Mode de sélection: " . $selection_mode);

        if (!empty($product_ids)) {
            error_log("  [get_all_product_ids] Produits manuels détectés → priorité");
            $final = $product_ids;
        }
        // 🔹 Tous les produits
        elseif ($selection_mode === 'all') {
            error_log("  [get_all_product_ids] Récupération de TOUS les produits");
            $final = ProductRepository::get_all_product_ids();
        }
        // 🔹 Produits d'une ou plusieurs catégories
        elseif ($selection_mode === 'categories' && !empty($category_ids)) {
            error_log("  [get_all_product_ids] Récupération produits par catégorie");
            $final = ProductRepository::get_products_by_categories($category_ids);
        }
        // 🔹 Rien sélectionné
        else {
            $final = [];
        }

        $final = array_map('intval', $final);
        $final = array_filter($final);
        $final = array_values(array_unique($final));

        error_log("  [get_all_product_ids] Sortie - IDs finaux : " . print_r($final, true));

        return $final;
    }



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

        Response::success([
            'products' => $products
        ]);
    }

    /**
     * Formate le message de retour
     */
    private function format_message(array $report): string
    {
        $message = sprintf(
            '%d produits traités : %d succès, %d erreurs',
            $report['total'],
            $report['success'],
            $report['errors']
        );

        return $message;
    }
}
