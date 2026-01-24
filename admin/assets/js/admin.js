/**
 * Wootour Bulk Editor - Admin JavaScript (Enhanced)
 */
(function ($) {
  "use strict";

  const WBE_Admin = {
    currentStep: 1,
    selectedProducts: [],
    formData: {
      start_date: "",
      end_date: "",
      weekdays: [],
      specific_dates: [],
      exclusions: [],
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
      this.updateStats();
      this.populateCategories();

      console.log("WBE Admin initialized successfully");
    },

    /**
     * Setup step navigation
     */
    setupStepNavigation: function () {
      const self = this;

      $(".wbe-next-step").on("click", function () {
        const nextStep = parseInt($(this).data("next"));
        if (self.validateStep(self.currentStep)) {
          self.goToStep(nextStep);
        }
      });

      $(".wbe-prev-step").on("click", function () {
        const prevStep = parseInt($(this).data("prev"));
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
     * Go to specific step
     */
    goToStep: function (step) {
      $(".wbe-step-content").removeClass("active");
      $(".wbe-step").removeClass("active");

      for (let i = 1; i < step; i++) {
        $(`.wbe-step[data-step="${i}"]`).addClass("completed");
      }

      $(`.wbe-step-content[data-step="${step}"]`).addClass("active");
      $(`.wbe-step[data-step="${step}"]`).addClass("active");

      this.currentStep = step;

      if (step === 3) {
        this.updateReview();
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
          this.showToast(wbe_admin_data.i18n.noProductsSelected, "error");
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
        onSelect: function (dateText, inst) {
          const fieldId = $(this).attr("id");
          if (fieldId === "wbe-start-date") {
            WBE_Admin.formData.start_date = dateText;
          } else if (fieldId === "wbe-end-date") {
            WBE_Admin.formData.end_date = dateText;
          }
        },
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

      // Show loading state
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
                `${products.length} produit(s) chargé(s)`,
                "success",
              );
            }
          } else {
            const errorMsg =
              response.data?.message ||
              "Erreur lors du chargement des produits";
            self.showToast(errorMsg, "error");
            $list.html('<div class="wbe-error">' + errorMsg + "</div>");
          }
        },
        error: function (xhr, status, error) {
          console.error("AJAX Error:", { status, error, xhr });

          let errorMsg = "Erreur serveur lors du chargement des produits";

          if (xhr.responseJSON && xhr.responseJSON.data) {
            errorMsg = xhr.responseJSON.data.message || errorMsg;
          }

          self.showToast(errorMsg, "error");
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
        self.showToast("Veuillez entrer au moins 2 caractères", "warning");
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
                `${products.length} produit(s) trouvé(s)`,
                "success",
              );
            }
          } else {
            const errorMsg =
              response.data?.message || "Erreur lors de la recherche";
            self.showToast(errorMsg, "error");
            $list.html('<div class="wbe-error">' + errorMsg + "</div>");
          }
        },
        error: function (xhr, status, error) {
          console.error("Search Error:", { status, error, xhr });

          let errorMsg = "Erreur lors de la recherche";

          if (xhr.responseJSON && xhr.responseJSON.data) {
            errorMsg = xhr.responseJSON.data.message || errorMsg;
          }

          self.showToast(errorMsg, "error");
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

      // Handle checkbox changes
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
    updateReview: function () {
      const $summary = $("#wbe-review-summary");
      let html = '<ul style="list-style: none; padding: 0;">';

      html += `<li style="padding: 8px 0; border-bottom: 1px solid #ddd;"><strong>Produits sélectionnés:</strong> ${this.selectedProducts.length}</li>`;

      if (this.formData.start_date) {
        html += `<li style="padding: 8px 0; border-bottom: 1px solid #ddd;"><strong>Date de début:</strong> ${this.formData.start_date}</li>`;
      }

      if (this.formData.end_date) {
        html += `<li style="padding: 8px 0; border-bottom: 1px solid #ddd;"><strong>Date de fin:</strong> ${this.formData.end_date}</li>`;
      }

      if (this.formData.weekdays.length > 0) {
        html += `<li style="padding: 8px 0; border-bottom: 1px solid #ddd;"><strong>Jours de la semaine:</strong> ${this.formData.weekdays.join(", ")}</li>`;
      }

      html += "</ul>";
      $summary.html(html);
    },

    /**
     * Preview changes
     */
    previewChanges: function () {
      this.showToast("Fonction de prévisualisation à venir...", "info");
    },

    /**
     * Apply changes to products - CORRECTED VERSION
     */
    applyChanges: function () {
      const self = this;

      // Validation: Check if products are selected
      if (this.selectedProducts.length === 0) {
        this.showToast(
          "❌ Aucun produit sélectionné. Veuillez sélectionner au moins un produit à l'étape 1.",
          "error",
        );
        return;
      }

      const formatDateForPHP = function (dateString) {
        if (!dateString) return "";

        // Si la date est déjà au format YYYY-MM-DD (venant du datepicker)
        if (dateString.match(/^\d{4}-\d{2}-\d{2}$/)) {
          return dateString;
        }

        // Sinon, convertir depuis le format dd/mm/yyyy
        const parts = dateString.split("/");
        if (parts.length === 3) {
          return `${parts[2]}-${parts[1]}-${parts[0]}`; // YYYY-MM-DD
        }

        return dateString;
      };

      // Collect form data properly
      const formData = {
        start_date: $("#wbe-start-date").val(),
        end_date: $("#wbe-end-date").val(),
        weekdays: {},
        specific: [],
        exclusions: [],
      };

      console.log("Form data to send:", formData);
      console.log("Product IDs:", this.selectedProducts);

      // Validation: Check if any changes are specified
      const hasChanges =
        this.formData.start_date ||
        this.formData.end_date ||
        this.formData.weekdays.length > 0 ||
        this.formData.specific_dates.length > 0 ||
        this.formData.exclusions.length > 0;

      if (!hasChanges) {
        this.showToast(
          "⚠️ Aucune modification spécifiée. Veuillez définir au moins une règle de disponibilité à l'étape 2.",
          "warning",
        );
        return;
      }

      const $applyBtn = $("#wbe-apply-btn");
      const $progressContainer = $("#wbe-progress-container");
      const $progressFill = $("#wbe-progress-fill");
      const $progressText = $("#wbe-progress-text");

      // Format weekdays correctly (as checkboxes)
      $(".wbe-weekday-checkbox:checked").each(function () {
        const dayName = $(this)
          .attr("name")
          .match(/\[(.*?)\]/)[1];
        formData.weekdays[dayName] = "on";
      });

      // Debug log
      console.log("Form data to send:", formData);
      console.log("Product IDs:", this.selectedProducts);
      console.log(
        "AJAX Action:",
        wbe_admin_data.ajax_actions?.process_batch || "wbe_process_batch",
      );

      // Show progress UI
      $progressContainer.show();
      $applyBtn.prop("disabled", true);
      $progressText.text("⏳ Application des modifications en cours...");

      // Start timer to show elapsed time
      const startTime = Date.now();
      const updateTimer = setInterval(function () {
        const elapsed = Math.floor((Date.now() - startTime) / 1000);
        $progressText.text(`⏳ Application en cours... (${elapsed}s)`);
      }, 1000);

      const ajaxAction =
        wbe_admin_data.ajax_actions?.process_batch || "wbe_process_batch";

      // ✅ CORRECTION: Utiliser l'action correcte "wbe_process_batch"
      $.ajax({
        url: wbe_admin_data.ajax_url,
        type: "POST",
        data: {
          action: ajaxAction,
          nonce: wbe_admin_data.nonce,
          product_ids: this.selectedProducts,
          start_date: formData.start_date,
          end_date: formData.end_date,
          weekdays: formData.weekdays, // Format: {monday: 'on', tuesday: 'on'}
          specific: formData.specific,
          exclusions: formData.exclusions,
          // AJOUTER pour debug
          debug: true,
          timestamp: Date.now(),
        },
        success: function (response) {
          clearInterval(updateTimer);
          const elapsed = Math.floor((Date.now() - startTime) / 1000);

          if (response.success) {
            $progressFill.css("width", "100%");
            $progressText.text(
              `✅ Modifications appliquées avec succès en ${elapsed}s`,
            );

            const results = response.data?.results;

            if (results) {
              // Show detailed success message
              const successCount = results.success ? results.success.length : 0;
              const totalCount = results.total || self.selectedProducts.length;

              let successMsg = `✅ ${successCount}/${totalCount} produit(s) mis à jour avec succès`;
              self.showToast(successMsg, "success");

              // Show detailed errors if some products failed
              if (results.failed && results.failed.length > 0) {
                self.showDetailedErrors(results.failed);
              }
            } else {
              // Generic success message
              const message =
                response.data?.message ||
                "Modifications appliquées avec succès";
              self.showToast(`✅ ${message}`, "success");
            }
          } else {
            // Business error (validation failed, etc.)
            const errorMsg =
              response.data?.message ||
              "Erreur lors de l'application des modifications";
            const errorCode = response.data?.code || "UNKNOWN_ERROR";

            self.showToast(`❌ ${errorMsg}`, "error");
            $progressText.text(`❌ Échec: ${errorMsg}`);

            // Log detailed error info
            console.error("Application failed:", {
              code: errorCode,
              message: errorMsg,
              data: response.data,
            });
          }
        },
        error: function (xhr, status, error) {
          clearInterval(updateTimer);

          console.error("AJAX Error Details:", {
            status: status,
            error: error,
            statusCode: xhr.status,
            responseText: xhr.responseText,
            responseJSON: xhr.responseJSON,
          });

          let errorMsg = "Erreur serveur inconnue";
          let errorDetails = "";

          // Parse error based on HTTP status code
          switch (xhr.status) {
            case 0:
              errorMsg = "Impossible de contacter le serveur";
              errorDetails =
                "Vérifiez votre connexion internet ou contactez l'administrateur.";
              break;

            case 400:
              errorMsg = "Requête invalide";
              if (xhr.responseJSON && xhr.responseJSON.data) {
                errorDetails =
                  xhr.responseJSON.data.message ||
                  "Les données envoyées sont incorrectes.";
              }
              break;

            case 401:
              errorMsg = "Non autorisé";
              errorDetails =
                "Votre session a peut-être expiré. Veuillez actualiser la page et réessayer.";
              break;

            case 403:
              errorMsg = "Accès refusé";
              errorDetails =
                "Vous n'avez pas les permissions nécessaires pour effectuer cette action.";
              break;

            case 404:
              errorMsg = "Ressource introuvable";
              errorDetails =
                "L'endpoint AJAX n'a pas été trouvé. Vérifiez que le plugin est correctement activé.";
              break;

            case 500:
              errorMsg = "Erreur interne du serveur";
              errorDetails =
                "Une erreur s'est produite côté serveur. Consultez les logs PHP pour plus de détails.";
              break;

            case 502:
            case 503:
            case 504:
              errorMsg = "Serveur temporairement indisponible";
              errorDetails =
                "Le serveur est surchargé ou en maintenance. Réessayez dans quelques instants.";
              break;

            default:
              errorMsg = `Erreur HTTP ${xhr.status}`;
              if (xhr.responseJSON && xhr.responseJSON.data) {
                errorDetails = xhr.responseJSON.data.message || error;
              } else {
                errorDetails = error || "Erreur inconnue";
              }
          }

          // Display main error
          self.showToast(`❌ ${errorMsg}`, "error");
          $progressText.text(`❌ ${errorMsg}`);

          // Display error details if available
          if (errorDetails) {
            setTimeout(function () {
              self.showToast(`ℹ️ ${errorDetails}`, "info");
            }, 500);
          }

          // Show technical details in console
          console.group("🔴 Erreur technique");
          console.error("Message:", errorMsg);
          console.error("Détails:", errorDetails);
          console.error("Statut HTTP:", xhr.status);
          console.error("Réponse brute:", xhr.responseText);
          console.groupEnd();
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

    /**
     * Show detailed errors for failed products
     */
    showDetailedErrors: function (failedProducts) {
      const self = this;

      if (!failedProducts || failedProducts.length === 0) {
        return;
      }

      // Group errors by error message
      const errorGroups = {};

      failedProducts.forEach(function (failure) {
        const errorMsg = failure.error || "Erreur inconnue";

        if (!errorGroups[errorMsg]) {
          errorGroups[errorMsg] = [];
        }

        errorGroups[errorMsg].push(failure.product_id);
      });

      // Show grouped errors
      let errorHtml =
        '<div style="max-height: 300px; overflow-y: auto; padding: 10px; background: #fff3cd; border: 1px solid #ffc107; border-radius: 4px; margin-top: 10px;">';
      errorHtml +=
        '<h4 style="margin-top: 0; color: #856404;">⚠️ Produits non mis à jour (' +
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

      // Insert error details after progress container
      const $errorContainer = $(errorHtml);
      $("#wbe-progress-container").after($errorContainer);

      // Also show a summary toast
      const uniqueErrors = Object.keys(errorGroups).length;
      self.showToast(
        `⚠️ ${failedProducts.length} produit(s) n'ont pas pu être mis à jour (${uniqueErrors} type(s) d'erreur)`,
        "warning",
      );
    },

    /**
     * Enhanced toast with icons and better styling
     */
    showToast: function (message, type) {
      type = type || "info";

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
          '<p style="margin: 0; flex: 1;">' +
          this.escapeHtml(message) +
          "</p>" +
          '<button type="button" class="notice-dismiss" style="position: relative; right: 0;"><span class="screen-reader-text">Fermer</span></button>',
      );

      $("#wbe-toast-container").append($toast);

      // Handle dismiss button
      $toast.find(".notice-dismiss").on("click", function () {
        $toast.fadeOut(function () {
          $(this).remove();
        });
      });

      // Auto-dismiss after 8 seconds (longer for errors)
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

  // Initialize when DOM is ready
  $(document).ready(function () {
    WBE_Admin.init();
  });
})(jQuery);
