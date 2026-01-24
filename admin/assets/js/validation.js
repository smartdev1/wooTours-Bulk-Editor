/**
 * Validation côté client du formulaire
 * 
 * @package Wootour_Bulk_Editor
 * @version 1.0.0
 */

(function($) {
    'use strict';

    /**
     * Classe de validation
     */
    class FormValidator {
        constructor() {
            this.form = $('#bulk-edit-form');
            this.errors = [];
            this.warnings = [];
            this.init();
        }

        /**
         * Initialisation
         */
        init() {
            this.bindEvents();
            this.setupValidationRules();
        }

        /**
         * Lier les événements
         */
        bindEvents() {
            const self = this;

            // Validation à la soumission
            this.form.on('submit', function(e) {
                if (!self.validate()) {
                    e.preventDefault();
                    self.displayErrors();
                    return false;
                }
                
                // Vérifier les avertissements
                if (self.warnings.length > 0) {
                    if (!self.displayWarnings()) {
                        e.preventDefault();
                        return false;
                    }
                }
                
                return true;
            });

            // Validation en temps réel
            this.form.on('blur', 'input, select, textarea', function() {
                self.validateField($(this));
            });

            // Nettoyer les erreurs quand on modifie
            this.form.on('input change', 'input, select, textarea', function() {
                self.clearFieldError($(this));
            });
        }

        /**
         * Configurer les règles de validation
         */
        setupValidationRules() {
            // Pas besoin de stocker explicitement, validation à la volée
        }

        /**
         * Valider l'ensemble du formulaire
         * @returns {boolean} True si valide
         */
        validate() {
            this.errors = [];
            this.warnings = [];

            // 1. Valider la sélection des produits
            this.validateProductSelection();

            // 2. Valider les dates
            this.validateDates();

            // 3. Valider les champs individuels
            this.validateIndividualFields();

            // 4. Vérifier les avertissements (non bloquants)
            this.checkWarnings();

            return this.errors.length === 0;
        }

        /**
         * Valider la sélection des produits
         */
        validateProductSelection() {
            if (!window.WootourProductSelector) {
                this.addError('Le sélecteur de produits n\'est pas initialisé.');
                return;
            }

            const selectedCount = window.WootourProductSelector.getCount();
            
            if (selectedCount === 0) {
                this.addError('Veuillez sélectionner au moins un produit.');
            } else if (selectedCount > 1000) {
                this.addWarning(
                    `Vous avez sélectionné ${selectedCount} produits. ` +
                    `Le traitement peut prendre plusieurs minutes.`
                );
            }
        }

        /**
         * Valider les dates
         */
        validateDates() {
            if (!window.WootourBulkCalendar) {
                return;
            }

            const dates = window.WootourBulkCalendar.getSelectedDates();
            const hasDates = dates.start_date || dates.end_date || 
                           dates.exclude_dates || dates.specific_dates || 
                           dates.week_days;

            if (!hasDates) {
                this.addError('Veuillez sélectionner au moins une date ou un jour de la semaine.');
                return;
            }

            // Validation de la cohérence des dates
            if (dates.start_date && dates.end_date) {
                const start = new Date(dates.start_date);
                const end = new Date(dates.end_date);
                
                if (start > end) {
                    this.addError('La date de début doit être antérieure ou égale à la date de fin.');
                }
                
                // Vérifier que la période n'est pas trop longue
                const diffTime = Math.abs(end - start);
                const diffDays = Math.ceil(diffTime / (1000 * 60 * 60 * 24));
                
                if (diffDays > 365) {
                    this.addWarning(
                        `Vous avez sélectionné une période de ${diffDays} jours. ` +
                        `Cela affectera un grand nombre de dates.`
                    );
                }
            }

            // Validation des dates spécifiques
            if (dates.specific_dates) {
                const specificDates = dates.specific_dates.split(',').filter(d => d.trim());
                
                if (specificDates.length > 50) {
                    this.addWarning(
                        `Vous avez sélectionné ${specificDates.length} dates spécifiques. ` +
                        `Le traitement peut être ralenti.`
                    );
                }
                
                // Vérifier les doublons
                const uniqueDates = [...new Set(specificDates)];
                if (uniqueDates.length !== specificDates.length) {
                    this.addWarning('Certaines dates spécifiques sont en doublon.');
                }
            }

            // Vérifier les conflits entre dates
            this.checkDateConflicts(dates);
        }

        /**
         * Vérifier les conflits de dates
         * @param {Object} dates - Objet dates
         */
        checkDateConflicts(dates) {
            if (!dates.specific_dates || !dates.exclude_dates) {
                return;
            }

            const specificDates = dates.specific_dates.split(',').filter(d => d.trim());
            const excludeDates = dates.exclude_dates.split(',').filter(d => d.trim());
            
            const conflicts = specificDates.filter(date => 
                excludeDates.includes(date)
            );
            
            if (conflicts.length > 0) {
                this.addWarning(
                    `Certaines dates sont à la fois spécifiques et exclues : ` +
                    `${conflicts.join(', ')}`
                );
            }
        }

        /**
         * Valider les champs individuels
         */
        validateIndividualFields() {
            // Validation des champs de texte
            $('input[type="text"], textarea').each((index, element) => {
                const $field = $(element);
                const value = $field.val().trim();
                const maxLength = $field.data('maxlength') || 255;
                
                if (value.length > maxLength) {
                    this.addFieldError(
                        $field,
                        `Ce champ ne doit pas dépasser ${maxLength} caractères.`
                    );
                }
            });

            // Validation des nombres
            $('input[type="number"]').each((index, element) => {
                const $field = $(element);
                const value = parseFloat($field.val());
                const min = parseFloat($field.attr('min')) || 0;
                const max = parseFloat($field.attr('max')) || 999999;
                
                if (!isNaN(value) && (value < min || value > max)) {
                    this.addFieldError(
                        $field,
                        `La valeur doit être comprise entre ${min} et ${max}.`
                    );
                }
            });

            // Validation des emails
            $('input[type="email"]').each((index, element) => {
                const $field = $(element);
                const value = $field.val().trim();
                
                if (value && !this.isValidEmail(value)) {
                    this.addFieldError($field, 'Adresse email invalide.');
                }
            });
        }

        /**
         * Vérifier les avertissements
         */
        checkWarnings() {
            // Vérifier le nombre total de modifications
            if (window.WootourProductSelector) {
                const selectedCount = window.WootourProductSelector.getCount();
                
                if (selectedCount > 500) {
                    this.addWarning(
                        `Vous êtes sur le point de modifier ${selectedCount} produits. ` +
                        `Cette opération est irréversible.`
                    );
                }
            }
        }

        /**
         * Valider un champ individuel
         * @param {jQuery} $field - Champ à valider
         */
        validateField($field) {
            this.clearFieldError($field);
            
            const fieldName = $field.attr('name');
            const value = $field.val().trim();
            
            // Validation selon le type de champ
            switch ($field.attr('type')) {
                case 'email':
                    if (value && !this.isValidEmail(value)) {
                        this.addFieldError($field, 'Adresse email invalide.');
                    }
                    break;
                    
                case 'number':
                    const numValue = parseFloat(value);
                    const min = parseFloat($field.attr('min'));
                    const max = parseFloat($field.attr('max'));
                    
                    if (value && !isNaN(numValue)) {
                        if (!isNaN(min) && numValue < min) {
                            this.addFieldError($field, `La valeur minimum est ${min}.`);
                        }
                        if (!isNaN(max) && numValue > max) {
                            this.addFieldError($field, `La valeur maximum est ${max}.`);
                        }
                    }
                    break;
                    
                case 'text':
                    const maxLength = $field.data('maxlength') || 255;
                    if (value.length > maxLength) {
                        this.addFieldError(
                            $field,
                            `Maximum ${maxLength} caractères.`
                        );
                    }
                    break;
                    
                case 'url':
                    if (value && !this.isValidUrl(value)) {
                        this.addFieldError($field, 'URL invalide.');
                    }
                    break;
            }
            
            // Validation des champs requis
            if ($field.prop('required') && !value) {
                this.addFieldError($field, 'Ce champ est requis.');
            }
        }

        /**
         * Ajouter une erreur
         * @param {string} message - Message d'erreur
         */
        addError(message) {
            this.errors.push(message);
        }

        /**
         * Ajouter un avertissement
         * @param {string} message - Message d'avertissement
         */
        addWarning(message) {
            this.warnings.push(message);
        }

        /**
         * Ajouter une erreur de champ
         * @param {jQuery} $field - Champ en erreur
         * @param {string} message - Message d'erreur
         */
        addFieldError($field, message) {
            $field.addClass('field-error');
            
            // Ajouter le message d'erreur
            let $error = $field.siblings('.field-error-message');
            if ($error.length === 0) {
                $error = $('<div class="field-error-message"></div>');
                $field.after($error);
            }
            
            $error.text(message).show();
            
            this.errors.push(`${$field.attr('name') || 'Champ'}: ${message}`);
        }

        /**
         * Effacer l'erreur d'un champ
         * @param {jQuery} $field - Champ à nettoyer
         */
        clearFieldError($field) {
            $field.removeClass('field-error');
            $field.siblings('.field-error-message').hide();
        }

        /**
         * Afficher toutes les erreurs
         */
        displayErrors() {
            if (this.errors.length === 0) {
                return;
            }

            let errorHtml = '<div class="validation-errors">';
            errorHtml += '<h3>Des erreurs ont été détectées :</h3>';
            errorHtml += '<ul>';
            
            this.errors.forEach(error => {
                errorHtml += `<li>${error}</li>`;
            });
            
            errorHtml += '</ul></div>';

            if (window.WootourBulkModals) {
                window.WootourBulkModals.showError(errorHtml, 'Erreurs de validation');
            } else {
                alert('Erreurs de validation:\n\n' + this.errors.join('\n'));
            }
        }

        /**
         * Afficher les avertissements
         * @returns {boolean} True si l'utilisateur confirme
         */
        displayWarnings() {
            if (this.warnings.length === 0) {
                return true;
            }

            let warningHtml = '<div class="validation-warnings">';
            warningHtml += '<h3>Avertissements :</h3>';
            warningHtml += '<ul>';
            
            this.warnings.forEach(warning => {
                warningHtml += `<li>${warning}</li>`;
            });
            
            warningHtml += '</ul>';
            warningHtml += '<p>Voulez-vous continuer malgré tout ?</p>';
            warningHtml += '</div>';

            if (window.WootourBulkModals) {
                return new Promise((resolve) => {
                    window.WootourBulkModals.showWarning(
                        warningHtml,
                        'Confirmation requise',
                        () => resolve(true),
                        () => resolve(false)
                    );
                });
            } else {
                return confirm(
                    'Avertissements:\n\n' + 
                    this.warnings.join('\n') + 
                    '\n\nVoulez-vous continuer ?'
                );
            }
        }

        /**
         * Vérifier si un email est valide
         * @param {string} email - Email à vérifier
         * @returns {boolean} True si valide
         */
        isValidEmail(email) {
            const re = /^[^\s@]+@[^\s@]+\.[^\s@]+$/;
            return re.test(email);
        }

        /**
         * Vérifier si une URL est valide
         * @param {string} url - URL à vérifier
         * @returns {boolean} True si valide
         */
        isValidUrl(url) {
            try {
                new URL(url);
                return true;
            } catch (_) {
                return false;
            }
        }

        /**
         * Obtenir le résumé de validation
         * @returns {Object} Résumé des erreurs et avertissements
         */
        getValidationSummary() {
            return {
                errors: this.errors,
                warnings: this.warnings,
                isValid: this.errors.length === 0,
                hasWarnings: this.warnings.length > 0
            };
        }
    }

    /**
     * Initialisation
     */
    $(document).ready(function() {
        window.wootourFormValidator = new FormValidator();
        
        // Exposer l'API
        window.WootourFormValidator = {
            validate: function() {
                return window.wootourFormValidator.validate();
            },
            getSummary: function() {
                return window.wootourFormValidator.getValidationSummary();
            },
            validateField: function(fieldSelector) {
                const $field = $(fieldSelector);
                if ($field.length) {
                    window.wootourFormValidator.validateField($field);
                }
            }
        };
    });

})(jQuery);