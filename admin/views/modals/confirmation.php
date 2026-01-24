<?php
/**
 * Modal de confirmation
 *
 * @package Wootour_Bulk_Editor
 * @version 1.0.0
 */

if (!defined('ABSPATH')) {
    exit; // Sécurité
}
?>

<div id="confirmation-modal" class="wootour-modal confirmation-modal" style="display: none;">
    <div class="modal-overlay"></div>
    <div class="modal-container modal-medium">
        
        <!-- En-tête -->
        <div class="modal-header">
            <div class="modal-icon">
                <span class="dashicons dashicons-warning"></span>
            </div>
            <h3 id="confirmation-title" class="modal-title">Confirmation requise</h3>
            <button type="button" class="modal-close" aria-label="Fermer">
                <span class="dashicons dashicons-no-alt"></span>
            </button>
        </div>
        
        <!-- Corps -->
        <div class="modal-body">
            <div class="confirmation-content">
                <p id="confirmation-message">
                    Êtes-vous sûr de vouloir effectuer cette action ?
                </p>
                
                <!-- Détails supplémentaires (optionnels) -->
                <div id="confirmation-details" class="confirmation-details" style="display: none;">
                    <div class="details-content">
                        <h4>Détails de l'opération :</h4>
                        <ul id="details-list"></ul>
                    </div>
                </div>
                
                <!-- Avertissements (optionnels) -->
                <div id="confirmation-warnings" class="confirmation-warnings" style="display: none;">
                    <div class="warning-content">
                        <h4><span class="dashicons dashicons-flag"></span> Avertissements :</h4>
                        <ul id="warnings-list"></ul>
                    </div>
                </div>
                
                <!-- Notes importantes -->
                <div class="confirmation-notes">
                    <p class="note important">
                        <span class="dashicons dashicons-info"></span>
                        Cette action est irréversible. Assurez-vous d'avoir une sauvegarde récente.
                    </p>
                </div>
            </div>
        </div>
        
        <!-- Pied de page -->
        <div class="modal-footer">
            <div class="footer-actions">
                <button type="button" class="button button-secondary modal-cancel">
                    <span class="dashicons dashicons-no"></span>
                    Annuler
                </button>
                <button type="button" class="button button-link modal-dismiss">
                    Ignorer
                </button>
                <button type="button" class="button button-primary modal-confirm">
                    <span class="dashicons dashicons-yes"></span>
                    Confirmer
                </button>
            </div>
            
            <!-- Case à cocher pour ne plus demander -->
            <div class="footer-options">
                <label class="option-checkbox">
                    <input type="checkbox" id="dont-ask-again">
                    <span>Ne plus demander pour cette action</span>
                </label>
            </div>
        </div>
        
    </div>
</div>

<!-- Template pour les détails d'élément -->
<script type="text/html" id="detail-item-template">
    <li class="detail-item">
        <span class="detail-label"></span>
        <span class="detail-value"></span>
    </li>
</script>

<!-- Template pour les avertissements -->
<script type="text/html" id="warning-item-template">
    <li class="warning-item">
        <span class="dashicons dashicons-warning"></span>
        <span class="warning-text"></span>
    </li>
</script>

