<?php

/**
 * Wootour Bulk Editor - Batch Processor Service
 * 
 * Handles batch processing of product availability updates
 * with timeout protection, progress tracking, and resume capability.
 * 
 * @package     WootourBulkEditor
 * @subpackage  Services
 * @author      Votre Nom <email@example.com>
 * @license     GPL-2.0+
 * @since       1.0.0
 */

namespace WootourBulkEditor\Services;

use WootourBulkEditor\Core\Constants;
use WootourBulkEditor\Repositories\WootourRepository;
use WootourBulkEditor\Repositories\ProductRepository;
use WootourBulkEditor\Models\Product;
use WootourBulkEditor\Exceptions\BatchException;
use WootourBulkEditor\Traits\Singleton;

// Exit if accessed directly
defined('ABSPATH') || exit;

/**
 * Class BatchProcessor
 * 
 * Orchestrates batch updates with performance and reliability in mind.
 * Implements the 50-products-per-batch limit for shared hosting.
 */
final class BatchProcessor implements ServiceInterface
{
    use Singleton;

    /**
     * @var WootourRepository
     */
    private $wootour_repository;

    /**
     * @var ProductRepository
     */
    private $product_repository;

    /**
     * @var AvailabilityService
     */
    private $availability_service;

    /**
     * @var LoggerService
     */
    private $logger_service;

    /**
     * Private constructor
     */
    private function __construct()
    {
        // Dependencies injected via init
    }

    /**
     * Initialize with dependencies
     */
    public function init(): void
    {
        $this->wootour_repository = WootourRepository::getInstance();
        $this->product_repository = ProductRepository::getInstance();
        $this->availability_service = AvailabilityService::getInstance();
        $this->logger_service = LoggerService::getInstance();
    }

    /**
     * Process batch update for multiple products
     * 
     * @param array $product_ids Array of product IDs to update
     * @param array $changes Availability changes to apply
     * @param string $operation_id Unique operation ID for tracking
     * @return array Result with success count, errors, and progress
     * @throws BatchException If batch processing fails
     */
    public function processBatch(array $product_ids, array $changes, string $operation_id = ''): array
    {
        // Validate inputs
        $this->validateBatchInput($product_ids, $changes);

        // Generate operation ID if not provided
        if (empty($operation_id)) {
            $operation_id = $this->generateOperationId($product_ids, $changes);
        }

        // Check if this is a resume operation
        $resume_data = $this->getResumeData($operation_id);
        $is_resume = !empty($resume_data);

        // Setup batch tracking
        $batch_state = $this->initializeBatchState(
            $product_ids,
            $changes,
            $operation_id,
            $is_resume,
            $resume_data
        );

        // Apply memory and time limits
        $this->applyResourceLimits();

        try {
            // Process in batches of 50 products
            $result = $this->processInChunks($batch_state);

            // Clean up on complete success
            if ($result['success_count'] === count($batch_state['all_product_ids'])) {
                $this->cleanupOperation($operation_id);
                $this->logBatchCompletion($operation_id, $result);
            }

            return $result;
        } catch (\Throwable $e) {
            // Save state for resume
            $this->saveResumeState($batch_state);

            throw BatchException::processingFailed(
                $operation_id,
                $e->getMessage(),
                $batch_state['processed_count'] ?? 0
            );
        }
    }

    /**
     * Validate batch input parameters
     */
    private function validateBatchInput(array $product_ids, array $changes): void
    {
        if (empty($product_ids)) {
            throw BatchException::emptyProductList();
        }

        if (empty($changes)) {
            throw BatchException::emptyChanges();
        }

        // Validate changes structure
        try {
            $this->availability_service->validateChanges($changes);
        } catch (\Exception $e) {
            throw BatchException::invalidChanges($e->getMessage());
        }

        // Limit total products to prevent memory issues
        $max_products = apply_filters('wbe_max_batch_products', 1000);
        if (count($product_ids) > $max_products) {
            throw BatchException::tooManyProducts(count($product_ids), $max_products);
        }
    }

    /**
     * Generate unique operation ID
     */
    private function generateOperationId(array $product_ids, array $changes): string
    {
        $hash_data = [
            'product_ids' => $product_ids,
            'changes' => $changes,
            'timestamp' => time(),
            'user_id' => get_current_user_id(),
        ];

        return 'wbe_op_' . md5(serialize($hash_data));
    }

