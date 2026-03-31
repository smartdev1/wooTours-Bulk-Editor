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

      // ── État du mode "Tout sauf" ──────────────────────────
      exclusionMode: "exclude_products",   // exclude_products | exclude_from_category | exclude_categories
      exclProducts: [],                    // [{id, title}]  produits exclus (sous-mode A)
      exclCategoryId: null,                // catégorie de base (sous-mode B)
      exclCategoryProducts: [],            // [{id, title}]  produits exclus dans la catégorie (sous-mode B)
      exclCategories: [],                  // [{id, name}]   catégories exclues (sous-mode C)
    },

    init: function () {
      this.initDatepickers();
      this.bindEvents();
      this.initSelect2();
      this.loadTotalProductsCount();
      this.initializeState();
    },

    initializeState: function () {
      this.state.excludedDates = [];
      this.state.specificDates = [];
      this.state.selectedManualProducts = [];
      this.state.allProducts = [];
      this.state.selectedCategories = [];
      this.state.selectedProducts = [];
      this.state.exclProducts = [];
      this.state.exclCategoryProducts = [];
      this.state.exclCategories = [];
    },

    // ================================================================
    //  DATEPICKERS
    // ================================================================
    initDatepickers: function () {
      const self = this;

      $(".wbe-datepicker").datepicker({
        dateFormat: "dd/mm/yy",
        minDate: 0,
        changeMonth: true,
        changeYear: true,
        showButtonPanel: true,
        monthNames: ["Janvier","Février","Mars","Avril","Mai","Juin","Juillet","Août","Septembre","Octobre","Novembre","Décembre"],
        monthNamesShort: ["Jan","Fév","Mar","Avr","Mai","Jun","Jul","Aoû","Sep","Oct","Nov","Déc"],
        dayNames: ["Dimanche","Lundi","Mardi","Mercredi","Jeudi","Vendredi","Samedi"],
        dayNamesShort: ["Dim","Lun","Mar","Mer","Jeu","Ven","Sam"],
        dayNamesMin: ["Di","Lu","Ma","Me","Je","Ve","Sa"],
        firstDay: 1,
      });

      $("#wbe-exclude-calendar").datepicker({
        dateFormat: "yy-mm-dd",
        minDate: 0,
        changeMonth: true,
        changeYear: true,
        numberOfMonths: 1,
        onSelect: function (dateText) { self.toggleExcludedDate(dateText); },
        beforeShowDay: function (date) {
          const dateStr = $.datepicker.formatDate("yy-mm-dd", date);
          return [true, self.state.excludedDates.indexOf(dateStr) !== -1 ? "wbe-excluded-date" : ""];
        },
      });

      $("#wbe-specific-calendar").datepicker({
        dateFormat: "yy-mm-dd",
        minDate: 0,
        changeMonth: true,
        changeYear: true,
        numberOfMonths: 1,
        onSelect: function (dateText) { self.toggleSpecificDate(dateText); },
        beforeShowDay: function (date) {
          const dateStr = $.datepicker.formatDate("yy-mm-dd", date);
          return [true, self.state.specificDates.indexOf(dateStr) !== -1 ? "wbe-specific-date" : ""];
        },
      });
    },

    // ================================================================
    //  SELECT2
    // ================================================================
    initSelect2: function () {
      const self = this;

      $("#wbe-category")
        .select2({ placeholder: "Choisir une catégorie...", allowClear: true, width: "100%",
          language: { noResults: function () { return "Aucune catégorie trouvée"; } } })
        .on("change", function () { self.handleCategoryChange($(this).val()); });

      // Select2 pour la catégorie de base du mode exclusion (sous-mode B)
      $("#wbe-excl-category")
        .select2({ placeholder: "Choisir une catégorie...", allowClear: true, width: "100%",
          language: { noResults: function () { return "Aucune catégorie trouvée"; } } })
        .on("change", function () { self.handleExclCategoryChange($(this).val()); });

      // Select2 pour les catégories à exclure (sous-mode C)
      $("#wbe-excl-categories-select")
        .select2({ placeholder: "Sélectionner des catégories à exclure...", allowClear: true, width: "100%",
          language: { noResults: function () { return "Aucune catégorie trouvée"; } } })
        .on("change", function () { self.handleExclCategoriesChange($(this).val()); });
    },

    // ================================================================
    //  BIND EVENTS
    // ================================================================
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
        if (selection === "manual") {
          $("#wbe-manual-selection").show();
          self.loadCategoryProducts();
          self.updateManualSelectionCount();
        } else {
          $("#wbe-manual-selection").hide();
          self.state.selectedManualProducts = [];
          self.state.selectedProducts = [];
        }
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

      // Sélection/désélection dans la catégorie
      $("#wbe-select-all").on("click", function () { self.selectAllCategoryProducts(); });
      $("#wbe-deselect-all").on("click", function () { self.deselectAllCategoryProducts(); });

      // Recherche dans la catégorie
      let searchInCategoryTimeout;
      $("#wbe-category-product-search").on("input", function () {
        clearTimeout(searchInCategoryTimeout);
        const query = $(this).val();
        searchInCategoryTimeout = setTimeout(function () { self.searchInCategory(query); }, 500);
      });

      // Recherche de produits (mode sélection libre)
      let searchProductsTimeout;
      $("#wbe-product-search").on("input", function () {
        clearTimeout(searchProductsTimeout);
        const query = $(this).val();
        if (query.length < 2) { $("#wbe-product-results").empty(); return; }
        searchProductsTimeout = setTimeout(function () { self.searchProducts(query); }, 500);
      });

      $("#wbe-select-all-manual").on("click", function () { self.selectAllProducts(); });
      $("#wbe-deselect-all-manual").on("click", function () { self.deselectAllProducts(); });

      // Navigation
      $(".wbe-next-step").on("click", function () { self.nextStep(); });
      $(".wbe-prev-step").on("click", function () { self.prevStep(); });

      // Validation step 2
      $('input[name="available_days[]"]').on("change", function () { self.validateStep2(); });
      $("#wbe-start-date, #wbe-end-date").on("change", function () { self.validateStep2(); });

      // Application / réinitialisation
      $("#wbe-apply-changes").on("click", function () { self.applyChanges(); });
      $("#wbe-reset-all").on("click", function () {
        if (confirm("Êtes-vous sûr de vouloir RÉINITIALISER toutes les disponibilités de ces produits ?\n\nCette action est irréversible.")) {
          self.resetAll();
        }
      });
      $("#wbe-restart").on("click", function () { location.reload(); });

      // ── Événements MODE EXCLUSION ─────────────────────────────────

      // Changement de sous-mode d'exclusion
      $('input[name="exclusion_mode"]').on("change", function () {
        const mode = $(this).val();
        self.state.exclusionMode = mode;
        self.switchExclusionSubmode(mode);
        self.updateExclGlobalSummary();
        self.updateNextButton();
      });

      // Recherche de produits à exclure (sous-mode A)
      let exclProductSearchTimeout;
      $("#wbe-excl-product-search").on("input", function () {
        clearTimeout(exclProductSearchTimeout);
        const query = $(this).val();
        if (query.length < 2) { $("#wbe-excl-product-results").empty(); return; }
        exclProductSearchTimeout = setTimeout(function () { self.searchExclProducts(query); }, 500);
      });

      // Recherche dans catégorie pour exclusion (sous-mode B)
      let exclCatSearchTimeout;
      $("#wbe-excl-cat-product-search").on("input", function () {
        clearTimeout(exclCatSearchTimeout);
        const query = $(this).val();
        exclCatSearchTimeout = setTimeout(function () { self.searchInExclCategory(query); }, 500);
      });

      $("#wbe-excl-cat-select-all").on("click", function () { self.selectAllExclCatProducts(); });
      $("#wbe-excl-cat-deselect-all").on("click", function () { self.deselectAllExclCatProducts(); });
    },

    // ================================================================
    //  HELPERS : SÉLECTION PAR CATÉGORIE (mode existant)
    // ================================================================
    handleCategoryChange: function (categoryId) {
      const $categorySelection = $("#wbe-category-selection");
      const $productsSelection = $("#wbe-products-in-category");
      const $summary = $("#wbe-category-summary");

      if (!categoryId) {
        $productsSelection.hide(); $summary.hide(); $categorySelection.show();
        this.state.selectedCategory = null;
        this.state.categoryProductsSelection = null;
        this.state.selectedManualProducts = [];
        this.state.selectedCategories = [];
        this.state.selectedProducts = [];
        this.updateNextButton(); this.updateProductCount(); return;
      }

      this.state.selectedCategory = parseInt(categoryId);
      this.state.selectedCategories = [categoryId];

      const $option = $('#wbe-category option[value="' + categoryId + '"]');
      const optionText = $option.text();
      const categoryName = optionText.split("(")[0].trim();
      const match = optionText.match(/\((\d+) produits\)/);
      const productCount = match ? match[1] : "0";

      $("#wbe-category-product-count").text(productCount);
      $("#wbe-selected-category-name").text(categoryName);

      $categorySelection.hide(); $productsSelection.show(); $summary.hide();
      $('input[name="category_products_selection"]').prop("checked", false);
      $("#wbe-manual-selection").hide();

      this.state.categoryProductsSelection = null;
      this.state.selectedManualProducts = [];
      this.state.selectedProducts = [];

      this.updateNextButton(); this.updateProductCount();
    },

    loadCategoryProducts: function () {
      if (!this.state.selectedCategory) return;
      const self = this;
      const $results = $("#wbe-category-product-results");
      const $spinner = $("#wbe-category-product-search").siblings(".spinner");
      $spinner.addClass("is-active");
      $results.html('<p class="wbe-loading">Chargement des produits...</p>');

      $.ajax({
        url: WBE_DATA.ajax_url, type: "POST",
        data: { action: "wbe_get_category_products", nonce: WBE_DATA.nonce, category_id: this.state.selectedCategory },
        success: function (response) {
          $spinner.removeClass("is-active");
          if (response.success && response.data.products) { self.renderCategoryProducts(response.data.products); }
          else { $results.html('<p class="wbe-no-results">Aucun produit trouvé</p>'); }
        },
        error: function () { $spinner.removeClass("is-active"); $results.html('<p class="wbe-error">Erreur lors du chargement</p>'); },
      });
    },

    renderCategoryProducts: function (products) {
      const $container = $("#wbe-category-product-results");
      const self = this;
      if (!products || products.length === 0) { $container.html('<p class="wbe-no-results">Aucun produit dans cette catégorie</p>'); return; }

      let html = '<ul class="wbe-product-items">';
      products.forEach(function (product) {
        const isSelected = self.state.selectedManualProducts.some((p) => p.id === product.id);
        html += `<li class="wbe-product-item ${isSelected ? "selected" : ""}" data-id="${product.id}">
          <label><input type="checkbox" value="${product.id}" ${isSelected ? "checked" : ""}>
          <span class="product-title">${product.title}</span>
          <span class="product-id">#${product.id}</span></label></li>`;
      });
      html += "</ul>";
      $container.html(html);

      $container.find('input[type="checkbox"]').on("change", function () {
        const productId = parseInt($(this).val());
        const productTitle = $(this).closest("label").find(".product-title").text();
        if ($(this).is(":checked")) { self.addManualProduct(productId, productTitle); }
        else { self.removeManualProduct(productId); }
      });
    },

    searchInCategory: function (query) {
      if (!this.state.selectedCategory || query.length < 2) { this.loadCategoryProducts(); return; }
      const self = this;
      const $results = $("#wbe-category-product-results");
      const $spinner = $("#wbe-category-product-search").siblings(".spinner");
      $spinner.addClass("is-active");

      $.ajax({
        url: WBE_DATA.ajax_url, type: "POST",
        data: { action: "wbe_search_in_category", nonce: WBE_DATA.nonce, category_id: this.state.selectedCategory, query: query },
        success: function (response) {
          $spinner.removeClass("is-active");
          if (response.success && response.data.products) { self.renderCategoryProducts(response.data.products); }
          else { $results.html('<p class="wbe-no-results">Aucun produit trouvé</p>'); }
        },
        error: function () { $spinner.removeClass("is-active"); $results.html('<p class="wbe-error">Erreur</p>'); },
      });
    },

    addManualProduct: function (productId, productTitle) {
      if (!this.state.selectedManualProducts.some((p) => p.id === productId)) {
        this.state.selectedManualProducts.push({ id: productId, title: productTitle });
        this.state.selectedProducts = this.state.selectedManualProducts;
        this.updateManualSelectionCount(); this.updateCategorySummary();
        this.updateNextButton(); this.updateProductCount();
      }
    },

    removeManualProduct: function (productId) {
      this.state.selectedManualProducts = this.state.selectedManualProducts.filter((p) => p.id !== productId);
      this.state.selectedProducts = this.state.selectedManualProducts;
      this.updateManualSelectionCount(); this.updateCategorySummary();
      this.updateNextButton(); this.updateProductCount();
    },

    updateManualSelectionCount: function () {
      $("#wbe-manual-selected-count").text(this.state.selectedManualProducts.length);
    },

    updateCategorySummary: function () {
      let productCount = 0;
      if (this.state.categoryProductsSelection === "all") {
        productCount = parseInt($("#wbe-category-product-count").text()) || 0;
      } else if (this.state.categoryProductsSelection === "manual") {
        productCount = this.state.selectedManualProducts.length;
      }
      $("#wbe-selected-product-count").text(productCount);
      $("#wbe-category-summary").show();
    },

    loadTotalProductsCount: function () {
      $.ajax({
        url: WBE_DATA.ajax_url, type: "POST",
        data: { action: "wbe_get_total_products", nonce: WBE_DATA.nonce },
        success: function (response) { if (response.success) { $("#wbe-total-catalog-count").text(response.data.total); } },
      });
    },

    selectAllCategoryProducts: function () { $("#wbe-category-product-results input[type='checkbox']").prop("checked", true).trigger("change"); },
    deselectAllCategoryProducts: function () { $("#wbe-category-product-results input[type='checkbox']").prop("checked", false).trigger("change"); },
    selectAllProducts: function () { $("#wbe-product-results input[type='checkbox']").prop("checked", true).trigger("change"); },
    deselectAllProducts: function () { $("#wbe-product-results input[type='checkbox']").prop("checked", false).trigger("change"); },

    switchSelectionMode: function (mode) {
      $(".wbe-selection-panel").hide();
      $("#panel-" + mode).show();

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
      if (mode === "exclusion") {
        // Initialiser le sous-mode par défaut
        this.switchExclusionSubmode(this.state.exclusionMode);
        this.updateExclGlobalSummary();
      }
    },

    searchProducts: function (query) {
      const self = this;
      const $results = $("#wbe-product-results");
      const $spinner = $("#wbe-product-search").siblings(".spinner");
      $spinner.addClass("is-active");

      $.ajax({
        url: WBE_DATA.ajax_url, type: "POST",
        data: { action: "wbe_search_products", nonce: WBE_DATA.nonce, query: query },
        success: function (response) {
          $spinner.removeClass("is-active");
          if (response.success && response.data.products) { self.renderProductResults(response.data.products); }
          else { $results.html('<p class="wbe-no-results">Aucun produit trouvé</p>'); }
        },
        error: function () { $spinner.removeClass("is-active"); $results.html('<p class="wbe-error">Erreur</p>'); },
      });
    },

    renderProductResults: function (products) {
      const self = this;
      const $results = $("#wbe-product-results");
      if (products.length === 0) { $results.html('<p class="wbe-no-results">Aucun produit trouvé</p>'); return; }

      let html = '<ul class="wbe-product-items">';
      products.forEach(function (product) {
        const isSelected = self.state.allProducts.some((p) => p.id === product.id);
        html += `<li class="wbe-product-item ${isSelected ? "selected" : ""}" data-id="${product.id}">
          <label><input type="checkbox" value="${product.id}" ${isSelected ? "checked" : ""} />
          <span class="product-title">${product.title}</span>
          <span class="product-id">#${product.id}</span></label></li>`;
      });
      html += "</ul>";
      $results.html(html);

      $results.find('input[type="checkbox"]').on("change", function () {
        const productId = parseInt($(this).val());
        const productTitle = $(this).closest("label").find(".product-title").text();
        if ($(this).is(":checked")) { self.addProduct(productId, productTitle); }
        else { self.removeProduct(productId); }
      });
    },

    addProduct: function (productId, productTitle) {
      if (!this.state.allProducts.some((p) => p.id === productId)) {
        this.state.allProducts.push({ id: productId, title: productTitle });
        this.state.selectedProducts = this.state.allProducts;
        this.renderSelectedProducts(); this.updateProductCount();
      }
    },

    removeProduct: function (productId) {
      this.state.allProducts = this.state.allProducts.filter((p) => p.id !== productId);
      this.state.selectedProducts = this.state.allProducts;
      this.renderSelectedProducts(); this.updateProductCount();
    },

    renderSelectedProducts: function () {
      const $container = $("#wbe-selected-products");
      const $noSelection = $container.find(".wbe-no-selection");
      const products = this.state.selectionMode === "manual" ? this.state.allProducts : this.state.selectedProducts;

      if (products.length === 0) { $noSelection.show(); $container.find(".wbe-tag").remove(); return; }
      $noSelection.hide(); $container.find(".wbe-tag").remove();

      const self = this;
      products.forEach(function (product) {
        const $tag = $(`<span class="wbe-tag" data-id="${product.id}">
          <span class="tag-icon"><span class="dashicons dashicons-admin-post"></span></span>
          <span class="tag-text">${product.title}</span>
          <button type="button" class="tag-remove" data-id="${product.id}"><span class="dashicons dashicons-no-alt"></span></button>
        </span>`);
        $tag.find(".tag-remove").on("click", function () {
          const id = parseInt($(this).data("id"));
          if (self.state.selectionMode === "manual") { self.removeProduct(id); }
          else if (self.state.selectionMode === "categories" && self.state.categoryProductsSelection === "manual") { self.removeManualProduct(id); }
        });
        $container.append($tag);
      });
    },

    updateProductCount: function () {
      let total = 0;
      let displayText = "0";

      if (this.state.selectionMode === "categories") {
        if (this.state.categoryProductsSelection === "all") { total = parseInt($("#wbe-category-product-count").text()) || 0; displayText = total; }
        else if (this.state.categoryProductsSelection === "manual") { total = this.state.selectedManualProducts.length; displayText = total; }
      } else if (this.state.selectionMode === "manual") {
        total = this.state.allProducts.length; displayText = total;
      } else if (this.state.selectionMode === "all") {
        displayText = "Tous"; total = parseInt($("#wbe-total-catalog-count").text()) || 0;
      } else if (this.state.selectionMode === "exclusion") {
        // Compter selon le sous-mode
        total = this.computeExclFinalCount();
        displayText = total > 0 ? total : "—";
      }

      $("#wbe-total-products").text(displayText);
      const hasSelection = total > 0 || this.state.selectionMode === "all";
      $(".wbe-step-1 .wbe-next-step").prop("disabled", !hasSelection);
    },

    updateNextButton: function () {
      let isValid = false;

      if (this.state.selectionMode === "categories") {
        isValid = this.state.selectedCategory !== null && this.state.categoryProductsSelection !== null;
      } else if (this.state.selectionMode === "manual") {
        isValid = this.state.allProducts.length > 0;
      } else if (this.state.selectionMode === "all") {
        isValid = true;
      } else if (this.state.selectionMode === "exclusion") {
        isValid = this.isExclusionValid();
      }

      $(".wbe-step-1 .wbe-next-step").prop("disabled", !isValid);
    },

    // ================================================================
    //  MODE EXCLUSION — LOGIQUE PRINCIPALE
    // ================================================================

    /**
     * Valide si le mode exclusion est prêt pour passer à l'étape 2
     */
    isExclusionValid: function () {
      const mode = this.state.exclusionMode;

      if (mode === "exclude_products") {
        // Valide même sans exclusions (= tous les produits)
        return true;
      } else if (mode === "exclude_from_category") {
        // Besoin d'une catégorie sélectionnée
        return this.state.exclCategoryId !== null;
      } else if (mode === "exclude_categories") {
        // Besoin d'au moins une catégorie à exclure
        return this.state.exclCategories.length > 0;
      }
      return false;
    },

    /**
     * Calcule le nombre final de produits qui seront traités
     */
    computeExclFinalCount: function () {
      const totalCatalog = parseInt($("#wbe-total-catalog-count").text()) || 0;
      const mode = this.state.exclusionMode;

      if (mode === "exclude_products") {
        return Math.max(0, totalCatalog - this.state.exclProducts.length);
      } else if (mode === "exclude_from_category") {
        const catCount = parseInt($("#wbe-excl-category option:selected").text().match(/\((\d+) produits\)/)?.[1] || "0");
        return Math.max(0, catCount - this.state.exclCategoryProducts.length);
      } else if (mode === "exclude_categories") {
        const excludedCount = this.state.exclCategories.reduce((sum, c) => sum + (c.count || 0), 0);
        return Math.max(0, totalCatalog - excludedCount);
      }
      return 0;
    },

    /**
     * Bascule entre les sous-panneaux d'exclusion
     */
    switchExclusionSubmode: function (mode) {
      $(".wbe-exclusion-subpanel").hide();

      if (mode === "exclude_products") {
        $("#excl-panel-products").show();
      } else if (mode === "exclude_from_category") {
        $("#excl-panel-from-category").show();
      } else if (mode === "exclude_categories") {
        $("#excl-panel-categories").show();
      }

      this.updateExclGlobalSummary();
    },

    /**
     * Met à jour le résumé global du mode exclusion
     */
    updateExclGlobalSummary: function () {
      const mode = this.state.exclusionMode;
      const $summary = $("#wbe-excl-global-summary");
      const totalCatalog = parseInt($("#wbe-total-catalog-count").text()) || 0;

      let scopeCount = "—";
      let excludedCount = 0;
      let finalCount = "—";

      if (mode === "exclude_products") {
        scopeCount = totalCatalog || "Tous";
        excludedCount = this.state.exclProducts.length;
        if (totalCatalog > 0) { finalCount = Math.max(0, totalCatalog - excludedCount); }
      } else if (mode === "exclude_from_category") {
        const $option = $("#wbe-excl-category option:selected");
        if (this.state.exclCategoryId) {
          const match = $option.text().match(/\((\d+) produits\)/);
          scopeCount = match ? parseInt(match[1]) : "—";
          excludedCount = this.state.exclCategoryProducts.length;
          if (typeof scopeCount === "number") { finalCount = Math.max(0, scopeCount - excludedCount); }
        }
      } else if (mode === "exclude_categories") {
        scopeCount = totalCatalog || "Tous";
        excludedCount = this.state.exclCategories.reduce((s, c) => s + (c.count || 0), 0);
        if (totalCatalog > 0) { finalCount = Math.max(0, totalCatalog - excludedCount); }
      }

      $("#wbe-excl-scope-count").text(scopeCount);
      $("#wbe-excl-excluded-count").text(excludedCount);
      $("#wbe-excl-final-count").text(finalCount);

      // Afficher le résumé seulement si on a des infos
      if (mode === "exclude_products" || (mode === "exclude_from_category" && this.state.exclCategoryId) || (mode === "exclude_categories" && this.state.exclCategories.length > 0)) {
        $summary.show();
      } else {
        $summary.hide();
      }
    },

    // ── Sous-mode A : Exclure des produits spécifiques ──────────────

    searchExclProducts: function (query) {
      const self = this;
      const $results = $("#wbe-excl-product-results");
      const $spinner = $("#wbe-excl-product-search").siblings(".spinner");
      $spinner.addClass("is-active");

      $.ajax({
        url: WBE_DATA.ajax_url, type: "POST",
        data: { action: "wbe_search_products", nonce: WBE_DATA.nonce, query: query },
        success: function (response) {
          $spinner.removeClass("is-active");
          if (response.success && response.data.products) { self.renderExclProductResults(response.data.products); }
          else { $results.html('<p class="wbe-no-results">Aucun produit trouvé</p>'); }
        },
        error: function () { $spinner.removeClass("is-active"); $results.html('<p class="wbe-error">Erreur</p>'); },
      });
    },

    renderExclProductResults: function (products) {
      const self = this;
      const $results = $("#wbe-excl-product-results");
      if (products.length === 0) { $results.html('<p class="wbe-no-results">Aucun produit trouvé</p>'); return; }

      let html = '<ul class="wbe-product-items">';
      products.forEach(function (product) {
        const isExcluded = self.state.exclProducts.some((p) => p.id === product.id);
        html += `<li class="wbe-product-item ${isExcluded ? "selected wbe-item-excluded" : ""}" data-id="${product.id}">
          <label><input type="checkbox" value="${product.id}" ${isExcluded ? "checked" : ""} />
          <span class="product-title">${product.title}</span>
          <span class="product-id">#${product.id}</span></label></li>`;
      });
      html += "</ul>";
      $results.html(html);

      $results.find('input[type="checkbox"]').on("change", function () {
        const productId = parseInt($(this).val());
        const productTitle = $(this).closest("label").find(".product-title").text();
        if ($(this).is(":checked")) { self.addExclProduct(productId, productTitle); }
        else { self.removeExclProduct(productId); }
      });
    },

    addExclProduct: function (productId, productTitle) {
      if (!this.state.exclProducts.some((p) => p.id === productId)) {
        this.state.exclProducts.push({ id: productId, title: productTitle });
        this.renderExclProductTags();
        this.updateExclGlobalSummary();
        this.updateNextButton();
      }
    },

    removeExclProduct: function (productId) {
      this.state.exclProducts = this.state.exclProducts.filter((p) => p.id !== productId);
      // Décocher dans les résultats si visible
      $("#wbe-excl-product-results input[value='" + productId + "']").prop("checked", false);
      $("#wbe-excl-product-results .wbe-product-item[data-id='" + productId + "']").removeClass("selected wbe-item-excluded");
      this.renderExclProductTags();
      this.updateExclGlobalSummary();
      this.updateNextButton();
    },

    renderExclProductTags: function () {
      const $container = $("#wbe-excl-selected-products");
      const self = this;

      $container.find(".wbe-tag").remove();
      $("#wbe-excl-products-count").text(this.state.exclProducts.length);

      if (this.state.exclProducts.length === 0) {
        $container.find(".wbe-no-selection").show(); return;
      }
      $container.find(".wbe-no-selection").hide();

      this.state.exclProducts.forEach(function (product) {
        const $tag = $(`<span class="wbe-tag wbe-tag-excl" data-id="${product.id}">
          <span class="tag-icon"><span class="dashicons dashicons-dismiss"></span></span>
          <span class="tag-text">${product.title}</span>
          <button type="button" class="tag-remove" data-id="${product.id}"><span class="dashicons dashicons-no-alt"></span></button>
        </span>`);
        $tag.find(".tag-remove").on("click", function () { self.removeExclProduct(parseInt($(this).data("id"))); });
        $container.append($tag);
      });
    },

    // ── Sous-mode B : Toute une catégorie sauf certains produits ────

    handleExclCategoryChange: function (categoryId) {
      this.state.exclCategoryId = categoryId ? parseInt(categoryId) : null;
      this.state.exclCategoryProducts = [];

      if (!categoryId) {
        $("#wbe-excl-cat-products-wrapper").hide();
        this.updateExclGlobalSummary(); this.updateNextButton(); return;
      }

      $("#wbe-excl-cat-products-wrapper").show();
      this.loadExclCategoryProducts();
      this.renderExclCatProductTags();
      this.updateExclGlobalSummary();
      this.updateNextButton();
    },

    loadExclCategoryProducts: function () {
      if (!this.state.exclCategoryId) return;
      const self = this;
      const $results = $("#wbe-excl-cat-product-results");
      const $spinner = $("#wbe-excl-cat-product-search").siblings(".spinner");

      $spinner.addClass("is-active");
      $results.html('<p class="wbe-loading">Chargement des produits...</p>');

      $.ajax({
        url: WBE_DATA.ajax_url, type: "POST",
        data: { action: "wbe_get_category_products", nonce: WBE_DATA.nonce, category_id: this.state.exclCategoryId },
        success: function (response) {
          $spinner.removeClass("is-active");
          if (response.success && response.data.products) { self.renderExclCatProducts(response.data.products); }
          else { $results.html('<p class="wbe-no-results">Aucun produit trouvé</p>'); }
        },
        error: function () { $spinner.removeClass("is-active"); $results.html('<p class="wbe-error">Erreur</p>'); },
      });
    },

    renderExclCatProducts: function (products) {
      const $container = $("#wbe-excl-cat-product-results");
      const self = this;

      if (!products || products.length === 0) { $container.html('<p class="wbe-no-results">Aucun produit dans cette catégorie</p>'); return; }

      let html = '<ul class="wbe-product-items">';
      products.forEach(function (product) {
        const isExcluded = self.state.exclCategoryProducts.some((p) => p.id === product.id);
        html += `<li class="wbe-product-item ${isExcluded ? "selected wbe-item-excluded" : ""}" data-id="${product.id}">
          <label><input type="checkbox" value="${product.id}" ${isExcluded ? "checked" : ""}>
          <span class="product-title">${product.title}</span>
          <span class="product-id">#${product.id}</span></label></li>`;
      });
      html += "</ul>";
      $container.html(html);

      $container.find('input[type="checkbox"]').on("change", function () {
        const productId = parseInt($(this).val());
        const productTitle = $(this).closest("label").find(".product-title").text();
        if ($(this).is(":checked")) { self.addExclCatProduct(productId, productTitle); }
        else { self.removeExclCatProduct(productId); }
      });
    },

    searchInExclCategory: function (query) {
      if (!this.state.exclCategoryId) return;
      if (query.length < 2) { this.loadExclCategoryProducts(); return; }

      const self = this;
      const $results = $("#wbe-excl-cat-product-results");
      const $spinner = $("#wbe-excl-cat-product-search").siblings(".spinner");
      $spinner.addClass("is-active");

      $.ajax({
        url: WBE_DATA.ajax_url, type: "POST",
        data: { action: "wbe_search_in_category", nonce: WBE_DATA.nonce, category_id: this.state.exclCategoryId, query: query },
        success: function (response) {
          $spinner.removeClass("is-active");
          if (response.success && response.data.products) { self.renderExclCatProducts(response.data.products); }
          else { $results.html('<p class="wbe-no-results">Aucun produit trouvé</p>'); }
        },
        error: function () { $spinner.removeClass("is-active"); $results.html('<p class="wbe-error">Erreur</p>'); },
      });
    },

    selectAllExclCatProducts: function () {
      $("#wbe-excl-cat-product-results input[type='checkbox']").prop("checked", true).trigger("change");
    },
    deselectAllExclCatProducts: function () {
      $("#wbe-excl-cat-product-results input[type='checkbox']").prop("checked", false).trigger("change");
    },

    addExclCatProduct: function (productId, productTitle) {
      if (!this.state.exclCategoryProducts.some((p) => p.id === productId)) {
        this.state.exclCategoryProducts.push({ id: productId, title: productTitle });
        this.renderExclCatProductTags();
        this.updateExclGlobalSummary();
      }
    },

    removeExclCatProduct: function (productId) {
      this.state.exclCategoryProducts = this.state.exclCategoryProducts.filter((p) => p.id !== productId);
      $("#wbe-excl-cat-product-results input[value='" + productId + "']").prop("checked", false);
      $("#wbe-excl-cat-product-results .wbe-product-item[data-id='" + productId + "']").removeClass("selected wbe-item-excluded");
      this.renderExclCatProductTags();
      this.updateExclGlobalSummary();
    },

    renderExclCatProductTags: function () {
      const $container = $("#wbe-excl-cat-selected-products");
      const self = this;

      $container.find(".wbe-tag").remove();
      $("#wbe-excl-cat-selected-count").text(this.state.exclCategoryProducts.length);
      $("#wbe-excl-cat-count").text(this.state.exclCategoryProducts.length);

      if (this.state.exclCategoryProducts.length === 0) {
        $container.find(".wbe-no-selection").show(); return;
      }
      $container.find(".wbe-no-selection").hide();

      this.state.exclCategoryProducts.forEach(function (product) {
        const $tag = $(`<span class="wbe-tag wbe-tag-excl" data-id="${product.id}">
          <span class="tag-icon"><span class="dashicons dashicons-dismiss"></span></span>
          <span class="tag-text">${product.title}</span>
          <button type="button" class="tag-remove" data-id="${product.id}"><span class="dashicons dashicons-no-alt"></span></button>
        </span>`);
        $tag.find(".tag-remove").on("click", function () { self.removeExclCatProduct(parseInt($(this).data("id"))); });
        $container.append($tag);
      });
    },

    // ── Sous-mode C : Exclure des catégories entières ────────────────

    handleExclCategoriesChange: function (selectedIds) {
      if (!selectedIds || selectedIds.length === 0) {
        this.state.exclCategories = [];
      } else {
        this.state.exclCategories = selectedIds.map((id) => {
          const $option = $('#wbe-excl-categories-select option[value="' + id + '"]');
          const text = $option.text();
          const match = text.match(/\((\d+) produits\)/);
          return { id: parseInt(id), name: text.split("(")[0].trim(), count: match ? parseInt(match[1]) : 0 };
        });
      }

      this.renderExclCategoriesTags();
      this.updateExclGlobalSummary();
      this.updateNextButton();
    },

    renderExclCategoriesTags: function () {
      const $tagsContainer = $("#wbe-excl-categories-tags");
      const $summary = $("#wbe-excl-categories-summary");
      const self = this;

      $tagsContainer.find(".wbe-tag").remove();
      $("#wbe-excl-categories-count").text(this.state.exclCategories.length);

      if (this.state.exclCategories.length === 0) {
        $summary.hide(); return;
      }

      $summary.show();

      this.state.exclCategories.forEach(function (cat) {
        const $tag = $(`<span class="wbe-tag wbe-tag-excl" data-id="${cat.id}">
          <span class="tag-icon"><span class="dashicons dashicons-tag"></span></span>
          <span class="tag-text">${cat.name} <small>(${cat.count} produits)</small></span>
          <button type="button" class="tag-remove" data-id="${cat.id}"><span class="dashicons dashicons-no-alt"></span></button>
        </span>`);
        $tag.find(".tag-remove").on("click", function () {
          const id = $(this).data("id").toString();
          const currentVals = $("#wbe-excl-categories-select").val() || [];
          const newVals = currentVals.filter((v) => v !== id);
          $("#wbe-excl-categories-select").val(newVals).trigger("change");
        });
        $tagsContainer.append($tag);
      });

      // Mise à jour du texte d'info
      const totalCatalog = parseInt($("#wbe-total-catalog-count").text()) || 0;
      const excludedCount = this.state.exclCategories.reduce((s, c) => s + c.count, 0);
      const finalCount = Math.max(0, totalCatalog - excludedCount);
      $("#wbe-excl-scope-text").text(
        `${finalCount} produit(s) sur ${totalCatalog} seront traités (${excludedCount} exclus via ${this.state.exclCategories.length} catégorie(s))`
      );
    },

    // ================================================================
    //  GESTION DES DATES
    // ================================================================
    toggleExcludedDate: function (dateStr) {
      const index = this.state.excludedDates.indexOf(dateStr);
      if (index === -1) { this.state.excludedDates.push(dateStr); }
      else { this.state.excludedDates.splice(index, 1); }
      this.renderExcludedDates(); this.validateStep2();
      $("#wbe-exclude-calendar").datepicker("refresh");
    },

    toggleSpecificDate: function (dateStr) {
      const index = this.state.specificDates.indexOf(dateStr);
      if (index === -1) { this.state.specificDates.push(dateStr); }
      else { this.state.specificDates.splice(index, 1); }
      this.renderSpecificDates(); this.validateStep2();
      $("#wbe-specific-calendar").datepicker("refresh");
    },

    renderExcludedDates: function () {
      const $list = $("#wbe-excluded-dates");
      const $noDate = $(".wbe-no-excluded-dates");
      if (this.state.excludedDates.length === 0) { $list.empty(); $noDate.show(); return; }
      $noDate.hide();
      const self = this;
      let html = "";
      this.state.excludedDates.sort().forEach(function (date) {
        html += `<li><span class="date">${self.formatDateForDisplay(date)}</span>
          <button type="button" class="wbe-remove-date" data-date="${date}"><span class="dashicons dashicons-no-alt"></span></button></li>`;
      });
      $list.html(html);
      $list.find(".wbe-remove-date").on("click", function () { self.toggleExcludedDate($(this).data("date")); });
    },

    renderSpecificDates: function () {
      const $list = $("#wbe-specific-dates");
      const $noDate = $(".wbe-no-specific-dates");
      if (this.state.specificDates.length === 0) { $list.empty(); $noDate.show(); return; }
      $noDate.hide();
      const self = this;
      let html = "";
      this.state.specificDates.sort().forEach(function (date) {
        html += `<li><span class="date">${self.formatDateForDisplay(date)}</span>
          <button type="button" class="wbe-remove-specific-date" data-date="${date}"><span class="dashicons dashicons-no-alt"></span></button></li>`;
      });
      $list.html(html);
      $list.find(".wbe-remove-specific-date").on("click", function () { self.toggleSpecificDate($(this).data("date")); });
    },

    convertDate: function (dateStr) {
      if (!dateStr) return "";
      if (/^\d{4}-\d{2}-\d{2}$/.test(dateStr)) return dateStr;
      const parts = dateStr.split("/");
      if (parts.length === 3) return `${parts[2]}-${parts[1]}-${parts[0]}`;
      return dateStr;
    },

    formatDateForDisplay: function (dateStr) {
      if (!dateStr) return "";
      if (/^\d{2}\/\d{2}\/\d{4}$/.test(dateStr)) return dateStr;
      const parts = dateStr.split("-");
      if (parts.length === 3) return `${parts[2]}/${parts[1]}/${parts[0]}`;
      return dateStr;
    },

    validateStep2: function () {
      const hasChanges =
        $("#wbe-start-date").val() !== "" ||
        $("#wbe-end-date").val() !== "" ||
        $('input[name="available_days[]"]:checked').length > 0 ||
        this.state.excludedDates.length > 0 ||
        this.state.specificDates.length > 0;
      $(".wbe-step-2 .wbe-next-step").prop("disabled", !hasChanges);
    },

    // ================================================================
    //  NAVIGATION
    // ================================================================
    nextStep: function () {
      if (this.state.currentStep === 2) { this.preparePreview(); }
      $(".wbe-step").removeClass("active");
      this.state.currentStep++;
      $(`.wbe-step-${this.state.currentStep}`).addClass("active");
      $("html, body").animate({ scrollTop: 0 }, 300);
    },

    prevStep: function () {
      $(".wbe-step").removeClass("active");
      this.state.currentStep--;
      $(`.wbe-step-${this.state.currentStep}`).addClass("active");
      $("html, body").animate({ scrollTop: 0 }, 300);
    },

    // ================================================================
    //  PRÉVISUALISATION
    // ================================================================
    preparePreview: function () {
      this.state.changes = this.collectChanges();

      let productCount = 0;
      let productLabel = "";

      if (this.state.selectionMode === "categories") {
        if (this.state.categoryProductsSelection === "all") { productCount = parseInt($("#wbe-category-product-count").text()) || 0; }
        else { productCount = this.state.selectedManualProducts.length; }
        productLabel = `${productCount} produit(s) de la catégorie`;
      } else if (this.state.selectionMode === "manual") {
        productCount = this.state.allProducts.length;
        productLabel = `${productCount} produit(s) sélectionné(s)`;
      } else if (this.state.selectionMode === "all") {
        productCount = parseInt($("#wbe-total-catalog-count").text()) || 0;
        productLabel = `Tous les produits (${productCount})`;
      } else if (this.state.selectionMode === "exclusion") {
        productCount = this.computeExclFinalCount();
        const mode = this.state.exclusionMode;
        if (mode === "exclude_products") {
          productLabel = `Tout le catalogue sauf ${this.state.exclProducts.length} produit(s) exclus = ${productCount} produit(s)`;
        } else if (mode === "exclude_from_category") {
          const catName = $("#wbe-excl-category option:selected").text().split("(")[0].trim();
          productLabel = `Catégorie "${catName}" sauf ${this.state.exclCategoryProducts.length} produit(s) exclus = ${productCount} produit(s)`;
        } else if (mode === "exclude_categories") {
          const catNames = this.state.exclCategories.map((c) => c.name).join(", ");
          productLabel = `Tout le catalogue sauf catégorie(s) "${catNames}" = ${productCount} produit(s)`;
        }
      }

      $("#wbe-preview-products").html(`<p>${productLabel}</p>`);
      $("#wbe-confirm-count").text(productCount);

      let changesHtml = "<ul>";
      if (this.state.changes.start_date) changesHtml += `<li><strong>Date de début :</strong> ${this.state.changes.start_date}</li>`;
      if (this.state.changes.end_date) changesHtml += `<li><strong>Date de fin :</strong> ${this.state.changes.end_date}</li>`;
      if (this.state.changes.available_days) changesHtml += `<li><strong>Jours disponibles :</strong> ${this.state.changes.available_days.join(", ")}</li>`;
      if (this.state.changes.unavailable_dates) changesHtml += `<li><strong>Dates exclues :</strong> ${this.state.changes.unavailable_dates.length} date(s)</li>`;
      if (this.state.changes.specific_dates) changesHtml += `<li><strong>Dates spécifiques :</strong> ${this.state.changes.specific_dates.length} date(s)</li>`;
      changesHtml += "</ul>";
      $("#wbe-preview-changes").html(changesHtml);
    },

    collectChanges: function () {
      const changes = {};
      const startDate = $("#wbe-start-date").val();
      const endDate = $("#wbe-end-date").val();
      if (startDate) changes.start_date = this.convertDate(startDate);
      if (endDate) changes.end_date = this.convertDate(endDate);
      const days = [];
      $('input[name="available_days[]"]:checked').each(function () { days.push($(this).val()); });
      if (days.length > 0) changes.available_days = days;
      if (this.state.excludedDates.length > 0) changes.unavailable_dates = this.state.excludedDates;
      if (this.state.specificDates.length > 0) changes.specific_dates = this.state.specificDates;
      return changes;
    },

    // ================================================================
    //  AJAX — APPLY / RESET
    // ================================================================
    applyChanges: function () {
      const self = this;
      this.showLoader("Traitement en cours...");

      const ajaxData = {
        action: "wbe_bulk_update",
        nonce: WBE_DATA.nonce,
        changes: this.state.changes,
        action_type: "update",
        selection_mode: this.state.selectionMode,
      };

      this.appendSelectionData(ajaxData);

      $.ajax({
        url: WBE_DATA.ajax_url, type: "POST", data: ajaxData,
        success: function (response) { self.hideLoader(); self.showResult(response); },
        error: function (xhr, status, error) { console.error("Erreur :", error); self.hideLoader(); alert("Une erreur est survenue lors du traitement."); },
      });
    },

    resetAll: function () {
      const self = this;
      this.showLoader("Réinitialisation en cours...");

      const ajaxData = {
        action: "wbe_bulk_update",
        nonce: WBE_DATA.nonce,
        action_type: "reset",
        selection_mode: this.state.selectionMode,
      };

      this.appendSelectionData(ajaxData);

      $.ajax({
        url: WBE_DATA.ajax_url, type: "POST", data: ajaxData,
        success: function (response) { self.hideLoader(); self.showResult(response); },
        error: function (xhr, status, error) { console.error("Erreur :", error); self.hideLoader(); alert("Une erreur est survenue lors de la réinitialisation."); },
      });
    },

    /**
     * Ajoute les données de sélection à l'objet ajaxData selon le mode actif
     */
    appendSelectionData: function (ajaxData) {
      if (this.state.selectionMode === "categories") {
        ajaxData.category_id = this.state.selectedCategory;
        ajaxData.category_selection = this.state.categoryProductsSelection;
        if (this.state.categoryProductsSelection === "manual") {
          ajaxData.products = this.state.selectedManualProducts.map((p) => p.id);
        }
      } else if (this.state.selectionMode === "manual") {
        ajaxData.products = this.state.allProducts.map((p) => p.id);
      } else if (this.state.selectionMode === "exclusion") {
        // Données spécifiques au mode exclusion
        ajaxData.exclusion_mode = this.state.exclusionMode;

        if (this.state.exclusionMode === "exclude_products") {
          ajaxData.excluded_product_ids = this.state.exclProducts.map((p) => p.id);

        } else if (this.state.exclusionMode === "exclude_from_category") {
          ajaxData.excl_category_id = this.state.exclCategoryId;
          ajaxData.excluded_product_ids = this.state.exclCategoryProducts.map((p) => p.id);

        } else if (this.state.exclusionMode === "exclude_categories") {
          ajaxData.excluded_category_ids = this.state.exclCategories.map((c) => c.id);
        }
      }
      // Mode "all" : rien à ajouter
    },

    // ================================================================
    //  RÉSULTAT / LOADER
    // ================================================================
    showResult: function (response) {
      $(".wbe-step").removeClass("active").hide();
      $(".wbe-step-result").show();

      let html = "";
      if (response.success) {
        const report = response.data.report;
        html = `<div class="notice notice-success">
          <p><strong>✓ Traitement terminé avec succès</strong></p>
          <p>${response.data.message}</p></div>
          <div class="wbe-report"><table class="widefat"><tbody>
          <tr><th>Produits traités :</th><td>${report.total}</td></tr>
          <tr><th>Succès :</th><td><span class="wbe-success">${report.success}</span></td></tr>
          <tr><th>Erreurs :</th><td><span class="wbe-errors">${report.errors}</span></td></tr>
          </tbody></table></div>`;
        if (report.error_details && report.error_details.length > 0) {
          html += '<div class="wbe-error-details"><h4>Détails des erreurs :</h4><ul>';
          report.error_details.forEach(function (error) { html += `<li>${error}</li>`; });
          html += "</ul></div>";
        }
      } else {
        html = `<div class="notice notice-error"><p><strong>✗ Erreur</strong></p>
          <p>${response.data ? response.data.message : "Une erreur est survenue."}</p></div>`;
      }
      $("#wbe-result-content").html(html);
    },

    showLoader: function (text) { $("#wbe-loader-text").text(text); $("#wbe-loader").fadeIn(200); },
    hideLoader: function () { $("#wbe-loader").fadeOut(200); },
  };

  $(document).ready(function () { WBE_App.init(); });
})(jQuery);
