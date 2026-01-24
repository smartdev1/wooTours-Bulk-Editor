<?php

namespace WootourBulkEditor\Core;

defined('ABSPATH') || exit;

/**
 * Class Autoloader
 * 
 * PSR-4 compliant autoloader for the plugin.
 * Maps namespace structure to file system structure.
 * 
 * @package WootourBulkEditor\Core
 */
final class Autoloader
{
    /**
     * Namespace prefix for this plugin
     */
    private const NAMESPACE_PREFIX = 'WootourBulkEditor\\';
    
    /**
     * Base directory where classes are located
     */
    private string $base_dir;
    
    /**
     * Constructor
     * 
     * @param string $base_dir Base directory path (defaults to plugin includes directory)
     */
    public function __construct(string $base_dir = '')
    {
        // Default to the includes directory
        $this->base_dir = $base_dir ?: dirname(__DIR__);
    }

    /**
     * Register the autoloader with SPL
     * 
     * @return void
     */
    public function register(): void
    {
        spl_autoload_register([$this, 'load_class']);
    }

    /**
     * Unregister the autoloader
     * 
     * @return void
     */
    public function unregister(): void
    {
        spl_autoload_unregister([$this, 'load_class']);
    }

    /**
     * Load a class file based on the class name
     * 
     * @param string $class Fully qualified class name
     * @return void
     */
    private function load_class(string $class): void
    {
        // Check if class uses our namespace prefix
        $len = strlen(self::NAMESPACE_PREFIX);
        if (strncmp(self::NAMESPACE_PREFIX, $class, $len) !== 0) {
            // Not our namespace, skip
            return;
        }

        // Get the relative class name (everything after the namespace prefix)
        $relative_class = substr($class, $len);
        
        // Convert namespace separators to directory separators and add .php extension
        $file = $this->base_dir . '/' . str_replace('\\', '/', $relative_class) . '.php';
        
        // If the file exists, require it
        if (file_exists($file)) {
            require_once $file;
        } else {
            error_log(sprintf(
                '[WootourBulkEditor] Autoloader: Class file not found: %s (looked in: %s)',
                $class,
                $file
            ));
        }
    }

    /**
     * Check if a class exists without loading it
     * 
     * @param string $class Fully qualified class name
     * @return bool
     */
    public static function class_exists(string $class): bool
    {
        return class_exists($class, false) || self::find_file($class) !== false;
    }

    /**
     * Find the file for a class without loading it
     * 
     * @param string $class Fully qualified class name
     * @return string|false The file path or false if not found
     */
    private static function find_file(string $class): string|false
    {
        $len = strlen(self::NAMESPACE_PREFIX);
        if (strncmp(self::NAMESPACE_PREFIX, $class, $len) !== 0) {
            return false;
        }

        $relative_class = substr($class, $len);
        $base_dir = dirname(__DIR__);
        $file = $base_dir . '/' . str_replace('\\', '/', $relative_class) . '.php';

        return file_exists($file) ? $file : false;
    }

    /**
     * Get all classes in a given namespace
     * 
     * @param string $namespace Namespace to search
     * @return array Array of fully qualified class names
     */
    public static function get_classes_in_namespace(string $namespace): array
    {
        $classes = [];
        
        // Remove leading backslash and namespace prefix
        $namespace = ltrim($namespace, '\\');
        if (strpos($namespace, self::NAMESPACE_PREFIX) === 0) {
            $namespace = substr($namespace, strlen(self::NAMESPACE_PREFIX));
        }
        
        $namespace_path = str_replace('\\', '/', $namespace);
        $base_dir = dirname(__DIR__);
        $search_dir = $base_dir . '/' . $namespace_path;

        if (!is_dir($search_dir)) {
            return $classes;
        }

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($search_dir, \RecursiveDirectoryIterator::SKIP_DOTS)
        );

        foreach ($iterator as $file) {
            if ($file->isFile() && $file->getExtension() === 'php') {
                $relative_path = str_replace([$base_dir . '/', '.php'], '', $file->getPathname());
                $class_name = self::NAMESPACE_PREFIX . str_replace('/', '\\', $relative_path);
                $classes[] = $class_name;
            }
        }

        return $classes;
    }

    /**
     * Prevent cloning
     */
    private function __clone()
    {
    }

    /**
     * Prevent unserialization
     * 
     * @throws \Exception
     */
    public function __wakeup(): void
    {
        throw new \Exception("Autoloader cannot be unserialized");
    }
}