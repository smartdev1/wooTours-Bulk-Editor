<?php
/**
 * Wootour Bulk Editor - AJAX Controller
 * 
 * Handles all AJAX requests from the admin interface
 * 
 * @package     WootourBulkEditor
 * @subpackage  Controllers
 * @since       1.0.0
 */

namespace WootourBulkEditor\Controllers;

use WootourBulkEditor\Core\Constants;
use WootourBulkEditor\Services\SecurityService;
use WootourBulkEditor\Services\LoggerService;
use WootourBulkEditor\Core\Traits\Singleton;

defined('ABSPATH') || exit;

class AjaxController
{
    use Singleton;

    private SecurityService $security_service;
    private ProductController $product_controller;
    private LoggerService $logger;

    protected function __construct()
    {
        // Dependencies will be injected via init
    }

    public function init(): void
    {
        $this->security_service = SecurityService::getInstance();
        $this->product_controller = ProductController::getInstance();
        $this->product_controller->init();
        $this->logger = LoggerService::getInstance();

        $this->register_ajax_handlers();
    }

    /**
     * Register AJAX handlers
     */
    private function register_ajax_handlers(): void
    {
        // Product operations
        add_action('wp_ajax_wbe_get_products', [$this, 'handle_get_products']);
        add_action('wp_ajax_wbe_search_products', [$this, 'handle_search_products']);
        add_action('wp_ajax_wbe_get_product', [$this, 'handle_get_product']);
        
        // Bulk operations
        add_action('wp_ajax_wbe_apply_changes', [$this, 'handle_apply_changes']);
        
        // Category operations
        add_action('wp_ajax_wbe_get_categories', [$this, 'handle_get_categories']);
    }

    /**
     * Handle get products by category AJAX request
     */
    public function handle_get_products(): void
    {
        try {
            // Verify nonce
            if (!$this->security_service->verify_nonce($_POST['nonce'] ?? '', 'ajax')) {
                throw new \Exception('Invalid security token');
            }

            // Verify permissions
            if (!$this->security_service->canManageProducts()) {
                throw new \Exception('Insufficient permissions');
            }

            // Get parameters
            $category_id = isset($_POST['category_id']) ? absint($_POST['category_id']) : 0;
            $page = isset($_POST['page']) ? absint($_POST['page']) : 1;
            $per_page = isset($_POST['per_page']) ? absint($_POST['per_page']) : 50;

            // Validate parameters
            if ($page < 1) $page = 1;
            if ($per_page < 1 || $per_page > 200) $per_page = 50;

            // Get products from controller
            $result = $this->product_controller->get_products_by_category(
                $category_id,
                $page,
                $per_page
            );

            // Log the request
            $this->logger->debug('Products retrieved by category', [
                'category_id' => $category_id,
                'page' => $page,
                'count' => count($result['products'])
            ]);

            // Send success response
            wp_send_json_success($result);

        } catch (\Exception $e) {
            $this->logger->error('Failed to get products', [
                'error' => $e->getMessage(),
                'request' => $_POST
            ]);

            wp_send_json_error([
                'message' => $e->getMessage()
            ], 400);
        }
    }

    /**
     * Handle search products AJAX request
     */
    public function handle_search_products(): void
    {
        try {
            // Verify nonce
            if (!$this->security_service->verify_nonce($_POST['nonce'] ?? '', 'ajax')) {
                throw new \Exception('Invalid security token');
            }

            // Verify permissions
            if (!$this->security_service->canManageProducts()) {
                throw new \Exception('Insufficient permissions');
            }

            // Get search term
            $search_term = isset($_POST['search']) ? sanitize_text_field($_POST['search']) : '';
            
            if (empty($search_term)) {
                throw new \Exception('Search term is required');
            }

            // Minimum search length
            if (strlen($search_term) < 2) {
                throw new \Exception('Search term must be at least 2 characters');
            }

            // Get limit
            $limit = isset($_POST['limit']) ? absint($_POST['limit']) : 50;
            if ($limit < 1 || $limit > 200) $limit = 50;

            // Search products using controller
            $result = $this->product_controller->search_products($search_term, $limit);

            // Log the search
            $this->logger->debug('Products searched', [
                'search_term' => $search_term,
                'found' => $result['found']
            ]);

            // Send success response
            wp_send_json_success($result);

        } catch (\Exception $e) {
            $this->logger->error('Product search failed', [
                'error' => $e->getMessage(),
                'request' => $_POST
            ]);

            wp_send_json_error([
                'message' => $e->getMessage()
            ], 400);
        }
    }

