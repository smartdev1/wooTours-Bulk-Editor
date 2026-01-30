<?php

/**
 * Wootour Bulk Editor - AJAX Controller (Validation Simplifiée)
 * 
 * Handles all AJAX requests from the admin interface
 * with security validation and standardized responses.
 * 
 * MODIFICATION MAJEURE :
 * - Toutes les règles de disponibilité sont maintenant optionnelles
 * - On peut ne définir aucune règle (conservation des données existantes)
 * - Validation de cohérence maintenue (dates, conflits)
 * 
 * @package     WootourBulkEditor
 * @subpackage  Controllers
 * @license     GPL-2.0+
 * @since       1.0.0
 */

namespace WootourBulkEditor\Controllers;

use WootourBulkEditor\Core\Constants;
use WootourBulkEditor\Services\BatchProcessor;
use WootourBulkEditor\Services\AvailabilityService;
use WootourBulkEditor\Repositories\ProductRepository;
use WootourBulkEditor\Services\SecurityService;
use WootourBulkEditor\Exceptions\ValidationException;
use WootourBulkEditor\Services\LoggerService;
use WootourBulkEditor\Controllers\ProductController;
use WootourBulkEditor\Exceptions\BatchException;
use WootourBulkEditor\Traits\Singleton;

// Exit if accessed directly
defined('ABSPATH') || exit;

/**
 * Class AjaxController
 * 
 * Manages all AJAX endpoints with proper security and error handling
 */
final class AjaxController
{
    use Singleton;

    /**
     * @var SecurityService
     */
    private $security_service;

    /**
     * @var BatchProcessor
     */
    private $batch_processor;

    /**
     * @var ProductRepository
     */
    private $product_repository;

    /**
     * @var AvailabilityService
     */
    private $availability_service;

    /**
     * Private constructor
     */
    private function __construct()
    {
        // Dependencies injected via init
    }

    /**
     * Initialize controller and register AJAX hooks
     */
    public function init(): void
    {
        $this->security_service = SecurityService::getInstance();
        $this->batch_processor = BatchProcessor::getInstance();
        $this->product_repository = ProductRepository::getInstance();
        $this->availability_service = AvailabilityService::getInstance();

        // Register AJAX actions
        foreach (Constants::AJAX_ACTIONS as $action) {
            add_action("wp_ajax_{$action}", [$this, 'handle_ajax_request']);
        }

        // Block unauthorized access
        add_action('wp_ajax_nopriv_wbe_', [$this, 'handle_unauthorized_access']);
    }

    /**
     * Main AJAX request handler
     */
    public function handle_ajax_request(): void
    {
        try {
            // 1. Validate request
            $this->validate_ajax_request();

            // 2. Get action and route to handler
            $action = $this->get_request_action();
            $result = $this->route_to_handler($action);

            // 3. Send success response
            $this->send_success_response($result);
        } catch (ValidationException $e) {
            $this->send_error_response($e->getMessage(), $e->getCode(), [
                'type' => 'validation_error',
            ]);
        } catch (BatchException $e) {
            $this->send_error_response($e->getMessage(), $e->getCode(), [
                'type' => 'batch_error',
                'can_resume' => strpos($e->getMessage(), 'can be resumed') !== false,
            ]);
        } catch (\Exception $e) {
            error_log(sprintf(
                '[WootourBulkEditor] AJAX error: %s in %s:%s',
                $e->getMessage(),
                $e->getFile(),
                $e->getLine()
            ));

            $this->send_error_response(
                'An unexpected error occurred. Please try again.',
                500,
                [
                    'type' => 'internal_error',
                    'debug' => defined('WP_DEBUG') && WP_DEBUG ? $e->getMessage() : null,
                ]
            );
        }
    }

    /**
     * Route AJAX action to appropriate handler
     */
    private function route_to_handler(string $action): array
    {
        switch ($action) {
            case Constants::AJAX_ACTIONS['get_products']:
                return $this->handle_get_products();

            case Constants::AJAX_ACTIONS['get_categories']:
                return $this->handle_get_categories();

            case Constants::AJAX_ACTIONS['process_batch']:
                return $this->handle_process_batch();

            case Constants::AJAX_ACTIONS['search_products']:
                return $this->handle_search_products();

            case Constants::AJAX_ACTIONS['validate_dates']:
                return $this->handle_validate_dates();

            case 'wbe_get_product_availability':
                return $this->handle_get_product_availability();

            case 'get_progress':
                return $this->handle_get_progress();

            case 'preview_changes':
                return $this->handle_preview_changes();

            case 'cancel_operation':
                return $this->handle_cancel_operation();

            case 'resume_operation':
                return $this->handle_resume_operation();

            default:
                throw new \InvalidArgumentException(sprintf('Unknown AJAX action: %s', $action));
        }
    }

    /**
     * Handle unauthorized access
     */
    public function handle_unauthorized_access(): void
    {
        $this->send_error_response(
            'Authentication required.',
            401,
            ['type' => 'authentication_error']
        );
    }

