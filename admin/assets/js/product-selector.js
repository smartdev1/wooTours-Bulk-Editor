/**
 * Gestion de la sélection des produits et du filtrage par catégorie
 * 
 * @package Wootour_Bulk_Editor
 * @version 1.0.0
 */

(function($) {
    'use strict';

    /**
     * Classe pour la sélection et le filtrage des produits
     */
    class ProductSelector {
        constructor() {
            this.selectAllCheckbox = $('#select-all-products');
            this.productCheckboxes = $('.product-checkbox');
            this.categoryFilter = $('#category-filter');
            this.searchFilter = $('#product-search');
            this.selectedCount = $('#selected-count');
            this.totalCount = $('#total-count');
            this.currentProducts = [];
            this.allProducts = [];
            this.batchSize = 50;
            this.init();
        }

        /**
         * Initialisation
         */
        init() {
            this.loadInitialProducts();
            this.bindEvents();
            this.updateCounters();
        }

        /**
         * Charger les produits initiaux
         */
        loadInitialProducts() {
            this.allProducts = [];
            
            $('.product-item').each((index, element) => {
                const product = {
                    id: $(element).data('product-id'),
                    name: $(element).data('product-name'),
                    sku: $(element).data('product-sku'),
                    categories: $(element).data('product-categories') ? 
                                $(element).data('product-categories').split(',') : [],
                    type: $(element).data('product-type'),
                    checkbox: $(element).find('.product-checkbox'),
                    element: $(element)
                };
                
                this.allProducts.push(product);
            });
            
            this.currentProducts = [...this.allProducts];
            this.updateTotalCount();
        }

        /**
         * Lier les événements
         */
        bindEvents() {
            const self = this;

            // Sélection/désélection globale
            this.selectAllCheckbox.on('change', function() {
                const isChecked = $(this).is(':checked');
                self.toggleAllProducts(isChecked);
            });

            // Filtre par catégorie
            this.categoryFilter.on('change', function() {
                const categoryId = $(this).val();
                self.filterByCategory(categoryId);
            });

            // Filtre par recherche
            this.searchFilter.on('keyup', _.debounce(function() {
                const searchTerm = $(this).val().toLowerCase();
                self.filterBySearch(searchTerm);
            }, 300));

            // Case à cocher individuelle
            $(document).on('change', '.product-checkbox', function() {
                self.updateSelectAllState();
                self.updateSelectedCount();
            });

            // Bouton de sélection par type
            $('.select-by-type').on('click', function(e) {
                e.preventDefault();
                const productType = $(this).data('type');
                self.selectByType(productType);
            });

            // Bouton d'inversion de sélection
            $('#invert-selection').on('click', function(e) {
                e.preventDefault();
                self.invertSelection();
            });

            // Bouton de nettoyage
            $('#clear-selection').on('click', function(e) {
                e.preventDefault();
                self.clearSelection();
            });
        }

        /**
         * Filtrer par catégorie
         * @param {string} categoryId - ID de la catégorie
         */
        filterByCategory(categoryId) {
            if (!categoryId) {
                // Afficher tous les produits
                this.currentProducts = [...this.allProducts];
                this.showAllProducts();
            } else {
                // Filtrer les produits
                this.currentProducts = this.allProducts.filter(product => {
                    return product.categories.includes(categoryId);
                });
                
                this.showFilteredProducts();
            }
            
            this.updateSelectAllState();
            this.updateCounters();
        }

        /**
         * Filtrer par terme de recherche
         * @param {string} searchTerm - Terme de recherche
         */
        filterBySearch(searchTerm) {
            if (!searchTerm.trim()) {
                this.currentProducts = [...this.allProducts];
                this.showAllProducts();
            } else {
                this.currentProducts = this.allProducts.filter(product => {
                    return product.name.toLowerCase().includes(searchTerm) || 
                           product.sku.toLowerCase().includes(searchTerm);
                });
                
                this.showFilteredProducts();
            }
            
            this.updateSelectAllState();
            this.updateCounters();
        }

        /**
         * Afficher tous les produits
         */
        showAllProducts() {
            $('.product-item').show();
        }

        /**
         * Afficher les produits filtrés
         */
        showFilteredProducts() {
            const visibleIds = this.currentProducts.map(p => p.id);
            
            $('.product-item').each((index, element) => {
                const productId = $(element).data('product-id');
                if (visibleIds.includes(productId.toString())) {
                    $(element).show();
                } else {
                    $(element).hide();
                }
            });
        }

        /**
         * Sélectionner/désélectionner tous les produits
         * @param {boolean} isChecked - État de sélection
         */
        toggleAllProducts(isChecked) {
            this.currentProducts.forEach(product => {
                product.checkbox.prop('checked', isChecked);
                product.element.toggleClass('selected', isChecked);
            });
            
            this.updateSelectedCount();
        }

        /**
         * Mettre à jour l'état de "Sélectionner tout"
         */
        updateSelectAllState() {
            const visibleCheckboxes = this.currentProducts.map(p => p.checkbox);
            const checkedCount = visibleCheckboxes.filter(cb => cb.is(':checked')).length;
            const totalVisible = visibleCheckboxes.length;
            
            if (totalVisible === 0) {
                this.selectAllCheckbox.prop('checked', false);
                this.selectAllCheckbox.prop('indeterminate', false);
            } else if (checkedCount === totalVisible) {
                this.selectAllCheckbox.prop('checked', true);
                this.selectAllCheckbox.prop('indeterminate', false);
            } else if (checkedCount > 0) {
                this.selectAllCheckbox.prop('checked', false);
                this.selectAllCheckbox.prop('indeterminate', true);
            } else {
                this.selectAllCheckbox.prop('checked', false);
                this.selectAllCheckbox.prop('indeterminate', false);
            }
        }

        /**
         * Sélectionner par type de produit
         * @param {string} productType - Type de produit
         */
        selectByType(productType) {
            const productsToSelect = this.currentProducts.filter(product => {
                return product.type === productType;
            });
            
            productsToSelect.forEach(product => {
                product.checkbox.prop('checked', true);
                product.element.addClass('selected');
            });
            
            this.updateSelectAllState();
            this.updateSelectedCount();
        }

        /**
         * Inverser la sélection
         */
        invertSelection() {
            this.currentProducts.forEach(product => {
                const isChecked = product.checkbox.is(':checked');
                product.checkbox.prop('checked', !isChecked);
                product.element.toggleClass('selected', !isChecked);
            });
            
            this.updateSelectAllState();
            this.updateSelectedCount();
        }

        /**
         * Effacer toute la sélection
         */
        clearSelection() {
            if (confirm('Êtes-vous sûr de vouloir effacer toute la sélection ?')) {
                this.allProducts.forEach(product => {
                    product.checkbox.prop('checked', false);
                    product.element.removeClass('selected');
                });
                
                this.selectAllCheckbox.prop('checked', false);
                this.selectAllCheckbox.prop('indeterminate', false);
                this.updateSelectedCount();
            }
        }

        /**
         * Mettre à jour le compteur de sélection
         */
        updateSelectedCount() {
            const selectedCount = this.allProducts.filter(p => 
                p.checkbox.is(':checked')
            ).length;
            
            this.selectedCount.text(selectedCount);
            
            // Mettre à jour le champ caché pour la soumission
            $('#selected-products-count').val(selectedCount);
        }

        /**
         * Mettre à jour le compteur total
         */
        updateTotalCount() {
            this.totalCount.text(this.allProducts.length);
        }

        /**
         * Mettre à jour tous les compteurs
         */
        updateCounters() {
            this.updateSelectedCount();
            this.updateTotalCount();
        }

        /**
         * Obtenir les IDs des produits sélectionnés
         * @returns {Array} Tableau d'IDs
         */
        getSelectedProductIds() {
            return this.allProducts
                .filter(p => p.checkbox.is(':checked'))
                .map(p => p.id);
        }

        /**
         * Obtenir le nombre de produits sélectionnés
         * @returns {number} Nombre de produits sélectionnés
         */
        getSelectedCount() {
            return this.getSelectedProductIds().length;
        }

        /**
         * Valider avant soumission
         * @returns {boolean} True si validation OK
         */
        validateSelection() {
            const selectedCount = this.getSelectedCount();
            
            if (selectedCount === 0) {
                this.showError('Veuillez sélectionner au moins un produit.');
                return false;
            }
            
            if (selectedCount > 1000) {
                this.showWarning(
                    `Vous avez sélectionné ${selectedCount} produits. ` +
                    `Le traitement peut prendre du temps. Souhaitez-vous continuer ?`
                );
                // Retourner true mais afficher un avertissement
                return true;
            }
            
            return true;
        }

        /**
         * Afficher une erreur
         * @param {string} message - Message d'erreur
         */
        showError(message) {
            if (window.WootourBulkModals) {
                window.WootourBulkModals.showError(message);
            } else {
                alert(message);
            }
        }

        /**
         * Afficher un avertissement
         * @param {string} message - Message d'avertissement
         */
        showWarning(message) {
            if (window.WootourBulkModals) {
                window.WootourBulkModals.showWarning(message);
            } else {
                if (confirm(message + '\n\nCliquez sur OK pour continuer.')) {
                    return true;
                }
                return false;
            }
        }
    }

    /**
     * Initialisation
     */
    $(document).ready(function() {
        window.wootourProductSelector = new ProductSelector();
        
        // Exposer les méthodes nécessaires
        window.WootourProductSelector = {
            getSelectedIds: function() {
                return window.wootourProductSelector.getSelectedProductIds();
            },
            validate: function() {
                return window.wootourProductSelector.validateSelection();
            },
            getCount: function() {
                return window.wootourProductSelector.getSelectedCount();
            }
        };
    });

})(jQuery);