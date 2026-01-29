<?php
/**
 * Wootour Bulk Editor - Batch Operation Model
 * 
 * Represents a batch operation with state tracking,
 * progress monitoring, and resume capabilities.
 * 
 * @package     WootourBulkEditor
 * @subpackage  Models
 
 * @license     GPL-2.0+
 * @since       1.0.0
 */

namespace WootourBulkEditor\Models;

use WootourBulkEditor\Core\Constants;

// Exit if accessed directly
defined('ABSPATH') || exit;

/**
 * Class BatchOperation
 * 
 * Value object representing a batch processing operation
 * with full state tracking for resume capabilities.
 */
final class BatchOperation
{
    /**
     * Operation statuses
     */
    public const STATUS_PENDING = 'pending';
    public const STATUS_PROCESSING = 'processing';
    public const STATUS_COMPLETED = 'completed';
    public const STATUS_FAILED = 'failed';
    public const STATUS_CANCELLED = 'cancelled';
    public const STATUS_PAUSED = 'paused';

    /**
     * Operation ID (unique identifier)
     */
    private string $id;

    /**
     * Operation status
     */
    private string $status;

    /**
     * Total number of products to process
     */
    private int $total_products;

    /**
     * Number of successfully processed products
     */
    private int $processed_count;

    /**
     * Number of failed products
     */
    private int $failed_count;

    /**
     * Array of processed product IDs
     */
    private array $processed_ids;

    /**
     * Array of failed products with error details
     */
    private array $failed_products;

    /**
     * Changes to apply to products
     */
    private array $changes;

    /**
     * User ID who initiated the operation
     */
    private int $user_id;

    /**
     * Start timestamp
     */
    private int $started_at;

    /**
     * Last update timestamp
     */
    private int $updated_at;

    /**
     * Completion timestamp
     */
    private int $completed_at;

    /**
     * Current batch number
     */
    private int $current_batch;

    /**
     * Total batches
     */
    private int $total_batches;

    /**
     * Operation metadata
     */
    private array $metadata;

    /**
     * Errors encountered during processing
     */
    private array $errors;

    /**
     * Warnings encountered during processing
     */
    private array $warnings;

    /**
     * Constructor
     */
    public function __construct(array $data = [])
    {
        $defaults = [
            'id'                => $this->generateId(),
            'status'            => self::STATUS_PENDING,
            'total_products'    => 0,
            'processed_count'   => 0,
            'failed_count'      => 0,
            'processed_ids'     => [],
            'failed_products'   => [],
            'changes'           => [],
            'user_id'           => get_current_user_id(),
            'started_at'        => time(),
            'updated_at'        => time(),
            'completed_at'      => 0,
            'current_batch'     => 0,
            'total_batches'     => 0,
            'metadata'          => [],
            'errors'            => [],
            'warnings'          => [],
        ];

        $data = array_merge($defaults, $data);
        $this->hydrate($data);
        $this->validate();
    }

    /**
     * Hydrate the object from array data
     */
    private function hydrate(array $data): void
    {
        $this->id               = (string) $data['id'];
        $this->status           = (string) $data['status'];
        $this->total_products   = (int) $data['total_products'];
        $this->processed_count  = (int) $data['processed_count'];
        $this->failed_count     = (int) $data['failed_count'];
        $this->processed_ids    = (array) $data['processed_ids'];
        $this->failed_products  = (array) $data['failed_products'];
        $this->changes          = (array) $data['changes'];
        $this->user_id          = (int) $data['user_id'];
        $this->started_at       = (int) $data['started_at'];
        $this->updated_at       = (int) $data['updated_at'];
        $this->completed_at     = (int) $data['completed_at'];
        $this->current_batch    = (int) $data['current_batch'];
        $this->total_batches    = (int) $data['total_batches'];
        $this->metadata         = (array) $data['metadata'];
        $this->errors           = (array) $data['errors'];
        $this->warnings         = (array) $data['warnings'];
    }