    /**
     * Validate AJAX request
     */
    private function validate_ajax_request(): void
    {
        // Check if user is logged in
        if (!is_user_logged_in()) {
            throw new \RuntimeException('User not authenticated', 401);
        }

        // Check capabilities
        $this->security_service->check_user_capability();

        // Verify nonce
        $nonce = $_POST['nonce'] ?? $_GET['nonce'] ?? '';
        if (!$this->security_service->verify_nonce($nonce, 'ajax')) {
            throw new \RuntimeException('Security check failed. Please refresh the page.', 403);
        }

        // Check if it's an AJAX request
        if (!defined('DOING_AJAX') || !DOING_AJAX) {
            throw new \RuntimeException('Invalid request method.', 400);
        }
    }

    /**
     * Handle: Search products by product name only
     */
    private function handle_search_products(): array
    {
        // Récupérer les paramètres
        $search_term = sanitize_text_field($_REQUEST['search'] ?? '');
        $limit = min(100, max(1, (int) ($_REQUEST['limit'] ?? 50)));

        // Validation
        if (empty($search_term) || strlen($search_term) < 2) {
            return [
                'success' => true,
                'data' => [
                    'products' => [],
                    'total' => 0,
                    'message' => 'Veuillez entrer au moins 2 caractères'
                ]
            ];
        }

        try {
            // Recherche SIMPLE : uniquement dans le titre (post_title)
            $args = [
                'post_type'      => ['product', 'product_variation'],
                'post_status'    => 'publish',
                's'              => $search_term,
                'posts_per_page' => $limit,
                'orderby'        => 'title',
                'order'          => 'ASC',
                'fields'         => 'ids',
                'sentence'       => true,
            ];

            // Forcer la recherche uniquement dans le titre
            add_filter('posts_search', function ($search, $wp_query) use ($search_term) {
                global $wpdb;

                if ($wp_query->is_search() && !empty($search_term)) {
                    $search = $wpdb->prepare(
                        " AND {$wpdb->posts}.post_title LIKE %s",
                        '%' . $wpdb->esc_like($search_term) . '%'
                    );
                }

                return $search;
            }, 10, 2);

            $query = new \WP_Query($args);

            // Retirer le filtre après la requête
            remove_all_filters('posts_search');

            $products = [];
            foreach ($query->posts as $product_id) {
                $product = wc_get_product($product_id);
                if (!$product) continue;

                // Récupérer l'image
                $image_id = $product->get_image_id();
                $image_url = $image_id ? wp_get_attachment_image_url($image_id, 'thumbnail') : wc_placeholder_img_src('thumbnail');

                // Informations de base
                $product_data = [
                    'id' => $product_id,
                    'name' => $product->get_name(),
                    'sku' => $product->get_sku() ?: '-',
                    'type' => $product->get_type(),
                    'price' => $product->get_price(),
                    'regular_price' => $product->get_regular_price(),
                    'sale_price' => $product->get_sale_price(),
                    'stock_status' => $product->get_stock_status(),
                    'stock_quantity' => $product->get_stock_quantity(),
                    'image' => $image_url,
                    'edit_link' => get_edit_post_link($product_id, ''),
                    'view_link' => get_permalink($product_id),
                ];

                // Si c'est une variation, ajouter les infos du parent
                if ($product->get_type() === 'variation') {
                    $parent_id = $product->get_parent_id();
                    $parent_product = wc_get_product($parent_id);
                    if ($parent_product) {
                        $product_data['parent'] = [
                            'id' => $parent_id,
                            'name' => $parent_product->get_name(),
                            'sku' => $parent_product->get_sku(),
                            'edit_link' => get_edit_post_link($parent_id, ''),
                        ];

                        // Pour les variations, montrer les attributs
                        $attributes = $product->get_attributes();
                        if (!empty($attributes)) {
                            $attribute_list = [];
                            foreach ($attributes as $name => $value) {
                                $attribute_list[] = [
                                    'name' => wc_attribute_label($name),
                                    'value' => $value,
                                ];
                            }
                            $product_data['attributes'] = $attribute_list;
                        }
                    }
                }

                // Récupérer la disponibilité WooTours si disponible
                $wootour_availability = null;
                if (Constants::is_wootour_active()) {
                    $meta_key = Constants::get_verified_meta_key();
                    if ($meta_key) {
                        $wootour_availability = get_post_meta($product_id, $meta_key, true);
                        $product_data['has_wootour'] = !empty($wootour_availability);
                    }
                }

                $products[] = $product_data;
            }

            return [
                'success' => true,
                'data' => [
                    'products' => $products,
                    'total' => count($products),
                    'found' => $query->found_posts,
                    'search_term' => $search_term,
                    'limit' => $limit,
                ]
            ];
        } catch (\Exception $e) {
            return [
                'success' => false,
                'data' => [
                    'products' => [],
                    'message' => 'Erreur lors de la recherche: ' . $e->getMessage()
                ]
            ];
        }
    }

    /**
     * Get action from request
     */
    private function get_request_action(): string
    {
        $action = $_REQUEST['action'] ?? '';

        if (empty($action)) {
            throw new \InvalidArgumentException('No action specified.', 400);
        }

        return $action;
    }