    /**
     * Get resume data for operation
     */
    private function getResumeData(string $operation_id): array
    {
        $data = get_transient('wbe_resume_' . $operation_id);

        if ($data && is_array($data)) {
            // Validate resume data is still valid
            if ($this->validateResumeData($data)) {
                return $data;
            }
        }

        return [];
    }

    /**
     * Validate resume data integrity
     */
    private function validateResumeData(array $data): bool
    {
        $required_keys = [
            'operation_id',
            'all_product_ids',
            'changes',
            'processed_ids',
            'failed_ids',
            'started_at',
        ];

        foreach ($required_keys as $key) {
            if (!array_key_exists($key, $data)) {
                return false;
            }
        }

        // Check if operation is too old (24h max)
        if (time() - $data['started_at'] > DAY_IN_SECONDS) {
            return false;
        }

        return true;
    }

    /**
     * Initialize batch state
     */
    private function initializeBatchState(
        array $product_ids,
        array $changes,
        string $operation_id,
        bool $is_resume,
        array $resume_data
    ): array {
        if ($is_resume) {
            // Resume from saved state
            $state = $resume_data;
            $state['is_resume'] = true;
            $state['resumed_at'] = time();

            $this->logResumeOperation($operation_id, $state);
        } else {
            // New operation
            $state = [
                'operation_id'      => $operation_id,
                'all_product_ids'   => $this->product_repository->validateProductIds($product_ids),
                'changes'          => $changes,
                'processed_ids'    => [],
                'failed_ids'       => [],
                'errors'           => [],
                'warnings'         => [],
                'started_at'       => time(),
                'is_resume'        => false,
                'processed_count'  => 0,
                'current_batch'    => 0,
            ];

            $this->logStartOperation($operation_id, $state);
        }

        // Calculate remaining products
        $state['remaining_ids'] = array_diff(
            $state['all_product_ids'],
            $state['processed_ids'],
            array_keys($state['failed_ids'])
        );

        // Save initial state
        $this->saveBatchState($state);

        return $state;
    }

    /**
     * Apply resource limits for shared hosting
     */
    private function applyResourceLimits(): void
    {
        // Increase time limit
        set_time_limit(Constants::TIMEOUT_SECONDS);

        // Increase memory limit
        if (wp_convert_hr_to_bytes(Constants::MEMORY_LIMIT) > wp_convert_hr_to_bytes(ini_get('memory_limit'))) {
            @ini_set('memory_limit', Constants::MEMORY_LIMIT);
        }

        // Disable WordPress heartbeats during processing
        add_filter('heartbeat_settings', function ($settings) {
            $settings['interval'] = 120; // Slow down heartbeats
            return $settings;
        });
    }

    /**
     * Process products in chunks of 50
     */
    private function processInChunks(array &$state): array
    {
        $start_time = time();
        $chunk_size = Constants::BATCH_SIZE;

        // Process remaining products in chunks
        while (!empty($state['remaining_ids'])) {
            // Check timeout
            if (time() - $start_time >= Constants::TIMEOUT_SECONDS - 5) {
                $this->saveResumeState($state);
                throw BatchException::timeoutExceeded(Constants::TIMEOUT_SECONDS);
            }

            // Get next chunk
            $chunk_ids = array_slice($state['remaining_ids'], 0, $chunk_size);

            // Process chunk
            $chunk_result = $this->processChunk($chunk_ids, $state['changes']);

            // Update state
            $state['current_batch']++;
            $state['processed_ids'] = array_merge($state['processed_ids'], $chunk_result['processed_ids']);
            $state['failed_ids'] = array_merge($state['failed_ids'], $chunk_result['failed_ids']);
            $state['errors'] = array_merge($state['errors'], $chunk_result['errors']);
            $state['warnings'] = array_merge($state['warnings'], $chunk_result['warnings']);

            // Recalculate remaining
            $state['remaining_ids'] = array_diff(
                $state['all_product_ids'],
                $state['processed_ids'],
                array_keys($state['failed_ids'])
            );

            $state['processed_count'] = count($state['processed_ids']);

            // Save progress
            $this->saveBatchState($state);

            // Log chunk completion
            $this->logChunkCompletion($state['operation_id'], $state['current_batch'], $chunk_result);

            // If we have warnings or errors, add small delay to prevent server overload
            if (!empty($chunk_result['errors']) || !empty($chunk_result['warnings'])) {
                usleep(100000); // 0.1 second delay
            }
        }

        // Compile final result
        return $this->compileResult($state);
    }