    /**
     * Validate the operation data
     * 
     * @throws \InvalidArgumentException If data is invalid
     */
    public function validate(): void
    {
        // Validate ID
        if (empty($this->id) || strlen($this->id) < 10) {
            throw new \InvalidArgumentException('Invalid operation ID');
        }

        // Validate status
        $valid_statuses = [
            self::STATUS_PENDING,
            self::STATUS_PROCESSING,
            self::STATUS_COMPLETED,
            self::STATUS_FAILED,
            self::STATUS_CANCELLED,
            self::STATUS_PAUSED,
        ];

        if (!in_array($this->status, $valid_statuses, true)) {
            throw new \InvalidArgumentException(sprintf(
                'Invalid status: %s',
                $this->status
            ));
        }

        // Validate counts
        if ($this->total_products < 0) {
            throw new \InvalidArgumentException('Total products cannot be negative');
        }

        if ($this->processed_count < 0) {
            throw new \InvalidArgumentException('Processed count cannot be negative');
        }

        if ($this->failed_count < 0) {
            throw new \InvalidArgumentException('Failed count cannot be negative');
        }

        if ($this->processed_count + $this->failed_count > $this->total_products) {
            throw new \InvalidArgumentException(
                'Processed + failed cannot exceed total products'
            );
        }

        // Validate timestamps
        if ($this->started_at <= 0) {
            throw new \InvalidArgumentException('Invalid start time');
        }

        if ($this->updated_at < $this->started_at) {
            throw new \InvalidArgumentException('Update time cannot be before start time');
        }

        if ($this->completed_at > 0 && $this->completed_at < $this->started_at) {
            throw new \InvalidArgumentException('Completion time cannot be before start time');
        }

        // Validate batches
        if ($this->current_batch < 0) {
            throw new \InvalidArgumentException('Current batch cannot be negative');
        }

        if ($this->total_batches < 0) {
            throw new \InvalidArgumentException('Total batches cannot be negative');
        }

        if ($this->current_batch > $this->total_batches && $this->total_batches > 0) {
            throw new \InvalidArgumentException('Current batch cannot exceed total batches');
        }
    }

    /**
     * Generate a unique operation ID
     */
    private function generateId(): string
    {
        return 'wbe_op_' . wp_generate_password(16, false) . '_' . time();
    }

    /**
     * Convert to array
     */
    public function toArray(): array
    {
        return [
            'id'                => $this->id,
            'status'            => $this->status,
            'total_products'    => $this->total_products,
            'processed_count'   => $this->processed_count,
            'failed_count'      => $this->failed_count,
            'processed_ids'     => $this->processed_ids,
            'failed_products'   => $this->failed_products,
            'changes'           => $this->changes,
            'user_id'           => $this->user_id,
            'started_at'        => $this->started_at,
            'updated_at'        => $this->updated_at,
            'completed_at'      => $this->completed_at,
            'current_batch'     => $this->current_batch,
            'total_batches'     => $this->total_batches,
            'metadata'          => $this->metadata,
            'errors'            => $this->errors,
            'warnings'          => $this->warnings,
        ];
    }

    /**
     * Convert to array for storage (minimal data)
     */
    public function toStorageArray(): array
    {
        return [
            'id'                => $this->id,
            'status'            => $this->status,
            'total_products'    => $this->total_products,
            'processed_count'   => $this->processed_count,
            'failed_count'      => $this->failed_count,
            'processed_ids'     => $this->processed_ids,
            'failed_products'   => $this->failed_products,
            'changes'           => $this->changes,
            'user_id'           => $this->user_id,
            'started_at'        => $this->started_at,
            'updated_at'        => $this->updated_at,
            'completed_at'      => $this->completed_at,
            'current_batch'     => $this->current_batch,
        ];
    }

    /**
     * Convert to array for API response
     */
    public function toApiArray(): array
    {
        $progress = $this->getProgressPercentage();
        
        return [
            'id'                => $this->id,
            'status'            => $this->status,
            'progress'          => $progress,
            'total_products'    => $this->total_products,
            'processed_count'   => $this->processed_count,
            'failed_count'      => $this->failed_count,
            'remaining_count'   => $this->getRemainingCount(),
            'user_id'           => $this->user_id,
            'started_at'        => $this->formatTimestamp($this->started_at),
            'updated_at'        => $this->formatTimestamp($this->updated_at),
            'completed_at'      => $this->completed_at ? $this->formatTimestamp($this->completed_at) : null,
            'duration'          => $this->getDuration(),
            'estimated_remaining' => $this->estimateRemainingTime(),
            'current_batch'     => $this->current_batch,
            'total_batches'     => $this->total_batches,
            'can_resume'        => $this->canResume(),
            'has_errors'        => !empty($this->errors),
            'has_warnings'      => !empty($this->warnings),
            'error_count'       => count($this->errors),
            'warning_count'     => count($this->warnings),
        ];
    }

