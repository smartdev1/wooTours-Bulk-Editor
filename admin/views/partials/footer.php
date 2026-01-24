<?php
/**
 * Pied de page de l'interface d'administration
 *
 * @package Wootour_Bulk_Editor
 * @version 1.0.0
 */

if (!defined('ABSPATH')) {
    exit; // Sécurité
}

// Informations système
$memory_usage = function_exists('memory_get_usage') ? round(memory_get_usage() / 1024 / 1024, 2) : 'N/A';
$execution_time = function_exists('microtime') ? round(microtime(true) - $_SERVER['REQUEST_TIME_FLOAT'], 3) : 'N/A';
?>

<!-- Pied de page principal -->
<div class="wootour-bulk-footer">
    
    <!-- Informations de debug (seulement en mode debug) -->
    <?php if (defined('WP_DEBUG') && WP_DEBUG): ?>
    <div class="footer-debug">
        <details>
            <summary>
                <span class="dashicons dashicons-info"></span>
                Informations de debug
            </summary>
            <div class="debug-content">
                <table class="debug-table">
                    <tr>
                        <th>Mémoire utilisée :</th>
                        <td><?php echo esc_html($memory_usage); ?> MB</td>
                    </tr>
                    <tr>
                        <th>Temps d'exécution :</th>
                        <td><?php echo esc_html($execution_time); ?> s</td>
                    </tr>
                    <tr>
                        <th>PHP Version :</th>
                        <td><?php echo esc_html(PHP_VERSION); ?></td>
                    </tr>
                    <tr>
                        <th>WordPress :</th>
                        <td><?php echo esc_html(get_bloginfo('version')); ?></td>
                    </tr>
                    <tr>
                        <th>WooCommerce :</th>
                        <td>
                            <?php 
                            if (class_exists('WooCommerce')) {
                                echo esc_html(WC()->version);
                            } else {
                                echo 'Non détecté';
                            }
                            ?>
                        </td>
                    </tr>
                    <tr>
                        <th>Wootour :</th>
                        <td>
                            <?php
                            $wootour_version = defined('WOOTOUR_VERSION') ? WOOTOUR_VERSION : 'Non détecté';
                            echo esc_html($wootour_version);
                            ?>
                        </td>
                    </tr>
                </table>
            </div>
        </details>
    </div>
    <?php endif; ?>

    <!-- Liens utiles -->
    <div class="footer-links">
        <div class="links-section">
            <h4>Support</h4>
            <ul>
                <li>
                    <a href="https://wootour.com/documentation" target="_blank">
                        <span class="dashicons dashicons-book"></span>
                        Documentation
                    </a>
                </li>
                <li>
                    <a href="https://wootour.com/faq" target="_blank">
                        <span class="dashicons dashicons-editor-help"></span>
                        FAQ
                    </a>
                </li>
                <li>
                    <a href="https://wootour.com/support" target="_blank">
                        <span class="dashicons dashicons-email"></span>
                        Contact support
                    </a>
                </li>
            </ul>
        </div>
        
        <div class="links-section">
            <h4>Ressources</h4>
            <ul>
                <li>
                    <a href="<?php echo admin_url('admin.php?page=wootour-bulk-edit&action=export_logs'); ?>">
                        <span class="dashicons dashicons-download"></span>
                        Exporter les logs
                    </a>
                </li>
                <li>
                    <a href="#" id="clear-cache-btn">
                        <span class="dashicons dashicons-update"></span>
                        Vider le cache
                    </a>
                </li>
                <li>
                    <a href="<?php echo admin_url('tools.php?page=site-health'); ?>">
                        <span class="dashicons dashicons-heart"></span>
                        Santé du site
                    </a>
                </li>
            </ul>
        </div>
        
        <div class="links-section">
            <h4>À propos</h4>
            <ul>
                <li>
                    <a href="#" id="show-changelog">
                        <span class="dashicons dashicons-media-text"></span>
                        Journal des modifications
                    </a>
                </li>
                <li>
                    <a href="https://github.com/your-repo/wootour-bulk-editor" target="_blank">
                        <span class="dashicons dashicons-admin-plugins"></span>
                        GitHub
                    </a>
                </li>
                <li>
                    <a href="<?php echo admin_url('admin.php?page=wootour-bulk-settings&tab=credits'); ?>">
                        <span class="dashicons dashicons-groups"></span>
                        Crédits
                    </a>
                </li>
            </ul>
        </div>
    </div>

    <!-- Copyright et version -->
    <div class="footer-bottom">
        <div class="copyright">
            <p>
                &copy; <?php echo date('Y'); ?> 
                <a href="https://wootour.com" target="_blank">Wootour</a> - 
                Extension d'édition en masse v<?php echo esc_html(WB_VERSION); ?>
            </p>
            <p class="footer-note">
                Cet outil est fourni tel quel. Assurez-vous de toujours tester les modifications 
                importantes dans un environnement de staging.
            </p>
        </div>
        
        <div class="footer-actions">
            <button type="button" class="button button-small" id="toggle-debug">
                <span class="dashicons dashicons-admin-tools"></span>
                Mode debug
            </button>
            <button type="button" class="button button-small" id="refresh-page">
                <span class="dashicons dashicons-update"></span>
                Actualiser
            </button>
            <a href="<?php echo wp_nonce_url(admin_url('admin-post.php?action=wootour_bulk_export_settings'), 'export_settings'); ?>" 
               class="button button-small">
                <span class="dashicons dashicons-database-export"></span>
                Sauvegarder
            </a>
        </div>
    </div>

