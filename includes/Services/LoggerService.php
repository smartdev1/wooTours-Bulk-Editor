<?php
/**
 * Wootour Bulk Editor - Logger Service
 * 
 * Handles logging of all plugin operations for traceability.
 * Stores logs in WordPress options without creating new tables.
 * 
 * @package     WootourBulkEditor
 * @subpackage  Services
 * @author      Votre Nom <email@example.com>
 * @license     GPL-2.0+
 * @since       1.0.0
 */

namespace WootourBulkEditor\Services;

use WootourBulkEditor\Core\Constants;
use WootourBulkEditor\Traits\Singleton;

// Exit if accessed directly
defined('ABSPATH') || exit;

/**
 * Class LoggerService
 * 
 * Centralized logging service with automatic log rotation.
 */
final class LoggerService implements ServiceInterface
{
    use Singleton;

    /**
     * Log levels
     */
    private const LEVELS = [
        'info'     => 1,
        'warning'  => 2,
        'error'    => 3,
        'critical' => 4,
    ];

    /**
     * Initialize service
     */
    public function init(): void
    {
        // Schedule daily log cleanup
        if (!wp_next_scheduled('wbe_daily_log_cleanup')) {
            wp_schedule_event(time(), 'daily', 'wbe_daily_log_cleanup');
        }
        
        add_action('wbe_daily_log_cleanup', [$this, 'cleanup_old_logs']);
    }

    /**
     * Log a batch operation start
     */
    public function logBatchStarted(
        string $operation_id, 
        int $product_count, 
        array $changes, 
        int $user_id = 0
    ): void {
        $this->log(
            'batch_started',
            sprintf('Batch operation %s started for %d products', $operation_id, $product_count),
            [
                'operation_id'  => $operation_id,
                'product_count' => $product_count,
                'changes'       => $this->sanitize_changes($changes),
                'user_id'       => $user_id ?: get_current_user_id(),
                'user_ip'       => $this->get_client_ip(),
                'timestamp'     => time(),
            ],
            'info'
        );
    }

    /**
     * Log a batch operation resume
     */
    public function logBatchResumed(
        string $operation_id, 
        int $total_products, 
        int $processed_so_far
    ): void {
        $this->log(
            'batch_resumed',
            sprintf('Batch operation %s resumed (%d/%d processed)', 
                $operation_id, $processed_so_far, $total_products),
            [
                'operation_id'      => $operation_id,
                'total_products'    => $total_products,
                'processed_so_far'  => $processed_so_far,
                'user_id'           => get_current_user_id(),
                'timestamp'         => time(),
            ],
            'info'
        );
    }

    /**
     * Log a batch operation chunk completion
     */
    public function logBatchChunk(
        string $operation_id, 
        int $chunk_number, 
        int $processed, 
        int $failed
    ): void {
        $this->log(
            'batch_chunk',
            sprintf('Batch chunk %d completed for operation %s (%d processed, %d failed)', 
                $chunk_number, $operation_id, $processed, $failed),
            [
                'operation_id'  => $operation_id,
                'chunk_number'  => $chunk_number,
                'processed'     => $processed,
                'failed'        => $failed,
                'timestamp'     => time(),
            ],
            'info'
        );
    }

    /**
     * Log a batch operation completion
     */
    public function logBatchCompleted(
        string $operation_id, 
        int $success_count, 
        int $failed_count, 
        int $processing_time
    ): void {
        $this->log(
            'batch_completed',
            sprintf('Batch operation %s completed: %d success, %d failed, %d seconds', 
                $operation_id, $success_count, $failed_count, $processing_time),
            [
                'operation_id'      => $operation_id,
                'success_count'     => $success_count,
                'failed_count'      => $failed_count,
                'processing_time'   => $processing_time,
                'user_id'           => get_current_user_id(),
                'timestamp'         => time(),
            ],
            'info'
        );
    }

    /**
     * Log a batch operation interruption
     */
    public function logBatchInterrupted(
        string $operation_id, 
        int $processed_count, 
        int $total_count
    ): void {
        $this->log(
            'batch_interrupted',
            sprintf('Batch operation %s interrupted: %d/%d processed', 
                $operation_id, $processed_count, $total_count),
            [
                'operation_id'      => $operation_id,
                'processed_count'   => $processed_count,
                'total_count'       => $total_count,
                'user_id'           => get_current_user_id(),
                'timestamp'         => time(),
            ],
            'warning'
        );
    }

    /**
     * Log a batch operation cancellation
     */
    public function logBatchCancelled(string $operation_id): void
    {
        $this->log(
            'batch_cancelled',
            sprintf('Batch operation %s cancelled by user', $operation_id),
            [
                'operation_id'  => $operation_id,
                'user_id'       => get_current_user_id(),
                'timestamp'     => time(),
            ],
            'warning'
        );
    }

