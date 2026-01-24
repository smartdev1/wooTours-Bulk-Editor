<?php

namespace WootourBulkEditor\Core\Traits;

defined('ABSPATH') || exit;

/**
 * Trait Singleton
 * 
 * Implements the Singleton design pattern for classes.
 * Ensures only one instance of a class exists throughout the application lifecycle.
 * 
 * @package WootourBulkEditor\Core\Traits
 */
trait Singleton
{
    /**
     * The single instance of the class
     * 
     * @var static|null
     */
    private static ?self $instance = null;

    /**
     * Get the singleton instance
     * 
     * @return static
     */
    public static function getInstance(): self
    {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        
        return self::$instance;
    }

    /**
     * Private constructor to prevent direct instantiation
     */
    private function __construct()
    {
        // Constructor logic should be in init() method instead
    }

    /**
     * Prevent cloning of the instance
     * 
     * @return void
     */
    private function __clone(): void
    {
        // Cloning disabled
    }

    /**
     * Prevent unserializing of the instance
     * 
     * @throws \Exception
     * @return void
     */
    public function __wakeup(): void
    {
        throw new \Exception("Cannot unserialize singleton");
    }
}