    /**
     * Handle: Get products (for product selector)
     */
    private function handle_get_products(): array
    {
        $category_id = (int) ($_REQUEST['category_id'] ?? 0);
        $page = max(1, (int) ($_REQUEST['page'] ?? 1));
        $per_page = min(100, max(1, (int) ($_REQUEST['per_page'] ?? 50)));
        $search = sanitize_text_field($_REQUEST['search'] ?? '');

        $products = [];

        if (!empty($search)) {
            // Search products
            $products = $this->product_repository->searchProducts($search, $per_page);
        } elseif ($category_id > 0) {
            // Get by category
            $products = $this->product_repository->getProductsByCategory($category_id, $page, $per_page);
        } else {
            // Get all (with limit)
            $products = $this->product_repository->getAllProducts($per_page);
        }

        // Convert to API format
        $product_data = array_map(function ($product) {
            return $product->toApiArray();
        }, $products);

        // Get pagination info
        $total = $this->product_repository->getProductCount($category_id);
        $total_pages = $per_page > 0 ? ceil($total / $per_page) : 1;

        return [
            'success' => true,
            'data' => [
                'products' => $product_data,
                'pagination' => [
                    'total' => $total,
                    'per_page' => $per_page,
                    'current_page' => $page,
                    'total_pages' => $total_pages,
                    'has_more' => $page < $total_pages,
                ],
                'category_id' => $category_id,
                'search_term' => $search,
            ],
        ];
    }

    /**
     * Handle: Get categories (for category filter)
     */
    private function handle_get_categories(): array
    {
        $categories = $this->product_repository->getCategoryTree();

        return [
            'success' => true,
            'data' => [
                'categories' => $categories,
                'total' => count($categories),
            ],
        ];
    }

    /**
     * Handle: Process batch update
     */
    private function handle_process_batch(): array
    {
        error_log('[WBE] Processing batch request');
        error_log('[WBE] POST data: ' . print_r($_POST, true));

        // Parse request data
        $product_ids = $this->parse_product_ids();
        $changes = $this->parse_changes();
        $operation_id = sanitize_text_field($_REQUEST['operation_id'] ?? '');

        error_log('[WBE] Parsed product IDs: ' . print_r($product_ids, true));
        error_log('[WBE] Parsed changes: ' . print_r($changes, true));

        // Validate we have products to process
        if (empty($product_ids)) {
            throw new ValidationException('No products selected.');
        }

        // NOTE IMPORTANTE : On n'exige plus qu'il y ait des changements
        // Si aucune règle n'est définie, les données existantes sont conservées

        error_log('[WBE] Starting batch processing for ' . count($product_ids) . ' products');

        // Process batch
        $result = $this->batch_processor->processBatch($product_ids, $changes, $operation_id);

        error_log('[WBE] Batch processing result: ' . print_r($result, true));
        return [
            'success' => true,
            'data' => $result,
            'message' => $this->generate_batch_message($result),
            'debug' => [
                'product_count' => count($product_ids),
                'changes_applied' => $changes
            ]
        ];
    }

    /**
     * Handle: Validate dates before moving to step 3
     * VERSION SIMPLIFIÉE : Toutes les règles sont optionnelles
     */
    private function handle_validate_dates(): array
    {
        try {
            // Parse et valider les données
            $changes = $this->parse_changes();

            // Validation simple mais efficace
            $errors = $this->validate_for_step2($changes);

            if (!empty($errors)) {
                return [
                    'success' => false,
                    'data' => [
                        'valid' => false,
                        'errors' => $errors,
                        'changes' => $changes
                    ]
                ];
            }

            return [
                'success' => true,
                'data' => [
                    'valid' => true,
                    'changes' => $changes,
                    'summary' => $this->generate_step2_summary($changes)
                ]
            ];
        } catch (ValidationException $e) {
            return [
                'success' => false,
                'data' => [
                    'valid' => false,
                    'errors' => [$e->getMessage()]
                ]
            ];
        } catch (\Exception $e) {
            return [
                'success' => false,
                'data' => [
                    'valid' => false,
                    'errors' => ['Une erreur technique est survenue. Veuillez réessayer.']
                ]
            ];
        }
    }

