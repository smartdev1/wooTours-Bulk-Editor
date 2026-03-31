(function ($) {
  "use strict";

  const WBE_App = {
    state: {
      currentStep: 1,
      selectionMode: "categories",
      selectedCategory: null,
      categoryProductsSelection: null,
      selectedManualProducts: [],
      allProducts: [],
      excludedDates: [],
      specificDates: [],
      changes: {},
      loading: false,
      selectedCategories: [],
      selectedProducts: [],
    },

    init: function () {
      this.initDatepickers();
      this.bindEvents();
      this.initSelect2();
      this.loadTotalProductsCount();
      this.initializeState();
    },

    /**
     * Initialisation de l'état
     */
    initializeState: function () {
      // S'assurer que les tableaux sont initialisés
      this.state.excludedDates = [];
      this.state.specificDates = [];
      this.state.selectedManualProducts = [];
      this.state.allProducts = [];
      this.state.selectedCategories = [];
      this.state.selectedProducts = [];
    },

    /**
     * Initialisation des datepickers
     */
    initDatepickers: function () {
      const self = this;

      // Datepickers pour les champs de date
      $(".wbe-datepicker").datepicker({
        dateFormat: "dd/mm/yy",
        minDate: 0,
        changeMonth: true,
        changeYear: true,
        showButtonPanel: true,
        monthNames: [
          "Janvier",
          "Février",
          "Mars",
          "Avril",
          "Mai",
          "Juin",
          "Juillet",
          "Août",
          "Septembre",
          "Octobre",
          "Novembre",
          "Décembre",
        ],
        monthNamesShort: [
          "Jan",
          "Fév",
          "Mar",
          "Avr",
          "Mai",
          "Jun",
          "Jul",
          "Aoû",
          "Sep",
          "Oct",
          "Nov",
          "Déc",
        ],
        dayNames: [
          "Dimanche",
          "Lundi",
          "Mardi",
          "Mercredi",
          "Jeudi",
          "Vendredi",
          "Samedi",
        ],
        dayNamesShort: ["Dim", "Lun", "Mar", "Mer", "Jeu", "Ven", "Sam"],
        dayNamesMin: ["Di", "Lu", "Ma", "Me", "Je", "Ve", "Sa"],
        firstDay: 1, // Lundi
      });

      // Calendrier pour exclure des dates
      $("#wbe-exclude-calendar").datepicker({
        dateFormat: "yy-mm-dd",
        minDate: 0,
        changeMonth: true,
        changeYear: true,
        numberOfMonths: 1,
        onSelect: function (dateText) {
          self.toggleExcludedDate(dateText);
        },
        beforeShowDay: function (date) {
          const dateStr = $.datepicker.formatDate("yy-mm-dd", date);
          const isExcluded = self.state.excludedDates.indexOf(dateStr) !== -1;

          return [true, isExcluded ? "wbe-excluded-date" : ""];
        },
      });

      // Calendrier pour dates spécifiques (nouveau comportement)
      $("#wbe-specific-calendar").datepicker({
        dateFormat: "yy-mm-dd",
        minDate: 0,
        changeMonth: true,
        changeYear: true,
        numberOfMonths: 1,
        onSelect: function (dateText) {
          self.toggleSpecificDate(dateText);
        },
        beforeShowDay: function (date) {
          const dateStr = $.datepicker.formatDate("yy-mm-dd", date);
          const isSpecific = self.state.specificDates.indexOf(dateStr) !== -1;

          return [true, isSpecific ? "wbe-specific-date" : ""];
        },
      });
    },

    /**
     * Initialise Select2 pour la catégorie unique
     */
    initSelect2: function () {
      const self = this;

      // Sélecteur de catégorie unique
      $("#wbe-category")
        .select2({
          placeholder: "Choisir une catégorie...",
          allowClear: true,
          width: "100%",
          language: {
            noResults: function () {
              return "Aucune catégorie trouvée";
            },
          },
        })
        .on("change", function () {
          const categoryId = $(this).val();
          self.handleCategoryChange(categoryId);
        });
    },

    /**
     * Gère le changement de catégorie
     */
    handleCategoryChange: function (categoryId) {
      console.log("Changement de catégorie :", categoryId);

      const $categorySelection = $("#wbe-category-selection");
      const $productsSelection = $("#wbe-products-in-category");
      const $summary = $("#wbe-category-summary");

      if (!categoryId) {
        // Aucune catégorie sélectionnée
        $productsSelection.hide();
        $summary.hide();
        $categorySelection.show();

        this.state.selectedCategory = null;
        this.state.categoryProductsSelection = null;
        this.state.selectedManualProducts = [];

        // Mettre à jour les tableaux de compatibilité
        this.state.selectedCategories = [];
        this.state.selectedProducts = [];

        this.updateNextButton();
        this.updateProductCount();
        return;
      }

      // Mettre à jour l'état
      this.state.selectedCategory = parseInt(categoryId);

      // Mettre à jour les tableaux de compatibilité
      this.state.selectedCategories = [categoryId];

      // Récupérer les informations de la catégorie
      const $option = $('#wbe-category option[value="' + categoryId + '"]');
      const optionText = $option.text();
      const categoryName = optionText.split("(")[0].trim();
      const match = optionText.match(/\((\d+) produits\)/);
      const productCount = match ? match[1] : "0";

      console.log(
        "Catégorie sélectionnée :",
        categoryName,
        "Produits :",
        productCount,
      );

      // Mettre à jour l'interface
      $("#wbe-category-product-count").text(productCount);
      $("#wbe-selected-category-name").text(categoryName);

      // Afficher l'étape de sélection des produits
      $categorySelection.hide();
      $productsSelection.show();
      $summary.hide();

      // Réinitialiser les sélections
      $('input[name="category_products_selection"]').prop("checked", false);
      $("#wbe-manual-selection").hide();

      this.state.categoryProductsSelection = null;
      this.state.selectedManualProducts = [];
      this.state.selectedProducts = [];

      this.updateNextButton();
      this.updateProductCount();
    },

    /**
     * Charge les produits de la catégorie sélectionnée
     */
    loadCategoryProducts: function () {
      if (!this.state.selectedCategory) return;

      const self = this;
      const $results = $("#wbe-category-product-results");
      const $spinner = $("#wbe-category-product-search").siblings(".spinner");

      $spinner.addClass("is-active");
      $results.html('<p class="wbe-loading">Chargement des produits...</p>');

      $.ajax({
        url: WBE_DATA.ajax_url,
        type: "POST",
        data: {
          action: "wbe_get_category_products",
          nonce: WBE_DATA.nonce,
          category_id: this.state.selectedCategory,
        },
        success: function (response) {
          $spinner.removeClass("is-active");

          if (response.success && response.data.products) {
            self.renderCategoryProducts(response.data.products);
          } else {
            $results.html('<p class="wbe-no-results">Aucun produit trouvé</p>');
          }
        },
        error: function () {
          $spinner.removeClass("is-active");
          $results.html('<p class="wbe-error">Erreur lors du chargement</p>');
        },
      });
    },

    /**
     * Affiche les produits de la catégorie
     */
    renderCategoryProducts: function (products) {
      const $container = $("#wbe-category-product-results");
      const self = this;

      if (!products || products.length === 0) {
        $container.html(
          '<p class="wbe-no-results">Aucun produit dans cette catégorie</p>',
        );
        return;
      }

      let html = '<ul class="wbe-product-items">';

      products.forEach(function (product) {
        const isSelected = self.state.selectedManualProducts.some(
          (p) => p.id === product.id,
        );

        html += `
          <li class="wbe-product-item ${isSelected ? "selected" : ""}" data-id="${product.id}">
            <label>
              <input type="checkbox" value="${product.id}" ${isSelected ? "checked" : ""}>
              <span class="product-title">${product.title}</span>
              <span class="product-id">#${product.id}</span>
            </label>
          </li>
        `;
      });

      html += "</ul>";
      $container.html(html);

      // Événements sur les checkboxes
      $container.find('input[type="checkbox"]').on("change", function () {
        const productId = parseInt($(this).val());
        const productTitle = $(this)
          .closest("label")
          .find(".product-title")
          .text();

        if ($(this).is(":checked")) {
          self.addManualProduct(productId, productTitle);
        } else {
          self.removeManualProduct(productId);
        }
      });
    },

    /**
     * Recherche dans la catégorie
     */
    searchInCategory: function (query) {
      if (!this.state.selectedCategory || query.length < 2) {
        this.loadCategoryProducts();
        return;
      }

      const self = this;
      const $results = $("#wbe-category-product-results");
      const $spinner = $("#wbe-category-product-search").siblings(".spinner");

      $spinner.addClass("is-active");

      $.ajax({
        url: WBE_DATA.ajax_url,
        type: "POST",
        data: {
          action: "wbe_search_in_category",
          nonce: WBE_DATA.nonce,
          category_id: this.state.selectedCategory,
          query: query,
        },
        success: function (response) {
          $spinner.removeClass("is-active");

          if (response.success && response.data.products) {
            self.renderCategoryProducts(response.data.products);
          } else {
            $results.html('<p class="wbe-no-results">Aucun produit trouvé</p>');
          }
        },
        error: function () {
          $spinner.removeClass("is-active");
          $results.html('<p class="wbe-error">Erreur lors de la recherche</p>');
        },
      });
    },

    /**
     * Ajoute un produit à la sélection manuelle dans la catégorie
     */
    addManualProduct: function (productId, productTitle) {
      const exists = this.state.selectedManualProducts.some(
        (p) => p.id === productId,
      );

      if (!exists) {
        this.state.selectedManualProducts.push({
          id: productId,
          title: productTitle,
        });

        // Mettre à jour les tableaux de compatibilité
        this.state.selectedProducts = this.state.selectedManualProducts;

        this.updateManualSelectionCount();
        this.updateCategorySummary();
        this.updateNextButton();
        this.updateProductCount();
      }
    },

    /**
     * Retire un produit de la sélection manuelle dans la catégorie
     */
    removeManualProduct: function (productId) {
      this.state.selectedManualProducts =
        this.state.selectedManualProducts.filter((p) => p.id !== productId);

      // Mettre à jour les tableaux de compatibilité
      this.state.selectedProducts = this.state.selectedManualProducts;

      this.updateManualSelectionCount();
      this.updateCategorySummary();
      this.updateNextButton();
      this.updateProductCount();
    },

    /**
     * Met à jour le compteur de sélection manuelle
     */
    updateManualSelectionCount: function () {
      $("#wbe-manual-selected-count").text(
        this.state.selectedManualProducts.length,
      );
    },

    /**
     * Met à jour le résumé de la catégorie
     */
    updateCategorySummary: function () {
      let productCount = 0;
      let summaryText = "";

      if (this.state.categoryProductsSelection === "all") {
        const count = parseInt($("#wbe-category-product-count").text()) || 0;
        productCount = count;
        summaryText = `Tous les produits (${count})`;
      } else if (this.state.categoryProductsSelection === "manual") {
        productCount = this.state.selectedManualProducts.length;
        summaryText = `${productCount} produit(s) sélectionné(s)`;
      }

      $("#wbe-selected-product-count").text(productCount);
      $("#wbe-category-summary").show();
    },

    /**
     * Charge le nombre total de produits (mode "tout le catalogue")
     */
    loadTotalProductsCount: function () {
      $.ajax({
        url: WBE_DATA.ajax_url,
        type: "POST",
        data: {
          action: "wbe_get_total_products",
          nonce: WBE_DATA.nonce,
        },
        success: function (response) {
          if (response.success) {
            $("#wbe-total-catalog-count").text(response.data.total);
          }
        },
      });
    },

    bindEvents: function () {
      const self = this;

      // Mode de sélection principal
      $('input[name="selection_mode"]').on("change", function () {
        const mode = $(this).val();
        self.state.selectionMode = mode;
        self.switchSelectionMode(mode);
        self.updateNextButton();
        self.updateProductCount();
      });

      // Choix dans la catégorie (tous vs manuel)
      $('input[name="category_products_selection"]').on("change", function () {
        const selection = $(this).val();
        self.state.categoryProductsSelection = selection;

        // Afficher/masquer l'interface de sélection manuelle
        if (selection === "manual") {
          $("#wbe-manual-selection").show();
          self.loadCategoryProducts();
          self.updateManualSelectionCount();
        } else {
          $("#wbe-manual-selection").hide();
          self.state.selectedManualProducts = [];
          self.state.selectedProducts = [];
        }

        // Afficher le résumé
        self.updateCategorySummary();
        self.updateNextButton();
        self.updateProductCount();
      });

      // Bouton "Changer de catégorie"
      $("#wbe-change-category").on("click", function () {
        $("#wbe-products-in-category").hide();
        $("#wbe-category-summary").hide();
        $("#wbe-category-selection").show();
        $("#wbe-category").val(null).trigger("change");

        self.state.selectedCategory = null;
        self.state.categoryProductsSelection = null;
        self.state.selectedManualProducts = [];
        self.state.selectedCategories = [];
        self.state.selectedProducts = [];

        self.updateNextButton();
        self.updateProductCount();
      });

      // Boutons de sélection/désélection dans la catégorie
      $("#wbe-select-all").on("click", function () {
        self.selectAllCategoryProducts();
      });

      $("#wbe-deselect-all").on("click", function () {
        self.deselectAllCategoryProducts();
      });

      // Recherche dans la catégorie
      let searchInCategoryTimeout;
      $("#wbe-category-product-search").on("input", function () {
        clearTimeout(searchInCategoryTimeout);
        const query = $(this).val();

        searchInCategoryTimeout = setTimeout(function () {
          self.searchInCategory(query);
        }, 500);
      });

      // Recherche de produits (mode sélection libre)
      let searchProductsTimeout;
      $("#wbe-product-search").on("input", function () {
        clearTimeout(searchProductsTimeout);
        const query = $(this).val();

        if (query.length < 2) {
          $("#wbe-product-results").empty();
          return;
        }

        searchProductsTimeout = setTimeout(function () {
          self.searchProducts(query);
        }, 500);
      });

      // Sélection/désélection tous (mode libre)
      $("#wbe-select-all-manual").on("click", function () {
        self.selectAllProducts();
      });

      $("#wbe-deselect-all-manual").on("click", function () {
        self.deselectAllProducts();
      });

      // Navigation entre étapes
      $(".wbe-next-step").on("click", function () {
        self.nextStep();
      });

      $(".wbe-prev-step").on("click", function () {
        self.prevStep();
      });

      // Champs de l'étape 2
      $('input[name="available_days[]"]').on("change", function () {
        self.validateStep2();
      });

      $("#wbe-start-date, #wbe-end-date").on("change", function () {
        self.validateStep2();
      });

      // Application et réinitialisation
      $("#wbe-apply-changes").on("click", function () {
        self.applyChanges();
      });

      $("#wbe-reset-all").on("click", function () {
        if (
          confirm(
            "Êtes-vous sûr de vouloir RÉINITIALISER toutes les disponibilités de ces produits ?\n\nCette action est irréversible.",
          )
        ) {
          self.resetAll();
        }
      });

      $("#wbe-restart").on("click", function () {
        location.reload();
      });
    },

    /**
     * Sélectionne tous les produits de la catégorie
     */
    selectAllCategoryProducts: function () {
      const $checkboxes = $(
        "#wbe-category-product-results input[type='checkbox']",
      );
      $checkboxes.prop("checked", true).trigger("change");
    },

    /**
     * Désélectionne tous les produits de la catégorie
     */
    deselectAllCategoryProducts: function () {
      const $checkboxes = $(
        "#wbe-category-product-results input[type='checkbox']",
      );
      $checkboxes.prop("checked", false).trigger("change");
    },

    /**
     * Sélectionne tous les produits (mode libre)
     */
    selectAllProducts: function () {
      const $checkboxes = $("#wbe-product-results input[type='checkbox']");
      $checkboxes.prop("checked", true).trigger("change");
    },

    /**
     * Désélectionne tous les produits (mode libre)
     */
    deselectAllProducts: function () {
      const $checkboxes = $("#wbe-product-results input[type='checkbox']");
      $checkboxes.prop("checked", false).trigger("change");
    },

    /**
     * Change le mode de sélection
     */
    switchSelectionMode: function (mode) {
      console.log("Changement de mode :", mode);

      // Masquer tous les panneaux
      $(".wbe-selection-panel").hide();

      // Afficher le panneau correspondant
      $("#panel-" + mode).show();

      // Réinitialiser les états
      if (mode !== "categories") {
        $("#wbe-category").val(null).trigger("change");
        this.state.selectedCategory = null;
        this.state.categoryProductsSelection = null;
        this.state.selectedManualProducts = [];
        this.state.selectedCategories = [];
      }

      if (mode !== "manual") {
        this.state.allProducts = [];
        this.state.selectedProducts = [];
        this.renderSelectedProducts();
      }

      if (mode !== "all") {
        // Mode non "tout le catalogue"
      }

      this.updateNextButton();
      this.updateProductCount();
    },

    /**
     * Recherche de produits (mode libre)
     */
    searchProducts: function (query) {
      const self = this;
      const $results = $("#wbe-product-results");
      const $spinner = $("#wbe-product-search").siblings(".spinner");

      $spinner.addClass("is-active");

      $.ajax({
        url: WBE_DATA.ajax_url,
        type: "POST",
        data: {
          action: "wbe_search_products",
          nonce: WBE_DATA.nonce,
          query: query,
        },
        success: function (response) {
          $spinner.removeClass("is-active");

          if (response.success && response.data.products) {
            self.renderProductResults(response.data.products);
          } else {
            $results.html('<p class="wbe-no-results">Aucun produit trouvé</p>');
          }
        },
        error: function () {
          $spinner.removeClass("is-active");
          $results.html('<p class="wbe-error">Erreur lors de la recherche</p>');
        },
      });
    },

    /**
     * Affiche les résultats de recherche (mode libre)
     */
    renderProductResults: function (products) {
      const self = this;
      const $results = $("#wbe-product-results");

      if (products.length === 0) {
        $results.html('<p class="wbe-no-results">Aucun produit trouvé</p>');
        return;
      }

      let html = '<ul class="wbe-product-items">';

      products.forEach(function (product) {
        const isSelected = self.state.allProducts.some(
          (p) => p.id === product.id,
        );

        html += `
          <li class="wbe-product-item ${isSelected ? "selected" : ""}" data-id="${product.id}">
            <label>
              <input type="checkbox" value="${product.id}" ${isSelected ? "checked" : ""} />
              <span class="product-title">${product.title}</span>
              <span class="product-id">#${product.id}</span>
            </label>
          </li>
        `;
      });

      html += "</ul>";
      $results.html(html);

      // Événement sur les checkbox
      $results.find('input[type="checkbox"]').on("change", function () {
        const productId = parseInt($(this).val());
        const productTitle = $(this)
          .closest("label")
          .find(".product-title")
          .text();

        if ($(this).is(":checked")) {
          self.addProduct(productId, productTitle);
        } else {
          self.removeProduct(productId);
        }
      });
    },

    /**
     * Ajoute un produit (mode libre)
     */
    addProduct: function (productId, productTitle) {
      const exists = this.state.allProducts.some((p) => p.id === productId);

      if (!exists) {
        this.state.allProducts.push({
          id: productId,
          title: productTitle,
        });

        // Mettre à jour les tableaux de compatibilité
        this.state.selectedProducts = this.state.allProducts;

        this.renderSelectedProducts();
        this.updateProductCount();
      }
    },

    /**
     * Retire un produit (mode libre)
     */
    removeProduct: function (productId) {
      this.state.allProducts = this.state.allProducts.filter(
        (p) => p.id !== productId,
      );

      // Mettre à jour les tableaux de compatibilité
      this.state.selectedProducts = this.state.allProducts;

      this.renderSelectedProducts();
      this.updateProductCount();
    },

    /**
     * Affiche les produits sélectionnés
     */
    renderSelectedProducts: function () {
      const $container = $("#wbe-selected-products");
      const $noSelection = $container.find(".wbe-no-selection");

      const products =
        this.state.selectionMode === "manual"
          ? this.state.allProducts
          : this.state.selectedProducts;

      if (products.length === 0) {
        $noSelection.show();
        $container.find(".wbe-tag").remove();
        return;
      }

      $noSelection.hide();
      $container.find(".wbe-tag").remove();

      const self = this;

      products.forEach(function (product) {
        const $tag = $(`
          <span class="wbe-tag" data-id="${product.id}">
            <span class="tag-icon"><span class="dashicons dashicons-admin-post"></span></span>
            <span class="tag-text">${product.title}</span>
            <button type="button" class="tag-remove" data-id="${product.id}">
              <span class="dashicons dashicons-no-alt"></span>
            </button>
          </span>
        `);

        $tag.find(".tag-remove").on("click", function () {
          const id = parseInt($(this).data("id"));

          if (self.state.selectionMode === "manual") {
            self.removeProduct(id);
          } else if (
            self.state.selectionMode === "categories" &&
            self.state.categoryProductsSelection === "manual"
          ) {
            self.removeManualProduct(id);
          }
        });

        $container.append($tag);
      });
    },

    /**
     * Met à jour le compteur de produits
     */
    updateProductCount: function () {
      let total = 0;
      let displayText = "0";

      if (this.state.selectionMode === "categories") {
        if (this.state.categoryProductsSelection === "all") {
          const count = parseInt($("#wbe-category-product-count").text()) || 0;
          total = count;
          displayText = count;
        } else if (this.state.categoryProductsSelection === "manual") {
          total = this.state.selectedManualProducts.length;
          displayText = total;
        }
      } else if (this.state.selectionMode === "manual") {
        total = this.state.allProducts.length;
        displayText = total;
      } else if (this.state.selectionMode === "all") {
        displayText = "Tous";
        total = parseInt($("#wbe-total-catalog-count").text()) || 0;
      }

      $("#wbe-total-products").text(displayText);

      // Activer/désactiver le bouton suivant
      const hasSelection = total > 0 || this.state.selectionMode === "all";
      $(".wbe-step-1 .wbe-next-step").prop("disabled", !hasSelection);
    },

    /**
     * Met à jour l'état du bouton "Suivant"
     */
    updateNextButton: function () {
      let isValid = false;

      if (this.state.selectionMode === "categories") {
        isValid =
          this.state.selectedCategory !== null &&
          this.state.categoryProductsSelection !== null;
      } else if (this.state.selectionMode === "manual") {
        isValid = this.state.allProducts.length > 0;
      } else if (this.state.selectionMode === "all") {
        isValid = true;
      }

      $(".wbe-step-1 .wbe-next-step").prop("disabled", !isValid);
    },

    // ============================================
    // GESTION DES DATES
    // ============================================

    /**
     * Gestion des dates exclues
     */
    toggleExcludedDate: function (dateStr) {
      const index = this.state.excludedDates.indexOf(dateStr);

      if (index === -1) {
        this.state.excludedDates.push(dateStr);
      } else {
        this.state.excludedDates.splice(index, 1);
      }

      this.renderExcludedDates();
      this.validateStep2();
      $("#wbe-exclude-calendar").datepicker("refresh");
    },

    /**
     * Gestion des dates spécifiques (NOUVEAU)
     */
    toggleSpecificDate: function (dateStr) {
      const index = this.state.specificDates.indexOf(dateStr);

      if (index === -1) {
        this.state.specificDates.push(dateStr);
      } else {
        this.state.specificDates.splice(index, 1);
      }

      this.renderSpecificDates();
      this.validateStep2();
      $("#wbe-specific-calendar").datepicker("refresh");
    },

    /**
     * Affiche la liste des dates exclues
     */
    renderExcludedDates: function () {
      const $list = $("#wbe-excluded-dates");
      const $noDate = $(".wbe-no-excluded-dates");

      if (this.state.excludedDates.length === 0) {
        $list.empty();
        $noDate.show();
        return;
      }

      $noDate.hide();

      const sorted = this.state.excludedDates.sort();
      let html = "";

      const self = this;

      sorted.forEach(function (date) {
        const displayDate = self.formatDateForDisplay(date);

        html += `
            <li>
                <span class="date">${displayDate}</span>
                <button type="button" class="wbe-remove-date" data-date="${date}">
                    <span class="dashicons dashicons-no-alt"></span>
                </button>
            </li>
        `;
      });

      $list.html(html);

      $list.find(".wbe-remove-date").on("click", function () {
        const date = $(this).data("date");
        self.toggleExcludedDate(date);
      });
    },

    /**
     * Affiche la liste des dates spécifiques (NOUVEAU)
     */
    renderSpecificDates: function () {
      const $list = $("#wbe-specific-dates");
      const $noDate = $(".wbe-no-specific-dates");

      if (this.state.specificDates.length === 0) {
        $list.empty();
        $noDate.show();
        return;
      }

      $noDate.hide();

      const sorted = this.state.specificDates.sort();
      let html = "";

      const self = this;

      sorted.forEach(function (date) {
        const displayDate = self.formatDateForDisplay(date);

        html += `
            <li>
                <span class="date">${displayDate}</span>
                <button type="button" class="wbe-remove-specific-date" data-date="${date}">
                    <span class="dashicons dashicons-no-alt"></span>
                </button>
            </li>
        `;
      });

      $list.html(html);

      $list.find(".wbe-remove-specific-date").on("click", function () {
        const date = $(this).data("date");
        self.toggleSpecificDate(date);
      });
    },

    /**
     * Convertit dd/mm/yyyy en yyyy-mm-dd
     */
    convertDate: function (dateStr) {
      if (!dateStr) return "";

      if (/^\d{4}-\d{2}-\d{2}$/.test(dateStr)) {
        return dateStr;
      }

      const parts = dateStr.split("/");
      if (parts.length === 3) {
        return `${parts[2]}-${parts[1]}-${parts[0]}`;
      }

      return dateStr;
    },

    /**
     * Convertit yyyy-mm-dd en dd/mm/yyyy pour affichage
     */
    formatDateForDisplay: function (dateStr) {
      if (!dateStr) return "";

      if (/^\d{2}\/\d{2}\/\d{4}$/.test(dateStr)) {
        return dateStr;
      }

      const parts = dateStr.split("-");
      if (parts.length === 3) {
        return `${parts[2]}/${parts[1]}/${parts[0]}`;
      }

      return dateStr;
    },

    /**
     * Valide l'étape 2
     */
    validateStep2: function () {
      const hasStartDate = $("#wbe-start-date").val() !== "";
      const hasEndDate = $("#wbe-end-date").val() !== "";
      const hasDays = $('input[name="available_days[]"]:checked').length > 0;
      const hasExcluded = this.state.excludedDates.length > 0;
      const hasSpecific = this.state.specificDates.length > 0;

      const hasChanges =
        hasStartDate || hasEndDate || hasDays || hasExcluded || hasSpecific;
      $(".wbe-step-2 .wbe-next-step").prop("disabled", !hasChanges);
    },

    /**
     * Passe à l'étape suivante
     */
    nextStep: function () {
      const currentStep = this.state.currentStep;

      if (currentStep === 2) {
        this.preparePreview();
      }

      $(".wbe-step").removeClass("active");
      this.state.currentStep++;
      $(`.wbe-step-${this.state.currentStep}`).addClass("active");

      $("html, body").animate({ scrollTop: 0 }, 300);
    },

    /**
     * Retourne à l'étape précédente
     */
    prevStep: function () {
      $(".wbe-step").removeClass("active");
      this.state.currentStep--;
      $(`.wbe-step-${this.state.currentStep}`).addClass("active");

      $("html, body").animate({ scrollTop: 0 }, 300);
    },

    /**
     * Prépare la prévisualisation
     */
    preparePreview: function () {
      this.state.changes = this.collectChanges();

      let productCount = 0;
      if (this.state.selectionMode === "categories") {
        if (this.state.categoryProductsSelection === "all") {
          productCount = parseInt($("#wbe-category-product-count").text()) || 0;
        } else {
          productCount = this.state.selectedManualProducts.length;
        }
      } else if (this.state.selectionMode === "manual") {
        productCount = this.state.allProducts.length;
      } else if (this.state.selectionMode === "all") {
        productCount = parseInt($("#wbe-total-catalog-count").text()) || 0;
      }

      $("#wbe-preview-products").html(
        `<p><strong>${productCount}</strong> produit(s) seront modifiés</p>`,
      );
      $("#wbe-confirm-count").text(productCount);

      let changesHtml = "<ul>";
      if (this.state.changes.start_date) {
        changesHtml += `<li><strong>Date de début :</strong> ${this.state.changes.start_date}</li>`;
      }
      if (this.state.changes.end_date) {
        changesHtml += `<li><strong>Date de fin :</strong> ${this.state.changes.end_date}</li>`;
      }
      if (this.state.changes.available_days) {
        changesHtml += `<li><strong>Jours disponibles :</strong> ${this.state.changes.available_days.join(", ")}</li>`;
      }
      if (this.state.changes.unavailable_dates) {
        changesHtml += `<li><strong>Dates exclues :</strong> ${this.state.changes.unavailable_dates.length} date(s)</li>`;
      }
      if (this.state.changes.specific_dates) {
        changesHtml += `<li><strong>Dates spécifiques :</strong> ${this.state.changes.specific_dates.length} date(s)</li>`;
      }
      changesHtml += "</ul>";
      $("#wbe-preview-changes").html(changesHtml);
    },

    /**
     * Collecte les modifications du formulaire
     */
    collectChanges: function () {
      const changes = {};

      const startDate = $("#wbe-start-date").val();
      const endDate = $("#wbe-end-date").val();

      if (startDate) changes.start_date = this.convertDate(startDate);
      if (endDate) changes.end_date = this.convertDate(endDate);

      const days = [];
      $('input[name="available_days[]"]:checked').each(function () {
        days.push($(this).val());
      });
      if (days.length > 0) changes.available_days = days;

      if (this.state.excludedDates.length > 0) {
        changes.unavailable_dates = this.state.excludedDates;
      }

      if (this.state.specificDates.length > 0) {
        changes.specific_dates = this.state.specificDates;
      }

      return changes;
    },

    /**
     * Applique les modifications
     */
    applyChanges: function () {
      const self = this;

      this.showLoader("Traitement en cours...");

      // Préparer les données selon le mode de sélection
      const ajaxData = {
        action: "wbe_bulk_update",
        nonce: WBE_DATA.nonce,
        changes: this.state.changes,
        action_type: "update",
        selection_mode: this.state.selectionMode,
      };

      // Ajouter les données spécifiques au mode
      if (this.state.selectionMode === "categories") {
        ajaxData.category_id = this.state.selectedCategory;
        ajaxData.category_selection = this.state.categoryProductsSelection;

        if (this.state.categoryProductsSelection === "manual") {
          // Envoyer les IDs des produits sélectionnés
          const productIds = this.state.selectedManualProducts.map((p) => p.id);
          ajaxData.products = productIds;
        }
      } else if (this.state.selectionMode === "manual") {
        // Envoyer tous les IDs des produits sélectionnés manuellement
        const productIds = this.state.allProducts.map((p) => p.id);
        ajaxData.products = productIds;
      }
      // Mode "all" n'a pas besoin de données supplémentaires

      console.log("Données envoyées :", ajaxData);

      $.ajax({
        url: WBE_DATA.ajax_url,
        type: "POST",
        data: ajaxData,
        success: function (response) {
          console.log("Réponse :", response);
          self.hideLoader();
          self.showResult(response);
        },
        error: function (xhr, status, error) {
          console.error("Erreur :", error, xhr.responseText);
          self.hideLoader();
          alert("Une erreur est survenue lors du traitement.");
        },
      });
    },

    /**
     * Réinitialise tout
     */
    resetAll: function () {
      const self = this;

      this.showLoader("Réinitialisation en cours...");

      const ajaxData = {
        action: "wbe_bulk_update",
        nonce: WBE_DATA.nonce,
        action_type: "reset",
        selection_mode: this.state.selectionMode,
      };

      if (this.state.selectionMode === "categories") {
        ajaxData.category_id = this.state.selectedCategory;
        ajaxData.category_selection = this.state.categoryProductsSelection;

        if (this.state.categoryProductsSelection === "manual") {
          const productIds = this.state.selectedManualProducts.map((p) => p.id);
          ajaxData.products = productIds;
        }
      } else if (this.state.selectionMode === "manual") {
        const productIds = this.state.allProducts.map((p) => p.id);
        ajaxData.products = productIds;
      }

      $.ajax({
        url: WBE_DATA.ajax_url,
        type: "POST",
        data: ajaxData,
        success: function (response) {
          self.hideLoader();
          self.showResult(response);
        },
        error: function (xhr, status, error) {
          console.error("Erreur :", error);
          self.hideLoader();
          alert("Une erreur est survenue lors de la réinitialisation.");
        },
      });
    },

    

    /**
     * Affiche le résultat
     */
    showResult: function (response) {
      $(".wbe-step").removeClass("active").hide();
      $(".wbe-step-result").show();

      let html = "";

      if (response.success) {
        const report = response.data.report;

        html = `
            <div class="notice notice-success">
                <p><strong>✓ Traitement terminé avec succès</strong></p>
                <p>${response.data.message}</p>
            </div>
            <div class="wbe-report">
                <table class="widefat">
                    <tbody>
                        <tr>
                            <th>Produits traités :</th>
                            <td>${report.total}</td>
                        </tr>
                        <tr>
                            <th>Succès :</th>
                            <td><span class="wbe-success">${report.success}</span></td>
                        </tr>
                        <tr>
                            <th>Erreurs :</th>
                            <td><span class="wbe-errors">${report.errors}</span></td>
                        </tr>
                    </tbody>
                </table>
            </div>
        `;

        if (report.error_details && report.error_details.length > 0) {
          html +=
            '<div class="wbe-error-details"><h4>Détails des erreurs :</h4><ul>';
          report.error_details.forEach(function (error) {
            html += `<li>${error}</li>`;
          });
          html += "</ul></div>";
        }
      } else {
        html = `
            <div class="notice notice-error">
                <p><strong>✗ Erreur</strong></p>
                <p>${response.data.message || "Une erreur est survenue."}</p>
            </div>
        `;
      }

      $("#wbe-result-content").html(html);
    },

    /**
     * Affiche le loader
     */
    showLoader: function (text) {
      $("#wbe-loader-text").text(text);
      $("#wbe-loader").fadeIn(200);
    },

    /**
     * Masque le loader
     */
    hideLoader: function () {
      $("#wbe-loader").fadeOut(200);
    },
  };

  // Initialisation au chargement
  $(document).ready(function () {
    WBE_App.init();
  });
})(jQuery);
