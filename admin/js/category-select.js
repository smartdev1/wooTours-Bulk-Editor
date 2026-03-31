/**
 * Gestion de la sélection par catégories avec choix des produits
 */

(function($) {
    'use strict';

    const WBE_CategorySelection = {
        
        selectedCategories: {},
        categoryProducts: {},
        
        /**
         * Initialisation
         */
        init: function() {
            this.bindEvents();
            this.initSelect2();
        },

        /**
         * Initialisation de Select2 pour les catégories
         */
        initSelect2: function() {
            $('#wbe-categories').select2({
                placeholder: 'Rechercher des catégories...',
                allowClear: true,
                width: '100%'
            });
        },

        /**
         * Liaison des événements
         */
        bindEvents: function() {
            const self = this;

            // Changement de sélection de catégories
            $('#wbe-categories').on('change', function() {
                self.handleCategoryChange($(this).val());
            });

            // Changement de mode de sélection par catégorie
            $(document).on('change', '[name^="category_"][name$="_mode"]', function() {
                const categoryId = $(this).attr('name').match(/category_(\d+)_mode/)[1];
                const mode = $(this).val();
                self.toggleProductSelector(categoryId, mode);
            });

            // Suppression d'une catégorie
            $(document).on('click', '.wbe-remove-category', function() {
                const categoryId = $(this).closest('.wbe-category-item').data('category-id');
                self.removeCategory(categoryId);
            });

            // Recherche de produits dans une catégorie
            $(document).on('input', '.wbe-category-product-search', function() {
                const categoryId = $(this).data('category-id');
                const searchTerm = $(this).val().toLowerCase();
                self.filterCategoryProducts(categoryId, searchTerm);
            });

            // Sélection/Désélection de produits
            $(document).on('change', '.wbe-product-checkbox', function() {
                const categoryId = $(this).data('category-id');
                self.updateSelectedCount(categoryId);
                self.updateTotalProducts();
            });
        },

        /**
         * Gestion du changement de catégories sélectionnées
         */
        handleCategoryChange: function(selectedIds) {
            const self = this;
            
            if (!selectedIds || selectedIds.length === 0) {
                $('#wbe-selected-categories-wrapper').hide();
                $('#wbe-categories-list').empty();
                this.selectedCategories = {};
                this.updateTotalProducts();
                return;
            }

            // Afficher le wrapper
            $('#wbe-selected-categories-wrapper').show();

            // Récupérer les infos des catégories sélectionnées
            selectedIds.forEach(function(categoryId) {
                if (!self.selectedCategories[categoryId]) {
                    self.addCategory(categoryId);
                }
            });

            // Retirer les catégories désélectionnées
            Object.keys(this.selectedCategories).forEach(function(categoryId) {
                if (!selectedIds.includes(categoryId)) {
                    self.removeCategory(categoryId, false);
                }
            });

            this.updateTotalProducts();
        },

        /**
         * Ajouter une catégorie
         */
        addCategory: function(categoryId) {
            const self = this;
            const $option = $('#wbe-categories option[value="' + categoryId + '"]');
            const categoryName = $option.text().split(' (')[0];
            const productCount = $option.data('count');

            // Stocker les infos
            this.selectedCategories[categoryId] = {
                name: categoryName,
                count: productCount,
                mode: 'all',
                selectedProducts: []
            };

            // Créer l'élément HTML
            const template = $('#wbe-category-template').html();
            const html = template
                .replace(/\{\{categoryId\}\}/g, categoryId)
                .replace(/\{\{categoryName\}\}/g, categoryName)
                .replace(/\{\{productCount\}\}/g, productCount);

            $('#wbe-categories-list').append(html);

            // Charger les produits en arrière-plan
            this.loadCategoryProducts(categoryId);
        },

        /**
         * Retirer une catégorie
         */
        removeCategory: function(categoryId, updateSelect = true) {
            // Retirer de la sélection
            delete this.selectedCategories[categoryId];
            delete this.categoryProducts[categoryId];

            // Retirer de l'interface
            $('.wbe-category-item[data-category-id="' + categoryId + '"]').remove();

            // Mettre à jour Select2
            if (updateSelect) {
                const currentValues = $('#wbe-categories').val() || [];
                const newValues = currentValues.filter(id => id !== categoryId.toString());
                $('#wbe-categories').val(newValues).trigger('change.select2');
            }

            // Cacher le wrapper si plus de catégories
            if (Object.keys(this.selectedCategories).length === 0) {
                $('#wbe-selected-categories-wrapper').hide();
            }

            this.updateTotalProducts();
        },

        /**
         * Charger les produits d'une catégorie via AJAX
         */
        loadCategoryProducts: function(categoryId) {
            const self = this;

            $.ajax({
                url: wbeAdmin.ajaxurl,
                method: 'POST',
                data: {
                    action: 'wbe_get_category_products',
                    nonce: wbeAdmin.nonce,
                    category_id: categoryId
                },
                success: function(response) {
                    if (response.success) {
                        self.categoryProducts[categoryId] = response.data.products;
                        self.renderProductList(categoryId, response.data.products);
                    }
                },
                error: function() {
                    self.showProductLoadError(categoryId);
                }
            });
        },

        /**
         * Afficher la liste des produits
         */
        renderProductList: function(categoryId, products) {
            const $container = $('.wbe-product-checklist[data-category-id="' + categoryId + '"]');
            
            if (products.length === 0) {
                $container.html('<p class="wbe-no-products">Aucun produit dans cette catégorie</p>');
                return;
            }

            let html = '<div class="wbe-select-all-wrapper">';
            html += '<label class="wbe-select-all">';
            html += '<input type="checkbox" class="wbe-select-all-products" data-category-id="' + categoryId + '" />';
            html += '<strong>Tout sélectionner / Tout désélectionner</strong>';
            html += '</label>';
            html += '</div>';
            html += '<ul class="wbe-products-list">';

            products.forEach(function(product) {
                html += '<li>';
                html += '<label>';
                html += '<input type="checkbox" class="wbe-product-checkbox" ';
                html += 'data-category-id="' + categoryId + '" ';
                html += 'data-product-id="' + product.id + '" />';
                html += '<span class="product-name">' + product.name + '</span>';
                if (product.sku) {
                    html += '<span class="product-sku">(SKU: ' + product.sku + ')</span>';
                }
                if (product.price) {
                    html += '<span class="product-price">' + product.price + '</span>';
                }
                html += '</label>';
                html += '</li>';
            });

            html += '</ul>';
            $container.html(html);

            // Événement "Tout sélectionner"
            this.bindSelectAllEvent(categoryId);
        },

        /**
         * Liaison de l'événement "Tout sélectionner"
         */
        bindSelectAllEvent: function(categoryId) {
            const self = this;
            
            $(document).on('change', '.wbe-select-all-products[data-category-id="' + categoryId + '"]', function() {
                const isChecked = $(this).prop('checked');
                $('.wbe-product-checkbox[data-category-id="' + categoryId + '"]').prop('checked', isChecked);
                self.updateSelectedCount(categoryId);
                self.updateTotalProducts();
            });
        },

        /**
         * Afficher erreur de chargement
         */
        showProductLoadError: function(categoryId) {
            const $container = $('.wbe-product-checklist[data-category-id="' + categoryId + '"]');
            $container.html('<p class="wbe-error">Erreur lors du chargement des produits</p>');
        },

        /**
         * Basculer l'affichage du sélecteur de produits
         */
        toggleProductSelector: function(categoryId, mode) {
            const $item = $('.wbe-category-item[data-category-id="' + categoryId + '"]');
            const $selector = $item.find('.wbe-product-selector');

            if (mode === 'select') {
                $selector.slideDown(300);
            } else {
                $selector.slideUp(300);
                // Décocher tous les produits
                $selector.find('.wbe-product-checkbox').prop('checked', false);
            }

            this.selectedCategories[categoryId].mode = mode;
            this.updateTotalProducts();
        },

        /**
         * Filtrer les produits affichés
         */
        filterCategoryProducts: function(categoryId, searchTerm) {
            const $list = $('.wbe-product-checklist[data-category-id="' + categoryId + '"] ul li');

            if (!searchTerm) {
                $list.show();
                return;
            }

            $list.each(function() {
                const productName = $(this).find('.product-name').text().toLowerCase();
                const productSku = $(this).find('.product-sku').text().toLowerCase();

                if (productName.includes(searchTerm) || productSku.includes(searchTerm)) {
                    $(this).show();
                } else {
                    $(this).hide();
                }
            });
        },

        /**
         * Mettre à jour le compteur de produits sélectionnés
         */
        updateSelectedCount: function(categoryId) {
            const count = $('.wbe-product-checkbox[data-category-id="' + categoryId + '"]:checked').length;
            const $countDisplay = $('.wbe-category-item[data-category-id="' + categoryId + '"] .wbe-selected-count .count');
            $countDisplay.text(count);

            // Mettre à jour l'état de "tout sélectionner"
            const total = $('.wbe-product-checkbox[data-category-id="' + categoryId + '"]').length;
            const $selectAll = $('.wbe-select-all-products[data-category-id="' + categoryId + '"]');
            $selectAll.prop('checked', count === total && total > 0);
        },

        /**
         * Calculer et afficher le total de produits à modifier
         */
        updateTotalProducts: function() {
            const self = this;
            let total = 0;

            Object.keys(this.selectedCategories).forEach(function(categoryId) {
                const category = self.selectedCategories[categoryId];

                if (category.mode === 'all') {
                    total += category.count;
                } else {
                    const selectedCount = $('.wbe-product-checkbox[data-category-id="' + categoryId + '"]:checked').length;
                    total += selectedCount;
                }
            });

            $('#wbe-total-products').text(total);

            // Activer/Désactiver le bouton suivant
            $('.wbe-step-1 .wbe-next-step').prop('disabled', total === 0);
        },

        /**
         * Récupérer les IDs de produits sélectionnés
         */
        getSelectedProductIds: function() {
            const self = this;
            let productIds = [];

            Object.keys(this.selectedCategories).forEach(function(categoryId) {
                const category = self.selectedCategories[categoryId];

                if (category.mode === 'all') {
                    // Tous les produits de la catégorie
                    if (self.categoryProducts[categoryId]) {
                        const ids = self.categoryProducts[categoryId].map(p => p.id);
                        productIds = productIds.concat(ids);
                        console.log(productIds);
                        
                    }
                } else {
                    // Produits sélectionnés manuellement
                    $('.wbe-product-checkbox[data-category-id="' + categoryId + '"]:checked').each(function() {
                        productIds.push(parseInt($(this).data('product-id')));
                    });
                }
            });

            return [...new Set(productIds)]; // Retirer les doublons
        }
    };

    // Initialisation au chargement
    $(document).ready(function() {
        WBE_CategorySelection.init();
    });

    // Exposer globalement
    window.WBE_CategorySelection = WBE_CategorySelection;

})(jQuery);