<?php
/**
 * Wootour Bulk Editor - Service Interface
 * 
 * Interface commune pour tous les services du plugin.
 * 
 * @package     WootourBulkEditor
 * @subpackage  Services
 * @author      Votre Nom <email@example.com>
 * @license     GPL-2.0+
 * @since       1.0.0
 */

namespace WootourBulkEditor\Services;

// Exit if accessed directly
defined('ABSPATH') || exit;

/**
 * Interface ServiceInterface
 * 
 * Définit le contrat que tous les services doivent respecter
 */
interface ServiceInterface
{
    /**
     * Initialiser le service
     * 
     * Cette méthode est appelée lors de l'initialisation du plugin
     * pour configurer les hooks, enregistrer les callbacks, etc.
     * 
     * @return void
     */
    public function init(): void;
}