    /**
     * Validation spécifique pour l'étape 2 - VERSION SIMPLIFIÉE
     * 
     * MODIFICATIONS MAJEURES :
     * - On ne vérifie PLUS qu'au moins une règle est définie
     * - Toutes les règles sont optionnelles
     * - On vérifie seulement la cohérence des données fournies
     */
    private function validate_for_step2(array $changes): array
    {
        $errors = [];

        // 1. Si une plage de dates est définie, la valider
        $hasStartDate = !empty($changes['start_date']);
        $hasEndDate = !empty($changes['end_date']);

        // Si une seule date est fournie, c'est une erreur
        if ($hasStartDate !== $hasEndDate) {
            if ($hasStartDate && !$hasEndDate) {
                $errors[] = 'La date de fin est requise si vous définissez une date de début.';
            } elseif (!$hasStartDate && $hasEndDate) {
                $errors[] = 'La date de début est requise si vous définissez une date de fin.';
            }
        }

        // Si les deux dates sont présentes, valider la plage
        if ($hasStartDate && $hasEndDate) {
            try {
                $this->validate_date_range($changes['start_date'], $changes['end_date']);
            } catch (ValidationException $e) {
                $errors[] = $e->getMessage();
            }
        }

        // 2. Vérifier les conflits de dates
        if (!empty($changes['specific']) && !empty($changes['exclusions'])) {
            $conflicts = array_intersect($changes['specific'], $changes['exclusions']);
            if (!empty($conflicts)) {
                $formattedConflicts = array_map(function($date) {
                    return date('d/m/Y', strtotime($date));
                }, $conflicts);
                $errors[] = sprintf(
                    'Les dates suivantes sont à la fois marquées comme disponibles et exclues : %s',
                    implode(', ', $formattedConflicts)
                );
            }
        }

        return $errors;
    }

    /**
     * Génère un résumé pour l'étape 3
     */
    private function generate_step2_summary(array $changes): array
    {
        $summary = [];

        // Vérifier si au moins une règle est définie
        $hasRules = (!empty($changes['start_date']) && !empty($changes['end_date'])) ||
                    !empty($changes['weekdays']) ||
                    !empty($changes['specific']) ||
                    !empty($changes['exclusions']);

        if (!$hasRules) {
            $summary['no_rules'] = true;
            $summary['message'] = 'Aucune règle de disponibilité définie. Les informations existantes des produits seront conservées.';
            return $summary;
        }

        // Plage de dates
        if (!empty($changes['start_date']) && !empty($changes['end_date'])) {
            $startFr = date('d/m/Y', strtotime($changes['start_date']));
            $endFr = date('d/m/Y', strtotime($changes['end_date']));
            $days = floor((strtotime($changes['end_date']) - strtotime($changes['start_date'])) / (60 * 60 * 24)) + 1;

            $summary['period'] = [
                'start' => $startFr,
                'end' => $endFr,
                'days' => $days,
                'text' => "Du $startFr au $endFr ($days jour" . ($days > 1 ? 's' : '') . ")"
            ];
        }

        // Jours de la semaine
        if (!empty($changes['weekdays'])) {
            $dayNames = ['Dimanche', 'Lundi', 'Mardi', 'Mercredi', 'Jeudi', 'Vendredi', 'Samedi'];
            $selectedDays = array_map(function ($index) use ($dayNames) {
                return $dayNames[$index] ?? 'Jour ' . $index;
            }, $changes['weekdays']);

            $summary['weekdays'] = [
                'count' => count($changes['weekdays']),
                'days' => $selectedDays,
                'text' => implode(', ', $selectedDays)
            ];
        }

        // Dates spécifiques
        if (!empty($changes['specific'])) {
            $formattedDates = array_map(function ($date) {
                return date('d/m/Y', strtotime($date));
            }, $changes['specific']);

            $summary['specific'] = [
                'count' => count($changes['specific']),
                'dates' => $formattedDates,
                'text' => implode(', ', $formattedDates)
            ];
        }

        // Exclusions
        if (!empty($changes['exclusions'])) {
            $formattedDates = array_map(function ($date) {
                return date('d/m/Y', strtotime($date));
            }, $changes['exclusions']);

            $summary['exclusions'] = [
                'count' => count($changes['exclusions']),
                'dates' => $formattedDates,
                'text' => implode(', ', $formattedDates)
            ];
        }

        return $summary;
    }

    /**
     * Handle: Get operation progress
     */
    private function handle_get_progress(): array
    {
        $operation_id = sanitize_text_field($_REQUEST['operation_id'] ?? '');

        if (empty($operation_id)) {
            throw new ValidationException('Operation ID required.');
        }

        $progress = $this->batch_processor->getProgress($operation_id);

        if (!$progress) {
            return [
                'success' => false,
                'data' => [
                    'operation_id' => $operation_id,
                    'status' => 'not_found',
                    'message' => 'Operation not found or expired.',
                ],
            ];
        }

        return [
            'success' => true,
            'data' => $progress,
        ];
    }