    /**
     * Log a single product update
     */
    public function logProductUpdated(
        int $product_id, 
        array $old_data, 
        array $new_data, 
        array $changes, 
        array $conflicts = []
    ): void {
        $this->log(
            'product_updated',
            sprintf('Product #%d availability updated', $product_id),
            [
                'product_id'    => $product_id,
                'old_data'      => $this->sanitize_availability_data($old_data),
                'new_data'      => $this->sanitize_availability_data($new_data),
                'changes'       => $this->sanitize_changes($changes),
                'conflicts'     => $conflicts,
                'user_id'       => get_current_user_id(),
                'timestamp'     => time(),
            ],
            'info'
        );
    }

    /**
     * Log a product update skip (no effective changes)
     */
    public function logProductSkipped(int $product_id, array $changes): void
    {
        $this->log(
            'product_skipped',
            sprintf('Product #%d skipped (no effective changes)', $product_id),
            [
                'product_id'    => $product_id,
                'changes'       => $this->sanitize_changes($changes),
                'reason'        => 'no_effective_changes',
                'user_id'       => get_current_user_id(),
                'timestamp'     => time(),
            ],
            'info'
        );
    }

    /**
     * Log a product update failure
     */
    public function logProductFailed(int $product_id, array $changes, string $error): void
    {
        $this->log(
            'product_failed',
            sprintf('Product #%d update failed: %s', $product_id, $error),
            [
                'product_id'    => $product_id,
                'changes'       => $this->sanitize_changes($changes),
                'error'         => $error,
                'user_id'       => get_current_user_id(),
                'timestamp'     => time(),
            ],
            'error'
        );
    }

    /**
     * Log a validation error
     */
    public function logValidationError(string $context, string $error, array $data = []): void
    {
        $this->log(
            'validation_error',
            sprintf('Validation error in %s: %s', $context, $error),
            array_merge($data, [
                'context'   => $context,
                'error'     => $error,
                'user_id'   => get_current_user_id(),
                'timestamp' => time(),
            ]),
            'warning'
        );
    }

    /**
     * Log a security event
     */
    public function logSecurityEvent(string $event, array $data = []): void
    {
        $this->log(
            'security_event',
            sprintf('Security event: %s', $event),
            array_merge($data, [
                'event'     => $event,
                'user_ip'   => $this->get_client_ip(),
                'user_agent'=> $_SERVER['HTTP_USER_AGENT'] ?? 'unknown',
                'timestamp' => time(),
            ]),
            'warning'
        );
    }

    /**
     * Log a system event (plugin activation, deactivation, etc.)
     */
    public function logSystemEvent(string $event, array $data = []): void
    {
        $this->log(
            'system_event',
            sprintf('System event: %s', $event),
            array_merge($data, [
                'event'     => $event,
                'timestamp' => time(),
            ]),
            'info'
        );
    }

    /**
     * Generic log method
     */
    public function log(
        string $action, 
        string $message, 
        array $data = [], 
        string $level = 'info'
    ): bool {
        // Validate log level
        if (!isset(self::LEVELS[$level])) {
            $level = 'info';
        }

        // Prepare log entry
        $log_entry = [
            'action'    => $action,
            'message'   => $message,
            'level'     => $level,
            'data'      => $data,
            'timestamp' => current_time('mysql'),
            'microtime' => microtime(true),
        ];

        // Get current logs
        $log_key = $this->get_log_key();
        $logs = get_option($log_key, []);

        // Add new entry
        $logs[] = $log_entry;

        // Apply log rotation
        if (count($logs) > Constants::LOG_MAX_ENTRIES) {
            $logs = array_slice($logs, -Constants::LOG_MAX_ENTRIES);
        }

        // Save logs
        $result = update_option($log_key, $logs, false);

        // Also log to PHP error log for critical errors
        if ($level === 'critical' || $level === 'error') {
            error_log(sprintf(
                '[WootourBulkEditor] %s: %s - %s',
                strtoupper($level),
                $action,
                $message
            ));
        }

        return $result !== false;
    }

    /**
     * Get logs with filtering options
     */
    public function getLogs(
        int $limit = 100,
        string $level = null,
        string $action = null,
        int $days = null
    ): array {
        $log_key = $this->get_log_key();
        $logs = get_option($log_key, []);

        // Reverse to get newest first
        $logs = array_reverse($logs);

        // Apply filters
        $filtered_logs = [];
        $cutoff_time = $days ? strtotime("-{$days} days") : 0;

        foreach ($logs as $log) {
            // Filter by level
            if ($level && ($log['level'] ?? 'info') !== $level) {
                continue;
            }

            // Filter by action
            if ($action && ($log['action'] ?? '') !== $action) {
                continue;
            }

            // Filter by date
            if ($cutoff_time) {
                $log_time = strtotime($log['timestamp'] ?? '');
                if ($log_time < $cutoff_time) {
                    continue;
                }
            }

            $filtered_logs[] = $log;

            // Apply limit
            if (count($filtered_logs) >= $limit) {
                break;
            }
        }

        return $filtered_logs;
    }

