<?php
/**
 * En-tête de l'interface d'administration
 *
 * @package Wootour_Bulk_Editor
 * @version 1.0.0
 */

if (!defined('ABSPATH')) {
    exit; // Sécurité
}

// Récupérer le nom du plugin
$plugin_name = get_plugin_data(WB_PLUGIN_FILE)['Name'];
$plugin_version = get_plugin_data(WB_PLUGIN_FILE)['Version'];
$current_page = isset($_GET['page']) ? sanitize_text_field($_GET['page']) : '';
?>

<!-- Header principal -->
<div class="wootour-bulk-header">
    
    <!-- Logo et titre -->
    <div class="header-branding">
        <div class="branding-logo">
            <span class="dashicons dashicons-calendar-alt"></span>
        </div>
        <div class="branding-content">
            <h1>
                <?php echo esc_html($plugin_name); ?>
                <span class="version-badge">v<?php echo esc_html($plugin_version); ?></span>
            </h1>
            <p class="branding-description">
                Extension d'édition en masse pour Wootour - Gestion des disponibilités calendaires
            </p>
        </div>
    </div>

    <!-- Navigation -->
    <nav class="header-navigation">
        <ul class="nav-tabs">
            <li class="nav-item <?php echo $current_page === 'wootour-bulk-edit' ? 'active' : ''; ?>">
                <a href="<?php echo admin_url('admin.php?page=wootour-bulk-edit'); ?>" class="nav-link">
                    <span class="dashicons dashicons-edit"></span>
                    <span class="nav-text">Édition en masse</span>
                </a>
            </li>
            <li class="nav-item <?php echo $current_page === 'wootour-bulk-history' ? 'active' : ''; ?>">
                <a href="<?php echo admin_url('admin.php?page=wootour-bulk-history'); ?>" class="nav-link">
                    <span class="dashicons dashicons-backup"></span>
                    <span class="nav-text">Historique</span>
                </a>
            </li>
            <li class="nav-item <?php echo $current_page === 'wootour-bulk-settings' ? 'active' : ''; ?>">
                <a href="<?php echo admin_url('admin.php?page=wootour-bulk-settings'); ?>" class="nav-link">
                    <span class="dashicons dashicons-admin-generic"></span>
                    <span class="nav-text">Paramètres</span>
                </a>
            </li>
            <li class="nav-item">
                <a href="https://wootour.com/documentation" target="_blank" class="nav-link">
                    <span class="dashicons dashicons-sos"></span>
                    <span class="nav-text">Documentation</span>
                </a>
            </li>
        </ul>
    </nav>

    <!-- Actions rapides -->
    <div class="header-actions">
        <div class="action-buttons">
            <button type="button" class="button button-secondary" id="toggle-sidebar">
                <span class="dashicons dashicons-menu"></span>
                <span class="button-text">Panels</span>
            </button>
            
            <div class="dropdown">
                <button type="button" class="button button-primary dropdown-toggle" id="quick-actions">
                    <span class="dashicons dashicons-plus"></span>
                    <span class="button-text">Actions rapides</span>
                    <span class="dashicons dashicons-arrow-down"></span>
                </button>
                <div class="dropdown-menu">
                    <a href="#" class="dropdown-item" id="quick-select-all">
                        <span class="dashicons dashicons-yes"></span>
                        Sélectionner tous les produits
                    </a>
                    <a href="#" class="dropdown-item" id="quick-clear-dates">
                        <span class="dashicons dashicons-no"></span>
                        Effacer toutes les dates
                    </a>
                    <div class="dropdown-divider"></div>
                    <a href="<?php echo admin_url('admin.php?page=wootour-bulk-edit&action=export_template'); ?>" 
                       class="dropdown-item">
                        <span class="dashicons dashicons-download"></span>
                        Exporter un template
                    </a>
                    <a href="<?php echo admin_url('admin.php?page=wootour-bulk-edit&action=import'); ?>" 
                       class="dropdown-item">
                        <span class="dashicons dashicons-upload"></span>
                        Importer des données
                    </a>
                </div>
            </div>
        </div>

        <!-- Statistiques rapides -->
        <div class="header-stats">
            <div class="stat-item">
                <span class="stat-label">Produits Wootour :</span>
                <span class="stat-value" id="total-wootour-products">0</span>
            </div>
            <div class="stat-item">
                <span class="stat-label">Dernière mise à jour :</span>
                <span class="stat-value" id="last-update-time"><?php echo date_i18n('d/m/Y H:i'); ?></span>
            </div>
        </div>
    </div>

</div>

<!-- Barre d'information -->
<div class="admin-notices-container">
    <?php
    // Afficher les notices WordPress
    settings_errors('wootour_bulk_messages');
    
    // Afficher les notices spécifiques au plugin
    if (isset($_GET['message'])) {
        $message_type = isset($_GET['message_type']) ? $_GET['message_type'] : 'success';
        ?>
        <div class="notice notice-<?php echo esc_attr($message_type); ?> is-dismissible">
            <p><?php echo esc_html(urldecode($_GET['message'])); ?></p>
        </div>
        <?php
    }
    ?>
</div>

<!-- Barre de progression globale (cachée par défaut) -->
<div id="global-progress-container" class="global-progress-container" style="display: none;">
    <div class="progress-header">
        <h3>Traitement en cours</h3>
        <button type="button" class="button button-link" id="hide-progress">
            <span class="dashicons dashicons-no-alt"></span>
        </button>
    </div>
    <div class="progress-body">
        <div class="progress-bar-container">
            <div class="progress-bar" id="global-progress-bar" role="progressbar" 
                 style="width: 0%" aria-valuenow="0" aria-valuemin="0" aria-valuemax="100">
            </div>
        </div>
        <div class="progress-text" id="global-progress-text">
            Initialisation...
        </div>
        <div class="progress-actions">
            <button type="button" class="button button-secondary" id="pause-global-process">
                <span class="dashicons dashicons-controls-pause"></span>
                Pause
            </button>
            <button type="button" class="button button-link" id="cancel-global-process">
                Annuler
            </button>
        </div>
    </div>
</div>