    /**
     * Handle: Get product availability data
     * Récupère les données de disponibilité existantes d'un produit
     */
    private function handle_get_product_availability(): array
    {
        $product_id = (int) ($_REQUEST['product_id'] ?? 0);

        if (empty($product_id)) {
            throw new ValidationException('Product ID required.');
        }

        // Vérifier que le produit existe
        $product = wc_get_product($product_id);
        if (!$product) {
            throw new ValidationException('Product not found.');
        }

        // Récupérer la clé meta WooTour principale
        $meta_key = Constants::get_verified_meta_key();
        if (!$meta_key) {
            return [
                'success' => true,
                'data' => [
                    'product_id' => $product_id,
                    'has_data' => false,
                    'message' => 'WooTour plugin not active or meta key not found.'
                ]
            ];
        }

        // Récupérer les données de disponibilité principales
        $availability_data = get_post_meta($product_id, $meta_key, true);

        // Récupérer les meta keys spécifiques WooTour
        // Dates exclues: wt_disable_book et wt_disabledate
        $excluded_dates_1 = get_post_meta($product_id, 'wt_disable_book', true);
        $excluded_dates_2 = get_post_meta($product_id, 'wt_disabledate', true);
        
        // Dates spéciales: wt_customdate
        $special_dates = get_post_meta($product_id, 'wt_customdate', true);

        // Logger les données brutes pour debug
        error_log('[WBE] Product ' . $product_id . ' - wt_disable_book: ' . print_r($excluded_dates_1, true));
        error_log('[WBE] Product ' . $product_id . ' - wt_disabledate: ' . print_r($excluded_dates_2, true));
        error_log('[WBE] Product ' . $product_id . ' - wt_customdate: ' . print_r($special_dates, true));

        // Parser les données selon le format WooTour
        $parsed_data = $this->parse_wootour_availability(
            $availability_data, 
            $excluded_dates_1, 
            $excluded_dates_2, 
            $special_dates,
            $product_id
        );

        return [
            'success' => true,
            'data' => array_merge([
                'product_id' => $product_id,
                'has_data' => !empty($availability_data) || !empty($excluded_dates_1) || !empty($excluded_dates_2) || !empty($special_dates),
                'product_name' => $product->get_name(),
            ], $parsed_data)
        ];
    }

    /**
     * Parser les données de disponibilité WooTour
     * 
     * @param mixed $availability_data Données brutes de disponibilité principales
     * @param mixed $excluded_dates_1 Dates exclues (wt_disable_book)
     * @param mixed $excluded_dates_2 Dates exclues (wt_disabledate)
     * @param mixed $special_dates Dates spéciales (wt_customdate)
     * @param int $product_id ID du produit (pour debug)
     * @return array Données parsées et formatées
     */
    private function parse_wootour_availability(
        $availability_data, 
        $excluded_dates_1 = null, 
        $excluded_dates_2 = null, 
        $special_dates = null,
        $product_id = 0
    ): array
    {
        $result = [
            'start_date' => '',
            'end_date' => '',
            'weekdays' => [],
            'specific' => [],
            'exclusions' => []
        ];

        // Si c'est déjà un tableau, traiter directement
        if (is_array($availability_data)) {
            // Date de début
            if (!empty($availability_data['start_date'])) {
                $result['start_date'] = $this->normalize_date($availability_data['start_date']);
            }

            // Date de fin
            if (!empty($availability_data['end_date'])) {
                $result['end_date'] = $this->normalize_date($availability_data['end_date']);
            }

            // Jours de la semaine
            if (!empty($availability_data['weekdays']) && is_array($availability_data['weekdays'])) {
                $result['weekdays'] = array_map('intval', $availability_data['weekdays']);
            }

            // Dates spécifiques (depuis availability_data)
            if (!empty($availability_data['specific']) && is_array($availability_data['specific'])) {
                $result['specific'] = array_map([$this, 'normalize_date'], $availability_data['specific']);
            }

            // Exclusions (depuis availability_data)
            if (!empty($availability_data['exclusions']) && is_array($availability_data['exclusions'])) {
                $result['exclusions'] = array_map([$this, 'normalize_date'], $availability_data['exclusions']);
            }
        }
        // Si c'est une chaîne sérialisée, la désérialiser d'abord
        elseif (is_string($availability_data) && !empty($availability_data)) {
            $unserialized = @unserialize($availability_data);
            if (is_array($unserialized)) {
                return $this->parse_wootour_availability(
                    $unserialized, 
                    $excluded_dates_1, 
                    $excluded_dates_2, 
                    $special_dates,
                    $product_id
                );
            }
        }

        // === TRAITER LES META KEYS WOOTOUR SPÉCIFIQUES ===

        // 1. Dates spéciales (wt_customdate)
        $parsed_special_dates = $this->parse_wootour_date_meta($special_dates, 'wt_customdate', $product_id);
        if (!empty($parsed_special_dates)) {
            // Fusionner avec les dates spécifiques existantes
            $result['specific'] = array_unique(array_merge($result['specific'], $parsed_special_dates));
        }

        // 2. Dates exclues (wt_disable_book)
        $parsed_excluded_1 = $this->parse_wootour_date_meta($excluded_dates_1, 'wt_disable_book', $product_id);
        if (!empty($parsed_excluded_1)) {
            $result['exclusions'] = array_unique(array_merge($result['exclusions'], $parsed_excluded_1));
        }

        // 3. Dates exclues (wt_disabledate)
        $parsed_excluded_2 = $this->parse_wootour_date_meta($excluded_dates_2, 'wt_disabledate', $product_id);
        if (!empty($parsed_excluded_2)) {
            $result['exclusions'] = array_unique(array_merge($result['exclusions'], $parsed_excluded_2));
        }

        // Trier les dates pour un affichage cohérent
        sort($result['specific']);
        sort($result['exclusions']);

        error_log('[WBE] Product ' . $product_id . ' - Final parsed data: ' . print_r($result, true));

        return $result;
    }