    /**
     * Get logs by operation ID
     */
    public function getLogsByOperation(string $operation_id, int $limit = 50): array
    {
        $logs = $this->getLogs(1000); // Get more logs to filter through
        
        $operation_logs = [];
        
        foreach ($logs as $log) {
            if (isset($log['data']['operation_id']) && 
                $log['data']['operation_id'] === $operation_id) {
                $operation_logs[] = $log;
            }
            
            if (count($operation_logs) >= $limit) {
                break;
            }
        }
        
        return $operation_logs;
    }

    /**
     * Get logs summary statistics
     */
    public function getLogsSummary(int $days = 30): array
    {
        $logs = $this->getLogs(1000, null, null, $days);
        
        $summary = [
            'total' => count($logs),
            'by_level' => [],
            'by_action' => [],
            'by_day' => [],
        ];
        
        foreach ($logs as $log) {
            // Count by level
            $level = $log['level'] ?? 'unknown';
            $summary['by_level'][$level] = ($summary['by_level'][$level] ?? 0) + 1;
            
            // Count by action
            $action = $log['action'] ?? 'unknown';
            $summary['by_action'][$action] = ($summary['by_action'][$action] ?? 0) + 1;
            
            // Count by day
            $day = date('Y-m-d', strtotime($log['timestamp'] ?? 'now'));
            $summary['by_day'][$day] = ($summary['by_day'][$day] ?? 0) + 1;
        }
        
        // Sort by count descending
        arsort($summary['by_level']);
        arsort($summary['by_action']);
        ksort($summary['by_day']);
        
        return $summary;
    }

    /**
     * Clear all logs
     */
    public function clearLogs(): bool
    {
        $log_key = $this->get_log_key();
        return delete_option($log_key);
    }

    /**
     * Clear old logs (called by scheduled event)
     */
    public function cleanup_old_logs(): void
    {
        $log_key = $this->get_log_key();
        $logs = get_option($log_key, []);
        
        if (empty($logs)) {
            return;
        }
        
        $cutoff_time = strtotime('-' . Constants::LOG_RETENTION_DAYS . ' days');
        $filtered_logs = [];
        
        foreach ($logs as $log) {
            $log_time = strtotime($log['timestamp'] ?? '');
            if ($log_time >= $cutoff_time) {
                $filtered_logs[] = $log;
            }
        }
        
        if (count($filtered_logs) < count($logs)) {
            update_option($log_key, $filtered_logs, false);
            
            $this->log(
                'logs_cleaned',
                sprintf('Cleaned old logs: removed %d entries older than %d days',
                    count($logs) - count($filtered_logs),
                    Constants::LOG_RETENTION_DAYS
                ),
                [
                    'removed_count' => count($logs) - count($filtered_logs),
                    'remaining_count' => count($filtered_logs),
                    'retention_days' => Constants::LOG_RETENTION_DAYS,
                ],
                'info'
            );
        }
    }