</div>

<!-- Modals génériques (seront remplies par JavaScript) -->
<div id="generic-modal" class="wootour-modal" style="display: none;">
    <div class="modal-overlay"></div>
    <div class="modal-container">
        <div class="modal-header">
            <h3 id="modal-title">Titre de la modal</h3>
            <button type="button" class="modal-close">
                <span class="dashicons dashicons-no-alt"></span>
            </button>
        </div>
        <div class="modal-body" id="modal-body">
            <!-- Contenu dynamique -->
        </div>
        <div class="modal-footer" id="modal-footer">
            <!-- Boutons dynamiques -->
        </div>
    </div>
</div>

<!-- Template pour les notifications -->
<div id="notification-template" style="display: none;">
    <div class="wootour-notification">
        <div class="notification-icon">
            <span class="dashicons"></span>
        </div>
        <div class="notification-content">
            <p class="notification-message"></p>
        </div>
        <button type="button" class="notification-close">
            <span class="dashicons dashicons-no-alt"></span>
        </button>
    </div>
</div>

<!-- Chargement des scripts inline pour les interactions -->
<script type="text/javascript">
jQuery(document).ready(function($) {
    
    // Initialisation des interactions du footer
    function initFooterInteractions() {
        
        // Vider le cache
        $('#clear-cache-btn').on('click', function(e) {
            e.preventDefault();
            
            if (confirm('Voulez-vous vider le cache du plugin ?')) {
                $.ajax({
                    url: ajaxurl,
                    type: 'POST',
                    data: {
                        action: 'wootour_bulk_clear_cache',
                        nonce: wootour_bulk_ajax.nonce
                    },
                    success: function(response) {
                        if (response.success) {
                            showNotification('Cache vidé avec succès.', 'success');
                        } else {
                            showNotification('Erreur lors du vidage du cache.', 'error');
                        }
                    }
                });
            }
        });
        
        // Afficher le changelog
        $('#show-changelog').on('click', function(e) {
            e.preventDefault();
            
            $.ajax({
                url: ajaxurl,
                type: 'GET',
                data: {
                    action: 'wootour_bulk_get_changelog',
                    nonce: wootour_bulk_ajax.nonce
                },
                success: function(response) {
                    if (response.success) {
                        showModal({
                            title: 'Journal des modifications',
                            content: response.data.changelog,
                            size: 'large'
                        });
                    }
                }
            });
        });
        
        // Basculer le mode debug
        $('#toggle-debug').on('click', function() {
            $('.footer-debug').slideToggle();
            $(this).toggleClass('active');
        });
        
        // Actualiser la page
        $('#refresh-page').on('click', function() {
            location.reload();
        });
        
        // Rafraîchir les statistiques
        function refreshStats() {
            $.ajax({
                url: ajaxurl,
                type: 'GET',
                data: {
                    action: 'wootour_bulk_get_stats',
                    nonce: wootour_bulk_ajax.nonce
                },
                success: function(response) {
                    if (response.success) {
                        $('#total-wootour-products').text(response.data.total_products);
                        $('#last-update-time').text(response.data.last_update);
                    }
                }
            });
        }
        
        // Rafraîchir les stats toutes les 30 secondes
        setInterval(refreshStats, 30000);
        
        // Rafraîchir au chargement
        refreshStats();
    }
    
    // Initialiser
    initFooterInteractions();
    
});
</script>