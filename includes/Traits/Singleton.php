<?php
/**
 * Wootour Bulk Editor - Singleton Trait
 * 
 * @package     WootourBulkEditor
 * @subpackage  Traits
 
 * @license     GPL-2.0+
 * @since       1.0.0
 */

namespace WootourBulkEditor\Traits;

// Exit if accessed directly
defined('ABSPATH') || exit;

/**
 * Trait Singleton
 * 
 * Provides singleton pattern implementation for classes
 */
trait Singleton
{
    /**
     * The singleton instance
     */
    private static ?self $instance = null;

    /**
     * Get the singleton instance
     * 
     * @return static
     */
    public static function getInstance(): static
    {
        if (null === self::$instance) {
            self::$instance = new static();
        }

        return self::$instance;
    }

    /**
     * Protected constructor to prevent creating a new instance
     */
    protected function __construct()
    {
        // Constructor logic here
    }

    /**
     * Private clone method to prevent cloning
     */
    private function __clone()
    {
        // Prevent cloning
    }

    /**
     * Private unserialize method to prevent unserializing
     */
    public function __wakeup()
    {
        throw new \Exception("Cannot unserialize singleton");
    }

    /**
     * Reset the instance (mainly for testing)
     * 
     * @return void
     */
    public static function resetInstance(): void
    {
        self::$instance = null;
    }
}