    /**
     * Export logs to file
     */
    public function exportLogs(string $format = 'json'): ?string
    {
        $logs = $this->getLogs(1000);
        
        if (empty($logs)) {
            return null;
        }
        
        switch ($format) {
            case 'json':
                return json_encode($logs, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
                
            case 'csv':
                return $this->exportLogsToCsv($logs);
                
            case 'text':
                return $this->exportLogsToText($logs);
                
            default:
                return null;
        }
    }

    /**
     * Export logs to CSV format
     */
    private function exportLogsToCsv(array $logs): string
    {
        $output = fopen('php://temp', 'r+');
        
        // Write header
        fputcsv($output, ['Timestamp', 'Level', 'Action', 'Message', 'Details']);
        
        // Write data
        foreach ($logs as $log) {
            fputcsv($output, [
                $log['timestamp'] ?? '',
                strtoupper($log['level'] ?? ''),
                $log['action'] ?? '',
                $log['message'] ?? '',
                json_encode($log['data'] ?? [], JSON_UNESCAPED_UNICODE)
            ]);
        }
        
        rewind($output);
        $csv = stream_get_contents($output);
        fclose($output);
        
        return $csv;
    }

    /**
     * Export logs to text format
     */
    private function exportLogsToText(array $logs): string
    {
        $text = "Wootour Bulk Editor - Logs Export\n";
        $text .= "Generated: " . current_time('mysql') . "\n";
        $text .= str_repeat("=", 80) . "\n\n";
        
        foreach ($logs as $log) {
            $text .= sprintf(
                "[%s] %-8s %-20s: %s\n",
                $log['timestamp'] ?? '',
                strtoupper($log['level'] ?? ''),
                $log['action'] ?? '',
                $log['message'] ?? ''
            );
            
            if (!empty($log['data'])) {
                foreach ($log['data'] as $key => $value) {
                    if (is_array($value)) {
                        $value = json_encode($value, JSON_UNESCAPED_UNICODE);
                    }
                    $text .= sprintf("    %-15s: %s\n", $key, $value);
                }
            }
            
            $text .= "\n";
        }
        
        return $text;
    }

    /**
     * Get the current log storage key
     */
    private function get_log_key(): string
    {
        $month = date('Y_m');
        return Constants::LOG_OPTION_PREFIX . 'logs_' . $month;
    }

    /**
     * Get client IP address
     */
    private function get_client_ip(): string
    {
        $ip = '';
        
        if (!empty($_SERVER['HTTP_CLIENT_IP'])) {
            $ip = $_SERVER['HTTP_CLIENT_IP'];
        } elseif (!empty($_SERVER['HTTP_X_FORWARDED_FOR'])) {
            $ip = $_SERVER['HTTP_X_FORWARDED_FOR'];
        } elseif (!empty($_SERVER['REMOTE_ADDR'])) {
            $ip = $_SERVER['REMOTE_ADDR'];
        }
        
        return filter_var($ip, FILTER_VALIDATE_IP) ? $ip : 'unknown';
    }

    /**
     * Sanitize availability data for logging
     */
    private function sanitize_availability_data(array $data): array
    {
        $sanitized = [];
        $allowed_keys = ['start_date', 'end_date', 'weekdays', 'exclusions', 'specific'];
        
        foreach ($data as $key => $value) {
            if (in_array($key, $allowed_keys)) {
                if (is_array($value)) {
                    $sanitized[$key] = array_map('sanitize_text_field', $value);
                } else {
                    $sanitized[$key] = sanitize_text_field($value);
                }
            }
        }
        
        return $sanitized;
    }

    /**
     * Sanitize changes data for logging
     */
    private function sanitize_changes(array $changes): array
    {
        return $this->sanitize_availability_data($changes);
    }

    /**
     * Check if logging is enabled
     */
    public function is_logging_enabled(): bool
    {
        return apply_filters('wbe_logging_enabled', true);
    }

    /**
     * Get disk usage of logs
     */
    public function get_logs_disk_usage(): array
    {
        global $wpdb;
        
        $log_key_pattern = $wpdb->esc_like(Constants::LOG_OPTION_PREFIX . 'logs_') . '%';
        $size = 0;
        $count = 0;
        
        $results = $wpdb->get_results($wpdb->prepare(
            "SELECT option_name, LENGTH(option_value) as size 
            FROM {$wpdb->options} 
            WHERE option_name LIKE %s",
            $log_key_pattern
        ));
        
        foreach ($results as $row) {
            $size += (int) $row->size;
            $count++;
        }
        
        return [
            'total_size_bytes' => $size,
            'total_size_human' => size_format($size),
            'log_files_count'  => $count,
            'average_size'     => $count > 0 ? size_format($size / $count) : '0 B',
        ];
    }

    /**
     * Test logging functionality
     */
    public function test_logging(): array
    {
        $test_id = 'test_' . wp_generate_password(8, false);
        
        $results = [
            'write_test' => false,
            'read_test' => false,
            'cleanup_test' => false,
        ];
        
        // Test write
        $write_result = $this->log(
            'test',
            'Test log entry',
            ['test_id' => $test_id, 'timestamp' => time()],
            'info'
        );
        
        $results['write_test'] = $write_result;
        
        // Test read
        $logs = $this->getLogs(10);
        $found = false;
        
        foreach ($logs as $log) {
            if (isset($log['data']['test_id']) && $log['data']['test_id'] === $test_id) {
                $found = true;
                break;
            }
        }
        
        $results['read_test'] = $found;
        
        // Test cleanup (simulate old log)
        $old_log_key = Constants::LOG_OPTION_PREFIX . 'logs_test';
        add_option($old_log_key, [
            [
                'action' => 'test_old',
                'message' => 'Old test log',
                'timestamp' => date('Y-m-d H:i:s', strtotime('-35 days')),
                'data' => ['test_id' => $test_id],
            ]
        ], '', 'no');
        
        $this->cleanup_old_logs();
        
        $old_logs = get_option($old_log_key, []);
        $results['cleanup_test'] = empty($old_logs);
        
        // Cleanup test option
        delete_option($old_log_key);
        
        return $results;
    }
}