    /**
     * Start the operation
     */
    public function start(int $total_products, array $changes): self
    {
        $clone = clone $this;
        
        $clone->status = self::STATUS_PROCESSING;
        $clone->total_products = $total_products;
        $clone->changes = $changes;
        $clone->started_at = time();
        $clone->updated_at = time();
        $clone->total_batches = (int) ceil($total_products / Constants::BATCH_SIZE);
        
        $clone->validate();
        return $clone;
    }

    /**
     * Update progress
     */
    public function updateProgress(
        int $processed_count,
        int $failed_count,
        array $processed_ids = [],
        array $failed_products = [],
        int $current_batch = 0
    ): self {
        $clone = clone $this;
        
        $clone->processed_count = $processed_count;
        $clone->failed_count = $failed_count;
        $clone->processed_ids = array_merge($clone->processed_ids, $processed_ids);
        $clone->failed_products = array_merge($clone->failed_products, $failed_products);
        $clone->current_batch = $current_batch;
        $clone->updated_at = time();
        
        // Auto-update status if complete
        if ($clone->isComplete()) {
            $clone->status = self::STATUS_COMPLETED;
            $clone->completed_at = time();
        }
        
        $clone->validate();
        return $clone;
    }

    /**
     * Add an error
     */
    public function addError(string $error, array $context = []): self
    {
        $clone = clone $this;
        
        $clone->errors[] = [
            'message'   => $error,
            'context'   => $context,
            'timestamp' => time(),
        ];
        
        $clone->updated_at = time();
        return $clone;
    }

    /**
     * Add a warning
     */
    public function addWarning(string $warning, array $context = []): self
    {
        $clone = clone $this;
        
        $clone->warnings[] = [
            'message'   => $warning,
            'context'   => $context,
            'timestamp' => time(),
        ];
        
        $clone->updated_at = time();
        return $clone;
    }

    /**
     * Mark as completed
     */
    public function complete(): self
    {
        $clone = clone $this;
        
        $clone->status = self::STATUS_COMPLETED;
        $clone->completed_at = time();
        $clone->updated_at = time();
        
        // Ensure counts are consistent
        if ($clone->processed_count + $clone->failed_count < $clone->total_products) {
            $clone->processed_count = $clone->total_products - $clone->failed_count;
        }
        
        $clone->validate();
        return $clone;
    }

    /**
     * Mark as failed
     */
    public function fail(string $reason = ''): self
    {
        $clone = clone $this;
        
        $clone->status = self::STATUS_FAILED;
        $clone->completed_at = time();
        $clone->updated_at = time();
        
        if (!empty($reason)) {
            $clone->addError($reason);
        }
        
        $clone->validate();
        return $clone;
    }

    /**
     * Mark as cancelled
     */
    public function cancel(): self
    {
        $clone = clone $this;
        
        $clone->status = self::STATUS_CANCELLED;
        $clone->completed_at = time();
        $clone->updated_at = time();
        
        $clone->validate();
        return $clone;
    }

    /**
     * Pause the operation
     */
    public function pause(): self
    {
        $clone = clone $this;
        
        $clone->status = self::STATUS_PAUSED;
        $clone->updated_at = time();
        
        $clone->validate();
        return $clone;
    }

    /**
     * Resume the operation
     */
    public function resume(): self
    {
        $clone = clone $this;
        
        $clone->status = self::STATUS_PROCESSING;
        $clone->updated_at = time();
        
        $clone->validate();
        return $clone;
    }

    /**
     * Check if operation is complete
     */
    public function isComplete(): bool
    {
        return $this->processed_count + $this->failed_count >= $this->total_products;
    }

    /**
     * Check if operation can be resumed
     */
    public function canResume(): bool
    {
        $resumable_statuses = [
            self::STATUS_PAUSED,
            self::STATUS_FAILED,
        ];
        
        return in_array($this->status, $resumable_statuses, true) && 
               !$this->isComplete();
    }

    /**
     * Check if operation is in progress
     */
    public function isInProgress(): bool
    {
        return $this->status === self::STATUS_PROCESSING;
    }

    /**
     * Get progress percentage
     */
    public function getProgressPercentage(): float
    {
        if ($this->total_products === 0) {
            return 0.0;
        }
        
        $total_processed = $this->processed_count + $this->failed_count;
        return min(100.0, ($total_processed / $this->total_products) * 100.0);
    }

