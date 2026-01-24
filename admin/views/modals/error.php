<?php
/**
 * Modal d'erreur
 *
 * @package Wootour_Bulk_Editor
 * @version 1.0.0
 */

if (!defined('ABSPATH')) {
    exit; // Sécurité
}
?>

<div id="error-modal" class="wootour-modal error-modal" style="display: none;">
    <div class="modal-overlay"></div>
    <div class="modal-container modal-medium">
        
        <!-- En-tête -->
        <div class="modal-header">
            <div class="modal-icon">
                <span class="dashicons dashicons-dismiss"></span>
            </div>
            <h3 id="error-title" class="modal-title">Une erreur est survenue</h3>
            <button type="button" class="modal-close" aria-label="Fermer">
                <span class="dashicons dashicons-no-alt"></span>
            </button>
        </div>
        
        <!-- Corps -->
        <div class="modal-body">
            <div class="error-content">
                <!-- Message d'erreur principal -->
                <div class="error-message">
                    <p id="error-main-message">
                        Une erreur s'est produite lors de l'opération.
                    </p>
                </div>
                
                <!-- Détails de l'erreur (dépliable) -->
                <div class="error-details-container">
                    <button type="button" class="error-details-toggle" id="toggle-error-details">
                        <span class="toggle-icon dashicons dashicons-arrow-down"></span>
                        <span class="toggle-text">Afficher les détails techniques</span>
                    </button>
                    
                    <div id="error-details" class="error-details" style="display: none;">
                        <div class="details-content">
                            <pre id="error-details-content"><code></code></pre>
                        </div>
                        
                        <!-- Actions pour les détails -->
                        <div class="details-actions">
                            <button type="button" class="button button-small" id="copy-error-details">
                                <span class="dashicons dashicons-clipboard"></span>
                                Copier
                            </button>
                            <button type="button" class="button button-small" id="save-error-log">
                                <span class="dashicons dashicons-download"></span>
                                Sauvegarder
                            </button>
                        </div>
                    </div>
                </div>
                
                <!-- Liste des erreurs (pour les erreurs multiples) -->
                <div id="error-list-container" class="error-list-container" style="display: none;">
                    <h4>Plusieurs erreurs sont survenues :</h4>
                    <ul id="error-list" class="error-list"></ul>
                </div>
                
                <!-- Suggestions de résolution -->
                <div class="error-suggestions">
                    <h4><span class="dashicons dashicons-lightbulb"></span> Suggestions :</h4>
                    <ul id="suggestions-list" class="suggestions-list">
                        <li>Vérifiez votre connexion internet</li>
                        <li>Réessayez l'opération</li>
                        <li>Contactez le support si le problème persiste</li>
                    </ul>
                </div>
                
                <!-- Code d'erreur -->
                <div class="error-code-container">
                    <small>
                        Code d'erreur : <code id="error-code">UNKNOWN_ERROR</code>
                        <span class="error-timestamp" id="error-timestamp"></span>
                    </small>
                </div>
            </div>
        </div>
        
        <!-- Pied de page -->
        <div class="modal-footer">
            <div class="footer-actions">
                <button type="button" class="button button-secondary modal-close">
                    <span class="dashicons dashicons-no"></span>
                    Fermer
                </button>
                <button type="button" class="button button-link" id="retry-action" style="display: none;">
                    <span class="dashicons dashicons-update"></span>
                    Réessayer
                </button>
                <button type="button" class="button button-primary" id="report-error">
                    <span class="dashicons dashicons-email"></span>
                    Signaler l'erreur
                </button>
            </div>
        </div>
        
    </div>
</div>

<!-- Template pour les éléments d'erreur -->
<script type="text/html" id="error-item-template">
    <li class="error-item">
        <span class="error-item-icon dashicons dashicons-warning"></span>
        <div class="error-item-content">
            <strong class="error-item-title"></strong>
            <p class="error-item-message"></p>
            <small class="error-item-context"></small>
        </div>
    </li>
</script>

<!-- Template pour les suggestions -->
<script type="text/html" id="suggestion-item-template">
    <li class="suggestion-item">
        <span class="dashicons dashicons-editor-help"></span>
        <span class="suggestion-text"></span>
    </li>
