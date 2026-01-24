/**
 * Traitement par lots avec progression AJAX
 * 
 * @package Wootour_Bulk_Editor
 * @version 1.0.0
 */

(function($) {
    'use strict';

    /**
     * Classe pour le traitement par lots
     */
    class BatchProcessor {
        constructor() {
            this.form = $('#bulk-edit-form');
            this.progressBar = $('#batch-progress');
            this.progressText = $('#progress-text');
            this.progressContainer = $('#progress-container');
            this.startButton = $('#start-batch');
            this.pauseButton = $('#pause-batch');
            this.resumeButton = $('#resume-batch');
            this.cancelButton = $('#cancel-batch');
            this.resultsContainer = $('#batch-results');
            this.currentBatch = 1;
            this.totalBatches = 1;
            this.isProcessing = false;
            this.isPaused = false;
            this.processedCount = 0;
            this.totalCount = 0;
            this.successCount = 0;
            this.errorCount = 0;
            this.batchSize = 50;
            this.xhr = null;
            this.init();
        }

        /**
         * Initialisation
         */
        init() {
            this.bindEvents();
            this.hideProgress();
        }

        /**
         * Lier les événements
         */
        bindEvents() {
            const self = this;

            // Démarrer le traitement
            this.startButton.on('click', function(e) {
                e.preventDefault();
                if (self.validateForm()) {
                    self.startProcessing();
                }
            });

            // Mettre en pause
            this.pauseButton.on('click', function(e) {
                e.preventDefault();
                self.pauseProcessing();
            });

            // Reprendre
            this.resumeButton.on('click', function(e) {
                e.preventDefault();
                self.resumeProcessing();
            });

            // Annuler
            this.cancelButton.on('click', function(e) {
                e.preventDefault();
                self.cancelProcessing();
            });

            // Empêcher la fermeture pendant le traitement
            $(window).on('beforeunload', function(e) {
                if (self.isProcessing && !self.isPaused) {
                    const message = 'Un traitement est en cours. Êtes-vous sûr de vouloir quitter ?';
                    e.returnValue = message;
                    return message;
                }
            });
        }

        /**
         * Valider le formulaire avant traitement
         * @returns {boolean} True si validation OK
         */
        validateForm() {
            // Valider la sélection des produits
            if (!window.WootourProductSelector || 
                !window.WootourProductSelector.validate()) {
                return false;
            }

            // Valider les dates
            if (window.WootourBulkCalendar) {
                const dates = window.WootourBulkCalendar.getSelectedDates();
                const hasDates = dates.start_date || dates.end_date || 
                               dates.exclude_dates || dates.specific_dates || 
                               dates.week_days;
                
                if (!hasDates) {
                    this.showError('Veuillez sélectionner au moins une date ou un jour de la semaine.');
                    return false;
                }
            }

            return true;
        }

        /**
         * Démarrer le traitement
         */
        startProcessing() {
            const selectedIds = window.WootourProductSelector.getSelectedIds();
            const formData = this.getFormData();
            
            this.totalCount = selectedIds.length;
            this.totalBatches = Math.ceil(this.totalCount / this.batchSize);
            this.currentBatch = 1;
            this.processedCount = 0;
            this.successCount = 0;
            this.errorCount = 0;
            
            this.showProgress();
            this.updateProgress(0, 'Initialisation...');
            
            // Désactiver le formulaire
            this.disableForm();
            
            // Traiter le premier lot
            this.processBatch(selectedIds, formData);
        }

        /**
         * Traiter un lot de produits
         * @param {Array} productIds - IDs des produits
         * @param {Object} formData - Données du formulaire
         */
        processBatch(productIds, formData) {
            if (this.isPaused) {
                return;
            }
            
            this.isProcessing = true;
            
            const startIndex = (this.currentBatch - 1) * this.batchSize;
            const endIndex = Math.min(startIndex + this.batchSize, productIds.length);
            const batchIds = productIds.slice(startIndex, endIndex);
            
            this.updateProgress(
                Math.round((this.processedCount / this.totalCount) * 100),
                `Traitement du lot ${this.currentBatch}/${this.totalBatches}...`
            );
            
            // Préparer les données AJAX
            const ajaxData = {
                action: 'wootour_bulk_process_batch',
                nonce: wootour_bulk_ajax.nonce,
                product_ids: batchIds,
                form_data: formData,
                batch_number: this.currentBatch,
                total_batches: this.totalBatches
            };
            
            // Envoyer la requête AJAX
            this.xhr = $.ajax({
                url: wootour_bulk_ajax.ajax_url,
                type: 'POST',
                dataType: 'json',
                data: ajaxData,
                timeout: 300000, // 5 minutes timeout
                
                beforeSend: () => {
                    this.updateButtonStates();
                },
                
                success: (response) => {
                    this.handleBatchSuccess(response, batchIds.length);
                },
                
                error: (xhr, status, error) => {
                    this.handleBatchError(xhr, status, error, batchIds);
                },
                
                complete: () => {
                    this.xhr = null;
                }
            });
        }

        /**
         * Gérer le succès d'un lot
         * @param {Object} response - Réponse du serveur
         * @param {number} batchSize - Taille du lot
         */
        handleBatchSuccess(response, batchSize) {
            if (response.success) {
                this.processedCount += batchSize;
                this.successCount += response.data.processed || batchSize;
                
                if (response.data.errors && response.data.errors.length > 0) {
                    this.errorCount += response.data.errors.length;
                    this.logErrors(response.data.errors);
                }
                
                // Mettre à jour la progression
                const percent = Math.round((this.processedCount / this.totalCount) * 100);
                this.updateProgress(
                    percent,
                    `Lot ${this.currentBatch}/${this.totalBatches} terminé - ` +
                    `${this.successCount} succès, ${this.errorCount} erreurs`
                );
                
                // Afficher les résultats intermédiaires
                if (this.currentBatch === this.totalBatches) {
                    this.finalizeProcessing();
                } else {
                    this.currentBatch++;
                    setTimeout(() => {
                        const remainingIds = window.WootourProductSelector.getSelectedIds();
                        const formData = this.getFormData();
                        this.processBatch(remainingIds, formData);
                    }, 500); // Petite pause entre les lots
                }
            } else {
                this.handleBatchError(null, 'error', response.data?.message || 'Erreur inconnue', []);
            }
        }

        /**
         * Gérer l'erreur d'un lot
         */
        handleBatchError(xhr, status, error, batchIds) {
            this.errorCount += batchIds.length;
            
            let errorMessage = 'Erreur lors du traitement du lot : ';
            
            if (status === 'timeout') {
                errorMessage += 'Timeout du serveur.';
            } else if (xhr && xhr.responseJSON && xhr.responseJSON.data) {
                errorMessage += xhr.responseJSON.data;
            } else {
                errorMessage += error || 'Erreur inconnue';
            }
            
            this.logError({
                batch: this.currentBatch,
                message: errorMessage,
                product_ids: batchIds
            });
            
            // Continuer avec le lot suivant si possible
            if (this.currentBatch < this.totalBatches && !this.isPaused) {
                this.currentBatch++;
                const remainingIds = window.WootourProductSelector.getSelectedIds();
                const formData = this.getFormData();
                this.processBatch(remainingIds, formData);
            } else {
                this.updateProgress(
                    Math.round((this.processedCount / this.totalCount) * 100),
                    `Erreur - ${this.errorCount} produits en échec`
                );
            }
        }

        /**
         * Finaliser le traitement
         */
        finalizeProcessing() {
            this.isProcessing = false;
            
            // Mettre à jour la barre de progression
            this.updateProgress(100, 'Traitement terminé !');
            
            // Afficher les résultats finaux
            this.showResults();
            
            // Réactiver le formulaire
            this.enableForm();
            
            // Mettre à jour les boutons
            this.updateButtonStates();
            
            // Enregistrer dans l'historique
            this.logToHistory();
        }

        /**
         * Afficher les résultats
         */
        showResults() {
            const resultsHtml = `
                <div class="batch-results-card">
                    <h3><span class="dashicons dashicons-yes-alt"></span> Traitement terminé</h3>
                    <div class="results-stats">
                        <div class="stat stat-success">
                            <span class="stat-value">${this.successCount}</span>
                            <span class="stat-label">Succès</span>
                        </div>
                        <div class="stat stat-error">
                            <span class="stat-value">${this.errorCount}</span>
                            <span class="stat-label">Erreurs</span>
                        </div>
                        <div class="stat stat-total">
                            <span class="stat-value">${this.totalCount}</span>
                            <span class="stat-label">Total</span>
                        </div>
                    </div>
                    ${this.errorCount > 0 ? 
                        `<div class="results-errors">
                            <p><strong>Des erreurs sont survenues :</strong></p>
                            <button id="view-errors" class="button button-secondary">
                                Voir les détails
                            </button>
                        </div>` : 
                        ''}
                    <div class="results-actions">
                        <button id="close-results" class="button button-primary">
                            Fermer
                        </button>
                        <button id="export-results" class="button">
                            Exporter le rapport
                        </button>
                    </div>
                </div>
            `;
            
            this.resultsContainer.html(resultsHtml).show();
            
            // Ajouter les événements pour les résultats
            $('#close-results').on('click', () => {
                this.resultsContainer.hide();
            });
            
            $('#view-errors').on('click', () => {
                this.showErrorDetails();
            });
            
            $('#export-results').on('click', () => {
                this.exportResults();
            });
        }

        /**
         * Mettre en pause le traitement
         */
        pauseProcessing() {
            if (this.xhr) {
                this.xhr.abort();
            }
            
            this.isPaused = true;
            this.isProcessing = false;
            
            this.updateProgress(
                Math.round((this.processedCount / this.totalCount) * 100),
                'Traitement en pause'
            );
            
            this.updateButtonStates();
        }

        /**
         * Reprendre le traitement
         */
        resumeProcessing() {
            this.isPaused = false;
            this.isProcessing = true;
            
            const remainingIds = window.WootourProductSelector.getSelectedIds();
            const formData = this.getFormData();
            
            this.updateProgress(
                Math.round((this.processedCount / this.totalCount) * 100),
                `Reprise du traitement...`
            );
            
            this.processBatch(remainingIds, formData);
            this.updateButtonStates();
        }

        /**
         * Annuler le traitement
         */
        cancelProcessing() {
            if (confirm('Êtes-vous sûr de vouloir annuler le traitement ? Les modifications déjà appliquées seront conservées.')) {
                if (this.xhr) {
                    this.xhr.abort();
                }
                
                this.isProcessing = false;
                this.isPaused = false;
                
                this.hideProgress();
                this.enableForm();
                this.updateButtonStates();
                
                this.showNotification('Traitement annulé.', 'warning');
            }
        }

        /**
         * Obtenir les données du formulaire
         * @returns {Object} Données du formulaire
         */
        getFormData() {
            const formData = {};
            
            // Récupérer les dates du calendrier
            if (window.WootourBulkCalendar) {
                Object.assign(formData, window.WootourBulkCalendar.getSelectedDates());
            }
            
            // Récupérer les autres champs
            this.form.find('input, select, textarea').each(function() {
                const name = $(this).attr('name');
                if (name && !name.includes('[]')) {
                    formData[name] = $(this).val();
                }
            });
            
            return formData;
        }

        /**
         * Mettre à jour la barre de progression
         */
        updateProgress(percent, message) {
            this.progressBar.css('width', percent + '%');
            this.progressBar.attr('aria-valuenow', percent);
            this.progressText.text(message);
            
            // Animation
            if (percent === 100) {
                this.progressBar.addClass('progress-complete');
            } else {
                this.progressBar.removeClass('progress-complete');
            }
        }

        /**
         * Afficher la barre de progression
         */
        showProgress() {
            this.progressContainer.slideDown();
            this.resultsContainer.hide();
        }

        /**
         * Masquer la barre de progression
         */
        hideProgress() {
            this.progressContainer.slideUp();
        }

        /**
         * Désactiver le formulaire
         */
        disableForm() {
            this.form.find('input, select, button, textarea')
                .not(this.pauseButton)
                .not(this.cancelButton)
                .prop('disabled', true);
            
            this.startButton.hide();
            this.pauseButton.show();
            this.cancelButton.show();
            this.resumeButton.hide();
        }

        /**
         * Réactiver le formulaire
         */
        enableForm() {
            this.form.find('input, select, button, textarea').prop('disabled', false);
            
            this.startButton.show();
            this.pauseButton.hide();
            this.cancelButton.hide();
            this.resumeButton.hide();
        }

        /**
         * Mettre à jour l'état des boutons
         */
        updateButtonStates() {
            if (this.isProcessing && !this.isPaused) {
                this.startButton.hide();
                this.pauseButton.show();
                this.cancelButton.show();
                this.resumeButton.hide();
            } else if (this.isPaused) {
                this.startButton.hide();
                this.pauseButton.hide();
                this.cancelButton.show();
                this.resumeButton.show();
            } else {
                this.startButton.show();
                this.pauseButton.hide();
                this.cancelButton.hide();
                this.resumeButton.hide();
            }
        }

        /**
         * Journaliser les erreurs
         */
        logErrors(errors) {
            errors.forEach(error => {
                this.logError(error);
            });
        }

        /**
         * Journaliser une erreur
         */
        logError(error) {
            const errors = JSON.parse(localStorage.getItem('wootour_bulk_errors') || '[]');
            errors.push({
                timestamp: new Date().toISOString(),
                ...error
            });
            localStorage.setItem('wootour_bulk_errors', JSON.stringify(errors));
        }

        /**
         * Afficher les détails des erreurs
         */
        showErrorDetails() {
            const errors = JSON.parse(localStorage.getItem('wootour_bulk_errors') || '[]');
            
            if (errors.length === 0) {
                alert('Aucune erreur à afficher.');
                return;
            }
            
            let errorDetails = '<h3>Détails des erreurs :</h3><ul>';
            
            errors.forEach((error, index) => {
                errorDetails += `
                    <li>
                        <strong>Lot ${error.batch || 'N/A'}:</strong>
                        ${error.message || 'Erreur inconnue'}
                        ${error.product_ids ? `(Produits: ${error.product_ids.join(', ')})` : ''}
                    </li>
                `;
            });
            
            errorDetails += '</ul>';
            
            if (window.WootourBulkModals) {
                window.WootourBulkModals.showError(errorDetails, 'Détails des erreurs');
            } else {
                alert(errorDetails);
            }
        }

        /**
         * Exporter les résultats
         */
        exportResults() {
            const data = {
                timestamp: new Date().toISOString(),
                total: this.totalCount,
                success: this.successCount,
                errors: this.errorCount,
                errors_details: JSON.parse(localStorage.getItem('wootour_bulk_errors') || '[]')
            };
            
            const blob = new Blob([JSON.stringify(data, null, 2)], {
                type: 'application/json'
            });
            
            const url = URL.createObjectURL(blob);
            const a = document.createElement('a');
            a.href = url;
            a.download = `wootour-bulk-report-${new Date().getTime()}.json`;
            document.body.appendChild(a);
            a.click();
            document.body.removeChild(a);
            URL.revokeObjectURL(url);
        }

        /**
         * Journaliser dans l'historique
         */
        logToHistory() {
            const history = JSON.parse(localStorage.getItem('wootour_bulk_history') || '[]');
            
            history.unshift({
                timestamp: new Date().toISOString(),
                total: this.totalCount,
                success: this.successCount,
                errors: this.errorCount,
                operation: this.getFormData()
            });
            
            // Garder seulement les 50 dernières entrées
            if (history.length > 50) {
                history.pop();
            }
            
            localStorage.setItem('wootour_bulk_history', JSON.stringify(history));
        }

        /**
         * Afficher une notification
         */
        showNotification(message, type = 'info') {
            const notification = $(`
                <div class="notice notice-${type} is-dismissible">
                    <p>${message}</p>
                </div>
            `);
            
            $('#wpbody-content').prepend(notification);
            
            setTimeout(() => {
                notification.fadeOut(() => notification.remove());
            }, 5000);
            
            notification.on('click', '.notice-dismiss', () => {
                notification.remove();
            });
        }
    }

    /**
     * Initialisation
     */
    $(document).ready(function() {
        window.wootourBatchProcessor = new BatchProcessor();
        
        // Exposer l'API
        window.WootourBatchProcessor = {
            start: function() {
                return window.wootourBatchProcessor.startProcessing();
            },
            pause: function() {
                return window.wootourBatchProcessor.pauseProcessing();
            },
            resume: function() {
                return window.wootourBatchProcessor.resumeProcessing();
            },
            cancel: function() {
                return window.wootourBatchProcessor.cancelProcessing();
            }
        };
    });

})(jQuery);