    /**
     * Parser une meta key WooTour contenant des dates
     * 
     * @param mixed $meta_value Valeur de la meta key
     * @param string $meta_key Nom de la meta key (pour debug)
     * @param int $product_id ID du produit (pour debug)
     * @return array Tableau de dates normalisées
     */
    private function parse_wootour_date_meta($meta_value, string $meta_key, int $product_id): array
    {
        $dates = [];

        if (empty($meta_value)) {
            return $dates;
        }

        // Cas 1: C'est déjà un tableau de dates
        if (is_array($meta_value)) {
            foreach ($meta_value as $date) {
                if (is_string($date) && !empty($date)) {
                    $normalized = $this->normalize_date($date);
                    if ($normalized) {
                        $dates[] = $normalized;
                    }
                }
                // Cas où c'est un tableau de tableaux (ex: [['date' => '2026-01-01'], ...])
                elseif (is_array($date) && isset($date['date'])) {
                    $normalized = $this->normalize_date($date['date']);
                    if ($normalized) {
                        $dates[] = $normalized;
                    }
                }
            }
        }
        // Cas 2: C'est une chaîne sérialisée
        elseif (is_string($meta_value)) {
            // Essayer de désérialiser
            $unserialized = @unserialize($meta_value);
            if (is_array($unserialized)) {
                return $this->parse_wootour_date_meta($unserialized, $meta_key, $product_id);
            }
            
            // Sinon, essayer de parser comme une date unique
            $normalized = $this->normalize_date($meta_value);
            if ($normalized) {
                $dates[] = $normalized;
            }
        }

        if (!empty($dates)) {
            error_log('[WBE] Product ' . $product_id . ' - Parsed ' . count($dates) . ' dates from ' . $meta_key);
        }

        return $dates;
    }

    /**
     * Normaliser une date au format YYYY-MM-DD
     * 
     * @param string $date Date à normaliser
     * @return string Date normalisée
     */
    private function normalize_date(string $date): string
    {
        if (empty($date)) {
            return '';
        }

        // Si déjà au bon format
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
            return $date;
        }

        // Si format DD/MM/YYYY
        if (preg_match('/^(\d{2})\/(\d{2})\/(\d{4})$/', $date, $matches)) {
            return sprintf('%04d-%02d-%02d', $matches[3], $matches[2], $matches[1]);
        }

        // Si format MM/DD/YYYY
        if (preg_match('/^(\d{2})\/(\d{2})\/(\d{4})$/', $date, $matches)) {
            // Ambiguïté - on suppose DD/MM/YYYY par défaut
            return sprintf('%04d-%02d-%02d', $matches[3], $matches[2], $matches[1]);
        }

        // Essayer strtotime comme dernier recours
        $timestamp = strtotime($date);
        if ($timestamp !== false) {
            return date('Y-m-d', $timestamp);
        }