    /**
     * Get remaining product count
     */
    public function getRemainingCount(): int
    {
        $total_processed = $this->processed_count + $this->failed_count;
        return max(0, $this->total_products - $total_processed);
    }

    /**
     * Get processing duration in seconds
     */
    public function getDuration(): int
    {
        $end_time = $this->completed_at ?: time();
        return max(0, $end_time - $this->started_at);
    }

    /**
     * Estimate remaining processing time
     */
    public function estimateRemainingTime(): ?int
    {
        if ($this->processed_count === 0 || $this->getDuration() === 0) {
            return null;
        }
        
        $time_per_product = $this->getDuration() / $this->processed_count;
        $remaining = $this->getRemainingCount();
        
        return (int) round($time_per_product * $remaining);
    }

    /**
     * Get failed products summary
     */
    public function getFailedSummary(): array
    {
        $summary = [];
        
        foreach ($this->failed_products as $failed) {
            if (is_array($failed)) {
                $summary[] = [
                    'product_id'   => $failed['product_id'] ?? 0,
                    'product_name' => $failed['product_name'] ?? 'Unknown',
                    'error'        => $failed['error'] ?? 'Unknown error',
                ];
            }
        }
        
        return $summary;
    }

    /**
     * Get operation statistics
     */
    public function getStatistics(): array
    {
        return [
            'success_rate' => $this->total_products > 0 ? 
                ($this->processed_count / $this->total_products) * 100 : 0,
            'failure_rate' => $this->total_products > 0 ? 
                ($this->failed_count / $this->total_products) * 100 : 0,
            'products_per_second' => $this->getDuration() > 0 ?
                $this->processed_count / $this->getDuration() : 0,
            'batches_completed' => $this->current_batch,
            'batches_remaining' => max(0, $this->total_batches - $this->current_batch),
        ];
    }

    /**
     * Check if operation is expired
     */
    public function isExpired(int $ttl = 86400): bool
    {
        // Default TTL: 24 hours
        return (time() - $this->updated_at) > $ttl;
    }

    /**
     * Format timestamp for display
     */
    private function formatTimestamp(int $timestamp): string
    {
        return date('Y-m-d H:i:s', $timestamp);
    }

    /**
     * Getters
     */
    public function getId(): string
    {
        return $this->id;
    }

    public function getStatus(): string
    {
        return $this->status;
    }

    public function getTotalProducts(): int
    {
        return $this->total_products;
    }

    public function getProcessedCount(): int
    {
        return $this->processed_count;
    }

    public function getFailedCount(): int
    {
        return $this->failed_count;
    }

    public function getProcessedIds(): array
    {
        return $this->processed_ids;
    }

    public function getFailedProducts(): array
    {
        return $this->failed_products;
    }

    public function getChanges(): array
    {
        return $this->changes;
    }

    public function getUserId(): int
    {
        return $this->user_id;
    }

    public function getStartedAt(): int
    {
        return $this->started_at;
    }

    public function getUpdatedAt(): int
    {
        return $this->updated_at;
    }

    public function getCompletedAt(): int
    {
        return $this->completed_at;
    }

    public function getCurrentBatch(): int
    {
        return $this->current_batch;
    }

    public function getTotalBatches(): int
    {
        return $this->total_batches;
    }

    public function getMetadata(): array
    {
        return $this->metadata;
    }

    public function getErrors(): array
    {
        return $this->errors;
    }

    public function getWarnings(): array
    {
        return $this->warnings;
    }

    /**
     * Magic getter for backward compatibility
     */
    public function __get(string $name)
    {
        $method = 'get' . str_replace('_', '', ucwords($name, '_'));
        
        if (method_exists($this, $method)) {
            return $this->$method();
        }
        
        trigger_error(
            sprintf('Undefined property: %s::$%s', __CLASS__, $name),
            E_USER_NOTICE
        );
        
        return null;
    }

    /**
     * Prevent setting properties directly
     */
    public function __set(string $name, $value)
    {
        throw new \LogicException(
            sprintf('%s is immutable. Use with*() methods instead.', __CLASS__)
        );
    }

    /**
     * String representation
     */
    public function __toString(): string
    {
        return sprintf(
            'Operation %s: %s (%d/%d products, %d%%)',
            substr($this->id, 0, 8),
            $this->status,
            $this->processed_count + $this->failed_count,
            $this->total_products,
            $this->getProgressPercentage()
        );
    }
}