    /**
     * Process a single chunk of products
     */
    private function processChunk(array $product_ids, array $changes): array
    {
        $result = [
            'processed_ids' => [],
            'failed_ids'    => [],
            'errors'        => [],
            'warnings'      => [],
        ];

        // Get products for this chunk
        $products = $this->product_repository->getProductsByIds($product_ids);

        foreach ($products as $product) {
            try {
                $this->processSingleProduct($product, $changes);
                $result['processed_ids'][] = $product->getId();
            } catch (\Exception $e) {
                $error_id = $product->getId();
                $result['failed_ids'][$error_id] = [
                    'product_id' => $error_id,
                    'product_name' => $product->getName(),
                    'error' => $e->getMessage(),
                    'code' => $e->getCode(),
                ];
                $result['errors'][] = sprintf(
                    'Product #%d "%s": %s',
                    $error_id,
                    $product->getName(),
                    $e->getMessage()
                );
            }
        }

        // Handle missing products (deleted during processing)
        $processed_ids = array_map(fn($p) => $p->getId(), $products);
        $missing_ids = array_diff($product_ids, $processed_ids);

        foreach ($missing_ids as $missing_id) {
            $result['failed_ids'][$missing_id] = [
                'product_id' => $missing_id,
                'product_name' => 'Unknown product (may have been deleted)',
                'error' => 'Product not found',
                'code' => 404,
            ];
            $result['errors'][] = sprintf('Product #%d not found', $missing_id);
        }

        return $result;
    }

    private function processSingleProduct(Product $product, array $changes): void
    {
        $product_id = $product->getId();

        error_log('[WBE BatchProcessor] Processing product #' . $product_id . ': ' . $product->getName());

        try {
            // Check if product still exists and is valid
            if (!$this->product_repository->isValidProduct($product_id)) {
                throw new \Exception('Product no longer exists or is invalid');
            }

            // Get existing availability
            $existing_availability = $this->wootour_repository->getAvailability($product_id);

            error_log('[WBE BatchProcessor] Existing availability: ' . print_r($existing_availability->toArray(), true));

            //  CORRECTION : Utiliser withProductId() au lieu de setProductId()
            // Votre classe Availability est immuable
            if (method_exists($existing_availability, 'withProductId')) {
                $existing_availability = $existing_availability->withProductId($product_id);
            }

            // Check if changes will actually modify anything
            if (!$this->availability_service->hasEffectiveChanges($existing_availability, $changes)) {
                error_log('[WBE BatchProcessor] Product #' . $product_id . ' skipped - no effective changes');
                return;
            }

            // Calculate conflicts/warnings
            $conflicts = $this->availability_service->calculateConflicts($existing_availability, $changes);

            // Merge changes
            $merged_availability = $this->availability_service->mergeChanges($existing_availability, $changes);

            //  CORRECTION : S'assurer que l'ID du produit est défini
            if (method_exists($merged_availability, 'withProductId')) {
                $merged_availability = $merged_availability->withProductId($product_id);
            }

            error_log('[WBE BatchProcessor] Merged availability: ' . print_r($merged_availability->toArray(), true));

            // Save to Wootour
            $save_result = $this->wootour_repository->updateAvailability($product_id, $merged_availability->toArray());

            if (!$save_result) {
                throw new \Exception('Failed to save availability data');
            }

            error_log('[WBE BatchProcessor] Product #' . $product_id . ' successfully updated');

            // Log success
            $this->logger_service->logProductUpdated(
                $product_id,
                $existing_availability->toArray(),
                $merged_availability->toArray(),
                $changes,
                $conflicts
            );

            // Clear relevant caches
            $this->product_repository->clearCache($product_id);
        } catch (\Exception $e) {
            error_log('[WBE BatchProcessor] ERROR processing product #' . $product_id . ': ' . $e->getMessage());
            // Log failure
            $this->logger_service->logProductFailed($product_id, $changes, $e->getMessage());
            throw $e; // Re-throw for chunk processing
        }
    }
    /**
     * Save batch state for resume capability
     */
    private function saveBatchState(array $state): void
    {
        // Keep only essential data for resume
        $resume_data = [
            'operation_id'      => $state['operation_id'],
            'all_product_ids'   => $state['all_product_ids'],
            'changes'          => $state['changes'],
            'processed_ids'    => $state['processed_ids'],
            'failed_ids'       => $state['failed_ids'],
            'started_at'       => $state['started_at'],
            'last_updated'     => time(),
        ];

        // Save with 1-hour expiry (enough time to resume)
        set_transient('wbe_resume_' . $state['operation_id'], $resume_data, HOUR_IN_SECONDS);

        // Also save progress for UI
        $progress = $this->calculateProgress($state);
        set_transient('wbe_progress_' . $state['operation_id'], $progress, 10 * MINUTE_IN_SECONDS);
    }

