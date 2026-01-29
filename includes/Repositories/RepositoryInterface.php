<?php
/**
 * Wootour Bulk Editor - Repository Interface
 * 
 * Interface commune pour tous les repositories du plugin.
 * 
 * @package     WootourBulkEditor
 * @subpackage  Repositories
 
 * @license     GPL-2.0+
 * @since       1.0.0
 */

namespace WootourBulkEditor\Repositories;

// Exit if accessed directly
defined('ABSPATH') || exit;

/**
 * Interface RepositoryInterface
 * 
 * Définit le contrat minimal que tous les repositories doivent respecter.
 */
interface RepositoryInterface
{
    /**
     * Initialiser le repository
     * 
     * Cette méthode est appelée lors de l'initialisation du plugin
     * pour configurer les connexions, les caches, etc.
     * 
     * @return void
     */
    public function init(): void;
}