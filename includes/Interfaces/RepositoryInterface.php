<?php
/**
 * Wootour Bulk Editor - Repository Interface
 * 
 * @package     WootourBulkEditor
 * @subpackage  Interfaces
 
 * @license     GPL-2.0+
 * @since       1.0.0
 */

namespace WootourBulkEditor\Interfaces;

// Exit if accessed directly
defined('ABSPATH') || exit;

/**
 * Interface RepositoryInterface
 * 
 * Contract for all repository classes
 */
interface RepositoryInterface
{
    /**
     * Initialize the repository
     */
    public function init(): void;

    /**
     * Get data by ID
     * 
     * @param int $id
     * @return mixed
     */
    public function get(int $id);

    /**
     * Update data
     * 
     * @param int $id
     * @param array $data
     * @return bool
     */
    public function update(int $id, array $data): bool;

    /**
     * Clear any internal cache
     * 
     * @param int $id
     */
    public function clearCache(int $id = 0): void;
}