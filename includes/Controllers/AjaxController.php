<?php

/**
 * Wootour Bulk Editor - AJAX Controller
 * 
 * Handles all AJAX requests from the admin interface
 * with security validation and standardized responses.
 * 
 * @package     WootourBulkEditor
 * @subpackage  Controllers
 * @author      Votre Nom <email@example.com>
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

            case Constants::AJAX_ACTIONS['validate_dates']:
                return $this->handle_validate_dates();

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

    // Dans validate_ajax_request() de la NOUVELLE version :
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

        // Commenter temporairement la vérification du referer
        // if (!$this->security_service->check_referer()) {
        //     throw new \RuntimeException('Invalid request origin.', 403);
        // }

        // Check if it's an AJAX request
        if (!defined('DOING_AJAX') || !DOING_AJAX) {
            throw new \RuntimeException('Invalid request method.', 400);
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
        error_log('[WBE] REQUEST data: ' . print_r($_REQUEST, true));

        // Parse request data
        $product_ids = $this->parse_product_ids();
        $changes = $this->parse_changes();
        $operation_id = sanitize_text_field($_REQUEST['operation_id'] ?? '');

        error_log('[WBE] Parsed product IDs: ' . print_r($product_ids, true));
        error_log('[WBE] Parsed changes: ' . print_r($changes, true));

        // Validate we have something to process
        if (empty($product_ids)) {
            throw new ValidationException('No products selected.');
        }

        if (empty($changes)) {
            throw new ValidationException('No changes specified.');
        }

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
     * Handle: Validate dates and changes
     */
    private function handle_validate_dates(): array
    {
        $changes = $this->parse_changes();

        // Validate changes structure
        $this->availability_service->validateChanges($changes);

        // Check for conflicts in empty availability (basic validation)
        $empty_availability = new \WootourBulkEditor\Models\Availability();
        $conflicts = $this->availability_service->calculateConflicts($empty_availability, $changes);

        return [
            'success' => true,
            'data' => [
                'valid' => empty(array_filter($conflicts, fn($c) => $c['type'] === 'error')),
                'conflicts' => $conflicts,
                'changes' => $changes,
            ],
        ];
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
     * Handle: Preview changes before applying
     */
    private function handle_preview_changes(): array
    {
        $product_ids = $this->parse_product_ids();
        $changes = $this->parse_changes();
        $sample_size = min(20, max(1, (int) ($_REQUEST['sample_size'] ?? 5)));

        if (empty($product_ids)) {
            throw new ValidationException('No products selected for preview.');
        }

        if (empty($changes)) {
            throw new ValidationException('No changes specified for preview.');
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
                    // 🔥 CORRECTION CRITIQUE : Convertir les dates du format DD/MM/YYYY en YYYY-MM-DD
                    if ($field === 'start_date' || $field === 'end_date') {
                        error_log('[WBE AjaxController] Raw date value for ' . $field . ': ' . $value);

                        // Vérifier si c'est déjà au format YYYY-MM-DD
                        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $value)) {
                            $changes[$field] = sanitize_text_field($value);
                            error_log('[WBE AjaxController] Date already YYYY-MM-DD: ' . $value);
                        }
                        // Vérifier si c'est au format DD/MM/YYYY
                        elseif (preg_match('/^(\d{2})\/(\d{2})\/(\d{4})$/', $value, $matches)) {
                            // Convertir DD/MM/YYYY en YYYY-MM-DD
                            $converted_date = sprintf(
                                '%04d-%02d-%02d',
                                $matches[3], // année
                                $matches[2], // mois  
                                $matches[1]  // jour
                            );
                            $changes[$field] = sanitize_text_field($converted_date);
                            error_log('[WBE AjaxController] Converted date ' . $field . ': ' . $value . ' → ' . $converted_date);
                        } else {
                            // Format inconnu, laisser tel quel (sera rejeté par la validation)
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

        error_log('[WBE AjaxController] Final parsed changes: ' . print_r($changes, true));
        error_log('[WBE AjaxController] === END parse_changes ===');

        return $changes;
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