</script>

<!-- Script de gestion de la modal d'erreur -->
<script type="text/javascript">
(function($) {
    'use strict';
    
    /**
     * Gestionnaire de modal d'erreur
     */
    class ErrorModal {
        constructor() {
            this.modal = $('#error-modal');
            this.retryCallback = null;
            this.reportCallback = null;
            this.errorLog = [];
            this.maxStoredErrors = 50;
            this.init();
        }
        
        /**
         * Initialisation
         */
        init() {
            this.bindEvents();
            this.loadErrorLog();
        }
        
        /**
         * Lier les événements
         */
        bindEvents() {
            const self = this;
            
            // Fermer la modal
            this.modal.find('.modal-close, .modal-close').on('click', function() {
                self.hide();
            });
            
            // Basculer les détails
            $('#toggle-error-details').on('click', function() {
                self.toggleErrorDetails();
            });
            
            // Copier les détails
            $('#copy-error-details').on('click', function() {
                self.copyErrorDetails();
            });
            
            // Sauvegarder le log
            $('#save-error-log').on('click', function() {
                self.saveErrorLog();
            });
            
            // Réessayer l'action
            $('#retry-action').on('click', function() {
                if (self.retryCallback) {
                    self.retryCallback();
                }
                self.hide();
            });
            
            // Signaler l'erreur
            $('#report-error').on('click', function() {
                if (self.reportCallback) {
                    self.reportCallback();
                } else {
                    self.reportError();
                }
            });
            
            // Empêcher la fermeture en cliquant sur l'overlay
            this.modal.find('.modal-overlay').on('click', function(e) {
                e.stopPropagation();
            });
        }
        
        /**
         * Afficher la modal
         * @param {Object|string} error - Erreur à afficher
         * @param {Object} options - Options supplémentaires
         */
        show(error, options = {}) {
            const defaults = {
                title: 'Une erreur est survenue',
                showRetry: false,
                onRetry: null,
                onReport: null,
                errorCode: 'UNKNOWN_ERROR',
                suggestions: [],
                context: {}
            };
            
            const config = $.extend({}, defaults, options);
            
            // Stocker l'erreur dans le log
            this.logError(error, config);
            
            // Mettre à jour le contenu
            this.modal.find('#error-title').text(config.title);
            
            // Gérer le message d'erreur
            this.updateErrorMessage(error, config);
            
            // Gérer les détails techniques
            this.updateErrorDetails(error, config);
            
            // Gérer la liste d'erreurs (si multiples)
            this.updateErrorList(error, config);
            
            // Gérer les suggestions
            this.updateSuggestions(config.suggestions);
            
            // Mettre à jour le code d'erreur et timestamp
            $('#error-code').text(config.errorCode);
            $('#error-timestamp').text(` (${new Date().toLocaleTimeString()})`);
            
            // Configurer les callbacks
            this.retryCallback = config.onRetry;
            this.reportCallback = config.onReport;
            
            // Afficher/masquer le bouton réessayer
            if (config.showRetry && config.onRetry) {
                $('#retry-action').show();
            } else {
                $('#retry-action').hide();
            }
            
            // Afficher la modal
            this.modal.fadeIn(200);
            $('body').addClass('modal-open');
        }
        
        /**
         * Masquer la modal
         */
        hide() {
            this.modal.fadeOut(200);
            $('body').removeClass('modal-open');
            
            // Réinitialiser
            this.retryCallback = null;
            this.reportCallback = null;
            
            // Masquer les détails
            $('#error-details').hide();
            $('#toggle-error-details .toggle-icon')
                .removeClass('dashicons-arrow-up')
                .addClass('dashicons-arrow-down');
        }
        
        /**
         * Mettre à jour le message d'erreur
         */
        updateErrorMessage(error, config) {
            let message = 'Une erreur s\'est produite lors de l\'opération.';
            
            if (typeof error === 'string') {
                message = error;
            } else if (error && error.message) {
                message = error.message;
            } else if (error && error.data && error.data.message) {
                message = error.data.message;
            } else if (error && error.responseJSON && error.responseJSON.data) {
                message = error.responseJSON.data;
            }
            
            $('#error-main-message').text(message);
        }
        
        /**
         * Mettre à jour les détails techniques
         */
        updateErrorDetails(error, config) {
            const $detailsContent = $('#error-details-content code');
            let details = '';
            
            // Construire l'objet de détails
            const errorDetails = {
                timestamp: new Date().toISOString(),
                error_code: config.errorCode,
                context: config.context,
                error_data: this.sanitizeErrorData(error)
            };
            
            // Ajouter les informations système
            errorDetails.system = {
                user_agent: navigator.userAgent,
                url: window.location.href,
                wordpress: typeof wootour_bulk_ajax !== 'undefined' ? 
                         wootour_bulk_ajax.wp_version : 'N/A',
                php_memory_limit: typeof wootour_bulk_ajax !== 'undefined' ? 
                                wootour_bulk_ajax.php_memory_limit : 'N/A'
            };
            
            // Formater en JSON lisible
            details = JSON.stringify(errorDetails, null, 2);
            $detailsContent.text(details);
            
            // Appliquer la coloration syntaxique si disponible
            if (typeof Prism !== 'undefined') {
                Prism.highlightElement($detailsContent[0]);
            }
        }
        
        /**
         * Nettoyer les données d'erreur pour l'affichage
         */
        sanitizeErrorData(error) {
            if (!error) return null;
            
            // Créer une copie pour éviter de modifier l'original
            const sanitized = {};
            
            // Extraire les informations utiles
            if (typeof error === 'string') {
                sanitized.message = error;
            } else {
                for (const key in error) {
                    // Éviter les données sensibles
                    if (key.match(/(password|token|key|secret|auth)/i)) {
                        sanitized[key] = '[REDACTED]';
                    } else if (typeof error[key] === 'object') {
                        sanitized[key] = this.sanitizeErrorData(error[key]);
                    } else {
                        sanitized[key] = error[key];
                    }
                }
            }
            
            return sanitized;
        }
        
        /**
         * Mettre à jour la liste d'erreurs
         */
        updateErrorList(error, config) {
            const $listContainer = $('#error-list-container');
            const $list = $('#error-list');
            
            $list.empty();
            
            // Vérifier si c'est une erreur multiple
            if (Array.isArray(error)) {
                error.forEach((err, index) => {
                    const $item = $($('#error-item-template').html());
                    
                    $item.find('.error-item-title').text(
                        err.title || `Erreur ${index + 1}`
                    );
                    
                    $item.find('.error-item-message').text(
                        err.message || 'Erreur inconnue'
                    );
                    
                    if (err.context) {
                        $item.find('.error-item-context').text(
                            `Contexte: ${JSON.stringify(err.context)}`
                        );
                    }
                    
                    $list.append($item);
                });
                
                $listContainer.show();
            } else {
                $listContainer.hide();
            }
        }
        
        /**
         * Mettre à jour les suggestions
         */
        updateSuggestions(suggestions) {
            const $list = $('#suggestions-list');
            
            // Vider les suggestions par défaut
            $list.empty();
            
            // Ajouter les suggestions personnalisées
            if (suggestions && suggestions.length > 0) {
                suggestions.forEach(suggestion => {
                    const $item = $($('#suggestion-item-template').html());
                    $item.find('.suggestion-text').text(suggestion);
                    $list.append($item);
                });
            } else {
                // Suggestions par défaut
                const defaultSuggestions = [
                    'Vérifiez votre connexion internet',
                    'Rafraîchissez la page et réessayez',
                    'Vérifiez les logs d\'erreurs WordPress',
                    'Contactez le support technique'
                ];
                
                defaultSuggestions.forEach(suggestion => {
                    const $item = $($('#suggestion-item-template').html());
                    $item.find('.suggestion-text').text(suggestion);
                    $list.append($item);
                });
            }
        }
        
        /**
         * Basculer l'affichage des détails
         */
        toggleErrorDetails() {
            const $details = $('#error-details');
            const $toggleIcon = $('#toggle-error-details .toggle-icon');
            
            $details.slideToggle(200);
            
            if ($details.is(':visible')) {
                $toggleIcon
                    .removeClass('dashicons-arrow-down')
                    .addClass('dashicons-arrow-up');
                $('#toggle-error-details .toggle-text')
                    .text('Masquer les détails techniques');
            } else {
                $toggleIcon
                    .removeClass('dashicons-arrow-up')
                    .addClass('dashicons-arrow-down');
                $('#toggle-error-details .toggle-text')
                    .text('Afficher les détails techniques');
            }
        }
        
        /**
         * Copier les détails dans le presse-papier
         */
        copyErrorDetails() {
            const detailsText = $('#error-details-content').text();
            
            navigator.clipboard.writeText(detailsText).then(() => {
                this.showNotification('Détails copiés dans le presse-papier.', 'success');
            }).catch(err => {
                console.error('Erreur lors de la copie :', err);
                this.showNotification('Impossible de copier les détails.', 'error');
            });
        }
        
        /**
         * Sauvegarder le log d'erreur
         */
        saveErrorLog() {
            const errorData = {
                timestamp: new Date().toISOString(),
                errors: this.errorLog,
                system_info: {
                    user_agent: navigator.userAgent,
                    url: window.location.href,
                    timestamp: new Date().toISOString()
                }
            };
            
            const blob = new Blob(
                [JSON.stringify(errorData, null, 2)], 
                { type: 'application/json' }
            );
            
            const url = URL.createObjectURL(blob);
            const a = document.createElement('a');
            a.href = url;
            a.download = `wootour-error-log-${new Date().getTime()}.json`;
            document.body.appendChild(a);
            a.click();
            document.body.removeChild(a);
            URL.revokeObjectURL(url);
            
            this.showNotification('Log d\'erreurs sauvegardé.', 'success');
        }
        
        /**
         * Journaliser une erreur
         */
        logError(error, config) {
            const errorEntry = {
                id: Date.now() + Math.random().toString(36).substr(2, 9),
                timestamp: new Date().toISOString(),
                error: this.sanitizeErrorData(error),
                config: config,
                url: window.location.href
            };
            
            this.errorLog.unshift(errorEntry);
            
            // Limiter la taille du log
            if (this.errorLog.length > this.maxStoredErrors) {
                this.errorLog = this.errorLog.slice(0, this.maxStoredErrors);
            }
            
            // Sauvegarder dans le localStorage
            this.saveErrorLogToStorage();
        }
        
        /**
         * Charger le log d'erreurs depuis le stockage
         */
        loadErrorLog() {
            try {
                const stored = localStorage.getItem('wootour_error_log');
                if (stored) {
                    this.errorLog = JSON.parse(stored);
                }
            } catch (e) {
                console.warn('Impossible de charger le log d\'erreurs :', e);
                this.errorLog = [];
            }
        }
        
        /**
         * Sauvegarder le log d'erreurs dans le stockage
         */
        saveErrorLogToStorage() {
            try {
                localStorage.setItem(
                    'wootour_error_log', 
                    JSON.stringify(this.errorLog)
                );
            } catch (e) {
                console.warn('Impossible de sauvegarder le log d\'erreurs :', e);
            }
        }
        
        /**
         * Signaler l'erreur au support
         */
        reportError() {
            const errorData = {
                subject: `[Wootour Bulk Editor] Erreur ${$('#error-code').text()}`,
                body: `Bonjour,\n\n` +
                      `Je rencontre une erreur avec l'extension Wootour Bulk Editor :\n\n` +
                      `- Code d'erreur : ${$('#error-code').text()}\n` +
                      `- Message : ${$('#error-main-message').text()}\n` +
                      `- URL : ${window.location.href}\n` +
                      `- Date/Heure : ${new Date().toLocaleString()}\n\n` +
                      `Détails techniques :\n` +
                      $('#error-details-content').text() + `\n\n` +
                      `Cordialement`
            };
            
            const mailtoLink = `mailto:support@wootour.com?` +
                `subject=${encodeURIComponent(errorData.subject)}&` +
                `body=${encodeURIComponent(errorData.body)}`;
            
            window.open(mailtoLink, '_blank');
        }
        
        /**
         * Afficher une notification
         */
        showNotification(message, type = 'info') {
            // Utiliser les notifications WordPress si disponibles
            if (typeof wp !== 'undefined' && wp.data && wp.data.dispatch) {
                wp.data.dispatch('core/notices').createNotice(
                    type,
                    message,
                    { isDismissible: true }
                );
            } else {
                // Fallback simple
                alert(message);
            }
        }
        
        /**
         * Afficher une erreur AJAX
         * @param {Object} xhr - Objet XHR jQuery
         * @param {Object} options - Options supplémentaires
         */
        showAjaxError(xhr, options = {}) {
            const errorCode = this.getAjaxErrorCode(xhr.status);
            const suggestions = this.getAjaxErrorSuggestions(xhr.status);
            
            this.show(xhr, {
                title: 'Erreur de communication',
                errorCode: errorCode,
                suggestions: suggestions,
                showRetry: true,
                context: {
                    status: xhr.status,
                    statusText: xhr.statusText,
                    url: xhr.responseURL || 'N/A'
                },
                ...options
            });
        }
        
        /**
         * Obtenir le code d'erreur pour un statut HTTP
         */
        getAjaxErrorCode(status) {
            const codes = {
                0: 'NETWORK_ERROR',
                400: 'BAD_REQUEST',
                401: 'UNAUTHORIZED',
                403: 'FORBIDDEN',
                404: 'NOT_FOUND',
                408: 'TIMEOUT',
                429: 'TOO_MANY_REQUESTS',
                500: 'SERVER_ERROR',
                502: 'BAD_GATEWAY',
                503: 'SERVICE_UNAVAILABLE'
            };
            
            return codes[status] || `HTTP_${status}`;
        }
        
        /**
         * Obtenir les suggestions pour un statut HTTP
         */
        getAjaxErrorSuggestions(status) {
            const suggestions = {
                0: [
                    'Vérifiez votre connexion internet',
                    'Vérifiez que le serveur est accessible',
                    'Désactivez temporairement votre antivirus/firewall'
                ],
                401: [
                    'Vérifiez vos identifiants de connexion',
                    'Rafraîchissez la page et reconnectez-vous',
                    'Vérifiez les permissions utilisateur'
                ],
                403: [
                    'Vérifiez les permissions d\'accès',
                    'Contactez l\'administrateur du site',
                    'Vérifiez les règles de sécurité'
                ],
                404: [
                    'Vérifiez l\'URL de la requête',
                    'Rafraîchissez la page',
                    'Vérifiez que la ressource existe'
                ],
                408: [
                    'Réessayez l\'opération',
                    'Réduisez la taille des données envoyées',
                    'Contactez l\'hébergeur pour augmenter le timeout'
                ],
                429: [
                    'Attendez quelques minutes avant de réessayer',
                    'Réduisez la fréquence des requêtes',
                    'Contactez l\'administrateur pour les limites'
                ],
                500: [
                    'Rafraîchissez la page',
                    'Vérifiez les logs d\'erreurs du serveur',
                    'Contactez le support technique'
                ],
                503: [
                    'Le serveur est en maintenance',
                    'Réessayez dans quelques minutes',
                    'Vérifiez l\'état du service'
                ]
            };
            
            return suggestions[status] || [];
        }
    }
    
    // Initialiser quand le DOM est prêt
    $(document).ready(function() {
        window.wootourErrorModal = new ErrorModal();
        
        // Exposer l'API
        window.WootourBulkModals = window.WootourBulkModals || {};
        window.WootourBulkModals.showError = function(error, title = 'Erreur') {
            return window.wootourErrorModal.show(error, { title: title });
        };
        
        window.WootourBulkModals.showAjaxError = function(xhr, options = {}) {
            return window.wootourErrorModal.showAjaxError(xhr, options);
        };
        
        window.WootourBulkModals.showWarning = function(message, title = 'Avertissement', onConfirm, onCancel) {
            // Pour les avertissements, utiliser la modal de confirmation
            if (window.wootourConfirmationModal) {
                return window.wootourConfirmationModal.show({
                    title: title,
                    message: message,
                    onConfirm: onConfirm,
                    onCancel: onCancel,
                    confirmText: 'Continuer',
                    cancelText: 'Annuler',
                    showDismiss: false
                });
            } else {
                // Fallback
                if (confirm(title + ': ' + message)) {
                    if (onConfirm) onConfirm();
                } else {
                    if (onCancel) onCancel();
                }
            }
        };
    });
    
})(jQuery);
</script>