    /**
     * Handle get single product AJAX request
     */
    public function handle_get_product(): void
    {
        try {
            // Verify nonce
            if (!$this->security_service->verify_nonce($_POST['nonce'] ?? '', 'ajax')) {
                throw new \Exception('Invalid security token');
            }

            // Verify permissions
            if (!$this->security_service->canManageProducts()) {
                throw new \Exception('Insufficient permissions');
            }

            // Get product ID
            $product_id = isset($_POST['product_id']) ? absint($_POST['product_id']) : 0;
            
            if (!$product_id) {
                throw new \Exception('Product ID is required');
            }

            // Get product from controller
            $result = $this->product_controller->get_product($product_id);

            wp_send_json_success($result);

        } catch (\Exception $e) {
            $this->logger->error('Failed to get product', [
                'error' => $e->getMessage(),
                'product_id' => $_POST['product_id'] ?? null
            ]);

            wp_send_json_error([
                'message' => $e->getMessage()
            ], 400);
        }
    }

    /**
     * Handle apply changes AJAX request
     */
    public function handle_apply_changes(): void
    {
        try {
            // Verify nonce
            if (!$this->security_service->verify_nonce($_POST['nonce'] ?? '', 'ajax')) {
                throw new \Exception('Invalid security token');
            }

            // Verify permissions
            if (!$this->security_service->canManageProducts()) {
                throw new \Exception('Insufficient permissions');
            }

            // Get product IDs
            $product_ids = isset($_POST['product_ids']) ? array_map('absint', (array)$_POST['product_ids']) : [];
            
            if (empty($product_ids)) {
                throw new \Exception('No products selected');
            }

            // Get form data
            $wootour_data = [
                'start_date' => isset($_POST['start_date']) ? sanitize_text_field($_POST['start_date']) : '',
                'end_date' => isset($_POST['end_date']) ? sanitize_text_field($_POST['end_date']) : '',
                'weekdays' => isset($_POST['weekdays']) ? array_map('sanitize_text_field', (array)$_POST['weekdays']) : [],
                'specific_dates' => isset($_POST['specific_dates']) ? array_map('sanitize_text_field', (array)$_POST['specific_dates']) : [],
                'exclusions' => isset($_POST['exclusions']) ? array_map('sanitize_text_field', (array)$_POST['exclusions']) : [],
            ];

            // Validate that at least one change is specified
            if (empty(array_filter($wootour_data))) {
                throw new \Exception('No changes specified');
            }

            // Apply updates using bulk update with meta data for Wootour
            $updates = [
                'meta' => [
                    'wootour_start_date' => $wootour_data['start_date'],
                    'wootour_end_date' => $wootour_data['end_date'],
                    'wootour_weekdays' => $wootour_data['weekdays'],
                    'wootour_specific_dates' => $wootour_data['specific_dates'],
                    'wootour_exclusions' => $wootour_data['exclusions'],
                ]
            ];

            $result = $this->product_controller->bulk_update_products($product_ids, $updates);
            
            $this->logger->info('Bulk changes applied', [
                'total' => $result['total'],
                'success' => count($result['success']),
                'failed' => count($result['failed'])
            ]);

            wp_send_json_success([
                'message' => sprintf(
                    __('Successfully updated %d of %d products', Constants::TEXT_DOMAIN),
                    count($result['success']),
                    $result['total']
                ),
                'results' => $result
            ]);

        } catch (\Exception $e) {
            $this->logger->error('Failed to apply changes', [
                'error' => $e->getMessage(),
                'request' => $_POST
            ]);

            wp_send_json_error([
                'message' => $e->getMessage()
            ], 400);
        }
    }

    /**
     * Handle get categories AJAX request
     */
    public function handle_get_categories(): void
    {
        try {
            // Verify nonce
            if (!$this->security_service->verify_nonce($_POST['nonce'] ?? '', 'ajax')) {
                throw new \Exception('Invalid security token');
            }

            // Verify permissions
            if (!$this->security_service->canManageProducts()) {
                throw new \Exception('Insufficient permissions');
            }

            // Get category tree from controller
            $categories = $this->product_controller->get_category_tree();

            wp_send_json_success([
                'categories' => $categories
            ]);

        } catch (\Exception $e) {
            $this->logger->error('Failed to get categories', [
                'error' => $e->getMessage()
            ]);

            wp_send_json_error([
                'message' => $e->getMessage()
            ], 400);
        }
    }

    /**
     * Send standardized JSON response
     * 
     * @param bool $success Success status
     * @param mixed $data Response data
     * @param string|null $message Optional message
     * @param int $status_code HTTP status code
     */
    private function send_json_response(bool $success, $data = null, ?string $message = null, int $status_code = 200): void
    {
        $response = [
            'success' => $success
        ];

        if ($message !== null) {
            $response['message'] = $message;
        }

        if ($data !== null) {
            $response['data'] = $data;
        }

        status_header($status_code);
        wp_send_json($response);
    }
}