        return '';
    }

    /**
     * Handle: Preview changes before applying
     */
    private function handle_preview_changes(): array
    {
        $product_ids = $this->parse_product_ids();
        $changes = $this->parse_changes();
        $sample_size = min(20, max(1, (int) ($_REQUEST['sample_size'] ?? 5)));
        error_log('test');
        if (empty($product_ids)) {
            throw new ValidationException('No products selected for preview.');
        }

        $preview = $this->batch_processor->previewChanges($product_ids, $changes, $sample_size);

        return [
            'success' => true,
            'data' => $preview,
            'message' => sprintf(
                'Preview generated for %d products (sampled %d)',
                count($product_ids),
                count($preview['samples'] ?? [])
            ),
        ];
    }

    /**
     * Handle: Cancel operation
     */
    private function handle_cancel_operation(): array
    {
        $operation_id = sanitize_text_field($_REQUEST['operation_id'] ?? '');

        if (empty($operation_id)) {
            throw new ValidationException('Operation ID required.');
        }

        $cancelled = $this->batch_processor->cancelOperation($operation_id);

        return [
            'success' => $cancelled,
            'data' => [
                'operation_id' => $operation_id,
                'cancelled' => $cancelled,
            ],
            'message' => $cancelled ? 'Operation cancelled.' : 'Failed to cancel operation.',
        ];
    }

    /**
     * Handle: Resume operation
     */
    private function handle_resume_operation(): array
    {
        $operation_id = sanitize_text_field($_REQUEST['operation_id'] ?? '');

        if (empty($operation_id)) {
            throw new ValidationException('Operation ID required.');
        }

        $result = $this->batch_processor->resumeOperation($operation_id);

        return [
            'success' => true,
            'data' => $result,
            'message' => 'Operation resumed successfully.',
        ];
    }

    /**
     * Parse product IDs from request
     */
    private function parse_product_ids(): array
    {
        $product_ids = [];

        // Support multiple formats
        if (isset($_REQUEST['product_ids'])) {
            if (is_array($_REQUEST['product_ids'])) {
                $product_ids = array_map('intval', $_REQUEST['product_ids']);
            } elseif (is_string($_REQUEST['product_ids'])) {
                $product_ids = array_map('intval', explode(',', $_REQUEST['product_ids']));
            }
        }

        // Also support category-based selection
        $category_id = (int) ($_REQUEST['category_id'] ?? 0);
        if ($category_id > 0 && empty($product_ids)) {
            // Get all products from category
            $products = $this->product_repository->getProductsByCategory($category_id, 1, 1000);
            $product_ids = array_map(fn($p) => $p->getId(), $products);
        }

        // Remove duplicates and invalid IDs
        $product_ids = array_unique(array_filter($product_ids, 'is_numeric'));

        return array_map('intval', $product_ids);
    }

    /**
     * Parse changes from request
     */
    private function parse_changes(): array
    {
        $changes = [];

        error_log('[WBE AjaxController] === START parse_changes ===');
        error_log('[WBE AjaxController] RAW REQUEST: ' . print_r($_REQUEST, true));
        // Parse each field with sanitization
        $fields = ['start_date', 'end_date', 'weekdays', 'exclusions', 'specific'];
        

        foreach ($fields as $field) {
            if (isset($_REQUEST[$field])) {
                $value = $_REQUEST[$field];

                if (is_array($value)) {
                    // Array fields (weekdays as checkboxes)
                    if ($field === 'weekdays') {
                        // Weekdays come as ['monday' => 'on', 'tuesday' => 'on']
                        $weekday_indices = [];
                        $weekday_map = [
                            'sunday' => 0,
                            'monday' => 1,
                            'tuesday' => 2,
                            'wednesday' => 3,
                            'thursday' => 4,
                            'friday' => 5,
                            'saturday' => 6
                        ];

                        foreach ($value as $day_name => $checked) {
                            if ($checked === 'on' && isset($weekday_map[$day_name])) {
                                $weekday_indices[] = $weekday_map[$day_name];
                            }
                        }

                        $changes[$field] = $weekday_indices;
                        error_log('[WBE AjaxController] Parsed weekdays: ' . print_r($weekday_indices, true));
                    } else {
                        // Other arrays (exclusions, specific dates)
                        $changes[$field] = array_map('sanitize_text_field', $value);
                    }
                } elseif (is_string($value)) {
                    // Convert date formats
                    if ($field === 'start_date' || $field === 'end_date') {
                        error_log('[WBE AjaxController] Raw date value for ' . $field . ': ' . $value);

                        // Check if already YYYY-MM-DD
                        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $value)) {
                            $changes[$field] = sanitize_text_field($value);
                            error_log('[WBE AjaxController] Date already YYYY-MM-DD: ' . $value);
                        }
                        // Check if DD/MM/YYYY format
                        elseif (preg_match('/^(\d{2})\/(\d{2})\/(\d{4})$/', $value, $matches)) {
                            // Convert DD/MM/YYYY to YYYY-MM-DD
                            $converted_date = sprintf(
                                '%04d-%02d-%02d',
                                $matches[3], // year
                                $matches[2], // month  
                                $matches[1]  // day
                            );
                            $changes[$field] = sanitize_text_field($converted_date);
                            error_log('[WBE AjaxController] Converted date ' . $field . ': ' . $value . ' → ' . $converted_date);
                        } else {
                            // Unknown format, leave as is (will be rejected by validation)
                            $changes[$field] = sanitize_text_field($value);
                            error_log('[WBE AjaxController] Unknown date format: ' . $value);
                        }
                    }
                    // String fields for weekdays (comma-separated)
                    elseif ($field === 'weekdays') {
                        $changes[$field] = array_map('intval', explode(',', $value));
                    } else {
                        $changes[$field] = sanitize_text_field($value);
                    }
                }
            }
        }

        // Process specific and exclusion dates (which may come as strings)
        foreach (['specific', 'exclusions'] as $date_field) {
            if (isset($changes[$date_field]) && is_string($changes[$date_field])) {
                $dates = explode(',', $changes[$date_field]);
                $converted_dates = [];

                foreach ($dates as $date) {
                    $date = trim($date);
                    if (empty($date)) continue;

                    // Convert DD/MM/YYYY to YYYY-MM-DD if necessary
                    if (preg_match('/^(\d{2})\/(\d{2})\/(\d{4})$/', $date, $matches)) {
                        $converted_date = sprintf('%04d-%02d-%02d', $matches[3], $matches[2], $matches[1]);
                        $converted_dates[] = $converted_date;
                    } elseif (preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
                        $converted_dates[] = $date;
                    }
                }

                $changes[$date_field] = $converted_dates;
            }
        }

        error_log('[WBE AjaxController] Final parsed changes: ' . print_r($changes, true));

        // VALIDATION DES DATES (only if both are provided)
        if (isset($changes['start_date']) && isset($changes['end_date']) && 
            !empty($changes['start_date']) && !empty($changes['end_date'])) {
            $this->validate_date_range($changes['start_date'], $changes['end_date']);
        }

        // VALIDATION DES CONFLITS (specific vs exclusion dates)
        if (!empty($changes['specific']) && !empty($changes['exclusions'])) {
            $specific_dates = $changes['specific'];
            $exclusion_dates = $changes['exclusions'];

            $conflicts = array_intersect($specific_dates, $exclusion_dates);
            if (!empty($conflicts)) {
                $conflict_dates = array_map(function ($date) {
                    return date('d/m/Y', strtotime($date));
                }, $conflicts);

                throw new ValidationException(
                    sprintf(
                        'Les dates suivantes sont à la fois spécifiques et exclues : %s',
                        implode(', ', $conflict_dates)
                    )
                );
            }
        }

        error_log('[WBE AjaxController] === END parse_changes (validation passed) ===');

        return $changes;
    }

    /**
     * Validate date range
     * @throws ValidationException
     */
    private function validate_date_range(string $start_date, string $end_date): void
    {
        // Check date format
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $start_date)) {
            throw new ValidationException(
                sprintf('Format de date de début invalide : %s. Utilisez JJ/MM/AAAA.', $start_date)
            );
        }

        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $end_date)) {
            throw new ValidationException(
                sprintf('Format de date de fin invalide : %s. Utilisez JJ/MM/AAAA.', $end_date)
            );
        }

        // Convert to timestamps for comparison
        $start_timestamp = strtotime($start_date);
        $end_timestamp = strtotime($end_date);

        if ($start_timestamp === false) {
            throw new ValidationException('Date de début invalide.');
        }

        if ($end_timestamp === false) {
            throw new ValidationException('Date de fin invalide.');
        }

        // Main validation: end date cannot be before start date
        if ($end_timestamp < $start_timestamp) {
            throw new ValidationException(
                sprintf(
                    'La date de fin (%s) ne peut pas être antérieure à la date de début (%s).',
                    date('d/m/Y', $end_timestamp),
                    date('d/m/Y', $start_timestamp)
                )
            );
        }

        // Optional: check that start date is not in the past
        $today_timestamp = strtotime(date('Y-m-d'));
        if ($start_timestamp < $today_timestamp) {
            throw new ValidationException(
                'La date de début ne peut pas être dans le passé.'
            );
        }

        // Optional: limit range to reasonable duration (e.g., 2 years)
        $max_days = 730; // 2 years
        $days_diff = ($end_timestamp - $start_timestamp) / (60 * 60 * 24);
        if ($days_diff > $max_days) {
            throw new ValidationException(
                sprintf(
                    'La plage de dates est trop longue (%d jours maximum).',
                    $max_days
                )
            );
        }

        error_log('[WBE AjaxController] Date range validation passed: ' . $start_date . ' to ' . $end_date);
    }

    /**
     * Generate user-friendly batch result message
     */
    private function generate_batch_message(array $result): string
    {
        $total = $result['total_products'] ?? 0;
        $success = $result['success_count'] ?? 0;
        $failed = $result['failed_count'] ?? 0;

        if ($success === $total) {
            return sprintf(
                'Successfully updated %d product%s.',
                $success,
                $success !== 1 ? 's' : ''
            );
        }

        if ($failed === $total) {
            return sprintf(
                'Failed to update %d product%s. Check error details.',
                $failed,
                $failed !== 1 ? 's' : ''
            );
        }

        return sprintf(
            'Updated %d of %d products. %d failed.',
            $success,
            $total,
            $failed
        );
    }

    /**
     * Send success JSON response
     */
    private function send_success_response(array $data = []): void
    {
        $response = array_merge([
            'success' => true,
            'timestamp' => time(),
        ], $data);

        $this->send_json_response($response);
    }

    /**
     * Send error JSON response
     */
    private function send_error_response(string $message, int $code = 400, array $data = []): void
    {
        $response = array_merge([
            'success' => false,
            'error' => [
                'code' => $code,
                'message' => $message,
                'timestamp' => time(),
            ],
        ], $data);

        status_header($code);
        $this->send_json_response($response);
    }

    /**
     * Send JSON response and terminate
     */
    private function send_json_response(array $data): void
    {
        // Set headers
        header('Content-Type: application/json; charset=' . get_option('blog_charset'));
        header('X-Robots-Tag: noindex');

        // Add security headers
        $this->security_service->add_security_headers();

        // Send response
        echo wp_json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);

        // Terminate safely
        wp_die();
    }

    /**
     * Check if request is AJAX
     */
    public function is_ajax_request(): bool
    {
        return defined('DOING_AJAX') && DOING_AJAX;
    }

    /**
     * Get current AJAX action
     */
    public function get_current_action(): string
    {
        return $_REQUEST['action'] ?? '';
    }

    /**
     * Test AJAX connectivity
     */
    public function test_connectivity(): array
    {
        return [
            'success' => true,
            'data' => [
                'ajax_url' => admin_url('admin-ajax.php'),
                'nonce' => wp_create_nonce(Constants::NONCE_ACTIONS['ajax']),
                'user_can' => current_user_can('manage_woocommerce'),
                'timestamp' => time(),
            ],
        ];
    }
}