    /**
     * Save resume state (when interrupted)
     */
    private function saveResumeState(array $state): void
    {
        $this->saveBatchState($state);

        // Log interruption
        $this->logger_service->logBatchInterrupted(
            $state['operation_id'],
            $state['processed_count'],
            count($state['all_product_ids'])
        );
    }

    /**
     * Calculate progress percentage
     */
    private function calculateProgress(array $state): array
    {
        $total = count($state['all_product_ids']);
        $processed = count($state['processed_ids']);
        $failed = count($state['failed_ids']);

        return [
            'total' => $total,
            'processed' => $processed,
            'failed' => $failed,
            'remaining' => $total - $processed - $failed,
            'percentage' => $total > 0 ? round(($processed + $failed) / $total * 100, 1) : 0,
            'current_batch' => $state['current_batch'],
            'estimated_remaining' => $this->estimateRemainingTime($state),
            'timestamp' => time(),
        ];
    }

    /**
     * Estimate remaining processing time
     */
    private function estimateRemainingTime(array $state): int
    {
        $processed = count($state['processed_ids']) + count($state['failed_ids']);
        $remaining = count($state['remaining_ids']);

        if ($processed === 0 || $remaining === 0) {
            return 0;
        }

        $elapsed = time() - $state['started_at'];
        $time_per_product = $elapsed / $processed;

        return (int) round($time_per_product * $remaining);
    }

    /**
     * Compile final result from state
     */
    private function compileResult(array $state): array
    {
        $total = count($state['all_product_ids']);
        $processed = count($state['processed_ids']);
        $failed = count($state['failed_ids']);

        return [
            'operation_id'    => $state['operation_id'],
            'total_products'  => $total,
            'success_count'   => $processed,
            'failed_count'    => $failed,
            'errors'          => $state['errors'],
            'warnings'        => $state['warnings'],
            'failed_details'  => $state['failed_ids'],
            'is_complete'     => empty($state['remaining_ids']),
            'is_resume'       => $state['is_resume'] ?? false,
            'processing_time' => time() - $state['started_at'],
            'batch_count'     => $state['current_batch'],
        ];
    }

    /**
     * Clean up operation data
     */
    private function cleanupOperation(string $operation_id): void
    {
        delete_transient('wbe_resume_' . $operation_id);
        delete_transient('wbe_progress_' . $operation_id);
    }

    /**
     * Get progress for an operation
     * 
     * @param string $operation_id Operation ID
     * @return array|null Progress data or null if not found
     */
    public function getProgress(string $operation_id): ?array
    {
        $progress = get_transient('wbe_progress_' . $operation_id);

        if ($progress && is_array($progress)) {
            $resume_data = get_transient('wbe_resume_' . $operation_id);
            $progress['can_resume'] = !empty($resume_data);
            $progress['operation_id'] = $operation_id;
            return $progress;
        }

        return null;
    }

    /**
     * Resume a failed or interrupted operation
     * 
     * @param string $operation_id Operation ID to resume
     * @return array Result with success count, errors, and progress
     * @throws BatchException If operation cannot be resumed
     */
    public function resumeOperation(string $operation_id): array
    {
        $resume_data = $this->getResumeData($operation_id);

        if (empty($resume_data)) {
            throw BatchException::cannotResume($operation_id);
        }

        // Check if operation is already complete
        $remaining = array_diff(
            $resume_data['all_product_ids'],
            $resume_data['processed_ids'],
            array_keys($resume_data['failed_ids'])
        );

        if (empty($remaining)) {
            throw BatchException::alreadyCompleted($operation_id);
        }

        // Resume processing
        return $this->processBatch([], [], $operation_id);
    }