<!-- Script de gestion de la modal -->
<script type="text/javascript">
(function($) {
    'use strict';
    
    /**
     * Gestionnaire de modal de confirmation
     */
    class ConfirmationModal {
        constructor() {
            this.modal = $('#confirmation-modal');
            this.callbacks = {
                confirm: null,
                cancel: null,
                dismiss: null
            };
            this.storageKey = 'wootour_bulk_confirmations';
            this.init();
        }
        
        /**
         * Initialisation
         */
        init() {
            this.bindEvents();
            this.loadIgnoredConfirmations();
        }
        
        /**
         * Lier les événements
         */
        bindEvents() {
            const self = this;
            
            // Fermer la modal
            this.modal.find('.modal-close, .modal-cancel').on('click', function() {
                self.hide();
                if (self.callbacks.cancel) {
                    self.callbacks.cancel();
                }
            });
            
            // Confirmer
            this.modal.find('.modal-confirm').on('click', function() {
                // Sauvegarder le choix "ne plus demander"
                if ($('#dont-ask-again').is(':checked')) {
                    self.saveIgnoredConfirmation();
                }
                
                self.hide();
                if (self.callbacks.confirm) {
                    self.callbacks.confirm();
                }
            });
            
            // Ignorer
            this.modal.find('.modal-dismiss').on('click', function() {
                self.hide();
                if (self.callbacks.dismiss) {
                    self.callbacks.dismiss();
                }
            });
            
            // Empêcher la fermeture en cliquant sur l'overlay
            this.modal.find('.modal-overlay').on('click', function(e) {
                e.stopPropagation();
            });
        }
        
        /**
         * Afficher la modal
         * @param {Object} options - Options de la modal
         */
        show(options) {
            const defaults = {
                title: 'Confirmation requise',
                message: 'Êtes-vous sûr de vouloir effectuer cette action ?',
                details: null,
                warnings: null,
                confirmText: 'Confirmer',
                cancelText: 'Annuler',
                dismissText: 'Ignorer',
                showDismiss: false,
                showDontAsk: false,
                actionId: null,
                onConfirm: null,
                onCancel: null,
                onDismiss: null
            };
            
            const config = $.extend({}, defaults, options);
            
            // Vérifier si cette confirmation a été ignorée
            if (config.actionId && this.isConfirmationIgnored(config.actionId)) {
                if (config.onConfirm) {
                    config.onConfirm();
                }
                return;
            }
            
            // Mettre à jour le contenu
            this.modal.find('#confirmation-title').text(config.title);
            this.modal.find('#confirmation-message').html(config.message);
            this.modal.find('.modal-confirm').text(config.confirmText);
            this.modal.find('.modal-cancel').text(config.cancelText);
            this.modal.find('.modal-dismiss').text(config.dismissText);
            
            // Afficher/masquer le bouton ignorer
            if (config.showDismiss) {
                this.modal.find('.modal-dismiss').show();
            } else {
                this.modal.find('.modal-dismiss').hide();
            }
            
            // Afficher/masquer "ne plus demander"
            if (config.showDontAsk && config.actionId) {
                this.modal.find('.footer-options').show();
                $('#dont-ask-again').data('action-id', config.actionId);
            } else {
                this.modal.find('.footer-options').hide();
            }
            
            // Gérer les détails
            this.updateDetails(config.details);
            
            // Gérer les avertissements
            this.updateWarnings(config.warnings);
            
            // Stocker les callbacks
            this.callbacks = {
                confirm: config.onConfirm,
                cancel: config.onCancel,
                dismiss: config.onDismiss
            };
            
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
            this.callbacks = {
                confirm: null,
                cancel: null,
                dismiss: null
            };
            $('#dont-ask-again').prop('checked', false);
        }
        
        /**
         * Mettre à jour les détails
         * @param {Array|Object} details - Détails à afficher
         */
        updateDetails(details) {
            const $detailsContainer = $('#confirmation-details');
            const $detailsList = $('#details-list');
            
            $detailsList.empty();
            
            if (!details) {
                $detailsContainer.hide();
                return;
            }
            
            if ($.isArray(details)) {
                details.forEach(detail => {
                    const $item = $($('#detail-item-template').html());
                    $item.find('.detail-label').text(detail.label + ' : ');
                    $item.find('.detail-value').text(detail.value);
                    $detailsList.append($item);
                });
            } else if (typeof details === 'object') {
                Object.keys(details).forEach(key => {
                    const $item = $($('#detail-item-template').html());
                    $item.find('.detail-label').text(this.formatLabel(key) + ' : ');
                    $item.find('.detail-value').text(details[key]);
                    $detailsList.append($item);
                });
            } else {
                const $item = $($('#detail-item-template').html());
                $item.find('.detail-value').text(details);
                $detailsList.append($item);
            }
            
            $detailsContainer.show();
        }
        
        /**
         * Mettre à jour les avertissements
         * @param {Array} warnings - Avertissements à afficher
         */
        updateWarnings(warnings) {
            const $warningsContainer = $('#confirmation-warnings');
            const $warningsList = $('#warnings-list');
            
            $warningsList.empty();
            
            if (!warnings || !warnings.length) {
                $warningsContainer.hide();
                return;
            }
            
            warnings.forEach(warning => {
                const $item = $($('#warning-item-template').html());
                $item.find('.warning-text').text(warning);
                $warningsList.append($item);
            });
            
            $warningsContainer.show();
        }
        
        /**
         * Formater une clé en label lisible
         * @param {string} key - Clé à formater
         * @returns {string} Label formaté
         */
        formatLabel(key) {
            return key.replace(/_/g, ' ')
                     .replace(/\b\w/g, l => l.toUpperCase());
        }
        
        /**
         * Charger les confirmations ignorées
         */
        loadIgnoredConfirmations() {
            this.ignoredConfirmations = JSON.parse(
                localStorage.getItem(this.storageKey) || '[]'
            );
        }
        
        /**
         * Sauvegarder une confirmation ignorée
         */
        saveIgnoredConfirmation() {
            const actionId = $('#dont-ask-again').data('action-id');
            
            if (actionId && !this.ignoredConfirmations.includes(actionId)) {
                this.ignoredConfirmations.push(actionId);
                localStorage.setItem(
                    this.storageKey, 
                    JSON.stringify(this.ignoredConfirmations)
                );
            }
        }
        
        /**
         * Vérifier si une confirmation est ignorée
         * @param {string} actionId - ID de l'action
         * @returns {boolean} True si ignorée
         */
        isConfirmationIgnored(actionId) {
            return this.ignoredConfirmations.includes(actionId);
        }
        
        /**
         * Réinitialiser toutes les confirmations ignorées
         */
        resetIgnoredConfirmations() {
            localStorage.removeItem(this.storageKey);
            this.ignoredConfirmations = [];
        }
        
        /**
         * Afficher une confirmation de suppression
         * @param {Object} options - Options supplémentaires
         */
        showDeleteConfirmation(options) {
            const defaults = {
                title: 'Confirmer la suppression',
                message: 'Êtes-vous sûr de vouloir supprimer cet élément ? Cette action est irréversible.',
                itemName: 'l\'élément',
                itemCount: 1
            };
            
            const config = $.extend({}, defaults, options);
            
            if (config.itemCount > 1) {
                config.message = `Êtes-vous sûr de vouloir supprimer ${config.itemCount} éléments ? Cette action est irréversible.`;
            }
            
            this.show(config);
        }
        
        /**
         * Afficher une confirmation de modification en masse
         * @param {Object} options - Options supplémentaires
         */
        showBulkEditConfirmation(options) {
            const defaults = {
                title: 'Confirmer les modifications en masse',
                message: 'Vous êtes sur le point de modifier plusieurs produits. Voulez-vous continuer ?',
                productCount: 0,
                changes: {}
            };
            
            const config = $.extend({}, defaults, options);
            
            if (config.productCount > 0) {
                config.message = `Vous êtes sur le point de modifier ${config.productCount} produits. Voulez-vous continuer ?`;
            }
            
            // Ajouter les détails des changements
            if (Object.keys(config.changes).length > 0) {
                config.details = config.changes;
            }
            
            this.show(config);
        }
    }
    
    // Initialiser quand le DOM est prêt
    $(document).ready(function() {
        window.wootourConfirmationModal = new ConfirmationModal();
        
        // Exposer l'API
        window.WootourBulkModals = window.WootourBulkModals || {};
        window.WootourBulkModals.showConfirmation = function(options) {
            return window.wootourConfirmationModal.show(options);
        };
        
        window.WootourBulkModals.showDeleteConfirmation = function(options) {
            return window.wootourConfirmationModal.showDeleteConfirmation(options);
        };
        
        window.WootourBulkModals.showBulkEditConfirmation = function(options) {
            return window.wootourConfirmationModal.showBulkEditConfirmation(options);
        };
        
        // Exposer les fonctions utilitaires
        window.WootourBulkModals.confirm = function(message, title = 'Confirmation') {
            return new Promise((resolve) => {
                window.wootourConfirmationModal.show({
                    title: title,
                    message: message,
                    onConfirm: () => resolve(true),
                    onCancel: () => resolve(false)
                });
            });
        };
    });
    
})(jQuery);
</script>