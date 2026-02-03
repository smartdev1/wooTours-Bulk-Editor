/**
 * Wootour Bulk Editor - Admin JavaScript (Enhanced - avec Reset)
 * VERSION MODIFIÉE : Ajout de la fonctionnalité de réinitialisation complète
 */
(function ($) {
  "use strict";

  // Variables globales pour stocker les dates
  let specificDates = [];
  let exclusionDates = [];
  let resetMode = false; // Nouveau: flag pour le mode reset

  const WBE_Admin = {
    currentStep: 1,
    selectedProducts: [],
    formData: {
      start_date: "",
      end_date: "",
      weekdays: [],
      specific_dates: [],
      exclusions: [],
      reset_all: false, // Nouveau: flag pour reset
    },

    /**
     * Initialize
     */
    init: function () {
      console.log("WBE Admin initializing...");

      if (typeof wbe_admin_data === "undefined") {
        console.error("wbe_admin_data not found");
        return;
      }

      this.setupStepNavigation();
      this.setupDatepickers();
      this.setupProductSelection();
      this.setupFormHandlers();
      this.setupDateManagement();
      this.setupResetHandler(); // Nouveau: gestionnaire de reset
      this.updateStats();
      this.populateCategories();
    },

    /**
     * NOUVEAU: Setup du gestionnaire de réinitialisation
     */
    setupResetHandler: function () {
      const self = this;

      $("#wbe-reset-all")
        .off("click")
        .on("click", function (e) {
          e.preventDefault();
          self.handleResetAll();
        });
    },

    /**
     * NOUVEAU: Gérer la réinitialisation complète
     */
    handleResetAll: function () {
      const self = this;

      // Vérifier qu'il y a des produits sélectionnés
      if (self.selectedProducts.length === 0) {
        self.showToast(
          "Erreur",
          "Veuillez d'abord sélectionner des produits à l'étape 1",
          "error",
        );
        return;
      }

      // Demander confirmation avec un message clair
      const confirmMessage = `⚠️ ATTENTION - ACTION IRRÉVERSIBLE ⚠️

Vous êtes sur le point de SUPPRIMER TOUTES les configurations de disponibilité de ${self.selectedProducts.length} produit(s).

Cela va effacer :
✓ Les plages de dates (début et fin)
✓ Les jours de la semaine disponibles
✓ Les dates spécifiques
✓ Les dates d'exclusion

Cette action est IRRÉVERSIBLE.

Voulez-vous vraiment continuer ?`;

      if (!confirm(confirmMessage)) {
        console.log("❌ Réinitialisation annulée par l'utilisateur");
        return;
      }

      // Deuxième confirmation (sécurité supplémentaire)
      const doubleConfirm = confirm(
        `Dernière confirmation :\n\nEffacer TOUTES les dates de ${self.selectedProducts.length} produit(s) ?\n\nCliquez OK pour confirmer.`,
      );

      if (!doubleConfirm) {
        console.log("❌ Réinitialisation annulée (double confirmation)");
        return;
      }

      console.log("✅ Réinitialisation confirmée par l'utilisateur");

      // Activer le mode reset
      resetMode = true;
      self.formData.reset_all = true;

      // Effacer tous les champs de l'interface
      self.clearAllFormFields();

      // Passer directement à l'étape 3 pour révision
      self.goToStep(3);

      // Mettre à jour le résumé avec l'indication de reset
      self.updateResetSummary();

      self.showToast(
        "Mode Réinitialisation Activé",
        `${self.selectedProducts.length} produit(s) seront réinitialisés lors de l'application`,
        "warning",
      );
    },

    /**
     * NOUVEAU: Effacer tous les champs du formulaire
     */
    clearAllFormFields: function () {
      console.log("🧹 Effacement de tous les champs du formulaire");

      // Effacer les dates
      $("#wbe-start-date").val("");
      $("#wbe-end-date").val("");

      // Décocher tous les jours de la semaine
      $(".wbe-weekday-checkbox").prop("checked", false);

      // Effacer les dates spécifiques et exclusions
      specificDates = [];
      exclusionDates = [];

      this.updateSpecificDatesList();
      this.updateExclusionDatesList();

      // Réinitialiser formData
      this.formData.start_date = "";
      this.formData.end_date = "";
      this.formData.weekdays = [];
      this.formData.specific_dates = [];
      this.formData.exclusions = [];

      console.log("✅ Tous les champs effacés");
    },

    /**
     * NOUVEAU: Mettre à jour le résumé pour le mode reset
     */
    updateResetSummary: function () {
      const $summary = $("#wbe-review-summary");

      let html = '<div class="wbe-review-content">';

      html += `<div class="wbe-review-section">
        <strong>Produits sélectionnés :</strong> ${this.selectedProducts.length}
      </div>`;

      html +=
        '<div class="wbe-review-section" style="padding: 20px; background: #fff3cd; border-left: 4px solid #d63638; margin: 10px 0;">';
      html +=
        '<h3 style="margin-top: 0; color: #d63638;">⚠️ MODE RÉINITIALISATION ACTIVÉ</h3>';
      html +=
        '<p style="font-size: 14px; margin: 10px 0;"><strong>Action :</strong> Suppression complète de toutes les configurations de disponibilité</p>';
      html += "<p style='font-size: 13px; color: #856404; margin: 5px 0;'>";
      html += "Les données suivantes seront EFFACÉES :<br>";
      html += "• Plage de dates (début et fin)<br>";
      html += "• Jours de la semaine disponibles<br>";
      html += "• Toutes les dates spécifiques<br>";
      html += "• Toutes les dates d'exclusion";
      html += "</p>";
      html +=
        '<p style="font-size: 13px; font-weight: bold; color: #d63638; margin-top: 10px;">Cette action est IRRÉVERSIBLE.</p>';
      html += "</div>";

      html += "</div>";

      $summary.html(html);
    },

    /**
     * Setup date management (dates spécifiques et exclusions)
     */
    setupDateManagement: function () {
      const self = this;

      $("#wbe-add-specific-date").datepicker({
        dateFormat: wbe_admin_data.date_format_js || "dd/mm/yy",
      });

      $("#wbe-add-exclusion-date").datepicker({
        dateFormat: wbe_admin_data.date_format_js || "dd/mm/yy",
      });

      $("#wbe-add-specific-btn").on("click", function () {
        const dateInput = $("#wbe-add-specific-date");
        const dateValue = dateInput.val().trim();

        if (!dateValue) {
          self.showToast("Erreur", "Veuillez sélectionner une date", "error");
          return;
        }

        const convertedDate = self.convertDateToYMD(dateValue);
        if (!convertedDate) {
          self.showToast("Erreur", "Format de date invalide", "error");
          return;
        }

        if (!specificDates.includes(convertedDate)) {
          specificDates.push(convertedDate);
          self.updateSpecificDatesList();
          dateInput.val("");
          self.showToast(
            "Succès",
            "Date ajoutée aux dates spécifiques",
            "success",
          );
        } else {
          self.showToast(
            "Avertissement",
            "Cette date est déjà ajoutée",
            "warning",
          );
        }
      });

      $("#wbe-add-exclusion-btn").on("click", function () {
        const dateInput = $("#wbe-add-exclusion-date");
        const dateValue = dateInput.val().trim();

        if (!dateValue) {
          self.showToast("Erreur", "Veuillez sélectionner une date", "error");
          return;
        }

        const convertedDate = self.convertDateToYMD(dateValue);
        if (!convertedDate) {
          self.showToast("Erreur", "Format de date invalide", "error");
          return;
        }

        if (!exclusionDates.includes(convertedDate)) {
          exclusionDates.push(convertedDate);
          self.updateExclusionDatesList();
          dateInput.val("");
          self.showToast("Succès", "Date ajoutée aux exclusions", "success");
        } else {
          self.showToast(
            "Avertissement",
            "Cette date est déjà exclue",
            "warning",
          );
        }
      });

      $("#wbe-clear-specific").on("click", function () {
        if (
          specificDates.length > 0 &&
          confirm(
            "Voulez-vous vraiment supprimer toutes les dates spécifiques ?",
          )
        ) {
          specificDates = [];
          self.updateSpecificDatesList();
          self.showToast(
            "Information",
            "Toutes les dates spécifiques ont été supprimées",
            "info",
          );
        }
      });

      $("#wbe-clear-exclusions").on("click", function () {
        if (
          exclusionDates.length > 0 &&
          confirm("Voulez-vous vraiment supprimer toutes les exclusions ?")
        ) {
          exclusionDates = [];
          self.updateExclusionDatesList();
          self.showToast(
            "Information",
            "Toutes les dates exclues ont été supprimées",
            "info",
          );
        }
      });
      self.updateSpecificDatesList();
      self.updateExclusionDatesList();
    },

    /**
     * Mettre à jour la liste des dates spécifiques
     */
    updateSpecificDatesList: function () {
      const $list = $("#wbe-specific-dates-list");
      $list.empty();

      if (specificDates.length === 0) {
        $list.attr("data-empty-text", "Aucune date spécifique ajoutée");
        $list.html(
          '<div class="wbe-empty-list">Aucune date spécifique ajoutée</div>',
        );
        return;
      }

      specificDates.sort();

      specificDates.forEach(function (date) {
        const $item = $('<div class="wbe-date-item"></div>');
        const displayDate = WBE_Admin.formatDateForDisplay(date);
        $item.html(`
          <span class="date-text">${displayDate}</span>
          <button type="button" class="remove-date" data-date="${date}">&times;</button>
        `);
        $list.append($item);
      });

      $list.find(".remove-date").on("click", function () {
        const dateToRemove = $(this).data("date");
        specificDates = specificDates.filter((d) => d !== dateToRemove);
        WBE_Admin.updateSpecificDatesList();
        WBE_Admin.showToast(
          "Information",
          "Date supprimée des dates spécifiques",
          "info",
        );
      });
    },

    /**
     * Mettre à jour la liste des exclusions
     */
    updateExclusionDatesList: function () {
      const $list = $("#wbe-exclusions-list");
      $list.empty();

      if (exclusionDates.length === 0) {
        $list.attr("data-empty-text", "Aucune date exclue");
        $list.html('<div class="wbe-empty-list">Aucune date exclue</div>');
        return;
      }

      exclusionDates.sort();

      exclusionDates.forEach(function (date) {
        const $item = $('<div class="wbe-date-item"></div>');
        const displayDate = WBE_Admin.formatDateForDisplay(date);
        $item.html(`
          <span class="date-text">${displayDate}</span>
          <button type="button" class="remove-date" data-date="${date}">&times;</button>
        `);
        $list.append($item);
      });

      $list.find(".remove-date").on("click", function () {
        const dateToRemove = $(this).data("date");
        exclusionDates = exclusionDates.filter((d) => d !== dateToRemove);
        WBE_Admin.updateExclusionDatesList();
        WBE_Admin.showToast(
          "Information",
          "Date supprimée des exclusions",
          "info",
        );
      });
    },

    /**
     * Setup step navigation
     */
    setupStepNavigation: function () {
      const self = this;

      $(".wbe-next-step").on("click", function (e) {
        e.preventDefault();
        const nextStep = parseInt($(this).data("next"));

        if (nextStep === 3) {
          self.validateAndGoToStep3();
        } else if (self.validateStep(self.currentStep)) {
          self.goToStep(nextStep);
        }
      });

      $(".wbe-prev-step").on("click", function (e) {
        e.preventDefault();
        const prevStep = parseInt($(this).data("prev"));

        // Si on revient à l'étape 2 depuis l'étape 3 en mode reset, désactiver le mode reset
        if (prevStep === 2 && resetMode) {
          if (
            confirm(
              "Voulez-vous annuler la réinitialisation et revenir à l'édition normale ?",
            )
          ) {
            resetMode = false;
            self.formData.reset_all = false;
            self.showToast(
              "Information",
              "Mode réinitialisation désactivé",
              "info",
            );
          }
        }

        self.goToStep(prevStep);
      });

      $(".wbe-step").on("click", function () {
        const step = parseInt($(this).data("step"));
        if (step < self.currentStep || $(this).hasClass("completed")) {
          self.goToStep(step);
        }
      });
    },

    /**
     * Valider et passer à l'étape 3
     */
    validateAndGoToStep3: function () {
      const self = this;

      // Si en mode reset, passer directement à l'étape 3
      if (resetMode) {
        self.goToStep(3);
        self.updateResetSummary();
        return;
      }

      // Sinon, validation normale
      const formData = this.collectStep2Data();
      const clientErrors = this.validateStep2Client(formData);

      if (clientErrors.length > 0) {
        this.showValidationErrors(clientErrors);
        return;
      }

      const $button = $('.wbe-next-step[data-next="3"]');
      const originalText = $button.html();
      $button
        .html(
          '<span class="spinner is-active" style="margin: 0 5px"></span> Validation...',
        )
        .prop("disabled", true);

      $.ajax({
        url: wbe_admin_data.ajax_url,
        type: "POST",
        data: {
          action:
            wbe_admin_data.ajax_actions?.validate_dates || "wbe_validate_dates",
          nonce: wbe_admin_data.nonce,
          start_date: formData.start_date,
          end_date: formData.end_date,
          weekdays: formData.weekdays,
          specific: formData.specific,
          exclusions: formData.exclusions,
        },
        success: function (response) {
          $button.html(originalText).prop("disabled", false);

          if (response.success && response.data.valid) {
            self.formData.start_date = formData.start_date;
            self.formData.end_date = formData.end_date;
            self.formData.weekdays = formData.weekdays;
            self.formData.specific_dates = formData.specific;
            self.formData.exclusions = formData.exclusions;

            self.goToStep(3);
            self.updateReviewSummary(formData);

            self.showToast(
              "Validation réussie",
              "Configuration validée avec succès",
              "success",
            );
          } else {
            const errors = response.data?.errors || ["Erreur de validation"];
            self.showValidationErrors(errors);
          }
        },
        error: function (xhr, status, error) {
          $button.html(originalText).prop("disabled", false);
          self.showToast(
            "Erreur",
            "La validation a échoué. Veuillez réessayer.",
            "error",
          );
        },
      });
    },

    /**
     * Collecter les données de l'étape 2
     */
    collectStep2Data: function () {
      const formData = {
        start_date: this.convertDateToYMD($("#wbe-start-date").val()) || "",
        end_date: this.convertDateToYMD($("#wbe-end-date").val()) || "",
        weekdays: [],
        specific: specificDates,
        exclusions: exclusionDates,
      };

      $(".wbe-weekday-checkbox:checked").each(function () {
        const dayName = $(this)
          .attr("name")
          .match(/\[(.*?)\]/)[1];
        const dayMap = {
          monday: 1,
          tuesday: 2,
          wednesday: 3,
          thursday: 4,
          friday: 5,
          saturday: 6,
          sunday: 0,
        };
        if (dayMap[dayName] !== undefined) {
          formData.weekdays.push(dayMap[dayName]);
        }
      });

      return formData;
    },

    /**
     * Validation côté client pour l'étape 2
     */
    validateStep2Client: function (formData) {
      const errors = [];
      const hasStartDate = !!formData.start_date;
      const hasEndDate = !!formData.end_date;

      if (hasStartDate && hasEndDate) {
        const startTime = new Date(formData.start_date).getTime();
        const endTime = new Date(formData.end_date).getTime();

        if (endTime < startTime) {
          errors.push(
            "La date de fin ne peut pas être antérieure à la date de début.",
          );
        }
      }

      if (formData.specific.length > 0 && formData.exclusions.length > 0) {
        const conflicts = formData.specific.filter((date) =>
          formData.exclusions.includes(date),
        );
        if (conflicts.length > 0) {
          const conflictDatesFormatted = conflicts.map((date) =>
            this.formatDateForDisplay(date),
          );
          errors.push(
            `Les dates suivantes sont à la fois marquées comme disponibles et exclues : ${conflictDatesFormatted.join(", ")}`,
          );
        }
      }

      return errors;
    },

    /**
     * Afficher les erreurs de validation
     */
    showValidationErrors: function (errors) {
      $('.wbe-step-content[data-step="2"] .notice').remove();

      if (errors.length === 0) return;

      let errorHtml =
        '<div class="notice notice-error is-dismissible" style="margin: 10px 0;">';
      errorHtml +=
        "<p><strong>Veuillez corriger les erreurs suivantes :</strong></p>";
      errorHtml += '<ul style="margin-left: 20px;">';

      errors.forEach(function (error) {
        errorHtml += "<li>" + WBE_Admin.escapeHtml(error) + "</li>";
      });

      errorHtml += "</ul>";
      errorHtml +=
        '<button type="button" class="notice-dismiss"><span class="screen-reader-text">Fermer</span></button>';
      errorHtml += "</div>";

      $(errorHtml).prependTo('.wbe-step-content[data-step="2"] .wbe-card-body');

      $("html, body").animate(
        {
          scrollTop: $('.wbe-step-content[data-step="2"]').offset().top - 50,
        },
        500,
      );

      $(document).on("click", ".notice-dismiss", function () {
        $(this).closest(".notice").remove();
      });
    },

    /**
     * Go to specific step
     */
    goToStep: function (step) {
      if (step === 2 && resetMode && this.currentStep === 3) {
        if (
          confirm(
            "Voulez-vous annuler la réinitialisation et revenir à l'édition normale ?",
          )
        ) {
          resetMode = false;
          this.formData.reset_all = false;
          this.showToast(
            "Information",
            "Mode réinitialisation désactivé",
            "info",
          );
        } else {
          // L'utilisateur veut rester en mode reset
          return;
        }
      }

      $(".wbe-step-content").removeClass("active");
      $(".wbe-step").removeClass("active");

      for (let i = 1; i < step; i++) {
        $(`.wbe-step[data-step="${i}"]`).addClass("completed");
      }

      $(`.wbe-step-content[data-step="${step}"]`).addClass("active");
      $(`.wbe-step[data-step="${step}"]`).addClass("active");

      this.currentStep = step;

      if (step === 3) {
        if (resetMode) {
          this.updateResetSummary();
        } else {
          this.updateReview();
        }
      }

      $("html, body").animate(
        { scrollTop: $(".wbe-admin-wrap").offset().top - 50 },
        300,
      );
    },

    /**
     * Validate current step
     */
    validateStep: function (step) {
      if (step === 1) {
        if (this.selectedProducts.length === 0) {
          this.showToast(
            "Erreur",
            "Veuillez sélectionner au moins un produit",
            "error",
          );
          return false;
        }
      }
      return true;
    },

    /**
     * Setup datepickers
     */
    setupDatepickers: function () {
      if (!$.fn.datepicker) {
        console.warn("jQuery UI Datepicker not loaded");
        return;
      }

      $(".wbe-datepicker").datepicker({
        dateFormat: wbe_admin_data.date_format_js || "dd/mm/yy",
        changeMonth: true,
        changeYear: true,
        minDate: 0,
      });

      $(".wbe-clear-date").on("click", function () {
        const targetId = $(this).data("target");
        $("#" + targetId)
          .val("")
          .datepicker("setDate", null);
      });
    },

    /**
     * Setup product selection
     */
    setupProductSelection: function () {
      const self = this;

      $("#wbe-load-category").on("click", function () {
        const categoryId = $("#wbe-category-select").val();
        self.loadProducts(categoryId);
      });

      $("#wbe-search-btn").on("click", function () {
        const searchTerm = $("#wbe-product-search").val();
        self.searchProducts(searchTerm);
      });

      $("#wbe-product-search").on("keypress", function (e) {
        if (e.which === 13) {
          e.preventDefault();
          $("#wbe-search-btn").click();
        }
      });

      $("#wbe-select-all").on("click", function () {
        $(".wbe-product-checkbox").prop("checked", true).trigger("change");
      });

      $("#wbe-deselect-all").on("click", function () {
        $(".wbe-product-checkbox").prop("checked", false).trigger("change");
      });
    },

    /**
     * Load products by category
     */
    loadProducts: function (categoryId) {
      const self = this;
      const $list = $("#wbe-product-list");
      const $loadBtn = $("#wbe-load-category");
      $list.html(
        '<div class="wbe-loading"><span class="spinner is-active"></span><span>' +
          wbe_admin_data.i18n.loading +
          "</span></div>",
      );
      $loadBtn.prop("disabled", true);

      $.ajax({
        url: wbe_admin_data.ajax_url,
        type: "POST",
        data: {
          action: "wbe_get_products",
          nonce: wbe_admin_data.nonce,
          category_id: categoryId,
          page: 1,
          per_page: 50,
        },
        success: function (response) {
          if (response.success && response.data) {
            const products = response.data.products || [];

            if (products.length === 0) {
              $list.html(
                '<p style="padding: 20px; text-align: center; color: #646970;">Aucun produit trouvé dans cette catégorie.</p>',
              );
            } else {
              self.displayProducts(products);
              self.showToast(
                "Succès",
                `${products.length} produit(s) chargé(s)`,
                "success",
              );
            }
          } else {
            const errorMsg =
              response.data?.message ||
              "Erreur lors du chargement des produits";
            self.showToast("Erreur", errorMsg, "error");
            $list.html('<div class="wbe-error">' + errorMsg + "</div>");
          }
        },
        error: function (xhr, status, error) {
          console.error("AJAX Error:", { status, error, xhr });

          let errorMsg = "Erreur serveur lors du chargement des produits";

          if (xhr.responseJSON && xhr.responseJSON.data) {
            errorMsg = xhr.responseJSON.data.message || errorMsg;
          }

          self.showToast("Erreur", errorMsg, "error");
          $list.html('<div class="wbe-error">' + errorMsg + "</div>");
        },
        complete: function () {
          $loadBtn.prop("disabled", false);
        },
      });
    },

    /**
     * Search products
     */
    searchProducts: function (searchTerm) {
      const self = this;
      const $list = $("#wbe-product-list");
      const $searchBtn = $("#wbe-search-btn");

      if (!searchTerm || searchTerm.trim().length < 2) {
        self.showToast(
          "Avertissement",
          "Veuillez entrer au moins 2 caractères",
          "warning",
        );
        return;
      }

      $list.html(
        '<div class="wbe-loading"><span class="spinner is-active"></span><span>' +
          wbe_admin_data.i18n.loading +
          "</span></div>",
      );
      $searchBtn.prop("disabled", true);

      $.ajax({
        url: wbe_admin_data.ajax_url,
        type: "POST",
        data: {
          action: "wbe_search_products",
          nonce: wbe_admin_data.nonce,
          search: searchTerm.trim(),
          limit: 50,
        },
        success: function (response) {
          if (response.success && response.data) {
            const products = response.data.products || [];

            if (products.length === 0) {
              $list.html(
                '<p style="padding: 20px; text-align: center; color: #646970;">Aucun produit trouvé pour "' +
                  self.escapeHtml(searchTerm) +
                  '"</p>',
              );
            } else {
              self.displayProducts(products);
              self.showToast(
                "Succès",
                `${products.length} produit(s) trouvé(s)`,
                "success",
              );
            }
          } else {
            const errorMsg =
              response.data?.message || "Erreur lors de la recherche";
            self.showToast("Erreur", errorMsg, "error");
            $list.html('<div class="wbe-error">' + errorMsg + "</div>");
          }
        },
        error: function (xhr, status, error) {
          console.error("Search Error:", { status, error, xhr });

          let errorMsg = "Erreur lors de la recherche";

          if (xhr.responseJSON && xhr.responseJSON.data) {
            errorMsg = xhr.responseJSON.data.message || errorMsg;
          }

          self.showToast("Erreur", errorMsg, "error");
          $list.html('<div class="wbe-error">' + errorMsg + "</div>");
        },
        complete: function () {
          $searchBtn.prop("disabled", false);
        },
      });
    },

    /**
     * Display products in list
     */
    displayProducts: function (products) {
      const self = this;
      const $list = $("#wbe-product-list");

      $list.empty();

      if (!products || products.length === 0) {
        $list.html(
          '<p style="padding: 20px; text-align: center; color: #646970;">Aucun produit trouvé.</p>',
        );
        return;
      }

      products.forEach(function (product) {
        const $item = $('<div class="wbe-product-item"></div>');
        const isChecked = self.selectedProducts.includes(product.id);

        const productName = self.escapeHtml(product.name || "Sans nom");
        const productSku = product.sku
          ? "| SKU: " + self.escapeHtml(product.sku)
          : "";

        $item.html(`
          <label style="display: flex; align-items: center; gap: 10px; cursor: pointer; flex: 1;">
            <input type="checkbox" 
                   class="wbe-product-checkbox" 
                   value="${product.id}" 
                   ${isChecked ? "checked" : ""}>
            <div>
              <div style="font-weight: 600;">${productName}</div>
              <div style="font-size: 12px; color: #646970;">
                ID: ${product.id} ${productSku}
              </div>
            </div>
          </label>
        `);

        $list.append($item);
      });

      $(".wbe-product-checkbox").on("change", function () {
        const productId = parseInt($(this).val());
        if ($(this).is(":checked")) {
          if (!self.selectedProducts.includes(productId)) {
            self.selectedProducts.push(productId);
          }
        } else {
          const index = self.selectedProducts.indexOf(productId);
          if (index > -1) {
            self.selectedProducts.splice(index, 1);
          }
        }
        self.updateSelectedCount();
      });
    },

    /**
     * Setup form handlers
     */
    setupFormHandlers: function () {
      const self = this;

      $(".wbe-weekday-checkbox").on("change", function () {
        self.formData.weekdays = $(".wbe-weekday-checkbox:checked")
          .map(function () {
            return $(this)
              .attr("name")
              .match(/\[(.*?)\]/)[1];
          })
          .get();
      });

      $("#wbe-preview-btn").on("click", function () {
        self.previewChanges();
      });

      $("#wbe-apply-btn").on("click", function () {
        if (confirm(wbe_admin_data.i18n.confirmApply)) {
          self.applyChanges();
        }
      });
    },

    /**
     * Update review summary
     */
    updateReviewSummary: function (formData) {
      const $summary = $("#wbe-review-summary");
      let html = '<div class="wbe-review-content">';

      html += `<div class="wbe-review-section"><strong>Produits sélectionnés :</strong> ${this.selectedProducts.length}</div>`;

      const hasRules =
        formData.start_date ||
        formData.end_date ||
        formData.weekdays.length > 0 ||
        formData.specific.length > 0 ||
        formData.exclusions.length > 0;

      if (!hasRules) {
        html +=
          '<div class="wbe-review-section" style="padding: 15px; background: #fff3cd; border-left: 4px solid #ffc107; margin: 10px 0;">';
        html += "<strong>⚠️ Aucune règle de disponibilité définie</strong><br>";
        html +=
          '<span style="font-size: 13px; color: #856404;">Les informations existantes des produits seront conservées.</span>';
        html += "</div>";
      } else {
        if (formData.start_date || formData.end_date) {
          if (formData.start_date && formData.end_date) {
            const startFr = this.formatDateForDisplay(formData.start_date);
            const endFr = this.formatDateForDisplay(formData.end_date);
            html += `<div class="wbe-review-section"><strong>Période :</strong> ${startFr} au ${endFr}</div>`;
          } else if (formData.start_date) {
            const startFr = this.formatDateForDisplay(formData.start_date);
            html += `<div class="wbe-review-section"><strong>Période :</strong> À partir du ${startFr}</div>`;
          } else {
            const endFr = this.formatDateForDisplay(formData.end_date);
            html += `<div class="wbe-review-section"><strong>Période :</strong> Jusqu'au ${endFr}</div>`;
          }
        }

        if (formData.weekdays.length > 0) {
          const dayNames = [
            "Dimanche",
            "Lundi",
            "Mardi",
            "Mercredi",
            "Jeudi",
            "Vendredi",
            "Samedi",
          ];
          const selectedDays = formData.weekdays.map(function (dayIndex) {
            return dayNames[dayIndex];
          });
          html += `<div class="wbe-review-section"><strong>Jours disponibles :</strong> ${selectedDays.join(", ")}</div>`;
        }

        if (formData.specific.length > 0) {
          const formattedDates = formData.specific.map((date) =>
            this.formatDateForDisplay(date),
          );
          html += `<div class="wbe-review-section"><strong>Dates spécifiques (${formData.specific.length}) :</strong><br>${formattedDates.join(", ")}</div>`;
        }

        if (formData.exclusions.length > 0) {
          const formattedDates = formData.exclusions.map((date) =>
            this.formatDateForDisplay(date),
          );
          html += `<div class="wbe-review-section"><strong>Dates exclues (${formData.exclusions.length}) :</strong><br>${formattedDates.join(", ")}</div>`;
        }
      }

      html += "</div>";
      $summary.html(html);
    },

    /**
     * Update review
     */
    updateReview: function () {
      const formData = {
        start_date: this.formData.start_date,
        end_date: this.formData.end_date,
        weekdays: this.formData.weekdays,
        specific: this.formData.specific_dates,
        exclusions: this.formData.exclusions,
      };

      this.updateReviewSummary(formData);
    },

    /**
     * Preview changes
     */
    previewChanges: function () {
      this.showToast(
        "Information",
        "Fonction de prévisualisation à venir...",
        "info",
      );
    },

    /**
     * Apply changes to products - MODIFIÉ pour gérer le mode reset
     */
    applyChanges: function () {
      const self = this;
      let ajaxData = {
        nonce: wbe_admin_data.nonce,
        product_ids: this.selectedProducts,
        debug: true,
        timestamp: Date.now(),
      };
      console.log('Current mode - Reset:', resetMode, '| Normal:', !resetMode);
console.log('AJAX action:', ajaxData.action);
console.log('Product IDs to process:', ajaxData.product_ids);
      if (this.selectedProducts.length === 0) {
        this.showToast("Erreur", "Aucun produit sélectionné.", "error");
        return;
      }

      const $applyBtn = $("#wbe-apply-btn");
      const $progressContainer = $("#wbe-progress-container");
      const $progressFill = $("#wbe-progress-fill");
      const $progressText = $("#wbe-progress-text");

      $progressContainer.show();
      $applyBtn.prop("disabled", true);

      // Texte selon le mode
      if (resetMode) {
        $progressText.text("⏳ Réinitialisation en cours...");
      } else {
        $progressText.text("⏳ Application des modifications en cours...");
      }

      const startTime = Date.now();
      const updateTimer = setInterval(function () {
        const elapsed = Math.floor((Date.now() - startTime) / 1000);
        if (resetMode) {
          $progressText.text(`⏳ Réinitialisation en cours... (${elapsed}s)`);
        } else {
          $progressText.text(`⏳ Application en cours... (${elapsed}s)`);
        }
      }, 1000);

      // ✅ MODIFICATION PRINCIPALE : Préparer les données selon le mode
      

      // ✅ MODE RESET : Action AJAX dédiée (OPTION A - RECOMMANDÉ)
      if (resetMode) {
        console.log("🔴 RESET MODE: Using dedicated AJAX action");

        ajaxData.action = "wbe_reset_products"; // ← Action spécifique

        // Pas besoin d'envoyer les champs de formulaire
        // Seulement les product_ids
      }
      // MODE NORMAL : Action batch standard
      else {
        console.log("✅ NORMAL MODE: Using batch process");

        ajaxData.action =
          wbe_admin_data.ajax_actions?.process_batch || "wbe_process_batch";

        // Collecter les données du formulaire
        const weekdaysObj = {};
        $(".wbe-weekday-checkbox:checked").each(function () {
          const dayName = $(this)
            .attr("name")
            .match(/\[(.*?)\]/)[1];
          weekdaysObj[dayName] = "on";
        });

        ajaxData.start_date = this.formData.start_date;
        ajaxData.end_date = this.formData.end_date;
        ajaxData.weekdays = weekdaysObj;
        ajaxData.specific = specificDates;
        ajaxData.exclusions = exclusionDates;
      }

      console.log("📤 Données envoyées au serveur:", ajaxData);

      // ✅ REQUÊTE AJAX
      $.ajax({
        url: wbe_admin_data.ajax_url,
        type: "POST",
        data: ajaxData,
        success: function (response) {
          clearInterval(updateTimer);
          const elapsed = Math.floor((Date.now() - startTime) / 1000);

          console.log("📥 Réponse du serveur:", response);

          if (response.success) {
            $progressFill.css("width", "100%");

            // ✅ MESSAGE SELON LE MODE
            if (resetMode) {
              $progressText.text(
                `✅ Réinitialisation effectuée avec succès en ${elapsed}s`,
              );

              const results = response.data;
              self.showToast(
                "Succès",
                `${results.success_count}/${results.total_products} produit(s) réinitialisé(s)`,
                "success",
              );

              // ✅ Désactiver le mode reset après succès
              resetMode = false;
              self.formData.reset_all = false;

              // ✅ Afficher les erreurs s'il y en a
              if (results.failed_count > 0) {
                self.showDetailedErrors(results.failed_details);
              }
            } else {
              $progressText.text(
                `✅ Modifications appliquées avec succès en ${elapsed}s`,
              );

              const results = response.data?.results || response.data;
              const successCount = results.success
                ? results.success.length
                : results.success_count || 0;
              const totalCount = results.total || self.selectedProducts.length;

              self.showToast(
                "Succès",
                `${successCount}/${totalCount} produit(s) mis à jour`,
                "success",
              );

              if (results.failed && results.failed.length > 0) {
                self.showDetailedErrors(results.failed);
              }
            }
          } else {
            const errorMsg =
              response.data?.message || response.message || "Erreur inconnue";
            self.showToast("Erreur", errorMsg, "error");
            $progressText.text(`❌ Échec: ${errorMsg}`);
          }
        },

        error: function (xhr, status, error) {
          clearInterval(updateTimer);
          console.error("AJAX Error:", { status, error, xhr });

          let errorMsg = "Erreur serveur";

          if (xhr.responseJSON && xhr.responseJSON.error) {
            errorMsg = xhr.responseJSON.error.message || errorMsg;
          }

          self.showToast("Erreur", errorMsg, "error");
          $progressText.text("❌ " + errorMsg);
        },

        complete: function () {
          clearInterval(updateTimer);
          $applyBtn.prop("disabled", false);

          setTimeout(function () {
            $progressContainer.hide();
            $progressFill.css("width", "0%");
          }, 3000);
        },
      });
    },

    handleResetAll: function () {
      const self = this;
      console.log("🔴 RESET MODE ACTIVATED");
      console.log("Selected products:", this.selectedProducts);
      console.log("Form data before reset:", this.formData);
      // Vérifier qu'il y a des produits sélectionnés
      if (self.selectedProducts.length === 0) {
        self.showToast(
          "Erreur",
          "Veuillez d'abord sélectionner des produits à l'étape 1",
          "error",
        );
        return;
      }

      // ✅ Message de confirmation plus clair
      const confirmMessage = `⚠️ ATTENTION - ACTION IRRÉVERSIBLE ⚠️

Vous êtes sur le point de SUPPRIMER TOUTES les configurations de disponibilité de ${self.selectedProducts.length} produit(s).

Cela va effacer :
✓ Les plages de dates (début et fin)
✓ Les jours de la semaine disponibles
✓ Les dates spécifiques
✓ Les dates d'exclusion

Cette action est IRRÉVERSIBLE.

Voulez-vous vraiment continuer ?`;

      if (!confirm(confirmMessage)) {
        console.log("❌ Réinitialisation annulée par l'utilisateur");
        return;
      }

      // Deuxième confirmation (sécurité supplémentaire)
      const doubleConfirm = confirm(
        `Dernière confirmation :\n\nEffacer TOUTES les dates de ${self.selectedProducts.length} produit(s) ?\n\nCliquez OK pour confirmer.`,
      );

      if (!doubleConfirm) {
        console.log("❌ Réinitialisation annulée (double confirmation)");
        return;
      }

      console.log("✅ Réinitialisation confirmée par l'utilisateur");
      console.log("🔴 Activation du mode RESET");

      // Activer le mode reset
      resetMode = true;
      self.formData.reset_all = true;

      // Effacer tous les champs de l'interface (visuel uniquement)
      self.clearAllFormFields();

      // Passer directement à l'étape 3 pour révision
      self.goToStep(3);

      // Mettre à jour le résumé avec l'indication de reset
      self.updateResetSummary();

      self.showToast(
        "Mode Réinitialisation Activé",
        `${self.selectedProducts.length} produit(s) seront réinitialisés lors de l'application`,
        "warning",
      );
    },
    /**
     * Show detailed errors for failed products
     */
    showDetailedErrors: function (failedProducts) {
      const self = this;

      if (!failedProducts || failedProducts.length === 0) {
        return;
      }

      const errorGroups = {};

      failedProducts.forEach(function (failure) {
        const errorMsg = failure.error || "Erreur inconnue";

        if (!errorGroups[errorMsg]) {
          errorGroups[errorMsg] = [];
        }

        errorGroups[errorMsg].push(failure.product_id);
      });

      let errorHtml =
        '<div style="max-height: 300px; overflow-y: auto; padding: 10px; background: #fff3cd; border: 1px solid #ffc107; border-radius: 4px; margin-top: 10px;">';
      errorHtml +=
        '<h4 style="margin-top: 0; color: #856404;">⚠ Produits non mis à jour (' +
        failedProducts.length +
        "):</h4>";
      errorHtml += '<ul style="margin: 0; padding-left: 20px;">';

      for (const [errorMsg, productIds] of Object.entries(errorGroups)) {
        errorHtml += '<li style="margin-bottom: 8px;">';
        errorHtml += "<strong>" + self.escapeHtml(errorMsg) + "</strong><br>";
        errorHtml +=
          '<span style="color: #666; font-size: 12px;">Produits: ' +
          productIds.join(", ") +
          "</span>";
        errorHtml += "</li>";
      }

      errorHtml += "</ul>";
      errorHtml +=
        '<button type="button" class="button button-small" onclick="this.parentElement.remove()" style="margin-top: 10px;">Fermer</button>';
      errorHtml += "</div>";

      const $errorContainer = $(errorHtml);
      $("#wbe-progress-container").after($errorContainer);

      const uniqueErrors = Object.keys(errorGroups).length;
      self.showToast(
        "Avertissement",
        `${failedProducts.length} produit(s) n'ont pas pu être mis à jour (${uniqueErrors} type(s) d'erreur)`,
        "warning",
      );
    },

    /**
     * Enhanced toast with icons and better styling
     */
    showToast: function (title, message, type) {
      type = type || "info";

      if (arguments.length === 2) {
        message = title;
        title = type;
        type = "info";
      }

      const icons = {
        success: "✅",
        error: "❌",
        warning: "⚠️",
        info: "ℹ️",
      };

      const colors = {
        success: "#d4edda",
        error: "#f8d7da",
        warning: "#fff3cd",
        info: "#d1ecf1",
      };

      const borderColors = {
        success: "#c3e6cb",
        error: "#f5c6cb",
        warning: "#ffeaa7",
        info: "#bee5eb",
      };

      const icon = icons[type] || icons.info;
      const bgColor = colors[type] || colors.info;
      const borderColor = borderColors[type] || borderColors.info;

      const $toast = $('<div class="notice is-dismissible"></div>');
      $toast.css({
        margin: "10px 0",
        padding: "12px 15px",
        display: "flex",
        "align-items": "center",
        gap: "10px",
        "background-color": bgColor,
        "border-left": "4px solid " + borderColor,
        "border-radius": "4px",
        "box-shadow": "0 2px 4px rgba(0,0,0,0.1)",
      });

      $toast.html(
        '<span style="font-size: 20px;">' +
          icon +
          "</span>" +
          '<div style="flex: 1;">' +
          (title ? "<strong>" + this.escapeHtml(title) + "</strong><br>" : "") +
          '<span style="font-size: 14px;">' +
          this.escapeHtml(message) +
          "</span>" +
          "</div>" +
          '<button type="button" class="notice-dismiss" style="position: relative; right: 0;"><span class="screen-reader-text">Fermer</span></button>',
      );

      $("#wbe-toast-container").append($toast);

      $toast.find(".notice-dismiss").on("click", function () {
        $toast.fadeOut(function () {
          $(this).remove();
        });
      });

      const duration = type === "error" ? 10000 : 8000;
      setTimeout(function () {
        $toast.fadeOut(function () {
          $(this).remove();
        });
      }, duration);
    },

    /**
     * Update statistics
     */
    updateStats: function () {
      if (wbe_admin_data.statistics) {
        $("#wbe-total-products").text(
          wbe_admin_data.statistics.total_products || 0,
        );
        $("#wbe-wootour-count").text(
          wbe_admin_data.statistics.with_wootour || 0,
        );
      }
      this.updateSelectedCount();
    },

    /**
     * Update selected count
     */
    updateSelectedCount: function () {
      $("#wbe-selected-count").text(this.selectedProducts.length);
    },

    /**
     * Populate categories dropdown
     */
    populateCategories: function () {
      if (!wbe_admin_data.categories) return;

      const $select = $("#wbe-category-select");
      this.addCategoriesToSelect($select, wbe_admin_data.categories, 0);
    },

    /**
     * Add categories to select (recursive)
     */
    addCategoriesToSelect: function ($select, categories, level) {
      const indent = "—".repeat(level) + " ";

      categories.forEach(function (category) {
        const categoryName = WBE_Admin.escapeHtml(category.name || "");
        $select.append(
          $("<option></option>")
            .val(category.id)
            .text(indent + categoryName + " (" + category.count + ")"),
        );
        if (category.children && category.children.length > 0) {
          WBE_Admin.addCategoriesToSelect(
            $select,
            category.children,
            level + 1,
          );
        }
      });
    },

    /**
     * Convert date to YYYY-MM-DD format
     */
    convertDateToYMD: function (dateStr) {
      if (!dateStr) return "";

      if (dateStr.match(/^\d{4}-\d{2}-\d{2}$/)) {
        return dateStr;
      }

      if (dateStr.match(/^(\d{2})\/(\d{2})\/(\d{4})$/)) {
        const parts = dateStr.split("/");
        return parts[2] + "-" + parts[1] + "-" + parts[0];
      }

      const timestamp = Date.parse(dateStr);
      if (!isNaN(timestamp)) {
        const date = new Date(timestamp);
        return (
          date.getFullYear() +
          "-" +
          String(date.getMonth() + 1).padStart(2, "0") +
          "-" +
          String(date.getDate()).padStart(2, "0")
        );
      }

      return "";
    },

    /**
     * Format date for display (YYYY-MM-DD → DD/MM/YYYY)
     */
    formatDateForDisplay: function (dateStr) {
      if (!dateStr) return "";

      if (dateStr.match(/^\d{2}\/\d{2}\/\d{4}$/)) {
        return dateStr;
      }
      if (dateStr.match(/^\d{4}-\d{2}-\d{2}$/)) {
        const parts = dateStr.split("-");
        return parts[2] + "/" + parts[1] + "/" + parts[0];
      }

      return dateStr;
    },

    /**
     * Escape HTML to prevent XSS
     */
    escapeHtml: function (text) {
      const map = {
        "&": "&amp;",
        "<": "&lt;",
        ">": "&gt;",
        '"': "&quot;",
        "'": "&#039;",
      };
      return String(text).replace(/[&<>"']/g, function (m) {
        return map[m];
      });
    },
  };

  $(document).ready(function () {
    WBE_Admin.init();
  });
})(jQuery);