    /**
     * Cancel an operation
     * 
     * @param string $operation_id Operation ID to cancel
     * @return bool True if cancelled
     */
    public function cancelOperation(string $operation_id): bool
    {
        $this->cleanupOperation($operation_id);
        $this->logger_service->logBatchCancelled($operation_id);

        return true;
    }

    /**
     * Preview changes without applying them
     * 
     * @param array $product_ids Product IDs to preview
     * @param array $changes Changes to preview
     * @param int $sample_size Maximum number of products to sample
     * @return array Preview results with conflicts and warnings
     */
    public function previewChanges(array $product_ids, array $changes, int $sample_size = 10): array
    {
        if (empty($product_ids) || empty($changes)) {
            return ['samples' => [], 'summary' => []];
        }

        // Take a sample of products
        $sample_ids = array_slice($product_ids, 0, min($sample_size, count($product_ids)));
        $products = $this->product_repository->getProductsByIds($sample_ids);

        $preview_results = [];
        $total_conflicts = 0;
        $total_warnings = 0;

        foreach ($products as $product) {
            try {
                $existing = $this->wootour_repository->getAvailability($product->getId());
                $conflicts = $this->availability_service->calculateConflicts($existing, $changes);

                $preview_results[] = [
                    'product_id'   => $product->getId(),
                    'product_name' => $product->getName(),
                    'has_wootour'  => $product->hasWootour(),
                    'existing'     => $this->availability_service->formatForDisplay($existing),
                    'conflicts'    => $conflicts,
                    'preview'      => $this->availability_service->calculatePreview(
                        $existing,
                        $changes,
                        date('Y-m-d'),
                        date('Y-m-d', strtotime('+30 days'))
                    ),
                ];

                foreach ($conflicts as $conflict) {
                    if ($conflict['type'] === 'error') {
                        $total_conflicts++;
                    } else {
                        $total_warnings++;
                    }
                }
            } catch (\Exception $e) {
                $preview_results[] = [
                    'product_id'   => $product->getId(),
                    'product_name' => $product->getName(),
                    'error'        => $e->getMessage(),
                ];
            }
        }

        return [
            'samples' => $preview_results,
            'summary' => [
                'sample_size'   => count($sample_ids),
                'total_products' => count($product_ids),
                'total_conflicts' => $total_conflicts,
                'total_warnings' => $total_warnings,
                'has_errors'    => $total_conflicts > 0,
            ],
        ];
    }

    /**
     * Logging methods
     */
    private function logStartOperation(string $operation_id, array $state): void
    {
        $this->logger_service->logBatchStarted(
            $operation_id,
            count($state['all_product_ids']),
            $state['changes'],
            get_current_user_id()
        );
    }

    private function logResumeOperation(string $operation_id, array $state): void
    {
        $this->logger_service->logBatchResumed(
            $operation_id,
            count($state['all_product_ids']),
            count($state['processed_ids'])
        );
    }

    private function logChunkCompletion(string $operation_id, int $batch_number, array $chunk_result): void
    {
        $this->logger_service->logBatchChunk(
            $operation_id,
            $batch_number,
            count($chunk_result['processed_ids']),
            count($chunk_result['failed_ids'])
        );
    }

    private function logBatchCompletion(string $operation_id, array $result): void
    {
        $this->logger_service->logBatchCompleted(
            $operation_id,
            $result['success_count'],
            $result['failed_count'],
            $result['processing_time']
        );
    }

    /**
     * Get batch statistics
     */
    public function getStatistics(): array
    {
        return [
            'max_batch_size' => Constants::BATCH_SIZE,
            'timeout_seconds' => Constants::TIMEOUT_SECONDS,
            'memory_limit' => Constants::MEMORY_LIMIT,
            'max_total_products' => apply_filters('wbe_max_batch_products', 1000),
            'performance' => $this->estimatePerformance(),
        ];
    }

    /**
     * Estimate processing performance
     */
    private function estimatePerformance(): array
    {
        // This would ideally track actual performance over time
        return [
            'estimated_products_per_second' => 5, // Conservative estimate
            'estimated_batch_time' => ceil(Constants::BATCH_SIZE / 5),
            'recommended_max' => Constants::TIMEOUT_SECONDS * 3, // 3x timeout buffer
        ];
    }
}
