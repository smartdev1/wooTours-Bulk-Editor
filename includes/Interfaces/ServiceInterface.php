<?php
/**
 * Wootour Bulk Editor - Service Interface
 * 
 * @package     WootourBulkEditor
 * @subpackage  Interfaces
 * @author      Votre Nom <email@example.com>
 * @license     GPL-2.0+
 * @since       1.0.0
 */

namespace WootourBulkEditor\Interfaces;

// Exit if accessed directly
defined('ABSPATH') || exit;

/**
 * Interface ServiceInterface
 * 
 * Contract for all service classes
 */
interface ServiceInterface
{
    /**
     * Initialize the service
     */
    public function init(): void;
}